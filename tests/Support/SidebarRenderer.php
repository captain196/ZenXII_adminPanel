<?php
/**
 * SidebarRenderer — renders the REAL admin sidebar for a set of Graded-RBAC
 * profiles and emits a semantic outline as JSON.
 *
 * WHY THIS IS A SEPARATE CLI SCRIPT, NOT A CLASS THE TEST INCLUDES
 * ---------------------------------------------------------------
 * It has to `require` application/helpers/rbac_helper.php to get the REAL
 * RBAC_MODULES / RBAC_MODULE_CHILDREN and the real R1/R2/R3 resolution. That
 * helper declares global functions — has_permission(), require_permission(),
 * rbac_granted_level() — and the view it renders needs a global get_instance().
 * Injecting those into the shared PHPUnit process would silently change the
 * environment for every later test that touches a controller. So the whole
 * render happens in its own process and the test reads back JSON.
 *
 * WHY IT RENDERS RATHER THAN MODELS
 * ---------------------------------
 * A hand-written model of the sidebar's $can() closure would only prove that
 * the model agrees with itself. This executes application/views/include/header.php
 * itself, so the assertions are about the file that actually ships.
 *
 * READ-ONLY. Writes nothing, contacts no tenant, opens no network connection.
 *
 * USAGE:  php tests/Support/SidebarRenderer.php   →  JSON on stdout
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$ROOT = realpath(__DIR__ . '/../../') . '/';

defined('BASEPATH') or define('BASEPATH', $ROOT . 'system/');
defined('APPPATH')  or define('APPPATH',  $ROOT . 'application/');
defined('FCPATH')   or define('FCPATH',   $ROOT);

if (!function_exists('base_url')) {
    function base_url($u = '') { return '/ZenX/school/' . ltrim((string) $u, '/'); }
}
if (!function_exists('log_message')) {
    function log_message($level, $message) { /* silent */ }
}

// ── Minimal CodeIgniter surface the header view touches ─────────────────────
class SR_Stub
{
    public function __call($n, $a) { return null; }
}
class SR_Security extends SR_Stub
{
    public function get_csrf_hash() { return 'CSRF_STUB'; }
    public function get_csrf_token_name() { return 'csrf_stub_name'; }
}
class SR_Input extends SR_Stub
{
    public function get($k = null, $x = false) { return null; }
    public function post($k = null, $x = false) { return null; }
}
class SR_Config extends SR_Stub
{
    private $sets = [];
    public function load($file, $sections = false, $failGracefully = false)
    {
        $p = APPPATH . 'config/' . $file . '.php';
        if (is_file($p)) { $config = []; include $p; $this->sets[$file] = $config; }
        return true;
    }
    public function item($key, $index = '')
    {
        if ($index !== '' && isset($this->sets[$index][$key])) return $this->sets[$index][$key];
        foreach ($this->sets as $set) if (isset($set[$key])) return $set[$key];
        return null;
    }
}
class SR_Load extends SR_Stub
{
    // Sub-views are suppressed: the subject under test is the menu, and pulling
    // in the loader markup would only add noise to every assertion.
    public function view($v, $d = [], $return = false) { return $return ? '' : null; }
    public function helper($h) { return null; }
    public function library($l, $p = null, $n = null) { return null; }
}
class SR_CI
{
    public $security, $input, $config, $load, $session;
    public function __construct()
    {
        $this->security = new SR_Security();
        $this->input    = new SR_Input();
        $this->config   = new SR_Config();
        $this->load     = new SR_Load();
        $this->session  = new SR_Stub();
    }
    /** The view uses $this->…, so it must be included from object context. */
    public function renderView($path, array $vars)
    {
        extract($vars);
        ob_start();
        include $path;
        return ob_get_clean();
    }
}

$SR_CI_INSTANCE = new SR_CI();
if (!function_exists('get_instance')) {
    function &get_instance() { global $SR_CI_INSTANCE; return $SR_CI_INSTANCE; }
}

