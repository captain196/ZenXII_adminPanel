<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_fin3 — expense↔ledger integrity (FIN-3 + FIN-4).
 *
 * FIN-3: editing a POSTED expense reset accounting_journal_ref='pending' and
 * re-posted; the deterministic sourceRef (TRN_EXPENSE_{id}) made the re-post a
 * dedup no-op, so an amount edit changed the record but never the ledger —
 * silent divergence. Fix: a posted expense's financial fields are immutable
 * (block the edit); non-financial edits preserve the ref and do not re-post.
 *
 * FIN-4: voiding a POSTED expense only flipped status='Voided' and never
 * reversed the journal, so the GL kept overstating transport expense. Fix:
 * void reverses the journal (postReversal, idempotent); if the reversal cannot
 * be posted the void is aborted rather than leaving a divergent record.
 *
 *   G1 (FIN-3) financial edit of a posted expense is BLOCKED; record unchanged
 *   G2 (FIN-3) non-financial edit succeeds + preserves the posted ref (no reset)
 *   G3 (FIN-4) void of a posted-but-unreversible expense is ABORTED; still Active
 *   G4 (FIN-4) real post→void reverses the journal (accounting_reversal_ref set)
 *              — adaptive: INFO-skips when auto-posting is unavailable
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_fin3 run
 */
class Transport_probe_fin3 extends CI_Controller
{
    private const COL = 'transportExpenses';
    private array $fixtures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_fin3');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };
        $info = function (string $m) { echo "  INFO: {$m}\n"; };

        $base = [
            'expense_type'   => 'Toll_Fee',
            'amount'         => 5000,
            'tax_amount'     => 0,
            'tax_rate'       => 0,
            'payee_type'     => 'External',
            'payee_name'     => 'Probe Tollway',
            'payment_method' => 'Cash',
            'notes'          => 'original',
        ];

        echo "==================================================\n";
        echo " FIN-3/FIN-4 expense<->ledger integrity probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            // Seed a "posted" expense WITHOUT depending on live accounting:
            // create manual (ref=''), then stamp a simulated journal ref.
            $created = $this->transport->saveExpense($base);
            $eid = (string) $created['id'];
            $this->fixtures[] = $eid;
            $simRef = 'JE_SIM_' . $eid;
            $this->fs->setEntity(self::COL, $eid, ['accounting_journal_ref' => $simRef, 'accounting_attempts' => 1], true);

            echo "\n[G1] financial edit of a posted expense is BLOCKED\n";
            try {
                $this->transport->saveExpense(array_merge($base, ['id' => $eid, 'amount' => 8000]));
                $fail("amount edit of a posted expense was ALLOWED (divergence path open)");
            } catch (\RuntimeException $e) {
                $rec = (array) $this->transport->getExpense($eid);
                ((float) ($rec['amount'] ?? 0) === 5000.0)
                    ? $pass("blocked + record amount unchanged (still 5000): " . $e->getMessage())
                    : $fail("blocked but record amount mutated to " . ($rec['amount'] ?? '?'));
            }

            echo "\n[G1b] DATE edit of a posted expense is BLOCKED (record date unchanged)\n";
            $seededDate = date('Y-m-d');   // $base had no expense_date → seeded as today
            try {
                $this->transport->saveExpense(array_merge($base, ['id' => $eid, 'expense_date' => '2020-01-01']));
                $fail("expense_date edit of a posted expense was ALLOWED (record/ledger date divergence + FMES month drift)");
            } catch (\RuntimeException $e) {
                $rec = (array) $this->transport->getExpense($eid);
                ((string) ($rec['expense_date'] ?? '') === $seededDate)
                    ? $pass("blocked + record date unchanged (still {$seededDate}): " . $e->getMessage())
                    : $fail("blocked but record date mutated to " . ($rec['expense_date'] ?? '?'));
            }

