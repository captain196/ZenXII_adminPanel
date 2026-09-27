<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * GAP-1 CLOSED — the staff attendance month lock is now operable and visible.
 *
 * BEFORE. `lock_staff_attendance` / `unlock_staff_attendance` existed, were
 * routed, were permission-gated and wrote `staffAttendanceLocks` — and NOTHING
 * called them. The one control that stops a corrected day from contradicting a
 * payslip already computed from it could only be operated with a hand-made
 * POST. Worse, the register itself had no idea a lock existed: an operator
 * could mark a whole closed month and learn it was refused only at Save, as a
 * generic failure.
 *
 * NO NEW POLICY WAS INVENTED. Every rule enforced here already existed
 * somewhere in this codebase:
 *
 *   • locking is the month-close step — lock_staff_attendance's own docblock
 *     says "called after payroll finalization";
 *   • unlock is an "admin override" that PRESERVES the audit trail — it sets
 *     isLocked=false rather than deleting the document;
 *   • re-opening demands a reason of >= 10 characters, copied verbatim from
 *     Hr::unlock_payroll(), this codebase's own bar for the same class of act;
 *   • the UI was always intended — Lock_cache's docblock says "Phase VI
 *     lock-management UI will own these invalidation calls".
 *
 * @see AttendanceMonthLockReachabilityTest  the finding this closes
 */
final class AttendanceMonthLockUiTest extends TestCase
{
    private static string $att  = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        self::$att  = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
        self::$view = (string) file_get_contents(__DIR__ . '/../../application/views/attendance/staff.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = '';
        self::$view = '';
    }

    private function method(string $name, int $fallback = 2600): string
    {
        $i = strpos(self::$att, 'function ' . $name);
        $this->assertNotFalse($i, "{$name} must exist");

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos(self::$att, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }
        return substr(self::$att, (int) $i, ($next ? min($next) : (int) $i + $fallback) - (int) $i);
    }

    // ── Reachability: the gap itself ─────────────────────────────────────

    /** The endpoints now have a caller. */
    public function test_a_screen_can_close_and_reopen_a_month(): void
    {
        $this->assertStringContainsString("postData('attendance/lock_staff_attendance'", self::$view);
        $this->assertStringContainsString("postData('attendance/unlock_staff_attendance'", self::$view);
        $this->assertStringContainsString("postData('attendance/staff_lock_status'", self::$view);
    }

    /** All three are routed. */
    public function test_the_routes_exist(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');

        foreach (['staff_lock_status', 'lock_staff_attendance', 'unlock_staff_attendance'] as $r) {
            $this->assertStringContainsString("\$route['attendance/{$r}']", $routes);
        }
    }

    /**
     * The status endpoint reads the whole session in ONE query.
     *
     * Twelve months resolved one document at a time would be twelve
     * cross-region round-trips to render a summary line.
     */
    public function test_session_lock_state_costs_one_query(): void
    {
        $body = $this->method('staff_lock_status');

        $this->assertStringContainsString("schoolWhere('staffAttendanceLocks'", $body,
            'tenant-scoped, and one query for the session');
        $this->assertSame(1, substr_count($body, 'schoolWhere('),
            'a read per month would make this a round-trip storm');
        $this->assertStringContainsString("['session', '==', \$this->session_year]", $body,
            'locks are session-scoped, matching the doc id the writers use');
    }

    // ── Protection against an accidental or unaccountable unlock ─────────

    /**
     * Re-opening a month requires a reason of at least 10 characters — the same
     * bar Hr::unlock_payroll() sets. Derived, not invented.
     */
    public function test_reopening_requires_a_reason(): void
    {
        $body = $this->method('unlock_staff_attendance');

        $this->assertStringContainsString("\$reason = trim((string) \$this->input->post('reason'));", $body);
        $this->assertStringContainsString('if (mb_strlen($reason) < 10) {', $body);
        $this->assertStringContainsString('return $this->json_error(', $body);

        // The same bar the payroll unlock sets.
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringContainsString('strlen($reason) < 10', $hr,
            'if the payroll bar moves, this one should be revisited with it');
    }

    /** Locking does NOT require a reason — closing a settled month is routine. */
    public function test_closing_a_month_does_not_demand_a_reason(): void
    {
        $body = $this->method('lock_staff_attendance');

        $this->assertStringNotContainsString("post('reason')", $body,
            'closing is the ordinary month-end step; only re-opening is exceptional');
    }

