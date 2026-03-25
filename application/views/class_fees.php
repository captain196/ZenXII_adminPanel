<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// Passed from controller: $classes (array), $sections (assoc: class→[sections]),
// $class (string, selected class from GET param, may be null)
$classes    = $classes  ?? [];
$sections   = $sections ?? [];
$selClass   = $class   ?? '';    // from ?class= URL param
$selSection = $section ?? '';    // from ?section= URL param (passed from fees_records)
?>


<div class="content-wrapper">
    <div class="cf-wrap">

        <!-- ── TOP BAR ── -->
        <div class="cf-topbar">
            <div>
                <h1 class="cf-page-title">
                    <i class="fa fa-users"></i> Class-wise Due Fees
                </h1>
                <ol class="cf-breadcrumb">
                    <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                    <li><a href="#">Fees</a></li>
                    <li>Class Due Fees</li>
                </ol>
            </div>
            <div class="cf-topbar-right">
                <a href="<?= site_url('fees/fees_records') ?>" class="cf-btn cf-btn-navy">
                    <i class="fa fa-bar-chart"></i> Collection Records
                </a>
                <a href="<?= site_url('fees/fees_counter') ?>" class="cf-btn cf-btn-primary">
                    <i class="fa fa-plus"></i> Fee Counter
                </a>
            </div>
        </div>

        <!-- ── LIVE STAT STRIP (updated after AJAX load) ── -->
        <div class="cf-stat-strip" id="statStrip">
            <div class="cf-stat cf-stat-blue">
                <div class="cf-stat-icon"><i class="fa fa-users"></i></div>
                <div class="cf-stat-body">
                    <div class="cf-stat-label">Total Students</div>
                    <div class="cf-stat-val" id="statStudents">—</div>
                </div>
            </div>
            <div class="cf-stat cf-stat-green">
                <div class="cf-stat-icon"><i class="fa fa-inr"></i></div>
                <div class="cf-stat-body">
                    <div class="cf-stat-label">Total Received</div>
                    <div class="cf-stat-val" id="statReceived">—</div>
                </div>
            </div>
            <div class="cf-stat cf-stat-red">
                <div class="cf-stat-icon"><i class="fa fa-exclamation-circle"></i></div>
                <div class="cf-stat-body">
                    <div class="cf-stat-label">Total Due</div>
                    <div class="cf-stat-val" id="statDue">—</div>
                </div>
            </div>
            <div class="cf-stat cf-stat-amber">
                <div class="cf-stat-icon"><i class="fa fa-percent"></i></div>
                <div class="cf-stat-body">
                    <div class="cf-stat-label">Collection Rate</div>
                    <div class="cf-stat-val" id="statRate">—</div>
                    <div class="cf-stat-sub" id="statRateSub"></div>
                </div>
            </div>
        </div>

        <!-- ── SELECTOR CARD ── -->
        <div class="cf-card">
            <div class="cf-card-head">
                <div class="cf-card-head-left">
                    <i class="fa fa-filter"></i>
                    <h3>Select Class &amp; Section</h3>
                </div>
            </div>
            <div class="cf-selector-body">
                <div class="cf-field">
                    <label class="cf-label">Class <span style="color:var(--fc-red)">*</span></label>
                    <div class="cf-select-wrap">
                        <select id="classSelect" class="cf-select">
                            <option value="" disabled selected>— Select Class —</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?= htmlspecialchars($cls) ?>" <?= ($cls === $selClass) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cls) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fa fa-chevron-down cf-select-arr"></i>
                    </div>
                </div>

                <div class="cf-field">
                    <label class="cf-label">Section <span style="color:var(--fc-red)">*</span></label>
                    <div class="cf-select-wrap">
                        <select id="sectionSelect" class="cf-select" disabled>
                            <option value="" disabled selected>— Select Section —</option>
                        </select>
                        <i class="fa fa-chevron-down cf-select-arr"></i>
                    </div>
                </div>

                <button class="cf-btn cf-btn-primary" id="loadBtn" disabled>
                    <i class="fa fa-refresh"></i> Load Students
                </button>
            </div>
        </div>

        <!-- ── RESULTS CARD (hidden until loaded) ── -->
        <div class="cf-card" id="dueFeeResult">
            <div class="cf-card-head">
                <div class="cf-card-head-left">
                    <i class="fa fa-table"></i>
                    <h3 id="resultHeading">Due Fees — Class</h3>
                </div>
                <div class="cf-card-head-right">
                    <button class="cf-btn cf-btn-ghost cf-btn-sm" id="exportDueBtn">
                        <i class="fa fa-download"></i> Export CSV
                    </button>
                </div>
            </div>

            <div class="cf-filter-bar">
                <div class="cf-search-wrap">
                    <i class="fa fa-search cf-search-icon"></i>
                    <input type="text" id="dueSearch" class="cf-search" placeholder="Search student or father name…"
                        autocomplete="off">
                </div>
                <span class="cf-row-count" id="dueRowCount">0 student(s)</span>
            </div>

            <div class="cf-table-wrap">
                <table class="cf-table" id="dueTable">
                    <thead>
                        <tr>
                            <th class="c" style="min-width:46px;">Sr.</th>
                            <th style="min-width:100px;">User ID</th>
                            <th style="min-width:200px;">Student / Guardian</th>
                            <th class="r" style="min-width:110px;">Total Fee</th>
                            <th class="r" style="min-width:110px;">Received</th>
                            <th class="r" style="min-width:100px;">Discount</th>
                            <th class="r" style="min-width:110px;">Due</th>
                            <th class="c" style="min-width:100px;">Status</th>
                            <th class="c" style="min-width:90px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="dueTbody">
                        <tr>
                            <td colspan="9" class="cf-loader">
                                <i class="fa fa-spinner fa-spin"></i>
                                <p>Select a class &amp; section above to load students.</p>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot id="dueTfoot" style="display:none;">
                        <tr>
                            <td colspan="3" style="text-align:left;padding-left:18px;font-weight:700;">Class Total</td>
                            <td class="r" id="footTotal">₹ 0</td>
                            <td class="r cf-foot-green" id="footReceived">₹ 0</td>
                            <td class="r" id="footDiscount" style="color:#fbbf24;">₹ 0</td>
                            <td class="r cf-foot-red" id="footDue">₹ 0</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div><!-- /.cf-wrap -->
