<?php
// ================================================================
// notice_bell_partial.php
// Drop this into your include/header.php where the notification
// bell icon appears. Replace whatever existing bell HTML you have.
//
// Requires: fetch_recent_notices() method in NoticeAnnouncement.php
//           (already implemented in your controller ✅)
//
// Drop-in: Replace your existing bell icon markup with this file:
//   <?php $this->load->view('include/notice_bell_partial'); ?>
// ================================================================
?>

<style>
/* ── Notice Bell Widget ── scoped to .nb-* ── */
.nb-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
}

/* Bell button */
.nb-btn {
  position: relative;
  width: 38px; height: 38px;
  border-radius: 10px;
  background: rgba(15,118,110,0.08);
  border: 1px solid rgba(15,118,110,0.18);
  color: #0f766e;
  font-size: 15px;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .2s, border-color .2s, transform .15s;
  outline: none;
}
.nb-btn:hover {
  background: rgba(15,118,110,0.15);
  transform: scale(1.05);
}
.nb-btn.active {
  background: rgba(15,118,110,0.18);
  border-color: rgba(15,118,110,0.35);
}

/* Unread badge */
.nb-badge {
  position: absolute;
  top: -5px; right: -5px;
  min-width: 18px; height: 18px;
  border-radius: 9px;
  background: #E05C6F;
  color: #fff;
  font-size: 10px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  padding: 0 4px;
  font-family: 'JetBrains Mono', monospace;
  border: 2px solid #0c1e38;
  animation: nbPulse 2.5s ease infinite;
  pointer-events: none;
}
.nb-badge[data-count="0"] { display: none; }

@keyframes nbPulse {
  0%,90%,100% { transform: scale(1); }
  95%          { transform: scale(1.2); }
}

/* Dropdown panel */
.nb-panel {
  display: none;
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  width: 340px;
  background: #0c1e38;
  border: 1px solid rgba(15,118,110,0.16);
  border-radius: 14px;
  box-shadow: 0 16px 48px rgba(0,0,0,0.55);
  z-index: 9999;
  overflow: hidden;
  animation: nbSlideIn .22s cubic-bezier(.34,1.56,.64,1);
}
.nb-panel.open { display: block; }

@keyframes nbSlideIn {
  from { opacity: 0; transform: translateY(-8px) scale(.97); }
  to   { opacity: 1; transform: translateY(0)  scale(1); }
}

