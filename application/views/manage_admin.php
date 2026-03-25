<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="ma-wrap">

    <!-- ── Page title + breadcrumb ── -->
    <div class="ma-page-title"><i class="fa fa-shield"></i> Admin Management</div>
    <ol class="ma-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Manage Admins</li>
    </ol>

    <!-- ── Tab bar ── -->
    <div class="ma-tabs">
        <button class="ma-tab" data-target="ma-listing" id="tabListing">
            <i class="fa fa-list-ul"></i> Admin Listing
        </button>
        <button class="ma-tab" data-target="ma-create">
            <i class="fa fa-user-plus"></i> Add New Admin
        </button>
        <button class="ma-tab" data-target="ma-password">
            <i class="fa fa-key"></i> Update Password
        </button>
    </div>

    <!-- ══════════════════════════════════════════
         PANEL 1 — ADMIN LISTING
    ══════════════════════════════════════════ -->
    <div class="ma-panel" id="ma-listing">
        <div class="ma-card">
            <div class="ma-card-head">
                <span><i class="fa fa-list-ul"></i> All Administrators</span>
                <span class="ma-count"><?= count($activeAdmins) + count($inactiveAdmins) ?> total</span>
            </div>
            <div class="ma-card-body--table">
                <div class="ma-tbl-wrap">
                    <table class="ma-tbl">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Admin ID</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $sr = 1; ?>
                        <?php foreach ($activeAdmins as $admin): ?>
                            <tr>
                                <td class="ma-num"><?= $sr++ ?></td>
                                <td class="ma-mono"><?= htmlspecialchars($admin['id'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="ma-role-badge"><?= htmlspecialchars($admin['role'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="ma-status ma-status--active"><i class="fa fa-circle"></i> Active</span></td>
                                <td>
                                    <button class="ma-act-btn ma-act-view" data-id="<?= htmlspecialchars($admin['id'], ENT_QUOTES, 'UTF-8') ?>" title="View / Edit">
                                        <i class="fa fa-pencil"></i> Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($inactiveAdmins as $admin): ?>
                            <tr class="ma-row--inactive">
                                <td class="ma-num"><?= $sr++ ?></td>
                                <td class="ma-mono"><?= htmlspecialchars($admin['id'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="ma-role-badge"><?= htmlspecialchars($admin['role'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="ma-status ma-status--inactive"><i class="fa fa-circle"></i> Inactive</span></td>
                                <td>
                                    <button class="ma-act-btn ma-act-view" data-id="<?= htmlspecialchars($admin['id'], ENT_QUOTES, 'UTF-8') ?>" title="View / Edit">
                                        <i class="fa fa-pencil"></i> Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($activeAdmins) && empty($inactiveAdmins)): ?>
                            <tr><td colspan="6" class="ma-empty"><i class="fa fa-inbox"></i><br>No administrators found</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         PANEL 2 — ADD NEW ADMIN
    ══════════════════════════════════════════ -->
    <div class="ma-panel" id="ma-create" style="display:none;">
        <div class="ma-card">
            <div class="ma-card-head">
                <span><i class="fa fa-user-plus"></i> Create New Administrator</span>
            </div>
            <div class="ma-card-body">
                <form id="addAdminForm" autocomplete="off">

                    <div class="ma-form-grid">

                        <!-- Left: Personal Info -->
                        <div class="ma-form-section">
                            <div class="ma-section-title"><i class="fa fa-user"></i> Personal Information</div>

                            <div class="ma-field">
                                <label>Full Name <span class="ma-req">*</span></label>
                                <input type="text" id="newName" name="name" class="ma-input" placeholder="Enter full name" required>
                            </div>
                            <div class="ma-field">
                                <label>Email Address <span class="ma-req">*</span></label>
                                <input type="email" id="newEmail" name="email" class="ma-input" placeholder="Enter email" required>
                            </div>
                            <div class="ma-field">
                                <label>Phone Number <span class="ma-req">*</span></label>
                                <input type="tel" id="newPhone" name="phone" class="ma-input" placeholder="Enter phone number" required>
                            </div>
                            <div class="ma-field">
                                <label>Date of Birth <span class="ma-req">*</span></label>
                                <input type="date" id="newDob" name="dob" class="ma-input" required>
                            </div>
                            <div class="ma-field">
                                <label>Gender <span class="ma-req">*</span></label>
                                <select id="newGender" name="gender" class="ma-select" required>
                                    <option value="" disabled selected>Select gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="ma-field">
                                <label>Role <span class="ma-req">*</span></label>
                                <select id="newRole" name="role" class="ma-select" required>
                                    <option value="" disabled selected>Select role</option>
                                    <option value="Super Admin">Super Admin</option>
                                    <option value="Accountant">Accountant</option>
                                    <option value="Academic Admin">Academic Admin</option>
                                </select>
                            </div>
                        </div>

                        <!-- Right: Credentials -->
                        <div class="ma-form-section">
                            <div class="ma-section-title"><i class="fa fa-lock"></i> Credentials</div>

                            <div class="ma-field">
                                <label>Admin ID</label>
                                <input type="text" name="admin" class="ma-input" value="<?= htmlspecialchars($adminId ?? 'NA', ENT_QUOTES, 'UTF-8') ?>" disabled>
                                <span class="ma-hint">Auto-generated</span>
                            </div>
                            <div class="ma-field">
                                <label>Password <span class="ma-req">*</span></label>
                                <div class="ma-pwd-wrap">
                                    <input type="password" id="newPassword" name="password" class="ma-input" placeholder="Enter password" required>
                                    <button type="button" class="ma-pwd-eye" data-target="newPassword"><i class="fa fa-eye"></i></button>
                                </div>
                            </div>
                            <div class="ma-field">
                                <label>Confirm Password <span class="ma-req">*</span></label>
                                <div class="ma-pwd-wrap">
                                    <input type="password" id="newConfirmPassword" name="confirm_password" class="ma-input" placeholder="Re-enter password" required>
                                    <button type="button" class="ma-pwd-eye" data-target="newConfirmPassword"><i class="fa fa-eye"></i></button>
                                </div>
                            </div>

                            <div class="ma-pwd-strength" id="pwdStrength" style="display:none;">
                                <div class="ma-pwd-bar"><div class="ma-pwd-fill" id="pwdFill"></div></div>
                                <span class="ma-pwd-lbl" id="pwdLbl"></span>
                            </div>
                        </div>

                    </div><!-- /.ma-form-grid -->

                    <div class="ma-form-actions">
                        <button type="reset" class="ma-btn ma-btn-ghost"><i class="fa fa-times"></i> Clear</button>
                        <button type="submit" class="ma-btn ma-btn-primary" id="createAdminBtn">
                            <i class="fa fa-user-plus"></i> Create Admin
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         PANEL 3 — UPDATE PASSWORD
    ══════════════════════════════════════════ -->
    <div class="ma-panel" id="ma-password" style="display:none;">
        <div class="ma-card" style="max-width:520px;">
            <div class="ma-card-head">
                <span><i class="fa fa-key"></i> Update Admin Password</span>
            </div>
            <div class="ma-card-body">
                <form id="updatePwdForm" autocomplete="off">

                    <div class="ma-field">
                        <label>Select Administrator <span class="ma-req">*</span></label>
                        <select name="admin_id" id="updAdminSel" class="ma-select" required>
                            <option value="" disabled selected>Select admin</option>
                            <?php foreach ($adminList as $a): ?>
                            <option value="<?= htmlspecialchars(explode(' - ', $a)[0], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ma-field">
                        <label>New Password <span class="ma-req">*</span></label>
                        <div class="ma-pwd-wrap">
                            <input type="password" id="updNewPwd" name="newPassword" class="ma-input" placeholder="Enter new password" required>
                            <button type="button" class="ma-pwd-eye" data-target="updNewPwd"><i class="fa fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="ma-field">
                        <label>Confirm Password <span class="ma-req">*</span></label>
                        <div class="ma-pwd-wrap">
                            <input type="password" id="updConfirmPwd" name="confirmPassword" class="ma-input" placeholder="Re-enter password" required>
                            <button type="button" class="ma-pwd-eye" data-target="updConfirmPwd"><i class="fa fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="ma-form-actions" style="margin-top:24px;">
                        <button type="submit" class="ma-btn ma-btn-primary" id="updatePwdBtn">
                            <i class="fa fa-check"></i> Update Password
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

</div><!-- /.ma-wrap -->
</div><!-- /.content-wrapper -->


<!-- ══════════════════════════════════════════
     VIEW / EDIT MODAL
══════════════════════════════════════════ -->
<div class="ma-overlay" id="maOverlay">
    <div class="ma-modal">
        <div class="ma-modal-head">
            <div>
                <div class="ma-modal-title"><i class="fa fa-user-circle-o"></i> Admin Details</div>
                <div class="ma-modal-id" id="maModalId"></div>
            </div>
            <button class="ma-modal-close" id="maModalClose">&times;</button>
        </div>
        <div class="ma-modal-body">
            <form id="editAdminForm" autocomplete="off">

                <div class="ma-modal-grid">
                    <div class="ma-field">
                        <label>Full Name</label>
                        <input type="text" id="mName" name="name" class="ma-input" disabled>
                    </div>
                    <div class="ma-field">
                        <label>Email</label>
                        <input type="email" id="mEmail" name="email" class="ma-input" disabled>
                    </div>
                    <div class="ma-field">
                        <label>Phone Number</label>
                        <input type="text" id="mPhone" name="phone" class="ma-input" disabled>
                    </div>
                    <div class="ma-field">
                        <label>Date of Birth</label>
                        <input type="date" id="mDob" name="dob" class="ma-input" disabled>
                    </div>
                    <div class="ma-field">
                        <label>Gender</label>
                        <select id="mGender" name="gender" class="ma-select" disabled>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="ma-field">
                        <label>Role</label>
                        <select id="mRole" name="role" class="ma-select" disabled>
                            <option value="Super Admin">Super Admin</option>
                            <option value="Accountant">Accountant</option>
                            <option value="Academic Admin">Academic Admin</option>
                        </select>
                    </div>
                </div>

                <div class="ma-field ma-field--toggle">
                    <label>Account Status</label>
                    <label class="ma-toggle">
                        <input type="checkbox" id="mStatus" disabled>
                        <span class="ma-toggle-track">
                            <span class="ma-toggle-thumb"></span>
                        </span>
                        <span class="ma-toggle-lbl" id="mStatusLbl">Active</span>
                    </label>
                </div>

            </form>
        </div>
        <div class="ma-modal-foot">
            <button class="ma-btn ma-btn-ghost" id="maEditBtn"><i class="fa fa-pencil"></i> Edit</button>
            <button class="ma-btn ma-btn-primary" id="maSaveBtn" style="display:none;"><i class="fa fa-check"></i> Save Changes</button>
        </div>
    </div>
</div>


<script>
/* ================================================================
   manage_admin.php — ERP Gold Theme JS (all CSRF fixed)
================================================================ */
(function () {
    'use strict';

    /* ── CSRF helper ── */
    function csrfFd() {
        var fd = new FormData();
        fd.append(csrfName, csrfToken);
        return fd;
    }

    /* ── Toast ── */
    function toast(type, msg) {
        var accent = { success: 'var(--gold)', error: '#e05c6f', warning: '#f59e0b', info: 'var(--t3)' };
        var el = document.createElement('div');
        el.textContent = msg;
        el.style.cssText = 'position:fixed;top:22px;right:22px;z-index:10000;padding:12px 18px;background:var(--bg2);color:var(--t1);border-left:4px solid ' + (accent[type] || accent.info) + ';border-radius:10px;box-shadow:var(--sh);font-size:13px;font-family:var(--font-b);max-width:320px;pointer-events:none;';
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 3500);
    }

    /* ══════════════════════════════════════════
       TABS
    ══════════════════════════════════════════ */
    var tabs   = document.querySelectorAll('.ma-tab');
    var panels = document.querySelectorAll('.ma-panel');

    function activateTab(target) {
        tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.target === target); });
        panels.forEach(function (p) { p.style.display = p.id === target ? '' : 'none'; });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { activateTab(this.dataset.target); });
    });

    // Default tab
    activateTab('ma-listing');

    /* ══════════════════════════════════════════
       PASSWORD EYE TOGGLE
    ══════════════════════════════════════════ */
    document.querySelectorAll('.ma-pwd-eye').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var inp = document.getElementById(this.dataset.target);
            if (!inp) return;
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            this.querySelector('i').className = show ? 'fa fa-eye-slash' : 'fa fa-eye';
        });
    });

    /* ══════════════════════════════════════════
       PASSWORD STRENGTH METER
    ══════════════════════════════════════════ */
    var pwdInput   = document.getElementById('newPassword');
    var pwdStrength = document.getElementById('pwdStrength');
    var pwdFill    = document.getElementById('pwdFill');
    var pwdLbl     = document.getElementById('pwdLbl');

    if (pwdInput) {
        pwdInput.addEventListener('input', function () {
            var v = this.value;
            if (!v) { pwdStrength.style.display = 'none'; return; }
            pwdStrength.style.display = '';
            var score = 0;
            if (v.length >= 8)            score++;
            if (/[A-Z]/.test(v))          score++;
            if (/[0-9]/.test(v))          score++;
            if (/[^A-Za-z0-9]/.test(v))   score++;
            var map = [
                { w: '25%',  color: '#e05c6f', lbl: 'Weak' },
                { w: '50%',  color: '#f59e0b', lbl: 'Fair' },
                { w: '75%',  color: 'var(--gold)', lbl: 'Good' },
                { w: '100%', color: '#3DD68C', lbl: 'Strong' },
            ];
            var s = map[Math.max(0, score - 1)];
            pwdFill.style.width = s.w;
            pwdFill.style.background = s.color;
            pwdLbl.textContent = s.lbl;
            pwdLbl.style.color = s.color;
        });
    }

    /* ══════════════════════════════════════════
       CREATE ADMIN FORM
    ══════════════════════════════════════════ */
    var addForm = document.getElementById('addAdminForm');
    if (addForm) {
        addForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var pwd  = document.getElementById('newPassword').value;
            var cpwd = document.getElementById('newConfirmPassword').value;
            if (pwd !== cpwd) { toast('warning', 'Passwords do not match.'); return; }

            var btn = document.getElementById('createAdminBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating…';

            var fd = csrfFd();
            new FormData(addForm).forEach(function (v, k) { fd.append(k, v); });

            fetch(BASE_URL + 'admin/manage_admin', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.status === 'success') {
                    toast('success', 'Admin created successfully.');
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    toast('error', (res && res.message) || 'Failed to create admin.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-user-plus"></i> Create Admin';
                }
            })
            .catch(function () {
                toast('error', 'Server error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-user-plus"></i> Create Admin';
            });
        });
    }

    /* ══════════════════════════════════════════
       UPDATE PASSWORD FORM
    ══════════════════════════════════════════ */
    var updForm = document.getElementById('updatePwdForm');
    if (updForm) {
        updForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var newPwd  = document.getElementById('updNewPwd').value;
            var confPwd = document.getElementById('updConfirmPwd').value;
            var adminId = document.getElementById('updAdminSel').value;

            if (!adminId) { toast('warning', 'Please select an admin.'); return; }
            if (newPwd !== confPwd) { toast('warning', 'Passwords do not match.'); return; }
            if (newPwd.length < 6)  { toast('warning', 'Password must be at least 6 characters.'); return; }

            var btn = document.getElementById('updatePwdBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating…';

            var fd = csrfFd();
            fd.append('admin_id',        adminId);
            fd.append('newPassword',     newPwd);
            fd.append('confirmPassword', confPwd);

            fetch(BASE_URL + 'admin/manage_admin', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.status === 'success') {
                    toast('success', 'Password updated successfully.');
                    updForm.reset();
                } else {
                    toast('error', (res && res.message) || 'Failed to update password.');
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-check"></i> Update Password';
            })
            .catch(function () {
                toast('error', 'Server error.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-check"></i> Update Password';
            });
        });
    }

    /* ══════════════════════════════════════════
       VIEW / EDIT MODAL
    ══════════════════════════════════════════ */
    var overlay  = document.getElementById('maOverlay');
    var modalId  = document.getElementById('maModalId');
    var editBtn  = document.getElementById('maEditBtn');
    var saveBtn  = document.getElementById('maSaveBtn');
    var closeBtn = document.getElementById('maModalClose');
    var mStatus  = document.getElementById('mStatus');
    var mStatusLbl = document.getElementById('mStatusLbl');
    var currentAdminId = null;

    // Status toggle label
    if (mStatus) {
        mStatus.addEventListener('change', function () {
            mStatusLbl.textContent = this.checked ? 'Active' : 'Inactive';
        });
    }

    // Open modal on View click
    document.querySelectorAll('.ma-act-view').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var adminId = this.dataset.id;
            currentAdminId = adminId;
            modalId.textContent = adminId;

            var fd = csrfFd();
            fd.append('admin_id', adminId);

            fetch(BASE_URL + 'admin/manage_admin', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.status === 'error') {
                    toast('error', (data && data.message) || 'Failed to fetch admin details.');
                    return;
                }
                var d = data.data;
                document.getElementById('mName').value  = d.Name        || '';
                document.getElementById('mEmail').value = d.Email       || '';
                document.getElementById('mPhone').value = d.PhoneNumber || '';
                // Convert dd-mm-yyyy → yyyy-mm-dd for date input
                var dob = (d.DOB || '').split('-').reverse().join('-');
                document.getElementById('mDob').value    = dob;
                document.getElementById('mGender').value = d.Gender || 'Male';
                document.getElementById('mRole').value   = d.Role   || '';
                mStatus.checked = (d.Status === 'Active');
                mStatusLbl.textContent = mStatus.checked ? 'Active' : 'Inactive';

                // Reset to view mode
                setModalEditing(false);
                overlay.classList.add('open');
            })
            .catch(function () { toast('error', 'Server error.'); });
        });
    });

    // Enable editing
    editBtn.addEventListener('click', function () { setModalEditing(true); });

    // Save changes
    saveBtn.addEventListener('click', function () {
        if (!currentAdminId) return;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

        var adminData = {
            Name:        document.getElementById('mName').value  || 'N/A',
            Email:       document.getElementById('mEmail').value || 'N/A',
            PhoneNumber: document.getElementById('mPhone').value || 'N/A',
            DOB:         (document.getElementById('mDob').value || '').split('-').reverse().join('-'),
            Gender:      document.getElementById('mGender').value || 'N/A',
            Role:        document.getElementById('mRole').value  || 'N/A',
            Status:      mStatus.checked ? 'Active' : 'Inactive',
        };

        var fd = csrfFd();
        fd.append('modal_id',  currentAdminId);
        fd.append('user_data[Name]',        adminData.Name);
        fd.append('user_data[Email]',       adminData.Email);
        fd.append('user_data[PhoneNumber]', adminData.PhoneNumber);
        fd.append('user_data[DOB]',         adminData.DOB);
        fd.append('user_data[Gender]',      adminData.Gender);
        fd.append('user_data[Role]',        adminData.Role);
        fd.append('user_data[Status]',      adminData.Status);

        fetch(BASE_URL + 'admin/updateUserData', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && (res.success || res.status === 'success')) {
                toast('success', 'Admin updated successfully.');
                overlay.classList.remove('open');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                toast('error', (res && res.message) || 'Failed to save changes.');
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fa fa-check"></i> Save Changes';
            }
        })
        .catch(function () {
            toast('error', 'Server error.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa fa-check"></i> Save Changes';
        });
    });

    // Close modal
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });

    function closeModal() {
        overlay.classList.remove('open');
        setModalEditing(false);
        currentAdminId = null;
    }

    function setModalEditing(on) {
        document.querySelectorAll('#editAdminForm input, #editAdminForm select').forEach(function (el) {
            el.disabled = !on;
        });
        // Keep status toggle always visible
        mStatus.disabled = !on;
        editBtn.style.display = on ? 'none' : '';
        saveBtn.style.display = on ? '' : 'none';
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa fa-check"></i> Save Changes';
    }

})();
</script>


