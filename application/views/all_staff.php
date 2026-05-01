<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="nsa-wrap">

    <!-- ══ TOP BAR ══ -->
    <div class="nsa-topbar">
        <div class="nsa-topbar-left">
            <h1 class="nsa-page-title">
                <i class="fa fa-users"></i> All Staff
            </h1>
            <ol class="nsa-breadcrumb">
                <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li>All Staff</li>
            </ol>
        </div>
        <div class="nsa-topbar-right">
             <a href="<?= base_url('staff/master_staff') ?>" class="nsa-btn nsa-btn-amber">
                <i class="fa fa-upload"></i> Import Staff
            </a>

            <a href="<?= base_url('staff/teacher_id_card') ?>" class="nsa-btn nsa-btn-ghost">
                <i class="fa fa-id-card-o"></i> ID Cards
            </a>

            <a href="<?= base_url('staff/new_staff') ?>" class="nsa-btn nsa-btn-primary">
                <i class="fa fa-plus"></i> Add New Staff
            </a>

            <?php
            // Only show Migrate Roles if there are staff without roles assigned
            $needsMigration = false;
            foreach ($staff as $_s) {
                if (empty($_s['staff_roles']) || !is_array($_s['staff_roles'])) {
                    $needsMigration = true;
                    break;
                }
            }
            if ($needsMigration):
            ?>
            <button type="button" class="nsa-btn nsa-btn-ghost nsa-btn-sm" id="migrateRolesBtn"
                    onclick="migrateStaffRoles()" title="Auto-assign roles to staff based on their Position field">
                <i class="fa fa-magic"></i> Migrate Roles
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ STAT STRIP ══ -->
    <?php
        // Compute stats server-side for accuracy
        $totalCount    = count($staff);
        $teachingCount = 0;
        $nonTeachingCount = 0;
        $deptSet = [];
        foreach ($staff as $_s) {
            $isTeaching = false;
            $sRoles = $_s['staff_roles'] ?? [];
            if (!empty($sRoles) && is_array($sRoles)) {
                foreach ($sRoles as $_rid) {
                    if (($staff_role_defs[$_rid]['category'] ?? '') === 'Teaching') {
                        $isTeaching = true;
                        break;
                    }
                }
            } else {
                $pos = $_s['Position'] ?? '';
                $isTeaching = (stripos($pos, 'teacher') !== false || stripos($pos, 'lecturer') !== false);
            }
            if ($isTeaching) $teachingCount++;
            else $nonTeachingCount++;

            $d = trim($_s['Department'] ?? '');
            if ($d !== '') $deptSet[$d] = true;
        }
    ?>
    <div class="nsa-stat-strip">
        <div class="nsa-stat-card">
            <div class="nsa-stat-icon" style="background:rgba(15,118,110,.12);color:var(--nsa-teal);">
                <i class="fa fa-users"></i>
            </div>
            <div>
                <div class="nsa-stat-num"><?= $totalCount ?></div>
                <div class="nsa-stat-lbl">Total Staff</div>
            </div>
        </div>
        <div class="nsa-stat-card">
            <div class="nsa-stat-icon" style="background:rgba(37,99,235,.12);color:#2563eb;">
                <i class="fa fa-graduation-cap"></i>
            </div>
            <div>
                <div class="nsa-stat-num"><?= $teachingCount ?></div>
                <div class="nsa-stat-lbl">Teaching</div>
            </div>
        </div>
        <div class="nsa-stat-card">
            <div class="nsa-stat-icon" style="background:rgba(217,119,6,.12);color:#d97706;">
                <i class="fa fa-briefcase"></i>
            </div>
            <div>
                <div class="nsa-stat-num"><?= $nonTeachingCount ?></div>
                <div class="nsa-stat-lbl">Non-Teaching</div>
            </div>
        </div>
        <div class="nsa-stat-card">
            <div class="nsa-stat-icon" style="background:rgba(13,122,95,.12);color:var(--nsa-green);">
                <i class="fa fa-building-o"></i>
            </div>
            <div>
                <div class="nsa-stat-num"><?= count($deptSet) ?></div>
                <div class="nsa-stat-lbl">Departments</div>
            </div>
        </div>
    </div>

    <!-- ══ TABLE CARD ══ -->
    <div class="nsa-card">

        <!-- Toolbar -->
        <div class="nsa-toolbar">
            <div class="nsa-search-wrap">
                <i class="fa fa-search nsa-search-icon"></i>
                <input type="text" id="staffSearch"
                       class="nsa-search-input"
                       placeholder="Search by name, ID, position, phone…">
                <button class="nsa-search-clear" id="clearSearch" title="Clear" style="display:none;">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="nsa-toolbar-right">
                <select id="filterDept" class="nsa-filter-select">
                    <option value="">All Departments</option>
                    <?php
                    $departments = array_unique(array_filter(array_column($staff, 'Department')));
                    sort($departments);
                    foreach ($departments as $dpt):
                    ?>
                    <option value="<?= htmlspecialchars($dpt) ?>"><?= htmlspecialchars($dpt) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterPosition" class="nsa-filter-select">
                    <option value="">All Positions</option>
                    <?php
                    $positions = array_unique(array_filter(array_column($staff, 'Position')));
                    sort($positions);
                    foreach ($positions as $pos):
                    ?>
                    <option value="<?= htmlspecialchars($pos) ?>"><?= htmlspecialchars($pos) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="nsa-btn nsa-btn-ghost nsa-btn-sm" id="resetFilters">
                    <i class="fa fa-refresh"></i> Reset
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="nsa-table-wrap">
            <table class="nsa-table" id="staffTable">
                <thead>
                    <tr>
                        <th class="nsa-th-check">
                            <input type="checkbox" id="selectAllStaff" class="nsa-checkbox">
                        </th>
                        <th class="nsa-th-sno">#</th>
                        <th>Staff</th>
                        <th class="nsa-sortable" data-col="3">Designation</th>
                        <th class="nsa-sortable" data-col="4">Department</th>
                        <th>Phone</th>
                        <th>Subjects</th>
                        <th>Status</th>
                        <th class="nsa-sortable" data-col="8">Joined</th>
                        <th class="nsa-th-action">Action</th>
                    </tr>
                </thead>
                <tbody id="staffTbody">
                    <?php if (empty($staff)): ?>
                    <tr>
                        <td colspan="10" class="nsa-empty-row"><!-- 10 cols: check, sno, staff, designation, dept, phone, subjects, status, joined, action -->
                            <i class="fa fa-users-slash"></i>
                            <p>No staff records found.</p>
                            <a href="<?= base_url('staff/new_staff') ?>" class="nsa-btn nsa-btn-primary nsa-btn-sm">
                                <i class="fa fa-plus"></i> Add First Staff
                            </a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php $i = 1; foreach ($staff as $s): ?>
                    <?php
                        $pic = !empty($s['_profilePic'])
                             ? htmlspecialchars($s['_profilePic'])
                             : base_url('tools/dist/img/user2-160x160.jpg');

                        $uid      = htmlspecialchars($s['User ID']      ?? 'N/A');
                        $name     = htmlspecialchars($s['Name']         ?? 'N/A');
                        $position = htmlspecialchars($s['Position']     ?? '—');
                        $dept     = htmlspecialchars($s['Department']   ?? '—');
                        $phone    = htmlspecialchars($s['Phone Number'] ?? '—');
                        $email    = htmlspecialchars($s['Email']        ?? '—');
                        $userId   = $s['User ID'] ?? '';
                        // Joining date: actual Firestore field is "Date Of Joining"
                        // (set by Staff::new_staff). Stored as dd-mm-yyyy (e.g. 07-04-2026)
                        // — strtotime can't parse that on Windows, so use createFromFormat.
                        $joinDate = $s['Date Of Joining'] ?? $s['Joining Date'] ?? $s['joining_date'] ?? '';
                        $joinDateFmt = '—';
                        if (!empty($joinDate)) {
                            $dt = DateTime::createFromFormat('d-m-Y', $joinDate)
                               ?: DateTime::createFromFormat('Y-m-d', $joinDate)
                               ?: false;
                            if ($dt instanceof DateTime) {
                                $joinDateFmt = $dt->format('d M Y');
                            }
                        }

                        // Role category for avatar dot color
                        $sRolesCheck = $s['staff_roles'] ?? [];
                        $avatarClass = 'nsa-badge-gray';
                        if (!empty($sRolesCheck) && is_array($sRolesCheck)) {
                            foreach ($sRolesCheck as $_rid) {
                                $cat = $staff_role_defs[$_rid]['category'] ?? '';
                                if ($cat === 'Teaching') { $avatarClass = 'nsa-badge-blue'; break; }
                                elseif ($cat === 'Administrative') { $avatarClass = 'nsa-badge-amber'; break; }
                                elseif ($cat === 'Non-Teaching') { $avatarClass = 'nsa-badge-green'; break; }
                            }
                        }
                    ?>
                    <tr class="nsa-staff-row"
                        data-name="<?= strtolower($name) ?>"
                        data-id="<?= strtolower($uid) ?>"
                        data-position="<?= strtolower($position) ?>"
                        data-dept="<?= strtolower($dept) ?>"
                        data-phone="<?= $phone ?>"
                        data-email="<?= strtolower($email) ?>">

                        <td class="nsa-td-check">
                            <input type="checkbox" class="row-checkbox-staff nsa-checkbox">
                        </td>

                        <td class="nsa-td-sno"><?= $i++ ?></td>

                        <!-- Staff: Photo + Name + ID -->
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div class="nsa-avatar-wrap">
                                    <img src="<?= $pic ?>" alt="<?= $name ?>" class="nsa-avatar"
                                         onerror="this.src='<?= base_url('tools/dist/img/user2-160x160.jpg') ?>'">
                                    <span class="nsa-avatar-dot <?= $avatarClass ?>"></span>
                                </div>
                                <div>
                                    <strong style="font-size:13px"><?= $name ?></strong>
                                    <div style="font-size:11px;color:var(--nsa-muted)"><?= $uid ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- Designation -->
                        <td>
                            <?php
                                $designation = htmlspecialchars($s['designation'] ?? $s['Position'] ?? '—');
                                $sRoles = $s['staff_roles'] ?? [];
                                $sPrimary = $s['primary_role'] ?? '';
                                $rDef = !empty($sPrimary) ? ($staff_role_defs[$sPrimary] ?? null) : null;
                                $catClass = match($rDef['category'] ?? '') {
                                    'Teaching'       => 'nsa-badge-blue',
                                    'Administrative' => 'nsa-badge-amber',
                                    'Non-Teaching'   => 'nsa-badge-green',
                                    default          => 'nsa-badge-gray',
                                };
                            ?>
                            <div style="font-size:12.5px"><?= $designation ?></div>
                            <?php if ($rDef): ?>
                            <span class="nsa-role-pill <?= $catClass ?>" style="font-size:10px;margin-top:2px">
                                <?= htmlspecialchars($rDef['label'] ?? '') ?>
                            </span>
                            <?php endif; ?>
                        </td>

                        <!-- Department -->
                        <td style="font-size:12.5px"><?= $dept ?></td>

                        <!-- Phone -->
                        <td>
                            <a href="tel:<?= $phone ?>" class="nsa-phone-link" style="font-size:12px">
                                <?= $phone ?>
                            </a>
                        </td>

                        <!-- Teaching Subjects -->
                        <td>
                            <?php
                                $teachSubs = $s['teaching_subjects'] ?? [];
                                if (is_array($teachSubs) && !empty($teachSubs)):
                                    foreach ($teachSubs as $ts):
                            ?>
                                <span style="display:inline-block;padding:1px 6px;margin:1px;border-radius:4px;background:rgba(15,118,110,.08);color:var(--nsa-teal);font-size:10px;font-weight:600"><?= htmlspecialchars($ts) ?></span>
                            <?php endforeach; else: ?>
                                <span style="color:var(--nsa-muted);font-size:11px">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Status -->
                        <td>
                            <?php $status = $s['status'] ?? $s['Status'] ?? 'Active'; ?>
                            <span class="staff-status-badge" data-staff-status="<?= htmlspecialchars($status) ?>"
                                  style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;
                                <?= $status === 'Active' ? 'background:rgba(22,163,74,.1);color:#16a34a' : 'background:rgba(220,38,38,.1);color:#dc2626' ?>">
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </td>

                        <!-- Joined -->
                        <td style="font-size:11px;color:var(--nsa-muted);white-space:nowrap"><?= $joinDateFmt ?></td>

                        <td class="nsa-td-action">
                            <div class="nsa-action-group">
                                <a href="<?= base_url('staff/teacher_profile/' . $userId) ?>"
                                   class="nsa-action-btn nsa-action-view" title="View Profile">
                                    <i class="fa fa-eye"></i>
                                </a>
                                <a href="<?= base_url('staff/edit_staff/' . $userId) ?>"
                                   class="nsa-action-btn nsa-action-edit" title="Edit Staff">
                                    <i class="fa fa-pencil"></i>
                                </a>
                                <?php if ($status === 'Active'): ?>
                                <button type="button"
                                        class="nsa-action-btn staff-status-toggle"
                                        data-user-id="<?= htmlspecialchars($userId) ?>"
                                        data-staff-name="<?= htmlspecialchars($s['Name'] ?? $s['name'] ?? $userId) ?>"
                                        data-target-status="Inactive"
                                        title="Deactivate Staff"
                                        style="background:rgba(220,38,38,.08);color:#dc2626;border:none;cursor:pointer">
                                    <i class="fa fa-ban"></i>
                                </button>
                                <?php else: ?>
                                <button type="button"
                                        class="nsa-action-btn staff-status-toggle"
                                        data-user-id="<?= htmlspecialchars($userId) ?>"
                                        data-staff-name="<?= htmlspecialchars($s['Name'] ?? $s['name'] ?? $userId) ?>"
                                        data-target-status="Active"
                                        title="Reactivate Staff"
                                        style="background:rgba(22,163,74,.08);color:#16a34a;border:none;cursor:pointer">
                                    <i class="fa fa-check-circle"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table footer -->
        <div class="nsa-table-footer">
            <span id="rowCountText" class="nsa-row-count"></span>
            <div class="nsa-selection-actions" id="selectionActions" style="display:none;">
                <span id="selectedCount" class="nsa-selected-count"></span>
                <button class="nsa-btn nsa-btn-ghost nsa-btn-sm" onclick="clearSelection()">
                    <i class="fa fa-times"></i> Deselect All
                </button>
            </div>
        </div>

    </div><!-- /.nsa-card -->

    <!-- No results message -->
    <div class="nsa-no-results" id="noResultsMsg" style="display:none;">
        <i class="fa fa-search"></i>
        <h3>No results found</h3>
        <p>Try adjusting your search or filter.</p>
        <button class="nsa-btn nsa-btn-ghost" onclick="resetAllFilters()">
            <i class="fa fa-refresh"></i> Clear Filters
        </button>
    </div>

