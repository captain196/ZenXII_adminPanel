<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Fee_overdue_policy.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ONE DEFINITION OF "OVERDUE", OWNED BY THE SERVER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT WAS WRONG ───────────────────────────────────────────────────────
 * "Overdue" was decided in two places that disagreed. The server had no
 * overdue concept at all — only `late_fee_grace_days`, which governs FINES,
 * defaults to 0, and applies only when late fees are enabled. The Parent App
 * hard-coded 15 days, inherited from a month-label heuristic.
 *
 * Measured on a device 2026-08-27 against a demand due 2026-04-10: genuinely
 * 139 days past due, displayed as "Overdue · 124d", and not shown as overdue
 * at all for the first fifteen — while a fine could already have accrued.
 *
 * ── THE FOUR CONCEPTS THAT MUST NOT COLLAPSE ─────────────────────────────
 *   due_day_of_month      which day a monthly demand falls due
 *   days_before_due       when a reminder goes out BEFORE due
 *   late_fee_grace_days   days after due before a FINE applies
 *   overdue_grace_days    days after due before it is PRESENTED as overdue
 *
 * A school may run late fees OFF and still want quiet days before a parent is
 * told they are late; another may fine from day one but want the screen to say
 * overdue immediately. Deriving presentation from `late_fee_grace_days` would
 * make both impossible and would change what parents see the moment a bursar
 * enabled late fees.
 *
 * ── WHY THE APP GETS A DATE, NOT THE POLICY ──────────────────────────────
 * `feeSettings` also holds the gateway `api_secret`, so the Parent App must
 * never read it, and a new parent-readable config collection would need its
 * own Firestore rule. The server stamps the derived `overdueFrom` onto each
 * demand instead — the app compares a date to today and holds no policy.
 *
 * Proven at runtime on the emulator: default policy → `overdueFrom == dueDate`
 * on all 25 demands; `overdue_grace_days = 15` → a consistent 15-day gap on
 * all 25.
 */
final class OverduePolicyAuthorityTest extends TestCase
{
    // ── THE CLAMP ────────────────────────────────────────────────────────

    /**
     * @dataProvider clampCases
     */
    public function test_clamp_normalizes_configured_values($raw, int $want, string $why): void
    {
        $this->assertSame($want, Fee_overdue_policy::clamp($raw), $why);
    }

    public static function clampCases(): array
    {
        return [
            'absent means default'   => [null, 0, 'a school that never configured this gets the default'],
            'blank means default'    => ['', 0, 'an empty form field must not become a grace period'],
            'garbage means default'  => ['abc', 0, 'a non-numeric setting was trusted'],
            'negative is floored'    => [-5, 0, 'a negative grace would mark a demand overdue BEFORE it was due'],
            'zero is honoured'       => [0, 0, ''],
            'a real value passes'    => [15, 15, ''],
            'numeric string passes'  => ['15', 15, 'the settings form posts strings'],
            'float truncates'        => [3.9, 3, ''],
            'absurd is capped'       => [4000, 365, 'an absurd value would silently disable the concept'],
            'the cap itself'         => [365, 365, ''],
        ];
    }

    public function test_the_default_is_zero_not_the_apps_old_fifteen(): void
    {
        // The whole point. Defaulting to 15 would bake the Parent App's
        // unanchored number into the server and give every school a grace
        // period nobody chose.
        $this->assertSame(0, Fee_overdue_policy::DEFAULT_GRACE,
            'the default grace is not 0 — schools would inherit a policy nobody set');
    }

    // ── THE BOUNDARY ─────────────────────────────────────────────────────

    public function test_zero_grace_makes_the_due_date_the_boundary(): void
    {
        $this->assertSame('2026-04-10',
            Fee_overdue_policy::overdueFrom('2026-04-10', 0));
    }

    public function test_grace_moves_the_boundary_forward(): void
    {
        $this->assertSame('2026-04-25', Fee_overdue_policy::overdueFrom('2026-04-10', 15));
        $this->assertSame('2026-05-10', Fee_overdue_policy::overdueFrom('2026-04-10', 30));
    }

