<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19E — Credential V2: a card version bound into the signature.
 *
 * WHY V2 EXISTS. V1 signs only "{schoolId}|{studentId}", both permanent for
 * the life of the student. A V1 credential is therefore immortal: reprinting a
 * lost student's card yields the identical token, and the only way to
 * invalidate the lost one is rotating the server secret — which kills every
 * card in every school. Binding a version makes ONE card retirable.
 *
 * The version must be in the SIGNED MATERIAL, not merely carried in the
 * payload; otherwise the holder could edit it. These tests prove exactly that.
 *
 * ⚠ NOT ENFORCED YET. The decoder reports `cardVersion`; nothing compares it
 * to the student's active card. Enforcement is Phase 19G, after reissue.
 * Enabling it now would reject every legitimate holder.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19ECredentialV2Test extends TestCase
{
    private const SCH_A = 'SCH_TEST01';
    private const SCH_B = 'SCH_TEST02';
    private const STU_A = 'STU0012';
    private const STU_B = 'STU0047';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/application/helpers/qr_token_helper.php';
    }

    private function b64(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private function unb64(string $tok): string
    {
        return (string) base64_decode(strtr($tok, '-_', '+/') . str_repeat('=', (4 - strlen($tok) % 4) % 4), true);
    }

    // ── A. V2 round trip ────────────────────────────────────────────────

    public function test_v2_credential_is_accepted_and_reports_its_version(): void
    {
        $dec = qr_token_decode(qr_token_encode_v2(self::SCH_A, self::STU_A, 3));
        $this->assertIsArray($dec);
        $this->assertSame(self::SCH_A, $dec['schoolId']);
        $this->assertSame(self::STU_A, $dec['studentId']);
        $this->assertSame(3, $dec['cardVersion']);
        $this->assertSame('v2', $dec['tokenVersion']);
        $this->assertFalse($dec['legacy']);
    }

    public function test_v2_payload_has_the_expected_four_part_shape(): void
    {
        $parts = explode('|', $this->unb64(qr_token_encode_v2(self::SCH_A, self::STU_A, 7)));
        $this->assertCount(4, $parts);
        $this->assertSame(self::SCH_A, $parts[0]);
        $this->assertSame(self::STU_A, $parts[1]);
        $this->assertSame('v7', $parts[2]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $parts[3]);
    }

    public function test_each_version_produces_a_distinct_credential(): void
    {
        $seen = [];
        for ($v = 1; $v <= 6; $v++) $seen[] = qr_token_encode_v2(self::SCH_A, self::STU_A, $v);
        $this->assertSame($seen, array_unique($seen), 'a reissue must not reproduce an earlier card');
    }

    public function test_reprinting_the_same_version_is_deterministic(): void
    {
        // A reprint is NOT a reissue — the card in the student's pocket must
        // keep working.
        $this->assertSame(
            qr_token_encode_v2(self::SCH_A, self::STU_A, 4),
            qr_token_encode_v2(self::SCH_A, self::STU_A, 4)
        );
    }

    // ── B. The version is genuinely signed ──────────────────────────────

    public function test_editing_the_version_invalidates_the_signature(): void
    {
        // THE central property. Without it a holder could self-promote a
        // superseded card to the current version and defeat revocation.
        $tok = qr_token_encode_v2(self::SCH_A, self::STU_A, 2);
        [$sch, $stu, , $sig] = explode('|', $this->unb64($tok));
        $forged = $this->b64("{$sch}|{$stu}|v9|{$sig}");
        $this->assertNull(qr_token_decode($forged), 'version must be signed, not merely carried');
    }

    public function test_a_signature_from_one_version_does_not_validate_another(): void
    {
        $v2 = explode('|', $this->unb64(qr_token_encode_v2(self::SCH_A, self::STU_A, 2)));
        $v3 = explode('|', $this->unb64(qr_token_encode_v2(self::SCH_A, self::STU_A, 3)));
        $this->assertNotSame($v2[3], $v3[3]);
        $this->assertNull(qr_token_decode($this->b64("{$v3[0]}|{$v3[1]}|v3|{$v2[3]}")));
    }

    public function test_a_tampered_v2_signature_is_rejected(): void
    {
        $tok = qr_token_encode_v2(self::SCH_A, self::STU_A, 1);
        [$sch, $stu, $ver, $sig] = explode('|', $this->unb64($tok));
        $flipped = ($sig[0] === 'a' ? 'b' : 'a') . substr($sig, 1);
        $this->assertNull(qr_token_decode($this->b64("{$sch}|{$stu}|{$ver}|{$flipped}")));
    }

    // ── C. Identity binding ─────────────────────────────────────────────

    public function test_v2_cannot_be_transferred_to_another_student(): void
    {
        $p = explode('|', $this->unb64(qr_token_encode_v2(self::SCH_A, self::STU_A, 2)));
        $swapped = $this->b64(self::SCH_A . '|' . self::STU_B . "|v2|{$p[3]}");
        $this->assertNull(qr_token_decode($swapped));
    }

    public function test_v2_cannot_be_transferred_to_another_school(): void
    {
        $p = explode('|', $this->unb64(qr_token_encode_v2(self::SCH_A, self::STU_A, 2)));
        $swapped = $this->b64(self::SCH_B . '|' . self::STU_A . "|v2|{$p[3]}");
        $this->assertNull(qr_token_decode($swapped));
    }

    public function test_the_same_student_in_two_schools_gets_different_credentials(): void
    {
        $this->assertNotSame(
            qr_token_encode_v2(self::SCH_A, self::STU_A, 1),
            qr_token_encode_v2(self::SCH_B, self::STU_A, 1)
        );
    }

    // ── D. Malformed V2 ─────────────────────────────────────────────────

    public function test_a_non_numeric_version_segment_is_rejected(): void
    {
        foreach (['vX', 'v', '2', 'v-1', 'v1.5', 'V2', 'v99999'] as $bad) {
            $sig = str_repeat('a', 16);
            $this->assertNull(
                qr_token_decode($this->b64(self::SCH_A . '|' . self::STU_A . "|{$bad}|{$sig}")),
                "version segment '{$bad}' must be rejected"
            );
        }
    }

    public function test_version_zero_is_rejected(): void
    {
        // 0 means "no managed card"; it must never be expressible in a token.
        $sig = str_repeat('a', 16);
        $this->assertNull(qr_token_decode($this->b64(self::SCH_A . '|' . self::STU_A . "|v0|{$sig}")));
    }

    public function test_encode_clamps_a_nonsensical_version_up_to_one(): void
    {
        $dec = qr_token_decode(qr_token_encode_v2(self::SCH_A, self::STU_A, 0));
        $this->assertIsArray($dec);
        $this->assertSame(1, $dec['cardVersion']);
    }

    public function test_a_five_part_payload_is_rejected(): void
    {
        $sig = str_repeat('a', 16);
        $this->assertNull(qr_token_decode($this->b64(self::SCH_A . '|' . self::STU_A . "|v1|{$sig}|extra")));
    }

    public function test_a_one_part_payload_is_still_rejected(): void
    {
        $this->assertNull(qr_token_decode($this->b64(self::SCH_A)));
    }

    // ── E. Backward compatibility — nothing may break ───────────────────

    public function test_v1_signed_tokens_still_decode(): void
    {
        $dec = qr_token_decode(qr_token_encode(self::SCH_A, self::STU_A));
        $this->assertIsArray($dec, 'existing printed cards must keep working');
        $this->assertFalse($dec['legacy']);
        $this->assertSame('v1', $dec['tokenVersion']);
    }

    public function test_v1_reports_no_card_version_rather_than_version_one(): void
    {
        // A V1 token names NO card. Defaulting it to 1 would make an
        // unmanaged card look like version 1 of a managed one.
        $this->assertNull(qr_token_decode(qr_token_encode(self::SCH_A, self::STU_A))['cardVersion']);
    }

    public function test_legacy_unsigned_tokens_still_decode_and_stay_flagged(): void
    {
        $dec = qr_token_decode($this->b64(self::SCH_A . '|' . self::STU_A));
        $this->assertIsArray($dec, 'SEC-ID-2 migration window must remain open');
        $this->assertTrue($dec['legacy']);
        $this->assertSame('legacy', $dec['tokenVersion']);
        $this->assertNull($dec['cardVersion']);
    }

    public function test_the_legacy_flag_contract_every_consumer_relies_on_is_unchanged(): void
    {
        // Attendance, Transport and Visitor all branch on !empty($d['legacy']).
        // V2 must read as non-legacy so Transport's fail-closed check accepts
        // it, and legacy must stay true so SEC-ID-3 telemetry still fires.
        $this->assertFalse(qr_token_decode(qr_token_encode_v2(self::SCH_A, self::STU_A, 1))['legacy']);
        $this->assertFalse(qr_token_decode(qr_token_encode(self::SCH_A, self::STU_A))['legacy']);
        $this->assertTrue(qr_token_decode($this->b64(self::SCH_A . '|' . self::STU_A))['legacy']);
    }

    public function test_a_v1_signature_cannot_be_replayed_as_v2(): void
    {
        // The two signature functions cover different material, so a V1 sig
        // must not validate a V2 payload for the same identity.
        $v1sig = explode('|', $this->unb64(qr_token_encode(self::SCH_A, self::STU_A)))[2];
        $this->assertNull(qr_token_decode($this->b64(self::SCH_A . '|' . self::STU_A . "|v1|{$v1sig}")));
    }

    // ── F. Identity discipline ──────────────────────────────────────────

    public function test_the_credential_binds_student_id_and_never_srno_or_roll(): void
    {
        // The credential must bind the INTERNAL FK. SRNO is the school-facing
        // display identity and Roll No is session-scoped — neither belongs in
        // a credential, and Roll No must never become card identity.
        $payload = $this->unb64(qr_token_encode_v2(self::SCH_A, self::STU_A, 1));
        $this->assertStringContainsString(self::STU_A, $payload);
        $this->assertStringNotContainsString('47', explode('|', $payload)[1], 'SRNO must not be the credential subject');
        $this->assertCount(4, explode('|', $payload), 'no room for roll number by construction');
    }
}
