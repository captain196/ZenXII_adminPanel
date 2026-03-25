<div class="content-wrapper">
    <section class="content">
        <div class="container-fluid">

<?php if ($this->session->flashdata('import_result')): ?>
    <?php
        $flashMsg = $this->session->flashdata('import_result');
        $isError  = stripos($flashMsg, 'fail') !== false || stripos($flashMsg, 'error') !== false;
    ?>
    <div class="alert <?= $isError ? 'alert-danger' : 'alert-success' ?>" role="alert">
        <?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fa fa-upload"></i> Bulk Student Import
                    </h3>
                </div>

                <div class="card-body">

                    <form action="<?= base_url('student/import_students') ?>"
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
                            <strong>Instructions:</strong>
                            <ul>
                                <li>File must follow the exact header format given below.</li>
                                <li><b>Required columns:</b> Name, Class, Section</li>
                                <li>All other columns are optional — leave blank if not available.</li>
                                <li>Class format: <b>8</b> or <b>Class 8</b></li>
                                <li>Section: <b>A / B / C</b></li>
                                <li>DOB format: <b>30-06-2018</b> or <b>2018-06-30</b></li>
                                <li>Photo & Documents can be uploaded later via Edit Student.</li>
                            </ul>
                        </div>

                        <div class="alert alert-secondary mt-2">
                            <strong>Excel Headers (in order):</strong><br>
                            <code>Name | Class | Section | DOB | Admission Date | Gender | Blood Group | Category | Religion | Nationality | Father Name | Father Occupation | Mother Name | Mother Occupation | Guard Contact | Guard Relation | Phone Number | Email | Street | City | State | PostalCode | Pre School | Pre Class | Pre Marks</code>
                        </div>

                        <button type="submit" class="btn btn-success" id="importBtn">
                            <i class="fa fa-check"></i> Upload & Import
                        </button>

                        <a href="<?= base_url('student/all_student') ?>"
                            class="btn btn-secondary">
                            Cancel
                        </a>

                    </form>

                </div>
            </div>

        </div>
    </section>
</div>
<script>
document.querySelector('form').addEventListener('submit', function(e) {
    var fileInput = this.querySelector('input[name="excelFile"]');
    if (fileInput.files.length && fileInput.files[0].size > 5 * 1024 * 1024) {
        e.preventDefault();
        alert('File size exceeds 5 MB limit. Please use a smaller file.');
        return;
    }
    var btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Importing…';
});
</script>