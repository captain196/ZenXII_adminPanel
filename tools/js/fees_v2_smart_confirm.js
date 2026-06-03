/* ============================================================================
 *  fees_v2_smart_confirm.js
 * ----------------------------------------------------------------------------
 *  Fees Exemption v2 — operator-facing smart-confirm helper.
 *
 *  Wraps a legacy fee-generation action with an optional confirm modal that
 *  warns the operator about active Fee Concessions that the legacy path will
 *  NOT apply (Phase 2: only Admission + Promotion apply concessions). The
 *  operator can cancel or proceed knowing the gap exists.
 *
 *  PUBLIC API:
 *     window.feesV2.confirmLegacyGen(opts)
 *        opts.scope     : 'student'  | 'school'
 *        opts.studentId : (scope=='student')  required, the STUxxxx id
 *        opts.label     : human label for the operation (e.g. 'Generate Demands')
 *        opts.proceedLabel? / cancelLabel? : button copy overrides
 *
 *     Returns a Promise<boolean>:
 *        - resolves true  → caller should proceed with the legacy action
 *        - resolves false → caller should ABORT (operator cancelled)
 *
 *  BEHAVIOR:
 *     - If `window.FEES_V2_FLAGS.smartConfirmEnabled` is false (the default
 *       pre-cutover), the helper resolves true immediately — no network
 *       call, no modal. Zero overhead.
 *     - When enabled, it calls the read-only check_*_active_concessions
 *       endpoint. If count==0 → resolves true immediately (no modal).
 *       Otherwise renders an amber confirm modal.
 *
 *  This helper is UI-ONLY. It NEVER calls fee-generation logic itself —
 *  the caller does that after the Promise resolves true.
 *
 *  Companion: application/views/_concession_awareness_badge.php (the
 *  green/amber pill rendered next to each save button).
 * ==========================================================================*/
