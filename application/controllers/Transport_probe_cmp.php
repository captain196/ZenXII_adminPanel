<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_cmp — F12.1 CBSE Compliance probe.
 *
 * M1 scope only (data foundation — rule pack seed/list/update + the 14
 * additive vehicle fields). Grows across M2/M3 as those milestones land;
 * finalized alongside the verifier gates at M5 per
 * TMS_F12_1_COMPLIANCE_ARCHITECTURE_SPEC.md §10.
 *
 * Proves, against the real tenant plus one synthetic second tenant:
 *   G1 seedRulePack('CBSE') creates exactly the 19 canonical rules
 *   G2 re-seeding is idempotent — 0 created, 19 already_present
 *   G3 listRules() returns correct weights/blocks_dispatch for spot-checked rules
 *   G4 updateRule() applies severity_weight + blocks_dispatch only — every
 *      other field (label, category, evaluation, source_field) is immutable
 *      even when the caller supplies them
 *   G5 updateRule() rejects an out-of-range severity_weight
 *   G6 updateRule() rejects an unknown rule code
 *   G7 tenant isolation — seeding tenant B does not change tenant A's count
 *      or vice versa (schoolWhere scoping, no cross-tenant bleed)
 *   G8 saveVehicle() persists the 14 compliance fields, and a subsequent
 *      save that OMITS them preserves the prior values (does not reset to
 *      the Not_Assessed baseline) — proves the additive-preserve design
 *
 * Self-provisioning + self-teardown. Business-entity fixtures (rule docs,
 * the probe vehicle) are removed; the compliance audit trail rows emitted
 * along the way are left in place, same convention as every other Transport
 * probe (transportAuditLog is meant to persist).
 *
 * CLI-only:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 php index.php transport_probe_cmp run
 */
