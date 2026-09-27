<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sidebar × Graded-RBAC regression lock.
 *
 * WHAT THIS PROTECTS
 * ------------------
 * The admin sidebar is not decoration: it is rendered from the same graded
 * permission map the controllers enforce with, and the Operations group is
 * gated by a real RBAC umbrella with bidirectional inheritance:
 *
 *   R1  direct grant          — 'Transport' in permissions[]
 *   R2  parent → child        — holding 'Operations' grants all six children
 *   R3  child → parent        — holding ANY child makes 'Operations' resolve present
 *
 * Before this file existed, NOTHING in the suite asserted sidebar structure at
 * all, so a change to header.php that widened a permission gate, un-padlocked a
 * child, or broke the umbrella would have shipped green.
 *
 * HOW IT WORKS — and why it is not a model
 * ----------------------------------------
 * tests/Support/SidebarRenderer.php renders the REAL
 * application/views/include/header.php against the REAL
 * application/helpers/rbac_helper.php, once per permission profile, and emits a
 * semantic outline (headings / clickable / locked). A hand-written model of the
 * sidebar's $can() closure would only prove the model agrees with itself.
 *
 * The renderer runs in a SEPARATE PROCESS. rbac_helper.php declares global
 * has_permission()/require_permission() and the view needs a global
 * get_instance(); defining those inside the shared PHPUnit process would
 * silently change the environment for every later controller test.
 *
 * SCOPE NOTE — what these tests do NOT prove:
 *   • They assert rendered navigation, not authorization. Controller gates,
 *     Firestore rules and direct-URL protection are enforced elsewhere and
 *     covered elsewhere; a sidebar item is never the authorization boundary.
 *   • They do not prove browser behaviour. No page was opened.
 */
class SidebarRbacRegressionTest extends TestCase
{
    private const RENDERER = __DIR__ . '/../Support/SidebarRenderer.php';

    /** The six modules under the Operations umbrella, and the rows they render. */
    private const OPS_ROWS = [
        'Library'       => 'Library → library',
        'Transport'     => 'Transport → transport',
        'Hostel'        => 'Hostel → hostel',
        'Inventory'     => 'Inventory → inventory',
        // 2026-08-31 — EXPECTED CHANGE, not a test bent to fit the code.
        // The bare 'assets' target was unreachable in production: the repo serves a
        // real ./assets/ directory, so Apache resolved the module route as that
        // directory and returned 301 → 403 before CodeIgniter saw it. Confirmed live.
        // The destination is now the controller's own default tab, which renders the
        // identical page. Route, controller, permission and padlock semantics unchanged;
        // this constant is the ONLY assertion touched by that repair.
        'Assets'        => 'Assets → assets/registry',
        'Campus Access' => 'Campus Access → campus_access',
    ];
    private const OPS_HUB   = 'Dashboard → operations';
    private const FLEET_MAP = 'Live Fleet Map → transport_fleet';

    /** Profiles holding exactly one Operations child. */
    private const SINGLE_CHILD = [
        'P09_Transport_only'    => 'Transport',
        'P10_Hostel_only'       => 'Hostel',
        'P11_Library_only'      => 'Library',
        'P12_Inventory_only'    => 'Inventory',
        'P13_Assets_only'       => 'Assets',
        'P14_CampusAccess_only' => 'Campus Access',
    ];

    private static ?array $nav = null;

    /** Render once for the whole class; 25 profiles in one subprocess. */
    private function nav(): array
    {
        if (self::$nav !== null) return self::$nav;

        $this->assertFileExists(self::RENDERER, 'The sidebar renderer is missing.');
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::RENDERER);
        $raw = shell_exec($cmd);
        $this->assertNotEmpty($raw, 'The sidebar renderer produced no output.');

        $json = json_decode((string) $raw, true);
        $this->assertIsArray($json, "The renderer did not emit JSON. Output began:\n"
            . substr((string) $raw, 0, 400));
        $this->assertArrayHasKey('profiles', $json);

