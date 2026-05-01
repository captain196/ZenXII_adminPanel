<style>
.ptm-wrap{padding:20px;max-width:1400px;margin:0 auto}
.ptm-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.ptm-title{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.ptm-title i{color:var(--gold);font-size:1.1rem}
.ptm-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.ptm-breadcrumb a{color:var(--gold);text-decoration:none}
.ptm-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}
.ptm-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px}
.ptm-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.ptm-btn{padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b);text-decoration:none;display:inline-block}
.ptm-btn-primary{background:var(--gold);color:#fff} .ptm-btn-primary:hover{background:var(--gold2);color:#fff}
.ptm-btn-outline{background:transparent;border:1px solid var(--gold);color:var(--gold)} .ptm-btn-outline:hover{background:var(--gold-dim)}
.ptm-btn-danger{background:transparent;border:1px solid #ef4444;color:#ef4444}.ptm-btn-danger:hover{background:rgba(239,68,68,.1)}
.ptm-btn-sm{padding:5px 10px;font-size:12px}
.ptm-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.ptm-table th,.ptm-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.ptm-table th{color:var(--t3);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.ptm-table td{color:var(--t1)}
.ptm-table tr:hover td{background:var(--gold-dim)}
.ptm-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font-b)}
.ptm-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.ptm-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.ptm-badge-rose{background:rgba(239,68,68,.12);color:#ef4444}
.ptm-badge-gray{background:rgba(156,163,175,.12);color:#9ca3af}
.ptm-table td.ptm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.ptm-table td.ptm-empty i{font-size:32px;opacity:.4;display:block;margin-bottom:10px}

/* Dedicated empty-state panel — replaces the table when there are 0 PTMs. */
.ptm-empty-panel{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:64px 24px;color:var(--t2);font-family:var(--font-b)}
.ptm-empty-panel .icon-wrap{width:88px;height:88px;border-radius:50%;background:var(--gold-dim,rgba(212,175,55,.12));display:flex;align-items:center;justify-content:center;margin-bottom:20px}
.ptm-empty-panel .icon-wrap i{font-size:38px;color:var(--gold);opacity:.9}
.ptm-empty-panel h3{margin:0 0 8px;font-size:18px;color:var(--t1);font-weight:700;font-family:var(--font-b)}
.ptm-empty-panel p{margin:0 0 22px;font-size:13px;color:var(--t3);max-width:420px;line-height:1.55;font-family:var(--font-b)}
.ptm-empty-panel .ptm-btn{padding:10px 22px}
.ptm-actions{display:flex;gap:6px;flex-wrap:wrap}
.ptm-toast{position:fixed;top:20px;right:20px;padding:12px 18px;border-radius:8px;color:#fff;font-family:var(--font-b);font-size:13px;font-weight:600;z-index:9999;display:none;box-shadow:0 4px 12px rgba(0,0,0,.2)}
.ptm-toast.success{background:#22c55e;display:block}.ptm-toast.error{background:#ef4444;display:block}
.ptm-status-menu{position:relative;display:inline-block}
.ptm-status-menu > .ptm-badge{cursor:pointer;user-select:none}
.ptm-status-menu > .ptm-badge::after{content:" \25BE";opacity:.7}
.ptm-status-list{position:absolute;top:calc(100% + 4px);left:0;background:var(--bg2);border:1px solid var(--border);border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,.15);min-width:160px;padding:4px 0;display:none;z-index:60}
.ptm-status-list.open{display:block}
.ptm-status-list button{display:flex;align-items:center;gap:8px;width:100%;background:transparent;border:none;padding:8px 12px;font-size:12px;font-family:var(--font-b);color:var(--t1);text-align:left;cursor:pointer}
.ptm-status-list button:hover{background:var(--gold-dim)}
.ptm-status-list button[disabled]{opacity:.4;cursor:not-allowed}
.ptm-status-list button .dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.ptm-status-list button .dot.scheduled{background:#3b82f6}
.ptm-status-list button .dot.completed{background:#22c55e}
.ptm-status-list button .dot.cancelled{background:#ef4444}
</style>

<div class="content-wrapper"><section class="content"><div class="ptm-wrap">
<div class="ptm-header">
  <div>
    <div class="ptm-title"><i class="fa fa-users"></i> Parent-Teacher Meetings</div>
    <ol class="ptm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li>PTM</li></ol>
  </div>
  <div>
    <a href="<?= base_url('ptm/create') ?>" class="ptm-btn ptm-btn-primary"><i class="fa fa-plus"></i> Create PTM</a>
  </div>
</div>

<div class="ptm-card">
  <div class="ptm-card-title" id="ptmListTitle"><span>Scheduled &amp; past meetings</span></div>
  <table class="ptm-table" id="ptmTable">
    <thead>
      <tr>
        <th>Title</th>
        <th>Class / Section</th>
        <th>Date</th>
        <th>Time</th>
        <th>Slots</th>
        <th>RSVPs</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="ptmRows">
      <tr><td colspan="8" class="ptm-empty"><i class="fa fa-spinner fa-spin"></i><br>Loading…</td></tr>
    </tbody>
  </table>
  <div id="ptmEmpty" class="ptm-empty-panel" style="display:none">
    <div class="icon-wrap"><i class="fa fa-calendar-plus-o"></i></div>
    <h3>No PTMs scheduled yet</h3>
    <p>Schedule your first parent-teacher meeting and parents in the targeted classes will be notified instantly to RSVP from the SchoolSync app.</p>
    <a href="<?= base_url('ptm/create') ?>" class="ptm-btn ptm-btn-primary"><i class="fa fa-plus"></i> Schedule your first PTM</a>
  </div>
</div>
</div></section></div>

<div id="ptmToast" class="ptm-toast"></div>

<script>
// CSRF — token rendered server-side; appended to every POST and refreshed
// from any response that returns a fresh csrf_token field.
var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
var CSRF_TOKEN = '<?= $this->security->get_csrf_hash() ?>';
(function(){
  const $ = (s,el)=>(el||document).querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (msg, kind='success') => {
    const t = $('#ptmToast'); t.className = 'ptm-toast ' + kind; t.textContent = msg;
    setTimeout(()=>t.style.display='none', 3000);
  };

  const badgeForStatus = s => {
    const cls = s === 'scheduled' ? 'ptm-badge-blue'
              : s === 'completed' ? 'ptm-badge-green'
              : s === 'cancelled' ? 'ptm-badge-rose'
              : 'ptm-badge-gray';
    return `<span class="ptm-badge ${cls}">${esc((s||'').toUpperCase())}</span>`;
  };

  // Status badge that opens a transition menu. Allowed transitions mirror
  // the controller's set_status() rules so the UI doesn't offer paths that
  // the backend will reject.
  const STATUS_TRANSITIONS = {
    scheduled: ['completed', 'cancelled'],
    completed: ['scheduled'],
    cancelled: ['scheduled'],
  };
  const statusControl = p => {
    const cur = p.status || 'scheduled';
    const allowed = STATUS_TRANSITIONS[cur] || [];
    const cls = cur === 'scheduled' ? 'ptm-badge-blue'
              : cur === 'completed' ? 'ptm-badge-green'
              : cur === 'cancelled' ? 'ptm-badge-rose'
              : 'ptm-badge-gray';
    const items = ['scheduled','completed','cancelled'].map(s => {
      const en = (s !== cur) && allowed.includes(s);
      const lbl = s === 'scheduled' ? 'Re-open as scheduled'
                : s === 'completed' ? 'Mark completed'
                : 'Mark cancelled';
      return `<button ${en?'':'disabled'} onclick="PTM.setStatus('${esc(p.id)}','${s}', this)"><span class="dot ${s}"></span>${lbl}</button>`;
    }).join('');
    return `<div class="ptm-status-menu">
      <span class="ptm-badge ${cls}" onclick="PTM.toggleStatusMenu(this)">${esc(cur.toUpperCase())}</span>
      <div class="ptm-status-list">${items}</div>
    </div>`;
  };

  const formatDate = iso => {
    if (!iso) return '—';
    try { const d = new Date(iso); return d.toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}); }
    catch { return iso; }
  };

  // Convert "HH:MM" (24h, what's stored) → "h:mm AM/PM" for display.
  // Times are wall-clock strings (no timezone), entered and read in IST,
  // so this is pure formatting — no zone conversion.
  const to12h = hm => {
    if (!hm) return '';
    const m = /^(\d{1,2}):(\d{2})$/.exec(String(hm).trim());
    if (!m) return hm;
    let h = parseInt(m[1], 10);
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return `${h}:${m[2]} ${ap}`;
  };

  // Phase-B/C PTMs carry the meeting window at the doc root
  // (startTime/endTime). Legacy slot-based docs only have it inside
  // slots[]; pull the first start and last end so we still show
  // something useful for old PTMs that never got migrated.
  const formatTimeWindow = p => {
    const slots = Array.isArray(p.slots) ? p.slots : [];
    const s = (p.startTime || (slots[0] && slots[0].startTime) || '').trim();
    const e = (p.endTime   || (slots.length && slots[slots.length-1].endTime) || '').trim();
    if (!s && !e) return '—';
    return `${to12h(s) || '??:??'} – ${to12h(e) || '??:??'}`;
  };

  function renderRows(items){
    const tbody = $('#ptmRows');
    const table = $('#ptmTable');
    const empty = $('#ptmEmpty');
    const title = $('#ptmListTitle');
    if (!items.length) {
      // Hide the table entirely and show the dedicated empty-state panel.
      // Keeps the empty state truly centered (the table-cell + colspan
      // approach loses to .ptm-table td's text-align:left specificity).
      table.style.display = 'none';
      empty.style.display = '';
      title.style.display = 'none';
      return;
    }
    table.style.display = '';
    empty.style.display = 'none';
    title.style.display = '';
    tbody.innerHTML = items.map(p => {
      const sec = p.sectionKey === 'ALL' ? 'All sections' : `${esc(p.className||'')} ${esc(p.section||'')}`;
      const slotCount = Array.isArray(p.slots) ? p.slots.length : 0;
      const rsvpHref = '<?= base_url('ptm/rsvps/') ?>' + encodeURIComponent(p.id);
      const editHref = '<?= base_url('ptm/edit/') ?>' + encodeURIComponent(p.id);
      return `<tr>
        <td><b>${esc(p.title || '(Untitled)')}</b><br><small style="color:var(--t3)">${esc(p.location||'')}</small></td>
        <td>${sec}</td>
        <td>${formatDate(p.date)}</td>
        <td>${esc(formatTimeWindow(p))}</td>
        <td>${slotCount}</td>
        <td><a href="${rsvpHref}" style="color:var(--gold);font-weight:600">View →</a></td>
        <td>${statusControl(p)}</td>
        <td><div class="ptm-actions">
          <a href="${rsvpHref}" class="ptm-btn ptm-btn-outline ptm-btn-sm" title="View RSVPs"><i class="fa fa-eye"></i></a>
          <a href="${editHref}" class="ptm-btn ptm-btn-outline ptm-btn-sm" title="Edit PTM"><i class="fa fa-pencil"></i></a>
          <button class="ptm-btn ptm-btn-danger ptm-btn-sm" title="Delete PTM" onclick="PTM.del('${esc(p.id)}','${esc(p.title)}')"><i class="fa fa-trash"></i></button>
        </div></td>
      </tr>`;
    }).join('');
  }

  async function loadList(){
    try {
      const res = await fetch('<?= base_url('ptm/get_list') ?>', {credentials:'same-origin'});
      const data = await res.json();
      if (!data || data.success === false) {
        toast(data && data.message || 'Failed to load PTMs', 'error');
        return;
      }
      renderRows((data.data && data.data.ptms) || data.ptms || []);
    } catch (e) {
      toast('Failed to load PTMs', 'error');
    }
  }

  window.PTM = {
    del: async function(id, title){
      if (!confirm(`Delete PTM "${title}"? This will also remove all parent RSVPs.`)) return;
      const fd = new FormData();
      fd.append(CSRF_NAME, CSRF_TOKEN);
      try {
        const res = await fetch('<?= base_url('ptm/delete/') ?>' + encodeURIComponent(id), {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const data = await res.json().catch(() => ({}));
        if (data && data.csrf_token) CSRF_TOKEN = data.csrf_token;
        if (res.ok && data && data.status !== 'error') {
          toast('PTM deleted');
          loadList();
        } else {
          toast((data && data.message) || 'Delete failed', 'error');
        }
      } catch (e) {
        toast('Delete failed', 'error');
      }
    },

    toggleStatusMenu: function(badgeEl){
      // Close any other open menus before opening this one.
      document.querySelectorAll('.ptm-status-list.open').forEach(el => {
        if (el !== badgeEl.nextElementSibling) el.classList.remove('open');
      });
      const list = badgeEl.nextElementSibling;
      if (list) list.classList.toggle('open');
    },

    setStatus: async function(id, newStatus, btn){
      // Extra confirm specifically on cancellation — irreversible from the
      // parent's POV (push fires immediately) so we don't want it on a
      // single accidental click.
      if (newStatus === 'cancelled') {
        if (!confirm('Cancel this PTM and notify all affected parents/teachers? They will receive a "[PTM CANCELLED]" push notification.')) return;
      }
      btn.disabled = true;
      const fd = new FormData();
      fd.append(CSRF_NAME, CSRF_TOKEN);
      fd.append('status', newStatus);
      try {
        const res = await fetch('<?= base_url('ptm/set_status/') ?>' + encodeURIComponent(id), {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const data = await res.json().catch(() => ({}));
        if (data && data.csrf_token) CSRF_TOKEN = data.csrf_token;
        if (res.ok && data && data.status !== 'error') {
          // Surface cancellation push warnings (e.g. enqueue failed).
          const warnings = data.warnings || [];
          if (warnings.length) {
            toast(`Status: ${newStatus.toUpperCase()} · ${warnings[0]}`, 'error');
          } else if (newStatus === 'cancelled') {
            toast('PTM cancelled · notification sent');
          } else {
            toast(`Status: ${newStatus.toUpperCase()}`);
          }
          loadList();
        } else {
          toast((data && data.message) || 'Status change failed', 'error');
        }
      } catch (e) {
        toast('Status change failed', 'error');
      }
    }
  };

  // Click-away closes any open status menu.
  document.addEventListener('click', e => {
    if (e.target.closest('.ptm-status-menu')) return;
    document.querySelectorAll('.ptm-status-list.open').forEach(el => el.classList.remove('open'));
  });

  loadList();
})();
</script>
