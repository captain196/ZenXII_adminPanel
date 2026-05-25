<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_lifecycle_verify — Tier 1.6 cross-system cascade verification helper.
 *
 * READ-ONLY CLI tool. Verifies the 4-layer active/inactive enforcement
 * cascade per [[staff_active_inactive_lifecycle]] Phases 1-3:
 *   L1: staff doc canonical status integrity (camelCase + PascalCase + audit)
 *   L2: userDevices FCM cleanup (zero docs for inactive staff)
 *   L3: subjectAssignments archive cascade (archivedBecauseOfDeactivation flag)
 *   L4: Firebase Auth disabled flag + tokensValidAfterTime
 *
 * INVOCATION:
 *   php index.php staff_lifecycle_verify verify_one <userId>
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 * Direct SCHOOL_ID bootstrap per BUG-A7 Framing α convention.
 */
class Staff_lifecycle_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Staff_lifecycle_verify is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /** CLI: php index.php staff_lifecycle_verify verify_one <userId> */
    public function verify_one(string $userId = ''): void
    {
        if ($userId === '') {
            echo "Usage: verify_one <userId>\n";
            exit(1);
        }
        $this->_verify_one($userId);
    }

    private function _verify_one(string $userId): void
    {
        echo "=== Tier 1.6 Cascade Verification — {$userId} ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n";

        // ── L1: staff doc ─────────────────────────────────────────────────
        $docId = "{$this->schoolFs}_{$userId}";
        echo "\n[L1] staff/{$docId}\n";
        $staff = $this->firebase->firestoreGet('staff', $docId);
        if (!is_array($staff)) {
            echo "  FAIL: staff doc not found.\n";
            return;
        }
        echo "  status (camelCase):    " . (string)($staff['status'] ?? '(missing)') . "\n";
        echo "  Status (PascalCase):   " . (string)($staff['Status'] ?? '(missing)') . "\n";
        echo "  deactivatedAt:         " . (string)($staff['deactivatedAt']      ?? '(absent)') . "\n";
        echo "  deactivatedBy:         " . (string)($staff['deactivatedBy']      ?? '(absent)') . "\n";
        echo "  deactivationReason:    " . (string)($staff['deactivationReason'] ?? '(absent)') . "\n";
        echo "  reactivatedAt:         " . (string)($staff['reactivatedAt']      ?? '(absent)') . "\n";
        echo "  reactivatedBy:         " . (string)($staff['reactivatedBy']      ?? '(absent)') . "\n";

        // ── L2: userDevices ───────────────────────────────────────────────
        echo "\n[L2] userDevices where userId=={$userId}\n";
        try {
            $devices = $this->firebase->firestoreQuery('userDevices', [
                ['userId', '==', $userId],
            ], null, 'ASC', 50);
            echo "  matching doc count: " . count($devices) . "\n";
            foreach ($devices as $d) {
                $data = is_array($d['data'] ?? null) ? $d['data'] : [];
                echo "  - docId=" . ($d['id'] ?? '') . "\n";
                echo "      platform=" . ($data['platform'] ?? '?')
                   . "  createdAt=" . ($data['createdAt'] ?? '?') . "\n";
                $tok = (string)($data['deviceToken'] ?? $data['fcmToken'] ?? '');
                if ($tok !== '') echo "      tokenPreview=" . substr($tok, 0, 16) . "...\n";
            }
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }

        // ── L3: subjectAssignments — try both teacherId and staffId ───────
        echo "\n[L3] subjectAssignments where teacherId=={$userId} OR staffId=={$userId}\n";
        try {
            $byTeacher = $this->firebase->firestoreQuery('subjectAssignments', [
                ['teacherId', '==', $userId],
            ], null, 'ASC', 100);
            $byStaff = $this->firebase->firestoreQuery('subjectAssignments', [
                ['staffId', '==', $userId],
            ], null, 'ASC', 100);
            $allById = [];
            foreach (array_merge($byTeacher, $byStaff) as $a) {
                $id = (string)($a['id'] ?? '');
                if ($id !== '' && !isset($allById[$id])) $allById[$id] = $a;
            }
            $assignments = array_values($allById);
            $archivedCount = 0;
            $activeCount = 0;
            $autoArchivedCount = 0;
            echo "  union doc count: " . count($assignments)
               . "  (teacherId-matches=" . count($byTeacher)
               . "  staffId-matches=" . count($byStaff) . ")\n";
            foreach ($assignments as $a) {
                $data = is_array($a['data'] ?? null) ? $a['data'] : [];
                $aStatus = (string)($data['status'] ?? '');
                $autoArchived = !empty($data['archivedBecauseOfDeactivation']);
                if ($aStatus === 'archived' || $autoArchived) $archivedCount++;
                else $activeCount++;
                if ($autoArchived) $autoArchivedCount++;
                echo "  - docId=" . ($a['id'] ?? '') . "\n";
                echo "      status={$aStatus}  archivedBecauseOfDeactivation="
                   . ($autoArchived ? 'true' : 'false') . "\n";
                echo "      subject=" . ($data['subject'] ?? '?')
                   . "  class=" . ($data['class'] ?? '?')
                   . "  section=" . ($data['section'] ?? '?') . "\n";
            }
            echo "  summary: archived={$archivedCount}  active={$activeCount}  autoArchived={$autoArchivedCount}\n";
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }

        // ── L4: Firebase Auth disabled flag ───────────────────────────────
        echo "\n[L4] Firebase Auth getUser({$userId})\n";
        try {
            $user = $this->firebase->getFirebaseUser($userId);
            if ($user !== null) {
                $disabled             = $user->disabled              ?? null;
                $email                = $user->email                 ?? '';
                $tokensValidAfterTime = $user->tokensValidAfterTime  ?? null;
                echo "  uid: "       . ($user->uid ?? '?') . "\n";
                echo "  email: "     . (string)$email . "\n";
                echo "  disabled: "  . var_export($disabled, true) . "\n";
                if ($tokensValidAfterTime !== null) {
                    echo "  tokensValidAfterTime: " . (string)$tokensValidAfterTime . "\n";
                }
            } else {
                echo "  user not found in Firebase Auth (or query failed — check log)\n";
            }
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }

        echo "\n=== End verification — {$userId} ===\n";
    }
}
