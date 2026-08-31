<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_lifecycle — handles fee lifecycle events triggered by student
 * management (admission, promotion, module enrolment, soft-delete).
 *
 * Phase 5 — Firestore-only. Every data path has been moved off
 * Realtime Database onto the canonical Firestore collections that
 * admin + parent + teacher apps already read. Public API is unchanged
 * so existing callers (Sis, Hostel, Transport, Classes) don't have to
 * know the backing store moved.
 *
 * Firestore collections used:
 *   feeStructures     — class/section fee chart (read-only here)
 *   feeDemands        — canonical demand docs (write — all lifecycle events)
 *   students          — parent/student profile lookups
 *   studentDiscounts  — sibling-discount state
 *   fee_audit_logs    — audit trail for every lifecycle event
 *
 * Demand status vocabulary used by this library:
 *   unpaid   — generated, nothing paid yet       (assignInitialFees, createModuleFee)
 *   partial  — paid partially                    (not written here; payment flow sets it)
 *   paid     — fully paid                         (not written here)
 *   archived — superseded by a later class/section (reassignFeesOnPromotion)
 *   frozen   — student soft-deleted, do not collect (freezeFeesOnSoftDelete)
 *   reversed — admission reversed                 (reverseAdmissionFees)
 */
class Fee_lifecycle
{
    /** @var object */  private $firebase;
    /** @var object */  private $fs;           // Firestore_service shim
    /** @var object */  private $fsTxn;        // Fee_firestore_txn
    /** @var object|null */ private $fsSync;  // Fee_firestore_sync (defaulter)
    /** @var object|null */ private $auditLogger; // Fee_audit_logger

    private string $schoolName;
    private string $sessionYear;
    private string $adminId;

    /** M5: request-scoped resolver, built lazily only when the flag is on. */
    private $_headResolver = null;

