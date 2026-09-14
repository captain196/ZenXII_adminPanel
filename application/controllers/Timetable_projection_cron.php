<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Timetable_plan_store.php';

/**
 * Timetable_projection_cron — T2 scheduled compatibility projection.
 *
 * Materialises the timetable that the canonical resolver says is in force into
 * the legacy `timetables` collection, because the Teacher app reads that
 * collection directly through the client SDK and cannot be repointed without an
 * app release.
 *
 * ── WHY A SCHEDULED COMPONENT EXISTS AT ALL ─────────────────────────────
 *
 * Effective-date activation is a RESOLVER concern, not a scheduler one:
 * resolveForDate() already answers "which version applies today" from the
 * versions themselves, with no flag to flip and no job to run. This cron exists
 * for one reason — the legacy surface is MATERIALISED, so somebody has to write
 * today's answer into it. Publish a version today effective next Monday and the
 * resolver is right immediately; the legacy documents are not, until this runs.
 *
 * It is therefore deliberately NOT a second business-rule engine. It asks the
 * resolver one question and copies the answer. It does not choose between
 * versions, break ties, apply precedence, or interpret dates. Every one of those
 * would be a second place where "which timetable is live" is decided, and two
 * such places is the failure this architecture exists to prevent.
 *
 * ── IT FAILS CLOSED ─────────────────────────────────────────────────────
 *
 *   resolved   → project that version, and only that version
 *   none       → nothing is published for this date. Not an error; nothing is
 *                written, and the legacy documents are left exactly as they are.
 *   ambiguous  → two or more published versions cover the date. REFUSE. The
 *                legacy collection holds one document per section per day and
 *                physically cannot represent two timetables, so guessing would
 *                silently pick one and show it to every teacher.
 *   unreadable → we could not determine what is live. REFUSE.
 *
 * ── WRITES REQUIRE APPLY=1 ──────────────────────────────────────────────
 *
 * Dry run by default, following Timetables_reconcile — the only other tool that
 * writes this collection. This is also what makes the switchover coordinated
 * rather than accidental: until the legacy direct writers stop being
 * authoritative, running this with APPLY=1 would create two competing writers
 * of the same documents. The flag is the seam where that is decided by a person.
 *
 *   SCHOOL_ID=SCH_XXXX SESSION_YEAR=2026-2027 \
 *     php index.php timetable_projection_cron run          # dry run, reports
 *
 *   APPLY=1 SCHOOL_ID=... SESSION_YEAR=... \
 *     php index.php timetable_projection_cron run          # writes
 *
 *   Optional: FOR_DATE=YYYY-MM-DD — resolve for a date other than today.
 *             DRY RUN ONLY. APPLY=1 may materialise today and nothing else:
 *             the legacy collection has no effective-date dimension, so
 *             writing another date's timetable into it shows every teacher a
 *             timetable that is over, or has not started. Refused, exit 2.
 *
 * Suggested schedule once switchover is authorised: daily, shortly after
 * midnight, so a version that becomes effective today is materialised before
 * the school day begins.
 *
 * Exit codes: 0 = ran (including "nothing to project"); 1 = env/init failure;
 *             2 = refused or failed — the legacy collection may be stale.
 */
