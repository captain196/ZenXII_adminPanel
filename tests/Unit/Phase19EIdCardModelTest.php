<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Repo convention for the Sr_no/Apaar/Admission_no service family, reused
// verbatim: a global get_instance() no-op stub loaded BEFORE the real audit
// helper, so log_audit() runs for real and degrades silently through its own
// `!isset($CI->fs)` early-exit. These must be required at FILE level, not in
// setUpBeforeClass — PHPUnit loads every test file in the suite to discover
// tests, so whichever file is parsed first wins the function_exists race.
// (Learned here: a stub registered inside setUpBeforeClass loses that race to
// the five existing tests that already require the real helper, and then
// silently captures nothing.)
require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';

// ── Test doubles ─────────────────────────────────────────────────────────
// In-memory stand-ins for Firebase + Firestore_service. `firestoreCreate` is
// modelled with its REAL semantics — create-if-not-exists, returning false on
// collision — because that is the atomic primitive the version claim depends
// on. A double that always succeeded would silently pass the concurrency test.

class FakeIdCardFirebase
{
    public array $docs = [];
    public int $createCalls = 0;

    public function firestoreCreate(string $col, string $id, array $data): bool
    {
        $this->createCalls++;
        if (isset($this->docs[$col][$id])) return false;   // collision signal
        $this->docs[$col][$id] = $data;
        return true;
    }
}

class FakeIdCardFs
{
    public int $getCalls = 0;
    public int $queryCalls = 0;

    public function __construct(private string $schoolId, private FakeIdCardFirebase $fb) {}

    public function schoolId(): string { return $this->schoolId; }
    public function docId(string $e): string { return "{$this->schoolId}_{$e}"; }
    public function docId2(string $a, string $b): string { return "{$this->schoolId}_{$a}_{$b}"; }

    public function get(string $col, string $id): ?array
    {
        $this->getCalls++;
        return $this->fb->docs[$col][$id] ?? null;
    }

    public function set(string $col, string $id, array $data, bool $merge = false): bool
    {
        $this->fb->docs[$col][$id] = $merge
            ? array_merge($this->fb->docs[$col][$id] ?? [], $data)
            : $data;
        return true;
    }

    /** Mirrors the real signature: schoolId equality is applied automatically. */
    public function schoolWhere(string $col, array $extra = []): array
    {
        $this->queryCalls++;
        $out = [];
        foreach ($this->fb->docs[$col] ?? [] as $id => $d) {
            if (($d['schoolId'] ?? null) !== $this->schoolId) continue;
            $out[] = ['id' => $id, 'data' => $d];
        }
        return $out;
    }
}

