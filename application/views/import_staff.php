<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="ims-wrap">

    <!-- Top bar -->
    <div class="ims-topbar">
        <h1 class="ims-page-title">
            <i class="fa fa-upload"></i> Bulk Staff Import
        </h1>
        <ol class="ims-breadcrumb">
            <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
            <li><a href="<?= base_url('staff/all_staff') ?>">All Staff</a></li>
            <li>Import Staff</li>
        </ol>
    </div>

    <!-- Flash result from non-AJAX fallback -->
    <?php if ($this->session->flashdata('import_result')): ?>
        <div class="ims-alert ims-alert-info">
            <i class="fa fa-info-circle"></i>
            <?= $this->session->flashdata('import_result') ?>
        </div>
    <?php endif; ?>

    <!-- Main grid -->
    <div class="ims-grid">

        <!-- LEFT: Upload Card -->
        <div class="ims-card ims-card-upload">
            <div class="ims-card-head">
                <i class="fa fa-cloud-upload"></i>
                <h3>Upload Staff File</h3>
            </div>
            <div class="ims-card-body">

                <!-- Step 1: Download Template -->
                <div class="ims-step">
                    <div class="ims-step-num">1</div>
                    <div class="ims-step-content">
                        <h4>Download Template</h4>
                        <p>Get the pre-formatted Excel template with correct headers, sample data, and dropdown validations.</p>
                        <a href="<?= base_url('staff/download_staff_template') ?>" class="ims-btn ims-btn-outline">
                            <i class="fa fa-download"></i> Download Template (.xlsx)
                        </a>
                    </div>
                </div>

                <!-- Step 2: Fill Data -->
                <div class="ims-step">
                    <div class="ims-step-num">2</div>
                    <div class="ims-step-content">
                        <h4>Fill Staff Data</h4>
                        <p>Open the template, delete the sample row, and enter your staff records. Required: <strong>Name, Phone, DOB, Email, Gender, Father Name, Position, Date Of Joining, Employment Type, Department, Basic Salary</strong>.</p>
                    </div>
                </div>

                <!-- Step 3: Upload -->
                <div class="ims-step">
                    <div class="ims-step-num">3</div>
                    <div class="ims-step-content">
                        <h4>Upload &amp; Import</h4>
                        <p>Drag and drop your file below or click to browse.</p>

                        <form id="importStaffForm" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
                                   value="<?= $this->security->get_csrf_hash() ?>">

                            <!-- Drop zone -->
                            <div class="ims-dropzone" id="dropZone">
                                <div class="ims-dropzone-inner" id="dropZoneInner">
                                    <i class="fa fa-file-excel-o ims-drop-icon"></i>
                                    <span class="ims-drop-text">Drop .xlsx or .csv file here</span>
                                    <span class="ims-drop-sub">or click to browse</span>
                                    <input type="file" name="excelFile" id="excelFileInput"
                                           accept=".xlsx,.csv" required>
                                </div>
                                <!-- File selected state -->
                                <div class="ims-file-chosen" id="fileChosen" style="display:none;">
                                    <i class="fa fa-file-excel-o"></i>
                                    <div class="ims-file-info">
                                        <span class="ims-file-name" id="chosenFileName"></span>
                                        <span class="ims-file-size" id="chosenFileSize"></span>
                                    </div>
                                    <button type="button" class="ims-file-remove" id="removeFileBtn" title="Remove file">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Action buttons -->
                            <div class="ims-actions">
                                <button type="submit" class="ims-btn ims-btn-primary" id="importBtn" disabled>
                                    <i class="fa fa-upload"></i> Import Staff
                                </button>
                                <a href="<?= base_url('staff/all_staff') ?>" class="ims-btn ims-btn-ghost">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Progress / Result overlay -->
            <div class="ims-progress-overlay" id="progressOverlay" style="display:none;">
                <div class="ims-progress-content">
                    <div class="ims-spinner" id="importSpinner"></div>
                    <h4 id="progressTitle">Importing staff records...</h4>
                    <p id="progressSubtext">Please wait, this may take a moment.</p>
                </div>
            </div>

            <!-- Result Card -->
            <div class="ims-result-card" id="resultCard" style="display:none;">
                <div class="ims-result-icon" id="resultIcon"></div>
                <h4 id="resultTitle"></h4>
                <div class="ims-result-stats" id="resultStats"></div>
                <div class="ims-result-skipped" id="resultSkipped" style="display:none;">
                    <h5><i class="fa fa-exclamation-triangle"></i> Skipped Rows</h5>
                    <ul id="skippedList"></ul>
                </div>
                <div class="ims-actions" style="margin-top:20px;">
                    <a href="<?= base_url('staff/all_staff') ?>" class="ims-btn ims-btn-primary">
                        <i class="fa fa-users"></i> View All Staff
                    </a>
                    <button type="button" class="ims-btn ims-btn-outline" onclick="resetImportForm()">
                        <i class="fa fa-refresh"></i> Import More
                    </button>
                </div>
            </div>
        </div>

        <!-- RIGHT: Info Card -->
        <div class="ims-card ims-card-info">
            <div class="ims-card-head">
                <i class="fa fa-info-circle"></i>
                <h3>Column Reference</h3>
            </div>
            <div class="ims-card-body">

                <div class="ims-col-group">
                    <h5><i class="fa fa-asterisk"></i> Required</h5>
                    <div class="ims-col-tag req">Name</div>
                    <div class="ims-col-tag req">Phone Number</div>
                    <div class="ims-col-tag req">DOB</div>
                    <div class="ims-col-tag req">Email</div>
                    <div class="ims-col-tag req">Gender</div>
                    <div class="ims-col-tag req">Father Name</div>
                    <div class="ims-col-tag req">Position</div>
                    <div class="ims-col-tag req">Date Of Joining</div>
                    <div class="ims-col-tag req">Employment Type</div>
                    <div class="ims-col-tag req">Department</div>
                    <div class="ims-col-tag req">Basic Salary</div>
                </div>

                <div class="ims-col-group">
                    <h5><i class="fa fa-user"></i> Personal</h5>
                    <div class="ims-col-tag">Blood Group</div>
                </div>

                <div class="ims-col-group">
                    <h5><i class="fa fa-graduation-cap"></i> Qualification</h5>
                    <div class="ims-col-tag">Qualification</div>
                    <div class="ims-col-tag">Experience</div>
                    <div class="ims-col-tag">University</div>
                    <div class="ims-col-tag">Year Of Passing</div>
                </div>

                <div class="ims-col-group">
                    <h5><i class="fa fa-money"></i> Salary</h5>
                    <div class="ims-col-tag">Allowances</div>
                </div>

                <div class="ims-col-group">
                    <h5><i class="fa fa-university"></i> Bank</h5>
                    <div class="ims-col-tag">Bank Name</div>
                    <div class="ims-col-tag">Account Holder Name</div>
                    <div class="ims-col-tag">Account Number</div>
                    <div class="ims-col-tag">IFSC Code</div>
                </div>

                <div class="ims-col-group">
                    <h5><i class="fa fa-phone"></i> Emergency</h5>
                    <div class="ims-col-tag">Emergency Contact Name</div>
                    <div class="ims-col-tag">Emergency Contact Number</div>
                </div>

                <div class="ims-col-group">
                    <h5><i class="fa fa-map-marker"></i> Address</h5>
                    <div class="ims-col-tag">Street</div>
                    <div class="ims-col-tag">City</div>
                    <div class="ims-col-tag">State</div>
                    <div class="ims-col-tag">Postal Code</div>
                </div>

                <div class="ims-info-box">
                    <h5><i class="fa fa-key"></i> Auto-Generated</h5>
                    <ul>
                        <li><strong>Staff ID</strong> — STA0001, STA0002, etc.</li>
                        <li><strong>Password</strong> — First3Name + Last3DOBYear + @<br>
                            <span class="ims-example">e.g. Name="Rajesh", DOB=1990 &rarr; <code>Raj990@</code></span>
                        </li>
                    </ul>
                </div>

                <div class="ims-info-box ims-info-note">
                    <h5><i class="fa fa-lightbulb-o"></i> Tips</h5>
                    <ul>
                        <li>Date format: <code>DD-MM-YYYY</code> or <code>YYYY-MM-DD</code></li>
                        <li>Phone: 10-digit Indian mobile (starts 6-9)</li>
                        <li>Gender dropdown: Male / Female / Other</li>
                        <li>Photo &amp; Aadhar — upload later via Edit Staff</li>
                        <li>Delete the sample row before importing</li>
                    </ul>
                </div>
            </div>
        </div>

    </div><!-- /.ims-grid -->

