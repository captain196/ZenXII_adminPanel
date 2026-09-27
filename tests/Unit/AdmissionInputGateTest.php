<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADM-INPUT-1 / ADM-DUP-1 / ADM-COMPLETE-1 — regression lock for the admission gate.
 *
 * Source-level guards, because save_admission() cannot be instantiated without a
 * CodeIgniter kernel, a Firestore client and a session. Re-implementing its logic
 * in a test would only prove the copy works.
 *
 * Every scan strips comments first. The comments here deliberately NAME the
 * patterns being forbidden (that is what makes them useful to the next reader),
 * and a naive substring search would match the explanation instead of the code —
 * the exact false-pass class that has bitten this suite before.
 */
final class AdmissionInputGateTest extends TestCase
{
    private const CONTROLLER = __DIR__ . '/../../application/controllers/Sis.php';
    private const VIEW       = __DIR__ . '/../../application/views/studentAdmission.php';

    /** Source with every comment form removed. */
    private function live(string $path): string
    {
        $src = (string) file_get_contents($path);
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);      // block + docblock
        $src = (string) preg_replace('~<!--.*?-->~s', '', $src);     // html
        $src = (string) preg_replace('!^\s*//.*$!m', '', $src);      // line
        $src = (string) preg_replace('!^\s*\*.*$!m', '', $src);      // orphan docblock body
        return $src;
    }

    // ══ 1 · the silent date coercion is gone ═════════════════════════════

    /**
     * `date('d-m-Y', strtotime($raw))` turned an unparseable date into
     * 01-01-1970 and rolled 31-02-2020 forward to 02-03-2020, both without
     * an error. Neither may come back in the admission path.
     */
    public function test_admission_no_longer_coerces_dates_through_strtotime(): void
    {
        // MUTATION-HARDENED. This assertion was originally an exact-string match on
        // the two removed lines, including their original column alignment — so
        // reintroducing the same coercion with one space instead of five walked
        // straight past it. Proven by mutation, not assumed.
        //
        // Banning strtotime() outright from this method body is whitespace-proof,
        // identifier-proof and states the actual rule: admission dates are parsed
        // strictly or refused, never coerced.
        $this->assertStringNotContainsString(
            'strtotime(',
            $this->saveAdmissionBody(),
            'save_admission() is coercing a date through strtotime() again. An '
            . "unparseable value becomes 01-01-1970 (date('d-m-Y', false)) and an "
            . 'impossible one rolls forward (31-02 becomes 02-03), both silently. '
            . 'Use _strictDate().'
        );
    }

    public function test_the_strict_date_parser_exists_and_rejects_rolled_dates(): void
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertStringContainsString(
            'private function _strictDate(string $raw, string $label): array',
            $src
        );
        // The warning count is what separates a real date from a rolled one.
        // checkdate() alone cannot: by then the roll has already happened.
        $this->assertStringContainsString("\$err['warning_count']", $src,
            '_strictDate must inspect the warning count, or 31-02-2020 silently becomes 02-03-2020');
        $this->assertStringContainsString("getLastErrors", $src);
        // PHP 8.2 returns false where 8.1 returns zeroed counts.
        $this->assertStringContainsString('$err === false', $src,
            '_strictDate must handle the PHP 8.2 getLastErrors() contract change');
    }

    // ══ 2 · phone is validated, not waved through ════════════════════════

    /**
     * The old pattern accepted six spaces.
     *
     * Scoped to save_admission(): the same pattern still guards update_profile(),
     * which this change deliberately did not touch. A whole-file assertion would
     * fail on that untouched call site and say nothing true about admission.
     */
    public function test_the_permissive_phone_pattern_is_gone_from_admission(): void
    {
        $this->assertStringNotContainsString(
            '[\d\s\+\-\(\)]{6,20}',
            $this->saveAdmissionBody(),
            'save_admission() accepts the 6-to-20-character phone pattern again, which '
            . 'matches six spaces — while the form enforces ^[6-9]\d{9}$.'
        );
    }

    /** The body of save_admission() alone, comments stripped. */
    private function saveAdmissionBody(): string
    {
        return $this->methodBody('save_admission');
    }

    /**
     * One method's body, comments stripped.
     *
     * Scoping matters more than it looks: this controller is ~12k lines and the
     * same identifiers legitimately recur across the admission, import and
     * enrolment paths. A whole-file scan therefore reports on whichever call site
     * happens to match — which is how the first version of two tests in this class
     * survived a mutation that deleted the code they claimed to guard.
     */
    private function methodBody(string $method): string
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertSame(
            1,
            preg_match_all('~function ' . preg_quote($method, '~') . '\(~', $src),
            "{$method}() must appear exactly once for a body scan to mean anything"
        );

        $start = strpos($src, 'function ' . $method . '(');
        $this->assertNotFalse($start, "{$method}() not found");

        // The next declaration at class indentation ends the body.
        $rest = substr($src, $start + strlen($method) + 10);
        $end  = preg_match('~\n    (?:public|private|protected|final|abstract) function ~',
                           $rest, $m, PREG_OFFSET_CAPTURE)
              ? $m[0][1]
              : strlen($rest);

        $body = substr($rest, 0, $end);
        $this->assertNotSame('', trim($body), "{$method}() body came back empty");

        return $body;
    }

    public function test_phone_is_normalised_to_a_real_indian_mobile(): void
    {
        $src = $this->live(self::CONTROLLER);
        $this->assertStringContainsString(
            'private function _normalisePhone10(string $raw): string', $src);
        $this->assertStringContainsString('/^[6-9]\d{9}$/', $src,
            'the server must enforce the same mobile rule the form does');
    }

    // ══ 3 · a duplicate PERSON is detected ═══════════════════════════════

    /**
     * The rule must stay phone AND name. Phone alone refuses every second
     * sibling (P14-1: indexPhones holds several students per number); name
     * alone refuses unrelated children who share a common name.
     */
    public function test_duplicate_detection_corroborates_phone_with_name(): void
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertStringContainsString(
            'private function _duplicateStudentSuspect(string $nameNorm, string $phone10): ?array',
            $src
        );
        // MUTATION-HARDENED. Both assertions used to scan the WHOLE controller, where
        // the import path calls _studentNameMatches($candidateId, $nameNorm) with
        // identical argument names — so deleting the corroboration from the admission
        // helper still matched the import call site and the test passed. Scope the
        // scan to the helper's own body.
        $body = $this->methodBody('_duplicateStudentSuspect');

        $this->assertStringContainsString('_existingStudentIdsByPhone($phone10)', $body,
            'duplicate detection must reuse the indexed phone lookup, not scan the roster');
        $this->assertStringContainsString('_studentNameMatches($candidateId, $nameNorm)', $body,
            'phone alone is not proof of a duplicate — siblings share a number, which is '
            . 'why P14-1 made indexPhones hold several students per phone');
        $this->assertStringContainsString("!== 'active'", $body,
            'a withdrawn student is not a duplicate — warning on a readmission trains the '
            . 'operator to click through the warning that matters');
    }

    public function test_the_operator_can_override_and_the_override_is_audited(): void
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertStringContainsString("'confirm_required'", $src,
            'a suspected duplicate must be answerable, not a hard refusal');
        $this->assertStringContainsString("post('confirm_duplicate')", $src);
        $this->assertStringContainsString("'admission_duplicate_override'", $src,
            'admitting over a duplicate warning must leave an audit trail');
    }

    /**
     * ROLL-AUDIT-1 was an uncaught TypeError from passing an array as
     * log_audit()'s 4th argument, which is typed string. The new call site
     * must not repeat it.
     */
    public function test_the_override_audit_passes_a_string_description(): void
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertMatchesRegularExpression(
            "~log_audit\(\s*'SIS',\s*'admission_duplicate_override',\s*\\\$userId,\s*sprintf\(~",
            $src,
            "log_audit()'s \$description is typed string; passing the payload array there "
            . 'raises an uncaught TypeError (ROLL-AUDIT-1).'
        );
    }

    // ══ 4 · required fields, both halves ═════════════════════════════════

    public function test_dob_and_phone_are_required_server_side(): void
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertStringContainsString("'Date of birth is required.'", $src);
        $this->assertStringContainsString('Date of birth cannot be in the future.', $src);
        $this->assertStringContainsString('self::ADMISSION_MIN_AGE', $src);
        $this->assertStringContainsString('self::ADMISSION_MAX_AGE', $src);
    }

    public function test_dob_and_phone_are_required_in_the_form(): void
    {
        $src = $this->live(self::VIEW);

        $this->assertMatchesRegularExpression(
            '~<input type="date" name="dob" id="dob"[^>]*required~s',
            $src,
            'the DOB field must carry `required`'
        );
        $this->assertMatchesRegularExpression(
            '~name="phone_number"[^>]*required~s',
            $src,
            'the phone field must carry `required`'
        );
        $this->assertStringContainsString('max="<?= date(\'Y-m-d\') ?>"', $src,
            'the native date picker must not offer a future date');
    }

    /**
     * The claim "only name, class and section are required" was load-bearing
     * in the old client comment. If it returns, the two halves have diverged.
     */
    public function test_the_form_does_not_still_claim_three_required_fields(): void
    {
        $this->assertStringNotContainsString(
            'REQUIRED AT ADMISSION: only Name, Class, Section',
            (string) file_get_contents(self::VIEW),
            'the form still documents the pre-ADM-INPUT-1 contract'
        );
    }

    // ══ 5 · incompleteness is reported ═══════════════════════════════════

    public function test_missing_optional_fields_are_reported_not_swallowed(): void
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertStringContainsString("'pendingProfileFields'", $src,
            'incomplete records must be marked on the student so they can be counted later');
        $this->assertStringContainsString("'pending_fields'", $src,
            'the operator must be told what is missing while the family is still present');
    }

    /** A reporting aid must never fail an admission that already happened. */
    public function test_the_completeness_write_is_best_effort(): void
    {
        $src = $this->live(self::CONTROLLER);
        $this->assertMatchesRegularExpression(
            '~try \{\s*\$this->fs->updateEntity\(\'students\', \$userId, \[\s*\'pendingProfileFields\'~s',
            $src,
            'the pendingProfileFields write must be wrapped — the student, the Auth '
            . 'account and the demands already exist by this point'
        );
    }

    // ══ 6 · no developer artifacts in the school UI ══════════════════════

    /** House rule: the panel never uses window.confirm(). */
    public function test_the_duplicate_dialog_is_not_a_window_confirm(): void
    {
        $src = $this->live(self::VIEW);

        $this->assertStringContainsString('function showDuplicateDialog(res)', $src);
        $this->assertStringNotContainsString('window.confirm(', $src);
        $this->assertStringNotContainsString('confirm(\'They are different', $src);
    }

    // ══ 7 · the edit path is guarded too ═════════════════════════════════

    /**
     * Hardening admission alone was half a fix: across a year far more phone
     * numbers are changed than created, so the edit path is where clean data
     * actually rots. Scoped to update_profile() for the same reason the admission
     * assertion is scoped — to make a true claim about one door.
     */
    public function test_update_profile_normalises_the_phone(): void
    {
        $body = $this->methodBody('update_profile');

        $this->assertStringNotContainsString('[\d\s\+\-\(\)]{6,20}', $body,
            'update_profile() accepts the permissive phone pattern again — a malformed '
            . 'edit silently repoints indexPhones and locks a family out of the Parent app');
        $this->assertStringContainsString('_normalisePhone10(', $body);
        $this->assertStringContainsString("\$updates['Phone Number'] = \$__p10;", $body,
            'the canonical form must be STORED, or indexPhones and its readers disagree');
    }

    // ══ 7b · the phone index cannot hold an unusable key ═════════════════

    /**
     * Four paths write indexPhones and only one validated the number. Normalising
     * at the index itself is what makes a lookup and a write agree: reads go
     * through _existingStudentIdsByPhone() with a normalised 10-digit key, so a row
     * filed under "+91 98765 43210" is invisible — and invisible is exactly what a
     * duplicate check must never be.
     */
    public function test_the_phone_index_normalises_its_key(): void
    {
        $body = $this->methodBody('_registerPhoneIndex');

        $this->assertStringContainsString('_normalisePhone10($phone)', $body,
            'the index must canonicalise its key, or writes and lookups disagree');
        // Fail CLOSED: not indexing degrades detection to "none found"; a wrong row
        // would actively mislead.
        $this->assertMatchesRegularExpression('~\$phone10 === \'\'\s*\)\s*\{.*?return;~s', $body,
            'an unusable number must not be indexed at all');
    }

    /** The CRM path is where a lead becomes a student and the number becomes a key. */
    public function test_crm_enrolment_canonicalises_the_phone(): void
    {
        $this->assertStringContainsString(
            "\$phone = \$this->_normalisePhone10((string) (\$app['phone'] ?? ''));",
            $this->methodBody('enroll_student'),
            'enroll_student() passed the CRM application phone straight into the index'
        );
    }

    /**
     * DELIBERATE NON-CHANGE, recorded so it is not "fixed" later.
     *
     * save_inquiry / save_application / submit_online_form keep the permissive
     * pattern. An ENQUIRY is not a student: a landline or an overseas number is a
     * perfectly good lead, and refusing it turns away admissions. The strict rule
     * belongs where the number becomes an identity key, which is now enforced at
     * _registerPhoneIndex() and enroll_student() instead.
     */
    public function test_lead_capture_stays_permissive_on_purpose(): void
    {
        foreach (['save_inquiry', 'save_application', 'submit_online_form'] as $m) {
            $this->assertStringContainsString('{10,15}', $this->methodBody($m),
                "{$m}() should stay permissive — a lead is not a student. The identity-key "
                . 'rule is enforced at _registerPhoneIndex() and enroll_student().');
        }
    }

    /**
     * A legacy profile must not become unsaveable. The survey found one stored
     * number that cannot be normalised; blocking it would stop an operator editing
     * that child's address over an unrelated bad phone.
     */
    public function test_an_unchanged_bad_legacy_phone_does_not_block_the_edit(): void
    {
        $body = $this->methodBody('update_profile');

        $this->assertStringContainsString('$__stored !== trim((string) $updates[\'Phone Number\'])', $body,
            'only a CHANGED bad value may be refused; re-submitting what is already '
            . 'stored must pass');
        $this->assertStringContainsString('unset($updates[\'Phone Number\'])', $body,
            'a grandfathered bad number must not be re-written as if it were valid');
    }

    /** Present-but-unusable is functionally missing, and must show as such. */
    public function test_an_unusable_phone_counts_as_a_gap(): void
    {
        $this->assertStringContainsString(
            "\$this->_normalisePhone10(\$__ph) === ''",
            $this->methodBody('_profileGaps'),
            'a number that cannot be indexed means the family cannot be found by it — '
            . 'that is a gap, not a filled field'
        );
    }

    // ══ 8 · completeness has exactly one definition ══════════════════════

    /**
     * If the desk and the roster each had their own list they would drift, and the
     * roster would quietly stop counting the thing the desk warned about.
     */
    public function test_one_definition_of_completeness(): void
    {
        $src = $this->live(self::CONTROLLER);

        $this->assertStringContainsString('private function _profileGaps(array $d): array', $src);
        // save_admission reports it; search_student counts and filters on it.
        $this->assertStringContainsString('$this->_profileGaps($studentData)', $src);
        $this->assertStringContainsString('$this->_profileGaps($p)', $src);
        $this->assertStringContainsString('incomplete_total', $src);
    }

    /** Counted over the filtered set — a per-page number would mean nothing. */
    public function test_the_incomplete_count_spans_the_whole_filtered_set(): void
    {
        $this->assertMatchesRegularExpression(
            '~foreach \(array_keys\(\$filtered\) as \$__uid\)~',
            $this->methodBody('search_student'),
            'the incomplete count must span the filtered set, not the current page'
        );
    }

    // ══ 9 · blocked at the point of consequence, not at the counter ══════

    /**
     * A TC is what the next school admits on. Admitting a thin record is right;
     * issuing a certificate from one is not.
     */
    public function test_a_tc_cannot_be_issued_from_an_incomplete_record(): void
    {
        $body = $this->methodBody('issue_tc');

        $this->assertStringContainsString('$__tcGaps', $body);
        $this->assertStringContainsString('Transfer Certificate prints these', $body);
        // The guard must precede the number allocation, or a refusal burns a TC number.
        $this->assertLessThan(
            strpos($body, '_get_tc_number') ?: PHP_INT_MAX,
            strpos($body, '$__tcGaps'),
            'the completeness guard must run BEFORE a TC number is allocated'
        );
    }

    // ══ 10 · age policy is the tenant's, never ours ══════════════════════

    public function test_the_age_policy_is_tenant_configured_and_off_by_default(): void
    {
        $sis = $this->live(self::CONTROLLER);
        $cfg = $this->live(__DIR__ . '/../../application/controllers/School_config.php');

        $this->assertStringContainsString('_ageBandCheck(string $classOrd', $sis);
        $this->assertStringContainsString('admissionAgePolicy', $sis);
        $this->assertStringContainsString('admissionAgePolicy', $cfg);

        // No band hard-coded anywhere: the rule differs by state, board and school.
        $this->assertStringNotContainsString("'Class 1' => ['min' => 6", $sis,
            'an age band must never be hard-coded — it differs by state and board');
    }

    /**
     * The controller must READ the policy and DECIDE nothing.
     *
     * This assertion used to check that _ageBandCheck() contained
     * `$dob->diff($cutoff)`. That was the best a source-guard could manage while
     * the arithmetic lived in a 12k-line controller — and it is a weak thing to
     * know about logic that decides who gets a seat. The date maths now lives in
     * Admission_age_policy, which has no CodeIgniter, Firestore or session
     * dependency, so AdmissionAgePolicyTest exercises the real boundary cases
     * (31 March vs 1 April, session-vs-calendar year, an impossible cut-off).
     *
     * What remains worth guarding here is that the split HOLDS: re-inlining the
     * arithmetic would make it untestable again without any test going red.
     */
    public function test_the_controller_delegates_the_age_decision(): void
    {
        $body = $this->methodBody('_ageBandCheck');

        $this->assertStringContainsString('Admission_age_policy::evaluate(', $body,
            'the age decision must stay in the pure, unit-tested policy class');
        $this->assertStringContainsString('$this->session_year', $body,
            'the session must be passed through — the cut-off follows the academic year');

        // No arithmetic here. If any of these reappear the logic has been
        // re-inlined and AdmissionAgePolicyTest is no longer testing what runs.
        foreach (['->diff(', 'createFromFormat', 'strtotime('] as $arithmetic) {
            $this->assertStringNotContainsString($arithmetic, $body,
                "_ageBandCheck() is doing date arithmetic again ({$arithmetic}) instead of "
                . 'delegating to Admission_age_policy, which is where it can be tested');
        }
    }

    /** The extracted policy must stay free of framework dependencies, or it is untestable. */
    public function test_the_age_policy_class_has_no_framework_dependencies(): void
    {
        $src = $this->live(__DIR__ . '/../../application/libraries/Admission_age_policy.php');

        foreach (['$this->fs', '$this->load', 'get_instance()', 'log_message('] as $dep) {
            $this->assertStringNotContainsString($dep, $src,
                "Admission_age_policy pulled in {$dep} — the whole point of the extraction "
                . 'is that it can be called from a plain unit test with no kernel');
        }
    }

    /** A warn verdict must not behave like a block. */
    public function test_warn_mode_does_not_refuse_the_admission(): void
    {
        $body = $this->saveAdmissionBody();

        $this->assertMatchesRegularExpression(
            "~\\\$ageVerdict\['severity'\] === 'block'~",
            $body,
            'only a block verdict may refuse — otherwise warn is a block wearing a soft word'
        );
        $this->assertStringContainsString('$ageWarning', $body);
    }

    /**
     * A sticky confirmation would disable duplicate detection for every later
     * admission in the same sitting — worse than not having it at all.
     */
    public function test_the_duplicate_confirmation_does_not_persist(): void
    {
        $src = $this->live(self::VIEW);
        $this->assertStringContainsString('window.__zenxConfirmDuplicate = false;', $src,
            'the confirmation flag must be cleared as soon as it is consumed');
    }
}
