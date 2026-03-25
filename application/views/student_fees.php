<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * student_fees.php
 *
 * BUGS FIXED vs old version:
 *
 * 1. fetch_fee_receipts 403 Forbidden
 *    Old: sent raw JSON body → CI CSRF filter can't read it → 403
 *    Fix: use FormData via postForm() so CSRF token is in $_POST field,
 *         matching $this->input->post('userId') in the controller.
 *
 * 2. "Please enter a valid numeric User ID" error for STU0006
 *    Old: validated userId with isNaN(parseInt(userId)) — rejected "STU0006"
 *    Fix: accept any non-empty string; controller does its own validation.
 *
 * 3. "Error fetching data: SyntaxError: Unexpected token '<'"
 *    Root cause was the 403 returning an HTML error page.
 *    Resolved by fixing #1 above.
 */
?>

<div class="content-wrapper">
    <div class="sfr-page">

        <!-- ════════════════════════════════════
         PAGE HEADER
         ════════════════════════════════════ -->
        <div class="sfr-page-hd">
            <h1 class="sfr-page-title">
                <i class="fa fa-history"></i>
                Student Fee Receipts
            </h1>
            <ol class="sfr-breadcrumb">
                <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li><a href="<?= site_url('fees/fees_records') ?>">Fees</a></li>
                <li>Student Fee Receipts</li>
            </ol>
        </div>

        <!-- ════════════════════════════════════
         STAT CARDS — hidden until data loads
         ════════════════════════════════════ -->
        <div class="sfr-stats" id="sfrStats">
            <div class="sfr-stat">
                <div class="sfr-stat-ico ico-teal"><i class="fa fa-list-ol"></i></div>
                <div>
                    <div class="sfr-stat-label">Total Receipts</div>
                    <div class="sfr-stat-val" id="sfrStCount">0</div>
                </div>
            </div>
            <div class="sfr-stat">
                <div class="sfr-stat-ico ico-green"><i class="fa fa-inr"></i></div>
                <div>
                    <div class="sfr-stat-label">Total Paid</div>
                    <div class="sfr-stat-val c-paid" id="sfrStPaid">₹ 0</div>
                </div>
            </div>
            <div class="sfr-stat">
                <div class="sfr-stat-ico ico-red"><i class="fa fa-exclamation-circle"></i></div>
                <div>
                    <div class="sfr-stat-label">Total Fine</div>
                    <div class="sfr-stat-val c-fine" id="sfrStFine">₹ 0</div>
                </div>
            </div>
            <div class="sfr-stat">
                <div class="sfr-stat-ico ico-amber"><i class="fa fa-tag"></i></div>
                <div>
                    <div class="sfr-stat-label">Total Discount</div>
                    <div class="sfr-stat-val c-disc" id="sfrStDisc">₹ 0</div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════
         SEARCH CARD
         ════════════════════════════════════ -->
        <div class="sfr-card">
            <div class="sfr-card-hd">
                <div class="sfr-card-hd-left">
                    <i class="fa fa-search"></i>
                    <h3>Look Up Student</h3>
                </div>
            </div>
            <div class="sfr-card-body">
                <div class="sfr-search-row">
                    <div class="sfr-field">
                        <label class="sfr-field-lbl">Student ID <span>*</span></label>
                        <div class="sfr-input-wr">
                            <i class="fa fa-id-card-o sfr-input-ico"></i>
                            <!-- accepts STU0006, STU0007 — not numeric-only -->
                            <input type="text" id="sfUserId" class="sfr-input" placeholder="e.g. STU0006, STU0007…"
                                autocomplete="off">
                        </div>
                    </div>
                    <button class="sfr-btn sfr-btn-primary" onclick="loadReceipts()">
                        <i class="fa fa-search"></i> Fetch Receipts
                    </button>
                    <button class="sfr-btn sfr-btn-ghost" onclick="clearSearch()">
                        <i class="fa fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════
         STUDENT BANNER — dark navy gradient
         Replaces the broken border-left strip
         ════════════════════════════════════ -->
        <div class="sfr-banner" id="sfStudentStrip">
            <div class="sfr-banner-inner">
                <div class="sfr-avatar" id="sfAvatar">??</div>
                <div class="sfr-banner-fields">
                    <div class="sfr-bf">
                        <div class="sfr-bf-lbl">Student ID</div>
                        <div class="sfr-bf-val teal" id="sfDispId">—</div>
                    </div>
                    <div class="sfr-bf">
                        <div class="sfr-bf-lbl">Name</div>
                        <div class="sfr-bf-val" id="sfDispName">—</div>
                    </div>
                    <div class="sfr-bf">
                        <div class="sfr-bf-lbl">Father's Name</div>
                        <div class="sfr-bf-val" id="sfDispFather">—</div>
                    </div>
                    <div class="sfr-bf">
                        <div class="sfr-bf-lbl">Class &amp; Section</div>
                        <div class="sfr-bf-val" id="sfDispClass">—</div>
                    </div>
                    <div class="sfr-bf">
                        <div class="sfr-bf-lbl">Receipts</div>
                        <div class="sfr-bf-val amber" id="sfDispCount">0</div>
                    </div>
                    <div class="sfr-bf">
                        <div class="sfr-bf-lbl">Total Paid</div>
                        <div class="sfr-bf-val green" id="sfDispTotal">₹ 0.00</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════
         PAYMENT HISTORY CARD
         ════════════════════════════════════ -->
        <div class="sfr-card" id="sfReceiptsCard">
            <div class="sfr-card-hd">
                <div class="sfr-card-hd-left">
                    <i class="fa fa-file-text-o"></i>
                    <h3>Payment History</h3>
                </div>
                <span class="sfr-count" id="sfRowCount"></span>
            </div>

            <!-- Inline filter — appears only when > 5 records -->
            <div class="sfr-filter-bar" id="sfFilterBar">
                <div class="sfr-filter-wr">
                    <i class="fa fa-search sfr-filter-ico"></i>
                    <input type="text" class="sfr-filter-inp" id="sfTableSearch"
                        placeholder="Filter by date, mode, reference…" autocomplete="off">
                </div>
                <span class="sfr-count" id="sfFilterCount"></span>
            </div>

            <div class="sfr-tbl-wr">
                <table class="sfr-tbl">
                    <thead>
                        <tr>
                            <th style="width:44px;">#</th>
                            <th>Receipt No.</th>
                            <th>Date</th>
                            <th>Student &amp; Father</th>
                            <th>Class</th>
                            <th>Amount Paid</th>
                            <th>Fine</th>
                            <th>Discount</th>
                            <th>Mode</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody id="sfReceiptsTbody">
                        <tr>
                            <td colspan="10" class="sfr-state">
                                <i class="fa fa-search sfr-state-ico"></i>
                                <p class="sfr-state-ttl">No student selected</p>
                                <p class="sfr-state-sub">Enter a student ID above and click Fetch Receipts.</p>
                            </td>
                        </tr>
                    </tbody>
                    <!-- Footer: solid dark navy, teal top border, coloured totals -->
                    <tfoot id="sfReceiptsFoot">
                        <tr>
                            <td class="sfr-tfoot-lbl" colspan="5">TOTALS</td>
                            <td class="sfr-tfoot-paid" id="sfFootAmt">₹ 0.00</td>
                            <td class="sfr-tfoot-fine" id="sfFootFin">₹ 0.00</td>
                            <td class="sfr-tfoot-disc" id="sfFootDis">₹ 0.00</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div><!-- /.sfr-page -->
