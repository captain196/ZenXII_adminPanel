<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Entity_firestore_sync — Syncs master entity data from RTDB to Firestore.
 *
 * Bridges the gap between:
 *   - Admin Panel (writes to RTDB)
 *   - Android apps (read from Firestore)
 *
 * Covers the 18 collections that were NOT previously dual-written:
 *   schools, staff, students, parents, sections, users,
 *   attendance (detail), exams, examSchedule, notifications,
 *   leaveApplications, routes, vehicles, studentRoutes,
 *   stories, galleryAlbums, galleryMedia, paymentIntents
 *
 * All writes are best-effort: RTDB is ALWAYS the source of truth.
 * Firestore failures are logged but never block the primary operation.
 *
 * Usage in controllers:
 *   $this->load->library('entity_firestore_sync', null, 'entity_sync');
 *   $this->entity_sync->init($this->firebase, $this->school_name, $this->session_year, $this->school_code);
 *   $this->entity_sync->syncStudent($studentId, $profileData);
 */
class Entity_firestore_sync
{
    /** @var object Firebase library */
    private $firebase;

    /** @var string School identifier (SCH_XXXXXX) */
    private $schoolId;

    /** @var string Academic session (e.g., "2025-26") */
    private $session;

    /** @var string School login code (e.g., "10005") */
    private $schoolCode;

    /** @var bool Whether initialization succeeded */
    private $ready = false;

