<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_migration — Firebase Structure Migration (Phase 1)
 *
 * NON-DESTRUCTIVE controller. Only reads existing Firebase data and writes
 * a migration map to System/Migration/. Does NOT modify any existing nodes.
 *
 * Current structure problems:
 *   - Schools keyed by human name (spaces, special chars) instead of school_id
 *   - Profile data duplicated across Users/Schools, System/Schools, Schools/Config/Profile
 *   - Users/Schools stores school operational data (should only hold user auth data)
 *   - Two onboarding paths (Schools.php legacy vs SA onboarding) create incompatible structures
 *   - School_ids uses mixed value formats (school_name vs SCH_XXXXXX)
 *
 * Target structure:
 *   System/Schools/{school_id}/      — single source of truth for profile, subscription, settings
 *   Schools/{school_id}/{session}/   — academic/operational data (keyed by school_id, not name)
 *   Indexes/School_codes/{code}      — login code → school_id
 *   Indexes/School_names/{nameKey}   — name uniqueness → school_id
 *   Users/Admin/{school_code}/{id}   — admin auth (unchanged)
 *   Users/Parents/{school_id}/{id}   — student profiles (unchanged)
 *
 * Methods:
 *   index()              — Dashboard view with migration report
 *   analyze()            — POST: scan all Firebase sources, build migration map, write to System/Migration/
 *   get_report()         — POST: return the current migration map as JSON
 *   clear_map()          — POST: delete System/Migration/ (reset for re-analysis)
 */
