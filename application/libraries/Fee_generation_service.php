<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_generation_service — the UNIFIED demand generator (Phase 1).
 *
 * Calls Fee_lifecycle::buildAdmissionDemandSpecs to get the chart-derived
 * spec set (identical to the legacy assignInitialFees build), then applies
 * any active studentConcessions on top:
 *   - fullExempt  → drop the demand entirely
 *   - percent     → netAmount = grossAmount * (1 - value/100), clamped >= 0
 *   - fixed       → netAmount = grossAmount - value, clamped >= 0
 * Multiple concessions on the same demand accumulate (fullExempt wins).
 *
 * **Byte-identical contract:** for any student with ZERO active concessions
 * the returned specs are EXACTLY the legacy specs (returned verbatim via
 * an early-return). The Phase-1 A/B verifier (Fee_generation_ab_verify)
 * asserts this on all 9 live students before any P2 flag flip.
 *
 * **What is NOT in scope for Phase 1/2:**
 *   - Service-enrollment-driven generation (Phase 3; reader exists but is
 *     not consumed here).
 *   - Replacing createModuleFee (Phase 3).
 *   - Touching manual/bulk/recalc generators (Phase 3).
 */
class Fee_generation_service
{
    /** @var Fee_lifecycle|null */
    private $feeLifecycle;

    /** @var Fee_concession_reader|null */
    private $concessionReader;

    /** @var Fee_service_enrollment_reader|null */
    private $enrollmentReader;

    private string $schoolId = '';
    private string $session  = '';

    public function init(
        $feeLifecycle,
        $concessionReader,
        $enrollmentReader,
        string $schoolId,
        string $session
    ): void {
        $this->feeLifecycle     = $feeLifecycle;
        $this->concessionReader = $concessionReader;
        $this->enrollmentReader = $enrollmentReader;
        $this->schoolId         = $schoolId;
        $this->session          = $session;
    }

