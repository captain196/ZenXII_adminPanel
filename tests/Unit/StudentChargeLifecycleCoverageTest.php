<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once APPPATH . 'libraries/Student_charge_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  STUDENT CHARGES — THE LIFECYCLE STAGES NOTHING ELSE COVERED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A coverage audit of the existing 67 Student-Charge tests found every stage
 * exercised except **duplicate / repeat charge** and **session behaviour**.
 * This file closes those two, and records the cross-system contract that the
 * device test made concrete.
 *
 * Driven against the REAL service with a lifecycle double, so the decisions
 * under test are the production ones.
 */
class StudentChargeLifecycleCoverageTest extends TestCase
{
    private const SCHOOL  = 'SCH_COVERAGE';
    private const SESSION = '2026-27';
    private const STUDENT = 'STU0012';

    /** Records every payload createModuleFee is called with. */
    private function lifecycle()
    {
        return new class {
            public array $calls = [];
            public function createModuleFee(string $studentId, string $moduleType, array $feeData): ?string
            {
                $this->calls[] = $feeData;
                if ((float) ($feeData['amount'] ?? 0) <= 0) return null;
                // Mirrors the real id: the chargeInstance is what separates two
                // charges on one head in one period.
                return 'DEM_' . $studentId . '_202604_TUITION_FEE_STUDENTCHARGE_'
                     . ($feeData['chargeInstance'] ?? '');
            }
        };
    }

    private function firebase()
    {
        return new class {
            public array $store = [];
            private int $c = 0;
            public function firestoreGet($col, $id) { return $this->store["{$col}/{$id}"] ?? null; }
            public function firestoreSet($col, $id, $d, $m = false): bool
            {
                $k = "{$col}/{$id}";
                $this->store[$k] = ($m && isset($this->store[$k])) ? array_merge($this->store[$k], $d) : $d;
                $this->store[$k]['__updateTime'] = 'T' . (++$this->c);
                return true;
            }
            public function firestoreCommitBatchStatus(array $ops): array
            {
                return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
            }
        };
    }

    private function svc($fb, $lc): Student_charge_service
    {
        return (new Student_charge_service())->init($fb, $lc, self::SCHOOL, self::SESSION, 'admin_1');
    }

    private function raise($svc, array $over = []): array
    {
        return $svc->raise(array_merge([
            'studentId' => self::STUDENT,
            'feeHead'   => 'Tuition Fee',
            'amount'    => 350.0,
            'reason'    => 'lost book',
        ], $over));
    }

    // ══ DUPLICATE / REPEAT CHARGE ═══════════════════════════════════════════

    /**
     * ID-D2, stated as a decision rather than left as a gap.
     *
     * Two charges on the SAME head, in the SAME period, for the SAME student
     * must remain TWO demands. A school can legitimately charge twice — two
     * lost books, two breakages — and collapsing them would mean the second
     * silently REWRITES the first and the school loses the money with no error.
     *
     * The separator is the charge's own id, used as the demand's
     * `chargeInstance`.
     */
    public function test_two_charges_on_one_head_stay_two_distinct_demands(): void
    {
        $lc  = $this->lifecycle();
        $svc = $this->svc($this->firebase(), $lc);

        $a = $this->raise($svc, ['reason' => 'first lost book']);
        $b = $this->raise($svc, ['reason' => 'second lost book']);

        $this->assertTrue($a['ok']); $this->assertTrue($b['ok']);
        $this->assertNotSame($a['chargeId'], $b['chargeId'], 'two charges share one charge id');
        $this->assertNotSame($a['demandId'], $b['demandId'],
            'two charges on the same head in the same period collapsed onto ONE demand. The '
            . 'second would rewrite the first and the school would lose that money silently.');

        $this->assertNotSame($lc->calls[0]['chargeInstance'], $lc->calls[1]['chargeInstance'],
            'the chargeInstance is not unique per charge, so it cannot separate the demands');
    }

    /**
     * The instance must be the charge's OWN id — not a counter, timestamp or
     * random value generated separately. Anything else would make the demand
     * id unreproducible from stored data.
     */
    public function test_the_demand_instance_is_the_charge_id(): void
    {
        $lc  = $this->lifecycle();
        $svc = $this->svc($this->firebase(), $lc);

        $r = $this->raise($svc);

        $this->assertSame($r['chargeId'], $lc->calls[0]['chargeInstance'],
            'the demand instance is derived from something other than the charge id, so the '
            . 'demand id cannot be rebuilt from canonical stored data');
        $this->assertStringContainsString($r['chargeId'], $r['demandId']);
    }

    /**
     * Repeat charges are a school decision, so the SERVICE must not dedupe
     * them. Recorded because "add idempotency" looks like an unambiguous
     * improvement and would break a legitimate second charge.
     *
     * The accidental double-submit is handled where it belongs — the panel
     * disables the button for the duration of the request.
     */
    public function test_the_service_does_not_dedupe_repeat_charges(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Student_charge_service.php');

        foreach (['idempot', 'dedup'] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($src),
                "Student_charge_service now dedupes. Two charges on one head in one period are "
                . 'a legitimate school action (two lost books) — refusing the second is a '
                . 'money-losing behaviour, not a safety feature.');
        }

