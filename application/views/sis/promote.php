<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── Promotion Page ───────────────────────────────────────────────── */
.pm-wrap { padding:28px 30px; }
.pm-breadcrumb { display:flex; align-items:center; gap:8px; margin-bottom:22px; font-size:13px; }
.pm-breadcrumb a { color:var(--t3); text-decoration:none; transition:color .2s; }
.pm-breadcrumb a:hover { color:var(--gold); }
.pm-breadcrumb span { color:var(--t3); }
.pm-breadcrumb .current { color:var(--t1); font-weight:600; }

.pm-header { margin-bottom:28px; }
.pm-header h1 { margin:0 0 6px; font-size:22px; font-family:var(--font-b); color:var(--t1); display:flex; align-items:center; gap:12px; }
.pm-header h1 i { color:var(--gold); font-size:20px; }
.pm-header p { margin:0; color:var(--t3); font-size:13px; }

/* Transfer card */
.pm-card { background:var(--bg2); border:1px solid var(--border); border-radius:14px;
    padding:0; margin-bottom:24px; overflow:hidden; box-shadow:var(--sh); }
.pm-card-head { padding:18px 28px; border-bottom:1px solid var(--border); background:var(--bg3);
    display:flex; align-items:center; justify-content:space-between; }
.pm-card-head h2 { margin:0; font-size:15px; font-family:var(--font-m); color:var(--t1); font-weight:700; }
.pm-card-body { padding:28px; }

/* FROM → TO layout */
.pm-transfer { display:flex; gap:0; align-items:stretch; }
.pm-side { flex:1; min-width:0; }
.pm-arrow { width:70px; display:flex; flex-direction:column; align-items:center; justify-content:center;
    background:var(--bg3); border-left:1px solid var(--border); border-right:1px solid var(--border); }
.pm-arrow i { font-size:22px; color:var(--gold); }
.pm-arrow span { font-size:10px; color:var(--t3); margin-top:4px; font-family:var(--font-m); text-transform:uppercase; }

.pm-side-inner { padding:24px 28px; }
.pm-side-label { font-size:11px; font-weight:700; letter-spacing:.8px; text-transform:uppercase;
    font-family:var(--font-m); margin-bottom:18px; padding:5px 12px; border-radius:6px; display:inline-block; }
.pm-from .pm-side-label { color:#dc2626; background:rgba(220,38,38,.08); }
.pm-to .pm-side-label   { color:#059669; background:rgba(5,150,105,.08); }

.pm-fg { margin-bottom:16px; }
.pm-fg:last-child { margin-bottom:0; }
.pm-fg label { display:block; font-size:12.5px; color:var(--t3); font-family:var(--font-m);
    margin-bottom:6px; font-weight:600; }
.pm-fg select { width:100%; padding:10px 14px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg); color:var(--t1); font-size:13.5px; font-family:inherit;
    transition:border-color .2s, box-shadow .2s; appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='7'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' fill='none'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 14px center; }
.pm-fg select:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-dim); }

/* Session badge */
.pm-session-tag { display:inline-block; font-size:10px; font-weight:700; padding:2px 7px;
    border-radius:4px; margin-left:6px; vertical-align:middle; }
