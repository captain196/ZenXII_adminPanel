<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Per-school notification preferences.
 *
 * MARKET EVIDENCE — the Asanify admin demo shows a Company → Notifications
 * configuration area with per-event settings and role-based recipient targeting
 * (Admin / Manager / Self). Those observations were supplied by the operator;
 * this programme did not watch the recording and does not claim to have.
 *
 * ZENX BEFORE: nothing at all. `MARK_REGISTRY` is a platform constant in
 * functions/index.js, identical for all nine tenants. A school that did not want
 * a particular alert had exactly one lever — a code change plus a Cloud
 * Functions deploy, applied to every tenant simultaneously.
 *
 * THE CHOKE POINT is what makes this small: every producer in the codebase
 * reaches Firestore through Push_enqueue_service::enqueue() — MY_Controller's
 * emit_push() delegates to it, and the CLI crons call it directly for precisely
 * that reason. One check there governs all of them, present and future, without
 * touching functions/ and therefore without a Functions deploy.
 */
final class NotificationPreferencesTest extends TestCase
{
    private static string $svc  = '';
    private static string $cfg  = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$svc  = (string) file_get_contents($root . 'application/libraries/Push_enqueue_service.php');
        self::$cfg  = (string) file_get_contents($root . 'application/controllers/School_config.php');
        self::$view = (string) file_get_contents($root . 'application/views/school_config/index.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$svc = self::$cfg = self::$view = '';
    }

    // ── Enforcement ──────────────────────────────────────────────────────

    /** The preference is consulted at the one point every producer passes. */
    public function test_preferences_are_enforced_at_the_single_choke_point(): void
    {
        $this->assertStringContainsString('if (!$this->allowed($fs, $schoolId, $mark)) {', self::$svc);
        $this->assertStringContainsString('private function allowed($fs, string $schoolId, string $mark): bool', self::$svc);

        // And emit_push still routes through this service rather than around it.
        $base = (string) file_get_contents(__DIR__ . '/../../application/core/MY_Controller.php');
        $this->assertStringContainsString('$this->pushEnqueue->enqueue(', $base,
            'if a producer ever bypasses the service, it bypasses the school\'s choice too');
    }

    /** The check happens BEFORE the write, or it would suppress nothing. */
    public function test_a_disabled_event_is_never_enqueued(): void
    {
        $i = strpos(self::$svc, '$this->allowed($fs, $schoolId, $mark)');
        $w = strpos(self::$svc, '$fs->set(self::COL');

        $this->assertNotFalse($i);
        $this->assertNotFalse($w);
        $this->assertLessThan($w, $i);
    }

    /**
     * THE MOST IMPORTANT TEST HERE. A school that has never opened the screen
     * must behave exactly as it did before this feature existed. Absent
     * preferences SEND.
     */
    public function test_a_school_with_no_preferences_is_unaffected(): void
    {
        $i = strpos(self::$svc, 'private function allowed(');
        $body = substr(self::$svc, (int) $i, 2200);

        $this->assertStringContainsString('if (!is_array($prefs)) {', $body);
        $this->assertStringContainsString('return true;                  // never configured, or unreadable', $body);
        $this->assertStringContainsString("if (!is_array(\$row) || !array_key_exists('enabled', \$row)) {", $body,
            'an event the school never touched must still send');
    }

    /**
     * Unreadable preferences SEND. A notification is not a security control:
     * failing closed would silence a school's alerts on a transient read error,
     * which is a worse failure than sending one it had disabled.
     */
    public function test_unreadable_preferences_fail_open(): void
    {
        $i = strpos(self::$svc, 'private function allowed(');
        $body = substr(self::$svc, (int) $i, 2200);

        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
        $this->assertStringContainsString('$prefs = null;', $body);
        $this->assertStringContainsString("log_message('error'", $body);
    }

    /** Only an explicit false suppresses. */
    public function test_only_an_explicit_false_suppresses(): void
    {
        $i = strpos(self::$svc, 'private function allowed(');
        $body = substr(self::$svc, (int) $i, 2200);

        $this->assertStringContainsString("return (bool) \$row['enabled'];", $body);
    }

