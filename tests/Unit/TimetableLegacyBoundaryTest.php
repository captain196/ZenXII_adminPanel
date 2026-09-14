<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE LEGACY BOUNDARY.
 *
 * The programme's hard invariant is that there is exactly ONE canonical source
 * of timetable truth. During migration the legacy `timetables` collection must
 * keep existing — the Teacher app reads it directly through the client SDK and
 * cannot be repointed without an app release — but it must stop being an
 * authority and become a derived projection.
 *
 * A boundary that is only written down drifts. This file is the executable
 * version: it pins the exact set of code paths that write the legacy collection,
 * so a NEW direct writer cannot be added quietly. Adding one is not forbidden by
 * a comment; it fails a test that names the file and says why.
 *
 * ── THE INVENTORY (verified against the repository, 2026-08-29) ─────────
 *
 * CANONICAL WRITERS — the version architecture (SOURCE OF TRUTH)
 *   Timetable_plan_store::ensureRegularPlan / createDraftVersion /
 *   createGenerationCandidate / createChangeVersion / saveCandidateDay /
 *   begin|complete|failGenerationRun / validateCandidate / publishCandidate
 *     → timetablePlans, timetableVersions, timetableVersionDays
 *   Firestore rules: all three are `allow read, write: if false` — server-only,
 *   written through the service account, no client access of any kind.
 *
 * CANONICAL ENTRY POINTS (exist, RBAC-gated, NOT yet backed by a UI)
 *   controllers/Timetable_lifecycle.php  — view / edit / manage on `Academic`
 *     routes: timetable/{versions,verdict,projection_preview,
 *             create_change_version,generate_candidate,save_day,
 *             validate_candidate,publish}
 *   controllers/Timetable_projection_cron.php — CLI-only, APPLY=1 required,
 *     lifecycle-guarded; the ONLY thing that materialises the legacy surface
 *
 * LEGACY DIRECT WRITERS — still live in production, must not be extended
 *   Timetable_service::_liveBatchOpsFrom  (via _commitLiveBatch; autoGenerate)
 *   Timetable_service::savePeriod
 *   Timetable_service::saveSectionTimetable
 *
 * RETIRED 2026-08-29 — no longer writes, no callers remain
 *   Timetable_service::reconcileClassFromMatrix
 *     Assignment changes used to silently rewrite live timetable teachers.
 *     Replaced by Academic::_timetable_assignments_changed(), which marks the
 *     affected draft/candidate verdicts stale (approved Option B).
 *
 * COMPATIBILITY WRITER — the only sanctioned writer of the legacy collection
 *   Timetable_plan_store::projectPublishedVersion   (dry-run by default)
 *     Reachable over HTTP ONLY as a read-only preview; applying it is the
 *     cron's job, behind APPLY=1. That flag is the coordinated-switchover seam:
 *     while the legacy direct writers below are still authoritative, enabling it
 *     would create two competing writers of the same documents.
 *
 * LEGACY REPAIR TOOL — dry-run by default, requires APPLY=1
 *   controllers/Timetables_reconcile.php
 *
 * READERS pending migration (cannot move without a release)
 *   Teacher app  TimetableFirestoreRepository   (schoolId + sectionKey)
 *   Teacher app  LessonPlanFirestoreRepository  (direct docId get)
 *   Admin        Academic.php, Substitute_service, Lesson_plan_service,
 *                Analytics_service, Timetables_healthcheck/verify
 */
