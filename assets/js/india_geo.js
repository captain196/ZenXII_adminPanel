/*
 * india_geo.js — shared India states/districts cascade for all address forms.
 * Data source of truth: assets/data/india_geo.json (also read by PHP for the staff import).
 *
 * Markup contract (no JS needed per-form beyond loading this file):
 *   State select    : <select id="state" data-india-state [data-india-fill] [data-india-selected="Kerala"]>
 *   District select  : <select id="city"  data-india-district="state" [data-india-selected="Ernakulam"]>
 *   - data-india-fill  -> JS also populates the state <select> options (omit if states are hardcoded)
 *   - data-india-district="<stateSelectId>" -> links a district select to its state select; cascades on change
 *   - data-india-selected -> pre-selects a saved value (edit forms). A saved value not in the
 *                            official list is preserved as "<value> (existing)" so legacy data never drops.
 *
 * Programmatic API (window.IndiaGeo):
 *   IndiaGeo.load()                              -> Promise<{states, districts}>
 *   IndiaGeo.fillStates(selectEl, selected?)     -> fill a state <select>
 *   IndiaGeo.fillDistricts(stateEl, distEl, sel?)-> fill a district <select> from stateEl.value
 *   IndiaGeo.wire(stateEl, distEl, opts?)        -> attach cascade + initial fill
 */
(function () {
  'use strict';

  var JSON_URL = (function () {
    var s = document.currentScript;
    if (s && s.src) return s.src.replace(/\/js\/india_geo\.js.*$/, '/data/india_geo.json');
    return '/assets/data/india_geo.json';
  })();

  var _promise = null;
  function load() {
    if (!_promise) {
      _promise = fetch(JSON_URL, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('india_geo ' + r.status); return r.json(); })
        .catch(function (e) { console.error('IndiaGeo: dataset load failed', e); return { states: [], districts: {} }; });
    }
    return _promise;
  }

  function opt(val, label, selected) {
    var o = document.createElement('option');
    o.value = val;
    o.textContent = label != null ? label : val;
    if (selected) o.selected = true;
    return o;
  }

  function fillStates(sel, selected) {
    if (!sel) return load();
    return load().then(function (g) {
      var cur = selected != null ? selected : (sel.getAttribute('data-india-selected') || '');
      var ph = sel.getAttribute('data-india-placeholder') || 'Select State';
      var frag = document.createDocumentFragment();
      frag.appendChild(opt('', ph, false));
      g.states.forEach(function (s) { frag.appendChild(opt(s, s, s === cur)); });
      sel.innerHTML = '';
      sel.appendChild(frag);
      return g;
    });
  }

  function fillDistricts(stateEl, distEl, selected) {
    if (!distEl) return load();
    return load().then(function (g) {
      var st = stateEl ? stateEl.value : '';
      var cur = selected != null ? selected : (distEl.getAttribute('data-india-selected') || '');
      var ph = distEl.getAttribute('data-india-placeholder') || 'Select District';
      var list = (g.districts && g.districts[st]) || [];
      var frag = document.createDocumentFragment();
      frag.appendChild(opt('', ph, false));
      list.forEach(function (d) { frag.appendChild(opt(d, d, d === cur)); });
      // Never drop a saved value that isn't in the official list (legacy / custom entries).
      if (cur && list.indexOf(cur) === -1) frag.appendChild(opt(cur, cur + ' (existing)', true));
      distEl.innerHTML = '';
      distEl.appendChild(frag);
      return g;
    });
  }

  function wire(stateEl, distEl, opts) {
    opts = opts || {};
    if (stateEl) {
      stateEl.addEventListener('change', function () { fillDistricts(stateEl, distEl, ''); });
    }
    var initSel = opts.distSelected != null
      ? opts.distSelected
      : (distEl ? (distEl.getAttribute('data-india-selected') || '') : '');
    return fillDistricts(stateEl, distEl, initSel);
  }

  function autoInit() {
    // 1) Fill state selects that opt in (staff/admission/config forms with empty selects).
    document.querySelectorAll('select[data-india-state][data-india-fill]').forEach(function (sel) {
      fillStates(sel);
    });
    // 2) Wire every district select to its named state select and cascade.
    document.querySelectorAll('select[data-india-district]').forEach(function (distEl) {
      var id = distEl.getAttribute('data-india-district');
      var stateEl = id ? document.getElementById(id) : null;
      wire(stateEl, distEl, {});
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoInit);
  else autoInit();

  window.IndiaGeo = { load: load, fillStates: fillStates, fillDistricts: fillDistricts, wire: wire };
})();