.pm-session-tag.current { background:var(--gold-dim); color:var(--gold); }
.pm-session-tag.next    { background:rgba(5,150,105,.1); color:#059669; }

/* Action bar */
.pm-actions { padding:18px 28px; border-top:1px solid var(--border); background:var(--bg3);
    display:flex; align-items:center; gap:12px; }
.pm-btn { padding:10px 22px; border:none; border-radius:8px; font-size:13px; font-family:var(--font-m);
    font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .2s; }
.pm-btn-primary { background:var(--gold); color:#fff; }
.pm-btn-primary:hover { background:var(--gold2); transform:translateY(-1px); box-shadow:0 4px 12px var(--gold-dim); }
.pm-btn-secondary { background:var(--bg2); color:var(--t2); border:1px solid var(--border); }
.pm-btn-secondary:hover { background:var(--bg); }
.pm-btn:disabled { opacity:.5; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

/* Info strip */
.pm-info { display:flex; align-items:flex-start; gap:10px; padding:14px 18px;
    background:var(--gold-dim); border:1px solid var(--gold-ring); border-radius:10px;
    font-size:12.5px; color:var(--t2); line-height:1.6; margin-bottom:24px; }
.pm-info i { color:var(--gold); margin-top:2px; flex-shrink:0; }

/* Preview panel */
.pm-preview { display:none; }
.pm-preview.show { display:block; }
.pm-preview-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.pm-preview-head h3 { margin:0; font-size:15px; font-family:var(--font-m); color:var(--t1); font-weight:700;
    display:flex; align-items:center; gap:8px; }
.pm-preview-head h3 .count { background:var(--gold); color:#fff; font-size:11px; padding:2px 10px;
    border-radius:12px; font-weight:700; }

.pm-table { width:100%; border-collapse:collapse; font-size:13px; }
.pm-table th { background:var(--bg3); color:var(--t3); padding:10px 14px; text-align:left;
    border-bottom:2px solid var(--border); font-family:var(--font-m); font-size:11.5px;
    text-transform:uppercase; letter-spacing:.4px; font-weight:700; }
.pm-table td { padding:10px 14px; border-bottom:1px solid var(--border); color:var(--t1); }
.pm-table tr:hover td { background:var(--gold-dim); }
.pm-table tr:last-child td { border-bottom:none; }
.pm-table code { background:var(--bg3); padding:2px 8px; border-radius:4px; font-size:12px; color:var(--t2); }

.pm-alert { padding:14px 18px; border-radius:10px; font-size:13px; margin-top:16px;
    display:none; align-items:center; gap:10px; font-family:var(--font-m); }
.pm-alert i { flex-shrink:0; }
.pm-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.pm-alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

/* Summary strip above confirm */
.pm-summary { padding:14px 18px; background:var(--bg); border:1px solid var(--border);
    border-radius:8px; font-size:12.5px; color:var(--t2); margin-bottom:16px;
    display:flex; flex-wrap:wrap; gap:6px 20px; }
.pm-summary strong { color:var(--t1); }

/* Responsive */
@media(max-width:768px){
    .pm-transfer { flex-direction:column; }
    .pm-arrow { width:100%; flex-direction:row; gap:8px; padding:12px;
        border-left:none; border-right:none; border-top:1px solid var(--border); border-bottom:1px solid var(--border); }
    .pm-arrow span { margin-top:0; }
    .pm-side-inner { padding:20px; }
    .pm-actions { flex-wrap:wrap; }
}
</style>

<div class="content-wrapper">
<div class="pm-wrap">

    <!-- Breadcrumb -->
    <div class="pm-breadcrumb">
        <a href="<?= base_url('sis') ?>"><i class="fa fa-th-large"></i> SIS</a>
        <span>/</span>
        <span class="current">Student Promotion</span>
    </div>

    <!-- Header -->
    <div class="pm-header">
        <h1><i class="fa fa-level-up"></i> Student Promotion</h1>
        <p>Promote students from one class to another across academic sessions.</p>
    </div>

    <!-- Info -->
    <div class="pm-info">
        <i class="fa fa-info-circle"></i>
        <div>
            Select the <strong>source</strong> class/section (current session) and the <strong>destination</strong> class/section.
            The target session defaults to the next academic year. Students will be moved from the old roster and enrolled in the new one.
        </div>
    </div>

    <!-- Transfer Card -->
    <div class="pm-card">
        <div class="pm-card-head">
            <h2><i class="fa fa-exchange" style="color:var(--gold);margin-right:8px;"></i> Promotion Setup</h2>
            <span style="font-size:12px;color:var(--t3);font-family:var(--font-m);">Session: <?= htmlspecialchars($session_year) ?></span>
        </div>

        <div class="pm-transfer">
            <!-- FROM -->
            <div class="pm-side pm-from">
                <div class="pm-side-inner">
                    <div class="pm-side-label"><i class="fa fa-sign-out" style="margin-right:4px;"></i> Source</div>
                    <div class="pm-fg">
                        <label>Class</label>
                        <select id="fromClass">
                            <option value="">-- Select Class --</option>
                            <?php foreach ($class_map as $ord => $secs): ?>
                            <option value="<?= htmlspecialchars($ord) ?>">Class <?= htmlspecialchars($ord) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pm-fg">
                        <label>Section</label>
                        <select id="fromSection">
                            <option value="all">All Sections</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Arrow -->
            <div class="pm-arrow">
                <i class="fa fa-long-arrow-right"></i>
                <span>Promote</span>
            </div>

            <!-- TO -->
            <div class="pm-side pm-to">
                <div class="pm-side-inner">
                    <div class="pm-side-label"><i class="fa fa-sign-in" style="margin-right:4px;"></i> Destination</div>
                    <div class="pm-fg">
                        <label>Class</label>
                        <select id="toClass">
                            <option value="">-- Select Class --</option>
                            <?php foreach ($class_map as $ord => $secs): ?>
                            <option value="<?= htmlspecialchars($ord) ?>">Class <?= htmlspecialchars($ord) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pm-fg">
                        <label>Section</label>
                        <select id="toSection">
                            <option value="">-- Select Section --</option>
                        </select>
                    </div>
                    <div class="pm-fg">
                        <label>Target Session</label>
                        <select id="toSession">
                            <?php foreach ($session_options as $sy): ?>
                            <option value="<?= htmlspecialchars($sy) ?>"<?= ($sy === ($next_session ?? '')) ? ' selected' : '' ?>>
                                <?= htmlspecialchars($sy) ?><?= ($sy === $session_year) ? '  (current)' : '' ?><?= ($sy === ($next_session ?? '')) ? '  (next)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="pm-actions">
            <button class="pm-btn pm-btn-primary" id="previewBtn" onclick="previewPromotion()">
                <i class="fa fa-eye"></i> Preview Students
            </button>
            <span style="font-size:12px;color:var(--t3);">Preview before confirming the promotion.</span>
        </div>
    </div>

    <!-- Preview Panel -->
    <div class="pm-card pm-preview" id="previewPanel">
        <div class="pm-card-head">
            <div class="pm-preview-head" style="margin-bottom:0;">
                <h3 id="previewTitle">
                    <i class="fa fa-users" style="color:var(--gold);"></i>
                    Students to Promote
                    <span class="count" id="previewCount">0</span>
                </h3>
            </div>
            <button class="pm-btn pm-btn-secondary" onclick="document.getElementById('previewPanel').classList.remove('show')">
                <i class="fa fa-times"></i> Close
            </button>
        </div>
        <div class="pm-card-body">
            <!-- Summary -->
            <div class="pm-summary" id="promoSummary"></div>

            <!-- Alert -->
            <div class="pm-alert" id="alertBox"><i class="fa"></i><span></span></div>

            <!-- Table -->
            <div style="overflow-x:auto; margin-top:16px;">
                <table class="pm-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Current Class</th>
                            <th>Section</th>
                        </tr>
                    </thead>
                    <tbody id="previewTbody"></tbody>
                </table>
            </div>

            <!-- Confirm -->
            <div style="margin-top:20px; display:flex; align-items:center; gap:12px;">
                <button class="pm-btn pm-btn-primary" id="confirmBtn" onclick="executePromotion()">
                    <i class="fa fa-check-circle"></i> Confirm &amp; Promote
                </button>
                <button class="pm-btn pm-btn-secondary" onclick="document.getElementById('previewPanel').classList.remove('show')">
                    Cancel
                </button>
            </div>
        </div>
    </div>

</div>
</div>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var CLASS_MAP = <?= json_encode($class_map) ?>;
var previewStudents = [];

function populateSections(selectEl, classOrd, includeAll) {
    selectEl.innerHTML = '';
    if (includeAll) selectEl.innerHTML = '<option value="all">All Sections</option>';
    else selectEl.innerHTML = '<option value="">-- Select Section --</option>';
    if (classOrd && CLASS_MAP[classOrd]) {
        CLASS_MAP[classOrd].forEach(function(s) {
            selectEl.innerHTML += '<option value="' + esc(s) + '">Section ' + esc(s) + '</option>';
        });
    }
}

document.getElementById('fromClass').addEventListener('change', function () {
    populateSections(document.getElementById('fromSection'), this.value, true);
});
document.getElementById('toClass').addEventListener('change', function () {
    populateSections(document.getElementById('toSection'), this.value, false);
});

function previewPromotion() {
    var fromClass   = document.getElementById('fromClass').value;
    var fromSection = document.getElementById('fromSection').value;
    if (!fromClass) { showAlert('Please select a source class.', 'error'); return; }

    var btn = document.getElementById('previewBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Loading...';

    var body = new URLSearchParams({ from_class: fromClass, from_section: fromSection });
    body.append(csrfName, csrfToken);
    fetch('<?= base_url("sis/promote_preview") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-eye"></i> Preview Students';
        if (data.status !== 'success') { showAlert(data.message, 'error'); return; }
        previewStudents = data.students;
        var tbody = document.getElementById('previewTbody');
        document.getElementById('previewCount').textContent = previewStudents.length;
        if (!previewStudents.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--t3);padding:28px;">No students found in the selected class/section.</td></tr>';
        } else {
            tbody.innerHTML = previewStudents.map(function(s, i) {
                return '<tr><td>' + (i+1) + '</td><td><code>' + esc(s.user_id) + '</code></td>'
                    + '<td>' + esc(s.name) + '</td><td>Class ' + esc(s.class) + '</td>'
                    + '<td>' + esc(s.section) + '</td></tr>';
            }).join('');
        }
        document.getElementById('previewTitle').innerHTML =
            '<i class="fa fa-users" style="color:var(--gold);"></i> Students to Promote '
            + '<span class="count">' + previewStudents.length + '</span>';

        // Build summary
        var toClass   = document.getElementById('toClass').value || '(not selected)';
        var toSection = document.getElementById('toSection').value || '(not selected)';
        var toSession = document.getElementById('toSession');
        var toSessionText = toSession.options[toSession.selectedIndex].text.trim();
        document.getElementById('promoSummary').innerHTML =
            '<span><strong>From:</strong> Class ' + esc(fromClass) + ' / ' + (fromSection === 'all' ? 'All Sections' : 'Section ' + esc(fromSection)) + '</span>'
            + '<span><strong>To:</strong> Class ' + esc(toClass) + ' / Section ' + esc(toSection) + '</span>'
            + '<span><strong>Session:</strong> ' + esc(toSessionText) + '</span>'
            + '<span><strong>Students:</strong> ' + previewStudents.length + '</span>';

        document.getElementById('previewPanel').classList.add('show');
        hideAlert();

        // Scroll to preview
        document.getElementById('previewPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-eye"></i> Preview Students';
        showAlert('Failed to load preview. Please try again.', 'error');
    });
}

function executePromotion() {
    var toClass   = document.getElementById('toClass').value;
    var toSection = document.getElementById('toSection').value;
    if (!toClass || !toSection) { showAlert('Please select destination class and section.', 'error'); return; }
    if (!confirm('Promote ' + previewStudents.length + ' student(s) to Class ' + toClass + ' / Section ' + toSection + '?')) return;

    var btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Promoting...';

    var body = new URLSearchParams({
        from_class:   document.getElementById('fromClass').value,
        from_section: document.getElementById('fromSection').value,
        to_class:     toClass,
        to_section:   toSection,
        to_session:   document.getElementById('toSession').value.trim(),
    });
    body.append(csrfName, csrfToken);
    fetch('<?= base_url("sis/execute_promotion") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            showAlert(data.message, 'success');
            btn.style.display = 'none';
        } else {
            showAlert(data.message, 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-check-circle"></i> Confirm & Promote';
        }
    })
    .catch(function() {
        showAlert('Promotion failed. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check-circle"></i> Confirm & Promote';
    });
}

function showAlert(msg, type) {
    var el = document.getElementById('alertBox');
    el.className = 'pm-alert pm-alert-' + type;
    el.innerHTML = '<i class="fa ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i><span>' + msg + '</span>';
    el.style.display = 'flex';
}
function hideAlert() { document.getElementById('alertBox').style.display = 'none'; }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