    /**
     * M5 — is the Fee Head Registry the authority for NEW demands?
     *
     * ALL-OR-NOTHING by design: every live new-demand path consults this same
     * method, so the system is never half-registry / half-heuristic.
     */
    private function _registryModeOn(): bool
    {
        $CI = function_exists('get_instance') ? get_instance() : null;
        if ($CI === null || !isset($CI->config)) return false;
        $CI->config->load('fee_head_registry', true, true);
        // One literal, one switch — see the note at the billing-authority gate.
        $flag = 'USE_FEE_HEAD_REGISTRY';
        if (!$this->_rolloutEnabled($flag, (bool) $CI->config->item($flag, 'fee_head_registry'))) {
            return false;
        }

        // ── THE GUARD THAT MAKES DEFAULT-ON SAFE ────────────────────────────
        //
        // Registry mode resolves every charted fee head against feeHeadRegistry
        // and fails CLOSED: one unresolvable head aborts that student's ENTIRE
        // billing. Measured with the flag on and the registry empty — demands=0,
        // ACC_FEEHEAD_UNRESOLVED, and the admission still reporting success.
        //
        // That was survivable while the flag was off by default and granted
        // per tenant. As a DEFAULT it would mean every school that has not yet
        // registered its heads silently bills nobody — the worst possible
        // outcome for a school on its first day.
        //
        // So the flag now expresses INTENT and this expresses CAPABILITY: if
        // the registry does not yet cover the chart, we stay on legacy pricing
        // and keep billing. The school loses the registry's category precision
        // until it finishes configuring, which is strictly better than losing
        // its revenue.
        //
        // Memoised per request per tenant: this runs once per generation, and a
        // school prices many students in one run.
        $school = (string) $this->schoolName;
        if ($school === '') return false;
        if (array_key_exists($school, self::$_registryReadyMemo)) {
            return self::$_registryReadyMemo[$school];
        }

        $ready = false;
        try {
            if (!class_exists('Fee_head_readiness')) {
                require_once APPPATH . 'libraries/Fee_head_readiness.php';
            }
            $missing = (new Fee_head_readiness())->missingForSchool($this->firebase, $school);
            // NULL means the question could not be answered. Never assume ready:
            // an unverified registry is exactly the state that bills nobody.
            $ready = ($missing === []);
            if (!$ready) {
                log_message('error', 'FEEHEAD_REGISTRY_HELD school=[' . $school . '] — registry mode '
                    . 'is enabled but the registry does not yet cover this school\'s fee chart ('
                    . ($missing === null ? 'unreadable' : count($missing) . ' head(s) unregistered: '
                    . implode(', ', array_slice($missing, 0, 6)))
                    . '). Pricing on the LEGACY authority so billing continues. Register the heads '
                    . 'in Fee Management → Fee Heads.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'FEEHEAD_REGISTRY_HELD school=[' . $school . '] — readiness check '
                . 'failed: ' . $e->getMessage() . '. Pricing on the LEGACY authority.');
            $ready = false;
        }

        return self::$_registryReadyMemo[$school] = $ready;
    }

    /** Per-request, per-tenant memo for the registry-capability guard. */
    private static array $_registryReadyMemo = [];

    /**
     * T2 (2026-08-18) — resolve a rollout flag for THIS generator's tenant.
     *
     * The config file stays the sole author of the GLOBAL DEFAULT; the caller
     * reads it and passes it in, and the resolver answers only "does this
     * tenant override it?". With no `schoolControl.rolloutFlags` entry the
     * default is returned unchanged, so every gate below is byte-identical to
     * its pre-T2 behaviour.
     *
     * Binds on `$this->schoolName` — which IS the schoolId here (the same value
     * passed to Fee_head_resolver::resolveByName) — so a cron or worker with no
     * controller-level tenant still resolves against the RIGHT tenant rather
     * than silently falling back to the global default. Re-init on an unchanged
     * schoolId keeps the memo, so the whole request costs at most one read.
     *
     * Fail-safe in every direction: no CI, no service, no school, or a read
     * failure all return the global default — today's legacy path.
     */
    private function _rolloutEnabled(string $key, bool $globalDefault): bool
    {
        $CI = function_exists('get_instance') ? get_instance() : null;
        if ($CI === null || $this->schoolName === '') return $globalDefault;
        try {
            if (!isset($CI->rollout)) {
                $CI->load->library('rollout_flag_service', null, 'rollout');
            }
            return (bool) $CI->rollout
                ->init($this->firebase, (string) $this->schoolName)
                ->isEnabled($key, $globalDefault);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_lifecycle::_rolloutEnabled failed for ' . $key . ': ' . $e->getMessage());
            return $globalDefault;
        }
    }

    /** Lazily construct the ONE resolver. Never a second implementation. */
    private function _resolver()
    {
        if ($this->_headResolver === null) {
            if (!class_exists('Fee_head_resolver')) {
                require_once APPPATH . 'libraries/Fee_head_resolver.php';
            }
            $CI = get_instance();
            $this->_headResolver = (new Fee_head_resolver())->init($CI->fs);
        }
        return $this->_headResolver;
    }

    /**
     * M5 — the registry stamp for a NEW demand, or a fail-closed error.
     *
     * Returns the three fields a new demand must carry, or ['ok'=>false] when
     * identity or classification cannot be established. Callers MUST abort
     * generation on failure: guessing here is exactly the defect the registry
     * exists to remove, and a wrong `category` silently changes who qualifies
     * for a scholarship.
     *
     * ⚠ `feeHeadRef` is returned for the DEMAND DOCUMENT only. It must never
     * reach _demandId() — see the warning on that method.
     *
     * @return array{ok:bool,reason?:string,fields?:array}
     */
    private function _registryStamp(string $feeHeadRef, string $headName = ''): array
    {
        $r = $this->_resolver();
        $res = $feeHeadRef !== ''
            ? $r->resolveForNewUse($this->schoolName, $feeHeadRef)
            : ($headName !== '' ? $r->resolveByName($this->schoolName, $headName) : ['ok' => false, 'reason' => 'missing_fee_head_ref']);

        if (empty($res['ok'])) {
            return ['ok' => false, 'reason' => (string) ($res['reason'] ?? 'unresolved')];
        }
        // An archived head may explain history but must never price new work.
        if (($res['status'] ?? 'active') !== 'active') {
            return ['ok' => false, 'reason' => 'archived'];
        }
        return ['ok' => true, 'fields' => [
            'feeHeadRef'     => (string) $res['feeHeadId'],
            'category'       => (string) $res['category'],
            'categorySource' => 'registry',
        ]];
    }

    /** Frequency → number of instalments per academic year */
    private const FREQUENCY_MAP = [
        'Monthly'   => 12,
        'Quarterly' => 4,
        'Annual'    => 1,
        'One-time'  => 1,
    ];

    private const ACADEMIC_START_MONTH = 4;


    /**
     * Phase 15.5 (AB) — memoised due-day-of-month.
     *
     * PERFORMANCE CONTRACT: `Fee_firestore_txn::getDueDay()` performs a
     * `feeSettings` read. Demand generation loops over every fee head × every
     * academic month (12 iterations for a monthly head), and callers loop over
     * students, so calling it inline would cost 12 reads PER STUDENT — a
     * textbook N+1. This memo pins it to ONE read per request/instance.
     *
     * Null means "not yet resolved"; 0 is never a valid due day (getDueDay
     * clamps to 1–28), so null is an unambiguous sentinel.
     *
     * @var int|null
     */
    private ?int $dueDayMemo = null;

    /**
     * The school's overdue grace, memoised for the same N+1 reason as
     * `$dueDayMemo`. Null means "not yet resolved"; 0 IS a valid grace (the
     * default), which is why the sentinel is null rather than 0.
     *
     * @var int|null
     */
    private ?int $overdueGraceMemo = null;

    // ─── Initialisation ──────────────────────────────────────────────

    public function init($firebase, string $schoolName, string $sessionYear, string $adminId = 'system'): self
    {
        $this->firebase    = $firebase;
        $this->schoolName  = $schoolName;
        $this->sessionYear = $sessionYear;
        $this->adminId     = $adminId;

        $CI =& get_instance();

        // Firestore_service (for .get / .exists / .getEntity)
        if (!isset($CI->fs) || !is_object($CI->fs)) {
            $CI->load->library('firestore_service', null, 'fs');
        }
        $this->fs = $CI->fs;

        // Fee_firestore_txn — canonical writeDemand / updateDemand / readFeeStructure.
        if (!isset($CI->fsTxn) || !is_object($CI->fsTxn)) {
            $CI->load->library('Fee_firestore_txn', null, 'fsTxn');
            $CI->fsTxn->init($firebase, $this->fs, $schoolName, $sessionYear);
        }
        $this->fsTxn = $CI->fsTxn;

        // Fee_firestore_sync — defaulter recompute hooks.
        if (!isset($CI->fsSync) || !is_object($CI->fsSync)) {
            $CI->load->library('Fee_firestore_sync', null, 'fsSync');
            $CI->fsSync->init($firebase, $schoolName, $sessionYear);
        }
        $this->fsSync = $CI->fsSync;

        // Audit logger — best-effort; library failure never fails a
        // lifecycle event, per Fee_audit_logger contract.
        try {
            require_once APPPATH . 'libraries/Fee_audit_logger.php';
            $this->auditLogger = new Fee_audit_logger($firebase, $schoolName, $sessionYear);
        } catch (\Throwable $_) { $this->auditLogger = null; }

        return $this;
    }

    // ─── Small helpers (Firestore-aware) ─────────────────────────────

    /**
     * Phase 15.5 (AB) — resolve the school's configured due day, ONCE.
     *
     * Falls back to Fee_firestore_txn's own default (10) if the txn library is
     * unavailable, so a missing dependency degrades to a sane due date rather
     * than to no due date at all — a dateless demand is permanently exempt
     * from late fees, which is the exact defect this phase closes.
     */
    private function _dueDay(): int
    {
        if ($this->dueDayMemo !== null) return $this->dueDayMemo;
        $day = 10;
        try {
            if (is_object($this->fsTxn) && method_exists($this->fsTxn, 'getDueDay')) {
                $day = (int) $this->fsTxn->getDueDay();
            }
        } catch (\Throwable $e) {
            log_message('error', 'Fee_lifecycle::_dueDay — getDueDay failed, using 10: ' . $e->getMessage());
        }
        // Mirror getDueDay's own clamp so a corrupt setting can never produce
        // an impossible calendar date (e.g. "2026-02-30").
        if ($day < 1 || $day > 28) $day = 10;
        return $this->dueDayMemo = $day;
    }

    /**
     * Phase 15.5 (AB) — canonical demand due date: `YYYY-MM-DD`.
     *
     * $periodKey is already `YYYY-MM` (see buildAdmissionDemandSpecs), so the
     * due date is a pure string composition — no date parsing, no timezone,
     * no month-length arithmetic. The 1–28 clamp in _dueDay() is what makes
     * that safe: day 29/30/31 would be invalid in February, so it can never
     * be produced. February and leap years therefore need no special case.
     *
     * Format matches `Fees::compute_fines_for_student`, which compares the
     * stored value lexicographically against `date('Y-m-d')`.
     */
    private function _dueDateFor(string $periodKey): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) return '';
        return sprintf('%s-%02d', $periodKey, $this->_dueDay());
    }

    /**
     * The school's overdue grace, memoised like `_dueDay()`.
     *
     * Read from the ONE authoritative definition rather than re-implemented:
     * `Fee_overdue_policy` owns the field name, the default and the clamp, so
     * this cannot drift from the settings screen or from the tests.
     */
    private function _overdueGraceDays(): int
    {
        if ($this->overdueGraceMemo !== null) return $this->overdueGraceMemo;
        require_once APPPATH . 'libraries/Fee_overdue_policy.php';
        $g = Fee_overdue_policy::DEFAULT_GRACE;
        try {
            $p = new Fee_overdue_policy();
            $g = $p->init($this->firebase, $this->schoolName, $this->sessionYear)->graceDays();
        } catch (\Throwable $e) {
            log_message('error',
                'Fee_lifecycle::_overdueGraceDays failed, using the default: ' . $e->getMessage());
        }
        return $this->overdueGraceMemo = $g;
    }

    /**
     * The last day this demand is still on time — `dueDate + graceDays`.
     *
     * ── WHY THIS IS STAMPED ONTO THE DEMAND ─────────────────────────────
     * The Parent App must not hold an overdue policy of its own (it hard-coded
     * 15 days, which matched no server rule). Handing it the POLICY would mean
     * either letting it read `feeSettings` — which also holds the gateway
     * `api_secret` — or inventing a new parent-readable collection and a
     * Firestore rule for it.
     *
     * Stamping the DERIVED DATE avoids both: `feeDemands` is already
     * parent-readable, the app compares a date to today and holds no policy at
     * all, and an old app with no knowledge of this field simply falls back to
     * `dueDate`, which is the correct zero-grace answer.
     */
    private function _overdueFromFor(string $dueDate): string
    {
        require_once APPPATH . 'libraries/Fee_overdue_policy.php';
        return Fee_overdue_policy::overdueFrom($dueDate, $this->_overdueGraceDays());
    }

    /**
     * Deterministic demand id.
     *
     * @param string $instanceKey WHICH OCCURRENCE of this head this demand is —
     *   appended ONLY when explicitly supplied, so every existing caller keeps
     *   a byte-identical id. Currently supplied only by the ID-card
     *   replacement path ('V{cardVersion}').
     *
     *   ⚠ NEVER pass a feeHeadRef (Fee Head Registry identity) into this
     *   method, in either $feeHeadId or $instanceKey. feeHeadRef answers "what
     *   type of charge is this"; the demand id answers "which exact demand is
     *   this". Module fees deliberately pass an EMPTY $feeHeadId so the key
     *   derives from the head name — feeding a registry ref in instead would
     *   change every existing Transport/Hostel demand id, and the next
     *   generation cycle would write a SECOND demand alongside each existing
     *   one: mass duplicate charges. Classification and identity stay apart.
     */
    private function _demandId(string $studentId, string $periodKey, string $feeHeadId, string $fallbackName = '', string $instanceKey = ''): string
    {
        $sid = preg_replace('/[^A-Za-z0-9]+/', '_', trim($studentId));
        $sid = trim((string) $sid, '_');
        $ym  = str_replace('-', '', $periodKey);
        $key = trim($feeHeadId);
        if ($key === '') {
            $key = strtoupper(trim($fallbackName));
            $key = preg_replace('/[^A-Z0-9]+/', '_', $key);
            $key = trim((string) $key, '_');
        }
        $id = "DEM_{$sid}_{$ym}_{$key}";

        // Absent instance ⇒ byte-identical to the pre-change id. This is what
        // protects Transport, Hostel and every chart-fee demand.
        $inst = strtoupper(trim($instanceKey));
        $inst = trim((string) preg_replace('/[^A-Z0-9]+/', '_', $inst), '_');
        return $inst === '' ? $id : "{$id}_{$inst}";
    }

    /** Student profile from Firestore. */
    private function _studentDoc(string $studentId): ?array
    {
        try {
            $d = $this->firebase->firestoreGet('students', "{$this->schoolName}_{$studentId}");
            return is_array($d) ? $d : null;
        } catch (\Throwable $_) { return null; }
    }

    /**
     * All current demands for a student in this session, keyed by
     * demandId. Thin wrapper around Fee_firestore_txn::demandsForStudent
     * so callers below stay readable.
     */
    private function _demandsFor(string $studentId): array
    {
        try {
            return $this->fsTxn->demandsForStudent($studentId);
        } catch (\Throwable $_) { return []; }
    }

    /**
     * P5-0 — FAIL-CLOSED demand read for callers where an empty set is a
     * SAFETY property, not merely a data answer.
     *
     * `_demandsFor()` above swallows the error and answers `[]`. For a reporting
     * caller that is a benign degradation. For `buildAdmissionDemandSpecs()` it
     * is a money-corruption path: that set carries both duplicate suppression
     * AND the BUG-075 paid-demand guard, and specs are written with
     * `merge = true`, so a blind rebuild re-emits specs for demands that already
     * carry a payment and resets `paidAmount = 0`, `balance = netAmount`,
     * `status = 'unpaid'` — un-paying settled money while its receipt and
     * journal survive and now disagree.
     *
     * NOTE ON THE FIRST ATTEMPT AT THIS FIX: wrapping the `_demandsFor()` CALL
     * SITE in a try/catch does nothing, because the swallow happens one level
     * down, inside `_demandsFor()` itself. The failure has to be propagated from
     * the read, which is what this method does. A source-shaped test would have
     * reported that first attempt as fixed; a behavioural one caught it.
     *
     * @throws \RuntimeException when the demand set cannot be read
     */
    private function _demandsForStrict(string $studentId): array
    {
        if ($this->fsTxn === null) {
            throw new \RuntimeException('Fee_lifecycle: no demand transaction bound');
        }
        // Prefer the strict reader (B-011) when present; fall back to the
        // lenient one wrapped so its failure still surfaces.
        if (method_exists($this->fsTxn, 'demandsForStudentStrict')) {
            return $this->fsTxn->demandsForStudentStrict($studentId);
        }
        $rows = $this->fsTxn->demandsForStudent($studentId);
        return is_array($rows) ? $rows : [];
    }

    /**
     * BUG-076 (2026-05-27): session-independent demand fetch. Used by
     * reassignFeesOnPromotion so the archive loop sees the student's
     * demands regardless of which session the operator's session-selector
     * is currently pointing at. Class/section filter inside the archive
     * loop is what actually drives the archive decision — session never
     * was load-bearing for archival correctness.
     */
    private function _demandsForAllSessions(string $studentId): array
    {
        try {
            return $this->fsTxn->demandsForStudentAllSessions($studentId);
        } catch (\Throwable $_) { return []; }
    }

    /**
     * BUG-045 Phase 1 B2 (2026-05-25): request-level fee-structure cache.
     *
     * Pre-fix bulk promote of N students into the same destination class
     * called readFeeStructure + readFeeHeadIds once per student = 2N reads
     * of the same Firestore doc. With this cache, the same class/section
     * pair is fetched ONCE per request lifetime; subsequent students hit
     * the in-memory cache (free).
     *
     * Cache key: "{classKey}_{sectionKey}" — session is fixed per
     * Fee_firestore_txn instance, so excluded from key.
     *
     * Memory bound: the cache holds at most one doc per touched
     * class/section pair per request; lifetime is one PHP request
     * (instance is destroyed at request end).
     *
     * @return array ['chart' => [...], 'headIds' => [...]]
     */
    private $_feeStructureCache = [];
    private function _cachedFeeStructureWithIds(string $class, string $section): array
    {
        $key = "{$class}_{$section}";
        if (!isset($this->_feeStructureCache[$key])) {
            $this->_feeStructureCache[$key] = $this->fsTxn->readFeeStructureWithIds($class, $section);
        }
        return $this->_feeStructureCache[$key];
    }

    // ═════════════════════════════════════════════════════════════════
    //  1. assignInitialFees
    // ═════════════════════════════════════════════════════════════════

    /**
     * Create fresh demand docs for a newly-admitted student from their
     * class+section fee structure. Idempotent by virtue of deterministic
     * demand IDs — re-running against the same student is a no-op.
     *
     * @return array  List of fee-head names that were generated.
     */
    /**
     * Build the demand specs that admission/promotion would emit for this
     * (student, class, section) — WITHOUT committing. Pure builder (no
     * Firestore writes). Extracted from assignInitialFees in Phase 1 of the
     * Fees Exemption v2 work so the unified generator (Fee_generation_service)
     * can call the same builder and produce byte-identical output for the
     * zero-concession case.
     *
     * Returns: [
     *   'specs'      => array<commitDemandBatch-entry>  ← what to write
     *   'assigned'   => array<head-name>                ← unique fee heads with amt>0
     *   'chartFound' => bool                            ← false iff feeStructure absent
     * ]
     *
     * Exceptions propagate to the caller (assignInitialFees keeps its outer
     * try/catch). DOES NOT change behavior of assignInitialFees — proven by
     * the Phase-1 A/B verification probe before any P2 flag flip.
     */
    /**
     * @param bool $forPreview  SE-001 (2026-08-09). READ-ONLY PRICING SIMULATION.
     *
     * Duplicate suppression is a WRITE-PATH concern: it stops a re-run creating
     * a second demand for a head the student already holds. Pricing is a
     * READ-PATH concern: a preview must show what the generator WOULD produce,
     * not what the database currently permits to be written.
     *
     * With the default (false) this method is byte-identical to its
     * pre-SE-001 behaviour, so every production generator call site is
     * unaffected. With true, the suppression set is not seeded from existing
     * demands — and ONLY that. All pricing, entitlement/concession resolution,
     * deduction, and rounding run through the exact same code either way;
     * there is no second pricing implementation.
     *
     * Passing true is safe ONLY because the preview caller never persists the
     * returned specs (Fee_lifecycle::previewDemandSpecs → Entitlements::preview
     * build and discard). **Never pass true from a path that writes.**
     */
    public function buildAdmissionDemandSpecs(
        string $studentId,
        string $class,
        string $section,
        bool $forPreview = false
    ): array {
        $assigned     = [];
        $batchEntries = [];

        // BUG-075 fix (2026-05-26): pre-read existing demands to detect
        // already-paid demand-ids. Re-running against a previously-visited
        // class (reverse-promote, rollover) MUST NOT overwrite paidAmount/
        // balance/status on demands that already carry a payment — the
        // deterministic demandId formula collides when feeHeadIds match
        // between source and target class structures, and Firestore
        // merge=true SET would silently reset the paid state.
        // ── P5-0 · THIS READ MUST FAIL CLOSED (2026-08-20) ──────────────────
        //
        // `$existingDemands` carries TWO safety properties at once:
        //   1. duplicate suppression (do not re-create a charge that exists);
        //   2. the BUG-075 paid-demand guard described directly above.
        //
        // The previous `catch { $existingDemands = []; }` discarded BOTH on any
        // read failure and carried on. Because specs are written with
        // `merge = true`, re-emitting a spec for an ALREADY-PAID demand rewrites
        // `paidAmount = 0`, `balance = netAmount`, `status = 'unpaid'` — the
        // exact BUG-075 symptom this block exists to prevent. A transient
        // Firestore error was therefore enough to silently un-pay settled money.
        //
        // That is strictly worse than the B-011 shape (which could only
        // over-bill an UNPAID demand): here the corruption lands on money the
        // family has already handed over, and the receipt and journal survive
        // pointing at a demand that no longer agrees with them.
        //
        // Refusing is safe and contract-preserving: `chartFound => false` is a
        // shape every caller already handles (it is the ordinary "this class has
        // no fee chart" answer), and it causes NOTHING to be written.
        $existingDemands = [];
        try {
            foreach ($this->_demandsForStrict($studentId) as $did => $d) {
                $existingDemands[$did] = $d;
            }
        } catch (\Throwable $e) {
            log_message('error', 'ACC_SPEC_BUILD_REFUSED school=[' . $this->schoolName
                . '] student=[' . $studentId . '] reason=[existing_demand_read_failed] — '
                . 'refusing to build specs; proceeding blind could reset paid demands: '
                . $e->getMessage());
            return ['specs' => [], 'assigned' => [], 'chartFound' => false,
                    'refused' => 'existing_demand_read_failed'];
        }

        // Fees V7: canonical duplicate-detection key (periodKey|canonicalFeeHeadId),
        // NON-archived only — id-agnostic, archive-aware.
        $canonMap = $this->fsTxn->canonicalHeadIdMap();
        $haveKeys = [];
        // SE-001: seed the suppression set from EXISTING demands only on write
        // paths. A preview must price the full chart — otherwise an already-
        // admitted student (i.e. every student) has every head suppressed and
        // the preview reports a confident, wrong ₹0.
        //
        // The set is still POPULATED further below as heads are emitted, so a
        // preview cannot list the same head twice within one build. Only the
        // "already in the database" seeding is skipped.
        // ── DEC-007 · DUAL-SHAPE INDEX (2026-08-18) ─────────────────────────
        //
        // THE DEFECT THIS CLOSES — duplicate financial obligations at admission.
        //
        // This side used to index ONLY `pk|canonicalFeeHeadId`, and only when
        // that field was non-empty. The LOOKUP below derives its canonical id
        // from the CHART (`canonMap[name] ?? headId`), which is effectively
        // never empty. So a stored demand with an empty `canonicalFeeHeadId`
        // was indexed NOWHERE while the lookup always used a populated key: it
        // could never match, and generation raised a SECOND demand for an
        // occurrence that already existed.
        //
        // That is the Phase 1 asymmetry, and strictly worse — pre-Phase-1
        // `Fees::_generateDemandsForMonth` at least indexed the NAME shape,
        // whereas this path skipped the document entirely.
        //
        // Live exposure when found: 26 of 29 demands on SCH_B56BB9A401 and 26
        // of 1118 on SCH_218AAF5C23 carried an empty `canonicalFeeHeadId` —
        // every one of them invisible to suppression.
        //
        // The values are now SETS OF DOCUMENT IDS rather than `true`, so the
        // lookup can count DISTINCT documents and tell "one demand indexed
        // under both shapes" apart from "two demands for one occurrence".
        // Identical contract to Phase 1/2; no demand id, amount or stored field
        // is touched by this — it only makes suppression see what is already
        // there.
        if (!$forPreview) {
            foreach ($existingDemands as $_did => $_d) {
                if (($_d['status'] ?? '') === 'archived') continue;
                $pk = (string) ($_d['periodKey'] ?? $_d['period_key'] ?? '');
                if ($pk === '') continue;
                $cid = (string) ($_d['canonicalFeeHeadId'] ?? '');
                $nm  = strtolower(trim((string) ($_d['feeHead'] ?? '')));
                if ($cid !== '') $haveKeys[$pk . '|' . $cid][$_did]       = true;
                if ($nm  !== '') $haveKeys[$pk . '|name:' . $nm][$_did]   = true;
            }
        }

        // BUG-045 Phase 1 B2+B3 (2026-05-25): merged read (B3) wrapped in
        // request-level cache (B2). Saves 1 RPC/student + (N-1) more when
        // all N students share a target class/section.
        $merged   = $this->_cachedFeeStructureWithIds($class, $section);
        $chart    = $merged['chart'];
        $headIds  = $merged['headIds'];
        // M5: name → school-scoped registry identity, carried through the
        // chart expansion. Empty when the flag is off or a row has no binding.
        $headRefs = (array) ($merged['headRefs'] ?? []);
        $registryMode = $this->_registryModeOn();
        if (empty($chart)) {
            return ['specs' => [], 'assigned' => [], 'chartFound' => false];
        }

        $stu = $this->_studentDoc($studentId);
        $studentName = (string) ($stu['name'] ?? $stu['studentName'] ?? $studentId);

        $now = date('c');

        // BUG-045 Phase 2 (2026-05-25): collect-then-batch — caller emits
        // a single :commit batched write via Fee_firestore_txn::commitDemandBatch
        // with per-doc fallback. This builder produces the spec list only.
        foreach ($chart as $monthOrYearly => $heads) {
            if (!is_array($heads) || empty($heads)) continue;
            foreach ($heads as $headName => $amount) {
                $amt = (float) $amount;
                if ($amt <= 0) continue;

                $isYearly  = ($monthOrYearly === 'Yearly Fees');
                $headId    = (string) ($headIds[$headName] ?? '');

                // One demand per academic month for monthly heads; a single
                // April bucket for yearly heads — matches the Phase-2.5 generator.
                $targetMonths = $isYearly ? ['April'] : [$monthOrYearly];
                if ($isYearly && $monthOrYearly !== 'Yearly Fees') continue;

                foreach ($targetMonths as $m) {
                    $year       = in_array($m, ['January','February','March'], true)
                                ? ((int) substr($this->sessionYear, 0, 4) + 1)
                                : (int) substr($this->sessionYear, 0, 4);
                    $periodKey  = sprintf('%04d-%02d', $year, $this->_monthNum($m));
                    $periodLbl  = $isYearly
                        ? "Yearly Fees {$this->sessionYear}"
                        : "{$m} {$year}";
                    $canonicalId = (string) ($canonMap[strtolower(trim($headName))] ?? $headId);

                    // Fees V7: skip if an active demand for this occurrence already
                    // exists — preserves it entirely (incl. payment), id-agnostic.
                    // Replaces the doc-id BUG-075 preserve-on-rewrite (issued demands immutable).
                    //
                    // DEC-007: consult BOTH shapes, mirroring how the existing side
                    // now indexes them. The empty-canonical fallback keys on the head
                    // NAME so two distinct empty-canonical heads cannot collapse into
                    // one `pk|` slot. Byte-identical to the previous behaviour whenever
                    // the stored canonical id is populated.
                    $dedupKeyByName = $periodKey . '|name:' . strtolower(trim($headName));
                    $dedupKey       = ($canonicalId !== '')
                        ? ($periodKey . '|' . $canonicalId)
                        : $dedupKeyByName;

                    // Union by DOCUMENT ID: one demand indexed under both shapes
                    // collapses to a single entry, so the count means "distinct
                    // demands representing this occurrence".
                    $matchIds   = ($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? []);
                    $matchCount = count($matchIds);

                    // Phase 2 parity: two or more existing demands already represent
                    // this one occurrence. That is a pre-existing data defect with no
                    // safe automatic answer — creating a third compounds it. Name it
                    // and leave the records alone. Nothing is deleted, merged or repaid.
                    if ($matchCount >= 2) {
                        $conflictIds = array_keys($matchIds);
                        sort($conflictIds);
                        log_message('error', 'ACC_FEE_DEMAND_IDENTITY_CONFLICT path=admission school=['
                            . $this->schoolName . '] student=[' . $studentId . '] period=[' . $periodKey
                            . '] head=[' . $headName . '] canonicalFeeHeadId=[' . $canonicalId
                            . '] matches=[' . implode(',', $conflictIds) . ']');
                    }

                    if ($matchCount >= 1) {
                        if (!in_array($headName, $assigned, true)) $assigned[] = $headName;
                        continue;
                    }

                    $demandId = $this->fsTxn->newDemandId();
                    // Guard within this run — index BOTH shapes under this run's own
                    // demand id, so a later head cannot slip past on the shape this
                    // one did not register.
                    $haveKeys[$dedupKey][$demandId]       = true;
                    $haveKeys[$dedupKeyByName][$demandId] = true;

                    // ── M5 · REGISTRY STAMP (flag-gated) ──────────────────
                    // With the flag OFF this block is inert and the spec is
                    // byte-identical to before. With it ON the head's
                    // school-scoped identity and its authoritative category
                    // are stamped onto the NEW demand — and an unresolvable
                    // head aborts generation rather than producing a demand
                    // nobody can classify.
                    $m5 = [];
                    if ($registryMode) {
                        $stamp = $this->_registryStamp((string) ($headRefs[$headName] ?? ''), $headName);
                        if (empty($stamp['ok'])) {
                            log_message('error', 'ACC_FEEHEAD_UNRESOLVED path=admission student=['
                                . $studentId . '] school=[' . $this->schoolName . '] head=[' . $headName
                                . '] reason=[' . ($stamp['reason'] ?? '?') . '] — generation aborted (fail closed)');
                            return ['ok' => false, 'specs' => [], 'chartFound' => true,
                                    'error' => 'fee_head_unresolved', 'feeHead' => $headName,
                                    'reason' => (string) ($stamp['reason'] ?? 'unresolved')];
                        }
                        $m5 = $stamp['fields'];
                    }

                    $entryData = $m5 + [
                        'studentId'    => $studentId,
                        'studentName'  => $studentName,
                        'className'    => $class,
                        'section'      => $section,
                        'feeHead'      => $headName,
                        'feeHeadId'    => $headId,
                        'canonicalFeeHeadId' => $canonicalId,
                        'frequency'    => $isYearly ? 'yearly' : 'monthly',
                        'period'       => $periodLbl,
                        'periodKey'    => $periodKey,
                        // Phase 15.5 (AB) — every demand gets a due date.
                        // Without this the late-fee engine skips the demand
                        // outright (Fees::compute_fines_for_student: `if
                        // ($dueDate === '' || $dueDate >= $today) continue;`),
                        // making late fees permanently unreachable. This is
                        // the single convergence point for admission, initial
                        // assignment, promotion reassignment, preview and
                        // re-admission — they all route through here, so
                        // preview and commit agree by construction.
                        'dueDate'      => $this->_dueDateFor($periodKey),
                        // The derived overdue boundary, so the Parent App
                        // needs no policy of its own. Same convergence point
                        // as dueDate above — admission, initial assignment,
                        // promotion reassignment, preview and re-admission all
                        // route through here, so every demand carries it.
                        'overdueFrom'  => $this->_overdueFromFor($this->_dueDateFor($periodKey)),
                        'grossAmount'  => $amt,
                        'netAmount'    => $amt,
                        'createdAt'    => $now,
                        'createdBy'    => $this->adminId,
                    ];
                    // New demand (existing ones were skipped above): always fresh unpaid.
                    $entryData['paidAmount'] = 0.0;
                    $entryData['balance']    = $amt;
                    $entryData['status']     = 'unpaid';

                    $batchEntries[] = [
                        'op'       => 'write',
                        'demandId' => $demandId,
                        'data'     => $entryData,
                    ];
                }
                if (!in_array($headName, $assigned, true)) $assigned[] = $headName;
            }
        }

        return ['specs' => $batchEntries, 'assigned' => $assigned, 'chartFound' => true];
    }

    /**
     * Phase 2 router: route through Fee_generation_service when the flag is
     * on (concession-aware), else call buildAdmissionDemandSpecs directly
     * (byte-identical to legacy / Phase 1 state). On any flag-read failure
     * the legacy path is taken — fail-safe.
     */
    private function _routedBuildAdmissionSpecs(string $studentId, string $class, string $section, bool $forPreview = false): array
    {
        $useUnified = false;
        $CI = function_exists('get_instance') ? get_instance() : null;
        try {
            if ($CI !== null && isset($CI->config)) {
                $CI->config->load('fees_exemption_v2_flags', true);
                $useUnified = (bool) $CI->config->item('USE_UNIFIED_FEE_GEN', 'fees_exemption_v2_flags');
            }
        } catch (\Throwable $_) {
            $useUnified = false;
        }

        if (!$useUnified || $CI === null) {
            return $this->buildAdmissionDemandSpecs($studentId, $class, $section, $forPreview);
        }

        // ── PHASE 3B / B2 — THE BILLING-AUTHORITY BOUNDARY ──────────────────
        // This is the ONE place that decides which collection prices a demand.
        // Everything downstream is authority-agnostic: the generator asks the
        // reader it is given for applicability and deduction semantics, and both
        // readers expose the identical contract.
        //
        // NEVER BOTH. Exactly one reader is constructed and passed. There is no
        // code path in which two candidate sets exist, which is what makes a
        // double discount structurally impossible rather than merely avoided.
        //
        // Fails SAFE: any error reading the flag leaves $useEntitlementAuthority
        // false, i.e. the legacy authority that has been billing all along.
        // T2: tenant-scoped. The config value is still the GLOBAL DEFAULT; the
        // resolver only answers whether this tenant overrides it. Any failure —
        // config, resolver, or read — leaves the legacy authority in place.
        // The flag name appears EXACTLY ONCE, deliberately: Phase 3B pins that
        // the authority is chosen at one place, and a second literal would be a
        // second switch that could disagree with the first.
        $authorityFlag = 'USE_ENTITLEMENT_BILLING';
        $useEntitlementAuthority = false;
        try {
            $useEntitlementAuthority = $this->_rolloutEnabled(
                $authorityFlag,
                (bool) $CI->config->item($authorityFlag, 'fees_exemption_v2_flags')
            );
        } catch (\Throwable $_) {
            $useEntitlementAuthority = false;
        }

        // ── THE GUARD THAT MAKES DEFAULT-ON SAFE ────────────────────────────
        //
        // This flag decides WHICH COLLECTION PRICES A DEMAND. For a new school
        // the entitlement authority is simply correct, which is why it is now
        // the default rather than something an operator must remember.
        //
        // But a school already holding ACTIVE studentConcessions is mid-history,
        // not mid-setup: flipping its authority would stop applying discounts
        // that are currently reducing real bills — a silent price RISE for those
        // families, with no error anywhere. So intent (the flag) is separated
        // from safety (this check), and a school with legacy concessions keeps
        // the legacy authority until it is migrated deliberately.
        //
        // Fails SAFE: if the question cannot be answered, stay on legacy — the
        // authority that has been billing all along.
        if ($useEntitlementAuthority) {
            try {
                // ── AUTH-SCOPE-1 · THE HOLD IS PER STUDENT, NOT PER SCHOOL ──
                //
                // This check was school-wide, and that turned a migration
                // safeguard into a live financial defect. PROVEN BY EXECUTION
                // on a clean two-student tenant (Tuition 1,000/month):
                //
                //   B holds a 50% ENTITLEMENT, no legacy concession anywhere:
                //       gross 12,000  discount 4,000  net  8,000
                //   A — a DIFFERENT student — is then granted one legacy
                //   concession through /fee_concessions, and B is re-priced:
                //       gross 12,000  discount     0  net 12,000
                //
                // B's entitlement was still ACTIVE at 50% and had not been
                // touched. One operator action on someone else's record raised
                // B's bill by 4,000, silently, with no error and nothing on
                // B's record to explain it. Any school still holding one
                // legacy concession could never price an entitlement for
                // anybody — including every benefit the new policy layer
                // grants.
                //
                // The ORIGINAL intent is preserved exactly, and is the reason
                // this is scoped rather than deleted: a student whose bill is
                // currently being reduced by a legacy concession must keep the
                // legacy authority, or that discount would vanish and their
                // fees would rise. That reasoning was always about a STUDENT's
                // own concessions; applying it school-wide punished everyone
                // else for one family's history.
                //
                // Fails SAFE unchanged: if the question cannot be answered,
                // stay on legacy — the authority that has been billing all along.
                $legacy = $this->firebase->firestoreQuery('studentConcessions', [
                    ['schoolId',  '==', (string) $this->schoolName],
                    ['studentId', '==', (string) $studentId],
                    ['status',    '==', 'active'],
                ], null, 'ASC', 1);
                if (!empty($legacy)) {
                    $useEntitlementAuthority = false;
                    log_message('error', 'ENTITLEMENT_AUTHORITY_HELD school=[' . $this->schoolName
                        . '] student=[' . $studentId . '] — THIS STUDENT holds an ACTIVE legacy '
                        . 'studentConcession, so their pricing stays on the legacy authority. '
                        . 'Switching automatically would stop applying a discount that is '
                        . 'currently reducing their bill. Migrate this student deliberately. '
                        . 'Other students in this school are unaffected.');
                }
            } catch (\Throwable $e) {
                $useEntitlementAuthority = false;
                log_message('error', 'ENTITLEMENT_AUTHORITY_HELD school=[' . $this->schoolName
                    . '] — could not check for legacy concessions (' . $e->getMessage()
                    . '); staying on the legacy authority.');
            }
        }

        if ($useEntitlementAuthority) {
            if (!isset($CI->entitlementReader)) $CI->load->library('Fee_entitlement_reader', null, 'entitlementReader');
            $CI->entitlementReader->init($this->firebase);
            $pricingReader = $CI->entitlementReader;
        } else {
            if (!isset($CI->concessionReader)) $CI->load->library('Fee_concession_reader', null, 'concessionReader');
            $CI->concessionReader->init($this->firebase);
            $pricingReader = $CI->concessionReader;
        }

        if (!isset($CI->enrollmentReader)) $CI->load->library('Fee_service_enrollment_reader', null, 'enrollmentReader');
        if (!isset($CI->genSvc))           $CI->load->library('Fee_generation_service',        null, 'genSvc');
        $CI->enrollmentReader->init($this->firebase);
        $CI->genSvc->init($this, $pricingReader, $CI->enrollmentReader, $this->schoolName, $this->sessionYear);
        return $CI->genSvc->generateDemandsForStudent($studentId, $class, $section, $forPreview);
    }

    /**
     * Phase E — DRY-RUN spec build for the live fee preview.
     *
     * A thin public wrapper over the SAME private router assignInitialFees()
     * uses, so a preview is produced by the identical code that will generate
     * the real demands — concessions, flags and all. It deliberately adds no
     * logic of its own: a preview computed by a second implementation is a
     * preview that can disagree with the invoice, which is the divergence
     * class (G4/G12) this project exists to close.
     *
     * READ-ONLY by construction — the router only BUILDS specs; committing is
     * assignInitialFees()'s separate responsibility, and is not called here.
     */
    /**
     * P5-C — canonically-priced specs for a WRITING caller.
     *
     * Bulk generation batches ~400 Firestore ops across many students, so it
     * cannot call `assignInitialFees()` per student without collapsing that
     * batching into one commit per student (2,000 commits on a 2,000-student
     * school). This exposes the same canonical build so the caller keeps its
     * batching and still gets ONE pricing authority.
     *
     * ⚠ NOT `previewDemandSpecs()`. That passes `forPreview = true`, which
     * DISABLES duplicate suppression so a preview shows the full chart for an
     * already-admitted student. Writing those specs would re-emit charges that
     * already exist and — because the write path merges — reset `paidAmount`,
     * `balance` and `status` on demands that already carry a payment. The two
     * methods differ by one boolean and by whether using the wrong one destroys
     * money, which is exactly why they are named apart rather than sharing a
     * default argument.
     *
     * Fails CLOSED: `buildAdmissionDemandSpecs()` returns
     * `chartFound => false, refused => …` when the existing-demand read fails
     * (P5-0), so a caller that checks `chartFound` writes nothing.
     *
     * @return array{specs:array, assigned:array, chartFound:bool, refused?:string}
     */
    public function buildCanonicalDemandSpecs(string $studentId, string $class, string $section): array
    {
        try {
            return $this->_routedBuildAdmissionSpecs($studentId, $class, $section, /* forPreview */ false);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::buildCanonicalDemandSpecs failed [{$studentId}]: " . $e->getMessage());
            return ['specs' => [], 'assigned' => [], 'chartFound' => false,
                    'refused' => 'spec_build_failed'];
        }
    }

    /**
     * P5-C — allocation persistence for a caller that committed its own batch.
     *
     * `assignInitialFees()` persists allocations itself, AFTER its demands are
     * committed. A batching caller commits elsewhere, so it must invoke this
     * once its flush has succeeded — same ordering, same rule: never write a
     * ledger row describing a demand that failed to write.
     *
     * Inert while `USE_ALLOCATION_LEDGER` is ungranted: the generator attaches
     * no `allocations` key, so this is a no-op walk.
     */
    public function persistAllocationsForBatch(string $studentId, array $batchEntries): void
    {
        $this->_persistAllocations($studentId, $batchEntries);
    }

    public function previewDemandSpecs(string $studentId, string $class, string $section): array
    {
        try {
            // SE-001: forPreview=true. Prices the FULL chart regardless of what
            // the student already holds, because this method never persists —
            // the caller reads the returned specs and discards them. Without
            // it, duplicate suppression zeroes the preview for every
            // already-admitted student.
            return $this->_routedBuildAdmissionSpecs($studentId, $class, $section, /* forPreview */ true);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::previewDemandSpecs failed [{$studentId}]: " . $e->getMessage());
            return ['specs' => [], 'assigned' => [], 'chartFound' => false];
        }
    }

    /**
     * Phase D (G4) — is entitlement-aware service pricing switched on?
     * Fail-safe: ANY problem reading the flag returns false, i.e. bill the
     * full configured amount. Never under-bill on a config error.
     */
    /**
     * ── SVC-1 · DOES THIS STUDENT HOLD AN ACTIVE SERVICE ENTITLEMENT? ─────
     *
     * Detection only — this NEVER changes what is billed. It exists so that
     * writing a service demand at gross while an approved concession sits
     * unapplied becomes visible instead of silent.
     *
     * Deliberately asymmetric with DEC-013b's fail-closed: that guard REFUSES
     * generation when it cannot read the entitlement set, because refusing to
     * generate is recoverable. Here a read failure must NOT be reported as "a
     * concession is being ignored" — that would emit a money-loss alert on
     * every Firestore blip and train an operator to ignore the one that matters.
     * An unreadable set returns false, and the read failure is logged as itself.
     *
     * Memoised per request: a rollover writes many module demands per student,
     * so this costs one query per student rather than one per demand — the same
     * reasoning as Fees::_activeEntitlementCount().
     */
    private function _studentHoldsServiceEntitlement(string $studentId): bool
    {
        static $svcMemo = [];
        if ($studentId === '') return false;
        if (array_key_exists($studentId, $svcMemo)) return $svcMemo[$studentId];

        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI === null) return $svcMemo[$studentId] = false;

            if (!isset($CI->entitlementReader)) {
                $CI->load->library('Fee_entitlement_reader', null, 'entitlementReader');
            }
            $CI->entitlementReader->init($this->firebase);

            // Property names verified against init(): this class stores
            // `schoolName` / `sessionYear`, NOT schoolId / session. Passing the
            // wrong names would hand the reader two empty strings — it would
            // return nothing, the probe would never fire, and SVC-1 would look
            // closed while detecting nothing at all.
            $held = $CI->entitlementReader->activeConcessionsForStudent(
                $this->schoolName, $studentId, $this->sessionYear
            );
            return $svcMemo[$studentId] = (is_array($held) && $held !== []);
        } catch (\Throwable $e) {
            log_message('error',
                'SVC-1 entitlement probe failed for student [' . $studentId . '] — '
                . 'NOT reporting an unapplied concession on an unreadable set: ' . $e->getMessage());
            return $svcMemo[$studentId] = false;
        }
    }

    private function _serviceEntitlementPricingEnabled(): bool
    {
        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI === null || !isset($CI->config)) return false;
            $CI->config->load('fees_exemption_v2_flags', true);
            // T2: tenant-scoped; the config value is the global default.
            $flag = 'USE_ENTITLEMENT_SERVICE_PRICING';
            return $this->_rolloutEnabled($flag, (bool) $CI->config->item($flag, 'fees_exemption_v2_flags'));
        } catch (\Throwable $_) {
            return false;
        }
    }

    /**
     * Phase D (G4) — resolve the net payable for one service fee through
     * Service_entitlement_pricing. Isolated here so createModuleFee stays
     * readable and so every failure mode funnels through one fail-open
     * return (gross, zero deduction).
     *
     * @return array{net: float, deduction: float}
     */
    /**
     * Period-aware sibling of _priceServiceFee(). Same libraries, same reader,
     * same fail-open-to-gross contract — the only difference is that pricing is
     * asked about the REAL months the service ran instead of the session-start
     * month the demand happens to be filed under.
     *
     * @param array<string, float> $monthlyFares periodKey => fare for that month
     * @return array{gross: float, net: float, deduction: float}
     */
    private function _priceServiceFeeByPeriods(
        string $studentId, string $moduleType, string $headName,
        array $monthlyFares, string $feeHeadRef = ''
    ): array {
        $gross = round(array_sum($monthlyFares), 2);
        $open  = ['gross' => $gross, 'net' => $gross, 'deduction' => 0.0];

        // The gross is period-derived and therefore correct regardless of the
        // pricing flag: it is the fare for months actually served, not a
        // discount. Only the DEDUCTION is entitlement-driven, so the flag
        // gates that half alone. Skipping the whole block when the flag is off
        // would have reintroduced the inverted proration this replaces.
        if (!$this->_serviceEntitlementPricingEnabled()) return $open;

        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI === null) return $open;

            foreach ([
                'Fee_entitlement_reader'      => 'entitlementReader',
                'Service_entitlement_pricing' => 'servicePricing',
            ] as $cls => $alias) {
                if (isset($CI->$alias)) continue;
                if (!file_exists(APPPATH . 'libraries/' . $cls . '.php')) {
                    log_message('error', "SERVICE_ENTITLEMENT_PRICING_UNAVAILABLE — {$cls}.php missing; billing GROSS.");
                    return $open;
                }
                $CI->load->library($cls, null, $alias);
            }

            $CI->entitlementReader->init($this->firebase);
            $CI->servicePricing->init($CI->entitlementReader, $this->schoolName, $this->sessionYear);

            $r = $CI->servicePricing->priceForPeriods(
                $studentId, strtolower($moduleType), $headName, $monthlyFares, $feeHeadRef
            );

            return [
                'gross'     => (float) ($r['gross'] ?? $gross),
                'net'       => (float) ($r['net'] ?? $gross),
                'deduction' => (float) ($r['deduction'] ?? 0.0),
            ];
        } catch (\Throwable $e) {
            log_message('error', "SERVICE_PERIOD_PRICING_FAILED [{$studentId}/{$moduleType}] — billing GROSS: " . $e->getMessage());
            return $open;
        }
    }

    private function _priceServiceFee(string $studentId, string $moduleType, string $headName, string $periodKey, float $gross, string $feeHeadRef = ''): array
    {
        $gross = round($gross, 2);
        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI === null) return ['net' => $gross, 'deduction' => 0.0];

            // Guard the loader the same way Phase C does: CI_Loader::library()
            // on a missing file ends in show_error()→exit(), which try/catch
            // cannot trap, and that would take down a real billing write.
            foreach ([
                'Fee_entitlement_reader'      => 'entitlementReader',
                'Service_entitlement_pricing' => 'servicePricing',
            ] as $cls => $alias) {
                if (isset($CI->$alias)) continue;
                if (!file_exists(APPPATH . 'libraries/' . $cls . '.php')) {
                    log_message('error', "SERVICE_ENTITLEMENT_PRICING_UNAVAILABLE — {$cls}.php missing; billing GROSS.");
                    return ['net' => $gross, 'deduction' => 0.0];
                }
                $CI->load->library($cls, null, $alias);
            }

            $CI->entitlementReader->init($this->firebase);
            $CI->servicePricing->init($CI->entitlementReader, $this->schoolName, $this->sessionYear);

            $r = $CI->servicePricing->priceFor(
                $studentId, strtolower($moduleType), $headName, $periodKey, $gross, $feeHeadRef
            );
            return ['net' => (float) $r['net'], 'deduction' => (float) $r['deduction']];
        } catch (\Throwable $e) {
            log_message('error', "SERVICE_ENTITLEMENT_PRICING_FAILED [{$studentId}/{$moduleType}] — billing GROSS: " . $e->getMessage());
            return ['net' => $gross, 'deduction' => 0.0];
        }
    }

    /**
     * P5 step 2 — persist the allocation rows the generator attached.
     *
     * Rows ride on `$entry['allocations']`, never inside `$entry['data']`, so
     * they were never part of the demand document. With `USE_ALLOCATION_LEDGER`
     * off the generator attaches nothing and this walk finds no rows — a true
     * no-op, no reads, no writes, no behaviour change.
     *
     * FAIL-SOFT, DELIBERATELY. The demands are already committed by the time
     * this runs. Losing a ledger row must never fail a billing write that
     * already succeeded, so every outcome is counted and logged rather than
     * thrown. The counts make a missed row reconcilable after the fact
     * (`ACC_ALLOCATION_PERSIST_FAILED` is greppable).
     *
     * Idempotent end to end: allocation ids are deterministic, so a re-run
     * collides rather than duplicating, and a partially-written set completes on
     * the next pass instead of doubling.
     */
    private function _persistAllocations(string $studentId, array $batchEntries): void
    {
        $rows = [];
        foreach ($batchEntries as $entry) {
            if (!empty($entry['allocations']) && is_array($entry['allocations'])) {
                foreach ($entry['allocations'] as $r) $rows[] = $r;
            }
        }
        if ($rows === []) return;   // flag off, or nothing discounted

        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI === null) return;
            if (!isset($CI->allocationSvc)) {
                if (!file_exists(APPPATH . 'libraries/Entitlement_allocation_service.php')) {
                    log_message('error', 'ACC_ALLOCATION_PERSIST_FAILED reason=[service_missing] rows='
                        . count($rows));
                    return;
                }
                $CI->load->library('Entitlement_allocation_service', null, 'allocationSvc');
            }
            $res = $CI->allocationSvc
                ->init($this->firebase, null, $this->schoolName, $this->sessionYear, $this->adminId)
                ->persist($rows);

            log_message('error', sprintf(
                'ACC_ALLOCATION_PERSISTED student=[%s] school=[%s] created=%d duplicate=%d failed=%d',
                $studentId, $this->schoolName,
                (int) $res['created'], (int) $res['duplicate'], (int) $res['failed']
            ));
        } catch (\Throwable $e) {
            log_message('error', "ACC_ALLOCATION_PERSIST_FAILED student=[{$studentId}]: " . $e->getMessage());
        }
    }

    public function assignInitialFees(
        string $studentId,
        string $class,
        string $section,
        string $parentDbKey = ''
    ): array {
        $assigned = [];
        try {
            // Phase 2 router: USE_UNIFIED_FEE_GEN gates the concession-aware
            // path; flag off = byte-identical to Phase 1 (proven 9/9 A/B).
            $built = $this->_routedBuildAdmissionSpecs($studentId, $class, $section);

            // ── FEEHEAD-MASK-1 · AN EXPLICIT REFUSAL MUST NOT READ AS "DONE" ──
            //
            // buildAdmissionDemandSpecs() fails CLOSED on an unresolvable fee
            // head and says so precisely:
            //     ['ok' => false, 'specs' => [], 'chartFound' => true,
            //      'error' => 'fee_head_unresolved', 'feeHead' => …, 'reason' => …]
            //
            // Note `chartFound => true`, and no 'assigned' key. The only guard
            // below tests chartFound, so a refusal walked straight past it,
            // committed an empty batch, and returned [] — identical to the
            // return for a student who legitimately had nothing to bill.
            //
            // Measured on a clean fixture with USE_FEE_HEAD_REGISTRY granted and
            // an empty feeHeadRegistry: every admission logged
            // ACC_FEEHEAD_UNRESOLVED and produced demands=0, while admission
            // itself reported SUCCESS. The student is admitted and unbillable,
            // and the one line explaining why is buried among the demands that
            // were never written.
            //
            // The refusal is re-stated here in the caller's own terms and
            // returned in the CONTRACT-CORRECT shape (always an array, never
            // null), so the business reason survives instead of being replaced
            // by a downstream missing-key symptom.
            if (($built['ok'] ?? true) === false) {
                log_message('error', 'Fee_lifecycle::assignInitialFees REFUSED for [' . $studentId
                    . '] error=[' . (string) ($built['error'] ?? 'unknown') . '] head=['
                    . (string) ($built['feeHead'] ?? '?') . '] reason=['
                    . (string) ($built['reason'] ?? '?') . '] — NO demands were generated and '
                    . 'this student is unbillable until it is resolved. Admission itself is '
                    . 'unaffected.');
                return [];
            }

            if (!$built['chartFound']) {
                // Visibility fix: a missing fee structure at admission time
                // means this student silently gets ZERO demands (bills) and
                // is unpayable at the counter until someone re-saves the
                // class Fee Structure or runs Generate Demands. Raise to
                // 'warning' (was 'info') so it's monitorable, and make the
                // remedy explicit. Admission still succeeds — this is a
                // best-effort, non-fatal signal.
                log_message('error', "Fee_lifecycle::assignInitialFees — NO fee structure for [{$class}/{$section}]; student [{$studentId}] admitted with ZERO fee demands. Set up fees for this class then run Generate Demands (or re-save the Fee Structure) to bill this student.");
                return [];
            }

            $batchEntries = $built['specs'];
            $assigned     = $built['assigned'];

            if (!empty($batchEntries)) {
                if (!$this->fsTxn->commitDemandBatch($batchEntries)) {
                    log_message('error', "Fee_lifecycle::assignInitialFees commitDemandBatch failed for [{$studentId}]; falling back to per-doc writes");
                    foreach ($batchEntries as $entry) {
                        $this->fsTxn->writeDemand($entry['demandId'], $entry['data']);
                    }
                }
                // ── P4 PERSISTENCE (P5 step 2) ──────────────────────────────
                // Ordering is load-bearing: allocations are written ONLY AFTER
                // the demands they describe are committed. An allocation
                // referencing a demand that failed to write would be a ledger
                // row about a charge that does not exist.
                //
                // Inert unless USE_ALLOCATION_LEDGER is granted (ships false),
                // and the generator only attaches `allocations` under that same
                // flag — so with it off `$batchEntries` carries no such key and
                // this is a no-op walk.
                $this->_persistAllocations($studentId, $batchEntries);
            }

            $this->_log('initial_fees_assigned', $studentId, [
                'class'         => $class,
                'section'       => $section,
                'fee_heads'     => $assigned,
                'count'         => count($assigned),
                'parent_db_key' => $parentDbKey,
            ]);

        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::assignInitialFees failed for [{$studentId}]: " . $e->getMessage());
        }
        return $assigned;
    }

    // ═════════════════════════════════════════════════════════════════
    //  2. reassignFeesOnPromotion
    // ═════════════════════════════════════════════════════════════════

    /**
     * Student moved class/section. Archive everything from the old
     * class and regenerate from the new class's structure. Paid and
     * partial demands are LEFT in place under their original class so
     * payment history stays intact; only unpaid demands from the old
     * class are archived.
     */
    public function reassignFeesOnPromotion(
        string $studentId,
        string $oldClass,
        string $oldSection,
        string $newClass,
        string $newSection,
        string $parentDbKey = ''
    ): array {
        $archived = 0; $preserved = 0;
        try {
            // BUG-045 Phase 2 (2026-05-25): collect-then-batch pattern.
            // Pre-fix issued one Firestore PATCH per archived demand inside
            // the loop. Now we accumulate archive ops and emit a single
            // :commit per student. Fallback to per-doc updateDemand if the
            // commit fails so semantics stay identical.
            $archiveEntries = [];
            $archivedAt = date('c');
            // BUG-076 (2026-05-27): use _demandsForAllSessions so the archive
            // loop is not blocked by session-selector drift on the operator
            // side. The archive decision below is purely className/section
            // based; session was never load-bearing for correctness.
            foreach ($this->_demandsForAllSessions($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                // Only archive UNPAID demands; paid/partial demands
                // represent real payment history.
                if ($st !== 'unpaid') { $preserved++; continue; }

                // ── PROMO-SC-1 · A ONE-OFF CHARGE IS NOT CLASS-DERIVED ──────
                //
                // The predicate below archives every unpaid demand whose
                // class/section differs from the target. That is right for
                // TUITION — Class 9 fees genuinely are not Class 10 fees, and
                // assignInitialFees() regenerates them from the new chart
                // immediately after this loop.
                //
                // It is wrong for a MODULE charge. A lost book, a broken
                // window, an ID-card replacement, a transport fare — none of
                // them is priced from the class chart, and NOTHING regenerates
                // them: the regeneration below reads the fee chart only, and
                // createModuleFee is never called on this path.
                //
                // So promoting a student — or merely correcting their section —
                // silently archived every outstanding one-off charge and the
                // school lost the money. `createModuleFee` stamps the student's
                // class and section on the demand, so every module charge fails
                // the comparison the moment either changes.
                //
                // Identified by an explicit MARKER, not by naming modules here.
                //
                // Two earlier attempts at a discriminator were wrong, and both
                // failures are worth recording:
                //
                //   • a list of module CATEGORIES ('transport', 'idcard', …)
                //     couples this generic method to specific modules — which
                //     existing guards correctly forbid — AND does not work,
                //     because with the fee-head registry ON the M5 stamp
                //     OVERRIDES the module-name category with the registry's
                //     own ('administrative' for an ID card). It would have
                //     protected nothing on exactly the tenants that have the
                //     registry enabled.
                //
                //   • parsing the module out of the demand id is the
                //     re-derive-identity-from-a-string anti-pattern this whole
                //     phase exists to remove.
                //
                // `createModuleFee()` now stamps `moduleType` on every charge it
                // writes. Its presence is the fact that matters — this demand
                // was not priced from a class chart — and a fifth module
                // producer inherits the protection with no change here.
                if (trim((string) ($d['moduleType'] ?? '')) !== '') { $preserved++; continue; }

                // Skip demands that already belong to the target class.
                if (((string) ($d['className'] ?? '')) === $newClass &&
                    ((string) ($d['section']   ?? '')) === $newSection) continue;

                $archiveEntries[] = [
                    'op'       => 'archive',
                    'demandId' => $did,
                    'data'     => [
                        'status'        => 'archived',
                        'archivedAt'    => $archivedAt,
                        'archivedBy'    => $this->adminId,
                        'archivedFrom'  => "{$oldClass}/{$oldSection}",
                    ],
                ];
                $archived++;
            }

            // BUG-045 Phase 2: single batched archive commit.
            if (!empty($archiveEntries)) {
                if (!$this->fsTxn->commitDemandBatch($archiveEntries)) {
                    log_message('error', "Fee_lifecycle::reassignFeesOnPromotion commitDemandBatch failed for [{$studentId}]; falling back to per-doc updates");
                    foreach ($archiveEntries as $entry) {
                        $this->fsTxn->updateDemand($entry['demandId'], $entry['data']);
                    }
                }
            }

            // Generate the new class's structure.
            $regenerated = $this->assignInitialFees($studentId, $newClass, $newSection, $parentDbKey);

            // BUG-076 Part 2 (2026-05-28): fail-loud guard on silent zero-
            // demand regeneration. assignInitialFees returns [] when the
            // destination class/section has NO fee structure in the active
            // session — historically a silent info-level no-op. That is
            // exactly how STU0004-11 ended up Active-in-Class-8 with zero
            // demands: a cross-session reverse-promote regenerated against a
            // session (2027-28) that had no Class 8 structure, generated
            // nothing, and reported "success". Surface it at error level AND
            // stamp the audit entry so the gap is actionable the moment it
            // happens, instead of being discovered weeks later via phantom-
            // dues forensics.
            $regenCount = is_array($regenerated) ? count($regenerated) : 0;
            if ($regenCount === 0) {
                log_message('error',
                    "Fee_lifecycle::reassignFeesOnPromotion — assignInitialFees generated "
                    . "0 demands for [{$studentId}] into [{$newClass}/{$newSection}] "
                    . "(session={$this->sessionYear}). Student may now have NO active fee "
                    . "demands. Verify a fee structure exists for {$newClass}/{$newSection} "
                    . "in session {$this->sessionYear}.");
            }

            $this->_log('promotion_reassigned', $studentId, [
                'old_class'   => $oldClass, 'old_section' => $oldSection,
                'new_class'   => $newClass, 'new_section' => $newSection,
                'archived'    => $archived, 'preserved'   => $preserved,
                'regenerated' => $regenCount,
                'regen_warning' => $regenCount === 0 ? 'NO_DESTINATION_STRUCTURE' : '',
            ]);

            // DEFAULTER-PROMO-1 fix (2026-06-29): recompute defaulter status with
            // the SAME business logic Admission uses
            // (Fee_defaulter_check::updateDefaulterStatus) — it reads feeDemands
            // canonically and writes the real projection, deleting the
            // feeDefaulters doc ONLY when dues are genuinely zero. The prior code
            // hardcoded is_defaulter=false, which unconditionally DELETED the
            // doc for a still-owing promoted student (they vanished from the
            // defaulter report until their next payment).
            try {
                $CI = function_exists('get_instance') ? get_instance() : null;
                if ($CI !== null) {
                    if (!isset($CI->feeDefaulter) || !is_object($CI->feeDefaulter)) {
                        $CI->load->library('Fee_defaulter_check', null, 'feeDefaulter');
                    }
                    $CI->feeDefaulter->init($this->firebase, $this->schoolName, $this->sessionYear);
                    $CI->feeDefaulter->updateDefaulterStatus($studentId);
                }
            } catch (\Throwable $_) {}

        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::reassignFeesOnPromotion failed [{$studentId}]: " . $e->getMessage());
        }
        // SIS Tier-1 fix B5 (2026-05-31): expose $regenCount in the return so
        // the promotion caller can detect the silent-zero-regenerated case
        // (destination structure deleted between Sis::execute_promotion's
        // upfront guard at L967 and this per-student call). Existing fields
        // preserved — sole caller is Sis::execute_promotion deferred handler
        // (grep-verified). Additive change; no other consumer breaks.
        return [
            'archived'    => $archived,
            'preserved'   => $preserved,
            'regenerated' => $regenCount ?? 0,
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  3. proRateFees
    // ═════════════════════════════════════════════════════════════════

    /**
     * Student joined mid-year → reduce remaining demands to reflect the
     * months remaining in the academic year. Prorates netAmount +
     * balance on unpaid/partial demands whose period is >= joinDate.
     */
    public function proRateFees(string $studentId, string $joinDate, string $feeType = 'all'): array
    {
        $adjusted = 0; $skipped = 0;
        try {
            $joinTs = strtotime($joinDate) ?: time();
            $joinMonth = (int) date('n', $joinTs);
            $remaining = $this->_remainingAcademicMonths($joinMonth);
            if ($remaining <= 0 || $remaining >= 12) {
                return ['adjusted' => 0, 'skipped' => 0];
            }
            $factor = $remaining / 12.0;

            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                if ($st === 'paid' || $st === 'archived') { $skipped++; continue; }
                $freq = strtolower((string) ($d['frequency'] ?? 'monthly'));
                // Monthly demands are already per-month — no prorate. Annual
                // / Yearly demands are the ones that need scaling.
                if ($freq !== 'annual' && $freq !== 'yearly') { $skipped++; continue; }
                // Optional filter: proRate only a single fee type. G2 fix
                // (2026-08-07): category is stored lowercase by createModuleFee
                // (same FIN-2 normalization as cancelSessionDemands) — comparing
                // against a caller-supplied mixed-case feeType like 'Transport'
                // never matched, so this filter silently skipped every Transport
                // demand and made proRateFees a structural no-op for every
                // existing caller (assignStudent/unassignStudent/changeRoute).
                if ($feeType !== 'all' && strtolower((string) ($d['category'] ?? $d['feeHead'] ?? '')) !== strtolower($feeType)) {
                    $skipped++; continue;
                }
                // ── DEC-012 · DISCOUNT-AWARE PRORATION (Option A) ───────────
                //
                // THE DEFECT THIS CLOSES. This used to be
                //     $newNet = grossAmount * $factor;
                // — re-derived from GROSS, ignoring `discountAmount` and never
                // updating it. A demand carrying an approved concession came
                // out as gross 2400 / discount 240 / net 1200: the concession
                // was silently erased AND the demand no longer satisfied
                // `gross − discount == net`. Proration is the LAST fee-affecting
                // step in all five Transport callers, so it ran after pricing
                // and undid it.
                //
                // OPTION A, chosen deliberately: proration scales the CHARGE, so
                // gross and discount scale together and the invariant holds by
                // construction. The alternatives (absorb proration into
                // `discountAmount`, or add a third `prorationAmount` field) bill
                // the family the IDENTICAL amount — the choice was purely about
                // what `grossAmount` means afterwards. Under A it means "the
                // charge for the period actually covered", which is also what
                // `createModuleFee` already does when it re-prices a service
                // demand in place (Transport_service:2754).
                //
                // THE LIST ANCHOR. `listGrossAmount` / `listDiscountAmount`
                // record the pre-proration charge and are written ONCE. Without
                // them a second proration would compound (2400 → 1200 → 600);
                // with them every run recomputes from the original, so
                // proration is IDEMPOTENT and the original charge stays
                // recoverable. This is the narrowest first instance of the
                // DEC-001 charge anchor.
                //
                // Measured before shipping: 89 proration-eligible demands
                // estate-wide, ZERO carrying a discount and ZERO already
                // prorated — so this changes no existing money.
                $curGross  = (float) ($d['grossAmount'] ?? $d['netAmount'] ?? 0);
                $curDisc   = (float) ($d['discountAmount'] ?? $d['discount_amount'] ?? 0);
                $listGross = array_key_exists('listGrossAmount', $d)
                    ? (float) $d['listGrossAmount'] : $curGross;
                $listDisc  = array_key_exists('listDiscountAmount', $d)
                    ? (float) $d['listDiscountAmount'] : $curDisc;

                $newGross  = round($listGross * $factor, 2);
                $newDisc   = round($listDisc  * $factor, 2);
                $newNet    = round($newGross - $newDisc, 2);
                $paidAmt   = (float) ($d['paidAmount'] ?? 0);
                // Phase 15.5 (F-1) — FINE-AWARE PRORATION.
                //
                // THE DEFECT THIS CLOSES: the balance was re-derived as
                // (newNet - paid), silently DROPPING any late fee already
                // posted to this demand. The fine engine maintains
                // `balance = netAmount + fine - paidAmount`
                // (Fees::compute_fines_for_student), so every proration event
                // — mid-session admission, service suspension, entitlement
                // change — erased the penalty and handed the family the money
                // back with no ledger entry and no audit trail.
                //
                // Dormant before this phase only because no demand carried a
                // dueDate, so no fine could exist. Stamping due dates (AB)
                // makes it live, which is why these two ship together.
                //
                // A fine is NOT prorated: it penalises lateness, not months of
                // service, so it does not scale with $factor. Reading both key
                // spellings because demand docs are normalised camel→snake by
                // Fee_firestore_txn::normalizeDemandDoc() and this method can
                // receive either shape depending on the caller.
                $fineAmt   = (float) ($d['fineAmount'] ?? $d['fine_amount'] ?? 0);
                $newBal    = round($newNet + $fineAmt - $paidAmt, 2);
                // OVER-COLLECTION GUARD (2026-08-08, Phase A verification pass).
                // Proration can push netAmount BELOW what the family already
                // paid (e.g. a mostly-paid annual fee suspended late in the
                // session). The balance floors at 0, so the excess is silently
                // retained with no credit recorded anywhere. Pre-Phase-A this
                // branch was unreachable for Transport (proRateFees was a
                // structural no-op — the G2 bug); the G1/G2 fix makes it
                // reachable, so it must at minimum be OBSERVABLE. The remedy —
                // a credit note against the student's ledger — is specified as
                // Phase C / AC4 in STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md
                // and is deliberately NOT implemented here. Grep
                // FEE_PRORATE_OVERCOLLECTION to find affected students.
                if ($newBal < 0) {
                    log_message('error', sprintf(
                        'FEE_PRORATE_OVERCOLLECTION student=[%s] demand=[%s] paid=%.2f newNet=%.2f excess=%.2f '
                        . '— balance floored to 0; credit owed to family is NOT yet recorded (Phase C / AC4).',
                        $studentId, $did, $paidAmt, $newNet, abs($newBal)
                    ));
                    $newBal = 0;
                }
                $this->fsTxn->updateDemand($did, [
                    // DEC-012: gross and discount scale together, so
                    // `gross − discount == net` still holds after proration.
                    'grossAmount'    => $newGross,
                    'discountAmount' => $newDisc,
                    'netAmount'      => $newNet,
                    'balance'        => $newBal,
                    // The list anchor — written once, identical on every re-run,
                    // which is what makes proration idempotent.
                    'listGrossAmount'    => $listGross,
                    'listDiscountAmount' => $listDisc,
                    'prorated'       => true,
                    'prorateFactor'  => $factor,
                ]);
                $adjusted++;
            }

            $this->_log('fees_prorated', $studentId, [
                'join_date' => $joinDate, 'fee_type' => $feeType,
                'factor'    => $factor,   'remaining_months' => $remaining,
                'adjusted'  => $adjusted, 'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::proRateFees failed [{$studentId}]: " . $e->getMessage());
        }
        return ['adjusted' => $adjusted, 'skipped' => $skipped];
    }

    // ═════════════════════════════════════════════════════════════════
    //  4. calculateSiblingDiscount
    // ═════════════════════════════════════════════════════════════════

    /**
     * ══ RETIRED · SIB-DEF-1 · ONE OF THREE DISAGREEING SIBLING DEFINITIONS ══
     *
     * Was: a sibling-discount lookup that grouped students by `parentDbKey` and
     * read a school policy from feeSettings/{school}_sibling_policy
     * (`secondChildDiscountPercent`).
     *
     * DEAD, AND PROVEN SO: zero callers anywhere in the codebase. It has never
     * priced anything. The `_sibling_policy` document it reads has no writer
     * either — no surface in ZenX creates one — so even if it were called it
     * would resolve 0% for every school.
     *
     * WHY IT IS REFUSED RATHER THAN LEFT LYING AROUND. ZenX carries THREE
     * different answers to "who is a sibling?", and that is the actual defect:
     *   1. THIS — parentDbKey grouping. Dead.
     *   2. Fee_management's bulk concession `criteria = 'sibling'` — LIVE — which
     *      groups by FATHER-NAME STRING EQUALITY. It merges unrelated families
     *      who share a common name, and splits real siblings whose records
     *      spell the father differently. It lives at a parked commit boundary
     *      and is reported, not touched, by this workstream.
     *   3. `studentRelationships` — the new VERIFIED model: a link exists only
     *      because a human resolved an SR Number against this tenant and
     *      confirmed the person. That is the intended canonical authority.
     *
     * Leaving a dead second definition callable invites someone to wire it up
     * as the "existing" answer. It now fails closed and says where to go.
     *
     * NOTHING IS DELETED: no data is touched, and the method still exists so
     * any unknown caller gets a loud, safe refusal rather than a fatal error.
     * Retiring the LIVE father-name path, and deciding whether a verified
     * sibling link should grant money at all, are both product decisions that
     * have not been taken.
     *
     * @deprecated Use Student_relationship_service::forStudent($id, 'sibling').
     */
    public function calculateSiblingDiscount(
        string $parentDbKey,
        string $studentId,
        string $schoolCode
    ): array {
        log_message('error',
            'SIB-DEF-1 calculateSiblingDiscount() is RETIRED and was called for student=['
            . $studentId . '] school=[' . $schoolCode . ']. It grouped siblings by parentDbKey, '
            . 'which is not the canonical definition. Use the VERIFIED relationships in '
            . 'studentRelationships (Student_relationship_service), and grant any benefit '
            . 'through the entitlement path so it carries a reason, an approver and an '
            . 'allocation. Returning "no discount" — no pricing decision has been made here.');

        return ['sibling_count' => 0, 'sibling_ids' => [], 'discount_pct' => 0.0, 'applies' => false];

        $siblings = [];
        try {
            // Phase 5 — students are keyed per-school in Firestore. A
            // parent's children are discoverable via the `parentDbKey`
            // field on each student doc.
            $rows = $this->firebase->firestoreQuery('students', [
                ['schoolId',    '==', $this->schoolName],
                ['parentDbKey', '==', $parentDbKey],
            ]);
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $sid = (string) ($d['studentId'] ?? $d['userId'] ?? ($r['id'] ?? ''));
                if ($sid === '' || $sid === $studentId) continue;
                $siblings[] = $sid;
            }

            // Read the school's sibling-discount policy from Firestore
            // feeSettings (replaces the legacy RTDB policy path).
            $policy = $this->firebase->firestoreGet('feeSettings', "{$this->schoolName}_sibling_policy");
            $policyPct = is_array($policy) ? (float) ($policy['secondChildDiscountPercent'] ?? 0) : 0.0;

            return [
                'sibling_count'   => count($siblings),
                'sibling_ids'     => $siblings,
                'discount_pct'    => $policyPct,
                'applies'         => count($siblings) >= 1 && $policyPct > 0,
            ];
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::calculateSiblingDiscount failed [{$studentId}]: " . $e->getMessage());
            return ['sibling_count' => 0, 'sibling_ids' => [], 'discount_pct' => 0.0, 'applies' => false];
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  5. freezeFeesOnSoftDelete
    // ═════════════════════════════════════════════════════════════════

    public function freezeFeesOnSoftDelete(string $studentId): bool
    {
        $frozen = 0;
        try {
            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                // Leave paid/archived alone; we only freeze collectable demands.
                if (in_array($st, ['paid', 'archived', 'frozen'], true)) continue;
                $this->fsTxn->updateDemand($did, [
                    'status'    => 'frozen',
                    'frozenAt'  => date('c'),
                    'frozenBy'  => $this->adminId,
                ]);
                $frozen++;
            }
            $this->_log('fees_frozen', $studentId, ['demands_frozen' => $frozen]);
            return true;
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::freezeFeesOnSoftDelete failed [{$studentId}]: " . $e->getMessage());
            return false;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  6. createModuleFee  (Hostel / Transport / Activity …)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Write a one-off module-fee demand — used when a student opts into
     * Transport or Hostel mid-year and a new demand has to be created.
     * The demand is categorised so clearance checks + reports can group
     * it correctly.
     */
    /**
     * TMS F3 (2026-07-07) — cancel all outstanding demands for a specific
     * student in a specific session for the given module.
     *
     * Used by Transport session rollover to clean up the old session's
     * transport demands before creating new session's demands. Preserves
     * historical demand records (never deletes) — flips status to 'cancelled'
     * and records the cancellation actor + reason.
     *
     * The session parameter is the Indian academic session (e.g. "2026-27");
     * internally maps to the periodKey format createModuleFee wrote.
     *
     * @return int Number of demands cancelled.
     */
    public function cancelSessionDemands(string $studentId, string $session, string $moduleType = 'Transport', string $reason = 'session_rollover'): int
    {
        if ($studentId === '' || $session === '' || $moduleType === '') {
            return 0;
        }
        // Session periodKey — Transport demands created via createModuleFee
        // use format YYYY-04 (Indian FY start month).
        $year = (int) substr($session, 0, 4);
        $periodKey = sprintf('%04d-%02d', $year, self::ACADEMIC_START_MONTH);

        try {
            // FIN-2: category is stored lowercase by createModuleFee
            // (strtolower). Firestore equality is case-sensitive, so a caller
            // passing 'Transport' would match ZERO rows and never cancel the
            // old-session demands on rollover (phantom cross-session dues).
            // Normalise the predicate to match what was written.
            $rows = (array) $this->fs->schoolWhere('feeDemands', [
                ['studentId', '==', $studentId],
                ['category',  '==', strtolower((string) $moduleType)],
                ['periodKey', '==', $periodKey],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_lifecycle::cancelSessionDemands query failed: ' . $e->getMessage());
            return 0;
        }
        $cancelled = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            // FIN-2: schoolWhere returns nested {id, data:{…}} rows. Read the
            // demand fields from the data level (fallback to a flat row) so the
            // paid/cancelled skip actually works — reading the status off the
            // envelope level is always empty for a nested row, which would
            // wrongly cancel a PAID demand on rollover.
            $d = (isset($row['data']) && is_array($row['data'])) ? $row['data'] : $row;
            $status = (string) ($d['status'] ?? '');
            if ($status === 'cancelled' || $status === 'paid') {
                continue;   // don't cancel already-paid or already-cancelled
            }

            // ── XMOD-1 · CATEGORY IS NOT THE MODULE ─────────────────────────
            //
            // The query above matches on `category`, and with the fee-head
            // registry ON that value is the REGISTRY's, not the module's — the
            // M5 stamp deliberately overrides it. So a Student Charge raised
            // against the "Transport Fee" head is stored with
            // category='transport' and is matched by a TRANSPORT rollover,
            // which would cancel a lost-book charge that has nothing to do
            // with transport.
            //
            // The demand's own `moduleType` is the module that raised it and is
            // never overridden. Filtered HERE rather than in the query
            // deliberately: adding the field to the predicate would need a new
            // composite index, and an undeployed index fails the query closed
            // in production — a far worse outcome than an in-memory check over
            // one student's handful of rows.
            //
            // Absent on demands written before this field existed, and those
            // must keep working: an empty marker falls through to the category
            // match exactly as before.
            $rowModule = trim((string) ($d['moduleType'] ?? ''));
            if ($rowModule !== '' && strcasecmp($rowModule, $moduleType) !== 0) {
                continue;   // another module's charge — not this rollover's business
            }
            $demandId = (string) ($d['demandId'] ?? $row['id'] ?? $d['id'] ?? '');
            if ($demandId === '') { continue; }
            try {
                $this->fsTxn->writeDemand($demandId, [
                    'status'        => 'cancelled',
                    'cancelledAt'   => date('c'),
                    'cancelledBy'   => $this->adminId,
                    'cancelReason'  => $reason,
                    'updatedAt'     => date('c'),
                ]);
                $cancelled++;
            } catch (\Throwable $e) {
                log_message('error', 'Fee_lifecycle::cancelSessionDemands writeDemand failed for ' . $demandId . ': ' . $e->getMessage());
            }
        }
        $this->_log('session_demands_cancelled', $studentId, [
            'module'    => $moduleType,
            'session'   => $session,
            'cancelled' => $cancelled,
            'reason'    => $reason,
        ]);
        return $cancelled;
    }

    public function createModuleFee(string $studentId, string $moduleType, array $feeData): ?string
    {
        try {
            $stu = $this->_studentDoc($studentId);
            if ($stu === null) return null;

            $headName   = (string) ($feeData['fee_head']  ?? $feeData['feeHead'] ?? "{$moduleType} Fee");
            $amount     = (float)  ($feeData['amount']    ?? 0);
            $frequency  = strtolower((string) ($feeData['frequency'] ?? 'Monthly'));
            if ($amount <= 0) return null;

            // WHICH OCCURRENCE of this head — optional, and only the ID-card
            // replacement path supplies it ('V{cardVersion}'). Transport and
            // Hostel pass nothing, so their demand ids stay byte-identical.
            $chargeInstance = trim((string) ($feeData['chargeInstance'] ?? $feeData['instance'] ?? ''));

            // ── M5 · MODULE HEAD MUST ALREADY BE REGISTERED ────────────────
            // Module callers do not yet carry a feeHeadRef, so the head is
            // bound by EXACT normalised name against the registry's own name
            // claim — an exact-match lookup of an authoritative record, not a
            // keyword heuristic. The category still comes from the registry.
            // An unregistered module head FAILS CLOSED: no runtime minting,
            // which is what stops anonymous financial heads reappearing.
            $moduleM5 = [];
            if ($this->_registryModeOn()) {
                $stamp = $this->_registryStamp('', $headName);
                if (empty($stamp['ok'])) {
                    log_message('error', 'ACC_FEEHEAD_UNRESOLVED path=module module=[' . $moduleType
                        . '] student=[' . $studentId . '] school=[' . $this->schoolName . '] head=[' . $headName
                        . '] reason=[' . ($stamp['reason'] ?? '?') . '] — charge refused (fail closed)');
                    return null;
                }
                $moduleM5 = $stamp['fields'];
            }

            // ── ROLL-2 · WHICH SESSION IS THIS CHARGE FOR? ──────────────
            //
            // The period — and therefore the DEMAND ID — was derived from the
            // instance's own sessionYear, with no way for a caller to say
            // otherwise. Transport's session rollover needs exactly that.
            //
            // WHAT WENT WRONG. Transport_rollover inits Fee_lifecycle with
            // $this->session_year, the session the ADMIN is in — normally the
            // session being rolled OUT of. rolloverSession() then does, per
            // student:
            //
            //   cancelSessionDemands($sid, $fromSession, 'Transport')
            //       → derives its periodKey from the PASSED session, correctly
            //         archiving the old demand
            //   createModuleFee($sid, 'Transport', [...])
            //       → derived its periodKey from $this->sessionYear
            //
            // With the admin still in the old session those are the SAME
            // periodKey, so the same demand id: the demand archived a moment
            // earlier was immediately rewritten at full fare and status
            // 'unpaid', and the new session received no transport demand at
            // all. Transport billing simply did not roll over.
            //
            // It was silently correct only when the admin had already switched
            // to the new session first — an unstated precondition that nothing
            // validated: rolloverSession() takes both sessions as free-form
            // POST parameters and compares neither against its own.
            //
            // Optional and defaulted, so every other caller is unchanged.
            $targetSession = trim((string) ($feeData['session'] ?? ''));
            if ($targetSession === '') $targetSession = $this->sessionYear;

            if ($targetSession !== $this->sessionYear) {
                // Never silent. Writing a charge into a session other than the
                // one this instance serves is legitimate for rollover and a
                // mistake anywhere else, and the two are indistinguishable
                // without a record.
                log_message('error', 'ACC_MODULE_FEE_CROSS_SESSION student=[' . $studentId
                    . '] module=[' . $moduleType . '] instance_session=[' . $this->sessionYear
                    . '] target_session=[' . $targetSession
                    . '] — a charge was raised into a session other than the one this '
                    . 'instance serves. That is expected during a session rollover and is a '
                    . 'mistake anywhere else; the two are indistinguishable without this line.');
            }

            $year       = (int) substr($targetSession, 0, 4);
            $periodKey  = sprintf('%04d-%02d', $year, self::ACADEMIC_START_MONTH);
            $demandId   = $this->_demandId($studentId, $periodKey, '', $headName . '_' . $moduleType, $chargeInstance);
            $periodLbl  = "April {$year}";

            // ── Phase D (G4, 2026-08-08): entitlement-aware service pricing ──
            // Baseline §0A.4 path #3 lists this generator's discount source as
            // "None" — a student holding a transport-scoped concession has
            // always been billed the full fare here. Phase D resolves the net
            // through Service_entitlement_pricing, which consults the SAME
            // studentEntitlements log the tuition path uses.
            //
            // Invariant I3 is enforced inside the reader: only a deliberately
            // service-scoped entitlement can discount a service fee; a blanket
            // scope:'all' concession cannot.
            //
            // FLAG-GATED, DEFAULT OFF. With the flag off, $net === $amount and
            // every field written below is byte-identical to the pre-Phase-D
            // document — this generator's production behaviour is unchanged
            // until the flag is deliberately flipped at certification.
            $net       = $amount;
            $deduction = 0.0;

            // ── TRN-PRORATE-INVERT-1 / TRN-CONC-PERIOD-1 · PERIOD-PRICED ────
            //
            // A caller that knows WHICH MONTHS the service actually ran passes
            // them as `billablePeriods` (periodKey => fare for that month).
            // Pricing then resolves month by month, so a concession is applied
            // to exactly the months it is effective for and to no others —
            // the intersection of service period and concession period.
            //
            // This is what replaces proRateFees() for services. Proration
            // answers "you JOINED in month X, pay the rest of the year", which
            // is right for a joiner and catastrophically wrong when the same
            // parameter is handed a STOP date: the student was billed as if
            // they had started on the day they left.
            //
            // Optional and additive. A caller that passes nothing gets exactly
            // the previous single-period behaviour, so Hostel, ID-card and
            // every other module are untouched.
            $billablePeriods = $feeData['billablePeriods'] ?? null;
            if (is_array($billablePeriods) && $billablePeriods !== []) {
                $priced = $this->_priceServiceFeeByPeriods(
                    $studentId, $moduleType, $headName, $billablePeriods,
                    (string) ($moduleM5['feeHeadRef'] ?? '')
                );
                // The caller's `amount` is a cross-check, not the authority:
                // the months are. If they disagree the months win and the
                // difference is logged rather than silently reconciled.
                if (abs($priced['gross'] - $amount) > 0.005) {
                    log_message('error', 'ACC_MODULE_PERIOD_GROSS_MISMATCH student=[' . $studentId
                        . '] module=[' . $moduleType . '] caller_amount=[' . $amount
                        . '] period_gross=[' . $priced['gross'] . '] — billing the PERIOD total.');
                }
                $amount    = $priced['gross'];
                $net       = $priced['net'];
                $deduction = $priced['deduction'];
            } elseif ($this->_serviceEntitlementPricingEnabled()) {
                // DEC-011 — forward the registry ref resolved above. Without it
                // a `head:<feeHeadRef>` entitlement (the ONLY shape the F-3
                // picker can now produce) cannot match a service demand.
                $priced    = $this->_priceServiceFee(
                    $studentId, $moduleType, $headName, $periodKey, $amount,
                    (string) ($moduleM5['feeHeadRef'] ?? '')
                );
                $net       = $priced['net'];
                $deduction = $priced['deduction'];
            } else {
                // ── SVC-1 · A SERVICE CONCESSION SILENTLY NOT APPLIED ────────
                // With the flag off this writes the GROSS fare, which is the
                // documented, deliberate behaviour — but it is only harmless
                // while no student actually holds a service entitlement.
                //
                // Families can request transport / hostel / meals through
                // Entitlement_requests, and Entitlement_service records them
                // as ACTIVE regardless of this flag. So a school can accumulate
                // approved service concessions and keep billing every one of
                // them at full fare, with nothing anywhere reporting it — the
                // same silent-money-loss shape as DEC-013b on the generation
                // path and ADM-ENT-1 on the admission path.
                //
                // DELIBERATELY NOT REFUSING, unlike DEC-013b. Refusing to write
                // a transport demand would block that student's transport
                // billing entirely, which is worse than billing gross. The
                // proportionate answer is to make the condition VISIBLE, so an
                // operator can see it rather than discover it from a parent.
                //
                // Memoised per student for the same reason DEC-013b memoises:
                // a rollover writes many module demands per student, and this
                // must cost one query per student, not one per demand.
                if ($this->_studentHoldsServiceEntitlement($studentId)) {
                    log_message('error',
                        'ACC_SERVICE_ENTITLEMENT_NOT_PRICED student=[' . $studentId
                        . '] module=[' . $moduleType . '] head=[' . $headName
                        . '] period=[' . $periodKey . '] gross=[' . $amount
                        . '] — an ACTIVE service entitlement exists but '
                        . 'USE_ENTITLEMENT_SERVICE_PRICING is off for this tenant, so this '
                        . 'demand is written at FULL FARE and the concession is not applied.');
                }
            }

            // FIN-1: never reset the payment state of an EXISTING demand. This
            // generic module-fee writer (Transport + Hostel) is re-invoked on the
            // SAME demandId (route is NOT part of the id) by route change /
            // assignment edit / rollover / reconcile. Read the existing demand;
            // if it already carries a payment, PRESERVE paidAmount (payment
            // history is preserved by the merge write) and recompute
            // balance/status against the new amount — mirroring proRateFees. A
            // brand-new (or genuinely unpaid) demand is written unpaid.
            // Phase D: balance is computed against the NET (post-entitlement)
            // amount, never the gross — otherwise a discounted student would
            // still owe the full fare.
            $existing  = $this->firebase->firestoreGet('feeDemands', $demandId);
            $paidSoFar = is_array($existing) ? (float) ($existing['paidAmount'] ?? $existing['paid_amount'] ?? 0) : 0.0;

            // ── FEES-FROZEN-1 — FINE-AWARE BALANCE (Phase 19B) ──────────
            //
            // THE DEFECT THIS CLOSES: both branches below re-derived `balance`
            // from the net alone, SILENTLY DROPPING any late fee already
            // posted to this demand. `writeDemand()` merges, so `fine_amount`
            // survives on the document — the money simply stopped being owed.
            // The demand would then read `fine_amount: 200` with a balance
            // that excludes it: the fine is recorded but uncollectable.
            //
            // ⚠ BOTH branches were affected, not just the paid one. Earlier
            // reports in this programme flagged only the `$paidSoFar > 0`
            // case. The `else` branch set `balance = $net` outright, so an
            // UNPAID demand carrying a fine lost it too — arguably the more
            // likely case, since a fine is normally applied before payment.
            //
            // This generator is RE-INVOKED on the same demandId by route
            // change, assignment edit, rollover and reconcile (see FIN-1
            // above), so the erasure repeats on every regeneration.
            //
            // Currently unreachable — the late-fee engine only fines academic
            // demands while module late fees are OFF. It becomes live the
            // moment Transport/Hostel late fees are enabled, which is the
            // next planned step; that is why it is fixed now, ahead of them.
            //
            // CANONICAL EQUATION — proven from the lifecycle, not assumed.
            // Four independent sites already agree on `net + fine - paid`:
            //   • Fee_late_fee_service::applyTo()      (the fine writer itself)
            //   • Fee_lifecycle::proRateFees()          (fixed in Phase 15.5)
            //   • Fee_refund_service                    (reversal path)
            //   • FeeCollectionService netTotal         (fees - discount + fine)
            // No double-counting: `$net` is already post-discount/entitlement
            // (see Phase D block above) and `fine_amount` is a separate field
            // that is never folded into net.
            //
            // Both key spellings are read: the engine writes `fine_amount`,
            // while other paths emit `fineAmount`, and this reads the RAW
            // Firestore document (not a normalised one).
            $fineSoFar = is_array($existing)
                ? (float) ($existing['fine_amount'] ?? $existing['fineAmount'] ?? 0)
                : 0.0;
            if ($fineSoFar < 0) $fineSoFar = 0.0;

            if ($paidSoFar > 0) {
                $balance  = max(0.0, round($net + $fineSoFar - $paidSoFar, 2));
                $payState = [
                    'paidAmount' => $paidSoFar,
                    'balance'    => $balance,
                    'status'     => $balance <= 0.005 ? 'paid' : 'partial',
                ];
            } else {
                $balance  = max(0.0, round($net + $fineSoFar, 2));
                $payState = [
                    'paidAmount' => 0.0,
                    'balance'    => $balance,
                    'status'     => 'unpaid',
                ];
            }

            $this->fsTxn->writeDemand($demandId, array_merge([
                'studentId'   => $studentId,
                'studentName' => (string) ($stu['name'] ?? $stu['studentName'] ?? ''),
                'className'   => (string) ($stu['className'] ?? ''),
                'section'     => (string) ($stu['section']   ?? ''),
                'feeHead'     => $headName,
            ], ($chargeInstance !== '' ? [
                // Stored so a later recalculation rebuilds the SAME demand id
                // from canonical data instead of parsing a display string.
                // Omitted entirely when absent, keeping every other module
                // demand byte-identical.
                'chargeInstance' => $chargeInstance,
            ] : []), [
                // F10 (2026-07-07) — normalise category to lowercase per
                // Refinement 4. Historical demands may carry mixed-case
                // ('Transport') — Parent app filter is case-insensitive
                // for read-time compat + falls back to name.contains for
                // pre-F10 records without the category field. NO data
                // migration required.
                // ROLL-2 — travels to _buildDemandDoc, which stamps it on the
                // document. Equal to the instance session for every caller
                // except the rollover, so nothing else changes.
                'session'     => $targetSession,
                // PROMO-SC-1 — the fact that this charge did NOT come from a
                // class fee chart, stated once and read by anyone who needs it.
                // Distinct from `category`, which the M5 registry stamp
                // deliberately overrides with its own authoritative value, and
                // which therefore cannot answer "was this class-derived?".
                'moduleType'  => $moduleType,
                'category'    => strtolower((string) $moduleType),
                'frequency'   => $frequency,
                'period'      => $periodLbl,
                'periodKey'   => $periodKey,
                // ── DUE-2 · THE DUE DATE WAS ACCEPTED AND THEN DROPPED ──────
                //
                // Callers pass `dueDate` in $feeData and it never reached the
                // document: this payload simply did not carry it, and
                // `_buildDemandDoc()` builds from named keys, so the value was
                // discarded without an error.
                //
                // Student_charge_service computes one deliberately and its own
                // docblock records why — ID-DUE-1: "a one-off MUST carry a
                // dueDate ... a dateless demand is invisible to BOTH the
                // reminder service and the late-fee engine — it sits unpaid
                // and unchased forever." It passed one in. It was dropped here.
                //
                // PROVEN ON A REAL DEVICE, not inferred. A Student Charge
                // raised today displayed in the Parent app as
                // "Overdue · 129d" — April 16 to today — because with no
                // dueDate the app correctly falls back to inferring one from
                // the month label, and createModuleFee files every module
                // charge under the session start (April).
                //
                // So three consumers were affected by one missing field: the
                // parent saw a charge as four months late on the day it was
                // raised, the late-fee engine could never fine it (fineFor()
                // returns null on an empty dueDate), and the reminder service
                // could never chase it.
                //
                // Optional: Transport and Hostel pass no dueDate, so their
                // demands are byte-identical to before.
                'dueDate'     => (string) ($feeData['dueDate'] ?? $feeData['due_date'] ?? ''),
                // Derived overdue boundary, from the same authoritative
                // policy the monthly generator uses. Transport and Hostel pass
                // no dueDate, and `overdueFrom()` returns '' for an absent due
                // date — an undated demand is not evidence of lateness — so
                // those documents stay byte-identical to before.
                'overdueFrom' => $this->_overdueFromFor(
                    (string) ($feeData['dueDate'] ?? $feeData['due_date'] ?? '')
                ),
                'grossAmount' => $amount,
                // Phase D: net diverges from gross ONLY when a service-scoped
                // entitlement applied. Flag off ⇒ $net === $amount ⇒ identical
                // to the pre-Phase-D document.
                'netAmount'   => $net,
                // G1 fix (2026-08-07): a fresh/restored write of this demand's
                // full amount must clear any prior proRateFees() reduction —
                // otherwise a resumed Transport assignment keeps a stale
                // prorated=true flag alongside the just-restored full amount.
                // Safe for every caller: nothing else in the codebase reads
                // these two fields.
                'prorated'      => false,
                'prorateFactor' => 0,
                // ── PRORATE-1 (2026-08-23) · REFRESH THE PRORATION ANCHOR ────
                //
                // The G1 note directly above is right, and it stopped one field
                // short of the one that decides the money. Clearing `prorated`
                // while leaving `listGrossAmount` at the PREVIOUS charge tells
                // proRateFees() the demand is un-prorated and then hands it the
                // old price to prorate from.
                //
                // proRateFees() scales `listGrossAmount * factor`, and that
                // anchor was written ONCE and never refreshed — deliberately, so
                // repeated proration could not compound (2400 -> 1200 -> 600).
                // Correct for a charge that never changes; wrong the moment one
                // does, and this generator exists to change it: it re-prices the
                // SAME demandId in place on every route change, subscription
                // change, suspension, assignment edit and rollover — the demand
                // id is route-independent by design.
                //
                // WHAT IT COST. Transport_service::changeRoute() writes the new
                // fare here and then calls proRateFees() as its last step. With
                // a stale anchor the student was re-billed on the OLD route's
                // fare: joined July on a 12,000 route (anchor 12,000), moved in
                // October to an 18,000 route, and proration produced
                // 12,000 x 0.5 = 6,000 instead of 9,000. An upgrade undercharges
                // the school; a downgrade overcharges the family. Every fee
                // field on the document agrees with every other one, which is
                // why nothing detected it.
                //
                // Writing the anchor HERE is what makes it correct rather than
                // merely different: this method always writes the full LIST
                // charge for the module, so the anchor now means exactly what
                // its name says. Idempotency is untouched — a re-run with the
                // same fare rewrites the same value, and proration still
                // recomputes from the list rather than compounding.
                'listGrossAmount'    => $amount,
                'listDiscountAmount' => $deduction > 0.005 ? $deduction : 0.0,
                'createdAt'   => date('c'),
                'createdBy'   => $this->adminId,
            ], $payState, [
                // Mirrors the field names the tuition path already persists
                // (Fee_generation_service), so reporting/audit/analytics read
                // one vocabulary across tuition and services.
                //
                // ── DEC-001 RESIDUAL, FIXED 2026-08-19 ───────────────────────
                // These were previously written ONLY when `$deduction > 0.005`,
                // to keep a non-discounted service demand byte-identical to
                // pre-Phase-D. But `writeDemand()` MERGES, and this method
                // re-prices the SAME demandId in place on every route change,
                // subscription change, suspension, assignment edit and rollover.
                // So a demand that once carried a discount kept a STALE
                // `discountAmount` while `grossAmount`/`netAmount` were rewritten
                // to the full fare — leaving `gross − discount != net` and
                // over-stating the concession on a charge that no longer has one.
                //
                // Writing them unconditionally clears that. It is the SAME
                // reasoning the G1 fix directly above already applied to
                // `prorated`/`prorateFactor`: a fresh write of the full amount
                // must clear every field the previous, reduced write left behind.
                // Those two were cleared; the MONEY field was not.
                //
                // Byte-identity is preserved where it actually matters: an
                // explicit 0 and an absent field are read identically by every
                // consumer (`(float) ($d['discountAmount'] ?? 0)`), including
                // `Fee_firestore_txn::moneyIncoherence()`. Inert today because
                // USE_ENTITLEMENT_SERVICE_PRICING is off, so no service demand
                // carries a deduction yet — fixed BEFORE Phase D can create one.
                'concessionApplied' => $deduction > 0.005 ? $deduction : 0.0,
                'discountAmount'    => $deduction > 0.005 ? $deduction : 0.0,
            ],
            // M5 LAST, deliberately: with the flag ON the registry's
            // authoritative category must override the module-name value
            // stamped above. Empty (and therefore inert) when the flag is off.
            $moduleM5));

            $this->_log('module_fee_created', $studentId, [
                'module' => $moduleType, 'head' => $headName, 'amount' => $amount,
                'net' => $net, 'entitlement_deduction' => $deduction,
                'demand_id' => $demandId,
            ]);
            return $demandId;
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::createModuleFee failed [{$studentId}/{$moduleType}]: " . $e->getMessage());
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  6b. unfreezeFeesOnRestore
    // ═════════════════════════════════════════════════════════════════

    /**
     * ── FRZ-4 · A RE-INSTATED STUDENT'S FEE BOOK STAYED FROZEN ─────────────
     *
     * `freezeFeesOnSoftDelete()` has three live callers and stamps
     * `status: 'frozen'` on every collectable demand. Nothing anywhere reversed
     * it: there was no unfreeze in the codebase, and `Sis::cancel_tc()` — which
     * restores a withdrawn student to `Status: 'Active'` — touched
     * `Fee_lifecycle` zero times.
     *
     * So a student withdrawn and then re-instated came back Active with their
     * whole fee book still marked "do not collect".
     *
     * ── WHY THIS GOT WORSE, NOT BETTER ────────────────────────────────────
     * Worth stating plainly because it is a consequence of a fix made earlier
     * in this same programme. Before FRZ-1, `fineFor()` used a denylist that
     * did not include 'frozen', so a re-instated student's demands kept
     * accruing late fees — accidentally the right behaviour, from a defect.
     * FRZ-1 correctly exempted frozen demands, and in doing so turned this
     * dormant gap into a live one: a re-instated student is now silently exempt
     * from late fees for the remainder of the session, and no report says so.
     *
     * ── DERIVING THE STATUS RATHER THAN STORING IT ────────────────────────
     * The freeze does not record what the status was before, and back-filling
     * that now would leave every already-frozen demand un-restorable. The
     * status is instead re-derived from the money, using the same rule the
     * payment and module paths already apply — paid/partial/unpaid from
     * `balance` and `paidAmount`. That is more robust than a stored value: a
     * payment or a waiver applied while the student was withdrawn is reflected,
     * where a restored snapshot would overwrite it.
     *
     * ONLY 'frozen' is touched. An archived or reversed demand is historical
     * and must not be resurrected by a re-instatement — those left the dues
     * surface for reasons that have nothing to do with the withdrawal.
     *
     * Fail-soft per demand, exactly as the freeze is: a student's re-instatement
     * must not fail because one projection could not be written.
     */
    public function unfreezeFeesOnRestore(string $studentId): int
    {
        $restored = 0;
        try {
            foreach ($this->_demandsFor($studentId) as $did => $d) {
                if ((string) ($d['status'] ?? '') !== 'frozen') continue;

                $paid    = (float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0);
                $balance = (float) ($d['balance'] ?? 0);

                // Same three-way rule the rest of the pipeline uses. Checked in
                // this order so a demand settled while the student was away
                // comes back 'paid' rather than 'partial'.
                if ($balance <= 0.005 && $paid > 0.005)      $status = 'paid';
                elseif ($paid > 0.005)                        $status = 'partial';
                else                                          $status = 'unpaid';

                $this->fsTxn->updateDemand($did, [
                    'status'      => $status,
                    'unfrozenAt'  => date('c'),
                    'unfrozenBy'  => $this->adminId,
                ]);
                $restored++;
            }
            $this->_log('fees_unfrozen', $studentId, ['demands_restored' => $restored]);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::unfreezeFeesOnRestore failed [{$studentId}]: " . $e->getMessage());
        }
        return $restored;
    }

    // ═════════════════════════════════════════════════════════════════
    //  7. reverseAdmissionFees
    // ═════════════════════════════════════════════════════════════════

    public function reverseAdmissionFees(string $studentId): int
    {
        $reversed = 0;
        try {
            foreach ($this->_demandsFor($studentId) as $did => $d) {
                $st = (string) ($d['status'] ?? 'unpaid');
                if (in_array($st, ['paid','archived','reversed','frozen'], true)) continue;
                $this->fsTxn->updateDemand($did, [
                    'status'      => 'reversed',
                    'reversedAt'  => date('c'),
                    'reversedBy'  => $this->adminId,
                    'balance'     => 0,
                ]);
                $reversed++;
            }
            $this->_log('admission_reversed', $studentId, ['demands_reversed' => $reversed]);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::reverseAdmissionFees failed [{$studentId}]: " . $e->getMessage());
        }
        return $reversed;
    }

    // ═════════════════════════════════════════════════════════════════
    //  8. consolidateFeeRecords
    // ═════════════════════════════════════════════════════════════════

    /**
     * Merge two student IDs (typically after a duplicate-admission
     * resolution). Source demands are migrated onto the target student
     * by rewriting their docId; source's demand docs are then archived.
     */
    public function consolidateFeeRecords(
        string $sourceStudentId,
        string $targetStudentId,
        bool   $deleteSource = true
    ): array {
        $moved = 0; $skipped = 0;
        try {
            foreach ($this->_demandsFor($sourceStudentId) as $srcId => $d) {
                // Rewrite to a target-scoped demandId so re-runs are idempotent.
                $periodKey = (string) ($d['periodKey'] ?? $d['period_key'] ?? '');
                $feeHeadId = (string) ($d['feeHeadId'] ?? '');
                $feeHead   = (string) ($d['feeHead']   ?? '');
                if ($periodKey === '' || ($feeHead === '' && $feeHeadId === '')) {
                    $skipped++; continue;
                }
                $targetDemandId = $this->_demandId($targetStudentId, $periodKey, $feeHeadId, $feeHead);
                // Write the target-scoped demand (merge=true on Phase 2.5 writeDemand).
                $this->fsTxn->writeDemand($targetDemandId, array_merge($d, [
                    'studentId'   => $targetStudentId,
                    'mergedFrom'  => $sourceStudentId,
                    'mergedAt'    => date('c'),
                ]));
                if ($deleteSource) {
                    // Mark the source demand archived so history is preserved.
                    $this->fsTxn->updateDemand($srcId, [
                        'status'     => 'archived',
                        'archivedAt' => date('c'),
                        'archivedBy' => $this->adminId,
                        'archiveReason' => "merged into {$targetStudentId}",
                    ]);
                }
                $moved++;
            }
            $this->_log('fees_consolidated', $targetStudentId, [
                'source_student' => $sourceStudentId,
                'moved'          => $moved,
                'skipped'        => $skipped,
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Fee_lifecycle::consolidateFeeRecords failed [{$sourceStudentId}→{$targetStudentId}]: " . $e->getMessage());
        }
        return ['moved' => $moved, 'skipped' => $skipped];
    }

    // ═════════════════════════════════════════════════════════════════
    //  Private helpers
    // ═════════════════════════════════════════════════════════════════

    private function _remainingAcademicMonths(int $joinMonth): int
    {
        // Academic year starts in April (month 4), ends in March.
        if ($joinMonth < self::ACADEMIC_START_MONTH) {
            // Jan / Feb / Mar — last quarter of the academic year.
            return self::ACADEMIC_START_MONTH - $joinMonth;
        }
        // Apr … Dec — months remaining through March.
        return 12 - ($joinMonth - self::ACADEMIC_START_MONTH);
    }

    private function _monthNum(string $m): int
    {
        static $map = [
            'April'=>4,'May'=>5,'June'=>6,'July'=>7,'August'=>8,'September'=>9,
            'October'=>10,'November'=>11,'December'=>12,'January'=>1,'February'=>2,'March'=>3,
        ];
        return $map[$m] ?? 1;
    }

    /**
     * Audit log — every mutating lifecycle event lands in fee_audit_logs
     * so the admin dashboard + regulator can reconstruct who did what.
     */
    private function _log(string $event, string $studentId, array $data = []): void
    {
        if ($this->auditLogger === null) return;
        try {
            $this->auditLogger->record(
                'update', 'demand', $studentId,
                /* before */ [],
                /* after  */ array_merge(['event' => $event], $data),
                $this->adminId,
                ['source' => 'fee_lifecycle', 'reason' => $event]
            );
        } catch (\Throwable $_) { /* never fail the caller on audit */ }
    }
}
