<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURE 1 — the product layer around the statutory wage classification.
 *
 * The engine could already classify a component. What it could not do was let a
 * school RECORD a determination: which classification, from when, why, and on
 * whose authority. Without that, a classification was either a hard-coded
 * default or an undated override that would silently reprice history the moment
 * anybody corrected it.
 *
 * WHY DATING IS THE POINT
 * -----------------------
 * A CA's determination under the universality test is a statement about a point
 * in time. An allowance can genuinely become universal — a school starts paying
 * it to everyone — or stop being so. Overwriting the classification would
 * reprice every past period retroactively, which is exactly the failure the
 * statutory-config schedule already exists to prevent.
 *
 * These exercise the REAL library and the REAL component service. No arithmetic
 * or resolution logic is reimplemented here.
 */
final class ComponentClassificationProductTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
        require_once __DIR__ . '/../../application/libraries/Statutory_wage_base.php';
    }

    /** A component whose classification changed mid-year. */
    private function scheduled(): array
    {
        return ['wageClassificationSchedule' => [
            [
                'classification' => 'CONDITIONAL',
                'effectiveFrom'  => '2026-04-01',
                'effectiveTo'    => '2026-07-31',
                'reason'         => 'paid only to senior staff',
                'verifiedBy'     => 'CA Alpha',
                'verifiedAt'     => '2026-03-28',
            ],
            [
                'classification' => 'INCLUDE',
                'effectiveFrom'  => '2026-08-01',
                'effectiveTo'    => null,
                'reason'         => 'extended to all staff — universality test now met',
                'verifiedBy'     => 'CA Beta',
                'verifiedAt'     => '2026-07-25',
            ],
        ]];
    }

    /** A valid base component, so each test varies only what it is about. */
    private function baseComponent(array $over = []): array
    {
        return array_merge([
            'code'         => 'HRA',
            'name'         => 'House Rent Allowance',
            'kind'         => 'earning',
            'calcType'     => 'FIXED',
            'amountPaise'  => 0,
            'displayOrder' => 20,
        ], $over);
    }

    private function svc(): Salary_component_service
    {
        require_once __DIR__ . '/../../application/libraries/Salary_component_service.php';
        return new Salary_component_service();
    }

    // ── Effective-dated resolution ───────────────────────────────────────

    /**
     * THE LOAD-BEARING TEST. The same component resolves differently for two
     * periods, because the determination changed between them.
     */
    public function test_a_classification_resolves_per_period(): void
    {
        $row = $this->scheduled();

        list($june,) = Statutory_wage_base::classify('OTHER_ALLOWANCES', $row, '2026-06-30');
        list($sept,) = Statutory_wage_base::classify('OTHER_ALLOWANCES', $row, '2026-09-30');

        $this->assertSame('CONDITIONAL', $june);
        $this->assertSame('INCLUDE', $sept);
    }

    /**
     * And appending a determination LATER must not change the earlier answer.
     * This is what makes a closed period reproducible.
     */
    public function test_a_later_determination_does_not_reprice_an_earlier_period(): void
    {
        $before = $this->scheduled();
        list($juneBefore,) = Statutory_wage_base::classify('OTHER_ALLOWANCES', $before, '2026-06-30');

        $after = $before;
        $after['wageClassificationSchedule'][1]['effectiveTo'] = '2026-11-30';
        $after['wageClassificationSchedule'][] = [
            'classification' => 'EXCLUDE',
            'effectiveFrom'  => '2026-12-01',
            'effectiveTo'    => null,
            'reason'         => 'converted to reimbursement of actuals',
        ];

        list($juneAfter,) = Statutory_wage_base::classify('OTHER_ALLOWANCES', $after, '2026-06-30');

        $this->assertSame($juneBefore, $juneAfter,
            'appending a future determination must leave every earlier period untouched');
    }

    /** A date before any determination falls back to the statutory default. */
    public function test_a_period_before_any_determination_uses_the_statutory_default(): void
    {
        $d = Statutory_wage_base::classificationDetail('HRA', $this->scheduled(), '2026-01-01');

        $this->assertSame('CONDITIONAL', $d['classification'],
            'HRA is a named exclusion in s.2(y), so that is the default');
        $this->assertSame('statutory_default', $d['source']);
    }

    // ── The detail record — what an audit needs ──────────────────────────

    /**
     * Resolution must report WHY, not merely what. "Your PF changed in August"
     * is only answerable if the determination that changed it is recoverable.
     */
    public function test_resolution_reports_its_provenance(): void
    {
        $d = Statutory_wage_base::classificationDetail('OTHER_ALLOWANCES', $this->scheduled(), '2026-09-30');

        $this->assertSame('INCLUDE', $d['classification']);
        $this->assertSame('schedule', $d['source']);
        $this->assertSame('2026-08-01', $d['effectiveFrom']);
        $this->assertSame('extended to all staff — universality test now met', $d['reason']);
        $this->assertSame('CA Beta', $d['verifiedBy']);
        $this->assertSame('2026-07-25', $d['verifiedAt']);
    }

    /** An undated override is still honoured, and reported as an override. */
    public function test_an_undated_override_is_honoured_and_labelled(): void
    {
        $d = Statutory_wage_base::classificationDetail(
            'MEDICAL',
            ['wageClassification' => 'EXCLUDE',
             'wageClassificationReason' => 'reimbursement against bills'],
            '2026-09-30'
        );

        $this->assertSame('EXCLUDE', $d['classification']);
        $this->assertSame('override', $d['source']);
        $this->assertTrue($d['explicit']);
        $this->assertSame('reimbursement against bills', $d['reason']);
    }

    /** A schedule outranks a flat override — the dated statement is more specific. */
    public function test_a_schedule_outranks_a_flat_override(): void
    {
        $row = $this->scheduled();
        $row['wageClassification'] = 'EXCLUDE';

        list($class,) = Statutory_wage_base::classify('OTHER_ALLOWANCES', $row, '2026-09-30');

        $this->assertSame('INCLUDE', $class,
            'the dated determination for that period must win over an undated one');
    }

    /** With no as-of date there is nothing to resolve a schedule against. */
    public function test_without_a_date_the_schedule_is_not_consulted(): void
    {
        $row = $this->scheduled();
        $row['wageClassification'] = 'EXCLUDE';

        list($class,) = Statutory_wage_base::classify('OTHER_ALLOWANCES', $row);

        $this->assertSame('EXCLUDE', $class,
            'a schedule cannot be resolved without a period; the flat override stands');
    }

    // ── It reaches the wage base ─────────────────────────────────────────

    /**
     * The determination must actually change the computed base — otherwise the
     * whole product layer is decoration.
     */
    public function test_the_determination_changes_the_computed_wage_base(): void
    {
        $structure = ['basic' => 25000, 'other_allowances' => 25000];
        $catalogue = ['other_allowances' => $this->scheduled()];

        $june = Statutory_wage_base::computeFromComponents(
            Statutory_wage_base::classifyLegacyStructure($structure, $catalogue, '2026-06-30')
        );
        $sept = Statutory_wage_base::computeFromComponents(
            Statutory_wage_base::classifyLegacyStructure($structure, $catalogue, '2026-09-30')
        );

        $this->assertSame(2500000, $june['basePaise'],
            'in June the allowance was CONDITIONAL — basic only, and exactly at the threshold');
        $this->assertSame(5000000, $sept['basePaise'],
            'from August it is wages, so it joins the base');
    }

    /** The legacy structure classifier threads the period through. */
    public function test_the_legacy_classifier_accepts_an_as_of_date(): void
    {
        $set = Statutory_wage_base::classifyLegacyStructure(
            ['other_allowances' => 1000],
            ['other_allowances' => $this->scheduled()],
            '2026-06-30'
        );

        $this->assertSame('CONDITIONAL', $set[0]['classification']);
    }

    // ── Validation, through the real component service ───────────────────

    /**
     * Departing from the statutory default requires a reason. An unexplained
     * reclassification is indistinguishable from a mistake a year later, and
     * this one moves money.
     */
    public function test_an_unexplained_override_of_the_statutory_default_is_refused(): void
    {
        list(, $errors) = $this->svc()->validate($this->baseComponent([
            'code' => 'DA', 'name' => 'Dearness Allowance', 'displayOrder' => 30,
            'wageClassification' => 'EXCLUDE',   // DA is INCLUDE by statute
        ]), true);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('requires a reason', implode(' ', $errors));
    }

    /** With a reason, the same override is accepted. */
    public function test_an_explained_override_is_accepted(): void
    {
        list(, $errors) = $this->svc()->validate($this->baseComponent([
            'code' => 'DA', 'name' => 'Dearness Allowance', 'displayOrder' => 30,
            'wageClassification' => 'EXCLUDE',
            'wageClassificationReason' => 'CA determination: paid as reimbursement here',
        ]), true);

        $this->assertSame([], $errors, implode(' ', $errors));
    }

    /** An invalid classification value is refused outright. */
    public function test_an_invalid_classification_is_refused(): void
    {
        list(, $errors) = $this->svc()->validate($this->baseComponent([
            'wageClassification' => 'SOMETIMES',
        ]), true);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('wageClassification must be one of', implode(' ', $errors));
    }

    /**
     * OVERLAPPING determinations are refused. Two rows covering one date make a
     * payroll run's basis depend on read order, and a closed period could
     * resolve differently on re-read.
     */
    public function test_overlapping_determinations_are_refused(): void
    {
        list(, $errors) = $this->svc()->validate($this->baseComponent([
            'wageClassificationSchedule' => [
                ['classification' => 'CONDITIONAL', 'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-09-30'],
                ['classification' => 'INCLUDE',     'effectiveFrom' => '2026-08-01', 'effectiveTo' => null],
            ],
        ]), true);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('overlap', strtolower(implode(' ', $errors)));
    }

    /** A schedule row with a calendar-invalid date is refused, not silently dropped. */
    public function test_a_malformed_schedule_date_is_refused(): void
    {
        list(, $errors) = $this->svc()->validate($this->baseComponent([
            'wageClassificationSchedule' => [
                ['classification' => 'INCLUDE', 'effectiveFrom' => '2026-02-30'],
            ],
        ]), true);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ISO date', implode(' ', $errors));
    }

    /** An end date before the start date is refused. */
    public function test_a_reversed_schedule_window_is_refused(): void
    {
        list(, $errors) = $this->svc()->validate($this->baseComponent([
            'wageClassificationSchedule' => [
                ['classification' => 'INCLUDE', 'effectiveFrom' => '2026-08-01', 'effectiveTo' => '2026-04-01'],
            ],
        ]), true);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('before effectiveFrom', implode(' ', $errors));
    }

    /** A well-formed schedule survives validation and is normalised. */
    public function test_a_valid_schedule_is_accepted_and_normalised(): void
    {
        list($clean, $errors) = $this->svc()->validate($this->baseComponent([
            'wageClassificationSchedule' => [
                ['classification' => 'conditional', 'effectiveFrom' => '2026-04-01',
                 'effectiveTo' => '2026-07-31', 'reason' => 'senior staff only'],
                ['classification' => 'include', 'effectiveFrom' => '2026-08-01',
                 'effectiveTo' => '', 'reason' => 'now universal'],
            ],
        ]), true);

        $this->assertSame([], $errors, implode(' ', $errors));
        $this->assertCount(2, $clean['wageClassificationSchedule']);
        $this->assertSame('CONDITIONAL', $clean['wageClassificationSchedule'][0]['classification'],
            'classifications must be upper-cased on the way in');
        $this->assertNull($clean['wageClassificationSchedule'][1]['effectiveTo'],
            'an empty end date must normalise to null — an open window, not an empty string');
    }

    // ── The admin surface exists and is protected ────────────────────────

    /** Both endpoints exist, and the write one requires manage. */
    public function test_the_endpoints_exist_and_are_rbac_protected(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Compensation.php'
        );

        $this->assertStringContainsString('public function get_component_classifications(): void', $src);
        $this->assertStringContainsString('public function save_component_classification(): void', $src);

        $i = strpos($src, 'public function save_component_classification(): void');
        $body = substr($src, (int) $i, 900);
        $this->assertStringContainsString('$this->_require_manage();', $body,
            'a classification change moves money — viewing is not enough');
    }

    /** And they are routed — compensation routes are declared explicitly. */
    public function test_the_endpoints_are_routed(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');

        foreach (['get_component_classifications', 'save_component_classification'] as $ep) {
            $this->assertStringContainsString("compensation/{$ep}", $routes,
                "compensation routes are explicit — without an entry {$ep} is unreachable");
        }
    }

    /** A determination is audited, because it changes somebody's contribution. */
    public function test_a_determination_is_audited(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Compensation.php'
        );

        $this->assertStringContainsString(
            "log_audit('Compensation', 'component_classification_set'", $src
        );
    }

    /**
     * Saving APPENDS a dated row and closes the previous open one, rather than
     * overwriting. Overwriting is the behaviour this whole feature exists to
     * prevent.
     */
    public function test_saving_appends_and_closes_rather_than_overwriting(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Compensation.php'
        );

        $i = strpos($src, 'public function save_component_classification(): void');
        $body = substr($src, (int) $i, 4200);

        $this->assertStringContainsString("\$row['effectiveTo'] = \$dayBefore;", $body,
            'the previous open determination must be closed the day before the new one starts');
        $this->assertStringContainsString('$schedule[] = [', $body,
            'a new determination must be appended, never substituted');
        $this->assertStringContainsString("ed_find_overlaps(\$schedule, 'classification')", $body,
            'and the result must be checked for overlap before it is stored');
    }
}
