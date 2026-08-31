<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Entitlements — Phase E. The ONE shared capture + approval surface for
 * `studentEntitlements`, mounted by all three admission pathways.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  "ONE SHARED COMPONENT, THREE ENTRY POINTS"
 * ─────────────────────────────────────────────────────────────────────────
 *  The roadmap requires the Services & Concessions step on the public online
 *  form, the CRM walk-in application, AND the direct Admission screen — "one
 *  shared component, three entry points, so a fix here doesn't need repeating
 *  per pathway."
 *
 *  That is satisfied by these endpoints being pathway-agnostic: they take a
 *  student/applicant id and a `source` tag, and are identical no matter who
 *  calls them. There is no per-pathway branch anywhere in this controller.
 *  Adding a fourth surface later (Student 360) requires no change here.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  RBAC — canonical graded only
 * ─────────────────────────────────────────────────────────────────────────
 *  Every endpoint gates on the graded capability via _require_role([], …) —
 *  an EMPTY legacy allow-list, exactly the pattern established when
 *  Fee_concessions was migrated off legacy role arrays. No role-name array
 *  exists in this file and none may be added; adjust the module capability
 *  instead.
 *
 *    capture / read  → Fees:edit
 *    approve/reject  → Fees:manage   (deliberately HIGHER than capture, so a
 *                                     clerk who can propose cannot self-grant)
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS CONTROLLER DOES NOT DO
 * ─────────────────────────────────────────────────────────────────────────
 *  It never writes Firestore directly — every mutation goes through
 *  Entitlement_service, per the baseline's "no controller is permitted to
 *  write to studentEntitlements directly". It also never prices: the preview
 *  endpoint delegates to the Phase C/D pricing services rather than
 *  recomputing, so the number an operator previews is produced by the same
 *  code that will bill them.
 */
