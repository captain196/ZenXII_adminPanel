<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';

/**
 * TT-C8 MULTI-TENANT ISOLATION GATE.
 *
 * One platform scheduler now processes every school in a single process. That
 * is the right architecture, and it puts several schools' timetable data in the
 * same PHP process at the same time — so "school A's projection wrote school
 * B's timetable" stops being impossible-by-deployment and becomes a property
 * that has to be proven.
 *
 * The blast radius is the worst available in this product: a parent opening the
 * app would see another school's lessons, and nothing about the documents would
 * look wrong.
 *
 * These exercise the REAL projection service against two tenants deliberately
 * built to collide — identical class names, identical section names, identical
 * session labels — because those are the inputs under which a scoping bug stops
 * being visible.
 */
final class TimetableMultiTenantIsolationTest extends TestCase
{
    private const A = 'SCH_AAAA1111';
    private const B = 'SCH_BBBB2222';

    /** A day of the SAME class and section in both schools — the collision case. */
    private function day(string $subject, string $teacher): array
    {
        return [
            'classSection' => 'Class 8th/Section A',
            'day'          => 'Monday',
            'periods'      => [[
                'periodNumber' => 1, 'subject' => $subject,
                'teacher' => $teacher, 'teacherId' => 'STA0001',
                'startTime' => '8:00AM', 'endTime' => '8:45AM',
                'room' => '', 'type' => 'class',
            ]],
        ];
    }

    private function project(string $school, string $session, array $days,
                             string $versionId = 'V1'): array
    {
        return Timetable_projection_service::project($days, [
            'schoolId' => $school, 'session' => $session, 'planId' => 'REGULAR',
            'versionId' => $versionId, 'at' => '2026-09-13T00:20:00+05:30',
            'byUid' => 'cron',
        ]);
    }

    private function skipIfNoNormaliser(): void
    {
        if (!class_exists('Entity_firestore_sync')) {
            $this->markTestSkipped('Entity_firestore_sync is not loadable in isolation');
        }
    }

    // ── 1-5, 14: two schools, colliding names, disjoint output ──────────

    /**
     * Same class, same section, same session, different timetables. If anything
     * about the projection keyed on the section rather than the tenant, these
     * two would land on the same documents and the later school would overwrite
     * the earlier one inside a single platform run.
     */
    public function test_two_schools_with_identical_class_and_section_never_collide(): void
    {
        $this->skipIfNoNormaliser();

        $a = $this->project(self::A, '2026-27', [$this->day('Mathematics', 'Teacher A')]);
        $b = $this->project(self::B, '2026-27', [$this->day('History',     'Teacher B')]);

        $this->assertCount(1, $a);
        $this->assertCount(1, $b);

        $this->assertSame([], array_intersect(array_keys($a), array_keys($b)),
            'two schools with the same class and section project onto the SAME '
            . 'document ids, so in one platform run the second school would '
            . 'overwrite the first — and parents would open another school\'s '
            . 'timetable');

        // …and each document is stamped with its own tenant, not the other's.
        $this->assertSame(self::A, reset($a)['schoolId']);
        $this->assertSame(self::B, reset($b)['schoolId']);
        $this->assertSame('Mathematics', reset($a)['periods'][0]['subject']);
        $this->assertSame('History',     reset($b)['periods'][0]['subject']);
    }

    /** Every field and the document id carry the tenant. */
    public function test_every_projected_document_belongs_to_exactly_its_tenant(): void
    {
        $this->skipIfNoNormaliser();

        foreach ([self::A, self::B] as $school) {
            $docs = $this->project($school, '2026-27', [$this->day('Science', 'T')]);

            foreach ($docs as $docId => $doc) {
                $this->assertStringStartsWith($school . '_', $docId,
                    "a projected document id does not begin with its own tenant");
                $this->assertSame($school, $doc['schoolId'],
                    'a projected document carries a different tenant than the one '
                    . 'it was projected for');

                $other = $school === self::A ? self::B : self::A;
                $this->assertStringNotContainsString($other, $docId,
                    "another tenant's id appears in this document id");
                $this->assertStringNotContainsString($other, json_encode($doc),
                    "another tenant's id appears somewhere in this document");
            }
        }
    }

