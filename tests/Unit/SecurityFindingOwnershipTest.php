<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RulesParser;

/**
 * EVERY FIRESTORE FINDING-CLASS PAIR MUST HAVE EXACTLY ONE OWNER.
 *
 * A security report is read once. This test runs on every suite execution, so a
 * new unscoped arm — added by ANY workstream, at any time — fails the build
 * instead of quietly joining a list nobody re-reads.
 *
 * It asserts against `application/backups/_billing_v2/live_current.rules`, which is
 * refreshed from PRODUCTION after every deployment step. Pointing it at a frozen
 * baseline would make it measure history instead of reality — the expected count
 * below is deliberately updated as each artifact lands, so a change in the
 * finding population stays visible rather than absorbed.
 *
 * ── WHY BOTH DIRECTIONS MATTER ────────────────────────────────────────────
 * UNOWNED  a real exposure nobody has claimed — the obvious failure.
 * STALE    a ledger row that owns nothing. Less obvious and just as dangerous:
 *          it is how a fix gets silently dropped during an artifact rebuild
 *          while the paperwork still says it is covered.
 *
 * @covers RulesParser
 */
class SecurityFindingOwnershipTest extends TestCase
{
    /** @var string */
    private static $rules;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../scripts/lib/rules_parser.php';
        self::$rules = __DIR__ . '/../../application/backups/_billing_v2/live_current.rules';
    }

    /** Re-run the ledger script and return its exit code plus output. */
    private function runLedger(): array
    {
        $script = __DIR__ . '/../../scripts/sec_ownership_ledger.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(self::$rules);
        exec($cmd . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_the_live_snapshot_is_present_and_whole()
    {
        $this->assertFileExists(
            self::$rules,
            'The live ruleset snapshot is the basis of every ownership claim. Without it '
            . 'this test would silently pass by measuring nothing.'
        );
        $this->assertGreaterThan(
            100000,
            filesize(self::$rules),
            'snapshot looks truncated — a partial ruleset would under-report findings'
        );
    }

    public function test_every_finding_class_pair_has_an_owner()
    {
        list($code, $out) = $this->runLedger();

        $this->assertSame(
            0,
            $code,
            "The Firestore ownership ledger is out of sync with the live ruleset.\n"
            . "Either a new unscoped arm appeared, or a ledger row no longer owns a real\n"
            . "finding. Resolve BEFORE any rules deployment.\n\n" . $out
        );
    }

    public function test_the_ledger_reports_zero_unowned_pairs()
    {
        list(, $out) = $this->runLedger();

        $this->assertMatchesRegularExpression(
            '/UNOWNED\s+:\s+0/',
            $out,
            "At least one finding-class pair is unowned.\n\n" . $out
        );
    }

    public function test_the_ledger_has_no_stale_rows()
    {
        list(, $out) = $this->runLedger();

        $this->assertMatchesRegularExpression(
            '/stale ledger rows\s+:\s+0/',
            $out,
            "A ledger row owns nothing. That is how a fix gets dropped in a rebuild\n"
            . "while the paperwork still claims coverage.\n\n" . $out
        );
    }

    /**
     * The count is asserted so that a change in the finding population is
     * VISIBLE rather than absorbed. If a workstream adds arms, this fails and
     * forces the ledger to be revisited deliberately.
     */
    public function test_the_finding_population_matches_the_deployed_state()
    {
        list(, $out) = $this->runLedger();

        $this->assertMatchesRegularExpression(
            '/finding-class pairs\s+:\s+0/',
            $out,
            "PRODUCTION HAS ACQUIRED A NEW CROSS-TENANT FINDING.\n\n"
            . "Expected ZERO. All 147 findings present at the 2026-08-21 baseline were\n"
            . "discharged by the seven deployed artifacts (SEC-1, SEC-3 w3/w4/w5, SEC-2,\n"
            . "SEC-6, SEC-8), verified against the deployed ruleset after each step.\n\n"
            . "A non-zero count means a rules change since then reopened a boundary, or\n"
            . "a new collection was added without tenant scoping. Do NOT adjust this\n"
            . "expectation to make the build pass — find the arm.\n\n" . $out
        );
    }

    // ══ THE IDENTITY CONTRACT THE OWNER-SCOPED ARMS DEPEND ON ═══════════════

    /**
     * Owner-scoped arms (`X == request.auth.uid`) carry NO school comparison.
     * That is sound only while staff and student ids are globally unique, so
     * a uid cannot name a different person in another tenant.
     *
     * This test pins the dependency in the classifier itself. If someone ever
     * decides owner-scoping needs a tenant check too, this fails and points at
     * the reasoning rather than letting the change look arbitrary.
     *
     * The runtime consequence is measured separately by SEC-7 Q13, which
     * demonstrates that an id COLLISION across tenants would grant a
     * cross-tenant read.
     */
    public function test_owner_scoping_is_treated_as_a_valid_boundary()
    {
        $src = "function isAuth() { return request.auth != null; }\n"
             . str_repeat("// pad\n", 3000);
        $p = new RulesParser($src);

        $this->assertSame(
            'owner-scoped',
            $p->classify('isAuth() && resource.data.staffId == request.auth.uid', 'get'),
            'Owner-scoping by request.auth.uid must count as a boundary in its own right. '
            . 'Requiring a school comparison as well reported staffAttendance, '
            . 'staffAttendanceSummary, attendancePunches and ptmRsvps as cross-tenant when '
            . 'they are not.'
        );
    }

    /**
     * SRNO MUST NEVER APPEAR IN AN AUTHORIZATION PREDICATE.
     *
     * SRNO is a SCHOOL-SCOPED, human-facing identifier — two tenants can hold
     * the same SRNO. Student ID (STU…) is the global, internal identity. Using
     * SRNO to authorize anything would make one school's record reachable with
     * another school's identifier.
     *
     * Asserted against the live ruleset rather than assumed.
     */
    public function test_no_authorization_arm_uses_srno_as_an_identity()
    {
        $src = file_get_contents(self::$rules);
        $p = new RulesParser($src);

        $offenders = [];
        foreach ($p->arms() as $arm) {
            if (preg_match('/\bsr_?no\b|\bsrNo\b|\bSRNO\b/i', $arm['inlined'])) {
                $offenders[] = $arm['collection'] . '.' . $arm['operation'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'SRNO is school-scoped and NOT unique across tenants. It must never gate '
            . 'authorization: ' . implode(', ', $offenders)
        );
    }

    /**
     * THE TWO ID NAMESPACES MUST STAY SEPARATE.
     *
     * Every owner-scoped authorization arm (`X == request.auth.uid`) omits a
     * school comparison, which is sound ONLY because Student and Staff ids are
     * globally unique. The repo states the contract explicitly:
     *
     *   Id_generator.php   -> feeCounters/_sys_STU...   GLOBAL Student ID counter
     *   Sr_no_service.php  -> feeCounters/_sys_SRNO_... SCHOOL-SCOPED SRNO counter
     *
     * and Sr_no_service documents why it did NOT reuse Id_generator: the latter
     * "has no per-school concept and must not be modified to invent one — that
     * would risk the GLOBAL Student ID counter for an unrelated feature."
     *
     * If the SRNO counter ever stopped being school-keyed, or the Student ID
     * counter started being, the security consequence would be silent: every
     * owner-scoped arm would become cross-tenant. SEC-7 Q13 demonstrates that
     * failure mode at runtime; this test guards the contract in code.
     */
    public function test_srno_counter_is_school_scoped_and_student_id_counter_is_not()
    {
        $srno = file_get_contents(__DIR__ . '/../../application/libraries/Sr_no_service.php');

        $this->assertStringContainsString(
            '_sys_SRNO_',
            $srno,
            'the SRNO counter namespace changed'
        );
        $this->assertMatchesRegularExpression(
            '/COUNTER_DOC_PREFIX\s*=\s*\'_sys_SRNO_\'/',
            $srno,
            'SRNO counter prefix changed — verify it is still keyed per school'
        );
        $this->assertStringContainsString(
            'SCHOOL-SCOPED',
            $srno,
            'Sr_no_service no longer declares itself school-scoped'
        );

        $idgen = __DIR__ . '/../../application/libraries/Id_generator.php';
        if (is_file($idgen)) {
            $src = file_get_contents($idgen);
            $this->assertStringNotContainsString(
                '_sys_SRNO_',
                $src,
                'the GLOBAL Student ID generator must not adopt the school-scoped SRNO '
                . 'namespace — that would collapse the two identity spaces'
            );
        }
    }

    /**
     * And the same for admission number, which is likewise school-scoped.
     */
    public function test_no_authorization_arm_uses_admission_number_as_an_identity()
    {
        $src = file_get_contents(self::$rules);
        $p = new RulesParser($src);

        $offenders = [];
        foreach ($p->arms() as $arm) {
            if (preg_match('/admissionNo|admission_number|admissionNumber/i', $arm['inlined'])) {
                $offenders[] = $arm['collection'] . '.' . $arm['operation'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Admission number is school-scoped and must not gate authorization: '
            . implode(', ', $offenders)
        );
    }
}
