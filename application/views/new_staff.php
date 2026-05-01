<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="nsa-wrap">

    <!-- ── Top bar ── -->
    <div class="nsa-topbar">
        <h1 class="nsa-page-title">
            <i class="fa fa-id-badge"></i> Add New Staff
        </h1>
        <ol class="nsa-breadcrumb">
            <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
            <li><a href="<?= base_url('staff/all_staff') ?>">All Staff</a></li>
            <li>Add Staff</li>
        </ol>
    </div>

    <!-- ── Layout ── -->
    <div class="nsa-layout">

        <!-- Sidebar nav -->
        <aside class="nsa-sidebar">
            <div class="nsa-sidebar-title">Form Sections</div>
            <a class="nsa-nav-item active" href="#sec-basic"><i class="fa fa-user"></i> <span>Basic Info</span></a>
            <a class="nsa-nav-item" href="#sec-job"><i class="fa fa-briefcase"></i> <span>Job Details</span></a>
            <a class="nsa-nav-item" href="#sec-guardian"><i class="fa fa-users"></i> <span>Guardian</span></a>
            <a class="nsa-nav-item" href="#sec-address"><i class="fa fa-map-marker"></i> <span>Address</span></a>
            <a class="nsa-nav-item" href="#sec-qualification"><i class="fa fa-graduation-cap"></i> <span>Qualification</span></a>
            <a class="nsa-nav-item" href="#sec-bank"><i class="fa fa-university"></i> <span>Bank Details</span></a>
            <a class="nsa-nav-item" href="#sec-salary"><i class="fa fa-money"></i> <span>Salary</span></a>
            <a class="nsa-nav-item" href="#sec-docs"><i class="fa fa-file-text-o"></i> <span>Documents</span></a>
        </aside>

        <!-- Main form -->
        <div class="nsa-main">
            <form action="<?= base_url('staff/new_staff') ?>"
                  method="post"
                  id="add_staff_form"
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
                                       value="STA<?= str_pad($staffIdCount, 4, '0', STR_PAD_LEFT) ?>"
                                       class="nsa-input" readonly>
                            </div>

                            <div class="nsa-field">
                                <label>Staff Name <span class="req">*</span></label>
                                <input type="text" id="name" name="Name"
                                       class="nsa-input" placeholder="Full name" required>
                            </div>

                            <div class="nsa-field">
                                <label>Date of Birth <span class="req">*</span></label>
                                <input type="date" id="dob" name="dob" class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label>Email <span class="req">*</span></label>
                                <input type="email" id="email_user" name="email"
                                       class="nsa-input" placeholder="staff@email.com" required>
                            </div>

                            <div class="nsa-field">
                                <label>Gender <span class="req">*</span></label>
                                <select id="gender" name="gender" class="nsa-select" required>
                                    <option value="">Select Gender</option>
                                    <option>Male</option>
                                    <option>Female</option>
                                    <option>Other</option>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label>Blood Group</label>
                                <select id="blood_group" name="blood_group" class="nsa-select">
                                    <option value="">Select</option>
                                    <option>A+</option><option>A-</option>
                                    <option>B+</option><option>B-</option>
                                    <option>O+</option><option>O-</option>
                                    <option>AB+</option><option>AB-</option>
                                    <option>Unknown</option>
                                </select>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ JOB DETAILS — single place for role, designation, department, joining ══ -->
                <div class="nsa-section" id="sec-job">
                    <div class="nsa-section-head">
                        <i class="fa fa-briefcase"></i>
                        <h3>Job Details</h3>
                        <span style="font-size:12px;color:var(--nsa-muted);margin-left:auto;">Role &amp; designation</span>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <input type="hidden" name="staff_roles" id="staffRolesHidden" value="" required>
                            <input type="hidden" name="primary_role" id="primaryRoleHidden" value="">

                            <div class="nsa-field nsa-col-2">
                                <label>Staff Role <span class="req">*</span></label>
                                <select id="staffRoleSelect" class="nsa-select">
                                    <option value="">+ Add role (Teacher, Accountant, Librarian, etc.)...</option>
                                </select>
                                <div id="selectedRolesChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>
                                <small style="color:var(--nsa-muted);font-size:11px;">
                                    <i class="fa fa-info-circle"></i>
                                    Pick one or more roles. The first role becomes the primary designation. Multi-role example: a teacher who also runs the library.
                                </small>
                            </div>

                            <div class="nsa-field">
                                <label>Department <span class="req">*</span></label>
                                <select id="teacher_department" name="department" class="nsa-input" required>
                                    <option value="">-- Select Department --</option>
                                </select>
                                <small style="color:var(--nsa-muted);font-size:11px;">Configure in HR → Departments first.</small>
                            </div>

                            <div class="nsa-field">
                                <label>Employment Type <span class="req">*</span></label>
                                <input type="text" id="employment_type" name="employment_type"
                                       class="nsa-input" placeholder="e.g. Full-time" required>
                            </div>

                            <div class="nsa-field">
                                <label>Date of Joining <span class="req">*</span></label>
                                <input type="date" id="date_of_joining" name="date_of_joining"
                                       value="<?= date('Y-m-d') ?>"
                                       class="nsa-input" readonly required>
                            </div>

                            <div class="nsa-field">
                                <label>Work Experience (Years)</label>
                                <input type="text" id="teacher_experience" name="teacher_experience"
                                       class="nsa-input" placeholder="e.g. 5">
                            </div>

                            <!-- Teacher capability — shown only when Position=Teacher or ROLE_TEACHER -->
                            <div id="teacherExtraFields" class="nsa-col-2" style="display:none;">
                                <div class="nsa-field">
                                    <label>Subjects this teacher can teach <span class="req">*</span></label>
                                    <input type="hidden" name="teaching_subjects" id="teachingSubjectsHidden" value="">
                                    <select id="subjectPicker" class="nsa-input">
                                        <option value="">+ Add subject...</option>
                                    </select>
                                    <div id="subjectChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>
                                    <small style="color:var(--nsa-muted);font-size:11px;">
                                        <i class="fa fa-info-circle"></i>
                                        Capability list only. Actual class &amp; section assignments are done in <strong>Academic Planner → Subject Assignments</strong>.
                                    </small>
                                </div>
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
                                       class="nsa-input" placeholder="Father's full name" required>
                            </div>

                            <div class="nsa-field">
                                <label>Phone Number <span class="req">*</span></label>
                                <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="phone_number" name="phone_number"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10" required></div>
                            </div>

                            <div class="nsa-field">
                                <label>Alternate Phone</label>
                                <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="alt_phone" name="alt_phone"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10"></div>
                            </div>

                            <div class="nsa-field">
                                <label>Marital Status</label>
                                <select id="marital_status" name="marital_status" class="nsa-select">
                                    <option value="">Select</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Divorced">Divorced</option>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label>Emergency Contact Name</label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                                       class="nsa-input" placeholder="Full name">
                            </div>

                            <div class="nsa-field">
                                <label>Emergency Contact Number</label>
                                <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="emergency_contact_phone" name="emergency_contact_phone"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10"></div>
                            </div>

                            <div class="nsa-field">
                                <label>Emergency Contact Relation</label>
                                <select id="emergency_contact_relation" name="emergency_contact_relation" class="nsa-select">
                                    <option value="">Select</option>
                                    <option value="Father">Father</option>
                                    <option value="Mother">Mother</option>
                                    <option value="Spouse">Spouse</option>
                                    <option value="Sibling">Sibling</option>
                                    <option value="Friend">Friend</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label>Designation</label>
                                <input type="text" id="designation" name="designation"
                                       class="nsa-input" placeholder="e.g. Senior Teacher, HOD">
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ STATUTORY IDs ══ -->
                <div class="nsa-section" id="sec-statutory">
                    <div class="nsa-section-head">
                        <i class="fa fa-id-card-o"></i>
                        <h3>Statutory Identification</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label>PAN Number</label>
                                <input type="text" id="pan_number" name="pan_number"
                                       class="nsa-input" placeholder="ABCDE1234F"
                                       pattern="[A-Z]{5}[0-9]{4}[A-Z]" maxlength="10"
                                       style="text-transform:uppercase">
                            </div>

                            <div class="nsa-field">
                                <label>Aadhar Number</label>
                                <input type="text" id="aadhar_number" name="aadhar_number"
                                       class="nsa-input" placeholder="12-digit number"
                                       pattern="[0-9]{12}" maxlength="12">
                            </div>

                            <div class="nsa-field">
                                <label>PF Number (UAN)</label>
                                <input type="text" id="pf_number" name="pf_number"
                                       class="nsa-input" placeholder="UAN or PF Account No.">
                            </div>

                            <div class="nsa-field">
                                <label>ESI Number</label>
                                <input type="text" id="esi_number" name="esi_number"
                                       class="nsa-input" placeholder="ESI IP Number">
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
                                <label>Street</label>
                                <input type="text" id="street" name="street"
                                       class="nsa-input" placeholder="House no., Street name">
                            </div>

                            <div class="nsa-field">
                                <label>City</label>
                                <input type="text" id="city" name="city"
                                       class="nsa-input" placeholder="City / District">
                            </div>

                            <div class="nsa-field">
                                <label>State</label>
                                <input type="text" id="state" name="state"
                                       class="nsa-input" placeholder="State">
                            </div>

                            <div class="nsa-field">
                                <label>Postal Code</label>
                                <input type="text" id="postal_code" name="postal_code"
                                       class="nsa-input" placeholder="6-digit PIN">
                            </div>

                        </div>

                        <!-- Permanent Address -->
                        <div style="margin-top:16px">
                            <label class="nsa-checkbox-label" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--t2)">
                                <input type="checkbox" id="same_as_current" name="same_as_current" value="1"
                                       style="width:16px;height:16px;accent-color:var(--gold)">
                                Permanent address same as current address
                            </label>
                        </div>

                        <div id="permanent_address_block" style="margin-top:12px">
                            <h4 style="font-size:13px;color:var(--t2);margin-bottom:8px;font-weight:600">Permanent Address</h4>
                            <div class="nsa-grid nsa-grid-2">
                                <div class="nsa-field nsa-col-2">
                                    <label>Street</label>
                                    <input type="text" id="perm_street" name="perm_street"
                                           class="nsa-input" placeholder="House no., Street name">
                                </div>
                                <div class="nsa-field">
                                    <label>City</label>
                                    <input type="text" id="perm_city" name="perm_city"
                                           class="nsa-input" placeholder="City / District">
                                </div>
                                <div class="nsa-field">
                                    <label>State</label>
                                    <input type="text" id="perm_state" name="perm_state"
                                           class="nsa-input" placeholder="State">
                                </div>
                                <div class="nsa-field">
                                    <label>Postal Code</label>
                                    <input type="text" id="perm_postal_code" name="perm_postal_code"
                                           class="nsa-input" placeholder="6-digit PIN">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ══ QUALIFICATION ══ -->
                <div class="nsa-section" id="sec-qualification">
                    <div class="nsa-section-head">
                        <i class="fa fa-graduation-cap"></i>
                        <h3>Qualification &amp; Education</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-3">

                            <div class="nsa-field">
                                <label>Highest Qualification</label>
                                <input type="text" id="qualification" name="qualification"
                                       class="nsa-input" placeholder="e.g. B.Ed, M.Sc">
                            </div>

                            <div class="nsa-field">
                                <label>University</label>
                                <input type="text" id="university" name="university"
                                       class="nsa-input" placeholder="University name">
                            </div>

                            <div class="nsa-field">
                                <label>Year of Passing</label>
                                <input type="text" id="year_of_passing" name="year_of_passing"
                                       class="nsa-input" placeholder="e.g. 2018">
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ BANK DETAILS ══ -->
                <div class="nsa-section" id="sec-bank">
                    <div class="nsa-section-head">
                        <i class="fa fa-university"></i>
                        <h3>Bank Details</h3>
                        <span style="font-size:12px;color:var(--t3);margin-left:auto;">Required before payroll</span>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label>Bank Name</label>
                                <input type="text" id="bank_name" name="bank_name"
                                       class="nsa-input" placeholder="e.g. State Bank of India">
                            </div>

                            <div class="nsa-field">
                                <label>Account Holder Name</label>
                                <input type="text" id="account_holder" name="account_holder"
                                       class="nsa-input" placeholder="Name as on passbook">
                            </div>

                            <div class="nsa-field">
                                <label>Account Number</label>
                                <input type="text" id="account_number" name="account_number"
                                       class="nsa-input" placeholder="Bank account number">
                            </div>

                            <div class="nsa-field">
                                <label>IFSC Code</label>
                                <input type="text" id="bank_ifsc" name="bank_ifsc"
                                       class="nsa-input" placeholder="e.g. SBIN0001234">
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
                                       class="nsa-input" placeholder="e.g. 30000" min="0" required>
                            </div>

                            <div class="nsa-field">
                                <label>Allowances (₹)</label>
                                <input type="number" id="allowances" name="allowances"
                                       class="nsa-input" placeholder="e.g. 5000" min="0" value="0">
                            </div>

                            <!-- Net Salary Display -->
                            <div class="nsa-field nsa-col-2">
                                <div class="nsa-net-salary-box">
                                    <span class="nsa-net-label"><i class="fa fa-calculator"></i> Net Salary</span>
                                    <span class="nsa-net-value" id="netSalaryDisplay">₹ 0</span>
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
                                <label>Staff Photo</label>
                                <div class="nsa-photo-wrap">
                                    <div class="nsa-photo-preview-box">
                                        <img id="staffPhotoPreview"
                                             src="<?= base_url('tools/dist/img/kids.jpg') ?>"
                                             alt="Preview">
                                        <span class="nsa-photo-hint">JPG / JPEG only</span>
                                    </div>
                                    <div class="nsa-photo-file">
                                        <div class="nsa-file-wrap" id="wrap_Photo">
                                            <input type="file" name="Photo" id="photo"
                                                   accept="image/jpeg,image/jpg"
                                                   onchange="previewStaffPhoto(event);nsaFileChosen(this,'wrap_Photo')">
                                            <div class="nsa-file-label">
                                                <div class="nsa-file-icon"><i class="fa fa-camera"></i></div>
                                                <div class="nsa-file-text">
                                                    <strong>Upload Photo</strong>
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
                                <label>Aadhar Card</label>
                                <div class="nsa-file-wrap" id="wrap_Aadhar" style="margin-top:4px;">
                                    <input type="file" name="Aadhar" id="aadhar"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="nsaFileChosen(this,'wrap_Aadhar')">
                                    <div class="nsa-file-label">
                                        <div class="nsa-file-icon"><i class="fa fa-id-card"></i></div>
                                        <div class="nsa-file-text">
                                            <strong>Upload Aadhar</strong>
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
                    <button type="reset" class="nsa-btn nsa-btn-ghost" onclick="resetPhotoPreview()">
                        <i class="fa fa-refresh"></i> Reset
                    </button>
                    <button type="button" id="preview_button"
                            class="nsa-btn nsa-btn-primary">
                        <i class="fa fa-eye"></i> Preview &amp; Submit
                    </button>
                </div>

            </form>
        </div><!-- /.nsa-main -->
    </div><!-- /.nsa-layout -->

