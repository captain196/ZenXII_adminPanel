<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
.imp-wrap { width:100%; max-width:none; margin:0; padding:24px 28px; box-sizing:border-box; }
.imp-hdr { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid var(--border,#e5e7eb); }
.imp-hdr h1 { margin:0; font-size:1.35rem; color:var(--t1,#111827); }
.imp-hdr .sub { font-size:.84rem; color:var(--t3,#6b7280); margin-top:3px; }
.imp-card { background:var(--bg2,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px;
    padding:18px 20px; margin-bottom:18px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
.imp-card h2 { font-size:1rem; margin:0 0 4px; color:var(--t1,#111827); }
.imp-card .hint { font-size:.82rem; color:var(--t3,#6b7280); margin:0 0 14px; }
.req-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.req-chip { font-size:.78rem; padding:4px 11px; border-radius:20px; font-weight:600; display:inline-flex; align-items:center; gap:6px; }
.req-chip.ok  { background:#dcfce7; color:#166534; }
.req-chip.bad { background:#fee2e2; color:#991b1b; }
.map-table, .prev-table { width:100%; border-collapse:collapse; font-size:.86rem; }
.map-table th, .prev-table th { background:var(--bg3,#f9fafb); color:var(--t2,#374151); text-align:left;
    padding:9px 12px; border-bottom:1px solid var(--border,#e5e7eb); font-size:.74rem; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; position:sticky; top:0; z-index:1; }
.map-table td { padding:8px 12px; border-bottom:1px solid var(--border,#f0f0f0); vertical-align:middle; }
.map-table .src { font-weight:600; color:var(--t1,#111827); }
.map-table .sample { color:var(--t3,#9ca3af); font-size:.8rem; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.map-table select { width:100%; padding:6px 9px; border:1px solid var(--border,#d1d5db); border-radius:6px; background:#fff; font-size:.85rem; }
.map-table select.unmapped { color:#9ca3af; }
.prev-wrap { overflow-x:auto; border:1px solid var(--border,#e5e7eb); border-radius:8px; max-height:62vh; }
.prev-table td { padding:6px 10px; border-bottom:1px solid var(--border,#f0f0f0); white-space:nowrap; }
.prev-table td[contenteditable] { outline:none; min-width:70px; }
.prev-table td[contenteditable]:focus { background:#fffbe6; box-shadow:inset 0 0 0 2px #fcd34d; }
.prev-table tr.row-error  td.statuscell { color:#991b1b; }
.prev-table tr.row-warn   td.statuscell { color:#b45309; }
.prev-table tr.row-ok     td.statuscell { color:#166534; }
.prev-table tr.row-error { background:#fef2f2; }
.prev-table tr.row-warn  { background:#fffbeb; }
.prev-table .issues { color:#b45309; font-size:.78rem; white-space:normal; min-width:220px; }
.prev-table tr.row-error .issues { color:#991b1b; }
.dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; }
.dot.ok{background:#22c55e;} .dot.warn{background:#f59e0b;} .dot.err{background:#ef4444;}
.sum-chips { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.sum-chip { font-size:.85rem; padding:6px 14px; border-radius:8px; font-weight:600; }
.sum-chip.ok{background:#dcfce7;color:#166534;} .sum-chip.warn{background:#fef3c7;color:#92400e;}
.sum-chip.err{background:#fee2e2;color:#991b1b;} .sum-chip.tot{background:var(--bg3,#f3f4f6);color:var(--t2,#374151);}
.imp-btn { padding:9px 18px; border:none; border-radius:7px; cursor:pointer; font-size:.9rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
.imp-btn.primary { background:var(--gold,#BC5A3C); color:#fff; }
.imp-btn.primary:disabled { opacity:.5; cursor:not-allowed; }
.imp-btn.ghost { background:var(--bg3,#f3f4f6); color:var(--t2,#374151); border:1px solid var(--border,#e5e7eb); }
.imp-btn.green { background:#16a34a; color:#fff; }
.imp-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
.imp-note { font-size:.8rem; color:#b45309; margin-top:8px; }
.imp-hidden { display:none; }
.spin { display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,.5); border-top-color:#fff; border-radius:50%; animation:impspin .7s linear infinite; }
@keyframes impspin { to { transform:rotate(360deg); } }
</style>

<div class="content-wrapper">
<div class="imp-wrap">

    <div class="imp-hdr">
        <div>
            <h1><i class="fa fa-table" style="color:var(--gold,#BC5A3C);margin-right:8px;"></i>Map &amp; Preview Staff Import</h1>
            <div class="sub">File: <b><?= htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?></b>
                — <?= count($rows) ?> data row<?= count($rows) === 1 ? '' : 's' ?> detected<?php if (!empty($capped)): ?>
                <span style="color:#b45309;">(showing first <?= (int) $capLimit ?>; split larger files)</span><?php endif; ?>
            </div>
        </div>
        <a href="<?= base_url('staff/master_staff') ?>" class="imp-btn ghost"><i class="fa fa-arrow-left"></i> Start over</a>
    </div>

    <!-- STEP 1 — MAPPING -->
    <div class="imp-card" id="mapCard">
        <h2>1. Match your columns</h2>
        <p class="hint">We pre-filled the best guess for each column. Fix any that look wrong, or set them to “— Ignore —”. Required fields must be mapped.</p>
        <div class="req-chips" id="reqChips"></div>
        <div style="overflow-x:auto;border:1px solid var(--border,#e5e7eb);border-radius:8px;">
            <table class="map-table">
                <thead><tr><th style="width:30%">Your column</th><th style="width:30%">Sample value</th><th style="width:40%">Maps to field</th></tr></thead>
                <tbody id="mapBody"></tbody>
            </table>
        </div>
        <div class="imp-actions">
            <button class="imp-btn primary" id="validateBtn"><i class="fa fa-eye"></i> Validate &amp; Preview</button>
        </div>
    </div>

    <!-- STEP 2 — PREVIEW -->
    <div class="imp-card imp-hidden" id="prevCard">
        <h2>2. Preview &amp; fix</h2>
        <p class="hint">Values below are cleaned/normalized. Red rows have blocking errors and won't import. You can edit any cell, then re-validate. Staff whose phone already exists are skipped automatically.</p>
        <div class="sum-chips" id="sumChips"></div>
        <div class="prev-wrap">
            <table class="prev-table">
                <thead><tr id="prevHead"></tr></thead>
                <tbody id="prevBody"></tbody>
            </table>
        </div>
        <div class="imp-note imp-hidden" id="editNote"><i class="fa fa-info-circle"></i> You edited some cells — click “Re-validate” to refresh statuses before importing.</div>

        <div class="imp-actions">
            <button class="imp-btn ghost" id="revalidateBtn"><i class="fa fa-refresh"></i> Re-validate</button>
            <button class="imp-btn green" id="commitBtn"><i class="fa fa-check"></i> Import valid rows</button>
            <button class="imp-btn ghost imp-hidden" id="errReportBtn"><i class="fa fa-download"></i> Download error rows</button>
            <span id="previewMeta" style="font-size:.8rem;color:var(--t3,#6b7280);"></span>
        </div>

        <div class="imp-hidden" id="progWrap" style="margin-top:14px;">
            <div style="height:10px;background:var(--bg3,#e5e7eb);border-radius:6px;overflow:hidden;">
                <div id="progBar" style="height:100%;width:0;background:#16a34a;transition:width .2s;"></div>
            </div>
            <div id="progText" style="font-size:.82rem;color:var(--t2,#374151);margin-top:6px;"></div>
        </div>
    </div>

    <!-- STEP 3 — RESULT -->
    <div class="imp-card imp-hidden" id="resultCard">
        <h2>3. Result</h2>
        <div id="resultMsg" style="font-size:.9rem;line-height:1.6;"></div>
        <div class="imp-actions">
            <a href="<?= base_url('staff/all_staff') ?>" class="imp-btn primary"><i class="fa fa-users"></i> Go to Staff List</a>
            <a href="<?= base_url('staff/master_staff') ?>" class="imp-btn ghost"><i class="fa fa-upload"></i> Import another file</a>
        </div>
    </div>

</div>
</div>

<script>
(function () {
    var SITE_URL = <?= json_encode(rtrim(base_url(), '/')) ?>;
    var RAW_HEADERS = <?= json_encode(array_values($headers)) ?>;
    var RAW_ROWS    = <?= json_encode(array_values($rows)) ?>;
    var AUTOMAP     = <?= json_encode($autoMap) ?>;          // colIndex -> fieldKey|null
    var SCHEMA      = <?= json_encode($schema) ?>;           // [{key,label,required}]
    var PREVIEW_LIMIT = 50;

    var CSRF_NAME = (document.querySelector('meta[name="csrf-name"]')  || {}).content || 'csrf_token';
    var CSRF_HASH = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var VALIDATED = null;   // [{data, errors, warnings, status}]
    var dirty = false;

    var schemaByKey = {};
    SCHEMA.forEach(function (f) { schemaByKey[f.key] = f; });
    var requiredKeys = SCHEMA.filter(function (f) { return f.required; }).map(function (f) { return f.key; });

    function el(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
        });
    }

    function fieldOptions(selectedKey) {
        var html = '<option value="">— Ignore —</option>';
        SCHEMA.forEach(function (f) {
            var sel = (f.key === selectedKey) ? ' selected' : '';
            html += '<option value="' + esc(f.key) + '"' + sel + '>' + esc(f.label)
                  + (f.required ? ' *' : '') + '</option>';
        });
        return html;
    }
    function renderMapping() {
        var body = el('mapBody');
        body.innerHTML = RAW_HEADERS.map(function (h, i) {
            var sample = '';
            for (var r = 0; r < RAW_ROWS.length && r < 5; r++) {
                if (RAW_ROWS[r][i] != null && String(RAW_ROWS[r][i]).trim() !== '') { sample = RAW_ROWS[r][i]; break; }
            }
            var guess = AUTOMAP[i] || '';
            return '<tr>'
                + '<td class="src">' + esc(h || ('Column ' + (i + 1))) + '</td>'
                + '<td class="sample">' + esc(sample) + '</td>'
                + '<td><select data-col="' + i + '" class="' + (guess ? '' : 'unmapped') + '">' + fieldOptions(guess) + '</select></td>'
                + '</tr>';
        }).join('');
        body.querySelectorAll('select').forEach(function (s) {
            s.addEventListener('change', function () {
                s.classList.toggle('unmapped', !s.value);
                refreshReqChips();
            });
        });
        refreshReqChips();
    }
    function currentMapping() {
        var map = {};
        el('mapBody').querySelectorAll('select').forEach(function (s) {
            map[parseInt(s.getAttribute('data-col'), 10)] = s.value || null;
        });
        return map;
    }
    function mappedKeys() {
        var map = currentMapping(), set = {};
        Object.keys(map).forEach(function (k) { if (map[k]) set[map[k]] = true; });
        return set;
    }
    function refreshReqChips() {
        var set = mappedKeys();
        el('reqChips').innerHTML = requiredKeys.map(function (k) {
            var ok = !!set[k];
            return '<span class="req-chip ' + (ok ? 'ok' : 'bad') + '">'
                + '<i class="fa fa-' + (ok ? 'check' : 'exclamation') + '"></i>'
                + esc(schemaByKey[k].label) + (ok ? ' mapped' : ' — not mapped') + '</span>';
        }).join('');
        var allReq = requiredKeys.every(function (k) { return set[k]; });
        el('validateBtn').disabled = !allReq;
        return allReq;
    }

    function buildCanonRows() {
        var map = currentMapping();
        return RAW_ROWS.map(function (row) {
            var o = {};
            for (var i = 0; i < row.length; i++) {
                var fk = map[i];
                if (fk) o[fk] = row[i];
            }
            return o;
        });
    }

    function post(action, payloadObj) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        fd.append('payload', JSON.stringify(payloadObj));
        return fetch(SITE_URL + '/staff/' + action, {
            method: 'POST', body: fd,
            headers: { 'X-CSRF-Token': CSRF_HASH, 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.text(); }).then(function (raw) {
            try { return JSON.parse(raw); }
            catch (e) { console.error(action + ' raw:', raw.substring(0, 600)); throw new Error('Server returned an unexpected response.'); }
        });
    }

    function displayedFields() {
        var set = mappedKeys();
        return SCHEMA.filter(function (f) { return f.required || set[f.key]; });
    }

    function renderSummary(sum) {
        el('sumChips').innerHTML =
            '<span class="sum-chip tot">Total: ' + sum.total + '</span>'
          + '<span class="sum-chip ok">Ready: ' + sum.ok + '</span>'
          + '<span class="sum-chip warn">Warnings: ' + sum.warning + '</span>'
          + '<span class="sum-chip err">Errors: ' + sum.error + '</span>';
        el('errReportBtn').classList.toggle('imp-hidden', sum.error === 0);
        var importable = sum.ok + sum.warning;
        el('commitBtn').disabled = importable === 0;
        el('commitBtn').innerHTML = '<i class="fa fa-check"></i> Import ' + importable + ' valid row' + (importable === 1 ? '' : 's');
    }

    function renderPreview() {
        var fields = displayedFields();
        el('prevHead').innerHTML = '<th>#</th><th class="statuscell">Status</th>'
            + fields.map(function (f) { return '<th>' + esc(f.label) + (f.required ? ' *' : '') + '</th>'; }).join('')
            + '<th>Issues</th>';

        var n = Math.min(VALIDATED.length, PREVIEW_LIMIT);
        var rowsHtml = '';
        for (var i = 0; i < n; i++) {
            var v = VALIDATED[i];
            var cls = v.status === 'error' ? 'row-error' : (v.status === 'warning' ? 'row-warn' : 'row-ok');
            var dotc = v.status === 'error' ? 'err' : (v.status === 'warning' ? 'warn' : 'ok');
            var issues = (v.errors || []).concat(v.warnings || []);
            rowsHtml += '<tr class="' + cls + '" data-row="' + i + '">'
                + '<td>' + (i + 1) + '</td>'
                + '<td class="statuscell"><span class="dot ' + dotc + '"></span>' + v.status + '</td>'
                + fields.map(function (f) {
                    return '<td contenteditable data-key="' + esc(f.key) + '">' + esc(v.data[f.key] || '') + '</td>';
                }).join('')
                + '<td class="issues">' + esc(issues.join('; ')) + '</td>'
                + '</tr>';
        }
        el('prevBody').innerHTML = rowsHtml;
        el('previewMeta').textContent = VALIDATED.length > PREVIEW_LIMIT
            ? ('Showing first ' + PREVIEW_LIMIT + ' of ' + VALIDATED.length + ' rows (all are validated & imported).') : '';

        el('prevBody').querySelectorAll('td[contenteditable]').forEach(function (td) {
            td.addEventListener('input', function () {
                var rowIdx = parseInt(td.closest('tr').getAttribute('data-row'), 10);
                var key = td.getAttribute('data-key');
                VALIDATED[rowIdx].data[key] = td.textContent;
                dirty = true;
                el('editNote').classList.remove('imp-hidden');
            });
        });
    }

    function setBtnLoading(btn, on, labelHtml) {
        if (on) { btn.dataset.html = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spin"></span> ' + (labelHtml || 'Working…'); }
        else { btn.disabled = false; btn.innerHTML = btn.dataset.html || btn.innerHTML; }
    }

    el('validateBtn').addEventListener('click', function () {
        if (!refreshReqChips()) { alert('Please map all required fields first.'); return; }
        var btn = this; setBtnLoading(btn, true, 'Validating…');
        post('import_validate', { rows: buildCanonRows() }).then(function (resp) {
            VALIDATED = resp.rows; dirty = false;
            el('prevCard').classList.remove('imp-hidden');
            el('editNote').classList.add('imp-hidden');
            renderSummary(resp.summary); renderPreview();
            el('prevCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }).catch(function (e) { alert(e.message || 'Validation failed.'); })
          .finally(function () { setBtnLoading(btn, false); });
    });

    el('revalidateBtn').addEventListener('click', function () {
        if (!VALIDATED) return;
        var btn = this; setBtnLoading(btn, true, 'Re-validating…');
        post('import_validate', { rows: VALIDATED.map(function (r) { return r.data; }) }).then(function (resp) {
            VALIDATED = resp.rows; dirty = false;
            el('editNote').classList.add('imp-hidden');
            renderSummary(resp.summary); renderPreview();
        }).catch(function (e) { alert(e.message || 'Re-validation failed.'); })
          .finally(function () { setBtnLoading(btn, false); });
    });

    var BATCH_SIZE = 25; // rows per commit request — keeps each call well under proxy/PHP timeouts

    el('commitBtn').addEventListener('click', function () {
        if (!VALIDATED) return;
        if (dirty && !confirm('You have unsaved edits that were not re-validated. Import anyway?')) return;
        var importable = VALIDATED.filter(function (r) { return r.status !== 'error'; }).length;
        if (!importable) { alert('No valid rows to import.'); return; }
        if (!confirm('Import ' + importable + ' staff member(s)? This writes to the database.')) return;

        var btn = this; setBtnLoading(btn, true, 'Importing… (do not close)');
        el('revalidateBtn').disabled = true;

        // Send only non-error rows (server re-validates authoritatively).
        var allRows = VALIDATED.filter(function (r) { return r.status !== 'error'; }).map(function (r) { return r.data; });

        var agg = { success: 0, duplicates: 0, error: 0 };
        var batched = allRows.length > BATCH_SIZE;
        if (batched) { el('progWrap').classList.remove('imp-hidden'); }

        function setProgress(done) {
            var pct = Math.round((done / allRows.length) * 100);
            el('progBar').style.width = pct + '%';
            el('progText').textContent = 'Imported ' + done + ' / ' + allRows.length + ' rows…';
        }

        function commitBatch(start) {
            var batch = allRows.slice(start, start + BATCH_SIZE);
            return post('import_commit', { rows: batch, firstBatch: start === 0 }).then(function (resp) {
                if (resp.status !== 'success') throw new Error(resp.message || 'Import failed.');
                var c = resp.counts || {};
                agg.success += c.success || 0; agg.duplicates += c.duplicates || 0; agg.error += c.error || 0;
                setProgress(Math.min(start + BATCH_SIZE, allRows.length));
                if (start + BATCH_SIZE < allRows.length) return commitBatch(start + BATCH_SIZE);
            });
        }

        if (batched) setProgress(0);
        commitBatch(0).then(function () {
            el('mapCard').classList.add('imp-hidden');
            el('prevCard').classList.add('imp-hidden');
            el('resultCard').classList.remove('imp-hidden');
            var html = '<div class="sum-chips">'
                + '<span class="sum-chip ok">Imported: ' + agg.success + '</span>'
                + (agg.duplicates ? '<span class="sum-chip warn">Duplicates skipped: ' + agg.duplicates + '</span>' : '')
                + '<span class="sum-chip err">Failed: ' + agg.error + '</span>'
                + '</div>';
            if (agg.success > 0) {
                html += '<div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">'
                    + '<a href="' + SITE_URL + '/staff/import_credentials_pdf" target="_blank" rel="noopener" '
                    + 'style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;'
                    + 'padding:9px 16px;border-radius:8px;font-size:.88rem;">'
                    + '<i class="fa fa-file-pdf-o"></i> Download Credentials PDF</a>'
                    + '<div style="margin-top:6px;color:#64748b;font-size:.8rem;">'
                    + 'Name, mobile number, User ID &amp; default password for the ' + agg.success
                    + ' newly imported staff member' + (agg.success === 1 ? '' : 's') + '. Keep it confidential.</div></div>';
            }
            el('resultMsg').innerHTML = html;
            el('resultCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }).catch(function (e) {
            alert((e.message || 'Import failed.') + '\n\nImported so far: ' + agg.success
                + '. You can re-run — already-imported staff are detected as duplicates and skipped.');
            setBtnLoading(btn, false); el('revalidateBtn').disabled = false;
            el('progWrap').classList.add('imp-hidden');
        });
    });

    el('errReportBtn').addEventListener('click', function () {
        if (!VALIDATED) return;
        var fields = SCHEMA.map(function (f) { return f.key; });
        var lines = [['Row'].concat(fields).concat(['Issues']).map(csvCell).join(',')];
        VALIDATED.forEach(function (v, i) {
            if (v.status !== 'error') return;
            var row = [String(i + 1)].concat(fields.map(function (k) { return v.data[k] || ''; }))
                .concat([(v.errors || []).concat(v.warnings || []).join(' | ')]);
            lines.push(row.map(csvCell).join(','));
        });
        var blob = new Blob([lines.join('\r\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'staff_import_error_rows.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
    function csvCell(s) {
        s = String(s == null ? '' : s);
        return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }

    renderMapping();
})();
</script>
