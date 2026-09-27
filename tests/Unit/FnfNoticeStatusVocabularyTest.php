<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UAT-D1 — F&F could never be initiated for ANY employee, ever.
 *
 * Found running the real Staff Lifecycle UAT against the live tenant
 * (SCH_B56BB9A401), not by static reading. `staffNoticePeriods` carries two
 * vocabularies on the same document:
 *
 *   status    approved | waived | reduced | rejected   — what
 *             Fnf_state_machine::NOTICE_SETTLEABLE actually checks
 *   decision  approve  | waive  | reduce  | reject      — the verb an HR
 *             operator pressed, written by Staff::decide_notice_period()
 *
 * `Compensation::_fnf_context()` read `decision` FIRST via
 * `$notice['decision'] ?? $notice['status']`. Every approved notice therefore
 * arrived at the guard as the string "approve" — not in NOTICE_SETTLEABLE —
 * and every real F&F initiation failed with "notice period is not in a
 * settleable state (currently: approve)", even though the notice was
 * genuinely approved and Firestore held `status: "approved"` correctly.
 *
 * The bug was in the READER, not the writer: `decide_notice_period()` writes
 * both fields correctly, and the guard's vocabulary was always documented as
 * NOTICE_SETTLEABLE — nothing that speaks the verb vocabulary was ever
 * supposed to reach it.
 */
final class FnfNoticeStatusVocabularyTest extends TestCase
{
    private static string $body = '';

    public static function setUpBeforeClass(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Compensation.php');
        $i = strpos($src, "\$notice = \$this->fs->get('staffNoticePeriods'");
        self::assertNotFalseOrFail($i);
        $j = strpos($src, "// Fall back to the separation record's last working day", $i);
        self::$body = substr($src, (int) $i, ((int) $j) - ((int) $i));
    }

    private static function assertNotFalseOrFail($v): void
    {
        if ($v === false) {
            throw new RuntimeException('locator anchor not found — this test is stale');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$body = '';
    }

    /** THE CENTRAL PROPERTY: `status` is read before `decision`, not after. */
    public function test_status_is_consulted_before_decision(): void
    {
        $statusPos   = strpos(self::$body, "\$notice['status']");
        $decisionPos = strpos(self::$body, "\$notice['decision']");

        $this->assertNotFalse($statusPos, 'the document\'s settled status must be read');
        $this->assertNotFalse($decisionPos,
            'the verb should still be read as a fallback for pre-existing documents');
        $this->assertLessThan($decisionPos, $statusPos,
            'status must be consulted BEFORE decision, or an approved notice arrives '
            . 'at the guard as the verb "approve" — which NOTICE_SETTLEABLE does not '
            . 'recognise — and F&F can never be initiated');
    }

    /**
     * The fix must never regress into `decision ?? status` — the exact
     * shape of the original defect, one string away from the correct order.
     */
    public function test_the_defective_precedence_is_gone(): void
    {
        $this->assertStringNotContainsString(
            "\$notice['decision'] ?? \$notice['status']", self::$body,
            'this is the exact broken precedence this test exists to catch');
    }

    /**
     * Pre-existing documents that predate the `status` field are still
     * handled — mapped from the verb, not left blank.
     */
    public function test_legacy_documents_with_only_a_verb_still_resolve(): void
    {
        $this->assertStringContainsString("'approve' => 'approved'", self::$body);
        $this->assertStringContainsString("'waive' => 'waived'", self::$body);
        $this->assertStringContainsString("'reduce'  => 'reduced'", self::$body);
        $this->assertStringContainsString("'reject' => 'rejected'", self::$body);
    }

    /**
     * Executable proof, independent of the controller: replaying the exact
     * mapping this fix performs against both a modern document (status
     * present) and a legacy one (verb only) lands on the vocabulary
     * `Fnf_state_machine::NOTICE_SETTLEABLE` actually declares.
     */
    public function test_the_mapping_lands_in_the_settleable_vocabulary(): void
    {
        require_once __DIR__ . '/../../application/libraries/Fnf_state_machine.php';

        $resolve = static function (array $notice): string {
            $status = trim((string) ($notice['status'] ?? ''));
            if ($status === '') {
                $verbToStatus = ['approve' => 'approved', 'waive' => 'waived',
                                 'reduce' => 'reduced', 'reject' => 'rejected'];
                $verb = trim((string) ($notice['decision'] ?? ''));
                $status = $verbToStatus[$verb] ?? $verb;
            }
            return $status;
        };

        // Modern document: both fields present, as decide_notice_period() writes.
        $modern = $resolve(['status' => 'approved', 'decision' => 'approve']);
        $this->assertSame('approved', $modern);
        $this->assertContains($modern, Fnf_state_machine::NOTICE_SETTLEABLE,
            'a real approved notice must resolve into the settleable vocabulary');

        // Legacy document: verb only.
        $legacy = $resolve(['decision' => 'waive']);
        $this->assertSame('waived', $legacy);
        $this->assertContains($legacy, Fnf_state_machine::NOTICE_SETTLEABLE);

        // A rejected notice must NOT be settleable — the guard's refusal
        // must still hold for the case it exists to catch.
        $rejected = $resolve(['status' => 'rejected']);
        $this->assertNotContains($rejected, Fnf_state_machine::NOTICE_SETTLEABLE);
    }
}
