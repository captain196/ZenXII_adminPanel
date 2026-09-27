<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="sa-wrap">

    <!-- ── Top bar ── -->
    <div class="sa-topbar">
        <h1 class="sa-page-title">
            <i class="fa fa-user-plus"></i> Add New Student
        </h1>
        <ol class="sa-breadcrumb">
            <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
            <li><a href="<?= base_url('sis/students') ?>">All Students</a></li>
            <li>Add Student</li>
        </ol>
    </div>

    <!-- ── Layout ── -->
    <div class="sa-layout">

        <!-- Sidebar nav -->
        <aside class="sa-sidebar">
            <div class="sa-sidebar-title">Form Sections</div>
            <a class="sa-nav-item active" href="#sec-basic"><i class="fa fa-user"></i> <span>Basic Info</span></a>
            <a class="sa-nav-item" href="#sec-parents"><i class="fa fa-users"></i> <span>Parents</span></a>
            <a class="sa-nav-item" href="#sec-address"><i class="fa fa-map-marker"></i> <span>Address</span></a>
            <a class="sa-nav-item" href="#sec-previous"><i class="fa fa-university"></i> <span>Prev. School</span></a>
            <a class="sa-nav-item" href="#sec-other"><i class="fa fa-info-circle"></i> <span>Other Details</span></a>
            <a class="sa-nav-item" href="#sec-docs"><i class="fa fa-file-text-o"></i> <span>Documents</span></a>
            <a class="sa-nav-item" href="#sec-fees"><i class="fa fa-money"></i> <span>Fees & Photo</span></a>
        </aside>

        <!-- Main form -->
        <div class="sa-main">
            <form action="<?= base_url('sis/save_admission') ?>"
                  method="post"
                  id="add_student_form"
                  enctype="multipart/form-data">
                  <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" 
           value="<?= $this->security->get_csrf_hash() ?>">

                    <!-- Tab wizard: one chapter at a time. Steps are declared
                         here and bound to the .sa-section ids below. -->
                    <nav class="sa-wiz" id="saWiz" aria-label="Admission steps"></nav>
                    <div class="sa-wiz-progress"><i id="saWizBar" style="width:0"></i></div>

                <!-- ══ BASIC INFORMATION ══ -->
                <div class="sa-section" id="sec-basic">
                    <div class="sa-section-head">
                        <i class="fa fa-user"></i>
                        <h3>Basic Information</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid">

                            <?php /* IDENTITY ROW (2026-08-16) — the three identifiers sit
                                     together, ahead of everything else, so an operator reads
                                     WHO this record is before HOW it is placed. SR Number and
                                     APAAR were previously stranded in the second row beneath
                                     Roll No, which put a session-scoped, reassignable number
                                     visually ahead of the permanent school-facing identity. */ ?>
                            <div class="sa-field">
                                <label>Student ID</label>
                                <input type="text" name="user_id" id="user_id"
                                       value="<?= htmlspecialchars($user_Id ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" readonly>
                            </div>

                            <div class="sa-field">
                                <label>SR Number</label>
                                <p class="sa-input" style="background:var(--input-bg,#f9fafb);color:var(--t3,#6b7280);display:flex;align-items:center;gap:6px;cursor:default;">
                                    <i class="fa fa-magic"></i> Assigned automatically on admission
                                </p>
                            </div>

                            <div class="sa-field">
                                <label>APAAR ID</label>
                                <input type="text" name="apaar_id" id="apaar_id" class="sa-input"
                                       placeholder="Optional — 12-digit government student ID"
                                       maxlength="14" inputmode="numeric" autocomplete="off">
                                <p id="apaarIdError" style="display:none;font-size:11px;color:#dc2626;margin:4px 0 0;">
                                    An APAAR ID is 12 digits. Check the number and try again, or leave it blank.
                                </p>
                            </div>

                            <div class="sa-field">
                                <label>Student Name <span class="req">*</span></label>
                                <input type="text" name="name" id="sname" aria-required="true"
                                       class="sa-input" placeholder="Full name" required>
                            </div>

                            <div class="sa-field">
                                <label>Class <span class="req">*</span></label>
                                <select id="class_name" name="class" class="sa-select" aria-required="true" required>
                                    <option value="" disabled selected>Select Class</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Section <span class="req">*</span></label>
                                <select id="section" name="section" class="sa-select" aria-required="true" required>
                                    <option value="" disabled selected>Select Section</option>
                                </select>
                                <!-- Phase 15: live per-section occupancy + the most-headroom
                                     suggestion, from the SAME endpoint the CRM screen uses.
                                     Advisory only — Sis::save_admission() re-validates the
                                     final choice against the live roster at commit time. -->
                                <small id="sectionCapacityHint" style="display:none;font-size:.76rem;"></small>
                            </div>

                            <!-- FIXED: roll_no field was missing → backend always stored empty string -->
                            <!-- Roll Number Research phase: manual entry remains the default and
                                 primary path (matches prior ZenX behaviour and Fedena precedent —
                                 see STUDENT_LIFECYCLE_ROLL_NUMBER_RESEARCH_AND_IMPLEMENTATION_REPORT.md
                                 §4/§8). The "Suggest" button is an OPT-IN convenience — it fills the
                                 box with the next free number for the selected class+section, but
                                 never assigns anything silently, and the field stays fully editable/
                                 overridable afterward. Server-side validation at save is unchanged. -->
                            <div class="sa-field">
                                <label>Roll No</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="text" name="roll_no" id="roll_no"
                                           class="sa-input" placeholder="Optional" style="flex:1;">
                                    <button type="button" id="suggestRollBtn" class="sa-btn-ghost"
                                            style="white-space:nowrap;padding:8px 12px;border-radius:8px;border:1px solid var(--b1,#e2e8f0);background:var(--bg2,#f8fafc);cursor:pointer;font-size:.82rem;">
                                        Suggest
                                    </button>
                                </div>
                                <small id="suggestRollHint" style="display:none;color:var(--t3,#6b7280);font-size:.76rem;"></small>
                            </div>

                            <?php /* SR Number and APAAR ID moved to the identity row at the top
                                     of this section (2026-08-16). Phase 9.1 semantics unchanged:
                                     SRNO is MANDATORY, automatic and server-allocated via
                                     Sr_no_service::allocateNext() — no operator input, no
                                     editable field, no client-trusted value. APAAR remains a
                                     separate optional operator-entered attribute wired to
                                     Apaar_service through Sis::save_admission(). */ ?>

                            <div class="sa-field">
                                <label>Date of Birth <span class="req">*</span></label>
                                <?php /* ADM-INPUT-1: required, and `max` is today so the native
                                         picker cannot offer a future date at all. Both are
                                         re-checked in validateAdmissionForm() and again by
                                         save_admission(), which is the only authority. */ ?>
                                <input type="date" name="dob" id="dob" class="sa-input"
                                       aria-required="true" required
                                       max="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="sa-field">
                                <label>Gender</label>
                                <select name="gender" id="gender" class="sa-select">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Admission Date</label>
                                <?php /* Defaults to today (2026-08-16 fix). Sis::save_admission()
                                        already falls back to date('d-m-Y') when this arrives empty,
                                        so the field was `required` purely to force a click that the
                                        server did not need. Pre-filling matches the real-world case —
                                        a student is almost always admitted today — while the picker
                                        stays fully editable for a back-dated admission. */ ?>
                                <input type="date" name="admission_date" id="admission_date" class="sa-input"
                                       value="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="sa-field">
                                <label>Email</label>
                                <input type="email" name="email" id="email_user"
                                       class="sa-input" placeholder="student@email.com">
                            </div>

                            <div class="sa-field">
                                <label>Phone Number <span class="req">*</span></label>
                                <?php /* ADM-INPUT-1: required. This is the number the school
                                         calls, the key the Parent app resolves the child by,
                                         and half of the duplicate-admission check. */ ?>
                                <input type="tel" name="phone_number" id="phone_number"
                                       inputmode="numeric" maxlength="10" autocomplete="tel"
                                       aria-required="true" required
                                       class="sa-input" placeholder="10-digit mobile number">
                            </div>

                            <div class="sa-field">
                                <label>Blood Group</label>
                                <select name="blood_group" id="blood_group" class="sa-select">
                                    <option value="">Select</option>
                                    <option>A+</option><option>A-</option>
                                    <option>B+</option><option>B-</option>
                                    <option>O+</option><option>O-</option>
                                    <option>AB+</option><option>AB-</option>
                                    <option>Unknown</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Category</label>
                                <select name="category" id="category" class="sa-select">
                                    <option value="">Select</option>
                                    <option>General</option>
                                    <option>OBC</option>
                                    <option>SC</option>
                                    <option>ST</option>
                                </select>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ PARENTS DETAILS ══ -->
                <div class="sa-section" id="sec-parents">
                    <div class="sa-section-head">
                        <i class="fa fa-users"></i>
                        <h3>Parents Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid">

                            <div class="sa-field">
                                <label>Father's Name</label>
                                <input type="text" name="father_name" id="father_name" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>Father's Occupation</label>
                                <input type="text" name="father_occupation" id="father_occupation" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>Father's Contact</label>
                                <input type="tel" name="guard_contact" id="guard_contact" inputmode="numeric" maxlength="10" autocomplete="tel" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>Guardian Relation</label>
                                <input type="text" name="guard_relation" id="guard_relation" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>Mother's Name</label>
                                <input type="text" name="mother_name" id="mother_name" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>Mother's Occupation</label>
                                <input type="text" name="mother_occupation" id="mother_occupation" class="sa-input">
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ ADDRESS ══ -->
                <div class="sa-section" id="sec-address">
                    <div class="sa-section-head">
                        <i class="fa fa-map-marker"></i>
                        <h3>Address Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid">

                            <div class="sa-field sa-col-2">
                                <label>Street</label>
                                <input type="text" name="street" id="street" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>State</label>
                                <select name="state" id="state" class="sa-select">
                                    <option value="">Select State</option>
                                    <option>Andhra Pradesh</option><option>Arunachal Pradesh</option>
                                    <option>Assam</option><option>Bihar</option>
                                    <option>Chhattisgarh</option><option>Goa</option>
                                    <option>Gujarat</option><option>Haryana</option>
                                    <option>Himachal Pradesh</option><option>Jharkhand</option>
                                    <option>Karnataka</option><option>Kerala</option>
                                    <option>Madhya Pradesh</option><option>Maharashtra</option>
                                    <option>Manipur</option><option>Meghalaya</option>
                                    <option>Mizoram</option><option>Nagaland</option>
                                    <option>Odisha</option><option>Punjab</option>
                                    <option>Rajasthan</option><option>Sikkim</option>
                                    <option>Tamil Nadu</option><option>Telangana</option>
                                    <option>Tripura</option><option>Uttar Pradesh</option>
                                    <option>Uttarakhand</option><option>West Bengal</option>
                                    <option>Andaman and Nicobar Islands</option>
                                    <option>Chandigarh</option>
                                    <option>Dadra and Nagar Haveli and Daman and Diu</option>
                                    <option>Delhi</option>
                                    <option>Jammu and Kashmir</option><option>Ladakh</option>
                                    <option>Lakshadweep</option><option>Puducherry</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>District</label>
                                <select name="city" id="city" class="sa-select" data-india-district="state">
                                    <option value="">Select District</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Postal Code</label>
                                <input type="text" name="postal_code" id="postal_code"
                                       inputmode="numeric" maxlength="6" autocomplete="postal-code"
                                       class="sa-input" placeholder="6-digit PIN — auto-fills State & District">
                                <small id="pinLookupHint" class="sa-field-hint" aria-live="polite" style="display:none;font-size:.76rem;"></small>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ PREVIOUS SCHOOL ══ -->
                <div class="sa-section" id="sec-previous">
                    <div class="sa-section-head">
                        <i class="fa fa-university"></i>
                        <h3>Previous School Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-3">

                            <div class="sa-field">
                                <label>Previous Class</label>
                                <input type="text" name="pre_class" id="pre_class" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>Previous School Name</label>
                                <input type="text" name="pre_school" id="pre_school" class="sa-input">
                            </div>

                            <div class="sa-field">
                                <label>Marks %</label>
                                <input type="text" name="pre_marks" id="pre_marks"
                                       class="sa-input" placeholder="e.g. 85%">
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ OTHER DETAILS ══ -->
                <div class="sa-section" id="sec-other">
                    <div class="sa-section-head">
                        <i class="fa fa-info-circle"></i>
                        <h3>Other Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-3">

                            <div class="sa-field">
                                <label>Religion</label>
                                <select name="religion" id="religion" class="sa-select"
                                        onchange="toggleOtherReligion(this)">
                                    <option value="">Select Religion</option>
                                    <option>Hindu</option><option>Muslim</option>
                                    <option>Sikh</option><option>Jain</option>
                                    <option>Buddh</option><option>Christian</option>
                                    <option>Other</option>
                                </select>
                                <input type="text" name="other_religion" id="other_religion"
                                       class="sa-input" placeholder="Please specify"
                                       style="display:none;margin-top:8px;">
                            </div>

                            <div class="sa-field">
                                <label>Nationality</label>
                                <input type="text" name="nationality" id="nationality"
                                       class="sa-input" placeholder="e.g. Indian">
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ DOCUMENTS ══ -->
                <div class="sa-section" id="sec-docs">
                    <div class="sa-section-head">
                        <i class="fa fa-file-text-o"></i>
                        <h3>Documents</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-3">

                            <div class="sa-field">
                                <label>Birth Certificate <span class="sa-opt">(optional)</span></label>
                                <div class="sa-file-wrap" id="wrap_birthCertificate">
                                    <input type="file" name="birthCertificate" id="birthCertificate"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="saFileChosen(this,'wrap_birthCertificate')">
                                    <div class="sa-file-label">
                                        <div class="sa-file-icon"><i class="fa fa-file-pdf-o"></i></div>
                                        <div class="sa-file-text">
                                            <strong>Upload File</strong>
                                            PDF, JPG, PNG · max 2MB
                                        </div>
                                    </div>
                                    <div class="sa-file-name" id="fn_birthCertificate"></div>
                                </div>
                            </div>

                            <div class="sa-field">
                                <label>Aadhar Card <span class="sa-opt">(optional)</span></label>
                                <div class="sa-file-wrap" id="wrap_aadharCard">
                                    <input type="file" name="aadharCard" id="aadharCard"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="saFileChosen(this,'wrap_aadharCard')">
                                    <div class="sa-file-label">
                                        <div class="sa-file-icon"><i class="fa fa-id-card"></i></div>
                                        <div class="sa-file-text">
                                            <strong>Upload File</strong>
                                            PDF, JPG, PNG · max 2MB
                                        </div>
                                    </div>
                                    <div class="sa-file-name" id="fn_aadharCard"></div>
                                </div>
                            </div>

                            <div class="sa-field">
                                <label>Transfer Certificate <span class="sa-opt">(optional — transfers only)</span></label>
                                <div class="sa-file-wrap" id="wrap_transferCertificate">
                                    <input type="file" name="transferCertificate" id="transferCertificate"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="saFileChosen(this,'wrap_transferCertificate')">
                                    <div class="sa-file-label">
                                        <div class="sa-file-icon"><i class="fa fa-certificate"></i></div>
                                        <div class="sa-file-text">
                                            <strong>Upload File</strong>
                                            PDF, JPG, PNG · max 2MB
                                        </div>
                                    </div>
                                    <div class="sa-file-name" id="fn_transferCertificate"></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ FEES EXEMPTION + PHOTO ══ -->
                <div class="sa-section" id="sec-fees">
                    <div class="sa-section-head">
                        <i class="fa fa-money"></i>
                        <h3>Fees &amp; Passport Photo</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-2">

                            <!-- Fees Exemption v2 (P0-b 2026-05-30): the broken exemption-
                                 checkbox block was removed here. The widget iterated
                                 schoolList('feeStructures') and emitted DOC FIELD names
                                 (schoolId/session/feeHeads/...) as checkbox values, so it
                                 could never store a real fee-head name. Concessions are now
                                 captured via the dedicated Fee_concessions screen. -->

                            <!-- Passport photo -->
                            <div class="sa-field">
                                <label>Passport Size Photo <span class="sa-opt">(optional)</span></label>
                                <div class="sa-photo-wrap">
                                    <div class="sa-photo-preview-box">
                                        <img id="passportPhotoPreview"
                                             src="<?= base_url('tools/dist/img/kids.jpg') ?>"
                                             alt="Preview">
                                        <span class="sa-photo-hint">170 × 200 px<br>JPG/PNG/WEBP</span>
                                    </div>
                                    <div class="sa-photo-file" style="flex:1;">
                                        <div class="sa-file-wrap" id="wrap_student_photo">
                                            <input type="file" name="student_photo" id="student_photo"
                                                   accept="image/*"
                                                   onchange="previewPassportPhoto(event);saFileChosen(this,'wrap_student_photo')">
                                            <div class="sa-file-label">
                                                <div class="sa-file-icon"><i class="fa fa-camera"></i></div>
                                                <div class="sa-file-text">
                                                    <strong>Upload Photo</strong>
                                                    JPG, PNG, WEBP · max 2MB
                                                </div>
                                            </div>
                                            <div class="sa-file-name" id="fn_student_photo"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ══ ADMISSION FEE (G10, 2026-08-08) ══
                     Hidden until a class is chosen and the server resolves a
                     configured admission fee for it. The amount shown here is
                     display-only — save_admission re-resolves it server-side and
                     never trusts a client-sent amount. -->
                <div class="sa-section" id="sec-admission-fee" style="display:none;">
                    <div class="sa-section-head">
                        <i class="fa fa-inr"></i>
                        <h3>Admission Fee</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-field" style="margin-bottom:14px;">
                            <div id="admFeeSummary"
                                 style="padding:10px 14px;border-radius:8px;background:rgba(188,90,60,.08);
                                        border:1px solid rgba(188,90,60,.25);font-size:14px;"></div>
                        </div>

                        <div class="sa-grid sa-grid-2" id="admFeeCollectFields">
                            <div class="sa-field">
                                <label>Payment Mode</label>
                                <select name="admission_payment_mode" id="admission_payment_mode" class="sa-input">
                                    <option value="">Select Mode</option>
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="neft">NEFT</option>
                                    <option value="rtgs">RTGS</option>
                                    <option value="dd">Demand Draft</option>
                                    <option value="pos">POS / Card</option>
                                </select>
                            </div>
                            <div class="sa-field" id="admBankLedgerField" style="display:none;">
                                <label>Bank Ledger</label>
                                <select name="admission_bank_ledger_code" id="admission_bank_ledger_code" class="sa-input">
                                    <option value="">Select Bank Ledger</option>
                                    <?php foreach (($bank_ledgers ?? []) as $bl): ?>
                                        <option value="<?= htmlspecialchars($bl['code'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($bl['name'] . ' (' . $bl['code'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sa-field" id="admBankRefField" style="display:none;">
                                <label>Reference / Instrument No.</label>
                                <input type="text" name="admission_bank_reference" id="admission_bank_reference"
                                       class="sa-input" placeholder="Cheque / UTR / transaction reference">
                            </div>
                        </div>

                        <?php if (!empty($can_waive_admission_fee)): ?>
                        <div class="sa-field" style="margin-top:8px;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                                <input type="checkbox" name="waive_admission_fee" id="waive_admission_fee" value="1">
                                Waive the admission fee for this student
                            </label>
                            <div id="admWaiveReasonWrap" style="display:none;margin-top:8px;">
                                <input type="text" name="waive_reason" id="waive_reason" class="sa-input"
                                       placeholder="Reason for waiver (required — recorded in the audit log)">
                                <small style="color:var(--sa-muted);">
                                    Waivers are permanently recorded against your account in the audit log.
                                </small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ══ APPROVE A FEE CONCESSION (Phase E) ══
                     The SHARED capture component — identical file mounted by
                     the CRM conversion screen and (later) Student 360. It stays
                     disabled until the student actually exists, because an
                     entitlement is student-scoped and this screen mints the
                     student id only on save. After a successful save the JS
                     calls ENTCAP.mount() with the new id and the operator
                     captures against the real record, through the same
                     entitlements/* endpoints every other surface uses. -->
                <!-- ══ SERVICE DECLARATIONS ══
                     The SAME partial the public admission form uses, so a
                     student admitted at the counter enters the identical
                     Transport lifecycle as one admitted online.

                     A DECLARATION IS NOT AN ASSIGNMENT. Ticking transport posts
                     decl_transport, and save_admission() turns it into a PENDING
                     transportRequests row for Operations → Transport → Requests.
                     Route, stop and the fee are decided there, at approval,
                     where the atomic capacity reservation lives — never here.

                     This replaces the old route into the concession
                     panel's "Service enrolment", which wrote an active-looking
                     record that no operational workflow ever read. -->
                <!-- These two blocks are FEE-related, so they belong to the Fees
                     step of the wizard. They live OUTSIDE the .sa-section list
                     (the declarations block posts decl_* fields; the capture
                     block is the shared #entcapBlock component), so the wizard
                     toggles this wrapper's `hidden` in lockstep with the Fees
                     step — exactly like #sec-admission-fee. `hidden` initially so
                     they don't flash on the Basic step before the JS runs. -->
                <div id="sec-concessions" hidden>
                <div style="margin:4px 0 14px;padding:10px 14px;border-radius:8px;background:rgba(59,130,246,.06);
                            border:1px solid rgba(59,130,246,.2);font-size:12.5px;color:var(--sa-blue,#334155);">
                    <i class="fa fa-info-circle"></i>
                    Declare any fee concession or transport need below. These are <strong>requests</strong> —
                    the <em>Approve a Fee Concession</em> panel activates <strong>after you save the student</strong>,
                    so you can record an approved concession against the new record in the same sitting.
                </div>
                <?php
                // $decl_can_lookup enables the live SR-No / Staff-ID lookups.
                // Only ever set on this STAFF-facing form: the public admission
                // form is unauthenticated, and giving it these endpoints would
                // let anyone enumerate a school's students and employees by
                // trying numbers. There the value travels as an unverified
                // claim and is resolved by staff at enrolment instead.
                $decl_prefix     = 'decl_';
                $decl_can_lookup = true;
                include APPPATH . 'views/_entitlement_declarations.php';
                ?>

                <?php $ec_source = 'direct_admission'; include APPPATH . 'views/_entitlements_capture.php'; ?>
                </div><!-- /#sec-concessions -->

                <!-- ══ ACTION BAR ══ -->
                <div class="sa-action-bar">
                      <div class="sa-wiz-nav">
                          <button type="button" id="saWizBack" class="sa-btn sa-btn-ghost">
                              <i class="fa fa-arrow-left"></i> Back
                          </button>
                          <button type="button" id="saWizNext" class="sa-btn sa-btn-primary">
                              Next <i class="fa fa-arrow-right"></i>
                          </button>
                      </div>
                    <button type="reset" class="sa-btn sa-btn-ghost">
                        <i class="fa fa-refresh"></i> Reset
                    </button>
                    <button type="button" id="submitStudentForm"
                            onclick="previewFormBeforeSubmit(event)"
                            class="sa-btn sa-btn-primary">
                        <i class="fa fa-eye"></i> Preview &amp; Submit
                    </button>
                    <?php $type = 'concession-aware'; include APPPATH . 'views/_concession_awareness_badge.php'; ?>
                </div>

            </form>
        </div><!-- /.sa-main -->
    </div><!-- /.sa-layout -->

</div><!-- /.sa-wrap -->
</div><!-- /.content-wrapper -->


<!-- ══════════════════════════════════════════════
     ADMISSION PREVIEW MODAL
══════════════════════════════════════════════ -->
<div class="sa-overlay" id="previewModalOverlay">
<div class="sa-modal" id="previewModal">

    <div class="sa-modal-head">
        <div>
            <h3><i class="fa fa-check-circle" style="margin-right:8px;color:#4ade80;"></i>Review Before Submitting</h3>
            <p>Please verify all details carefully before final submission.</p>
        </div>
        <button class="sa-modal-x" onclick="closePreviewModal()">&times;</button>
    </div>

    <!-- Hero strip -->
    <div class="sa-preview-hero">
        <img id="previewPhoto" src="" alt="Student Photo">
        <div class="sa-preview-hero-info">
            <h2 id="previewName"></h2>
            <div style="font-size:13px;color:var(--sa-muted);">Student Admission ID: <strong id="previewId" style="color:var(--sa-navy);"></strong></div>
            <div class="sa-preview-hero-badges">
                <span class="sa-prev-badge" id="previewClass"></span>
                <span class="sa-prev-badge" id="previewSection"></span>
                <span class="sa-prev-badge" id="previewAdmissionDate"></span>
                <span class="sa-prev-badge" id="previewRollNo"></span>
            </div>
        </div>
    </div>

    <div class="sa-modal-body">

        <!-- Academic -->
        <div class="sa-prev-section">
            <div class="sa-prev-section-title"><i class="fa fa-graduation-cap"></i> Academic Details</div>
            <div class="sa-prev-grid">
                <div class="sa-prev-field"><div class="lbl">DOB</div><div class="val" id="previewDob"></div></div>
                <div class="sa-prev-field"><div class="lbl">Gender</div><div class="val" id="previewGender"></div></div>
                <div class="sa-prev-field"><div class="lbl">Blood Group</div><div class="val" id="previewBloodGroup"></div></div>
                <div class="sa-prev-field"><div class="lbl">Category</div><div class="val" id="previewCategory"></div></div>
            </div>
        </div>

        <!-- Contact -->
        <div class="sa-prev-section">
            <div class="sa-prev-section-title"><i class="fa fa-phone"></i> Contact Details</div>
            <div class="sa-prev-grid">
                <div class="sa-prev-field"><div class="lbl">Phone</div><div class="val" id="previewPhone"></div></div>
                <div class="sa-prev-field"><div class="lbl">Email</div><div class="val" id="previewEmail"></div></div>
                <div class="sa-prev-field"><div class="lbl">Nationality</div><div class="val" id="previewNationality"></div></div>
                <div class="sa-prev-field"><div class="lbl">Religion</div><div class="val" id="previewReligion"></div></div>
            </div>
        </div>

        <!-- Parents -->
        <div class="sa-prev-section">
            <div class="sa-prev-section-title"><i class="fa fa-users"></i> Parent Details</div>
            <div class="sa-prev-grid">
                <div class="sa-prev-field"><div class="lbl">Father Name</div><div class="val" id="previewFatherName"></div></div>
                <div class="sa-prev-field"><div class="lbl">Father Occupation</div><div class="val" id="previewFatherOccupation"></div></div>
                <div class="sa-prev-field"><div class="lbl">Guardian Contact</div><div class="val" id="previewGuardianContact"></div></div>
                <div class="sa-prev-field"><div class="lbl">Mother Name</div><div class="val" id="previewMotherName"></div></div>
                <div class="sa-prev-field"><div class="lbl">Mother Occupation</div><div class="val" id="previewMotherOccupation"></div></div>
                <div class="sa-prev-field"><div class="lbl">Guardian Relation</div><div class="val" id="previewGuardianRelation"></div></div>
            </div>
        </div>

        <!-- Address -->
        <div class="sa-prev-section">
            <div class="sa-prev-section-title"><i class="fa fa-map-marker"></i> Address</div>
            <div class="sa-prev-grid">
                <div class="sa-prev-field" style="grid-column:span 3;">
                    <div class="lbl">Full Address</div>
                    <div class="val">
                        <span id="previewStreet"></span>,
                        <span id="previewCity"></span>,
                        <span id="previewState"></span> – <span id="previewPostalCode"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Previous school -->
        <div class="sa-prev-section">
            <div class="sa-prev-section-title"><i class="fa fa-university"></i> Previous School</div>
            <div class="sa-prev-grid">
                <div class="sa-prev-field"><div class="lbl">Previous Class</div><div class="val" id="previewPreClass"></div></div>
                <div class="sa-prev-field"><div class="lbl">Marks %</div><div class="val" id="previewPreMarks"></div></div>
                <div class="sa-prev-field"><div class="lbl">School Name</div><div class="val" id="previewPreSchool"></div></div>
            </div>
        </div>

        <!-- Documents -->
        <div class="sa-prev-section">
            <div class="sa-prev-section-title"><i class="fa fa-file-text-o"></i> Uploaded Documents</div>
            <div class="sa-prev-docs">
                <div class="sa-prev-doc-item">
                    <i class="fa fa-file"></i>
                    <span id="previewBirthCertificateName">Birth Certificate</span>
                    <a href="#" target="_blank" id="previewBirthCertificateView" style="display:none;"><i class="fa fa-eye"></i> View</a>
                </div>
                <div class="sa-prev-doc-item">
                    <i class="fa fa-id-card"></i>
                    <span id="previewAadharCardName">Aadhar Card</span>
                    <a href="#" target="_blank" id="previewAadharCardView" style="display:none;"><i class="fa fa-eye"></i> View</a>
                </div>
                <div class="sa-prev-doc-item">
                    <i class="fa fa-certificate"></i>
                    <span id="previewSchoolLeavingName">Transfer Certificate</span>
                    <a href="#" target="_blank" id="previewSchoolLeavingView" style="display:none;"><i class="fa fa-eye"></i> View</a>
                </div>
            </div>
        </div>

        <!-- Fee exemptions -->
        <div class="sa-prev-section">
            <div class="sa-prev-section-title"><i class="fa fa-tag"></i> Fee Exemptions</div>
            <div class="val" id="previewFees" style="font-size:13.5px;"></div>
        </div>

    </div>

    <div class="sa-modal-foot">
        <button type="button" class="sa-btn sa-btn-ghost" onclick="closePreviewModal()">
            <i class="fa fa-edit"></i> Edit
        </button>
        <button type="button" class="sa-btn sa-btn-success" onclick="submitFinalForm()" id="finalSubmitBtn">
            <i class="fa fa-check"></i> Final Submit
        </button>
    </div>

</div>
</div>



<script>
/* ================================================================
   studentAdmission.php — all original IDs and logic preserved
   jQuery used only for $.ajax (footer already loads jQuery)
================================================================ */

