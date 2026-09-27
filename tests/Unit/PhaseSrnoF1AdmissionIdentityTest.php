<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 * SRNO-F1 — admission must not succeed without an SRNO
 * ══════════════════════════════════════════════════════════════════════════
 *
 * PRODUCT INVARIANT (operator, 2026-08-17):
 *
 *     student creation + SRNO allocation = successful admission
 *
 * `studentId` is the global system identity; SRNO is the school-scoped
 * OPERATIONAL identity schools actually work by. An admitted student without an
 * SRNO is not a complete student.
 *
 * THE DEFECT. `_claimIdentityAttributes()` treated an SRNO allocation failure —
 * and an exception — as a WARNING, and `save_admission()` appended that warning
 * to a SUCCESS response. The code said so in its own message text: "The
 * admission was completed; SRNO requires administrator follow-up."
 *
 * THE PRECEDENT. Import already gets this right and is fail-closed: a failed
 * `allocateNext` DELETES the just-created student and RELEASES the Student ID
 * (pinned by Phase203kStep0ImportHarnessTest::test_GOLDEN_03_...). Admission is
 * the path that disagreed; this aligns it.
 *
 * WHY THE FAIL POINT IS SAFE. In save_admission() the SRNO claim runs BEFORE
 * the Firebase Auth user, before the entitlement, before fee generation, before
 * history/audit and before the admission-fee receipt. Nothing financial and no
 * credential exists yet, so failing there is clean.
 *
 * ── NO I/O ──────────────────────────────────────────────────────────────────
 * Source is read with comments STRIPPED, so no comment can satisfy an
 * assertion. No Firestore, no tenant, no admission, no writes.
 */
class PhaseSrnoF1AdmissionIdentityTest extends TestCase
{
    private const SIS = __DIR__ . '/../../application/controllers/Sis.php';