</div><!-- /.content-wrapper -->

<div class="sfr-toasts" id="sfToastWrap"></div>


<script>
/* ═══════════════════════════════════════════════════════════
   All existing bug fixes retained:
   ① postForm() — CSRF token in FormData $_POST → no 403
   ② No isNaN check — STU0006 accepted
   ③ SyntaxError resolved because ① stops the HTML 403 response
   ═══════════════════════════════════════════════════════════ */

var CSRF_NAME = (document.querySelector('meta[name="csrf-name"]') || {}).content || '<?= $this->security->get_csrf_token_name() ?>';
var CSRF_HASH = (document.querySelector('meta[name="csrf-token"]') || {}).content || '<?= $this->security->get_csrf_hash() ?>';
var SITE_URL = '<?= rtrim(site_url(), '/') ?>';

/* ── Helpers ── */
function esc(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s || ''));
    return d.innerHTML;
}
function fmtRs(n) {
    n = parseFloat(String(n || 0).replace(/,/g, '')) || 0;
    return '₹ ' + n.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function initials(s) {
    var p = String(s || '').trim().split(/\s+/);
    return p.length >= 2 ? (p[0][0] + p[1][0]).toUpperCase() : (p[0] || '?').slice(0, 2).toUpperCase();
}

function toast(msg, type) {
    type = type || 'success';
    var ico = {
        success: 'check-circle',
        error: 'times-circle',
        warning: 'exclamation-triangle'
    } [type];
    var el = document.createElement('div');
    el.className = 'sfr-toast t-' + type;
    el.innerHTML = '<i class="fa fa-' + ico + '"></i> ' + esc(msg);
    document.getElementById('sfToastWrap').appendChild(el);
    setTimeout(function() {
        el.classList.add('sfr-toast-hide');
        setTimeout(function() {
            el.remove();
        }, 320);
    }, 3500);
}

/* CSRF in FormData body (layer 1: CI filter reads $_POST)
   + X-CSRF-Token header (layer 2: MY_Controller check) */
function postForm(url, params) {
    var fd = new FormData();
    fd.append(CSRF_NAME, CSRF_HASH);
    if (params) Object.keys(params).forEach(function(k) {
        fd.append(k, params[k]);
    });
    return fetch(url, {
        method: 'POST',
        body: fd,
        headers: {
            'X-CSRF-Token': CSRF_HASH,
            'X-Requested-With': 'XMLHttpRequest'
        }
    }).then(function(r) {
        if (!r.ok) return r.text().then(function(t) {
            throw new Error('HTTP ' + r.status + ' — ' + t.slice(0, 120));
        });
        return r.json();
    });
}

/* Inline table filter */
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('sfTableSearch').addEventListener('input', function() {
        var q = this.value.toLowerCase();
        var rows = document.querySelectorAll('#sfReceiptsTbody tr[data-s]');
        var n = 0;
        rows.forEach(function(r) {
            var show = !q || r.dataset.s.includes(q);
            r.style.display = show ? '' : 'none';
            if (show) n++;
        });
        document.getElementById('sfFilterCount').textContent = n + ' record(s)';
    });
});

