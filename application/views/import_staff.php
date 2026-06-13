<div class="content-wrapper">
    <section class="content">
        <div class="container-fluid">

<?php if ($this->session->flashdata('import_result')): ?>
    <?php
        $flashMsg = $this->session->flashdata('import_result');
        $isError  = stripos($flashMsg, 'fail') !== false || stripos($flashMsg, 'error') !== false
                 || stripos($flashMsg, 'not ') !== false || stripos($flashMsg, 'unsupported') !== false;
    ?>
    <div class="alert <?= $isError ? 'alert-danger' : 'alert-success' ?>" role="alert">
        <?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fa fa-upload"></i> Bulk Staff Import
                    </h3>
                </div>

                <div class="card-body">

                    <form action="<?= base_url('staff/preview_import') ?>"
                        method="post"
                        enctype="multipart/form-data">
                        <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
                            value="<?= $this->security->get_csrf_hash() ?>">
                        <div class="form-group">
                            <label>Select Excel File (.xlsx / .csv)</label>
                            <input type="file"
                                name="excelFile"
                                class="form-control"
                                accept=".xlsx,.csv"
                                required>
                        </div>

                        <div class="alert alert-info mt-3">
                            <strong>How it works:</strong>
                            <ul>
                                <li><b>Any column layout is accepted</b> — after upload you'll map your file's columns to the right fields (we pre-guess them for you), then preview before anything is saved.</li>
                                <li><b>Only Name and Phone Number are required.</b> Everything else (DOB, Email, Gender, Father Name, Role, Employment Type, Joining Date, Salary…) is optional — leave blank and fill later via Edit Staff.</li>
                                <li>Rows missing optional data still import. A <b>present but malformed</b> value (bad email/phone/date) imports with an <b>amber warning</b> — only a missing Name or Phone blocks a row.</li>
                                <li>We auto-clean common formats: dates (<b>15-06-1985</b>, <b>1985-06-15</b>, <b>04/12/2019</b>), phone (drops <b>+91</b>/spaces), PAN/IFSC (uppercased).</li>
                                <li><b>Role</b> is read from the Position/Role column (Teacher, Accountant, Clerk…). If it can't be recognized, the staff imports with <b>no role</b> (assign later) — never silently made a Teacher.</li>
                                <li>For a teacher with no Subjects column, the <b>Department</b> value is treated as the subject; set the real department later from Edit Staff.</li>
                                <li>Rows with problems are flagged in the preview — fix them inline or import just the valid rows.</li>
                                <li><b>Safe to re-run:</b> staff already in the system (matched by phone) are skipped automatically — you won't get duplicates.</li>
                                <li>Photo &amp; Documents can be uploaded later via Edit Staff.</li>
                            </ul>
                            <a href="<?= base_url('staff/download_staff_template') ?>" class="btn btn-sm btn-outline-primary mt-1">
                                <i class="fa fa-download"></i> Download standard template (.xlsx)
                            </a>
                        </div>

                        <button type="submit" class="btn btn-success" id="importBtn">
                            <i class="fa fa-arrow-right"></i> Upload &amp; Map Columns
                        </button>

                        <a href="<?= base_url('staff/all_staff') ?>" class="btn btn-secondary">
                            Cancel
                        </a>

                    </form>

                </div>
            </div>

        </div>
    </section>
</div>
<script>
document.querySelector('form').addEventListener('submit', function (e) {
    var fileInput = this.querySelector('input[name="excelFile"]');
    if (fileInput.files.length && fileInput.files[0].size > 5 * 1024 * 1024) {
        e.preventDefault();
        alert('File size exceeds 5 MB limit. Please use a smaller file.');
        return;
    }
    var btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading…';
});
</script>