    /**
     * Build admission/promotion demand specs for the student, with active
     * concessions applied. DOES NOT commit. Caller decides whether to write.
     *
     * Returns the same shape as Fee_lifecycle::buildAdmissionDemandSpecs:
     *   ['specs' => array<entry>, 'assigned' => array<head-name>, 'chartFound' => bool]
     * — but each spec's `data.netAmount` (and `data.balance` if unpaid) may
     * be reduced by an applicable concession, and fully-exempted heads are
     * dropped from the specs.
     */
    /**
     * @param bool $forPreview  SE-001 (2026-08-09) — read-only pricing simulation.
     *   Forwarded verbatim to buildAdmissionDemandSpecs(); it ONLY disables
     *   seeding duplicate-suppression from existing demands. Every line of
     *   pricing below — concession resolution, deduction, rounding, the
     *   fullExempt drop — runs identically in both modes, so a preview and a
     *   real generation can never disagree. Default false ⇒ existing callers
     *   are byte-identical.
     */
    public function generateDemandsForStudent(string $studentId, string $class, string $section, bool $forPreview = false): array
    {
        // 1) Get the chart-derived base specs (byte-identical to legacy).
        $built = $this->feeLifecycle->buildAdmissionDemandSpecs($studentId, $class, $section, $forPreview);

        // No chart → nothing to do (mirror legacy return).
        if (!($built['chartFound'] ?? false)) return $built;

        // 2) Read active concessions. Zero concessions → byte-identical to legacy.
        $concessions = $this->concessionReader
            ? $this->concessionReader->activeConcessionsForStudent($this->schoolId, $studentId, $this->session)
            : [];

        // Phase C (2026-08-08) — read-only A/B shadow. Runs AFTER the
        // authoritative legacy result is computed, never before, and its
        // return value is discarded. See _shadowCompare().
        if (empty($concessions)) {
            $this->_shadowCompare($studentId, $built, $built, 0);
            return $built;
        }

        // 3) Apply concession deductions per spec — ONE implementation,
        //    shared with the Phase C shadow path (see _applyDeductions).
        $result = $this->_applyDeductions(
            $built,
            $concessions,
            // ── DEC-009 (2026-08-18) — FORWARD THE REGISTRY REF ──────────────
            //
            // THE DEFECT THIS CLOSES. `$ref` was declared here and then DROPPED
            // — `appliesTo()` was called with five arguments. The comment that
            // stood here justified it by describing `$this->concessionReader`
            // as the LEGACY reader, whose records fold the target into
            // `targetFeeHeadId` and genuinely need no ref.
            //
            // But this property is not the legacy reader. It is whichever
            // reader Fee_lifecycle injected, and under USE_ENTITLEMENT_BILLING
            // that is Fee_entitlement_reader — whose scope IS the
            // `head:<feeHeadRef>` string and which resolves the ref in its
            // FIRST match tier. Dropping the argument left that tier
            // permanently unreachable in production, so a head-scoped
            // entitlement captured through the F-3 picker (which emits
            // `head:FH_…`) matched nothing and deducted zero. The demand was
            // billed at gross, the entitlement showed `active`, and no error
            // was raised anywhere — a silent overcharge.
            //
            // Proven live on 2026-08-18: STU0184 held an active 10% Tuition
            // entitlement, `appliesTo()` returned TRUE for all 12 periods when
            // called WITH the ref, and every demand still carried
            // discountAmount = 0.
            //
            // Why the shadow never caught it: _shadowCompare() builds its own
            // closure that DOES forward the ref, so shadow and production
            // differed by construction — and the shadow is off by default.
            //
            // The fix is to forward what _applyDeductions already supplies.
            // Both readers now take the identical 6-argument contract; the
            // legacy reader accepts the ref and ignores it, exactly as its
            // record shape requires. See DEC-009.
            fn(array $c, string $p, string $hId, string $h, string $cat, string $ref = ''): bool
                => $this->concessionReader->appliesTo($c, $p, $hId, $h, $cat, $ref),
            // ── PHASE 3B / B2 — ASK THE READER, NEVER THE FIELD ──────────────
            // These used to be `(string) ($c['type'] ?? '')` and
            // `(float) ($c['value'] ?? 0)` — the LEGACY field names, inlined.
            // An entitlement stores `valueType`/`value` and has no `type` field,
            // so the reader swap alone would have read the right rows through
            // the wrong fields and deducted ZERO from every entitlement.
            //
            // Both readers now expose the identical accessor pair, so this
            // method is source-agnostic: swapping the reader is the ONLY change
            // the cutover makes, and the arithmetic below is untouched.
            fn(array $c): string => $this->concessionReader->deductionType($c),
            fn(array $c): float  => $this->concessionReader->deductionValue($c)
        );

        $this->_shadowCompare($studentId, $built, $result, count($concessions));
        return $result;
    }