        $view = (string) file_get_contents(APPPATH . 'views/fee_management/student_charges.php');
        $this->assertStringContainsString('btn.disabled = true;', $view,
            'the panel no longer guards against a double-click, which is where an ACCIDENTAL '
            . 'repeat is prevented — the service deliberately allows a deliberate one');
    }

    // ══ SESSION BEHAVIOUR ═══════════════════════════════════════════════════

    /**
     * The charge record is session-stamped, so a charge belongs to the year it
     * was raised in and does not follow the student across a rollover.
     */
    public function test_a_charge_is_stamped_with_the_session_it_was_raised_in(): void
    {
        $fb  = $this->firebase();
        $svc = $this->svc($fb, $this->lifecycle());

        $r   = $this->raise($svc);
        $doc = $fb->store['studentCharges/' . self::SCHOOL . '_' . $r['chargeId']];

        $this->assertSame(self::SESSION, $doc['session'],
            'the charge is not session-stamped, so it would appear in every session\'s list');
        $this->assertSame(self::SCHOOL, $doc['schoolId']);
        $this->assertSame(self::STUDENT, $doc['studentId']);
    }

    /**
     * The list endpoint filters by session, so last year's charges do not
     * surface in this year's view. Pinned because the query is the only thing
     * enforcing it.
     */
    public function test_the_list_is_session_scoped(): void
    {
        $ctrl = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $at   = strpos($ctrl, 'public function list_student_charges');
        $body = substr($ctrl, $at, 1200);

        $this->assertStringContainsString("['session',   '==', \$this->session_year],", $body,
            'the charge list is no longer session-scoped — every year\'s charges would appear '
            . 'together, and the outstanding total would be wrong');
        $this->assertStringContainsString("['schoolId',  '==', \$this->school_name],", $body,
            'the charge list lost its tenant predicate');
    }

    // ══ DUE DATE — DUE-2 ════════════════════════════════════════════════════

    /**
     * The due date must reach `createModuleFee`, and from there the demand.
     * DUE-2 proved on a real device that a missing one makes the Parent app
     * fall back to inferring a date from the month label — showing a charge
     * raised today as "Overdue · 129d".
     */
    public function test_the_charge_supplies_a_due_date_to_the_generator(): void
    {
        $lc  = $this->lifecycle();
        $svc = $this->svc($this->firebase(), $lc);

        $this->raise($svc);

        $this->assertArrayHasKey('dueDate', $lc->calls[0],
            'the service no longer supplies a due date. A dateless demand is invisible to the '
            . 'late-fee engine and the reminder service, and displays as months overdue.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $lc->calls[0]['dueDate']);

        $expected = date('Y-m-d', strtotime('+' . Student_charge_service::DEFAULT_DUE_DAYS . ' days'));
        $this->assertSame($expected, $lc->calls[0]['dueDate'],
            'the default payment window changed — confirm it is deliberate');
    }

    /** A caller may shorten or lengthen the window, but never remove it. */
    public function test_the_payment_window_can_be_set_but_not_removed(): void
    {
        $lc  = $this->lifecycle();
        $svc = $this->svc($this->firebase(), $lc);

        $this->raise($svc, ['dueDays' => 7]);
        $this->assertSame(date('Y-m-d', strtotime('+7 days')), $lc->calls[0]['dueDate']);

        // A negative window is nonsense and must fall back, not produce a date
        // in the past — that would be born overdue.
        $this->raise($svc, ['dueDays' => -30]);
        $expected = date('Y-m-d', strtotime('+' . Student_charge_service::DEFAULT_DUE_DAYS . ' days'));
        $this->assertSame($expected, $lc->calls[1]['dueDate'],
            'a negative payment window produced a past due date, so the charge would be '
            . 'overdue the moment it was raised');
    }

    // ══ CROSS-SYSTEM CONTRACT ═══════════════════════════════════════════════

    /**
     * Student Charges require **no** Parent-app-specific code: they converge
     * into `feeDemands` and are rendered by the same path as every other
     * demand. Verified by searching the app for any charge-specific symbol.
     *
     * This is the property that makes the whole design worth having — a new
     * charge type costs nothing on the client.
     */
    public function test_the_parent_app_needs_no_student_charge_specific_code(): void
    {
        $appDir = 'D:/Projects/SchoolSyncParent/app/src/main/java';
        if (!is_dir($appDir)) {
            $this->markTestSkipped('Parent app source not present on this machine.');
        }

        $hits = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
        foreach ($it as $f) {
            if (substr((string) $f, -3) !== '.kt') continue;
            $src = (string) file_get_contents((string) $f);
            if (stripos($src, 'studentCharge') !== false || stripos($src, 'chargeInstance') !== false) {
                $hits[] = basename((string) $f);
            }
        }

        $this->assertSame([], $hits,
            'the Parent app now carries Student-Charge-specific code: ' . implode(', ', $hits)
            . '. Charges converge into feeDemands precisely so the client needs no per-charge-'
            . 'type handling; a special case here means the convergence has been broken.');
    }
}
