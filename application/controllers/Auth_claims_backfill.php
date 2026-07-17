<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Auth_claims_backfill — one-off CLI that REPAIRS existing Firebase Auth
 * accounts whose custom claims were minted before the canonical claim
 * builder (Firebase::buildCanonicalClaims) existed.
 *
 * WHY: setCustomUserClaims() replaces the whole claim set, and ~15 mint
 * sites historically hand-wrote divergent arrays — some emitted only
 * snake_case school_id (breaking admin-panel login, which reads camelCase
 * schoolId), some only camelCase (breaking the mobile apps, whose Firestore
 * rules read snake_case school_id), some an empty role, and the student/
 * parent sites emitted no student_id (Parent red-flag listener silently
 * empty). Symptom: "login works but every screen is empty" (PERMISSION_DENIED).
 * New writes are now canonical; this script back-fills accounts already created.
 *
 * HOW: claims-driven normalisation. For each Auth user it reads the account's
 * OWN existing claims, takes whichever casing carries the tenant values, and
 * re-emits them through the SAME canonical builder the runtime now uses — so
 * every repaired token dual-casts school_id/schoolId + school_code/schoolCode
 * + parent_db_key/parentDbKey, carries a non-empty role, and (for student/
 * parent accounts) a student_id. Source-agnostic: no re-derivation of role
 * or login-code from Firestore, so it cannot invent a wrong claim.
 *
 * SAFETY MODEL
 *   - DRY-RUN by default. Pass `commit` to actually write.
 *   - IDEMPOTENT — computes the canonical set and writes ONLY when it differs
 *     from what the account already has. Safe to re-run.
 *   - SKIPS (never touches, reports separately):
 *       * super_admin accounts (cross-tenant, intentionally school-less)
 *       * accounts with NO school_id/schoolId in claims at all — these cannot
 *         be repaired from claims (total mint failure); they need a source-doc
 *         re-mint (re-save the user in the admin panel, which now mints
 *         canonically). Listed so you can act on them.
 *       * empty-role accounts — role is NOT guessed (assigning the wrong role
 *         could over- or under-privilege). Listed for a source re-mint.
 *   - Optional school filter: restrict to a single schoolId.
 *   - Claims REPLACE semantics preserved: unknown/extra claims already on the
 *     account (must_change_password, password_reset_*, …) are carried through.
 *
 * USAGE (from project root):
 *   php index.php auth_claims_backfill run                     # dry-run, all schools
 *   php index.php auth_claims_backfill run all commit          # execute, all schools
 *   php index.php auth_claims_backfill run SCH_ABC123          # dry-run, one school
 *   php index.php auth_claims_backfill run SCH_ABC123 commit   # execute, one school
 */
class Auth_claims_backfill extends CI_Controller
{
    /** @var bool */
    private $commit = false;

    /** @var string 'all' or a specific schoolId */
    private $onlySchool = 'all';

    /** @var array<string,true> UIDs to leave untouched (control group). */
    private $exclude = [];

    /** @var resource|null Rollback log handle (commit only). */
    private $backupFh = null;

    /** @var string|null */
    private $backupPath = null;

    /** @var bool When true, blank-role accounts are resolved from their
     *  authoritative staff/students/admins doc instead of being skipped. */
    private $useSourceDoc = false;

    /** Roles treated as student/parent identity (uid == studentId). */
    private const STUDENT_ROLES = ['student', 'Student', 'parent', 'Parent', 'guardian', 'Guardian'];

    /** Roles left completely untouched (cross-tenant, no school). */
    private const SUPERADMIN_ROLES = ['super_admin', 'Super Admin'];

    /** Claim keys the canonical builder owns; everything else is "extra". */
    private const KNOWN_KEYS = [
        'role', 'roleLabel',
        'school_id', 'schoolId',
        'school_code', 'schoolCode',
        'parent_db_key', 'parentDbKey',
        'student_id', 'student_ids',
        'staffId', 'roleTier',
    ];

    private $stats = [
        'scanned'         => 0,
        'already_ok'      => 0,
        'repaired'        => 0,
        'repaired_srcdoc' => 0,
        'skip_excluded'   => 0,
        'skip_other_sch' => 0,
        'skip_superadm'  => 0,
        'skip_no_school' => 0,
        'skip_empty_role'=> 0,
        'write_failures' => 0,
    ];

