<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';
require_once __DIR__ . '/../../application/libraries/Entitlement_service.php';

/**
 * P6 — F-3 (registry-backed fee-head picker) + PR-1 (approve with amendment).
 *
 *  F-3   The head-scope target was a FREE-TEXT BOX, so an operator could only
 *        ever produce `head:<typed name>` — the reader's legacy tier-3 path,
 *        which a rename silently breaks. The authoritative `head:<feeHeadRef>`
 *        format existed and NO surface could emit it. Now it is picked from
 *        `feeHeadRegistry` through the one fee-head authority.
 *
 *  PR-1  A parent's request is filed as a ₹0 placeholder because the family
 *        describes a situation and the school decides the money. `approve()`
 *        only flipped status, so approving one created an entitlement worth
 *        nothing. The approver now supplies the terms — nothing is inferred.
 */
class P6CaptureAndApprovalTest extends TestCase
{
    private const VIEW = APPPATH . 'views/_entitlements_capture.php';

    // ═════════════════════════════════════════════════════════════════════
    //  F-3 · THE PICKER
    // ═════════════════════════════════════════════════════════════════════

    /** A fake Firestore helper — no I/O, no network, no live data. */
    private function fsDouble(array $rows, string $schoolCode = 'SCH_B56BB9A401')
    {
        return new class($rows, $schoolCode) {
            public function __construct(private array $rows, private string $code) {}
            public function schoolCode(): string { return $this->code; }
            public function querySchool(string $col, array $extra = []): array { return $this->rows; }
        };
    }

    private function row(array $d): array { return ['id' => 'x', 'data' => $d]; }

    private function head(string $ref, string $name, string $cat = 'academic',
                          string $status = 'active', int $sort = 0): array
    {
        return $this->row(['feeHeadId' => $ref, 'schoolId' => 'SCH_B56BB9A401', 'name' => $name,
                           'category' => $cat, 'status' => $status, 'sortOrder' => $sort]);
    }

    private function resolver($fs): Fee_head_resolver
    {
        return (new Fee_head_resolver())->init($fs);
    }

    public function test_F3_lists_active_heads_with_ref_name_and_category(): void
    {
        $out = $this->resolver($this->fsDouble([
            $this->head('FH_AAAAAAAAAAAA', 'Tuition Fee', 'academic'),
            $this->head('FH_BBBBBBBBBBBB', 'Bus Fee',     'transport'),
        ]))->listForSchool('SCH_B56BB9A401');

        $this->assertCount(2, $out);
        $this->assertSame(['feeHeadRef', 'name', 'category'], array_keys($out[0]),
            'exactly the three fields a picker needs — no leaked internals');
        $this->assertSame('FH_AAAAAAAAAAAA', $out[1]['feeHeadRef']);   // sorted by name
    }

    /** Name-claim tokens share the collection but are NOT heads. */
    public function test_F3_excludes_name_claim_tokens(): void
    {
        $out = $this->resolver($this->fsDouble([
            $this->head('FH_AAAAAAAAAAAA', 'Tuition Fee'),
            $this->row(['kind' => 'nameClaim', 'schoolId' => 'SCH_B56BB9A401',
                        'nameNorm' => 'tuition fee', 'feeHeadId' => 'FH_AAAAAAAAAAAA']),
        ]))->listForSchool('SCH_B56BB9A401');

        $this->assertCount(1, $out, 'a claim offered as a head would hand out an unresolvable ref');
    }

    public function test_F3_excludes_archived_heads(): void
    {
        $out = $this->resolver($this->fsDouble([
            $this->head('FH_AAAAAAAAAAAA', 'Tuition Fee', 'academic', 'active'),
            $this->head('FH_CCCCCCCCCCCC', 'Old Fee',     'academic', 'archived'),
        ]))->listForSchool('SCH_B56BB9A401');

        $this->assertCount(1, $out, 'an archived head must not be attachable to a NEW entitlement');
    }

