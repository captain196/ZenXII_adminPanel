<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Fee_head_resolver')) {
    require_once APPPATH . 'libraries/Fee_head_resolver.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  MEALS-1 — A CONCESSION AGAINST A CHARGE THAT DOES NOT EXIST
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `category:` entitlement scopes were accepted with **no vocabulary check at
 * all**. `appliesTo()` matches the target against a demand's own `category`, so
 * `category:banana` produced a perfectly valid, approved, audited entitlement
 * that could never match anything — a silent no-op wearing the costume of an
 * approved concession.
 *
 * The reachable instance is MEALS, and the system had already decided against
 * it. `Fee_head_resolver::ASSIGNABLE` withholds the category and says why:
 *
 *   "'meals' is reserved but withheld: ZenX bills no meals today (no module,
 *    controller, collection or head exists), and offering it would let a school
 *    classify heads into a category nothing consumes."
 *
 * Verified against the call graph rather than taken on trust: `createModuleFee`
 * has exactly four production module producers — Transport, Hostel, IDCard and
 * StudentCharge. **There is no meals controller, service or collection.**
 *
 * That decision was made in one file and contradicted in three others. Two
 * admin views and `Entitlement_requests::REQUEST_TYPES` all offered Meals, so a
 * family could request free meals, a school could approve it, an auditor could
 * see it ACTIVE — and no bill would ever reflect it, because there is no meal
 * bill.
 *
 * ── THE LATER HAZARD ──────────────────────────────────────────────────────
 * Worse than inert. If meals billing is ever added, these pre-existing grants
 * activate RETROACTIVELY on their stored `effectiveFrom` dates and discount the
 * very first meal bills — nobody would connect that to a concession approved
 * years earlier against nothing.
 *
 * ── WHY THIS IS NOT A PRODUCT DECISION ────────────────────────────────────
 * The product decision was already taken and written down in `ASSIGNABLE`. This
 * change enforces it where it was being bypassed. The guard delegates to that
 * constant rather than restating the list, so the day a meals module exists,
 * adding 'meals' there re-enables everything with no other edit.
 */
class EntitlementCategoryScopeTest extends TestCase
{
    private static string $svc = '';

    public static function setUpBeforeClass(): void
    {
        self::$svc = (string) file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
    }

    /** Executable code only — the rationale comment names every category. */
    private function code(): string
    {
        $s = preg_replace('!/\*.*?\*/!s', '', self::$svc);
        return (string) preg_replace('!^\s*//.*$!m', '', (string) $s);
    }

    // ══ THE PREMISE ═════════════════════════════════════════════════════════

    /**
     * ZenX genuinely bills no meals. If that ever changes this whole file must
     * be re-derived rather than deleted.
     */
    public function test_control_no_module_raises_a_meal_charge(): void
    {
        $producers = [];
        foreach (['libraries', 'controllers', 'services'] as $dir) {
            $path = APPPATH . $dir;
            if (!is_dir($path)) continue;
            foreach (scandir($path) as $f) {
                if (substr($f, -4) !== '.php') continue;
                if (strpos($f, 'probe') !== false || strpos($f, 'verifier') !== false) continue;
                $src = (string) file_get_contents($path . DIRECTORY_SEPARATOR . $f);
                $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
                $src = (string) preg_replace('!^\s*//.*$!m', '', $src);
                if (preg_match("/createModuleFee\(\s*\\\$\w+\s*,\s*'(\w+)'/", $src, $m)) {
                    $producers[] = $m[1];
                }
            }
        }

        $this->assertNotEmpty($producers, 'no module fee producers found — the scan is broken');
        $this->assertNotContains('Meals', $producers,
            'a meals charge producer now exists. MEALS-1 refuses meals concessions precisely '
            . 'because nothing bills meals — re-derive this file and add meals to '
            . 'Fee_head_resolver::ASSIGNABLE.');
    }

    /** The authority this guard delegates to must still withhold meals. */
    public function test_control_the_resolver_still_withholds_meals(): void
    {
        $this->assertContains('meals', Fee_head_resolver::CATEGORIES,
            'meals is no longer even a known category');
        $this->assertNotContains('meals', Fee_head_resolver::ASSIGNABLE,
            'meals became assignable. If a meals module now exists that is correct — and this '
            . 'file should be re-derived rather than left asserting the opposite.');
    }

    // ══ THE GUARD ═══════════════════════════════════════════════════════════

    /** WOULD HAVE FAILED BEFORE: category scopes had no vocabulary check. */
    public function test_a_category_scope_is_validated_against_the_assignable_set(): void
    {
        $code = $this->code();

        $this->assertStringContainsString(
            "if (!in_array(\$cat, Fee_head_resolver::ASSIGNABLE, true)) {", $code,
            'a category-scoped entitlement is accepted without checking the category exists. '
            . 'category:banana would be approved, audited and never match anything.');
    }

    /**
     * It must delegate, not restate. A copied list is the drift PM-1 and this
     * fix both exist to prevent.
     */
    public function test_the_guard_delegates_rather_than_restating_the_list(): void
    {
        $code = $this->code();

        $this->assertStringNotContainsString("['academic', 'transport', 'hostel', 'administrative']", $code,
            'the assignable list was copied into Entitlement_service. It must reference '
            . 'Fee_head_resolver::ASSIGNABLE so adding a meals module needs one edit, not two.');
    }

