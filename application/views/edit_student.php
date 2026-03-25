<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php
/* ── Extract doc nodes ── */
if (!function_exists('getDocUrls')) {
    function getDocUrls($docNode) {
        if (is_array($docNode)) return ['url' => $docNode['url'] ?? '', 'thumbnail' => $docNode['thumbnail'] ?? ''];
        return ['url' => (string)($docNode ?? ''), 'thumbnail' => ''];
    }
}
$birthCert  = getDocUrls($student_data['Doc']['Birth Certificate']    ?? '');
$aadhar     = getDocUrls($student_data['Doc']['Aadhar Card']          ?? '');
$transfer   = getDocUrls($student_data['Doc']['Transfer Certificate'] ?? '');
$profilePic = $student_data['Profile Pic'] ?? '';
$addr       = $student_data['Address'] ?? [];
if (!is_array($addr)) $addr = [];

$selectedFees = is_array($selected_exempted_fees ?? null) ? array_keys($selected_exempted_fees) : [];
?>

<div class="content-wrapper">
<div class="sa-wrap">

    <!-- ── Top bar ── -->
    <div class="sa-topbar">
        <h1 class="sa-page-title">
            <i class="fa fa-pencil-square-o"></i> Edit Student
        </h1>
        <ol class="sa-breadcrumb">
            <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
            <li><a href="<?= base_url('sis/students') ?>">Student Records</a></li>
            <li>Edit Student</li>
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
            <form action="<?= base_url('student/edit_student/' . urlencode($student_data['User Id'])) ?>"
                  method="post"
                  id="edit_student_form"
                  enctype="multipart/form-data">
                <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
                       value="<?= $this->security->get_csrf_hash() ?>">

                <!-- == BASIC INFORMATION == -->
                <div class="sa-section" id="sec-basic">
                    <div class="sa-section-head">
                        <i class="fa fa-user"></i>
                        <h3>Basic Information</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid">

                            <div class="sa-field">
                                <label>Student ID</label>
                                <input type="text" name="user_id"
                                       value="<?= htmlspecialchars($student_data['User Id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" readonly>
                            </div>

                            <div class="sa-field">
                                <label>Student Name <span class="req">*</span></label>
                                <input type="text" name="Name"
                                       value="<?= htmlspecialchars($student_data['Name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Class</label>
                                <input type="text" name="class"
                                       value="<?= htmlspecialchars($student_data['Class'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" readonly>
                            </div>

                            <div class="sa-field">
                                <label>Section</label>
                                <input type="text" name="section"
                                       value="<?= htmlspecialchars($student_data['Section'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" readonly>
                            </div>

                            <div class="sa-field">
                                <label>Date of Birth <span class="req">*</span></label>
                                <input type="text" name="dob"
                                       value="<?= htmlspecialchars($student_data['DOB'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input datepicker" placeholder="dd-mm-yyyy" required>
                            </div>

                            <div class="sa-field">
                                <label>Gender</label>
                                <?php $gen = $student_data['Gender'] ?? ''; ?>
                                <select name="gender" class="sa-select">
                                    <option value="">Select Gender</option>
                                    <option value="Male"   <?= $gen === 'Male'   ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= $gen === 'Female' ? 'selected' : '' ?>>Female</option>
                                    <option value="Other"  <?= $gen === 'Other'  ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Admission Date <span class="req">*</span></label>
                                <input type="text" name="admission_date"
                                       value="<?= htmlspecialchars($student_data['Admission Date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input datepicker" placeholder="dd-mm-yyyy" required>
                            </div>

                            <div class="sa-field">
                                <label>Email <span class="req">*</span></label>
                                <input type="email" name="email"
                                       value="<?= htmlspecialchars($student_data['Email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Phone Number <span class="req">*</span></label>
                                <input type="text" name="phone_number"
                                       value="<?= htmlspecialchars($student_data['Phone Number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Blood Group <span class="req">*</span></label>
                                <?php $bg = $student_data['Blood Group'] ?? ''; ?>
                                <select name="blood_group" class="sa-select" required>
                                    <option value="">Select</option>
                                    <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-','Unknown'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $bg === $g ? 'selected' : '' ?>><?= $g ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Category <span class="req">*</span></label>
                                <?php $cat = $student_data['Category'] ?? ''; ?>
                                <select name="category" class="sa-select" required>
                                    <option value="">Select</option>
                                    <?php foreach (['General','OBC','SC','ST'] as $c): ?>
                                    <option value="<?= $c ?>" <?= $cat === $c ? 'selected' : '' ?>><?= $c ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- == PARENTS DETAILS == -->
                <div class="sa-section" id="sec-parents">
                    <div class="sa-section-head">
                        <i class="fa fa-users"></i>
                        <h3>Parents Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid">

                            <div class="sa-field">
                                <label>Father's Name <span class="req">*</span></label>
                                <input type="text" name="father_name"
                                       value="<?= htmlspecialchars($student_data['Father Name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Father's Occupation <span class="req">*</span></label>
                                <input type="text" name="father_occupation"
                                       value="<?= htmlspecialchars($student_data['Father Occupation'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Father's Contact <span class="req">*</span></label>
                                <input type="text" name="guard_contact"
                                       value="<?= htmlspecialchars($student_data['Guard Contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Guardian Relation <span class="req">*</span></label>
                                <input type="text" name="guard_relation"
                                       value="<?= htmlspecialchars($student_data['Guard Relation'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Mother's Name <span class="req">*</span></label>
                                <input type="text" name="mother_name"
                                       value="<?= htmlspecialchars($student_data['Mother Name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Mother's Occupation <span class="req">*</span></label>
                                <input type="text" name="mother_occupation"
                                       value="<?= htmlspecialchars($student_data['Mother Occupation'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- == ADDRESS == -->
                <div class="sa-section" id="sec-address">
                    <div class="sa-section-head">
                        <i class="fa fa-map-marker"></i>
                        <h3>Address Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid">

                            <div class="sa-field sa-col-2">
                                <label>Street <span class="req">*</span></label>
                                <input type="text" name="street"
                                       value="<?= htmlspecialchars($addr['Street'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>State <span class="req">*</span></label>
                                <?php $curState = $addr['State'] ?? ''; ?>
                                <select name="state" id="state" class="sa-select" required>
                                    <option value="">Select State</option>
                                    <?php foreach (['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Andaman and Nicobar Islands','Chandigarh','Dadra and Nagar Haveli and Daman and Diu','Delhi','Jammu and Kashmir','Ladakh','Lakshadweep','Puducherry'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $curState === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>District <span class="req">*</span></label>
                                <select name="city" id="city" class="sa-select" required>
                                    <option value="<?= htmlspecialchars($addr['City'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            selected><?= htmlspecialchars($addr['City'] ?? 'Select District', ENT_QUOTES, 'UTF-8') ?></option>
                                </select>
                            </div>

                            <div class="sa-field">
                                <label>Postal Code <span class="req">*</span></label>
                                <input type="text" name="postal_code"
                                       value="<?= htmlspecialchars($addr['PostalCode'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" placeholder="6-digit PIN" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- == PREVIOUS SCHOOL == -->
                <div class="sa-section" id="sec-previous">
                    <div class="sa-section-head">
                        <i class="fa fa-university"></i>
                        <h3>Previous School Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-3">

                            <div class="sa-field">
                                <label>Previous Class <span class="req">*</span></label>
                                <input type="text" name="pre_class"
                                       value="<?= htmlspecialchars($student_data['Pre Class'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Previous School Name <span class="req">*</span></label>
                                <input type="text" name="pre_school"
                                       value="<?= htmlspecialchars($student_data['Pre School'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" required>
                            </div>

                            <div class="sa-field">
                                <label>Marks % <span class="req">*</span></label>
                                <input type="text" name="pre_marks"
                                       value="<?= htmlspecialchars($student_data['Pre Marks'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" placeholder="e.g. 85%" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- == OTHER DETAILS == -->
                <div class="sa-section" id="sec-other">
                    <div class="sa-section-head">
                        <i class="fa fa-info-circle"></i>
                        <h3>Other Details</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-3">

                            <div class="sa-field">
                                <label>Religion <span class="req">*</span></label>
                                <?php
                                $rel = $student_data['Religion'] ?? '';
                                $stdReligions = ['Hindu','Muslim','Sikh','Jain','Buddh','Christian','Other'];
                                $isOther = ($rel !== '' && !in_array($rel, $stdReligions, true));
                                ?>
                                <select name="religion" id="religion" class="sa-select"
                                        onchange="toggleOtherReligion(this)" required>
                                    <option value="">Select Religion</option>
                                    <?php foreach ($stdReligions as $r): ?>
                                    <option value="<?= $r ?>" <?= ($rel === $r || ($isOther && $r === 'Other')) ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="other_religion" id="other_religion"
                                       class="sa-input" placeholder="Please specify"
                                       value="<?= $isOther ? htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') : '' ?>"
                                       style="display:<?= $isOther ? 'block' : 'none' ?>;margin-top:8px;">
                            </div>

                            <div class="sa-field">
                                <label>Nationality <span class="req">*</span></label>
                                <input type="text" name="nationality"
                                       value="<?= htmlspecialchars($student_data['Nationality'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       class="sa-input" placeholder="e.g. Indian" required>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- == DOCUMENTS == -->
                <div class="sa-section" id="sec-docs">
                    <div class="sa-section-head">
                        <i class="fa fa-file-text-o"></i>
                        <h3>Documents</h3>
                    </div>
                    <div class="sa-section-body">
                        <div class="sa-grid sa-grid-3">

                            <?php
                            $docs = [
                                ['key' => 'birthCertificate',    'label' => 'Birth Certificate',    'icon' => 'fa-file-pdf-o', 'data' => $birthCert],
                                ['key' => 'aadharCard',          'label' => 'Aadhar Card',          'icon' => 'fa-id-card',    'data' => $aadhar],
                                ['key' => 'transferCertificate', 'label' => 'Transfer Certificate', 'icon' => 'fa-certificate','data' => $transfer],
                            ];
                            foreach ($docs as $doc):
                            ?>
                            <div class="sa-field">
                                <label><?= $doc['label'] ?></label>
                                <?php if ($doc['data']['url']): ?>
                                <div style="margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                                    <a href="<?= htmlspecialchars($doc['data']['url']) ?>" target="_blank"
                                       style="font-size:12px;color:var(--sa-blue);font-weight:600;">
                                        <i class="fa fa-eye"></i> View Existing
                                    </a>
                                    <span style="font-size:11px;color:var(--sa-muted);">Upload below to replace</span>
                                </div>
                                <?php endif; ?>
                                <div class="sa-file-wrap" id="wrap_<?= $doc['key'] ?>">
                                    <input type="file" name="<?= $doc['key'] ?>" id="<?= $doc['key'] ?>"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           onchange="saFileChosen(this,'wrap_<?= $doc['key'] ?>')">
                                    <div class="sa-file-label">
                                        <div class="sa-file-icon"><i class="fa <?= $doc['icon'] ?>"></i></div>
                                        <div class="sa-file-text">
                                            <strong>Upload File</strong>
                                            PDF, JPG, PNG - max 2MB
                                        </div>
                                    </div>
                                    <div class="sa-file-name" id="fn_<?= $doc['key'] ?>"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                        </div>
                    </div>
                </div>

                <!-- == FEES EXEMPTION + PHOTO == -->
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
                                                               value="<?= htmlspecialchars($feeKey) ?>"
                                                               <?= in_array(trim($feeKey), $selectedFees, true) ? 'checked' : '' ?>>
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
                                <label>Passport Size Photo</label>
                                <div class="sa-photo-wrap">
                                    <div class="sa-photo-preview-box">
                                        <img id="passportPhotoPreview"
                                             src="<?= htmlspecialchars($profilePic ?: base_url('tools/dist/img/kids.jpg')) ?>"
                                             alt="Preview">
                                        <span class="sa-photo-hint">170 x 200 px<br>JPG/PNG/WEBP</span>
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
                                                    JPG, PNG, WEBP - max 2MB
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

                <!-- == ACTION BAR == -->
                <div class="sa-action-bar">
                    <button type="button" class="sa-btn sa-btn-ghost" onclick="window.history.back()">
                        <i class="fa fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="sa-btn sa-btn-primary" id="updateBtn">
                        <i class="fa fa-save"></i> Update Student
                    </button>
                </div>

            </form>
        </div><!-- /.sa-main -->
    </div><!-- /.sa-layout -->

</div><!-- /.sa-wrap -->
</div><!-- /.content-wrapper -->


<script>
/* ── File chosen feedback ── */
function saFileChosen(input, wrapId) {
    var wrap = document.getElementById(wrapId);
    var nameEl = wrap ? wrap.querySelector('.sa-file-name') : null;
    if (!nameEl) return;
    if (input.files && input.files.length > 0) {
        nameEl.textContent = '\u2713 ' + input.files[0].name;
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

/* ── Toast ── */
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

document.addEventListener('DOMContentLoaded', function() {

    /* ── Select all fees ── */
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

    /* ── State -> District ── */
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

    /* ── AJAX Submit ── */
    var form = document.getElementById('edit_student_form');
    if (!form) return;
    var isSubmitting = false;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (isSubmitting) return;
        isSubmitting = true;

        var btn = document.getElementById('updateBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...'; }

        $.ajax({
            url:         form.getAttribute('action'),
            type:        'POST',
            data:        new FormData(form),
            processData: false,
            contentType: false,
            dataType:    'json',
            success: function(res) {
                if (res.status === 'success') {
                    if (res.photo_notice) showAlert('info', res.photo_notice);
                    showAlert('success', 'Student updated successfully!');
                    setTimeout(function() { window.location.href = '<?= base_url("sis/students") ?>'; }, 1500);
                } else {
                    showAlert('error', res.message || 'Failed to update student.');
                    isSubmitting = false;
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Update Student'; }
                }
            },
            error: function() {
                showAlert('error', 'Server error. Please try again.');
                isSubmitting = false;
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Update Student'; }
            }
        });
    });

    /* ── Sidebar active highlight on scroll ── */
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

    /* ── Datepicker ── */
    if (typeof $.fn.datepicker !== 'undefined') {
        $('.datepicker').datepicker({
            format: 'dd-mm-yyyy',
            autoclose: true,
            todayHighlight: true
        });
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

.sa-wrap { font-family:'Instrument Sans',sans-serif; background:var(--sa-bg); color:var(--sa-text); padding:26px 22px 60px; min-height:100vh; }
.sa-topbar { margin-bottom:28px; }
.sa-page-title { font-family:'Lora',serif; font-size:25px; font-weight:700; color:var(--sa-navy); display:flex; align-items:center; gap:10px; margin:0 0 6px; }
.sa-page-title i { color:var(--sa-blue); }
.sa-breadcrumb { display:flex; align-items:center; gap:6px; font-size:13px; color:var(--sa-muted); list-style:none; margin:0; padding:0; }
.sa-breadcrumb a { color:var(--sa-blue); text-decoration:none; font-weight:500; }
.sa-breadcrumb a:hover { text-decoration:underline; }
.sa-breadcrumb li::before { content:'/'; margin-right:6px; color:#cbd5e1; }
.sa-breadcrumb li:first-child::before { display:none; }

.sa-layout { display:grid; grid-template-columns:220px 1fr; gap:20px; align-items:start; }
@media(max-width:900px){ .sa-layout{grid-template-columns:1fr;} .sa-sidebar{display:none;} }

.sa-sidebar { background:var(--sa-white); border-radius:var(--sa-radius); box-shadow:var(--sa-shadow); overflow:hidden; position:sticky; top:16px; }
.sa-sidebar-title { padding:14px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--sa-muted); border-bottom:1px solid var(--sa-border); background:var(--sa-bg); }
.sa-nav-item { display:flex; align-items:center; gap:10px; padding:11px 16px; font-size:13px; font-weight:500; color:var(--sa-muted); border-bottom:1px solid var(--sa-border); cursor:pointer; transition:background .12s,color .12s; text-decoration:none; }
.sa-nav-item:last-child { border-bottom:none; }
.sa-nav-item i { width:16px; text-align:center; color:#94a3b8; transition:color .12s; }
.sa-nav-item:hover { background:var(--sa-sky); color:var(--sa-blue); }
.sa-nav-item:hover i { color:var(--sa-blue); }
.sa-nav-item.active { background:var(--sa-sky); color:var(--sa-blue); font-weight:600; border-left:3px solid var(--sa-blue); }
.sa-nav-item.active i { color:var(--sa-blue); }

.sa-main { display:flex; flex-direction:column; gap:18px; }
.sa-section { background:var(--sa-white); border-radius:var(--sa-radius); box-shadow:var(--sa-shadow); overflow:hidden; }
.sa-section-head { padding:15px 22px; border-bottom:1px solid var(--sa-border); display:flex; align-items:center; gap:10px; background:linear-gradient(90deg,var(--sa-sky) 0%,var(--sa-white) 100%); }
.sa-section-head i { color:var(--sa-blue); font-size:15px; }
.sa-section-head h3 { margin:0; font-family:'Lora',serif; font-size:15px; font-weight:600; color:var(--sa-navy); }
.sa-section-body { padding:22px 22px 10px; }

.sa-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px 20px; }
.sa-grid-3 { grid-template-columns:repeat(3,1fr); }
.sa-grid-2 { grid-template-columns:repeat(2,1fr); }
.sa-col-2 { grid-column:span 2; }
@media(max-width:1100px){ .sa-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:768px){ .sa-grid{grid-template-columns:repeat(2,1fr);} .sa-col-2{grid-column:span 2;} }
@media(max-width:480px){ .sa-grid{grid-template-columns:1fr;} .sa-col-2{grid-column:span 1;} }

.sa-field { display:flex; flex-direction:column; gap:5px; }
.sa-field label { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--sa-muted); }
.sa-field label .req { color:var(--sa-red); margin-left:2px; }

.sa-input,.sa-select { height:40px; padding:0 12px; border:1.5px solid var(--sa-border); border-radius:8px; font-size:13.5px; color:var(--sa-text); background:#fafbff; outline:none; transition:border-color .14s,box-shadow .14s; font-family:'Instrument Sans',sans-serif; width:100%; }
.sa-input:focus,.sa-select:focus { border-color:var(--sa-blue); box-shadow:0 0 0 3px rgba(15,118,110,.1); background:#fff; }
.sa-input[readonly] { background:#f1f5f9; color:var(--sa-muted); cursor:not-allowed; }
.sa-select { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 11px center; background-color:#fafbff; padding-right:30px; }

.sa-file-wrap { position:relative; border:1.5px dashed var(--sa-border); border-radius:8px; padding:12px 14px; background:#fafbff; transition:border-color .14s; cursor:pointer; }
.sa-file-wrap:hover { border-color:var(--sa-blue); }
.sa-file-wrap input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
.sa-file-label { display:flex; align-items:center; gap:10px; pointer-events:none; }
.sa-file-icon { width:34px; height:34px; border-radius:7px; background:var(--sa-sky); display:flex; align-items:center; justify-content:center; color:var(--sa-blue); font-size:14px; flex-shrink:0; }
.sa-file-text { font-size:12.5px; color:var(--sa-muted); }
.sa-file-text strong { display:block; font-size:13px; color:var(--sa-text); font-weight:600; }
.sa-file-name { font-size:11.5px; color:var(--sa-green); font-weight:600; margin-top:4px; display:none; }

.sa-photo-wrap { display:flex; gap:20px; align-items:flex-start; }
.sa-photo-preview-box { width:120px; flex-shrink:0; display:flex; flex-direction:column; align-items:center; gap:8px; }
.sa-photo-preview-box img { width:110px; height:130px; object-fit:cover; border-radius:8px; border:2px solid var(--sa-border); box-shadow:var(--sa-shadow); }
.sa-photo-hint { font-size:11px; color:var(--sa-muted); text-align:center; }

.sa-check-group { border:1.5px solid var(--sa-border); border-radius:8px; padding:12px 14px; background:#fafbff; max-height:160px; overflow-y:auto; }
.sa-check-item { display:flex; align-items:center; gap:8px; padding:5px 0; font-size:13px; color:var(--sa-text); cursor:pointer; }
.sa-check-item input[type="checkbox"] { width:15px; height:15px; accent-color:var(--sa-blue); cursor:pointer; flex-shrink:0; }
.sa-check-item:hover { color:var(--sa-blue); }
.sa-check-muted { font-size:13px; color:var(--sa-muted); padding:4px 0; }
.sa-check-all { display:flex; align-items:center; gap:8px; padding:8px 0 10px; font-size:12.5px; font-weight:600; color:var(--sa-blue); cursor:pointer; border-bottom:1px solid var(--sa-border); margin-bottom:8px; }
.sa-check-all input { accent-color:var(--sa-blue); width:15px; height:15px; }

.sa-action-bar { background:var(--sa-white); border-radius:var(--sa-radius); box-shadow:var(--sa-shadow); padding:16px 22px; display:flex; align-items:center; justify-content:flex-end; gap:12px; }
.sa-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 22px; border-radius:9px; font-size:13.5px; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:opacity .13s,transform .1s; font-family:'Instrument Sans',sans-serif; white-space:nowrap; }
.sa-btn:hover { opacity:.86; transform:translateY(-1px); }
.sa-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; }
.sa-btn-primary { background:var(--sa-blue); color:#fff; box-shadow:0 2px 10px rgba(15,118,110,.25); }
.sa-btn-ghost { background:var(--sa-white); color:var(--sa-text); border:1.5px solid var(--sa-border); }
.sa-btn-ghost:hover { border-color:var(--sa-blue); color:var(--sa-blue); }

.sa-toast { position:fixed; top:20px; right:20px; padding:12px 18px; border-radius:10px; font-size:13.5px; font-weight:500; color:#fff; z-index:99999; box-shadow:0 4px 14px rgba(0,0,0,.18); display:flex; align-items:center; gap:9px; animation:sa-toast-in .25s ease; max-width:340px; }
@keyframes sa-toast-in { from{transform:translateX(20px);opacity:0;} to{transform:translateX(0);opacity:1;} }
.sa-toast.success { background:var(--sa-green); }
.sa-toast.error { background:var(--sa-red); }
.sa-toast.info { background:var(--sa-blue); }
</style>
