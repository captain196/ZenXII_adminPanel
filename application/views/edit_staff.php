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

    <?php $pendingScheduleReview = $staff_data['pendingScheduleReview'] ?? null; ?>
    <?php if (!empty($hr_manage) && is_array($pendingScheduleReview)): ?>
    <!-- ══ PENDING WORK SCHEDULE REVIEW BANNER (Trail 2 Priority 3/4,
         2026-08-19) ══ Persistent (Firestore-backed staff.pendingScheduleReview),
         only shown to an admin who holds HR:manage (the same authority
         needed to actually resolve it via Work Schedules). Set when a
         post-transfer schedule composition attempt fails; cleared by
         Dismiss or the next successful composition attempt. -->
    <div class="nsa-alert-banner" id="scheduleReviewBanner" style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
        <i class="fa fa-exclamation-triangle" style="font-size:18px;flex-shrink:0;"></i>
        <div style="flex:1;font-size:13px;line-height:1.5;">
            <strong>Work Schedule needs review.</strong>
            <?= htmlspecialchars((string) ($pendingScheduleReview['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($pendingScheduleReview['effectiveFrom'])): ?>
                (intended effective <?= htmlspecialchars((string) $pendingScheduleReview['effectiveFrom'], ENT_QUOTES, 'UTF-8') ?>)
            <?php endif; ?>
            <?php if (!empty($pendingScheduleReview['since'])): ?>
                <span style="opacity:.75;">— flagged <?= htmlspecialchars(date('Y-m-d', strtotime((string) $pendingScheduleReview['since'])), ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
        <a class="nsa-btn nsa-btn-ghost" style="white-space:nowrap;" href="<?= base_url('work_schedules') ?>" target="_blank" rel="noopener">
            <i class="fa fa-external-link"></i> Go to Work Schedules
        </a>
        <button type="button" class="nsa-btn nsa-btn-ghost" style="white-space:nowrap;" onclick="dismissScheduleReview()">Dismiss</button>
    </div>
    <?php endif; ?>

    <?php if (!empty($teaching_needs_assignment)): ?>
    <!-- ══ CROSS-D1: the hire is not finished ══
         A teaching hire with no subjectAssignments row for this session has a
         working login into an empty app. Advisory only — it never blocks the
         profile, and it links to the module that actually resolves it. -->
    <div class="nsa-alert-banner" id="teachingAssignmentBanner" style="background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
        <i class="fa fa-graduation-cap" style="font-size:18px;flex-shrink:0;"></i>
        <div style="flex:1;font-size:13px;line-height:1.5;">
            <strong>This teacher has no classes yet.</strong>
            They can sign in to the Teacher app, but it will show no classes, no
            students and no timetable until they are assigned a subject and section
            for this session.
        </div>
        <a class="nsa-btn nsa-btn-ghost" style="white-space:nowrap;" href="<?= base_url('academic') ?>" target="_blank" rel="noopener">
            <i class="fa fa-external-link"></i> Assign subjects
        </a>
    </div>
    <?php endif; ?>

    <!-- ── Layout ── -->
    <div class="nsa-layout">

        <!-- The sidebar jump-nav was replaced by the stepper below: two
             navigators for the same ten sections is the duplication this screen
             was already suffering from, and the old list had drifted anyway —
             it never listed Statutory ID, and could not list Employee Lifecycle. -->

        <!-- Main form -->
        <div class="nsa-main">

            <!-- ── Stepper (edit mode: every step reachable) ── -->
            <div class="nsa-stepper" id="editStepper"></div>
            <div class="nsa-stepper-progress"><div class="nsa-stepper-progress-bar" id="editProgressBar"></div></div>
            <form action="<?= base_url('staff/edit_staff/' . htmlspecialchars($staff_data['User ID'])) ?>"
                  method="post"
                  id="edit_staff_form"
                  enctype="multipart/form-data">
                  <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" 
           value="<?= $this->security->get_csrf_hash() ?>">

                <!-- ══ BASIC INFORMATION ══ -->
                <div class="nsa-section nsa-step-panel" id="sec-basic" data-step="0">
                    <div class="nsa-section-head">
                        <i class="fa fa-user"></i>
                        <h3>Basic Information</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid">

                            <div class="nsa-field">
                                <label for="user_id">Staff ID</label>
                                <input type="text" name="user_id" id="user_id"
                                       value="<?= htmlspecialchars($staff_data['User ID'] ?? '') ?>"
                                       class="nsa-input" readonly>
                            </div>

                            <div class="nsa-field">
                                <label for="name">Staff Name <span class="req">*</span></label>
                                <input type="text" id="name" name="Name"
                                       value="<?= htmlspecialchars($staff_data['Name'] ?? '') ?>"
                                       class="nsa-input" placeholder="Full name" required>
                            </div>

                            <div class="nsa-field">
                                <label for="dob">Date of Birth</label>
                                <input type="date" id="dob" name="DOB"
                                       value="<?= !empty($staff_data['DOB']) ? date('Y-m-d', strtotime($staff_data['DOB'])) : '' ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="email_user">Email</label>
                                <input type="email" id="email_user" name="Email"
                                       value="<?= htmlspecialchars($staff_data['Email'] ?? '') ?>"
                                       class="nsa-input" placeholder="staff@email.com" required>
                            </div>

                            <div class="nsa-field">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" class="nsa-select" required>
                                    <option value="">Select Gender</option>
                                    <?php foreach (['Male','Female','Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($staff_data['Gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label for="blood_group">Blood Group</label>
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
                                <label for="religion">Religion</label>
                                <select id="religion" name="religion" class="nsa-select" required>
                                    <option value="">Select Religion</option>
                                    <?php foreach (['Hindu','Muslim','Sikh','Jain','Buddh','Christian','Other'] as $r): ?>
                                    <option value="<?= $r ?>" <?= ($staff_data['Religion'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label for="category">Category</label>
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
                            <!-- Staff roles are chosen here in the picker below;
                                 the first chip is the primary role. -->
                            <input type="hidden" name="staff_roles" id="staffRolesHidden"
                                   value="<?= htmlspecialchars(implode(',', $existingRoles)) ?>">
                            <input type="hidden" name="primary_role" id="primaryRoleHidden"
                                   value="<?= htmlspecialchars($existingPrimary) ?>">

                            <div class="nsa-field nsa-col-2">
                                <label for="staffRoleSelect">Staff Role <span class="req">*</span></label>
                                <select id="staffRoleSelect" class="nsa-select">
                                    <option value="">+ Add role (Teacher, Accountant, Librarian, etc.)...</option>
                                </select>
                                <div id="selectedRolesChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                                    <span id="noRolesHint" style="display:none;font-size:12px;color:var(--nsa-red,#dc2626);">
                                        <i class="fa fa-exclamation-circle"></i> No role yet — add at least one.
                                    </span>
                                </div>
                                <small style="color:var(--nsa-muted);font-size:11px;">
                                    <i class="fa fa-info-circle"></i>
                                    Pick one or more roles. The first (&#9733;) is the primary designation. Adding a <strong>Teacher</strong> role reveals the subjects field below.
                                </small>
                            </div>

                            <div class="nsa-field">
                                <label for="date_of_joining">Date of Joining</label>
                                <input type="date" id="date_of_joining" name="date_of_joining"
                                       value="<?= !empty($staff_data['Date Of Joining']) ? date('Y-m-d', strtotime($staff_data['Date Of Joining'])) : '' ?>"
                                       class="nsa-input" readonly>
                            </div>

                            <div class="nsa-field">
                                <label for="employment_type">Employment Type</label>
                                <?php
                                // UX-D2. A stored value outside this list is KEPT and shown as
                                // selected: 9 staff have none and several carry legacy spellings,
                                // and silently rewriting somebody's employment type because the
                                // dropdown had no matching option would be a data change nobody
                                // asked for.
                                $etTypes   = ['Full-time', 'Part-time', 'Contract', 'Probation', 'Temporary', 'Consultant', 'Intern'];
                                $etCurrent = (string) ($staff_data['Employment Type'] ?? '');
                                $etKnown   = in_array($etCurrent, $etTypes, true);
                                ?>
                                <select id="employment_type" name="employment_type" class="nsa-input" required>
                                    <option value="">-- Select Employment Type --</option>
                                    <?php foreach ($etTypes as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>"<?= $etCurrent === $t ? ' selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($etCurrent !== '' && !$etKnown): ?>
                                        <option value="<?= htmlspecialchars($etCurrent) ?>" selected><?= htmlspecialchars($etCurrent) ?> (existing)</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label for="teacher_department">Department</label>
                                <select id="teacher_department" name="department" class="nsa-input" required>
                                    <option value="">-- Select Department --</option>
                                </select>
                            </div>

                            <?php
                                $currentReportingManagerId = $staff_data['reportingManagerId'] ?? '';
                                $ownStaffId = $staff_data['staffId'] ?? $staff_data['User ID'] ?? '';
                            ?>
                            <!-- Reporting Manager (Phase 2C — Staff Lifecycle). Optional; server
                                 re-validates same-school/Active/self-reference/cycle regardless
                                 of this list's contents. Self excluded client-side (defense in
                                 depth only — the server rejects self-reference unconditionally). -->
                            <div class="nsa-field">
                                <label for="reporting_manager_id">Reporting Manager</label>
                                <select id="reporting_manager_id" name="reporting_manager_id" class="nsa-input"
                                        data-own-staff-id="<?= htmlspecialchars($ownStaffId, ENT_QUOTES, 'UTF-8') ?>"
                                        data-current-value="<?= htmlspecialchars($currentReportingManagerId, ENT_QUOTES, 'UTF-8') ?>">
                                    <option value="">-- None --</option>
                                    <?php if ($currentReportingManagerId !== ''): ?>
                                    <!-- Rendered server-side so the CURRENTLY assigned manager is always
                                         shown even if Inactive — the Active-only staff-list endpoint below
                                         would silently omit them, making the field look empty otherwise. -->
                                    <option value="<?= htmlspecialchars($currentReportingManagerId, ENT_QUOTES, 'UTF-8') ?>" selected>
                                        <?= htmlspecialchars(($reporting_manager_name ?? $currentReportingManagerId) . (($reporting_manager_status ?? '') === 'Inactive' ? ' (Inactive)' : ''), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                    <?php endif; ?>
                                </select>
                                <small id="reportingManagerInactiveWarning"
                                       style="<?= (isset($reporting_manager_status) && $reporting_manager_status === 'Inactive') ? '' : 'display:none;' ?>color:var(--nsa-red,#dc2626);font-size:11px;">
                                    <i class="fa fa-exclamation-triangle"></i>
                                    This Reporting Manager is currently Inactive. Please review and reassign.
                                </small>
                            </div>

                            <?php if (!empty($hrms_capability_enabled) && !empty($locations)): ?>
                            <div class="nsa-field">
                                <label>Location</label>
                                <select id="location_id" name="location_id" class="nsa-input">
                                    <?php $currentLocationId = (string) ($staff_data['locationId'] ?? ''); ?>
                                    <?php foreach ($locations as $loc):
                                        $selected = ($currentLocationId !== '')
                                            ? ($currentLocationId === $loc['locationId'])
                                            : !empty($loc['isDefault']); ?>
                                        <option value="<?= htmlspecialchars($loc['locationId'], ENT_QUOTES, 'UTF-8') ?>" <?= $selected ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($loc['name'], ENT_QUOTES, 'UTF-8') ?><?= !empty($loc['isDefault']) ? ' (default)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color:var(--nsa-muted);font-size:11px;">Configure in System Settings → Organisation.</small>
                            </div>
                            <?php endif; ?>

                            <!-- Teacher capability — shown only when a Teacher role is selected -->
                            <div id="teacherExtraFields" class="nsa-col-2" style="display:none;">
                                <div class="nsa-field">
                                    <label for="teachingSubjectsHidden">Subjects this teacher can teach</label>
                                    <input type="hidden" name="teaching_subjects" id="teachingSubjectsHidden"
                                           value="<?= htmlspecialchars(implode(',', array_map('strval', (array)($staff_data['teaching_subjects'] ?? [])))) ?>">
                                    <select id="subjectPicker" class="nsa-input">
                                        <option value="">+ Add subject...</option>
                                    </select>
                                    <div id="subjectChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>
                                    <small style="color:var(--nsa-muted);font-size:11px;">
                                        <i class="fa fa-info-circle"></i>
                                        Capability list only. Actual class &amp; section assignments are done in <strong>Academic Planner &rarr; Subject Assignments</strong>.
                                    </small>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ GUARDIAN DETAILS ══ -->
                <div class="nsa-section nsa-step-panel" id="sec-guardian" data-step="1">
                    <div class="nsa-section-head">
                        <i class="fa fa-users"></i>
                        <h3>Guardian Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label for="father_name">Father's Name</label>
                                <input type="text" id="father_name" name="father_name"
                                       value="<?= htmlspecialchars($staff_data['Father Name'] ?? '') ?>"
                                       class="nsa-input" placeholder="Father's full name" required>
                            </div>

                            <div class="nsa-field">
                                <label for="phone_number">Phone Number <span class="req">*</span></label>
                                <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="phone_number" name="phone_number"
                                       value="<?= htmlspecialchars($staff_data['Phone Number'] ?? '') ?>"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10" required></div>
                            </div>

                            <div class="nsa-field">
                                <label for="alt_phone">Alternate Phone</label>
                                <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="alt_phone" name="alt_phone"
                                       value="<?= htmlspecialchars($staff_data['altPhone'] ?? '') ?>"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10"></div>
                            </div>

                            <div class="nsa-field">
                                <label>Marital Status</label>
                                <?php $ms = $staff_data['maritalStatus'] ?? ''; ?>
                                <select id="marital_status" name="marital_status" class="nsa-select">
                                    <option value="">Select</option>
                                    <option value="Single"   <?= $ms === 'Single'   ? 'selected' : '' ?>>Single</option>
                                    <option value="Married"  <?= $ms === 'Married'  ? 'selected' : '' ?>>Married</option>
                                    <option value="Widowed"  <?= $ms === 'Widowed'  ? 'selected' : '' ?>>Widowed</option>
                                    <option value="Divorced" <?= $ms === 'Divorced' ? 'selected' : '' ?>>Divorced</option>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label for="emergency_contact_name">Emergency Contact Name</label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                                       value="<?= htmlspecialchars($staff_data['emergencyContact']['name'] ?? '') ?>"
                                       class="nsa-input" placeholder="Full name" required>
                            </div>

                            <div class="nsa-field">
                                <label for="emergency_contact_phone">Emergency Contact Number</label>
                                <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="emergency_contact_phone" name="emergency_contact_phone"
                                       value="<?= htmlspecialchars($staff_data['emergencyContact']['phoneNumber'] ?? '') ?>"
                                       class="nsa-input" placeholder="10-digit number"
                                       pattern="[0-9]{10}" maxlength="10"></div>
                            </div>

                            <div class="nsa-field">
                                <label>Emergency Contact Relation</label>
                                <?php $ecr = $staff_data['emergencyContact']['relation'] ?? ''; ?>
                                <select id="emergency_contact_relation" name="emergency_contact_relation" class="nsa-select">
                                    <option value="">Select</option>
                                    <option value="Father"  <?= $ecr === 'Father'  ? 'selected' : '' ?>>Father</option>
                                    <option value="Mother"  <?= $ecr === 'Mother'  ? 'selected' : '' ?>>Mother</option>
                                    <option value="Spouse"  <?= $ecr === 'Spouse'  ? 'selected' : '' ?>>Spouse</option>
                                    <option value="Sibling" <?= $ecr === 'Sibling' ? 'selected' : '' ?>>Sibling</option>
                                    <option value="Friend"  <?= $ecr === 'Friend'  ? 'selected' : '' ?>>Friend</option>
                                    <option value="Other"   <?= $ecr === 'Other'   ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label for="designation">Designation</label>
                                <input type="text" id="designation" name="designation"
                                       value="<?= htmlspecialchars($staff_data['designation'] ?? '') ?>"
                                       class="nsa-input" placeholder="e.g. Senior Teacher, HOD">
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ STATUTORY IDs ══ -->
                <div class="nsa-section nsa-step-panel" id="sec-statutory" data-step="2">
                    <div class="nsa-section-head">
                        <i class="fa fa-id-card-o"></i>
                        <h3>Statutory Identification</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label for="pan_number">PAN Number</label>
                                <input type="text" id="pan_number" name="pan_number"
                                       value="<?= htmlspecialchars($staff_data['panNumber'] ?? '') ?>"
                                       class="nsa-input" placeholder="ABCDE1234F"
                                       pattern="[A-Z]{5}[0-9]{4}[A-Z]" maxlength="10"
                                       style="text-transform:uppercase">
                            </div>

                            <div class="nsa-field">
                                <label for="aadhar_number">Aadhar Number</label>
                                <input type="text" id="aadhar_number" name="aadhar_number"
                                       value="<?= htmlspecialchars($staff_data['aadharNumber'] ?? '') ?>"
                                       class="nsa-input" placeholder="12-digit number"
                                       pattern="[0-9]{12}" maxlength="12">
                            </div>

                            <div class="nsa-field">
                                <label for="pf_number">PF Number (UAN)</label>
                                <input type="text" id="pf_number" name="pf_number"
                                       value="<?= htmlspecialchars($staff_data['pfNumber'] ?? '') ?>"
                                       class="nsa-input" placeholder="UAN or PF Account No.">
                            </div>

                            <div class="nsa-field">
                                <label for="esi_number">ESI Number</label>
                                <input type="text" id="esi_number" name="esi_number"
                                       value="<?= htmlspecialchars($staff_data['esiNumber'] ?? '') ?>"
                                       class="nsa-input" placeholder="ESI IP Number">
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ ADDRESS ══ -->
                <div class="nsa-section nsa-step-panel" id="sec-address" data-step="3">
                    <div class="nsa-section-head">
                        <i class="fa fa-map-marker"></i>
                        <h3>Address Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field nsa-col-2">
                                <label for="street">Street</label>
                                <input type="text" id="street" name="street"
                                       value="<?= htmlspecialchars($staff_data['Address']['Street'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="state">State</label>
                                <select id="state" name="state" class="nsa-input"
                                        data-india-state data-india-fill
                                        data-india-selected="<?= htmlspecialchars($staff_data['Address']['State'] ?? '') ?>" required>
                                    <option value=""><?= ($staff_data['Address']['State'] ?? '') !== '' ? htmlspecialchars($staff_data['Address']['State']) : 'Select State' ?></option>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label for="city">District</label>
                                <select id="city" name="city" class="nsa-input"
                                        data-india-district="state"
                                        data-india-selected="<?= htmlspecialchars($staff_data['Address']['City'] ?? '') ?>" required>
                                    <option value=""><?= ($staff_data['Address']['City'] ?? '') !== '' ? htmlspecialchars($staff_data['Address']['City']) : 'Select District' ?></option>
                                </select>
                            </div>

                            <div class="nsa-field">
                                <label for="postal_code">Postal Code</label>
                                <input type="text" id="postal_code" name="postal_code"
                                       value="<?= htmlspecialchars($staff_data['Address']['PostalCode'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                        </div>

                        <!-- Permanent Address -->
                        <div style="margin-top:16px">
                            <label class="nsa-checkbox-label" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--t2)">
                                <input type="checkbox" id="same_as_current" name="same_as_current" value="1"
                                       <?= !empty($staff_data['sameAsCurrentAddress']) ? 'checked' : '' ?>
                                       style="width:16px;height:16px;accent-color:var(--gold)">
                                Permanent address same as current address
                            </label>
                        </div>

                        <div id="permanent_address_block" style="margin-top:12px">
                            <h4 style="font-size:13px;color:var(--t2);margin-bottom:8px;font-weight:600">Permanent Address</h4>
                            <div class="nsa-grid nsa-grid-2">
                                <div class="nsa-field nsa-col-2">
                                    <label for="perm_street">Street</label>
                                    <input type="text" id="perm_street" name="perm_street"
                                           value="<?= htmlspecialchars($staff_data['permanentAddress']['street'] ?? '') ?>"
                                           class="nsa-input" placeholder="House no., Street name">
                                </div>
                                <div class="nsa-field">
                                    <label for="perm_state">State</label>
                                    <select id="perm_state" name="perm_state" class="nsa-input"
                                            data-india-state data-india-fill
                                            data-india-selected="<?= htmlspecialchars($staff_data['permanentAddress']['state'] ?? '') ?>">
                                        <option value=""><?= ($staff_data['permanentAddress']['state'] ?? '') !== '' ? htmlspecialchars($staff_data['permanentAddress']['state']) : 'Select State' ?></option>
                                    </select>
                                </div>
                                <div class="nsa-field">
                                    <label for="perm_city">District</label>
                                    <select id="perm_city" name="perm_city" class="nsa-input"
                                            data-india-district="perm_state"
                                            data-india-selected="<?= htmlspecialchars($staff_data['permanentAddress']['city'] ?? '') ?>">
                                        <option value=""><?= ($staff_data['permanentAddress']['city'] ?? '') !== '' ? htmlspecialchars($staff_data['permanentAddress']['city']) : 'Select District' ?></option>
                                    </select>
                                </div>
                                <div class="nsa-field">
                                    <label for="perm_postal_code">Postal Code</label>
                                    <input type="text" id="perm_postal_code" name="perm_postal_code"
                                           value="<?= htmlspecialchars($staff_data['permanentAddress']['postalCode'] ?? '') ?>"
                                           class="nsa-input" placeholder="6-digit PIN">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ══ QUALIFICATION ══ -->
                <div class="nsa-section nsa-step-panel" id="sec-qualification" data-step="4">
                    <div class="nsa-section-head">
                        <i class="fa fa-graduation-cap"></i>
                        <h3>Qualification Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-3">

                            <div class="nsa-field">
                                <label for="qualification">Highest Qualification</label>
                                <input type="text" id="qualification" name="qualification"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['highestQualification'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="university">University</label>
                                <input type="text" id="university" name="university"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['university'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="year_of_passing">Year of Passing</label>
                                <input type="text" id="year_of_passing" name="year_of_passing"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['yearOfPassing'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="teacher_experience">Work Experience (Years)</label>
                                <input type="text" id="teacher_experience" name="teacher_experience"
                                       value="<?= htmlspecialchars($staff_data['qualificationDetails']['experience'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ BANK DETAILS ══ -->
                <div class="nsa-section nsa-step-panel" id="sec-bank" data-step="5">
                    <div class="nsa-section-head">
                        <i class="fa fa-university"></i>
                        <h3>Bank Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label for="bank_name">Bank Name</label>
                                <input type="text" id="bank_name" name="bank_name"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['bankName'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="account_holder">Account Holder Name</label>
                                <input type="text" id="account_holder" name="account_holder"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['accountHolderName'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="account_number">Account Number</label>
                                <input type="text" id="account_number" name="account_number"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['accountNumber'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                            <div class="nsa-field">
                                <label for="bank_ifsc">IFSC Code</label>
                                <input type="text" id="bank_ifsc" name="bank_ifsc"
                                       value="<?= htmlspecialchars($staff_data['bankDetails']['ifscCode'] ?? '') ?>"
                                       class="nsa-input" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ SALARY ══ -->
                <div class="nsa-section nsa-step-panel" id="sec-salary" data-step="5">
                    <div class="nsa-section-head">
                        <i class="fa fa-money"></i>
                        <h3>Salary Details</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2">

                            <div class="nsa-field">
                                <label for="basicSalary">Basic Salary (₹)</label>
                                <input type="number" id="basicSalary" name="basicSalary"
                                       value="<?= htmlspecialchars($staff_data['salaryDetails']['basicSalary'] ?? '') ?>"
                                       class="nsa-input" min="0" required>
                            </div>

                            <div class="nsa-field">
                                <label for="allowances">Allowances (₹)</label>
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
                <div class="nsa-section nsa-step-panel" id="sec-docs" data-step="6">
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

                                <!-- UX-D3: replacing the Aadhar happens under HR Documents >
                                     Identity Proof, further down this same step. Two upload
                                     controls for one document on one screen is what this
                                     removes; any file already on the record still shows above. -->
                                <div class="nsa-doc-pointer" style="margin-top:10px;">
                                    <i class="fa fa-info-circle"></i>
                                    <span>To add or replace this document, use
                                          <strong>HR Documents &rarr; Identity Proof</strong> below.</span>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ HR DOCUMENTS (Staff HR Advanced Capability Programme, Phase A,
                     2026-08-18) ══
                     Deliberately separate from "Documents & Photo" above, which is a
                     pre-existing, unrelated Photo/Aadhar mechanism
                     ($this->firebase->uploadFile() direct into staff.Doc{}) — this
                     section covers the NEW document types added via the canonical,
                     reusable Document Service (Staff::upload_staff_document() etc.),
                     which genuinely didn't exist before this phase (appointment
                     letter, qualification/experience certificates, employment
                     contract). AJAX, not part of the big form submit above — each
                     upload takes effect immediately, independent of "Save Changes". -->
                <div class="nsa-section nsa-step-panel" id="sec-hr-docs" data-step="6">
                    <div class="nsa-section-head">
                        <i class="fa fa-briefcase"></i>
                        <h3>HR Documents</h3>
                    </div>
                    <div class="nsa-section-body">
                        <div class="nsa-grid nsa-grid-2" id="hrDocsGrid" data-staff-id="<?= htmlspecialchars($staff_data['User ID'] ?? '') ?>">
                            <?php foreach ([
                                'appointment_letter'        => 'Appointment Letter',
                                'staff_id_proof'            => 'Identity Proof',
                                'qualification_certificate' => 'Qualification Certificate',
                                'experience_certificate'    => 'Previous Experience Certificate',
                                'employment_contract'       => 'Employment Contract',
                            ] as $hrDocType => $hrDocLabel): ?>
                            <div class="nsa-field hr-doc-field" data-type="<?= htmlspecialchars($hrDocType) ?>">
                                <label><?= htmlspecialchars($hrDocLabel) ?></label>
                                <div class="hr-doc-current" style="font-size:12px;color:var(--nsa-muted);margin-bottom:6px;">
                                    <i class="fa fa-spinner fa-spin"></i> Checking...
                                </div>
                                <div class="nsa-file-wrap">
                                    <input type="file" class="hr-doc-file" accept=".pdf,.jpg,.jpeg,.png">
                                    <div class="nsa-file-label">
                                        <div class="nsa-file-icon"><i class="fa fa-upload"></i></div>
                                        <div class="nsa-file-text">
                                            <strong>Upload / Replace</strong>
                                            PDF, JPG, PNG · max 5MB — uploads immediately
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ══ EMPLOYEE LIFECYCLE ══
                     These are not form actions. Each opens its own workflow and
                     writes its own record, independent of "Save Changes". They
                     sat in the form action bar, which put nine buttons in one
                     non-wrapping sticky row, implied they were part of saving,
                     and pushed Save to the end of seven unrelated controls. -->
                <div class="nsa-section nsa-step-panel" id="sec-lifecycle" data-step="7">
                    <div class="nsa-section-head">
                        <i class="fa fa-random"></i>
                        <h3>Employee Lifecycle</h3>
                    </div>
                    <div class="nsa-section-body">
                        <p class="nsa-lifecycle-hint">
                            Each of these is a separate record with its own approval trail.
                            They save on their own &mdash; you do not need to press Save Changes afterwards.
                        </p>
                        <div class="nsa-lifecycle-grid">
                            <button type="button" id="openOnboardingModalBtn" class="nsa-lifecycle-btn" onclick="openOnboardingModal()">
                                <i class="fa fa-list-ol"></i>
                                <span class="nsa-lifecycle-label">Onboarding</span>
                                <span class="nsa-lifecycle-sub">Joining checklist</span>
                            </button>
                            <button type="button" id="openPromotionModalBtn" class="nsa-lifecycle-btn" onclick="openPromotionModal()">
                                <i class="fa fa-arrow-up"></i>
                                <span class="nsa-lifecycle-label">Record Promotion</span>
                                <span class="nsa-lifecycle-sub">Designation or grade change</span>
                            </button>
                            <button type="button" id="openTransferModalBtn" class="nsa-lifecycle-btn" onclick="openTransferModal()">
                                <i class="fa fa-exchange"></i>
                                <span class="nsa-lifecycle-label">Transfer</span>
                                <span class="nsa-lifecycle-sub">Department or campus move</span>
                            </button>
                            <button type="button" id="openNoticeModalBtn" class="nsa-lifecycle-btn" onclick="openNoticeModal()">
                                <i class="fa fa-sign-out"></i>
                                <span class="nsa-lifecycle-label">Notice Period</span>
                                <span class="nsa-lifecycle-sub">Resignation and last working day</span>
                            </button>
                            <button type="button" id="openClearanceModalBtn" class="nsa-lifecycle-btn" onclick="openClearanceModal()">
                                <i class="fa fa-check-square-o"></i>
                                <span class="nsa-lifecycle-label">Clearance</span>
                                <span class="nsa-lifecycle-sub">Department sign-off</span>
                            </button>
                            <button type="button" id="openExitDocsModalBtn" class="nsa-lifecycle-btn" onclick="openExitDocsModal()">
                                <i class="fa fa-file-text"></i>
                                <span class="nsa-lifecycle-label">Exit Documents</span>
                                <span class="nsa-lifecycle-sub">Relieving and experience letters</span>
                            </button>
                            <button type="button" id="openExitSummaryModalBtn" class="nsa-lifecycle-btn" onclick="openExitSummaryModal()">
                                <i class="fa fa-list-alt"></i>
                                <span class="nsa-lifecycle-label">Exit Summary</span>
                                <span class="nsa-lifecycle-sub">Full separation record</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══ WIZARD FOOTER ══
                     Save Changes stays reachable on EVERY step. This is an edit
                     screen: the record is already complete, so there is no
                     "finish the wizard first" moment to wait for, and an admin
                     who came to fix one field must be able to save from where
                     they are. -->
                <div class="nsa-wizard-footer">
                    <button type="button" class="nsa-btn nsa-btn-ghost" id="editBackBtn" onclick="editWizardBack()">
                        <i class="fa fa-arrow-left"></i> Back
                    </button>
                    <div class="nsa-wizard-footer-status" id="editFooterStatus"></div>
                    <div class="nsa-wizard-footer-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" id="editNextBtn" onclick="editWizardNext()">
                            Next <i class="fa fa-arrow-right"></i>
                        </button>
                        <button type="button" id="updateStaffBtn"
                                class="nsa-btn nsa-btn-primary">
                            <i class="fa fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>

            </form>

            <!-- ══ PROMOTION MODAL (Staff HR Advanced Capability Programme,
                 Phase 1, 2026-08-18) ══
                 Deliberately OUTSIDE <form id="edit_staff_form"> — its inputs
                 carry no `name` attribute and are read directly by ID, so a
                 FormData(form) submit of the main "Save Changes" action can
                 never accidentally sweep a promotion field into an unrelated
                 save. A dedicated action, not a reuse of the generic edit
                 form: shows CURRENT values read-only next to NEW value
                 inputs, requires a reason, and calls Staff::promote_staff()
                 directly — never modifies Payroll/salary, never introduces a
                 new employment-history event type (see the endpoint's own
                 docblock for why). -->
            <div class="nsa-modal-overlay" id="promotionModalOverlay" style="display:none;">
                <div class="nsa-modal" style="max-width:560px;">
                    <div class="nsa-modal-title"><i class="fa fa-arrow-up"></i> Record Promotion</div>
                    <p style="font-size:12.5px;color:var(--nsa-muted);margin:-6px 0 14px;">
                        A deliberate HR action — recorded as an effective-dated employment-history
                        episode, separate from a routine profile edit. Leave a field's "New" value
                        empty to leave it unchanged.
                    </p>
                    <div class="nsa-grid nsa-grid-2">
                        <div class="nsa-field">
                            <label>Current Position</label>
                            <input type="text" id="promoCurrentPosition" class="nsa-input" disabled>
                        </div>
                        <div class="nsa-field">
                            <label>New Position</label>
                            <input type="text" id="promoNewPosition" class="nsa-input" placeholder="Leave empty to keep current">
                        </div>
                        <div class="nsa-field">
                            <label>Current Department</label>
                            <input type="text" id="promoCurrentDepartment" class="nsa-input" disabled>
                        </div>
                        <div class="nsa-field">
                            <label>New Department</label>
                            <select id="promoNewDepartment" class="nsa-input">
                                <option value="">Leave empty to keep current</option>
                            </select>
                        </div>
                        <div class="nsa-field">
                            <label>Current Reporting Manager</label>
                            <input type="text" id="promoCurrentManager" class="nsa-input" disabled>
                        </div>
                        <div class="nsa-field">
                            <label>New Reporting Manager</label>
                            <select id="promoNewManager" class="nsa-input">
                                <option value="">Leave empty to keep current</option>
                            </select>
                        </div>
                        <div class="nsa-field">
                            <label>Effective Date</label>
                            <input type="date" id="promoEffectiveFrom" class="nsa-input">
                        </div>
                        <div class="nsa-field" style="grid-column:1/-1;">
                            <label>Reason <span class="nsa-optional-tag">visible in the employment-history audit trail</span></label>
                            <textarea id="promoReason" class="nsa-input" rows="2" placeholder="e.g. Promoted to Senior Teacher based on Q2 appraisal recommendation"></textarea>
                        </div>
                    </div>
                    <div class="nsa-modal-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closePromotionModal()">Cancel</button>
                        <button type="button" class="nsa-btn nsa-btn-primary" id="submitPromotionBtn" onclick="submitPromotion()">
                            <i class="fa fa-check"></i> Record Promotion
                        </button>
                    </div>
                </div>
            </div>

            <!-- ══ TRANSFER MODAL (Trail 2 Phase I, 2026-08-18; Work
                 Schedule decision point added Priority 3/4, 2026-08-19) ══
                 Deliberately OUTSIDE <form id="edit_staff_form"> — same
                 reasoning as the Promotion modal. Calls Staff::transfer_staff()
                 directly — that endpoint itself still does NOT touch Work
                 Schedules (Work_schedules::save_assignment() remains the
                 sole writer of staffScheduleAssignments; no second writer
                 was added). The optional "Work Schedule" choice below is
                 explicit human opt-in composed at the UI level: on a
                 successful transfer, submitTransfer() separately calls the
                 EXISTING save_assignment() only if "Change" was chosen —
                 never inferred from the department change. Only rendered
                 for an admin who independently holds HR:manage (the
                 authority save_assignment() itself requires); everyone
                 else keeps the original passive hint, unchanged. -->
            <div class="nsa-modal-overlay" id="transferModalOverlay" style="display:none;">
                <div class="nsa-modal" style="max-width:560px;">
                    <div class="nsa-modal-title"><i class="fa fa-exchange"></i> Transfer Staff</div>
                    <p style="font-size:12.5px;color:var(--nsa-muted);margin:-6px 0 14px;">
                        Moves this employee's department and/or reporting manager, effective on a
                        date you choose — past, today, or future.
                        <?php if (empty($hr_manage)): ?>
                        If this transfer also needs a new
                        Work Schedule, set that separately under <strong>Attendance → Work Schedules →
                        Assignments</strong> once this is saved.
                        <?php endif; ?>
                    </p>
                    <div class="nsa-grid nsa-grid-2">
                        <div class="nsa-field">
                            <label>Current Department</label>
                            <input type="text" id="transferCurrentDepartment" class="nsa-input" disabled>
                        </div>
                        <div class="nsa-field">
                            <label>New Department</label>
                            <select id="transferNewDepartment" class="nsa-input">
                                <option value="">Leave empty to keep current</option>
                            </select>
                        </div>
                        <div class="nsa-field">
                            <label>Current Reporting Manager</label>
                            <input type="text" id="transferCurrentManager" class="nsa-input" disabled>
                        </div>
                        <div class="nsa-field">
                            <label>New Reporting Manager</label>
                            <select id="transferNewManager" class="nsa-input">
                                <option value="">Leave empty to keep current</option>
                            </select>
                        </div>
                        <div class="nsa-field" style="grid-column:1/-1;">
                            <label>Effective Date <span class="req">*</span></label>
                            <input type="date" id="transferEffectiveFrom" class="nsa-input" required>
                        </div>
                        <?php if (!empty($hr_manage)): ?>
                        <div class="nsa-field" style="grid-column:1/-1;border-top:1px dashed var(--nsa-border,#e5e7eb);padding-top:12px;margin-top:2px;">
                            <label>Does this employee also need a new Work Schedule?</label>
                            <div style="display:flex;gap:16px;font-size:13px;margin:4px 0 8px;">
                                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
                                    <input type="radio" name="transferScheduleChoice" value="keep" checked onchange="onTransferScheduleChoiceChange()"> Keep current Work Schedule
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
                                    <input type="radio" name="transferScheduleChoice" value="change" onchange="onTransferScheduleChoiceChange()"> Change Work Schedule
                                </label>
                            </div>
                            <select id="transferNewSchedule" class="nsa-input" style="display:none;">
                                <option value="">Select a Work Schedule…</option>
                            </select>
                            <p style="font-size:11.5px;color:var(--nsa-muted);margin:6px 0 0;">
                                Never inferred from the department — an explicit choice, applied via the
                                existing Work Schedules assignment writer, effective the same date as the transfer above.
                            </p>
                        </div>
                        <?php endif; ?>
                        <div class="nsa-field" style="grid-column:1/-1;">
                            <label>Reason <span class="nsa-optional-tag">visible in the employment-history audit trail</span></label>
                            <textarea id="transferReason" class="nsa-input" rows="2" placeholder="e.g. Moved to Senior School to cover the Grade 9 vacancy"></textarea>
                        </div>
                    </div>
                    <div class="nsa-modal-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closeTransferModal()">Cancel</button>
                        <button type="button" class="nsa-btn nsa-btn-primary" id="submitTransferBtn" onclick="submitTransfer()">
                            <i class="fa fa-check"></i> Record Transfer
                        </button>
                    </div>
                </div>
            </div>

            <!-- ══ NOTICE PERIOD MODAL (Staff HR Advanced Capability
                 Programme, Phase 2, 2026-08-18) ══
                 Also outside <form id="edit_staff_form"> — same reasoning as
                 the Promotion modal. Content is rendered entirely by JS
                 (noticeRenderState()) since the modal's shape genuinely
                 differs by state (no notice / pending decision / awaiting
                 separation / completed) rather than being one static form. -->
            <div class="nsa-modal-overlay" id="noticeModalOverlay" style="display:none;">
                <div class="nsa-modal" style="max-width:560px;">
                    <div class="nsa-modal-title"><i class="fa fa-sign-out"></i> Notice Period</div>
                    <div id="noticeModalBody">
                        <p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>
                    </div>
                    <div class="nsa-modal-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closeNoticeModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- ══ CLEARANCE MODAL (Staff HR Advanced Capability Programme,
                 Phase 3, 2026-08-18) ══ Outside the main form, same reasoning
                 as Promotion/Notice Period. -->
            <div class="nsa-modal-overlay" id="clearanceModalOverlay" style="display:none;">
                <div class="nsa-modal" style="max-width:620px;">
                    <div class="nsa-modal-title"><i class="fa fa-check-square-o"></i> Clearance / Handover</div>
                    <div id="clearanceModalBody">
                        <p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>
                    </div>
                    <div class="nsa-modal-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closeClearanceModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- ══ ONBOARDING MODAL (Staff HR Advanced Capability Programme,
                 Phase 6, 2026-08-19) ══ Outside the main form, same
                 reasoning as Clearance — its closest precedent (a
                 configurable, per-item checklist with a recomputed overall
                 status). Manual-initiate (HR clicks "Start Onboarding"),
                 not auto-triggered from new_staff() — composes at the
                 workflow level rather than touching that already-proven
                 creation flow. -->
            <div class="nsa-modal-overlay" id="onboardingModalOverlay" style="display:none;">
                <div class="nsa-modal" style="max-width:620px;">
                    <div class="nsa-modal-title"><i class="fa fa-list-ol"></i> Onboarding Checklist</div>
                    <div id="onboardingModalBody">
                        <p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>
                    </div>
                    <div class="nsa-modal-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closeOnboardingModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- ══ EXIT DOCUMENTS MODAL (Staff HR Advanced Capability
                 Programme, Phase 4, 2026-08-18) ══ Pure links — the print
                 view itself (staff/print_exit_document) does its own
                 Inactive+exitDate gate server-side and refuses gracefully,
                 so no extra client-side precondition is duplicated here. -->
            <div class="nsa-modal-overlay" id="exitDocsModalOverlay" style="display:none;">
                <div class="nsa-modal" style="max-width:440px;">
                    <div class="nsa-modal-title"><i class="fa fa-file-text"></i> Exit Documents</div>
                    <p style="font-size:12.5px;color:var(--nsa-muted);margin:-6px 0 14px;">
                        Only available once this staff member is separated (Inactive with a recorded
                        last working day). Opens a printable page in a new tab.
                    </p>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="printExitDocument('relieving')"><i class="fa fa-file-text-o"></i> Relieving Letter</button>
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="printExitDocument('experience')"><i class="fa fa-file-text-o"></i> Experience Certificate</button>
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="printExitDocument('service')"><i class="fa fa-file-text-o"></i> Service Certificate</button>
                    </div>
                    <div class="nsa-modal-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closeExitDocsModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- ══ EXIT SUMMARY MODAL (Staff HR Advanced Capability
                 Programme, Phase 5, 2026-08-18) ══ READ-ONLY aggregation —
                 every figure shown is fetched verbatim from its own owning
                 module (the compensation engine's latest finalized/paid pay
                 slip, Leave's balance doc, this programme's own Notice
                 Period / Clearance records). Nothing on this modal is
                 computed here; there is no encashment, notice-recovery,
                 withholding, tax, or final amount owed figure anywhere on
                 this screen. -->
            <div class="nsa-modal-overlay" id="exitSummaryModalOverlay" style="display:none;">
                <div class="nsa-modal" style="max-width:620px;">
                    <div class="nsa-modal-title"><i class="fa fa-list-alt"></i> Exit Summary</div>
                    <p style="font-size:12.5px;color:var(--nsa-muted);margin:-6px 0 14px;">
                        Read-only. Aggregates figures already recorded elsewhere — no amount on this
                        screen is calculated here.
                    </p>
                    <div id="exitSummaryModalBody">
                        <p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>
                    </div>
                    <div class="nsa-modal-actions">
                        <button type="button" class="nsa-btn nsa-btn-ghost" onclick="closeExitSummaryModal()">Close</button>
                    </div>
                </div>
            </div>

        </div><!-- /.nsa-main -->
    </div><!-- /.nsa-layout -->

</div><!-- /.nsa-wrap -->
</div><!-- /.content-wrapper -->


<script src="<?= base_url('assets/js/india_geo.js') ?>"></script>
<script src="<?= base_url('assets/js/zx_dialog.js') ?>?v=1"></script>
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
/* ═══════════════════════════════════════════════════════════════
   EDIT WIZARD
   Same eight-step shape as Add Staff, with one deliberate difference:
   every step is unlocked from the start. Add Staff gates progress because
   it is building a record that does not exist yet; here the record is
   already complete and valid, so gating would only stand between an admin
   and the one field they came to change.
   ═══════════════════════════════════════════════════════════════ */
var EDIT_STEPS = [
    { title: 'Basic Info',     icon: 'fa-user' },
    { title: 'Guardian',       icon: 'fa-users' },
    { title: 'Statutory ID',   icon: 'fa-id-card-o' },
    { title: 'Address',        icon: 'fa-map-marker' },
    { title: 'Qualification',  icon: 'fa-graduation-cap' },
    { title: 'Compensation',   icon: 'fa-money' },
    { title: 'Documents',      icon: 'fa-folder-open' },
    { title: 'Lifecycle',      icon: 'fa-random' }
];
var editCurrentStep = 0;
var editVisited = { 0: true };

function editPanels() {
    return Array.prototype.slice.call(document.querySelectorAll('.nsa-step-panel'));
}

function renderEditStepper() {
    var bar = document.getElementById('editStepper');
    if (!bar) return;
    bar.innerHTML = '';

    // A step showing a validation error must say so, otherwise a failed save
    // looks like nothing happened when the offending field is on another step.
    var errSteps = {};
    document.querySelectorAll('.has-error').forEach(function (el) {
        var panel = el.closest('.nsa-step-panel');
        if (panel) errSteps[parseInt(panel.getAttribute('data-step'), 10)] = true;
    });

    EDIT_STEPS.forEach(function (s, i) {
        var el = document.createElement('button');
        el.type = 'button';
        var cls = 'nsa-step';
        if (i === editCurrentStep) cls += ' active';
        if (editVisited[i]) cls += ' visited';
        if (errSteps[i]) cls += ' has-error';
        el.className = cls;
        el.setAttribute('aria-current', i === editCurrentStep ? 'step' : 'false');
        el.innerHTML =
            '<span class="nsa-step-circle">' + (errSteps[i] ? '!' : (i + 1)) + '</span>' +
            '<span class="nsa-step-label">' + s.title + '</span>';
        el.addEventListener('click', function () { editShowStep(i); });
        bar.appendChild(el);
    });

    var pct = Math.round((editCurrentStep / (EDIT_STEPS.length - 1)) * 100);
    var pb = document.getElementById('editProgressBar');
    if (pb) pb.style.width = pct + '%';
    var st = document.getElementById('editFooterStatus');
    if (st) st.textContent = 'Step ' + (editCurrentStep + 1) + ' of ' + EDIT_STEPS.length
                           + ' \u00b7 ' + EDIT_STEPS[editCurrentStep].title;
}

function editShowStep(i) {
    if (i < 0 || i >= EDIT_STEPS.length) return;
    editPanels().forEach(function (el) {
        var step = parseInt(el.getAttribute('data-step'), 10);
        if (step === i) {
            el.style.display = 'block';
            el.classList.remove('nsa-step-in');
            void el.offsetWidth;
            requestAnimationFrame(function () { el.classList.add('nsa-step-in'); });
        } else {
            el.style.display = 'none';
            el.classList.remove('nsa-step-in');
        }
    });
    editCurrentStep = i;
    editVisited[i] = true;

    var back = document.getElementById('editBackBtn');
    var next = document.getElementById('editNextBtn');
    if (back) back.style.visibility = (i === 0) ? 'hidden' : 'visible';
    if (next) next.style.visibility = (i === EDIT_STEPS.length - 1) ? 'hidden' : 'visible';

    renderEditStepper();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function editWizardBack() { editShowStep(editCurrentStep - 1); }
function editWizardNext() { editShowStep(editCurrentStep + 1); }

/**
 * Reveal the first field that failed validation. Without this a save can fail
 * silently: validateEditForm() marks the field and returns false, but the field
 * is on a hidden step, so the operator sees no error and no save.
 */
function editRevealFirstError() {
    var bad = document.querySelector('.has-error');
    if (!bad) { renderEditStepper(); return false; }
    var panel = bad.closest('.nsa-step-panel');
    if (panel) {
        var step = parseInt(panel.getAttribute('data-step'), 10);
        if (step !== editCurrentStep) editShowStep(step);
    }
    renderEditStepper();
    try { bad.focus({ preventScroll: true }); } catch (e) { /* older browsers */ }
    bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
}

document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('editStepper')) return;

    // Deep links from elsewhere in the panel still work: #sec-salary opens the
    // step that section now lives on rather than scrolling to a hidden node.
    var start = 0;
    if (window.location.hash) {
        var target = document.querySelector('.nsa-step-panel' + window.location.hash);
        if (target) start = parseInt(target.getAttribute('data-step'), 10) || 0;
    }
    editShowStep(start);
});

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

    // Single-source, permissive validation — matches the import model: only
    // Name + Phone are hard-required, so a sparsely-imported staff can be saved
    // after fixing just one field. Format checks fire only when a value exists.
    if (!getValue('name')) showError('name', 'Name is required');

    var emailVal = getValue('email_user');
    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (emailVal && !emailPattern.test(emailVal))
        showError('email_user', 'Enter a valid email address');

    var phonePattern = /^[6-9]\d{9}$/;
    if (!phonePattern.test(getValue('phone_number')))
        showError('phone_number', 'Enter valid 10-digit mobile number');
    if (getValue('emergency_contact_phone') && !phonePattern.test(getValue('emergency_contact_phone')))
        showError('emergency_contact_phone', 'Enter valid 10-digit mobile number');

    var postalVal = getValue('postal_code');
    var postalPattern = /^[1-9][0-9]{5}$/;
    if (postalVal && !postalPattern.test(postalVal))
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

    /* ── Permanent address "same as current" toggle ── */
    (function(){
        var sameChk = document.getElementById('same_as_current');
        var permBlock = document.getElementById('permanent_address_block');
        if (!sameChk || !permBlock) return;
        // Hide block on initial render if box was checked from server
        if (sameChk.checked) permBlock.style.display = 'none';
        sameChk.addEventListener('change', function() {
            if (this.checked) {
                permBlock.style.display = 'none';
                ['street','state','postal_code'].forEach(function(k){
                    var src = document.getElementById(k);
                    var dst = document.getElementById('perm_' + k);
                    if (src && dst) dst.value = src.value;
                });
                // District select must cascade off the copied permanent state.
                if (window.IndiaGeo) {
                    IndiaGeo.fillDistricts(document.getElementById('perm_state'), document.getElementById('perm_city'), document.getElementById('city').value);
                } else {
                    var pc = document.getElementById('perm_city'); if (pc) pc.value = document.getElementById('city').value;
                }
            } else {
                permBlock.style.display = '';
            }
        });
    })();

    /* ── Reporting Manager (Phase 2C) — reuses the existing HR staff-list
       endpoint (Active-only, same-school), excludes the staff member
       themselves. The currently-assigned manager (Active or Inactive) is
       already rendered server-side as a pre-selected option, so this only
       ADDS the rest of the choices without duplicating that one. Server-side
       validation on submit is authoritative regardless of this list. ── */
    (function() {
        var sel = document.getElementById('reporting_manager_id');
        if (!sel) return;
        var ownId = sel.getAttribute('data-own-staff-id') || '';
        var currentValue = sel.getAttribute('data-current-value') || '';
        fetch('<?= base_url("hr/get_staff_list") ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                var staff = resp.staff || (resp.data && resp.data.staff) || [];
                staff.forEach(function(s) {
                    if (s.id === ownId || s.id === currentValue) return; // no self-report; current option already rendered
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name + (s.position ? ' — ' + s.position : '');
                    sel.appendChild(opt);
                });
            })
            .catch(function() { /* non-fatal — field stays optional */ });
    })();

    /* ── Load departments from HR module ── */
    var _deptRoles = {};   // department name → allowed staff-role IDs (Departments & Roles)
    (function(){
        var sel = document.getElementById('teacher_department');
        if (!sel) return;
        var currentDept = '<?= htmlspecialchars($staff_data['Department'] ?? $staff_data['department'] ?? '', ENT_QUOTES, 'UTF-8') ?>';
        fetch('<?= base_url("hr/get_departments") ?>')
            .then(function(r){ return r.json(); })
            .then(function(r){
                if (r.status !== 'success') return;
                var depts = r.departments || [];
                var found = false;
                depts.forEach(function(d){
                    _deptRoles[d.name] = Array.isArray(d.role_ids) ? d.role_ids : [];
                    if ((d.status||'Active') !== 'Active') return;
                    var opt = document.createElement('option');
                    opt.value = d.name;
                    opt.textContent = d.name;
                    // Case-insensitive so a legacy "science" selects the real
                    // "Science" option (and saving self-heals the casing) rather
                    // than adding a duplicate "(not in HR)" entry.
                    if (d.name.toLowerCase() === currentDept.toLowerCase()) { opt.selected = true; found = true; }
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
                if (typeof refreshRoleOptions === 'function') refreshRoleOptions();
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
        // Re-scope the role picker whenever the department changes (prunes on user change).
        sel.addEventListener('change', function(){
            if (typeof onDepartmentChanged === 'function') onDepartmentChanged();
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
            if (!validateEditForm()) { editRevealFirstError(); return; }
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
            refreshRoleOptions();
            // Render existing selections (legacy roles are kept even if they are
            // not part of the current department's mapping — we don't strip what
            // the staff already has; only a manual department change prunes).
            if (_selectedRoles.length && !_primaryRole) _primaryRole = _selectedRoles[0];
            renderRoleChips();
        });
    }
    loadStaffRoles();

    /* Roles selectable for the chosen department (STRICT — see new_staff).
     * null → no department chosen; mapped → those roles; unmapped → [] (none). */
    function allowedRoleIds() {
        var deptSel = document.getElementById('teacher_department');
        var dept = deptSel ? deptSel.value : '';
        if (!dept) return null;
        var ids = _deptRoles[dept];
        if (ids && ids.length) return ids.filter(function(id){ return _roleData[id]; });
        return [];
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
        if (!_selectedRoles.length) {
            container.innerHTML = '<span style="font-size:12px;color:var(--nsa-red,#dc2626);">'
                + '<i class="fa fa-exclamation-circle"></i> No role yet — add at least one.</span>';
        }
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
        if (typeof recheckTeachingVisibility === 'function') recheckTeachingVisibility();
    }

    /* ── Teaching subjects (capability list — visible only for Teacher role) ── */
    var _subjHidden = document.getElementById('teachingSubjectsHidden');
    var _selectedSubjects = ((_subjHidden && _subjHidden.value) || '')
        .split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    var _subjectsLoading = false, _subjectsLoaded = false;

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
        for (var i = 0; i < _selectedRoles.length; i++) {
            if (roleCanTeach(_selectedRoles[i])) return true;
        }
        return false;
    }

    function recheckTeachingVisibility() {
        var section = document.getElementById('teacherExtraFields');
        if (!section) return;
        var show = isTeacherSelected();
        section.style.display = show ? 'block' : 'none';
        if (show) loadSubjectOptions();
    }

    function loadSubjectOptions() {
        var sel = document.getElementById('subjectPicker');
        if (!sel || _subjectsLoaded || _subjectsLoading) return;
        _subjectsLoading = true;
        fetch('<?= base_url("school_config/get_all_subjects") ?>', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
            body: '<?= $this->security->get_csrf_token_name() ?>=<?= $this->security->get_csrf_hash() ?>'
        })
        .then(function(r){ return r.json(); })
        .then(function(r){
            while (sel.options.length > 1) sel.remove(1);
            if (r.status === 'success' && r.subjects) {
                var seen = {};
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
            _subjectsLoaded = true; _subjectsLoading = false;
        })
        .catch(function(){
            while (sel.options.length > 1) sel.remove(1);
            ['English','Hindi','Mathematics','Science','Social Science','Computer Science',
             'Physics','Chemistry','Biology','Accountancy','Business Studies','Economics',
             'History','Geography','Political Science','Sanskrit','Physical Education','Art'].forEach(function(s){
                var opt = document.createElement('option');
                opt.value = s; opt.textContent = s;
                sel.appendChild(opt);
            });
            _subjectsLoaded = true; _subjectsLoading = false;
        });
    }

    (function(){
        var picker = document.getElementById('subjectPicker');
        if (!picker) return;
        picker.addEventListener('change', function(){
            var val = this.value;
            if (!val || _selectedSubjects.indexOf(val) !== -1) { this.value = ''; return; }
            _selectedSubjects.push(val);
            renderSubjectChips();
            this.value = '';
        });
    })();

    function renderSubjectChips() {
        var container = document.getElementById('subjectChips');
        if (!container) return;
        container.innerHTML = '';
        _selectedSubjects.forEach(function(s){
            var chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:rgba(188,90,60,.1);color:#BC5A3C;border-radius:14px;font-size:12px;font-weight:500;';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.style.cssText = 'background:none;border:none;color:inherit;cursor:pointer;font-weight:700;padding:0 2px;';
            btn.innerHTML = '&times;';
            btn.addEventListener('click', function(){
                _selectedSubjects = _selectedSubjects.filter(function(x){ return x !== s; });
                renderSubjectChips();
            });
            chip.textContent = s + ' ';
            chip.appendChild(btn);
            container.appendChild(chip);
        });
        var hidden = document.getElementById('teachingSubjectsHidden');
        if (hidden) hidden.value = _selectedSubjects.join(',');
    }
    renderSubjectChips();
    recheckTeachingVisibility();

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

/* ══ HR DOCUMENTS (Staff HR Advanced Capability Programme, Phase A) ══
   Independent AJAX widget — each upload takes effect immediately via
   Staff::upload_staff_document()/get_staff_documents(), unrelated to the
   big "Save Changes" form submit above. */
(function () {
    var $grid = $('#hrDocsGrid');
    if (!$grid.length) return;
    var staffId = $grid.data('staff-id');

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function renderCurrent($field, ref) {
        var $cur = $field.find('.hr-doc-current');
        if (!ref) {
            $cur.html('<i class="fa fa-minus-circle" style="color:var(--nsa-muted);"></i> Not uploaded yet');
            return;
        }
        var statusIcon = { verified: 'fa-check-circle', rejected: 'fa-times-circle', pending: 'fa-clock-o' }[ref.verificationStatus] || 'fa-clock-o';
        var statusColor = { verified: '#059669', rejected: '#dc2626', pending: '#d97706' }[ref.verificationStatus] || '#d97706';
        $cur.html(
            '<i class="fa ' + statusIcon + '" style="color:' + statusColor + ';"></i> ' +
            'Uploaded <span style="text-transform:capitalize;color:' + statusColor + ';">(' + esc(ref.verificationStatus || 'pending') + ')</span> ' +
            (ref.url ? '&nbsp;<a href="' + esc(ref.url) + '" target="_blank">View</a>' : '')
        );
    }

    function loadHrDocuments() {
        if (!staffId) return;
        $.get('<?= base_url("staff/get_staff_documents") ?>', { staff_id: staffId }, function (res) {
            var byType = {};
            (res && res.documents ? res.documents : []).forEach(function (d) { byType[d.type] = d; });
            $grid.find('.hr-doc-field').each(function () {
                var type = $(this).data('type');
                renderCurrent($(this), byType[type] || null);
            });
        }, 'json').fail(function () {
            $grid.find('.hr-doc-current').html('<i class="fa fa-exclamation-triangle"></i> Could not load document status.');
        });
    }

    $grid.on('change', '.hr-doc-file', function () {
        var $input = $(this);
        var $field = $input.closest('.hr-doc-field');
        var type = $field.data('type');
        var file = this.files && this.files[0];
        if (!file || !staffId) return;

        var fd = new FormData();
        fd.append('staff_id', staffId);
        fd.append('type', type);
        fd.append('document', file);

        $field.find('.hr-doc-current').html('<i class="fa fa-spinner fa-spin"></i> Uploading...');
        $.ajax({
            url: '<?= base_url("staff/upload_staff_document") ?>',
            method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function (res) {
                if (res && res.status && res.status !== 'error') {
                    nsaShowAlert('success', res.message || 'Document uploaded.');
                    loadHrDocuments();
                } else {
                    nsaShowAlert('error', (res && res.message) || 'Upload failed.');
                    loadHrDocuments();
                }
                $input.val('');
            },
            error: function () {
                nsaShowAlert('error', 'Server error during upload — please try again.');
                loadHrDocuments();
                $input.val('');
            }
        });
    });

    loadHrDocuments();
})();

/* ══ PROMOTION MODAL (Staff HR Advanced Capability Programme, Phase 1) ══
   Global functions — called from onclick= attributes, so deliberately NOT
   nested inside the HR-documents IIFE above. Independent AJAX action,
   entirely separate from the main "Save Changes" form submit. */
var PROMO_STAFF_ID = <?= json_encode($staff_data['User ID'] ?? '') ?>;
var PROMO_CURRENT_POSITION = <?= json_encode($staff_data['position'] ?? $staff_data['Position'] ?? '') ?>;
var PROMO_CURRENT_DEPARTMENT = <?= json_encode($staff_data['department'] ?? $staff_data['Department'] ?? '') ?>;
var PROMO_CURRENT_MANAGER_ID = <?= json_encode($currentReportingManagerId ?? '') ?>;
var _promoDeptLoaded = false, _promoMgrLoaded = false;
var HR_MANAGE = <?= json_encode(!empty($hr_manage)) ?>;

function dismissScheduleReview() {
    var banner = document.getElementById('scheduleReviewBanner');
    var btn = banner ? banner.querySelector('button') : null;
    if (btn) { btn.disabled = true; btn.textContent = 'Dismissing…'; }
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    $.ajax({
        url: '<?= base_url("staff/clear_pending_schedule_review") ?>',
        method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') { if (banner) banner.remove(); }
            else { nsaShowAlert('error', (res && res.message) || 'Could not dismiss the reminder.'); if (btn) { btn.disabled = false; btn.textContent = 'Dismiss'; } }
        },
        error: function () {
            nsaShowAlert('error', 'Server error — please try again.');
            if (btn) { btn.disabled = false; btn.textContent = 'Dismiss'; }
        }
    });
}

function openPromotionModal() {
    document.getElementById('promoCurrentPosition').value = PROMO_CURRENT_POSITION || '(none set)';
    document.getElementById('promoCurrentDepartment').value = PROMO_CURRENT_DEPARTMENT || '(none set)';
    document.getElementById('promoNewPosition').value = '';
    document.getElementById('promoEffectiveFrom').value = '';
    document.getElementById('promoReason').value = '';

    if (!_promoDeptLoaded) {
        _promoDeptLoaded = true;
        fetch('<?= base_url("hr/get_departments") ?>')
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r.status !== 'success') return;
                var sel = document.getElementById('promoNewDepartment');
                (r.departments || []).forEach(function (d) {
                    if ((d.status || 'Active') !== 'Active') return;
                    var opt = document.createElement('option');
                    opt.value = d.name; opt.textContent = d.name;
                    sel.appendChild(opt);
                });
            }).catch(function () {});
    }

    if (!_promoMgrLoaded) {
        _promoMgrLoaded = true;
        fetch('<?= base_url("hr/get_staff_list") ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                var staff = resp.staff || (resp.data && resp.data.staff) || [];
                var sel = document.getElementById('promoNewManager');
                var currentText = '(none set)';
                staff.forEach(function (s) {
                    if (s.id === PROMO_STAFF_ID) return; // no self-report
                    if (s.id === PROMO_CURRENT_MANAGER_ID) currentText = s.name;
                    var opt = document.createElement('option');
                    opt.value = s.id; opt.textContent = s.name + (s.position ? ' — ' + s.position : '');
                    sel.appendChild(opt);
                });
                document.getElementById('promoCurrentManager').value = currentText;
            }).catch(function () {
                document.getElementById('promoCurrentManager').value = PROMO_CURRENT_MANAGER_ID || '(none set)';
            });
    }

    document.getElementById('promotionModalOverlay').style.display = 'flex';
}

function closePromotionModal() {
    document.getElementById('promotionModalOverlay').style.display = 'none';
}

function submitPromotion() {
    var newPosition = document.getElementById('promoNewPosition').value.trim();
    var newDepartment = document.getElementById('promoNewDepartment').value;
    var newManager = document.getElementById('promoNewManager').value;
    var effectiveFrom = document.getElementById('promoEffectiveFrom').value;
    var reason = document.getElementById('promoReason').value.trim();

    if (!newPosition && !newDepartment && !newManager) {
        nsaShowAlert('error', 'Set at least one new value (position, department, or reporting manager).');
        return;
    }

    var btn = document.getElementById('submitPromotionBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Recording…';

    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    if (newPosition) fd.append('new_position', newPosition);
    if (newDepartment) fd.append('new_department', newDepartment);
    // Only submitted when the user actually picked someone — an empty
    // selection means "leave unchanged", NOT "clear the manager" (empty
    // string is a distinct, valid server-side value meaning "cleared").
    // This modal is not the place to clear an existing manager.
    if (newManager) fd.append('new_reporting_manager_id', newManager);
    if (effectiveFrom) fd.append('effective_from', effectiveFrom);
    if (reason) fd.append('reason', reason);

    $.ajax({
        url: '<?= base_url("staff/promote_staff") ?>',
        method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-check"></i> Record Promotion';
            if (res && res.status && res.status !== 'error') {
                nsaShowAlert('success', res.message || 'Promotion recorded.');
                if (res.warning) nsaShowAlert('warning', res.warning);
                closePromotionModal();
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                nsaShowAlert('error', (res && res.message) || 'Could not record the promotion.');
            }
        },
        error: function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-check"></i> Record Promotion';
            nsaShowAlert('error', 'Server error — please try again.');
        }
    });
}

/* ══ TRANSFER MODAL (Trail 2 Phase I) ══
   Same department/manager-dropdown loading pattern as the Promotion
   modal above — deliberately duplicated rather than shared, matching how
   this file already keeps each modal's script self-contained. Reuses the
   PROMO_STAFF_ID/PROMO_CURRENT_* globals already declared above (this
   page's one staff record, one set of "current" values — no need for a
   second copy). */
var _transferDeptLoaded = false, _transferMgrLoaded = false;

var _transferSchedulesLoaded = false;

function onTransferScheduleChoiceChange() {
    var change = document.querySelector('input[name="transferScheduleChoice"]:checked');
    var sel = document.getElementById('transferNewSchedule');
    if (!sel) return;
    sel.style.display = (change && change.value === 'change') ? '' : 'none';
}

function openTransferModal() {
    document.getElementById('transferCurrentDepartment').value = PROMO_CURRENT_DEPARTMENT || '(none set)';
    document.getElementById('transferNewDepartment').value = '';
    document.getElementById('transferEffectiveFrom').value = '';
    document.getElementById('transferReason').value = '';

    var keepRadio = document.querySelector('input[name="transferScheduleChoice"][value="keep"]');
    if (keepRadio) keepRadio.checked = true;
    onTransferScheduleChoiceChange();

    if (HR_MANAGE && !_transferSchedulesLoaded) {
        _transferSchedulesLoaded = true;
        fetch('<?= base_url("work_schedules/get_data") ?>')
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r.status !== 'success') return;
                var sel = document.getElementById('transferNewSchedule');
                if (!sel) return;
                (r.schedules || []).forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.scheduleId; opt.textContent = s.name || s.scheduleId;
                    sel.appendChild(opt);
                });
            }).catch(function () {});
    }

    if (!_transferDeptLoaded) {
        _transferDeptLoaded = true;
        fetch('<?= base_url("hr/get_departments") ?>')
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r.status !== 'success') return;
                var sel = document.getElementById('transferNewDepartment');
                (r.departments || []).forEach(function (d) {
                    if ((d.status || 'Active') !== 'Active') return;
                    var opt = document.createElement('option');
                    opt.value = d.name; opt.textContent = d.name;
                    sel.appendChild(opt);
                });
            }).catch(function () {});
    }

    if (!_transferMgrLoaded) {
        _transferMgrLoaded = true;
        fetch('<?= base_url("hr/get_staff_list") ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                var staff = resp.staff || (resp.data && resp.data.staff) || [];
                var sel = document.getElementById('transferNewManager');
                var currentText = '(none set)';
                staff.forEach(function (s) {
                    if (s.id === PROMO_STAFF_ID) return; // no self-report
                    if (s.id === PROMO_CURRENT_MANAGER_ID) currentText = s.name;
                    var opt = document.createElement('option');
                    opt.value = s.id; opt.textContent = s.name + (s.position ? ' — ' + s.position : '');
                    sel.appendChild(opt);
                });
                document.getElementById('transferCurrentManager').value = currentText;
            }).catch(function () {
                document.getElementById('transferCurrentManager').value = PROMO_CURRENT_MANAGER_ID || '(none set)';
            });
    }

    document.getElementById('transferModalOverlay').style.display = 'flex';
}