            echo "\n[G2] non-financial edit succeeds + preserves the posted ref\n";
            $this->transport->saveExpense(array_merge($base, ['id' => $eid, 'notes' => 'edited note']));
            $rec = (array) $this->transport->getExpense($eid);
            ((string) ($rec['accounting_journal_ref'] ?? '') === $simRef && (string) ($rec['notes'] ?? '') === 'edited note')
                ? $pass("ref preserved ({$simRef}, not reset to pending) + notes updated")
                : $fail("ref=" . ($rec['accounting_journal_ref'] ?? '?') . " notes=" . ($rec['notes'] ?? '?'));

            echo "\n[G3] void of a posted-but-unreversible expense is ABORTED\n";
            try {
                // simRef has no matching 'accounting' doc → postReversal returns
                // null → void must abort, not leave a voided-unreversed record.
                $this->transport->voidExpense($eid, 'probe abort test');
                $fail("void succeeded despite unreversible journal (divergence)");
            } catch (\RuntimeException $e) {
                $rec = (array) $this->transport->getExpense($eid);
                ((string) ($rec['status'] ?? '') === 'Active')
                    ? $pass("void aborted + expense still Active: " . $e->getMessage())
                    : $fail("void aborted but status=" . ($rec['status'] ?? '?'));
            }

            echo "\n[G4] real post -> void reverses the journal (adaptive)\n";
            $r2 = $this->transport->saveExpense(array_merge($base, ['amount' => 3300, 'force_post' => true, 'notes' => 'g4 real post']));
            $eid2 = (string) $r2['id'];
            $this->fixtures[] = $eid2;
            $postStatus = (string) ($r2['posting']['status'] ?? '');
            $realRef    = (string) ($r2['posting']['journal_ref'] ?? '');
            if ($postStatus === 'posted' && $realRef !== '') {
                $this->transport->voidExpense($eid2, 'probe real reversal');
                $rec = (array) $this->transport->getExpense($eid2);
                $revRef = (string) ($rec['accounting_reversal_ref'] ?? '');
                ((string) ($rec['status'] ?? '') === 'Voided' && $revRef !== '' && $revRef !== 'failed')
                    ? $pass("posted journal {$realRef} reversed on void (reversal={$revRef}), status=Voided")
                    : $fail("status=" . ($rec['status'] ?? '?') . " reversal_ref=" . ($revRef ?: '(empty)'));
            } else {
                $info("live auto-posting unavailable in this tenant (posting status='{$postStatus}') — using stub seam for the success path (G4b)");
            }

            echo "\n[G4b] void of a posted expense stores the reversal ref + Voided (stub seam)\n";
            // Inject a stub accounting service through the documented
            // ci->transportAccountingSvc cache seam so the FIN-4 success path is
            // exercised deterministically even where the live engine cannot post.
            $this->transportAccountingSvc = new class {
                public function postReversal($orig, $reason, $jdate = null) { return 'JE_REV_' . $orig; }
            };
            $r3 = $this->transport->saveExpense(array_merge($base, ['amount' => 1200, 'notes' => 'g4b']));
            $eid3 = (string) $r3['id'];
            $this->fixtures[] = $eid3;
            $this->fs->setEntity(self::COL, $eid3, ['accounting_journal_ref' => 'JE_REAL_' . $eid3, 'accounting_attempts' => 1], true);
            $this->transport->voidExpense($eid3, 'probe stub reversal');
            $rec = (array) $this->transport->getExpense($eid3);
            ((string) ($rec['status'] ?? '') === 'Voided' && (string) ($rec['accounting_reversal_ref'] ?? '') === 'JE_REV_JE_REAL_' . $eid3)
                ? $pass("void reversed the journal (reversal=" . ($rec['accounting_reversal_ref'] ?? '') . "), status=Voided")
                : $fail("status=" . ($rec['status'] ?? '?') . " reversal_ref=" . ($rec['accounting_reversal_ref'] ?? '(empty)'));
            unset($this->transportAccountingSvc);
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as $id) { try { $this->fs->removeEntity(self::COL, $id); } catch (\Throwable $e) {} }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