class Timetable_projection_cron extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';
    private string $forDate     = '';
    private bool   $apply       = false;

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Timetable_projection_cron is CLI-only.', 403);
        }
        $this->load->library('firebase');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        $this->forDate     = (string) (getenv('FOR_DATE')     ?: '') ?: date('Y-m-d');
        $this->apply       = (string) (getenv('APPLY')        ?: '') === '1';

        // SCHOOL_ID / SESSION_YEAR are validated in run(), NOT here: run_all()
        // is the platform-level entry point and deliberately takes neither.
        // Requiring them in the constructor is what made the only automatic
        // path a per-school one.
    }

    /**
     * PLATFORM-LEVEL entry point: every eligible tenant, one invocation.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     *
     * ZenX is multi-tenant SaaS. run() below takes SCHOOL_ID and SESSION_YEAR,
     * which means automatic synchronisation would need one scheduled entry per
     * customer — installed by someone with server access, for every school, at
     * onboarding. That is not a product; it is a per-customer deployment task,
     * and it fails the moment a school is onboarded and nobody remembers.
     *
     * This takes NO tenant arguments. It discovers eligible tenants from the
     * `schools` collection and their sessions from `currentSession`, so a school
     * onboarded tomorrow is picked up by the next run with no configuration
     * anywhere.
     *
     * ── IT IS NOT A SECOND AUTHORITY ────────────────────────────────────
     *
     * Every tenant goes through _projectOne(), which is the SAME method run()
     * uses. There is one code path that resolves a date and one that projects a
     * version; this adds a loop and failure isolation around them, nothing else.
     *
     * ── ONE SCHOOL'S FAILURE MUST NOT STOP THE OTHERS ───────────────────
     *
     * Note lifecycle_guard_check() rather than lifecycle_guard_or_skip(): the
     * latter calls exit(0), which is right for a single-tenant job and would
     * silently end the whole run here on the first suspended school — leaving
     * every tenant after it in the list unprojected, with a success exit code.
     *
     *   APPLY=1 php index.php timetable_projection_cron run_all
     *   php index.php timetable_projection_cron run_all          # dry run
     *
     * Exit: 0 when every eligible tenant was processed or correctly skipped;
     *       2 when at least one FAILED — the others still ran.
     */
    public function run_all(): void
    {
        $startedAt = microtime(true);
        $today     = date('Y-m-d');

        // FOR_DATE is a dry-run affordance on the single-tenant path. Across a
        // whole platform it has no honest meaning — it would materialise one
        // date's timetable for every school at once — so it is ignored here.
        $date = $today;

        echo "Timetable projection — ALL ELIGIBLE TENANTS\n";
        echo "  date:  {$date}\n";
        echo "  mode:  " . ($this->apply ? 'APPLY (writes enabled)' : 'DRY-RUN (no writes)') . "\n";

        try {
            $tenants = $this->_discoverTenants();
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: could not list tenants — " . $e->getMessage() . "\n"
                . "No school was processed. This is a platform failure, not a "
                . "tenant one.\n");
            exit(2);
        }

        echo "  tenants discovered: " . count($tenants) . "\n\n";

        $this->load->helper('lifecycle_guard');

        $results = ['projected' => 0, 'current' => 0, 'nothing' => 0,
                    'skipped' => 0, 'failed' => 0];
        $failures = [];

        foreach ($tenants as $t) {
            $schoolId = $t['schoolId'];
            $session  = $t['session'];
            $label    = $schoolId . ' / ' . $session;

            // Per tenant, and nothing below may escape it. A school that throws
            // is recorded and the loop continues; the alternative is that one
            // broken tenant hides every other school's timetable being stale.
            try {
                $gate = lifecycle_guard_check($this->firebase, $schoolId,
                    Lifecycle_resolver::JOB_OPERATIONAL);

                if (empty($gate['allowed'])) {
                    $results['skipped']++;
                    printf("  SKIP     %-34s %s\n", $label,
                        'lifecycle: ' . (string) ($gate['code'] ?? 'not allowed'));
                    $this->_recordTenantRun($schoolId, $session, $date, 'skipped',
                        ['reason' => (string) ($gate['code'] ?? ''), 'written' => 0]);
                    continue;
                }

                $out = $this->_projectOne($schoolId, $session, $date, $this->apply);

                // A refusal is not a quiet skip. "We could not tell which
                // timetable is live" must make the RUN fail, or a school whose
                // versions overlap would be reported as healthy every night.
                if ($out['outcome'] === 'refused') {
                    $results['failed']++;
                    $why = (string) ($out['summary'] ?? 'refused') . ' — '
                         . (string) ($out['resolution']['explain'] ?? '');
                    $failures[] = $label . ': ' . $why;
                    fwrite(STDERR, sprintf("  REFUSED  %-34s %s\n", $label, $why));
                    $this->_recordTenantRun($schoolId, $session, $date, 'refused',
                        ['error' => $why, 'written' => 0]);
                    continue;
                }

                $results[$out['outcome']] = ($results[$out['outcome']] ?? 0) + 1;
                printf("  %-8s %-34s %s\n", strtoupper($out['outcome']), $label,
                    $out['summary']);
                $this->_recordTenantRun($schoolId, $session, $date, $out['outcome'], $out);

            } catch (\Throwable $e) {
                $results['failed']++;
                $failures[] = $label . ': ' . $e->getMessage();
                fwrite(STDERR, sprintf("  FAILED   %-34s %s\n", $label, $e->getMessage()));
                log_message('error', 'timetable_projection_cron run_all: ' . $label
                    . ' failed: ' . $e->getMessage());
                $this->_recordTenantRun($schoolId, $session, $date, 'failed',
                    ['error' => $e->getMessage(), 'written' => 0]);
            }
        }

        $dur = round(microtime(true) - $startedAt, 1);
        echo "\n" . str_repeat('─', 60) . "\n";
        printf("  projected %d · already current %d · nothing to do %d · skipped %d · FAILED %d   (%ss)\n",
            $results['projected'], $results['current'], $results['nothing'],
            $results['skipped'], $results['failed'], $dur);

        if ($results['failed'] > 0) {
            fwrite(STDERR, "\n" . $results['failed'] . " tenant(s) FAILED. The rest were "
                . "processed:\n  " . implode("\n  ", $failures) . "\n");
            exit(2);
        }
        exit(0);
    }

    /**
     * Which tenants and sessions exist, from the tenant authority itself.
     *
     * ── THE TWO TRAPS IN THIS COLLECTION ────────────────────────────────
     *
     * `schools` does not contain only schools. Alongside the tenant documents it
     * holds `{schoolId}_profile` documents (five of them on the live project)
     * and at least one field-less stub. Enumerating by document id — or by a
     * `SCH_` prefix — would hand both to the projection as if they were
     * customers.
     *
     * So a tenant is a document that CLAIMS to be one: it carries a `schoolId`
     * field, and that field equals its own document id. `_profile` documents and
     * the stub carry no `schoolId` at all, so they fall out without needing a
     * name pattern to exclude them.
     *
     * Eligibility is deliberately NEGATIVE — a school is processed unless
     * something says otherwise:
     *
     *   • `status` is present on only half the live tenants, so requiring
     *     status == 'active' would silently stop projecting for the other half.
     *     It excludes only when explicitly set to something that is not active.
     *   • `adminDisabled` is a MAP ({value, reason, actor, updatedAt}), not a
     *     boolean. `if ($school['adminDisabled'])` is TRUE for every tenant that
     *     has the map — including every enabled one — so the flag is read at
     *     `.value` and nowhere else.
     *   • no `currentSession` means the school has not been set up far enough to
     *     have a timetable. Skipped, and said so, rather than guessed.
     *
     * The deeper lifecycle question (suspended, closing, closed) is NOT answered
     * here. That is Lifecycle_resolver's, and run_all() asks it per tenant.
     */
    private function _discoverTenants(): array
    {
        $rows = $this->firebase->firestoreQueryOrFail('schools', [], null, 'ASC', 5000);

        $tenants = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $d  = is_array($row['data'] ?? null) ? $row['data'] : $row;

            $verdict = self::tenantFromSchoolDoc($id, $d);
            if ($verdict['eligible'] !== true) {
                // A document that is simply not a tenant (a `_profile` row) is
                // not worth a line; anything that LOOKS like a school and is
                // being passed over is, or a school silently stops receiving
                // timetables and nobody can see why.
                if ($verdict['reason'] !== 'not_a_tenant_document') {
                    echo "  (skipping {$id}: {$verdict['reason']})\n";
                }
                if ($verdict['reason'] === 'identity_mismatch') {
                    log_message('error', 'timetable_projection_cron: schools/' . $id
                        . ' claims a different schoolId; refusing to project it');
                }
                continue;
            }

            $tenants[$verdict['schoolId']] = [
                'schoolId' => $verdict['schoolId'],
                'session'  => $verdict['session'],
            ];
        }

        // Stable order so two runs produce comparable transcripts. Keyed and
        // ksort()ed rather than usort()ed with a comparator: a comparator in
        // this file would be indistinguishable, to a reader and to the test
        // that polices it, from the version-ranking logic that must never live
        // here. Ordering tenants is not ordering timetables.
        ksort($tenants);
        return array_values($tenants);
    }

    /**
     * Is this `schools` document a tenant this job should process, and in which
     * session?
     *
     * Pure and static so the rule can be exercised directly against fixtures —
     * including the case that matters most for a SaaS, a school onboarded
     * yesterday — rather than only inferred from live data that happens to
     * contain no counter-examples today.
     *
     * ── WHAT IS A TENANT ────────────────────────────────────────────────
     *
     * A document whose ID is a tenant ID: SCH_ followed by 4-32 alphanumerics
     * and nothing else (Superadmin_schools' own shape). That single rule
     * disposes of the `{schoolId}_profile` rows that share this collection,
     * without a name blacklist — '_' is not alphanumeric.
     *
     * Deliberately NOT keyed on the `schoolId` FIELD. B2_registry_service
     * records that older school documents do not reliably carry one, so
     * requiring it would silently stop projecting for exactly the oldest
     * customers. When the field IS present it must agree with the id: a
     * document claiming to be a different tenant is a tenant-isolation fault,
     * and is refused under either identity rather than guessed at.
     *
     * ── ELIGIBILITY IS NEGATIVE ─────────────────────────────────────────
     *
     * A school is processed unless something says otherwise, because the cost
     * of the two mistakes is not symmetric: wrongly skipping a school means its
     * teachers quietly see the wrong timetable, while wrongly including one
     * means a no-op (it has no published version) or a correct projection.
     *
     *   status          excludes only when explicitly set to something other
     *                   than active. It is absent on half the live tenants, so
     *                   requiring status === 'active' would stop projecting for
     *                   every school that predates the field.
     *   adminDisabled   is a MAP {value, reason, actor, updatedAt}. Read as a
     *                   boolean it is truthy for every school that HAS it —
     *                   including every enabled one — so the whole platform
     *                   would be skipped and the run would report success.
     *   currentSession  the declared sole session authority (MY_Controller and
     *                   every login path fail closed without it). Absent means
     *                   the school is not set up far enough to have a timetable.
     *
     * The deeper lifecycle question — suspended, closing, closed — is NOT
     * answered here. That belongs to Lifecycle_resolver, and run_all() asks it
     * per tenant through lifecycle_guard_check().
     *
     * @return array{eligible:bool, schoolId:string, session:string, reason:string}
     */
    public static function tenantFromSchoolDoc(string $id, $data): array
    {
        $no = function (string $reason) use ($id) {
            return ['eligible' => false, 'schoolId' => $id, 'session' => '',
                    'reason' => $reason];
        };

        if (!is_array($data)) { return $no('not_a_tenant_document'); }
        if (!preg_match('/^SCH_[A-Za-z0-9]{4,32}$/', $id)) {
            return $no('not_a_tenant_document');
        }

        $claimed = trim((string) ($data['schoolId'] ?? ''));
        if ($claimed !== '' && $claimed !== $id) {
            return $no('identity_mismatch');
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if ($status !== '' && $status !== 'active') {
            return $no('status=' . $status);
        }

        $disabled = $data['adminDisabled'] ?? null;
        if ((is_array($disabled) && !empty($disabled['value'])) || $disabled === true) {
            return $no('admin disabled');
        }

        $session = trim((string) ($data['currentSession'] ?? ''));
        if ($session === '') {
            return $no('no current session set');
        }

        return ['eligible' => true, 'schoolId' => $id, 'session' => $session,
                'reason' => ''];
    }

    /**
     * Project ONE tenant. The single code path both entry points use.
     *
     * Returns a structured outcome and never exits, never echoes a verdict, and
     * never decides anything the resolver has not already decided:
     *
     *   projected  a version was in force and was materialised (or would be)
     *   current    in force, and the apps already match it — nothing written
     *   nothing    no published version covers this date. A correct no-op
     *   (throws)   ambiguous, unreadable, or the projection failed
     *
     * It throws rather than exiting so run_all() can record the tenant and carry
     * on to the next school.
     */
    private function _projectOne(string $schoolId, string $session,
                                 string $date, bool $apply): array
    {
        $store = (new Timetable_plan_store())->init(
            $this->firebase, $schoolId, $session, 'timetable_projection_cron');

        $r        = $store->resolveForDate($date);
        $decision = Timetable_plan_service::projectionDecision($r);

        if ($decision['action'] === 'skip') {
            return ['outcome' => 'nothing', 'written' => 0, 'versionId' => '',
                    'resolution' => $r, 'decision' => $decision,
                    'summary' => 'nothing published covers this date'];
        }
        if ($decision['action'] !== 'project') {
            // Ambiguous or unreadable. We do not know what is live, so nothing
            // is written. Returned rather than thrown so each caller can report
            // it in its own voice — the single-tenant run prints the competing
            // versions, the platform run counts the tenant and carries on —
            // while both still get the verdict from this one place.
            return ['outcome' => 'refused', 'written' => 0, 'versionId' => '',
                    'resolution' => $r, 'decision' => $decision,
                    'summary' => (string) ($decision['reason'] ?? 'refused')];
        }

        $version   = $r['version'];
        $planId    = (string) ($version['planId'] ?? '');
        $versionId = (string) ($version['versionId'] ?? '');

        $plan = $store->projectPublishedVersion($planId, $versionId, $apply);

        $dx      = is_array($plan['diagnosis'] ?? null) ? $plan['diagnosis'] : [];
        $pending = count($plan['writes'] ?? []);

        if ($pending === 0) {
            return ['outcome' => 'current', 'written' => 0, 'versionId' => $versionId,
                    'resolution' => $r, 'decision' => $decision, 'plan' => $plan,
                    'summary' => 'already matches ' . $versionId,
                    'diagnosis' => (string) ($dx['summary'] ?? '')];
        }

        return [
            'outcome'    => 'projected',
            'written'    => (int) ($plan['written'] ?? 0),
            'pending'    => $pending,
            'versionId'  => $versionId,
            'planId'     => $planId,
            'resolution' => $r,
            'decision'   => $decision,
            'plan'       => $plan,
            'summary'    => $apply
                ? ((int) ($plan['written'] ?? 0)) . ' document(s) written from ' . $versionId
                : $pending . ' document(s) WOULD be written from ' . $versionId,
            'diagnosis'  => (string) ($dx['summary'] ?? ''),
        ];
    }

    /**
     * One record per tenant per run, in the existing scheduled_job_runs
     * convention, so "did school X get its timetable today" is answerable
     * without reading a log file on a server nobody has access to.
     *
     * Never allowed to break the run: a school whose outcome could not be
     * recorded still had its timetable projected, and losing the record is
     * strictly better than losing the projection.
     */
    private function _recordTenantRun(string $schoolId, string $session,
                                      string $date, string $outcome, array $detail): void
    {
        try {
            $runId = 'TTP_' . date('Ymd') . '_' . $schoolId . '_' . $session;
            $this->firebase->firestoreSet('scheduled_job_runs', $runId, [
                'runId'      => $runId,
                'job'        => 'timetable_projection',
                'scope'      => 'platform',
                'schoolId'   => $schoolId,
                'session'    => $session,
                'forDate'    => $date,
                'outcome'    => $outcome,
                'applied'    => $this->apply,
                'versionId'  => (string) ($detail['versionId'] ?? ''),
                'written'    => (int) ($detail['written'] ?? 0),
                'diagnosis'  => (string) ($detail['diagnosis'] ?? ''),
                'error'      => (string) ($detail['error'] ?? ''),
                'reason'     => (string) ($detail['reason'] ?? ''),
                'finishedAt' => date('c'),
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'timetable_projection_cron: could not record run for '
                . $schoolId . ': ' . $e->getMessage());
        }
    }

    public function run(): void
    {
        // Single-tenant entry point — kept for manual and targeted runs. The
        // platform path is run_all(), which needs neither of these.
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            fwrite(STDERR, "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables,\n"
                . "       or use `run_all` to process every eligible tenant.\n");
            exit(1);
        }

        // ── LIFECYCLE GUARD ─────────────────────────────────────────────
        // operational: materialising the timetable teachers see is day-to-day
        // school operation. It stops when the tenant is suspended or the school
        // is closed, and continues through CLOSING — a school still running
        // classes until its effective closure date still needs its timetable.
        $this->load->helper('lifecycle_guard');
        lifecycle_guard_or_skip($this->firebase, $this->schoolFs,
            Lifecycle_resolver::JOB_OPERATIONAL, 'timetable_projection_cron');

        echo "Timetable compatibility projection\n";
        echo "  school:  {$this->schoolFs}\n";
        echo "  session: {$this->sessionYear}\n";
        echo "  date:    {$this->forDate}\n";
        echo "  mode:    " . ($this->apply ? "APPLY (writes enabled)" : "DRY-RUN (no writes)") . "\n\n";

        // ── FOR_DATE + APPLY: only today may be MATERIALISED ────────────
        //
        // The legacy document id is {school}_{session}_{sectionKey}_{WEEKDAY}.
        // There is no effective-date dimension in it, so that collection holds
        // exactly one timetable: the one teachers see NOW. Materialising any
        // other date therefore writes a timetable that is not in force as
        // though it were — silently, and with a success exit code.
        //
        //   a PAST date    → teachers are shown a timetable that has ended
        //   a FUTURE date  → teachers are shown one that has not started
        //
        // Neither is ever the intent, and neither is recoverable by noticing:
        // the projected documents look exactly like a correct projection. The
        // resolver is unaffected — only this materialisation is wrong — so the
        // damage is invisible on the canonical screens that would reveal it.
        //
        // FOR_DATE stays fully available for DRY RUNS, which is what it is for:
        // "what would be projected on the 13th?" answers a real question and
        // writes nothing. This mirrors supersedeVersion(), which refuses a past
        // last-effective-date for the same reason — a dated operation must not
        // change what already happened, or what has not happened yet.
        $today = date('Y-m-d');
        if ($this->apply && $this->forDate !== $today) {
            fwrite(STDERR,
                "REFUSED: APPLY=1 may only materialise TODAY ({$today}); FOR_DATE was "
                . "{$this->forDate}.\n"
                . "The legacy collection has no effective-date dimension — it holds the "
                . "one timetable teachers see now — so writing another date's timetable "
                . "into it would show every teacher a timetable that is "
                . ($this->forDate < $today ? "already over" : "not yet in force") . ".\n"
                . "Re-run without APPLY to see what that date resolves to, or without "
                . "FOR_DATE to materialise today.\n");
            exit(2);
        }

        // ── ONE PATH ────────────────────────────────────────────────────
        //
        // This used to be a second copy of resolve → decide → project, which
        // meant the single-tenant and platform runs could answer the same
        // question differently after any edit to one of them. The transcript
        // below is this method's own; the DECISIONS are all _projectOne's.
        try {
            $out = $this->_projectOne(
                $this->schoolFs, $this->sessionYear, $this->forDate, $this->apply);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
            exit(2);
        }

        $r        = is_array($out['resolution'] ?? null) ? $out['resolution'] : [];
        $decision = is_array($out['decision'] ?? null) ? $out['decision'] : [];

        echo "Resolution: " . (string) ($r['status'] ?? '?') . "\n";
        if (!empty($r['explain'])) echo "  {$r['explain']}\n";

        if ($out['outcome'] === 'nothing') {
            // Leaving the legacy documents untouched is correct: this cron
            // materialises what IS live, and it has no mandate to clear a
            // school's timetable because no canonical version covers today.
            echo "\nNothing published covers this date. No changes made.\n";
            exit((int) ($decision['exitCode'] ?? 0));
        }

        if ($out['outcome'] === 'refused') {
            // ambiguous, unreadable, invalid — every one of them means we do not
            // KNOW what is live.
            fwrite(STDERR, "\nREFUSED: the timetable in force could not be determined "
                . "unambiguously, so nothing was projected. The timetable teachers "
                . "currently see has been left unchanged.\n");
            foreach (($r['candidates'] ?? []) as $c) {
                if (!is_array($c)) continue;
                fwrite(STDERR, "  competing: " . (string) ($c['versionId'] ?? '?')
                    . " (" . (string) ($c['effectiveFrom'] ?? '?') . " → "
                    . ((string) ($c['effectiveTo'] ?? '') ?: 'open') . ")\n");
            }
            exit((int) ($decision['exitCode'] ?? 2));
        }

        $plan      = is_array($out['plan'] ?? null) ? $out['plan'] : [];
        $versionId = (string) ($out['versionId'] ?? '');
        echo "  version: {$versionId} (plan " . (string) ($out['planId'] ?? '') . ")\n\n";

        echo (string) ($plan['explain'] ?? '') . "\n";

        // WHAT KIND of difference, not only how much. A count reads the same
        // whether the apps are one lesson behind or holding a timetable from a
        // version that no longer exists — and this transcript is what an
        // operator reads when the alert fires, so it has to name the state
        // rather than leave it to be inferred from a number.
        $dx = is_array($plan['diagnosis'] ?? null) ? $plan['diagnosis'] : [];
        if ($dx) {
            echo "\n" . (string) ($dx['summary'] ?? '') . "\n";

            $src = is_array($dx['sourceVersions'] ?? null) ? $dx['sourceVersions'] : [];
            if (count($src) > 1) {
                // No single publish can produce this. A partial run can.
                echo "  WARNING: the apps hold a MIXTURE of versions —\n";
                foreach ($src as $vid => $n) { echo "    {$vid}: {$n} document(s)\n"; }
            } elseif ($src) {
                foreach ($src as $vid => $n) {
                    echo "  currently materialised from: {$vid} ({$n} document(s))\n";
                }
            } else {
                echo "  currently materialised from: nothing — no document carries "
                   . "a projection stamp\n";
            }
            if (($dx['lastProjectedAt'] ?? '') !== '') {
                echo "  last materialised at: " . $dx['lastProjectedAt'] . "\n";
            }
        }

        if (!empty($plan['orphans'])) {
            echo "\nNot accounted for by this version (reported, NOT deleted):\n";
            foreach ($plan['orphans'] as $o) echo "  {$o}\n";
        }

        if (!$this->apply) {
            echo "\nDry run — nothing was written. Re-run with APPLY=1 to apply.\n";
            exit(0);
        }

        echo "\nProjected {$plan['written']} document(s).\n";
        exit(0);
    }
}
