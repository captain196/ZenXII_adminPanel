<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
require_once __DIR__ . '/../../application/libraries/Timetable_plan_service.php';

/**
 * PHASE 1 — the authoritative timetable model, proven as behaviour.
 *
 * These tests exercise the real resolution logic with real inputs. They are not
 * source-string assertions: every case below calls resolveForDate() and checks
 * what it actually returns, so a refactor that keeps the words and breaks the
 * behaviour fails here.
 *
 * What phase 1 has to establish before anything is persisted or wired:
 *
 *   1. a VERSION and an EFFECTIVE PERIOD stay distinct concepts;
 *   2. only PUBLISHED versions are ever live — drafts and candidates cannot
 *      leak to a teacher, which is what makes isolated generation safe later;
 *   3. "which timetable applied on 10 July?" is answerable and STABLE;
 *   4. an ambiguous date is REPORTED, never guessed;
 *   5. the house effective-date conventions are reused exactly.
 */
final class TimetablePlanResolutionTest extends TestCase
{
    /** A school that changed timetable in October — the case that motivated this. */
    private function session(): array
    {
        return [
            [   // Regular, Apr-Sep
                'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-2027',
                'planId' => 'PLAN_REG', 'planName' => 'Regular Timetable',
                'versionId' => 'V2', 'status' => 'published',
                'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-09-30',
            ],
            [   // Winter, Oct onwards, open-ended
                'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-2027',
                'planId' => 'PLAN_WIN', 'planName' => 'Winter Timetable',
                'versionId' => 'V1', 'status' => 'published',
                'effectiveFrom' => '2026-10-01', 'effectiveTo' => '',
            ],
            [   // A superseded revision of Regular — must never resolve
                'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-2027',
                'planId' => 'PLAN_REG', 'planName' => 'Regular Timetable',
                'versionId' => 'V1', 'status' => 'superseded',
                'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-09-30',
            ],
            [   // A draft for next term — must never resolve
                'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-2027',
                'planId' => 'PLAN_EXAM', 'planName' => 'Exam Timetable',
                'versionId' => 'V1', 'status' => 'draft',
                'effectiveFrom' => '2027-01-01', 'effectiveTo' => '2027-03-31',
            ],
        ];
    }

    // ── the question the whole architecture exists to answer ──────────────

    public function test_it_answers_which_timetable_applied_on_a_past_date(): void
    {
        $r = Timetable_plan_service::resolveForDate($this->session(), '2026-07-10');

        $this->assertSame('resolved', $r['status'],
            'the 10 July question must have a definite answer — this is the whole point');
        $this->assertSame('PLAN_REG', $r['version']['planId']);
        $this->assertSame('V2', $r['version']['versionId'],
            'the published revision must win, not the superseded one');
    }

    /** A different date in the same session legitimately gets a different timetable. */
    public function test_a_later_date_resolves_to_the_winter_timetable(): void
    {
        $r = Timetable_plan_service::resolveForDate($this->session(), '2026-11-15');

        $this->assertSame('resolved', $r['status']);
        $this->assertSame('PLAN_WIN', $r['version']['planId'],
            'multiple effective periods within ONE session is the requirement; '
            . 'October onwards is genuinely a different timetable, not a correction '
            . 'of the earlier one');
    }

    /** Boundary days belong to exactly one period, with no gap and no overlap. */
    public function test_the_transition_boundary_is_clean(): void
    {
        $sep30 = Timetable_plan_service::resolveForDate($this->session(), '2026-09-30');
        $oct01 = Timetable_plan_service::resolveForDate($this->session(), '2026-10-01');

        $this->assertSame('PLAN_REG', $sep30['version']['planId'],
            'the last day of the range is INSIDE it — effectiveTo is inclusive');
        $this->assertSame('PLAN_WIN', $oct01['version']['planId'],
            'the first day of the next range is inside that one');
    }

    /** An open-ended effectiveTo really is open-ended — the house convention. */
    public function test_an_empty_effective_to_is_open_ended(): void
    {
        $r = Timetable_plan_service::resolveForDate($this->session(), '2031-06-01');

        $this->assertSame('resolved', $r['status'],
            'an empty effectiveTo means no end date, matching the convention used '
            . 'by Policy_assignment_service and the other 136 effective-dated sites');
        $this->assertSame('PLAN_WIN', $r['version']['planId']);
    }