    public function test_the_boundary_crosses_months_and_years_correctly(): void
    {
        // Date arithmetic, not string arithmetic.
        $this->assertSame('2027-01-04', Fee_overdue_policy::overdueFrom('2026-12-20', 15));
        $this->assertSame('2026-03-02', Fee_overdue_policy::overdueFrom('2026-02-15', 15));
    }

    public function test_an_iso_timestamp_is_accepted(): void
    {
        // Some writers stamp a full timestamp; only the date part governs.
        $this->assertSame('2026-04-25',
            Fee_overdue_policy::overdueFrom('2026-04-10T09:30:00+05:30', 15));
    }

    /**
     * @dataProvider unusableDueDates
     */
    public function test_an_unusable_due_date_yields_no_boundary(string $due, string $why): void
    {
        // An absent due date is NOT evidence of lateness. Inventing one is how
        // PARENT-DUE-1 made a charge raised today look seven months overdue.
        $this->assertSame('', Fee_overdue_policy::overdueFrom($due, 15), $why);
    }

    public static function unusableDueDates(): array
    {
        return [
            'empty'        => ['', 'an empty due date produced a boundary'],
            'whitespace'   => ['   ', 'whitespace produced a boundary'],
            'not a date'   => ['soon', 'a non-date produced a boundary'],
            'partial'      => ['2026-04', 'an incomplete date produced a boundary'],
            'wrong order'  => ['10-04-2026', 'a non-ISO date was accepted'],
        ];
    }

    public function test_the_boundary_clamps_its_own_grace(): void
    {
        // A caller passing an unclamped value must not bypass the policy.
        $this->assertSame('2026-04-10', Fee_overdue_policy::overdueFrom('2026-04-10', -30));
        $this->assertSame(
            Fee_overdue_policy::overdueFrom('2026-04-10', 365),
            Fee_overdue_policy::overdueFrom('2026-04-10', 99999),
            'an absurd grace was not capped at the boundary');
    }

    // ── THE CONCEPTS STAY SEPARATE ───────────────────────────────────────

    public function test_overdue_grace_is_not_the_late_fee_grace(): void
    {
        // Guards the design decision, not just the code. If a future change
        // makes the policy read `late_fee_grace_days`, a school running late
        // fees OFF would lose the ability to set quiet days, and enabling late
        // fees would silently change what every parent sees.
        $src = (string) file_get_contents(APPPATH . 'libraries/Fee_overdue_policy.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else { $code .= $t; }
        }
        $this->assertStringNotContainsString('late_fee_grace_days', $code,
            'the overdue policy now reads the LATE FEE grace — two different questions '
            . 'sharing one answer');
        $this->assertStringContainsString('overdue_grace_days', $code,
            'the overdue policy no longer reads its own field');
    }

    public function test_the_settings_writer_uses_the_same_clamp(): void
    {
        // A second clamp in the controller would drift from this one.
        $fm = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $this->assertStringContainsString('Fee_overdue_policy::clamp(', $fm,
            'the settings writer clamps the value itself instead of using the policy');
    }

    public function test_the_demand_generator_derives_rather_than_recomputes(): void
    {
        // Fee_lifecycle must not re-implement the arithmetic.
        $fl = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('Fee_overdue_policy::overdueFrom(', $fl,
            'the demand generator computes the boundary itself instead of deriving it');
    }

    public function test_the_write_boundary_carries_the_field(): void
    {
        // Found the hard way: the first attempt stamped `overdueFrom` in the
        // generator and 0 of 25 demands carried it, because _buildDemandDoc is
        // an explicit allowlist that silently drops unknown fields.
        $txn = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $this->assertStringContainsString("'overdueFrom'", $txn,
            '_buildDemandDoc does not name overdueFrom — it will be dropped on write '
            . 'and every demand will reach the app without a boundary');
    }
}
