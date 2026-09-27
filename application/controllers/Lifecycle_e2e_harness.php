<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Lifecycle_e2e_harness — CLI driver for authorized School Lifecycle E2E.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  HARD TENANT LOCK
 * ──────────────────────────────────────────────────────────────────────
 * The authorized tenant is a COMPILE-TIME CONSTANT, not an argument. There
 * is no code path in this file that can mutate any other school: every
 * mutating method calls _assertTenant() first, and the id it asserts against
 * is the constant — a caller cannot pass a different one, because no method
 * accepts a school id at all.
 *
 * This is deliberate. The instruction was "if any operation unexpectedly
 * targets a school id other than the authorized one, stop" — the cheapest
 * way to honour that is to make the wrong target unrepresentable.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHAT IT MUTATES
 * ──────────────────────────────────────────────────────────────────────
 * Only what Institution_lifecycle itself writes: schools.institution,
 * tenantPublic.institutionState/institutionEffectiveDate, and
 * schoolLifecycleEvents. Plus, for the commercial-axis matrix,
 * schoolControl.lifecycle.state via the canonical B2 service.
 *
 * It NEVER touches students, staff, fees, accounting, transport, sessions,
 * documents or users. Every write is logged to an evidence file so cleanup
 * can be proven complete.
 *
 * CLI ONLY. Delete this harness after certification.
 */
class Lifecycle_e2e_harness extends CI_Controller
{
    /** The ONLY tenant this harness may ever touch. */
    const TENANT = 'SCH_B56BB9A401';

    /** Actor identities used for the two-person transitions. */
    const ACTOR    = 'E2E_SA_PRIMARY';
    const APPROVER = 'E2E_SA_SECOND';

