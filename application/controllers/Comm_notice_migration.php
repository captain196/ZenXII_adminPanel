<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Comm_notice_migration — P2.0.4 bounded historical notice migration.
 *
 * Migrates the 4 raw-format event-source notice docs (NOT00001-NOT00004)
 * to school-scoped doc IDs ({schoolFs}_NOT00001-NOT00004), preserving the
 * 5-pad numeric identity per D7.A operator decision.
 *
 * D7.A — in-place rename preserving original 5-pad identity:
 *   notices/NOT00001  →  notices/SCH_D94FE8F7AD_NOT00001
 *   notices/NOT00002  →  notices/SCH_D94FE8F7AD_NOT00002
 *   notices/NOT00003  →  notices/SCH_D94FE8F7AD_NOT00003
 *   notices/NOT00004  →  notices/SCH_D94FE8F7AD_NOT00004
 *
 * The `noticeId` field inside each doc remains "NOT00001" etc. — only
 * the doc-ID acquires school-prefix scoping for cross-tenant collision safety.
 *
 * D8.B — legacy `communicationCounters` collection preserved as inert
 * (no cleanup; matches D3.B inert-legacy precedent).
 *
 * Cross-reference forensic (P2.0.4 STAGE 2 via comm_notice_xref_probe):
 *   • circularReads: 0 matches (no read-receipt orphans)
 *   • pushRequests: 0 matches (event-keyed, not notice-keyed)
 *   • messageInboxes: 0 matches
 *   • notifications: 0 docs (collection empty)
 *   → Migration is fully isolated; no cross-collection coupling.
 *
 * Migration choreography per doc:
 *   1. Read source doc at notices/<rawId>
 *   2. createDocument at notices/{schoolFs}_<rawId> with preserved data
 *      + migration-provenance fields (migratedAt, migrationSource)
 *   3. deleteDocument at notices/<rawId>
 *   Order matters: create-new-FIRST ensures ≥1 copy exists at all times.
 *
 * Idempotency:
 *   • createDocument fails-if-exists → re-run skips already-migrated docs
 *   • deleteDocument is naturally idempotent (no-op if already deleted)
 *
 * Subcommands:
 *   dry_run   — list what would migrate
 *   apply     — execute migration
 *   verify    — cross-reference: confirm new docs present, old docs absent
 *
 * INVOCATION:
 *   php index.php comm_notice_migration dry_run
 *   php index.php comm_notice_migration apply
 *   php index.php comm_notice_migration verify
 *   Env: SCHOOL_ID=<schoolFs>
 */
class Comm_notice_migration extends CI_Controller
{
    private string $schoolFs = '';
    private const TARGET_RAW_IDS = ['NOT00001', 'NOT00002', 'NOT00003', 'NOT00004'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Comm_notice_migration is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');
        $this->schoolFs = (string) (getenv('SCHOOL_ID') ?: '');
        if ($this->schoolFs === '') {
            echo "ERROR: Set SCHOOL_ID environment variable.\n";
            exit(1);
        }
    }

    public function dry_run(): void { $this->_run(false); }
    public function apply(): void   { $this->_run(true);  }

    public function verify(): void
    {
        echo "=== Comm_notice_migration verify ===\n";
        echo "schoolFs={$this->schoolFs}\n\n";

        $found_new = 0;
        $found_old = 0;
        foreach (self::TARGET_RAW_IDS as $rawId) {
            $newId = $this->schoolFs . '_' . $rawId;
            $newDoc = null;
            $oldDoc = null;
            try { $newDoc = $this->firebase->firestoreGet('notices', $newId); } catch (\Throwable $e) {}
            try { $oldDoc = $this->firebase->firestoreGet('notices', $rawId); } catch (\Throwable $e) {}
            $newPresent = is_array($newDoc) && !empty($newDoc);
            $oldPresent = is_array($oldDoc) && !empty($oldDoc);
            $status = $newPresent && !$oldPresent ? '✅ migrated'
                : ($newPresent && $oldPresent ? '⚠ both present — incomplete migration'
                : (!$newPresent && $oldPresent ? '⚠ pre-migration'
                : '⚠ NEITHER PRESENT — data loss?'));
            if ($newPresent) $found_new++;
            if ($oldPresent) $found_old++;
            echo "  {$rawId} → {$newId}: {$status}\n";
        }
        echo "\n── Summary ──\n";
        echo "  new-format docs present:  {$found_new} / 4\n";
        echo "  old-format docs remaining: {$found_old} / 4\n";
        if ($found_new === 4 && $found_old === 0) {
            echo "  ✅ Migration verified — all 4 docs at canonical school-scoped doc IDs.\n";
        } elseif ($found_new === 0 && $found_old === 4) {
            echo "  ℹ Pre-migration state — apply not yet run.\n";
        } else {
            echo "  ⚠ Migration incomplete — partial state detected.\n";
        }
        echo "=== End verify ===\n";
    }

