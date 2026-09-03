<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Support Desk regression guards.
 *
 * The module had 16 controller methods, ~1900 lines and ZERO unit tests while
 * Homework, Backup and the document engine all had suites. Every defect below
 * was found by executing the product during the 2026-08-31 certification run,
 * and each one was invisible from the front: the mechanism looked correct and
 * the failure produced no error anyone saw.
 *
 * These are source-inspection guards in the same idiom as
 * HomeworkSchoolIdPredicateTest — they defend the SHAPE of the fix, so that a
 * later refactor which quietly reintroduces the bug fails here with an
 * explanation attached rather than shipping silently.
 *
 * Discovered + fixed: certification run 2026-08-31
 * See qa/support-desk/05-risk-register.md R25–R28 and qa/_patterns.md P-13.
 */
class SupportDeskRegressionTest extends TestCase
{
    private string $ctrl;
    private string $js;

    protected function setUp(): void
    {
        $this->ctrl = file_get_contents(__DIR__ . '/../../application/controllers/Support.php');
        $this->js   = file_get_contents(__DIR__ . '/../../assets/js/support_desk.js');
        $this->assertNotFalse($this->ctrl);
        $this->assertNotFalse($this->js);
    }

    /**
     * R25 — supportMessages.createdAt MUST be written as a Firestore Timestamp.
     *
     * The parent app has no choice about the type: firestore.rules requires
     * `createdAt == request.time` on a client create. The panel wrote an ISO
     * STRING, and Firestore orders a mixed-type field BY TYPE FIRST — so every
     * parent message sorted above every staff message regardless of the clock.
     * Live proof: a parent reply sent 29 Aug 02:58 was returned 2nd of 6, above
     * three staff messages from 28 Aug.
     */
    public function test_r25_message_created_at_is_a_timestamp_not_a_string(): void
    {
        $this->assertStringContainsString(
            'private function _ts_value(', $this->ctrl,
            'The Timestamp-typed helper _ts_value() is gone. Without it the panel '
            . 'writes createdAt as a string and mixed-type ordering returns.'
        );
        $this->assertStringContainsString(
            "'createdAt'  => \$nowT,", $this->ctrl,
            '_append_message() must write the Timestamp ($nowT), not the ISO string ($now).'
        );
        $this->assertStringNotContainsString(
            "'createdAt'  => \$now,", $this->ctrl,
            'A string createdAt has come back on the message write — thread order will invert.'
        );
    }

    /**
     * R25 corollary — the string helper stays UTC with a Z suffix so that
     * string-typed fields (lastMessageAt et al) still sort against each other.
     */
    public function test_r25_iso_helper_emits_z_suffixed_utc(): void
    {
        $this->assertStringContainsString("gmdate('Y-m-d\\TH:i:s'", $this->ctrl);
        $this->assertStringContainsString(".'.000Z'", str_replace(' ', '', $this->ctrl));
    }

    /**
     * R27 — assigning a ticket to the person who already holds it must be a
     * no-op. Without this guard a retry after a timed-out assign produced THREE
     * duplicate side effects: a second TICKET_ASSIGNED push (the dedupe key ends
     * in the assignment clock, so the two calls minted different keys), a second
     * audit row reading REASSIGNED with from == to, and a second system message
     * telling the PARENT their ticket had moved when it had not.
     */
    public function test_r27_assign_to_current_assignee_is_a_no_op(): void
    {
        $this->assertStringContainsString(
            "if (\$prev !== '' && \$prev === \$staffId) {", $this->ctrl,
            'The assign idempotence guard is gone — a retried assign will duplicate '
            . 'the push, the audit row and the parent-visible system message.'
        );
        $this->assertStringContainsString("'unchanged' => true", $this->ctrl);

        // The guard must sit BEFORE the push, or it defends nothing.
        $guard = strpos($this->ctrl, "if (\$prev !== '' && \$prev === \$staffId)");
        $push  = strpos($this->ctrl, "_push('TICKET_ASSIGNED'");
        $this->assertNotFalse($guard);
        $this->assertNotFalse($push);
        $this->assertLessThan(
            $push, $guard,
            'The idempotence guard must precede the TICKET_ASSIGNED emit.'
        );
    }

    /**
     * B10 — every ticket mutation goes through _patch_ticket(), which takes the
     * ALREADY-AUTHORISED document. A raw update keyed on a caller-supplied id
     * upserted a GHOST document when the id was untrimmed, so exactly one raw
     * update may exist: the one inside the helper itself.
     */
    public function test_b10_single_raw_ticket_update_inside_the_helper(): void
    {
        $this->assertSame(
            1, substr_count($this->ctrl, "fs->update('supportTickets'"),
            'More than one raw supportTickets update exists. Every mutation must go '
            . 'through _patch_ticket() so a ghost-document write is unexpressible.'
        );
    }

