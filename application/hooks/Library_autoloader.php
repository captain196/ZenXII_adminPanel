<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * LIB-AUTOLOAD-1 (2026-09-19) — resolve `application/libraries/*.php` by class name.
 *
 * ── THE PROBLEM ──────────────────────────────────────────────────────────
 * CodeIgniter 3 has no autoloader for libraries. `$this->load->library()`
 * requires and instantiates one, but a bare STATIC reference —
 * `Some_service::CONSTANT` or `Some_service::helper()` — resolves only if that
 * library happens to have been loaded earlier in the SAME request.
 *
 * That makes such a reference silently order-dependent. It works from the page
 * that loads the class and fatals from the one that does not, and the fatal is
 * usually caught and rewritten into a friendly message that names the symptom
 * and hides the cause.
 *
 * Observed twice, both in production code:
 *   - Phase 19M — `Id_card_service::validReasons()` in Sis::id_card_issue(),
 *     fixed by loading the service first AT THAT CALL SITE.
 *   - IDC-FEE-LOAD-1 (2026-09-19) — `Id_card_service::REASON_LOST` inside
 *     `Id_card_fee_policy::chargeableReasons()`, reached from
 *     Sis::id_card_fee_settings(). The Replacement Charges dialog returned 400
 *     with "Could not load replacement charges" for every school, indefinitely.
 *
 * The second happened BECAUSE the first was fixed at a call site: a dependency
 * problem patched at one caller returns at the next. A source audit of all 191
 * libraries found 22 unguarded cross-library static references — in Action
 * registry, the fee generators, timetable and transport. Nine of them reference
 * `Firestore_service`, which only works by accident: MY_Controller instantiates
 * it on every request, so it is always loaded before any library runs. The rest
 * are live.
 *
 * ── WHY AN AUTOLOADER RATHER THAN 22 GUARDS ──────────────────────────────
 * A guard per site is 22 edits that fix 22 known cases and nothing else; the
 * 23rd is written the same week. This resolves the class the same way PHP
 * resolves every other class, so the failure mode stops existing.
 *
 * ── WHY IT IS SAFE HERE ──────────────────────────────────────────────────
 *   - It runs LAST. spl_autoload_register appends, so Composer's autoloader is
 *     consulted first and this only ever sees names nothing else could resolve.
 *     A name that fatals today still fatals; it simply gets one more chance.
 *   - 189 of the 191 library files declare a class matching their filename
 *     (verified by tokenizer, not by eye). The two that do not —
 *     Firestore_rest_client.php (declares FirestoreRestClient) and
 *     PaymentProviderInterface.php — are unreachable by this rule and therefore
 *     behave exactly as they do today.
 *   - The name is validated against a strict pattern and basename()d before it
 *     touches the filesystem, so a crafted class name cannot traverse out of
 *     the libraries directory.
 *   - require_once, so a library CI has already loaded is never re-included.
 *   - It does not change `$this->load->library()`. CI's loader checks
 *     `class_exists($class, FALSE)` — autoloading suppressed — so its own path
 *     is unaffected either way.
 *
 * It does NOT replace `$this->load->library()`: that still does the config
 * loading and instance registration this cannot. This only makes a STATIC
 * reference resolvable, which is the whole failure class.
 */
class Library_autoloader
{
    /** Class names we have already tried and failed, so a miss costs one stat. */
    private static $missed = [];

    public function register()
    {
        // Callable only. PHP 8 ignores the second argument ($do_throw) and emits a
        // notice for passing it — which would be logged on EVERY request. The third
        // ($prepend) defaults to false, and append is exactly what is wanted: this
        // must be the last autoloader consulted, after Composer.
        spl_autoload_register([__CLASS__, 'load']);
    }

    public static function load($class)
    {
        if (isset(self::$missed[$class])) return;

        // CI library classes are a single Ucfirst_snake token. Anything with a
        // namespace separator belongs to Composer and was already declined by
        // its autoloader; anything outside this shape cannot be one of ours.
        if (!preg_match('~^[A-Za-z][A-Za-z0-9_]*$~', $class)) {
            self::$missed[$class] = true;
            return;
        }

        // basename() is belt-and-braces over the pattern above: even if that
        // ever loosened, no separator can survive to reach the path.
        $file = APPPATH . 'libraries/' . basename($class) . '.php';

        if (!is_file($file)) {
            self::$missed[$class] = true;
            return;
        }

        require_once $file;
    }
}
