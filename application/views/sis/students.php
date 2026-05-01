<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size: 16px !important; }
/* Full-width layout — pre-polish the wrapper was capped at 1200px which
   left a lot of empty side margin on a 14-column table and made the
   Actions column the first thing to overflow. Now the wrapper hugs
   the viewport with a comfortable side padding. */
.sis-students-wrap { width:100%; max-width:none; margin:0; padding:24px 28px; box-sizing:border-box; }

.page-hdr { display:flex; align-items:center; justify-content:space-between;
    margin-bottom:20px; flex-wrap:wrap; gap:10px; padding-bottom:14px;
    border-bottom:1px solid var(--border); }
.page-hdr h1 { margin:0; font-size:1.4rem; color:var(--t1); font-family:var(--font-b); letter-spacing:-0.01em; }
.page-hdr .page-sub { font-size:.84rem; color:var(--t3); margin-top:2px; }

/* Filter bar — slight elevation + tighter group sizing */
.filter-bar { background:var(--bg2); border:1px solid var(--border); border-radius:10px;
    padding:14px 16px; margin-bottom:18px; display:flex; gap:12px; flex-wrap:wrap;
    align-items:flex-end; box-shadow:0 1px 2px rgba(15,23,42,0.04); }
.filter-bar .fg { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:.78rem; color:var(--t3); font-family:var(--font-m);
    text-transform:uppercase; letter-spacing:.04em; }
.filter-bar select, .filter-bar input { padding:8px 11px; border:1px solid var(--border);
    border-radius:6px; background:var(--bg3); color:var(--t1); font-size:.88rem; min-width:150px;
    transition:border-color .15s; }
.filter-bar select:focus, .filter-bar input:focus { outline:none; border-color:var(--gold); }
.btn-search { padding:9px 20px; background:var(--gold); color:#fff; border:none;
    border-radius:6px; cursor:pointer; font-size:.88rem; font-family:var(--font-m);
    transition:background .15s, transform .05s; }
.btn-search:hover { background:var(--gold2); }
.btn-search:active { transform:translateY(1px); }

/* Table wrapper — horizontal scroll on overflow + subtle elevation. */
.students-table-wrap { background:var(--bg2); border:1px solid var(--border); border-radius:10px;
    overflow-x:auto; overflow-y:hidden; box-shadow:0 1px 2px rgba(15,23,42,0.04); }
.students-table { width:100%; border-collapse:collapse; font-size:.88rem; min-width:1280px; }
.students-table th { background:var(--bg3); color:var(--t2); font-family:var(--font-m);
    padding:11px 14px; text-align:left; border-bottom:1px solid var(--border);
    white-space:nowrap; position:sticky; top:0; z-index:1; font-size:.78rem;
    text-transform:uppercase; letter-spacing:.04em; }
.students-table td { padding:10px 14px; border-bottom:1px solid var(--border); color:var(--t1); vertical-align:middle; }
.students-table tr:last-child td { border-bottom:none; }
.students-table tr:hover td { background:var(--gold-dim); }

