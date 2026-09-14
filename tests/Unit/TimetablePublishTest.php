<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * A Firestore double for the full version lifecycle: versions and days, both
 * readable back, so validate → publish can be driven end to end.
 */
class FakePublishFirestore
{
    public array $versions = [];
    public array $days     = [];
    // Publishing an in-force timetable now projects it, so the double has to
    // hold the collection teachers read.
    public array $legacy   = [];
    public array $writes   = [];
    /** TT-C8: the per-(school,session) publish concurrency token. */
    public array $guards   = [];
    public bool  $refuseVersionWrite = false;
    public bool  $versionQueryUnreadable = false;
    public bool  $guardUnreadable = false;

    /**
     * Server-assigned document versions, keyed "collection\0docId".
     *
     * The CAS the publish transition depends on is expressed entirely through
     * these, so a double that did not model them could not tell a guarded
     * publish from an unguarded one — and would report a concurrency fix as
     * working while it did nothing.
     */
    public array $updateTimes = [];
    private int  $tick = 0;

    /** Fires INSIDE the commit, between precondition check and apply. */
    public $beforeCommit = null;

    /**
     * Fires right after the version scan that the overlap check reads — i.e.
     * BETWEEN "is anything else in force?" and the guard read that is supposed
     * to have preceded it.
     *
     * This is the only window that can tell whether the token is read before or
     * after the overlap check. Reading it afterwards leaves a publish that
     * lands during the scan invisible: the scan missed it, and the token picked
     * up later already includes it, so the commit succeeds and two timetables
     * go live. `beforeCommit` cannot model that, because by then the token has
     * been read either way.
     */
    public $afterVersionScan = null;

    private function key(string $c, string $d): string { return $c . "\0" . $d; }

    private function bump(string $c, string $d): void
    {
        $this->updateTimes[$this->key($c, $d)] = 'T' . (++$this->tick);
    }

    /** The store's view of a collection, by reference so writes land. */
    private function &bucket(string $collection): array
    {
        if ($collection === 'timetableVersions')          { $b =& $this->versions; }
        elseif ($collection === 'timetableVersionDays')   { $b =& $this->days; }
        elseif ($collection === 'timetables')             { $b =& $this->legacy; }
        else                                              { $b =& $this->guards; }
        return $b;
    }

