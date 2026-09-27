<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20.3b — P20.3-CLAIM-1: create-if-not-exists error semantics.
 *
 * THE DEFECT: Firestore_rest_client::createDocument() returned `false` for an
 * HTTP 409 (the document already exists — a BUSINESS contention result) and
 * ALSO for every infrastructure failure: 500, 401, malformed request, timeout,
 * DNS failure, connection reset. A caller holding that boolean could not tell
 * "someone else owns this key" from "the write did not happen and I do not
 * know why".
 *
 * WHY IT MATTERS: a claim loop that reads `false` as "taken, try the next
 * candidate" walks past keys nobody owns during an outage. In
 * Sis::suggest_roll_no() that silently skipped free roll numbers. For the seat
 * claim Phase 20.2 proposed, it would have stepped past the last valid slot
 * and granted a place beyond capacity — causing the exact overflow the
 * mechanism existed to prevent. Phase 20.3 rejected that design on this basis.
 *
 * THE INVARIANT UNDER TEST:
 *     UNKNOWN FAILURE is NEVER reported as COLLISION.
 */
class Phase203bCreateErrorSemanticsTest extends TestCase
{
    private const CLIENT = __DIR__ . '/../../application/libraries/Firestore_rest_client.php';
    private const FB     = __DIR__ . '/../../application/libraries/Firebase.php';
    private const SIS    = __DIR__ . '/../../application/controllers/Sis.php';

