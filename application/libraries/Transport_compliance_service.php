<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_compliance_service — F12.1 (CBSE Transport Safety Compliance).
 *
 * ─── SCOPE OF THIS FILE (M1 + M2 landed) ─────────────────────────────────
 * Per TMS_F12_1_COMPLIANCE_ARCHITECTURE_SPEC.md §10:
 *   M1 (done) — rule-pack seeding + listing + admin-tunable overrides.
 *   M2 (done) — evaluation engine: evaluateVehicle / computePosture /
 *               rebuildFleetPosture / isDispatchBlocked (read-only) +
 *               getVehicleCompliance.
 * NOT yet implemented, strictly in milestone order, never merged forward:
 *   M3 — attestation + waivers (attestRule / waiveRule). The engine already
 *        DEFERS to those writes (live waivers and manual_attest rules are
 *        preserved, never auto-overwritten) so M3 slots in without reworking
 *        anything here.
 *   M4 — controller + UI.   M5 — verifier gates + cron.
 *
 * ─── COLLECTIONS OWNED ────────────────────────────────────────────────────
 *   transportComplianceRules   — the seeded/overridable rule pack
 *   vehicleCompliance          — per-vehicle per-rule state (M2/M3 writers)
 *   vehicleCompliancePosture   — derived per-vehicle rollup (M2 writer)
 * All three are server-only writes (firestore.rules: allow write: if false).
 *
 * ─── DOC-ID CONVENTION (RC-3 canonical, single {schoolId} prefix) ────────
 * Every Firestore_service::setEntity/getEntity/createEntity call below is
 * given a BARE entity id; those methods internally prepend
 * "{schoolId}_" via Firestore_service::docId(). Compound keys (vehicle +
 * rule) are built as bare "{vehicleId}_{ruleCode}" strings BEFORE being
 * passed in — never via docId2(), which would double-prefix.
 *
 * ─── AUDIT SEAM ───────────────────────────────────────────────────────────
 * Transport_service::emitAudit() is the platform's single audit seam, but
 * it is an instance method on Transport_service and this spec's init()
 * signature (§3) takes only ($fs, $schoolId, $adminId) — no Transport_service
 * reference. _emitAudit() below writes into the SAME collection
 * (Transport_service::COL_TRANSPORT_AUDIT_LOG) with the SAME row shape, so
 * every Transport audit consumer (dashboards, exports) sees one consistent
 * trail regardless of which class wrote the row.
 *
 * ─── AUTHORIZATION ────────────────────────────────────────────────────────
 * This service performs NO authorization of its own (spec §3 amendment,
 * 2026-08-07): it never reads session roles, never calls has_permission().
 * All access control lives at the M4 controller boundary via the canonical
 * graded RBAC engine (Transport module, view/edit/manage).
 */
class Transport_compliance_service
{
    // ─── Collection names (single source of truth for this service) ──────
    public const COL_COMPLIANCE_RULES         = 'transportComplianceRules';
    public const COL_VEHICLE_COMPLIANCE       = 'vehicleCompliance';
    public const COL_VEHICLE_COMPLIANCE_POSTURE = 'vehicleCompliancePosture';

    /** Rule pack identifiers this service knows how to seed. F12.1 ships CBSE only. */
    public const PACKS = ['CBSE'];

    /** Evaluation kinds (M2 consumes these; M1 only stores the tag). */
    public const EVALUATIONS = ['manual_attest', 'document_expiry', 'field_check', 'derived'];

