<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Birthday_check — read-only verifier for birthday-wish feature.
 *
 * Cross-references:
 *   • students with DOB populated
 *   • whose DOB matches today (admin dashboard "Birthdays Today" expectation)
 *   • upcoming birthdays in next 14 days
 *   • birthdayWishes audit collection (which wishes already sent)
 *   • notices collection with source='birthday_wish' (parent-app inbox entries)
 *
 * Mirrors the EXACT logic used by:
 *   • Admin.php dashboard $birthdaysToday detection (today's match)
 *   • Parent DashboardScreen.kt isWardBirthdayToday (DOB regex parsing)
 *
 * INVOCATION: php index.php birthday_check check
 * Env: SCHOOL_ID=<schoolFs>
 */
class Birthday_check extends CI_Controller
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

    public function check(): void
    {
        $today = date('Y-m-d');
        $todayMd = date('m-d');
        echo "=== Birthday-wish feature verification ===\n";
        echo "schoolFs={$this->schoolFs}\n";
        echo "today={$today}  (month-day: {$todayMd})\n\n";

        // ── 1. Students with DOB
        $students = $this->_fetch('students');
        $withDob = [];
        $withoutDob = [];
        foreach ($students as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sid = (string)($d['studentId'] ?? $d['User ID'] ?? $d['userId'] ?? $r['id'] ?? '');
            $dob = (string)($d['dob'] ?? $d['DOB'] ?? $d['dateOfBirth'] ?? '');
            $name = (string)($d['name'] ?? $d['Name'] ?? '');
            if ($dob !== '') {
                $withDob[$sid] = ['name' => $name, 'dob' => $dob];
            } else {
                $withoutDob[] = $sid . ' (' . $name . ')';
            }
        }
        echo "── Student DOB inventory ──\n";
        echo "Total students: " . count($students) . "\n";
        echo "With DOB: " . count($withDob) . "\n";
        echo "Without DOB: " . count($withoutDob) . "\n";
        if (!empty($withoutDob)) {
            echo "Missing DOB:\n";
            foreach ($withoutDob as $row) echo "  - {$row}\n";
        }
        echo "\nAll DOBs:\n";
        foreach ($withDob as $sid => $info) {
            echo "  {$sid}  {$info['name']}  DOB='{$info['dob']}'\n";
        }

        // ── 2. Whose DOB matches today (admin dashboard expected output)
        echo "\n── Today's birthdays (admin dashboard 'Birthdays Today' widget expects these) ──\n";
        $todayMatches = [];
        foreach ($withDob as $sid => $info) {
            $md = $this->_extract_md($info['dob']);
            if ($md === $todayMd) {
                $todayMatches[] = ['sid' => $sid, 'name' => $info['name'], 'dob' => $info['dob']];
            }
        }
        if (empty($todayMatches)) {
            echo "  No student birthday today (" . date('M j') . "). Dashboard widget will correctly show 'No birthdays today'.\n";
        } else {
            foreach ($todayMatches as $m) echo "  🎂 {$m['sid']}  {$m['name']}  ({$m['dob']})\n";
        }

        // ── 3. Upcoming birthdays in next 14 days
        echo "\n── Upcoming birthdays (next 14 days; useful to know when to expect dashboard widget to populate) ──\n";
        $upcoming = [];
        for ($i = 1; $i <= 14; $i++) {
            $futureMd = date('m-d', strtotime("+{$i} days"));
            foreach ($withDob as $sid => $info) {
                if ($this->_extract_md($info['dob']) === $futureMd) {
                    $upcoming[] = [
                        'date' => date('M j', strtotime("+{$i} days")),
                        'days' => $i,
                        'sid'  => $sid,
                        'name' => $info['name'],
                        'dob'  => $info['dob'],
                    ];
                }
            }
        }
        if (empty($upcoming)) {
            echo "  No birthdays in next 14 days.\n";
        } else {
            foreach ($upcoming as $u) echo "  {$u['date']} (in {$u['days']} days) — {$u['sid']} {$u['name']} (DOB {$u['dob']})\n";
        }

        // ── 4. birthdayWishes audit collection inventory
        echo "\n── birthdayWishes audit collection (historical wishes sent) ──\n";
        $wishes = $this->_fetch('birthdayWishes');
        echo "Total wishes recorded: " . count($wishes) . "\n";
        foreach ($wishes as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sentAt = (string)($d['sentAt'] ?? '');
            $sid = (string)($d['studentId'] ?? '');
            $name = (string)($d['studentName'] ?? '');
            $source = (string)($d['source'] ?? '');
            echo "  {$sentAt}  {$sid}  {$name}  source={$source}\n";
        }

        // ── 5. notices with source='birthday_wish' (parent-app inbox entries)
        echo "\n── notices collection — birthday_wish entries (parent-app inbox visibility) ──\n";
        $notices = $this->_fetch('notices');
        $bdayNotices = [];
        foreach ($notices as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if ((string)($d['source'] ?? '') === 'birthday_wish') {
                $bdayNotices[] = $d;
            }
        }
        echo "Birthday notice docs in notices collection: " . count($bdayNotices) . "\n";
        foreach ($bdayNotices as $n) {
            echo "  noticeId={$n['noticeId']}  target={$n['targetStudentId']}  title=\"{$n['title']}\"  sentAt={$n['sentAt']}\n";
        }

        // ── 6. Parent-app banner expectation
        echo "\n── Parent-app dashboard banner expectation ──\n";
        echo "The 🎂 Happy Birthday banner + floating balloon animation in the Parent app appears when:\n";
        echo "  user.dob month-day == today (device timezone)\n";
        echo "DOB is read from TokenManager preferences (key DOB), refreshed from Firestore on each dashboard launch.\n";
        echo "Banner is decoupled from the wish-push — it shows whether or not admin clicked 'Wish'.\n";
        echo "\nTo confirm a particular student would see the banner today: cross-check whether their DOB month-day = {$todayMd}.\n";

        if (!empty($todayMatches)) {
            echo "\n✅ Expected to see banner today in Parent app for: ";
            echo implode(', ', array_map(fn($m) => $m['sid'], $todayMatches)) . "\n";
        } else {
            echo "\nℹ Today no student matches; banner will not appear in any parent's app today.\n";
            if (!empty($upcoming)) {
                $nextOne = $upcoming[0];
                echo "  Next opportunity: {$nextOne['date']} for {$nextOne['name']} ({$nextOne['sid']}).\n";
            }
        }

        echo "\n=== End birthday check ===\n";
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

    /** Extract month-day from DOB string in 3 supported formats. Returns 'mm-dd' or ''. */
    private function _extract_md(string $dob): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dob, $m)) {
            return $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $dob, $m)) {
            return $m[2] . '-' . $m[1];
        }
        return '';
    }
}
