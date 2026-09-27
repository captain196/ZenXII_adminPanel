<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase J, 2026-08-18.
 *
 * Confirmed bug: Staff::edit_staff() (the generic edit form) writes
 * staff.Department immediately, with no effective date — and Payroll's
 * generate_payroll()/regenerate_staff_payroll() read that LIVE field
 * directly when resolving a payroll PERIOD's calendar/holiday-audience via
 * _staff_working_days(). An admin editing a staff member's department
 * today could therefore silently change the computed working-days math
 * for an already-past period, if that period is (re)generated after the
 * edit — a present-tense change reaching back into history.
 *
 * Fix: a new READ-ONLY Staff_employment_history_service::resolveAttributeAsOf()
 * resolves the department that was actually effective for the period being
 * calculated, using the SAME staffEmploymentTimeline projection Promotion
 * already writes to — no new data model, no new writer, and both payroll
 * call sites fall open to the live value on any resolver failure (never
 * blocks payroll generation).
 *
 * Deliberately scoped to the period START date only — a genuine mid-month
 * department-transfer split is out of scope (Staff Transfer doesn't exist
 * as a feature yet, per Trail 2 Phase B's audit).
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/library code).
 */
class PayrollDepartmentEffectiveDatingTest extends TestCase
{
    private string $serviceSrc;
    private string $hrSrc;

