<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Section_seat_guard.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  CONCURRENCY ON LIFECYCLE PLACEMENT — EXECUTED, NOT READ
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Two admins moving students into the same section at the same time is the
 * race that overbooks a class. The guard that prevents it — a compare-and-set
 * on the destination section document — had no test that ran it.
 *
 * ── WHY THIS GUARD RATHER THAN A COUNTER ─────────────────────────────────
 * The obvious design is an occupancy counter incremented per placement. That
 * is precisely what drifts: two writers read the same count and both write
 * count+1. ZenX instead stores an OPAQUE MARKER — the class constant says
 * "Never a count" — and derives occupancy from the student rows themselves.
 * A derived count cannot drift, so the only thing needing protection is the
 * "has anything changed since I looked?" question, which is what the
 * updateTime precondition answers.
 *
 * ── THE THREE OUTCOMES, AND WHY THEY DIFFER ──────────────────────────────
 *   PLACED    committed atomically
 *   CONFLICT  another writer won; Firestore rolled the batch back, so
 *             NOTHING was written and a retry is safe
 *   FAILURE   the outcome is UNKNOWN; the student write may already have
 *             landed, so re-committing is how duplicates are made
 *
 * Collapsing CONFLICT and FAILURE into "it didn't work" is the mistake that
 * turns a safe retry into a double placement. These tests assert they stay
 * distinct.
 */
final class LifecycleConcurrencyGuardTest extends TestCase
{
    /** Records what reached Firestore, so "nothing was written" is checkable. */
    private function firebaseDouble(array $result, array &$seen)
    {
        return new class($result, $seen) {
            public array $result;
            /** @var array<int,array> */
            public $seen;
            public int $calls = 0;
            public function __construct(array $result, array &$seen) {
                $this->result = $result;
                $this->seen   = &$seen;
            }
            public function firestoreCommitBatchStatus(array $ops): array {
                $this->calls++;
                $this->seen[] = $ops;
                return $this->result;
            }
        };
    }

    private function fsDouble(?array $sectionDoc)
    {
        return new class($sectionDoc) {
            private ?array $doc;
            public function __construct(?array $doc) { $this->doc = $doc; }
            public function sectionDocId(string $c, string $s, string $sess): string {
                return "SCH_T_{$sess}_{$c}_{$s}";
            }
            public function get(string $collection, string $docId) {
                return $this->doc;
            }
        };
    }

    /**
     * The capacity decision's section version, passed to every commitPlacement()
     * below.
     *
     * 2026-09-15 — commitPlacement() gained a MANDATORY $decisionVersion (Phase
     * 20.3: a CAS-protected count must be read AFTER the version). These tests
     * predate it and called the method with four arguments, so every one of them
     * stopped at the new "no decision version" refusal and never reached the CAS
     * path it was written to pin. Seven of them had been failing ever since —
     * reporting FAILURE where they expected CONFLICT — while the guard itself was
     * correct and, in fact, STRICTER than the tests assumed.
     *
     * Verified at runtime before touching them: two concurrent admissions into a
     * capacity-1 section on the Firestore emulator produced exactly one placement
     * and one refusal, with a single student document written.
     *
     * Matches the fixture's __updateTime so the precondition is self-consistent.
     */
    private const DECISION_VERSION = 'T1';

    private function guard(?array $sectionDoc, array $commitResult, array &$seen): Section_seat_guard
    {
        $g = new Section_seat_guard();
        $g->init($this->fsDouble($sectionDoc), $this->firebaseDouble($commitResult, $seen), 'SCH_T', 'admin_a');
        return $g;
    }

    private function writes(): array
    {
        return [['collection' => 'students', 'docId' => 'SCH_T_STU0001', 'data' => ['section' => 'Section B']]];
    }

    // ── THE FAIL-CLOSED CASE ─────────────────────────────────────────────

