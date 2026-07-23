<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admins_fold — retire the Firestore `admins` collection by folding every
 * regular admin (ADM\d+) into a `staff` record carrying ROLE_ADMIN, and
 * migrating its id ADM->STA. School Super Admins (SSA\d+) are LEFT UNTOUCHED
 * (their authority is RTDB + Firebase Auth; the admins/{SSA} doc is a
 * best-effort mirror).
 *
 * This controller houses the whole fold as ordered subcommands. STEP 1 here is
 * the read-only INVENTORY — it must run and be reviewed before any migration.
 *
 *   php index.php admins_fold inventory                 # read-only, all tenants
 *   php index.php admins_fold inventory SCH_ABC123      # read-only, one tenant
 *   (migrate/restore added in a later step — NOT YET.)
 *
 * SAFETY: inventory writes NOTHING. It only reads `admins` + `staff` and reports.
 */
class Admins_fold extends CI_Controller
{
    // Docs in `admins` use school-scoped composite ids: {schoolId}_{ENTITY}.
    // The logical entity id is the trailing token; classify on its prefix.
    //   {schoolId}_ADM0003 -> fold      {schoolId}_SSA0011 -> leave
    // Firebase Auth uid == the BARE entity id (ADM0003), not the composite.
    private const ENTITY_RE = '/(ADM\d+|SSA\d+|STA\d+)$/';

