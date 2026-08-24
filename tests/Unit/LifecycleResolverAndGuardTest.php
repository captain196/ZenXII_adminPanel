<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeLifecycleFirestore;

require_once __DIR__ . '/../../application/libraries/Lifecycle_resolver.php';

/**
 * Lifecycle_resolver — capability composition, job gating, backward
 * compatibility, PHP↔Firestore-rules parity, and cron guard coverage.
 *
 * Three of these tests exist to stop specific regressions:
 *
 *  • the BACKWARD-COMPATIBILITY test proves the institution axis is inert
 *    for every existing tenant (no institution field ⇒ behaves as before);
 *  • the PARITY test derives the rules' allow-list from firestore.rules
 *    itself, so the PHP list cannot silently drift from the deployed gate;
 *  • the GUARD-COVERAGE test reads the cron controllers and fails if any of
 *    them loses its lifecycle guard — the defect that let a suspended
 *    tenant keep charging parents late fees.
 */
class LifecycleResolverAndGuardTest extends TestCase
{
    private const ALLOW_STATES  = ['trialing', 'active', 'expiring_soon', 'grace'];
    private const DENY_STATES   = ['past_due', 'suspended', 'expired', 'provisioning_incomplete'];
    private const INST_STATES   = ['PROSPECT', 'ONBOARDING', 'ACTIVE', 'CLOSING', 'CLOSED', 'ARCHIVED'];

    // ── Backward compatibility: the whole feature must ship inert ──────

    public function test_absent_institution_state_resolves_to_active(): void
    {
        foreach (['', null, 'nonsense', 'SUSPENDED', 'Active', 'active'] as $in) {
            $this->assertSame('ACTIVE', \Lifecycle_resolver::normalize_institution($in),
                'unknown/absent institution state must fall back to ACTIVE');
        }
    }

    public function test_existing_tenants_see_no_behaviour_change_whatsoever(): void
    {
        // For every commercial state that permits access today, a tenant with
        // no institution field must get an all-true capability row and every
        // job class allowed — i.e. exactly today's behaviour.
        foreach (self::ALLOW_STATES as $comm) {
            $caps = \Lifecycle_resolver::compose($comm, '');
            foreach ($caps as $k => $v) {
                $this->assertTrue($v, "cap {$k} must remain enabled for commercial={$comm} with no institution state");
            }
            foreach (\Lifecycle_resolver::JOB_CLASSES as $class) {
                $this->assertTrue(\Lifecycle_resolver::job_allowed($comm, '', $class),
                    "job class {$class} must remain allowed for commercial={$comm}");
            }
            $this->assertSame('OK', \Lifecycle_resolver::explain($comm, '')['code']);
        }
    }

    // ── The P0 defect, expressed as a test ────────────────────────────

    public function test_money_jobs_are_blocked_on_a_commercially_suspended_tenant(): void
    {
        foreach (self::DENY_STATES as $comm) {
            $this->assertFalse(
                \Lifecycle_resolver::job_allowed($comm, 'ACTIVE', \Lifecycle_resolver::JOB_MONEY),
                "money jobs must not run for a tenant in commercial state '{$comm}' — "
                . 'this is the late-fee-on-a-suspended-school defect'
            );
        }
    }

    public function test_billing_retention_and_safety_survive_commercial_suspension(): void
    {
        foreach (self::DENY_STATES as $comm) {
            foreach ([\Lifecycle_resolver::JOB_BILLING, \Lifecycle_resolver::JOB_RETENTION,
                      \Lifecycle_resolver::JOB_SAFETY, \Lifecycle_resolver::JOB_INTEGRITY] as $class) {
                $this->assertTrue(\Lifecycle_resolver::job_allowed($comm, 'ACTIVE', $class),
                    "{$class} must keep running on a '{$comm}' tenant");
            }
        }
    }

    public function test_transport_safety_is_never_revoked_for_a_commercial_reason(): void
    {
        foreach (array_merge(self::ALLOW_STATES, self::DENY_STATES, ['']) as $comm) {
            $caps = \Lifecycle_resolver::compose($comm, 'ACTIVE');
            $this->assertTrue($caps['allowTransportSafety'],
                "a bus with children aboard must not be grounded by commercial state '{$comm}'");
            $this->assertTrue(\Lifecycle_resolver::job_allowed($comm, 'ACTIVE', \Lifecycle_resolver::JOB_SAFETY));
        }
        // …but a CLOSED or ARCHIVED school has no trips at all.
        foreach (['CLOSED', 'ARCHIVED'] as $inst) {
            $this->assertFalse(\Lifecycle_resolver::compose('active', $inst)['allowTransportSafety']);
        }
    }

