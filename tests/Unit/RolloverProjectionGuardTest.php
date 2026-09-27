<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Rollover_projection_guard.php';

/**
 * WHETHER THE SESSION ROLLOVER MAY WRITE OVER WHAT IT FINDS.
 *
 * The rollover carries a school's timetable into the new session with a full
 * document write. Before this guard existed the only thing that stopped it was
 * the tool's own revision marker, and two kinds of document do not carry one:
 *
 *   • documents the PROJECTION authored — the materialised form of a published
 *     canonical timetable. Running the rollover after a projection replaced this
 *     year's published timetable with last year's copy on every teacher and
 *     parent screen.
 *   • documents that could not be READ. The read is what decides whether the
 *     rollover already ran, and its failure was swallowed — so a network blip
 *     produced the same overwrite.
 *
 * The decision now lives in a pure function precisely so it can be executed
 * here, rather than proven by reading a CLI script with a live Firestore client
 * in the middle of it.
 */
final class RolloverProjectionGuardTest extends TestCase
{
    private const REV = 1;

    private function decide($existing, bool $readFailed = false): string
    {
        return Rollover_projection_guard::decide($existing, self::REV, $readFailed);
    }

    /** A document the projection wrote. */
    private function projected(array $over = []): array
    {
        return array_merge([
            'schoolId' => 'SCH_A', 'session' => '2026-27',
            'sectionKey' => 'Class 9th/Section A', 'day' => 'Monday',
            'periods' => [['periodNumber' => 1, 'subject' => 'Mathematics']],
            'projectedFromPlan' => 'PLAN1', 'projectedFromVersion' => 'V1',
            'projectedAt' => '2026-09-01T00:00:00+00:00', 'version' => 3,
        ], $over);
    }

    /** A document a previous rollover carried. */
    private function carried(int $rev = self::REV): array
    {
        return [
            'schoolId' => 'SCH_A', 'session' => '2026-27',
            'sectionKey' => 'Class 9th/Section A', 'day' => 'Monday',
            'periods' => [], 'carriedFromSession' => '2025-26',
            'phase3RolloverRev' => $rev, 'version' => 1,
        ];
    }

    // ── the ordinary case ────────────────────────────────────────────────

    public function test_an_absent_destination_is_written(): void
    {
        $this->assertSame(Rollover_projection_guard::WRITE, $this->decide(null));
    }

    public function test_a_destination_from_an_older_revision_is_written_again(): void
    {
        $this->assertSame(Rollover_projection_guard::WRITE,
            $this->decide($this->carried(0)),
            'bumping PHASE3_ROLLOVER_REV is how an operator revisits a rollover');
    }

    public function test_a_destination_already_rolled_at_this_revision_is_skipped(): void
    {
        $this->assertSame(Rollover_projection_guard::SKIP_ALREADY_ROLLED,
            $this->decide($this->carried()));
    }

    // ── THE FIX: canonical-derived state is not this tool's to overwrite ──

    public function test_a_projected_document_is_never_overwritten(): void
    {
        $this->assertSame(Rollover_projection_guard::SKIP_PROJECTED,
            $this->decide($this->projected()),
            "a published timetable must survive a rollover run after it");
    }

    /**
     * A document that was carried once and has since been projected over.
     *
     * This is a REAL state, not a contrived one: the projection writes with
     * merge, so a carried document that a published version later covers keeps
     * its `phase3RolloverRev` marker AND gains projection provenance. Both
     * fields are then present and at the current revision.
     *
     * Which reason wins decides what the operator is told. Answering "already
     * rolled at this revision" reads as "this one was mine and is done";
     * the truth is "this is canonical state and not this tool's to touch".
     * Both refuse the write, so the difference is honesty rather than safety —
     * which is exactly why it needs asserting: nothing else would catch it.
     */
    public function test_a_carried_then_projected_document_reports_the_canonical_reason(): void
    {
        $doc = $this->projected(['phase3RolloverRev' => self::REV,
                                 'carriedFromSession' => '2025-26']);

        $this->assertSame(Rollover_projection_guard::SKIP_PROJECTED,
            $this->decide($doc),
            'provenance outranks the rollover marker the document inherited');
    }

    /** An empty provenance field is not provenance. */
    public function test_a_blank_projection_marker_does_not_protect(): void
    {
        $doc = $this->carried(0);
        $doc['projectedFromVersion'] = '   ';

        $this->assertSame(Rollover_projection_guard::WRITE, $this->decide($doc),
            'only a real version id means the projection authored this');
    }

    // ── THE FIX: not knowing is not permission ──────────────────────────

    public function test_an_unreadable_destination_is_never_written_over(): void
    {
        $this->assertSame(Rollover_projection_guard::SKIP_UNREADABLE,
            $this->decide(null, true),
            'the read is what decides whether the rollover already ran; its '
            . 'failure must not be read as "not yet rolled"');
    }

    /** Unreadable outranks everything — the fields below it are not available. */
    public function test_unreadable_is_decided_before_anything_else(): void
    {
        $this->assertSame(Rollover_projection_guard::SKIP_UNREADABLE,
            $this->decide($this->projected(), true));
        $this->assertSame(Rollover_projection_guard::SKIP_UNREADABLE,
            $this->decide($this->carried(), true));
    }

    // ── what the operator is told ───────────────────────────────────────

    public function test_each_refusal_explains_itself_to_the_operator(): void
    {
        $id = 'SCH_A_2026-27_Class 9th_Section A_Monday';

        $this->assertMatchesRegularExpression('~published version~i',
            Rollover_projection_guard::explain(
                Rollover_projection_guard::SKIP_PROJECTED, $id));
        $this->assertMatchesRegularExpression('~could not be read~i',
            Rollover_projection_guard::explain(
                Rollover_projection_guard::SKIP_UNREADABLE, $id));
        $this->assertMatchesRegularExpression('~already rolled~i',
            Rollover_projection_guard::explain(
                Rollover_projection_guard::SKIP_ALREADY_ROLLED, $id));

        foreach ([Rollover_projection_guard::SKIP_PROJECTED,
                  Rollover_projection_guard::SKIP_UNREADABLE,
                  Rollover_projection_guard::SKIP_ALREADY_ROLLED] as $d) {
            $this->assertStringContainsString($id,
                Rollover_projection_guard::explain($d, $id),
                'the line names the document, or it cannot be acted on');
        }
    }

    // ── the tool actually consults it ───────────────────────────────────

    /**
     * A pure guard nothing calls is decoration. The timetable carry must reach
     * its decision through this function, and must not keep a second copy of
     * the old rule beside it.
     */
    public function test_the_rollover_tool_uses_the_guard_for_the_timetable_carry(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../scripts/rollover_academic_session.php');
        $code = (string) preg_replace('~^\s*//.*$~m', '', $src);

        $this->assertStringContainsString(
            "require_once APPPATH . 'libraries/Rollover_projection_guard.php';", $code);
        $this->assertMatchesRegularExpression(
            '~Rollover_projection_guard::decide\(\s*\$existing,\s*PHASE3_ROLLOVER_REV,\s*\$readFailed\s*\)~s',
            $code, 'the timetable carry asks the guard');
        $this->assertMatchesRegularExpression(
            '~catch \(\\\\Throwable \$e\) \{ \$existing = null; \$readFailed = true; \}~',
            $code, 'and tells it when the destination could not be read');
    }
}