class Entitlements extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->helper('admission_crm');
    }

    /** Canonical service handle — constructed identically for every endpoint. */
    private function _svc()
    {
        $this->load->library('Entitlement_service', null, 'entitlementSvc');
        return $this->entitlementSvc->init(
            $this->firebase, $this->fs, $this->school_id, $this->session_year,
            (string) ($this->admin_id ?? '')
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  CAPTURE  (all three admission pathways)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST — propose an entitlement (service enrolment or concession).
     * Identical contract for every pathway; `source` records which one.
     */
    public function propose()
    {
        $this->_require_role([], 'entitlement_propose', 'Fees', 'edit');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $studentId = trim((string) $this->input->post('student_id'));
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }

        // Approval requirement is a SERVER decision, never a client claim —
        // otherwise the capture form could mark its own concession
        // pre-approved and bypass maker-checker entirely.
        $kind           = trim((string) $this->input->post('kind'));
        $concessionType = trim((string) ($this->input->post('concession_type') ?? 'other'));
        $approvalRequired = $this->_approval_required($kind, $concessionType);

        $res = $this->_svc()->propose([
            'studentId'            => $studentId,
            'kind'                 => $kind,
            'serviceType'          => trim((string) ($this->input->post('service_type') ?? '')),
            'concessionType'       => $concessionType,
            'scope'                => trim((string) $this->input->post('scope')),
            'valueType'            => trim((string) $this->input->post('value_type')),
            'value'                => $this->input->post('value'),
            // No `frequency` — retired at the P2 contract freeze (I1). A posted
            // value from an older client is simply not read.
            'effectiveFrom'        => trim((string) $this->input->post('effective_from')),
            'effectiveTo'          => trim((string) ($this->input->post('effective_to') ?? '')),
            'reason'               => trim((string) ($this->input->post('reason') ?? '')),
            'govtReceivableAmount' => $this->input->post('govt_receivable_amount'),
            'linkedRouteId'        => trim((string) ($this->input->post('linked_route_id') ?? '')),
            'linkedRoomId'         => trim((string) ($this->input->post('linked_room_id') ?? '')),
            'source'               => trim((string) ($this->input->post('source') ?? 'manual')),
            'approvalRequired'     => $approvalRequired,
        ]);

        if (empty($res['ok'])) return $this->json_error((string) ($res['error'] ?? 'Could not save.'));
        // FEE-Z6 — see approve() for the mechanism. This endpoint was the most
        // damaging instance of it: `propose` returns 'active' or
        // 'pending_approval', NEITHER of which is 'success', so the shared
        // capture partial's transport guard threw EVERY successful capture into
        // its error branch. Staff adding a concession during admission saw a red
        // failure for a record that had actually saved — inviting a retry and a
        // duplicate. All three admission pathways mount that partial.
        return $this->json_success([
            'message' => $res['status'] === 'pending_approval'
                ? 'Submitted for approval.'
                : 'Entitlement recorded.',
            'id'                => $res['id'],
            'entitlementStatus' => $res['status'],
        ]);
    }

    /**
     * Which proposals need a second pair of eyes.
     *
     * Concessions give money away, so they are maker-checker gated — the
     * standard control in commercial ERPs, and the reason `approvalRequired`
     * exists in the schema. Service enrolments (bus/hostel) CHARGE money and
     * are routine front-office work, so they activate immediately; gating them
     * would stall admissions for no financial-control benefit.
     *
     * A user who already holds Fees:manage could approve their own proposal
     * anyway, so their capture is auto-active rather than parked in a queue
     * they would immediately clear themselves — same outcome, one less step,
     * and it keeps the queue meaningful instead of full of self-serve noise.
     */
    private function _approval_required(string $kind, string $concessionType): bool
    {
        if ($kind !== 'concession') return false;
        return !has_permission('Fees', 'manage');
    }

    /** POST — every entitlement on file for a student (capture-surface display). */
    public function for_student()
    {
        $this->_require_role([], 'entitlement_for_student', 'Fees', 'edit');
        $studentId = trim((string) $this->input->post('student_id'));
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }
        return $this->json_success(['entitlements' => $this->_svc()->forStudent($studentId)]);
    }

    /**
     * F-3 — POST: the school's registered fee heads, for the capture picker.
     *
     * THE DEFECT THIS CLOSES. The head-scope target was a FREE-TEXT BOX
     * (`placeholder="e.g. transport / Tuition"`), so whatever an operator typed
     * became the scope target verbatim. In practice that produced
     * `head:<typed name>` — the reader's tier-3 legacy path, which a later
     * rename silently breaks. There was no way to produce the authoritative
     * `head:<feeHeadRef>` at all: the format existed, and no surface could emit
     * it.
     *
     * Read-only, and deliberately thin — it delegates to the ONE fee-head
     * authority (Fee_head_resolver) rather than querying `feeHeadRegistry`
     * itself. No second master, no second identity.
     *
     * Independent of USE_FEE_HEAD_REGISTRY: that flag gates whether the
     * registry CLASSIFIES a demand at generation time. Reading the registry to
     * let an operator pick a head neither prices nor generates anything, so
     * gating this on the flag would leave the picker permanently empty while
     * the very records it protects were being created.
     */
    public function fee_heads()
    {
        $this->_require_role([], 'entitlement_fee_heads', 'Fees', 'edit');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $this->load->library('Fee_head_resolver', null, 'feeHeadResolver');
        $heads = $this->feeHeadResolver->init($this->fs)->listForSchool($this->school_id);

        // An empty registry is a legitimate state, not an error — but the UI
        // must be able to tell "none registered" from "the call failed", or it
        // will render an empty picker and look broken.
        return $this->json_success([
            'heads'      => $heads,
            'count'      => count($heads),
            'categories' => Fee_head_resolver::ASSIGNABLE,
        ]);
    }

    /**
     * Register a fee head for THIS school.
     *
     * ── THE GAP THIS CLOSES ──────────────────────────────────────────────
     * fee_heads() above could only LIST heads, and nothing in the entire
     * application could create one. The registry's only writer was
     * scripts/m3_mint_fee_head_registry.php — a developer-run script with a
     * hardcoded mapping for two named tenants.
     *
     * For a school onboarded tomorrow that was not a missing convenience. With
     * USE_FEE_HEAD_REGISTRY active and an empty registry, fee head resolution
     * fails closed and admission generates ZERO demands: students admitted,
     * nobody billable, and the only remedy a developer editing a script. This
     * endpoint is what makes the foundation an ADMINISTRATOR action instead.
     *
     * Deliberately thin. All identity, uniqueness and refusal logic belongs to
     * Fee_head_registry_service — the one write authority — exactly as the read
     * side delegates to Fee_head_resolver. No second master, no second identity.
     *
     * 'manage' rather than 'edit': a fee head is a permanent financial
     * identity whose category decides whether a school-wide concession reaches
     * it. Creating one is a stronger act than editing an entitlement.
     *
     * Independent of USE_FEE_HEAD_REGISTRY, for the same reason fee_heads() is:
     * the flag gates whether the registry CLASSIFIES a demand at generation
     * time. Gating creation on it would make the foundation impossible to build
     * until the flag was on, and the flag useless until the foundation existed.
     */
    public function create_fee_head()
    {
        $this->_require_role([], 'entitlement_fee_heads', 'Fees', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $name     = (string) $this->input->post('name');
        $category = (string) $this->input->post('category');

        $this->load->library('Fee_head_registry_service', null, 'feeHeadRegistry');
        $r = $this->feeHeadRegistry
            ->init($this->firebase, (string) ($this->admin_id ?? 'admin'))
            // The tenant is taken from the SESSION, never from the request. A
            // schoolId accepted from the client would let one school mint a head
            // in another's registry.
            ->create($this->school_id, $name, $category);

        if (empty($r['ok'])) {
            return $this->json_error((string) ($r['error'] ?? 'Could not register the fee head.'));
        }

        return $this->json_success([
            'feeHeadId' => $r['feeHeadId'],
            'created'   => (bool) ($r['created'] ?? false),
            'message'   => ($r['created'] ?? false)
                ? 'Fee head registered.'
                : 'That fee head is already registered — no duplicate was created.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  LIVE FEE PREVIEW
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST — what will this student actually be billed?
     *
     * Delegates to the SAME pricing code that generates the real demands:
     * Fee_generation_service for the tuition chart (Phase C) and
     * Service_entitlement_pricing for a service fee (Phase D). It deliberately
     * does not recompute anything itself — a preview that runs its own maths
     * is a preview that can lie, and this project already carries the scars of
     * parallel fee calculations (G4/G12).
     *
     * READ-ONLY: builds specs in memory and discards them. Nothing is written.
     */
    public function preview()
    {
        $this->_require_role([], 'entitlement_preview', 'Fees', 'edit');

        $studentId = trim((string) $this->input->post('student_id'));
        // DEFECT FIX (2026-08-09, Gate 5 blocker): the admission form's class /
        // section selects hold RAW values ("8th", "A"), but fee structures are
        // keyed canonically ("Class 8th", "Section A"). Passing the raw values
        // straight through meant buildAdmissionDemandSpecs() found no chart,
        // returned chartFound=false, and generateDemandsForStudent() hit its
        // early-return guard BEFORE reaching _shadowCompare() — so the preview
        // always showed "Total payable ₹0" and the A/B shadow could never run.
        //
        // Normalised with the SAME helpers Sis::save_admission() uses (see
        // Sis.php:576-577), so the preview resolves exactly the chart the real
        // generator will. No new normalisation logic is introduced here.
        $class     = trim((string) ($this->input->post('class')   ?? ''));
        $section   = trim((string) ($this->input->post('section') ?? ''));
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }

        // The caller may send nothing (the page's class/section selects can be
        // reset or re-rendered between admitting and previewing). The STUDENT
        // RECORD is authoritative — a form field is not — so fall back to it
        // rather than silently skipping the chart lookup and reporting a
        // misleading "Total payable ₹0".
        if ($class === '' || $section === '') {
            try {
                $stu = $this->fs->getEntity('students', $studentId);
                if (is_array($stu)) {
                    if ($class === '')   $class   = (string) ($stu['className'] ?? $stu['class']   ?? $stu['Class']   ?? '');
                    if ($section === '') $section = (string) ($stu['section']   ?? $stu['Section'] ?? '');
                }
            } catch (\Throwable $e) {
                log_message('error', 'Entitlements::preview student lookup failed for ' . $studentId . ': ' . $e->getMessage());
            }
        }

        // Normalise with the SAME helpers Sis::save_admission() uses
        // (Sis.php:576-577): fee structures are keyed "Class 8th"/"Section A",
        // while the form selects hold raw "8th"/"A". Both helpers are
        // idempotent, so an already-canonical value passes through unchanged.
        $class   = Firestore_service::classKey($class);
        $section = Firestore_service::sectionKey($section);

        // Fail LOUD instead of returning a confident ₹0. A preview that cannot
        // resolve the chart must say so — the previous silent skip is exactly
        // what hid this defect until Gate 5 could not execute.
        if ($class === '' || $section === '') {
            return $this->json_error('Could not determine the student\'s class/section for the preview.');
        }

        $lines = [];
        $gross = 0.0; $net = 0.0;

        // ── Tuition chart, through the canonical generator ──
        try {
            if ($class !== '' && $section !== '') {
                $this->load->library('Fee_lifecycle', null, 'feeLifecycleP');
                $this->feeLifecycleP->init($this->firebase, $this->school_name, $this->session_year, (string) ($this->admin_id ?? ''));
                $built = $this->feeLifecycleP->previewDemandSpecs($studentId, $class, $section);
                foreach (($built['specs'] ?? []) as $entry) {
                    $d = $entry['data'] ?? [];
                    $g = (float) ($d['grossAmount'] ?? 0);
                    $n = (float) ($d['netAmount']   ?? $g);
                    $gross += $g; $net += $n;
                    $lines[] = [
                        'type'      => 'tuition',
                        'feeHead'   => (string) ($d['feeHead'] ?? ''),
                        'period'    => (string) ($d['period']  ?? ''),
                        'gross'     => round($g, 2),
                        'net'       => round($n, 2),
                        'concession'=> round($g - $n, 2),
                    ];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Entitlements::preview tuition leg failed for ' . $studentId . ': ' . $e->getMessage());
        }

        return $this->json_success([
            'lines'      => $lines,
            'totalGross' => round($gross, 2),
            'totalNet'   => round($net, 2),
            'totalConcession' => round($gross - $net, 2),
            'note'       => 'Preview only — computed by the same engine that generates demands. Nothing was saved.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  APPROVAL QUEUE
    // ─────────────────────────────────────────────────────────────────────

    /** GET/POST — pending queue, oldest request first (Phase B index). */
    public function queue()
    {
        $this->_require_role([], 'entitlement_queue', 'Fees', 'manage');
        return $this->json_success(['pending' => $this->_svc()->pendingQueue()]);
    }

    public function approve()
    {
        $this->_require_role([], 'entitlement_approve', 'Fees', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');
        $id = trim((string) $this->input->post('entitlement_id'));
        if ($id === '' || !$this->safe_path_segment($id)) return $this->json_error('Invalid entitlement_id.');

        // PR-1 — the approver's decided terms. Only the four PRICING fields are
        // accepted; identity and provenance are not amendable, and anything not
        // posted is simply left as proposed. Each key is included only when the
        // client actually sent it, so an approve with no amendment reaches the
        // service as an empty array and behaves exactly as before.
        // DEC-004: `scope` is NOT here. It is identity, not a business term, and
        // the service refuses an attempt to change it rather than ignoring one.
        $amend = [];
        foreach ([
            'value_type'     => 'valueType',
            'value'          => 'value',
            'effective_from' => 'effectiveFrom',
            'effective_to'   => 'effectiveTo',
        ] as $post => $field) {
            $v = $this->input->post($post);
            if ($v !== null && $v !== false) $amend[$field] = $v;
        }

        $res = $this->_svc()->approve($id, trim((string) ($this->input->post('note') ?? '')), $amend);
        if (empty($res['ok'])) return $this->json_error((string) ($res['error'] ?? 'Could not approve.'));
        // FEE-Z5 — the entitlement's new state is returned as `entitlementStatus`,
        // NOT `status`.
        //
        // `json_success()` builds its envelope with
        //     array_merge(['status' => 'success'], $data)
        // so a `status` key in $data OVERWRITES the envelope. This line used to
        // send `'status' => $res['status']`, making a successful approval go out
        // as {"status":"active", …}. Every client applying the standard
        // phantom-success guard — `body.status !== 'success'` — therefore read a
        // SUCCESSFUL approval as a FAILURE: the Student 360 approvals panel threw
        // the success into its error branch, showed the message in red, and never
        // refreshed the list. Found by UAT 9.10c/9.10d.
        //
        // The envelope is the transport contract; the entitlement's lifecycle
        // state is payload. Conflating the two is what broke it, so they are now
        // separate keys. `json_success()` itself is deliberately NOT changed —
        // that is a global contract and a separate decision.
        return $this->json_success([
            'message'           => 'Entitlement approved.',
            'entitlementStatus' => $res['status'],
        ]);
    }

    public function reject()
    {
        $this->_require_role([], 'entitlement_reject', 'Fees', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');
        $id = trim((string) $this->input->post('entitlement_id'));
        if ($id === '' || !$this->safe_path_segment($id)) return $this->json_error('Invalid entitlement_id.');

        $res = $this->_svc()->reject($id, trim((string) ($this->input->post('reason') ?? '')));
        if (empty($res['ok'])) return $this->json_error((string) ($res['error'] ?? 'Could not reject.'));
        // FEE-Z6 — same envelope collision as approve(). Service returns 'rejected'.
        return $this->json_success([
            'message'           => 'Entitlement rejected.',
            'entitlementStatus' => $res['status'],
        ]);
    }

    public function discontinue()
    {
        $this->_require_role([], 'entitlement_discontinue', 'Fees', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');
        $id = trim((string) $this->input->post('entitlement_id'));
        if ($id === '' || !$this->safe_path_segment($id)) return $this->json_error('Invalid entitlement_id.');

        $res = $this->_svc()->discontinue(
            $id,
            trim((string) ($this->input->post('reason') ?? '')),
            trim((string) ($this->input->post('effective_to') ?? ''))
        );
        if (empty($res['ok'])) return $this->json_error((string) ($res['error'] ?? 'Could not discontinue.'));
        // FEE-Z6 — same envelope collision as approve().
        //
        // `$res['status']` is passed through rather than hardcoded to
        // 'discontinued' on purpose: discontinue() is idempotent, and on an
        // already-discontinued or already-rejected row it returns that row's
        // EXISTING status with `noop => true`. Hardcoding would report
        // 'discontinued' for a row that is actually 'rejected'.
        return $this->json_success([
            'message'           => 'Entitlement discontinued.',
            'entitlementStatus' => $res['status'],
        ]);
    }
}
