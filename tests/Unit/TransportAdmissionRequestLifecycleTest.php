<?php
/**
 * Direct-admission -> Transport request lifecycle (2026-08-30).
 *
 * WHAT THIS PINS. A student admitted through Sis::save_admission who declares
 * transport must land on the SAME canonical worklist every other request uses —
 * transportRequests, status pending, source 'admission' — and must not be
 * enrolled or billed by the act of asking.
 *
 * Before this wiring the transport declaration on the direct-admission form was
 * captured and dropped: the family said they wanted transport, nobody in
 * Operations ever saw it, and the first anyone knew was a parent phoning about
 * a bus that never came.
 *
 * WHY THESE ASSERTIONS AND NOT MORE. The approval half of the lifecycle
 * (assignStudent, capacity, fee) is older, frozen and already covered. What is
 * new — and therefore what can regress — is the wiring at the admission end and
 * the one defect that made the whole chain unapprovable, TRN-STOP-STATUS-1.
 *
 * The end-to-end behaviour was proven by execution against the emulator, not
 * inferred here: admission raised the request, approval created exactly ONE
 * Transport demand linked by fee_demand_ref, rejection created neither an
 * enrolment nor a fee, and a duplicate request was refused as already_pending.
 * These tests are the regression net under that run.
 */

use PHPUnit\Framework\TestCase;

final class TransportAdmissionRequestLifecycleTest extends TestCase
{
    private const SIS        = __DIR__ . '/../../application/controllers/Sis.php';
    private const CONTROLLER = __DIR__ . '/../../application/controllers/Transport.php';
    private const SERVICE    = __DIR__ . '/../../application/libraries/Transport_service.php';
    private const VIEW       = __DIR__ . '/../../application/views/studentAdmission.php';

    private function src(string $f): string { return (string) file_get_contents($f); }

    /**
     * One method's source, bounded by the NEXT method rather than by a byte
     * count. A fixed window silently stops covering the line it was written to
     * pin the moment a comment above it grows — the assertion then passes on a
     * truncated string and proves nothing.
     */
    private function methodBody(string $file, string $signature): string
    {
        $s  = $this->src($file);
        $at = strpos($s, $signature);
        $this->assertNotFalse($at, "{$signature} no longer exists in {$file}");

        $next = preg_match('/\n    (?:public|private|protected) function /', $s, $m,
                           PREG_OFFSET_CAPTURE, $at + strlen($signature))
              ? $m[0][1] : strlen($s);

        return substr($s, $at, $next - $at);
    }

    /**
     * The admission handler's transport block, bounded by the try/catch it
     * actually lives in rather than by a character count.
     *
     * 2026-09-14 — was substr($s, $at - 400, 2200). The catch that closes this
     * block sat ~135 characters inside that budget, so two or three added lines
     * would have pushed it out and failed the test while the code stayed correct
     * — the same way the Phase 20.3h seat-guard assertion broke. This class's own
     * docblock warns about exactly that, and provides methodBody() for it; this
     * helper had not been converted.
     *
     * Comments are stripped HERE rather than in src(), which four other
     * assertions in this file share: a comment must be able neither to satisfy an
     * assertion nor to push real code out of range.
     */
    private function admissionTransportBlock(): string
    {
        $s = '';
        foreach (token_get_all((string) file_get_contents(self::SIS)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $s .= $t[1];
            } else { $s .= $t; }
        }

        $at = strpos($s, 'decl_transport');
        $this->assertNotFalse($at, 'the admission handler no longer reads the transport declaration');

        $try = strrpos(substr($s, 0, $at), 'try {');
        $this->assertNotFalse($try,
            'the transport block is no longer inside a try — a transport failure would '
            . 'abort the admission that already succeeded');

        // Brace-match the try body, then the catch body that follows it.
        $i = strpos($s, '{', $try);
        $end = $this->matchBrace($s, $i);
        $catchAt = strpos($s, 'catch', $end);
        if ($catchAt !== false && $catchAt - $end < 40) {
            $cOpen = strpos($s, '{', $catchAt);
            if ($cOpen !== false) $end = $this->matchBrace($s, $cOpen);
        }
        return substr($s, $try, $end - $try);
    }

