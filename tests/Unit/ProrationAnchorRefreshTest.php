<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Fee_firestore_txn')) {
    require_once APPPATH . 'libraries/Fee_firestore_txn.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PRORATE-1 — A ROUTE CHANGE RE-BILLED THE OLD ROUTE'S FARE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `proRateFees()` scales a demand by the remaining academic months:
 *
 *     $newGross = round($listGross * $factor, 2);
 *     $newDisc  = round($listDisc  * $factor, 2);
 *
 * `listGrossAmount` is the LIST ANCHOR, and it was written once and never
 * refreshed — deliberately, and for a good reason:
 *
 *     "Without them a second proration would compound (2400 → 1200 → 600);
 *      with them every run recomputes from the original, so proration is
 *      IDEMPOTENT and the original charge stays recoverable."
 *
 * That is correct for a charge that never changes. It is wrong the moment one
 * does — and `createModuleFee()` exists to change it. Its own FIN-1 note says
 * it is re-invoked on the SAME demandId "by route change / assignment edit /
 * rollover / reconcile", and the Transport demand id is route-independent by
 * design.
 *
 * ── WHAT IT COST ──────────────────────────────────────────────────────────
 * `Transport_service::changeRoute()` writes the new fare through
 * `createModuleFee()` and then calls `proRateFees()` as its last step:
 *
 *   July    joins a Rs.12,000 route → prorated, anchor stored as 12,000
 *   October moves to a Rs.18,000 route → gross rewritten to 18,000,
 *           anchor still 12,000 → proration yields 12,000 × 0.5 = Rs.6,000
 *
 * Rs.9,000 was correct. An upgrade undercharges the school; a downgrade
 * overcharges the family. Every fee field on the document agrees with every
 * other one — `gross − discount == net` still holds — which is why no
 * invariant check caught it, exactly like ACC-PM1 and SC-ACCT-1 before it.
 *
 * ── THE FIX WAS ALREADY ARGUED FOR IN THE FILE ────────────────────────────
 * Two earlier fixes recognised this exact class and each stopped one field
 * short:
 *
 *   G1  cleared `prorated` / `prorateFactor`, because "a fresh/restored write
 *       of this demand's full amount must clear any prior proRateFees()
 *       reduction"
 *   DEC-001 residual cleared the stale `discountAmount`, because writeDemand()
 *       MERGES and "this method re-prices the SAME demandId in place"
 *
 * Neither touched the anchor — so the flags said "not prorated" while the
 * anchor still held the previous route's price.
 *
 * ── TWO INDEPENDENT CAUSES, BOTH CLOSED ───────────────────────────────────
 * 1. `_buildDemandDoc()` rebuilds its document from named keys and SILENTLY
 *    DISCARDS anything else, so a generator could not have written the anchor
 *    even if it tried.
 * 2. `createModuleFee()` did not supply it.
 *
 * Fixing either alone changes nothing, which is why both are tested here.
 */
class ProrationAnchorRefreshTest extends TestCase
{
    /** @var Fee_firestore_txn */
    private $txn;

    private static string $lifecycle = '';

    public static function setUpBeforeClass(): void
    {
        self::$lifecycle = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
    }

    protected function setUp(): void
    {
        // _buildDemandDoc() reads only its arguments and two untyped string
        // properties (which default to null and stringify to ""), so it runs
        // without a Firestore handle.
        $this->txn = (new ReflectionClass('Fee_firestore_txn'))->newInstanceWithoutConstructor();
    }

    /** Drives the REAL private builder rather than a description of it. */
    private function build(array $data): array
    {
        $m = new ReflectionMethod('Fee_firestore_txn', '_buildDemandDoc');
        $m->setAccessible(true);
        return $m->invoke($this->txn, 'DEM_TEST', $data);
    }