/* ── Toast notifications ── */
function showAlert(type, message) {
    var toast = document.createElement('div');
    toast.className = 'sa-toast ' + type;
    var icons = { success:'fa-check-circle', error:'fa-times-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };
    var icon = document.createElement('i');
    icon.className = 'fa ' + (icons[type]||'fa-info-circle');
    toast.appendChild(icon);
    toast.appendChild(document.createTextNode(' ' + message));
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3400);
}

/* ── File chosen feedback ── */
function saFileChosen(input, wrapId) {
    var wrap = document.getElementById(wrapId);
    var nameEl = wrap ? wrap.querySelector('.sa-file-name') : null;
    if (!nameEl) return;
    if (input.files && input.files.length > 0) {
        nameEl.textContent = '✓ ' + input.files[0].name;
        nameEl.style.display = 'block';
        if (wrap) wrap.style.borderColor = 'var(--sa-green)';
    } else {
        nameEl.style.display = 'none';
        if (wrap) wrap.style.borderColor = '';
    }
}

/* ── Preview passport photo ── */
function previewPassportPhoto(event) {
    var file = event.target.files && event.target.files[0];
    if (!file) return;
    var preview = document.getElementById('passportPhotoPreview');
    if (!preview) return;
    var reader = new FileReader();
    reader.onload = function(e) { preview.src = e.target.result; };
    reader.readAsDataURL(file);
}

