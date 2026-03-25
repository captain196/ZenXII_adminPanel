<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size: 16px !important; }
.tc-wrap  { max-width:1100px; margin:0 auto; padding:24px 20px; }
.page-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:10px; }
.page-hdr h1 { margin:0; font-size:1.3rem; color:var(--t1); font-family:var(--font-b); }

.btn-primary { padding:9px 20px; background:var(--gold); color:#fff; border:none;
    border-radius:7px; cursor:pointer; font-size:.86rem; font-family:var(--font-m); text-decoration:none; }
.btn-primary:hover { background:var(--gold2); }

/* Issue TC Modal */
.tc-modal-bg { display:none; }
.tc-modal-box { background:var(--bg2); border-radius:12px; padding:28px; width:460px; max-width:95vw; box-shadow:0 25px 50px rgba(0,0,0,.25); }
.tc-modal-box h3 { margin:0 0 18px; font-size:1.1rem; color:var(--t1); font-family:var(--font-b); }
.fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
.fg label { font-size:.84rem; color:var(--t3); font-family:var(--font-m); }
.fg input, .fg select, .fg textarea {
    padding:9px 12px; border:1px solid var(--border); border-radius:6px;
    background:var(--bg3); color:var(--t1); font-size:.86rem; }
.fg textarea { resize:vertical; min-height:60px; }
.tc-modal-btns { display:flex; gap:10px; margin-top:4px; }
.btn-secondary { padding:9px 18px; background:var(--bg3); color:var(--t2);
    border:1px solid var(--border); border-radius:7px; cursor:pointer; font-size:.86rem; }

/* Table */
.tc-table-wrap { background:var(--bg2); border:1px solid var(--border); border-radius:10px; overflow:hidden; }
.tc-table { width:100%; border-collapse:collapse; font-size:.9rem; }
.tc-table th { background:var(--bg3); color:var(--t2); font-family:var(--font-m);
    padding:10px 14px; text-align:left; border-bottom:1px solid var(--border); }
.tc-table td { padding:10px 14px; border-bottom:1px solid var(--border); color:var(--t1); }
.tc-table tr:last-child td { border-bottom:none; }
.tc-table tr:hover td { background:var(--gold-dim); }

.badge-active   { background:#dcfce7; color:#166534; padding:3px 10px; border-radius:20px; font-size:.82rem; }
.badge-cancel   { background:var(--bg3); color:var(--t3); padding:3px 10px; border-radius:20px; font-size:.82rem; }

.act-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 18px; border-radius:7px;
    border:1px solid var(--border); background:var(--bg3); color:var(--t2); cursor:pointer;
    font-size:.88rem; font-family:var(--font-m); text-decoration:none; white-space:nowrap;
    transition:all .18s var(--ease); line-height:1.4; }
