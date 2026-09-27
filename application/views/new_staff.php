<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="nsa-wrap">

    <!-- ── Top bar ── -->
    <div class="nsa-topbar">
        <div class="nsa-topbar-row">
            <div>
                <h1 class="nsa-page-title">
                    <i class="fa fa-id-badge"></i> Add New Staff
                </h1>
                <ol class="nsa-breadcrumb">
                    <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                    <li><a href="<?= base_url('staff/all_staff') ?>">All Staff</a></li>
                    <li>Add Staff</li>
                </ol>
            </div>
            <button type="button" class="nsa-btn nsa-btn-ghost" id="wizardStartOverBtn" onclick="wizardStartOver()">
                <i class="fa fa-refresh"></i> Start Over
            </button>
        </div>
    </div>

    <!-- ── Draft-restore banner (hidden unless a saved draft exists) ── -->
    <div class="nsa-draft-banner" id="draftBanner" style="display:none;">
        <i class="fa fa-clock-o"></i>
        <span>You have an unfinished draft<span id="draftBannerWhen"></span>. Continue where you left off?</span>
        <div class="nsa-draft-banner-actions">
            <button type="button" class="nsa-btn nsa-btn-ghost" onclick="wizardDiscardDraft()">Discard</button>
            <button type="button" class="nsa-btn nsa-btn-primary" onclick="wizardRestoreDraft()">Restore Draft</button>
        </div>
    </div>

    <!-- ── Stepper ── -->
    <div class="nsa-stepper" id="wizardStepper"></div>
    <div class="nsa-stepper-progress"><div class="nsa-stepper-progress-bar" id="wizardProgressBar"></div></div>

    <!-- ── Wizard body ── -->
    <div class="nsa-main">
        <form action="<?= base_url('staff/new_staff') ?>"
              method="post"
              id="add_staff_form"
              enctype="multipart/form-data">
              <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
       value="<?= $this->security->get_csrf_hash() ?>">

            <!-- Global (not tied to any single step) -->
            <input type="hidden" name="staff_roles" id="staffRolesHidden" value="">
            <input type="hidden" name="primary_role" id="primaryRoleHidden" value="">
            <input type="hidden" name="teaching_subjects" id="teachingSubjectsHidden" value="">

            <!-- ══════════════════════════════════════════════════════════
                 STEP 1 — PERSONAL INFORMATION
                 Who is this person: identity, contact email, photo.
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-personal" data-step="0">
                <div class="nsa-section-head">
                    <i class="fa fa-user"></i>
                    <h3>Personal Information</h3>
                    <span class="nsa-section-hint">Basic identity details</span>
                </div>
                <div class="nsa-section-body">
                    <div class="nsa-grid">

                        <div class="nsa-field">
                            <label>Staff ID</label>
                            <input type="text" name="user_id" id="user_id"
                                   value="STA<?= str_pad($staffIdCount, 4, '0', STR_PAD_LEFT) ?>"
                                   class="nsa-input" readonly>
                        </div>

                        <div class="nsa-field nsa-col-2">
                            <label>Staff Name <span class="req">*</span></label>
                            <input type="text" id="name" name="Name"
                                   class="nsa-input" placeholder="Full name" required>
                        </div>

                        <div class="nsa-field">
                            <label>Date of Birth <span class="req">*</span></label>
                            <input type="date" id="dob" name="dob" class="nsa-input" required>
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

                        <div class="nsa-field">
                            <label>Email <span class="req">*</span></label>
                            <input type="email" id="email_user" name="email"
                                   class="nsa-input" placeholder="staff@email.com" required>
                        </div>

                        <div class="nsa-field">
                            <label>Religion <span class="req">*</span></label>
                            <select id="religion" name="religion" class="nsa-select" required>
                                <option value="">Select Religion</option>
                                <?php foreach (['Hindu','Muslim','Sikh','Jain','Buddh','Christian','Other'] as $r): ?>
                                <option value="<?= $r ?>"><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="nsa-field">
                            <label>Category <span class="req">*</span></label>
                            <select id="category" name="category" class="nsa-select" required>
                                <option value="">Select</option>
                                <?php foreach (['General','OBC','SC','ST'] as $c): ?>
                                <option value="<?= $c ?>"><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Staff Photo -->
                        <div class="nsa-field nsa-col-2">
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

                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 STEP 2 — CONTACT & GUARDIAN
                 How do we reach them, and their family in an emergency.
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-contact" data-step="1">
                <div class="nsa-section-head">
                    <i class="fa fa-users"></i>
                    <h3>Contact &amp; Guardian Details</h3>
                    <span class="nsa-section-hint">Phone, guardian &amp; emergency contact</span>
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

                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 STEP 3 — EMPLOYMENT DETAILS
                 What's their job here: role, department, designation,
                 reporting line, joining terms. (Designation lives HERE,
                 not under Guardian — it's a job attribute.)
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-employment" data-step="2">
                <div class="nsa-section-head">
                    <i class="fa fa-briefcase"></i>
                    <h3>Employment Details</h3>
                    <span class="nsa-section-hint">Role, department &amp; joining terms</span>
                </div>
                <div class="nsa-section-body">
                    <div class="nsa-grid nsa-grid-2">

                        <!-- UX-D1: Department first. The role picker is scoped BY department
                             and shows "Select a department first..." until one is chosen, so
                             asking for the role above it put the dependent field first. -->
                        <div class="nsa-field nsa-col-2">
                            <label>Department <span class="req">*</span></label>
                            <select id="teacher_department" name="department" class="nsa-input" required>
                                <option value="">-- Select Department --</option>
                            </select>
                            <small style="color:var(--nsa-muted);font-size:11px;">Configure in HR &rarr; Departments first. Roles are scoped to the department you pick.</small>
                        </div>

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
                            <label>Designation</label>
                            <input type="text" id="designation" name="designation"
                                   class="nsa-input" placeholder="e.g. Senior Teacher, HOD">
                            <small style="color:var(--nsa-muted);font-size:11px;">Display title shown on ID cards &amp; documents. Leave empty to use the role name.</small>
                        </div>

                        <!-- Reporting Manager (Phase 2C — Staff Lifecycle). Optional at
                             admission; server re-validates same-school/Active/self-
                             reference/cycle regardless of this list's contents. -->
                        <div class="nsa-field">
                            <label>Reporting Manager</label>
                            <select id="reporting_manager_id" name="reporting_manager_id" class="nsa-input">
                                <option value="">-- None --</option>
                            </select>
                            <small style="color:var(--nsa-muted);font-size:11px;">Optional. The staff member this person reports to.</small>
                        </div>

                        <?php if (!empty($hrms_capability_enabled) && !empty($locations)): ?>
                        <div class="nsa-field">
                            <label>Location</label>
                            <select id="location_id" name="location_id" class="nsa-input">
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= htmlspecialchars($loc['locationId'], ENT_QUOTES, 'UTF-8') ?>" <?= !empty($loc['isDefault']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($loc['name'], ENT_QUOTES, 'UTF-8') ?><?= !empty($loc['isDefault']) ? ' (default)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--nsa-muted);font-size:11px;">Configure in System Settings → Organisation.</small>
                        </div>
                        <?php endif; ?>

                        <div class="nsa-field">
                            <label>Employment Type <span class="req">*</span></label>
                            <!-- UX-D2: free text here produced Full-time / full-time /
                                 "full time" as three separate values in live data, plus
                                 "one day employment" and "fafwfw". A closed list is the
                                 only thing that keeps this reportable. -->
                            <select id="employment_type" name="employment_type" class="nsa-input" required>
                                <option value="">-- Select Employment Type --</option>
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Probation">Probation</option>
                                <option value="Temporary">Temporary</option>
                                <option value="Consultant">Consultant</option>
                                <option value="Intern">Intern</option>
                            </select>
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

            <!-- ══════════════════════════════════════════════════════════
                 STEP 4 — STATUTORY IDENTIFICATION
                 Their government IDs — its own step, separate from banking.
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-statutory" data-step="3">
                <div class="nsa-section-head">
                    <i class="fa fa-id-card-o"></i>
                    <h3>Statutory Identification</h3>
                    <span class="nsa-section-hint">Government-issued ID numbers</span>
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

                        <!-- Aadhar Card upload — kept beside the Aadhar number it proves -->
                        <!-- UX-D3: the NUMBER belongs here; the CARD does not. Uploading it
                             here as well as under HR Documents > Identity Proof gave the same
                             document two homes, two states and no single answer to "do we hold
                             this person's ID?". Collected once, after joining, where issue and
                             replacement are tracked per document type. -->
                        <div class="nsa-field nsa-col-2">
                            <div class="nsa-doc-pointer">
                                <i class="fa fa-info-circle"></i>
                                <span>Identity, qualification and contract documents are collected
                                      under <strong>HR Documents</strong> on the staff profile once
                                      this record is created.</span>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 STEP 5 — ADDRESS
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-address" data-step="4">
                <div class="nsa-section-head">
                    <i class="fa fa-map-marker"></i>
                    <h3>Address Details</h3>
                    <span class="nsa-section-hint">Where they live</span>
                </div>
                <div class="nsa-section-body">
                    <div class="nsa-grid nsa-grid-2">

                        <div class="nsa-field nsa-col-2">
                            <label>Street</label>
                            <input type="text" id="street" name="street"
                                   class="nsa-input" placeholder="House no., Street name">
                        </div>

                        <div class="nsa-field">
                            <label>State</label>
                            <select id="state" name="state" class="nsa-input"
                                    data-india-state data-india-fill data-india-placeholder="Select State">
                                <option value="">Select State</option>
                            </select>
                        </div>

                        <div class="nsa-field">
                            <label>District</label>
                            <select id="city" name="city" class="nsa-input"
                                    data-india-district="state" data-india-placeholder="Select District">
                                <option value="">Select District</option>
                            </select>
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
                                <label>State</label>
                                <select id="perm_state" name="perm_state" class="nsa-input"
                                        data-india-state data-india-fill data-india-placeholder="Select State">
                                    <option value="">Select State</option>
                                </select>
                            </div>
                            <div class="nsa-field">
                                <label>District</label>
                                <select id="perm_city" name="perm_city" class="nsa-input"
                                        data-india-district="perm_state" data-india-placeholder="Select District">
                                    <option value="">Select District</option>
                                </select>
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

            <!-- ══════════════════════════════════════════════════════════
                 STEP 6 — QUALIFICATION & EDUCATION
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-qualification" data-step="5">
                <div class="nsa-section-head">
                    <i class="fa fa-graduation-cap"></i>
                    <h3>Qualification &amp; Education</h3>
                    <span class="nsa-section-hint">Highest qualification on record</span>
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

            <!-- ══════════════════════════════════════════════════════════
                 STEP 7 — COMPENSATION & BANKING
                 How we pay them — salary and bank details together, since
                 both feed the same payroll setup.
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-compensation" data-step="6">
                <div class="nsa-section-head">
                    <i class="fa fa-money"></i>
                    <h3>Compensation &amp; Banking</h3>
                    <span class="nsa-section-hint">Salary structure &amp; payout account</span>
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

            <!-- ══════════════════════════════════════════════════════════
                 STEP 8 — REVIEW & CREATE
                 Exactly what will be saved. No new data entry here.
            ══════════════════════════════════════════════════════════ -->
            <div class="nsa-section nsa-step-panel" id="step-review" data-step="7">
                <div class="nsa-section-head">
                    <i class="fa fa-check-circle"></i>
                    <h3>Review &amp; Create</h3>
                    <span class="nsa-section-hint">Confirm every detail before creating the account</span>
                </div>
                <div class="nsa-section-body" style="padding-top:0;">

                    <!-- Hero strip -->
                    <div class="nsa-preview-hero">
                        <img id="reviewStaffPhotoImg" src="" alt="Staff Photo">
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

                    <div class="nsa-review-body">

                        <!-- Basic Info -->
                        <div class="nsa-prev-section">
                            <div class="nsa-prev-section-title"><i class="fa fa-user"></i> Personal Information</div>
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
                            </div>
                        </div>

                        <!-- Employment -->
                        <div class="nsa-prev-section">
                            <div class="nsa-prev-section-title"><i class="fa fa-briefcase"></i> Employment Details</div>
                            <div class="nsa-prev-grid">
                                <div class="nsa-prev-field"><div class="lbl">Designation</div><div class="val" id="previewDesignation"></div></div>
                                <div class="nsa-prev-field"><div class="lbl">Employment Type</div><div class="val" id="previewEmploymentType"></div></div>
                                <div class="nsa-prev-field"><div class="lbl">Experience</div><div class="val" id="previewExperience"></div></div>
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
                                <div class="nsa-prev-field"><div class="lbl">Qualification</div><div class="val" id="previewQualification"></div></div>
                                <div class="nsa-prev-field"><div class="lbl">University</div><div class="val" id="previewUniversity"></div></div>
                                <div class="nsa-prev-field"><div class="lbl">Year of Passing</div><div class="val" id="previewYearOfPassing"></div></div>
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
                        <div class="nsa-prev-section" style="margin-bottom:0;">
                            <div class="nsa-prev-section-title"><i class="fa fa-file-text-o"></i> Uploaded Documents</div>
                            <div class="nsa-prev-docs">
                                <div class="nsa-prev-doc-item">
                                    <i class="fa fa-camera"></i>
                                    <span id="previewPhotoName">Staff Photo</span>
                                    <a href="#" target="_blank" id="previewPhotoView" style="display:none;"><i class="fa fa-eye"></i> View</a>
                                </div>
                                <!-- DOC-D1: the Aadhaar CARD row was removed with its upload
                                     (UX-D3 — identity documents are collected under HR Documents
                                     after joining). The row outlived the input, and because
                                     nsaHandleDocPreview() bailed on the missing input its
                                     hardcoded "Aadhar Card" label reached the screen, implying
                                     a held ID document on a form that cannot accept one. -->
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </form>

        <!-- ══ WIZARD FOOTER — Back / Save & Continue / Create ══ -->
        <div class="nsa-wizard-footer">
            <button type="button" class="nsa-btn nsa-btn-ghost" id="wizardBackBtn" onclick="wizardBack()">
                <i class="fa fa-arrow-left"></i> Back
            </button>
            <div class="nsa-wizard-footer-status" id="wizardFooterStatus"></div>
            <button type="button" class="nsa-btn nsa-btn-primary" id="wizardNextBtn" onclick="wizardNext()">
                Save &amp; Continue <i class="fa fa-arrow-right"></i>
            </button>
            <button type="button" class="nsa-btn nsa-btn-success" id="wizardCreateBtn" style="display:none;" onclick="submitStaffFinalForm()">
                <i class="fa fa-check"></i> Create Staff
            </button>
        </div>

    </div><!-- /.nsa-main -->
</div><!-- /.nsa-wrap -->
</div><!-- /.content-wrapper -->


<script src="<?= base_url('assets/js/india_geo.js') ?>"></script>
<script src="<?= base_url('assets/js/zx_dialog.js') ?>?v=1"></script>
<script>

/* ═══════════════════════════════════════════════════════════════
   WIZARD ENGINE
   8 steps, one visible at a time. "Save & Continue" validates only
   the fields inside the current step, marks it complete, and
   advances. Jumping ahead via the stepper is not allowed past the
   furthest step already unlocked; jumping back is always allowed.
   The Review step re-validates everything (belt-and-braces in case
   a restored draft skipped a step) before Create Staff is enabled.
═══════════════════════════════════════════════════════════════ */
var WIZARD_STEPS = [
    { id: 'step-personal',      title: 'Personal Info',    icon: 'fa-user' },
    { id: 'step-contact',       title: 'Contact & Guardian',icon: 'fa-users' },
    { id: 'step-employment',    title: 'Employment',       icon: 'fa-briefcase' },
    { id: 'step-statutory',     title: 'Statutory ID',     icon: 'fa-id-card-o' },
    { id: 'step-address',       title: 'Address',          icon: 'fa-map-marker' },
    { id: 'step-qualification', title: 'Qualification',    icon: 'fa-graduation-cap' },
    { id: 'step-compensation',  title: 'Compensation',     icon: 'fa-money' },
    { id: 'step-review',        title: 'Review & Create',  icon: 'fa-check-circle' }
];
var wizardCurrentStep = 0;
var wizardFurthestUnlocked = 0;
var wizardCompleted = {};

function wizardStepEl(i) { return document.getElementById(WIZARD_STEPS[i].id); }

function renderStepper() {
    var bar = document.getElementById('wizardStepper');
    bar.innerHTML = '';
    WIZARD_STEPS.forEach(function(s, i) {
        var el = document.createElement('div');
        var cls = 'nsa-step';
        if (i === wizardCurrentStep) cls += ' active';
        if (wizardCompleted[i]) cls += ' completed';
        if (i <= wizardFurthestUnlocked) cls += ' clickable';
        el.className = cls;
        el.innerHTML =
            '<div class="nsa-step-circle">' + (wizardCompleted[i] && i !== wizardCurrentStep ? '<i class="fa fa-check"></i>' : (i + 1)) + '</div>' +
            '<div class="nsa-step-label">' + s.title + '</div>';
        if (i <= wizardFurthestUnlocked) {
            el.addEventListener('click', function() { wizardGoTo(i); });
        }
        bar.appendChild(el);
    });
    var pct = Math.round(((wizardCurrentStep) / (WIZARD_STEPS.length - 1)) * 100);
    document.getElementById('wizardProgressBar').style.width = pct + '%';
    document.getElementById('wizardFooterStatus').textContent = 'Step ' + (wizardCurrentStep + 1) + ' of ' + WIZARD_STEPS.length;
}

function showStep(i) {
    WIZARD_STEPS.forEach(function(s, idx) {
        var el = document.getElementById(s.id);
        if (!el) return;
        if (idx === i) {
            el.style.display = 'block';
            el.classList.remove('nsa-step-in');
            // Force a reflow so the browser registers the "before" state,
            // then add the class next frame so the CSS transition plays
            // instead of jumping straight to the end state.
            void el.offsetWidth;
            requestAnimationFrame(function() { el.classList.add('nsa-step-in'); });
        } else {
            el.style.display = 'none';
            el.classList.remove('nsa-step-in');
        }
    });
    wizardCurrentStep = i;
    var isLast = (i === WIZARD_STEPS.length - 1);
    document.getElementById('wizardBackBtn').style.display = (i === 0) ? 'none' : 'inline-flex';
    document.getElementById('wizardNextBtn').style.display = isLast ? 'none' : 'inline-flex';
    document.getElementById('wizardCreateBtn').style.display = isLast ? 'inline-flex' : 'none';
    if (isLast) {
        fillStaffPreviewData();
    }
    renderStepper();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    wizardSaveDraft();
}

function wizardGoTo(i) {
    if (i > wizardFurthestUnlocked) return; // no skipping ahead
    showStep(i);
}

function wizardBack() {
    if (wizardCurrentStep > 0) showStep(wizardCurrentStep - 1);
}

function wizardNext() {
    var el = wizardStepEl(wizardCurrentStep);
    if (!validateStepFields(el, wizardCurrentStep)) return;
    wizardCompleted[wizardCurrentStep] = true;
    if (wizardCurrentStep + 1 > wizardFurthestUnlocked) wizardFurthestUnlocked = wizardCurrentStep + 1;
    showStep(wizardCurrentStep + 1);
}

async function wizardStartOver() {
    if (!await zxConfirm({ title: 'Start over? All entered details on this form will be cleared.' })) return;
    document.getElementById('add_staff_form').reset();
    resetPhotoPreview();
    _selectedRoles = []; _primaryRole = '';
    if (typeof renderRoleChips === 'function') renderRoleChips();
    _selectedSubjects = [];
    if (typeof renderSubjectChips === 'function') renderSubjectChips();
    wizardCompleted = {};
    wizardFurthestUnlocked = 0;
    wizardDiscardDraft();
    showStep(0);
}

/* ── Validation ── */
var FIELD_VALIDATORS = [
    { id: 'name',              required: true },
    { id: 'dob',                required: true },
    { id: 'gender',             required: true },
    { id: 'religion',           required: true },
    { id: 'category',           required: true },
    { id: 'email_user',         required: true,  pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Enter a valid email address' },
    { id: 'father_name',        required: true },
    { id: 'phone_number',       required: true,  pattern: /^[6-9]\d{9}$/, message: 'Enter valid 10-digit mobile number' },
    { id: 'emergency_contact_phone', required: false, pattern: /^[6-9]\d{9}$/, message: 'Enter valid 10-digit mobile number' },
    { id: 'employment_type',    required: true },
    { id: 'teacher_department', required: true, message: 'Department is required' },
    { id: 'postal_code',        required: false, pattern: /^[1-9][0-9]{5}$/, message: 'Enter valid 6-digit PIN code' },
    { id: 'basicSalary',        required: true,  numeric: true, message: 'Enter valid basic salary' }
];

function clearFieldErrors(scopeEl) {
    var root = scopeEl || document;
    root.querySelectorAll('.nsa-input.has-error, .nsa-select.has-error').forEach(function(el) { el.classList.remove('has-error'); });
    root.querySelectorAll('.nsa-error-msg').forEach(function(el) { el.remove(); });
}

function showError(inputId, message) {
    var input = document.getElementById(inputId);
    if (!input) return;
    input.classList.add('has-error');
    var span = document.createElement('span');
    span.className = 'nsa-error-msg';
    span.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + message;
    input.parentNode.insertBefore(span, input.nextSibling);
}

function runFieldValidators(scopeEl) {
    var ok = true;
    FIELD_VALIDATORS.forEach(function(v) {
        var el = document.getElementById(v.id);
        if (!el) return;
        if (scopeEl && !scopeEl.contains(el)) return;
        var val = (el.value || '').trim();
        if (v.required && !val) {
            showError(v.id, v.message || (el.previousElementSibling && el.previousElementSibling.textContent ? el.previousElementSibling.textContent.replace('*', '').trim() + ' is required' : 'This field is required'));
            ok = false; return;
        }
        if (val && v.pattern && !v.pattern.test(val)) { showError(v.id, v.message || 'Invalid value'); ok = false; return; }
        if (val && v.numeric && isNaN(parseFloat(val))) { showError(v.id, v.message || 'Enter a valid number'); ok = false; return; }
    });
    return ok;
}

function validateFile(inputId, allowedTypes, maxSizeMB) {
    var input = document.getElementById(inputId);
    if (!input || !input.files || !input.files.length) return true;
    var file = input.files[0];
    var ok = true;
    if (!allowedTypes.includes(file.type)) { showError(inputId, 'Invalid file format'); ok = false; }
    if (file.size > maxSizeMB * 1024 * 1024) { showError(inputId, 'File must be under ' + maxSizeMB + 'MB'); ok = false; }
    return ok;
}

/* Step-scoped validation — used by "Save & Continue". */
function validateStepFields(sectionEl, stepIndex) {
    clearFieldErrors(sectionEl);
    var ok = runFieldValidators(sectionEl);

    if (WIZARD_STEPS[stepIndex].id === 'step-personal') {
        if (!validateFile('photo', ['image/jpeg', 'image/jpg'], 2)) ok = false;
    }
    if (WIZARD_STEPS[stepIndex].id === 'step-statutory') {
        if (!validateFile('aadhar', ['image/jpeg', 'image/png', 'application/pdf', 'image/jpg'], 2)) ok = false;
    }
    if (WIZARD_STEPS[stepIndex].id === 'step-employment') {
        var rolesHidden = document.getElementById('staffRolesHidden');
        if (!rolesHidden || !rolesHidden.value) {
            nsaShowAlert('error', 'Select at least one staff role.');
            ok = false;
        } else if (isTeacherSelected()) {
            var subjHidden = document.getElementById('teachingSubjectsHidden');
            if (!subjHidden || !subjHidden.value) {
                nsaShowAlert('error', 'Add at least one subject this teacher can teach.');
                ok = false;
            }
        }
    }

    if (!ok) {
        var firstErr = sectionEl.querySelector('.has-error, .nsa-error-msg');
        if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return ok;
}

/* Full-form validation — used by the Review step / Create Staff as a
   final safety net (e.g. a restored draft that skipped a step). */
function validateStaffForm() {
    clearFieldErrors(document);
    var ok = runFieldValidators(null);
    if (!validateFile('photo',  ['image/jpeg', 'image/jpg'], 2)) ok = false;
    if (!validateFile('aadhar', ['image/jpeg', 'image/png', 'application/pdf', 'image/jpg'], 2)) ok = false;

    var rolesHidden = document.getElementById('staffRolesHidden');
    if (!rolesHidden || !rolesHidden.value) ok = false;

    if (!ok) {
        // Jump to the first step that actually has an error so the user can fix it.
        for (var i = 0; i < WIZARD_STEPS.length - 1; i++) {
            if (wizardStepEl(i).querySelector('.has-error')) { wizardFurthestUnlocked = Math.max(wizardFurthestUnlocked, i); showStep(i); break; }
        }
    }
    return ok;
}

/* ── Toast notifications ── */
function nsaShowAlert(type, message) {
    var toast = document.createElement('div');
    toast.className = 'nsa-toast ' + type;
    var icons = { success:'fa-check-circle', error:'fa-times-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };
    toast.innerHTML =
        '<span class="nsa-toast-icon"><i class="fa ' + (icons[type]||'fa-info-circle') + '"></i></span>' +
        '<span class="nsa-toast-msg">' + message + '</span>';

    // Stack on top of any toast(s) already showing instead of overlapping.
    var stacked = document.querySelectorAll('.nsa-toast:not(.nsa-toast-out)').length;
    toast.style.top = (20 + stacked * 62) + 'px';

    document.body.appendChild(toast);
    setTimeout(function() {
        toast.classList.add('nsa-toast-out');
        setTimeout(function() { toast.remove(); }, 220);
    }, 3400);
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

/* ── Fill review-step data ── */
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

    /* Personal */
    setText('previewDob',        getValue('dob'));
    setText('previewGender',     getValue('gender'));
    setText('previewBloodGroup', getValue('blood_group'));
    setText('previewEmail',      getValue('email_user'));

    /* Contact / Guardian */
    setText('previewFatherName',        getValue('father_name'));
    setText('previewPhone',             getValue('phone_number'));
    setText('previewAltPhone',          getValue('alt_phone'));
    setText('previewMaritalStatus',     getValue('marital_status'));
    setText('previewEmergencyName',     getValue('emergency_contact_name'));
    setText('previewEmergencyPhone',    getValue('emergency_contact_phone'));
    setText('previewEmergencyRelation', getValue('emergency_contact_relation'));

    /* Employment */
    setText('previewDesignation',    getValue('designation'));
    setText('previewEmploymentType', getValue('employment_type'));
    setText('previewExperience',     getValue('teacher_experience') + ' yrs');

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
    setText('previewQualification',  getValue('qualification'));
    setText('previewUniversity',     getValue('university'));
    setText('previewYearOfPassing',  getValue('year_of_passing'));

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
    var previewImg   = document.getElementById('reviewStaffPhotoImg');
    if (staffPhotoEl && previewImg) previewImg.src = staffPhotoEl.src;

    /* Documents */
    nsaHandleDocPreview('photo',  'previewPhotoName',  'previewPhotoView');
}

function nsaHandleDocPreview(inputId, nameId, viewId) {
    var input   = document.getElementById(inputId);
    var nameEl  = document.getElementById(nameId);
    var viewBtn = document.getElementById(viewId);
    if (!nameEl || !viewBtn) return;
    // DOC-D1: a row whose input has been removed must degrade to
    // "Not Uploaded", never keep its hardcoded markup label.
    if (!input) { nameEl.textContent = 'Not Uploaded'; viewBtn.style.display = 'none'; return; }
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

/* ── Final submit via AJAX (Create Staff, on the Review step) ── */
function submitStaffFinalForm() {
    if (!validateStaffForm()) { nsaShowAlert('error', 'Please fix the highlighted fields before creating the staff record.'); return; }

    var form = document.getElementById('add_staff_form');
    if (!form) { nsaShowAlert('error', 'Form not found — please refresh.'); return; }

    var btn = document.getElementById('wizardCreateBtn');
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
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Create Staff'; }
                return;
            }
            if (res.status === 'success') {
                wizardDiscardDraft();

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
                            // ATS-D2: the generated password is a ONE-TIME reveal. This path
                            // used to return before showStaffConfirmation() and navigate away
                            // on a timer, so an ATS hire never showed its credentials while a
                            // direct create did. The modal owns the redirect now, so nothing
                            // races the admin while they copy them.
                            showStaffConfirmation(res, '<?= base_url("ats") ?>');
                        },
                        error: function() {
                            nsaShowAlert('warning', 'Staff created but hire finalization failed. Please update ATS manually.');
                            // ATS-D2: the staff record and its Auth account exist even when
                            // finalize_hire fails, so the credentials must still be handed over.
                            showStaffConfirmation(res, '<?= base_url("ats") ?>');
                        }
                    });
                    return;
                }

                showStaffConfirmation(res);
            } else {
                nsaShowAlert('error', res.message || 'Submission failed. Please try again.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Create Staff'; }
            }
        },
        error: function() {
            nsaShowAlert('error', 'Server error — please try again.');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Create Staff'; }
        }
    });
}

