<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Rollout_grant_service.php';

/**
 * INCIDENT REGRESSION — granting one rollout flag must never delete another.
 *
 * WHAT HAPPENED IN PRODUCTION (2026-08-18)
 * `Rollout_grant_service::set()` sent `['rolloutFlags' => [$flag => $entry]]` with
 * merge=true, believing a masked PATCH deep-merges nested map keys. It does not:
 * `updateMask.fieldPaths=rolloutFlags` REPLACES the whole map. Granting
 * USE_ENTITLEMENT_BILLING therefore DELETED the live USE_FEE_HEAD_REGISTRY grant,
 * leaving the tenant in the billing-on / M5-off combination DEC-006 exists to prevent.
 *
 * WHY THE ORIGINAL TEST DID NOT CATCH IT
 * `T3RolloutGrantTest::test_a_valid_grant_writes_one_nested_key_by_merge` asserted the
 * OUTGOING PAYLOAD SHAPE against a double that merely recorded calls. A recording double
 * cannot express "the server replaced the masked field", so the payload looked right while
 * the stored result was destructive.
 *
 * THE FIX HERE: the double below SIMULATES FIRESTORE'S ACTUAL updateMask SEMANTICS —
 * a masked field is REPLACED wholesale, sibling top-level fields are preserved. Run this
 * file against the pre-fix implementation and it fails on the very first scenario.
 */
class RolloutGrantSiblingPreservationTest extends TestCase
{
    private const UAT = 'SCH_B56BB9A401';

    /**
     * A Firestore double with REAL merge semantics:
     *   merge=true  → each supplied TOP-LEVEL key replaces that key wholesale
     *                 (this is `updateMask.fieldPaths=<key>`); unsupplied
     *                 top-level keys are left untouched.
     *   merge=false → the whole document is replaced.
     * That distinction is precisely what the incident turned on.
     */
    private function fb(array $doc = [])
    {
        return new class($doc) {
            public array $store;
            public function __construct(array $doc) { $this->store = $doc; }
            public function firestoreGet(string $c, string $id): ?array
            {
                if ($c === 'schools') return ['id' => $id];              // school exists
                return $this->store === [] ? null : $this->store;
            }
            public function firestoreSet(string $c, string $id, array $data, bool $merge = false): bool
            {
                if (!$merge) { $this->store = $data; return true; }
                foreach ($data as $k => $v) $this->store[$k] = $v;       // REPLACE per masked key
                return true;
            }
        };
    }

    private function svc($fb): Rollout_grant_service
    {
        return (new Rollout_grant_service())->init($fb, 'SUP0001');
    }

    private function flags($fb): array
    {
        return array_keys($fb->store['rolloutFlags'] ?? []);
    }

    // ══ THE EXACT PRODUCTION SEQUENCE ════════════════════════════════════

    /** Steps 1-6 of the mandated scenario, in order, against real merge semantics. */
    public function test_granting_three_flags_in_sequence_preserves_all_of_them(): void
    {
        $fb  = $this->fb(['schoolId' => self::UAT, 'subscription' => ['planId' => 'PLAN_X']]);
        $svc = $this->svc($fb);

        // 1-2. Grant M5 and verify it exists.
        $this->assertTrue($svc->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'M5 UAT')['ok']);
        $this->assertSame(['USE_FEE_HEAD_REGISTRY'], $this->flags($fb));

        // 3-4. Grant billing — BOTH must survive. This is the exact production failure.
        $this->assertTrue($svc->set(self::UAT, 'USE_ENTITLEMENT_BILLING', true, 'SE-V2 UAT')['ok']);
        $this->assertSame(['USE_FEE_HEAD_REGISTRY', 'USE_ENTITLEMENT_BILLING'], $this->flags($fb),
            'granting billing must NOT delete the M5 grant — this is the incident');

        // 5-6. A third flag; all three survive.
        $this->assertTrue($svc->set(self::UAT, 'ENTITLEMENT_SHADOW_COMPARE', true, 'shadow')['ok']);
        $this->assertSame(
            ['USE_FEE_HEAD_REGISTRY', 'USE_ENTITLEMENT_BILLING', 'ENTITLEMENT_SHADOW_COMPARE'],
            $this->flags($fb));