function closeTransferModal() {
    document.getElementById('transferModalOverlay').style.display = 'none';
}

function submitTransfer() {
    var newDepartment = document.getElementById('transferNewDepartment').value;
    var newManager = document.getElementById('transferNewManager').value;
    var effectiveFrom = document.getElementById('transferEffectiveFrom').value;
    var reason = document.getElementById('transferReason').value.trim();

    if (!newDepartment && !newManager) {
        nsaShowAlert('error', 'Set at least one new value (department or reporting manager).');
        return;
    }
    if (!effectiveFrom) {
        nsaShowAlert('error', 'Effective date is required for a transfer.');
        return;
    }

    var btn = document.getElementById('submitTransferBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Recording…';

    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    if (newDepartment) fd.append('new_department', newDepartment);
    // Same "empty selection = leave unchanged, not clear" contract as the
    // Promotion modal — see that function's own comment for why.
    if (newManager) fd.append('new_reporting_manager_id', newManager);
    fd.append('effective_from', effectiveFrom);
    if (reason) fd.append('reason', reason);

    var scheduleChoice = document.querySelector('input[name="transferScheduleChoice"]:checked');
    var wantsScheduleChange = HR_MANAGE && scheduleChoice && scheduleChoice.value === 'change';
    var newScheduleId = wantsScheduleChange ? document.getElementById('transferNewSchedule').value : '';
    if (wantsScheduleChange && !newScheduleId) {
        nsaShowAlert('error', 'Select the new Work Schedule, or choose "Keep current".');
        return;
    }

    $.ajax({
        url: '<?= base_url("staff/transfer_staff") ?>',
        method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (!(res && res.status)) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-check"></i> Record Transfer';
                nsaShowAlert('error', (res && res.message) || 'Could not record the transfer.');
                return;
            }

            nsaShowAlert('success', res.message || 'Transfer recorded.');
            if (res.warning) nsaShowAlert('warning', res.warning);
            closeTransferModal();

            // The transfer itself has ALREADY succeeded at this point —
            // department transfer and schedule assignment are independent
            // facts, so nothing below this line can undo it. It only
            // decides whether to also attempt the explicit, opted-in
            // schedule composition via the existing save_assignment writer.
            if (!wantsScheduleChange) {
                setTimeout(function () { window.location.reload(); }, 1200);
                return;
            }

            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating Work Schedule…';
            _composeTransferScheduleChange(PROMO_STAFF_ID, newScheduleId, effectiveFrom)
                .then(function () {
                    nsaShowAlert('success', 'Work Schedule updated to match the transfer.');
                })
                .catch(function (err) {
                    nsaShowAlert('warning',
                        'Transfer was recorded, but the Work Schedule change could not be completed ('
                        + (err && err.message ? err.message : 'unknown error')
                        + '). A reminder has been added to this page.');
                    return _flagPendingScheduleReview(PROMO_STAFF_ID,
                        'Work Schedule composition failed after transfer: ' + (err && err.message ? err.message : 'unknown error'),
                        effectiveFrom);
                })
                .then(function () {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-check"></i> Record Transfer';
                    setTimeout(function () { window.location.reload(); }, 1200);
                });
        },
        error: function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-check"></i> Record Transfer';
            nsaShowAlert('error', 'Server error — please try again.');
        }
    });
}