/* Panel header */
.nb-panel-head {
  padding: 14px 18px 12px;
  border-bottom: 1px solid rgba(15,118,110,0.09);
  display: flex; align-items: center; justify-content: space-between;
}
.nb-panel-title {
  font-family: 'Fraunces', serif;
  font-size: 15px; font-weight: 700;
  color: #fff; letter-spacing: -.2px;
}
.nb-panel-title em { font-style: italic; color: #0f766e; }
.nb-mark-read {
  font-size: 10px; font-family: 'JetBrains Mono', monospace;
  color: #7A6E54; background: none; border: none;
  cursor: pointer; padding: 3px 6px; border-radius: 5px;
  transition: color .2s, background .2s;
}
.nb-mark-read:hover { color: #0f766e; background: rgba(15,118,110,0.08); }

/* Notice list */
.nb-list {
  max-height: 320px;
  overflow-y: auto;
}
.nb-list::-webkit-scrollbar { width: 4px; }
.nb-list::-webkit-scrollbar-thumb { background: #0f2a47; border-radius: 4px; }

.nb-item {
  padding: 12px 18px;
  border-bottom: 1px solid rgba(15,118,110,0.06);
  cursor: pointer;
  transition: background .15s;
  display: flex; gap: 12px; align-items: flex-start;
}
.nb-item:last-child { border-bottom: none; }
.nb-item:hover { background: rgba(15,118,110,0.04); }
.nb-item.unread { background: rgba(15,118,110,0.04); }
.nb-item.unread .nb-item-title { color: #fff; }

/* Unread dot */
.nb-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #0f766e;
  flex-shrink: 0; margin-top: 5px;
  box-shadow: 0 0 6px rgba(15,118,110,0.6);
}
.nb-dot.read { background: #0f2a47; box-shadow: none; }

.nb-item-content { flex: 1; min-width: 0; }
.nb-item-title {
  font-size: 13px; font-weight: 600;
  color: #94c9c3;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  margin-bottom: 3px;
}
.nb-item-desc {
  font-size: 11.5px; color: #7A6E54;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  line-height: 1.45;
}
.nb-item-meta {
  display: flex; align-items: center; gap: 6px;
  margin-top: 5px;
}
.nb-item-time {
  font-size: 10px; font-family: 'JetBrains Mono', monospace;
  color: #5A5040;
}
.nb-item-from {
  font-size: 9px; font-family: 'JetBrains Mono', monospace;
  padding: 1px 6px; border-radius: 4px;
  background: rgba(15,118,110,0.10); color: #0f766e;
}

/* Empty state */
.nb-empty {
  padding: 32px 18px;
  text-align: center;
  color: #5A5040;
  font-size: 13px;
}
.nb-empty i { font-size: 28px; display: block; margin-bottom: 10px; opacity: .4; }

/* Loading skeleton */
.nb-skeleton { padding: 12px 18px; border-bottom: 1px solid rgba(15,118,110,0.06); }
.nb-skel-line {
  height: 10px; border-radius: 6px;
  background: linear-gradient(90deg, #0c1e38 25%, #0f2a47 50%, #0c1e38 75%);
  background-size: 200% 100%;
  animation: nbSkelShimmer 1.4s infinite;
  margin-bottom: 6px;
}
@keyframes nbSkelShimmer {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* Panel footer */
.nb-panel-foot {
  padding: 10px 18px;
  border-top: 1px solid rgba(15,118,110,0.09);
}
.nb-view-all {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  width: 100%; padding: 9px;
  border-radius: 8px;
  border: 1px solid rgba(15,118,110,0.16);
  background: transparent; color: #94c9c3;
  font-size: 12px; font-family: 'DM Sans', sans-serif;
  font-weight: 600;
  text-decoration: none;
  transition: all .2s;
}
.nb-view-all:hover {
  background: rgba(15,118,110,0.08);
  border-color: rgba(15,118,110,0.3);
  color: #0f766e;
}
</style>

<div class="nb-wrap" id="nbWrap">
  <!-- Bell button -->
  <button class="nb-btn" id="nbBtn" title="Notices &amp; Announcements">
    <i class="fa fa-bell"></i>
    <span class="nb-badge" id="nbBadge" data-count="0">0</span>
  </button>

  <!-- Dropdown panel -->
  <div class="nb-panel" id="nbPanel">
    <div class="nb-panel-head">
      <div class="nb-panel-title">Notices &amp; <em>Announcements</em></div>
      <button class="nb-mark-read" id="nbMarkRead">
        <i class="fa fa-check"></i> Mark all read
      </button>
    </div>
    <div class="nb-list" id="nbList">
      <!-- Skeleton loaders -->
      <?php for($i=0;$i<3;$i++): ?>
      <div class="nb-skeleton">
        <div class="nb-skel-line" style="width:70%"></div>
        <div class="nb-skel-line" style="width:90%"></div>
        <div class="nb-skel-line" style="width:45%"></div>
      </div>
      <?php endfor; ?>
    </div>
    <div class="nb-panel-foot">
      <a href="<?= base_url('NoticeAnnouncement') ?>" class="nb-view-all">
        <i class="fa fa-list-ul"></i> View All Notices
      </a>
    </div>
  </div>
</div>

<script>
(function(){
  var SITE_URL  = '<?= rtrim(site_url(), '/') ?>';
  var READ_KEY  = 'nb_read_ids_<?= $this->school_name ?? "school" ?>';

  var $btn      = document.getElementById('nbBtn');
  var $panel    = document.getElementById('nbPanel');
  var $list     = document.getElementById('nbList');
  var $badge    = document.getElementById('nbBadge');
  var $markRead = document.getElementById('nbMarkRead');

  var allNotices = [];
  var readIds    = JSON.parse(localStorage.getItem(READ_KEY) || '[]');

  /* ── Open / close ── */
  $btn.addEventListener('click', function(e){
    e.stopPropagation();
    var isOpen = $panel.classList.toggle('open');
    $btn.classList.toggle('active', isOpen);
    if (isOpen) markAllRead();
  });

  document.addEventListener('click', function(e){
    if (!document.getElementById('nbWrap').contains(e.target)) {
      $panel.classList.remove('open');
      $btn.classList.remove('active');
    }
  });

  /* ── Mark all as read ── */
  function markAllRead() {
    allNotices.forEach(function(n){ if(!readIds.includes(n.id)) readIds.push(n.id); });
    localStorage.setItem(READ_KEY, JSON.stringify(readIds));
    updateBadge();
    renderList();
  }

  $markRead.addEventListener('click', function(e){
    e.stopPropagation();
    markAllRead();
  });

  /* ── Badge count ── */
  function updateBadge() {
    var unread = allNotices.filter(function(n){ return !readIds.includes(n.id); }).length;
    $badge.textContent  = unread > 99 ? '99+' : unread;
    $badge.dataset.count = unread;
    $badge.style.display = unread > 0 ? 'flex' : 'none';
  }

  /* ── Render list ── */
  function renderList() {
    if (!allNotices.length) {
      $list.innerHTML =
        '<div class="nb-empty"><i class="fa fa-bell-slash-o"></i>No notices yet</div>';
      return;
    }

    var html = '';
    allNotices.forEach(function(n){
      var isRead = readIds.includes(n.id);
      var ts     = n.Timestamp || n.Time_Stamp || 0;
      var date   = ts ? new Date(ts) : new Date();
      var timeAgo = formatTimeAgo(date);
      var desc   = (n.Description || '').substring(0, 120);
      var toKeys = n['To Id'] ? Object.keys(n['To Id']) : [];
      var toLabel = toKeys.length > 0
        ? toKeys.slice(0,2).join(', ') + (toKeys.length > 2 ? ' +' + (toKeys.length-2) + ' more' : '')
        : 'All';

      html +=
        '<div class="nb-item ' + (isRead ? '' : 'unread') + '" data-id="'+n.id+'">' +
        '<div class="nb-dot ' + (isRead ? 'read' : '') + '"></div>' +
        '<div class="nb-item-content">' +
        '<div class="nb-item-title">' + escHtml(n.Title || 'Untitled') + '</div>' +
        '<div class="nb-item-desc">'  + escHtml(desc) + '</div>' +
        '<div class="nb-item-meta">' +
        '<span class="nb-item-time"><i class="fa fa-clock-o"></i> ' + timeAgo + '</span>' +
        '<span class="nb-item-from">To: ' + escHtml(toLabel) + '</span>' +
        '</div></div></div>';
    });

    $list.innerHTML = html;
  }

  /* ── Fetch from controller ── */
  function fetchNotices() {
    fetch(SITE_URL + '/NoticeAnnouncement/fetch_recent_notices')
      .then(function(r){ return r.json(); })
      .then(function(data){
        allNotices = Array.isArray(data) ? data : [];
        updateBadge();
        renderList();
      })
      .catch(function(){
        $list.innerHTML =
          '<div class="nb-empty" style="color:#E05C6F"><i class="fa fa-exclamation-triangle"></i>Could not load</div>';
      });
  }

  /* ── Helpers ── */
  function escHtml(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function formatTimeAgo(date) {
    var diff = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return Math.floor(diff/60)   + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600)  + 'h ago';
    if (diff < 604800)return Math.floor(diff/86400) + 'd ago';
    return date.toLocaleDateString('en-IN', {day:'numeric', month:'short'});
  }

  /* Init */
  fetchNotices();
  /* Refresh every 2 minutes */
  setInterval(fetchNotices, 120000);

})();
</script>