<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * WHEN A CLASS-MATRIX SAVE MUST BE REFUSED OUTRIGHT.
 *
 * The matrix screen sends the WHOLE class in one request, and the save
 * reconciles: anything currently stored that the request does not mention is
 * deleted. That is the correct model — it is how removing a subject or a
 * teacher works — and it is also why a request that arrived incomplete is
 * indistinguishable from a deliberate mass removal.
 *
 * firestore.rules grants marks and homework writes straight off these rows, so
 * a row deleted here is a teacher losing access to a class. Deleting rows the
 * administrator never asked to remove is the failure that matters, and it is
 * silent: the save reports how many rows it wrote, not how many it removed.
 *
 * The decisions live here, apart from any I/O, so they can be tested by running
 * them rather than by reading the controller and hoping.
 */
class Subject_matrix_guard
{
    /**
     * Why this save must not proceed, or null when it may.
     *
     * @param array    $desired         docId => doc the request would store
     * @param string[] $currentSections section label of every row stored now
     *                                  ('' for a class-wide row)
     * @param string[] $sectionsIn      sections the request says the class has
     */
    public static function refusalReason(array $desired, array $currentSections,
                                         array $sectionsIn): ?string
    {
        $hasCurrent = !empty($currentSections);

        // Nothing at all in the request, while the class is populated. A whole
        // class is not cleared by accident, and this is what a dropped payload
        // looks like.
        if (empty($desired) && $hasCurrent) {
            return 'Nothing to save — the request contained no subjects. Reload the '
                 . 'page and try again.';
        }

        if (!$hasCurrent) {
            return null;                       // a new class cannot lose anything
        }

        // The subtler one. If the request carries no sections, every per-section
        // subject silently builds no rows — so the reconciliation removes every
        // per-section assignment in the class while the class-wide ones survive.
        // The request still looks non-empty, so a "was anything sent?" check
        // does not catch it.
        //
        // This is exactly what a failed sections read upstream produces: the
        // screen draws a grid with no section columns, and saving it revokes
        // every section teacher in the class.
        $hasPerSectionRows = false;
        foreach ($currentSections as $sec) {
            if (trim((string) $sec) !== '') { $hasPerSectionRows = true; break; }
        }

        if ($hasPerSectionRows && empty($sectionsIn)) {
            return 'This class has per-section subject assignments, but the request '
                 . 'listed no sections — saving it would remove every section '
                 . 'teacher. Nothing was changed. Reload the page and try again.';
        }

        return null;
    }

    /**
     * Sections this save can change that it holds no token for.
     *
     * The tokens are taken before the current rows are read, so that a save
     * landing in between is caught. A section that had NO serialisation
     * document at that moment has no token to conflict on — and if its rows
     * then turn up in the current set, this save would revoke them with
     * nothing guarding the decision.
     *
     * That is the one revocation the interlock cannot see, so it is named
     * here and the caller refuses rather than proceeding.
     *
     * @param string[] $currentSections section of every row stored now
     * @param callable $ledgerIdFor     fn(string $section): string
     * @param array    $ledgerTokens    ledgerDocId => updateTime
     * @return string[] the section labels left unguarded
     */
    public static function unguardedSections(array $currentSections,
                                             callable $ledgerIdFor,
                                             array $ledgerTokens): array
    {
        $out = [];
        foreach (array_unique($currentSections) as $sec) {
            $lid = $ledgerIdFor((string) $sec);
            if (!array_key_exists($lid, $ledgerTokens)) {
                $out[] = (string) $sec;
            }
        }
        return $out;
    }

    /**
     * Every write this save performs, as one batch.
     *
     * Revocations, grants and the serialisation points travel together, so a
     * class cannot end up with a teacher removed but their replacement not yet
     * in place — and so the whole thing is refused if somebody else has
     * touched any of these sections since the tokens were read.
     *
     * One token per SECTION rather than one for the class: two administrators
     * working on different sections are not in conflict, and a per-section
     * save through Subject_assignment_service takes the very same document, so
     * the two write surfaces interlock instead of talking past each other.
     *
     * @param string[] $stale        docIds to remove
     * @param array    $desired      docId => doc to write
     * @param array    $ledgerTokens ledgerDocId => updateTime ('' = expect absent)
     * @param array    $ledgerData   the body to stamp on each ledger document
     */
    public static function commitOps(array $stale, array $desired,
                                     array $ledgerTokens, array $ledgerData): array
    {
        $ops = [];

        foreach ($stale as $rid) {
            $ops[] = ['op' => 'delete', 'collection' => 'subjectAssignments',
                      'docId' => (string) $rid];
        }
        foreach ($desired as $id => $doc) {
            $ops[] = ['op' => 'set', 'collection' => 'subjectAssignments',
                      'docId' => (string) $id, 'data' => $doc, 'merge' => true];
        }
        foreach ($ledgerTokens as $lid => $tok) {
            $ops[] = [
                'op'         => 'set',
                'collection' => 'subjectAssignmentSections',
                'docId'      => (string) $lid,
                'data'       => $ledgerData,
                'merge'      => true,
                'precondition' => ((string) $tok) !== ''
                    ? ['updateTime' => (string) $tok]
                    : ['exists' => false],
            ];
        }

        return $ops;
    }

    /**
     * The rows this save removes: everything stored that the request no longer
     * mentions.
     *
     * @param string[] $currentIds
     * @param array    $desired    docId => doc
     * @return string[]
     */
    public static function staleIds(array $currentIds, array $desired): array
    {
        $out = [];
        foreach ($currentIds as $id) {
            if (!isset($desired[$id])) { $out[] = (string) $id; }
        }
        return $out;
    }
}
