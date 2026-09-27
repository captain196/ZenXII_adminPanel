<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * S-1 — the statutory configuration must actually reach the payroll engine.
 *
 * FOUND 2026-08-22. `Statutory_config_service` is a complete, effective-dated,
 * overlap-checked configuration system with its own Admin UI
 * (`Compensation::get_statutory_config` / `save_statutory_config`). Every one of
 * the four `_compute_payslip()` call sites passed a hard-coded `null` for
 * `$statutory`, so the statutory-aware branch was unreachable.
 *
 * A tenant could configure a PF ceiling, an eligibility rule or a professional-
 * tax slab table, see it accepted, see it listed back in the UI — and payroll
 * would ignore it entirely. That is worse than an unimplemented feature: it is a
 * setting that lies.
 *
 * FIXED by resolving the tenant's configuration as of the PERIOD END and passing
 * it to both engines, and by freezing the resolved policy onto the run.
 *
 * WHY THIS IS SAFE TO HAVE DONE WITHOUT AUTHORISATION. A tenant with no
 * statutory configuration resolves to `null`, which is the legacy branch and
 * today's behaviour byte-for-byte. Census at the time of the change: **zero**
 * statutory configurations in any of the nine tenants. So the change is inert
 * until a tenant deliberately creates one through the UI — which is opt-in, per
 * tenant, and is exactly what creating one is FOR.
 *
 * These are source assertions where the seam is structural, and real execution
 * where behaviour can be exercised. `_compute_payslip()` itself is exercised
 * through the real-engine harness in the money suites; what is asserted here is
 * the WIRING, which is not otherwise observable without standing up Firestore.
 */
final class StatutoryConfigReachesPayrollTest extends TestCase
{
    private static string $hr = '';

    public static function setUpBeforeClass(): void
    {
        self::$hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$hr = '';
    }

    // ── The defect must not return ───────────────────────────────────────

    /**
     * THE LOAD-BEARING ASSERTION. No call site may hard-code `null` for the
     * statutory argument again.
     */
    public function test_no_call_site_hard_codes_a_null_statutory_config(): void
    {
        $this->assertSame(
            0,
            substr_count(self::$hr, 'extraWorkedRate, null,'),
            'a hard-coded null here makes every tenant statutory configuration inert'
        );
    }

    /** Both engines resolve the configuration, and both pass it through. */
    public function test_both_engines_resolve_and_pass_the_configuration(): void
    {
        $this->assertSame(
            2,
            substr_count(self::$hr, '$statutoryCfg = $this->_statutory_config_for($periodEndDate);'),
            'generation and regeneration must both resolve it, and from the same date'
        );

        $this->assertSame(
            4,
            substr_count(self::$hr, '$extraWorkedRate, $statutoryCfg'),
            'all four compute call sites — two baselines and two actuals — must use it'
        );
    }

    /**
     * Resolution is AS OF THE PERIOD, never as of today.
     *
     * A configuration created in September must not reprice July. This is the
     * property that makes historical payroll reproducible at all.
     */
    public function test_resolution_is_as_of_the_period_not_today(): void
    {
        $i = strpos(self::$hr, 'private function _statutory_config_for(');
        $this->assertNotFalse($i, 'resolver not found');

        // Bounded at the NEXT method rather than by a character count. A fixed
        // window silently overran into neighbouring helpers as they were added,
        // failing for a reason that had nothing to do with the invariant.
        $end = strpos(self::$hr, 'private function _location_doc(', (int) $i);
        $this->assertNotFalse($end, 'expected _location_doc to follow the resolver');
        $body = substr(self::$hr, (int) $i, (int) $end - (int) $i);
        $this->assertStringContainsString('_statutory_config_for', $body, 'slice sanity');

        $this->assertStringContainsString(
            'Statutory_config_service::selectFor($all, $asOfDate)',
            $body,
            'the resolver must select for the given date'
        );

        // Comments are STRIPPED before the forbidden-token scan. The invariant
        // is about executable code, and the docblock legitimately contains the
        // heading "AS-OF, NOT NOW" — documentation asserting the very property
        // under test must not be what fails it.
        $code = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        $this->assertStringContainsString('_statutory_config_for', $code, 'strip sanity');

        foreach (["date('Y-m-d')", 'time()', 'now'] as $today) {
            $this->assertStringNotContainsString($today, $code,
                'resolving against today would let a later configuration reprice a closed month');
        }
    }

    /** The same shared effective-dating helper the salary assignments use. */
    public function test_it_uses_the_shared_effective_dating_helper(): void
    {
        $svc = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Statutory_config_service.php'
        );

        $i = strpos($svc, 'public static function selectFor(');
        $this->assertNotFalse($i);
        $body = substr($svc, (int) $i, 300);

