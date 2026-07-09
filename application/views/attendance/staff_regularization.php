<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
.sr-wrap { --sr-green:#16a34a; --sr-red:#dc2626; --sr-orange:#d97706; --sr-blue:#2563eb;
    font-family: var(--font-b,'Plus Jakarta Sans',sans-serif); color: var(--t1); }
.sr-hdr { margin-bottom: 16px; }
.sr-hdr h4 { font-weight: 700; margin: 0 0 4px; font-size: 18px; }
.sr-hdr h4 i { color: var(--gold); margin-right: 6px; }
.sr-hdr p { font-size: 13px; color: var(--t3); margin: 0; }
.sr-tabs { display: flex; gap: 6px; margin: 14px 0 18px; flex-wrap: wrap; }
.sr-tab { font: inherit; font-size: 13px; font-weight: 600; border: 1px solid var(--border);
    background: var(--bg2); color: var(--t2); padding: 7px 14px; border-radius: 8px; cursor: pointer; }
.sr-tab.active { background: var(--gold); color: #1a1200; border-color: var(--gold); }
.sr-list { display: flex; flex-direction: column; gap: 12px; }
.sr-card { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
.sr-card-top { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.sr-name { font-weight: 700; font-size: 15px; }
.sr-sid { font-family: var(--font-m,monospace); font-size: 12px; color: var(--t3); }
.sr-pill { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 20px; margin-left: auto; }
.sr-pill.pending { color: var(--sr-orange); background: rgba(217,119,6,.14); }
.sr-pill.approved { color: var(--sr-green); background: rgba(22,163,74,.14); }
.sr-pill.rejected { color: var(--sr-red); background: rgba(220,38,38,.14); }
.sr-reason { font-size: 13px; color: var(--t2); margin: 8px 0; }
.sr-dates { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
.sr-date { font-size: 12px; font-family: var(--font-m,monospace); background: var(--bg3);
    border: 1px solid var(--border); border-radius: 6px; padding: 3px 8px; }
.sr-date small { color: var(--t3); margin-left: 6px; }
.sr-actions { display: flex; gap: 8px; align-items: center; margin-top: 12px; flex-wrap: wrap; }
.sr-actions input.sr-remarks { flex: 1; min-width: 160px; font: inherit; font-size: 13px;
    padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--t1); }
.sr-actions select.sr-mark { font: inherit; font-size: 13px; padding: 7px 8px;
    border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--t1); }
.sr-btn { font: inherit; font-size: 13px; font-weight: 600; border: 0; border-radius: 8px;
    padding: 8px 16px; cursor: pointer; }
.sr-btn.approve { background: var(--sr-green); color: #fff; }
.sr-btn.reject { background: transparent; color: var(--sr-red); border: 1px solid var(--sr-red); }
.sr-btn:disabled { opacity: .5; cursor: default; }
.sr-empty, .sr-loading { text-align: center; color: var(--t3); font-size: 14px; padding: 40px 0; }
.sr-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: var(--t1); color: var(--bg); font-size: 13px; font-weight: 600; padding: 10px 18px;
    border-radius: 10px; opacity: 0; transition: opacity .2s; z-index: 9999; pointer-events: none; }
.sr-toast.show { opacity: 1; }
</style>

<div class="sr-wrap">
    <div class="sr-hdr">
        <h4><i class="fa-solid fa-user-clock"></i>Staff Attendance Regularization</h4>
        <p>Review and approve GPS self-attendance correction requests filed by staff from the app. Approving stamps the corrected day onto their attendance record.</p>
    </div>

    <div class="sr-tabs" id="srTabs">
        <button class="sr-tab active" data-status="pending">Pending</button>
        <button class="sr-tab" data-status="approved">Approved</button>
        <button class="sr-tab" data-status="rejected">Rejected</button>
        <button class="sr-tab" data-status="all">All</button>
    </div>

    <div class="sr-list" id="srList">
        <div class="sr-loading">Loading requests…</div>
    </div>
</div>

<div class="sr-toast" id="srToast"></div>

<script>
(function () {
    var LIST_URL   = '<?= site_url('attendance/staff_regularization/list') ?>';
    var DECIDE_URL = '<?= site_url('attendance/staff_regularization/decide') ?>';
    var state = { status: 'pending' };
    var $list = $('#srList');

    function toast(msg) {
        var t = document.getElementById('srToast');
        t.textContent = msg; t.classList.add('show');
        setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function fmtTime(iso) {
        if (!iso) return '';
        try { var d = new Date(iso); if (isNaN(d)) return ''; return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}); }
        catch (e) { return ''; }
    }

    function render(batches) {
        var keys = Object.keys(batches || {});
        if (!keys.length) { $list.html('<div class="sr-empty">No ' + esc(state.status) + ' requests.</div>'); return; }
        var html = '';
        keys.forEach(function (bid) {
            var items = batches[bid];
            if (!items || !items.length) return;
            var first = items[0];
            var status = first.status || 'pending';
            var datesHtml = items.map(function (it) {
                var ci = fmtTime(it.claimedCheckInAt), co = fmtTime(it.claimedCheckOutAt);
                var times = (ci || co) ? ' <small>' + esc(ci) + (co ? '–' + esc(co) : '') + '</small>' : '';
                return '<span class="sr-date">' + esc(it.date) + times + '</span>';
            }).join('');
            var actions = '';
            if (status === 'pending') {
                actions =
                    '<div class="sr-actions">' +
                        '<select class="sr-mark" title="Mark to apply on approval">' +
                            '<option value="">Present (default)</option>' +
                            '<option value="T">Late (T)</option>' +
                            '<option value="M">Half-day (M)</option>' +
                            '<option value="L">Leave (L)</option>' +
                            '<option value="W">Work-on-off (W)</option>' +
                        '</select>' +
                        '<input class="sr-remarks" type="text" placeholder="Remarks (optional)">' +
                        '<button class="sr-btn reject" data-batch="' + esc(bid) + '" data-decision="reject">Reject</button>' +
                        '<button class="sr-btn approve" data-batch="' + esc(bid) + '" data-decision="approve">Approve</button>' +
                    '</div>';
            } else if (first.remarks) {
                actions = '<div class="sr-reason"><em>Remarks: ' + esc(first.remarks) + '</em></div>';
            }
            html +=
                '<div class="sr-card" data-card="' + esc(bid) + '">' +
                    '<div class="sr-card-top">' +
                        '<span class="sr-name">' + esc(first.staffName || first.staffId) + '</span>' +
                        '<span class="sr-sid">' + esc(first.staffId) + '</span>' +
                        '<span class="sr-pill ' + esc(status) + '">' + esc(status) + '</span>' +
                    '</div>' +
                    '<div class="sr-reason">' + esc(first.reason || '(no reason given)') + '</div>' +
                    '<div class="sr-dates">' + datesHtml + '</div>' +
                    actions +
                '</div>';
        });
        $list.html(html);
    }

    function load() {
        $list.html('<div class="sr-loading">Loading requests…</div>');
        $.post(LIST_URL, { status: state.status }, function (res) {
            if (res && res.status === 'success') { render((res.data && res.data.batches) || {}); }
            else { $list.html('<div class="sr-empty">Could not load requests.</div>'); }
        }, 'json').fail(function () {
            $list.html('<div class="sr-empty">Could not load requests.</div>');
        });
    }

    $('#srTabs').on('click', '.sr-tab', function () {
        $('#srTabs .sr-tab').removeClass('active'); $(this).addClass('active');
        state.status = $(this).data('status'); load();
    });

    $list.on('click', '.sr-btn', function () {
        var $btn = $(this), bid = $btn.data('batch'), decision = $btn.data('decision');
        if ($btn.prop('disabled')) return;
        if (decision === 'reject' && !confirm('Reject this regularization request?')) return;
        var $card = $btn.closest('.sr-card');
        var remarks = $card.find('.sr-remarks').val() || '';
        var mark = $card.find('.sr-mark').val() || '';
        $card.find('.sr-btn').prop('disabled', true);
        $.post(DECIDE_URL, { batch_id: bid, decision: decision, remarks: remarks, mark: mark }, function (res) {
            if (res && res.status === 'success') {
                var d = res.data || {};
                var msg = decision === 'approve'
                    ? ('Approved ' + (d.appliedCount || 0) + ' day(s)' + (d.skippedCount ? ', ' + d.skippedCount + ' skipped' : ''))
                    : 'Request rejected';
                toast(msg);
                load();
            } else {
                toast((res && res.message) || 'Action failed');
                $card.find('.sr-btn').prop('disabled', false);
            }
        }, 'json').fail(function () {
            toast('Action failed'); $card.find('.sr-btn').prop('disabled', false);
        });
    });

    load();
})();
</script>