// The real catalogue, the real hierarchy, the real resolver.
require_once APPPATH . 'helpers/rbac_helper.php';

// ── Profiles ────────────────────────────────────────────────────────────────
// Every entry documents WHY it exists. Do not delete one without proving the
// coverage it provides is duplicated elsewhere.
$ALL_FEATURES = [
    'School Management', 'Staff Management', 'Student Management',
    'Class Management', 'Subject Management', 'Exam Management',
    'Fees Management', 'Account Management', 'Notice and Announcement',
];
$OPS_CHILDREN = ['Library', 'Transport', 'Hostel', 'Inventory', 'Assets', 'Campus Access'];

$PROFILES = [
    // Bypass roles short-circuit before any resolution — they must still see everything.
    'P01_SuperAdmin'           => ['why' => 'RBAC_BYPASS_ROLES short-circuit',              'role' => 'Super Admin',        'perms' => []],
    'P02_SchoolSuperAdmin'     => ['why' => 'second bypass role, must match P01',           'role' => 'School Super Admin', 'perms' => []],
    'P03_SchoolAdmin_all'      => ['why' => 'full catalogue WITHOUT bypass — different path','role' => 'Admin',             'perms' => 'ALL'],
    // Non-ops profiles: prove the sidebar outside Operations is untouched.
    'P04_HR_admin'             => ['why' => 'single unrelated module',                      'role' => 'Staff', 'perms' => ['HR']],
    'P05_Payroll_admin'        => ['why' => 'same module at a LOWER graded level',          'role' => 'Staff', 'perms' => ['HR'], 'levels' => ['HR' => 'view']],
    'P06_Fees_accounts'        => ['why' => 'two unrelated modules, feature-gated sections','role' => 'Staff', 'perms' => ['Fees', 'Accounting']],
    'P07_Academic_admin'       => ['why' => 'multi-module section header logic',            'role' => 'Staff', 'perms' => ['Academic', 'Examinations', 'Results']],
    'P08_Admissions_admin'     => ['why' => 'SIS drives two separate menu groups',          'role' => 'Staff', 'perms' => ['SIS']],
    // One ops child each: proves R1 and proves the five padlocks stay padlocks.
    'P09_Transport_only'       => ['why' => 'R1 + the Fleet Map twin-row case',             'role' => 'Staff', 'perms' => ['Transport']],
    'P10_Hostel_only'          => ['why' => 'R1 single child',                              'role' => 'Staff', 'perms' => ['Hostel']],
    'P11_Library_only'         => ['why' => 'R1 single child',                              'role' => 'Staff', 'perms' => ['Library']],
    'P12_Inventory_only'       => ['why' => 'R1 single child',                              'role' => 'Staff', 'perms' => ['Inventory']],
    'P13_Assets_only'          => ['why' => 'R1 single child',                              'role' => 'Staff', 'perms' => ['Assets']],
    'P14_CampusAccess_only'    => ['why' => 'R1 single child, two-word module name',        'role' => 'Staff', 'perms' => ['Campus Access']],
    // Combinations across the umbrella.
    'P15_Mixed_ops_children'   => ['why' => 'two children held, four padlocked',            'role' => 'Staff', 'perms' => ['Transport', 'Inventory']],
    'P16_Legacy_Operations'    => ['why' => 'R2 — legacy umbrella-only role, un-migrated',  'role' => 'Staff', 'perms' => ['Operations']],
    'P19_Ops_view_level'       => ['why' => 'R2 at view level — menu must match P16',       'role' => 'Staff', 'perms' => ['Operations'], 'levels' => ['Operations' => 'view']],
    'P23_All_ops_children'     => ['why' => 'R3 saturated — must equal the R2 profiles',    'role' => 'Staff', 'perms' => $OPS_CHILDREN],
    'P25_Parent_plus_child'    => ['why' => 'umbrella AND a child together — no double-count','role' => 'Staff', 'perms' => ['Operations', 'Transport']],
    // Edges.
    'P17_Minimal'              => ['why' => 'no permissions — nothing may leak',            'role' => 'Staff', 'perms' => []],
    'P18_Stories_only'         => ['why' => 'known edge: item renders with no heading',     'role' => 'Staff', 'perms' => ['Stories']],
    'P20_Security_pair'        => ['why' => 'ops child + non-ops module side by side',      'role' => 'Staff', 'perms' => ['Campus Access', 'Device Management']],
    'P21_Teacher'              => ['why' => 'typical teaching role, no ops',                'role' => 'Teacher', 'perms' => ['Homework', 'Attendance', 'LMS']],
    'P22_Reports_only'         => ['why' => 'known edge: heading with zero items',          'role' => 'Staff', 'perms' => ['Reports']],
    'P24_NoFeatures_Transport' => ['why' => 'proves the ops group carries NO feature gate', 'role' => 'Staff', 'perms' => ['Transport'], 'features' => []],
];