    /**
     * The CBSE rule pack — spec §2.4, 19 rules.
     *
     * NOTE (found during M1 implementation, 2026-08-07): the spec prose at
     * §2.4 states "Total weight 154"; the sum of the 19 individual weights
     * exactly as tabulated is 144. This constant encodes the individual
     * per-rule weights AS TABULATED (the authoritative row-by-row data);
     * the "154" prose figure is flagged in the M1 report as a one-line spec
     * correction for operator approval. CH-TRN-CMP-1 (M5) must compute the
     * expected total FROM this constant rather than hardcode either number,
     * so the gate can never go permanently red the way CH-TRN-SEC1-1 briefly
     * did after the RBAC claim migration.
     *
     * source_field is populated for field_check/document_expiry rules (the
     * `vehicles` field M2's evaluator reads); empty for manual_attest/derived
     * rules, which read no single field.
     */
    public const CBSE_RULE_PACK = [
        ['code' => 'CBSE_GPS',              'label' => 'GPS fitted and working',            'category' => 'Equipment',   'severity_weight' => 10, 'evaluation' => 'field_check',     'source_field' => 'gps_enabled',            'blocks_dispatch' => true],
        ['code' => 'CBSE_CCTV',             'label' => 'CCTV fitted and working',           'category' => 'Equipment',   'severity_weight' => 10, 'evaluation' => 'field_check',     'source_field' => 'cctv_working',           'blocks_dispatch' => true],
        ['code' => 'CBSE_SPEED_GOVERNOR',   'label' => 'Speed governor capped at 40 km/h',  'category' => 'Equipment',   'severity_weight' => 10, 'evaluation' => 'derived',         'source_field' => '',                       'blocks_dispatch' => true],
        ['code' => 'CBSE_LADY_ATTENDANT',   'label' => 'Trained lady attendant on board',   'category' => 'Personnel',   'severity_weight' => 9,  'evaluation' => 'derived',         'source_field' => '',                       'blocks_dispatch' => true],
        ['code' => 'CBSE_FIRST_AID',        'label' => 'First-aid kit present and in date', 'category' => 'Equipment',   'severity_weight' => 7,  'evaluation' => 'field_check',     'source_field' => 'first_aid_kit',          'blocks_dispatch' => false],
        ['code' => 'CBSE_FIRE_EXT',         'label' => 'Fire extinguisher serviced',        'category' => 'Equipment',   'severity_weight' => 7,  'evaluation' => 'document_expiry', 'source_field' => 'fire_ext_expiry',        'blocks_dispatch' => false],
        ['code' => 'CBSE_ALARM_BELL',       'label' => 'Alarm bell / siren operational',    'category' => 'Equipment',   'severity_weight' => 5,  'evaluation' => 'field_check',     'source_field' => 'alarm_bell',             'blocks_dispatch' => false],
        ['code' => 'CBSE_WINDOW_GRILL',     'label' => 'Horizontal window grills with mesh','category' => 'Equipment',   'severity_weight' => 5,  'evaluation' => 'field_check',     'source_field' => 'window_grills',          'blocks_dispatch' => false],
        ['code' => 'CBSE_DOOR_LOCK',        'label' => 'Reliable door locks, shut in motion','category' => 'Equipment',  'severity_weight' => 6,  'evaluation' => 'manual_attest',   'source_field' => '',                       'blocks_dispatch' => false],
        ['code' => 'CBSE_EMERGENCY_PHONE',  'label' => 'Emergency mobile phone in vehicle', 'category' => 'Equipment',   'severity_weight' => 5,  'evaluation' => 'field_check',     'source_field' => 'emergency_phone_no',     'blocks_dispatch' => false],
        ['code' => 'CBSE_OCCUPANCY_RATIO',  'label' => 'Under-12 occupancy within 1.5×',    'category' => 'Operational', 'severity_weight' => 8,  'evaluation' => 'derived',         'source_field' => '',                       'blocks_dispatch' => false],
        ['code' => 'DOC_FITNESS',           'label' => 'Fitness certificate valid',         'category' => 'Document',    'severity_weight' => 9,  'evaluation' => 'document_expiry', 'source_field' => 'fitness_expiry',         'blocks_dispatch' => true],
        ['code' => 'DOC_INSURANCE',         'label' => 'Insurance valid',                   'category' => 'Document',    'severity_weight' => 9,  'evaluation' => 'document_expiry', 'source_field' => 'insurance_expiry',       'blocks_dispatch' => true],
        ['code' => 'DOC_PERMIT',            'label' => 'Route permit valid',                'category' => 'Document',    'severity_weight' => 8,  'evaluation' => 'document_expiry', 'source_field' => 'permit_expiry',          'blocks_dispatch' => false],
        ['code' => 'DOC_POLLUTION',         'label' => 'PUC certificate valid',             'category' => 'Document',    'severity_weight' => 6,  'evaluation' => 'document_expiry', 'source_field' => 'pollution_expiry',       'blocks_dispatch' => false],
        ['code' => 'DOC_ROAD_TAX',          'label' => 'Road tax paid',                     'category' => 'Document',    'severity_weight' => 5,  'evaluation' => 'document_expiry', 'source_field' => 'road_tax_expiry',        'blocks_dispatch' => false],
        ['code' => 'DRV_LICENCE',           'label' => 'Driver licence valid',              'category' => 'Personnel',   'severity_weight' => 10, 'evaluation' => 'derived',         'source_field' => '',                       'blocks_dispatch' => true],
        ['code' => 'DRV_BADGE',             'label' => 'Driver PSV badge valid',            'category' => 'Personnel',   'severity_weight' => 8,  'evaluation' => 'derived',         'source_field' => '',                       'blocks_dispatch' => true],
        ['code' => 'DRV_MEDICAL',           'label' => 'Driver medical certificate valid',  'category' => 'Personnel',   'severity_weight' => 7,  'evaluation' => 'derived',         'source_field' => '',                       'blocks_dispatch' => false],
    ];

    /** @var object|null Firestore_service */
    private $fs = null;

    /** @var string */
    private $schoolId = '';

    /** @var string */
    private $adminId = 'system';

    /** @var bool */
    private $ready = false;

    /**
     * Bind tenant context. Must be called before any method below.
     *
     * @param object $fs       Firestore_service instance, already init'd on the same tenant.
     * @param string $schoolId Tenant identifier (SCH_XXXXXX).
     * @param string $adminId  Actor identifier for audit rows (falls back to 'system').
     */
    public function init($fs, string $schoolId, string $adminId = 'system'): self
    {
        if ($schoolId === '') {
            throw new \LogicException('Transport_compliance_service::init requires a non-empty schoolId');
        }
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->adminId  = $adminId !== '' ? $adminId : 'system';
        $this->ready    = true;
        return $this;
    }