    // ── unpublished work must never be live ──────────────────────────────

    /**
     * This is the guarantee that makes isolated generation safe in phase 3: a
     * candidate can exist, be edited and be scored without any teacher or parent
     * ever seeing it.
     */
    public function test_drafts_candidates_and_superseded_versions_never_resolve(): void
    {
        foreach (['draft', 'candidate', 'superseded'] as $status) {
            $only = [[
                'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-2027',
                'planId' => 'P', 'versionId' => 'V', 'status' => $status,
                'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
            ]];

            $r = Timetable_plan_service::resolveForDate($only, '2026-07-10');
            $this->assertSame('none', $r['status'],
                "a '{$status}' version resolved as live — unpublished work must "
                . 'never reach a teacher');
            $this->assertNull($r['version']);
        }
    }

    // ── never guess ──────────────────────────────────────────────────────

    /**
     * Two published versions covering one date is a real possibility once
     * several plans exist. Choosing a winner — newest? narrowest? highest
     * priority? — is a product policy nobody has decided, so the resolver must
     * REPORT it. Guessing here would be exactly the silent-wrong-answer class
     * this programme exists to remove.
     */
    public function test_an_overlapping_date_is_reported_not_guessed(): void
    {
        $overlapping = [
            ['schoolId' => 'S', 'session' => 'X', 'planId' => 'A', 'planName' => 'Regular',
             'versionId' => 'V1', 'status' => 'published',
             'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-12-31'],
            ['schoolId' => 'S', 'session' => 'X', 'planId' => 'B', 'planName' => 'Winter',
             'versionId' => 'V1', 'status' => 'published',
             'effectiveFrom' => '2026-10-01', 'effectiveTo' => ''],
        ];

        $r = Timetable_plan_service::resolveForDate($overlapping, '2026-11-01');

        $this->assertSame('ambiguous', $r['status'],
            'the resolver must not pick a winner — precedence is an undecided policy');
        $this->assertNull($r['version'], 'an ambiguous result must not carry a chosen version');
        $this->assertCount(2, $r['candidates'], 'both contenders must be reported');
    }

    /** A date with nothing published says so explicitly, rather than returning empty. */
    public function test_an_uncovered_date_says_so_explicitly(): void
    {
        $r = Timetable_plan_service::resolveForDate($this->session(), '2026-03-15');

        $this->assertSame('none', $r['status']);
        $this->assertStringContainsString('no published timetable', $r['reason'],
            'an uncovered date must be a stated outcome, not an empty grid the '
            . 'caller has to interpret');
    }

    public function test_a_malformed_date_is_rejected_not_silently_missed(): void
    {
        foreach (['', '10-07-2026', '2026-13-01', '2026-02-30', 'today'] as $bad) {
            $r = Timetable_plan_service::resolveForDate($this->session(), $bad);
            $this->assertSame('invalid', $r['status'],
                "'{$bad}' should be rejected as a date, not quietly match nothing");
        }
    }

    // ── explainability ───────────────────────────────────────────────────

    /**
     * The house convention: one human-readable line justifying a date-based
     * decision. Policy_assignment_service built it so a payslip could explain
     * itself; the equivalent here is an attendance register explaining which
     * timetable it was taken against.
     */
    public function test_every_outcome_explains_itself_in_one_line(): void
    {
        $ok = Timetable_plan_service::resolveForDate($this->session(), '2026-07-10');
        $line = Timetable_plan_service::explain($ok, '2026-07-10');
        $this->assertStringContainsString('Regular Timetable', $line);
        $this->assertStringContainsString('2026-04-01', $line);
        $this->assertStringContainsString('2026-09-30', $line);

        $winter = Timetable_plan_service::resolveForDate($this->session(), '2026-11-15');
        $this->assertStringContainsString('open-ended',
            Timetable_plan_service::explain($winter, '2026-11-15'),
            'an open range must be described in words, not as an empty value');

        $amb = ['status' => 'ambiguous', 'version' => null, 'reason' => '',
                'candidates' => [
                    ['planName' => 'Regular', 'versionId' => 'V1'],
                    ['planName' => 'Winter',  'versionId' => 'V1'],
                ]];
        $ambLine = Timetable_plan_service::explain($amb, '2026-11-01');
        $this->assertStringContainsString('AMBIGUOUS', $ambLine);
        $this->assertStringContainsString('Regular', $ambLine);
        $this->assertStringContainsString('Winter', $ambLine,
            'an ambiguity must name both contenders so the operator can fix it');
    }