</div><!-- /.nsa-wrap -->
</div><!-- /.content-wrapper -->


<script>
/* ── Phase 1: Activate / Deactivate staff toggle ──
 * Phase 1 scope: just flips status in Firestore + repaints the row.
 * Phase 2 (login enforcement) and Phase 3 (subjectAssignments cleanup) are
 * deliberately not done yet — users won't be auto-logged-out from teacher app
 * and existing class assignments stay live until later phases ship.
 *
 * Vanilla JS — no jQuery dependency, works regardless of script order.
 */
(function() {
    var STATUS_URL  = '<?= base_url("staff/set_status/") ?>';
    var CSRF_NAME   = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_VALUE  = '<?= $this->security->get_csrf_hash() ?>';

    var ACTIVE_BADGE_STYLE   = 'padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:rgba(22,163,74,.1);color:#16a34a';
    var INACTIVE_BADGE_STYLE = 'padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:rgba(220,38,38,.1);color:#dc2626';
    var DEACT_BTN_STYLE      = 'background:rgba(220,38,38,.08);color:#dc2626;border:none;cursor:pointer';
    var REACT_BTN_STYLE      = 'background:rgba(22,163,74,.08);color:#16a34a;border:none;cursor:pointer';

    document.addEventListener('click', function(e) {
        var btn = e.target.closest && e.target.closest('.staff-status-toggle');
        if (!btn) return;
        e.preventDefault();

        var userId       = btn.getAttribute('data-user-id');
        var staffName    = btn.getAttribute('data-staff-name') || userId;
        var targetStatus = btn.getAttribute('data-target-status');

        var promptMsg = (targetStatus === 'Inactive')
            ? 'Deactivate ' + staffName + '?\n\nThey will be excluded from active staff lists, payroll batches, and assignment dropdowns.\n\n(Reason — optional, can be left empty)'
            : 'Reactivate ' + staffName + '?\n\nThey will be added back to active staff lists.';

        var reason = window.prompt(promptMsg, '');
        if (reason === null) return;

        btn.disabled = true;
        var originalHtml = btn.innerHTML;
        btn.innerHTML   = '<i class="fa fa-spinner fa-spin"></i>';

        var body = new URLSearchParams();
        body.append('status', targetStatus);
        body.append('reason', reason);
        body.append(CSRF_NAME, CSRF_VALUE);

        fetch(STATUS_URL + encodeURIComponent(userId), {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json().catch(function() { return { status: 'error', message: 'Server returned non-JSON.' }; }); })
        .then(function(res) {
            if (!res || res.status !== 'success') {
                alert((res && res.message) ? res.message : 'Status change failed.');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                return;
            }
            // Repaint the row in place — no reload
            var row   = btn.closest('tr');
            var badge = row && row.querySelector('.staff-status-badge');
            if (badge) {
                badge.textContent = targetStatus;
                badge.setAttribute('style', targetStatus === 'Active' ? ACTIVE_BADGE_STYLE : INACTIVE_BADGE_STYLE);
                badge.setAttribute('data-staff-status', targetStatus);
            }
            if (targetStatus === 'Active') {
                btn.setAttribute('data-target-status', 'Inactive');
                btn.setAttribute('title', 'Deactivate Staff');
                btn.setAttribute('style', DEACT_BTN_STYLE);
                btn.innerHTML = '<i class="fa fa-ban"></i>';
            } else {
                btn.setAttribute('data-target-status', 'Active');
                btn.setAttribute('title', 'Reactivate Staff');
                btn.setAttribute('style', REACT_BTN_STYLE);
                btn.innerHTML = '<i class="fa fa-check-circle"></i>';
            }
            btn.disabled = false;
        })
        .catch(function(err) {
            alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    });
})();

/* ── Migrate Staff Roles (one-shot) ── */
function migrateStaffRoles() {
    if (!confirm('This will auto-assign role IDs to all staff based on their Position field.\n\nStaff who already have roles will be skipped.\n\nProceed?')) return;
    var btn = document.getElementById('migrateRolesBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Migrating...'; }
    $.post('<?= base_url("staff/migrate_staff_roles") ?>', {
        '<?= $this->security->get_csrf_token_name() ?>': '<?= $this->security->get_csrf_hash() ?>'
    }, function(res) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-magic"></i> Migrate Roles'; }
        var r = typeof res === 'string' ? JSON.parse(res) : res;
        alert(r.message || 'Migration complete.');
        if (r.migrated > 0) location.reload();
    }).fail(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-magic"></i> Migrate Roles'; }
        alert('Migration failed. Check console for details.');
    });
}