final class TimetableLegacyBoundaryTest extends TestCase
{
    private function src(string $file): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../application/libraries/' . $file);
    }

    /** Map a byte offset to the function it sits in. */
    private function enclosingFunctions(string $code, string $needle): array
    {
        $out = [];
        $offset = 0;
        while (($pos = strpos($code, $needle, $offset)) !== false) {
            $before = substr($code, 0, $pos);
            if (preg_match_all('~function\s+(\w+)\s*\(~', $before, $m)) {
                $out[] = end($m[1]);
            }
            $offset = $pos + 1;
        }
        return array_values(array_unique($out));
    }

    // ── the legacy writers are exactly these four ───────────────────────

    public function test_the_set_of_legacy_direct_writers_is_pinned(): void
    {
        $code = $this->src('Timetable_service.php');

        $writers = array_merge(
            $this->enclosingFunctions($code, 'firestoreSet(self::COLLECTION_TT'),
            $this->enclosingFunctions($code, 'firestoreUpdate(self::COLLECTION_TT'),
            $this->enclosingFunctions($code, 'firestoreDelete(self::COLLECTION_TT'),
            $this->enclosingFunctions($code, "'collection' => self::COLLECTION_TT")
        );
        $writers = array_values(array_unique($writers));
        sort($writers);

        // reconcileClassFromMatrix was retired on 2026-08-29: subject-assignment
        // changes no longer rewrite timetable content, they mark the affected
        // draft and candidate verdicts stale (approved Option B). It kept its
        // signature and returns a truthful zero, but it writes nothing, so it
        // is no longer a direct writer.
        $this->assertSame(
            ['_liveBatchOpsFrom', 'savePeriod', 'saveSectionTimetable'],
            $writers,
            "the set of code paths that write the legacy timetable collection has "
            . 'changed. If you added one, that is a new second source of truth: the '
            . 'canonical path is a timetable version (draft → validate → publish → '
            . 'project). If you removed one, update this list and the inventory '
            . 'above — that is progress, not a failure.');
    }

    /**
     * The retirement must stay retired. Re-adopting this method would restore a
     * path where changing subject assignments silently edits a timetable that
     * teachers are already looking at.
     */
    public function test_the_retired_assignment_sync_has_no_callers_and_no_writes(): void
    {
        $svc = $this->src('Timetable_service.php');
        $i   = strpos($svc, 'public function reconcileClassFromMatrix(');
        $this->assertNotFalse($i);
        $body = substr($svc, $i, (int) strpos(substr($svc, $i), "
    }
"));

        foreach (['firestoreUpdate', 'firestoreSet', 'firestoreDelete'] as $w) {
            $this->assertStringNotContainsString($w, $body,
                'the retired assignment sync must not write anything; assignment '
                . 'changes mark verdicts stale, they do not edit timetables');
        }

        foreach (glob(__DIR__ . '/../../application/controllers/*.php') as $file) {
            $code = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
            $code = (string) preg_replace('~^\s*//.*$~m', '', $code);
            $this->assertStringNotContainsString('reconcileClassFromMatrix(', $code,
                basename($file) . ' calls the retired assignment sync');
        }
    }

    public function test_every_legacy_writer_is_marked_as_legacy(): void
    {
        $code = $this->src('Timetable_service.php');

        foreach (['_liveBatchOpsFrom', 'savePeriod', 'saveSectionTimetable'] as $fn) {
            $i = strpos($code, "function {$fn}(");
            $this->assertNotFalse($i, "{$fn}() is gone");

            // The banner sits immediately above the declaration.
            $above = substr($code, max(0, $i - 1400), min($i, 1400));
            $this->assertStringContainsString('LEGACY DIRECT WRITE — DO NOT EXTEND', $above,
                "{$fn}() writes the legacy collection but is not marked legacy, so "
                . 'the next person to read it has no way to know it is superseded');
        }
    }

    // ── the canonical layer must not become a second legacy writer ──────

    public function test_only_the_projection_writes_the_legacy_collection(): void
    {
        $code = $this->src('Timetable_plan_store.php');

        $writers = $this->enclosingFunctions($code, 'self::COLLECTION_LEGACY_TT');
        sort($writers);

        $this->assertSame(['_existingLegacyDocs', 'projectPublishedVersion'], $writers,
            'the canonical layer may touch the legacy collection ONLY to project '
            . 'into it; any other use makes it an authority again');
    }

    /**
     * The canonical entry points are where a second authority would most easily
     * appear — a controller is the natural place someone would "just also write
     * the live doc" to make a change show up immediately.
     */
    public function test_the_canonical_entry_points_never_write_the_legacy_collection(): void
    {
        foreach (['Timetable_lifecycle.php', 'Timetable_projection_cron.php'] as $f) {
            $code = (string) file_get_contents(
                __DIR__ . '/../../application/controllers/' . $f);

            // Quote-agnostic on purpose: a mutation using "timetables" instead
            // of 'timetables' slipped past the single-quoted form of this
            // assertion, which made the guard look stronger than it was.
            $this->assertDoesNotMatchRegularExpression('~[\'"]timetables[\'"]~', $code,
                "{$f} must not name the legacy collection directly, in either "
                . 'quote style; the only sanctioned write goes through '
                . 'projectPublishedVersion()');
            $this->assertStringNotContainsString('COLLECTION_TT', $code,
                "{$f} must not reach the legacy generator's collection constant");
        }

        // And the HTTP surface must not be able to apply a projection at all.
        $http = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_lifecycle.php');
        $this->assertStringNotContainsString('$versionId, true', $http,
            'applying the compatibility projection over HTTP would rewrite what '
            . 'every teacher sees from a web request; it is deliberately CLI-only');
    }

    public function test_the_pure_services_never_name_the_legacy_collection(): void
    {
        foreach (['Timetable_plan_service.php', 'Timetable_validation_service.php'] as $f) {
            $this->assertStringNotContainsString("'timetables'", $this->src($f),
                "{$f} is pure and must not know where anything is stored");
        }
    }

    /**
     * The projection service maps INTO the legacy shape, so it may not name the
     * collection either — the store decides where the result goes.
     */
    public function test_the_projection_service_maps_but_does_not_store(): void
    {
        $code = $this->src('Timetable_projection_service.php');

        $this->assertStringNotContainsString("'timetables'", $code);
        foreach (['firestoreSet', 'firestoreQuery', 'firestoreGet', 'firestoreUpdate'] as $io) {
            $this->assertStringNotContainsString($io, $code,
                'the projection mapper must stay pure so its output can be inspected '
                . 'before anything reaches teachers');
        }
    }

    // ── the canonical collections are written only by the canonical layer ─

    public function test_the_legacy_generator_never_writes_canonical_collections(): void
    {
        $code = $this->src('Timetable_service.php');

        foreach (['timetableVersions', 'timetableVersionDays', 'timetablePlans'] as $c) {
            $this->assertStringNotContainsString("'{$c}'", $code,
                'the legacy service must not write the canonical collections '
                . 'directly; it goes through the plan store, which owns their shape');
        }
    }

    // ── one canonical source ────────────────────────────────────────────

    public function test_there_is_no_path_from_legacy_back_into_canonical(): void
    {
        $code = $this->src('Timetable_plan_store.php');

        // The one place the legacy collection is READ is the projection's
        // own comparison. Nothing may turn a legacy document into canonical
        // content, which is what uncontrolled two-way sync would be.
        $readers = $this->enclosingFunctions($code, 'COLLECTION_LEGACY_TT, [');
        $this->assertSame(['_existingLegacyDocs'], $readers,
            'reading the legacy collection anywhere else would be the first half '
            . 'of a two-way sync');

        $fn = substr($code, (int) strpos($code, 'function _existingLegacyDocs('));
        $fn = substr($fn, 0, (int) strpos($fn, "\n    /**"));
        foreach (['saveCandidateDay', 'createDraftVersion', 'firestoreSet'] as $w) {
            $this->assertStringNotContainsString($w, $fn,
                'reading the legacy collection must never write canonical data');
        }
    }
}
