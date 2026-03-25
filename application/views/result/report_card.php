<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Report Card — Template dispatcher.
 * Loads the selected report card template based on school config.
 * Backward-compatible: defaults to 'classic' if no template selected.
 */
$rc_template = $rc_template ?? 'classic';
$allowed = ['classic', 'cbse', 'minimal', 'modern', 'elegant'];
if (!in_array($rc_template, $allowed, true)) {
    $rc_template = 'classic';
}
try {
    $this->load->view('result/templates/' . $rc_template);
} catch (\Throwable $e) {
    // Fallback: show minimal error card instead of crashing the page
    echo '<div style="max-width:700px;margin:40px auto;padding:30px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;font-family:sans-serif;text-align:center;">'
       . '<h3 style="color:#dc2626;margin:0 0 10px">Report Card Error</h3>'
       . '<p style="color:#6b7280;margin:0">Unable to render the "' . htmlspecialchars($rc_template) . '" template. '
       . 'Please try the Classic template or contact your administrator.</p></div>';
    log_message('error', 'Report card template error [' . $rc_template . ']: ' . $e->getMessage());
}