/**
 * Composes the optional post-transfer schedule change purely by calling the
 * EXISTING, UNMODIFIED Work_schedules::save_assignment() — first to close
 * whatever staff-level assignment is currently open (if any), then to open
 * the new one, both effective the same date as the transfer. Never writes
 * staffScheduleAssignments directly. Returns a Promise; a rejection means
 * "the transfer already succeeded but this follow-up did not" — the caller
 * treats that as an independent, recoverable fact, not a failure to roll
 * back.
 */
function _composeTransferScheduleChange(staffId, newScheduleId, effectiveFrom) {
    return fetch('<?= base_url("work_schedules/get_data") ?>')
        .then(function (r) { return r.json(); })
        .then(function (r) {
            if (r.status !== 'success') throw new Error('Could not load current Work Schedule assignments.');
            var current = (r.assignments || []).find(function (a) {
                return a.attachmentType === 'staff' && a.attachmentId === staffId && !a.effectiveTo;
            });
            if (!current) return Promise.resolve();

            var closeTo = new Date(effectiveFrom + 'T00:00:00');
            closeTo.setDate(closeTo.getDate() - 1);
            var closeToISO = closeTo.toISOString().slice(0, 10);

            var fd = new FormData();
            fd.append('assignment_id', current.assignmentId);
            fd.append('attachment_type', 'staff');
            fd.append('attachment_id', staffId);
            fd.append('schedule_id', current.policyId);
            fd.append('effective_from', current.effectiveFrom);
            fd.append('effective_to', closeToISO);
            return $.ajax({
                url: '<?= base_url("work_schedules/save_assignment") ?>',
                method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            }).then(function (res) {
                if (!(res && res.status)) throw new Error((res && res.message) || 'Could not close the current Work Schedule assignment.');
            });
        })
        .then(function () {
            var fd = new FormData();
            fd.append('attachment_type', 'staff');
            fd.append('attachment_id', staffId);
            fd.append('schedule_id', newScheduleId);
            fd.append('effective_from', effectiveFrom);
            fd.append('effective_to', '');
            return $.ajax({
                url: '<?= base_url("work_schedules/save_assignment") ?>',
                method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            });
        })
        .then(function (res) {
            if (!(res && res.status)) throw new Error((res && res.message) || 'Could not create the new Work Schedule assignment.');
        });
}

