<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE FINANCIAL DATE MUST NOT DEPEND ON THE HOST
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT (found auditing the first live payment, 2026-08-26) ────────
 * ZenX pinned no timezone, so PHP fell back to the host's. Receipts, journal
 * entries, demand due-dates and processed-payment markers are all stamped
 * with date('c') / date('d-m-Y'), which meant the HOST decided the ACCOUNTING
 * DATE of a payment.
 *
 * Receipt F1 was stamped `2026-08-26T06:18:07+02:00` — a dev box on
 * Europe/Berlin. The instant was correct; the offset was not. On a UTC host,
 * which is what a Lightsail box runs by default, it stops being cosmetic:
 *
 *     a payment at 00:30 IST on 27 Aug is recorded as 26 Aug
 *
 * That files the receipt AND its journal entry in the wrong accounting day,
 * and across a month boundary in the wrong accounting month. Every school
 * ZenX serves closes its books on IST dates.
 *
 * ── WHY NO TEST CAUGHT IT ────────────────────────────────────────────────
 * Every existing date assertion compared ZenX against itself, so it passed
 * under any timezone. The bug is only visible when the host's zone is
 * compared against the zone the BOOKS are kept in — which nothing asserted.
 */
final class ApplicationTimezonePinTest extends TestCase
{
    private function configSource(): string
    {
        return file_get_contents(__DIR__ . '/../../application/config/config.php');
    }

    public function test_the_application_pins_a_timezone_explicitly(): void
    {
        $this->assertMatchesRegularExpression(
            '/date_default_timezone_set\s*\(/',
            $this->configSource(),
            'config.php must pin a timezone rather than inherit the host default'
        );
    }

    public function test_the_default_is_india(): void
    {
        $this->assertMatchesRegularExpression(
            "/getenv\('APP_TIMEZONE'\)\s*\?:\s*'Asia\/Kolkata'/",
            $this->configSource(),
            'the default must be Asia/Kolkata; APP_TIMEZONE may override it'
        );
    }

    public function test_the_pin_is_validated_before_being_applied(): void
    {
        // A typo in APP_TIMEZONE must not raise a PHP warning and silently
        // leave the host default in place — the exact state being fixed.
        $this->assertStringContainsString('timezone_identifiers_list()', $this->configSource());
    }

    public function test_an_unknown_timezone_is_rejected_rather_than_applied(): void
    {
        // Behavioural: the guard the config relies on genuinely discriminates.
        $this->assertContains('Asia/Kolkata', timezone_identifiers_list());
        $this->assertNotContains('Asia/Kolkatta', timezone_identifiers_list());
    }

    /**
     * The defect itself, reproduced: the same instant lands on different
     * CALENDAR DAYS depending on the zone. This is what makes the pin a
     * money-safety control rather than a formatting preference.
     */
    public function test_the_same_instant_falls_on_different_days_across_zones(): void
    {
        $keep = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Kolkata');
            $instant = mktime(0, 30, 0, 8, 27, 2026);   // 00:30 IST, 27 Aug

            date_default_timezone_set('Asia/Kolkata');
            $ist = date('d-m-Y', $instant);
            date_default_timezone_set('UTC');
            $utc = date('d-m-Y', $instant);

            $this->assertSame('27-08-2026', $ist, 'IST must see 27 Aug');
            $this->assertSame('26-08-2026', $utc, 'UTC sees the previous day — the defect');
            $this->assertNotSame($ist, $utc, 'if these ever match, this test proves nothing');
        } finally {
            date_default_timezone_set($keep);
        }
    }

    public function test_month_boundary_lands_in_the_wrong_accounting_month(): void
    {
        // The costliest form: a 31 Mar payment booked into the previous
        // financial month, after the books for it may already be closed.
        $keep = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Kolkata');
            $instant = mktime(0, 15, 0, 4, 1, 2026);    // 00:15 IST, 1 Apr

            date_default_timezone_set('Asia/Kolkata');
            $istMonth = date('m', $instant);
            date_default_timezone_set('UTC');
            $utcMonth = date('m', $instant);

            $this->assertSame('04', $istMonth);
            $this->assertSame('03', $utcMonth, 'UTC books it into the prior month');
        } finally {
            date_default_timezone_set($keep);
        }
    }

    /**
     * THE SCHEDULED-JOB WRAPPER MUST PIN IT TOO.
     *
     * scripts/run_scheduled.php is a standalone CLI script: it requires two
     * libraries directly and never bootstraps CodeIgniter, so the pin in
     * application/config/config.php does not run in that process. Its CHILD
     * does go through index.php and IS pinned.
     *
     * Left unpinned the two disagree about the date for the 5h30m between
     * 18:30 UTC and midnight IST — and the nightly timetable job is scheduled
     * for 00:20 IST, squarely inside that window. On a UTC host the wrapper
     * would stamp the run record, and the runId built from date('Ymd'), with
     * YESTERDAY while the child correctly projected today's timetable. The run
     * history would then say a school's timetable was never materialised on the
     * day it actually was.
     *
     * Found 2026-09-13 during infrastructure verification, before the schedule
     * was installed.
     */
    public function test_the_scheduled_job_wrapper_pins_the_same_timezone(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../scripts/run_scheduled.php');

        $this->assertStringContainsString('date_default_timezone_set(', $src,
            'scripts/run_scheduled.php does not pin a timezone. It never loads '
            . 'application/config/config.php, so it inherits the HOST zone while '
            . 'the job it launches runs in IST — and every run record it writes '
            . 'would carry the wrong date around midnight IST');

        // Same resolution order as config.php, so the two cannot drift.
        $this->assertMatchesRegularExpression(
            "~getenv\('APP_TIMEZONE'\)\s*\?:\s*'Asia/Kolkata'~", $src,
            'the wrapper resolves its timezone differently from the application, '
            . 'so APP_TIMEZONE could move one and not the other');
        $this->assertStringContainsString('timezone_identifiers_list()', $src,
            'the wrapper does not validate the identifier before setting it');

        // And it must be pinned BEFORE the first timestamp is taken.
        $pin   = strpos($src, 'date_default_timezone_set(');
        $runId = strpos($src, "\$runId = 'RUN_'");
        $this->assertNotFalse($runId, 'the runId line is gone');
        $this->assertLessThan($runId, $pin,
            'the timezone is pinned after the run id is built, so the id still '
            . 'carries the host date');
    }
}
