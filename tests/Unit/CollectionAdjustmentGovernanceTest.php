<?php
/**
 * ADJ-GOV-1 / ADJ-GOV-2 — the collection-layer discount is a governed
 * financial authority, and there is exactly ONE of it.
 *
 * WHAT WAS WRONG. `studentDiscounts.onDemandDiscount` is REAL money at the
 * COLLECTION layer, not a summary:
 *   • `Fees::fetch_months` computes `netDue = max(0, totalRemaining - discAmt)`
 *   • `FeeCollectionService` uses the same field as the authorised CAP on what
 *     a clerk may discount on a receipt.
 *
 * PROVEN at runtime: a `Fees: edit` user set 999,999 against a student owing
 * 8,000 — accepted, reason stored empty, netDue driven to 0, totalDiscount
 * accumulated to 1,001,999.
 *
 * ADJ-GOV-1 closed the two doors in Fees.php. ADJ-GOV-2 found THREE MORE in
 * Fee_management.php — `apply_discount` (rule/bulk), scholarship award and
 * scholarship revoke — of which `apply_discount` is the worst: it writes
 * `onDemandDiscount` for an entire selection of students in one click, capped
 * only by the policy's optional `max_discount`, which defaults to 0 = NO CAP.
 *
 * Two inline copies of a money rule is exactly how `apply_discount` stayed
 * uncapped after `submit_discount` was fixed. So the rule now lives in ONE
 * library and every door calls it.
 *
 * WHAT IS AND IS NOT A SCHOOL CHOICE. Schools genuinely need to waive an amount
 * at the desk, so the capability is governed rather than removed. The school
 * may set a TIGHTER ceiling. It may not choose whether a waiver needs a reason,
 * or whether it may exceed the bill — those are platform invariants.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Benefit_policy_service.php';
require_once __DIR__ . '/../../application/libraries/Collection_adjustment_policy.php';

final class CollectionAdjustmentGovernanceTest extends TestCase
{
    private const FEES = __DIR__ . '/../../application/controllers/Fees.php';
    private const FMGT = __DIR__ . '/../../application/controllers/Fee_management.php';
    private const FCS  = __DIR__ . '/../../application/services/FeeCollectionService.php';
    private const LIB  = __DIR__ . '/../../application/libraries/Collection_adjustment_policy.php';

    private function src(string $f): string { return (string) file_get_contents($f); }

    /** Firestore double: demands + an optional policy document. */
    private function fb(array $demands, ?array $policy = null)
    {
        return new class($demands, $policy) {
            public function __construct(private array $demands, private ?array $policy) {}
            public function firestoreQuery(string $c, array $conds): array
            {
                $out = [];
                foreach ($this->demands as $d) {
                    $ok = true;
                    foreach ($conds as [$f, $op, $v]) {
                        if ($op === '==' && (($d[$f] ?? null) !== $v)) { $ok = false; break; }
                    }
                    if ($ok) $out[] = ['data' => $d];
                }
                return $out;
            }
            public function get(string $c, string $id) { return $this->policy; }
            public function set(string $c, string $id, array $d, bool $m = false): bool { return true; }
            public function firestoreGet(string $c, string $id) { return $this->policy; }
            public function firestoreSet(string $c, string $id, array $d, bool $m = false): bool { return true; }
        };
    }

    private function demand(float $balance, string $status = 'unpaid', string $session = '2026-27'): array
    {
        return ['schoolId' => 'SCH_T1', 'studentId' => 'STU1', 'session' => $session,
                'status' => $status, 'balance' => $balance];
    }

    private function policy(array $demands, ?array $pol = null): Collection_adjustment_policy
    {
        return (new Collection_adjustment_policy())
            ->init($this->fb($demands, $pol), 'SCH_T1', '2026-27', 'SSA9001');
    }

    // ══ ONE AUTHORITY, FIVE DOORS ══════════════════════════════════════════

    /** Every writer of onDemandDiscount must pass the same gate. */
    public function test_all_discount_writers_route_through_the_one_authority(): void
    {
        $this->assertStringContainsString('_adjustmentPolicy()->refusal(', $this->src(self::FEES),
            'the fee counter doors must delegate to the shared authority');
        $this->assertStringContainsString('_adjustmentPolicy()->refusal(', $this->src(self::FMGT),
            'apply_discount writes onDemandDiscount in BULK and must pass the same gate — '
            . 'this is the door that stayed uncapped after the counter was fixed');
    }

    /** The gate must not be re-implemented inline anywhere. */
    public function test_the_rule_is_not_duplicated_inline(): void
    {
        foreach ([self::FEES, self::FMGT] as $f) {
            $this->assertStringNotContainsString('ADJ_GOV_REFUSED_OVER_OUTSTANDING', $this->src($f),
                'the refusal belongs to the library. A second copy is how the doors diverged.');
        }
        $this->assertStringContainsString('ADJ_GOV_REFUSED_OVER_OUTSTANDING', $this->src(self::LIB));
    }

    // ══ PLATFORM INVARIANTS — BEHAVIOUR, NOT SOURCE TEXT ═══════════════════

    /** A waiver may never exceed what the student actually owes. */
    public function test_an_adjustment_cannot_exceed_the_outstanding_balance(): void
    {
        $p = $this->policy([$this->demand(8000)]);
        $this->assertNull($p->refusal('STU1', 8000, 'hardship'), '8,000 against 8,000 owed is allowed');
        $this->assertNotNull($p->refusal('STU1', 999999, 'hardship'),
            '999,999 against an 8,000 bill was ACCEPTED before this gate existed');
        $this->assertStringContainsString('8,000.00', (string) $p->refusal('STU1', 999999, 'x'),
            'the refusal must name the real outstanding so the operator can act on it');
    }

    /** Paid and archived periods cannot be discounted again. */
    public function test_settled_periods_are_excluded_from_the_ceiling(): void
    {
        $p = $this->policy([
            $this->demand(5000, 'paid'), $this->demand(3000, 'archived'),
            $this->demand(3000, 'cancelled'), $this->demand(2000, 'unpaid'),
        ]);
        $this->assertSame(2000.0, $p->outstanding('STU1'),
            'only the live unpaid balance may be waived');
    }

    /** The ceiling is scoped to THIS session. */
    public function test_the_ceiling_is_session_scoped(): void
    {
        $p = $this->policy([$this->demand(2000), $this->demand(9000, 'unpaid', '2025-26')]);
        $this->assertSame(2000.0, $p->outstanding('STU1'),
            "last year's demands must not enlarge this year's waiver ceiling");
    }

    /** An unreadable outstanding refuses, rather than allowing an uncapped waiver. */
    public function test_an_unreadable_outstanding_fails_closed(): void
    {
        $fb = new class {
            public function firestoreQuery(string $c, array $x): array { throw new \RuntimeException('down'); }
            public function get(string $c, string $i) { return null; }
        };
        $p = (new Collection_adjustment_policy())->init($fb, 'SCH_T1', '2026-27', 'SSA9001');
        $this->assertSame(0.0, $p->outstanding('STU1'));
        $this->assertNotNull($p->refusal('STU1', 1, 'hardship'),
            'if the outstanding cannot be established the adjustment must be REFUSED — '
            . 'the alternative is permitting an uncapped one');
    }

    /** Every waiver carries a reason. */
    public function test_a_reason_is_mandatory(): void
    {
        $p = $this->policy([$this->demand(8000)]);
        $this->assertNotNull($p->refusal('STU1', 100, ''),   'empty reason must be refused');
        $this->assertNotNull($p->refusal('STU1', 100, '   '), 'whitespace is not a reason');
        $this->assertNull($p->refusal('STU1', 100, 'sibling hardship'));
    }

    /** A negative adjustment is not a payment channel. */
    public function test_a_negative_adjustment_is_refused(): void
    {
        $this->assertNotNull($this->policy([$this->demand(8000)])->refusal('STU1', -50, 'x'));
    }

    // ══ SCHOOL POLICY — THE TIGHTER CEILING ════════════════════════════════

    /** An unconfigured school still has the invariant ceiling. */
    public function test_an_unconfigured_school_falls_back_to_the_outstanding_ceiling(): void
    {
        $p = (new Benefit_policy_service())->init(
            $this->fb([], null), 'SCH_T1', '2026-27', 'SSA9001')->resolveAdjustment();
        $this->assertSame(0.0, $p['maxAmount'],  '0 means no EXTRA school ceiling…');
        $this->assertSame(0.0, $p['maxPercent'], '…not "no limit" — the outstanding cap still applies');
    }

    /** A school ceiling tightens; it can never widen past the outstanding. */
    public function test_a_school_ceiling_only_tightens(): void
    {
        $pol = ['version' => 2, 'collection_adjustment' => ['maxAmount' => 500, 'maxPercent' => 0]];
        $p = $this->policy([$this->demand(8000)], $pol);
        $this->assertNull($p->refusal('STU1', 500, 'ok'));
        $this->assertNotNull($p->refusal('STU1', 600, 'over the school limit'));
    }

    /** A caller-supplied cap (a discount policy's max_discount) may only tighten. */
    public function test_a_caller_cap_cannot_widen_the_invariant(): void
    {
        $p = $this->policy([$this->demand(1000)]);
        $this->assertNotNull($p->refusal('STU1', 5000, 'bulk rule', 9999),
            'a policy max_discount of 9,999 must NOT authorise more than the student owes');
    }

    /**
     * The same rule, isolated from the outstanding ceiling.
     *
     * The test above cannot see this branch: 5,000 against 1,000 owed is
     * already refused by the invariant, so a `max(...)`-shaped bug would still
     * look correct. Here the outstanding is generous and the SCHOOL ceiling is
     * the binding one, so a caller cap that replaced instead of tightened it
     * would let 800 through.
     */
    public function test_a_caller_cap_cannot_loosen_a_tighter_school_ceiling(): void
    {
        $pol = ['version' => 2, 'collection_adjustment' => ['maxAmount' => 500, 'maxPercent' => 0]];
        $p = $this->policy([$this->demand(10000)], $pol);

        $this->assertNull($p->refusal('STU1', 400, 'within both caps', 9999));
        $this->assertNotNull($p->refusal('STU1', 800, 'over the school ceiling', 9999),
            'the school ceiling of 500 must still bind even when the caller passes 9,999 — '
            . 'a caller cap may only ever TIGHTEN');
    }

    // ══ SESSION STAMPING ═══════════════════════════════════════════════════

    /** Every write is stamped with its session. */
    public function test_writes_are_session_stamped(): void
    {
        $s = $this->policy([])->stamp('hardship', 'fees_counter_inline');
        $this->assertSame('2026-27', $s['session']);
        $this->assertSame('SCH_T1',  $s['schoolId']);
        $this->assertSame('SSA9001', $s['appliedBy']);
        $this->assertSame('hardship', $s['reason']);
    }

    /**
     * A discount stamped for another session must not reduce this session's
     * collection — but a LEGACY unstamped document keeps working untouched.
     */
    public function test_session_scope_is_enforced_without_reinterpreting_legacy_records(): void
    {
        $p = $this->policy([]);
        $this->assertTrue($p->appliesToSession(['onDemandDiscount' => 500]),
            'a legacy record with no session stamp must keep its current meaning');
        $this->assertTrue($p->appliesToSession(['session' => '2026-27']));
        $this->assertFalse($p->appliesToSession(['session' => '2025-26']),
            "last year's waiver must not silently reduce this year's collection");
    }

    /** All three money readers honour the stamp. */
    public function test_the_money_readers_enforce_session_scope(): void
    {
        $fees = $this->src(self::FEES);
        $this->assertSame(2, substr_count($fees, 'appliesToSession('),
            'both fetch_months (counter netDue) and fetch_fee_details must enforce it');
        // Assert the guard is IN THE CONDITION, not merely present in the file.
        // A mutant that computed $__wrongSession and then ignored it survived an
        // earlier version of this assertion — the variable existed, the money
        // was still authorised.
        $this->assertStringContainsString('if (!$__expired && !$__wrongSession) {', $this->src(self::FCS),
            'the server-side receipt cap must not be authorised by another session\'s discount, '
            . 'and the check must actually gate the assignment');
    }

    // ══ SD-2 · REPORTING COHERENCE ═════════════════════════════════════════

    /** The truthful current reduction is derived, not read from a drifting tally. */
    public function test_the_current_reduction_is_derived_from_the_governing_fields(): void
    {
        $this->assertSame(1500.0, Collection_adjustment_policy::currentReduction(
            ['onDemandDiscount' => 1000, 'scholarshipDiscount' => 500, 'totalDiscount' => 999999]),
            'totalDiscount is a drifting tally; the truth is onDemand + scholarship');
        $this->assertSame(0.0, Collection_adjustment_policy::currentReduction(
            ['onDemandDiscount' => 1000, 'validUntil' => '2000-01-01']),
            'an expired discount reduces nothing');
        $this->assertSame(0.0, Collection_adjustment_policy::currentReduction([]));
    }

    /** Both counter doors now store the SAME thing (SD-2). */
    public function test_the_two_counter_doors_store_a_coherent_total(): void
    {
        $fees = $this->src(self::FEES);
        $this->assertSame(2, substr_count($fees, "'totalDiscount'       => round(\$am"),
            'set_student_discount and submit_discount must both store onDemand + scholarship. '
            . 'The accumulating form made the same grant produce different stored state '
            . 'depending on which screen the operator used.');
        $this->assertStringNotContainsString('$curTotal + (int) $discount', $fees,
            'the accumulating total is retired');
        $this->assertStringNotContainsString("'onDemandDiscount'    => (int) \$discount", $fees,
            'the (int) cast silently truncated a 500.50 waiver to 500');
    }

    // ══ SD-3 · THE AUDIT TRAIL ═════════════════════════════════════════════

    /**
     * The discount trail must actually be written. Production runs
     * log_threshold=1 (errors only), so the previous log_message('info', …)
     * recorded NOTHING — the same defect class as LOG-1.
     */
    public function test_the_discount_audit_trail_is_actually_recorded(): void
    {
        foreach ([self::FEES, self::FMGT] as $f) {
            $src = $this->src($f);
            $this->assertStringNotContainsString("log_message('info', sprintf(\n                'ACC_DISCOUNT_WRITE", $src,
                'an info-level line on a money path writes nothing at threshold 1');
            $this->assertStringContainsString('_adjustmentPolicy()->audit(', $src,
                'every discount write must reach the canonical feeAuditLogs trail');
        }
        $this->assertStringContainsString("'discount'", $this->src(self::LIB));
        $this->assertStringContainsString('Fee_audit_logger', $this->src(self::LIB),
            'reuse the canonical trail that demands, receipts, payments and refunds use — '
            . 'a parallel audit subsystem would be a second source of truth');
    }

    // ══ ADJ-GOV-3 · ATTRIBUTION SURVIVES THE WRITE BOUNDARY ════════════════

    /**
     * A mandatory reason is worthless if the storage layer discards it.
     *
     * `Fee_firestore_txn::updateDiscount` built a FIXED document, so `reason`,
     * `appliedBy`, `appliedAt`, `source` and `validUntil` were all dropped —
     * and because the write is a full overwrite, anything previously stored was
     * erased too. PROVEN at runtime before the fix: a waiver written through
     * the real endpoint landed with an empty reason and no actor.
     *
     * This is the root cause of SD-1 in STUDENT_DISCOUNTS_FORENSIC_AUDIT, which
     * found a live school carrying a permanent, unattributed reduction and
     * concluded its provenance was "unknown and unknowable from code". It was
     * unknowable because the writer threw it away.
     *
     * `validUntil` matters equally: it is the only temporal control on an
     * otherwise permanent discount, and both the counter and the server-side
     * receipt cap read it. Dropping it turned an expiring waiver into a
     * forever one.
     */
    public function test_the_discount_writer_preserves_attribution_and_expiry(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fee_firestore_txn.php');
        $at  = strpos($src, 'function updateDiscount');
        $this->assertNotFalse($at, 'the discount writer no longer exists');
        // Bounded to the METHOD, not a fixed byte window: a window that stops
        // short reads as "the code is missing" and would have passed this test
        // for the wrong reason.
        $end  = strpos($src, "\n    public function ", $at + 10);
        $body = substr($src, $at, ($end ?: strlen($src)) - $at);

        foreach (['reason', 'appliedBy', 'appliedAt', 'source', 'validUntil'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "updateDiscount must persist '{$field}'. A gate that makes a reason mandatory "
                . 'is defeated if the write boundary discards it one call later.');
        }
        $this->assertStringContainsString('$existing[$keep]', $body,
            'an unrelated update must not erase attribution that is already stored');
    }

    // ══ ADJ-GOV-4 · THE MIRROR MUST NOT ERASE THE ORIGINAL ═════════════════

    /**
     * `Fee_firestore_sync::syncDiscount` is the SIXTH writer of
     * `studentDiscounts` and runs immediately after `apply_discount` has
     * written the same document. It builds a fixed payload and does a full
     * `firestoreSet`, so everything it does not name is wiped.
     *
     * Fixing the write boundary in `updateDiscount` (ADJ-GOV-3) was NOT enough:
     * this mirror silently undid it microseconds later. Only the runtime check
     * caught that — a governed bulk apply landed with an empty reason.
     *
     * `validUntil` matters most here: `apply_discount` explicitly accepts
     * `valid_until` and dual-emits it, and both the counter and the receipt cap
     * read it. Dropping it turned an EXPIRING bulk discount into a permanent
     * one — the school sets an end date and the system quietly ignores it.
     */
    public function test_the_discount_mirror_preserves_attribution_and_expiry(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fee_firestore_sync.php');
        $at  = strpos($src, 'function syncDiscount');
        $this->assertNotFalse($at, 'the discount mirror no longer exists');
        $end  = strpos($src, "\n    public function ", $at + 10);
        $body = substr($src, $at, ($end ?: strlen($src)) - $at);

        foreach (['reason', 'appliedBy', 'source', 'validUntil'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "the mirror must carry '{$field}' through. A mirror may restate a record, "
                . 'never quietly strip it.');
        }
        $this->assertStringContainsString('$existing[$keep]', $body,
            'a mirror write must preserve what it was not given');
    }

    // ══ ADJ-GOV-5 · THE PERCENTAGE BASE MUST ACTUALLY RESOLVE ══════════════

    /**
     * `apply_discount` built the feeStructures key by STRIPPING the "Class "
     * and "Section " prefixes (`10th_A`), while the documents are stored — and
     * read by every other consumer — under the canonical
     * `{schoolId}_{session}_{className}_{section}` with the FULL names.
     *
     * The lookup therefore always missed, the percentage base was always 0, and
     * every percentage-type rule discount silently computed ZERO while the
     * endpoint still reported "Discount applied to N student(s)". That is a
     * phantom success on money: the operator is told a class received 10% and
     * nothing happened. PROVEN at runtime — 0.00 before, 60.00 after.
     */
    public function test_the_percentage_base_uses_the_canonical_structure_key(): void
    {
        $src = $this->src(self::FMGT);

        $this->assertStringNotContainsString(
            "str_ireplace(['Class ', 'Section '], '', \$class) . '_' . str_ireplace('Section ', '', \$section)",
            $src,
            'stripping the prefixes produces a key that matches no stored document, so the '
            . 'percentage base silently resolves to zero');
        $this->assertStringContainsString("\$csKey = \$class . '_' . \$section;", $src,
            'the canonical key keeps the full class and section names');
    }

    // ══ ADJ-GOV-6 · THE AUTHORISATION IS CONSUMED BY THE RECEIPT ═══════════

    /**
     * `onDemandDiscount` is a SINGLE STANDING REDUCTION — `fetch_months`
     * subtracts it once from the whole outstanding. But the receipt path
     * re-read it fresh on every collection and never decremented it, so one
     * approved waiver could be spent again on the next receipt, and the next.
     *
     * PROVEN at runtime before the fix: a 500 waiver was accepted on receipt 1
     * AND receipt 2 — two receipts each recording discount=500 — with
     * `onDemandDiscount` still standing at 500 afterwards. After the fix the
     * same sequence gives 500 -> 0 and the second attempt is refused
     * ("exceeds the approved concession Rs. 0.00").
     */
    public function test_a_receipt_consumes_the_authorisation_it_spends(): void
    {
        $fcs = $this->src(self::FCS);

        $this->assertStringContainsString('$__remainingAuth = round(max(0.0, $approvedDiscountCap - $discountFees), 2);', $fcs,
            'the receipt must reduce the standing authorisation by what it used, and never below zero');
        $this->assertStringContainsString("'onDemandDiscount' => \$__remainingAuth", $fcs,
            'and must WRITE the reduced authorisation back — re-reading an unchanged field '
            . 'is what let one approval be spent on every receipt');
        $this->assertStringContainsString('ACC_DISCOUNT_CONSUMED', $fcs,
            'consumption of an approved waiver is a money event and must be recorded');
    }

    /**
     * The receipt path must read the session from the DATA it was given, not
     * from a protected controller property.
     *
     * `MY_Controller::$session_year` is protected and FeeCollectionService is a
     * separate class, so `$controller->session_year` throws — and the
     * surrounding `\Throwable` catch swallowed it, left the cap at 0 and
     * silently REJECTED EVERY counter discount. No unit test caught that; only
     * driving the real endpoint and reading the log did.
     */
    public function test_the_receipt_cap_reads_the_session_from_its_own_data(): void
    {
        // EXECUTABLE CODE ONLY. The fix is explained in a comment that names
        // the very expression being banned, so a raw source search matches the
        // documentation and passes for the wrong reason.
        $code = '';
        foreach (token_get_all($this->src(self::FCS)) as $t) {
            $code .= is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1])
                : $t;
        }

        $this->assertStringNotContainsString('$controller->session_year', $code,
            'session_year is PROTECTED on MY_Controller — reading it from this class throws, '
            . 'and the catch turns that into a silent rejection of every discount');
        $this->assertStringContainsString('$__docSession !== (string) $session', $code,
            'use $session, taken from $data[\'session_year\']');
    }

    // ══ ADJ-GOV-7 · A RETIRED SOURCE MAY NOT ZERO LIVE MONEY ═══════════════

    /**
     * `syncAllDiscountsAndMonthFees()` reads discounts from RTDB — a store
     * retired by ZERO-RTDB — and feeds `syncDiscount()`, which is a FULL
     * overwrite. An empty read would therefore zero `onDemandDiscount` and
     * `scholarshipDiscount` for every student it walks, wiping live approved
     * concessions across a whole school.
     *
     * It is reachable: `fee_management/firestore_bulk_sync` is a real endpoint
     * open to any `Fees: manage` admin. It was inert only because a missing
     * RTDB path returns NULL and `is_array(null)` is false — luck, not design.
     */
    public function test_an_empty_legacy_source_cannot_overwrite_a_live_discount(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fee_firestore_sync.php');

        $this->assertStringContainsString('ADJ_GOV_RTDB_SYNC_REFUSED', $src,
            'an empty legacy source must be refused loudly, not applied silently');
        $this->assertStringContainsString('if ($__hasSource) {', $src,
            'the sync must run only when the legacy source actually carries a discount');
        $this->assertStringNotContainsString("\$disc = \$this->firebase->get(\"{\$base}/Discount\");\n                        if (is_array(\$disc)) {", $src,
            'a bare is_array() guard treats an empty shell as real data');
    }

    // ══ SD-2 · totalDiscount IS DERIVED ON EVERY DOOR ══════════════════════

    /**
     * The bulk door accumulated `existing total + this discount`, so the figure
     * grew on every rule application and never fell when the governing
     * `onDemandDiscount` was reduced or consumed. The school-wide discount
     * report sums this field, so it over-reported without limit.
     *
     * OBSERVED on the persisted document before the fix: onDemandDiscount 60,
     * scholarshipDiscount 0, totalDiscount 2,740. After: 60 / 0 / 60.
     */
    public function test_the_bulk_door_derives_the_reported_total(): void
    {
        $src = $this->src(self::FMGT);

        $this->assertStringNotContainsString('$newTotal = $existingAmt + $discountAmount;', $src,
            'an accumulating total drifts from the money it claims to report');
        $this->assertStringContainsString('$newTotal = round($discountAmount + $__scholNow, 2);', $src,
            'the reported total is the sum of the parts that actually govern money');
    }

    // ══ ADJ-GOV-8 · THE DUAL-EMITTED EXPIRY PAIR AGREES ════════════════════

    /**
     * Each writer supplies only ONE of `validUntil` / `valid_until`, so the
     * attribution-preserving loops could pair a caller's camel value with a
     * STALE stored snake value. Every reader is camel-first and `??` only falls
     * through on NULL, so this was harmless today — but a record that
     * contradicts itself sets up the dangerous reading: "" = never expires.
     */
    public function test_the_expiry_pair_is_normalised_by_both_writers(): void
    {
        foreach ([__DIR__ . '/../../application/libraries/Fee_firestore_txn.php',
                  __DIR__ . '/../../application/libraries/Fee_firestore_sync.php'] as $f) {
            $src = (string) file_get_contents($f);
            $this->assertStringContainsString("\$doc['validUntil']  = \$__vu;", $src,
                basename($f) . ' must normalise the expiry pair');
            $this->assertStringContainsString("\$doc['valid_until'] = \$__vu;", $src,
                basename($f) . ' must echo the authoritative camel key to the snake key');
        }
    }

    /**
     * The counter waiver is deliberately NOT in BENEFITS. Those are evaluated
     * automatically against verified relationships; an ad-hoc waiver must never
     * be granted by any automatic path.
     */
    public function test_the_adjustment_is_not_an_automatically_awardable_benefit(): void
    {
        $this->assertNotContains(Benefit_policy_service::ADJUSTMENT, Benefit_policy_service::BENEFITS,
            'a counter waiver must never be reachable by the automatic benefit evaluator');
    }
}
