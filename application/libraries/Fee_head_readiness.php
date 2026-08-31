<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FEE HEAD READINESS — IS THIS SCHOOL SAFE TO BILL FROM THE REGISTRY?
 * ══════════════════════════════════════════════════════════════════════════
 *
 * One question, one implementation: which fee heads does this school CHARGE
 * that it has not REGISTERED?
 *
 * ── WHY IT MATTERS ───────────────────────────────────────────────────────
 * Under USE_FEE_HEAD_REGISTRY, generation resolves every chart head against
 * feeHeadRegistry and fails CLOSED: one unresolvable head aborts that
 * student's entire billing. Measured on a clean fixture with the flag on and
 * the registry empty — ACC_FEEHEAD_UNRESOLVED, demands=0, and the admission
 * still reporting success. A new tenant's registry is empty by construction,
 * so this is the default outcome of enabling the flag early, not an edge case.
 *
 * ── WHY IT IS ITS OWN CLASS ──────────────────────────────────────────────
 * Two callers need the same answer for different reasons:
 *   • Fee_management::fee_head_readiness() — tells the operator what is still
 *     missing, BEFORE anyone is admitted unbillable.
 *   • the rollout grant surface — refuses the registry grant while anything is
 *     missing, so the dangerous ordering is impossible rather than merely
 *     discouraged.
 * Two copies of this comparison would eventually disagree, and the one that
 * drifted would either block a correct grant or wave through a broken one.
 *
 * It also keeps the grant surface free of iteration: T3 forbids loops
 * there precisely so no bulk or wildcard grant path can hide in it, and that
 * guard is worth more than the convenience of inlining this.
 *
 * READ-ONLY. Creates nothing, registers nothing, grants nothing.
 */
class Fee_head_readiness
{
    /**
     * Names this school charges but has not registered.
     *
     * @return array<string>|null [] when complete, a list of display names when
     *   incomplete, and NULL when the question could not be answered at all.
     *   The distinction matters: a caller that gates money on this must treat
     *   "could not ask" differently from "the answer is yes".
     */
    public function missingForSchool($firebase, string $schoolId): ?array
    {
        if ($firebase === null || trim($schoolId) === '') return null;

        // A handle that cannot be queried is not a degraded live client, it is a
        // different kind of object. Saying "unknown" lets the caller decide,
        // rather than reporting a clean bill of health it never established.
        if (!method_exists($firebase, 'firestoreQuery')) return null;

        if (!class_exists('Fee_head_resolver')) {
            require_once APPPATH . 'libraries/Fee_head_resolver.php';
        }

        try {
            $registered = $this->_registeredNames($firebase, $schoolId);
            $charted    = $this->_chartedNames($firebase, $schoolId);

            // Compared on the resolver's OWN normalisation, because that is what
            // generation will use. Raw string comparison would pass
            // "Tuition  Fee" against "Tuition Fee" and certify a gap that still
            // breaks billing.
            $missing = array_diff_key($charted, $registered);
            $out = array_values($missing);
            sort($out);
            return $out;
        } catch (\Throwable $e) {
            log_message('error', 'FEEHEAD_READINESS_FAILED school=[' . $schoolId . ']: '
                . $e->getMessage() . ' — reported as unknown, not as ready.');
            return null;
        }
    }

    /** nameNorm => true, for every ACTIVE registered head (claims excluded). */
    private function _registeredNames($firebase, string $schoolId): array
    {
        $rows = (array) $firebase->firestoreQuery(Fee_head_resolver::COL,
            [['schoolId', '==', $schoolId]], null, 'ASC', 500);

        $heads = array_filter(array_map(static function ($row) use ($schoolId) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
            if (!is_array($d)) return null;
            // Tenant check in the COMPARISON, not only in the query. The filter
            // above should already guarantee this, but "should" is how one
            // school's configuration ends up certifying another as ready to
            // bill. Cheap, and it makes the isolation testable.
            if ((string) ($d['schoolId'] ?? '') !== $schoolId) return null;
            // Name claims share the collection but are uniqueness tokens, not
            // heads; an archived head must never satisfy a NEW billing run.
            if (($d['kind'] ?? '') === Fee_head_resolver::KIND_NAME_CLAIM) return null;
            if (($d['status'] ?? 'active') !== 'active') return null;
            $n = Fee_head_resolver::nameNorm((string) ($d['name'] ?? ''));
            return $n !== '' ? $n : null;
        }, $rows));

        return array_fill_keys(array_values($heads), true);
    }

    /** nameNorm => display name, for every head appearing in the school's chart. */
    private function _chartedNames($firebase, string $schoolId): array
    {
        $rows = (array) $firebase->firestoreQuery('feeStructures',
            [['schoolId', '==', $schoolId]], null, 'ASC', 500);

        $names = [];
        $collect = static function ($r) use (&$names) {
            if (!is_array($r)) return;
            $nm = trim((string) ($r['name'] ?? ''));
            if ($nm === '') return;
            $names[Fee_head_resolver::nameNorm($nm)] = $nm;
        };

        array_map(static function ($row) use ($collect, $schoolId) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
            if (!is_array($d)) return;
            // Same reason as above: never let another tenant's chart contribute
            // a head to this tenant's readiness answer.
            if ((string) ($d['schoolId'] ?? '') !== $schoolId) return;
            // The chart shape varies by vintage: feeHeads on the titles doc,
            // heads/rows on class charts. All three are the same question.
            array_map(static function ($key) use ($d, $collect) {
                if (is_array($d[$key] ?? null)) array_map($collect, $d[$key]);
            }, ['feeHeads', 'heads', 'rows']);
        }, $rows);

        return $names;
    }
}
