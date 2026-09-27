<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student_timeline_reader — one chronological view across the audit trails
 * that several modules already keep.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT IT DELIBERATELY DOES NOT DO (baseline §Student 360 Readiness, point 6)
 * ─────────────────────────────────────────────────────────────────────────
 *  • It does NOT create a new "studentTimeline" collection. A unified write
 *    target would mean every module dual-writing its history — a second
 *    source of truth, guaranteed to drift from the first, and the exact
 *    divergence class this project exists to remove.
 *  • It does NOT write. Ever.
 *  • It does NOT interpret. It normalises shape (when / what / who / source)
 *    and orders by time. Meaning stays with the module that recorded it.
 *
 *  Each entry keeps its `source` collection so the operator can see WHERE a
 *  fact came from. That provenance is the point: a timeline that hides its
 *  origins is unauditable.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  COST, AND WHY SORTING HAPPENS IN PHP
 * ─────────────────────────────────────────────────────────────────────────
 *  One bounded query per source collection — three reads, fixed.
 *
 *  Ordering is applied in PHP after the fetch, NOT by Firestore. Asking
 *  Firestore for `schoolId == · studentId == · orderBy timestamp DESC` needs a
 *  composite index on each of the three collections, and this module is not
 *  authorized to deploy indexes. That constraint has a real consequence worth
 *  stating plainly: a Firestore `limit` WITHOUT an `orderBy` returns an
 *  arbitrary slice ordered by document id — so a naive cap would have silently
 *  shown "some 40 events" while looking exactly like "the 40 most recent".
 *
 *  So the cap is set well above any realistic per-student history, the whole
 *  set is sorted here, and when a source does hit its cap the result says so
 *  (`truncated`) instead of quietly presenting a partial history as complete.
 *  If per-student volumes ever approach the cap, the fix is the composite
 *  indexes plus a real `orderBy` — not a bigger number.
 */
class Student_timeline_reader
{
    /** @var object|null */ private $firebase;
    private string $schoolId = '';

    /**
     * The audit collections that are genuinely filterable BY STUDENT, with the
     * field names each writer actually emits — verified against the writers,
     * not assumed. They were written by different modules at different times
     * and agree on almost nothing, so normalising on read is far cheaper and
     * safer than migrating live audit data.
     *
     *   studentHistory  Sis::_log_history()          studentId / changed_at / changed_by
     *   feeAuditLogs    Fee_audit_logger::record()   entityId  / timestamp  / performedBy
     *
     * ── WHY `feeAuditLogs` FILTERS ON `entityId` ──────────────────────────
     * It has no studentId field. `record()` takes (entity, entityId), and the
     * two student-scoped writers pass the student id as entityId:
     * Fee_lifecycle → ('demand', $studentId), Fee_defaulter_check →
     * ('defaulter', $studentId). Fee_refund_service passes a refund id, so
     * refund rows simply do not match — correct, not a gap.
     *
     * ── WHY THREE OTHER COLLECTIONS ARE NOT HERE ──────────────────────────
     * `auditLogs` is written by Document_service keyed on `documentId`, with
     * no student field at all — it cannot be filtered to a student, only
     * scanned. `tenantAudit` and `accountingAudit` key by tenant and by ledger
     * entry. Including any of them would mean reading the whole collection and
     * filtering in PHP — a cost that grows with school age, to surface rows
     * mostly not about this student.
     */
    private const SOURCES = [
        'studentHistory' => [
            'student' => 'studentId', 'when' => 'changed_at',
            'what'    => 'description', 'who' => 'changed_by',
        ],
        'feeAuditLogs'   => [
            'student' => 'entityId',  'when' => 'timestamp',
            // No description field exists; the line is composed from
            // action + entity + reason. See _describe().
            'what'    => null,        'who' => 'performedBy',
        ],
    ];

