<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  NO CREDENTIAL MATERIAL MAY BE TRACKED IN THE REPOSITORY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT WAS FOUND (2026-08-26) ──────────────────────────────────────────
 * `snapshots/phase3f_gateway_backup.json` was committed and tracked. It was a
 * gateway configuration backup for school SCH_D94FE8F7AD, created 2026-04-17,
 * and it carried a real Razorpay TEST api_key (23 chars) and api_secret
 * (24 chars).
 *
 * It did not match the currently live credential — it is historical — but a
 * historical secret in a repository is still a secret in a repository. Anyone
 * with clone access has it, and it stays reachable through git history even
 * after the file is deleted.
 *
 * The file has been removed from the working tree and untracked, and
 * .gitignore now refuses the whole shape. Purging it from HISTORY is a
 * separate operator action; this test only ensures nothing NEW arrives.
 *
 * ── WHY A TEST AND NOT JUST A .gitignore RULE ────────────────────────────
 * .gitignore does nothing for a file that is already tracked, and nothing for
 * a path someone adds with `git add -f`. This asserts the property directly:
 * no tracked file contains a credential-shaped assignment.
 *
 * Values are never read into the failure message — only the file and the key.
 */
final class NoTrackedCredentialMaterialTest extends TestCase
{
    /** @return string[] repo-relative paths of tracked text files */
    private function trackedTextFiles(): array
    {
        $root = realpath(__DIR__ . '/../..');
        $out  = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files 2>&1', $out);

        $skip = '/\.(png|jpe?g|gif|svg|pdf|zip|apk|jar|ttf|woff2?|ico|mp4|webm)$/i';
        return array_values(array_filter($out, static function ($p) use ($root, $skip) {
            if ($p === '' || preg_match($skip, $p)) return false;
            $full = $root . '/' . $p;
            return is_file($full) && filesize($full) < 2 * 1024 * 1024;
        }));
    }

    /**
     * A JSON/PHP/JS assignment of a credential key to a long literal.
     * Deliberately narrow: it must not fire on a key NAME, a masked value, or
     * an empty string, all of which are legitimate and common.
     */
    private const CREDENTIAL_ASSIGNMENT =
        '/["\']?(api_secret|apiSecret|key_secret|webhook_secret|webhookSecret|private_key|privateKey)["\']?'
        . '\s*(?::|=>|=)\s*'
        . '["\']([A-Za-z0-9_\-]{16,})["\']/';

    public function test_no_tracked_file_contains_a_credential_assignment(): void
    {
        $root      = realpath(__DIR__ . '/../..');
        $offenders = [];

        foreach ($this->trackedTextFiles() as $rel) {
            $src = (string) @file_get_contents($root . '/' . $rel);
            if ($src === '') continue;
            if (!preg_match_all(self::CREDENTIAL_ASSIGNMENT, $src, $m, PREG_SET_ORDER)) continue;

            foreach ($m as $hit) {
                $key   = $hit[1];
                $value = $hit[2];

                // Obvious placeholders are not credentials. Kept tight so a
                // real secret cannot hide behind a vaguely test-ish name.
                if (preg_match('/^(x{4,}|placeholder|changeme|your_?|dummy|sample|example|redacted|test_?secret|fake)/i', $value)) {
                    continue;
                }
                // Report the KEY and the LENGTH. Never the value.
                $offenders[] = sprintf('%s :: %s (len=%d)', $rel, $key, strlen($value));
            }
        }

        $this->assertSame([], $offenders,
            "credential material is tracked in the repository:\n  "
            . implode("\n  ", $offenders)
            . "\nRemove the file, untrack it, and rotate the credential. Values are "
            . 'deliberately not printed.');
    }

    public function test_the_detector_actually_recognises_a_credential(): void
    {
        // Control: without this, the test above passes trivially the moment the
        // pattern breaks, and would have missed the very file that prompted it.
        $specimen = '{"schoolId":"SCH_X","api_secret":"AbCdEf0123456789XyZw","mode":"test"}';
        $this->assertSame(1, preg_match(self::CREDENTIAL_ASSIGNMENT, $specimen),
            'the detector no longer recognises a credential assignment');
    }

    /** The same decision the real test makes, factored so both use one rule. */
    private function isReportable(string $line): bool
    {
        if (!preg_match(self::CREDENTIAL_ASSIGNMENT, $line, $m)) return false;
        // The placeholder check applies to the VALUE, not to the whole line —
        // the first version of this control applied it to the line and so
        // reported a placeholder as a leak.
        return preg_match('/^(x{4,}|placeholder|changeme|your_?|dummy|sample|example|redacted|test_?secret|fake)/i', $m[2]) !== 1;
    }

    public function test_the_detector_does_not_fire_on_legitimate_shapes(): void
    {
        foreach ([
            '{"api_secret":""}',                       // absent
            '"api_secret" => present(len=24)',         // masked report
            "'api_secret' => \$data['api_secret'],",   // a field copy
            '{"api_secret":"xxxxxxxxxxxxxxxx"}',       // placeholder
            'has_api_secret: true',                    // presence boolean
        ] as $safe) {
            $this->assertFalse($this->isReportable($safe),
                "the detector fires on a legitimate shape: {$safe}");
        }
    }

    public function test_the_detector_still_fires_on_the_real_thing(): void
    {
        // Paired control: the exemptions above must not have blunted it.
        $this->assertTrue($this->isReportable('{"api_secret":"AbCdEf0123456789XyZw"}'),
            'the placeholder exemptions now swallow a real credential');
    }

    public function test_gitignore_refuses_the_shape_that_was_committed(): void
    {
        $ignore = (string) file_get_contents(__DIR__ . '/../../.gitignore');
        $this->assertStringContainsString('snapshots/*gateway*.json', $ignore,
            'the ignore rule that prevents another gateway snapshot being committed is missing');
    }

    public function test_the_offending_snapshot_is_gone_from_the_working_tree(): void
    {
        $this->assertFileDoesNotExist(__DIR__ . '/../../snapshots/phase3f_gateway_backup.json',
            'the credential-bearing snapshot is back in the working tree');
    }
}
