<?php
namespace Tests\Support;

/**
 * Minimal in-memory double for the two Firestore surfaces Sr_no_service
 * uses ($fs = Firestore_service-shaped, $firebase = Firebase-shaped),
 * exercising the REAL claim/relink logic in Sr_no_service.php against
 * real control flow — not a source-text grep.
 *
 * MULTI-TENANT MODEL: real Firestore is ONE database shared by every
 * school; tenant isolation comes entirely from schoolId-prefixed doc ids,
 * never from separate storage. This fake mirrors that exactly — pass a
 * SHARED FakeSrNoBackingStore to multiple instances (one per schoolId) to
 * test cross-tenant behavior against the SAME underlying store, the same
 * way two schools' requests hit the same real Firestore project.
 *
 * Mirrors the REAL id-prefixing contract exactly (a real property of the
 * production classes, confirmed by reading them, not a simplification):
 * get()/firestoreCreate()/commitBatch() take the doc id AS GIVEN by the
 * caller (Sr_no_service always passes the already-prefixed id to these
 * three); casAppendBounded() takes a BARE entityId and prefixes it
 * internally via docId() — matching Firestore_service's own signature.
 *
 *   - firestoreCreate(): true on create, false if the doc already exists
 *     (the exact create-if-not-exists collision signal both classes use).
 *   - commitBatch(): applies every op only if every op's precondition.
 *     updateTime (when present) still matches the doc's current version —
 *     otherwise the WHOLE batch is rejected, mirroring Firestore's real
 *     atomic-batch-with-precondition semantics.
 *   - get(): returns the doc including a synthetic __updateTime that
 *     changes on every write, so a caller holding a stale read reliably
 *     loses a race — this is what makes the CAS tests meaningful.
 */
class FakeSrNoFirestore
{
    private FakeSrNoBackingStore $backing;
    private string $schoolId;

    public function __construct(string $schoolId = 'SCH_TEST', ?FakeSrNoBackingStore $backing = null)
    {
        $this->schoolId = $schoolId;
        $this->backing = $backing ?? new FakeSrNoBackingStore();
    }

    /** So a test can hand the SAME backing store to a second school's fake. */
    public function backing(): FakeSrNoBackingStore
    {
        return $this->backing;
    }

    /** Mirrors Firestore_service::docId() exactly — {schoolId}_{entityId}. */
    public function docId(string $entityId): string
    {
        return "{$this->schoolId}_{$entityId}";
    }

    public function get(string $collection, string $docId): ?array
    {
        return $this->backing->store[$collection][$docId] ?? null;
    }

    /** Mirrors Firestore_service::casAppendBounded — entityId is BARE, prefixed here. */
    public function casAppendBounded(string $collection, string $entityId, string $field, array $entry, int $cap): array
    {
        $docId = $this->docId($entityId);
        $doc = $this->backing->store[$collection][$docId] ?? null;
        if ($doc === null) return ['ok' => false];
        $arr = $doc[$field] ?? [];
        $arr[] = $entry;
        if (count($arr) > $cap) $arr = array_slice($arr, -$cap);
        $this->backing->store[$collection][$docId][$field] = $arr;
        return ['ok' => true];
    }

    public function commitBatch(array $ops): bool
    {
        foreach ($ops as $op) {
            if (($op['op'] ?? '') !== 'update') continue;
            $pre = $op['precondition'] ?? null;
            if ($pre === null) continue;
            $doc = $this->backing->store[$op['collection']][$op['docId']] ?? null;
            $liveVersion = is_array($doc) ? ($doc['__updateTime'] ?? '') : '';
            if ($liveVersion !== $pre['updateTime']) return false; // CAS conflict — whole batch rejected
        }
        foreach ($ops as $op) {
            $coll = $op['collection']; $id = $op['docId'];
            $existing = $this->backing->store[$coll][$id] ?? [];
            $merged = array_merge($existing, $op['data']);
            $this->backing->version++;
            $merged['__updateTime'] = (string) $this->backing->version;
            $this->backing->store[$coll][$id] = $merged;
        }
        return true;
    }

    /** firestoreCreate takes the FULL (already-prefixed) doc id, as Sr_no_service::claim() passes it. */
    public function firestoreCreate(string $collection, string $docId, array $data): bool
    {
        if (isset($this->backing->store[$collection][$docId])) return false; // already exists — the collision signal
        $this->backing->version++;
        $data['__updateTime'] = (string) $this->backing->version;
        $this->backing->store[$collection][$docId] = $data;
        return true;
    }

    // ── Firebase.php-shaped surface (used by Sr_no_service::allocateNext()
    //    for the school-scoped counter — a DIFFERENT primitive than the
    //    srNoIndex/apaarIndex ownership claims above, hence the separate,
    //    Firebase.php-named methods rather than reusing get()/docId(). ──

    public function firestoreGet(string $collection, string $docId): ?array
    {
        return $this->backing->store[$collection][$docId] ?? null;
    }

    public function firestoreSet(string $collection, string $docId, array $data): bool
    {
        $this->backing->version++;
        $data['__updateTime'] = (string) $this->backing->version;
        $this->backing->store[$collection][$docId] = $data;
        return true;
    }

    public function firestoreDelete(string $collection, string $docId): bool
    {
        unset($this->backing->store[$collection][$docId]);
        return true;
    }

    /** Minimal shape: equality-only conditions, no orderBy/limit needed by
     *  any current caller (Sr_no_service::_findMaxExistingSrNoForSchool()
     *  fetches all matches and reduces in PHP, exactly like the real
     *  Firebase::firestoreQuery() call it makes). */
    public function firestoreQuery(string $collection, array $conditions = [], ?string $orderBy = null, string $direction = 'ASC', ?int $limit = null, $startAfter = null): array
    {
        $rows = [];
        foreach ($this->backing->store[$collection] ?? [] as $id => $data) {
            $match = true;
            foreach ($conditions as $c) {
                [$field, $op, $val] = $c;
                if ($op !== '==') { $match = false; break; }
                if (($data[$field] ?? null) !== $val) { $match = false; break; }
            }
            if ($match) $rows[] = ['id' => $id, 'data' => $data];
        }
        return $rows;
    }
}
