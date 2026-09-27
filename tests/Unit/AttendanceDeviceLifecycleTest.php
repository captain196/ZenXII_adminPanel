<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Attendance device authentication and lifecycle.
 *
 * A biometric / RFID / face reader punches through `attendance/api_punch`,
 * authenticated by an API key alone — no session, no user. Whatever it writes
 * becomes attendance, and attendance becomes pay. So the questions that matter
 * are: who can authenticate, and does taking that ability away actually work.
 *
 * DEV-1 (found and fixed 2026-08-23) is the second one.
 */
final class AttendanceDeviceLifecycleTest extends TestCase
{
    private static string $att = '';

    public static function setUpBeforeClass(): void
    {
        self::$att = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = '';
    }

    private function method(string $name): string
    {
        $i = strpos(self::$att, 'function ' . $name);
        $this->assertNotFalse($i, "{$name} must exist");

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos(self::$att, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }
        $this->assertNotEmpty($next);
        return substr(self::$att, (int) $i, min($next) - (int) $i);
    }

    // ── DEV-1 — the status toggle was decorative ─────────────────────────

    /**
     * DEFECT FIXED. `update_device` accepted and stored status=inactive, and the
     * device screen offered it — but `_validate_api_key()` resolves a punch from
     * the key index alone and never reads the device's status. An "inactive"
     * device kept punching, while the administrator was told it was disabled.
     *
     * The worst shape a security control can take: it reports success.
     */
    public function test_deactivating_a_device_revokes_its_key(): void
    {
        $body = $this->method('update_device');

        $this->assertStringContainsString(
            "if (isset(\$updates['status'])) {", $body,
            'a status change must reach authentication, not just storage');
        $this->assertStringContainsString('$this->_apply_device_status($deviceId, $updates[\'status\']);', $body);

        $apply = $this->method('_apply_device_status');
        $this->assertStringContainsString("\$this->fs->remove('attendanceDeviceKeys', \$hash);", $apply,
            'the key index is what the punch path authenticates against');
    }

    /**
     * Revocation is INSTANT, not TTL-delayed. `_validate_api_key()` caches a
     * successful lookup for API_KEY_CACHE_TTL, so dropping the index alone
     * would leave a decommissioned reader punching for the rest of the window.
     */
    public function test_revocation_busts_the_auth_cache(): void
    {
        $apply = $this->method('_apply_device_status');

        $this->assertStringContainsString('$this->_cache_delete("api_key_{$hash}");', $apply);

        // The same instant-revocation idiom the other two paths already use.
        foreach (['delete_device', 'regenerate_key'] as $m) {
            $this->assertStringContainsString('_cache_delete("api_key_', $this->method($m),
                "{$m} must also revoke instantly — this is one idiom, not three");
        }
    }

    /** Reactivating restores authentication from the stored hash. */
    public function test_reactivating_a_device_restores_it(): void
    {
        $apply = $this->method('_apply_device_status');

        $this->assertStringContainsString("\$this->fs->set('attendanceDeviceKeys', \$hash, [", $apply);
        $this->assertStringContainsString("'deviceId'      => \$deviceId,", $apply);
        $this->assertStringContainsString("'schoolId'      => \$this->school_id,", $apply,
            'the restored index entry must carry the tenant, as the original did');
    }

    /** Both directions are audited — this is a security event either way. */
    public function test_both_directions_are_audited(): void
    {
        $apply = $this->method('_apply_device_status');

        $this->assertStringContainsString('DEVICE_DEACTIVATED', $apply);
        $this->assertStringContainsString('DEVICE_REACTIVATED', $apply);
    }

    /**
     * A device with no stored hash cannot have its status enforced, and that is
     * logged rather than passing silently — otherwise the administrator is back
     * to believing a toggle worked when it did not.
     */
    public function test_an_unenforceable_status_change_is_logged(): void
    {
        $apply = $this->method('_apply_device_status');

        $this->assertStringContainsString('has no key hash', $apply);
        $this->assertStringContainsString("log_message('error'", $apply);
    }

    /** Both field spellings are tolerated when reading the hash. */
    public function test_the_hash_is_read_under_either_spelling(): void
    {
        $apply = $this->method('_apply_device_status');

        $this->assertStringContainsString("\$dev['apiKeyHash'] ?? \$dev['api_key_hash'] ?? ''", $apply,
            'regenerate_key writes apiKeyHash while older records carry api_key_hash; '
            . 'delete_device already tolerates both and this must too');
    }