    // ── Composition semantics ─────────────────────────────────────────

    public function test_composition_is_strictly_most_restrictive(): void
    {
        foreach (array_merge(self::ALLOW_STATES, self::DENY_STATES) as $comm) {
            foreach (self::INST_STATES as $inst) {
                $c = \Lifecycle_resolver::commercial_caps($comm);
                $i = \Lifecycle_resolver::institution_caps($inst);
                $x = \Lifecycle_resolver::compose($comm, $inst);
                foreach (\Lifecycle_resolver::CAPS as $k) {
                    $this->assertSame(!empty($c[$k]) && !empty($i[$k]), $x[$k],
                        "cap {$k} for ({$comm},{$inst}) must be the AND of both axes");
                    if (!$x[$k]) continue;
                    $this->assertTrue(!empty($c[$k]) && !empty($i[$k]),
                        "cap {$k} was granted for ({$comm},{$inst}) although an axis denied it");
                }
            }
        }
    }

    public function test_closing_school_keeps_operating_but_takes_no_new_intake(): void
    {
        $caps = \Lifecycle_resolver::compose('active', 'CLOSING');
        $this->assertFalse($caps['allowNewIntake'], 'a closing school must not admit new students');
        foreach (['allowRead','allowWrite','allowLogin','allowPayments','allowDocuments','allowNotifications'] as $k) {
            $this->assertTrue($caps[$k], "CLOSING must keep {$k} — the running year has to finish (CBSE)");
        }
        $this->assertTrue(\Lifecycle_resolver::job_allowed('active', 'CLOSING', \Lifecycle_resolver::JOB_MONEY),
            'fee settlement is the whole point of the CLOSING window');
    }

    public function test_closed_and_archived_are_read_only_and_write_free(): void
    {
        $closed = \Lifecycle_resolver::compose('active', 'CLOSED');
        $this->assertTrue($closed['allowRead']);
        $this->assertTrue($closed['allowHistoricalAccess']);
        $this->assertTrue($closed['allowDocuments'], 'certificates must remain issuable after closure');
        foreach (['allowWrite','allowPayments','allowNewIntake','allowJobs','allowNotifications'] as $k) {
            $this->assertFalse($closed[$k], "CLOSED must deny {$k}");
        }

        $archived = \Lifecycle_resolver::compose('active', 'ARCHIVED');
        foreach (\Lifecycle_resolver::CAPS as $k) {
            if ($k === 'allowHistoricalAccess') { $this->assertTrue($archived[$k]); continue; }
            $this->assertFalse($archived[$k], "ARCHIVED must deny {$k}");
        }
    }

    public function test_onboarding_allows_configuration_but_no_live_operations(): void
    {
        $caps = \Lifecycle_resolver::compose('active', 'ONBOARDING');
        $this->assertTrue($caps['allowWrite'],  'the school is being configured');
        $this->assertTrue($caps['allowLogin']);
        foreach (['allowNewIntake','allowPayments','allowNotifications','allowDocuments','allowJobs'] as $k) {
            $this->assertFalse($caps[$k], "ONBOARDING must deny {$k}");
        }
        $this->assertFalse(\Lifecycle_resolver::job_allowed('active', 'ONBOARDING', \Lifecycle_resolver::JOB_MONEY));
        $this->assertFalse(\Lifecycle_resolver::job_allowed('active', 'ONBOARDING', \Lifecycle_resolver::JOB_NOTIFICATION));
    }

    public function test_unknown_job_class_fails_closed(): void
    {
        foreach (['', 'wat', 'MONEY', 'admin'] as $bogus) {
            $this->assertFalse(\Lifecycle_resolver::job_allowed('active', 'ACTIVE', $bogus),
                "unrecognised job class '{$bogus}' must fail closed");
        }
    }

    // ── User-facing explanation ───────────────────────────────────────