    public function test_F3_excludes_unknown_and_invalid_categories(): void
    {
        $out = $this->resolver($this->fsDouble([
            $this->head('FH_AAAAAAAAAAAA', 'Tuition Fee', 'academic'),
            $this->head('FH_DDDDDDDDDDDD', 'Legacy',      'unknown'),
            $this->head('FH_EEEEEEEEEEEE', 'Odd',         'General'),
        ]))->listForSchool('SCH_B56BB9A401');

        $this->assertCount(1, $out, "'unknown' is a read-time interpretation; 'General' is not vocabulary");
    }

    public function test_F3_enforces_tenant_isolation_per_row(): void
    {
        $out = $this->resolver($this->fsDouble([
            $this->head('FH_AAAAAAAAAAAA', 'Tuition Fee'),
            $this->row(['feeHeadId' => 'FH_FFFFFFFFFFFF', 'schoolId' => 'SCH_OTHER',
                        'name' => 'Other School Fee', 'category' => 'academic', 'status' => 'active']),
        ]))->listForSchool('SCH_B56BB9A401');

        $this->assertCount(1, $out);
        $this->assertSame('FH_AAAAAAAAAAAA', $out[0]['feeHeadRef']);
    }

    /** A wrong-tenant read is a bug, not an empty registry — refuse loudly. */
    public function test_F3_refuses_a_tenant_mismatch_rather_than_returning_empty(): void
    {
        $out = $this->resolver($this->fsDouble([$this->head('FH_AAAAAAAAAAAA', 'Tuition Fee')], 'SCH_OTHER'))
            ->listForSchool('SCH_B56BB9A401');
        $this->assertSame([], $out);
    }

    public function test_F3_sorts_by_sortOrder_then_name(): void
    {
        $out = $this->resolver($this->fsDouble([
            $this->head('FH_3', 'Zebra Fee', 'academic', 'active', 1),
            $this->head('FH_1', 'Beta Fee',  'academic', 'active', 5),
            $this->head('FH_2', 'Alpha Fee', 'academic', 'active', 1),
        ]))->listForSchool('SCH_B56BB9A401');

        $this->assertSame(['Alpha Fee', 'Zebra Fee', 'Beta Fee'], array_column($out, 'name'));
    }

    public function test_F3_empty_registry_returns_empty_not_an_error(): void
    {
        $this->assertSame([], $this->resolver($this->fsDouble([]))->listForSchool('SCH_B56BB9A401'));
        $this->assertSame([], $this->resolver(null)->listForSchool('SCH_B56BB9A401'));
        $this->assertSame([], $this->resolver($this->fsDouble([]))->listForSchool(''));
    }

    // ── the surface ──────────────────────────────────────────────────────

    public function test_F3_the_free_text_target_is_gone(): void
    {
        $view = file_get_contents(self::VIEW);

        // Comment-stripped: REG-P6-R1's explanatory comment legitimately NAMES
        // the retired element, and a test must not dictate prose. The
        // repo-wide, comment-aware guard lives in EntitlementCaptureContractTest.
        $live = preg_replace('#^\s*(//|\*|/\*).*$#m', ' ', $view);
        $this->assertStringNotContainsString('ecScopeTarget', $live);
        $this->assertStringNotContainsString('e.g. transport / Tuition', $view);
        $this->assertStringContainsString('<select id="ecScopeHead">', $view);
        $this->assertStringContainsString('<select id="ecScopeCat">', $view);
    }

    public function test_F3_scopeValue_emits_ref_and_category_from_pickers_only(): void
    {
        $view = file_get_contents(self::VIEW);
        $this->assertStringContainsString("return 'head:' + (\$('ecScopeHead').value || '').trim();", $view);
        $this->assertStringContainsString("return 'category:' + (\$('ecScopeCat').value || '').trim();", $view);
    }

    public function test_F3_endpoint_delegates_to_the_one_authority(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Entitlements.php');
        $this->assertStringContainsString('public function fee_heads()', $src);
        $this->assertStringContainsString("\$this->load->library('Fee_head_resolver'", $src);
        $this->assertStringNotContainsString("firestoreQuery('feeHeadRegistry'", $src,
            'the controller must not become a second reader of the registry');
        $this->assertStringContainsString("'entitlement_fee_heads', 'Fees', 'edit'", $src);
    }