/* ================================================================
   all_staff.php  —  JS
================================================================ */
document.addEventListener('DOMContentLoaded', function () {

    var tbody        = document.getElementById('staffTbody');
    var allRows      = Array.from(tbody ? tbody.querySelectorAll('.nsa-staff-row') : []);
    var searchInput  = document.getElementById('staffSearch');
    var clearBtn     = document.getElementById('clearSearch');
    var filterDept   = document.getElementById('filterDept');
    var filterPos    = document.getElementById('filterPosition');
    var noResults    = document.getElementById('noResultsMsg');
    var rowCountText = document.getElementById('rowCountText');

    /* ── Initial count ── */
    updateCounts(allRows.length);

    /* ── Filter engine ── */
    function applyFilters() {
        var query   = (searchInput ? searchInput.value.toLowerCase().trim() : '');
        var dept    = (filterDept ? filterDept.value.toLowerCase() : '');
        var pos     = (filterPos ? filterPos.value.toLowerCase() : '');
        var visible = 0;

        allRows.forEach(function (row, idx) {
            var d = row.dataset;
            var matchSearch = !query ||
                d.name.includes(query)     ||
                d.id.includes(query)       ||
                d.position.includes(query) ||
                d.dept.includes(query)     ||
                d.phone.includes(query)    ||
                d.email.includes(query);

            var matchDept = !dept || d.dept.includes(dept);
            var matchPos  = !pos  || d.position.includes(pos);

            var show = matchSearch && matchDept && matchPos;
            row.style.display = show ? '' : 'none';

            /* Re-number S.No. for visible rows */
            if (show) {
                visible++;
                var snoCell = row.querySelector('.nsa-td-sno');
                if (snoCell) snoCell.textContent = visible;
            }
        });

        /* Show/hide no-results message */
        if (noResults) noResults.style.display = (visible === 0 && allRows.length > 0) ? 'flex' : 'none';

        /* Show/hide clear button */
        if (clearBtn) clearBtn.style.display = query ? 'flex' : 'none';

        updateCounts(visible);
    }

    function updateCounts(n) {
        if (rowCountText) rowCountText.textContent = 'Showing ' + n + ' of ' + allRows.length + ' staff';
    }

    /* ── Event listeners ── */
    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (filterDept)  filterDept.addEventListener('change', applyFilters);
    if (filterPos)   filterPos.addEventListener('change', applyFilters);

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            applyFilters();
        });
    }

    document.getElementById('resetFilters') && document.getElementById('resetFilters').addEventListener('click', resetAllFilters);

    /* ── Column sort ── */
    document.querySelectorAll('.nsa-sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col      = parseInt(th.dataset.col);
            var isAsc    = th.classList.contains('sort-asc');
            var dir      = isAsc ? -1 : 1;

            document.querySelectorAll('.nsa-sortable').forEach(function(t) {
                t.classList.remove('sort-asc','sort-desc');
            });
            th.classList.add(dir === 1 ? 'sort-asc' : 'sort-desc');

            var sorted = allRows.slice().sort(function (a, b) {
                var aText = a.cells[col] ? a.cells[col].textContent.trim().toLowerCase() : '';
                var bText = b.cells[col] ? b.cells[col].textContent.trim().toLowerCase() : '';
                return aText < bText ? -dir : aText > bText ? dir : 0;
            });

            sorted.forEach(function (row) { tbody.appendChild(row); });
            applyFilters();
        });
    });

    /* ── Select all ── */
    var selectAll = document.getElementById('selectAllStaff');
    var selActions = document.getElementById('selectionActions');
    var selCount   = document.getElementById('selectedCount');

    function updateSelectionUI() {
        var checked = document.querySelectorAll('.row-checkbox-staff:checked').length;
        if (selActions) selActions.style.display = checked > 0 ? 'flex' : 'none';
        if (selCount)   selCount.textContent = checked + ' selected';
        if (selectAll) {
            var visible = allRows.filter(function(r) { return r.style.display !== 'none'; });
            var visChecked = visible.filter(function(r) { return r.querySelector('.row-checkbox-staff').checked; });
            selectAll.indeterminate = visChecked.length > 0 && visChecked.length < visible.length;
            selectAll.checked = visible.length > 0 && visChecked.length === visible.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('click', function (e) {
            e.stopPropagation();
            var visibleRows = allRows.filter(function(r) { return r.style.display !== 'none'; });
            visibleRows.forEach(function (row) {
                var cb = row.querySelector('.row-checkbox-staff');
                if (cb) cb.checked = selectAll.checked;
            });
            updateSelectionUI();
        });
    }

    document.querySelectorAll('.row-checkbox-staff').forEach(function (cb) {
        cb.addEventListener('change', function (e) {
            e.stopPropagation();
            updateSelectionUI();
        });
    });

    /* ── Row click to select ── */
    allRows.forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.tagName === 'A' || e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' ||
                e.target.closest('a') || e.target.closest('.nsa-action-group')) return;
            var cb = row.querySelector('.row-checkbox-staff');
            if (cb) {
                cb.checked = !cb.checked;
                updateSelectionUI();
            }
        });
    });

    /* initial count */
    updateCounts(allRows.length);
});

