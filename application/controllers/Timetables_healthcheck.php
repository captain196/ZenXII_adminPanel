<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Timetables_healthcheck — SYSTEM-WIDE, READ-ONLY integrity audit of the
 * timetable ⇄ subject-matrix ⇄ staff triangle, across ALL schools & sessions.
 *
 * Mutates nothing. Safe during live traffic. This is the diagnostic companion
 * to Timetables_reconcile (which applies the teacherId/name fixes it finds).
 *
 * ── WHY ──────────────────────────────────────────────────────────────────
 * `subjectAssignments` (the subject-matrix) is the single source of truth for
 * "who teaches (class, section, subject)". Each timetable period stamps a
 * teacherId + teacher(name) at generation/edit time. When the matrix is edited
 * afterward, the timetable keeps the OLD teacher → the app (which matches
 * period.teacherId == myId) silently hides that period from its real teacher
 * and shows the wrong one to everyone. This sweep quantifies that drift and
 * every adjacent integrity defect, for current AND historical (all-session)
 * data.
 *
 * ── DIMENSIONS ─────────────────────────────────────────────────────────────
 *   id-drift          period.teacherId ≠ matrix teacherId (subject matched)
 *   name-drift        id matches but period.teacher (name) ≠ matrix name
 *   fillable-blank    class period, blank teacherId, matrix HAS a teacher
 *   unfillable-blank  class period, blank teacherId, matrix has none (real gap)
 *   subject-orphan    period subject not present in the matrix for that class
 *   ghost-teacher-tt  period teacherId not found in staff for the school
 *   inactive-teach-tt period teacher exists but staff.status != active
 *   ghost-teacher-sa  a matrix assignment points at a non-existent staffId
 *
 * ── INVOCATION ─────────────────────────────────────────────────────────────
 *   All schools/sessions:
 *     php index.php timetables_healthcheck run
 *   One school (all its sessions):
 *     SCHOOL_ID=SCH_xxx php index.php timetables_healthcheck run
 *   One school + session:
 *     SCHOOL_ID=SCH_xxx SESSION_YEAR=2026-27 php index.php timetables_healthcheck run
 *   Add VERBOSE=1 to list every offending period (else per-school summary only).
 */
class Timetables_healthcheck extends CI_Controller
{
    private string $schoolFilter  = '';
    private string $sessionFilter = '';
    private bool   $verbose       = false;

