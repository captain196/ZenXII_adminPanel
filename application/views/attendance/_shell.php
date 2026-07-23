<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Attendance tab-shell (Phase 2 IA consolidation).
 *
 * Renders a pill tab-bar over a set of ISOLATED <iframe> panes. Each pane hosts
 * an existing attendance page in ?embed=1 mode (chrome-less). Because every tab
 * is its own document, the hosted pages keep 100% of their original inline JS,
 * element IDs and CSRF state — there is NO DOM merge and no collision risk.
 *
 * Expects $shell = [
 *   'title'    => string,
 *   'subtitle' => string,
 *   'icon'     => 'fa-...',
 *   'active'   => 'key'            // optional default tab (hash overrides)
 *   'tabs'     => [ ['key'=>, 'label'=>, 'icon'=>'fa-...', 'url'=>, 'badge'=>bool?] ],
 *   'extra_js' => string           // optional raw JS appended (e.g. badge counters)
 * ]
 */
$shellTabs  = $shell['tabs'] ?? [];
$activeKey  = $shell['active'] ?? ($shellTabs[0]['key'] ?? '');
?>
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.1.0">

<style>
/* Shell chrome — scoped to .att-shell so it can't leak into embedded pages. */
.att-shell { --sh-accent:#2f6fbf; }
.att-shell .att-shell-head { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
.att-shell .att-shell-ico { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center;
    background:var(--sh-accent); color:#fff; font-size:20px; flex:0 0 auto; }
.att-shell .att-shell-title { font-size:20px; font-weight:700; color:var(--att-t1,#1b2733); line-height:1.15; }
.att-shell .att-shell-sub { font-size:12.5px; color:var(--att-t3,#6b7a89); margin-top:2px; }

.att-shell .att-shell-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;
    border-bottom:1px solid var(--att-bd,#e4e9ef); padding-bottom:12px; }
.att-shell .att-shell-tab { display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none;
    font-size:13.5px; font-weight:600; color:var(--att-t2,#38444f); background:var(--att-bg2,#f2f5f8);
    border:1px solid var(--att-bd,#e4e9ef); border-radius:10px; padding:9px 15px; transition:all .15s; }
.att-shell .att-shell-tab:hover { border-color:var(--sh-accent); color:var(--sh-accent); }
.att-shell .att-shell-tab.active { background:var(--sh-accent); border-color:var(--sh-accent); color:#fff; }
.att-shell .att-shell-tab { min-height:44px; }
.att-shell .att-shell-tab:focus-visible { outline:2px solid var(--sh-accent); outline-offset:2px; }
.att-shell .att-shell-tab .fa { font-size:13px; opacity:.9; }
.att-shell .att-shell-badge { min-width:19px; height:19px; padding:0 5px; border-radius:10px; font-size:11px; font-weight:800;
    display:none; align-items:center; justify-content:center; background:#e5484d; color:#fff; }
.att-shell .att-shell-tab.active .att-shell-badge { background:#fff; color:var(--sh-accent); }

/* Taller stage (smaller top reserve) + a lower min-height on short/landscape
   viewports so the hosted register isn't crushed behind a third scrollbar. */
/* No fixed frame — each iframe auto-sizes to its content (JS) so the PARENT
   page scrolls as one, like a normal list page (no inner scroll). */
.att-shell .att-shell-stage { position:relative; }
.att-shell .att-shell-pane { display:none; position:relative; }
.att-shell .att-shell-pane.active { display:block; }
.att-shell .att-shell-frame { width:100%; border:0; display:block; background:transparent; min-height:0; }
/* While a modal is open inside the iframe, pin it to the viewport so the modal's
   position:fixed aligns with the real viewport (not the tall auto-height iframe). */
.att-shell .att-shell-frame.shell-frame-pinned { position:fixed !important; top:0 !important; left:0 !important; width:100% !important; height:100vh !important; z-index:4000 !important; }
.att-shell .att-shell-spin { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    background:var(--att-bg1,#fff); z-index:2; }
.att-shell .att-shell-spin.hidden { display:none; }
.att-shell .att-shell-spin .sp { width:30px; height:30px; border-radius:50%; border:3px solid var(--att-bd,#e4e9ef);
    border-top-color:var(--sh-accent); animation:attSpin .7s linear infinite; }
@keyframes attSpin { to { transform:rotate(360deg); } }
@media (prefers-reduced-motion:reduce){ .att-shell .att-shell-spin .sp { animation:none; } }
/* Failure state — replaces an endlessly-spinning frame with a real recovery UI. */
.att-shell .att-shell-error { position:absolute; inset:0; z-index:3; display:none; flex-direction:column;
    align-items:center; justify-content:center; gap:14px; padding:26px; text-align:center; background:var(--att-bg1,#fff); }
.att-shell .att-shell-error.show { display:flex; }
.att-shell .att-shell-error .em { font-size:26px; }
.att-shell .att-shell-error .msg { font-size:14px; color:var(--att-t2,#38444f); max-width:44ch; }
.att-shell .att-shell-retry { font-size:13.5px; font-weight:700; color:#fff; background:var(--sh-accent); border:0;
    border-radius:9px; padding:9px 18px; cursor:pointer; min-height:40px; }
.att-shell .att-shell-retry:hover { filter:brightness(.95); }
.att-shell .att-shell-retry:focus-visible { outline:2px solid var(--sh-accent); outline-offset:2px; }
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="att-shell">

    <div class="att-shell-head">
        <div class="att-shell-ico"><i class="fa <?= html_escape($shell['icon'] ?? 'fa-th-large') ?>"></i></div>
        <div>
            <div class="att-shell-title"><?= html_escape($shell['title'] ?? 'Attendance') ?></div>
            <?php if (!empty($shell['subtitle'])): ?>
                <div class="att-shell-sub"><?= html_escape($shell['subtitle']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="att-shell-tabs" role="tablist" aria-label="<?= html_escape($shell['title'] ?? 'Attendance') ?> sections">
        <?php foreach ($shellTabs as $t): $act = $t['key'] === $activeKey; ?>
            <button type="button" class="att-shell-tab<?= $act ? ' active' : '' ?>"
                    role="tab" id="shtab-<?= html_escape($t['key']) ?>"
                    aria-controls="shpane-<?= html_escape($t['key']) ?>"
                    aria-selected="<?= $act ? 'true' : 'false' ?>"
                    tabindex="<?= $act ? '0' : '-1' ?>"
                    data-key="<?= html_escape($t['key']) ?>">
                <i class="fa <?= html_escape($t['icon'] ?? 'fa-circle-o') ?>" aria-hidden="true"></i>
                <span><?= html_escape($t['label']) ?></span>
                <?php if (!empty($t['badge'])): ?>
                    <span class="att-shell-badge" id="shbadge-<?= html_escape($t['key']) ?>" aria-hidden="true">0</span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="att-shell-stage">
        <?php foreach ($shellTabs as $t): $act = $t['key'] === $activeKey; ?>
            <div class="att-shell-pane<?= $act ? ' active' : '' ?>" id="shpane-<?= html_escape($t['key']) ?>"
                 role="tabpanel" aria-labelledby="shtab-<?= html_escape($t['key']) ?>"
                 aria-hidden="<?= $act ? 'false' : 'true' ?>" data-key="<?= html_escape($t['key']) ?>">
                <div class="att-shell-spin"><div class="sp"></div></div>
                <div class="att-shell-error" role="alert">
                    <span class="em" aria-hidden="true">⚠️</span>
                    <span class="msg">This section is taking longer than expected to load.</span>
                    <button type="button" class="att-shell-retry">Retry</button>
                </div>
                <iframe class="att-shell-frame" title="<?= html_escape($t['label']) ?>"
                        data-src="<?= html_escape($t['url']) ?>"></iframe>
            </div>
        <?php endforeach; ?>
    </div>

</div>
</div>
</section>
</div>

<script>
(function(){
    var root  = document.querySelector('.att-shell');
    if (!root) return;
    var tabs  = Array.prototype.slice.call(root.querySelectorAll('.att-shell-tab'));
    var panes = Array.prototype.slice.call(root.querySelectorAll('.att-shell-pane'));
    var LOAD_TIMEOUT = 15000;   // watchdog: no infinite spinner

    // Auto-size each iframe to its content so there is ONE page scroll (no inner
    // scroll). Same-origin → read the child scrollHeight directly and re-fit on
    // every child re-render (ResizeObserver on its <body>).
    function fitFrame(f){
        try {
            var d = f.contentWindow.document;
            var h = Math.max(d.body ? d.body.scrollHeight : 0, d.documentElement ? d.documentElement.scrollHeight : 0);
            if (h > 0) {
                var target = h + 8;
                var cur = parseInt(f.style.height, 10) || 0;
                // Only resize on a real change. A sub-pixel/echo delta would otherwise
                // ping-pong with the ResizeObserver and creep the height upward forever.
                if (Math.abs(target - cur) > 2) f.style.height = target + 'px';
            }
        } catch(e){}
    }
    function observeFrame(f){
        try {
            var d = f.contentWindow.document;
            if (f._ro){ try { f._ro.disconnect(); } catch(e){} f._ro = null; }
            if (window.ResizeObserver && d.body){ f._ro = new ResizeObserver(function(){ fitFrame(f); }); f._ro.observe(d.body); }
            else if (!f._iv){ f._iv = setInterval(function(){ fitFrame(f); }, 700); }
            setTimeout(function(){ fitFrame(f); }, 300);
            setTimeout(function(){ fitFrame(f); }, 900);
            setTimeout(function(){ fitFrame(f); }, 1800);
        } catch(e){}
    }
    window.addEventListener('resize', function(){ document.querySelectorAll('.att-shell-frame').forEach(function(f){ if (f.getAttribute('src')) fitFrame(f); }); });

    // A modal opened/closed inside a child iframe → pin/unpin that iframe to the
    // viewport so its position:fixed modal centers on the real viewport.
    window.addEventListener('message', function(ev){
        if (!ev.data || typeof ev.data.shellModal === 'undefined') return;
        var f = null;
        root.querySelectorAll('.att-shell-frame').forEach(function(fr){ try { if (fr.contentWindow === ev.source) f = fr; } catch(e){} });
        if (!f){ var ap = root.querySelector('.att-shell-pane.active'); f = ap && ap.querySelector('.att-shell-frame'); }
        if (!f) return;
        var pane = f.closest('.att-shell-pane');
        if (ev.data.shellModal){
            if (pane) pane.style.minHeight = f.offsetHeight + 'px';   // freeze layout (no page jump)
            f.classList.add('shell-frame-pinned');
        } else {
            f.classList.remove('shell-frame-pinned');
            if (pane) pane.style.minHeight = '';
            fitFrame(f);
        }
    });

    function paneFor(key){ return panes.filter(function(p){ return p.getAttribute('data-key') === key; })[0]; }
    function tabFor(key){  return tabs.filter(function(t){ return t.getAttribute('data-key') === key; })[0]; }

    // Load a pane's iframe with a watchdog + failure UI. If it neither loads nor
    // errors within LOAD_TIMEOUT, swap the spinner for a Retry state rather than
    // spinning forever (FE-C2). `force` reloads an already-loaded frame.
    function loadFrame(pane, force){
        if (!pane) return;
        var f = pane.querySelector('.att-shell-frame');
        if (!f) return;
        var spin = pane.querySelector('.att-shell-spin');
        var err  = pane.querySelector('.att-shell-error');
        if (f.getAttribute('src') && !force){
            var done=false; try{ done = f.contentWindow && f.contentWindow.document && f.contentWindow.document.readyState==='complete'; }catch(e){}
            if (done && spin) spin.classList.add('hidden');
            return;
        }
        if (err) err.classList.remove('show');
        if (spin) spin.classList.remove('hidden');
        var settled = false;
        var wd = setTimeout(function(){
            if (settled) return; settled = true;
            if (spin) spin.classList.add('hidden');
            if (err) err.classList.add('show');
        }, LOAD_TIMEOUT);
        f.onload  = function(){ settled=true; clearTimeout(wd); if(spin)spin.classList.add('hidden'); if(err)err.classList.remove('show'); fitFrame(f); observeFrame(f); };
        f.onerror = function(){ settled=true; clearTimeout(wd); if(spin)spin.classList.add('hidden'); if(err)err.classList.add('show'); };
        var src = f.getAttribute('data-src');
        if (force){ var sep = src.indexOf('?')===-1?'?':'&'; src = src + sep + '_retry=' + Date.now(); }
        f.style.height = '440px'; // load-time placeholder; fitFrame() sets exact height on load
        f.setAttribute('src', src);
    }

    root.querySelectorAll('.att-shell-retry').forEach(function(btn){
        btn.addEventListener('click', function(){ loadFrame(btn.closest('.att-shell-pane'), true); });
    });

    // Reflect the WAI-ARIA tab state (selected / roving tabindex / panel hidden).
    function setState(key){
        tabs.forEach(function(t){
            var on = t.getAttribute('data-key') === key;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.setAttribute('tabindex', on ? '0' : '-1');
        });
        panes.forEach(function(p){
            var on = p.getAttribute('data-key') === key;
            p.classList.toggle('active', on);
            p.setAttribute('aria-hidden', on ? 'false' : 'true');
        });
    }

    function activate(key, push, focusTab){
        var target = paneFor(key);
        if (!target){ key = tabs[0] && tabs[0].getAttribute('data-key'); target = paneFor(key); }
        if (!target) return;
        setState(key);
        loadFrame(target, false);
        if (focusTab){ var tb = tabFor(key); if (tb) tb.focus(); }
        if (push && key){ try { history.pushState({shellTab:key}, '', '#'+key); } catch(e){} }
    }

    tabs.forEach(function(t){
        t.addEventListener('click', function(){ activate(t.getAttribute('data-key'), true); });
    });

    // Keyboard: arrow / Home / End roving focus across the tablist (ARIA pattern).
    var tablist = root.querySelector('.att-shell-tabs');
    if (tablist){
        tablist.addEventListener('keydown', function(e){
            var idx = tabs.indexOf(document.activeElement);
            if (idx === -1) return;
            var ni = null;
            if (e.key==='ArrowRight'||e.key==='ArrowDown') ni=(idx+1)%tabs.length;
            else if (e.key==='ArrowLeft'||e.key==='ArrowUp') ni=(idx-1+tabs.length)%tabs.length;
            else if (e.key==='Home') ni=0;
            else if (e.key==='End') ni=tabs.length-1;
            if (ni===null) return;
            e.preventDefault();
            activate(tabs[ni].getAttribute('data-key'), true, true);
        });
    }

    // Initial tab: URL hash wins, else the server-marked active pane.
    var initial = (location.hash||'').replace('#','').trim();
    if (!initial){ var a = root.querySelector('.att-shell-pane.active'); initial = a ? a.getAttribute('data-key') : ''; }
    activate(initial, false);

    // Back/forward moves BETWEEN tabs instead of exiting the whole shell (FE-H7).
    window.addEventListener('popstate', function(){
        var k = (location.hash||'').replace('#','').trim();
        if (k && paneFor(k)){ setState(k); loadFrame(paneFor(k), false); }
    });
    window.addEventListener('hashchange', function(){
        var k = (location.hash||'').replace('#','').trim();
        if (k && paneFor(k)){ setState(k); loadFrame(paneFor(k), false); }
    });

    // Preload the OTHER tabs one at a time (instant switching without starving
    // the visible frame). Each frame carries its own watchdog/failure UI.
    function preloadSequentially(){
        var queue = panes.slice();
        (function next(){
            var p = queue.shift();
            if (!p) return;
            var f = p.querySelector('.att-shell-frame');
            if (!f || f.getAttribute('src')){ next(); return; }
            var spin = p.querySelector('.att-shell-spin');
            var err  = p.querySelector('.att-shell-error');
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
    var activeFrame = activePane ? activePane.querySelector('.att-shell-frame') : null;
    if (activeFrame){
        activeFrame.addEventListener('load', function(){ whenIdle(preloadSequentially); });
        whenIdle(function(){ if (activeFrame.getAttribute('src')) whenIdle(preloadSequentially); });
    }

    <?= $shell['extra_js'] ?? '' ?>
})();
</script>
