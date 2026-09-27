<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');

/**
 * REG-P6-R1 — THE ENTITLEMENT CAPTURE CONTRACT, ENFORCED REPO-WIDE.
 *
 * What went wrong. `_entitlements_capture.php` is included by three hosts, and
 * two of them submit through its own AJAX `propose()`. The third — direct
 * admission — submits the entitlement WITH the admission form, so it scraped
 * the panel's DOM and rebuilt the scope string itself. When F-3 replaced the
 * free-text `ecScopeTarget` with the registry pickers, that copy kept reading
 * the deleted element, the target was always empty, and every head- and
 * category-scoped entitlement submitted at admission was SILENTLY DROPPED.
 *
 * Why the P6 tests missed it: they asserted `ecScopeTarget` was absent FROM THE
 * PARTIAL. The contract was repo-wide; the guard was file-scoped.
 *
 * So these tests are deliberately REPO-WIDE. They scan every view for stale
 * references to capture elements that no longer exist, and they pin the rule
 * that a host must obtain its scope from the one implementation rather than
 * rebuilding it. That is the class of defect, not just this instance.
 */
class EntitlementCaptureContractTest extends TestCase
{
    private const PARTIAL = APPPATH . 'views/_entitlements_capture.php';
    private const ADMIT   = APPPATH . 'views/studentAdmission.php';

    /** Every element id the capture panel currently defines. */
    private function definedIds(): array
    {
        preg_match_all('/id="(ec[A-Za-z]+)"/', file_get_contents(self::PARTIAL), $m);
        return array_values(array_unique($m[1]));
    }

    /** JS with comments stripped — a comment may legitimately NAME a dead id. */
    private function liveJs(string $file): string
    {
        $src = file_get_contents($file);
        $src = preg_replace('#/\*.*?\*/#s', ' ', $src);           // block comments
        $src = preg_replace('#^\s*//.*$#m', ' ', $src);           // line comments
        return (string) $src;
    }

    /**
     * Every view that actually INCLUDES the capture partial.
     *
     * Matched on the include statement, not a bare filename mention — a doc
     * comment that names the partial is not a host, and `ec*` ids elsewhere in
     * the codebase (e.g. `ecBackBtn` in the exam module) belong to other
     * components entirely. The capture contract applies to hosts only.
     */
    private function hostViews(): array
    {
        $hosts = [];
        foreach (array_merge(glob(APPPATH . 'views/*.php'), glob(APPPATH . 'views/*/*.php')) as $f) {
            if (preg_match("#include\s+APPPATH\s*\.\s*[\"']views/_entitlements_capture\.php#", file_get_contents($f))) {
                $hosts[] = $f;
            }
        }
        return $hosts;
    }

    // ══ THE REGRESSION GUARD ═════════════════════════════════════════════

    /**
     * No view may reference a capture element the partial does not define.
     * This is the assertion that would have caught REG-P6-R1 on the day.
     */
    public function test_no_view_references_a_deleted_capture_element(): void
    {
        $defined = $this->definedIds();
        $this->assertNotEmpty($defined, 'the partial must define capture ids');

        foreach ($this->hostViews() as $file) {
            preg_match_all("/getElementById\(\s*'(ec[A-Za-z]+)'\s*\)/", $this->liveJs($file), $m);
            foreach (array_unique($m[1]) as $id) {
                $this->assertContains($id, $defined,
                    basename($file) . " references '{$id}', which the capture partial no longer defines — "
                    . 'this is exactly how REG-P6-R1 silently dropped entitlements.');
            }
        }
    }

    public function test_the_retired_elements_are_gone_from_every_view(): void
    {
        foreach (array_merge(glob(APPPATH . 'views/*.php'), glob(APPPATH . 'views/*/*.php')) as $file) {
            $js = $this->liveJs($file);
            $this->assertStringNotContainsString('ecScopeTarget', $js, basename($file));  // F-3
            $this->assertStringNotContainsString('ecFrequency',   $js, basename($file));  // P2-B.1
        }
    }

    /** The free-text picker must not come back. */
    public function test_the_free_text_head_picker_is_not_restored(): void
    {
        $p = file_get_contents(self::PARTIAL);
        $this->assertStringNotContainsString('e.g. transport / Tuition', $p);
        $this->assertStringContainsString('<select id="ecScopeHead">', $p);
        $this->assertStringContainsString('<select id="ecScopeCat">', $p);
    }

    // ══ ONE CONTRACT, NOT TWO ════════════════════════════════════════════

    public function test_the_partial_exposes_the_single_scope_implementation(): void
    {
        $p = file_get_contents(self::PARTIAL);
        $this->assertStringContainsString('scopeValue: scopeValue', $p);
        $this->assertStringContainsString('scopeReady: function ()', $p);
    }

