/**
 * Support Desk — shared front-end helpers (P1, read-only).
 *
 * Loaded by views/support/{index,mine,thread}.php. It lives here rather than
 * inline in each view for one reason that matters more than tidiness: the
 * fail-closed fetch below must behave IDENTICALLY on every screen. Three inline
 * copies is how one of them quietly loses its error branch and starts rendering
 * a 403 as "no tickets".
 *
 * Everything that reaches innerHTML through these helpers is escaped here.
 */
(function (w) {
  'use strict';

  var SD = {};

  /** Base URL with no trailing slash. Set once by the view. */
  SD.base = '';

  /** HTML-escape. Every parent-supplied string passes through this. */
  SD.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  /** Relative time from epoch seconds. */
  SD.ago = function (sec) {
    if (!sec) return '—';
    var d = Math.floor(Date.now() / 1000) - sec;
    if (d < 60) return 'just now';
    if (d < 3600) return Math.floor(d / 60) + 'm ago';
    if (d < 86400) return Math.floor(d / 3600) + 'h ago';
    if (d < 2592000) return Math.floor(d / 86400) + 'd ago';
    return new Date(sec * 1000).toLocaleDateString();
  };

  /** Absolute date-time from epoch seconds. */
  SD.when = function (sec) {
    return sec ? new Date(sec * 1000).toLocaleString() : '—';
  };

  /**
   * Fail-closed JSON GET.
   *
   * fetch() does NOT reject on 403 or 500 — it resolves with ok:false. The
   * documented failure in this panel is a denied request reported as success.
   * On a read screen the equivalent is worse: a denied query rendering as an
   * empty list, because an empty queue looks like good news rather than a bug.
   *
   * So this throws on: a non-ok status, a non-JSON body, or a {status:'error'}
   * payload. It never resolves to something a caller could mistake for "no
   * results". Callers must render a distinct error state.
   */
  SD.getJSON = function (url) {
    return fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (txt) {
        var body = null;
        try { body = JSON.parse(txt); } catch (e) { /* handled below */ }

        if (!r.ok) {
          // 401/403 here usually means the session died or the module was
          // revoked mid-session. Say so plainly instead of "failed".
          var msg = (body && body.message) ||
            (r.status === 401 || r.status === 403
              ? 'Your session or access to this module has changed. Reload and sign in again.'
              : 'Request failed (' + r.status + ').');
          throw new Error(msg);
        }
        if (!body || typeof body !== 'object') {
          throw new Error('The server returned an unreadable response.');
        }
        if (body.status === 'error') {
          throw new Error(body.message || 'The server refused this request.');
        }
        return body;
      });
    });
  };

  /**
   * CSRF state. Set once by the view from the CI token.
   *
   * Support is a NORMAL panel module — it is not in csrf_exclude_uris, and it
   * must not be added there. Only superadmin/* and a few webhook routes are
   * excluded; the documented symptom of a missing token on a protected route
   * is a blank 403 with nothing in the log and nothing in the console.
   *
   * csrf_regenerate is FALSE in config, so the hash is stable for the session.
   * Every JSON response still carries a fresh csrf_token, and we adopt it —
   * that keeps working if regeneration is ever turned on.
   */
  SD.csrfName = 'csrf_token';
  SD.csrfHash = '';

  /**
   * Fail-closed JSON POST.
   *
   * Same contract as getJSON: a non-ok status, a non-JSON body, or a
   * {status:'error'} payload all throw. That matters more on writes than on
   * reads — fetch() resolving on a 403 is precisely how a denied action gets
   * reported to the user as done.
   *
   * @param {string} url
   * @param {object} fields  plain values; arrays are sent as name[] repeats
   */
  SD.postJSON = function (url, fields) {
    var fd = new FormData();
    fd.append(SD.csrfName, SD.csrfHash);
    Object.keys(fields || {}).forEach(function (k) {
      var v = fields[k];
      if (Array.isArray(v)) v.forEach(function (item) { fd.append(k + '[]', item); });
      else if (v !== undefined && v !== null) fd.append(k, v);
    });

    return fetch(url, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (txt) {
        var body = null;
        try { body = JSON.parse(txt); } catch (e) { /* handled below */ }

        // Adopt a rotated token before deciding success or failure — a
        // rejected request still returns one, and the retry needs it.
        if (body && body.csrf_token) SD.csrfHash = body.csrf_token;

        if (!r.ok) {
          throw new Error((body && body.message) ||
            (r.status === 403
              ? 'That action was refused. Your access may have changed — reload and try again.'
              : 'Request failed (' + r.status + ').'));
        }
        if (!body || typeof body !== 'object') {
          throw new Error('The server returned an unreadable response. Nothing was saved.');
        }
        if (body.status === 'error') {
          throw new Error(body.message || 'The server refused this request.');
        }
        return body;
      });
    });
  };

  /** Status pill markup. */
  SD.pill = function (s) {
    var cls = {
      open: 'sd-open', assigned: 'sd-assigned', reopened: 'sd-reopened',
      resolved: 'sd-resolved', closed: 'sd-closed'
    }[s] || 'sd-closed';
    return '<span class="sd-pill ' + cls + '">' + SD.esc(s) + '</span>';
  };

  /**
   * One queue/list row.
   *
   * EVERY interpolation is escaped, URI-encoded, or a number coerced with
   * Number(). If you add a column, escape it — the subject is parent-supplied
   * free text and is the module's most obvious injection vector.
   *
   * opts.showAssignee — false on "My Tickets", where it is always you.
   */
  SD.rowHTML = function (t, opts) {
    opts = opts || {};
    var no   = t.ticketNo ? ('#' + t.ticketNo) : '—';
    var msgs = Number(t.messageCount) || 0;
    var atts = Number(t.attachments) || 0;

    var stu = t.isAnonymous
      ? '<em>withheld</em>'
      : (SD.esc(t.studentName || '—') +
         (t.className ? '<div class="sd-meta">' + SD.esc(t.className) + '</div>' : ''));

    var att = atts
      ? '<div class="sd-meta">' + atts + ' attachment' + (atts === 1 ? '' : 's') + '</div>'
      : '';

    // Escaped name or a styled placeholder — never a string-replace on escaped
    // output, which would inject markup for a staff member named "<unassigned>".
    var assignee = t.assignedName
      ? SD.esc(t.assignedName)
      : '<span class="sd-meta">Unassigned</span>';

    var html = '<tr>' +
      '<td class="sd-no">' + SD.esc(no) + '</td>' +
      '<td><a class="sd-subj" href="' + SD.base + '/support/thread/' +
        encodeURIComponent(t.ticketId) + '">' + SD.esc(t.subject || '(no subject)') + '</a>' +
        '<div class="sd-meta">' + msgs + ' message' + (msgs === 1 ? '' : 's') + '</div>' + att +
      '</td>' +
      '<td>' + SD.esc(t.category || '—') + '</td>' +
      '<td>' + stu + '</td>' +
      '<td>' + SD.pill(t.status) +
        (t.awaitingUs ? '<span class="sd-pill sd-await">awaiting us</span>' : '') + '</td>';

    if (opts.showAssignee !== false) html += '<td>' + assignee + '</td>';

    html += '<td>' + SD.esc(SD.ago(t.lastMessageAt)) + '</td></tr>';
    return html;
  };

  /**
   * Generic paginated list mount, shared by the queue and My Tickets.
   *
   * cfg: { rows, state, more, moreWrap, url(cursor), empty(), showAssignee }
   *
   * ⚠ cfg.empty() MUST return already-escaped HTML. It is written straight to
   * innerHTML, so any dynamic value inside it — a search term, a filter name —
   * has to go through SD.esc() at the call site. Everything else this function
   * renders is escaped here; empty() is the one string it cannot vouch for.
   *
   * Returns { reload, loadMore, reset }.
   */
  SD.mountList = function (cfg) {
    var cursor = null, loading = false;

    /** Writes HTML. Callers are responsible for escaping — see cfg.empty(). */
    function render(msg, isError) {
      cfg.state.hidden = false;
      cfg.state.className = isError ? 'sd-state sd-err' : 'sd-state';
      cfg.state.innerHTML = msg;
    }

    function load(append) {
      if (loading) return;
      loading = true;
      if (!append) { cfg.rows.innerHTML = ''; cfg.moreWrap.hidden = true; }
      render('Loading…', false);

      SD.getJSON(cfg.url(append ? cursor : null)).then(function (d) {
        var list = d.rows || [];
        var html = list.map(function (t) {
          return SD.rowHTML(t, { showAssignee: cfg.showAssignee });
        }).join('');

        if (append) cfg.rows.insertAdjacentHTML('beforeend', html);
        else        cfg.rows.innerHTML = html;

        if (cfg.rows.children.length === 0) render(cfg.empty(), false);
        else cfg.state.hidden = true;

        cursor = d.nextCursor || null;
        cfg.moreWrap.hidden = !cursor;
      }).catch(function (e) {
        // Deliberately NOT an empty state — a failure must never read as
        // "no tickets". Offer a retry rather than a dead screen.
        render('<b>Could not load tickets</b>' + SD.esc(e.message) +
               '<br><br><button class="sd-btn" data-sd-retry>Try again</button>', true);
        var btn = cfg.state.querySelector('[data-sd-retry]');
        if (btn) btn.addEventListener('click', function () { load(false); });
        cfg.moreWrap.hidden = true;
      }).then(function () { loading = false; });
    }

    cfg.more.addEventListener('click', function () { load(true); });

    return {
      reload:   function () { cursor = null; load(false); },
      loadMore: function () { load(true); },
      reset:    function () { cursor = null; }
    };
  };

  w.SD = SD;
})(window);