    /**
     * The concession deduction algorithm — the SINGLE implementation, used by
     * both the authoritative legacy path and the Phase C entitlement shadow.
     *
     * Extracted verbatim from the pre-Phase-C inline loop; the only change is
     * that the three source-shape-specific operations (does this rule apply /
     * what type is it / what value) are supplied as callables, so the same
     * arithmetic runs over `studentConcessions` and `studentEntitlements`
     * without a second copy of the math. Duplicating it would be the exact
     * "one action, two implementations" divergence this project exists to
     * close — and would make an A/B diff meaningless, since the two sides
     * could then differ for reasons unrelated to the data.
     *
     * @param array    $built   the chart-derived base specs
     * @param array    $rules   candidate concessions/entitlements
     * @param callable $applies fn(rule, periodKey, feeHeadId, feeHead, category): bool
     * @param callable $typeOf  fn(rule): string   percent|fixed|fullExempt
     * @param callable $valueOf fn(rule): float
     */
    /**
     * P5 step 1 — is the allocation ledger on for THIS tenant?
     *
     * Tenant-scoped through the same resolver every other rollout flag uses, so
     * the ledger can be granted and revoked per school without touching
     * USE_ENTITLEMENT_BILLING (already live for the UAT tenant).
     *
     * Fails CLOSED on any error: not emitting a ledger row is harmless, whereas
     * emitting one against a collection whose rules are not yet deployed is not.
     */
    private function _allocationLedgerEnabled(): bool
    {
        // ── LEDGER-RESOLVE-1 · THIS RESOLVER NEVER RETURNED TRUE ────────────
        //
        // It called `$CI->rolloutFlags->enabled($key, $schoolId, $global)`.
        // `Rollout_flag_service` has no `enabled()` method and no `__call()` —
        // the method is `isEnabled($key, $globalDefault)`, and it reads the
        // tenant from `init($firebase, $schoolId)`, which was never called
        // either. So the line raised `Call to undefined method`, the blanket
        // `catch (\Throwable)` swallowed it, and the function returned FALSE.
        //
        // Every time. For every tenant. Regardless of any rollout grant.
        //
        // The allocation ledger could therefore never activate through the
        // normal generation path, and a granted tenant behaved exactly like an
        // ungranted one. Nothing surfaced it because the failure and the
        // legitimate "flag is off" answer were the same value — the catch made
        // a programming error indistinguishable from configuration.
        //
        // Fixed here, and the two states are now separated: a genuine "off"
        // returns false quietly; a resolver that BLOWS UP still fails closed
        // (never emitting an unexplained ledger row) but says so loudly, so the
        // next occurrence is greppable rather than invisible.
        $CI = function_exists('get_instance') ? get_instance() : null;
        if ($CI === null || !isset($CI->config)) return false;

        try {
            $CI->config->load('fees_exemption_v2_flags', true);
            $global = (bool) $CI->config->item('USE_ALLOCATION_LEDGER', 'fees_exemption_v2_flags');
        } catch (\Throwable $e) {
            log_message('error', 'LEDGER_FLAG_UNRESOLVED reason=[config] school=['
                . $this->schoolId . ']: ' . $e->getMessage() . ' — ledger treated as OFF.');
            return false;
        }

        if (!file_exists(APPPATH . 'libraries/Rollout_flag_service.php')) {
            // No per-tenant override machinery: the global default is the whole
            // answer, which is a legitimate configuration, not a failure.
            return $this->_ledgerBackedByAuthority($global);
        }

        try {
            if (!isset($CI->rolloutFlags)) {
                $CI->load->library('Rollout_flag_service', null, 'rolloutFlags');
            }
            // init() binds the tenant AND resets the per-tenant cache when the
            // school changes — mandatory in a multi-tenant process that may
            // price for more than one school in a single run.
            return $this->_ledgerBackedByAuthority((bool) $CI->rolloutFlags
                ->init($this->firebaseHandle(), $this->schoolId)
                ->isEnabled('USE_ALLOCATION_LEDGER', $global));
        } catch (\Throwable $e) {
            log_message('error', 'LEDGER_FLAG_UNRESOLVED reason=[rollout_service] school=['
                . $this->schoolId . ']: ' . $e->getMessage()
                . ' — ledger treated as OFF (fail closed).');
            return false;
        }
    }

