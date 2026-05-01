<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Roster_helper — Firestore-only class roster reads.
 *
 * Single source of truth for "give me the active students in this
 * class/section." Replaces the legacy RTDB roster reads scattered across
 * Result.php, Attendance.php, Lms.php, Certificates.php, Exam_engine.php,
 * Homework_firestore_sync.php, Health_check.php, and Superadmin_schools.php.
 *
 * The replaced node was:
 *   Schools/{schoolName}/{session}/{classKey}/{sectionKey}/Students/List
 *
 * which had two inconsistent shapes ({uid: "Name"} vs {uid: {Name, RollNo}})
 * and was only kept up-to-date by `Dual_write::add/removeFromRoster`. This
 * helper queries the Firestore `students` collection (kept current by
 * `Entity_firestore_sync::syncStudent` on every admin write) using the
 * deployed `schoolId+className+section+status` compound index.
 *
 * RETURN SHAPE
 * ------------
 * `for_class()` always returns a stable `[studentId => fields]` object
 * map — never the legacy bare-string shape. Fields included:
 *   - Name       (display name; falls back to studentId if blank)
 *   - RollNo     (string; empty when unset)
 *   - Class      (display: "Class 10th")
 *   - Section    (display: "Section A")
 *   - phone      (Android-canonical key)
 *   - email
 *   - profilePic
 *   - parentDbKey
 *
 * Callers that previously did `is_string($roster[$uid]) ? $roster[$uid] : $uid`
 * can now just do `$roster[$uid]['Name'] ?? $uid` — but the legacy pattern
 * still works because object shape is returned consistently.
 *
 * USAGE
 * -----
 *   $this->load->library('Firestore_service', null, 'fs');
 *   $this->fs->init($this->firebase, $this->school_id, $this->session_year);
 *   $this->load->library('Roster_helper', null, 'roster');
 *   $this->roster->init($this->fs);
 *
 *   $students = $this->roster->for_class('Class 10th', 'Section A');
 *   // => [ 'STU0001' => ['Name'=>'Alice', 'RollNo'=>'12', ...], ... ]
 *
 *   $count = $this->roster->count_for_class('Class 10th', 'Section A');
 *   $one   = $this->roster->for_student('STU0001');
 *
 * SCHOOL ISOLATION
 * ----------------
 * `init($fs)` adopts the calling Firestore_service's school context — the
 * helper has no schoolId of its own. `schoolWhere()` always scopes by
 * `schoolId == $fs->schoolId`, so multi-tenant isolation is preserved.
 */
class Roster_helper
{
    /** @var object Firestore_service instance */
    private $fs;

    /** @var bool Whether init() succeeded */
    private $ready = false;

    public function init($fsService): self
    {
        $this->fs    = $fsService;
        $this->ready = ($fsService !== null);
        return $this;
    }

    /**
     * Return the active student roster for a class/section.
     *
     * @param string $classKey   Display key, e.g. "Class 10th". Title-Case
     *                           accepted; the helper normalises via
     *                           Firestore_service::classKey().
     * @param string $sectionKey Display key, e.g. "Section A". Same
     *                           normalisation applies.
     * @return array<string, array>  [studentId => fields], sorted by RollNo
     *                               ascending then Name. Empty array on
     *                               failure / not-ready / no matches.
     */
    public function for_class(string $classKey, string $sectionKey): array
    {
        if (!$this->ready) return [];
        $ck = Firestore_service::classKey($classKey);
        $sk = Firestore_service::sectionKey($sectionKey);
        if ($ck === '' || $sk === '') return [];

        try {
            $rows = $this->fs->schoolWhere('students', [
                ['className', '==', $ck],
                ['section',   '==', $sk],
                ['status',    '==', 'Active'],
            ]);
        } catch (\Exception $e) {
            log_message('error', "Roster_helper::for_class({$ck}/{$sk}) failed: " . $e->getMessage());
            return [];
        }

        // Pre-fetch the schoolId so we can strip its `{schoolId}_` prefix
        // off doc ids cleanly. Stripping at the first underscore would
        // clip "SCH_" out of a "SCH_TEST_001_STU0099" docId and leave
        // "TEST_001_STU0099" — wrong. Schools whose schoolId itself
        // contains underscores would silently mis-key the roster.
        $schoolPrefix = '';
        if (method_exists($this->fs, 'schoolId')) {
            $sid = (string) $this->fs->schoolId();
            if ($sid !== '') $schoolPrefix = $sid . '_';
        } elseif (isset($this->fs->schoolId) && is_string($this->fs->schoolId) && $this->fs->schoolId !== '') {
            $schoolPrefix = $this->fs->schoolId . '_';
        }

        $out = [];
        foreach ($rows as $entry) {
            // schoolWhere returns either ['id'=>..., 'data'=>[...]] or the
            // raw doc array — accept both shapes.
            $data = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($data)) continue;

            $sid = self::_resolve_student_id($entry, $data, $schoolPrefix);
            if ($sid === '') continue;

            $out[$sid] = [
                'Name'        => self::_pick($data, ['name', 'Name'], $sid),
                'RollNo'      => self::_pick($data, ['rollNo', 'Roll No', 'RollNo'], ''),
                'Class'       => self::_pick($data, ['className', 'Class'], $ck),
                'Section'     => self::_pick($data, ['section', 'Section'], $sk),
                'phone'       => self::_pick($data, ['phone', 'phoneNumber', 'Phone Number'], ''),
                'email'       => self::_pick($data, ['email', 'Email'], ''),
                'profilePic'  => self::_pick($data, ['profilePic', 'Profile Pic'], ''),
                'parentDbKey' => self::_pick($data, ['parentDbKey'], ''),
            ];
        }

