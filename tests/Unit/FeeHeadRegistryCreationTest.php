<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  DAY-ONE-1 · A NEW SCHOOL COULD NOT CREATE A FEE HEAD
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE GAP ──────────────────────────────────────────────────────────────
 * `feeHeadRegistry` had exactly one writer in the whole repository:
 * scripts/m3_mint_fee_head_registry.php, a developer-run script carrying a
 * hardcoded 12-row mapping for two named tenants. Fee_head_resolver is
 * read-only, Entitlements::fee_heads() is a read-only picker, and the chart
 * sync deliberately never mints.
 *
 * With registry mode active and an empty registry, head resolution fails
 * closed and admission produces ZERO demands. So a third school had no
 * supported path to its first bill at all — the remedy was a developer
 * editing a script. That is the "hidden developer step" category, and these
 * tests exist to keep it closed.
 *
 * ── DOCID-ENCODE-1, FOUND WHILE PROVING IT ───────────────────────────────
 * Creating the first real fee head immediately exposed a platform defect.
 * Firestore_rest_client built the create URL as `?documentId=` . urlencode(),
 * where a space becomes '+' and is taken LITERALLY, while every read, update
 * and delete builds the path with rawurlencode(), where a space becomes %20
 * and decodes back to a space.
 *
 * A fee head name claim is `{schoolId}_name_{nameNorm}` and nameNorm KEEPS
 * SPACES, so the claim for "Tuition Fee" was written as
 * '..._name_tuition+fee' and every subsequent read looked for
 * '..._name_tuition fee'. The document existed and was permanently
 * unreachable — resolveByName() returned not_found for a head plainly visible
 * in the picker, and generation aborted.
 *
 * That reaches far past fee heads: ANY document whose id contains a space was
 * created under an id nothing could read back.
 */
final class FeeHeadRegistryCreationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once APPPATH . 'libraries/Fee_head_resolver.php';
        require_once APPPATH . 'libraries/Fee_head_registry_service.php';
    }

    // ══ DOCID-ENCODE-1 · CREATE AND READ MUST AGREE ═════════════════════════

    /**
     * The create path must not use urlencode() on the document id. This is a
     * source assertion because the defect lives in URL construction, and a unit
     * test cannot reach Firestore to observe the stored id.
     */
    public function test_document_creation_does_not_plus_encode_the_id(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Firestore_rest_client.php');

        $this->assertSame(0, preg_match_all('/\?documentId=" \. urlencode\(/', $src),
            'a create site is back to urlencode() for ?documentId=. In a query string a space '
            . "becomes '+', which Firestore stores LITERALLY, while every read builds the path "
            . 'with rawurlencode() and looks for %20. Any document id containing a space is then '
            . 'created under an id nothing can ever read back.');

        $this->assertGreaterThanOrEqual(2, preg_match_all('/\?documentId=" \. rawurlencode\(/', $src),
            'the create sites no longer use rawurlencode() for the document id');
    }

    /** A space really does encode differently — the premise of the defect. */
    public function test_the_two_encoders_genuinely_disagree_on_a_space(): void
    {
        $id = 'SCH_X_name_tuition fee';
        $this->assertSame('SCH_X_name_tuition+fee', urlencode($id));
        $this->assertSame('SCH_X_name_tuition%20fee', rawurlencode($id));
        $this->assertNotSame(urlencode($id), rawurlencode($id),
            'if these ever agree the defect above is unreachable and this guard is obsolete');
    }

    /** The id shape that triggered it is still the one the registry uses. */
    public function test_a_claim_id_still_contains_a_space(): void
    {
        $claim = Fee_head_resolver::claimDocId('SCH_X', 'Tuition Fee');
        $this->assertStringContainsString(' ', $claim,
            'claim ids no longer contain spaces. If nameNorm changed, DOCID-ENCODE-1 stops '
            . 'affecting fee heads — but the encoder fix must stay for every other collection.');
    }

    // ══ IDENTITY ════════════════════════════════════════════════════════════

    /** Byte-compatible with the approved M3 mint — one identity model, not two. */
    public function test_the_identity_derivation_matches_the_approved_mint(): void
    {
        $norm = Fee_head_resolver::nameNorm('Tuition Fee');
        $expected = 'FH_' . strtoupper(substr(sha1('SCH_X' . '|' . $norm), 0, 12));
        $this->assertSame($expected, Fee_head_registry_service::mintId('SCH_X', $norm),
            'the service mints a different identity than scripts/m3_mint_fee_head_registry.php. '
            . 'Two minting conventions for one collection is exactly the duplication the '
            . 'registry exists to end.');
    }

    /** Deterministic: the same school and name always yield the same identity. */
    public function test_minting_is_deterministic(): void
    {
        $n = Fee_head_resolver::nameNorm('Tuition Fee');
        $this->assertSame(
            Fee_head_registry_service::mintId('SCH_X', $n),
            Fee_head_registry_service::mintId('SCH_X', $n),
            'a repeated registration would fork the identity, so a double-click would create '
            . 'two heads for one logical fee');
    }

    /** TENANT ISOLATION: the same name in two schools is two different heads. */
    public function test_the_same_name_in_two_tenants_yields_different_identities(): void
    {
        $n = Fee_head_resolver::nameNorm('Tuition Fee');
        $this->assertNotSame(
            Fee_head_registry_service::mintId('SCH_A', $n),
            Fee_head_registry_service::mintId('SCH_B', $n),
            "two schools' Tuition Fee collapsed to ONE identity — one tenant's concession "
            . "would then reference another tenant's head");
    }

    /** Whitespace variants must not become two heads. */
    public function test_whitespace_variants_collapse_to_one_identity(): void
    {
        $this->assertSame(
            Fee_head_registry_service::mintId('SCH_X', Fee_head_resolver::nameNorm('Tuition  Fee')),
            Fee_head_registry_service::mintId('SCH_X', Fee_head_resolver::nameNorm('tuition fee')),
            '"Tuition  Fee" and "tuition fee" minted different heads, so the same fee could be '
            . 'registered twice and a concession would only reach one of them');
    }

    // ══ REFUSALS · NOTHING IS WRITTEN ON A BAD REQUEST ══════════════════════

    /**
     * A Firestore double that FAILS the test if anything is written. Every case
     * below must refuse before touching the collection.
     */
    private function strictFs()
    {
        return new class {
            public array $writes = [];
            public function firestoreCreate($col, $id, $data): bool { $this->writes[] = $id; return true; }
            public function firestoreGet($col, $id): ?array { return null; }
        };
    }

    public function test_an_unassignable_category_is_refused_without_writing(): void
    {
        $fs = $this->strictFs();
        $r = (new Fee_head_registry_service())->init($fs, 'admin')->create('SCH_X', 'Sports Fee', 'nonsense');

        $this->assertFalse($r['ok']);
        $this->assertSame('bad_category', $r['reason']);
        $this->assertSame([], $fs->writes,
            'a head was written with a category outside the closed vocabulary. The category '
            . 'decides whether a school-wide concession reaches this head, so coercing it to a '
            . 'default silently moves money.');
    }

    public function test_a_blank_name_is_refused_without_writing(): void
    {
        $fs = $this->strictFs();
        $r = (new Fee_head_registry_service())->init($fs, 'admin')->create('SCH_X', '   ', 'academic');

        $this->assertFalse($r['ok']);
        $this->assertSame('no_name', $r['reason']);
        $this->assertSame([], $fs->writes);
    }

    public function test_a_missing_tenant_is_refused_without_writing(): void
    {
        $fs = $this->strictFs();
        $r = (new Fee_head_registry_service())->init($fs, 'admin')->create('', 'Tuition Fee', 'academic');

        $this->assertFalse($r['ok']);
        $this->assertSame('no_tenant', $r['reason']);
        $this->assertSame([], $fs->writes,
            'a fee head was written with no owning school — it would belong to every tenant '
            . 'and none');
    }

    // ══ THE HAPPY PATH WRITES THE CLAIM FIRST ═══════════════════════════════

    /**
     * The name claim is the uniqueness gate, so it must be written BEFORE the
     * head. Reversed, two administrators registering the same name at the same
     * moment could both mint a head before either claimed the name.
     */
    public function test_the_name_claim_is_written_before_the_head(): void
    {
        $fs = $this->strictFs();
        $r = (new Fee_head_registry_service())->init($fs, 'admin')->create('SCH_X', 'Tuition Fee', 'academic');

        $this->assertTrue($r['ok']);
        $this->assertCount(2, $fs->writes, 'expected exactly one claim and one head');
        $this->assertStringContainsString('_name_', $fs->writes[0],
            'the head was written before the name claim; the claim is the uniqueness gate and '
            . 'must come first');
        $this->assertSame(Fee_head_resolver::docId('SCH_X', $r['feeHeadId']), $fs->writes[1]);
    }

    /**
     * A name already held by a DIFFERENT identity must refuse, not mint a
     * second head for the same logical fee.
     */
    public function test_a_name_held_by_another_identity_is_refused(): void
    {
        $fs = new class {
            public array $writes = [];
            public function firestoreCreate($col, $id, $data): bool { $this->writes[] = $id; return false; }
            public function firestoreGet($col, $id): ?array { return ['feeHeadId' => 'FH_SOMEONEELSE']; }
        };

        $r = (new Fee_head_registry_service())->init($fs, 'admin')->create('SCH_X', 'Tuition Fee', 'academic');

        $this->assertFalse($r['ok']);
        $this->assertSame('name_taken', $r['reason']);
        $this->assertCount(1, $fs->writes,
            'the head was still written after the name claim was refused — one logical fee head '
            . 'would then hold two identities, which is the condition the claim exists to prevent');
    }
}