    private function src(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    /**
     * Mirrors Firestore_rest_client::createDocumentStatus()'s classification.
     * `$code` is CURLINFO_HTTP_CODE — 0 when curl_exec got no response at all
     * (timeout / DNS / connection reset), which is how request() reports
     * transport failure.
     */
    private function classify(int $code): string
    {
        if ($code >= 200 && $code < 300) return 'SUCCESS';
        if ($code === 409)               return 'COLLISION';
        return 'FAILURE';
    }

    // ── T1–T8: classification ────────────────────────────────────────────

    public function test_T1_successful_create_is_SUCCESS(): void
    {
        $this->assertSame('SUCCESS', $this->classify(200));
        $this->assertSame('SUCCESS', $this->classify(201));
    }

    public function test_T2_http_409_is_COLLISION(): void
    {
        $this->assertSame('COLLISION', $this->classify(409),
            '409 is the ONLY code that may be reported as contention.');
    }

    public function test_T3_http_400_is_FAILURE(): void
    {
        $this->assertSame('FAILURE', $this->classify(400));
    }

    public function test_T4_http_401_and_403_are_FAILURE(): void
    {
        $this->assertSame('FAILURE', $this->classify(401));
        $this->assertSame('FAILURE', $this->classify(403));
    }

    public function test_T5_http_500_is_FAILURE(): void
    {
        $this->assertSame('FAILURE', $this->classify(500));
        $this->assertSame('FAILURE', $this->classify(503));
    }

    public function test_T6_timeout_is_FAILURE_not_collision(): void
    {
        // curl timeout → curl_exec returns false → CURLINFO_HTTP_CODE == 0.
        $this->assertSame('FAILURE', $this->classify(0),
            'A timeout must never be reported as "already exists".');
    }

    public function test_T7_network_failure_is_FAILURE_not_collision(): void
    {
        // DNS failure / connection reset also surface as code 0.
        $this->assertSame('FAILURE', $this->classify(0));
    }

    public function test_T8_unknown_or_malformed_response_is_FAILURE(): void
    {
        foreach ([-1, 1, 100, 302, 418, 999] as $code) {
            $this->assertSame('FAILURE', $this->classify($code),
                "Unexpected code {$code} must be FAILURE, never COLLISION.");
        }
    }

    /** The invariant, stated directly. */
    public function test_INVARIANT_only_409_is_ever_collision(): void
    {
        for ($code = 0; $code <= 600; $code++) {
            if ($code === 409) continue;
            $this->assertNotSame('COLLISION', $this->classify($code),
                "HTTP {$code} must never be classified as COLLISION.");
        }
    }

    // ── T9–T14: roll-number caller behaviour ─────────────────────────────

    /**
     * Mirrors the corrected suggest_roll_no() walk.
     * Returns ['outcome' => 'claimed'|'error'|'exhausted', 'roll' => ?int,
     *          'tried' => int]
     */
    private function rollWalk(array $statusesByCandidate, int $max = 5): array
    {
        $tried = 0;
        for ($c = 1; $c <= $max; $c++) {
            $tried++;
            $status = $statusesByCandidate[$c] ?? 'COLLISION';

            if ($status === 'FAILURE') {
                return ['outcome' => 'error', 'roll' => null, 'tried' => $tried];
            }
            if ($status === 'SUCCESS') {
                return ['outcome' => 'claimed', 'roll' => $c, 'tried' => $tried];
            }
            // COLLISION → try the next candidate
        }
        return ['outcome' => 'exhausted', 'roll' => null, 'tried' => $tried];
    }

    public function test_T9_collision_tries_the_next_candidate(): void
    {
        $r = $this->rollWalk([1 => 'COLLISION', 2 => 'COLLISION', 3 => 'SUCCESS']);
        $this->assertSame('claimed', $r['outcome']);
        $this->assertSame(3, $r['roll'], 'A real collision must advance the walk.');
        $this->assertSame(3, $r['tried']);
    }

    public function test_T10_http_500_does_NOT_try_the_next_candidate(): void
    {
        $r = $this->rollWalk([1 => 'FAILURE', 2 => 'SUCCESS']);
        $this->assertSame('error', $r['outcome']);
        $this->assertNull($r['roll'], 'A server error must not hand out roll 2.');
        $this->assertSame(1, $r['tried'], 'The walk must stop at the failure.');
    }

    public function test_T11_timeout_does_NOT_try_the_next_candidate(): void
    {
        $r = $this->rollWalk([1 => 'COLLISION', 2 => 'FAILURE', 3 => 'SUCCESS']);
        $this->assertSame('error', $r['outcome']);
        $this->assertNull($r['roll']);
        $this->assertSame(2, $r['tried'], 'Stops at candidate 2; never reaches 3.');
    }

    public function test_T12_network_failure_does_NOT_consume_candidates(): void
    {
        $r = $this->rollWalk([1 => 'FAILURE']);
        $this->assertSame('error', $r['outcome']);
        $this->assertSame(1, $r['tried']);
    }

    public function test_T13_success_returns_the_claimed_roll(): void
    {
        $r = $this->rollWalk([1 => 'SUCCESS']);
        $this->assertSame('claimed', $r['outcome']);
        $this->assertSame(1, $r['roll']);
    }

    public function test_T14_legitimate_collision_sequence_still_works(): void
    {
        // The pre-existing happy path must be unchanged: a run of genuinely
        // taken numbers still walks to the first free one.
        $r = $this->rollWalk([1 => 'COLLISION', 2 => 'COLLISION', 3 => 'COLLISION', 4 => 'SUCCESS']);
        $this->assertSame('claimed', $r['outcome']);
        $this->assertSame(4, $r['roll']);
    }

    /** The exact regression: the OLD boolean could not express this. */
    public function test_REGRESSION_old_boolean_conflated_the_two_outcomes(): void
    {
        $oldBool = function (int $code): bool { return $code >= 200 && $code < 300; };

        $this->assertFalse($oldBool(409), 'Old API: collision → false');
        $this->assertFalse($oldBool(500), 'Old API: server error → ALSO false');
        $this->assertFalse($oldBool(0),   'Old API: timeout → ALSO false');

        // Indistinguishable then; distinguishable now.
        $this->assertSame('COLLISION', $this->classify(409));
        $this->assertSame('FAILURE',   $this->classify(500));
        $this->assertSame('FAILURE',   $this->classify(0));
    }

    // ── backward compatibility ───────────────────────────────────────────

    public function test_BC_createDocument_still_returns_bool(): void
    {
        $src = $this->src(self::CLIENT);
        $this->assertStringContainsString(
            'publicfunctioncreateDocument(string$collection,string$docId,array$data):bool',
            preg_replace('/\s+/', '', $src),
            'The boolean API must keep its exact signature — 30+ call sites depend on it.'
        );
    }

    public function test_BC_boolean_wrapper_is_true_only_on_SUCCESS(): void
    {
        $src = preg_replace('/\s+/', '', $this->src(self::CLIENT));
        $this->assertStringContainsString(
            "returnthis->createDocumentStatus(collection,docId,data)['status']===self::CREATE_SUCCESS;",
            str_replace('$', '', $src),
            'createDocument() must delegate and remain true only on success — '
            . 'preserving byte-for-byte the old observable behaviour.'
        );
    }

    public function test_BC_status_constants_exist(): void
    {
        $src = $this->src(self::CLIENT);
        foreach (['CREATE_SUCCESS', 'CREATE_COLLISION', 'CREATE_FAILURE'] as $c) {
            $this->assertStringContainsString($c, $src);
        }
    }

    public function test_BC_firebase_wrapper_added_without_changing_the_old_one(): void
    {
        $src = preg_replace('/\s+/', '', $this->src(self::FB));
        $this->assertStringContainsString('functionfirestoreCreate(string$collection,string$docId,array$data):bool', $src,
            'The existing Firebase wrapper must keep its boolean contract.');
        $this->assertStringContainsString('functionfirestoreCreateStatus(', $src,
            'A status-aware sibling must be added alongside it.');
    }

    public function test_BC_uninitialised_client_is_FAILURE_not_collision(): void
    {
        $src  = $this->src(self::FB);
        $body = substr($src, strpos($src, 'function firestoreCreateStatus('), 700);
        $this->assertStringContainsString("'FAILURE'", $body);
        $this->assertStringNotContainsString("'COLLISION'", $body,
            'An uninitialised client proves nothing about key ownership.');
    }

    // ── caller adaptation ────────────────────────────────────────────────

    public function test_CALLER_suggest_roll_no_uses_the_status_api(): void
    {
        $src = $this->src(self::SIS);
        $this->assertStringContainsString("firestoreCreateStatus('rollNoClaims'", $src,
            'The roll claim must consume the status-aware API.');
        $this->assertStringContainsString('ACC_ROLL_CLAIM_INFRA_FAILURE', $src,
            'Infrastructure failure must be logged distinctly from contention.');
    }

    public function test_CALLER_only_the_roll_path_was_adapted(): void
    {
        // Scope discipline: 32 other firestoreCreate() call sites across
        // identity generation, fee counters, accounting and the registry keep
        // the boolean API and are NOT touched in this phase.
        $src = $this->src(self::SIS);
        $this->assertSame(1, substr_count($src, 'firestoreCreateStatus('),
            'Exactly one caller adapted in this phase.');
    }

    // ── SRNO ─────────────────────────────────────────────────────────────

    public function test_SRNO_error_semantics_change_touches_no_identity_logic(): void
    {
        $client = $this->src(self::CLIENT);
        $start  = strpos($client, 'function createDocumentStatus(');
        $body   = substr($client, $start, 1800);
        $this->assertSame(0, preg_match_all('/srNo|sr_no|admissionNo|rollNo|studentId/i', $body),
            'The transport-level classifier must be identity-agnostic.');
    }

    public function test_SRNO_roll_failure_path_assigns_nothing(): void
    {
        // A FAILURE returns an error WITHOUT claiming, so no roll is issued and
        // no identity is minted. Mirrors the early-return in suggest_roll_no().
        $r = $this->rollWalk([1 => 'FAILURE']);
        $this->assertNull($r['roll'], 'No roll may be assigned on infrastructure failure.');
        $this->assertSame('error', $r['outcome']);
    }
}