    private function src(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(self::SIS)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    private function body(string $name): string
    {
        $s = $this->src();
        $start = strpos($s, "function {$name}(");
        $this->assertNotFalse($start, "{$name}() not found.");
        $end = strpos($s, 'function ', $start + strlen($name) + 12);
        return substr($s, $start, ($end === false ? strlen($s) : $end) - $start);
    }

    private function flat(string $name): string
    {
        return preg_replace('/\s+/', '', $this->body($name));
    }

    /** Character offset of a needle inside save_admission(), for ordering proofs. */
    private function posIn(string $method, string $needle): int
    {
        $p = strpos($this->flat($method), $needle);
        $this->assertNotFalse($p, "'{$needle}' not found in {$method}().");
        return $p;
    }

    // ══════════════════════════════════════════════════════════════════
    //  The helper must SIGNAL failure, not just describe it
    // ══════════════════════════════════════════════════════════════════

    /** F1-01 — the helper reports whether the SRNO was actually assigned. */
    public function test_SRNO_F1_01_helper_signals_allocation_failure(): void
    {
        $f = $this->flat('_claimIdentityAttributes');

        $this->assertStringContainsString('bool&$srnoAssigned', $f,
            'The helper must expose whether SRNO allocation SUCCEEDED, not only a human-readable '
            . 'warning. A caller cannot enforce an identity invariant against prose.');
        $this->assertSame(2, substr_count($f, '$srnoAssigned=false;'),
            'Both failure paths must clear it: the ok===false branch AND the catch. An exception is '
            . 'no less a failure than a returned error.');
    }

    /** F1-02 — the misleading "admission was completed" claim is gone. */
    public function test_SRNO_F1_02_helper_no_longer_claims_admission_completed(): void
    {
        $f = $this->flat('_claimIdentityAttributes');
        $this->assertStringNotContainsString('Theadmissionwascompleted;SRNOrequiresadministratorfollow-up.', $f,
            'The helper must not assert that the admission completed — that is the caller\'s '
            . 'decision, and under the invariant it is now false when SRNO failed.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  Admission must act on the signal
    // ══════════════════════════════════════════════════════════════════

    /** F1-03 — save_admission() checks the signal and refuses. */
    public function test_SRNO_F1_03_admission_fails_when_srno_not_assigned(): void
    {
        $f = $this->flat('save_admission');

        $this->assertStringContainsString('$srnoAssigned=true;', $f,
            'The caller initialises the flag …');
        $this->assertStringContainsString('$srnoAssigned)', $f,
            '… passes it by reference and then branches on it.');
        $this->assertStringContainsString('if(!$srnoAssigned){', $f,
            'Admission must REFUSE when the SRNO was not assigned.');
        $this->assertStringContainsString('ACC_ADMISSION_SRNO_FAILED', $f,
            'and the refusal must be greppable in the log.');
    }

    /**
     * F1-04 — the compensating rollback mirrors the proven Import contract:
     * delete the student, release the Student ID.
     */
    public function test_SRNO_F1_04_rollback_matches_the_import_contract(): void
    {
        $f = $this->flat('save_admission');
        $branch = substr($f, strpos($f, 'if(!$srnoAssigned){'));

        $this->assertStringContainsString('$this->entity_sync->deleteStudent($userId);', $branch,
            'The just-created student must not survive a failed identity transaction …');
        $this->assertStringContainsString("releaseClaim('STU'", $branch,
            '… and the Student ID must go back to the counter, exactly as Import does.');
    }

    /** F1-05 — the Student ID released is the numeric part, as Import releases it. */
    public function test_SRNO_F1_05_student_id_lifecycle_correct_after_rollback(): void
    {
        $f = $this->flat('save_admission');
        $branch = substr($f, strpos($f, 'if(!$srnoAssigned){'));

        $this->assertStringContainsString("releaseClaim('STU',(int)preg_replace('/\\D/','',\$userId))", $branch,
            'Identical extraction to the Import rollback — the claim doc is keyed by the numeric '
            . 'value, so releasing anything else would leak the number.');
    }

    /**
     * F1-06 — section strength is recomputed after the deletion. save_admission()
     * bumped it when the student was written; leaving it inflated would corrupt
     * the capacity data the atomic-capacity guard depends on.
     */
    public function test_SRNO_F1_06_section_strength_is_repaired_after_rollback(): void
    {
        $f = $this->flat('save_admission');
        $branch = substr($f, strpos($f, 'if(!$srnoAssigned){'));

        $this->assertStringContainsString('_recompute_section_strength(', $branch,
            'The same method inflated currentStrength earlier in this request; the compensation '
            . 'must put it back, or a refused admission permanently consumes a seat.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  Nothing financial may have happened yet
    // ══════════════════════════════════════════════════════════════════

    /**
     * F1-07 — the refusal happens BEFORE the Auth user, the entitlement, fee
     * generation, history/audit and the admission-fee receipt. That ordering is
     * what makes the fail point safe, so it is asserted, not assumed.
     */
    public function test_SRNO_F1_07_refusal_precedes_every_financial_and_credential_write(): void
    {
        $srnoCheck = $this->posIn('save_admission', 'if(!$srnoAssigned){');

        foreach ([
            '_createFirebaseAuthStudent(' => 'Firebase Auth user',
            '_resolve_admission_entitlement(' => 'entitlement resolution',
            'assignInitialFees(' => 'fee demand generation',
            '_log_history(' => 'admission history',
            '_admission_fee_apply(' => 'admission-fee receipt + accounting',
        ] as $needle => $what) {
            $this->assertGreaterThan($srnoCheck, $this->posIn('save_admission', $needle),
                "The SRNO refusal must come BEFORE {$what}; otherwise a rollback would have to undo it.");
        }
    }

    /** F1-08 — billing is never reported after an identity failure. */
    public function test_SRNO_F1_08_billing_not_reported_when_identity_fails(): void
    {
        $f = $this->flat('save_admission');
        $branch = substr($f, strpos($f, 'if(!$srnoAssigned){'));
        $end = strpos($branch, 'json_error');
        $this->assertNotFalse($end, 'The branch must terminate the request with an error …');

        $upToReturn = substr($branch, 0, $end);
        $this->assertStringNotContainsString('billing_state', $upToReturn,
            '… and must not have produced any billing state — no demand was generated.');
        $this->assertStringNotContainsString('json_success', $upToReturn);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Existing behaviour preserved
    // ══════════════════════════════════════════════════════════════════

    /** F1-09 — the success path is untouched: SRNO assigned ⇒ admission proceeds. */
    public function test_SRNO_F1_09_successful_allocation_still_admits(): void
    {
        $f = $this->flat('save_admission');
        foreach ([
            '_placeStudentGuarded(',
            '_claimIdentityAttributes(',
            '_createFirebaseAuthStudent(',
            'assignInitialFees(',
            '_log_history(',
            'json_success(',
        ] as $needle) {
            $this->assertStringContainsString($needle, $f,
                "The normal admission path must be unchanged — {$needle} is still present.");
        }
    }

    /** F1-10 — APAAR and Admission Number remain warnings, not hard failures. */
    public function test_SRNO_F1_10_apaar_and_admission_number_still_warn_only(): void
    {
        $f = $this->flat('_claimIdentityAttributes');

        $this->assertStringContainsString('AdmissionNumbercouldnotbegeneratedautomatically', $f,
            'Admission Number remains a warning — the invariant the operator declared covers SRNO, '
            . 'and widening it unasked would be redesigning unrelated behaviour.');
        $this->assertStringContainsString('$warnings[]', $f,
            'The warning channel itself is retained for the identity attributes that are not '
            . 'invariant-bearing.');
    }

    /**
     * F1-11 — enroll_student() is NOT changed, and the reason is structural:
     * there `_claimIdentityAttributes()` runs AFTER the Auth user and AFTER fee
     * generation, so failing closed would require undoing a credential and real
     * demands. Registered as SRNO-F2 rather than fixed under a "narrow" mandate.
     */
    public function test_SRNO_F1_11_enroll_student_registered_as_SRNO_F2_not_silently_changed(): void
    {
        $f = $this->flat('enroll_student');

        $authPos  = strpos($f, '_createFirebaseAuthStudent(');
        $feesPos  = strpos($f, 'assignInitialFees(');
        $srnoPos  = strpos($f, '_claimIdentityAttributes(');

        $this->assertNotFalse($srnoPos);
        $this->assertNotFalse($authPos);
        $this->assertNotFalse($feesPos);
        $this->assertGreaterThan($authPos, $srnoPos,
            'SRNO-F2: in enroll_student the SRNO claim happens AFTER the Auth user …');
        $this->assertGreaterThan($feesPos, $srnoPos,
            '… and AFTER fee generation, so the same fail-closed fix is NOT a narrow change there. '
            . 'Registered for separate authorisation; deliberately untouched.');
    }
}
