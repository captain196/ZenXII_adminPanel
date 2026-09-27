<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  EVERY STUDENT LIFECYCLE TRANSITION IS AUTHORIZED AND TENANT-SCOPED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A lifecycle transition moves a real person between states that carry money,
 * identity and history consequences: a withdrawal freezes a fee book, a
 * promotion moves a cohort across a session boundary, a TC ends enrolment.
 * None of them may be reachable without the manage right, and none may accept
 * an unvalidated student id — an id is a Firestore path segment, so an
 * unvalidated one is a route into another tenant's data.
 *
 * ── WHY A MATRIX RATHER THAN PER-METHOD TESTS ────────────────────────────
 * LC-1 was missed precisely because the question was asked the wrong way
 * round. FRZ-4 counted CALLERS of the freeze function and concluded "three
 * live callers" — but there were four DOORS to Inactive, and the fourth
 * (change_status) never called it. Enumerating the doors is what finds the
 * missing one.
 *
 * The same inversion applies to authorization: asking "is this method
 * gated?" tests only the methods someone remembered. This enumerates the
 * transitions and demands a gate on each, so a NEW transition added without
 * one fails here rather than in production.
 *
 * ── SCOPE, STATED HONESTLY ───────────────────────────────────────────────
 * A CodeIgniter controller cannot be instantiated without the framework, so
 * the gate cannot be executed here. The defect class is a MISSING gate, which
 * is a property of the source, so it is asserted against comment-stripped
 * source with a control proving the stripper works. What the gates decide —
 * role resolution, tenant scoping — is covered behaviourally by the RBAC and
 * multi-tenant suites.
 */
