<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UI-D4 — the Job Posting modal.
 *
 * Reported by the operator against a real recruitment screen at 90% browser
 * zoom: the card was cramped (620px) and, with ten fields stacked in one
 * scrolling block, Cancel/Save were pushed past the bottom of the viewport —
 * the form could be filled in but not submitted.
 *
 * The fix splits the card into head / scrolling body / pinned footer, so the
 * actions are structurally incapable of being scrolled away. The risk that
 * carries is that a field silently disappears during the re-layout and the
 * save path then posts an empty value, so field parity with saveJob() is
 * asserted here rather than assumed.
 */
final class JobModalProfessionalShellTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/hr/index.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /** Just the #modalJob overlay, so assertions cannot drift to another modal. */
    private function modal(): string
    {
        $start = strpos($this->src, '<div class="hr-modal-overlay" id="modalJob">');
        $this->assertNotFalse($start, '#modalJob overlay is gone');
        $end = strpos($this->src, '<!-- Circular Viewer Modal -->', $start);
        $this->assertNotFalse($end, 'the marker that bounds the job modal moved');
        return substr($this->src, $start, $end - $start);
    }

    /**
     * DEFECT UI-D4a: every field saveJob() reads must still exist. A dropped
     * input does not throw — jQuery returns an empty set and .val() yields
     * undefined, so the posting saves with the field silently blank.
     */
    public function test_every_field_the_save_path_reads_is_present(): void
    {
        $save = strstr($this->src, 'function saveJob(){');
        $this->assertNotFalse($save);
        $save = substr($save, 0, (int) strpos($save, '}).fail('));

        $this->assertMatchesRegularExpression("~\\\$\('#job~", $save, 'saveJob no longer reads #job* fields');
        preg_match_all("~\\\$\('#(job[A-Za-z]+)~", $save, $m);
        $read = array_values(array_unique($m[1]));
        $this->assertNotEmpty($read);

        $modal = $this->modal();
        foreach ($read as $id) {
            $this->assertMatchesRegularExpression(
                '~id="' . preg_quote($id, '~') . '"~', $modal,
                "saveJob() reads #{$id} but the modal no longer renders it"
            );
        }
    }

    /** DEFECT UI-D4b: the cramped fixed width is gone. */
    public function test_the_card_is_no_longer_pinned_to_620px(): void
    {
        $this->assertStringNotContainsString('style="max-width:620px;"', $this->modal());
        $this->assertStringContainsString('hr-modal hr-modal-lg', $this->modal());
    }

    /**
     * DEFECT UI-D4c: the footer must sit outside the scrolling region.
     * This is the actual reported failure — not cosmetics.
     */
    public function test_the_actions_are_pinned_outside_the_scrolling_body(): void
    {
        $modal = $this->modal();

        $body    = strpos($modal, 'class="hr-modal-body"');
        $actions = strpos($modal, 'class="hr-modal-actions"');
        $this->assertNotFalse($body, 'no scrolling body region');
        $this->assertNotFalse($actions, 'no actions region');
        $this->assertLessThan($actions, $body, 'actions must follow the body');

        // The body has to be closed before the actions open, otherwise the
        // footer is nested inside the scroller and scrolls away again.
        $between = substr($modal, $body, $actions - $body);
        $this->assertStringContainsString('</div>', $between);

        // Only the body scrolls; the card itself clips.
        $this->assertMatchesRegularExpression(
            '~\.hr-modal-body\{[^}]*overflow-y:auto~', $this->src,
            'the body is not the scroll container'
        );
        $this->assertMatchesRegularExpression(
            '~\.hr-modal-lg\{[^}]*flex-direction:column~', $this->src);
        $this->assertMatchesRegularExpression(
            '~\.hr-modal-lg\{[^}]*max-height:~', $this->src,
            'without a max-height the card can still exceed the viewport');
        $this->assertMatchesRegularExpression(
            '~\.hr-modal-lg\{[^}]*overflow:hidden~', $this->src);
    }

    /** A large modal needs an explicit way out besides the footer. */
    public function test_the_card_has_a_close_control(): void
    {
        $this->assertMatchesRegularExpression(
            '~class="hr-modal-x"[^>]*onclick="HR\.closeModal\(\'modalJob\'\)"~', $this->modal());
    }

    /**
     * The department roster. get_departments() already returns staff[] and
     * staff_count per department; the modal never showed it, so a recruiter
     * had to guess existing headcount before choosing how many positions to
     * open. Wiring, not cosmetics — assert it is actually reachable.
     */
    public function test_the_department_snapshot_is_wired_on_every_path(): void
    {
        $this->assertSame(1, preg_match_all('~function renderJobDeptSnapshot\(\)~', $this->src),
            'renderJobDeptSnapshot must be defined exactly once');

        $this->assertStringContainsString('id="jobDeptSnapshot"', $this->modal());

        // Reset when a new posting opens…
        $open = strstr($this->src, 'function openJobModal(id){');
        $open = substr((string) $open, 0, (int) strpos((string) $open, "openModal('modalJob')"));
        $this->assertStringContainsString('renderJobDeptSnapshot()', $open,
            'a new posting would inherit the previous department roster');

        // …populated when an existing posting is edited…
        $edit = strstr($this->src, 'function editJob(id){');
        $edit = substr((string) $edit, 0, 1200);
        $this->assertStringContainsString('renderJobDeptSnapshot()', $edit);

        // …and kept in step with the select.
        $this->assertMatchesRegularExpression(
            "~on\('change',\s*'#jobDept',\s*renderJobDeptSnapshot\)~", $this->src);
    }

    /** It must read the roster the endpoint actually returns, not invent one. */
    public function test_the_snapshot_reads_the_real_endpoint_fields(): void
    {
        $fn = strstr($this->src, 'function renderJobDeptSnapshot()');
        $fn = substr((string) $fn, 0, 2000);

        foreach (['deptCache', 'dept.staff', 'staff_count'] as $needle) {
            $this->assertStringContainsString($needle, $fn,
                "the snapshot must read {$needle} as get_departments() emits it");
        }
        // Names come from other users' input — they must be escaped.
        $this->assertStringContainsString('esc(', $fn, 'staff names are rendered unescaped');
    }
}