    public function init($firebase, string $schoolId): self
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        return $this;
    }

    /**
     * @param int $perSource Hard cap per source collection. Deliberately high:
     *        it is a runaway guard, not a page size. See the class note on why
     *        a low cap would be actively misleading here.
     * @return array{entries: array, sources: array, truncated: bool}
     */
    public function forStudent(string $studentId, int $perSource = 250): array
    {
        $entries   = [];
        $sourceLog = [];
        $truncated = false;
        if ($this->firebase === null || $studentId === '') {
            return ['entries' => [], 'sources' => [], 'truncated' => false];
        }
        $perSource = max(1, min($perSource, 500));

        foreach (self::SOURCES as $col => $map) {
            $got = 0;
            try {
                $rows = $this->firebase->firestoreQuery($col, [
                    ['schoolId',      '==', $this->schoolId],
                    [$map['student'], '==', $studentId],
                ], null, 'ASC', $perSource);

                foreach ((array) $rows as $r) {
                    $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                    if (!is_array($d)) continue;
                    $what = $map['what'] !== null
                        ? trim((string) ($d[$map['what']] ?? ''))
                        : self::_describe($d);
                    if ($what === '') continue;   // an entry with no story is noise
                    $entries[] = [
                        'when'   => self::_normalizeWhen((string) ($d[$map['when']] ?? '')),
                        'what'   => $what,
                        'who'    => (string) ($d[$map['who']] ?? ''),
                        'source' => $col,
                    ];
                    $got++;
                }
            } catch (\Throwable $e) {
                // One unavailable collection must not blank the whole
                // timeline — record the failure and carry on with the rest.
                log_message('error', "Student_timeline_reader: {$col} read failed for {$studentId}: " . $e->getMessage());
                $sourceLog[$col] = 'error';
                continue;
            }
            $sourceLog[$col] = $got;
            if ($got >= $perSource) $truncated = true;
        }

        // Newest first. Safe as a string compare ONLY because _normalizeWhen()
        // has already put every source into one format — the two writers do
        // not agree natively ('Y-m-d H:i:s' vs ISO-8601 with a 'T'), and a
        // raw compare would sort every same-day feeAuditLogs row above every
        // studentHistory row regardless of the actual time, because ' ' < 'T'.
        // Entries with no timestamp sort last rather than being dropped.
        usort($entries, function ($a, $b) {
            if ($a['when'] === $b['when']) return 0;
            if ($a['when'] === '') return 1;
            if ($b['when'] === '') return -1;
            return strcmp($b['when'], $a['when']);
        });

        return ['entries' => $entries, 'sources' => $sourceLog, 'truncated' => $truncated];
    }

    /**
     * Put every source's timestamp into one comparable, displayable format.
     *
     * Returns 'Y-m-d H:i:s', or '' when the value cannot be parsed — never a
     * fabricated time. An unparseable stamp sorts last and displays blank,
     * which is honest; inventing "now" for it would silently move a real event
     * to the top of the student's history.
     */
    private static function _normalizeWhen(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $ts = strtotime($raw);
        return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
    }

    /**
     * Compose a readable line for a source that records no description.
     *
     * `Fee_audit_logger` stores structured change data (action / entity /
     * reason / changedKeys) rather than prose, so a sentence has to be built
     * from it. This is PRESENTATION — it renames nothing and decides nothing.
     * The reader must not start interpreting what a change meant; if a line
     * needs more than these fields to make sense, the fix is for the writer to
     * record it, not for this method to infer it.
     */
    private static function _describe(array $d): string
    {
        $action = trim((string) ($d['action'] ?? ''));
        $entity = trim((string) ($d['entity'] ?? ''));
        if ($action === '' && $entity === '') return '';

        $line = trim(ucfirst($action) . ' ' . $entity);
        $reason = trim((string) ($d['reason'] ?? ''));
        if ($reason !== '') $line .= ' — ' . $reason;

        $keys = $d['changedKeys'] ?? null;
        if (is_array($keys) && $keys !== []) {
            $shown = array_slice($keys, 0, 4);
            $line .= ' (' . implode(', ', array_map('strval', $shown))
                   . (count($keys) > 4 ? ', +' . (count($keys) - 4) . ' more' : '') . ')';
        }
        return $line;
    }
}
