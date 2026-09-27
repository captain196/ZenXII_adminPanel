<?php
declare(strict_types=1);

/**
 * BATCH COMMIT WITH REAL PRECONDITION SEMANTICS, FOR TEST DOUBLES.
 *
 * Firestore's `:commit` evaluates every `currentDocument` precondition
 * server-side and rolls the whole batch back if any fails, and it reports
 * success as the literal `SUCCESS` (Firestore_rest_client::COMMIT_SUCCESS).
 *
 * Doubles in this repository have got both of those wrong, twice, and each time
 * the mistake certified a defect instead of catching it:
 *
 *   • one returned `OK`, which no client ever returns. Production had the same
 *     literal wrong, so the double and the code agreed — and every successful
 *     publish was reported to the administrator as a failure. Only a run
 *     against the real client showed it.
 *   • one applied the operations it could and ignored preconditions entirely,
 *     which makes a compare-and-set that does nothing look exactly like one
 *     that works.
 *
 * So the behaviour lives here once, and the doubles share it. A double is only
 * useful while it is indistinguishable from the thing it replaces on the
 * dimension under test; for anything CAS-shaped, that dimension is precisely
 * this.
 *
 * ── SCOPE ───────────────────────────────────────────────────────────────
 *
 * Document existence is tracked from the writes this trait has applied. That
 * is exact for documents created through the batch path — which is what
 * `['exists' => false]` is used for — and it does not attempt to see documents
 * a double seeded directly into its own arrays. A double that needs
 * `exists`-preconditioned writes against pre-seeded documents must say so by
 * calling seedExisting().
 */
trait FirestoreBatchPreconditionTrait
{
    /** "collection\0docId" => server-assigned version token. */
    public array $batchUpdateTimes = [];
    private int  $batchTick = 0;

    /** Fires inside the commit, between the precondition check and the apply. */
    public $beforeCommit = null;

    private function batchKey(string $c, string $d): string { return $c . "\0" . $d; }

    /** Tell the trait a document already exists (and give it a token). */
    public function seedExisting(string $collection, string $docId): void
    {
        $this->batchUpdateTimes[$this->batchKey($collection, $docId)]
            = 'T' . (++$this->batchTick);
    }

    /** The token a read should surface as `__updateTime`, or '' when absent. */
    public function tokenFor(string $collection, string $docId): string
    {
        return (string) ($this->batchUpdateTimes[$this->batchKey($collection, $docId)] ?? '');
    }

    private function batchPreconditionsHold(array $ops): bool
    {
        foreach ($ops as $op) {
            $pre = $op['precondition'] ?? null;
            if (!is_array($pre)) continue;

            $c = (string) ($op['collection'] ?? '');
            $d = (string) ($op['docId'] ?? '');
            $exists = isset($this->batchUpdateTimes[$this->batchKey($c, $d)]);

            if (array_key_exists('exists', $pre) && $pre['exists'] !== $exists) return false;
            if (isset($pre['updateTime'])
                && $this->tokenFor($c, $d) !== $pre['updateTime']) return false;
        }
        return true;
    }

    public function firestoreCommitBatchStatus($ops)
    {
        if (!is_array($ops)) return ['status' => 'FAILURE'];

        if (!$this->batchPreconditionsHold($ops)) {
            return ['status' => 'PRECONDITION_CONFLICT'];
        }

        if (is_callable($this->beforeCommit)) {
            $cb = $this->beforeCommit;
            $this->beforeCommit = null;              // once
            $cb($this);
            // Someone may have written inside the window. Re-check EVERY
            // precondition — real Firestore evaluates them all at commit, and a
            // re-check of only the updateTime ones let a first-publish race
            // through while reporting the guard as working.
            if (!$this->batchPreconditionsHold($ops)) {
                return ['status' => 'PRECONDITION_CONFLICT'];
            }
        }

        foreach ($ops as $op) {
            $c = (string) ($op['collection'] ?? '');
            $d = (string) ($op['docId'] ?? '');
            $this->firestoreSet($c, $d, is_array($op['data'] ?? null) ? $op['data'] : [],
                                (bool) ($op['merge'] ?? false));
            $this->batchUpdateTimes[$this->batchKey($c, $d)] = 'T' . (++$this->batchTick);
        }

        // The literal the real client returns. Not 'OK'.
        return ['status' => 'SUCCESS'];
    }

    /**
     * The publish concurrency token, read back the way Firestore returns it.
     *
     * Doubles delegate their `timetablePublishGuards` reads here so the token
     * they hand back is the one their own commit assigned. Returning null
     * unconditionally (as a collection-blind double does) makes every publish
     * look like the first one, and the second then fails its `exists: false`
     * precondition for a reason that has nothing to do with concurrency.
     */
    public function publishGuardDoc(string $docId, array $store): ?array
    {
        $doc = $store[$docId] ?? null;
        if (!is_array($doc)) return null;
        $t = $this->tokenFor('timetablePublishGuards', $docId);
        return $t === '' ? $doc : array_merge($doc, ['__updateTime' => $t]);
    }
}