    /** Sampled evidence for the report (bounded). */
    private $samples = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('This backfill is CLI-only.', 403);
            exit(1);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    public function run($school = 'all', $mode = 'dry-run', ...$excludeArgs)
    {
        // CodeIgniter's permitted_uri_chars rejects commas in CLI args, so the
        // exclude list is passed as SPACE-SEPARATED UIDs (variadic), e.g.:
        //   run all commit STA0011 STU0012 SSA0011 STU0047
        $keywords = ['all', 'commit', 'dry-run', 'source-doc', ''];
        $allArgs  = array_merge([$school, $mode], $excludeArgs);

        // `commit` / `source-doc` may appear in any slot.
        foreach ($allArgs as $arg) {
            if ($arg === 'commit')     $this->commit = true;
            if ($arg === 'source-doc') $this->useSourceDoc = true;
        }
        // The school filter: first slot, if it's a real id (not a keyword).
        if (!in_array($school, $keywords, true)) {
            $this->onlySchool = $school;
        }
        // Excludes: the variadic tail (defensively also split on comma in case a
        // shell passes a quoted CSV). Keywords never count as UIDs.
        foreach ($excludeArgs as $arg) {
            foreach (explode(',', (string) $arg) as $id) {
                $id = trim($id);
                if ($id !== '' && !in_array($id, $keywords, true)) $this->exclude[$id] = true;
            }
        }

        $this->_out('');
        $this->_out('== Auth claims backfill ==');
        $this->_out('mode    : ' . ($this->commit ? 'COMMIT (writing)' : 'DRY-RUN (no writes)'));
        $this->_out('school  : ' . $this->onlySchool);
        $this->_out('exclude : ' . ($this->exclude ? implode(', ', array_keys($this->exclude)) : '(none)'));
        $this->_out('srcdoc  : ' . ($this->useSourceDoc ? 'ON (resolve blank roles from Firestore)' : 'off'));

        // Open the rollback log BEFORE any write. Each line: {uid, old, new}.
        if ($this->commit) {
            $this->backupPath = APPPATH . 'logs/claims_backfill_backup_' . date('Ymd_His') . '.jsonl';
            $this->backupFh   = @fopen($this->backupPath, 'a');
            if ($this->backupFh === false) {
                $this->backupFh = null;
                $this->_out('WARN: could not open rollback log at ' . $this->backupPath . ' — proceeding WITHOUT backup.');
            } else {
                $this->_out('backup  : ' . $this->backupPath);
            }
        }
        $this->_out('');

        try {
            $this->firebase->eachAuthUser(function ($user) {
                $this->_process_user($user);
            });
        } catch (\Throwable $e) {
            $this->_out('FATAL: listUsers failed — ' . $e->getMessage());
            log_message('error', 'Auth_claims_backfill: listUsers failed: ' . $e->getMessage());
            if ($this->backupFh) fclose($this->backupFh);
            exit(1);
        }

        if ($this->backupFh) fclose($this->backupFh);
        $this->_report();
    }

