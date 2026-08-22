<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
.mp-wrap { padding: 20px 22px 40px; }
.mp-head { display:flex; align-items:center; gap:14px; padding:18px 22px; margin-bottom:22px;
           background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); }
.mp-head-icon { width:44px; height:44px; border-radius:10px; background:var(--gold); display:flex;
                align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 0 18px var(--gold-glow); }
.mp-head-icon i { color:#fff; font-size:20px; }
.mp-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.mp-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }

.mp-card { background:var(--bg2); border:1px solid var(--border); border-radius:var(--r);
           box-shadow:var(--sh); padding:18px 20px; margin-bottom:18px; }
.mp-card h3 { font-size:14px; font-weight:700; color:var(--t1); margin:0 0 4px; }
.mp-card .mp-sub { font-size:12px; color:var(--t3); margin-bottom:14px; }

.mp-row { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
.mp-field { display:flex; flex-direction:column; gap:5px; min-width:220px; flex:1; }
.mp-field label { font-size:11px; font-weight:600; color:var(--t2); text-transform:uppercase; letter-spacing:.4px; }
.mp-field input, .mp-field select {
    padding:8px 10px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg1); color:var(--t1); font-size:13px; }
.mp-hint { font-size:11px; color:var(--t3); }

.mp-btn { padding:8px 14px; border-radius:8px; border:1px solid var(--border); cursor:pointer;
          font-size:12px; font-weight:600; background:var(--bg1); color:var(--t1); }
.mp-btn-primary { background:var(--gold); border-color:var(--gold); color:#fff; }
.mp-btn:disabled { opacity:.5; cursor:not-allowed; }

.mp-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:99px;
           font-size:11px; font-weight:700; }