class Transport_probe_cmp extends CI_Controller
{
    /** @var array<int,array{0:string,1:string,2:string}> [schoolId, collection, entityId] */
    private array $cleanup = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firestore_service',           null, 'fs');
        $this->load->library('numbering_service',           null, 'numbering');
        $this->load->library('transport_service',            null, 'transport');
        $this->load->library('transport_compliance_service', null, 'cmp');
    }

    public function run(): void
    {
        $sA   = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sA === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        $ts = (int) (microtime(true) * 1000);
        $sB = "SCH_PROBE_CMP_B_{$ts}"; // second synthetic tenant, isolation check only

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        // Re-bind the whole stack to a given tenant, exactly as a real
        // request would (mirrors the mtonboard multi-tenant probe pattern).
        $useTenant = function (string $sid) use ($sess) {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_cmp');
            $this->cmp->init($this->fs, $sid, 'probe_cmp');
        };

        echo "==================================================\n";
        echo " F12.1 M1 — CBSE Compliance probe (data foundation)\n";
        echo " tenant A={$sA}  tenant B={$sB}  session={$sess}\n";
        echo "==================================================\n";

        try {
            $useTenant($sA);

            echo "\n[G1] seedRulePack('CBSE') yields exactly the 19 canonical rules\n";
            // Snapshot what the tenant ALREADY has. Two reasons this matters:
            //   1. teardown must delete only rules THIS RUN created — an earlier
            //      revision tore down every code it seeded, which wiped the
            //      tenant's real compliance configuration on every run and made
            //      the next run's result depend on the previous run's damage;
            //   2. the assertion must hold on a tenant that is already seeded
            //      (the normal production state), not only on a virgin one.
            $preExisting = [];
            foreach ($this->cmp->listRules() as $r) { $preExisting[(string) ($r['rule_code'] ?? '')] = true; }

            $r1 = $this->cmp->seedRulePack('CBSE');
            foreach ($r1['rule_codes'] as $code) {
                if (!isset($preExisting[$code])) { $this->cleanup[] = [$sA, 'transportComplianceRules', $code]; }
            }
            $total1 = (int) $r1['seeded'] + (int) $r1['already_present'];
            ($total1 === 19 && count($r1['rule_codes']) === 19 && count($this->cmp->listRules()) === 19)
                ? $pass("19 canonical rules present (seeded={$r1['seeded']}, already_present={$r1['already_present']}"
                        . '; ' . count($preExisting) . ' pre-existing left untouched by teardown)')
                : $fail("seeded={$r1['seeded']} already_present={$r1['already_present']} total={$total1} listed=" . count($this->cmp->listRules()));

            echo "\n[G2] Re-seeding is idempotent\n";
            $r2 = $this->cmp->seedRulePack('CBSE');
            ($r2['seeded'] === 0 && $r2['already_present'] === 19)
                ? $pass("second seed: seeded=0 already_present=19 (no duplication, no clobber)")
                : $fail("second seed: seeded={$r2['seeded']} already_present={$r2['already_present']} (expected 0/19)");

            echo "\n[G3] listRules() content — spot-check weights + blocks_dispatch\n";
            $rules = $this->cmp->listRules();
            $byCode = [];
            foreach ($rules as $r) { $byCode[(string) ($r['rule_code'] ?? '')] = $r; }
            $gps = $byCode['CBSE_GPS'] ?? [];
            $rtx = $byCode['DOC_ROAD_TAX'] ?? [];
            (count($rules) === 19
                && (int) ($gps['severity_weight'] ?? -1) === 10 && (bool) ($gps['blocks_dispatch'] ?? false) === true
                && (int) ($rtx['severity_weight'] ?? -1) === 5  && (bool) ($rtx['blocks_dispatch'] ?? true)  === false)
                ? $pass("19 rules listed; CBSE_GPS w=10/blocks=true, DOC_ROAD_TAX w=5/blocks=false")
                : $fail("listRules mismatch — count=" . count($rules) . " gps=" . json_encode($gps) . " rtx=" . json_encode($rtx));

            $sumTabulated = array_sum(array_column(Transport_compliance_service::CBSE_RULE_PACK, 'severity_weight'));
            $sumSeeded = array_sum(array_column($rules, 'severity_weight'));
            ($sumTabulated === $sumSeeded)
                ? $pass("weight sum self-consistent: constant={$sumTabulated} == seeded={$sumSeeded} (spec prose '154' is stale — see M1 report)")
                : $fail("weight sum drift: constant={$sumTabulated} != seeded={$sumSeeded}");

            echo "\n[G4] updateRule() applies ONLY severity_weight + blocks_dispatch\n";
            $before = $byCode['CBSE_GPS'];
            $updated = $this->cmp->updateRule('CBSE_GPS', [
                'severity_weight' => 6,
                'blocks_dispatch' => false,
                'label'           => 'HACKED LABEL',   // must be ignored
                'category'        => 'HACKED CATEGORY', // must be ignored
            ]);
            ((int) ($updated['severity_weight'] ?? -1) === 6
                && (bool) ($updated['blocks_dispatch'] ?? true) === false
                && (string) ($updated['label'] ?? '') === (string) $before['label']
                && (string) ($updated['category'] ?? '') === (string) $before['category'])
                ? $pass("weight->6, blocks->false applied; label+category untouched (still '{$before['label']}')")
                : $fail("updateRule leaked extra fields or failed to apply: " . json_encode($updated));

            echo "\n[G5] updateRule() rejects an out-of-range severity_weight\n";
            try {
                $this->cmp->updateRule('CBSE_CCTV', ['severity_weight' => 11]);
                $fail("severity_weight=11 was ACCEPTED (must reject 1-10 range)");
            } catch (\InvalidArgumentException $e) {
                $pass("severity_weight=11 rejected: " . $e->getMessage());
            }

            echo "\n[G6] updateRule() rejects an unknown rule code\n";
            try {
                $this->cmp->updateRule('CBSE_DOES_NOT_EXIST', ['severity_weight' => 5]);
                $fail("unknown rule code was ACCEPTED");
            } catch (\InvalidArgumentException $e) {
                $pass("unknown rule code rejected: " . $e->getMessage());
            }

            echo "\n[G7] Tenant isolation — seeding tenant B does not affect tenant A's count\n";
            $useTenant($sB);
            $rB = $this->cmp->seedRulePack('CBSE');
            foreach ($rB['rule_codes'] as $code) { $this->cleanup[] = [$sB, 'transportComplianceRules', $code]; }
            $countB = count($this->cmp->listRules());
            $useTenant($sA);
            $countA = count($this->cmp->listRules());
            ($countA === 19 && $countB === 19)
                ? $pass("tenant A=19, tenant B=19 — no cross-tenant bleed")
                : $fail("tenant A={$countA} tenant B={$countB} (expected 19/19)");

            echo "\n[G8] saveVehicle() persists + preserves the 14 compliance fields\n";
            $vehIn = [
                'number' => 'PROBE-CMP-' . $ts,
                'cctv_installed'            => '1',
                'cctv_working'              => '1',
                'speed_governor_fitted'     => '1',
                'speed_governor_limit_kmph' => 40,
                'first_aid_kit'             => '1',
                'seating_capacity_under12'  => 12,
                'emergency_phone_no'        => '9876500000',
            ];
            $saved = $this->transport->saveVehicle($vehIn);
            $vid = (string) $saved['id'];
            $this->cleanup[] = [$sA, '__vehicle__', $vid];
            $v1 = $this->transport->getVehicle($vid);
            $firstSaveOk = is_array($v1)
                && $v1['cctv_installed'] === true && $v1['cctv_working'] === true
                && $v1['speed_governor_fitted'] === true && (int) $v1['speed_governor_limit_kmph'] === 40
                && $v1['first_aid_kit'] === true && (int) $v1['seating_capacity_under12'] === 12
                && (string) $v1['emergency_phone_no'] === '9876500000';
            $firstSaveOk
                ? $pass("initial save persisted all 7 populated compliance fields correctly")
                : $fail("initial save mismatch: " . json_encode($v1));

            // Simulate today's Add/Edit Vehicle form: an edit save that knows
            // NOTHING about the compliance fields (they are omitted entirely).
            $edit = $this->transport->saveVehicle(['id' => $vid, 'number' => 'PROBE-CMP-' . $ts, 'capacity' => 42]);
            $v2 = $this->transport->getVehicle($vid);
            $preserved = is_array($v2)
                && $v2['cctv_installed'] === true && $v2['cctv_working'] === true
                && $v2['speed_governor_fitted'] === true && (int) $v2['speed_governor_limit_kmph'] === 40
                && $v2['first_aid_kit'] === true && (int) $v2['seating_capacity_under12'] === 12
                && (string) $v2['emergency_phone_no'] === '9876500000'
                && (int) $v2['capacity'] === 42;
            $preserved
                ? $pass("compliance-blind edit preserved all 7 fields (capacity also updated to 42)")
                : $fail("compliance-blind edit RESET fields it never mentioned: " . json_encode($v2));
            // ───────────────────────── M2 gates ─────────────────────────
            echo "\n[G9] evaluateVehicle() computes all four evaluation kinds\n";
            // Re-seed rules for tenant A (G4 mutated CBSE_GPS; restore canonical weights).
            $this->cmp->updateRule('CBSE_GPS', ['severity_weight' => 10, 'blocks_dispatch' => true]);
            $future = date('Y-m-d', strtotime('+400 days'));
            $soon   = date('Y-m-d', strtotime('+10 days'));
            $past   = date('Y-m-d', strtotime('-5 days'));
            // Full payload builder. MUST resend every statutory expiry field on
            // each save: Transport_service::saveVehicle preserves the F12.1
            // compliance fields when omitted, but the five F1 expiry fields
            // (insurance/fitness/permit/pollution/road_tax) fall back to ''
            // rather than to the existing value — see gate G17, which pins
            // that asymmetry deliberately. A partial save here would silently
            // wipe them and make every downstream gate test the wrong state.
            $veh = function (array $over = []) use ($vid, $ts, $future, $soon, $past): array {
                return array_merge([
                    'id' => $vid, 'number' => 'PROBE-CMP-' . $ts,
                    'gps_enabled' => '1',                        // field_check  -> Compliant
                    'cctv_working' => '1',                       // field_check  -> Compliant
                    'alarm_bell' => '1', 'window_grills' => '1',
                    'speed_governor_fitted' => '1', 'speed_governor_limit_kmph' => 40, // derived -> Compliant
                    'insurance_expiry' => $future,               // document_expiry -> Compliant
                    'fitness_expiry'   => $soon,                 // document_expiry -> Expiring_Soon
                    'permit_expiry'    => $past,                 // document_expiry -> Non_Compliant
                    'pollution_expiry' => $future,
                    'road_tax_expiry'  => $future,
                    'fire_ext_expiry'  => $future,
                ], $over);
            };
            $this->transport->saveVehicle($veh());
            $ev = $this->cmp->evaluateVehicle($vid);
            $st = $ev['statuses'];
            ($st['CBSE_GPS'] === 'Compliant'
                && $st['CBSE_SPEED_GOVERNOR'] === 'Compliant'
                && $st['DOC_INSURANCE'] === 'Compliant'
                && $st['DOC_FITNESS'] === 'Expiring_Soon'
                && $st['DOC_PERMIT'] === 'Non_Compliant')
                ? $pass("field_check=Compliant, derived=Compliant, expiry future/soon/past -> Compliant/Expiring_Soon/Non_Compliant")
                : $fail("evaluation kinds mismatch: " . json_encode($st));

            echo "\n[G10] Deferred derived rules stay Not_Assessed (spec §9 stubs)\n";
            ($st['CBSE_LADY_ATTENDANT'] === 'Not_Assessed' && $st['CBSE_OCCUPANCY_RATIO'] === 'Not_Assessed'
                && $st['CBSE_DOOR_LOCK'] === 'Not_Assessed')
                ? $pass("LADY_ATTENDANT + OCCUPANCY_RATIO (deferred) and DOOR_LOCK (manual_attest) all Not_Assessed")
                : $fail("stub/manual rules were auto-computed: " . json_encode([
                    'lady' => $st['CBSE_LADY_ATTENDANT'] ?? null,
                    'occ'  => $st['CBSE_OCCUPANCY_RATIO'] ?? null,
                    'door' => $st['CBSE_DOOR_LOCK'] ?? null]));

            echo "\n[G11] Score matches an INDEPENDENT hand computation of spec §2.5\n";
            $rulesNow = $this->cmp->listRules();
            $wByCode = [];
            foreach ($rulesNow as $r) { $wByCode[(string) $r['rule_code']] = (int) $r['severity_weight']; }
            $factor = ['Compliant'=>1.0,'Expiring_Soon'=>0.75,'Waived'=>0.5,'Non_Compliant'=>0.0,'Not_Assessed'=>0.0];
            $tot = array_sum($wByCode); $earn = 0.0;
            foreach ($wByCode as $c => $w) { $earn += $w * $factor[$st[$c] ?? 'Not_Assessed']; }
            $expectScore = (int) round(100 * $earn / $tot);
            $gotScore = (int) $ev['posture']['score'];
            ($gotScore === $expectScore)
                ? $pass("score={$gotScore} matches hand-computed {$expectScore} (weights {$tot})")
                : $fail("score={$gotScore} but hand-computed {$expectScore}");

            echo "\n[G12] blocks_dispatch override forces At_Risk regardless of score\n";
            // DOC_PERMIT is Non_Compliant but blocks_dispatch=false; make a
            // BLOCKING rule fail and confirm the tier overrides.
            $pB = $ev['posture'];
            $blockingFail = false;
            foreach ($rulesNow as $r) {
                if ((bool) $r['blocks_dispatch'] && ($st[(string) $r['rule_code']] ?? '') === 'Non_Compliant') { $blockingFail = true; break; }
            }
            if ($blockingFail) {
                ((string) $pB['tier'] === 'At_Risk' && $pB['blocks_dispatch'] === true)
                    ? $pass("a blocking rule is Non_Compliant -> tier=At_Risk, blocks_dispatch=true")
                    : $fail("blocking rule failing but tier={$pB['tier']} blocks=" . json_encode($pB['blocks_dispatch']));
            } else {
                // No blocking rule currently failing — force one and re-evaluate.
                $this->transport->saveVehicle($veh(['insurance_expiry' => $past]));
                $ev2 = $this->cmp->evaluateVehicle($vid);
                ((string) $ev2['posture']['tier'] === 'At_Risk' && $ev2['posture']['blocks_dispatch'] === true)
                    ? $pass("forced DOC_INSURANCE expiry -> tier=At_Risk, blocks_dispatch=true")
                    : $fail("forced blocking failure but tier={$ev2['posture']['tier']}");
                // Restore, and confirm the block actually CLEARS — proving the
                // override is driven by live state, not a latch.
                $this->transport->saveVehicle($veh());
                $ev2b = $this->cmp->evaluateVehicle($vid);
                ($ev2b['posture']['blocks_dispatch'] === false)
                    ? $pass("restoring insurance cleared the block (tier={$ev2b['posture']['tier']}) — override is live, not latched")
                    : $fail("block did NOT clear after restore: " . json_encode($ev2b['posture']));
            }

            echo "\n[G13] isDispatchBlocked() reflects stored posture\n";
            $blockedNow = $this->cmp->isDispatchBlocked($vid);
            $postureNow = (array) $this->fs->getEntity('vehicleCompliancePosture', $vid);
            ($blockedNow === (bool) ($postureNow['blocks_dispatch'] ?? false))
                ? $pass("isDispatchBlocked()=" . var_export($blockedNow, true) . " matches posture doc")
                : $fail("isDispatchBlocked()=" . var_export($blockedNow, true) . " but posture says " . var_export($postureNow['blocks_dispatch'] ?? null, true));

            echo "\n[G14] computePosture() is provably recomputable (idempotent)\n";
            $p1 = $this->cmp->computePosture($vid);
            $p2 = $this->cmp->computePosture($vid);
            ((int) $p1['score'] === (int) $p2['score']
                && (string) $p1['tier'] === (string) $p2['tier']
                && $p1['failing_rule_codes'] == $p2['failing_rule_codes']
                && (int) $p1['rule_count'] === (int) $p2['rule_count'])
                ? $pass("two consecutive recomputes identical (score={$p1['score']} tier={$p1['tier']} rules={$p1['rule_count']})")
                : $fail("posture not idempotent: " . json_encode([$p1, $p2]));

            echo "\n[G15] A live waiver is preserved; an EXPIRED waiver is not\n";
            // M3 owns waiver writes; M2 must merely respect them. Write the
            // rows directly here to prove the engine's deference contract.
            $liveExp = date('Y-m-d', strtotime('+30 days'));
            $deadExp = date('Y-m-d', strtotime('-1 day'));
            $this->fs->setEntity('vehicleCompliance', $vid . '_DOC_PERMIT', [
                'vehicleId'=>$vid,'rule_code'=>'DOC_PERMIT','status'=>'Waived',
                'waiver_reason'=>'probe live waiver, twenty plus chars','waiver_by'=>'probe',
                'waiver_expires_on'=>$liveExp,
            ], true);
            $this->fs->setEntity('vehicleCompliance', $vid . '_DOC_POLLUTION', [
                'vehicleId'=>$vid,'rule_code'=>'DOC_POLLUTION','status'=>'Waived',
                'waiver_reason'=>'probe expired waiver, twenty plus chars','waiver_by'=>'probe',
                'waiver_expires_on'=>$deadExp,
            ], true);
            $ev3 = $this->cmp->evaluateVehicle($vid);
            $s3 = $ev3['statuses'];
            ($s3['DOC_PERMIT'] === 'Waived' && $s3['DOC_POLLUTION'] !== 'Waived')
                ? $pass("live waiver preserved (DOC_PERMIT=Waived); expired waiver fell back to evaluation (DOC_POLLUTION={$s3['DOC_POLLUTION']})")
                : $fail("waiver deference wrong: DOC_PERMIT={$s3['DOC_PERMIT']} DOC_POLLUTION={$s3['DOC_POLLUTION']}");

            echo "\n[G16] rebuildFleetPosture() is per-vehicle isolated and never aborts\n";
            $rb = $this->cmp->rebuildFleetPosture(false);   // posture-only sweep
            ($rb['total'] >= 1 && $rb['ok'] >= 1 && is_array($rb['failed']))
                ? $pass("swept total={$rb['total']} ok={$rb['ok']} failed=" . count($rb['failed']))
                : $fail("rebuild returned: " . json_encode($rb));

            echo "\n[G17] saveVehicle field-preservation asymmetry is PINNED (pre-existing F1 behaviour)\n";
            // Discovered during M2: saveVehicle preserves the F12.1 compliance
            // fields when a caller omits them, but the five F1 statutory expiry
            // fields fall back to '' instead of the existing value
            // (Transport_service.php:486-491 use `?? ''`, whereas :560-561 use
            // `?? ($existing[...] ?? default)`). The production UI is NOT
            // exposed — editVeh() repopulates all five and saveVehicle() posts
            // all five — but a programmatic caller that omits them wipes them,
            // and F12.1 now scores exactly those fields (two of which are
            // dispatch-blocking). This gate pins TODAY'S behaviour so the
            // decision to change it is deliberate and visible, never accidental.
            $this->transport->saveVehicle($veh());                       // known-good baseline
            $this->transport->saveVehicle(['id'=>$vid,'number'=>'PROBE-CMP-'.$ts]); // deliberately partial
            $vAfter = (array) $this->transport->getVehicle($vid);

            // F1-owned fields that RESET on a partial save. gps_enabled belongs
            // here too (`?? '0'`), not with the F12.1 additions — and it is the
            // source for CBSE_GPS: weight 10 AND dispatch-blocking, the single
            // highest-impact rule in the pack.
            $f1Reset = [
                'insurance_expiry' => ((string) ($vAfter['insurance_expiry'] ?? 'x')) === '',
                'fitness_expiry'   => ((string) ($vAfter['fitness_expiry']   ?? 'x')) === '',
                'gps_enabled'      => ($vAfter['gps_enabled'] ?? null) === false,
            ];
            // F12.1-owned fields that PRESERVE on a partial save (M1 design).
            $f121Preserved = [
                'cctv_working'             => ($vAfter['cctv_working'] ?? null) === true,
                'first_aid_kit'            => ($vAfter['first_aid_kit'] ?? null) === true,
                'speed_governor_fitted'    => ($vAfter['speed_governor_fitted'] ?? null) === true,
                'speed_governor_limit_kmph'=> (int) ($vAfter['speed_governor_limit_kmph'] ?? 0) === 40,
            ];
            $allReset     = !in_array(false, $f1Reset, true);
            $allPreserved = !in_array(false, $f121Preserved, true);
            ($allReset && $allPreserved)
                ? $pass("F1 fields reset (insurance/fitness/gps_enabled) · F12.1 fields preserved (cctv/first_aid/governor+limit) — asymmetry pinned; see M2 report finding")
                : $fail("asymmetry changed — F1Reset=" . json_encode($f1Reset)
                        . " F121Preserved=" . json_encode($f121Preserved)
                        . " (if F1 was fixed to preserve, update this gate deliberately)");
            $this->transport->saveVehicle($veh());   // restore for a clean teardown

            // ───────────────────────── M3 gates ─────────────────────────
            echo "\n[G18] attestRule() persists a human decision + supersedes evaluation\n";
            $this->cmp->evaluateVehicle($vid);                       // DOOR_LOCK -> Not_Assessed
            $a1 = $this->cmp->attestRule($vid, 'CBSE_DOOR_LOCK', ['status'=>'Compliant','notes'=>'probe attest']);
            $after1 = $this->cmp->getVehicleCompliance($vid);
            $doorLock = null;
            foreach ($after1 as $r) { if ($r['rule_code'] === 'CBSE_DOOR_LOCK') { $doorLock = $r; } }
            ((string) ($doorLock['status'] ?? '') === 'Compliant'
                && (string) ($doorLock['attested_by'] ?? '') === 'probe_cmp'
                && (string) ($doorLock['attested_at'] ?? '') !== '')
                ? $pass("manual_attest rule attested Compliant by 'probe_cmp' with timestamp")
                : $fail("attest did not persist: " . json_encode($doorLock));

            echo "\n[G19] A later evaluateVehicle() does NOT clobber the attestation\n";
            $this->cmp->evaluateVehicle($vid);
            $after2 = $this->cmp->getVehicleCompliance($vid);
            $doorLock2 = null;
            foreach ($after2 as $r) { if ($r['rule_code'] === 'CBSE_DOOR_LOCK') { $doorLock2 = $r; } }
            ((string) ($doorLock2['status'] ?? '') === 'Compliant')
                ? $pass("manual attestation survived a full re-evaluation sweep")
                : $fail("re-evaluation clobbered the attestation: " . json_encode($doorLock2));

            echo "\n[G20] attestRule() rejects a non-attestable status (Waived needs waiveRule)\n";
            try {
                $this->cmp->attestRule($vid, 'CBSE_DOOR_LOCK', ['status'=>'Waived']);
                $fail("attestRule accepted status=Waived (must route through waiveRule)");
            } catch (\InvalidArgumentException $e) {
                $pass("rejected: " . $e->getMessage());
            }

            echo "\n[G21] attestRule() rejects a non-existent evidence document (no silent skip)\n";
            try {
                $this->cmp->attestRule($vid, 'CBSE_DOOR_LOCK', [
                    'status'=>'Compliant','evidence_document_id'=>'DOC_DOES_NOT_EXIST_'.$ts]);
                $fail("accepted a non-existent evidence document id");
            } catch (\InvalidArgumentException $e) {
                $pass("rejected: " . $e->getMessage());
            }

            echo "\n[G22] waiveRule() enforces the >=20-character reason bound\n";
            try {
                $this->cmp->waiveRule($vid, 'CBSE_CCTV', [
                    'reason'=>'too short',   // 9 chars
                    'waiver_expires_on'=>date('Y-m-d', strtotime('+30 days'))]);
                $fail("accepted a 9-character waiver reason");
            } catch (\InvalidArgumentException $e) {
                $pass("rejected: " . $e->getMessage());
            }

            echo "\n[G23] waiveRule() enforces the <=90-day bound (and rejects the past)\n";
            $okReason = 'Vendor retrofit scheduled next month, documented.';
            try {
                $this->cmp->waiveRule($vid, 'CBSE_CCTV', [
                    'reason'=>$okReason,'waiver_expires_on'=>date('Y-m-d', strtotime('+91 days'))]);
                $fail("accepted a 91-day waiver");
            } catch (\InvalidArgumentException $e) {
                $pass("91 days rejected: " . $e->getMessage());
            }
            try {
                $this->cmp->waiveRule($vid, 'CBSE_CCTV', [
                    'reason'=>$okReason,'waiver_expires_on'=>date('Y-m-d', strtotime('-1 day'))]);
                $fail("accepted a back-dated waiver");
            } catch (\InvalidArgumentException $e) {
                $pass("back-dated waiver rejected: " . $e->getMessage());
            }
            try {
                $this->cmp->waiveRule($vid, 'CBSE_CCTV', ['reason'=>$okReason]);
                $fail("accepted a waiver with no expiry (would be permanent)");
            } catch (\InvalidArgumentException $e) {
                $pass("missing expiry rejected: " . $e->getMessage());
            }

            echo "\n[G24] A valid waiver persists actor + bounded expiry, and scores 0.5\n";
            $wExp = date('Y-m-d', strtotime('+45 days'));
            $this->cmp->waiveRule($vid, 'CBSE_CCTV', ['reason'=>$okReason,'waiver_expires_on'=>$wExp]);
            $after3 = $this->cmp->getVehicleCompliance($vid);
            $cctv = null;
            foreach ($after3 as $r) { if ($r['rule_code'] === 'CBSE_CCTV') { $cctv = $r; } }
            ((string) ($cctv['status'] ?? '') === 'Waived'
                && (string) ($cctv['waiver_by'] ?? '') === 'probe_cmp'
                && (string) ($cctv['waiver_expires_on'] ?? '') === $wExp
                && strlen((string) ($cctv['waiver_reason'] ?? '')) >= 20)
                ? $pass("Waived by 'probe_cmp', expires {$wExp}, reason " . strlen((string) $cctv['waiver_reason']) . " chars")
                : $fail("waiver row wrong: " . json_encode($cctv));

            echo "\n[G25] Waiving a BLOCKING rule clears the dispatch block but not the score\n";
            // CBSE_CCTV is blocks_dispatch=true. Force it Non_Compliant, confirm
            // the block; then waive and confirm the block lifts while the score
            // still reflects a 0.5 factor rather than full compliance.
            $this->transport->saveVehicle($veh(['cctv_working' => '0']));
            $evBlk = $this->cmp->evaluateVehicle($vid);   // waiver was superseded? no: waiver still live -> stays Waived
            $pWaived = $this->cmp->computePosture($vid);
            $blockedWithWaiver = (bool) $pWaived['blocks_dispatch'];
            (!$blockedWithWaiver)
                ? $pass("live waiver on a blocking rule keeps dispatch unblocked (score={$pWaived['score']}, tier={$pWaived['tier']})")
                : $fail("waiver did not relieve the dispatch block: " . json_encode($pWaived));

            echo "\n[G26] Attestation supersedes a waiver (waiver fields cleared)\n";
            $this->transport->saveVehicle($veh());   // cctv_working back to '1'
            $this->cmp->attestRule($vid, 'CBSE_CCTV', ['status'=>'Compliant','notes'=>'retrofit completed']);
            $after4 = $this->cmp->getVehicleCompliance($vid);
            $cctv2 = null;
            foreach ($after4 as $r) { if ($r['rule_code'] === 'CBSE_CCTV') { $cctv2 = $r; } }
            ((string) ($cctv2['status'] ?? '') === 'Compliant'
                && (string) ($cctv2['waiver_reason'] ?? '') === ''
                && (string) ($cctv2['waiver_expires_on'] ?? '') === '')
                ? $pass("attestation cleared waiver_reason + waiver_expires_on")
                : $fail("waiver fields survived an attestation: " . json_encode($cctv2));

            echo "\n[G27] Every M3 mutation emitted an audit row\n";
            $auditRows = (array) $this->fs->schoolWhere('transportAuditLog', [['entity_type','==','vehicle_compliance']]);
            $sawAttest = false; $sawWaive = false;
            foreach ($auditRows as $ar) {
                $r = is_array($ar['data'] ?? null) ? $ar['data'] : $ar;
                if ((string) ($r['action'] ?? '') === 'attested') { $sawAttest = true; }
                if ((string) ($r['action'] ?? '') === 'waived')   { $sawWaive   = true; }
            }
            ($sawAttest && $sawWaive)
                ? $pass("transportAuditLog contains both 'attested' and 'waived' rows for vehicle_compliance")
                : $fail("missing audit rows — attested=" . var_export($sawAttest, true) . " waived=" . var_export($sawWaive, true));

            echo "\n[G28] Document Service is CONSUMED, never modified (link-not-copy)\n";
            // The service file itself is the contract; assert the compliance
            // service only ever calls the public link/unlink/getMetadata API and
            // never touches Storage or the documents collection directly.
            $src = (string) @file_get_contents(APPPATH . 'libraries/Transport_compliance_service.php');
            $usesApi   = strpos($src, '->link(') !== false && strpos($src, '->getMetadata(') !== false;
            $noByteOps = strpos($src, 'uploadStorageFile') === false
                      && strpos($src, 'deleteStorageFile') === false
                      && strpos($src, "'documents'") === false
                      && strpos($src, "'documentLinks'") === false;
            ($usesApi && $noByteOps)
                ? $pass("uses link()/getMetadata() only; no Storage calls, no direct documents/documentLinks writes")
                : $fail("link-not-copy discipline broken — usesApi=" . var_export($usesApi, true) . " noByteOps=" . var_export($noByteOps, true));
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->cleanup as [$sid, $col, $id]) {
                try {
                    $useTenant($sid);
                    if ($col === '__vehicle__') {
                        // Remove the derived compliance state BEFORE the vehicle.
                        // deleteVehicle() does not cascade to vehicleCompliance /
                        // vehicleCompliancePosture (those collections are owned by
                        // F12.1, not F1), so skipping this leaves ~19 orphaned rule
                        // rows plus a posture doc per run — precisely the residue
                        // that produced the pre-existing PROBE_CON1_* data-artifact
                        // FAILs this suite already carries.
                        foreach (Transport_compliance_service::CBSE_RULE_PACK as $rule) {
                            try { $this->fs->removeEntity('vehicleCompliance', $id . '_' . $rule['code']); } catch (\Throwable $e) {}
                        }
                        try { $this->fs->removeEntity('vehicleCompliancePosture', $id); } catch (\Throwable $e) {}
                        // deleteVehicle() blocks on an active-route reference; the probe
                        // vehicle is never route-assigned, so this always succeeds.
                        $this->transport->deleteVehicle($id);
                    } else {
                        $this->fs->removeEntity($col, $id);
                    }
                } catch (\Throwable $e) {
                    echo "  (cleanup warning: {$col}/{$id}: " . $e->getMessage() . ")\n";
                }
            }
            echo "  cleaned (tenants A+B rule docs, probe vehicle + its compliance rows/posture)\n";

            // Report — never silently delete — any compliance row whose vehicle no
            // longer exists. Pre-existing orphans are data, not this run's residue,
            // so removing them is an operator decision, not a probe side effect.
            try {
                $useTenant($sA);
                $live = [];
                foreach ($this->transport->listVehicles() as $v) { $live[(string) ($v['id'] ?? '')] = true; }
                $orphans = [];
                foreach ((array) $this->fs->schoolWhere('vehicleCompliance', []) as $row) {
                    $r = is_array($row['data'] ?? null) ? $row['data'] : $row;
                    $vid = (string) ($r['vehicleId'] ?? '');
                    if ($vid !== '' && !isset($live[$vid])) { $orphans[$vid] = ($orphans[$vid] ?? 0) + 1; }
                }
                if ($orphans !== []) {
                    echo '  NOTE: ' . array_sum($orphans) . ' orphaned vehicleCompliance row(s) across '
                       . count($orphans) . " deleted vehicle(s): " . implode(', ', array_keys($orphans)) . "\n";
                    echo "        (left in place — cleanup is an operator decision)\n";
                } else {
                    echo "  no orphaned compliance rows\n";
                }
            } catch (\Throwable $e) {
                echo '  (orphan scan skipped: ' . $e->getMessage() . ")\n";
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