.badge-active   { background:#dcfce7; color:#166534; padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:600; }
.badge-tc       { background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:600; }
.badge-inactive { background:var(--bg3); color:var(--t3); padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:600; }

/* Compact icon-only action buttons — pre-polish the wide pill buttons
   spilled out of the Actions column. */
.act-btn { display:inline-flex; align-items:center; justify-content:center;
    width:30px; height:30px; padding:0; border-radius:6px; border:1px solid var(--border);
    background:var(--bg3); color:var(--t2); cursor:pointer; font-size:.85rem;
    text-decoration:none; margin-right:4px; transition:background .12s, color .12s,
    border-color .12s, transform .05s; }
.act-btn:last-child { margin-right:0; }
.act-btn:hover { background:var(--gold-dim); color:var(--gold); border-color:var(--gold-ring); }
.act-btn:active { transform:translateY(1px); }
.act-btn.red:hover { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
/* Spinner state on click — prevents double-fire and signals "working". */
.act-btn.is-loading { pointer-events:none; opacity:.7; cursor:not-allowed; background:var(--bg3); }

.pagination { display:flex; gap:8px; align-items:center; justify-content:center; padding:16px; }
.page-btn { padding:5px 12px; border:1px solid var(--border); border-radius:5px;
    background:var(--bg3); color:var(--t2); cursor:pointer; font-size:.82rem; }
.page-btn.active, .page-btn:hover { background:var(--gold); color:#fff; border-color:var(--gold); }

.tbl-empty { text-align:center; padding:40px; color:var(--t3); }
</style>

<div class="content-wrapper">
<div class="sis-students-wrap">

    <div class="page-hdr">
        <h1><i class="fa fa-users" style="color:var(--gold);margin-right:8px;"></i>Student List</h1>
        <a href="<?= base_url('sis/admission') ?>" class="btn-search" style="text-decoration:none;">
            <i class="fa fa-plus"></i> New Admission
        </a>
    </div>

    <div class="filter-bar">
        <div class="fg">
            <label>Search</label>
            <input id="searchQ" type="text" placeholder="Name / ID / Father name..." style="min-width:200px;">
        </div>
        <div class="fg">
            <label>Class</label>
            <select id="classFilter">
                <option value="">All Classes</option>
                <?php foreach ($class_map as $ord => $sections): ?>
                <option value="<?= htmlspecialchars($ord) ?>">Class <?= htmlspecialchars($ord) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Section</label>
            <select id="secFilter"><option value="">All Sections</option></select>
        </div>
        <div class="fg">
            <label>Gender</label>
            <select id="genderFilter">
                <option value="">All</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
        </div>
        <div class="fg">
            <label>Status</label>
            <select id="statusFilter">
                <option value="Active" selected>Active</option>
                <option value="Inactive">Inactive</option>
                <option value="TC">TC</option>
                <option value="">All (excl. Deleted)</option>
            </select>
        </div>
        <button class="btn-search" onclick="loadStudents(1)"><i class="fa fa-search"></i> Search</button>
    </div>

    <div id="bulkBar" style="display:none;background:var(--bg2);border:1px solid var(--gold);border-radius:10px;padding:12px 16px;margin-bottom:12px;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:.88rem;color:var(--t1);font-weight:600;" id="bulkCount">0 selected</span>
        <button class="btn-search" onclick="bulkDelete()" style="background:#E05C6F;font-size:.82rem;padding:6px 14px;"><i class="fa fa-trash"></i> Delete Selected</button>
        <button onclick="clearSelection()" style="padding:6px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:.82rem;color:var(--t2);">Clear Selection</button>
    </div>

    <div class="students-table-wrap">
        <table class="students-table">
            <thead>
                <tr>
                    <th style="width:30px"><input type="checkbox" id="selectAllCb" title="Select All"></th>
                    <th>#</th>
                    <th>Photo</th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Father Name</th>
                    <th>Class</th>
                    <th>Section</th>
                    <th>Gender</th>
                    <th>DOB</th>
                    <th>Admission</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="studentsTbody">
                <tr><td colspan="14" class="tbl-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
            </tbody>
        </table>
        <div class="pagination" id="paginationWrap"></div>
    </div>

</div>
</div>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var CLASS_MAP = <?= json_encode($class_map) ?>;
var currentPage = 1;

document.getElementById('classFilter').addEventListener('change', function () {
    const cls = this.value;
    const secSel = document.getElementById('secFilter');
    secSel.innerHTML = '<option value="">All Sections</option>';
    if (cls && CLASS_MAP[cls]) {
        CLASS_MAP[cls].forEach(s => {
            secSel.innerHTML += `<option value="${s}">Section ${s}</option>`;
        });
    }
    loadStudents(1);
});

document.getElementById('secFilter').addEventListener('change', () => loadStudents(1));
document.getElementById('searchQ').addEventListener('keydown', e => {
    if (e.key === 'Enter') loadStudents(1);
});

function loadStudents(page) {
    currentPage = page;
    const tbody = document.getElementById('studentsTbody');
    tbody.innerHTML = '<tr><td colspan="14" class="tbl-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>';

    var params = new URLSearchParams({
        query:   document.getElementById('searchQ').value.trim(),
        class:   document.getElementById('classFilter').value,
        section: document.getElementById('secFilter').value,
        gender:  document.getElementById('genderFilter').value,
        status:  document.getElementById('statusFilter').value,
        page:    page,
    });
    params.append(csrfName, csrfToken);

    fetch('<?= base_url('sis/search_student') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                   'X-Requested-With': 'XMLHttpRequest' },
        body: params.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success') {
            tbody.innerHTML = `<tr><td colspan="14" class="tbl-empty">${data.message || 'No results'}</td></tr>`;
            return;
        }
        const students = data.students;
        if (!students || students.length === 0) {
            tbody.innerHTML = '<tr><td colspan="14" class="tbl-empty">No students found.</td></tr>';
            document.getElementById('paginationWrap').innerHTML = '';
            return;
        }
        const offset   = (page - 1) * data.per_page;
        const fallback = '<?= base_url('tools/image/default-school.jpeg') ?>';
        tbody.innerHTML = students.map((s, i) => {
            const photo = s.photo || fallback;
            const badgeCls = s.status === 'Active' ? 'active' : (s.status === 'TC' ? 'tc' : 'inactive');
            return `<tr>
                <td><input type="checkbox" class="row-cb" value="${esc(s.user_id)}" data-name="${esc(s.name)}"></td>
                <td>${offset + i + 1}</td>
                <td><img src="${esc(photo)}" onerror="this.src='${fallback}'"
                    style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid var(--gold-ring);"></td>
                <td><code>${esc(s.user_id)}</code></td>
                <td><strong>${esc(s.name)}</strong></td>
                <td>${esc(s.father_name)}</td>
                <td>Class ${esc(s.class)}</td>
                <td>${esc(s.section)}</td>
                <td>${esc(s.gender)}</td>
                <td style="font-size:.85rem;">${esc(s.dob)}</td>
                <td style="font-size:.85rem;">${esc(s.admission_date)}</td>
                <td>${esc(s.phone)}</td>
                <td><span class="badge-${badgeCls}">${esc(s.status)}</span></td>
                <td style="white-space:nowrap;">
                    <a href="<?= base_url('sis/profile/') ?>${encodeURIComponent(s.user_id)}" class="act-btn" title="View Profile"><i class="fa fa-eye"></i></a>
                    <a href="<?= base_url('sis/edit_student/') ?>${encodeURIComponent(s.user_id)}" class="act-btn" title="Edit Student" style="background:var(--gold-dim);color:var(--gold);"><i class="fa fa-pencil"></i></a>
                    <a href="<?= base_url('sis/documents/') ?>${encodeURIComponent(s.user_id)}" class="act-btn" title="Documents"><i class="fa fa-folder-open-o"></i></a>
                    ${s.status === 'Active' ? `<button class="act-btn" style="background:#fef3c7;color:#92400e;" title="Mark Inactive" onclick="changeStatus(event, '${esc(s.user_id)}','${esc(s.name)}','Inactive')"><i class="fa fa-pause"></i></button>` : ''}
                    ${s.status === 'Inactive' ? `<button class="act-btn" style="background:#dcfce7;color:#166534;" title="Reactivate" onclick="changeStatus(event, '${esc(s.user_id)}','${esc(s.name)}','Active')"><i class="fa fa-play"></i></button>` : ''}
                    ${s.status !== 'Inactive' ? `<button class="act-btn red" title="Withdraw" onclick="withdrawStudent(event, '${esc(s.user_id)}','${esc(s.name)}')"><i class="fa fa-sign-out"></i></button>` : ''}
                </td>
            </tr>`;
        }).join('');

        // Pagination
        const total   = data.total;
        const perPage = data.per_page;
        const pages   = Math.ceil(total / perPage);
        let paginHtml = `<span style="color:var(--t3);font-size:.82rem;">${total} student(s)</span>`;
        if (pages > 1) {
            if (page > 1) paginHtml += `<button class="page-btn" onclick="loadStudents(${page-1})">&laquo; Prev</button>`;
            paginHtml += `<span class="page-btn active">Page ${page} / ${pages}</span>`;
            if (page < pages) paginHtml += `<button class="page-btn" onclick="loadStudents(${page+1})">Next &raquo;</button>`;
        }
        document.getElementById('paginationWrap').innerHTML = paginHtml;
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="14" class="tbl-empty">Failed to load students.</td></tr>';
    });
}

function esc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Toggle a button into a "loading" state — swap the icon with a
// spinner, mark the button disabled-by-class so the user can't
// double-click. Returns a `restore()` function the caller invokes
// in finally{} to put the button back to its original look.
// Returns a no-op when no button is provided (e.g. forceOverride
// recursion from withdrawStudent).
function setActionLoading(btn) {
    if (!btn || !btn.classList) return function () {};
    var originalHtml = btn.innerHTML;
    btn.classList.add('is-loading');
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    return function restore() {
        btn.classList.remove('is-loading');
        btn.innerHTML = originalHtml;
    };
}

// Lightweight Active <-> Inactive toggle. Distinct from `withdrawStudent`
// (which collects a reason and runs the full withdrawal pipeline incl.
// fee freeze + dues check). Use this for routine reactivations or quick
// toggles where the heavier withdrawal flow isn't appropriate.
function changeStatus(evt, userId, name, newStatus) {
    var verb = newStatus === 'Active' ? 'reactivate' : 'mark as Inactive';
    if (!confirm('Are you sure you want to ' + verb + ' "' + name + '"?')) return;

    var btn = evt && evt.currentTarget ? evt.currentTarget : null;
    var restore = setActionLoading(btn);

    var body = new URLSearchParams({ user_id: userId, status: newStatus });
    body.append(csrfName, csrfToken);

    fetch('<?= base_url('sis/change_status') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        // CSRF rotation — every server response refreshes the token.
        if (data.csrf_token) csrfToken = data.csrf_token;
        if (data.status === 'success') {
            // No need to restore — the row is about to be re-rendered
            // by loadStudents() below, replacing the whole button.
            alert(data.message || ('Status updated to ' + newStatus + '.'));
            loadStudents(currentPage);
        } else {
            restore();
            alert(data.message || 'Could not update status.');
        }
    })
    .catch(() => {
        restore();
        alert('Request failed.');
    });
}

