<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_expense_authz — focused evidence for the save_expense finance
 * maker/checker boundary (gates A, B, D, E, H, I, J).
 *
 * THE PROPERTY UNDER TEST
 *   Recording a transport expense is Transport:edit.
 *   Creating the Accounting journal is Transport:manage.
 *   saveExpense() is the ONLY one of the four callers of _postToAccounting()
 *   that is reachable at edit, so it carries an explicit $postingAuthorized
 *   argument — a separate parameter, never an $input key, because $input is
 *   built from client POST data.
 *
 * THREE PROOFS KEPT SEPARATE — they are NOT interchangeable:
 *   1. AUTHORIZATION proof   — the guard refuses an unauthorized caller.
 *   2. POSTING-PATH proof    — an authorized caller reaches _postToAccounting().
 *   3. JOURNAL-CREATION proof— a real Accounting journal id exists afterwards.
 *   This tenant has incomplete Accounting setup, so (3) may legitimately end in
 *   'pending'. That is reported as such and never reported as (3) satisfied.
 *
 * DELIBERATELY OUT OF SCOPE — do not let these be inferred as PASS:
 *   C = BLOCKED — auto-mode runtime not exercised; tenant stays in manual mode
 *                 and no tenant configuration is modified by this probe.
 *   F = PASS BY AZ-12b COMPENSATING EVIDENCE — Transport Manager holds
 *                 Transport:manage; NOT an independent runtime save_expense run.
 *   G = BLOCKED — direct authenticated HTTP POST unavailable in this environment.
 *
 *   SCHOOL_ID=SCH_202512 SESSION_YEAR=2026-27 php index.php transport_probe_expense_authz run
 */
class Transport_probe_expense_authz extends CI_Controller
{
    /** Fixtures are written; refuse to run anywhere but the test tenant. */
    private const ALLOWED_TENANT = 'SCH_202512';
    private const MARK = 'PROBE_EXPAUTH';

