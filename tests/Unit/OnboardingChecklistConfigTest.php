<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ONB-1 — the onboarding task list was tenant-configurable in name only.
 *
 * WHAT ZENX ALREADY HAD, and it is a lot: a `staffOnboarding` collection, a
 * per-employee checklist document, initiate / update-task / read endpoints, a
 * modal in edit_staff that drives all three, a shipped default task list, and a
 * reader — `Staff::_onboarding_tasks()` — that deliberately prefers
 * `schools/{schoolId}.onboardingTasks` over that default.
 *
 * WHAT WAS MISSING: anything that WROTE that field. The list was configurable
 * only with direct database access, so every tenant ran the same shipped
 * checklist whatever their joining process actually was.
 *
 * That is the sixth instance of one shape in this programme — capability built,
 * way in absent (DEV-1, DEV-2, GAP-1, PAY-1, LEAVE-1, ONB-1).
 *
 * NOTHING WAS INVENTED. The shipped default is untouched; a school may now
 * replace it with its own, and clearing it restores the default because that is
 * already what the reader does with an empty list.
 */
final class OnboardingChecklistConfigTest extends TestCase
{
    private static string $cfg   = '';
    private static string $staff = '';
    private static string $view  = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$cfg   = (string) file_get_contents($root . 'application/controllers/School_config.php');
        self::$staff = (string) file_get_contents($root . 'application/controllers/Staff.php');
        self::$view  = (string) file_get_contents($root . 'application/views/school_config/index.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$cfg = self::$staff = self::$view = '';
    }

    private function methodOf(string $src, string $name): string
    {
        $i = strpos($src, 'function ' . $name);
        $this->assertNotFalse($i, "{$name} must exist");

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos($src, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }

        // The LAST method in a file has nothing after it — bound at end of file
        // rather than failing, which is what an earlier version of this helper
        // did to every method appended at the bottom of a controller.
        $end = $next ? min($next) : strlen($src);

        return substr($src, (int) $i, $end - (int) $i);
    }

    // ── The pre-existing machinery still stands ──────────────────────────

    /**
     * The checklist itself was already built and wired. This test exists so a
     * later reader does not mistake ONB-1 for "onboarding was added" — it was
     * not; only its configuration was.
     */
    public function test_the_existing_checklist_is_intact(): void
    {
        foreach (['get_onboarding', 'initiate_onboarding', 'update_onboarding_task'] as $m) {
            $this->assertStringContainsString("public function {$m}", self::$staff);
        }

        $this->assertStringContainsString("private const ONBOARDING_COL = 'staffOnboarding';", self::$staff);
        $this->assertStringContainsString('ONBOARDING_DEFAULT_TASKS', self::$staff);

        // And the modal drives all three.
        $edit = (string) file_get_contents(__DIR__ . '/../../application/views/edit_staff.php');
        foreach (['staff/get_onboarding', 'staff/initiate_onboarding', 'staff/update_onboarding_task'] as $u) {
            $this->assertStringContainsString($u, $edit);
        }
    }

    // ── ONB-1: the missing writer ────────────────────────────────────────

    /** A school can now set its own list, from a screen. */
    public function test_the_task_list_is_editable_from_the_panel(): void
    {
        $this->assertStringContainsString('public function get_onboarding_tasks', self::$cfg);
        $this->assertStringContainsString('public function save_onboarding_tasks', self::$cfg);

        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        $this->assertStringContainsString("\$route['school_config/get_onboarding_tasks']", $routes);
        $this->assertStringContainsString("\$route['school_config/save_onboarding_tasks']", $routes);

        $this->assertStringContainsString('data-tab="onboarding"', self::$view);
        $this->assertStringContainsString('id="tab-onboarding"', self::$view);
        $this->assertStringContainsString("post('school_config/save_onboarding_tasks'", self::$view);
        $this->assertStringContainsString("if (btn.dataset.tab === 'onboarding') {", self::$view);
    }

    /**
     * THE ONE THAT WOULD HAVE BITTEN. The reader uses `schools/{schoolId}` —
     * the bare document — while other config on this controller lives on
     * `{schoolId}_profile`. Writing to the wrong document would store a list
     * the checklist never reads, and the screen would look like it worked.
     */
    public function test_the_writer_targets_the_document_the_reader_reads(): void
    {
        $reader = $this->methodOf(self::$staff, '_onboarding_tasks');
        $this->assertStringContainsString("\$this->fs->get('schools', \$this->school_id)", $reader);

        $writer = $this->methodOf(self::$cfg, 'save_onboarding_tasks');
        $this->assertStringContainsString(
            "\$this->fs->update('schools', \$this->school_id, ['onboardingTasks' => \$tasks])", $writer,
            'the writer must target the same document as the reader, not the _profile doc');
        $this->assertStringNotContainsString("docId('profile')", $writer);
    }

