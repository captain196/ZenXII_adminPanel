<?php
$ptm = $ptm ?? [];
$ptmId = $ptm['id'] ?? '';
$slots = is_array($ptm['slots'] ?? null) ? $ptm['slots'] : [];

// "HH:MM" (24h, stored) → "h:mm AM/PM" (Indian local). Wall-clock only,
// no timezone conversion — values are entered and read in IST.
$ptm_to12h = function ($hm) {
    if (!$hm) return '';
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $hm), $m)) return $hm;
    $ts = strtotime("{$m[1]}:{$m[2]}");
    return $ts !== false ? date('g:i A', $ts) : $hm;
};
?>
<style>
.ptm-wrap{padding:20px;max-width:1200px;margin:0 auto}
.ptm-header{margin-bottom:20px}
.ptm-title{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.ptm-title i{color:var(--gold);font-size:1.1rem}
.ptm-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.ptm-breadcrumb a{color:var(--gold);text-decoration:none}
.ptm-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}
.ptm-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px}
.ptm-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px}
.ptm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px}
.ptm-stat{padding:14px;border-radius:8px;background:var(--bg1);border:1px solid var(--border);text-align:center}
.ptm-stat .num{font-size:24px;font-weight:700;color:var(--gold);font-family:var(--font-b);line-height:1}
.ptm-stat .lbl{font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;margin-top:6px;font-family:var(--font-b)}
.ptm-detail-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-family:var(--font-b)}
.ptm-detail-row:last-child{border-bottom:none}
.ptm-detail-row .k{font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.05em}
.ptm-detail-row .v{font-size:13px;color:var(--t1);font-weight:600;text-align:right}
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
.ptm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.ptm-empty i{font-size:32px;opacity:.4;display:block;margin-bottom:10px}
.ptm-toast{position:fixed;top:20px;right:20px;padding:12px 18px;border-radius:8px;color:#fff;font-family:var(--font-b);font-size:13px;font-weight:600;z-index:9999;display:none;box-shadow:0 4px 12px rgba(0,0,0,.2)}
.ptm-toast.success{background:#22c55e;display:block}.ptm-toast.error{background:#ef4444;display:block}
.ptm-slots{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.ptm-slot-chip{padding:6px 12px;border-radius:6px;background:var(--gold-dim);border:1px solid var(--gold);font-size:12px;color:var(--gold);font-weight:600;font-family:var(--font-b)}

/* Grouped-by-slot RSVP view */
.ptm-slot-group{border:1px solid var(--border);border-radius:8px;background:var(--bg1);margin-bottom:10px;overflow:hidden}
.ptm-slot-group-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;cursor:pointer;font-family:var(--font-b);user-select:none}
.ptm-slot-group-head:hover{background:var(--gold-dim)}
.ptm-slot-group-head .left{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.ptm-slot-group-head .num{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;border-radius:50%;background:var(--gold);color:#fff;font-size:12px;font-weight:700}
.ptm-slot-group-head .when{font-size:13px;font-weight:700;color:var(--t1);white-space:nowrap}
.ptm-slot-group-head .who{font-size:12px;color:var(--t2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ptm-slot-group-head .right{display:flex;align-items:center;gap:14px;flex-shrink:0}
.ptm-slot-group-head .caret{transition:transform var(--ease,.2s);font-size:12px;color:var(--t3)}
.ptm-slot-group.open .ptm-slot-group-head .caret{transform:rotate(90deg)}
.ptm-cap-bar{width:120px;height:6px;border-radius:6px;background:var(--border);overflow:hidden}
.ptm-cap-bar > i{display:block;height:100%;background:var(--gold);border-radius:6px}
.ptm-cap-bar.full > i{background:#22c55e}
.ptm-cap-bar.over > i{background:#ef4444}
.ptm-cap-text{font-size:11px;color:var(--t3);font-family:var(--font-b);min-width:64px;text-align:right}
.ptm-slot-group-body{display:none;border-top:1px solid var(--border)}
.ptm-slot-group.open .ptm-slot-group-body{display:block}
.ptm-slot-group-body table{margin:0}
.ptm-slot-group-body .ptm-empty{padding:20px;font-size:12px}
.ptm-grp-pill{display:inline-block;padding:2px 8px;border-radius:20px;background:rgba(156,163,175,.15);color:var(--t3);font-size:10px;font-weight:700;margin-left:6px}
</style>

<div class="content-wrapper"><section class="content"><div class="ptm-wrap">
<div class="ptm-header">
  <div class="ptm-title"><i class="fa fa-users"></i> RSVPs · <?= htmlspecialchars($ptm['title'] ?? '') ?></div>
  <ol class="ptm-breadcrumb">
    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
    <li><a href="<?= base_url('ptm') ?>">PTM</a></li>
    <li><?= htmlspecialchars($ptm['title'] ?? 'RSVPs') ?></li>
  </ol>
</div>

<div class="ptm-card">
  <div class="ptm-card-title">Meeting summary</div>
  <div class="ptm-detail-row"><span class="k">Date</span><span class="v"><?= htmlspecialchars($ptm['date'] ?? '—') ?></span></div>
  <div class="ptm-detail-row"><span class="k">Class / Section</span><span class="v"><?= htmlspecialchars(($ptm['sectionKey'] ?? 'ALL') === 'ALL' ? 'All sections' : (($ptm['className'] ?? '') . ' ' . ($ptm['section'] ?? ''))) ?></span></div>
  <div class="ptm-detail-row"><span class="k">Location</span><span class="v"><?= htmlspecialchars($ptm['location'] ?? '—') ?></span></div>
  <div class="ptm-detail-row"><span class="k">Status</span><span class="v"><?= htmlspecialchars(strtoupper($ptm['status'] ?? '')) ?></span></div>
  <div class="ptm-detail-row"><span class="k">Slots</span><span class="v"><?= count($slots) ?></span></div>
  <?php if (!empty($slots)): ?>
  <div class="ptm-slots">
    <?php foreach ($slots as $s): ?>
      <div class="ptm-slot-chip"><?= htmlspecialchars($ptm_to12h($s['startTime'] ?? '') . ' – ' . $ptm_to12h($s['endTime'] ?? '')) ?> · <?= htmlspecialchars($s['teacherName'] ?: 'Any teacher') ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="ptm-card">
  <div class="ptm-card-title">Parent responses</div>
  <div class="ptm-stats" id="rsvpStats">
    <div class="ptm-stat"><div class="num" id="cConfirmed">0</div><div class="lbl">Confirmed</div></div>
    <div class="ptm-stat"><div class="num" id="cAttended">0</div><div class="lbl">Attended</div></div>
    <div class="ptm-stat"><div class="num" id="cDeclined">0</div><div class="lbl">Declined</div></div>
    <div class="ptm-stat"><div class="num" id="cPending">0</div><div class="lbl">Pending</div></div>
  </div>
  <div id="rsvpGroups">
    <div class="ptm-empty"><i class="fa fa-spinner fa-spin"></i><br>Loading…</div>
  </div>
</div>
</div></section></div>

<div id="ptmToast" class="ptm-toast"></div>

<script>
(function(){
  const PTM_ID = <?= json_encode($ptmId) ?>;
  // Slot defs come from the PTM doc — used to build groups with expected
  // capacity even when no RSVP has landed in a slot yet.
  const PTM_SLOTS = <?= json_encode(array_map(function ($s, $i) {
      return [
          'slotIndex'   => isset($s['slotIndex']) ? (int) $s['slotIndex'] : $i,
          'startTime'   => (string) ($s['startTime']   ?? ''),
          'endTime'     => (string) ($s['endTime']     ?? ''),
          'teacherId'   => (string) ($s['teacherId']   ?? ''),
          'teacherName' => (string) ($s['teacherName'] ?? ''),
          'capacity'    => isset($s['capacity']) ? (int) $s['capacity'] : 1,
      ];
  }, $slots, array_keys($slots))) ?>;

  const $ = (s,el)=>(el||document).querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = (msg, kind='success') => { const t=$('#ptmToast'); t.className='ptm-toast '+kind; t.textContent=msg; setTimeout(()=>t.style.display='none',3000); };
  // "HH:MM" (24h) → "h:mm AM/PM" — pure display formatting in IST
  // (values are wall-clock strings, no timezone conversion).
  const to12h = hm => {
    if (!hm) return '';
    const m = /^(\d{1,2}):(\d{2})$/.exec(String(hm).trim());
    if (!m) return hm;
    let h = parseInt(m[1], 10);
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return `${h}:${m[2]} ${ap}`;
  };
  const fmtDate = ts => {
    if (!ts) return '—';
    const ms = typeof ts === 'object' && ts._seconds ? ts._seconds * 1000 : (typeof ts === 'number' ? ts : Date.parse(ts));
    if (!ms || isNaN(ms)) return '—';
    return new Date(ms).toLocaleString('en-IN', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
  };
  // Status badge — covers both legacy (confirmed/attended/declined/no-show/
  // pending) and Phase-A (applied/delivered/no-show/declined) vocabulary
  // so the page reads consistently regardless of which write path
  // produced the RSVP.
  const statusBadge = s => {
    const v = (s || 'pending').toLowerCase();
    const cls =
        v === 'confirmed' || v === 'applied'   ? 'ptm-badge-blue'
      : v === 'attended'  || v === 'delivered' ? 'ptm-badge-green'
      : v === 'declined'                       ? 'ptm-badge-rose'
      : v === 'no-show'                        ? 'ptm-badge-rose'
      :                                          'ptm-badge-gray';
    return `<span class="ptm-badge ${cls}">${esc(v.toUpperCase())}</span>`;
  };

  // Reader: flatten an RSVP doc into one virtual row per booking. Three
  // mutually-exclusive branches; exactly one returns rows.
  //   1. bookings[] non-empty (Round-3) → one row per booking.
  //   2. Phase-A flat shape (root status in the new vocabulary AND no
  //      bookings[]) → one row from root fields. Phase-A doesn't model
  //      slots, so slotIndex/slotStartTime/slotEndTime stay blank.
  //   3. Legacy single-RSVP shape (Round-1/2 — slot fields at root, no
  //      bookings[], status in the legacy vocab) → one row from root.
  // Order matters: branch 1 wins if both bookings[] and a Phase-A root
  // status are present (transient dual-write window). Bookings carry
  // richer per-slot data; the root mirror is a fallback.
  const PHASE_A_STATUSES = ['applied', 'delivered', 'no-show', 'declined'];

  function flattenBookings(rsvpDoc){
    const list = Array.isArray(rsvpDoc.bookings) ? rsvpDoc.bookings : [];
    if (list.length > 0) {
      return list.map(b => ({
        slotIndex:      typeof b.slotIndex === 'number' ? b.slotIndex : -1,
        slotStartTime:  b.slotStartTime || '',
        slotEndTime:    b.slotEndTime   || '',
        teacherId:      b.teacherId     || '',
        teacherName:    b.teacherName   || '',
        status:         b.status        || 'pending',
        note:           b.note          || '',
        respondedAt:    b.respondedAt   || rsvpDoc.respondedAt,
        queueNumber:    typeof rsvpDoc.queueNumber === 'number' ? rsvpDoc.queueNumber : 0,
        studentId:   rsvpDoc.studentId   || '',
        studentName: rsvpDoc.studentName || '',
        className:   rsvpDoc.className   || '',
        section:     rsvpDoc.section     || '',
        rollNo:      rsvpDoc.rollNo      || '',
        parentName:  rsvpDoc.parentName  || '',
        parentPhone: rsvpDoc.parentPhone || '',
      }));
    }

    // Phase-A flat shape — root status is one of the new vocabulary AND
    // there's no bookings array. Render as one row.
    //
    // slotIndex is set to 0 (not -1) so the row groups under the legacy
    // single-slot mirror that admin save() always emits for Phase-B/C
    // PTMs (one slot covering the full window). Without this the row
    // would land in the trailing "Unassigned" group with the misleading
    // "slot was edited or removed" sublabel — even though the PTM is
    // perfectly valid; it's just section-wise instead of slot-based.
    const rootStatus = String(rsvpDoc.status || '').toLowerCase();
    if (PHASE_A_STATUSES.indexOf(rootStatus) !== -1) {
      return [{
        slotIndex:     0,
        slotStartTime: '',
        slotEndTime:   '',
        teacherId:     rsvpDoc.teacherId     || '',
        teacherName:   rsvpDoc.teacherName   || '',
        status:        rootStatus,
        note:          rsvpDoc.note          || '',
        respondedAt:   rsvpDoc.appliedAt || rsvpDoc.respondedAt,
        queueNumber:   typeof rsvpDoc.queueNumber === 'number' ? rsvpDoc.queueNumber : 0,
        studentId:     rsvpDoc.studentId     || '',
        studentName:   rsvpDoc.studentName   || '',
        className:     rsvpDoc.className     || '',
        section:       rsvpDoc.section       || '',
        rollNo:        rsvpDoc.rollNo        || '',
        parentName:    rsvpDoc.parentName    || '',
        parentPhone:   rsvpDoc.parentPhone   || '',
      }];
    }

    // Legacy single-RSVP shape (slot-based, Round 1/2) — fabricate one row.
    const hasLegacy = (rsvpDoc.teacherId && rsvpDoc.teacherId.length)
                   || (rsvpDoc.slotStartTime && rsvpDoc.slotStartTime.length)
                   || (typeof rsvpDoc.slotIndex === 'number' && rsvpDoc.slotIndex >= 0);
    if (!hasLegacy) return [];
    return [{
      slotIndex:     typeof rsvpDoc.slotIndex === 'number' ? rsvpDoc.slotIndex : -1,
      slotStartTime: rsvpDoc.slotStartTime || '',
      slotEndTime:   rsvpDoc.slotEndTime   || '',
      teacherId:     rsvpDoc.teacherId     || '',
      teacherName:   rsvpDoc.teacherName   || '',
      status:        rsvpDoc.status        || 'pending',
      note:          rsvpDoc.note          || '',
      respondedAt:   rsvpDoc.respondedAt,
      queueNumber:   typeof rsvpDoc.queueNumber === 'number' ? rsvpDoc.queueNumber : 0,
      studentId:     rsvpDoc.studentId     || '',
      studentName:   rsvpDoc.studentName   || '',
      className:     rsvpDoc.className     || '',
      section:       rsvpDoc.section       || '',
      rollNo:        rsvpDoc.rollNo        || '',
      parentName:    rsvpDoc.parentName    || '',
      parentPhone:   rsvpDoc.parentPhone   || '',
    }];
  }

  // Stable group key — slotIndex when known, otherwise compose from
  // start/end+teacher. Bookings whose slot was deleted from the PTM
  // since they responded land in the "Unassigned" group.
  function groupKey(r){
    if (typeof r.slotIndex === 'number' && r.slotIndex >= 0) return `idx:${r.slotIndex}`;
    if (r.slotStartTime || r.slotEndTime || r.teacherId) {
      return `tw:${r.slotStartTime||''}|${r.slotEndTime||''}|${r.teacherId||''}`;
    }
    return 'unassigned';
  }
  function slotGroupKey(s){ return `idx:${s.slotIndex}`; }

  function renderRsvpRow(r){
    return `<tr>
      <td><b>${esc(r.studentName||'')}</b><br><small style="color:var(--t3)">${esc(r.studentId||'')}</small></td>
      <td>${esc((r.className||'') + ' ' + (r.section||''))}${r.rollNo?` · #${esc(r.rollNo)}`:''}</td>
      <td>${esc(r.parentName||'')}<br><small style="color:var(--t3)">${esc(r.parentPhone||'')}</small></td>
      <td>${statusBadge(r.status)}</td>
      <td>${esc(r.note||'')}</td>
      <td><small>${fmtDate(r.respondedAt)}</small></td>
    </tr>`;
  }

  function renderGroup(idx, label, sublabel, capacity, rsvps){
    // Counts toward filling the slot: anyone who has committed or
    // already met. Phase-A "applied"/"delivered" map onto the legacy
    // "confirmed"/"attended" semantics. Pending/declined/no-show do
    // not count because they haven't / won't show up.
    const confirmedish = rsvps.filter(r =>
         r.status === 'confirmed' || r.status === 'attended'
      || r.status === 'applied'   || r.status === 'delivered'
    ).length;
    const cap = Math.max(0, capacity || 0);
    const pct = cap > 0 ? Math.min(100, Math.round((confirmedish / cap) * 100)) : 0;
    const barCls = cap === 0 ? '' : (confirmedish > cap ? 'over' : (confirmedish === cap ? 'full' : ''));
    const capText = cap === 0 ? `${confirmedish} responded` : `${confirmedish} / ${cap} confirmed`;
    const body = rsvps.length === 0
      ? '<div class="ptm-empty"><i class="fa fa-inbox"></i><br>No responses for this slot yet.</div>'
      : `<table class="ptm-table">
           <thead><tr><th>Student</th><th>Class</th><th>Parent</th><th>Status</th><th>Note</th><th>Responded</th></tr></thead>
           <tbody>${rsvps.map(renderRsvpRow).join('')}</tbody>
         </table>`;
    return `<div class="ptm-slot-group ${rsvps.length ? 'open' : ''}">
      <div class="ptm-slot-group-head" onclick="this.parentElement.classList.toggle('open')">
        <div class="left">
          <span class="num">${esc(idx)}</span>
          <span class="when">${esc(label)}</span>
          <span class="who">${esc(sublabel)}</span>
        </div>
        <div class="right">
          <div class="ptm-cap-bar ${barCls}"><i style="width:${pct}%"></i></div>
          <div class="ptm-cap-text">${esc(capText)}</div>
          <i class="fa fa-chevron-right caret"></i>
        </div>
      </div>
      <div class="ptm-slot-group-body">${body}</div>
    </div>`;
  }

  async function loadRsvps(){
    try {
      const res = await fetch('<?= base_url('ptm/get_rsvps/') ?>' + encodeURIComponent(PTM_ID), {credentials:'same-origin'});
      const data = await res.json();
      if (!data || data.success === false) {
        toast(data && data.message || 'Failed to load', 'error');
        return;
      }
      const docs  = (data.data && data.data.rsvps) || [];
      // Round-3: each parent doc may carry multiple bookings. Counters and
      // slot grouping operate on individual bookings, so flatten first.
      const rows = docs.flatMap(flattenBookings);

      // Map Phase-A vocabulary onto the four legacy display buckets so
      // the page reads consistently regardless of which app build wrote
      // the RSVP. applied → confirmed, delivered → attended. no-show is
      // intentionally not surfaced in any of the four cards (it's a
      // teacher-finalized non-attendance state — neither pending nor
      // attended); add a fifth card if you want it visible.
      const counts = { confirmed:0, attended:0, declined:0, pending:0 };
      rows.forEach(r => {
        const s = String(r.status || 'pending').toLowerCase();
        if      (s === 'confirmed' || s === 'applied')   counts.confirmed++;
        else if (s === 'attended'  || s === 'delivered') counts.attended++;
        else if (s === 'declined')                       counts.declined++;
        else if (s === 'no-show')                        { /* not displayed */ }
        else                                             counts.pending++;
      });
      $('#cConfirmed').textContent = counts.confirmed;
      $('#cAttended').textContent  = counts.attended;
      $('#cDeclined').textContent  = counts.declined;
      $('#cPending').textContent   = counts.pending;

      // Bucket booking rows by group key so each PTM slot owns its own list.
      const buckets = new Map();
      rows.forEach(r => {
        const k = groupKey(r);
        if (!buckets.has(k)) buckets.set(k, []);
        buckets.get(k).push(r);
      });

      const wrap = $('#rsvpGroups');
      const blocks = [];
      // Render a group for every defined slot, in order. Slots with zero
      // RSVPs still show so admins can spot under-booked windows.
      PTM_SLOTS.forEach((s, i) => {
        const k = slotGroupKey(s);
        const rsvps = buckets.get(k) || [];
        buckets.delete(k);
        const label = `${to12h(s.startTime) || '??:??'} – ${to12h(s.endTime) || '??:??'}`;
        const teach = s.teacherName ? esc(s.teacherName) : 'Any teacher';
        const sub = teach + (s.capacity ? ` <span class="ptm-grp-pill">cap ${s.capacity}</span>` : '');
        blocks.push(renderGroup(i + 1, label, sub, s.capacity, rsvps));
      });
      // Anything left over targeted a slot that no longer exists on the
      // PTM doc (post-edit). Surface as "Unassigned" so the data isn't lost.
      let trailingIdx = PTM_SLOTS.length;
      for (const [k, rsvps] of buckets.entries()) {
        trailingIdx++;
        const label = rsvps[0]?.slotStartTime
          ? `${to12h(rsvps[0].slotStartTime)} – ${to12h(rsvps[0].slotEndTime) || '??:??'}`
          : 'Unassigned';
        const sub = (rsvps[0]?.teacherName || 'No teacher') + ' · slot was edited or removed';
        blocks.push(renderGroup('?', label, sub, 0, rsvps));
      }

      wrap.innerHTML = blocks.length ? blocks.join('') : '<div class="ptm-empty"><i class="fa fa-inbox"></i><br>No slots configured for this PTM.</div>';
    } catch (e) {
      toast('Failed to load', 'error');
    }
  }

  loadRsvps();
})();
</script>
