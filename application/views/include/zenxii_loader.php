<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
/**
 * ZenXii shared loading animation (Lottie) — drop-in for every panel.
 *
 * Include once per page (already wired into both panel headers):
 *     <?php $this->load->view('include/zenxii_loader'); ?>
 *
 * Then use the global `ZXLoader` API anywhere:
 *
 *   Full-screen overlay (ref-counted — safe to nest around AJAX):
 *     ZXLoader.show('Saving…');         // optional message
 *     ZXLoader.hide();                  // call once per show()
 *     ZXLoader.hide(true);              // force-hide regardless of count
 *
 *   Cover a single card/table while it loads (returns a handle):
 *     var h = ZXLoader.cover('#adminTableWrap', 'Loading admins…');
 *     ... h.destroy();
 *
 *   Render the animation inline into an empty container:
 *     var h = ZXLoader.mount('#myBox', { size: 72 });
 *     ... h.destroy();
 *
 * Theme-aware: uses --sa (superadmin) or --gold (school panel) for accent,
 * with hard fallbacks, so it looks right in either header without changes.
 */
?>
<!-- ═══════════════ ZenXii Shared Loader ═══════════════ -->
<div id="zxLoaderOverlay" class="zx-ld-overlay" role="status" aria-live="polite" aria-hidden="true">
    <div class="zx-ld-card">
        <div id="zxLoaderAnim" class="zx-ld-anim"></div>
        <div id="zxLoaderMsg" class="zx-ld-msg">Loading…</div>
    </div>
</div>

<style>
.zx-ld-overlay{
    position:fixed;inset:0;z-index:99999;
    display:none;align-items:center;justify-content:center;
    background:rgba(8,11,17,.55);
    opacity:0;transition:opacity .18s ease;
}
.zx-ld-overlay.is-open{display:flex;opacity:1;}
.zx-ld-card{
    display:flex;flex-direction:column;align-items:center;gap:10px;
    padding:0;
    background:transparent;
    border:0;
    box-shadow:none;
}
.zx-ld-anim{width:min(640px,90vmin);height:min(640px,90vmin);}
.zx-ld-msg{
    font-family:var(--font-b,'Inter',system-ui,sans-serif);
    font-size:13px;font-weight:500;letter-spacing:-.1px;
    color:var(--t2,#8d96a0);text-align:center;
}
.zx-ld-msg:empty{display:none;}

/* Inline / cover variants */
.zx-ld-cover{
    position:absolute;inset:0;z-index:50;
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;
    background:color-mix(in srgb, var(--bg2,#161b22) 78%, transparent);
    border-radius:inherit;
}
.zx-ld-cover .zx-ld-anim{width:84px;height:84px;}
.zx-ld-cover .zx-ld-msg{font-size:12.5px;}
.zx-ld-inline{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:24px;}
</style>

<!-- PERF: `defer` the 298KB Lottie player so it no longer blocks HTML parsing on every
     page. Safe because window.lottie is only touched inside build() (on show/cover/mount,
     i.e. user/AJAX actions that fire after load) and is guarded by hasLottie() with a
     graceful null fallback — the ZXLoader API below defines fine before lottie arrives. -->
<script src="<?= base_url() ?>tools/js/lottie.min.js" defer></script>
<script>
window.ZXLoader = (function () {
    var ANIM_PATH = '<?= base_url() ?>Designs/loading_anim.json';
    var overlayCount = 0;
    var overlayEl, overlayAnim = null, msgEl;

    function hasLottie() { return typeof window.lottie !== 'undefined' && window.lottie; }

    // Build a Lottie instance inside `container`. Returns the anim or null.
    function build(container) {
        if (!hasLottie()) return null;
        try {
            return window.lottie.loadAnimation({
                container: container,
                renderer: 'svg',
                loop: true,
                autoplay: true,
                path: ANIM_PATH,
                rendererSettings: { preserveAspectRatio: 'xMidYMid meet' }
            });
        } catch (e) { return null; }
    }

    function resolve(target) {
        if (!target) return null;
        if (typeof target === 'string') return document.querySelector(target);
        if (target.jquery) return target[0];          // jQuery object
        return target;                                 // raw DOM node
    }

    function initOverlay() {
        if (overlayEl) return;
        overlayEl = document.getElementById('zxLoaderOverlay');
        msgEl     = document.getElementById('zxLoaderMsg');
        if (overlayEl && !overlayAnim) {
            overlayAnim = build(document.getElementById('zxLoaderAnim'));
        }
    }

    return {
        /** Show the global overlay. Ref-counted: pair every show() with a hide(). */
        show: function (message) {
            initOverlay();
            if (!overlayEl) return;
            overlayCount++;
            if (msgEl) msgEl.textContent = (message != null ? message : 'Loading…');
            overlayEl.classList.add('is-open');
            overlayEl.setAttribute('aria-hidden', 'false');
            if (overlayAnim && overlayAnim.isPaused) overlayAnim.play();
        },

        /** Hide the global overlay. Pass true to force-close regardless of count. */
        hide: function (force) {
            overlayCount = force ? 0 : Math.max(0, overlayCount - 1);
            if (overlayCount > 0 || !overlayEl) return;
            overlayEl.classList.remove('is-open');
            overlayEl.setAttribute('aria-hidden', 'true');
        },

        /** Update the overlay message without changing visibility. */
        message: function (message) {
            if (msgEl) msgEl.textContent = (message != null ? message : '');
        },

        /**
         * Cover an existing container (card, table wrap) with a loader.
         * Returns { destroy: fn }. The container is made position:relative if static.
         */
        cover: function (target, message) {
            var host = resolve(target);
            if (!host) return { destroy: function () {} };
            if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
            var box = document.createElement('div');
            box.className = 'zx-ld-cover';
            var a = document.createElement('div'); a.className = 'zx-ld-anim';
            var m = document.createElement('div'); m.className = 'zx-ld-msg';
            m.textContent = (message != null ? message : '');
            box.appendChild(a); box.appendChild(m);
            host.appendChild(box);
            var anim = build(a);
            return { destroy: function () { try { if (anim) anim.destroy(); } catch (e) {} if (box.parentNode) box.parentNode.removeChild(box); } };
        },

        /**
         * Render the animation inline inside an (ideally empty) container.
         * Returns { destroy: fn }.
         */
        mount: function (target, opts) {
            var host = resolve(target);
            if (!host) return { destroy: function () {} };
            opts = opts || {};
            var wrap = document.createElement('div'); wrap.className = 'zx-ld-inline';
            var a = document.createElement('div'); a.className = 'zx-ld-anim';
            if (opts.size) { a.style.width = opts.size + 'px'; a.style.height = opts.size + 'px'; }
            wrap.appendChild(a);
            if (opts.message) { var m = document.createElement('div'); m.className = 'zx-ld-msg'; m.textContent = opts.message; wrap.appendChild(m); }
            host.appendChild(wrap);
            var anim = build(a);
            return { destroy: function () { try { if (anim) anim.destroy(); } catch (e) {} if (wrap.parentNode) wrap.parentNode.removeChild(wrap); } };
        }
    };
})();
</script>
<!-- ═══════════════ /ZenXii Shared Loader ═══════════════ -->