    /**
     * Fires right after a VERSION document is read.
     *
     * Opens the window between "what does this version currently say?" and the
     * guard read that is supposed to have preceded it. `afterVersionScan` fires
     * on a query and cannot reach a caller that reads one document by id.
     */
    public $afterVersionRead = null;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($collection === 'timetableVersions' && is_callable($this->afterVersionRead)) {
            $cb = $this->afterVersionRead;
            $this->afterVersionRead = null;          // once
            $doc = $this->versions[$docId] ?? null;
            $cb($this);                               // someone else acts NOW
            return $doc;                              // ...on the value we already had
        }
        if ($collection === 'timetablePublishGuards') {
            if ($this->guardUnreadable) {
                throw new \RuntimeException('GET_FAILED:timetablePublishGuards:UNAVAILABLE');
            }
            $doc = $this->guards[$docId] ?? null;
            if (!is_array($doc)) return null;
            $ut = $this->updateTimes[$this->key($collection, $docId)] ?? '';
            return $ut === '' ? $doc : array_merge($doc, ['__updateTime' => $ut]);
        }
        return $this->versions[$docId] ?? null;
    }

    /** Every precondition in the batch, evaluated against the state right now. */
    private function preconditionsHold(array $ops): bool
    {
        foreach ($ops as $op) {
            $pre = $op['precondition'] ?? null;
            if (!is_array($pre)) continue;

            $c = (string) ($op['collection'] ?? '');
            $d = (string) ($op['docId'] ?? '');

            if (array_key_exists('exists', $pre)
                && $pre['exists'] !== array_key_exists($d, $this->bucket($c))) {
                return false;
            }
            if (isset($pre['updateTime'])
                && ($this->updateTimes[$this->key($c, $d)] ?? '') !== $pre['updateTime']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Batch commit with REAL precondition semantics: every precondition is
     * checked BEFORE anything is applied, and a single failure applies
     * nothing. Firestore rolls a batch back whole; a double that applied the
     * ops it could would make a broken CAS look like a working one.
     */
    public function firestoreCommitBatchStatus($ops)
    {
        if (!is_array($ops)) return ['status' => 'FAILURE'];

        foreach ($ops as $op) {
            if ($this->refuseVersionWrite
                && (string) ($op['collection'] ?? '') === 'timetableVersions') {
                return ['status' => 'FAILURE', 'message' => 'write refused'];
            }
        }

        if (!$this->preconditionsHold($ops)) {
            return ['status' => 'PRECONDITION_CONFLICT'];
        }

        if (is_callable($this->beforeCommit)) {
            $cb = $this->beforeCommit;
            $this->beforeCommit = null;      // once, or it recurses forever
            $cb($this);

            // The window has opened and someone may have written. Re-check
            // EVERY precondition, not just the updateTime ones — real
            // Firestore evaluates `exists` server-side at commit too, and a
            // double that re-checked only half of them let a first-publish
            // race through while reporting the guard as working. Which is why
            // the check is one function called twice, not two copies.
            if (!$this->preconditionsHold($ops)) {
                return ['status' => 'PRECONDITION_CONFLICT'];
            }
        }

        foreach ($ops as $op) {
            $c = (string) ($op['collection'] ?? '');
            $d = (string) ($op['docId'] ?? '');
            $data = is_array($op['data'] ?? null) ? $op['data'] : [];
            $b =& $this->bucket($c);
            $b[$d] = !empty($op['merge']) ? array_merge($b[$d] ?? [], $data) : $data;
            $this->bump($c, $d);
            $this->writes[] = ['collection' => $c, 'docId' => $d,
                               'data' => $data, 'merge' => !empty($op['merge'])];
        }
        // 'SUCCESS', not 'OK' — Firestore_rest_client::COMMIT_SUCCESS. This
        // double returned 'OK' while production checked for 'OK' too, so both
        // agreed and both were wrong; the real client returns 'SUCCESS' and
        // every publish was reported as failed. A double must return the
        // literal the real thing returns, or it certifies the mistake.
        return ['status' => 'SUCCESS'];
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        $want = [];
        foreach ($conditions as $c) $want[(string) ($c[0] ?? '')] = $c[2] ?? null;

        if ($collection === 'timetableVersions' && $this->versionQueryUnreadable) {
            throw new \RuntimeException('index missing');
        }
        $src = ['timetableVersionDays' => $this->days,
                'timetables'           => $this->legacy][$collection] ?? $this->versions;

        $out = [];
        foreach ($src as $row) {
            foreach ($want as $k => $v) {
                if (($row[$k] ?? null) !== $v) continue 2;
            }
            $out[] = $row;
        }

        if ($collection === 'timetableVersions' && is_callable($this->afterVersionScan)) {
            $cb = $this->afterVersionScan;
            $this->afterVersionScan = null;     // once
            // The rows already answered this caller's question. Whatever
            // happens now is invisible to it unless something later notices.
            $cb($this);
        }

        return $out;
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        if ($collection === 'timetableVersions') {
            if ($this->refuseVersionWrite) return false;
            $this->versions[$docId] = $merge
                ? array_merge($this->versions[$docId] ?? [], $data) : $data;
        }
        if ($collection === 'timetableVersionDays') {
            $this->days[$docId] = $merge
                ? array_merge($this->days[$docId] ?? [], $data) : $data;
        }
        if ($collection === 'timetables') {
            $this->legacy[$docId] = $merge
                ? array_merge($this->legacy[$docId] ?? [], $data) : $data;
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }
}

/**
 * PHASE B — THE PUBLISH TRANSITION.
 *
 * Publishing is the only thing that makes a timetable live, and it is one-way.
 * Everything here defends one claim: after publishing, the version is what was
 * published, and stays that way. A correction is a NEW version.
 *
 * The gates are not decoration. Each test below proves the refusal actually
 * happens and that nothing is written when it does — a guard that logs and
 * proceeds is the failure this programme was created to remove.
 */
final class TimetablePublishTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'PLAN_A';

    private function store(FakePublishFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function vDoc(string $vid): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
    }

    /** A complete, clean candidate ready to publish. */
    private function fb(string $vid = 'CAND_1', bool $clean = true): FakePublishFirestore
    {
        $fb = new FakePublishFirestore();
        $fb->versions[$this->vDoc($vid)] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => $vid,
            'status' => Timetable_plan_service::STATUS_CANDIDATE,
            'effectiveFrom' => '2026-01-01', 'effectiveTo' => '',
            'generation' => ['runId' => 'GEN_1',
                             'state' => Timetable_plan_service::GEN_COMPLETE,
                             'daysWritten' => 2],
        ];
        // Two sections on Monday. When $clean is false they share a teacher in
        // the same slot, which is a HARD violation.
        $t2 = $clean ? 'STA2' : 'STA1';
        foreach ([['Class 8th/A', 'STA1'], ['Class 9th/A', $t2]] as [$sec, $tid]) {
            $safe = str_replace('/', '_', $sec);
            $fb->days[$this->vDoc($vid) . "_{$safe}_Monday"] = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'planId' => self::PLAN, 'versionId' => $vid,
                'classSection' => $sec, 'day' => 'Monday',
                'periods' => [[
                    'periodNumber' => 1, 'subject' => 'Mathematics',
                    'teacher' => 'T', 'teacherId' => $tid,
                    'startTime' => '09:00AM', 'endTime' => '09:45AM',
                    'room' => '', 'type' => 'class',
                ]],
                'manuallyEdited' => false,
            ];
        }
        return $fb;
    }

    private function publishReady(FakePublishFirestore $fb, string $vid = 'CAND_1'): Timetable_plan_store
    {
        $store = $this->store($fb);
        $store->validateCandidate(self::PLAN, $vid);
        return $store;
    }

    // ── the happy path ───────────────────────────────────────────────────

    public function test_a_validated_complete_candidate_publishes(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        $res = $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $v = $fb->versions[$this->vDoc('CAND_1')];
        $this->assertSame(Timetable_plan_service::STATUS_PUBLISHED, $v['status']);
        $this->assertSame('2026-06-01', $v['effectiveFrom']);
        $this->assertSame('STA0001', $v['publishedBy']);
        $this->assertSame(2, $res['dayCount']);
        $this->assertSame([], $res['overlaps']);
    }

    public function test_a_published_version_records_what_was_published(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $v = $fb->versions[$this->vDoc('CAND_1')];
        $this->assertNotSame('', $v['publishedFingerprint'],
            'the published record must stay reconstructable as what was published');
        $this->assertSame($v['validation']['fingerprint'], $v['publishedFingerprint']);
        $this->assertSame(2, $v['publishedDayCount']);
    }

    public function test_publishing_makes_it_resolvable_on_its_dates(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $r = $store->resolveForDate('2026-06-15');
        $this->assertSame(Timetable_plan_service::RESOLVED, $r['status']);
        $this->assertSame('CAND_1', $r['version']['versionId']);

        $before = $store->resolveForDate('2026-05-31');
        $this->assertSame(Timetable_plan_service::NONE, $before['status'],
            'a timetable must not apply before the date it takes effect');
    }

    // ── immutability: the whole point ───────────────────────────────────

    public function test_a_published_version_cannot_be_edited(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~cannot be edited directly~i');
        $store->saveCandidateDay(self::PLAN, 'CAND_1', 'Class 8th/A', 'Tuesday', []);
    }

    public function test_a_published_version_cannot_be_generated_into(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~already been published~i');
        $store->beginGenerationRun(self::PLAN, 'CAND_1');
    }

    public function test_publishing_twice_is_refused_not_silently_repeated(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        try {
            $store->publishCandidate(self::PLAN, 'CAND_1', '2026-09-01');
            $this->fail('re-publishing must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~already published~i', $e->getMessage());
        }

        $this->assertSame('2026-06-01',
            $fb->versions[$this->vDoc('CAND_1')]['effectiveFrom'],
            'a refused re-publish must not silently re-date a live timetable');
    }

    public function test_a_correction_is_made_as_a_new_version(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $before = $fb->versions[$this->vDoc('CAND_1')];
        $chg    = $store->createChangeVersion(self::PLAN, 'CAND_1');

        $this->assertSame($before, $fb->versions[$this->vDoc('CAND_1')],
            'the published version must be byte-identical after a change version '
            . 'is taken from it');
        $this->assertSame(Timetable_plan_service::STATUS_DRAFT,
            $fb->versions[$this->vDoc($chg['versionId'])]['status']);
        $this->assertSame(2, $chg['days_copied']);
    }

    // ── the gates ────────────────────────────────────────────────────────

    public function test_an_unvalidated_candidate_cannot_be_published(): void
    {
        $fb = $this->fb();
        try {
            $this->store($fb)->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
            $this->fail('an unchecked timetable must not go live');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~not been checked~i', $e->getMessage());
        }
        $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
            $fb->versions[$this->vDoc('CAND_1')]['status'],
            'a refused publish must leave the version exactly as it was');
    }

    public function test_a_candidate_with_hard_violations_cannot_be_published(): void
    {
        $fb    = $this->fb('CAND_1', false);   // one teacher, two sections, one slot
        $store = $this->publishReady($fb);

        try {
            $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
            $this->fail('a timetable with an impossible arrangement must not go live');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~must be fixed~i', $e->getMessage());
            $this->assertMatchesRegularExpression('~STA1~', $e->getMessage(),
                'the refusal must say what is wrong, not just that something is');
        }
        $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
            $fb->versions[$this->vDoc('CAND_1')]['status']);
    }

    public function test_soft_violations_do_not_block_publishing(): void
    {
        $fb = $this->fb();
        // Add an empty period: a gap, which is soft.
        $k = $this->vDoc('CAND_1') . '_Class 8th_A_Monday';
        $fb->days[$k]['periods'][] = ['periodNumber' => 2, 'subject' => '',
            'teacher' => '', 'teacherId' => '', 'startTime' => '', 'endTime' => '',
            'room' => '', 'type' => 'class'];

        $store = $this->publishReady($fb);
        $res   = $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $this->assertGreaterThan(0, $res['softCount'],
            'premise: this candidate has a soft violation');
        $this->assertSame(Timetable_plan_service::STATUS_PUBLISHED,
            $fb->versions[$this->vDoc('CAND_1')]['status'],
            'soft violations are the school\'s preferences and must not block');
    }

    public function test_an_edited_candidate_must_be_rechecked_before_publishing(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        // A manual edit after validation.
        $store->saveCandidateDay(self::PLAN, 'CAND_1', 'Class 8th/A', 'Tuesday', [[
            'periodNumber' => 1, 'subject' => 'Science', 'teacher' => 'T',
            'teacherId' => 'STA3', 'startTime' => '', 'endTime' => '',
            'room' => '', 'type' => 'class',
        ]]);

        try {
            $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
            $this->fail('a verdict must not outlive the arrangement it describes');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~changed since|fresh check~i', $e->getMessage());
        }
    }

    /**
     * The stale FLAG is best-effort — invalidateVerdict() logs and continues on
     * failure, because it runs after the day is already saved. So the gate must
     * not depend on it. The fingerprint is what actually protects publishing.
     */
    public function test_a_lost_stale_flag_does_not_let_edited_content_publish(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        // Edit the content directly and pretend the stale marking never landed.
        $k = $this->vDoc('CAND_1') . '_Class 8th_A_Monday';
        $fb->days[$k]['periods'][0]['subject'] = 'Physics';
        $fb->versions[$this->vDoc('CAND_1')]['validationStale'] = false;

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~changed since~i');
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
    }

    public function test_an_incomplete_candidate_cannot_be_published(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('CAND_1')]['generation']['state'] =
            Timetable_plan_service::GEN_FAILED;

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~not generated completely~i');
        $this->store($fb)->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
    }

    public function test_a_bad_effective_date_is_refused_before_anything_is_read(): void
    {
        foreach (['', 'tomorrow', '2026-13-01', '01-06-2026'] as $bad) {
            $fb = $this->fb();
            try {
                $this->store($fb)->publishCandidate(self::PLAN, 'CAND_1', $bad);
                $this->fail("'{$bad}' must not be accepted as an effective date");
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~effectiveFrom~i', $e->getMessage());
            }
            $this->assertSame([], $fb->writes,
                'an invalid date must be caught before anything is written');
        }
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $fb = $this->fb();
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~effectiveTo is before~i');
        $this->store($fb)->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01', '2026-05-01');
    }

    public function test_a_missing_version_is_refused(): void
    {
        $fb = new FakePublishFirestore();
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~does not exist~i');
        $this->store($fb)->publishCandidate(self::PLAN, 'NOPE', '2026-06-01');
    }

    public function test_a_refused_write_is_not_reported_as_published(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $fb->refuseVersionWrite = true;

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~could not be published~i');
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
    }

    // ── ONE TIMETABLE IN FORCE — refused, not reported ───────────────────
    //
    // TT-C8, owner-authorised 2026-08-31. These four tests asserted the older
    // convention ("overlaps are detected and reported, never forbidden"), and
    // they had to change WITH the rule. That convention still holds for drafts
    // and candidates; it cannot hold for two PUBLISHED versions, because an
    // overlap between those has no valid meaning anywhere in the system — the
    // resolver refuses to choose, the projection can hold only one timetable
    // per section per day, the cron then refuses too, and there is no unpublish
    // to get back out. Reporting that state is not a warning, it is a trap.

    public function test_an_overlapping_publish_is_refused(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('V_OLD')] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V_OLD',
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];
        $store = $this->publishReady($fb);

        try {
            $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
            $this->fail('publishing a second timetable over live dates must be refused');
        } catch (Service_exception $e) {
            $this->assertSame('overlap', $e->errorType);
            $this->assertMatchesRegularExpression('~only one timetable in force~i',
                $e->getMessage(), 'the refusal must say WHY, in the operator\x27s terms');
            $this->assertMatchesRegularExpression('~V_OLD~', $e->getMessage(),
                'and name what is already there, or it cannot be acted on');
        }

        $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
            $fb->versions[$this->vDoc('CAND_1')]['status'],
            'a refused publish leaves the candidate exactly as it was');
    }

    public function test_the_previous_published_version_is_not_re_dated(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('V_OLD')] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V_OLD',
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => '',
        ];
        $before = $fb->versions[$this->vDoc('V_OLD')];

        $store = $this->publishReady($fb);

        // The publish is now REFUSED (TT-C8) rather than reported. The property
        // under test survives the change and matters more because of it: the
        // system does NOT get itself out of the overlap by quietly ending the
        // previous timetable early. Auto-superseding was considered and
        // rejected — it mutates published dating, and no decision authorises
        // that. So the refusal must leave the predecessor untouched.
        try { $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01'); }
        catch (Service_exception $e) { /* expected — asserted in its own test */ }

        $this->assertSame($before, $fb->versions[$this->vDoc('V_OLD')],
            'auto-closing the previous version would be a mutation of published '
            . 'data, and no decision authorises it');
    }

    /**
     * The resolver still refuses to guess — and now it is a SAFETY NET rather
     * than a routine outcome.
     *
     * Publishing can no longer create this state (TT-C8), so it is seeded
     * directly: data that predates the rule, or that arrived some other way,
     * must still resolve to AMBIGUOUS and never to a silent winner. Removing
     * this test along with the publish path that used to produce the state
     * would have left the last line of defence untested.
     */
    public function test_a_pre_existing_overlap_still_resolves_as_ambiguous(): void
    {
        $fb = $this->fb();
        foreach ([['V_OLD', '2026-04-01'], ['V_OTHER', '2026-06-01']] as [$vid, $from]) {
            $fb->versions[$this->vDoc($vid)] = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'planId' => self::PLAN, 'versionId' => $vid,
                'status' => Timetable_plan_service::STATUS_PUBLISHED,
                'effectiveFrom' => $from, 'effectiveTo' => '',
            ];
        }
        $store = $this->publishReady($fb);

        $r = $store->resolveForDate('2026-07-01');
        $this->assertSame(Timetable_plan_service::AMBIGUOUS, $r['status'],
            'two published timetables claiming one date must never be silently '
            . 'resolved in favour of one of them');
        $this->assertNotSame('', $r['explain']);
    }

    public function test_a_non_overlapping_publish_reports_no_overlap(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('V_OLD')] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V_OLD',
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => '2026-01-01', 'effectiveTo' => '2026-05-31',
        ];
        $store = $this->publishReady($fb);
        $res   = $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $this->assertSame([], $res['overlaps'],
            'a version that ends the day before must not be reported as overlapping');
    }

    public function test_a_draft_is_not_counted_as_an_overlap(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('V_DRAFT')] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V_DRAFT',
            'status' => Timetable_plan_service::STATUS_DRAFT,
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
        ];
        $store = $this->publishReady($fb);
        $res   = $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');

        $this->assertSame([], $res['overlaps'],
            'only published versions compete for a date');
    }

    public function test_publishing_refuses_when_the_version_list_is_unreadable(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);
        $fb->versionQueryUnreadable = true;

        try {
            $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
            $this->fail('publishing blind to who else claims the dates is a guess');
        } catch (Service_exception $e) {
            $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
                $fb->versions[$this->vDoc('CAND_1')]['status'],
                'an unreadable overlap check must not publish anyway');
        }
    }

    // ── tenant and session isolation ────────────────────────────────────

    public function test_another_tenants_version_cannot_be_published(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('CAND_1')]['schoolId'] = 'SCH_OTHER';

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~does not exist~i');
        $this->store($fb)->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
    }

    public function test_another_sessions_version_cannot_be_published(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('CAND_1')]['session'] = '2025-2026';

        $this->expectException(Service_exception::class);
        $this->store($fb)->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
    }

    // ── validation persistence ──────────────────────────────────────────

    public function test_validation_records_a_trustworthy_verdict(): void
    {
        $fb    = $this->fb();
        $store = $this->store($fb);
        $store->validateCandidate(self::PLAN, 'CAND_1');

        $v = $fb->versions[$this->vDoc('CAND_1')];
        $this->assertTrue($v['validation']['publishable']);
        $this->assertFalse($v['validationStale']);
        $this->assertSame('STA0001', $v['validation']['validatedBy']);

        $cur = $store->currentVerdict(self::PLAN, 'CAND_1');
        $this->assertTrue($cur['trusted']);
    }

    public function test_an_incomplete_candidate_cannot_be_validated(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('CAND_1')]['generation']['state'] =
            Timetable_plan_service::GEN_RUNNING;

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~not generated completely~i');
        $this->store($fb)->validateCandidate(self::PLAN, 'CAND_1');
    }

    public function test_a_manual_edit_marks_the_verdict_stale(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        $store->saveCandidateDay(self::PLAN, 'CAND_1', 'Class 8th/A', 'Tuesday', []);

        $cur = $store->currentVerdict(self::PLAN, 'CAND_1');
        $this->assertFalse($cur['trusted']);
        $this->assertNotSame('', $cur['reason'],
            'an untrusted verdict must say why, or the operator cannot act on it');
    }

    /**
     * The stale FLAG and the fingerprint are two independent signals, and the
     * flag must matter on its own. Re-saving a day with IDENTICAL content marks
     * the verdict stale without changing the fingerprint, so this is the only
     * case where the flag is the thing protecting publishing.
     */
    public function test_the_stale_flag_alone_blocks_publishing(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        $before = $fb->versions[$this->vDoc('CAND_1')]['validation']['fingerprint'];

        // Same content, saved again as a manual edit.
        $store->saveCandidateDay(self::PLAN, 'CAND_1', 'Class 8th/A', 'Monday', [[
            'periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'T',
            'teacherId' => 'STA1', 'startTime' => '09:00AM', 'endTime' => '09:45AM',
            'room' => '', 'type' => 'class',
        ]]);

        $cur = $store->currentVerdict(self::PLAN, 'CAND_1');
        $this->assertSame($before, $cur['verdict']['fingerprint'],
            'premise: the content is unchanged, so the fingerprint still matches');
        $this->assertFalse($cur['trusted'],
            'the stale flag must be believed on its own; the fingerprint cannot '
            . 'catch an edit that rewrote a day with the same content');

        // The refusal must now say WHY the check went stale, not merely that it
        // did — an administrator cannot act on "needs a fresh check" alone.
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~edited by hand~i');
        $store->publishCandidate(self::PLAN, 'CAND_1', '2026-06-01');
    }

    public function test_a_refused_verdict_write_is_not_reported_as_validated(): void
    {
        $fb = $this->fb();
        $fb->refuseVersionWrite = true;

        try {
            $this->store($fb)->validateCandidate(self::PLAN, 'CAND_1');
            $this->fail('a verdict that was not stored must not be reported as stored');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be saved~i', $e->getMessage());
        }

        $this->assertArrayNotHasKey('validation', $fb->versions[$this->vDoc('CAND_1')],
            'nothing may look validated when the verdict write was refused');
    }

    public function test_an_unvalidated_version_reports_absent_not_clean(): void
    {
        $fb  = $this->fb();
        $cur = $this->store($fb)->currentVerdict(self::PLAN, 'CAND_1');

        $this->assertFalse($cur['present']);
        $this->assertFalse($cur['trusted'],
            'never checked must never read as checked and fine');
    }

    // ── the live collection stays untouched ─────────────────────────────

    /**
     * Publishing a timetable that is in force NOW must reach teachers by itself.
     *
     * This is the clean-onboarding test: a school that joined this morning
     * publishes a timetable and teachers see it. Requiring somebody to have
     * scheduled a cron first would mean the school publishes and nothing
     * happens, with no indication why.
     */
    public function test_publishing_something_in_force_now_reaches_teachers(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        $res = $store->publishCandidate(self::PLAN, 'CAND_1', '2020-01-01');

        $this->assertTrue($res['projection']['attempted']);
        $this->assertTrue($res['projection']['applied'],
            'a timetable that applies today must be visible to teachers today');
        $this->assertSame(2, $res['projection']['written']);
        $this->assertNotEmpty($fb->legacy,
            'the compatibility projection is what teachers actually read');
    }

    /**
     * A future-dated timetable must NOT be materialised early — that is exactly
     * what the resolver's effective dating is for, and what the cron exists to
     * do on the day it becomes effective.
     */
    public function test_publishing_something_future_dated_does_not_reach_teachers_yet(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        $res = $store->publishCandidate(self::PLAN, 'CAND_1', '2099-01-01');

        $this->assertFalse($res['projection']['attempted']);
        $this->assertMatchesRegularExpression('~starts later~i', $res['projection']['reason']);
        $this->assertSame([], $fb->legacy,
            'a timetable that starts next term must not replace the one teachers '
            . 'are using today');
    }

    /**
     * A timetable whose period has already ENDED is not in force either, and
     * must not replace what teachers are currently using. Publishing one is a
     * legitimate act — backfilling last term's timetable for the record — but it
     * is history, not today.
     */
    public function test_publishing_something_already_expired_does_not_reach_teachers(): void
    {
        $fb    = $this->fb();
        $store = $this->publishReady($fb);

        $res = $store->publishCandidate(self::PLAN, 'CAND_1', '2020-01-01', '2020-06-30');

        $this->assertFalse($res['projection']['attempted'],
            'a timetable that stopped applying years ago must not overwrite the '
            . 'one teachers are using today');
        $this->assertSame([], $fb->legacy);
        $this->assertSame(Timetable_plan_service::STATUS_PUBLISHED,
            $fb->versions[$this->vDoc('CAND_1')]['status'],
            'it is still genuinely published — it simply does not apply now');
    }

    /**
     * An overlapping publish reaches the projection at all — because it never
     * reaches the publish.
     *
     * This test used to assert that an overlap SUPPRESSED the projection: the
     * publish went through, and the projection was skipped so as not to resolve
     * an ambiguous date by writing whichever version was saved last. That
     * branch is gone, because the state it protected against is now refused one
     * step earlier (TT-C8). The property still worth pinning is the one that
     * matters to teachers: nothing was written to the collection they read.
     */
    public function test_a_refused_overlapping_publish_writes_nothing_teachers_can_see(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc('V_OLD')] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V_OLD',
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => '2020-01-01', 'effectiveTo' => '',
        ];
        $store = $this->publishReady($fb);

        try {
            $store->publishCandidate(self::PLAN, 'CAND_1', '2020-06-01');
            $this->fail('expected the overlapping publish to be refused');
        } catch (Service_exception $e) {
            $this->assertSame('overlap', $e->errorType);
        }

        $this->assertSame([], $fb->legacy,
            'a refused publish must not reach the collection the apps read');
        $this->assertSame([], $fb->guards,
            'and must not move the publish token either — nothing happened');
    }

    /**
     * Publishing is the canonical act; the projection is a downstream copy. A
     * failed copy must not undo a published timetable — but it must be reported,
     * because otherwise teachers silently never see it.
     */
    public function test_a_failed_projection_does_not_undo_the_publish(): void
    {
        $fb = new class extends FakePublishFirestore {
            public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
            {
                if ($collection === 'timetables') { throw new \RuntimeException('index missing'); }
                return parent::firestoreQueryOrFail($collection, $conditions, $orderBy, $dir, $limit);
            }
        };
        foreach ($this->fb()->versions as $k => $v) { $fb->versions[$k] = $v; }
        foreach ($this->fb()->days as $k => $v) { $fb->days[$k] = $v; }

        $store = $this->publishReady($fb);
        $res   = $store->publishCandidate(self::PLAN, 'CAND_1', '2020-01-01');

        $this->assertSame(Timetable_plan_service::STATUS_PUBLISHED,
            $fb->versions[$this->vDoc('CAND_1')]['status'],
            'the canonical record stands even when the downstream copy fails');
        $this->assertNotNull($res['projection']['error'],
            'a silent failure here means teachers never see the timetable and '
            . 'nobody is told');
    }
}
