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
 * LEGACY: application/controllers/Certificates.php was RETIRED on 2026-09-04.
 * It was an RTDB prototype, 692 lines with zero tests, whose counter was
 * read-increment-write — two concurrent issues minted the same number AND the
 * same record id, so the second silently overwrote the first. It also never
 * produced a document (pdfUrl was hardcoded '', printing was window.print()
 * over browser DOM) and its revocation flag was never read back at the
 * retrieval path, so a revoked certificate stayed printable.
 * It had issued ZERO certificates across all 8 production schools — verified
 * live before removal — so nothing was migrated. See
 * qa/certificates/20-legacy-backend.md and 22-three-systems.md.
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
        /* VIEW, not edit. `design` only serves the shell; every byte of content
           arrives through `get_template`, which is itself view-graded, and every
           write is separately graded. Gating the shell at `edit` meant a
           view-grade user could not so much as LOOK at the certificate their
           school issues — a read grade that reads nothing. The client renders
           read-only for them; the server refuses their writes regardless. */
        'design'         => 'view',
        // reads
        'get_types'      => 'view',
        'seed_standard'  => 'edit',   // creates templates — same grade as create()
        'get_templates'  => 'view',
        'get_template'   => 'view',
        'get_blocks'     => 'view',
        'get_versions'   => 'view',
        'version_pdf'    => 'view',
        'presence'       => 'view',
        'leave'          => 'view',
        'duplicate'      => 'edit',
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
        'deactivate'     => 'manage',
        'delete'         => 'manage',
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

    /**
     * Who is doing this.
     *
     * MY_Controller has NO `staff_id` property — the identity is `admin_id`,
     * and log_audit() reads the same session key. Every call here previously
     * passed `$this->staff_id ?? ''`, which resolved to an empty string, so the
     * immutable snapshot of a statutory certificate recorded NOBODY as its
     * publisher: `publishedBy: ""`. Observed on the first live publish.
     *
     * The snapshot exists to answer "who issued this, and from what", years
     * later. Half of that question was unanswerable.
     */
    private function _actor(): string
    {
        return (string) ($this->admin_id ?? '');
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
            /* The grade itself, so the client can SAY which one it is rather
               than inferring it from two booleans and getting the wording
               wrong. Ordered, so the UI can reason about it. */
            'grade'        => has_permission(self::MODULE, 'manage') ? 'manage'
                            : (has_permission(self::MODULE, 'edit') ? 'edit' : 'view'),
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
     * Give this school the standard documents it should already have.
     *
     * A new school's library was empty and nothing provisioned it — a template
     * existed only once a human opened the designer (which has no navigation
     * link), picked a starter, and proofed, published and activated it.
     *
     * IDEMPOTENT by document type, so it is safe to call on every hub load: a
     * school that already has a transfer certificate is not handed a second.
     * Seeded templates arrive as DRAFTS — publishing freezes a legal record and
     * activating is what every print point resolves, and neither may happen
     * because a page was loaded.
     */
    public function seed_standard(): void
    {
        $this->_require_post();

        $this->_run(function () {
            $school = $this->_school_context();

            /* The school's OWN templates, read through the same school-scoped
               query the list endpoint uses. Passing an unscoped read here would
               let another tenant's population suppress this school's seeding. */
            require_once APPPATH . 'libraries/Doc_rows.php';
            $existing = Doc_rows::map($this->fs->where(
                'documentTemplates', [['schoolId', '=', (string) $this->school_id]]
            )) ?: [];

            $this->load->library('doc_seeder', null, 'docseed');
            $r = $this->docseed->seed(
                (string) $this->school_id,
                $school['board'] ?? null,
                $school['state'] ?? null,
                $existing,
                $this->_actor()
            );

            if ($r['seeded']) {
                log_audit(self::AUDIT_MODULE, 'template.seed', (string) $this->school_id,
                          'Seeded standard templates: ' . implode(', ', $r['seeded']));
            }
            return $r;
        });
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
            /* The tenant is handed to the service from the SESSION, so that
               every lifecycle method is ownership-checked in ONE place rather
               than in each endpoint — which is how save, publish, activate and
               archive came to have no check at all. */
            $this->load->library('doc_template_service',
                ['schoolId' => (string) $this->school_id], 'doctpl');
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
            /* Normalised to docId => fields. The raw query returns a LIST of
               ['id'=>…, 'data'=>…] envelopes, and every client that read this
               endpoint got a numerically-indexed array whose rows had no
               name, docType or status on them. */
            require_once APPPATH . 'libraries/Doc_rows.php';
            $rows = Doc_rows::map($this->fs->schoolWhere('documentTemplates', $where));

            /* PROJECT. This endpoint returned every template's COMPLETE document,
               `objects` array included, and it is called on every hub load.
               Measured on one real school: 85 templates, **456 KB** in a single
               response — of which the list needs a name, a status and a couple of
               version numbers. The designer fetches the full document through
               get_template when a template is actually opened.
               At 850 templates that response was ~4.5 MB on every page load; at
               8,500, ~45 MB, built as one PHP array before json_encode. */
            $summary = [];
            foreach ($rows as $id => $t) {
                $summary[$id] = [
                    'schoolId'         => $t['schoolId']         ?? '',
                    'templateId'       => $t['templateId']       ?? '',
                    'docType'          => $t['docType']          ?? '',
                    'docTitle'         => $t['docTitle']         ?? '',
                    'name'             => $t['name']             ?? '',
                    'status'           => $t['status']           ?? 'draft',
                    'version'          => $t['version']          ?? 1,
                    'publishedVersion' => $t['publishedVersion'] ?? null,
                    'activeVersion'    => $t['activeVersion']    ?? null,
                    'starterId'        => $t['starterId']        ?? null,
                    'updatedAt'        => $t['updatedAt']        ?? '',
                    'updatedBy'        => $t['updatedBy']        ?? ($t['createdBy'] ?? ''),
                    /* The gallery draws a schematic from object GEOMETRY only —
                       never their content — so the shapes travel and the text,
                       images and merge bindings stay behind. */
                    'shapes'           => $this->_shapes($t['objects'] ?? []),
                ];
            }
            return ['templates' => $summary];
        });
    }

    /**
     * Geometry only, for the gallery's schematic preview.
     *
     * Five numbers an object instead of the whole object. On the measured
     * population this is the difference between a 456 KB list response and one
     * an order of magnitude smaller, and it means no template's text, images or
     * merge bindings are shipped to a screen that only draws rectangles.
     */
    private function _shapes(array $objects): array
    {
        $out = [];
        foreach ($objects as $o) {
            if (!is_array($o)) {
                continue;
            }
            $out[] = [
                'x' => (float) ($o['xMm'] ?? 0), 'y' => (float) ($o['yMm'] ?? 0),
                'w' => (float) ($o['wMm'] ?? 0), 'h' => (float) ($o['hMm'] ?? 0),
                't' => (string) ($o['type'] ?? 'text'),
                'r' => !empty($o['requiredKey']),   // drawn in the statutory colour
                'g' => (string) ($o['region'] ?? 'body'),
                's' => (($o['content']['shape'] ?? '') === 'seal'),
            ];
        }
        return $out;
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
            /* Return the DOCUMENT ID explicitly.
               The stored `templateId` field is the SHORT entity id ("TPL0001"),
               while every endpoint here takes the full document id
               ("SCH_..._TPL0001"). A client that assigned the stored document
               over its own state silently swapped one for the other and then
               saved against an id that does not exist — which is exactly what
               happened on the first live run. */
            $t['_id'] = $id;
            return ['template' => $t];
        });
    }

    /**
     * The frozen versions of one template — the real version history.
     *
     * The designer's history panel previously displayed HARDCODED versions
     * ("v2 · active · sha256:9c41…a2f1") for every template, regardless of what
     * had actually been published. That is worse than showing nothing: it is
     * fabricated audit information in the one place a person goes to ask
     * "which template produced this certificate?".
     *
     * Newest first. The snapshot body is deliberately NOT returned — a list of
     * twenty versions would carry twenty full documents, and the caller wants
     * the provenance, not the content.
     */
    public function get_versions(): void
    {
        $this->_run(function () {
            $id = $this->safe_path_segment((string) $this->input->get('templateId'), 'templateId');

            $head = $this->fs->get('documentTemplates', $id);
            if (!is_array($head) || ($head['schoolId'] ?? null) !== $this->school_id) {
                throw new RuntimeException('Template not found');
            }

            $rows = [];
            $highest = (int) ($head['publishedVersion'] ?? 0);
            for ($v = $highest; $v >= 1; $v--) {
                $doc = $this->fs->get('documentTemplateVersions', $id . '_v' . $v);
                if (!is_array($doc) || !$doc) {
                    // A gap is reported, never skipped: a missing snapshot is
                    // exactly what makes a version unreproducible.
                    $rows[] = ['version' => $v, 'missing' => true];
                    continue;
                }
                $rows[] = [
                    'version'      => $v,
                    /* Older snapshots were frozen before the proof record kept
                       its file paths, so fall back to the naming convention
                       proof_pdf() uses. A version published months ago should
                       still be viewable. */
                    'pdfLangs'     => $this->_versionPdfLangs($id, $v, $doc),
                    'publishedAt'  => $doc['publishedAt']  ?? null,
                    'publishedBy'  => $doc['publishedBy']  ?? null,
                    'proofPdfHash' => $doc['proofPdfHash'] ?? null,
                    'mpdfVersion'  => $doc['mpdfVersion']  ?? null,
                    'fontManifest' => array_keys((array) ($doc['fontManifest'] ?? [])),
                    'active'       => ((int) ($head['activeVersion'] ?? 0)) === $v,
                ];
            }

            return ['versions' => $rows,
                    'draftVersion'  => (int) ($head['version'] ?? 1),
                    'activeVersion' => $head['activeVersion'] ?? null];
        });
    }

    /**
     * Stream the frozen PDF for one published version.
     *
     * History could only ever ACTIVATE a version — there was no way to look at
     * one first. "Make this the certificate my school issues" was offered
     * without "show me what it is", which is the wrong way round for a
     * statutory document.
     *
     * Served through here rather than as a static file because
     * uploads/.htaccess denies .pdf: a rendered certificate carries the
     * school's letterhead, crest and signatures, and those must not be
     * fetchable by anyone who can guess a filename. This checks the session,
     * the capability and the tenant first.
     */
    /** Which languages have a readable PDF on disk for this version. */
    private function _versionPdfLangs(string $id, int $v, array $doc): array
    {
        $out = [];
        foreach (array_keys((array) ($doc['proofPdfPaths'] ?? [])) as $l) {
            $out[$l] = true;
        }
        $dir = FCPATH . 'uploads/' . $this->school_id . '/doctemplates/_proofs/';
        foreach ((array) ($doc['snapshot']['languages'] ?? ['en']) as $l) {
            $l = preg_replace('/[^a-z]/', '', strtolower((string) $l));
            if ($l !== '' && is_readable($dir . basename($id) . '_v' . $v . '_' . $l . '.pdf')) {
                $out[$l] = true;
            }
        }
        return array_keys($out);
    }

    public function version_pdf(): void
    {
        $id   = $this->safe_path_segment((string) $this->input->get('templateId'), 'templateId');
        $ver  = (int) $this->input->get('version');
        $lang = preg_replace('/[^a-z]/', '', strtolower((string) $this->input->get('lang'))) ?: 'en';

        $head = $this->fs->get('documentTemplates', $id);
        if (!is_array($head) || ($head['schoolId'] ?? null) !== $this->school_id) {
            show_404();
            return;
        }

        $snap = $this->fs->get('documentTemplateVersions', $id . '_v' . $ver);
        $rel  = $snap['proofPdfPaths'][$lang] ?? null;
        if (!$rel) {
            // Snapshot predates paths being recorded — use the convention.
            $rel = 'uploads/' . $this->school_id . '/doctemplates/_proofs/'
                 . basename($id) . '_v' . $ver . '_' . $lang . '.pdf';
        }

        /* The stored path is ours, but treat it as untrusted anyway: resolve it
           and require the result to sit inside THIS school's proof directory. */
        $root = realpath(FCPATH . 'uploads/' . $this->school_id . '/doctemplates/_proofs');
        $file = realpath(FCPATH . $rel);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            show_404();
            return;
        }

        /* INTEGRITY GATE — does the file still match what was published?
         *
         * The snapshot records a sha256 of each language's PDF at publication. Nothing
         * ever checked it: the file was streamed on trust, so if the bytes on disk had
         * changed by ANY route — the proof-overwrite defect fixed in this pass, a bad
         * backup restore, a compromised host, a half-written file after a crash — a
         * school would download a document that no longer matched its own record and
         * nothing anywhere would say so. The certification question was "does anything
         * notice?" and the answer was no.
         *
         * Now the read path notices. A mismatch is refused rather than served, because a
         * certificate whose bytes disagree with the record of what was issued is worse
         * than no certificate at all — and it is logged, because somebody needs to know.
         *
         * Absent digest = an older snapshot published before this was frozen. Those are
         * served, since refusing them would retire history nobody can re-render. */
        $expected = $snap['proofPdfPerLanguage'][$lang]['hash'] ?? null;
        if (is_string($expected) && $expected !== '') {
            $actual = 'sha256:' . hash_file('sha256', $file);
            if (!hash_equals($expected, $actual)) {
                log_message('error', sprintf(
                    'Doc_templates::version_pdf INTEGRITY FAILURE — %s v%d (%s): recorded %s, on disk %s. Refusing to serve.',
                    $id, $ver, $lang, $expected, $actual
                ));
                log_audit(self::AUDIT_MODULE, 'version.integrity_failure', $id,
                          "v$ver ($lang) no longer matches the hash recorded at publication");
                show_404();
                return;
            }
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    /**
     * Heartbeat: record that I am here, and report who else is.
     *
     * One call does both. At Firestore's cross-region latency a separate
     * "who else" read would double the cost of something that runs every
     * minute.
     */
    public function presence(): void
    {
        if (!$this->_require_post()) return;

        $this->_run(function () {
            $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
            return $this->_presence()->heartbeat(
                (string) $this->school_id, $id, $this->_actor(),
                (string) ($this->session->userdata('admin_name') ?: $this->_actor())
            );
        });
    }

    /** Best-effort departure; rides on a page-unload beacon. */
    public function leave(): void
    {
        if (!$this->_require_post()) return;

        $this->_run(function () {
            $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
            return ['left' => $this->_presence()->leave(
                (string) $this->school_id, $id, $this->_actor())];
        });
    }

    /**
     * Take my own copy — the escape hatch, and the branch.
     *
     * Available at any time and offered at every collision, so there is always
     * an exit that keeps the work. The copy is a fresh draft: never published,
     * never active, and carrying nothing of the original's lifecycle.
     */
    public function duplicate(): void
    {
        if (!$this->_require_post()) return;

        $this->_run(function () {
            $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');

            $src = $this->fs->get('documentTemplates', $id);
            if (!is_array($src) || ($src['schoolId'] ?? null) !== $this->school_id) {
                throw new RuntimeException('Template not found');
            }

            /* Objects may be supplied by the caller — that is the whole point
               when this is reached from a conflict: the copy must carry what is
               ON THEIR SCREEN, not what is stored, or duplicating to rescue
               your work would save someone else's instead. */
            $objects = json_decode((string) $this->input->post('objects'), true);
            $seed = [
                'name'             => (string) ($this->input->post('name')
                                      ?: (($src['name'] ?? 'Template') . ' (copy)')),
                'page'             => $src['page']   ?? [],
                'header'           => $src['header'] ?? [],
                'footer'           => $src['footer'] ?? [],
                'objects'          => is_array($objects) ? $objects : ($src['objects'] ?? []),
                'languages'        => $src['languages'] ?? ['en'],
                'defaultLanguage'  => $src['defaultLanguage'] ?? 'en',
                'contractRef'      => $src['contractRef'] ?? null,
                'complianceBasis'  => $src['complianceBasis']  ?? [],
                'complianceLayers' => $src['complianceLayers'] ?? [],
                'starterId'        => $src['starterId'] ?? null,
                'copiedFrom'       => $id,
            ];

            $r = $this->_templates()->create(
                (string) $this->school_id, (string) ($src['docType'] ?? ''), $seed, $this->_actor());

            log_audit(self::AUDIT_MODULE, 'template.duplicate', $r['templateId'],
                      'Copied from ' . $id);

            return ['templateId' => $r['templateId'], 'template' => $r['head']];
        });
    }

    private function _presence(): Doc_presence
    {
        if (!isset($this->docpres)) {
            $this->load->library('doc_presence', null, 'docpres');
        }
        return $this->docpres;
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

    /**
     * Mint a new draft. schoolId comes from the SESSION, never the body — the
     * panel uses the Admin SDK, which bypasses firestore.rules entirely, so a
     * client-supplied tenant would be honoured.
     */
    public function create(): void
    {
        if (!$this->_require_post()) return;

        $this->_run(function () {
            $asked   = (string) $this->input->post('docType');
            $docType = $this->_safe_type($asked);
            if ($docType === '') {
                /* SAY WHAT HAPPENED. This used to fall through to the service,
                   which answered "schoolId and docType are required" — a message
                   that names the wrong cause and sends the reader to look at
                   their session. The type was simply not one this school may
                   create, and that is both the truth and something they can act
                   on. */
                throw new InvalidArgumentException(
                    $asked === ''
                        ? 'No document type was given.'
                        : "'$asked' is not a document type this school can create. A state-specific "
                          . 'form is offered only in the state that prescribes it; a custom document '
                          . 'must be created from the hub so it can be named.'
                );
            }

            $seed = json_decode((string) $this->input->post('seed'), true);
            if ($seed !== null && !is_array($seed)) {
                throw new InvalidArgumentException('seed must be a JSON object');
            }
            $seed = $seed ?: [];
            // A seed may carry a starter's layout. It may NOT carry identity,
            // ownership or lifecycle — those are the server's to set.
            foreach (['schoolId', 'templateId', 'status', 'version', 'lockVersion',
                      'publishedVersion', 'activeVersion', 'lastProof'] as $k) {
                unset($seed[$k]);
            }

            $r = $this->_templates()->create(
                (string) $this->school_id, $docType, $seed, $this->_actor()
            );
            log_audit(self::AUDIT_MODULE, 'template.create', $r['templateId'],
                      "Created $docType template");

            return ['templateId' => $r['templateId'], 'template' => $r['head']];
        });
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
            $out = $this->_templates()->save($id, $patch, (int) $lock, $this->_actor());

            /* PASS THE REJECTIONS THROUGH.
               save() drops any field that is not an editable part of the design —
               docType and version among them, both of which were exploitable — and
               reports which ones it dropped. This endpoint returned only the new
               lockVersion, so that report died here and the client was told
               "saved" with no hint that part of its request had been discarded.
               Answering success while silently discarding half the payload is the
               phantom-success shape this codebase already has a pattern entry for;
               it is not any better when the discarding is deliberate. */
            $res = ['lockVersion' => $out['lockVersion']];
            if (!empty($out['rejectedFields'])) {
                $res['rejectedFields'] = $out['rejectedFields'];
            }
            return $res;
        });
    }

    /**
     * Authoritative validation. The designer runs its own copy for live
     * feedback; THIS is the one publish is gated on.
     *
     * The client's copy cannot be the gate — it runs on the caller's machine
     * and answers to whoever is holding it. Both are kept in step by
     * DocContractParityTest.
     */
    public function validate(): void
    {
        if (!$this->_require_post()) return;

        $this->_run(function () {
            $tpl = json_decode((string) $this->input->post('template'), true);
            if (!is_array($tpl)) {
                throw new InvalidArgumentException('template must be a JSON object');
            }
            $docType = $this->_safe_type((string) ($tpl['docType'] ?? ''));

            $blocking = [];
            $warnings = [];

            /* 1 — every REQUIRED contract key is bound by some object. */
            $bound = $this->_boundKeys($tpl);
            foreach ($this->_contract()->keysFor($docType) as $key => $def) {
                if (!empty($def['required']) && !in_array($key, $bound, true)) {
                    $blocking[] = ['type' => 'unbound', 'key' => $key,
                                   'message' => "Required field '{$def['label']}' is not on the template"];
                }
            }

            /* 2 — a text object with no line height. mPDF and the browser
                   resolve a missing line-height differently, so the proof and
                   the preview would disagree about where the text sits. */
            foreach ($this->_objects($tpl) as $o) {
                if (($o['type'] ?? '') === 'text' && empty($o['style']['lineHeight'])) {
                    $blocking[] = ['type' => 'lineheight', 'id' => (string) ($o['id'] ?? '?'),
                                   'message' => 'Text object has no line height'];
                }
            }

            /* 3 — an image object with no source.
                   The serializer refuses to render one, so without this the
                   only symptom is a proof that fails after the fact. The
                   shipped Annexure-I starter carries a School crest object,
                   so every new TC template hits this until a crest is
                   uploaded — found on the first live run. */
            foreach ($this->_objects($tpl) as $o) {
                if (($o['type'] ?? '') === 'image' && empty($o['content']['src'])) {
                    $warnings[] = ['type' => 'nosrc', 'id' => (string) ($o['id'] ?? '?'),
                                   'message' => 'Image "' . ($o['name'] ?? $o['id'] ?? 'object')
                                              . '" has no picture yet — it will not appear on the document'];
                }
            }

            /* 4 — contract-level checks on a sample bundle: off-contract keys
                   and over-length values. Over-length is a WARNING by design —
                   maxLen is our own estimate, and the real gate measures the
                   rendered block. */
            $r = $this->_contract()->validateBundle(
                $docType, $this->_contract()->sampleBundle($docType, true), $bound
            );
            foreach ($r['errors'] ?? [] as $e) {
                if (($e['type'] ?? '') === 'offContract') { $blocking[] = $e; }
            }
            $warnings = array_merge($warnings, $r['warnings'] ?? []);

            return ['blocking' => $blocking, 'warnings' => $warnings,
                    'ok' => $blocking === []];
        });
    }

    /** Every merge key bound by any object on the template. */
    private function _boundKeys(array $tpl): array
    {
        $keys = [];
        foreach ($this->_objects($tpl) as $o) {
            foreach ((array) ($o['content']['i18n'] ?? []) as $runs) {
                foreach ((array) $runs as $run) {
                    if (!empty($run['field'])) { $keys[(string) $run['field']] = true; }
                }
            }
        }
        return array_keys($keys);
    }

    /** Objects from the body plus header and footer regions. */
    private function _objects(array $tpl): array
    {
        return array_merge(
            (array) ($tpl['objects'] ?? []),
            (array) ($tpl['header']['objects'] ?? []),
            (array) ($tpl['footer']['objects'] ?? [])
        );
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
                    /* Browser sink. mPDF-only placeholders must resolve to what
                       the reader will actually see — the preview footer printed
                       the literal characters "{PAGENO}" where the PDF shows a
                       page number. proof_pdf() passes nothing and gets the PDF
                       form, because the default favours the legal artifact. */
                    'forPdf'      => false,
                ]),
                'lang'   => $lang,
                'sample' => $mode,
            ];
        });
    }

    /**
     * Render a real proof PDF and put it ON RECORD.
     *
     * The template is read from the STORE, never from the request body. That is
     * the whole point: publish() verifies that the proof on record still
     * describes the stored design, so a proof rendered from a caller-supplied
     * document would describe something that was never saved.
     *
     * One proof per declared language — a template can be correct in English
     * and overflow in Hindi, and only the language you rendered would have told
     * you.
     */
    public function proof_pdf(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');

        $this->_run(function () use ($id) {
            $tpl = $this->fs->get('documentTemplates', $id);
            if (!is_array($tpl) || ($tpl['schoolId'] ?? null) !== $this->school_id) {
                throw new RuntimeException('Template not found');
            }

            $this->load->library('doc_serializer', null, 'docser');
            $contract = $this->_contract()->get((string) ($tpl['docType'] ?? ''));

            $dir = FCPATH . 'uploads/' . $this->school_id . '/doctemplates/_proofs';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create the proof directory');
            }

            $version  = (int) ($tpl['version'] ?? 1);

            /* DEFENCE IN DEPTH — refuse to render onto a published version's file.
             *
             * The proof filename is built from the LIVE head's version, and this
             * method writes it unconditionally. That is fine while the head's
             * version is ahead of what has been published, which is the only
             * state save() can now produce. It was NOT fine when save() would
             * accept a `version` field: setting it back to an already-published
             * number made this write land on the exact path recorded inside that
             * version's immutable snapshot, replacing the artefact a school
             * downloads while the snapshot still read as untouched.
             *
             * save() no longer accepts `version`, so the chain is already broken
             * upstream. This guard exists because a P0 should not depend on one
             * allowlist staying correct forever — and because the invariant is
             * worth stating where the write happens, not only where the input is
             * filtered. */
            $publishedVersion = $tpl['publishedVersion'] ?? null;
            if ($publishedVersion !== null && $version <= (int) $publishedVersion) {
                throw new RuntimeException(
                    "Refusing to render a proof at version $version: version "
                    . "$publishedVersion of this template is already published, and its "
                    . 'PDF is the record of what a certificate issued from it said. '
                    . 'Rendering here would overwrite that record. Edit the draft first — '
                    . 'a normal edit moves the version forward.'
                );
            }

            $paths    = [];
            $pages    = 0;
            $perLang  = [];
            $bytesAll = '';

            foreach ((array) ($tpl['languages'] ?? ['en']) as $lang) {
                $lang = (string) $lang;
                $html = $this->docser->render($tpl, [], $lang, [
                    'contract' => $contract,
                    'sample'   => 'p95',      // the hard case, not the flattering one
                ]);

                $pdf = $this->docpdf->render($html, (array) ($tpl['page'] ?? []));
                // NOT pageCount() — that renders the document all over again.
                $n   = $this->docpdf->lastPageCount();

                $safeLang = preg_replace('/[^a-z]/', '', strtolower($lang)) ?: 'x';
                $file = $dir . '/' . basename($id) . '_v' . $version . '_' . $safeLang . '.pdf';
                if (file_put_contents($file, $pdf) === false) {
                    throw new RuntimeException('Could not write the proof PDF');
                }

                $paths[$lang]   = 'uploads/' . $this->school_id . '/doctemplates/_proofs/' . basename($file);
                $perLang[$lang] = ['pages' => $n, 'bytes' => strlen($pdf),
                                   'hash' => 'sha256:' . hash('sha256', $pdf)];
                $pages   += $n;
                $bytesAll .= $pdf;
            }

            /* One hash over every language, in the order the template declares
               them. A per-language hash alone could not answer "is this the
               same document I published?" for a bilingual certificate. */
            $rec = $this->_templates()->recordProof($id, [
                'hash'         => 'sha256:' . hash('sha256', $bytesAll),
                'fontManifest' => $this->docpdf->fontManifest(),
                'mpdfVersion'  => $this->docpdf->engineVersion(),
                'pages'        => $pages,
                'pdfPaths'     => $paths,
                'perLanguage'  => $perLang,
            ], $this->_actor(), $tpl);   // $tpl is already loaded — do not re-read it

            log_audit(self::AUDIT_MODULE, 'template.proof', $id,
                      'Proof rendered v' . $version . ' — ' . $rec['hash']);

            return ['proof' => $rec, 'paths' => $paths, 'perLanguage' => $perLang];
        });
    }

    /** Image types a certificate may carry, by CONTENT not by file name. */
    const ASSET_MIME = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** 4 MB. A crest or a signature; anything larger is a scanned page. */
    const ASSET_MAX_BYTES = 4194304;

    /** 40 megapixels. A full A4 page at 600 DPI is ~35 MP, so this bounds memory
     *  without constraining anything a school would legitimately upload. */
    const ASSET_MAX_PIXELS = 40000000;

    /**
     * Upload a crest or signature.
     *
     * The type is decided by INSPECTING the file, never by its extension or by
     * the browser-supplied Content-Type — both are caller-controlled, and a
     * .png that is really a PHP script inside a web-served directory is a
     * remote-code-execution path, not a rendering bug.
     *
     * The stored name is derived from the file's own content hash, so a
     * caller cannot choose a path, collide with an existing asset, or traverse
     * out of the school's directory.
     */
    public function upload_asset(): void
    {
        if (!$this->_require_post()) return;

        $this->_run(function () {
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('No file was uploaded');
            }
            if (!is_uploaded_file($f['tmp_name'])) {
                throw new RuntimeException('Not an uploaded file');
            }
            if (($f['size'] ?? 0) > self::ASSET_MAX_BYTES) {
                throw new InvalidArgumentException(
                    'Image is larger than ' . (self::ASSET_MAX_BYTES / 1048576) . ' MB'
                );
            }

            // Content sniffing, and then a second opinion: getimagesize also
            // has to agree it is a real raster image with real dimensions.
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
            if (!isset(self::ASSET_MIME[$mime])) {
                throw new InvalidArgumentException(
                    'Only PNG, JPEG and WebP images are accepted (this file is ' . ($mime ?: 'unrecognised') . ')'
                );
            }
            $info = @getimagesize($f['tmp_name']);
            if (!$info || empty($info[0]) || empty($info[1])) {
                throw new InvalidArgumentException('That file is not a readable image');
            }

            /* A PIXEL CAP, because the byte cap does not bound memory.
             *
             * Compressed size and decompressed size are unrelated. A 12000x12000
             * PNG of one flat colour is **17 KB on disk** — comfortably inside the
             * 4 MB cap, correctly sniffed as image/png, and perfectly readable by
             * getimagesize. Decompressed it is 144 megapixels, about 549 MB in
             * memory, against Doc_renderer's 96 MB ceiling. Measured, not
             * estimated. Any `edit`-grade user could upload it and then kill the
             * PHP worker on every render that touched it.
             *
             * 40 MP is far beyond any legitimate crest, signature or student
             * photo — a full A4 page at 600 DPI is about 35 MP — so the cap costs
             * real users nothing and the refusal names the actual limit. */
            $pixels = (int) $info[0] * (int) $info[1];
            if ($pixels > self::ASSET_MAX_PIXELS) {
                throw new InvalidArgumentException(sprintf(
                    'That image is %d x %d (%.0f megapixels), larger than the %d megapixel limit. '
                    . 'File size is not the constraint — a small file can still be enormous once '
                    . 'decompressed. Resize it and upload again.',
                    $info[0], $info[1], $pixels / 1000000, self::ASSET_MAX_PIXELS / 1000000
                ));
            }

            $dir = FCPATH . 'uploads/' . $this->school_id . '/doctemplates/assets';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create the asset directory');
            }

            $name = hash_file('sha256', $f['tmp_name']) . '.' . self::ASSET_MIME[$mime];
            $dest = $dir . '/' . $name;
            if (!is_file($dest) && !move_uploaded_file($f['tmp_name'], $dest)) {
                throw new RuntimeException('Could not store the image');
            }
            @chmod($dest, 0644);

            // Relative, and with no scheme — Doc_serializer::guardSrc() rejects
            // anything else, and this path is what it will be handed.
            $src = 'uploads/' . $this->school_id . '/doctemplates/assets/' . $name;
            log_audit(self::AUDIT_MODULE, 'template.asset_upload', $name, "Uploaded $mime asset");

            return ['src' => $src, 'width' => (int) $info[0], 'height' => (int) $info[1],
                    'mime' => $mime, 'bytes' => (int) $f['size']];
        });
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
            $b = $this->_blocks()->save($id, $data, $this->_actor());
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
            /* No proof is read from the request. The server renders it in
               proof_pdf() and publish() verifies what is on record still
               describes the stored design — a caller-supplied proof would let
               anyone publish a snapshot whose hash no PDF ever produced. */
            $r = $this->_templates()->publish($id, $this->_actor());
            /* lockVersion moves on every lifecycle write. Without returning it
               the client keeps the stale one and its next autosave conflicts —
               which presented as an "someone else saved this" dialog that
               reappeared after every keystroke, for a single user working
               alone. */
            return ['versionId'   => $r['versionId'],
                    'version'     => $r['version'],
                    'lockVersion' => $r['head']['lockVersion'] ?? null];
        });
    }

    public function activate(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.activate_attempt', $id, 'Activate requested');

        $this->_run(function () use ($id) {
            /* An explicit version means a ROLLBACK to an earlier published
               version. Null means the newest. The service validates the number
               and refuses one whose frozen snapshot is missing. */
            $v = $this->input->post('version');
            $version = ($v === null || $v === '') ? null : (int) $v;

            return $this->_templates()->activate($id, $this->_actor(), $version);
        });
    }

    /**
     * Clear the active pointer for this document type.
     *
     * The UI offered this from the start with no endpoint behind it — the
     * client deleted its own copy of the pointer and said it was done.
     */
    public function deactivate(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.deactivate_attempt', $id, 'Deactivate requested');

        $this->_run(fn() => $this->_templates()->deactivate($id, $this->_actor()));
    }

    /**
     * Delete a never-published draft. The service refuses anything else — a
     * published version is the record of what an issued certificate said.
     */
    public function delete(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.delete_attempt', $id, 'Delete requested');

        $this->_run(fn() => $this->_templates()->delete($id, $this->_actor()));
    }

    public function archive(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.archive_attempt', $id, 'Archive requested');

        $this->_run(fn() => $this->_templates()->archive($id, $this->_actor()));
    }

    // ═══════════════════════════════════════════════════════════════════

    /** v1 document types. Widened from the research corpus in v2. */
    /**
     * Accept a document type, or nothing.
     *
     * THIS WAS A HARDCODED LIST OF THREE — transfer_certificate, bonafide,
     * character — while the catalogue declared seven. Every other type fell
     * through to '', and `create` then rejected it with "schoolId and docType
     * are required", a message that names neither the type nor the real cause.
     * Kerala's Form 5A, Kerala's r.22A certificate, the A.P. Study Certificate
     * and the Fee Receipt could not be created AT ALL, and the error blamed a
     * missing school id. Verified live: bonafide created, study and fee_receipt
     * both refused.
     *
     * It now asks the catalogue, so a type added to `doc_types.php` works
     * without anyone remembering this method exists. Still fail-closed: an
     * unrecognised type returns '' exactly as before.
     */
    private function _safe_type(string $t): string
    {
        if ($t === '') {
            return '';
        }
        try {
            // Load the library BEFORE the static call — it is loaded lazily, so
            // the class need not be declared yet on the first request that hits
            // a custom type.
            $contract = $this->_contract();
            // A custom type is validated by its shape; nothing declares it centrally.
            if ($contract::isCustom($t)) {
                return $t;
            }
            return $contract->typeAvailable($t, $this->_school_context()['state']) ? $t : '';
        } catch (Throwable $e) {
            log_message('error', 'Doc_templates::_safe_type — ' . $e->getMessage());
            return '';
        }
    }
}