        foreach ($json['profiles'] as $name => $p) {
            $this->assertArrayNotHasKey('error', $p,
                "Profile {$name} failed to render: " . ($p['error'] ?? ''));
        }
        return self::$nav = $json;
    }

    private function profile(string $name): array
    {
        $nav = $this->nav();
        $this->assertArrayHasKey($name, $nav['profiles'], "Unknown profile {$name}.");
        return $nav['profiles'][$name];
    }

    /** Readable failure text: which profile, which item, which state. */
    private function why(string $profile, string $item, string $state): string
    {
        $p = $this->profile($profile);
        return "\nPROFILE  {$profile} — " . ($p['why'] ?? '')
             . "\nITEM     {$item}"
             . "\nSTATE    {$state}"
             . "\nheadings : " . implode(' | ', $p['headings'])
             . "\nclickable: " . implode(' | ', $p['clickable'])
             . "\nlocked   : " . implode(' | ', $p['locked']) . "\n";
    }

    // ── 0 · the renderer itself must discriminate ────────────────────────────

    /**
     * Guard against a silently broken harness. While building this, a stubbing
     * mistake made every profile render identically — a green test proving
     * nothing. If profiles stop differing, the tests below are worthless.
     */
    public function test_the_renderer_actually_distinguishes_profiles(): void
    {
        $nav = $this->nav();
        $sigs = [];
        foreach ($nav['profiles'] as $name => $p) {
            $sigs[$name] = md5(json_encode([$p['headings'], $p['clickable'], $p['locked']]));
        }
        $this->assertGreaterThan(10, count(array_unique($sigs)),
            'Profiles are rendering (near-)identically — the harness is broken, not the sidebar.');

        $this->assertNotSame(
            $sigs['P09_Transport_only'], $sigs['P10_Hostel_only'],
            'Transport-only and Hostel-only render identically — per-item gating is not being exercised.'
        );
        $this->assertNotSame(
            $sigs['P17_Minimal'], $sigs['P09_Transport_only'],
            'A user with no permissions renders the same menu as a Transport holder.'
        );
    }

    /** The umbrella definition itself is load-bearing; assert it is intact. */
    public function test_the_operations_umbrella_definition_is_unchanged(): void
    {
        $nav = $this->nav();
        $this->assertArrayHasKey('Operations', $nav['meta']['children'],
            "RBAC_MODULE_CHILDREN no longer defines 'Operations'. The sidebar's group gate, "
            . 'every child grant via R2, and every parent presence via R3 depend on it.');
        $this->assertSame(
            array_keys(self::OPS_ROWS),
            $nav['meta']['children']['Operations'],
            'The Operations children changed. Sidebar grouping and RBAC inheritance both move with this list.'
        );
    }

    // ── 1 · headings ─────────────────────────────────────────────────────────

    public function test_every_profile_with_operations_rows_gets_the_operations_heading(): void
    {
        foreach ($this->nav()['profiles'] as $name => $p) {
            $hasRows = (bool) array_intersect(
                array_merge($p['clickable'], array_map(fn($l) => $l, $p['locked'])),
                array_merge(array_values(self::OPS_ROWS), array_keys(self::OPS_ROWS), [self::OPS_HUB])
            );
            if (!$hasRows) continue;
            $this->assertContains('Operations', $p['headings'],
                $this->why($name, 'Operations section', 'rows render but the heading does not'));
        }
    }

    public function test_no_profile_gets_an_operations_heading_with_no_rows(): void
    {
        foreach ($this->nav()['profiles'] as $name => $p) {
            if (!in_array('Operations', $p['headings'], true)) continue;
            $rows = array_merge(
                array_intersect($p['clickable'], array_merge(array_values(self::OPS_ROWS), [self::OPS_HUB])),
                array_intersect($p['locked'], array_keys(self::OPS_ROWS))
            );
            $this->assertNotEmpty($rows,
                $this->why($name, 'Operations section', 'heading renders above an empty group'));
        }
    }

    public function test_a_user_with_no_operations_access_sees_no_operations_heading(): void
    {
        foreach (['P17_Minimal', 'P04_HR_admin', 'P21_Teacher', 'P22_Reports_only'] as $name) {
            $p = $this->profile($name);
            $this->assertNotContains('Operations', $p['headings'],
                $this->why($name, 'Operations section', 'visible to a user holding no ops capability'));
        }
    }

    // ── 2 · clickable · 3 · locked · 4 · hidden ──────────────────────────────

    /**
     * R1 + independence. Holding exactly one child must make exactly that child
     * clickable and leave the other five padlocked — never clickable, never gone.
     * This is the assertion that fails if a child's gate is broadened to the
     * 'Operations' parent, which would light all six at once.
     */
    public function test_a_single_child_grant_unlocks_exactly_that_child(): void
    {
        foreach (self::SINGLE_CHILD as $name => $held) {
            $p = $this->profile($name);

            $this->assertContains(self::OPS_ROWS[$held], $p['clickable'],
                $this->why($name, $held, 'held but not clickable — permission LOST'));

            foreach (self::OPS_ROWS as $module => $row) {
                if ($module === $held) continue;
                $this->assertNotContains($row, $p['clickable'],
                    $this->why($name, $module, 'NOT held but clickable — permission WIDENED'));
                $this->assertContains($module, $p['locked'],
                    $this->why($name, $module, 'was padlocked, now neither clickable nor locked — visibility LOST'));
            }
        }
    }

    /** Exactly five padlocks for a single-child holder — no more, no fewer. */
    public function test_padlock_count_is_preserved_for_single_child_profiles(): void
    {
        foreach (self::SINGLE_CHILD as $name => $held) {
            $p = $this->profile($name);
            $locked = array_values(array_intersect($p['locked'], array_keys(self::OPS_ROWS)));
            $this->assertCount(5, $locked,
                $this->why($name, 'padlocks', 'expected 5 locked ops rows, got ' . count($locked)));
        }
    }

    /** The Fleet Map shares Transport's capability exactly — it has no gate of its own. */
    public function test_the_fleet_map_follows_transport_exactly(): void
    {
        foreach ($this->nav()['profiles'] as $name => $p) {
            $transport = in_array(self::OPS_ROWS['Transport'], $p['clickable'], true);
            $fleet     = in_array(self::FLEET_MAP, $p['clickable'], true);
            $this->assertSame($transport, $fleet,
                $this->why($name, 'Live Fleet Map',
                    'diverged from Transport — it must share the same capability, never gain one of its own'));
        }
    }

    // ── 5 · Operations hub visibility (R3) ───────────────────────────────────

    /**
     * R3: holding any child synthesises parent presence, so the hub stays
     * reachable. If this fails, child → parent inheritance has been removed and
     * ops staff have silently lost a page they are still authorized for.
     */
    public function test_any_single_child_still_reaches_the_operations_hub(): void
    {
        foreach (self::SINGLE_CHILD as $name => $held) {
            $this->assertContains(self::OPS_HUB, $this->profile($name)['clickable'],
                $this->why($name, self::OPS_HUB,
                    'unreachable — R3 child→parent inheritance appears to be gone'));
        }
    }

    /** R2: the legacy umbrella-only role must still open all six children. */
    public function test_the_legacy_operations_umbrella_still_grants_every_child(): void
    {
        foreach (['P16_Legacy_Operations', 'P19_Ops_view_level', 'P25_Parent_plus_child'] as $name) {
            $p = $this->profile($name);
            foreach (self::OPS_ROWS as $module => $row) {
                $this->assertContains($row, $p['clickable'],
                    $this->why($name, $module, 'not clickable — R2 parent→child inheritance appears to be gone'));
            }
            $this->assertEmpty(array_intersect($p['locked'], array_keys(self::OPS_ROWS)),
                $this->why($name, 'padlocks', 'the umbrella grants everything, so nothing may be locked'));
        }
    }

    /** R2 and R3 must converge: umbrella, umbrella-at-view, and all-six render alike. */
    public function test_parent_grant_and_full_child_grant_render_identically(): void
    {
        $a = $this->profile('P16_Legacy_Operations');
        $b = $this->profile('P23_All_ops_children');
        $c = $this->profile('P19_Ops_view_level');
        $this->assertSame($a['clickable'], $b['clickable'],
            'Umbrella grant and all-six-children grant must produce the same menu.');
        $this->assertSame($a['clickable'], $c['clickable'],
            'A graded level must not change which items appear — only what they permit.');
    }

    // ── the rest of the sidebar must stay out of this ────────────────────────

    public function test_a_non_operations_profile_sees_no_operations_rows(): void
    {
        foreach (['P04_HR_admin', 'P06_Fees_accounts', 'P08_Admissions_admin', 'P21_Teacher'] as $name) {
            $p = $this->profile($name);
            foreach (array_merge(array_values(self::OPS_ROWS), [self::OPS_HUB, self::FLEET_MAP]) as $row) {
                $this->assertNotContains($row, $p['clickable'],
                    $this->why($name, $row, 'visible to a profile holding no ops capability'));
            }
            $this->assertEmpty(array_intersect($p['locked'], array_keys(self::OPS_ROWS)),
                $this->why($name, 'padlocks', 'ops padlocks leaked to a non-ops profile'));
        }
    }

    public function test_a_user_with_no_permissions_sees_only_the_dashboard(): void
    {
        $p = $this->profile('P17_Minimal');
        $this->assertSame(['Overview'], $p['headings'],
            $this->why('P17_Minimal', 'headings', 'a permissionless user sees more than Overview'));
        $this->assertSame(['Dashboard → admin'], $p['clickable'],
            $this->why('P17_Minimal', 'clickable', 'a permissionless user has more than the dashboard'));
        $this->assertSame([], $p['locked']);
    }

    /** Bypass roles resolve before any module check and must see the whole menu. */
    public function test_bypass_roles_see_everything_and_are_never_padlocked(): void
    {
        foreach (['P01_SuperAdmin', 'P02_SchoolSuperAdmin'] as $name) {
            $p = $this->profile($name);
            $this->assertEmpty($p['locked'],
                $this->why($name, 'padlocks', 'a bypass role must never be padlocked'));
            foreach (self::OPS_ROWS as $module => $row) {
                $this->assertContains($row, $p['clickable'], $this->why($name, $module, 'missing for a bypass role'));
            }
        }
    }

    /**
     * The Operations group carries NO school_features gate — unlike Campus,
     * Finance and the rest. Adding one would silently remove the whole group
     * for tenants whose plan does not list that feature.
     */
    public function test_the_operations_group_has_no_tenant_feature_gate(): void
    {
        $withFeatures = $this->profile('P09_Transport_only');
        $without      = $this->profile('P24_NoFeatures_Transport');
        $this->assertSame($withFeatures['clickable'], $without['clickable'],
            $this->why('P24_NoFeatures_Transport', 'Operations group',
                'disappeared when school_features was emptied — a feature gate has been added'));
        $this->assertSame($withFeatures['locked'], $without['locked']);
    }
}
