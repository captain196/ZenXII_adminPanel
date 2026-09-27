/* ═══════════════════════════════════════════════════════════════════════
   ZenXii busy-state safety net — progress, double-submit guard, and
   transport-failure reporting for EVERY mutating request.
   ───────────────────────────────────────────────────────────────────────
   There are ~646 POST call sites across the admin views and roughly 389 of
   them had no progress state, no in-flight guard and no failure handler. The
   operator hit it on Add Applicant and again on the ATS stage buttons: the
   request worked, but the button gave no sign of life.

   Editing 389 call sites by hand is how you corrupt a view. This instead
   binds the behaviour once, at the transport layer, with NO call-site
   changes:

     click a control  ->  a POST starts shortly after  ->  that control is
     disabled and shows a spinner until the request settles.

   Disabling is what actually prevents the duplicate-record bug; the spinner
   is what the user sees.

   DESIGN NOTES
   - The association is time-boxed (TRIGGER_WINDOW). A background poller
     firing with no recent click is never attributed to a button.
   - Clicks INSIDE a themed dialog (.zx-dlg-bg) do not reassign the trigger.
     `await zxConfirm()` then POST is a common shape; without this the
     dialog's own "Confirm" button — already removed from the DOM — would be
     the one we tried to spin.
   - Every busy state has a hard timeout. A bug in here must never leave a
     control permanently disabled.
   - Transport failures (including CI's blank 403 on a missing csrf_exclude
     entry) surface in a self-contained notice. It deliberately does NOT call
     the page's toast(): the signature differs per module (toast(m,'error')
     in HR, toast(m,false) in ATS), so calling it would be a guess.
   - Application-level failures ({status:'error'} with HTTP 200) are NOT
     touched — pages already handle those.
   - Opt out on any control with data-zx-nobusy.
   ═══════════════════════════════════════════════════════════════════════ */