    // ── validation, following the house rules ────────────────────────────

    public function test_validation_matches_the_house_effective_date_rules(): void
    {
        $base = [
            'schoolId' => 'S', 'session' => 'X', 'planId' => 'P', 'versionId' => 'V',
            'status' => 'draft', 'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];

        $this->assertSame([], Timetable_plan_service::validateVersion($base),
            'an open-ended draft is valid — empty effectiveTo is legitimate');

        $noFrom = $base; $noFrom['effectiveFrom'] = '';
        $this->assertContains('effectiveFrom is required',
            Timetable_plan_service::validateVersion($noFrom));

        $backwards = $base; $backwards['effectiveFrom'] = '2026-10-01';
        $backwards['effectiveTo'] = '2026-04-01';
        $this->assertContains('effectiveTo is before effectiveFrom',
            Timetable_plan_service::validateVersion($backwards));

        $badStatus = $base; $badStatus['status'] = 'live';
        $errs = Timetable_plan_service::validateVersion($badStatus);
        $this->assertNotEmpty($errs, 'an unknown status must be rejected');

        // Tenant and session are structural, not optional.
        foreach (['schoolId', 'session', 'planId', 'versionId'] as $req) {
            $missing = $base; $missing[$req] = '';
            $this->assertContains("{$req} is required",
                Timetable_plan_service::validateVersion($missing),
                "{$req} must be mandatory — tenant and session scoping are not optional");
        }
    }

    // ── overlap: report, do not forbid ───────────────────────────────────

    public function test_overlaps_are_detected_and_reported(): void
    {
        $existing = [
            ['planId' => 'A', 'versionId' => 'V1', 'status' => 'published',
             'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-09-30'],
            ['planId' => 'B', 'versionId' => 'V1', 'status' => 'draft',
             'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-09-30'],
        ];

        $clashes = Timetable_plan_service::findOverlaps($existing,
            ['versionId' => 'NEW', 'effectiveFrom' => '2026-08-01', 'effectiveTo' => '2026-10-31']);
        $this->assertCount(1, $clashes,
            'only the PUBLISHED range counts as an overlap — a draft is not live');

        $after = Timetable_plan_service::findOverlaps($existing,
            ['versionId' => 'NEW', 'effectiveFrom' => '2026-10-01', 'effectiveTo' => '']);
        $this->assertSame([], $after, 'a range starting after the last one ends is not an overlap');

        $self = Timetable_plan_service::findOverlaps($existing,
            ['versionId' => 'V1', 'effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-09-30']);
        $this->assertSame([], $self,
            'a version must not overlap itself when it is being edited');
    }

    // ── phase discipline ─────────────────────────────────────────────────

    /**
     * Phase 1 is additive and reversible ONLY while this service touches
     * nothing. The moment it reads or writes Firestore, or writes into the live
     * timetables collection, that claim stops being true.
     */
    public function test_phase_one_performs_no_persistence_at_all(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_service.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        foreach (['firestoreSet', 'firestoreQuery', 'firestoreGet', 'commitBatch',
                  'sessionWhere', 'schoolWhere', '->remove('] as $io) {
            $this->assertStringNotContainsString($io, $code,
                "phase 1 must stay pure — {$io} makes it non-reversible and moves "
                . 'storage decisions ahead of the phase that owns them');
        }

        $this->assertStringNotContainsString("'timetables'", $code,
            'phase 1 must not reference the live collection; the compatibility '
            . 'projection belongs to phase 5');
    }
}