    /**
     * Creation only — the DEC-004 placement, for the same reason: re-validating
     * a STORED scope at approve/amend time could retroactively refuse a record
     * that already exists.
     */
    public function test_the_guard_sits_on_the_creation_path_only(): void
    {
        $at = strpos(self::$svc, 'MEALS-1');
        $this->assertNotFalse($at, 'the MEALS-1 guard is gone');

        // It must live beside the DEC-004 head guard, which is creation-only.
        $dec004 = strpos(self::$svc, '$err = $this->_assertRegisteredHead(');
        $this->assertNotFalse($dec004);
        $this->assertLessThan($at, $dec004,
            'the category guard moved away from the creation-only validation block; confirm it '
            . 'cannot now retroactively refuse a stored scope at approve or amend time');
    }

    /**
     * The class is loaded lazily elsewhere, so a `category:` scope can be the
     * first thing in a request to reference it. Without the require, this guard
     * would fatal instead of validating — turning a validation into a 500.
     */
    public function test_the_resolver_class_is_loaded_before_the_static_reference(): void
    {
        $at = strpos(self::$svc, "if (strpos(\$scope, 'category:') === 0) {");
        $this->assertNotFalse($at);
        $body = substr(self::$svc, $at, 900);

        $useAt  = strpos($body, 'Fee_head_resolver::ASSIGNABLE');
        $loadAt = strpos($body, "require_once APPPATH . 'libraries/Fee_head_resolver.php';");

        $this->assertNotFalse($loadAt,
            'the guard references Fee_head_resolver statically without ensuring it is loaded. '
            . '_headResolver() loads it lazily and only for head: scopes, so a category: scope '
            . 'can be the first reference in a request — and this would fatal.');
        $this->assertLessThan($useAt, $loadAt, 'the class is loaded after it is used');
    }

    /** An unknown category and a known-but-unbillable one need different messages. */
    public function test_the_two_refusals_are_distinguished(): void
    {
        // Anchor on the executable branch. The rationale comment above it names
        // every category, so a window measured from the 'MEALS-1' banner reads
        // prose and asserts nothing about the code.
        $at = strpos(self::$svc, "if (strpos(\$scope, 'category:') === 0) {");
        $this->assertNotFalse($at, 'the category branch is gone');
        $body = substr(self::$svc, $at, 1200);

        $this->assertStringContainsString('is not a fee category', $body,
            'a typo like category:banana gets the same message as a real but unbillable '
            . 'category, so an operator cannot tell a mistake from a limitation');
        $this->assertStringContainsString('ZenX raises no', $body);
    }

    // ══ THE SURFACES THAT CONTRADICTED THE POLICY ═══════════════════════════

    /**
     * Defence in depth: the service guard is the contract, but leaving Meals in
     * the pickers would show operators an option that now always errors.
     */
    public function test_no_surface_still_offers_meals(): void
    {
        $surfaces = [
            'views/fee_management/concessions.php',
            'views/_entitlements_capture.php',
            'controllers/Entitlement_requests.php',
        ];

        foreach ($surfaces as $rel) {
            $src = (string) file_get_contents(APPPATH . $rel);
            $src = (string) preg_replace('~<!--.*?-->~s', '', $src);
            $src = (string) preg_replace('!^\s*//.*$!m', '', $src);

            $this->assertStringNotContainsString('value="meals"', $src,
                "{$rel} still offers Meals as a selectable service. Entitlement_service now "
                . 'refuses that scope, so the option can only produce an error.');
            $this->assertStringNotContainsString("'meals'      =>", $src,
                "{$rel} still maps a meals request type");
        }
    }

    /**
     * Transport and hostel must be untouched on the SERVICE-ASSIGNMENT surface —
     * they are real, billed services.
     *
     * `views/_entitlements_capture.php` was in this list until ENTCAP-UX-1
     * (2026-09-19) and should not have been. That panel's service options lived
     * inside `ecSvcTypeWrap`, which syncFields() reveals only when
     * kind === 'service' — a kind removed on 2026-08-30 (SVC-ORPHAN-1) because
     * the row it wrote was consumed by nothing. The assertion was therefore
     * pinning markup no operator could reach, and it fired when that dead markup
     * was finally deleted. The invariant that DOES hold is asserted below.
     */
    public function test_transport_and_hostel_are_still_offered(): void
    {
        $rel = 'views/fee_management/concessions.php';
        $src = (string) file_get_contents(APPPATH . $rel);

        $this->assertStringContainsString('value="transport"', $src,
            "{$rel} lost the transport option — that is a real billed service");
        $this->assertStringContainsString('value="hostel"', $src);

        $this->assertContains('transport', Fee_head_resolver::ASSIGNABLE);
        $this->assertContains('hostel', Fee_head_resolver::ASSIGNABLE);
    }

    /**
     * SVC-ORPHAN-1 — the admission capture panel must never again offer to
     * create a SERVICE entitlement. It wrote kind='service' status='active' and
     * reported "Entitlement recorded" when no request, queue entry, route, stop
     * or fee existed anywhere. Transport is REQUESTED through the declarations
     * block, which feeds requestTransport() and the Transport → Requests queue.
     */
    public function test_the_capture_panel_offers_no_service_kind(): void
    {
        $src = (string) file_get_contents(APPPATH . 'views/_entitlements_capture.php');
        // Comments explaining the removal name the very tokens we forbid.
        $src = (string) preg_replace('~<\?php\s*/\*.*?\*/\s*\?>~s', '', $src);
        $src = (string) preg_replace('~<!--.*?-->~s', '', $src);
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $src = (string) preg_replace('!^\s*//.*$!m', '', $src);

        $this->assertStringNotContainsString('value="service"', $src,
            'the capture panel must not offer a service kind (SVC-ORPHAN-1)');
        $this->assertStringNotContainsString('ecSvcType', $src);
        $this->assertStringNotContainsString('value="transport"', $src,
            'transport is requested through the declarations block, never granted here');
    }
}
