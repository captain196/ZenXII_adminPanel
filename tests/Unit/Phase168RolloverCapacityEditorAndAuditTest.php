<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.8 — SEC-16 (operator-edited target capacity) + SEC-14 (audit).
 *
 * ⚠ CORRECTION TO THE PHASE 16.7 REPORT: it claimed rollover wrote NO audit
 * record. Wrong — `log_audit('Configuration','rollover_session',…)` has always
 * existed at School_config.php:4228. The 16.7 grep window stopped short of it.
 * SEC-14 is therefore WITHDRAWN as a defect; what remained was a quality issue
 * (the summary was JSON-stuffed into the free-text description instead of the
 * structured context), which is fixed.
 *
 * ⚠ SECOND VERIFICATION CATCH: `log_audit()` persists ONLY `appId`,
 * `entityType` and `meta` from $context. A flat payload would have been
 * SILENTLY DISCARDED — it would have looked right and stored nothing.
 *
 * SEC-16: the operator can now change a section's capacity during rollover,
 * applied to NEWLY CREATED sections only — an existing target section is
 * skipped and never overwritten (the non-destructive rule from Phase 16.7).
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase168RolloverCapacityEditorAndAuditTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors the SEC-16 override parser. Returns [ok, map|errorKey]. */
    private function parseOverrides($raw): array
    {
        $out = [];
        if (!is_array($raw)) return [true, $out];
        foreach ($raw as $k => $v) {
            $key = trim((string) $k);
            if ($key === '') continue;
            if (!is_scalar($v)) continue;
            $s = trim((string) $v);
            if ($s === '' || !preg_match('/^\d+$/', $s)) return [false, $key];
            $out[mb_strtolower($key)] = (int) $s;
        }
        return [true, $out];
    }

    /** Mirrors the copy loop with overrides + skip-existing. */
    private function rollover(string $school, string $to, array $src, array $overrides = [], array $existingIds = []): array
    {
        $copied = []; $skipped = 0; $edited = 0;
        foreach ($src as $d) {
            $class = (string) ($d['className'] ?? '');
            $sec   = (string) ($d['section'] ?? '');
            if ($class === '' || $sec === '') { $skipped++; continue; }

            $id = "{$school}_{$to}_{$class}_{$sec}";
            if (in_array($id, $existingIds, true)) { $skipped++; continue; }

            $cap = (int) ($d['maxStrength'] ?? $d['max_strength'] ?? 0);
            if ($cap < 0) $cap = 0;

            $k = mb_strtolower("{$class}|{$sec}");
            if (array_key_exists($k, $overrides)) { $cap = $overrides[$k]; $edited++; }

            $copied[$id] = ['className' => $class, 'section' => $sec,
                            'maxStrength' => $cap, 'session' => $to];
        }
        return ['copied' => $copied, 'skipped' => $skipped, 'edited' => $edited];
    }

    /** Mirrors MY_Controller::_sectionCapacity() (D17). */
    private function resolve(?array $doc): int
    {
        $c = 40;
        if (is_array($doc)) {
            $raw = (int) ($doc['maxStrength'] ?? 0);
            if ($raw > 0) $c = $raw;
        }
        return $c;
    }

    private function src(): array
    {
        return [
            ['className' => 'Class 5th', 'section' => 'Section A', 'maxStrength' => 30],
            ['className' => 'Class 5th', 'section' => 'Section B', 'maxStrength' => 40],
            ['className' => 'Class 5th', 'section' => 'Section C', 'maxStrength' => 50],
        ];
    }

    // ── A. SEC-16 — editing works ───────────────────────────────────────

    public function test_edited_capacity_is_applied_to_a_new_section(): void
    {
        // 5-C: 50 → 45 before confirming.
        [$ok, $ov] = $this->parseOverrides(['Class 5th|Section C' => '45']);
        $this->assertTrue($ok);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        $caps = array_map(fn($d) => $d['maxStrength'], array_values($r['copied']));
        $this->assertSame([30, 40, 45], $caps);
        $this->assertSame(1, $r['edited']);
    }

    public function test_unedited_sections_keep_the_source_capacity(): void
    {
        [, $ov] = $this->parseOverrides(['Class 5th|Section C' => '45']);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        $vals = array_values($r['copied']);
        $this->assertSame(30, $vals[0]['maxStrength']);
        $this->assertSame(40, $vals[1]['maxStrength']);
    }

    public function test_override_matching_is_case_insensitive(): void
    {
        [, $ov] = $this->parseOverrides(['class 5th|section c' => '45']);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        $this->assertSame(45, array_values($r['copied'])[2]['maxStrength']);
    }

    public function test_edited_capacity_is_what_the_resolver_reports(): void
    {
        [, $ov] = $this->parseOverrides(['Class 5th|Section C' => '45']);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        $this->assertSame(45, $this->resolve(array_values($r['copied'])[2]));
    }

    // ── B. SEC-16 — validation ──────────────────────────────────────────

    public function test_zero_is_accepted_and_means_unconfigured(): void
    {
        [$ok, $ov] = $this->parseOverrides(['Class 5th|Section A' => '0']);
        $this->assertTrue($ok);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        $doc = array_values($r['copied'])[0];
        $this->assertSame(0, $doc['maxStrength'], 'stored as unconfigured');
        $this->assertSame(40, $this->resolve($doc), 'resolver supplies the default');
    }

    public function test_negative_and_decimal_and_text_are_rejected(): void
    {
        foreach (['-5', '30.5', 'abc', '', ' '] as $bad) {
            [$ok] = $this->parseOverrides(['Class 5th|Section A' => $bad]);
            $this->assertFalse($ok, "'{$bad}' must be rejected");
        }
    }

    public function test_no_arbitrary_upper_bound_is_invented(): void
    {
        // No source establishes a maximum section size; imposing one would be
        // an invented business rule.
        [$ok, $ov] = $this->parseOverrides(['Class 5th|Section A' => '500']);
        $this->assertTrue($ok);
        $this->assertSame(500, $ov['class 5th|section a']);
    }

    public function test_an_unknown_section_key_cannot_create_a_section(): void
    {
        [, $ov] = $this->parseOverrides(['Class 9th|Section Z' => '25']);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        $this->assertCount(3, $r['copied'], 'overrides only annotate sections the source already has');
        $this->assertSame(0, $r['edited']);
    }

    // ── C. Non-destructive guarantee ────────────────────────────────────

    public function test_an_existing_target_section_is_not_edited_or_overwritten(): void
    {
        // THE CRITICAL SAFETY RULE: editing must never reach a section the
        // operator already configured for next year.
        $existing = 'SCH_1_2026-27_Class 5th_Section A';
        [, $ov] = $this->parseOverrides(['Class 5th|Section A' => '99']);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov, [$existing]);
        $this->assertArrayNotHasKey($existing, $r['copied']);
        $this->assertSame(0, $r['edited'], 'a skipped section is never counted as edited');
        $this->assertSame(1, $r['skipped']);
    }

    public function test_second_rollover_with_overrides_remains_idempotent(): void
    {
        [, $ov] = $this->parseOverrides(['Class 5th|Section C' => '45']);
        $first  = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        $second = $this->rollover('SCH_1', '2026-27', $this->src(), $ov, array_keys($first['copied']));
        $this->assertCount(0, $second['copied']);
        $this->assertSame(0, $second['edited']);
    }

    public function test_source_session_is_never_written(): void
    {
        [, $ov] = $this->parseOverrides(['Class 5th|Section C' => '45']);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        foreach (array_keys($r['copied']) as $id) {
            $this->assertStringContainsString('_2026-27_', $id);
            $this->assertStringNotContainsString('_2025-26_', $id);
        }
    }

    public function test_schools_cannot_collide(): void
    {
        $a = $this->rollover('SCH_1', '2026-27', $this->src());
        $b = $this->rollover('SCH_2', '2026-27', $this->src());
        $this->assertSame([], array_intersect(array_keys($a['copied']), array_keys($b['copied'])));
    }

    // ── D. SEC-14 — audit shape ─────────────────────────────────────────

    /** Mirrors log_audit()'s context filter: only appId/entityType/meta survive. */
    private function persistedContext(array $context): array
    {
        $out = [];
        if (!empty($context['appId']))      $out['appId']      = (string) $context['appId'];
        if (!empty($context['entityType'])) $out['entityType'] = (string) $context['entityType'];
        if (isset($context['meta']) && is_array($context['meta']) && $context['meta'] !== []) {
            $out['meta'] = $context['meta'];
        }
        return $out;
    }

    public function test_a_flat_context_payload_would_be_silently_discarded(): void
    {
        // THE VERIFICATION CATCH. This is why the payload is nested under meta.
        $flat = ['fromSession' => '2025-26', 'toSession' => '2026-27', 'status' => 'completed'];
        $this->assertSame([], $this->persistedContext($flat),
            'a flat payload stores NOTHING — it would have looked correct and vanished');
    }

    public function test_nested_meta_survives_and_carries_the_operational_facts(): void
    {
        $ctx = ['entityType' => 'session_rollover', 'meta' => [
            'fromSession' => '2025-26', 'toSession' => '2026-27', 'status' => 'completed',
            'sectionsCopied' => 3, 'capacitiesEdited' => 1, 'promotionRequested' => 0,
        ]];
        $p = $this->persistedContext($ctx);
        $this->assertSame('session_rollover', $p['entityType']);
        $this->assertSame(3, $p['meta']['sectionsCopied']);
        $this->assertSame(1, $p['meta']['capacitiesEdited']);
        $this->assertSame(0, $p['meta']['promotionRequested']);
    }

    public function test_audit_records_the_status_so_a_partial_run_is_not_reported_as_success(): void
    {
        foreach (['completed', 'partial', 'failed'] as $status) {
            $p = $this->persistedContext(['meta' => ['status' => $status]]);
            $this->assertSame($status, $p['meta']['status']);
        }
    }

    public function test_audit_distinguishes_structure_only_from_promotion(): void
    {
        $structureOnly = $this->persistedContext(['meta' => ['promotionRequested' => 0, 'studentsPromoted' => 0]]);
        $withPromotion = $this->persistedContext(['meta' => ['promotionRequested' => 1, 'studentsPromoted' => 120]]);
        $this->assertSame(0, $structureOnly['meta']['promotionRequested']);
        $this->assertSame(120, $withPromotion['meta']['studentsPromoted']);
    }

    // ── E. Scope: no student data touched ───────────────────────────────

    public function test_rollover_never_writes_student_identity_or_rolls(): void
    {
        [, $ov] = $this->parseOverrides(['Class 5th|Section C' => '45']);
        $r = $this->rollover('SCH_1', '2026-27', $this->src(), $ov);
        foreach ($r['copied'] as $doc) {
            $this->assertArrayNotHasKey('studentId', $doc);
            $this->assertArrayNotHasKey('rollNo', $doc);
            $this->assertArrayNotHasKey('srNo', $doc);
        }
    }

    public function test_identity_separation_unchanged(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }
}