    protected function setUp(): void
    {
        foreach ([
            'serviceSrc' => __DIR__ . '/../../application/libraries/Staff_employment_history_service.php',
            'hrSrc'      => __DIR__ . '/../../application/controllers/Hr.php',
        ] as $prop => $path) {
            $raw = file_get_contents($path);
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
            $this->$prop = $out;
        }
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

    private function resolverBody(): string
    {
        $start = strpos($this->serviceSrc, 'function resolveAttributeAsOf(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->serviceSrc, $start);
    }

    // ── The resolver itself ───────────────────────────────────────────────

    public function test_resolver_is_read_only_no_write_ops(): void
    {
        $body = $this->resolverBody();
        $this->assertStringNotContainsString('commitBatch', $body);
        $this->assertStringNotContainsString('->set(', $body);
        $this->assertStringNotContainsString('->update(', $body);
    }

    public function test_resolver_only_accepts_the_locked_attribute_vocabulary(): void
    {
        $body = $this->resolverBody();
        $this->assertStringContainsString('!in_array($attribute, self::ATTRIBUTES, true)', $body);
    }

    public function test_resolver_validates_the_date_format(): void
    {
        $body = $this->resolverBody();
        $this->assertStringContainsString("preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$asOfDate)", $body);
    }

    public function test_resolver_excludes_windows_that_start_after_the_target_date(): void
    {
        $body = $this->resolverBody();
        $this->assertStringContainsString('if ($ef === \'\' || $ef > $asOfDate) continue;', $body);
    }

    public function test_resolver_excludes_windows_already_closed_before_the_target_date(): void
    {
        $body = $this->resolverBody();
        $this->assertStringContainsString("if (\$et !== null && (string) \$et !== '' && (string) \$et <= \$asOfDate) continue;", $body);
    }

    /**
     * The Firestore read moved out of resolveAttributeAsOf() into the private
     * timelineRows() reader when warmTimeline() was added to remove payroll's
     * N+1 (2026-08-19). These two tests follow it: the properties being
     * asserted — staff+attribute scoping, and failing closed to null rather
     * than throwing — are unchanged, only their location is.
     */
    private function readerBody(): string
    {
        $start = strpos($this->serviceSrc, 'function timelineRows(');
        $this->assertNotFalse($start, 'timelineRows() reader not found');
        return $this->extractFunctionBody($this->serviceSrc, $start);
    }

    public function test_resolver_fails_closed_to_null_never_throws(): void
    {
        $reader = $this->readerBody();
        $this->assertStringContainsString('} catch (\Throwable $e) {', $reader);
        $this->assertStringContainsString('return null;', $reader);

        // …and the resolver must PROPAGATE that null rather than treating a
        // failed read as "no history", which would silently fall back to the
        // live value and defeat effective dating.
        $this->assertStringContainsString(
            'if ($rows === null) return null;',
            $this->resolverBody()
        );
    }

    public function test_resolver_query_is_tenant_and_staff_scoped(): void
    {
        $reader = $this->readerBody();
        $this->assertStringContainsString("['staffId',   '==', \$staffId],", $reader);
        $this->assertStringContainsString("['attribute', '==', \$attribute],", $reader);

        // schoolWhere() is what applies the tenant scope; a raw query() here
        // would read across tenants.
        $this->assertStringContainsString('$this->fs->schoolWhere(self::TIMELINE_COL', $reader);
    }

    /**
     * The warm path must be tenant-scoped too. It reads the whole collection in
     * one query, so schoolWhere() is the only thing keeping it inside the tenant.
     */
    public function test_warm_preload_is_tenant_scoped(): void
    {
        $start = strpos($this->serviceSrc, 'function warmTimeline(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->serviceSrc, $start);

        $this->assertStringContainsString("\$this->fs->schoolWhere(self::TIMELINE_COL, [])", $body);
        $this->assertStringNotContainsString('->query(', $body, 'a raw query would cross tenants');
        // Failing to warm must be survivable, not fatal.
        $this->assertStringContainsString('return false;', $body);
    }

    // ── generate_payroll() call site ────────────────────────────────────

    public function test_generate_payroll_resolves_department_from_history(): void
    {
        $start = strpos($this->hrSrc, 'public function generate_payroll(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString("resolveAttributeAsOf(\$staffId, 'department', \$periodStart)", $body);
    }

    public function test_generate_payroll_resolves_against_period_start_not_todays_date(): void
    {
        $start = strpos($this->hrSrc, 'public function generate_payroll(');
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString("\$periodStart = sprintf('%04d-%02d-01', (int) \$year, \$monthNum);", $body);
    }

    public function test_generate_payroll_falls_open_to_live_department_on_resolver_failure(): void
    {
        $start = strpos($this->hrSrc, 'public function generate_payroll(');
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $callSitePos = strpos($body, "resolveAttributeAsOf(\$staffId, 'department'");
        $this->assertNotFalse($callSitePos);
        // Window widened 700 → 1400: the position resolution now sits
        // between the department call and the shared catch block.
        $chunk = substr($body, max(0, $callSitePos - 400), 1400);
        $this->assertStringContainsString('} catch (\Throwable $e) {', $chunk);
        // Message widened when position joined this same try block — see
        // test_generate_payroll_now_also_resolves_position() below.
        $this->assertStringContainsString('history resolve failed', $chunk);
        // Never a json_error / block on this specific failure path.
        $this->assertStringNotContainsString('json_error', $chunk);
    }

    /**
     * REVERSAL, recorded deliberately (Consolidated reconciliation audit,
     * 2026-08-19).
     *
     * This test previously asserted the OPPOSITE — that generate_payroll()
     * must never resolve position — on the stated grounds that "position is
     * display/structure-lookup metadata, not calendar/holiday-audience calc
     * input (per the Phase J audit)".
     *
     * That premise was factually wrong about this codebase, and the
     * reconciliation audit disproved it: $position feeds
     * stripos($position, 'teacher') / 'lecturer' in the Teaching vs
     * Non-Teaching expense classification, which selects GL account 5010 or
     * 5020 for the salary journal. Phase J simply did not look past the
     * calendar/holiday consumers when it scoped itself to department.
     *
     * Worse, the same boolean already used the Phase-J-corrected historical
     * $department, so one expression mixed a period-effective department
     * with a live position.
     *
     * Position is therefore now resolved against the same period start, by
     * the same resolver, and 'position' was already in the tracked
     * ATTRIBUTES vocabulary so no schema change was needed. The
     * classification rule and the account codes are unchanged.
     *
     * See PayrollExpenseClassificationEffectiveDatingTest for the full
     * coverage of that fix.
     */
    public function test_generate_payroll_now_also_resolves_position(): void
    {
        $start = strpos($this->hrSrc, 'public function generate_payroll(');
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString("resolveAttributeAsOf(\$staffId, 'position', \$periodStart)", $body);
        // Department resolution must still be there too — this widened the
        // scope, it did not replace the original fix.
        $this->assertStringContainsString("resolveAttributeAsOf(\$staffId, 'department', \$periodStart)", $body);
    }

    // ── regenerate_staff_payroll() call site ────────────────────────────

    public function test_regenerate_staff_payroll_resolves_department_from_history(): void
    {
        $start = strpos($this->hrSrc, 'public function regenerate_staff_payroll(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString("resolveAttributeAsOf(\$staffId, 'department', \$periodStart)", $body);
    }

    public function test_regenerate_staff_payroll_falls_open_on_resolver_failure(): void
    {
        $start = strpos($this->hrSrc, 'public function regenerate_staff_payroll(');
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $callSitePos = strpos($body, "resolveAttributeAsOf(\$staffId, 'department'");
        $this->assertNotFalse($callSitePos);
        $chunk = substr($body, max(0, $callSitePos - 400), 700);
        $this->assertStringContainsString('} catch (\Throwable $e) {', $chunk);
        $this->assertStringContainsString('regenerate_staff_payroll: department history resolve failed', $chunk);
    }

    // ── Both call sites resolve BEFORE the working-days calculation ─────

    public function test_both_call_sites_resolve_department_before_staff_working_days_is_called(): void
    {
        foreach (['public function generate_payroll(', 'public function regenerate_staff_payroll('] as $fnSig) {
            $start = strpos($this->hrSrc, $fnSig);
            $this->assertNotFalse($start);
            $body = $this->extractFunctionBody($this->hrSrc, $start);
            $resolvePos = strpos($body, "resolveAttributeAsOf(\$staffId, 'department'");
            $workingDaysPos = strpos($body, '_staff_working_days($monthNum, (int) $year, $holidays, $jd, $xd, $staffId, $department)');
            $this->assertNotFalse($resolvePos, "{$fnSig} must resolve department history");
            $this->assertNotFalse($workingDaysPos, "{$fnSig} must call _staff_working_days");
            $this->assertLessThan($workingDaysPos, $resolvePos, "{$fnSig}: department must be resolved before _staff_working_days() consumes it");
        }
    }
}
