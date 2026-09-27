<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Onboarding and Clearance complete under DIFFERENT rules, deliberately.
 *
 * Both are per-item checklists on a staff record and look interchangeable, but
 * their completion predicates differ and must not be "harmonised":
 *
 *   Onboarding  items: pending | done | skipped
 *               completes when every item is done OR skipped.
 *               Skipping is a legitimate resolution — not every school runs
 *               every induction step.
 *
 *   Clearance   items: pending | cleared | flagged
 *               completes ONLY when every department is 'cleared'.
 *               'flagged' is an unresolved issue (asset not returned, dues
 *               outstanding); treating it as resolved would let someone exit
 *               with an open liability.
 *
 * Both fail closed on 'pending'.
 *
 * Audited 2026-08-21 and found CORRECT. This is a regression guard: the obvious
 * refactor — one shared "all items resolved" helper — would silently let a
 * flagged clearance complete.
 */
final class ChecklistCompletionAsymmetryTest extends TestCase
{
    /** Onboarding: done OR skipped resolves. */
    private function onboardingComplete(array $tasks): bool
    {
        foreach ($tasks as $t) {
            $st = is_array($t) ? (string) ($t['status'] ?? '') : '';
            if ($st !== 'done' && $st !== 'skipped') return false;
        }
        return true;
    }

    /** Clearance: only 'cleared' resolves. */
    private function clearanceComplete(array $depts): bool
    {
        foreach ($depts as $d) {
            if (!is_array($d) || (string) ($d['status'] ?? '') !== 'cleared') return false;
        }
        return true;
    }

    private function t(string ...$statuses): array
    {
        return array_map(static fn($s) => ['status' => $s], $statuses);
    }

    // ── Onboarding ───────────────────────────────────────────────────────

    public function test_onboarding_completes_when_all_done(): void
    {
        $this->assertTrue($this->onboardingComplete($this->t('done', 'done', 'done')));
    }

    public function test_onboarding_completes_with_skipped_items(): void
    {
        $this->assertTrue($this->onboardingComplete($this->t('done', 'skipped', 'done')),
            'skipping an induction step is a legitimate resolution');
    }

    public function test_onboarding_completes_when_everything_is_skipped(): void
    {
        $this->assertTrue($this->onboardingComplete($this->t('skipped', 'skipped')));
    }

    public function test_a_single_pending_task_blocks_onboarding(): void
    {
        $this->assertFalse($this->onboardingComplete($this->t('done', 'pending', 'skipped')));
    }

    // ── Clearance — the strict one ───────────────────────────────────────

    public function test_clearance_completes_only_when_all_cleared(): void
    {
        $this->assertTrue($this->clearanceComplete($this->t('cleared', 'cleared')));
    }

    /**
     * THE ASYMMETRY THAT MATTERS. A flagged department is an open liability —
     * unreturned asset, outstanding dues. It must never count as resolved.
     */
    public function test_a_flagged_department_blocks_clearance(): void
    {
        $this->assertFalse($this->clearanceComplete($this->t('cleared', 'flagged')),
            'a flagged clearance would let someone exit with an open liability');
    }

    public function test_a_pending_department_blocks_clearance(): void
    {
        $this->assertFalse($this->clearanceComplete($this->t('cleared', 'pending')));
    }

    /** 'skipped' is not a clearance status at all and must not resolve it. */
    public function test_onboarding_vocabulary_does_not_leak_into_clearance(): void
    {
        $this->assertFalse($this->clearanceComplete($this->t('cleared', 'skipped')),
            "'skipped' resolves onboarding but must NOT resolve clearance");
        $this->assertFalse($this->clearanceComplete($this->t('cleared', 'done')));
    }

    /** The two predicates must genuinely differ on the same input. */
    public function test_the_two_predicates_are_not_interchangeable(): void
    {
        $mixed = $this->t('skipped', 'skipped');

        $this->assertTrue($this->onboardingComplete($mixed));
        $this->assertFalse($this->clearanceComplete($mixed),
            'a shared "all resolved" helper would break clearance');
    }

    // ── Malformed and empty input ────────────────────────────────────────

    public function test_malformed_entries_block_both(): void
    {
        $this->assertFalse($this->onboardingComplete(['not-an-array']));
        $this->assertFalse($this->clearanceComplete(['not-an-array']));
    }

    public function test_missing_status_blocks_both(): void
    {
        $this->assertFalse($this->onboardingComplete([['note' => 'x']]));
        $this->assertFalse($this->clearanceComplete([['note' => 'x']]));
    }

    /** Status matching is exact — no case-insensitive back door. */
    public function test_status_matching_is_exact(): void
    {
        $this->assertFalse($this->clearanceComplete($this->t('Cleared')));
        $this->assertFalse($this->onboardingComplete($this->t('DONE')));
    }

    // ── Source pins ──────────────────────────────────────────────────────

    public function test_shipped_predicates_still_differ(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString("if (\$st !== 'done' && \$st !== 'skipped') {", $src);
        $this->assertStringContainsString("(string) (\$d['status'] ?? '') !== 'cleared'", $src);
    }

    public function test_status_vocabularies_stay_separate(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString("ONBOARDING_TASK_STATUSES = ['pending', 'done', 'skipped']", $src);
        $this->assertStringContainsString("CLEARANCE_DEPT_STATUSES = ['pending', 'cleared', 'flagged']", $src);
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
