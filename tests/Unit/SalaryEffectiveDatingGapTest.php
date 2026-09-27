<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * D-006 — salary effective dating. **My original analysis was wrong, and this
 * file records the correction.**
 *
 * WHAT I FIRST CONCLUDED (2026-08-21)
 * -----------------------------------
 * "Compensation is not effective-dated: one `SAL_{staffId}` document,
 * overwritten in place, read live by both engines." I reached that by tracing
 * the legacy document and stopping there.
 *
 * WHAT IS ACTUALLY TRUE (verified 2026-08-22)
 * ------------------------------------------
 * A complete effective-dated compensation architecture already exists, built by
 * the W2 programme:
 *
 *   Salary_assignment_service  entityId = "{staffId}_{effectiveFrom}",
 *                              validates effectiveFrom / effectiveTo, refuses
 *                              an effectiveTo preceding effectiveFrom,
 *                              resolve($staffId, $date) is an as-of lookup
 *   Compensation_reader        resolve() / resolveMany() with three modes:
 *                              legacy | shadow | component
 *   plus Salary_component_service, Salary_grade_service,
 *   Salary_template_service and Statutory_config_service
 *
 * `generate_payroll()` already resolves through that reader **as of the last
 * day of the pay period**, with its own comment naming the reason:
 * "regenerating a finalized run must not silently reprice it."
 *
 * So the architecture is not missing. `USE_COMPONENT_COMPENSATION` is FALSE —
 * a deliberate, documented gate — so legacy is what actually runs.
 *
 * THE REAL DEFECT, FOUND AND FIXED
 * --------------------------------
 * `regenerate_staff_payroll()` did NOT go through the reader. It read
 * `SAL_{staffId}` directly, bypassing mode and as-of date entirely.
 *
 * Today that is harmless: with the flag false both paths land on the same
 * legacy document. It becomes a live payroll defect the moment the flag flips —
 * generation would resolve the effective-dated assignment for the period while
 * regeneration read whatever the employee earns TODAY, so regenerating a closed
 * month would reprice it. That breaks generation ≡ regeneration, the invariant
 * this programme treats as load-bearing.
 *
 * Fixed by routing regeneration through the same reader with an identically
 * computed period-end date. Legacy mode is byte-identical to before.
 *
 * WHAT REMAINS IS A CUTOVER, NOT ENGINEERING
 * ------------------------------------------
 * Flipping `USE_COMPONENT_COMPENSATION` is an owner decision with a documented
 * gate, and the shadow mode exists precisely to de-risk it. Not flipped here.
 */
final class SalaryEffectiveDatingGapTest extends TestCase
{
    private static string $hrCache = '';
    private string $hr;

    public static function setUpBeforeClass(): void
    {
        self::$hrCache = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Hr.php'
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$hrCache = '';
    }

    protected function setUp(): void
    {
        $this->assertNotSame('', self::$hrCache, 'Hr.php could not be read');
        $this->hr = self::$hrCache;
    }

    // ── The architecture exists ──────────────────────────────────────────

    public function test_an_effective_dated_assignment_service_exists(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Salary_assignment_service.php');
        $this->assertNotFalse($src, 'the effective-dated salary service must exist');

        $this->assertStringContainsString(
            "return \$staffId . '_' . \$effectiveFrom;",
            $src,
            'assignment identity carries its own effective date'
        );
        $this->assertStringContainsString('public function resolve(string $staffId, string $date)', $src,
            'as-of resolution is the whole point');
    }

    public function test_the_assignment_service_validates_its_interval(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Salary_assignment_service.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString('effectiveFrom must be a real date', $src);
        $this->assertStringContainsString('effectiveTo must not precede effectiveFrom.', $src,
            'an inverted interval would make as-of resolution meaningless');
    }

