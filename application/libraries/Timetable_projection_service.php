<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * COMPATIBILITY PROJECTION — canonical version → legacy `timetables` shape.
 *
 * Pure. No reads, no writes, no clock of its own. Given the days of a published
 * version and whatever is currently in the legacy collection, it computes what
 * the legacy collection WOULD have to contain to be a faithful representation
 * of that version, and what is currently there that the version does not
 * account for.
 *
 * ── A PROJECTION IS NOT A TIMETABLE ─────────────────────────────────────
 *
 * The Teacher app reads `timetables` directly through the client SDK, filtering
 * by schoolId + sectionKey. It cannot be pointed at the version architecture
 * without an app release, so the legacy collection has to keep existing and
 * keep its shape. What changes is its STATUS: it stops being a place anyone
 * edits and becomes a derived copy of the canonical version.
 *
 * That is the invariant this class exists to make checkable. It maps one way
 * only — canonical to legacy. There is no inverse, and nothing here reads a
 * legacy edit back into a version, because two editable representations of one
 * timetable is the second source of truth the whole programme is avoiding.
 *
 * ── WHY IT MERGES RATHER THAN REPLACES ──────────────────────────────────
 *
 * Every field the canonical version defines is overwritten outright, so the
 * derived content is exact. Fields the legacy generator wrote and this layer
 * does not author (generationId, generatedAt, generatedByUid) are left alone
 * rather than erased: they are evidence about how the current live document got
 * there, and destroying them during a migration that is supposed to be
 * reversible would be the hidden destructive step.
 *
 * ── WHY ORPHANS ARE REPORTED AND NOT DELETED ────────────────────────────
 *
 * A legacy document the version does not account for — a section that no longer
 * exists, a day that was dropped — is stale content teachers can still see.
 * Removing it is the right end state and a destructive act, so this class
 * REPORTS it and lets an explicit, authorised step decide. Silently deleting
 * live teacher-visible documents during a projection is precisely the kind of
 * migration this codebase does not do.
 */
class Timetable_projection_service
{
    /** Fields the canonical version owns. Everything else on a legacy doc is left alone. */
    const OWNED_FIELDS = [
        'schoolId', 'session', 'className', 'section', 'classOrder',
        'sectionCode', 'sectionKey', 'day', 'periods', 'manuallyEdited',
    ];

    /**
     * The fields that say WHICH canonical version a document is derived from.
     *
     * Deliberately excludes `projectedAt` and `projectedByUid`: those change on
     * every run, and comparing them would make the projection rewrite every
     * document forever. Provenance is about identity, not about when it was
     * last confirmed.
     */
    const PROVENANCE_FIELDS = ['projectedFromPlan', 'projectedFromVersion'];

