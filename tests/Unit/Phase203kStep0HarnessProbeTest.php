<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20.3K-Step0 — FEASIBILITY PROBE for an executable Import test seam.
 *
 * Purpose: establish, empirically rather than by inspection, whether
 * `Sis::_runStudentImport()` can be invoked from PHPUnit at all.
 *
 * This is a PREREQUISITE probe, not the golden-master suite. It answers one
 * question: can the class be loaded and the private method reached without a
 * running CI3 stack? Everything in Phase 20.3K-Step0 depends on that answer,
 * and the previous phase stopped precisely because it was unknown.
 *
 * Approach:
 *   1. Declare a minimal CI_Controller stub BEFORE loading MY_Controller,
 *      so the parent chain resolves without CI3's core.
 *   2. require MY_Controller.php and Sis.php (READ-ONLY — neither is modified).
 *   3. Instantiate Sis via newInstanceWithoutConstructor(), bypassing both
 *      heavy constructors (MY_Controller's loads firebase, firestore_service,
 *      session, helpers, and emits headers).
 *   4. Reach the private method with ReflectionMethod::setAccessible(true) —
 *      production visibility is NOT changed.
 *
 * If any step fails, the failure message names the exact blocker so the next
 * phase does not have to rediscover it.
 */
class Phase203kStep0HarnessProbeTest extends TestCase
{
    private const MYC = __DIR__ . '/../../application/core/MY_Controller.php';
    private const SIS = __DIR__ . '/../../application/controllers/Sis.php';

    /** Step 1 — can the parent chain be satisfied with a stub? */
    public function test_probe_01_ci_controller_stub_can_be_declared(): void
    {
        if (!class_exists('CI_Controller', false)) {
            eval('class CI_Controller { public static $instance; public function __construct() { self::$instance = $this; } }');
        }
        $this->assertTrue(class_exists('CI_Controller', false),
            'A CI_Controller stub is required before MY_Controller can be loaded.');
    }

    /** Step 2 — does MY_Controller parse and load against the stub? */
    public function test_probe_02_my_controller_loads(): void
    {
        if (!class_exists('CI_Controller', false)) {
            eval('class CI_Controller { public static $instance; public function __construct() { self::$instance = $this; } }');
        }
        if (!function_exists('get_instance')) {
            // Must mirror CI3's `function &get_instance()` — BY REFERENCE.
            // Both this file and the harness declare it and PHPUnit shares one
            // process, so a by-value definition here would win and break
            // `$CI = &get_instance();` in production code under test.
            eval('function &get_instance() { return \CI_Controller::$instance; }');
        }

        $err = null;
        try {
            if (!class_exists('MY_Controller', false)) {
                require_once self::MYC;
            }
        } catch (\Throwable $e) {
            $err = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertNull($err, "MY_Controller could not be loaded — blocker: {$err}");
        $this->assertTrue(class_exists('MY_Controller', false));
    }

    /** Step 3 — does Sis itself load? */
    public function test_probe_03_sis_controller_loads(): void
    {
        if (!class_exists('MY_Controller', false)) {
            $this->markTestSkipped('MY_Controller did not load — see probe 02.');
        }

        $err = null;
        try {
            if (!class_exists('Sis', false)) {
                require_once self::SIS;
            }
        } catch (\Throwable $e) {
            $err = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertNull($err, "Sis could not be loaded — blocker: {$err}");
        $this->assertTrue(class_exists('Sis', false));
    }

    /** Step 4 — can an instance be built WITHOUT running the constructors? */
    public function test_probe_04_instance_without_constructor(): void
    {
        if (!class_exists('Sis', false)) {
            $this->markTestSkipped('Sis did not load — see probe 03.');
        }

        $err = null; $obj = null;
        try {
            $rc  = new \ReflectionClass('Sis');
            $obj = $rc->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            $err = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertNull($err, "Could not instantiate Sis without constructor — blocker: {$err}");
        $this->assertInstanceOf('Sis', $obj);
    }

    /** Step 5 — is the private method reachable and does it have the expected shape? */
    public function test_probe_05_private_method_is_reachable(): void
    {
        if (!class_exists('Sis', false)) {
            $this->markTestSkipped('Sis did not load — see probe 03.');
        }

        $rm = new \ReflectionMethod('Sis', '_runStudentImport');
        $this->assertTrue($rm->isPrivate(), 'Production visibility must remain private.');

        $rm->setAccessible(true);
        $this->assertSame(2, $rm->getNumberOfParameters(),
            '_runStudentImport(array $rows, array $opts = []) — signature drift would invalidate the harness.');
    }

    /**
     * Step 6 — which dependencies must the harness supply?
     *
     * Records the property names the method reaches for, so the golden-master
     * suite knows exactly which doubles to build rather than guessing.
     */
    public function test_probe_06_declares_required_dependencies(): void
    {
        $src = file_get_contents(self::SIS);
        $start = strpos($src, 'private function _runStudentImport');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 30000);

        preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\b/', $body, $m);
        $deps = array_values(array_unique($m[1]));
        sort($deps);

        // Not an assertion about correctness — a recorded inventory. The count
        // is what determines whether a seam is practical at all.
        $this->assertNotEmpty($deps);
        fwrite(STDERR, "\n[probe-06] _runStudentImport touches \$this-> members ("
            . count($deps) . "): " . implode(', ', array_slice($deps, 0, 40)) . "\n");
    }
}