    /**
     * P5 step 1 — turn the per-entitlement contributions into ledger rows.
     *
     * Delegates to Entitlement_allocation_service, which owns identity,
     * validation, tenant/session isolation and over-allocation refusal. This
     * method only supplies the demand and the amounts; it decides nothing.
     *
     * A contribution the service REFUSES is dropped and logged rather than
     * written: a malformed ledger row is worse than a missing one, because the
     * projection check would then disagree with a demand that is actually correct.
     */
    /**
     * ── LEDGER-EMIT-1 · THE GRANT ALONE IS NOT ENOUGH ───────────────────────
     *
     * The ledger records WHICH GRANT caused a discount. That is only answerable
     * if the pricing authority in force is itself grant-backed. Under the legacy
     * concession authority the rules carry no entitlement identity, so the
     * ledger could not explain a single row it wrote.
     *
     * Before this gate, USE_ALLOCATION_LEDGER on top of the legacy authority
     * produced: the flag reading ON, 25 genuinely discounted demands, and 25
     * `missing_entitlement_id` refusals buried in the allocation service —
     * zero rows written, no line saying why, and an operator with every reason
     * to believe the ledger was working. Fail-closed, but silent about the one
     * thing that would have explained it.
     *
     * The question is asked of the reader that was ACTUALLY INJECTED, never of
     * a field name and never of a second flag literal that could drift out of
     * step with the authority switch in Fee_lifecycle.
     *
     * Only reached when the grant is genuinely on, so an ungranted legacy
     * tenant — the overwhelming majority — stays silent.
     */
    /** Schools already warned this process — the gate runs once per DEMAND. */
    private static $ledgerMismatchWarned = [];

    private function _ledgerBackedByAuthority(bool $granted): bool
    {
        if (!$granted) return false;

        $reader = $this->concessionReader;
        if (is_object($reader)
            && method_exists($reader, 'supportsAllocationLedger')
            && !$reader->supportsAllocationLedger()) {
            // One line per tenant, not one per demand: this is a single
            // CONFIGURATION answer, and repeating it 25 times per student
            // buries it in exactly the way the refusal spam it replaced did.
            if (!isset(self::$ledgerMismatchWarned[$this->schoolId])) {
                self::$ledgerMismatchWarned[$this->schoolId] = true;
                log_message('error',
                'LEDGER_AUTHORITY_MISMATCH school=[' . $this->schoolId . '] '
                . 'USE_ALLOCATION_LEDGER is granted but the pricing authority is '
                . get_class($reader) . ', which carries no entitlement identity. '
                // Deliberately does NOT name the authority flag. Phase 3B pins that
                // the authority is selected at exactly ONE boundary, in Fee_lifecycle,
                // and that this service never learns which flag chose its reader —
                // naming it here would be a second, drifting switch in prose.
                . 'Ledger treated as OFF for this tenant. The ledger needs a grant-backed '
                . 'pricing authority to have anything to record; see the authority '
                . 'boundary in Fee_lifecycle. This state means the ledger was granted '
                . 'by hand without it.');
            }
            return false;
        }
        return true;
    }