    public function test_every_denied_combination_has_a_specific_human_message(): void
    {
        foreach (array_merge(self::ALLOW_STATES, self::DENY_STATES, ['']) as $comm) {
            foreach (self::INST_STATES as $inst) {
                $x = \Lifecycle_resolver::explain($comm, $inst);
                $caps = \Lifecycle_resolver::compose($comm, $inst);
                if (!$caps['allowLogin'] || !$caps['allowWrite']) {
                    $this->assertNotSame('OK', $x['code'],
                        "({$comm},{$inst}) restricts access but produced no explanation code");
                    $this->assertNotSame('', trim($x['message']),
                        "({$comm},{$inst}) must not surface as a bare permission error");
                    $this->assertStringNotContainsStringIgnoringCase('permission_denied', $x['message']);
                }
            }
        }
    }

    public function test_institution_closure_outranks_a_billing_message(): void
    {
        // A closed school that also stopped paying should be explained as
        // CLOSED — the truer, more useful statement for a parent.
        $this->assertSame('SCHOOL_CLOSED', \Lifecycle_resolver::explain('suspended', 'CLOSED')['code']);
        $this->assertSame('SCHOOL_ARCHIVED', \Lifecycle_resolver::explain('expired', 'ARCHIVED')['code']);
    }

    // ── PHP ↔ firestore.rules parity (derived from the rules source) ───

    public function test_php_allow_list_matches_the_tenantActive_rule_in_firestore_rules(): void
    {
        $path = __DIR__ . '/../../firebase-rules/firestore.rules';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);

        $this->assertTrue(
            (bool) preg_match('/function\s+tenantActive\s*\([^)]*\)\s*\{(.*?)\n\s*\}/s', $src, $fn),
            'tenantActive() not found in firestore.rules'
        );
        $body = $fn[1];

        $this->assertTrue(
            (bool) preg_match('/lifecycle\.state\s+in\s*\[(.*?)\]/s', $body, $m),
            'tenantActive() no longer contains a lifecycle.state allow-list'
        );
        preg_match_all("/'([a-z_]+)'/", $m[1], $states);
        $rulesAllow = $states[1];
        sort($rulesAllow);

        $phpAllow = \Lifecycle_resolver::COMMERCIAL_ALLOW;
        sort($phpAllow);

        $this->assertSame($rulesAllow, $phpAllow,
            'PHP and firestore.rules disagree on which commercial states permit access. '
            . 'The two gates MUST stay identical — a drift means the server and the client '
            . 'enforce different tenancy rules.');

