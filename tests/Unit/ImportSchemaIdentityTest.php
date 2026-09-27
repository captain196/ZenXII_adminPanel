<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Sr_no_service.php';
require_once __DIR__ . '/../../application/libraries/Apaar_service.php';
require_once __DIR__ . '/../../application/libraries/Import_schema.php';

/**
 * Phase 9.2 (2026-08-13) — pure, service-free tests for Import_schema's new
 * SR Number / APAAR ID / Roll No handling: normalization, format
 * validation, and import-mode-aware required-ness. Import_schema has no CI
 * instance dependency (confirmed by source read — no get_instance()/
 * $this->load anywhere), so it is directly instantiable here, same as
 * every other pure-logic test in this suite.
 *
 * Firestore-dependent behavior (the actual atomic claim/allocate calls,
 * historical-conflict rejection against existing data, cross-tenant
 * isolation) is covered by the ALREADY-EXISTING SrNoServiceTest/
 * ApaarServiceTest/*MultiTenantTest suites — Import_schema/Sis.php reuse
 * those exact primitives rather than reimplementing them, so this file
 * does not re-prove atomicity/concurrency/tenant-isolation, only the
 * schema/validation layer that sits in front of them.
 */
class ImportSchemaIdentityTest extends TestCase
{
    private function schema(): \Import_schema
    {
        return new \Import_schema();
    }

    // ── Field catalogue ──────────────────────────────────────────────────

    public function test_schema_now_includes_sr_number_apaar_and_roll_no(): void
    {
        $keys = array_column($this->schema()->fieldsForUi(), 'key');
        $this->assertContains('SR Number', $keys);
        $this->assertContains('APAAR ID', $keys);
        $this->assertContains('Roll No', $keys);
    }

    public function test_none_of_the_three_new_fields_are_unconditionally_required(): void
    {
        // Required-ness for SR Number is conditional on import mode, which
        // this pure per-field flag cannot express — enforced instead in
        // validateRow()'s mode-aware branch, tested below.
        foreach ($this->schema()->fieldsForUi() as $f) {
            if (in_array($f['key'], ['SR Number', 'APAAR ID', 'Roll No'], true)) {
                $this->assertFalse($f['required'], "{$f['key']} must not be unconditionally required");
            }
        }
    }

    // ── Normalization — delegates to the canonical services, not reinvented ──

    public function test_sr_number_normalization_matches_sr_no_service_exactly(): void
    {
        $schema = $this->schema();
        foreach (['501', '0501', '00501'] as $raw) {
            $this->assertSame(
                \Sr_no_service::normalize($raw)['display'],
                $schema->normalizeValue('SR Number', $raw)
            );
        }
    }

    public function test_sr_number_normalization_rejects_non_digit_input(): void
    {
        $schema = $this->schema();
        foreach (['SR-501', '501 A', ''] as $raw) {
            $this->assertSame('', $schema->normalizeValue('SR Number', $raw));
        }
    }

    public function test_apaar_normalization_matches_apaar_service_exactly(): void
    {
        $schema = $this->schema();
        $this->assertSame(
            \Apaar_service::normalize('1234 5678 9012'),
            $schema->normalizeValue('APAAR ID', '1234 5678 9012')
        );
        $this->assertSame('123456789012', $schema->normalizeValue('APAAR ID', '1234-5678-9012'));
    }

    public function test_apaar_normalization_rejects_wrong_length(): void
    {
        $this->assertSame('', $this->schema()->normalizeValue('APAAR ID', '12345'));
    }

    public function test_roll_no_is_free_text_not_forced_numeric(): void
    {
        // Matches save_admission()'s own existing Roll No handling —
        // alphanumeric input is preserved as-is, never coerced to 0 or
        // rejected (the audited existing architecture has no numeric-only
        // rule for this field anywhere).
        $schema = $this->schema();
        $this->assertSame('5A-12', $schema->normalizeValue('Roll No', '5A-12'));
        $this->assertSame('7', $schema->normalizeValue('Roll No', '7'));
    }

    // ── validateRow() — mode-aware SR Number required-ness ───────────────

    public function test_historical_mode_requires_sr_number(): void
    {
        $v = $this->schema()->validateRow(['Name' => 'Rahul', 'Class' => '5', 'Section' => 'A'], 'historical');
        $this->assertSame('error', $v['status']);
        $this->assertTrue(in_array('SR Number is required in Historical / Existing Register Data mode', $v['errors'], true));
    }

    public function test_historical_mode_accepts_a_valid_sr_number(): void
    {
        $v = $this->schema()->validateRow(['Name' => 'Rahul', 'Class' => '5', 'Section' => 'A', 'SR Number' => '501'], 'historical');
        $this->assertSame('ok', $v['status']);
        $this->assertSame('501', $v['data']['SR Number']);
    }