    public function test_the_reader_offers_a_shadow_mode_to_de_risk_the_cutover(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Compensation_reader.php');
        $this->assertNotFalse($src);

        foreach (['MODE_LEGACY', 'MODE_SHADOW', 'MODE_COMPONENT'] as $mode) {
            $this->assertStringContainsString("const {$mode}", $src);
        }
        $this->assertStringContainsString('authoritative result is always the legacy one', $src,
            'shadow mode must compare without changing what anybody is paid');
    }

    // ── Both engines now ask the same question ───────────────────────────

    /** THE FIX. Generation and regeneration must resolve salary identically. */
    public function test_both_engines_resolve_salary_through_the_one_reader(): void
    {
        $this->assertStringContainsString(
            '$this->_compensation_reader()->resolveMany(',
            $this->hr,
            'generation resolves through the reader'
        );
        $this->assertStringContainsString(
            '$structureDoc = $this->_compensation_reader()->resolve($staffId, $periodEndDate);',
            $this->hr,
            'regeneration must resolve through the SAME reader — a raw SAL_ read bypasses '
            . 'both the mode and the as-of date'
        );
    }

    /** And with the same as-of date, or a closed month could be repriced. */
    public function test_both_engines_use_the_period_end_as_of_date(): void
    {
        $this->assertSame(
            2,
            substr_count($this->hr, "\$periodEndDate = date(\n            'Y-m-t',"),
            'generation and regeneration must compute the period-end date identically'
        );
    }

    /** No path may read the legacy structure directly any more. */
    public function test_no_engine_reads_the_legacy_structure_directly(): void
    {
        $this->assertStringNotContainsString(
            '$structureDoc = $this->fs->get(\'salarySlips\', $this->fs->docId("SAL_{$staffId}"));',
            $this->hr,
            'a direct read bypasses the reader and silently pins the engine to legacy mode'
        );
    }

    /** Legacy mode must still reach the same document, so today is unchanged. */
    public function test_legacy_mode_still_resolves_the_same_document(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Compensation_reader.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString('getEntity(\'salarySlips\', "SAL_{$staffId}")', $src,
            'with the flag false the reader must return exactly what the raw read returned');
    }

    // ── The remaining step is a flag, not code ───────────────────────────

    /**
     * The component path is gated OFF. Recorded as incomplete rather than
     * skipped so the cutover stays visible: the engineering is done, the
     * decision is not.
     */
    public function test_the_component_cutover_is_still_gated(): void
    {
        $flags = file_get_contents(__DIR__ . '/../../application/config/hrms_compensation_flags.php');
        $this->assertNotFalse($flags);

        if (strpos($flags, "\$config['USE_COMPONENT_COMPENSATION'] = false;") !== false) {
            $this->markTestIncomplete(
                'D-006 CUTOVER PENDING (engineering complete): the effective-dated compensation '
                . 'architecture exists (Salary_assignment_service + Compensation_reader) and BOTH '
                . 'payroll engines now resolve through it as of period end. '
                . "USE_COMPONENT_COMPENSATION is false, so legacy `SAL_{staffId}` is what actually "
                . 'runs and a salary revision still overwrites in place. Flipping it is an owner '
                . 'decision with a documented gate; MODE_SHADOW exists to de-risk it by comparing '
                . 'both without changing anybody\'s pay. Census as of 2026-08-25: 1 salary '
                . 'structure and 1 payslip now exist (UAT employee STA0110, run PR0019) — '
                . 'the previous "0 in any tenant" reading is stale, and the parity '
                . 'evidence for the cutover has been produced: the same employee carries a '
                . 'component assignment and a legacy structure that agree at 35,000 gross.'
            );
        }

        $this->assertStringContainsString("\$config['USE_COMPONENT_COMPENSATION'] = true;", $flags);
    }

    /** The flag must stay false until the cutover is deliberately taken. */
    public function test_the_flag_defaults_off(): void
    {
        $flags = file_get_contents(__DIR__ . '/../../application/config/hrms_compensation_flags.php');
        $this->assertNotFalse($flags);

        $this->assertStringContainsString('DEFAULT FALSE and it must stay false until', $flags,
            'the gate and its conditions must remain documented in the config itself');
    }