</div><!-- /.ims-wrap -->
</div><!-- /.content-wrapper -->

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- JS -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<script>
(function() {
    var dropZone      = document.getElementById('dropZone');
    var dropInner     = document.getElementById('dropZoneInner');
    var fileInput     = document.getElementById('excelFileInput');
    var fileChosen    = document.getElementById('fileChosen');
    var chosenName    = document.getElementById('chosenFileName');
    var chosenSize    = document.getElementById('chosenFileSize');
    var removeBtn     = document.getElementById('removeFileBtn');
    var importBtn     = document.getElementById('importBtn');
    var form          = document.getElementById('importStaffForm');
    var progressOvr   = document.getElementById('progressOverlay');
    var resultCard    = document.getElementById('resultCard');

    // Click to browse
    dropZone.addEventListener('click', function(e) {
        if (e.target.closest('.ims-file-remove')) return;
        if (fileChosen.style.display !== 'none') return;
        fileInput.click();
    });

    // Drag events
    ['dragenter', 'dragover'].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault();
            dropZone.classList.add('ims-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault();
            dropZone.classList.remove('ims-dragover');
        });
    });

    dropZone.addEventListener('drop', function(e) {
        var files = e.dataTransfer.files;
        if (files.length) {
            var ext = files[0].name.split('.').pop().toLowerCase();
            if (ext !== 'xlsx' && ext !== 'csv') {
                showToast('error', 'Only .xlsx or .csv files are allowed');
                return;
            }
            fileInput.files = files;
            showFileInfo(files[0]);
        }
    });

    fileInput.addEventListener('change', function() {
        if (this.files.length) showFileInfo(this.files[0]);
    });

    function showFileInfo(file) {
        chosenName.textContent = file.name;
        var sizeMB = (file.size / 1024 / 1024).toFixed(2);
        chosenSize.textContent = sizeMB + ' MB';
        dropInner.style.display = 'none';
        fileChosen.style.display = 'flex';
        importBtn.disabled = false;
    }

    removeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        fileInput.value = '';
        dropInner.style.display = '';
        fileChosen.style.display = 'none';
        importBtn.disabled = true;
    });

    // Form submit via AJAX
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!fileInput.files.length) {
            showToast('error', 'Please select a file first');
            return;
        }

        var formData = new FormData(form);
        importBtn.disabled = true;
        progressOvr.style.display = 'flex';
        resultCard.style.display  = 'none';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?= base_url("staff/import_staff") ?>', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            progressOvr.style.display = 'none';

            try {
                var resp = JSON.parse(xhr.responseText);
            } catch(err) {
                showToast('error', 'Unexpected server response');
                importBtn.disabled = false;
                return;
            }

            if (resp.status === 'success') {
                showResult(resp.data || resp);
            } else {
                showToast('error', resp.message || 'Import failed');
                importBtn.disabled = false;
            }
        };

        xhr.onerror = function() {
            progressOvr.style.display = 'none';
            showToast('error', 'Network error — please try again');
            importBtn.disabled = false;
        };

        xhr.send(formData);
    });

    function showResult(data) {
        var icon   = document.getElementById('resultIcon');
        var title  = document.getElementById('resultTitle');
        var stats  = document.getElementById('resultStats');
        var skipWr = document.getElementById('resultSkipped');
        var skipUl = document.getElementById('skippedList');

        var success = data.success || 0;
        var failed  = data.failed  || 0;

        if (success > 0 && failed === 0) {
            icon.innerHTML = '<i class="fa fa-check-circle" style="color:#0f766e;font-size:48px;"></i>';
            title.textContent = 'Import Successful!';
        } else if (success > 0) {
            icon.innerHTML = '<i class="fa fa-exclamation-circle" style="color:#d97706;font-size:48px;"></i>';
            title.textContent = 'Partially Imported';
        } else {
            icon.innerHTML = '<i class="fa fa-times-circle" style="color:#dc2626;font-size:48px;"></i>';
            title.textContent = 'Import Failed';
        }

        stats.innerHTML =
            '<div class="ims-stat"><span class="ims-stat-num ims-stat-success">' + success + '</span><span class="ims-stat-lbl">Imported</span></div>' +
            '<div class="ims-stat"><span class="ims-stat-num ims-stat-fail">' + failed + '</span><span class="ims-stat-lbl">Failed</span></div>';

        var skipped = data.skipped || [];
        if (skipped.length) {
            skipUl.innerHTML = '';
            skipped.forEach(function(msg) {
                var li = document.createElement('li');
                li.textContent = msg;
                skipUl.appendChild(li);
            });
            skipWr.style.display = '';
        } else {
            skipWr.style.display = 'none';
        }

        resultCard.style.display = 'block';
    }

    function showToast(type, msg) {
        var toast = document.createElement('div');
        toast.className = 'ims-toast ' + type;
        var icons = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle' };
        toast.innerHTML = '<i class="fa ' + (icons[type] || 'fa-info-circle') + '"></i> ' + msg;
        document.body.appendChild(toast);
        setTimeout(function() { toast.remove(); }, 3500);
    }

    window.resetImportForm = function() {
        fileInput.value = '';
        dropInner.style.display = '';
        fileChosen.style.display = 'none';
        importBtn.disabled = true;
        resultCard.style.display  = 'none';
        progressOvr.style.display = 'none';
    };
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- CSS -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<style>
:root {
    --ims-teal: var(--gold, #0f766e);
    --ims-teal2: var(--gold2, #0d6b63);
    --ims-teal-dim: var(--gold-dim, rgba(15,118,110,.10));
    --ims-navy: #0c1e38;
    --ims-bg: var(--bg, #f0f7f5);
    --ims-white: var(--bg2, #ffffff);
    --ims-border: var(--border, #e2e8f0);
    --ims-text: var(--t1, #1e293b);
    --ims-muted: var(--t3, #94a3b8);
    --ims-shadow: 0 1px 12px rgba(12,30,56,.07);
    --ims-radius: 12px;
}

.ims-wrap {
    font-family: 'Instrument Sans', sans-serif;
    background: var(--ims-bg);
    color: var(--ims-text);
    padding: 26px 22px 60px;
    min-height: 100vh;
}

/* Top bar */
.ims-topbar { margin-bottom: 28px; }
.ims-page-title {
    font-family: 'Lora', serif;
    font-size: 25px; font-weight: 700;
    color: var(--ims-navy);
    display: flex; align-items: center; gap: 10px;
    margin: 0 0 6px;
}
.ims-page-title i { color: var(--ims-teal); }
.ims-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--ims-muted);
    list-style: none; margin: 0; padding: 0;
}
.ims-breadcrumb a { color: var(--ims-teal); text-decoration: none; font-weight: 500; }
.ims-breadcrumb a:hover { text-decoration: underline; }
.ims-breadcrumb li::before { content: '/'; margin-right: 6px; color: #cbd5e1; }
.ims-breadcrumb li:first-child::before { display: none; }

/* Grid */
.ims-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 960px) {
    .ims-grid { grid-template-columns: 1fr; }
}

/* Cards */
.ims-card {
    background: var(--ims-white);
    border-radius: var(--ims-radius);
    box-shadow: var(--ims-shadow);
    overflow: hidden;
    position: relative;
}
.ims-card-head {
    display: flex; align-items: center; gap: 10px;
    padding: 18px 24px;
    border-bottom: 1px solid var(--ims-border);
    background: var(--ims-teal-dim);
}
.ims-card-head i { color: var(--ims-teal); font-size: 18px; }
.ims-card-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--ims-navy); }
.ims-card-body { padding: 24px; }

/* Steps */
.ims-step {
    display: flex; gap: 16px;
    margin-bottom: 28px;
    padding-bottom: 28px;
    border-bottom: 1px dashed var(--ims-border);
}
.ims-step:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.ims-step-num {
    width: 36px; height: 36px; min-width: 36px;
    border-radius: 50%;
    background: var(--ims-teal);
    color: #fff;
    font-weight: 700; font-size: 15px;
    display: flex; align-items: center; justify-content: center;
}
.ims-step-content h4 { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: var(--ims-navy); }
.ims-step-content p { margin: 0 0 12px; font-size: 13.5px; color: var(--ims-muted); line-height: 1.5; }

/* Buttons */
.ims-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px; font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all .2s;
    text-decoration: none;
}
.ims-btn-primary {
    background: var(--ims-teal); color: #fff;
}
.ims-btn-primary:hover { background: var(--ims-teal2); color: #fff; }
.ims-btn-primary:disabled { opacity: .45; cursor: not-allowed; }
.ims-btn-outline {
    background: transparent;
    border: 1.5px solid var(--ims-teal);
    color: var(--ims-teal);
}
.ims-btn-outline:hover { background: var(--ims-teal-dim); color: var(--ims-teal); }
.ims-btn-ghost {
    background: transparent; color: var(--ims-muted);
    border: 1px solid var(--ims-border);
}
.ims-btn-ghost:hover { background: var(--ims-teal-dim); color: var(--ims-teal); border-color: var(--ims-teal); }
.ims-actions { display: flex; gap: 12px; margin-top: 16px; }

/* Drop zone */
.ims-dropzone {
    border: 2px dashed var(--ims-border);
    border-radius: 10px;
    padding: 6px;
    cursor: pointer;
    transition: all .25s;
    position: relative;
}
.ims-dropzone:hover, .ims-dropzone.ims-dragover {
    border-color: var(--ims-teal);
    background: var(--ims-teal-dim);
}
.ims-dropzone-inner {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 36px 20px;
    text-align: center;
}
.ims-drop-icon { font-size: 40px; color: var(--ims-teal); margin-bottom: 10px; }
.ims-drop-text { font-size: 15px; font-weight: 600; color: var(--ims-navy); }
.ims-drop-sub { font-size: 13px; color: var(--ims-muted); margin-top: 4px; }
.ims-dropzone input[type="file"] { display: none; }

/* File chosen */
.ims-file-chosen {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 20px;
}
.ims-file-chosen > i { font-size: 32px; color: var(--ims-teal); }
.ims-file-info { display: flex; flex-direction: column; flex: 1; }
.ims-file-name { font-size: 14px; font-weight: 600; color: var(--ims-navy); }
.ims-file-size { font-size: 12px; color: var(--ims-muted); }
.ims-file-remove {
    width: 32px; height: 32px;
    border-radius: 50%;
    border: none;
    background: rgba(220,38,38,.08);
    color: #dc2626;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: background .2s;
}
.ims-file-remove:hover { background: rgba(220,38,38,.18); }

/* Progress overlay */
.ims-progress-overlay {
    position: absolute; inset: 0;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center;
    z-index: 10;
    border-radius: var(--ims-radius);
}
.ims-progress-content { text-align: center; }
.ims-progress-content h4 { margin: 16px 0 4px; font-size: 16px; color: var(--ims-navy); }
.ims-progress-content p { font-size: 13px; color: var(--ims-muted); margin: 0; }
.ims-spinner {
    width: 44px; height: 44px; margin: 0 auto;
    border: 4px solid var(--ims-border);
    border-top-color: var(--ims-teal);
    border-radius: 50%;
    animation: imsSpin .8s linear infinite;
}
@keyframes imsSpin { to { transform: rotate(360deg); } }

/* Result */
.ims-result-card {
    padding: 32px 24px;
    text-align: center;
}
.ims-result-card h4 { font-size: 18px; margin: 12px 0 20px; color: var(--ims-navy); }
.ims-result-stats { display: flex; justify-content: center; gap: 40px; margin-bottom: 16px; }
.ims-stat { display: flex; flex-direction: column; align-items: center; }
.ims-stat-num { font-size: 32px; font-weight: 800; line-height: 1; }
.ims-stat-success { color: #0f766e; }
.ims-stat-fail { color: #dc2626; }
.ims-stat-lbl { font-size: 13px; color: var(--ims-muted); margin-top: 4px; }
.ims-result-skipped {
    text-align: left;
    background: #fef3c7;
    border-radius: 8px;
    padding: 14px 18px;
    margin-top: 16px;
}
.ims-result-skipped h5 { margin: 0 0 8px; font-size: 13px; color: #92400e; }
.ims-result-skipped ul { margin: 0; padding-left: 18px; font-size: 13px; color: #78350f; }
.ims-result-skipped li { margin-bottom: 3px; }
.ims-result-card .ims-actions { justify-content: center; }

/* Info card */
.ims-card-info .ims-card-body { padding: 16px 20px; }
.ims-col-group { margin-bottom: 16px; }
.ims-col-group h5 {
    font-size: 12px; font-weight: 700;
    color: var(--ims-muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin: 0 0 8px;
    display: flex; align-items: center; gap: 6px;
}
.ims-col-group h5 i { font-size: 11px; }
.ims-col-tag {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    background: var(--ims-teal-dim);
    color: var(--ims-teal);
    margin: 0 4px 6px 0;
}
.ims-col-tag.req {
    background: var(--ims-teal);
    color: #fff;
    font-weight: 700;
}

/* Info boxes */
.ims-info-box {
    background: var(--ims-teal-dim);
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 12px;
}
.ims-info-box h5 {
    font-size: 13px; font-weight: 700;
    color: var(--ims-teal);
    margin: 0 0 8px;
    display: flex; align-items: center; gap: 6px;
}
.ims-info-box ul { margin: 0; padding-left: 16px; font-size: 12.5px; line-height: 1.7; }
.ims-info-box li { color: var(--ims-text); }
.ims-info-box code {
    background: rgba(15,118,110,.1);
    color: var(--ims-teal);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 12px;
}
.ims-example { font-size: 12px; color: var(--ims-muted); }
.ims-info-note { background: #fef9c3; }
.ims-info-note h5 { color: #92400e; }

/* Alert */
.ims-alert {
    padding: 12px 18px;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 20px;
    display: flex; align-items: center; gap: 10px;
}
.ims-alert-info { background: var(--ims-teal-dim); color: var(--ims-teal); }

/* Toast */
.ims-toast {
    position: fixed; bottom: 28px; right: 28px;
    padding: 14px 24px;
    border-radius: 10px;
    font-size: 14px; font-weight: 600;
    color: #fff;
    z-index: 9999;
    display: flex; align-items: center; gap: 8px;
    animation: imsToastIn .3s ease;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
}
.ims-toast.success { background: #0f766e; }
.ims-toast.error   { background: #dc2626; }
.ims-toast.warning { background: #d97706; }
@keyframes imsToastIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* Night mode support */
[data-theme="night"] .ims-wrap,
.night-mode .ims-wrap {
    --ims-navy: #e2e8f0;
    --ims-bg: var(--bg, #070f1c);
    --ims-white: var(--bg2, #0c1e38);
    --ims-border: var(--border, #1a3555);
    --ims-text: var(--t1, #e2e8f0);
    --ims-muted: var(--t3, #64748b);
}
[data-theme="night"] .ims-progress-overlay,
.night-mode .ims-progress-overlay {
    background: rgba(7,15,28,.92);
}
[data-theme="night"] .ims-result-skipped,
.night-mode .ims-result-skipped {
    background: #422006;
}
[data-theme="night"] .ims-info-note,
.night-mode .ims-info-note {
    background: #422006;
}
</style>