    private function _buildAllocations(array $entry, array $data, array $contributions): array
    {
        if ($contributions === []) return [];

        // ── DEFECT FIXED 2026-08-18 (found by a no-false-pass re-check) ──────
        //
        // This method was originally handed `$data` alone. `$data` is the DEMAND
        // BODY built by buildAdmissionDemandSpecs, and it does NOT carry
        // `demandId`, `schoolId` or `session`:
        //   • `demandId` lives on the ENTRY (`['op','demandId','data']`);
        //   • `schoolId` and `session` are stamped later, at the write boundary,
        //     by Fee_firestore_txn::_buildDemandDoc().
        //
        // So every row would have been refused three times over —
        // `missing_demand_id`, then `tenant_mismatch`, then `session_mismatch` —
        // and the ledger would have emitted NOTHING while the flag read as on.
        // Silent, fail-closed, and invisible: the same shape as DEC-008/009/010/011.
        //
        // The step-1 tests did not catch it because they asserted the SOURCE
        // STRUCTURE (that a call exists, that rows attach to the entry) rather
        // than proving a row survives the real spec shape. A test that cannot
        // express the production shape cannot fail on a production defect —
        // the same lesson as DEC-010 and the rollout incident.
        //
        // Composed here, explicitly: tenant/session from THIS service's bound
        // context (canonical `schoolId`, per DEC-008), the demand id from the
        // entry, and the demand body last-but-one so a real value always wins
        // over the defaults.
        $demand = array_merge(
            ['schoolId' => $this->schoolId, 'session' => $this->session],
            $data,
            ['demandId' => (string) ($entry['demandId'] ?? '')]
        );
        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI === null) return [];
            if (!isset($CI->allocationSvc)) {
                if (!file_exists(APPPATH . 'libraries/Entitlement_allocation_service.php')) return [];
                $CI->load->library('Entitlement_allocation_service', null, 'allocationSvc');
            }
            $svc = $CI->allocationSvc->init(
                null, null, $this->schoolId, $this->session,
                (string) ($CI->admin_id ?? 'system')
            );
            $rows = [];
            foreach ($contributions as $c) {
                $r = $svc->build($demand, is_array($c['rule'] ?? null) ? $c['rule'] : [],
                                 (float) ($c['amount'] ?? 0));
                if (!empty($r['ok'])) { $rows[] = $r['row']; continue; }
                log_message('error', 'ACC_ALLOCATION_REFUSED demand=['
                    . (string) ($demand['demandId'] ?? '?') . '] student=['
                    . (string) ($demand['studentId'] ?? '?') . '] reason=['
                    . (string) ($r['reason'] ?? '?') . '] — row NOT emitted.');
            }
            return $rows;
        } catch (\Throwable $e) {
            log_message('error', 'ACC_ALLOCATION_BUILD_FAILED student=['
                . (string) ($demand['studentId'] ?? '?') . ']: ' . $e->getMessage());
            return [];
        }
    }

    private function _applyDeductions(array $built, array $rules, callable $applies, callable $typeOf, callable $valueOf): array
    {
        $assigned = $built['assigned'];
        $newSpecs = [];
        foreach ($built['specs'] as $entry) {
            $data    = $entry['data'];
            $period  = (string) ($data['periodKey']  ?? '');
            $headId  = (string) ($data['feeHeadId']  ?? '');
            $feeHead = (string) ($data['feeHead']    ?? '');
            $cat     = (string) ($data['category']   ?? '');
            // P0: the demand-side school-scoped registry identity, when the
            // spec carries one. Empty on legacy specs — which is precisely
            // what leaves the reader's ref branch inert for them.
            $headRef = (string) ($data['feeHeadRef'] ?? '');
            $gross   = (float)  ($data['grossAmount'] ?? 0);

            $deduction  = 0.0;
            $fullExempt = false;
            // ── P5 STEP 1 · PER-ENTITLEMENT CONTRIBUTIONS ────────────────────
            //
            // The loop below already knows exactly what each grant contributed;
            // it simply threw that away into a running total, which is why
            // `discountAmount` is an unexplained number and why reversing one
            // grant of two is guesswork.
            //
            // Recording each contribution costs one array append and changes NO
            // arithmetic: `$deduction` accumulates exactly as before, so with
            // the ledger flag off the demand written is byte-identical.
            $contributions = [];
            foreach ($rules as $c) {
                if (!$applies($c, $period, $headId, $feeHead, $cat, $headRef)) continue;
                $type  = $typeOf($c);
                $value = $valueOf($c);
                if ($type === 'fullExempt') {
                    $fullExempt = true;
                    break;
                }
                if ($type === 'percent')    {
                    $part = $gross * ($value / 100.0);
                    $deduction += $part;
                    $contributions[] = ['rule' => $c, 'amount' => $part];
                    continue;
                }
                if ($type === 'fixed')      {
                    $deduction += $value;
                    $contributions[] = ['rule' => $c, 'amount' => (float) $value];
                    continue;
                }

                // ── PHASE 2 / F3 (2026-08-16) — FAIL LOUD, NOT SILENT ────────
                // Anything else is a rule this algorithm cannot apply. The
                // arithmetic is unchanged — it still contributes nothing, which
                // is the safe direction (bill the full amount rather than guess
                // a discount) — but it is no longer INVISIBLE.
                //
                // Silence is exactly what hid the `amountDue` hazard: an
                // approved concession that billed full price with no error
                // anywhere. Entitlement_service::VALUE_TYPES now refuses to
                // accept a type this loop cannot apply, so in a healthy system
                // this line is unreachable; it exists to make a future
                // vocabulary drift, or a hand-written document, greppable
                // instead of a silent overcharge.
                log_message('error', sprintf(
                    'ENTITLEMENT_VALUETYPE_UNSUPPORTED type=[%s] value=[%s] feeHead=[%s] period=[%s] '
                    . '— rule IGNORED, demand billed at gross. Fix the entitlement or implement the type.',
                    $type, (string) $value, $feeHead, $period
                ));
            }

            if ($fullExempt) {
                // Drop this demand entirely. Remove from assigned if it was the last
                // period for this head (we conservatively keep it in assigned to
                // mirror the gross intent; UI can show "fully exempt" separately).
                continue;
            }

            // ── STACK-1 · CONCESSIONS THAT EXCEED THE CHARGE ────────────────
            //
            // Grants STACK: every rule whose scope matches this demand adds to
            // `$deduction`. That is deliberate and correct — a sibling discount
            // and a scholarship are separate awards and both should reduce the
            // bill — and DEC-004 already closed the one case where stacking was
            // an accident (the same logical head reached through two id
            // formats, summed as if it were two grants).
            //
            // What was missing is a signal when the total goes PAST the charge.
            // A student holding an RTE/EWS full exemption AND a 20% sibling
            // discount produces a deduction of 120% of gross. The clamp below
            // floors the net at zero, so the family is billed correctly and
            // nothing is wrong with the money — which is exactly why this could
            // never be noticed.
            //
            // WHAT IS ACTUALLY LOST. `discountAmount` must equal
            // `gross - net` or the demand breaks the `gross - discount == net`
            // invariant every reader depends on. So the stored discount is the
            // CLAMPED figure, and the excess is not recorded anywhere. A school
            // cannot see that it granted more relief than it charged for, and
            // revoking one of two overlapping grants silently changes nothing —
            // the bill stays zero — which looks like the revocation failed.
            //
            // NOT REFUSED, deliberately, for the same reason as SVC-1: refusing
            // would block the demand entirely, and billing a genuinely exempt
            // family zero is the RIGHT answer. The proportionate response is to
            // make the condition visible, matching the F3 precedent in this
            // same loop — "FAIL LOUD, NOT SILENT" — where the arithmetic is
            // left alone and only the silence is removed.
            if ($deduction > $gross + 0.005) {
                log_message('error', sprintf(
                    'ACC_CONCESSION_EXCEEDS_CHARGE student=[%s] feeHead=[%s] period=[%s] '
                    . 'gross=[%.2f] granted=[%.2f] applied=[%.2f] excess=[%.2f] rules=[%d] '
                    . '— the concessions on this demand total MORE than the charge. The family '
                    . 'is billed 0, which is correct, but the demand can only record the '
                    . 'clamped discount, so the surplus grant is invisible and revoking one of '
                    . 'the overlapping grants will not change the bill.',
                    (string) ($data['studentId'] ?? ''), $feeHead, $period,
                    $gross, $deduction, $gross, $deduction - $gross, count($contributions)
                ));
            }
            $net = max(0.0, round($gross - $deduction, 2));
            if (abs($net - $gross) > 0.0001) {
                $data['netAmount']         = $net;
                $data['concessionApplied'] = round($gross - $net, 2);
                $data['discountAmount']    = round($gross - $net, 2); // Item 1: persist discount so gross − discount == net (reporting/audit/analytics)
                // Recompute balance only when this spec is for a fresh (unpaid) demand.
                // BUG-075 preservePayment may have omitted balance entirely; only set
                // balance when the legacy build had set it (i.e., status was emitted).
                if (array_key_exists('balance', $data) && array_key_exists('status', $data)) {
                    $data['balance'] = $net;
                }
            }

            $entry['data'] = $data;

            // ── P5 STEP 1 · ATTACH THE ALLOCATION ROWS ───────────────────────
            //
            // Deliberately attached to the ENTRY, never to `$entry['data']`:
            // `data` is what Fee_firestore_txn writes to the demand document, so
            // putting allocations there would silently smuggle a ledger array
            // into every demand. They travel beside the demand so the write path
            // can persist both together, and are simply ignored by anything that
            // does not know about them.
            //
            // Gated: with USE_ALLOCATION_LEDGER off this whole block is skipped
            // and the spec is byte-identical to before.
            if ($this->_allocationLedgerEnabled()) {
                $rows = $this->_buildAllocations($entry, $data, $contributions);
                if ($rows !== []) $entry['allocations'] = $rows;
            }

            $newSpecs[] = $entry;
        }

        return ['specs' => $newSpecs, 'assigned' => $assigned, 'chartFound' => true];
    }

    /**
     * Phase C — READ-ONLY A/B shadow comparison (baseline §Migration Strategy,
     * adversarial review M1: "the new generator runs and its output is diffed
     * against legacy WITHOUT persisting the 'B' side twice").
     *
     * Contract, and the reason this is safe to run inside the live generator:
     *   • It is invoked only AFTER the authoritative result is final.
     *   • Its return value is discarded — the caller already returned/holds
     *     the legacy result. It cannot change a single demand.
     *   • It never writes: no demand, no journal, no receipt, no entitlement.
     *   • Every failure is swallowed. A shadow that throws must never break
     *     real billing; that would invert the entire point of a shadow.
     *   • It is OFF by default (ENTITLEMENT_SHADOW_COMPARE), so it costs
     *     exactly zero extra Firestore reads until deliberately enabled for
     *     the one verification cycle.
     *
     * On divergence it logs a single greppable line, ENTITLEMENT_AB_DIVERGENCE,
     * carrying the student and the specific per-demand deltas — enough to
     * diagnose without dumping whole spec sets into the log.
     */
    private function _shadowCompare(string $studentId, array $built, array $authoritative, int $legacyRuleCount = 0): void
    {
        try {
            $CI = function_exists('get_instance') ? get_instance() : null;
            if ($CI === null || !isset($CI->config)) return;

            $CI->config->load('fees_exemption_v2_flags', true);
            // T2 (2026-08-18): tenant-scoped. The config value remains the
            // GLOBAL DEFAULT and is read exactly as before; the resolver only
            // answers whether THIS tenant overrides it, so with no grant on
            // file this branch behaves identically — still zero extra reads,
            // because Rollout_flag_service reads nothing until asked and the
            // controller has already bound + memoised it for this request.
            $flag     = 'ENTITLEMENT_SHADOW_COMPARE';   // one literal, one switch
            $shadowOn = (bool) $CI->config->item($flag, 'fees_exemption_v2_flags');
            if ($this->schoolId !== '' && isset($CI->rollout)) {
                $shadowOn = (bool) $CI->rollout
                    ->init($this->firebaseHandle(), $this->schoolId)
                    ->isEnabled($flag, $shadowOn);
            }
            if (!$shadowOn) {
                return;   // default OFF — zero additional reads
            }

            // FAILURE-ISOLATION HARDENING (Phase C verification, 2026-08-08).
            // CI_Loader::library() on a missing/renamed file calls show_error(),
            // which ends in exit() — NOT catchable by the try/catch around this
            // method. That is the one way a shadow could kill a real billing
            // request, so the file is checked before the loader is ever asked
            // for it. Fail silent-and-return: an unavailable shadow is a
            // non-event for billing.
            if (!isset($CI->entitlementReader)) {
                if (!file_exists(APPPATH . 'libraries/Fee_entitlement_reader.php')) {
                    log_message('error', 'ENTITLEMENT_AB_SHADOW_UNAVAILABLE — Fee_entitlement_reader.php missing; shadow skipped, billing unaffected.');
                    return;
                }
                $CI->load->library('Fee_entitlement_reader', null, 'entitlementReader');
            }
            $fb = $this->firebaseHandle();
            if ($fb === null) return;   // no handle → nothing to read; billing unaffected

            $CI->entitlementReader->init($fb);
            $reader = $CI->entitlementReader;

            $ents = $reader->activeConcessionsForStudent($this->schoolId, $studentId, $this->session);

            $shadow = empty($ents) ? $built : $this->_applyDeductions(
                $built,
                $ents,
                // P0: the entitlement reader prefers feeHeadRef when the spec
                // carries one, falling back to feeHeadId then name.
                fn(array $e, string $p, string $hId, string $h, string $cat, string $ref = ''): bool
                    => $reader->appliesTo($e, $p, $hId, $h, $cat, $ref),
                fn(array $e): string => $reader->deductionType($e),
                fn(array $e): float  => $reader->deductionValue($e)
            );

            $diffs = $this->_diffSpecs($authoritative, $shadow);
            if ($diffs === []) return;

            // Level MUST be 'error'. CI3's Log::$_levels is ERROR|DEBUG|INFO|ALL
            // — any other string (notably 'warning') fails the isset() guard in
            // write_log() and is DISCARDED, and log_threshold is 1 (errors only)
            // in production anyway. A shadow finding that never reaches the log
            // is worse than no shadow at all.
            log_message('error', sprintf(
                'ENTITLEMENT_AB_DIVERGENCE student=[%s] school=[%s] session=[%s] legacyRules=%d entitlementRules=%d diffs=%s',
                $studentId, $this->schoolId, $this->session,
                $legacyRuleCount,     // passed in — never re-queried (Gate 8: no redundant read)
                count($ents),
                json_encode(array_slice($diffs, 0, 20))
            ));
        } catch (\Throwable $e) {
            // A shadow must never affect the request it is shadowing.
            // 'error' level, not 'warning' — see the note above; a silently
            // dropped failure line would make a totally broken shadow invisible.
            log_message('error', 'ENTITLEMENT_AB_SHADOW_FAILED student=[' . $studentId . ']: ' . $e->getMessage());
        }
    }

    /**
     * Compare two spec sets by demandId → netAmount, plus set membership
     * (a fullExempt drop on one side and not the other is the highest-signal
     * divergence there is, so it is reported explicitly rather than as a
     * silent count mismatch).
     *
     * @return array<int, array{demandId:string, issue:string, legacy:mixed, shadow:mixed}>
     */
    private function _diffSpecs(array $a, array $b): array
    {
        $index = function (array $set): array {
            $out = [];
            foreach (($set['specs'] ?? []) as $entry) {
                $d  = $entry['data'] ?? [];
                $id = (string) ($entry['demandId'] ?? $d['demandId'] ?? '');
                if ($id === '') continue;
                $out[$id] = round((float) ($d['netAmount'] ?? 0), 2);
            }
            return $out;
        };
        $ia = $index($a);
        $ib = $index($b);

        $diffs = [];
        foreach ($ia as $id => $net) {
            if (!array_key_exists($id, $ib)) {
                $diffs[] = ['demandId' => $id, 'issue' => 'missing_in_shadow', 'legacy' => $net, 'shadow' => null];
            } elseif (abs($ib[$id] - $net) > 0.005) {
                $diffs[] = ['demandId' => $id, 'issue' => 'net_mismatch', 'legacy' => $net, 'shadow' => $ib[$id]];
            }
        }
        foreach ($ib as $id => $net) {
            if (!array_key_exists($id, $ia)) {
                $diffs[] = ['demandId' => $id, 'issue' => 'extra_in_shadow', 'legacy' => null, 'shadow' => $net];
            }
        }
        return $diffs;
    }

    /**
     * The shadow reader needs the same Firebase handle the legacy reader was
     * initialised with. Fee_generation_service is handed readers, not the
     * handle, so it is sourced from the controller instance — the same place
     * Fee_lifecycle::_routedBuildAdmissionSpecs() gets it.
     */
    private function firebaseHandle()
    {
        $CI = function_exists('get_instance') ? get_instance() : null;
        return ($CI !== null && isset($CI->firebase)) ? $CI->firebase : null;
    }
}