</div><!-- /.content-wrapper -->

<!-- Toast container -->
<div class="cf-toast-wrap" id="cfToastWrap"></div>


<script>
var CSRF_NAME  = document.querySelector('meta[name="csrf-name"]').getAttribute('content');
var CSRF_HASH  = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

var SITE_URL       = '<?= rtrim(site_url(), '/') ?>';
var CF_SECTIONS    = <?= json_encode($sections, JSON_UNESCAPED_UNICODE) ?>;
var CF_INIT_CLASS  = <?= json_encode($selClass) ?>;
var CF_INIT_SECTION= <?= json_encode($selSection) ?>;

/* ── Toast ── */
function cfToast(msg, type) {
    var wrap = document.getElementById('cfToastWrap');
    var el   = document.createElement('div');
    type     = type || 'info';
    el.className = 'cf-toast cf-toast-' + type;
    var icons = { success:'check-circle', error:'times-circle', warning:'exclamation-triangle', info:'info-circle' };
    el.innerHTML = '<i class="fa fa-' + (icons[type]||'info-circle') + '"></i> ' + msg;
    wrap.appendChild(el);
    var dur = (type==='error'||type==='warning') ? 6000 : 3000;
    setTimeout(function() {
        el.classList.add('cf-toast-hide');
        setTimeout(function(){ el.remove(); }, 320);
    }, dur);
}

function fmtRs(n) {
    return '₹ ' + parseFloat(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0});
}

/* ── Populate section dropdown when class changes ── */
function populateSections(cls) {
    var sel     = document.getElementById('sectionSelect');
    var loadBtn = document.getElementById('loadBtn');
    sel.innerHTML = '<option value="" disabled selected>— Select Section —</option>';
    sel.disabled  = true;
    loadBtn.disabled = true;

    var secs = CF_SECTIONS[cls] || [];
    if (secs.length === 0) {
        cfToast('No sections found for ' + cls, 'warning');
        return;
    }
    secs.forEach(function(s) {
        var opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s;
        sel.appendChild(opt);
    });
    sel.disabled = false;

    // If only one section, auto-select it
    if (secs.length === 1) {
        sel.value = secs[0];
        loadBtn.disabled = false;
    }
}

