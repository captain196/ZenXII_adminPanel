<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance_coverage_service — Phase C2 (Substitute / Coverage marking).
 *
 * WHAT THIS SOLVES
 * ----------------
 * Student attendance marking is authorised ONLY by
 * MY_Controller::_teacher_can_access() — a classroom-scoped teacher may mark a
 * section only if it appears in their own subjectAssignments. Student
 * attendance is `allow write: if false` in firestore.rules, so that PHP gate is
 * the ENTIRE boundary. When the owning class teacher is absent, nobody else
 * could legitimately mark that section for the day.
 *
 * DESIGN (from the C2 research note)
 * ----------------------------------
 * Coverage is an EXPLICIT, DATE-SCOPED, ADMIN-GRANTED assignment that is
 * ADDITIVE to the owner check — never a replacement for it, and never a general
 * "any teacher can mark any section" switch:
 *
 *   - Granted by an Admin/Principal (require_permission Attendance MANAGE) —
 *     a teacher cannot self-grant.
 *   - Scoped to ONE date + ONE class + ONE section + ONE substitute uid.
 *     A grant for 2026-09-14 gives no authority on 2026-09-15.
 *   - Revocable; a revoked grant denies immediately.
 *   - Audited: the grant records grantedBy/grantedAt; the mark itself still
 *     records the actual marker (markedBy = the substitute's uid) on the
 *     summary/day docs, so "who really marked" is never lost.
 *
 * TENANT ISOLATION
 * ----------------
 * The doc id is prefixed with the server-derived schoolId and can_cover() reads
 * by that exact id, so a grant in tenant A can never be resolved from tenant B.
 * schoolId + session are also stamped as fields for querying/audit.
 *
 * This introduces NO second attendance master. It writes only the
 * `attendanceCoverage` collection — a pure authorisation record, read
 * negatively by the marking endpoints. It never touches attendanceSummary or
 * the per-day `attendance` docs.
 */
class Attendance_coverage_service
{
    const COLLECTION = 'attendanceCoverage';

    /** @var object CI Firestore_helper ($this->fs) */
    private $fs;
    private $schoolId = '';
    private $session  = '';

    public function init($fs, string $schoolId, string $session): self
    {
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        return $this;
    }

    /**
     * Mirror MY_Controller::_cs_norm() so a grant keyed here matches the mark
     * gate exactly: strip leading "class "/"section ", lowercase, trim.
     */
    private function normClass(string $class): string
    {
        return strtolower(trim((string) preg_replace('/^(?:class\s+)+/i', '', trim($class))));
    }
    private function normSection(string $section): string
    {
        return strtolower(trim((string) preg_replace('/^(?:section\s+)+/i', '', trim($section))));
    }

    /** Sanitise a normalised token for use inside a Firestore doc id. */
    private function idToken(string $s): string
    {
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
        return trim($s, '-');
    }

    /**
     * Deterministic, tenant-scoped, idempotent id. The same (school, date,
     * class, section, teacher) always maps to one doc, so a re-grant updates in
     * place and can_cover() can read by id with no query/index.
     */
    public function docId(string $date, string $class, string $section, string $teacherId): string
    {
        return $this->schoolId . '_' . $date . '_'
            . $this->idToken($this->normClass($class)) . '_'
            . $this->idToken($this->normSection($section)) . '_'
            . $this->idToken($teacherId);
    }

    /**
     * Grant coverage. Idempotent (same id → in-place update). Returns the id.
     *
     * @throws InvalidArgumentException on bad input (caller maps to json_error)
     */
    public function grant(
        string $date, string $class, string $section,
        string $teacherId, string $teacherName,
        string $grantedBy, string $grantedByName, string $reason = ''
    ): string {
        $this->assertDate($date);
        if ($class === '' || $section === '') {
            throw new InvalidArgumentException('Class and section are required.');
        }
        if ($teacherId === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $teacherId)) {
            throw new InvalidArgumentException('A valid substitute teacher is required.');
        }
        if ($grantedBy === '' || $grantedBy === $teacherId) {
            // A teacher cannot grant coverage to themselves; the granter is the
            // admin acting server-side, never the substitute.
            throw new InvalidArgumentException('Coverage must be granted by an administrator.');
        }

        $id = $this->docId($date, $class, $section, $teacherId);
        $now = date('c');
        $this->fs->set(self::COLLECTION, $id, [
            'schoolId'         => $this->schoolId,
            'session'          => $this->session,
            'date'             => $date,
            'classKey'         => $this->normClass($class),
            'sectionKey'       => $this->normSection($section),
            'classLabel'       => trim($class),
            'sectionLabel'     => trim($section),
            'substituteId'     => $teacherId,
            'substituteName'   => trim($teacherName),
            'reason'           => trim($reason),
            'revoked'          => false,
            'grantedBy'        => $grantedBy,
            'grantedByName'    => trim($grantedByName),
            'grantedAt'        => $now,
            'updatedAt'        => $now,
        ], false);

        return $id;
    }

    /** Revoke a grant (soft — keeps the audit row). Returns true if a live grant was revoked. */
    public function revoke(
        string $date, string $class, string $section,
        string $teacherId, string $revokedBy
    ): bool {
        $id  = $this->docId($date, $class, $section, $teacherId);
        $doc = $this->fs->get(self::COLLECTION, $id);
        if (!$doc || (string) ($doc['schoolId'] ?? '') !== $this->schoolId) {
            return false; // nothing to revoke / not this tenant
        }
        if (!empty($doc['revoked'])) return false; // already revoked
        $this->fs->update(self::COLLECTION, $id, [
            'revoked'    => true,
            'revokedBy'  => $revokedBy,
            'revokedAt'  => date('c'),
            'updatedAt'  => date('c'),
        ]);
        return true;
    }

    /**
     * The authorisation predicate the marking endpoints call. TRUE only when a
     * live (non-revoked) grant exists for exactly this tenant + teacher + class
     * + section + date. Read by exact doc id — no cross-tenant path.
     */
    public function can_cover(string $teacherId, string $class, string $section, string $date): bool
    {
        if ($teacherId === '' || $date === '') return false;
        $id  = $this->docId($date, $class, $section, $teacherId);
        $doc = $this->fs->get(self::COLLECTION, $id);
        if (!$doc) return false;
        if ((string) ($doc['schoolId'] ?? '')   !== $this->schoolId) return false; // belt-and-braces
        if ((string) ($doc['substituteId'] ?? '') !== $teacherId)    return false;
        if ((string) ($doc['date'] ?? '')        !== $date)          return false;
        return empty($doc['revoked']);
    }

    /**
     * List grants for admin/audit — tenant + session scoped; optional date filter.
     *
     * Queries ONLY the base school+session pattern (which is indexed everywhere in
     * this codebase) and applies the date filter + recency sort in PHP. Adding a
     * third equality + orderBy to the Firestore query would demand a composite
     * index; without it Firestore returns a silent [] (query() never throws), so
     * the list would look empty even though grants exist.
     */
    public function listGrants(string $date = ''): array
    {
        $raw = $this->fs->schoolWhere(self::COLLECTION, [], null, 'ASC', 500);
        $raw = is_array($raw) ? $raw : [];
        $session = $this->session;
        $out = [];
        foreach ($raw as $r) {
            // Firestore_service query rows are wrapped {id, data:{...}} (unlike the
            // flat shape of get()); unwrap so callers see the fields directly.
            $d = isset($r['data']) && is_array($r['data']) ? $r['data'] : $r;
            if ((string) ($d['session'] ?? '') !== $session) continue;
            if ($date !== '' && (string) ($d['date'] ?? '') !== $date) continue;
            if (!isset($d['id']) && isset($r['id'])) $d['id'] = $r['id'];
            $out[] = $d;
        }
        usort($out, fn($a, $b) => strcmp((string) ($b['grantedAt'] ?? ''), (string) ($a['grantedAt'] ?? '')));
        return $out;
    }

    private function assertDate(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }
    }
}
