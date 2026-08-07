<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admission CRM recent-cache bounds (M7-B) — the SINGLE canonical source for the
 * crmApplications.history[] and timeline[] caps. These arrays are BOUNDED RECENT
 * DISPLAY CACHES, not the permanent audit record: the authoritative, unbounded
 * trail lives in auditLogs + auditArchive (M7-A), queryable per application via
 * audit_trail_for_app(). Trimming a recent cache never loses audit truth.
 *
 * Caps chosen from real usage (not arbitrary): observed max history=21,
 * timeline=1 across live tenants; a normal complete lifecycle emits ~6–15
 * history events, an unusually active application ~21. history[] is the richer
 * operational log and gets the larger window; timeline[] is the short payment
 * journey (offline_selected → payment_collected, ± capture retries) and gets a
 * smaller but still generous window. Both comfortably exceed real usage so
 * normal applications never lose a visible entry; only pathological arrays trim,
 * and those entries remain permanently in auditLogs.
 */

if (!defined('CRM_HISTORY_CAP'))  define('CRM_HISTORY_CAP', 50);
if (!defined('CRM_TIMELINE_CAP')) define('CRM_TIMELINE_CAP', 20);

if (!function_exists('crm_history_cap')) {
    function crm_history_cap(): int { return CRM_HISTORY_CAP; }
}
if (!function_exists('crm_timeline_cap')) {
    function crm_timeline_cap(): int { return CRM_TIMELINE_CAP; }
}

if (!function_exists('admission_resolve_fee')) {
    /**
     * CANONICAL admission-fee resolver (Fix D) — the SINGLE source of the
     * admission-fee amount for BOTH the online (public) and offline (admin-collect)
     * paths, so an admin collecting at the counter charges exactly what the online
     * flow would. Policy: class-specific `feeStructures` head containing "admission"
     * wins; otherwise the global `schools.admissionFee`. Stateless (takes the
     * already-fetched Firestore_service + schools doc) to avoid any library-init
     * ordering coupling in the certified online path.
     *
     * @param object $fs        Firestore_service (school-scoped)
     * @param array  $schoolDoc the schools/{id} document (for the global fallback)
     * @param string $session   session year
     * @param string $cls       applicant class (with or without the "Class " prefix)
     * @return array ['enabled'=>bool,'amount'=>float,'currency'=>string,'source'=>string,'fee_head'=>string,'label'=>string]
     */
    function admission_resolve_fee($fs, array $schoolDoc, string $session, string $cls): array
    {
        $result = ['enabled' => false, 'amount' => 0.0, 'currency' => 'INR', 'source' => 'none', 'fee_head' => 'Admission Fee', 'label' => 'Admission Fee'];

        // Pass 1 — class-specific lookup in feeStructures.
        if ($cls !== '' && $session !== '' && is_object($fs) && method_exists($fs, 'schoolList')) {
            try {
                $normCls = (stripos($cls, 'Class ') === 0) ? $cls : ('Class ' . $cls);
                $rows = $fs->schoolList('feeStructures', [
                    ['session',   '==', $session],
                    ['className', '==', $normCls],
                ]);
                foreach ((array) $rows as $r) {
                    $heads = is_array($r['feeHeads'] ?? null) ? $r['feeHeads'] : [];
                    foreach ($heads as $h) {
                        if (!is_array($h)) continue;
                        $nameLower = strtolower(trim((string) ($h['name'] ?? '')));
                        if ($nameLower === '' || strpos($nameLower, 'admission') === false) continue;
                        $amt = (float) ($h['amount'] ?? 0);
                        if ($amt <= 0) continue;
                        return [
                            'enabled'  => true,
                            'amount'   => $amt,
                            'currency' => 'INR',
                            'source'   => 'feeStructures',
                            'fee_head' => (string) ($h['name'] ?? 'Admission Fee'),
                            'label'    => (string) ($h['name'] ?? 'Admission Fee'),
                        ];
                    }
                }
            } catch (\Exception $e) {
                log_message('error', 'admission_resolve_fee feeStructures lookup failed: ' . $e->getMessage());
            }
        }

        // Pass 2 — global default on the schools doc.
        $admissionFee = is_array($schoolDoc['admissionFee'] ?? null) ? $schoolDoc['admissionFee'] : [];
        if (!empty($admissionFee['enabled']) && (float) ($admissionFee['amount'] ?? 0) > 0) {
            return [
                'enabled'  => true,
                'amount'   => (float) $admissionFee['amount'],
                'currency' => (string) ($admissionFee['currency'] ?? 'INR'),
                'source'   => 'schools.admissionFee',
                'fee_head' => (string) ($admissionFee['label'] ?? 'Admission Fee'),
                'label'    => (string) ($admissionFee['label'] ?? 'Admission Fee'),
            ];
        }
        return $result;
    }
}