    /** Index just past the brace matching the one at $open. */
    private function matchBrace(string $s, int $open): int
    {
        $depth = 0; $i = $open; $len = strlen($s);
        while ($i < $len) {
            if ($s[$i] === '{') { $depth++; }
            elseif ($s[$i] === '}') { $depth--; if ($depth === 0) return $i + 1; }
            $i++;
        }
        return $len;
    }

    // == THE DECLARATION REACHES THE WORKLIST ================================

    public function test_a_declared_transport_need_raises_a_canonical_request(): void
    {
        $block = $this->admissionTransportBlock();

        $this->assertStringContainsString('->requestTransport(', $block,
            'admission must go through the canonical requestTransport() lifecycle. A second '
            . 'request model, or a direct write to transportRequests, would fork the worklist.');
        $this->assertStringContainsString("'admission'", $block,
            "the request must be attributed to source 'admission' so Operations can tell an "
            . 'admission-time need from a mid-session one');
    }

    /**
     * THE INVARIANT THAT PROTECTS MONEY. Asking for transport is not buying it.
     * The fee is raised at approval by assignStudent(), once a route with a
     * fare and a stop have been chosen.
     */
    public function test_admission_never_creates_an_enrolment_or_a_fee(): void
    {
        $block = $this->admissionTransportBlock();

        // Call syntax, not bare names. Both admission paths carry comments that
        // explain WHY assignStudent() is not called here; forbidding the bare
        // name would fail on the very comment that documents the invariant.
        foreach (['->assignStudent(', '->createModuleFee(', '->raise_student_charge('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $block,
                "admission reached '{$forbidden}'. A transport REQUEST must never enrol a student "
                . 'or raise a fee — at declaration time no route, no stop and no fare exist yet, '
                . 'so anything billed here is billed against nothing.');
        }
    }

    /** A failed request must not fail the admission — the student is already saved. */
    public function test_a_transport_failure_cannot_lose_an_admission(): void
    {
        $this->assertMatchesRegularExpression('/catch\s*\(\s*\\\\?Throwable/', $this->admissionTransportBlock(),
            'the transport request runs AFTER the student is written. An unhandled throw here '
            . 'would surface as a failed admission for a student who is in fact admitted, and '
            . 'the operator would re-admit them.');
    }

    public function test_the_operator_is_told_where_the_request_went(): void
    {
        $block = $this->admissionTransportBlock();

        $this->assertStringContainsString('Requests', $block,
            'the success message must name the queue the request landed in, or nobody goes to look');
        $this->assertStringNotContainsString('Campus', $block,
            "the navigation is Operations -> Transport -> Requests. 'Campus' is a non-clickable "
            . 'section heading in the sidebar, so naming it sends the operator somewhere they '
            . 'cannot click.');
    }

    /**
     * BOTH ways into the school must reach the same worklist.
     *
     * Direct admission (Sis::save_admission) and Online Admission conversion
     * (Sis::enroll_student) are separate entry points that both create a
     * student. Wiring only one leaves a silent hole: half the intake declares
     * transport and is never queued, which is indistinguishable from nobody
     * asking for transport.
     */
    public function test_both_admission_entry_points_reach_the_worklist(): void
    {
        foreach (['public function save_admission', 'public function enroll_student'] as $entry) {
            $body = $this->methodBody(self::SIS, $entry);

            $this->assertStringContainsString('->requestTransport(', $body,
                "{$entry} does not raise a transport request. A student admitted through this "
                . 'path who declares transport would never appear in Operations.');
            $this->assertStringContainsString('catch (\Throwable', $body,
                "{$entry} must not let a transport worklist failure fail an admission that has "
                . 'already created the student and their Auth account');
            $this->assertStringNotContainsString('->assignStudent(', $body,
                "{$entry} must not enrol or bill — at declaration time no route, stop or fare "
                . 'exists, so anything billed here is billed against nothing');
        }
    }

