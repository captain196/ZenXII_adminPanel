<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20.3h — atomic section capacity: wiring state and safety locks.
 *
 * P20.2-RACE-1: every seat-consuming path checks capacity and then writes the
 * student with nothing in between, so two requests can both read 39/40 and both
 * write. The fix commits the student write(s) and a CAS marker on the
 * destination section in ONE Firestore batch, guarded by
 * `currentDocument.updateTime` — proven against live Firestore in Phase 20.3g
 * (two in-flight writers, winners=1, losers=1).
 *
 * ── WHAT THIS FILE ENFORCES ─────────────────────────────────────────────────
 * The mechanism is all-or-nothing across FIVE paths. Wiring some of them and
 * enabling the flag would leave bypasses while the code reads as protected —
 * the exact false-safety failure that caused Phase 20.3c to be reverted.
 *
 * These tests therefore MECHANICALLY PREVENT that: the flag cannot be set true
 * while any path is still unwired. `test_FLAG_cannot_be_enabled_until_all_five_paths_are_wired`
 * fails loudly the moment someone flips it early.
 *
 * As paths are wired, add their marker to WIRED_MARKERS below. When all five
 * are present the flag lock releases automatically.
 */
class Phase203hAtomicCapacityWiringTest extends TestCase
{
    private const SIS     = __DIR__ . '/../../application/controllers/Sis.php';
    private const CLASSES = __DIR__ . '/../../application/controllers/Classes.php';
    private const GUARD   = __DIR__ . '/../../application/libraries/Section_seat_guard.php';
    private const WRITER  = __DIR__ . '/../../application/libraries/Student_write_service.php';
    private const CONFIG  = __DIR__ . '/../../application/config/capacity_cas.php';

    /**
     * The five seat-consuming placement paths and the source signature that
     * proves each is routed through the capacity authority.
     */
    private const PATHS = [
        'admission' => [self::SIS,     "'save_admission'"],
        'enroll'    => [self::SIS,     "'enroll_student'"],
        'transfer'  => [self::CLASSES, 'placeStudentGroup('],
        // Markers chosen because they have ZERO pre-existing occurrences in the
        // source — verified — so they can only appear once the path is wired.
        // A looser marker like 'promotion' gave a false positive against an
        // unrelated string literal, which would have reported an unwired path
        // as protected: exactly the kind of false assurance this file exists
        // to prevent.
        //
        // Promotion is wired inside Student_write_service::batchMoveStudents(),
        // NOT in Sis.php — that method is the actual placement write for the
        // promotion path.
        'promotion' => [self::WRITER,  'placeStudentGroup('],
        // Marker is structurally tied to Import's own group-refusal log line —
        // it exists only inside the Pass 2 atomic-group implementation and has
        // zero pre-existing occurrences, so it cannot false-positive the way a
        // generic word like 'import' would.
        'import'    => [self::SIS,     'ACC_IMPORT_GROUP_REFUSED'],
    ];