    /** 3 + 4: the session is part of the identity, both when shared and when not. */
    public function test_the_session_separates_documents_as_strictly_as_the_tenant(): void
    {
        $this->skipIfNoNormaliser();

        $now  = $this->project(self::A, '2026-27', [$this->day('Maths', 'T')]);
        $next = $this->project(self::A, '2027-28', [$this->day('Maths', 'T')]);

        $this->assertSame([], array_intersect(array_keys($now), array_keys($next)),
            'two sessions of the SAME school project onto the same documents, so '
            . 'rolling over a session would overwrite the year still being taught');
        $this->assertSame('2026-27', reset($now)['session']);
        $this->assertSame('2027-28', reset($next)['session']);
    }

    // ── 7-8: substitution attempts ──────────────────────────────────────

    /**
     * CROSS-TENANT SCHOOL-ID SUBSTITUTION. A version day that carries another
     * school's id — through corruption, a bad backfill, or a crafted write —
     * must not be able to steer the projection. The tenant comes from the
     * caller's context, which the platform loop takes from the document id it
     * discovered, and never from the payload being projected.
     */
    public function test_a_version_day_cannot_smuggle_in_another_tenants_id(): void
    {
        $this->skipIfNoNormaliser();

        $hostile = $this->day('Maths', 'T');
        $hostile['schoolId'] = self::B;          // the substitution attempt
        $hostile['session']  = '2099-00';

        $docs = $this->project(self::A, '2026-27', [$hostile]);

        $doc = reset($docs);
        $this->assertSame(self::A, $doc['schoolId'],
            'a version day carrying another school\'s id steers the projection, '
            . 'so corrupt or crafted canonical data could write into a different '
            . 'tenant');
        $this->assertSame('2026-27', $doc['session'],
            'a version day can override the session it is projected into');
        $this->assertStringStartsWith(self::A . '_2026-27_', (string) key($docs),
            'the document id is built from the payload rather than the context');
    }

    /**
     * CROSS-TENANT SECTION-KEY SUBSTITUTION. The section key is REBUILT from
     * the normalised class and section, never copied, so a malformed or
     * hostile stored key cannot propagate into the collection both apps query.
     *
     * A '/' reaching a document id matters beyond tidiness: the Teacher app
     * reads a timetable cell by document id when validating a lesson-plan save,
     * and a '/' in an id makes that read THROW rather than return not-found.
     */
    public function test_a_hostile_section_key_cannot_reshape_the_document_id(): void
    {
        $this->skipIfNoNormaliser();

        $hostile = $this->day('Maths', 'T');
        $hostile['classSection'] = 'Class 8th/Section A/../' . self::B . '/Section Z';

        $docs = $this->project(self::A, '2026-27', [$hostile]);

        // REFUSED, not mangled. A key that is not a clean class/section pair
        // cannot address one document, so the projection emits nothing for it
        // rather than an id that merely looks addressable.
        $this->assertSame([], $docs,
            'a section key that is not a clean class/section pair still produces '
            . 'a document. Whatever id that is, the Teacher app rebuilds this id '
            . 'from ITS own normalisation to read a cell, so the two disagree — '
            . 'and the document carried another tenant\'s id as a substring');

        // The valid day beside it is unaffected: one bad section must not cost
        // a school the rest of its timetable.
        $mixed = $this->project(self::A, '2026-27',
            [$hostile, $this->day('Maths', 'T')]);
        $this->assertCount(1, $mixed,
            'refusing one malformed section dropped the valid ones too');
        $this->assertStringStartsWith(self::A . '_2026-27_Class 8th_Section A_',
            (string) key($mixed));
    }

