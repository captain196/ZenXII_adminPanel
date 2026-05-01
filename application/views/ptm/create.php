<style>
.ptm-wrap{padding:20px;max-width:1400px;margin:0 auto}
.ptm-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.ptm-title{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.ptm-title i{color:var(--gold);font-size:1.1rem}
.ptm-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.ptm-breadcrumb a{color:var(--gold);text-decoration:none}
.ptm-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}

.ptm-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start}
@media (max-width:1100px){.ptm-grid{grid-template-columns:1fr}}

.ptm-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px}
.ptm-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.ptm-card-title .sub{font-weight:500;color:var(--t3);font-size:12px}
.ptm-section-head{display:flex;align-items:center;gap:8px;font-family:var(--font-b);font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin:18px 0 10px;padding-bottom:6px;border-bottom:1px dashed var(--border)}
.ptm-section-head:first-child{margin-top:0}
.ptm-section-head i{color:var(--gold)}

.ptm-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
.ptm-form-row.full{grid-template-columns:1fr}
@media (max-width:700px){.ptm-form-row{grid-template-columns:1fr}}

.ptm-label{display:block;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;font-family:var(--font-b)}
.ptm-label .req{color:#ef4444;margin-left:2px}
.ptm-input,.ptm-select,.ptm-textarea{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:6px;background:var(--bg1);color:var(--t1);font-size:13px;font-family:var(--font-b);transition:border-color var(--ease),box-shadow var(--ease)}
.ptm-textarea{min-height:90px;resize:vertical}
.ptm-input:focus,.ptm-select:focus,.ptm-textarea:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim,rgba(212,175,55,.15))}
.ptm-help{font-size:11px;color:var(--t3);margin-top:4px;font-family:var(--font-b)}

