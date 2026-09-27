<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_late_fee_service — Phase 15.6.
 *
 * ONE canonical late-fee engine. Before this library the only implementation
 * lived inside `Fees::compute_fines_for_student()` — a PRIVATE method on a web
 * controller, therefore unreachable from CLI. Automating late fees would have
 * required a second copy of the rules, i.e. two fee engines that could drift
 * apart on money. This library is that single implementation; the controller
 * endpoint and the nightly cron both delegate here.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  POLICY (feeSettings/{schoolId}_{session}_reminders)
 * ──────────────────────────────────────────────────────────────────────
 *   late_fee_enabled     bool     master switch (pre-existing)
 *   late_fee_type        string   'fixed' | 'percentage'   (pre-existing)
 *   late_fee_value       number   amount or percent        (pre-existing)
 *   late_fee_grace_days  int      NEW — days after dueDate before a fine
 *                                 applies. DEFAULT 0 = today's behaviour.
 *   late_fee_max_cap     number   NEW — per-demand ceiling.
 *                                 DEFAULT 0 = uncapped = today's behaviour.
 *
 * Both new keys default to the EXACT pre-existing behaviour, so a school that
 * never configures them sees no change. They are deliberately per-school and
 * per-session: Indian school fee regulation is STATE jurisdiction (Delhi,
 * Maharashtra and Gujarat statutes all differ), so there is no single national
 * grace period or cap that could be hard-coded correctly for every tenant.
 *
 * PER-DAY PENALTIES ARE DELIBERATELY NOT SUPPORTED. The idempotency guard
 * below is `abs(existing - computed) < 0.01` — an absolute write, not an
 * accumulator, which is what makes repeated runs safe. A per-day fine changes
 * every calendar day, so it would rewrite the demand (and move the balance)
 * on every run, and without a mandatory cap it grows unbounded. Supporting it
 * safely needs a cap contract plus reversal semantics that are NOT ESTABLISHED
 * BY SOURCES. Excluded until they are.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  COST (school-wide run)
 * ──────────────────────────────────────────────────────────────────────
 *   1 settings read  +  1 demands query  +  W writes
 * where W = demands whose fine actually CHANGED. A second run the same day
 * performs ZERO writes. Deliberately NOT one query per student — that would
 * be an N+1 over the whole school.
 */
class Fee_late_fee_service
{
    private const COL_DEMANDS  = 'feeDemands';
    private const COL_SETTINGS = 'feeSettings';

    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var object */ private $fsTxn;

    private string $schoolId = '';
    private string $session  = '';
    private bool   $ready    = false;

    /** Memoised policy — one settings read per instance, never per demand. */
    private ?array $policyMemo = null;

    public function init($firebase, $fs, $fsTxn, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->fsTxn    = $fsTxn;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($schoolId !== '' && $session !== '');
        return $this;
    }

    // ── Policy ───────────────────────────────────────────────────────

    /**
     * Resolve the school's late-fee policy exactly once.
     *
     * Returns ['enabled'=>bool,'type'=>string,'value'=>float,
     *          'graceDays'=>int,'cap'=>float,'reason'=>string]
     */
    public function policy(): array
    {
        if ($this->policyMemo !== null) return $this->policyMemo;

        $off = ['enabled' => false, 'type' => 'fixed', 'value' => 0.0,
                'graceDays' => 0, 'cap' => 0.0, 'reason' => 'Late fee not enabled'];
        if (!$this->ready) return $this->policyMemo = $off;

        try {
            $s = $this->firebase->firestoreGet(
                self::COL_SETTINGS, "{$this->schoolId}_{$this->session}_reminders"
            );
        } catch (\Throwable $e) {
            log_message('error', 'Fee_late_fee_service::policy read failed: ' . $e->getMessage());
            // Fail CLOSED: an unreadable policy must not silently fine families.
            return $this->policyMemo = array_merge($off, ['reason' => 'Late fee settings unreadable']);
        }

        if (!is_array($s) || !($s['late_fee_enabled'] ?? false)) {
            return $this->policyMemo = $off;
        }

        $value = (float) ($s['late_fee_value'] ?? 0);
        if ($value <= 0) {
            return $this->policyMemo = array_merge($off, ['reason' => 'Late fee value is 0']);
        }

        // Grace days: clamped to a sane range. Negative would fine BEFORE the
        // due date; an absurd value would silently disable late fees.
        $grace = (int) ($s['late_fee_grace_days'] ?? 0);
        if ($grace < 0)   $grace = 0;
        if ($grace > 365) $grace = 365;

        $cap = (float) ($s['late_fee_max_cap'] ?? 0);
        if ($cap < 0) $cap = 0.0;   // 0 == uncapped

        return $this->policyMemo = [
            'enabled'   => true,
            'type'      => (string) ($s['late_fee_type'] ?? 'fixed'),
            'value'     => $value,
            'graceDays' => $grace,
            'cap'       => $cap,
            'reason'    => '',
        ];
    }

