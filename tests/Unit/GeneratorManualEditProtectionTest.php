<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D23 — the generator must not overwrite hand-built days it cannot see.
 *
 * autoGenerate() offers the operator a contract: manually edited days are
 * PRESERVED unless "force overwrite" is ticked. The whole contract rests on one
 * preload:
 *
 *     $existingDocs = firestoreQuery(TT, [schoolId, session], null, 'ASC', 1000);
 *     ...
 *     $existing       = $existingByDocId[$docId] ?? null;
 *     $manuallyEdited = is_array($existing) && !empty($existing['manuallyEdited']);
 *     if ($manuallyEdited && !$forceOverwrite) { ...preserve... }
 *
 * Two independent ways that map came back incomplete, and BOTH made
 * $manuallyEdited false for days that really were hand-edited:
 *
 *   1. firestoreQuery() returns [] for a FAILED read exactly as it does for "no
 *      existing timetable" (ACAD-D12), so one transient failure emptied it;
 *   2. the 1000-doc cap truncated it silently — about 166 class-sections at six
 *      working days — so the lexicographic tail lost protection entirely.
 *
 * The consequence is the worst available. The day is then rewritten with
 * merge = FALSE, a whole-document overwrite, destroying work the operator did
 * not authorise overwriting — and no rollback exists anywhere in the codebase.
 *
 * WHY THIS FIX IS NOT A POLICY CHOICE. One could argue for treating unknown days
 * as "manually edited" and skipping them instead. But the generator has already
 * been TOLD what to do — preserve unless forced — and an incomplete map means it
 * cannot honour that instruction either way. Refusing the run is the only
 * behaviour that keeps the promise the operator was given, and it matches the
 * rule ACAD-D14 already established for the assignment delete-set.
 *
 * ── ACAD-D24, deliberately NOT fixed here ─────────────────────────────────
 *
 * The write itself commits in chunks of 450, sequentially, with merge = false.
 * If chunk 3 of 7 fails, chunks 1–2 are already committed and the run stops, so
 * the school is left with SOME class-sections regenerated and others not — an
 * inconsistent timetable with no way back. Making that atomic (or genuinely
 * reversible) is an architecture decision, not a defect fix, and it is raised in
 * the decision report rather than assumed here.
 */
