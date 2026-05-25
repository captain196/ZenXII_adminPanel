<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Homework_hwid_verify — Tier 1.8 homework canonical hwId entropy verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical hwId schema per Finding #8
 * (Admin) + [[teacher_hwid_entropy_backlog]] (Teacher, shipped 2026-05-15):
 *
 *   hwId format: {school_name}_{epoch_ms}_{8_hex_chars}
 *
 *   - school_name prefix (one or more underscore-separable segments)
 *   - epoch_ms: 13 digits (milliseconds since 1970 — present-day clock)
 *   - 8-hex suffix from bin2hex(random_bytes(4)) — 32 bits SecureRandom entropy
 *
 * Verifies both:
 *   1. Format conformance (regex match against canonical pattern)
 *   2. Entropy presence (suffix is 8 hex chars, lowercase a-f / 0-9)
 *   3. Collision check (no two docs share the same hwId)
 *
 * Source code reference: application/controllers/Homework.php:1054
 *
 * INVOCATION:
 *   php index.php homework_hwid_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Homework_hwid_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    /** Canonical hwId regex per Finding #8: {prefix}_{epoch_ms}_{8_hex_chars} */
    private const HWID_PATTERN = '/^[A-Za-z][A-Za-z0-9_\-]*_\d{12,14}_[a-f0-9]{8}$/';

    /** Permissive pattern that catches "has 8-hex suffix" even if prefix shape varies */
    private const HWID_SUFFIX_PATTERN = '/_[a-f0-9]{8}$/';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Homework_hwid_verify is CLI-only.', 403);
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

    /** CLI: php index.php homework_hwid_verify verify */
    public function verify(): void
    {
        echo "=== Tier 1.8 Homework hwId Entropy Verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo "Canonical pattern: {prefix}_{epoch_ms}_{8_hex_chars}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('homeworks', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            echo "ERROR fetching homeworks: " . $e->getMessage() . "\n";
            return;
        }

        $totalDocs = count($rows);
        echo "Total homework docs fetched: {$totalDocs}\n\n";

        if ($totalDocs === 0) {
            echo "No homework docs in scope.\n";
            echo "\n=== T1.8 TRIVIAL PASS — no data to verify ===\n";
            echo "Verdict: ✅ NORMAL (vacuous truth — no homework writes to evaluate against the canonical pattern)\n";
            echo "When homework is created in this environment, re-run this verifier to confirm conformance.\n";
            return;
        }

        $conformantStrict = 0;
        $conformantSuffix = 0;   // has 8-hex suffix but prefix shape differs
        $nonConformant = [];     // doesn't even have 8-hex suffix
        $hwIdsSeen = [];         // collision detection
        $duplicates = [];

        foreach ($rows as $r) {
            $docId = (string)($r['id'] ?? '');
            $data  = is_array($r['data'] ?? null) ? $r['data'] : [];

            // Try multiple possible field locations + fall back to doc-id
            $hwId = (string)(
                $data['hwId'] ?? $data['hw_id'] ?? $data['_id']
                ?? $data['id'] ?? $docId
            );

            if ($hwId === '') {
                $nonConformant[] = "(no hwId field) docId={$docId}";
                continue;
            }

            // Collision check
            if (isset($hwIdsSeen[$hwId])) {
                $duplicates[] = $hwId;
            }
            $hwIdsSeen[$hwId] = true;

            // Format conformance
            if (preg_match(self::HWID_PATTERN, $hwId)) {
                $conformantStrict++;
            } elseif (preg_match(self::HWID_SUFFIX_PATTERN, $hwId)) {
                $conformantSuffix++;
                echo "  ⚠ has 8-hex suffix but prefix shape differs: {$hwId}\n";
            } else {
                $nonConformant[] = $hwId;
            }
        }

        // ── Report ────────────────────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        echo "Strict canonical (full pattern match): {$conformantStrict}\n";
        echo "Suffix-only conformant (8-hex tail, varied prefix): {$conformantSuffix}\n";
        echo "Non-conformant (no 8-hex suffix): " . count($nonConformant) . "\n";
        foreach ($nonConformant as $row) echo "  - {$row}\n";

        echo "\n─── Collision check ───\n";
        echo "Unique hwIds: " . count($hwIdsSeen) . " / {$totalDocs} docs\n";
        echo "Duplicate hwIds: " . count($duplicates) . "\n";
        foreach ($duplicates as $d) echo "  - DUPLICATE: {$d}\n";

        echo "\n─── Verdict ───\n";
        $totalDrift = count($nonConformant) + count($duplicates);
        if ($totalDrift === 0 && $conformantStrict === $totalDocs) {
            echo "✅ T1.8 NORMAL — all {$totalDocs} homework docs conform to canonical hwId pattern with 32-bit SecureRandom entropy suffix; zero collisions.\n";
        } elseif ($totalDrift === 0 && $conformantSuffix > 0) {
            echo "✅ T1.8 NORMAL-WITH-OBSERVATION — all {$totalDocs} have 8-hex entropy suffix; {$conformantSuffix} have prefix shape that doesn't strictly match {prefix}_{epoch_ms}_{8_hex} (may be Phase 1 legacy or alternate writer). No collisions.\n";
        } elseif (count($duplicates) > 0) {
            echo "🛑 FREEZE_REQUIRED — hwId collisions detected. Per Finding #8 the entropy suffix MUST prevent collisions; any duplicate is an integrity boundary violation.\n";
        } else {
            echo "⚠ WATCH — " . count($nonConformant) . " docs lack canonical 8-hex entropy suffix. Classify per soak contract.\n";
        }

        echo "\n=== End verification ===\n";
    }
}