    /** A suppressed event is logged — a silent drop is indistinguishable from a bug. */
    public function test_a_suppressed_event_is_logged(): void
    {
        $this->assertStringContainsString('is switched off for school', self::$svc);
    }

    /**
     * The tenant is taken from the EXPLICIT schoolId, not from the helper's own
     * scope: a CLI cron's helper may be scoped to another tenant or to none.
     */
    public function test_the_tenant_comes_from_the_explicit_school_id(): void
    {
        $i = strpos(self::$svc, 'private function allowed(');
        $body = substr(self::$svc, (int) $i, 2200);

        $this->assertStringContainsString("\$fs->get('schools', \$schoolId . '_profile')", $body);

        // Scan the CODE, not the commentary. The comment above that line
        // explains why docId() is wrong here, and naming it there must not read
        // as using it — the same self-trip this suite's forbidden-token scans
        // have hit before.
        $code = '';
        foreach (token_get_all('<?php ' . $body) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $code .= is_array($tok) ? $tok[1] : $tok;
        }

        $this->assertStringNotContainsString('$fs->docId(', $code,
            'docId() would bind the read to the helper\'s tenant, not the event\'s');
    }

    /** Preferences are memoised per school, not re-read for every push. */
    public function test_preferences_are_cached_per_request(): void
    {
        $this->assertStringContainsString('private $_prefs = [];', self::$svc);
        $this->assertStringContainsString('array_key_exists($schoolId, $this->_prefs)', self::$svc);
    }

    // ── The admin surface ────────────────────────────────────────────────

    /** A school can read and change this without a developer. */
    public function test_the_settings_are_reachable_from_the_panel(): void
    {
        $this->assertStringContainsString('public function get_notification_settings', self::$cfg);
        $this->assertStringContainsString('public function save_notification_setting', self::$cfg);

        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        $this->assertStringContainsString("\$route['school_config/get_notification_settings']", $routes);
        $this->assertStringContainsString("\$route['school_config/save_notification_setting']", $routes);

        $this->assertStringContainsString('data-tab="notifications"', self::$view);
        $this->assertStringContainsString('id="tab-notifications"', self::$view);
        $this->assertStringContainsString("post('school_config/get_notification_settings'", self::$view);
        $this->assertStringContainsString("post('school_config/save_notification_setting'", self::$view);
    }

    /** Opening the tab loads it — a pane nothing populates is an empty screen. */
    public function test_the_tab_loads_when_opened(): void
    {
        $this->assertStringContainsString("if (btn.dataset.tab === 'notifications') {", self::$view);
        $this->assertStringContainsString('loadNotificationSettings();', self::$view);
    }

    /**
     * The events offered are a DELIBERATE allow-list, not a dump of the
     * registry: a school must not be able to silence something it does not
     * control, and an event with no meaningful "off" should not be offered.
     */
    public function test_the_event_list_is_an_allow_list(): void
    {
        $this->assertStringContainsString('private const NOTIFICATION_EVENTS = [', self::$cfg);
        $this->assertStringContainsString("if (!in_array(\$mark, \$known, true)) {", self::$cfg,
            'an unknown mark must be refused, not written through');
        $this->assertStringContainsString('Unknown notification event.', self::$cfg);
    }

    /** Reading is a view act; changing needs manage. */
    public function test_rbac_separates_reading_from_changing(): void
    {
        $this->assertStringContainsString(
            "'school_config_get_notification_settings', 'Configuration', 'view'", self::$cfg);
        $this->assertStringContainsString(
            "'school_config_save_notification_setting', 'Configuration', 'manage'", self::$cfg);
    }

    /** Every change records who and when, and is audited. */
    public function test_every_change_is_attributed_and_audited(): void
    {
        $i = strpos(self::$cfg, 'public function save_notification_setting');
        $body = substr(self::$cfg, (int) $i, 2600);

        $this->assertStringContainsString("'updatedAt' => date('c'),", $body);
        $this->assertStringContainsString("'updatedBy' =>", $body);
        $this->assertStringContainsString('NOTIFICATION_SETTING', $body,
            'switching a school\'s alerts off is exactly what someone later needs explained');
    }

