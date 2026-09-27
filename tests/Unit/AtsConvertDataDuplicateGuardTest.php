<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ATS → Staff conversion duplicate-guard — closes a gap found during the
 * Staff Lifecycle completeness audit: Ats::get_convert_data() checked stage
 * and ats_status but never checked whether the applicant was already linked
 * to a staff record. Since ats_status can, per an existing code comment on
 * finalize_hire()'s 'Joined' stage handling, go stale without being set to
 * 'hired', re-opening the Convert-to-Staff form for an already-linked
 * applicant could produce a second, independent staff doc for the same
 * person — violating this codebase's "no duplicate staff identity"
 * principle (the same principle Rehire was built to preserve).
 *
 * Source-regression test (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock).
 */
class AtsConvertDataDuplicateGuardTest extends TestCase
{
    private string $atsSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Ats.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->atsSrc = $out;
    }

    private function convertDataBody(): string
    {
        $start = strpos($this->atsSrc, 'function get_convert_data(');
        $this->assertNotFalse($start, 'get_convert_data() must exist.');
        $openBrace = strpos($this->atsSrc, '{', $start);
        $depth = 0;
        $len = strlen($this->atsSrc);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($this->atsSrc[$i] === '{') $depth++;
            elseif ($this->atsSrc[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($this->atsSrc, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    public function test_rejects_when_applicant_already_linked_to_a_staff_record(): void
    {
        $body = $this->convertDataBody();
        $this->assertStringContainsString("trim((string) (\$applicant['staff_id'] ?? '')) !== ''", $body);
        $this->assertStringContainsString('already linked to a staff record', $body);
    }

    public function test_guard_checked_before_job_lookup_and_prefill_response(): void
    {
        $body = $this->convertDataBody();
        $guardPos = strpos($body, "trim((string) (\$applicant['staff_id'] ?? '')) !== ''");
        $jobLookupPos = strpos($body, "\$jobId    = \$applicant['job_id']");
        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($jobLookupPos);
        $this->assertLessThan($jobLookupPos, $guardPos,
            'The duplicate-link guard must run before any job lookup or prefill data is built.');
    }

    public function test_does_not_weaken_the_existing_stage_and_ats_status_guards(): void
    {
        $body = $this->convertDataBody();
        $this->assertStringContainsString("Only candidates at the Selected stage can be converted to staff.", $body);
        $this->assertStringContainsString("This candidate has already been hired.", $body);
        $this->assertStringContainsString("This candidate has been rejected", $body);
    }

    public function test_no_new_endpoint_or_collection_introduced(): void
    {
        $this->assertStringNotContainsString('function guard_convert_data', $this->atsSrc);
        $this->assertStringNotContainsString('staffLinks', $this->atsSrc);
    }
}
