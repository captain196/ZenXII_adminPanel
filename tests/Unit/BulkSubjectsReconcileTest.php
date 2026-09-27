<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * school_config → Subjects tab: save_bulk_subjects must be AUTHORITATIVE.
 *
 * THE DEFECT (reported 2026-08-29, reproduced by the operator twice):
 * the editor is a whole-list editor and answered `{status:"success",
 * message:"6 subjects saved for class 9.", count:6, failed:[]}` — while being
 * WRITE-ONLY. It looped the submitted rows through setEntity() and never
 * deleted a document that had dropped out of the list. So:
 *
 *   • delete a subject  → success reported, doc survives, it is back on reload;
 *   • delete + replace  → both the old and the new subject exist;
 *   • rename            → looked broken too, because the replacement row was
 *                         allocated a code POSITIONALLY (classKey*100 + n)
 *                         while rows loaded from Firestore kept their stored
 *                         code. Removing the 3rd of 6 and adding one handed the
 *                         new row a code an existing subject already owned, so
 *                         $subjectMap[$code] overwrote it and an untouched
 *                         subject silently renamed itself.
 *
 * `failed[]` was empty in every case because nothing failed — the endpoint did
 * exactly what it was written to do. A count of rows WRITTEN is not a
 * description of the resulting list, which is why this stayed invisible.
 *
 * SCOPE NOTE — what these tests do NOT prove:
 *   • They are source-contract tests, like their Phase 20.2 neighbours. They do
 *     not execute the endpoint, do not touch Firestore, and therefore do not
 *     prove that a delete round-trips against the live tenant. That needs UAT.
 *   • They do not prove the dependency guard is CORRECT, only that removal is
 *     routed through it and that every non-delete branch records a reason.
 */
class BulkSubjectsReconcileTest extends TestCase
{
    private const CTRL = __DIR__ . '/../../application/controllers/School_config.php';
    private const VIEW = __DIR__ . '/../../application/views/school_config/index.php';

    /**
     * Controller source with comments stripped, so a prose mention of
     * `removeEntity` in a comment can never satisfy an assertion below.
     */
    private function phpSrc(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(self::CTRL)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    /** Just the save_bulk_subjects body, comments already stripped. */
    private function endpoint(): string
    {
        $src   = $this->phpSrc();
        $start = strpos($src, 'function save_bulk_subjects');
        $this->assertNotFalse($start, 'save_bulk_subjects no longer exists.');
        $end = strpos($src, 'function save_stream', $start);
        $this->assertNotFalse($end, 'save_stream anchor moved — re-scope this test.');
        return substr($src, $start, $end - $start);
    }

    public function test_removed_subjects_are_actually_deleted(): void
    {
        $body = $this->endpoint();
        $this->assertStringContainsString(
            "removeEntity('subjects'",
            $body,
            'save_bulk_subjects is write-only again: a subject removed in the editor '
            . 'is never removed from Firestore, so it returns on the next load.'
        );
    }

    public function test_the_existing_subject_read_is_fail_loud(): void
    {
        $body = $this->endpoint();

        // schoolWhere() returns [] on failure exactly as it does for an empty
        // class. Reconciling deletions off a silent [] would skip every removal
        // and still report success — the same shape as the original defect.
        $this->assertStringContainsString(
            "schoolWhereOrFail('subjects'",
            $body,
            'The existing-subjects read must be the *OrFail variant; a silent [] '
            . 'would make "delete nothing" indistinguishable from "class is empty".'
        );
    }

    public function test_removal_is_gated_on_manage_not_edit(): void
    {
        $body = $this->endpoint();

        // Saving stays Configuration:edit; deleting matches delete_subject's
        // Configuration:manage. Without this, bulk save is a privilege bypass
        // around the single-delete endpoint's own gate.
        $this->assertMatchesRegularExpression(
            "/has_permission\(\s*'Configuration'\s*,\s*'manage'\s*\)/",
            $body,
            'Bulk removal must require Configuration:manage, as delete_subject does.'
        );
    }

    public function test_removal_runs_the_same_referential_integrity_guard(): void
    {
        $body = $this->endpoint();

        // A subject a teacher is still assigned to must not be deleted out from
        // under MY_Controller::_get_teacher_assignments — the Teacher app's auth
        // gate would then surface orphaned assignments.
        $this->assertStringContainsString('_dep_count_safe', $body,
            'Bulk removal must run the dependency check delete_subject runs.');
        $this->assertStringContainsString('subjectAssignments', $body,
            'The dependency check must cover subjectAssignments.');
    }

    public function test_every_refusal_to_delete_is_reported(): void
    {
        $body = $this->endpoint();

        // A subject that survives the save reappears in the list. Silently is
        // exactly how the original defect presented, so each skip carries a
        // reason back to the UI.
        $this->assertStringContainsString("'kept'", $body,
            'The response must carry kept[] so a surviving subject explains itself.');
        $this->assertStringContainsString("'removed'", $body,
            'The response must report what was actually removed.');
    }

    public function test_code_allocation_is_not_positional(): void
    {
        $body = $this->endpoint();

        // The collision source: a blank row's code derived from how many rows
        // happened to precede it, while reclaimed rows kept stored codes.
        $this->assertStringNotContainsString(
            'count($subjectMap) + 1',
            $body,
            'Positional code allocation is back — a new row can be handed a code '
            . 'an existing subject already owns and silently overwrite it.'
        );
        $this->assertStringNotContainsString('count($subjectMap)+1', $body);
    }

    public function test_allocation_avoids_codes_already_in_use(): void
    {
        $body = $this->endpoint();

        // Codes currently held in Firestore are claimed before any new row is
        // allocated, so a replacement can never land on an existing document.
        $this->assertStringContainsString('$claimed', $body,
            'Allocation must track claimed codes.');
        $this->assertMatchesRegularExpression(
            '/foreach\s*\(\s*array_keys\(\$existing\)/',
            $body,
            'Codes already in Firestore must be claimed before allocating new ones.'
        );
    }

    public function test_the_ui_surfaces_subjects_that_could_not_be_removed(): void
    {
        $view = file_get_contents(self::VIEW);

        $this->assertStringContainsString('renderKeptSubjects', $view,
            'The Subjects tab must render kept[] — a subject that quietly returns '
            . 'to the list reads as a broken delete.');
        $this->assertMatchesRegularExpression(
            '/renderKeptSubjects\(\s*\(d && d\.kept\)/',
            $view,
            'The save callback must pass the response kept[] to the renderer.'
        );
    }
}