// ── Render + reduce ─────────────────────────────────────────────────────────
function sr_render(array $p, array $allFeatures)
{
    $rbac_permissions = ($p['perms'] === 'ALL') ? RBAC_MODULES : $p['perms'];
    $rbac_levels      = $p['levels'] ?? [];
    $admin_role       = $p['role'];
    $school_features  = array_key_exists('features', $p) ? $p['features'] : $allFeatures;
    $admin_name          = 'Regression Profile';
    $admin_id            = 'STA0000';
    $school_name         = 'TESTSCHOOL';
    $schoolName          = 'TESTSCHOOL';
    $school_display_name = 'Test School';
    $session_year        = '2026-2027';

    $ci = get_instance();
    try {
        $html = $ci->renderView(APPPATH . 'views/include/header.php', compact(
            'rbac_permissions', 'rbac_levels', 'admin_role', 'school_features',
            'admin_name', 'admin_id', 'school_name', 'schoolName',
            'school_display_name', 'session_year'
        ));
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        return ['error' => 'RENDER FAILED: ' . $e->getMessage()];
    }

    $s = strpos($html, '<ul class="sidebar-menu"');
    if ($s === false) return ['error' => 'SIDEBAR MENU NOT FOUND IN OUTPUT'];
    $e = strpos($html, '</section>', $s);
    if ($e === false) $e = strlen($html);
    $menu = substr($html, $s, $e - $s);
    $menu = preg_replace('/<!--.*?-->/s', '', $menu);

    $headings = [];
    $clickable = [];
    $locked = [];

    preg_match_all(
        '/<li class="g-sec">(.*?)<\/li>'
      . '|<li class="nav-locked"><a[^>]*>(?:<i[^>]*><\/i>)?(.*?)<\/a>'
      . '|<a href="([^"]*)"[^>]*>(?:<i[^>]*><\/i>)?\s*(?:<span>)?([^<]*)/s',
        $menu, $m, PREG_SET_ORDER
    );
    foreach ($m as $x) {
        if (!empty($x[1])) { $headings[] = trim(html_entity_decode(strip_tags($x[1]))); continue; }
        if (!empty($x[2])) { $locked[]   = trim(html_entity_decode(strip_tags($x[2]))); continue; }
        $href  = $x[3] ?? '';
        $label = trim(html_entity_decode(strip_tags($x[4] ?? '')));
        if ($label === '' || $href === '#') continue;   // '#' = treeview toggle, not a destination
        $clickable[] = $label . ' → ' . preg_replace('#^/ZenX/school/#', '', $href);
    }

    return [
        'headings'  => $headings,
        'clickable' => $clickable,
        'locked'    => $locked,
    ];
}

$out = ['profiles' => [], 'meta' => [
    'modules'  => RBAC_MODULES,
    'children' => RBAC_MODULE_CHILDREN,
]];
foreach ($PROFILES as $name => $p) {
    $r = sr_render($p, $ALL_FEATURES);
    $r['why'] = $p['why'];
    $out['profiles'][$name] = $r;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
