<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_compliance — F12.1 M4 (Admin surface for CBSE safety compliance).
 *
 * ─── SCOPE (spec §10-M4) ──────────────────────────────────────────────────
 * Exactly the eight endpoints of TMS_F12_1_COMPLIANCE_ARCHITECTURE_SPEC.md
 * §4.2, and nothing else. All business logic lives in
 * Transport_compliance_service (M1-M3); this class is a thin, authorized
 * transport layer.
 *
 * ─── AUTHORIZATION (spec §4) ──────────────────────────────────────────────
 * Every endpoint is gated by the canonical graded RBAC helper:
 *
 *     require_permission('Transport', '<action>', '<level>')
 *
 *   dashboard      GET       Transport · view
 *   vehicle/{id}   GET       Transport · view
 *   attest         POST      Transport · edit
 *   evaluate/{id}  POST      Transport · edit
 *   waive          POST      Transport · manage
 *   rebuild        POST      Transport · manage
 *   seed_pack      POST      Transport · manage
 *   rules          GET/POST  Transport · manage
 *
 * Deliberately NOT present, per spec §4.5:
 *   • no new RBAC module — this reuses the existing `Transport` surface
 *     (`functions/rbac_modules.json` is shared byte-for-byte with the
 *     staffCapabilities Cloud Function; adding a module there is a
 *     cross-system change, out of scope);
 *   • no role-name allow-list of any kind. The legacy fallback was retired
 *     Transport-wide on 2026-08-07: access is granted only by holding the
 *     Transport module (directly, or via its Operations umbrella parent) at
 *     the required level, or by a RBAC_BYPASS_ROLES principal;
 *   • no constructor-level `require_permission(...)` second gate — §4.2 is
 *     the single authorization boundary. Authentication itself is already
 *     enforced by MY_Controller's guard.
 */
