<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!--
  F13.2 Admin live fleet map.

  Renderer-agnostic: the provider comes from System Settings → Maps & Location
  via /transport_fleet/map_config, and drawing goes through
  ZenxMapRendererFactory. This view names no map SDK.

  Leaflet's library is loaded from the CDN already permitted by script-src /
  style-src. Markers are SVG circleMarkers, so no CDN image is fetched and
  img-src only has to allow real tile hosts.
-->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>window.ZENX_BASE_URL = '<?= rtrim(base_url(), "/") ?>';</script>
<script src="<?= base_url('assets/js/maps/map_renderer.js') ?>"></script>

<style>
/* ── F13.2 Live Fleet ──────────────────────────────────────────────────────
   Themed from the panel's own tokens (--card/--border/--t1..--t3/--gold), so
   Day and Night both work and the page carries the terracotta identity rather
   than a second accent of its own.

   Tracking state is SEMANTIC colour and is deliberately kept separate from the
   accent — a bus losing signal must not read as "branded". Night gets its own
   state ramp: the day hexes are tuned for a white ground and go muddy on the
   dark one. */
.flt-wrap{
    padding:18px 20px 34px;
    --st-live:#1E7A45;  --st-live-bg:rgba(30,122,69,.12);
    --st-stale:#9A5B06; --st-stale-bg:rgba(154,91,6,.12);
    --st-nosig:#A81F17; --st-nosig-bg:rgba(168,31,23,.11);
    --st-none:#6B5346;  --st-none-bg:rgba(107,83,70,.11);
    --flt-h:clamp(380px,56vh,620px);
}
[data-theme="night"] .flt-wrap,body[data-theme="night"] .flt-wrap{
    --st-live:#67CE92;  --st-live-bg:rgba(103,206,146,.14);
    --st-stale:#E3A854; --st-stale-bg:rgba(227,168,84,.14);
    --st-nosig:#F0908A; --st-nosig-bg:rgba(240,144,138,.14);
    --st-none:#A8907F;  --st-none-bg:rgba(168,144,127,.13);
}