</div><!-- /.nsa-wrap -->
</div><!-- /.content-wrapper -->


<!-- ══════════════════════════════════════════════
     STAFF PREVIEW MODAL
══════════════════════════════════════════════ -->
<div class="nsa-overlay" id="staffPreviewModalOverlay">
<div class="nsa-modal" id="staffPreviewModal">

    <div class="nsa-modal-head">
        <div>
            <h3><i class="fa fa-check-circle" style="margin-right:8px;color:#4ade80;"></i>Review Before Submitting</h3>
            <p>Please verify all details carefully before final submission.</p>
        </div>
        <button class="nsa-modal-x" onclick="closeStaffPreviewModal()">&times;</button>
    </div>

    <!-- Hero strip -->
    <div class="nsa-preview-hero">
        <img id="previewStaffPhoto" src="" alt="Staff Photo">
        <div class="nsa-preview-hero-info">
            <h2 id="previewStaffName"></h2>
            <div style="font-size:13px;color:var(--nsa-muted);">Staff ID: <strong id="previewStaffId" style="color:var(--nsa-navy);"></strong></div>
            <div class="nsa-preview-hero-badges">
                <span class="nsa-prev-badge" id="previewPosition"></span>
                <span class="nsa-prev-badge" id="previewDepartment"></span>
                <span class="nsa-prev-badge" id="previewJoiningDate"></span>
            </div>
        </div>
    </div>

    <div class="nsa-modal-body">

        <!-- Basic Info -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-user"></i> Basic Information</div>
            <div class="nsa-prev-grid">
                <div class="nsa-prev-field"><div class="lbl">DOB</div><div class="val" id="previewDob"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Gender</div><div class="val" id="previewGender"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Blood Group</div><div class="val" id="previewBloodGroup"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Email</div><div class="val" id="previewEmail"></div></div>
            </div>
        </div>

        <!-- Contact -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-phone"></i> Contact &amp; Guardian Details</div>
            <div class="nsa-prev-grid">
                <div class="nsa-prev-field"><div class="lbl">Father Name</div><div class="val" id="previewFatherName"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Phone</div><div class="val" id="previewPhone"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Alt. Phone</div><div class="val" id="previewAltPhone"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Marital Status</div><div class="val" id="previewMaritalStatus"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Emergency Name</div><div class="val" id="previewEmergencyName"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Emergency Phone</div><div class="val" id="previewEmergencyPhone"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Emergency Relation</div><div class="val" id="previewEmergencyRelation"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Designation</div><div class="val" id="previewDesignation"></div></div>
            </div>
        </div>

        <!-- Statutory IDs -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-id-card-o"></i> Statutory Identification</div>
            <div class="nsa-prev-grid">
                <div class="nsa-prev-field"><div class="lbl">PAN</div><div class="val" id="previewPan"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Aadhar</div><div class="val" id="previewAadharNum"></div></div>
                <div class="nsa-prev-field"><div class="lbl">PF (UAN)</div><div class="val" id="previewPf"></div></div>
                <div class="nsa-prev-field"><div class="lbl">ESI</div><div class="val" id="previewEsi"></div></div>
            </div>
        </div>

        <!-- Address -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-map-marker"></i> Address</div>
            <div class="nsa-prev-grid">
                <div class="nsa-prev-field" style="grid-column:span 3;">
                    <div class="lbl">Current Address</div>
                    <div class="val">
                        <span id="previewStreet"></span>,
                        <span id="previewCity"></span>,
                        <span id="previewState"></span> – <span id="previewPostalCode"></span>
                    </div>
                </div>
                <div class="nsa-prev-field" style="grid-column:span 3;">
                    <div class="lbl">Permanent Address</div>
                    <div class="val" id="previewPermAddress"></div>
                </div>
            </div>
        </div>

        <!-- Qualification -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-graduation-cap"></i> Qualification Details</div>
            <div class="nsa-prev-grid">
                <div class="nsa-prev-field"><div class="lbl">Employment Type</div><div class="val" id="previewEmploymentType"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Qualification</div><div class="val" id="previewQualification"></div></div>
                <div class="nsa-prev-field"><div class="lbl">University</div><div class="val" id="previewUniversity"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Year of Passing</div><div class="val" id="previewYearOfPassing"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Experience</div><div class="val" id="previewExperience"></div></div>
            </div>
        </div>

        <!-- Bank -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-university"></i> Bank Details</div>
            <div class="nsa-prev-grid">
                <div class="nsa-prev-field"><div class="lbl">Bank Name</div><div class="val" id="previewBankName"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Account Holder</div><div class="val" id="previewAccountHolder"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Account No.</div><div class="val" id="previewAccountNumber"></div></div>
                <div class="nsa-prev-field"><div class="lbl">IFSC</div><div class="val" id="previewIfsc"></div></div>
            </div>
        </div>

        <!-- Salary -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-money"></i> Salary Details</div>
            <div class="nsa-prev-grid">
                <div class="nsa-prev-field"><div class="lbl">Basic Salary</div><div class="val" id="previewBasicSalary"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Allowances</div><div class="val" id="previewAllowances"></div></div>
                <div class="nsa-prev-field"><div class="lbl">Net Salary</div><div class="val" id="previewNetSalary" style="font-weight:700;color:var(--nsa-green);"></div></div>
            </div>
        </div>

        <!-- Documents -->
        <div class="nsa-prev-section">
            <div class="nsa-prev-section-title"><i class="fa fa-file-text-o"></i> Uploaded Documents</div>
            <div class="nsa-prev-docs">
                <div class="nsa-prev-doc-item">
                    <i class="fa fa-camera"></i>
                    <span id="previewPhotoName">Staff Photo</span>
                    <a href="#" target="_blank" id="previewPhotoView" style="display:none;"><i class="fa fa-eye"></i> View</a>
                </div>
                <div class="nsa-prev-doc-item">
                    <i class="fa fa-id-card"></i>
                    <span id="previewAadharName">Aadhar Card</span>
                    <a href="#" target="_blank" id="previewAadharView" style="display:none;"><i class="fa fa-eye"></i> View</a>
                </div>
            </div>
        </div>

    </div>

    <div class="nsa-modal-foot">
        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closeStaffPreviewModal()">
            <i class="fa fa-edit"></i> Edit
        </button>
        <button type="button" class="nsa-btn nsa-btn-success" onclick="submitStaffFinalForm()" id="confirmSubmitBtn">
            <i class="fa fa-check"></i> Final Submit
        </button>
    </div>

