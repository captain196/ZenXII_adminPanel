<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Applications tab-shell (IA reorg 2026-07 — Phase 3).
 *
 * Renders a pill tab-bar over ISOLATED <iframe> panes, one per view. Each pane
 * hosts an existing page in ?embed=1 mode (chrome-less). Because every tab is
 * its own document, the hosted pages keep 100% of their original inline JS,
 * element IDs and CSRF state — NO DOM merge, no collisions. Mirrors the proven
 * attendance/_shell.php mechanism, styled for the CRM (gold accent).
 */
$shellTabs = [
    ['key' => 'table',  'label' => 'Table',  'icon' => 'fa-table',   'url' => base_url('sis/applications') . '?embed=1'],
    ['key' => 'board',  'label' => 'Board',   'icon' => 'fa-columns', 'url' => base_url('sis/pipeline') . '?embed=1'],
    ['key' => 'funnel', 'label' => 'Funnel', 'icon' => 'fa-filter',  'url' => base_url('sis/admission_leads') . '?embed=1'],
];
$activeKey = 'table';
?>
<style>
html { font-size:16px !important; }
.ac-shell { padding:22px 22px 26px; }
.ac-shell-head { display:flex; align-items:center; gap:14px; margin-bottom:16px; }
.ac-shell-ico { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center;
    background:var(--gold); color:#fff; font-size:20px; flex:0 0 auto; box-shadow:0 0 18px var(--gold-glow); }
.ac-shell-title { font-size:20px; font-weight:700; color:var(--t1); font-family:var(--font-d); line-height:1.15; }
.ac-shell-sub { font-size:12.5px; color:var(--t3); margin-top:2px; }
.ac-shell-newbtn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:9px; border:1px solid var(--gold);
    background:var(--gold); color:#fff; font-size:13px; font-weight:600; cursor:pointer; font-family:var(--font-b); box-shadow:0 2px 10px var(--gold-ring); flex:0 0 auto; }
.ac-shell-newbtn:hover { background:var(--gold2); }

.ac-shell-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.ac-shell-tab { display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none; min-height:42px;
    font-size:13.5px; font-weight:600; color:var(--t2); background:var(--bg2);
    border:1px solid var(--border); border-radius:10px; padding:9px 16px; transition:all var(--ease); font-family:var(--font-b); }
.ac-shell-tab:hover { border-color:var(--gold); color:var(--gold); }
.ac-shell-tab.active { background:var(--gold); border-color:var(--gold); color:#fff; box-shadow:0 2px 10px var(--gold-ring); }
.ac-shell-tab:focus-visible { outline:2px solid var(--gold); outline-offset:2px; }
.ac-shell-tab .fa { font-size:13px; opacity:.92; }

/* No fixed frame — each pane's iframe is auto-sized to its content (JS), so
   there's a SINGLE page scroll and the list itself never scrolls. */
.ac-shell-stage { position:relative; }
.ac-shell-pane { display:none; position:relative; min-height:0; }
.ac-shell-pane.active { display:block; }
.ac-shell-frame { width:100%; border:0; display:block; background:transparent; min-height:0; }
/* While a modal is open inside the iframe, pin it to the viewport so the
   modal's position:fixed aligns with the real viewport (not the tall iframe). */
.ac-shell-frame.shell-frame-pinned { position:fixed !important; top:0 !important; left:0 !important; width:100% !important; height:100vh !important; z-index:4000 !important; }
/* Shell-level toast: a child iframe forwards its page toast here so it shows in
   the real viewport instead of off-screen atop the tall auto-height iframe. */
.ac-shell-toast { position:fixed; top:18px; left:50%; transform:translateX(-50%) translateY(-10px); z-index:5000; padding:11px 18px; border-radius:9px; font-size:13px; font-weight:600; box-shadow:0 6px 24px rgba(0,0,0,.18); opacity:0; transition:opacity .18s, transform .18s; pointer-events:none; max-width:90vw; }
.ac-shell-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
.ac-shell-toast.success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-shell-toast.error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.ac-shell-toast.info { background:var(--bg2); color:var(--t1); border:1px solid var(--border); }
.ac-shell-spin { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:var(--bg2); z-index:2; }
.ac-shell-spin.hidden { display:none; }
.ac-shell-spin .sp { width:30px; height:30px; border-radius:50%; border:3px solid var(--border); border-top-color:var(--gold); animation:acSpin .7s linear infinite; }
@keyframes acSpin { to { transform:rotate(360deg); } }
@media (prefers-reduced-motion:reduce){ .ac-shell-spin .sp { animation:none; } }
.ac-shell-error { position:absolute; inset:0; z-index:3; display:none; flex-direction:column; align-items:center; justify-content:center;
    gap:14px; padding:26px; text-align:center; background:var(--bg2); }
.ac-shell-error.show { display:flex; }
.ac-shell-error .em { font-size:26px; }
.ac-shell-error .msg { font-size:14px; color:var(--t2); max-width:44ch; }
.ac-shell-retry { font-size:13.5px; font-weight:700; color:#fff; background:var(--gold); border:0; border-radius:9px; padding:9px 18px; cursor:pointer; min-height:40px; }
.ac-shell-retry:hover { background:var(--gold2); }
</style>

<div class="content-wrapper">
<div class="ac-shell">

    <div class="ac-shell-head">
        <div class="ac-shell-ico"><i class="fa fa-file-text-o"></i></div>
        <div style="flex:1;min-width:0;">
            <div class="ac-shell-title">Applications</div>
            <div class="ac-shell-sub">One workspace — switch between Table, Board and Funnel views</div>
        </div>
    </div>

    <div class="ac-shell-tabs" role="tablist" aria-label="Applications views">
        <?php foreach ($shellTabs as $t): $act = $t['key'] === $activeKey; ?>
            <button type="button" class="ac-shell-tab<?= $act ? ' active' : '' ?>"
                    role="tab" id="shtab-<?= html_escape($t['key']) ?>"
                    aria-controls="shpane-<?= html_escape($t['key']) ?>"
                    aria-selected="<?= $act ? 'true' : 'false' ?>"
                    tabindex="<?= $act ? '0' : '-1' ?>"
                    data-key="<?= html_escape($t['key']) ?>">
                <i class="fa <?= html_escape($t['icon']) ?>" aria-hidden="true"></i>
                <span><?= html_escape($t['label']) ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="ac-shell-stage">
        <?php foreach ($shellTabs as $t): $act = $t['key'] === $activeKey; ?>
            <div class="ac-shell-pane<?= $act ? ' active' : '' ?>" id="shpane-<?= html_escape($t['key']) ?>"
                 role="tabpanel" aria-labelledby="shtab-<?= html_escape($t['key']) ?>"
                 aria-hidden="<?= $act ? 'false' : 'true' ?>" data-key="<?= html_escape($t['key']) ?>">
                <div class="ac-shell-spin"><div class="sp"></div></div>
                <div class="ac-shell-error" role="alert">
                    <span class="em" aria-hidden="true">&#9888;&#65039;</span>
                    <span class="msg">This view is taking longer than expected to load.</span>
                    <button type="button" class="ac-shell-retry">Retry</button>
                </div>
                <iframe class="ac-shell-frame" title="<?= html_escape($t['label']) ?>"
                        data-src="<?= html_escape($t['url']) ?>"></iframe>
            </div>
        <?php endforeach; ?>
    </div>

</div>
</div>

<script>
(function(){
    var root = document.querySelector('.ac-shell');
    if (!root) return;
    var tabs  = Array.prototype.slice.call(root.querySelectorAll('.ac-shell-tab'));
    var panes = Array.prototype.slice.call(root.querySelectorAll('.ac-shell-pane'));
    var LOAD_TIMEOUT = 15000;

    // Auto-size an iframe to its content so there is ONE page scroll (no inner
    // scroll). Same-origin, so we can read the child's scrollHeight directly,
    // and re-fit whenever the child re-renders (ResizeObserver on its <body>).
    function fitFrame(f){
        try {
            var doc = f.contentWindow.document;
            var h = Math.max(doc.body ? doc.body.scrollHeight : 0, doc.documentElement ? doc.documentElement.scrollHeight : 0);
            if (h > 0) {
                var target = h + 8;
                var cur = parseInt(f.style.height, 10) || 0;
                // Only resize on a real change — avoids a sub-pixel echo ping-ponging
                // with the ResizeObserver and creeping the height upward forever.
                if (Math.abs(target - cur) > 2) f.style.height = target + 'px';
            }
        } catch(e){}
    }
    function observeFrame(f){
        try {
            var doc = f.contentWindow.document;
            if (f._ro){ try { f._ro.disconnect(); } catch(e){} f._ro = null; }
            if (window.ResizeObserver && doc.body){
                f._ro = new ResizeObserver(function(){ fitFrame(f); });
                f._ro.observe(doc.body);
            } else if (!f._iv){
                f._iv = setInterval(function(){ fitFrame(f); }, 700);
            }
            // Late refits catch async renders (fetch → table paint).
            setTimeout(function(){ fitFrame(f); }, 300);
            setTimeout(function(){ fitFrame(f); }, 900);
            setTimeout(function(){ fitFrame(f); }, 1800);
        } catch(e){}
    }
    window.addEventListener('resize', function(){ document.querySelectorAll('.ac-shell-frame').forEach(function(f){ if (f.getAttribute('src')) fitFrame(f); }); });

    function paneFor(key){ return panes.filter(function(p){ return p.getAttribute('data-key') === key; })[0]; }
    function tabFor(key){  return tabs.filter(function(t){ return t.getAttribute('data-key') === key; })[0]; }

    function loadFrame(pane, force){
        if (!pane) return;
        var f = pane.querySelector('.ac-shell-frame'); if (!f) return;
        var spin = pane.querySelector('.ac-shell-spin');
        var err  = pane.querySelector('.ac-shell-error');
        if (f.getAttribute('src') && !force){
            var done=false; try{ done = f.contentWindow && f.contentWindow.document && f.contentWindow.document.readyState==='complete'; }catch(e){}
            if (done && spin) spin.classList.add('hidden');
            return;
        }
        if (err) err.classList.remove('show');
        if (spin) spin.classList.remove('hidden');
        var settled=false;
        var wd = setTimeout(function(){ if(settled)return; settled=true; if(spin)spin.classList.add('hidden'); if(err)err.classList.add('show'); }, LOAD_TIMEOUT);
        f.onload  = function(){ settled=true; clearTimeout(wd); if(spin)spin.classList.add('hidden'); if(err)err.classList.remove('show'); fitFrame(f); observeFrame(f); };
        f.onerror = function(){ settled=true; clearTimeout(wd); if(spin)spin.classList.add('hidden'); if(err)err.classList.add('show'); };
        var src = f.getAttribute('data-src');
        if (force){ var sep = src.indexOf('?')===-1?'?':'&'; src = src + sep + '_retry=' + Date.now(); }
        f.style.height = '440px'; // load-time placeholder; fitFrame() sets exact height on load
        f.setAttribute('src', src);
    }

    root.querySelectorAll('.ac-shell-retry').forEach(function(btn){
        btn.addEventListener('click', function(){ loadFrame(btn.closest('.ac-shell-pane'), true); });
    });

    function setState(key){
        tabs.forEach(function(t){ var on = t.getAttribute('data-key')===key;
            t.classList.toggle('active', on); t.setAttribute('aria-selected', on?'true':'false'); t.setAttribute('tabindex', on?'0':'-1'); });
        panes.forEach(function(p){ var on = p.getAttribute('data-key')===key;
            p.classList.toggle('active', on); p.setAttribute('aria-hidden', on?'false':'true'); });
    }

    function activate(key, push, focusTab, force){
        var target = paneFor(key);
        if (!target){ key = tabs[0] && tabs[0].getAttribute('data-key'); target = paneFor(key); }
        if (!target) return;
        setState(key);
        // force=true → re-fetch the view so a change made in ANOTHER tab
        // (e.g. approving in Table) is reflected when you switch here. The
        // initial activation uses force=false (the preloaded frame is fresh).
        loadFrame(target, !!force);
        if (focusTab){ var tb = tabFor(key); if (tb) tb.focus(); }
        if (push && key){ try { history.pushState({shellTab:key}, '', '#'+key); } catch(e){} }
    }

    tabs.forEach(function(t){ t.addEventListener('click', function(){ activate(t.getAttribute('data-key'), true, false, true); }); });

    // A modal opened/closed inside a child iframe → pin/unpin that iframe to the
    // viewport so its position:fixed modal centers on the real viewport.
    window.addEventListener('message', function(ev){
        if (!ev.data || typeof ev.data.shellModal === 'undefined') return;
        var f = null;
        root.querySelectorAll('.ac-shell-frame').forEach(function(fr){ try { if (fr.contentWindow === ev.source) f = fr; } catch(e){} });
        if (!f){ var ap = root.querySelector('.ac-shell-pane.active'); f = ap && ap.querySelector('.ac-shell-frame'); }
        if (!f) return;
        var pane = f.closest('.ac-shell-pane');
        if (ev.data.shellModal){
            if (pane) pane.style.minHeight = f.offsetHeight + 'px';   // freeze layout (no page jump)
            f.classList.add('shell-frame-pinned');
        } else {
            f.classList.remove('shell-frame-pinned');
            if (pane) pane.style.minHeight = '';
            fitFrame(f);
        }
    });

    // A child iframe forwarded a page toast → show it at the shell (viewport) level.
    var toastEl = null, toastTimer = null;
    window.addEventListener('message', function(ev){
        if (!ev.data || !ev.data.shellToast) return;
        var t = ev.data.shellToast;
        if (!toastEl){
            toastEl = document.createElement('div');
            toastEl.className = 'ac-shell-toast';
            toastEl.setAttribute('role', 'status');
            toastEl.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastEl);
        }
        toastEl.className = 'ac-shell-toast ' + (t.type === 'error' ? 'error' : t.type === 'success' ? 'success' : 'info');
        toastEl.textContent = String(t.msg || '');
        // reflow then show (so the transition runs)
        void toastEl.offsetWidth;
        toastEl.classList.add('show');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function(){ toastEl.classList.remove('show'); }, 3800);
    });

    var tablist = root.querySelector('.ac-shell-tabs');
    if (tablist){
        tablist.addEventListener('keydown', function(e){
            var idx = tabs.indexOf(document.activeElement); if (idx === -1) return;
            var ni = null;
            if (e.key==='ArrowRight'||e.key==='ArrowDown') ni=(idx+1)%tabs.length;
            else if (e.key==='ArrowLeft'||e.key==='ArrowUp') ni=(idx-1+tabs.length)%tabs.length;
            else if (e.key==='Home') ni=0; else if (e.key==='End') ni=tabs.length-1;
            if (ni===null) return; e.preventDefault();
            activate(tabs[ni].getAttribute('data-key'), true, true, true);
        });
    }

    var initial = (location.hash||'').replace('#','').trim();
    if (!initial){ var a = root.querySelector('.ac-shell-pane.active'); initial = a ? a.getAttribute('data-key') : ''; }
    activate(initial, false);

    window.addEventListener('popstate', function(){ var k=(location.hash||'').replace('#','').trim(); if(k&&paneFor(k)){ setState(k); loadFrame(paneFor(k),true); } });
    window.addEventListener('hashchange', function(){ var k=(location.hash||'').replace('#','').trim(); if(k&&paneFor(k)){ setState(k); loadFrame(paneFor(k),true); } });

    function preloadSequentially(){
        var queue = panes.slice();
        (function next(){
            var p = queue.shift(); if (!p) return;
            var f = p.querySelector('.ac-shell-frame');
            if (!f || f.getAttribute('src')){ next(); return; }
            var spin = p.querySelector('.ac-shell-spin');
            var err  = p.querySelector('.ac-shell-error');
            var settled=false;
            var wd = setTimeout(function(){ if(settled)return; settled=true; if(spin)spin.classList.add('hidden'); if(err)err.classList.add('show'); next(); }, LOAD_TIMEOUT);
            f.onload  = function(){ settled=true; clearTimeout(wd); if(spin)spin.classList.add('hidden'); if(err)err.classList.remove('show'); fitFrame(f); observeFrame(f); next(); };
            f.onerror = function(){ settled=true; clearTimeout(wd); if(spin)spin.classList.add('hidden'); if(err)err.classList.add('show'); next(); };
            f.style.height = '440px'; // placeholder until fitFrame() on load
            f.setAttribute('src', f.getAttribute('data-src'));
        })();
    }
    var whenIdle = window.requestIdleCallback || function(fn){ return setTimeout(fn, 600); };
    var activePane  = paneFor(initial) || panes[0];
    var activeFrame = activePane ? activePane.querySelector('.ac-shell-frame') : null;
    if (activeFrame){
        activeFrame.addEventListener('load', function(){ whenIdle(preloadSequentially); });
        whenIdle(function(){ if (activeFrame.getAttribute('src')) whenIdle(preloadSequentially); });
    }
})();
</script>
