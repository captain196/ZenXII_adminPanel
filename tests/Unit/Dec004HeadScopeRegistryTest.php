<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';
require_once __DIR__ . '/../../application/libraries/Entitlement_service.php';

/**
 * DEC-004 — a head-scoped entitlement must name a REGISTERED head.
 *
 * Why. `_fingerprintId` hashes `scope` as a raw string, so `head:FH_TUI` (the
 * school-scoped registry ref) and `head:FH_ROWID` (a legacy chart-row id) hash
 * differently ⇒ two documents ⇒ both approvable ⇒ both match a registry-stamped
 * demand (reader tiers 1 and 2) ⇒ `_applyDeductions` SUMS them. The two id
 * formats are shape-identical, so nothing downstream can tell them apart.
 *
 * Option (e), REFUSE rather than translate: no id mapping, no name matching, no
 * compatibility layer, and `_fingerprintId` untouched. The stored scope is
 * canonical because nothing else is accepted.
 *
 * NO Firestore. Every test uses doubles; no entitlement record is created.
 */
class Dec004HeadScopeRegistryTest extends TestCase
{
    /** Registry double — the ONE authority, faked at its own boundary. */
    private function fsDouble(array $heads)
    {
        return new class($heads) {
            public array $reads = [];
            public function __construct(private array $heads) {}
            public function schoolCode(): string { return 'SCH_B56BB9A401'; }
            public function get(string $col, string $docId): ?array
            {
                $this->reads[] = $docId;
                return $this->heads[$docId] ?? null;
            }
        };
    }

    private function head(string $ref, string $cat = 'academic', string $status = 'active'): array
    {
        return ['feeHeadId' => $ref, 'schoolId' => 'SCH_B56BB9A401', 'name' => 'Tuition Fee',
                'category' => $cat, 'status' => $status];
    }

    private function svc(array $heads = [])
    {
        $s  = (new ReflectionClass(Entitlement_service::class))->newInstanceWithoutConstructor();
        $fs = $this->fsDouble($heads);
        $fb = new class {
            public array $created = [];
            public function firestoreCreateStatus($c, $id, $d) { $this->created[] = $id; return ['status' => 'SUCCESS']; }
            public function firestoreGet($c, $id) { return null; }
            public function firestoreSet($c, $id, $d, $m = false) { return true; }
        };
        (function () use ($fs, $fb) {
            $this->schoolId = 'SCH_B56BB9A401'; $this->session = '2026-27'; $this->actorId = 'SSA0011';
            $this->fs = $fs; $this->firebase = $fb;
            $this->headResolver = (new Fee_head_resolver())->init($fs);
        })->call($s);
        return [$s, $fb, $fs];
    }

    private function input(array $over = []): array
    {
        return array_merge([
            'studentId' => 'STU0001', 'kind' => 'concession', 'concessionType' => 'sibling',
            'scope' => 'head:FH_AAAAAAAAAAAA', 'valueType' => 'percent', 'value' => 10,
            'effectiveFrom' => '2026-04-01', 'reason' => 'sibling of STU0002',
        ], $over);
    }

    // ══ 1-4 · what is accepted, and what is refused ══════════════════════

    public function test_1_a_registered_feeHeadRef_is_accepted(): void
    {
        [$svc, $fb] = $this->svc(['SCH_B56BB9A401_FH_AAAAAAAAAAAA' => $this->head('FH_AAAAAAAAAAAA')]);
        $res = $svc->propose($this->input());
        $this->assertTrue($res['ok'], (string) ($res['error'] ?? ''));
        $this->assertCount(1, $fb->created);
    }

    public function test_2_an_unregistered_head_target_is_refused(): void
    {
        [$svc, $fb] = $this->svc([]);   // empty registry
        $res = $svc->propose($this->input());
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('not registered for this school', $res['error']);
        $this->assertSame([], $fb->created, 'nothing may be written on refusal');
    }

    /** An FH-shaped string is not evidence of anything. */
    public function test_3_an_arbitrary_FH_shaped_target_is_refused(): void
    {
        [$svc, $fb] = $this->svc(['SCH_B56BB9A401_FH_AAAAAAAAAAAA' => $this->head('FH_AAAAAAAAAAAA')]);
        $res = $svc->propose($this->input(['scope' => 'head:FH_DEADBEEF1234']));
        $this->assertFalse($res['ok']);
        $this->assertSame([], $fb->created);
    }