</div>
</div>


<script>


/* ── Toast notifications ── */
function nsaShowAlert(type, message) {
    var toast = document.createElement('div');
    toast.className = 'nsa-toast ' + type;
    var icons = { success:'fa-check-circle', error:'fa-times-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };
    toast.innerHTML = '<i class="fa ' + (icons[type]||'fa-info-circle') + '"></i> ' + message;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3400);
}

/* ── File chosen feedback ── */
function nsaFileChosen(input, wrapId) {
    var wrap = document.getElementById(wrapId);
    var nameEl = wrap ? wrap.querySelector('.nsa-file-name') : null;
    if (!nameEl) return;
    if (input.files && input.files.length > 0) {
        nameEl.innerHTML = '';
        var ok = document.createElement('span');
        ok.textContent = '✓ ' + input.files[0].name;
        nameEl.appendChild(ok);
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'nsa-file-remove';
        rm.title = 'Remove file';
        rm.innerHTML = '&times; Remove';
        rm.style.cssText = 'margin-left:10px;background:none;border:none;color:var(--nsa-red,#dc2626);cursor:pointer;font-size:12px;font-weight:600;padding:0;';
        rm.addEventListener('click', function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
            nsaClearFile(input.id, wrapId);
        });
        nameEl.appendChild(rm);
        nameEl.style.display = 'block';
        if (wrap) wrap.style.borderColor = 'var(--nsa-green)';
    } else {
        nameEl.innerHTML = '';
        nameEl.style.display = 'none';
        if (wrap) wrap.style.borderColor = '';
    }
}

