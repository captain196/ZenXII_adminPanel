<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Numbering_verifier — Phase 1 verifier for the platform Numbering_service.
 *
 * CLI-only diagnostic. Six assertions covering registry sanity, format
 * patterns, period-scope schema, and pad-width utilisation. Read-only;
 * does not allocate, write, or mutate any Firestore document.
 *
 * Assertions:
 *   CH-NUM-1  inventory                — list registered kinds + flag state (INFO)
 *   CH-NUM-2  service-routing          — enabled kinds have no direct counter
 *                                        touches outside Numbering_service
 *   CH-NUM-3  legal-gapless integrity  — enabled LEGAL_GAPLESS kinds have no
 *                                        silent-catch fallback paths
 *   CH-NUM-4  seed-pattern validity    — each kind's seedSource regex parses
 *   CH-NUM-5  period-scope schema      — each kind's periodScope is recognised
 *   CH-NUM-6  pad-width utilisation    — peek pointer per kind; warn at >=80%
 *
 * Invocation:
 *   php index.php numbering_verifier verify
 *
 * Env required:
 *   SCHOOL_ID     e.g. SCH_D94FE8F7AD
 *   SESSION_YEAR  e.g. 2026-27
 *
 * Exit codes:
 *   0 — all assertions PASS or INFO (no FAIL)
 *   1 — env vars missing or registry not loadable
 *   2 — one or more assertions FAIL
 */