/**
 * Phase 19E — the ID card data model.
 *
 * Establishes the state ZenX never had: a card that can be superseded and
 * revoked independently of the student. Before this, "reissue" produced a
 * byte-identical credential, so a lost card could not be retired without
 * rotating the server secret and killing every card in every school.
 *
 * ⚠ NOTHING HERE IS ENFORCED YET. No QR consumer calls isCurrentVersion().
 * That flip is Phase 19G, after every student has been reissued — doing it
 * now would reject every legitimate holder, since an un-issued student has
 * currentVersion 0.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19EIdCardModelTest extends TestCase
{
    private const SCH = 'SCH_TEST01';
    private const STU_A = 'STU0012';  private const SR_A = '47';
    private const STU_B = 'STU0047';  private const SR_B = '12';

    private FakeIdCardFirebase $fb;
    private FakeIdCardFs $fs;
    private \Id_card_service $svc;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/application/helpers/qr_token_helper.php';
        if (!class_exists('Id_card_service')) {
            require_once dirname(__DIR__, 2) . '/application/libraries/Id_card_service.php';
        }
    }

    protected function setUp(): void
    {
        $this->fb  = new FakeIdCardFirebase();
        $this->fs  = new FakeIdCardFs(self::SCH, $this->fb);
        $this->svc = (new \Id_card_service())->init($this->fb, $this->fs, self::SCH, 'ADM001');
    }

    private function identityA(): array
    {
        return ['srNo' => self::SR_A, 'admissionNo' => 'ADM/2026/0012',
                'studentName' => 'Test A', 'className' => 'Class 8th', 'section' => 'Section A'];
    }

    private function issueFirst(string $stu = self::STU_A): array
    {
        return $this->svc->issue($stu, \Id_card_service::REASON_FIRST_ISSUE, $this->identityA());
    }

    // ── A. First issue ──────────────────────────────────────────────────

    public function test_first_issue_creates_version_one_and_a_credential(): void
    {
        $r = $this->issueFirst();
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['version']);
        $dec = qr_token_decode($r['credential']);
        $this->assertSame(1, $dec['cardVersion']);
        $this->assertSame(self::STU_A, $dec['studentId']);
    }

    public function test_first_issue_sets_the_pointer_active(): void
    {
        $this->issueFirst();
        $c = $this->svc->currentCard(self::STU_A);
        $this->assertSame(\Id_card_service::STATUS_ACTIVE, $c['status']);
        $this->assertSame(1, $c['currentVersion']);
        $this->assertSame(\Id_card_service::KIND_CURRENT, $c['kind']);
    }

    public function test_an_unissued_student_has_no_card_and_version_zero(): void
    {
        $this->assertNull($this->svc->currentCard(self::STU_B));
        $this->assertSame(0, $this->svc->currentVersion(self::STU_B));
    }

    public function test_a_second_first_issue_is_refused(): void
    {
        $this->issueFirst();
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, $this->identityA());
        $this->assertFalse($r['ok']);
        $this->assertSame('card_already_issued', $r['reason']);
    }

    public function test_replacing_a_card_that_was_never_issued_is_refused(): void
    {
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->identityA());
        $this->assertFalse($r['ok']);
        $this->assertSame('no_existing_card_to_replace', $r['reason']);
    }

    public function test_an_unknown_reason_is_refused(): void
    {
        $r = $this->svc->issue(self::STU_A, 'because_i_said_so', $this->identityA());
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_reason', $r['reason']);
    }

    // ── B. Replacement — the case that could not exist before ───────────

    public function test_replacement_supersedes_the_old_card(): void
    {
        $this->issueFirst();
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->identityA());

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['version']);

        $old = $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 1));
        $this->assertSame(\Id_card_service::STATUS_REPLACED, $old['status']);
        $this->assertSame(2, $old['supersededByVersion']);
        $this->assertSame(\Id_card_service::REASON_LOST, $old['replacedReason']);

        $new = $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 2));
        $this->assertSame(\Id_card_service::STATUS_ACTIVE, $new['status']);
        $this->assertSame(1, $new['supersedesVersion']);
    }

    public function test_only_one_active_card_exists_after_replacement(): void
    {
        $this->issueFirst();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->identityA());
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_DAMAGED, $this->identityA());

        $active = 0;
        foreach ($this->fb->docs['idCards'] as $d) {
            if (($d['kind'] ?? '') === \Id_card_service::KIND_VERSION
                && ($d['status'] ?? '') === \Id_card_service::STATUS_ACTIVE) $active++;
        }
        $this->assertSame(1, $active, 'a student may never hold two active cards');
        $this->assertSame(3, $this->svc->currentVersion(self::STU_A));
    }

    public function test_the_old_credential_is_no_longer_the_current_version(): void
    {
        // THE point of the whole phase: a specific card becomes retirable.
        $first = $this->issueFirst();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_STOLEN, $this->identityA());

        $oldVer = qr_token_decode($first['credential'])['cardVersion'];
        $this->assertSame(1, $oldVer);
        $this->assertFalse($this->svc->isCurrentVersion(self::STU_A, $oldVer),
            'the stolen card must stop being current');
        $this->assertTrue($this->svc->isCurrentVersion(self::STU_A, 2));
    }

    public function test_each_replacement_mints_a_different_credential(): void
    {
        $a = $this->issueFirst()['credential'];
        $b = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->identityA())['credential'];
        $this->assertNotSame($a, $b, 'reissue previously produced a byte-identical token');
    }

    // ── C. Revocation without a successor ───────────────────────────────

    public function test_revoke_marks_the_card_revoked(): void
    {
        $this->issueFirst();
        $r = $this->svc->revoke(self::STU_A, \Id_card_service::REASON_STOLEN);
        $this->assertTrue($r['ok']);

        $v = $this->fs->get('idCards', $this->svc->versionDocId(self::STU_A, 1));
        $this->assertSame(\Id_card_service::STATUS_REVOKED, $v['status']);
        $this->assertSame('ADM001', $v['revokedBy']);
        $this->assertSame(0, $this->svc->currentVersion(self::STU_A), 'a revoked card is not current');
    }

    public function test_revoking_twice_is_idempotent(): void
    {
        $this->issueFirst();
        $this->svc->revoke(self::STU_A, \Id_card_service::REASON_STOLEN);
        $r = $this->svc->revoke(self::STU_A, \Id_card_service::REASON_STOLEN);
        $this->assertTrue($r['ok']);
        $this->assertSame('noop', $r['reason']);
    }

    public function test_revoking_a_student_with_no_card_fails_cleanly(): void
    {
        $r = $this->svc->revoke(self::STU_B, \Id_card_service::REASON_LOST);
        $this->assertFalse($r['ok']);
        $this->assertSame('no_card', $r['reason']);
    }

    public function test_a_card_can_be_reissued_after_revocation(): void
    {
        $this->issueFirst();
        $this->svc->revoke(self::STU_A, \Id_card_service::REASON_STOLEN);
        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_STOLEN, $this->identityA());
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['version']);
        $this->assertTrue($this->svc->isCurrentVersion(self::STU_A, 2));
    }

    // ── D. Concurrency — the atomic claim ───────────────────────────────

    public function test_a_version_collision_probes_upward_instead_of_overwriting(): void
    {
        $this->issueFirst();
        // Simulate a concurrent operator having already claimed v2.
        $this->fb->docs['idCards'][$this->svc->versionDocId(self::STU_A, 2)] =
            ['kind' => 'version', 'schoolId' => self::SCH, 'studentId' => self::STU_A, 'version' => 2];

        $r = $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->identityA());
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['version'], 'must not overwrite the other operator\'s claim');
    }

    public function test_the_claim_uses_create_if_not_exists_not_a_blind_write(): void
    {
        $this->issueFirst();
        $this->assertGreaterThan(0, $this->fb->createCalls,
            'version claiming must go through firestoreCreate, the atomic primitive');
    }

    // ── E. Enforcement is OFF ───────────────────────────────────────────

    public function test_is_current_version_rejects_null_and_zero(): void
    {
        $this->issueFirst();
        $this->assertFalse($this->svc->isCurrentVersion(self::STU_A, null), 'V1/legacy name no card');
        $this->assertFalse($this->svc->isCurrentVersion(self::STU_A, 0));
    }

    public function test_is_current_version_fails_closed_for_an_unmanaged_student(): void
    {
        // Must never read "no card on file" as "valid" — which is exactly why
        // this cannot be switched on before every student is reissued.
        $this->assertFalse($this->svc->isCurrentVersion(self::STU_B, 1));
    }

    /**
     * SUPERSEDED PREMISE, updated in Phase 19L rather than deleted.
     *
     * This originally asserted that no consumer referenced the card service at
     * all — correct for 19E, when the model existed but nothing was wired.
     * Phase 19L deliberately wired `validateCredential()` into all three
     * consumers so credential policy lives in ONE place.
     *
     * The invariant that actually matters is unchanged and is what this now
     * asserts: no consumer may call the enforcement predicate DIRECTLY
     * (that would be a second policy), and enforcement must still be OFF by
     * configuration. Deleting the test would have quietly dropped that guard.
     */
    public function test_consumers_defer_to_the_authority_and_never_enforce_directly(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'application/controllers/Attendance.php',
            'application/libraries/Transport_qr_attendance_service.php',
            'application/libraries/Visitor_service.php',
        ] as $rel) {
            $src = (string) file_get_contents($root . '/' . $rel);
            $this->assertStringNotContainsString('isCurrentVersion', $src,
                "{$rel} must not re-implement the version check — that is the authority's job");
            $this->assertStringContainsString('validateCredential', $src,
                "{$rel} must defer credential validity to the one authority");
        }

        $cfg = (string) file_get_contents($root . '/application/config/id_card_flags.php');
        foreach (['IDCARD_ENFORCE_V2', 'IDCARD_REJECT_LEGACY', 'IDCARD_REJECT_V1'] as $f) {
            $this->assertMatchesRegularExpression("/'{$f}'\]\s*=\s*false/", $cfg,
                "{$f} must remain off until telemetry evidence exists");
        }
    }

    // ── F. Register — one query, not N ──────────────────────────────────

    public function test_the_register_costs_one_query_regardless_of_student_count(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $sid = sprintf('STU%04d', $i);
            $this->svc->issue($sid, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => (string) $i]);
        }
        $before = $this->fs->queryCalls;
        $reg = $this->svc->registerForSchool();
        $this->assertSame(1, $this->fs->queryCalls - $before, 'must never be N reads for N students');
        $this->assertCount(25, $reg);
    }

    public function test_the_register_returns_only_pointer_docs(): void
    {
        $this->issueFirst();
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_LOST, $this->identityA());
        $reg = $this->svc->registerForSchool();
        $this->assertCount(1, $reg, 'three docs exist for this student; only the pointer is a register row');
        $this->assertSame(2, $reg[self::STU_A]['currentVersion']);
    }

    // ── G. Tenant isolation ─────────────────────────────────────────────

    public function test_every_document_carries_the_school_id(): void
    {
        $this->issueFirst();
        foreach ($this->fb->docs['idCards'] as $id => $d) {
            $this->assertSame(self::SCH, $d['schoolId']);
            $this->assertStringStartsWith(self::SCH . '_', $id, 'doc ids must be tenant-prefixed');
        }
    }

    public function test_another_schools_cards_are_never_returned(): void
    {
        $this->issueFirst();
        $this->fb->docs['idCards']['SCH_OTHER_STU0001'] =
            ['kind' => 'current', 'schoolId' => 'SCH_OTHER', 'studentId' => 'STU0001', 'currentVersion' => 1];
        $this->assertArrayNotHasKey('STU0001', $this->svc->registerForSchool());
    }

    // ── H. SRNO discipline ──────────────────────────────────────────────

    public function test_srno_is_denormalised_for_display_but_is_not_the_key(): void
    {
        $this->issueFirst();
        $c = $this->svc->currentCard(self::STU_A);
        $this->assertSame(self::SR_A, $c['srNo'], 'SRNO must be present for register display/print');
        // ...but the document is keyed by studentId, never by SRNO.
        $this->assertArrayHasKey(self::SCH . '_' . self::STU_A, $this->fb->docs['idCards']);
        $this->assertArrayNotHasKey(self::SCH . '_' . self::SR_A, $this->fb->docs['idCards']);
    }

    public function test_admission_no_is_carried_alongside_srno(): void
    {
        $this->issueFirst();
        $this->assertSame('ADM/2026/0012', $this->svc->currentCard(self::STU_A)['admissionNo']);
    }

    public function test_roll_no_is_never_stored_on_a_card(): void
    {
        // Roll No is session/class-scoped and reassigned on promotion; it must
        // never become part of card identity.
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE,
            $this->identityA() + ['rollNo' => '12', 'Roll No' => '12']);
        foreach ($this->fb->docs['idCards'] as $d) {
            $this->assertArrayNotHasKey('rollNo', $d);
            $this->assertArrayNotHasKey('Roll No', $d);
        }
    }

    public function test_two_students_with_swapped_srno_never_collide(): void
    {
        // STU0012→SR 47 and STU0047→SR 12: if SRNO were ever used as the key,
        // these two would cross-contaminate.
        $this->svc->issue(self::STU_A, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_A]);
        $this->svc->issue(self::STU_B, \Id_card_service::REASON_FIRST_ISSUE, ['srNo' => self::SR_B]);
        $this->assertSame(self::SR_A, $this->svc->currentCard(self::STU_A)['srNo']);
        $this->assertSame(self::SR_B, $this->svc->currentCard(self::STU_B)['srNo']);
    }

    // ── I. Lifecycle neutrality ─────────────────────────────────────────

    public function test_promotion_and_transfer_do_not_touch_the_credential(): void
    {
        // Neither class nor section is signed into the credential, so a
        // promotion or a section transfer cannot invalidate a card.
        $r = $this->issueFirst();
        $before = $r['credential'];
        // A promotion would rewrite className/section on the student doc; the
        // card's identity inputs (schoolId, studentId, version) are untouched.
        $this->assertSame($before, $this->svc->mintCredential(self::STU_A, 1));
    }

    public function test_re_admission_yields_a_different_credential_identity(): void
    {
        // Re-admission mints a NEW studentId (SRNO is preserved separately by
        // Sr_no_service::relink), so the new record's credential differs.
        $this->assertNotSame(
            $this->svc->mintCredential(self::STU_A, 1),
            $this->svc->mintCredential(self::STU_B, 1)
        );
    }

    // ── J. Audit ────────────────────────────────────────────────────────

    /**
     * Audit is asserted STRUCTURALLY, not by capturing calls.
     *
     * This repo has no live Firestore in the test environment, and by
     * convention (tests/Support/audit_helper_stub.php) log_audit() is allowed
     * to run for real and degrade to a no-op — so there is nothing to capture.
     * What actually matters is the payload SHAPE: log_audit() persists only
     * `appId`, `entityType` and `meta` from $context and silently discards
     * every other top-level key. Passing a flat payload has already caused a
     * real defect in this programme (Phase 16.8, rollover audit), which is
     * precisely what this pins.
     */
    private function assertAuditCallsAreWellFormed(string $method): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/application/libraries/Id_card_service.php');
        $body = null;
        $at = strpos($src, 'function ' . $method . '(');
        $this->assertNotFalse($at, "{$method}() not found");
        $open = strpos($src, '{', $at);
        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $body = substr($src, $open, $i - $open + 1); break; } }
        }
        $this->assertNotNull($body);

        // Strip comments so prose describing log_audit cannot satisfy or
        // defeat the scan (the trap this programme hit in Phase 19C).
        $code = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $code .= $t[1]; }
            else $code .= $t;
        }

        $this->assertStringContainsString('log_audit(', $code, "{$method}() must write an audit entry");
        $this->assertStringContainsString("'entityType' => 'idCard'", $code);
        $this->assertStringContainsString("'meta' => [", $code,
            "{$method}() must nest its payload under meta or it is silently discarded");

        // No top-level context key outside the persisted whitelist.
        $ctxAt = strpos($code, 'log_audit(');
        $ctx   = substr($code, $ctxAt);
        preg_match_all("/'([a-zA-Z]+)'\s*=>/", substr($ctx, 0, strpos($ctx, "'meta' => [") + 11), $m);
        foreach ($m[1] as $key) {
            $this->assertContains($key, ['appId', 'entityType', 'meta'],
                "top-level audit context key '{$key}' would be silently discarded by log_audit()");
        }
    }

    public function test_issue_writes_a_well_formed_audit_entry(): void
    {
        $this->assertAuditCallsAreWellFormed('issue');
    }

    public function test_revoke_writes_a_well_formed_audit_entry(): void
    {
        $this->assertAuditCallsAreWellFormed('revoke');
    }

    public function test_issue_and_revoke_both_complete_with_the_real_audit_helper_loaded(): void
    {
        // The real log_audit() runs here (see the file-level requires). If the
        // service ever passed it something that threw outside its own
        // try/catch, these calls would fail rather than silently no-op.
        $this->assertTrue($this->issueFirst()['ok']);
        $this->assertTrue($this->svc->revoke(self::STU_A, \Id_card_service::REASON_STOLEN)['ok']);
    }

    // ── K. Model shape ──────────────────────────────────────────────────

    public function test_lost_stolen_and_damaged_are_reasons_not_statuses(): void
    {
        $st = \Id_card_service::validStatuses();
        foreach (['lost', 'stolen', 'damaged'] as $r) {
            $this->assertNotContains($r, $st, "'{$r}' describes WHY a card left service, not its state");
            $this->assertContains($r, \Id_card_service::validReasons());
        }
        $this->assertSame(['active', 'replaced', 'revoked', 'expired'], $st);
    }

    public function test_chargeable_reasons_exclude_first_issue_and_school_error(): void
    {
        $c = \Id_card_service::chargeableReasons();
        $this->assertContains('lost', $c);
        $this->assertNotContains('first_issue', $c);
        $this->assertNotContains('wear', $c);
        $this->assertNotContains('data_correction', $c, "the school's own correction is not the family's fault");
        $this->assertNotContains('admin', $c);
    }

    public function test_this_phase_raises_no_fee(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/application/libraries/Id_card_service.php');
        $this->assertStringNotContainsString('createModuleFee', $src, 'fee integration is Phase 19I');
        $this->assertStringNotContainsString('feeDemands', $src);
    }

    public function test_the_service_introduces_no_rtdb_usage(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/application/libraries/Id_card_service.php');
        foreach (['getDatabase', 'firebaseio', 'database()->'] as $needle) {
            $this->assertStringNotContainsString($needle, $src, 'Firestore-only is mandatory');
        }
    }
}
