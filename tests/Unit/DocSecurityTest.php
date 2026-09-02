<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_serializer;
use Doc_renderer;
use ReflectionClass;
use RuntimeException;

/**
 * Document Engine — security surface (P9.6).
 *
 * Three attack shapes, each with a real consequence in this system:
 *
 *  1. IMAGE SOURCE. mPDF fetches remote images SERVER-SIDE, so an unvalidated
 *     `src` is an SSRF primitive against the Ohio box. On the preview path the
 *     same value is a request to a third party from the school's browser, made
 *     by a document nobody thought was networked. The two paths are guarded
 *     SEPARATELY because the browser preview never passes through Doc_renderer.
 *
 *  2. SCRIPT INJECTION through content. Certificate text is authored by staff
 *     and rendered into HTML; an unescaped run is stored XSS in the panel.
 *
 *  3. CSRF. `publish` and `activate` change which certificate a school legally
 *     issues. A forged cross-site POST that flipped the active Transfer
 *     Certificate template would be silent and consequential, which is why
 *     nothing here is in `csrf_exclude_uris`.
 */
class DocSecurityTest extends TestCase
{
    private Doc_serializer $s;

    public static function setUpBeforeClass(): void
    {
        foreach (['BASEPATH' => __DIR__, 'FCPATH' => __DIR__ . '/', 'APPPATH' => __DIR__ . '/'] as $k => $v) {
            if (!defined($k)) {
                define($k, $v);
            }
        }
        require_once __DIR__ . '/../../application/libraries/Doc_serializer.php';
        require_once __DIR__ . '/../../application/libraries/Doc_renderer.php';
    }

    protected function setUp(): void
    {
        $this->s = new Doc_serializer();
    }

    private function tplWithImage(string $src): array
    {
        return [
            'templateId' => 'TPL1', 'docType' => 'transfer_certificate', 'languages' => ['en'],
            'page' => ['size' => 'A4'],
            'objects' => [[
                'id' => 'img', 'type' => 'image', 'xMm' => 10, 'yMm' => 10, 'wMm' => 30,
                'z' => 1, 'height' => 'auto', 'content' => ['src' => $src],
            ]],
        ];
    }

    /* ---------------------------------------------------------------- *
     * 1 — image source, PREVIEW path (Doc_serializer)
     * ---------------------------------------------------------------- */

    /** @dataProvider hostileSources */
    public function test_the_serializer_rejects_a_hostile_image_source(string $src, string $why): void
    {
        $this->expectException(RuntimeException::class);
        $this->s->render($this->tplWithImage($src), [], 'en');
    }

    public static function hostileSources(): array
    {
        return [
            'remote http'       => ['http://evil.example/p.gif', 'SSRF on the PDF path, tracking pixel on the preview'],
            'remote https'      => ['https://evil.example/p.gif', 'same'],
            'file scheme'       => ['file:///etc/passwd', 'local file disclosure'],
            'php wrapper'       => ['php://filter/read=convert.base64-encode/resource=index.php', 'source disclosure'],
            'data url'          => ['data:text/html,<script>alert(1)</script>', 'inline payload'],
            // javascript: and data: carry no "//", so a scheme:// test alone
            // misses them — which is exactly how these usually slip through.
            'javascript scheme' => ['javascript:alert(1)', 'no // — a scheme:// test would miss it'],
            'protocol relative' => ['//evil.example/p.gif', 'inherits the page scheme'],
            'parent traversal'  => ['../../../etc/passwd', 'escapes the storage root'],
        ];
    }

    public function test_a_plain_storage_path_is_accepted(): void
    {
        $html = $this->s->render($this->tplWithImage('schools/SCH1/doctemplates/crest.png'), [], 'en');
        $this->assertStringContainsString('schools/SCH1/doctemplates/crest.png', $html);
    }

