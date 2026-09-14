<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';

/**
 * EVERY WRITER OF THE COMPATIBILITY COLLECTION, INCLUDING THE ONES OUTSIDE
 * application/.
 *
 * `timetables` is what the Teacher and Parent apps read. Under the canonical
 * architecture it is a projection of the published version and has exactly one
 * legitimate writer. An existing guard pins the writers inside application/ —
 * and stops at its boundary, because it walks that directory only.
 *
 * scripts/ writes it too. The session-rollover tool copies a school's whole
 * timetable into the new session, and it is the one writer of this collection
 * that is neither the projection nor a retired path: it creates documents that
 * no canonical version authored, in a session where the canonical side has
 * nothing at all — rollover carries `timetables`, `timetableSettings` and
 * `subjectAssignments`, and does not carry timetablePlans, timetableVersions or
 * timetableVersionDays.
 *
 * That is not a bug this file can fix. A school that rolls over needs a
 * timetable in the new session, and whether last year's is carried or the year
 * starts blank is already the school's choice through the tool's own strategy
 * flag. What matters here is that the second writer is VISIBLE, that its
 * consequence is written down, and that a THIRD one cannot arrive unnoticed.
 */
final class TimetableWriterAuthorityScriptsTest extends TestCase
{
    private const SCHOOL = 'SCH_TT01';
    private const SESS   = '2026-27';

