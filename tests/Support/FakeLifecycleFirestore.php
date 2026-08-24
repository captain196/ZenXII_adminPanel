<?php
namespace Tests\Support;

/**
 * FakeLifecycleFirestore — an in-memory stand-in for the Firebase library,
 * exposing only the methods Institution_lifecycle and Lifecycle_resolver
 * actually call.
 *
 * It records every collection written to, which lets the data-integrity
 * tests assert the central safety property of the lifecycle layer: it must
 * NEVER write to a domain collection (students, staff, feeDemands,
 * accounting, transport…). The assertion is on the recording, not on the
 * code's good intentions.
 */
class FakeLifecycleFirestore
{
    /** @var array<string,array<string,array>> collection => docId => data */
    public $docs = [];
    /** @var string[] every collection written to, in order */
    public $writes = [];
    /** @var string[] ordered log of operations, for ordering assertions */
    public $ops = [];

    /** Force the next commitBatchStatus to report a CAS conflict. */
    public $forceCasConflict = false;
    /** Force createDocumentStatus to report a collision (replay). */
    public $forceCreateCollision = false;
    /** Force reads to throw, simulating an unreachable Firestore. */
    public $throwOnRead = false;

    public function seed(string $collection, string $docId, array $data): void
    {
        $this->docs[$collection][$docId] = $data;
    }

    public function firestoreGet(string $collection, string $docId)
    {
        if ($this->throwOnRead) {
            throw new \RuntimeException('simulated Firestore outage');
        }
        $this->ops[] = "get:{$collection}/{$docId}";
        return $this->docs[$collection][$docId] ?? null;
    }

    public function firestoreCreateStatus(string $collection, string $docId, array $data): array
    {
        if ($this->forceCreateCollision || isset($this->docs[$collection][$docId])) {
            $this->ops[] = "create-collision:{$collection}/{$docId}";
            return ['status' => 'COLLISION', 'code' => 409, 'message' => 'exists'];
        }
        $this->docs[$collection][$docId] = $data;
        $this->writes[] = $collection;
        $this->ops[]    = "create:{$collection}/{$docId}";
        return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
    }

    public function firestoreCommitBatchStatus(array $ops): array
    {
        if ($this->forceCasConflict) {
            $this->ops[] = 'commit-conflict';
            return ['status' => 'PRECONDITION_CONFLICT', 'code' => 409, 'message' => 'conflict'];
        }
        foreach ($ops as $op) {
            $col = (string) ($op['collection'] ?? '');
            $id  = (string) ($op['docId'] ?? '');
            $existing = $this->docs[$col][$id] ?? [];
            $this->docs[$col][$id] = array_replace($existing, (array) ($op['data'] ?? []));
            $this->writes[] = $col;
            $this->ops[]    = "commit:{$col}/{$id}";
        }
        return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
    }

    public function firestoreUpdate(string $collection, string $docId, array $patch): bool
    {
        $existing = $this->docs[$collection][$docId] ?? [];
        $this->docs[$collection][$docId] = array_replace($existing, $patch);
        $this->writes[] = $collection;
        $this->ops[]    = "update:{$collection}/{$docId}";
        return true;
    }

    public function firestoreQuery(string $collection, array $filters = [])
    {
        $out = [];
        foreach (($this->docs[$collection] ?? []) as $doc) {
            $match = true;
            foreach ($filters as $f) {
                [$field, $opr, $val] = $f;
                if ($opr === '=' && (($doc[$field] ?? null) !== $val)) { $match = false; break; }
            }
            if ($match) $out[] = $doc;
        }
        return $out;
    }

    /** Distinct collections this fake was asked to write to. */
    public function writtenCollections(): array
    {
        return array_values(array_unique($this->writes));
    }
}