/* ════════════════
   loadReceipts()
   ════════════════ */
function loadReceipts() {
    var userId = document.getElementById('sfUserId').value.trim();
    if (!userId) {
        toast('Please enter a student ID (e.g. STU0006).', 'warning');
        document.getElementById('sfUserId').focus();
        return;
    }

    var tbody = document.getElementById('sfReceiptsTbody');
    tbody.innerHTML =
        '<tr><td colspan="10" class="sfr-state">' +
        '<i class="fa fa-spinner fa-spin sfr-state-ico" style="opacity:.6;"></i>' +
        '<p class="sfr-state-ttl">Loading…</p>' +
        '<p class="sfr-state-sub">Fetching records for <strong>' + esc(userId) + '</strong></p>' +
        '</td></tr>';

    document.getElementById('sfReceiptsFoot').style.display = 'none';
    document.getElementById('sfFilterBar').style.display = 'none';
    document.getElementById('sfRowCount').textContent = '';
    document.getElementById('sfStudentStrip').classList.remove('visible');
    document.getElementById('sfrStats').style.display = 'none';

    postForm(SITE_URL + '/fees/fetch_fee_receipts', {
            userId: userId
        })
        .then(function(data) {
            tbody.innerHTML = '';

            if (!Array.isArray(data) || !data.length) {
                tbody.innerHTML =
                    '<tr><td colspan="10" class="sfr-state">' +
                    '<i class="fa fa-inbox sfr-state-ico"></i>' +
                    '<p class="sfr-state-ttl">No payment records found</p>' +
                    '<p class="sfr-state-sub">Student <strong>' + userId +
                    '</strong> has no fee receipts yet.</p>' +
                    '</td></tr>';
                return;
            }

            /* ── Student banner ── */
            var first = data[0];
            var parts = (first.student || '').split('/');
            var name = (parts[0] || '').trim();
            var father = (parts[1] || '').trim();

            document.getElementById('sfAvatar').textContent = initials(name);
            document.getElementById('sfDispId').textContent = first.Id || userId;
            document.getElementById('sfDispName').textContent = name || '—';
            document.getElementById('sfDispFather').textContent = father || '—';
            document.getElementById('sfDispClass').textContent = first.class || '—';
            document.getElementById('sfDispCount').textContent = data.length;
            document.getElementById('sfStudentStrip').classList.add('visible');

            /* ── Rows ── */
            var tAmt = 0,
                tFin = 0,
                tDis = 0;

            data.forEach(function(rec, i) {
                var amt = parseFloat(String(rec.amount || 0).replace(/,/g, '')) || 0;
                var fin = parseFloat(String(rec.fine || 0).replace(/,/g, '')) || 0;
                var dis = parseFloat(String(rec.discount || 0).replace(/,/g, '')) || 0;
                tAmt += amt;
                tFin += fin;
                tDis += dis;

                var searchKey = [rec.receiptNo, rec.date, first.student, rec.class,
                    rec.account, rec.reference
                ].join(' ').toLowerCase();

                var tr = document.createElement('tr');
                tr.setAttribute('data-s', searchKey);
                tr.innerHTML =
                    /* # */
                    '<td class="sfr-td-num">' + (i + 1) + '</td>' +
                    /* Receipt No */
                    '<td><span class="sfr-pill"><i class="fa fa-hashtag"></i>' + esc(rec.receiptNo || '—') +
                    '</span></td>' +
                    /* Date */
                    '<td class="sfr-td-date">' +
                    '<i class="fa fa-calendar-o sfr-td-date-ico"></i>' +
                    esc(rec.date || '—') +
                    '</td>' +
                    /* Student + father — avatar + stacked text */
                    '<td>' +
                    '<div style="display:flex;align-items:center;">' +
                    '<span class="sfr-ico-cell">' + esc(initials(name)) + '</span>' +
                    '<div class="sfr-sname">' + esc(name) +
                    (father ? '<span>S/o ' + esc(father) + '</span>' : '') +
                    '</div>' +
                    '</div>' +
                    '</td>' +
                    /* Class */
                    '<td class="sfr-td-class">' + esc(rec.class || '—') +
                    '</td>' +
                    /* Amount Paid */
                    '<td><span class="c-paid">' + fmtRs(amt) + '</span></td>' +
                    /* Fine — dash if zero */
                    '<td>' + (fin > 0 ? '<span class="c-fine">' + fmtRs(fin) + '</span>' :
                        '<span class="c-mute">—</span>') + '</td>' +
                    /* Discount — dash if zero */
                    '<td>' + (dis > 0 ? '<span class="c-disc">' + fmtRs(dis) + '</span>' :
                        '<span class="c-mute">—</span>') + '</td>' +
                    /* Mode */
                    '<td><span class="sfr-mode">' + esc(rec.account || 'N/A') + '</span></td>' +
                    /* Reference */
                    '<td class="sfr-td-ref">' +
                    esc(rec.reference || '—') +
                    '</td>';

                tbody.appendChild(tr);
            });

            /* ── Footer totals ── */
            document.getElementById('sfFootAmt').textContent = fmtRs(tAmt);
            document.getElementById('sfFootFin').textContent = fmtRs(tFin);
            document.getElementById('sfFootDis').textContent = fmtRs(tDis);
            document.getElementById('sfReceiptsFoot').style.display = ''; /* reveal navy footer */

            /* ── Banner total ── */
            document.getElementById('sfDispTotal').textContent = fmtRs(tAmt);

            /* ── Stat cards ── */
            document.getElementById('sfrStCount').textContent = data.length;
            document.getElementById('sfrStPaid').textContent = fmtRs(tAmt);
            document.getElementById('sfrStFine').textContent = fmtRs(tFin);
            document.getElementById('sfrStDisc').textContent = fmtRs(tDis);
            document.getElementById('sfrStats').style.display = 'grid';

            /* ── Row count + filter ── */
            document.getElementById('sfRowCount').textContent = data.length + ' record(s)';
            document.getElementById('sfFilterCount').textContent = data.length + ' record(s)';
            if (data.length > 5) document.getElementById('sfFilterBar').style.display = 'flex';
        })
        .catch(function(err) {
            tbody.innerHTML =
                '<tr><td colspan="10" class="sfr-state">' +
                '<i class="fa fa-exclamation-circle sfr-state-ico sfr-state-err"></i>' +
                '<p class="sfr-state-ttl sfr-state-err">Failed to load receipts</p>' +
                '<p class="sfr-state-sub">' + esc(err.message || 'Please try again.') + '</p>' +
                '</td></tr>';
            toast('Failed to load receipts.', 'error');
        });
}

