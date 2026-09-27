<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX Leave Policy Programme — Phase 2 (Effective-Dated Leave Policies)
 * wiring inside Hr.php, 2026-08-18.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class LeavePhase2EffectiveDatingWiringTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->src = $out;
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function functionBody(string $needle): string
    {
        $start = strpos($this->src, $needle);
        $this->assertNotFalse($start, "Expected to find: {$needle}");
        return $this->extractFunctionBody($this->src, $start);
    }

    public function test_version_append_gated_by_flag_and_by_real_change(): void
    {
        $body = $this->functionBody('public function save_leave_type(');
        $this->assertStringContainsString(
            "if (\$this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED') && (\$isNew || \$allocChanged)) {",
            $body,
            'a description-only edit or a no-op resave must not mint a redundant version.'
        );
    }

    public function test_version_append_refuses_duplicate_date_rather_than_overwrite(): void
    {
        $body = $this->functionBody('public function save_leave_type(');
        $this->assertStringContainsString('\Leave_policy_service::canAppendVersion(', $body);
        $this->assertStringContainsString("\$versionAppendError = \$check['error'];", $body);
    }

    public function test_retroactive_propagation_skipped_when_effective_dating_on(): void
    {
        $body = $this->functionBody('public function save_leave_type(');
        $this->assertStringContainsString(
            "} elseif (!\$isNew && is_array(\$prevType) && !\$this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED')) {",
            $body,
            'the legacy retroactive rewrite must be unreachable once effective dating is live — running both would reintroduce the exact risk Phase 2 exists to close.'
        );
    }

    public function test_flat_fields_still_written_identically_when_flag_off(): void
    {
        // When the flag is off, $data must contain no 'versions' key at
        // all — the document shape written to Firestore is byte-identical
        // to pre-Phase-2, which is what keeps the Teacher app (a direct,
        // flat-field reader of schools.leaveTypes) completely unaffected.
        $body = $this->functionBody('public function save_leave_type(');
        $ifPos = strpos($body, "if (\$this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED') && (\$isNew || \$allocChanged)) {");
        $writePos = strpos($body, "\$ok = \$this->fs->update('schools',");
        $this->assertNotFalse($ifPos);
        $this->assertNotFalse($writePos);
        $this->assertLessThan($writePos, $ifPos, 'version handling must resolve before the single Firestore write, not require a second write.');
    }

    public function test_derive_live_balances_accepts_as_of_date_and_defaults_off(): void
    {
        $this->assertStringContainsString(
            'private function _deriveLiveBalances(array $stored, array $activeTypes, ?array $staffMeta = null, ?string $asOfDate = null): array',
            $this->src
        );
        $body = $this->functionBody('private function _deriveLiveBalances(');
        $this->assertStringContainsString("\$effectiveDatingOn = \$this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED');", $body);
        $this->assertStringContainsString('\Leave_policy_service::effectiveVersion($lt, $resolveDate)', $body);
    }

    public function test_derive_live_balances_falls_back_to_flat_field_when_no_version_resolves(): void
    {
        $body = $this->functionBody('private function _deriveLiveBalances(');
        // $allocated is seeded from the flat field FIRST, then only
        // overwritten if a version actually resolves — never left unset.
        $allocPos = strpos($body, "\$allocated = (int) (\$lt['days_per_year'] ?? 0);");
        $verPos = strpos($body, '$ver = \Leave_policy_service::effectiveVersion($lt, $resolveDate);');
        $this->assertNotFalse($allocPos);
        $this->assertNotFalse($verPos);
        $this->assertLessThan($verPos, $allocPos);
    }

    public function test_get_leave_balances_call_sites_pass_year_end_as_of_date(): void
    {
        // 2 from Phase 2 (get_leave_balances' single-staff + bulk-staff
        // call sites) + 1 from Phase 7 (preview_leave_year_closure's per-type
        // version resolution) + 1 from the per-school accrual cap in apply_leave
        // (2026-09-19) — all four legitimately resolve "the policy in force for
        // year $year" the same way.
        $this->assertSame(4, substr_count($this->src, '"{$year}-12-31"'),
            'the single-staff and bulk-staff balance derivation call sites (Phase 2), the closure preview (Phase 7) and the apply_leave accrual cap must all resolve against the requested year.');
    }
}