    /** Clearing the list restores the built-in default — the reader's own contract. */
    public function test_an_empty_list_means_use_the_default(): void
    {
        $reader = $this->methodOf(self::$staff, '_onboarding_tasks');
        $this->assertStringContainsString('if (!empty($list)) return $list;', $reader);
        $this->assertStringContainsString('return self::ONBOARDING_DEFAULT_TASKS;', $reader);

        $writer = $this->methodOf(self::$cfg, 'save_onboarding_tasks');
        $this->assertStringContainsString('the built-in checklist will be used', $writer);

        // And the screen says which state it is in.
        $this->assertStringContainsString('usingDefault', self::$view);
        $this->assertStringContainsString('using the built-in checklist', self::$view);
    }

    // ── Validation ───────────────────────────────────────────────────────

    /** Bounded, de-duplicated, and blanks are treated as deletions. */
    public function test_the_list_is_validated(): void
    {
        $writer = $this->methodOf(self::$cfg, 'save_onboarding_tasks');

        $this->assertStringContainsString('if (count($raw) > 30) {', $writer,
            'an unbounded list would be copied onto every checklist that starts');
        $this->assertStringContainsString('mb_strlen($t) > 120', $writer);
        $this->assertStringContainsString('if (isset($seen[$k])) continue;', $writer,
            'duplicate tasks would produce duplicate checklist rows');
        $this->assertStringContainsString("if (\$t === '') continue;", $writer);
    }

    /** A non-array payload is refused rather than written through. */
    public function test_a_malformed_payload_is_refused(): void
    {
        $writer = $this->methodOf(self::$cfg, 'save_onboarding_tasks');

        $this->assertStringContainsString('if (!is_array($raw)) {', $writer);
        $this->assertStringContainsString('Send the task list as an array.', $writer);
    }

    // ── RBAC + tenancy ───────────────────────────────────────────────────

    /** Reading is a view act; changing needs manage. */
    public function test_rbac_separates_reading_from_changing(): void
    {
        $this->assertStringContainsString(
            "'school_config_get_onboarding_tasks', 'Configuration', 'view'", self::$cfg);
        $this->assertStringContainsString(
            "'school_config_save_onboarding_tasks', 'Configuration', 'manage'", self::$cfg);
    }

    /** Both endpoints are bound to the caller's own school. */
    public function test_the_endpoints_are_tenant_scoped(): void
    {
        foreach (['get_onboarding_tasks', 'save_onboarding_tasks'] as $m) {
            $body = $this->methodOf(self::$cfg, $m);
            $this->assertStringContainsString('$this->school_id', $body);
            $this->assertStringNotContainsString('$this->input->post(\'school', $body,
                'the tenant must never come from the request');
        }
    }

    /** The change is logged — a checklist is a process record. */
    public function test_the_change_is_logged(): void
    {
        $writer = $this->methodOf(self::$cfg, 'save_onboarding_tasks');
        $this->assertStringContainsString('ONBOARDING_TASKS updated', $writer);
    }

    // ── UI/UX ────────────────────────────────────────────────────────────

    /**
     * The operator is told that editing the list does NOT rewrite checklists
     * already in progress. Without that they would reasonably assume it did.
     */
    public function test_the_screen_states_the_effect_on_existing_checklists(): void
    {
        $writer = $this->methodOf(self::$cfg, 'save_onboarding_tasks');
        $this->assertStringContainsString('Applies to checklists started from now on.', $writer);

        $this->assertStringContainsString('keeps the tasks their checklist was opened with', self::$view);
    }

    /** Loading, error, disabled and double-submit states all exist. */
    public function test_the_screen_has_its_states(): void
    {
        $this->assertStringContainsString('id="onbLoading"', self::$view);
        $this->assertStringContainsString('id="onbError"', self::$view);
        $this->assertStringContainsString('if (onbSaving) return;', self::$view);
        $this->assertStringContainsString('btn.disabled = true;', self::$view);
        $this->assertStringContainsString('loadOnboardingTasks();', self::$view);
    }

    /** Saving an empty list is confirmed, because it silently swaps in the default. */
    public function test_clearing_the_list_is_confirmed(): void
    {
        $this->assertStringContainsString('Save an empty checklist?', self::$view);
    }

    /** The editor never leaves the operator with nothing to type into. */
    public function test_the_editor_always_keeps_one_row(): void
    {
        $this->assertStringContainsString('if (rows.length <= 1)', self::$view);
    }
}
