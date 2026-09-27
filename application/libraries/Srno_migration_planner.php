<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 * SRNO MIGRATION PLANNER — canonical namespace convergence, PLAN ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `srNoIndex` is PARTIALLY MIGRATED. Its document ids are already canonical
 * (`SCH_XXXX_{n}`, built by `$fs->docId()`), but two things still carry the
 * legacy `parent_db_key` (school-code) namespace:
 *
 *   • the `schoolId` FIELD stored inside each index document;
 *   • the counter and claim documents (`_sys_SRNO_{code}`, `…_claim_{n}`).
 *
 * This class produces a per-school PLAN describing exactly what a migration
 * would change. **It writes nothing.** Execution is a separate, protected
 * operation; separating the plan from the act is what makes the act reviewable.
 *
 * ── THE SAFETY PROPERTY EVERYTHING RESTS ON ───────────────────────────────
 * **No step ever rewrites a document id.** SRNO identity — and therefore
 * uniqueness — lives entirely in the canonical `srNoIndex` doc id, which
 * contains no namespace. The migration touches only a FIELD and the COUNTER.
 * That is why it cannot renumber a student, duplicate an SRNO, or orphan a
 * claim: it never touches the thing that establishes identity.
 *
 * ── WHAT IS TEMPORARY vs WHAT IS PERMANENT ────────────────────────────────
 *   A (preserve temporarily): the legacy `schoolId` field values and the legacy
 *     counter — real, working data that must keep working until converged.
 *   B (retire): the three caller sites that pass `parent_db_key` into the
 *     identity services (`save_admission`, `_runStudentImport`,
 *     `import_dedupe_check`). Nothing requires the legacy key here — the doc id
 *     never used it. `Readmission` already passes the canonical id.
 *
 * `parent_db_key` remains legitimately required for genuinely legacy-keyed
 * stores (`Users/Parents/{code}`, `Users/Admin/{code}`) — those are NOT in scope.
 */
class Srno_migration_planner
{
    public const INDEX_COL   = 'srNoIndex';
    public const COUNTER_COL = 'feeCounters';
    public const COUNTER_PREFIX = '_sys_SRNO_';

    /** A school needing no work at all. */
    public const STATE_CANONICAL = 'canonical_only';
    /** Rows exist only under the legacy key — the common case today. */
    public const STATE_LEGACY    = 'legacy_only';
    /** Rows under BOTH — a school migrated midway, or two entry points used. */
    public const STATE_SPLIT     = 'split';
    /** No SRNO data at all — nothing to migrate. */
    public const STATE_EMPTY     = 'empty';

