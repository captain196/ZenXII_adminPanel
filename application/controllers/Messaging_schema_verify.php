<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Messaging_schema_verify — Tier 1.3 canonical messaging schema verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical schema per
 * [[messaging_canonical_schema]] (Phase 1-6 closure):
 *   1. Field names in camelCase (no snake_case drift)
 *   2. Role tokens in lowercase (in role/senderRole fields + messageInboxes doc IDs)
 *   3. Collection presence (conversations / messages / messageInboxes /
 *      circulars / notices / messageTemplates / alertTriggers /
 *      messageQueue / deliveryLogs)
 *
 * Sample-based — for each collection, fetches up to 50 docs and audits
 * every field name + role-token value. Reports drift signals per the
 * locked operating contract format.
 *
 * INVOCATION:
 *   php index.php messaging_schema_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Messaging_schema_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    /** Messaging collections per Phase 5/6 canonical (camelCase only). */
    private const COLLECTIONS = [
        'conversations',
        'messages',
        'messageInboxes',
        'circulars',
        'notices',
        'messageTemplates',
        'alertTriggers',
        'messageQueue',
        'deliveryLogs',
        'notifications',   // legacy / alternate
    ];

    /** Fields whose values are expected to be lowercase role tokens. */
    private const ROLE_VALUED_FIELDS = [
        'role', 'senderRole', 'otherPartyRole', 'recipientRole', 'inboxRole',
    ];

    /** Expected canonical role tokens (case-sensitive lowercase). */
    private const CANONICAL_ROLE_TOKENS = [
        'admin', 'teacher', 'parent', 'hr', 'student', 'school', 'system',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Messaging_schema_verify is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /** CLI: php index.php messaging_schema_verify verify */
    public function verify(): void
    {
        echo "=== Tier 1.3 Messaging Canonical Schema Verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n";

        $globalSnakeCaseFields = [];   // field-name => count
        $globalUpperRoleValues = [];   // value => count
        $globalUpperRoleDocIds = [];   // doc-id => bool
        $collectionStatus = [];        // per-collection: count + drift flags

        foreach (self::COLLECTIONS as $col) {
            echo "\n--- {$col} ---\n";
            try {
                $rows = $this->firebase->firestoreQuery($col, [
                    ['schoolId', '==', $this->schoolFs],
                ], null, 'ASC', 50);
            } catch (\Throwable $e) {
                echo "  collection unavailable: " . $e->getMessage() . "\n";
                $collectionStatus[$col] = ['count' => -1, 'snake_count' => 0, 'role_drift' => 0, 'docid_drift' => 0];
                continue;
            }

            if (count($rows) === 0) {
                echo "  empty (0 docs in scope)\n";
                $collectionStatus[$col] = ['count' => 0, 'snake_count' => 0, 'role_drift' => 0, 'docid_drift' => 0];
                continue;
            }

            $docCount       = count($rows);
            $snakeFields    = [];   // field => count (per collection)
            $roleDriftCount = 0;
            $docIdDriftCount = 0;

            // Sample first doc fields for visibility
            $firstDoc = $rows[0]['data'] ?? [];
            echo "  total docs: {$docCount}\n";
            echo "  first-doc fields (top-level): ";
            $fieldNames = is_array($firstDoc) ? array_keys($firstDoc) : [];
            echo implode(', ', array_slice($fieldNames, 0, 12));
            if (count($fieldNames) > 12) echo ', ... (' . (count($fieldNames) - 12) . ' more)';
            echo "\n";

            foreach ($rows as $r) {
                $docId = (string)($r['id'] ?? '');
                $data  = is_array($r['data'] ?? null) ? $r['data'] : [];

                // 1. Scan ALL field names recursively for snake_case
                $allFieldPaths = [];
                $this->_collect_field_paths($data, '', $allFieldPaths);
                foreach ($allFieldPaths as $path) {
                    // strip path index notation [n]
                    $cleanPath = preg_replace('/\[\d+\]/', '', $path);
                    $segments = explode('.', $cleanPath);
                    foreach ($segments as $seg) {
                        // Snake-case detection: contains underscore AND has letters
                        // Allow numeric-only keys (array indices) — those don't apply
                        if (strpos($seg, '_') !== false && preg_match('/[a-zA-Z]/', $seg)) {
                            $snakeFields[$seg] = ($snakeFields[$seg] ?? 0) + 1;
                            $globalSnakeCaseFields[$seg] = ($globalSnakeCaseFields[$seg] ?? 0) + 1;
                        }
                    }
                }

                // 2. Check role-valued fields for UPPER/Title case
                foreach (self::ROLE_VALUED_FIELDS as $rf) {
                    $val = $this->_get_nested_field($data, $rf);
                    if ($val !== null && is_string($val) && $val !== '') {
                        if ($val !== strtolower($val)) {
                            $roleDriftCount++;
                            $globalUpperRoleValues[$val] = ($globalUpperRoleValues[$val] ?? 0) + 1;
                        }
                    }
                }

                // 3. For messageInboxes specifically: check doc-id has lowercase role token
                if ($col === 'messageInboxes' && $docId !== '') {
                    // Doc ID format: {schoolId}_{role}_{userId}_{convId}
                    // Check that the role segment is lowercase
                    $parts = explode('_', $docId);
                    $found = false;
                    foreach ($parts as $p) {
                        $lower = strtolower($p);
                        if (in_array($lower, self::CANONICAL_ROLE_TOKENS, true)) {
                            if ($p !== $lower) {
                                $docIdDriftCount++;
                                $globalUpperRoleDocIds[$docId] = true;
                            }
                            $found = true;
                            break;
                        }
                    }
                }
            }

            // Per-collection summary
            $collectionStatus[$col] = [
                'count' => $docCount,
                'snake_count' => array_sum($snakeFields),
                'snake_distinct' => count($snakeFields),
                'role_drift' => $roleDriftCount,
                'docid_drift' => $docIdDriftCount,
            ];

            if (!empty($snakeFields)) {
                echo "  ⚠ snake_case fields detected:\n";
                foreach ($snakeFields as $f => $c) echo "      \"{$f}\" x {$c}\n";
            } else {
                echo "  ✓ no snake_case drift\n";
            }
            if ($roleDriftCount > 0) {
                echo "  ⚠ role-valued field has non-lowercase value(s): {$roleDriftCount} instances\n";
            } else {
                echo "  ✓ role values lowercase\n";
            }
            if ($col === 'messageInboxes') {
                if ($docIdDriftCount > 0) {
                    echo "  ⚠ messageInboxes doc-id with uppercase role token: {$docIdDriftCount} docs\n";
                } else {
                    echo "  ✓ messageInboxes doc-ids lowercase\n";
                }
            }
        }

        // ── Global report ─────────────────────────────────────────────────
        echo "\n" . str_repeat('=', 64) . "\n";
        echo "PER-COLLECTION SUMMARY\n";
        echo str_repeat('=', 64) . "\n";
        printf("  %-22s %-10s %-12s %-10s %-10s\n",
            'collection', 'docs', 'snake_count', 'role_drift', 'docid_drift');
        foreach ($collectionStatus as $col => $st) {
            $countDisp = $st['count'] === -1 ? '(N/A)' : (string)$st['count'];
            printf("  %-22s %-10s %-12d %-10d %-10d\n",
                $col, $countDisp, $st['snake_count'] ?? 0, $st['role_drift'] ?? 0, $st['docid_drift'] ?? 0);
        }

        echo "\n" . str_repeat('=', 64) . "\n";
        echo "GLOBAL DRIFT TALLY\n";
        echo str_repeat('=', 64) . "\n";
        echo "Distinct snake_case fields across all collections: " . count($globalSnakeCaseFields) . "\n";
        if (!empty($globalSnakeCaseFields)) {
            arsort($globalSnakeCaseFields);
            foreach ($globalSnakeCaseFields as $f => $c) echo "  - \"{$f}\" x {$c}\n";
        }
        echo "\nNon-lowercase role values: " . count($globalUpperRoleValues) . "\n";
        if (!empty($globalUpperRoleValues)) {
            foreach ($globalUpperRoleValues as $v => $c) echo "  - \"{$v}\" x {$c}\n";
        }
        echo "\nmessageInboxes doc-ids with uppercase role tokens: " . count($globalUpperRoleDocIds) . "\n";
        if (!empty($globalUpperRoleDocIds)) {
            foreach (array_keys($globalUpperRoleDocIds) as $id) echo "  - {$id}\n";
        }

        $totalDrift = count($globalSnakeCaseFields)
                    + count($globalUpperRoleValues)
                    + count($globalUpperRoleDocIds);

        echo "\n" . str_repeat('=', 64) . "\n";
        echo "VERDICT\n";
        echo str_repeat('=', 64) . "\n";
        if ($totalDrift === 0) {
            echo "✅ T1.3 NORMAL — full canonical conformance: camelCase fields + lowercase role tokens across all messaging collections.\n";
        } else {
            echo "⚠ Drift detected ({$totalDrift} distinct indicators). Classify above per soak contract.\n";
            echo "Note: legacy read-fallback fields documented in [[messaging_canonical_schema]] §'Read fallbacks (Phase 2)' may explain some snake_case occurrences if they live ALONGSIDE canonical camelCase counterparts.\n";
        }

        echo "\n=== End verification ===\n";
    }

    /** Recursively collect dotted field paths in $data. */
    private function _collect_field_paths($data, string $prefix, array &$out): void
    {
        if (!is_array($data)) return;
        foreach ($data as $k => $v) {
            $key = (string)$k;
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";
            $out[] = $path;
            if (is_array($v)) {
                $this->_collect_field_paths($v, $path, $out);
            }
        }
    }

    /** Get a nested field by dotted path; here we only check top-level. */
    private function _get_nested_field(array $data, string $key)
    {
        return array_key_exists($key, $data) ? $data[$key] : null;
    }
}
