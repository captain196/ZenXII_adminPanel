<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Production hardening programme (2026-08-19) — P0 DATA-LOSS FIX.
 *
 * `schools/{id}.attendancePolicy` is a shared nested map with FOUR writers
 * owning disjoint branches:
 *
 *   Attendance::save_attendance_policy()  → version, enabledMethods, gps,
 *                                            shifts, weeklyOffs,
 *                                            allowWorkOnOff, autoAbsent
 *   Work_schedules::save_schedule()        → workSchedules[]
 *   Work_schedules::delete_schedule()      → workSchedules[]
 *   Working_week::save_version()           → workingWeek.versions[]
 *
 * Firestore_rest_client::buildUpdateMask() emits one updateMask fieldPath
 * per top-level array key. A NON-dotted key therefore masks — and replaces
 * — the entire nested map.
 *
 * `Attendance::save_attendance_policy()` built its $policy fresh from POST
 * input (never reading the stored document) and wrote it under the
 * non-dotted key `attendancePolicy`. It has never contained `workSchedules`
 * or `workingWeek`. So every save of the Attendance Policy screen — even
 * just changing a geofence radius — DESTROYED the tenant's entire
 * work-schedule catalogue and every working-week version.
 *
 * That is deterministic data loss on every write, not the intermittent
 * lost-update race it was first classified as. And because an employee
 * with no resolvable schedule BLOCKS payroll generation
 * (Work_schedules::_blockedStaff), the blast radius was school-wide
 * payroll failure.
 *
 * The two Work_schedules writers had the milder form: they read-modify-write
 * the whole map, so they preserved siblings *at read time* but could clobber
 * a concurrent geofence/working-week save landing in between.
 *
 * Fix: every writer masks only the paths it owns (dotted paths), or uses a
 * recursive merge write. No schema change, no new abstraction — this is the
 * pattern the REST client documents (B2.3.2-FIX R5) and that
 * Hr::lock_payroll_month() already uses for `payrollLocks.{key}`.
 *
 * This file pairs SOURCE-regression tests with an EXECUTABLE simulation of
 * Firestore's documented field-mask semantics, because the defect is
 * fundamentally behavioural: string matching alone cannot demonstrate that
 * one write shape loses data and the other does not.
 */
class AttendancePolicyFieldScopedWriteTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    //  Executable model of Firestore PATCH + updateMask
    // ─────────────────────────────────────────────────────────────────

    /**
     * Apply a Firestore PATCH with an updateMask to a document.
     *
     * Semantics per Firestore REST: for each fieldPath in the mask, the
     * value at that path in the stored document is REPLACED by the value at
     * the same path in the request body (or removed if the body omits it).
     * Paths not named in the mask are left untouched.
     *
     * The mask is derived exactly as Firestore_rest_client::buildUpdateMask()
     * derives it — one path per top-level key of the write payload.
     *
     * @param array $doc     stored document
     * @param array $payload the $data array passed to fs->update()
     */
    private function applyPatch(array $doc, array $payload): array
    {
        foreach ($payload as $path => $value) {
            $parts = explode('.', (string) $path);
            $ref = &$doc;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $ref[$part] = $value;
                } else {
                    if (!isset($ref[$part]) || !is_array($ref[$part])) $ref[$part] = [];
                    $ref = &$ref[$part];
                }
            }
            unset($ref);
        }
        return $doc;
    }

    /** A tenant with all three branches populated. */
    private function storedDoc(): array
    {
        return ['attendancePolicy' => [
            'version'    => 1,
            'gps'        => ['geofences' => [['centerLat' => 1.0, 'radius' => 200]]],
            'shifts'     => ['default' => ['name' => 'General']],
            'weeklyOffs' => ['Sun'],
            'workSchedules' => [
                ['scheduleId' => 'WS_A', 'name' => 'Teaching'],
                ['scheduleId' => 'WS_B', 'name' => 'Admin'],
            ],
            'workingWeek' => ['versions' => [['version' => 1, 'weeklyOffs' => ['Sun']]]],
        ]];
    }

    /** The freshly-built policy map save_attendance_policy() produces. */
    private function freshPolicy(): array
    {
        return [
            'version'        => 1,
            'enabledMethods' => ['gps', 'manual'],
            'gps'            => ['geofences' => [['centerLat' => 2.0, 'radius' => 500]]],
            'shifts'         => ['default' => ['name' => 'General']],
            'weeklyOffs'     => ['Sun'],
            'allowWorkOnOff' => false,
            'autoAbsent'     => true,
            'updatedAt'      => '2026-08-19T00:00:00+00:00',
            'updatedBy'      => 'admin',
        ];
    }

    // ── The defect, demonstrated ─────────────────────────────────────

    public function test_OLD_whole_map_write_destroys_work_schedules_and_working_week(): void
    {
        // Reproduces the pre-fix behaviour: one non-dotted key.
        $after = $this->applyPatch($this->storedDoc(), [
            'attendancePolicy' => $this->freshPolicy(),
        ]);

        $this->assertArrayNotHasKey('workSchedules', $after['attendancePolicy'],
            'the whole-map write destroys the work-schedule catalogue — this is the P0 being fixed');
        $this->assertArrayNotHasKey('workingWeek', $after['attendancePolicy'],
            'the whole-map write destroys every working-week version');
        // And it did successfully apply the intended change, which is why the
        // loss was silent — the screen appeared to work.
        $this->assertSame(500, $after['attendancePolicy']['gps']['geofences'][0]['radius']);
    }

    // ── The fix, demonstrated ────────────────────────────────────────

    public function test_NEW_field_scoped_write_preserves_both_sibling_branches(): void
    {
        $p = $this->freshPolicy();
        $after = $this->applyPatch($this->storedDoc(), [
            'attendancePolicy.version'        => $p['version'],
            'attendancePolicy.enabledMethods' => $p['enabledMethods'],
            'attendancePolicy.gps'            => $p['gps'],
            'attendancePolicy.shifts'         => $p['shifts'],
            'attendancePolicy.weeklyOffs'     => $p['weeklyOffs'],
            'attendancePolicy.allowWorkOnOff' => $p['allowWorkOnOff'],
            'attendancePolicy.autoAbsent'     => $p['autoAbsent'],
            'attendancePolicy.updatedAt'      => $p['updatedAt'],
            'attendancePolicy.updatedBy'      => $p['updatedBy'],
        ]);

        // Siblings survive, fully intact.
        $this->assertCount(2, $after['attendancePolicy']['workSchedules']);
        $this->assertSame('WS_A', $after['attendancePolicy']['workSchedules'][0]['scheduleId']);
        $this->assertCount(1, $after['attendancePolicy']['workingWeek']['versions']);
        // And the intended change still applied.
        $this->assertSame(500, $after['attendancePolicy']['gps']['geofences'][0]['radius']);
    }

    public function test_field_scoped_write_still_supports_clearing_a_branch(): void
    {
        // Removing every geofence must still work — each owned path is
        // written WHOLESALE at its own path, so clearing is unaffected.
        $after = $this->applyPatch($this->storedDoc(), [
            'attendancePolicy.gps' => ['geofences' => []],
        ]);
        $this->assertSame([], $after['attendancePolicy']['gps']['geofences']);
        $this->assertCount(2, $after['attendancePolicy']['workSchedules'], 'clearing one branch must not touch another');
    }

    public function test_concurrent_disjoint_writers_no_longer_clobber_each_other(): void
    {
        // The lost-update race: two admins save different screens, each
        // having read the document BEFORE the other wrote.
        $doc = $this->storedDoc();

        // Admin A saves the geofence (field-scoped).
        $doc = $this->applyPatch($doc, [
            'attendancePolicy.gps' => ['geofences' => [['centerLat' => 9.9, 'radius' => 750]]],
        ]);
        // Admin B saves the schedule catalogue from a STALE read (field-scoped).
        $doc = $this->applyPatch($doc, [
            'attendancePolicy.workSchedules' => [['scheduleId' => 'WS_C', 'name' => 'Night']],
        ]);

        // Both survive — neither overwrote the other's branch.
        $this->assertSame(750, $doc['attendancePolicy']['gps']['geofences'][0]['radius'],
            "admin A's geofence must survive admin B's stale-read save");
        $this->assertSame('WS_C', $doc['attendancePolicy']['workSchedules'][0]['scheduleId']);
        $this->assertCount(1, $doc['attendancePolicy']['workingWeek']['versions'],
            'the untouched third branch must survive both writes');
    }

    public function test_dotted_path_creates_the_parent_map_when_absent(): void
    {
        // A tenant that has never saved a policy must still get one.
        $after = $this->applyPatch([], ['attendancePolicy.gps' => ['geofences' => []]]);
        $this->assertArrayHasKey('attendancePolicy', $after);
        $this->assertArrayHasKey('gps', $after['attendancePolicy']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Source regression — the writers must stay field-scoped
    // ─────────────────────────────────────────────────────────────────

    private function codeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    public function test_attendance_writer_uses_dotted_paths_for_every_key_it_owns(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Attendance.php');
        foreach (['version', 'enabledMethods', 'gps', 'shifts', 'weeklyOffs',
                  'allowWorkOnOff', 'autoAbsent', 'updatedAt', 'updatedBy'] as $k) {
            $this->assertStringContainsString("'attendancePolicy.{$k}'", $src,
                "save_attendance_policy() must write attendancePolicy.{$k} as its own masked path");
        }
    }

    public function test_no_writer_replaces_the_whole_attendance_policy_map(): void
    {
        // The exact pre-fix shape must not reappear in any production writer.
        foreach ([
            'application/controllers/Attendance.php',
            'application/controllers/Work_schedules.php',
        ] as $rel) {
            $src = $this->codeOnly(__DIR__ . '/../../' . $rel);
            $this->assertStringNotContainsString("['attendancePolicy' => \$policy]", $src,
                "{$rel} must not write the whole attendancePolicy map — that is the P0 data-loss shape");
        }
    }

    public function test_work_schedule_writers_scope_to_their_own_branch(): void
    {
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Work_schedules.php');
        $this->assertSame(2, substr_count($src, "'attendancePolicy.workSchedules' =>"),
            'both save_schedule() and delete_schedule() must write only the workSchedules path');
    }

    public function test_working_week_writer_still_uses_a_recursive_merge(): void
    {
        // This writer was already correct — guard it against regression.
        $src = $this->codeOnly(__DIR__ . '/../../application/controllers/Working_week.php');
        $this->assertStringContainsString("'attendancePolicy' => ['workingWeek' => ['versions' => \$versions]]", $src);
        $this->assertStringContainsString('], true);', $src, 'must remain a merge write');
    }

    public function test_no_rbac_or_validation_was_altered_in_the_writers(): void
    {
        $att = $this->codeOnly(__DIR__ . '/../../application/controllers/Attendance.php');
        $this->assertStringContainsString('Could not save the attendance policy. Please retry.', $att,
            'the failure path must be preserved');
        $ws = $this->codeOnly(__DIR__ . '/../../application/controllers/Work_schedules.php');
        $this->assertStringContainsString('This schedule is assigned to', $ws,
            'delete_schedule() must keep its in-use guard');
    }
}
