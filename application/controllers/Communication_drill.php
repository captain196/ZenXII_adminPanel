<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Communication_drill — drill-down on Communication Phase 6 verifier findings.
 *
 * Subcommands:
 *   notices         dump notice doc shape + ID format
 *   circulars       dump circular doc shape
 *   acks            dump circularAcks doc shape — find the orphan
 *   messaging       dump conversations/messageInboxes/messages doc shapes
 *   push            dump pushRequests doc shape (full)
 *   targets         dump distinct target_group / targetGroup / target values
 *
 * INVOCATION:
 *   php index.php communication_drill <subcmd>
 *   Env: SCHOOL_ID=<schoolFs>
 */
class Communication_drill extends CI_Controller
{
    private string $schoolFs = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
        $this->load->library('firestore_service');
        $this->schoolFs = (string) (getenv('SCHOOL_ID') ?: '');
        if ($this->schoolFs === '') { echo "ERROR: Set SCHOOL_ID\n"; exit(1); }
    }

    private function _fetch(string $col): array
    {
        try {
            return $this->firebase->firestoreQuery($col, [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function notices(): void
    {
        $rows = $this->_fetch('notices');
        echo "Notices: " . count($rows) . "\n";
        $idShapes = [];
        foreach ($rows as $i => $r) {
            $d = is_array($r['data']??null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $id    = (string)($d['id'] ?? '');
            $src   = (string)($d['source'] ?? '');
            $tg    = (string)($d['target_group'] ?? $d['targetGroup'] ?? $d['target'] ?? '');
            $tit   = (string)($d['title'] ?? '');
            $crt   = (string)($d['created_at'] ?? $d['createdAt'] ?? $d['issued_date'] ?? '');
            echo "  [{$i}] docId=\"{$docId}\"  data.id=\"{$id}\"  source=\"{$src}\"  target=\"{$tg}\"  created=\"{$crt}\"  title=\"" . substr($tit,0,40) . "\"\n";
            $shape = preg_match('/^NOT\d+$/', $id) ? 'NOT-N' : (preg_match('/^[A-Z]+\d+$/', $id) ? 'OTHER' : 'irregular');
            $idShapes[$shape] = ($idShapes[$shape] ?? 0) + 1;
        }
        echo "id shape distribution: " . json_encode($idShapes) . "\n";
    }

    public function acks(): void
    {
        $rows = $this->_fetch('circularAcks');
        $cirs = $this->_fetch('circulars');
        $cirIds = [];
        foreach ($cirs as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $cirIds[(string)($d['id']??'')] = true; }
        echo "Acks: " . count($rows) . "  Circulars (id-set): " . count($cirIds) . "\n";
        foreach ($rows as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $docId = (string)($r['id'] ?? '');
            $cid = (string)($d['circularId'] ?? '');
            $uid = (string)($d['userId'] ?? '');
            $ackAt = (string)($d['ackedAt'] ?? $d['acked_at'] ?? '');
            $known = isset($cirIds[$cid]) ? 'known' : 'ORPHAN';
            echo "  docId=\"{$docId}\"  circularId=\"{$cid}\" ({$known})  userId=\"{$uid}\"  ackedAt=\"{$ackAt}\"  fields=" . implode(',', array_keys($d)) . "\n";
        }
        echo "circulars present (ids): " . implode(', ', array_keys($cirIds)) . "\n";
    }

    public function messaging(): void
    {
        $conv = $this->_fetch('conversations');
        $inb = $this->_fetch('messageInboxes');
        $msg = $this->_fetch('messages');

        echo "=== conversations ({$this->cnt($conv)}) ===\n";
        foreach ($conv as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $docId = (string)($r['id'] ?? '');
            $cid = (string)($d['id'] ?? '');
            $parts = is_array($d['participants']??null) ? array_keys($d['participants']) : [];
            $partIds = is_array($d['participantIds']??null) ? $d['participantIds'] : [];
            echo "  docId={$docId} id={$cid}  parts(map keys)=" . json_encode($parts) . "  participantIds=" . json_encode($partIds) . "\n";
            echo "    fields=" . implode(',', array_keys($d)) . "\n";
        }
        echo "\n=== messageInboxes ({$this->cnt($inb)}) ===\n";
        foreach ($inb as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $docId = (string)($r['id'] ?? '');
            echo "  docId={$docId}  fields=" . implode(',', array_keys($d)) . "\n";
            $sample = array_slice($d, 0, 6, true);
            foreach ($sample as $k=>$v) echo "    {$k}=" . (is_scalar($v)?$v:json_encode($v)) . "\n";
        }
        echo "\n=== messages ({$this->cnt($msg)}) ===\n";
        foreach ($msg as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $docId = (string)($r['id'] ?? '');
            echo "  docId={$docId}  fields=" . implode(',', array_keys($d)) . "\n";
            foreach ($d as $k=>$v) echo "    {$k}=" . (is_scalar($v)?(string)$v:json_encode($v)) . "\n";
        }
    }

    public function push(): void
    {
        $rows = $this->_fetch('pushRequests');
        echo "pushRequests: " . count($rows) . "\n";
        foreach ($rows as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $docId = (string)($r['id'] ?? '');
            echo "  docId={$docId}  fields=" . implode(',', array_keys($d)) . "\n";
            foreach ($d as $k=>$v) {
                $vs = is_scalar($v) ? (string)$v : json_encode($v);
                if (strlen($vs) > 100) $vs = substr($vs, 0, 100) . '…';
                echo "    {$k}={$vs}\n";
            }
        }
    }

    public function targets(): void
    {
        foreach (['notices','circulars','messageQueue'] as $col) {
            $rows = $this->_fetch($col);
            echo "── {$col} ({$this->cnt($rows)}) ──\n";
            $dist = [];
            foreach ($rows as $r) {
                $d = is_array($r['data']??null)?$r['data']:[];
                foreach (['target_group','targetGroup','target','targetType','recipient_type','recipientType'] as $f) {
                    if (array_key_exists($f, $d)) {
                        $v = is_scalar($d[$f]) ? (string)$d[$f] : json_encode($d[$f]);
                        $dist["{$f}={$v}"] = ($dist["{$f}={$v}"] ?? 0) + 1;
                    }
                }
            }
            foreach ($dist as $k=>$c) echo "    {$k}  ×{$c}\n";
        }
    }

    private function cnt(array $a): int { return count($a); }
}