    public function test_the_admission_form_still_asks_the_question(): void
    {
        $this->assertStringContainsString('_entitlement_declarations.php', $this->src(self::VIEW),
            'the declarations block is what produces decl_transport. Without it the wiring above '
            . 'is unreachable and requests silently stop being raised.');
    }

    // == TRN-STOP-STATUS-1 ===================================================

    /**
     * THE DEFECT THAT MADE THE LIFECYCLE UNAPPROVABLE.
     *
     * The Stop modal posts no status. The controller coerced that absence to ''
     * with trim($this->input->post('status') ?? ''), and '' is not null, so
     * saveStop()'s ?? 'Active' default never fired. Every stop created through
     * the real UI was stored status=''.
     *
     * approveTransportRequest() refuses a non-Active stop, so a school could
     * create a route and a stop, a family could request transport, and the
     * request could never be approved:
     *     "Stop STP0001 is not Active (status='')"
     * Reproduced end to end on the emulator, then fixed and re-proven.
     */
    public function test_an_absent_stop_status_reaches_the_service_as_absent(): void
    {
        $block = $this->methodBody(self::CONTROLLER, 'public function save_stop');

        $this->assertDoesNotMatchRegularExpression(
            "/'status'\s*=>\s*trim\(\\\$this->input->post\('status'\)\s*\?\?\s*''\)/", $block,
            "save_stop is coercing an absent status to ''. That defeats saveStop()'s 'Active' "
            . 'default and every stop created through the UI becomes unapprovable.');

        $this->assertMatchesRegularExpression("/'status'\s*=>.*!==\s*''/s", $block,
            'save_stop must distinguish "not supplied" from "supplied empty" and pass NULL for '
            . 'the former, so the service default applies');
    }

    /** The default it depends on. If this moves, the controller fix is pointless. */
    public function test_the_service_still_defaults_a_new_stop_to_active(): void
    {
        $this->assertMatchesRegularExpression("/\\\$input\['status'\]\s*\?\?.*'Active'/s",
            $this->methodBody(self::SERVICE, 'public function saveStop'),
            'saveStop must keep defaulting an unspecified status to Active — an operator adding '
            . 'a stop is adding a stop they intend to use');
    }

    /** Approval must keep refusing a non-Active stop; the fix is upstream of it. */
    public function test_approval_still_refuses_a_non_active_stop(): void
    {
        $this->assertStringContainsString('is not Active', $this->src(self::SERVICE),
            'the guard is correct and must stay. TRN-STOP-STATUS-1 was fixed at the write end, '
            . 'by storing a truthful status, NOT by weakening this check.');
    }

    // == IDEMPOTENCY — THE REFUSALS THAT PREVENT DOUBLE BILLING ==============

    /**
     * Both refusals were proven at runtime: a second request for a pending
     * student returned already_pending, and a request for an enrolled student
     * returned already_assigned. Neither created a row.
     */
    public function test_a_duplicate_request_cannot_create_a_second_row(): void
    {
        $s = $this->src(self::SERVICE);

        $this->assertStringContainsString("'already_pending'", $s,
            'a resubmitted request must be refused, not queued twice — two pending rows for one '
            . 'student can be approved twice and billed twice');
        $this->assertStringContainsString("'already_assigned'", $s,
            'a student already on a route must not be able to raise a request that would enrol '
            . 'and bill them a second time');
        $this->assertMatchesRegularExpression('/\$docId\s*=\s*"\{\$studentId\}_\{\$this->sessionYear\}"/', $s,
            'the request id is derived from student + session, so a duplicate collides on the '
            . 'same document instead of creating a second one. That is the structural half of '
            . 'the idempotency; the status guards are the behavioural half.');
    }

    /** Rejection is an outcome, not an erasure. */
    public function test_a_rejection_survives_a_re_request(): void
    {
        $this->assertStringContainsString('$history[] = [',
            $this->methodBody(self::SERVICE, 'public function requestTransport'),
            'a re-request after a rejection must keep the earlier decision in history. The doc id '
            . 'is deterministic, so without this the record of what the school decided, and when, '
            . 'is overwritten by the next attempt.');
    }
}