    /** Every .php file under the given roots, cache and logs excluded. */
    private function sourceFiles(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            $real = realpath(__DIR__ . '/../../' . $root);
            if ($real === false) { continue; }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
                $path = str_replace('\\', '/', $file->getPathname());
                if (strpos($path, '/cache/') !== false) { continue; }
                if (strpos($path, '/logs/')  !== false) { continue; }
                $out[$path] = (string) file_get_contents($path);
            }
        }
        return $out;
    }

    /** A comment naming a write is not a write. */
    private function code(string $src): string
    {
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        return (string) preg_replace('~^\s*//.*$~m', '', $src);
    }

    public function test_the_writers_of_the_compatibility_collection_are_pinned_across_the_repository(): void
    {
        $writers = [];
        foreach ($this->sourceFiles(['application', 'scripts']) as $path => $src) {
            // Both the framework helper and the raw client surface, by literal
            // or by either class constant for the collection.
            if (preg_match('~(firestore(Set|Update|Delete)|setDocument|updateDocument'
                . '|deleteDocument)\s*\(\s*'
                . "(['\"]timetables['\"]|self::COLLECTION_LEGACY_TT|self::COLLECTION_TT)~",
                $this->code($src))) {
                $writers[] = basename($path);
            }
        }
        $writers = array_values(array_unique($writers));
        sort($writers);

        $this->assertSame(
            [
                // The projection — the one writer the architecture sanctions.
                'Timetable_plan_store.php',
                // The retired generator. Its write statements survive as
                // readable history and cannot execute: every method that
                // reaches one calls _refuseLegacyWrite() first, which throws
                // unconditionally.
                'Timetable_service.php',
                // The legacy repair tool, which skips projected documents.
                'Timetables_reconcile.php',
                // The session-rollover tool. It carries a school's timetable
                // into the new session WITHOUT carrying the canonical plan or
                // its versions, so the new session's teacher-visible documents
                // are ones no published version authored. Retiring or
                // redirecting it is a session-boundary decision with an
                // operational consequence — a school that rolls over would
                // otherwise have no timetable until it publishes one.
                'rollover_academic_session.php',
            ],
            $writers,
            'a new writer of `timetables` is a second source of truth for what '
            . 'teachers and parents see. Adding one must be a decision somebody '
            . 'makes on purpose.'
        );
    }

    /** The canonical side is not carried, which is what makes the above true. */
    public function test_rollover_carries_the_projection_but_not_the_canonical_authority(): void
    {
        $src = $this->code((string) file_get_contents(
            __DIR__ . '/../../scripts/rollover_academic_session.php'));

        $this->assertMatchesRegularExpression("~setDocument\(\s*'timetables'~", $src,
            'premise: rollover writes the compatibility collection');

        foreach (['timetablePlans', 'timetableVersions', 'timetableVersionDays'] as $canonical) {
            $this->assertStringNotContainsString($canonical, $src,
                "rollover does not carry {$canonical}, so after a rollover the "
                . 'canonical authority has nothing for the new session while the '
                . 'apps already show a full carried timetable');
        }
    }

    // ── what happens when the two writers meet ──────────────────────────

    /** A document a rollover carried, for a section the new version defines. */
    private function carried(string $sectionKey, string $day = 'Monday'): array
    {
        $safe = str_replace('/', '_', $sectionKey);
        return [self::SCHOOL . '_' . self::SESS . '_' . $safe . '_' . $day => [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'sectionKey' => $sectionKey, 'day' => $day,
            'className' => explode('/', $sectionKey)[0],
            'section'   => explode('/', $sectionKey)[1],
            'periods' => [
                ['periodNumber' => 1, 'subject' => 'Last year\'s subject',
                 'teacherId' => 'STA_OLD', 'teacher' => 'A Former Teacher',
                 'startTime' => '09:00', 'endTime' => '09:40', 'type' => 'class'],
            ],
            'version' => 1,
            'carriedFromSession' => '2025-26',
        ]];
    }

    private function projectionFor(array $sectionKeys): array
    {
        $days = [];
        foreach ($sectionKeys as $sk) {
            $days[] = ['classSection' => $sk, 'day' => 'Monday', 'periods' => [
                ['periodNumber' => 1, 'subject' => 'Mathematics',
                 'teacherId' => 'STA0001', 'teacher' => 'A Teacher',
                 'startTime' => '09:00', 'endTime' => '09:40', 'type' => 'class'],
            ]];
        }
        return Timetable_projection_service::project($days, [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => 'PLAN1', 'versionId' => 'V1',
            'at' => '2026-09-01T00:00:00+00:00', 'byUid' => 'STA_HEAD',
        ]);
    }

    /**
     * Where the two writers overlap, the canonical version wins: projecting
     * overwrites the carried document, so teachers stop seeing last year's
     * lesson in a section this year's version defines.
     */
    public function test_the_published_version_overwrites_a_carried_document(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);
        $existing  = $this->carried('Class 9th/Section A');

        $plan = Timetable_projection_service::plan($projected, $existing);

        $docId = array_key_first($projected);
        $this->assertArrayHasKey($docId, $plan['writes']);
        $this->assertSame('Mathematics', $plan['writes'][$docId]['periods'][0]['subject']);
        $this->assertSame(2, $plan['writes'][$docId]['version'],
            'the legacy version counter advances from what was carried');
    }

    /**
     * THE CONSEQUENCE WORTH KNOWING. Where they do NOT overlap — a section the
     * rollover carried and the new version does not define — the projection
     * reports the document and leaves it alone. It stays in the collection the
     * apps read, so a teacher or parent can still be shown a section that this
     * year's published timetable does not contain.
     *
     * Reported-not-deleted is the safe half of the answer and deliberate: a
     * projection that silently deleted live teacher-visible documents would be
     * the hidden destructive step. Acting on the report is a separate,
     * authorised step.
     */
    public function test_a_carried_section_the_new_version_drops_survives_as_an_orphan(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);
        $existing  = $projected + $this->carried('Class 8th/Section C');

        $plan = Timetable_projection_service::plan($projected, $existing);

        $orphan = self::SCHOOL . '_' . self::SESS . '_Class 8th_Section C_Monday';
        $this->assertSame([$orphan], $plan['orphans'],
            'the carried section is named so somebody can decide about it');
        $this->assertArrayNotHasKey($orphan, $plan['writes'],
            'and it is neither rewritten nor removed by the projection');
    }

    // ── no teacher-visible document changes through an HTTP request ─────

    /**
     * The lifecycle controller can PREVIEW a projection but never apply one:
     * applying is the cron's job behind APPLY=1. That is what keeps a single
     * writer single — an HTTP handler that could apply would let two admins
     * project competing versions concurrently.
     */
    /**
     * No CONTROLLER calls the projection with the apply flag itself.
     *
     * This assertion used to look at Timetable_lifecycle.php alone and was
     * named "no http handler applies the projection", which overstated it:
     * publishing over HTTP DOES apply the projection, through
     * publishCandidate(). The property that is actually true, and worth
     * keeping true, is narrower — no controller reaches past the publish
     * lifecycle to write the compatibility collection on its own terms. It is
     * checked across every controller now, not just the one.
     */
    public function test_no_controller_applies_the_projection_directly(): void
    {
        $seen = 0;
        foreach ($this->sourceFiles(['application/controllers']) as $path => $raw) {
            $src = $this->code($raw);
            if (strpos($src, 'projectPublishedVersion(') === false) continue;

            // A CLI-only controller is not an HTTP surface. It has to PROVE
            // that, though — the exclusion is earned by the guard actually
            // being present and refusing, never by the file being named like
            // a job. Timetable_projection_cron lives in controllers/ and is
            // reachable over HTTP the moment that guard is removed.
            if (strpos($src, 'is_cli_request()') !== false) {
                $this->assertMatchesRegularExpression(
                    '~if\s*\(\s*!\$this->input->is_cli_request\(\)\s*\)\s*\{\s*show_error~',
                    $src,
                    basename($path) . ': claims to be CLI-only, so it must refuse '
                    . 'a web request outright');
                continue;
            }

            preg_match_all('~projectPublishedVersion\(([^;]*?)\)\s*;~s', $src, $m);
            foreach ($m[1] as $args) {
                $seen++;
                if (substr_count($args, ',') === 1) { continue; }   // dry run

                // ── the one applying controller, and what earns it ──────
                //
                // Timetable_lifecycle::apply_projection() sends the timetable
                // in force TODAY to the apps. It exists because publishing a
                // FUTURE-dated version projects nothing, and the cron that was
                // supposed to catch up is scheduled by nothing in this repo —
                // so a school published on 2026-09-12 for the 13th and the apps
                // would never have shown it.
                //
                // The property this test protects is not "no controller
                // writes"; publishing already writes. It is that no controller
                // CHOOSES what to write. So the exclusion is earned, never
                // granted by filename: this caller must take its version from
                // the resolver, cost `manage`, and be refused for any date but
                // today. Strip any of those and it fails here.
                $this->assertSame('Timetable_lifecycle.php', basename($path),
                    basename($path) . ': a projection applied straight from an '
                    . 'HTTP request bypasses the publish lifecycle that decides '
                    . 'whether a version may reach teachers at all — ' . trim($args));

                $this->assertMatchesRegularExpression(
                    '~\$res\s*=\s*\$store->resolveForDate\(\$today\)~', $src,
                    'the applying controller must take the version from the '
                    . 'resolver on the server\'s own today. Choosing one itself '
                    . 'would make it a second authority on which timetable is in '
                    . 'force, which is the whole thing this guard prevents');
                $this->assertStringContainsString('$vid', trim($args),
                    'the applied version must be the resolved one, not an id the '
                    . 'request supplied');
                $this->assertStringContainsString(
                    "require_permission('Academic', 'timetable_projection_apply', 'manage')",
                    $src, 'applying must cost `manage`, the grade publishing costs');
                $this->assertMatchesRegularExpression('~\$asked\s*!==\s*\$today~', $src,
                    'applying must be refused for any date but today — the '
                    . 'collection has one document per weekday and no date, so any '
                    . 'other day would show a timetable early or roll one back');
            }
        }
        $this->assertGreaterThan(0, $seen,
            'premise: some controller reaches the projection at all');
    }

    /**
     * PUBLISHING is the one HTTP path that applies the projection, and it is
     * meant to be.
     *
     * A school that publishes today's timetable expects teachers to see it
     * without knowing a cron exists, so publishCandidate() projects when the
     * version is in force now. That is a deliberate write to the collection
     * both apps read, so the guarantees around it are pinned here rather than
     * left to a comment: it is `manage`-gated, and it is suppressed when the
     * resolver would be ambiguous.
     */
    public function test_publishing_is_the_only_http_path_that_applies_it(): void
    {
        $handlers = [];
        foreach ($this->sourceFiles(['application/controllers']) as $path => $raw) {
            if (strpos($this->code($raw), 'publishCandidate(') !== false) {
                $handlers[] = basename($path);
            }
        }
        $this->assertSame(['Timetable_lifecycle.php'], $handlers,
            'exactly one HTTP surface may make a timetable live');

        $src = $this->code((string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_lifecycle.php'));
        $this->assertMatchesRegularExpression(
            "~function publish\(\).*?require_permission\(\s*'Academic'\s*,\s*'timetable_publish'\s*,\s*'manage'\s*\)~s",
            $src,
            'the step that changes what every teacher sees is `manage`, not `edit`');

        // And an ambiguous state is not reachable through this path at all.
        //
        // This assertion used to require the branch that SUPPRESSED the
        // projection when a publish overlapped something: publish went ahead,
        // and the projection was skipped so as not to resolve an ambiguous date
        // by writing whichever version was saved last. TT-C8 replaced that with
        // a refusal one step earlier, so the branch is gone — and asserting a
        // dead branch still exists would have blocked the better fix.
        //
        // What has to remain true is stronger: publishing REFUSES rather than
        // producing the ambiguity, and it commits the guard token with the
        // version so two publishes cannot both get through.
        $store = $this->code((string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php'));

        $this->assertMatchesRegularExpression(
            "~if \(!empty\(\\\$overlaps\)\) \{.*?throw new Service_exception\(.*?'overlap'~s",
            $store,
            'an overlapping publish must be refused, not reported — the '
            . 'resolver cannot choose between two live timetables and the '
            . 'projection cannot represent them');

        $this->assertMatchesRegularExpression(
            "~firestoreCommitBatchStatus\(\[\s*\['collection' => self::COLLECTION_VERSIONS.*?\\\$guardWrite,\s*\]\)~s",
            $store,
            'the version write and the concurrency token must be ONE commit');

        $this->assertMatchesRegularExpression(
            "~\\\$commitStatus === 'PRECONDITION_CONFLICT'~", $store,
            'and a lost race must be refused rather than swallowed');
    }

    /**
     * The apply flag has exactly two callers, and they are the two the
     * architecture names.
     *
     * `timetables` is the only collection in this system that two subsystems
     * could plausibly both claim, so who may write it with $apply = true is
     * the single most important fact about it. A comment saying so is not
     * enough — this fails if a third one appears.
     */
    public function test_the_apply_flag_has_exactly_two_callers(): void
    {
        $callers = [];
        foreach ($this->sourceFiles(['application', 'scripts']) as $path => $raw) {
            $src = $this->code($raw);
            // Three arguments, or two plus an explicit apply variable.
            if (preg_match('~projectPublishedVersion\([^;]*?,[^;]*?,[^;]*?\)~s', $src)) {
                $callers[] = basename($path);
            }
        }
        $callers = array_values(array_unique($callers));
        sort($callers);

        $this->assertSame(
            [
                // The school's own "send to teachers and parents" action, for
                // the case publishing cannot cover: a version published to take
                // effect LATER projects nothing on the day it is published, and
                // the cron below is scheduled by nothing in this repository. It
                // chooses no version — it applies whatever resolveForDate()
                // says is in force today, refuses every other date, and costs
                // `manage`. Those constraints are enforced in
                // test_no_controller_applies_the_projection_directly() and in
                // TimetableReachesTheAppsTest, not by this list.
                'Timetable_lifecycle.php',
                // Publishing something already in force, so a school does not
                // have to wait for the cron to reach its teachers.
                'Timetable_plan_store.php',
                // The scheduled materialiser — passes $this->apply, which is
                // false unless APPLY=1.
                'Timetable_projection_cron.php',
            ],
            $callers,
            'a fourth caller of the applying projection is a fourth authority '
            . 'over what teachers and parents see'
        );
    }

    /**
     * Cloud Functions are outside every PHP scan in this file.
     *
     * The census is only a census if it covers the whole system. Functions run
     * with admin credentials and bypass Firestore rules entirely, so a writer
     * there would be invisible to gate B's rule AND to the PHP guard above.
     */
    public function test_cloud_functions_do_not_write_the_compatibility_collection(): void
    {
        $root = realpath(__DIR__ . '/../../functions');
        if ($root === false) {
            $this->markTestSkipped('functions/ is not present in this checkout');
        }

        $hits = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'js') continue;
            $path = str_replace('\\', '/', $file->getPathname());
            if (strpos($path, '/node_modules/') !== false) continue;

            $src = (string) preg_replace('~/\*.*?\*/~s', '',
                   (string) preg_replace('~^\s*//.*$~m', '', (string) file_get_contents($path)));

            // collection('timetables…') or doc('timetables/…') followed by a
            // write anywhere in the same statement.
            if (preg_match('~(collection|doc)\(\s*[\'"`]timetable~i', $src)) {
                $hits[] = basename($path);
            }
        }

        $this->assertSame([], array_values(array_unique($hits)),
            'a Cloud Function writing `timetables` would bypass both the '
            . 'Firestore rule that denies client writes and every PHP guard '
            . 'in this file');
    }
}
