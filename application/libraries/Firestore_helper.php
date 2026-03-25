<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Firestore_helper — Centralized Firestore operations for the admin panel.
 *
 * Wraps Firebase library's Firestore methods with school-context awareness,
 * standard sectionKey builder, and collection name constants matching
 * the Android apps' Constants.Firestore.
 *
 * Usage:
 *   $this->load->library('Firestore_helper', null, 'fs');
 *   $this->fs->init($this->firebase, $this->school_name, $this->session_year);
 *   $docs = $this->fs->query('homework', [['schoolId', '=', $this->fs->schoolCode()]]);
 */
class Firestore_helper
{
    private $firebase;
    private $schoolCode;
    private $session;
    private $ready = false;

    // ── Collection names (must match Android Constants.Firestore) ────
    const SCHOOLS             = 'schools';
    const STAFF               = 'staff';
    const STUDENTS            = 'students';
    const PARENTS             = 'parents';
    const SECTIONS            = 'sections';
    const USERS               = 'users';
    const ATTENDANCE          = 'attendance';
    const ATTENDANCE_SUMMARY  = 'attendanceSummary';
    const HOMEWORK            = 'homework';
    const SUBMISSIONS         = 'submissions';
    const LEAVE_APPLICATIONS  = 'leaveApplications';
    const EXAMS               = 'exams';
    const EXAM_SCHEDULE       = 'examSchedule';
    const MARKS               = 'marks';
    const RESULTS             = 'results';
    const TIMETABLES          = 'timetables';
    const FEE_STRUCTURES      = 'feeStructures';
    const FEE_DEMANDS         = 'feeDemands';
    const FEE_DEFAULTERS      = 'feeDefaulters';
    const FEE_RECEIPTS        = 'feeReceipts';
    const PAYMENT_INTENTS     = 'paymentIntents';
    const CIRCULARS           = 'circulars';
    const EVENTS              = 'events';
    const GALLERY_ALBUMS      = 'galleryAlbums';
    const GALLERY_MEDIA       = 'galleryMedia';
    const STORIES             = 'stories';
    const NOTIFICATIONS       = 'notifications';
    const LIBRARY_BOOKS       = 'libraryBooks';
    const LIBRARY_ISSUES      = 'libraryIssues';
    const LIBRARY_FINES       = 'libraryFines';

    /**
     * Initialise with Firebase instance and school context.
     */
    public function init($firebase, string $schoolCode, string $session): self
    {
        $this->firebase   = $firebase;
        $this->schoolCode = $schoolCode;
        $this->session    = $session;
        $this->ready      = ($firebase !== null && $schoolCode !== '' && $session !== '');
        return $this;
    }

    public function schoolCode(): string { return $this->schoolCode; }
    public function session(): string { return $this->session; }

    // ── Class/Section helpers ────────────────────────────────────────

    /**
     * Ensure "Class " prefix: "8th" → "Class 8th"
     */
    public static function classKey(string $val): string
    {
        $s = trim($val);
        return ($s !== '' && stripos($s, 'Class ') !== 0) ? "Class {$s}" : $s;
    }

    /**
     * Ensure "Section " prefix: "A" → "Section A"
     */
    public static function sectionKey(string $val): string
    {
        $s = trim($val);
        return ($s !== '' && stripos($s, 'Section ') !== 0) ? "Section {$s}" : $s;
    }

    /**
     * Build sectionKey: "Class 8th/Section A"
     */
    public static function buildSectionKey(string $className, string $section): string
    {
        return self::classKey($className) . '/' . self::sectionKey($section);
    }

    // ── CRUD wrappers ────────────────────────────────────────────────

    /**
     * Get a single document.
     */
    public function get(string $collection, string $docId): ?array
    {
        if (!$this->ready) return null;
        return $this->firebase->firestoreGet($collection, $docId);
    }

    /**
     * Set (create/overwrite) a document.
     */
    public function set(string $collection, string $docId, array $data, bool $merge = false): bool
    {
        if (!$this->ready) return false;
        return $this->firebase->firestoreSet($collection, $docId, $data, $merge);
    }

    /**
     * Update specific fields on a document.
     */
    public function update(string $collection, string $docId, array $data): bool
    {
        if (!$this->ready) return false;
        return $this->firebase->firestoreUpdate($collection, $docId, $data);
    }

    /**
     * Delete a document.
     */
    public function delete(string $collection, string $docId): bool
    {
        if (!$this->ready) return false;
        return $this->firebase->firestoreDelete($collection, $docId);
    }

    /**
     * Query documents.
     */
    public function query(
        string $collection,
        array $conditions = [],
        ?string $orderBy = null,
        string $direction = 'DESC',
        ?int $limit = null
    ): array {
        if (!$this->ready) return [];
        return $this->firebase->firestoreQuery($collection, $conditions, $orderBy, $direction, $limit);
    }

    /**
     * Query documents filtered by this school.
     * Automatically adds schoolId condition.
     */
    public function querySchool(
        string $collection,
        array $extraConditions = [],
        ?string $orderBy = null,
        string $direction = 'DESC',
        ?int $limit = null
    ): array {
        $conditions = array_merge(
            [['schoolId', '=', $this->schoolCode]],
            $extraConditions
        );
        return $this->query($collection, $conditions, $orderBy, $direction, $limit);
    }

    /**
     * Query documents filtered by this school + session.
     */
    public function querySchoolSession(
        string $collection,
        array $extraConditions = [],
        ?string $orderBy = null,
        string $direction = 'DESC',
        ?int $limit = null
    ): array {
        $conditions = array_merge(
            [
                ['schoolId', '=', $this->schoolCode],
                ['session', '=', $this->session],
            ],
            $extraConditions
        );
        return $this->query($collection, $conditions, $orderBy, $direction, $limit);
    }
}