(function () {
    'use strict';

    var FLAGS = window.FEES_V2_FLAGS || {};
    var BASE  = (typeof window.BASE_URL === 'string' && window.BASE_URL) ||
                (window.BASE || '');

    function isEnabled() {
        return !!FLAGS.smartConfirmEnabled;
    }

    function postCheck(endpoint, payload) {
        return new Promise(function (resolve) {
            if (typeof jQuery === 'undefined' || typeof jQuery.ajax !== 'function') {
                // No jQuery — degrade open (let the caller proceed).
                resolve(null);
                return;
            }
            var url = BASE + 'fee_concessions/' + endpoint;
            jQuery.ajax({
                url: url, type: 'POST', data: payload, dataType: 'json',
                timeout: 8000
            }).done(function (res) {
                if (res && (res.success || res.status === 'success')) {
                    var d = res.data || res;
                    resolve(d);
                } else {
                    resolve(null);
                }
            }).fail(function () { resolve(null); });
        });
    }

    function renderModal(message, proceedLabel, cancelLabel) {
        return new Promise(function (resolve) {
            var existing = document.getElementById('feesV2SmartConfirmOverlay');
            if (existing) existing.parentNode.removeChild(existing);

            var overlay = document.createElement('div');
            overlay.id = 'feesV2SmartConfirmOverlay';
            overlay.style.cssText =
                'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;' +
                'display:flex;align-items:center;justify-content:center;padding:16px;';

            var panel = document.createElement('div');
            panel.style.cssText =
                'background:#fff;border-radius:10px;max-width:560px;width:100%;' +
                'box-shadow:0 20px 50px rgba(0,0,0,0.25);overflow:hidden;' +
                'font-family:inherit;';

            panel.innerHTML =
                '<div style="background:#fef3c7;color:#92400e;padding:14px 20px;' +
                'border-bottom:1px solid #fde68a;font-weight:600;font-size:15px;">' +
                '<i class="fa fa-exclamation-triangle" style="margin-right:8px;"></i>' +
                'Active Fee Concessions detected</div>' +
                '<div style="padding:18px 20px;font-size:13.5px;line-height:1.55;color:#1f2937;">' +
                message + '</div>' +
                '<div style="padding:12px 20px;background:#f9fafb;border-top:1px solid #e5e7eb;' +
                'display:flex;justify-content:flex-end;gap:10px;">' +
                '<button type="button" id="feesV2SmartConfirmCancel" style="padding:8px 16px;' +
                'border-radius:6px;border:1px solid #d1d5db;background:#fff;color:#374151;' +
                'font-weight:500;cursor:pointer;">' + cancelLabel + '</button>' +
                '<button type="button" id="feesV2SmartConfirmProceed" style="padding:8px 16px;' +
                'border-radius:6px;border:0;background:#d97706;color:#fff;font-weight:600;' +
                'cursor:pointer;">' + proceedLabel + '</button>' +
                '</div>';

            overlay.appendChild(panel);
            document.body.appendChild(overlay);

            function close(decision) {
                if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
                resolve(decision);
            }
            document.getElementById('feesV2SmartConfirmCancel').addEventListener('click',
                function () { close(false); });
            document.getElementById('feesV2SmartConfirmProceed').addEventListener('click',
                function () { close(true); });
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) close(false);
            });
        });
    }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Public entry point.
     */
    function confirmLegacyGen(opts) {
        opts = opts || {};
        if (!isEnabled()) {
            return Promise.resolve(true);
        }
        var scope    = opts.scope === 'school' ? 'school' : 'student';
        var label    = opts.label   || 'this operation';
        var proceed  = opts.proceedLabel || 'Proceed anyway';
        var cancel   = opts.cancelLabel  || 'Cancel';

        var checkP;
        if (scope === 'student') {
            if (!opts.studentId) { return Promise.resolve(true); }
            checkP = postCheck('check_active_concessions', { student_id: opts.studentId });
        } else {
            checkP = postCheck('check_school_active_concessions', {});
        }

        return checkP.then(function (data) {
            if (!data) return true;  // fail-open on network/permission errors
            var n = Number(data.count || 0);
            if (n === 0) return true;

            var msg;
            if (scope === 'student') {
                var items = (data.active_concessions || []).slice(0, 3).map(function (c) {
                    var v = c.type === 'percent' ? (c.value + '%') :
                            c.type === 'fixed'   ? ('₹' + c.value) :
                            c.type === 'fullExempt' ? 'full exempt' : c.value;
                    return '<li>' + escHtml(v) + ' — ' +
                           escHtml(c.scope || c.target || '') + '</li>';
                }).join('');
                msg = '<p>This student has <b>' + n + ' active concession' +
                      (n === 1 ? '' : 's') + '</b> on file:</p>' +
                      '<ul style="margin:8px 0 12px 18px;">' + items + '</ul>' +
                      '<p><b>' + escHtml(label) + '</b> uses the legacy fee-generation ' +
                      'path, which does <b>not</b> apply these concessions in Phase 2.</p>' +
                      '<p style="color:#6b7280;font-size:12.5px;">To apply concessions, ' +
                      'use <b>Promotion</b> (re-promote into the same class) or wait for ' +
                      'Phase 3 convergence. Cancel here is safe.</p>';
            } else {
                var sc = Number(data.student_count || 0);
                msg = '<p>This school has <b>' + n + ' active concession' +
                      (n === 1 ? '' : 's') + '</b> on file across <b>' + sc +
                      ' student' + (sc === 1 ? '' : 's') + '</b>.</p>' +
                      '<p><b>' + escHtml(label) + '</b> uses the legacy fee-generation ' +
                      'path, which does <b>not</b> apply concessions in Phase 2 — ' +
                      'any affected student in this batch will be billed at the gross ' +
                      'amount.</p>' +
                      '<p style="color:#6b7280;font-size:12.5px;">To apply concessions, ' +
                      'use <b>Promotion</b> (re-promote affected students) or wait for ' +
                      'Phase 3 convergence. Cancel here is safe.</p>';
            }
            return renderModal(msg, proceed, cancel);
        });
    }

    window.feesV2 = window.feesV2 || {};
    window.feesV2.confirmLegacyGen = confirmLegacyGen;
})();