    /** The name is display identity and is mutable — never an authority. */
    public function test_4_a_name_based_head_target_is_refused(): void
    {
        [$svc, $fb] = $this->svc(['SCH_B56BB9A401_FH_AAAAAAAAAAAA' => $this->head('FH_AAAAAAAAAAAA')]);
        foreach (['head:Tuition Fee', 'head:tuition fee', 'head:Tuition'] as $scope) {
            $res = $svc->propose($this->input(['scope' => $scope]));
            $this->assertFalse($res['ok'], "'{$scope}' must be refused");
        }
        $this->assertSame([], $fb->created);
    }

    /** Fail closed on every unhealthy registry state, never open. */
    public function test_archived_and_unclassified_heads_are_refused(): void
    {
        [$svc] = $this->svc(['SCH_B56BB9A401_FH_AAAAAAAAAAAA' => $this->head('FH_AAAAAAAAAAAA', 'academic', 'archived')]);
        $this->assertFalse($svc->propose($this->input())['ok']);

        [$svc2] = $this->svc(['SCH_B56BB9A401_FH_AAAAAAAAAAAA' => $this->head('FH_AAAAAAAAAAAA', 'unknown')]);
        $r = $svc2->propose($this->input());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no valid category', $r['error']);
    }

    // ══ 5-6 · the other scopes are untouched ═════════════════════════════

    public function test_5_scope_all_remains_valid_and_reads_no_registry(): void
    {
        [$svc, $fb, $fs] = $this->svc([]);
        $this->assertTrue($svc->propose($this->input(['scope' => 'all']))['ok']);
        $this->assertSame([], $fs->reads, "an 'all' scope must not cost a registry read");
    }

    public function test_6_category_scope_remains_valid_and_reads_no_registry(): void
    {
        [$svc, $fb, $fs] = $this->svc([]);
        $this->assertTrue($svc->propose($this->input(['scope' => 'category:academic']))['ok']);
        $this->assertSame([], $fs->reads);
    }

    // ══ 7-8 · identity behaviour is unchanged ════════════════════════════

    public function test_7_an_identical_duplicate_proposal_is_suppressed(): void
    {
        [$svc] = $this->svc(['SCH_B56BB9A401_FH_AAAAAAAAAAAA' => $this->head('FH_AAAAAAAAAAAA')]);
        $r = new ReflectionMethod(Entitlement_service::class, '_fingerprintId');
        $r->setAccessible(true);
        $this->assertSame(
            $r->invoke($svc, 'STU0001', $this->input()),
            $r->invoke($svc, 'STU0001', $this->input(['reason' => 'retyped reason'])),
            'same business identity ⇒ same doc id ⇒ create-if-not-exists collides'
        );
    }

    /** Decision B: two DIFFERENT grants on one head must both remain possible. */
    public function test_8_two_distinct_grants_on_the_same_head_stay_distinct(): void
    {
        [$svc] = $this->svc(['SCH_B56BB9A401_FH_AAAAAAAAAAAA' => $this->head('FH_AAAAAAAAAAAA')]);
        $r = new ReflectionMethod(Entitlement_service::class, '_fingerprintId');
        $r->setAccessible(true);

        $sibling = $this->input(['valueType' => 'percent', 'value' => 10]);
        $merit   = $this->input(['valueType' => 'fixed',   'value' => 50]);
        $this->assertNotSame($r->invoke($svc, 'STU0001', $sibling), $r->invoke($svc, 'STU0001', $merit));
        $this->assertTrue($svc->propose($sibling)['ok']);
        $this->assertTrue($svc->propose($merit)['ok']);
    }

    // ══ 9 · scope is not amendable at approval ═══════════════════════════