    /** Legacy scope compatibility is a READER concern and must be untouched. */
    public function test_F3_legacy_scope_compatibility_is_preserved(): void
    {
        $reader = file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $this->assertStringContainsString('$feeHeadId  !== \'\' && $target === $feeHeadId', $reader);
        $this->assertStringContainsString('$feeHead    !== \'\' && $target === $feeHead', $reader);

        $svc = file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
        $this->assertStringContainsString("(legacy: 'head:<feeHeadId>')", $svc,
            'validation still accepts legacy targets — F-3 changes only what the UI can EMIT');
    }

    // ═════════════════════════════════════════════════════════════════════
    //  PR-1 · APPROVE WITH AMENDMENT
    // ═════════════════════════════════════════════════════════════════════

    private function svcWith(array $stored)
    {
        $s = (new ReflectionClass(Entitlement_service::class))->newInstanceWithoutConstructor();
        (function () { $this->schoolId = 'SCH_B56BB9A401'; $this->session = '2026-27'; $this->actorId = 'SSA0011'; })->call($s);

        // Capture the write instead of performing one. Zero Firestore I/O.
        $fb = new class($stored) {
            public array $written = [];
            public function __construct(private array $doc) {}
            public function firestoreGet($c, $id) { return $this->doc; }
            public function firestoreSet($c, $id, $d, $m = false) { $this->written = $d; return true; }
        };
        (function () use ($fb) { $this->firebase = $fb; })->call($s);
        return [$s, $fb];
    }

    /** The placeholder Entitlement_requests actually files. */
    private function parentRequest(array $over = []): array
    {
        return array_merge([
            'entitlementId' => 'E1', 'schoolId' => 'SCH_B56BB9A401', 'studentId' => 'STU0001',
            'kind' => 'concession', 'concessionType' => 'other', 'scope' => 'all',
            'valueType' => 'fixed', 'value' => 0, 'effectiveFrom' => '2026-04-01',
            'effectiveTo' => null, 'status' => 'pending_approval',
            'requestedBy' => 'parent-uid', 'source' => 'parent_request',
        ], $over);
    }

    /** THE DEFECT: approving a ₹0 placeholder unedited must now be refused. */
    public function test_PR1_a_zero_value_request_cannot_be_approved_as_is(): void
    {
        [$svc, $fb] = $this->svcWith($this->parentRequest());
        $res = $svc->approve('E1', 'looks fine');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('carries no value', $res['error']);
        $this->assertSame([], $fb->written, 'nothing may be written on refusal');
    }

    /**
     * DEC-004 UPDATE: this originally amended `scope` too. Scope is now
     * IDENTITY and is not amendable — re-scoping requires a new proposal — so
     * the test amends only the business terms and asserts the stored scope
     * survives untouched. The contract is narrower, not weaker.
     */
    public function test_PR1_the_approver_supplies_the_terms(): void
    {
        [$svc, $fb] = $this->svcWith($this->parentRequest());
        $res = $svc->approve('E1', 'hardship confirmed', [
            'valueType' => 'percent', 'value' => 25,
        ]);

        $this->assertTrue($res['ok']);
        $this->assertSame('active', $fb->written['status']);
        $this->assertSame('all', $fb->written['scope'], 'the proposed scope is preserved verbatim');
        $this->assertNotContains('scope', $fb->written['amendedFields']);
        $this->assertSame('percent', $fb->written['valueType']);
        $this->assertSame(25.0, $fb->written['value']);
        $this->assertSame('SSA0011', $fb->written['approvedBy']);          // I5
        $this->assertSame('SSA0011', $fb->written['amendedBy']);
        $this->assertContains('value', $fb->written['amendedFields']);
    }

    /** The family's original ask survives the decision that answered it. */
    public function test_PR1_the_original_proposal_is_preserved(): void
    {
        [$svc, $fb] = $this->svcWith($this->parentRequest());
        $svc->approve('E1', 'ok', ['valueType' => 'percent', 'value' => 25]);

        $this->assertSame('all',   $fb->written['originalProposal']['scope']);
        $this->assertSame('fixed', $fb->written['originalProposal']['valueType']);
        $this->assertSame(0.0,     $fb->written['originalProposal']['value']);
        $this->assertTrue($fb->written['fingerprintStale'],
            'the docId no longer describes the row — recorded, not hidden (feeds DEC-004)');
    }

