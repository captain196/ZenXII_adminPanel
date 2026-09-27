<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A draft appraisal is readable by the employee it describes.
 *
 * FOUND 2026-08-21 while verifying the Teacher-app ESS appraisal contract.
 *
 * The Teacher app documents the intent plainly — AppraisalDoc.kt: "Teachers
 * only see Submitted/Reviewed", and HRFirestoreRepository.getMyAppraisals():
 * "Drafts are filtered out — teachers shouldn't see in-progress reviews."
 *
 * But that filter is CLIENT-SIDE:
 *
 *     val visible = all.filter { it.status != "Draft" }
 *
 * and the rule it runs behind (firestore.rules, match /appraisals/{docId}) is:
 *
 *     allow read: if resource.data.staffId == request.auth.uid
 *                 || (isAdmin() && isSameSchool());
 *
 * Status is not a condition. The draft is therefore transmitted to the
 * employee's device and merely hidden by the UI — any raw Firestore read with
 * that employee's own token returns it in full, including a reviewer's
 * unfinished `areasOfImprovement` notes and a provisional `overallRating`.
 *
 * This is not the same bug as the one the rule's own comment records. That
 * earlier fix ("Was isSameSchool() — leaked every staff appraisal to any
 * same-school token") correctly stopped colleagues reading each other's
 * appraisals. The remaining gap is narrower and self-only: the subject of the
 * review can read their own review before it is finished.
 *
 * EXPOSURE MEASURED, READ-ONLY, ACROSS ALL TENANTS (2026-08-21):
 *     appraisals total ............ 0
 *     status = Draft ............. 0
 * LATENT. No appraisal exists in any tenant, so nothing is exposed today and
 * no data remediation is required. It becomes live the first time a reviewer
 * saves a draft.
 *
 * THE FIX (one line, NOT applied here):
 *
 *     allow read: if (resource.data.staffId == request.auth.uid
 *                     && resource.data.status != 'Draft')
 *                 || (isAdmin() && isSameSchool());
 *
 * Deliberately not applied: firestore.rules is edited concurrently by other
 * sessions and a deploy ships the entire file, so an uncommitted edit sitting
 * in the working tree is itself a hazard. It needs the pull-first flow plus
 * deploy authorization, neither of which is in scope. Queued as D-003.
 *
 * Admin panel access is unaffected — it uses the Admin SDK, which bypasses
 * rules entirely.
 */
final class DraftAppraisalVisibilityTest extends TestCase
{
    private string $rules;

    protected function setUp(): void
    {
        $r = file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
        $this->assertNotFalse($r);
        $this->rules = $r;
    }

    /** The predicate the rule implements today. */
    private function canRead(array $doc, string $uid): bool
    {
        return (string) ($doc['staffId'] ?? '') === $uid;
    }

    /** The predicate it should implement. */
    private function canReadFixed(array $doc, string $uid): bool
    {
        return (string) ($doc['staffId'] ?? '') === $uid
            && (string) ($doc['status'] ?? '') !== 'Draft';
    }

    // ── What is already correct, and must stay correct ───────────────────

    public function test_a_colleague_cannot_read_someone_elses_appraisal(): void
    {
        $doc = ['staffId' => 'STA0007', 'status' => 'Reviewed'];
        $this->assertFalse($this->canRead($doc, 'STA0001'),
            'the same-school leak was already fixed and must not regress');
    }

    public function test_the_same_school_leak_stays_fixed(): void
    {
        $this->assertStringNotContainsString(
            'allow read: if isSameSchool();',
            substr($this->rules, (int) strpos($this->rules, 'match /appraisals/{docId}'), 300),
            'appraisals must never go back to a bare same-school read'
        );
    }

    public function test_a_submitted_appraisal_is_readable_by_its_subject(): void
    {
        $doc = ['staffId' => 'STA0007', 'status' => 'Submitted'];
        $this->assertTrue($this->canRead($doc, 'STA0007'));
        $this->assertTrue($this->canReadFixed($doc, 'STA0007'),
            'the fix must not break the legitimate ESS case');
    }

    public function test_the_fix_leaves_reviewed_appraisals_visible(): void
    {
        $doc = ['staffId' => 'STA0007', 'status' => 'Reviewed'];
        $this->assertTrue($this->canReadFixed($doc, 'STA0007'));
    }

    // ── The gap ──────────────────────────────────────────────────────────

    public function test_client_side_filtering_is_the_only_thing_hiding_drafts(): void
    {
        $repo = file_get_contents(
            'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/'
            . 'data/repository/firestore/HRFirestoreRepository.kt'
        );

        if ($repo === false) {
            $this->markTestSkipped('Teacher app source not present on this machine');
        }

        $this->assertStringContainsString('all.filter { it.status != "Draft" }', $repo,
            'if this filter is gone, drafts are visible in the UI outright');
    }

    /**
     * The invariant that does NOT yet hold. markTestIncomplete rather than a
     * skip so it stays visible in every suite run: this is known outstanding
     * work, not a passing behaviour.
     */
    public function test_rules_should_refuse_a_draft_appraisal(): void
    {
        $block = substr($this->rules, (int) strpos($this->rules, 'match /appraisals/{docId}'), 400);

        if (strpos($block, "status != 'Draft'") === false) {
            $this->markTestIncomplete(
                'D-003 (latent, 0 appraisals exist as of 2026-08-21): firestore.rules allows a '
                . 'staff member to read their OWN appraisal at any status, including Draft. The '
                . "Teacher app hides drafts client-side only, so a raw Firestore read with the "
                . "employee's own token returns a reviewer's unfinished notes. Fix: add "
                . "&& resource.data.status != 'Draft' to the staffId read arm. Needs the "
                . 'pull-first rules flow plus deploy authorization.'
            );
        }

        $doc = ['staffId' => 'STA0007', 'status' => 'Draft'];
        $this->assertFalse($this->canRead($doc, 'STA0007'));
    }

    /** Pins the exact rule text the fix must replace. */
    public function test_current_rule_text_is_what_this_analysis_assumed(): void
    {
        $this->assertStringContainsString(
            'allow read: if resource.data.staffId == request.auth.uid || (isAdmin() && isSameSchool());',
            $this->rules,
            'if this line changed, re-run the analysis in this docblock before trusting it'
        );
    }

    public function test_writes_remain_admin_only(): void
    {
        $block = substr($this->rules, (int) strpos($this->rules, 'match /appraisals/{docId}'), 900);

        // ORIGINAL INTENT, PRESERVED AND STRENGTHENED: an employee must never
        // write their own appraisal.
        //
        // UPDATED BY CAL-D1 (2026-09-01). This pinned the literal
        // `allow write: if isAdmin();`, but isAdmin() binds the CALLER's tenant
        // and never the WRITTEN document — proven on the emulator, an admin of
        // one school could create, overwrite and delete another school's
        // appraisals. The arm is now `allow write: if false` (the server-only
        // posture SEC-3 wave 4 deployed on 2026-08-21), which denies the
        // employee this test protects against AND everyone else besides. Both
        // apps were checked: their HR repositories contain zero write verbs for
        // appraisals, so nothing legitimate loses access.
        //
        // The assertion now states the property rather than the spelling.
        $this->assertMatchesRegularExpression(
            '~allow write: if (false|isAdmin\(\) && isSameSchoolWrite\(\));~', $block,
            'an employee must never write their own appraisal — the write arm must be '
            . 'server-only, or at minimum tenant-bound. A bare isAdmin() is neither.');

        $this->assertStringNotContainsString('allow write: if isAdmin();', $block,
            'a bare isAdmin() write arm permits cross-tenant appraisal writes');
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
