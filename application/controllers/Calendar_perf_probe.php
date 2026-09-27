<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Calendar_perf_probe — READ-ONLY CLI timing probe for the Academic Calendar read.
 *
 * WHY. `get_calendar_events` was observed pending at ~1.1 minutes in the browser.
 * Reading the source suggests two candidates — a full `sessionWhere` scan filtered
 * in PHP, and PHP's file-based session lock serialising 40+ concurrent AJAX calls
 * from one page — and source reading cannot tell which dominates. This measures
 * the SERVER side so the two can be separated by evidence.
 *
 * STRICT GUARANTEES
 *   • Read-only. Zero set/update/delete of any kind.
 *   • CLI only — refuses a web request.
 *   • Touches no other tenant than the one named on the command line.
 *   • Mutates nothing, migrates nothing, caches nothing new.
 *
 * USAGE
 *   SCHOOL_ID=SCH_XXXXXXXXXX SESSION_YEAR=2026-27 \
 *     php index.php calendar_perf_probe run
 */
class Calendar_perf_probe extends CI_Controller
{
    public function run()
    {
        if (!$this->input->is_cli_request()) { show_404(); return; }

        $schoolId = getenv('SCHOOL_ID') ?: '';
        $session  = getenv('SESSION_YEAR') ?: '';
        if ($schoolId === '' || $session === '') {
            echo "SCHOOL_ID and SESSION_YEAR are required.\n";
            return;
        }

        echo "=== Calendar read timing — {$schoolId} / {$session} ===\n\n";

        $t0 = microtime(true);
        $this->load->library('firestore_service');
        $this->firestore_service->init($schoolId);
        $tInit = microtime(true) - $t0;
        printf("  firestore_service init         %7.1f ms\n", $tInit * 1000);

        // 1. The exact read Calendar_service::getEvents() performs.
        $t = microtime(true);
        $rows = [];
        try {
            $rows = $this->firestore_service->sessionWhere('calendarEvents', []);
        } catch (\Throwable $e) {
            echo "  READ FAILED: " . $e->getMessage() . "\n";
            return;
        }
        $tRead = microtime(true) - $t;
        $n = is_array($rows) ? count($rows) : 0;
        printf("  sessionWhere(calendarEvents)   %7.1f ms   (%d documents)\n", $tRead * 1000, $n);

        // 2. The PHP-side month filter, on the same data.
        $t = microtime(true);
        $month = date('Y-m');
        $kept = 0;
        foreach ((array) $rows as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $s = $d['startDate'] ?? $d['start_date'] ?? '';
            $e = $d['endDate']   ?? $d['end_date']   ?? $s;
            $ms = $month . '-01';
            $me = date('Y-m-t', strtotime($ms));
            if ($e < $ms || $s > $me) continue;
            $kept++;
        }
        $tFilter = microtime(true) - $t;
        printf("  PHP month filter               %7.1f ms   (%d of %d kept)\n",
            $tFilter * 1000, $kept, $n);

        // 3. A second identical read — shows whether anything is cached.
        $t = microtime(true);
        try { $this->firestore_service->sessionWhere('calendarEvents', []); } catch (\Throwable $e) {}
        $tRead2 = microtime(true) - $t;
        printf("  same read, second time         %7.1f ms   %s\n", $tRead2 * 1000,
            $tRead2 < $tRead / 2 ? '(cached)' : '(NOT cached — every call pays full cost)');

        $total = $tInit + $tRead + $tFilter;
        printf("\n  server-side total for ONE call %7.1f ms\n", $total * 1000);

        // 4. CAL-PERF-1 — the real path, through Calendar_service, which is what
        //    the browser actually hits. Two consecutive month loads, exactly as
        //    a prev/next click pair does.
        echo "\n  --- Calendar_service::getEvents (the real endpoint path) ---\n";
        require_once APPPATH . 'libraries/Calendar_service.php';
        $this->load->library('firebase');
        $svc = (new Calendar_service())->init(
            $this->firebase, $this->firestore_service, $schoolId, $session, 'probe', 'probe');

        // Clear the file tier so the first call is honestly cold.
        $dir = APPPATH . 'cache/calendar_events';
        foreach (glob($dir . '/*.json') ?: [] as $f) @unlink($f);

        $t = microtime(true);
        $svc->getEvents(date('Y-m'));
        $tCold = microtime(true) - $t;

        // A SEPARATE instance, so the per-request memo cannot hide a cold read —
        // this models the next HTTP request, not the same one.
        $svc2 = (new Calendar_service())->init(
            $this->firebase, $this->firestore_service, $schoolId, $session, 'probe', 'probe');
        $t = microtime(true);
        $svc2->getEvents(date('Y-m', strtotime('+1 month')));
        $tWarm = microtime(true) - $t;

        printf("  first month load (cold)        %7.1f ms\n", $tCold * 1000);
        printf("  next month load (new instance) %7.1f ms   %s\n", $tWarm * 1000,
            $tWarm < $tCold / 2 ? '<-- cache hit' : '<-- NO cache benefit');
        if ($tCold > 0) {
            printf("  saved per subsequent month     %7.1f ms (%.0f%%)\n",
                ($tCold - $tWarm) * 1000, (1 - $tWarm / $tCold) * 100);
        }

        echo "\n  Interpretation:\n";
        if ($total < 2.0) {
            echo "    The server-side read is NOT the 1.1-minute cost. That points at\n"
               . "    request serialisation (PHP file-session locking across the page's\n"
               . "    concurrent AJAX calls) rather than this query.\n";
        } else {
            echo "    The server-side read is itself slow and worth optimising.\n";
        }
    }
}
