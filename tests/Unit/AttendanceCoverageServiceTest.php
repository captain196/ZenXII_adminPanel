<?php
/**
 * PHASE C2 — Substitute / Coverage authorisation predicate.
 *
 * Student attendance is `allow write: if false` in firestore.rules; the entire
 * write boundary is PHP. Coverage widens that boundary for exactly one
 * substitute, one section, one date — and NOTHING else. These tests pin that
 * scope so a future edit cannot silently turn coverage into a "any teacher can
 * mark any section" switch.
 *
 * The service is pure over its injected Firestore_helper, so an in-memory fake
 * exercises the real logic with no emulator.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Attendance_coverage_service.php';

/** Minimal in-memory stand-in for Firestore_helper. */
final class _FakeFsCoverage
{
    public array $store = [];          // "collection/id" => data
    private string $school;
    private string $session;
    public function __construct(string $school, string $session) { $this->school = $school; $this->session = $session; }
    public function get(string $c, string $id): ?array { return $this->store["$c/$id"] ?? null; }
    public function set(string $c, string $id, array $d, bool $m = false): bool {
        $this->store["$c/$id"] = $m && isset($this->store["$c/$id"]) ? array_merge($this->store["$c/$id"], $d) : $d;
        return true;
    }
    public function update(string $c, string $id, array $d): bool {
        if (!isset($this->store["$c/$id"])) return false;
        $this->store["$c/$id"] = array_merge($this->store["$c/$id"], $d); return true;
    }
    /** Mirrors Firestore_service::schoolWhere (schoolId equality only). */
    public function schoolWhere(string $c, array $extra = [], $ob = null, $dir = 'ASC', $lim = null): array {
        $out = [];
        foreach ($this->store as $k => $v) {
            if (strpos($k, "$c/") !== 0) continue;
            if (($v['schoolId'] ?? '') !== $this->school) continue;
            $ok = true;
            foreach ($extra as [$f, $op, $val]) { if (($v[$f] ?? null) !== $val) { $ok = false; break; } }
            if ($ok) $out[] = $v;
        }
        return $out;
    }
}

final class AttendanceCoverageServiceTest extends TestCase
{
    private const SCHOOL = 'SATEST_A';
    private const SESSION = '2026-27';
    private const SUB = 'TCHR_SUB01';

    private function svc(_FakeFsCoverage $fs): Attendance_coverage_service
    {
        return (new Attendance_coverage_service())->init($fs, self::SCHOOL, self::SESSION);
    }

    // ── The happy path grants exactly what was asked, and nothing more ──

    public function test_grant_then_can_cover_exact_date_section(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub Teacher', 'ADMIN01', 'Admin', 'sick leave');

        $this->assertTrue($s->can_cover(self::SUB, 'Class 8th', 'Section A', '2026-09-14'),
            'the granted substitute must be able to cover the granted section on the granted date');
    }

    public function test_class_section_label_variants_normalise_to_same_grant(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        // Granted with "Class 8th"/"Section A"; the mark gate passes raw "8th"/"A".
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $this->assertTrue($s->can_cover(self::SUB, '8th', 'A', '2026-09-14'),
            'label drift (Class/Section prefixes, case) must not defeat a legitimate grant');
    }

    // ── Scope: a grant is one date, one section, one teacher ──

    public function test_grant_does_not_leak_to_a_different_date(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $this->assertFalse($s->can_cover(self::SUB, 'Class 8th', 'Section A', '2026-09-15'),
            'a grant for one date must give no authority on the next');
    }

    public function test_grant_does_not_leak_to_a_different_section(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $this->assertFalse($s->can_cover(self::SUB, 'Class 8th', 'Section B', '2026-09-14'),
            'coverage of Section A must not authorise Section B');
    }

    public function test_grant_does_not_leak_to_a_different_teacher(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $this->assertFalse($s->can_cover('SOME_OTHER_TEACHER', 'Class 8th', 'Section A', '2026-09-14'),
            'a grant to one uid must give no authority to another');
    }

    // ── Tenant isolation ──

    public function test_grant_in_one_tenant_is_unreadable_from_another(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        (new Attendance_coverage_service())->init($fs, self::SCHOOL, self::SESSION)
            ->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');

        // A service bound to a DIFFERENT school must not resolve the grant, even
        // reading the same fake store, because the doc id is school-prefixed and
        // can_cover re-checks the schoolId field.
        $other = (new Attendance_coverage_service())->init($fs, 'SATEST_B', self::SESSION);
        $this->assertFalse($other->can_cover(self::SUB, 'Class 8th', 'Section A', '2026-09-14'),
            'a coverage grant must never be resolvable across tenants');
    }

    // ── Revocation denies immediately ──

    public function test_revoke_denies_coverage(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $this->assertTrue($s->can_cover(self::SUB, 'Class 8th', 'Section A', '2026-09-14'));

        $this->assertTrue($s->revoke('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'ADMIN01'));
        $this->assertFalse($s->can_cover(self::SUB, 'Class 8th', 'Section A', '2026-09-14'),
            'a revoked grant must deny immediately');
    }

    public function test_revoke_when_nothing_granted_returns_false(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $this->assertFalse($this->svc($fs)->revoke('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'ADMIN01'));
    }

    // ── Idempotency ──

    public function test_regrant_is_idempotent_single_doc(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $id1 = $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $id2 = $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin', 'again');
        $this->assertSame($id1, $id2, 'the same grant must map to one deterministic doc id');
        $rows = array_filter($fs->store, fn($k) => strpos($k, 'attendanceCoverage/') === 0, ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $rows, 're-granting must update in place, not create duplicates');
    }

    public function test_regrant_after_revoke_reactivates(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $s->revoke('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'ADMIN01');
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $this->assertTrue($s->can_cover(self::SUB, 'Class 8th', 'Section A', '2026-09-14'),
            'a fresh grant after a revoke must restore access (revoked:false)');
    }

    // ── Validation / self-grant guard ──

    public function test_self_grant_is_rejected(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $this->expectException(InvalidArgumentException::class);
        // grantedBy === substitute uid → a teacher granting themselves.
        $this->svc($fs)->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', self::SUB, 'Self');
    }

    public function test_bad_date_is_rejected(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $this->expectException(InvalidArgumentException::class);
        $this->svc($fs)->grant('2026-13-40', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
    }

    public function test_empty_teacher_is_rejected(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $this->expectException(InvalidArgumentException::class);
        $this->svc($fs)->grant('2026-09-14', 'Class 8th', 'Section A', '', 'Sub', 'ADMIN01', 'Admin');
    }

    public function test_can_cover_false_when_nothing_granted(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $this->assertFalse($this->svc($fs)->can_cover(self::SUB, 'Class 8th', 'Section A', '2026-09-14'),
            'with no grant on file, coverage must be denied (fail closed)');
    }

    public function test_list_is_session_scoped(): void
    {
        $fs = new _FakeFsCoverage(self::SCHOOL, self::SESSION);
        $s  = $this->svc($fs);
        $s->grant('2026-09-14', 'Class 8th', 'Section A', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $s->grant('2026-09-15', 'Class 9th', 'Section B', self::SUB, 'Sub', 'ADMIN01', 'Admin');
        $this->assertCount(2, $s->listGrants());
        $this->assertCount(1, $s->listGrants('2026-09-14'));
    }
}