.act-btn:hover { background:var(--gold-dim); color:var(--gold); border-color:var(--gold-ring); }
.act-btn.print { background:var(--gold); color:#fff; border-color:var(--gold); }
.act-btn.print:hover { background:var(--gold2); border-color:var(--gold2); color:#fff; }
.act-btn.red { border-color:#fecaca; }
.act-btn.red:hover { background:#fee2e2; color:#991b1b; border-color:#fecaca; }

.alert { padding:10px 14px; border-radius:6px; font-size:.85rem; display:none; margin-bottom:14px; }
.alert-success { background:#dcfce7; color:#166534; }
.alert-error   { background:#fee2e2; color:#991b1b; }

.student-lookup { display:flex; gap:8px; }
.student-lookup input { flex:1; }
.student-lookup button { padding:9px 14px; background:var(--bg3); border:1px solid var(--border);
    border-radius:6px; cursor:pointer; color:var(--t2); }
.student-found { font-size:.82rem; color:var(--gold); margin-top:4px; display:none; }
</style>

<div class="content-wrapper">
<div class="tc-wrap">

    <div class="page-hdr">
        <h1><i class="fa fa-file-text-o" style="color:var(--gold);margin-right:8px;"></i>
            Transfer Certificates
            <?php if ($tc_total > 0): ?>
            <span style="font-size:.82rem;font-weight:400;color:var(--t3);margin-left:8px;">(<?= $tc_total ?> total)</span>
            <?php endif; ?>
        </h1>
        <button class="btn-primary" onclick="openIssueTc()"><i class="fa fa-plus"></i> Issue New TC</button>
    </div>

    <div class="tc-table-wrap">
        <?php if (empty($tc_records) && $tc_page === 1): ?>
        <div style="text-align:center;padding:50px;color:var(--t3);">
            <i class="fa fa-file-text-o" style="font-size:2.5rem;margin-bottom:10px;display:block;"></i>
            No transfer certificates issued yet.
        </div>
        <?php else: ?>
        <table class="tc-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>TC No</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Issued Date</th>
                    <th>Destination</th>
                    <th>Issued By</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $rowNum = ($tc_page - 1) * $tc_per_page + 1; ?>
                <?php foreach ($tc_records as $tc): ?>
                <tr>
                    <td style="color:var(--t3);"><?= $rowNum++ ?></td>
                    <td><strong><?= htmlspecialchars($tc['tc_no'] ?? '') ?></strong></td>
                    <td>
                        <a href="<?= base_url('sis/profile/' . urlencode($tc['user_id'] ?? '')) ?>"
                            style="color:var(--gold);text-decoration:none;">
                            <?= htmlspecialchars($tc['name'] ?? '') ?>
                        </a>
                        <div style="font-size:.84rem;color:var(--t3);"><?= htmlspecialchars($tc['user_id'] ?? '') ?></div>
                    </td>
                    <td>Class <?= htmlspecialchars($tc['class'] ?? '') ?> / <?= htmlspecialchars($tc['section'] ?? '') ?></td>
                    <td><?= htmlspecialchars($tc['issued_date'] ?? '') ?></td>
                    <td><?= htmlspecialchars($tc['destination'] ?? '') ?></td>
                    <td><?= htmlspecialchars($tc['issued_by'] ?? '') ?></td>
                    <td>
                        <span class="badge-<?= ($tc['status'] ?? '') === 'active' ? 'active' : 'cancel' ?>">
                            <?= htmlspecialchars($tc['status'] ?? '') ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:8px;align-items:center;">
                        <?php if (($tc['status'] ?? '') === 'active'): ?>
                        <a href="<?= base_url('sis/print_tc/' . urlencode($tc['user_id']) . '/' . urlencode($tc['tc_key'])) ?>"
                            target="_blank" class="act-btn print"><i class="fa fa-print"></i> Print</a>
                        <button class="act-btn red"
                            data-uid="<?= htmlspecialchars($tc['user_id'], ENT_QUOTES, 'UTF-8') ?>"
                            data-tckey="<?= htmlspecialchars($tc['tc_key'], ENT_QUOTES, 'UTF-8') ?>"
                            onclick="cancelTc(this)">
                            <i class="fa fa-times"></i> Cancel
                        </button>
                        <?php else: ?>
                        <span style="color:var(--t3);font-size:.82rem;">Cancelled</span>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($tc_pages > 1): ?>
        <div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:16px;border-top:1px solid var(--border);">
            <?php if ($tc_page > 1): ?>
            <a href="<?= base_url('sis/tc?page=' . ($tc_page - 1)) ?>"
                style="padding:5px 14px;border:1px solid var(--border);border-radius:5px;background:var(--bg3);color:var(--t2);text-decoration:none;font-size:.85rem;">
                &laquo; Prev
            </a>
            <?php endif; ?>
            <span style="color:var(--t3);font-size:.85rem;">Page <?= $tc_page ?> of <?= $tc_pages ?></span>
            <?php if ($tc_page < $tc_pages): ?>
            <a href="<?= base_url('sis/tc?page=' . ($tc_page + 1)) ?>"
                style="padding:5px 14px;border:1px solid var(--border);border-radius:5px;background:var(--bg3);color:var(--t2);text-decoration:none;font-size:.85rem;">
                Next &raquo;
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

</div>
</div>

<!-- Issue TC Modal -->
<div class="tc-modal-bg" id="tcModal">
    <div class="tc-modal-box">
        <h3><i class="fa fa-file-text-o" style="color:var(--gold);margin-right:8px;"></i>Issue Transfer Certificate</h3>
        <div id="modalAlert" class="alert"></div>

        <div class="fg">
            <label>Student ID *</label>
            <div class="student-lookup">
                <input type="text" id="tcUserId" placeholder="Enter student ID...">
                <button onclick="lookupStudent()"><i class="fa fa-search"></i></button>
            </div>
            <div id="studentFound" class="student-found"></div>
        </div>
        <div class="fg">
            <label>Reason for TC *</label>
            <textarea id="tcReason" placeholder="e.g. Family relocation, admission to another school..."></textarea>
        </div>
        <div class="fg">
            <label>Destination School / Place</label>
            <input type="text" id="tcDestination" placeholder="e.g. Delhi Public School, Mumbai">
        </div>

        <div class="tc-modal-btns">
            <button class="btn-primary" onclick="submitTc()"><i class="fa fa-check"></i> Issue TC</button>
            <button class="btn-secondary" onclick="closeModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// Move modal to body so position:fixed isn't broken by transformed parents
document.body.appendChild(document.getElementById('tcModal'));

function openIssueTc() {
    var el = document.getElementById('tcModal');
    el.style.cssText = 'display:block;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,.5);z-index:99999;';
    var box = el.querySelector('.tc-modal-box');
    box.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100000;background:var(--bg2);border-radius:12px;padding:28px;width:460px;max-width:95vw;box-shadow:0 25px 50px rgba(0,0,0,.25);';
}
function closeModal() {
    var el = document.getElementById('tcModal');
    el.style.cssText = 'display:none;';
}

function lookupStudent() {
    var uid = document.getElementById('tcUserId').value.trim();
    if (!uid) { alert('Enter a student ID.'); return; }
    var body = new URLSearchParams({ user_id: uid });
    body.append(csrfName, csrfToken);
    fetch('<?= base_url('sis/get_student') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var el = document.getElementById('studentFound');
        if (data.status === 'success') {
            var s = data.student;
            el.textContent = 'Found: ' + (s.Name || uid) + ' — Class ' + (s.Class || '?') + ' / ' + (s.Section || '?');
            el.style.color = 'var(--gold)';
            el.style.display = 'block';
        } else {
            el.textContent = 'Student not found.';
            el.style.color = '#991b1b';
            el.style.display = 'block';
        }
    });
}

function submitTc(forceOverride) {
    var uid = document.getElementById('tcUserId').value.trim();
    var reason = document.getElementById('tcReason').value.trim();
    if (!uid || !reason) { alert('Student ID and reason are required.'); return; }
    var body = new URLSearchParams({
        user_id: uid,
        reason: reason,
        destination: document.getElementById('tcDestination').value.trim(),
    });
    if (forceOverride) body.append('force_override', 'true');
    body.append(csrfName, csrfToken);
    fetch('<?= base_url('sis/issue_tc') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var a = document.getElementById('modalAlert');
        if (data.status === 'success') {
            a.className = 'alert alert-success';
            a.textContent = data.message;
            a.style.display = 'block';
            setTimeout(function() { location.reload(); }, 1500);
        } else if (data.can_override && data.dues) {
            // Show dues warning with override option
            var d = data.dues;
            a.className = 'alert alert-error';
            a.innerHTML = '<strong style="display:block;margin-bottom:6px;">'
                + '<i class="fa fa-exclamation-triangle"></i> Outstanding Dues Detected</strong>'
                + '<span>' + data.message + '</span>'
                + '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">'
                + '<a href="<?= base_url('fees/fees_counter') ?>" class="btn btn-sm" '
                + 'style="background:var(--gold);color:#fff;border:none;border-radius:6px;font-size:12px;">'
                + '<i class="fa fa-inr"></i> Collect Fees</a>'
                + '<button onclick="submitTc(true)" class="btn btn-sm" '
                + 'style="background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:12px;">'
                + '<i class="fa fa-warning"></i> Issue TC Anyway (Override)</button>'
                + '</div>';
            a.style.display = 'block';
        } else {
            a.className = 'alert alert-error';
            a.textContent = data.message;
            a.style.display = 'block';
        }
    });
}

function cancelTc(btn) {
    var userId = btn.dataset.uid;
    var tcKey  = btn.dataset.tckey;
    if (!confirm('Cancel this TC and re-activate the student?')) return;
    var body = new URLSearchParams({ user_id: userId, tc_key: tcKey });
    body.append(csrfName, csrfToken);
    fetch('<?= base_url('sis/cancel_tc') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        alert(data.message);
        if (data.status === 'success') location.reload();
    });
}
</script>