class Transport_compliance extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
        $this->load->library('transport_compliance_service', null, 'cmp');
        $this->cmp->init($this->fs, $this->school_name, $this->admin_id ?? 'system');
    }

    // ═════════════════════════════════════════════════════════════════════
    //  READ — Transport · view
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Fleet compliance posture: one row per vehicle, plus headline counts.
     * Reads the precomputed vehicleCompliancePosture doc per vehicle — no
     * per-rule fan-out (spec §6 read budget).
     */
    public function dashboard(): void
    {
        require_permission('Transport', 'compliance_dashboard', 'view');

        $vehicles = $this->transport->listVehicles();
        $rows = [];
        $tiers = ['Compliant' => 0, 'Minor_Gaps' => 0, 'Major_Gaps' => 0, 'At_Risk' => 0];
        $unassessed = 0;

        foreach ($vehicles as $v) {
            $vid = (string) ($v['id'] ?? '');
            if ($vid === '') { continue; }
            $p = $this->fs->getEntity('vehicleCompliancePosture', $vid);
            if (!is_array($p)) {
                $unassessed++;
                $rows[] = [
                    'vehicleId' => $vid, 'number' => (string) ($v['number'] ?? ''),
                    'score' => null, 'tier' => null, 'blocks_dispatch' => false,
                    'failing_count' => 0, 'expiring_count' => 0, 'computed_at' => '',
                ];
                continue;
            }
            $tier = (string) ($p['tier'] ?? '');
            if (isset($tiers[$tier])) { $tiers[$tier]++; }
            $rows[] = [
                'vehicleId'       => $vid,
                'number'          => (string) ($v['number'] ?? ''),
                'score'           => (int) ($p['score'] ?? 0),
                'tier'            => $tier,
                'blocks_dispatch' => (bool) ($p['blocks_dispatch'] ?? false),
                'failing_count'   => count((array) ($p['failing_rule_codes'] ?? [])),
                'expiring_count'  => count((array) ($p['expiring_within_30d'] ?? [])),
                'computed_at'     => (string) ($p['computed_at'] ?? ''),
            ];
        }

        $this->json_success([
            'vehicles'    => $rows,
            'tiers'       => $tiers,
            'unassessed'  => $unassessed,
            'total'       => count($rows),
            'rule_count'  => count($this->cmp->listRules()),
        ]);
    }

    /** One vehicle's full rule-by-rule checklist + its posture. */
    public function vehicle(string $vehicleId = ''): void
    {
        require_permission('Transport', 'compliance_vehicle', 'view');
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            $this->json_error('vehicleId is required.', 400);
        }
        $v = $this->transport->getVehicle($vehicleId);
        if (!is_array($v)) {
            $this->json_error('Vehicle not found.', 404);
        }
        $this->json_success([
            'vehicle'    => ['id' => $vehicleId, 'number' => (string) ($v['number'] ?? '')],
            'rules'      => $this->cmp->listRules(),
            'compliance' => $this->cmp->getVehicleCompliance($vehicleId),
            'posture'    => $this->fs->getEntity('vehicleCompliancePosture', $vehicleId) ?: null,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  WRITE — Transport · edit
    // ═════════════════════════════════════════════════════════════════════

    /** Record a human attestation for one rule on one vehicle. */
    public function attest(): void
    {
        require_permission('Transport', 'compliance_attest', 'manage');
        try {
            $row = $this->cmp->attestRule(
                trim((string) $this->input->post('vehicle_id')),
                trim((string) $this->input->post('rule_code')),
                [
                    'status'               => trim((string) $this->input->post('status')),
                    'evidence_document_id' => trim((string) ($this->input->post('evidence_document_id') ?? '')),
                    'expires_on'           => trim((string) ($this->input->post('expires_on') ?? '')),
                    'notes'                => trim((string) ($this->input->post('notes') ?? '')),
                ]
            );
            $this->json_success(['message' => 'Attestation saved.', 'row' => $row]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_compliance::attest — ' . $e->getMessage());
            $this->json_error('Could not save the attestation. Please retry.', 500);
        }
    }

    /** Re-run the automated evaluators for one vehicle. */
    public function evaluate(string $vehicleId = ''): void
    {
        require_permission('Transport', 'compliance_evaluate', 'edit');
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            $this->json_error('vehicleId is required.', 400);
        }
        try {
            $res = $this->cmp->evaluateVehicle($vehicleId);
            $this->json_success(['message' => 'Vehicle re-evaluated.', 'result' => $res]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_compliance::evaluate — ' . $e->getMessage());
            $this->json_error('Could not evaluate this vehicle. Please retry.', 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  WRITE — Transport · manage
    // ═════════════════════════════════════════════════════════════════════

    /** Waive one rule for one vehicle (bounded, audited). */
    public function waive(): void
    {
        require_permission('Transport', 'compliance_waive', 'manage');
        try {
            $row = $this->cmp->waiveRule(
                trim((string) $this->input->post('vehicle_id')),
                trim((string) $this->input->post('rule_code')),
                [
                    'reason'            => (string) $this->input->post('reason'),
                    'waiver_expires_on' => trim((string) $this->input->post('waiver_expires_on')),
                    'notes'             => trim((string) ($this->input->post('notes') ?? '')),
                ]
            );
            $this->json_success(['message' => 'Waiver recorded.', 'row' => $row]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_compliance::waive — ' . $e->getMessage());
            $this->json_error('Could not record the waiver. Please retry.', 500);
        }
    }

    /** Re-evaluate + recompute posture across the whole fleet. */
    public function rebuild(): void
    {
        require_permission('Transport', 'compliance_rebuild', 'manage');
        try {
            $evaluate = ((string) ($this->input->post('evaluate') ?? '1')) === '1';
            $res = $this->cmp->rebuildFleetPosture($evaluate);
            $this->json_success([
                'message' => "Fleet rebuild complete — {$res['ok']} of {$res['total']} vehicles.",
                'result'  => $res,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_compliance::rebuild — ' . $e->getMessage());
            $this->json_error('Could not rebuild fleet posture. Please retry.', 500);
        }
    }

    /** Seed a compliance rule pack for this tenant (idempotent). */
    public function seed_pack(): void
    {
        require_permission('Transport', 'compliance_seed_pack', 'manage');
        try {
            $pack = trim((string) ($this->input->post('pack') ?? 'CBSE'));
            $res  = $this->cmp->seedRulePack($pack !== '' ? $pack : 'CBSE');
            $this->json_success([
                'message' => "{$res['pack']} pack ready — {$res['seeded']} added, {$res['already_present']} already present.",
                'result'  => $res,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_compliance::seed_pack — ' . $e->getMessage());
            $this->json_error('Could not seed the rule pack. Please retry.', 500);
        }
    }

    /**
     * GET  → list the tenant's rules.
     * POST → update one rule's severity_weight / blocks_dispatch.
     * Both are `manage`: reading the tunable policy and changing it are the
     * same administrative concern, and the dashboard/vehicle views already
     * surface every rule a `view` user needs.
     */
    public function rules(): void
    {
        require_permission('Transport', 'compliance_rules', 'manage');

        if (strtoupper((string) $this->input->method(true)) !== 'POST') {
            $this->json_success(['rules' => $this->cmp->listRules()]);
        }

        try {
            $input = [];
            if ($this->input->post('severity_weight') !== null) {
                $input['severity_weight'] = (int) $this->input->post('severity_weight');
            }
            if ($this->input->post('blocks_dispatch') !== null) {
                $input['blocks_dispatch'] = ((string) $this->input->post('blocks_dispatch')) === '1';
            }
            $row = $this->cmp->updateRule(trim((string) $this->input->post('rule_code')), $input);
            $this->json_success(['message' => 'Rule updated.', 'rule' => $row]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_compliance::rules — ' . $e->getMessage());
            $this->json_error('Could not update the rule. Please retry.', 500);
        }
    }
}
