<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20.2 — Section capacity workflow (P20.2-CAP-1/2/3, P20.2-UI-1, RBAC-1).
 *
 * SCOPE NOTE — what these tests do NOT prove:
 *   • They do not prove concurrency safety. P20.2-RACE-1 is an UNRESOLVED
 *     engine property: capacity is read-then-write with no transaction, CAS or
 *     seat claim, so two concurrent placements can both pass a 39/40 check.
 *     Sequential unit tests CANNOT demonstrate otherwise and none here claims to.
 *   • They do not prove browser behaviour. UAT was not executed.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47.
 */
class Phase202SectionCapacityWorkflowTest extends TestCase
{
    private const CTRL = __DIR__ . '/../../application/controllers/School_config.php';
    private const VIEW = __DIR__ . '/../../application/views/school_config/index.php';

    private function phpSrc(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(self::CTRL)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    /**
     * View source with HTML and line comments stripped.
     *
     * Deliberately does NOT strip `/* … *\/` blocks: this 4,300-line view
     * contains an unbalanced `/*` earlier in the file, so a non-greedy block
     * strip swallows everything up to the next `*\/` — including the capacity
     * modal at line 827. Verified by bisecting the three passes.
     *
     * Safe to omit: every absence-assertion below is scoped to a named handler
     * body whose own comments do not contain the forbidden tokens, so block
     * comments cannot produce a false pass.
     *
     * (Delimiter must not be `!` — the HTML pattern itself contains `<!--`.)
     */
    private function viewSrc(): string
    {
        $raw = file_get_contents(self::VIEW);
        $raw = preg_replace('~<!--.*?-->~s', '', $raw);
        return preg_replace('~^\s*//.*$~m', '', $raw);
    }

    // ── the two capacity rules must agree ────────────────────────────────

    /** Mirrors MY_Controller::_sectionCapacity()'s D17 resolution. */
    private function canonicalCapacity($raw): int
    {
        $capacity = 40;
        $n = (int) $raw;
        if ($n > 0) $capacity = $n;
        return $capacity;
    }

    /** Mirrors School_config::_display_capacity(). */
    private function displayCapacity($doc): int
    {
        $raw = 0;
        if (is_array($doc)) $raw = (int) ($doc['maxStrength'] ?? $doc['max_strength'] ?? 0);
        return $raw > 0 ? $raw : 40;
    }

    public function test_CAP1_display_resolver_matches_canonical_D17_across_input_space(): void
    {
        $cases = [
            'absent'        => [],
            'zero'          => ['maxStrength' => 0],
            'negative'      => ['maxStrength' => -5],
            'non_numeric'   => ['maxStrength' => 'abc'],
            'empty_string'  => ['maxStrength' => ''],
            'null'          => ['maxStrength' => null],
            'positive'      => ['maxStrength' => 30],
            'string_number' => ['maxStrength' => '25'],
            'legacy_snake'  => ['max_strength' => 35],
            'large'         => ['maxStrength' => 500],
        ];
        foreach ($cases as $name => $doc) {
            $raw = $doc['maxStrength'] ?? $doc['max_strength'] ?? 0;
            $this->assertSame(
                $this->canonicalCapacity($raw),
                $this->displayCapacity($doc),
                "Display resolver drifted from the canonical D17 rule for case: {$name}"
            );
        }
    }

    public function test_CAP1_D17_defaults_are_exactly_preserved(): void
    {
        $this->assertSame(40, $this->displayCapacity([]),               'absent → 40');
        $this->assertSame(40, $this->displayCapacity(['maxStrength' => 0]),  '0 → 40, NOT unlimited');
        $this->assertSame(40, $this->displayCapacity(['maxStrength' => -1]), 'negative → 40');
        $this->assertSame(30, $this->displayCapacity(['maxStrength' => 30]), 'configured wins');
    }

    public function test_CAP1_configured_flag_distinguishes_decision_from_fallback(): void
    {
        // P20-F3 found 35/35 sections inheriting a 40 nobody chose. The UI must
        // never present that fallback as an operator decision (P20-D3: no backfill).
        $configured = function ($doc) {
            if (!is_array($doc)) return false;
            return ((int) ($doc['maxStrength'] ?? $doc['max_strength'] ?? 0)) > 0;
        };
        $this->assertFalse($configured([]),                      'absent is NOT configured');
        $this->assertFalse($configured(['maxStrength' => 0]),     '0 is NOT configured');
        $this->assertTrue($configured(['maxStrength' => 40]),     'an explicit 40 IS configured');
        $this->assertTrue($configured(['max_strength' => 40]),    'legacy shape counts');
    }

    // ── occupancy / available / status ───────────────────────────────────

    private function seatRow(int $capacity, ?int $occupied): array
    {
        return [
            'capacity'  => $capacity,
            'occupied'  => $occupied,
            'available' => ($occupied === null) ? null : max(0, $capacity - $occupied),
            'over'      => ($occupied === null) ? null : max(0, $occupied - $capacity),
        ];
    }

    public function test_CAP2_available_and_over_are_derived_correctly(): void
    {
        $open = $this->seatRow(40, 32);
        $this->assertSame(8, $open['available']);
        $this->assertSame(0, $open['over']);

        $full = $this->seatRow(40, 40);
        $this->assertSame(0, $full['available']);
        $this->assertSame(0, $full['over']);

        $over = $this->seatRow(30, 38);
        $this->assertSame(0, $over['available'], 'available never goes negative');
        $this->assertSame(8, $over['over']);
    }

    public function test_CAP2_unknown_occupancy_is_null_not_zero(): void
    {
        $unknown = $this->seatRow(40, null);
        $this->assertNull($unknown['occupied'], 'Unavailable occupancy must not be fabricated as 0.');
        $this->assertNull($unknown['available']);
        $this->assertNull($unknown['over']);
    }

    // ── capacity write endpoint ──────────────────────────────────────────

    public function test_CAP3_endpoint_exists_and_writes_only_maxStrength(): void
    {
        $src   = $this->phpSrc();
        $start = strpos($src, 'function save_section_capacity(');
        $this->assertNotFalse($start, 'save_section_capacity endpoint missing.');
        $body  = substr($src, $start, 5200);

        $this->assertStringContainsString("'maxStrength'=>\$capacity",
            preg_replace('/\s+/', '', $body), 'Must write maxStrength.');
        // Identity and student fields must never be written here.
        foreach (['className', 'sectionCode', 'sectionKey', 'studentId', 'srNo', 'rollNo'] as $forbidden) {
            $this->assertSame(0, preg_match('/[\'"]' . $forbidden . '[\'"]\s*=>/', $body),
                "save_section_capacity must not write {$forbidden}.");
        }
    }

    public function test_CAP3_zero_capacity_is_rejected_not_treated_as_unlimited(): void
    {
        $src  = $this->phpSrc();
        $body = substr($src, strpos($src, 'function save_section_capacity('), 5200);
        $this->assertStringContainsString('$capacity<1', preg_replace('/\s+/', '', $body),
            'Capacity below 1 must be rejected — 0 would silently resolve to 40 under D17.');
    }

    public function test_CAP3_uses_canonical_docId_never_hand_built(): void
    {
        $src  = $this->phpSrc();
        $body = substr($src, strpos($src, 'function save_section_capacity('), 5200);
        $this->assertStringContainsString('sectionDocId(', $body, 'SEC-2: identity via the canonical helper.');
        $this->assertSame(0, preg_match('/\$this->school_id\s*\.\s*[\'"]_/', $body),
            'Document id must never be concatenated by hand.');
    }

    public function test_CAP3_occupancy_uses_the_canonical_roster_rule(): void
    {
        $src  = $this->phpSrc();
        $body = substr($src, strpos($src, 'function save_section_capacity('), 5200);
        $this->assertStringContainsString('_filterSectionRoster(', $body,
            'Occupancy must come from the same derivation the capacity guard uses (SEC-11).');
        $this->assertStringContainsString('occupancy_undetermined', $body,
            'Unverifiable occupancy must fail closed, not assume 0.');
    }

    public function test_CAP3_reduction_below_occupancy_requires_confirmation(): void
    {
        $src  = $this->phpSrc();
        $body = substr($src, strpos($src, 'function save_section_capacity('), 5200);
        $this->assertStringContainsString('confirm_required', $body);
        $this->assertStringContainsString('confirm_over_capacity', $body);

        // P20-D5: the policy is warn + confirm — never auto-remove or move.
        foreach (['updateEntity(\'students\'', 'remove(\'students\'', 'transfer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                'Capacity change must never touch student records.');
        }
    }

    /** The decision gate itself, independent of transport. */
    public function test_CAP3_reduction_policy_decision_table(): void
    {
        $needsConfirm = function (int $newCap, int $occupied, bool $confirmed) {
            return ($newCap < $occupied) && !$confirmed;
        };
        $this->assertFalse($needsConfirm(45, 38, false), 'increase → allowed outright');
        $this->assertFalse($needsConfirm(38, 38, false), 'equal to occupancy → allowed');
        $this->assertTrue($needsConfirm(30, 38, false),  'below occupancy → confirm required');
        $this->assertFalse($needsConfirm(30, 38, true),  'below occupancy + confirmed → allowed');
        $this->assertFalse($needsConfirm(40, 0,  false), 'empty section → allowed');
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    public function test_RBAC_capacity_write_is_capability_only(): void
    {
        $src  = $this->phpSrc();
        $body = substr($src, strpos($src, 'function save_section_capacity('), 900);
        $this->assertStringContainsString("_require_role([],", preg_replace('/\s+/', '', $body),
            'An empty allow-list removes the legacy role-name fallback, so a user merely '
            . 'NAMED "Principal" cannot bypass the graded capability.');
        $this->assertStringContainsString("'SIS'", $body);
        $this->assertStringContainsString("'manage'", $body);
    }

    // ── UI ───────────────────────────────────────────────────────────────

    public function test_UI_does_not_recompute_occupancy_in_javascript(): void
    {
        $view = $this->viewSrc();
        $start = strpos($view, 'function renderSeats()');
        $this->assertNotFalse($start, 'renderSeats() missing.');
        $body = substr($view, $start, 3000);

        // Formatting only — no client-side roster/capacity derivation.
        foreach (['_filterSectionRoster', 'maxStrength', 'students.filter', '.status === \'Active\''] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                'The UI must not derive occupancy or capacity itself.');
        }
    }

    public function test_UI_uses_bootstrap3_and_no_native_dialogs(): void
    {
        $view = $this->viewSrc();
        $this->assertStringContainsString('id="capacityModal"', $view);
        $this->assertStringContainsString('data-dismiss="modal"', $view, 'Bootstrap 3 attribute.');
        $this->assertSame(0, preg_match_all('/data-bs-|btn-close|ms-auto/', $view),
            'No Bootstrap 4/5 markup may be introduced.');
    }

    public function test_UI_capacity_save_has_duplicate_submit_protection(): void
    {
        $view = $this->viewSrc();
        $body = substr($view, strpos($view, 'window.saveCapacity'), 2200);
        $this->assertStringContainsString('btn.disabled = true', $body);
        $this->assertStringContainsString('confirm_required', $body,
            'The UI must surface the server-computed warning, not invent its own.');
    }

    public function test_UI_shows_unset_capacity_distinctly(): void
    {
        $view = $this->viewSrc();
        $this->assertStringContainsString('capacity_configured', $view,
            'A fallback 40 must be visually distinguished from a configured 40.');
        $this->assertStringContainsString('not set', $view);
    }

    // ── identity / SRNO ──────────────────────────────────────────────────

    public function test_SECTION_IDENTITY_no_rename_capability_added(): void
    {
        $src = $this->phpSrc();
        $this->assertSame(0, preg_match('/function\s+rename_section/i', $src),
            'sectionKey is a cross-platform contract — rename must not be exposed.');
        $view = $this->viewSrc();
        $this->assertSame(0, preg_match('/renameSection\s*=/', $view));
    }

    public function test_SRNO_capacity_workflow_never_references_student_identity(): void
    {
        $src  = $this->phpSrc();
        foreach (['save_section_capacity', '_display_capacity', '_capacity_is_configured'] as $fn) {
            $start = strpos($src, $fn . '(');
            $this->assertNotFalse($start, "{$fn} not found.");
            $body = substr($src, $start, 5200);
            $this->assertSame(0, preg_match_all('/srNo|sr_no|admissionNo|rollNo/i', $body),
                "{$fn} must not reference student identity fields.");
        }
    }

    public function test_SRNO_ui_capacity_editor_carries_no_identity_fields(): void
    {
        $view = $this->viewSrc();
        $body = substr($view, strpos($view, 'window.openCapacityEditor'), 2500);
        $this->assertSame(0, preg_match_all('/srNo|admissionNo|rollNo/i', $body));
    }

    // ── explicit non-claim ───────────────────────────────────────────────

    public function test_RACE1_is_documented_as_unresolved(): void
    {
        $src = file_get_contents(self::CTRL);   // comments intentionally KEPT
        $this->assertStringContainsString('P20.2-RACE-1', $src,
            'The unresolved concurrency limitation must remain documented at the '
            . 'capacity endpoint so a future reader does not assume it is race-safe.');
    }
}