    public function test_9_scope_cannot_be_changed_during_approval(): void
    {
        $s  = (new ReflectionClass(Entitlement_service::class))->newInstanceWithoutConstructor();
        $fb = new class {
            public array $written = [];
            public function firestoreGet($c, $id) {
                return ['entitlementId' => 'E1', 'schoolId' => 'SCH_B56BB9A401', 'studentId' => 'STU0001',
                        'kind' => 'concession', 'scope' => 'head:FH_AAAAAAAAAAAA', 'valueType' => 'fixed',
                        'value' => 0, 'effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
                        'status' => 'pending_approval', 'requestedBy' => 'parent-uid'];
            }
            public function firestoreSet($c, $id, $d, $m = false) { $this->written = $d; return true; }
        };
        (function () use ($fb) {
            $this->schoolId = 'SCH_B56BB9A401'; $this->session = '2026-27';
            $this->actorId = 'SSA0011'; $this->firebase = $fb;
        })->call($s);

        $res = $s->approve('E1', 'ok', ['scope' => 'head:FH_BBBBBBBBBBBB', 'value' => 25]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('cannot be changed at approval', $res['error']);
        $this->assertSame([], $fb->written, 'refused explicitly — never silently ignored');

        // Re-sending the SAME scope is not a change and must be allowed.
        $ok = $s->approve('E1', 'ok', ['scope' => 'head:FH_AAAAAAAAAAAA', 'valueType' => 'percent', 'value' => 25]);
        $this->assertTrue($ok['ok']);
        $this->assertSame('head:FH_AAAAAAAAAAAA', $fb->written['scope']);
        $this->assertNotContains('scope', $fb->written['amendedFields']);
    }

    // ══ 10-13 · every creation route, and the boundaries ═════════════════

    /** All three creation routes funnel through the ONE validator. */
    public function test_10_11_every_creation_route_shares_the_validation(): void
    {
        $svc = file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
        $this->assertSame(1, substr_count($svc, '$err = $this->_validate($in);'),
            'propose() is the single funnel — all three routes call it');
        $this->assertStringContainsString('_assertRegisteredHead', $svc);

        foreach (['controllers/Entitlements.php',
                  'controllers/Entitlement_requests.php',
                  'controllers/Sis.php'] as $f) {
            $code = file_get_contents(APPPATH . $f);
            $this->assertStringNotContainsString('firestoreSet(\'studentEntitlements\'', $code,
                "{$f} must not bypass the service");
        }
        // Direct admission posts a scope; the bridge now builds it from the picker.
        $this->assertStringContainsString("'scope'                => trim((string) \$this->input->post('ent_scope')),",
            file_get_contents(APPPATH . 'controllers/Sis.php'));
        // The parent route is fixed at 'all' and so never reaches the registry check.
        $this->assertStringContainsString("'scope'            => 'all',",
            file_get_contents(APPPATH . 'controllers/Entitlement_requests.php'));
    }

    public function test_12_the_student360_read_path_is_unchanged(): void
    {
        $code = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'controllers/Student360.php')) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringContainsString("\$this->load->library('Entitlement_service'", $code);
        foreach (['propose', 'approve', 'reject', 'discontinue'] as $w) {
            $this->assertStringNotContainsString("entitlementSvc->{$w}(", $code);
        }
    }

    public function test_13_legacy_head_scopes_remain_READABLE(): void
    {
        $reader = file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $this->assertStringContainsString('$feeHeadId  !== \'\' && $target === $feeHeadId', $reader,
            'tier 2 must survive — creation is narrowed, reading is not');
        $this->assertStringContainsString('$feeHead    !== \'\' && $target === $feeHead', $reader);
        $this->assertStringNotContainsString('ENTITLEMENT_HEAD_SCOPE_SHADOWED', $reader,
            'no reader-side discarding — that option was rejected');
    }

    // ══ 14-15 · safety ═══════════════════════════════════════════════════

    public function test_14_no_entitlement_record_is_created_by_these_tests(): void
    {
        // Every write in this file lands in a double. Pin the intent so nobody
        // later swaps a double for a live handle.
        [$svc, $fb] = $this->svc([]);
        $svc->propose($this->input());
        $this->assertSame([], $fb->created);
        $this->assertFalse(class_exists('Firebase', false), 'the real Firebase class must never load here');
    }

    public function test_15_identity_and_gross_semantics_are_untouched(): void
    {
        $svc = file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
        $this->assertStringContainsString(
            "return \$this->schoolId . '_' . \$studentId . '_E' . substr(sha1(implode('|', \$parts)), 0, 20);", $svc,
            '_fingerprintId byte-identical — DEC-004 narrows creation, it does not canonicalise');
        $this->assertStringNotContainsString("'feeHeadRef'     =>", $svc,
            'no second identity field on the entitlement record');

        $this->assertStringContainsString("'grossAmount' => \$amount",
            file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'), 'DEC-001 untouched');
        $this->assertStringContainsString("\$config['USE_ENTITLEMENT_BILLING'] = true",
            file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'));
        $this->assertStringContainsString("\$config['USE_FEE_HEAD_REGISTRY'] = true",
            file_get_contents(APPPATH . 'config/fee_head_registry.php'));
    }
}