<style>
/* ── Manage Admin — ERP Gold Theme ── */

:root, [data-theme="night"], [data-theme="day"] {
    --ma-bg:     var(--bg);
    --ma-card:   var(--bg2);
    --ma-border: var(--border);
    --ma-t1:     var(--t1);
    --ma-t2:     var(--t2);
    --ma-t3:     var(--t3);
    --ma-gold:   var(--gold);
    --ma-dim:    var(--gold-dim);
    --ma-sh:     var(--sh);
    --ma-r:      14px;
}

.ma-wrap {
    font-family: var(--font-b);
    background: var(--ma-bg);
    color: var(--ma-t1);
    padding: 24px 20px 52px;
    min-height: 100vh;
}

/* ── Page title ── */
.ma-page-title {
    font-family: var(--font-d);
    font-size: 22px; font-weight: 800;
    color: var(--ma-t1); margin-bottom: 6px;
    display: flex; align-items: center; gap: 10px;
}
.ma-page-title i { color: var(--ma-gold); }

/* ── Breadcrumb ── */
.ma-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; color: var(--ma-t3);
    font-family: var(--font-b); margin-bottom: 22px;
    list-style: none; padding: 0;
}
.ma-breadcrumb li:not(:last-child)::after { content: '/'; margin-left: 6px; opacity:.5; }
.ma-breadcrumb a { color: var(--ma-gold); text-decoration: none; }