    public $fs = null;
    public $numbering = null;
    public $transport = null;

    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];
    /** [expenseId => lastKnownJournalRef] — created by this probe. */
    private array $created = [];
    private string $tag = '';

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); }
    }

    private function ok(string $id, string $w): void { $this->pass++; printf("  PASS  %-5s %s\n", $id, $w); }
    private function bad(string $id, string $w, string $d): void
    {
        $this->fail++; $this->failures[] = "{$id} — {$w}: {$d}";
        printf("  FAIL  %-5s %s\n          → %s\n", $id, $w, $d);
    }
    private function eq(string $id, string $w, $exp, $got): void
    {
        if ($exp === $got) { $this->ok($id, $w); return; }
        $this->bad($id, $w, 'expected ' . var_export($exp, true) . ', got ' . var_export($got, true));
    }
    private function truthy(string $id, string $w, $v, string $d = ''): void
    {
        if ($v) { $this->ok($id, $w); return; }
        $this->bad($id, $w, $d !== '' ? $d : 'falsy');
    }

    /** A minimal, clearly-marked expense payload. */
    private function payload(string $note, array $over = []): array
    {
        return array_merge([
            'expense_type'   => 'Vehicle_Maintenance',
            'expense_date'   => date('Y-m-d'),
            'amount'         => 100.00,
            'tax_amount'     => 0,
            'payee_type'     => 'External',
            'payee_name'     => self::MARK . '_' . $this->tag,
            'payment_method' => 'Cash',
            'notes'          => self::MARK . ' ' . $note,
        ], $over);
    }

    /** Journal ref as stored on the expense doc. */
    private function ref(string $id): string
    {
        try {
            $d = $this->transport->getExpense($id);
            if (!is_array($d)) { return '(missing)'; }
            if (isset($d['data']) && is_array($d['data']) && !isset($d['accounting_journal_ref'])) { $d = $d['data']; }
            return (string) ($d['accounting_journal_ref'] ?? '');
        } catch (\Throwable $e) { return '(error:' . $e->getMessage() . ')'; }
    }

    /** True when the ref is a REAL journal id — not '', 'pending' or 'failed'. */
    private function isRealJournal(string $ref): bool
    {
        return $ref !== '' && $ref !== 'pending' && $ref !== 'failed' && $ref !== '(missing)'
            && strpos($ref, '(error') !== 0;
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        if ($sid !== self::ALLOWED_TENANT) {
            fwrite(STDERR, "REFUSED: writes fixtures; runs only on " . self::ALLOWED_TENANT . " (got {$sid}).\n");
            exit(1);
        }
        $this->tag = (string) (int) (microtime(true) * 1000);

        echo "==================================================\n";
        echo " save_expense finance authorization — A,B,D,E,H,I,J\n";
        echo " tenant={$sid}   C/F/G intentionally NOT tested here\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $sid, $sess);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, self::MARK . '_ACTOR');

        // Report the tenant posting mode WITHOUT modifying it (gate C context).
        $mode = 'unknown';
        try {
            $ctrl = $this->fs->getEntity('schoolControl', $sid);
            if (is_array($ctrl)) { $mode = (string) ($ctrl['transport']['expense_posting_mode'] ?? 'manual'); }
        } catch (\Throwable $_) {}
        echo "\n  tenant expense_posting_mode = {$mode}  (NOT modified by this probe)\n";

        try {
            $this->gates();
        } catch (\Throwable $e) {
            $this->bad('EXC', 'unhandled exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            $this->teardown();
        }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        echo " C = BLOCKED (auto-mode not exercised; tenant stays manual)\n";
        echo " F = PASS BY AZ-12b COMPENSATING EVIDENCE (not a runtime run)\n";
        echo " G = BLOCKED (no direct authenticated HTTP POST available)\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        if ($this->fail > 0) { exit(1); }
    }

    private function gates(): void
    {
        // ── A — Driver/edit records an expense, no Accounting posting ────────
        echo "\n[A] unauthorized caller records an expense\n";
        $a = null;
        try {
            $a = $this->transport->saveExpense($this->payload('gate A record only'), /* postingAuthorized */ false);
        } catch (\Throwable $e) {
            $this->bad('A', 'unauthorized caller CAN record an expense', $e->getMessage());
        }
        $aid = is_array($a) ? (string) ($a['id'] ?? '') : '';
        if ($aid !== '') { $this->created[$aid] = ''; }
        $this->truthy('A', 'unauthorized caller CAN record an expense (edit capability retained)', $aid !== '');
        if ($aid !== '') {
            $aref = $this->ref($aid);
            $this->created[$aid] = $aref;
            $this->truthy('A2', 'that expense has NO Accounting journal',
                !$this->isRealJournal($aref), "journal ref was '{$aref}'");
        }

        // ── B — force_post by an unauthorized caller is refused ──────────────
        // AUTHORIZATION PROOF.
        echo "\n[B] unauthorized force_post\n";
        $threw = false; $msg = '';
        try {
            $this->transport->saveExpense($this->payload('gate B force_post', ['force_post' => true]), false);
        } catch (\Throwable $e) { $threw = true; $msg = $e->getMessage(); }
        $this->truthy('B', 'unauthorized force_post is REJECTED', $threw, 'no exception thrown — posting was allowed');
        $this->truthy('B2', 'rejection names the manage requirement',
            stripos($msg, 'manage') !== false, "message was: {$msg}");

        // ── H — the rejected attempt created NOTHING ─────────────────────────
        // Proves the guard runs BEFORE any write and before _postToAccounting().
        echo "\n[H] rejected attempt leaves no expense and no journal\n";
        $leaked = [];
        try {
            $rows = (array) $this->fs->schoolWhere('transportExpenses', [], null, 'ASC', 500);
            foreach ($rows as $r) {
                $d = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $notes = (string) ($d['notes'] ?? '');
                if (strpos($notes, 'gate B force_post') !== false) {
                    $leaked[] = (string) ($d['id'] ?? '?') . ' ref=' . (string) ($d['accounting_journal_ref'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            $this->bad('H', 'rejected attempt wrote nothing', 'could not verify: ' . $e->getMessage());
            $leaked = null;
        }
        if (is_array($leaked)) {
            $this->eq('H', 'rejected force_post wrote NO expense and NO journal', 0, count($leaked));
            if ($leaked !== []) { echo '        leaked: ' . implode(' | ', $leaked) . "\n"; }
        }

        // ── D — authorized caller records ────────────────────────────────────
        echo "\n[D] authorized caller records an expense\n";
        $d = null;
        try {
            $d = $this->transport->saveExpense($this->payload('gate D authorized record'), /* postingAuthorized */ true);
        } catch (\Throwable $e) {
            $this->bad('D', 'authorized caller CAN record an expense', $e->getMessage());
        }
        $did = is_array($d) ? (string) ($d['id'] ?? '') : '';
        if ($did !== '') { $this->created[$did] = $this->ref($did); }
        $this->truthy('D', 'authorized caller CAN record an expense', $did !== '');

        // ── E + I — authorized caller reaches the posting path ───────────────
        // POSTING-PATH PROOF. Journal creation is reported separately below.
        echo "\n[E/I] authorized force_post reaches the Accounting posting path\n";
        $e = null; $eerr = '';
        try {
            $e = $this->transport->saveExpense($this->payload('gate E authorized post', ['force_post' => true]), true);
        } catch (\Throwable $ex) { $eerr = $ex->getMessage(); }
        $eid = is_array($e) ? (string) ($e['id'] ?? '') : '';
        if ($eid !== '') { $this->created[$eid] = ''; }
        $this->truthy('E', 'authorized force_post is ACCEPTED (not refused)', $eid !== '',
            $eerr !== '' ? "threw: {$eerr}" : 'no expense id returned');

        $status = is_array($e) ? (string) (($e['posting']['status'] ?? '')) : '';
        $this->truthy('I', 'posting path was followed (status reported, not "manual")',
            $status !== '' && $status !== 'manual', "posting.status was '{$status}'");

        // JOURNAL-CREATION PROOF — reported, never conflated with the above.
        if ($eid !== '') {
            $eref = $this->ref($eid);
            $this->created[$eid] = $eref;
            if ($this->isRealJournal($eref)) {
                $this->ok('I2', "REAL Accounting journal created: {$eref}");
            } else {
                echo "  INFO  I2    journal-creation NOT proven — ref='{$eref}'"
                   . " (tenant Accounting setup incomplete; posting path reached, queued)\n";
            }
        }

        // ── J — a pending/unposted expense can later be posted by a manager ──
        echo "\n[J] unposted expense later posted by an authorized manager\n";
        if ($aid === '') {
            $this->bad('J', 'unposted expense can later be posted', 'gate A produced no expense to post');
        } else {
            $before = $this->ref($aid);
            $jerr = ''; $after = $before;
            try {
                $this->transport->postExpenseNow($aid);
                $after = $this->ref($aid);
                $this->created[$aid] = $after;
            } catch (\Throwable $ex) { $jerr = $ex->getMessage(); }

            if ($jerr !== '') {
                // A refusal caused by incomplete Accounting setup is an
                // environment limit, not an authorization failure — say which.
                if (stripos($jerr, 'manage') !== false || stripos($jerr, 'permission') !== false) {
                    $this->bad('J', 'manager posting of a recorded expense', "authorization refused: {$jerr}");
                } else {
                    echo "  INFO  J     posting attempted; Accounting refused: {$jerr}\n";
                    echo "              (environment limitation, NOT an authorization failure)\n";
                }
            } else {
                $this->truthy('J', 'recorded expense transitions once a manager posts it',
                    $after !== $before || $this->isRealJournal($after),
                    "ref unchanged ('{$before}' → '{$after}')");
            }
        }
    }

    /**
     * Deterministic teardown. Removes ONLY this probe's expenses.
     *
     * An expense that reached a REAL journal is deliberately NOT deleted —
     * removing it would orphan an Accounting entry. Those are reported for a
     * human decision instead, per the standing rule against touching
     * Accounting data to make a run look clean.
     */
    private function teardown(): void
    {
        echo "\n[TEARDOWN]\n";
        $removed = 0; $keptPosted = []; $failed = 0;
        foreach ($this->created as $id => $ref) {
            if ($id === '') { continue; }
            $live = $this->ref($id);
            if ($this->isRealJournal($live)) {
                $keptPosted[] = "{$id} (journal {$live})";
                continue;
            }
            try { $this->fs->removeEntity('transportExpenses', $id); $removed++; }
            catch (\Throwable $e) { $failed++; echo "  FAILED remove {$id}: " . $e->getMessage() . "\n"; }
        }
        echo "  probe expenses created : " . count($this->created) . "\n";
        echo "  removed                : {$removed}" . ($failed ? " (failed {$failed})" : '') . "\n";
        if ($keptPosted !== []) {
            echo "  KEPT (real journal — not deleted, needs a human decision):\n";
            foreach ($keptPosted as $k) { echo "    • {$k}\n"; }
        }
        echo "  tenant configuration   : NOT modified\n";
    }
}