/* ════════════════
   clearSearch()
   ════════════════ */
function clearSearch() {
    document.getElementById('sfUserId').value = '';
    document.getElementById('sfTableSearch').value = '';
    document.getElementById('sfReceiptsFoot').style.display = 'none';
    document.getElementById('sfFilterBar').style.display = 'none';
    document.getElementById('sfrStats').style.display = 'none';
    document.getElementById('sfRowCount').textContent = '';
    document.getElementById('sfStudentStrip').classList.remove('visible');
    document.getElementById('sfReceiptsTbody').innerHTML =
        '<tr><td colspan="10" class="sfr-state">' +
        '<i class="fa fa-search sfr-state-ico"></i>' +
        '<p class="sfr-state-ttl">No student selected</p>' +
        '<p class="sfr-state-sub">Enter a student ID above and click Fetch Receipts.</p>' +
        '</td></tr>';
}

/* Auto-load from ?userId=STU0007 */
(function() {
    var uid = new URLSearchParams(window.location.search).get('userId') || '';
    if (uid) {
        document.getElementById('sfUserId').value = uid;
        setTimeout(loadReceipts, 120);
    }
})();

/* Enter key */
document.getElementById('sfUserId').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') loadReceipts();
});
</script>


<style>
/*
 * student_fees.php — Theme-aware CSS using variables from global header.
 *
 * Uses --sfr-* local tokens that resolve to global theme vars (--gold, --bg, --t1, etc.)
 * with hardcoded fallbacks for safety. Automatically adapts to light/dark mode.
 *
 * Font-size scale (rem-based, consistent with other fee views):
 *   Page title:  1.35rem   Breadcrumb: .78rem     Card head: .88rem
 *   Label:       .68rem    Body/Input: .82rem     Table body: .8rem
 *   Table head:  .68rem    Stat value: 1.25rem    Badge/pill: .7rem
 *   Button:      .82rem    Toast:      .8rem
 */