function withdrawStudent(evt, userId, name, forceOverride) {
    if (!forceOverride) {
        var reason = prompt('Withdraw "' + name + '"?\nEnter reason (or leave blank for "Withdrawn"):');
        if (reason === null) return;
        window._withdrawReason = reason || 'Withdrawn';
        window._withdrawUserId = userId;
        window._withdrawName = name;
    }
    // On the recursive force-override call evt is null; setActionLoading
    // tolerates that and returns a no-op restore.
    var btn = evt && evt.currentTarget ? evt.currentTarget : null;
    var restore = setActionLoading(btn);

    var body = new URLSearchParams({ user_id: window._withdrawUserId || userId, reason: window._withdrawReason || 'Withdrawn' });
    if (forceOverride) body.append('force_override', 'true');
    body.append(csrfName, csrfToken);
    fetch('<?= base_url('sis/withdraw') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            // Row will be re-rendered — no restore needed.
            alert(data.message);
            loadStudents(currentPage);
        } else if (data.can_override && data.dues) {
            restore();
            if (confirm(data.message + '\n\nDo you want to withdraw anyway? (Admin override)')) {
                withdrawStudent(null, userId, name, true);
            }
        } else {
            restore();
            alert(data.message);
        }
    })
    .catch(() => {
        restore();
        alert('Request failed.');
    });
}