    private function _assertReady(): void
    {
        if (!$this->ready) {
            throw new \LogicException('Transport_compliance_service used before init().');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  RULE PACK — seed / list / update
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Seed a rule pack for this tenant. Idempotent: a rule already present
     * (identified by its bare `code`) is left completely untouched — this
     * is what makes `severity_weight` / `blocks_dispatch` school-overridable
     * (§2.1): re-seeding, or seeding again after an operator override, never
     * clobbers the override. Only missing rules are created.
     *
     * @return array{pack:string, seeded:int, already_present:int, rule_codes:array<int,string>}
     */
    public function seedRulePack(string $pack = 'CBSE'): array
    {
        $this->_assertReady();
        if (!in_array($pack, self::PACKS, true)) {
            throw new \InvalidArgumentException('Unknown compliance pack: ' . $pack);
        }

        $definitions = $this->_packDefinitions($pack);
        $seeded = 0;
        $already = 0;
        $now = date('c');

        foreach ($definitions as $rule) {
            $code = (string) $rule['code'];
            // createEntity is a create-if-not-exists CAS (409 on already-exists) —
            // exactly the idempotency + no-clobber guarantee this method needs,
            // reusing the same primitive the platform already trusts elsewhere
            // (nextSchoolCounter, Fee_firestore_txn claim docs).
            $created = $this->fs->createEntity(self::COL_COMPLIANCE_RULES, $code, [
                'rule_code'       => $code,
                'label'           => (string) $rule['label'],
                'category'        => (string) $rule['category'],
                'pack'            => $pack,
                'severity_weight' => (int) $rule['severity_weight'],
                'evaluation'      => (string) $rule['evaluation'],
                'source_field'    => (string) $rule['source_field'],
                'blocks_dispatch' => (bool) $rule['blocks_dispatch'],
                'active'          => true,
                'created_at'      => $now,
            ]);
            if ($created) {
                $seeded++;
            } else {
                $already++;
            }
        }

        $this->_emitAudit('compliance_pack', $pack, 'seeded', null,
            ['pack' => $pack, 'seeded' => $seeded, 'already_present' => $already],
            ['domain' => 'compliance']);

        return [
            'pack'            => $pack,
            'seeded'          => $seeded,
            'already_present' => $already,
            'rule_codes'      => array_column($definitions, 'code'),
        ];
    }

    /**
     * List every compliance rule seeded for this tenant.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listRules(): array
    {
        $this->_assertReady();
        $rows = (array) $this->fs->schoolWhere(self::COL_COMPLIANCE_RULES, [], 'rule_code', 'ASC');
        $out = [];
        foreach ($rows as $row) {
            $r = $this->_unwrapNestedRow($row);
            if (empty($r) || empty($r['rule_code'])) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Update the two operator-tunable fields on a seeded rule.
     * Every OTHER field (label, category, evaluation, source_field, pack)
     * is immutable via this method — spec §3: "severity_weight +
     * blocks_dispatch only". Any other key in $input is silently ignored,
     * never merged, so a caller cannot widen this into a general-purpose
     * rule editor by accident.
     *
     * @return array<string,mixed> the updated rule row
     */
    public function updateRule(string $ruleCode, array $input): array
    {
        $this->_assertReady();
        $ruleCode = trim($ruleCode);
        if ($ruleCode === '') {
            throw new \InvalidArgumentException('ruleCode is required.');
        }
        $existing = $this->fs->getEntity(self::COL_COMPLIANCE_RULES, $ruleCode);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Unknown compliance rule: ' . $ruleCode);
        }

        $patch = [];
        if (array_key_exists('severity_weight', $input)) {
            $w = (int) $input['severity_weight'];
            if ($w < 1 || $w > 10) {
                throw new \InvalidArgumentException('severity_weight must be between 1 and 10.');
            }
            $patch['severity_weight'] = $w;
        }
        if (array_key_exists('blocks_dispatch', $input)) {
            $patch['blocks_dispatch'] = (bool) $input['blocks_dispatch'];
        }
        if (empty($patch)) {
            return $this->_unwrapNestedRow($existing) ?: $existing;
        }

        $this->fs->setEntity(self::COL_COMPLIANCE_RULES, $ruleCode, $patch, /* merge */ true);

        $before = ['severity_weight' => $existing['severity_weight'] ?? null, 'blocks_dispatch' => $existing['blocks_dispatch'] ?? null];
        $this->_emitAudit('compliance_rule', $ruleCode, 'updated', $before, $patch, ['domain' => 'compliance']);

        $updated = $this->fs->getEntity(self::COL_COMPLIANCE_RULES, $ruleCode);
        return is_array($updated) ? $updated : array_merge($existing, $patch);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  M2 — EVALUATION ENGINE
    // ═════════════════════════════════════════════════════════════════════

    /** Compliance statuses (spec §2.2). */
    public const STATUS_NOT_ASSESSED  = 'Not_Assessed';
    public const STATUS_COMPLIANT     = 'Compliant';
    public const STATUS_NON_COMPLIANT = 'Non_Compliant';
    public const STATUS_EXPIRING_SOON = 'Expiring_Soon';
    public const STATUS_WAIVED        = 'Waived';

    /** Posture tiers (spec §2.5). */
    public const TIER_COMPLIANT  = 'Compliant';
    public const TIER_MINOR_GAPS = 'Minor_Gaps';
    public const TIER_MAJOR_GAPS = 'Major_Gaps';
    public const TIER_AT_RISK    = 'At_Risk';

    /** Window (days) within which a dated requirement reads Expiring_Soon. */
    public const EXPIRY_WARN_DAYS = 30;

    /**
     * Rules whose `derived` evaluators are DEFERRED pending operator
     * decisions 3 and 4 (spec §9). Until those are answered these two behave
     * exactly like `manual_attest`: the engine never auto-computes them, so a
     * human attestation from M3 is the only thing that can move them off
     * Not_Assessed. Spec §10-M2 mandates precisely this stubbing; removing a
     * code from this list is what "implements" it in a later milestone.
     */
    public const DEFERRED_DERIVED_RULES = ['CBSE_LADY_ATTENDANT', 'CBSE_OCCUPANCY_RATIO'];

    /** @var array<int,array<string,mixed>>|null per-instance rule memo (rebuild N+1 guard) */
    private $rulesMemo = null;

    /**
     * Evaluate every active rule for one vehicle and persist the results,
     * then recompute that vehicle's posture.
     *
     * ─── WHAT THIS METHOD WILL NOT OVERWRITE ─────────────────────────────
     * The engine is deliberately subordinate to human decisions:
     *   • a live `Waived` row (waiver_expires_on today or later) is preserved
     *     verbatim — an automated sweep must never silently revoke a waiver
     *     a principal granted. An EXPIRED waiver is not preserved; the rule
     *     falls back to normal evaluation, which is the whole point of
     *     bounding waivers at 90 days.
     *   • `manual_attest` rules (and the two deferred `derived` rules above)
     *     are never auto-computed — M3's attestation is their only writer.
     * Everything else (`field_check`, `document_expiry`, live `derived`) is
     * recomputed from source data on every call.
     *
     * @return array{vehicleId:string, evaluated:int, preserved:int, statuses:array<string,string>, posture:array<string,mixed>}
     */
    public function evaluateVehicle(string $vehicleId): array
    {
        $this->_assertReady();
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw new \InvalidArgumentException('vehicleId is required.');
        }
        $vehicle = $this->fs->getEntity(Transport_service::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }

        $rules = $this->_rules();
        $today = date('Y-m-d');

        // Resolve the driver ONCE for all DRV_* rules (no per-rule staff read).
        //
        // NOTE: this uses the vehicle's PERMANENTLY ASSIGNED driver
        // (`vehicles.staff_id`), not Transport_service::getEffectiveDriver()'s
        // temp-assignment overlay. Two reasons: (1) spec §3 fixes this
        // service's init() signature to ($fs, $schoolId, $adminId) with no
        // Transport_service handle, and Transport_service's own architecture
        // rule forbids direct cross-domain method calls; (2) a compliance
        // posture certifies the vehicle's standing crew for an inspection —
        // binding it to whoever happens to be covering today would make the
        // posture flap as temporary assignments come and go.
        $driverStaffId = trim((string) ($vehicle['staff_id'] ?? $vehicle['driver_staff_id'] ?? ''));
        $driverStaff = $driverStaffId !== '' ? $this->fs->getEntity('staff', $driverStaffId) : null;
        if (!is_array($driverStaff)) { $driverStaff = null; }

        $evaluated = 0;
        $preserved = 0;
        $statuses  = [];

        foreach ($rules as $rule) {
            $code = (string) ($rule['rule_code'] ?? '');
            if ($code === '' || !(bool) ($rule['active'] ?? true)) {
                continue;
            }
            $rowId    = $vehicleId . '_' . $code;
            $existing = $this->fs->getEntity(self::COL_VEHICLE_COMPLIANCE, $rowId);
            $existing = is_array($existing) ? $existing : null;

            // 1. Live waiver wins over anything the engine would compute.
            if ($existing !== null && (string) ($existing['status'] ?? '') === self::STATUS_WAIVED
                && $this->_waiverIsLive($existing, $today)) {
                $statuses[$code] = self::STATUS_WAIVED;
                $preserved++;
                continue;
            }

            // 2. Human-owned rules are never auto-computed.
            $evaluation = (string) ($rule['evaluation'] ?? '');
            if ($evaluation === 'manual_attest' || in_array($code, self::DEFERRED_DERIVED_RULES, true)) {
                $statuses[$code] = $existing !== null
                    ? (string) ($existing['status'] ?? self::STATUS_NOT_ASSESSED)
                    : self::STATUS_NOT_ASSESSED;
                // Materialise a Not_Assessed row the first time so the
                // dashboard shows the full 19-rule checklist rather than
                // silently omitting never-touched rules.
                if ($existing === null) {
                    $this->fs->setEntity(self::COL_VEHICLE_COMPLIANCE, $rowId, [
                        'vehicleId'         => $vehicleId,
                        'rule_code'         => $code,
                        'status'            => self::STATUS_NOT_ASSESSED,
                        'expires_on'        => '',
                        'last_evaluated_at' => date('c'),
                        'notes'             => $evaluation === 'manual_attest'
                            ? 'Awaiting manual attestation.'
                            : 'Automated evaluator deferred (spec §9 open decision).',
                    ], /* merge */ true);
                }
                $preserved++;
                continue;
            }

            // 3. Auto-evaluate.
            [$status, $expiresOn, $note] = $this->_evaluateRule($rule, $vehicle, $driverStaff, $today);
            $this->fs->setEntity(self::COL_VEHICLE_COMPLIANCE, $rowId, [
                'vehicleId'         => $vehicleId,
                'rule_code'         => $code,
                'status'            => $status,
                'expires_on'        => $expiresOn,
                'last_evaluated_at' => date('c'),
                'notes'             => $note,
            ], /* merge */ true);
            $statuses[$code] = $status;
            $evaluated++;
        }

        $posture = $this->computePosture($vehicleId);

        $this->_emitAudit('vehicle_compliance', $vehicleId, 'evaluated', null, [
            'evaluated' => $evaluated,
            'preserved' => $preserved,
            'score'     => $posture['score'] ?? null,
            'tier'      => $posture['tier'] ?? null,
        ], ['domain' => 'compliance']);

        return [
            'vehicleId' => $vehicleId,
            'evaluated' => $evaluated,
            'preserved' => $preserved,
            'statuses'  => $statuses,
            'posture'   => $posture,
        ];
    }

    /**
     * Recompute and persist one vehicle's derived posture (spec §2.5).
     * Pure function of vehicleCompliance + the rule weights — always
     * rebuildable, never authoritative.
     *
     * @return array<string,mixed> the posture row as written
     */
    public function computePosture(string $vehicleId): array
    {
        $this->_assertReady();
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw new \InvalidArgumentException('vehicleId is required.');
        }

        $rules = $this->_rules();
        $rows  = (array) $this->fs->schoolWhere(
            self::COL_VEHICLE_COMPLIANCE,
            [['vehicleId', '==', $vehicleId]]
        );
        $byCode = [];
        foreach ($rows as $row) {
            $r = $this->_unwrapNestedRow($row, 'rule_code');
            $c = (string) ($r['rule_code'] ?? '');
            if ($c !== '') { $byCode[$c] = $r; }
        }

        $totalWeight = 0;
        $earned      = 0.0;
        $failing     = [];
        $expiring    = [];
        $blocked     = false;
        $ruleCount   = 0;
        $today       = date('Y-m-d');

        foreach ($rules as $rule) {
            $code = (string) ($rule['rule_code'] ?? '');
            if ($code === '' || !(bool) ($rule['active'] ?? true)) {
                continue;
            }
            $ruleCount++;
            $w = (int) ($rule['severity_weight'] ?? 0);
            $totalWeight += $w;

            $status = (string) ($byCode[$code]['status'] ?? self::STATUS_NOT_ASSESSED);
            $earned += $w * $this->_statusFactor($status);

            if ($status === self::STATUS_NON_COMPLIANT || $status === self::STATUS_NOT_ASSESSED) {
                $failing[] = $code;
                // blocks_dispatch override is scoped to Non_Compliant ONLY
                // (spec §2.5) — a rule nobody has assessed yet must not hard-
                // block a fleet that has simply never run an evaluation.
                if ($status === self::STATUS_NON_COMPLIANT && (bool) ($rule['blocks_dispatch'] ?? false)) {
                    $blocked = true;
                }
            }
            if ($status === self::STATUS_EXPIRING_SOON) {
                $expiring[] = $code;
            }
        }

        $score = $totalWeight > 0 ? (int) round(100 * $earned / $totalWeight) : 0;
        $tier  = $this->_tierFor($score, $blocked);

        $posture = [
            'vehicleId'            => $vehicleId,
            'score'                => $score,
            'tier'                 => $tier,
            'failing_rule_codes'   => $failing,
            'expiring_within_30d'  => $expiring,
            'blocks_dispatch'      => $blocked,
            'rule_count'           => $ruleCount,
            'computed_at'          => date('c'),
        ];
        $this->fs->setEntity(self::COL_VEHICLE_COMPLIANCE_POSTURE, $vehicleId, $posture, /* merge */ true);
        return $posture;
    }