    private function _process_user($user): void
    {
        $this->stats['scanned']++;

        $uid    = (string) ($user->uid ?? '');
        $claims = [];
        if (isset($user->customClaims) && is_array($user->customClaims)) {
            $claims = $user->customClaims;
        }

        // 0. Explicit exclude list (control group).
        if (isset($this->exclude[$uid])) {
            $this->stats['skip_excluded']++;
            $this->_sample('excluded', $uid, '(left untouched)');
            return;
        }

        $role = trim((string) ($claims['role'] ?? ''));

        // 1. Never touch cross-tenant super admins.
        if (in_array($role, self::SUPERADMIN_ROLES, true)) {
            $this->stats['skip_superadm']++;
            return;
        }

        // 2. Resolve tenant from whichever casing is present.
        $schoolId = (string) ($claims['school_id'] ?? $claims['schoolId'] ?? '');
        if ($schoolId === '') {
            $this->stats['skip_no_school']++;
            $this->_sample('no_school', $uid, $role);
            return;
        }

        // 3. Optional single-school filter.
        if ($this->onlySchool !== 'all' && $schoolId !== $this->onlySchool) {
            $this->stats['skip_other_sch']++;
            return;
        }

        // 4. Blank role. In claims-only mode we do NOT guess it. With
        //    `source-doc` we resolve the real role from the authoritative
        //    staff/students/admins Firestore doc keyed {schoolId}_{uid}.
        $fromDoc = null;
        if ($role === '') {
            if (!$this->useSourceDoc) {
                $this->stats['skip_empty_role']++;
                $this->_sample('empty_role', $uid, '(blank) school=' . $schoolId);
                return;
            }
            $fromDoc = $this->_resolve_from_source($uid, $schoolId);
            if ($fromDoc === null || ($fromDoc['role'] ?? '') === '') {
                $this->stats['skip_empty_role']++;
                $this->_sample('empty_role', $uid, '(blank, no source doc) school=' . $schoolId);
                return;
            }
            $role = (string) $fromDoc['role'];
        }

        $schoolCode  = (string) ($claims['school_code'] ?? $claims['schoolCode'] ?? '');
        $parentDbKey = (string) ($claims['parent_db_key'] ?? $claims['parentDbKey'] ?? '');
        // Mirror MY_Controller: parent_db_key = login code (school_code) ?: school_id.
        if ($parentDbKey === '') $parentDbKey = ($schoolCode !== '' ? $schoolCode : $schoolId);
        if ($schoolCode === '')  $schoolCode  = $parentDbKey;

        $roleLabel = trim((string) ($claims['roleLabel'] ?? ''));
        if ($roleLabel === '' && $fromDoc) $roleLabel = (string) ($fromDoc['role_label'] ?? '');

        // Student/parent accounts log in AS the student → uid == studentId.
        $studentId  = trim((string) ($claims['student_id'] ?? ''));
        $studentIds = (isset($claims['student_ids']) && is_array($claims['student_ids']))
            ? $claims['student_ids'] : null;
        if ($studentId === '' && $fromDoc && !empty($fromDoc['student_id'])) {
            $studentId = (string) $fromDoc['student_id'];
        }
        if ($studentId === '' && in_array($role, self::STUDENT_ROLES, true)) {
            $studentId = $uid;
        }

        // Preserve any claim we don't canonicalise (must_change_password, etc.).
        $extra = array_diff_key($claims, array_flip(self::KNOWN_KEYS));

        $ctx = [
            'role'          => $role,
            'role_label'    => $roleLabel,
            'school_id'     => $schoolId,
            'school_code'   => $schoolCode,
            'parent_db_key' => $parentDbKey,
            'student_id'    => $studentId,
            'student_ids'   => $studentIds,
            // Staff/admin tokens (no student in scope) carry staffId == uid so the
            // re-mint ADDS the staffId claim rather than dropping it. Students/
            // parents pass '' → builder stays lean for them.
            'staff_id'      => ($studentId === '' ? $uid : ''),
            'extra'         => $extra,
        ];

        $canonical = $this->firebase->buildCanonicalClaims($ctx);

        if ($this->_claims_equal($canonical, $claims)) {
            $this->stats['already_ok']++;
            return;
        }

        // Non-canonical → repair.
        if ($this->commit) {
            // Rollback log FIRST — record the old set before we overwrite it.
            if ($this->backupFh) {
                fwrite($this->backupFh, json_encode([
                    'uid' => $uid,
                    'old' => $claims,
                    'new' => $canonical,
                ]) . "\n");
            }
            $ok = $this->firebase->setFirebaseClaims($uid, $canonical);
            if (!$ok) {
                $this->stats['write_failures']++;
                $this->_sample('write_fail', $uid, $role . ' school=' . $schoolId);
                return;
            }
        }
        if ($fromDoc) {
            $this->stats['repaired_srcdoc']++;
            $this->_sample('repair_srcdoc', $uid, "role='{$role}' (from " . ($fromDoc['source'] ?? '?') . ") school=" . $schoolId);
        } else {
            $this->stats['repaired']++;
            $this->_sample('repair', $uid, $role . ' school=' . $schoolId . ' ' . $this->_diff_hint($claims, $canonical));
        }
    }

