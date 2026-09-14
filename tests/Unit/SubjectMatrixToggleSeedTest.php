<?php

use PHPUnit\Framework\TestCase;

/**
 * BEHAVIOURAL test for the Same-teacher toggle seeding in the Subject
 * Assignment matrix (application/views/academic/index.php).
 *
 * Provenance: UAT 2026-09-02 on 8th/Mathematics. The operator had
 * "Sunita Raghav, every section", toggled Same-teacher OFF to assign her to
 * Section A only, and the next save persisted THREE per-section rows with
 * teacherId='' — the class-wide choice was silently discarded when the toggle
 * rendered empty per-section selects. The save reported success, and marks
 * write access hangs directly off these rows.
 *
 * The fix is `AC.sa._toggleSeed`. This test EXTRACTS that function from the
 * view source and EXECUTES it in Node — it does not grep for its presence,
 * because a structural assertion cannot fail for the defect this guards
 * against (see the 81-of-100-structural census).
 */
final class SubjectMatrixToggleSeedTest extends TestCase
{
    private static string $fnSource = '';

    public static function setUpBeforeClass(): void
    {
        $view = file_get_contents(APPPATH . 'views/academic/index.php');
        // Extract the whole method: from its key to the closing "},\n" at the
        // same nesting depth, by brace counting from the first '{'.
        $start = strpos($view, '_toggleSeed:function');
        if ($start === false) {
            self::fail('_toggleSeed not found in academic/index.php');
        }
        $open = strpos($view, '{', $start);
        $depth = 0;
        $end = null;
        for ($i = $open, $n = strlen($view); $i < $n; $i++) {
            if ($view[$i] === '{') $depth++;
            elseif ($view[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        self::assertNotNull($end, '_toggleSeed body is not brace-balanced');
        $body = substr($view, $open, $end - $open + 1);
        self::$fnSource = 'var _toggleSeed = function(sub,sections,checked)' . $body . ';';
    }

    /** Run _toggleSeed in Node with a JSON fixture; return the mutated sub. */
    private function seed(array $sub, array $sections, bool $checked): array
    {
        $js = self::$fnSource . "\n"
            . 'var sub = ' . json_encode($sub) . ";\n"
            . 'var out = _toggleSeed(sub, ' . json_encode($sections) . ', ' . ($checked ? 'true' : 'false') . ");\n"
            . 'process.stdout.write(JSON.stringify(out));';
        $file = sys_get_temp_dir() . '/toggle_seed_' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($file, $js);
        exec('node ' . escapeshellarg($file) . ' 2>&1', $outLines, $rc);
        @unlink($file);
        $raw = implode("\n", $outLines);
        $this->assertSame(0, $rc, "node execution failed:\n{$raw}");
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "non-JSON from node:\n{$raw}");
        return $decoded;
    }

    private function mathsClassWide(): array
    {
        return [
            'code' => '801', 'name' => 'Mathematics',
            'same_for_all' => true,
            'class_wide_teacher_id' => 'STA0112',
            'class_wide_teacher_name' => 'Sunita Raghav',
            'section_teachers' => (object)[],
        ];
    }

    /** The UAT defect itself: OFF must seed every section, not empty them. */
    public function test_toggle_off_seeds_every_section_with_the_class_wide_teacher(): void
    {
        $out = $this->seed($this->mathsClassWide(), ['Section A', 'Section B', 'Section C'], false);

        $this->assertFalse($out['same_for_all']);
        foreach (['Section A', 'Section B', 'Section C'] as $sec) {
            $this->assertSame('STA0112', $out['section_teachers'][$sec]['id'] ?? null,
                "{$sec} lost the teacher across the toggle — the 2026-09-02 UAT defect");
            $this->assertSame('Sunita Raghav', $out['section_teachers'][$sec]['name'] ?? null);
        }
    }

    /** Seeding must not overwrite a section the operator already set differently. */
    public function test_toggle_off_preserves_an_existing_section_choice(): void
    {
        $sub = $this->mathsClassWide();
        $sub['section_teachers'] = ['Section B' => ['id' => 'STA0004', 'name' => 'Sunita Devi']];

        $out = $this->seed($sub, ['Section A', 'Section B'], false);

        $this->assertSame('STA0004', $out['section_teachers']['Section B']['id'],
            'seeding overwrote a deliberate per-section choice');
        $this->assertSame('STA0112', $out['section_teachers']['Section A']['id']);
    }

    /** OFF with no class-wide teacher has nothing to seed — and must not invent one. */
    public function test_toggle_off_without_class_wide_teacher_seeds_nothing(): void
    {
        $sub = $this->mathsClassWide();
        $sub['class_wide_teacher_id'] = '';
        $sub['class_wide_teacher_name'] = '';

        $out = $this->seed($sub, ['Section A'], false);

        $this->assertFalse($out['same_for_all']);
        $this->assertTrue(
            empty($out['section_teachers']['Section A']['id']),
            'a teacher was invented from nowhere'
        );
    }

    /** ON adopts the section teacher only when every section agrees. */
    public function test_toggle_on_adopts_a_unanimous_section_teacher(): void
    {
        $sub = $this->mathsClassWide();
        $sub['same_for_all'] = false;
        $sub['class_wide_teacher_id'] = '';
        $sub['class_wide_teacher_name'] = '';
        $sub['section_teachers'] = [
            'Section A' => ['id' => 'STA0112', 'name' => 'Sunita Raghav'],
            'Section B' => ['id' => 'STA0112', 'name' => 'Sunita Raghav'],
        ];

        $out = $this->seed($sub, ['Section A', 'Section B'], true);

        $this->assertTrue($out['same_for_all']);
        $this->assertSame('STA0112', $out['class_wide_teacher_id']);
        $this->assertSame('Sunita Raghav', $out['class_wide_teacher_name']);
    }

    /**
     * The UAT 2026-09-12 case: A=Sunita, B and C empty, toggle ON. She is the
     * only teacher the row knows — "same teacher everywhere" can only mean
     * her. Empty sections must not veto the adoption.
     */
    public function test_toggle_on_adopts_the_single_teacher_when_other_sections_are_empty(): void
    {
        $sub = $this->mathsClassWide();
        $sub['same_for_all'] = false;
        $sub['class_wide_teacher_id'] = '';
        $sub['class_wide_teacher_name'] = '';
        $sub['section_teachers'] = [
            'Section A' => ['id' => 'STA0112', 'name' => 'Sunita Raghav'],
        ];

        $out = $this->seed($sub, ['Section A', 'Section B', 'Section C'], true);

        $this->assertTrue($out['same_for_all']);
        $this->assertSame('STA0112', $out['class_wide_teacher_id'],
            'the only teacher in the row was discarded by empty sections — the 2026-09-12 UAT finding');
        $this->assertSame('Sunita Raghav', $out['class_wide_teacher_name']);
    }

    /** ON with DIVERGENT sections must not pick a winner — that would invent data. */
    public function test_toggle_on_with_divergent_sections_adopts_nobody(): void
    {
        $sub = $this->mathsClassWide();
        $sub['same_for_all'] = false;
        $sub['class_wide_teacher_id'] = '';
        $sub['class_wide_teacher_name'] = '';
        $sub['section_teachers'] = [
            'Section A' => ['id' => 'STA0112', 'name' => 'Sunita Raghav'],
            'Section B' => ['id' => 'STA0004', 'name' => 'Sunita Devi'],
        ];

        $out = $this->seed($sub, ['Section A', 'Section B'], true);

        $this->assertTrue($out['same_for_all']);
        $this->assertSame('', (string)($out['class_wide_teacher_id'] ?? ''),
            'toggle ON invented a class-wide teacher from divergent sections');
    }

    /** ON never clobbers an already-chosen class-wide teacher. */
    public function test_toggle_on_keeps_an_existing_class_wide_teacher(): void
    {
        $sub = $this->mathsClassWide();
        $sub['same_for_all'] = false;
        $sub['section_teachers'] = ['Section A' => ['id' => 'STA0004', 'name' => 'Sunita Devi']];

        $out = $this->seed($sub, ['Section A'], true);

        $this->assertSame('STA0112', $out['class_wide_teacher_id'],
            'an existing class-wide teacher was replaced');
    }

    /**
     * The save-time guard must exist at the CALL SITE: the losing-teacher
     * comparison against AC.sa._teacherBaseline, and the confirm() that makes
     * the revocation deliberate. Call-site anchored (not the definition),
     * matching how the seven substring-collision fixes were done.
     */
    public function test_save_guard_confirms_before_stripping_a_subjects_last_teacher(): void
    {
        $view = file_get_contents(APPPATH . 'views/academic/index.php');

        $save = strpos($view, "post('academic/save_class_matrix'");
        $this->assertNotFalse($save, 'save_class_matrix call site missing');

        $guard = strpos($view, 'AC.sa._teacherBaseline||{}');
        $this->assertNotFalse($guard, 'save() no longer compares against the loaded teacher baseline');
        $this->assertLessThan($save, $guard, 'the baseline comparison must run BEFORE the save posts');

        $confirm = strpos($view, 'This save removes the ONLY teacher from');
        $this->assertNotFalse($confirm, 'the revocation prompt is gone');

        // The save body now lives in doSave() and the modal gates the CALL,
        // so source position no longer mirrors execution order. Assert the
        // gating structure itself: the post must sit INSIDE doSave, and the
        // themed modal must be what invokes doSave on confirmation.
        $doSave = strpos($view, 'var doSave=function(){');
        $this->assertNotFalse($doSave, 'the gated doSave wrapper is gone');
        $this->assertLessThan($save, $doSave, 'the save post must live inside doSave()');
        $this->assertLessThan($confirm, $save, 'the modal gate must come after doSave is defined');

        // BUG-073 house rule: the prompt must be the THEMED modal, not the
        // native "localhost says" confirm() (operator feedback, 2026-09-12).
        $window = substr($view, max(0, $confirm - 400), 800);
        $this->assertStringContainsString('_confirmModal', $window,
            'the revocation prompt is not using the themed _confirmModal');
        $this->assertStringContainsString('if(go) doSave();', $window,
            'the modal no longer gates the save call');
        $this->assertStringNotContainsString("confirm('This save removes", $view,
            'the native confirm() came back for the revocation prompt');

        // And the baseline must actually be captured where the server answers.
        $this->assertStringContainsString("AC.sa._teacherBaseline={};", $view,
            'the baseline is never captured on matrix load');
    }
}
