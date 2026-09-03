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
        'get_versions'   => 'view',
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
            $docType = $this->_safe_type((string) $this->input->post('docType'));

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
            return ['lockVersion' => $out['lockVersion']];
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

    public function archive(): void
    {
        if (!$this->_require_post()) return;
        $id = $this->safe_path_segment((string) $this->input->post('templateId'), 'templateId');
        log_audit(self::AUDIT_MODULE, 'template.archive_attempt', $id, 'Archive requested');

        $this->_run(fn() => $this->_templates()->archive($id, $this->_actor()));
    }

    // ═══════════════════════════════════════════════════════════════════

    /** v1 document types. Widened from the research corpus in v2. */
    private function _safe_type(string $t): string
    {
        $allowed = ['transfer_certificate', 'bonafide', 'character'];
        return in_array($t, $allowed, true) ? $t : '';
    }
}
