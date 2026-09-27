<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/helpers/recruitment_helper.php';

/**
 * ZenX HRMS W1.3 — recruitment stage↔status bridge.
 *
 * WHY THIS EXISTS
 * ---------------
 * Ats.php and Hr.php write the SAME Firestore document (`hrApplicants`) using
 * two different vocabularies: ATS thinks in `stage`, HR thinks in `status`.
 * Before W1.3 the mapping between them existed in FOUR private copies — two in
 * Ats.php (`HR_STATUS_MAP` and a separate, non-inverse `_normalize_stage()`
 * table) and one in Hr.php's dashboard, plus HR's own `$validStatuses` list.
 * Nothing forced them to agree.
 *
 * They now all resolve through recruitment_helper.php, and these are real
 * behavioural tests of it — the functions are pure, so unlike the controller
 * tests in this suite they execute the actual code rather than inspecting its
 * source.
 *
 * The round-trip test is the one that matters: if stage→status→stage ever stops
 * being the identity, advancing a candidate in ATS would move them to a
 * different stage than the one the operator clicked.
 */
class RecruitmentVocabularyBridgeTest extends TestCase
{
    /** Every ATS stage must survive a round-trip through the HR vocabulary. */
    public function test_stage_survives_round_trip_through_hr_status(): void
    {
        foreach (recruitment_stages() as $stage) {
            $status = recruitment_status_from_stage($stage);
            $this->assertSame(
                $stage,
                recruitment_stage_from_status($status),
                "Stage '{$stage}' did not survive the round-trip (became status '{$status}'). "
                . "A candidate advanced in ATS would land in the wrong column."
            );
        }
    }

    /** Every HR status a user can select must project onto a real ATS stage. */
    public function test_every_hr_status_maps_to_a_known_stage(): void
    {
        $stages = recruitment_stages();
        foreach (recruitment_statuses() as $status) {
            $stage = recruitment_stage_from_status($status);
            $this->assertContains(
                $stage,
                $stages,
                "HR status '{$status}' mapped to '{$stage}', which is not an ATS stage."
            );
        }
    }

    /**
     * The writer direction must be one-to-one: two stages sharing a status
     * would make the round-trip above ambiguous.
     */
    public function test_stage_to_status_is_injective(): void
    {
        $seen = [];
        foreach (recruitment_stages() as $stage) {
            $status = recruitment_status_from_stage($stage);
            // Build the message from what is known NOW — PHP evaluates the
            // argument eagerly, so referencing $seen[$status] here would notice
            // an undefined key on every PASSING iteration.
            $clash = $seen[$status] ?? null;
            $this->assertArrayNotHasKey(
                $status,
                $seen,
                "Stages '{$clash}' and '{$stage}' both write status '{$status}' — "
                . "the stage→status round-trip is then ambiguous."
            );
            $seen[$status] = $stage;
        }
    }

    /**
     * The reader direction is deliberately many-to-one: it must accept both
     * vocabularies, because it reads documents written by either module across
     * their entire history.
     */
    public function test_reader_accepts_both_vocabularies(): void
    {
        $this->assertSame('Interviewed', recruitment_stage_from_status('Interview'),   'HR spelling');
        $this->assertSame('Interviewed', recruitment_stage_from_status('Interviewed'), 'ATS spelling');
        $this->assertSame('Hired',       recruitment_stage_from_status('Joined'),      'HR spelling');
        $this->assertSame('Hired',       recruitment_stage_from_status('Hired'),       'ATS spelling');
    }

    /** Unknown / legacy / empty values must floor to Applied, never throw. */
    public function test_unknown_values_floor_to_applied(): void
    {
        foreach (['', 'Nonsense', 'applied', 'HIRED', 'Withdrawn'] as $junk) {
            $this->assertSame(
                'Applied',
                recruitment_stage_from_status($junk),
                "Value '{$junk}' must floor to Applied — this runs against documents "
                . "written by older code paths and may never throw."
            );
        }
    }

    /**
     * 'Rejected' has no stage of its own. It must floor rather than claim a
     * pipeline position, and callers must rely on `ats_status` instead — which
     * is why Hr.php skips the `stage` write for this status specifically.
     */
    public function test_rejected_has_no_stage_and_is_carried_by_ats_status(): void
    {
        $this->assertSame('Applied', recruitment_stage_from_status('Rejected'));
        $this->assertSame('rejected', recruitment_ats_status_from_status('Rejected'));
    }

    /**
     * The ats_status derivation drives real gates in Ats.php (a hired candidate
     * cannot be deleted; a rejected one cannot be advanced), so each mapping is
     * pinned explicitly.
     */
    public function test_ats_status_derivation(): void
    {
        $this->assertSame('active', recruitment_ats_status_from_status('Applied'));
        $this->assertSame('active', recruitment_ats_status_from_status('Shortlisted'));
        $this->assertSame('active', recruitment_ats_status_from_status('Interview'));
        $this->assertSame('active', recruitment_ats_status_from_status('Selected'));
        $this->assertSame('hired',  recruitment_ats_status_from_status('Joined'));

        // null means "this status carries no lifecycle meaning" — callers must
        // then leave ats_status untouched rather than clobber a value ATS owns.
        $this->assertNull(recruitment_ats_status_from_status(''));
        $this->assertNull(recruitment_ats_status_from_status('Nonsense'));
    }

    /** The pipeline order is load-bearing: Ats::_validate_transition() uses index order. */
    public function test_stage_order_is_stable(): void
    {
        $this->assertSame(
            ['Applied', 'Shortlisted', 'Interviewed', 'Selected', 'Hired'],
            recruitment_stages(),
            "Ats::_validate_transition() derives 'forward' from this order — "
            . "reordering it silently changes which moves are legal."
        );
    }

    /**
     * Hr::update_applicant_status() validates posted values against its own
     * hardcoded list. If that list and this one drift, a status HR accepts
     * could have no ATS meaning.
     */
    public function test_helper_status_list_matches_the_validator_in_hr(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $ok = preg_match('/\$validStatuses\s*=\s*\[(.*?)\]\s*;/s', $src, $m);
        $this->assertSame(1, $ok, 'Could not locate $validStatuses in Hr.php.');

        preg_match_all("/'([A-Za-z]+)'/", $m[1], $found);
        $hrList = $found[1];
        sort($hrList);

        $helperList = recruitment_statuses();
        sort($helperList);

        $this->assertSame(
            $helperList,
            $hrList,
            "Hr.php's \$validStatuses no longer matches recruitment_statuses(). "
            . "A status accepted by HR but unknown to the bridge floors to "
            . "'Applied' in ATS, silently moving the candidate backwards."
        );
    }
}