/* ═══════════════════════════════════════════════════════════════
   DRAFT AUTOSAVE — client-side only (localStorage). Text/select
   values and the role/subject/step state are saved; files can't be
   persisted this way and are intentionally excluded (the browser
   never exposes a way to restore a File object from storage), so a
   restored draft always leaves the Photo/Aadhar uploads for the
   user to re-attach. Nothing here touches the server.
═══════════════════════════════════════════════════════════════ */
var DRAFT_KEY = 'zenx_new_staff_draft_v1';
var DRAFT_FIELD_IDS = [
    'name','dob','gender','blood_group','email_user','religion','category',
    'father_name','phone_number','alt_phone','marital_status',
    'emergency_contact_name','emergency_contact_phone','emergency_contact_relation',
    'teacher_department','designation','reporting_manager_id','location_id',
    'employment_type','teacher_experience',
    'staffRolesHidden','primaryRoleHidden','teachingSubjectsHidden',
    'pan_number','aadhar_number','pf_number','esi_number',
    'street','state','city','postal_code',
    'perm_street','perm_state','perm_city','perm_postal_code',
    'qualification','university','year_of_passing',
    'basicSalary','allowances',
    'bank_name','account_holder','account_number','bank_ifsc'
];
var _draftSaveTimer = null;
// True once there's nothing left to decide: either no draft existed at
// boot, or the user explicitly restored/discarded the one that did. While
// false (a restore banner is showing, undecided), autosave is suppressed —
// otherwise the very first debounced save after boot would silently
// overwrite the saved draft with the blank new form before the user ever
// gets a chance to click "Restore Draft".
var _draftDecided = true;
function wizardSaveDraft() {
    if (!_draftDecided) return;
    clearTimeout(_draftSaveTimer);
    _draftSaveTimer = setTimeout(function() {
        try {
            var data = {};
            DRAFT_FIELD_IDS.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) data[id] = el.value;
            });
            data._sameAsCurrent = !!(document.getElementById('same_as_current') && document.getElementById('same_as_current').checked);
            data._step = wizardCurrentStep;
            data._furthest = wizardFurthestUnlocked;
            data._completed = wizardCompleted;
            data._savedAt = new Date().toISOString();
            localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
        } catch (e) { /* storage unavailable — draft feature degrades silently */ }
    }, 350);
}