class Superadmin_migration extends MY_Superadmin_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/migration
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $this->load->view('superadmin/include/sa_header');
        $this->load->view('superadmin/migration/index');
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/migration/analyze
    //
    // Scans all Firebase data sources to discover every school, determines
    // their current state (legacy vs migrated), and writes a comprehensive
    // migration map to System/Migration/.
    //
    // NON-DESTRUCTIVE: Only reads existing data. Only writes to System/Migration/.
    // ─────────────────────────────────────────────────────────────────────────
    public function analyze()
    {
        if (!in_array($this->sa_role, ['developer', 'superadmin'], true)) {
            $this->json_error('Insufficient privileges.', 403);
            return;
        }

        $startTime = microtime(true);

        try {
            // ── Step 1: Read all index and registry sources ──────────────
            $usersSchoolsKeys  = $this->_safe_shallow('Users/Schools');
            $schoolsKeys       = $this->_safe_shallow('Schools');
            $systemSchoolsKeys = $this->_safe_shallow('System/Schools');
            $schoolIdsRaw      = $this->firebase->get('School_ids') ?? [];
            $schoolNamesRaw    = $this->firebase->get('School_names') ?? [];

            if (!is_array($schoolIdsRaw))   $schoolIdsRaw   = [];
            if (!is_array($schoolNamesRaw)) $schoolNamesRaw = [];

            // ── Step 2: Build reverse-lookup maps ────────────────────────
            // School_ids: {code} → value (SCH_XXXXXX or school_name)
            $codeToValue = [];
            $schoolIdsCount = null;
            foreach ($schoolIdsRaw as $code => $val) {
                if ($code === 'Count') {
                    $schoolIdsCount = $val;
                    continue;
                }
                if (is_string($val) && trim($val) !== '') {
                    $codeToValue[(string)$code] = trim($val);
                }
            }

            // School_names: {nameKey} → school_id
            $nameKeyToId = [];
            foreach ($schoolNamesRaw as $nameKey => $val) {
                if (is_string($val) && trim($val) !== '') {
                    $nameKeyToId[(string)$nameKey] = trim($val);
                }
            }

            // ── Step 3: Discover all unique school identifiers ───────────
            $allKeys = [];

            // From Users/Schools
            foreach ($usersSchoolsKeys as $key) {
                $allKeys[$key] = ['sources' => ['Users/Schools']];
            }

            // From Schools (academic tree)
            foreach ($schoolsKeys as $key) {
                if (!isset($allKeys[$key])) $allKeys[$key] = ['sources' => []];
                $allKeys[$key]['sources'][] = 'Schools';
            }

            // From System/Schools
            foreach ($systemSchoolsKeys as $key) {
                if (!isset($allKeys[$key])) $allKeys[$key] = ['sources' => []];
                $allKeys[$key]['sources'][] = 'System/Schools';
            }

            // From School_ids values (may reference schools by name)
            foreach ($codeToValue as $code => $val) {
                if (!isset($allKeys[$val])) $allKeys[$val] = ['sources' => []];
                $allKeys[$val]['sources'][] = 'School_ids/' . $code;
            }

            // ── Step 4: Analyze each school ──────────────────────────────
            $schoolMap    = [];
            $legacyCount  = 0;
            $migratedCount = 0;
            $errorCount   = 0;
            $warnings     = [];

            foreach ($allKeys as $firebaseKey => $meta) {
                try {
                    $entry = $this->_analyze_school($firebaseKey, $meta['sources'], $codeToValue, $nameKeyToId);
                    $schoolMap[$entry['school_id']] = $entry;

                    if ($entry['status'] === 'legacy') $legacyCount++;
                    elseif ($entry['status'] === 'migrated_ready') $migratedCount++;

                    if (!empty($entry['warnings'])) {
                        foreach ($entry['warnings'] as $w) {
                            $warnings[] = ['school' => $firebaseKey, 'warning' => $w];
                        }
                    }
                } catch (Exception $e) {
                    $errorCount++;
                    $warnings[] = [
                        'school'  => $firebaseKey,
                        'warning' => 'Analysis failed: ' . $e->getMessage(),
                    ];
                }
            }

            // ── Step 5: Detect orphaned indexes ──────────────────────────
            $orphanedCodes = [];
            foreach ($codeToValue as $code => $val) {
                $found = false;
                foreach ($schoolMap as $entry) {
                    if ($entry['school_code'] === $code) { $found = true; break; }
                    if (in_array($code, $entry['all_codes'] ?? [], true)) { $found = true; break; }
                }
                if (!$found) {
                    $orphanedCodes[] = ['code' => $code, 'points_to' => $val];
                }
            }

            $orphanedNames = [];
            foreach ($nameKeyToId as $nameKey => $id) {
                if (!isset($schoolMap[$id])) {
                    $orphanedNames[] = ['name_key' => $nameKey, 'points_to' => $id];
                }
            }

            // ── Step 6: Detect parent data references ────────────────────
            $parentsKeys = $this->_safe_shallow('Users/Parents');

            $parentSchoolIds = [];
            foreach ($parentsKeys as $key) {
                $parentSchoolIds[$key] = [
                    'has_matching_school' => false,
                    'mapped_school_id'   => null,
                ];
            }

            foreach ($schoolMap as $sid => $entry) {
                // Check if Parents node uses the school_id
                if (isset($parentSchoolIds[$sid])) {
                    $parentSchoolIds[$sid]['has_matching_school'] = true;
                    $parentSchoolIds[$sid]['mapped_school_id']    = $sid;
                }
                // Check if Parents node uses a school_code
                if (!empty($entry['school_code']) && isset($parentSchoolIds[$entry['school_code']])) {
                    $parentSchoolIds[$entry['school_code']]['has_matching_school'] = true;
                    $parentSchoolIds[$entry['school_code']]['mapped_school_id']    = $sid;
                }
                // Check legacy key
                if (!empty($entry['legacy_key']) && isset($parentSchoolIds[$entry['legacy_key']])) {
                    $parentSchoolIds[$entry['legacy_key']]['has_matching_school'] = true;
                    $parentSchoolIds[$entry['legacy_key']]['mapped_school_id']    = $sid;
                }
            }

            $orphanedParents = [];
            foreach ($parentSchoolIds as $pKey => $pData) {
                if (!$pData['has_matching_school']) {
                    $orphanedParents[] = $pKey;
                }
            }

            // ── Step 7: Build summary ────────────────────────────────────
            $elapsed = round((microtime(true) - $startTime) * 1000);

            $summary = [
                'analyzed_at'         => date('Y-m-d H:i:s'),
                'elapsed_ms'          => $elapsed,
                'total_schools'       => count($schoolMap),
                'legacy_schools'      => $legacyCount,
                'migrated_schools'    => $migratedCount,
                'analysis_errors'     => $errorCount,
                'warnings_count'      => count($warnings),
                'orphaned_codes'      => count($orphanedCodes),
                'orphaned_names'      => count($orphanedNames),
                'orphaned_parents'    => count($orphanedParents),
                'school_ids_count'    => $schoolIdsCount,
                'sources_scanned'     => [
                    'Users/Schools'  => count($usersSchoolsKeys),
                    'Schools'        => count($schoolsKeys),
                    'System/Schools' => count($systemSchoolsKeys),
                    'School_ids'     => count($codeToValue),
                    'School_names'   => count($nameKeyToId),
                    'Users/Parents'  => count($parentsKeys),
                ],
            ];

            // ── Step 8: Write migration map to System/Migration/ ─────────
            $migrationData = [
                'summary'          => $summary,
                'school_map'       => $schoolMap,
                'warnings'         => $warnings,
                'orphaned_codes'   => $orphanedCodes,
                'orphaned_names'   => $orphanedNames,
                'orphaned_parents' => $orphanedParents,
            ];

            $this->firebase->set('System/Migration', $migrationData);

            $this->sa_log('migration_analysis', '', [
                'total_schools' => count($schoolMap),
                'legacy'        => $legacyCount,
                'migrated'      => $migratedCount,
            ]);

            $this->json_success([
                'summary'  => $summary,
                'warnings' => $warnings,
                'message'  => sprintf(
                    'Analysis complete: %d schools found (%d legacy, %d already migrated, %d errors). '
                    . 'Migration map written to System/Migration/.',
                    count($schoolMap), $legacyCount, $migratedCount, $errorCount
                ),
            ]);

        } catch (Exception $e) {
            log_message('error', 'Migration analyze: ' . $e->getMessage());
            $this->json_error('Analysis failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/migration/get_report
    //
    // Returns the current migration map from System/Migration/.
    // ─────────────────────────────────────────────────────────────────────────
    public function get_report()
    {
        try {
            $data = $this->firebase->get('System/Migration');
            if (empty($data)) {
                $this->json_error('No migration analysis found. Run "Analyze" first.');
                return;
            }
            $this->json_success(['migration' => $data]);
        } catch (Exception $e) {
            $this->json_error('Failed to read migration data: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/migration/clear_map
    //
    // Deletes System/Migration/ so analysis can be re-run fresh.
    // ─────────────────────────────────────────────────────────────────────────
    public function clear_map()
    {
        if (!in_array($this->sa_role, ['developer', 'superadmin'], true)) {
            $this->json_error('Insufficient privileges.', 403);
            return;
        }

        try {
            $this->firebase->delete('System/Migration');
            $this->sa_log('migration_map_cleared', '');
            $this->json_success(['message' => 'Migration map cleared.']);
        } catch (Exception $e) {
            $this->json_error('Failed to clear migration map: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/migration/migrate_phone_index
    //
    // Reads the global Exits/ and User_ids_pno/ nodes and copies each entry
    // into the tenant-scoped Schools/{school_name}/Phone_Index/{phone} path.
    //
    // Steps:
    //   1. Read Exits/ — maps phone → school_id
    //   2. Read User_ids_pno/ — maps phone → userId
    //   3. For each phone, resolve school_name from Indexes/School_codes
    //   4. Write Schools/{school_name}/Phone_Index/{phone} → userId
    //   5. Report stats (migrated, skipped, errors)
    //
    // NON-DESTRUCTIVE: Does NOT delete the old Exits/User_ids_pno nodes.
    // The old global paths are kept for mobile app backward compatibility.
    // Controllers now dual-write to both paths.
    // ─────────────────────────────────────────────────────────────────────────
    public function migrate_phone_index()
    {
        if (!in_array($this->sa_role, ['developer', 'superadmin'], true)) {
            $this->json_error('Insufficient privileges.', 403);
            return;
        }

        $startTime = microtime(true);

        try {
            // Step 1: Read global phone indexes
            $exits      = $this->firebase->get('Exits') ?? [];
            $userIdsPno = $this->firebase->get('User_ids_pno') ?? [];
            if (!is_array($exits))      $exits      = [];
            if (!is_array($userIdsPno)) $userIdsPno = [];

            // Step 2: Build school_code → school_name lookup from Indexes/School_codes
            $schoolCodes = $this->firebase->get('Indexes/School_codes') ?? [];
            if (!is_array($schoolCodes)) $schoolCodes = [];

            // Build reverse map: school_id_or_code → school_name
            // School_codes maps code → school_name for legacy, code → SCH_xxx for new
            $codeToName = [];
            foreach ($schoolCodes as $code => $val) {
                if ($code === 'Count' || !is_string($val)) continue;
                $codeToName[(string)$code] = trim($val);
            }

            // Also build school_name → school_name (identity) for direct name references
            // Exits stores: phone → school_id (which may be a code like "10004" or a name like "Demo")
            // We need to resolve school_id → school_name for the Firebase path

            $migrated = 0;
            $skipped  = 0;
            $errors   = [];
            $batch    = []; // school_name → [phone => userId, ...]

            foreach ($exits as $phone => $schoolRef) {
                if (!is_string($phone) || $phone === '') continue;

                $userId = $userIdsPno[$phone] ?? null;
                if ($userId === null) {
                    $skipped++;
                    continue;
                }

                // Resolve schoolRef to school_name
                // schoolRef could be: a school code (e.g. "10004"), a school name (e.g. "Demo"),
                // or a SCH_ id. Try resolution in order:
                $schoolName = null;

                // 1. Direct match in School_codes (schoolRef is a code)
                if (isset($codeToName[$schoolRef])) {
                    $schoolName = $codeToName[$schoolRef];
                }

                // 2. schoolRef IS the school_name (legacy pattern where Exits stores school name)
                if ($schoolName === null) {
                    // Check if any school code points to this value
                    if (in_array($schoolRef, $codeToName, true)) {
                        $schoolName = $schoolRef;
                    }
                }

                // 3. Fallback — use the schoolRef directly as path segment
                if ($schoolName === null) {
                    $schoolName = $schoolRef;
                }

                if (empty($schoolName)) {
                    $errors[] = "Phone {$phone}: could not resolve school for ref '{$schoolRef}'";
                    continue;
                }

                // Batch writes per school
                if (!isset($batch[$schoolName])) {
                    $batch[$schoolName] = [];
                }
                $batch[$schoolName][$phone] = $userId;
            }

            // Step 3: Write batched tenant-scoped indexes
            foreach ($batch as $schoolName => $phoneMap) {
                try {
                    $this->firebase->update("Schools/{$schoolName}/Phone_Index", $phoneMap);
                    $migrated += count($phoneMap);
                } catch (Exception $e) {
                    $errors[] = "School '{$schoolName}': " . $e->getMessage();
                }
            }

            $elapsed = round((microtime(true) - $startTime) * 1000);

            $stats = [
                'total_exits_entries'     => count($exits),
                'total_user_ids_entries'  => count($userIdsPno),
                'schools_processed'       => count($batch),
                'phone_entries_migrated'  => $migrated,
                'skipped_no_user_id'      => $skipped,
                'errors'                  => $errors,
                'elapsed_ms'              => $elapsed,
                'migrated_at'             => date('Y-m-d H:i:s'),
            ];

            // Write migration log
            $this->firebase->set('System/Migration/PhoneIndex', $stats);

            $this->sa_log('phone_index_migration', '', [
                'migrated' => $migrated,
                'skipped'  => $skipped,
                'errors'   => count($errors),
            ]);

            $this->json_success([
                'stats'   => $stats,
                'message' => sprintf(
                    'Phone index migration complete: %d entries migrated across %d schools. %d skipped, %d errors.',
                    $migrated, count($batch), $skipped, count($errors)
                ),
            ]);

        } catch (Exception $e) {
            log_message('error', 'Phone index migration: ' . $e->getMessage());
            $this->json_error('Migration failed: ' . $e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE: Analyze a single school
    // ═════════════════════════════════════════════════════════════════════════
    private function _analyze_school(string $firebaseKey, array $sources, array $codeToValue, array $nameKeyToId): array
    {
        $isSCH = strpos($firebaseKey, 'SCH_') === 0;
        $warn  = [];
        $entry = [
            'firebase_key'       => $firebaseKey,
            'school_id'          => null,       // SCH_XXXXXX (existing or to-be-generated)
            'school_id_is_new'   => false,       // true if we generated a new SCH_ id
            'school_name'        => null,        // human-readable display name
            'school_code'        => null,        // admin login code
            'all_codes'          => [],          // all school_codes pointing to this school
            'legacy_key'         => null,        // original name-based key (if legacy)
            'status'             => 'unknown',   // legacy | migrated_ready | needs_review
            'sources'            => $sources,
            'data_locations'     => [],
            'profile_fields'     => [],
            'subscription'       => [],
            'sessions'           => [],
            'academic_sessions'  => [],
            'has_config'         => false,
            'has_students'       => false,
            'has_admin'          => false,
            'stats'              => [],
            'warnings'           => [],
            'migration_actions'  => [],          // what Phase 2 needs to do
        ];

        // ── Determine school_id ──────────────────────────────────────────
        if ($isSCH) {
            $entry['school_id'] = $firebaseKey;
        } else {
            // Legacy school — check if already has a SCH_ id via School_names
            $nameKey = $this->_school_name_key($firebaseKey);
            $mapped  = $nameKeyToId[$nameKey] ?? null;
            if ($mapped && strpos($mapped, 'SCH_') === 0) {
                $entry['school_id'] = $mapped;
            } else {
                // Generate a new school_id for migration
                $entry['school_id']       = $this->_generate_migration_id($firebaseKey);
                $entry['school_id_is_new'] = true;
            }
            $entry['legacy_key'] = $firebaseKey;
        }

        // ── Resolve school_code(s) from School_ids ───────────────────────
        foreach ($codeToValue as $code => $val) {
            if ($val === $firebaseKey || $val === $entry['school_id']) {
                $entry['all_codes'][] = $code;
                if (!$entry['school_code']) {
                    $entry['school_code'] = $code;
                }
            }
        }

        // ── Read Users/Schools/{key} (registry data) ─────────────────────
        $usersData = null;
        $usersKey  = $firebaseKey;
        if ($isSCH) {
            $usersData = $this->firebase->get("Users/Schools/{$firebaseKey}");
        } else {
            // Try both the name and the resolved school_id
            $usersData = $this->firebase->get("Users/Schools/{$firebaseKey}");
            if (empty($usersData) && $entry['school_id'] !== $firebaseKey) {
                $usersData = $this->firebase->get("Users/Schools/{$entry['school_id']}");
                if (!empty($usersData)) $usersKey = $entry['school_id'];
            }
        }

        if (!empty($usersData) && is_array($usersData)) {
            $entry['data_locations'][] = "Users/Schools/{$usersKey}";

            // Extract profile data
            $profile = is_array($usersData['profile'] ?? null) ? $usersData['profile'] : [];
            $entry['school_name'] = $profile['school_name'] ?? $profile['name'] ?? null;

            if (!$entry['school_code']) {
                $entry['school_code'] = $profile['school_code'] ?? ($usersData['School Id'] ?? null);
            }

            // Map profile fields present
            $profileKeys = ['school_name', 'name', 'school_code', 'school_id', 'city', 'street',
                            'email', 'phone', 'logo_url', 'status', 'created_at', 'firebase_key'];
            foreach ($profileKeys as $pk) {
                if (!empty($profile[$pk])) $entry['profile_fields']['Users/Schools/profile/' . $pk] = $profile[$pk];
            }

            // Legacy flat fields (from old Schools.php manage_school)
            $legacyFields = ['School Name', 'Address', 'Phone Number', 'Email', 'Logo',
                            'School Principal', 'Website', 'Affiliated To', 'Affiliation Number'];
            foreach ($legacyFields as $lf) {
                if (!empty($usersData[$lf])) {
                    $entry['profile_fields']['Users/Schools/' . $lf] = $usersData[$lf];
                    if (!$entry['school_name'] && $lf === 'School Name') {
                        $entry['school_name'] = $usersData[$lf];
                    }
                }
            }

            // Subscription
            if (is_array($usersData['subscription'] ?? null)) {
                $entry['subscription'] = [
                    'location' => "Users/Schools/{$usersKey}/subscription",
                    'status'   => $usersData['subscription']['status'] ?? 'unknown',
                    'plan'     => $usersData['subscription']['plan_name']
                                  ?? $usersData['subscription']['planName'] ?? 'unknown',
                    'expiry'   => $usersData['subscription']['expiry_date']
                                  ?? ($usersData['subscription']['duration']['endDate'] ?? 'unknown'),
                ];
            }

            // Stats cache
            if (is_array($usersData['stats_cache'] ?? null)) {
                $entry['stats'] = $usersData['stats_cache'];
            }
        }

        // ── Read System/Schools/{key} (SA metadata mirror) ───────────────
        $systemData = null;
        $systemKey  = $isSCH ? $firebaseKey : $entry['school_id'];
        $systemData = $this->firebase->get("System/Schools/{$systemKey}");
        if (empty($systemData) && !$isSCH) {
            $systemData = $this->firebase->get("System/Schools/{$firebaseKey}");
            if (!empty($systemData)) $systemKey = $firebaseKey;
        }

        if (!empty($systemData) && is_array($systemData)) {
            $entry['data_locations'][] = "System/Schools/{$systemKey}";

            $sysProfile = is_array($systemData['profile'] ?? null) ? $systemData['profile'] : [];
            if (!$entry['school_name'] && !empty($sysProfile['school_name'])) {
                $entry['school_name'] = $sysProfile['school_name'];
            }
            if (!$entry['school_code'] && !empty($sysProfile['school_code'])) {
                $entry['school_code'] = $sysProfile['school_code'];
            }

            // Check for data divergence between Users/Schools and System/Schools
            if (!empty($entry['subscription']) && is_array($systemData['subscription'] ?? null)) {
                $sysSub = $systemData['subscription'];
                $usrSub = $entry['subscription'];
                if (($sysSub['status'] ?? '') !== ($usrSub['status'] ?? '')) {
                    $warn[] = 'Subscription status mismatch: Users/Schools=' . ($usrSub['status'] ?? '?')
                            . ' vs System/Schools=' . ($sysSub['status'] ?? '?');
                }
            }
        }

        // ── Read Schools/{key}/Config/Profile (School_config profile) ────
        $configKey = $isSCH ? $firebaseKey : $firebaseKey;
        $configProfile = $this->firebase->get("Schools/{$configKey}/Config/Profile");
        if (!empty($configProfile) && is_array($configProfile)) {
            $entry['has_config'] = true;
            $entry['data_locations'][] = "Schools/{$configKey}/Config/Profile";

            // Check for profile fragmentation
            $configFields = ['display_name', 'address', 'phone', 'email', 'logo_url', 'principal_name'];
            foreach ($configFields as $cf) {
                if (!empty($configProfile[$cf])) {
                    $entry['profile_fields']['Schools/Config/Profile/' . $cf] = $configProfile[$cf];
                }
            }

            if (!$entry['school_name'] && !empty($configProfile['display_name'])) {
                $entry['school_name'] = $configProfile['display_name'];
            }
        }

        // If we still don't have a school_name, use the firebase key itself
        if (!$entry['school_name']) {
            $entry['school_name'] = $isSCH ? $firebaseKey : $firebaseKey;
            $warn[] = 'No human-readable school name found in any data source.';
        }

        // ── Read Schools/{key}/Sessions ──────────────────────────────────
        $sessions = $this->firebase->get("Schools/{$configKey}/Sessions");
        if (!empty($sessions) && is_array($sessions)) {
            $entry['sessions'] = array_values(array_filter($sessions, 'is_string'));
        }

        // ── Detect academic session data (shallow) ───────────────────────
        $academicKeys = $this->_safe_shallow("Schools/{$configKey}");
        foreach ($academicKeys as $ak) {
            // Session keys match pattern like "2024-25" or "2024-2025"
            if (preg_match('/^\d{4}-\d{2,4}$/', $ak)) {
                $entry['academic_sessions'][] = $ak;
            }
        }

        // Also check if academic data exists under a different key (legacy vs id)
        if (!$isSCH && $entry['school_id'] !== $firebaseKey) {
            $altAcademic = $this->_safe_shallow("Schools/{$entry['school_id']}");
            foreach ($altAcademic as $ak) {
                if (preg_match('/^\d{4}-\d{2,4}$/', $ak) && !in_array($ak, $entry['academic_sessions'], true)) {
                    $entry['academic_sessions'][] = $ak;
                    $entry['data_locations'][] = "Schools/{$entry['school_id']}/{$ak} (migrated copy)";
                }
            }
        }

        // ── Check Users/Parents/{school_id or code} ──────────────────────
        $parentKeys = [];
        if ($entry['school_code']) {
            $pk = $this->_safe_shallow("Users/Parents/{$entry['school_code']}");
            if (!empty($pk)) {
                $parentKeys           = $pk;
                $entry['has_students'] = true;
                $entry['data_locations'][] = "Users/Parents/{$entry['school_code']}";
            }
        }
        if (empty($parentKeys) && $entry['school_id']) {
            $pk = $this->_safe_shallow("Users/Parents/{$entry['school_id']}");
            if (!empty($pk)) {
                $parentKeys           = $pk;
                $entry['has_students'] = true;
                $entry['data_locations'][] = "Users/Parents/{$entry['school_id']}";
            }
        }
        if (empty($parentKeys) && $entry['legacy_key']) {
            $pk = $this->_safe_shallow("Users/Parents/{$entry['legacy_key']}");
            if (!empty($pk)) {
                $parentKeys           = $pk;
                $entry['has_students'] = true;
                $entry['data_locations'][] = "Users/Parents/{$entry['legacy_key']}";
            }
        }

        // ── Check Users/Admin/{school_code} ──────────────────────────────
        if ($entry['school_code']) {
            $adminKeys = $this->_safe_shallow("Users/Admin/{$entry['school_code']}");
            if (!empty($adminKeys)) {
                $entry['has_admin'] = true;
                $entry['data_locations'][] = "Users/Admin/{$entry['school_code']}";
            }
        }

        // ── Determine migration status ───────────────────────────────────
        if ($isSCH) {
            // Already uses SCH_ key — check if structure matches target
            $hasUsersSchools  = in_array("Users/Schools/{$firebaseKey}", $entry['data_locations'], true);
            $hasSystemSchools = false;
            foreach ($entry['data_locations'] as $loc) {
                if (strpos($loc, 'System/Schools/') === 0) $hasSystemSchools = true;
            }

            if ($hasUsersSchools && $hasSystemSchools) {
                $entry['status'] = 'migrated_ready';
                $entry['migration_actions'][] = 'Move profile/subscription from Users/Schools to System/Schools (consolidate)';
                $entry['migration_actions'][] = 'Delete Users/Schools/' . $firebaseKey . ' after consolidation';
            } elseif ($hasUsersSchools) {
                $entry['status'] = 'migrated_ready';
                $entry['migration_actions'][] = 'Copy profile/subscription to System/Schools/' . $firebaseKey;
                $entry['migration_actions'][] = 'Delete Users/Schools/' . $firebaseKey;
            } else {
                $entry['status'] = 'needs_review';
                $warn[] = 'SCH_ keyed school but no Users/Schools or System/Schools data found.';
            }
        } else {
            $entry['status'] = 'legacy';
            $entry['migration_actions'][] = "Generate school_id: {$entry['school_id']}";
            $entry['migration_actions'][] = "Consolidate profile from all sources → System/Schools/{$entry['school_id']}/profile";
            $entry['migration_actions'][] = "Move subscription → System/Schools/{$entry['school_id']}/subscription";
            if (!empty($entry['academic_sessions'])) {
                $entry['migration_actions'][] = "Copy academic data: Schools/{$firebaseKey}/* → Schools/{$entry['school_id']}/*";
            }
            if ($entry['has_config']) {
                $entry['migration_actions'][] = "Move config → System/Schools/{$entry['school_id']}/settings/";
            }
            $entry['migration_actions'][] = "Update Indexes/School_codes/{$entry['school_code']} → {$entry['school_id']}";
            $entry['migration_actions'][] = "Write Indexes/School_names → {$entry['school_id']}";
            $entry['migration_actions'][] = "Delete Users/Schools/{$firebaseKey} after consolidation";
        }

        // ── Generate school_code if none found ───────────────────────────
        if (!$entry['school_code']) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $entry['school_name']), 0, 3));
            if (strlen($prefix) < 2) $prefix = 'SCH';
            $entry['school_code'] = $prefix . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
            $entry['school_code_is_generated'] = true;
            $warn[] = 'No school_code found. Auto-generated: ' . $entry['school_code'];
        }

        // ── Profile fragmentation check ──────────────────────────────────
        $profileLocations = 0;
        foreach ($entry['data_locations'] as $loc) {
            if (strpos($loc, 'Users/Schools') !== false) $profileLocations++;
            if (strpos($loc, 'System/Schools') !== false) $profileLocations++;
            if (strpos($loc, 'Config/Profile') !== false) $profileLocations++;
        }
        if ($profileLocations > 1) {
            $warn[] = "Profile data found in {$profileLocations} locations — needs consolidation.";
        }

        $entry['warnings'] = $warn;
        return $entry;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Safe shallow_get that returns an array of keys, never throws.
     */
    private function _safe_shallow(string $path): array
    {
        try {
            $result = $this->firebase->shallow_get($path);
            return is_array($result) ? $result : [];
        } catch (Exception $e) {
            log_message('error', "Migration: shallow_get({$path}) failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate a deterministic-but-unique school_id from a school name.
     * Uses first 6 hex chars of md5(name) prefixed with SCH_.
     * Checks for collision against existing keys.
     */
    private function _generate_migration_id(string $schoolName): string
    {
        // Deterministic: same name always generates same candidate
        $base = strtoupper(substr(md5($schoolName), 0, 6));
        $candidate = 'SCH_' . $base;

        // Check collision against Users/Schools and System/Schools
        $existing = $this->firebase->get("Users/Schools/{$candidate}");
        if (!empty($existing)) {
            // Collision — append 2 random hex chars
            $candidate = 'SCH_' . $base . strtoupper(bin2hex(random_bytes(1)));
        }

        return $candidate;
    }

    /**
     * Convert school name to a safe Firebase-compatible key.
     * Matches the pattern used by Superadmin_schools::_school_name_key().
     */
    private function _school_name_key(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($name));
    }
}