    // ── Internals ────────────────────────────────────────────────────

    private function _run(bool $apply): void
    {
        $mode = $apply ? 'APPLY' : 'DRY-RUN';
        echo "=== Comm_notice_migration {$mode} ===\n";
        echo "schoolFs={$this->schoolFs}\n";
        echo "target raw IDs: " . implode(', ', self::TARGET_RAW_IDS) . "\n";
        echo "migration: notices/<rawId>  →  notices/{$this->schoolFs}_<rawId>\n\n";

        $stats = [
            'src_found'        => 0,
            'tgt_already'      => 0,
            'created'          => 0,
            'deleted_old'      => 0,
            'would_create'     => 0,
            'would_delete_old' => 0,
            'failed'           => 0,
        ];

        foreach (self::TARGET_RAW_IDS as $rawId) {
            $newId = $this->schoolFs . '_' . $rawId;
            echo "── {$rawId} → {$newId} ──\n";

            // 1. Read source
            $src = null;
            try { $src = $this->firebase->firestoreGet('notices', $rawId); } catch (\Throwable $e) {}
            if (!is_array($src) || empty($src)) {
                echo "  [SKIP] source not found — likely already migrated in prior run\n";
                continue;
            }
            $stats['src_found']++;

            // 2. Check target already exists (idempotency)
            $tgt = null;
            try { $tgt = $this->firebase->firestoreGet('notices', $newId); } catch (\Throwable $e) {}
            if (is_array($tgt) && !empty($tgt)) {
                echo "  [TARGET-EXISTS] {$newId} already present — re-attempting old-doc delete only\n";
                $stats['tgt_already']++;
                if ($apply) {
                    try {
                        $ok = $this->firebase->firestoreDelete('notices', $rawId);
                        if ($ok) {
                            echo "  [OLD-DELETED] {$rawId}\n";
                            $stats['deleted_old']++;
                        } else {
                            echo "  [WARN] old-doc delete returned false: {$rawId}\n";
                        }
                    } catch (\Throwable $e) {
                        echo "  [FAIL] old-doc delete failed: " . $e->getMessage() . "\n";
                        $stats['failed']++;
                    }
                } else {
                    echo "  [DRY-RUN] would delete old doc {$rawId}\n";
                    $stats['would_delete_old']++;
                }
                continue;
            }

            // 3. Build new doc data: preserve source + add migration provenance.
            //    Strip Firestore server-managed fields (__updateTime).
            $newData = $src;
            unset($newData['__updateTime']);
            $newData['schoolId']        = $this->schoolFs;   // re-affirm tenant
            // noticeId field PRESERVED (D7.A) — no overwrite
            $newData['migratedAt']      = date('c');
            $newData['migrationSource'] = 'p2_0_4_in_place_rename';

            if (!$apply) {
                echo "  [DRY-RUN] would create {$newId} (preserves noticeId field='" . ($src['noticeId'] ?? '?') . "')\n";
                echo "  [DRY-RUN] would delete old {$rawId}\n";
                $stats['would_create']++;
                $stats['would_delete_old']++;
                continue;
            }

            // 4. Create new doc (atomic fails-if-exists)
            try {
                $ok = $this->firebase->firestoreCreate('notices', $newId, $newData);
                if (!$ok) {
                    echo "  [FAIL] createDocument returned false for {$newId} (possibly raced)\n";
                    $stats['failed']++;
                    continue;
                }
                echo "  [CREATED] notices/{$newId}\n";
                $stats['created']++;
            } catch (\Throwable $e) {
                echo "  [FAIL] create failed: " . $e->getMessage() . "\n";
                $stats['failed']++;
                continue;
            }

            // 5. Delete old doc — only after new doc confirmed
            try {
                $ok = $this->firebase->firestoreDelete('notices', $rawId);
                if ($ok) {
                    echo "  [OLD-DELETED] {$rawId}\n";
                    $stats['deleted_old']++;
                } else {
                    echo "  [WARN] old-doc delete returned false: {$rawId} — new doc exists, manual cleanup may be needed\n";
                }
            } catch (\Throwable $e) {
                echo "  [FAIL] old-doc delete failed: " . $e->getMessage() . "\n";
                $stats['failed']++;
            }
        }

        echo "\n── Summary ──\n";
        foreach ($stats as $k => $v) echo "  {$k}: {$v}\n";
        echo "=== End {$mode} ===\n";
    }
}