function wizardLoadDraftRaw() {
    try {
        var raw = localStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
}

function wizardDiscardDraft() {
    try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
    var banner = document.getElementById('draftBanner');
    if (banner) banner.style.display = 'none';
    _draftDecided = true;
}

function wizardRestoreDraft() {
    var data = wizardLoadDraftRaw();
    if (!data) { _draftDecided = true; return; }
    _draftDecided = true;
    DRAFT_FIELD_IDS.forEach(function(id) {
        var el = document.getElementById(id);
        if (el && data[id] !== undefined) el.value = data[id];
    });
    // Department is a select whose <option>s load asynchronously (same
    // fetch that resolves the ATS-prefill deferred value below) — if it
    // hasn't finished yet, setting .value here is a silent no-op. Defer to
    // the same _pendingDeptValue mechanism the department fetch already
    // applies once its options exist.
    if (data.teacher_department) {
        var deptSel = document.getElementById('teacher_department');
        if (_deptLoaded && deptSel) {
            deptSel.value = data.teacher_department;
        } else {
            _pendingDeptValue = data.teacher_department;
        }
    }
    if (data._sameAsCurrent) {
        var chk = document.getElementById('same_as_current');
        if (chk) { chk.checked = true; chk.dispatchEvent(new Event('change')); }
    }
    // Rebuild role/subject chips from the restored hidden fields.
    var rolesVal = document.getElementById('staffRolesHidden').value;
    _selectedRoles = rolesVal ? rolesVal.split(',').filter(Boolean) : [];
    _primaryRole = document.getElementById('primaryRoleHidden').value || (_selectedRoles[0] || '');
    var subjVal = document.getElementById('teachingSubjectsHidden').value;
    _selectedSubjects = subjVal ? subjVal.split(',').filter(Boolean) : [];

    updateNetSalary();
    document.getElementById('draftBanner').style.display = 'none';

    // Chips/department-dependent UI render once departments & roles finish loading.
    var tryRenderChips = function() {
        if (typeof renderRoleChips === 'function') renderRoleChips();
        if (typeof renderSubjectChips === 'function') renderSubjectChips();
        if (typeof recheckTeachingVisibility === 'function') recheckTeachingVisibility();
    };
    if (_deptLoaded) tryRenderChips(); else setTimeout(tryRenderChips, 800);

    wizardFurthestUnlocked = data._furthest || 0;
    wizardCompleted = data._completed || {};
    showStep(data._step || 0);
    nsaShowAlert('success', 'Draft restored.');
}

function wizardCheckForDraft() {
    var data = wizardLoadDraftRaw();
    if (!data) return;
    var hasContent = DRAFT_FIELD_IDS.some(function(id) { return data[id]; });
    if (!hasContent) return;
    // Suspend autosave until the user decides — otherwise showStep(0)'s own
    // draft save (fired moments later, from the blank form) would silently
    // overwrite this very draft before the banner is even read.
    _draftDecided = false;
    var when = '';
    try { when = ' (saved ' + new Date(data._savedAt).toLocaleString() + ')'; } catch (e) {}
    document.getElementById('draftBannerWhen').textContent = when;
    document.getElementById('draftBanner').style.display = 'flex';
}

/* ═══════════════════════════
   DOMContentLoaded Init
═══════════════════════════ */
var _deptLoaded = false;
var _pendingDeptValue = null; // set by ATS prefill or a restored draft when the department <select>'s options haven't loaded yet
var _selectedRoles = [];
var _primaryRole = '';
var _selectedSubjects = [];

document.addEventListener('DOMContentLoaded', function() {

    /* ── Permanent address "same as current" toggle ── */
    var sameChk = document.getElementById('same_as_current');
    var permBlock = document.getElementById('permanent_address_block');
    if (sameChk && permBlock) {
        sameChk.addEventListener('change', function() {
            if (this.checked) {
                permBlock.style.display = 'none';
                document.getElementById('perm_street').value = document.getElementById('street').value;
                document.getElementById('perm_state').value = document.getElementById('state').value;
                document.getElementById('perm_postal_code').value = document.getElementById('postal_code').value;
                // Mirror district into the permanent select, cascading off the copied state.
                if (window.IndiaGeo) {
                    IndiaGeo.fillDistricts(document.getElementById('perm_state'), document.getElementById('perm_city'), document.getElementById('city').value);
                } else {
                    document.getElementById('perm_city').value = document.getElementById('city').value;
                }
            } else {
                permBlock.style.display = '';
            }
        });
    }

    /* ── Reporting Manager (Phase 2C) — reuses the existing HR staff-list
       endpoint (same one the Head-of-Department picker already uses), which
       is already same-school-scoped and Active-only server-side. Server-side
       validation on submit is authoritative regardless of this list. ── */
    (function() {
        var sel = document.getElementById('reporting_manager_id');
        if (!sel) return;
        fetch('<?= base_url("hr/get_staff_list") ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                var staff = resp.staff || (resp.data && resp.data.staff) || [];
                staff.forEach(function(s) {
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name + (s.position ? ' — ' + s.position : '');
                    sel.appendChild(opt);
                });
                var draft = wizardLoadDraftRaw();
                if (draft && draft.reporting_manager_id) sel.value = draft.reporting_manager_id;
            })
            .catch(function() { /* non-fatal — field stays optional */ });
    })();

    /* ── Load departments from HR module into dropdown ── */
    var _deptRoles = {};   // department name → allowed staff-role IDs (Departments & Roles)
    (function(){
        var sel = document.getElementById('teacher_department');
        if (!sel) return;
        fetch('<?= base_url("hr/get_departments") ?>')
            .then(function(r){ return r.json(); })
            .then(function(r){
                if (r.status !== 'success') return;
                var depts = r.departments || [];
                depts.forEach(function(d){
                    _deptRoles[d.name] = Array.isArray(d.role_ids) ? d.role_ids : [];
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
                if (typeof refreshRoleOptions === 'function') refreshRoleOptions();
            })
            .catch(function(){ _deptLoaded = true; });
        // Re-scope the role picker whenever the department changes.
        sel.addEventListener('change', function(){
            if (typeof onDepartmentChanged === 'function') onDepartmentChanged();
        });
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
                    'experience': 'teacher_experience',
                    /* ATS-D1: get_convert_data() has always emitted the job title as
                       `staff_position`, but nothing consumed it, so the single most
                       job-specific field arrived blank on a screen that announces
                       itself as pre-filled. `designation` is the canonical label -
                       when it is empty Staff.php falls back to a generic role name
                       and _infer_roles_from_position() has nothing to work from. */
                    'staff_position': 'designation'
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

    /* ── Draft autosave: any field change persists to localStorage ── */
    document.getElementById('add_staff_form').addEventListener('input', wizardSaveDraft);
    document.getElementById('add_staff_form').addEventListener('change', wizardSaveDraft);

    /* ── Staff Role Picker (multi-select chips) ── */
    var _roleData = {};

    function loadStaffRoles() {
        $.getJSON('<?= base_url("staff/get_staff_roles") ?>', function(d) {
            if (d.status !== 'success' || !d.roles) return;
            _roleData = d.roles;
            refreshRoleOptions();
            // A restored draft may have rendered chips before role labels
            // loaded (falling back to raw role ids) — refresh them now.
            if (_selectedRoles.length && typeof renderRoleChips === 'function') renderRoleChips();
        });
    }
    loadStaffRoles();

    /* Roles allowed for the currently chosen department (STRICT — matches the
     * Excel import template and Departments & Roles):
     *   - no department chosen        → null (picker locked, "choose department first")
     *   - department has a mapping     → just those roles
     *   - department has NO mapping    → [] (none; set roles for it in Departments & Roles)
     * "role is a subset of department": you pick the department, then its roles.  */
    function allowedRoleIds() {
        var deptSel = document.getElementById('teacher_department');
        var dept = deptSel ? deptSel.value : '';
        if (!dept) return null;
        var ids = _deptRoles[dept];
        if (ids && ids.length) return ids.filter(function(id){ return _roleData[id]; });
        return []; // dept not mapped yet → no roles (configure in Departments & Roles)
    }

    function refreshRoleOptions() {
        var sel = document.getElementById('staffRoleSelect');
        if (!sel) return;
        var allowed = allowedRoleIds();
        if (allowed === null) {
            sel.innerHTML = '<option value="">Select a department first…</option>';
            sel.disabled = true;
            return;
        }
        // STAFF-D1: a department with NO role_ids mapped used to fall through
        // to an empty "+ Add role..." list — a silent dead end on a REQUIRED
        // field, with nothing telling the operator why or where to fix it.
        if (!allowed.length) {
            sel.innerHTML = '<option value="">No roles mapped to this department &mdash; set them in HR &rarr; Departments</option>';
            sel.disabled = true;
            return;
        }
        sel.disabled = false;
        sel.innerHTML = '<option value="">+ Add role...</option>';
        allowed.forEach(function(rid) {
            var r = _roleData[rid]; if (!r) return;
            var opt = document.createElement('option');
            opt.value = rid;
            opt.textContent = r.label + ' (' + r.category + ')';
            sel.appendChild(opt);
        });
    }
    window.refreshRoleOptions = refreshRoleOptions;

    // When the department changes, drop any picked roles that no longer belong
    // to it (unless the dept is unmapped → all allowed), then rebuild options.
    function onDepartmentChanged() {
        var allowed = allowedRoleIds();
        if (allowed) {
            var before = _selectedRoles.length;
            _selectedRoles = _selectedRoles.filter(function(r){ return allowed.indexOf(r) !== -1; });
            if (_primaryRole && allowed.indexOf(_primaryRole) === -1) _primaryRole = _selectedRoles[0] || '';
            if (_selectedRoles.length !== before) renderRoleChips();
        }
        refreshRoleOptions();
    }
    window.onDepartmentChanged = onDepartmentChanged;

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
        wizardSaveDraft();
    }
    window.renderRoleChips = renderRoleChips;

    /* ── Teacher-specific fields: show/hide based on Position ── */

    // ─── Teaching section toggle ─────────────────────────────────────
    // Show teaching fields if ROLE_TEACHER is in staff_roles
    /* CT-D1: does this role TEACH? Gated on the catalogue's own declaration
       rather than one hardcoded id. ROLE_CLASS_TEACHER ships with the SAME
       category ('Teaching') and the SAME flags (['can_teach', ...]) as
       ROLE_TEACHER, so hiding Teaching Subjects from it left a Class Teacher
       unable to hold teaching_subjects — while Academic's picker still
       offered them (Position falls back to the role label "Class Teacher",
       which the designation regex matches) and the assignment write then
       refused with "Add this subject to their teaching_subjects in Staff
       profile first" — the very field this gate hid. A closed loop.
       Both arms are needed: shipped roles carry flags, custom roles
       (Staff_access::save_role) are written WITHOUT a flags key. Falls back
       to the two shipped teaching ids before the catalogue loads, so
       behaviour is never worse than before. */
    function roleCanTeach(rid) {
        var r = (typeof _roleData !== 'undefined' && _roleData) ? _roleData[rid] : null;
        if (!r) return rid === 'ROLE_TEACHER' || rid === 'ROLE_CLASS_TEACHER';
        var f = r.flags;
        if (f && typeof f.indexOf === 'function' && f.indexOf('can_teach') !== -1) return true;
        return (r.category || '') === 'Teaching';
    }

    function isTeacherSelected() {
        var rolesHidden = document.getElementById('staffRolesHidden');
        if (rolesHidden && rolesHidden.value) {
            var roles = rolesHidden.value.split(',').map(function(r){ return r.trim(); });
            for (var i = 0; i < roles.length; i++) {
                if (roleCanTeach(roles[i])) return true;
            }
        }
        return false;
    }
    window.isTeacherSelected = isTeacherSelected;

    function recheckTeachingVisibility() {
        var section = document.getElementById('teacherExtraFields');
        if (!section) return;
        var show = isTeacherSelected();
        section.style.display = show ? 'block' : 'none';
        if (show) {
            loadSubjectOptions();
        }
    }
    window.recheckTeachingVisibility = recheckTeachingVisibility;

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
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:var(--gold-dim,rgba(188,90,60,.1));color:var(--gold,#BC5A3C);border-radius:14px;font-size:12px;font-weight:500;';
            chip.innerHTML = s + ' <button type="button" style="background:none;border:none;color:inherit;cursor:pointer;font-weight:700;padding:0 2px;" onclick="removeSubject(\'' + s.replace(/'/g,"\\'") + '\')">&times;</button>';
            container.appendChild(chip);
        });
        document.getElementById('teachingSubjectsHidden').value = _selectedSubjects.join(',');
        wizardSaveDraft();
    }
    window.renderSubjectChips = renderSubjectChips;

    window.removeSubject = function(s) {
        _selectedSubjects = _selectedSubjects.filter(function(x){ return x !== s; });
        renderSubjectChips();
    };

    /* ── Wizard boot ── */
    wizardCheckForDraft();
    showStep(0);
});
</script>


<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Lora:wght@500;600;700&display=swap');

:root {
    --nsa-navy:   #2A1C12;
    --nsa-green:  #2FA875;
    --nsa-teal:   #BC5A3C;
    --nsa-sky:    #F7ECE7;
    --nsa-dark:   #2FA875;
    --nsa-red:    #dc2626;
    --nsa-amber:  #d97706;
    --nsa-text:   #22160F;
    --nsa-muted:  #7A6A5E;
    --nsa-border: #E7DED6;
    --nsa-white:  #ffffff;
    --nsa-bg:     #F7F4F1;
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
.nsa-topbar { margin-bottom: 20px; }
.nsa-topbar-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
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

/* ── Draft banner ── */
.nsa-draft-banner {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: linear-gradient(135deg, rgba(188,90,60,.10), rgba(47,168,117,.08));
    border: 1.5px solid rgba(188,90,60,.25);
    border-radius: var(--nsa-radius);
    padding: 12px 18px;
    margin-bottom: 18px;
    font-size: 13px; color: var(--nsa-text);
}
.nsa-draft-banner i { color: var(--nsa-teal); font-size: 16px; }
.nsa-draft-banner-actions { margin-left: auto; display: flex; gap: 8px; }
.nsa-draft-banner .nsa-btn { padding: 6px 14px; font-size: 12.5px; }

/* ── Stepper ── */
.nsa-stepper {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 4px;
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: var(--nsa-shadow);
    padding: 18px 16px 14px;
    overflow-x: auto;
}
.nsa-step {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    flex: 1; min-width: 84px; position: relative; text-align: center;
}
.nsa-step.clickable { cursor: pointer; }
.nsa-step:not(:last-child)::after {
    content: ''; position: absolute; top: 15px; left: calc(50% + 21px); right: calc(-50% + 21px);
    height: 2px; background: var(--nsa-border); z-index: 0;
}
.nsa-step.completed:not(:last-child)::after { background: var(--nsa-teal); }
.nsa-step-circle {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 700;
    background: var(--nsa-bg); border: 2px solid var(--nsa-border); color: var(--nsa-muted);
    z-index: 1; transition: all .15s;
}
.nsa-step.active .nsa-step-circle { border-color: var(--nsa-teal); background: var(--nsa-teal); color: #fff; box-shadow: 0 0 0 4px rgba(188,90,60,.15); }
.nsa-step.completed .nsa-step-circle { border-color: var(--nsa-teal); background: #fff; color: var(--nsa-teal); }
.nsa-step-label { font-size: 10.5px; font-weight: 600; color: var(--nsa-muted); max-width: 100px; line-height: 1.25; transition: color .15s; }
.nsa-step.active .nsa-step-label { color: var(--nsa-navy); }
.nsa-step.completed .nsa-step-label { color: var(--nsa-teal); }
.nsa-step.clickable:hover .nsa-step-circle { transform: scale(1.1); border-color: var(--nsa-teal); }
.nsa-step.clickable:hover .nsa-step-label { color: var(--nsa-teal); }

.nsa-stepper-progress { height: 4px; background: var(--nsa-border); border-radius: 4px; margin: 8px 0 22px; overflow: hidden; }
.nsa-stepper-progress-bar { height: 100%; background: var(--nsa-teal); transition: width .25s ease; }

/* ── Main ── */
.nsa-main { display: flex; flex-direction: column; gap: 18px; }
.nsa-step-panel {
    display: none;
    opacity: 0;
    transform: translateY(10px);
    transition: opacity .28s ease, transform .28s ease;
}
.nsa-step-panel.nsa-step-in { opacity: 1; transform: translateY(0); }
@media (prefers-reduced-motion: reduce) {
    .nsa-step-panel { transition: none; }
}

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
.nsa-section-hint { font-size: 12px; color: var(--nsa-muted); margin-left: auto; }
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
    box-shadow: 0 0 0 3px rgba(188,90,60,.1);
    background: #fff;
}
.nsa-input[readonly] {
    background: #f1f5f9;
    color: var(--nsa-muted);
    cursor: not-allowed;
}
.nsa-input.has-error, .nsa-select.has-error { border-color: var(--nsa-red); animation: nsa-shake .32s ease; }
.nsa-error-msg {
    font-size: 11.5px; color: var(--nsa-red);
    display: flex; align-items: center; gap: 4px;
    margin-top: 2px;
    animation: nsa-fade-in .18s ease;
}
@keyframes nsa-shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-3px); }
    75% { transform: translateX(3px); }
}
@keyframes nsa-fade-in {
    from { opacity: 0; transform: translateY(-2px); }
    to   { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
    .nsa-input.has-error, .nsa-select.has-error { animation: none; }
    .nsa-error-msg { animation: none; }
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
    background: linear-gradient(135deg, var(--nsa-sky) 0%, #F3E4DC 100%);
    border: 1.5px solid rgba(188,90,60,.25);
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

/* ── Wizard footer ── */
.nsa-wizard-footer {
    position: sticky; bottom: 12px;
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: 0 4px 20px rgba(12,30,56,.14);
    padding: 14px 22px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    z-index: 20;
}
.nsa-wizard-footer-status { font-size: 12.5px; color: var(--nsa-muted); font-weight: 600; }

/* ── Pointer to where documents actually live (UX-D3) ── */
.nsa-doc-pointer {
    display: flex; align-items: flex-start; gap: 9px;
    padding: 11px 13px; border-radius: 9px;
    background: var(--nsa-bg, #f8fafc);
    border: 1px dashed var(--nsa-border, #e2e8f0);
    font-size: 12.5px; line-height: 1.5; color: var(--nsa-muted, #64748b);
}
.nsa-doc-pointer > i { color: var(--nsa-teal, #BC5A3C); margin-top: 1px; }

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
.nsa-btn-primary { background: var(--nsa-teal); color: #fff; box-shadow: 0 2px 10px rgba(188,90,60,.28); }
.nsa-btn-ghost   { background: var(--nsa-white); color: var(--nsa-text); border: 1.5px solid var(--nsa-border); }
.nsa-btn-ghost:hover { border-color: var(--nsa-teal); color: var(--nsa-teal); }
.nsa-btn-success { background: var(--nsa-dark); color: #fff; }

/* ── Review step (formerly a modal — now inline) ── */
.nsa-preview-hero {
    display: flex; align-items: center; gap: 20px;
    padding: 18px 4px 22px;
    border-bottom: 1px solid var(--nsa-border);
    margin: 0 -4px 20px;
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
    background: rgba(188,90,60,.1); color: var(--nsa-teal);
    border: 1px solid rgba(188,90,60,.2);
}

.nsa-review-body {
    padding-bottom: 8px;
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px;
}
@media (max-width: 860px) { .nsa-review-body { grid-template-columns: 1fr; } }

/* Preview sections — confirmation cards, not a dense label/value sheet */
.nsa-prev-section {
    margin-bottom: 0;
    background: var(--nsa-bg);
    border: 1px solid var(--nsa-border);
    border-radius: 12px;
    padding: 16px 18px;
    position: relative;
    overflow: hidden;
    transition: border-color .15s, box-shadow .15s;
}
.nsa-prev-section::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    background: var(--nsa-teal);
}
.nsa-prev-section:hover { border-color: rgba(188,90,60,.35); box-shadow: 0 2px 10px rgba(188,90,60,.08); }
/* Documents card spans full width — it's a short summary, not a data grid */
.nsa-prev-section:last-child { grid-column: 1 / -1; }
.nsa-prev-section-title {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px;
    color: var(--nsa-navy);
    margin-bottom: 12px;
    display: flex; align-items: center; gap: 9px;
}
.nsa-prev-section-title i {
    width: 26px; height: 26px; border-radius: 8px; flex-shrink: 0;
    background: var(--nsa-sky); color: var(--nsa-teal);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
}
.nsa-prev-grid {
    display: grid; grid-template-columns: repeat(2, 1fr);
    gap: 10px 16px;
}
@media (max-width: 600px) { .nsa-prev-grid { grid-template-columns: 1fr; } }
.nsa-prev-field .lbl {
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    color: var(--nsa-muted); margin-bottom: 2px;
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
    background: var(--nsa-white); border: 1px solid var(--nsa-border);
    font-size: 12.5px; font-weight: 500;
}
.nsa-prev-doc-item i { color: var(--nsa-teal); }
.nsa-prev-doc-item a { color: var(--nsa-teal); text-decoration: none; font-size: 11.5px; margin-left: 4px; }
.nsa-prev-doc-item a:hover { text-decoration: underline; }

/* ── Toast ── */
.nsa-toast {
    position: fixed; right: 20px;
    padding: 13px 18px 13px 14px; border-radius: 12px;
    font-size: 13.5px; font-weight: 500;
    color: #fff; z-index: 99999;
    box-shadow: 0 10px 28px rgba(0,0,0,.2), 0 2px 8px rgba(0,0,0,.1);
    display: flex; align-items: center; gap: 10px;
    animation: nsa-toast-in .32s cubic-bezier(.34,1.56,.64,1);
    max-width: 360px;
    transition: top .2s ease;
}
.nsa-toast.nsa-toast-out { animation: nsa-toast-out .2s ease forwards; }
.nsa-toast-icon {
    width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0;
    background: rgba(255,255,255,.22);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
}
.nsa-toast-msg { line-height: 1.4; }
@keyframes nsa-toast-in {
    from { transform: translateX(24px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}
@keyframes nsa-toast-out {
    from { transform: translateX(0);    opacity: 1; }
    to   { transform: translateX(24px); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .nsa-toast, .nsa-toast.nsa-toast-out { animation: none; }
}
.nsa-toast.success { background: var(--nsa-dark); }
.nsa-toast.error   { background: var(--nsa-red);  }
.nsa-toast.warning { background: var(--nsa-amber); }

/* ── ATS Prefill Banner ── */
.nsa-ats-banner {
    background: linear-gradient(135deg, rgba(188,90,60,.10), rgba(21,128,61,.10));
    border: 1.5px solid rgba(188,90,60,.25);
    border-radius: 10px;
    padding: 12px 18px;
    margin-bottom: 16px;
    font: 500 13px/1.5 var(--nsa-font, 'Plus Jakarta Sans', sans-serif);
    color: var(--nsa-text, #22160F);
    display: flex; align-items: center; gap: 8px;
}
.nsa-ats-banner i { color: #BC5A3C; font-size: 16px; }

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
    background: var(--nsa-sky, #F7ECE7); color: var(--nsa-teal, #BC5A3C);
    border: 1px solid rgba(188,90,60,.2); cursor: pointer; transition: all .15s;
}
.nsa-role-chip:hover { background: rgba(188,90,60,.15); }
.nsa-role-chip.nsa-role-primary {
    background: var(--nsa-teal, #BC5A3C); color: #fff;
    border-color: var(--nsa-teal, #BC5A3C);
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
    background:var(--bg3,#f5f5f5);border:1.5px solid var(--gold,#BC5A3C);
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
.sf-btn-print { background:var(--gold,#BC5A3C);color:#fff; }
.sf-btn-print:hover { opacity:.85; }
.sf-btn-done { background:var(--bg3,#f5f5f5);color:var(--t1,#111);border:1px solid var(--border,#ddd); }
.sf-btn-done:hover { background:var(--bg2,#eee); }
@keyframes sfFadeIn { from{opacity:0} to{opacity:1} }
@keyframes sfScaleIn { from{transform:scale(.9);opacity:0} to{transform:scale(1);opacity:1} }
</style>

<script>
function showStaffConfirmation(res, doneRedirectUrl) {
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
                '<i class="fa fa-info-circle" style="margin-right:5px;color:var(--gold,#BC5A3C);"></i>' +
                'Staff can login using <strong>Staff ID</strong> and <strong>Password</strong> in the ZenXii Teacher App. ' +
                'They will be asked to <strong>set a new password</strong> on first login.' +
            '</div>' +
            '<div class="sf-actions">' +
                '<button class="sf-btn sf-btn-print" onclick="printStaffSlip()"><i class="fa fa-print"></i> Print Slip</button>' +
                '<button class="sf-btn sf-btn-done" onclick="closeStaffConfirm()"><i class="fa fa-check"></i> Done</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    window._staffSlipData = res;
    // ATS-D2: where Done should go. Null = reload (direct-create behaviour).
    window._staffConfirmRedirect = doneRedirectUrl || null;
}
function closeStaffConfirm() {
    var el = document.querySelector('.staff-confirm-overlay');
    if (el) el.remove();
    var to = window._staffConfirmRedirect;
    window._staffConfirmRedirect = null;
    if (to) { window.location.href = to; return; }
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
        '<div class="note"><strong>Note:</strong> Use Staff ID and Password to login in the ZenXii Teacher App.</div>' +
        '</body></html>'
    );
    w.document.close();
    setTimeout(function(){ w.print(); }, 300);
}
function nsaEsc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