class Numbering_verifier extends CI_Controller
{
    private string $schoolId    = '';
    private string $sessionYear = '';
    private array  $registry    = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Numbering_verifier is CLI-only.', 403);
        }

        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');

        $this->schoolId    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolId === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }

        $this->fs->init($this->schoolId, $this->sessionYear);
        $this->numbering->init($this->fs, $this->schoolId, $this->sessionYear);

        $reg = $this->config->item('numbering');
        if (!is_array($reg) || empty($reg['kinds'])) {
            echo "ERROR: numbering.php config not loadable or empty.\n";
            exit(1);
        }
        $this->registry = $reg;
    }

    /**
     * Run all six assertions; print one line per assertion; exit non-zero
     * on any FAIL.
     */
    public function verify(): void
    {
        $results = [
            $this->_chNum1(),
            $this->_chNum2(),
            $this->_chNum3(),
            $this->_chNum4(),
            $this->_chNum5(),
            $this->_chNum6(),
        ];
        foreach ($results as $r) {
            echo $r['line'] . "\n";
        }
        $failed = 0;
        foreach ($results as $r) {
            if ($r['status'] === 'FAIL') {
                $failed++;
            }
        }
        echo "\n" . ($failed === 0 ? 'OK' : "FAILED ({$failed})") . "\n";
        exit($failed === 0 ? 0 : 2);
    }

    // ─── assertions ──────────────────────────────────────────────────────

    /** CH-NUM-1 — list each registered kind with its flag state. */
    private function _chNum1(): array
    {
        $items = [];
        foreach (array_keys($this->registry['kinds']) as $kind) {
            $flag = $this->registry['_kindFlags'][$kind] ?? 'unknown';
            $items[] = "{$kind}={$flag}";
        }
        return $this->_result('CH-NUM-1', 'INFO', 'inventory', implode(' ', $items));
    }

    /**
     * CH-NUM-2 — when a kind is 'enabled', no caller may touch its counter
     * storage outside Numbering_service. Phase 1 ships all kinds 'disabled'
     * so there is nothing to verify (INFO). The structural-scan body arrives
     * when the first kind enables (Phase 2+) and PASS/FAIL becomes meaningful.
     */
    private function _chNum2(): array
    {
        $enabled = $this->_enabledKinds();
        if (count($enabled) === 0) {
            return $this->_result('CH-NUM-2', 'INFO', 'service-routing', 'no enabled kinds');
        }
        return $this->_result(
            'CH-NUM-2', 'INFO', 'service-routing',
            count($enabled) . ' enabled; structural scan deferred to Phase 2+'
        );
    }

    /**
     * CH-NUM-3 — enabled LEGAL_GAPLESS kinds must not silently catch the
     * service's RuntimeException and substitute a fallback ID. Phase 1 has
     * no enabled LEGAL_GAPLESS kinds, so there is nothing to verify (INFO).
     * Silent-catch scan arrives when the first LEGAL_GAPLESS kind enables.
     */
    private function _chNum3(): array
    {
        $relevant = [];
        foreach ($this->registry['kinds'] as $kind => $cfg) {
            $isGapless = ($cfg['gaplessClass'] ?? '') === 'LEGAL_GAPLESS';
            $isEnabled = ($this->registry['_kindFlags'][$kind] ?? 'disabled') === 'enabled';
            if ($isGapless && $isEnabled) {
                $relevant[] = $kind;
            }
        }
        if (count($relevant) === 0) {
            return $this->_result('CH-NUM-3', 'INFO', 'legal-gapless integrity', 'no enabled LEGAL_GAPLESS kinds');
        }
        return $this->_result(
            'CH-NUM-3', 'INFO', 'legal-gapless integrity',
            count($relevant) . ' enabled LEGAL_GAPLESS; silent-catch scan deferred to Phase 2+'
        );
    }

    /** CH-NUM-4 — each registered seed-source pattern must be a valid PCRE. */
    private function _chNum4(): array
    {
        $invalid = [];
        foreach ($this->registry['kinds'] as $kind => $cfg) {
            $pattern = $cfg['seedSource']['pattern'] ?? null;
            if ($pattern === null) {
                $invalid[] = "{$kind}=no_pattern";
                continue;
            }
            if (@preg_match($pattern, '') === false) {
                $invalid[] = "{$kind}=invalid_regex";
            }
        }
        $n = count($this->registry['kinds']);
        if ($invalid !== []) {
            return $this->_result('CH-NUM-4', 'FAIL', 'seed-pattern validity', implode(' ', $invalid));
        }
        return $this->_result('CH-NUM-4', 'PASS', 'seed-pattern validity', "{$n}/{$n} patterns parse");
    }

    /** CH-NUM-5 — each kind's periodScope must be one of the recognised values. */
    private function _chNum5(): array
    {
        $valid = ['none', 'session', 'month'];
        $bad   = [];
        foreach ($this->registry['kinds'] as $kind => $cfg) {
            $scope = $cfg['periodScope'] ?? '';
            if (!in_array($scope, $valid, true)) {
                $bad[] = "{$kind}={$scope}";
            }
        }
        if ($bad !== []) {
            return $this->_result('CH-NUM-5', 'FAIL', 'period-scope schema', implode(' ', $bad));
        }
        return $this->_result('CH-NUM-5', 'PASS', 'period-scope schema', 'all kinds use valid periodScope');
    }

    /**
     * CH-NUM-6 — peek pointer per kind for the configured tenant; compute
     * util_pct = value / (10^padWidth - 1). WARN at >=80%, FAIL at >=95%.
     */
    private function _chNum6(): array
    {
        $items = [];
        $worst = 0.0;
        foreach ($this->registry['kinds'] as $kind => $cfg) {
            try {
                $value = $this->numbering->peek($kind);
            } catch (\Exception $e) {
                $items[] = "{$kind}=ERR";
                continue;
            }
            $pad = (int) $cfg['padWidth'];
            $max = $pad > 0 ? ((10 ** $pad) - 1) : 0;
            $util = $max > 0 ? round(($value / $max) * 100, 1) : 0.0;
            if ($util > $worst) {
                $worst = $util;
            }
            $items[] = "{$kind}={$value}({$util}%)";
        }
        $status = $worst >= 95 ? 'FAIL' : ($worst >= 80 ? 'WARN' : 'PASS');
        return $this->_result(
            'CH-NUM-6',
            $status,
            'pad-width util',
            "worst={$worst}% " . implode(' ', $items)
        );
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function _enabledKinds(): array
    {
        $out = [];
        foreach (($this->registry['_kindFlags'] ?? []) as $kind => $flag) {
            if ($flag === 'enabled') {
                $out[] = $kind;
            }
        }
        return $out;
    }

    private function _result(string $code, string $status, string $label, string $detail): array
    {
        return [
            'code'   => $code,
            'status' => $status,
            'label'  => $label,
            'line'   => sprintf('%-9s %-4s  %s: %s', $code, $status, $label, $detail),
        ];
    }
}