        // Roll-number first (numeric), then alphabetical name. Roll numbers
        // can be non-numeric ("12A") — fall back to string compare.
        uasort($out, function ($a, $b) {
            $ar = $a['RollNo'];
            $br = $b['RollNo'];
            $an = is_numeric($ar) ? (int) $ar : null;
            $bn = is_numeric($br) ? (int) $br : null;
            if ($an !== null && $bn !== null && $an !== $bn) return $an <=> $bn;
            if ($an !== null && $bn === null) return -1;
            if ($an === null && $bn !== null) return 1;
            $rc = strnatcasecmp((string) $ar, (string) $br);
            if ($rc !== 0) return $rc;
            return strcasecmp((string) $a['Name'], (string) $b['Name']);
        });

        return $out;
    }

    /**
     * Count active students in a class/section. Equivalent to
     * `count(for_class(...))` but kept as a named API for the
     * count-only call sites (Homework_firestore_sync, Superadmin_schools)
     * so they read clearly.
     */
    public function count_for_class(string $classKey, string $sectionKey): int
    {
        return count($this->for_class($classKey, $sectionKey));
    }

    /**
     * Single-student lookup by docId pattern `{schoolId}_{studentId}`.
     * Returns null when not found / not ready / not in current school.
     * Used by enrollment-guard sites that previously read
     * `Students/List/{studentId}`.
     */
    public function for_student(string $studentId): ?array
    {
        if (!$this->ready || $studentId === '') return null;
        try {
            $doc = $this->fs->get('students', $this->fs->docId($studentId));
        } catch (\Exception $e) {
            log_message('error', "Roster_helper::for_student({$studentId}) failed: " . $e->getMessage());
            return null;
        }
        if (!is_array($doc) || empty($doc)) return null;
        $status = (string) self::_pick($doc, ['status', 'Status'], '');
        if ($status !== '' && strcasecmp($status, 'Active') !== 0) return null;
        return [
            'Name'        => self::_pick($doc, ['name', 'Name'], $studentId),
            'RollNo'      => self::_pick($doc, ['rollNo', 'Roll No'], ''),
            'Class'       => self::_pick($doc, ['className', 'Class'], ''),
            'Section'     => self::_pick($doc, ['section', 'Section'], ''),
            'phone'       => self::_pick($doc, ['phone', 'phoneNumber', 'Phone Number'], ''),
            'email'       => self::_pick($doc, ['email', 'Email'], ''),
            'profilePic'  => self::_pick($doc, ['profilePic', 'Profile Pic'], ''),
            'parentDbKey' => self::_pick($doc, ['parentDbKey'], ''),
        ];
    }

    /**
     * Cheap existence check — used by enrollment guards that don't need
     * the full profile (e.g. Result.php's marks-skip check pre-migration).
     */
    public function is_active(string $studentId): bool
    {
        return $this->for_student($studentId) !== null;
    }

    // ─── Private helpers ────────────────────────────────────────────────

    /**
     * Resolve the canonical bare studentId from a query row. The Firestore
     * docId is `{schoolId}_{studentId}`; we strip the *exact* schoolId
     * prefix so callers see the same id their parent/teacher app sees.
     * Stripping at the first underscore would mis-key any schoolId that
     * itself contains underscores (e.g. "SCH_TEST_001").
     */
    private static function _resolve_student_id($entry, array $data, string $schoolPrefix): string
    {
        $u = self::_pick($data, ['userId', 'studentId', 'User Id'], '');
        if ($u !== '') return (string) $u;
        if (is_array($entry) && isset($entry['id']) && is_string($entry['id'])) {
            $id = $entry['id'];
            if ($schoolPrefix !== '' && strncmp($id, $schoolPrefix, strlen($schoolPrefix)) === 0) {
                return (string) substr($id, strlen($schoolPrefix));
            }
            return $id;
        }
        return '';
    }

    /**
     * Pick the first present, non-empty value from a list of aliases.
     */
    private static function _pick(array $data, array $keys, $default)
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if ($v === null) continue;
            if (is_string($v) && $v === '') continue;
            return $v;
        }
        return $default;
    }
}
