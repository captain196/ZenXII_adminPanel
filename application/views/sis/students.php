<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Lora:wght@500;600;700&display=swap');

/* ─────────────────────────────────────────────────────────────────────────
   SIS Student List — restyled to match the All-Staff page (nsa-* system).
   Same palette / shell / table / toolbar / action buttons / menu so the two
   listings look identical. Columns and data are unchanged.
   ───────────────────────────────────────────────────────────────────────── */
:root {
    --nsa-navy:   #2A1C12;
    --nsa-teal:   #BC5A3C;
    --nsa-sky:    #F7ECE7;
    --nsa-green:  #2FA875;
    --nsa-red:    #dc2626;
    --nsa-amber:  #d97706;
    --nsa-blue:   #2563eb;
    --nsa-text:   #22160F;
    --nsa-muted:  #64748b;
    --nsa-border: #E7DED6;
    --nsa-white:  #ffffff;
    --nsa-bg:     #F7F4F1;
    --nsa-shadow: 0 1px 12px rgba(12,30,56,.07);
    --nsa-radius: 12px;
}

/* ── Shell ── */
.nsa-wrap {
    font-family: 'Instrument Sans', sans-serif;
    background: var(--nsa-bg);
    color: var(--nsa-text);
    padding: 26px 22px 60px;
    min-height: 100vh;
}