function _flagPendingScheduleReview(staffId, reason, effectiveFrom) {
    var fd = new FormData();
    fd.append('staff_id', staffId);
    fd.append('reason', reason);
    fd.append('effective_from', effectiveFrom);
    return $.ajax({
        url: '<?= base_url("staff/flag_pending_schedule_review") ?>',
        method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
    }).catch(function () { /* best-effort — the toast already told the admin */ });
}

/* ══ NOTICE PERIOD MODAL (Staff HR Advanced Capability Programme,
   Phase 2) ══
   complete_notice_period() is intentionally called ONLY after the
   existing, unmodified staff/set_status endpoint has already succeeded —
   this UI never bypasses that endpoint or duplicates its logic. */
function openNoticeModal() {
    document.getElementById('noticeModalOverlay').style.display = 'flex';
    noticeLoad();
}
function closeNoticeModal() {
    document.getElementById('noticeModalOverlay').style.display = 'none';
}

function noticeLoad() {
    var body = document.getElementById('noticeModalBody');
    body.innerHTML = '<p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>';
    $.get('<?= base_url("staff/get_notice_period") ?>', { staff_id: PROMO_STAFF_ID }, function (res) {
        noticeRenderState((res && res.notice) ? res.notice : null);
    }, 'json').fail(function () {
        body.innerHTML = '<p style="color:var(--nsa-red,#dc2626);font-size:13px;">Could not load notice period status.</p>';
    });
}