/* ── Clear a chosen file (used by per-input remove buttons) ── */
function nsaClearFile(inputId, wrapId) {
    var input = document.getElementById(inputId);
    if (!input) return;
    input.value = '';                      // reset the file input
    nsaFileChosen(input, wrapId);          // re-render (now empty)
    if (inputId === 'photo') {
        var preview = document.getElementById('staffPhotoPreview');
        if (preview) preview.src = '<?= base_url('tools/dist/img/kids.jpg') ?>';
    }
}

/* ── Preview staff photo ── */
function previewStaffPhoto(event) {
    var file = event.target.files && event.target.files[0];
    if (!file) return;
    var preview = document.getElementById('staffPhotoPreview');
    if (!preview) return;
    var reader = new FileReader();
    reader.onload = function(e) { preview.src = e.target.result; };
    reader.readAsDataURL(file);
}

/* ── Reset photo preview on form reset ── */
function resetPhotoPreview() {
    var preview = document.getElementById('staffPhotoPreview');
    if (preview) preview.src = '<?= base_url('tools/dist/img/kids.jpg') ?>';
    document.querySelectorAll('.nsa-file-name').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('.nsa-file-wrap').forEach(function(el) { el.style.borderColor = ''; });
}

/* ── Net Salary Calculator ── */
function updateNetSalary() {
    var basic = parseFloat(document.getElementById('basicSalary').value) || 0;
    var allow = parseFloat(document.getElementById('allowances').value) || 0;
    var net   = basic + allow;
    var el    = document.getElementById('netSalaryDisplay');
    if (el) el.textContent = '₹ ' + net.toLocaleString('en-IN');
}

/* ── Validation ── */
function validateStaffForm() {
    var isValid = true;

    document.querySelectorAll('.nsa-input.has-error, .nsa-select.has-error').forEach(function(el) {
        el.classList.remove('has-error');
    });
    document.querySelectorAll('.nsa-error-msg').forEach(function(el) { el.remove(); });

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? (el.value || '').trim() : '';
    }

    function showError(inputId, message) {
        var input = document.getElementById(inputId);
        if (!input) { isValid = false; return; }
        input.classList.add('has-error');
        var span = document.createElement('span');
        span.className = 'nsa-error-msg';
        span.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + message;
        input.parentNode.insertBefore(span, input.nextSibling);
        isValid = false;
    }

    /* Required fields */
    if (!getValue('name'))                   showError('name',                   'Staff name is required');
    if (!getValue('dob'))                    showError('dob',                    'Date of birth is required');
    if (!getValue('father_name'))            showError('father_name',            'Father name is required');
    if (!getValue('employment_type'))        showError('employment_type',        'Employment type is required');
    if (!getValue('teacher_department'))     showError('teacher_department',     'Department is required');

    /* Email */
    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(getValue('email_user')))
        showError('email_user', 'Enter a valid email address');

    /* Phone (required) */
    var phonePattern = /^[6-9]\d{9}$/;
    if (!phonePattern.test(getValue('phone_number')))
        showError('phone_number', 'Enter valid 10-digit mobile number');

    /* Emergency phone — validate format only if filled */
    var emergencyPhone = getValue('emergency_contact_phone');
    if (emergencyPhone && !phonePattern.test(emergencyPhone))
        showError('emergency_contact_phone', 'Enter valid 10-digit mobile number');

    /* Postal code — validate format only if filled */
    var postalVal = getValue('postal_code');
    var postalPattern = /^[1-9][0-9]{5}$/;
    if (postalVal && !postalPattern.test(postalVal))
        showError('postal_code', 'Enter valid 6-digit PIN code');

    /* Salary — basic salary required, allowances default to 0 */
    if (!getValue('basicSalary') || isNaN(parseFloat(getValue('basicSalary'))))
        showError('basicSalary', 'Enter valid basic salary');

    /* Files — validate format/size only if uploaded (both optional) */
    function validateFile(inputId, allowedTypes, maxSizeMB) {
        var input = document.getElementById(inputId);
        if (!input || !input.files || !input.files.length) return;
        var file = input.files[0];
        if (!allowedTypes.includes(file.type)) showError(inputId, 'Invalid file format');
        if (file.size > maxSizeMB * 1024 * 1024) showError(inputId, 'File must be under ' + maxSizeMB + 'MB');
    }

    validateFile('photo',  ['image/jpeg', 'image/jpg'], 2);
    validateFile('aadhar', ['image/jpeg', 'image/png', 'application/pdf', 'image/jpg'], 2);

    if (!isValid) {
        var firstErr = document.querySelector('.has-error, .nsa-error-msg');
        if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    return isValid;
}

/* ── Open / close preview modal ── */
function openStaffPreviewModal()  { document.getElementById('staffPreviewModalOverlay').classList.add('open'); }
function closeStaffPreviewModal() { document.getElementById('staffPreviewModalOverlay').classList.remove('open'); }

document.getElementById('staffPreviewModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeStaffPreviewModal();
});

