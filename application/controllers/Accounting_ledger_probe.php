<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting_ledger_probe — tiny read-only probe.
 *
 * Lists every accountingLedger row for a school+session and surfaces
 * (source, source_ref, status, entryId) so we can confirm whether a
 * specific receipt has a matching JE. Used by the orphan-receipt
 * forensic to verify JE linkage without false negatives from audit
 * filter logic.
 *
 * INVOCATION: php index.php accounting_ledger_probe list
 * Env: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 */
class Accounting_ledger_probe extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
    }

    public function list(): void
    {
        $schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($schoolFs === '' || $sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR.\n";
            exit(1);
        }
        echo "── accountingLedger probe ──\n";
        echo "schoolFs={$schoolFs}  session={$sessionYear}\n\n";

        $rows = $this->firebase->firestoreQuery('accountingLedger', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sessionYear],
        ], null, 'ASC', 5000);
        echo "Rows matched (schoolId+session): " . count($rows) . "\n";

        // also try without session filter to see if session field is missing/different
        $rowsNoSession = $this->firebase->firestoreQuery('accountingLedger', [
            ['schoolId', '==', $schoolFs],
        ], null, 'ASC', 5000);
        echo "Rows matched (schoolId only):    " . count($rowsNoSession) . "\n\n";

        echo "── All JEs (source / source_ref / status) ──\n";
        $sourceRefIndex = [];
        foreach ($rowsNoSession as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $eid  = (string) ($r['id'] ?? '');
            $src  = (string) ($d['source']     ?? '?');
            $sref = (string) ($d['source_ref'] ?? $d['sourceRef'] ?? '');
            $stat = (string) ($d['status']     ?? '?');
            $sess = (string) ($d['session']    ?? '(no session field)');
            echo "  {$eid}  source={$src}  source_ref='{$sref}'  status={$stat}  session={$sess}\n";
            if ($src === 'fee' && $sref !== '') {
                $sourceRefIndex[$sref] = $eid;
            }
        }

        echo "\n── Fee-JE source_ref map ──\n";
        if (empty($sourceRefIndex)) {
            echo "  (no fee-JEs found)\n";
        } else {
            ksort($sourceRefIndex, SORT_NATURAL);
            foreach ($sourceRefIndex as $sref => $eid) {
                echo "  receiptNo='{$sref}'  →  JE='{$eid}'\n";
            }
        }

        echo "\n── Orphan-cluster check (key search) ──\n";
        $orphanKeys = ['F11','F12','F13','F14','F15','F16','F17','F18','F19','F33'];
        foreach ($orphanKeys as $rk) {
            $expectedDocId = "{$schoolFs}_{$sessionYear}_JE_FEE_{$rk}";
            // Try BOTH collections — v2 path writes to 'accounting',
            // legacy mirror path writes to 'accountingLedger'.
            $accV2  = $this->firebase->firestoreGet('accounting',       $expectedDocId);
            $accMir = $this->firebase->firestoreGet('accountingLedger', $expectedDocId);
            $idempKey = "JE_FEE_{$rk}";
            $idempDoc = $this->firebase->firestoreGet('accountingIdempotency', "{$schoolFs}_{$idempKey}");
            echo sprintf("  %s  expectedDocId=%s\n", $rk, $expectedDocId);
            echo "    accounting (v2 canonical): " . (is_array($accV2)  && !empty($accV2)  ? 'EXISTS (' . ($accV2['source'] ?? '?')  . ', dr=' . ($accV2['total_dr']  ?? '?') . ')' : 'MISSING') . "\n";
            echo "    accountingLedger (mirror): " . (is_array($accMir) && !empty($accMir) ? 'EXISTS' : 'MISSING') . "\n";
            echo "    accountingIdempotency:     " . (is_array($idempDoc) && !empty($idempDoc) ? 'EXISTS (status=' . ($idempDoc['status'] ?? '?') . ', entryId=' . ($idempDoc['entryId'] ?? '?') . ')' : 'MISSING') . "\n";
        }
    }
}