(function (w, d) {
    'use strict';
    if (w.__zxBusyInstalled) return;
    w.__zxBusyInstalled = true;

    var TRIGGER_WINDOW = 2500;    // ms between the click and the POST
    var MAX_BUSY       = 45000;   // hard ceiling on any busy state
    var SEL = 'button,[type=submit],a.btn,.btn,.hr-btn,.ats-btn,.nsa-btn,.zx-btn';

    var trigger = null, triggerAt = 0;

    /* ── Track what the user last pressed ───────────────────────────── */
    d.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;

        // A confirm inside a themed dialog belongs to the action that opened
        // it — keep the original trigger and just extend its window.
        if (t.closest('.zx-dlg-bg')) { triggerAt = Date.now(); return; }

        var el = t.closest(SEL);
        if (!el || el.disabled || el.hasAttribute('data-zx-nobusy')) { return; }

        trigger = el;
        triggerAt = Date.now();
    }, true);

    /**
     * Deterministic handoff for the confirm-then-POST shape.
     *
     * zx_dialog captures the element that had focus when it opened — the
     * button the user actually pressed — and hands it back here on confirm,
     * so that association never depends on the click-timing heuristic.
     */
    w.__zxSetTrigger = function (el) {
        if (el && el.nodeType === 1 && el.matches && el.matches(SEL)) {
            trigger = el;
            triggerAt = Date.now();
        }
    };

    function claim() {
        if (!trigger) return null;
        if (Date.now() - triggerAt > TRIGGER_WINDOW) return null;
        if (!d.contains(trigger)) return null;      // already detached
        return trigger;
    }

    /* ── Busy state ─────────────────────────────────────────────────── */
    function busy(el) {
        if (!el) return null;

        // Already ours: a second concurrent request from the same control.
        // Count it so free() only restores once everything has settled.
        if (el.__zxCount) { el.__zxCount++; return el; }

        // Disabled before we got here means the PAGE set its own busy state
        // (saveJob/saveApplicant and friends do). Leave it entirely alone.
        // jQuery runs a request's own .always() BEFORE the global
        // ajaxComplete, so managing it too would restore the snapshot we took
        // mid-spin — putting the spinner back and re-disabling the button for
        // good. Ownership belongs to whoever claimed it first.
        if (el.disabled) return null;

        el.__zxCount = 1;

        try {
            var r = el.getBoundingClientRect();
            if (r.width) el.style.minWidth = Math.ceil(r.width) + 'px';
            el.__zxHtml = el.innerHTML;
            el.innerHTML = '<i class="fa fa-spinner fa-spin"></i> '
                         + (el.getAttribute('data-zx-busy-text') || 'Working…');
        } catch (_) { /* exotic control — disabling alone still guards it */ }

        el.__zxDisabled = el.disabled;
        el.disabled = true;
        el.setAttribute('aria-busy', 'true');
        el.__zxTimer = setTimeout(function () { el.__zxCount = 1; free(el); }, MAX_BUSY);
        return el;
    }

    function free(el) {
        if (!el || !el.__zxCount) return;
        el.__zxCount--;
        if (el.__zxCount > 0) return;

        clearTimeout(el.__zxTimer);
        try {
            if (el.__zxHtml !== undefined) el.innerHTML = el.__zxHtml;
            el.style.minWidth = '';
        } catch (_) { /* detached */ }
        el.disabled = !!el.__zxDisabled;
        el.removeAttribute('aria-busy');
    }

    /* ── Transport-failure notice (self-contained) ──────────────────── */
    function notify(msg) {
        var box = d.getElementById('zx-busy-err');
        if (!box) {
            box = d.createElement('div');
            box.id = 'zx-busy-err';
            box.setAttribute('role', 'alert');
            box.style.cssText = 'position:fixed;left:50%;bottom:26px;transform:translateX(-50%);'
                + 'z-index:99999;max-width:min(560px,92vw);padding:12px 18px;border-radius:9px;'
                + 'background:#7f1d1d;color:#fff;font:13.5px/1.5 "Segoe UI",system-ui,sans-serif;'
                + 'box-shadow:0 12px 34px rgba(0,0,0,.32);';
            d.body.appendChild(box);
        }
        box.textContent = msg;
        box.style.display = 'block';
        clearTimeout(notify._t);
        notify._t = setTimeout(function () { box.style.display = 'none'; }, 7000);
    }

    function describe(status) {
        if (status === 0)   return 'The request did not reach the server. Check your connection and try again — nothing was saved.';
        if (status === 403) return 'The server refused this action (403). Nothing was saved.';
        if (status === 419) return 'Your session expired. Reload the page and sign in again — nothing was saved.';
        if (status >= 500)  return 'The server failed to complete this action (' + status + '). Nothing was saved.';
        return 'This action did not complete (' + status + '). Nothing was saved.';
    }

    function isMutating(method) {
        method = String(method || 'GET').toUpperCase();
        return method !== 'GET' && method !== 'HEAD';
    }

    /**
     * Did the page already get a message it can render?
     *
     * MY_Controller::json_error() answers HTTP 400 with a real
     * {status:'error', message:'…'} body, and every module surfaces that
     * itself. Speaking here too would double every ordinary validation error
     * ("Applicant name is required.") in a much louder colour.
     *
     * What is left over is precisely what nobody handles: CI's BLANK 403 when
     * a route is missing from csrf_exclude_uris, a PHP fatal returning HTML,
     * and a dead connection.
     */
    function handledByPage(xhr) {
        try {
            if (xhr.responseJSON && typeof xhr.responseJSON.status === 'string') return true;
            var t = xhr.responseText;
            if (t && t.indexOf('"status"') !== -1) return true;
        } catch (_) { /* opaque */ }
        return false;
    }

    /* ── jQuery transport ───────────────────────────────────────────── */
    if (w.jQuery) {
        var $ = w.jQuery;
        var byXhr = (typeof WeakMap === 'function') ? new WeakMap() : null;
        var fallback = [];

        function remember(xhr, el) {
            if (!el) return;
            if (byXhr) { byXhr.set(xhr, el); } else { fallback.push([xhr, el]); }
        }
        function recall(xhr) {
            if (byXhr) { var v = byXhr.get(xhr); byXhr.delete(xhr); return v || null; }
            for (var i = 0; i < fallback.length; i++) {
                if (fallback[i][0] === xhr) { return fallback.splice(i, 1)[0][1]; }
            }
            return null;
        }

        $(d).ajaxSend(function (e, xhr, settings) {
            if (!isMutating(settings && settings.type)) return;
            remember(xhr, busy(claim()));
        });

        $(d).ajaxComplete(function (e, xhr, settings) {
            if (!isMutating(settings && settings.type)) return;
            free(recall(xhr));

            var st = xhr && xhr.status;
            if (xhr && xhr.statusText === 'abort') return;
            if (typeof st === 'number' && (st === 0 || st >= 400) && !handledByPage(xhr)) {
                notify(describe(st));
            }
        });
    }

    /* ── fetch() transport ──────────────────────────────────────────── */
    if (typeof w.fetch === 'function') {
        var nativeFetch = w.fetch.bind(w);
        w.fetch = function (input, init) {
            var method = (init && init.method) || (input && input.method) || 'GET';
            if (!isMutating(method)) return nativeFetch(input, init);

            var el = busy(claim());
            return nativeFetch(input, init).then(function (res) {
                free(el);
                // Same rule as the jQuery path: a JSON body means the caller has a
                // message of its own to show.
                var ct = (res && res.headers && res.headers.get('content-type')) || '';
                if (res && !res.ok && ct.indexOf('json') === -1) notify(describe(res.status));
                return res;
            }, function (err) {
                free(el);
                notify(describe(0));
                throw err;
            });
        };
    }
})(window, document);
