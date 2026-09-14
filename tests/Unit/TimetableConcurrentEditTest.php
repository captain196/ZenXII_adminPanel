<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D19 / ACAD-D20 — two ways one admin's timetable edit vanished silently.
 *
 * ── ACAD-D19: the preservation guard was unreachable ──────────────────────
 *
 * savePeriod() rebuilds the day's ENTIRE `periods` array from a snapshot, so
 * ACAD-D6 added a guard: if the existing day cannot be read, refuse, because
 * rebuilding from nothing blanks every period except the edited one.
 *
 *     try { $existingDoc = $this->firebase->firestoreGet(TT, $fsDocId); ... }
 *     catch (\Throwable $e) { ...throw Service_exception... }
 *
 * But firestoreGet() returns null for a FAILED read exactly as it does for a
 * genuine 404 (ACAD-D17) — it does not throw. The catch was therefore dead code
 * for every realistic failure, $existingPeriods stayed empty, and the day was
 * wiped anyway while the save reported success. The guard existed and did
 * nothing; only a fail-loud read makes it real.
 *
 * ── ACAD-D20: lost update between concurrent admins ───────────────────────
 *
 * savePeriod() rewrites the day's whole `periods` array, so the read and the
 * write must describe the same snapshot:
 *
 *   1. request A reads the day  {periods: [P1..P8], version: 5}
 *   2. request B reads the day  {periods: [P1..P8], version: 5}
 *   3. A applies its cell and writes  version 6
 *   4. B applies its cell and writes  version 6   <- A's cell gone
 *
 * `version` was written as prevVersion + 1 but never CHECKED, so it recorded
 * the collision without preventing it — both writes land on 6, so even the
 * counter does not reveal it.
 *
 * SEVERITY, STATED HONESTLY. An earlier draft of this comment claimed two
 * coordinators editing across an afternoon would lose each other's work. That
 * is WRONG, and the repository disproves it: savePeriod() takes ONE cell from
 * the client and rebuilds every other period from a server-side read issued in
 * the SAME request. A second request therefore re-reads and already contains
 * the first request's edit. The race window is between this request's own read
 * and its own write — milliseconds, not minutes.
 *
 * So this is a genuine read-modify-write race that Firestore does not prevent
 * without a precondition, but a NARROW one. It is worth closing because the
 * fix is cheap and reuses an existing primitive — not because it is frequent.
 *
 * WHERE THE WIDE VERSION LIVES (ACAD-D21, NOT fixed here).
 * saveSectionTimetable() accepts a CLIENT-SUPPLIED whole-week payload and
 * writes each day wholesale, using the stored doc only to backfill missing
 * times. There the stale snapshot is the operator's browser, so the window IS
 * human-scale and one admin's save silently discards another's. Closing it
 * needs the client to send back the version it loaded, and its only caller is
 * views/section_students.php — a file another programme is actively editing.
 * Recorded and left alone rather than half-fixed: a server-side guard that no
 * client ever triggers would read as protection while providing none.
 *
 * THE FIX USES WHAT ALREADY EXISTED. Nothing here is invented: decodeDocument()
 * already surfaces Firestore's server-assigned updateTime as `__updateTime`
 * specifically so callers can use it as a currentDocument.updateTime
 * precondition, commitBatch() already accepts one, and commitBatchStatus()
 * already classifies PRECONDITION_CONFLICT apart from an unknown failure. The
 * accounting CAS loop uses the same primitive.
 *
 * SCOPE NOTE — what this fix deliberately does NOT do: it refuses the losing
 * write rather than merging the two edits. Auto-merging non-overlapping cell
 * edits is a defensible product behaviour but it is a POLICY choice, not a
 * defect fix, and it is raised for owner decision rather than assumed here.
 * Refusing is the safe default: no edit is lost and the operator is told.
 */