    /**
     * Build the legacy documents a published version implies.
     *
     * @param array $versionDays rows from timetableVersionDays
     * @param array $ctx schoolId, session, planId, versionId, at, byUid, byName
     * @return array docId => data
     */
    public static function project($versionDays, array $ctx): array
    {
        $schoolId = (string) ($ctx['schoolId'] ?? '');
        $session  = (string) ($ctx['session'] ?? '');
        if ($schoolId === '' || $session === '' || !is_array($versionDays)) return [];

        $out = [];
        foreach ($versionDays as $d) {
            if (!is_array($d)) continue;

            $sectionKey = (string) ($d['classSection'] ?? '');
            $day        = (string) ($d['day'] ?? '');
            if ($sectionKey === '' || $day === '') continue;

            // The canonical split. classOrder and sectionCode are not stored on
            // a version day, so they are derived through the SAME normaliser the
            // rest of the codebase uses rather than re-parsed here.
            $parts    = explode('/', $sectionKey, 2);
            $canon    = Entity_firestore_sync::normalizeClassSection(
                $parts[0] ?? '', $parts[1] ?? '');

            // The ID is built from the CANONICAL parts, exactly like the
            // sectionKey field below — not from the raw stored string.
            //
            // Found by the multi-tenant isolation gate, 2026-09-13. The field
            // was already rebuilt canonically, for the documented reason that a
            // malformed stored key must not propagate into the collection the
            // apps query — but the document ID was still assembled from the raw
            // value, so the guarantee stopped at the field. A stored
            // classSection of "Class 8th/Section A/../SCH_OTHER/Section Z"
            // produced the id
            //     SCH_MINE_2026-27_Class 8th_Section A_.._SCH_OTHER_Section Z_Monday
            // which stayed inside its own tenant — the prefix and every field
            // are still this school's — but is not a document any reader can
            // address, and carries another tenant's id as a substring.
            //
            // The quieter and more likely failure is duplication: the same
            // logical section stored with different spacing or casing yields
            // two different IDs but ONE canonical sectionKey field, so the apps
            // (which query on the field) receive two documents for the same
            // day and one silently wins.
            //
            // For well-formed data this changes nothing: "Class 8th/Section A"
            // normalises to itself, which is why every live document id is
            // already in this exact shape.
            $canonKey = $canon['className'] . '/' . $canon['section'];

            // A class or section that still contains a '/' after normalisation
            // cannot be represented as ONE addressable document: the key is
            // `class/section`, so a third segment makes the pair ambiguous and
            // the Teacher app — which rebuilds this id to read a cell when
            // validating a lesson-plan save — would address something else.
            //
            // Refused rather than mangled into an addressable-looking id.
            // Nothing is lost quietly: any document already there for this
            // section is then reported by plan() as not accounted for by this
            // version, which is exactly the review path for it.
            if (strpos($canon['className'], '/') !== false
                || strpos($canon['section'], '/') !== false) {
                continue;
            }

            $safeKey = str_replace('/', '_', $canonKey);
            $docId   = "{$schoolId}_{$session}_{$safeKey}_{$day}";

            $out[$docId] = [
                'schoolId'       => $schoolId,
                'session'        => $session,
                'className'      => $canon['className'],
                'section'        => $canon['section'],
                'classOrder'     => $canon['classOrder'],
                'sectionCode'    => $canon['sectionCode'],
                // Rebuilt from the canonical parts, not copied, so a malformed
                // stored key cannot propagate into the collection the app queries.
                'sectionKey'     => $canon['className'] . '/' . $canon['section'],
                'day'            => $day,
                'periods'        => is_array($d['periods'] ?? null) ? $d['periods'] : [],
                'manuallyEdited' => (bool) ($d['manuallyEdited'] ?? false),

                // Provenance: this document is derived, and from what.
                'projectedFromPlan'    => (string) ($ctx['planId'] ?? ''),
                'projectedFromVersion' => (string) ($ctx['versionId'] ?? ''),
                'projectedAt'          => (string) ($ctx['at'] ?? ''),
                'projectedByUid'       => (string) ($ctx['byUid'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Compare the projection against what the legacy collection holds.
     *
     * Idempotent by construction: a document whose owned fields already match is
     * reported as unchanged and is NOT rewritten, so projecting twice writes
     * nothing the second time and no version counter drifts.
     *
     * @return array writes: docId=>data, orphans: docIds, unchanged: docIds, explain: string
     */
    public static function plan(array $projected, $existing): array
    {
        $existing = is_array($existing) ? $existing : [];

        $writes = [];
        $unchanged = [];
        foreach ($projected as $docId => $data) {
            $have = is_array($existing[$docId] ?? null) ? $existing[$docId] : null;

            if ($have !== null
                && self::ownedFieldsMatch($have, $data)
                && self::provenanceMatches($have, $data)) {
                $unchanged[] = $docId;
                continue;
            }

            // Legacy convention: the live document carries a monotonic version.
            $data['version'] = ((int) ($have['version'] ?? 0)) + 1;
            $writes[$docId]  = $data;
        }

        $orphans = [];
        foreach (array_keys($existing) as $docId) {
            if (!isset($projected[$docId])) $orphans[] = $docId;
        }

        sort($orphans);
        sort($unchanged);

        return [
            'writes'    => $writes,
            'orphans'   => $orphans,
            'unchanged' => $unchanged,
            'explain'   => sprintf(
                '%d document(s) would be written, %d already match, %d existing '
                . 'document(s) are not accounted for by this version and are '
                . 'reported for review rather than deleted.',
                count($writes), count($unchanged), count($orphans)),
        ];
    }

    /**
     * Classify the difference between what the apps hold and what the published
     * timetable says they should hold.
     *
     * ── WHY A COUNT IS NOT AN ANSWER ────────────────────────────────────
     *
     * plan() answers "how many documents would change", which is the right
     * question for a projection run and the wrong one for a person asking
     * whether teachers are looking at the correct timetable. "18 documents
     * differ" is equally true when the apps are one lesson out of date and when
     * they are showing a different school year. Worse, "the projection command
     * completed" says nothing at all: a run that wrote 18 documents from the
     * wrong version succeeds just as loudly as one that wrote the right ones.
     *
     * On 2026-09-12 a live tenant held 18 legacy documents that matched NO
     * published version, were missing Class 8th entirely, and carried no
     * projection stamp. Every existing signal reported that as "18 pending".
     * The distinctions below are the ones that would have named it:
     *
     *   current      content and provenance both agree — genuinely in sync
     *   stale        derived from a DIFFERENT version; a projection is overdue
     *   unprojected  no provenance stamp at all; predates the canonical
     *                lifecycle and has never been materialised from a version
     *   divergent    stamped with THIS version but the content disagrees —
     *                something wrote over a projected document. The only
     *                classification here that implies a defect rather than a
     *                pending job
     *   absent       the version covers this section-day and the apps have
     *                nothing. A class whose timetable is simply missing
     *   orphan       the apps hold a document this version does not account
     *                for. Reported, never deleted
     *
     * Pure: takes what project() built, what the collection holds, and the
     * plan() already computed, and decides nothing on its own. One authority.
     *
     * @param array $projected docId => the document this version implies
     * @param array $existing  docId => the document the apps currently hold
     * @param array $plan      the result of plan() on the same two inputs
     */
    public static function diagnose(array $projected, $existing, array $plan): array
    {
        $existing = is_array($existing) ? $existing : [];
        $unchanged = array_flip(is_array($plan['unchanged'] ?? null) ? $plan['unchanged'] : []);

        $byState = ['current' => [], 'stale' => [], 'unprojected' => [],
                    'divergent' => [], 'absent' => [], 'orphan' => []];

        $sourceVersions = [];
        $lastProjectedAt = '';

        foreach ($projected as $docId => $want) {
            $have = is_array($existing[$docId] ?? null) ? $existing[$docId] : null;

            if ($have === null) { $byState['absent'][] = $docId; continue; }

            $stamp = trim((string) ($have['projectedFromVersion'] ?? ''));
            if ($stamp !== '') {
                $sourceVersions[$stamp] = ($sourceVersions[$stamp] ?? 0) + 1;
            }
            $at = trim((string) ($have['projectedAt'] ?? ''));
            if ($at !== '' && $at > $lastProjectedAt) { $lastProjectedAt = $at; }

            if (isset($unchanged[$docId])) { $byState['current'][] = $docId; continue; }

            if ($stamp === '')                              { $byState['unprojected'][] = $docId; }
            elseif (!self::provenanceMatches($have, $want))  { $byState['stale'][]       = $docId; }
            else                                             { $byState['divergent'][]   = $docId; }
        }

        foreach (is_array($plan['orphans'] ?? null) ? $plan['orphans'] : [] as $docId) {
            $byState['orphan'][] = $docId;
        }

        $counts = [];
        foreach ($byState as $state => $ids) { sort($byState[$state]); $counts[$state] = count($ids); }

        $expected = count($projected);
        $inSync   = ($counts['current'] === $expected)
                 && $counts['orphan'] === 0;

        arsort($sourceVersions);

        return [
            'inSync'          => $inSync,
            'expected'        => $expected,
            'counts'          => $counts,
            'documents'       => $byState,
            // Which canonical version(s) produced what the apps currently hold.
            // More than one means the collection is a mixture, which no single
            // publish can produce and which a partial run can.
            'sourceVersions'  => $sourceVersions,
            'lastProjectedAt' => $lastProjectedAt,
            'summary'         => self::diagnosisSentence($counts, $expected, $inSync),
        ];
    }

    /** The diagnosis in one sentence, worst thing first. */
    private static function diagnosisSentence(array $c, int $expected, bool $inSync): string
    {
        if ($inSync) {
            return 'Teachers and parents are seeing this timetable — all '
                 . $expected . ' class page(s) match it.';
        }

        $parts = [];
        // Ordered by how alarming each is, not by count: a single class with no
        // timetable at all matters more than twenty that are a day behind.
        if ($c['divergent'])   $parts[] = $c['divergent'] . ' changed by something other than publishing';
        if ($c['absent'])      $parts[] = $c['absent'] . ' missing entirely';
        if ($c['unprojected']) $parts[] = $c['unprojected'] . ' never sent from a published timetable';
        if ($c['stale'])       $parts[] = $c['stale'] . ' left over from an earlier timetable';
        if ($c['orphan'])      $parts[] = $c['orphan'] . ' for classes this timetable does not cover';

        return 'Teachers and parents are NOT seeing this timetable: '
             . implode('; ', $parts) . '.';
    }

    /**
     * Is this legacy document canonical-derived — written by the projection?
     *
     * ONE definition, because two writers still have to make this call and a
     * document that one of them protects and the other does not is the worst
     * outcome available:
     *
     *   • Timetables_reconcile skips it rather than repairing it in place;
     *   • the session rollover refuses to carry last year's copy over it
     *     (Rollover_projection_guard, which applies the same rule and is held
     *     to it by an equivalence test).
     *
     * Whitespace is not provenance: a field holding "   " says nothing about
     * which version a document came from, and reading it as a stamp would
     * protect a document nothing authored.
     */
    public static function isDerived($doc): bool
    {
        if (!is_array($doc)) return false;
        return trim((string) ($doc['projectedFromVersion'] ?? '')) !== '';
    }

    /**
     * True when the document already declares itself derived from THIS version.
     *
     * ── TT-C4: MATCHING CONTENT IS NOT THE SAME AS BEING MARKED ─────────
     *
     * Content equality was originally the whole test, and it left a hole that
     * only appeared once the projection could see existing documents at all.
     * A legacy document whose periods happened to equal the published version's
     * — the ordinary case after the first switchover, and the guaranteed case
     * for a school whose canonical version was built FROM the legacy content —
     * was reported as unchanged and never written. So it never received
     * `projectedFromVersion`.
     *
     * That stamp is not decoration. It is the single field every other guard in
     * this architecture keys off:
     *
     *   • Rollover_projection_guard refuses to overwrite a document that
     *     carries it — an unstamped canonical-derived document is carried over
     *     by the next session rollover, replacing a published timetable with
     *     last year's copy on every teacher and parent screen;
     *   • Timetables_reconcile skips documents that carry it — an unstamped one
     *     is repaired in place, which is a second authority editing projection
     *     state with no version to show for it.
     *
     * So the documents the old rule protected LEAST were exactly the ones that
     * were already correct. Provenance is therefore part of what makes a
     * document "already projected", and a document missing it is written.
     */
    public static function provenanceMatches($have, $want): bool
    {
        if (!is_array($have) || !is_array($want)) return false;
        foreach (self::PROVENANCE_FIELDS as $f) {
            $h = trim((string) ($have[$f] ?? ''));
            $w = trim((string) ($want[$f] ?? ''));
            if ($h === '' || $h !== $w) return false;
        }
        return true;
    }

    /** True when every field the canonical version owns already matches. */
    public static function ownedFieldsMatch($have, $want): bool
    {
        if (!is_array($have) || !is_array($want)) return false;
        foreach (self::OWNED_FIELDS as $f) {
            if (($have[$f] ?? null) !== ($want[$f] ?? null)) return false;
        }
        return true;
    }
}
