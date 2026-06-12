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
    public function generateDemandsForStudent(string $studentId, string $class, string $section): array
    {
        // 1) Get the chart-derived base specs (byte-identical to legacy).
        $built = $this->feeLifecycle->buildAdmissionDemandSpecs($studentId, $class, $section);

        // No chart → nothing to do (mirror legacy return).
        if (!($built['chartFound'] ?? false)) return $built;

        // 2) Read active concessions. Zero concessions → byte-identical to legacy.
        $concessions = $this->concessionReader
            ? $this->concessionReader->activeConcessionsForStudent($this->schoolId, $studentId, $this->session)
            : [];
        if (empty($concessions)) return $built;

        // 3) Apply concession deductions per spec.
        $assigned = $built['assigned'];
        $newSpecs = [];
        foreach ($built['specs'] as $entry) {
            $data    = $entry['data'];
            $period  = (string) ($data['periodKey']  ?? '');
            $headId  = (string) ($data['feeHeadId']  ?? '');
            $feeHead = (string) ($data['feeHead']    ?? '');
            $cat     = (string) ($data['category']   ?? '');
            $gross   = (float)  ($data['grossAmount'] ?? 0);

            $deduction  = 0.0;
            $fullExempt = false;
            foreach ($concessions as $c) {
                if (!$this->concessionReader->appliesTo($c, $period, $headId, $feeHead, $cat)) continue;
                $type  = (string) ($c['type']  ?? '');
                $value = (float)  ($c['value'] ?? 0);
                if ($type === 'fullExempt') { $fullExempt = true; break; }
                if ($type === 'percent')    $deduction += $gross * ($value / 100.0);
                if ($type === 'fixed')      $deduction += $value;
            }

            if ($fullExempt) {
                // Drop this demand entirely. Remove from assigned if it was the last
                // period for this head (we conservatively keep it in assigned to
                // mirror the gross intent; UI can show "fully exempt" separately).
                continue;
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
            $newSpecs[] = $entry;
        }

        return ['specs' => $newSpecs, 'assigned' => $assigned, 'chartFound' => true];
    }
}