    /**
     * Recompute posture for every vehicle in the tenant.
     *
     * Per-vehicle isolated: one poisoned vehicle is recorded in `failed[]`
     * and the sweep continues — spec §10-M2 and the same discipline the
     * cross-tenant analytics rollup uses (PERF-6).
     *
     * @param bool $evaluate true → full re-evaluation per vehicle; false →
     *                       posture recompute only (cheap, no source reads).
     * @return array{total:int, ok:int, failed:array<int,array{vehicleId:string,error:string}>}
     */
    public function rebuildFleetPosture(bool $evaluate = true): array
    {
        $this->_assertReady();
        $this->rulesMemo = null; // one fresh rule read for the whole sweep

        $vehicles = (array) $this->fs->schoolWhere(
            Transport_service::COL_VEHICLES,
            [],
            null,
            'ASC',
            Transport_service::LIST_SCAN_LIMIT
        );

        $ok = 0;
        $failed = [];
        $total = 0;

        foreach ($vehicles as $doc) {
            $v = $this->_unwrapNestedRow($doc, 'schoolId');
            $vid = (string) ($v['vehicleId'] ?? $v['id'] ?? '');
            if ($vid === '') { continue; }
            $total++;
            try {
                if ($evaluate) {
                    $this->evaluateVehicle($vid);
                } else {
                    $this->computePosture($vid);
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = ['vehicleId' => $vid, 'error' => $e->getMessage()];
                log_message('error', "Transport_compliance_service::rebuildFleetPosture vehicle={$vid}: " . $e->getMessage());
            }
        }

        $this->_emitAudit('compliance_fleet', 'rebuild', 'rebuilt', null,
            ['total' => $total, 'ok' => $ok, 'failed' => count($failed)],
            ['domain' => 'compliance']);

        return ['total' => $total, 'ok' => $ok, 'failed' => $failed];
    }

    /**
     * True when the vehicle's stored posture says a dispatch-blocking rule is
     * failing. READ-ONLY reporting helper.
     *
     * NOT wired into Transport_service::createTrip at M2 — spec §9 open
     * decision 1 (warn-only vs hard-block) is still unanswered and the spec's
     * stated default is warn-only. Wiring it into the dispatch path is a
     * behaviour change that belongs to whichever milestone lands after that
     * decision, not to the evaluation engine.
     */
    public function isDispatchBlocked(string $vehicleId): bool
    {
        $this->_assertReady();
        if (trim($vehicleId) === '') { return false; }
        $p = $this->fs->getEntity(self::COL_VEHICLE_COMPLIANCE_POSTURE, trim($vehicleId));
        return is_array($p) && (bool) ($p['blocks_dispatch'] ?? false);
    }

    /**
     * Read a vehicle's full rule-by-rule compliance state (dashboard read).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getVehicleCompliance(string $vehicleId): array
    {
        $this->_assertReady();
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') { return []; }
        $rows = (array) $this->fs->schoolWhere(
            self::COL_VEHICLE_COMPLIANCE,
            [['vehicleId', '==', $vehicleId]]
        );
        $out = [];
        foreach ($rows as $row) {
            $r = $this->_unwrapNestedRow($row, 'rule_code');
            if (!empty($r['rule_code'])) { $out[] = $r; }
        }
        usort($out, static function ($a, $b) {
            return strcmp((string) $a['rule_code'], (string) $b['rule_code']);
        });
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  M3 — ATTESTATION + WAIVERS
    // ═════════════════════════════════════════════════════════════════════

    /** Statuses a human may attest directly. `Waived` has its own entry point. */
    public const ATTESTABLE_STATUSES = [self::STATUS_COMPLIANT, self::STATUS_NON_COMPLIANT];

    /** Maximum waiver duration, in days (spec §2.2 / §7). */
    public const WAIVER_MAX_DAYS = 90;

    /** Minimum waiver-reason length, in characters (spec §7). */
    public const WAIVER_REASON_MIN = 20;

    /** Module key used for Document Service links owned by compliance evidence. */
    public const DOC_LINK_MODULE = 'transport_compliance';

    /**
     * Attest a rule for a vehicle — the human counterpart to evaluateVehicle().
     *
     * ─── EVIDENCE IS LINKED, NEVER COPIED ────────────────────────────────
     * `evidence_document_id` is passed to the Canonical Document Service's
     * link() — a reference-counted attach. This service NEVER uploads, copies,
     * moves or deletes bytes, and never modifies the Document Service. When an
     * attestation REPLACES earlier evidence, the previous document is unlink()ed
     * so its refcount falls correctly and retention can proceed; the bytes are
     * left entirely to the Document Service's own lifecycle.
     *
     * @param array $input {status, evidence_document_id?, expires_on?, notes?}
     * @return array<string,mixed> the persisted compliance row
     */
    public function attestRule(string $vehicleId, string $ruleCode, array $input): array
    {
        $this->_assertReady();
        [$vehicleId, $ruleCode, $rule] = $this->_assertRuleTarget($vehicleId, $ruleCode);

        $status = trim((string) ($input['status'] ?? ''));
        if (!in_array($status, self::ATTESTABLE_STATUSES, true)) {
            throw new \InvalidArgumentException(
                'status must be one of: ' . implode(', ', self::ATTESTABLE_STATUSES) . ' (use waiveRule() to waive).'
            );
        }

        $rowId    = $vehicleId . '_' . $ruleCode;
        $existing = $this->fs->getEntity(self::COL_VEHICLE_COMPLIANCE, $rowId);
        $existing = is_array($existing) ? $existing : [];
        $priorDoc = trim((string) ($existing['evidence_document_id'] ?? ''));

        // ── evidence handling ────────────────────────────────────────────
        $newDoc = trim((string) ($input['evidence_document_id'] ?? ''));
        $expiresOn = trim((string) ($input['expires_on'] ?? ''));
        if ($newDoc !== '') {
            $meta = $this->_docService()->getMetadata($newDoc);
            if (!is_array($meta)) {
                // getMetadata() returns null for a missing doc AND for a
                // cross-tenant one (read() enforces schoolId) — either way the
                // caller may not attach it.
                throw new \InvalidArgumentException('Evidence document not found for this school: ' . $newDoc);
            }
            $res = $this->_docService()->link($newDoc, self::DOC_LINK_MODULE, $rowId, $ruleCode, $this->adminId);
            if (empty($res['ok'])) {
                throw new \RuntimeException('Could not link evidence document: ' . (string) ($res['error'] ?? 'unknown error'));
            }
            // Auto-fill the expiry from the document when the caller did not
            // supply one (spec §7.2 "auto-filled from the linked document").
            if ($expiresOn === '' && !empty($meta['expiresOn'])) {
                $expiresOn = trim((string) $meta['expiresOn']);
            }
        }
        if ($expiresOn !== '') {
            $ts = strtotime($expiresOn);
            if ($ts === false) {
                throw new \InvalidArgumentException("expires_on is not a valid date ('{$expiresOn}').");
            }
            $expiresOn = date('Y-m-d', $ts);
        }

        $now = date('c');
        $patch = [
            'vehicleId'            => $vehicleId,
            'rule_code'            => $ruleCode,
            'status'               => $status,
            'evidence_document_id' => $newDoc,
            'attested_by'          => $this->adminId,
            'attested_at'          => $now,
            'expires_on'           => $expiresOn,
            'last_evaluated_at'    => $now,
            'notes'                => trim((string) ($input['notes'] ?? '')),
            // An attestation supersedes any earlier waiver on the same rule.
            'waiver_reason'        => '',
            'waiver_by'            => '',
            'waiver_expires_on'    => '',
        ];
        $this->_casWrite($rowId, $patch, 'attestRule');

        // Detach superseded evidence AFTER the row commits, so a failed write
        // can never leave the new document unlinked while the old one is gone.
        if ($priorDoc !== '' && $priorDoc !== $newDoc) {
            $this->_docService()->unlink($priorDoc, self::DOC_LINK_MODULE, $rowId, $this->adminId);
        }

        $this->_emitAudit('vehicle_compliance', $rowId, 'attested',
            ['status' => $existing['status'] ?? null, 'evidence_document_id' => $priorDoc ?: null],
            ['status' => $status, 'evidence_document_id' => $newDoc ?: null, 'expires_on' => $expiresOn],
            ['domain' => 'compliance', 'vehicleId' => $vehicleId, 'rule_code' => $ruleCode]);

        $this->computePosture($vehicleId);
        return (array) $this->fs->getEntity(self::COL_VEHICLE_COMPLIANCE, $rowId);
    }

    /**
     * Waive a rule for a vehicle — the audited escape hatch.
     *
     * Bounds enforced here and asserted by CH-TRN-CMP-3 (M5):
     *   • reason at least WAIVER_REASON_MIN characters
     *   • expiry present, not in the past, and at most WAIVER_MAX_DAYS out
     *   • actor recorded
     * A waiver is never permanent: once `waiver_expires_on` passes,
     * evaluateVehicle() stops honouring it and the rule returns to normal
     * evaluation (proven by probe gate G15).
     *
     * @param array $input {reason, waiver_expires_on, notes?}
     * @return array<string,mixed> the persisted compliance row
     */
    public function waiveRule(string $vehicleId, string $ruleCode, array $input): array
    {
        $this->_assertReady();
        [$vehicleId, $ruleCode, $rule] = $this->_assertRuleTarget($vehicleId, $ruleCode);

        $reason = trim((string) ($input['reason'] ?? $input['waiver_reason'] ?? ''));
        // Count characters, not bytes, so a reason written in an Indic script
        // is not rejected for being "too short" when it plainly is not.
        $reasonLen = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($reasonLen < self::WAIVER_REASON_MIN) {
            throw new \InvalidArgumentException(
                'Waiver reason must be at least ' . self::WAIVER_REASON_MIN . ' characters.'
            );
        }

        $expires = trim((string) ($input['waiver_expires_on'] ?? $input['expires_on'] ?? ''));
        if ($expires === '') {
            throw new \InvalidArgumentException('waiver_expires_on is required — waivers are never permanent.');
        }
        $ts = strtotime($expires);
        if ($ts === false) {
            throw new \InvalidArgumentException("waiver_expires_on is not a valid date ('{$expires}').");
        }
        $expires = date('Y-m-d', $ts);
        $today   = date('Y-m-d');
        if ($expires < $today) {
            throw new \InvalidArgumentException('waiver_expires_on cannot be in the past.');
        }
        $maxDate = date('Y-m-d', strtotime($today . ' +' . self::WAIVER_MAX_DAYS . ' days'));
        if ($expires > $maxDate) {
            throw new \InvalidArgumentException(
                'A waiver may not exceed ' . self::WAIVER_MAX_DAYS . " days (max {$maxDate})."
            );
        }

        $rowId    = $vehicleId . '_' . $ruleCode;
        $existing = $this->fs->getEntity(self::COL_VEHICLE_COMPLIANCE, $rowId);
        $existing = is_array($existing) ? $existing : [];

        $now = date('c');
        $patch = [
            'vehicleId'         => $vehicleId,
            'rule_code'         => $ruleCode,
            'status'            => self::STATUS_WAIVED,
            'waiver_reason'     => $reason,
            'waiver_by'         => $this->adminId,
            'waiver_expires_on' => $expires,
            'attested_by'       => $this->adminId,
            'attested_at'       => $now,
            'last_evaluated_at' => $now,
            'notes'             => trim((string) ($input['notes'] ?? '')),
        ];
        $this->_casWrite($rowId, $patch, 'waiveRule');

        $this->_emitAudit('vehicle_compliance', $rowId, 'waived',
            ['status' => $existing['status'] ?? null],
            ['status' => self::STATUS_WAIVED, 'waiver_expires_on' => $expires, 'reason_length' => $reasonLen],
            ['domain' => 'compliance', 'vehicleId' => $vehicleId, 'rule_code' => $ruleCode]);

        $this->computePosture($vehicleId);
        return (array) $this->fs->getEntity(self::COL_VEHICLE_COMPLIANCE, $rowId);
    }

    // ─── M3 internals ────────────────────────────────────────────────────

    /**
     * Validate the (vehicle, rule) target once for both entry points.
     * @return array{0:string,1:string,2:array} [vehicleId, ruleCode, rule]
     */
    private function _assertRuleTarget(string $vehicleId, string $ruleCode): array
    {
        $vehicleId = trim($vehicleId);
        $ruleCode  = trim($ruleCode);
        if ($vehicleId === '') { throw new \InvalidArgumentException('vehicleId is required.'); }
        if ($ruleCode === '')  { throw new \InvalidArgumentException('ruleCode is required.'); }

        $vehicle = $this->fs->getEntity(Transport_service::COL_VEHICLES, $vehicleId);
        if (!is_array($vehicle)) {
            throw new \InvalidArgumentException('Vehicle not found: ' . $vehicleId);
        }
        $rule = $this->fs->getEntity(self::COL_COMPLIANCE_RULES, $ruleCode);
        if (!is_array($rule)) {
            throw new \InvalidArgumentException('Unknown compliance rule: ' . $ruleCode);
        }
        return [$vehicleId, $ruleCode, $rule];
    }

    /**
     * Persist a compliance-row change through the RC-1 CAS primitive
     * (spec §10-M3). Falls back to a plain merge write ONLY when the platform
     * CAS primitive is unavailable in this deployment — the same
     * availability guard casUpdateEntity itself uses — so a missing primitive
     * degrades to last-write-wins rather than silently dropping the write.
     */
    private function _casWrite(string $rowId, array $patch, string $who): void
    {
        $res = $this->fs->casUpdateEntity(
            self::COL_VEHICLE_COMPLIANCE,
            $rowId,
            static function (array $doc) use ($patch): array { return $patch; }
        );
        if (!empty($res['ok'])) {
            return;
        }
        if ((string) ($res['reason'] ?? '') === 'unavailable') {
            $this->fs->setEntity(self::COL_VEHICLE_COMPLIANCE, $rowId, $patch, /* merge */ true);
            return;
        }
        throw new \RuntimeException(
            "{$who}: compliance row is busy (write contention) — please retry."
            . ' [' . (string) ($res['reason'] ?? 'cas failed') . ']'
        );
    }

    /**
     * Canonical Document Service handle, bound to this tenant.
     *
     * Resolved lazily from CI rather than injected, because spec §3 fixes this
     * service's init() signature to ($fs, $schoolId, $adminId) with no Firebase
     * handle — and Document_service::init() requires the Firebase library, not
     * Firestore_service. CONSUMED ONLY: never modified, never bypassed.
     */
    private function _docService()
    {
        $CI =& get_instance();
        if (!isset($CI->firebase)) {
            $CI->load->library('firebase');
        }
        if (!isset($CI->document_service)) {
            $CI->load->library('document_service');
        }
        return $CI->document_service->init($CI->firebase, $this->schoolId);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  M2 — evaluation internals
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Evaluate a single auto-evaluable rule.
     *
     * @return array{0:string,1:string,2:string} [status, expires_on, note]
     */
    private function _evaluateRule(array $rule, array $vehicle, ?array $driverStaff, string $today): array
    {
        $code  = (string) ($rule['rule_code'] ?? '');
        $kind  = (string) ($rule['evaluation'] ?? '');
        $field = (string) ($rule['source_field'] ?? '');

        if ($kind === 'field_check') {
            $present = $this->_isPresent($vehicle[$field] ?? null);
            return $present
                ? [self::STATUS_COMPLIANT, '', '']
                : [self::STATUS_NON_COMPLIANT, '', "vehicles.{$field} is not set."];
        }

        if ($kind === 'document_expiry') {
            return $this->_evaluateExpiry((string) ($vehicle[$field] ?? ''), $today, "vehicles.{$field}");
        }

        // derived — the deferred pair never reaches here (filtered upstream).
        switch ($code) {
            case 'CBSE_SPEED_GOVERNOR':
                $fitted = !empty($vehicle['speed_governor_fitted']);
                $limit  = (int) ($vehicle['speed_governor_limit_kmph'] ?? 0);
                if (!$fitted) {
                    return [self::STATUS_NON_COMPLIANT, '', 'Speed governor not fitted.'];
                }
                if ($limit <= 0) {
                    return [self::STATUS_NON_COMPLIANT, '', 'Speed governor limit not recorded.'];
                }
                if ($limit > 40) {
                    return [self::STATUS_NON_COMPLIANT, '', "Governor limit {$limit} km/h exceeds the CBSE 40 km/h cap."];
                }
                return [self::STATUS_COMPLIANT, '', ''];

            case 'DRV_LICENCE':
                if ($driverStaff === null) {
                    return [self::STATUS_NOT_ASSESSED, '', 'No driver assigned to this vehicle.'];
                }
                return $this->_evaluateExpiry(
                    (string) ($driverStaff['driver_meta']['licence_expires'] ?? ''),
                    $today, 'driver_meta.licence_expires'
                );

            case 'DRV_MEDICAL':
                if ($driverStaff === null) {
                    return [self::STATUS_NOT_ASSESSED, '', 'No driver assigned to this vehicle.'];
                }
                return $this->_evaluateExpiry(
                    (string) ($driverStaff['driver_meta']['medical_expires'] ?? ''),
                    $today, 'driver_meta.medical_expires'
                );

            case 'DRV_BADGE':
                if ($driverStaff === null) {
                    return [self::STATUS_NOT_ASSESSED, '', 'No driver assigned to this vehicle.'];
                }
                // Mirrors _validateDriverReadiness: the badge check only
                // engages once a badge number exists. Without one we report
                // Not_Assessed ("we cannot tell") rather than Non_Compliant
                // ("we know it is bad") — both score 0, but the label stays
                // honest and the remediation queue reads correctly.
                $badgeNo = (string) ($driverStaff['driver_meta']['badge_no'] ?? '');
                if ($badgeNo === '') {
                    return [self::STATUS_NOT_ASSESSED, '', 'No PSV badge number recorded for the assigned driver.'];
                }
                return $this->_evaluateExpiry(
                    (string) ($driverStaff['driver_meta']['badge_expires'] ?? ''),
                    $today, 'driver_meta.badge_expires'
                );
        }

        return [self::STATUS_NOT_ASSESSED, '', 'No evaluator implemented for ' . $code . '.'];
    }

    /**
     * Shared date-validity evaluator.
     *
     * DELIBERATE DIVERGENCE FROM DISPATCH: `_validateDriverReadiness` treats an
     * EMPTY expiry as passing (fail-open — never block a trip on missing
     * data). Compliance answers a different question — "can this be proven to
     * an inspector today?" — so an empty date is Non_Compliant (fail-closed).
     * Same underlying fields, different question, intentionally different
     * answers.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function _evaluateExpiry(string $date, string $today, string $sourceLabel): array
    {
        $date = trim($date);
        if ($date === '') {
            return [self::STATUS_NON_COMPLIANT, '', "{$sourceLabel} is not recorded."];
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return [self::STATUS_NON_COMPLIANT, '', "{$sourceLabel} is not a valid date ('{$date}')."];
        }
        $normalised = date('Y-m-d', $ts);
        if ($normalised < $today) {
            return [self::STATUS_NON_COMPLIANT, $normalised, "Expired on {$normalised}."];
        }
        $warnFrom = date('Y-m-d', strtotime($today . ' +' . self::EXPIRY_WARN_DAYS . ' days'));
        if ($normalised <= $warnFrom) {
            return [self::STATUS_EXPIRING_SOON, $normalised, "Expires {$normalised} (within " . self::EXPIRY_WARN_DAYS . " days)."];
        }
        return [self::STATUS_COMPLIANT, $normalised, ''];
    }

    /** Truthiness for a field_check source value (bool / int / non-empty string). */
    private function _isPresent($value): bool
    {
        if (is_bool($value))   { return $value; }
        if (is_int($value))    { return $value > 0; }
        if (is_float($value))  { return $value > 0; }
        if (is_string($value)) { return trim($value) !== '' && $value !== '0'; }
        return !empty($value);
    }

    /** Spec §2.5 status → score factor. */
    private function _statusFactor(string $status): float
    {
        switch ($status) {
            case self::STATUS_COMPLIANT:     return 1.0;
            case self::STATUS_EXPIRING_SOON: return 0.75;
            case self::STATUS_WAIVED:        return 0.5;
            case self::STATUS_NON_COMPLIANT:
            case self::STATUS_NOT_ASSESSED:
            default:                         return 0.0;
        }
    }

    /** Spec §2.5 tiering, blocks_dispatch override first. */
    private function _tierFor(int $score, bool $blocked): string
    {
        if ($blocked)      { return self::TIER_AT_RISK; }
        if ($score >= 100) { return self::TIER_COMPLIANT; }
        if ($score >= 85)  { return self::TIER_MINOR_GAPS; }
        if ($score >= 65)  { return self::TIER_MAJOR_GAPS; }
        return self::TIER_AT_RISK;
    }

    /** A waiver counts only while it has not expired (spec §2.2 / M3 bounds). */
    private function _waiverIsLive(array $row, string $today): bool
    {
        $exp = trim((string) ($row['waiver_expires_on'] ?? ''));
        if ($exp === '') { return false; }   // unbounded waiver is not honoured
        return $exp >= $today;
    }

    /** Rule list, memoised per instance (rebuild N+1 guard). */
    private function _rules(): array
    {
        if ($this->rulesMemo === null) {
            $this->rulesMemo = $this->listRules();
        }
        return $this->rulesMemo;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    /** @return array<int,array<string,mixed>> */
    private function _packDefinitions(string $pack): array
    {
        switch ($pack) {
            case 'CBSE':
                return self::CBSE_RULE_PACK;
            default:
                return [];
        }
    }

    /**
     * Unwrap the {id, data:{...}} envelope some Firestore_service reads
     * return (schoolWhere query rows) — same convention, same signature, as
     * Transport_service::_unwrapNestedRow(), and gated platform-wide by
     * CH-TRN-ENV-1. $markerField is a field guaranteed present on an already
     * -flat row, used to detect whether unwrapping is needed at all.
     */
    private function _unwrapNestedRow($row, string $markerField = 'rule_code'): array
    {
        if (!is_array($row)) {
            return [];
        }
        if (isset($row[$markerField])) {
            return $row;
        }
        if (isset($row['data']) && is_array($row['data'])) {
            $data = $row['data'];
            if (!empty($row['id']) && !isset($data['id'])) {
                $data['id'] = $row['id'];
            }
            return $data;
        }
        return $row;
    }

    /**
     * Local audit emission — see the class docblock's "AUDIT SEAM" note for
     * why this isn't a call to Transport_service::emitAudit(). Row shape is
     * kept byte-for-byte compatible with that method's output.
     */
    private function _emitAudit(string $entityType, string $entityId, string $action, $before, $after, array $context = []): string
    {
        $ts   = microtime(true);
        $tsMs = (int) round($ts * 1000);
        $docId = sprintf('%s_TAUD_%d_%s', $this->schoolId, $tsMs, $entityId !== '' ? $entityId : 'na');

        $row = [
            'schoolId'    => $this->schoolId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'domain'      => (string) ($context['domain'] ?? 'compliance'),
            'source'      => (string) ($context['source'] ?? 'admin_panel'),
            'before'      => $this->_capJson($before, 4096),
            'after'       => $this->_capJson($after, 4096),
            'context'     => $this->_capJson($context, 2048),
            'actor_id'    => $this->adminId,
            'event_at'    => date('c'),
            'event_at_ms' => $tsMs,
        ];

        // Direct setEntity — audit log is append-only; Firestore rule for
        // transportAuditLog denies client-side writes regardless.
        $this->fs->setEntity(Transport_service::COL_TRANSPORT_AUDIT_LOG, $docId, $row, /* merge */ false);
        return $docId;
    }

    private function _capJson($value, int $maxBytes): string
    {
        $json = json_encode($value);
        if ($json === false) {
            return '""';
        }
        if (strlen($json) > $maxBytes) {
            return substr($json, 0, $maxBytes - 3) . '...';
        }
        return $json;
    }
}