function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

function noticeRenderState(notice) {
    var body = document.getElementById('noticeModalBody');
    var status = notice ? notice.status : null;

    if (!status || status === 'withdrawn' || status === 'rejected' || status === 'completed') {
        // No active notice — offer to open one. A withdrawn/rejected/
        // completed record stays in Firestore for history but no longer
        // blocks a fresh submission.
        var priorNote = '';
        if (status === 'rejected') priorNote = '<p style="font-size:12px;color:var(--nsa-muted);">A previous notice period was rejected on ' + esc((notice.decidedAt || '').slice(0, 10)) + '.</p>';
        if (status === 'completed') priorNote = '<p style="font-size:12px;color:var(--nsa-muted);">A previous notice period was completed on ' + esc((notice.updatedAt || '').slice(0, 10)) + '.</p>';
        body.innerHTML =
            priorNote +
            '<div class="nsa-field"><label>Notice Start Date</label>' +
            '<input type="date" id="noticeStartDate" class="nsa-input"></div>' +
            '<div class="nsa-field"><label>Reason</label>' +
            '<textarea id="noticeReason" class="nsa-input" rows="2" placeholder="e.g. Resigned to pursue further studies"></textarea></div>' +
            '<button type="button" class="nsa-btn nsa-btn-primary" style="margin-top:10px;" onclick="noticeSubmit()">' +
            '<i class="fa fa-flag"></i> Open Notice Period</button>';
        return;
    }

    if (status === 'pending') {
        body.innerHTML =
            '<div class="nsa-field"><label>Notice Start</label><input class="nsa-input" disabled value="' + esc(notice.noticeStartDate) + '"></div>' +
            '<div class="nsa-field"><label>Expected Last Working Day</label><input class="nsa-input" disabled value="' + esc(notice.expectedLastWorkingDay) + ' (' + esc(notice.noticeDurationDays) + ' days notice)"></div>' +
            (notice.reason ? '<div class="nsa-field"><label>Reason</label><textarea class="nsa-input" rows="2" disabled>' + esc(notice.reason) + '</textarea></div>' : '') +
            '<div class="nsa-field"><label>Adjusted Last Working Day <span class="nsa-optional-tag">only needed for Waive/Reduce</span></label>' +
            '<input type="date" id="noticeActualLwd" class="nsa-input" max="' + esc(notice.expectedLastWorkingDay) + '"></div>' +
            '<div class="nsa-field"><label>Decision Note</label><textarea id="noticeDecisionNote" class="nsa-input" rows="2"></textarea></div>' +
            '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">' +
            '<button type="button" class="nsa-btn nsa-btn-primary" onclick="noticeDecide(\'approve\')"><i class="fa fa-check"></i> Approve</button>' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" onclick="noticeDecide(\'waive\')"><i class="fa fa-forward"></i> Waive (Release Early)</button>' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" onclick="noticeDecide(\'reduce\')"><i class="fa fa-compress"></i> Reduce</button>' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" onclick="noticeDecide(\'reject\')" style="color:var(--nsa-red,#dc2626);"><i class="fa fa-times"></i> Reject</button>' +
            '</div>' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" style="margin-top:8px;font-size:12px;" onclick="noticeWithdraw()">Withdraw this notice period</button>';
        return;
    }

    if (status === 'approved' || status === 'waived' || status === 'reduced') {
        body.innerHTML =
            '<p style="font-size:13px;">Notice period <strong>' + esc(status) + '</strong>. Last working day: <strong>' + esc(notice.actualLastWorkingDay) + '</strong>.</p>' +
            (notice.decisionNote ? '<p style="font-size:12px;color:var(--nsa-muted);">' + esc(notice.decisionNote) + '</p>' : '') +
            '<button type="button" class="nsa-btn nsa-btn-primary" style="margin-top:8px;" onclick="noticeCompleteSeparation()">' +
            '<i class="fa fa-sign-out"></i> Complete Separation</button>' +
            '<p style="font-size:11px;color:var(--nsa-muted);margin-top:8px;">Marks the staff member Inactive via the existing Separation workflow, then closes this notice period.</p>';
        return;
    }

    body.innerHTML = '<p style="font-size:13px;color:var(--nsa-muted);">Unrecognized notice period state.</p>';
}

function noticeSubmit() {
    var startDate = document.getElementById('noticeStartDate').value;
    var reason = document.getElementById('noticeReason').value.trim();
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    if (startDate) fd.append('notice_start_date', startDate);
    if (reason) fd.append('reason', reason);
    $.ajax({
        url: '<?= base_url("staff/submit_resignation") ?>', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') { nsaShowAlert('success', res.message || 'Notice period opened.'); noticeLoad(); }
            else nsaShowAlert('error', (res && res.message) || 'Could not open notice period.');
        },
        error: function () { nsaShowAlert('error', 'Server error — please try again.'); }
    });
}

function noticeDecide(decision) {
    var actualLwd = document.getElementById('noticeActualLwd').value;
    var note = document.getElementById('noticeDecisionNote').value.trim();
    if ((decision === 'waive' || decision === 'reduce') && !actualLwd) {
        nsaShowAlert('error', 'Set the adjusted last working day for ' + decision + '.');
        return;
    }
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    fd.append('decision', decision);
    if (actualLwd) fd.append('actual_last_working_day', actualLwd);
    if (note) fd.append('decision_note', note);
    $.ajax({
        url: '<?= base_url("staff/decide_notice_period") ?>', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') { nsaShowAlert('success', res.message || 'Decision recorded.'); noticeLoad(); }
            else nsaShowAlert('error', (res && res.message) || 'Could not record the decision.');
        },
        error: function () { nsaShowAlert('error', 'Server error — please try again.'); }
    });
}

async function noticeWithdraw() {
    if (!await zxConfirm({ title: 'Withdraw this notice period?' })) return;
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    $.ajax({
        url: '<?= base_url("staff/withdraw_resignation") ?>', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') { nsaShowAlert('success', 'Notice period withdrawn.'); noticeLoad(); }
            else nsaShowAlert('error', (res && res.message) || 'Could not withdraw.');
        },
        error: function () { nsaShowAlert('error', 'Server error — please try again.'); }
    });
}

