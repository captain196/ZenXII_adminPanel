<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Shared HR sequential-ID minting (ZenX HRMS W1.3).
 *
 * WHY THIS EXISTS
 * ---------------
 * `Hr::_next_id()` was private, so `Ats.php` could not reuse it and instead
 * carried its own RTDB read-modify-write counter:
 *
 *     $cur = (int) ($this->firebase->get($path) ?? 0);
 *     $this->firebase->set($path, $cur + 1);
 *
 * That is not atomic. Two concurrent "add candidate" clicks read the same value
 * and mint the SAME `APP0007`, and because the write is a whole-document set,
 * the second candidate silently overwrites the first — a lost application with
 * no error anywhere. Worse, the two modules counted in DIFFERENT stores (ATS in
 * RTDB, HR in Firestore), so once both wrote `hrApplicants` they would collide
 * on ids that each store independently believed were free.
 *
 * Extracting the minting into one function means ATS and HR now advance ONE
 * atomic per-school counter (`Firestore_service::nextSchoolCounter()`, which
 * uses createDocument()'s 409-safe compare-and-set), so ids can never collide
 * regardless of which module issues them or how concurrent the callers are.
 *
 * `Hr::_next_id()` is kept as a thin delegate: its nine call sites (DEPT, JOB,
 * APP, LT, LR, PR, APR) are unchanged and keep their exact previous behaviour.
 */

if (!function_exists('hr_next_id')) {
    /**
     * Mint the next sequential HR id for this school, atomically.
     *
     * @param object $fs     Firestore_service (already school-scoped).
     * @param string $prefix Id prefix, e.g. 'APP'.
     * @param string $type   Counter type, e.g. 'Applicant'. MUST match across
     *                       every module that mints this prefix, or two
     *                       independent counters will issue duplicate ids.
     * @param int    $pad    Zero-pad width.
     */
    function hr_next_id($fs, string $prefix, string $type, int $pad = 4): string
    {
        $kind = 'hr_' . $type;

        // Seed ONCE from the legacy schools/{profile}.hrCounters value so a
        // school that already issued APP0001..APP0006 under the old non-atomic
        // scheme never re-issues a used number.
        $seedFromLegacy = function () use ($fs, $type) {
            try {
                $schoolDoc = $fs->get('schools', $fs->docId('profile'));
                $counters  = (is_array($schoolDoc) && isset($schoolDoc['hrCounters'])) ? $schoolDoc['hrCounters'] : [];
                return (isset($counters[$type]) && is_numeric($counters[$type])) ? (int) $counters[$type] : 0;
            } catch (\Throwable $e) {
                return 0;
            }
        };

        $n = 0;
        try {
            $n = $fs->nextSchoolCounter($kind, 0, 12, [], $seedFromLegacy);
        } catch (\Throwable $e) {
            log_message('error', "hr_next_id counter failed [{$type}]: " . $e->getMessage());
            $n = 0;
        }

        if ($n > 0) {
            // Best-effort mirror onto legacy hrCounters (no longer authoritative)
            // so any legacy reader stays roughly in sync. Never affects correctness.
            try {
                $fs->update('schools', $fs->docId('profile'), ["hrCounters.{$type}" => $n]);
            } catch (\Throwable $e) { /* non-fatal */ }
            return $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        }

        // Counter unavailable — unique NON-sequential fallback (never a dup seq id).
        $fallback = (int) (microtime(true) * 1000) % 100000;
        return $prefix . str_pad((string) $fallback, $pad, '0', STR_PAD_LEFT) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
}
