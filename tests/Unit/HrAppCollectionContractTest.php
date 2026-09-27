<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Collection-name contract between the admin panel and the two apps for the
 * HR recruitment / training surfaces.
 *
 * SWEPT 2026-08-21 (Phase G). The admin panel is the only writer; the apps are
 * read-only consumers. A wrong collection name on the app side does not error —
 * the query simply matches nothing, or is refused by the catch-all
 * `match /{document=**} { allow read, write: if false; }` at the end of
 * firestore.rules. Either way the screen renders empty and nothing is logged.
 *
 * CANONICAL WRITERS (admin):
 *   hrJobs         job openings          Ats.php + Hr.php   — app-readable by rule
 *   hrApplicants   candidate PII         Ats.php            — isAdmin() ONLY, never app
 *   staffTraining  training sessions     Hr.php             — NO RULE AT ALL
 *
 * APP CONSUMERS, as they stand:
 *   Teacher  RECRUITMENTS = "hrJobs"     CORRECT — live screen, filters
 *                                        statusLower == 'open', which is exactly
 *                                        the field the writer emits for it.
 *   Teacher  TRAININGS    = "trainings"  WRONG — admin writes `staffTraining`.
 *                                        Currently harmless: repository-only,
 *                                        no caller anywhere in the app.
 *   Parent   RECRUITMENT  = "recruitments"  WRONG — dead code, no ViewModel/screen.
 *   Parent   TRAINING     = "trainings"      WRONG — dead code, no ViewModel/screen.
 *
 * So there is NO live broken feature. What exists is a latent trap: three dead
 * repositories carrying collection names that do not resolve. The moment anyone
 * wires a training screen it returns empty, and the cause is two repos away from
 * the symptom. The same shape as the Parent AppraisalDoc / SalarySlipDoc models,
 * which are likewise dead but wired to nothing.
 *
 * Not "fixed" here for two reasons: correcting the constant alone would still
 * not work, because `staffTraining` has no Firestore rule and the catch-all
 * denies it; and the Parent app has no training surface to serve. Enabling app
 * training is a product decision plus a rules change, not a rename.
 *
 * This test pins the admin side — the half this repository owns — so the
 * canonical names cannot drift silently underneath the apps.
 */
final class HrAppCollectionContractTest extends TestCase
{
    private string $ats;
    private string $hr;
    private string $rules;

    protected function setUp(): void
    {
        $ats   = file_get_contents(__DIR__ . '/../../application/controllers/Ats.php');
        $hr    = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $rules = file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
        $this->assertNotFalse($ats);
        $this->assertNotFalse($hr);
        $this->assertNotFalse($rules);
        $this->ats   = $ats;
        $this->hr    = $hr;
        $this->rules = $rules;
    }

    // ── Canonical writer names ───────────────────────────────────────────

    public function test_job_openings_are_written_to_hrjobs(): void
    {
        $this->assertStringContainsString("schoolWhere('hrJobs', [])", $this->ats);
        $this->assertStringContainsString("schoolList('hrJobs')", $this->hr);
    }

    public function test_applicants_are_written_to_hrapplicants(): void
    {
        $this->assertStringContainsString("setEntity('hrApplicants'", $this->ats);
    }

    public function test_training_is_written_to_stafftraining(): void
    {
        $this->assertStringContainsString("setEntity('staffTraining'", $this->hr);
        $this->assertStringContainsString("schoolWhere('staffTraining'", $this->hr);
    }

    /**
     * The Teacher app filters `statusLower == 'open'`, not `status`. The writer
     * must keep emitting that lowercase mirror or the openings list goes empty
     * while the admin grid still looks correct.
     */
    public function test_the_writer_emits_the_lowercase_status_the_app_queries(): void
    {
        $this->assertStringContainsString("'statusLower'", $this->hr,
            "Teacher getRecruitmentOpenings() filters on statusLower — dropping it "
            . 'empties the app screen with no admin-side symptom');
    }

    // ── Rule posture for each collection ─────────────────────────────────

    public function test_hrjobs_is_app_readable(): void
    {
        $i = strpos($this->rules, 'match /hrJobs/{docId}');
        $this->assertNotFalse($i, 'hrJobs must keep a rule or the Teacher screen breaks');
        $block = substr($this->rules, (int) $i, 400);

        $this->assertStringContainsString('allow read: if isSameSchool();', $block);
    }

    /** Candidate PII must never become app-readable. */
    public function test_hrapplicants_stays_admin_only(): void
    {
        $i = strpos($this->rules, 'match /hrApplicants/{docId}');
        $this->assertNotFalse($i);
        $block = substr($this->rules, (int) $i, 500);

        $this->assertStringContainsString('allow read: if isAdmin() && isSameSchool();', $block,
            'applicant contact details must not be exposed to any app token');
        $this->assertStringNotContainsString('allow read: if isSameSchool();', $block);
    }

    /** Both write arms must be tenant-bound, not merely admin-gated. */
    public function test_recruitment_writes_are_bound_to_the_writers_own_tenant(): void
    {
        foreach (['hrJobs', 'hrApplicants'] as $col) {
            $i = strpos($this->rules, "match /{$col}/{docId}");
            $this->assertNotFalse($i);
            $block = substr($this->rules, (int) $i, 1200);

            $this->assertStringContainsString(
                'allow create, update: if isAdmin() && isSameSchoolWrite();',
                $block,
                "an isAdmin()-only write arm lets an admin of school A overwrite school B's {$col}"
            );
        }
    }

    /**
     * The catch-all is what turns a wrong collection name into a silent empty
     * result rather than a loud error. It must stay deny-all.
     */
    public function test_the_catch_all_denies_everything(): void
    {
        $this->assertStringContainsString(
            "match /{document=**} {\n      allow read, write: if false;",
            str_replace("\r\n", "\n", $this->rules),
            'an unmatched collection must fail closed'
        );
    }

    // ── The recorded trap ────────────────────────────────────────────────

    /**
     * `staffTraining` has no rule, so no app can read it regardless of the
     * constant it uses. Recorded as incomplete rather than skipped so it stays
     * visible: enabling app training needs BOTH a rules block and the app-side
     * constant corrected to `staffTraining`.
     */
    public function test_app_training_would_need_a_rule_before_it_could_work(): void
    {
        if (strpos($this->rules, 'match /staffTraining/{') === false) {
            $this->markTestIncomplete(
                'LATENT (no live caller as of 2026-08-21): admin writes training to '
                . '`staffTraining`, but both apps declare `trainings` AND `staffTraining` has no '
                . 'Firestore rule, so the catch-all denies it. Harmless today — the training '
                . 'repositories are dead code with no ViewModel or screen in either app. Wiring '
                . 'a training screen requires a rules block AND correcting the app constant; '
                . 'the rename alone would still return empty.'
            );
        }

        $this->assertStringContainsString('match /staffTraining/{', $this->rules);
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