    /** Writes are tenant-scoped through docId(), like every other config write. */
    public function test_the_write_is_tenant_scoped(): void
    {
        $i = strpos(self::$cfg, 'public function save_notification_setting');
        $body = substr(self::$cfg, (int) $i, 2600);

        $this->assertStringContainsString("\$this->fs->docId('profile')", $body);
        $this->assertStringContainsString('"notificationSettings.{$mark}"', $body,
            'a dotted field write, so one event never clobbers another');
    }

    // ── UI/UX ────────────────────────────────────────────────────────────

    /** Switching OFF is confirmed; switching back on is not. */
    public function test_silencing_an_alert_is_confirmed(): void
    {
        $this->assertStringContainsString("if (!next && !confirm(", self::$view);
        $this->assertStringContainsString('Stop sending this notification?', self::$view);
    }

    /** Loading, disabled and error states all exist. */
    public function test_the_screen_has_its_states(): void
    {
        $this->assertStringContainsString('id="notifLoading"', self::$view);
        $this->assertStringContainsString('id="notifError"', self::$view);
        $this->assertStringContainsString('fa-spinner fa-spin', self::$view);
        $this->assertStringContainsString('if (notifBusy[mark]) return;', self::$view,
            'a second click while one is in flight must be ignored');
        $this->assertStringContainsString('btn.disabled = true;', self::$view);
    }

    /** A failed save never leaves the button showing a state that was not saved. */
    public function test_a_failed_save_restores_the_button(): void
    {
        // Anchor inside the NOTIFICATION save handler. `if (!r || r.status !==
        // 'success')` is a house idiom that appears in several modules on this
        // page, so searching for it from the top of the file finds whichever
        // module happens to sit highest — which is how this test started
        // reading the onboarding handler instead.
        $at = strpos(self::$view, "post('school_config/save_notification_setting'");
        $this->assertNotFalse($at, 'the notification save handler must exist');

        $handler = substr(self::$view, (int) $at, 900);

        $i = strpos($handler, "if (!r || r.status !== 'success') {");
        $this->assertNotFalse($i, 'the handler must check the outcome before claiming success');
        $branch = substr($handler, (int) $i, 320);

        $this->assertStringContainsString('btn.innerHTML = original;', $branch,
            'a failed save must never leave the button showing a state that was not saved');
    }

    /** After a successful save the state is re-read, not assumed. */
    public function test_the_state_is_re_read_after_a_save(): void
    {
        $i = strpos(self::$view, "post('school_config/save_notification_setting'");
        $this->assertNotFalse($i);
        $handler = substr(self::$view, (int) $i, 900);

        $this->assertStringContainsString('loadNotificationSettings();', $handler);
    }

    /** Operator-authored text is escaped before it reaches innerHTML. */
    public function test_rendered_values_are_escaped(): void
    {
        $this->assertStringContainsString('function scEsc(', self::$view);
        $this->assertStringContainsString('scEsc(e.label)', self::$view);
        $this->assertStringContainsString('scEsc(e.updatedBy)', self::$view);
    }

    /** An empty allow-list renders a sentence, not a blank panel. */
    public function test_an_empty_list_says_so(): void
    {
        $this->assertStringContainsString('No configurable notifications.', self::$view);
    }

    // ── Scope honesty ────────────────────────────────────────────────────

    /**
     * Recipient RE-TARGETING was deliberately not built. Which audience a mark
     * reaches is resolved inside MARK_REGISTRY in functions/, so changing it
     * needs a Functions deploy, and the right per-event audience is a product
     * decision. The omission is recorded so it does not read as an oversight.
     */
    public function test_recipient_retargeting_is_recorded_as_not_built(): void
    {
        $this->assertStringContainsString('DELIBERATELY NOT INCLUDED: recipient re-targeting', self::$cfg);

        // And nothing pretends otherwise in the UI.
        $this->assertStringNotContainsString('data-notif-audience', self::$view);
    }
}
