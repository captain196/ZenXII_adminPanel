<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ZERO-RTDB certification for the Assets module.
 *
 * Assets stored its feature data in RTDB (Schools/{school}/Operations/Assets/*)
 * via $this->firebase->get/set/update/delete. That violated the Firestore-only
 * architecture. Census was empty fleet-wide (0 assets across all tenants), so
 * this was a clean cutover with no backfill: every store now goes through the
 * school-scoped Firestore_service, ids via the canonical nextSchoolCounter, and
 * the accounting integration (Operations_accounting) is unchanged.
 *
 * Source-assertion (CI controller; cannot be instantiated offline).
 */
final class AssetsFirestoreOnlyTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Assets.php');
    }

    private function code(): string
    {
        $out = '';
        foreach (token_get_all(self::$src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return (string) preg_replace('/\s+/', ' ', $out);
    }

    public function test_no_live_rtdb_feature_access(): void
    {
        $code = $this->code();
        $this->assertDoesNotMatchRegularExpression(
            '~\$this->firebase->(get|set|update|push|delete|remove)\s*\(~', $code,
            'Assets must not touch RTDB via the firebase wrappers — it is Firestore-only now');
        $this->assertStringNotContainsString('getReference', $code,
            'Assets must not use the raw RTDB reference API');
        $this->assertStringNotContainsString('Schools/{', $code,
            'the RTDB path form Schools/{school}/... must be gone');
    }

    public function test_storage_is_school_scoped_firestore(): void
    {
        $code = $this->code();
        // Lists go through schoolWhere (schoolId-scoped); single docs through the
        // *Entity helpers (doc id = {schoolId}_{entityId}) — both tenant-isolated.
        foreach (['schoolWhere', 'getEntity', 'setEntity', 'removeEntity'] as $m) {
            $this->assertStringContainsString('->fs->' . $m . '(', $code,
                "Assets must use Firestore_service::{$m} for tenant-scoped storage");
        }
    }

    public function test_ids_use_the_canonical_per_school_counter(): void
    {
        $this->assertStringContainsString('->fs->nextSchoolCounter(', $this->code(),
            'sequential ids must reuse the canonical nextSchoolCounter, not a new authority');
        // And no re-introduction of the old RTDB counter path.
        $this->assertDoesNotMatchRegularExpression('~operations_accounting->next_id~', $this->code(),
            'the old RTDB-counter next_id() must not be used');
    }

    public function test_id_allocation_fails_closed(): void
    {
        // A failed counter (nextSchoolCounter returns 0 -> _gen_id '') must be
        // rejected, never written as a colliding/blank id.
        $this->assertMatchesRegularExpression(
            "~if\s*\(\s*\\\$id\s*===?\s*''\s*\)\s*\\\$this->json_error~", $this->code(),
            'a failed id allocation must fail closed with json_error');
    }

    public function test_accounting_integration_preserved(): void
    {
        // The module boundary is intact: Assets still delegates journals to the
        // Operations_accounting authority (not migrated here).
        $code = $this->code();
        $this->assertStringContainsString('operations_accounting->create_journal(', $code,
            'the accounting journal integration must be preserved');
        $this->assertStringContainsString('operations_accounting->validate_accounts(', $code,
            'account validation must be preserved');
    }
}