    /** The reason is recorded on the document and in the audit row. */
    public function test_the_reason_is_persisted_and_audited(): void
    {
        $body = $this->method('unlock_staff_attendance');

        $this->assertStringContainsString("'unlockReason' => \$reason,", $body);
        $this->assertStringContainsString("'reason'   => \$reason,", $body);
        $this->assertStringContainsString("_log_attendance_change('UNLOCK_STAFF_ATTENDANCE'", $body);
    }

    /** Unlock still preserves the trail rather than deleting the lock. */
    public function test_unlock_preserves_the_audit_trail(): void
    {
        $body = $this->method('unlock_staff_attendance');

        $this->assertStringContainsString("'isLocked'     => false,", $body);
        $this->assertStringContainsString("'unlockedBy'", $body);
        $this->assertStringNotContainsString('firestoreDelete', $body,
            'the lock document is the record that the month was ever closed');
    }

    /** The client validates too, so the dialog fails before a round-trip. */
    public function test_the_dialog_enforces_the_same_minimum(): void
    {
        $this->assertStringContainsString('ok.disabled = n < 10;', self::$view);
        $this->assertStringContainsString('if (reason.length < 10) return;', self::$view);
        $this->assertStringContainsString('10 characters minimum', self::$view);
    }

    /** A failed re-open keeps the dialog open so the typed reason survives. */
    public function test_a_failed_reopen_does_not_discard_the_reason(): void
    {
        $i = strpos(self::$view, 'Could not re-open the month.');
        $this->assertNotFalse($i);

        // The catch block only — the success path above it legitimately closes.
        $catchAt = strrpos(substr(self::$view, 0, (int) $i), '.catch(function (e) {');
        $this->assertNotFalse($catchAt);
        $branch = substr(self::$view, (int) $catchAt, ((int) $i - (int) $catchAt) + 500);

        $this->assertStringContainsString("err.classList.add('show')", $branch);
        $this->assertStringNotContainsString('close();', $branch,
            'closing the dialog on failure throws away what the operator typed');
        $this->assertStringContainsString('ok.disabled = text.value.trim().length < 10;', $branch,
            'the confirm button must come back so the retry is actually possible');
    }

    /** Closing is confirmed, because it stops everyone else editing. */
    public function test_closing_is_confirmed(): void
    {
        $this->assertStringContainsString("if (!confirm('Close ' + month", self::$view);
    }

    // ── The register knows about the lock ────────────────────────────────

    /**
     * A closed month is not editable by ANYONE, so the effective gate is the
     * permission AND the lock — at every site the permission already gated,
     * including the hardened save guard.
     */
    public function test_a_closed_month_disables_editing_everywhere(): void
    {
        $this->assertStringContainsString('function canEditNow() { return CAN_EDIT && !MONTH_LOCKED; }', self::$view);

        // No site may still BRANCH on the bare permission: every one of those is
        // a place a closed month would still be editable. CAN_EDIT survives only
        // in its declaration and inside canEditNow(), which is the whole point.
        $this->assertSame(0, substr_count(self::$view, '!CAN_EDIT'),
            'a leftover !CAN_EDIT is a gate that ignores the month lock');
        $this->assertSame(1, substr_count(self::$view, 'CAN_EDIT &&'),
            'the only conjunction on CAN_EDIT should be canEditNow()\'s own');
        $this->assertSame(1, substr_count(self::$view, 'var CAN_EDIT = '));

        $this->assertStringContainsString(
            'if (!canEditNow()) return;   // hardened: view-only, or a closed month, can never save',
            self::$view,
            'the save guard must consult the lock, so a tab left open across a '
            . 'month close cannot post into it');
    }

    /** Lock state rides along with the register payload. */
    public function test_the_month_fetch_reports_the_lock(): void
    {
        $body = $this->method('_fetch_staff_attendance_fs', 9000);

        $this->assertStringContainsString("'lock'               => \$this->_staff_month_lock_state(", $body);
        $this->assertStringContainsString('applyLockState(res.lock);', self::$view);
    }

    /**
     * The register reads the lock LIVE. Lock_cache is explicit that anything
     * whose correctness depends on the freshest state must bypass the cache,
     * and a register that says "open" from a stale entry invites ten minutes of
     * work on a month that cannot be saved.
     */
    public function test_the_register_reads_the_lock_live(): void
    {
        $body = $this->method('_staff_month_lock_state');

        $this->assertStringContainsString('is_locked_live(', $body);
        $this->assertStringNotContainsString('->is_locked(', $body);
    }