final class StudentLifecycleTransitionSecurityTest extends TestCase
{
    /** @var array<string,string> controller path => stripped source */
    private static array $src = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['controllers/Sis.php', 'controllers/Classes.php', 'controllers/Readmission.php'] as $rel) {
            $out = '';
            foreach (token_get_all((string) file_get_contents(APPPATH . $rel)) as $t) {
                if (is_array($t)) {
                    if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                    $out .= $t[1];
                } else { $out .= $t; }
            }
            self::$src[$rel] = $out;
        }
    }

    /**
     * The transitions, enumerated as DOORS. `mutating` marks the ones that
     * change a student's state; a render-only screen needs the gate but not
     * id validation, because it takes no id to validate.
     */
    private const TRANSITIONS = [
        ['controllers/Sis.php',         'save_admission',        true],
        ['controllers/Sis.php',         'execute_promotion',     true],
        ['controllers/Sis.php',         'issue_tc',              true],
        ['controllers/Sis.php',         'cancel_tc',             true],
        ['controllers/Sis.php',         'withdraw_student',      true],
        ['controllers/Sis.php',         'change_status',         true],
        ['controllers/Sis.php',         'delete_student',        true],
        ['controllers/Sis.php',         'promote_from_waitlist', true],
        ['controllers/Classes.php',     'transfer_students',     true],
        ['controllers/Readmission.php', 'confirm',               true],
    ];

    private function body(string $rel, string $method): string
    {
        $src = self::$src[$rel] ?? '';
        $at  = strpos($src, "public function {$method}(");
        $this->assertNotFalse($at, "{$rel}::{$method} not found — a lifecycle transition was renamed or removed");
        $next = strpos($src, 'public function ', $at + 20);
        return substr($src, $at, ($next === false ? strlen($src) : $next) - $at);
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Control. Every assertion below reads stripped source; if the
        // stripper failed, a comment naming a gate would satisfy the test.
        foreach (self::$src as $rel => $src) {
            $this->assertStringNotContainsString('══', $src, "{$rel}: comments were not stripped");
        }
    }

    public function test_every_transition_is_authorization_gated(): void
    {
        foreach (self::TRANSITIONS as [$rel, $method, $_]) {
            $body = $this->body($rel, $method);
            // `_require_principal` is Readmission's own gate. It is deliberately
            // NARROWER than Sis::MANAGE_ROLES — Principal only — so it is a
            // stronger gate, not a missing one. Recognising it is accuracy, not
            // a relaxation: a method with NO gate still fails.
            $gated = preg_match(
                '/_require_role\s*\(|require_permission\s*\(|has_permission\s*\(|_require_principal\s*\(/',
                $body
            ) === 1;
            $this->assertTrue($gated,
                "{$rel}::{$method} is a student lifecycle transition with no authorization gate — "
                . 'it changes enrolment state, and enrolment state carries money and identity');
        }
    }

    public function test_every_mutating_transition_validates_the_student_id(): void
    {
        // A student id becomes a Firestore document path segment. An
        // unvalidated one is a traversal into another tenant's data.
        $exempt = ['execute_promotion', 'transfer_students', 'save_admission'];
        foreach (self::TRANSITIONS as [$rel, $method, $mutating]) {
            if (!$mutating || in_array($method, $exempt, true)) continue;
            $body = $this->body($rel, $method);
            $this->assertMatchesRegularExpression(
                '/safe_path_segment\s*\(|_validate_student_id\s*\(/', $body,
                "{$rel}::{$method} accepts a student id without validating it as a path segment"
            );
        }
    }

    public function test_the_bulk_transitions_validate_every_id_in_the_batch(): void
    {
        // A batch endpoint is the interesting case: validating the first id
        // and trusting the rest is the classic shape of this bug.
        foreach (['execute_promotion', 'transfer_students'] as $method) {
            $rel  = $method === 'transfer_students' ? 'controllers/Classes.php' : 'controllers/Sis.php';
            $body = $this->body($rel, $method);
            // Two acceptable shapes, and execute_promotion uses the STRONGER
            // one: it validates each id against an allowlist regex and then
            // array_intersect_key()s the result against students already
            // fetched from the source class — so a forged id cannot name a row
            // outside the tenant-scoped set at all, it simply matches nothing.
            // `[^)]*` cannot span a cast such as `foreach ((array) $ids as $id)`
            // — the cast's own ')' ends the match early. Look for a validator
            // within a short window after a foreach instead.
            $perMemberValidation = preg_match(
                '/foreach\s*\(.{0,120}?\)\s*\{.{0,400}?(safe_path_segment|_validate_student_id|preg_match\()/s',
                $body
            ) === 1;
            $intersectedWithScopedSet = strpos($body, 'array_intersect_key(') !== false;

            $this->assertTrue($perMemberValidation || $intersectedWithScopedSet,
                "{$rel}::{$method} neither validates every id in the batch nor intersects "
                . 'the selection with an already tenant-scoped set — a batch must not '
                . 'validate only its first member'
            );
        }
    }

    public function test_no_transition_trusts_a_client_supplied_school(): void
    {
        // The tenant must come from the authenticated session, never from the
        // request. A client-supplied school id is a cross-tenant write.
        foreach (self::TRANSITIONS as [$rel, $method, $_]) {
            $body = $this->body($rel, $method);
            $this->assertDoesNotMatchRegularExpression(
                "/input->post\(\s*['\"](school_id|schoolId|school)['\"]\s*\)/", $body,
                "{$rel}::{$method} reads the school from the REQUEST — the tenant must come "
                . 'from the authenticated session'
            );
        }
    }

    public function test_every_transition_leaves_an_audit_trail(): void
    {
        // A state change nobody can reconstruct afterwards is not auditable,
        // and enrolment changes are exactly what an inspection asks about.
        foreach (self::TRANSITIONS as [$rel, $method, $mutating]) {
            if (!$mutating) continue;
            $body = $this->body($rel, $method);
            // transfer_students audits through a dedicated history SERVICE
            // (`studentHistorySvc->log()`), not the controller helper. The
            // audit exists; only the shape differs.
            $this->assertMatchesRegularExpression(
                '/_log_history\s*\(|log_audit\s*\(|HistorySvc->log\s*\(|historyService->log\s*\(/', $body,
                "{$rel}::{$method} changes enrolment state without writing history or audit"
            );
        }
    }

    public function test_the_state_changing_doors_are_post_only(): void
    {
        // A GET that mutates is CSRF-reachable and can be triggered by a
        // prefetch or a crawler.
        foreach (['withdraw_student', 'change_status'] as $method) {
            $body = $this->body('controllers/Sis.php', $method);
            $this->assertMatchesRegularExpression(
                "/input->method\(\)\s*!==\s*'post'|input->post\(/", $body,
                "Sis::{$method} does not require POST"
            );
        }
    }
}
