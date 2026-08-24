<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeLifecycleFirestore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/libraries/Lifecycle_resolver.php';
require_once __DIR__ . '/../../application/helpers/lifecycle_guard_helper.php';

/**
 * Per-operation web authorization gate (defect N1).
 *
 * Before this gate, tenant state was checked at login and then only on a
 * 300-second timer, and even then it merely forced a logout. An admin of a
 * school suspended or closed mid-session kept FULL WRITE ACCESS for up to
 * five minutes — and because the PHP backend writes through the Admin SDK,
 * firestore.rules never saw those writes either.
 *
 * These tests pin the decision table of the replacement, including the two
 * properties that make it safe to ship: it never GRANTS anything, and an
 * unreadable tenant state degrades to the previous behaviour rather than to
 * an outage.
 *
 * Each test uses a distinct schoolId because lifecycle_web_verdict()
 * memoizes per request, keyed by school.
 */
class LifecycleWebGateTest extends TestCase
{
    private int $seq = 0;

    /** Bind a fake tenant into the CI singleton and return its schoolId. */
    private function tenant(string $commercial, ?string $institution): string
    {
        $schoolId = 'SCH_WG_' . (++$this->seq) . '_' . bin2hex(random_bytes(2));

        $f = new FakeLifecycleFirestore();
        $f->seed('schoolControl', $schoolId, ['lifecycle' => ['state' => $commercial]]);
        $school = ['schoolId' => $schoolId];
        if ($institution !== null) $school['institution'] = ['state' => $institution];
        $f->seed('schools', $schoolId, $school);

        $CI = get_instance();
        $CI->school_id = $schoolId;
        $CI->firebase  = $f;
        return $schoolId;
    }

    private function unreadableTenant(): string
    {
        $schoolId = 'SCH_WG_DEAD_' . (++$this->seq);
        $f = new FakeLifecycleFirestore();
        $f->throwOnRead = true;
        $CI = get_instance();
        $CI->school_id = $schoolId;
        $CI->firebase  = $f;
        return $schoolId;
    }

    // ── Normal operation is untouched ─────────────────────────────────

    public function test_active_tenant_allows_both_read_and_write_levels(): void
    {
        $this->tenant('active', 'ACTIVE');
        foreach (['view', 'edit', 'manage'] as $level) {
            $r = lifecycle_allows_operation('Fees', $level);
            $this->assertTrue($r['allowed'], "level {$level} must be allowed on an active school");
        }
    }

    public function test_legacy_tenant_with_no_institution_field_is_unaffected(): void
    {
        $this->tenant('active', null);
        $this->assertTrue(lifecycle_allows_operation('Fees', 'manage')['allowed'],
            'a tenant predating this feature must behave exactly as before');
    }

    // ── The N1 window, closed ─────────────────────────────────────────

    public function test_commercially_suspended_tenant_is_blocked_per_operation(): void
    {
        $this->tenant('suspended', 'ACTIVE');
        $r = lifecycle_allows_operation('Fees', 'manage');
        $this->assertFalse($r['allowed'],
            'an in-session admin of a suspended school must be blocked immediately, '
            . 'not up to 300 seconds later');
        $this->assertSame('TENANT_SUSPENDED', $r['code']);
        $this->assertNotSame('', $r['message']);
    }

    public function test_every_denying_commercial_state_blocks_writes(): void
    {
        foreach (['past_due', 'suspended', 'expired', 'provisioning_incomplete'] as $state) {
            $this->tenant($state, 'ACTIVE');
            $this->assertFalse(lifecycle_allows_operation('Fees', 'manage')['allowed'],
                "commercial state '{$state}' must block writes");
        }
    }

    // ── CLOSED: read-only, but documents survive ──────────────────────

    public function test_closed_school_blocks_ordinary_writes_but_allows_reads(): void
    {
        $this->tenant('active', 'CLOSED');
        $this->assertTrue(lifecycle_allows_operation('Fees', 'view')['allowed'],
            'historical fee records must stay readable after closure');
        $this->assertFalse(lifecycle_allows_operation('Fees', 'manage')['allowed'],
            'a closed school must not take new fee operations');
        $this->assertSame('SCHOOL_CLOSED', lifecycle_allows_operation('Fees', 'manage')['code']);
    }

    public function test_certificates_remain_issuable_after_closure(): void
    {
        // The whole point of CLOSED-not-off: a parent can still request a
        // transfer certificate, and a mark sheet can still be reprinted.
        $this->tenant('active', 'CLOSED');
        foreach (['Certificates', 'Results'] as $module) {
            $this->assertTrue(lifecycle_allows_operation($module, 'manage')['allowed'],
                "{$module} must remain issuable on a CLOSED school");
        }
    }

    public function test_certificates_are_NOT_issuable_once_archived(): void
    {
        $this->tenant('active', 'ARCHIVED');
        foreach (['Certificates', 'Results'] as $module) {
            $this->assertFalse(lifecycle_allows_operation($module, 'manage')['allowed'],
                "{$module} must be denied on an ARCHIVED school (SA-mediated only)");
        }
    }

    public function test_certificate_exemption_does_not_leak_to_a_suspended_tenant(): void
    {
        // The document carve-out is an INSTITUTION-axis concession. It must
        // not become a way to keep working on a tenant that stopped paying.
        $this->tenant('suspended', 'ACTIVE');
        $this->assertFalse(lifecycle_allows_operation('Certificates', 'manage')['allowed'],
            'commercial suspension must still block certificate issuance');
    }

    public function test_archived_school_blocks_even_reads(): void
    {
        $this->tenant('active', 'ARCHIVED');
        $this->assertFalse(lifecycle_allows_operation('SIS', 'view')['allowed']);
        $this->assertSame('SCHOOL_ARCHIVED', lifecycle_allows_operation('SIS', 'view')['code']);
    }