    /**
     * The date a demand must be due BEFORE in order to attract a fine.
     * With graceDays = 0 this is today, preserving the original
     * `$dueDate >= $today → skip` semantics byte-for-byte.
     */
    public function cutoffDate(?int $graceDays = null, ?string $today = null): string
    {
        $g = $graceDays ?? (int) $this->policy()['graceDays'];
        $t = $today     ?? date('Y-m-d');
        if ($g <= 0) return $t;
        return date('Y-m-d', strtotime("{$t} -{$g} days"));
    }

    /**
     * Pure fine computation for one demand. Public so it is directly testable
     * and so the rule can never be re-implemented elsewhere.
     *
     * Returns null when no fine applies.
     */
    public function fineFor(array $demand, array $policy, string $cutoff): ?float
    {
        $status = strtolower((string) ($demand['status'] ?? 'unpaid'));

        // Paid demands never attract a new fine.
        //
        // SW4-companion-F (2026-05-27), carried forward verbatim in intent
        // from Fees::compute_fines_for_student — archived demands MUST be
        // skipped. This is a MUTATION-class path: applyTo() writes
        // fine_amount + balance. Without this guard an archived demand whose
        // dueDate has passed would accrue a fine, and the recomputed balance
        // (netAmount + fine - paidAmount) would re-introduce a non-zero
        // balance onto a demand the archive operation deliberately set to
        // balance=0 — re-corrupting historical state and bleeding the demand
        // back into pending-dues surfaces.
        //
        // Active-demand semantics unchanged: 'unpaid' and 'partial' proceed.
        // ── FRZ-1 · A DENYLIST HERE HAS NOW FAILED TWICE ────────────────
        // This was `$status === 'paid' || $status === 'archived'`, and that
        // shape misses every closed status nobody thought to add. It missed
        // 'archived' until SW4-companion-F added it reactively, and it was
        // still missing 'frozen'.
        //
        // 'frozen' is what `Fee_lifecycle::freezeFeesOnSoftDelete()` writes
        // when a student is WITHDRAWN or soft-deleted — its own docblock reads
        // "do not collect". `computeForSchool()` queries every demand in the
        // session with no student-status filter, so a withdrawn student's
        // demands were still in the set: each late-fee run added a fine and
        // recomputed `balance = netAmount + fine - paidAmount`, growing a debt
        // against someone who had already left the school.
        //
        // Now an ALLOWLIST, which is the shape REDATE_ELIGIBLE_STATUSES in
        // this same file already uses. An unrecognised status is treated as
        // CLOSED rather than fineable, so the next status added to the
        // vocabulary is safe by default instead of dangerous by default.
        if (!in_array($status, self::FINEABLE_STATUSES, true)) return null;

        $dueDate = (string) ($demand['due_date'] ?? $demand['dueDate'] ?? '');
        // No due date → never overdue. This is why AB (Phase 15.5) had to land
        // first: before it, almost every demand fell out here.
        if ($dueDate === '' || $dueDate >= $cutoff) return null;

        $netAmount = (float) ($demand['net_amount'] ?? $demand['netAmount'] ?? 0);
        if ($netAmount <= 0) return null;

        $fine = ($policy['type'] === 'percentage')
            ? round($netAmount * ((float) $policy['value'] / 100), 2)
            : round((float) $policy['value'], 2);

        $cap = (float) ($policy['cap'] ?? 0);
        if ($cap > 0 && $fine > $cap) $fine = round($cap, 2);

        return $fine;
    }

