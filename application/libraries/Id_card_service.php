<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Id_card_service — THE single sanctioned write/read path for `idCards`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  WHY THIS EXISTS (Phase 19E)
 * ═══════════════════════════════════════════════════════════════════════
 *  Before this class, ZenX had an ID-card PRINTER, not an ID-card system.
 *  `Sis::id_card()` renders every active student and mints a QR at page-render
 *  time; nothing was ever persisted. Verified in Phase 19D: no card
 *  collection, no status, no version, no issuance record anywhere.
 *
 *  Three consequences followed from that one fact:
 *    • "Reissue" produced a byte-identical credential (the token is a pure
 *      function of schoolId+studentId, both permanent).
 *    • A lost card could not be revoked — the only lever was rotating the
 *      server secret, which kills every card in every school.
 *    • A replacement fee could not honestly be charged, because the new card
 *      was indistinguishable from the one it replaced.
 *
 *  This class introduces the missing state so a single card becomes
 *  retirable. It does NOT change how any scanner behaves — see ENFORCEMENT.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ENFORCEMENT IS DELIBERATELY OFF IN THIS PHASE
 * ═══════════════════════════════════════════════════════════════════════
 *  Nothing in this class is wired into Attendance::scan_qr,
 *  Transport_qr_attendance_service or Visitor_service. `isCurrentVersion()`
 *  exists and is tested, but no consumer calls it yet.
 *
 *  That is not an oversight. Turning version checking on before every student
 *  has been reissued would invalidate cards school-wide on the first scan —
 *  the precise outcome the migration plan forbids. The order is:
 *      19E  model + credential V2 (here)      ← additive, zero behaviour change
 *      19F  lifecycle operations + UI actions
 *      19G  flip consumers to version checking
 *      19J  retire legacy acceptance, by measured evidence, not by date
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DOCUMENT MODEL — two shapes, one collection
 * ═══════════════════════════════════════════════════════════════════════
 *  CURRENT-CARD POINTER   `idCards/{schoolId}_{studentId}`
 *      kind: 'current', currentVersion, status, reason, plus a denormalised
 *      copy of the identity fields so the future register renders from ONE
 *      query with no per-student join.
 *
 *  VERSION RECORD         `idCards/{schoolId}_{studentId}_v{N}`
 *      kind: 'version', immutable once written except for supersede/revoke
 *      stamps. This is the permanent issuance history.
 *
 *  The version record is AUTHORITATIVE; the pointer is a fast lookup. That is
 *  the same two-tier philosophy `Sr_no_service` documents for srNoIndex and
 *  `Id_generator` for the STU counter ("the claim doc is authoritative, not
 *  the pointer"), reused rather than reinvented.
 *
 *  CONCURRENCY: a version is claimed with `firestoreCreate` — atomic
 *  create-if-not-exists. Two operators issuing simultaneously both try
 *  `_v3`; exactly one create succeeds, the loser probes `_v4`. This is the
 *  SAME primitive Id_generator, Sr_no_service, Apaar_service and
 *  Admission_no_service already rely on for collision safety. There is no
 *  read-then-write-N+1 race, and therefore no way to end up with two active
 *  cards at the same version.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  STATUS vs REASON vs EVENT — kept strictly separate
 * ═══════════════════════════════════════════════════════════════════════
 *  Phase 19D's model was reviewed against the code before adoption and is
 *  implemented as proposed, with `expired` retained as a valid status but
 *  never set by this class (no ZenX evidence that Indian K-12 expires cards —
 *  decision ID-D8). Modelling `lost`/`stolen`/`damaged` as STATUSES is the
 *  common design error: they describe WHY a card left service, not what state
 *  it is in. A lost card and a damaged card are both simply `replaced`.
 *
 *      status  active | replaced | revoked | expired
 *      reason  first_issue | lost | stolen | damaged | wear
 *              | data_correction | admin
 *      event   issued | replaced | revoked | reprinted
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  IDENTITY DISCIPLINE
 * ═══════════════════════════════════════════════════════════════════════
 *  studentId  — internal FK. The credential binds THIS, and the card doc keys
 *               on it. Never displayed as the school-facing identity.
 *  srNo       — permanent school-facing identity. DENORMALISED onto the card
 *               for display/search/print only. It is NOT a foreign key here
 *               and nothing resolves a card by it.
 *  admissionNo— admission/register identity, denormalised for the same reason.
 *  rollNo     — deliberately ABSENT. It is session/class-scoped and
 *               reassigned on promotion; it must never become card identity.
 */
class Id_card_service
{
    /** @var object|null */ private $firebase;
    /** @var object|null */ private $fs;
    private string $schoolId = '';
    private string $actorId  = '';
    private bool   $ready    = false;

    public const COL = 'idCards';

    public const KIND_CURRENT = 'current';
    public const KIND_VERSION = 'version';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_REVOKED  = 'revoked';
    public const STATUS_EXPIRED  = 'expired';

    public const REASON_FIRST_ISSUE     = 'first_issue';
    public const REASON_LOST            = 'lost';
    public const REASON_STOLEN          = 'stolen';
    public const REASON_DAMAGED         = 'damaged';
    public const REASON_WEAR            = 'wear';
    public const REASON_DATA_CORRECTION = 'data_correction';
    public const REASON_ADMIN           = 'admin';

    public const EVENT_ISSUED    = 'issued';
    public const EVENT_REPLACED  = 'replaced';
    public const EVENT_REVOKED   = 'revoked';
    public const EVENT_REPRINTED = 'reprinted';

    /** Credential family stamped on each version record. */
    public const CRED_V2 = 'v2';

    /** Version-claim contention ceiling. Mirrors Sr_no_service's bounded probe. */
    public const MAX_CLAIM_ATTEMPTS = 12;

    /** Guards a runaway loop; far above any real reissue count for one student. */
    public const MAX_VERSION = 999;

    public static function validStatuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_REPLACED, self::STATUS_REVOKED, self::STATUS_EXPIRED];
    }

    public static function validReasons(): array
    {
        return [
            self::REASON_FIRST_ISSUE, self::REASON_LOST, self::REASON_STOLEN,
            self::REASON_DAMAGED, self::REASON_WEAR, self::REASON_DATA_CORRECTION,
            self::REASON_ADMIN,
        ];
    }

    public static function validEvents(): array
    {
        return [self::EVENT_ISSUED, self::EVENT_REPLACED, self::EVENT_REVOKED, self::EVENT_REPRINTED];
    }

    /**
     * Reasons that are candidates for a replacement charge. Deliberately a
     * CLASSIFICATION ONLY — this class raises no fee and knows no amount.
     * Fee integration is Phase 19I and depends on decisions ID-D2/D4/D7.
     * `first_issue`, `wear`, `data_correction` and `admin` are excluded: the
     * first card and the school's own corrections are not the family's fault.
     */
    public static function chargeableReasons(): array
    {
        return [self::REASON_LOST, self::REASON_STOLEN, self::REASON_DAMAGED];
    }

    /** Max stored length of an operator note. */
    public const NOTE_MAX = 300;

    /**
     * Normalise an optional operator note.
     *
     * A note is CONTEXT ("FIR #123/2026", "surrendered at the front desk"),
     * never the canonical reason — that stays a controlled enum, because it
     * drives audit, reporting and (in 19I) whether a fee applies. Control
     * characters are stripped so a pasted newline cannot break a log line or a
     * CSV export, and the whole thing is bounded.
     */
    public static function normalizeNote(string $raw): string
    {
        $n = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($raw));
        $n = trim(preg_replace('/\s{2,}/u', ' ', (string) $n));
        return mb_substr($n, 0, self::NOTE_MAX);
    }

    public function init($firebase, $fs, string $schoolId, string $actorId): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->actorId  = $actorId !== '' ? $actorId : 'SYSTEM';
        $this->ready    = ($firebase !== null && $fs !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    // ══════════════════════════════════════════════════════════════════
    //  DOC IDS
    // ══════════════════════════════════════════════════════════════════

    /** Pointer doc — one per student. */
    public function currentDocId(string $studentId): string
    {
        return $this->fs->docId($studentId);
    }

    /** Version record — one per issuance, immutable history. */
    public function versionDocId(string $studentId, int $version): string
    {
        return $this->fs->docId2($studentId, 'v' . $version);
    }

    // ══════════════════════════════════════════════════════════════════
    //  READ
    // ══════════════════════════════════════════════════════════════════

    /**
     * The student's current card pointer, or null if they have never been
     * issued a managed card. ONE Firestore get — no query, so no composite
     * index is required on this path. That matters: this is the read a
     * scanner would perform once version enforcement lands in 19G.
     */
    public function currentCard(string $studentId): ?array
    {
        if (!$this->ready || $studentId === '') return null;
        $doc = $this->fs->get(self::COL, $this->currentDocId($studentId));
        return is_array($doc) ? $doc : null;
    }

    /** The student's active version, or 0 when they hold no managed card. */
    public function currentVersion(string $studentId): int
    {
        $c = $this->currentCard($studentId);
        if ($c === null) return 0;
        if ((string) ($c['status'] ?? '') !== self::STATUS_ACTIVE) return 0;
        return (int) ($c['currentVersion'] ?? 0);
    }

    /**
     * THE ENFORCEMENT PREDICATE — deliberately not called by any consumer yet.
     *
     * Phase 19G will call this from the QR consumers after decoding a V2
     * token. Until every student has been reissued, calling it would reject
     * legitimate holders, because a student with no managed card yet has
     * currentVersion 0 and would fail every check.
     *
     * Fails CLOSED for a managed card (wrong/superseded/revoked version →
     * false) and returns false for an unmanaged student, so a caller can never
     * accidentally read "no card on file" as "valid".
     */
    public function isCurrentVersion(string $studentId, ?int $cardVersion): bool
    {
        if ($cardVersion === null || $cardVersion < 1) return false;
        return $this->currentVersion($studentId) === $cardVersion;
    }

    // ══════════════════════════════════════════════════════════════════
    //  CANONICAL CREDENTIAL VALIDATION  (SEC-ID-1 / SEC-ID-2 — Phase 19I)
    // ══════════════════════════════════════════════════════════════════

    /**
     * THE single credential-lifecycle authority.
     *
     * Every runtime QR consumer must reach its verdict through THIS method.
     * Before it existed, each consumer decided independently what to do with a
     * legacy token — Attendance accepted, Transport rejected, Campus Access
     * accepted read-only — which is three copies of a security policy that can
     * drift apart. `qr_token_decode()` proves a token is AUTHENTIC; this
     * decides whether the card it names is CURRENTLY VALID. Those are
     * different questions and only the second one needs Firestore.
     *
     * DELIBERATELY NOT DECIDED HERE — each consumer keeps owning:
     *   • student status (scan_qr's Active gate)
     *   • eligibility (Transport's studentRoutes check)
     *   • authorization (Campus Access RBAC)
     * Those are domain rules, not credential rules.
     *
     * FAILS CLOSED: any unknown state returns invalid.
     *
     * READ COST: zero while enforcement is off (the flags short-circuit before
     * any Firestore call), one point-get per scan once IDCARD_ENFORCE_V2 is on
     * and the token is a V2 one. Never a query, never an index.
     *
     * @param array|null $decoded qr_token_decode() output
     * @param array      $flags   ['enforceV2'=>bool,'rejectLegacy'=>bool,'rejectV1'=>bool]
     * @return array{valid:bool,code:string,studentId?:string,cardVersion?:?int,tokenVersion?:string}
     */
    public function validateCredential(?array $decoded, array $flags = []): array
    {
        if (!is_array($decoded)) {
            return ['valid' => false, 'code' => 'malformed'];
        }

        $tokenVersion = (string) ($decoded['tokenVersion'] ?? '');
        $studentId    = (string) ($decoded['studentId'] ?? '');
        $tokSchool    = (string) ($decoded['schoolId'] ?? '');
        $cardVersion  = $decoded['cardVersion'] ?? null;

        if ($studentId === '' || $tokSchool === '') {
            return ['valid' => false, 'code' => 'malformed'];
        }

        // Tenant check belongs here because it is a property of the CREDENTIAL,
        // not of any one consumer's domain. Consumers may still re-check.
        if ($this->schoolId !== '' && $tokSchool !== $this->schoolId) {
            return ['valid' => false, 'code' => 'cross_tenant', 'studentId' => $studentId,
                    'tokenVersion' => $tokenVersion, 'cardVersion' => $cardVersion];
        }

        $base = ['studentId' => $studentId, 'tokenVersion' => $tokenVersion, 'cardVersion' => $cardVersion];

        $enforceV2    = !empty($flags['enforceV2']);
        $rejectLegacy = !empty($flags['rejectLegacy']);
        $rejectV1     = !empty($flags['rejectV1']);

        if ($tokenVersion === 'legacy' || !empty($decoded['legacy'])) {
            return $rejectLegacy
                ? array_merge($base, ['valid' => false, 'code' => 'legacy_rejected'])
                : array_merge($base, ['valid' => true,  'code' => 'legacy_accepted']);
        }

        if ($tokenVersion === 'v1') {
            // A V1 card names NO version, so IDCARD_ENFORCE_V2 cannot apply to
            // it — only the dedicated V1 flag can retire it. Conflating the two
            // would force a school to reprint cryptographically sound cards.
            return $rejectV1
                ? array_merge($base, ['valid' => false, 'code' => 'v1_rejected'])
                : array_merge($base, ['valid' => true,  'code' => 'v1_accepted']);
        }

        if ($tokenVersion === 'v2') {
            if (!$enforceV2) {
                return array_merge($base, ['valid' => true, 'code' => 'v2_unenforced']);
            }
            if (!is_int($cardVersion) || $cardVersion < 1) {
                return array_merge($base, ['valid' => false, 'code' => 'malformed']);
            }

            $current = $this->currentVersion($studentId);   // 1 point-get
            if ($current === 0) {
                // No managed card on file, or the active one is revoked. A
                // signature alone must not stand in for a live card.
                return array_merge($base, ['valid' => false, 'code' => 'no_active_card']);
            }
            if ($current !== $cardVersion) {
                return array_merge($base, ['valid' => false, 'code' => 'superseded']);
            }
            return array_merge($base, ['valid' => true, 'code' => 'v2_current']);
        }

        return array_merge($base, ['valid' => false, 'code' => 'unknown_token_version']);
    }

    /**
     * The enforcement flags, read from `config/id_card_flags.php`.
     *
     * ONE place reads them. Each consumer calling `config->item()` itself
     * would be three chances for the three of them to disagree about what is
     * enforced — which is the same drift `validateCredential()` exists to
     * prevent, one level up.
     *
     * Fails SAFE: if the config cannot be loaded the flags come back all-false,
     * i.e. today's accept-everything behaviour. A missing config must never
     * silently start rejecting cards at the gate.
     */
    public static function configuredFlags(): array
    {
        $off = ['enforceV2' => false, 'rejectLegacy' => false, 'rejectV1' => false];
        try {
            $CI = &get_instance();
            $CI->config->load('id_card_flags', true);
            return [
                'enforceV2'    => (bool) $CI->config->item('IDCARD_ENFORCE_V2', 'id_card_flags'),
                'rejectLegacy' => (bool) $CI->config->item('IDCARD_REJECT_LEGACY', 'id_card_flags'),
                'rejectV1'     => (bool) $CI->config->item('IDCARD_REJECT_V1', 'id_card_flags'),
            ];
        } catch (\Throwable $e) {
            log_message('error', 'Id_card_service::configuredFlags failed, defaulting to accept-all: ' . $e->getMessage());
            return $off;
        }
    }

    /**
     * Operator-facing message for a rejection code. Deliberately vague about
     * WHY a credential failed — a scanner is a public-facing surface and the
     * distinction between "forged", "superseded" and "revoked" is exactly the
     * oracle an attacker probes for. The precise code goes to the log.
     */
    public static function rejectionMessage(string $code): string
    {
        switch ($code) {
            case 'legacy_rejected':
            case 'v1_rejected':
                return 'This ID card is an older version and is no longer valid. Please have it reissued.';
            case 'superseded':
            case 'no_active_card':
                return 'This ID card is no longer valid. Please have it reissued.';
            case 'cross_tenant':
                return 'This ID card belongs to a different school.';
            default:
                return 'This QR code is not a valid ID card.';
        }
    }

    /**
     * MIGRATION EVIDENCE — how many students still hold an un-versioned
     * credential, and can enforcement be switched on without locking anyone
     * out?
     *
     * This is the report that replaces guessing a deadline. It is derived from
     * the card register, so it answers "who has been issued a managed card",
     * which is the only thing the server can know. It CANNOT know what card a
     * family physically carries — only SEC-ID-3 telemetry can show that, and
     * that has not been deployed. Both facts are reported side by side so the
     * limitation is visible at the point of decision.
     *
     * ONE query for the whole school (see registerForSchool()).
     *
     * @param int $totalActiveStudents from the caller's cached snapshot — no
     *                                 extra read is taken here.
     */
    public function migrationReadiness(int $totalActiveStudents = 0): array
    {
        $reg = $this->registerForSchool();

        $active = 0; $revoked = 0; $other = 0;
        foreach ($reg as $c) {
            $st = (string) ($c['status'] ?? '');
            if ($st === self::STATUS_ACTIVE)       $active++;
            elseif ($st === self::STATUS_REVOKED)  $revoked++;
            else                                   $other++;
        }

        $withManagedCard = count($reg);
        $neverIssued     = max(0, $totalActiveStudents - $withManagedCard);

        return [
            'totalActiveStudents' => $totalActiveStudents,
            'withManagedCard'     => $withManagedCard,
            'activeCards'         => $active,
            'revokedCards'        => $revoked,
            'otherCards'          => $other,
            'neverIssued'         => $neverIssued,
            // Enforcing while anyone still lacks a managed card would reject
            // them on the first scan.
            'safeToEnforceV2'     => ($totalActiveStudents > 0 && $neverIssued === 0 && $active > 0),
            // The server cannot prove what is in a family's pocket.
            'legacyHoldersKnown'  => false,
            'note'                => 'Physical legacy-card circulation is NOT knowable from the register. '
                                   . 'Deploy SEC-ID-3 and observe the log before retiring legacy tokens.',
        ];
    }

    /**
     * Full issuance history for one student, newest version first.
     *
     * Reads the pointer to learn how many versions exist, then fetches those
     * records by CONSTRUCTED doc id. That is a bounded number of point-gets
     * for ONE student (a card is reissued a handful of times in a school
     * career) and needs no index — as opposed to a where() on studentId,
     * which would.
     */
    public function historyFor(string $studentId): array
    {
        if (!$this->ready || $studentId === '') return [];
        $cur = $this->currentCard($studentId);
        if ($cur === null) return [];
        $top = (int) ($cur['currentVersion'] ?? 0);
        if ($top < 1) return [];

        $out = [];
        for ($v = $top; $v >= 1; $v--) {
            $doc = $this->fs->get(self::COL, $this->versionDocId($studentId, $v));
            if (is_array($doc)) $out[] = $doc;
        }
        return $out;
    }

    /**
     * The whole school's current cards — ONE query, for the future register.
     *
     * Filters `kind` in PHP rather than in the query on purpose: a
     * schoolId-equality query is served by Firestore's automatic single-field
     * index, whereas adding a second equality would demand a COMPOSITE index.
     * Phase 19E is explicitly not permitted to touch `firestore.indexes.json`
     * (and it is dirty in the working tree from other work), so the query is
     * shaped to need nothing new. Status/class filtering belongs in PHP for
     * the same reason.
     *
     * Read budget: 1 query for N students, never N reads. See the report's
     * read/write budget section.
     *
     * @return array<string,array> keyed by studentId
     */
    public function registerForSchool(): array
    {
        if (!$this->ready) return [];
        try {
            $rows = $this->fs->schoolWhere(self::COL, []);
        } catch (\Throwable $e) {
            log_message('error', 'Id_card_service::registerForSchool query failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r;
            if (!is_array($d)) continue;
            if ((string) ($d['kind'] ?? '') !== self::KIND_CURRENT) continue;
            $sid = (string) ($d['studentId'] ?? '');
            if ($sid !== '') $out[$sid] = $d;
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    //  WRITE
    // ══════════════════════════════════════════════════════════════════

    /**
     * Issue a card — the first one, or a replacement.
     *
     * Supersede-then-issue is a single logical operation: the previous version
     * record is stamped `replaced` and the new version becomes active. A
     * student therefore holds AT MOST ONE active card, enforced structurally
     * by the atomic version claim rather than by a read-check.
     *
     * @param array  $identity srNo / admissionNo / studentName / className /
     *                         section — denormalised for the register. Roll No
     *                         is deliberately not accepted; see class docblock.
     * @param string $note     OPTIONAL free-text operator note (e.g. a police
     *                         report reference for a stolen card). Kept
     *                         STRICTLY SEPARATE from `$reason`: the reason is a
     *                         controlled enum that drives audit, reporting and
     *                         (in 19I) whether a fee applies, and must never be
     *                         derived from typed text. Bounded to 300 chars so
     *                         a pasted essay cannot bloat the document.
     * @param int|null $expectedCurrentVersion OPTIONAL optimistic-concurrency
     *   assertion — ID-RACE-1 (Phase 19K).
     *
     *   Without it this method is NOT idempotent, and that is a real defect,
     *   not a theoretical one: two rapid Replace submissions (a double-submit
     *   that beats the panel's client-side guard, an operator retrying on a
     *   slow connection, or two operators acting at once) each read the same
     *   prior version and each atomically claim the NEXT one. The result is
     *   two cards — v2 AND v3 — and, once amounts are configured, TWO
     *   replacement fee demands for one real event. The atomic version claim
     *   prevents two cards at the same version; it cannot prevent two
     *   legitimate-looking sequential ones.
     *
     *   When supplied, the caller asserts which version it believed was
     *   current. If the live document disagrees, the operation aborts with
     *   `version_conflict` and NOTHING is written. The second click therefore
     *   fails cleanly instead of silently producing a second card.
     *
     *   This is the SAME discipline Sr_no_service::relink() already enforces
     *   ("requires the caller to already know and assert the expected current
     *   owner"), reused rather than reinvented. Null keeps the previous
     *   behaviour for callers that genuinely cannot know the version.
     *
     * @return array{ok:bool,reason?:string,version?:int,credential?:string}
     */
    public function issue(
        string $studentId,
        string $reason,
        array $identity = [],
        string $note = '',
        ?int $expectedCurrentVersion = null
    ): array {
        if (!$this->ready)      return ['ok' => false, 'reason' => 'not_initialized'];
        if ($studentId === '')  return ['ok' => false, 'reason' => 'missing_student_id'];
        if (!in_array($reason, self::validReasons(), true)) {
            return ['ok' => false, 'reason' => 'invalid_reason'];
        }

        $existing = $this->currentCard($studentId);
        $prevVer  = is_array($existing) ? (int) ($existing['currentVersion'] ?? 0) : 0;

        // A first issue must not silently masquerade as a replacement, and a
        // replacement must not claim to be a first issue — the reason drives
        // both the audit trail and (in 19I) whether a fee applies.
        if ($prevVer === 0 && $reason !== self::REASON_FIRST_ISSUE) {
            return ['ok' => false, 'reason' => 'no_existing_card_to_replace'];
        }
        if ($prevVer > 0 && $reason === self::REASON_FIRST_ISSUE) {
            return ['ok' => false, 'reason' => 'card_already_issued'];
        }

        // ID-RACE-1 — optimistic concurrency. Checked AFTER the reason guards
        // so a caller gets the most specific refusal, and BEFORE any write so
        // a losing race leaves nothing behind.
        if ($expectedCurrentVersion !== null && $expectedCurrentVersion !== $prevVer) {
            return [
                'ok'      => false,
                'reason'  => 'version_conflict',
                'version' => $prevVer,
            ];
        }

        $now      = date('c');
        $srNo     = trim((string) ($identity['srNo'] ?? ''));
        $admNo    = trim((string) ($identity['admissionNo'] ?? ''));
        $note     = self::normalizeNote($note);

        // Atomic version claim — see CONCURRENCY in the class docblock.
        $version = 0;
        for ($i = 0; $i < self::MAX_CLAIM_ATTEMPTS; $i++) {
            $candidate = $prevVer + 1 + $i;
            if ($candidate > self::MAX_VERSION) break;

            $claimed = (bool) $this->firebase->firestoreCreate(
                self::COL,
                $this->versionDocId($studentId, $candidate),
                [
                    'kind'             => self::KIND_VERSION,
                    'schoolId'         => $this->schoolId,
                    'studentId'        => $studentId,
                    'version'          => $candidate,
                    'status'           => self::STATUS_ACTIVE,
                    'reason'           => $reason,
                    'reasonNote'       => $note,
                    'event'            => self::EVENT_ISSUED,
                    'credentialType'   => self::CRED_V2,
                    'srNo'             => $srNo,
                    'admissionNo'      => $admNo,
                    'studentName'      => (string) ($identity['studentName'] ?? ''),
                    'className'        => (string) ($identity['className'] ?? ''),
                    'section'          => (string) ($identity['section'] ?? ''),
                    'supersedesVersion' => $prevVer > 0 ? $prevVer : null,
                    'issuedAt'         => $now,
                    'issuedBy'         => $this->actorId,
                    'createdAt'        => $now,
                    'updatedAt'        => $now,
                ]
            );
            if ($claimed) { $version = $candidate; break; }
            // Lost the race for this number — probe upward.
        }

        if ($version === 0) {
            log_message('error', "Id_card_service::issue version claim exhausted student={$studentId} school={$this->schoolId}");
            return ['ok' => false, 'reason' => 'version_claim_exhausted'];
        }

        // Retire the predecessor. Best-effort by design: the new version
        // record already exists and the pointer below is what decides which
        // card is live, so a failure here leaves a stale stamp on history,
        // never a second active card.
        if ($prevVer > 0) {
            try {
                $this->fs->set(self::COL, $this->versionDocId($studentId, $prevVer), [
                    'status'              => self::STATUS_REPLACED,
                    'event'               => self::EVENT_REPLACED,
                    'supersededByVersion' => $version,
                    'replacedAt'          => $now,
                    'replacedBy'          => $this->actorId,
                    'replacedReason'      => $reason,
                    'updatedAt'           => $now,
                ], true);
            } catch (\Throwable $e) {
                log_message('error', "Id_card_service::issue supersede stamp failed student={$studentId} v={$prevVer}: " . $e->getMessage());
            }
        }

        $this->_writePointer($studentId, $version, self::STATUS_ACTIVE, $reason, $identity, $now);

        log_audit('SIS', 'id_card_issued', $studentId,
            "ID card v{$version} issued ({$reason})", [
                'entityType' => 'idCard',
                'meta' => [
                    'version'    => $version,
                    'previous'   => $prevVer,
                    'reason'     => $reason,
                    'reasonNote' => $note,
                    'srNo'       => $srNo,
                    'schoolId'   => $this->schoolId,
                    'actor'      => $this->actorId,
                ],
            ]);

        return [
            'ok'         => true,
            'version'    => $version,
            'credential' => $this->mintCredential($studentId, $version),
        ];
    }

    /**
     * Revoke the student's active card WITHOUT issuing a successor — the
     * "card is gone and the student should hold nothing" case. Distinct from
     * issue(), which supersedes.
     *
     * @return array{ok:bool,reason?:string,version?:int}
     */
    public function revoke(string $studentId, string $reason, string $note = ''): array
    {
        if (!$this->ready)     return ['ok' => false, 'reason' => 'not_initialized'];
        if ($studentId === '') return ['ok' => false, 'reason' => 'missing_student_id'];
        if (!in_array($reason, self::validReasons(), true)) {
            return ['ok' => false, 'reason' => 'invalid_reason'];
        }

        $cur = $this->currentCard($studentId);
        if ($cur === null) return ['ok' => false, 'reason' => 'no_card'];

        $ver = (int) ($cur['currentVersion'] ?? 0);
        if ($ver < 1) return ['ok' => false, 'reason' => 'no_card'];
        if ((string) ($cur['status'] ?? '') !== self::STATUS_ACTIVE) {
            // Idempotent: already out of service, nothing further to do.
            return ['ok' => true, 'reason' => 'noop', 'version' => $ver];
        }

        $now  = date('c');
        $note = self::normalizeNote($note);
        try {
            $this->fs->set(self::COL, $this->versionDocId($studentId, $ver), [
                'status'    => self::STATUS_REVOKED,
                'event'     => self::EVENT_REVOKED,
                'revokedAt' => $now,
                'revokedBy' => $this->actorId,
                'revokedReason' => $reason,
                'revokedNote'   => $note,
                'updatedAt' => $now,
            ], true);
        } catch (\Throwable $e) {
            log_message('error', "Id_card_service::revoke version write failed student={$studentId} v={$ver}: " . $e->getMessage());
            return ['ok' => false, 'reason' => 'write_failed'];
        }

        $this->_writePointer($studentId, $ver, self::STATUS_REVOKED, $reason, [
            'srNo'        => (string) ($cur['srNo'] ?? ''),
            'admissionNo' => (string) ($cur['admissionNo'] ?? ''),
            'studentName' => (string) ($cur['studentName'] ?? ''),
            'className'   => (string) ($cur['className'] ?? ''),
            'section'     => (string) ($cur['section'] ?? ''),
        ], $now);

        log_audit('SIS', 'id_card_revoked', $studentId,
            "ID card v{$ver} revoked ({$reason})", [
                'entityType' => 'idCard',
                'meta' => [
                    'version' => $ver, 'reason' => $reason, 'reasonNote' => $note,
                    'schoolId' => $this->schoolId, 'actor' => $this->actorId,
                ],
            ]);

        return ['ok' => true, 'version' => $ver];
    }

    /**
     * Mint the V2 credential for a specific card version. Pure — no I/O, no
     * state change. Reprinting the SAME version deliberately yields the SAME
     * token: a reprint is not a reissue, and must not silently invalidate the
     * card in the student's pocket.
     */
    public function mintCredential(string $studentId, int $version): string
    {
        // Load the helper only if it isn't already in scope. CI's loader is a
        // no-op on a second call, but issue() mints on every card and this
        // keeps the hot path free of a needless loader round-trip — and lets
        // the service be unit-tested without booting a CI instance.
        if (!function_exists('qr_token_encode_v2')) {
            $CI = &get_instance();
            $CI->load->helper('qr_token');
        }
        return qr_token_encode_v2($this->schoolId, $studentId, $version);
    }

    private function _writePointer(string $studentId, int $version, string $status, string $reason, array $identity, string $now): void
    {
        try {
            $this->fs->set(self::COL, $this->currentDocId($studentId), [
                'kind'           => self::KIND_CURRENT,
                'schoolId'       => $this->schoolId,
                'studentId'      => $studentId,
                'currentVersion' => $version,
                'status'         => $status,
                'reason'         => $reason,
                'credentialType' => self::CRED_V2,
                'srNo'           => trim((string) ($identity['srNo'] ?? '')),
                'admissionNo'    => trim((string) ($identity['admissionNo'] ?? '')),
                'studentName'    => (string) ($identity['studentName'] ?? ''),
                'className'      => (string) ($identity['className'] ?? ''),
                'section'        => (string) ($identity['section'] ?? ''),
                'updatedAt'      => $now,
            ], true);
        } catch (\Throwable $e) {
            log_message('error', "Id_card_service::_writePointer failed student={$studentId}: " . $e->getMessage());
        }
    }
}
