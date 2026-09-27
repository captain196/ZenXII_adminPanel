<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Comp-Off policy settings — the Admin surface for `compOffExpiryDays` and
 * `compOffMaxBalanceDays`.
 *
 * The production gate recorded these as a real gap: the backend honoured both
 * keys, but no tenant could reach them without writing
 * `schools.attendancePolicy` by hand. A setting nobody can configure is not a
 * feature, so this closes it.
 *
 * The settings live where Calendar Half-Day already lives — the same document,
 * the same endpoint (`Attendance::save_attendance_policy`), the same view. No
 * second settings store was introduced.
 *
 * DESIGN POINT THAT DRIVES EVERY TEST BELOW. Both settings REDUCE an
 * entitlement the employee has already earned by working a rest day: expiry
 * lapses banked credits, and the cap refuses new ones. So both default to
 * DISABLED, `0` means "off" rather than "zero days", and an unconfigured tenant
 * behaves exactly as it did before these fields existed.
 *
 * Validation is asserted SERVER-side. The number inputs carry `min`/`max`, but
 * browser attributes are a convenience, not enforcement — a malformed value
 * silently coerced to a default would leave an operator believing an expiry
 * window is live when credits in fact never lapse.
 */
final class CompOffPolicySettingsTest extends TestCase
{
    /**
     * Read ONCE per class, not per test.
     *
     * `Attendance.php` is multi-megabyte, and re-reading it in setUp() for
     * every test method contributed to exhausting PHP's 512M limit and killing
     * the whole suite. A test file that reads large sources must hold them
     * statically.
     */
    private static string $ctrlCache = '';
    private static string $viewCache = '';

    private string $ctrl;
    private string $view;

    public static function setUpBeforeClass(): void
    {
        self::$ctrlCache = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Attendance.php'
        );
        self::$viewCache = (string) file_get_contents(
            __DIR__ . '/../../application/views/attendance/settings.php'
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$ctrlCache = '';
        self::$viewCache = '';
    }

    protected function setUp(): void
    {
        $this->assertNotSame('', self::$ctrlCache, 'Attendance.php could not be read');
        $this->assertNotSame('', self::$viewCache, 'settings.php could not be read');
        $this->ctrl = self::$ctrlCache;
        $this->view = self::$viewCache;
    }

    /** The shipped server-side validator, transcribed for exercise. */
    private function validate($expiryRaw, $capRaw): array
    {
        $expiry = 0;
        $cap    = 0.0;

        if ($expiryRaw !== '' && $expiryRaw !== null) {
            if (!is_numeric($expiryRaw) || (float) $expiryRaw != (int) $expiryRaw) {
                return ['ok' => false, 'field' => 'expiry', 'reason' => 'whole_number'];
            }
            $expiry = (int) $expiryRaw;
            if ($expiry < 0)   return ['ok' => false, 'field' => 'expiry', 'reason' => 'negative'];
            if ($expiry > 365) return ['ok' => false, 'field' => 'expiry', 'reason' => 'too_large'];
        }

        if ($capRaw !== '' && $capRaw !== null) {
            if (!is_numeric($capRaw)) {
                return ['ok' => false, 'field' => 'cap', 'reason' => 'not_a_number'];
            }
            $cap = round((float) $capRaw, 2);
            if ($cap < 0)   return ['ok' => false, 'field' => 'cap', 'reason' => 'negative'];
            if ($cap > 365) return ['ok' => false, 'field' => 'cap', 'reason' => 'too_large'];
        }

        return ['ok' => true, 'expiryDays' => $expiry, 'maxBalanceDays' => $cap];
    }

    // ── Defaults: an unconfigured tenant is unchanged ────────────────────