.mp-pill-ok   { background:#e6f4ea; color:#1e7b34; }
.mp-pill-bad  { background:#fdecea; color:#b3261e; }
.mp-pill-idle { background:#eceff1; color:#5f6368; }
.mp-pill-live { background:#e8f0fe; color:#1a56c4; }

.mp-prov { border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin-bottom:12px; }
.mp-prov-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:6px; }
.mp-prov-name { font-size:13px; font-weight:700; color:var(--t1); }
.mp-prov-note { font-size:11px; color:var(--t3); margin-bottom:10px; }
.mp-prov-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }

.mp-audit { width:100%; border-collapse:collapse; font-size:12px; }
.mp-audit th, .mp-audit td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--border); }
.mp-audit th { font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--t3); }
.mp-msg { font-size:12px; margin-top:8px; }
.mp-msg-ok  { color:#1e7b34; }
.mp-msg-bad { color:#b3261e; }
.mp-secret-note { font-size:11px; color:#8a6d00; background:#fff8e1; border:1px solid #ffe082;
                  border-radius:8px; padding:8px 10px; margin-bottom:12px; }
</style>

<?php /* content-wrapper supplies the sidebar offset every other admin page uses
        (attendance/settings:100, school_config/index:349). Without it this page
        rendered from x=0, i.e. UNDERNEATH the fixed sidebar, so the left edge of
        every card, label and button was hidden. .mp-wrap keeps its own inner
        padding — the two are complementary, not duplicates. */ ?>
<div class="content-wrapper">
<div class="mp-wrap">
    <div class="mp-head">
<?php /* ZenX ships Font Awesome 4 (font-awesome.min.css). `fa-solid
        fa-map-location-dot` is FA6 syntax — FA4 has neither the `fa-solid`
        style prefix nor that glyph name, so the icon silently rendered as an
        empty orange square. `fa fa-map-marker` is the FA4 equivalent and
        matches every other icon in the sidebar. */ ?>
        <div class="mp-head-icon"><i class="fa fa-map-marker"></i></div>
        <div>
            <div class="mp-head-title">Maps &amp; Location</div>
            <div class="mp-head-sub">
                One place to manage the map provider used by the admin panel and every app.
                Transport live tracking reads from here; future fleet, attendance and asset maps will too.
            </div>
        </div>
    </div>

    <?php
    /* ── 5D — MAP SOURCE: inherit the ZenX platform default, or override ──
       Everything here comes from the service: the effective provider, the
       state and any fallback warning are produced by the SAME resolution the
       Admin fleet map and the Parent app use, so this page cannot disagree
       with what actually renders. No provider name is hardcoded, and no
       credential VALUE is in scope — the template receives labels and key
       names only. */
    $isInherit = ($source === Maps_config_service::SOURCE_INHERIT);
    $effLabel  = $effective['label'] !== '' ? $effective['label'] : ($effective['provider'] ?: '—');
    $stFall    = in_array($effective['state'], [
        Maps_config_service::ST_OVERRIDE_INVALID,
        Maps_config_service::ST_TENANT_UNAVAILABLE,
    ], true);
    $stNone    = in_array($effective['state'], [
        Maps_config_service::ST_PLATFORM_NOT_CONFIGURED,
        Maps_config_service::ST_PLATFORM_UNAVAILABLE,
        Maps_config_service::ST_PROVIDER_UNSUPPORTED,
    ], true);
    ?>
    <div class="mp-card">
        <h3>Map source</h3>
        <div class="mp-sub">
            Choose whether this school uses the map service ZenX provides, or its own.
        </div>

        <?php if ($stFall): ?>
            <div class="mp-secret-note" style="background:#fff8e1;border-color:#ffe082;color:#8a6d00">
                <b>Your own map provider is not being used right now.</b>
                <?= html_escape($effective['warning']) ?>
                ZenX Platform Maps is being used instead, so maps keep working.
            </div>
        <?php elseif ($stNone): ?>
            <div class="mp-secret-note" style="background:#ffebee;border-color:#ffcdd2;color:#b71c1c">
                <b>Maps are not available yet.</b>
                ZenX has not finished setting up a map provider for the platform.
                Everything else on this page still works, and coordinates can still be typed in by hand.
            </div>
        <?php endif; ?>

        <label style="display:block;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:10px;cursor:pointer">
            <input type="radio" name="mpSource" value="inherit" <?= $isInherit ? 'checked' : '' ?>
                   <?= $can_manage ? '' : 'disabled' ?>>
            <b>Use ZenX Platform Maps</b>
            <span style="font-size:11px;color:var(--t3)">— recommended</span>
            <div style="font-size:12px;color:var(--t3);margin:4px 0 0 22px">
                ZenX manages the map service for you. Nothing to configure, no key to obtain.
                <?php if ($isInherit && $effective['provider'] !== ''): ?>
                    <div style="margin-top:4px">
                        Currently using <b><?= html_escape($effLabel) ?></b>, provided by ZenX.
                    </div>
                <?php endif; ?>
            </div>
        </label>

        <label style="display:block;padding:10px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer">
            <input type="radio" name="mpSource" value="custom" <?= $isInherit ? '' : 'checked' ?>
                   <?= $can_manage ? '' : 'disabled' ?>>
            <b>Use this school's own map provider</b>
            <div style="font-size:12px;color:var(--t3);margin:4px 0 0 22px">
                Use your own account and credentials. You will need an API key from the provider.
            </div>
            <div id="mpSrcPick" style="margin:10px 0 0 22px;display:<?= $isInherit ? 'none' : 'block' ?>">
                <select id="mpSrcProvider" style="max-width:280px" <?= $can_manage ? '' : 'disabled' ?>>
                    <?php foreach ($render_providers as $pid): ?>
                        <option value="<?= html_escape($pid) ?>" <?= $source === $pid ? 'selected' : '' ?>>
                            <?= html_escape($providers[$pid]['label'] ?? $pid) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:11px;color:var(--t3);margin-top:4px">
                    Configure this provider's credentials below, test the connection, then activate it.
                </div>
            </div>
        </label>

        <?php if ($can_manage): ?>
            <button class="mp-btn mp-btn-primary" style="margin-top:12px" onclick="mpSaveSource()">Save map source</button>
        <?php else: ?>
            <div style="font-size:11px;color:var(--t3);margin-top:10px">
                You need the <b>manage</b> permission on Configuration to change the map source.
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Mode ─────────────────────────────────────────────────────── -->
    <div class="mp-card">
        <h3>Operating mode</h3>
        <div class="mp-sub">
            <strong>Global</strong> — one provider everywhere; change it once and every surface follows.
            <strong>Platform</strong> — each surface may use a different provider.
            A platform left blank in Platform mode falls back to the global provider, so switching
            modes never blanks a working map.
        </div>
        <div class="mp-row">
            <div class="mp-field" style="max-width:200px">
                <label for="mp-mode">Mode</label>
                <select id="mp-mode">
                    <option value="global">Global — one provider</option>
                    <option value="platform">Platform specific</option>
                </select>
            </div>
            <div class="mp-field" style="max-width:260px" id="mp-global-wrap">
                <label for="mp-global">Global provider</label>
                <select id="mp-global"></select>
            </div>
        </div>
        <div class="mp-row" id="mp-platform-wrap" style="margin-top:14px; display:none"></div>
        <div class="mp-prov-actions">
            <button class="mp-btn mp-btn-primary" id="mp-save-mode">Save assignment</button>
        </div>
        <div class="mp-msg" id="mp-mode-msg"></div>
    </div>

    <!-- ── Providers ────────────────────────────────────────────────── -->
    <div class="mp-card">
        <h3>Providers</h3>
        <div class="mp-sub">
            A provider can only be activated after a connection test succeeds. Saving credentials
            always clears the test result and disables the provider, because changed keys have not
            been proven.
        </div>
        <div class="mp-secret-note">
            <strong>Key handling.</strong> Keys marked <em>server-only</em> are stored in a separate
            document that no app can read. Every other key is sent to the device that draws the map —
            that is unavoidable for any map SDK — so restrict those at the provider by HTTP referrer
            (web) or package name + SHA-1 (Android).
        </div>
        <div id="mp-providers"></div>
    </div>

    <!-- ── Defaults ─────────────────────────────────────────────────── -->
    <div class="mp-card">
        <h3>Map defaults</h3>
        <div class="mp-sub">Where a map opens before it has anything to show. Leave the centre blank to let each map decide.</div>
        <div class="mp-row">
            <div class="mp-field" style="max-width:180px">
                <label for="mp-lat">Default latitude</label>
                <input type="text" id="mp-lat" placeholder="e.g. 12.9716">
            </div>
            <div class="mp-field" style="max-width:180px">
                <label for="mp-lng">Default longitude</label>
                <input type="text" id="mp-lng" placeholder="e.g. 77.5946">
            </div>
            <div class="mp-field" style="max-width:150px">
                <label for="mp-zoom">Default zoom (1–22)</label>
                <input type="number" id="mp-zoom" min="1" max="22" step="0.5">
            </div>
            <button class="mp-btn mp-btn-primary" id="mp-save-defaults">Save defaults</button>
        </div>
        <div class="mp-msg" id="mp-defaults-msg"></div>
    </div>

    <!-- ── Audit ────────────────────────────────────────────────────── -->
    <div class="mp-card">
        <h3>Change history</h3>
        <div class="mp-sub">Who changed what, and when. Credential values are never recorded — only which keys were touched.</div>
        <table class="mp-audit">
            <thead><tr><th>When</th><th>Action</th><th>Subject</th><th>By</th></tr></thead>
            <tbody id="mp-audit-body"><tr><td colspan="4" style="color:var(--t3)">Loading…</td></tr></tbody>
        </table>
    </div>
</div><!-- /.mp-wrap -->
</div><!-- /.content-wrapper -->

<script>
(function () {
    'use strict';
    var BASE = '<?= rtrim(base_url(), "/") ?>/maps_config/';
    var REGISTRY = {}, CONFIG = {}, RESOLVED = {}, AVAIL = {}, SURFACES = [];

    // CSRF. The header's auto-attach only wraps jQuery $.ajax; this page uses
    // fetch(), so the token has to be added by hand or every POST comes back a
    // blank 403 with no log and no console error. Read from the COOKIE each
    // time — CI regenerates the token per request, and a value captured at
    // page render goes stale after the first POST.
    var CSRFN = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRFT = '<?= $this->security->get_csrf_hash() ?>';
    function csrfToken() {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + CSRFN + '=([^;]+)'));
        return m ? decodeURIComponent(m[1]) : CSRFT;
    }

    function el(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    // Every response is checked for BOTH transport failure and an application
    // -level error. fetch() does not reject on 4xx/5xx, so a bare .json() would
    // render a denied action as success — the "phantom success" trap.
    function post(path, body) {
        var fd = new FormData();
        Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
        fd.append(CSRFN, csrfToken());
        return fetch(BASE + path, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() }
        })
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
    /* ── 5D — map source (inherit / own provider) ─────────────────────────
       Reuses the CSRF-aware post() above; the server re-checks the Configuration
       manage permission on every call, so the disabled controls are convenience,
       never the boundary. */
    (function () {
        var radios = document.querySelectorAll('input[name="mpSource"]');
        var pick   = document.getElementById('mpSrcPick');
        if (!radios.length) { return; }
        radios.forEach(function (r) {
            r.addEventListener('change', function () {
                if (pick) { pick.style.display = (r.value === 'custom' && r.checked) ? 'block' : 'none'; }
            });
        });
        window.mpSaveSource = function () {
            var chosen = document.querySelector('input[name="mpSource"]:checked');
            if (!chosen) { return; }
            var source = 'inherit';
            if (chosen.value === 'custom') {
                var sel = document.getElementById('mpSrcProvider');
                source = sel ? sel.value : '';
                if (!source) { alert('Choose a map provider.'); return; }
            }
            post('maps_config/set_source', { source: source })
                .then(function (j) { alert(j.message || 'Saved.'); location.reload(); })
                .catch(function (e) { alert(e.message || 'Could not save the map source.'); });
        };
    }());

    function get(path) {
        return fetch(BASE + path, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                return r.json().catch(function () { return { status: 'error', message: 'HTTP ' + r.status }; })
                    .then(function (j) {
                        if (!r.ok || j.status === 'error') { throw new Error(j.message || 'Request failed'); }
                        return j;
                    });
            });
    }
    function say(node, msg, ok) {
        node.textContent = msg;
        node.className = 'mp-msg ' + (ok ? 'mp-msg-ok' : 'mp-msg-bad');
    }

    function statusPill(entry) {
        if (!entry) { return '<span class="mp-pill mp-pill-idle">Not configured</span>'; }
        if (entry.enabled) { return '<span class="mp-pill mp-pill-live">Active</span>'; }
        if (entry.validation_status === 'valid')   { return '<span class="mp-pill mp-pill-ok">Tested — not active</span>'; }
        if (entry.validation_status === 'invalid') { return '<span class="mp-pill mp-pill-bad">Test failed</span>'; }
        return '<span class="mp-pill mp-pill-idle">Untested</span>';
    }

    function renderProviders() {
        var wrap = el('mp-providers');
        var entries = CONFIG.providers || {};
        wrap.innerHTML = Object.keys(REGISTRY).map(function (id) {
            var spec = REGISTRY[id], entry = entries[id] || null;
            var creds = spec.credentials || {};
            var fields = Object.keys(creds).map(function (k) {
                var m = creds[k];
                var val = (entry && entry.public && entry.public[k]) ? entry.public[k] : '';
                var secret = m.sensitivity === 'secret';
                return '<div class="mp-field">' +
                    '<label>' + esc(m.label) + (m.required ? ' *' : '') +
                        (secret ? ' <span class="mp-pill mp-pill-idle">server-only</span>' : '') + '</label>' +
                    '<input type="' + (secret ? 'password' : 'text') + '" data-prov="' + esc(id) + '" data-key="' + esc(k) + '"' +
                        ' value="' + esc(val) + '" placeholder="' + esc(m.hint || '') + '">' +
                    (m.hint ? '<span class="mp-hint">' + esc(m.hint) + '</span>' : '') +
                '</div>';
            }).join('');

            var validated = entry && entry.validated_at
                ? 'Last tested ' + esc(entry.validated_at) : 'Never tested';
            var err = (entry && entry.validation_error)
                ? '<div class="mp-msg mp-msg-bad">' + esc(entry.validation_error) + '</div>' : '';

            return '<div class="mp-prov">' +
                '<div class="mp-prov-head"><div class="mp-prov-name">' + esc(spec.label) + '</div>' +
                    statusPill(entry) + '</div>' +
                '<div class="mp-prov-note" style="margin-bottom:8px">Renderer availability: ' + availabilityChips(id) + '</div>' +
                (spec.note ? '<div class="mp-prov-note">' + esc(spec.note) + '</div>' : '') +
                '<div class="mp-row">' + fields + '</div>' +
                '<div class="mp-prov-actions">' +
                    '<button class="mp-btn" data-act="save" data-prov="' + esc(id) + '">Save credentials</button>' +
                    '<button class="mp-btn" data-act="test" data-prov="' + esc(id) + '">Test connection</button>' +
                    '<button class="mp-btn mp-btn-primary" data-act="activate" data-prov="' + esc(id) + '"' +
                        ((entry && entry.validation_status === 'valid' && !entry.enabled) ? '' : ' disabled') +
                        '>Activate</button>' +
                    '<button class="mp-btn" data-act="deactivate" data-prov="' + esc(id) + '"' +
                        ((entry && entry.enabled) ? '' : ' disabled') + '>Disable</button>' +
                '</div>' +
                '<div class="mp-hint" style="margin-top:8px">' + validated + '</div>' + err +
                '<div class="mp-msg" data-msg="' + esc(id) + '"></div>' +
            '</div>';
        }).join('');
    }

    /**
     * Options for a provider select.
     *
     * `platform` null → the GLOBAL select, which needs a provider that renders
     * on every surface. Otherwise the per-platform select, which needs a
     * renderer for that platform.
     *
     * Unusable options are DISABLED and labelled, not hidden: an administrator
     * should be able to see that TomTom exists and why they cannot pick it for
     * the Parent app, rather than wonder where it went.
     */
    function providerOptions(selected, platform) {
        var out = '<option value="">— none —</option>';
        Object.keys(REGISTRY).forEach(function (id) {
            var entry = (CONFIG.providers || {})[id];
            var live = entry && entry.enabled;
            var av = AVAIL[id] || { platforms: [], global_capable: false };
            var usable = platform === null
                ? av.global_capable
                : av.platforms.indexOf(platform) !== -1;
            // A no-surface platform (teacher/driver) can hold any provider —
            // it renders nothing either way, so the choice is stored for later.
            if (platform !== null && SURFACES.indexOf(platform) === -1) { usable = true; }

            var why = '';
            if (!usable) {
                why = platform === null ? ' — no renderer on all surfaces' : ' — no ' + platform + ' renderer';
            } else if (!live) {
                why = ' (not activated)';
            }
            out += '<option value="' + esc(id) + '"' +
                   (selected === id ? ' selected' : '') +
                   (usable ? '' : ' disabled') + '>' +
                   esc(REGISTRY[id].label) + esc(why) + '</option>';
        });
        return out;
    }

    /** Per-provider availability chips, so the limitation is visible up front. */
    function availabilityChips(id) {
        var av = AVAIL[id] || { platforms: [] };
        return ['admin', 'parent', 'teacher', 'driver'].map(function (p) {
            var has = av.platforms.indexOf(p) !== -1;
            var noSurface = SURFACES.indexOf(p) === -1;
            var cls = has ? 'mp-pill-ok' : (noSurface ? 'mp-pill-idle' : 'mp-pill-bad');
            var mark = has ? '✓' : (noSurface ? '–' : '✗');
            return '<span class="mp-pill ' + cls + '" title="' +
                   (has ? 'renderer available' : (noSurface ? 'no map surface yet' : 'no renderer yet')) +
                   '">' + mark + ' ' + esc(p) + '</span>';
        }).join(' ');
    }

    function renderMode() {
        var mode = CONFIG.mode || 'global';
        el('mp-mode').value = mode;
        el('mp-global').innerHTML = providerOptions(CONFIG.global_provider || '', null);
        el('mp-global-wrap').style.display = '';
        var pw = el('mp-platform-wrap');
        pw.style.display = (mode === 'platform') ? 'flex' : 'none';
        pw.innerHTML = (CONFIG.platform_provider ? Object.keys(CONFIG.platform_provider) : [])
            .map(function (p) {
                return '<div class="mp-field" style="max-width:240px">' +
                    '<label>' + esc(p) + ' <span class="mp-hint">(now: ' + esc(RESOLVED[p] || 'none') + ')</span></label>' +
                    '<select data-platform="' + esc(p) + '">' +
                        providerOptions(CONFIG.platform_provider[p] || '', p) + '</select></div>';
            }).join('');
    }

    function renderDefaults() {
        var c = CONFIG.default_center || {};
        el('mp-lat').value = (c.lat === null || c.lat === undefined) ? '' : c.lat;
        el('mp-lng').value = (c.lng === null || c.lng === undefined) ? '' : c.lng;
        el('mp-zoom').value = CONFIG.default_zoom || 15;
    }

    function loadAudit() {
        get('audit_history').then(function (j) {
            var rows = j.rows || [];
            el('mp-audit-body').innerHTML = rows.length
                ? rows.map(function (r) {
                    return '<tr><td>' + esc(r.created_at) + '</td><td>' + esc(r.action) +
                           '</td><td>' + esc(r.subject) + '</td><td>' + esc(r.actor) + '</td></tr>';
                  }).join('')
                : '<tr><td colspan="4" style="color:var(--t3)">No changes recorded yet.</td></tr>';
        }).catch(function (e) {
            el('mp-audit-body').innerHTML = '<tr><td colspan="4" class="mp-msg-bad">' + esc(e.message) + '</td></tr>';
        });
    }

    function loadAll() {
        return get('get_config').then(function (j) {
            CONFIG = j.config || {}; RESOLVED = j.resolved || {}; REGISTRY = j.registry || {};
            AVAIL = j.availability || {}; SURFACES = j.surfaces || [];
            renderProviders(); renderMode(); renderDefaults();
        }).catch(function (e) {
            el('mp-providers').innerHTML = '<div class="mp-msg mp-msg-bad">' + esc(e.message) + '</div>';
        });
    }

    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('button[data-act]');
        if (!b) { return; }
        var act = b.getAttribute('data-act'), prov = b.getAttribute('data-prov');
        var msg = document.querySelector('[data-msg="' + prov + '"]');
        b.disabled = true;

        var done = function () { b.disabled = false; };
        var reloadThen = function (m, ok) { say(msg, m, ok); loadAll().then(loadAudit).then(done); };

        if (act === 'save') {
            var body = { provider: prov };
            document.querySelectorAll('input[data-prov="' + prov + '"]').forEach(function (i) {
                body[i.getAttribute('data-key')] = i.value;
            });
            post('save_provider', body)
                .then(function (j) { reloadThen(j.message || 'Saved.', true); })
                .catch(function (e) { say(msg, e.message, false); done(); });
        } else if (act === 'test') {
            say(msg, 'Testing…', true);
            post('test_connection', { provider: prov })
                .then(function (j) { reloadThen(j.message, !!j.ok); })
                .catch(function (e) { say(msg, e.message, false); done(); });
        } else if (act === 'activate' || act === 'deactivate') {
            post(act === 'activate' ? 'activate' : 'deactivate', { provider: prov })
                .then(function (j) { reloadThen(j.message, true); })
                .catch(function (e) { say(msg, e.message, false); done(); });
        }
    });

    el('mp-mode').addEventListener('change', function () {
        CONFIG.mode = this.value; renderMode();
    });

    el('mp-save-mode').addEventListener('click', function () {
        var body = { mode: el('mp-mode').value, global_provider: el('mp-global').value };
        document.querySelectorAll('select[data-platform]').forEach(function (s) {
            body['platform_' + s.getAttribute('data-platform')] = s.value;
        });
        post('save_mode', body)
            .then(function (j) { say(el('mp-mode-msg'), j.message, true); return loadAll().then(loadAudit); })
            .catch(function (e) { say(el('mp-mode-msg'), e.message, false); });
    });

    el('mp-save-defaults').addEventListener('click', function () {
        post('save_defaults', {
            default_lat: el('mp-lat').value, default_lng: el('mp-lng').value, default_zoom: el('mp-zoom').value
        }).then(function (j) { say(el('mp-defaults-msg'), j.message, true); return loadAudit(); })
          .catch(function (e) { say(el('mp-defaults-msg'), e.message, false); });
    });

    loadAll().then(loadAudit);
}());
</script>