/* Chains the EXISTING, unmodified staff/set_status endpoint (Inactive) —
   the exact same call the pre-existing separation modal already makes —
   then, only on its success, calls complete_notice_period() to close the
   notice record out. set_status()'s own logic is never duplicated here. */
async function noticeCompleteSeparation() {
    if (!await zxConfirm({ title: 'Mark this staff member Inactive and complete the notice period? This uses the existing Separation workflow.' })) return;
    var fd = new FormData();
    fd.append('status', 'Inactive');
    fd.append('reason', 'Resignation — notice period completed');
    $.ajax({
        url: '<?= base_url("staff/set_status/") ?>' + encodeURIComponent(PROMO_STAFF_ID),
        method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (!res || !res.status) {
                nsaShowAlert('error', (res && res.message) || 'Separation failed.');
                return;
            }
            var fd2 = new FormData();
            fd2.append('staff_id', PROMO_STAFF_ID);
            $.ajax({
                url: '<?= base_url("staff/complete_notice_period") ?>', method: 'POST', data: fd2,
                processData: false, contentType: false, dataType: 'json',
                success: function (res2) {
                    nsaShowAlert('success', 'Staff separated and notice period completed.');
                    if (!res2 || !res2.status) nsaShowAlert('warning', (res2 && res2.message) || 'Separation succeeded, but the notice record could not be closed out — check the audit log.');
                    noticeLoad();
                },
                error: function () { nsaShowAlert('warning', 'Separation succeeded, but closing the notice record failed — please retry.'); noticeLoad(); }
            });
        },
        error: function () { nsaShowAlert('error', 'Server error during separation — please try again.'); }
    });
}