    // ── Execution ────────────────────────────────────────────────────

    /**
     * Apply the policy to a pre-loaded set of demands.
     * `$demands` is [demandId => normalised demand].
     */
    private function applyTo(array $demands, array $policy, string $cutoff, bool $dryRun): array
    {
        $updated = 0; $totalFine = 0.0; $skipped = 0; $details = [];

        foreach ($demands as $did => $d) {
            $fine = $this->fineFor($d, $policy, $cutoff);
            if ($fine === null) { $skipped++; continue; }

            // IDEMPOTENCY: absolute value, never `+=`. Re-running the same day
            // is a no-op, which is what makes a nightly cron safe.
            $existingFine = (float) ($d['fine_amount'] ?? $d['fineAmount'] ?? 0);
            if (abs($existingFine - $fine) < 0.01) { $skipped++; continue; }

            $netAmount  = (float) ($d['net_amount']  ?? $d['netAmount']  ?? 0);
            $paidAmount = (float) ($d['paid_amount'] ?? $d['paidAmount'] ?? 0);
            $newBalance = round(max(0, $netAmount + $fine - $paidAmount), 2);

            $details[] = ['demandId' => (string) $did, 'fine' => $fine, 'balance' => $newBalance];

            if (!$dryRun) {
                $this->fsTxn->updateDemand((string) $did, [
                    'fine_amount'      => $fine,
                    'balance'          => $newBalance,
                    'fine_computed_at' => date('c'),
                ]);
            }
            $updated++;
            $totalFine += $fine;
        }

        return [
            'demands_updated' => $updated,
            'total_fine'      => round($totalFine, 2),
            'skipped'         => $skipped,
            'dry_run'         => $dryRun,
            'details'         => $details,
        ];
    }

