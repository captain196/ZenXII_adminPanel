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

                <!-- ══ BASIC INFORMATION ══ -->
                <div class="sa-section" id="sec-basic">
                    <div class="sa-section-head">
                        <i class="fa fa-user"></i>
                        <h3>Basic Information</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid">

                            <div class="sa-field">
                                <label>Student ID</label>
                                <input type="text" name="user_id" id="user_id"
                                       value="<?= htmlspecialchars($user_Id ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" readonly>
                            </div>

                            <div class="sa-field">
                                <label>Student Name <span class="req">*</span></label>
                                <input type="text" name="name" id="sname"
                                       class="sa-input" placeholder="Full name" required>
                            </div>

                            <div class="sa-field">
                                <label>Class <span class="req">*</span></label>
                                <select id="class_name" name="class" class="sa-select" required>
                                    <option value="" disabled selected>Select Class</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Section <span class="req">*</span></label>
                                <select id="section" name="section" class="sa-select" required>
                                    <option value="" disabled selected>Select Section</option>
                                </select>
                            </div>

                            <!-- FIXED: roll_no field was missing → backend always stored empty string -->
                            <div class="sa-field">
                                <label>Roll No</label>
                                <input type="text" name="roll_no" id="roll_no"
                                       class="sa-input" placeholder="Optional">
                            </div>

                            <div class="sa-field">
                                <label>Date of Birth <span class="req">*</span></label>
                                <input type="date" name="dob" id="dob" class="sa-input" required>
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
                                <label>Admission Date <span class="req">*</span></label>
                                <input type="date" name="admission_date" id="admission_date" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Email <span class="req">*</span></label>
                                <input type="email" name="email" id="email_user"
                                       class="sa-input" placeholder="student@email.com" required>
                            </div>

                            <div class="sa-field">
                                <label>Phone Number <span class="req">*</span></label>
                                <input type="text" name="phone_number" id="phone_number"
                                       class="sa-input" placeholder="10-digit number" required>
                            </div>

                            <div class="sa-field">
                                <label>Blood Group <span class="req">*</span></label>
                                <select name="blood_group" id="blood_group" class="sa-select" required>
                                    <option value="">Select</option>
                                    <option>A+</option><option>A-</option>
                                    <option>B+</option><option>B-</option>
                                    <option>O+</option><option>O-</option>
                                    <option>AB+</option><option>AB-</option>
                                    <option>Unknown</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Category <span class="req">*</span></label>
                                <select name="category" id="category" class="sa-select" required>
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
                                <label>Father's Name <span class="req">*</span></label>
                                <input type="text" name="father_name" id="father_name" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Father's Occupation <span class="req">*</span></label>
                                <input type="text" name="father_occupation" id="father_occupation" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Father's Contact <span class="req">*</span></label>
                                <input type="text" name="guard_contact" id="guard_contact" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Guardian Relation <span class="req">*</span></label>
                                <input type="text" name="guard_relation" id="guard_relation" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Mother's Name <span class="req">*</span></label>
                                <input type="text" name="mother_name" id="mother_name" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Mother's Occupation <span class="req">*</span></label>
                                <input type="text" name="mother_occupation" id="mother_occupation" class="sa-input" required>
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
                                <label>Street <span class="req">*</span></label>
                                <input type="text" name="street" id="street" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>State <span class="req">*</span></label>
                                <select name="state" id="state" class="sa-select" required>
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
                                <label>District <span class="req">*</span></label>
                                <select name="city" id="city" class="sa-select" required>
                                    <option value="">Select District</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Postal Code <span class="req">*</span></label>
                                <input type="text" name="postal_code" id="postal_code"
                                       class="sa-input" placeholder="6-digit PIN" required>
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
                                <label>Previous Class <span class="req">*</span></label>
                                <input type="text" name="pre_class" id="pre_class" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Previous School Name <span class="req">*</span></label>
                                <input type="text" name="pre_school" id="pre_school" class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Marks % <span class="req">*</span></label>
                                <input type="text" name="pre_marks" id="pre_marks"
                                       class="sa-input" placeholder="e.g. 85%" required>
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
                                <label>Religion <span class="req">*</span></label>
                                <select name="religion" id="religion" class="sa-select"
                                        onchange="toggleOtherReligion(this)" required>
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
                                <label>Nationality <span class="req">*</span></label>
                                <input type="text" name="nationality" id="nationality"
                                       class="sa-input" placeholder="e.g. Indian" required>
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
                                <label>Birth Certificate <span class="req">*</span></label>
                                <div class="sa-file-wrap" id="wrap_birthCertificate">
                                    <input type="file" name="birthCertificate" id="birthCertificate"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="saFileChosen(this,'wrap_birthCertificate')" required>
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
                                <label>Aadhar Card <span class="req">*</span></label>
                                <div class="sa-file-wrap" id="wrap_aadharCard">
                                    <input type="file" name="aadharCard" id="aadharCard"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="saFileChosen(this,'wrap_aadharCard')" required>
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
                                <label>Transfer Certificate <span class="req">*</span></label>
                                <div class="sa-file-wrap" id="wrap_transferCertificate">
                                    <input type="file" name="transferCertificate" id="transferCertificate"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="saFileChosen(this,'wrap_transferCertificate')" required>
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
                        <h3>Fee Exemptions &amp; Passport Photo</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-2">

                            <!-- Fee exemptions -->
                            <div class="sa-field">
                                <label>Fees to Exempt for This Student</label>

                                <!-- Select all checkbox -->
                                <label class="sa-check-all">
                                    <input type="checkbox" id="select_all_exempted_fees">
                                    Select All Fees
                                </label>

                                <div class="sa-check-group" style="max-height:180px;">
                                    <?php if (isset($exemptedFees) && is_array($exemptedFees)): ?>
                                        <?php foreach ($exemptedFees as $feeType => $fees): ?>
                                            <?php if (is_array($fees)): ?>
                                                <?php foreach ($fees as $feeKey => $feeValue): ?>
                                                    <label class="sa-check-item">
                                                        <input type="checkbox"
                                                               name="exempted_fees_multiple[]"
                                                               id="fee_<?= htmlspecialchars($feeKey) ?>"
                                                               value="<?= htmlspecialchars($feeKey) ?>">
                                                        <?= htmlspecialchars($feeKey) ?>
                                                        <span style="font-size:11px;color:var(--sa-muted);margin-left:4px;">(<?= htmlspecialchars($feeType) ?>)</span>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="sa-check-muted">No fee options available.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Passport photo -->
                            <div class="sa-field">
                                <label>Passport Size Photo <span class="req">*</span></label>
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
                                                   onchange="previewPassportPhoto(event);saFileChosen(this,'wrap_student_photo')"
                                                   required>
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

                <!-- ══ ACTION BAR ══ -->
                <div class="sa-action-bar">
                    <button type="reset" class="sa-btn sa-btn-ghost">
                        <i class="fa fa-refresh"></i> Reset
                    </button>
                    <button type="button" id="submitStudentForm"
                            onclick="previewFormBeforeSubmit(event)"
                            class="sa-btn sa-btn-primary">
                        <i class="fa fa-eye"></i> Preview &amp; Submit
                    </button>
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

    /* clear previous errors */
    document.querySelectorAll('.sa-input.has-error, .sa-select.has-error').forEach(function(el) {
        el.classList.remove('has-error');
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
        var span = document.createElement('span');
        span.className = 'sa-error-msg';
        span.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + message;
        input.parentNode.insertBefore(span, input.nextSibling);
        isValid = false;
    }

    if (!getValue('sname'))          showError('sname',          'Student name is required');
    if (!getValue('class_name'))     showError('class_name',     'Please select a class');
    if (!getValue('section'))        showError('section',        'Please select a section');
    if (!getValue('dob'))            showError('dob',            'Date of birth is required');
    if (!getValue('admission_date')) showError('admission_date', 'Admission date is required');

    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(getValue('email_user')))
        showError('email_user', 'Enter a valid email address');

    var phonePattern = /^[6-9]\d{9}$/;
    if (!phonePattern.test(getValue('phone_number')))
        showError('phone_number', 'Enter valid 10-digit mobile number');

    var postalPattern = /^[1-9][0-9]{5}$/;
    if (!postalPattern.test(getValue('postal_code')))
        showError('postal_code', 'Enter valid 6-digit PIN code');

    var marksPattern = /^[0-9]{1,3}%?$/;
    if (!marksPattern.test(getValue('pre_marks')))
        showError('pre_marks', 'Enter valid percentage (e.g. 85%)');

    /* files */
    function validateFile(inputId, allowedTypes, maxSizeMB) {
        var input = document.getElementById(inputId);
        if (!input) return;
        if (!input.files || !input.files.length) { showError(inputId, 'File is required'); return; }
        var file = input.files[0];
        if (!allowedTypes.includes(file.type)) showError(inputId, 'Invalid format (PDF, JPG, PNG allowed)');
        if (file.size > maxSizeMB * 1024 * 1024) showError(inputId, 'File must be under ' + maxSizeMB + 'MB');
    }

    var docTypes = ['image/jpeg','image/png','application/pdf'];
    validateFile('birthCertificate',    docTypes, 2);
    validateFile('aadharCard',          docTypes, 2);
    validateFile('transferCertificate', docTypes, 2);
    validateFile('student_photo', ['image/jpeg','image/png','image/webp'], 2);

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

/* ── Final submit via AJAX ── */
function submitFinalForm() {
    var form = document.getElementById('add_student_form');
    if (!form) { showAlert('error', 'Form not found — please refresh.'); return; }

    var btn = document.getElementById('finalSubmitBtn');
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
                showAlert('error', 'Unexpected server response.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Final Submit'; }
                return;
            }
            if (res.status === 'success') {
                closePreviewModal();
                showAlert('success', res.message || 'Student admitted successfully!');
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

    /* Class change → load sections from CLASS_MAP */
    classSelect.addEventListener('change', function() {
        sectionSelect.innerHTML = '<option value="" disabled selected>Select Section</option>';
        var cls = this.value;
        if (cls && CLASS_MAP[cls]) {
            CLASS_MAP[cls].forEach(function(sec) {
                var opt = document.createElement('option');
                opt.value = sec;
                opt.textContent = 'Section ' + sec;
                sectionSelect.appendChild(opt);
            });
        }
    });

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



<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Lora:wght@500;600;700&display=swap');

:root {
    --sa-navy:   #0c1e38;
    --sa-blue:   #0f766e;
    --sa-sky:    #e6f4f1;
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