    // ── The authentication path itself ──────────────────────────────────

    /** Keys are stored and compared as hashes, never in the clear. */
    public function test_keys_are_never_stored_in_the_clear(): void
    {
        $validate = $this->method('_validate_api_key');

        $this->assertStringContainsString("\$keyHash = hash('sha256', \$rawKey);", $validate);
        $this->assertStringContainsString("\$this->fs->get('attendanceDeviceKeys', \$keyHash)", $validate,
            'the index is keyed BY the hash, so a database read never reveals a key');

        $regen = $this->method('regenerate_key');
        $this->assertStringContainsString('bin2hex(random_bytes(32))', $regen,
            'a device key must come from a CSPRNG');
        $this->assertStringContainsString("hash('sha256', \$rawKey)", $regen);
    }

    /** Brute force against the key space is rate limited per IP. */
    public function test_failed_authentication_is_rate_limited(): void
    {
        $validate = $this->method('_validate_api_key');

        $this->assertStringContainsString('MAX_FAILED_ATTEMPTS', $validate);
        $this->assertStringContainsString('$this->input->ip_address()', $validate);
        $this->assertStringContainsString('API key rate limit exceeded', $validate);
    }

    /** A short key is rejected before any lookup happens. */
    public function test_a_trivial_key_is_rejected_outright(): void
    {
        $this->assertStringContainsString('if (!$rawKey || strlen($rawKey) < 16) return false;',
            $this->method('_validate_api_key'));
    }

    // ── What a punch may do once authenticated ──────────────────────────

    /** A device may only punch someone who belongs to ITS school. */
    public function test_a_device_cannot_punch_into_another_tenant(): void
    {
        $punch = $this->method('api_punch');

        $this->assertStringContainsString(
            "\$this->fs->get('staff', \"{\$schoolIdForCheck}_{\$personId}\")", $punch);
        $this->assertStringContainsString("'Staff ID does not belong to this school.', 403", $punch);
        $this->assertStringContainsString('$schoolIdForCheck = (string) ($auth[\'school_id\'] ?? \'\');', $punch,
            'the tenant must come from the API KEY, never from a header or session');
    }

    /** An inactive employee cannot punch, matching the GPS path. */
    public function test_an_inactive_employee_cannot_punch(): void
    {
        $punch = $this->method('api_punch');

        $this->assertStringContainsString("if (\$sStatus !== 'active') {", $punch);
        $this->assertStringContainsString("'Staff member is not active.', 403", $punch);
    }

    /** A device punch writes through the canonical writer, like every other path. */
    public function test_a_device_punch_uses_the_canonical_writer(): void
    {
        $punch = $this->method('api_punch');

        $this->assertStringContainsString('$this->staff_attendance_writer->markSingleDay(', $punch);
        $this->assertStringContainsString("'source'       => 'punch',", $punch);
        $this->assertStringContainsString("'markedBy'     => 'device:' . \$deviceId,", $punch,
            'the ledger must record WHICH device wrote the day');
    }

    /** A closed month refuses a device punch too, with its own status code. */
    public function test_a_device_cannot_punch_into_a_closed_month(): void
    {
        $punch = $this->method('api_punch');

        $this->assertStringContainsString('} catch (MonthLockedException $e) {', $punch);
        $this->assertStringContainsString("'Attendance for this month is locked.', 409", $punch);
    }

    /** A retried punch is not counted twice. */
    public function test_a_replayed_punch_is_idempotent(): void
    {
        $punch = $this->method('api_punch');

        $this->assertStringContainsString('attendanceProcessedEvents', $punch);
        $this->assertStringContainsString("'idempotent' => true", $punch);
        $this->assertStringContainsString("preg_match('/^[A-Za-z0-9_\-]{8,64}$/', \$eventId)", $punch,
            'the dedupe key must be validated before it is used to compose a doc id');
    }

    /** No session, no punch — a punch is never recorded against a guessed year. */
    public function test_a_punch_without_a_resolved_session_is_refused(): void
    {
        $punch = $this->method('api_punch');

        $this->assertStringContainsString("if (\$activeSession === '') {", $punch);
        $this->assertStringContainsString('Punch rejected.', $punch);
    }
}
