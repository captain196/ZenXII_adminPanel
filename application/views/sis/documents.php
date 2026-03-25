<?php defined('BASEPATH') or exit('No direct script access allowed');

$userId = $student['User Id'] ?? '';
$name   = $student['Name'] ?? 'Student';

$skipKeys   = ['Photo', 'PhotoUrl'];
$docDisplay = [];
if (!empty($student['Doc']) && is_array($student['Doc'])) {
    foreach ($student['Doc'] as $label => $entry) {
        if (in_array($label, $skipKeys, true)) continue;
        $url   = is_array($entry) ? ($entry['url'] ?? '') : (string)$entry;
        $thumb = is_array($entry) ? ($entry['thumbnail'] ?? '') : '';
        $upAt  = is_array($entry) ? ($entry['uploaded_at'] ?? '') : '';
        if ($url !== '') $docDisplay[$label] = ['url' => $url, 'thumbnail' => $thumb, 'uploaded_at' => $upAt];
    }
}
?>

<style>
html { font-size: 16px !important; }
.doc-wrap { max-width:900px; margin:0 auto; padding:24px 20px; }
.page-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
.page-hdr h1 { margin:0; font-size:1.2rem; color:var(--t1); font-family:var(--font-b); }

.upload-panel { background:var(--bg2); border:1px solid var(--border); border-radius:10px;
    padding:20px; margin-bottom:20px; }
.upload-panel h3 { margin:0 0 14px; font-size:1rem; color:var(--t1); font-family:var(--font-b); }

.upload-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.fg { display:flex; flex-direction:column; gap:5px; }
.fg label { font-size:.86rem; color:var(--t3); }
.fg input, .fg select { padding:8px 10px; border:1px solid var(--border); border-radius:6px;
    background:var(--bg3); color:var(--t1); font-size:.9rem; }
.btn-upload { padding:9px 18px; background:var(--gold); color:#fff; border:none;
    border-radius:6px; cursor:pointer; font-size:.9rem; }
.btn-upload:hover { background:var(--gold2); }

.docs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; }
.doc-card { background:var(--bg2); border:1px solid var(--border); border-radius:8px; overflow:hidden; }
.doc-thumb { height:130px; background:var(--bg3); display:flex; align-items:center;
    justify-content:center; overflow:hidden; }
.doc-thumb img { width:100%; height:100%; object-fit:cover; }
.doc-thumb i { font-size:3rem; color:var(--t3); }
.doc-info { padding:10px; }
.doc-label { font-size:.9rem; font-weight:600; color:var(--t1); word-break:break-word; }
.doc-date  { font-size:.82rem; color:var(--t3); margin-top:2px; }
.doc-actions { display:flex; gap:8px; margin-top:8px; }
.doc-btn { padding:6px 14px; border-radius:6px; border:1px solid var(--border);
    background:var(--bg3); color:var(--t2); font-size:.84rem; cursor:pointer; text-decoration:none; }