    private function transportDemand(array $over = []): array
    {
        return array_merge([
            'studentId'   => 'STU0012',
            'feeHead'     => 'Transport Fee',
            'category'    => 'transport',
            'frequency'   => 'Annual',
            'period'      => 'April 2026',
            'periodKey'   => '2026-04',
            'grossAmount' => 18000.0,
            'netAmount'   => 18000.0,
        ], $over);
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    /**
     * The builder must really be building. Without this, an `assertArrayNotHasKey`
     * below would pass on an empty array and prove nothing.
     */
    public function test_control_the_builder_produces_a_demand_document(): void
    {
        $doc = $this->build($this->transportDemand());

        $this->assertSame(18000.0, $doc['grossAmount']);
        $this->assertSame('transport', $doc['category']);
        $this->assertSame('April', $doc['month'], 'periodToMonth no longer derives the month');
    }

    // ══ CAUSE 1 — THE WRITE BOUNDARY DISCARDED IT ═══════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the anchor was not in the named-key list, so
     * `_buildDemandDoc()` dropped it silently — no error, no log.
     */
    public function test_the_write_boundary_no_longer_discards_the_anchor(): void
    {
        $doc = $this->build($this->transportDemand([
            'listGrossAmount'    => 18000.0,
            'listDiscountAmount' => 1800.0,
        ]));

        $this->assertArrayHasKey('listGrossAmount', $doc,
            'writeDemand() still discards the proration anchor. proRateFees() computes the '
            . 'charge from this field, so a generator that re-prices a demand cannot refresh '
            . 'the figure its own price will be prorated from.');
        $this->assertSame(18000.0, $doc['listGrossAmount']);
        $this->assertSame(1800.0, $doc['listDiscountAmount']);
    }

    /**
     * Conditional, exactly like `concessionApplied` and `chargeInstance`. A
     * caller that supplies nothing must still write a byte-identical document —
     * otherwise every tuition demand in the estate gains two fields it never
     * had, on a change that is supposed to affect Transport only.
     */
    public function test_a_caller_that_supplies_no_anchor_writes_no_anchor(): void
    {
        $doc = $this->build($this->transportDemand());

        $this->assertArrayNotHasKey('listGrossAmount', $doc,
            'the anchor is now emitted unconditionally, so every demand written by every '
            . 'caller gains a field it did not have before');
        $this->assertArrayNotHasKey('listDiscountAmount', $doc);
    }

    /** Money fields must be stored as floats, not whatever the caller passed. */
    public function test_the_anchor_is_coerced_to_float(): void
    {
        $doc = $this->build($this->transportDemand([
            'listGrossAmount'    => '18000',
            'listDiscountAmount' => '1800',
        ]));

        $this->assertSame(18000.0, $doc['listGrossAmount'],
            'a string anchor reached the document. proRateFees() multiplies this value, so a '
            . 'non-numeric type here is a money bug one step later.');
        $this->assertIsFloat($doc['listDiscountAmount']);
    }

    // ══ CAUSE 2 — THE GENERATOR DID NOT SUPPLY IT ═══════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: `createModuleFee()` wrote `grossAmount` and
     * cleared `prorated`, but left the anchor untouched.
     */
    public function test_the_module_generator_refreshes_the_anchor_with_the_charge(): void
    {
        $at = strpos(self::$lifecycle, 'public function createModuleFee');
        $this->assertNotFalse($at, 'createModuleFee was renamed');

        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString("'listGrossAmount'    => \$amount", $body,
            'createModuleFee() writes a new charge without refreshing the proration anchor. '
            . 'A route change then prorates the PREVIOUS route\'s fare.');
        $this->assertStringContainsString("'listDiscountAmount' => \$deduction", $body,
            'the discount half of the anchor is not refreshed, so a demand that once carried '
            . 'a service concession keeps prorating against it after the concession is gone');
    }

    /**
     * The anchor must be the LIST charge, not the prorated one. `$amount` is
     * the full module fee this method was called with; `$net` may already carry
     * an entitlement deduction. Anchoring on `$net` would fold the discount in
     * twice — once in the anchor, once again when proration scales `listDisc`.
     */
    public function test_the_anchor_is_the_list_charge_not_the_discounted_one(): void
    {
        $at   = strpos(self::$lifecycle, 'public function createModuleFee');
        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringNotContainsString("'listGrossAmount'    => \$net", $body,
            "the anchor was set to the NET amount. proRateFees() computes "
            . 'net = listGross - listDisc, so anchoring on an already-discounted figure '
            . 'applies the concession twice.');
    }

    /**
     * The G1 clearing must survive. If `prorated` stopped being reset, a
     * re-priced demand would report itself as still prorated while carrying a
     * full-fare amount — the inconsistency G1 was written to close.
     */
    public function test_the_g1_proration_flags_are_still_cleared(): void
    {
        $at   = strpos(self::$lifecycle, 'public function createModuleFee');
        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString("'prorated'      => false", $body,
            'the G1 flag clearing was lost while adding the anchor');
        $this->assertStringContainsString("'prorateFactor' => 0", $body);
    }

    // ══ THE ARITHMETIC THE ANCHOR FEEDS ═════════════════════════════════════

    /**
     * `proRateFees()` must still compute from the anchor — that is the whole
     * reason refreshing it matters. If it ever went back to reading
     * `grossAmount` directly, repeated proration would compound again and this
     * fix would be pointless rather than merely unnecessary.
     */
    public function test_proration_still_computes_from_the_anchor(): void
    {
        $at   = strpos(self::$lifecycle, 'public function proRateFees');
        $this->assertNotFalse($at);
        $body = substr(self::$lifecycle, $at, 6000);

        $this->assertStringContainsString('$newGross  = round($listGross * $factor, 2)', $body,
            'proration no longer scales the list anchor. If it now reads grossAmount directly, '
            . 'a second proration compounds (2400 -> 1200 -> 600) — the defect the anchor was '
            . 'introduced to prevent.');
        $this->assertStringContainsString('$newDisc   = round($listDisc  * $factor, 2)', $body);
    }

    /**
     * The fall-back when no anchor exists must remain the CURRENT gross, so
     * every demand written before this change prorates exactly as it does
     * today. Without this the fix would silently re-price historical demands.
     */
    public function test_a_demand_with_no_anchor_still_prorates_from_its_current_gross(): void
    {
        $at   = strpos(self::$lifecycle, 'public function proRateFees');
        $body = substr(self::$lifecycle, $at, 6000);

        $this->assertStringContainsString(
            "\$listGross = array_key_exists('listGrossAmount', \$d)",
            $body,
            'the anchor fallback changed — pre-existing demands carry no anchor and must keep '
            . 'prorating from their current gross');
        $this->assertStringContainsString('? (float) $d[\'listGrossAmount\'] : $curGross;', $body);
    }

    // ══ SCOPE ═══════════════════════════════════════════════════════════════

    /**
     * Proration is Transport-only today: all five `proRateFees()` call sites
     * live in `Transport_service`. That is why this change is scoped to the
     * module generator and the academic path was deliberately not touched.
     *
     * Recorded as a test because the reasoning stops holding the moment a
     * non-Transport caller appears — at which point the academic generator
     * needs the same anchor refresh and this file should be re-derived.
     */
    public function test_proration_is_still_reached_only_from_transport(): void
    {
        $callers = [];
        foreach (['libraries', 'controllers', 'services'] as $dir) {
            $path = APPPATH . $dir;
            if (!is_dir($path)) continue;
            foreach (scandir($path) as $f) {
                if (substr($f, -4) !== '.php') continue;
                $src = (string) file_get_contents($path . DIRECTORY_SEPARATOR . $f);
                // Strip comments so the many prose references do not count.
                $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
                $src = (string) preg_replace('!^\s*//.*$!m', '', $src);
                if (strpos($src, '->proRateFees(') !== false) $callers[] = $f;
            }
        }

        // WAS ['Transport_service.php']; is now EMPTY, and that is the point.
        //
        // proRateFees() answers "you JOINED in month X, pay the rest of the
        // year". Right for a joiner — and the exact mechanism behind
        // TRN-PRORATE-INVERT-1 once Transport started feeding it a STOP date:
        // a student who left in February was billed February and March, and
        // nothing for the six months they had actually travelled.
        //
        // Transport now derives billable months from its own recorded service
        // periods, so it no longer prorates at all. The safety property this
        // test was written to guard — that nothing prorates ACADEMIC demands,
        // the path whose stale-anchor defect was deliberately left untouched —
        // is therefore satisfied more strongly than before, not weakened.
        $this->assertSame([], array_values(array_unique($callers)),
            'proRateFees() has a caller again. Any new caller must be reviewed against '
            . 'TRN-PRORATE-INVERT-1 first: the function takes a JOIN date, and passing it any '
            . 'other kind of date silently bills the wrong months.');
    }
    // ══ DUE-2 · THE DUE DATE MUST REACH THE DEMAND ══════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE — and was proven on a real device, not inferred.
     *
     * `Student_charge_service` computes a due date and passes it into
     * `createModuleFee()`. That payload never carried it onward, and
     * `_buildDemandDoc()` builds from named keys, so it was discarded silently.
     *
     * A Student Charge raised today showed in the Parent app as
     * "Overdue · 129d" — April 16 to today — because with no dueDate the app
     * correctly falls back to inferring one from the month label, and every
     * module charge is filed under the session start.
     *
     * One missing field, three broken consumers: the parent saw a charge four
     * months late on the day it was raised, the late-fee engine could never
     * fine it (`fineFor()` returns null on an empty dueDate), and the reminder
     * service could never chase it. That is exactly the condition ID-DUE-1
     * was written to prevent.
     */
    public function test_a_supplied_due_date_reaches_the_demand(): void
    {
        $at   = strpos(self::$lifecycle, 'public function createModuleFee');
        $this->assertNotFalse($at);
        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString(
            "'dueDate'     => (string) (\$feeData['dueDate'] ?? \$feeData['due_date'] ?? ''),", $body,
            'createModuleFee still drops a caller-supplied dueDate. A one-off charge then '
            . 'displays as months overdue the day it is raised, and is invisible to both the '
            . 'late-fee engine and the reminder service.');
    }

    /** The write boundary must still accept it — dropping it there is the same defect. */
    public function test_the_write_boundary_persists_the_due_date(): void
    {
        $doc = $this->build($this->transportDemand(['dueDate' => '2026-09-22']));

        $this->assertSame('2026-09-22', $doc['dueDate'],
            'the demand document no longer carries dueDate, so nothing downstream can chase it');
    }

    /**
     * A caller that supplies none must still write an empty string rather than
     * omitting the field — the Parent app treats blank as "infer from the
     * month", which is the correct fallback for Transport and Hostel.
     */
    public function test_a_module_charge_without_a_due_date_is_still_written(): void
    {
        $doc = $this->build($this->transportDemand());

        $this->assertArrayHasKey('dueDate', $doc,
            'the field vanished entirely for callers that supply no date, so a reader cannot '
            . 'tell "no due date" from "field missing"');
        $this->assertSame('', $doc['dueDate']);
    }
}