/* ── Toggle other religion field ── */
function toggleOtherReligion(selectElement) {
    var otherInput = document.getElementById('other_religion');
    if (!otherInput) return;
    if (selectElement.value === 'Other') {
        otherInput.style.display = 'block';
        otherInput.required = true;
    } else {
        otherInput.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}

/* ── Validation ── */
function validateAdmissionForm() {
    var isValid = true;

    /* clear previous errors (also reset the a11y invalid state) */
    document.querySelectorAll('.sa-input.has-error, .sa-select.has-error').forEach(function(el) {
        el.classList.remove('has-error');
        el.removeAttribute('aria-invalid');
    });
    document.querySelectorAll('.sa-error-msg').forEach(function(el) { el.remove(); });

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? (el.value || '').trim() : '';
    }

    function showError(inputId, message) {
        var input = document.getElementById(inputId);
        if (!input) { isValid = false; return; }
        input.classList.add('has-error');
        input.setAttribute('aria-invalid', 'true');   // announced by screen readers
        var span = document.createElement('span');
        span.className = 'sa-error-msg';
        span.setAttribute('role', 'alert');
        span.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + message;
        input.parentNode.insertBefore(span, input.nextSibling);
        isValid = false;
    }

    // ── REQUIRED AT ADMISSION (ADM-INPUT-1) ───────────────────────────────
    // Name, Class, Section, Date of Birth, Phone. save_admission() enforces the
    // same five server-side and is the authority; this half only spares the
    // operator a round trip.
    //
    // DOB and phone were optional until 2026-09-19. DOB drives age eligibility,
    // the RTE age band, the UDISE+ return and the ID card; phone is how the
    // school reaches the family, how the Parent app resolves the child, and half
    // of the duplicate check. "Complete it later" had no later.
    if (!getValue('sname'))      showError('sname',      'Student name is required');
    if (!getValue('class_name')) showError('class_name', 'Please select a class');
    if (!getValue('section'))    showError('section',    'Please select a section');

    var dobV = getValue('dob');
    if (!dobV) {
        showError('dob', 'Date of birth is required');
    } else {
        // Compare date-only values: a Date built from "YYYY-MM-DD" is UTC midnight,
        // so comparing it against a local `new Date()` misjudges by a day either
        // side of midnight depending on the timezone.
        var _t = new Date(); _t.setHours(0, 0, 0, 0);
        var _p = dobV.split('-');
        var _d = new Date(+_p[0], +_p[1] - 1, +_p[2]);
        if (isNaN(_d)) {
            showError('dob', 'Enter a valid date of birth');
        } else if (_d > _t) {
            showError('dob', 'Date of birth cannot be in the future');
        } else {
            var _age = _t.getFullYear() - _d.getFullYear();
            var _m   = _t.getMonth() - _d.getMonth();
            if (_m < 0 || (_m === 0 && _t.getDate() < _d.getDate())) _age--;
            // Bounds only — class-appropriate age is a school policy, not ours.
            if (_age < 2 || _age > 30) {
                showError('dob', 'That makes the student ' + _age + ' years old. Please check the date.');
            }
        }
    }

    var phoneV = getValue('phone_number');
    if (!phoneV) {
        showError('phone_number', 'A 10-digit mobile number is required');
    } else if (!/^[6-9]\d{9}$/.test(phoneV)) {
        showError('phone_number', 'Enter valid 10-digit mobile number');
    }

    // Optional fields — format checked only when provided.
    var emailV = getValue('email_user');
    if (emailV && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailV))
        showError('email_user', 'Enter a valid email address');

    var postalV = getValue('postal_code');
    if (postalV && !/^[1-9][0-9]{5}$/.test(postalV))
        showError('postal_code', 'Enter valid 6-digit PIN code');

    var marksV = getValue('pre_marks');
    if (marksV && !/^[0-9]{1,3}%?$/.test(marksV))
        showError('pre_marks', 'Enter valid percentage (e.g. 85%)');

    /* Phase 9 correction pass — APAAR ID is optional; format-only check
       mirrors Apaar_service::normalize() server-side (never trusted alone). */
    var apaarRaw = getValue('apaar_id');
    if (apaarRaw !== '') {
        var apaarNorm = apaarRaw.replace(/[\s\-]/g, '');
        if (!/^[0-9]{12}$/.test(apaarNorm)) {
            showError('apaar_id', 'An APAAR ID is 12 digits, or leave it blank');
        }
    }

    /* files — OPTIONAL at admission (server treats every document as optional;
       save_admission stores whatever is provided and skips the rest). A file is
       NOT required: a first-time admission (nursery/KG/Class 1) legitimately has
       no Transfer Certificate, and forcing one here blocked those admissions
       entirely. Format/size are still validated, but only when a file is chosen. */
    function validateFile(inputId, allowedTypes, maxSizeMB) {
        var input = document.getElementById(inputId);
        if (!input) return;
        if (!input.files || !input.files.length) return; // optional — nothing to validate
        var file = input.files[0];
        if (!allowedTypes.includes(file.type)) showError(inputId, 'Invalid format (PDF, JPG, PNG allowed)');
        if (file.size > maxSizeMB * 1024 * 1024) showError(inputId, 'File must be under ' + maxSizeMB + 'MB');
    }

    var docTypes = ['image/jpeg','image/png','application/pdf'];
    validateFile('birthCertificate',    docTypes, 2);
    validateFile('aadharCard',          docTypes, 2);
    validateFile('transferCertificate', docTypes, 2);
    validateFile('student_photo', ['image/jpeg','image/png','image/webp'], 2);

    /* G10 — admission fee. Client-side convenience only; save_admission
       re-validates all of this server-side and rejects independently. */
    var admSec = document.getElementById('sec-admission-fee');
    if (admSec && admSec.style.display !== 'none') {
        var waived = document.getElementById('waive_admission_fee');
        if (waived && waived.checked) {
            if (!getValue('waive_reason'))
                showError('waive_reason', 'A reason is required to waive the admission fee');
        } else {
            var mode = getValue('admission_payment_mode');
            if (!mode) {
                showError('admission_payment_mode', 'Select how the admission fee was collected');
            } else if (mode !== 'cash' && !getValue('admission_bank_ledger_code')) {
                showError('admission_bank_ledger_code', 'Select a bank ledger for a non-cash collection');
            }
        }
    }

    if (!isValid) {
        var firstErr = document.querySelector('.has-error, .sa-error-msg');
        if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    return isValid;
}

/* ── Open / close preview modal ── */
function openPreviewModal()  { document.getElementById('previewModalOverlay').classList.add('open'); }
function closePreviewModal() { document.getElementById('previewModalOverlay').classList.remove('open'); }

document.getElementById('previewModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePreviewModal();
});

/* ── Preview before submit ── */
function previewFormBeforeSubmit(event) {
    event.preventDefault();
    if (!validateAdmissionForm()) return;
    fillPreviewData();
    openPreviewModal();
}

function fillPreviewData() {
    var getValue = function(id) {
        var el = document.getElementById(id);
        return el ? (el.value || '') : '';
    };
    var setText = function(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value || '—';
    };

    /* Hero */
    setText('previewName',          getValue('sname'));
    setText('previewId',            getValue('user_id'));
    setText('previewClass',   'Class: ' + getValue('class_name'));
    setText('previewSection', 'Section: ' + getValue('section'));
    setText('previewAdmissionDate', getValue('admission_date'));
    var rollNo = getValue('roll_no');
    setText('previewRollNo', rollNo ? 'Roll: ' + rollNo : '');

    /* Academic */
    setText('previewDob',        getValue('dob'));
    setText('previewGender',     getValue('gender'));
    setText('previewBloodGroup', getValue('blood_group'));
    setText('previewCategory',   getValue('category'));

    /* Contact */
    setText('previewPhone',       getValue('phone_number'));
    setText('previewEmail',       getValue('email_user'));
    setText('previewNationality', getValue('nationality'));

    var religion = getValue('religion');
    if (religion === 'Other') religion = getValue('other_religion');
    setText('previewReligion', religion);

    /* Parents */
    setText('previewFatherName',        getValue('father_name'));
    setText('previewFatherOccupation',  getValue('father_occupation'));
    setText('previewMotherName',        getValue('mother_name'));
    setText('previewMotherOccupation',  getValue('mother_occupation'));
    setText('previewGuardianContact',   getValue('guard_contact'));
    setText('previewGuardianRelation',  getValue('guard_relation'));

    /* Address */
    setText('previewStreet',     getValue('street'));
    setText('previewCity',       getValue('city'));
    setText('previewState',      getValue('state'));
    setText('previewPostalCode', getValue('postal_code'));

    /* Previous school */
    setText('previewPreClass',  getValue('pre_class'));
    setText('previewPreSchool', getValue('pre_school'));
    setText('previewPreMarks',  getValue('pre_marks'));

    /* Fees */
    var selectedFees = Array.from(document.querySelectorAll('input[name="exempted_fees_multiple[]"]:checked'))
                            .map(function(cb) { return cb.value; });
    setText('previewFees', selectedFees.length ? selectedFees.join(', ') : 'None');

    /* Documents */
    handleDocumentPreview('birthCertificate',    'previewBirthCertificateName', 'previewBirthCertificateView');
    handleDocumentPreview('aadharCard',          'previewAadharCardName',       'previewAadharCardView');
    handleDocumentPreview('transferCertificate', 'previewSchoolLeavingName',    'previewSchoolLeavingView');

    /* Photo */
    var photoPreview = document.getElementById('passportPhotoPreview');
    var previewImg   = document.getElementById('previewPhoto');
    if (photoPreview && previewImg) previewImg.src = photoPreview.src;
}

function handleDocumentPreview(inputId, nameId, viewId) {
    var input   = document.getElementById(inputId);
    var nameEl  = document.getElementById(nameId);
    var viewBtn = document.getElementById(viewId);
    if (!input || !nameEl || !viewBtn) return;
    if (input.files && input.files.length > 0) {
        var file = input.files[0];
        nameEl.textContent  = file.name;
        viewBtn.href        = URL.createObjectURL(file);
        viewBtn.style.display = 'inline';
    } else {
        nameEl.textContent    = 'Not Uploaded';
        viewBtn.style.display = 'none';
    }
}