/* ── Fill preview data ── */
function fillStaffPreviewData() {
    var getValue = function(id) {
        var el = document.getElementById(id);
        return el ? (el.value || '') : '';
    };
    var setText = function(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value || '—';
    };

    /* Hero */
    setText('previewStaffName',   getValue('name'));
    setText('previewStaffId',     getValue('user_id'));
    setText('previewPosition',    getValue('staffRolesHidden').split(',').join(', '));
    setText('previewDepartment',  getValue('teacher_department'));
    setText('previewJoiningDate', getValue('date_of_joining'));

    /* Basic */
    setText('previewDob',        getValue('dob'));
    setText('previewGender',     getValue('gender'));
    setText('previewBloodGroup', getValue('blood_group'));
    setText('previewEmail',      getValue('email_user'));

    /* Guardian / Contact */
    setText('previewFatherName',        getValue('father_name'));
    setText('previewPhone',             getValue('phone_number'));
    setText('previewAltPhone',          getValue('alt_phone'));
    setText('previewMaritalStatus',     getValue('marital_status'));
    setText('previewEmergencyName',     getValue('emergency_contact_name'));
    setText('previewEmergencyPhone',    getValue('emergency_contact_phone'));
    setText('previewEmergencyRelation', getValue('emergency_contact_relation'));
    setText('previewDesignation',       getValue('designation'));

    /* Statutory IDs */
    setText('previewPan',       getValue('pan_number'));
    setText('previewAadharNum', getValue('aadhar_number'));
    setText('previewPf',        getValue('pf_number'));
    setText('previewEsi',       getValue('esi_number'));

    /* Address */
    setText('previewStreet',     getValue('street'));
    setText('previewCity',       getValue('city'));
    setText('previewState',      getValue('state'));
    setText('previewPostalCode', getValue('postal_code'));

    /* Permanent Address */
    var sameAddr = document.getElementById('same_as_current');
    if (sameAddr && sameAddr.checked) {
        setText('previewPermAddress', 'Same as current address');
    } else {
        var pa = [getValue('perm_street'), getValue('perm_city'), getValue('perm_state'), getValue('perm_postal_code')].filter(function(v){return v;}).join(', ');
        setText('previewPermAddress', pa || '—');
    }

    /* Qualification */
    setText('previewEmploymentType', getValue('employment_type'));
    setText('previewQualification',  getValue('qualification'));
    setText('previewUniversity',     getValue('university'));
    setText('previewYearOfPassing',  getValue('year_of_passing'));
    setText('previewExperience',     getValue('teacher_experience') + ' yrs');

    /* Bank */
    setText('previewBankName',      getValue('bank_name'));
    setText('previewAccountHolder', getValue('account_holder'));
    setText('previewAccountNumber', getValue('account_number'));
    setText('previewIfsc',          getValue('bank_ifsc'));

    /* Salary */
    var basic = parseFloat(getValue('basicSalary')) || 0;
    var allow = parseFloat(getValue('allowances'))  || 0;
    setText('previewBasicSalary', '₹ ' + basic.toLocaleString('en-IN'));
    setText('previewAllowances',  '₹ ' + allow.toLocaleString('en-IN'));
    setText('previewNetSalary',   '₹ ' + (basic + allow).toLocaleString('en-IN'));

    /* Photo preview */
    var staffPhotoEl = document.getElementById('staffPhotoPreview');
    var previewImg   = document.getElementById('previewStaffPhoto');
    if (staffPhotoEl && previewImg) previewImg.src = staffPhotoEl.src;

    /* Documents */
    nsaHandleDocPreview('photo',  'previewPhotoName',  'previewPhotoView');
    nsaHandleDocPreview('aadhar', 'previewAadharName', 'previewAadharView');
}

function nsaHandleDocPreview(inputId, nameId, viewId) {
    var input   = document.getElementById(inputId);
    var nameEl  = document.getElementById(nameId);
    var viewBtn = document.getElementById(viewId);
    if (!input || !nameEl || !viewBtn) return;
    if (input.files && input.files.length > 0) {
        var file = input.files[0];
        nameEl.textContent    = file.name;
        viewBtn.href          = URL.createObjectURL(file);
        viewBtn.style.display = 'inline';
    } else {
        nameEl.textContent    = 'Not Uploaded';
        viewBtn.style.display = 'none';
    }
}

/* ── Final submit via AJAX ── */
function submitStaffFinalForm() {
    var form = document.getElementById('add_staff_form');
    if (!form) { nsaShowAlert('error', 'Form not found — please refresh.'); return; }

    var btn = document.getElementById('confirmSubmitBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…'; }

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
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
                return;
            }
            if (res.status === 'success') {
                closeStaffPreviewModal();

                // Surface any backend warning (e.g. Firebase Auth account creation failure)
                if (res.warning) {
                    nsaShowAlert('warning', res.warning);
                }

                // ATS integration: finalize hire if this was a convert-to-staff flow
                var atsAppId = document.getElementById('ats_application_id');
                if (atsAppId && atsAppId.value && res.staff_id) {
                    var csrfName = '<?= $this->security->get_csrf_token_name() ?>';
                    var csrfHash = res.csrf_hash || $('meta[name="csrf-token"]').attr('content');
                    var atsData = {};
                    atsData[csrfName] = csrfHash;
                    atsData['application_id'] = atsAppId.value;
                    atsData['staff_id'] = res.staff_id;
                    $.ajax({
                        url: '<?= base_url("ats/finalize_hire") ?>',
                        type: 'POST',
                        data: atsData,
                        dataType: 'json',
                        success: function(atsRes) {
                            if (atsRes && atsRes.status === 'success') {
                                nsaShowAlert('success', 'Staff created & hire finalized!');
                            } else {
                                nsaShowAlert('warning', 'Staff created but ATS update returned: ' + (atsRes.message || 'unknown error'));
                            }
                            setTimeout(function() { window.location.href = '<?= base_url("ats") ?>'; }, 1600);
                        },
                        error: function() {
                            nsaShowAlert('warning', 'Staff created but hire finalization failed. Please update ATS manually.');
                            setTimeout(function() { window.location.href = '<?= base_url("ats") ?>'; }, 2500);
                        }
                    });
                    return;
                }

                showStaffConfirmation(res);
            } else {
                nsaShowAlert('error', res.message || 'Submission failed. Please try again.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
            }
        },
        error: function() {
            nsaShowAlert('error', 'Server error — please try again.');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
        }
    });
}

