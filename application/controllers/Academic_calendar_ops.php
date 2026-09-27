<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Calendar_service.php';

/**
 * Academic_calendar_ops — PHASE D1 (copy/roll-forward) + D2 (bulk import).
 *
 * Extends the CANONICAL Academic Calendar (`calendarEvents` via Calendar_service)
 * — no second holiday master. Source events are READ directly (schoolWhere);
 * new events are WRITTEN through Calendar_service inited to the TARGET session,
 * so the canonical writer + audit + Holiday_service cache invalidation apply.
 *
 * Safety: tenant is server-derived (schoolWhere / school_id); idempotent
 * (skips an event already present in the target by title+date); preview before
 * commit; year-shifted dates flagged for admin review (lunar festivals move).
 */
class Academic_calendar_ops extends MY_Controller
{
    const COL = 'calendarEvents';

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('rbac');
    }

    public function index()
    {
        require_permission('Academic', 'view');
        $this->load->view('include/header');
        $this->load->view('academic/calendar_ops');
        $this->load->view('include/footer');
    }

    // ── helpers ─────────────────────────────────────────────────────────────
    private function _valid_session(string $s): bool { return (bool) preg_match('/^\d{4}-\d{2}$/', $s); }

    private function _shift_date(string $d, int $years): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return $d;
        $y = (int) $m[1] + $years; $mo = (int) $m[2]; $da = (int) $m[3];
        $dim = (int) cal_days_in_month(CAL_GREGORIAN, $mo, $y);
        if ($da > $dim) $da = $dim;   // clamp Feb-29 etc.
        return sprintf('%04d-%02d-%02d', $y, $mo, $da);
    }

    /** Read all events for a session (direct canonical read). */
    private function _events_for(string $session): array
    {
        $out = [];
        foreach ((array) $this->fs->schoolWhere(self::COL, [['session', '==', $session]]) as $entry) {
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($d)) continue;
            $out[] = [
                'id'        => (string) ($entry['id'] ?? $d['id'] ?? ''),
                'title'     => (string) ($d['title'] ?? ''),
                'type'      => (string) ($d['type'] ?? 'event'),
                'startDate' => (string) ($d['startDate'] ?? $d['start_date'] ?? ''),
                'endDate'   => (string) ($d['endDate'] ?? $d['end_date'] ?? ''),
                'description'=> (string) ($d['description'] ?? ''),
                'visibleTo' => is_array($d['visibleTo'] ?? null) ? $d['visibleTo'] : [],
                'appliesTo' => is_array($d['appliesTo'] ?? null) ? $d['appliesTo'] : [],
            ];
        }
        return $out;
    }

    /** dedupe key: title|startDate (case-insensitive title). */
    private function _key(string $title, string $startDate): string { return strtolower(trim($title)) . '|' . $startDate; }

    // ── D1: COPY / ROLL-FORWARD ─────────────────────────────────────────────

    /** POST source, target → dry-run plan (no writes). */
    public function copy_preview()
    {
        require_permission('Academic', 'view');
        header('Content-Type: application/json');
        $src = trim((string) $this->input->post('source'));
        $tgt = trim((string) $this->input->post('target'));
        if (!$this->_valid_session($src) || !$this->_valid_session($tgt)) return $this->json_error('Valid source and target sessions (YYYY-YY) are required.');
        if ($src === $tgt) return $this->json_error('Source and target sessions must differ.');

        $shift = ((int) substr($tgt, 0, 4)) - ((int) substr($src, 0, 4));
        $srcEvents = $this->_events_for($src);
        $tgtKeys = [];
        foreach ($this->_events_for($tgt) as $e) $tgtKeys[$this->_key($e['title'], $e['startDate'])] = true;

        $plan = ['copy' => [], 'skip' => []];
        foreach ($srcEvents as $e) {
            if ($e['title'] === '' || $e['startDate'] === '') continue;
            $ns = $this->_shift_date($e['startDate'], $shift);
            $ne = $e['endDate'] !== '' ? $this->_shift_date($e['endDate'], $shift) : $ns;
            $row = ['title' => $e['title'], 'type' => $e['type'], 'from' => $e['startDate'], 'to' => $ns];
            if (isset($tgtKeys[$this->_key($e['title'], $ns)])) $plan['skip'][] = $row + ['reason' => 'already in target'];
            else $plan['copy'][] = $row;
        }
        return $this->json_success([
            'source' => $src, 'target' => $tgt, 'shiftYears' => $shift,
            'toCopy' => count($plan['copy']), 'toSkip' => count($plan['skip']),
            'plan' => $plan,
            'note' => 'Dated holidays (esp. lunar festivals) move year-to-year — review the shifted dates after copying.',
        ]);
    }

    /** POST source, target → create the shifted events in target (idempotent). */
    public function copy_commit()
    {
        require_permission('Academic', 'manage');
        header('Content-Type: application/json');
        $src = trim((string) $this->input->post('source'));
        $tgt = trim((string) $this->input->post('target'));
        if (!$this->_valid_session($src) || !$this->_valid_session($tgt)) return $this->json_error('Valid source and target sessions are required.');
        if ($src === $tgt) return $this->json_error('Source and target sessions must differ.');

        $shift = ((int) substr($tgt, 0, 4)) - ((int) substr($src, 0, 4));
        $srcEvents = $this->_events_for($src);
        $tgtKeys = [];
        foreach ($this->_events_for($tgt) as $e) $tgtKeys[$this->_key($e['title'], $e['startDate'])] = true;

        // canonical writer inited to the TARGET session
        $svc = new Calendar_service();
        $svc->init($this->firebase, $this->fs, (string) $this->school_id, $tgt, (string) ($this->admin_id ?? ''), (string) ($this->admin_name ?? ''), null);

        $copied = 0; $skipped = 0; $failed = 0; $errs = [];
        foreach ($srcEvents as $e) {
            if ($e['title'] === '' || $e['startDate'] === '') { $skipped++; continue; }
            $ns = $this->_shift_date($e['startDate'], $shift);
            $ne = $e['endDate'] !== '' ? $this->_shift_date($e['endDate'], $shift) : $ns;
            if (isset($tgtKeys[$this->_key($e['title'], $ns)])) { $skipped++; continue; }  // idempotent
            try {
                $svc->saveEvent('', $e['title'], $e['type'], $ns, $ne, $e['description'], $e['visibleTo'] ?: null, $e['appliesTo'] ?: null, null, null);
                $tgtKeys[$this->_key($e['title'], $ns)] = true;  // guard within this run too
                $copied++;
            } catch (\Throwable $ex) { $failed++; $errs[] = $e['title'] . ': ' . $ex->getMessage(); }
        }
        $this->_flush_holiday_cache($tgt);
        log_message('info', "calendar copy {$src}->{$tgt} by {$this->admin_id}: copied={$copied} skipped={$skipped} failed={$failed}");
        return $this->json_success(['source'=>$src,'target'=>$tgt,'copied'=>$copied,'skipped'=>$skipped,'failed'=>$failed,'errors'=>array_slice($errs,0,10)]);
    }

    // ── D2: BULK IMPORT ─────────────────────────────────────────────────────

    /** POST csv (raw text), target session → parse+validate, no writes. */
    public function import_preview()
    {
        require_permission('Academic', 'view');
        header('Content-Type: application/json');
        $tgt = trim((string) $this->input->post('target')) ?: (string) $this->session_year;
        if (!$this->_valid_session($tgt)) return $this->json_error('Valid target session required.');
        $rows = $this->_parse_csv((string) $this->input->post('csv'));
        [$valid, $invalid] = $this->_validate_rows($rows, $tgt);
        return $this->json_success(['target'=>$tgt,'valid'=>count($valid),'invalid'=>count($invalid),'validRows'=>$valid,'invalidRows'=>$invalid]);
    }

    /** POST csv, target → validate + create valid rows (idempotent). */
    public function import_commit()
    {
        require_permission('Academic', 'manage');
        header('Content-Type: application/json');
        $tgt = trim((string) $this->input->post('target')) ?: (string) $this->session_year;
        if (!$this->_valid_session($tgt)) return $this->json_error('Valid target session required.');
        $rows = $this->_parse_csv((string) $this->input->post('csv'));
        [$valid, $invalid] = $this->_validate_rows($rows, $tgt);
        if (empty($valid)) return $this->json_error('No valid rows to import.' . (count($invalid) ? ' ' . count($invalid) . ' invalid.' : ''), 422);

        $tgtKeys = [];
        foreach ($this->_events_for($tgt) as $e) $tgtKeys[$this->_key($e['title'], $e['startDate'])] = true;

        $svc = new Calendar_service();
        $svc->init($this->firebase, $this->fs, (string) $this->school_id, $tgt, (string) ($this->admin_id ?? ''), (string) ($this->admin_name ?? ''), null);

        $created = 0; $skipped = 0; $failed = 0; $errs = [];
        foreach ($valid as $r) {
            if (isset($tgtKeys[$this->_key($r['title'], $r['startDate'])])) { $skipped++; continue; }
            try {
                $svc->saveEvent('', $r['title'], $r['type'], $r['startDate'], $r['endDate'], $r['description'] ?? '', ['admin','teacher','parent'], null, null, null);
                $tgtKeys[$this->_key($r['title'], $r['startDate'])] = true;
                $created++;
            } catch (\Throwable $ex) { $failed++; $errs[] = $r['title'] . ': ' . $ex->getMessage(); }
        }
        $this->_flush_holiday_cache($tgt);
        return $this->json_success(['target'=>$tgt,'created'=>$created,'skipped'=>$skipped,'failed'=>$failed,'invalid'=>count($invalid),'errors'=>array_slice($errs,0,10)]);
    }

    /** CSV: header row title,type,startDate[,endDate,description]. Tolerant. */
    private function _parse_csv(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') return [];
        $lines = preg_split('/\r\n|\r|\n/', $csv);
        $rows = []; $header = null;
        foreach ($lines as $ln) {
            if (trim($ln) === '') continue;
            $cells = str_getcsv($ln);
            if ($header === null) { $header = array_map(fn($h) => strtolower(trim($h)), $cells); continue; }
            $row = [];
            foreach ($header as $i => $h) $row[$h] = isset($cells[$i]) ? trim($cells[$i]) : '';
            $rows[] = $row;
        }
        return $rows;
    }

    private function _validate_rows(array $rows, string $tgt): array
    {
        $validTypes = ['holiday','exam','meeting','event','activity','special_working_day','half_day'];
        $valid = []; $invalid = []; $seen = [];
        foreach ($rows as $n => $r) {
            $title = trim((string) ($r['title'] ?? ''));
            $type  = strtolower(trim((string) ($r['type'] ?? 'holiday')));
            $sd    = trim((string) ($r['startdate'] ?? $r['start_date'] ?? $r['date'] ?? ''));
            $ed    = trim((string) ($r['enddate'] ?? $r['end_date'] ?? '')) ?: $sd;
            $desc  = trim((string) ($r['description'] ?? ''));
            $err = [];
            if ($title === '') $err[] = 'title required';
            if (!in_array($type, $validTypes, true)) $err[] = "invalid type '$type'";
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || !strtotime($sd)) $err[] = 'bad startDate';
            if ($ed !== $sd && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed) || $ed < $sd)) $err[] = 'bad endDate';
            $k = strtolower($title) . '|' . $sd;
            if (isset($seen[$k])) $err[] = 'duplicate row';
            $seen[$k] = true;
            if ($err) $invalid[] = ['row' => $n + 2, 'title' => $title, 'errors' => $err];
            else $valid[] = ['title'=>$title,'type'=>$type,'startDate'=>$sd,'endDate'=>$ed,'description'=>$desc];
        }
        return [$valid, $invalid];
    }

    private function _flush_holiday_cache(string $session): void
    {
        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init($this->fs, (string) $this->school_id, $session);
            $this->holiday_service->flush();
        } catch (\Throwable $e) { log_message('error', 'calendar_ops flush: ' . $e->getMessage()); }
    }
}