function clearSelection() {
    document.querySelectorAll('.row-checkbox-staff').forEach(function(cb) { cb.checked = false; });
    var sa = document.getElementById('selectAllStaff');
    if (sa) { sa.checked = false; sa.indeterminate = false; }
    var sel = document.getElementById('selectionActions');
    if (sel) sel.style.display = 'none';
}

function resetAllFilters() {
    var s = document.getElementById('staffSearch');
    var d = document.getElementById('filterDept');
    var p = document.getElementById('filterPosition');
    if (s) s.value = '';
    if (d) d.value = '';
    if (p) p.value = '';
    document.querySelectorAll('.nsa-staff-row').forEach(function(r){ r.style.display=''; });
    var noR = document.getElementById('noResultsMsg');
    if (noR) noR.style.display = 'none';
    var cb = document.getElementById('clearSearch');
    if (cb) cb.style.display = 'none';
    /* Recount */
    var all = document.querySelectorAll('.nsa-staff-row').length;
    var rc = document.getElementById('rowCountText');
    if (rc) rc.textContent = 'Showing ' + all + ' of ' + all + ' staff';
    /* Re-number */
    var i = 1;
    document.querySelectorAll('.nsa-staff-row').forEach(function(r) {
        var sno = r.querySelector('.nsa-td-sno');
        if (sno) sno.textContent = i++;
    });
}
</script>


