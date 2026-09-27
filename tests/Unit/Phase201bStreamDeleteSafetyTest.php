<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20.1b — P20-F8: stream-delete safety.
 *
 * WHAT PHASE 20.1 PREDICTED: another single-shape `status` predicate, i.e.
 * `['streamKey','==',$k], ['status','==','Active']` missing PascalCase
 * `Status` students.
 *
 * WHAT SOURCE TRACING ACTUALLY FOUND: worse, and different in kind. **No code
 * anywhere writes `streamKey` — or any stream field — onto a student
 * document.** The live probe confirmed 0 of 23 students carry any of
 * streamKey / stream / Stream / streamName / StreamKey / stream_key, and 0 of
 * 35 sections carry `streamKey` either.
 *
 * So the predicate ALWAYS returned 0. It was INERT, not shape-sensitive.
 * Correcting status → Status would not have changed its result by one row.
 * The tests below assert that inertness directly, because a fix aimed only at
 * the status shape would have looked correct and protected nothing.
 *
 * CANONICAL MEMBERSHIP (the only link the data model has): a student belongs
 * to stream K when their section is a STREAM-NAMED section — "Section Science
 * A" / sectionCode "Science A" — the form bulk_save_sections() already accepts
 * and normalizeClassSection() preserves.
 *
 * IDENTITY DISCIPLINE (SRNO sweep): STU0012 → SRNO 47 → Roll 12 ·
 * STU0047 → SRNO 12 → Roll 47.
 */
class Phase201bStreamDeleteSafetyTest extends TestCase
{
    private const STREAM  = 'Science';
    private const SESSION = '2026-27';

    // ── mirrors of the implementation ────────────────────────────────────

    /** Mirrors School_config::_sectionBelongsToStream(). */
    private function belongs(string $sectionToken, string $streamKey): bool
    {
        $t = trim(preg_replace('/^Section\s+/i', '', trim($sectionToken)));
        if ($t === '' || $streamKey === '') return false;
        return (bool) preg_match('/^' . preg_quote($streamKey, '/') . '\s+[A-Za-z]$/i', $t);
    }

    /** The OLD guard — kept so its inertness is asserted, not assumed. */
    private function vulnerableGuard(array $docs, string $streamKey): int
    {
        $n = 0;
        foreach ($docs as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : [];
            if (($d['streamKey'] ?? null) === $streamKey && ($d['status'] ?? null) === 'Active') $n++;
        }
        return $n;
    }

    /**
     * Mirrors School_config::_stream_active_students().
     * $snapshotThrows / $probeThrows / $probeFindsStudents simulate the
     * snapshot-health discriminator.
     */
    private function decide(
        array $snapshot,
        string $streamKey = self::STREAM,
        string $session = self::SESSION,
        bool $snapshotThrows = false,
        bool $probeThrows = false,
        bool $probeFindsStudents = false
    ): array {
        if (trim($streamKey) === '') return ['state' => 'UNDETERMINED', 'count' => 0];
        if ($snapshotThrows)        return ['state' => 'UNDETERMINED', 'count' => 0];

        if (empty($snapshot)) {
            if ($probeThrows)        return ['state' => 'UNDETERMINED', 'count' => 0];
            if ($probeFindsStudents) return ['state' => 'UNDETERMINED', 'count' => 0];
            return ['state' => 'EMPTY', 'count' => 0];
        }

        $sessionCmp = mb_strtolower(trim($session));
        $count = 0;
        foreach ($snapshot as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : null;
            if (!is_array($d)) continue;
            $sid = (string) ($d['studentId'] ?? $d['userId'] ?? $d['User Id'] ?? '');
            if ($sid === '') continue;
            $status = trim((string) ($d['status'] ?? $d['Status'] ?? 'Active'));
            if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;
            $rowSession = mb_strtolower(trim((string) ($d['session'] ?? '')));
            if ($sessionCmp !== '' && $rowSession !== '' && $rowSession !== $sessionCmp) continue;
            if ($this->belongs((string) ($d['section'] ?? $d['Section'] ?? ''), $streamKey)) $count++;
        }
        return ['state' => $count > 0 ? 'NON_EMPTY' : 'EMPTY', 'count' => $count];
    }

    private function isDeletable(array $r): bool { return $r['state'] === 'EMPTY'; }

