<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student Documents — Canonical Document Service consumer (Phase 2).
 *
 * Renders service-managed documents from the student's `documentRefs[]`
 * (preview / download / verify / replace / delete + status badge + version),
 * plus any pre-existing legacy `Doc[]` entries (view / download only) for full
 * backward compatibility. Uploads go through the Document Service; the Document
 * Service itself is unchanged.
 */

$userId = $student['User Id'] ?? '';
$name   = $student['Name'] ?? 'Student';

// Service-managed documents (authoritative for lifecycle).
$refs = is_array($student['documentRefs'] ?? null) ? $student['documentRefs'] : [];
$managedIds = [];
foreach ($refs as $r) { if (is_array($r) && !empty($r['documentId'])) $managedIds[(string) $r['documentId']] = true; }

// Legacy documents = Doc[] entries not managed by the service (no matching documentId).
$legacy   = [];
$skipKeys = ['Photo', 'PhotoUrl'];
$docMap   = $student['Doc'] ?? $student['documents'] ?? [];
if (is_array($docMap)) {
    foreach ($docMap as $label => $entry) {
        if (in_array($label, $skipKeys, true)) continue;
        $did = is_array($entry) ? (string) ($entry['documentId'] ?? '') : '';
        if ($did !== '' && isset($managedIds[$did])) continue; // shown via documentRefs
        $url = is_array($entry) ? ($entry['url'] ?? '') : (string) $entry;
        if ($url === '') continue;
        $legacy[$label] = [
            'url'         => $url,
            'thumbnail'   => is_array($entry) ? ($entry['thumbnail'] ?? '') : '',
            'uploaded_at' => is_array($entry) ? ($entry['uploaded_at'] ?? '') : '',
        ];
    }
}

// Predefined types render in a fixed order; anything else falls under Additional.
$typeOrder = ['aadhaar', 'birth_certificate', 'transfer_certificate', 'marksheet', 'medical_certificate'];
$predefined = []; $additional = [];
foreach ($refs as $r) {
    if (!is_array($r) || empty($r['documentId'])) continue;
    if (($r['category'] ?? '') === 'additional' || ($r['type'] ?? '') === 'additional') $additional[] = $r;
    else $predefined[] = $r;
}
usort($predefined, function ($a, $b) use ($typeOrder) {
    return array_search($a['type'] ?? '', $typeOrder) <=> array_search($b['type'] ?? '', $typeOrder);
});
?>

