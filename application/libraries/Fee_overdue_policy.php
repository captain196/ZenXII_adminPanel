<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  Fee_overdue_policy — THE ONE DEFINITION OF "OVERDUE"
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * "Overdue" was decided independently in two places that disagreed:
 *
 *   • the SERVER had no overdue concept at all — only `late_fee_grace_days`,
 *     which governs FINES and is gated on `late_fee_enabled`;
 *   • the PARENT APP hard-coded 15 days (`FeeDueDates.GRACE_DAYS`), inherited
 *     from a month-label heuristic when PARENT-DUE-1 moved that path onto the
 *     server `dueDate`.
 *
 * Measured on a device 2026-08-27 against a demand due 2026-04-10: genuinely
 * 139 days past due, displayed as "Overdue · 124d", and shown as NOT overdue
 * at all for the first fifteen — while a fine could already have accrued,
 * because `late_fee_grace_days` defaults to 0 and no tenant sets it.
 *
 * ── FOUR CONCEPTS THAT MUST NOT COLLAPSE ─────────────────────────────────
 * The estate already distinguishes these. This class adds the missing one
 * WITHOUT merging it into its neighbours:
 *
 *   due_day_of_month      which day a monthly demand falls due  -> dueDate
 *   days_before_due       when a reminder goes out BEFORE due
 *   late_fee_grace_days   days after due before a FINE applies  (fines only,
 *                         and only when late_fee_enabled)
 *   overdue_grace_days    days after due before a demand is PRESENTED as
 *                         overdue                                  <- NEW
 *
 * A school may legitimately run late fees OFF and still want a few days of
 * quiet before a parent is told they are late; another may fine from day one
 * but want the screen to say "overdue" immediately. Deriving presentation
 * from `late_fee_grace_days` would make both impossible, and would silently
 * change what parents see the moment a bursar enabled late fees.
 *
 * ── WHY THE DEFAULT IS 0 ─────────────────────────────────────────────────
 * With no configuration, "overdue" means exactly "past the date the school
 * set". That is the plain meaning of the word and needs no policy to justify
 * it. The alternative — defaulting to 15 — would bake the Parent App's
 * unanchored number into the server and make every school inherit a grace
 * period nobody chose.
 *
 * Schools that WANT the old behaviour set `overdue_grace_days = 15`.
 *
 * ── HOW OTHER SURFACES CONSUME THIS ──────────────────────────────────────
 * Server-side callers ask this class. The Parent App is NOT given the policy
 * value: demand generation stamps the derived `overdueFrom` date onto each
 * demand, which the app already reads. That way the app holds no policy at
 * all — it compares a date to now — and cannot drift, cannot leak the
 * `feeSettings` document (which also holds the gateway `api_secret`), and
 * needs no new collection or Firestore rule.
 */
class Fee_overdue_policy
{
    private const COL_SETTINGS = 'feeSettings';

    /** Matches Fee_late_fee_service's clamp, for the same reasons. */
    public const MIN_GRACE = 0;
    public const MAX_GRACE = 365;

    /** Absent configuration means "overdue the day after it was due". */
    public const DEFAULT_GRACE = 0;

    private $firebase = null;
    private string $schoolId = '';
    private string $session  = '';
    private ?int $memo = null;

    public function init($firebase, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->memo     = null;
        return $this;
    }

    /**
     * The school's overdue grace, in days.
     *
     * Fails OPEN to 0 — unlike the late-fee policy, which fails closed. The
     * asymmetry is deliberate: an unreadable policy must never cause a family
     * to be FINED (money), but it also must not hide a real debt from the
     * people who owe it (presentation). 0 shows the truth the demand already
     * carries, so an outage degrades to plain past-due rather than to silence.
     */
    public function graceDays(): int
    {
        if ($this->memo !== null) return $this->memo;
        if ($this->firebase === null || $this->schoolId === '' || $this->session === '') {
            return $this->memo = self::DEFAULT_GRACE;
        }

        try {
            $s = $this->firebase->firestoreGet(
                self::COL_SETTINGS, "{$this->schoolId}_{$this->session}_reminders"
            );
        } catch (\Throwable $e) {
            log_message('error',
                'Fee_overdue_policy: settings unreadable for ' . $this->schoolId
                . ' — presenting demands as overdue from their due date. ' . $e->getMessage());
            return $this->memo = self::DEFAULT_GRACE;
        }

        return $this->memo = self::clamp(
            is_array($s) ? ($s['overdue_grace_days'] ?? null) : null
        );
    }

    /**
     * Normalize a configured value. PURE, so every caller — the settings
     * writer, the demand stamper and the tests — agrees without a Firestore
     * handle, and a copy cannot drift from the source.
     *
     * A missing, blank, non-numeric or negative value becomes the default;
     * an absurd one is capped rather than silently disabling the concept.
     */
    public static function clamp($raw): int
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) return self::DEFAULT_GRACE;
        $n = (int) $raw;
        if ($n < self::MIN_GRACE) return self::MIN_GRACE;
        if ($n > self::MAX_GRACE) return self::MAX_GRACE;
        return $n;
    }

    /**
     * The LAST day on which a demand is still on time.
     *
     *     overdueFrom = dueDate + graceDays
     *
     * THE CONTRACT, stated once so every surface agrees:
     *   overdue        <=>  today >  overdueFrom
     *   days overdue    =   today -  overdueFrom   (0 on the day itself)
     *
     * So a demand due on the 10th with no grace is NOT overdue on the 10th —
     * the school said "pay by the 10th" and the 10th is not yet late — and is
     * 1 day overdue on the 11th. With 15 days of grace the same demand is on
     * time through the 25th and 1 day overdue on the 26th.
     *
     * Getting this boundary wrong by a day is the kind of error nobody
     * notices until a parent is told they are late on the day they were asked
     * to pay, so it is spelled out rather than left to each caller.
     *
     * Returns '' when there is no usable due date — an absent due date is not
     * evidence of lateness, and inventing one is how PARENT-DUE-1 made a
     * charge raised today look seven months overdue.
     *
     * PURE and static so the demand stamper, any server surface and the tests
     * all derive the same answer from the same code.
     */
    public static function overdueFrom(string $dueDate, int $graceDays): string
    {
        $due = trim($dueDate);
        if ($due === '') return '';
        // Accept 'YYYY-MM-DD' and ISO-8601 timestamps carrying that prefix.
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $due, $m)) return '';
        $ts = strtotime($m[1]);
        if ($ts === false) return '';
        $g = self::clamp($graceDays);
        return date('Y-m-d', $ts + ($g * 86400));
    }
}
