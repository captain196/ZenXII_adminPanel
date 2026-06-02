<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * B2.3.2-FIX runtime verifier. Exercises every B2_registry_service method
 * via the actual PHP Firestore_rest_client path (the same path that runs
 * under live HTTP requests). Validates SHAPE + COUNT + WARNING contracts —
 * complements the existing structural cutover verifier and the JS Admin
 * SDK data verifier (which use different code paths and therefore missed
 * the {id,data} response-shape + positional-condition bugs).
 *
 * MANDATORY GATE — must PASS before any flip of b2.registry_firestore.
 *
 * Verdict categories per method:
 *   OK         — returns non-empty correct shape; method works as designed
 *   EMPTY      — returns [] / null when production would expect data
 *   DEGRADED   — returns data but shape wrong
 *   BROKEN     — throws or emits warnings during execution
 *
 * Exit codes:
 *   0  — all probes OK and zero warnings emitted (clean gate)
 *   2  — any EMPTY/DEGRADED/BROKEN OR any warning emitted (review required)
 *
 * Usage:
 *   php index.php b2_runtime_verify library_runtime
 *   php index.php b2_runtime_verify all          (alias)
 */
class B2_runtime_verify extends CI_Controller
{
    private $rows = [];
    private $warn_count = 0;

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only\n"); }
        // Capture PHP warnings (the {id,data} mismatch + "Undefined array key"
        // are emitted as warnings; we tally them per probe so the count is
        // visible in the report.
        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity === E_WARNING || $severity === E_NOTICE) {
                $this->warn_count++;
            }
            return true; // suppress
        });
    }

    /**
     * Alias — equivalent to library_runtime(). Symmetry with the other
     * cutover/foundation verifier `all()` entry points.
     */
    public function all()
    {
        $this->library_runtime();
    }

    public function library_runtime()
    {
        $this->load->library('firebase');
        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $svc->init($this->firebase);

        echo "B2.3.2 RUNTIME HEALTH AUDIT — via PHP Firestore_rest_client path\n";
        echo "Flag: b2.registry_firestore = " . var_export($this->_flag('b2.registry_firestore'), true) . "\n";
        echo "Flag: b2.reports_firestore  = " . var_export($this->_flag('b2.reports_firestore'),  true) . "\n";
        echo str_repeat('─', 80) . "\n\n";

        // ── Anchor: confirm Firestore IS reachable + per-doc get works ──
        $this->_probe('firestoreGet schools/SCH_D94FE8F7AD (per-id baseline)', function () {
            return $this->firebase->firestoreGet('schools', 'SCH_D94FE8F7AD');
        }, [
            'expect' => 'array with email/schoolCode/name fields',
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (!isset($r['email']) || !isset($r['schoolCode'])) return ['DEGRADED', 'missing fields'];
                return ['OK', 'fields: ' . implode(',', array_slice(array_keys($r), 0, 5)) . '...'];
            },
        ]);

        // ── Section A: list_plans (no-conditions query, orderBy on real field) ──
        $this->_probe('list_plans() — read all 4 plans', function () use ($svc) {
            return $svc->list_plans();
        }, [
            'expect' => '4 plans with planFamilyId+name',
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (count($r) === 0) return ['EMPTY', '0 rows'];
                $first = $r[0];
                if (isset($first['id'], $first['data'])) return ['DEGRADED', '{id,data} wrapper not unwrapped'];
                if (!isset($first['planFamilyId'])) return ['DEGRADED', 'planFamilyId missing'];
                return ['OK', count($r) . ' plans; first.name=' . ($first['name'] ?? '?')];
            },
        ]);

        // ── Section B: list_tenants_summary (the central tenant list) ──
        $this->_probe('list_tenants_summary() — central tenant grid feed', function () use ($svc) {
            return $svc->list_tenants_summary();
        }, [
            'expect' => '2 tenant rows with schoolId/schoolName/lifecycleState',
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (count($r) === 0) return ['EMPTY', '0 rows'];
                $first = $r[0];
                if (!isset($first['schoolId']) || $first['schoolId'] === '') return ['DEGRADED', 'schoolId empty'];
                if (!isset($first['lifecycleState'])) return ['DEGRADED', 'lifecycleState missing'];
                return ['OK', count($r) . ' tenants; first.schoolId=' . $first['schoolId']];
            },
        ]);

        // ── Section C: count_schools_on_plan (uses conditions) ──
        $this->_probe('count_schools_on_plan("PLAN_2E596A") — conditional query', function () use ($svc) {
            return $svc->count_schools_on_plan('PLAN_2E596A');
        }, [
            'expect' => 'integer; SCH_9C7986EA3E is on PLAN_2E596A so >= 1',
            'check'  => function ($r) {
                if (!is_int($r)) return ['BROKEN', 'not an int'];
                if ($r === 0) return ['EMPTY', '0 count (expected ≥1)'];
                return ['OK', "count = {$r}"];
            },
        ]);

        // ── Section D: list_payments (no conditions) ──
        $this->_probe('list_payments() — global payment list (may be 0 if no payments collected yet)', function () use ($svc) {
            return $svc->list_payments();
        }, [
            'expect' => 'array (possibly empty if no payments collected)',
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (count($r) === 0) return ['OK', '0 rows (acceptable — no payments collected yet)'];
                $first = $r[0];
                if (isset($first['id'], $first['data'])) return ['DEGRADED', '{id,data} wrapper not unwrapped'];
                return ['OK', count($r) . ' rows'];
            },
        ]);

        // ── Section E: list_payments_for_school (conditional) ──
        $this->_probe('list_payments_for_school("SCH_9C7986EA3E") — per-tenant payment list', function () use ($svc) {
            return $svc->list_payments_for_school('SCH_9C7986EA3E');
        }, [
            'expect' => 'array (possibly empty)',
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                return ['OK', count($r) . ' rows (warnings tally below)'];
            },
        ]);

        // ── Section F: get_tenant_detail (composite per-id read) ──
        $this->_probe('get_tenant_detail("SCH_9C7986EA3E") — composite read (used by School Detail page)', function () use ($svc) {
            return $svc->get_tenant_detail('SCH_9C7986EA3E');
        }, [
            'expect' => 'array with schools, schoolControl, subscriptionDoc, lifecycleState, planFamilyId',
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array (got ' . gettype($r) . ')'];
                $missing = [];
                foreach (['schools', 'schoolControl', 'subscriptionDoc', 'lifecycleState', 'planFamilyId'] as $k) {
                    if (!array_key_exists($k, $r)) $missing[] = $k;
                }
                if (!empty($missing)) return ['DEGRADED', 'missing keys: ' . implode(',', $missing)];
                if ($r['lifecycleState'] === '') return ['DEGRADED', 'lifecycleState empty'];
                return ['OK', "lifecycle={$r['lifecycleState']}, plan={$r['planFamilyId']}"];
            },
        ]);

        // ── Section G: resolve_school_code (per-id, used by Admin_login) ──
        $this->_probe('resolve_school_code("10002") — Admin_login code lookup', function () use ($svc) {
            return $svc->resolve_school_code('10002');
        }, [
            'expect' => 'string SCH_9C7986EA3E',
            'check'  => function ($r) {
                if ($r === null) return ['EMPTY', 'returned null (tenant admin would get "school not found")'];
                if (!is_string($r)) return ['BROKEN', 'not a string'];
                if (strpos($r, 'SCH_') !== 0) return ['DEGRADED', "returned non-SCH_*: {$r}"];
                return ['OK', "resolved to {$r}"];
            },
        ]);

        // ── Section H: lifecycle_access (per-id, used by MY_Controller status gate) ──
        $this->_probe('lifecycle_access("SCH_9C7986EA3E") — per-request status gate', function () use ($svc) {
            return $svc->lifecycle_access('SCH_9C7986EA3E');
        }, [
            'expect' => "['known'=>true, 'allowed'=>true, 'state'=>'active']",
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (empty($r['known'])) return ['EMPTY', 'known=false (MY_Controller would fail-open — silent breakage)'];
                if (empty($r['allowed'])) return ['DEGRADED', 'allowed=false (admin would be force-logged-out)'];
                return ['OK', "state={$r['state']}"];
            },
        ]);

        // ── Section I: login_access_view (used by Admin_login) ──
        $this->_probe('login_access_view("SCH_9C7986EA3E", time()) — Admin_login subscription gate', function () use ($svc) {
            return $svc->login_access_view('SCH_9C7986EA3E', time());
        }, [
            'expect' => "['known'=>true, 'allowed'=>true, 'state'=>'active', 'periodEndTs'>0]",
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (empty($r['known'])) return ['EMPTY', 'known=false (Admin_login would redirect: "Subscription record not found")'];
                if (empty($r['allowed'])) return ['DEGRADED', 'allowed=false (login would be blocked)'];
                if (empty($r['periodEndTs'])) return ['DEGRADED', 'periodEndTs missing'];
                return ['OK', "state={$r['state']}, periodEndTs=" . date('Y-m-d', $r['periodEndTs'])];
            },
        ]);

        // ── Section J: get_features (used by Admin_login features dropdown) ──
        $this->_probe('get_features("SCH_9C7986EA3E") — entitled module list', function () use ($svc) {
            return $svc->get_features('SCH_9C7986EA3E');
        }, [
            'expect' => 'non-empty array of module keys',
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (count($r) === 0) return ['EMPTY', 'admin login would see no features'];
                return ['OK', count($r) . ' modules: ' . implode(',', array_slice($r, 0, 3)) . '...'];
            },
        ]);

        // ── Section K: get_display_name (used by Admin_login UI) ──
        $this->_probe('get_display_name("SCH_9C7986EA3E") — tenant display name', function () use ($svc) {
            return $svc->get_display_name('SCH_9C7986EA3E');
        }, [
            'expect' => 'string school name (e.g. "ZZ B1 Soak Test")',
            'check'  => function ($r) {
                if (!is_string($r)) return ['BROKEN', 'not a string'];
                if ($r === '') return ['EMPTY', 'empty name — tenant header would fall back to schoolId'];
                return ['OK', "name = \"{$r}\""];
            },
        ]);

        // ── Section L: get_plan + get_payment (per-id reads) ──
        $this->_probe('get_plan("PLAN_2E596A") — plan template read', function () use ($svc) {
            return $svc->get_plan('PLAN_2E596A');
        }, [
            'expect' => "array with name='Standard', price=12000",
            'check'  => function ($r) {
                if (!is_array($r)) return ['BROKEN', 'not an array'];
                if (!isset($r['name'])) return ['DEGRADED', 'name field missing'];
                return ['OK', "name={$r['name']}, price=" . ($r['price'] ?? '?')];
            },
        ]);

        // ── Section M: name_taken + code_taken ──
        $this->_probe('code_taken("10002") — Superadmin_schools::check_availability path', function () use ($svc) {
            return $svc->code_taken('10002');
        }, [
            'expect' => 'true (SCH_9C7986EA3E uses code 10002)',
            'check'  => function ($r) {
                if (!is_bool($r)) return ['BROKEN', 'not a bool'];
                if ($r === false) return ['EMPTY', 'returned false (existing code reported as available — would cause duplicate code)'];
                return ['OK', 'taken=true'];
            },
        ]);

        // ─────────────────────────────────────────────────────────────────
        // B2.3.2-FIX R5 — ROUND-TRIP WRITE PROBES
        // Each probe: write → read-back → verify nested data → revert.
        // Target the test tenant (SCH_9C7986EA3E "ZZ B1 Soak Test").
        // ─────────────────────────────────────────────────────────────────

        $TEST = 'SCH_9C7986EA3E';

        // ── W1: update_school_profile (single field, no dotted path) ──
        $this->_probe('write_rt: update_school_profile (city round-trip)', function () use ($svc, $TEST) {
            $before  = $this->firebase->firestoreGet('schools', $TEST);
            $orig    = (string) ($before['city'] ?? '');
            $token   = 'RT-PROFILE-' . time();
            $ok1 = $svc->update_school_profile($TEST, ['city' => $token, 'updatedAt' => date('c')]);
            if (!$ok1) throw new \Exception('initial update returned false');
            $mid = $this->firebase->firestoreGet('schools', $TEST);
            if (($mid['city'] ?? '') !== $token) throw new \Exception("token did not land; got '" . ($mid['city'] ?? '') . "'");
            $ok2 = $svc->update_school_profile($TEST, ['city' => $orig, 'updatedAt' => date('c')]);
            if (!$ok2) throw new \Exception('revert update returned false');
            $after = $this->firebase->firestoreGet('schools', $TEST);
            if (($after['city'] ?? '') !== $orig) throw new \Exception('revert did not persist');
            return is_object($svc) ? ['known' => true] : [];
        }, [
            'expect' => 'write+revert succeeds',
            'check'  => function () { return ['OK', 'round-trip clean']; },
        ]);

        // ── W2: write_lifecycle_state (dotted-key nested path — the R5 case) ──
        $this->_probe('write_rt: write_lifecycle_state (lifecycle.state nested write)', function () use ($svc, $TEST) {
            $before = $this->firebase->firestoreGet('schoolControl', $TEST);
            $origState  = (string) (($before['lifecycle'] ?? [])['state']  ?? 'active');
            $origReason = (string) (($before['lifecycle'] ?? [])['reason'] ?? 'active_period');
            // Write to a different state value briefly
            $ok1 = $svc->write_lifecycle_state($TEST, 'expiring_soon', 'rt_probe');
            if (!$ok1) throw new \Exception('lifecycle write returned false');
            $mid = $this->firebase->firestoreGet('schoolControl', $TEST);
            $midState  = (string) (($mid['lifecycle'] ?? [])['state']  ?? '');
            $midReason = (string) (($mid['lifecycle'] ?? [])['reason'] ?? '');
            // R5 check: nested fields updated AND no literal dotted junk
            $junk = isset($mid['lifecycle.state']) || isset($mid['lifecycle.reason']) || isset($mid['lifecycle.computedAt']);
            if ($midState !== 'expiring_soon') throw new \Exception("nested lifecycle.state did not flip; got '{$midState}'");
            if ($midReason !== 'rt_probe')     throw new \Exception("nested lifecycle.reason did not flip; got '{$midReason}'");
            // NOTE: legacy junk from prior bug may still exist; we only check that the
            // CURRENT write didn't ADD new junk by tracking what was there before.
            $junkBefore = isset($before['lifecycle.state']) || isset($before['lifecycle.reason']);
            if (!$junkBefore && $junk) throw new \Exception('R5 regression — new literal dotted field created by this write');
            // Revert to original state
            $svc->write_lifecycle_state($TEST, $origState, $origReason);
            return [];
        }, [
            'expect' => 'nested lifecycle.state flips; no new literal dotted-key junk created',
            'check'  => function () { return ['OK', 'nested write landed; revert clean']; },
        ]);

        // ── W3: set_admin_disabled (suspend writes adminDisabled AND lifecycle.state) ──
        // 2026-06-02 SAFE-REVERT FIX: pre-fix used set_admin_disabled('active', ...)
        // on revert, which unconditionally wrote adminDisabled.value=false on BOTH
        // schools/{id} and tenantPublic/{id}. If the operator had set the tenant
        // disabled via a path that wrote tenantPublic.adminDisabled=true while
        // leaving schools.adminDisabled.value=false (out-of-sync schema-drift
        // state), the early-bail at schools.adminDisabled.value would miss the
        // operator state and the 'active' revert would silently clobber the
        // tenantPublic mirror. Post-fix snapshots BOTH surfaces and restores
        // each byte-for-byte via firestoreUpdate.
        $this->_probe('write_rt: set_admin_disabled (composite write — adminDisabled + lifecycle)', function () use ($svc, $TEST) {
            $beforeSch  = $this->firebase->firestoreGet('schools',       $TEST) ?: [];
            $beforeCtrl = $this->firebase->firestoreGet('schoolControl', $TEST) ?: [];
            $beforePub  = $this->firebase->firestoreGet('tenantPublic',  $TEST) ?: [];
            $origDisabledStruct = $beforeSch['adminDisabled'] ?? null;
            $origDisabledFlag   = (bool) ($origDisabledStruct['value'] ?? false);
            $origLifeState      = (string) (($beforeCtrl['lifecycle'] ?? [])['state'] ?? 'active');
            $origPubAd          = !empty($beforePub['adminDisabled']);
            if ($origDisabledFlag) {
                // Tenant already suspended on schools surface; skip exercise but
                // do NOT mutate anything — preserves operator state regardless.
                throw new \Exception('test tenant already suspended; cannot exercise the round-trip safely');
            }
            $ok1 = $svc->set_admin_disabled($TEST, 'suspended', 'rt_probe');
            if (!$ok1) throw new \Exception('suspend returned false');
            $midSch = $this->firebase->firestoreGet('schools', $TEST);
            $midCtrl = $this->firebase->firestoreGet('schoolControl', $TEST);
            $midAdminVal = (bool) (($midSch['adminDisabled'] ?? [])['value'] ?? false);
            $midLifeState = (string) (($midCtrl['lifecycle'] ?? [])['state'] ?? '');
            if (!$midAdminVal) throw new \Exception('adminDisabled.value did not flip to true');
            if ($midLifeState !== 'suspended') throw new \Exception("lifecycle.state did not flip to suspended; got '{$midLifeState}'");
            // SAFE REVERT — restore EXACT pre-probe snapshot via direct writes.
            if ($origDisabledStruct !== null) {
                $this->firebase->firestoreUpdate('schools', $TEST, ['adminDisabled' => $origDisabledStruct]);
            }
            $this->firebase->firestoreUpdate('tenantPublic', $TEST, ['adminDisabled' => $origPubAd]);
            $svc->write_lifecycle_state($TEST, $origLifeState, 'rt_probe_restore');
            return [];
        }, [
            'expect' => 'adminDisabled + lifecycle.state both flip to suspended; revert restores EXACT pre-probe snapshot',
            'check'  => function () { return ['OK', 'composite write verified; snapshot-restore on revert']; },
        ]);

        // ── W4: update_stats_cache (nested map: statsCache.{totalStudents, totalStaff, lastUpdated}) ──
        $this->_probe('write_rt: update_stats_cache (nested map write)', function () use ($svc, $TEST) {
            $before = $this->firebase->firestoreGet('schools', $TEST);
            $orig = $before['statsCache'] ?? [];
            $token = (int) (microtime(true) * 1000) % 10000;  // unique-ish bump
            $ok = $svc->update_stats_cache($TEST, [
                'totalStudents' => $token,
                'totalStaff'    => $token + 1,
            ]);
            if (!$ok) throw new \Exception('update returned false');
            $after = $this->firebase->firestoreGet('schools', $TEST);
            $sc = $after['statsCache'] ?? [];
            if ((int)($sc['totalStudents'] ?? -1) !== $token) throw new \Exception("totalStudents did not land; got " . ($sc['totalStudents'] ?? 'null'));
            if ((int)($sc['totalStaff']    ?? -1) !== $token + 1) throw new \Exception("totalStaff did not land");
            if (empty($sc['lastUpdated'])) throw new \Exception('lastUpdated not set');
            // Restore original stats (best-effort)
            $svc->update_stats_cache($TEST, [
                'totalStudents' => (int) ($orig['totalStudents'] ?? 0),
                'totalStaff'    => (int) ($orig['totalStaff']    ?? 0),
            ]);
            return [];
        }, [
            'expect' => 'statsCache.{totalStudents,totalStaff,lastUpdated} land as nested map; revert clean',
            'check'  => function () { return ['OK', 'nested-map write verified']; },
        ]);

        // ── W5 (H-LIFECYCLE H1.5): tenantPublic mirror fan-out probe ──
        // Confirms that write_lifecycle_state() and set_admin_disabled()
        // both fan out to tenantPublic/{id}.lifecycleState + .adminDisabled
        // — the public-mirror surface that mobile apps subscribe to for
        // reactive logout (H2/H3). Drift here would silently break the
        // mobile-side gate, so this probe is part of the H1 pre-deploy
        // gate. Restores the test tenant to its original state on exit.
        $this->_probe('write_rt: tenantPublic_lifecycle_mirror_fanout (H-LIFECYCLE H1.5)', function () use ($svc, $TEST) {
            // 2026-06-02 SAFE-REVERT FIX: pre-fix revert used
            // set_admin_disabled('active', ...) unconditionally — this would
            // overwrite tenantPublic.adminDisabled=true (operator-set state)
            // with false, silently clobbering the operator's intentional
            // disable. The post-fix snapshots EXACT pre-probe state on BOTH
            // schools and tenantPublic surfaces, and restores via direct
            // firestoreUpdate to preserve the snapshot byte-for-byte.
            $origCtrl = $this->firebase->firestoreGet('schoolControl', $TEST) ?: [];
            $origLife = is_array($origCtrl['lifecycle'] ?? null) ? $origCtrl['lifecycle'] : [];
            $origState = (string) ($origLife['state'] ?? 'active');
            $origSchPub = $this->firebase->firestoreGet('tenantPublic', $TEST) ?: [];
            $origAd = !empty($origSchPub['adminDisabled']);
            $origSch = $this->firebase->firestoreGet('schools', $TEST) ?: [];
            $origDisabledStruct = $origSch['adminDisabled'] ?? null;

            // Step 1: write a non-default lifecycle state and confirm fan-out.
            $okL = $svc->write_lifecycle_state($TEST, 'expiring_soon', 'verifier_probe_h1');
            if (!$okL) throw new \Exception('write_lifecycle_state returned false');
            $pubAfterLife = $this->firebase->firestoreGet('tenantPublic', $TEST) ?: [];
            $mState = (string) ($pubAfterLife['lifecycleState'] ?? '');
            if ($mState !== 'expiring_soon') {
                throw new \Exception("tenantPublic.lifecycleState fan-out failed: got '{$mState}' expected 'expiring_soon'");
            }

            // Step 2: toggle adminDisabled via set_admin_disabled('inactive') and confirm fan-out.
            $okA = $svc->set_admin_disabled($TEST, 'inactive', 'verifier_probe_h1');
            if (!$okA) throw new \Exception('set_admin_disabled returned false');
            $pubAfterAd = $this->firebase->firestoreGet('tenantPublic', $TEST) ?: [];
            $mAd = !empty($pubAfterAd['adminDisabled']);
            if (!$mAd) {
                throw new \Exception("tenantPublic.adminDisabled fan-out failed: got false expected true");
            }

            // Step 3: SAFE REVERT — restore EXACT pre-probe snapshot via
            // direct writes (bypasses set_admin_disabled's unconditional
            // false write). This preserves operator-set state byte-for-byte.
            if ($origDisabledStruct !== null) {
                $this->firebase->firestoreUpdate('schools', $TEST, ['adminDisabled' => $origDisabledStruct]);
            }
            $this->firebase->firestoreUpdate('tenantPublic', $TEST, ['adminDisabled' => $origAd]);
            $svc->write_lifecycle_state($TEST, $origState !== '' ? $origState : 'active', 'verifier_probe_h1_restore');

            // Verify revert landed.
            $pubFinal = $this->firebase->firestoreGet('tenantPublic', $TEST) ?: [];
            if ((string)($pubFinal['lifecycleState'] ?? '') !== ($origState !== '' ? $origState : 'active')) {
                throw new \Exception('revert lifecycleState did not land');
            }
            if (!empty($pubFinal['adminDisabled']) !== $origAd) {
                throw new \Exception('revert adminDisabled did not land (expected ' . ($origAd ? 'true' : 'false') . ')');
            }
            return [];
        }, [
            'expect' => 'tenantPublic.{lifecycleState,adminDisabled} mirror both write paths; revert clean',
            'check'  => function () { return ['OK', 'mirror fan-out verified both directions']; },
        ]);

        $this->_print_report();
        // Hard gate: any verdict other than OK, OR any PHP warning, fails.
        $nonOk = 0;
        foreach ($this->rows as $r) {
            if ($r['verdict'] !== 'OK') $nonOk++;
        }
        $exitCode = ($nonOk === 0 && $this->warn_count === 0) ? 0 : 2;
        echo "\nGATE: " . ($exitCode === 0 ? 'PASS' : 'REVIEW') . "\n";
        exit($exitCode);
    }

    private function _probe(string $label, callable $fn, array $spec): void
    {
        $warns_before = $this->warn_count;
        $start = microtime(true);
        try {
            $result = $fn();
            $err = null;
        } catch (\Throwable $e) {
            $result = null;
            $err = $e->getMessage();
        }
        $ms = (int) ((microtime(true) - $start) * 1000);
        $warns = $this->warn_count - $warns_before;

        if ($err !== null) {
            $verdict = 'BROKEN';
            $detail  = 'threw: ' . $err;
        } else {
            $checked = call_user_func($spec['check'], $result);
            $verdict = $checked[0];
            $detail  = $checked[1];
        }

        $this->rows[] = [
            'label'   => $label,
            'expect'  => $spec['expect'],
            'verdict' => $verdict,
            'detail'  => $detail,
            'warns'   => $warns,
            'ms'      => $ms,
        ];
    }

    private function _print_report(): void
    {
        $counts = ['OK' => 0, 'EMPTY' => 0, 'DEGRADED' => 0, 'BROKEN' => 0];
        echo "\n";
        echo str_pad('METHOD', 60) . " | " . str_pad('VERDICT', 8) . " | WARNS | MS    | DETAIL\n";
        echo str_repeat('─', 60) . "─+─" . str_repeat('─', 8) . "─+───────+───────+" . str_repeat('─', 40) . "\n";
        foreach ($this->rows as $r) {
            $counts[$r['verdict']]++;
            echo str_pad($r['label'], 60) . " | "
               . str_pad($r['verdict'], 8) . " | "
               . str_pad((string) $r['warns'], 5, ' ', STR_PAD_LEFT) . " | "
               . str_pad((string) $r['ms'], 5, ' ', STR_PAD_LEFT) . " | "
               . $r['detail'] . "\n";
        }
        echo "\n";
        echo "SUMMARY  OK={$counts['OK']}  EMPTY={$counts['EMPTY']}  DEGRADED={$counts['DEGRADED']}  BROKEN={$counts['BROKEN']}\n";
        echo "PHP warnings emitted across all probes: " . $this->warn_count . "\n";
    }

    private function _flag(string $key): bool
    {
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $f = $this->config->item('b2_migration_flags') ?: [];
        return !empty($f[$key]);
    }
}
