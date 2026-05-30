<?php
/**
 * _concession_awareness_badge.php — render a small status badge next to a
 * fee-generation control, indicating whether that path applies new Fee
 * Concessions. UI-only; reads flags via fees_exemption_v2_flags.
 *
 * Usage:
 *   $type = 'concession-aware';   // for admission, promotion
 *   include APPPATH . 'views/_concession_awareness_badge.php';
 *
 *   $type = 'legacy-gen';         // for chart-save (Option A), manual,
 *                                 // bulk, recalc — all still legacy in P2
 *   include APPPATH . 'views/_concession_awareness_badge.php';
 *
 * Visibility rules:
 *   - PHASE_3_CONVERGED=true        → renders nothing (all paths converged)
 *   - USE_UNIFIED_FEE_GEN=true      → green on aware paths; amber on legacy paths
 *   - both off (pre-cutover)        → renders nothing (no guidance to give yet)
 */

$_type = isset($type) ? (string) $type : '';

$this->config->load('fees_exemption_v2_flags', true);
$_phase3  = (bool) $this->config->item('PHASE_3_CONVERGED',    'fees_exemption_v2_flags');
$_unified = (bool) $this->config->item('USE_UNIFIED_FEE_GEN',  'fees_exemption_v2_flags');

if ($_phase3 || !$_unified) {
    // Phase 3 → no badge needed (all converged). Or pre-cutover → no
    // guidance to surface yet. Either way: render nothing.
    return;
}

if ($_type === 'concession-aware') {
    echo '<span title="This path applies active Fee Concessions to the demands it generates." '
       . 'style="display:inline-block;font-size:11px;font-weight:600;padding:3px 9px;border-radius:10px;background:#dcfce7;color:#166534;margin-left:8px;vertical-align:middle;">'
       . '<i class="fa fa-check-circle" style="margin-right:4px;"></i>Concession-aware</span>';
} elseif ($_type === 'legacy-gen') {
    echo '<span title="Phase 2: this path uses the legacy generator and does NOT apply new Fee Concessions. To apply a concession, use Promote or Admission (or wait for Phase 3 convergence)." '
       . 'style="display:inline-block;font-size:11px;font-weight:600;padding:3px 9px;border-radius:10px;background:#fef3c7;color:#92400e;margin-left:8px;vertical-align:middle;">'
       . '<i class="fa fa-info-circle" style="margin-right:4px;"></i>Phase 2 — legacy gen</span>';
}