<style>
html { font-size: 16px !important; }
.doc-wrap { max-width:1000px; margin:0 auto; padding:24px 20px; }
.page-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:10px; }
.page-hdr h1 { margin:0; font-size:1.2rem; color:var(--t1); font-family:var(--font-b); }
.upload-panel { background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:18px 20px; margin-bottom:22px; }
.upload-panel h3 { margin:0 0 12px; font-size:1rem; color:var(--t1); font-family:var(--font-b); }
.upload-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.fg { display:flex; flex-direction:column; gap:5px; }
.fg label { font-size:.82rem; color:var(--t3); }
.fg input, .fg select { padding:8px 10px; border:1px solid var(--border); border-radius:6px; background:var(--bg3); color:var(--t1); font-size:.9rem; }
.btn-upload { padding:9px 18px; background:var(--gold); color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:.9rem; }
.btn-upload:hover { background:var(--gold2,#b98900); }
.sec-title { font-size:.82rem; text-transform:uppercase; letter-spacing:.04em; color:var(--t3); font-weight:700; margin:22px 0 10px; }
.docs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
.doc-card { background:var(--bg2); border:1px solid var(--border); border-radius:10px; overflow:hidden; display:flex; flex-direction:column; }
.doc-thumb { height:130px; background:var(--bg3); display:flex; align-items:center; justify-content:center; overflow:hidden; }
.doc-thumb img { width:100%; height:100%; object-fit:cover; }
.doc-thumb i { font-size:3rem; color:var(--t3); }
.doc-info { padding:11px; display:flex; flex-direction:column; gap:4px; flex:1; }
.doc-label { font-size:.92rem; font-weight:600; color:var(--t1); word-break:break-word; }
.doc-meta { font-size:.78rem; color:var(--t3); }
.badge { display:inline-flex; align-items:center; gap:4px; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:20px; width:fit-content; }
.badge.verified { background:#dcfce7; color:#166534; }
.badge.rejected { background:#fee2e2; color:#991b1b; }
.badge.pending  { background:#fef3c7; color:#92400e; }
.badge.legacy   { background:var(--bg3); color:var(--t3); border:1px solid var(--border); }
.doc-actions { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.doc-btn { padding:5px 9px; border-radius:6px; border:1px solid var(--border); background:var(--bg3); color:var(--t2); font-size:.8rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.doc-btn:hover { background:var(--gold-dim,#fff7e6); color:var(--gold); }
.doc-btn.green:hover { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
.doc-btn.red:hover { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
.empty-state { text-align:center; padding:44px; color:var(--t3); background:var(--bg2); border:1px solid var(--border); border-radius:10px; }
.empty-state i { font-size:2.6rem; display:block; margin-bottom:10px; }
.alert { padding:10px 14px; border-radius:6px; font-size:.85rem; display:none; margin-bottom:14px; }
.alert-success { background:#dcfce7; color:#166534; }
.alert-error   { background:#fee2e2; color:#991b1b; }
.audit-box { background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:14px 18px; margin-top:8px; max-height:320px; overflow:auto; display:none; }
.audit-item { font-size:.84rem; color:var(--t2); padding:6px 0; border-bottom:1px solid var(--border); }
.audit-item:last-child { border-bottom:none; }
.audit-item .ts { color:var(--t3); font-size:.78rem; }
</style>

<div class="content-wrapper">
<div class="doc-wrap">

    <div style="margin-bottom:12px;">
        <a href="<?= base_url('sis/profile/' . urlencode($userId)) ?>" style="color:var(--t3);font-size:.85rem;text-decoration:none;"><i class="fa fa-arrow-left"></i> Back to Profile</a>
    </div>

    <div class="page-hdr">
        <h1><i class="fa fa-folder-open-o" style="color:var(--gold);margin-right:8px;"></i>Documents — <?= htmlspecialchars($name) ?></h1>
        <button class="doc-btn" onclick="toggleAudit()"><i class="fa fa-history"></i> Document History</button>
    </div>
    <div id="auditBox" class="audit-box"></div>

    <!-- Upload -->
    <div class="upload-panel">
        <h3><i class="fa fa-upload" style="color:var(--gold);margin-right:6px;"></i>Add / Update Document</h3>
        <div id="uploadAlert" class="alert"></div>
        <form id="uploadForm">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
            <div class="upload-row">
                <div class="fg">
                    <label>Document type *</label>
                    <select name="doc_type" id="docType" style="min-width:200px;" onchange="onTypeChange()">
                        <option value="aadhaar">Aadhaar Card</option>
                        <option value="birth_certificate">Birth Certificate</option>
                        <option value="transfer_certificate">Transfer Certificate</option>
                        <option value="marksheet">Marksheet</option>
                        <option value="medical_certificate">Medical Certificate</option>
                        <option value="additional">Other document…</option>
                    </select>
                </div>
                <div class="fg" id="customLabelWrap" style="display:none;">
                    <label>Document name *</label>
                    <input type="text" name="doc_label" id="docLabel" placeholder="e.g. Caste Certificate" style="min-width:200px;">
                </div>
                <div class="fg">
                    <label>File *</label>
                    <input type="file" name="document" id="docFile" accept="image/jpeg,image/png,application/pdf">
                </div>
                <button type="submit" class="btn-upload"><i class="fa fa-upload"></i> Upload</button>
            </div>
            <div class="doc-meta" style="margin-top:8px;">Accepted: JPG, PNG, PDF — up to 5&nbsp;MB. Re-uploading a predefined type replaces it (new version).</div>
        </form>
    </div>

    <input type="file" id="replaceInput" style="display:none" accept="image/jpeg,image/png,application/pdf" onchange="onReplaceChosen(this)">

    <?php if (empty($predefined) && empty($additional) && empty($legacy)): ?>
    <div class="empty-state"><i class="fa fa-folder-open-o"></i>No documents yet. Use the form above to add one.</div>
    <?php endif; ?>

    <?php if (!empty($predefined) || !empty($additional)): ?>
        <?php if (!empty($predefined)): ?>
        <div class="sec-title">Documents</div>
        <div class="docs-grid">
            <?php foreach ($predefined as $r): echo docCard($r, $userId); endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($additional)): ?>
        <div class="sec-title">Additional Documents</div>
        <div class="docs-grid">
            <?php foreach ($additional as $r): echo docCard($r, $userId); endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($legacy)): ?>
    <div class="sec-title">Legacy Documents <span style="font-weight:400;text-transform:none;">(uploaded before the Document Service — view/download; re-upload above to manage)</span></div>
    <div class="docs-grid">
        <?php foreach ($legacy as $label => $doc): ?>
        <div class="doc-card">
            <div class="doc-thumb">
                <?php if ($doc['thumbnail'] || preg_match('/\.(jpg|jpeg|png|gif|webp)(\?|$)/i', $doc['url'])): ?>
                    <img src="<?= htmlspecialchars($doc['thumbnail'] ?: $doc['url']) ?>" onerror="this.parentElement.innerHTML='<i class=\'fa fa-file-o\'></i>'" alt="<?= htmlspecialchars($label) ?>">
                <?php elseif (preg_match('/\.pdf(\?|$)/i', $doc['url'])): ?>
                    <i class="fa fa-file-pdf-o" style="color:#ef4444;"></i>
                <?php else: ?>
                    <i class="fa fa-file-o"></i>
                <?php endif; ?>
            </div>
            <div class="doc-info">
                <div class="doc-label"><?= htmlspecialchars($label) ?></div>
                <span class="badge legacy"><i class="fa fa-clock-o"></i> Legacy</span>
                <?php if ($doc['uploaded_at']): ?><div class="doc-meta"><?= htmlspecialchars(substr($doc['uploaded_at'], 0, 10)) ?></div><?php endif; ?>
                <div class="doc-actions">
                    <a href="<?= htmlspecialchars($doc['url']) ?>" target="_blank" rel="noopener" class="doc-btn"><i class="fa fa-external-link"></i> View</a>
                    <button class="doc-btn red" data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" onclick="deleteLegacy(this)"><i class="fa fa-trash"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
</div>

<?php
/** Render one service-managed document card. */
function docCard(array $r, string $userId): string
{
    $esc  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $url  = (string) ($r['url'] ?? '');
    $lbl  = (string) ($r['label'] ?? ($r['type'] ?? 'Document'));
    $did  = (string) ($r['documentId'] ?? '');
    $st   = (string) ($r['verificationStatus'] ?? 'pending');
    $ver  = (int) ($r['version'] ?? 1);
    $isImg = (bool) preg_match('/\.(jpg|jpeg|png|gif|webp)(\?|$)/i', $url);
    $badge = in_array($st, ['verified', 'rejected'], true) ? $st : 'pending';
    $badgeI = $st === 'verified' ? 'fa-check-circle' : ($st === 'rejected' ? 'fa-times-circle' : 'fa-clock-o');

    $thumb = $isImg
        ? '<img src="' . $esc($url) . '" onerror="this.parentElement.innerHTML=\'<i class=\\\'fa fa-file-o\\\'></i>\'" alt="' . $esc($lbl) . '">'
        : (preg_match('/\.pdf(\?|$)/i', $url) ? '<i class="fa fa-file-pdf-o" style="color:#ef4444;"></i>' : '<i class="fa fa-file-o"></i>');

    $h  = '<div class="doc-card"><div class="doc-thumb">' . $thumb . '</div><div class="doc-info">';
    $h .= '<div class="doc-label">' . $esc($lbl) . '</div>';
    $h .= '<span class="badge ' . $badge . '"><i class="fa ' . $badgeI . '"></i> ' . ucfirst($st) . '</span>';
    if ($ver > 1) $h .= '<div class="doc-meta">Version ' . $ver . '</div>';
    $h .= '<div class="doc-actions">';
    $h .= '<a href="' . $esc($url) . '" target="_blank" rel="noopener" class="doc-btn"><i class="fa fa-download"></i></a>';
    $h .= '<button class="doc-btn green" title="Mark verified" onclick="verifyDoc(this,\'' . $esc($did) . '\',\'verified\')"><i class="fa fa-check"></i></button>';
    $h .= '<button class="doc-btn red" title="Mark rejected" onclick="verifyDoc(this,\'' . $esc($did) . '\',\'rejected\')"><i class="fa fa-times"></i></button>';
    $h .= '<button class="doc-btn" title="Replace" onclick="replaceDoc(this,\'' . $esc($did) . '\')"><i class="fa fa-refresh"></i></button>';
    $h .= '<button class="doc-btn red" title="Delete" onclick="deleteDoc(this,\'' . $esc($did) . '\')"><i class="fa fa-trash"></i></button>';
    $h .= '</div></div></div>';
    return $h;
}
?>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var STU_ID    = <?= json_encode($userId) ?>;
var BASE      = '<?= base_url() ?>';

function onTypeChange() {
    document.getElementById('customLabelWrap').style.display =
        document.getElementById('docType').value === 'additional' ? 'flex' : 'none';
}
function showAlert(msg, ok) {
    var el = document.getElementById('uploadAlert');
    el.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
    el.textContent = msg; el.style.display = 'block';
}
function post(url, fd, cb) {
    fd.append(csrfName, csrfToken);
    var isForm = (fd instanceof FormData);
    fetch(BASE + url, {
        method: 'POST',
        headers: isForm ? { 'X-Requested-With': 'XMLHttpRequest' }
                        : { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: isForm ? fd : fd.toString()
    }).then(function (r) { return r.json(); }).then(cb).catch(function () { cb({ status: 'error', message: 'Network error. Please retry.' }); });
}

document.getElementById('uploadForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (document.getElementById('docType').value === 'additional' && !document.getElementById('docLabel').value.trim()) {
        showAlert('Please enter a name for the document.', false); return;
    }
    if (!document.getElementById('docFile').files.length) { showAlert('Please choose a file.', false); return; }
    var btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading…';
    post('sis/student_upload_document', new FormData(this), function (d) {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-upload"></i> Upload';
        showAlert(d.message || (d.status === 'success' ? 'Uploaded.' : 'Upload failed.'), d.status === 'success');
        if (d.status === 'success') setTimeout(function () { location.reload(); }, 900);
    });
});

// Floating confirmation toast — visible no matter where the page is scrolled.
function toast(msg, ok) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:9999;padding:11px 20px;'
        + 'border-radius:8px;font-size:.9rem;font-weight:600;color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.18);'
        + 'background:' + (ok ? '#16a34a' : '#dc2626');
    document.body.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }, 1600);
}
// Show a spinner on the clicked button; returns a restore() to undo it on error.
function busy(btn) {
    if (!btn) return function () {};
    var html = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    return function () { btn.disabled = false; btn.innerHTML = html; };
}

function verifyDoc(btn, docId, status) {
    if (status === 'rejected' && !confirm('Mark this document as rejected?')) return;
    var restore = busy(btn);
    post('sis/verify_student_document', new URLSearchParams({ user_id: STU_ID, document_id: docId, status: status }), function (d) {
        if (d.status === 'success') {
            toast(status === 'verified' ? 'Document verified' : 'Document rejected', true);
            setTimeout(function () { location.reload(); }, 800);
        } else { restore(); toast(d.message || 'Could not update the document.', false); }
    });
}
var _replaceTarget = null, _replaceBtn = null;
function replaceDoc(btn, docId) { _replaceTarget = docId; _replaceBtn = btn; var i = document.getElementById('replaceInput'); i.value = ''; i.click(); }
function onReplaceChosen(inp) {
    if (!_replaceTarget || !inp.files.length) return;
    var restore = busy(_replaceBtn);
    var fd = new FormData(); fd.append('user_id', STU_ID); fd.append('document_id', _replaceTarget); fd.append('document', inp.files[0]);
    post('sis/replace_student_document', fd, function (d) {
        if (d.status === 'success') { toast('Document replaced (new version)', true); setTimeout(function () { location.reload(); }, 800); }
        else { restore(); toast(d.message || 'Could not replace the document.', false); }
    });
}
function deleteDoc(btn, docId) {
    if (!confirm('Delete this document? The file is permanently removed only if nothing else references it and the retention window has passed.')) return;
    var restore = busy(btn);
    post('sis/delete_student_document', new URLSearchParams({ user_id: STU_ID, document_id: docId }), function (d) {
        if (d.status === 'success') { toast('Document deleted', true); setTimeout(function () { location.reload(); }, 700); }
        else { restore(); toast(d.message || 'Could not delete the document.', false); }
    });
}
function deleteLegacy(btn) {
    var label = btn.dataset.label;
    if (!confirm('Delete legacy document "' + label + '"? This cannot be undone.')) return;
    var restore = busy(btn);
    post('sis/delete_document', new URLSearchParams({ user_id: STU_ID, doc_label: label }), function (d) {
        if (d.status === 'success') { toast('Document deleted', true); setTimeout(function () { location.reload(); }, 700); }
        else { restore(); toast(d.message || 'Could not delete.', false); }
    });
}

var _auditLoaded = false;
function toggleAudit() {
    var box = document.getElementById('auditBox');
    if (box.style.display === 'block') { box.style.display = 'none'; return; }
    box.style.display = 'block';
    if (_auditLoaded) return;
    box.innerHTML = '<div class="audit-item"><i class="fa fa-spinner fa-spin"></i> Loading document history…</div>';
    fetch(BASE + 'sis/student_document_audit?user_id=' + encodeURIComponent(STU_ID), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            _auditLoaded = true;
            if (d.status !== 'success' || !d.trail || !d.trail.length) { box.innerHTML = '<div class="audit-item">No document history yet.</div>'; return; }
            box.innerHTML = d.trail.map(function (e) {
                var act = (e.action || '').replace('DOCUMENT_', '').toLowerCase();
                return '<div class="audit-item"><strong>' + act + '</strong> — ' + (e.description || '') +
                       ' <span class="ts">· ' + (e.changed_by || '') + ' · ' + (e.changed_at || '') + '</span></div>';
            }).join('');
        }).catch(function () { box.innerHTML = '<div class="audit-item">Could not load history.</div>'; });
}
</script>
