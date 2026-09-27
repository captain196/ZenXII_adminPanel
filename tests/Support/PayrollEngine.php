<?php
declare(strict_types=1);

/**
 * Executes the SHIPPED payroll calculation engine in isolation.
 *
 * `Hr::_compute_payslip()` is documented as "pure computation with no Firestore
 * access", and it is the single money engine both `generate_payroll()` and
 * `regenerate_staff_payroll()` call. That makes it directly executable: the
 * class needs no container, only a `MY_Controller` symbol to extend, and the
 * method touches no instance state.
 *
 * Before this harness, every money test in the suite MIRRORED the engine's
 * arithmetic in the test file ("Mirrors _compute_payslip()'s retention ratio").
 * A mirror proves the transcription self-consistent, not the engine correct —
 * if the engine changed, the mirror kept passing. This runs the real thing.
 *
 * Nothing here stubs or reimplements any part of the calculation.
 */
final class PayrollEngine
{
    private static ?object $instance = null;
    private static ?ReflectionClass $rc = null;

    /**
     * Load Hr once, using the SAME bootstrap the Phase203k import harness
     * established: stub `CI_Controller`, then require the REAL
     * `application/core/MY_Controller.php`.
     *
     * Declaring a fake MY_Controller here instead would be shared-process
     * poison — this file loads earlier alphabetically than those suites, so a
     * stub defined here shadows the real class for every later test and their
     * controllers lose their inherited methods. Everything below is declared
     * only when absent, so whichever suite runs first wins identically.
     */
    private static function boot(): void
    {
        if (self::$instance !== null) return;

        if (!class_exists('CI_Controller', false)) {
            eval('class CI_Controller { public static $instance; public function __construct() { self::$instance = $this; } }');
        }
        if (!function_exists('get_instance')) {
            // CI3 declares `function &get_instance()` — BY REFERENCE.
            eval('function &get_instance() { return \CI_Controller::$instance; }');
        }
        if (!class_exists('MY_Controller', false)) {
            require_once __DIR__ . '/../../application/core/MY_Controller.php';
        }
        if (!class_exists('Hr', false)) {
            require_once __DIR__ . '/../../application/controllers/Hr.php';
        }

        self::$rc = new ReflectionClass('Hr');
        // No constructor: the engine needs no container, session or Firestore.
        self::$instance = self::$rc->newInstanceWithoutConstructor();
    }

    /** True when the engine could be loaded on this machine. */
    public static function available(): bool
    {
        try {
            self::boot();
            return self::$instance !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Run the real engine.
     *
     * @param array $sal              salary structure (basic, hra, da, ta, medical, other_allowances)
     * @param float $staffWorkingDays the ratio DENOMINATOR for the period
     * @param float $daysAbsent       unpaid absence
     * @param float $lwpDays          leave-without-pay days
     * @param float $extraWorkedDays  days worked on a scheduled off-day ('W')
     * @param float $extraWorkedRate  pay-policy multiplier, 1.0 = at par
     */
    public static function compute(
        array $sal,
        float $staffWorkingDays,
        float $daysAbsent = 0.0,
        float $lwpDays = 0.0,
        float $extraWorkedDays = 0.0,
        float $extraWorkedRate = 1.0
    ): array {
        self::boot();

        $m = self::$rc->getMethod('_compute_payslip');
        $m->setAccessible(true);

        return $m->invoke(
            self::$instance,
            $sal,
            $staffWorkingDays,
            $daysAbsent,
            $lwpDays,
            $extraWorkedDays,
            $extraWorkedRate
        );
    }

    /**
     * Call any private instance method on the real Hr controller.
     *
     * Used for the XLSX sheet builders (`_xl_salary_register`, `_xl_dept_report`,
     * `_xl_payment_report`), which take a sheet plus plain arrays and touch no
     * Firestore — so a real workbook can be produced and parsed back.
     */
    public static function call(string $method, ...$args)
    {
        self::boot();

        $m = self::$rc->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke(self::$instance, ...$args);
    }

    /** Call a private static money primitive (_toPaise / _applyRatio / _toRupees). */
    public static function primitive(string $name, ...$args)
    {
        self::boot();

        $m = self::$rc->getMethod($name);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    /** A representative structure summing to a round ₹40,000. */
    public static function salary(): array
    {
        return [
            'basic'            => 20000,
            'hra'              => 8000,
            'da'               => 5000,
            'ta'               => 2000,
            'medical'          => 1000,
            'other_allowances' => 4000,
        ];
    }

    /** Sum of the earning components the engine returns. */
    public static function earningsSum(array $r): float
    {
        return (float) $r['basic']
            + (float) $r['hra']
            + (float) $r['da']
            + (float) $r['ta']
            + (float) $r['medical']
            + (float) $r['other_allowances'];
    }
}