    public function test_a_section_without_an_update_time_refuses_to_place(): void
    {
        // No updateTime means no precondition is possible, so an "atomic"
        // commit would merely LOOK atomic. Refusing is the only safe reading.
        $seen = [];
        $g = $this->guard(['maxStrength' => 40], ['status' => 'SUCCESS'], $seen);
        $res = $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        $this->assertSame(Section_seat_guard::FAILURE, $res['status']);
        $this->assertSame([], $seen, 'a placement was committed with NO concurrency guard');
    }

    public function test_a_missing_section_is_never_placed_into(): void
    {
        $seen = [];
        $g = $this->guard(null, ['status' => 'SUCCESS'], $seen);
        $res = $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);
        $this->assertSame(Section_seat_guard::FAILURE, $res['status']);
        $this->assertSame([], $seen, 'wrote into a section that does not exist');
    }

    public function test_an_empty_write_set_commits_nothing(): void
    {
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T1'], ['status' => 'SUCCESS'], $seen);
        $this->assertSame(Section_seat_guard::FAILURE,
            $g->commitPlacement('Class 9th', 'Section B', '2026-27', [], self::DECISION_VERSION)['status']);
        $this->assertSame([], $seen);
    }

    // ── THE GUARD ITSELF ─────────────────────────────────────────────────

    public function test_the_commit_carries_the_update_time_as_a_precondition(): void
    {
        // Positive control for every refusal above: with a guard available the
        // placement proceeds, and the precondition really is attached.
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T-OBSERVED'], ['status' => 'SUCCESS'], $seen);
        $res = $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        $this->assertSame(Section_seat_guard::PLACED, $res['status']);
        $this->assertCount(1, $seen, 'expected exactly one batch commit');

        $marker = null;
        foreach ($seen[0] as $op) {
            if (($op['collection'] ?? '') === 'sections') { $marker = $op; break; }
        }
        $this->assertNotNull($marker, 'no section marker op in the batch — nothing guards the race');
        // 2026-09-15 — this expected the section's own __updateTime, read by the
        // guard at commit time. That is the WEAKER, superseded design: a
        // precondition taken from a fresh self-read only asserts "nothing changed
        // since I just looked", which cannot catch a change between the CAPACITY
        // DECISION and the commit — the exact race that once gave one section an
        // occupancy of five on a capacity of two.
        //
        // The guard now preconditions on the version the CALLER decided against,
        // so a section mutated after that decision fails the CAS. Asserting the
        // self-read here would pin the weaker contract back in place.
        $this->assertSame(['updateTime' => self::DECISION_VERSION], $marker['precondition'] ?? null,
            'the marker is not preconditioned on the version the capacity decision was '
            . 'made against, so a section changed since that decision would still commit');
    }

    public function test_the_marker_is_a_timestamp_and_never_a_count(): void
    {
        // A count would drift under exactly the race this guards. The class
        // constant says "Never a count"; this asserts the value agrees.
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T1'], ['status' => 'SUCCESS'], $seen);
        $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        foreach ($seen[0] as $op) {
            if (($op['collection'] ?? '') !== 'sections') continue;
            $value = $op['data'][Section_seat_guard::MARKER_FIELD] ?? null;
            $this->assertIsString($value);

            // 2026-09-15 — the marker is now "<ISO-8601>.<microseconds>#<random>".
            // strtotime() on the WHOLE string returns false because of the entropy
            // suffix, which is why this assertion had been failing. The entropy is
            // deliberate: two placements landing in the same second would otherwise
            // write an identical marker, and a competing precondition would still
            // pass. So parse the timestamp PREFIX and assert the entropy separately
            // rather than weakening the check to a bare isString().
            $prefix = explode('#', explode('.', $value)[0])[0];
            $this->assertNotFalse(strtotime($prefix),
                'the seat marker does not start with a parseable timestamp');
            $this->assertIsNotNumeric($value, 'the seat marker became a COUNT — it will drift');
            $this->assertStringContainsString('#', $value,
                'the marker carries no entropy, so two placements in the same second '
                . 'write an identical marker and a competing precondition still passes');
        }
    }

    public function test_the_student_write_travels_in_the_same_batch_as_the_marker(): void
    {
        // If the student write and the guard were separate commits, the guard
        // would protect nothing: the student could move while the marker failed.
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T1'], ['status' => 'SUCCESS'], $seen);
        $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        $collections = array_column($seen[0], 'collection');
        $this->assertContains('students', $collections);
        $this->assertContains('sections', $collections);
        $this->assertCount(1, $seen, 'the placement was split across more than one commit');
    }

    // ── LOSING THE RACE ──────────────────────────────────────────────────

    public function test_losing_the_race_reports_conflict_and_writes_nothing(): void
    {
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T1'], ['status' => 'PRECONDITION_CONFLICT'], $seen);
        $res = $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        $this->assertSame(Section_seat_guard::CONFLICT, $res['status']);
        $this->assertSame(1, $res['attempts'], 'a lost race was retried — the batch already rolled back');
    }

    public function test_an_unknown_outcome_is_reported_as_failure_not_conflict(): void
    {
        // The distinction that matters: CONFLICT means nothing was written and
        // a retry is safe. FAILURE means the student write may ALREADY have
        // landed, so retrying is how a duplicate placement is made.
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T1'], ['status' => 'FAILURE', 'code' => 13], $seen);
        $res = $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        $this->assertSame(Section_seat_guard::FAILURE, $res['status']);
        $this->assertNotSame(Section_seat_guard::CONFLICT, $res['status'],
            'an unknown outcome is being reported as a safe-to-retry conflict');
        $this->assertStringContainsString('unknown', strtolower($res['message']),
            'the operator is not told the outcome is unknown before they retry');
    }

    public function test_an_unknown_outcome_is_never_retried(): void
    {
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T1'], ['status' => 'FAILURE', 'code' => 13], $seen);
        $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        $this->assertCount(1, $seen,
            're-committing an unknown outcome is exactly how duplicate placements are created');
        $this->assertSame(1, Section_seat_guard::MAX_ATTEMPTS,
            'blind retry was enabled on a commit whose outcome may be unknown');
    }

    public function test_a_thrown_transport_error_is_an_unknown_outcome_not_a_conflict(): void
    {
        $seen = [];
        $g = new Section_seat_guard();
        $fb = new class {
            public int $calls = 0;
            public function firestoreCommitBatchStatus(array $ops): array {
                $this->calls++;
                throw new \RuntimeException('transport reset');
            }
        };
        $g->init($this->fsDouble(['__updateTime' => 'T1']), $fb, 'SCH_T', 'admin_a');
        $res = $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes(), self::DECISION_VERSION);

        $this->assertSame(Section_seat_guard::FAILURE, $res['status'],
            'a transport error was treated as a clean conflict — the write may have landed');
        $this->assertSame(1, $fb->calls, 'a thrown commit was retried');
    }

    /**
     * The refusal every other test used to hit by accident.
     *
     * Without a decision version there is nothing proving the capacity decision is
     * still valid at commit time, so the guard must refuse rather than commit a
     * write that merely looks atomic. Now that the other tests supply a version,
     * this behaviour needs a test of its own or it would go uncovered.
     */
    public function test_a_placement_without_a_decision_version_refuses(): void
    {
        $seen = [];
        $g = $this->guard(['__updateTime' => 'T1'], ['status' => 'SUCCESS'], $seen);
        $res = $g->commitPlacement('Class 9th', 'Section B', '2026-27', $this->writes());

        $this->assertSame(Section_seat_guard::FAILURE, $res['status'],
            'a placement with no decision version was allowed to commit — the capacity '
            . 'decision could no longer be proven valid at commit time');
        $this->assertSame([], $seen, 'the refusal still wrote to Firestore');
    }
}