    /**
     * The document id and the sectionKey FIELD must be built the same way.
     *
     * They were not: the field was rebuilt from the canonical parts (documented
     * as protecting the collection the apps query from a malformed stored key)
     * while the id was assembled from the RAW string. Found by this gate on
     * 2026-09-13.
     *
     * The loud version of that bug is a crafted key; the likely one is
     * duplication — the same section stored with different spacing yields two
     * ids but one canonical field, so the apps receive two documents for one day
     * and one silently wins.
     */
    public function test_the_document_id_and_the_section_key_field_agree(): void
    {
        $this->skipIfNoNormaliser();

        // Same section, written three ways a school's data plausibly contains.
        foreach (['Class 8th/Section A', '  Class 8th / Section A  ', '8/A'] as $written) {
            $day = $this->day('Maths', 'T');
            $day['classSection'] = $written;

            $docs = $this->project(self::A, '2026-27', [$day]);
            $this->assertCount(1, $docs, "no document for '{$written}'");

            $docId = (string) key($docs);
            $doc   = reset($docs);

            $expected = self::A . '_2026-27_'
                      . str_replace('/', '_', $doc['sectionKey']) . '_Monday';

            $this->assertSame($expected, $docId,
                "the document id and the sectionKey field disagree for '{$written}'. "
                . 'The apps query on the field and the Teacher app addresses the id, '
                . 'so the same section reaches them as two different documents');
        }
    }

    // ── 6: reads are scoped too ─────────────────────────────────────────

    /**
     * School B must not be able to READ school A's legacy documents. If it
     * could, the projection would see another tenant's documents as "existing",
     * report them as orphans, and — worse — treat A's matching documents as
     * already-current and skip writing B's.
     *
     * Defence in depth, and both halves matter: the query is scoped, AND every
     * returned row is re-checked. The re-check exists because a query can be
     * served by an index that is wrong or stale, and because Firestore's REST
     * row shape has fooled this exact method before.
     */
    public function test_the_legacy_read_is_scoped_and_then_re_checked(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        $i = strpos($store, 'function _existingLegacyDocs');
        $this->assertNotFalse($i, '_existingLegacyDocs() is gone');
        $body = substr($store, $i, 3000);

        $this->assertMatchesRegularExpression(
            "~\['schoolId',\s*'==',\s*\\\$this->schoolId\]~", $body,
            'the legacy read is not scoped to this tenant, so a projection would '
            . "see every school's documents");
        $this->assertMatchesRegularExpression(
            "~\['session',\s*'==',\s*\\\$this->session\]~", $body,
            'the legacy read is not scoped to this session');

        $this->assertMatchesRegularExpression(
            "~\\\$d\['schoolId'\] \?\? ''\) !== \\\$this->schoolId\) continue~", $body,
            'rows are not re-checked against the tenant after the query — a wrong '
            . 'or stale index would silently widen the read');
        $this->assertMatchesRegularExpression(
            "~\\\$d\['session'\]\s*\?\? ''\)\s*!== \\\$this->session\)\s*continue~", $body,
            'rows are not re-checked against the session after the query');
    }

    /** And the canonical reads are equally scoped. */
    public function test_canonical_reads_are_tenant_and_session_scoped(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        foreach (['listVersions', 'listCandidateDays'] as $reader) {
            $i = strpos($store, "function {$reader}");
            $this->assertNotFalse($i, "{$reader}() is gone");
            $body = substr($store, $i, 1800);

            // Whitespace-tolerant: the two readers align their arrays
            // differently, and alignment is not the property under test.
            $this->assertMatchesRegularExpression(
                "~\['schoolId',\s*'==',\s*\\\$this->schoolId\]~", $body,
                "{$reader}() is not scoped to this tenant, so one school could "
                . "resolve another school's timetable");
            $this->assertMatchesRegularExpression(
                "~\['session',\s*'==',\s*\\\$this->session\]~", $body,
                "{$reader}() is not scoped to this session");
        }
    }

    // ── 13: repeated and concurrent platform runs ───────────────────────