/* ── Load due fees via AJAX ── */
function loadDueFees() {
    var cls  = document.getElementById('classSelect').value;
    var sec  = document.getElementById('sectionSelect').value;
    if (!cls || !sec) { cfToast('Please select both class and section.', 'warning'); return; }

    var resultCard = document.getElementById('dueFeeResult');
    var tbody      = document.getElementById('dueTbody');
    var tfoot      = document.getElementById('dueTfoot');
    var heading    = document.getElementById('resultHeading');

    resultCard.style.display = 'block';
    tbody.innerHTML = '<tr><td colspan="9" class="cf-loader"><i class="fa fa-spinner fa-spin"></i><p>Loading student data…</p></td></tr>';
    tfoot.style.display = 'none';
    heading.textContent  = 'Due Fees — ' + cls + ' ' + sec;

    // Reset stats
    ['statStudents','statReceived','statDue','statRate'].forEach(function(id){
        document.getElementById(id).textContent = '…';
    });

    /* FIX: Send CSRF token in both FormData body + header */
    var fd = new FormData();
    fd.append(CSRF_NAME, CSRF_HASH);
    fd.append('class', cls);
    fd.append('section', sec);

    fetch(SITE_URL + '/fees/due_fees_table', {
        method:  'POST',
        body:    fd,
        headers: {
            'X-CSRF-Token':     CSRF_HASH,
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(r) { return r.text(); })
    .then(function(raw) {
        var data;
        try { data = JSON.parse(raw); } catch(e) {
            tbody.innerHTML = '<tr><td colspan="9" class="cf-empty-res"><i class="fa fa-exclamation-triangle"></i><p>Server error — check PHP logs.</p></td></tr>';
            cfToast('Server returned non-JSON response', 'error');
            console.error('due_fees_table raw:', raw.substring(0,400));
            return;
        }

        // Handle structured error (no fee structure)
        if (data && data.error === 'no_fee_structure') {
            tbody.innerHTML = '<tr><td colspan="9" class="cf-empty-res">'
                + '<div style="text-align:center;padding:20px 0">'
                + '<i class="fa fa-exclamation-triangle" style="font-size:28px;color:#d97706;display:block;margin-bottom:10px"></i>'
                + '<strong style="font-size:14px;color:var(--t1)">No Fee Structure Found</strong>'
                + '<p style="font-size:12.5px;color:var(--t3);margin:6px 0 14px">' + (data.message || 'Set up fee titles and chart first.') + '</p>'
                + '<a href="' + SITE_URL + '/fee_management/categories" style="display:inline-block;padding:7px 16px;border-radius:6px;background:var(--gold,#0f766e);color:#fff;text-decoration:none;font-size:12px;font-weight:600;margin-right:8px"><i class="fa fa-list"></i> Fee Categories</a>'
                + '<a href="' + SITE_URL + '/fees/fees_chart" style="display:inline-block;padding:7px 16px;border-radius:6px;border:1.5px solid var(--gold,#0f766e);color:var(--gold,#0f766e);text-decoration:none;font-size:12px;font-weight:600"><i class="fa fa-table"></i> Fee Chart</a>'
                + '</div></td></tr>';
            cfToast('No fee structure found. Set up Fee Titles & Chart first.', 'error');
            return;
        }

        if (!Array.isArray(data) || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="cf-empty-res"><i class="fa fa-users"></i><p>No students found in ' + cls + ' ' + sec + '.</p></td></tr>';
            updateStats([], 0, 0, 0, 0);
            return;
        }

        // Check for error row
        if (data.length === 1 && data[0].userId === null) {
            tbody.innerHTML = '<tr><td colspan="9" class="cf-empty-res"><i class="fa fa-info-circle"></i><p>' + (data[0].name || 'No data available.') + '</p></td></tr>';
            return;
        }

        renderTable(data);
        document.getElementById('dueRowCount').textContent = data.length + ' student(s)';

        // Footer totals
        var totFee = 0, totRec = 0, totDue = 0, totDisc = 0;
        data.forEach(function(r) {
            totFee  += parseFloat(r.totalFee    || 0);
            totRec  += parseFloat(r.receivedFee || 0);
            totDue  += parseFloat(r.dueFee      || 0);
            totDisc += parseFloat(r.discount    || 0);
        });
        document.getElementById('footTotal').textContent    = fmtRs(totFee);
        document.getElementById('footReceived').textContent = fmtRs(totRec);
        document.getElementById('footDiscount').textContent = totDisc > 0 ? fmtRs(totDisc) : '₹ 0';
        document.getElementById('footDue').textContent      = fmtRs(Math.max(0, totDue));
        tfoot.style.display = 'table-row-group';

        updateStats(data, data.length, totRec, totDue, totFee);
        setupSearch(data);
    })
    .catch(function(e) {
        tbody.innerHTML = '<tr><td colspan="9" class="cf-empty-res"><i class="fa fa-wifi"></i><p>Network error. Please retry.</p></td></tr>';
        cfToast('Network error: ' + e.message, 'error');
    });
}

/* ── Render table rows ── */
function renderTable(data) {
    var tbody = document.getElementById('dueTbody');
    tbody.innerHTML = '';

    data.forEach(function(rec, idx) {
        var parts    = (rec.name || '').split(' / ');
        var sName    = parts[0] || rec.name || '—';
        var fName    = parts[1] || '';
        var initials = sName.split(' ').slice(0,2).map(function(w){ return w[0]||''; }).join('').toUpperCase();

        var total    = parseFloat(rec.totalFee    || 0);
        var rcvd     = parseFloat(rec.receivedFee || 0);
        var disc     = parseFloat(rec.discount    || 0);
        var due      = parseFloat(rec.dueFee      || 0);
        var uid      = rec.userId || '—';

        var pct    = total > 0 ? Math.min(100, Math.round((rcvd / total) * 100)) : 0;
        var status, badgeCls;
        if (due < -0.5)        { status = 'Overpaid'; badgeCls = 'cf-badge-over'; }
        else if (due <= 0.5)   { status = 'Paid';     badgeCls = 'cf-badge-paid'; }
        else if (rcvd > 0.5)   { status = 'Partial';  badgeCls = 'cf-badge-partial'; }
        else                   { status = 'Due';       badgeCls = 'cf-badge-due'; }

        var dueClass   = due < -0.5 ? 'over' : (due <= 0.5 ? 'exact' : 'due');
        var dueDisplay = due < -0.5 ? '+' + fmtRs(Math.abs(due)) : (due <= 0.5 ? '₹ 0' : fmtRs(due));

        var fillCls = pct >= 100 ? 'paid' : (pct >= 50 ? 'partial' : (pct > 0 ? 'due' : 'due'));

        var tr = document.createElement('tr');
        tr.className = 'cf-row';
        tr.dataset.name = (sName + ' ' + fName + ' ' + uid).toLowerCase();
        tr.dataset.uid  = uid;

        tr.innerHTML =
            '<td class="c" style="font-weight:600;color:var(--fc-muted);font-size:12px;">' + (idx+1) + '</td>' +
            '<td>' +
                '<span style="font-family:monospace;font-size:12px;background:#f1f5f9;' +
                'padding:2px 7px;border-radius:5px;color:var(--fc-teal);font-weight:600;">' +
                escHtml(uid) + '</span>' +
            '</td>' +
            '<td>' +
                '<div class="cf-name-cell">' +
                    '<div class="cf-avatar">' + initials + '</div>' +
                    '<div class="cf-name-text">' +
                        '<span class="cf-student-name">' + escHtml(sName) + '</span>' +
                        (fName ? '<span class="cf-father-name">S/o ' + escHtml(fName) + '</span>' : '') +
                    '</div>' +
                '</div>' +
            '</td>' +
            '<td class="r cf-amt-total">' + fmtRs(total) + '</td>' +
            '<td class="r cf-amt-received">' + fmtRs(rcvd) + '</td>' +
            '<td class="r" style="color:var(--fc-amber);font-weight:600;">' + (disc > 0 ? fmtRs(disc) : '<span style="color:var(--fc-muted)">—</span>') + '</td>' +
            '<td class="r cf-amt-due ' + dueClass + '">' + dueDisplay + '</td>' +
            '<td class="c"><span class="cf-badge ' + badgeCls + '">' + status + '</span></td>' +
            '<td class="c">' +
                (rec.userId
                    ? '<a href="' + SITE_URL + '/fees/fees_counter?uid=' + encodeURIComponent(rec.userId) + '" class="cf-btn cf-btn-amber cf-btn-sm" title="Collect fees"><i class="fa fa-inr"></i> Collect</a>'
                    : '<span style="color:var(--fc-muted);font-size:12px;">—</span>') +
            '</td>';
        tbody.appendChild(tr);
    });
}

/* ── Update stat cards ── */
function updateStats(data, count, totRec, totDue, totFee) {
    document.getElementById('statStudents').textContent = count;
    document.getElementById('statReceived').textContent = fmtRs(totRec);
    document.getElementById('statDue').textContent      = fmtRs(Math.max(0, totDue));
    var rate = totFee > 0 ? Math.round((totRec / totFee) * 100) : 0;
    document.getElementById('statRate').textContent    = rate + '%';
    document.getElementById('statRateSub').textContent = rate >= 80 ? '✓ Good collection' : (rate >= 50 ? 'In progress' : 'Needs attention');
}

/* ── Live search on due table ── */
var allDueRows = [];
function setupSearch(data) {
    allDueRows = Array.from(document.getElementById('dueTbody').querySelectorAll('.cf-row'));
    var searchEl = document.getElementById('dueSearch');
    var countEl  = document.getElementById('dueRowCount');
    if (!searchEl) return;
    searchEl.value = '';
    searchEl.oninput = function() {
        var q = searchEl.value.toLowerCase().trim();
        var n = 0;
        allDueRows.forEach(function(row) {
            var show = !q || (row.dataset.name || '').includes(q) || (row.dataset.uid || '').toLowerCase().includes(q);
            row.style.display = show ? '' : 'none';
            if (show) n++;
        });
        if (countEl) countEl.textContent = n + ' student(s)';
    };
}

/* ── Export CSV ── */
function exportDueCSV() {
    var rows = Array.from(document.getElementById('dueTbody').querySelectorAll('tr:not([style*="display:none"])'));
    var hdr  = ['Student','Father','Annual Fee','Received','Due','Status'];
    var lines = [hdr.join(',')];
    rows.forEach(function(row) {
        var tds = row.querySelectorAll('td');
        if (!tds.length || tds.length < 5) return;
        var nameCell = tds[0];
        var sName = (nameCell.querySelector('.cf-student-name')||{}).textContent || '';
        var fName = (nameCell.querySelector('.cf-father-name') ||{}).textContent || '';
        fName = fName.replace(/^S\/o\s*/,'');
        var line = [
            '"'+sName+'"', '"'+fName+'"',
            '"'+(tds[1]||{}).textContent.replace(/[₹,\s]/g,'')+'"',
            '"'+(tds[2]||{}).textContent.replace(/[₹,\s]/g,'')+'"',
            '"'+(tds[3]||{}).textContent.replace(/[₹,\s]/g,'')+'"',
            '"'+((tds[4]||{}).textContent||'').trim()+'"'
        ];
        lines.push(line.join(','));
    });
    var blob = new Blob([lines.join('\n')], {type:'text/csv'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'due_fees_' + (document.getElementById('classSelect').value||'class') + '.csv';
    a.click();
}

function escHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

/* ── DOMContentLoaded ── */
document.addEventListener('DOMContentLoaded', function() {

    var classEl   = document.getElementById('classSelect');
    var sectionEl = document.getElementById('sectionSelect');
    var loadBtn   = document.getElementById('loadBtn');
    var expBtn    = document.getElementById('exportDueBtn');

    /* Class → populate sections */
    classEl.addEventListener('change', function() {
        populateSections(classEl.value);
        document.getElementById('dueFeeResult').style.display = 'none';
    });

    /* Section → enable Load button */
    sectionEl.addEventListener('change', function() {
        loadBtn.disabled = !sectionEl.value;
    });

    loadBtn.addEventListener('click', loadDueFees);
    if (expBtn) expBtn.addEventListener('click', exportDueCSV);

    /* Auto-load if ?class= and ?section= params were passed (from fees_records click-through) */
    if (CF_INIT_CLASS) {
        classEl.value = CF_INIT_CLASS;
        if (!classEl.value) {
            Array.from(classEl.options).forEach(function(opt) {
                if (opt.value.toLowerCase() === CF_INIT_CLASS.toLowerCase()) {
                    classEl.value = opt.value;
                }
            });
        }

        if (classEl.value) {
            populateSections(classEl.value);

            setTimeout(function() {
                var sec = document.getElementById('sectionSelect');

                if (CF_INIT_SECTION) {
                    var matched = false;
                    Array.from(sec.options).forEach(function(opt) {
                        if (!matched && opt.value.trim().toLowerCase() === CF_INIT_SECTION.trim().toLowerCase()) {
                            sec.value   = opt.value;
                            loadBtn.disabled = false;
                            matched = true;
                        }
                    });
                    if (!matched) {
                        Array.from(sec.options).forEach(function(opt) {
                            if (!matched && opt.value.toLowerCase().includes(CF_INIT_SECTION.toLowerCase().replace('section ','').trim())) {
                                sec.value   = opt.value;
                                loadBtn.disabled = false;
                                matched = true;
                            }
                        });
                    }
                }

                if (sec.value && !loadBtn.disabled) {
                    loadDueFees();
                }
            }, 150);
        }
    }
});
</script>

<style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

    /* ── Design tokens — identical to fees_counter ── */
    :root {
        --fc-navy: #0b1f3a;
        --fc-teal: #0e7490;
        --fc-sky: #e0f2fe;
        --fc-green: #16a34a;
        --fc-red: #dc2626;
        --fc-amber: #d97706;
        --fc-blue: #2563eb;
        --fc-text: #1e293b;
        --fc-muted: #64748b;
        --fc-border: #e2e8f0;
        --fc-bg: #f1f5f9;
        --fc-white: #ffffff;
        --fc-shadow: 0 1px 14px rgba(11, 31, 58, .08);
        --fc-radius: 12px;
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    /* ── Wrapper ── */
    .cf-wrap {
        font-family: 'DM Sans', sans-serif;
        background: var(--fc-bg);
        color: var(--fc-text);
        padding: 24px 20px 60px;
        min-height: 100vh;
    }

    /* ── Top bar ── */
    .cf-topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 22px;
    }

    .cf-page-title {
        font-family: 'Playfair Display', serif;
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--fc-navy);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 5px;
    }

    .cf-page-title>i {
        color: var(--fc-teal);
    }

    .cf-breadcrumb {
        display: flex;
        gap: 0;
        list-style: none;
        margin: 0;
        padding: 0;
        font-size: 12.5px;
        color: var(--fc-muted);
    }

    .cf-breadcrumb li+li::before {
        content: ' / ';
        margin: 0 4px;
        color: var(--fc-border);
    }

    .cf-breadcrumb a {
        color: var(--fc-teal);
        text-decoration: none;
        font-weight: 500;
    }

    .cf-topbar-right {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    /* ── Stat strip ── */
    .cf-stat-strip {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 20px;
    }

    @media(max-width:900px) {
        .cf-stat-strip {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media(max-width:500px) {
        .cf-stat-strip {
            grid-template-columns: 1fr 1fr;
        }
    }

    .cf-stat {
        background: var(--fc-white);
        border-radius: var(--fc-radius);
        box-shadow: var(--fc-shadow);
        border: 1px solid var(--fc-border);
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 13px;
        transition: transform .15s, box-shadow .15s;
    }

    .cf-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(11, 31, 58, .13);
    }

    .cf-stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }

    .cf-stat-blue .cf-stat-icon {
        background: #dbeafe;
        color: var(--fc-blue);
    }

    .cf-stat-green .cf-stat-icon {
        background: #dcfce7;
        color: var(--fc-green);
    }

    .cf-stat-amber .cf-stat-icon {
        background: #fef3c7;
        color: var(--fc-amber);
    }

    .cf-stat-red .cf-stat-icon {
        background: #fee2e2;
        color: var(--fc-red);
    }

    .cf-stat-label {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--fc-muted);
        margin-bottom: 3px;
    }

    .cf-stat-val {
        font-family: 'Playfair Display', serif;
        font-size: 20px;
        font-weight: 700;
        color: var(--fc-navy);
        line-height: 1.15;
    }

    .cf-stat-sub {
        font-size: 12.5px;
        color: var(--fc-muted);
        margin-top: 2px;
    }

    /* ── Card ── */
    .cf-card {
        background: var(--fc-white);
        border-radius: var(--fc-radius);
        box-shadow: var(--fc-shadow);
        border: 1px solid var(--fc-border);
        overflow: hidden;
        margin-bottom: 18px;
    }

    /* ── Selector card head ── */
    .cf-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        padding: 13px 18px;
        border-bottom: 1px solid var(--fc-border);
        background: linear-gradient(90deg, var(--fc-sky) 0%, var(--fc-white) 65%);
    }

    .cf-card-head-left {
        display: flex;
        align-items: center;
        gap: 9px;
    }

    .cf-card-head-left>i {
        color: var(--fc-teal);
    }

    .cf-card-head-left>h3 {
        font-family: 'Playfair Display', serif;
        font-size: 15px;
        font-weight: 700;
        color: var(--fc-navy);
        margin: 0;
    }

    .cf-card-head-right {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    /* ── Selector body ── */
    .cf-selector-body {
        padding: 16px 18px;
        display: flex;
        align-items: flex-end;
        gap: 14px;
        flex-wrap: wrap;
    }

    .cf-field {
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 180px;
        flex: 1;
    }

    .cf-label {
        font-size: 12.5px;
        font-weight: 700;
        letter-spacing: .4px;
        text-transform: uppercase;
        color: var(--fc-muted);
    }

    .cf-select-wrap {
        position: relative;
    }

    .cf-select {
        width: 100%;
        height: 40px;
        padding: 0 34px 0 12px;
        border: 1.5px solid var(--fc-border);
        border-radius: 8px;
        font-size: 13.5px;
        font-family: 'DM Sans', sans-serif;
        color: var(--fc-text);
        background: #fff;
        outline: none;
        appearance: none;
        cursor: pointer;
        transition: border-color .13s, box-shadow .13s;
    }

    .cf-select:focus {
        border-color: var(--fc-teal);
        box-shadow: 0 0 0 3px rgba(14, 116, 144, .1);
    }

    .cf-select-arr {
        position: absolute;
        right: 11px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--fc-muted);
        font-size: 12.5px;
        pointer-events: none;
    }

    /* ── Buttons ── */
    .cf-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 18px;
        border-radius: 8px;
        border: none;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        text-decoration: none;
        transition: opacity .13s, transform .1s;
        height: 40px;
    }

    .cf-btn:hover:not(:disabled) {
        opacity: .85;
        transform: translateY(-1px);
    }

    .cf-btn:disabled {
        opacity: .38;
        cursor: not-allowed;
        transform: none !important;
    }

    .cf-btn-sm {
        padding: 6px 13px;
        font-size: 12px;
        height: 34px;
    }

    .cf-btn-primary {
        background: var(--fc-teal);
        color: #fff;
        box-shadow: 0 2px 8px rgba(14, 116, 144, .3);
    }

    .cf-btn-navy {
        background: var(--fc-navy);
        color: #fff;
    }

    .cf-btn-ghost {
        background: var(--fc-white);
        color: var(--fc-text);
        border: 1.5px solid var(--fc-border);
    }

    .cf-btn-ghost:hover:not(:disabled) {
        border-color: var(--fc-teal);
        color: var(--fc-teal);
        opacity: 1;
    }

    .cf-btn-amber {
        background: var(--fc-amber);
        color: #fff;
    }

    /* ── Results area ── */
    #dueFeeResult {
        display: none;
    }

    /* Filter bar */
    .cf-filter-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        padding: 10px 18px;
        border-bottom: 1px solid var(--fc-border);
        background: #fafcff;
    }

    .cf-search-wrap {
        position: relative;
        flex: 1;
        max-width: 280px;
    }

    .cf-search-icon {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--fc-muted);
        font-size: 12px;
        pointer-events: none;
    }

    .cf-search {
        width: 100%;
        height: 36px;
        padding: 0 10px 0 30px;
        border: 1.5px solid var(--fc-border);
        border-radius: 8px;
        font-size: 13px;
        font-family: 'DM Sans', sans-serif;
        outline: none;
        background: #fff;
        color: var(--fc-text);
        transition: border-color .13s, box-shadow .13s;
    }

    .cf-search:focus {
        border-color: var(--fc-teal);
        box-shadow: 0 0 0 3px rgba(14, 116, 144, .1);
    }

    .cf-row-count {
        font-size: 12px;
        color: var(--fc-muted);
        font-weight: 600;
        white-space: nowrap;
    }

    /* Due table */
    .cf-table-wrap {
        overflow-x: auto;
        max-height: 520px;
        overflow-y: auto;
    }

    .cf-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .cf-table thead th {
        background: var(--fc-navy);
        color: rgba(255, 255, 255, .88);
        padding: 10px 14px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .4px;
        text-transform: uppercase;
        white-space: nowrap;
        text-align: left;
        position: sticky;
        top: 0;
        z-index: 4;
    }

    .cf-table thead th.r {
        text-align: right;
    }

    .cf-table thead th.c {
        text-align: center;
    }

    .cf-table thead th:first-child {
        padding-left: 18px;
    }

    .cf-table td {
        padding: 10px 14px;
        border-bottom: 1px solid var(--fc-border);
        vertical-align: middle;
        font-size: 13px;
    }

    .cf-table td:first-child {
        padding-left: 18px;
    }

    .cf-table td.r {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }

    .cf-table td.c {
        text-align: center;
    }

    .cf-table tbody tr {
        cursor: pointer;
        transition: background .1s;
    }

    .cf-table tbody tr:hover {
        background: var(--fc-sky);
    }

    .cf-table tbody tr.cf-selected {
        background: rgba(14, 116, 144, .12) !important;
    }

    /* Name cell */
    .cf-name-cell {
        display: flex;
        align-items: center;
        gap: 9px;
    }

    .cf-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--fc-teal), var(--fc-teal));
        color: #fff;
        font-weight: 700;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        letter-spacing: .5px;
    }

    .cf-name-text {
        line-height: 1.3;
    }

    .cf-student-name {
        font-weight: 600;
        color: var(--fc-text);
        display: block;
    }

    .cf-father-name {
        font-size: 12.5px;
        color: var(--fc-muted);
    }

    /* Amount cells */
    .cf-amt-total {
        font-weight: 600;
    }

    .cf-amt-received {
        font-weight: 600;
        color: var(--fc-green);
    }

    .cf-amt-due {
        font-weight: 700;
    }

    .cf-amt-due.over {
        color: var(--fc-green);
    }

    .cf-amt-due.exact {
        color: var(--fc-muted);
    }

    .cf-amt-due.due {
        color: var(--fc-red);
    }

    /* Status badge */
    .cf-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12.5px;
        font-weight: 700;
        letter-spacing: .3px;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .cf-badge-paid {
        background: #dcfce7;
        color: #14532d;
    }

    .cf-badge-partial {
        background: #fef3c7;
        color: #92400e;
    }

    .cf-badge-due {
        background: #fee2e2;
        color: #991b1b;
    }

    .cf-badge-over {
        background: #dbeafe;
        color: #1e40af;
    }

    /* Progress bar */
    .cf-progress-wrap {
        width: 100%;
        min-width: 80px;
    }

    .cf-progress-bg {
        width: 100%;
        height: 6px;
        background: var(--fc-border);
        border-radius: 3px;
        overflow: hidden;
    }

    .cf-progress-fill {
        height: 100%;
        border-radius: 3px;
        transition: width .4s ease;
    }

    .cf-progress-fill.paid {
        background: var(--fc-green);
    }

    .cf-progress-fill.partial {
        background: var(--fc-amber);
    }

    .cf-progress-fill.due {
        background: var(--fc-red);
    }

    .cf-progress-fill.over {
        background: var(--fc-blue);
    }

    .cf-progress-pct {
        font-size: 12px;
        color: var(--fc-muted);
        margin-top: 2px;
        text-align: right;
    }

    /* Tfoot */
    .cf-table tfoot td {
        background: var(--fc-navy);
        color: rgba(255, 255, 255, .9);
        font-weight: 700;
        font-size: 12.5px;
        padding: 10px 14px;
        text-align: right;
        position: sticky;
        bottom: 0;
        z-index: 3;
    }

    .cf-table tfoot td:first-child {
        text-align: left;
        padding-left: 18px;
    }

    .cf-table tfoot .cf-foot-green {
        color: #6ee7b7;
    }

    .cf-table tfoot .cf-foot-red {
        color: #fca5a5;
    }

    /* Loading / empty */
    .cf-loader {
        text-align: center;
        padding: 40px 20px;
        color: var(--fc-muted);
    }

    .cf-loader i {
        font-size: 24px;
    }

    .cf-loader p {
        margin: 10px 0 0;
        font-size: 14px;
    }

    .cf-empty-res {
        text-align: center;
        padding: 50px 20px;
        color: var(--fc-muted);
    }

    .cf-empty-res i {
        font-size: 40px;
        opacity: .2;
        display: block;
        margin-bottom: 12px;
    }

    .cf-empty-res p {
        margin: 0;
        font-size: 14px;
    }

    /* Toast */
    .cf-toast-wrap {
        position: fixed;
        top: 18px;
        right: 18px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 8px;
        pointer-events: none;
    }

    .cf-toast {
        background: var(--fc-navy);
        color: #fff;
        border-radius: 8px;
        padding: 11px 16px;
        font-size: 13px;
        font-weight: 500;
        box-shadow: 0 4px 20px rgba(0, 0, 0, .22);
        display: flex;
        align-items: center;
        gap: 9px;
        animation: cfSlideIn .25s ease;
        pointer-events: auto;
        max-width: 320px;
    }

    .cf-toast-success {
        border-left: 4px solid var(--fc-green);
    }

    .cf-toast-error {
        border-left: 4px solid var(--fc-red);
    }

    .cf-toast-warning {
        border-left: 4px solid var(--fc-amber);
    }

    .cf-toast-info {
        border-left: 4px solid var(--fc-teal);
    }

    .cf-toast-hide {
        opacity: 0;
        transition: opacity .3s;
    }

    @keyframes cfSlideIn {
        from {
            transform: translateX(30px);
            opacity: 0;
        }

        to {
            transform: none;
            opacity: 1;
        }
    }
</style>