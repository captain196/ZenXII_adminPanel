<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_service_enrollment_reader — read-only access to studentServiceEnrollments
 * for the unified generator. Phase 1 of Fees Exemption v2.
 *
 * Defined for Phase-1 architectural completeness; NOT consumed by
 * Fee_generation_service in Phase 2 (services still go through the legacy
 * createModuleFee path until Phase 3 converges them). Methods are stable
 * so Phase 3 can light up without API churn.
 */
class Fee_service_enrollment_reader
{
    /** @var Firebase|null */
    private $firebase;

    public function init($firebase): void
    {
        $this->firebase = $firebase;
    }

    /**
     * All active enrollments for the student (status='active'). The caller
     * uses effective-window math to decide per-period applicability —
     * see windowApplies().
     */
    public function activeEnrollmentsForStudent(string $schoolId, string $studentId): array
    {
        if ($this->firebase === null) return [];
        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentServiceEnrollments', [
                ['schoolId',  '==', $schoolId],
                ['studentId', '==', $studentId],
                ['status',    '==', 'active'],
            ], null, 'ASC', 50);
        } catch (\Throwable $e) {
            log_message('error', "Fee_service_enrollment_reader: query failed for {$studentId}: " . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $out[] = $d;
        }
        return $out;
    }

    /**
     * Does the enrollment's effective window overlap the demand's period?
     * periodKey = 'YYYY-MM'. Same overlap semantics as concession appliesTo:
     * effectiveFrom <= last-of-month AND (effectiveTo null OR >= first-of-month).
     */
    public function windowApplies(array $enrollment, string $periodKey): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) return false;
        $year = (int) $m[1]; $month = (int) $m[2];
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last  = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', strtotime($first)));

        $effFrom = (string) ($enrollment['effectiveFrom'] ?? '');
        $effTo   = $enrollment['effectiveTo'] ?? null;
        if ($effFrom === '') return false;
        if (strcmp($effFrom, $last) > 0) return false;
        if ($effTo !== null && $effTo !== '' && strcmp((string) $effTo, $first) < 0) return false;
        return true;
    }
}
