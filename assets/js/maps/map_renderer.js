/**
 * ZenXii admin — map renderer abstraction.
 *
 * The web counterpart of the apps' `MapRenderer` interface, and the same
 * bargain: panel code calls plain geographic methods, and the only file that
 * knows which mapping library is loaded is a renderer implementation.
 *
 * Provider selection is NOT made here. The panel resolves it from the
 * centralized configuration (`GET maps_config/client_config?platform=admin`),
 * which reads Firestore `mapsConfig` — global vs platform mode, active
 * provider, public credentials. Changing the provider in System Settings
 * changes the map with no code change and no redeploy.
 *
 * Adding a provider means adding one implementation and one line in
 * `ZenxMapRendererFactory`. Transport logic is untouched by design; nothing in
 * this file knows what a trip or a vehicle is — only points on the earth.
 *
 * Usage:
 *   const r = await ZenxMapRendererFactory.forPlatform('admin');
 *   if (!r.isAvailable()) { …show the reason…; return; }
 *   await r.initialize(document.getElementById('map'));
 *   r.addMarker('bus', lat, lng);
 *   r.animateCamera(lat, lng, 1000);
 */
(function (global) {
    'use strict';

    /**
     * The contract. Methods mirror the Android interface one-for-one, so the
     * two platforms cannot drift into different mental models of "a map".
     */
    class ZenxMapRenderer {
        constructor(providerId, credentials) {
            this.providerId = providerId;
            this.credentials = credentials || {};
        }
        /** False when the library or its credentials are missing. */
        isAvailable() { return false; }
        async initialize(_container, _options) { throw new Error('not implemented'); }
        moveCamera(_lat, _lng, _zoom) {}
        animateCamera(_lat, _lng, _durationMs) {}
        addMarker(_id, _lat, _lng, _opts) {}
        updateMarker(_id, _lat, _lng, _opts) {}
        removeMarker(_id) {}
        drawPolyline(_id, _points) {}
        clearPolyline(_id) {}
        /**
         * GAP-2 (2026-08-16) — provider-neutral map click.
         *
         * The handler receives ONLY `{lat, lng}` as plain numbers. Leaflet's
         * LatLng, MapLibre's LngLat and Google's google.maps.LatLng are each
         * unwrapped INSIDE their renderer and never cross this boundary, so a
         * caller that places a stop by clicking cannot acquire a dependency on
         * whoever drew the map. Pass null to detach.
         */
        onMapClick(_handler) {}
        /** Same contract for a dragged marker: `{lat, lng}`, nothing else. */
        onMarkerDrag(_id, _handler) {}
        dispose() {}
    }

    /**
     * Leaflet — the admin panel's current implementation, wrapped rather than
     * rewritten.
     *
     * Tiles come from the configured provider's style/URL template. Attribution
     * stays on: it is a licence term of every OSM tile source, not decoration.
     */
    class LeafletRenderer extends ZenxMapRenderer {
        constructor(providerId, credentials) {
            super(providerId, credentials);
            this._map = null;
            this._markers = {};
            this._lines = {};
        }

        isAvailable() {
            return typeof global.L !== 'undefined' && !!this._tileUrl();
        }

        /**
         * Resolve a raster tile URL from whatever the administrator supplied.
         *
         * Leaflet renders RASTER tiles; a MapLibre/Mapbox `style.json` is a
         * vector style and will not load in it. Where only a style URL exists,
         * the provider's raster endpoint is derived from the same key — and if
         * that is not possible the renderer reports unavailable rather than
         * silently drawing an empty grid.
         */
        _tileUrl() {
            const c = this.credentials;
            if (c.tile_url) { return c.tile_url; }
            if (this.providerId === 'maptiler' && c.api_key) {
                return 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=' + encodeURIComponent(c.api_key);
            }
            if (this.providerId === 'tomtom' && c.api_key) {
                return 'https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?key=' + encodeURIComponent(c.api_key);
            }
            if (this.providerId === 'maplibre' && c.style_url) {
                const m = /[?&]key=([^&]+)/.exec(c.style_url);
                if (m) {
                    return 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=' + m[1];
                }
            }
            return '';
        }

        async initialize(container, options) {
            const opts = options || {};
            this._map = global.L.map(container).setView(
                [opts.lat || 0, opts.lng || 0], opts.zoom || 13
            );
            global.L.tileLayer(this._tileUrl(), {
                maxZoom: 19,
                attribution: this.credentials.attribution || '&copy; OpenStreetMap contributors'
            }).addTo(this._map);
            return this._map;
        }

        moveCamera(lat, lng, zoom) {
            if (this._map) { this._map.setView([lat, lng], zoom || this._map.getZoom()); }
        }
        animateCamera(lat, lng, durationMs) {
            if (this._map) {
                this._map.panTo([lat, lng], { animate: true, duration: (durationMs || 1000) / 1000 });
            }
        }
        /**
         * Markers are SVG circles, NOT `L.marker()`.
         *
         * Two reasons, both deliberate:
         *  1. CSP. Leaflet's default marker icon is a PNG fetched from the CDN
         *     that serves its stylesheet, which would force that CDN into
         *     `img-src`. A circleMarker is vector — drawn by Leaflet, no image
         *     request — so the policy only has to allow actual map tiles.
         *  2. State. A fleet marker has to encode tracking state (live / stale /
         *     no signal / not tracking) at a glance, and a recoloured circle
         *     does that without shipping four icon files.
         *
         * `opts.color` is supplied by the caller from canonical state; this
         * renderer assigns no meaning of its own.
         */
        addMarker(id, lat, lng, opts) {
            if (!this._map) { return; }
            this.removeMarker(id);
            const o = opts || {};
            this._markers[id] = global.L.circleMarker([lat, lng], {
                radius: o.radius || 8,
                color: o.color || '#0E7C7B',
                fillColor: o.color || '#0E7C7B',
                fillOpacity: o.fillOpacity === undefined ? 0.9 : o.fillOpacity,
                weight: 2
            }).addTo(this._map);
            if (o.tooltip) { this._markers[id].bindTooltip(String(o.tooltip)); }
        }
        updateMarker(id, lat, lng, opts) {
            const m = this._markers[id];
            if (!m) { this.addMarker(id, lat, lng, opts); return; }
            m.setLatLng([lat, lng]);
            const o = opts || {};
            if (o.color) { m.setStyle({ color: o.color, fillColor: o.color }); }
            if (o.tooltip) { m.bindTooltip(String(o.tooltip)); }
        }
        removeMarker(id) {
            if (this._markers[id]) { this._map.removeLayer(this._markers[id]); delete this._markers[id]; }
        }
        /**
         * Leaflet's click event carries `e.latlng`, a Leaflet LatLng instance.
         * It is destructured to two Numbers here so the caller never touches a
         * Leaflet type — that unwrapping IS the abstraction boundary.
         */
        onMapClick(handler) {
            if (!this._map) { return; }
            if (this._clickFn) { this._map.off('click', this._clickFn); this._clickFn = null; }
            if (typeof handler !== 'function') { return; }
            this._clickFn = function (e) {
                handler({ lat: Number(e.latlng.lat), lng: Number(e.latlng.lng) });
            };
            this._map.on('click', this._clickFn);
        }
        /**
         * Draggable marker. Leaflet circleMarker is NOT draggable, so a picker
         * marker is created as a real L.marker — kept in the same registry so
         * removeMarker()/dispose() still clean it up.
         */
        onMarkerDrag(id, handler) {
            const m = this._markers[id];
            if (!m || typeof m.on !== 'function' || typeof handler !== 'function') { return; }
            if (m.dragging && typeof m.dragging.enable === 'function') { m.dragging.enable(); }
            m.on('dragend', function () {
                const p = m.getLatLng();
                handler({ lat: Number(p.lat), lng: Number(p.lng) });
            });
        }
        /**
         * A pin for coordinate picking (visually distinct from fleet dots).
         *
         * Deliberately an SVG circleMarker, NOT Leaflet's L.marker: the default
         * marker is a PNG fetched from Leaflet's own CDN, which would force
         * another origin into CSP img-src for the sake of one pin. Same
         * reasoning as the fleet map's markers — the allowlist stays at exactly
         * two map origins, and CH-TRN-CSP-1 enforces it.
         *
         * Consequence, accepted: Leaflet cannot drag a circleMarker, so this
         * renderer offers no onMarkerDrag. Repositioning is done by clicking
         * the new spot, which is equivalent for placing a stop and costs no
         * CSP surface. Providers with native draggable vector markers may
         * implement onMarkerDrag; the contract allows it, nothing requires it.
         */
        addDraggableMarker(id, lat, lng) {
            if (!this._map) { return; }
            this.addMarker(id, lat, lng, { color: '#BC5A3C', radius: 9, fillOpacity: 1 });
        }
        drawPolyline(id, points) {
            if (!this._map) { return; }
            this.clearPolyline(id);
            if (!points || points.length < 2) { return; }
            this._lines[id] = global.L.polyline(points, { weight: 4 }).addTo(this._map);
        }
        clearPolyline(id) {
            if (this._lines[id]) { this._map.removeLayer(this._lines[id]); delete this._lines[id]; }
        }
        dispose() {
            if (this._map) { this._map.remove(); this._map = null; }
            this._markers = {}; this._lines = {};
        }
    }

    /**
     * Providers whose web SDK is not loaded in the panel.
     *
     * Reports unavailable and no-ops rather than throwing, so selecting one in
     * System Settings degrades to a clear message and recovers on switching
     * back — it can never break the page.
     */
    class PendingWebRenderer extends ZenxMapRenderer {
        isAvailable() { return false; }
        async initialize(container) {
            if (container) {
                container.textContent =
                    'The selected map provider (' + this.providerId +
                    ') is not yet available in the admin panel.';
            }
            return null;
        }
    }

    var ZenxMapRendererFactory = {
        /** provider id → renderer. The ONLY provider switch in the panel. */
        create: function (providerId, credentials) {
            switch (providerId) {
                case 'maplibre':
                case 'maptiler':
                case 'tomtom':
                    // Raster tiles Leaflet can consume directly.
                    return new LeafletRenderer(providerId, credentials);
                case 'google':
                case 'mapbox':
                case 'here':
                    return new PendingWebRenderer(providerId, credentials);
                default:
                    return null;
            }
        },

        /**
         * Resolve from the centralized configuration.
         *
         * Returns null when no provider is configured — callers must render
         * that state rather than defaulting, so a misconfiguration is visible
         * to the administrator who can fix it.
         */
        forPlatform: function (platform) {
            var base = (global.ZENX_BASE_URL || '').replace(/\/$/, '');
            return fetch(base + '/maps_config/client_config?platform=' + encodeURIComponent(platform || 'admin'),
                         { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    // fetch() does not reject on 4xx/5xx — check both the
                    // transport status and the application-level status, or a
                    // denied request renders as success.
                    return r.json().catch(function () { return { status: 'error' }; })
                        .then(function (j) {
                            if (!r.ok || j.status === 'error' || !j.config) { return null; }
                            var cfg = j.config;
                            if (!cfg.provider || !cfg.enabled) { return null; }
                            return ZenxMapRendererFactory.create(cfg.provider, cfg.credentials || {});
                        });
                })
                .catch(function () { return null; });
        }
    };

    global.ZenxMapRenderer = ZenxMapRenderer;
    global.ZenxMapRendererFactory = ZenxMapRendererFactory;
}(window));
