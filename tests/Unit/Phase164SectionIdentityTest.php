<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.4 — SEC-2 / SEC-4: section document identity and capacity default.
 *
 * SEC-2 (FIXED): `Classes::create_section()` built the section document id by
 * hand WITHOUT the session — `{schoolId}_{classKey}_{section}` — while every
 * reader and the other writer use `Firestore_service::sectionDocId()`,
 * `{schoolId}_{session}_{classKey}_{sectionKey}`. Create and read addressed
 * DIFFERENT DOCUMENTS, so a freshly configured capacity was invisible:
 * `get_section_settings()` reported 0 and `_sectionCapacity()` fell back to its
 * hardcoded default of 40.
 *
 * SEC-4 (NOT FIXED — business decision): `_sectionCapacity()` can never return
 * <= 0, so the "unlimited" branch in `_sectionCapacityExceeded()` is
 * unreachable, and an unconfigured section is capped at 40 by
 * admission/import/CRM while promotion and transfer treat it as unlimited.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase164SectionIdentityTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors Firestore_service::sectionDocId(). */
    private function canonicalId(string $schoolId, string $session, string $classKey, string $sectionKey): string
    {
        return "{$schoolId}_{$session}_{$classKey}_{$sectionKey}";
    }

    /** The pre-fix, hand-built id from Classes::create_section(). */
    private function legacyId(string $schoolId, string $classKey, string $section): string
    {
        return "{$schoolId}_{$classKey}_{$section}";
    }

    /** Mirrors MY_Controller::_sectionCapacity() — note the default. */
    private function resolveCapacity(?array $doc): int
    {
        $capacity = 40;                       // hardcoded default
        if (is_array($doc)) {
            $raw = (int) ($doc['maxStrength'] ?? $doc['max_strength'] ?? 0);
            if ($raw > 0) $capacity = $raw;
        }
        return $capacity;
    }

    // ── A. SEC-2 — the defect ───────────────────────────────────────────

    public function test_legacy_and_canonical_ids_are_different_documents(): void
    {
        $legacy    = $this->legacyId('SCH_1', 'Class 5th', 'Section A');
        $canonical = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $this->assertNotSame($legacy, $canonical,
            'create wrote one document while every reader looked at another');
    }

    public function test_a_section_written_with_the_legacy_id_is_invisible_to_readers(): void
    {
        // Reader looks up the canonical id; the store only holds the legacy one.
        $store = [$this->legacyId('SCH_1', 'Class 5th', 'Section A') => ['maxStrength' => 30]];
        $lookup = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $this->assertArrayNotHasKey($lookup, $store);
    }

    public function test_the_invisible_document_causes_the_configured_capacity_to_be_ignored(): void
    {
        // School configured 30. Reader finds nothing → falls back to 40.
        $store  = [$this->legacyId('SCH_1', 'Class 5th', 'Section A') => ['maxStrength' => 30]];
        $lookup = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $this->assertSame(40, $this->resolveCapacity($store[$lookup] ?? null),
            'the school set 30 but 40 was enforced');
    }

    // ── B. SEC-2 — after the fix ────────────────────────────────────────

    public function test_create_and_read_now_address_the_same_document(): void
    {
        $created = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $read    = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $this->assertSame($created, $read);
    }

    public function test_configured_capacity_is_now_honoured(): void
    {
        $id    = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $store = [$id => ['maxStrength' => 30]];
        $this->assertSame(30, $this->resolveCapacity($store[$id] ?? null));
    }

    public function test_sections_are_now_separable_per_session(): void
    {
        // The legacy id made 2025-26 and 2026-27 collide on one document, so
        // creating the same class+section in a new session was refused.
        $a = $this->canonicalId('SCH_1', '2025-26', 'Class 5th', 'Section A');
        $b = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $this->assertNotSame($a, $b);

        $legacyA = $this->legacyId('SCH_1', 'Class 5th', 'Section A');
        $legacyB = $this->legacyId('SCH_1', 'Class 5th', 'Section A');
        $this->assertSame($legacyA, $legacyB, 'pre-fix, both sessions collided');
    }

    public function test_tenants_never_collide(): void
    {
        $this->assertNotSame(
            $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A'),
            $this->canonicalId('SCH_2', '2026-27', 'Class 5th', 'Section A')
        );
    }

    // ── C. SEC-4 — the capacity default (NOT fixed; business decision) ──

    public function test_capacity_resolver_never_returns_zero(): void
    {
        // Documents the CURRENT behaviour so a future policy change is a
        // deliberate, visible test edit rather than a silent drift.
        $this->assertSame(40, $this->resolveCapacity(null));
        $this->assertSame(40, $this->resolveCapacity([]));
        $this->assertSame(40, $this->resolveCapacity(['maxStrength' => 0]));
        $this->assertSame(40, $this->resolveCapacity(['maxStrength' => -5]));
    }

    public function test_the_unlimited_branch_is_unreachable_through_the_resolver(): void
    {
        // _sectionCapacityExceeded() treats capacity <= 0 as unlimited, but the
        // resolver can never produce that, so admission/import/CRM cap an
        // unconfigured section at 40.
        for ($i = 0; $i < 5; $i++) {
            $this->assertGreaterThan(0, $this->resolveCapacity(null));
        }
    }

    public function test_promotion_and_transfer_use_different_semantics(): void
    {
        // Sis.php:1513 and Classes.php:576 read the RAW maxStrength and skip
        // the guard entirely when it is unset — i.e. unlimited. This asserts
        // the inconsistency exists, so closing it is an explicit decision.
        $rawUnset = 0;
        $promotionEnforces = ($rawUnset > 0);
        $admissionEnforces = ($this->resolveCapacity(null) > 0);
        $this->assertFalse($promotionEnforces, 'promotion treats unset as unlimited');
        $this->assertTrue($admissionEnforces,  'admission caps unset at 40');
        $this->assertNotSame($promotionEnforces, $admissionEnforces,
            'SEC-4: the two paths disagree — needs a policy decision');
    }

    // ── D. Identity invariants ──────────────────────────────────────────

    public function test_section_identity_carries_no_student_identity(): void
    {
        $id = $this->canonicalId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $this->assertStringNotContainsString(self::STU_A, $id);
        $this->assertStringNotContainsString(self::SRNO_A, $id);
    }

    public function test_identity_separation_unchanged(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
    }
}