    /**
     * B3 — a FAILED query must never render as "no tickets". Every list endpoint
     * asks the query layer whether the read actually succeeded and fails closed.
     */
    public function test_b3_list_endpoints_fail_closed_on_a_failed_query(): void
    {
        $this->assertGreaterThanOrEqual(
            4, substr_count($this->ctrl, 'lastQueryFailed()'),
            'A list endpoint has stopped checking whether its query failed — a broken '
            . 'read will be presented to staff as an empty queue.'
        );
    }

    /**
     * R28 — SD.postJSON sends SD.csrfName/SD.csrfHash, which default to an EMPTY
     * string in support_desk.js. Only thread.php used to emit the real values, so
     * every POST from the queue and My Tickets shipped an empty token, 403'd, and
     * the helper blamed the user's PERMISSIONS for a missing token.
     */
    public function test_r28_every_support_view_emits_the_csrf_token(): void
    {
        foreach (['index', 'mine', 'thread'] as $view) {
            $src = file_get_contents(__DIR__ . "/../../application/views/support/{$view}.php");
            $this->assertNotFalse($src);
            $this->assertStringContainsString(
                "support/_csrf", $src,
                "views/support/{$view}.php no longer includes the CSRF partial — every "
                . "POST from that page will 403 and report it as an access change."
            );
        }
        $partial = __DIR__ . '/../../application/views/support/_csrf.php';
        $this->assertFileExists($partial);
        $this->assertStringContainsString('get_csrf_hash', file_get_contents($partial));
    }

    /**
     * R28 defence in depth — a READ also adopts a rotated token, so a view that
     * somehow lacks one heals itself on its first data load instead of being
     * silently unable to write. Both getJSON and postJSON must do this.
     */
    public function test_r28_both_helpers_adopt_a_rotated_csrf_token(): void
    {
        $this->assertSame(
            2, substr_count($this->js, 'body.csrf_token) SD.csrfHash = body.csrf_token;'),
            'Both SD.getJSON and SD.postJSON must adopt a rotated CSRF token.'
        );
    }

    /**
     * W4 — the panel must never report success for a write it did not verify.
     * _patch_ticket returns a bool and its callers check it.
     */
    public function test_w4_patch_ticket_returns_and_is_checked(): void
    {
        $this->assertMatchesRegularExpression(
            '/private function _patch_ticket\([^)]*\)\s*:\s*bool/s', $this->ctrl,
            '_patch_ticket must return bool so callers can fail closed.'
        );
        $this->assertStringContainsString('if (!$this->_patch_ticket(', $this->ctrl);
    }

    /**
     * R29 — the attachment endpoint must STREAM, never redirect to a signed URL.
     *
     * A GCS signed URL's path is the object path, and the object path carries
     * the reporter id (schools/{schoolId}/support/{reporterId}/{ticketId}/n.jpg).
     * Redirecting therefore put the reporter's student id in the staff member's
     * address bar — undoing, in one hop, the anonymity that _row() and
     * get_thread enforce field by field on every other surface.
     *
     * The id cannot be dropped from the path: the Storage rule needs it, because
     * attachments upload before the ticket document exists and cannot be
     * authorised by a firestore.get(). So the path stays and must not be exposed.
     */
    public function test_r29_attachment_streams_and_never_exposes_the_storage_path(): void
    {
        $this->assertStringNotContainsString(
            'signedUrl(', $this->ctrl,
            'attachment() is minting a signed URL again. Its path contains the '
            . 'reporterId, so handing it to staff defeats ticket anonymity.'
        );
        $this->assertStringNotContainsString(
            'redirect($url)', $this->ctrl,
            'attachment() is redirecting to storage again — the storage path must '
            . 'never reach the client.'
        );
        $this->assertStringContainsString(
            'downloadAsStream()', $this->ctrl,
            'attachment() must stream the object bytes through the panel, keeping '
            . 'access bound to the session and the RBAC check that already ran.'
        );
        // A shared cache must never hold one family's attachment.
        $this->assertStringContainsString('Cache-Control: private', $this->ctrl);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $this->ctrl);
    }

    /**
     * R29 corollary — anonymity is enforced SERVER-side, not by the view.
     * _row() blanks the identifying fields and get_thread blanks senderName on
     * parent-authored messages, so a payload captured in devtools carries no
     * name even though the client also hides them.
     */
    public function test_r29_anonymity_is_enforced_server_side(): void
    {
        foreach (["'studentName'", "'className'", "'reporterName'"] as $field) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($field, '/') . '\s*=>\s*\$anon \?/', $this->ctrl,
                "$field must be blanked server-side when the ticket is anonymous."
            );
        }
        $this->assertStringContainsString(
            "'senderName'  => (\$isParent && \$anon) ? ''", $this->ctrl,
            'A parent-authored message must not carry a name on an anonymous ticket.'
        );
    }
}
