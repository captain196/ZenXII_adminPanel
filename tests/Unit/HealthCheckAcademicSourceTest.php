<?php

use PHPUnit\Framework\TestCase;

/**
 * The Academic health checks must read the store the data is actually in.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 *
 * All five legacy Academic checks read RTDB paths (`Schools/{school}/…`).
 * RTDB is retired; the reads succeed and return nothing, and the checks then
 * reported that emptiness as fact about the school:
 *
 *   Timetable Settings          FAIL "No timetable settings configured"
 *                               …while Firestore held 6 periods × 6 days
 *   Class Timetables Populated  FAIL "No class timetables configured"
 *                               …while Firestore held 18 timetable documents
 *   Curriculum / Calendar /     PASS "nothing yet"  ← VACUOUS
 *   Substitute Records
 *
 * The vacuous passes were the more dangerous half. A failing check gets
 * investigated; a green one that never queried anything gets believed. An
 * operator reading 3/6 green would conclude three subsystems were verified.
 *
 * Found in the browser 2026-09-12: Health Check reported "No timetable settings
 * configured" for a school whose Period Scheduling screen showed 6×45 minutes,
 * 8:00AM–1:00PM.
 */
final class HealthCheckAcademicSourceTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Health_check.php');
    }

    /** The academic module's checks, bounded by the next module. */
    private function academic(): string
    {
        $i = strpos(self::$src, 'private function _checks_academic');
        $this->assertNotFalse($i, '_checks_academic is gone');
        $next = strpos(self::$src, 'private function _checks_', $i + 10);
        return substr(self::$src, $i, ($next !== false ? $next - $i : 12000));
    }

    /** THE FIX: not one academic check may read the retired store. */
    public function test_no_academic_check_reads_the_retired_rtdb(): void
    {
        $a = $this->academic();

        $this->assertSame(0, substr_count($a, '$fb->get("Schools/'),
            'an academic check still reads an RTDB path, so it reports the '
            . 'emptiness of a retired store as fact about the school');
        $this->assertSame(0, substr_count($a, '$fb->shallow_get("Schools/'),
            'an academic check still shallow-reads an RTDB path');
    }

    /** Each check must name the canonical Firestore collection it verifies. */
    public function test_each_check_reads_its_canonical_collection(): void
    {
        $a = $this->academic();

        foreach ([
            'timetableSettings' => 'Timetable Settings',
            'timetables'        => 'Class Timetables Populated',
            'curriculum'        => 'Curriculum Data',
            'calendarEvents'    => 'Calendar Events',
            'substitutes'       => 'Substitute Records',
        ] as $collection => $check) {
            $this->assertStringContainsString("'{$collection}'", $a,
                "the '{$check}' check no longer reads the {$collection} collection");
        }
    }

    /**
     * A read that FAILED must not be reported as "nothing there". That
     * conflation is the whole defect class this repository has been removing —
     * an unreadable store and an empty one are different facts.
     */
    public function test_an_unreadable_read_is_a_failure_not_an_empty_result(): void
    {
        $a = $this->academic();

        // Every Firestore read in this module is fail-loud or wrapped.
        $this->assertSame(0, substr_count($a, 'firestoreQuery('),
            'a tolerant firestoreQuery() is used somewhere: it returns [] for a '
            . 'FAILED read exactly as it does for "no rows", so the check would '
            . 'report a failure as an empty school');

        $this->assertGreaterThanOrEqual(5, substr_count($a, 'catch (\Throwable $e)'),
            'not every check handles a failed read, so one could surface as a '
            . 'fatal or as silence');

        // \Exception misses \Error and \TypeError — a read that dies on a type
        // would escape the handler and take the whole page with it.
        $this->assertSame(0, substr_count($a, 'catch (\Exception'),
            'a check catches \Exception rather than \Throwable, so an \Error '
            . 'from a failed read escapes the guard');

        // All five say so in the operator's terms, not in an exception trace.
        $this->assertSame(5, substr_count($a, 'could not be read'),
            'every one of the five checks must report a failed read as a failure '
            . 'that names what could not be read');

        // …and each one is reported through _f(). Counting the MESSAGES is not
        // enough: swapping _f() for _p() keeps every message and turns an
        // unreadable store into a green tick — the precise defect being fixed.
        preg_match_all('/_([pf])\(\s*\'[^\']*could not be read/', $a, $m);
        $this->assertSame(['f', 'f', 'f', 'f', 'f'], $m[1],
            'a "could not be read" message is reported as a PASS: the check '
            . 'renders green while having verified nothing');
    }

    /** Settings are dual-written in two key shapes; reading one would re-break it. */
    public function test_both_settings_key_shapes_are_read(): void
    {
        $a = $this->academic();
        foreach (['periodsPerDay', 'No_of_periods', 'workingDays', 'Working_days'] as $key) {
            $this->assertStringContainsString($key, $a,
                "the '{$key}' settings shape is not read, so a tenant stored in "
                . 'that shape alone would report "not configured"');
        }
    }

    /** Tenant and session come from the server context, never a request value. */
    public function test_every_check_is_tenant_and_session_bound(): void
    {
        $a = $this->academic();

        $this->assertGreaterThanOrEqual(5, substr_count($a, '$this->school_id'),
            'a check is not bound to this tenant');
        $this->assertStringNotContainsString("input->get(", $a);
        $this->assertStringNotContainsString("input->post(", $a);
    }

    /**
     * The header printed the tenant code twice — "SCH_… (SCH_…)" — because
     * MY_Controller sets `school_name = school_id`. A school looking at its own
     * diagnostics should see its own name.
     */
    public function test_the_header_shows_the_human_school_name(): void
    {
        $this->assertStringContainsString(
            "\$data['school_name']  = \$this->school_display_name", self::$src,
            'the health check header shows the tenant id where the school name belongs');
    }
}