/* ══ CLEARANCE / HANDOVER MODAL (Staff HR Advanced Capability Programme,
   Phase 3) ══ */
function openClearanceModal() {
    document.getElementById('clearanceModalOverlay').style.display = 'flex';
    clearanceLoad();
}
function closeClearanceModal() {
    document.getElementById('clearanceModalOverlay').style.display = 'none';
}

function clearanceLoad() {
    var body = document.getElementById('clearanceModalBody');
    body.innerHTML = '<p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>';
    $.get('<?= base_url("staff/get_clearance") ?>', { staff_id: PROMO_STAFF_ID }, function (res) {
        clearanceRenderState((res && res.clearance) ? res.clearance : null);
    }, 'json').fail(function () {
        body.innerHTML = '<p style="color:var(--nsa-red,#dc2626);font-size:13px;">Could not load clearance status.</p>';
    });
}

var CLEARANCE_STATUS_COLOR = { pending: '#d97706', cleared: '#059669', flagged: '#dc2626' };
var CLEARANCE_STATUS_ICON  = { pending: 'fa-clock-o', cleared: 'fa-check-circle', flagged: 'fa-flag' };

function clearanceRenderState(clearance) {
    var body = document.getElementById('clearanceModalBody');

    if (!clearance || clearance.status !== 'in_progress') {
        var priorNote = (clearance && clearance.status === 'completed')
            ? '<p style="font-size:12px;color:var(--nsa-muted);">A previous clearance process was completed on ' + esc((clearance.completedAt || '').slice(0, 10)) + '.</p>'
            : '';
        body.innerHTML = priorNote +
            '<p style="font-size:13px;color:var(--nsa-muted);margin-bottom:10px;">No clearance process is currently open for this staff member.</p>' +
            '<button type="button" class="nsa-btn nsa-btn-primary" onclick="clearanceInitiate()"><i class="fa fa-flag"></i> Start Clearance Process</button>';
        return;
    }

    var depts = clearance.departments || {};
    var rows = '';
    Object.keys(depts).forEach(function (dept) {
        var d = depts[dept] || {};
        var st = d.status || 'pending';
        var color = CLEARANCE_STATUS_COLOR[st] || '#d97706';
        var icon = CLEARANCE_STATUS_ICON[st] || 'fa-clock-o';
        rows +=
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--nsa-border,#e2e8f0);">' +
            '<div><i class="fa ' + icon + '" style="color:' + color + ';margin-right:6px;"></i><strong>' + esc(dept) + '</strong>' +
            (d.note ? '<div style="font-size:11px;color:var(--nsa-muted);margin-top:2px;">' + esc(d.note) + '</div>' : '') + '</div>' +
            '<div style="display:flex;gap:6px;">' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" style="padding:4px 10px;font-size:11px;" onclick="clearanceUpdateDept(' + JSON.stringify(dept) + ',\'cleared\')">Clear</button>' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" style="padding:4px 10px;font-size:11px;color:#dc2626;" onclick="clearanceUpdateDept(' + JSON.stringify(dept) + ',\'flagged\')">Flag</button>' +
            '</div></div>';
    });

    body.innerHTML =
        '<p style="font-size:12px;color:var(--nsa-muted);margin-bottom:8px;">Every department must be marked Cleared before this process can complete. A Flagged department blocks completion.</p>' +
        rows;
}

function clearanceInitiate() {
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    $.ajax({
        url: '<?= base_url("staff/initiate_clearance") ?>', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') { nsaShowAlert('success', res.message || 'Clearance started.'); clearanceLoad(); }
            else nsaShowAlert('error', (res && res.message) || 'Could not start clearance.');
        },
        error: function () { nsaShowAlert('error', 'Server error — please try again.'); }
    });
}

async function clearanceUpdateDept(dept, status) {
    var note = '';
    if (status === 'flagged') {
        // UI-D3: optional, so the dialog does not force a value.
        note = await zxPrompt({
            title: 'Flag ' + dept,
            message: 'Reason for flagging this department (optional):',
            placeholder: 'Optional',
            confirmText: 'Flag department',
            required: false
        });
        if (note === null) return;
    }
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    fd.append('department', dept);
    fd.append('dept_status', status);
    if (note) fd.append('note', note);
    $.ajax({
        url: '<?= base_url("staff/update_clearance_department") ?>', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') {
                nsaShowAlert('success', res.overall_status === 'completed' ? 'Clearance fully completed!' : 'Department updated.');
                clearanceLoad();
            } else {
                nsaShowAlert('error', (res && res.message) || 'Could not update department.');
            }
        },
        error: function () { nsaShowAlert('error', 'Server error — please try again.'); }
    });
}

/* ══ ONBOARDING CHECKLIST MODAL (Staff HR Advanced Capability Programme,
   Phase 6) ══ */
function openOnboardingModal() {
    document.getElementById('onboardingModalOverlay').style.display = 'flex';
    onboardingLoad();
}
function closeOnboardingModal() {
    document.getElementById('onboardingModalOverlay').style.display = 'none';
}

function onboardingLoad() {
    var body = document.getElementById('onboardingModalBody');
    body.innerHTML = '<p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>';
    $.get('<?= base_url("staff/get_onboarding") ?>', { staff_id: PROMO_STAFF_ID }, function (res) {
        onboardingRenderState((res && res.onboarding) ? res.onboarding : null);
    }, 'json').fail(function () {
        body.innerHTML = '<p style="color:var(--nsa-red,#dc2626);font-size:13px;">Could not load onboarding status.</p>';
    });
}

var ONBOARDING_STATUS_COLOR = { pending: '#d97706', done: '#059669', skipped: '#64748b' };
var ONBOARDING_STATUS_ICON  = { pending: 'fa-clock-o', done: 'fa-check-circle', skipped: 'fa-minus-circle' };

function onboardingRenderState(onboarding) {
    var body = document.getElementById('onboardingModalBody');

    if (!onboarding || onboarding.status !== 'in_progress') {
        var priorNote = (onboarding && onboarding.status === 'completed')
            ? '<p style="font-size:12px;color:var(--nsa-muted);">A previous onboarding checklist was completed on ' + esc((onboarding.completedAt || '').slice(0, 10)) + '.</p>'
            : '';
        body.innerHTML = priorNote +
            '<p style="font-size:13px;color:var(--nsa-muted);margin-bottom:10px;">No onboarding checklist is currently open for this staff member.</p>' +
            '<button type="button" class="nsa-btn nsa-btn-primary" onclick="onboardingInitiate()"><i class="fa fa-flag"></i> Start Onboarding</button>';
        return;
    }

    var tasks = onboarding.tasks || {};
    var rows = '';
    Object.keys(tasks).forEach(function (task) {
        var t = tasks[task] || {};
        var st = t.status || 'pending';
        var color = ONBOARDING_STATUS_COLOR[st] || '#d97706';
        var icon = ONBOARDING_STATUS_ICON[st] || 'fa-clock-o';
        rows +=
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--nsa-border,#e2e8f0);">' +
            '<div><i class="fa ' + icon + '" style="color:' + color + ';margin-right:6px;"></i><strong>' + esc(task) + '</strong>' +
            (t.note ? '<div style="font-size:11px;color:var(--nsa-muted);margin-top:2px;">' + esc(t.note) + '</div>' : '') + '</div>' +
            '<div style="display:flex;gap:6px;">' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" style="padding:4px 10px;font-size:11px;" onclick="onboardingUpdateTask(' + JSON.stringify(task) + ',\'done\')">Done</button>' +
            '<button type="button" class="nsa-btn nsa-btn-ghost" style="padding:4px 10px;font-size:11px;color:#64748b;" onclick="onboardingUpdateTask(' + JSON.stringify(task) + ',\'skipped\')">N/A</button>' +
            '</div></div>';
    });

    body.innerHTML =
        '<p style="font-size:12px;color:var(--nsa-muted);margin-bottom:8px;">Every task must be marked Done or N/A before this checklist can complete.</p>' +
        rows;
}

function onboardingInitiate() {
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    $.ajax({
        url: '<?= base_url("staff/initiate_onboarding") ?>', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') { nsaShowAlert('success', res.message || 'Onboarding started.'); onboardingLoad(); }
            else nsaShowAlert('error', (res && res.message) || 'Could not start onboarding.');
        },
        error: function () { nsaShowAlert('error', 'Server error — please try again.'); }
    });
}