/* ── ADM-DUP-1 · duplicate-admission confirmation ──────────────────────────
   Deliberately NOT window.confirm(): it cannot show the matched student, cannot
   be styled to distinguish the safe choice from the destructive one, and is
   suppressible by the browser. The operator has to be able to SEE which child
   the system matched before deciding they are two different people.

   Styles are inline on purpose — this markup is injected into a page whose
   utility classes include display:grid card helpers that would reflow it. */
function showDuplicateDialog(res) {
    var d = res.duplicate || {};
    var esc = function (t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    };

    var old = document.getElementById('zxDupOverlay');
    if (old) old.remove();

    var o = document.createElement('div');
    o.id = 'zxDupOverlay';
    o.setAttribute('role', 'dialog');
    o.setAttribute('aria-modal', 'true');
    o.setAttribute('aria-labelledby', 'zxDupTitle');
    o.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.55);'
                    + 'display:flex;align-items:center;justify-content:center;padding:20px;';

    o.innerHTML =
        '<div style="background:#fff;border-radius:12px;max-width:560px;width:100%;'
      + 'box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden;">'
      +   '<div style="padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;gap:10px;align-items:center;">'
      +     '<i class="fa fa-exclamation-triangle" style="color:#b45309;font-size:18px;"></i>'
      +     '<h3 id="zxDupTitle" style="margin:0;font-size:16px;font-weight:700;color:#111827;">'
      +       'This student may already be admitted</h3>'
      +   '</div>'
      +   '<div style="padding:18px 22px;font-size:13.5px;line-height:1.6;color:#374151;">'
      +     '<p style="margin:0 0 14px;">' + esc(res.message || '') + '</p>'
      +     '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;">'
      +       '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;'
      +         'color:#6b7280;margin-bottom:7px;">Already on the roster</div>'
      +       '<div style="font-weight:600;color:#111827;">' + esc(d.name || '') + '</div>'
      +       '<div style="color:#6b7280;margin-top:3px;">' + esc(d.studentId || '')
      +         (d.srNo ? ' &middot; SR ' + esc(d.srNo) : '')
      +         ' &middot; ' + esc(d.class || '') + ' ' + esc(d.section || '') + '</div>'
      +     '</div>'
      +     '<p style="margin:14px 0 0;color:#6b7280;">If these really are two different '
      +       'children who share a name and a phone number, you can continue — the '
      +       'decision is recorded in the audit log.</p>'
      +   '</div>'
      +   '<div style="padding:14px 22px;background:#f9fafb;border-top:1px solid #e5e7eb;'
      +     'display:flex;gap:10px;justify-content:flex-end;">'
      +     '<button type="button" id="zxDupCancel" style="padding:9px 16px;border-radius:7px;'
      +       'border:1px solid #d1d5db;background:#fff;font-size:13px;font-weight:600;cursor:pointer;">'
      +       'Cancel — let me check</button>'
      +     '<button type="button" id="zxDupGo" style="padding:9px 16px;border-radius:7px;border:0;'
      +       'background:#b45309;color:#fff;font-size:13px;font-weight:600;cursor:pointer;">'
      +       'They are different — admit anyway</button>'
      +   '</div>'
      + '</div>';

    document.body.appendChild(o);

    var close = function () { o.remove(); };
    document.getElementById('zxDupCancel').addEventListener('click', close);
    // Cancel is the safe default, so it takes focus and Escape chooses it.
    document.getElementById('zxDupCancel').focus();
    o.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    document.getElementById('zxDupGo').addEventListener('click', function () {
        close();
        window.__zenxConfirmDuplicate = true;
        submitFinalForm();
    });
}

/* ── Final submit via AJAX ── */
function submitFinalForm() {
    var form = document.getElementById('add_student_form');
    if (!form) { showAlert('error', 'Form not found — please refresh.'); return; }

    var btn = document.getElementById('finalSubmitBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…'; }

    var formData = new FormData(form);

    // ADM-DUP-1 — set only by the duplicate dialog's "Admit anyway", and cleared
    // immediately so a LATER admission in the same sitting is checked again. A
    // sticky flag here would silently disable duplicate detection for the rest of
    // the session, which is worse than not having it.
    if (window.__zenxConfirmDuplicate) {
        formData.append('confirm_duplicate', '1');
        window.__zenxConfirmDuplicate = false;
    }

    // ── PHASE 3B / B1 — CARRY A KNOWN ENTITLEMENT WITH THE ADMISSION ────────
    // A concession agreed at the admission desk is submitted WITH the student,
    // so the server can record it before it prices the demands. Previously the
    // capture panel could only be used after the response, by which time the
    // invoice had already been written at full price.
    //
    // Purely additive: when the operator has filled nothing in, no ent_* field
    // is sent and save_admission() takes its unchanged path. The post-admission
    // ENTCAP flow is untouched and remains the route for concessions agreed
    // later.
    (function attachKnownEntitlement() {
        var kindEl = document.getElementById('ecKind');
        var valEl  = document.getElementById('ecValue');
        var fromEl = document.getElementById('ecFrom');
        var rsnEl  = document.getElementById('ecReason');
        if (!kindEl || !fromEl || !rsnEl) return;

        // Require the fields the server validates, so a half-filled panel is
        // simply ignored rather than failing the whole admission.
        if (!fromEl.value || !rsnEl.value.trim()) return;

        var vtEl = document.getElementById('ecValueType');
        var vt   = vtEl ? vtEl.value : '';
        if (vt !== 'fullExempt' && !(valEl && valEl.value)) return;

        // REG-P6-R1 (2026-08-18) — the scope comes from window.ENTCAP, the ONE
        // implementation the capture panel itself uses. This block used to
        // rebuild the string from `ecScopeTarget`; F-3 replaced that free-text
        // box with the registry-backed `ecScopeHead` / `ecScopeCat` pickers, and
        // because this copy still read the deleted element the target was
        // always empty — so the guard below returned early and EVERY head- and
        // category-scoped entitlement submitted with a direct admission was
        // silently dropped. Reusing ENTCAP.scopeValue() means a future change to
        // the scope contract cannot leave this host behind again.
        if (!window.ENTCAP || typeof window.ENTCAP.scopeValue !== 'function') return;
        if (!window.ENTCAP.scopeReady()) return;   // half-filled panel: ignore, never guess

        formData.append('ent_kind',            kindEl.value);
        // `ent_service_type` NOT sent: the panel captures concessions only, and
        // propose() discards serviceType on a concession anyway. It was reading a
        // deleted element through a null-guard, which is how REG-P6-R1 started.
        formData.append('ent_concession_type', (document.getElementById('ecConcType') || {}).value || 'other');
        formData.append('ent_scope',           window.ENTCAP.scopeValue());
        formData.append('ent_value_type',      vt);
        formData.append('ent_value',           (valEl && valEl.value) || 0);
        // `ent_frequency` removed — entitlement-level frequency was retired at
        // the P2 contract freeze (I1); it read `ecFrequency`, also deleted.
        formData.append('ent_effective_from',  fromEl.value);
        formData.append('ent_effective_to',    (document.getElementById('ecTo') || {}).value || '');
        formData.append('ent_reason',          rsnEl.value.trim());
        formData.append('ent_govt_receivable_amount', (document.getElementById('ecGovt') || {}).value || 0);
    })();

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
                showAlert('error', 'Unexpected server response.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
                return;
            }
            // ── ADM-DUP-1 — a third outcome, between success and failure ──────
            // The server found a student with this exact name on this exact mobile
            // number. That is usually a re-entry; occasionally it is genuinely two
            // children. Only the operator can tell, so ask — and ask BEFORE testing
            // for success, so a confirmation can never be mistaken for an admission.
            if (res.status === 'confirm_required') {
                showDuplicateDialog(res);
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
                return;
            }
            if (res.status === 'success') {
                closePreviewModal();
                // Student created — wipe the autosaved draft so the next
                // admission starts clean (and a restored draft can't reappear).
                if (window.__zenxClearAdmDraft) window.__zenxClearAdmDraft();
                // ── PHASE 3B / B1 — BILLING STATE, NOT A BARE SUCCESS ────────
                // The server now returns billed | billing_pending |
                // billing_failed. An HTTP 200 means the STUDENT was created; it
                // does not mean the student was BILLED correctly. Rendering a
                // green "Admission successful!" over an unbilled student is the
                // exact failure this phase removes, so the alert level is driven
                // by the financial state rather than by the transport status.
                var _bs = res.billing_state || 'billed';
                showAlert(
                    _bs === 'billed' ? 'success' : (_bs === 'billing_pending' ? 'warning' : 'error'),
                    res.message || 'Student admitted.'
                );
                // ADM-COMPLETE-1 — the student IS admitted; this is a follow-up list,
                // so it is rendered as information and never as an error. Shown while
                // the family is still at the desk, which is the cheap moment to close
                // the gaps.
                // ADM-AGE-1 — shown AFTER the admission succeeded, because warn mode
                // means the school wants to know, not to be stopped.
                if (res.age_warning) { showAlert('warning', res.age_warning); }
                if (res.pending_fields && res.pending_fields.length) {
                    showAlert('warning', 'Admitted, but this record is incomplete — still missing: '
                        + res.pending_fields.join(', ')
                        + '. Add these from the student profile when you can.');
                }
                // Phase E — the student now exists, so the shared capture
                // component can bind to it. Mounting instead of reloading lets
                // the operator record concessions/services in the same sitting;
                // reloading here would discard the just-created context and
                // force them to re-find the student.
                if (window.ENTCAP && res.user_id) {
                    // UAT DEFECT FIX (2026-08-09): this branch deliberately does
                    // NOT reload, so the operator can capture entitlements in the
                    // same sitting. But that left "Preview & Submit" live — a
                    // second click would create a DUPLICATE student and charge a
                    // SECOND admission fee. Lock the form down the moment the
                    // student exists; the page is now a capture surface, not an
                    // admission form.
                    var _lock = document.getElementById('submitStudentForm');
                    if (_lock) {
                        _lock.disabled = true;
                        _lock.style.opacity = '.5';
                        _lock.style.cursor = 'not-allowed';
                        _lock.innerHTML = '<i class="fa fa-check"></i> Admitted — ' + res.user_id;
                        _lock.onclick = function (e) { e.preventDefault(); return false; };
                    }
                    var _reset = document.querySelector('.sa-action-bar button[type="reset"]');
                    if (_reset) { _reset.disabled = true; _reset.style.opacity = '.5'; }

                    // UAT DEFECT FIX (2026-08-09): the server generates the
                    // student's Parent-app login password and returns it, but
                    // this screen discarded it — so the family could never be
                    // told their credentials and could never sign in. The CRM
                    // conversion flow already shows a credentials panel; the
                    // direct-admission screen never had one. Same idea here,
                    // rendered once and never auto-dismissed, because these
                    // cannot be retrieved again after the page is left.
                    if (res.password) {
                        // This view has no global esc() — define one locally.
                        // The values are server-generated, but they go into
                        // innerHTML, so they get escaped on principle rather
                        // than on the assumption that they are always safe.
                        var esc = function (s) {
                            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                            });
                        };
                        var cred = document.createElement('div');
                        cred.id = 'saCredBox';
                        cred.style.cssText = 'margin:18px 0;padding:16px 18px;border:1px solid #BC5A3C;'
                            + 'border-radius:10px;background:rgba(188,90,60,.06)';
                        cred.innerHTML =
                              '<div style="font-weight:700;font-size:15px;margin-bottom:4px">Login details for ' + esc(res.name || '') + '</div>'
                            + '<div style="font-size:12.5px;opacity:.75;margin-bottom:12px">Share these with the parent. '
                            + '<strong>They cannot be shown again</strong> once you leave this page.</div>'
                            + '<div style="display:flex;gap:26px;flex-wrap:wrap;align-items:center">'
                            +   '<div><div style="font-size:11.5px;opacity:.7">USER ID</div>'
                            +     '<code id="saCredUid" style="font-size:15px;font-weight:700">' + esc(res.user_id) + '</code></div>'
                            // Phase 9.1 — SRNO beside Student ID, system-generated, not editable here.
                            +   (res.sr_no
                                    ? '<div><div style="font-size:11.5px;opacity:.7">SR NUMBER</div>'
                                    +   '<code style="font-size:15px;font-weight:700">' + esc(res.sr_no) + '</code></div>'
                                    : '')
                            // Phase 10.1 — Admission Number (YYYY/CLASS/XXX), distinct
                            // from both SR Number and Student ID.
                            +   (res.admission_no
                                    ? '<div><div style="font-size:11.5px;opacity:.7">ADMISSION NUMBER</div>'
                                    +   '<code style="font-size:15px;font-weight:700">' + esc(res.admission_no) + '</code></div>'
                                    : '')
                            +   '<div><div style="font-size:11.5px;opacity:.7">PASSWORD</div>'
                            +     '<code id="saCredPwd" style="font-size:15px;font-weight:700">' + esc(res.password) + '</code></div>'
                            +   '<button type="button" id="saCredCopy" class="sa-btn sa-btn-ghost">Copy both</button>'
                            + '</div>';
                        var anchor = document.getElementById('entcapBlock');
                        if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(cred, anchor);
                        else document.querySelector('.sa-main').appendChild(cred);

                        var cb = document.getElementById('saCredCopy');
                        if (cb) cb.addEventListener('click', function () {
                            var txt = 'User ID: ' + res.user_id + '\nPassword: ' + res.password;
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(txt).then(function () { cb.textContent = 'Copied'; });
                            } else {
                                // Clipboard API needs a secure context; plain
                                // http://localhost dev setups fall back here.
                                var ta = document.createElement('textarea');
                                ta.value = txt; document.body.appendChild(ta); ta.select();
                                try { document.execCommand('copy'); cb.textContent = 'Copied'; } catch (e) {}
                                document.body.removeChild(ta);
                            }
                        });
                    }

                    ENTCAP.mount({
                        studentId: res.user_id,
                        klass:     document.getElementById('class_name') ? document.getElementById('class_name').value : '',
                        section:   document.getElementById('section')    ? document.getElementById('section').value    : ''
                    });
                    var cap = document.getElementById('entcapBlock');
                    if (cap) cap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;   // stay on the page; no reload
                }
                setTimeout(function() { location.reload(); }, 1600);
            } else {
                showAlert('error', res.message || 'Submission failed. Please try again.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
            }
        },
        error: function() {
            showAlert('error', 'Server error — please try again.');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
        }
    });
}

