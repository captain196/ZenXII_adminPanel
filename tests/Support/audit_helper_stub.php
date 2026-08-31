<?php
/**
 * Minimal global-namespace get_instance() stub so log_audit() (called for
 * real, unmocked, by Sr_no_service under test) degrades silently via its
 * own documented `!isset($CI->fs) -> return` early-exit, exactly as it
 * does in production whenever it's called before MY_Controller's fs
 * bootstrap. No test asserts against auditLogs writes (this repo has no
 * live Firestore in the test environment to write to) — these tests
 * verify Sr_no_service's own control flow and its srNoIndex/students
 * writes, which happen before/independent of the log_audit() call.
 */
// P20.3K-Step2 — must be declared BY REFERENCE, matching CI3's real
// `function &get_instance()`. Production code legitimately writes
// `$CI = &get_instance();` (the CI3 house idiom, e.g.
// Section_seat_guard::enabled()), which is a fatal
// "Only variables should be assigned by reference" against a by-value stub.
//
// This stub loads before the Import harness's own eval'd definition and wins
// the function_exists() race, so fixing it here is what actually takes effect.
// Returning a reference is backward-compatible for every existing caller.
if (!function_exists('get_instance')) {
    function &get_instance()
    {
        static $inst = null;
        if ($inst !== null) return $inst;
        $inst = new class {
            // No `fs` property — log_audit()'s own `!isset($CI->fs)` check
            // makes this a safe, documented no-op, matching its production
            // behavior when called before Firestore_service is bootstrapped.

            // Declared (not dynamic) and NULL by default so that
            // `$CI->config->load(...)` raises an Error which
            // Section_seat_guard::enabled() catches and answers FALSE — the
            // flag-off default every existing test relies on. A test that
            // needs the flag ON assigns a config double here and MUST reset
            // it to null afterwards.
            public $config = null;
        };
        return $inst;
    }
}