    public function test_PR1_fullExempt_is_exempt_from_the_zero_guard(): void
    {
        [$svc, $fb] = $this->svcWith($this->parentRequest());
        $res = $svc->approve('E1', 'RTE', ['valueType' => 'fullExempt', 'value' => 0]);
        $this->assertTrue($res['ok']);
        $this->assertSame('fullExempt', $fb->written['valueType']);
    }

    public function test_PR1_an_amendment_is_validated_by_the_same_rules_as_propose(): void
    {
        foreach ([
            [['valueType' => 'percent', 'value' => 150], 'between 0 and 100'],
            [['valueType' => 'amountDue', 'value' => 5], 'valueType must be'],
            // DEC-004: a scope change is refused by the IDENTITY guard before
            // shape validation is reached — an earlier and stronger refusal, so
            // these two rows now assert that instead of the shape message.
            [['valueType' => 'percent', 'value' => 10, 'scope' => 'nonsense'], 'cannot be changed at approval'],
            [['valueType' => 'percent', 'value' => 10, 'scope' => 'head:'],    'cannot be changed at approval'],
            [['valueType' => 'percent', 'value' => 10, 'effectiveFrom' => '01-04-2026'], 'effectiveFrom must be'],
            [['valueType' => 'percent', 'value' => 10, 'effectiveTo' => '2026-03-01'],   'cannot be earlier'],
        ] as [$amend, $needle]) {
            [$svc, $fb] = $this->svcWith($this->parentRequest());
            $res = $svc->approve('E1', 'ok', $amend);
            $this->assertFalse($res['ok'], 'expected refusal for ' . json_encode($amend));
            $this->assertStringContainsString($needle, $res['error']);
            $this->assertSame([], $fb->written);
        }
    }

    /** A staff entitlement that already carries value approves unchanged. */
    public function test_PR1_a_normal_approval_without_amendment_is_unchanged(): void
    {
        [$svc, $fb] = $this->svcWith($this->parentRequest([
            'scope' => 'head:FH_AAAAAAAAAAAA', 'valueType' => 'percent', 'value' => 10,
            'requestedBy' => 'CLERK1', 'source' => 'manual',
        ]));
        $res = $svc->approve('E1', 'approved');

        $this->assertTrue($res['ok']);
        $this->assertSame([], $res['amended'], 'no amendment ⇒ no amendment metadata');
        foreach (['amendedBy', 'amendedAt', 'originalProposal', 'fingerprintStale', 'scope', 'value'] as $k) {
            $this->assertArrayNotHasKey($k, $fb->written, "{$k} must not appear on an unamended approval");
        }
        $this->assertSame(['status', 'approvedBy', 'approvedAt', 'approvalNote', 'updatedAt'],
            array_keys($fb->written), 'byte-identical to the pre-PR-1 patch');
    }

    // ── invariants that must survive PR-1 ────────────────────────────────