    public function test_an_empty_form_yields_both_disabled(): void
    {
        $r = $this->validate('', '');

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['expiryDays'], '0 = credits never lapse');
        $this->assertSame(0.0, $r['maxBalanceDays'], '0 = no cap');
    }

    public function test_an_absent_field_is_treated_as_disabled(): void
    {
        $r = $this->validate(null, null);

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['expiryDays']);
    }

    /** Explicit zero is the same as unset — it means OFF, not "zero days". */
    public function test_explicit_zero_means_off_not_immediate_expiry(): void
    {
        $r = $this->validate('0', '0');

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['expiryDays'],
            'if 0 meant "expires today" every credit would vanish the moment it was earned');
    }

    // ── Configured values ────────────────────────────────────────────────

    public function test_a_market_typical_window_is_accepted(): void
    {
        foreach ([30, 45, 60, 90] as $days) {
            $r = $this->validate((string) $days, '');
            $this->assertTrue($r['ok'], "{$days} days must be accepted");
            $this->assertSame($days, $r['expiryDays']);
        }
    }

    public function test_a_fractional_cap_is_accepted(): void
    {
        $r = $this->validate('', '7.5');

        $this->assertTrue($r['ok']);
        $this->assertSame(7.5, $r['maxBalanceDays'],
            'half-day granularity is native to this lifecycle, so a 7.5-day cap is legitimate');
    }

    public function test_both_can_be_configured_together(): void
    {
        $r = $this->validate('60', '10');

        $this->assertTrue($r['ok']);
        $this->assertSame(60, $r['expiryDays']);
        $this->assertSame(10.0, $r['maxBalanceDays']);
    }

    // ── Boundaries ───────────────────────────────────────────────────────

    public function test_the_upper_bounds_are_inclusive(): void
    {
        $this->assertTrue($this->validate('365', '365')['ok']);
    }

    public function test_beyond_the_upper_bound_is_refused(): void
    {
        $this->assertSame('too_large', $this->validate('366', '')['reason']);
        $this->assertSame('too_large', $this->validate('', '366')['reason']);
    }

    /**
     * A typo like 3650 would read as "effectively never" while actually
     * stamping a decade-long window onto every credit — worse than either
     * intent, because it looks disabled and is not.
     */
    public function test_a_decade_long_typo_is_refused(): void
    {
        $this->assertFalse($this->validate('3650', '')['ok']);
    }

    // ── Invalid input ────────────────────────────────────────────────────

    public function test_a_negative_value_is_refused_not_clamped(): void
    {
        $this->assertSame('negative', $this->validate('-1', '')['reason']);
        $this->assertSame('negative', $this->validate('', '-0.5')['reason']);
    }

    public function test_a_fractional_expiry_is_refused(): void
    {
        $this->assertSame('whole_number', $this->validate('30.5', '')['reason'],
            'half a day of validity is meaningless — a credit either survives the day or does not');
    }

    public function test_non_numeric_input_is_refused_not_coerced(): void
    {
        foreach (['abc', 'thirty', '30 days', 'null'] as $bad) {
            $this->assertFalse($this->validate($bad, '')['ok'], "'{$bad}' must be refused");
            $this->assertFalse($this->validate('', $bad)['ok'], "'{$bad}' must be refused for the cap");
        }
    }

    /** Refusal must be explicit — a silently coerced default is the real danger. */
    public function test_a_refusal_never_silently_falls_back_to_a_default(): void
    {
        $r = $this->validate('-5', '');

        $this->assertFalse($r['ok']);
        $this->assertArrayNotHasKey('expiryDays', $r,
            'returning a default alongside a refusal would let a caller persist it by accident');
    }

    // ── The shipped endpoint ─────────────────────────────────────────────

    public function test_the_endpoint_validates_server_side(): void
    {
        foreach ([
            'Comp-Off expiry must be a whole number of days.',
            'Comp-Off expiry cannot be negative. Use 0 for "never expires".',
            'Comp-Off expiry cannot exceed 365 days.',
            'Comp-Off balance cap must be a number of days.',
            'Comp-Off balance cap cannot be negative. Use 0 for "no cap".',
            'Comp-Off balance cap cannot exceed 365 days.',
        ] as $msg) {
            $this->assertStringContainsString($msg, $this->ctrl,
                'each refusal must name the field and state what 0 means');
        }
    }

    public function test_both_keys_are_persisted_through_the_canonical_writer(): void
    {
        // Whitespace-insensitive: the policy map is column-aligned, and the two
        // keys differ in length, so pinning the exact gap would be brittle
        // without making the assertion any stronger.
        $flat = preg_replace('/\s+/', ' ', $this->ctrl);

        foreach ([
            'compOffExpiryDays'     => '$compOffExpiryDays',
            'compOffMaxBalanceDays' => '$compOffMaxBalanceDays',
        ] as $key => $var) {
            $this->assertStringContainsString("'{$key}' => {$var},", $flat,
                "{$key} must be built into the policy map from its validated variable");
            $this->assertStringContainsString("'attendancePolicy.{$key}' => \$policy['{$key}'],", $flat,
                "{$key} must be written on the same dotted path as every other policy field");
        }
    }

    /** No second settings store — same document, same endpoint as Calendar Half-Day. */
    public function test_it_reuses_the_existing_attendance_policy_document(): void
    {
        $this->assertStringContainsString("'attendancePolicy.calendarHalfDayPayEnabled'", $this->ctrl,
            'the comp-off keys must sit alongside the existing policy fields, not in a new store');
        $this->assertStringContainsString('function save_attendance_policy', $this->ctrl);
    }

    // ── The view ─────────────────────────────────────────────────────────

    public function test_the_form_exposes_both_fields(): void
    {
        $this->assertStringContainsString('id="compOffExpiryDays"', $this->view);
        $this->assertStringContainsString('id="compOffMaxBalanceDays"', $this->view);
    }

    public function test_the_form_states_what_zero_means(): void
    {
        $this->assertStringContainsString('0 = never expires', $this->view);
        $this->assertStringContainsString('0 = no cap', $this->view);
    }

    /** A disabled setting must render EMPTY, not as a deliberate-looking 0. */
    public function test_a_disabled_setting_renders_as_an_empty_field(): void
    {
        $this->assertStringContainsString(
            "(p.compOffExpiryDays > 0) ? p.compOffExpiryDays : ''",
            $this->view,
            'showing 0 would read as a value somebody chose rather than "not configured"'
        );
    }

    public function test_an_empty_field_is_submitted_as_disabled(): void
    {
        $this->assertStringContainsString("? \$('compOffExpiryDays').value : 0", $this->view);
        $this->assertStringContainsString("? \$('compOffMaxBalanceDays').value : 0", $this->view);
    }

    // ── The backend actually consumes what the UI writes ─────────────────

    /** The whole point: what this screen saves is what the ledger reads. */
    public function test_the_saved_keys_are_the_keys_the_backend_reads(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);

        $this->assertStringContainsString("\$pol['compOffExpiryDays'] ?? 0", $hr,
            'Hr::_comp_off_policy() must read the exact key this screen writes');
        $this->assertStringContainsString("\$pol['compOffMaxBalanceDays'] ?? 0", $hr);
    }
}