    /** An entity-encoded scheme must not slip past the decode. */
    public function test_an_entity_encoded_scheme_is_still_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->s->render($this->tplWithImage('java&#115;cript:alert(1)'), [], 'en');
    }

    /* ---------------------------------------------------------------- *
     * 1b — image source, PDF path (Doc_renderer)
     * ---------------------------------------------------------------- */

    /** @dataProvider hostileSources */
    public function test_the_renderer_also_rejects_a_hostile_image_source(string $src, string $why): void
    {
        $rc = new ReflectionClass(Doc_renderer::class);
        $r  = $rc->newInstanceWithoutConstructor();

        $roots = $rc->getProperty('imageRoots');
        $roots->setValue($r, [__DIR__]);

        $guard = $rc->getMethod('guardImages');

        $this->expectException(RuntimeException::class);
        $guard->invoke($r, '<img src="' . $src . '">');
    }

    /** Both guards must exist. Losing either leaves one path unprotected. */
    public function test_both_paths_carry_a_guard(): void
    {
        $this->assertTrue(
            (new ReflectionClass(Doc_serializer::class))->hasMethod('guardSrc'),
            'the preview path lost its guard — the browser never passes through Doc_renderer'
        );
        $this->assertTrue(
            (new ReflectionClass(Doc_renderer::class))->hasMethod('guardImages'),
            'the PDF path lost its guard — mPDF fetches remote images server-side'
        );
    }

    /* ---------------------------------------------------------------- *
     * 2 — script injection through authored content
     * ---------------------------------------------------------------- */

    public function test_script_tags_in_authored_text_are_escaped(): void
    {
        $tpl = [
            'templateId' => 'TPL1', 'docType' => 'transfer_certificate', 'languages' => ['en'],
            'page' => ['size' => 'A4'],
            'objects' => [[
                'id' => 't', 'type' => 'text', 'xMm' => 10, 'yMm' => 10, 'wMm' => 50,
                'z' => 1, 'height' => 'auto', 'style' => ['lineHeight' => 1.4],
                'content' => ['i18n' => ['en' => ['runs' => [
                    ['t' => '<script>alert(1)</script>'],
                    ['t' => '" onmouseover="alert(2)'],
                ]]]],
            ]],
        ];
        $html = $this->s->render($tpl, [], 'en');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onmouseover="alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** A resolved merge VALUE is attacker-influenced too — a student's name. */
    public function test_a_resolved_merge_value_is_escaped(): void
    {
        $tpl = [
            'templateId' => 'TPL1', 'docType' => 'transfer_certificate', 'languages' => ['en'],
            'page' => ['size' => 'A4'],
            'objects' => [[
                'id' => 't', 'type' => 'text', 'xMm' => 10, 'yMm' => 10, 'wMm' => 50,
                'z' => 1, 'height' => 'auto', 'style' => ['lineHeight' => 1.4],
                'content' => ['i18n' => ['en' => ['runs' => [['f' => 'student.fullName']]]]],
            ]],
        ];
        $html = $this->s->render($tpl, ['student.fullName' => '<img src=x onerror=alert(1)>'], 'en',
                                 ['contract' => ['student.fullName' => ['label' => 'Name', 'sample' => 'A']]]);

        /* The danger is an UNESCAPED tag, not the literal text "onerror=" — a
           correctly escaped payload still contains that substring harmlessly,
           and asserting on it fails against correct behaviour. What must be
           true: the payload appears only in escaped form, and no new element
           was introduced into the body. */
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html,
            'the value must appear escaped, in full');

        $body = substr($html, (int) strpos($html, '</style>'));
        $this->assertSame(
            0,
            preg_match('/<img\b/i', $body),
            'an <img> element was introduced by a merge value — that is stored XSS'
        );
    }

    /* ---------------------------------------------------------------- *
     * 3 — CSRF
     * ---------------------------------------------------------------- */

    /**
     * Gate G0.7 verified live that a POST without a token 403s. This asserts
     * the CONFIGURATION that makes that true has not been quietly relaxed —
     * the usual way it goes wrong is someone adding a route to
     * csrf_exclude_uris to fix a "blank page" and never taking it out.
     */
    public function test_no_doc_templates_route_is_excluded_from_csrf(): void
    {
        $cfg = file_get_contents(__DIR__ . '/../../application/config/config.php');
        $this->assertNotFalse($cfg);

        preg_match("/\\\$config\\['csrf_exclude_uris'\\]\s*=\s*\[(.*?)\];/s", $cfg, $m);
        $list = $m[1] ?? '';

        $this->assertStringNotContainsString('doc_templates', $list,
            'a doc_templates route was excluded from CSRF. publish and activate change which '
            . 'certificate a school legally issues; a forged cross-site POST must not reach them.');
    }

    /** The controller must still declare a capability for every endpoint. */
    public function test_every_endpoint_declares_a_capability(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Doc_templates.php');
        $this->assertNotFalse($src);

        preg_match_all('/public function ([a-z_][a-zA-Z0-9_]*)\(/', $src, $m);
        $endpoints = array_filter($m[1], fn($n) => !in_array($n, ['__construct', '_remap'], true));

        preg_match("/const CAPABILITIES = \[(.*?)\];/s", $src, $c);
        $caps = $c[1] ?? '';
        $this->assertNotSame('', $caps, 'CAPABILITIES map not found');

        foreach ($endpoints as $e) {
            $this->assertStringContainsString("'$e'", $caps,
                "endpoint '$e' has no capability declared. _remap() refuses an undeclared "
                . 'endpoint, so this would fail closed — but silently, as a 403 nobody expected.');
        }
    }

    /* ---------------------------------------------------------------- *
     * P9.3 — resource caps and TYPED error codes
     * ---------------------------------------------------------------- */

    /**
     * Every cap is derived from the G0.6 measurement, not guessed, and the
     * comments say so. A cap nobody can trace back to a measurement gets
     * "temporarily" raised the first time it fires.
     */
    public function test_every_resource_cap_is_declared_and_sane(): void
    {
        $this->assertSame('96M', Doc_renderer::MAX_MEMORY, '26MB measured x ~3.7 headroom');
        $this->assertGreaterThanOrEqual(10, Doc_renderer::MAX_SECONDS);
        $this->assertGreaterThanOrEqual(2, Doc_renderer::MAX_PAGES,
            'a worst-case TC is 2 pages; a cap below that would reject valid documents');
        $this->assertSame(0xFF, Doc_renderer::OTL_ALL_SCRIPTS,
            'bit 0x80 drives complex-script shaping — without it Indic matras render in the wrong order');
    }

    /**
     * Failures carry a TYPED code, not just prose. A caller that has to
     * substring-match an English sentence to tell "too big" from "bad image"
     * will get it wrong the first time the wording is improved.
     */
    public function test_failures_carry_typed_error_codes(): void
    {
        $codes = [];
        foreach (['Doc_renderer', 'Doc_serializer', 'Doc_template_service'] as $cls) {
            $src = file_get_contents(__DIR__ . "/../../application/libraries/$cls.php");
            preg_match_all('/\bE_[A-Z_]+/', $src, $m);
            foreach ($m[0] as $c) {
                $codes[$c] = true;
            }
        }
        $codes = array_keys($codes);

        $this->assertContains('E_PAGE_OVERFLOW', $codes);
        $this->assertContains('E_IMAGE_SOURCE', $codes);
        $this->assertContains('E_CONFLICT', $codes);

        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^E_[A-Z][A-Z_]*$/', $c,
                "malformed error code '$c' — codes are matched by callers");
        }
    }

    /** The image guard must raise its own code, not a bare exception. */
    public function test_the_image_guard_raises_its_typed_code(): void
    {
        $rc = new ReflectionClass(Doc_renderer::class);
        $r  = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('imageRoots')->setValue($r, [__DIR__]);

        try {
            $rc->getMethod('guardImages')->invoke($r, '<img src="https://evil.example/p.gif">');
            $this->fail('a remote image must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('E_IMAGE_SOURCE:', $e->getMessage());
        }
    }

    /**
     * P9.4, the half that is assertable here: a critical failure must never
     * return a success response. Every failure path in the engine THROWS —
     * none returns an empty string, a partial document, or a null a caller
     * could mistake for output.
     */
    public function test_no_engine_failure_path_returns_instead_of_throwing(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Doc_serializer.php');

        // Every failure message in this class is raised, never returned.
        preg_match_all("/return\s+['\"](E_|Doc_serializer:)/", $src, $m);
        $this->assertSame([], $m[0],
            'a failure was RETURNED rather than thrown — the caller would render it as content');
    }

    /* ---------------------------------------------------------------- *
     * Controller wiring — the gap no plan row tracks
     * ---------------------------------------------------------------- */

    /**
     * The services were fully tested while NOTHING CALLED THEM. That gap falls
     * between P1.6 (controller + routes) and Phase 6 (service), so no plan row
     * owns it and it would go unnoticed precisely because both sides look done.
     *
     * This pins which endpoints are live, so the number can only move
     * deliberately.
     */
    public function test_the_wired_endpoints_no_longer_return_a_pending_stub(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Doc_templates.php');

        $wired = ['get_types', 'get_templates', 'get_template', 'get_blocks', 'get_versions',
                  'create', 'save', 'validate', 'preview', 'proof_pdf', 'upload_asset',
                  'save_block', 'publish', 'activate', 'archive'];

        foreach ($wired as $name) {
            $i = strpos($src, "public function $name(): void");
            $this->assertNotFalse($i, "endpoint '$name' has gone missing");

            $j    = strpos($src, 'public function', $i + 20);
            $body = substr($src, $i, ($j === false ? strlen($src) : $j) - $i);

            $this->assertStringNotContainsString("'pending'", $body,
                "endpoint '$name' regressed to a stub — the service is tested but unreachable again");
        }
    }

    /**
     * NOTHING is stubbed any more — all 14 AJAX endpoints are wired.
     *
     * Kept as an assertion rather than deleted, and deliberately phrased as
     * "the set is empty" rather than "these four are stubs": a stub reappearing
     * is a silent loss of function, because a stub returns HTTP 200 with
     * `{status: 'success'}`. The client cannot tell it from a real answer, and
     * neither can a person clicking the button.
     */
    public function test_no_endpoint_is_a_stub_any_more(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Doc_templates.php');

        $stubs = [];
        preg_match_all('/public function ([a-z_][a-zA-Z0-9_]*)\(\): void/', $src, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as $k => [$name, $_]) {
            $i    = $m[0][$k][1];
            $j    = strpos($src, 'public function', $i + 20);
            $body = substr($src, $i, ($j === false ? strlen($src) : $j) - $i);
            if (str_contains($body, "'pending'")) {
                $stubs[] = $name;
            }
        }
        sort($stubs);

        $this->assertSame([], $stubs,
            'an endpoint went back to returning a pending stub. A stub answers 200 with '
            . "{status:'success'}, so the caller — and the person who clicked — cannot tell "
            . 'it from a real result. If this is deliberate, it needs to fail loudly instead.');
    }

    /* ================================================================== *
     *  A8 · P0-2 and P1 — exposure and image scoping
     * ================================================================== */

    /**
     * Proof PDFs must not be fetchable over HTTP.
     *
     * They are written to uploads/{schoolId}/doctemplates/_proofs/ with a
     * PREDICTABLE name, and Apache serves static files before CodeIgniter is
     * reached — the front-controller rewrite is guarded by `!-f`. So they were
     * reachable with no session and no RBAC by anyone who could guess a
     * schoolId and a template number. A rendered certificate carries the
     * school's letterhead, crest and signature graphics.
     */
    public function test_uploads_denies_web_access_to_rendered_pdfs(): void
    {
        $ht = __DIR__ . '/../../uploads/.htaccess';
        $this->assertFileExists($ht, 'uploads/ has no .htaccess — proof PDFs are web-readable');

        $src = file_get_contents($ht);
        $this->assertMatchesRegularExpression('/FilesMatch\s+"[^"]*\\\.pdf/i', $src,
            'uploads/.htaccess does not deny .pdf');
        $this->assertMatchesRegularExpression('/Require all denied|Deny from all/i', $src);
    }

    public function test_uploads_never_executes_php(): void
    {
        $src = file_get_contents(__DIR__ . '/../../uploads/.htaccess');
        $this->assertStringContainsString('php', $src,
            'everything under uploads/ is user-supplied; script execution must be off');
        $this->assertStringContainsString('-Indexes', $src, 'directory listing must be off');
    }

    /**
     * The renderer must have NO default image root.
     *
     * It used to seed the entire uploads tree, and allowImageRoot() only
     * appends — so the per-tenant scoping the controller performs was a no-op:
     * the broad root already satisfied every check, and a template author at
     * one school could embed a file from another school's uploads directory
     * into their own PDF.
     */
    public function test_the_renderer_has_no_default_image_root(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Doc_renderer.php');

        $this->assertDoesNotMatchRegularExpression(
            '/\$this->imageRoots\[\]\s*=\s*rtrim\(\s*FCPATH\s*\.\s*.uploads./',
            $src,
            'Doc_renderer seeds the whole uploads tree as an image root again. That makes '
            . 'the controller\'s per-tenant allowImageRoot() call a no-op, because '
            . 'allowImageRoot() appends rather than replaces.');
    }

    public function test_the_service_is_handed_its_tenant_from_the_session(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Doc_templates.php');

        // Single-quoted on purpose: a double-quoted PHP string interpolates
        // $this->school_id, and the test then reads its own property instead.
        $this->assertStringContainsString(
            '$this->load->library(\'doc_template_service\',', $src,
            'the lifecycle service must be constructed by the controller');
        $this->assertStringContainsString(
            '[\'schoolId\' => (string) $this->school_id]', $src,
            'the service must be handed the SESSION school id, so every lifecycle method is '
            . 'ownership-checked in ONE place rather than in each endpoint — which is how '
            . 'save, publish, activate and archive came to have no check at all');
    }

    /**
     * Every print point must name a module that actually exists in the RBAC
     * catalogue. Mine did not: the registry shipped Fee_management, Students,
     * Staff and Payroll, none of which are real keys. has_permission() does a
     * bare map lookup and fails closed on an unknown key, so wiring against
     * them would have denied EVERYONE, silently, with nothing to debug.
     */
    public function test_every_print_point_names_a_real_rbac_module(): void
    {
        $targets = include __DIR__ . '/../../application/config/document_targets.php';
        $json = json_decode(file_get_contents(__DIR__ . '/../../functions/rbac_modules.json'), true);

        $known = [];
        array_walk_recursive($json, function ($v) use (&$known) {
            if (is_string($v)) { $known[$v] = true; }
        });

        foreach ($targets as $key => $t) {
            $this->assertArrayHasKey($t['module'], $known,
                "print point '$key' names RBAC module '{$t['module']}', which is not in "
                . 'functions/rbac_modules.json. has_permission() fails closed on an unknown '
                . 'key, so this would deny everyone with no error to trace.');
        }
    }

    /**
     * Every endpoint must declare a capability. _remap() refuses one that does
     * not — fail-closed — but that turns a missing declaration into a 403 for
     * everyone rather than an obvious error, so it is worth catching here.
     */
    public function test_every_public_endpoint_declares_a_capability(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Doc_templates.php');

        preg_match('/const CAPABILITIES = \[(.*?)\n    \];/s', $src, $cm);
        $this->assertNotEmpty($cm, 'CAPABILITIES map not found');
        preg_match_all("/'([a-z_]+)'\s*=>/", $cm[1], $declared);

        preg_match_all('/\n    public function ([a-z_][a-zA-Z0-9_]*)\(/', $src, $methods);
        $public = array_filter($methods[1],
            fn($m) => !in_array($m, ['__construct', '_remap'], true) && $m[0] !== '_');

        $missing = array_diff($public, $declared[1]);
        $this->assertSame([], array_values($missing),
            'endpoint(s) with no declared capability: ' . implode(', ', $missing)
            . '. _remap() refuses them, so they would 403 for everyone with nothing to trace.');
    }
}