    private const CAP = 10000;   // per-collection fetch cap (warns if hit)

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Timetables_healthcheck is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->schoolFilter  = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionFilter = (string) (getenv('SESSION_YEAR') ?: '');
        $this->verbose       = (string) (getenv('VERBOSE')      ?: '') === '1';
    }

    private function nk(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    private function fetchAll(string $collection, array $conditions): array
    {
        $rows = $this->firebase->firestoreQuery($collection, $conditions, null, 'ASC', self::CAP);
        if (count($rows) >= self::CAP) {
            echo "  ⚠ WARNING: {$collection} hit the " . self::CAP . "-row cap — results may be truncated.\n";
        }
        return $rows;
    }

    public function run(): void
    {
        echo "=== Timetable ⇄ Subject-matrix ⇄ Staff — SYSTEM HEALTH CHECK ===\n";
        echo "Scope: " . ($this->schoolFilter !== '' ? "school={$this->schoolFilter}" : "ALL schools")
            . ($this->sessionFilter !== '' ? " session={$this->sessionFilter}" : " ALL sessions") . "\n";
        echo str_repeat('=', 76) . "\n\n";

        $saCond = [];
        $ttCond = [];
        $stCond = [];
        if ($this->schoolFilter !== '') {
            $saCond[] = ['schoolId', '==', $this->schoolFilter];
            $ttCond[] = ['schoolId', '==', $this->schoolFilter];
            $stCond[] = ['schoolId', '==', $this->schoolFilter];
        }

        echo "Loading collections…\n";
        $saRows = $this->fetchAll('subjectAssignments', $saCond);
        $ttRows = $this->fetchAll('timetables', $ttCond);
        $stRows = $this->fetchAll('staff', $stCond);
        echo "  subjectAssignments={" . count($saRows) . "}  timetables={" . count($ttRows)
            . "}  staff={" . count($stRows) . "}\n\n";

        // ── Staff sets per school: staffId => status ─────────────────────
        $staffBySchool = [];   // school => [staffId => status]
        $staffName     = [];   // school => [staffId => name]
        foreach ($stRows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sch = (string)($d['schoolId'] ?? '');
            $sid = (string)($d['staffId'] ?? '');
            if ($sid === '') {
                // derive from entity doc id: {schoolId}_{staffId}
                $docId = (string)($r['id'] ?? '');
                if ($sch !== '' && strpos($docId, $sch . '_') === 0) $sid = substr($docId, strlen($sch) + 1);
            }
            if ($sch === '' || $sid === '') continue;
            $staffBySchool[$sch][$sid] = (string)($d['status'] ?? 'unknown');
            $nm = trim((string)($d['name'] ?? (($d['firstName'] ?? '') . ' ' . ($d['lastName'] ?? ''))));
            $staffName[$sch][$sid] = $nm;
        }

        // ── Authority maps per school|session ────────────────────────────
        //   sectioned[key][ class|section|subjName ] = [tid, tname, code]
        //   classwide[key][ class|subjName ]         = [tid, tname, code]
        //   subjKnown[key][ class|section|subjName ] = true (subject exists in matrix, teacher or not)
        $sectioned = [];  $classwide = [];  $subjKnown = [];
        $saGhost = [];    // "school|session|class|section|subj tid" — assignment → missing staff
        foreach ($saRows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if (!empty($d['archived'])) continue;
            $sch = (string)($d['schoolId'] ?? '');
            $ses = (string)($d['session'] ?? '');
            if ($sch === '' || $ses === '') continue;
            if ($this->sessionFilter !== '' && $ses !== $this->sessionFilter) continue;
            $key = "{$sch}|{$ses}";
            $cls = $this->nk((string)($d['className'] ?? ''));
            $sec = (string)($d['section'] ?? '');
            $subN= $this->nk((string)($d['subjectName'] ?? ($d['subjectCode'] ?? '')));
            $code= (string)($d['subjectCode'] ?? '');
            if ($cls === '' || $subN === '') continue;
            $secK = ($sec === '' || $sec === '_ALL_') ? '' : $this->nk($sec);
            // subject-known registry (even teacher-less rows count)
            if ($secK === '') {
                $subjKnown[$key]["{$cls}|*|{$subN}"] = true;   // class-wide
            } else {
                $subjKnown[$key]["{$cls}|{$secK}|{$subN}"] = true;
            }
            $tid = trim((string)($d['teacherId'] ?? ''));
            if ($tid === '') continue;
            $tname = trim((string)($d['teacherName'] ?? ''));
            if ($secK === '') $classwide[$key]["{$cls}|{$subN}"] = [$tid, $tname, $code];
            else              $sectioned[$key]["{$cls}|{$secK}|{$subN}"] = [$tid, $tname, $code];
            // matrix → missing staff?
            if (isset($staffBySchool[$sch]) && !isset($staffBySchool[$sch][$tid])) {
                $saGhost[$key][] = "{$d['className']}/{$sec} {$subN} → {$tid} (not in staff)";
            }
        }

        // ── Walk timetables, categorize every class period ───────────────
        $stats = [];   // key => [dimension => count]
        $detail = [];  // key => [dimension => [lines]]
        $affTeacher = []; // key => [tid => count]  (teachers gaining slots via id-drift/fillable)
        $docCount = [];
        $ttSessions = [];

        foreach ($ttRows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sch = (string)($d['schoolId'] ?? '');
            $ses = (string)($d['session'] ?? '');
            if ($sch === '' || $ses === '') continue;
            if ($this->sessionFilter !== '' && $ses !== $this->sessionFilter) continue;
            $key = "{$sch}|{$ses}";
            $ttSessions[$key] = true;
            $docCount[$key] = ($docCount[$key] ?? 0) + 1;

            $periods = $d['periods'] ?? null;
            if (!is_array($periods)) continue;
            $clsK = $this->nk((string)($d['className'] ?? ''));
            $secK = $this->nk((string)($d['section'] ?? ''));
            $day  = (string)($d['day'] ?? '');
            $label= (string)($d['className'] ?? '') . '/' . (string)($d['section'] ?? '') . " {$day}";

            foreach ($periods as $idx => $p) {
                if (!is_array($p)) continue;
                $type = (string)($p['type'] ?? 'class');
                $subj = trim((string)($p['subject'] ?? ''));
                if ($type !== 'class' || $subj === '') continue;
                $subN = $this->nk($subj);
                $pn   = (string)($p['periodNumber'] ?? ($idx + 1));
                $curTid  = trim((string)($p['teacherId'] ?? ''));
                $curName = trim((string)($p['teacher'] ?? ''));

                $auth = $sectioned[$key]["{$clsK}|{$secK}|{$subN}"]
                    ?? $classwide[$key]["{$clsK}|{$subN}"]
                    ?? null;
                $known = isset($subjKnown[$key]["{$clsK}|{$secK}|{$subN}"])
                    || isset($subjKnown[$key]["{$clsK}|*|{$subN}"]);

                $bump = function (string $dim, string $line) use (&$stats, &$detail, $key) {
                    $stats[$key][$dim] = ($stats[$key][$dim] ?? 0) + 1;
                    if ($this->verbose) $detail[$key][$dim][] = $line;
                };

                if ($auth !== null) {
                    [$aTid, $aName] = $auth;
                    if ($curTid === '') {
                        $bump('fillable-blank', "{$label} P{$pn} {$subj} → {$aTid} ({$aName})");
                        $affTeacher[$key][$aTid] = ($affTeacher[$key][$aTid] ?? 0) + 1;
                    } elseif ($curTid !== $aTid) {
                        $bump('id-drift', "{$label} P{$pn} {$subj}: {$curTid} ({$curName}) → {$aTid} ({$aName})");
                        $affTeacher[$key][$aTid] = ($affTeacher[$key][$aTid] ?? 0) + 1;
                    } elseif ($aName !== '' && $this->nk($curName) !== $this->nk($aName)) {
                        $bump('name-drift', "{$label} P{$pn} {$subj}: \"{$curName}\" → \"{$aName}\"");
                    }
                    // else fully correct
                } else {
                    if ($curTid === '') {
                        if ($known) $bump('unfillable-blank', "{$label} P{$pn} {$subj} (subject in matrix, no teacher)");
                        else        $bump('subject-orphan', "{$label} P{$pn} {$subj} (subject not in matrix)");
                    } else {
                        // has a teacher but matrix doesn't know this subject here
                        if (!$known) $bump('subject-orphan', "{$label} P{$pn} {$subj} → {$curTid} (subject not in matrix)");
                        // staff existence check for the stamped teacher
                        if (isset($staffBySchool[$sch]) && !isset($staffBySchool[$sch][$curTid])) {
                            $bump('ghost-teacher-tt', "{$label} P{$pn} {$subj} → {$curTid} (not in staff)");
                        }
                    }
                }
                // Independent staff checks for any stamped teacher
                if ($curTid !== '' && isset($staffBySchool[$sch])) {
                    if (!isset($staffBySchool[$sch][$curTid])) {
                        // counted above only in the no-auth branch; count once here globally
                    } elseif (strtolower($staffBySchool[$sch][$curTid]) !== 'active'
                              && $staffBySchool[$sch][$curTid] !== 'unknown') {
                        $bump('inactive-teacher-tt', "{$label} P{$pn} {$subj} → {$curTid} (status={$staffBySchool[$sch][$curTid]})");
                    }
                }
            }
        }

        // ── Report ───────────────────────────────────────────────────────
        $dims = ['id-drift','name-drift','fillable-blank','unfillable-blank',
                 'subject-orphan','ghost-teacher-tt','inactive-teacher-tt'];
        $grand = array_fill_keys($dims, 0);
        $grandSaGhost = 0;

        ksort($ttSessions);
        foreach (array_keys($ttSessions) as $key) {
            [$sch, $ses] = explode('|', $key);
            $s = $stats[$key] ?? [];
            $sum = array_sum(array_intersect_key($s, array_flip($dims)));
            $saG = count($saGhost[$key] ?? []);
            $grandSaGhost += $saG;
            $flag = ($sum === 0 && $saG === 0) ? '✅' : '⚠';
            echo "{$flag} {$sch}  session {$ses}   ({$docCount[$key]} timetable docs)\n";
            foreach ($dims as $dim) {
                $c = $s[$dim] ?? 0; $grand[$dim] += $c;
                if ($c > 0) printf("      %-20s %d\n", $dim, $c);
            }
            if ($saG > 0) printf("      %-20s %d\n", 'matrix→ghost-staff', $saG);
            if ($sum === 0 && $saG === 0) echo "      (clean)\n";

            // affected teachers (who GAINS periods once reconciled)
            if (!empty($affTeacher[$key])) {
                arsort($affTeacher[$key]);
                $parts = [];
                foreach ($affTeacher[$key] as $tid => $c) {
                    $nm = $staffName[$sch][$tid] ?? '';
                    $parts[] = "{$tid}" . ($nm !== '' ? "({$nm})" : '') . ":{$c}";
                }
                echo "      → periods that will (re)attach to: " . implode('  ', $parts) . "\n";
            }

            if ($this->verbose && !empty($detail[$key])) {
                foreach ($dims as $dim) {
                    foreach (($detail[$key][$dim] ?? []) as $ln) echo "         [{$dim}] {$ln}\n";
                }
                foreach (($saGhost[$key] ?? []) as $ln) echo "         [matrix→ghost] {$ln}\n";
            }
            echo "\n";
        }

        // ── Grand totals ─────────────────────────────────────────────────
        echo str_repeat('-', 76) . "\n";
        echo "GRAND TOTALS across " . count($ttSessions) . " school-session scope(s):\n";
        $totalDefects = 0;
        foreach ($dims as $dim) {
            printf("  %-20s %d\n", $dim, $grand[$dim]);
            if (in_array($dim, ['id-drift','fillable-blank','name-drift'], true)) $totalDefects += $grand[$dim];
        }
        printf("  %-20s %d\n", 'matrix→ghost-staff', $grandSaGhost);
        echo "\n";
        echo "Auto-fixable by Timetables_reconcile (id-drift + fillable-blank + name-drift): "
            . ($grand['id-drift'] + $grand['fillable-blank'] + $grand['name-drift']) . " periods\n";
        echo "Needs human attention (subject-orphan / ghost / inactive / unfillable): "
            . ($grand['subject-orphan'] + $grand['ghost-teacher-tt'] + $grand['inactive-teacher-tt']
               + $grand['unfillable-blank'] + $grandSaGhost) . " periods/rows\n";
        echo "\nRun with VERBOSE=1 to list every offending period.\n";
        echo "=== End health check ===\n";
    }
}