/* ── Top bar ── */
.nsa-topbar {
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-wrap: wrap; gap: 14px; margin-bottom: 24px;
}
.nsa-topbar-right { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.nsa-page-title {
    font-family: 'Lora', serif; font-size: 25px; font-weight: 700;
    color: var(--nsa-navy); display: flex; align-items: center; gap: 10px; margin: 0 0 6px;
}
.nsa-page-title i { color: var(--nsa-teal); }
.nsa-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--nsa-muted); list-style: none; margin: 0; padding: 0;
}
.nsa-breadcrumb a { color: var(--nsa-teal); text-decoration: none; font-weight: 500; }
.nsa-breadcrumb a:hover { text-decoration: underline; }
.nsa-breadcrumb li::before { content: '/'; margin-right: 6px; color: #cbd5e1; }
.nsa-breadcrumb li:first-child::before { display: none; }

/* ── Main card ── */
.nsa-card {
    background: var(--nsa-white); border-radius: var(--nsa-radius);
    box-shadow: var(--nsa-shadow); border: 1px solid var(--nsa-border); overflow: hidden;
}

/* ── Toolbar ── */
.nsa-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; padding: 16px 20px;
    border-bottom: 1px solid var(--nsa-border); background: var(--nsa-bg);
}
.nsa-toolbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.nsa-search-wrap { position: relative; display: flex; align-items: center; min-width: 280px; }
.nsa-search-icon { position: absolute; left: 12px; color: var(--nsa-muted); font-size: 13px; pointer-events: none; }
.nsa-search-input {
    width: 100%; height: 38px; padding: 0 36px 0 34px;
    border: 1.5px solid var(--nsa-border); border-radius: 8px;
    font-size: 13.5px; color: var(--nsa-text); background: var(--nsa-white);
    font-family: 'Instrument Sans', sans-serif; outline: none;
    transition: border-color .14s, box-shadow .14s;
}
.nsa-search-input:focus { border-color: var(--nsa-teal); box-shadow: 0 0 0 3px rgba(188,90,60,.1); }
.nsa-search-clear {
    position: absolute; right: 8px; background: none; border: none; cursor: pointer;
    color: var(--nsa-muted); font-size: 12px; display: flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; border-radius: 50%; transition: background .12s, color .12s;
}
.nsa-search-clear:hover { background: #f1f5f9; color: var(--nsa-red); }
.nsa-filter-select {
    height: 38px; padding: 0 28px 0 10px; border: 1.5px solid var(--nsa-border);
    border-radius: 8px; font-size: 13px; color: var(--nsa-text); background: var(--nsa-white);
    font-family: 'Instrument Sans', sans-serif; cursor: pointer; outline: none; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 9px center; transition: border-color .14s;
}
.nsa-filter-select:focus { border-color: var(--nsa-teal); }

/* ── Table ── */
.nsa-table-wrap { overflow-x: auto; }
.nsa-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.nsa-table thead tr { background: var(--nsa-navy); }
.nsa-table thead th {
    padding: 12px 14px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px; color: rgba(255,255,255,.85);
    white-space: nowrap; border: none; text-align: left;
}
.nsa-table tbody tr { border-bottom: 1px solid var(--nsa-border); transition: background .1s; }
.nsa-table tbody tr:last-child { border-bottom: none; }
.nsa-table tbody tr:hover { background: var(--nsa-sky); }
.nsa-table td { padding: 11px 14px; color: var(--nsa-text); vertical-align: middle; border: none; }

/* Column widths */
.nsa-th-sno,    .nsa-td-sno    { width: 50px; text-align: center; color: var(--nsa-muted); font-weight: 600; }
.nsa-th-avatar, .nsa-td-avatar { width: 64px; text-align: center; }
.nsa-th-action, .nsa-td-action { width: 120px; text-align: center; }

/* ── Avatar ── */
.nsa-avatar-wrap { position: relative; display: inline-block; }
.nsa-avatar {
    width: 40px; height: 40px; border-radius: 50%; object-fit: cover;
    border: 2px solid var(--nsa-border); box-shadow: 0 1px 4px rgba(0,0,0,.1);
    transition: border-color .15s, transform .15s;
}
.nsa-table tbody tr:hover .nsa-avatar { border-color: var(--nsa-teal); transform: scale(1.08); }
.nsa-avatar-dot {
    position: absolute; bottom: 1px; right: 1px; width: 10px; height: 10px;
    border-radius: 50%; border: 2px solid #fff;
}
.nsa-dot-green { background: var(--nsa-green); }
.nsa-dot-red   { background: var(--nsa-red); }
.nsa-dot-gray  { background: var(--nsa-muted); }

/* ── ID badge ── */
.nsa-id-badge {
    display: inline-block; padding: 3px 9px; background: var(--nsa-sky); color: var(--nsa-teal);
    border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: .3px;
    border: 1px solid rgba(188,90,60,.2);
}

/* ── Name cell ── */
.nsa-name-cell strong { font-size: 13.5px; color: var(--nsa-navy); }

/* ── Phone link ── */
.nsa-phone-link {
    color: var(--nsa-teal); text-decoration: none; font-size: 13px;
    display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;
}
.nsa-phone-link:hover { text-decoration: underline; }

/* ── Status pill ── */
.sis-status { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.sis-status.active   { background: rgba(22,163,74,.1);  color: #16a34a; }
.sis-status.tc       { background: rgba(220,38,38,.1);  color: #dc2626; }
.sis-status.inactive { background: #f3f4f6;              color: #6b7280; }

/* ── Action buttons ── */
.nsa-action-group { display: flex; gap: 6px; justify-content: center; align-items: center; flex-wrap: nowrap; }
.nsa-action-btn {
    width: 30px; height: 30px; border-radius: 7px; display: inline-flex;
    align-items: center; justify-content: center; font-size: 13px; text-decoration: none;
    transition: transform .12s, opacity .12s; border: none; cursor: pointer;
}
.nsa-action-btn:hover { transform: translateY(-1px); opacity: .85; }
.nsa-action-view { background: #e0f2fe; color: #0369a1; }
.nsa-action-edit { background: #fef3c7; color: #d97706; }
.nsa-action-btn.is-loading { pointer-events: none; opacity: .7; cursor: not-allowed; }

/* ── Row action menu (⋮) ── */
.nsa-menu-wrap { position: relative; display: inline-flex; }
.nsa-menu-trigger { background: #f1f5f9; color: var(--nsa-muted); }
.nsa-menu-trigger:hover { background: #E7DED6; color: var(--nsa-text); }
.nsa-menu[hidden] { display: none; }
.nsa-menu {
    position: fixed; z-index: 1000; min-width: 188px; padding: 6px;
    background: var(--nsa-white); border: 1px solid var(--nsa-border);
    border-radius: 10px; box-shadow: 0 10px 30px rgba(12,30,56,.16); text-align: left;
}
.nsa-menu-item {
    display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 11px;
    border: none; background: none; cursor: pointer; border-radius: 7px;
    font-family: 'Instrument Sans', sans-serif; font-size: 13px; font-weight: 500;
    color: var(--nsa-text); text-align: left; white-space: nowrap; text-decoration: none;
}
.nsa-menu-item i { width: 16px; text-align: center; color: var(--nsa-muted); font-size: 13px; }
.nsa-menu-item:hover { background: var(--nsa-sky); }
.nsa-menu-item:hover i { color: var(--nsa-teal); }
.nsa-menu-sep { height: 1px; background: var(--nsa-border); margin: 5px 4px; }
.nsa-menu-danger { color: var(--nsa-red); }
.nsa-menu-danger i { color: var(--nsa-red); }
.nsa-menu-danger:hover { background: #fef2f2; }
.nsa-menu-danger:hover i { color: var(--nsa-red); }

/* ── Table footer + pagination ── */
.nsa-table-footer {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 12px 20px; border-top: 1px solid var(--nsa-border);
    background: var(--nsa-bg); font-size: 13px; color: var(--nsa-muted); flex-wrap: wrap;
}
.nsa-pagination { display: flex; gap: 8px; align-items: center; }
.nsa-page-btn {
    padding: 5px 12px; border: 1px solid var(--nsa-border); border-radius: 6px;
    background: var(--nsa-white); color: var(--nsa-text); cursor: pointer; font-size: 12.5px;
    font-family: 'Instrument Sans', sans-serif; transition: background .12s, color .12s, border-color .12s;
}
.nsa-page-btn:hover { border-color: var(--nsa-teal); color: var(--nsa-teal); }
.nsa-page-btn.active { background: var(--nsa-teal); color: #fff; border-color: var(--nsa-teal); cursor: default; }

/* ── Buttons ── */
.nsa-btn {
    display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 9px;
    font-size: 13.5px; font-weight: 600; cursor: pointer; border: none; text-decoration: none;
    transition: opacity .13s, transform .1s; font-family: 'Instrument Sans', sans-serif; white-space: nowrap;
}
.nsa-btn:hover { opacity: .86; transform: translateY(-1px); }
.nsa-btn-sm { padding: 6px 14px; font-size: 12.5px; }
.nsa-btn-primary { background: var(--nsa-teal); color: #fff; box-shadow: 0 2px 10px rgba(188,90,60,.28); }
.nsa-btn-amber   { background: #d97706; color: #fff; box-shadow: 0 2px 10px rgba(217,119,6,.22); }
.nsa-btn-ghost   { background: var(--nsa-white); color: var(--nsa-text); border: 1.5px solid var(--nsa-border); }
.nsa-btn-ghost:hover { border-color: var(--nsa-teal); color: var(--nsa-teal); opacity: 1; }

/* ── Empty / message row ── */
.nsa-empty-row { text-align: center; padding: 60px 20px !important; color: var(--nsa-muted); }
.nsa-empty-row i { font-size: 48px; opacity: .25; display: block; margin-bottom: 12px; }
.nsa-empty-row p { margin: 0; font-size: 15px; }

/* ── Loading shimmer — skeleton rows shown while the table fetches ── */
.sk {
    background: linear-gradient(90deg, var(--nsa-sky) 25%, var(--nsa-bg) 50%, var(--nsa-sky) 75%);
    background-size: 200% 100%; animation: sisSkel 1.5s infinite; border-radius: 6px; display: inline-block;
}
@keyframes sisSkel { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
.skeleton-row td { padding: 11px 14px; border-bottom: 1px solid var(--nsa-border); vertical-align: middle; }
.sk-line { height: 11px; width: 80%; }
.sk-line.sm { width: 50%; }
.sk-line.lg { width: 90%; }
.sk-av { width: 40px; height: 40px; border-radius: 50%; }
.sk-chip { width: 60px; height: 22px; border-radius: 20px; }
.sk-act { width: 30px; height: 30px; border-radius: 7px; margin-right: 4px; }
</style>

<div class="content-wrapper">
<div class="nsa-wrap">

<?php if ($this->session->flashdata('import_result')): ?>
    <?php
        // Bulk-import summary. import_students() redirects here after a CSV/XLSX
        // import; without this block the summary (counts + per-row reasons) was
        // built and then silently discarded, so a fully-skipped import looked
        // like "nothing happened".
        $flashMsg = $this->session->flashdata('import_result');
        $isError  = (stripos($flashMsg, 'fail') !== false || stripos($flashMsg, 'error') !== false)
                    && stripos($flashMsg, 'Imported Successfully: 0') !== false;
    ?>
    <div class="alert <?= $isError ? 'alert-danger' : 'alert-success' ?>"
         role="alert"
         style="margin-bottom:18px;padding:12px 16px;border-radius:8px;font-size:.9rem;line-height:1.5;">
        <?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

    <!-- ══ TOP BAR ══ -->
    <div class="nsa-topbar">
        <div class="nsa-topbar-left">
            <h1 class="nsa-page-title"><i class="fa fa-users"></i> Student List</h1>
            <ol class="nsa-breadcrumb">
                <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li>Student List</li>
            </ol>
        </div>
        <div class="nsa-topbar-right">
            <a href="<?= base_url('sis/master_student') ?>" class="nsa-btn nsa-btn-amber">
                <i class="fa fa-upload"></i> Import Students
            </a>
            <a href="<?= base_url('sis/id_card') ?>" class="nsa-btn nsa-btn-ghost">
                <i class="fa fa-id-card-o"></i> ID Cards
            </a>
            <a href="<?= base_url('sis/admission') ?>" class="nsa-btn nsa-btn-primary">
                <i class="fa fa-plus"></i> New Admission
            </a>
        </div>
    </div>

    <!-- ══ TABLE CARD ══ -->
    <div class="nsa-card">

        <!-- Toolbar -->
        <div class="nsa-toolbar">
            <div class="nsa-search-wrap">
                <i class="fa fa-search nsa-search-icon"></i>
                <input type="text" id="searchQ" class="nsa-search-input"
                       placeholder="Search by name, ID, class, phone…">
                <button class="nsa-search-clear" id="clearSearch" title="Clear" style="display:none;">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="nsa-toolbar-right">
                <select id="classFilter" class="nsa-filter-select">
                    <option value="">All Classes</option>
                    <?php foreach ($class_map as $ord => $sections): ?>
                    <option value="<?= htmlspecialchars($ord) ?>">Class <?= htmlspecialchars($ord) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="secFilter" class="nsa-filter-select"><option value="">All Sections</option></select>
                <select id="genderFilter" class="nsa-filter-select">
                    <option value="">All Genders</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
                <select id="statusFilter" class="nsa-filter-select">
                    <option value="Active" selected>Active</option>
                    <option value="Inactive">Inactive</option>
                    <option value="TC">TC</option>
                    <option value="">All (excl. Deleted)</option>
                </select>
                <button class="nsa-btn nsa-btn-ghost nsa-btn-sm" id="resetFilters">
                    <i class="fa fa-refresh"></i> Reset
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="nsa-table-wrap">
            <table class="nsa-table" id="studentsTable">
                <thead>
                    <tr>
                        <th class="nsa-th-sno">#</th>
                        <th class="nsa-th-avatar">Photo</th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>DOB</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th class="nsa-th-action">Actions</th>
                    </tr>
                </thead>
                <tbody id="studentsTbody">
                    <!-- Shimmer rows injected by renderSkeleton() on first paint and every fetch. -->
                </tbody>
            </table>
        </div>

        <!-- Table footer -->
        <div class="nsa-table-footer">
            <span id="rowCountText"></span>
            <div class="nsa-pagination" id="paginationWrap"></div>
        </div>

    </div><!-- /.nsa-card -->

</div>
</div>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var CLASS_MAP = <?= json_encode($class_map) ?>;
var CAN_RESET_PW = <?= json_encode(in_array(
    $this->session->userdata('admin_role'),
    ['Super Admin', 'School Super Admin', 'Admin', 'Principal'],
    true
)) ?>;
// Delete is gated to the same roles the controller allows (MANAGE_ROLES).
var CAN_DELETE = <?= json_encode(in_array(
    $this->session->userdata('admin_role'),
    ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Front Office'],
    true
)) ?>;
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

// Live search — filter as you type (debounced so we don't hit the server on
// every keystroke). Enter still triggers an immediate search.
var _searchDebounce;
document.getElementById('searchQ').addEventListener('input', function () {
    document.getElementById('clearSearch').style.display = this.value ? 'flex' : 'none';
    clearTimeout(_searchDebounce);
    _searchDebounce = setTimeout(() => loadStudents(1), 300);
});
document.getElementById('searchQ').addEventListener('keydown', e => {
    if (e.key === 'Enter') { clearTimeout(_searchDebounce); loadStudents(1); }
});
// Clear-search button — empties the box and reloads.
document.getElementById('clearSearch').addEventListener('click', function () {
    var box = document.getElementById('searchQ');
    box.value = ''; this.style.display = 'none'; box.focus();
    loadStudents(1);
});
// Reset — clears search + all filters back to defaults and reloads.
document.getElementById('resetFilters').addEventListener('click', function () {
    document.getElementById('searchQ').value = '';
    document.getElementById('clearSearch').style.display = 'none';
    document.getElementById('classFilter').value = '';
    document.getElementById('secFilter').innerHTML = '<option value="">All Sections</option>';
    document.getElementById('genderFilter').value = '';
    document.getElementById('statusFilter').value = 'Active';
    loadStudents(1);
});

// Build a block of shimmer rows that mirrors the real table's 10 columns,
// so the loading state shows the page's structure instead of a lone spinner.
function renderSkeleton(rows) {
    var cell =
        '<td class="nsa-td-sno"><span class="sk sk-line sm" style="width:18px;"></span></td>' +
        '<td class="nsa-td-avatar"><span class="sk sk-av"></span></td>' +
        '<td><span class="sk sk-chip"></span></td>' +
        '<td><span class="sk sk-line lg"></span></td>' +
        '<td><span class="sk sk-line sm"></span></td>' +
        '<td><span class="sk sk-line sm"></span></td>' +
        '<td><span class="sk sk-line"></span></td>' +
        '<td><span class="sk sk-line"></span></td>' +
        '<td><span class="sk sk-chip"></span></td>' +
        '<td class="nsa-td-action"><span class="sk sk-act"></span><span class="sk sk-act"></span><span class="sk sk-act"></span></td>';
    var html = '';
    for (var i = 0; i < (rows || 8); i++) html += '<tr class="skeleton-row">' + cell + '</tr>';
    return html;
}

// `silent` skips the shimmer placeholder so a background resync (e.g. after a
// single-row delete) doesn't flash the table — the current rows stay on screen
// until the fresh ones drop in.
function loadStudents(page, silent) {
    currentPage = page;
    const tbody = document.getElementById('studentsTbody');
    if (!silent) tbody.innerHTML = renderSkeleton(8);

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
            tbody.innerHTML = `<tr><td colspan="10" class="nsa-empty-row"><i class="fa fa-exclamation-circle"></i><p>${data.message || 'No results'}</p></td></tr>`;
            document.getElementById('rowCountText').textContent = '';
            document.getElementById('paginationWrap').innerHTML = '';
            return;
        }
        const students = data.students;
        if (!students || students.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="nsa-empty-row"><i class="fa fa-user-times"></i><p>No students found.</p></td></tr>';
            document.getElementById('rowCountText').textContent = '0 students';
            document.getElementById('paginationWrap').innerHTML = '';
            return;
        }
        const offset   = (page - 1) * data.per_page;
        const fallback = '<?= base_url('tools/image/default-school.jpeg') ?>';
        tbody.innerHTML = students.map((s, i) => {
            const photo = s.photo || fallback;
            const statusCls = s.status === 'Active' ? 'active' : (s.status === 'TC' ? 'tc' : 'inactive');
            const dotCls    = s.status === 'Active' ? 'nsa-dot-green' : (s.status === 'TC' ? 'nsa-dot-red' : 'nsa-dot-gray');
            return `<tr>
                <td class="nsa-td-sno">${offset + i + 1}</td>
                <td class="nsa-td-avatar">
                    <span class="nsa-avatar-wrap">
                        <img src="${esc(photo)}" onerror="this.src='${fallback}'" loading="lazy" decoding="async"
                             class="nsa-avatar" alt="${esc(s.name)}">
                        <span class="nsa-avatar-dot ${dotCls}"></span>
                    </span>
                </td>
                <td><span class="nsa-id-badge">${esc(s.user_id)}</span></td>
                <td class="nsa-name-cell"><strong>${esc(s.name)}</strong></td>
                <td>Class ${esc(s.class)}</td>
                <td>${esc(s.section)}</td>
                <td style="font-size:12.5px;color:var(--nsa-muted);white-space:nowrap;">${esc(s.dob)}</td>
                <td><a href="tel:${esc(s.phone)}" class="nsa-phone-link">${esc(s.phone)}</a></td>
                <td><span class="sis-status ${statusCls}">${esc(s.status)}</span></td>
                <td class="nsa-td-action">
                    <div class="nsa-action-group">
                        <a href="<?= base_url('sis/profile/') ?>${encodeURIComponent(s.user_id)}" class="nsa-action-btn nsa-action-view" title="View Profile"><i class="fa fa-eye"></i></a>
                        <a href="<?= base_url('sis/edit_student/') ?>${encodeURIComponent(s.user_id)}" class="nsa-action-btn nsa-action-edit" title="Edit Student"><i class="fa fa-pencil"></i></a>
                        <span class="nsa-menu-wrap">
                            <button type="button" class="nsa-action-btn nsa-menu-trigger" title="More actions" aria-haspopup="true" aria-expanded="false"><i class="fa fa-ellipsis-v"></i></button>
                            <div class="nsa-menu" role="menu" hidden>
                                <a href="<?= base_url('sis/documents/') ?>${encodeURIComponent(s.user_id)}" class="nsa-menu-item"><i class="fa fa-folder-open-o"></i> Documents</a>
                                ${CAN_RESET_PW ? `<button type="button" class="nsa-menu-item js-reset-pw-btn" data-user-id="${esc(s.user_id)}" data-user-name="${esc(s.name)}"><i class="fa fa-key"></i> Reset Password</button>` : ''}
                                ${s.status === 'Active' ? `<button type="button" class="nsa-menu-item" onclick="changeStatus(event, '${esc(s.user_id)}','${esc(s.name)}','Inactive')"><i class="fa fa-pause"></i> Mark Inactive</button>` : ''}
                                ${s.status === 'Inactive' ? `<button type="button" class="nsa-menu-item" onclick="changeStatus(event, '${esc(s.user_id)}','${esc(s.name)}','Active')"><i class="fa fa-play"></i> Reactivate</button>` : ''}
                                ${s.status !== 'Inactive' ? `<button type="button" class="nsa-menu-item" onclick="withdrawStudent(event, '${esc(s.user_id)}','${esc(s.name)}')"><i class="fa fa-sign-out"></i> Withdraw</button>` : ''}
                                ${CAN_DELETE ? `<div class="nsa-menu-sep"></div><button type="button" class="nsa-menu-item nsa-menu-danger" onclick="deleteStudent(event, '${esc(s.user_id)}','${esc(s.name)}')"><i class="fa fa-trash"></i> Delete Student</button>` : ''}
                            </div>
                        </span>
                    </div>
                </td>
            </tr>`;
        }).join('');

        // Footer count + pagination
        const total   = data.total;
        const perPage = data.per_page;
        const pages   = Math.ceil(total / perPage);
        document.getElementById('rowCountText').textContent =
            total + ' student' + (total === 1 ? '' : 's');
        let paginHtml = '';
        if (pages > 1) {
            if (page > 1) paginHtml += `<button class="nsa-page-btn" onclick="loadStudents(${page-1})">&laquo; Prev</button>`;
            paginHtml += `<span class="nsa-page-btn active">Page ${page} / ${pages}</span>`;
            if (page < pages) paginHtml += `<button class="nsa-page-btn" onclick="loadStudents(${page+1})">Next &raquo;</button>`;
        }
        document.getElementById('paginationWrap').innerHTML = paginHtml;
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="10" class="nsa-empty-row"><i class="fa fa-exclamation-triangle"></i><p>Failed to load students.</p></td></tr>';
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

// Per-row delete. Hits the same sis/delete_student endpoint the old bulk
// flow used — a recoverable soft-delete by default (fee records preserved,
// CRM back-link cleared). The endpoint enforces MANAGE_ROLES server-side;
// CAN_DELETE just hides the button for roles that can't use it.
function deleteStudent(evt, userId, name) {
    if (!confirm('Delete "' + name + '"?\n\n'
        + 'The student is removed from active lists (recoverable). '
        + 'Fee records are preserved.\n\nProceed?')) return;

    var btn = evt && evt.currentTarget ? evt.currentTarget : null;
    var restore = setActionLoading(btn);
    // Shared ZenXii loading animation (same as school_config).
    if (window.ZXLoader) ZXLoader.show('Deleting ' + name + '…');

    var body = new URLSearchParams({ user_id: userId });
    body.append(csrfName, csrfToken);

    fetch('<?= base_url("sis/delete_student/") ?>' + encodeURIComponent(userId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (window.ZXLoader) ZXLoader.hide(true);
        // CSRF rotation — every server response refreshes the token.
        if (data.csrf_token) csrfToken = data.csrf_token;
        if (data.status === 'success') {
            // Live update — fade just this row out for instant feedback, then
            // silently resync so counts/pagination stay correct (no flash, no
            // blocking alert, no full reload).
            var row = btn && btn.closest ? btn.closest('tr') : null;
            if (row) {
                row.style.transition = 'opacity .18s ease';
                row.style.opacity = '0';
                setTimeout(function () {
                    var tb = document.getElementById('studentsTbody');
                    if (row.parentNode) row.parentNode.removeChild(row);
                    var remaining = tb ? tb.querySelectorAll('tr').length : 0;
                    var target = (remaining === 0 && currentPage > 1) ? currentPage - 1 : currentPage;
                    loadStudents(target, true);
                }, 180);
            } else {
                loadStudents(currentPage, true);
            }
        } else {
            restore();
            alert(data.message || 'Could not delete student.');
        }
    })
    .catch(() => {
        if (window.ZXLoader) ZXLoader.hide(true);
        restore();
        alert('Request failed.');
    });
}

// Row action menu (⋮) — open/position/close. Rows are re-rendered on every
// load, so this uses event delegation off document. Menu is position:fixed so
// the table's overflow can't clip it. One open at a time.
(function() {
    function closeAll(except) {
        document.querySelectorAll('.nsa-menu').forEach(function(m) {
            if (m === except || m.hidden) return;
            m.hidden = true;
            var wrap = m.closest('.nsa-menu-wrap');
            var t = wrap && wrap.querySelector('.nsa-menu-trigger');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }
    function place(trigger, menu) {
        menu.hidden = false;
        var r  = trigger.getBoundingClientRect();
        var mw = menu.offsetWidth, mh = menu.offsetHeight;
        var left = Math.max(8, r.right - mw);
        var top  = r.bottom + 6;
        if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
        menu.style.left = left + 'px';
        menu.style.top  = top + 'px';
    }
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest && e.target.closest('.nsa-menu-trigger');
        if (trigger) {
            e.preventDefault(); e.stopPropagation();
            var wrap = trigger.closest('.nsa-menu-wrap');
            var menu = wrap && wrap.querySelector('.nsa-menu');
            if (!menu) return;
            var wasOpen = !menu.hidden;
            closeAll();
            if (wasOpen) { menu.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }
            else         { place(trigger, menu); trigger.setAttribute('aria-expanded', 'true'); }
            return;
        }
        if (e.target.closest && e.target.closest('.nsa-menu-item')) { closeAll(); return; }
        closeAll();
    });
    window.addEventListener('scroll', function() { closeAll(); }, true);
    window.addEventListener('resize', function() { closeAll(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAll(); });
})();

// Initial load — show all enrolled students on page open
loadStudents(1);
</script>

<?php
if (in_array($this->session->userdata('admin_role'),
              ['Super Admin', 'School Super Admin', 'Admin', 'Principal'], true)) {
    $this->load->view('partials/reset_password_modal', [
        'reset_pw_endpoint' => base_url('sis/reset_password'),
        'reset_pw_label'    => 'Student',
    ]);
}
?>