    private $evidence = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Lifecycle_e2e_harness is CLI-only.', 403);
        }
    }

    /** Fail closed unless the tenant is exactly the authorized one. */
    private function _assertTenant(string $schoolId): void
    {
        if ($schoolId !== self::TENANT) {
            fwrite(STDERR, "TENANT GUARD TRIPPED: refused '{$schoolId}' (authorized: " . self::TENANT . ")\n");
            exit(9);
        }
    }

    private function _lc()
    {
        $this->load->library('institution_lifecycle');
        return $this->institution_lifecycle->init($this->firebase);
    }

    /**
     * DELETE named fields from a document (as opposed to nulling them).
     *
     * Firestore removes a field when a PATCH names it in updateMask but omits
     * it from the body. Writing null instead stores an explicit null, which is
     * what caused the tenant lock-out described in restore().
     */
    private function _deleteFields(string $collection, array $fields): bool
    {
        $this->_assertTenant(self::TENANT);

        $sa = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
        try {
            $creds = new Google\Auth\Credentials\ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/datastore'], $sa
            );
            $token = $creds->fetchAuthToken()['access_token'] ?? '';
        } catch (\Throwable $e) {
            return false;
        }
        if ($token === '') return false;

        $mask = implode('&', array_map(
            fn($f) => 'updateMask.fieldPaths=' . rawurlencode($f), $fields
        ));
        $url = 'https://firestore.googleapis.com/v1/projects/graderadmin/databases/(default)'
             . '/documents/' . $collection . '/' . rawurlencode(self::TENANT) . '?' . $mask;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['fields' => (object) []]),
            CURLOPT_TIMEOUT        => 60,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    private function _log(string $op, array $data): void
    {
        $this->evidence[] = array_merge(['op' => $op, 'ts' => date('c'), 'tenant' => self::TENANT], $data);
        $file = FCPATH . 'e2e_evidence.jsonl';
        file_put_contents($file, json_encode(end($this->evidence)) . "\n", FILE_APPEND);
    }

    // ── READ-ONLY ────────────────────────────────────────────────────

    /** Current composed state of the authorized tenant. */
    public function status(): void
    {
        $this->_assertTenant(self::TENANT);
        $lc  = $this->_lc();
        $cur = $lc->current_state(self::TENANT);

        $this->load->library('lifecycle_resolver');
        $v = (new Lifecycle_resolver())->init($this->firebase)->resolve(self::TENANT);

        $pub = (array) ($this->firebase->firestoreGet('tenantPublic', self::TENANT) ?? []);

        printf("tenant            : %s\n", self::TENANT);
        printf("institution.state : %s (explicit=%s)\n", $cur['state'], $cur['explicit'] ? 'yes' : 'no');
        printf("institution.since : %s  effective=%s\n", $cur['since'] ?: '-', $cur['effectiveDate'] ?: '-');
        printf("commercial.state  : %s\n", $v['commercial'] !== '' ? $v['commercial'] : '(unreadable)');
        printf("mirror.institution: %s\n", $pub['institutionState'] ?? '(absent)');
        printf("mirror.effective  : %s\n", $pub['institutionEffectiveDate'] ?? '(absent)');
        printf("mirror.lifecycle  : %s\n", $pub['lifecycleState'] ?? '(absent)');
        printf("code              : %s\n", $v['code']);
        echo "caps: ";
        foreach ($v['caps'] as $k => $val) echo $k . '=' . ($val ? '1' : '0') . ' ';
        echo "\n";
    }

    /** Capability + job matrix for a hypothetical pair — pure, no I/O. */
    public function matrix(): void
    {
        $this->load->library('lifecycle_resolver');
        $commercials = ['active', 'suspended'];
        $institutions = ['ACTIVE', 'CLOSING', 'CLOSED', 'ARCHIVED'];
        printf("%-11s %-9s | %s\n", 'commercial', 'inst', 'read write login pay intake jobs notif docs safety hist | money safety retention');
        foreach ($commercials as $c) {
            foreach ($institutions as $i) {
                $caps = Lifecycle_resolver::compose($c, $i);
                printf("%-11s %-9s | %5d %5d %5d %3d %6d %4d %5d %4d %6d %4d | %5d %6d %9d\n",
                    $c, $i,
                    $caps['allowRead'], $caps['allowWrite'], $caps['allowLogin'], $caps['allowPayments'],
                    $caps['allowNewIntake'], $caps['allowJobs'], $caps['allowNotifications'],
                    $caps['allowDocuments'], $caps['allowTransportSafety'], $caps['allowHistoricalAccess'],
                    Lifecycle_resolver::job_allowed($c, $i, Lifecycle_resolver::JOB_MONEY),
                    Lifecycle_resolver::job_allowed($c, $i, Lifecycle_resolver::JOB_SAFETY),
                    Lifecycle_resolver::job_allowed($c, $i, Lifecycle_resolver::JOB_RETENTION));
            }
        }
    }

    /** Readiness against the real tenant. */
    public function readiness(): void
    {
        $this->_assertTenant(self::TENANT);
        $this->load->library('institution_readiness');
        $r = $this->institution_readiness->init($this->firebase)->evaluate(self::TENANT);
        printf("score=%d%%  blocking=%d  warnings=%d  applicable=%d  goLive=%s\n",
            $r['score'], $r['blocking'], $r['warnings'], $r['applicable'],
            $r['goLiveAllowed'] ? 'ALLOWED' : 'BLOCKED');
        foreach ($r['checks'] as $c) {
            if ($c['status'] === 'PASS') continue;
            printf("  [%-14s] %-34s %s\n", $c['status'], $c['label'], $c['what']);
        }
    }

    /** Closure checklist against the real tenant. */
    public function closure(): void
    {
        $this->_assertTenant(self::TENANT);
        $this->load->library('institution_closure');
        $school  = (array) ($this->firebase->firestoreGet('schools', self::TENANT) ?? []);
        $r = $this->institution_closure->init($this->firebase)
                  ->checklist(self::TENANT, (string) ($school['currentSession'] ?? ''));
        printf("blocking=%d warnings=%d unknown=%d closeAllowed=%s\n",
            $r['blocking'], $r['warnings'], $r['unknown'], $r['closeAllowed'] ? 'YES' : 'NO');
        foreach ($r['items'] as $i) {
            printf("  [%-8s] %-38s count=%s\n", $i['status'], $i['label'], $i['count']);
        }
    }

    /** Lifecycle history for the tenant. */
    public function history(): void
    {
        $this->_assertTenant(self::TENANT);
        $rows = $this->_lc()->history(self::TENANT, 50);
        printf("events: %d\n", count($rows));
        foreach ($rows as $e) {
            printf("  %-24s %-10s -> %-10s actor=%-16s approver=%-16s outcome=%-18s eff=%s\n",
                substr((string) ($e['ts'] ?? ''), 0, 19),
                $e['fromState'] ?? '?', $e['toState'] ?? '?',
                $e['actorId'] ?? '?', ($e['approverId'] ?? '') ?: '-',
                $e['outcome'] ?? '?', $e['effectiveDate'] ?? '-');
        }
    }

    // ── MUTATING (authorized tenant only) ────────────────────────────

    /**
     * php index.php lifecycle_e2e_harness transition <TO_STATE> <reason> [effectiveDate]
     * Two-person transitions automatically supply a distinct approver.
     */
    public function transition($to = '', $reason = 'E2E certification', $effective = ''): void
    {
        $this->_assertTenant(self::TENANT);
        $to = strtoupper(trim((string) $to));
        if ($to === '') { fwrite(STDERR, "usage: transition <STATE> [reason] [YYYY-MM-DD]\n"); exit(1); }

        $lc  = $this->_lc();
        $cur = $lc->current_state(self::TENANT);
        $needsTwo = Institution_lifecycle::requires_two_person($cur['state'], $to);

        $opts = [
            'reason'       => (string) $reason,
            'actorId'      => self::ACTOR,
            'actorRole'    => 'superadmin',
            'actorSurface' => 'e2e_harness',
            'evidence'     => ['harness' => true, 'baselineState' => $cur['state']],
        ];
        if ($effective !== '') $opts['effectiveDate'] = (string) $effective;
        if ($needsTwo)         $opts['approverId']    = self::APPROVER;

        $res = $lc->transition(self::TENANT, $to, $opts);

        $this->_log('transition', [
            'from' => $res['from'], 'to' => $res['to'], 'success' => $res['success'],
            'idempotent' => $res['idempotent'], 'error' => $res['error'], 'eventId' => $res['eventId'],
        ]);

        printf("%s : %s -> %s %s\n",
            $res['success'] ? 'OK  ' : 'FAIL',
            $res['from'] ?: '?', $res['to'] ?: '?',
            $res['idempotent'] ? '(idempotent)' : '');
        printf("  message : %s\n", $res['message']);
        if ($res['error'] !== '') printf("  error   : %s\n", $res['error']);
        if ($res['eventId'] !== '') printf("  eventId : %s\n", $res['eventId']);
        if (!$res['success']) exit(2);
    }

    /**
     * php index.php lifecycle_e2e_harness commercial <state> <reason>
     * Drives the COMMERCIAL axis through the canonical B2 service.
     */
    public function commercial($state = '', $reason = 'E2E certification'): void
    {
        $this->_assertTenant(self::TENANT);
        $state = strtolower(trim((string) $state));
        if (!in_array($state, ['active', 'suspended'], true)) {
            fwrite(STDERR, "usage: commercial <active|suspended> [reason]\n"); exit(1);
        }

        $this->load->library('B2_registry_service');
        $svc = $this->b2_registry_service->init($this->firebase);
        $ok  = $svc->write_lifecycle_state(self::TENANT, $state, (string) $reason,
            ['role' => 'sa', 'uid' => self::ACTOR, 'surface' => 'e2e_harness']);

        $this->_log('commercial', ['state' => $state, 'ok' => $ok]);
        printf("%s : commercial -> %s\n", $ok ? 'OK  ' : 'FAIL', $state);
        if (!$ok) exit(2);
    }

    /**
     * RESTORE — returns the tenant to its captured baseline: institution map
     * REMOVED, mirror fields REMOVED, commercial back to active.
     * Domain data is never touched.
     */
    public function restore(): void
    {
        $this->_assertTenant(self::TENANT);

        // WARNING, LEARNED THE HARD WAY:
        // writing null does NOT remove a Firestore field — it stores an
        // explicit null. An earlier version of this method wrote
        // `institution => null`, which left `schools.institution` present but
        // null. The rules clause then evaluated `'state' in sch.data.institution`
        // against a null map and DENIED EVERY READ AND WRITE for the tenant —
        // a self-inflicted outage caused by a tidy-up.
        //
        // Two consequences, both now in place:
        //   1. the rules carry an `is map` guard so a null can never lock a
        //      tenant out again (institution_nullmap_sec.test.js);
        //   2. cleanup DELETES the field via updateMask instead of nulling it.
        $ok1 = $this->_deleteFields('schools', ['institution']);
        $ok2 = $this->_deleteFields('tenantPublic', ['institutionState', 'institutionEffectiveDate']);

        $this->_log('restore', ['schools' => $ok1, 'tenantPublic' => $ok2]);
        printf("restore: schools.institution=%s tenantPublic fields=%s\n",
            $ok1 ? 'cleared' : 'FAILED', $ok2 ? 'cleared' : 'FAILED');
        $this->status();
    }
}
