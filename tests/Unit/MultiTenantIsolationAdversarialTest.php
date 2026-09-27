<?php

use PHPUnit\Framework\TestCase;

foreach (['Fee_lifecycle', 'Fee_payment_claim', 'Student_charge_service',
          'Accounting_idempotency', 'Firestore_service'] as $__c) {
    if (!class_exists($__c)) {
        @require_once APPPATH . 'libraries/' . $__c . '.php';
    }
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  MULTI-TENANT ISOLATION — DRIVEN, NOT READ
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Two materially different tenants are given DELIBERATELY COLLIDING inputs —
 * the same student, the same order, the same charge, the same receipt number —
 * and every real document-id builder in the money path is driven to prove the
 * two can never address the same document.
 *
 * The adversarial choice matters: a test using different inputs per tenant
 * proves nothing, because the ids would differ for the wrong reason. Every
 * fixture below is IDENTICAL across both tenants, so the only thing that can
 * separate the documents is the tenant scoping itself.
 *
 * ── THE ONE THAT IS NOT SCHOOL-PREFIXED ───────────────────────────────────
 * `Fee_lifecycle::_demandId()` produces `DEM_{studentId}_{yyyymm}_{head}` and
 * contains **no schoolId at all**. It is safe only because student ids are
 * minted from `feeCounters/_sys_STU` — a counter document with no school scope,
 * i.e. one global sequence — so `STU0012` exists in exactly one tenant estate-wide.
 *
 * That makes a global counter a TENANT-ISOLATION dependency, not just an
 * identity convenience. Sharding it per school — the obvious scaling move, and
 * one that looks purely internal — would immediately make demand ids collide
 * across tenants. `Fee_firestore_txn::nextCounter()` uses the OPPOSITE
 * convention (`{$this->schoolId}_{$kind}`), so the two are inconsistent, which
 * is exactly how someone could "fix" the inconsistency in the dangerous
 * direction.
 *
 * ── RECEIPT NUMBERS DELIBERATELY COLLIDE ──────────────────────────────────
 * `Admission_collection::next_receipt_no()` returns `ADM000001` with no school
 * prefix, and the counter behind it IS school-scoped — so both tenants really do
 * produce `ADM000001`. That is correct: a receipt series is per school and
 * starts at 1. What must not collide is the DOCUMENT, and the tests below prove
 * the receipt, the ledger entry and the idempotency slot are each prefixed.
 */
class MultiTenantIsolationAdversarialTest extends TestCase
{
    /** Two materially different tenants: different code shape, different age. */
    private const TENANT_A = 'SCH_B56BB9A401';   // the long-lived UAT tenant
    private const TENANT_B = 'SCH_202512';       // a short, numeric-suffixed one

    /** Deliberately identical across both tenants. */
    private const STUDENT  = 'STU0012';
    private const ORDER    = 'order_MxYz123456';
    private const CHARGE   = 'SC0001';
    private const RECEIPT  = 'ADM000001';
    private const SESSION  = '2026-27';

    /** Sets a private/protected property on an instance built without a constructor. */
    private function make(string $class, array $props): object
    {
        $o = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($props as $name => $value) {
            $p = new ReflectionProperty($class, $name);
            $p->setAccessible(true);
            $p->setValue($o, $value);
        }
        return $o;
    }

    private function call(object $o, string $method, array $args)
    {
        $m = new ReflectionMethod(get_class($o), $method);
        $m->setAccessible(true);
        return $m->invokeArgs($o, $args);
    }

    /** Asserts two tenants produce different document ids for identical input. */
    private function assertIsolated(string $a, string $b, string $what): void
    {
        $this->assertNotSame($a, $b,
            "{$what}: both tenants addressed the SAME document ('{$a}') for identical input. "
            . 'One tenant would read, overwrite or claim the other\'s record.');

        $this->assertStringContainsString(self::TENANT_A, $a,
            "{$what}: tenant A's document id does not carry its school id, so isolation "
            . 'depends on something other than the id — state what');
        $this->assertStringContainsString(self::TENANT_B, $b);
    }

    // ══ THE GENERIC ENTITY SCOPE ════════════════════════════════════════════

    public function test_entity_doc_ids_cannot_collide_across_tenants(): void
    {
        $a = $this->make('Firestore_service', ['schoolId' => self::TENANT_A]);
        $b = $this->make('Firestore_service', ['schoolId' => self::TENANT_B]);

        $this->assertIsolated($a->docId(self::STUDENT), $b->docId(self::STUDENT), 'docId');
        $this->assertIsolated(
            $a->docId2(self::SESSION, self::STUDENT),
            $b->docId2(self::SESSION, self::STUDENT), 'docId2');
        $this->assertIsolated(
            $a->docId3(self::SESSION, self::STUDENT, 'x'),
            $b->docId3(self::SESSION, self::STUDENT, 'x'), 'docId3');
    }

    /** Every school-scoped query must carry the schoolId predicate itself. */
    public function test_the_scoped_query_helpers_inject_the_tenant_predicate(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Firestore_service.php');

        foreach (['schoolWhere', 'sessionWhere'] as $fn) {
            $at = strpos($src, "public function {$fn}");
            $this->assertNotFalse($at, "{$fn} was renamed");
            $body = substr($src, $at, 900);

            // Formatting-insensitive: schoolWhere writes the predicate inline
            // and sessionWhere spreads it over several lines. Asserting one
            // layout would fail for a reason that has nothing to do with
            // tenant isolation.
            $this->assertStringContainsString("['schoolId', '==', \$this->schoolId]", $body,
                "{$fn}() no longer forces the schoolId predicate, so a caller passing only "
                . 'business conditions would read every tenant\'s rows');
            $this->assertStringContainsString('array_merge(', $body,
                "{$fn}() no longer merges the tenant predicate ahead of the caller's "
                . 'conditions — confirm a caller cannot now displace it');
        }

        $at   = strpos($src, 'public function sessionWhere');
        $body = substr($src, $at, 900);
        $this->assertStringContainsString("['session', '==', \$this->session]", $body,
            'sessionWhere no longer forces the session predicate — the single most repeated '
            . 'bug class in this codebase');
    }

    // ══ THE PAYMENT LOCK ════════════════════════════════════════════════════

    /**
     * A gateway order id is issued by the PROVIDER, not by ZenX, so two tenants
     * on the same Razorpay account could in principle see overlapping ids. The
     * claim document must not depend on that being impossible.
     */
    public function test_two_tenants_cannot_share_a_payment_claim(): void
    {
        $a = $this->make('Fee_payment_claim', ['schoolId' => self::TENANT_A]);
        $b = $this->make('Fee_payment_claim', ['schoolId' => self::TENANT_B]);

        $this->assertIsolated($a->docId(self::ORDER), $b->docId(self::ORDER), 'payment claim');
    }

    // ══ STUDENT CHARGES ═════════════════════════════════════════════════════

    public function test_two_tenants_cannot_share_a_student_charge_document(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Student_charge_service.php');

        $this->assertStringContainsString("\$docId  = \$this->schoolId . '_' . \$chargeId;", $src,
            'the student-charge document id is no longer school-prefixed, so SC0001 in one '
            . 'tenant is the same document as SC0001 in another');
        $this->assertStringContainsString("self::COL, \$this->schoolId . '_' . \$chargeId, \$doc", $src,
            'the charge WRITE path lost its school prefix even if the read path kept one');
    }

    /**
     * Prefixing alone is not enough. A caller that constructs the id from user
     * input could still guess another tenant's charge, so the service verifies
     * the loaded document's own `schoolId` before acting on it — defence in
     * depth, and the only guard that survives a malformed doc id.
     */
    public function test_a_charge_belonging_to_another_tenant_is_refused_on_read(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Student_charge_service.php');

        $this->assertStringContainsString(
            "if ((string) (\$charge['schoolId'] ?? '') !== \$this->schoolId) {", $src,
            'the waiver no longer re-checks the loaded charge\'s own tenant. The doc id is '
            . 'school-prefixed, but a caller building that id from user input could otherwise '
            . 'reach across the boundary by guessing.');
        $this->assertStringContainsString('cross-tenant attempt', $src,
            'a cross-tenant attempt is no longer logged, so probing would be invisible');
    }

    // ══ ACCOUNTING ══════════════════════════════════════════════════════════

    /**
     * Both tenants really do mint `ADM000001` — the counter is per school and
     * starts at 1. The idempotency slot for the resulting journal must still be
     * distinct, or tenant B's first admission would be deduped against tenant
     * A's and silently produce no journal.
     */
    public function test_an_identical_receipt_number_yields_distinct_idempotency_slots(): void
    {
        $a = $this->make('Accounting_idempotency', ['schoolId' => self::TENANT_A]);
        $b = $this->make('Accounting_idempotency', ['schoolId' => self::TENANT_B]);

        $key = 'JE_FEE_' . self::RECEIPT;

        $this->assertIsolated(
            $this->call($a, '_docId', [$key]),
            $this->call($b, '_docId', [$key]),
            'accounting idempotency slot');
    }

    /**
     * The ledger entry itself. `JE_FEE_ADM000001` is deterministic and NOT
     * unique across tenants, so the document must carry both the school and the
     * session.
     */
    public function test_the_ledger_entry_document_carries_school_and_session(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Operations_accounting.php');

        $count = substr_count($src, '$ledgerDocId = "{$this->school_name}_{$this->session_year}_{$entryId}";');
        $this->assertGreaterThanOrEqual(2, $count,
            'a ledger document id lost its school/session prefix. entryId is the deterministic '
            . 'JE_FEE_{receipt}, and receipt numbers restart at 1 per school — so an unprefixed '
            . "ledger doc would put two tenants' first admission journals in one document.");
    }

    // ══ THE DEMAND ID — THE UNPREFIXED ONE ══════════════════════════════════

    /**
     * Demand ids are IDENTICAL across tenants for the same student, by design.
     * This test asserts that and then asserts the property that makes it safe,
     * so the dependency is recorded where it can fail rather than only in prose.
     */
    public function test_demand_ids_are_tenant_agnostic_and_depend_on_a_global_student_counter(): void
    {
        $a = $this->make('Fee_lifecycle', ['schoolName' => self::TENANT_A, 'sessionYear' => self::SESSION]);
        $b = $this->make('Fee_lifecycle', ['schoolName' => self::TENANT_B, 'sessionYear' => self::SESSION]);

        $idA = $this->call($a, '_demandId', [self::STUDENT, '2026-04', '', 'Tuition Fee', '']);
        $idB = $this->call($b, '_demandId', [self::STUDENT, '2026-04', '', 'Tuition Fee', '']);

        $this->assertSame($idA, $idB,
            'demand ids now differ by tenant. That is SAFER, not worse — but it changes every '
            . 'demand id in the estate, so it is a migration and this test must be re-derived '
            . 'rather than deleted.');

        // The property that makes the collision harmless: one global student sequence.
        $gen = (string) file_get_contents(APPPATH . 'libraries/Id_generator.php');
        $this->assertStringContainsString('feeCounters/_sys_{prefix}', $gen,
            'the student-id counter is no longer the global feeCounters/_sys_{prefix} document. '
            . 'If it is now school-scoped, STU0012 exists in more than one tenant — and demand '
            . 'ids, which carry no schoolId, would COLLIDE ACROSS TENANTS.');
        $this->assertStringNotContainsString('_sys_{$this->schoolId}', $gen,
            'the student counter appears to have been school-scoped');
    }

    /**
     * The two counter conventions are genuinely opposite, and that is the trap.
     * Recorded so a later "make these consistent" pass has to confront which
     * direction is safe.
     */
    public function test_the_fee_counter_is_school_scoped_unlike_the_student_counter(): void
    {
        $txn = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');

        $this->assertStringContainsString('$counterDoc = "{$this->schoolId}_{$kind}";', $txn,
            'the fee counter (receipt numbers) is no longer school-scoped, which would make '
            . 'two tenants share one receipt sequence');
    }

    // ══ THE GATEWAY CREDENTIALS ═════════════════════════════════════════════

    /**
     * Per-tenant gateway credentials are the difference between a school
     * receiving its own money and receiving someone else's.
     */
    public function test_gateway_configuration_is_addressed_per_tenant(): void
    {
        $fm = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $this->assertMatchesRegularExpression(
            '/[\'"]feeSettings[\'"],\s*[\'"]?\{?\$\{?(this->)?school_name\}?[^\'"]*_gateway/',
            $fm,
            'the gateway configuration document is no longer addressed by school. Every tenant '
            . 'would share one set of credentials, so payments could settle into another '
            . "school's account.");
    }
}