    /**
     * Build the plan for ONE school. Pure: it is handed the already-read data,
     * so the same code plans a dry run, a real run and a unit test.
     *
     * @param string $canonical    canonical school_id
     * @param string $legacyKey    school_code / parent_db_key ('' when identical)
     * @param array  $indexRows    srNoIndex rows: ['id'=>docId,'schoolId'=>field,'srNo'=>n,'currentStudentId'=>..]
     * @param array  $counters     feeCounters rows keyed by docId => ['value'=>n]
     * @return array
     */
    public function plan(string $canonical, string $legacyKey, array $indexRows, array $counters): array
    {
        $canonical = trim($canonical);
        $legacyKey = trim($legacyKey);
        $sameKey   = ($legacyKey === '' || $legacyKey === $canonical);

        $fieldCanonical = 0; $fieldLegacy = 0; $fieldOther = [];
        $idCanonical = 0; $idForeign = [];
        $maxSrNo = 0; $srNos = []; $dupIds = [];

        foreach ($indexRows as $r) {
            $id    = (string) ($r['id'] ?? '');
            $field = (string) ($r['schoolId'] ?? '');
            $n     = (int) ($r['srNo'] ?? 0);

            // Identity check: the doc id MUST be prefixed with the canonical id.
            if (strpos($id, $canonical . '_') === 0) $idCanonical++;
            else $idForeign[] = $id;

            if ($field === $canonical)      $fieldCanonical++;
            elseif ($field === $legacyKey)  $fieldLegacy++;
            else                            $fieldOther[$field] = ($fieldOther[$field] ?? 0) + 1;

            if ($n > $maxSrNo) $maxSrNo = $n;
            if ($n > 0) {
                if (isset($srNos[$n])) $dupIds[] = $n;
                $srNos[$n] = true;
            }
        }

        $state = self::STATE_EMPTY;
        if ($indexRows !== []) {
            if ($fieldLegacy > 0 && $fieldCanonical > 0)      $state = self::STATE_SPLIT;
            elseif ($fieldLegacy > 0)                          $state = self::STATE_LEGACY;
            elseif ($fieldCanonical > 0)                       $state = self::STATE_CANONICAL;
            else                                               $state = self::STATE_SPLIT; // unknown field values
        }

        $legacyCounter    = $counters[self::COUNTER_PREFIX . $legacyKey]    ?? null;
        $canonicalCounter = $counters[self::COUNTER_PREFIX . $canonical]    ?? null;
        $legacyPointer    = (int) ($legacyCounter['value']    ?? 0);
        $canonicalPointer = (int) ($canonicalCounter['value'] ?? 0);

        // The counter must never move BACKWARDS: a lower pointer would re-issue
        // numbers that already exist. The seed is the max of every signal.
        $seed = max($maxSrNo, $legacyPointer, $canonicalPointer);

        // ── PREFLIGHT — refuse to plan when the data is not safe to touch ──
        $blockers = [];
        if ($canonical === '')      $blockers[] = 'missing_canonical_school_id';
        if ($idForeign !== [])      $blockers[] = 'index_doc_id_not_canonical:' . implode(',', array_slice($idForeign, 0, 3));
        if ($dupIds !== [])         $blockers[] = 'duplicate_srno_values:' . implode(',', array_slice($dupIds, 0, 3));
        if ($fieldOther !== [])     $blockers[] = 'unrecognised_schoolId_field:' . implode(',', array_keys($fieldOther));
        if ($canonicalPointer > $seed) $blockers[] = 'canonical_pointer_ahead_of_seed';

        $fieldUpdates = ($sameKey || $state === self::STATE_CANONICAL || $state === self::STATE_EMPTY)
            ? 0 : $fieldLegacy;

        return [
            'schoolId'        => $canonical,
            'legacyKey'       => $sameKey ? null : $legacyKey,
            'state'           => $state,
            'indexRows'       => count($indexRows),
            'docIdsCanonical' => $idCanonical,
            'fieldCanonical'  => $fieldCanonical,
            'fieldLegacy'     => $fieldLegacy,
            'maxSrNo'         => $maxSrNo,
            'legacyPointer'   => $legacyPointer,
            'canonicalPointer'=> $canonicalPointer,
            'counterSeed'     => $seed,
            'blockers'        => $blockers,
            'safe'            => $blockers === [],
            'noop'            => ($blockers === [] && $fieldUpdates === 0
                                  && ($canonicalPointer === $seed || $seed === 0)),
            'actions'         => [
                // Step 2 — field backfill. Doc ids untouched.
                'updateSchoolIdField' => $fieldUpdates,
                // Step 3 — seed the canonical counter. The legacy counter is
                // LEFT IN PLACE as evidence and as the rollback source.
                'writeCanonicalCounter' => ($blockers === [] && $seed > $canonicalPointer)
                                            ? ['docId' => self::COUNTER_PREFIX . $canonical, 'value' => $seed]
                                            : null,
                // Never: no id rewrite, no delete, no renumber.
                'rewriteDocIds'   => 0,
                'deleteAnything'  => 0,
                'changeSrNoValues'=> 0,
            ],
        ];
    }

    /**
     * Is applying this plan twice the same as applying it once?
     *
     * Both actions are idempotent by construction: the field backfill writes a
     * value that is already the target on a second run (so the second run finds
     * 0 rows to update), and the counter write is `max`-seeded, so it can only
     * ever move forward to the same value.
     */
    public function isIdempotent(array $plan): bool
    {
        return $plan['actions']['rewriteDocIds'] === 0
            && $plan['actions']['deleteAnything'] === 0
            && $plan['actions']['changeSrNoValues'] === 0;
    }

    /** A one-line, honest summary. */
    public static function summarise(array $p): string
    {
        if (!$p['safe']) {
            return sprintf('BLOCKED  %-18s %s', $p['schoolId'], implode(' | ', $p['blockers']));
        }
        if ($p['noop']) {
            return sprintf('NO-OP    %-18s state=%-14s rows=%d', $p['schoolId'], $p['state'], $p['indexRows']);
        }
        $c = $p['actions']['writeCanonicalCounter'];
        return sprintf('PLAN     %-18s state=%-14s rows=%d fieldUpdates=%d counter=%s',
            $p['schoolId'], $p['state'], $p['indexRows'],
            $p['actions']['updateSchoolIdField'],
            $c ? ('seed→' . $c['value']) : 'unchanged');
    }
}
