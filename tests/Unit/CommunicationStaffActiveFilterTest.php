<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Communication bulk-send staff recipient filter — closes a gap found during
 * the Staff Lifecycle completeness audit: the student-recipient query in
 * send_bulk() already filtered to status=='Active', but the parallel
 * staff-recipient query had no status filter at all, so a deactivated/
 * separated staff member stayed in the raw recipient list at the query
 * level (only masked downstream by unrelated userDevices cleanup
 * suppressing actual push delivery, not by any explicit staff-status check
 * here). Mirrors the pre-existing student-side pattern exactly.
 *
 * Source-regression test (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock).
 */
class CommunicationStaffActiveFilterTest extends TestCase
{
    private string $body;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Communication.php');
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
        $src = $out;

        $start = strpos($src, 'function send_bulk(');
        $this->assertNotFalse($start, 'send_bulk() must exist.');
        $openBrace = strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) { $this->body = substr($src, $openBrace, $i - $openBrace + 1); return; }
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    public function test_staff_recipient_query_now_filters_to_active_status(): void
    {
        $this->assertStringContainsString(
            "\$staffDocs = \$this->fs->schoolWhere('staff', [['status', '==', 'Active']]);",
            $this->body
        );
    }

    public function test_student_recipient_filter_unchanged(): void
    {
        $this->assertStringContainsString(
            "\$studentDocs = \$this->fs->schoolWhere('students', [['status', '==', 'Active']]);",
            $this->body
        );
    }

    public function test_both_queries_remain_school_scoped_via_schoolwhere(): void
    {
        $this->assertStringContainsString("schoolWhere('students',", $this->body);
        $this->assertStringContainsString("schoolWhere('staff',", $this->body);
    }
}
