<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_concession_reader — read-only access to studentConcessions for the
 * unified generator. Phase 1 of Fees Exemption v2. NO writes.
 *
 * Source of truth: studentConcessions docs written by Fee_concessions
 * (Phase 0). Until P2 cutover, this reader is exercised ONLY by the A/B
 * verification probe — production generation paths still use legacy.
 */
class Fee_concession_reader
{
    /** @var Firebase|null */
    private $firebase;

    public function init($firebase): void
    {
        $this->firebase = $firebase;
    }

    /**
     * All active studentConcessions for (schoolId, studentId, session).
     * Returns docs where status='active' AND (session matches OR session=null).
     * Effective-window overlap is checked per demand via appliesTo() — this
     * method returns the candidate set, not the per-demand decision.
     */
    public function activeConcessionsForStudent(string $schoolId, string $studentId, string $session): array
    {
        if ($this->firebase === null) return [];

        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentConcessions', [
                ['schoolId',  '==', $schoolId],
                ['studentId', '==', $studentId],
                ['status',    '==', 'active'],
            ], null, 'ASC', 200);
        } catch (\Throwable $e) {
            log_message('error', "Fee_concession_reader: query failed for {$studentId}: " . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $cSess = $d['session'] ?? null;
            // session=null → applies to all sessions; otherwise must match.
            if ($cSess !== null && (string) $cSess !== $session) continue;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * Does this concession apply to the demand identified by (periodKey,
     * feeHeadId, category)? periodKey is 'YYYY-MM' (April→year-04 etc.).
     *
     * Match rules (all must hold):
     *  1. Scope matches: scope='all', OR scope='head' AND targetFeeHeadId matches,
     *     OR scope='category' AND targetCategory matches the demand's category.
     *  2. Effective-window overlap: effectiveFrom <= last-of-period-month,
     *     AND (effectiveTo is null OR effectiveTo >= first-of-period-month).
     */
    public function appliesTo(array $concession, string $periodKey, string $feeHeadId, string $feeHead, string $category = ''): bool
    {
        // Scope
        $scope = (string) ($concession['scope'] ?? '');
        if ($scope === 'head') {
            $target = (string) ($concession['targetFeeHeadId'] ?? '');
            if ($target === '' || $target !== $feeHeadId) {
                // Fallback: some chart heads may not have stable IDs — accept name match too.
                $targetName = (string) ($concession['targetFeeHead'] ?? '');
                if ($targetName === '' || $targetName !== $feeHead) return false;
            }
        } elseif ($scope === 'category') {
            $target = (string) ($concession['targetCategory'] ?? '');
            if ($target === '' || strcasecmp($target, $category) !== 0) return false;
        } elseif ($scope !== 'all') {
            return false;
        }

        // Effective window vs the demand's month
        if (!preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) return false;
        $year  = (int) $m[1];
        $month = (int) $m[2];
        $firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $lastOfMonth  = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', strtotime($firstOfMonth)));

        $effFrom = (string) ($concession['effectiveFrom'] ?? '');
        $effTo   = $concession['effectiveTo'] ?? null;
        if ($effFrom === '') return false;
        if (strcmp($effFrom, $lastOfMonth) > 0) return false;
        if ($effTo !== null && $effTo !== '' && strcmp((string) $effTo, $firstOfMonth) < 0) return false;

        return true;
    }
}
