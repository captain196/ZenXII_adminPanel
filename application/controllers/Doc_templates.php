<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Doc_templates — Certificate Designer (Document Engine, Template Engine half).
 *
 * SCOPE: designs, validates, versions, publishes and activates templates.
 * It does NOT issue documents and does NOT wire any module's print button —
 * those belong to the Issuance Engine (CON-NO_PRINT_IMPL).
 *
 * NAMING: not "template_designer" — Result.php:112 already owns
 * result/template_designer, which is a MARKS-SCHEME editor, unrelated.
 *
 * LEGACY: application/controllers/Certificates.php is left running and
 * untouched. It is an RTDB prototype whose counter is read-increment-write
 * ("best-effort atomicity" in its own comment), so concurrent issuance mints
 * duplicate certificate numbers. Replacement is alongside, per ADR D6.
 *
 * CSRF: protection stays ON. Gate G0.7 proved the token round-trips correctly
 * with the existing config (csrf_token, csrf_regenerate=FALSE) — POST without
 * a token 403s, POST with one passes. NOTHING here goes in csrf_exclude_uris.
 * Excluding these routes would disable CSRF on publish/activate and let a
 * forged cross-site POST flip a school's active Transfer Certificate template.
 *
 * AUDIT: uses the existing log_audit() helper so events land in the existing
 * Audit Logs viewer. A separate document-event collection would fragment audit
 * a fourth way (alongside auditLogs, academicAuditLog, attendanceAuditLog).
 */
class Doc_templates extends MY_Controller
{
    /** RBAC module key. Reuses the existing Certificates catalogue entry. */
    const MODULE = 'Certificates';

    /** Audit module label (appears in the Audit Logs viewer). */
    const AUDIT_MODULE = 'DocTemplates';

    /**
     * Per-endpoint capability. Graded view | edit | manage.
     *
     * Enforced centrally in _remap() rather than repeated per method, so a new
     * endpoint cannot be added without an explicit decision about who may call
     * it — an omission fails closed instead of inheriting `view`.
     */
    const CAPABILITIES = [
        // page loads
        'index'          => 'view',
        'gallery'        => 'view',
        'design'         => 'edit',
        // reads
        'get_types'      => 'view',
        'get_templates'  => 'view',
        'get_template'   => 'view',
        'get_blocks'     => 'view',
        // writes
        'create'         => 'edit',
        'save'           => 'edit',
        'validate'       => 'edit',
        'preview'        => 'edit',
        'proof_pdf'      => 'edit',
        'upload_asset'   => 'edit',
        'save_block'     => 'edit',
        // state transitions — legally consequential
        'publish'        => 'manage',
        'activate'       => 'manage',
        'archive'        => 'manage',
    ];

    public function __construct()
    {
        parent::__construct();

        require_permission(self::MODULE);
        $this->load->helper('audit');
        $this->load->library('doc_renderer', null, 'docpdf');

        // Scope image resolution to this school's storage only (SSRF guard).
        if (!empty($this->school_id)) {
            $this->docpdf->allowImageRoot(FCPATH . 'uploads/' . $this->school_id);
        }
    }

    /**
     * Central capability gate.
     *
     * CI calls _remap for every request to this controller, so this is the one
     * place a capability can be checked. An endpoint missing from CAPABILITIES
     * is refused outright — fail closed.
     */
    public function _remap(string $method, array $params = [])
    {
        if (!method_exists($this, $method) || str_starts_with($method, '_')) {
            show_404();
            return;
        }

        $needed = self::CAPABILITIES[$method] ?? null;
        if ($needed === null) {
            log_message('error', 'Doc_templates: no capability declared for ' . $method);
            $this->_deny('This action is not available.', 403);
            return;
        }

        if (!has_permission(self::MODULE, $needed)) {
            $this->_deny('You do not have permission to perform this action.', 403);
            return;
        }

        call_user_func_array([$this, $method], $params);
    }

