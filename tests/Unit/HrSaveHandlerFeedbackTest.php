<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REC-D6 — a mutating action must show progress, refuse a second submit, and
 * report failure.
 *
 * Reported against Add Applicant: the Save button gave no feedback while the
 * POST was in flight. The invisible half was worse — saveApplicant() had no
 * in-flight guard, so a second click POSTs again with a blank id and creates a
 * DUPLICATE applicant, and it had no .fail() handler, so a 500 or a dropped
 * connection surfaced nothing at all.
 *
 * This is a ratchet. PROTECTED handlers must keep all three properties.
 * UNPROTECTED lists the handlers that still lack them: if one gains
 * protection, this test fails and tells you to promote it, so the list can
 * never silently rot into a false record of the module's state.
 */
final class HrSaveHandlerFeedbackTest extends TestCase
{
    /** Verified to guard, show progress, and handle failure. */
    private const PROTECTED_HANDLERS = [
        'saveApplicant',
        'saveAppraisal',
        'saveJob',
        'saveTrainingRecord',
    ];

    /**
     * Known gaps — same defect class, in modules this UAT has not reached.
     * Deliberately recorded rather than silently fixed in an unrelated pass.
     */
    private const UNPROTECTED_HANDLERS = [
        'saveAccrualMode',   // a checkbox toggle, not a form-save button: it
                             // reverts on failure and re-reflects server state on
                             // reload, so the double-submit/spinner protections
                             // this ratchet tracks don't apply the same way.
        'saveLeavePolicy',   // same shape — a generic per-school leave-policy
                             // checkbox toggle (effective-dating/pro-rata/…),
                             // reverts on failure, re-reflects on reload.
        'saveLeaveRequest',
        'saveLeaveType',
        'saveSalary',
    ];

    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/hr/index.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    private function body(string $fn): string
    {
        $i = strpos($this->src, "function {$fn}(");
        $this->assertNotFalse($i, "{$fn}() is gone");

        // Bound at the next top-level function in the same IIFE.
        $next = strpos($this->src, "\n  function ", $i + 5);
        $end  = strpos($this->src, "\n  async function ", $i + 5);
        if ($next === false || ($end !== false && $end < $next)) $next = $end;

        return substr($this->src, $i, ($next === false ? strlen($this->src) : $next) - $i);
    }

    public function test_protected_handlers_refuse_a_second_submit(): void
    {
        foreach (self::PROTECTED_HANDLERS as $fn) {
            $b = $this->body($fn);
            $this->assertMatchesRegularExpression('~window\._\w+InFlight\b~', $b,
                "{$fn}() has no in-flight guard — a double click submits twice");
            $this->assertMatchesRegularExpression('~window\._\w+InFlight\s*=\s*false~', $b,
                "{$fn}() sets the guard but never clears it — one failure disables saving for the session");
        }
    }

    public function test_protected_handlers_show_progress_and_restore_the_button(): void
    {
        foreach (self::PROTECTED_HANDLERS as $fn) {
            $b = $this->body($fn);
            $this->assertStringContainsString('fa-spinner fa-spin', $b,
                "{$fn}() gives no progress feedback");
            $this->assertMatchesRegularExpression('~\.always\(|\.html\(orig\)~', $b,
                "{$fn}() never restores the button, so it stays stuck on 'Saving…'");
        }
    }

    public function test_protected_handlers_report_transport_failure(): void
    {
        foreach (self::PROTECTED_HANDLERS as $fn) {
            $this->assertStringContainsString('.fail(', $this->body($fn),
                "{$fn}() swallows a 500 or a dropped connection silently");
        }
    }

    /** The reported case, pinned by name. */
    public function test_the_applicant_save_button_is_targeted_by_id(): void
    {
        $this->assertStringContainsString('id="btnSaveApplicant"', $this->src,
            'the button needs a stable id, not an onclick-substring selector');
        $this->assertStringContainsString("\$('#btnSaveApplicant')", $this->body('saveApplicant'));
    }

    /**
     * The ratchet. If a listed handler is protected now, promote it — do not
     * leave a stale record claiming the module is worse than it is.
     */
    public function test_the_unprotected_list_is_still_accurate(): void
    {
        $promoted = [];
        foreach (self::UNPROTECTED_HANDLERS as $fn) {
            $b = $this->body($fn);
            $hasAll = preg_match('~window\._\w+InFlight\b~', $b)
                   && strpos($b, 'fa-spinner fa-spin') !== false
                   && strpos($b, '.fail(') !== false;
            if ($hasAll) $promoted[] = $fn;
        }

        $this->assertSame([], $promoted,
            "these now guard, show progress and handle failure — move them into\n"
            . "PROTECTED_HANDLERS so the invariant is enforced for them too:\n  "
            . implode("\n  ", $promoted));
    }

    /** Every save handler is accounted for in exactly one list. */
    public function test_no_save_handler_is_unaccounted_for(): void
    {
        preg_match_all('~function (save[A-Za-z]+)\(~', $this->src, $m);
        $found = array_values(array_unique($m[1]));
        sort($found);

        $known = array_merge(self::PROTECTED_HANDLERS, self::UNPROTECTED_HANDLERS);
        sort($known);

        $this->assertSame($known, $found,
            'a save handler was added or renamed without being classified — '
            . 'add it to PROTECTED_HANDLERS or UNPROTECTED_HANDLERS');
    }
}