final class TimetableConcurrentEditTest extends TestCase
{
    private function svc(): string
    {
        $p = __DIR__ . '/../../application/libraries/Timetable_service.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function method(string $name): string
    {
        $s = $this->svc();
        $i = strpos($s, "function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        $e = strpos($s, "\n    public function ", $i + 10);
        $this->assertNotFalse($e, "could not bound {$name}()");
        return substr($s, $i, $e - $i);
    }

    // ── ACAD-D19 ─────────────────────────────────────────────────────────

    /** Both day-preservation reads must be able to fail. */
    public function test_both_preservation_reads_are_fail_loud(): void
    {
        $s = $this->svc();

        $this->assertSame(2, preg_match_all(
            '~firestoreGetOrFail\(self::COLLECTION_TT~', $s),
            'savePeriod and saveSectionTimetable each rebuild a day from a snapshot '
            . 'read. On firestoreGet() a failed read is indistinguishable from an '
            . 'absent document, so the refusal below never fires and the day is '
            . 'rebuilt from nothing.');

        $this->assertSame(0, preg_match_all(
            '~firestoreGet\(self::COLLECTION_TT~', $s),
            'a preservation read is back on the silent getter');
    }

    /** The refusal has to actually be reachable from that read. */
    public function test_the_preservation_refusal_follows_the_fail_loud_read(): void
    {
        foreach (['savePeriod', 'saveSectionTimetable'] as $m) {
            $fn = $this->method($m);

            $r = strpos($fn, 'firestoreGetOrFail(self::COLLECTION_TT');
            $this->assertNotFalse($r, "{$m}: no fail-loud preservation read");

            $window = substr($fn, $r, 1800);
            $this->assertMatchesRegularExpression('~catch\s*\(\\\\Throwable~', $window,
                "{$m}: the preservation read has no handler");
            $this->assertStringContainsString('throw new Service_exception', $window,
                "{$m}: an unreadable day must refuse, not rebuild from nothing");
        }
    }

    // ── ACAD-D20 ─────────────────────────────────────────────────────────

    /** The CAS token must be captured from the same read the rebuild uses. */
    public function test_the_cas_token_is_captured_from_the_snapshot_read(): void
    {
        $fn = $this->method('savePeriod');

        $this->assertStringContainsString("\$existingDoc['__updateTime']", $fn,
            'the precondition must come from the SAME read the periods array was '
            . 'rebuilt from, or it does not describe the snapshot being replaced');

        $read  = strpos($fn, 'firestoreGetOrFail(self::COLLECTION_TT');
        $token = strpos($fn, '__updateTime');
        $this->assertNotFalse($token, 'the CAS token is not captured at all');
        $this->assertGreaterThan($read, $token,
            'the token must be taken from the read, not invented later');
    }

    /** The write must carry a precondition, and the right one. */
    public function test_the_write_is_conditional_on_the_snapshot(): void
    {
        $fn = $this->method('savePeriod');

        $this->assertStringContainsString("\$writeOp['precondition']", $fn,
            'an unconditional write is exactly the lost update this guards');

        $this->assertMatchesRegularExpression(
            "~\\\$casUpdateTime !== ''\s*\n?\s*\?\s*\['updateTime' => \\\$casUpdateTime\]~", $fn,
            'an existing day must be conditional on its updateTime');

        $this->assertMatchesRegularExpression(
            "~:\s*\['exists' => false\]~", $fn,
            'a NEW day must be conditional on not already existing — two admins '
            . 'creating the same day-doc at once is the same race');
    }

    /** A conflict must be told apart from an unknown failure. */
    public function test_a_conflict_is_distinguished_and_refuses(): void
    {
        $fn = $this->method('savePeriod');

        $this->assertStringContainsString('firestoreCommitBatchStatus', $fn,
            'commitBatch() returns a bare bool, which cannot tell a precondition '
            . 'conflict from a network failure — the operator needs to know which');

        $c = strpos($fn, "PRECONDITION_CONFLICT");
        $this->assertNotFalse($c, 'a CAS conflict is not detected');

        $branch = substr($fn, $c, 900);
        $this->assertStringContainsString('throw new Service_exception', $branch,
            'a losing write must refuse — silently proceeding is the defect');
        $this->assertStringContainsString("'conflict'", $branch,
            'the refusal must be typed as a conflict so the UI can treat it as '
            . 'retryable rather than as a hard error');
    }

    /** The message must tell the operator what to DO. */
    public function test_the_conflict_message_is_actionable(): void
    {
        $fn = $this->method('savePeriod');

        $c = strpos($fn, 'PRECONDITION_CONFLICT');
        $this->assertNotFalse($c);
        $branch = substr($fn, $c, 900);

        $this->assertMatchesRegularExpression('~Someone else changed~i', $branch,
            'the operator must learn that another person edited this day, not just '
            . 'that "an error occurred"');
        $this->assertMatchesRegularExpression('~Reload~i', $branch,
            'the message must say how to recover; a conflict the operator cannot '
            . 'act on just becomes a retry loop that keeps failing');
    }

    /** A non-conflict commit failure must also refuse, not fall through. */
    public function test_an_unknown_commit_failure_also_refuses(): void
    {
        $fn = $this->method('savePeriod');

        $this->assertMatchesRegularExpression(
            "~if \(\\\$status !== 'SUCCESS'\) \{~", $fn,
            'only checking for a conflict lets every OTHER commit failure report '
            . 'success — the original false-success shape in a new place');

        $i = strpos($fn, "if (\$status !== 'SUCCESS')");
        $this->assertNotFalse($i);
        $this->assertStringContainsString('throw new Service_exception',
            substr($fn, $i, 600),
            'a failed commit must refuse');
    }

    /** Still exactly one persistence call, still after every guard. */
    public function test_the_cas_commit_is_the_only_write_and_comes_last(): void
    {
        $fn = $this->method('savePeriod');

        preg_match_all(
            '~firestoreSet|firestoreDelete|firestoreUpdate|->set\(|->update\(|->remove\(|commitBatch|batchSet~i',
            $fn, $writes, PREG_OFFSET_CAPTURE);

        $this->assertCount(1, $writes[0],
            'introducing the CAS commit must not leave the old unconditional write '
            . 'behind as a second persistence path');

        $writeAt = $writes[0][0][1];
        foreach (['firestoreGetOrFail', 'PRECONDITION_CONFLICT'] as $marker) {
            $at = strpos($fn, $marker);
            $this->assertNotFalse($at, "{$marker} is gone");
        }

        $token = strpos($fn, '__updateTime');
        $this->assertLessThan($writeAt, $token,
            'the CAS token must be captured before the write that uses it');
    }

    /** The premise: the platform primitive this relies on still exists. */
    public function test_the_platform_cas_primitive_is_still_there(): void
    {
        $rest = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Firestore_rest_client.php');
        $fb = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Firebase.php');

        $this->assertStringContainsString("\$result['__updateTime']", $rest,
            'reads no longer surface updateTime, so no caller can build a CAS '
            . 'precondition');
        $this->assertStringContainsString("\$writeEntry['currentDocument']", $rest,
            'commitBatch no longer honours a precondition');
        $this->assertStringContainsString("const COMMIT_CONFLICT", $rest,
            'the conflict classification is gone');
        $this->assertStringContainsString('function firestoreCommitBatchStatus', $fb,
            'the wrapper this fix calls is gone');
    }
}
