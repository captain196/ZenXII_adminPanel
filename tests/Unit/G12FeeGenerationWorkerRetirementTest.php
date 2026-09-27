<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!defined('FCPATH'))   define('FCPATH', __DIR__ . '/../../');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * G12 — the fee-generation Cloud Function must stay RETIRED (unwired).
 *
 * `functions/fee_generation_worker.js::processFeeGenerationJob` triggered on
 * every `fee_generation_jobs/{jobId}` create and raced `Fees::_processGenerationJob`,
 * the PHP synchronous generator the same request already invoked. The worker:
 *
 *   • hardcodes `discount_amount: 0` AND `discountAmount: 0` — no discount,
 *     concession, scholarship or entitlement of any kind is ever applied;
 *   • uses a DETERMINISTIC doc id (`{schoolId}_{session}_{studentId}_{periodKey}_{feeHeadSlug}`)
 *     that cannot collide with the PHP path's random 20-hex id.
 *
 * Because the ids cannot collide, the worker can never overwrite — only ADD a
 * second, full-gross demand for the same conceptual charge. For this programme
 * that is fatal: an entitlement student caught in a bulk generation would get an
 * undiscounted duplicate, silently defeating SE-V2/DEC-009 and breaking the P4
 * invariant (`discountAmount == Σ allocations`) for a demand the ledger never saw.
 *
 * It was retired in code on 2026-08-08 by commenting out its require/export in
 * `functions/index.js`. This test keeps it that way.
 *
 * ── AND THE PART CODE CANNOT FIX ──────────────────────────────────────────
 * **The retirement was never DEPLOYED.** Verified 2026-08-19 via
 * `firebase functions:list --project graderadmin`: `processFeeGenerationJob`
 * is LIVE (v2, `google.cloud.firestore.document.v1.created`, us-central1).
 * `firebase functions:log` shows no recent invocations, and no CF-shaped demand
 * ids exist in the estate (0 of 1,356) — so the race is LATENT, not firing.
 * Latent is not safe: `Fees.php` still creates the job documents that trigger it.
 *
 * Deploying the retirement is a production deployment (and ships every other
 * function), so it is gated. This test guards the code half; B-012 tracks the
 * deployment half.
 */
class G12FeeGenerationWorkerRetirementTest extends TestCase
{
    private function functionsPath(string $file): string
    {
        return FCPATH . 'functions/' . $file;
    }

    private function indexSrc(): string
    {
        return (string) file_get_contents($this->functionsPath('index.js'));
    }

    /** Strip JS comments so a retirement NOTE is never mistaken for live code. */
    private function indexCode(): string
    {
        $src = $this->indexSrc();
        $src = preg_replace('#/\*.*?\*/#s', ' ', $src);          // block comments
        $src = preg_replace('#^\s*//.*$#m', ' ', $src);          // whole-line comments
        return (string) $src;
    }

    // ══ THE GUARD ═══════════════════════════════════════════════════════

    /** The worker must not be require()d from live code. */
    public function test_the_worker_is_not_required_from_index(): void
    {
        $code = $this->indexCode();
        $this->assertStringNotContainsString('require("./fee_generation_worker")', $code,
            'G12: re-requiring this worker re-opens the duplicate-demand race');
        $this->assertStringNotContainsString("require('./fee_generation_worker')", $code);
    }

    /** And must not be exported under any name. */
    public function test_the_worker_is_not_exported(): void
    {
        $code = $this->indexCode();
        $this->assertDoesNotMatchRegularExpression(
            '/exports\.\w+\s*=\s*feeWorker/i', $code,
            'G12: exporting the worker re-arms the trigger');
        $this->assertStringNotContainsString('exports.processFeeGenerationJob', $code,
            'the trigger must not be re-exported');
    }

    /** The retirement rationale must survive — it is why the file is still on disk. */
    public function test_the_retirement_note_survives(): void
    {
        $src = $this->indexSrc();
        $this->assertStringContainsString('processFeeGenerationJob', $src,
            'the note explaining WHY it is unwired must remain');
        $this->assertStringContainsString('fee_generation_worker.js', $src);
    }

    /** The file itself stays on disk, unwired, with its own warning. */
    public function test_the_worker_file_still_carries_its_warning(): void
    {
        $worker = (string) file_get_contents($this->functionsPath('fee_generation_worker.js'));
        $this->assertStringContainsString('RETIRED', $worker);
        $this->assertStringContainsString('G12', $worker);
        $this->assertStringContainsString('Do not re-require/re-export', $worker,
            'the warning must stay attached to the code it warns about');
    }

    // ══ WHY IT IS DANGEROUS — pinned so the reason cannot be lost ═══════

    /**
     * The two properties that make this worker unsafe. If someone ever "fixes"
     * the worker, these assertions failing is the signal to re-read G12 — not
     * to quietly delete the test.
     */
    public function test_the_worker_is_still_discount_blind(): void
    {
        $worker = (string) file_get_contents($this->functionsPath('fee_generation_worker.js'));
        $this->assertMatchesRegularExpression('/discount_amount:\s*0/', $worker,
            'unchanged: the worker applies no discount of any kind');
        $this->assertMatchesRegularExpression('/discountAmount:\s*0/', $worker);
        foreach (['entitlement', 'Entitlement'] as $needle) {
            $this->assertStringNotContainsString($needle, $worker,
                'the worker has no entitlement awareness whatsoever');
        }
    }

    // ══ THE TRIGGER SOURCE IS STILL LIVE ════════════════════════════════

    /**
     * PHP still creates the job documents this trigger fires on, which is why
     * the deployed-but-unwired function remains a live hazard rather than a
     * dormant file. Pinned so the dependency stays visible.
     */
    public function test_php_still_creates_the_trigger_documents(): void
    {
        $fees = (string) file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString("firestoreSet('fee_generation_jobs'", $fees,
            'if this ever stops being true, G12 loses its trigger and the '
            . 'deployment urgency changes — re-assess rather than assume');
    }

    // ══ THE CENSUS: NO OTHER CLOUD FUNCTION WRITES DEMANDS ══════════════

    /**
     * My original P5 census enumerated only PHP writers and MISSED Cloud
     * Functions entirely. This closes that gap permanently: any NEW function
     * that writes `feeDemands` and is wired into `index.js` fails here.
     */
    public function test_no_wired_cloud_function_writes_demands(): void
    {
        $code = $this->indexCode();
        $offenders = [];
        foreach ((array) glob(FCPATH . 'functions/*.js') as $f) {
            $base = basename($f, '.js');
            if ($base === 'index') continue;
            if (strpos((string) file_get_contents($f), 'feeDemands') === false) continue;
            // Does live (comment-stripped) index code pull it in?
            if (strpos($code, './' . $base) !== false) $offenders[] = $base;
        }
        $this->assertSame([], $offenders,
            'a wired Cloud Function writing feeDemands bypasses the canonical '
            . 'entitlement-aware pipeline: ' . implode(', ', $offenders));
    }
}