    public function test_closing_school_still_operates_normally(): void
    {
        $this->tenant('active', 'CLOSING');
        foreach (['view', 'edit', 'manage'] as $level) {
            $this->assertTrue(lifecycle_allows_operation('Attendance', $level)['allowed'],
                'a CLOSING school must finish its running year');
        }
    }

    public function test_onboarding_school_allows_configuration_writes(): void
    {
        $this->tenant('active', 'ONBOARDING');
        $this->assertTrue(lifecycle_allows_operation('Configuration', 'manage')['allowed'],
            'ONBOARDING exists precisely so the school can be configured');
    }

    // ── Fail posture ──────────────────────────────────────────────────

    public function test_unreadable_tenant_state_fails_open_for_the_web_gate(): void
    {
        $this->unreadableTenant();
        $r = lifecycle_allows_operation('Fees', 'manage');
        $this->assertTrue($r['allowed'],
            'a Firestore blip must not brick every school admin panel — the login '
            . 'gate and client rules still apply, so this degrades to prior behaviour');
        $this->assertFalse($r['known']);
    }

    public function test_no_tenant_context_is_a_no_op(): void
    {
        $CI = get_instance();
        $CI->school_id = '';
        $CI->firebase  = null;
        $this->assertTrue(lifecycle_allows_operation('Fees', 'manage')['allowed'],
            'login pages and Superadmin surfaces have no tenant context and must be untouched');
    }

    // ── Transport safety carve-out ────────────────────────────────────

    public function test_sos_survives_commercial_suspension(): void
    {
        // B2_lifecycle_job flips tenants to `suspended` automatically when
        // grace lapses — unattended and date-driven, so it can fire while a
        // bus is mid-route. The emergency channel must not be a billing lever.
        $this->tenant('suspended', 'ACTIVE');
        foreach (['sos_raise', 'sos_respond', 'sos_resolve'] as $action) {
            $this->assertTrue(
                lifecycle_allows_operation('Transport', 'manage', $action)['allowed'],
                "{$action} must survive commercial suspension"
            );
        }
    }

    public function test_finishing_a_running_trip_survives_commercial_suspension(): void
    {
        $this->tenant('suspended', 'ACTIVE');
        foreach (['trip_complete', 'override_complete_trip', 'trip_cancel',
                  'trip_drop_student', 'trip_checkpoint_reached'] as $action) {
            $this->assertTrue(
                lifecycle_allows_operation('Transport', 'edit', $action)['allowed'],
                "{$action} must be able to close out a trip already on the road"
            );
        }
    }

    public function test_starting_a_new_trip_does_NOT_survive_suspension(): void
    {
        // The carve-out lets a suspended school FINISH what is running. It
        // must not let it dispatch more.
        $this->tenant('suspended', 'ACTIVE');
        foreach (['trip_start', 'trip_create', 'event_dispatch_trip'] as $action) {
            $this->assertFalse(
                lifecycle_allows_operation('Transport', 'manage', $action)['allowed'],
                "{$action} must NOT be exempted — a suspended school dispatches nothing new"
            );
        }
    }

    public function test_the_carve_out_does_not_reopen_general_tenant_access(): void
    {
        $this->tenant('suspended', 'ACTIVE');
        // Non-safety Transport actions stay blocked…
        foreach (['save_vendor', 'delete_route', 'bulk_assign', 'compliance_waive'] as $action) {
            $this->assertFalse(lifecycle_allows_operation('Transport', 'manage', $action)['allowed'],
                "Transport/{$action} must stay blocked");
        }
        // …and every other module is untouched by it.
        foreach (['Fees', 'Accounting', 'SIS', 'HR', 'Attendance', 'Communication'] as $module) {
            $this->assertFalse(lifecycle_allows_operation($module, 'manage', 'sos_raise')['allowed'],
                "{$module} must not inherit the Transport safety carve-out via the action name");
        }
    }

    public function test_safety_carve_out_ends_at_closure(): void
    {
        // A CLOSED or ARCHIVED school runs no buses, so there is nothing to
        // keep safe and the carve-out must not act as a back door.
        foreach (['CLOSED', 'ARCHIVED'] as $state) {
            $this->tenant('active', $state);
            foreach (['sos_raise', 'trip_complete'] as $action) {
                $this->assertFalse(
                    lifecycle_allows_operation('Transport', 'manage', $action)['allowed'],
                    "{$action} must be denied on a {$state} school"
                );
            }
        }
    }

    public function test_safety_actions_still_work_normally_when_active(): void
    {
        $this->tenant('active', 'ACTIVE');
        $this->assertTrue(lifecycle_allows_operation('Transport', 'manage', 'sos_raise')['allowed']);
        $this->tenant('active', 'CLOSING');
        $this->assertTrue(lifecycle_allows_operation('Transport', 'manage', 'sos_raise')['allowed'],
            'a closing school still runs buses until its effective date');
    }

    // ── The gate can only ever DENY ───────────────────────────────────

    public function test_gate_never_grants_anything_rbac_would_deny(): void
    {
        // Contract check: the gate returns a boolean that is ANDed with RBAC.
        // It exposes no path that returns anything other than allowed/denied,
        // so it cannot widen access. Assert the shape it promises.
        $this->tenant('active', 'ACTIVE');
        $r = lifecycle_allows_operation('Fees', 'manage');
        $this->assertSame(['allowed', 'code', 'message', 'known'], array_keys($r));
        $this->assertIsBool($r['allowed']);
        $this->assertArrayNotHasKey('grant', $r);
        $this->assertArrayNotHasKey('level', $r);
    }
}