/* ════════════════════════════════════════════
   SELECT ALL FEES + CLASS→SECTION→SUBJECTS
════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {

    /* Select all fees */
    var selectAllFees = document.getElementById('select_all_exempted_fees');
    var feeCheckboxes = document.querySelectorAll('input[name="exempted_fees_multiple[]"]');
    if (selectAllFees) {
        selectAllFees.addEventListener('change', function() {
            feeCheckboxes.forEach(function(cb) { cb.checked = selectAllFees.checked; });
        });
        feeCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', function() {
                selectAllFees.checked = Array.from(feeCheckboxes).every(function(c) { return c.checked; });
            });
        });
    }

    var classSelect   = document.getElementById('class_name');
    var sectionSelect = document.getElementById('section');
    if (!classSelect || !sectionSelect) return;

    /* Load classes from PHP-provided map */
    var CLASS_MAP = <?= json_encode($class_map ?? []) ?>;
    Object.keys(CLASS_MAP).forEach(function(cls) {
        var opt = document.createElement('option');
        opt.value = cls;
        opt.textContent = cls;
        classSelect.appendChild(opt);
    });

    /* Class change → load sections from CLASS_MAP, then enrich with live
       capacity (Phase 15). The dropdown is populated from CLASS_MAP FIRST so
       the form stays fully usable if the capacity call fails or is slow —
       capacity is decoration on top, never a prerequisite for choosing a
       section. The server re-validates the final choice at save regardless
       (Sis::save_admission), so this is convenience, not enforcement. */
    /* ── Section loading (2026-08-16) ─────────────────────────────────
       Sections used to be painted from CLASS_MAP immediately as bare
       "Section A", then rewritten a moment later as "Section A (1/40)".
       The operator saw a usable-looking dropdown carrying no occupancy and
       could pick a full section before the real numbers landed — a visible
       flicker, and briefly wrong information.

       Now the field stays in an explicit LOADING state and is populated
       once, from settled data. CLASS_MAP is retained purely as the fallback
       if the capacity call fails, so a backend hiccup degrades to a working
       form rather than an empty one — the form must never become
       unusable just because occupancy is unavailable. */
    function setSectionLoading(msg) {
        sectionSelect.innerHTML = '<option value="" disabled selected>' + msg + '</option>';
        sectionSelect.disabled = true;
    }

    /** Populate from CLASS_MAP alone — the degraded path, no occupancy shown. */
    function populateSectionsPlain(cls) {
        sectionSelect.innerHTML = '<option value="" disabled selected>Select Section</option>';
        (CLASS_MAP[cls] || []).forEach(function (sec) {
            var opt = document.createElement('option');
            opt.value = sec;
            opt.textContent = 'Section ' + sec;
            sectionSelect.appendChild(opt);
        });
        sectionSelect.disabled = false;
    }

    classSelect.addEventListener('change', function() {
        var cls = this.value;
        if (!cls) {
            sectionSelect.innerHTML = '<option value="" disabled selected>Select Section</option>';
            sectionSelect.disabled = false;
            if (sectionCapHint) sectionCapHint.style.display = 'none';
            return;
        }
        setSectionLoading('Loading sections…');
        loadAdmissionFee(cls);
        loadSectionCapacity(cls);
    });

    /* Phase 15 — reuse the EXISTING get_class_sections endpoint (the same one
       the CRM enrollment screen already uses). No second capacity
       implementation, no new endpoint, no schema change. */
    var sectionCapHint = document.getElementById('sectionCapacityHint');
    function loadSectionCapacity(cls) {
        if (!sectionCapHint) return;
        sectionCapHint.style.display = 'none';
        sectionCapHint.innerHTML = '';
        if (!cls) return;

        fetch('<?= base_url("sis/get_class_sections") ?>?class=' + encodeURIComponent(cls), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(res) {
            if (!res || res.status !== 'success' || !Array.isArray(res.detail)) {
                // Degrade to plain names rather than leaving the field stuck
                // on "Loading sections…" — the operator must still be able to
                // admit a student when occupancy is unavailable.
                populateSectionsPlain(cls);
                sectionCapHint.style.color = 'var(--t3,#6b7280)';
                sectionCapHint.textContent = 'Section occupancy unavailable — capacity is still checked when you save.';
                sectionCapHint.style.display = 'block';
                return;
            }

            // Build the dropdown ONCE, from settled data, so no intermediate
            // state without occupancy is ever shown.
            var byName = {};
            res.detail.forEach(function(d) { byName[d.section] = d; });

            sectionSelect.innerHTML = '<option value="" disabled selected>Select Section</option>';
            (CLASS_MAP[cls] || Object.keys(byName)).forEach(function (sec) {
                var d = byName[sec];
                var opt = document.createElement('option');
                opt.value = sec;
                opt.textContent = d
                    ? 'Section ' + sec + '  (' + d.current + '/' + d.capacity + ')' + (d.full ? '  — FULL' : '')
                    : 'Section ' + sec;
                opt.disabled = !!(d && d.full);
                sectionSelect.appendChild(opt);
            });
            sectionSelect.disabled = false;

            // Pre-select the suggested (most-headroom) section, but leave it
            // fully changeable — never a forced assignment.
            if (res.suggested && byName[res.suggested] && !byName[res.suggested].full) {
                sectionSelect.value = res.suggested;
            }

            var rows = res.detail.map(function(d) {
                return d.section + ' ' + d.current + '/' + d.capacity + (d.full ? ' FULL' : '');
            }).join(' · ');

            // CAPACITY LIVES ON THE ACADEMIC SETUP -> SECTIONS TAB.
            //
            // This pointed at classes/view/{slug} — the LEGACY per-class screen —
            // while the comment beside it already said capacity had moved to the
            // school_config Sections tab. Retiring classes/manage_classes moved
            // the feature but not the link, so "Change capacity" walked the
            // operator out of the canonical setup and into the old page.
            //
            // Points at the canonical tab instead, so the operator lands on the
            // section matrix and its capacity editor (save_section_capacity).
            var manage = ' &nbsp;·&nbsp; <a href="<?= base_url("school_config") ?>#sections" target="_blank"'
                       + ' style="color:var(--gold,#BC5A3C);text-decoration:underline">Change capacity</a>';

            if (!res.suggested) {
                sectionCapHint.style.color = '#b91c1c';
                sectionCapHint.innerHTML = 'All sections for this class are currently full. '
                    + (rows ? '(' + rows + ')' : '') + manage;
            } else {
                sectionCapHint.style.color = 'var(--t3,#6b7280)';
                sectionCapHint.innerHTML = rows + '  ·  Suggested: ' + res.suggested + manage;
            }
            sectionCapHint.style.display = 'block';
        })
        .catch(function() {
            populateSectionsPlain(cls);
            sectionCapHint.style.color = 'var(--t3,#6b7280)';
            sectionCapHint.textContent = 'Section occupancy unavailable — capacity is still checked when you save.';
            sectionCapHint.style.display = 'block';
        });
    }

    /* ── Roll Number Research phase: opt-in "Suggest" ──────────────────
       Explicit action only — never runs automatically, never overwrites
       something the operator already typed without them clicking again. */
    var suggestRollBtn  = document.getElementById('suggestRollBtn');
    var suggestRollHint = document.getElementById('suggestRollHint');
    if (suggestRollBtn) {
        suggestRollBtn.addEventListener('click', function () {
            // ADM-1 (2026-08-16) — this read getElementById('class'), but the
            // select is id="class_name". It therefore resolved to null, `cls`
            // was always '', and the button ALWAYS answered "Select Class and
            // Section first." even with both chosen — a permanently dead
            // control. Every other consumer in this file (lines ~1074, ~1113)
            // already used 'class_name'; this one site was the outlier.
            var classEl = document.getElementById('class_name');
            var sectionEl = document.getElementById('section');
            var cls = classEl ? classEl.value : '';
            var sec = sectionEl ? sectionEl.value : '';
            if (!cls || !sec) {
                if (suggestRollHint) {
                    suggestRollHint.style.display = 'block';
                    suggestRollHint.style.color = '#b91c1c';
                    suggestRollHint.textContent = 'Select Class and Section first.';
                }
                return;
            }
            suggestRollBtn.disabled = true;
            suggestRollBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Finding…';
            var fd = new FormData();
            fd.append('class', cls);
            fd.append('section', sec);
            var tokenEl = document.querySelector('#add_student_form input[name="<?= $this->security->get_csrf_token_name() ?>"]');
            if (tokenEl) fd.append(tokenEl.name, tokenEl.value);

            fetch('<?= base_url("sis/suggest_roll_no") ?>', {
                method: 'POST', body: fd, credentials: 'same-origin'
            })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (res) {
                suggestRollBtn.disabled = false;
                suggestRollBtn.innerHTML = 'Suggest';
                if (tokenEl && res && res.csrf_token) tokenEl.value = res.csrf_token;
                var rollEl = document.getElementById('roll_no');
                if (res && res.status === 'success' && res.rollNo) {
                    if (rollEl) rollEl.value = res.rollNo;
                    if (suggestRollHint) {
                        suggestRollHint.style.display = 'block';
                        suggestRollHint.style.color = 'var(--t3,#6b7280)';
                        suggestRollHint.textContent = 'Suggested — you can still change it before saving.';
                    }
                } else if (suggestRollHint) {
                    suggestRollHint.style.display = 'block';
                    suggestRollHint.style.color = '#b91c1c';
                    suggestRollHint.textContent = (res && res.message) ? res.message : 'Could not suggest a Roll Number.';
                }
            })
            .catch(function () {
                suggestRollBtn.disabled = false;
                suggestRollBtn.innerHTML = 'Suggest';
            });
        });
    }

    /* ── G10: admission fee (class-specific) ── */
    var admSection = document.getElementById('sec-admission-fee');

    function loadAdmissionFee(cls) {
        if (!admSection) return;
        if (!cls) { admSection.style.display = 'none'; return; }
        var fd = new FormData();
        fd.append('class', cls);
        var tokenEl = document.querySelector('#add_student_form input[name="<?= $this->security->get_csrf_token_name() ?>"]');
        if (tokenEl) fd.append(tokenEl.name, tokenEl.value);

        fetch('<?= base_url("sis/resolve_admission_fee") ?>', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(res) {
            // Fail closed on a denied/errored lookup: hide the section rather
            // than imply "no fee due". The server re-resolves authoritatively
            // at save time and will reject a missing payment mode anyway.
            if (!res || res.status !== 'success') { admSection.style.display = 'none'; return; }
            if (tokenEl && res.csrf_token) tokenEl.value = res.csrf_token;
            if (!res.enabled || !(res.amount > 0)) { admSection.style.display = 'none'; return; }

            var summary = document.getElementById('admFeeSummary');
            if (summary) {
                summary.innerHTML = '<strong>' + escapeHtml(res.label || 'Admission Fee') + ':</strong> ₹'
                    + Number(res.amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                    + ' — collected at admission.';
            }
            admSection.style.display = '';
        })
        .catch(function() { admSection.style.display = 'none'; });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    /* Payment mode → show bank ledger + reference for non-cash */
    var admMode = document.getElementById('admission_payment_mode');
    if (admMode) {
        admMode.addEventListener('change', function() {
            var isCash = (this.value === 'cash');
            var hasMode = (this.value !== '');
            var bankField = document.getElementById('admBankLedgerField');
            var refField  = document.getElementById('admBankRefField');
            if (bankField) bankField.style.display = (hasMode && !isCash) ? '' : 'none';
            if (refField)  refField.style.display  = (hasMode && !isCash) ? '' : 'none';
        });
    }

    /* Waive toggle → require a reason, disable the collection fields */
    var waiveBox = document.getElementById('waive_admission_fee');
    if (waiveBox) {
        waiveBox.addEventListener('change', function() {
            var on = this.checked;
            var wrap = document.getElementById('admWaiveReasonWrap');
            var collect = document.getElementById('admFeeCollectFields');
            if (wrap)    wrap.style.display = on ? '' : 'none';
            if (collect) collect.style.display = on ? 'none' : '';
            var reason = document.getElementById('waive_reason');
            if (reason && !on) reason.value = '';
        });
    }

    /* State → district */
    var stateDistricts = {
        "Uttar Pradesh": ["Agra","Aligarh","Allahabad","Ambedkar Nagar","Amethi","Amroha","Auraiya","Azamgarh","Baghpat","Bahraich","Ballia","Balrampur","Banda","Barabanki","Bareilly","Basti","Bhadohi","Bijnor","Budaun","Bulandshahr","Chandauli","Chitrakoot","Deoria","Etah","Etawah","Faizabad","Farrukhabad","Fatehpur","Firozabad","Gautam Buddha Nagar","Ghaziabad","Ghazipur","Gonda","Gorakhpur","Hamirpur","Hapur","Hardoi","Hathras","Jalaun","Jaunpur","Jhansi","Kannauj","Kanpur Dehat","Kanpur Nagar","Kasganj","Kaushambi","Kushinagar","Lakhimpur Kheri","Lalitpur","Lucknow","Maharajganj","Mahoba","Mainpuri","Mathura","Mau","Meerut","Mirzapur","Moradabad","Muzaffarnagar","Pilibhit","Pratapgarh","Raebareli","Rampur","Saharanpur","Sambhal","Sant Kabir Nagar","Shahjahanpur","Shamli","Shravasti","Siddharthnagar","Sitapur","Sonbhadra","Sultanpur","Unnao","Varanasi"],
        "Uttarakhand": ["Almora","Bageshwar","Chamoli","Champawat","Dehradun","Haridwar","Nainital","Pauri Garhwal","Pithoragarh","Rudraprayag","Tehri Garhwal","Udham Singh Nagar","Uttarkashi"],
        "Delhi": ["Central Delhi","East Delhi","New Delhi","North Delhi","North East Delhi","North West Delhi","South Delhi","South East Delhi","South West Delhi","West Delhi"],
        "Maharashtra": ["Mumbai","Pune","Nagpur","Nashik","Thane","Aurangabad","Solapur","Kolhapur","Amravati","Nanded","Sangli","Jalgaon","Latur"],
        "Rajasthan": ["Ajmer","Alwar","Banswara","Baran","Barmer","Bharatpur","Bhilwara","Bikaner","Bundi","Chittorgarh","Churu","Dausa","Dholpur","Dungarpur","Hanumangarh","Jaipur","Jaisalmer","Jalore","Jhalawar","Jhunjhunu","Jodhpur","Karauli","Kota","Nagaur","Pali","Pratapgarh","Rajsamand","Sawai Madhopur","Sikar","Sirohi","Sri Ganganagar","Tonk","Udaipur"],
        "Gujarat": ["Ahmedabad","Amreli","Anand","Aravalli","Banaskantha","Bharuch","Bhavnagar","Botad","Chhota Udaipur","Dahod","Dang","Devbhoomi Dwarka","Gandhinagar","Gir Somnath","Jamnagar","Junagadh","Kheda","Kutch","Mahisagar","Mehsana","Morbi","Narmada","Navsari","Panchmahal","Patan","Porbandar","Rajkot","Sabarkantha","Surat","Surendranagar","Tapi","Vadodara","Valsad"],
        "Karnataka": ["Bagalkot","Ballari","Belagavi","Bengaluru Rural","Bengaluru Urban","Bidar","Chamarajanagara","Chikballapur","Chikkamagaluru","Chitradurga","Dakshina Kannada","Davanagere","Dharwad","Gadag","Hassan","Haveri","Kalaburagi","Kodagu","Kolar","Koppal","Mandya","Mysuru","Raichur","Ramanagara","Shivamogga","Tumakuru","Udupi","Uttara Kannada","Vijayanagara","Vijayapura","Yadgir"],
        "Tamil Nadu": ["Ariyalur","Chengalpattu","Chennai","Coimbatore","Cuddalore","Dharmapuri","Dindigul","Erode","Kallakurichi","Kancheepuram","Kanyakumari","Karur","Krishnagiri","Madurai","Mayiladuthurai","Nagapattinam","Namakkal","Nilgiris","Perambalur","Pudukkottai","Ramanathapuram","Ranipet","Salem","Sivaganga","Tenkasi","Thanjavur","Theni","Thoothukudi","Tiruchirappalli","Tirunelveli","Tirupathur","Tiruppur","Tiruvallur","Tiruvannamalai","Tiruvarur","Vellore","Villupuram","Virudhunagar"],
        "Kerala": ["Alappuzha","Ernakulam","Idukki","Kannur","Kasaragod","Kollam","Kottayam","Kozhikode","Malappuram","Palakkad","Pathanamthitta","Thiruvananthapuram","Thrissur","Wayanad"],
        "West Bengal": ["Alipurduar","Bankura","Birbhum","Cooch Behar","Dakshin Dinajpur","Darjeeling","Hooghly","Howrah","Jalpaiguri","Jhargram","Kalimpong","Kolkata","Malda","Murshidabad","Nadia","North 24 Parganas","Paschim Bardhaman","Paschim Medinipur","Purba Bardhaman","Purba Medinipur","Purulia","South 24 Parganas","Uttar Dinajpur"],
        "Bihar": ["Araria","Arwal","Aurangabad","Banka","Begusarai","Bhagalpur","Bhojpur","Buxar","Darbhanga","East Champaran","Gaya","Gopalganj","Jamui","Jehanabad","Kaimur","Katihar","Khagaria","Kishanganj","Lakhisarai","Madhepura","Madhubani","Munger","Muzaffarpur","Nalanda","Nawada","Patna","Purnia","Rohtas","Saharsa","Samastipur","Saran","Sheikhpura","Sheohar","Sitamarhi","Siwan","Supaul","Vaishali","West Champaran"],
        "Punjab": ["Amritsar","Barnala","Bathinda","Faridkot","Fatehgarh Sahib","Fazilka","Ferozepur","Gurdaspur","Hoshiarpur","Jalandhar","Kapurthala","Ludhiana","Mansa","Moga","Mohali","Muktsar","Nawanshahr","Pathankot","Patiala","Roopnagar","Sangrur","Tarn Taran"],
        "Haryana": ["Ambala","Bhiwani","Charkhi Dadri","Faridabad","Fatehabad","Gurugram","Hisar","Jhajjar","Jind","Kaithal","Karnal","Kurukshetra","Mahendragarh","Nuh","Palwal","Panchkula","Panipat","Rewari","Rohtak","Sirsa","Sonipat","Yamunanagar"]
    };

    var stateEl = document.getElementById('state');
    if (stateEl) {
        stateEl.addEventListener('change', function() {
            var distSel = document.getElementById('city');
            if (!distSel) return;
            distSel.innerHTML = '<option value="">Select District</option>';
            var list = stateDistricts[this.value];
            if (list) {
                list.forEach(function(d) {
                    var o = document.createElement('option');
                    o.value = o.textContent = d;
                    distSel.appendChild(o);
                });
            } else {
                distSel.innerHTML = '<option value="">No districts listed</option>';
            }
        });
    }

    /* Sidebar active highlight on scroll */
    var sections = document.querySelectorAll('.sa-section');
    var navItems = document.querySelectorAll('.sa-nav-item');
    window.addEventListener('scroll', function() {
        var scrollY = window.scrollY + 120;
        sections.forEach(function(sec) {
            if (sec.offsetTop <= scrollY && sec.offsetTop + sec.offsetHeight > scrollY) {
                navItems.forEach(function(n) { n.classList.remove('active'); });
                var match = document.querySelector('.sa-nav-item[href="#' + sec.id + '"]');
                if (match) match.classList.add('active');
            }
        });
    });

    // LEAD SYSTEM — prefill form from lead data if lead_id is present
    var leadId = '<?= htmlspecialchars($lead_id ?? '', ENT_QUOTES, 'UTF-8') ?>';
    if (leadId) {
        fetch('<?= base_url("sis/get_lead_data") ?>?lead_id=' + encodeURIComponent(leadId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status !== 'success' || !data.lead) return;
            var L = data.lead;

            // Basic fields
            if (L.student_name) document.getElementById('sname').value = L.student_name;
            if (L.phone)        document.getElementById('phone_number').value = L.phone;
            if (L.email)        document.getElementById('email_user').value = L.email;
            if (L.father_name)  document.getElementById('father_name').value = L.father_name;
            if (L.mother_name)  document.getElementById('mother_name').value = L.mother_name;
            if (L.parent_name && !L.father_name) document.getElementById('father_name').value = L.parent_name;
            if (L.guardian_phone || L.phone) document.getElementById('guard_contact').value = L.guardian_phone || L.phone;
            if (L.dob) {
                var dobField = document.getElementById('dob');
                // Convert dd-mm-yyyy or yyyy-mm-dd
                var parsed = new Date(L.dob);
                if (!isNaN(parsed)) dobField.value = parsed.toISOString().slice(0, 10);
            }
            if (L.gender) {
                var gSel = document.getElementById('gender');
                if (gSel) { for (var i = 0; i < gSel.options.length; i++) {
                    if (gSel.options[i].value === L.gender) { gSel.selectedIndex = i; break; }
                }}
            }
            if (L.address) document.getElementById('street') && (document.getElementById('street').value = L.address);
            if (L.city) document.getElementById('city') && (document.getElementById('city').value = L.city);
            if (L.state) {
                var stEl = document.getElementById('state');
                if (stEl) { for (var j = 0; j < stEl.options.length; j++) {
                    if (stEl.options[j].value === L.state) { stEl.selectedIndex = j; stEl.dispatchEvent(new Event('change')); break; }
                }}
            }
            if (L.pincode) document.getElementById('postal_code') && (document.getElementById('postal_code').value = L.pincode);
            if (L.religion) {
                var relSel = document.getElementById('religion');
                if (relSel) { for (var k = 0; k < relSel.options.length; k++) {
                    if (relSel.options[k].value === L.religion) { relSel.selectedIndex = k; break; }
                }}
            }
            if (L.nationality) {
                var natSel = document.getElementById('nationality');
                if (natSel) { for (var n = 0; n < natSel.options.length; n++) {
                    if (natSel.options[n].value === L.nationality) { natSel.selectedIndex = n; break; }
                }}
            }

            // Class — trigger change to load sections
            if (L['class']) {
                var cls = L['class'].replace(/^Class\s+/i, '');
                for (var c = 0; c < classSelect.options.length; c++) {
                    if (classSelect.options[c].value === cls) {
                        classSelect.selectedIndex = c;
                        classSelect.dispatchEvent(new Event('change'));
                        // Section (set after sections populate)
                        if (L.section) {
                            setTimeout(function() {
                                for (var s = 0; s < sectionSelect.options.length; s++) {
                                    if (sectionSelect.options[s].value === L.section) {
                                        sectionSelect.selectedIndex = s; break;
                                    }
                                }
                            }, 200);
                        }
                        break;
                    }
                }
            }

            // ── Files uploaded on the public application: show them + carry
            //    over on save; relax "required" where the lead already
            //    supplied the photo/Aadhaar so the admin needn't re-scan. ──
            (function(){
                function _ext(s){ s=(s||'').split('?')[0]; try{s=decodeURIComponent(s);}catch(e){} var i=s.lastIndexOf('.'); return i<0?'':s.slice(i+1).toLowerCase(); }
                function _isImg(s){ return ['jpg','jpeg','png','gif','webp'].indexOf(_ext(s))!==-1; }
                function _esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
                // Preset the Passport Size Photo preview from the lead (this is
                // where the uploaded photo is shown — so we do NOT also list it
                // in the attachments card below, to avoid a duplicate photo).
                if (L.photo_url){
                    var pp=document.getElementById('passportPhotoPreview'); if (pp) pp.src = L.photo_url;
                    var sp=document.getElementById('student_photo'); if (sp) sp.removeAttribute('required');
                    var wp=document.getElementById('wrap_student_photo'); if (wp) wp.classList.add('has-file');
                }
                if (L.aadhaar_url){ var ac=document.getElementById('aadharCard'); if (ac) ac.removeAttribute('required'); }
                // Attachments card lists Aadhaar + other documents only (photo is
                // already visible in the Passport Size Photo box above).
                var items = [];
                if (L.aadhaar_url) items.push({label:'Aadhaar Card', url:L.aadhaar_url});
                if (Array.isArray(L.documents)) L.documents.forEach(function(d){ if (d && d.url) items.push({label:(d.name || 'Document'), url:d.url}); });
                if (!items.length) return;
                var tiles='';
                items.forEach(function(a){
                    var im=a.img||_isImg(a.url), lbl=_esc(a.label), url=_esc(a.url);
                    if (im) tiles += '<a href="'+url+'" target="_blank" rel="noopener" style="display:inline-block;width:92px;text-align:center;text-decoration:none;color:#334155;"><img src="'+url+'" alt="'+lbl+'" style="width:92px;height:92px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;display:block;"><span style="display:block;font-size:11px;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+lbl+'</span></a>';
                    else tiles += '<a href="'+url+'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#BC5A3C;font-size:13px;font-weight:600;"><i class="fa fa-file-o"></i> '+lbl+'</a>';
                });
                var card=document.createElement('div');
                card.style.cssText='background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;padding:14px 16px;margin:0 0 16px;';
                card.innerHTML='<div style="font-size:13px;font-weight:700;color:#BC5A3C;margin-bottom:4px;"><i class="fa fa-paperclip"></i> Uploaded with the application</div>'
                             + '<div style="font-size:11.5px;color:#5a716c;margin-bottom:10px;">These are attached to the student automatically on save. Upload again only to replace one.</div>'
                             + '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;">'+tiles+'</div>';
                var docsSec=document.getElementById('sec-docs');
                if (docsSec && docsSec.firstChild) docsSec.insertBefore(card, docsSec.firstChild);
                else if (docsSec) docsSec.appendChild(card);
            })();

            // Store lead_id in hidden field for save_admission
            var hid = document.createElement('input');
            hid.type = 'hidden'; hid.name = 'lead_id'; hid.value = leadId;
            document.querySelector('form') && document.querySelector('form').appendChild(hid);

            // Show info banner
            var banner = document.createElement('div');
            banner.style.cssText = 'background:#dcfce7;color:#166534;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;border:1px solid #bbf7d0;';
            banner.innerHTML = '<i class="fa fa-info-circle"></i> Prefilled from lead <strong>' + (L.id || leadId) + '</strong> — ' + (L.student_name || '') + '. Review and complete the admission.';
            var formTop = document.getElementById('sec-basic');
            if (formTop) formTop.parentNode.insertBefore(banner, formTop);
        })
        .catch(function(err) { console.warn('Lead prefill failed:', err); });
    }

});
</script>