async function onboardingUpdateTask(task, status) {
    var note = '';
    if (status === 'skipped') {
        // UI-D3: optional, so the dialog does not force a value.
        note = await zxPrompt({
            title: 'Mark "' + task + '" not applicable',
            message: 'Reason (optional):',
            placeholder: 'Optional',
            confirmText: 'Mark N/A',
            required: false
        });
        if (note === null) return;
    }
    var fd = new FormData();
    fd.append('staff_id', PROMO_STAFF_ID);
    fd.append('task', task);
    fd.append('task_status', status);
    if (note) fd.append('note', note);
    $.ajax({
        url: '<?= base_url("staff/update_onboarding_task") ?>', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.status !== 'error') {
                nsaShowAlert('success', res.overall_status === 'completed' ? 'Onboarding fully completed!' : 'Task updated.');
                onboardingLoad();
            } else {
                nsaShowAlert('error', (res && res.message) || 'Could not update task.');
            }
        },
        error: function () { nsaShowAlert('error', 'Server error — please try again.'); }
    });
}

/* ══ EXIT DOCUMENTS MODAL (Staff HR Advanced Capability Programme,
   Phase 4) ══ Simple links — no AJAX round-trip needed before opening the
   print view; the print endpoint enforces its own Inactive+exitDate gate
   server-side. */
function openExitDocsModal() {
    document.getElementById('exitDocsModalOverlay').style.display = 'flex';
}
function closeExitDocsModal() {
    document.getElementById('exitDocsModalOverlay').style.display = 'none';
}
function printExitDocument(type) {
    window.open('<?= base_url("staff/print_exit_document/") ?>' + encodeURIComponent(PROMO_STAFF_ID) + '?type=' + encodeURIComponent(type), '_blank');
}

/* ══ EXIT SUMMARY MODAL (Staff HR Advanced Capability Programme, Phase 5)
   ══ READ-ONLY aggregation view. Every value rendered below comes straight
   from Staff::get_exit_summary()'s response — this file performs no
   arithmetic on money figures, only display formatting. */
function nsaFmtCurrency(v) {
    var n = Number(v || 0);
    return '₹' + n.toLocaleString('en-IN', { maximumFractionDigits: 2 });
}
function openExitSummaryModal() {
    document.getElementById('exitSummaryModalOverlay').style.display = 'flex';
    exitSummaryLoad();
}
function closeExitSummaryModal() {
    document.getElementById('exitSummaryModalOverlay').style.display = 'none';
}
function exitSummaryLoad() {
    var body = document.getElementById('exitSummaryModalBody');
    body.innerHTML = '<p style="color:var(--nsa-muted);font-size:13px;"><i class="fa fa-spinner fa-spin"></i> Loading…</p>';
    $.ajax({
        url: '<?= base_url("staff/get_exit_summary") ?>', method: 'GET',
        data: { staff_id: PROMO_STAFF_ID }, dataType: 'json',
        success: function (res) {
            if (res && res.status && res.summary) {
                exitSummaryRender(res.summary);
            } else {
                body.innerHTML = '<p style="color:#c0392b;font-size:13px;">' + ((res && res.message) || 'Could not load exit summary.') + '</p>';
            }
        },
        error: function () { body.innerHTML = '<p style="color:#c0392b;font-size:13px;">Server error — please try again.</p>'; }
    });
}
function exitSummaryRender(s) {
    var body = document.getElementById('exitSummaryModalBody');
    var rows = '';
    rows += '<div class="nsa-field"><label>Last Working Day</label><input class="nsa-input" disabled value="' + esc(s.lastWorkingDay || '—') + '"></div>';

    var payslip = s.latestPayslip;
    var payslipText = payslip
        ? (payslip.month + ' ' + payslip.year + ' — Net: ' + nsaFmtCurrency(payslip.net_pay) + ', Gross: ' + nsaFmtCurrency(payslip.gross)
            + ', Deductions: ' + nsaFmtCurrency(payslip.total_deductions) + ', LWP days: ' + (payslip.lwp_days || 0))
        : 'No finalized/paid payroll run found for this staff member.';
    rows += '<div class="nsa-field"><label>Latest Finalized Payroll</label><input class="nsa-input" disabled value="' + esc(payslipText) + '"></div>';

    var leaveBal = s.leaveBalance;
    var leaveText = (leaveBal && Object.keys(leaveBal).length)
        ? Object.keys(leaveBal).map(function (k) { return k + ': ' + leaveBal[k]; }).join(' | ')
        : 'No leave balance record found.';
    rows += '<div class="nsa-field"><label>Leave Balance</label><input class="nsa-input" disabled value="' + esc(leaveText) + '"></div>';

    var clearance = s.clearance;
    var clearanceText = clearance ? ('Overall: ' + (clearance.status || 'pending')) : 'Clearance not yet initiated.';
    rows += '<div class="nsa-field"><label>Existing Dues (Clearance Status)</label><input class="nsa-input" disabled value="' + esc(clearanceText) + '"></div>';

    rows += '<div class="nsa-field"><label>Pending Reimbursements</label><input class="nsa-input" disabled value="Not tracked in ZenX (no expense/reimbursement claim workflow exists)."></div>';

    body.innerHTML = rows;
}
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
    box-shadow: 0 0 0 3px rgba(188,90,60,.1);
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
    background: #f0fdf8; border: 1.5px solid rgba(188,90,60,.2);
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
    box-shadow: 0 -4px 16px rgba(15,23,42,.10);
    padding: 16px 22px;
    display: flex; align-items: center; justify-content: flex-end; gap: 12px;
    /* wrap, not overflow: this row is sticky, so anything running past the
       viewport width would be unreachable rather than merely ugly. */
    flex-wrap: wrap;
    /* Keep Save reachable on a long form without scrolling to the bottom. */
    position: sticky; bottom: 12px; z-index: 20;
    border: 1px solid var(--nsa-border, #e2e8f0);
}

/* ── Wizard: same visual language as Add Staff ── */
.nsa-layout { grid-template-columns: 1fr; }
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
    cursor: pointer; background: none; border: 0; font: inherit; color: inherit;
}
.nsa-step:not(:last-child)::after {
    content: ''; position: absolute; top: 15px; left: calc(50% + 21px); right: calc(-50% + 21px);
    height: 2px; background: var(--nsa-border); z-index: 0;
}
.nsa-step.visited:not(:last-child)::after { background: var(--nsa-teal); }
.nsa-step-circle {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 700;
    background: var(--nsa-bg); border: 2px solid var(--nsa-border); color: var(--nsa-muted);
    z-index: 1; transition: all .15s;
}
.nsa-step.active .nsa-step-circle { border-color: var(--nsa-teal); background: var(--nsa-teal); color: #fff; box-shadow: 0 0 0 4px rgba(188,90,60,.15); }
.nsa-step.visited .nsa-step-circle { border-color: var(--nsa-teal); background: #fff; color: var(--nsa-teal); }
.nsa-step.has-error .nsa-step-circle { border-color: #dc2626; background: #fee2e2; color: #991b1b; }
.nsa-step-label { font-size: 10.5px; font-weight: 600; color: var(--nsa-muted); max-width: 100px; line-height: 1.25; transition: color .15s; }
.nsa-step.active .nsa-step-label { color: var(--nsa-navy); }
.nsa-step.visited .nsa-step-label { color: var(--nsa-teal); }
.nsa-step.has-error .nsa-step-label { color: #991b1b; }
.nsa-step:hover .nsa-step-circle { transform: scale(1.1); border-color: var(--nsa-teal); }
.nsa-step:hover .nsa-step-label { color: var(--nsa-teal); }
.nsa-step:focus-visible { outline: 2px solid var(--nsa-teal); outline-offset: 3px; border-radius: 6px; }

.nsa-stepper-progress { height: 4px; background: var(--nsa-border); border-radius: 4px; margin: 8px 0 22px; overflow: hidden; }
.nsa-stepper-progress-bar { height: 100%; background: var(--nsa-teal); transition: width .25s ease; }

.nsa-step-panel { display: none; opacity: 0; transform: translateY(10px);
                  transition: opacity .28s ease, transform .28s ease; }
.nsa-step-panel.nsa-step-in { opacity: 1; transform: translateY(0); }

.nsa-wizard-footer {
    position: sticky; bottom: 12px;
    background: var(--nsa-white);
    border-radius: var(--nsa-radius);
    box-shadow: 0 4px 20px rgba(12,30,56,.14);
    padding: 14px 22px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    flex-wrap: wrap;
    z-index: 20;
}
.nsa-wizard-footer-status { font-size: 12.5px; color: var(--nsa-muted); font-weight: 600; }
.nsa-wizard-footer-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

@media (prefers-reduced-motion: reduce) {
    .nsa-step-panel { transition: none; }
}

/* ── Pointer to where documents actually live (UX-D3) ── */
.nsa-doc-pointer {
    display: flex; align-items: flex-start; gap: 9px;
    padding: 11px 13px; border-radius: 9px;
    background: var(--nsa-bg, #f8fafc);
    border: 1px dashed var(--nsa-border, #e2e8f0);
    font-size: 12.5px; line-height: 1.5; color: var(--nsa-muted, #64748b);
}
.nsa-doc-pointer > i { color: var(--nsa-teal, #BC5A3C); margin-top: 1px; }

/* ── Employee lifecycle: workflows, not form controls ── */
.nsa-lifecycle-hint {
    margin: 0 0 14px; font-size: 13px; line-height: 1.5;
    color: var(--nsa-muted, #64748b);
}
.nsa-lifecycle-grid {
    display: grid; gap: 10px;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
}
.nsa-lifecycle-btn {
    display: flex; flex-direction: column; align-items: flex-start; gap: 3px;
    padding: 13px 15px; text-align: left; cursor: pointer;
    background: var(--nsa-white, #fff);
    border: 1px solid var(--nsa-border, #e2e8f0);
    border-radius: 10px;
    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
}
.nsa-lifecycle-btn:hover {
    border-color: var(--nsa-gold, #BC5A3C);
    box-shadow: 0 3px 12px rgba(15,23,42,.08);
    transform: translateY(-1px);
}
.nsa-lifecycle-btn:focus-visible {
    outline: 2px solid var(--nsa-gold, #BC5A3C); outline-offset: 2px;
}
.nsa-lifecycle-btn > i {
    font-size: 15px; color: var(--nsa-gold, #BC5A3C); margin-bottom: 2px;
}
.nsa-lifecycle-label {
    font-size: 13.5px; font-weight: 600; color: var(--nsa-text, #22160F);
}
.nsa-lifecycle-sub {
    font-size: 11.5px; line-height: 1.35; color: var(--nsa-muted, #64748b);
}

/* ── Phone input-group (+91 prefix), parity with Add Staff ── */
.phone-ig { display: flex; align-items: stretch; }
.phone-ig .phone-pfx {
    display: flex; align-items: center; padding: 0 11px;
    background: var(--nsa-sky, #F7ECE7); color: var(--nsa-text, #22160F);
    border: 1px solid var(--nsa-border, #e2e8f0); border-right: none;
    border-radius: 8px 0 0 8px; font-size: 13px; font-weight: 600; white-space: nowrap;
}
.phone-ig .nsa-input { border-radius: 0 8px 8px 0; flex: 1; min-width: 0; }

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

/* ── Promotion Modal (Staff HR Advanced Capability Programme, Phase 1) ── */
.nsa-modal-overlay {
    position: fixed; inset: 0; background: rgba(34,22,15,.45);
    display: flex; align-items: center; justify-content: center; z-index: 9999;
    padding: 20px;
}
.nsa-modal {
    background: var(--nsa-white, #fff); border-radius: 14px; padding: 24px 26px;
    width: 100%; max-height: 90vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.nsa-modal-title {
    font-family: 'Lora', serif; font-size: 19px; font-weight: 600;
    color: var(--nsa-text, #22160F); margin-bottom: 4px;
    display: flex; align-items: center; gap: 9px;
}
.nsa-modal-actions {
    display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px;
}
</style>