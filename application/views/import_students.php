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

                    <form action="<?= base_url('sis/import_preview') ?>"
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
                                <li><b>Required fields:</b> Name, Class, Section. Everything else is optional.</li>
                                <li>We auto-clean common formats: dates (<b>30-06-2018</b>, <b>2018-06-30</b>, <b>04/12/2019</b>), class (<b>8</b>, <b>Class 8</b>, <b>VIII</b>, <b>Eighth</b>), section (<b>A</b> / <b>Sec A</b>), phone (drops <b>+91</b>), gender, blood group.</li>
                                <li>Rows with problems are flagged in the preview — you can fix them inline or import just the valid rows.</li>
                                <li><b>Safe to re-run:</b> students already in the system (matched by phone) can be skipped or have their details updated — you won't get duplicates.</li>
                                <li>No fee chart yet? Students still import — set up <b>Fees &rarr; Chart</b> afterwards and fee demands are assigned automatically.</li>
                                <li>Photo & Documents can be uploaded later via Edit Student.</li>
                            </ul>
                            <a href="<?= base_url('sis/import_template') ?>" class="btn btn-sm btn-outline-primary mt-1">
                                <i class="fa fa-download"></i> Download standard template (CSV)
                            </a>
                        </div>

                        <button type="submit" class="btn btn-success" id="importBtn">
                            <i class="fa fa-arrow-right"></i> Upload &amp; Map Columns
                        </button>

                        <a href="<?= base_url('sis/all_student') ?>"
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