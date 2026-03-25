<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="ms-wrap">

    <!-- ── TOP BAR ── -->
    <div class="ms-topbar">
        <div>
            <h1 class="ms-page-title"><i class="fa fa-university"></i> Manage Schools</h1>
            <ol class="ms-breadcrumb">
                <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li>Schools</li>
                <li>Manage Schools</li>
            </ol>
        </div>
        <button class="ms-btn ms-btn-primary" onclick="openModal('addModal')">
            <i class="fa fa-plus"></i> Add New School
        </button>
    </div>

    <!-- ── STAT STRIP ── -->
    <?php
    $totalSchools  = count($Schools ?? []);
    $activeCount   = 0;
    $expiredCount  = 0;
    $warningCount  = 0;
    foreach (($Schools ?? []) as $s) {
        $endDate = $s['subscription']['duration']['endDate'] ?? null;
        if ($endDate) {
            $days = ceil((strtotime($endDate) - time()) / 86400);
            if ($days <= 0)  $expiredCount++;
            elseif ($days <= 30) $warningCount++;
            else $activeCount++;
        }
    }
    ?>
    <div class="ms-stat-strip">
        <div class="ms-stat ms-stat-blue">
            <div class="ms-stat-icon"><i class="fa fa-university"></i></div>
            <div>
                <div class="ms-stat-label">Total Schools</div>
                <div class="ms-stat-val"><?= $totalSchools ?></div>
            </div>
        </div>
        <div class="ms-stat ms-stat-green">
            <div class="ms-stat-icon"><i class="fa fa-check-circle"></i></div>
            <div>
                <div class="ms-stat-label">Active</div>
                <div class="ms-stat-val"><?= $activeCount ?></div>
            </div>
        </div>
        <div class="ms-stat ms-stat-amber">
            <div class="ms-stat-icon"><i class="fa fa-exclamation-triangle"></i></div>
            <div>
                <div class="ms-stat-label">Expiring Soon</div>
                <div class="ms-stat-val"><?= $warningCount ?></div>
            </div>
        </div>
        <div class="ms-stat ms-stat-teal">
            <div class="ms-stat-icon"><i class="fa fa-calendar-times-o"></i></div>
            <div>
                <div class="ms-stat-label">Expired</div>
                <div class="ms-stat-val"><?= $expiredCount ?></div>
            </div>
        </div>
    </div>

    <!-- ── SCHOOLS TABLE ── -->
    <div class="ms-card">
        <div class="ms-card-head">
            <div class="ms-card-head-left">
                <i class="fa fa-table"></i>
                <h3>Registered Schools</h3>
                <span class="ms-head-hint">Click a row to select · use Edit / Delete to manage</span>
            </div>
        </div>

        <div class="ms-filter-bar">
            <div class="ms-search-wrap">
                <i class="fa fa-search ms-search-icon"></i>
                <input type="text" class="ms-search" id="msSearch" placeholder="Search by name, principal, email…" autocomplete="off">
            </div>
            <span class="ms-row-count" id="msRowCount"><?= $totalSchools ?> school(s)</span>
        </div>

        <?php if (!empty($Schools)): ?>
        <div class="ms-table-wrap">
            <table class="ms-table" id="msTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ID</th>
                        <th>Logo</th>
                        <th>School Name</th>
                        <th>Principal</th>
                        <th>Board</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Subscription</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="msBody">
                <?php $sno = 1; foreach ($Schools as $school):
                    $endDate  = $school['subscription']['duration']['endDate'] ?? null;
                    $planName = $school['subscription']['planName'] ?? 'N/A';
                    $subStatus = 'N/A'; $subClass = '';
                    if ($endDate) {
                        $days = ceil((strtotime($endDate) - time()) / 86400);
                        if ($days <= 0)       { $subStatus = 'Expired';    $subClass = 'ms-sub-expired';  }
                        elseif ($days <= 30)  { $subStatus = $days.'d left'; $subClass = 'ms-sub-warning'; }
                        else                  { $subStatus = 'Active';     $subClass = 'ms-sub-active';   }
                    }
                ?>
                <tr class="ms-row"
                    data-name="<?= htmlspecialchars(strtolower($school['School Name'] ?? '')) ?>"
                    data-principal="<?= htmlspecialchars(strtolower($school['School Principal'] ?? '')) ?>"
                    data-email="<?= htmlspecialchars(strtolower($school['Email'] ?? '')) ?>">
                    <td><?= $sno ?></td>
                    <td><span class="ms-id-pill"><?= htmlspecialchars($school['School Id']) ?></span></td>
                    <td class="ms-logo-cell">
                        <?php if (isset($school['Logo']) && filter_var($school['Logo'], FILTER_VALIDATE_URL)): ?>
                            <img src="<?= htmlspecialchars($school['Logo']) ?>" alt="Logo">
                        <?php else: ?>
                            <div class="ms-no-logo"><i class="fa fa-building-o"></i></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= htmlspecialchars($school['School Name'] ?? '') ?></strong>
                        <?php if (!empty($school['Address'])): ?>
                        <br><span style="font-size:11px;color:var(--ms-muted);"><?= htmlspecialchars($school['Address']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($school['School Principal'] ?? 'N/A') ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($school['Affiliated To'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($school['Phone Number'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($school['Email'] ?? 'N/A') ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($planName) ?></td>
                    <td><span class="ms-sub-badge <?= $subClass ?>"><?= $subStatus ?></span></td>
                    <td>
                        <div class="ms-action-wrap">
                            <a href="<?= site_url('schools/edit_school/' . $school['School Id']) ?>"
                               class="ms-btn ms-btn-edit ms-btn-sm" title="Edit">
                                <i class="fa fa-pencil"></i>
                            </a>
                            <button type="button" class="ms-btn ms-btn-danger ms-btn-sm"
                                onclick="confirmDelete('<?= htmlspecialchars($school['School Id']) ?>', '<?= htmlspecialchars($school['School Name']) ?>')"
                                title="Delete">
                                <i class="fa fa-trash-o"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php $sno++; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="ms-empty">
            <i class="fa fa-university"></i>
            <p style="font-size:15px;font-weight:600;margin:0 0 6px;">No schools registered yet</p>
            <p style="font-size:13px;margin:0 0 16px;">Click "Add New School" to register your first school.</p>
            <button class="ms-btn ms-btn-primary" onclick="openModal('addModal')">
                <i class="fa fa-plus"></i> Add New School
            </button>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.ms-wrap -->
</div><!-- /.content-wrapper -->


<!-- ══ MODAL: Add New School ══ -->
<div class="ms-overlay" id="addModal">
<div class="ms-modal">
    <div class="ms-modal-head">
        <h4><i class="fa fa-university"></i> Add New School</h4>
        <button class="ms-modal-close" onclick="closeModal('addModal')">&times;</button>
    </div>
    <form id="addSchoolForm" enctype="multipart/form-data">
        <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
               value="<?= $this->security->get_csrf_hash() ?>">

        <div class="ms-modal-body">

            <!-- Section: Basic Info -->
            <div class="ms-form-section">
                <div class="ms-form-section-title"><i class="fa fa-info-circle"></i> Basic Information</div>
                <div class="ms-form-grid-2">
                    <div class="ms-field">
                        <label class="ms-label">School ID</label>
                        <input type="text" name="School Id" class="ms-input" readonly
                            value="<?= isset($currentSchoolCount) ? 'SCH'.str_pad($currentSchoolCount, 5, '0', STR_PAD_LEFT) : '' ?>">
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">School Name <span class="ms-req">*</span></label>
                        <input type="text" name="School Name" class="ms-input" required placeholder="Enter school name">
                    </div>
                </div>
                <div class="ms-form-grid-2">
                    <div class="ms-field">
                        <label class="ms-label">Principal <span class="ms-req">*</span></label>
                        <input type="text" name="School Principal" class="ms-input" required placeholder="Principal name">
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">School Logo</label>
                        <input type="file" name="school_logo" class="ms-input" accept="image/*">
                    </div>
                </div>
                <div class="ms-field">
                    <label class="ms-label">Address <span class="ms-req">*</span></label>
                    <textarea name="Address" class="ms-textarea" required placeholder="Full school address"></textarea>
                </div>
            </div>

            <!-- Section: Contact -->
            <div class="ms-form-section">
                <div class="ms-form-section-title"><i class="fa fa-phone"></i> Contact Details</div>
                <div class="ms-form-grid-3">
                    <div class="ms-field">
                        <label class="ms-label">Phone <span class="ms-req">*</span></label>
                        <input type="text" name="Phone Number" class="ms-input" required placeholder="STD + Number">
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">Mobile <span class="ms-req">*</span></label>
                        <input type="text" name="Mobile Number" class="ms-input" required placeholder="10-digit mobile">
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">Email <span class="ms-req">*</span></label>
                        <input type="email" name="Email" class="ms-input" required placeholder="school@email.com">
                    </div>
                </div>
                <div class="ms-field">
                    <label class="ms-label">Website</label>
                    <input type="url" name="Website" class="ms-input" placeholder="https://www.school.edu.in">
                </div>
            </div>

            <!-- Section: Affiliation -->
            <div class="ms-form-section">
                <div class="ms-form-section-title"><i class="fa fa-certificate"></i> Affiliation</div>
                <div class="ms-form-grid-2">
                    <div class="ms-field">
                        <label class="ms-label">Affiliated To <span class="ms-req">*</span></label>
                        <select name="Affiliated To" class="ms-select" required>
                            <option value="" disabled selected>Select board</option>
                            <optgroup label="National Boards">
                                <option value="Central Board of Secondary Education">CBSE</option>
                                <option value="Council for the Indian School Certificate Examinations">CISCE / ICSE</option>
                                <option value="National Institute of Open Schooling">NIOS</option>
                            </optgroup>
                            <optgroup label="State Boards">
                                <option value="Andhra Pradesh Board of Secondary Education">Andhra Pradesh</option>
                                <option value="Board of Secondary Education, Assam">Assam</option>
                                <option value="Bihar School Examination Board">Bihar</option>
                                <option value="Chhattisgarh Board of Secondary Education">Chhattisgarh</option>
                                <option value="Goa Board of Secondary and Higher Secondary Education">Goa</option>
                                <option value="Gujarat Secondary and Higher Secondary Education Board">Gujarat</option>
                                <option value="Board of School Education, Haryana">Haryana</option>
                                <option value="Himachal Pradesh Board of School Education">Himachal Pradesh</option>
                                <option value="Jammu and Kashmir State Board of School Education">J&amp;K</option>
                                <option value="Jharkhand Academic Council">Jharkhand</option>
                                <option value="Karnataka Secondary Education Examination Board">Karnataka</option>
                                <option value="Kerala Board of Public Examinations">Kerala</option>
                                <option value="Madhya Pradesh Board of Secondary Education">Madhya Pradesh</option>
                                <option value="Maharashtra State Board of Secondary and Higher Secondary Education">Maharashtra</option>
                                <option value="Board of Secondary Education, Manipur">Manipur</option>
                                <option value="Meghalaya Board of School Education">Meghalaya</option>
                                <option value="Mizoram Board of School Education">Mizoram</option>
                                <option value="Nagaland Board of School Education">Nagaland</option>
                                <option value="Board of Secondary Education, Odisha">Odisha</option>
                                <option value="Punjab School Education Board">Punjab</option>
                                <option value="Board of Secondary Education, Rajasthan">Rajasthan</option>
                                <option value="Board of Secondary Education, Sikkim">Sikkim</option>
                                <option value="Tamil Nadu State Board of School Examination">Tamil Nadu</option>
                                <option value="Telangana State Board of Intermediate Education">Telangana</option>
                                <option value="Tripura Board of Secondary Education">Tripura</option>
                                <option value="Uttar Pradesh Madhyamik Shiksha Parishad">Uttar Pradesh</option>
                                <option value="Uttarakhand Board of School Education">Uttarakhand</option>
                                <option value="West Bengal Board of Secondary Education">West Bengal</option>
                                <option value="Other">Other</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">Affiliation Number <span class="ms-req">*</span></label>
                        <input type="text" name="Affiliation Number" class="ms-input" required placeholder="e.g. 1000123">
                    </div>
                </div>
            </div>

            <!-- Section: Subscription -->
            <div class="ms-form-section">
                <div class="ms-form-section-title"><i class="fa fa-credit-card"></i> Subscription &amp; Payment</div>
                <div class="ms-form-grid-2">
                    <div class="ms-field">
                        <label class="ms-label">Plan <span class="ms-req">*</span></label>
                        <select name="subscription_plan" class="ms-select" required>
                            <option value="Premium Plan">Premium Plan</option>
                            <option value="Basic Plan">Basic Plan</option>
                        </select>
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">Duration (Months) <span class="ms-req">*</span></label>
                        <input type="number" name="subscription_duration" class="ms-input" required placeholder="e.g. 12" min="1">
                    </div>
                </div>
                <div class="ms-form-grid-3">
                    <div class="ms-field">
                        <label class="ms-label">Payment Amount <span class="ms-req">*</span></label>
                        <input type="number" name="last_payment_amount" class="ms-input" required placeholder="₹ 0">
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">Payment Date <span class="ms-req">*</span></label>
                        <input type="date" name="last_payment_date" class="ms-input" required>
                    </div>
                    <div class="ms-field">
                        <label class="ms-label">Payment Method <span class="ms-req">*</span></label>
                        <select name="payment_method" class="ms-select" required>
                            <option value="Cash">Cash</option>
                            <option value="Net Banking">Net Banking</option>
                            <option value="Debit Card">Debit Card</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="UPI">UPI</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Section: Features -->
            <div>
                <div class="ms-form-section-title"><i class="fa fa-th-large"></i> Module Features</div>
                <label class="ms-select-all-row">
                    <input type="checkbox" id="msSelectAll"> Select All Modules
                </label>
                <div class="ms-features-grid">
                    <?php
                    $featureList = [
                        'School Management', 'Class Management', 'Student Management',
                        'Staff Management', 'Account Management', 'Fees Management',
                        'Exam Management', 'Admin Management'
                    ];
                    foreach ($featureList as $i => $feat):
                    ?>
                    <label class="ms-feature-item">
                        <input type="checkbox" name="features[]" value="<?= htmlspecialchars($feat) ?>" class="ms-feature-cb">
                        <?= htmlspecialchars($feat) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

        </div><!-- /.ms-modal-body -->

        <div class="ms-modal-footer">
            <button type="button" class="ms-btn ms-btn-ghost" onclick="closeModal('addModal')">Cancel</button>
            <button type="submit" class="ms-btn ms-btn-primary" id="addSubmitBtn">
                <i class="fa fa-plus"></i> Add School
            </button>
        </div>
    </form>
</div>
</div>

<!-- ══ MODAL: Delete Confirm ══ -->
<div class="ms-overlay" id="deleteModal">
<div class="ms-modal" style="max-width:440px;">
    <div class="ms-modal-head" style="background:#dc2626;">
        <h4><i class="fa fa-exclamation-triangle"></i> Confirm Delete</h4>
        <button class="ms-modal-close" onclick="closeModal('deleteModal')">&times;</button>
    </div>
    <div class="ms-modal-body">
        <p style="font-size:14px;color:#374151;margin:0 0 8px;">
            Are you sure you want to permanently delete:
        </p>
        <p id="deleteSchoolName" style="font-size:16px;font-weight:700;color:#1a2332;margin:0 0 16px;"></p>
        <p style="font-size:13px;color:#6b7280;margin:0;">
            This will remove all school data, student records, and uploaded files from storage. <strong>This action cannot be undone.</strong>
        </p>
    </div>
    <div class="ms-modal-footer">
        <button type="button" class="ms-btn ms-btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
        <a id="deleteConfirmBtn" href="#" class="ms-btn ms-btn-danger">
            <i class="fa fa-trash-o"></i> Delete Permanently
        </a>
    </div>
</div>
</div>

<!-- Toast container -->
<div id="msToastWrap" class="ms-toast-wrap"></div>


<script>
/* ── Utilities ── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.ms-overlay').forEach(function(ov) {
    ov.addEventListener('click', function(e) { if (e.target === ov) ov.classList.remove('open'); });
});

function showToast(msg, type) {
    var wrap = document.getElementById('msToastWrap');
    var el = document.createElement('div');
    el.className = 'ms-toast ms-toast-' + (type || 'success');
    el.innerHTML = '<i class="fa fa-' + (type === 'error' ? 'times-circle' : 'check-circle') + '"></i> ' + msg;
    wrap.appendChild(el);
    setTimeout(function() {
        el.classList.add('ms-toast-hide');
        setTimeout(function() { el.remove(); }, 350);
    }, 3500);
}

/* ── Table search ── */
(function() {
    var search = document.getElementById('msSearch');
    var rows   = document.querySelectorAll('#msBody .ms-row');
    var rcEl   = document.getElementById('msRowCount');
    if (!search) return;
    search.addEventListener('input', function() {
        var q = search.value.toLowerCase().trim();
        var n = 0;
        rows.forEach(function(row) {
            var hay = (row.dataset.name + ' ' + row.dataset.principal + ' ' + row.dataset.email);
            var show = !q || hay.includes(q);
            row.style.display = show ? '' : 'none';
            if (show) n++;
        });
        if (rcEl) rcEl.textContent = n + ' school(s)';
    });
})();

/* ── Select-All features ── */
document.getElementById('msSelectAll').addEventListener('change', function() {
    document.querySelectorAll('.ms-feature-cb').forEach(function(cb) { cb.checked = this.checked; }, this);
});
document.querySelectorAll('.ms-feature-cb').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var all   = document.querySelectorAll('.ms-feature-cb');
        var checked = document.querySelectorAll('.ms-feature-cb:checked');
        document.getElementById('msSelectAll').checked = all.length === checked.length;
    });
});

/* ── Delete confirm ── */
function confirmDelete(schoolId, schoolName) {
    document.getElementById('deleteSchoolName').textContent = schoolName + ' (' + schoolId + ')';
    document.getElementById('deleteConfirmBtn').href = '<?= site_url("schools/delete_school/") ?>' + schoolId;
    openModal('deleteModal');
}

/* ── Add School form ── */
document.getElementById('addSchoolForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var btn = document.getElementById('addSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    var formData = new FormData(this);
    /* Re-inject fresh CSRF since modal may have been open a while */
    formData.set('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>');

    fetch('<?= site_url("schools/manage_school") ?>', {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-Token':     '<?= $this->security->get_csrf_hash() ?>',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(function(resp) {
        if (resp.trim() === '1') {
            showToast('School added successfully!', 'success');
            closeModal('addModal');
            setTimeout(function() { location.reload(); }, 1200);
        } else {
            showToast('Error: ' + (resp || 'Failed to add school.'), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-plus"></i> Add School';
        }
    })
    .catch(function(e) {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-plus"></i> Add School';
        console.error('addSchool error:', e);
    });
});
</script>

<style>
/* ── Manage Schools — matches existing ERP theme (navy/teal/amber) ── */
:root {
    --ms-navy:    #1a2332;
    --ms-teal:    #0d9488;
    --ms-teal-lt: #ccfbf1;
    --ms-amber:   #d97706;
    --ms-red:     #dc2626;
    --ms-green:   #16a34a;
    --ms-muted:   #6b7280;
    --ms-border:  #e5e7eb;
    --ms-bg:      #f4f6f9;
    --ms-white:   #ffffff;
    --ms-shadow:  0 2px 8px rgba(0,0,0,.08);
    --ms-radius:  10px;
}

.ms-wrap { padding: 20px 24px; background: var(--ms-bg); min-height: 100vh; }

/* ── Top bar ── */
.ms-topbar {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 22px; flex-wrap: wrap; gap: 12px;
}
.ms-page-title {
    font-size: 22px; font-weight: 700; color: var(--ms-navy);
    margin: 0 0 4px; display: flex; align-items: center; gap: 8px;
}
.ms-page-title i { color: var(--ms-teal); }
.ms-breadcrumb {
    list-style: none; padding: 0; margin: 0;
    display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ms-muted);
}
.ms-breadcrumb li:not(:last-child)::after { content: '/'; margin-left: 6px; }
.ms-breadcrumb a { color: var(--ms-teal); text-decoration: none; }
.ms-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 7px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: none; text-decoration: none; transition: all .18s;
}
.ms-btn-primary { background: var(--ms-teal); color: #fff; }
.ms-btn-primary:hover { background: #0f766e; }
.ms-btn-danger  { background: var(--ms-red);  color: #fff; }
.ms-btn-danger:hover  { background: #b91c1c; }
.ms-btn-edit    { background: var(--ms-navy); color: #fff; }
.ms-btn-edit:hover    { background: #243044; }
.ms-btn-ghost   { background: #fff; color: var(--ms-navy); border: 1.5px solid var(--ms-border); }
.ms-btn-ghost:hover   { border-color: var(--ms-teal); color: var(--ms-teal); }
.ms-btn-sm { padding: 6px 12px; font-size: 12px; }

/* ── Stat strip ── */
.ms-stat-strip {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 14px; margin-bottom: 22px;
}
.ms-stat {
    background: var(--ms-white); border-radius: var(--ms-radius);
    padding: 16px 18px; display: flex; align-items: center; gap: 14px;
    box-shadow: var(--ms-shadow); border-left: 4px solid transparent;
}
.ms-stat-blue   { border-left-color: #3b82f6; }
.ms-stat-green  { border-left-color: var(--ms-green); }
.ms-stat-amber  { border-left-color: var(--ms-amber); }
.ms-stat-teal   { border-left-color: var(--ms-teal); }
.ms-stat-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.ms-stat-blue  .ms-stat-icon { background: #eff6ff; color: #3b82f6; }
.ms-stat-green .ms-stat-icon { background: #f0fdf4; color: var(--ms-green); }
.ms-stat-amber .ms-stat-icon { background: #fffbeb; color: var(--ms-amber); }
.ms-stat-teal  .ms-stat-icon { background: #f0fdfa; color: var(--ms-teal); }
.ms-stat-label { font-size: 11px; color: var(--ms-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.ms-stat-val   { font-size: 22px; font-weight: 800; color: var(--ms-navy); line-height: 1.2; }

/* ── Card ── */
.ms-card {
    background: var(--ms-white); border-radius: var(--ms-radius);
    box-shadow: var(--ms-shadow); margin-bottom: 20px; overflow: hidden;
}
.ms-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1.5px solid var(--ms-border);
    flex-wrap: wrap; gap: 10px;
}
.ms-card-head-left { display: flex; align-items: center; gap: 10px; }
.ms-card-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--ms-navy); }
.ms-card-head i  { color: var(--ms-teal); font-size: 16px; }
.ms-head-hint { font-size: 12px; color: var(--ms-muted); }

/* ── Filter bar ── */
.ms-filter-bar {
    display: flex; align-items: center; gap: 12px; padding: 14px 20px;
    border-bottom: 1px solid var(--ms-border); flex-wrap: wrap;
}
.ms-search-wrap { position: relative; flex: 1; min-width: 200px; }
.ms-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--ms-muted); font-size: 13px; }
.ms-search {
    width: 100%; padding: 8px 12px 8px 32px;
    border: 1.5px solid var(--ms-border); border-radius: 7px;
    font-size: 13px; outline: none; transition: border .18s;
}
.ms-search:focus { border-color: var(--ms-teal); }
.ms-row-count { font-size: 12px; color: var(--ms-muted); white-space: nowrap; }

/* ── Table ── */
.ms-table-wrap { overflow-x: auto; }
.ms-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ms-table thead tr {
    background: var(--ms-navy); color: #fff; text-transform: uppercase;
    font-size: 11px; letter-spacing: .5px;
}
.ms-table thead th { padding: 12px 14px; font-weight: 600; white-space: nowrap; }
.ms-table tbody tr { border-bottom: 1px solid var(--ms-border); transition: background .12s; }
.ms-table tbody tr:hover { background: #f8fafc; }
.ms-table td { padding: 12px 14px; vertical-align: middle; color: var(--ms-navy); }

.ms-logo-cell img {
    width: 40px; height: 40px; border-radius: 8px;
    object-fit: cover; border: 1.5px solid var(--ms-border);
}
.ms-no-logo {
    width: 40px; height: 40px; border-radius: 8px;
    background: #f3f4f6; display: flex; align-items: center; justify-content: center;
    font-size: 9px; color: var(--ms-muted); text-align: center; line-height: 1.2;
    border: 1.5px solid var(--ms-border);
}
.ms-id-pill {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    background: var(--ms-teal-lt); color: var(--ms-teal); font-size: 11px; font-weight: 700;
}
.ms-sub-badge {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
}
.ms-sub-active   { background: #dcfce7; color: var(--ms-green); }
.ms-sub-expired  { background: #fee2e2; color: var(--ms-red); }
.ms-sub-warning  { background: #fffbeb; color: var(--ms-amber); }
.ms-action-wrap  { display: flex; gap: 6px; }
.ms-empty { text-align: center; padding: 48px 20px; color: var(--ms-muted); }
.ms-empty i { font-size: 40px; margin-bottom: 12px; opacity: .4; display: block; }

/* ── Modal overlay ── */
.ms-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 1050;
    align-items: flex-start; justify-content: center; padding: 30px 16px;
    overflow-y: auto;
}
.ms-overlay.open { display: flex; }
.ms-modal {
    background: var(--ms-white); border-radius: 12px; width: 100%; max-width: 680px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2); animation: msSlideIn .2s ease;
}
@keyframes msSlideIn { from { transform: translateY(-24px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.ms-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1.5px solid var(--ms-border);
    background: var(--ms-navy); border-radius: 12px 12px 0 0;
}
.ms-modal-head h4 { margin: 0; font-size: 15px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px; }
.ms-modal-close {
    background: none; border: none; color: rgba(255,255,255,.7);
    font-size: 20px; cursor: pointer; padding: 0 4px; line-height: 1;
}
.ms-modal-close:hover { color: #fff; }
.ms-modal-body { padding: 22px; }

/* ── Form grid ── */
.ms-form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.ms-form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 560px) {
    .ms-form-grid-2, .ms-form-grid-3 { grid-template-columns: 1fr; }
}
.ms-form-section {
    margin-bottom: 20px; padding-bottom: 16px;
    border-bottom: 1px solid var(--ms-border);
}
.ms-form-section-title {
    font-size: 11px; font-weight: 700; color: var(--ms-teal);
    text-transform: uppercase; letter-spacing: .7px; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.ms-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.ms-label { font-size: 12px; font-weight: 600; color: var(--ms-navy); }
.ms-req { color: var(--ms-red); }
.ms-input, .ms-select, .ms-textarea {
    padding: 9px 12px; border: 1.5px solid var(--ms-border);
    border-radius: 7px; font-size: 13px; outline: none;
    transition: border .18s; background: #fff; color: var(--ms-navy);
    width: 100%; box-sizing: border-box;
}
.ms-input:focus, .ms-select:focus, .ms-textarea:focus { border-color: var(--ms-teal); }
.ms-input[readonly] { background: #f9fafb; color: var(--ms-muted); }
.ms-textarea { min-height: 72px; resize: vertical; }

/* ── Checkbox features grid ── */
.ms-features-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 8px; padding: 12px;
    border: 1.5px solid var(--ms-border); border-radius: 7px; background: #fafafa;
}
.ms-feature-item {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 10px; border-radius: 6px; background: #fff;
    border: 1px solid var(--ms-border); cursor: pointer; transition: all .15s;
    font-size: 12px; font-weight: 500; color: var(--ms-navy);
}
.ms-feature-item:hover { border-color: var(--ms-teal); background: var(--ms-teal-lt); }
.ms-feature-item input[type="checkbox"] { accent-color: var(--ms-teal); }
.ms-select-all-row {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 10px; font-size: 12px; font-weight: 600; color: var(--ms-teal);
    cursor: pointer;
}
.ms-select-all-row input { accent-color: var(--ms-teal); }

/* ── Form footer ── */
.ms-modal-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 16px 22px; border-top: 1px solid var(--ms-border);
    background: #f9fafb; border-radius: 0 0 12px 12px;
}

/* ── Toast ── */
.ms-toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.ms-toast {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
    box-shadow: 0 4px 16px rgba(0,0,0,.15); animation: msToastIn .25s ease;
    min-width: 240px;
}
@keyframes msToastIn { from { transform: translateX(60px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.ms-toast-success { background: #f0fdf4; color: var(--ms-green); border-left: 4px solid var(--ms-green); }
.ms-toast-error   { background: #fef2f2; color: var(--ms-red);   border-left: 4px solid var(--ms-red); }
.ms-toast-hide    { animation: msToastOut .3s ease forwards; }
@keyframes msToastOut { to { transform: translateX(60px); opacity: 0; } }
</style>