.doc-btn:hover { background:var(--gold-dim); color:var(--gold); }
.doc-btn.red:hover { background:#fee2e2; color:#991b1b; border-color:#fecaca; }

.empty-state { text-align:center; padding:50px; color:var(--t3); background:var(--bg2);
    border:1px solid var(--border); border-radius:10px; }
.empty-state i { font-size:3rem; display:block; margin-bottom:10px; }

.alert { padding:10px 14px; border-radius:6px; font-size:.85rem; display:none; margin-bottom:14px; }
.alert-success { background:#dcfce7; color:#166534; }
.alert-error   { background:#fee2e2; color:#991b1b; }
</style>

<div class="content-wrapper">
<div class="doc-wrap">

    <div style="margin-bottom:14px;">
        <a href="<?= base_url('sis/profile/' . urlencode($userId)) ?>" style="color:var(--t3);font-size:.85rem;text-decoration:none;">
            <i class="fa fa-arrow-left"></i> Back to Profile
        </a>
    </div>

    <div class="page-hdr">
        <h1><i class="fa fa-folder-open-o" style="color:var(--gold);margin-right:8px;"></i>
            Documents — <?= htmlspecialchars($name) ?>
        </h1>
    </div>

    <!-- Upload -->
    <div class="upload-panel">
        <h3><i class="fa fa-upload" style="color:var(--gold);margin-right:6px;"></i>Upload Document</h3>
        <div id="uploadAlert" class="alert"></div>
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
            <div class="upload-row">
                <div class="fg">
                    <label>Document Label *</label>
                    <input type="text" name="doc_label" placeholder="e.g. Birth Certificate, Aadhar, Marksheet" style="min-width:200px;">
                </div>
                <div class="fg">
                    <label>File *</label>
                    <input type="file" name="document" accept="image/*,.pdf">
                </div>
                <button type="submit" class="btn-upload"><i class="fa fa-upload"></i> Upload</button>
            </div>
        </form>
    </div>

    <!-- Documents Grid -->
    <?php if (empty($docDisplay)): ?>
    <div class="empty-state">
        <i class="fa fa-folder-open-o"></i>
        No documents uploaded yet. Use the form above to upload.
    </div>
    <?php else: ?>
    <div class="docs-grid">
        <?php foreach ($docDisplay as $label => $doc): ?>
        <div class="doc-card">
            <div class="doc-thumb">
                <?php if ($doc['thumbnail'] || strpos($doc['url'], 'image') !== false || preg_match('/\.(jpg|jpeg|png|gif|webp)(\?|$)/i', $doc['url'])): ?>
                <img src="<?= htmlspecialchars($doc['thumbnail'] ?: $doc['url']) ?>"
                    onerror="this.parentElement.innerHTML='<i class=\'fa fa-file-o\'></i>'"
                    alt="<?= htmlspecialchars($label) ?>">
                <?php elseif (preg_match('/\.pdf(\?|$)/i', $doc['url'])): ?>
                <i class="fa fa-file-pdf-o" style="color:#ef4444;"></i>
                <?php else: ?>
                <i class="fa fa-file-o"></i>
                <?php endif; ?>
            </div>
            <div class="doc-info">
                <div class="doc-label"><?= htmlspecialchars($label) ?></div>
                <?php if ($doc['uploaded_at']): ?>
                <div class="doc-date"><?= htmlspecialchars(substr($doc['uploaded_at'], 0, 10)) ?></div>
                <?php endif; ?>
                <div class="doc-actions">
                    <a href="<?= htmlspecialchars($doc['url']) ?>" target="_blank" class="doc-btn">
                        <i class="fa fa-external-link"></i> View
                    </a>
                    <button class="doc-btn red" data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" onclick="deleteDoc(this)">
                        <i class="fa fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
</div>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

document.getElementById('uploadForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn   = this.querySelector('button[type=submit]');
    var alertEl = document.getElementById('uploadAlert');
    btn.disabled = true; btn.textContent = 'Uploading...';
    alertEl.style.display = 'none';

    var fd = new FormData(this);
    fd.append(csrfName, csrfToken);
    fetch('<?= base_url('sis/upload_document') ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-upload"></i> Upload';
        alertEl.className = 'alert ' + (data.status === 'success' ? 'alert-success' : 'alert-error');
        alertEl.textContent = data.message;
        alertEl.style.display = 'block';
        if (data.status === 'success') setTimeout(function() { location.reload(); }, 1200);
    })
    .catch(function() {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-upload"></i> Upload';
        alertEl.className = 'alert alert-error';
        alertEl.textContent = 'Upload failed.';
        alertEl.style.display = 'block';
    });
});

function deleteDoc(btn) {
    var label = btn.dataset.label;
    if (!confirm('Delete document "' + label + '"? This cannot be undone.')) return;
    var body = new URLSearchParams({
        user_id: '<?= htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') ?>',
        doc_label: label,
    });
    body.append(csrfName, csrfToken);
    fetch('<?= base_url('sis/delete_document') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        alert(data.message);
        if (data.status === 'success') location.reload();
    });
}
</script>