    private function src(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    /**
     * Comment-stripped body of ONE method, brace-matched.
     *
     * Positional assertions must be scoped to the method they are about. A
     * character-window around a string literal silently changes meaning the
     * moment code is inserted nearby — which is exactly how the enroll
     * assertion below broke while the code it guards stayed correct.
     */
    private function methodBody(string $path, string $method): string
    {
        $src = $this->src($path);
        $at  = strpos($src, "function {$method}(");
        $this->assertNotFalse($at, "{$method}() not found in " . basename($path));
        $open  = strpos($src, '{', $at);
        $depth = 0; $i = $open; $len = strlen($src);
        while ($i < $len) {
            if ($src[$i] === '{') { $depth++; }
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) break; }
            $i++;
        }
        return substr($src, $open, $i - $open);
    }

    /** Which paths currently route through the guard. */
    private function wiredPaths(): array
    {
        $wired = [];
        foreach (self::PATHS as $name => [$file, $needle]) {
            $src = $this->src($file);
            // A path counts as wired only if the guard is invoked in the same
            // file AND its own call-site marker is present.
            if (strpos($src, 'Section_seat_guard::enabled()') !== false
                && strpos($src, $needle) !== false) {
                $wired[] = $name;
            }
        }
        return $wired;
    }

    // ── THE LOCK ─────────────────────────────────────────────────────────

    public function test_FLAG_cannot_be_enabled_until_all_five_paths_are_wired(): void
    {
        $cfg = file_get_contents(self::CONFIG);
        preg_match("/'atomic_capacity_enabled'\]\s*=\s*(true|false)/i", $cfg, $m);
        $enabled = isset($m[1]) && strtolower($m[1]) === 'true';

        $wired   = $this->wiredPaths();
        $missing = array_values(array_diff(array_keys(self::PATHS), $wired));

        if ($enabled) {
            $this->assertSame([], $missing,
                "atomic_capacity_enabled is TRUE but these placement paths are NOT wired: "
                . implode(', ', $missing) . ". Enabling the flag now would leave real capacity "
                . "bypasses while the code reads as protected — the failure that caused Phase "
                . "20.3c to be reverted. Set the flag back to false until every path is wired."
            );
        } else {
            $this->assertFalse($enabled, 'Flag is false — safe regardless of wiring state.');
        }
    }

    /**
     * The APPROVED shipped state, and the contract that comes with it.
     *
     * ── HISTORY ─────────────────────────────────────────────────────────────
     * This test previously asserted the shipped default must be FALSE. That was
     * correct while enablement was unauthorised: it stopped anyone shipping a
     * half-wired feature. The operator authorised enablement on 2026-08-16
     * (Phases 20.3K–20.3M: five paths wired, CAS proven against live Firestore,
     * P20.2-RACE-1 closed), so "must be false" is obsolete by deliberate product
     * decision — NOT by a defect, and NOT by weakening a check.
     *
     * ── WHAT IT ASSERTS NOW ─────────────────────────────────────────────────
     * The shipped value is TRUE **and**, inseparably, all five placement paths
     * are wired. The two are asserted together on purpose: an enabled flag is
     * only safe as a pair with complete wiring, and flipping the value alone —
     * `false` → `true` with no coupling — is exactly the change this file exists
     * to make impossible.
     *
     * If a path is ever unwired while the flag is true, this fails and NAMES the
     * missing paths, alongside `test_FLAG_cannot_be_enabled_until_all_five_paths_are_wired`,
     * which is untouched and still guards the same invariant from the other
     * direction. Two independent tests, one invariant.
     */
    public function test_approved_shipped_state_is_enabled_AND_all_five_paths_wired(): void
    {
        $cfg = file_get_contents(self::CONFIG);

        $this->assertMatchesRegularExpression(
            "/'atomic_capacity_enabled'\]\s*=\s*true\s*;/i", $cfg,
            'The approved shipped state is atomic_capacity_enabled = TRUE (authorised 2026-08-16 '
            . 'after the Phase 20.3L/20.3M live UAT). If this has been turned back off, that is a '
            . 'deliberate rollback and this expectation must be updated with it — never silently.'
        );

        $missing = array_values(array_diff(array_keys(self::PATHS), $this->wiredPaths()));
        $this->assertSame([], $missing,
            'atomic_capacity_enabled is TRUE but these placement paths are NOT wired: '
            . implode(', ', $missing) . '. An enabled flag plus an unwired path is a real capacity '
            . 'bypass in code that reads as protected — the failure that caused Phase 20.3c to be '
            . 'reverted. Wire the path or set the flag back to false; do not ship this state.'
        );
    }

    public function test_group_cap_is_499_students_plus_one_marker(): void
    {
        $cfg = file_get_contents(self::CONFIG);
        $this->assertMatchesRegularExpression(
            "/'capacity_group_max_students'\]\s*=\s*499\s*;/", $cfg,
            'Firestore commits 500 writes max; a group is N students + 1 marker, so N <= 499.'
        );
    }

    // ── current wiring state, asserted explicitly ────────────────────────

    public function test_wired_paths_are_exactly_those_reported(): void
    {
        // This documents the TRUE state. It must be updated deliberately as
        // paths are wired — it cannot drift silently.
        $this->assertSame(
            // Order follows PATHS declaration order, not wiring chronology.
            ['admission', 'enroll', 'transfer', 'promotion', 'import'],
            $this->wiredPaths(),
            'Wiring state changed. Update this expectation AND the Phase 20.3i report, '
            . 'then re-check the flag lock above.'
        );
    }

    public function test_all_five_paths_are_now_wired(): void
    {
        $this->assertCount(5, $this->wiredPaths(),
            'All five placement paths must now be detected; the flag lock is therefore released.');
    }

    public function test_promotion_group_is_atomic_all_or_nothing(): void
    {
        // A refused destination group must report EVERY student as failed —
        // never "promoted" for a commit that did not happen.
        $src = $this->src(self::WRITER);
        $this->assertStringContainsString('placeStudentGroup(', $src);
        $this->assertStringContainsString("\$result['failed']=array_keys(\$students);",
            preg_replace('/\s+/', '', $src),
            'On group refusal every student must land in failed[].');
        $this->assertStringContainsString("\$result['moved']=array_keys(\$students);",
            preg_replace('/\s+/', '', $src),
            'On group success every student is moved.');
    }

    // ── guard contract ───────────────────────────────────────────────────

    public function test_guard_exposes_single_all_or_nothing_switch(): void
    {
        $g = $this->src(self::GUARD);
        $this->assertStringContainsString('function enabled(', $g);
        $this->assertStringContainsString('function groupMaxStudents(', $g);
        $this->assertStringContainsString('function placeStudent(', $g);
        $this->assertStringContainsString('function placeStudentGroup(', $g);
        $this->assertStringContainsString('function commitPlacement(', $g);
    }

    public function test_guard_refuses_oversized_group_without_chunking(): void
    {
        $g = preg_replace('/\s+/', '', $this->src(self::GUARD));
        $this->assertStringContainsString('$n>$max', $g,
            'An oversized destination group must be refused outright.');
        foreach (['array_chunk', 'array_slice'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $g,
                'Chunking is forbidden — the first chunk would move the section updateTime '
                . 'and the second would no longer be one atomic capacity decision.');
        }
    }

    public function test_guard_refuses_when_updateTime_is_missing(): void
    {
        $g = $this->src(self::GUARD);
        $this->assertStringContainsString('ACC_SEAT_CAS_NO_UPDATETIME', $g,
            'Without a precondition there is no guarantee — refuse rather than write unguarded.');
    }

    public function test_guard_never_retries_an_unknown_outcome(): void
    {
        $g = $this->src(self::GUARD);
        $this->assertStringContainsString('MAX_ATTEMPTS', $g);
        $this->assertStringNotContainsString('while(true)', preg_replace('/\s+/', '', $g));
    }

    // ── occupancy authority ──────────────────────────────────────────────

    public function test_marker_is_opaque_and_never_a_seat_count(): void
    {
        $g = $this->src(self::GUARD);
        $this->assertStringContainsString("MARKER_FIELD='seatCasAt'", preg_replace('/\s+/', '', $g));
        // A timestamp cannot be mistaken for occupancy; a counter could.
        $this->assertStringContainsString('date(\'c\')', $g,
            'The marker must be an opaque timestamp, not an incrementing count.');
    }

    public function test_no_second_occupancy_authority_exists(): void
    {
        foreach ([self::GUARD, self::SIS, self::CLASSES] as $f) {
            $src = $this->src($f);
            foreach (['seatClaims', 'occupiedSeats', 'reservedSeats', 'seatCount', 'seatLedger'] as $banned) {
                $this->assertStringNotContainsString($banned, $src,
                    "Occupancy must derive solely from student documents; {$banned} would be a second authority.");
            }
        }
    }

    // ── identity safety ──────────────────────────────────────────────────

    public function test_guard_mints_no_identity(): void
    {
        $g = $this->src(self::GUARD);
        foreach (['srNo', 'sr_no', 'admissionNo', 'rollNo', 'allocateNext', '_nextStudentId'] as $banned) {
            $this->assertStringNotContainsString($banned, $g,
                "The capacity mechanism must not touch identity ({$banned}).");
        }
    }

    public function test_enroll_guards_saveStudent_not_syncStudent(): void
    {
        // Phase 20.3e-1 proved saveStudent() is the document-CREATING write in
        // enroll. Guarding the later sync would leave the real creation
        // unprotected — a bypass that reads as protected.
        //
        // 2026-09-13 — this was a ±1200/400-character window around the string
        // 'enroll_student'. The Phase 20.3 seat-retry loop was later inserted
        // BETWEEN the guard call and the fallback write, pushing saveStudent out
        // of the window: the test failed while the code was correct. Verified by
        // reading the flow — the guarded branch replaces the creating write and the
        // direct saveStudent() survives only as the flag-off fallback.
        //
        // It now asserts the ORDER that actually carries the safety property,
        // scoped to enroll_student()'s own body, so inserting code cannot break it
        // and moving the guard cannot pass it.
        $body = $this->methodBody(self::SIS, 'enroll_student');

        $this->assertMatchesRegularExpression("/_placeStudentGuarded\([^;]*'enroll_student'/s", $body,
            'enroll does not route its placement through the seat guard for this path.');

        $guard = strpos($body, '_seatGuardEnabled()');
        $save  = strpos($body, 'saveStudent(');
        $this->assertNotFalse($guard, 'the seat-guard branch is gone from enroll.');
        $this->assertNotFalse($save,  'enroll no longer performs the creating write.');
        $this->assertGreaterThan($guard, $save,
            'the direct saveStudent() must be the seat-guard-DISABLED fallback BENEATH the '
            . 'guard branch. Above it, the document-creating write would run unguarded.');

        // The sync is a follow-up mirror, never the protected write.
        $sync = strpos($body, 'syncStudent');
        if ($sync !== false) {
            $this->assertGreaterThan($save, $sync,
                'syncStudent runs AFTER the creating write. A guard wrapped around the '
                . 'sync instead would leave the real document creation unprotected.');
        }
    }
}