    /** A student in stream-named section "Science A", WITHOUT any stream field. */
    private function stu(array $o = []): array
    {
        return ['data' => array_merge([
            'studentId' => 'STU0012',
            'className' => 'Class 11th',
            'section'   => 'Section Science A',
            'session'   => self::SESSION,
            'status'    => 'Active',
            'srNo'      => '47',
            'rollNo'    => '12',
        ], $o)];
    }

    private function src(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(
            __DIR__ . '/../../application/controllers/School_config.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    // ── T1 ───────────────────────────────────────────────────────────────

    public function test_T1_empty_stream_is_deletable(): void
    {
        $other = $this->stu(['section' => 'Section A']);   // no stream
        $r = $this->decide([$other]);
        $this->assertSame('EMPTY', $r['state']);
        $this->assertTrue($this->isDeletable($r));
    }

    // ── T2 / T3 / T4 — and the inertness proof ───────────────────────────

    public function test_T2_camelcase_active_stream_member_blocks_delete(): void
    {
        $rows = [$this->stu()];
        $r = $this->decide($rows);
        $this->assertSame('NON_EMPTY', $r['state']);
        $this->assertFalse($this->isDeletable($r));
        $this->assertSame(1, $r['count']);
    }

    public function test_T2b_old_guard_was_INERT_even_for_a_perfect_camelcase_student(): void
    {
        // The decisive difference from Phase 20.1: this student is fully
        // canonical — camelCase `status` = 'Active' — and the old predicate
        // STILL saw nothing, because no `streamKey` is ever written.
        $rows = [$this->stu()];
        $this->assertSame(0, $this->vulnerableGuard($rows, self::STREAM),
            'The old guard returns 0 even for a canonical Active student: it was '
            . 'inert, not shape-sensitive. A status-only fix would protect nothing.');
        $this->assertFalse($this->isDeletable($this->decide($rows)));
    }

    public function test_T3_pascalcase_status_stream_member_blocks_delete(): void
    {
        $legacy = $this->stu(['Status' => 'Active']);
        unset($legacy['data']['status']);
        $this->assertSame(0, $this->vulnerableGuard([$legacy], self::STREAM));
        $r = $this->decide([$legacy]);
        $this->assertSame('NON_EMPTY', $r['state']);
        $this->assertSame(1, $r['count']);
    }

    public function test_T4_mixed_status_shapes_all_block_delete(): void
    {
        $legacy = $this->stu(['studentId' => 'STU0047', 'srNo' => '12', 'rollNo' => '47', 'Status' => 'Active']);
        unset($legacy['data']['status']);
        $r = $this->decide([$this->stu(), $legacy]);
        $this->assertSame('NON_EMPTY', $r['state']);
        $this->assertSame(2, $r['count']);
    }

    public function test_T4b_absent_status_entirely_counts_as_active(): void
    {
        $noStatus = $this->stu();
        unset($noStatus['data']['status']);
        $r = $this->decide([$noStatus]);
        $this->assertSame('NON_EMPTY', $r['state'],
            'Absent status means Active, matching MY_Controller:1280.');
    }

    // ── T5 / T6 — membership shapes ──────────────────────────────────────

    public function test_T5_bare_sectionCode_form_is_recognised(): void
    {
        $this->assertTrue($this->belongs('Science A', 'Science'), 'bare sectionCode form');
        $this->assertTrue($this->belongs('Section Science A', 'Science'), 'prefixed form');
        $this->assertTrue($this->belongs('section science a', 'Science'), 'case-insensitive');
    }

    public function test_T6_membership_holds_with_no_stream_field_present(): void
    {
        $s = $this->stu();
        foreach (['streamKey', 'stream', 'Stream', 'streamName'] as $f) {
            $this->assertArrayNotHasKey($f, $s['data'],
                'Fixture must carry no stream field — that is the real data shape.');
        }
        $this->assertSame(1, $this->decide([$s])['count'],
            'Membership must be derivable without any student-level stream field.');
    }

    public function test_T6b_other_streams_and_plain_sections_do_not_match(): void
    {
        $this->assertFalse($this->belongs('Section Commerce A', 'Science'));
        $this->assertFalse($this->belongs('Section A', 'Science'));
        $this->assertFalse($this->belongs('', 'Science'));
        $this->assertFalse($this->belongs('Section Science', 'Science'), 'needs a section letter');
        $this->assertFalse($this->belongs('Section Sciences A', 'Science'), 'no prefix bleed');
    }

    // ── T7 ───────────────────────────────────────────────────────────────

    public function test_T7_withdrawn_only_leaves_stream_deletable(): void
    {
        $rows = [
            $this->stu(['status' => 'Withdrawn']),
            $this->stu(['studentId' => 'STU0047', 'status' => 'Inactive']),
        ];
        $this->assertSame('EMPTY', $this->decide($rows)['state']);
    }

    // ── T8 / T9 — session semantics ──────────────────────────────────────

    public function test_T8_other_session_student_does_not_block(): void
    {
        $r = $this->decide([$this->stu(['session' => '2025-26'])]);
        $this->assertSame('EMPTY', $r['state'],
            'Stream definitions are school-level, but the students blocking a delete '
            . 'must belong to the session being operated on.');
    }

    public function test_T9_blank_session_legacy_student_still_blocks(): void
    {
        $r = $this->decide([$this->stu(['session' => ''])]);
        $this->assertSame('NON_EMPTY', $r['state'],
            'Blank session is a legacy document and is kept — same rule as '
            . 'MY_Controller:1288. This is the existing canonical treatment, not a new policy.');
    }

    // ── T10 / T11 / T12 — fail closed ────────────────────────────────────

    public function test_T10_snapshot_exception_is_UNDETERMINED(): void
    {
        $r = $this->decide([$this->stu()], self::STREAM, self::SESSION, true);
        $this->assertSame('UNDETERMINED', $r['state']);
        $this->assertFalse($this->isDeletable($r));
    }

    public function test_T10b_blank_stream_key_is_UNDETERMINED(): void
    {
        $this->assertSame('UNDETERMINED', $this->decide([$this->stu()], '')['state']);
    }

    public function test_T11_malformed_rows_do_not_fatal_and_do_not_authorise_delete(): void
    {
        $rows = [null, 'garbage', ['data' => null], ['data' => ['studentId' => '']], $this->stu()];
        $r = $this->decide($rows);
        $this->assertSame('NON_EMPTY', $r['state']);
        $this->assertSame(1, $r['count']);
    }

    public function test_T12_empty_snapshot_while_students_exist_is_UNDETERMINED(): void
    {
        // _cachedStudentDocsForRollCheck() returns [] on error, which is
        // indistinguishable from "no students". The bounded existence probe
        // separates them; disagreement fails closed.
        $r = $this->decide([], self::STREAM, self::SESSION, false, false, true);
        $this->assertSame('UNDETERMINED', $r['state'],
            'A silently-empty snapshot must never authorise deletion.');
    }

    public function test_T12b_genuinely_empty_school_is_EMPTY_not_undetermined(): void
    {
        $r = $this->decide([], self::STREAM, self::SESSION, false, false, false);
        $this->assertSame('EMPTY', $r['state'],
            'A school with no students at all must remain able to delete an unused stream.');
    }

    public function test_T12c_probe_exception_is_UNDETERMINED(): void
    {
        $this->assertSame('UNDETERMINED',
            $this->decide([], self::STREAM, self::SESSION, false, true, false)['state']);
    }

    // ── T13 — one rule across all stream-delete paths ────────────────────

    public function test_T13_delete_stream_uses_the_canonical_helpers(): void
    {
        $src = $this->src();

        $this->assertSame(1, substr_count($src, '$this->_stream_active_students('),
            'delete_stream() must resolve student membership through the canonical helper.');
        $this->assertSame(1, substr_count($src, '$this->_stream_live_sections('),
            'delete_stream() must resolve section membership through the canonical helper.');
        $this->assertSame(1, substr_count($src, 'private function _stream_active_students('),
            'Exactly ONE definition of stream student membership.');
        $this->assertSame(1, substr_count($src, 'private static function _sectionBelongsToStream('),
            'Exactly ONE definition of section-to-stream membership.');

        // Both helpers must share the one membership primitive.
        $this->assertSame(2, substr_count($src, 'self::_sectionBelongsToStream('),
            'Student and section checks must use the SAME membership rule.');
    }

    public function test_T13b_no_streamKey_student_predicate_survives(): void
    {
        $this->assertSame(0,
            preg_match_all("/\\['streamKey',\\s*'==',[^\\]]*\\],\\s*\\['status',\\s*'==',\\s*'Active'\\]/",
                $this->src()),
            'The inert streamKey+status predicate must be gone entirely.');
    }

    public function test_T13c_delete_stream_is_the_only_removal_path(): void
    {
        $src = $this->src();
        // save_stream/save_streams are documented upserts; assert they contain
        // no stream removal so this test fails if that ever changes.
        $this->assertSame(1, substr_count($src, 'function delete_stream('),
            'Only one stream deletion endpoint may exist.');
        $this->assertStringNotContainsString('unset($allStreams[$streamKey]); //bulk', $src);
    }

    // ── T14 / T15 / T16 — identity, SRNO, and scope ──────────────────────

    public function test_T14_stream_delete_decision_mutates_no_student(): void
    {
        $rows = [$this->stu(), $this->stu(['studentId' => 'STU0047', 'srNo' => '12', 'rollNo' => '47'])];
        $before = json_encode($rows);
        $this->decide($rows);
        $this->assertSame($before, json_encode($rows), 'The decision is read-only.');
    }

    public function test_T15_srno_and_roll_untouched_and_unreferenced(): void
    {
        $rows = [$this->stu(), $this->stu(['studentId' => 'STU0047', 'srNo' => '12', 'rollNo' => '47'])];
        $this->decide($rows);
        $this->assertSame('47', $rows[0]['data']['srNo'],   'STU0012 keeps SRNO 47.');
        $this->assertSame('12', $rows[0]['data']['rollNo'], 'STU0012 keeps Roll 12.');
        $this->assertSame('12', $rows[1]['data']['srNo'],   'STU0047 keeps SRNO 12.');
        $this->assertSame('47', $rows[1]['data']['rollNo'], 'STU0047 keeps Roll 47.');

        $body = $this->helperBody();
        $this->assertSame(0, preg_match_all('/srNo|sr_no|admissionNo|rollNo/i', $body),
            'Stream membership must never reference student identity fields.');
    }

    public function test_T16_no_capacity_or_roll_logic_in_the_stream_helper(): void
    {
        $body = $this->helperBody();
        foreach (['maxStrength', 'max_strength', '_sectionCapacity', 'rollNo'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                "Stream-delete safety must not touch capacity or roll logic ({$needle}).");
        }
        $this->assertStringNotContainsString('->update(', $body, 'Helper must not write.');
        $this->assertStringNotContainsString('->remove(', $body, 'Helper must not delete.');
    }

    // ── cap saturation must fail closed, not read as empty ───────────────

    public function test_T11b_saturated_sections_scan_is_UNDETERMINED(): void
    {
        // Mirrors _stream_live_sections()'s cap guard: a truncated scan that
        // finds nothing is not proof of emptiness.
        $cap = 500;
        $scan = function (array $rows) use ($cap) {
            if (count($rows) >= $cap) return ['state' => 'UNDETERMINED', 'count' => 0];
            $n = 0;
            foreach ($rows as $r) if ($this->belongs((string) ($r['section'] ?? ''), self::STREAM)) $n++;
            return ['state' => $n > 0 ? 'NON_EMPTY' : 'EMPTY', 'count' => $n];
        };

        $saturated = array_fill(0, $cap, ['section' => 'Section A']);
        $this->assertSame('UNDETERMINED', $scan($saturated)['state'],
            'A saturated scan must not authorise deletion.');

        $under = array_fill(0, 10, ['section' => 'Section A']);
        $this->assertSame('EMPTY', $scan($under)['state'],
            'An unsaturated scan with no stream sections is genuinely empty.');
    }

    public function test_T11c_source_carries_the_saturation_guard(): void
    {
        $this->assertStringContainsString('sections_scan_saturated', $this->src(),
            'The cap-saturation fail-closed guard must be present in source.');
    }

    private function helperBody(): string
    {
        $src   = $this->src();
        $start = strpos($src, 'private function _stream_active_students(');
        $this->assertNotFalse($start, 'Canonical stream helper not found.');
        return substr($src, $start, 3000);
    }
}