/* ── Tab bar ── */
.ma-tabs {
    display: flex; gap: 4px;
    background: var(--ma-card);
    border: 1px solid var(--ma-border);
    border-radius: var(--ma-r);
    padding: 6px; margin-bottom: 16px;
    box-shadow: var(--ma-sh);
    overflow-x: auto; scrollbar-width: none;
}
.ma-tabs::-webkit-scrollbar { display: none; }
.ma-tab {
    flex-shrink: 0;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border: none;
    border-radius: 9px; background: transparent;
    color: var(--ma-t3);
    font-size: 13px; font-weight: 600;
    font-family: var(--font-b); cursor: pointer;
    transition: all .2s; white-space: nowrap;
}
.ma-tab:hover { background: var(--ma-dim); color: var(--ma-gold); }
.ma-tab.active { background: var(--ma-gold); color: #ffffff; }
.ma-tab i { font-size: 12px; }

/* ── Card ── */
.ma-card {
    background: var(--ma-card);
    border: 1px solid var(--ma-border);
    border-radius: var(--ma-r);
    box-shadow: var(--ma-sh);
    overflow: hidden;
    margin-bottom: 20px;
}
.ma-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 24px; height: 48px;
    background: linear-gradient(90deg, var(--ma-gold) 0%, var(--gold2,#0d6b63) 100%);
    color: #ffffff;
    font-size: 13px; font-weight: 700; font-family: var(--font-b);
}
.ma-count {
    font-size: 11px; font-family: var(--font-m);
    background: rgba(255,255,255,.15); padding: 3px 10px; border-radius: 20px;
}
.ma-card-body { padding: 24px 28px; }
.ma-card-body--table { padding: 0; }

/* ── Table ── */
.ma-tbl-wrap { overflow-x: auto; }
.ma-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.ma-tbl thead th {
    background: linear-gradient(90deg, var(--ma-gold) 0%, var(--gold2,#0d6b63) 100%);
    color: #ffffff; padding: 13px 16px;
    font-size: 12px; font-weight: 700; font-family: var(--font-b);
    text-align: left; white-space: nowrap;
}
.ma-tbl tbody tr { border-bottom: 1px solid var(--ma-border); transition: background .15s; }
.ma-tbl tbody tr:hover { background: var(--ma-dim); }
.ma-row--inactive { opacity: .6; }
.ma-tbl tbody td { padding: 13px 16px; color: var(--ma-t1); vertical-align: middle; }
.ma-num { color: var(--ma-t3); font-family: var(--font-m); font-size: 12px; width: 40px; }
.ma-mono { font-family: var(--font-m); font-size: 12px; }
.ma-empty { text-align: center; padding: 48px; color: var(--ma-t3); font-size: 14px; line-height: 2; }

/* ── Status badges ── */
.ma-status { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; font-family:var(--font-b); }
.ma-status i { font-size: 7px; }
.ma-status--active  { background:rgba(61,214,140,.12); color:#3DD68C; border:1px solid rgba(61,214,140,.3); }
.ma-status--inactive{ background:rgba(224,92,111,.1); color:#e05c6f; border:1px solid rgba(224,92,111,.25); }

/* ── Role badge ── */
.ma-role-badge {
    display: inline-block; padding: 3px 10px;
    background: var(--ma-dim); color: var(--ma-gold);
    border: 1px solid rgba(15,118,110,.3);
    border-radius: 20px; font-size: 11px; font-weight: 700;
    font-family: var(--font-b);
}

/* ── Action button ── */
.ma-act-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 8px;
    border: 1px solid var(--ma-border);
    background: transparent; cursor: pointer;
    font-size: 12px; font-weight: 600;
    font-family: var(--font-b); transition: all .2s;
}
.ma-act-view { color: var(--ma-gold); }
.ma-act-view:hover { background: var(--ma-dim); border-color: var(--ma-gold); }

/* ── Form layout ── */
.ma-form-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 24px;
}
.ma-form-section {
    background: var(--bg3, var(--bg));
    border: 1px solid var(--ma-border);
    border-radius: 12px; padding: 20px;
}
.ma-section-title {
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .7px; color: var(--ma-t2);
    font-family: var(--font-b);
    padding-bottom: 12px; margin-bottom: 18px;
    border-bottom: 1px solid var(--ma-border);
    display: flex; align-items: center; gap: 7px;
}
.ma-section-title i { color: var(--ma-gold); }

/* ── Fields ── */
.ma-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.ma-field:last-child { margin-bottom: 0; }
.ma-field label {
    font-size: 12px; font-weight: 700;
    color: var(--ma-t2); text-transform: uppercase;
    letter-spacing: .5px; font-family: var(--font-b);
}
.ma-req { color: #e05c6f; }
.ma-hint { font-size: 11px; color: var(--ma-t3); margin-top: 2px; }

.ma-input, .ma-select {
    height: 42px; padding: 0 12px;
    border: 1px solid var(--ma-border);
    border-radius: 10px;
    background: var(--ma-card); color: var(--ma-t1);
    font-size: 13px; font-family: var(--font-b);
    transition: border-color .2s, box-shadow .2s;
    width: 100%; box-sizing: border-box;
}
.ma-input:focus, .ma-select:focus {
    outline: none; border-color: var(--ma-gold);
    box-shadow: 0 0 0 3px var(--ma-dim);
}
.ma-input:disabled, .ma-select:disabled { opacity: .5; cursor: not-allowed; }
.ma-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px;
}

/* ── Password wrap ── */
.ma-pwd-wrap { position: relative; }
.ma-pwd-wrap .ma-input { padding-right: 42px; }
.ma-pwd-eye {
    position: absolute; top: 50%; right: 10px; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--ma-t3); font-size: 14px; padding: 4px;
    transition: color .2s;
}
.ma-pwd-eye:hover { color: var(--ma-gold); }

/* ── Password strength ── */
.ma-pwd-strength { margin-top: 6px; }
.ma-pwd-bar { height: 4px; background: var(--ma-border); border-radius: 2px; overflow: hidden; margin-bottom: 4px; }
.ma-pwd-fill { height: 100%; width: 0; border-radius: 2px; transition: all .3s; }
.ma-pwd-lbl { font-size: 11px; font-family: var(--font-m); }

/* ── Buttons ── */
.ma-btn {
    height: 40px; padding: 0 22px; border-radius: 20px; border: none;
    font-size: 13px; font-weight: 600; font-family: var(--font-b);
    cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
    transition: all .2s;
}
.ma-btn:disabled { opacity: .5; cursor: not-allowed; }
.ma-btn-primary {
    background: linear-gradient(135deg, var(--ma-gold) 0%, var(--gold2,#0d6b63) 100%);
    color: #ffffff;
}
.ma-btn-primary:not(:disabled):hover { opacity: .9; transform: translateY(-1px); }
.ma-btn-ghost { background: transparent; color: var(--ma-t3); border: 1px solid var(--ma-border); }
.ma-btn-ghost:hover { border-color: var(--ma-t2); color: var(--ma-t1); }

.ma-form-actions {
    display: flex; align-items: center; justify-content: flex-end; gap: 12px;
    margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--ma-border);
}

/* ── Toggle switch ── */
.ma-field--toggle { flex-direction: row; align-items: center; gap: 16px; padding: 16px 0; border-top: 1px solid var(--ma-border); margin-top: 4px; }
.ma-field--toggle label:first-child { width: auto; margin: 0; }
.ma-toggle { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; }
.ma-toggle input { display: none; }
.ma-toggle-track {
    position: relative; width: 44px; height: 24px;
    background: var(--ma-border); border-radius: 12px;
    transition: background .25s;
}
.ma-toggle input:checked ~ .ma-toggle-track { background: var(--ma-gold); }
.ma-toggle-thumb {
    position: absolute; top: 3px; left: 3px;
    width: 18px; height: 18px; border-radius: 50%;
    background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2);
    transition: transform .25s;
}
.ma-toggle input:checked ~ .ma-toggle-track .ma-toggle-thumb { transform: translateX(20px); }
.ma-toggle-lbl { font-size: 13px; font-weight: 600; color: var(--ma-t2); }

/* ══════════════════════════════════════════
   MODAL
══════════════════════════════════════════ */
.ma-overlay {
    display: none; position: fixed; inset: 0; z-index: 1050;
    background: rgba(0,0,0,.55); backdrop-filter: blur(3px);
    align-items: center; justify-content: center;
}
.ma-overlay.open { display: flex; }
.ma-modal {
    background: var(--ma-card);
    border: 1px solid var(--ma-border);
    border-radius: var(--ma-r);
    box-shadow: 0 24px 60px rgba(0,0,0,.4);
    width: 90%; max-width: 580px;
    max-height: 90vh; overflow-y: auto;
    display: flex; flex-direction: column;
}
.ma-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 24px;
    background: linear-gradient(130deg, #0c1e38 0%, #070f1c 100%);
    border-bottom: 1px solid rgba(15,118,110,.2);
    position: sticky; top: 0; z-index: 2;
}
.ma-modal-title { font-size: 14px; font-weight: 700; color: #e6f4f1; display: flex; align-items: center; gap: 8px; }
.ma-modal-id { font-size: 11px; color: rgba(230,244,241,.5); font-family: var(--font-m); margin-top: 2px; }
.ma-modal-close {
    background: none; border: none; color: rgba(230,244,241,.5);
    font-size: 22px; cursor: pointer; padding: 4px 8px; line-height: 1;
    transition: color .2s;
}
.ma-modal-close:hover { color: #e6f4f1; }
.ma-modal-body { padding: 24px; flex: 1; }
.ma-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 4px; }
.ma-modal-foot {
    padding: 16px 24px;
    border-top: 1px solid var(--ma-border);
    display: flex; justify-content: flex-end; gap: 12px;
    background: var(--bg3, var(--bg));
}

@media (max-width: 640px) {
    .ma-form-grid { grid-template-columns: 1fr; }
    .ma-modal-grid { grid-template-columns: 1fr; }
}
</style>
