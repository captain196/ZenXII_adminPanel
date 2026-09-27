<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P5-BF · HISTORICAL ALLOCATION LEDGER BACKFILL — CONTROLLED WRITER
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php index.php ledger_backfill analyze <SCHOOL_ID> [SESSION]     (default, no writes)
 *   php index.php ledger_backfill execute <SCHOOL_ID> [SESSION] --commit
 *
 * Repairs the LEDGER REPRESENTATION of discounts that were already given. It
 * creates `entitlementAllocations` rows explaining an existing
 * `discountAmount`. It changes no charge, no payment, no receipt, no journal
 * and no balance — see "AMOUNT PRESERVATION" below.
 *
 * ── THE STAGES ARE DELIBERATELY SEPARATE ──────────────────────────────────
 *      read → plan → validate → decide → persist(only in execute) → reconcile
 *
 * `Ledger_backfill_planner` owns the DECISION and is pure, so every rule is
 * unit-tested against fixtures rather than reviewed by reading.
 * `Entitlement_allocation_service` owns IDENTITY, tenant/session isolation and
 * over-allocation refusal, and performs the write. This controller only moves
 * data between them. There is no second persister and no second definition of
 * what an allocation is — `P4AllocationLedgerTest` freezes that boundary.
 *
 * ── WRITING IS OPT-IN, TWICE ──────────────────────────────────────────────
 * The default mode writes nothing. `execute` additionally requires `--commit`.
 * A run without both is an analysis, whatever else is on the command line.
 *
 * ── IDEMPOTENCY IS STRUCTURAL, NOT CHECKED ────────────────────────────────
 * Allocation ids are deterministic — `{schoolId}_{demandId}_{entitlementId}` —
 * and `persist()` writes with create-if-absent, reporting COLLISION rather
 * than overwriting. So a re-run cannot double the ledger even if the planner
 * were wrong, and an interrupted run completes rather than duplicating.
 *
 * ── AMBIGUITY FAILS CLOSED ────────────────────────────────────────────────
 * A demand whose split cannot be reconstructed from present state is REFUSED
 * and reported. It is never apportioned, rounded, or attributed to the nearest
 * plausible grant. A missing row is visible and fixable; a wrong row is a
 * permanent, auditable claim about why a family was charged what they were.
 *
 * ── AMOUNT PRESERVATION ───────────────────────────────────────────────────
 * The only collection written is `entitlementAllocations`. There is no write
 * to feeDemands, feeReceipts, feeReceiptAllocations, accountingLedger or any
 * payment record anywhere in this file — asserted by
 * LedgerBackfillWriterTest::test_the_writer_touches_only_the_ledger_collection.
 */