    // ── E-1: the cutover fallback that would have paid from defaults ─────

    /**
     * FOUND 2026-08-22 auditing the component cutover (Section E), fixed same day.
     *
     * `regenerate_staff_payroll()` falls back to `_auto_create_from_profile()`
     * when the reader resolves nothing. That fallback belongs to the LEGACY
     * store: it derives a structure from `schools.salaryDefaults` percentages
     * and writes it to `SAL_{staffId}` through `_fsSyncSalary()`.
     *
     * Under MODE_COMPONENT the authority is `staffSalaryAssignments` resolved
     * through the `salaryComponents` catalogue, and that legacy document is
     * never read back. So after cutover the fallback would have done two wrong
     * things at once for any employee missing an assignment: paid them from
     * DEFAULT percentages instead of their real one, and written to a store
     * nothing consults — quietly, on every regeneration.
     *
     * A missing assignment after cutover is a data gap. It must refuse.
     *
     * Latent: USE_COMPONENT_COMPENSATION is false, so the fallback is still the
     * correct behaviour on the path that actually runs today. Fixed before the
     * flag can be flipped rather than after somebody is paid a default salary.
     */
    public function test_component_mode_refuses_rather_than_auto_creating(): void
    {
        $i = strpos($this->hr, 'public function regenerate_staff_payroll');
        $this->assertNotFalse($i, 'regenerate_staff_payroll not found');
        $body = substr($this->hr, (int) $i, 12000);

        $this->assertStringContainsString(
            'if ($this->_compensation_reader()->mode() === Compensation_reader::MODE_COMPONENT) {',
            $body,
            'after cutover a missing assignment must refuse, not synthesise a structure'
        );
        $this->assertStringContainsString(
            'no effective salary assignment for this staff member',
            $body,
            'and the refusal must name what is actually missing'
        );
    }

    /**
     * The legacy path is UNCHANGED. The guard must not have narrowed the
     * behaviour that runs today, which is the whole basis for calling this
     * change safe while the flag is false.
     */
    public function test_the_legacy_auto_create_path_still_stands(): void
    {
        $i = strpos($this->hr, 'public function regenerate_staff_payroll');
        $body = substr($this->hr, (int) $i, 12000);

        $this->assertStringContainsString(
            '$autoStruct = $this->_auto_create_from_profile($staffId, $profile);',
            $body,
            'legacy and shadow mode must keep auto-creating exactly as before'
        );
        $this->assertStringContainsString(
            "if (\$autoStruct === null) \$this->json_error('Cannot recompute — no salary data for staff.');",
            $body
        );
    }

    /**
     * Whole-roster emptiness already fails closed at BOTH payroll entry points.
     * Checked rather than assumed, because the flags file warns that flipping
     * the cutover early "produces an employee with no salary, which is a silent
     * zero" — at the payroll boundary that is not what happens, and the guard
     * that makes it not happen must stay.
     */
    public function test_both_payroll_entry_points_refuse_an_empty_resolution(): void
    {
        foreach (['public function preflight_payroll', 'public function generate_payroll'] as $fn) {
            $i = strpos($this->hr, $fn);
            $this->assertNotFalse($i, "{$fn} not found");

            // Bounded at the NEXT method rather than by a character count. A
            // fixed window silently stopped short as these methods grew, and a
            // test that fails because the method got longer teaches the next
            // person to widen the assertion instead of reading it.
            $next = preg_match(
                '/\n    (?:public|private|protected)[^\n]*function /',
                $this->hr, $m, PREG_OFFSET_CAPTURE, (int) $i + 40
            ) ? (int) $m[0][1] : strlen($this->hr);

            $body = substr($this->hr, (int) $i, $next - (int) $i);

            $this->assertStringContainsString(
                "\$this->json_error('No salary structures found. Please set up salary structures first.');",
                $body,
                "{$fn} must refuse an empty resolution rather than run a zero payroll"
            );
        }
    }
}
