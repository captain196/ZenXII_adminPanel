/* ═══════════════════════════════════════════════════════════════════════
   ZenXii themed dialogs — replaces window.confirm() / window.prompt()
   ───────────────────────────────────────────────────────────────────────
   The native dialogs render as "localhost says…" chrome: unstyleable, off
   theme, and they say the host name rather than the product name. Worse,
   they block the whole tab, so a slow page behind them looks frozen.

   Two calls, both PROMISE-based (the native ones are synchronous, so every
   call site has to be converted to async — that is deliberate, not an
   oversight):

       await zxConfirm({ title, message, confirmText, danger })  -> bool
       await zxPrompt ({ title, message, placeholder, value, required }) -> string|null

   NOTES
   - No jQuery. Some screens have it, some do not.
   - The open-state class is `zx-dlg-open`, NOT `.show`: Bootstrap 3 ships
     `.show{display:block!important}`, which silently un-centres any flex
     overlay that reuses that name (it already broke the ATS modals here).
   - Styles are injected once, scoped under .zx-dlg, so a screen that loads
     this file cannot have its own CSS disturbed.
   - Theme colours come from the CSS variables the admin shell already
     defines, with literal fallbacks for screens that do not define them.
   ═══════════════════════════════════════════════════════════════════════ */
(function (w, d) {
    'use strict';
    if (w.zxConfirm && w.zxPrompt) return;   // already installed

    var STYLE_ID = 'zx-dlg-styles';

    function injectStyles() {
        if (d.getElementById(STYLE_ID)) return;
        var css = [
            '.zx-dlg-bg{position:fixed;inset:0;z-index:20000;display:none;',
            'align-items:center;justify-content:center;padding:20px;',
            'background:rgba(15,23,42,.55);backdrop-filter:blur(2px);}',
            '.zx-dlg-bg.zx-dlg-open{display:flex;}',
            '.zx-dlg{width:440px;max-width:100%;border-radius:12px;overflow:hidden;',
            'background:var(--nsa-white,#fff);color:var(--nsa-text,#22160F);',
            'box-shadow:0 18px 50px rgba(15,23,42,.32);',
            'font-family:inherit;animation:zxDlgIn .16s ease;}',
            '@keyframes zxDlgIn{from{opacity:0;transform:translateY(8px) scale(.985)}',
            'to{opacity:1;transform:none}}',
            '.zx-dlg-head{padding:16px 20px 0;font:700 15.5px/1.3 inherit;}',
            '.zx-dlg-body{padding:10px 20px 4px;font-size:13.5px;line-height:1.55;',
            'color:var(--nsa-muted,#64748b);white-space:pre-line;}',
            '.zx-dlg-input{width:100%;margin:12px 0 2px;padding:10px 12px;font:inherit;',
            'font-size:13.5px;border:1px solid var(--nsa-border,#e2e8f0);border-radius:8px;',
            'background:var(--nsa-white,#fff);color:var(--nsa-text,#22160F);box-sizing:border-box;}',
            '.zx-dlg-input:focus{outline:2px solid var(--nsa-teal,#BC5A3C);outline-offset:1px;',
            'border-color:var(--nsa-teal,#BC5A3C);}',
            '.zx-dlg-err{min-height:16px;margin:5px 0 0;font-size:12px;color:#dc2626;}',
            '.zx-dlg-foot{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;',
            'padding:14px 20px 18px;}',
            '.zx-dlg-btn{padding:9px 16px;font:600 13px/1 inherit;border-radius:8px;',
            'cursor:pointer;border:1px solid transparent;}',
            '.zx-dlg-btn:focus-visible{outline:2px solid var(--nsa-teal,#BC5A3C);outline-offset:2px;}',
            '.zx-dlg-ghost{background:var(--nsa-bg,#f8fafc);color:var(--nsa-text,#22160F);',
            'border-color:var(--nsa-border,#e2e8f0);}',
            '.zx-dlg-ghost:hover{background:var(--nsa-border,#e2e8f0);}',
            '.zx-dlg-primary{background:var(--nsa-teal,#BC5A3C);color:#fff;}',
            '.zx-dlg-primary:hover{filter:brightness(1.06);}',
            '.zx-dlg-danger{background:#dc2626;color:#fff;}',
            '.zx-dlg-danger:hover{filter:brightness(1.06);}'
        ].join('');
        var el = d.createElement('style');
        el.id = STYLE_ID;
        el.appendChild(d.createTextNode(css));
        d.head.appendChild(el);
    }

    function esc(s) {
        var n = d.createElement('div');
        n.appendChild(d.createTextNode(s == null ? '' : String(s)));
        return n.innerHTML;
    }

    /**
     * Shared shell. `withInput` turns it into a prompt.
     * Resolves: confirm -> bool | prompt -> trimmed string, or null on cancel.
     */
    function open(opts, withInput) {
        injectStyles();
        opts = opts || {};

        return new Promise(function (resolve) {
            var bg = d.createElement('div');
            bg.className = 'zx-dlg-bg';
            bg.setAttribute('role', 'dialog');
            bg.setAttribute('aria-modal', 'true');

            bg.innerHTML =
                '<div class="zx-dlg">' +
                    '<div class="zx-dlg-head">' + esc(opts.title || (withInput ? 'Enter a value' : 'Are you sure?')) + '</div>' +
                    '<div class="zx-dlg-body">' + esc(opts.message || '') +
                        (withInput
                            ? '<input class="zx-dlg-input" type="text" placeholder="' + esc(opts.placeholder || '') + '"' +
                          ' value="' + esc(opts.value || '') + '">' +
                              '<p class="zx-dlg-err"></p>'
                            : '') +
                    '</div>' +
                    '<div class="zx-dlg-foot">' +
                        '<button type="button" class="zx-dlg-btn zx-dlg-ghost" data-zx="cancel">' +
                            esc(opts.cancelText || 'Cancel') + '</button>' +
                        '<button type="button" class="zx-dlg-btn ' +
                            (opts.danger ? 'zx-dlg-danger' : 'zx-dlg-primary') + '" data-zx="ok">' +
                            esc(opts.confirmText || (withInput ? 'Save' : 'Confirm')) + '</button>' +
                    '</div>' +
                '</div>';

            d.body.appendChild(bg);
            // Reflow so the open class animates rather than snapping.
            void bg.offsetWidth;
            bg.classList.add('zx-dlg-open');

            var input  = bg.querySelector('.zx-dlg-input');
            var errEl  = bg.querySelector('.zx-dlg-err');
            var prev   = d.activeElement;
            var closed = false;

            function done(value) {
                if (closed) return;
                closed = true;
                d.removeEventListener('keydown', onKey, true);
                // Hand the originating control to the busy-state net. The
                // POST fires after this promise resolves, by which time the
                // dialog is gone, so without this the net has to guess which
                // button the request belongs to.
                try { if (w.__zxSetTrigger) w.__zxSetTrigger(prev); } catch (e) {}
                bg.remove();
                try { if (prev && prev.focus) prev.focus(); } catch (e) { /* detached */ }
                resolve(value);
            }

            function accept() {
                if (!withInput) return done(true);
                var v = (input.value || '').trim();
                if (opts.required !== false && v === '') {
                    errEl.textContent = opts.requiredMessage || 'This is required.';
                    input.focus();
                    return;
                }
                done(v);
            }

            function onKey(e) {
                if (e.key === 'Escape')      { e.preventDefault(); done(withInput ? null : false); }
                else if (e.key === 'Enter' && withInput && d.activeElement === input) {
                    e.preventDefault(); accept();
                }
            }

            bg.addEventListener('click', function (e) {
                var act = e.target && e.target.getAttribute && e.target.getAttribute('data-zx');
                if (act === 'ok')     return accept();
                if (act === 'cancel') return done(withInput ? null : false);
                // Backdrop click cancels; clicks inside the card do not.
                if (e.target === bg)  return done(withInput ? null : false);
            });
            d.addEventListener('keydown', onKey, true);

            var first = input || bg.querySelector('[data-zx="ok"]');
            first.focus();
            // Native prompt() pre-selects its default so typing replaces it.
            if (input && input.value) { try { input.select(); } catch (e) {} }
        });
    }

    w.zxConfirm = function (opts) { return open(opts, false); };
    w.zxPrompt  = function (opts) { return open(opts, true); };
})(window, document);