<!-- India state→district autocomplete for the City field (preserved from live). -->
<script src="<?= base_url('assets/js/india_geo.js') ?>"></script>



<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Lora:wght@500;600;700&display=swap');

:root {
    --sa-navy:   #0c1e38;
    --sa-blue:   #BC5A3C;   /* project brand */
    --sa-sky:    #F7ECE7;   /* brand tint   */
    --sa-green:  #15803d;
    --sa-red:    #dc2626;
    --sa-amber:  #d97706;
    --sa-text:   #1a2535;
    --sa-muted:  #64748b;
    --sa-border: #e2e8f0;
    --sa-white:  #ffffff;
    --sa-bg:     #f1f5fb;
    --sa-shadow: 0 1px 12px rgba(12,30,56,.07);
    --sa-radius: 12px;
}

/* ── TAB WIZARD ───────────────────────────────────────────────────────────
   The eight sections below were already the natural chapters of an
   admission; the wizard shows them one at a time instead of as one long
   scroll, so an operator always knows how much is left. Nothing is
   re-ordered and no field moves — only visibility changes, which keeps the
   existing preview/submit flow working unchanged. */
.sa-wiz{display:flex;gap:4px;overflow-x:auto;padding:4px 0 0;margin:0 0 22px;
  border-bottom:1px solid var(--sa-border);scrollbar-width:thin}