// Gender filter
document.getElementById('genderFilter').addEventListener('change', () => loadStudents(1));
// Status filter — reloads on change so admin can quickly flip between
// Active / Inactive / TC / All.
document.getElementById('statusFilter').addEventListener('change', () => loadStudents(1));

// Bulk selection
document.getElementById('selectAllCb').addEventListener('change', function() {
    document.querySelectorAll('.row-cb').forEach(cb => { cb.checked = this.checked; });
    updateBulkBar();
});
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('row-cb')) updateBulkBar();
});

function updateBulkBar() {
    var checked = document.querySelectorAll('.row-cb:checked');
    var bar = document.getElementById('bulkBar');
    if (checked.length > 0) {
        bar.style.display = 'flex';
        document.getElementById('bulkCount').textContent = checked.length + ' selected';
    } else {
        bar.style.display = 'none';
    }
    // Sync select-all
    var all = document.querySelectorAll('.row-cb');
    document.getElementById('selectAllCb').checked = all.length > 0 && checked.length === all.length;
}

function clearSelection() {
    document.querySelectorAll('.row-cb').forEach(cb => { cb.checked = false; });
    document.getElementById('selectAllCb').checked = false;
    updateBulkBar();
}

// FIXED: sequential delete with proper error handling (was parallel fire-and-forget)
async function bulkDelete() {
    var checked = document.querySelectorAll('.row-cb:checked');
    if (checked.length === 0) return;
    var names = Array.from(checked).map(cb => cb.dataset.name).slice(0, 5).join(', ');
    if (checked.length > 5) names += '... and ' + (checked.length - 5) + ' more';
    if (!confirm('Delete ' + checked.length + ' student(s)?\n' + names)) return;

    var ids = Array.from(checked).map(cb => cb.value);
    var succeeded = 0, failed = 0, errors = [];

    for (var i = 0; i < ids.length; i++) {
        var body = new URLSearchParams({ user_id: ids[i] });
        body.append(csrfName, csrfToken);
        try {
            var resp = await fetch('<?= base_url("sis/delete_student/") ?>' + encodeURIComponent(ids[i]), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
            });
            var data = await resp.json();
            if (data.status === 'success') {
                succeeded++;
            } else {
                failed++;
                errors.push(ids[i] + ': ' + (data.message || 'Unknown error'));
            }
        } catch (e) {
            failed++;
            errors.push(ids[i] + ': Network error');
        }
    }

    var msg = 'Deleted ' + succeeded + ' of ' + ids.length + ' student(s).';
    if (failed > 0) msg += '\nFailed: ' + failed + '\n' + errors.slice(0, 5).join('\n');
    alert(msg);
    loadStudents(currentPage);
}

// Initial load — show all enrolled students on page open
loadStudents(1);
</script>
