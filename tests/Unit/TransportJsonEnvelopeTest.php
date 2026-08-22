<?php
/**
 * ROLL-1 — a Transport JSON payload must not redefine the envelope's `status`.
 *
 * THE DEFECT. `MY_Controller::json_success()` does
 *
 *     array_merge(['status' => 'success'], $data)
 *
 * so `$data` WINS. `Transport_rollover::start()` sent the rollover state under
 * the key `status`, which therefore overwrote the envelope with 'complete' or
 * 'in_progress'. Every client in this codebase opens with
 *
 *     if (r.status !== 'success') { …show failure…; return; }
 *
 * so EVERY rollover response took the failure branch: a completed rollover
 * painted a red "Failed", and the auto-continue branch that resumes the
 * remaining students was unreachable. A rollover that hit the wall-clock ceiling
 * stopped permanently, with the admin told only "Retry".
 *
 * `status` means transport-level success/error everywhere here. This test pins
 * that no Transport endpoint reuses the key for something else.
 */

use PHPUnit\Framework\TestCase;

final class TransportJsonEnvelopeTest extends TestCase
{
    private const CONTROLLERS = __DIR__ . '/../../application/controllers';
    private const ROLLOVER    = self::CONTROLLERS . '/Transport_rollover.php';
    private const VIEW        = __DIR__ . '/../../application/views/transport/index.php';

    /** @return string[] */
    private function transportControllers(): array
    {
        $f = glob(self::CONTROLLERS . '/Transport*.php');
        $this->assertNotEmpty($f, 'no Transport controllers found');
        return $f;
    }

    public function test_json_success_still_lets_the_payload_win(): void
    {
        // The whole defect depends on this merge order. If it is ever reversed,
        // the collision stops mattering and this suite's premise changes — so
        // assert it rather than assume it.
        $mc = (string) file_get_contents(__DIR__ . '/../../application/core/MY_Controller.php');
        $this->assertMatchesRegularExpression(
            "/array_merge\(\s*\['status'\s*=>\s*'success'\]\s*,\s*\\\$data\s*\)/", $mc,
            'json_success() merge order changed — revisit the envelope-collision rule'
        );
    }

    public function test_no_transport_endpoint_redefines_status_in_its_payload(): void
    {
        $offenders = [];
        foreach ($this->transportControllers() as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match_all('/json_success\(\s*\[(.*?)\]\s*\)/s', $src, $m)) { continue; }
            foreach ($m[1] as $body) {
                if (preg_match('/[\'"]status[\'"]\s*=>/', $body)) {
                    $offenders[] = basename($file) . ': ' . trim(substr(preg_replace('/\s+/', ' ', $body), 0, 80));
                }
            }
        }
        $this->assertSame([], $offenders,
            "a payload key named 'status' overwrites the envelope, so every client's "
            . "`if (r.status !== 'success')` guard fires on success:\n  " . implode("\n  ", $offenders));
    }

    public function test_rollover_reports_its_state_under_a_distinct_key(): void
    {
        $src = (string) file_get_contents(self::ROLLOVER);
        $this->assertStringContainsString("'rollover_status'", $src,
            'the rollover state must have its own key');
        // Both endpoints, not just start().
        $this->assertSame(3, substr_count($src, "'rollover_status'"),
            'start() and both status() responses should each report rollover_status');
    }

    public function test_the_pump_reads_the_rollover_key_not_the_envelope(): void
    {
        $view = (string) file_get_contents(self::VIEW);
        $this->assertTrue((bool) preg_match('/TRN\.rolloverPump\s*=\s*function.*?\n  \};/s', $view, $m),
            'rolloverPump not found');
        $pump = $m[0];

        $this->assertMatchesRegularExpression('/var st\s*=\s*r\.rollover_status/', $pump,
            'the pump must branch on the rollover state, not on the envelope status');
        // The envelope guard is still correct and must stay — it catches real
        // transport failures.
        $this->assertMatchesRegularExpression("/if\(r\.status!=='success'\)/", $pump,
            'the envelope guard should remain for genuine request failures');
        // And the auto-continue branch must be reachable.
        $this->assertMatchesRegularExpression("/st===['\"]in_progress['\"]/", $pump,
            'the auto-continue branch must exist');
        $this->assertMatchesRegularExpression('/rolloverPump\(from,to\)/', $pump,
            'the pump must recurse to resume the remaining students');
    }
}