    /**
     * A host must ASK for the scope, never rebuild it. Rebuilding is what let
     * the two copies drift apart in the first place.
     */
    public function test_the_admission_bridge_uses_the_shared_contract(): void
    {
        $js = $this->liveJs(self::ADMIT);
        $this->assertStringContainsString("formData.append('ent_scope',           window.ENTCAP.scopeValue());", $js);
        $this->assertStringContainsString('window.ENTCAP.scopeReady()', $js);
        $this->assertDoesNotMatchRegularExpression("/scopeKind\s*\+\s*':'/", $js,
            'the bridge must not rebuild the scope string itself');
    }

    public function test_the_bridge_no_longer_sends_a_retired_frequency(): void
    {
        $this->assertStringNotContainsString('ent_frequency', $this->liveJs(self::ADMIT));

        $php = file_get_contents(APPPATH . 'controllers/Sis.php');
        $code = '';
        foreach (token_get_all($php) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringNotContainsString('ent_frequency', $code,
            'the server side of the same obsolete dependency');
    }

    // ══ WHAT MUST STILL SURVIVE A DIRECT ADMISSION ═══════════════════════

    /**
     * The three scope shapes, as the bridge now produces them. `scopeValue()`
     * is reproduced here from the partial's own source so the expectation
     * cannot silently diverge from the implementation.
     */
    public function test_all_head_and_category_scopes_all_survive_the_bridge(): void
    {
        $p = file_get_contents(self::PARTIAL);
        $this->assertStringContainsString("if (k === 'all')      return 'all';", $p);
        $this->assertStringContainsString("if (k === 'head')     return 'head:' + (\$('ecScopeHead').value || '').trim();", $p);
        $this->assertStringContainsString("if (k === 'category') return 'category:' + (\$('ecScopeCat').value || '').trim();", $p);

        // …and the bridge forwards whatever that returns, unmodified.
        $this->assertStringContainsString("window.ENTCAP.scopeValue()", $this->liveJs(self::ADMIT));
    }

    /** The half-filled-panel guard must still ignore rather than fail the admission. */
    public function test_a_half_filled_panel_is_ignored_not_submitted(): void
    {
        $js = $this->liveJs(self::ADMIT);
        $this->assertStringContainsString('if (!window.ENTCAP.scopeReady()) return;', $js);
        $this->assertStringContainsString("if (!fromEl.value || !rsnEl.value.trim()) return;", $js);
    }

    // ══ THE OTHER HOSTS ══════════════════════════════════════════════════

    /**
     * CRM walk-in and Student 360 submit through the panel's own propose(), so
     * they hold no scope logic and cannot drift. Pin that, so a future host
     * that DOES rebuild a scope is noticed.
     */
    public function test_crm_and_student360_hold_no_scope_logic_of_their_own(): void
    {
        foreach ([APPPATH . 'views/admission_crm/applications.php',
                  APPPATH . 'views/student360/workspace.php'] as $f) {
            $js = $this->liveJs($f);
            $this->assertStringNotContainsString('ent_scope', $js, basename($f));
            $this->assertStringNotContainsString("'head:'", $js, basename($f));
            $this->assertStringNotContainsString("'category:'", $js, basename($f));
        }
    }

    /**
     * The capture partial has THREE hosts. The public admission form is NOT one
     * of them: an unauthenticated family DECLARES a situation
     * (`_entitlement_declarations.php`) and can never create a grant — that
     * separation is a security boundary, so it is pinned here too.
     */
    public function test_the_capture_partial_keeps_exactly_its_three_hosts(): void
    {
        $hosts = array_map('basename', $this->hostViews());
        sort($hosts);
        $this->assertSame(['applications.php', 'studentAdmission.php', 'workspace.php'], $hosts,
            'a NEW host means a new place the scope contract can drift — add it to this test');

        $public = file_get_contents(APPPATH . 'views/admission/public_form.php');
        $this->assertStringContainsString("views/_entitlement_declarations.php", $public);
        $this->assertStringNotContainsString("views/_entitlements_capture.php", $public,
            'the public form declares; it must never capture');
    }

    // ══ SCOPE HELD ═══════════════════════════════════════════════════════

    public function test_phase_A_changed_no_identity_or_financial_semantics(): void
    {
        $svc = file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
        $this->assertStringContainsString(
            "return \$this->schoolId . '_' . \$studentId . '_E' . substr(sha1(implode('|', \$parts)), 0, 20);", $svc,
            '_fingerprintId must be byte-identical — Phase A touches no identity');

        $this->assertStringContainsString("'grossAmount' => \$amount",
            file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'),
            'DEC-001 untouched');

        $this->assertStringContainsString("\$config['USE_ENTITLEMENT_BILLING'] = true",
            file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'));
    }
}