    /** An unreadable lock holds editing back rather than inviting a doomed edit. */
    public function test_an_unreadable_lock_does_not_read_as_open(): void
    {
        $body = $this->method('_staff_month_lock_state');

        $i = strpos($body, 'catch (\Throwable $e)');
        $this->assertNotFalse($i);
        $catch = substr($body, (int) $i, 400);

        $this->assertStringContainsString("'isLocked'  => true,", $catch);
        $this->assertStringContainsString("'readable'  => false,", $catch);

        // And the UI says so rather than silently greying out.
        $this->assertStringContainsString('Lock state unavailable', self::$view);
    }

    /** A failed load leaves the state unknown, never "open". */
    public function test_a_failed_load_does_not_leave_the_month_looking_open(): void
    {
        $this->assertSame(2, substr_count(self::$view, 'applyLockState({ readable: false })'),
            'both the error-status branch and the network catch must reset the pill');
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    /**
     * The controls are DRAWN on a manage capability and ENFORCED by the
     * endpoints. The flag decides what is visible, never what is permitted.
     */
    public function test_the_controls_are_drawn_on_manage_and_enforced_server_side(): void
    {
        $status = $this->method('staff_lock_status');
        $this->assertStringContainsString("has_permission('Attendance', 'manage')", $status);
        $this->assertStringContainsString("_require_role(self::VIEW_ROLES, 'staff_lock_status', 'Attendance', 'view')", $status,
            'reading lock state is a view act; only changing it needs manage');

        foreach (['lock_staff_att', 'unlock_staff_att'] as $action) {
            $this->assertStringContainsString(
                "_require_role(self::MANAGE_ROLES, '{$action}', 'strict')", self::$att,
                'the write endpoints keep the hard admin floor');
        }

        $this->assertStringContainsString('if (lockBusy || !LOCK_CAN_MANAGE || !LOCK_READABLE) return;', self::$view);
        $this->assertStringContainsString('LOCK_CAN_MANAGE && LOCK_READABLE', self::$view,
            'a viewer sees the state but is offered no button');
    }

    // ── Loading, disabled and idempotency ───────────────────────────────

    /** The button reports that it is working and cannot be double-fired. */
    public function test_the_action_shows_progress_and_cannot_be_double_fired(): void
    {
        $this->assertStringContainsString('function setLockBusy(on, label)', self::$view);
        $this->assertStringContainsString('btn.disabled = on;', self::$view);
        $this->assertStringContainsString("fa-spinner fa-spin", self::$view);
        $this->assertStringContainsString('if (lockBusy ||', self::$view);
    }

    /** After acting, the state is RE-READ rather than assumed. */
    public function test_the_new_state_is_re_read_not_assumed(): void
    {
        $i = strpos(self::$view, 'function doLockMonth');
        $this->assertNotFalse($i);
        $fn = substr(self::$view, (int) $i, 1400);

        $this->assertStringContainsString('loadAttendance();', $fn,
            'the register must be re-read; a locally flipped flag can disagree with the server');
        $this->assertStringContainsString('loadLockedMonths();', $fn);
    }

    /** Unlock is idempotent server-side (merge), so a retry cannot corrupt state. */
    public function test_unlock_is_idempotent(): void
    {
        $body = $this->method('unlock_staff_attendance');

        $this->assertStringContainsString('], true);', $body,
            'merge=true so unlocking a never-locked month is harmless');
    }

    /** Both writes invalidate the lock cache, or the next write would use a stale verdict. */
    public function test_both_writes_invalidate_the_cache(): void
    {
        foreach (['lock_staff_attendance', 'unlock_staff_attendance'] as $m) {
            $this->assertStringContainsString('$this->lock_cache->invalidate(', $this->method($m));
        }
    }

    // ── Tenant isolation ────────────────────────────────────────────────

    /** Every lock document is addressed by tenant AND session. */
    public function test_locks_are_scoped_to_tenant_and_session(): void
    {
        foreach (['lock_staff_attendance', 'unlock_staff_attendance'] as $m) {
            $this->assertStringContainsString(
                '$docId = "{$this->school_id}_{$this->session_year}_{$monthKey}";', $this->method($m));
        }

        $this->assertStringContainsString('$this->lock_cache->is_locked_live($this->school_id, $this->session_year',
            $this->method('_staff_month_lock_state'));
    }

    /** The status listing cannot cross tenants — schoolWhere adds the predicate. */
    public function test_the_status_listing_cannot_cross_tenants(): void
    {
        $body = $this->method('staff_lock_status');

        $this->assertStringContainsString("\$this->fs->schoolWhere(", $body);
        $this->assertStringNotContainsString('firestoreQuery(', $body,
            'a raw query would drop the tenant predicate');
    }
}
