<?php

use PHPUnit\Framework\TestCase;

/**
 * Timetable synchronisation must be a PLATFORM operation, not a per-customer
 * deployment task.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────
 *
 * The only automatic path was `--schoolId=… --session=…`: one scheduled entry
 * per customer, installed by someone with server access, repeated at every
 * onboarding. For a multi-tenant SaaS that is not a product — it is a manual
 * step that silently fails the first time somebody forgets, and the symptom is
 * a school whose teachers see the wrong timetable with no error anywhere.
 *
 * `run_all` takes no tenant arguments at all. It discovers tenants and sessions
 * from the same authorities the rest of the system uses, so onboarding a school
 * makes it eligible with no configuration, no deployment, and no code change.
 *
 * ── AND IT MUST STILL NOT BE A SECOND AUTHORITY ─────────────────────────
 *
 * Both entry points go through one private method that asks the canonical
 * resolver and copies its answer. This file pins that they cannot diverge.
 */
final class TimetablePlatformSchedulerTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');
    }

    /** Comment-stripped code, for anything order- or absence-sensitive. */
    private function code(): string
    {
        $out = '';
        foreach (token_get_all(self::$src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    private function method(string $name): string
    {
        $code = $this->code();
        $i = strpos($code, "function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        $next = strpos($code, "\n    private function ", $i + 10);
        $alt  = strpos($code, "\n    public function ", $i + 10);
        if ($alt !== false && ($next === false || $alt < $next)) { $next = $alt; }
        return substr($code, $i, ($next !== false ? $next - $i : 12000));
    }

    // ── no school-specific configuration, anywhere ──────────────────────

    /** THE ACCEPTANCE CRITERION. */
    public function test_the_platform_entry_point_takes_no_tenant_arguments(): void
    {
        $body = $this->method('run_all');

        foreach (['SCHOOL_ID', 'SESSION_YEAR', 'schoolId=', 'session='] as $arg) {
            $this->assertStringNotContainsString("getenv('{$arg}", $body,
                "run_all reads {$arg}, so it is still a per-school job");
        }
        $this->assertStringContainsString('_discoverTenants()', $body,
            'run_all does not discover tenants, so somebody must still tell it '
            . 'which schools exist');
    }

    /**
     * No customer may be named in the scheduler. A hardcoded id is a scheduler
     * that works for exactly one school and reports success for the rest.
     */
    public function test_no_school_or_session_is_hardcoded(): void
    {
        $code = $this->code();

        $this->assertSame(0, preg_match_all('~SCH_[A-Z0-9]{6,}~', $code),
            'a school id is hardcoded in the scheduler');
        $this->assertSame(0, preg_match_all('~[\'"]20\d\d-\d\d[\'"]~', $code),
            'a session year is hardcoded in the scheduler, so it would stop '
            . 'working at the next session rollover');
    }

    /**
     * Tenants come from the tenant authority, sessions from the session field
     * that authority carries — not from a config file or a list in this job.
     */
    public function test_tenants_and_sessions_come_from_the_tenant_authority(): void
    {
        $body = $this->method('_discoverTenants');

        $this->assertStringContainsString("firestoreQueryOrFail('schools'", $body,
            'tenant discovery no longer reads the `schools` collection');
        $this->assertStringContainsString("'currentSession'", $body,
            'the session is not taken from the school record, so a school in a '
            . 'different session would be projected against the wrong one');
    }

    /**
     * `schools` holds `{id}_profile` documents and at least one field-less stub
     * beside the real tenants. Discovery must reject them by what they ARE, not
     * by a name pattern — a tenant is a document that claims to be one.
     */
    public function test_only_real_tenant_documents_are_processed(): void
    {
        $body = $this->method('_discoverTenants');

        // The canonical tenant-id shape — SCH_ then alphanumerics and nothing
        // else — which rejects `{id}_profile` on the underscore without needing
        // a name blacklist.
        $this->assertStringContainsString("preg_match('/^SCH_[A-Za-z0-9]{4,32}\$/', \$id)",
            $body,
            'discovery no longer checks the canonical tenant-id shape, so '
            . '`{id}_profile` documents would be handed to the projection as if '
            . 'they were customers');

        // Deliberately NOT keyed on the schoolId FIELD: older school documents
        // do not reliably carry one (B2_registry_service says so), and requiring
        // it would silently stop projecting for the oldest customers.
        $this->assertMatchesRegularExpression(
            "~\\\$claimed\s*!==\s*''\s*&&\s*\\\$claimed\s*!==\s*\\\$id~", $body,
            'a document whose schoolId contradicts its own id is projected '
            . 'anyway — that is a tenant-isolation fault, not a formatting one');
    }

    /**
     * `adminDisabled` is a MAP — {value, reason, actor, updatedAt}. Reading it
     * as a boolean is truthy for EVERY school that has it, including every
     * enabled one, so the whole platform would be skipped and the run would
     * report success.
     */
    public function test_the_disable_flag_is_read_at_its_value_not_as_a_boolean(): void
    {
        $body = $this->method('_discoverTenants');

        $this->assertStringContainsString("\$disabled['value']", $body,
            'adminDisabled is being tested as a boolean; it is a map, so every '
            . 'school carrying it reads as disabled and none would be projected');
        $this->assertMatchesRegularExpression('~is_array\(\$disabled\)~', $body,
            'the map shape is not checked before reading it');
    }

    /**
     * `status` is absent on half the live tenants, so eligibility must be
     * NEGATIVE: exclude only what is explicitly not active. Requiring
     * status === 'active' would silently stop projecting for every school that
     * predates the field.
     */
    public function test_a_missing_status_does_not_exclude_a_school(): void
    {
        $body = $this->method('_discoverTenants');

        $this->assertMatchesRegularExpression(
            "~\\\$status\s*!==\s*''\s*&&\s*\\\$status\s*!==\s*'active'~", $body,
            'eligibility requires status to equal active, so every school without '
            . 'that field — half the live tenants — would stop being projected');
    }

    // ── ONBOARDING: exercised, not inferred ─────────────────────────────

    /**
     * The eligibility rule is loaded directly so it can be run against cases
     * the live data does not currently contain — above all, a school onboarded
     * yesterday. Reading the class file rather than booting CodeIgniter: this
     * method is pure and static precisely so it can be tested this way.
     */
    private function decide(string $id, $data): array
    {
        if (!class_exists('Timetable_projection_cron', false)) {
            $src = self::$src;
            // The controller extends CI_Controller, which is not loadable here.
            // Evaluate ONLY the pure static method, under a stand-in name.
            $i = strpos($src, 'public static function tenantFromSchoolDoc');
            $this->assertNotFalse($i, 'tenantFromSchoolDoc() is gone');
            $end = strpos($src, "\n    /**", $i);
            $fn  = substr($src, $i, $end - $i);
            $fn  = str_replace('public static function tenantFromSchoolDoc',
                               'function zx_tenantFromSchoolDoc', $fn);
            if (!function_exists('zx_tenantFromSchoolDoc')) {
                eval($fn);
            }
        }
        return zx_tenantFromSchoolDoc($id, $data);
    }

    /** SCHOOL B: onboarded with nothing but normal configuration. */
    public function test_a_newly_onboarded_school_is_eligible_with_no_configuration(): void
    {
        $brandNew = [
            'schoolId'       => 'SCH_NEW0001',
            'schoolName'     => 'A School Onboarded Today',
            'currentSession' => '2026-27',
            'sessions'       => ['2026-27'],
            'createdAt'      => date('c'),
        ];

        $v = $this->decide('SCH_NEW0001', $brandNew);

        $this->assertTrue($v['eligible'],
            'a school onboarded through the normal flow is not picked up by the '
            . 'platform job, so somebody would have to add a scheduler entry for '
            . 'it — which is the whole failure being removed. Reason given: '
            . $v['reason']);
        $this->assertSame('2026-27', $v['session'],
            'its session is not taken from its own record');
    }

    /** A school that predates the `status` field keeps being served. */
    public function test_an_older_school_without_a_status_field_stays_eligible(): void
    {
        $v = $this->decide('SCH_OLD0001', [
            'schoolName' => 'An Older Customer', 'currentSession' => '2025-26',
        ]);

        $this->assertTrue($v['eligible'],
            'a school with no `status` field is excluded — that is half the live '
            . 'tenants, and they would silently stop receiving timetables');
        $this->assertSame('2025-26', $v['session'],
            'each school is projected in ITS OWN session, not a shared one');
    }

    /** …and one that predates the `schoolId` field, which older docs lack. */
    public function test_a_school_document_without_a_schoolid_field_is_still_a_tenant(): void
    {
        $v = $this->decide('SCH_LEGACY01', ['currentSession' => '2026-27']);

        $this->assertTrue($v['eligible'],
            'discovery requires a schoolId FIELD, which B2_registry_service says '
            . 'older school documents do not reliably carry — so the oldest '
            . 'customers would be the ones to stop being projected');
    }

    /** The `_profile` rows that share this collection are not customers. */
    public function test_profile_documents_are_not_treated_as_tenants(): void
    {
        // Synthetic ids on purpose. The live `schools` collection contains
        // `{schoolId}_profile` rows beside the real tenants, but naming an
        // actual customer here would make the test read as depending on that
        // customer existing — and it does not: this exercises the rule, not
        // the data.
        foreach (['SCH_AAAA1111_profile', 'SCH_BBBB2222_profile'] as $id) {
            $v = $this->decide($id, ['currentSession' => '2026-27']);
            $this->assertFalse($v['eligible'],
                "{$id} is treated as a customer; it is a profile row that shares "
                . 'the schools collection');
            $this->assertSame('not_a_tenant_document', $v['reason']);
        }
    }

    /** A half-created stub is skipped, and says why. */
    public function test_a_school_with_no_session_is_skipped_with_a_reason(): void
    {
        $v = $this->decide('SCH_202512', []);
        $this->assertFalse($v['eligible']);
        $this->assertStringContainsString('session', $v['reason'],
            'the reason does not say what is missing, so nobody can fix it');
    }

    /** THE MAP TRAP, exercised rather than read. */
    public function test_an_enabled_school_carrying_the_disable_map_stays_eligible(): void
    {
        $enabled = [
            'currentSession' => '2026-27',
            // Exactly the live shape. Truthy as a value; false as a decision.
            'adminDisabled'  => ['value' => false, 'reason' => '',
                                 'actor' => 'SUP0001', 'updatedAt' => '2026-06-09T11:26:01+00:00'],
        ];

        $this->assertTrue($this->decide('SCH_ENABLED1', $enabled)['eligible'],
            'a school carrying adminDisabled as a MAP with value=false reads as '
            . 'disabled. Every live tenant carries that map, so the entire '
            . 'platform would be skipped and the run would report success');

        $disabled = $enabled;
        $disabled['adminDisabled']['value'] = true;
        $this->assertFalse($this->decide('SCH_DISABLED', $disabled)['eligible'],
            'a genuinely disabled school is still processed');
    }

    /** A document claiming another tenant's id is refused outright. */
    public function test_a_document_claiming_a_different_tenant_is_refused(): void
    {
        $v = $this->decide('SCH_AAAA1111', [
            'schoolId' => 'SCH_BBBB2222', 'currentSession' => '2026-27',
        ]);

        $this->assertFalse($v['eligible'],
            'a school document whose schoolId contradicts its own id is projected '
            . "anyway — one tenant's timetable could be written under another's id");
        $this->assertSame('identity_mismatch', $v['reason']);
    }

    /** An explicitly closed or suspended school is left alone. */
    public function test_an_explicitly_inactive_school_is_not_processed(): void
    {
        foreach (['closed', 'suspended', 'archived'] as $status) {
            $v = $this->decide('SCH_INACTIVE', [
                'currentSession' => '2026-27', 'status' => $status,
            ]);
            $this->assertFalse($v['eligible'],
                "a school with status={$status} is still processed");
        }
    }

    // ── one school must not be able to stop the others ──────────────────

    /**
     * lifecycle_guard_or_skip() calls exit(0). Correct for a single-tenant job;
     * catastrophic in a loop, where it would end the run at the first suspended
     * school and leave every tenant after it unprojected — with exit code 0.
     */
    public function test_the_loop_uses_the_non_exiting_lifecycle_check(): void
    {
        $body = $this->method('run_all');

        $this->assertStringContainsString('lifecycle_guard_check(', $body,
            'the platform loop does not consult the lifecycle guard at all');
        $this->assertStringNotContainsString('lifecycle_guard_or_skip(', $body,
            'the platform loop calls the exiting guard, which ends the whole run '
            . 'at the first ineligible school and reports success');
    }

    /** Every tenant is processed inside its own try/catch. */
    public function test_one_tenant_failing_does_not_stop_the_rest(): void
    {
        $body = $this->method('run_all');

        $this->assertMatchesRegularExpression(
            '~foreach\s*\(\$tenants as \$t\)\s*\{[\s\S]*?try\s*\{~', $body,
            'the per-tenant work is not wrapped in try/catch inside the loop, so '
            . 'one broken school hides every other school being stale');
        $this->assertMatchesRegularExpression('~catch \(\\\\Throwable \$e\)[\s\S]{0,600}?\$results\[\'failed\'\]\+\+~', $body,
            'a thrown tenant is not counted as a failure');

        // …and the loop continues rather than rethrowing.
        $this->assertStringNotContainsString('throw $e;', $body,
            'a tenant failure is rethrown, which ends the run');
    }

    /**
     * "We could not tell which timetable is live" must make the RUN fail.
     *
     * A refusal is not a quiet no-op: it is the ambiguous/unreadable case, and
     * counting it as "nothing to do" would report a school whose published
     * versions overlap as healthy every single night, forever.
     */
    public function test_a_refusal_counts_as_a_failure_not_as_nothing_to_do(): void
    {
        $body = $this->method('run_all');

        $this->assertMatchesRegularExpression(
            "~\\\$out\['outcome'\] === 'refused'\s*\)\s*\{\s*\\\$results\['failed'\]\+\+~",
            $body,
            'a refused tenant is not counted as a failure, so a school whose '
            . 'timetable could not be determined is reported as up to date');

        // …and it is recorded under its own outcome, not folded into another.
        $this->assertMatchesRegularExpression(
            "~_recordTenantRun\([^)]*'refused'~", $body,
            'a refusal is recorded under a different outcome, so the run history '
            . 'cannot distinguish "nothing published" from "we could not tell"');
    }

    /** A failure must be visible in the exit code, not only in the log. */
    public function test_any_tenant_failure_makes_the_run_fail(): void
    {
        $body = $this->method('run_all');

        $this->assertMatchesRegularExpression(
            "~\\\$results\['failed'\] > 0[\s\S]{0,600}?exit\(2\)~", $body,
            'a run where some schools failed still exits 0, so a scheduler would '
            . 'report it as healthy');
    }

    /** Losing a bookkeeping write must never lose a projection. */
    public function test_recording_a_result_cannot_break_the_run(): void
    {
        $body = $this->method('_recordTenantRun');

        $this->assertMatchesRegularExpression('~try\s*\{[\s\S]*?catch \(\\\\Throwable~', $body,
            'the per-tenant record write is unguarded; a Firestore blip while '
            . 'recording would abort a school that had already been projected');
    }

    // ── it is still not a second authority ──────────────────────────────

    /** Both entry points share ONE projection path. */
    public function test_both_entry_points_use_the_same_single_code_path(): void
    {
        $code = $this->code();

        $this->assertSame(1, substr_count($code, 'function _projectOne('),
            'the shared per-tenant path is gone or duplicated');
        $this->assertSame(1, substr_count($code, 'projectPublishedVersion('),
            'the projection is invoked from more than one place in this file, so '
            . 'the single-tenant and platform paths can drift apart');
        $this->assertSame(1, substr_count($code, 'resolveForDate('),
            'the resolver is consulted from more than one place, which is a '
            . 'second implementation of "which timetable is live" waiting to happen');
    }

    /**
     * The platform run is TODAY only. FOR_DATE is a dry-run affordance on the
     * single-tenant path; across a whole platform it would materialise one
     * date's timetable for every school at once.
     */
    public function test_the_platform_run_is_todays_date_only(): void
    {
        $body = $this->method('run_all');

        $this->assertMatchesRegularExpression('~\$date\s*=\s*\$today;~', $body,
            'run_all no longer pins the date to today');
        $this->assertStringNotContainsString('$this->forDate', $body,
            'run_all honours FOR_DATE, so a stray value would write a past or '
            . 'future timetable into every school at once');
    }

    // ── observability ───────────────────────────────────────────────────

    /** Per tenant, in the existing convention. */
    public function test_every_tenant_outcome_is_recorded(): void
    {
        $body = $this->method('_recordTenantRun');

        $this->assertStringContainsString("'scheduled_job_runs'", $body,
            'per-tenant outcomes are not recorded in the existing convention');

        foreach (['schoolId', 'session', 'forDate', 'outcome', 'versionId',
                  'written', 'diagnosis', 'error'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "the run record omits '{$field}', so \"did this school get its "
                . 'timetable today, and from which version" cannot be answered');
        }
    }

    /** Skips are recorded too — a silently skipped school looks like a healthy one. */
    public function test_a_skipped_tenant_is_recorded_not_silently_dropped(): void
    {
        $body = $this->method('run_all');

        $this->assertMatchesRegularExpression(
            "~empty\(\\\$gate\['allowed'\]\)[\s\S]{0,700}?_recordTenantRun\([\s\S]{0,200}?'skipped'~",
            $body,
            'a lifecycle-skipped school leaves no record, so a school that is '
            . 'never projected is indistinguishable from one that is up to date');
    }
}