    private $onlySchool = 'all';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); exit(1); }
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->helper('rbac');
        $this->load->helper('deptrole'); // derive_role_id() for label→ROLE_* preview
    }

    /**
     * Read-only census of the admins collection. For every doc:
     *   - classify by id prefix: ADM (fold) / SSA (leave) / OTHER (flag)
     *   - for ADM: does staff/{id} already exist? does it carry an admin-tier
     *     role? does the Firebase Auth user exist? is the ADM id well-formed?
     * Nothing is written.
     */
    public function inventory($school = 'all')
    {
        if ($school !== '' && $school !== 'all') $this->onlySchool = $school;

        $this->_out('');
        $this->_out('== admins-collection inventory (READ-ONLY) ==');
        $this->_out('school : ' . $this->onlySchool);
        $this->_out('');

        try { $rows = $this->fs->where('admins', [], null, 'ASC', 5000); }
        catch (\Throwable $e) { $this->_out('list admins failed: ' . $e->getMessage()); return; }

        $stats = [
            'admins_docs' => 0, 'adm_fold' => 0, 'ssa_leave' => 0, 'sta_anomaly' => 0, 'other_flag' => 0,
            'adm_with_staff' => 0, 'adm_missing_staff' => 0,
            'adm_with_admin_role' => 0, 'adm_auth_present' => 0, 'adm_auth_missing' => 0,
        ];
        $flags = [];      // anomalies worth eyeballing before migrating
        $byTenant = [];   // schoolId => ['adm'=>N,'ssa'=>N]

        foreach ($rows as $r) {
            $rawId = (string) ($r['id'] ?? '');
            $d     = is_array($r['data'] ?? null) ? $r['data'] : [];
            if ($rawId === '') continue;

            // Logical entity id = trailing (ADM|SSA|STA)\d+ token of the composite id.
            $entity = '';
            if (preg_match(self::ENTITY_RE, $rawId, $m)) $entity = $m[1];
            // schoolId: prefer the field; else strip "_{ENTITY}" off the composite id.
            $sid = (string) ($d['schoolId'] ?? '');
            if ($sid === '' && $entity !== '') $sid = preg_replace('/_' . preg_quote($entity, '/') . '$/', '', $rawId);

            if ($this->onlySchool !== 'all' && $sid !== $this->onlySchool) continue;
            $stats['admins_docs']++;

            // ---- classify by entity prefix ----
            if (strpos($entity, 'SSA') === 0) {
                $stats['ssa_leave']++;
                $byTenant[$sid]['ssa'] = ($byTenant[$sid]['ssa'] ?? 0) + 1;
                continue;
            }
            if (strpos($entity, 'STA') === 0) {
                // A staff id sitting in the admins collection — unexpected. Fold
                // should reconcile (it's already a staff record) not re-key.
                $stats['sta_anomaly']++;
                $flags[] = "STA_IN_ADMINS {$rawId} (school={$sid}) — staff id in admins collection; fold reconciles, no re-key";
                continue;
            }
            if (strpos($entity, 'ADM') !== 0) {
                $stats['other_flag']++;
                $flags[] = "OTHER  {$rawId} (school={$sid}) — non-ADM/SSA/STA id in admins; inspect (likely test/temp doc)";
                continue;
            }

            // ---- ADM fold candidate ----
            $stats['adm_fold']++;
            $byTenant[$sid]['adm'] = ($byTenant[$sid]['adm'] ?? 0) + 1;

            if ($sid === '') {
                $flags[] = "NO_SCHOOL {$rawId} — ADM doc has no derivable schoolId; cannot scope migration";
            }

            // Staff mirror present? saveStaff scopes to {schoolId}_ADM#### == this raw id.
            $staff = null;
            try { $staff = $this->fs->get('staff', $rawId); } catch (\Throwable $e) { $staff = null; }
            if (is_array($staff) && !empty($staff)) {
                $stats['adm_with_staff']++;
                $roles = $this->_staff_roles($staff);
                if (in_array('ROLE_ADMIN', $roles, true)
                    || strcasecmp((string) ($staff['Role'] ?? ''), 'Admin') === 0) {
                    $stats['adm_with_admin_role']++;
                } else {
                    $flags[] = "STAFF_NO_ADMINROLE {$entity} (school={$sid}) — staff doc exists but lacks ROLE_ADMIN; fold must add it";
                }
            } else {
                $stats['adm_missing_staff']++;
                $flags[] = "MISSING_STAFF {$entity} (school={$sid}) — no staff/{$rawId}; fold must create it";
            }

            // Auth user present? uid == BARE entity id. Login continuity depends on it.
            $authOk = false;
            try { $authOk = $this->firebase->getFirebaseUser($entity) !== null; }
            catch (\Throwable $e) { $authOk = false; }
            if ($authOk) { $stats['adm_auth_present']++; }
            else { $stats['adm_auth_missing']++;
                   $flags[] = "AUTH_MISSING {$entity} (school={$sid}) — no Firebase Auth user; migration cannot re-key login"; }
        }

        // ---- report ----
        $this->_out('── per-tenant ──');
        if (empty($byTenant)) { $this->_out('  (none)'); }
        foreach ($byTenant as $sid => $c) {
            $this->_out(sprintf('  %-16s ADM=%-3d SSA=%-3d', $sid === '' ? '(no-school)' : $sid,
                $c['adm'] ?? 0, $c['ssa'] ?? 0));
        }
        $this->_out('');
        $this->_out('── flags (' . count($flags) . ') ──');
        foreach (($flags ?: ['  (none — every ADM has a staff mirror + Auth user)']) as $f) $this->_out('  ' . $f);
        $this->_out('');
        $this->_out('── summary ──');
        foreach ($stats as $k => $v) $this->_out(sprintf('  %-22s %d', $k, $v));
        $this->_out('');
        $this->_out('Read-only. Next: `admin_role_backfill run all commit` (step 2), then reader/onboarding repoint, then `admins_fold migrate` (built later).');
        $this->_out('');
    }

    /**
     * Read-only dump of one entity's admins doc + staff mirror + role fields +
     * Auth presence. Pass the full composite id, e.g.
     *   php index.php admins_fold show SCH_B56BB9A401_ADM0001
     */
    public function show($rawId = '')
    {
        if ($rawId === '') { $this->_out('Pass the composite id, e.g. SCH_B56BB9A401_ADM0001'); return; }
        $entity = preg_match(self::ENTITY_RE, $rawId, $m) ? $m[1] : '';

        $this->_out("\n== show {$rawId} (entity={$entity}) ==\n");

        $adm = $this->fs->get('admins', $rawId);
        $this->_out('admins doc:');
        $this->_out($adm ? $this->_pick($adm, ['adminId','schoolId','Name','Role','Status','Email','mustChangePassword']) : '  (none)');

        $staff = $this->fs->get('staff', $rawId);
        $this->_out("\nstaff doc:");
        if ($staff) {
            $this->_out($this->_pick($staff, ['staffId','schoolId','Name','name','Role','Status','staff_roles','staffRoles','roles','role_ids','roleTier','permissionLevels']));
        } else { $this->_out('  (none)'); }

        $auth = null;
        try { $auth = $this->firebase->getFirebaseUser($entity); } catch (\Throwable $e) {}
        $this->_out("\nAuth user (uid={$entity}): " . ($auth ? 'PRESENT' : 'absent'));
        $this->_out('');
    }

    /** Compact key=>value dump of the requested keys only. */
    private function _pick(array $doc, array $keys): string
    {
        $out = [];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $doc)) continue;
            $v = $doc[$k];
            $out[] = sprintf('  %-18s %s', $k, is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v));
        }
        return $out ? implode("\n", $out) : '  (none of the probed keys present)';
    }

    /**
     * ADM->STA fold migration. DRY-RUN by default (plans, no writes). `commit`
     * executes. Per ADM the strategy is CREATE-NEW-THEN-RETIRE-OLD, and the old
     * Auth user is DISABLED (not deleted) so every step is reversible:
     *   1. claim a fresh STA id; create Auth uid=STA (temp pw + mustChangePassword)
     *   2. re-mint canonical claims for STA (role_fallback='Admin', staffId=STA)
     *   3. write staff/{sid}_STA (copy of old staff doc; staff_roles=[nominal,ADMIN])
     *   4. rollback record written
     *   5. retire old: revoke+DISABLE Auth {ADM}; delete admins/{ADM}, staff/{ADM},
     *      staffCapabilities/{ADM}. (RTDB credential node left inert — write-only.)
     * STA-in-admins stubs and junk docs are just deleted (staff is authoritative).
     * Historical FKs (created_by/…) are LEFT AS-IS (cosmetic; see dry-run report).
     *
     *   php index.php admins_fold migrate                     # plan all
     *   php index.php admins_fold migrate SCH_B56BB9A401      # plan one tenant
     *   php index.php admins_fold migrate all commit          # EXECUTE
     *   php index.php admins_fold restore <rollback.jsonl>    # revert
     *
     * PREREQ for commit: the fold panel code (steps 3–4) MUST be DEPLOYED first,
     * else the live admin UI (still reading `admins`) goes blank once ADM docs are
     * deleted. Claims re-mint keeps panel login working via the Admin bypass.
     */
    public function migrate($school = 'all', $mode = 'dry-run')
    {
        $commit = ($mode === 'commit' || $school === 'commit');
        if ($school === 'commit') $school = 'all';
        if ($school !== '' && $school !== 'all') $this->onlySchool = $school;

        $this->_out('');
        $this->_out('== ADM->STA fold — ' . ($commit ? 'MIGRATION (COMMIT, writing)' : 'PLAN (DRY-RUN, no writes)') . ' ==');
        $this->_out('school : ' . $this->onlySchool);
        $this->_out('');

        try { $rows = $this->fs->where('admins', [], null, 'ASC', 5000); }
        catch (\Throwable $e) { $this->_out('list admins failed: ' . $e->getMessage()); return; }

        // Curated FK targets to REPORT (not rewrite). collection => [id-fields].
        $fkTargets = [
            'homework'        => ['created_by', 'createdBy', 'teacherId'],
            'notices'         => ['created_by', 'createdBy'],
            'galleryMedia'    => ['uploadedBy', 'createdBy'],
            'staffAttendance' => ['markedBy', 'staffId'],
            'auditLogs'       => ['actorId', 'adminId'],
        ];

        $this->load->library('id_generator');
        $peek = '';
        if (!$commit) { try { $peek = (string) $this->id_generator->generate('STA_PEEK'); } catch (\Throwable $e) {} }

        // Rollback log (commit only).
        $rollback = null;
        if ($commit) {
            $path = FCPATH . 'admins_fold_migrate_rollback_' . date('Ymd_His') . '.jsonl';
            $rollback = @fopen($path, 'w');
            $this->_out('rollback log: ' . $path . "\n");
        }

        $plans = 0; $stubs = 0; $junk = 0; $ssa = 0; $done = 0; $failed = 0;

        foreach ($rows as $r) {
            $rawId = (string) ($r['id'] ?? '');
            $d     = is_array($r['data'] ?? null) ? $r['data'] : [];
            if ($rawId === '') continue;
            $entity = preg_match(self::ENTITY_RE, $rawId, $m) ? $m[1] : '';
            $sid = (string) ($d['schoolId'] ?? '');
            if ($sid === '' && $entity !== '') $sid = preg_replace('/_' . preg_quote($entity, '/') . '$/', '', $rawId);
            if ($this->onlySchool !== 'all' && $sid !== $this->onlySchool) continue;

            if (strpos($entity, 'SSA') === 0) { $ssa++; continue; }

            if (strpos($entity, 'STA') === 0) {
                $stubs++;
                $this->_out("STUB  {$rawId} — staff doc authoritative; delete stray admins doc only.");
                if ($commit) {
                    if ($rollback) fwrite($rollback, json_encode(['kind' => 'stub', 'rawId' => $rawId, 'admins' => $d]) . "\n");
                    $ok = $this->fs->remove('admins', $rawId);
                    $this->_out('      ' . ($ok ? '[deleted]' : '[FAILED delete]'));
                }
                continue;
            }
            if (strpos($entity, 'ADM') !== 0) {
                $junk++;
                $this->_out("JUNK  {$rawId} (school={$sid}) — delete test/temp doc.");
                if ($commit) {
                    if ($rollback) fwrite($rollback, json_encode(['kind' => 'junk', 'rawId' => $rawId, 'admins' => $d]) . "\n");
                    $ok = $this->fs->remove('admins', $rawId);
                    $this->_out('      ' . ($ok ? '[deleted]' : '[FAILED delete]'));
                }
                continue;
            }

            // ---- ADM ----
            $plans++;
            $name  = (string) ($d['Name'] ?? $d['Profile']['name'] ?? '');
            $label = (string) ($d['Role'] ?? '');
            $rid   = $this->_resolve_role_id($sid, $label);
            $roles = $rid !== null ? [$rid] : []; // FOLD: nominal role only, NO escalation

            if (!$commit) {
                $newId = $peek !== '' ? $peek : 'STA????';
                $this->_out("ADM   {$rawId}  \"{$name}\"  role-label=" . var_export($label, true));
                $this->_out("  new id      {$newId}  (peek — each account claims a fresh STA at commit)");
                $this->_out("  Auth        create uid={$newId} (temp pw + mustChangePassword); revoke+DISABLE old {$entity} (not deleted)");
                $this->_out("  claims      re-mint {role,school_id,school_code,parent_db_key,staffId={$newId}} for {$newId}");
                $this->_out("  staff doc   copy staff/{$rawId} → staff/{$sid}_{$newId}; staff_roles=[" . implode(',', $roles) . ']'
                    . ($rid === null ? "  ⚠ label '{$label}' unmatched in catalogue → EMPTY roles (no module access) — fix role first" : ''));
                $this->_out("  delete      admins/{$rawId}, staff/{$rawId}, staffCapabilities/{$entity}");
                $fkLines = [];
                foreach ($fkTargets as $col => $fields) {
                    foreach ($fields as $f) {
                        try { $n = count($this->fs->where($col, [[$f, '==', $entity]], null, 'ASC', 500)); } catch (\Throwable $e) { $n = 0; }
                        if ($n > 0) $fkLines[] = "{$col}.{$f}={$n}";
                    }
                }
                $this->_out("  historical refs (LEFT AS-IS): " . ($fkLines ? implode('  ', $fkLines) : 'none in probed set'));
                $this->_out('');
                continue;
            }

            // ---- COMMIT: execute the re-key ----
            [$ok, $msg] = $this->_exec_adm($sid, $entity, $rawId, $d, $label, $roles, $rollback);
            $this->_out(($ok ? "DONE  " : "FAIL  ") . "{$rawId} \"{$name}\" → {$msg}");
            $ok ? $done++ : $failed++;
        }
        if ($rollback) fclose($rollback);

        $this->_out('');
        $this->_out('── summary ──');
        $this->_out(sprintf('  ADM %-16s %d', $commit ? 're-keyed' : 'plans', $commit ? $done : $plans));
        if ($commit) $this->_out(sprintf('  ADM failed        %d', $failed));
        $this->_out(sprintf('  STA stubs         %d', $stubs));
        $this->_out(sprintf('  junk              %d', $junk));
        $this->_out(sprintf('  SSA (skipped)     %d', $ssa));
        $this->_out('');
        $this->_out($commit
            ? 'Committed. Old ADM logins are DISABLED (recoverable). `restore <log>` reverts.'
            : "Dry-run only. Ensure steps 3–4 panel code is DEPLOYED, then:\n  php index.php admins_fold migrate " . $this->onlySchool . " commit");
        $this->_out('');
    }

    /**
     * Execute one ADM->STA re-key. Returns [bool ok, string msg]. Create-new
     * first; only after the new identity is fully written do we retire the old
     * (disable Auth, delete docs). Every pre-retire step that fails aborts before
     * any destructive op, leaving the old identity fully intact.
     */
    private function _exec_adm(string $sid, string $entity, string $rawId, array $adminDoc, string $label, array $roles, $rollback): array
    {
        // 1. claim a fresh STA id
        $newId = $this->id_generator->generate('STA');
        if ($newId === null) return [false, 'STA id allocation failed (nothing changed)'];
        $newSeq = (int) preg_replace('/^[A-Z]+/', '', $newId);

        // old claims → reuse school_code + parent_db_key (identical school context)
        $oldClaims = [];
        try { $u = $this->firebase->getFirebaseUser($entity); if ($u) $oldClaims = (array) ($u->customClaims ?? []); } catch (\Throwable $e) {}
        $oldStaff = null;
        try { $oldStaff = $this->fs->get('staff', $rawId); } catch (\Throwable $e) {}

        // 2. create new Auth user (temp pw; user resets on first login)
        $tmp = 'Aa1' . bin2hex(random_bytes(9));
        try {
            $created = $this->firebase->createFirebaseUser(Firebase::authEmail($newId), $tmp, ['uid' => $newId, 'displayName' => (string) ($adminDoc['Name'] ?? $newId)]);
        } catch (\Throwable $e) { $created = null; }
        if (!$created) { $this->id_generator->releaseClaim('STA', $newSeq); return [false, "Auth create for {$newId} failed (nothing changed)"]; }

        // 3. canonical claims for the new id
        $this->firebase->setCanonicalClaims($newId, [
            'role'          => $label,
            'role_fallback' => 'Admin',
            'school_id'     => $sid,
            'school_code'   => (string) ($oldClaims['school_code'] ?? $oldClaims['schoolCode'] ?? ''),
            'parent_db_key' => (string) ($oldClaims['parent_db_key'] ?? ''),
            'extra'         => ['must_change_password' => true],
        ]);

        // 4. write the new staff doc (copy old staff, else derive from admins doc)
        $base = is_array($oldStaff) && $oldStaff ? $oldStaff : [
            'Name'   => (string) ($adminDoc['Name'] ?? ''),
            'name'   => (string) ($adminDoc['Name'] ?? ''),
            'Email'  => (string) ($adminDoc['Email'] ?? $adminDoc['Profile']['email'] ?? ''),
            'Role'   => $label,
            'Status' => 'Active',
        ];
        $newStaff = array_merge($base, [
            'schoolId'           => $sid,
            'staffId'            => $newId,
            'staff_roles'        => $roles,
            'mustChangePassword' => true,
            'migratedFrom'       => $entity,
            'updatedAt'          => date('c'),
        ]);
        // staff doc id == {schoolId}_{staffId} (same scheme docId() builds).
        $newStaffDocId = "{$sid}_{$newId}";
        $wrote = $this->fs->set('staff', $newStaffDocId, $newStaff, true);
        if ($wrote === false) {
            // roll back the just-created Auth user + id; old identity untouched.
            try { $this->firebase->deleteFirebaseUser($newId); } catch (\Throwable $e) {}
            $this->id_generator->releaseClaim('STA', $newSeq);
            return [false, "staff/{$sid}_{$newId} write failed (old identity intact)"];
        }

        // 5. rollback record BEFORE retiring the old identity
        if ($rollback) {
            fwrite($rollback, json_encode([
                'kind' => 'adm', 'oldId' => $entity, 'newId' => $newId, 'sid' => $sid, 'newSeq' => $newSeq,
                'admins' => $adminDoc, 'staff' => $oldStaff,
            ]) . "\n");
        }

        // 6. retire the old identity — DISABLE (not delete) Auth so it is recoverable
        try { $this->firebase->revokeRefreshTokens($entity); } catch (\Throwable $e) {}
        try { $this->firebase->updateFirebaseUser($entity, ['disabled' => true]); } catch (\Throwable $e) {}
        try { $this->fs->remove('admins', $rawId); } catch (\Throwable $e) {}
        try { $this->fs->remove('staff', $rawId); } catch (\Throwable $e) {}
        try { $this->fs->remove('staffCapabilities', $entity); } catch (\Throwable $e) {}

        return [true, "{$newId} created (staff_roles=[" . implode(',', $roles) . "]); old {$entity} disabled+removed"];
    }

    /**
     * Revert a migration from its rollback .jsonl. Re-enables each old Auth user,
     * restores the old admins/staff docs, and removes the new STA identity.
     *   php index.php admins_fold restore admins_fold_migrate_rollback_XXXX.jsonl
     */
    public function restore($file = '')
    {
        if ($file === '') { $this->_out('Pass the rollback .jsonl filename.'); return; }
        $path = (strpos($file, '/') === 0) ? $file : FCPATH . $file;
        if (!is_file($path)) { $this->_out('Not found: ' . $path); return; }
        $this->load->library('id_generator');

        $fh = fopen($path, 'r'); $n = 0;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $rec = json_decode($line, true); if (!is_array($rec)) continue;
            $kind = (string) ($rec['kind'] ?? '');

            if ($kind === 'stub' || $kind === 'junk') {
                $rawId = (string) ($rec['rawId'] ?? '');
                if ($rawId !== '' && is_array($rec['admins'] ?? null)) {
                    $this->fs->set('admins', $rawId, $rec['admins'], true);
                    $this->_out("  [restored {$kind}] admins/{$rawId}"); $n++;
                }
                continue;
            }
            if ($kind !== 'adm') continue;

            $oldId = (string) ($rec['oldId'] ?? '');
            $newId = (string) ($rec['newId'] ?? '');
            $sid   = (string) ($rec['sid'] ?? '');
            if ($oldId === '' || $newId === '' || $sid === '') continue;

            // re-enable + restore the old identity
            try { $this->firebase->updateFirebaseUser($oldId, ['disabled' => false]); } catch (\Throwable $e) {}
            if (is_array($rec['admins'] ?? null)) $this->fs->set('admins', "{$sid}_{$oldId}", $rec['admins'], true);
            if (is_array($rec['staff'] ?? null))  $this->fs->set('staff', "{$sid}_{$oldId}", $rec['staff'], true);

            // remove the new identity
            try { $this->firebase->deleteFirebaseUser($newId); } catch (\Throwable $e) {}
            try { $this->fs->remove('staff', "{$sid}_{$newId}"); } catch (\Throwable $e) {}
            try { $this->fs->remove('staffCapabilities', $newId); } catch (\Throwable $e) {}
            if (!empty($rec['newSeq'])) $this->id_generator->releaseClaim('STA', (int) $rec['newSeq']);

            $this->_out("  [restored adm] {$oldId} re-enabled; {$newId} removed"); $n++;
        }
        fclose($fh);
        $this->_out("Done. {$n} record(s) reverted.");
    }

    /** Resolve a role LABEL to its ROLE_* id in a tenant catalogue, or null. */
    private function _resolve_role_id(string $schoolId, string $label): ?string
    {
        if ($schoolId === '' || $label === '') return null;
        try { $school = $this->fs->get('schools', $schoolId); } catch (\Throwable $e) { return null; }
        $roles = is_array($school['staffRoles'] ?? null) ? $school['staffRoles'] : [];
        foreach ($roles as $rid => $rentry) {
            if (!is_array($rentry)) continue;
            if ((string) ($rentry['label'] ?? '') === $label || (string) $rid === $label) return (string) $rid;
        }
        return null;
    }

    /**
     * Read-only harness for the admin_roster_helper (the shared reader the panel
     * now uses). Proves the union(staff ROLE_ADMIN, legacy admins) resolves the
     * expected admins for a tenant BEFORE any migration runs.
     *   php index.php admins_fold roster SCH_B56BB9A401
     */
    public function roster($schoolId = '')
    {
        if ($schoolId === '') { $this->_out('Pass a schoolId, e.g. SCH_B56BB9A401'); return; }
        $this->load->helper('admin_roster');
        $rows = admin_roster_rows($this->fs, $schoolId);
        $this->_out("\n== admin roster for {$schoolId} (" . count($rows) . ") ==");
        foreach ($rows as $r) {
            $this->_out(sprintf('  %-10s %-22s %-24s %-22s [%s] %s',
                $r['id'], $r['name'], $r['email'], $r['role'], $r['status'], $r['source']));
        }
        $this->_out('');
    }

    /** Union of staff role-id fields across the schema variants. */
    private function _staff_roles(array $staff): array
    {
        foreach (['staff_roles', 'staffRoles', 'roles', 'role_ids'] as $k) {
            if (is_array($staff[$k] ?? null)) return array_values(array_filter(array_map('strval', $staff[$k])));
        }
        return [];
    }

    private function _out(string $line): void { fwrite(STDOUT, $line . "\n"); }
}
