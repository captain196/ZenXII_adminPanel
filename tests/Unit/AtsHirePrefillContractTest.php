<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ATS-D1 — the recruitment -> employee seam.
 *
 * `Ats::get_convert_data()` is a PRODUCER: it returns a `prefill` map that the
 * new-staff screen consumes through a `fieldMap`. Nothing links the two, so a
 * key can be added on one side and silently ignored on the other — which is
 * exactly what happened to `staff_position`, the job title the candidate was
 * actually hired for. The screen announced itself as pre-filled and left the
 * canonical designation blank.
 *
 * The assertion is the PROPERTY — every key the producer emits is consumed —
 * not a hardcoded list, so the next key added to get_convert_data() has to be
 * wired up rather than quietly dropped.
 */
final class AtsHirePrefillContractTest extends TestCase
{
    private static string $ats  = '';
    private static string $form = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$ats  = (string) file_get_contents($root . 'application/controllers/Ats.php');
        self::$form = (string) file_get_contents($root . 'application/views/new_staff.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$ats = self::$form = '';
    }

    /** The keys `get_convert_data()` puts into its `prefill` payload. */
    private function producedKeys(): array
    {
        $i = strpos(self::$ats, 'public function get_convert_data');
        $this->assertNotFalse($i, 'get_convert_data() has moved or been renamed');

        $j = strpos(self::$ats, "'prefill'", $i);
        $this->assertNotFalse($j, 'get_convert_data() no longer returns a prefill payload');

        // The payload literal: from `'prefill' => [` to its closing bracket.
        $open = strpos(self::$ats, '[', $j);
        $body = substr(self::$ats, (int) $open, 900);
        $end  = strpos($body, '],');
        $body = substr($body, 0, $end === false ? 900 : $end);

        preg_match_all("~'([A-Za-z_][A-Za-z_0-9]*)'\s*=>~", $body, $m);
        $keys = array_values(array_unique($m[1]));

        $this->assertNotEmpty($keys, 'could not read the prefill keys');
        return $keys;
    }

    /** The keys the new-staff screen actually maps onto form fields. */
    private function consumedKeys(): array
    {
        $i = strpos(self::$form, 'var fieldMap = {');
        $this->assertNotFalse($i, 'the ATS prefill fieldMap has moved');

        $body = substr(self::$form, (int) $i, 1200);
        $end  = strpos($body, '};');
        $body = substr($body, 0, $end === false ? 1200 : $end);

        preg_match_all("~'([A-Za-z_][A-Za-z_0-9]*)'\s*:~", $body, $m);
        $keys = $m[1];

        // `department` is handled separately below the map because the select
        // loads asynchronously — count it as consumed.
        if (strpos(self::$form, "if (pf['department'])") !== false) {
            $keys[] = 'department';
        }
        return array_values(array_unique($keys));
    }

    /** DEFECT ATS-D1: `staff_position` was produced and never consumed. */
    public function test_every_prefilled_field_is_actually_consumed(): void
    {
        $dropped = array_values(array_diff($this->producedKeys(), $this->consumedKeys()));

        $this->assertSame([], $dropped,
            'get_convert_data() emits these keys but the new-staff screen ignores them, '
            . 'so the operator sees a "pre-filled" form with blank fields: '
            . implode(', ', $dropped));
    }

    /**
     * The specific regression: the job title reaches `designation`, which is
     * the canonical label. Asserted by name because of what depends on it.
     */
    public function test_the_job_title_lands_on_the_canonical_designation_field(): void
    {
        $this->assertStringContainsString("'staff_position': 'designation'", self::$form);

        // The producer really does send the job title under that key.
        $this->assertStringContainsString("'staff_position' =>", self::$ats);

        // And the form has that field for it to land on.
        $this->assertStringContainsString('id="designation" name="designation"', self::$form);
    }

    /**
     * Why a blank designation is not cosmetic: it is the canonical label, and
     * an empty one silently degrades to a generic role name.
     */
    public function test_a_blank_designation_degrades_to_a_generic_role_label(): void
    {
        $staff = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');

        $this->assertStringContainsString(
            '$positionLabel = $designationInput !== \'\'', $staff,
            'the fallback this defect used to trigger has moved — re-check the impact');
        $this->assertStringContainsString('_infer_roles_from_position', $staff);
    }
}