class Ledger_backfill extends CI_Controller
{
    /** The ONLY collection this controller may write. */
    private const LEDGER_COL = 'entitlementAllocations';

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) show_404();
        $this->load->library('firebase');
    }

    /** Default: decide and report, write nothing. */
    public function analyze($schoolId = '', $session = '')
    {
        $this->_run($schoolId, $session, false);
    }

    /** Write — but only with an explicit --commit as well. */
    public function execute($schoolId = '', $session = '')
    {
        $commit = in_array('--commit', (array) ($_SERVER['argv'] ?? []), true);
        if (!$commit) {
            fwrite(STDERR, "REFUSING: `execute` requires --commit as well.\n"
                . "Writing to a money ledger is opt-in twice, deliberately.\n"
                . "Run `analyze` first and read the plan.\n");
            exit(2);
        }
        $this->_run($schoolId, $session, true);
    }

    private function _run($schoolId, $session, bool $write): void
    {
        $schoolId = trim((string) $schoolId);
        $session  = trim((string) $session);
        if ($schoolId === '') {
            fwrite(STDERR, "usage: php index.php ledger_backfill analyze <SCHOOL_ID> [SESSION]\n"
                . "  the tenant is required and is never inferred\n");
            exit(2);
        }

        $emu = trim((string) (getenv('FIRESTORE_EMULATOR_HOST') ?: ''));
        echo "LEDGER BACKFILL — " . ($write ? 'EXECUTE (WRITES)' : 'ANALYZE (read only)') . "\n";
        echo "tenant : {$schoolId}" . ($session !== '' ? "  session: {$session}" : '') . "\n";
        echo "target : " . ($emu !== '' ? "EMULATOR {$emu}" : 'LIVE PROJECT') . "\n";
        echo str_repeat('=', 74) . "\n\n";

        // ── READ ──────────────────────────────────────────────────────────
        $demands = $this->_rows('feeDemands', $schoolId, $session);
        $ents    = $this->_rows('studentEntitlements', $schoolId, '');
        $allocs  = $this->_rows(self::LEDGER_COL, $schoolId, '');

        echo "  demands            : " . count($demands) . "\n";
        echo "  entitlements       : " . count($ents) . "\n";
        echo "  existing ledger    : " . count($allocs) . "\n\n";

        // ── PLAN (pure) ───────────────────────────────────────────────────
        $this->load->library('Ledger_backfill_planner');
        $plan   = Ledger_backfill_planner::plan($schoolId, $session, $demands, $ents, $allocs);
        $t      = $plan['totals'];
        $refuse = array_values(array_filter($plan['decisions'],
            fn($d) => $d['outcome'] === Ledger_backfill_planner::REFUSE));
        $create = array_values(array_filter($plan['decisions'],
            fn($d) => $d['outcome'] === Ledger_backfill_planner::CREATE));

        echo "── PLAN ──────────────────────────────────────────────────────────\n";
        echo "  no discount (I2 silent)   : {$t['no_discount']}\n";
        echo "  already explained         : {$t['already_explained']}\n";
        echo "  rows to create            : {$t['rows_to_create']}\n";
        echo "  refused (need a human)    : {$t['refused']}\n\n";

        if ($refuse) {
            echo "── REFUSED · NOTHING WILL BE WRITTEN FOR THESE ────────────────────\n";
            foreach (array_slice($refuse, 0, 25) as $r) {
                echo "  demand={$r['demandId']}  [{$r['reason']}]\n    {$r['detail']}\n";
            }
            if (count($refuse) > 25) echo '  … and ' . (count($refuse) - 25) . " more\n";
            echo "\n";
        }

        // ── VALIDATE + BUILD through the single authority ─────────────────
        $this->load->library('Entitlement_allocation_service', null, 'allocSvc');
        $svc = $this->allocSvc->init($this->firebase, null, $schoolId, $session,
            'ledger_backfill');

        $rows = []; $buildRefused = 0;
        foreach ($create as $dec) {
            foreach ($dec['rows'] as $r) {
                $b = $svc->build($dec['demand'], $r['entitlement'], (float) $r['amount']);
                if (empty($b['ok'])) {
                    $buildRefused++;
                    echo "  BUILD REFUSED demand={$dec['demandId']} ent={$r['entitlementId']}"
                        . " reason=[" . (string) ($b['reason'] ?? '?') . "]\n";
                    continue;
                }
                $rows[] = $b['row'];
            }
        }
        if ($buildRefused > 0) echo "\n";
        echo "  rows built by the allocation service : " . count($rows)
            . ($buildRefused ? "  ({$buildRefused} refused by build())" : '') . "\n\n";

        // ── RECONCILE (pre-write) ────────────────────────────────────────
        $built = 0.0;
        foreach ($rows as $r) $built += (float) ($r['allocatedAmount'] ?? 0);
        echo "── RECONCILIATION ────────────────────────────────────────────────\n";
        printf("  discount total on eligible demands : %.2f\n", $t['discount_total']);
        printf("  allocation total after this run    : %.2f\n", $t['allocated_after']);
        printf("  of which THIS run would write      : %.2f\n", round($built, 2));
        $unexplained = round($t['discount_total'] - $t['allocated_after'], 2);
        printf("  left unexplained (refused demands) : %.2f\n\n", $unexplained);

        // ── PERSIST — only when explicitly writing ───────────────────────
        if (!$write) {
            echo ($refuse === [] ? "ANALYSIS CLEAN" : "ANALYSIS INCOMPLETE — " . count($refuse) . " refused")
                . " · no documents were written.\n";
            echo "To write: php index.php ledger_backfill execute {$schoolId} {$session} --commit\n";
            exit($refuse === [] ? 0 : 1);
        }

        if ($rows === []) {
            echo "Nothing to write.\n";
            exit(0);
        }

        $res = $svc->persist($rows);
        echo "── WRITE RESULT ──────────────────────────────────────────────────\n";
        echo "  created   : {$res['created']}\n";
        echo "  duplicate : {$res['duplicate']}   (deterministic id collided — idempotent)\n";
        echo "  failed    : {$res['failed']}\n";
        foreach (array_slice($res['errors'] ?? [], 0, 10) as $e) echo "    ! {$e}\n";
        echo "\n";

        // ── RECONCILE (post-write) ───────────────────────────────────────
        $after = $this->_rows(self::LEDGER_COL, $schoolId, '');
        $sum = 0.0;
        foreach ($after as $a) {
            if (strtolower((string) ($a['status'] ?? 'active')) === 'active') {
                $sum += (float) ($a['allocatedAmount'] ?? 0);
            }
        }
        echo "  ledger rows now      : " . count($after) . "\n";
        printf("  active allocated sum : %.2f\n", round($sum, 2));
        echo ((int) $res['failed'] === 0)
            ? "\nBACKFILL COMPLETE. Re-run `billing_v2_certify` to confirm I2.\n"
            : "\nBACKFILL INCOMPLETE — {$res['failed']} row(s) failed; re-run is safe (idempotent).\n";
        exit((int) $res['failed'] === 0 ? 0 : 1);
    }

    /** Tenant-scoped read. Session filter applied only when one was given. */
    private function _rows(string $col, string $schoolId, string $session): array
    {
        $f = [['schoolId', '==', $schoolId]];
        if ($session !== '') $f[] = ['session', '==', $session];
        $out = [];
        try {
            foreach ((array) $this->firebase->firestoreQuery($col, $f) as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d)) $out[] = $d;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "read failed for {$col}: " . $e->getMessage() . "\n");
            exit(3);
        }
        return $out;
    }
}
