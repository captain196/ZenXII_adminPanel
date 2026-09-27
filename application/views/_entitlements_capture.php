<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * SHARED PARTIAL — Entitlement CAPTURE + live fee preview (Phase E).
 *
 * THE single staff-facing capture component. Mounted by every surface where a
 * real student record exists and staff may grant an entitlement:
 *   • Direct Admission screen      (studentAdmission.php)   — after save
 *   • CRM walk-in conversion       (admission_crm/*)        — after conversion
 *   • (later) Student 360          — no change needed here
 *
 * ── ONE COMPONENT, NOT THREE ──────────────────────────────────────────
 * Every surface includes THIS file and calls THESE endpoints. There is no
 * pathway-specific branch anywhere in it: the host page supplies a studentId
 * and a source tag via window.ENTCAP.mount(). A fix here fixes all surfaces.
 *
 * ── ZERO BUSINESS LOGIC ───────────────────────────────────────────────
 * This partial computes nothing. It does not price, does not decide whether
 * approval is required, and does not validate beyond the browser's own
 * required-field prompts. Every one of those decisions is made server-side by
 * Entitlement_service / Entitlements — deliberately, so a bypassed or stale
 * page cannot produce an entitlement the server would have refused. The
 * preview number is rendered from the server's response, never recomputed.
 *
 * Expects: $ec_source (string) — pathway tag recorded on each entitlement.
 */
$ecSource = $ec_source ?? 'manual';
?>

<style>
.entcap{border:1px solid rgba(188,90,60,.28);border-radius:10px;padding:16px 18px;margin:18px 0;background:rgba(188,90,60,.05)}
.entcap h4{margin:0 0 12px;font-size:15px;font-weight:700}
.entcap-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.entcap-f label{display:block;font-size:12.5px;font-weight:600;margin-bottom:4px}
.entcap-f input,.entcap-f select{width:100%;padding:7px 9px;border:1px solid rgba(0,0,0,.2);border-radius:6px;font-size:13px}
.entcap-hint{display:block;margin-top:5px;font-size:11.5px;line-height:1.45;opacity:.75}
.entcap-hint:empty{display:none}
.entcap-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
.entcap-btn{padding:8px 15px;border-radius:6px;border:1px solid transparent;font-size:13px;font-weight:600;cursor:pointer}
.entcap-btn.primary{background:#BC5A3C;color:#fff}
.entcap-btn.ghost{background:transparent;border-color:rgba(0,0,0,.25)}
.entcap-msg{margin-top:11px;font-size:13px;padding:9px 11px;border-radius:6px;display:none}
.entcap-msg.ok{background:rgba(22,163,74,.12);color:#166534;display:block}
.entcap-msg.err{background:rgba(220,38,38,.1);color:#991b1b;display:block}
.entcap-list{margin-top:14px;font-size:13px}
.entcap-list table{width:100%;border-collapse:collapse}
.entcap-list th,.entcap-list td{text-align:left;padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.1)}
.entcap-pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
.entcap-pill.active{background:rgba(22,163,74,.15);color:#166534}
.entcap-pill.pending{background:rgba(217,119,6,.15);color:#92400e}
.entcap-pill.other{background:rgba(0,0,0,.08)}
.entcap-prev{margin-top:12px;font-size:13px;display:none}
.entcap-prev table{width:100%;border-collapse:collapse}
.entcap-prev td{padding:4px 8px;border-bottom:1px solid rgba(0,0,0,.08)}
.entcap-prev .tot{font-weight:700}
.entcap[data-ready="0"]{opacity:.55;pointer-events:none}
.entcap-lock{margin:-6px 0 13px;font-size:12.5px;opacity:.8}
.entcap[data-ready="1"] .entcap-lock{display:none}
</style>

<div class="entcap" id="entcapBlock" data-ready="0" data-source="<?= htmlspecialchars($ecSource, ENT_QUOTES, 'UTF-8') ?>">
    <h4>Approve a Fee Concession</h4>
    <p class="entcap-lock">Unlocks once the student record exists. On the admission screen that happens when you save the student.</p>

    <div class="entcap-grid">
        <?php /* The kind is FIXED. "Service enrolment (adds fee)" was removed on
                 2026-08-30 (SVC-ORPHAN-1) and never replaced, leaving a select with a
                 single option - which reads as a decision the operator has to make.
                 Transport and hostel are REQUESTED through the declarations block,
                 which feeds requestTransport() and the Transport -> Requests queue.
                 Hidden, not deleted: add(), the direct-admission host scrape and
                 P2ContractFreezeTest all read `ecKind`. */ ?>
        <input type="hidden" id="ecKind" value="concession">
        <div class="entcap-f" id="ecConcTypeWrap">
            <label>Concession type</label>
            <select id="ecConcType">
                <option value="sibling">Sibling</option>
                <option value="staff_ward">Staff ward</option>
                <option value="rte_ews">RTE / EWS</option>
                <option value="scholarship">Scholarship</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="entcap-f">
            <label>Applies to</label>
            <select id="ecScopeKind">
                <option value="all">All fees</option>
                <option value="category">A fee category</option>
                <option value="head">A single fee head</option>
            </select>
        </div>
        <!-- F-3: the target is PICKED, never typed. A free-text box produced
             `head:<whatever was typed>` — the reader's legacy name tier, which
             a rename silently breaks — and could not emit the authoritative
             `head:<feeHeadRef>` at all. Two pickers, one per scope kind; the
             wrapper shows whichever the scope needs. -->
        <div class="entcap-f" id="ecScopeHeadWrap" style="display:none;">
            <label>Fee head</label>
            <select id="ecScopeHead"><option value="">Loading…</option></select>
            <small id="ecScopeHeadHint" class="entcap-hint"></small>
        </div>
        <div class="entcap-f" id="ecScopeCatWrap" style="display:none;">
            <label>Fee category</label>
            <select id="ecScopeCat"><option value="">Select…</option></select>
        </div>
        <div class="entcap-f">
            <label>Value type</label>
            <select id="ecValueType">
                <option value="percent">Percent</option>
                <option value="fixed">Fixed amount</option>
                <option value="fullExempt">Full exemption</option>
            </select>
        </div>
        <div class="entcap-f" id="ecValueWrap">
            <label>Value</label>
            <input type="number" id="ecValue" min="0" step="0.01" value="0">
        </div>
        <!-- Billing frequency removed at the P2 contract freeze (I1). It asked
             the operator for a billing period the entitlement never used — the
             period belongs to the demand. Nothing replaces it here. -->
        <div class="entcap-f">
            <label>Effective from <span style="color:#b91c1c">*</span></label>
            <input type="date" id="ecFrom">
        </div>
        <div class="entcap-f">
            <label>Effective to</label>
            <input type="date" id="ecTo">
        </div>
        <div class="entcap-f" id="ecGovtWrap" style="display:none;">
            <!-- I4: an RTE/EWS grant without a receivable is an unrecorded
                 government debt. Server refuses it; surfaced here so the
                 operator sees why before submitting. -->
            <label>Govt. receivable (₹) <span style="color:#b91c1c">*</span></label>
            <input type="number" id="ecGovt" min="0" step="0.01" value="0">
        </div>
        <div class="entcap-f" style="grid-column:1/-1">
            <label>Reason <span style="color:#b91c1c">*</span></label>
            <input type="text" id="ecReason" placeholder="Recorded in the audit trail">
        </div>
    </div>

    <div class="entcap-actions">
        <button type="button" class="entcap-btn primary" id="ecAdd">Add entitlement</button>
        <button type="button" class="entcap-btn ghost" id="ecPreview">Preview fees</button>
    </div>

    <div class="entcap-msg" id="ecMsg"></div>
    <div class="entcap-prev" id="ecPrev"></div>
    <div class="entcap-list" id="ecList"></div>
</div>

<script>
window.ENTCAP = (function () {
    var BASE = '<?= rtrim(base_url(), '/') ?>/';
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var studentId = '', klass = '', section = '';

    var $ = function (id) { return document.getElementById(id); };
    var block = $('entcapBlock');

    function csrfToken() {
        var el = document.querySelector('input[name="' + CSRF_NAME + '"]');
        return el ? el.value : '';
    }
    function setToken(t) {
        if (!t) return;
        document.querySelectorAll('input[name="' + CSRF_NAME + '"]').forEach(function (el) { el.value = t; });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function msg(text, ok) {
        var m = $('ecMsg');
        m.className = 'entcap-msg ' + (ok ? 'ok' : 'err');
        m.textContent = text;
    }
    function post(path, fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.append(CSRF_NAME, csrfToken());
        return fetch(BASE + path, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                // Phantom-success guard: fetch() does not reject on 403/500, so
                // a denied action would otherwise render as done. Check the HTTP
                // status AND the envelope before treating anything as success.
                return r.json().then(function (j) { return { httpOk: r.ok, body: j }; })
                               .catch(function () { return { httpOk: false, body: null }; });
            })
            .then(function (res) {
                if (res.body && res.body.csrf_token) setToken(res.body.csrf_token);
                if (!res.httpOk || !res.body || res.body.status !== 'success') {
                    throw new Error((res.body && res.body.message) || 'Request failed.');
                }
                return res.body;
            });
    }

    // ── conditional fields ──
    function syncFields() {
        var kind = $('ecKind').value;
        // `kind` is fixed to 'concession' (see the hidden ecKind above), so this
        // wrapper is always shown. Kept as an assignment rather than deleted: the
        // day a second kind returns, this is the line that has to change.
        $('ecConcTypeWrap').style.display = kind === 'concession' ? '' : 'none';
        var sk = $('ecScopeKind').value;
        $('ecScopeHeadWrap').style.display = sk === 'head'     ? '' : 'none';
        $('ecScopeCatWrap').style.display  = sk === 'category' ? '' : 'none';
        if (sk === 'head') loadFeeHeads();
        $('ecValueWrap').style.display = $('ecValueType').value === 'fullExempt' ? 'none' : '';
        $('ecGovtWrap').style.display =
            (kind === 'concession' && $('ecConcType').value === 'rte_ews') ? '' : 'none';
    }
    ['ecKind','ecConcType','ecScopeKind','ecValueType'].forEach(function (id) {
        var el = $(id); if (el) el.addEventListener('change', syncFields);
    });

    // ── F-3 · fee-head picker ──
    // Loaded once per capture surface, lazily (only when a head scope is
    // actually chosen) so the common 'all'/'category' cases cost no read.
    var headsLoaded = false;
    function loadFeeHeads() {
        if (headsLoaded) return;
        headsLoaded = true;                       // in-flight guard: never two calls
        post('entitlements/fee_heads', {}).then(function (r) {
            var heads = r.heads || [], sel = $('ecScopeHead'), hint = $('ecScopeHeadHint');
            sel.innerHTML = '';
            if (!heads.length) {
                // Fail CLOSED and say why. The old free-text box would have
                // happily accepted a typed name here; refusing is the point.
                sel.innerHTML = '<option value="">No fee heads registered</option>';
                hint.textContent = 'This school has no registered fee heads yet, so a '
                    + 'head-scoped entitlement cannot be created. Use a category or '
                    + 'school-wide scope, or register the fee head first.';
                return;
            }
            sel.innerHTML = '<option value="">Select…</option>' + heads.map(function (h) {
                return '<option value="' + esc(h.feeHeadRef) + '">'
                     + esc(h.name) + ' (' + esc(h.category) + ')</option>';
            }).join('');
            hint.textContent = '';

            var cat = $('ecScopeCat');
            if (cat && cat.options.length <= 1) {
                cat.innerHTML = '<option value="">Select…</option>'
                    + (r.categories || []).map(function (c) {
                        return '<option value="' + esc(c) + '">' + esc(c) + '</option>';
                    }).join('');
            }
        }).catch(function (e) {
            headsLoaded = false;                  // a failure must be retryable
            $('ecScopeHead').innerHTML = '<option value="">Could not load fee heads</option>';
            $('ecScopeHeadHint').textContent = e.message || 'Could not load fee heads.';
        });
    }

    /**
     * The scope string. `head:` now always carries a school-scoped feeHeadRef
     * chosen from the registry — never a typed name. An unset picker returns an
     * empty target, which the server rejects ("A scoped entitlement needs a
     * target after the colon"), so a half-filled form cannot create a scope
     * that silently matches nothing.
     */
    function scopeValue() {
        var k = $('ecScopeKind').value;
        if (k === 'all')      return 'all';
        if (k === 'head')     return 'head:' + ($('ecScopeHead').value || '').trim();
        if (k === 'category') return 'category:' + ($('ecScopeCat').value || '').trim();
        return '';
    }

    function refreshList() {
        if (!studentId) return;
        post('entitlements/for_student', { student_id: studentId }).then(function (r) {
            var rows = r.entitlements || [];
            if (!rows.length) { $('ecList').innerHTML = ''; return; }
            var html = '<table><tr><th>Type</th><th>Applies to</th><th>Value</th><th>Status</th></tr>';
            rows.forEach(function (e) {
                var st = String(e.status || '');
                var cls = st === 'active' ? 'active' : (st === 'pending_approval' ? 'pending' : 'other');
                var val = e.valueType === 'fullExempt' ? 'Full exemption'
                        : (e.valueType === 'percent' ? (e.value + '%') : ('₹' + e.value));
                html += '<tr><td>' + esc(e.concessionType || e.serviceType || e.kind) + '</td><td>'
                     + esc(e.scope) + '</td><td>' + esc(val) + '</td><td><span class="entcap-pill '
                     + cls + '">' + esc(st.replace('_', ' ')) + '</span></td></tr>';
            });
            $('ecList').innerHTML = html + '</table>';
        }).catch(function () { /* list is informational; never block capture */ });
    }

    /**
     * UAT fix (2026-08-09): buttons had no busy state, so a slow round-trip
     * looked like a dead click and invited a double-submit. The server
     * validates every request independently so a duplicate was never
     * *dangerous* — but it could create two identical entitlements.
     */
    function busy(btn, on, label) {
        if (!btn) return;
        if (on) {
            btn.dataset.label = btn.dataset.label || btn.textContent;
            btn.disabled = true;
            btn.style.opacity = '.65';
            btn.style.cursor = 'wait';
            btn.textContent = label || 'Working…';
        } else {
            btn.disabled = false;
            btn.style.opacity = '';
            btn.style.cursor = '';
            btn.textContent = btn.dataset.label || btn.textContent;
        }
    }

    function add() {
        if (!studentId) { msg('No student selected yet.', false); return; }
        busy($('ecAdd'), true, 'Saving…');
        post('entitlements/propose', {
            student_id:      studentId,
            kind:            $('ecKind').value,
            concession_type: $('ecConcType').value,
            scope:           scopeValue(),
            value_type:      $('ecValueType').value,
            value:           $('ecValue').value || 0,
            // no `frequency` — retired at the P2 contract freeze (I1)
            effective_from:  $('ecFrom').value,
            effective_to:    $('ecTo').value,
            reason:          $('ecReason').value,
            govt_receivable_amount: $('ecGovt').value || 0,
            source:          block.getAttribute('data-source') || 'manual'
        }).then(function (r) {
            msg(r.message || 'Saved.', true);
            $('ecReason').value = '';
            refreshList();
        }).catch(function (e) { msg(e.message, false); })
          .then(function () { busy($('ecAdd'), false); });   // always clear, pass or fail
    }

    function preview() {
        if (!studentId) { msg('No student selected yet.', false); return; }
        busy($('ecPreview'), true, 'Calculating…');
        post('entitlements/preview', { student_id: studentId, "class": klass, section: section })
        .then(function (r) {
            var html = '<table>';
            (r.lines || []).forEach(function (l) {
                html += '<tr><td>' + esc(l.feeHead) + ' <span style="opacity:.6">' + esc(l.period) + '</span></td>'
                     + '<td style="text-align:right">₹' + l.net
                     + (l.concession > 0 ? ' <span style="opacity:.6">(−₹' + l.concession + ')</span>' : '')
                     + '</td></tr>';
            });
            html += '<tr class="tot"><td>Total payable</td><td style="text-align:right">₹'
                 + r.totalNet + '</td></tr></table>'
                 + '<div style="opacity:.65;margin-top:6px">' + esc(r.note || '') + '</div>';
            $('ecPrev').innerHTML = html;
            $('ecPrev').style.display = 'block';
        }).catch(function (e) { msg(e.message, false); })
          .then(function () { busy($('ecPreview'), false); });
    }

    var addBtn = $('ecAdd'), prevBtn = $('ecPreview');
    if (addBtn)  addBtn.addEventListener('click', add);
    if (prevBtn) prevBtn.addEventListener('click', preview);
    syncFields();

    return {
        /** Host page calls this once a real student exists. */
        mount: function (opts) {
            studentId = (opts && opts.studentId) || '';
            klass     = (opts && opts.klass)     || '';
            section   = (opts && opts.section)   || '';
            block.setAttribute('data-ready', studentId ? '1' : '0');
            if (studentId) refreshList();
        },
        /**
         * REG-P6-R1 — the scope string, for hosts that submit the entitlement
         * WITH their own form instead of through this panel's AJAX propose.
         *
         * Direct admission (studentAdmission.php) is such a host: it scrapes
         * these fields into `ent_*` on the admission POST so the server can
         * record the entitlement BEFORE it prices the demands. It previously
         * rebuilt the scope string itself by reading `ecScopeTarget` — and when
         * F-3 replaced that free-text box with the registry pickers, the host
         * kept reading the deleted element and silently dropped every head- and
         * category-scoped entitlement.
         *
         * Exposing the ONE implementation removes the duplicate that made that
         * possible: a host can no longer hold a stale idea of what a scope is.
         */
        scopeValue: scopeValue,

        /** Whether the head picker has a usable selection yet (host guard). */
        scopeReady: function () {
            var k = $('ecScopeKind').value;
            return k === 'all' || scopeValue().slice(k.length + 1).trim() !== '';
        },

        /** Pre-fill from the family's declarations captured pre-admission. */
        prefill: function (d) {
            if (!d) return;
            if (d.rte)        { $('ecKind').value='concession'; $('ecConcType').value='rte_ews'; }
            else if (d.sibling)    { $('ecKind').value='concession'; $('ecConcType').value='sibling'; }
            else if (d.staff_ward) { $('ecKind').value='concession'; $('ecConcType').value='staff_ward'; }
            // transport/hostel deliberately NOT prefilled here any more: a
            // service declaration is a REQUEST, and pre-selecting it in this
            // panel invited staff to save it as an active entitlement that no
            // operational workflow reads. Transport declarations flow to
            // requestTransport() instead. Concession prefills are unaffected.
            syncFields();
        }
    };
})();
</script>