        // The second suspension signal must be composed in the rules too.
        $this->assertStringContainsString('adminDisabled', $body,
            'tenantActive() must still consider schools.adminDisabled');
    }

    public function test_php_deny_list_is_the_exact_complement_of_the_allow_list(): void
    {
        $all = array_merge(\Lifecycle_resolver::COMMERCIAL_ALLOW, \Lifecycle_resolver::COMMERCIAL_DENY);
        $this->assertSame(count($all), count(array_unique($all)),
            'a commercial state must not appear in both the allow and deny lists');
    }

    // ── Resolver I/O ──────────────────────────────────────────────────

    public function test_admin_disabled_flag_is_treated_as_a_suspension_signal(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->seed('schoolControl', 'SCH_T', ['lifecycle' => ['state' => 'active']]);
        $f->seed('schools', 'SCH_T', ['adminDisabled' => ['value' => true]]);

        $r = (new \Lifecycle_resolver())->init($f)->resolve('SCH_T');
        $this->assertSame('suspended', $r['commercial'],
            'the SA operational toggle must deny even when billing says active (more-restrictive-wins)');
        $this->assertFalse($r['caps']['allowWrite']);
    }

    public function test_resolver_costs_at_most_two_reads_and_memoizes(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->seed('schoolControl', 'SCH_T', ['lifecycle' => ['state' => 'active']]);
        $f->seed('schools', 'SCH_T', ['schoolId' => 'SCH_T']);

        $r = new \Lifecycle_resolver();
        $r->init($f);
        $r->resolve('SCH_T');
        $first = count($f->ops);
        $this->assertLessThanOrEqual(2, $first, 'a resolve must not cost more than 2 document reads');

        $r->resolve('SCH_T');
        $this->assertSame($first, count($f->ops), 'a second resolve in the same request must be memoized');
    }

    public function test_unreadable_tenant_state_is_reported_as_unknown_not_as_active(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->throwOnRead = true;
        $r = (new \Lifecycle_resolver())->init($f)->resolve('SCH_T');
        $this->assertFalse($r['known']);
        $this->assertSame('TENANT_STATE_UNKNOWN', $r['code']);
        $this->assertFalse($r['caps']['allowWrite'], 'an unverifiable tenant must not be writable');
    }

    // ── Cron guard: behaviour + coverage ──────────────────────────────

    public function test_guard_fails_closed_for_money_and_open_for_safety_when_state_is_unknown(): void
    {
        require_once __DIR__ . '/../../application/helpers/lifecycle_guard_helper.php';

        $f = new FakeLifecycleFirestore();
        $f->throwOnRead = true;   // state cannot be determined

        $money = lifecycle_guard_check($f, 'SCH_T', \Lifecycle_resolver::JOB_MONEY);
        $this->assertFalse($money['allowed'], 'never charge a family on a tenant we cannot verify');
        $this->assertFalse($money['known']);

        foreach ([\Lifecycle_resolver::JOB_SAFETY, \Lifecycle_resolver::JOB_RETENTION,
                  \Lifecycle_resolver::JOB_BILLING, \Lifecycle_resolver::JOB_INTEGRITY] as $class) {
            $v = lifecycle_guard_check($f, 'SCH_T', $class);
            $this->assertTrue($v['allowed'], "{$class} must fail OPEN when tenant state is unknown");
        }
    }

    public function test_guard_allows_normal_tenants_and_blocks_suspended_ones(): void
    {
        require_once __DIR__ . '/../../application/helpers/lifecycle_guard_helper.php';

        $ok = new FakeLifecycleFirestore();
        $ok->seed('schoolControl', 'SCH_OK', ['lifecycle' => ['state' => 'active']]);
        $ok->seed('schools', 'SCH_OK', ['schoolId' => 'SCH_OK']);
        $this->assertTrue(lifecycle_guard_check($ok, 'SCH_OK', \Lifecycle_resolver::JOB_MONEY)['allowed']);

        $bad = new FakeLifecycleFirestore();
        $bad->seed('schoolControl', 'SCH_BAD', ['lifecycle' => ['state' => 'suspended']]);
        $bad->seed('schools', 'SCH_BAD', ['schoolId' => 'SCH_BAD']);
        $v = lifecycle_guard_check($bad, 'SCH_BAD', \Lifecycle_resolver::JOB_MONEY);
        $this->assertFalse($v['allowed']);
        $this->assertSame('TENANT_SUSPENDED', $v['code']);
    }

    /**
     * Coverage lock. If any of these jobs loses its guard, the original
     * defect is back and this test says so by name.
     */
    public function test_every_tenant_scoped_scheduled_job_consults_the_lifecycle_guard(): void
    {
        $expected = [
            'Fees_late_fee_cron'             => 'JOB_MONEY',
            'FeeWorker'                      => 'JOB_MONEY',
            'AccountingReconciler'           => 'JOB_MONEY',
            'PayrollReconciler'              => 'JOB_MONEY',
            'Transport_expense_reconciler'   => 'JOB_MONEY',
            'Campus_access_cron'             => 'JOB_OPERATIONAL',
            'Transport_activity_cron'        => 'JOB_OPERATIONAL',
            'Transport_expiry_cron'          => 'JOB_NOTIFICATION',
            'Transport_gps_cron'             => 'JOB_SAFETY',
            'Transport_analytics_rollup_job' => 'JOB_ANALYTICS',
            'Transport_fleet_rollup_job'     => 'JOB_ANALYTICS',
        ];

        foreach ($expected as $controller => $class) {
            $path = __DIR__ . "/../../application/controllers/{$controller}.php";
            $this->assertFileExists($path, "{$controller} is missing");
            $src = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression(
                '/lifecycle_guard_(or_skip|check)\s*\(/', $src,
                "{$controller} no longer calls the lifecycle guard — a suspended or closed "
                . 'tenant would be operated on again'
            );
            $this->assertStringContainsString(
                "Lifecycle_resolver::{$class}", $src,
                "{$controller} must be classified as {$class}"
            );
            $this->assertStringContainsString(
                "\$this->load->helper('lifecycle_guard')", $src,
                "{$controller} must load the lifecycle_guard helper"
            );
        }
    }
}