    /** Deny consistently for both AJAX and page loads. */
    private function _deny(string $message, int $code): void
    {
        if ($this->input->is_ajax_request()) {
            $this->json_error($message, $code);
            return;
        }
        redirect(rbac_denied_url(self::MODULE));
    }

    /** Reject non-POST on state-changing endpoints. */
    private function _require_post(): bool
    {
        if ($this->input->method() !== 'post') {
            $this->json_error('POST required', 405);
            return false;
        }
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PAGE LOADS
    // ═══════════════════════════════════════════════════════════════════

    /** Gallery shell. Tabs load their data over AJAX. */
    public function index(string $docType = ''): void
    {
        $data = [
            'active_tab'   => 'gallery',
            'doc_type'     => $this->_safe_type($docType),
            'school_name'  => $this->school_display_name ?? '',
            'session_year' => $this->session_year ?? '',
            'can_edit'     => has_permission(self::MODULE, 'edit'),
            'can_manage'   => has_permission(self::MODULE, 'manage'),
        ];

        $this->load->view('include/header');
        $this->load->view('doc_templates/index', $data);
        $this->load->view('include/footer');
    }

    public function gallery(string $docType = ''): void
    {
        $this->index($docType);
    }

    /** Designer shell for one template. */
    public function design(string $templateId = ''): void
    {
        $templateId = $this->safe_path_segment($templateId, 'templateId');

        $data = [
            'active_tab'   => 'design',
            'template_id'  => $templateId,
            'school_name'  => $this->school_display_name ?? '',
            'session_year' => $this->session_year ?? '',
            'can_manage'   => has_permission(self::MODULE, 'manage'),
        ];

        // Same shell as index(): D0/D1/D2 are one view switched client-side,
        // with the breadcrumb as the only back-navigation (UX_SPEC §2).
        $this->load->view('include/header');
        $this->load->view('doc_templates/index', $data);
        $this->load->view('include/footer');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AJAX — reads
    //  Bodies land with Doc_template_service (P1.x). The capability map,
    //  CSRF posture and response contract are settled here first because
    //  every endpoint below inherits them.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The document-type catalogue for THIS school. P1.8/P1.9 — no longer a stub.
     *
     * Config-driven plus ONE keyed read of the school doc. No composite query,
     * so this endpoint is not blocked by P1.2 (indexes withdrawn) or P1.3
     * (rules unwritten) the way the template reads are.
     *
     * Returns unavailable types too, each with a reason — see
     * Doc_contract::catalogue(). Silently omitting them reads as "your state is
     * unsupported", which is both wrong and unactionable.
     *
     * NOTE: the SPA still runs on its own copy of this catalogue in
     * `designer.js`. The two are held identical by
     * `tests/Unit/DocContractParityTest`; switching the client to consume this
     * endpoint is a later step and is not implied by shipping it.
     */
    public function get_types(): void
    {
        $school = $this->_school_context();

        try {
            $contract = $this->_contract();
        } catch (Throwable $e) {
            // A missing or half-loaded contract must not render as "no document
            // types are available", which looks like a configured product with
            // nothing switched on. Fail loudly instead.
            log_message('error', 'Doc_templates::get_types — ' . $e->getMessage());
            $this->_deny('The document catalogue is not configured. Contact support.', 500);
            return;
        }

        $this->json_success(['data' => [
            'school' => $school,
            'types'  => $contract->catalogue($school['state']),
        ]]);
    }

    /**
     * School context the catalogue and compliance stack are resolved against.
     *
     * `state` gates the state-specific certificates (Kerala Form 5A / r.22A,
     * A.P. Study Certificate); `board` selects the compliance layer.
     *
     * A school with no `state` set gets the state-gated types marked
     * unavailable with a reason that says so, rather than being quietly given
     * or quietly denied them.
     */
    private function _school_context(): array
    {
        $doc = $this->fs->get('schools', $this->fs->schoolId());
        if (!is_array($doc)) {
            $doc = [];
        }

        return [
            'name'  => (string) ($doc['name'] ?? $this->school_display_name ?? ''),
            'state' => (string) ($doc['state'] ?? ''),
            'board' => (string) ($doc['affiliationBoard'] ?? $doc['board'] ?? ''),
            'stage' => (string) ($doc['stage'] ?? 'both'),
        ];
    }

    /** Lazily loaded contract service (P1.9). */
    private function _contract(): Doc_contract
    {
        if (!isset($this->doccontract)) {
            $this->load->library('doc_contract', null, 'doccontract');
        }
        return $this->doccontract;
    }

    /** Lazily loaded lifecycle service (Phase 6). */
    private function _templates(): Doc_template_service
    {
        if (!isset($this->doctpl)) {
            $this->load->library('doc_template_service', null, 'doctpl');
        }
        return $this->doctpl;
    }

    /** Lazily loaded block service (P8.1). */
    private function _blocks(): Doc_block_service
    {
        if (!isset($this->docblock)) {
            $this->load->library('doc_block_service', null, 'docblock');
        }
        return $this->docblock;
    }

    /**
     * One place where a service exception becomes an HTTP response.
     *
     * The typed E_ codes (P9.3) are what the client branches on; the message is
     * for a human. A failure NEVER returns 200 with an empty payload — that is
     * the phantom-success trap this repo has been bitten by before, and on a
     * denied publish it would report "done" for something that did not happen.
     */
    private function _run(callable $fn): void
    {
        try {
            $this->json_success(['data' => $fn()]);
        } catch (InvalidArgumentException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $msg  = $e->getMessage();
            $code = str_starts_with($msg, 'E_CONFLICT') ? 409 : 422;
            log_message('error', 'Doc_templates: ' . $msg);
            $this->json_error($msg, $code);
        } catch (Throwable $e) {
            // Never leak an internal message for an unexpected failure.
            log_message('error', 'Doc_templates UNEXPECTED: ' . $e->getMessage());
            $this->json_error('The action could not be completed.', 500);
        }
    }

    public function get_templates(): void
    {
        $this->_run(function () {
            $docType = (string) $this->input->get('docType');
            $where   = [['schoolId', '=', $this->school_id]];
            if ($docType !== '') {
                $where[] = ['docType', '=', $docType];
            }
            $rows = $this->fs->schoolWhere('documentTemplates', $where) ?: [];
            return ['templates' => $rows];
        });
    }

    public function get_template(): void
    {
        $this->_run(function () {
            $id = $this->safe_path_segment((string) $this->input->get('templateId'), 'templateId');
            $t = $this->fs->get('documentTemplates', $id);
            // Tenant check on the way OUT as well as in the rules — the panel
            // uses the Admin SDK, which bypasses firestore.rules entirely.
            if (!is_array($t) || ($t['schoolId'] ?? null) !== $this->school_id) {
                throw new RuntimeException('Template not found');
            }
            return ['template' => $t];
        });
    }

    public function get_blocks(): void
    {
        $this->_run(fn() => [
            'blocks' => array_values($this->_blocks()->listFor(
                (string) $this->school_id,
                ($this->input->get('blockType') ?: null)
            )),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AJAX — writes
    // ═══════════════════════════════════════════════════════════════════

    public function create(): void
    {
        if (!$this->_require_post()) return;
        $this->json_success(['data' => ['pending' => 'P1.x']]);
    }

    public function save(): void
    {
        if (!$this->_require_post()) return;
        $this->_run(function () {
            $id   = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
            $lock = $this->input->post('lockVersion');
            if ($lock === null || $lock === '') {
                // Without the caller's lockVersion there is no optimistic
                // concurrency at all — a missing one must not default to "wins".
                throw new InvalidArgumentException('id and lockVersion are required');
            }
            $patch = json_decode((string) $this->input->post('patch'), true);
            if (!is_array($patch)) {
                throw new InvalidArgumentException('patch must be a JSON object');
            }
            $out = $this->_templates()->save($id, $patch, (int) $lock);
            return ['lockVersion' => $out['lockVersion']];
        });
    }

    public function validate(): void
    {
        if (!$this->_require_post()) return;
        $this->json_success(['data' => ['pending' => 'P5.x']]);
    }

    /**
     * Serialize a template to HTML — the SAME string the PDF path renders.
     *
     * There is deliberately no second "preview serializer". If the two ever
     * diverged the preview would be lying about what prints, and a certificate
     * is a legal record (IMPLEMENTATION_ARCHITECTURE §5.3).
     *
     * Sample mode is the default: previewing with real student data would put a
     * named minor's record on screen for anyone designing a layout.
     */
    public function preview(): void
    {
        if (!$this->_require_post()) return;

        $this->_run(function () {
            $tpl = json_decode((string) $this->input->post('template'), true);
            if (!is_array($tpl)) {
                throw new InvalidArgumentException('template must be a JSON object');
            }

            $docType = (string) ($tpl['docType'] ?? '');
            $lang    = (string) ($this->input->post('lang') ?: ($tpl['defaultLanguage'] ?? 'en'));
            $mode    = (string) ($this->input->post('sample') ?: 'typical');
            if (!in_array($mode, ['typical', 'p95'], true)) {
                throw new InvalidArgumentException("sample must be 'typical' or 'p95'");
            }

            $this->load->library('doc_serializer', null, 'docser');

            return [
                'html' => $this->docser->render($tpl, [], $lang, [
                    'contract'    => $this->_contract()->get($docType),
                    'sample'      => $mode,
                    'isDuplicate' => (bool) $this->input->post('isDuplicate'),
                ]),
                'lang'   => $lang,
                'sample' => $mode,
            ];
        });
    }

    public function proof_pdf(): void
    {
        if (!$this->_require_post()) return;
        $this->json_success(['data' => ['pending' => 'P6.2']]);
    }

    public function upload_asset(): void
    {
        if (!$this->_require_post()) return;
        $this->json_success(['data' => ['pending' => 'P1.x']]);
    }

    public function save_block(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('blockId'), 'blockId');

        $this->_run(function () use ($id) {
            $data = json_decode((string) $this->input->post('block'), true);
            if (!is_array($data)) {
                throw new InvalidArgumentException('block must be a JSON object');
            }
            // schoolId comes from the SESSION, never the request body. A
            // client-supplied one would let a caller write into another tenant,
            // and the panel uses the Admin SDK so firestore.rules would not
            // catch it.
            $data['schoolId'] = $this->school_id;
            $b = $this->_blocks()->save($id, $data, (string) ($this->staff_id ?? ''));
            return ['version' => $b['version']];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AJAX — state transitions (manage)
    //  Each is legally consequential and therefore audited.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Freeze the current draft as an immutable version.
     *
     * Publishing does NOT activate. That is a separate act with a separate
     * capability grade, because activeVersion is the pointer every print point
     * resolves — moving it changes what the school legally issues.
     *
     * The *_attempt audit event is kept and still fires BEFORE the work: it
     * records that someone asked, which survives even when the attempt then
     * fails. The service logs the successful transition separately (P6.7).
     */
    public function publish(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.publish_attempt', $id, 'Publish requested');

        $this->_run(function () use ($id) {
            $proof = json_decode((string) $this->input->post('proof'), true);
            if (!is_array($proof)) {
                throw new InvalidArgumentException('proof is required');
            }
            $r = $this->_templates()->publish($id, $proof, (string) ($this->staff_id ?? ''));
            return ['versionId' => $r['versionId'], 'version' => $r['version']];
        });
    }

    public function activate(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.activate_attempt', $id, 'Activate requested');

        $this->_run(fn() => $this->_templates()->activate($id, (string) ($this->staff_id ?? '')));
    }

    public function archive(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.archive_attempt', $id, 'Archive requested');

        $this->_run(fn() => $this->_templates()->archive($id, (string) ($this->staff_id ?? '')));
    }

    // ═══════════════════════════════════════════════════════════════════

    /** v1 document types. Widened from the research corpus in v2. */
    private function _safe_type(string $t): string
    {
        $allowed = ['transfer_certificate', 'bonafide', 'character'];
        return in_array($t, $allowed, true) ? $t : '';
    }
}