/* Header — title carries the page, meta sits on the baseline beside it. */
.flt-head{display:flex;align-items:baseline;flex-wrap:wrap;gap:6px 14px;margin-bottom:16px}
.flt-head h1{
    margin:0;font-size:21px;font-weight:700;letter-spacing:-.2px;
    color:var(--t1,#2A1C14);line-height:1.2;
}
.flt-head .sub{font-size:12px;color:var(--t3,#9E8578)}

/* auto-fit grid, not flex — six counters can never overflow the row, they
   reflow into however many columns actually fit. */
.flt-counts{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));
    gap:10px;margin-bottom:16px;
}
.flt-count{
    background:var(--card,#fff);border:1px solid var(--border,#ddd);border-radius:8px;
    padding:10px 12px 11px 15px;position:relative;overflow:hidden;
}
.flt-count .n{
    font-size:26px;font-weight:700;line-height:1.05;color:var(--t1,#2A1C14);
    font-variant-numeric:tabular-nums;
}
.flt-count .k{
    font-size:10.5px;color:var(--t3,#9E8578);text-transform:uppercase;
    letter-spacing:.6px;font-weight:600;margin-bottom:3px;
}
.flt-count .bar{position:absolute;left:0;top:0;bottom:0;width:3px}

/* minmax(0,1fr) — a plain 1fr refuses to shrink below the map's content and
   pushes the rail off-screen. Stacks on narrow viewports. */
.flt-main{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px;align-items:start}
@media (max-width:1180px){ .flt-main{grid-template-columns:minmax(0,1fr)} }

.flt-mapshell{position:relative;height:var(--flt-h)}
#flt-map{
    height:100%;border:1px solid var(--border,#ddd);border-radius:8px;
    background:var(--bg3,#F3E9E2);
}
/* An unconfigured map must read as intentional, not as a broken grey slab. */
.flt-mapmsg{
    position:absolute;inset:0;display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:6px;text-align:center;
    padding:24px;pointer-events:none;border-radius:8px;
}
.flt-mapmsg .t{font-size:13.5px;font-weight:600;color:var(--t2,#6B5346)}
.flt-mapmsg .d{font-size:12px;color:var(--t3,#9E8578);max-width:340px;line-height:1.5}

.flt-side{display:flex;flex-direction:column;gap:12px;min-width:0}
.flt-list{
    border:1px solid var(--border,#ddd);border-radius:8px;background:var(--card,#fff);
    overflow-y:auto;max-height:var(--flt-h);
}
.flt-item{
    padding:10px 12px;border-bottom:1px solid var(--border,#eee);cursor:pointer;
    box-shadow:inset 0 0 0 0 var(--gold,#BC5A3C);transition:background .12s,box-shadow .12s;
}
.flt-item:last-child{border-bottom:0}
.flt-item:hover{background:var(--bg3,#F3E9E2)}
.flt-item.active{background:var(--gold-dim,rgba(188,90,60,.10));box-shadow:inset 3px 0 0 var(--gold,#BC5A3C)}
.flt-item:focus-visible{outline:2px solid var(--gold,#BC5A3C);outline-offset:-2px}
.flt-item .veh{font-weight:700;font-size:13px;color:var(--t1,#2A1C14)}
.flt-item .meta{font-size:11.5px;color:var(--t3,#9E8578);margin-top:3px;line-height:1.45}
.flt-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:1px}
.flt-badge{
    display:inline-block;padding:1px 7px;border-radius:10px;font-size:10.5px;
    font-weight:700;white-space:nowrap;
}
.flt-b-live {background:var(--st-live-bg); color:var(--st-live)}
.flt-b-stale{background:var(--st-stale-bg);color:var(--st-stale)}
.flt-b-nosig{background:var(--st-nosig-bg);color:var(--st-nosig)}
.flt-b-nott {background:var(--st-none-bg); color:var(--st-none)}
.flt-b-late {background:var(--st-nosig-bg);color:var(--st-nosig)}
.flt-b-abnd {background:var(--st-none-bg); color:var(--st-none)}
.flt-empty{padding:38px 18px;text-align:center;color:var(--t3,#9E8578);font-size:13px;line-height:1.6}

.flt-detail{
    border:1px solid var(--border,#ddd);border-radius:8px;background:var(--card,#fff);
    padding:13px 14px;font-size:12.5px;
}
.flt-detail dl{
    margin:0;display:grid;grid-template-columns:minmax(0,auto) minmax(0,1fr);
    gap:5px 12px;align-items:baseline;
}
.flt-detail dt{
    color:var(--t3,#9E8578);font-weight:600;font-size:10.5px;text-transform:uppercase;
    letter-spacing:.5px;white-space:nowrap;
}
.flt-detail dd{margin:0;color:var(--t1,#2A1C14);word-break:break-word}
.flt-status{font-size:11.5px;color:var(--t3,#9E8578);margin-top:9px;line-height:1.5}
.flt-warn,.flt-err{padding:9px 11px;border-radius:6px;font-size:12.5px;margin-bottom:12px;line-height:1.5}
.flt-warn{background:var(--st-stale-bg);border:1px solid var(--st-stale);color:var(--st-stale)}
.flt-err {background:var(--st-nosig-bg);border:1px solid var(--st-nosig);color:var(--st-nosig)}
</style>

<div class="content-wrapper">
<div class="flt-wrap">
  <div class="flt-head">
    <h1>Live Fleet</h1>
    <span class="sub" id="flt-provider"></span>
  </div>

  <div id="flt-notice"></div>

  <div class="flt-counts" id="flt-counts"></div>

  <div class="flt-main">
    <div class="flt-mapbox">
      <div class="flt-mapshell">
        <div id="flt-map"></div>
        <div class="flt-mapmsg" id="flt-mapmsg">
          <div class="t">Starting map…</div>
        </div>
      </div>
      <div class="flt-status" id="flt-status">Loading…</div>
    </div>
    <div class="flt-side">
      <div class="flt-list" id="flt-list">
        <div class="flt-empty">Loading…</div>
      </div>
      <div class="flt-detail" id="flt-detail" style="display:none"></div>
    </div>
  </div>
</div>
</div>

<script>
(function () {
    'use strict';
    var BASE = '<?= rtrim(base_url(), "/") ?>/transport_fleet/';

    /**
     * Poll interval. F13.1 writes a position at most once every 15s per
     * vehicle, so asking more often cannot surface data that does not exist —
     * and the server caches for 12s anyway, which keeps cost per SCHOOL rather
     * than per open tab.
     */
    var POLL_MS = 15000;

    var renderer = null, timer = null, selected = null;
    var byId = {};          // vehicle_id -> row, so we patch instead of re-render
    var markerState = {};   // vehicle_id -> last drawn "lat,lng,state"
    var pollCount = 0;

    var BADGE = { live: 'flt-b-live', stale: 'flt-b-stale', no_signal: 'flt-b-nosig', not_tracking: 'flt-b-nott' };
    var LABEL = { live: 'Live', stale: 'Stale', no_signal: 'No signal', not_tracking: 'Not tracking' };

    function el(id) { return document.getElementById(id); }

    /**
     * Tracking state → colour, read from the SAME CSS custom properties the
     * badges use, so a marker can never drift out of step with the legend it is
     * matched against — and so Day/Night needs no second palette in JS.
     */
    var VAR_FOR = { live: '--st-live', stale: '--st-stale', no_signal: '--st-nosig', not_tracking: '--st-none' };
    function stateColor(state) {
        var cs = getComputedStyle(document.querySelector('.flt-wrap'));
        return (cs.getPropertyValue(VAR_FOR[state] || VAR_FOR.not_tracking) || '').trim() || '#6B5346';
    }
    /** Current theme, so a Day/Night toggle repaints markers instead of
     *  leaving them in the previous theme's ramp. */
    function theme() {
        return document.body.getAttribute('data-theme')
            || document.documentElement.getAttribute('data-theme') || 'day';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function notice(html) { el('flt-notice').innerHTML = html || ''; }

    // fetch() does not reject on 4xx/5xx, so both the transport status and the
    // application status are checked — otherwise a permission denial renders as
    // an empty fleet, which looks like "no buses running".
    function getJSON(path) {
        return fetch(BASE + path, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                return r.json().catch(function () { return { status: 'error', message: 'HTTP ' + r.status }; })
                    .then(function (j) {
                        if (!r.ok || j.status === 'error') {
                            throw new Error(j.message || ('Request failed (HTTP ' + r.status + ')'));
                        }
                        return j;
                    });
            });
    }

    // ── map ──────────────────────────────────────────────────────────────

    /** Say inside the map frame why it is empty; grey silence reads as a bug. */
    function mapMsg(title, detail) {
        var box = el('flt-mapmsg');
        if (!title) { box.style.display = 'none'; return; }
        box.style.display = '';
        box.innerHTML = '<div class="t">' + esc(title) + '</div>'
                      + (detail ? '<div class="d">' + esc(detail) + '</div>' : '');
    }

    function initMap() {
        return window.ZenxMapRendererFactory.forPlatform('admin').then(function (r) {
            if (!r) {
                mapMsg('No map provider configured',
                       'Choose one in System Settings → Maps & Location. The fleet list beside this '
                     + 'map keeps working without it.');
                el('flt-provider').textContent = 'no map provider · list only';
                return null;
            }
            if (!r.isAvailable()) {
                mapMsg('Map provider unavailable',
                       'The selected provider (' + r.providerId + ') has no admin renderer, or its '
                     + 'tile configuration is incomplete. The fleet list keeps working.');
                el('flt-provider').textContent = 'provider: ' + r.providerId + ' (unavailable)';
                return null;
            }
            el('flt-provider').textContent = 'provider: ' + r.providerId;
            return r.initialize(el('flt-map'), { lat: 0, lng: 0, zoom: 12 }).then(function () {
                mapMsg(null);
                return r;
            });
        }).catch(function (e) {
            mapMsg('Map could not start', e.message + '. The fleet list keeps working.');
            return null;
        });
    }

    /** Patch only vehicles whose position or state actually changed. */
    function drawMarkers(rows) {
        if (!renderer) { return; }
        var seen = {}, th = theme();
        rows.forEach(function (v) {
            seen[v.vehicle_id] = true;
            if (v.lat === null || v.lng === null) { return; }
            var sig = v.lat + ',' + v.lng + ',' + v.tracking_state + ',' + th;
            if (markerState[v.vehicle_id] === sig) { return; }   // nothing to do
            var opts = {
                color: stateColor(v.tracking_state),
                tooltip: v.vehicle_number + ' · ' + (LABEL[v.tracking_state] || '')
            };
            if (markerState[v.vehicle_id] === undefined) {
                renderer.addMarker(v.vehicle_id, v.lat, v.lng, opts);
            } else {
                // Smoothing between two REAL fixes is the renderer's business.
                // Nothing here extrapolates beyond the reported position.
                renderer.updateMarker(v.vehicle_id, v.lat, v.lng, opts);
            }
            markerState[v.vehicle_id] = sig;
        });
        // A trip that completed or was cancelled while the map was open.
        Object.keys(markerState).forEach(function (id) {
            if (!seen[id]) { renderer.removeMarker(id); delete markerState[id]; }
        });
    }

    // ── list + counters ──────────────────────────────────────────────────

    function renderCounts(c) {
        // 'Active' is the total, so it takes the ACCENT; the rest are semantic
        // state and take the state ramp.
        var accent = (getComputedStyle(document.querySelector('.flt-wrap'))
                        .getPropertyValue('--gold') || '').trim() || '#BC5A3C';
        var defs = [
            ['Active', c.active, accent], ['Live', c.live, stateColor('live')],
            ['Stale', c.stale, stateColor('stale')], ['No signal', c.no_signal, stateColor('no_signal')],
            ['Not tracking', c.not_tracking, stateColor('not_tracking')],
            ['Behind schedule', c.late, stateColor('no_signal')]
        ];
        el('flt-counts').innerHTML = defs.map(function (d) {
            return '<div class="flt-count"><span class="bar" style="background:' + d[2] + '"></span>'
                 + '<div class="k">' + esc(d[0]) + '</div><div class="n">' + (d[1] | 0) + '</div></div>';
        }).join('');
    }

    /** 95 → "1h 35m late". A five-digit minute count is not information. */
    function lateBadge(v) {
        if (v.schedule_state === 'abandoned') {
            return ' <span class="flt-badge flt-b-abnd" title="' + esc(v.schedule_text)
                 + '">trip left open</span>';
        }
        if (v.schedule_state !== 'late' || v.schedule_delta_min === null) { return ''; }
        var m = Math.abs(v.schedule_delta_min | 0);
        var t = m < 60 ? m + 'm' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
        return ' <span class="flt-badge flt-b-late">' + t + ' late</span>';
    }

    function rowHTML(v) {
        return '<div class="veh"><span class="flt-dot" style="background:'
             + stateColor(v.tracking_state) + '"></span>' + esc(v.vehicle_number)
             + ' <span class="flt-badge ' + (BADGE[v.tracking_state] || 'flt-b-nott') + '">'
             + esc(LABEL[v.tracking_state] || '') + '</span>' + lateBadge(v) + '</div>'
             + '<div class="meta">' + esc(v.route_name || '—') + ' · ' + esc(v.driver_name || 'Driver unknown') + '</div>'
             + '<div class="meta">' + esc(v.freshness_text)
             + (v.speed_kmph !== null ? ' · ' + Math.round(v.speed_kmph) + ' km/h' : '')
             + (v.stops_total ? ' · stop ' + v.stops_done + '/' + v.stops_total : '') + '</div>';
    }

    function renderList(rows) {
        var list = el('flt-list');
        if (!rows.length) {
            list.innerHTML = '<div class="flt-empty">No trips are running right now.<br>'
                           + '<span style="font-size:11.5px">Buses appear here once a driver starts a trip.</span></div>';
            byId = {}; el('flt-detail').style.display = 'none';
            return;
        }
        // Reuse existing nodes where possible so the list does not flicker and
        // the operator's scroll position survives a refresh.
        var existing = {};
        [].forEach.call(list.querySelectorAll('[data-vid]'), function (n) { existing[n.getAttribute('data-vid')] = n; });

        var frag = document.createDocumentFragment();
        rows.forEach(function (v) {
            var node = existing[v.vehicle_id];
            if (!node) {
                node = document.createElement('div');
                node.className = 'flt-item';
                node.setAttribute('data-vid', v.vehicle_id);
                node.setAttribute('tabindex', '0');          // keyboard-reachable
                node.setAttribute('role', 'button');
                node.addEventListener('click', function () { select(v.vehicle_id, true); });
                node.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(v.vehicle_id, true); }
                });
            }
            node.innerHTML = rowHTML(v);
            if (v.vehicle_id === selected) { node.classList.add('active'); }
            else { node.classList.remove('active'); }
            frag.appendChild(node);
            delete existing[v.vehicle_id];
        });
        Object.keys(existing).forEach(function (k) { existing[k].remove(); });
        list.innerHTML = '';
        list.appendChild(frag);
    }

    function renderDetail() {
        var box = el('flt-detail');
        var v = byId[selected];
        if (!v) { box.style.display = 'none'; return; }
        box.style.display = '';
        var rows = [
            ['Vehicle', v.vehicle_number], ['Route', v.route_name || '—'],
            ['Driver', v.driver_name || '—'], ['Trip', v.trip_id + ' · ' + v.trip_status],
            ['Direction', v.trip_direction || '—'],
            ['Tracking', (LABEL[v.tracking_state] || '') + ' — ' + v.freshness_text],
            ['Speed', v.speed_kmph !== null ? Math.round(v.speed_kmph) + ' km/h' : '—'],
            ['Heading', v.heading !== null ? Math.round(v.heading) + '°' : '—'],
            ['Next stop', v.next_stop_name || '—'],
            ['Stops', v.stops_total ? (v.stops_done + ' done · ' + v.stops_remaining + ' remaining') : '—'],
            ['Progress', v.progress_pct + '%'],
            // A measured straight-line distance, labelled as such. NOT an ETA —
            // an arrival time needs travel-time modelling that does not exist,
            // and a wrong one misleads the person timing a walk to the stop.
            ['Distance to next', v.distance_to_next_m !== null
                ? (v.distance_to_next_m >= 1000
                    ? (v.distance_to_next_m / 1000).toFixed(1) + ' km (straight line)'
                    : v.distance_to_next_m + ' m (straight line)') : '—'],
            ['Schedule', v.schedule_text || '—'],
            ['On board', v.roster_size ? (v.boarded_count + ' of ' + v.roster_size) : '—']
        ];
        box.innerHTML = '<dl style="margin:0">' + rows.map(function (r) {
            return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>';
        }).join('') + '</dl>';
    }

    /** Selection is shared by list and map. */
    function select(vehicleId, focusMap) {
        selected = vehicleId;
        [].forEach.call(document.querySelectorAll('[data-vid]'), function (n) {
            n.classList.toggle('active', n.getAttribute('data-vid') === vehicleId);
        });
        renderDetail();
        var v = byId[vehicleId];
        if (focusMap && renderer && v && v.lat !== null && v.lng !== null) {
            renderer.animateCamera(v.lat, v.lng, 600);
        }
    }

    // ── poll loop ────────────────────────────────────────────────────────

    function tick() {
        getJSON('live').then(function (j) {
            var rows = j.vehicles || [];
            byId = {};
            rows.forEach(function (v) { byId[v.vehicle_id] = v; });

            renderCounts(j.counts || {});
            renderList(rows);
            drawMarkers(rows);

            // A working map with nothing to plot opens on the null island. Say
            // so, rather than leaving the operator looking at empty ocean.
            // Only when the renderer is up — otherwise this would overwrite the
            // "no provider configured" explanation.
            if (renderer) {
                mapMsg(rows.length ? null : 'No buses on the map',
                       rows.length ? null : 'Buses appear here once a driver starts a trip.');
            }

            // Keep the selection if that bus is still active; drop it if the
            // trip ended while the map was open.
            if (selected && !byId[selected]) { selected = null; }
            renderDetail();

            pollCount++;
            el('flt-status').textContent =
                'Updated ' + new Date().toLocaleTimeString()
                + ' · ' + rows.length + ' active'
                + ' · ' + (j.cached ? 'cached ' + (j.cache_age_s || 0) + 's' : 'fresh')
                + ' · reads ' + ((j.reads && j.reads.documents) || 0)
                + ' · polls ' + pollCount;
            notice(j.error ? '<div class="flt-err">' + esc(j.error) + '</div>' : '');
        }).catch(function (e) {
            // Never fall back to fake data — say what happened.
            notice('<div class="flt-err">Could not refresh the fleet: ' + esc(e.message) + '</div>');
            el('flt-status').textContent = 'Last attempt failed at ' + new Date().toLocaleTimeString();
        });
    }

    function startPolling() { if (!timer) { tick(); timer = setInterval(tick, POLL_MS); } }
    function stopPolling() { if (timer) { clearInterval(timer); timer = null; } }

    // A backgrounded tab must not keep billing reads for a map nobody is
    // looking at. Resume with an immediate tick so the operator does not stare
    // at stale numbers after switching back.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopPolling(); } else { startPolling(); }
    });
    window.addEventListener('beforeunload', stopPolling);

    initMap().then(function (r) { renderer = r; startPolling(); });
}());
</script>