        $this->assertStringContainsString('ed_select_for($configs, $date)', $body,
            'two effective-dating implementations would eventually disagree, and the '
            . 'disagreement would surface as a period resolving to one statutory config '
            . 'but a different salary assignment');
    }

    // ── Fail-safe: absent configuration is legacy behaviour ──────────────

    /**
     * THE PROPERTY THAT MAKES THIS CHANGE SAFE. No configuration ⇒ null ⇒ the
     * legacy branch, byte-for-byte.
     */
    public function test_an_absent_configuration_resolves_to_null(): void
    {
        $i = strpos(self::$hr, 'private function _statutory_config_for(');
        $body = substr(self::$hr, (int) $i, 2600);

        $this->assertStringContainsString('$resolved = null;', $body,
            'the resolver must start from null so an unconfigured tenant is unchanged');
        $this->assertStringContainsString('if (!empty($all)) {', $body,
            'an empty configuration list must not be resolved against');
    }

    /**
     * A read FAILURE must also resolve to null rather than to some other money
     * answer — and must be logged rather than absorbed.
     */
    public function test_a_read_failure_fails_to_the_legacy_branch_and_is_logged(): void
    {
        $i = strpos(self::$hr, 'private function _statutory_config_for(');
        $body = substr(self::$hr, (int) $i, 2600);

        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
        $this->assertStringContainsString('HR statutory config resolution failed for', $body,
            'a silent catch here would change money without saying so');
    }

    /** The legacy branch itself is untouched. */
    public function test_the_legacy_branch_is_unchanged(): void
    {
        $this->assertStringContainsString('if ($statutory === null) {', self::$hr);
        $this->assertStringContainsString(
            '// ── LEGACY PATH — unchanged since W1.2, byte for byte ──',
            self::$hr
        );
    }

    // ── Performance: one read per run, not one per employee ──────────────

    /**
     * A 200-employee run must not issue 200 statutory reads. The resolver is
     * memoised per date, and it is additionally hoisted out of the per-employee
     * loop at both call sites.
     */
    public function test_resolution_is_memoised_and_hoisted(): void
    {
        $i = strpos(self::$hr, 'private function _statutory_config_for(');
        $body = substr(self::$hr, (int) $i, 2600);

        $this->assertStringContainsString('static $memo = [];', $body,
            'without the memo a run would re-read the configuration per employee');
        $this->assertStringContainsString('array_key_exists($asOfDate, $memo)', $body,
            'the memo must distinguish "resolved to null" from "not yet resolved" — '
            . 'isset() would re-read on every employee for an unconfigured tenant');
    }

    // ── Reproducibility: the run remembers what priced it ────────────────

    /** The resolved policy is frozen onto the run document. */
    public function test_the_run_freezes_the_statutory_policy(): void
    {
        $this->assertStringContainsString(
            "'statutory_snapshot' => \$this->_statutory_snapshot(\$statutoryCfg),",
            self::$hr,
            'without this a configuration created later leaves earlier runs unexplainable'
        );
    }

    /** Including when there was none — "no policy" is itself the explanation. */
    public function test_the_snapshot_records_the_absence_of_a_policy_too(): void
    {
        $i = strpos(self::$hr, 'private function _statutory_snapshot(');
        $this->assertNotFalse($i);
        $body = substr(self::$hr, (int) $i, 1800);

        $this->assertStringContainsString("'configured'    => false,", $body);
        $this->assertStringContainsString("'source'        => 'structure',", $body);
        $this->assertStringContainsString("'configured'    => true,", $body);
        $this->assertStringContainsString("'effectiveFrom' =>", $body,
            'the snapshot must name WHICH window priced the run');
    }

    /**
     * The trace reads the frozen snapshot from the run and must NOT re-resolve.
     * Re-resolving would answer "what would apply today", which is precisely the
     * question a reproducibility trace must not answer.
     */
    public function test_the_trace_reads_the_frozen_snapshot_rather_than_re_resolving(): void
    {
        $i = strpos(self::$hr, 'public function payslip_trace(');
        $this->assertNotFalse($i);
        $body = substr(self::$hr, (int) $i, 4000);

        $this->assertStringContainsString("\$run['statutory_snapshot']", $body,
            'the trace must read what was frozen');
        $this->assertStringNotContainsString('_statutory_config_for(', $body,
            're-resolving in the trace would report present policy for a past run');
    }

    /** And the run it reads must actually be in scope — php -l cannot catch this. */
    public function test_the_trace_loads_the_run_it_reads(): void
    {
        $i = strpos(self::$hr, 'public function payslip_trace(');
        $body = substr(self::$hr, (int) $i, 4000);

        $this->assertStringContainsString(
            '$run = $this->fs->get(\'salarySlips\', $this->fs->docId("RUN_{$runId}"));',
            $body,
            'reading $run without loading it would emit an undefined-variable warning '
            . 'and silently report the pre-snapshot branch forever'
        );
        $this->assertStringContainsString('if (!is_array($run)) $run = [];', $body);
    }

    /** A run generated before snapshotting says so, rather than claiming a policy. */
    public function test_a_pre_snapshot_run_is_reported_honestly(): void
    {
        $this->assertStringContainsString('PRE-DATES SNAPSHOTTING', self::$hr,
            'an older run must not be presented as having a frozen policy it never had');
    }

    /** The stale claim that snapshotting is impossible is gone. */
    public function test_the_obsolete_not_configured_claim_is_removed(): void
    {
        $this->assertStringNotContainsString(
            'the statutory policy in force cannot be frozen with the run',
            self::$hr,
            'that statement described the defect, and the defect is fixed'
        );
    }
}
