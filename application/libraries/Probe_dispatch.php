<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  Probe_dispatch — the ONE way a probe controller calls a real controller.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THIS IS A LIBRARY AND NOT A METHOD ON EACH PROBE ─────────────────
 * `Lifecycle_probe` (emulator) and `Production_admission_probe` (production)
 * both need to construct a real controller and call a real endpoint. Copying
 * the helper into each would mean the production copy had never executed —
 * production would be the first runtime of the code that orchestrates a real
 * admission. Sharing one implementation makes the emulator rehearsal an actual
 * proof of the production path rather than a proof of something that merely
 * looks like it.
 *
 * ── WHY THE REGISTRY IS TEMPORARILY NARROWED ─────────────────────────────
 * `CI_Controller::__construct()` does:
 *
 *     foreach (is_loaded() as $var => $class) $this->$var =& load_class($class);
 *
 * `is_loaded()` is a GLOBAL registry, and `CI_Loader` aliases its own class
 * list to it by reference, so every library ever loaded through
 * `$this->load->library()` ends up in it — including `Firebase`, which this
 * application AUTOLOADS. `load_class()` only searches the CORE directories, so
 * the moment a SECOND controller is constructed that loop reaches 'Firebase'
 * and dies with "Class CI_Firebase not found". That is a property of the
 * application's autoload configuration, not of any harness: in-process
 * construction of a second controller cannot work here at all.
 *
 * The registry is returned BY REFERENCE, so the fix is to present the
 * constructor with only the genuine core classes — exactly what it would see on
 * a real request — and restore it immediately afterwards. The target controller
 * then loads its own libraries through its own constructor, which is what a
 * real request does too.
 *
 * NOTHING IS BYPASSED. The controller still runs its full constructor, its own
 * authorization, and its real dependencies.
 */
class Probe_dispatch
{
    /** The genuine core classes a real request presents to a controller. */
    private const CORE = ['benchmark', 'hooks', 'config', 'log', 'utf8', 'uri',
                          'router', 'output', 'security', 'input', 'lang'];

    /**
     * Construct `$controller` and call `$method`, with `$post` as `$_POST`.
     *
     * @return mixed whatever the endpoint returns — most `exit` via
     *               json_success()/json_error(), which is why probes run one
     *               dispatch per process.
     */
    public static function run(string $controller, string $method, array $post)
    {
        $_POST = $post;
        $class = ucfirst($controller);
        require_once APPPATH . 'controllers/' . $class . '.php';

        $registry =& is_loaded();
        $saved    = $registry;
        foreach (array_keys($registry) as $k) {
            if (!in_array($k, self::CORE, true)) unset($registry[$k]);
        }

        try {
            $c = new $class();
            return $c->$method();
        } finally {
            // Restore even when the endpoint exits via json_success(); a
            // `finally` still runs on a normal return, and on exit() the
            // process is ending anyway.
            $registry = $saved;
        }
    }
}