final class GeneratorManualEditProtectionTest extends TestCase
{
    private function svc(): string
    {
        $p = __DIR__ . '/../../application/libraries/Timetable_service.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /** One method of the pipeline, by name. */
    private function method(string $name): string
    {
        $s = $this->svc();
        $i = strpos($s, "function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        // Take the NEAREST boundary of any visibility. Checking private first and
        // only falling back when absent overshoots a nearer public method and
        // swallows everything in between — which silently pulled unrelated methods
        // into the "pipeline" and produced a false failure.
        $bounds = [];
        foreach (['private', 'protected', 'public'] as $vis) {
            $p = strpos($s, "\n    {$vis} function ", $i + 10);
            if ($p !== false) $bounds[] = $p;
        }
        $this->assertNotEmpty($bounds, "could not bound {$name}()");
        $e = min($bounds);
        return substr($s, $i, $e - $i);
    }

    /**
     * The LIVE GENERATION PIPELINE.
     *
     * Generation used to be one method; it is now four, each with one
     * responsibility. The guards did not leave the pipeline — they moved within
     * it: ACAD-D25 into _loadGenerationInputs(), the ACAD-D23 preserve branch
     * into _composeGeneratedDays(), chunking into _commitLiveBatch(). Scanning
     * only autoGenerate() would report them missing when they are reachable on
     * every run, so these tests scan the pipeline as a whole and separate tests
     * pin WHICH method each guard lives in.
     */
    private function generator(): string
    {
        return $this->method('autoGenerate')
             . $this->method('_loadGenerationInputs')
             . $this->method('_composeGeneratedDays')
             . $this->method('_liveBatchOpsFrom')
             . $this->method('_commitLiveBatch');
    }

    /** The contract this protects must still exist. */
    public function test_the_preserve_manual_edits_contract_is_still_there(): void
    {
        $fn = $this->generator();

        $this->assertMatchesRegularExpression(
            '~\$manuallyEdited = is_array\(\$existing\) && !empty\(\$existing\[\x27manuallyEdited\x27\]\)~',
            $fn, 'the manual-edit detection is gone');

        $this->assertMatchesRegularExpression(
            '~if \(\$manuallyEdited && !\$forceOverwrite\)~', $fn,
            'the preserve branch is gone — without it there is nothing to protect');
    }

    /** The preload must be able to fail. */
    public function test_the_preload_is_fail_loud(): void
    {
        $fn = $this->generator();

        $this->assertStringContainsString('firestoreQueryOrFail(self::COLLECTION_TT', $fn,
            'on firestoreQuery() a failed preload is indistinguishable from "this '
            . 'school has no timetable yet", and every day loses its protection');

        $this->assertDoesNotMatchRegularExpression(
            '~firestoreQuery\(self::COLLECTION_TT~', $fn,
            'the silent read is back in the generator');
    }

    /** Saturation is also "cannot prove", and must refuse. */
    public function test_a_saturated_preload_refuses_the_run(): void
    {
        $s  = $this->svc();
        $fn = $this->generator();

        $this->assertMatchesRegularExpression(
            '~const GENERATE_PRELOAD_LIMIT\s*=\s*\d+~', $s,
            'the cap must be named so the saturation check cannot drift from it');

        $this->assertStringContainsString('self::GENERATE_PRELOAD_LIMIT + 1', $fn,
            'reading exactly the cap cannot tell "exactly full" from "truncated"');

        $this->assertMatchesRegularExpression(
            '~count\(\$existingDocs\) > self::GENERATE_PRELOAD_LIMIT~', $fn,
            'no saturation check — a truncated preload reads as "nothing was hand-edited"');
    }

    /** Both failure paths must refuse, and refuse BEFORE any write. */
    public function test_both_failure_paths_refuse_before_writing(): void
    {
        $fn = $this->generator();

        // Target the two PRELOAD refusals by their own wording. autoGenerate
        // legitimately contains other refusals (missing settings, no subject
        // assignments, and the chunk-write failure), so a bare count of
        // Service_exception would be measuring someone else's guards.
        $this->assertSame(1, preg_match_all(
            '~throw new Service_exception\(\s*\n\s*\x27This school has more timetable data~', $fn),
            'the saturated-preload message must be inside a throw — keeping the '
            . 'wording while dropping the refusal is exactly the silent overwrite');
        $this->assertSame(1, preg_match_all(
            '~throw new Service_exception\(\s*\n\s*\x27The existing timetable could not be read~', $fn),
            'the failed-preload message must be inside a throw');

        // Nothing may persist between the preload and its refusals.
        $preload = strpos($fn, 'firestoreQueryOrFail(self::COLLECTION_TT');
        $this->assertNotFalse($preload);

        $before = substr($fn, 0, $preload);
        $this->assertSame(0, preg_match_all('~commitBatch|firestoreSet~i', $before),
            'a write happens before the preload, so a refusal would leave the school '
            . 'partly regenerated');
    }

    /** A Service_exception must not be swallowed by the generic handler. */
    public function test_the_refusal_is_not_recaught_as_an_unknown_failure(): void
    {
        $fn = $this->generator();

        $this->assertMatchesRegularExpression(
            '~catch \(Service_exception \$e\) \{\s*throw \$e;~', $fn,
            'the broad \\Throwable catch would otherwise swallow the refusal and '
            . 'convert it back into a generic message — or worse, let the run continue');
    }

    /** The operator must learn what was at stake, not just that it failed. */
    public function test_the_refusal_explains_what_it_protected(): void
    {
        $fn = $this->generator();

        $this->assertMatchesRegularExpression('~overwritten manually edited days~i', $fn,
            'the saturated-preload message must say what running anyway would have cost');
        $this->assertMatchesRegularExpression('~edited by hand~i', $fn,
            'the failed-preload message must say the same');
    }

    /**
     * ACAD-D25 — the most destructive path found in this audit.
     *
     * getAllForSession() routes to sessionWhere() -> firestoreQuery(), which
     * returns [] for a FAILED read exactly as it does for "no assignments".
     * Nothing downstream objected:
     *
     *   - the grid is pre-initialised for EVERY section and day
     *     ($grid[$sk][$day] = array_fill(0, $periodsPerDay, null));
     *   - an unfilled cell is written as an empty period, not skipped;
     *   - every day document is committed with merge = false.
     *
     * So one failed assignments read blanked the ENTIRE school's timetable in a
     * single run and reported success. Only manuallyEdited days survived, which
     * in an auto-generated school is none of them, and there is no rollback.
     */
    public function test_it_refuses_to_generate_when_no_assignment_resolves(): void
    {
        $fn = $this->generator();

        $this->assertMatchesRegularExpression(
            '~\$totalAssignments\s*=\s*0;~', $fn,
            'the assignment tally is gone');

        // The tally must actually COUNT. A tally stuck at zero makes the guard
        // fire every time — fail-safe, but generation would never work again,
        // and asserting only that the guard exists would not notice.
        $this->assertMatchesRegularExpression(
            '~\$totalAssignments \+= count\(\$\w+\);~', $fn,
            'the tally must sum the resolved assignments per section');

        $this->assertMatchesRegularExpression(
            '~if \(\$totalAssignments === 0\) \{~', $fn,
            'nothing stops a run with zero assignments, and the pre-initialised grid '
            . 'then writes an empty period for every cell of every day');

        $i = strpos($fn, 'if ($totalAssignments === 0) {');
        $this->assertNotFalse($i);
        $this->assertStringContainsString('throw new Service_exception',
            substr($fn, $i, 900),
            'a run with nothing to place must refuse, not blank the timetable');
    }

    /** The guard is useless unless it precedes the grid build and the write. */
    public function test_the_empty_assignment_guard_precedes_the_grid_and_the_write(): void
    {
        $fn = $this->generator();

        $guard = strpos($fn, 'if ($totalAssignments === 0) {');
        $grid  = strpos($fn, '$grid[$sk][$day] = array_fill(');
        // stripos, not strpos: the call is firestoreCommitBatch — a case-
        // sensitive search for 'commitBatch' silently finds nothing and the
        // ordering assertion below would never run.
        $write = stripos($fn, 'commitBatch');

        // Ordering is now about CALL order, not text position: the guards live in
        // different methods, so comparing offsets in a concatenation would prove
        // nothing. What matters is that autoGenerate loads (and therefore guards)
        // before it places or commits.
        $auto  = $this->method('autoGenerate');
        $load  = strpos($auto, '$this->_loadGenerationInputs(');
        $place = strpos($auto, '$this->_placeGrid(');
        $write = strpos($auto, '$this->_commitLiveBatch(');

        foreach (['load' => $load, 'place' => $place, 'write' => $write] as $k => $v) {
            $this->assertNotFalse($v, "the {$k} step is gone from autoGenerate");
        }
        $this->assertLessThan($place, $load,
            'inputs must be guarded before the grid is pre-filled with empty cells');
        $this->assertLessThan($write, $load,
            'inputs must be guarded before anything is committed');

        // And the guard must genuinely be inside the loader that runs first.
        $this->assertStringContainsString('if ($totalAssignments === 0) {',
            $this->method('_loadGenerationInputs'),
            'ACAD-D25 must live in the shared input loader, so BOTH destinations '
            . 'refuse to generate from an unreadable assignment set');
    }

    /** The message must not send the operator back to re-run destructively. */
    public function test_the_refusal_warns_against_re_running(): void
    {
        $fn = $this->generator();

        $i = strpos($fn, 'if ($totalAssignments === 0) {');
        $this->assertNotFalse($i);
        $branch = substr($fn, $i, 1200);

        $this->assertMatchesRegularExpression('~could not be read~i', $branch,
            'the message must admit the list may simply have failed to load, rather '
            . 'than asserting the school has no assignments');
        $this->assertMatchesRegularExpression('~cleared the~i', $branch,
            'it must say what re-running would have cost — otherwise the operator '
            . 'retries straight into the destructive path');
    }
    /**
     * ACAD-D27 — the operator must be warned BEFORE a destructive run, not after.
     *
     * autoGenerate already counts manual_skipped and manual_overwritten, and writes
     * both into the audit log once the run has finished. The confirmation dialog
     * showed only sections / free slots / conflicts — so an administrator could
     * approve a run that destroyed hand-built days without ever being told, and
     * only the audit trail would record what was lost.
     *
     * The numbers were already in the preview response. This is surfacing
     * information the system already had, before the irreversible step.
     */
    public function test_the_confirm_dialog_warns_about_manual_days(): void
    {
        $p = __DIR__ . '/../../application/views/academic/index.php';
        $this->assertFileExists($p);
        $v = (string) file_get_contents($p);

        $i = strpos($v, "post('academic/auto_generate_timetable',{preview:'1'})");
        $this->assertNotFalse($i, 'the generator preview call is gone');
        $region = substr($v, $i, 2200);

        $this->assertStringContainsString('r.manual_overwritten', $region,
            'the confirm dialog must read the overwrite count the server already sends');
        $this->assertStringContainsString('r.manual_skipped', $region,
            'the confirm dialog must also report what will be preserved, so the '
            . 'warning is trustworthy rather than alarming');

        $this->assertMatchesRegularExpression('~OVERWRITTEN~', $region,
            'the destructive outcome must be stated plainly, not implied');
        $this->assertMatchesRegularExpression('~cannot be recovered~i', $region,
            'there is no rollback, so the dialog must say the loss is permanent');

        // The wording existing is not enough — it has to be REACHED. Asserting only
        // that the text is present let a mutant survive that kept every string and
        // simply never appended it.
        $this->assertMatchesRegularExpression('~if\(lost>0\) msg\+=~', $region,
            'the overwrite warning must actually be appended when days will be lost');
        $this->assertMatchesRegularExpression('~if\(kept>0\) msg\+=~', $region,
            'the preserved count must actually be appended when days are kept');
    }

    /** The warning must precede the confirmation, not follow it. */
    public function test_the_warning_is_built_before_the_confirm_prompt(): void
    {
        $p = __DIR__ . '/../../application/views/academic/index.php';
        $v = (string) file_get_contents($p);

        $i = strpos($v, "post('academic/auto_generate_timetable',{preview:'1'})");
        $this->assertNotFalse($i);
        $region = substr($v, $i, 2200);

        $warn    = strpos($region, 'manual_overwritten');
        $confirm = strpos($region, '_confirmModal(');

        $this->assertNotFalse($warn, 'the warning is gone');
        $this->assertNotFalse($confirm, 'the confirmation prompt is gone');
        $this->assertLessThan($confirm, $warn,
            'the warning must be composed before the dialog opens — a warning shown '
            . 'after the operator has already confirmed protects nobody');
    }
    /**
     * ACAD-D24 premise. Not a fix — this pins the CURRENT behaviour so the
     * decision report's claim about partial generation stays true, and so that
     * whoever makes it atomic later gets a failing test rather than a surprise.
     */
    public function test_generation_still_commits_in_non_atomic_chunks(): void
    {
        $fn = $this->generator();

        $this->assertMatchesRegularExpression('~array_chunk\(\$batchOps, \d+\)~', $fn,
            'ACAD-D24 premise: generation writes in sequential chunks');

        $this->assertMatchesRegularExpression("~'merge' => false~", $fn,
            'ACAD-D24 premise: each chunk replaces whole day documents');

        $this->assertMatchesRegularExpression('~groups of changes were already saved~', $fn,
            'ACAD-D24 premise: a mid-run failure stops, leaving earlier chunks '
            . 'committed and the school partly regenerated. If this assertion ever '
            . 'fails because generation became atomic, delete this test — the '
            . 'architecture decision it documents was taken.');
    }

    /**
     * ACAD-D22. The failure message used to tell the operator to use the
     * generation ID to "roll back any committed chunks". No rollback has ever
     * existed anywhere in this codebase, so the one instruction given at the
     * moment a school's timetable was half-rewritten was an instruction nobody
     * could follow.
     *
     * The behaviour is unchanged — this is still a non-atomic write. What
     * changed is that the message now describes the state the school is
     * actually in.
     */
    public function test_the_failure_message_does_not_promise_a_rollback(): void
    {
        $fn = $this->method('_commitLiveBatch');

        // The word itself is not the problem — DENYING a rollback is the honest
        // thing to say. What must not appear is an OFFER of one.
        foreach (['use to roll back', 'to roll back', 'can roll back',
                  'roll back any', 'to undo', 'can undo', 'to revert'] as $offer) {
            $this->assertStringNotContainsStringIgnoringCase($offer, $fn,
                "the message must not offer to '{$offer}': no such operation exists "
                . 'on this path, and an operator who tries to follow it loses time '
                . 'while the school runs on a half-updated timetable');
        }

        // And it must say what is actually true, or it is merely vaguer.
        $this->assertMatchesRegularExpression('~Nothing was undone~', $fn,
            'the operator must be told explicitly that nothing was reversed');
        $this->assertMatchesRegularExpression('~no automatic rollback~i', $fn,
            'and the absence must be stated, not merely implied by silence');
        $this->assertMatchesRegularExpression('~some classes~i', $fn,
            'and that the school is now inconsistent, not simply "failed"');
        $this->assertMatchesRegularExpression('~generation ID~i', $fn,
            'the generation ID is still the way to find what changed, so it must '
            . 'still be given — with an honest description of what it is for');
        $this->assertMatchesRegularExpression('~again~', $fn,
            'a refusal that does not say what to do next leaves the school stuck');
    }
}