    /**
     * Initialize with school context.
     */
    public function init($firebase, string $schoolId, string $session, string $schoolCode = ''): self
    {
        $this->firebase   = $firebase;
        $this->schoolId   = $schoolId;
        $this->session    = $session;
        $this->schoolCode = $schoolCode ?: $schoolId;
        $this->ready      = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════
    //  SCHOOL PROFILE
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync school profile to Firestore 'schools' collection.
     * Call after: School_config::save_profile(), Superadmin_schools::onboard()
     */
    public function syncSchool(array $profileData): bool
    {
        if (!$this->ready) return false;
        $docId = $this->schoolId;

        // Map input to Firestore fields, only include non-empty values
        $fieldMap = [
            'name'          => $profileData['school_name'] ?? $profileData['display_name'] ?? null,
            'address'       => $profileData['address'] ?? $profileData['street'] ?? null,
            'city'          => $profileData['city'] ?? null,
            'state'         => $profileData['state'] ?? null,
            'phone'         => $profileData['phone'] ?? null,
            'email'         => $profileData['email'] ?? null,
            'logoUrl'       => $profileData['logo_url'] ?? null,
            'principal'     => $profileData['principal_name'] ?? null,
            'board'         => $profileData['board'] ?? null,
        ];

        $doc = ['updatedAt' => date('c')];
        foreach ($fieldMap as $key => $val) {
            if ($val !== null && $val !== '') {
                $doc[$key] = $val;
            }
        }

        // Always write identity/status fields (Android SchoolDoc expects these)
        $doc['schoolCode']      = $this->schoolCode;

        // SC-Step1 (Session Convergence — 2026-06-02): DEMOTE currentSession
        // write to "write only if absent" per Q7 verdict. syncSchool is a
        // profile-sync operation, not a session lifecycle operation.
        // Defense-in-depth alignment with Firestore_service::saveSchool DEMOTE
        // (currently zero production callers; kept consistent for any future
        // restoration). Active session changes flow exclusively through
        // School_config::set_active_session (sole canonical writer).
        try {
            $existing = $this->firebase->firestoreGet('schools', $docId);
            if (!is_array($existing) || empty($existing['currentSession'])) {
                $doc['currentSession'] = $this->session;  // defensive seed
            }
            // else: populated — DO NOT include currentSession in $doc (preserve canonical)
        } catch (\Exception $e) {
            log_message('error',
                "SC-Step1: Entity_firestore_sync::syncSchool currentSession DEMOTE read failed for {$docId}: " . $e->getMessage());
            // Conservative: do NOT include currentSession on read failure (fail-safe)
        }

        $doc['status']          = $profileData['status'] ?? 'active';

        return $this->_write('schools', $docId, $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  CLASS / SECTION NORMALIZATION (Phase 1, 2026-04-08)
    // ══════════════════════════════════════════════════════════════════
    //
    // Single source of truth for the canonical Firestore class/section shape.
    // Every writer that touches a student doc must funnel through here so
    // Firestore documents land in exactly one shape:
    //
    //   className:   "Class 10th"   (display)
    //   section:     "Section A"    (display)
    //   classOrder:  10             (sortable Int — see CLASS_ORDER_MAP)
    //   sectionCode: "A"            (raw — usable for whereEqualTo queries
    //                                where you don't want the prefix)
    //
    // Empty string for any input field is preserved (legitimate for
    // class-wide assignments, single-section classes, etc.).

    /** Pre-K → 12 sortable rank. Anything not in the map → null classOrder. */
    private const CLASS_ORDER_MAP = [
        'PLAYGROUP'   => -5,
        'PRE-NURSERY' => -4,
        'PRE NURSERY' => -4,
        'NURSERY'     => -3,
        'LKG'         => -2,
        'UKG'         => -1,
        '1'  => 1,  '2'  => 2,  '3'  => 3,  '4'  => 4,  '5'  => 5,
        '6'  => 6,  '7'  => 7,  '8'  => 8,  '9'  => 9,  '10' => 10,
        '11' => 11, '12' => 12,
    ];

    /**
     * Normalize a (rawClass, rawSection) input pair into the canonical
     * 4-field Firestore shape used by every student write.
     *
     * Accepted class inputs (case-insensitive, whitespace-tolerant):
     *   "10", "10th", "Class 10", "Class 10th", "  class 10TH  ",
     *   "LKG", "lkg", "Class LKG", "Nursery", "Pre-Nursery", "" (empty)
     *
     * Accepted section inputs:
     *   "A", "Section A", "section a", "Red", "1", "" (empty)
     *
     * Output is always an array with all 4 keys present:
     *   [
     *     'className'   => "Class 10th",   // empty if input was empty
     *     'section'     => "Section A",
     *     'classOrder'  => 10,             // null if non-numeric / unknown
     *     'sectionCode' => "A",
     *   ]
     */
    public static function normalizeClassSection(string $rawClass, string $rawSection): array
    {
        // ── Class side ────────────────────────────────────────────────
        $classToken = trim($rawClass);
        // Strip "Class " / "class " prefix if present (any case).
        if ($classToken !== '' && stripos($classToken, 'class ') === 0) {
            $classToken = trim(substr($classToken, 6));
        }
        // Strip ordinal suffix so "10th" → "10", "1st" → "1".
        $classCanonical = preg_replace('/(\d+)(st|nd|rd|th)$/i', '$1', $classToken) ?? $classToken;
        $classCanonical = trim($classCanonical);

        $classOrder = null;
        $className  = '';

        if ($classCanonical !== '') {
            // Numeric? → ordinal-suffixed display ("10" → "Class 10th")
            if (ctype_digit($classCanonical)) {
                $n = (int) $classCanonical;
                $className  = 'Class ' . self::ordinalSuffix($n);
                $classOrder = self::CLASS_ORDER_MAP[(string) $n] ?? null;
            } else {
                // Non-numeric (LKG, UKG, Nursery, …) — preserve casing of
                // the input so "Pre-Nursery" stays human-readable, but
                // look up the order via the upper-cased key.
                $className  = 'Class ' . $classCanonical;
                $key = strtoupper($classCanonical);
                $classOrder = self::CLASS_ORDER_MAP[$key] ?? null;
            }
        }

        // ── Section side ──────────────────────────────────────────────
        $sectionToken = trim($rawSection);
        if ($sectionToken !== '' && stripos($sectionToken, 'section ') === 0) {
            $sectionToken = trim(substr($sectionToken, 8));
        }

        $sectionCode = $sectionToken;          // raw token (no prefix)
        $section     = $sectionToken === '' ? '' : "Section {$sectionToken}";

        return [
            'className'   => $className,
            'section'     => $section,
            'classOrder'  => $classOrder,
            'sectionCode' => $sectionCode,
        ];
    }

    /**
     * "1" → "1st", "2" → "2nd", "3" → "3rd", "11" → "11th", "21" → "21st".
     * Handles the 11/12/13 special-case (they always take "th").
     */
    private static function ordinalSuffix(int $n): string
    {
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 13) return "{$n}th";
        switch ($n % 10) {
            case 1:  return "{$n}st";
            case 2:  return "{$n}nd";
            case 3:  return "{$n}rd";
            default: return "{$n}th";
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENTS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync student profile to Firestore 'students' collection.
     * Call after: Sis::save_student(), Sis::update_student(), Sis::admit_student()
     *
     * PARTIAL-MERGE CONTRACT
     * ----------------------
     * Only fields that are *actually present* in `$data` are written. Missing
     * fields are NOT defaulted to empty strings — they are simply omitted, and
     * because the underlying write is a Firestore merge-set, omitted fields
     * preserve their existing values on the document.
     *
     * This is required because callers fall into two camps:
     *   1. Full saves (save_student, admit_student, import_students) — pass
     *      every field; all of them get written.
     *   2. Partial edits (update_profile, change_status, edit_student, the
     *      TC issue/cancel/withdraw paths) — pass only what changed.
     *
     * The previous implementation rebuilt the whole doc with `?? ''`
     * fallbacks, so a partial edit (e.g. just `Name`) silently overwrote
     * dob, gender, parents, address, documents, etc. with empty strings.
     * This routine now never produces empty-string clears: a caller that
     * truly wants to clear a field has to do it via `fs->updateEntity`
     * directly, not through this sync helper.
     *
     * Identity fields (schoolId/schoolCode/studentId/userId/parentDbKey/
     * session) and `updatedAt` are always emitted — they are invariants of
     * the row, not user-editable data.
     */
    public function syncStudent(string $studentId, array $data): bool
    {
        if (!$this->ready) return false;
        // DocId uses schoolId (SCH_...) — matches Firestore_service::docId() and Android queries
        $docId = "{$this->schoolId}_{$studentId}";

        // Identity invariants — always written. Safe under merge: schoolId
        // is fixed for the life of the doc, and refreshing them keeps a
        // legacy doc with stale fields self-healing.
        $doc = [
            'schoolId'    => $this->schoolId,
            'schoolCode'  => $this->schoolCode,
            'studentId'   => $studentId,
            'userId'      => $studentId,
            'parentDbKey' => $this->schoolCode,
            // BUG-052 fix 2026-05-25: respect caller-provided session for cross-session writes
            // (e.g. Sis::promote target_session). Fallback to $this->session preserves
            // legacy single-session callers (save_student, admit_student, import_students).
            'session'     => $data['session'] ?? $data['Session'] ?? $this->session,
            'updatedAt'   => date('c'),
        ];

        // Helper: pick the first present, non-null, non-empty-string value
        // from the alias list. Returns null when nothing usable is present.
        // Empty strings are treated as "not provided" so a caller passing
        // a blank field can never wipe an existing value via this helper.
        // (For arrays: empty array is also treated as absent — partial
        // edits never include an empty `Doc` array, full saves always
        // include a populated one.)
        $pick = function (array $aliases) use ($data) {
            foreach ($aliases as $key) {
                if (!array_key_exists($key, $data)) continue;
                $v = $data[$key];
                if ($v === null) continue;
                if (is_string($v) && $v === '') continue;
                if (is_array($v) && empty($v)) continue;
                return $v;
            }
            return null;
        };

        // Personal
        if (($v = $pick(['Name', 'name', 'student_name'])) !== null)        $doc['name']        = $v;
        if (($v = $pick(['DOB', 'dob'])) !== null)                          $doc['dob']         = $v;
        if (($v = $pick(['Gender', 'gender'])) !== null)                    $doc['gender']      = $v;
        if (($v = $pick(['Category', 'category'])) !== null)                $doc['category']    = $v;
        if (($v = $pick(['Blood Group', 'bloodGroup'])) !== null)           $doc['bloodGroup']  = $v;
        if (($v = $pick(['Religion', 'religion'])) !== null)                $doc['religion']    = $v;
        if (($v = $pick(['Nationality', 'nationality'])) !== null)          $doc['nationality'] = $v;

        // Class & section — paired. Only normalise when at least one side
        // is provided; otherwise we'd emit empty `className`/`section` etc.
        // and clobber the canonical class/section row written elsewhere.
        $hasClass   = $pick(['Class',   'className']) !== null;
        $hasSection = $pick(['Section', 'section'])   !== null;
        if ($hasClass || $hasSection) {
            $cs = self::normalizeClassSection(
                $data['Class']   ?? $data['className'] ?? '',
                $data['Section'] ?? $data['section']   ?? ''
            );
            $doc['className']   = $cs['className'];
            $doc['section']     = $cs['section'];
            $doc['classOrder']  = $cs['classOrder'];
            $doc['sectionCode'] = $cs['sectionCode'];
            $doc['sectionKey']  = ($cs['className'] !== '' && $cs['section'] !== '')
                ? "{$cs['className']}/{$cs['section']}"
                : '';
        }

        if (($v = $pick(['Roll No', 'RollNo', 'rollNo'])) !== null)         $doc['rollNo']        = $v;
        if (($v = $pick(['Admission Date', 'admissionDate'])) !== null)     $doc['admissionDate'] = $v;

        // Contact — `phone` is the Android-canonical key; `phoneNumber` is
        // kept for backward-compat. Both are mirrored from the same source.
        if (($v = $pick(['Phone Number', 'phoneNumber', 'Phone', 'phone'])) !== null) {
            $doc['phone']       = $v;
            $doc['phoneNumber'] = $v;
        }
        if (($v = $pick(['Email', 'email', 'Parent_email'])) !== null)      $doc['email']   = $v;
        if (($v = $pick(['Address', 'address'])) !== null)                  $doc['address'] = $v;

        // Family
        if (($v = $pick(['Father Name', 'fatherName', 'father_name'])) !== null)            $doc['fatherName']       = $v;
        if (($v = $pick(['Father Occupation', 'fatherOccupation'])) !== null)               $doc['fatherOccupation'] = $v;
        if (($v = $pick(['Mother Name', 'motherName', 'mother_name'])) !== null)            $doc['motherName']       = $v;
        if (($v = $pick(['Mother Occupation', 'motherOccupation'])) !== null)               $doc['motherOccupation'] = $v;
        if (($v = $pick(['Guard Contact', 'guardContact'])) !== null)                       $doc['guardContact']     = $v;
        if (($v = $pick(['Guard Relation', 'guardRelation'])) !== null)                     $doc['guardRelation']    = $v;

        // Previous education
        if (($v = $pick(['Pre Class', 'preClass'])) !== null)               $doc['preClass']  = $v;
        if (($v = $pick(['Pre School', 'preSchool'])) !== null)             $doc['preSchool'] = $v;
        if (($v = $pick(['Pre Marks', 'preMarks'])) !== null)               $doc['preMarks']  = $v;

        // Documents & photo. profilePic has an extra fallback into the
        // nested Doc.ProfilePic shape that admin's save_student emits.
        if (($v = $pick(['Profile Pic', 'profilePic'])) !== null) {
            $doc['profilePic'] = $v;
        } elseif (isset($data['Doc']['ProfilePic']) && $data['Doc']['ProfilePic'] !== '') {
            $doc['profilePic'] = $data['Doc']['ProfilePic'];
        }
        if (($v = $pick(['Doc', 'documents'])) !== null)                    $doc['documents'] = $v;

        // Status — emitted only if caller provided. New-student creation
        // paths (save_student/admit_student/import_students) explicitly
        // pass `Status => 'Active'`, so this still defaults correctly.
        if (($v = $pick(['Status', 'status'])) !== null)                    $doc['status'] = $v;

        return $this->_write('students', $docId, $doc);
    }

    /**
     * Delete student from Firestore.
     * Call after: Sis::delete_student()
     *
     * DocId MUST match `syncStudent()` — both are
     * `{schoolId}_{studentId}`. Pre-fix this used `{schoolCode}_…`
     * which would orphan the doc whenever schoolId ≠ schoolCode (e.g.
     * the SCH_xxx vs login-code-only split that exists for some
     * legacy schools). The mismatch was latent because in most
     * tenants schoolId === schoolCode, but it was a real production
     * data-loss bug waiting on the first divergent tenant.
     */
    public function deleteStudent(string $studentId): bool
    {
        if (!$this->ready) return false;
        $docId = "{$this->schoolId}_{$studentId}";
        return $this->_delete('students', $docId);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PARENTS (mirrored from student for parent app context)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync parent/guardian info to Firestore 'parents' collection.
     */
    /**
     * Sync parent/guardian profile to Firestore 'parents' collection.
     *
     * Future use cases:
     *   - Multi-child parent accounts (one parent → multiple children)
     *   - Parent-Teacher meeting booking (PTM scheduling)
     *   - Parent communication preferences (notification settings)
     *   - Parent portal features (fee payments, leave requests)
     *
     * DocId: {schoolId}_{studentId}
     * For multi-child: childrenIds[] array accumulates student IDs
     */
    public function syncParent(string $studentId, array $data): bool
    {
        if (!$this->ready) return false;
        $docId = "{$this->schoolId}_{$studentId}";

        // SIS Wave-4 fix S7 (2026-05-31): use the same $pick helper that
        // syncStudent uses (L270-280), so partial payloads no longer clobber
        // existing parent-doc fields with empty strings. Pre-fix, every call
        // built a full doc with `?? ''` fallbacks for every field; combined
        // with merge=true _write, the empty strings still overwrote existing
        // values. Most visible failure: toggle_status@Sis.php:1869 passed a
        // Status-only payload and wiped fatherName/motherName/phone/address.
        // The fix is mechanical — only WRITE a field when $pick returns a
        // usable value; otherwise leave it absent so merge=true preserves
        // whatever was there.
        //
        // Categories below (preserved in this order):
        //   IDENTITY invariants — always written (schoolId, schoolCode, ...)
        //   $pick-conditional fields — only written when $data provides them
        //   CLASS-SNAPSHOT — gated like syncStudent@296 (Class OR Section)
        //   PHONE + GUARDCONTACT — phone via $pick; guardContact via $pick
        //     OR fallback to phone (preserves "guard defaults to phone on
        //     new docs" behavior the original code intended)
        //   NOTIFICATION_PREFS defaults — always-written, unchanged
        //     (every-call-overwrites-customization is a separate concern,
        //     out of S7 scope)
        //
        // S2/S3 callers (issue_tc / cancel_tc / withdraw_student) pass full
        // $student payloads via array_merge as a defensive workaround. Those
        // workarounds REMAIN in place per operator direction — they're
        // belt-and-suspenders that survives future refactors of syncParent.
        $pick = function (array $aliases) use ($data) {
            foreach ($aliases as $key) {
                if (!array_key_exists($key, $data)) continue;
                $v = $data[$key];
                if ($v === null) continue;
                if (is_string($v) && $v === '') continue;
                if (is_array($v) && empty($v)) continue;
                return $v;
            }
            return null;
        };

        // IDENTITY invariants — always written. Safe under merge: stable
        // for the lifetime of the doc, refreshing keeps legacy docs
        // self-healing if any field drifts.
        $doc = [
            'schoolId'    => $this->schoolId,
            'schoolCode'  => $this->schoolCode,
            'parentDbKey' => $this->schoolCode,
            'studentId'   => $studentId,
            'userId'      => $studentId,
            'childrenIds' => [$studentId],
            // BUG-052 fix 2026-05-25: caller-provided session precedence
            'session'     => $data['session'] ?? $data['Session'] ?? $this->session,
            'updatedAt'   => date('c'),
        ];

        // Student reference (for quick display without fetching students collection)
        if (($v = $pick(['Name', 'name', 'student_name'])) !== null)        $doc['studentName'] = $v;
        if (($v = $pick(['Roll No', 'RollNo', 'rollNo'])) !== null)         $doc['rollNo']      = $v;

        // Class & section — paired snapshot. Only normalise when at least
        // one side is provided; otherwise we'd emit empty className/section
        // and clobber the canonical class/section row written elsewhere
        // (same gate as syncStudent@296-308).
        $hasClass   = $pick(['Class',   'className']) !== null;
        $hasSection = $pick(['Section', 'section'])   !== null;
        if ($hasClass || $hasSection) {
            $cs = self::normalizeClassSection(
                $data['Class']   ?? $data['className'] ?? '',
                $data['Section'] ?? $data['section']   ?? ''
            );
            $doc['className']   = $cs['className'];
            $doc['section']     = $cs['section'];
            $doc['classOrder']  = $cs['classOrder'];
            $doc['sectionCode'] = $cs['sectionCode'];
        }

        // Father details — `name` field is aliased to fatherName by long-
        // standing convention (parent app reads `name` for display).
        if (($v = $pick(['Father Name', 'fatherName', 'father_name'])) !== null) {
            $doc['name']       = $v;
            $doc['fatherName'] = $v;
        }
        if (($v = $pick(['Father Occupation', 'fatherOccupation'])) !== null) $doc['fatherOccupation'] = $v;

        // Mother details
        if (($v = $pick(['Mother Name', 'motherName', 'mother_name'])) !== null)         $doc['motherName']       = $v;
        if (($v = $pick(['Mother Occupation', 'motherOccupation'])) !== null)            $doc['motherOccupation'] = $v;

        // Phone + guardian contact: phone via $pick; guardContact via $pick
        // OR fallback to phone (preserves "default guard = phone on new
        // docs" the original code intended via `?? $phone`).
        $phone = $pick(['Phone Number', 'phoneNumber', 'Phone', 'phone']);
        $guard = $pick(['Guard Contact', 'guardContact']);
        if ($phone !== null) $doc['phone'] = $phone;
        if ($guard !== null)        $doc['guardContact'] = $guard;
        elseif ($phone !== null)    $doc['guardContact'] = $phone;
        if (($v = $pick(['Guard Relation', 'guardRelation'])) !== null) $doc['guardRelation'] = $v;

        if (($v = $pick(['Email', 'email', 'Parent_email'])) !== null) $doc['email'] = $v;

        // Address (full structured)
        if (($v = $pick(['Address', 'address'])) !== null) $doc['address'] = $v;

        // Profile
        if (($v = $pick(['Profile Pic', 'profilePic'])) !== null)   $doc['profilePic']    = $v;
        if (($v = $pick(['Gender', 'gender'])) !== null)            $doc['gender']        = $v;
        if (($v = $pick(['DOB', 'dob'])) !== null)                  $doc['dob']           = $v;
        if (($v = $pick(['Blood Group', 'bloodGroup'])) !== null)   $doc['bloodGroup']    = $v;
        if (($v = $pick(['Category', 'category'])) !== null)        $doc['category']      = $v;
        if (($v = $pick(['Religion', 'religion'])) !== null)        $doc['religion']      = $v;
        if (($v = $pick(['Nationality', 'nationality'])) !== null)  $doc['nationality']   = $v;
        if (($v = $pick(['Admission Date', 'admissionDate'])) !== null) $doc['admissionDate'] = $v;

        // Status (kept conditional under S7 — pre-fix had a 'Active' default
        // here that would clobber existing status on partial calls)
        if (($v = $pick(['Status', 'status'])) !== null) $doc['status'] = $v;

        // Communication preferences (defaults block — preserved as always-
        // written per S7 scope: the every-call-overwrite of operator
        // customization is a separate Tier-3 concern.)
        $doc['notificationPrefs'] = [
            'attendance' => true,
            'fees'       => true,
            'homework'   => true,
            'exam'       => true,
            'circular'   => true,
            'sms'        => false,
        ];

        return $this->_write('parents', $docId, $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  STAFF / TEACHERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync staff profile to Firestore 'staff' collection.
     * Call after: Staff::add_staff_ajax(), Staff::edit_staff()
     */
    public function syncStaff(string $staffId, array $data): bool
    {
        if (!$this->ready) return false;
        $docId = "{$this->schoolCode}_{$staffId}";
        $doc = [
            'schoolCode'  => $this->schoolId,
            'staffId'     => $staffId,
            'name'        => $data['Name'] ?? $data['name'] ?? '',
            'email'       => $data['Email'] ?? $data['email'] ?? '',
            'phone'       => $data['Phone'] ?? $data['phone'] ?? '',
            'role'        => $data['Role'] ?? $data['role'] ?? '',
            'designation' => $data['designation'] ?? $data['Designation'] ?? $data['Position'] ?? '',
            'position'    => $data['Position'] ?? $data['position'] ?? '',
            'department'  => $data['Department'] ?? $data['department'] ?? '',
            // Carry role fields so a sync-only staff doc is still visible to
            // teacher-detection (Academic) and payroll classification.
            'staff_roles'  => is_array($data['staff_roles'] ?? null) ? array_values($data['staff_roles']) : [],
            'primary_role' => $data['primary_role'] ?? '',
            'profilePic'  => $data['Doc']['ProfilePic'] ?? $data['profile_pic'] ?? '',
            'status'      => $data['Status'] ?? 'Active',
            // BUG-052 fix 2026-05-25: caller-provided session precedence
            'session'     => $data['session'] ?? $data['Session'] ?? $this->session,
            'updatedAt'   => date('c'),

            // Phase A (2026-04-08): non-sensitive profile fields only.
            'altPhone'      => $data['altPhone']      ?? '',
            'maritalStatus' => $data['maritalStatus']  ?? '',
            // SECURITY (audit C1): panNumber / aadharNumber / pfNumber / esiNumber
            // are NO LONGER written to the same-school-readable `staff` doc here.
            // This Dual_write mirror path (Dual_write::syncStaff) would otherwise
            // re-introduce the exact PII leak C1 closed. Those fields live only in
            // the owner-readable/server-written `staffPrivate` doc now; the canonical
            // writer is Staff.php (_split_staff_private).
        ];
        return $this->_write('staff', $docId, $doc);
    }

    /**
     * Delete staff from Firestore.
     */
    public function deleteStaff(string $staffId): bool
    {
        if (!$this->ready) return false;
        $docId = "{$this->schoolCode}_{$staffId}";
        return $this->_delete('staff', $docId);
    }

    // ══════════════════════════════════════════════════════════════════
    //  SECTIONS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync section info to Firestore 'sections' collection.
     * Call after: School_config::save_section()
     */
    public function syncSection(string $className, string $section, array $data = []): bool
    {
        if (!$this->ready) return false;
        $classKey   = Firestore_service::classKey($className);
        $sectionKey = Firestore_service::sectionKey($section);
        // Android expects: {schoolId}_{session}_{className}_{section}
        $docId      = "{$this->schoolId}_{$this->session}_{$classKey}_{$sectionKey}";
        $doc = [
            'schoolId'     => $this->schoolId,
            'className'    => $classKey,
            'section'      => $sectionKey,
            'classTeacher' => $data['ClassTeacher'] ?? '',
            // BUG-052 fix 2026-05-25: caller-provided session precedence
            'session'      => $data['session'] ?? $data['Session'] ?? $this->session,
            'studentCount' => 0,
            'updatedAt'    => date('c'),
        ];
        return $this->_write('sections', $docId, $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  EXAMS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync exam metadata to Firestore 'exams' collection.
     * Call after: Exam::save_exam()
     */
    public function syncExam(string $examId, array $data): bool
    {
        if (!$this->ready) return false;
        $docId = "{$this->schoolId}_{$examId}";
        return $this->_write('exams', $docId, $this->buildExamDoc($examId, $data));
    }

    /**
     * EXAM-DEF-FS-CUTOVER Phase 0 (contract alignment): build the canonical
     * `exams` document matching the mobile ExamDoc contract (Parent + Teacher,
     * identical) and the ExamFirestoreRepository.getExams() query, which filters
     * `schoolId` + `session` and orders by `startDate`.
     *
     * Pure (no I/O) so the shape can be verified without a Firestore write.
     * `schoolId` VALUE = $this->schoolId (the SCH_ id — same value students /
     * results use; confirmed against live Firestore). Callers should pass
     * `startDate`/`endDate` in ISO `YYYY-MM-DD` so the orderBy('startDate') query
     * sorts chronologically, and `gradingScale` in the mobile enum form.
     * Accepts both camelCase and legacy snake_case input keys.
     */
    public function buildExamDoc(string $examId, array $data): array
    {
        return [
            'schoolId'          => $this->schoolId,   // was 'schoolCode' — getExams() filters on schoolId
            'session'           => $data['session'] ?? $data['Session'] ?? $this->session,
            'examId'            => $examId,
            'examName'          => $data['examName'] ?? $data['name'] ?? $data['exam_name'] ?? '',
            'examType'          => $data['examType'] ?? $data['type'] ?? '',
            'gradingScale'      => $data['gradingScale'] ?? $data['grading_scale'] ?? '',
            'passingPercent'    => (int) ($data['passingPercent'] ?? $data['passing_percent'] ?? 33),
            'maxTotal'          => (float) ($data['maxTotal'] ?? $data['max_total'] ?? $data['max_marks'] ?? 0),
            'maxTheory'         => (float) ($data['maxTheory'] ?? $data['max_theory'] ?? 0),
            'maxPractical'      => (float) ($data['maxPractical'] ?? $data['max_practical'] ?? 0),
            'startDate'         => $data['startDate'] ?? $data['start_date'] ?? '',
            'endDate'           => $data['endDate'] ?? $data['end_date'] ?? '',
            'status'            => $data['status'] ?? 'Draft',
            'weight'            => (float) ($data['weight'] ?? 0),
            'applicableClasses' => array_values($data['applicableClasses'] ?? $data['applicable_classes'] ?? []),
            // Phase 2B: detail-view fields (additive; mobile ignores extras; closes the 2A residual).
            'generalInstructions' => array_values($data['generalInstructions'] ?? $data['GeneralInstructions'] ?? []),
            'createdBy'           => (string) ($data['createdBy'] ?? $data['CreatedBy'] ?? ''),
            // Phase 1 lifecycle audit (additive; null until the transition stamps them).
            'publishedBy'         => $data['publishedBy'] ?? null,
            'publishedAt'         => $data['publishedAt'] ?? null,
            'completedBy'         => $data['completedBy'] ?? null,
            'completedAt'         => $data['completedAt'] ?? null,
            // Phase 3.1 reverse-lifecycle audit (additive; null until the
            // Unpublish/Reopen transitions are introduced in Phase 3.2).
            'unpublishedBy'       => $data['unpublishedBy'] ?? null,
            'unpublishedAt'       => $data['unpublishedAt'] ?? null,
            'reopenedBy'          => $data['reopenedBy'] ?? null,
            'reopenedAt'          => $data['reopenedAt'] ?? null,
            'createdAt'         => $data['createdAt'] ?? date('c'),
            'updatedAt'         => date('c'),
        ];
    }

    /**
     * Sync exam schedule to Firestore 'examSchedule' collection.
     */
    public function syncExamSchedule(string $examId, string $className, string $section, array $scheduleData): bool
    {
        if (!$this->ready) return false;
        $classKey   = Firestore_service::classKey($className);
        $sectionKey = Firestore_service::sectionKey($section);
        $docId      = "{$this->schoolId}_{$examId}_{$classKey}_{$sectionKey}";
        $doc = [
            'schoolCode'  => $this->schoolId,
            'examId'      => $examId,
            'className'   => $classKey,
            'section'     => $sectionKey,
            'schedule'    => $scheduleData,
            // BUG-052 fix 2026-05-25: caller-provided session precedence (parameter is $scheduleData here)
            'session'     => $scheduleData['session'] ?? $scheduleData['Session'] ?? $this->session,
            'updatedAt'   => date('c'),
        ];
        return $this->_write('examSchedule', $docId, $doc);
    }

    /**
     * Phase 6 (2026-04-08): canonical exam-schedule write.
     *
     * Replaces the legacy single-subject syncExamSchedule() call which had
     * two critical bugs:
     *   1. The docId was {schoolId}_{examId}_{class}_{section} — same for
     *      every subject — so writing one subject at a time silently
     *      overwrote every previous subject. Only the LAST subject ever
     *      survived in Firestore.
     *   2. Teacher app's ExamScheduleDoc.kt expects `subjects: List<...>`,
     *      not a flat `schedule` object. Even if the per-subject overwrite
     *      hadn't existed, the data shape was incompatible.
     *
     * Callers should accumulate every subject for a (className, section)
     * pair into one array and call this ONCE per section.
     *
     * @param array $subjects List of {subjectName, date, startTime, endTime,
     *                                 maxTheory, maxPractical, maxTotal,
     *                                 passingMarks, room} entries.
     */
    public function syncExamScheduleFull(string $examId, string $className, string $section, array $subjects): bool
    {
        if (!$this->ready) return false;

        $cs = self::normalizeClassSection($className, $section);
        $classKey   = $cs['className'] !== '' ? $cs['className'] : Firestore_service::classKey($className);
        $sectionKey = $cs['section']   !== '' ? $cs['section']   : Firestore_service::sectionKey($section);
        $docId      = "{$this->schoolId}_{$examId}_{$classKey}_{$sectionKey}";

        $doc = [
            'schoolId'    => $this->schoolId,
            'schoolCode'  => $this->schoolCode,
            'session'     => $this->session,
            'examId'      => $examId,
            'className'   => $classKey,
            'section'     => $sectionKey,
            'classOrder'  => $cs['classOrder'],
            'sectionCode' => $cs['sectionCode'],
            'sectionKey'  => ($classKey !== '' && $sectionKey !== '') ? "{$classKey}/{$sectionKey}" : '',
            'subjects'    => array_values($subjects),  // ensure JSON array, not object
            'updatedAt'   => date('c'),
            'createdAt'   => date('c'),
        ];
        return $this->_write('examSchedule', $docId, $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ATTENDANCE (detail records)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync individual attendance record to Firestore 'attendance' collection.
     * Call after: Attendance::save_attendance()
     */
    public function syncAttendanceRecord(string $studentId, string $date, array $data): bool
    {
        if (!$this->ready) return false;
        $docId = "{$this->schoolCode}_{$studentId}_{$date}";
        $doc = [
            'schoolCode'  => $this->schoolId,
            'studentId'   => $studentId,
            'date'        => $date,
            'status'      => $data['status'] ?? 'P',  // P/A/L/H/T/V
            'className'   => Firestore_service::classKey($data['className'] ?? ''),
            'section'     => Firestore_service::sectionKey($data['section'] ?? ''),
            'markedBy'    => $data['markedBy'] ?? '',
            'markedAt'    => $data['markedAt'] ?? date('c'),
            'late'        => $data['late'] ?? false,
            'lateMinutes' => $data['lateMinutes'] ?? 0,
            // BUG-052 fix 2026-05-25: caller-provided session precedence
            'session'     => $data['session'] ?? $data['Session'] ?? $this->session,
        ];
        return $this->_write('attendance', $docId, $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync notification to Firestore 'notifications' collection.
     * Call after: any notification push event
     */
    public function syncNotification(string $notifId, array $data): bool
    {
        if (!$this->ready) return false;
        $docId = "{$this->schoolCode}_{$notifId}";
        $doc = [
            'schoolCode'  => $this->schoolId,
            'title'       => $data['title'] ?? '',
            'body'        => $data['body'] ?? $data['message'] ?? '',
            'type'        => $data['type'] ?? 'general',
            'targetRole'  => $data['targetRole'] ?? 'all',
            'targetClass' => $data['targetClass'] ?? '',
            'createdAt'   => $data['createdAt'] ?? date('c'),
            'createdBy'   => $data['createdBy'] ?? '',
            // BUG-052 fix 2026-05-25: caller-provided session precedence
            'session'     => $data['session'] ?? $data['Session'] ?? $this->session,
        ];
        return $this->_write('notifications', $docId, $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  TRANSPORT
    // ══════════════════════════════════════════════════════════════════

    public function syncRoute(string $routeId, array $data): bool
    {
        if (!$this->ready) return false;
        $doc = array_merge($data, [
            'schoolCode' => $this->schoolId,
            'routeId'    => $routeId,
            'updatedAt'  => date('c'),
        ]);
        return $this->_write('routes', "{$this->schoolCode}_{$routeId}", $doc);
    }

    public function syncVehicle(string $vehicleId, array $data): bool
    {
        if (!$this->ready) return false;
        $doc = array_merge($data, [
            'schoolCode' => $this->schoolId,
            'vehicleId'  => $vehicleId,
            'updatedAt'  => date('c'),
        ]);
        return $this->_write('vehicles', "{$this->schoolCode}_{$vehicleId}", $doc);
    }

    public function syncStudentRoute(string $studentId, array $data): bool
    {
        if (!$this->ready) return false;
        $doc = array_merge($data, [
            'schoolCode' => $this->schoolId,
            'studentId'  => $studentId,
            'updatedAt'  => date('c'),
        ]);
        return $this->_write('studentRoutes', "{$this->schoolCode}_{$studentId}", $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  GALLERY & STORIES
    // ══════════════════════════════════════════════════════════════════

    // ──────────────────────────────────────────────────────────────────
    //  GALLERY — canonical app contract (2026-07 consolidation)
    // ──────────────────────────────────────────────────────────────────
    //
    // Mobile read-contract (Teacher + Parent, identical):
    //   galleryAlbums/{schoolId}_{albumId}
    //     {schoolId, albumId, title, description, category, session,
    //      coverImage, source("general"|"event"), eventId, mediaCount(int),
    //      isArchived(bool), createdBy, createdAt(ISO), updatedAt(ISO)}
    //   galleryMedia/{albumId}_{mediaId}
    //     {schoolId, albumId, url, type("image"|"video"), thumbnail,
    //      duration, caption, isArchived(bool), uploadedBy, uploadedAt(ISO)}
    //
    // These two methods previously wrote `schoolCode` + wrong doc-ids and had
    // ZERO callers. They are now wired from Schools::uploadMedia / deleteMedia /
    // setEventCover as a best-effort dual-write alongside the RTDB Events tree.
    // For this school ERP, schoolId (SCH_XXXXXX) === school_name, so the value
    // passed to init() as $schoolId is the canonical SCH_ id the apps query on.

    /**
     * Upsert a gallery album (merge). Only the contract fields actually present
     * in $data are written, so a partial call (e.g. cover-only) never clears
     * other fields. mediaCount is intentionally NOT written here — use
     * bumpGalleryAlbumCount() for atomic, concurrency-safe count maintenance.
     */
    public function syncGalleryAlbum(string $albumId, array $data): bool
    {
        if (!$this->ready || $albumId === '') return false;
        $docId = "{$this->schoolId}_{$albumId}";

        $doc = [
            'schoolId'  => $this->schoolId,
            'albumId'   => $albumId,
            'session'   => $data['session'] ?? $this->session,
            'updatedAt' => date('c'),
        ];
        // Copy contract fields only when the caller supplied a non-empty value
        // (merge-safe: omitted fields keep whatever is already on the doc).
        foreach (['title', 'description', 'category', 'coverImage', 'source', 'eventId', 'createdBy'] as $k) {
            if (isset($data[$k]) && $data[$k] !== '') $doc[$k] = $data[$k];
        }
        if (array_key_exists('isArchived', $data)) $doc['isArchived'] = (bool) $data['isArchived'];
        if (array_key_exists('mediaCount', $data)) $doc['mediaCount'] = (int) $data['mediaCount'];
        // createdAt is only meaningful on first create; merge preserves the
        // original value once set, so re-sends never bump it.
        if (!empty($data['createdAt'])) $doc['createdAt'] = $data['createdAt'];

        return $this->_write('galleryAlbums', $docId, $doc);
    }

    /**
     * Atomically add $delta to an album's mediaCount (server-side increment —
     * concurrency-safe, upserts mediaCount from 0 when absent). +1 on upload,
     * -1 on delete.
     */
    public function bumpGalleryAlbumCount(string $albumId, int $delta): bool
    {
        if (!$this->ready || $albumId === '' || $delta === 0) return false;
        $docId = "{$this->schoolId}_{$albumId}";
        try {
            return $this->firebase->firestoreIncrement('galleryAlbums', $docId, ['mediaCount' => $delta]);
        } catch (\Exception $e) {
            log_message('error', "Entity_firestore_sync::bumpGalleryAlbumCount({$docId}) failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Patch an album's coverImage only (merge). Used by Schools::setEventCover.
     */
    public function updateGalleryAlbumCover(string $albumId, string $coverImage, ?bool $pinned = null): bool
    {
        if (!$this->ready || $albumId === '') return false;
        $docId = "{$this->schoolId}_{$albumId}";
        $doc = [
            'schoolId'   => $this->schoolId,
            'albumId'    => $albumId,
            'coverImage' => $coverImage,
            'updatedAt'  => date('c'),
        ];
        // coverPinned = true when an admin deliberately chose this cover
        // (setEventCover) so later uploads don't auto-advance it; false releases
        // the pin so the cover resumes tracking the latest media. null leaves it.
        if ($pinned !== null) $doc['coverPinned'] = $pinned;
        return $this->_write('galleryAlbums', $docId, $doc);
    }

    /**
     * Insert / overwrite a gallery-media doc. doc-id = {albumId}_{mediaId}.
     * Signature is (albumId, mediaId, data) — the media doc-id is compound on
     * albumId + mediaId per the app contract.
     */
    public function syncGalleryMedia(string $albumId, string $mediaId, array $data): bool
    {
        if (!$this->ready || $albumId === '' || $mediaId === '') return false;
        $docId = "{$albumId}_{$mediaId}";

        $doc = [
            'schoolId'   => $this->schoolId,
            'albumId'    => $albumId,
            'mediaId'    => $mediaId,
            'url'        => (string) ($data['url'] ?? ''),
            'type'       => (string) ($data['type'] ?? 'image'),
            'thumbnail'  => (string) ($data['thumbnail'] ?? ''),
            'duration'   => (string) ($data['duration'] ?? ''),
            'caption'    => (string) ($data['caption'] ?? ''),
            'isArchived' => isset($data['isArchived']) ? (bool) $data['isArchived'] : false,
            'uploadedBy' => (string) ($data['uploadedBy'] ?? ''),
            'uploadedAt' => (string) ($data['uploadedAt'] ?? date('c')),
        ];
        return $this->_write('galleryMedia', $docId, $doc);
    }

    /**
     * Delete a gallery-media doc. doc-id = {albumId}_{mediaId}.
     */
    public function syncDeleteGalleryMedia(string $albumId, string $mediaId): bool
    {
        if (!$this->ready || $albumId === '' || $mediaId === '') return false;
        return $this->_delete('galleryMedia', "{$albumId}_{$mediaId}");
    }

    public function syncStory(string $storyId, array $data): bool
    {
        if (!$this->ready) return false;
        $doc = array_merge($data, [
            'schoolCode' => $this->schoolId,
            'storyId'    => $storyId,
            'updatedAt'  => date('c'),
        ]);
        return $this->_write('stories', "{$this->schoolCode}_{$storyId}", $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  LEAVE APPLICATIONS
    // ══════════════════════════════════════════════════════════════════

    public function syncLeaveApplication(string $leaveId, array $data): bool
    {
        if (!$this->ready) return false;
        $doc = array_merge($data, [
            'schoolCode' => $this->schoolId,
            'leaveId'    => $leaveId,
            'updatedAt'  => date('c'),
        ]);
        return $this->_write('leaveApplications', "{$this->schoolCode}_{$leaveId}", $doc);
    }

    // ══════════════════════════════════════════════════════════════════
    //  BULK SYNC (for migration / initial population)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Sync all students for the current school from RTDB to Firestore.
     * Call from migration or admin bulk operation.
     *
     * @return int Number of students synced
     */
    public function bulkSyncStudents(string $parentDbKey): int
    {
        if (!$this->ready) return 0;
        $students = $this->firebase->get("Users/Parents/{$parentDbKey}");
        if (!is_array($students)) return 0;

        $count = 0;
        foreach ($students as $id => $data) {
            if (!is_array($data)) continue;
            if ($this->syncStudent((string) $id, $data)) $count++;
            if ($this->syncParent((string) $id, $data)) {} // best-effort
        }
        return $count;
    }

    /**
     * Sync all staff for the current school from RTDB to Firestore.
     *
     * @return int Number of staff synced
     */
    public function bulkSyncStaff(string $schoolCode): int
    {
        if (!$this->ready) return 0;
        $admins = $this->firebase->get("Users/Admin/{$schoolCode}");
        if (!is_array($admins)) return 0;

        $count = 0;
        foreach ($admins as $id => $data) {
            if (!is_array($data) || $id === '_Counter') continue;
            if ($this->syncStaff((string) $id, $data)) $count++;
        }
        return $count;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Best-effort write to Firestore. Never throws.
     */
    private function _write(string $collection, string $docId, array $data): bool
    {
        try {
            return $this->firebase->firestoreSet($collection, $docId, $data, true);
        } catch (\Exception $e) {
            log_message('error', "Entity_firestore_sync::_write({$collection}/{$docId}) failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Best-effort delete from Firestore. Never throws.
     */
    private function _delete(string $collection, string $docId): bool
    {
        try {
            return $this->firebase->firestoreDelete($collection, $docId);
        } catch (\Exception $e) {
            log_message('error', "Entity_firestore_sync::_delete({$collection}/{$docId}) failed: " . $e->getMessage());
            return false;
        }
    }
}
