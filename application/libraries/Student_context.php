<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student_context — the ONE snapshot Student 360 works from.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS (baseline §Performance & Scalability Budget)
 * ─────────────────────────────────────────────────────────────────────────
 *  Student 360 renders a rail of actions, each with a precondition ("can this
 *  student be issued a TC?", "is transport suspended?"). If every precondition
 *  ran its own Firestore query, the rail would be an N+1 fan-out that grows
 *  every time a module adds an action — the page would get slower simply
 *  because the ERP got richer.
 *
 *  So the context is assembled ONCE per page load, and
 *  Action_registry::resolveFor() receives it. **Preconditions read from this
 *  object and MUST NOT issue their own reads.** That rule is the whole point
 *  of this class; breaking it silently reintroduces the N+1.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT IT IS NOT
 * ─────────────────────────────────────────────────────────────────────────
 *  Not a cache, not a model, not a source of truth. It is a read-only
 *  snapshot assembled from the modules that own each fact — Fees owns dues,
 *  Transport owns assignments, Entitlements owns entitlements. It stores
 *  nothing and writes nothing.
 *
 *  Every field is defensive: a module that is unavailable yields a safe
 *  default rather than an exception, because one broken module must not blank
 *  the whole workspace. A precondition that cannot be evaluated should
 *  disable its action, never crash the page.
 */
class Student_context
{
    /** @var object|null */ private $firebase;
    /** @var object|null */ private $fs;
    /** @var object|null Transport_service, already init()'d by the caller. */
    private $transport;
    /** @var object|null Entitlement_service, already init()'d by the caller. */
    private $entitlements;
    private string $schoolId = '';
    private string $session  = '';

    /**
     * Both services are INJECTED, already init()'d, rather than constructed
     * here. That keeps this class a pure assembler: it owns no module's
     * configuration, and a caller cannot accidentally get a differently-scoped
     * Transport or Entitlement service than the rest of its request is using.
     *
     * @param object|null $transport    An init()'d Transport_service.
     * @param object|null $entitlements An init()'d Entitlement_service.
     *        Either being absent makes that panel report `available: false`,
     *        rather than this class reaching into the module's collections
     *        itself and forking the query shape.
     */
    public function init(
        $firebase, $fs, string $schoolId, string $session,
        $transport = null, $entitlements = null
    ): self {
        $this->firebase     = $firebase;
        $this->fs           = $fs;
        $this->schoolId     = $schoolId;
        $this->session      = $session;
        $this->transport    = $transport;
        $this->entitlements = $entitlements;
        return $this;
    }

    /**
     * Build the snapshot. Returns a plain array — deliberately not an object
     * graph, so it can be handed to the view, JSON-encoded for the client, and
     * passed to preconditions without any of them needing this class loaded.
     *
     * READ BUDGET (baseline Release Gate 8): 4 reads per page load, fixed.
     *   1 student doc · 1 demands query · 1 entitlements query · 1 transport doc
     * It does NOT grow as the Action Registry grows — that is the guarantee.
     *
     * @return array|null null when the student does not exist in this tenant.
     */
    public function build(string $studentId): ?array
    {
        if ($this->firebase === null || $studentId === '') return null;

        // ── 1. Identity (also the tenant-isolation gate) ──────────────────
        $stu = null;
        try {
            $stu = $this->fs->getEntity('students', $studentId);
        } catch (\Throwable $e) {
            log_message('error', 'Student_context: student read failed for ' . $studentId . ': ' . $e->getMessage());
        }
        if (!is_array($stu)) return null;
        // A student from another school must never resolve, even if the id is
        // guessed. getEntity() prefixes the tenant, but this makes the
        // guarantee explicit rather than incidental.
        if ((string) ($stu['schoolId'] ?? $this->schoolId) !== $this->schoolId) return null;

        // Read through _val(), never a bare (string) cast. Two reasons, both
        // observed in real data rather than hypothetical:
        //
        //  1. Some fields are STRUCTURED (`Address` is a nested array), and a
        //     cast on one raises "Array to string conversion" and prints the
        //     literal word "Array".
        //  2. There is a second, CAPITALISED variant of this schema —
        //     `Name`/`Class`/`Section`/`FatherName` — written by the legacy
        //     admission path. `Red_flags.php` already reads both spellings for
        //     exactly this reason. Reading only camelCase would render a blank
        //     identity header for those students, which on a 360 screen looks
        //     like a data-loss bug rather than a reader bug.
        $ctx = [
            'studentId'   => $studentId,
            'name'        => $this->_val($stu, ['name', 'Name', 'studentName'], $studentId),
            'className'   => $this->_val($stu, ['className', 'Class']),
            'section'     => $this->_val($stu, ['section', 'Section']),
            'fatherName'  => $this->_val($stu, ['fatherName', 'FatherName', 'Father Name']),
            'status'      => strtolower($this->_val($stu, ['status', 'Status'], 'active')),
            'session'     => $this->_val($stu, ['session', 'Session'], $this->session),
            'schoolId'    => $this->schoolId,
            // B-1 — the stored profile picture. Read, never copied: Student 360
            // renders whatever URL SIS already holds, so there is no second
            // image store to keep in sync and no schema change. Blank is a
            // legitimate answer and the view falls back to initials.
            'photo'       => $this->_val($stu, ['profilePic', 'profile_pic', 'Profile Pic']),
        ];
        $ctx['isActive'] = !in_array($ctx['status'], ['inactive', 'deleted', 'left', 'tc_issued'], true);

        // ── Academic / demographic detail ─────────────────────────────────
        // Costs NOTHING: every field below already arrived on the student doc
        // read above. It is projected here rather than fetched, which is why
        // the Academic panel adds no reads at all.
        $ctx['academic'] = $this->_academic($stu);

        // ── 2. Fees — dues, from the module that owns them ────────────────
        $ctx['fees'] = $this->_fees($studentId);

        // ── 3. Entitlements — via the canonical collection, read-only ─────
        $ctx['entitlements'] = $this->_entitlements($studentId);

        // ── 4. Transport — assignment + suspension state ──────────────────
        $ctx['transport'] = $this->_transport($studentId);

        // ── 5. Discipline — active/resolved flags + pattern ───────────────
        $ctx['discipline'] = $this->_discipline($studentId);

        // ── 6. Fee-defaulter status — the STORED result-withheld flag ─────
        $ctx['defaulter'] = $this->_defaulter($studentId);

        // ── 7. Alerts (E-1) — pure aggregation over 1..6, no new reads ────
        $ctx['alerts'] = $this->_alerts($ctx);

        return $ctx;
    }

    /**
     * E-1 — the operational alert strip.
     *
     * ── AGGREGATION ONLY. NO NEW BUSINESS LOGIC, NO NEW READS. ────────────
     * Every alert below is a restatement of a fact another module already
     * decided and this snapshot already holds. This method contains no
     * threshold, no policy and no computation beyond "is this fact present".
     *
     * Concretely: it does not decide what counts as a defaulter (Fees did),
     * whether a result is withheld (`Fee_defaulter_check::_shouldWithholdResult`
     * did, and its answer is read from the stored `feeDefaulters` doc), or what
     * makes a discipline flag serious (Red Flags did). Student 360 only decides
     * that these facts deserve to be visible without opening a tab.
     *
     * Severity is presentation, not policy: 'danger' means money or a blocked
     * outcome, 'warn' means someone must act, 'info' is context.
     *
     * @return array<int, array{key:string,level:string,text:string,tab:?string}>
     */
    /**
     * Public entry point for the same aggregation.
     *
     * The controller redacts module data the current user may not see, and
     * must then rebuild the alert strip over the REDACTED context — otherwise
     * an alert would disclose the very fact the redaction removed ("3 active
     * discipline flags" tells you there are three, without showing them).
     * Exposing the pure function is how that stays a single implementation.
     */
    public function alertsFor(array $ctx): array
    {
        return $this->_alerts($ctx);
    }

    private function _alerts(array $c): array
    {
        $a = [];

        if (!empty($c['fees']['hasDues'])) {
            $a[] = ['key' => 'dues', 'level' => 'danger', 'tab' => 's360p-fees',
                    'text' => 'Outstanding fees ₹' . number_format((float) ($c['fees']['outstanding'] ?? 0), 2)];
        }
        if (!empty($c['defaulter']['resultWithheld'])) {
            $a[] = ['key' => 'withheld', 'level' => 'danger', 'tab' => 's360p-res',
                    'text' => 'Result withheld for fee dues'];
        }
        if (!empty($c['defaulter']['examBlocked'])) {
            $a[] = ['key' => 'exam_blocked', 'level' => 'danger', 'tab' => 's360p-res',
                    'text' => 'Exam blocked — unpaid exam fee'];
        }
        if (((int) ($c['discipline']['activeCount'] ?? 0)) > 0) {
            $n = (int) $c['discipline']['activeCount'];
            $a[] = ['key' => 'discipline', 'level' => 'warn', 'tab' => 's360p-disc',
                    'text' => $n . ' active discipline flag' . ($n === 1 ? '' : 's')];
        }
        if (((int) ($c['entitlements']['parentPendingCount'] ?? 0)) > 0) {
            $n = (int) $c['entitlements']['parentPendingCount'];
            $a[] = ['key' => 'parent_req', 'level' => 'warn', 'tab' => 's360p-req',
                    'text' => $n . ' parent request' . ($n === 1 ? '' : 's') . ' awaiting a decision'];
        }
        if (((int) ($c['entitlements']['pendingCount'] ?? 0)) > 0) {
            $n = (int) $c['entitlements']['pendingCount'];
            $a[] = ['key' => 'ent_pending', 'level' => 'warn', 'tab' => 's360p-ent',
                    'text' => $n . ' entitlement' . ($n === 1 ? '' : 's') . ' pending approval'];
        }
        if (!empty($c['transport']['suspended'])) {
            $a[] = ['key' => 'transport', 'level' => 'warn', 'tab' => 's360p-trn',
                    'text' => 'Transport suspended'];
        }
        if (empty($c['isActive'])) {
            $a[] = ['key' => 'inactive', 'level' => 'info', 'tab' => null,
                    'text' => 'Student is ' . ($c['status'] ?? 'not active')];
        }

        // Most serious first, and stable within a level so the strip does not
        // reshuffle between loads.
        $rank = ['danger' => 0, 'warn' => 1, 'info' => 2];
        usort($a, fn(array $x, array $y) => ($rank[$x['level']] ?? 9) <=> ($rank[$y['level']] ?? 9));
        return $a;
    }

    /**
     * A-2 — Discipline, from `studentFlags`.
     *
     * ── WHY THIS DOES NOT CALL Red_flags::get_student_flags() ─────────────
     * That endpoint is a CONTROLLER method, so it cannot be called from here
     * without an HTTP hop. More importantly it delegates to
     * `_collect_all_flags()`, which fetches the **whole school's** flags —
     * two 500-row queries — and then filters to one student in PHP. That is
     * correct for the dashboard it was written for, and completely wrong for
     * a per-student page: ~1,000 document reads to display a handful of rows.
     *
     * So this reads the same collection with the student pushed down into the
     * query. No flag logic is reimplemented — severity, type and status are
     * stored fields, and the counts below are arithmetic over them. Anything
     * that constitutes a *rule* (what to flag, when to escalate, who may
     * resolve) stays in Red Flags, and every mutation still goes there.
     *
     * ── DUAL-KEY ─────────────────────────────────────────────────────────
     * `create_flag()` stamps BOTH `schoolId` and `schoolCode`. The canonical
     * aggregation runs a two-query OR because older rows may carry only one.
     * The same is done here — still equality-only, still cheap — rather than
     * assuming every row is modern and silently under-reporting a student's
     * history.
     *
     * ── WHY THIS IS *NOT* SESSION-SCOPED (deliberate — do not "fix") ──────
     * `_fees()` above was corrected to filter by session (S360-Z2). This query
     * is intentionally left unscoped, because the Red Flags module itself
     * chooses not to scope: `Red_flags::_collect_all_flags()` states "Session
     * is intentionally NOT included in the Firestore query". Adding a session
     * filter here would make Student 360 show FEWER flags than the module that
     * owns them, and a behaviour record that resets each July is not what a
     * school office means by discipline history.
     *
     * The distinction that matters: **money is a per-session balance; conduct
     * is a continuous record.** Same missing clause, opposite correct answer.
     */
    private function _discipline(string $studentId): array
    {
        $out = ['available' => false, 'flags' => [], 'activeCount' => 0, 'resolvedCount' => 0,
                'byType' => [], 'bySeverity' => [], 'highActive' => 0];
        if ($this->firebase === null || $studentId === '') return $out;

        $seen = [];
        $rows = [];
        try {
            foreach (['schoolId', 'schoolCode'] as $key) {
                $res = $this->firebase->firestoreQuery('studentFlags', [
                    [$key,        '==', $this->schoolId],
                    ['studentId', '==', $studentId],
                ], null, 'ASC', 100);
                foreach ((array) $res as $r) {
                    $id = (string) ($r['id'] ?? '');
                    $d  = is_array($r['data'] ?? null) ? $r['data'] : $r;
                    if (!is_array($d)) continue;
                    $fid = $id !== '' ? $id : (string) ($d['flagId'] ?? '');
                    if ($fid !== '' && isset($seen[$fid])) continue;   // dedupe the OR
                    if ($fid !== '') $seen[$fid] = true;
                    $rows[] = $d;
                }
            }
            $out['available'] = true;
        } catch (\Throwable $e) {
            log_message('error', 'Student_context: discipline read failed for ' . $studentId . ': ' . $e->getMessage());
            return $out;
        }

        foreach ($rows as $d) {
            $status   = strtolower((string) ($d['status'] ?? 'active'));
            if ($status === 'deleted') continue;          // soft-deleted, as Red Flags treats it
            $severity = strtolower((string) ($d['severity'] ?? 'low'));
            $type     = strtolower((string) ($d['type'] ?? 'other'));

            $out['flags'][] = [
                'flagId'      => (string) ($d['flagId'] ?? ''),
                'type'        => $type,
                'severity'    => $severity,
                'status'      => $status,
                'description' => (string) ($d['description'] ?? $d['remarks'] ?? ''),
                'createdAt'   => (string) ($d['createdAt'] ?? ''),
                'createdBy'   => (string) ($d['createdBy'] ?? $d['flaggedBy'] ?? ''),
                'resolvedAt'  => (string) ($d['resolvedAt'] ?? ''),
                'resolvedBy'  => (string) ($d['resolvedBy'] ?? ''),
            ];
            if ($status === 'resolved') $out['resolvedCount']++;
            else                        $out['activeCount']++;
            if ($status !== 'resolved' && $severity === 'high') $out['highActive']++;

            $out['byType'][$type]         = ($out['byType'][$type] ?? 0) + 1;
            $out['bySeverity'][$severity] = ($out['bySeverity'][$severity] ?? 0) + 1;
        }

        usort($out['flags'], fn(array $a, array $b) => strcmp((string) $b['createdAt'], (string) $a['createdAt']));
        return $out;
    }

    /**
     * Fee-defaulter status, read from the STORED `feeDefaulters` document.
     *
     * ── WHY A STORED READ AND NOT A COMPUTATION ──────────────────────────
     * `result_withheld` is decided by `Fee_defaulter_check::_shouldWithholdResult()`,
     * which is **private** and consults a `feeSettings` policy document.
     * Re-deriving it here would fork a money-adjacent rule. `updateDefaulterStatus()`
     * computes it and writes it to `feeDefaulters/{school}_{session}_{student}` —
     * so the correct read-only move is to display what that canonical writer
     * stored, exactly as the Attendance panel displays stored percentages.
     *
     * ── AND WHY NOT COPY Result.php ──────────────────────────────────────
     * `Result::index()` obtains the same flag with
     * `$this->firebase->get("Schools/{school}/{session}/Fees/Defaulters/{id}")`
     * — an **RTDB** read. This platform forbids RTDB, and `Fee_defaulter_check`
     * itself records that "Firestore is the SOLE source of truth for defaulter
     * status". Student 360 therefore reads the Firestore document rather than
     * reproducing the Result module's legacy path. Reported as RES-001.
     *
     * One point read. Absent doc = no withholding, which is the correct
     * fail-open: a missing status must not invent a block on a child's result.
     */
    private function _defaulter(string $studentId): array
    {
        $out = ['available' => false, 'isDefaulter' => false, 'totalDues' => 0.0,
                'resultWithheld' => false, 'examBlocked' => false];
        if ($this->firebase === null || $studentId === '') return $out;
        try {
            $doc = $this->firebase->firestoreGet(
                'feeDefaulters',
                $this->schoolId . '_' . $this->session . '_' . $studentId
            );
            $out['available'] = true;
            if (is_array($doc) && $doc !== []) {
                if (isset($doc['data']) && is_array($doc['data'])) $doc = $doc['data'];
                $out['isDefaulter']    = !empty($doc['is_defaulter'] ?? $doc['isDefaulter'] ?? false);
                $out['totalDues']      = (float) ($doc['total_dues'] ?? $doc['totalDues'] ?? 0);
                $out['resultWithheld'] = !empty($doc['result_withheld'] ?? $doc['resultWithheld'] ?? false);
                $out['examBlocked']    = !empty($doc['exam_blocked'] ?? $doc['examBlocked'] ?? false);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Student_context: defaulter read failed for ' . $studentId . ': ' . $e->getMessage());
            $out['available'] = false;
        }
        return $out;
    }

    /**
     * Read one display value off a student document, safely.
     *
     * ── WHY EVERY READ GOES THROUGH HERE ─────────────────────────────────
     * Two properties of the real `students` schema make a bare `(string)` cast
     * unsafe, and both were found at runtime rather than by inspection:
     *
     *  • **Structured fields.** `Sis` writes
     *    `"Address" => ["Street"=>…, "City"=>…, "State"=>…, "PostalCode"=>…]`
     *    and `"Doc" => [...]`. Casting one raises "Array to string conversion"
     *    and prints the literal word "Array" where a value belongs.
     *
     *  • **Two key spellings.** A legacy admission path writes capitalised keys
     *    (`Name`, `Class`, `Section`, `FatherName`, `Profile Pic`) instead of
     *    camelCase. `Red_flags.php` already reads both for this reason. Reading
     *    one spelling renders a blank identity header for the other cohort —
     *    which on a 360 screen reads as data loss, not as a reader bug.
     *
     * Arrays are flattened generically rather than special-casing `Address`,
     * because any field here may turn out to be structured and a display-only
     * projection must never be what breaks the page. Scalar leaves are joined
     * in insertion order — for an address that is already Street → City →
     * State → PostalCode — with empty parts dropped, so a half-filled address
     * renders "Kanpur, 208001" rather than ", Kanpur, , 208001".
     *
     * @param array $keys Candidate spellings, most-canonical first.
     */
    private function _val(array $d, array $keys, string $default = ''): string
    {
        foreach ($keys as $k) {
            if (!isset($d[$k])) continue;
            $s = $this->_flatten($d[$k]);
            if ($s !== '') return $s;
        }
        return $default;
    }

    /** Recursively reduce a stored value to something displayable. */
    private function _flatten($v): string
    {
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $item) {
                $s = $this->_flatten($item);
                if ($s !== '') $parts[] = $s;
            }
            return implode(', ', $parts);
        }
        if (is_bool($v))   return $v ? 'Yes' : 'No';
        if (is_scalar($v)) return trim((string) $v);
        return '';   // objects / null / resources are not displayable
    }

    /**
     * Project academic / demographic fields off the student document.
     *
     * A PROJECTION, not a fetch — the doc is already in hand, so the Academic
     * panel costs zero additional reads.
     *
     * Field names are read defensively with alternates because the student
     * schema has accumulated variants over time (`admissionNo` vs
     * `admission_no` vs `Admission Number`). Guessing one and silently showing
     * a blank would be worse than checking all three: a blank admission number
     * on a 360 screen reads as "this student has none".
     */
    private function _academic(array $stu): array
    {
        $pick = fn(array $d, array $keys): string => $this->_val($d, $keys);

        return [
            'admissionNo'    => $pick($stu, ['admissionNo', 'admission_no', 'Admission Number', 'admissionNumber']),
            'admissionDate'  => $pick($stu, ['admissionDate', 'admission_date', 'Admission Date']),
            'rollNo'         => $pick($stu, ['rollNo', 'roll_no', 'Roll No', 'rollNumber']),
            'house'          => $pick($stu, ['house', 'House']),
            'dob'            => $pick($stu, ['dob', 'DOB', 'dateOfBirth', 'date_of_birth']),
            'gender'         => $pick($stu, ['gender', 'Gender']),
            'bloodGroup'     => $pick($stu, ['bloodGroup', 'blood_group', 'Blood Group']),
            'category'       => $pick($stu, ['category', 'Category']),
            'religion'       => $pick($stu, ['religion', 'Religion']),
            'motherName'     => $pick($stu, ['motherName', 'mother_name', 'Mother Name']),
            'phone'          => $pick($stu, ['phone', 'Phone', 'mobile', 'contactNumber']),
            'email'          => $pick($stu, ['email', 'Email']),
            'address'        => $pick($stu, ['address', 'Address']),
            'previousSchool' => $pick($stu, ['previousSchool', 'previous_school', 'Previous School']),
            // Identity fields from the SR/APAAR design. Present only where the
            // tenant has adopted them; blank is a legitimate answer, not a bug.
            // Phase 9.2 (2026-08-13): 'srNo' added as the FIRST-preference
            // alias — it is the live, canonical field Sr_no_service/
            // Sis::_claimIdentityAttributes() actually write (confirmed by
            // source read); 'srNumber'/'sr_number'/'SR Number' are a
            // pre-existing, still-dead legacy field name from an earlier
            // design pass with zero writers anywhere in this codebase
            // (confirmed by repo-wide grep) — kept only for backward
            // compatibility in case a future writer ever adopts that name,
            // never removed per the explicit instruction not to silently
            // break Student 360 compatibility.
            'srNumber'       => $pick($stu, ['srNo', 'srNumber', 'sr_number', 'SR Number']),
            'apaarId'        => $pick($stu, ['apaarId', 'apaar_id', 'APAAR ID']),
        ];
    }

    /**
     * Dues for the CURRENT SESSION.
     *
     * ── SESSION SCOPING (S360-Z2, fixed 2026-08-12) ──────────────────────
     * This query originally filtered on schoolId + studentId only. That is the
     * defect CLAUDE.md calls "the most repeated bug in this codebase" — and it
     * was not theoretical here: STU0011 holds 37 demands dated 2027-28, so the
     * workspace reported ₹47,000 outstanding for a student who owes NOTHING in
     * 2026-27. Wrong on the headline figure, the red alert, and the folded
     * Money headline simultaneously.
     *
     * It also produced a false cross-module finding: that student appeared
     * "inconsistently exempt" from result-withholding, when in truth
     * `feeDefaulters` is session-scoped (`{school}_{session}_{student}`) and
     * had correctly recorded no dues. The Fees module was right; this reader
     * was wrong.
     *
     * Verified before adding the filter: all 853 demands in the tenant carry a
     * non-blank `session`, so nothing is hidden by scoping. Still equality-only
     * — three `==` clauses use index merging, so NO composite index is needed
     * and no rules/index deploy accompanies this fix.
     *
     * ── ARCHIVED / CANCELLED ─────────────────────────────────────────────
     * Excluded, as the ledger should. (FEE-002 records that the ledger summary
     * does NOT exclude them — STU0004 carries 74 archived beside 37 active, so
     * every fee appears twice there. This class deliberately does the correct
     * thing and does not attempt to fix the Fees module.)
     */
    private function _fees(string $studentId): array
    {
        $out = ['totalDue' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'demandCount' => 0, 'hasDues' => false];
        try {
            $rows = $this->firebase->firestoreQuery('feeDemands', [
                ['schoolId',  '==', $this->schoolId],
                ['studentId', '==', $studentId],
                ['session',   '==', $this->session],
            ], null, 'ASC', 500);
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                if (!is_array($d)) continue;
                $st = strtolower((string) ($d['status'] ?? ''));
                if ($st === 'archived' || $st === 'cancelled') continue;
                $out['demandCount']++;
                $out['totalDue'] += (float) ($d['netAmount'] ?? 0);
                $out['paid']     += (float) ($d['paidAmount'] ?? 0);
                $out['outstanding'] += (float) ($d['balance'] ?? 0);
            }
            foreach (['totalDue', 'paid', 'outstanding'] as $k) $out[$k] = round($out[$k], 2);
            $out['hasDues'] = $out['outstanding'] > 0.005;
        } catch (\Throwable $e) {
            log_message('error', 'Student_context: fees read failed for ' . $studentId . ': ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Entitlements, via `Entitlement_service::forStudent()` — the SAME
     * accessor the shared capture partial reaches through
     * `entitlements/for_student`.
     *
     * Deliberately NOT a direct `studentEntitlements` query. Only two places
     * may shape that query: `Fee_entitlement_reader` (Phase C, frozen — the
     * PRICING read, which applies invariant I3 and effective-window filtering)
     * and `Entitlement_service` (the write-path service's own display read).
     * A third shape here would be a silent fork: at the Gate 8 reader swap the
     * canonical resolution changes and this panel would keep reporting the old
     * answer, with nothing failing to reveal it.
     *
     * `forStudent()` returns every status; the split below is presentation.
     */
    private function _entitlements(string $studentId): array
    {
        $out = ['available' => false, 'active' => [], 'pending' => [],
                'parentRequests' => [], 'activeCount' => 0, 'pendingCount' => 0,
                'parentRequestCount' => 0, 'parentPendingCount' => 0];
        if ($this->entitlements === null) return $out;
        $out['available'] = true;
        try {
            $rows = $this->entitlements->forStudent($studentId);
            foreach ((array) $rows as $d) {
                if (!is_array($d)) continue;
                $row = [
                    'id'     => (string) ($d['entitlementId'] ?? ''),
                    'kind'   => (string) ($d['kind'] ?? ''),
                    'type'   => (string) ($d['concessionType'] ?? $d['serviceType'] ?? ''),
                    'scope'  => (string) ($d['scope'] ?? ''),
                    'valueType' => (string) ($d['valueType'] ?? ''),
                    'value'  => $d['value'] ?? 0,
                    'status' => (string) ($d['status'] ?? ''),
                    'source' => (string) ($d['source'] ?? ''),
                    'reason' => (string) ($d['reason'] ?? ''),
                    'createdAt' => (string) ($d['createdAt'] ?? ''),
                ];
                if ($row['status'] === 'active')                 { $out['active'][]  = $row; }
                elseif ($row['status'] === 'pending_approval')   { $out['pending'][] = $row; }

                // ── Parent Requests ───────────────────────────────────────
                // A parent request is NOT a separate collection. Phase F's
                // Entitlement_requests::submit() calls
                // Entitlement_service::propose() with source='parent_request',
                // value=0 and approvalRequired forced true — so it lands in
                // `studentEntitlements` like any other proposal.
                //
                // The Parent Requests panel is therefore a FILTER over rows
                // already fetched above: zero additional reads, and it cannot
                // drift from the entitlement record because it IS the
                // entitlement record.
                if ($row['source'] === 'parent_request') {
                    $out['parentRequests'][] = $row;
                }
            }
            $out['activeCount']         = count($out['active']);
            $out['pendingCount']        = count($out['pending']);
            $out['parentRequestCount']  = count($out['parentRequests']);
            $out['parentPendingCount']  = count(array_filter(
                $out['parentRequests'],
                static fn(array $r): bool => $r['status'] === 'pending_approval'
            ));
        } catch (\Throwable $e) {
            log_message('error', 'Student_context: entitlements read failed for ' . $studentId . ': ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Transport assignment, via `Transport_service::getStudentAssignment()` —
     * the module's own single-student read accessor, which already applies the
     * defensive tenant guard and the {id,data} shape normalisation.
     *
     * This class does NOT read `studentRoutes` itself. That collection is
     * marked LEGACY in Transport_service (`COL_STUDENT_ROUTES_LEGACY`, "kept
     * during rollover") — a direct read here would silently pin Student 360 to
     * a collection Transport intends to move off, and would have to be found
     * and fixed again at rollover. Going through the accessor means the day
     * Transport switches storage, this file needs no change.
     *
     * Without an injected Transport_service the panel reports `unavailable`
     * rather than guessing. Transport *mutations* never come near this class.
     */
    private function _transport(string $studentId): array
    {
        $out = [
            'available'  => false, 'assigned' => false, 'suspended' => false,
            'routeName'  => '', 'routeId' => '', 'stopName' => '',
            'monthlyFee' => 0.0, 'subscription' => '',
        ];
        if ($this->transport === null) return $out;
        $out['available'] = true;
        try {
            $a = $this->transport->getStudentAssignment($studentId);
            if (is_array($a) && $a !== []) {
                $out['assigned']     = true;
                $out['routeName']    = (string) ($a['route_name'] ?? '');
                $out['routeId']      = (string) ($a['route_id'] ?? '');
                $out['stopName']     = (string) ($a['stop_name'] ?? '');
                $out['monthlyFee']   = (float)  ($a['monthly_fee'] ?? 0);
                $out['subscription'] = (string) ($a['subscription_type'] ?? '');
                // Same test Transport_service::_isSuspended() applies. That
                // helper is private, so the single field check is mirrored
                // rather than called; if it ever becomes public, call it.
                $out['suspended']    = ((string) ($a['suspended_from'] ?? '')) !== '';
            }
        } catch (\Throwable $e) {
            log_message('error', 'Student_context: transport read failed for ' . $studentId . ': ' . $e->getMessage());
            $out['available'] = false;
        }
        return $out;
    }
}