    /**
     * Two platform runs overlapping — a slow night still running when the next
     * fires, or an operator pressing Send during the cron — must converge, not
     * interleave into a mixture.
     *
     * They do because the write is a full materialisation of whatever the
     * resolver says is in force, and the resolver admits exactly one version
     * per date. Both runs therefore compute the SAME documents; the second
     * writes nothing at all.
     */
    public function test_a_second_overlapping_run_writes_nothing_for_any_tenant(): void
    {
        $this->skipIfNoNormaliser();

        foreach ([self::A, self::B] as $school) {
            $want = $this->project($school, '2026-27', [$this->day('Maths', 'T')]);

            $first = Timetable_projection_service::plan($want, []);
            $this->assertCount(1, $first['writes'], 'the first run writes');

            $after  = $first['writes'];
            $second = Timetable_projection_service::plan($want, $after);

            $this->assertSame([], $second['writes'],
                "a concurrent or repeated run rewrites {$school}'s documents, so "
                . 'two overlapping platform runs would churn every timetable and '
                . 'drift the version counter');
        }
    }

    /**
     * The per-tenant run record id is deterministic per tenant/session/day, so a
     * re-run overwrites its own row rather than creating a second one — and one
     * tenant's record can never land on another's.
     */
    public function test_run_records_are_per_tenant_and_idempotent(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');

        $this->assertMatchesRegularExpression(
            "~'TTP_' \. date\('Ymd'\) \. '_' \. \\\$schoolId \. '_' \. \\\$session~", $src,
            'the run-record id is not built from the tenant and session, so two '
            . "schools' outcomes could overwrite each other — or a re-run could "
            . 'pile up duplicate rows that hide the real one');
    }

    // ── 9-12: fleet behaviour under mixed tenant states ─────────────────

    /**
     * The states a real platform run meets at once, and what each must do.
     * These are pinned as the loop's OWN outcomes so that adding a state later
     * cannot quietly fold into an existing one.
     */
    public function test_the_loop_distinguishes_every_tenant_outcome(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');
        $i   = strpos($src, 'public function run_all');
        $body = substr($src, $i, 6000);

        // nothing published (10), refused, failed (9), lifecycle-skipped (12)
        foreach (['nothing', 'refused', 'failed', 'skipped', 'projected', 'current']
                 as $outcome) {
            $this->assertStringContainsString("'{$outcome}'", $body,
                "the platform loop no longer distinguishes the '{$outcome}' "
                . 'outcome, so that state is being reported as another one');
        }
    }

    /** 15: the Health Check answers per school, from the school's own context. */
    public function test_the_health_check_reports_each_tenant_independently(): void
    {
        $hc = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Health_check.php');
        $i  = strpos($hc, "'name' => 'Teachers See The Published Timetable'");
        $this->assertNotFalse($i);
        $body = substr($hc, $i, 3000);

        $this->assertMatchesRegularExpression(
            '~init\(\s*\$this->firebase,\s*\(string\) \$this->school_id,\s*'
            . '\(string\) \$this->session_year~', $body,
            'the health check does not build its store from THIS request\'s '
            . 'tenant and session, so one school could be shown another\'s state');

        // No school may be named in a check that every school runs.
        $this->assertSame(0, preg_match_all('~SCH_[A-Z0-9]{6,}~', $body),
            'a school id is hardcoded in the health check');
    }

    /** No tenant may be named anywhere in the scheduler. */
    public function test_the_scheduler_names_no_tenant_and_no_session(): void
    {
        foreach (['application/controllers/Timetable_projection_cron.php',
                  'scripts/run_scheduled.php'] as $rel) {
            $src = (string) file_get_contents(__DIR__ . '/../../' . $rel);
            $code = (string) preg_replace('~/\*[\s\S]*?\*/~', '', $src);
            $code = (string) preg_replace('~^\s*(//|#).*$~m', '', $code);

            $this->assertSame(0, preg_match_all('~SCH_[A-Z0-9]{6,}~', $code),
                "{$rel} hardcodes a school id");
            $this->assertSame(0, preg_match_all('~[\'"]20\d\d-\d\d[\'"]~', $code),
                "{$rel} hardcodes a session year");
        }
    }
}