    /** One student. Mirrors the original endpoint's contract exactly. */
    public function computeForStudent(string $studentId, bool $dryRun = false): array
    {
        if (!$this->ready || $studentId === '') {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => 'Not initialised'];
        }
        $policy = $this->policy();
        if (!$policy['enabled']) {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => $policy['reason']];
        }
        $demands = $this->fsTxn->demandsForStudent($studentId);
        if (empty($demands)) return ['demands_updated' => 0, 'total_fine' => 0];

        return $this->applyTo($demands, $policy, $this->cutoffDate(), $dryRun);
    }

    /**
     * Whole school, in ONE demands query.
     *
     * Deliberately NOT `foreach student → computeForStudent()`: that is an
     * N+1 (one Firestore query per student, ~2,000 for a mid-size school).
     * One session-scoped query returns every demand; the filtering is then
     * in-memory and free.
     */
    public function computeForSchool(bool $dryRun = false): array
    {
        if (!$this->ready) {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => 'Not initialised'];
        }
        $policy = $this->policy();
        if (!$policy['enabled']) {
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => $policy['reason']];
        }

        try {
            // Tenant + session scoped: schoolWhere() applies the schoolId
            // predicate, so no cross-tenant read is possible here.
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['session', '==', $this->session],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_late_fee_service::computeForSchool query failed: ' . $e->getMessage());
            return ['demands_updated' => 0, 'total_fine' => 0, 'message' => 'Demand query failed'];
        }

        $demands = [];
        foreach ((array) $rows as $r) {
            $id = (string) ($r['id'] ?? '');
            $d  = $r['data'] ?? [];
            if ($id === '' || !is_array($d)) continue;
            // Same normalisation demandsForStudent() applies, so the per-student
            // and school-wide paths see identical field shapes.
            $demands[$id] = Fee_firestore_txn::normalizeDemandDoc($d);
        }

        $out = $this->applyTo($demands, $policy, $this->cutoffDate(), $dryRun);
        $out['demands_scanned'] = count($demands);
        return $out;
    }

    /**
     * READ-ONLY diagnostic: how many demands are permanently exempt from late
     * fees because they carry no due date (i.e. were generated before Phase
     * 15.5). Performs NO writes. Backfilling them mutates production financial
     * records and therefore requires explicit authorization — this method only
     * measures the scope so that decision can be made on evidence.
     */
    public function auditMissingDueDates(): array
    {
        if (!$this->ready) return ['scanned' => 0, 'missing' => 0, 'samples' => []];
        try {
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['session', '==', $this->session],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_late_fee_service::auditMissingDueDates failed: ' . $e->getMessage());
            return ['scanned' => 0, 'missing' => 0, 'samples' => [], 'error' => 'query failed'];
        }

        $scanned = 0; $missing = 0; $reconstructable = 0; $samples = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? [];
            if (!is_array($d)) continue;
            $scanned++;
            $n  = Fee_firestore_txn::normalizeDemandDoc($d);
            $dd = trim((string) ($n['due_date'] ?? ''));
            if ($dd !== '') continue;
            $missing++;
            // A demand is deterministically reconstructable iff it carries a
            // well-formed periodKey — the same input the live generator uses.
            $pk = trim((string) ($n['period_key'] ?? $n['periodKey'] ?? ''));
            $ok = (bool) preg_match('/^\d{4}-\d{2}$/', $pk);
            if ($ok) $reconstructable++;
            if (count($samples) < 20) {
                $samples[] = [
                    'demandId'        => (string) ($r['id'] ?? ''),
                    'studentId'       => (string) ($n['student_id'] ?? $n['studentId'] ?? ''),
                    'periodKey'       => $pk,
                    'status'          => (string) ($n['status'] ?? ''),
                    'reconstructable' => $ok,
                ];
            }
        }

        return [
            'scanned'          => $scanned,
            'missing'          => $missing,
            'reconstructable'  => $reconstructable,
            'ambiguous'        => $missing - $reconstructable,
            'samples'          => $samples,
            'note'             => 'READ-ONLY. No demand was modified. Backfill requires explicit authorization.',
        ];
    }

    /**
     * DRY-RUN backfill plan for dateless historical demands.
     *
     * ⚠ THIS METHOD NEVER WRITES. There is deliberately no `$commit`
     * parameter and no write path anywhere in this class for historical
     * demands: changing the due date of an existing financial record decides
     * whether a family is charged a penalty, so it requires explicit
     * authorization and a separate, reviewed migration. This only produces
     * the exact proposal so that decision can be made on evidence.
     *
     * RECONSTRUCTION RULE (deterministic, never a guess):
     *     dueDate = periodKey + current getDueDay()
     * i.e. byte-identical to what the live generator would produce for the
     * same demand today. A demand qualifies ONLY if its periodKey matches
     * `YYYY-MM`. Anything else is reported as AMBIGUOUS and left alone.
     *
     * ⚠ KNOWN LIMITATION, stated rather than hidden: reconstruction applies
     * the school's CURRENT due-day. If a school changed its due day mid-year,
     * a rebuilt date may differ from what was operationally true when the
     * demand was raised. ZenX stores no history of that setting, so this
     * cannot be detected from data. That is precisely why this is a proposal
     * for a human to approve, not an automatic repair.
     */
    public function planDueDateBackfill(): array
    {
        if (!$this->ready) return ['plan' => [], 'reconstructable' => 0, 'ambiguous' => 0];
        try {
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['session', '==', $this->session],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_late_fee_service::planDueDateBackfill failed: ' . $e->getMessage());
            return ['plan' => [], 'reconstructable' => 0, 'ambiguous' => 0, 'error' => 'query failed'];
        }

        $day = $this->resolveDueDay();
        $plan = []; $ambiguous = []; $scanned = 0;

        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? [];
            if (!is_array($d)) continue;
            $scanned++;
            $n = Fee_firestore_txn::normalizeDemandDoc($d);
            if (trim((string) ($n['due_date'] ?? '')) !== '') continue;

            $pk  = trim((string) ($n['period_key'] ?? $n['periodKey'] ?? ''));
            $row = [
                'demandId'  => (string) ($r['id'] ?? ''),
                'studentId' => (string) ($n['student_id'] ?? $n['studentId'] ?? ''),
                'schoolId'  => $this->schoolId,
                'session'   => $this->session,
                'periodKey' => $pk,
                'status'    => (string) ($n['status'] ?? ''),
            ];
            if (preg_match('/^\d{4}-\d{2}$/', $pk)) {
                $row['currentDueDate']  = '(none)';
                $row['proposedDueDate'] = sprintf('%s-%02d', $pk, $day);
                $plan[] = $row;
            } else {
                $row['reason'] = 'periodKey missing or non-canonical — cannot reconstruct deterministically';
                $ambiguous[] = $row;
            }
        }

        return [
            'scanned'         => $scanned,
            'reconstructable' => count($plan),
            'ambiguous'       => count($ambiguous),
            'dueDayApplied'   => $day,
            'plan'            => $plan,
            'ambiguousRows'   => $ambiguous,
            'note'            => 'DRY-RUN ONLY. Nothing was written. No write path exists in this class.',
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
       FEES-D7-DUE — FORWARD DUE-DATE REPAIR (Option C, Phase 19Q)
       ──────────────────────────────────────────────────────────────────
       planDueDateBackfill() above reconstructs the date a demand WOULD have
       had. That is option A, and it is dangerous: stamping a months-overdue
       date on an unpaid demand makes it instantly finable, penalising a
       family for a window they never had the opportunity to meet.

       Option C instead gives the demand a FAIR NEW date from which today's
       fee process can operate. It does not pretend a historical due date
       existed; it makes a stranded demand actionable going forward.

       NO NEW MONEY-POLICY SETTING WAS INVENTED. The new date is the next
       future occurrence of the school's OWN configured due day
       (`getDueDay()`, 1–28) — the same value the live generator already uses
       to stamp dueDate. `late_fee_grace_days` was deliberately NOT reused:
       it means "days after due before a fine", and overloading it as a
       due-date offset would give one setting two unrelated meanings.

       ONLY the dueDate field is ever written. Not balance, not status, not
       paidAmount, not a receipt, not a journal.
    ══════════════════════════════════════════════════════════════════ */

    /** Statuses that may receive a forward due date. Everything else is closed. */
    /**
     * The ONLY demand statuses that may attract a new fine (FRZ-1).
     *
     * Deliberately a separate constant from REDATE_ELIGIBLE_STATUSES even
     * though the values match today: one governs a money mutation, the other
     * a due-date repair. Collapsing them would mean a future change to either
     * eligibility rule silently changed the other.
     */
    public const FINEABLE_STATUSES = ['unpaid', 'partial'];

    public const REDATE_ELIGIBLE_STATUSES = ['unpaid', 'partial'];

    /**
     * The next future occurrence of the school's configured due day.
     *
     * STRICTLY forward: if today IS the due day, the next month's is used, so
     * a re-dated demand can never be due "today" and become finable the same
     * evening the repair runs.
     */
    public function nextDueDate(?string $today = null): string
    {
        $day = $this->resolveDueDay();
        $t   = $today ?? date('Y-m-d');
        $ts  = strtotime($t);
        if ($ts === false) return '';

        $candidate = date('Y-m-', $ts) . sprintf('%02d', $day);
        if ($candidate > $t) return $candidate;

        $nextMonth = date('Y-m-01', strtotime(date('Y-m-01', $ts) . ' +1 month'));
        return date('Y-m-', strtotime($nextMonth)) . sprintf('%02d', $day);
    }

    /**
     * Classify every demand for the forward re-date repair.
     *
     * DRY-RUN by default. Writes ONLY when $commit === true, and even then
     * only the dueDate field via the canonical `updateDemand()`.
     *
     * @return array{eligible:array,ineligible:array,requiresReview:array,...}
     */
    public function forwardDueDateRepair(bool $commit = false, ?string $today = null): array
    {
        if (!$this->ready) {
            return ['error' => 'not_initialised', 'eligible' => [], 'ineligible' => [], 'requiresReview' => []];
        }
        try {
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['session', '==', $this->session],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_late_fee_service::forwardDueDateRepair query failed: ' . $e->getMessage());
            return ['error' => 'query_failed', 'eligible' => [], 'ineligible' => [], 'requiresReview' => []];
        }

        $newDue    = $this->nextDueDate($today);
        $eligible  = []; $ineligible = []; $review = [];
        $scanned   = 0; $written = 0; $writeFailed = 0;

        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? [];
            if (!is_array($d)) continue;
            $scanned++;

            $n        = Fee_firestore_txn::normalizeDemandDoc($d);
            $demandId = (string) ($r['id'] ?? '');
            $status   = strtolower(trim((string) ($n['status'] ?? '')));
            $due      = trim((string) ($n['due_date'] ?? ''));
            $paid     = (float) ($n['paid_amount'] ?? 0);
            $balance  = (float) ($n['balance'] ?? 0);

            $row = [
                'demandId'       => $demandId,
                'studentId'      => (string) ($n['student_id'] ?? $n['studentId'] ?? ''),
                'session'        => $this->session,
                'feeHead'        => (string) ($n['fee_head'] ?? $n['feeHead'] ?? ''),
                'netAmount'      => (float) ($n['net_amount'] ?? 0),
                'fineAmount'     => (float) ($n['fineAmount'] ?? $n['fine_amount'] ?? 0),
                'paidAmount'     => $paid,
                'balance'        => $balance,
                'status'         => $status,
                'currentDueDate' => $due !== '' ? $due : '(none)',
                'periodKey'      => (string) ($n['period_key'] ?? $n['periodKey'] ?? ''),
            ];

            // ── Ineligible: financially closed or already dated ──────────
            if ($due !== '') {
                $ineligible[] = $row + ['reason' => 'already has a due date'];
                continue;
            }
            if (!in_array($status, self::REDATE_ELIGIBLE_STATUSES, true)) {
                // paid / archived / reversed / cancelled / anything unknown.
                $ineligible[] = $row + ['reason' => "status '{$status}' is closed or unrecognised"];
                continue;
            }
            if ($balance <= 0.005) {
                // Nothing outstanding — re-dating would chase a family for
                // nothing.
                $ineligible[] = $row + ['reason' => 'no outstanding balance'];
                continue;
            }

            // ── Ambiguous: leave strictly alone ──────────────────────────
            if ($row['studentId'] === '' || $demandId === '') {
                $review[] = $row + ['reason' => 'REQUIRES_REVIEW — missing studentId or demandId'];
                continue;
            }
            if ($paid > 0 && $status === 'unpaid') {
                // The document contradicts itself. Never guess at money.
                $review[] = $row + ['reason' => 'REQUIRES_REVIEW — status says unpaid but a payment exists'];
                continue;
            }

            $row['proposedDueDate'] = $newDue;
            $row['fieldsChanged']   = ['dueDate'];
            $row['reason']          = 'outstanding, undated — given a forward due date so reminders can operate';

            if ($commit) {
                // ONLY dueDate. updateDemand() is a merge patch, so no other
                // field is touched.
                $ok = (bool) $this->fsTxn->updateDemand($demandId, ['due_date' => $newDue]);
                $row['written'] = $ok;
                $ok ? $written++ : $writeFailed++;
                if (!$ok) {
                    log_message('error', "forwardDueDateRepair write failed demand={$demandId} school={$this->schoolId}");
                }
            }
            $eligible[] = $row;
        }

        return [
            'mode'            => $commit ? 'COMMIT' : 'DRY-RUN',
            'schoolId'        => $this->schoolId,
            'session'         => $this->session,
            'today'           => $today ?? date('Y-m-d'),
            'dueDayApplied'   => $this->resolveDueDay(),
            'proposedDueDate' => $newDue,
            'scanned'         => $scanned,
            'eligibleCount'   => count($eligible),
            'ineligibleCount' => count($ineligible),
            'reviewCount'     => count($review),
            'written'         => $written,
            'writeFailed'     => $writeFailed,
            'eligible'        => $eligible,
            'ineligible'      => $ineligible,
            'requiresReview'  => $review,
            'note'            => $commit
                ? 'Only the dueDate field was written. No balance, status, payment, receipt or journal was touched.'
                : 'DRY-RUN. Nothing was written.',
        ];
    }

    /** The school's configured due day, clamped exactly as generation does. */
    public function resolveDueDay(): int
    {
        $day = 10;
        try {
            if (is_object($this->fsTxn) && method_exists($this->fsTxn, 'getDueDay')) {
                $day = (int) $this->fsTxn->getDueDay();
            }
        } catch (\Throwable $e) {
            log_message('error', 'Fee_late_fee_service::resolveDueDay failed, using 10: ' . $e->getMessage());
        }
        if ($day < 1 || $day > 28) $day = 10;
        return $day;
    }
}