<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Lora:wght@500;600;700&display=swap');

:root {
    --nsa-navy:   #0c1e38;
    --nsa-teal:   #0f766e;
    --nsa-sky:    #e6f4f1;
    --nsa-green:  #0d7a5f;
    --nsa-dark:   #15803d;
    --nsa-red:    #dc2626;
    --nsa-amber:  #d97706;
    --nsa-blue:   #2563eb;
    --nsa-pink:   #db2777;
    --nsa-text:   #1a2535;
    --nsa-muted:  #64748b;
    --nsa-border: #e2e8f0;
    --nsa-white:  #ffffff;
    --nsa-bg:     #f0f7f5;
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
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 24px;
}
.nsa-topbar-left {}
.nsa-topbar-right { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

.nsa-page-title {
    font-family: 'Lora', serif;
    font-size: 25px; font-weight: 700;
    color: var(--nsa-navy);
    display: flex; align-items: center; gap: 10px;
    margin: 0 0 6px;
}
.nsa-page-title i { color: var(--nsa-teal); }

.nsa-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--nsa-muted);
    list-style: none; margin: 0; padding: 0;
}
.nsa-breadcrumb a { color: var(--nsa-teal); text-decoration: none; font-weight: 500; }
.nsa-breadcrumb a:hover { text-decoration: underline; }
.nsa-breadcrumb li::before { content: '/'; margin-right: 6px; color: #cbd5e1; }
.nsa-breadcrumb li:first-child::before { display: none; }

/* ── Stat strip ── */
.nsa-stat-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
@media (max-width: 768px) { .nsa-stat-strip { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px) { .nsa-stat-strip { grid-template-columns: 1fr 1fr; } }

.nsa-stat-card {
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: var(--nsa-shadow);
    padding: 16px 18px;
    display: flex; align-items: center; gap: 14px;
    border: 1px solid var(--nsa-border);
    transition: transform .15s, box-shadow .15s;
}
.nsa-stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(12,30,56,.1); }
.nsa-stat-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.nsa-stat-num {
    font-family: 'Lora', serif;
    font-size: 24px; font-weight: 700;
    color: var(--nsa-navy); line-height: 1;
}
.nsa-stat-lbl {
    font-size: 11.5px; color: var(--nsa-muted);
    font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
    margin-top: 3px;
}

/* ── Main card ── */
.nsa-card {
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: var(--nsa-shadow);
    border: 1px solid var(--nsa-border);
    overflow: hidden;
}

/* ── Toolbar ── */
.nsa-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--nsa-border);
    background: var(--nsa-bg);
}
.nsa-toolbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.nsa-search-wrap {
    position: relative;
    display: flex; align-items: center;
    min-width: 280px;
}
.nsa-search-icon {
    position: absolute; left: 12px;
    color: var(--nsa-muted); font-size: 13px;
    pointer-events: none;
}
.nsa-search-input {
    width: 100%; height: 38px;
    padding: 0 36px 0 34px;
    border: 1.5px solid var(--nsa-border);
    border-radius: 8px;
    font-size: 13.5px; color: var(--nsa-text);
    background: var(--nsa-white);
    font-family: 'Instrument Sans', sans-serif;
    outline: none; transition: border-color .14s, box-shadow .14s;
}
.nsa-search-input:focus {
    border-color: var(--nsa-teal);
    box-shadow: 0 0 0 3px rgba(15,118,110,.1);
}
.nsa-search-clear {
    position: absolute; right: 8px;
    background: none; border: none; cursor: pointer;
    color: var(--nsa-muted); font-size: 12px;
    display: flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; border-radius: 50%;
    transition: background .12s, color .12s;
}
.nsa-search-clear:hover { background: #f1f5f9; color: var(--nsa-red); }

.nsa-filter-select {
    height: 38px; padding: 0 28px 0 10px;
    border: 1.5px solid var(--nsa-border);
    border-radius: 8px; font-size: 13px;
    color: var(--nsa-text); background: var(--nsa-white);
    font-family: 'Instrument Sans', sans-serif;
    cursor: pointer; outline: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 9px center;
    transition: border-color .14s;
}
.nsa-filter-select:focus { border-color: var(--nsa-teal); }

/* ── Table ── */
.nsa-table-wrap { overflow-x: auto; }

.nsa-table {
    width: 100%; border-collapse: collapse;
    font-size: 13.5px;
}

.nsa-table thead tr {
    background: var(--nsa-navy);
}
.nsa-table thead th {
    padding: 12px 14px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px;
    color: rgba(255,255,255,.85);
    white-space: nowrap; border: none;
    position: relative;
}
.nsa-table thead th:first-child { border-radius: 0; }

.nsa-sortable { cursor: pointer; user-select: none; }
.nsa-sortable:hover { color: #fff; background: rgba(255,255,255,.08); }
.nsa-sortable.sort-asc  i::before { content: '\f0de'; }
.nsa-sortable.sort-desc i::before { content: '\f0dd'; }
.nsa-sortable i { margin-left: 4px; opacity: .5; font-size: 10px; }
.nsa-sortable.sort-asc i, .nsa-sortable.sort-desc i { opacity: 1; }

.nsa-table tbody tr {
    border-bottom: 1px solid var(--nsa-border);
    transition: background .1s;
    cursor: pointer;
}
.nsa-table tbody tr:last-child { border-bottom: none; }
.nsa-table tbody tr:hover { background: var(--nsa-sky); }
.nsa-table tbody tr.row-selected { background: #d1fae5; }

.nsa-table td {
    padding: 11px 14px;
    color: var(--nsa-text);
    vertical-align: middle;
    border: none;
}

/* Column widths */
.nsa-th-check, .nsa-td-check { width: 40px; text-align: center; }
.nsa-th-sno,   .nsa-td-sno   { width: 50px; text-align: center; color: var(--nsa-muted); font-weight: 600; }
.nsa-th-avatar, .nsa-td-avatar { width: 64px; text-align: center; }
.nsa-th-action, .nsa-td-action { width: 90px; text-align: center; }
.nsa-td-email { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ── Avatar ── */
.nsa-avatar-wrap { position: relative; display: inline-block; }
.nsa-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--nsa-border);
    box-shadow: 0 1px 4px rgba(0,0,0,.1);
    transition: border-color .15s, transform .15s;
}
.nsa-table tbody tr:hover .nsa-avatar { border-color: var(--nsa-teal); transform: scale(1.08); }
.nsa-avatar-dot {
    position: absolute; bottom: 1px; right: 1px;
    width: 10px; height: 10px; border-radius: 50%;
    border: 2px solid #fff;
}
.nsa-badge-blue { background: var(--nsa-blue); }
.nsa-badge-pink { background: var(--nsa-pink); }
.nsa-badge-gray { background: var(--nsa-muted); }

/* ── ID Badge ── */
.nsa-id-badge {
    display: inline-block;
    padding: 3px 9px;
    background: var(--nsa-sky);
    color: var(--nsa-teal);
    border-radius: 20px;
    font-size: 12px; font-weight: 700;
    letter-spacing: .3px;
    border: 1px solid rgba(15,118,110,.2);
}

/* ── Name cell ── */
.nsa-name-cell { display: flex; flex-direction: column; gap: 2px; }
.nsa-name-cell strong { font-size: 13.5px; color: var(--nsa-navy); }
.nsa-name-sub { font-size: 11.5px; color: var(--nsa-muted); }

/* ── Phone link ── */
.nsa-phone-link {
    color: var(--nsa-teal); text-decoration: none; font-size: 13px;
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.nsa-phone-link:hover { text-decoration: underline; }

/* ── Action buttons ── */
.nsa-action-group { display: flex; gap: 6px; justify-content: center; }
.nsa-action-btn {
    width: 30px; height: 30px; border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; text-decoration: none;
    transition: transform .12s, opacity .12s;
    border: none; cursor: pointer;
}
.nsa-action-btn:hover { transform: translateY(-1px); opacity: .85; }
.nsa-action-view { background: #e0f2fe; color: #0369a1; }
.nsa-action-edit { background: #fef3c7; color: #d97706; }

/* ── Checkbox ── */
.nsa-checkbox {
    width: 15px; height: 15px;
    accent-color: var(--nsa-teal);
    cursor: pointer;
}

/* ── Table footer ── */
.nsa-table-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 20px;
    border-top: 1px solid var(--nsa-border);
    background: var(--nsa-bg);
    font-size: 13px; color: var(--nsa-muted);
}
.nsa-selection-actions { display: flex; align-items: center; gap: 10px; }
.nsa-selected-count {
    background: var(--nsa-sky); color: var(--nsa-teal);
    padding: 3px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
}

/* ── Empty row ── */
.nsa-empty-row {
    text-align: center;
    padding: 60px 20px !important;
    color: var(--nsa-muted);
}
.nsa-empty-row i { font-size: 48px; opacity: .25; display: block; margin-bottom: 12px; }
.nsa-empty-row p { margin: 0 0 16px; font-size: 15px; }

/* ── No results ── */
.nsa-no-results {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 10px;
    padding: 60px 20px; text-align: center;
}
.nsa-no-results i { font-size: 46px; color: var(--nsa-muted); opacity: .3; }
.nsa-no-results h3 { margin: 0; color: var(--nsa-navy); font-family: 'Lora', serif; }
.nsa-no-results p  { margin: 0; color: var(--nsa-muted); font-size: 14px; }

/* ── Buttons ── */
.nsa-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 9px;
    font-size: 13.5px; font-weight: 600;
    cursor: pointer; border: none; text-decoration: none;
    transition: opacity .13s, transform .1s;
    font-family: 'Instrument Sans', sans-serif;
    white-space: nowrap;
}
.nsa-btn:hover { opacity: .86; transform: translateY(-1px); }
.nsa-btn-sm { padding: 6px 14px; font-size: 12.5px; }
.nsa-btn-primary { background: var(--nsa-teal); color: #fff; box-shadow: 0 2px 10px rgba(15,118,110,.28); }
.nsa-btn-amber   { background: #d97706; color: #fff; box-shadow: 0 2px 10px rgba(217,119,6,.22); }
.nsa-btn-ghost   { background: var(--nsa-white); color: var(--nsa-text); border: 1.5px solid var(--nsa-border); }
.nsa-btn-ghost:hover { border-color: var(--nsa-teal); color: var(--nsa-teal); opacity: 1; }

/* ── Role badges ── */
.nsa-role-pill {
    display: inline-block; padding: 2px 8px; border-radius: 12px;
    font-size: 11px; font-weight: 600; margin: 1px 2px;
    line-height: 1.5;
}
.nsa-role-pill.nsa-badge-blue  { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.nsa-role-pill.nsa-badge-amber { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.nsa-role-pill.nsa-badge-green { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.nsa-role-pill.nsa-badge-gray  { background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; }
.nsa-role-pill-primary { font-weight: 700; }
</style>