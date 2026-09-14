<?php

use PHPUnit\Framework\TestCase;

/**
 * Teacher Workload asks "which timetable is in force on this date". That is a
 * question, and answering it must not change anything.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 *
 * Academic::get_canonical_timetable() opened with ensureRegularPlan(), which
 * CREATES the `timetablePlans` document — plus an audit 'create' row — when
 * none exists. Two consequences, both worst at a brand-new school, which is
 * exactly where no plan exists yet:
 *
 *   1. The endpoint grants `view` (and the Teacher role). The first person to
 *      open the Workload tab authored a tenant record they had no permission
 *      to author, under their own uid.
 *   2. If that write failed, a school whose only real state is "no timetable
 *      yet" got "The timetable could not be set up" instead of being told it
 *      has no timetable yet — a hard error on day one.
 *
 * Nothing on the read path needs the document: resolveForDate() reads the
 * versions collection, and listCandidateDays() uses planId only as a query
 * filter. Publishing still creates the plan, from the edit side.
 */
final class CanonicalTimetableReadIsReadOnlyTest extends TestCase
{
    private static string $ctrl  = '';
    private static string $store = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl  = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Academic.php');
        self::$store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');
    }

    /**
     * The CODE of get_canonical_timetable(), to the next method — comments
     * stripped. The comment above the fix names ensureRegularPlan() to explain
     * why it is absent; matching raw text would read that explanation as the
     * call itself and fail on the very change it is checking for.
     */
    private function endpoint(): string
    {
        $i = strpos(self::$ctrl, 'public function get_canonical_timetable');
        $this->assertNotFalse($i, 'get_canonical_timetable() is gone');
        $next = strpos(self::$ctrl, "\n    public function ", $i + 10);
        $body = substr(self::$ctrl, $i, ($next !== false ? $next - $i : 9000));

        $code = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }

    /** THE FIX. */
    public function test_the_read_endpoint_creates_no_plan(): void
    {
        $this->assertStringNotContainsString('ensureRegularPlan', $this->endpoint(),
            'the workload read calls ensureRegularPlan(), which writes a plan '
            . 'document and an audit row — a view-permission user would author a '
            . 'tenant record, and a new school with no timetable would get a '
            . 'write error instead of "no timetable yet"');
    }

    /** No other writer sneaks in either. */
    public function test_the_read_endpoint_writes_nothing_at_all(): void
    {
        $body = $this->endpoint();
        foreach ([
            'firestoreSet', 'firestoreUpdate', 'firestoreDelete',
            'createDraft', 'publishVersion', 'supersedeVersion',
            'projectPublishedVersion', '_audit(',
        ] as $writer) {
            $this->assertStringNotContainsString($writer, $body,
                "the workload read calls {$writer}(): answering which timetable "
                . 'applies must not change what applies');
        }
    }

    /**
     * The plan id is now a named constant rather than a literal in two places.
     * A drift between them would scope listCandidateDays() to a plan that holds
     * no days, and every teacher would silently read as unassigned.
     */
    public function test_the_plan_id_has_one_definition(): void
    {
        $this->assertStringContainsString("const PLAN_REGULAR = 'REGULAR';", self::$store,
            'the plan id constant is gone');
        $this->assertStringContainsString(
            'Timetable_plan_store::PLAN_REGULAR', $this->endpoint(),
            'the read endpoint hardcodes a plan id instead of using the constant, '
            . 'so a change to the plan id would leave it querying a plan with no days');
        $this->assertStringContainsString('$planId = self::PLAN_REGULAR;', self::$store,
            'ensureRegularPlan() no longer derives its id from the constant, so the '
            . 'writer and the reader can drift apart');
    }

    /**
     * The write side still creates the plan — this fix removes a side effect
     * from a read, it does not remove the plan's creation from the product.
     */
    public function test_the_edit_side_still_creates_the_plan(): void
    {
        $lifecycle = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_lifecycle.php');
        $this->assertStringContainsString('ensureRegularPlan()', $lifecycle,
            'nothing creates the plan any more, so publishing would have no plan '
            . 'to publish into');
    }
}