    public function test_PR1_self_approval_is_still_impossible(): void
    {
        [$svc, $fb] = $this->svcWith($this->parentRequest(['requestedBy' => 'SSA0011']));
        $res = $svc->approve('E1', 'ok', ['valueType' => 'percent', 'value' => 25]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('cannot approve an entitlement you requested', $res['error']);
        $this->assertSame([], $fb->written);
    }

    public function test_PR1_only_a_pending_entitlement_can_be_approved(): void
    {
        foreach (['active', 'rejected', 'discontinued', 'proposed'] as $st) {
            [$svc, $fb] = $this->svcWith($this->parentRequest(['status' => $st]));
            $res = $svc->approve('E1', 'ok', ['valueType' => 'percent', 'value' => 25]);
            $this->assertFalse($res['ok'], "status {$st} must not be approvable");
            $this->assertSame([], $fb->written);
        }
    }

    /** Identity and provenance are NOT amendable. */
    public function test_PR1_identity_and_provenance_cannot_be_amended(): void
    {
        [$svc, $fb] = $this->svcWith($this->parentRequest());
        $svc->approve('E1', 'ok', [
            'valueType' => 'percent', 'value' => 25,
            'studentId' => 'STU9999', 'kind' => 'service', 'session' => '2099-00',
            'requestedBy' => 'SSA0011', 'source' => 'manual', 'reason' => 'rewritten',
        ]);
        foreach (['studentId', 'kind', 'session', 'requestedBy', 'source', 'reason'] as $k) {
            $this->assertArrayNotHasKey($k, $fb->written, "{$k} must never be amendable");
        }
    }

    public function test_PR1_the_controller_forwards_only_the_four_pricing_fields(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Entitlements.php');
        $this->assertStringContainsString("'value_type'     => 'valueType'", $src);
        $this->assertStringContainsString("'effective_from' => 'effectiveFrom'", $src);
        foreach (['student_id', 'kind', 'concession_type', 'source'] as $forbidden) {
            $this->assertStringNotContainsString("'{$forbidden}' =>", $src,
                "{$forbidden} must not be in the amend map");
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SCOPE HELD — P6 crosses no open decision
    // ═════════════════════════════════════════════════════════════════════

    public function test_scope_held_no_decision_was_crossed(): void
    {
        $svc = file_get_contents(APPPATH . 'libraries/Entitlement_service.php');

        // DEC-004: identity untouched.
        $this->assertStringContainsString("return \$this->schoolId . '_' . \$studentId . '_E' . substr(sha1(implode('|', \$parts)), 0, 20);", $svc,
            '_fingerprintId must be byte-identical');
        $this->assertStringNotContainsString('ENTITLEMENT_HEAD_SCOPE_SHADOWED',
            file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php'),
            'DD-1 closure is NOT part of P6');

        // DEC-001: gross semantics untouched.
        $this->assertStringNotContainsString('GROSS_AMOUNT_IMMUTABLE',
            file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php'));
        $this->assertStringContainsString("'grossAmount' => \$amount",
            file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'),
            'Transport/Hostel pricing behaviour must be unchanged');

        // No flag moved; no new collection.
        $this->assertStringContainsString("\$config['USE_ENTITLEMENT_BILLING'] = true",
            file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'));
        $this->assertStringContainsString("\$config['USE_FEE_HEAD_REGISTRY'] = true",
            file_get_contents(APPPATH . 'config/fee_head_registry.php'));
        // P4 BOUNDARY — MOVED, NOT DROPPED (2026-08-18).
        //
        // This line used to read `assertFileDoesNotExist(...)`: at P6, the proof
        // that nothing had been crossed was that the allocation ledger did not
        // exist at all. P4 is now separately authorized and the service DOES
        // exist, so that assertion is obsolete BY DESIGN — not a regression, and
        // not a test to delete.
        //
        // The guard it provided is preserved in a stronger form: the ledger may
        // exist, but it must remain INERT — no billing path may call it until
        // P5 wires it. Weakening this to nothing would have removed the only
        // check standing between "P4 designed" and "P4 silently live".
        $allocSvc = APPPATH . 'libraries/Entitlement_allocation_service.php';
        if (file_exists($allocSvc)) {
            $callers = [];
            foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                                 glob(APPPATH . 'libraries/*.php')) as $f) {
                if (basename($f) === 'Entitlement_allocation_service.php') continue;
                if (strpos(file_get_contents($f), 'Entitlement_allocation_service') !== false) {
                    $callers[] = basename($f);
                }
            }
            sort($callers);
                        // P5-BF (2026-08-29): widened for the gated historical backfill.
            // The planner is PURE and the controller persists through
            // Entitlement_allocation_service, so the one-writer property this
            // freeze protects is intact — see
            // P4AllocationLedgerTest::test_only_the_allocation_service_writes_ledger_rows,
            // which asserts that behaviourally and does not need widening when a
            // legitimate READER is added. This same list is duplicated across three
            // freeze tests; that duplication is why all three failed at once.
            $this->assertSame(['Fee_generation_service.php', 'Fee_lifecycle.php', 'Ledger_backfill.php', 'Ledger_backfill_planner.php'], $callers,
                'only the generator may reference the ledger: ' . implode(', ', $callers));
        }
    }
}
