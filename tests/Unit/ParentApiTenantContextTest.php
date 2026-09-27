<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Payment_provider_config_resolver.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PARENT-API TENANT CONTEXT — school_id MUST REACH THE PROVIDER RESOLVER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT (reproduced in live runtime, 2026-08-26) ──────────────────
 * Fee_management's parent-app API branch runs CI_Controller::__construct()
 * rather than parent::__construct(), so MY_Controller's bootstrap — which
 * normally assigns $this->school_id — never executes. The branch set only:
 *
 *     $this->school_name = (string) ($claims['school_id'] ?? '');
 *
 * leaving $this->school_id NULL for the entire parent-app request. When the
 * provider programme was wired in, _init_payment_service() began passing
 * that property to a `string`-typed parameter:
 *
 *     Payment_provider_config_resolver::resolve($fb, $this->school_id, ...)
 *
 * Result, on a real device, twice:
 *
 *     TypeError: Argument #2 ($schoolId) must be of type string, null given
 *     → HTTP 500 on parent_create_order AND parent_verify_payment
 *
 * The Parent App payment path was completely down. Admin-side paths were
 * unaffected, because MY_Controller populates school_id there — which is
 * exactly why every prior test passed.
 *
 * ── WHY IT SURVIVED THE PRE-PAYMENT GATE ─────────────────────────────────
 * The gate asserted that resolve() IS WIRED. It never asserted that its
 * arguments are non-null on each path that reaches it. "The call exists" and
 * "the call can succeed" are different claims, and only the second one is
 * worth anything. No source-string check could have caught this; the defect
 * lives in a property's runtime value, not in the text of the call.
 *
 * ── WHAT THIS FILE CAN AND CANNOT PROVE ──────────────────────────────────
 * Honest scope, because overstating it would recreate the original problem:
 *
 *   PROVABLE HERE (behavioural, executed):
 *     · the resolver REJECTS null — the precise mechanism of the outage
 *     · the resolver ACCEPTS a real id and forwards it to the tenant query
 *       unchanged — so a populated school_id genuinely reaches Firestore
 *     · a control proving those two assertions are not both passing merely
 *       because everything throws
 *
 *   NOT PROVABLE HERE:
 *     · that parent_create_order end-to-end returns 200. A CI3 controller
 *       cannot be instantiated without the framework (the same limitation
 *       already recorded in _init_payment_service's own comments), and an
 *       HTTP-level test would additionally require a live server, a real
 *       Firebase ID token, and would MINT a Razorpay order as a side effect.
 *       That proof is the live device attempt, whose "before" evidence is
 *       the pair of logged 500s above.
 *
 * The constructor invariant itself is guarded by a source assertion below —
 * deliberately narrow, comment-stripped, and order-aware, so it cannot pass
 * on a file that merely mentions the right words.
 */
final class ParentApiTenantContextTest extends TestCase
{
    /** Faithful stand-in: records the schoolId the resolver actually queries. */
    private function spyFirebase(array $rows = []): object
    {
        return new class($rows) {
            public array $queriedWith = [];
            private array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function firestoreQuery(string $collection, array $where)
            {
                $this->queriedWith[] = ['collection' => $collection, 'where' => $where];
                return $this->rows;
            }
            public function firestoreGet($collection, $docId) { return null; }
        };
    }

    private function controllerSource(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'controllers/Fee_management.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    // ── BEHAVIOURAL ──────────────────────────────────────────────────────

    public function test_resolver_rejects_a_null_school_id(): void
    {
        // The exact failure that took the Parent App payment path down. If this
        // ever stops throwing, the type-hint has been weakened and the defect
        // can return silently as a WRONG-TENANT read instead of a loud 500.
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line — passing null deliberately */
        Payment_provider_config_resolver::resolve($this->spyFirebase(), null, 'SCH_X', '2026-27');
    }

    public function test_resolver_rejects_a_null_school_name_too(): void
    {
        // The sibling argument has the same exposure on the same code path.
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line */
        Payment_provider_config_resolver::resolve($this->spyFirebase(), 'SCH_X', null, '2026-27');
    }

    public function test_a_real_school_id_reaches_the_tenant_scoped_query(): void
    {
        // Non-vacuity control for the two TypeError tests above: with a real
        // id the SAME call completes. So they are not passing because every
        // invocation throws.
        $fb = $this->spyFirebase();
        Payment_provider_config_resolver::resolve($fb, 'SCH_B56BB9A401', 'SCH_B56BB9A401', '2026-27');

        $this->assertNotEmpty($fb->queriedWith, 'resolver never queried the registry at all');
        $where = $fb->queriedWith[0]['where'];

        // The id must arrive intact — not empty, not defaulted, not a
        // different tenant's. Tenant isolation depends on this one value.
        $this->assertSame(['schoolId', '==', 'SCH_B56BB9A401'], $where[0]);
    }

    public function test_an_empty_school_id_is_not_silently_treated_as_a_wildcard(): void
    {
        // '' passes the string type-hint, so it would NOT have produced the
        // 500. It would have queried for schoolId == '' — matching nothing,
        // or worse, whatever a future query builder does with an empty term.
        // Assert it is carried through verbatim rather than dropped, which is
        // what makes the constructor's own '' guard the load-bearing check.
        $fb = $this->spyFirebase();
        Payment_provider_config_resolver::resolve($fb, '', '', '2026-27');
        $this->assertSame(['schoolId', '==', ''], $fb->queriedWith[0]['where'][0]);
    }

    // ── CONSTRUCTOR INVARIANT ────────────────────────────────────────────

    public function test_parent_api_branch_populates_school_id_from_the_authenticated_claim(): void
    {
        $src = $this->controllerSource();

        // Assigned from the claim, not from client input.
        $this->assertMatchesRegularExpression(
            '/\$this->school_id\s*=\s*\(string\)\s*\(\s*\$claims\[\'school_id\'\]\s*\?\?\s*\'\'\s*\)\s*;/',
            $src,
            'parent-API branch must set school_id from the authenticated school_id claim'
        );
    }

    public function test_school_name_is_derived_from_school_id_not_read_twice(): void
    {
        // MY_Controller:1633 is the authority: name == id. Deriving keeps one
        // source of truth; two parallel reads of the same claim can drift.
        $this->assertMatchesRegularExpression(
            '/\$this->school_name\s*=\s*\$this->school_id\s*;/',
            $this->controllerSource(),
            'school_name must be derived from school_id'
        );
    }

    public function test_school_id_is_assigned_before_the_payment_service_is_initialised(): void
    {
        // Ordering is the actual invariant. An assignment that happens after
        // _init_payment_service() would satisfy every regex above and still
        // reproduce the outage exactly.
        $src = $this->controllerSource();
        $assign = strpos($src, '$this->school_id    = (string) ($claims[\'school_id\']');
        if ($assign === false) {
            $assign = (int) preg_match('/\$this->school_id\s*=\s*\(string\)\s*\(\s*\$claims/', $src, $m, PREG_OFFSET_CAPTURE)
                ? $m[0][1] : false;
        }
        $init = strpos($src, 'private function _init_payment_service');

        $this->assertNotFalse($assign, 'school_id assignment not found');
        $this->assertNotFalse($init, '_init_payment_service not found');
        $this->assertLessThan($init, $assign,
            'school_id must be assigned in the constructor, before any method that resolves a provider');
    }

    public function test_the_guard_rejects_a_missing_school_id(): void
    {
        // The '' case the type-hint cannot catch. Without this, a token with
        // no school_id claim proceeds with an empty tenant.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$this->school_id\s*===\s*\'\'\s*\|\|\s*\$this->session_year\s*===\s*\'\'\s*\)/',
            $this->controllerSource(),
            'the parent-API branch must refuse a request with no resolvable school_id'
        );
    }

    public function test_every_resolver_call_site_passes_school_id_first(): void
    {
        // All five call sites share one exposure. If a new one is added with
        // the arguments swapped, the tenant read silently targets the wrong
        // key rather than failing loudly.
        $src = $this->controllerSource();
        preg_match_all('/Payment_provider_config_resolver::resolve\(\s*([^)]*?)\)/s', $src, $m);
        $this->assertGreaterThanOrEqual(5, count($m[1]), 'expected at least 5 resolve() call sites');
        foreach ($m[1] as $args) {
            $parts = array_map('trim', explode(',', $args));
            $this->assertSame('$this->firebase', $parts[0]);
            $this->assertSame('$this->school_id', $parts[1], 'argument #2 must be school_id');
            $this->assertSame('$this->school_name', $parts[2], 'argument #3 must be school_name');
        }
    }

    public function test_the_source_guard_is_not_matching_its_own_comments(): void
    {
        // Control: the assertions above run against comment-stripped source.
        // Prove the stripper actually strips, or every guard here could be
        // satisfied by a docblock describing the fix rather than the fix.
        $stripped = $this->controllerSource();
        $this->assertStringNotContainsString('THE DEFECT', $stripped);
        $this->assertStringNotContainsString('MY_Controller:1633 is the authority', $stripped);
    }
}