    /**
     * Resolve a blank-role account's real role from its authoritative
     * Firestore doc ({schoolId}_{uid}). Returns
     * ['role'=>, 'role_label'=>, 'student_id'=>, 'source'=>] or null.
     * Collection order is guided by the id prefix (STA/STU/ADM/SSA).
     */
    private function _resolve_from_source(string $uid, string $schoolId): ?array
    {
        $prefix = strtoupper(substr($uid, 0, 3));
        switch ($prefix) {
            case 'STU': $candidates = [['students', 'student']]; break;
            case 'ADM': $candidates = [['admins', 'admin'], ['staff', 'staff']]; break;
            case 'SSA': $candidates = [['staff', 'staff'], ['admins', 'admin']]; break;
            case 'STA': $candidates = [['staff', 'staff']]; break;
            default:    $candidates = [['staff', 'staff'], ['students', 'student'], ['admins', 'admin']];
        }

        foreach ($candidates as [$col, $type]) {
            $doc = $this->fs->get($col, $schoolId . '_' . $uid);
            if (!is_array($doc) || empty($doc)) continue;

            if ($type === 'student') {
                return ['role' => 'student', 'role_label' => 'Student', 'student_id' => $uid, 'source' => $col];
            }
            if ($type === 'admin') {
                $r = trim((string) ($doc['Role'] ?? $doc['role'] ?? ''));
                if ($r === '') $r = 'Admin';
                return ['role' => $r, 'role_label' => $r, 'student_id' => '', 'source' => $col];
            }
            // staff: prefer the human Position label; else humanise primary_role.
            $pos = trim((string) ($doc['Position'] ?? $doc['position'] ?? ''));
            $pr  = trim((string) ($doc['primary_role'] ?? ''));
            if ($pos !== '') {
                $role = $pos;
            } elseif ($pr !== '') {
                $role = ucfirst(strtolower(str_replace(['ROLE_', '_'], ['', ' '], $pr)));
            } else {
                // Neutral default — never assume Teacher for a role-less staffer.
                $role = 'Staff';
            }
            return ['role' => $role, 'role_label' => $role, 'student_id' => '', 'source' => $col];
        }
        return null;
    }

    /** Order-insensitive deep comparison of two claim maps. */
    private function _claims_equal(array $a, array $b): bool
    {
        return $this->_norm($a) === $this->_norm($b);
    }

    private function _norm($v)
    {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            $out = [];
            foreach ($v as $k => $vv) $out[$k] = $this->_norm($vv);
            if ($isList) { sort($out); } else { ksort($out); }
            return $out;
        }
        return $v;
    }

    /** Human-readable hint of what the repair adds/changes. */
    private function _diff_hint(array $old, array $new): string
    {
        $added = [];
        foreach (['schoolId', 'school_id', 'schoolCode', 'school_code', 'parentDbKey', 'parent_db_key', 'roleLabel', 'student_id', 'student_ids'] as $k) {
            $ov = $old[$k] ?? null;
            if (($ov === null || $ov === '' || $ov === []) && !empty($new[$k])) $added[] = '+' . $k;
        }
        if (($old['role'] ?? '') !== ($new['role'] ?? '')) $added[] = 'role*';
        return $added ? '[' . implode(',', $added) . ']' : '';
    }

    private function _sample(string $bucket, string $uid, string $note): void
    {
        if (!isset($this->samples[$bucket])) $this->samples[$bucket] = [];
        if (count($this->samples[$bucket]) < 15) {
            $this->samples[$bucket][] = $uid . '  ' . $note;
        }
    }

    private function _report(): void
    {
        $this->_out('');
        $this->_out('-- summary --');
        foreach ($this->stats as $k => $v) {
            $this->_out(sprintf('  %-16s %d', $k, $v));
        }
        foreach ($this->samples as $bucket => $rows) {
            $this->_out('');
            $this->_out("-- sample: {$bucket} (max 15) --");
            foreach ($rows as $r) $this->_out('  ' . $r);
        }
        $this->_out('');
        $totalRepairs = $this->stats['repaired'] + $this->stats['repaired_srcdoc'];
        if (!$this->commit) {
            $this->_out('DRY-RUN complete. Re-run with `commit` to apply the ' . $totalRepairs . ' repair(s).');
        } else {
            $this->_out('COMMIT complete. ' . $totalRepairs . ' account(s) repaired.');
            $this->_out('NOTE: repaired tokens take effect on the user\'s next ID-token refresh');
            $this->_out('      (app cold-start / re-login force-refreshes; otherwise up to ~1h).');
        }
        if ($this->stats['skip_no_school'] > 0 || $this->stats['skip_empty_role'] > 0) {
            $this->_out('');
            $this->_out('ACTION NEEDED: no_school / empty_role accounts cannot be repaired from claims.');
            $this->_out('  Re-save each in the admin panel (create/edit/reset now mints canonically),');
            $this->_out('  or extend this script to source their role from the staff/students doc.');
        }
        $this->_out('');
    }

    private function _out(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