/* ═══════════════════════════
   DOMContentLoaded Init
═══════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {

    /* ── Permanent address "same as current" toggle ── */
    var sameChk = document.getElementById('same_as_current');
    var permBlock = document.getElementById('permanent_address_block');
    if (sameChk && permBlock) {
        sameChk.addEventListener('change', function() {
            if (this.checked) {
                permBlock.style.display = 'none';
                document.getElementById('perm_street').value = document.getElementById('street').value;
                document.getElementById('perm_city').value = document.getElementById('city').value;
                document.getElementById('perm_state').value = document.getElementById('state').value;
                document.getElementById('perm_postal_code').value = document.getElementById('postal_code').value;
            } else {
                permBlock.style.display = '';
            }
        });
    }

    /* ── Load departments from HR module into dropdown ── */
    var _deptLoaded = false;
    var _pendingDeptValue = null;
    (function(){
        var sel = document.getElementById('teacher_department');
        if (!sel) return;
        fetch('<?= base_url("hr/get_departments") ?>')
            .then(function(r){ return r.json(); })
            .then(function(r){
                if (r.status !== 'success') return;
                var depts = r.departments || [];
                depts.forEach(function(d){
                    if ((d.status||'Active') !== 'Active') return;
                    var opt = document.createElement('option');
                    opt.value = d.name;
                    opt.textContent = d.name;
                    sel.appendChild(opt);
                });
                _deptLoaded = true;
                // Apply deferred ATS prefill value if any
                if (_pendingDeptValue) {
                    sel.value = _pendingDeptValue;
                    if (!sel.value) {
                        var opt = document.createElement('option');
                        opt.value = _pendingDeptValue; opt.textContent = _pendingDeptValue;
                        opt.selected = true; sel.appendChild(opt);
                    }
                }
            })
            .catch(function(){ _deptLoaded = true; });
    })();

    /* ── ATS Prefill: auto-fill form from applicant tracking data ── */
    var _atsPrefill = null;
    try {
        var raw = sessionStorage.getItem('ats_prefill');
        if (raw) {
            _atsPrefill = JSON.parse(raw);
            sessionStorage.removeItem('ats_prefill');
            if (_atsPrefill && _atsPrefill.prefill) {
                var pf = _atsPrefill.prefill;
                var fieldMap = {
                    'Name': 'name',
                    'email': 'email_user',
                    'phone_number': 'phone_number',
                    'qualification': 'qualification',
                    'experience': 'teacher_experience'
                };
                for (var key in fieldMap) {
                    if (pf[key]) {
                        var el = document.getElementById(fieldMap[key]);
                        if (el) el.value = pf[key];
                    }
                }
                // Department is a select that loads async — defer the value
                if (pf['department']) {
                    if (_deptLoaded) {
                        var dSel = document.getElementById('teacher_department');
                        if (dSel) dSel.value = pf['department'];
                    } else {
                        _pendingDeptValue = pf['department'];
                    }
                }
                // Store application_id for finalize_hire after submission
                var atsHidden = document.createElement('input');
                atsHidden.type = 'hidden';
                atsHidden.name = 'ats_application_id';
                atsHidden.id = 'ats_application_id';
                atsHidden.value = _atsPrefill.application_id || '';
                var form = document.getElementById('add_staff_form');
                if (form) form.appendChild(atsHidden);

                // Show banner
                var banner = document.createElement('div');
                banner.className = 'nsa-ats-banner';
                banner.innerHTML = '<i class="fa fa-info-circle"></i> Pre-filled from ATS applicant: <strong>' + (pf.Name || '') + '</strong>. Complete the remaining fields and submit.';
                var wrap = document.querySelector('.nsa-main');
                if (wrap) wrap.insertBefore(banner, wrap.firstChild);
            }
        }
    } catch(e) { /* ignore parse errors */ }

    /* Net salary live calculator */
    var basicInput = document.getElementById('basicSalary');
    var allowInput = document.getElementById('allowances');
    if (basicInput) basicInput.addEventListener('input', updateNetSalary);
    if (allowInput) allowInput.addEventListener('input', updateNetSalary);

    /* Preview button */
    var previewBtn = document.getElementById('preview_button');
    if (previewBtn) {
        previewBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!validateStaffForm()) return;
            fillStaffPreviewData();
            openStaffPreviewModal();
        });
    }

    /* ── Staff Role Picker (multi-select chips) ── */
    var _roleData = {};
    var _selectedRoles = [];
    var _primaryRole = '';

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
            chip.title = isPrimary ? 'Primary role — click to change' : 'Click to set as primary';
            chip.setAttribute('data-rid', rid);
            chip.addEventListener('click', function(e) {
                if (e.target.classList.contains('nsa-role-remove')) return;
                _primaryRole = rid;
                renderRoleChips();
            });
            container.appendChild(chip);
        });
        // Remove buttons
        container.querySelectorAll('.nsa-role-remove').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var rid = this.getAttribute('data-rid');
                _selectedRoles = _selectedRoles.filter(function(r) { return r !== rid; });
                if (_primaryRole === rid) _primaryRole = _selectedRoles[0] || '';
                renderRoleChips();
            });
        });
        // Sync hidden fields
        document.getElementById('staffRolesHidden').value = _selectedRoles.join(',');
        document.getElementById('primaryRoleHidden').value = _primaryRole;
        // Notify teaching section toggle (Phase 1)
        if (typeof recheckTeachingVisibility === 'function') recheckTeachingVisibility();
    }

    /* ── Teacher-specific fields: show/hide based on Position ── */
    var _selectedSubjects = [];

    // ─── Teaching section toggle ─────────────────────────────────────
    // Show teaching fields if ROLE_TEACHER is in staff_roles
    function isTeacherSelected() {
        var rolesHidden = document.getElementById('staffRolesHidden');
        if (rolesHidden && rolesHidden.value) {
            var roles = rolesHidden.value.split(',').map(function(r){ return r.trim(); });
            if (roles.indexOf('ROLE_TEACHER') !== -1) return true;
        }
        return false;
    }

    function recheckTeachingVisibility() {
        var section = document.getElementById('teacherExtraFields');
        if (!section) return;
        var show = isTeacherSelected();
        section.style.display = show ? 'block' : 'none';
        if (show) {
            loadSubjectOptions();
        }
    }

    // Run once on page load (handles edit case where data is pre-filled)
    recheckTeachingVisibility();

    var _subjectsLoading = false;
    var _subjectsLoaded  = false;
    function loadSubjectOptions() {
        var sel = document.getElementById('subjectPicker');
        if (_subjectsLoaded || _subjectsLoading) return; // already loaded or in flight
        _subjectsLoading = true;
        fetch('<?= base_url("school_config/get_all_subjects") ?>', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
            body: '<?= $this->security->get_csrf_token_name() ?>=<?= $this->security->get_csrf_hash() ?>'
        })
        .then(function(r){ return r.json(); })
        .then(function(r){
            if (r.status === 'success' && r.subjects) {
                var seen = {};
                // Drop any options other than the placeholder before appending
                while (sel.options.length > 1) sel.remove(1);
                r.subjects.forEach(function(s){
                    var nm = (s.name || s.code || '').trim();
                    if (!nm) return;
                    var key = nm.toLowerCase();
                    if (seen[key]) return;
                    seen[key] = true;
                    var opt = document.createElement('option');
                    opt.value = nm;
                    opt.textContent = nm + (s.category ? ' (' + s.category + ')' : '');
                    sel.appendChild(opt);
                });
            }
            _subjectsLoaded  = true;
            _subjectsLoading = false;
        })
        .catch(function(){
            // Fallback: add common subjects
            while (sel.options.length > 1) sel.remove(1);
            ['English','Hindi','Mathematics','Science','Social Science','Computer Science',
             'Physics','Chemistry','Biology','Accountancy','Business Studies','Economics',
             'History','Geography','Political Science','Sanskrit','Physical Education','Art'].forEach(function(s){
                var opt = document.createElement('option');
                opt.value = s; opt.textContent = s;
                sel.appendChild(opt);
            });
            _subjectsLoaded  = true;
            _subjectsLoading = false;
        });
    }

    // Subject chip picker (capability list — actual class assignment in Academic Planner)
    document.getElementById('subjectPicker').addEventListener('change', function(){
        var val = this.value;
        if (!val || _selectedSubjects.indexOf(val) !== -1) { this.value=''; return; }
        _selectedSubjects.push(val);
        renderSubjectChips();
        this.value = '';
    });

    function renderSubjectChips() {
        var container = document.getElementById('subjectChips');
        container.innerHTML = '';
        _selectedSubjects.forEach(function(s){
            var chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:var(--gold-dim,rgba(15,118,110,.1));color:var(--gold,#0f766e);border-radius:14px;font-size:12px;font-weight:500;';
            chip.innerHTML = s + ' <button type="button" style="background:none;border:none;color:inherit;cursor:pointer;font-weight:700;padding:0 2px;" onclick="removeSubject(\'' + s.replace(/'/g,"\\'") + '\')">&times;</button>';
            container.appendChild(chip);
        });
        document.getElementById('teachingSubjectsHidden').value = _selectedSubjects.join(',');
    }

    window.removeSubject = function(s) {
        _selectedSubjects = _selectedSubjects.filter(function(x){ return x !== s; });
        renderSubjectChips();
    };

    /* Sidebar active highlight on scroll */
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

/* ── Page shell ── */
html, body, .content-wrapper { background: var(--nsa-bg) !important; }
.nsa-wrap {
    font-family: 'Instrument Sans', sans-serif;
    background: var(--nsa-bg);
    color: var(--nsa-text);
    padding: 26px 22px 60px;
    min-height: 100vh;
    width: 100%;
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
    position: sticky;
    top: 16px;
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
    cursor: pointer;
    transition: background .12s, color .12s;
    text-decoration: none;
}
.nsa-nav-item:last-child { border-bottom: none; }
.nsa-nav-item i { width: 16px; text-align: center; color: #94a3b8; transition: color .12s; }
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
.nsa-grid   { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px 20px; }
.nsa-grid-3 { grid-template-columns: repeat(3, 1fr); }
.nsa-grid-2 { grid-template-columns: repeat(2, 1fr); }
.nsa-col-2  { grid-column: span 2; }
@media (max-width: 1100px) { .nsa-grid   { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .nsa-grid, .nsa-grid-3, .nsa-grid-2 { grid-template-columns: repeat(2, 1fr); } .nsa-col-2 { grid-column: span 2; } }
@media (max-width: 480px)  { .nsa-grid, .nsa-grid-3, .nsa-grid-2 { grid-template-columns: 1fr; } .nsa-col-2 { grid-column: span 1; } }

/* ── Fields ── */
.nsa-field { display: flex; flex-direction: column; gap: 5px; }
.nsa-field label {
    font-size: 12px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px;
    color: var(--nsa-muted);
}
.nsa-field label .req { color: var(--nsa-red); margin-left: 2px; }

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
.nsa-input[readonly] {
    background: #f1f5f9;
    color: var(--nsa-muted);
    cursor: not-allowed;
}
.nsa-input.has-error, .nsa-select.has-error { border-color: var(--nsa-red); }
.nsa-error-msg {
    font-size: 11.5px; color: var(--nsa-red);
    display: flex; align-items: center; gap: 4px;
    margin-top: 2px;
}
.nsa-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 11px center;
    background-color: #fafbff;
    padding-right: 30px;
}

/* ── Net salary box ── */
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
    border-radius: 8px;
    padding: 12px 14px;
    background: #fafbff;
    transition: border-color .14s;
    cursor: pointer;
}
.nsa-file-wrap:hover { border-color: var(--nsa-teal); }
.nsa-file-wrap input[type="file"] {
    position: absolute; inset: 0;
    opacity: 0; cursor: pointer; width: 100%; height: 100%;
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
    width: 100px; height: 120px;
    object-fit: cover; border-radius: 8px;
    border: 2px solid var(--nsa-border);
    box-shadow: var(--nsa-shadow);
}
.nsa-photo-hint { font-size: 11px; color: var(--nsa-muted); text-align: center; }
.nsa-photo-file { flex: 1; }

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
.nsa-btn-success { background: var(--nsa-dark); color: #fff; }

/* ── Modal overlay ── */
.nsa-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.5); z-index: 9100;
    align-items: flex-start; justify-content: center;
    padding: 24px 16px; overflow-y: auto;
}
.nsa-overlay.open { display: flex; }
.nsa-modal {
    background: var(--nsa-white);
    border-radius: 16px;
    width: 100%; max-width: 860px;
    box-shadow: 0 20px 60px rgba(0,0,0,.22);
    animation: nsa-modal-in .18s ease;
    margin: auto;
}
@keyframes nsa-modal-in {
    from { transform: translateY(-12px); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
}
.nsa-modal-head {
    background: var(--nsa-navy);
    padding: 20px 26px;
    border-radius: 16px 16px 0 0;
    display: flex; align-items: center; justify-content: space-between;
}
.nsa-modal-head h3 {
    margin: 0; font-family: 'Lora', serif;
    font-size: 19px; font-weight: 700; color: #fff;
}
.nsa-modal-head p { margin: 3px 0 0; font-size: 12.5px; color: rgba(255,255,255,.6); }
.nsa-modal-x {
    background: rgba(255,255,255,.1); border: none;
    color: #fff; font-size: 18px; width: 32px; height: 32px;
    border-radius: 8px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .13s;
}
.nsa-modal-x:hover { background: rgba(255,255,255,.2); }

/* Hero strip */
.nsa-preview-hero {
    display: flex; align-items: center; gap: 20px;
    padding: 22px 26px;
    border-bottom: 1px solid var(--nsa-border);
    background: var(--nsa-sky);
}
.nsa-preview-hero img {
    width: 72px; height: 86px; object-fit: cover;
    border-radius: 10px; border: 2px solid var(--nsa-teal);
    flex-shrink: 0;
}
.nsa-preview-hero-info h2 {
    margin: 0 0 4px;
    font-family: 'Lora', serif;
    font-size: 21px; font-weight: 700;
    color: var(--nsa-navy);
}
.nsa-preview-hero-badges { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 6px; }
.nsa-prev-badge {
    padding: 3px 11px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
    background: rgba(15,118,110,.1); color: var(--nsa-teal);
    border: 1px solid rgba(15,118,110,.2);
}

.nsa-modal-body { padding: 22px 26px; }

/* Preview sections */
.nsa-prev-section { margin-bottom: 22px; }
.nsa-prev-section-title {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px;
    color: var(--nsa-muted);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 7px;
}
.nsa-prev-section-title::after {
    content: ''; flex: 1; height: 1px; background: var(--nsa-border);
}
.nsa-prev-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 10px 16px;
}
@media (max-width: 600px) { .nsa-prev-grid { grid-template-columns: repeat(2, 1fr); } }
.nsa-prev-field .lbl {
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    color: #94a3b8; margin-bottom: 2px;
}
.nsa-prev-field .val {
    font-size: 13.5px; font-weight: 500;
    color: var(--nsa-text); word-break: break-word;
}

/* Docs */
.nsa-prev-docs { display: flex; gap: 10px; flex-wrap: wrap; }
.nsa-prev-doc-item {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 13px; border-radius: 8px;
    background: #f0fdf8; border: 1px solid var(--nsa-border);
    font-size: 12.5px; font-weight: 500;
}
.nsa-prev-doc-item i { color: var(--nsa-teal); }
.nsa-prev-doc-item a { color: var(--nsa-teal); text-decoration: none; font-size: 11.5px; margin-left: 4px; }
.nsa-prev-doc-item a:hover { text-decoration: underline; }

.nsa-modal-foot {
    padding: 16px 26px;
    border-top: 1px solid var(--nsa-border);
    display: flex; align-items: center; justify-content: flex-end; gap: 12px;
    border-radius: 0 0 16px 16px;
    background: var(--nsa-bg);
}

/* ── Toast ── */
.nsa-toast {
    position: fixed; top: 20px; right: 20px;
    padding: 12px 18px; border-radius: 10px;
    font-size: 13.5px; font-weight: 500;
    color: #fff; z-index: 99999;
    box-shadow: 0 4px 14px rgba(0,0,0,.18);
    display: flex; align-items: center; gap: 9px;
    animation: nsa-toast-in .25s ease;
    max-width: 340px;
}
@keyframes nsa-toast-in {
    from { transform: translateX(20px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}
.nsa-toast.success { background: var(--nsa-dark); }
.nsa-toast.error   { background: var(--nsa-red);  }
.nsa-toast.warning { background: var(--nsa-amber); }

/* ── ATS Prefill Banner ── */
.nsa-ats-banner {
    background: linear-gradient(135deg, rgba(15,118,110,.10), rgba(21,128,61,.10));
    border: 1.5px solid rgba(15,118,110,.25);
    border-radius: 10px;
    padding: 12px 18px;
    margin-bottom: 16px;
    font: 500 13px/1.5 var(--nsa-font, 'Plus Jakarta Sans', sans-serif);
    color: var(--nsa-dark, #0c1e38);
    display: flex; align-items: center; gap: 8px;
}
.nsa-ats-banner i { color: #0f766e; font-size: 16px; }

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

/* Staff Confirmation Dialog */
.staff-confirm-overlay {
    position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);
    z-index:10000;display:flex;align-items:center;justify-content:center;animation:sfFadeIn .2s;
}
.staff-confirm-box {
    background:var(--bg2,#fff);border-radius:16px;padding:32px 36px;
    max-width:440px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center;animation:sfScaleIn .25s;
}
.staff-confirm-box .sf-icon {
    width:64px;height:64px;border-radius:50%;background:#dcfce7;
    display:flex;align-items:center;justify-content:center;margin:0 auto 16px;
}
.staff-confirm-box .sf-icon i { font-size:28px;color:#16a34a; }
.staff-confirm-box h2 { margin:0 0 6px;font-size:1.2rem;color:var(--t1,#111); }
.staff-confirm-box .sf-sub { color:var(--t3,#888);font-size:.9rem;margin-bottom:20px; }
.sf-cred-card {
    background:var(--bg3,#f5f5f5);border:1.5px solid var(--gold,#0f766e);
    border-radius:12px;padding:18px 20px;text-align:left;margin-bottom:20px;
}
.sf-cred-row { display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border,#e5e7eb);font-size:.92rem; }
.sf-cred-row:last-child { border-bottom:none; }
.sf-cred-row .sf-label { color:var(--t3,#888);font-size:.82rem; }
.sf-cred-row .sf-value { color:var(--t1,#111);font-weight:600;font-family:var(--font-m,monospace);font-size:.95rem; }
.sf-cred-row .sf-pwd { color:#dc2626;letter-spacing:.5px; }
.staff-confirm-box .sf-note { font-size:.8rem;color:var(--t3,#888);background:var(--bg3,#f5f5f5);padding:10px 14px;border-radius:8px;margin-bottom:18px;text-align:left; }
.staff-confirm-box .sf-actions { display:flex;gap:10px;justify-content:center; }
.staff-confirm-box .sf-btn { padding:10px 24px;border-radius:8px;border:none;cursor:pointer;font-size:.9rem;font-weight:600;transition:all .15s; }
.sf-btn-print { background:var(--gold,#0f766e);color:#fff; }
.sf-btn-print:hover { opacity:.85; }
.sf-btn-done { background:var(--bg3,#f5f5f5);color:var(--t1,#111);border:1px solid var(--border,#ddd); }
.sf-btn-done:hover { background:var(--bg2,#eee); }
@keyframes sfFadeIn { from{opacity:0} to{opacity:1} }
@keyframes sfScaleIn { from{transform:scale(.9);opacity:0} to{transform:scale(1);opacity:1} }
</style>

<script>
function showStaffConfirmation(res) {
    var overlay = document.createElement('div');
    overlay.className = 'staff-confirm-overlay';
    overlay.innerHTML =
        '<div class="staff-confirm-box">' +
            '<div class="sf-icon"><i class="fa fa-check"></i></div>' +
            '<h2>Staff Added Successfully!</h2>' +
            '<p class="sf-sub">Share these credentials with the staff member for app login.</p>' +
            '<div class="sf-cred-card">' +
                '<div class="sf-cred-row"><span class="sf-label">Name</span><span class="sf-value">' + nsaEsc(res.name || '') + '</span></div>' +
                '<div class="sf-cred-row"><span class="sf-label">Staff ID</span><span class="sf-value">' + nsaEsc(res.staff_id || '') + '</span></div>' +
                '<div class="sf-cred-row"><span class="sf-label">Password</span><span class="sf-value sf-pwd">' + nsaEsc(res.default_password || '') + '</span></div>' +
                '<div class="sf-cred-row"><span class="sf-label">Position</span><span class="sf-value">' + nsaEsc(res.position || '') + '</span></div>' +
            '</div>' +
            '<div class="sf-note">' +
                '<i class="fa fa-info-circle" style="margin-right:5px;color:var(--gold,#0f766e);"></i>' +
                'Staff can login using <strong>Staff ID</strong> and <strong>Password</strong> in the SchoolSync Teacher App.' +
            '</div>' +
            '<div class="sf-actions">' +
                '<button class="sf-btn sf-btn-print" onclick="printStaffSlip()"><i class="fa fa-print"></i> Print Slip</button>' +
                '<button class="sf-btn sf-btn-done" onclick="closeStaffConfirm()"><i class="fa fa-check"></i> Done</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    window._staffSlipData = res;
}
function closeStaffConfirm() {
    var el = document.querySelector('.staff-confirm-overlay');
    if (el) el.remove();
    location.reload();
}
function printStaffSlip() {
    var d = window._staffSlipData || {};
    var w = window.open('', '_blank', 'width=400,height=450');
    w.document.write(
        '<!DOCTYPE html><html><head><title>Staff Credential Slip</title>' +
        '<style>body{font-family:sans-serif;padding:30px;max-width:360px;margin:auto;}' +
        'h2{text-align:center;margin-bottom:5px;}' +
        '.sub{text-align:center;color:#888;font-size:13px;margin-bottom:20px;}' +
        'table{width:100%;border-collapse:collapse;margin-bottom:20px;}' +
        'td{padding:8px 10px;border-bottom:1px solid #eee;font-size:14px;}' +
        'td:first-child{color:#888;width:40%;}td:last-child{font-weight:600;}' +
        '.pwd{color:#dc2626;letter-spacing:.5px;font-family:monospace;}' +
        '.note{font-size:12px;color:#888;background:#f9f9f9;padding:10px;border-radius:6px;}' +
        '</style></head><body>' +
        '<h2>Staff Credential Slip</h2><p class="sub">Teacher App Login</p>' +
        '<table>' +
        '<tr><td>Name</td><td>' + nsaEsc(d.name||'') + '</td></tr>' +
        '<tr><td>Staff ID</td><td>' + nsaEsc(d.staff_id||'') + '</td></tr>' +
        '<tr><td>Password</td><td class="pwd">' + nsaEsc(d.default_password||'') + '</td></tr>' +
        '<tr><td>Position</td><td>' + nsaEsc(d.position||'') + '</td></tr>' +
        '</table>' +
        '<div class="note"><strong>Note:</strong> Use Staff ID and Password to login in the SchoolSync Teacher App.</div>' +
        '</body></html>'
    );
    w.document.close();
    setTimeout(function(){ w.print(); }, 300);
}
function nsaEsc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>