.sa-wiz-step{display:flex;align-items:center;gap:9px;padding:11px 15px;border:none;
  background:none;cursor:pointer;white-space:nowrap;color:var(--sa-muted);
  font:600 13px/1 inherit;border-bottom:2px solid transparent;transition:color .15s}
.sa-wiz-step:hover{color:var(--sa-text)}
.sa-wiz-step[aria-current="step"]{color:var(--sa-blue);border-bottom-color:var(--sa-blue)}
.sa-wiz-num{display:inline-flex;align-items:center;justify-content:center;
  width:22px;height:22px;border-radius:50%;background:var(--sa-border);
  color:var(--sa-text);font-size:11px;font-weight:700;flex:none}
.sa-wiz-step[aria-current="step"] .sa-wiz-num{background:var(--sa-blue);color:#fff}
.sa-wiz-step.is-done .sa-wiz-num{background:var(--sa-sky);color:var(--sa-blue)}
.sa-wiz-step.is-done .sa-wiz-num::before{content:"13"}
.sa-wiz-step.is-done .sa-wiz-num span{display:none}
.sa-wiz-progress{height:3px;background:var(--sa-border);border-radius:2px;overflow:hidden;margin:0 0 20px}
.sa-wiz-progress i{display:block;height:100%;background:var(--sa-blue);
  border-radius:2px;transition:width .25s ease}
.sa-section[hidden]{display:none!important}
.sa-wiz-nav{display:flex;align-items:center;gap:10px;margin-right:auto}
@media(prefers-reduced-motion:reduce){.sa-wiz-progress i{transition:none}}

/* ── Page shell ── */
.sa-wrap {
    font-family: 'Instrument Sans', sans-serif;
    background: var(--sa-bg);
    color: var(--sa-text);
    padding: 26px 22px 60px;
    min-height: 100vh;
}

/* ── Top bar ── */
.sa-topbar { margin-bottom: 28px; }
.sa-page-title {
    font-family: 'Lora', serif;
    font-size: 25px; font-weight: 700;
    color: var(--sa-navy);
    display: flex; align-items: center; gap: 10px;
    margin: 0 0 6px;
}
.sa-page-title i { color: var(--sa-blue); }
.sa-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--sa-muted);
    list-style: none; margin: 0; padding: 0;
}
.sa-breadcrumb a { color: var(--sa-blue); text-decoration: none; font-weight: 500; }
.sa-breadcrumb a:hover { text-decoration: underline; }
.sa-breadcrumb li::before { content: '/'; margin-right: 6px; color: #cbd5e1; }
.sa-breadcrumb li:first-child::before { display: none; }

/* ── Step indicator ── */
.sa-steps {
    display: flex; gap: 0;
    background: var(--sa-white);
    border-radius: var(--sa-radius);
    box-shadow: var(--sa-shadow);
    overflow: hidden;
    margin-bottom: 24px;
}
.sa-step {
    flex: 1; padding: 14px 10px;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    font-size: 12.5px; font-weight: 600;
    color: var(--sa-muted);
    border-right: 1px solid var(--sa-border);
    cursor: pointer;
    transition: background .15s, color .15s;
    position: relative;
}
.sa-step:last-child { border-right: none; }
.sa-step-num {
    width: 24px; height: 24px; border-radius: 50%;
    background: var(--sa-border); color: var(--sa-muted);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; flex-shrink: 0;
    transition: background .15s, color .15s;
}
.sa-step.active { color: var(--sa-blue); background: var(--sa-sky); }
.sa-step.active .sa-step-num { background: var(--sa-blue); color: #fff; }
.sa-step.done { color: var(--sa-green); }
.sa-step.done .sa-step-num { background: var(--sa-green); color: #fff; }
@media (max-width: 640px) {
    .sa-step-label { display: none; }
    .sa-step { padding: 12px; }
}

/* ── Layout: sidebar + main ── */
.sa-layout {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 900px) {
    .sa-layout { grid-template-columns: 1fr; }
    .sa-sidebar { display: none; }
}

/* ── Sidebar nav ── */
.sa-sidebar {
    background: var(--sa-white);
    border-radius: var(--sa-radius);
    box-shadow: var(--sa-shadow);
    overflow: hidden;
    position: sticky;
    top: 16px;
}
.sa-sidebar-title {
    padding: 14px 16px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .7px;
    color: var(--sa-muted);
    border-bottom: 1px solid var(--sa-border);
    background: var(--sa-bg);
}
.sa-nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px;
    font-size: 13px; font-weight: 500;
    color: var(--sa-muted);
    border-bottom: 1px solid var(--sa-border);
    cursor: pointer;
    transition: background .12s, color .12s;
    text-decoration: none;
}
.sa-nav-item:last-child { border-bottom: none; }
.sa-nav-item i { width: 16px; text-align: center; color: #94a3b8; transition: color .12s; }
.sa-nav-item:hover { background: var(--sa-sky); color: var(--sa-blue); }
.sa-nav-item:hover i { color: var(--sa-blue); }
.sa-nav-item.active { background: var(--sa-sky); color: var(--sa-blue); font-weight: 600; border-left: 3px solid var(--sa-blue); }
.sa-nav-item.active i { color: var(--sa-blue); }

/* ── Main content ── */
.sa-main { display: flex; flex-direction: column; gap: 18px; }

/* ── Section card ── */
.sa-section {
    background: var(--sa-white);
    border-radius: var(--sa-radius);
    box-shadow: var(--sa-shadow);
    overflow: hidden;
}
.sa-section-head {
    padding: 15px 22px;
    border-bottom: 1px solid var(--sa-border);
    display: flex; align-items: center; gap: 10px;
    background: linear-gradient(90deg, var(--sa-sky) 0%, var(--sa-white) 100%);
}
.sa-section-head i { color: var(--sa-blue); font-size: 15px; }
.sa-section-head h3 {
    margin: 0;
    font-family: 'Lora', serif;
    font-size: 15px; font-weight: 600;
    color: var(--sa-navy);
}
.sa-section-body { padding: 22px 22px 10px; }

/* ── Grid ── */
.sa-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px 20px; }
.sa-grid-3 { grid-template-columns: repeat(3, 1fr); }
.sa-grid-2 { grid-template-columns: repeat(2, 1fr); }
.sa-col-2 { grid-column: span 2; }
.sa-col-3 { grid-column: span 3; }
.sa-col-4 { grid-column: span 4; }
@media (max-width: 1100px) { .sa-grid { grid-template-columns: repeat(3, 1fr); } .sa-col-4 { grid-column: span 3; } }
@media (max-width: 768px)  { .sa-grid { grid-template-columns: repeat(2, 1fr); } .sa-col-2,.sa-col-3,.sa-col-4 { grid-column: span 2; } }
@media (max-width: 480px)  { .sa-grid { grid-template-columns: 1fr; } .sa-col-2,.sa-col-3,.sa-col-4 { grid-column: span 1; } }

/* ── Form fields ── */
.sa-field { display: flex; flex-direction: column; gap: 5px; }
.sa-field label {
    font-size: 12px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px;
    color: var(--sa-muted);
}
.sa-field label .req { color: var(--sa-red); margin-left: 2px; }
.sa-field label .sa-opt { color: var(--sa-muted, #94a3b8); font-weight: 400; font-size: .82em; margin-left: 4px; }

.sa-input, .sa-select {
    height: 40px;
    padding: 0 12px;
    border: 1.5px solid var(--sa-border);
    border-radius: 8px;
    font-size: 13.5px;
    color: var(--sa-text);
    background: #fafbff;
    outline: none;
    transition: border-color .14s, box-shadow .14s;
    font-family: 'Instrument Sans', sans-serif;
    width: 100%;
}
.sa-input:focus, .sa-select:focus {
    border-color: var(--sa-blue);
    box-shadow: 0 0 0 3px rgba(15,118,110,.1);
    background: #fff;
}
.sa-input[readonly] {
    background: #f1f5f9;
    color: var(--sa-muted);
    cursor: not-allowed;
}
.sa-input.has-error, .sa-select.has-error { border-color: var(--sa-red); }
.sa-error-msg {
    font-size: 11.5px; color: var(--sa-red);
    display: flex; align-items: center; gap: 4px;
    margin-top: 2px;
}
.sa-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 11px center;
    background-color: #fafbff;
    padding-right: 30px;
}
.sa-select:disabled { opacity: .5; cursor: not-allowed; }

/* ── File input ── */
.sa-file-wrap {
    position: relative;
    border: 1.5px dashed var(--sa-border);
    border-radius: 8px;
    padding: 12px 14px;
    background: #fafbff;
    transition: border-color .14s;
    cursor: pointer;
}
.sa-file-wrap:hover { border-color: var(--sa-blue); }
.sa-file-wrap input[type="file"] {
    position: absolute; inset: 0;
    opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.sa-file-label {
    display: flex; align-items: center; gap: 10px;
    pointer-events: none;
}
.sa-file-icon {
    width: 34px; height: 34px; border-radius: 7px;
    background: var(--sa-sky);
    display: flex; align-items: center; justify-content: center;
    color: var(--sa-blue); font-size: 14px; flex-shrink: 0;
}
.sa-file-text { font-size: 12.5px; color: var(--sa-muted); }
.sa-file-text strong { display: block; font-size: 13px; color: var(--sa-text); font-weight: 600; }
.sa-file-name {
    font-size: 11.5px; color: var(--sa-green); font-weight: 600;
    margin-top: 4px; display: none;
}

/* ── Photo upload ── */
.sa-photo-wrap {
    display: flex; gap: 20px; align-items: flex-start;
}
.sa-photo-preview-box {
    width: 120px; flex-shrink: 0;
    display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.sa-photo-preview-box img {
    width: 110px; height: 130px;
    object-fit: cover; border-radius: 8px;
    border: 2px solid var(--sa-border);
    box-shadow: var(--sa-shadow);
}
.sa-photo-hint { font-size: 11px; color: var(--sa-muted); text-align: center; }
.sa-photo-file { flex: 1; }

/* ── Checkbox group ── */
.sa-check-group {
    border: 1.5px solid var(--sa-border);
    border-radius: 8px;
    padding: 12px 14px;
    background: #fafbff;
    max-height: 160px;
    overflow-y: auto;
}
.sa-check-item {
    display: flex; align-items: center; gap: 8px;
    padding: 5px 0;
    font-size: 13px; color: var(--sa-text);
    cursor: pointer;
}
.sa-check-item input[type="checkbox"] {
    width: 15px; height: 15px;
    accent-color: var(--sa-blue);
    cursor: pointer; flex-shrink: 0;
}
.sa-check-item:hover { color: var(--sa-blue); }
.sa-check-muted { font-size: 13px; color: var(--sa-muted); padding: 4px 0; }

.sa-check-all {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 0 10px;
    font-size: 12.5px; font-weight: 600;
    color: var(--sa-blue); cursor: pointer;
    border-bottom: 1px solid var(--sa-border);
    margin-bottom: 8px;
}
.sa-check-all input { accent-color: var(--sa-blue); width: 15px; height: 15px; }

/* ── Action bar ── */
.sa-action-bar {
    background: var(--sa-white);
    border-radius: var(--sa-radius);
    box-shadow: var(--sa-shadow);
    padding: 16px 22px;
    display: flex; align-items: center; justify-content: flex-end; gap: 12px;
}

/* ── Buttons ── */
.sa-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 22px; border-radius: 9px;
    font-size: 13.5px; font-weight: 600;
    cursor: pointer; border: none; text-decoration: none;
    transition: opacity .13s, transform .1s;
    font-family: 'Instrument Sans', sans-serif;
    white-space: nowrap;
}
.sa-btn:hover { opacity: .86; transform: translateY(-1px); }
.sa-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.sa-btn-primary { background: var(--sa-blue); color: #fff; box-shadow: 0 2px 10px rgba(15,118,110,.25); }
.sa-btn-ghost   { background: var(--sa-white); color: var(--sa-text); border: 1.5px solid var(--sa-border); }
.sa-btn-ghost:hover { border-color: var(--sa-blue); color: var(--sa-blue); }
.sa-btn-success { background: var(--sa-green); color: #fff; }
.sa-btn-danger  { background: #fff0f0; color: var(--sa-red); border: 1.5px solid #fca5a5; }
.sa-btn-danger:hover { background: var(--sa-red); color: #fff; }

/* ── Preview Modal ── */
.sa-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.5); z-index: 9100;
    align-items: flex-start; justify-content: center;
    padding: 24px 16px; overflow-y: auto;
}
.sa-overlay.open { display: flex; }
.sa-modal {
    background: var(--sa-white);
    border-radius: 16px;
    width: 100%; max-width: 860px;
    box-shadow: 0 20px 60px rgba(0,0,0,.22);
    animation: sa-modal-in .18s ease;
    margin: auto;
}
@keyframes sa-modal-in {
    from { transform: translateY(-12px); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
}
.sa-modal-head {
    background: var(--sa-navy);
    padding: 20px 26px;
    border-radius: 16px 16px 0 0;
    display: flex; align-items: center; justify-content: space-between;
}
.sa-modal-head h3 {
    margin: 0; font-family: 'Lora', serif;
    font-size: 19px; font-weight: 700; color: #fff;
}
.sa-modal-head p { margin: 3px 0 0; font-size: 12.5px; color: rgba(255,255,255,.6); }
.sa-modal-x {
    background: rgba(255,255,255,.1); border: none;
    color: #fff; font-size: 18px; width: 32px; height: 32px;
    border-radius: 8px; cursor: pointer; display: flex;
    align-items: center; justify-content: center;
    transition: background .13s;
}
.sa-modal-x:hover { background: rgba(255,255,255,.2); }

/* Student hero inside modal */
.sa-preview-hero {
    display: flex; align-items: center; gap: 20px;
    padding: 22px 26px;
    border-bottom: 1px solid var(--sa-border);
    background: var(--sa-sky);
}
.sa-preview-hero img {
    width: 80px; height: 96px; object-fit: cover;
    border-radius: 10px; border: 2px solid var(--sa-blue);
    flex-shrink: 0;
}
.sa-preview-hero-info h2 {
    margin: 0 0 4px;
    font-family: 'Lora', serif;
    font-size: 21px; font-weight: 700;
    color: var(--sa-navy);
}
.sa-preview-hero-badges { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 6px; }
.sa-prev-badge {
    padding: 3px 11px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
    background: rgba(15,118,110,.1); color: var(--sa-blue);
    border: 1px solid rgba(24,71,194,.2);
}

.sa-modal-body { padding: 22px 26px; }

/* Preview sections */
.sa-prev-section { margin-bottom: 22px; }
.sa-prev-section-title {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px;
    color: var(--sa-muted);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 7px;
}
.sa-prev-section-title::after {
    content: ''; flex: 1; height: 1px; background: var(--sa-border);
}
.sa-prev-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 10px 16px;
}
@media (max-width: 600px) { .sa-prev-grid { grid-template-columns: repeat(2, 1fr); } }

.sa-prev-field { }
.sa-prev-field .lbl {
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    color: #94a3b8; margin-bottom: 2px;
}
.sa-prev-field .val {
    font-size: 13.5px; font-weight: 500;
    color: var(--sa-text);
    word-break: break-word;
}

/* Doc preview inside modal */
.sa-prev-docs { display: flex; gap: 10px; flex-wrap: wrap; }
.sa-prev-doc-item {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 13px; border-radius: 8px;
    background: #f8faff; border: 1px solid var(--sa-border);
    font-size: 12.5px; font-weight: 500;
}
.sa-prev-doc-item i { color: var(--sa-blue); }
.sa-prev-doc-item a { color: var(--sa-blue); text-decoration: none; font-size: 11.5px; margin-left: 4px; }
.sa-prev-doc-item a:hover { text-decoration: underline; }

.sa-modal-foot {
    padding: 16px 26px;
    border-top: 1px solid var(--sa-border);
    display: flex; align-items: center; justify-content: flex-end; gap: 12px;
    border-radius: 0 0 16px 16px;
    background: var(--sa-bg);
}

/* ── Toast alerts ── */
.sa-toast {
    position: fixed; top: 20px; right: 20px;
    padding: 12px 18px; border-radius: 10px;
    font-size: 13.5px; font-weight: 500;
    color: #fff; z-index: 99999;
    box-shadow: 0 4px 14px rgba(0,0,0,.18);
    display: flex; align-items: center; gap: 9px;
    animation: sa-toast-in .25s ease;
    max-width: 340px;
}
@keyframes sa-toast-in {
    from { transform: translateX(20px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}
.sa-toast.success { background: var(--sa-green); }
.sa-toast.error   { background: var(--sa-red);   }
.sa-toast.warning { background: var(--sa-amber);  }
</style>
<script>
/* ── ADMISSION TAB WIZARD ──────────────────────────────────────────────────
   Shows one existing .sa-section at a time. Nothing is re-ordered, no field
   moves, and the Preview & Submit flow below is untouched — only visibility
   changes, so every handler already bound to these inputs keeps working.

   sec-admission-fee is deliberately NOT a step: it is shown and hidden by the
   fee logic depending on whether an admission fee applies, so it rides along
   inside the Fees step rather than becoming a step that sometimes vanishes. */
(function () {
    'use strict';

    var STEPS = [
        { id: 'sec-basic',    label: 'Basic',     icon: 'fa-user' },
        { id: 'sec-parents',  label: 'Parents',   icon: 'fa-users' },
        { id: 'sec-address',  label: 'Address',   icon: 'fa-map-marker' },
        { id: 'sec-previous', label: 'Previous School', icon: 'fa-institution' },
        { id: 'sec-other',    label: 'Other',     icon: 'fa-info-circle' },
        { id: 'sec-docs',     label: 'Documents', icon: 'fa-paperclip' },
        { id: 'sec-fees',     label: 'Fees',      icon: 'fa-inr' }
    ];

    var nav    = document.getElementById('saWiz');
    var bar    = document.getElementById('saWizBar');
    var back   = document.getElementById('saWizBack');
    var next   = document.getElementById('saWizNext');
    var submit = document.getElementById('submitStudentForm');
    if (!nav || !back || !next) return;

    // Only steps whose section actually rendered — a section removed by a
    // future edit must not leave a dead tab behind.
    var steps = STEPS.filter(function (s) { return document.getElementById(s.id); });
    if (!steps.length) return;
    var extra  = document.getElementById('sec-admission-fee');
    var extra2 = document.getElementById('sec-concessions');   // fee concessions + services, Fees step only
    var at = 0;

    steps.forEach(function (s, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'sa-wiz-step';
        b.dataset.i = i;
        b.innerHTML = '<span class="sa-wiz-num"><span>' + (i + 1) + '</span></span>' +
                      '<i class="fa ' + s.icon + '" aria-hidden="true"></i> ' + s.label;
        // Jumping back is free; jumping forward validates everything in between,
        // so a tab click can never skip a required field.
        b.addEventListener('click', function () { go(i, i > at); });
        nav.appendChild(b);
    });

    /* Validate only the fields inside one step. reportValidity() shows the
       browser's own message on the first offender, which is why the step must
       be visible before it is called — a hidden control cannot be focused and
       the browser reports nothing at all. */
    function stepValid(i) {
        var sec = document.getElementById(steps[i].id);
        if (!sec) return true;
        var bad = null;
        var fields = sec.querySelectorAll('input, select, textarea');
        for (var k = 0; k < fields.length; k++) {
            var f = fields[k];
            if (f.disabled || f.type === 'hidden' || f.offsetParent === null) continue;
            if (!f.checkValidity()) { bad = f; break; }
        }
        if (!bad) return true;
        show(i);
        bad.reportValidity();
        return false;
    }

    function show(i) {
        at = i;
        steps.forEach(function (s, k) {
            var sec = document.getElementById(s.id);
            if (sec) sec.hidden = (k !== i);
            var tab = nav.children[k];
            if (!tab) return;
            tab.classList.toggle('is-done', k < i);
            if (k === i) { tab.setAttribute('aria-current', 'step'); }
            else { tab.removeAttribute('aria-current'); }
        });
        if (extra)  extra.hidden  = (steps[i].id !== 'sec-fees');
        if (extra2) extra2.hidden = (steps[i].id !== 'sec-fees');
        back.style.visibility = i === 0 ? 'hidden' : 'visible';
        var last = i === steps.length - 1;
        next.style.display   = last ? 'none' : '';
        if (submit) submit.style.display = last ? '' : 'none';
        if (bar) bar.style.width = Math.round(((i + 1) / steps.length) * 100) + '%';
        nav.children[i] && nav.children[i].scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    function go(i, validateForward) {
        if (validateForward) {
            for (var k = at; k < i; k++) { if (!stepValid(k)) return; }
        }
        show(i);
        var main = document.querySelector('.sa-main') || document.body;
        main.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    next.addEventListener('click', function () { go(Math.min(at + 1, steps.length - 1), true); });
    back.addEventListener('click', function () { go(Math.max(at - 1, 0), false); });

    /* If the browser blocks submission because something invalid sits on a
       step the operator cannot see, jump them to it. Without this the click
       does nothing and the page looks broken. */
    if (submit) {
        submit.addEventListener('click', function () {
            for (var k = 0; k < steps.length; k++) { if (!stepValid(k)) return; }
        }, true);
    }

    show(0);
})();
</script>

<script>
/* ── P1/P2: PIN → State/District auto-fill (server proxy) + draft autosave ── */
(function () {
    'use strict';
    var pin  = document.getElementById('postal_code');
    var st   = document.getElementById('state');
    var dist = document.getElementById('city');          // District select (india_geo cascade)
    var hint = document.getElementById('pinLookupHint');
    var pinTimer = null, lastPin = '';

    function setHint(msg, ok) {
        if (!hint) return;
        hint.style.display = msg ? '' : 'none';
        hint.textContent   = msg || '';
        hint.style.color   = ok ? '#16a34a' : 'var(--sa-muted,#94a3b8)';
    }
    // Select an option by text/value; INJECT the API value if the static list
    // lacks it, so a PIN result is never silently dropped (no hardcoded gap).
    function selectByText(sel, val) {
        if (!sel || !val) return;
        var v = String(val).trim().toLowerCase(), hit = null;
        Array.prototype.forEach.call(sel.options, function (o) {
            if ((o.value || o.text).trim().toLowerCase() === v) hit = o;
        });
        if (!hit) { hit = new Option(val, val); sel.add(hit); }
        sel.value = hit.value;
    }
    function lookupPin() {
        if (!pin) return;
        var v = (pin.value || '').replace(/\D/g, '');
        if (v.length !== 6) { setHint('', false); return; }
        if (v === lastPin) return;
        lastPin = v;
        setHint('Looking up ' + v + '…', false);
        fetch('<?= base_url('sis/pincode_lookup') ?>?pin=' + encodeURIComponent(v),
              { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.status !== 'success' || !j.state) {
                    setHint((j && j.message) || 'Could not resolve PIN — pick State/District manually.', false);
                    return;
                }
                selectByText(st, j.state);
                if (st) st.dispatchEvent(new Event('change', { bubbles: true })); // cascade districts
                setTimeout(function () { selectByText(dist, j.district); }, 80);
                setHint('✓ ' + j.district + ', ' + j.state + (j.area ? ' · ' + j.area : ''), true);
            })
            .catch(function () { setHint('PIN lookup failed — pick State/District manually.', false); });
    }
    if (pin) {
        pin.addEventListener('input', function () { clearTimeout(pinTimer); pinTimer = setTimeout(lookupPin, 500); });
        pin.addEventListener('blur', lookupPin);
    }

    /* ---- Draft autosave (localStorage). Guards a 39-field form against a
       closed tab / timeout. class/section/state/district are AJAX/cascade-driven
       so they are re-picked, not stored; a restored PIN re-derives State/District. */
    // Scope the draft to THIS admin + school + session so that on a shared
    // front-office PC one operator can never see another operator's (or another
    // school's) half-typed admission with its student/parent PII. Sanitised to
    // keep the key localStorage-safe.
    var KEY = ('zenx_adm_draft_'
        + <?= json_encode(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($this->session->userdata('admin_id') ?? 'anon'))) ?>
        + '_' + <?= json_encode(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($this->session->userdata('session') ?: ($this->session_year ?? '')))) ?>);
    var DRAFT_MAX_AGE = 24 * 60 * 60 * 1000; // discard drafts older than 24h
    var form = document.getElementById('add_student_form');
    if (!form) return;
    var SKIP = { class_name: 1, section: 1, state: 1, city: 1 };
    function saveDraft() {
        try {
            var data = { __t: Date.now() };
            form.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (!el.name || el.type === 'file' || el.type === 'password' || el.type === 'hidden') return;
                if (/csrf/i.test(el.name) || SKIP[el.id]) return;
                var key = '#' + (el.id || el.name);
                if (el.type === 'checkbox') data[key] = el.checked ? 1 : 0;
                else if (el.value) data[key] = el.value;
            });
            localStorage.setItem(KEY, JSON.stringify(data));
        } catch (e) {}
    }
    function restoreDraft() {
        var raw; try { raw = localStorage.getItem(KEY); } catch (e) { return; }
        if (!raw) return;
        var data; try { data = JSON.parse(raw); } catch (e) { return; }
        if (!data) return;
        // Don't resurrect stale PII: a draft older than the cutoff is discarded,
        // not offered for restore.
        if (data.__t && (Date.now() - data.__t) > DRAFT_MAX_AGE) {
            try { localStorage.removeItem(KEY); } catch (e) {}
            return;
        }
        var restored = 0;
        Object.keys(data).forEach(function (k) {
            if (k.charAt(0) !== '#') return;
            var el = document.getElementById(k.slice(1));
            if (!el) return;
            if (el.type === 'checkbox') el.checked = !!data[k]; else el.value = data[k];
            restored++;
        });
        if (!restored) return;
        var banner = document.createElement('div');
        banner.style.cssText = 'margin:12px 0;padding:10px 14px;border-radius:8px;background:rgba(188,90,60,.08);'
            + 'border:1px solid rgba(188,90,60,.3);font-size:13px;display:flex;gap:10px;align-items:center;';
        banner.innerHTML = '<i class="fa fa-history"></i> Restored your unsaved draft.'
            + ' <button type="button" id="saDraftDiscard" style="margin-left:auto;background:none;border:1px solid rgba(188,90,60,.4);'
            + 'color:var(--sa-blue,#334155);border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;">Discard draft</button>';
        var main = document.querySelector('.sa-main') || form;
        main.insertBefore(banner, main.firstChild);
        var db = document.getElementById('saDraftDiscard');
        if (db) db.addEventListener('click', function () {
            window.__zenxClearAdmDraft(); banner.remove(); form.reset();
        });
        // If a PIN was restored, re-derive State/District automatically.
        if (pin && (pin.value || '').replace(/\D/g, '').length === 6) setTimeout(lookupPin, 200);
    }
    var saveTimer = null;
    function scheduleSave() { clearTimeout(saveTimer); saveTimer = setTimeout(saveDraft, 500); }
    form.addEventListener('input', scheduleSave);
    form.addEventListener('change', scheduleSave);
    window.__zenxClearAdmDraft = function () { try { localStorage.removeItem(KEY); } catch (e) {} };
    restoreDraft();
})();
</script>
