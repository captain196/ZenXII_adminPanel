<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="nsa-wrap">

    <!-- ── Top bar ── -->
    <div class="nsa-topbar">
        <h1 class="nsa-page-title">
            <i class="fa fa-pencil-square-o"></i> Edit Staff
        </h1>
        <ol class="nsa-breadcrumb">
            <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
            <li><a href="<?= base_url('staff/all_staff') ?>">All Staff</a></li>
            <li><?= htmlspecialchars($staff_data['Name'] ?? 'Edit Staff') ?></li>
        </ol>
    </div>

    <!-- ── Layout ── -->
    <div class="nsa-layout">

        <!-- Sidebar nav -->
        <aside class="nsa-sidebar">
            <div class="nsa-sidebar-title">Form Sections</div>
            <a class="nsa-nav-item active" href="#sec-basic"><i class="fa fa-user"></i> <span>Basic Info</span></a>
            <a class="nsa-nav-item" href="#sec-guardian"><i class="fa fa-users"></i> <span>Guardian</span></a>
            <a class="nsa-nav-item" href="#sec-address"><i class="fa fa-map-marker"></i> <span>Address</span></a>
            <a class="nsa-nav-item" href="#sec-qualification"><i class="fa fa-graduation-cap"></i> <span>Qualification</span></a>
            <a class="nsa-nav-item" href="#sec-bank"><i class="fa fa-university"></i> <span>Bank Details</span></a>
            <a class="nsa-nav-item" href="#sec-salary"><i class="fa fa-money"></i> <span>Salary</span></a>
            <a class="nsa-nav-item" href="#sec-docs"><i class="fa fa-file-text-o"></i> <span>Documents</span></a>
        </aside>

        <!-- Main form -->
        <div class="nsa-main">
            <form action="<?= base_url('staff/edit_staff/' . htmlspecialchars($staff_data['User ID'])) ?>"
                  method="post"
                  id="edit_staff_form"
                  enctype="multipart/form-data">
                  <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" 
           value="<?= $this->security->get_csrf_hash() ?>">

                <!-- ══ BASIC INFORMATION ══ -->
                <div class="nsa-section" id="sec-basic">
                    <div class="nsa-section-head">
                        <i class="fa fa-user"></i>
                        <h3>Basic Information</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid">

                            <div class="nsa-field">
                                <label>Staff ID</label>
                                <input type="text" name="user_id" id="user_id"
                                       value="<?= htmlspecialchars($staff_data['User ID'] ?? '') ?>"
                                       class="nsa-input" readonly>
                            </div>

                            <div class="nsa-field">
                                <label>Staff Name <span class="req">*</span></label>
                                <input type="text" id="name" name="Name"
                                       value="<?= htmlspecialchars($staff_data['Name'] ?? '') ?>"
                                       class="nsa-input" placeholder="Full name" required>
                            </div>

                            <div class="nsa-field">
                                <label>Date of Birth <span class="req">*</span></label>
                                <input type="date" id="dob" name="DOB"
                                       value="<?= !empty($staff_data['DOB']) ? date('Y-m-d', strtotime($staff_data['DOB'])) : '' ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>Email <span class="req">*</span></label>
                                <input type="email" id="email_user" name="Email"
                                       value="<?= htmlspecialchars($staff_data['Email'] ?? '') ?>"
                                       class="nsa-input" placeholder="staff@email.com" required>
                            </div>

                            <div class="nsa-field">
                                <label>Gender <span class="req">*</span></label>
                                <select id="gender" name="gender" class="nsa-select" required>
                                    <option value="">Select Gender</option>
                                    <?php foreach (['Male','Female','Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($staff_data['Gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label>Blood Group <span class="req">*</span></label>
                                <select id="blood_group" name="blood_group" class="nsa-select" required>
                                    <option value="">Select</option>
                                    <?php
                                    $currentBG = $staff_data['blood_group'] ?? $staff_data['Blood Group'] ?? '';
                                    foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-','Unknown'] as $bg):
                                    ?>
                                    <option value="<?= $bg ?>" <?= $currentBG === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label>Religion <span class="req">*</span></label>
                                <select id="religion" name="religion" class="nsa-select" required>
                                    <option value="">Select Religion</option>
                                    <?php foreach (['Hindu','Muslim','Sikh','Jain','Buddh','Christian','Other'] as $r): ?>
                                    <option value="<?= $r ?>" <?= ($staff_data['Religion'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label>Category <span class="req">*</span></label>
                                <select id="category" name="category" class="nsa-select" required>
                                    <option value="">Select</option>
                                    <?php foreach (['General','OBC','SC','ST'] as $c): ?>
                                    <option value="<?= $c ?>" <?= ($staff_data['Category'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php
                                $existingRoles   = $staff_data['staff_roles']  ?? [];
                                $existingPrimary = $staff_data['primary_role'] ?? '';
                                if (!is_array($existingRoles)) $existingRoles = [];
                            ?>
                            <!-- Staff roles auto-assigned from Designation via backend -->
                            <input type="hidden" name="staff_roles" id="staffRolesHidden"
                                   value="<?= htmlspecialchars(implode(',', $existingRoles)) ?>">
                            <input type="hidden" name="primary_role" id="primaryRoleHidden"
                                   value="<?= htmlspecialchars($existingPrimary) ?>">

                            <div class="nsa-field">
                                <label>Designation / Title <span class="req">*</span></label>
                                <?php $curPos = $staff_data['Position'] ?? ''; ?>
                                <select id="staff_position" name="position" class="nsa-input" required>
                                    <option value="">-- Select Designation --</option>
                                    <?php
                                    $designations = ['Teacher','Senior Teacher','Head of Department','Vice Principal','Principal','Accountant','Librarian','Lab Assistant','Clerk','Receptionist','IT Administrator','Sports Coach','Counselor','Driver','Security Guard','Peon / Attendant','Other'];
                                    foreach ($designations as $dsg):
                                        $sel = ($curPos === $dsg) ? ' selected' : '';
                                    ?>
                                    <option value="<?= $dsg ?>"<?= $sel ?>><?= $dsg ?></option>
                                    <?php endforeach;
                                    // If current position doesn't match any preset, add it
                                    if ($curPos !== '' && !in_array($curPos, $designations, true)):
                                    ?>
                                    <option value="<?= htmlspecialchars($curPos) ?>" selected><?= htmlspecialchars($curPos) ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label>Date of Joining</label>
                                <input type="date" id="date_of_joining" name="date_of_joining"
                                       value="<?= !empty($staff_data['Date Of Joining']) ? date('Y-m-d', strtotime($staff_data['Date Of Joining'])) : '' ?>"
                                       class="nsa-input" readonly>
                            </div>

                            <div class="nsa-field">
                                <label>Employment Type <span class="req">*</span></label>
                                <input type="text" id="employment_type" name="employment_type"
                                       value="<?= htmlspecialchars($staff_data['Employment Type'] ?? '') ?>"
                                       class="nsa-input" placeholder="e.g. Full-time" required>
                            </div>

                            <div class="nsa-field">
                                <label>Department <span class="req">*</span></label>
                                <select id="teacher_department" name="department" class="nsa-input" required>
                                    <option value="">-- Select Department --</option>
                                </select>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ GUARDIAN DETAILS ══ -->
                <div class="nsa-section" id="sec-guardian">
                    <div class="nsa-section-head">
                        <i class="fa fa-users"></i>
                        <h3>Guardian Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label>Father's Name <span class="req">*</span></label>
                                <input type="text" id="father_name" name="father_name"
                                       value="<?= htmlspecialchars($staff_data['Father Name'] ?? '') ?>"
                                       class="nsa-input" placeholder="Father's full name" required>
                            </div>

                            <div class="nsa-field">
                                <label>Phone Number <span class="req">*</span></label>
                                <input type="tel" id="phone_number" name="phone_number"
                                       value="<?= htmlspecialchars($staff_data['Phone Number'] ?? '') ?>"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10" required>
                            </div>

                            <div class="nsa-field">
                                <label>Emergency Contact Name <span class="req">*</span></label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                                       value="<?= htmlspecialchars($staff_data['emergencyContact']['name'] ?? '') ?>"
                                       class="nsa-input" placeholder="Full name" required>
                            </div>

                            <div class="nsa-field">
                                <label>Emergency Contact Number <span class="req">*</span></label>
                                <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone"
                                       value="<?= htmlspecialchars($staff_data['emergencyContact']['phoneNumber'] ?? '') ?>"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ ADDRESS ══ -->
                <div class="nsa-section" id="sec-address">
                    <div class="nsa-section-head">
                        <i class="fa fa-map-marker"></i>
                        <h3>Address Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field nsa-col-2">
                                <label>Street <span class="req">*</span></label>
                                <input type="text" id="street" name="street"
                                       value="<?= htmlspecialchars($staff_data['Address']['Street'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>City <span class="req">*</span></label>
                                <input type="text" id="city" name="city"
                                       value="<?= htmlspecialchars($staff_data['Address']['City'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>State <span class="req">*</span></label>
                                <input type="text" id="state" name="state"
                                       value="<?= htmlspecialchars($staff_data['Address']['State'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>Postal Code <span class="req">*</span></label>
                                <input type="text" id="postal_code" name="postalcode"
                                       value="<?= htmlspecialchars($staff_data['Address']['PostalCode'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ QUALIFICATION ══ -->
                <div class="nsa-section" id="sec-qualification">
                    <div class="nsa-section-head">
                        <i class="fa fa-graduation-cap"></i>
                        <h3>Qualification Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-3">

                            <div class="nsa-field">
                                <label>Highest Qualification <span class="req">*</span></label>
                                <input type="text" id="qualification" name="qualification"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['highestQualification'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>University <span class="req">*</span></label>
                                <input type="text" id="university" name="university"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['university'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>Year of Passing <span class="req">*</span></label>
                                <input type="text" id="year_of_passing" name="year_of_passing"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['yearOfPassing'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>Work Experience (Years) <span class="req">*</span></label>
                                <input type="text" id="teacher_experience" name="teacher_experience"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['experience'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ BANK DETAILS ══ -->
                <div class="nsa-section" id="sec-bank">
                    <div class="nsa-section-head">
                        <i class="fa fa-university"></i>
                        <h3>Bank Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label>Bank Name <span class="req">*</span></label>
                                <input type="text" id="bank_name" name="bank_name"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['bankName'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>Account Holder Name <span class="req">*</span></label>
                                <input type="text" id="account_holder" name="account_holder"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['accountHolderName'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>Account Number <span class="req">*</span></label>
                                <input type="text" id="account_number" name="account_number"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['accountNumber'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>IFSC Code <span class="req">*</span></label>
                                <input type="text" id="bank_ifsc" name="bank_ifsc"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['ifscCode'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ SALARY ══ -->
                <div class="nsa-section" id="sec-salary">
                    <div class="nsa-section-head">
                        <i class="fa fa-money"></i>
                        <h3>Salary Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label>Basic Salary (₹) <span class="req">*</span></label>
                                <input type="number" id="basicSalary" name="basicSalary"
                                       value="<?= htmlspecialchars($staff_data['salaryDetails']['basicSalary'] ?? '') ?>"
                                       class="nsa-input" min="0" required>
                            </div>

                            <div class="nsa-field">
                                <label>Allowances (₹) <span class="req">*</span></label>
                                <input type="number" id="allowances" name="allowances"
                                       value="<?= htmlspecialchars($staff_data['salaryDetails']['Allowances'] ?? '') ?>"
                                       class="nsa-input" min="0" required>
                            </div>

                            <div class="nsa-field nsa-col-2">
                                <div class="nsa-net-salary-box">
                                    <span class="nsa-net-label"><i class="fa fa-calculator"></i> Net Salary</span>
                                    <span class="nsa-net-value" id="netSalaryDisplay">
                                        ₹ <?= number_format(($staff_data['salaryDetails']['Net Salary'] ?? 0), 0, '.', ',') ?>
                                    </span>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ DOCUMENTS & PHOTO ══ -->
                <div class="nsa-section" id="sec-docs">
                    <div class="nsa-section-head">
                        <i class="fa fa-file-text-o"></i>
                        <h3>Documents &amp; Photo</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <!-- Staff Photo -->
                            <div class="nsa-field">
                                <label>Staff Photo <span class="nsa-optional-tag">optional — leave empty to keep current</span></label>

                                <?php
                                // Resolve current photo URL from new Doc structure or old ProfilePic key
                                $currentPhotoUrl = $staff_data['Doc']['Photo']['url']
                                                ?? $staff_data['ProfilePic']
                                                ?? $staff_data['Photo URL']
                                                ?? '';
                                ?>

                                <div class="nsa-photo-wrap">
                                    <div class="nsa-photo-preview-box">
                                        <img id="staffPhotoPreview"
                                             src="<?= !empty($currentPhotoUrl) ? htmlspecialchars($currentPhotoUrl) : base_url('tools/dist/img/kids.jpg') ?>"
                                             alt="Staff Photo">
                                        <span class="nsa-photo-hint">JPG / JPEG only</span>
                                        <?php if (!empty($currentPhotoUrl)): ?>
                                        <a href="<?= htmlspecialchars($currentPhotoUrl) ?>" target="_blank"
                                           class="nsa-current-link">
                                            <i class="fa fa-eye"></i> View current
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="nsa-photo-file">
                                        <div class="nsa-file-wrap" id="wrap_Photo">
                                            <input type="file" name="Photo" id="photo"
                                                   accept="image/jpeg,image/jpg"
                                                   onchange="previewStaffPhoto(event);nsaFileChosen(this,'wrap_Photo')">
                                            <div class="nsa-file-label">
                                                <div class="nsa-file-icon"><i class="fa fa-camera"></i></div>
                                                <div class="nsa-file-text">
                                                    <strong>Replace Photo</strong>
                                                    JPG, JPEG · max 2MB
                                                </div>
                                            </div>
                                            <div class="nsa-file-name" id="fn_Photo"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Aadhar Card -->
                            <div class="nsa-field">
                                <label>Aadhar Card <span class="nsa-optional-tag">optional — leave empty to keep current</span></label>

                                <?php
                                $currentAadharUrl   = $staff_data['Doc']['Aadhar Card']['url']
                                                    ?? $staff_data['Aadhar URL']
                                                    ?? '';
                                $currentAadharThumb = $staff_data['Doc']['Aadhar Card']['thumbnail']
                                                    ?? '';

                                /*
                                 * Detect if the ORIGINAL document is a PDF.
                                 * We check both the doc URL and the thumb URL.
                                 * If thumb URL is empty OR equals the doc URL AND doc is PDF
                                 * → thumbnail generation failed / fell back → show PDF icon instead.
                                 *
                                 * A URL is considered PDF if it contains ".pdf" before "?" or at end.
                                 */
                                $aadharIsPdf = (bool) preg_match('/\.pdf(\?|$)/i', $currentAadharUrl);

                                /*
                                 * Thumb is usable only when:
                                 *  - it's not empty
                                 *  - it's different from the doc URL  (i.e. not the fallback copy)
                                 *  - OR the doc is an image (not PDF) — in that case thumb==doc is fine
                                 */
                                $thumbIsUsable = !empty($currentAadharThumb)
                                    && (!$aadharIsPdf || $currentAadharThumb !== $currentAadharUrl);
                                ?>

                                <!-- Current Aadhar preview card -->
                                <?php if (!empty($currentAadharUrl)): ?>
                                <div class="nsa-current-doc-card">
                                    <div class="nsa-current-doc-left">

                                        <?php if ($aadharIsPdf && !$thumbIsUsable): ?>
                                        <!-- ── PDF with no thumbnail → show PDF icon placeholder ── -->
                                        <div class="nsa-doc-thumb-placeholder nsa-doc-pdf-placeholder">
                                            <i class="fa fa-file-pdf-o"></i>
                                            <span>PDF</span>
                                        </div>

                                        <?php elseif ($thumbIsUsable): ?>
                                        <!-- ── Image thumbnail available ── -->
                                        <img src="<?= htmlspecialchars($currentAadharThumb) ?>"
                                             alt="Aadhar thumbnail"
                                             class="nsa-doc-thumb"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <!-- Hidden fallback shown via onerror above -->
                                        <div class="nsa-doc-thumb-placeholder" style="display:none;">
                                            <i class="fa fa-id-card"></i>
                                        </div>

                                        <?php else: ?>
                                        <!-- ── No thumb at all → generic icon ── -->
                                        <div class="nsa-doc-thumb-placeholder">
                                            <i class="fa fa-id-card"></i>
                                        </div>
                                        <?php endif; ?>

                                    </div>
                                    <div class="nsa-current-doc-right">
                                        <div class="nsa-current-doc-label">
                                            <?= $aadharIsPdf ? '<i class="fa fa-file-pdf-o" style="margin-right:4px;color:#dc2626;"></i>PDF Document' : 'Image Document' ?>
                                        </div>
                                        <div style="font-size:11px;color:var(--nsa-muted);margin-top:2px;">
                                            Current Aadhar Card on file
                                        </div>
                                        <a href="<?= htmlspecialchars($currentAadharUrl) ?>" target="_blank"
                                           class="nsa-btn nsa-btn-ghost" style="padding:6px 14px;font-size:12px;margin-top:8px;">
                                            <i class="fa fa-<?= $aadharIsPdf ? 'file-pdf-o' : 'eye' ?>"></i>
                                            <?= $aadharIsPdf ? 'Open PDF' : 'View' ?>
                                        </a>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- New Aadhar upload -->
                                <div class="nsa-file-wrap" id="wrap_Aadhar" style="margin-top:10px;">
                                    <input type="file" name="Aadhar" id="aadhar"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="nsaFileChosen(this,'wrap_Aadhar')">
                                    <div class="nsa-file-label">
                                        <div class="nsa-file-icon"><i class="fa fa-id-card"></i></div>
                                        <div class="nsa-file-text">
                                            <strong>Replace Aadhar</strong>
                                            PDF, JPG, PNG · max 2MB
                                        </div>
                                    </div>
                                    <div class="nsa-file-name" id="fn_Aadhar"></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ ACTION BAR ══ -->
                <div class="nsa-action-bar">
                    <button type="button" class="nsa-btn nsa-btn-ghost" onclick="window.history.back()">
                        <i class="fa fa-arrow-left"></i> Cancel
                    </button>
                    <button type="button" id="updateStaffBtn"
                            class="nsa-btn nsa-btn-primary">
                        <i class="fa fa-save"></i> Save Changes
                    </button>
                </div>

            </form>
        </div><!-- /.nsa-main -->
    </div><!-- /.nsa-layout -->

</div><!-- /.nsa-wrap -->
</div><!-- /.content-wrapper -->


<script>

/* ── Toast ── */
function nsaShowAlert(type, message) {
    var toast = document.createElement('div');
    toast.className = 'nsa-toast ' + type;
    var icons = { success:'fa-check-circle', error:'fa-times-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };
    toast.innerHTML = '<i class="fa ' + (icons[type] || 'fa-info-circle') + '"></i> ' + message;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3800);
}

/* ── File chosen indicator ── */
function nsaFileChosen(input, wrapId) {
    var wrap   = document.getElementById(wrapId);
    var nameEl = wrap ? wrap.querySelector('.nsa-file-name') : null;
    if (!nameEl) return;
    if (input.files && input.files.length > 0) {
        nameEl.textContent = '✓ ' + input.files[0].name;
        nameEl.style.display = 'block';
        if (wrap) wrap.style.borderColor = 'var(--nsa-green)';
    } else {
        nameEl.style.display = 'none';
        if (wrap) wrap.style.borderColor = '';
    }
}

/* ── Live photo preview ── */
function previewStaffPhoto(event) {
    var file = event.target.files && event.target.files[0];
    if (!file) return;
    var preview = document.getElementById('staffPhotoPreview');
    if (!preview) return;
    var reader = new FileReader();
    reader.onload = function(e) { preview.src = e.target.result; };
    reader.readAsDataURL(file);
}

/* ── Net Salary live calc ── */
function updateNetSalary() {
    var basic = parseFloat(document.getElementById('basicSalary').value)  || 0;
    var allow = parseFloat(document.getElementById('allowances').value)   || 0;
    var el    = document.getElementById('netSalaryDisplay');
    if (el) el.textContent = '₹ ' + (basic + allow).toLocaleString('en-IN');
}

/* ── Inline validation ── */
function validateEditForm() {
    var isValid = true;

    document.querySelectorAll('.nsa-input.has-error, .nsa-select.has-error').forEach(function(el) {
        el.classList.remove('has-error');
    });
    document.querySelectorAll('.nsa-error-msg').forEach(function(el) { el.remove(); });

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? (el.value || '').trim() : '';
    }

    function showError(id, msg) {
        var el = document.getElementById(id);
        if (!el) { isValid = false; return; }
        el.classList.add('has-error');
        var span = document.createElement('span');
        span.className = 'nsa-error-msg';
        span.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + msg;
        el.parentNode.insertBefore(span, el.nextSibling);
        isValid = false;
    }

    if (!getValue('name'))                   showError('name',                   'Name is required');
    if (!getValue('dob'))                    showError('dob',                    'Date of birth is required');
    // Staff roles auto-assigned from designation — no validation needed
    if (!getValue('father_name'))            showError('father_name',            'Father name is required');
    if (!getValue('street'))                 showError('street',                 'Street is required');
    if (!getValue('city'))                   showError('city',                   'City is required');
    if (!getValue('state'))                  showError('state',                  'State is required');
    if (!getValue('postal_code'))            showError('postal_code',            'Postal code is required');
    if (!getValue('qualification'))          showError('qualification',          'Qualification is required');
    if (!getValue('university'))             showError('university',             'University is required');
    if (!getValue('year_of_passing'))        showError('year_of_passing',        'Year of passing is required');
    if (!getValue('teacher_experience'))     showError('teacher_experience',     'Experience is required');
    if (!getValue('teacher_department'))     showError('teacher_department',     'Department is required');
    if (!getValue('bank_name'))              showError('bank_name',              'Bank name is required');
    if (!getValue('account_holder'))         showError('account_holder',         'Account holder is required');
    if (!getValue('account_number'))         showError('account_number',         'Account number is required');
    if (!getValue('bank_ifsc'))              showError('bank_ifsc',              'IFSC code is required');
    if (!getValue('emergency_contact_name')) showError('emergency_contact_name', 'Emergency contact name is required');

    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(getValue('email_user')))
        showError('email_user', 'Enter a valid email address');

    var phonePattern = /^[6-9]\d{9}$/;
    if (!phonePattern.test(getValue('phone_number')))
        showError('phone_number', 'Enter valid 10-digit mobile number');
    if (getValue('emergency_contact_phone') && !phonePattern.test(getValue('emergency_contact_phone')))
        showError('emergency_contact_phone', 'Enter valid 10-digit mobile number');

    var postalPattern = /^[1-9][0-9]{5}$/;
    if (!postalPattern.test(getValue('postal_code')))
        showError('postal_code', 'Enter valid 6-digit PIN code');

    /* Photo validation — only if new file selected */
    var photoInput = document.getElementById('photo');
    if (photoInput && photoInput.files && photoInput.files.length > 0) {
        var pFile = photoInput.files[0];
        if (!['image/jpeg','image/jpg'].includes(pFile.type))
            showError('photo', 'Only JPG/JPEG allowed for photo');
        if (pFile.size > 2 * 1024 * 1024)
            showError('photo', 'Photo must be under 2MB');
    }

    /* Aadhar validation — only if new file selected */
    var aadharInput = document.getElementById('aadhar');
    if (aadharInput && aadharInput.files && aadharInput.files.length > 0) {
        var aFile = aadharInput.files[0];
        if (!['image/jpeg','image/png','application/pdf','image/jpg'].includes(aFile.type))
            showError('aadhar', 'Only PDF, JPG or PNG allowed for Aadhar');
        if (aFile.size > 2 * 1024 * 1024)
            showError('aadhar', 'Aadhar must be under 2MB');
    }

    if (!isValid) {
        var firstErr = document.querySelector('.has-error, .nsa-error-msg');
        if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return isValid;
}

/* ── DOMContentLoaded ── */
document.addEventListener('DOMContentLoaded', function() {

    /* ── Load departments from HR module ── */
    (function(){
        var sel = document.getElementById('teacher_department');
        if (!sel) return;
        var currentDept = '<?= htmlspecialchars($staff_data['Department'] ?? '', ENT_QUOTES, 'UTF-8') ?>';
        fetch('<?= base_url("hr/get_departments") ?>')
            .then(function(r){ return r.json(); })
            .then(function(r){
                if (r.status !== 'success') return;
                var depts = r.departments || [];
                var found = false;
                depts.forEach(function(d){
                    if ((d.status||'Active') !== 'Active') return;
                    var opt = document.createElement('option');
                    opt.value = d.name;
                    opt.textContent = d.name;
                    if (d.name === currentDept) { opt.selected = true; found = true; }
                    sel.appendChild(opt);
                });
                // If current department doesn't match any HR dept, add it as-is
                if (currentDept && !found) {
                    var opt = document.createElement('option');
                    opt.value = currentDept;
                    opt.textContent = currentDept + ' (not in HR)';
                    opt.selected = true;
                    sel.appendChild(opt);
                }
            })
            .catch(function(){
                // Fallback: add current value as only option
                if (currentDept) {
                    var opt = document.createElement('option');
                    opt.value = currentDept;
                    opt.textContent = currentDept;
                    opt.selected = true;
                    sel.appendChild(opt);
                }
            });
    })();

    /* Net salary calculator */
    var basicInput = document.getElementById('basicSalary');
    var allowInput = document.getElementById('allowances');
    if (basicInput) basicInput.addEventListener('input', updateNetSalary);
    if (allowInput) allowInput.addEventListener('input', updateNetSalary);

    /* Update button */
    var updateBtn = document.getElementById('updateStaffBtn');
    if (updateBtn) {
        updateBtn.addEventListener('click', function() {
            if (!validateEditForm()) return;
            submitEditForm();
        });
    }

    /* ── Staff Role Picker (multi-select chips) ── */
    var _roleData = {};
    var _selectedRoles = (document.getElementById('staffRolesHidden').value || '').split(',').filter(Boolean);
    var _primaryRole = document.getElementById('primaryRoleHidden').value || '';

    function loadStaffRoles() {
        $.getJSON('<?= base_url("staff/get_staff_roles") ?>', function(d) {
            if (d.status !== 'success' || !d.roles) return;
            _roleData = d.roles;
            var sel = document.getElementById('staffRoleSelect');
            sel.innerHTML = '<option value="">+ Add role...</option>';
            Object.keys(_roleData).forEach(function(rid) {
                var r = _roleData[rid];
                var opt = document.createElement('option');
                opt.value = rid;
                opt.textContent = r.label + ' (' + r.category + ')';
                sel.appendChild(opt);
            });
            // Render existing selections
            if (_selectedRoles.length && !_primaryRole) _primaryRole = _selectedRoles[0];
            renderRoleChips();
        });
    }
    loadStaffRoles();

    document.getElementById('staffRoleSelect').addEventListener('change', function() {
        var rid = this.value;
        if (!rid || _selectedRoles.indexOf(rid) !== -1) { this.value = ''; return; }
        _selectedRoles.push(rid);
        if (_selectedRoles.length === 1) _primaryRole = rid;
        renderRoleChips();
        this.value = '';
    });

    function renderRoleChips() {
        var container = document.getElementById('selectedRolesChips');
        container.innerHTML = '';
        _selectedRoles.forEach(function(rid) {
            var r = _roleData[rid] || { label: rid, category: '?' };
            var isPrimary = rid === _primaryRole;
            var chip = document.createElement('span');
            chip.className = 'nsa-role-chip' + (isPrimary ? ' nsa-role-primary' : '');
            chip.innerHTML = (isPrimary ? '<i class="fa fa-star" style="font-size:10px;margin-right:3px;"></i>' : '')
                + r.label
                + '<button type="button" class="nsa-role-remove" data-rid="' + rid + '">&times;</button>';
            chip.title = isPrimary ? 'Primary role' : 'Click to set as primary';
            chip.setAttribute('data-rid', rid);
            chip.addEventListener('click', function(e) {
                if (e.target.classList.contains('nsa-role-remove')) return;
                _primaryRole = rid;
                renderRoleChips();
            });
            container.appendChild(chip);
        });
        container.querySelectorAll('.nsa-role-remove').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var rid = this.getAttribute('data-rid');
                _selectedRoles = _selectedRoles.filter(function(r) { return r !== rid; });
                if (_primaryRole === rid) _primaryRole = _selectedRoles[0] || '';
                renderRoleChips();
            });
        });
        document.getElementById('staffRolesHidden').value = _selectedRoles.join(',');
        document.getElementById('primaryRoleHidden').value = _primaryRole;
    }

    /* Sidebar active scroll highlight */
    var sections = document.querySelectorAll('.nsa-section');
    var navItems = document.querySelectorAll('.nsa-nav-item');
    window.addEventListener('scroll', function() {
        var scrollY = window.scrollY + 120;
        sections.forEach(function(sec) {
            if (sec.offsetTop <= scrollY && sec.offsetTop + sec.offsetHeight > scrollY) {
                navItems.forEach(function(n) { n.classList.remove('active'); });
                var match = document.querySelector('.nsa-nav-item[href="#' + sec.id + '"]');
                if (match) match.classList.add('active');
            }
        });
    });
});

/* ── AJAX submit ── */
function submitEditForm() {
    var form = document.getElementById('edit_staff_form');
    if (!form) { nsaShowAlert('error', 'Form not found.'); return; }

    var btn = document.getElementById('updateStaffBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';
    }

    var formData = new FormData(form);

    $.ajax({
        url:         form.action,
        type:        'POST',
        data:        formData,
        processData: false,
        contentType: false,
        success: function(response) {
            var res;
            try { res = typeof response === 'string' ? JSON.parse(response) : response; }
            catch(e) {
                nsaShowAlert('error', 'Unexpected server response.');
                resetBtn();
                return;
            }
            if (res.status === 'success') {
                nsaShowAlert('success', res.message || 'Staff updated successfully!');
                setTimeout(function() {
                    window.location.href = '<?= base_url("staff/all_staff") ?>';
                }, 1600);
            } else {
                nsaShowAlert('error', res.message || 'Update failed. Please try again.');
                resetBtn();
            }
        },
        error: function() {
            nsaShowAlert('error', 'Server error — please try again.');
            resetBtn();
        }
    });

    function resetBtn() {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-save"></i> Save Changes';
        }
    }
}
</script>


<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Lora:wght@500;600;700&display=swap');

:root {
    --nsa-navy:   #0c1e38;
    --nsa-green:  #0d7a5f;
    --nsa-teal:   #0f766e;
    --nsa-sky:    #e6f4f1;
    --nsa-dark:   #15803d;
    --nsa-red:    #dc2626;
    --nsa-amber:  #d97706;
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
.nsa-topbar { margin-bottom: 28px; }
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

/* ── Layout ── */
.nsa-layout {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 900px) {
    .nsa-layout { grid-template-columns: 1fr; }
    .nsa-sidebar { display: none; }
}

/* ── Sidebar ── */
.nsa-sidebar {
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: var(--nsa-shadow);
    overflow: hidden;
    position: sticky; top: 16px;
}
.nsa-sidebar-title {
    padding: 14px 16px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .7px;
    color: var(--nsa-muted);
    border-bottom: 1px solid var(--nsa-border);
    background: var(--nsa-bg);
}
.nsa-nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px;
    font-size: 13px; font-weight: 500;
    color: var(--nsa-muted);
    border-bottom: 1px solid var(--nsa-border);
    text-decoration: none;
    transition: background .12s, color .12s;
}
.nsa-nav-item:last-child { border-bottom: none; }
.nsa-nav-item i { width: 16px; text-align: center; color: #94a3b8; }
.nsa-nav-item:hover { background: var(--nsa-sky); color: var(--nsa-teal); }
.nsa-nav-item:hover i { color: var(--nsa-teal); }
.nsa-nav-item.active { background: var(--nsa-sky); color: var(--nsa-teal); font-weight: 600; border-left: 3px solid var(--nsa-teal); }
.nsa-nav-item.active i { color: var(--nsa-teal); }

/* ── Main ── */
.nsa-main { display: flex; flex-direction: column; gap: 18px; }

/* ── Section card ── */
.nsa-section {
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: var(--nsa-shadow);
    overflow: hidden;
}
.nsa-section-head {
    padding: 15px 22px;
    border-bottom: 1px solid var(--nsa-border);
    display: flex; align-items: center; gap: 10px;
    background: linear-gradient(90deg, var(--nsa-sky) 0%, var(--nsa-white) 100%);
}
.nsa-section-head i { color: var(--nsa-teal); font-size: 15px; }
.nsa-section-head h3 {
    margin: 0;
    font-family: 'Lora', serif;
    font-size: 15px; font-weight: 600;
    color: var(--nsa-navy);
}
.nsa-section-body { padding: 22px 22px 14px; }

/* ── Grids ── */
.nsa-grid   { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px 20px; }
.nsa-grid-3 { grid-template-columns: repeat(3,1fr); }
.nsa-grid-2 { grid-template-columns: repeat(2,1fr); }
.nsa-col-2  { grid-column: span 2; }
@media (max-width:1100px){ .nsa-grid   { grid-template-columns: repeat(3,1fr); } }
@media (max-width:768px) { .nsa-grid,.nsa-grid-3,.nsa-grid-2 { grid-template-columns: repeat(2,1fr); } .nsa-col-2 { grid-column: span 2; } }
@media (max-width:480px) { .nsa-grid,.nsa-grid-3,.nsa-grid-2 { grid-template-columns: 1fr; } .nsa-col-2 { grid-column: span 1; } }

/* ── Fields ── */
.nsa-field { display: flex; flex-direction: column; gap: 5px; }
.nsa-field label {
    font-size: 12px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px;
    color: var(--nsa-muted);
}
.nsa-field label .req { color: var(--nsa-red); margin-left: 2px; }
.nsa-optional-tag {
    font-size: 10px; font-weight: 400;
    text-transform: none; letter-spacing: 0;
    color: var(--nsa-amber); margin-left: 6px;
    background: #fef3c7; padding: 1px 6px;
    border-radius: 20px; vertical-align: middle;
}

.nsa-input, .nsa-select {
    height: 40px;
    padding: 0 12px;
    border: 1.5px solid var(--nsa-border);
    border-radius: 8px;
    font-size: 13.5px;
    color: var(--nsa-text);
    background: #fafbff;
    outline: none;
    transition: border-color .14s, box-shadow .14s;
    font-family: 'Instrument Sans', sans-serif;
    width: 100%;
}
.nsa-input:focus, .nsa-select:focus {
    border-color: var(--nsa-teal);
    box-shadow: 0 0 0 3px rgba(15,118,110,.1);
    background: #fff;
}
.nsa-input[readonly] { background: #f1f5f9; color: var(--nsa-muted); cursor: not-allowed; }
.nsa-input.has-error, .nsa-select.has-error { border-color: var(--nsa-red); }
.nsa-error-msg {
    font-size: 11.5px; color: var(--nsa-red);
    display: flex; align-items: center; gap: 4px; margin-top: 2px;
}
.nsa-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 11px center;
    background-color: #fafbff; padding-right: 30px;
}

/* ── Net salary ── */
.nsa-net-salary-box {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 18px;
    background: linear-gradient(135deg, var(--nsa-sky) 0%, #d1fae5 100%);
    border: 1.5px solid rgba(15,118,110,.25);
    border-radius: 10px;
}
.nsa-net-label { font-size: 13px; font-weight: 600; color: var(--nsa-teal); display:flex; align-items:center; gap:8px; }
.nsa-net-value { font-size: 20px; font-weight: 700; color: var(--nsa-teal); font-family: 'Lora', serif; }

/* ── File input ── */
.nsa-file-wrap {
    position: relative;
    border: 1.5px dashed var(--nsa-border);
    border-radius: 8px; padding: 12px 14px;
    background: #fafbff; cursor: pointer;
    transition: border-color .14s;
}
.nsa-file-wrap:hover { border-color: var(--nsa-teal); }
.nsa-file-wrap input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.nsa-file-label { display: flex; align-items: center; gap: 10px; pointer-events: none; }
.nsa-file-icon {
    width: 34px; height: 34px; border-radius: 7px;
    background: var(--nsa-sky);
    display: flex; align-items: center; justify-content: center;
    color: var(--nsa-teal); font-size: 14px; flex-shrink: 0;
}
.nsa-file-text { font-size: 12.5px; color: var(--nsa-muted); }
.nsa-file-text strong { display: block; font-size: 13px; color: var(--nsa-text); font-weight: 600; }
.nsa-file-name {
    font-size: 11.5px; color: var(--nsa-dark); font-weight: 600;
    margin-top: 4px; display: none;
}

/* ── Photo upload ── */
.nsa-photo-wrap { display: flex; gap: 18px; align-items: flex-start; }
.nsa-photo-preview-box {
    width: 110px; flex-shrink: 0;
    display: flex; flex-direction: column; align-items: center; gap: 6px;
}
.nsa-photo-preview-box img {
    width: 100px; height: 120px; object-fit: cover;
    border-radius: 8px; border: 2px solid var(--nsa-border);
    box-shadow: var(--nsa-shadow);
}
.nsa-photo-hint { font-size: 11px; color: var(--nsa-muted); text-align: center; }
.nsa-current-link {
    font-size: 11px; color: var(--nsa-teal); text-decoration: none;
    display: flex; align-items: center; gap: 4px; margin-top: 4px;
}
.nsa-current-link:hover { text-decoration: underline; }
.nsa-photo-file { flex: 1; }

/* ── Current doc card ── */
.nsa-current-doc-card {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px;
    background: #f0fdf8; border: 1.5px solid rgba(15,118,110,.2);
    border-radius: 8px; margin-bottom: 4px;
}
.nsa-doc-thumb {
    width: 52px; height: 60px; object-fit: cover;
    border-radius: 6px; border: 1px solid var(--nsa-border);
    flex-shrink: 0;
}
.nsa-doc-thumb-placeholder {
    width: 52px; height: 60px; border-radius: 6px;
    background: var(--nsa-sky);
    display: flex; align-items: center; justify-content: center;
    color: var(--nsa-teal); font-size: 22px; flex-shrink: 0;
}

/* PDF-specific placeholder — red tint to signal it's a PDF */
.nsa-doc-pdf-placeholder {
    background: #fff1f1;
    color: #dc2626;
    flex-direction: column;
    gap: 2px;
    font-size: 18px;
}
.nsa-doc-pdf-placeholder span {
    font-size: 9px; font-weight: 700;
    letter-spacing: .5px; color: #dc2626;
    font-family: 'Instrument Sans', sans-serif;
}
.nsa-current-doc-label {
    font-size: 12px; font-weight: 600;
    color: var(--nsa-teal);
    text-transform: uppercase; letter-spacing: .4px;
}

/* ── Action bar ── */
.nsa-action-bar {
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: var(--nsa-shadow);
    padding: 16px 22px;
    display: flex; align-items: center; justify-content: flex-end; gap: 12px;
}

/* ── Buttons ── */
.nsa-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 22px; border-radius: 9px;
    font-size: 13.5px; font-weight: 600;
    cursor: pointer; border: none; text-decoration: none;
    transition: opacity .13s, transform .1s;
    font-family: 'Instrument Sans', sans-serif;
    white-space: nowrap;
}
.nsa-btn:hover { opacity: .86; transform: translateY(-1px); }
.nsa-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.nsa-btn-primary { background: var(--nsa-teal); color: #fff; box-shadow: 0 2px 10px rgba(15,118,110,.28); }
.nsa-btn-ghost   { background: var(--nsa-white); color: var(--nsa-text); border: 1.5px solid var(--nsa-border); }
.nsa-btn-ghost:hover { border-color: var(--nsa-teal); color: var(--nsa-teal); }

/* ── Toast ── */
.nsa-toast {
    position: fixed; top: 20px; right: 20px;
    padding: 12px 18px; border-radius: 10px;
    font-size: 13.5px; font-weight: 500;
    color: #fff; z-index: 99999;
    box-shadow: 0 4px 14px rgba(0,0,0,.18);
    display: flex; align-items: center; gap: 9px;
    animation: nsa-toast-in .25s ease; max-width: 340px;
}
@keyframes nsa-toast-in {
    from { transform: translateX(20px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}
.nsa-toast.success { background: var(--nsa-dark); }
.nsa-toast.error   { background: var(--nsa-red);  }
.nsa-toast.warning { background: var(--nsa-amber);}

/* ── Staff Role Picker ── */
.nsa-field-full { grid-column: 1 / -1; }
.nsa-roles-picker {
    display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
    padding: 8px 10px; border: 1.5px solid var(--nsa-border, #d1d5db); border-radius: 8px;
    background: var(--nsa-white, #fff); min-height: 44px;
}
.nsa-roles-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.nsa-role-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 16px; font-size: 12px; font-weight: 600;
    background: var(--nsa-sky, #e6f4f1); color: var(--nsa-teal, #0f766e);
    border: 1px solid rgba(15,118,110,.2); cursor: pointer; transition: all .15s;
}
.nsa-role-chip:hover { background: rgba(15,118,110,.15); }
.nsa-role-chip.nsa-role-primary {
    background: var(--nsa-teal, #0f766e); color: #fff;
    border-color: var(--nsa-teal, #0f766e);
}
.nsa-role-remove {
    background: none; border: none; color: inherit; font-size: 14px; font-weight: 700;
    cursor: pointer; padding: 0 2px; line-height: 1; opacity: .7;
}
.nsa-role-remove:hover { opacity: 1; }
</style>