:root {
    --sfr-navy:    var(--bg4, #1a2332);
    --sfr-teal:    var(--gold, #0d9488);
    --sfr-teal2:   var(--gold2, #0f766e);
    --sfr-sky:     var(--gold-dim, #f0fdfa);
    --sfr-green:   #16a34a;
    --sfr-red:     #dc2626;
    --sfr-amber:   #d97706;
    --sfr-text:    var(--t1, #1a2332);
    --sfr-text2:   var(--t2, #374151);
    --sfr-muted:   var(--t3, #9ca3af);
    --sfr-border:  var(--border, #f1f5f9);
    --sfr-bg:      var(--bg, #f4f6f9);
    --sfr-card:    var(--bg2, #ffffff);
    --sfr-card-hd: var(--bg3, #f8fafc);
    --sfr-shadow:  var(--sh, 0 1px 3px rgba(0,0,0,.06));
    --sfr-radius:  12px;
}

/* ── Page ── */
.sfr-page {
    padding: 24px 28px;
    background: var(--sfr-bg);
    min-height: 100vh;
}

/* ── PAGE HEADER ── */
.sfr-page-hd { margin-bottom: 26px; }

.sfr-page-title {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--sfr-text);
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.3px;
    line-height: 1.2;
}
.sfr-page-title i {
    color: var(--sfr-teal);
    font-size: 1.2rem;
}

.sfr-breadcrumb {
    list-style: none;
    padding: 0; margin: 0;
    display: flex; align-items: center; gap: 6px;
    font-size: .78rem;
    color: var(--sfr-muted);
}
.sfr-breadcrumb li + li::before { content: '/'; color: var(--sfr-border); margin-right: 6px; }
.sfr-breadcrumb a {
    color: var(--sfr-teal);
    text-decoration: none; font-weight: 500;
}
.sfr-breadcrumb a:hover { text-decoration: underline; }

/* ── STAT CARDS ── */
.sfr-stats {
    display: none;
    grid-template-columns: repeat(auto-fit, minmax(195px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.sfr-stat {
    background: var(--sfr-card);
    border-radius: var(--sfr-radius);
    padding: 18px 20px;
    display: flex; align-items: center; gap: 16px;
    box-shadow: var(--sfr-shadow);
    border: 1px solid var(--sfr-border);
    transition: transform .18s, box-shadow .18s;
}
.sfr-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,.09);
}
.sfr-stat-ico {
    width: 46px; height: 46px; border-radius: var(--sfr-radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; flex-shrink: 0;
}
.ico-teal  { background: var(--sfr-sky); color: var(--sfr-teal); }
.ico-green { background: #f0fdf4; color: var(--sfr-green); }
.ico-red   { background: #fef2f2; color: var(--sfr-red); }
.ico-amber { background: #fffbeb; color: var(--sfr-amber); }
.sfr-stat-label {
    font-size: .8rem; font-weight: 700; color: var(--sfr-muted);
    text-transform: uppercase; letter-spacing: .6px; margin-bottom: 4px;
}
.sfr-stat-val {
    font-size: 1.25rem; font-weight: 800; color: var(--sfr-text); line-height: 1;
}

/* ── CARDS ── */
.sfr-card {
    background: var(--sfr-card);
    border-radius: var(--sfr-radius);
    border: 1px solid var(--sfr-border);
    box-shadow: var(--sfr-shadow);
    margin-bottom: 20px;
    overflow: hidden;
}
.sfr-card-hd {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px;
    background: var(--sfr-card-hd);
    border-bottom: 1.5px solid var(--sfr-border);
    gap: 12px; flex-wrap: wrap;
}
.sfr-card-hd-left { display: flex; align-items: center; gap: 10px; }
.sfr-card-hd-left i { color: var(--sfr-teal); font-size: .92rem; }
.sfr-card-hd-left h3 {
    margin: 0; font-size: .88rem; font-weight: 700;
    color: var(--sfr-text);
    letter-spacing: -.1px;
}
.sfr-card-body { padding: 20px; }

/* ── SEARCH ROW ── */
.sfr-search-row {
    display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;
}
.sfr-field { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 220px; }
.sfr-field-lbl {
    font-size: .8rem; font-weight: 700; color: var(--sfr-text2);
    text-transform: uppercase; letter-spacing: .55px;
}
.sfr-field-lbl span { color: var(--sfr-red); }
.sfr-input-wr { position: relative; }
.sfr-input-ico {
    position: absolute; left: 12px; top: 50%;
    transform: translateY(-50%); color: var(--sfr-muted); font-size: .8rem; pointer-events: none;
}
.sfr-input {
    width: 100%; box-sizing: border-box;
    padding: 10px 14px 10px 36px;
    border: 1.5px solid var(--sfr-border);
    border-radius: 8px;
    font-size: .82rem; font-weight: 500;
    color: var(--sfr-text);
    background: var(--sfr-card);
    outline: none;
    transition: border-color .18s, box-shadow .18s;
}
.sfr-input:focus {
    border-color: var(--sfr-teal);
    box-shadow: 0 0 0 3px rgba(13,148,136,.13);
}
.sfr-input::placeholder { color: var(--sfr-muted); opacity: .6; }

.sfr-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 22px; border-radius: 8px;
    font-size: .82rem; font-weight: 700;
    cursor: pointer; border: none;
    transition: all .18s; white-space: nowrap; line-height: 1;
}
.sfr-btn-primary {
    background: var(--sfr-teal);
    color: #fff;
    box-shadow: 0 2px 8px rgba(13,148,136,.28);
}
.sfr-btn-primary:hover {
    background: var(--sfr-teal2);
    box-shadow: 0 4px 16px rgba(13,148,136,.38);
    transform: translateY(-1px);
}
.sfr-btn-ghost {
    background: var(--sfr-card);
    color: var(--sfr-text2);
    border: 1.5px solid var(--sfr-border);
}
.sfr-btn-ghost:hover { border-color: var(--sfr-teal); color: var(--sfr-teal); background: var(--sfr-sky); }

/* ── STUDENT INFO BANNER ── */
.sfr-banner {
    display: none;
    background: linear-gradient(135deg, var(--sfr-navy) 0%, #22304a 55%, #1e3a5a 100%);
    border-radius: var(--sfr-radius);
    padding: 20px 26px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(26,35,50,.3);
    position: relative; overflow: hidden;
}
.sfr-banner::before {
    content: ''; position: absolute;
    right: -40px; top: -40px;
    width: 200px; height: 200px;
    background: rgba(13,148,136,.1); border-radius: 50%; pointer-events: none;
}
.sfr-banner::after {
    content: ''; position: absolute;
    right: 80px; bottom: -60px;
    width: 140px; height: 140px;
    background: rgba(13,148,136,.06); border-radius: 50%; pointer-events: none;
}
.sfr-banner.visible { display: block; }

.sfr-banner-inner {
    display: flex; align-items: center; flex-wrap: wrap; gap: 0; position: relative; z-index: 1;
}
.sfr-avatar {
    width: 52px; height: 52px; border-radius: 14px;
    background: var(--sfr-teal);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; font-weight: 800; color: #fff;
    flex-shrink: 0; margin-right: 22px;
    box-shadow: 0 2px 10px rgba(0,0,0,.22);
}
.sfr-banner-fields { display: flex; align-items: center; flex-wrap: wrap; gap: 0; flex: 1; }
.sfr-bf {
    padding: 0 22px;
    border-right: 1px solid rgba(255,255,255,.1);
}
.sfr-bf:first-child { padding-left: 0; }
.sfr-bf:last-child  { border-right: none; }
.sfr-bf-lbl {
    font-size: .85rem; color: rgba(255,255,255,.5);
    text-transform: uppercase; letter-spacing: .7px; font-weight: 600; margin-bottom: 5px;
}
.sfr-bf-val { font-size: .88rem; font-weight: 700; color: #fff; white-space: nowrap; }
.sfr-bf-val.teal  { color: #5eead4; }
.sfr-bf-val.green { color: #86efac; }
.sfr-bf-val.amber { color: #fcd34d; }

/* ── FILTER BAR ── */
.sfr-filter-bar {
    display: none; align-items: center; justify-content: space-between;
    padding: 12px 20px; border-bottom: 1px solid var(--sfr-border);
    background: var(--sfr-card-hd); gap: 12px; flex-wrap: wrap;
}
.sfr-filter-wr { position: relative; }
.sfr-filter-ico {
    position: absolute; left: 11px; top: 50%;
    transform: translateY(-50%); color: var(--sfr-muted); font-size: .8rem; pointer-events: none;
}
.sfr-filter-inp {
    padding: 8px 14px 8px 32px;
    border: 1.5px solid var(--sfr-border); border-radius: 8px;
    font-size: .8rem; color: var(--sfr-text); background: var(--sfr-card);
    outline: none; transition: border-color .18s; min-width: 240px;
}
.sfr-filter-inp:focus { border-color: var(--sfr-teal); }
.sfr-count { font-size: .85rem; color: var(--sfr-muted); font-weight: 600; white-space: nowrap; }

/* ── TABLE ── */
.sfr-tbl-wr { overflow-x: auto; }
.sfr-tbl { width: 100%; border-collapse: collapse; font-size: .8rem; }

.sfr-tbl thead tr { background: var(--sfr-navy); }
.sfr-tbl thead th {
    padding: 13px 16px;
    font-size: .8rem; font-weight: 700; color: #fff;
    text-transform: uppercase; letter-spacing: .65px;
    white-space: nowrap; border: none; text-align: left;
}

.sfr-tbl tbody tr { border-bottom: 1px solid var(--sfr-border); transition: background .14s; }
.sfr-tbl tbody tr:last-child { border-bottom: none; }
.sfr-tbl tbody tr:hover { background: var(--sfr-sky); }
.sfr-tbl td { padding: 13px 16px; vertical-align: middle; color: var(--sfr-text2); font-size: .8rem; }

/* JS-generated cell classes (replaces inline styles) */
.sfr-td-num  { color: var(--sfr-muted); font-weight: 600; font-size: .85rem; }
.sfr-td-date { white-space: nowrap; color: var(--sfr-text2); }
.sfr-td-date-ico { color: var(--sfr-muted); margin-right: 5px; font-size: .8rem; }
.sfr-td-class { font-size: .85rem; color: var(--sfr-text2); white-space: nowrap; }
.sfr-td-ref  { color: var(--sfr-muted); font-size: .85rem; max-width: 110px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.sfr-sname  { font-weight: 700; color: var(--sfr-text); font-size: .84rem; line-height: 1.3; }
.sfr-sname  span { display: block; font-size: .82rem; color: var(--sfr-muted); font-weight: 400; margin-top: 2px; }

.sfr-ico-cell {
    width: 36px; height: 36px; border-radius: 10px;
    background: var(--sfr-teal); color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 800;
    flex-shrink: 0; margin-right: 10px; vertical-align: middle;
}

.sfr-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px 3px 8px; border-radius: 20px;
    background: var(--sfr-sky); color: var(--sfr-teal2);
    font-size: .7rem; font-weight: 800;
    border: 1px solid rgba(13,148,136,.18); white-space: nowrap;
}
.sfr-pill i { font-size: .82rem; }

.sfr-mode {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    background: var(--sfr-card-hd); color: var(--sfr-text2);
    font-size: .7rem; font-weight: 700; border: 1px solid var(--sfr-border);
}

/* Amount colour classes */
.c-paid { font-weight: 700; color: var(--sfr-green); }
.c-fine { font-weight: 600; color: var(--sfr-red); }
.c-disc { font-weight: 600; color: var(--sfr-amber); }
.c-mute { color: var(--sfr-muted); }

/* ── TABLE FOOTER ── */
.sfr-tbl tfoot { display: none; }
.sfr-tbl tfoot tr {
    background: var(--sfr-navy);
    border-top: 3px solid var(--sfr-teal);
}
.sfr-tbl tfoot td {
    padding: 14px 16px; border: none;
    font-weight: 700; font-size: .8rem; color: #fff;
}
.sfr-tfoot-lbl {
    font-size: .68rem !important; font-weight: 600 !important;
    color: rgba(255,255,255,.55) !important;
    text-transform: uppercase; letter-spacing: .65px;
    text-align: right; padding-right: 20px !important;
}
.sfr-tfoot-paid { color: #86efac !important; font-size: .88rem !important; }
.sfr-tfoot-fine { color: #fca5a5 !important; }
.sfr-tfoot-disc { color: #fcd34d !important; }

/* ── Empty / loading state ── */
.sfr-state {
    text-align: center !important;
    padding: 60px 20px !important;
    color: var(--sfr-muted);
}
.sfr-state-ico {
    font-size: 2.5rem; display: block;
    margin: 0 auto 16px; color: var(--sfr-teal); opacity: .25;
}
.sfr-state-ttl {
    font-size: .92rem; font-weight: 700; color: var(--sfr-text2); margin: 0 0 6px;
}
.sfr-state-sub { font-size: .8rem; color: var(--sfr-muted); margin: 0; }
.sfr-state-err { color: var(--sfr-red) !important; opacity: .7; }

/* ── Toast ── */
.sfr-toasts {
    position: fixed; bottom: 24px; right: 24px;
    z-index: 9999; display: flex; flex-direction: column; gap: 8px;
}
.sfr-toast {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 18px; border-radius: 8px;
    font-size: .8rem; font-weight: 600;
    background: var(--sfr-card);
    box-shadow: 0 4px 20px rgba(0,0,0,.13);
    animation: sfr-in .25s ease; min-width: 240px;
    border: 1px solid var(--sfr-border);
}
.t-success { border-left: 4px solid var(--sfr-green); color: var(--sfr-green); }
.t-error   { border-left: 4px solid var(--sfr-red);   color: var(--sfr-red); }
.t-warning { border-left: 4px solid var(--sfr-amber); color: var(--sfr-amber); }
.sfr-toast-hide { animation: sfr-out .3s ease forwards; }
@keyframes sfr-in  { from { transform: translateX(60px); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes sfr-out { to   { transform: translateX(60px); opacity: 0; } }
</style>

