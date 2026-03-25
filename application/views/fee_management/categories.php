<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── Fee Heads — matches existing module design system ── */
.fh-wrap{padding:24px 22px 52px;min-height:100vh}

/* Header */
.fh-head{display:flex;align-items:center;gap:14px;padding:18px 22px;margin-bottom:22px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r,10px);box-shadow:var(--sh)}
.fh-head-icon{width:44px;height:44px;border-radius:10px;background:var(--gold);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 0 18px var(--gold-glow)}
.fh-head-icon i{color:#fff;font-size:18px}
.fh-head-info{flex:1}
.fh-head-title{font-size:18px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.fh-head-sub{font-size:12px;color:var(--t3);margin-top:2px}
.fh-head-sub a{color:var(--gold);font-weight:600;text-decoration:none}
.fh-head-sub a:hover{text-decoration:underline}

/* Stats row */
.fh-stats{display:flex;gap:12px;margin-bottom:22px;flex-wrap:wrap}
.fh-stat{flex:1;min-width:130px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r,10px);padding:16px 18px;display:flex;align-items:center;gap:12px;box-shadow:var(--sh)}
.fh-stat-ic{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.fh-stat-ic.ic-all{background:var(--gold-dim);color:var(--gold)}
.fh-stat-ic.ic-m{background:rgba(22,163,74,.1);color:#16a34a}
.fh-stat-ic.ic-y{background:rgba(217,119,6,.1);color:#d97706}
.fh-stat-v{font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-b);line-height:1.1}
.fh-stat-l{font-size:10.5px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.4px;margin-top:2px}

/* Add card */
.fh-add-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r,10px);box-shadow:var(--sh);margin-bottom:22px;overflow:hidden}
.fh-add-hd{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.fh-add-hd i{color:var(--gold);font-size:14px}
.fh-add-hd span{font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.fh-add-body{padding:16px 18px}
.fh-add-form{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}

/* Fields */
.fh-fg{display:flex;flex-direction:column;gap:4px}
.fh-fg.fg-name{flex:1;min-width:200px}
.fh-fg.fg-type{flex:0 0 155px}
.fh-fg label{font-size:10.5px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}
.fh-fg input,.fh-fg select{padding:8px 12px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:13px;font-family:var(--font-b);outline:none;transition:border-color .15s;height:38px}
.fh-fg input:focus,.fh-fg select:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}
.fh-fg input::placeholder{color:var(--t3)}
.fh-fg select{cursor:pointer;-webkit-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2394a3b8' d='M5 7L1 3h8z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}

/* Buttons */
.fh-btn{padding:8px 18px;border:none;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:var(--font-b);transition:all .15s;display:inline-flex;align-items:center;gap:7px;height:38px}
.fh-btn-p{background:var(--gold);color:#fff}
.fh-btn-p:hover{background:var(--gold2)}
.fh-btn-s{background:var(--bg3);color:var(--t2);border:1px solid var(--border)}
.fh-btn-s:hover{border-color:var(--gold);color:var(--gold)}
.fh-btn-d{background:transparent;color:#dc2626;border:1px solid #fca5a5}
.fh-btn-d:hover{background:#fee2e2}
.fh-btn:disabled{opacity:.5;cursor:not-allowed}
.fh-btn-sm{padding:5px 10px;font-size:11px;height:30px}

/* Table card */
.fh-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r,10px);box-shadow:var(--sh);overflow:hidden}
.fh-card-hd{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fh-card-hd h3{margin:0;font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.fh-card-hd i{color:var(--gold);font-size:14px}
.fh-card-hd .fh-count{margin-left:auto;font-size:11px;font-weight:700;background:var(--gold-dim);color:var(--gold);padding:3px 10px;border-radius:12px}

/* Table */
.fh-table-wrap{overflow-x:auto}
.fh-table{width:100%;border-collapse:collapse;font-size:13px}
.fh-table th{background:var(--bg3);color:var(--t3);font-family:var(--font-m);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border);font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;position:sticky;top:0;z-index:1}
.fh-table td{padding:10px 14px;border-bottom:1px solid var(--border);color:var(--t1);vertical-align:middle}
.fh-table tr:last-child td{border-bottom:none}
.fh-table tr:hover td{background:var(--gold-dim)}

.fh-th-num{width:50px}
.fh-th-type{width:130px}
.fh-th-act{width:70px;text-align:right}

.fh-cell-num{color:var(--t3);font-size:12px;font-weight:500}
.fh-cell-name{font-weight:700;font-size:13.5px;color:var(--t1)}
.fh-cell-act{text-align:right}

/* Badges */
.fh-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700}
.fh-badge-m{background:rgba(22,163,74,.1);color:#16a34a}
.fh-badge-y{background:rgba(217,119,6,.1);color:#d97706}

/* Icon button */
.fh-icon-btn{width:30px;height:30px;border:1px solid var(--border);background:transparent;border-radius:6px;color:var(--t3);cursor:pointer;font-size:12px;transition:all .12s;display:inline-flex;align-items:center;justify-content:center}
.fh-icon-btn:hover{color:#dc2626;border-color:#fca5a5;background:#fee2e2}

/* Empty */
.fh-empty{text-align:center;padding:40px 20px;color:var(--t3);font-size:13px}
.fh-empty i.fa-inbox{font-size:32px;display:block;margin-bottom:10px;opacity:.3}
.fh-empty p{margin:6px 0 16px;font-size:12.5px;color:var(--t3)}
.fh-msg{text-align:center;padding:30px 14px;color:var(--t3);font-size:13px}

/* Next step bar */
.fh-next{display:flex;align-items:center;gap:12px;padding:14px 18px;margin-top:18px;background:var(--gold-dim);border:1px solid rgba(15,118,110,.2);border-radius:var(--r,10px);font-size:12.5px;color:var(--t2)}
.fh-next i{color:var(--gold);font-size:14px;flex-shrink:0}
.fh-next a{color:var(--gold);font-weight:700;text-decoration:none}
.fh-next a:hover{text-decoration:underline}

/* Dialog */
.fh-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99998;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:all .2s}
.fh-overlay.on{opacity:1;visibility:visible}
.fh-dlg{background:var(--bg2);border-radius:12px;box-shadow:0 16px 48px rgba(0,0,0,.18);padding:30px;width:380px;max-width:92vw;text-align:center;transform:scale(.92);transition:transform .2s}
.fh-overlay.on .fh-dlg{transform:scale(1)}
.fh-dlg-icon{font-size:36px;color:#dc2626;margin-bottom:14px}
.fh-dlg p{font-size:13.5px;color:var(--t2);margin:0 0 22px;line-height:1.5}
.fh-dlg-btns{display:flex;gap:10px;justify-content:center}

/* Toast */
.fh-toast-box{position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.fh-toast{padding:10px 18px;border-radius:8px;color:#fff;font-size:12.5px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);pointer-events:auto;display:flex;align-items:center;gap:8px;animation:fhSlide .2s ease;transition:opacity .3s;font-family:var(--font-b);background:#16a34a}
.fh-toast-err{background:#dc2626}
@keyframes fhSlide{from{transform:translateX(20px);opacity:0}to{transform:none;opacity:1}}

/* Responsive */
@media(max-width:768px){
    .fh-wrap{padding:16px 12px 44px}
    .fh-stats{gap:8px}
    .fh-stat{min-width:100px;padding:12px 14px}
    .fh-add-form{flex-direction:column}
    .fh-fg.fg-name,.fh-fg.fg-type{flex:auto;width:100%}
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="fh-wrap">

    <!-- Header -->
    <div class="fh-head">
        <div class="fh-head-icon"><i class="fa fa-money"></i></div>
        <div class="fh-head-info">
            <div class="fh-head-title">Fee Heads</div>
            <div class="fh-head-sub">Define what you charge students. Set amounts per class in <a href="<?= base_url('fees/fees_structure') ?>">Fee Structure</a>.</div>
        </div>
    </div>

    <!-- Stats -->
    <div class="fh-stats">
        <div class="fh-stat">
            <div class="fh-stat-ic ic-all"><i class="fa fa-list"></i></div>
            <div>
                <div class="fh-stat-v" id="sAll">0</div>
                <div class="fh-stat-l">Total</div>
            </div>
        </div>
        <div class="fh-stat">
            <div class="fh-stat-ic ic-m"><i class="fa fa-refresh"></i></div>
            <div>
                <div class="fh-stat-v" id="sM">0</div>
                <div class="fh-stat-l">Monthly</div>
            </div>
        </div>
        <div class="fh-stat">
            <div class="fh-stat-ic ic-y"><i class="fa fa-calendar-check-o"></i></div>
            <div>
                <div class="fh-stat-v" id="sY">0</div>
                <div class="fh-stat-l">Yearly</div>
            </div>
        </div>
    </div>

    <!-- Add Form -->
    <div class="fh-add-card">
        <div class="fh-add-hd">
            <i class="fa fa-plus-circle"></i>
            <span>Add Fee Head</span>
        </div>
        <div class="fh-add-body">
            <form id="addForm" autocomplete="off" class="fh-add-form">
                <div class="fh-fg fg-name">
                    <label>Fee Head Name</label>
                    <input type="text" id="fName" placeholder="e.g. Tuition Fee" required>
                </div>
                <div class="fh-fg fg-type">
                    <label>Billing Type</label>
                    <select id="fType">
                        <option value="Monthly">Monthly</option>
                        <option value="Yearly">Yearly</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="fh-btn fh-btn-p" id="btnAdd"><i class="fa fa-plus"></i> Add</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="fh-card">
        <div class="fh-card-hd">
            <i class="fa fa-list-ul"></i>
            <h3>All Fee Heads</h3>
            <span class="fh-count" id="countBadge">0</span>
        </div>
        <div class="fh-table-wrap">
            <table class="fh-table" id="fhTable">
                <thead>
                    <tr>
                        <th class="fh-th-num">#</th>
                        <th>Fee Head</th>
                        <th class="fh-th-type">Type</th>
                        <th class="fh-th-act"></th>
                    </tr>
                </thead>
                <tbody id="tbody">
                    <tr><td colspan="4" class="fh-msg"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="fh-empty" id="emptyBox" style="display:none">
            <i class="fa fa-inbox"></i>
            <p>No fee heads yet. Add one above to get started.</p>
            <button class="fh-btn fh-btn-p" onclick="document.getElementById('fName').focus()"><i class="fa fa-plus"></i> Add First Fee Head</button>
        </div>
    </div>

    <!-- Next step -->
    <div class="fh-next" id="nextBar" style="display:none">
        <i class="fa fa-arrow-circle-right"></i>
        <span>Fee heads ready. Now <a href="<?= base_url('fees/fees_structure') ?>">set amounts per class</a> in Fee Structure.</span>
    </div>

</div>
</section>
</div>

<!-- Delete dialog -->
<div class="fh-overlay" id="overlay">
    <div class="fh-dlg">
        <div class="fh-dlg-icon"><i class="fa fa-exclamation-triangle"></i></div>
        <p id="dlgMsg">Delete this fee head?</p>
        <div class="fh-dlg-btns">
            <button class="fh-btn fh-btn-s" onclick="closeDlg()">Cancel</button>
            <button class="fh-btn fh-btn-d" id="btnDel"><i class="fa fa-trash-o"></i> Delete</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toastBox" class="fh-toast-box"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {

var B  = '<?= base_url() ?>';
var CN = document.querySelector('meta[name="csrf-name"]').getAttribute('content');
var CH = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
var heads = [];
var delItem = null;

load();

function load() {
    rpc('GET', 'fee_management/fetch_fee_titles').then(function(r) {
        heads = (r.status === 'success' && r.titles) ? r.titles : [];
        render();
    });
}

function render() {
    var tb = document.getElementById('tbody');
    var em = document.getElementById('emptyBox');
    var tbl = document.getElementById('fhTable');
    var next = document.getElementById('nextBar');
    var badge = document.getElementById('countBadge');

    var m = 0, y = 0;
    heads.forEach(function(h) { h.type === 'Monthly' ? m++ : y++; });
    document.getElementById('sAll').textContent = heads.length;
    document.getElementById('sM').textContent = m;
    document.getElementById('sY').textContent = y;
    badge.textContent = heads.length;

    if (!heads.length) {
        tbl.style.display = 'none';
        em.style.display = '';
        next.style.display = 'none';
        return;
    }
    tbl.style.display = '';
    em.style.display = 'none';
    next.style.display = '';

    var html = '';
    heads.forEach(function(h, i) {
        var cls = h.type === 'Monthly' ? 'fh-badge-m' : 'fh-badge-y';
        var ico = h.type === 'Monthly' ? 'fa-refresh' : 'fa-calendar-check-o';
        html += '<tr>'
            + '<td class="fh-cell-num">' + (i + 1) + '</td>'
            + '<td class="fh-cell-name">' + esc(h.title) + '</td>'
            + '<td><span class="fh-badge ' + cls + '"><i class="fa ' + ico + '"></i> ' + h.type + '</span></td>'
            + '<td class="fh-cell-act"><button class="fh-icon-btn" onclick="confirmDel(\'' + escQ(h.title) + '\',\'' + h.type + '\')" title="Delete"><i class="fa fa-trash-o"></i></button></td>'
            + '</tr>';
    });
    tb.innerHTML = html;
}

/* Add */
document.getElementById('addForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var inp = document.getElementById('fName');
    var name = inp.value.trim();
    if (!name) return;

    var btn = document.getElementById('btnAdd');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

    rpc('POST', 'fee_management/save_fee_title', { fee_title: name, fee_type: document.getElementById('fType').value })
    .then(function(r) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-plus"></i> Add';
        if (r.status !== 'success') { toast(r.message || 'Failed', 1); return; }
        inp.value = '';
        inp.focus();
        toast('"' + name + '" added');
        load();
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-plus"></i> Add';
        toast('Server error', 1);
    });
});

/* Delete */
window.confirmDel = function(t, ty) {
    delItem = { title: t, type: ty };
    document.getElementById('dlgMsg').textContent = 'Delete "' + t + '"? This will remove it from fee structures.';
    document.getElementById('overlay').classList.add('on');
};
window.closeDlg = function() { delItem = null; document.getElementById('overlay').classList.remove('on'); };
document.getElementById('overlay').addEventListener('click', function(e) { if (e.target === this) closeDlg(); });

document.getElementById('btnDel').addEventListener('click', function() {
    if (!delItem) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    rpc('POST', 'fee_management/delete_fee_title', { fee_title: delItem.title, fee_type: delItem.type })
    .then(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-trash-o"></i> Delete';
        closeDlg();
        toast('Deleted');
        load();
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-trash-o"></i> Delete';
        closeDlg();
        toast('Failed', 1);
    });
});

/* Helpers */
function rpc(method, url, data) {
    var opts = { method: method, headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (data) {
        var fd = new FormData();
        fd.append(CN, CH);
        for (var k in data) fd.append(k, data[k]);
        opts.body = fd;
    }
    return fetch(B + url, opts).then(function(r) { return r.json(); }).then(function(r) {
        if (r.csrf_token) CH = r.csrf_token;
        return r;
    });
}

function toast(msg, err) {
    var el = document.createElement('div');
    el.className = 'fh-toast' + (err ? ' fh-toast-err' : '');
    el.innerHTML = '<i class="fa ' + (err ? 'fa-times-circle' : 'fa-check-circle') + '"></i> ' + esc(msg);
    document.getElementById('toastBox').appendChild(el);
    setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 300); }, 3000);
}

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function escQ(s) { return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }

});
</script>