        // 7-8. Revoke one; the others survive.
        $this->assertTrue($svc->revoke(self::UAT, 'USE_ENTITLEMENT_BILLING', 'UAT over')['ok']);
        $this->assertSame(['USE_FEE_HEAD_REGISTRY', 'ENTITLEMENT_SHADOW_COMPARE'], $this->flags($fb),
            'revoking one flag must not remove its siblings');

        // 9. And the surviving grants keep their ORIGINAL metadata, not a rewrite.
        $this->assertSame('M5 UAT', $fb->store['rolloutFlags']['USE_FEE_HEAD_REGISTRY']['reason']);
    }

    /** Re-granting the SAME flag updates it in place without touching siblings. */
    public function test_updating_a_flag_preserves_siblings_and_replaces_only_itself(): void
    {
        $fb  = $this->fb(['schoolId' => self::UAT]);
        $svc = $this->svc($fb);
        $svc->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'first');
        $svc->set(self::UAT, 'ENTITLEMENT_SHADOW_COMPARE', true, 'shadow');
        $svc->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'second — corrected reason');

        $this->assertSame(['USE_FEE_HEAD_REGISTRY', 'ENTITLEMENT_SHADOW_COMPARE'], $this->flags($fb));
        $this->assertSame('second — corrected reason',
            $fb->store['rolloutFlags']['USE_FEE_HEAD_REGISTRY']['reason']);
        $this->assertSame('shadow', $fb->store['rolloutFlags']['ENTITLEMENT_SHADOW_COMPARE']['reason']);
    }

    /** A DISABLE grant is still a write — it too must preserve siblings. */
    public function test_disabling_one_flag_preserves_the_others(): void
    {
        $fb  = $this->fb(['schoolId' => self::UAT]);
        $svc = $this->svc($fb);
        $svc->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'm5');
        $svc->set(self::UAT, 'USE_ENTITLEMENT_BILLING', false, 'kill switch');

        $this->assertSame(['USE_FEE_HEAD_REGISTRY', 'USE_ENTITLEMENT_BILLING'], $this->flags($fb));
        $this->assertTrue($fb->store['rolloutFlags']['USE_FEE_HEAD_REGISTRY']['enabled']);
        $this->assertFalse($fb->store['rolloutFlags']['USE_ENTITLEMENT_BILLING']['enabled']);
    }

    // ══ SIBLING TOP-LEVEL FIELDS ════════════════════════════════════════

    /** Merge protects sibling TOP-LEVEL fields — that part was always true. */
    public function test_no_other_control_document_field_is_disturbed(): void
    {
        $fb = $this->fb(['schoolId' => self::UAT,
                         'subscription' => ['planId' => 'PLAN_7FBE49'],
                         'lifecycle'    => ['state' => 'active', 'computedAt' => 1781004361],
                         'featureFlags' => []]);
        $this->svc($fb)->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'm5');

        $this->assertSame('PLAN_7FBE49', $fb->store['subscription']['planId']);
        $this->assertSame(1781004361,    $fb->store['lifecycle']['computedAt']);
        $this->assertSame([],            $fb->store['featureFlags']);
        $this->assertSame(self::UAT,     $fb->store['schoolId']);
    }

    // ══ THE IMPLEMENTATION USES READ-MODIFY-WRITE ═══════════════════════

    public function test_set_reads_the_existing_map_before_writing(): void
    {
        $code = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'libraries/Rollout_grant_service.php')) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringContainsString('$current[$flag] = $entry;', $code,
            'set() must read-modify-write the FULL map, as revoke() already did');
        $this->assertStringNotContainsString('[self::FIELD => [$flag => $entry]]', $code,
            'the single-key payload is what deleted a live grant — it must not return');
    }

    /** DEC-006 still enforced, and still evaluated against the CURRENT map. */
    public function test_the_M5_dependency_still_holds_after_the_fix(): void
    {
        $fb = $this->fb(['schoolId' => self::UAT]);
        $r  = $this->svc($fb)->set(self::UAT, 'USE_ENTITLEMENT_BILLING', true, 'no m5 yet');
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->flags($fb), 'a refused grant writes nothing');
    }
}