    public function test_new_mode_does_not_require_sr_number(): void
    {
        $v = $this->schema()->validateRow(['Name' => 'Amit', 'Class' => '5', 'Section' => 'A'], 'new');
        $this->assertSame('ok', $v['status']);
    }

    public function test_new_mode_ignores_an_operator_supplied_sr_number_with_a_warning_not_an_error(): void
    {
        // Part 26 test #26: "New-admission mode does not accept operator
        // SRNO" — proven here as: the value is blanked out (never reaches
        // the importer) and the row is a WARNING, not a hard error (a
        // stray value shouldn't block an otherwise-valid new admission).
        $v = $this->schema()->validateRow(['Name' => 'Amit', 'Class' => '5', 'Section' => 'A', 'SR Number' => '999'], 'new');
        $this->assertSame('warning', $v['status']);
        $this->assertSame('', $v['data']['SR Number'], 'the supplied SR Number must be blanked, never silently used for allocation');
    }

    public function test_unspecified_mode_neither_requires_nor_blanks_sr_number(): void
    {
        // The unspecified-mode path ('') is what the legacy, confirmed-
        // dead-and-unreachable exact-template import still uses — this
        // documents that pre-Phase-9.2 behavior is unchanged for it, not
        // that it is a recommended mode for new callers.
        $v = $this->schema()->validateRow(['Name' => 'X', 'Class' => '5', 'Section' => 'A', 'SR Number' => '501']);
        $this->assertSame('ok', $v['status']);
        $this->assertSame('501', $v['data']['SR Number']);
    }

    public function test_invalid_sr_number_format_is_an_error_regardless_of_mode(): void
    {
        foreach (['historical', 'new', ''] as $mode) {
            $v = $this->schema()->validateRow(['Name' => 'X', 'Class' => '5', 'Section' => 'A', 'SR Number' => 'ABC'], $mode);
            $this->assertSame('error', $v['status'], "mode=[{$mode}]");
        }
    }

    public function test_invalid_apaar_format_is_an_error_regardless_of_mode(): void
    {
        foreach (['historical', 'new', ''] as $mode) {
            $v = $this->schema()->validateRow(['Name' => 'X', 'Class' => '5', 'Section' => 'A', 'APAAR ID' => '12345'], $mode);
            $this->assertSame('error', $v['status'], "mode=[{$mode}]");
        }
    }

    public function test_apaar_is_optional_in_both_modes(): void
    {
        foreach (['historical', 'new'] as $mode) {
            $mapped = ['Name' => 'X', 'Class' => '5', 'Section' => 'A'];
            if ($mode === 'historical') $mapped['SR Number'] = '501';
            $v = $this->schema()->validateRow($mapped, $mode);
            $this->assertSame('ok', $v['status'], "APAAR must remain optional in mode=[{$mode}]");
        }
    }

    public function test_blank_roll_no_is_always_valid(): void
    {
        $v = $this->schema()->validateRow(['Name' => 'X', 'Class' => '5', 'Section' => 'A'], 'new');
        $this->assertSame('ok', $v['status']);
        $this->assertSame('', $v['data']['Roll No']);
    }

    /**
     * The downloadable template must carry one example value per column.
     *
     * 2026-09-13 — templateHeaders() emitted 28 columns while exampleRow()
     * returned 25, so import_template() wrote a ragged CSV and the three
     * identifier columns (SR Number, APAAR ID, Roll No) had no worked example.
     * A school choosing Historical mode — where SR Number is REQUIRED on every
     * row — was shown a blank cell for the one field it had to supply.
     */
    public function test_the_template_example_row_covers_every_column(): void
    {
        $schema  = new \Import_schema();
        $headers = $schema->templateHeaders();
        $example = $schema->exampleRow();

        $this->assertCount(count($headers), $example,
            'the template example row does not line up with its headers, so the '
            . 'downloaded CSV is ragged and the trailing columns have no example');

        $row = array_combine($headers, $example);
        foreach (['SR Number', 'APAAR ID', 'Roll No'] as $field) {
            $this->assertArrayHasKey($field, $row);
            $this->assertNotSame('', trim((string) $row[$field]),
                "the template gives no example for {$field}");
        }

        // An example that would fail our own validation teaches the wrong format.
        $this->assertNotSame('', $schema->normalizeSrNumber($row['SR Number']),
            'the SR Number example does not satisfy Sr_no_service format rules');
        $this->assertNotSame('', $schema->normalizeApaar($row['APAAR ID']),
            'the APAAR example does not satisfy Apaar_service format rules');
    }
}