.ptm-btn{padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:var(--font-b);text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all var(--ease)}
.ptm-btn-primary{background:var(--gold);color:#fff}.ptm-btn-primary:hover{background:var(--gold2);color:#fff}
.ptm-btn-primary[disabled]{opacity:.45;cursor:not-allowed}
.ptm-btn-outline{background:transparent;border:1px solid var(--border);color:var(--t2)}.ptm-btn-outline:hover{background:var(--bg1)}

/* Section → Class Teacher mapping table */
.ptm-mapping{display:grid;gap:6px;margin-top:6px}
.ptm-mapping-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) auto;gap:10px;padding:8px 12px;background:var(--bg1);border:1px solid var(--border);border-radius:6px;align-items:center;font-family:var(--font-b);font-size:12px}
.ptm-mapping-row.missing{border-color:#ef4444;background:rgba(239,68,68,.06)}
.ptm-mapping-row .sec{color:var(--t1);font-weight:600}
.ptm-mapping-row .teacher{color:var(--t2)}
.ptm-mapping-row .teacher.unset{color:#ef4444;font-weight:600}
.ptm-mapping-row .pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.04em}
.ptm-mapping-row .pill.ok{background:rgba(34,197,94,.12);color:#22c55e}
.ptm-mapping-row .pill.bad{background:rgba(239,68,68,.12);color:#ef4444}
.ptm-mapping-empty{padding:14px;text-align:center;font-size:12px;color:var(--t3);font-family:var(--font-b);background:var(--bg1);border:1px dashed var(--border);border-radius:6px}
.ptm-mapping-warning{padding:10px 12px;background:rgba(239,68,68,.08);border:1px solid #ef4444;border-radius:6px;font-size:12px;color:#ef4444;font-family:var(--font-b);margin-top:10px;display:flex;align-items:flex-start;gap:8px}
.ptm-mapping-warning i{margin-top:2px}
.ptm-mapping-warning a{color:#ef4444;text-decoration:underline;font-weight:600}

.ptm-summary{position:sticky;top:20px}
.ptm-summary .pv-row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px dashed var(--border);font-size:13px;font-family:var(--font-b)}
.ptm-summary .pv-row:last-child{border-bottom:none}
.ptm-summary .pv-row .k{color:var(--t3);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.ptm-summary .pv-row .v{color:var(--t1);text-align:right;font-weight:600;max-width:60%;word-break:break-word}
.ptm-summary .pv-row .v.muted{color:var(--t4);font-weight:400}

.ptm-tip{display:flex;gap:10px;padding:10px;background:var(--bg1);border:1px solid var(--border);border-left:3px solid var(--gold);border-radius:6px;font-size:12px;color:var(--t2);font-family:var(--font-b);line-height:1.5}
.ptm-tip i{color:var(--gold);margin-top:2px}
.ptm-tip + .ptm-tip{margin-top:8px}

.ptm-form-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:14px 18px;margin-top:4px;background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:10px}

.ptm-toast{position:fixed;top:20px;right:20px;padding:12px 18px;border-radius:8px;color:#fff;font-family:var(--font-b);font-size:13px;font-weight:600;z-index:9999;display:none;box-shadow:0 4px 12px rgba(0,0,0,.2)}
.ptm-toast.success{background:#22c55e;display:block}.ptm-toast.error{background:#ef4444;display:block}
</style>

<?php
$ptmEdit = isset($ptm) && is_array($ptm) ? $ptm : null;
$isEdit  = $ptmEdit !== null;

// Pre-derive window for edit-mode (Phase-A normalizer logic, server-side
// equivalent): prefer root startTime/endTime; fall back to first/last slot.
$editStart = '';
$editEnd   = '';
$editScope = 'all';
if ($isEdit) {
    $editStart = (string) ($ptmEdit['startTime'] ?? '');
    $editEnd   = (string) ($ptmEdit['endTime']   ?? '');
    if ($editStart === '' && !empty($ptmEdit['slots']) && is_array($ptmEdit['slots'])) {
        $editStart = (string) ($ptmEdit['slots'][0]['startTime'] ?? '');
        $lastSlot  = end($ptmEdit['slots']);
        $editEnd   = (string) ($lastSlot['endTime'] ?? '');
    }
    $editSectionKey = (string) ($ptmEdit['sectionKey'] ?? 'ALL');
    $editScope = ($editSectionKey === 'ALL' || $editSectionKey === '') ? 'all' : 'specific';
}
?>

<div class="content-wrapper"><section class="content"><div class="ptm-wrap">
<div class="ptm-header">
  <div>
    <div class="ptm-title">
      <i class="fa fa-<?= $isEdit ? 'pencil' : 'plus-circle' ?>"></i>
      <?= $isEdit ? 'Edit PTM' : 'Schedule PTM' ?>
    </div>
    <ol class="ptm-breadcrumb">
      <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
      <li><a href="<?= base_url('ptm') ?>">PTM</a></li>
      <li><?= $isEdit ? htmlspecialchars($ptmEdit['ptmEventId'] ?? 'Edit') : 'New' ?></li>
    </ol>
  </div>
</div>

<div class="ptm-grid">
  <!-- ── LEFT: form ───────────────────────────────────────────── -->
  <div>
    <form id="ptmForm" class="ptm-card" onsubmit="return PTM.save(event)">
      <input type="hidden" name="ptmEventId" id="ptmEventId" value="<?= $isEdit ? htmlspecialchars($ptmEdit['ptmEventId'] ?? '') : '' ?>">
      <div class="ptm-card-title"><span><?= $isEdit ? 'Edit meeting' : 'Meeting details' ?></span><span class="sub">Each section is hosted by its own class teacher</span></div>

      <div class="ptm-section-head"><i class="fa fa-info-circle"></i> Basics</div>
      <div class="ptm-form-row full">
        <div>
          <label class="ptm-label">Title <span class="req">*</span></label>
          <input class="ptm-input" name="title" maxlength="200" required placeholder="e.g. Q2 Parent-Teacher Meeting">
        </div>
      </div>
      <div class="ptm-form-row">
        <div>
          <label class="ptm-label">Date <span class="req">*</span></label>
          <input class="ptm-input" name="date" type="date" required>
        </div>
        <div>
          <label class="ptm-label">Location</label>
          <input class="ptm-input" name="location" maxlength="100" placeholder="e.g. Auditorium / Class room">
        </div>
      </div>

      <div class="ptm-section-head"><i class="fa fa-clock-o"></i> Time window</div>
      <div class="ptm-form-row">
        <div>
          <label class="ptm-label">Start time <span class="req">*</span></label>
          <input class="ptm-input" name="startTime" id="startTime" type="time" required value="<?= htmlspecialchars($editStart ?: '10:00') ?>">
        </div>
        <div>
          <label class="ptm-label">End time <span class="req">*</span></label>
          <input class="ptm-input" name="endTime" id="endTime" type="time" required value="<?= htmlspecialchars($editEnd ?: '14:00') ?>">
        </div>
      </div>
      <p class="ptm-help">Parents can drop in any time during this window. Each section's class teacher meets every parent who has applied for that section.</p>

      <div class="ptm-section-head"><i class="fa fa-users"></i> Audience</div>
      <div class="ptm-form-row">
        <div>
          <label class="ptm-label">Scope <span class="req">*</span></label>
          <select class="ptm-select" name="audienceScope" id="audienceScope">
            <option value="all" <?= $editScope === 'all' ? 'selected' : '' ?>>Whole school — all sections</option>
            <option value="specific" <?= $editScope === 'specific' ? 'selected' : '' ?>>Specific class &amp; section</option>
          </select>
        </div>
        <div id="sectionPickerWrap" style="display:<?= $editScope === 'specific' ? '' : 'none' ?>">
          <label class="ptm-label">Class &amp; section <span class="req">*</span></label>
          <select class="ptm-select" name="sectionKey" id="sectionPicker">
            <option value="">Loading…</option>
          </select>
        </div>
      </div>

      <div class="ptm-section-head"><i class="fa fa-align-left"></i> Description</div>
      <div class="ptm-form-row full">
        <div>
          <label class="ptm-label">Agenda / notes for parents</label>
          <textarea class="ptm-textarea" name="description" maxlength="2000" placeholder="Optional context — what parents should bring, focus areas, etc."></textarea>
        </div>
      </div>
    </form>

    <div class="ptm-card">
      <div class="ptm-card-title">
        <span>Section &rarr; class teacher</span>
        <span id="mappingStatus" class="sub"></span>
      </div>
      <p class="ptm-help" style="margin-bottom:10px">Each section's class teacher meets all applying parents from that section. Set up missing class teachers in <a href="<?= base_url('classes') ?>" style="color:var(--gold)">Sections admin</a> first.</p>
      <div id="mappingList" class="ptm-mapping">
        <div class="ptm-mapping-empty">Loading section mapping…</div>
      </div>
      <div id="mappingWarning" class="ptm-mapping-warning" style="display:none">
        <i class="fa fa-exclamation-triangle"></i>
        <div id="mappingWarningText"></div>
      </div>
    </div>

    <div class="ptm-form-actions">
      <a href="<?= base_url('ptm') ?>" class="ptm-btn ptm-btn-outline"><i class="fa fa-times"></i> Cancel</a>
      <button id="saveBtn" class="ptm-btn ptm-btn-primary" onclick="PTM.save(event)" disabled><i class="fa fa-check"></i> <?= $isEdit ? 'Save changes' : 'Save PTM' ?></button>
    </div>
  </div>

  <!-- ── RIGHT: live summary + tips ──────────────────────────── -->
  <aside class="ptm-summary">
    <div class="ptm-card">
      <div class="ptm-card-title"><span>Preview</span></div>
      <div class="pv-row"><span class="k">Title</span><span class="v muted" id="pvTitle">—</span></div>
      <div class="pv-row"><span class="k">Date</span><span class="v muted" id="pvDate">—</span></div>
      <div class="pv-row"><span class="k">Window</span><span class="v" id="pvWindow">—</span></div>
      <div class="pv-row"><span class="k">Audience</span><span class="v" id="pvAud">Whole school</span></div>
      <div class="pv-row"><span class="k">Sections</span><span class="v" id="pvSections">0</span></div>
      <div class="pv-row"><span class="k">Class teachers</span><span class="v" id="pvTeachers">0</span></div>
    </div>

    <div class="ptm-card">
      <div class="ptm-card-title"><span>Tips</span></div>
      <div class="ptm-tip"><i class="fa fa-info-circle"></i><div>Each section is independently hosted by its own <strong>class teacher</strong> — no slot bookings or subject teachers in this model.</div></div>
      <div class="ptm-tip"><i class="fa fa-bell"></i><div>Parents are notified instantly when this PTM is published; the relevant class teachers receive their own notification.</div></div>
    </div>
  </aside>
</div>

</div></section></div>

<div id="ptmToast" class="ptm-toast"></div>

<script>
// CSRF token rendered server-side, refreshed from any response that
// returns a fresh csrf_token field.
var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
var CSRF_TOKEN = '<?= $this->security->get_csrf_hash() ?>';
(function(){
  // Edit-mode payload from the controller. `null` for the new-PTM page.
  const PTM_EDIT = <?= $isEdit ? json_encode([
    'ptmEventId'  => $ptmEdit['ptmEventId'] ?? '',
    'title'       => $ptmEdit['title']       ?? '',
    'date'        => $ptmEdit['date']        ?? '',
    'location'    => $ptmEdit['location']    ?? '',
    'description' => $ptmEdit['description'] ?? '',
    'sectionKey'  => $ptmEdit['sectionKey']  ?? 'ALL',
    'className'   => $ptmEdit['className']   ?? '',
    'section'     => $ptmEdit['section']     ?? '',
    'status'      => $ptmEdit['status']      ?? 'scheduled',
    'startTime'   => $editStart,
    'endTime'     => $editEnd,
  ]) : 'null' ?>;

  const $  = (s,el)=>(el||document).querySelector(s);
  const $$ = (s,el)=>(el||document).querySelectorAll(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (msg, kind='success') => {
    const t = $('#ptmToast'); t.className='ptm-toast '+kind; t.textContent=msg;
    setTimeout(()=>t.style.display='none', 3000);
  };

  let sections   = [];   // list of {className, section, sectionKey, label}
  let mapping    = [];   // currently displayed section→teacher mapping
  let missingKeys = new Set();

  async function loadSections(){
    try {
      const res = await fetch('<?= base_url('ptm/get_sections') ?>', {credentials:'same-origin'});
      const data = await res.json();
      sections = (data && data.sections) || (data && data.data && data.data.sections) || [];
    } catch (e) { sections = []; }
    const sel = $('#sectionPicker');
    if (!sections.length) {
      sel.innerHTML = '<option value="">No classes found — add students first</option>';
      return;
    }
    const opts = ['<option value="">— Select class &amp; section —</option>'];
    for (const s of sections) {
      opts.push(`<option value="${esc(s.sectionKey)}" data-cls="${esc(s.className)}" data-sec="${esc(s.section)}">${esc(s.label)}</option>`);
    }
    sel.innerHTML = opts.join('');
  }

  function fmtDate(iso){
    if (!iso) return '';
    try {
      const d = new Date(iso + 'T00:00:00');
      return d.toLocaleDateString(undefined, { weekday:'short', day:'numeric', month:'short', year:'numeric' });
    } catch(e){ return iso; }
  }

  /**
   * Fetch the section→class-teacher mapping for the current scope and
   * render it. Disables Save when any section lacks a class teacher.
   */
  async function refreshMapping(){
    const scope = $('#audienceScope').value;
    const opt   = $('#sectionPicker').options[$('#sectionPicker').selectedIndex];
    const list  = $('#mappingList');
    const warn  = $('#mappingWarning');
    const wText = $('#mappingWarningText');
    const status = $('#mappingStatus');

    // Specific scope without a section pick → can't resolve yet.
    if (scope === 'specific' && !(opt && opt.value)) {
      list.innerHTML = '<div class="ptm-mapping-empty">Pick a class &amp; section to see the class teacher.</div>';
      warn.style.display = 'none';
      mapping = []; missingKeys = new Set();
      status.textContent = '';
      gateSaveButton();
      return;
    }

    let url = '<?= base_url('ptm/resolve_class_teachers') ?>?scope=' + encodeURIComponent(scope);
    if (scope === 'specific') {
      url += '&className=' + encodeURIComponent(opt.dataset.cls)
          +  '&section='   + encodeURIComponent(opt.dataset.sec);
    }
    status.textContent = 'Loading…';

    let resp = null;
    try {
      const res = await fetch(url, {credentials:'same-origin'});
      resp = await res.json();
    } catch (e) {
      list.innerHTML = '<div class="ptm-mapping-empty">Couldn’t load mapping — retry.</div>';
      warn.style.display = 'none';
      mapping = []; missingKeys = new Set();
      status.textContent = 'failed';
      gateSaveButton();
      return;
    }

    mapping     = (resp && resp.sections) || (resp && resp.data && resp.data.sections) || [];
    const miss  = (resp && resp.missing)  || (resp && resp.data && resp.data.missing)  || [];
    missingKeys = new Set(miss.map(m => m.sectionKey));
    status.textContent = mapping.length + ' section' + (mapping.length === 1 ? '' : 's');

    if (mapping.length === 0) {
      list.innerHTML = '<div class="ptm-mapping-empty">No sections in scope.</div>';
      warn.style.display = 'none';
      gateSaveButton();
      return;
    }

    list.innerHTML = mapping.map(m => {
      const isMissing = missingKeys.has(m.sectionKey);
      const teacher = isMissing
        ? `<span class="teacher unset">No class teacher set</span>`
        : `<span class="teacher">${esc(m.classTeacherName || m.classTeacherId)}</span>`;
      const pill = isMissing
        ? `<span class="pill bad">FIX</span>`
        : `<span class="pill ok">OK</span>`;
      return `<div class="ptm-mapping-row ${isMissing ? 'missing' : ''}">
        <span class="sec">${esc(m.sectionKey)}</span>
        ${teacher}
        ${pill}
      </div>`;
    }).join('');

    if (miss.length > 0) {
      const list2 = miss.slice(0, 5).map(m => esc(m.sectionKey)).join(', ');
      const more  = miss.length > 5 ? ` and ${miss.length - 5} more` : '';
      wText.innerHTML = `${miss.length} section${miss.length === 1 ? '' : 's'} need a class teacher: <strong>${list2}${more}</strong>. Set them in <a href="<?= base_url('classes') ?>">Sections admin</a>, then refresh this page.`;
      warn.style.display = '';
    } else {
      warn.style.display = 'none';
    }

    gateSaveButton();
    refreshPreview();
  }

  /**
   * Save button is enabled only when title, date, time window are valid
   * AND every section in scope has a class teacher resolved.
   */
  function gateSaveButton(){
    const f = $('#ptmForm');
    const ok = f.title.value.trim() !== ''
            && f.date.value !== ''
            && $('#startTime').value !== ''
            && $('#endTime').value   !== ''
            && mapping.length > 0
            && missingKeys.size === 0
            && ($('#audienceScope').value !== 'specific' || $('#sectionPicker').value !== '');
    $('#saveBtn').disabled = !ok;
  }

  function refreshPreview(){
    const f = $('#ptmForm');
    const title = f.title.value.trim();
    const date  = f.date.value;
    const startT = $('#startTime').value;
    const endT   = $('#endTime').value;
    const scope  = $('#audienceScope').value;
    const opt    = $('#sectionPicker').options[$('#sectionPicker').selectedIndex];

    const pvT = $('#pvTitle'); pvT.textContent = title || '—'; pvT.classList.toggle('muted', !title);
    const pvD = $('#pvDate');  pvD.textContent = date ? fmtDate(date) : '—'; pvD.classList.toggle('muted', !date);
    const pvW = $('#pvWindow');
    pvW.textContent = (startT && endT) ? `${startT} – ${endT}` : '—';
    pvW.classList.toggle('muted', !(startT && endT));

    const pvA = $('#pvAud');
    let aud, muted = false;
    if (scope === 'specific') {
      aud = (opt && opt.value) ? `${opt.dataset.cls} · ${opt.dataset.sec}` : 'Choose a section';
      muted = !(opt && opt.value);
    } else {
      aud = 'Whole school';
    }
    pvA.textContent = aud; pvA.classList.toggle('muted', muted);

    $('#pvSections').textContent = mapping.length;
    $('#pvTeachers').textContent = mapping.filter(m => !!m.classTeacherId).length;
  }

  function applyEditPrefill(){
    if (!PTM_EDIT) return;
    const f = $('#ptmForm');
    f.title.value       = PTM_EDIT.title || '';
    f.date.value        = PTM_EDIT.date  || '';
    f.location.value    = PTM_EDIT.location    || '';
    f.description.value = PTM_EDIT.description || '';
    if (PTM_EDIT.startTime) $('#startTime').value = PTM_EDIT.startTime;
    if (PTM_EDIT.endTime)   $('#endTime').value   = PTM_EDIT.endTime;

    const isAll = !PTM_EDIT.sectionKey || PTM_EDIT.sectionKey === 'ALL';
    $('#audienceScope').value = isAll ? 'all' : 'specific';
    $('#sectionPickerWrap').style.display = isAll ? 'none' : '';
    if (!isAll) {
      const sel = $('#sectionPicker');
      let matched = false;
      for (const o of sel.options) {
        if (o.value && o.value.toLowerCase() === String(PTM_EDIT.sectionKey).toLowerCase()) {
          sel.value = o.value;
          matched = true;
          break;
        }
      }
      if (!matched) {
        const o = document.createElement('option');
        o.value = PTM_EDIT.sectionKey;
        o.dataset.cls = PTM_EDIT.className || '';
        o.dataset.sec = PTM_EDIT.section   || '';
        o.textContent = `${PTM_EDIT.className || ''} · ${PTM_EDIT.section || ''} (orphaned)`;
        o.selected = true;
        sel.appendChild(o);
      }
    }
  }

  window.PTM = {
    save: async function(e){
      if (e) e.preventDefault();
      const form = $('#ptmForm');
      const title = form.title.value.trim();
      const date  = form.date.value;
      const startT = $('#startTime').value;
      const endT   = $('#endTime').value;
      const scope  = $('#audienceScope').value;

      if (!title) { toast('Title is required', 'error'); return false; }
      if (!date)  { toast('Date is required',  'error'); return false; }
      if (!startT || !endT) { toast('Start and end times are required', 'error'); return false; }
      if (startT >= endT)   { toast('End time must be after start',     'error'); return false; }

      let className = '', sectionStr = '';
      if (scope === 'specific') {
        const opt = $('#sectionPicker').options[$('#sectionPicker').selectedIndex];
        if (!opt || !opt.value) {
          toast('Pick a class & section first', 'error');
          return false;
        }
        className  = opt.dataset.cls || '';
        sectionStr = opt.dataset.sec || '';
      }

      if (mapping.length === 0 || missingKeys.size > 0) {
        toast('Resolve missing class teachers before saving', 'error');
        return false;
      }

      const fd = new FormData();
      fd.append(CSRF_NAME, CSRF_TOKEN);
      const editId = ($('#ptmEventId')?.value || '').trim();
      if (editId !== '') fd.append('ptmEventId', editId);
      fd.append('title',         title);
      fd.append('date',          date);
      fd.append('location',      form.location.value.trim());
      fd.append('description',   form.description.value.trim());
      fd.append('startTime',     startT);
      fd.append('endTime',       endT);
      fd.append('audienceScope', scope);
      fd.append('className',     className);
      fd.append('section',       sectionStr);
      fd.append('status',        (PTM_EDIT && PTM_EDIT.status) ? PTM_EDIT.status : 'scheduled');

      const postSave = async (withConfirmFlag) => {
        if (withConfirmFlag) fd.set('confirmDestructive', '1');
        fd.set(CSRF_NAME, CSRF_TOKEN);
        const r = await fetch('<?= base_url('ptm/save') ?>', {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json().catch(() => ({}));
        if (j && j.csrf_token) CSRF_TOKEN = j.csrf_token;
        return { ok: r.ok, status: r.status, body: j };
      };

      $('#saveBtn').disabled = true;
      try {
        let res = await postSave(false);

        // 409 + CONFIRM_REQUIRED → show destructive-change confirm dialog.
        if (!res.ok && res.body && res.body.code === 'CONFIRM_REQUIRED') {
          const lines = (res.body.destructiveChanges || []).slice(0, 6).map(s => '  • ' + s).join('\n');
          const msg = (res.body.message || 'This change may affect existing RSVPs.') +
                      (lines ? '\n\nChanges:\n' + lines : '') +
                      '\n\nProceed anyway?';
          if (!confirm(msg)) {
            toast('Save cancelled', 'error');
            $('#saveBtn').disabled = false;
            return false;
          }
          res = await postSave(true);
        }

        // 422 + MISSING_CLASS_TEACHER → re-render mapping with the server's
        // truth and surface a toast. Saves out of date when the admin
        // clicked save before the live preview finished loading.
        if (!res.ok && res.body && res.body.code === 'MISSING_CLASS_TEACHER') {
          await refreshMapping();
          toast(res.body.message || 'Some sections lack a class teacher.', 'error');
          return false;
        }

        if (res.ok && res.body && res.body.status !== 'error') {
          const warnings = res.body.warnings || [];
          if (warnings.length) {
            toast('PTM saved · ' + warnings[0], 'error');
            setTimeout(() => window.location.href = '<?= base_url('ptm') ?>', 2400);
          } else {
            toast('PTM saved');
            setTimeout(() => window.location.href = '<?= base_url('ptm') ?>', 600);
          }
        } else {
          toast((res.body && res.body.message) || 'Save failed', 'error');
        }
      } catch (e) {
        toast('Save failed', 'error');
      } finally {
        $('#saveBtn').disabled = false;
      }
      return false;
    }
  };

  // Live wiring
  document.addEventListener('input',  e => { if (e.target.closest('#ptmForm')) { refreshPreview(); gateSaveButton(); }});
  document.addEventListener('change', e => { if (e.target.closest('#ptmForm')) { refreshPreview(); gateSaveButton(); }});
  $('#audienceScope').addEventListener('change', () => {
    const isSpecific = $('#audienceScope').value === 'specific';
    $('#sectionPickerWrap').style.display = isSpecific ? '' : 'none';
    refreshMapping();
  });
  $('#sectionPicker').addEventListener('change', () => { refreshMapping(); });

  // Bootstrap
  loadSections().then(async () => {
    if (PTM_EDIT) applyEditPrefill();
    await refreshMapping();
    refreshPreview();
    gateSaveButton();
  });
})();
</script>
