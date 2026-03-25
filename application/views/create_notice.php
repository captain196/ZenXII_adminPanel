<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
    <div class="cn-wrap">

        <!-- ── Page header ── -->
        <div class="cn-page-head">
            <div class="cn-page-head-left">
                <h2><i class="fa fa-bell"></i> Create Notice</h2>
                <ol class="cn-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li><a href="<?= base_url('NoticeAnnouncement') ?>">Notices</a></li>
                    <li>Create</li>
                </ol>
            </div>
            <a href="<?= base_url('NoticeAnnouncement') ?>" class="cn-back-btn">
                <i class="fa fa-list-ul"></i> All Notices
            </a>
        </div>

        <div class="cn-grid">

            <!-- ════════════════════════════════
                 LEFT — Notice form
            ════════════════════════════════ -->
            <div>
                <div class="cn-card">
                    <div class="cn-card-head">
                        <div class="cn-card-icon"><i class="fa fa-file-text-o"></i></div>
                        <div class="cn-card-head-text">
                            <h4>Notice Details</h4>
                            <p>Fill in the title, message and choose recipients</p>
                        </div>
                    </div>
                    <div class="cn-card-body">
                        <form id="cnForm" method="post"
                            action="<?= site_url('NoticeAnnouncement/create_notice') ?>"
                            enctype="multipart/form-data">
                            <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
                                value="<?= $this->security->get_csrf_hash() ?>">
                            <input type="hidden" name="count"
                                value="<?= isset($notices['Count']) ? (int)$notices['Count'] : 0 ?>">
                            <input type="hidden" name="to_id_json" id="cnToIdJson" value="{}">

                            <!-- Title -->
                            <div class="cn-field">
                                <label class="cn-label">Title <span class="req">*</span></label>
                                <input type="text" class="cn-input" name="title" required
                                    maxlength="120"
                                    placeholder="e.g. School Closure on Republic Day">
                            </div>

                            <!-- Description + char counter -->
                            <div class="cn-field">
                                <label class="cn-label">Description <span class="req">*</span></label>
                                <textarea class="cn-textarea" name="description" id="cnDesc"
                                    required maxlength="1000"
                                    placeholder="Write your notice message here…"></textarea>
                                <div class="cn-char-row">
                                    <span id="cnDescCount">0</span>/1000 characters
                                </div>
                            </div>

                            <!-- Priority + Category in a row -->
                            <div class="cn-meta-row">
                                <!-- Priority -->
                                <div class="cn-field cn-field-half">
                                    <label class="cn-label">Priority</label>
                                    <div class="cn-priority-group">
                                        <label class="cn-priority-pill cn-pri-high">
                                            <input type="radio" name="priority" value="High">
                                            <i class="fa fa-exclamation-circle"></i> High
                                        </label>
                                        <label class="cn-priority-pill cn-pri-normal cn-pri-active">
                                            <input type="radio" name="priority" value="Normal" checked>
                                            <i class="fa fa-minus-circle"></i> Normal
                                        </label>
                                        <label class="cn-priority-pill cn-pri-low">
                                            <input type="radio" name="priority" value="Low">
                                            <i class="fa fa-arrow-circle-down"></i> Low
                                        </label>
                                    </div>
                                </div>
                                <!-- Category -->
                                <div class="cn-field cn-field-half">
                                    <label class="cn-label">Category</label>
                                    <select class="cn-select" name="category" id="cnCategory">
                                        <option value="General">📋 General</option>
                                        <option value="Academic">📚 Academic</option>
                                        <option value="Administrative">🏛 Administrative</option>
                                        <option value="Holiday">🎉 Holiday</option>
                                        <option value="Exam">📝 Exam</option>
                                        <option value="Event">🎭 Event</option>
                                    </select>
                                </div>
                            </div>

                            <div class="cn-divider"></div>

                            <!-- Recipients label + count badge -->
                            <div class="cn-recip-header">
                                <label class="cn-label" style="margin:0">
                                    Recipients <span class="req">*</span>
                                </label>
                                <span class="cn-recip-badge" id="cnRecipBadge" style="display:none">
                                    <i class="fa fa-users"></i>
                                    <span id="cnRecipCount">0</span> selected
                                </span>
                            </div>

                            <div class="cn-recip-row">
                                <!-- Bulk -->
                                <div class="cn-check-group">
                                    <div class="cn-group-title"><i class="fa fa-users"></i> Bulk</div>
                                    <?php
                                    $bulkOpts = [
                                        'All School'   => ['fa-globe',           '#e05c6f'],
                                        'All Students' => ['fa-graduation-cap',  '#3dd68c'],
                                        'All Teachers' => ['fa-chalkboard-teacher','#4ab5e3'],
                                        'All Admins'   => ['fa-shield',          '#0f766e'],
                                    ];
                                    foreach ($bulkOpts as $opt => [$icon, $color]):
                                    ?>
                                    <div class="cn-check-row">
                                        <input type="checkbox" name="to_option[]" value="<?= $opt ?>"
                                            id="cnChk_<?= str_replace(' ', '_', $opt) ?>">
                                        <label for="cnChk_<?= str_replace(' ', '_', $opt) ?>">
                                            <i class="fa <?= $icon ?>" style="color:<?= $color ?>;width:14px"></i>
                                            <?= $opt ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- By Class -->
                                <div class="cn-check-group">
                                    <div class="cn-group-title"><i class="fa fa-graduation-cap"></i> By Class</div>
                                    <label class="cn-label" style="margin-top:4px">Select Class / Section</label>
                                    <select class="cn-select" id="cnClassDD">
                                        <option value="">— Select —</option>
                                        <?php foreach ($classes as $val => $lbl): ?>
                                            <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($classes)): ?>
                                    <p class="cn-empty-hint"><i class="fa fa-info-circle"></i> No classes in this session.</p>
                                    <?php endif; ?>
                                </div>

                                <!-- Individual search -->
                                <div class="cn-check-group">
                                    <div class="cn-group-title"><i class="fa fa-search"></i> Individual</div>
                                    <div class="cn-search-wrap">
                                        <i class="fa fa-search cn-search-icon"></i>
                                        <input type="text" class="cn-input" id="cnSearch"
                                            placeholder="Name or ID…" autocomplete="off">
                                        <div id="cnSearchResults"></div>
                                    </div>
                                    <p class="cn-empty-hint" style="margin-top:6px">
                                        <i class="fa fa-info-circle"></i> Search student, teacher or admin by name / ID.
                                    </p>
                                </div>
                            </div>

                            <!-- Selected recipients tags -->
                            <div class="cn-tags-wrap" id="cnTagsWrap">
                                <div class="cn-tags-header">
                                    <span class="cn-tags-title">
                                        <i class="fa fa-check-circle"></i> Selected Recipients
                                    </span>
                                    <button type="button" class="cn-clear-all" id="cnClearAll">
                                        <i class="fa fa-times"></i> Clear All
                                    </button>
                                </div>
                                <div class="cn-tags-list" id="cnTagsList"></div>
                            </div>

                            <button type="submit" class="cn-submit" id="cnSubmitBtn">
                                <i class="fa fa-paper-plane"></i> Send Notice
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════
                 RIGHT — Recent + Stats
            ════════════════════════════════ -->
            <div>

                <!-- Recent Notices -->
                <div class="cn-card">
                    <div class="cn-card-head">
                        <div class="cn-card-icon" style="background:rgba(74,181,227,.12);color:#2a8fbf">
                            <i class="fa fa-clock-o"></i>
                        </div>
                        <div class="cn-card-head-text">
                            <h4>Recent Notices</h4>
                            <p>Last 5 sent this session</p>
                        </div>
                    </div>
                    <div class="cn-card-body" style="padding:10px 16px">
                        <div id="cnRecentList">
                            <p style="font-size:12px;color:var(--muted);text-align:center;padding:12px 0">
                                <i class="fa fa-spinner fa-spin"></i> Loading…
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Session Stats -->
                <div class="cn-card" style="margin-top:14px">
                    <div class="cn-card-head">
                        <div class="cn-card-icon" style="background:rgba(61,214,140,.12);color:#1fa86a">
                            <i class="fa fa-bar-chart"></i>
                        </div>
                        <div class="cn-card-head-text">
                            <h4>Session Stats</h4>
                            <p>Notice summary</p>
                        </div>
                    </div>
                    <div class="cn-card-body" style="padding:12px 16px">
                        <div class="cn-stat-row">
                            <span class="cn-stat-label">Total Notices</span>
                            <span class="cn-stat-val" style="color:var(--gold)">
                                <?= isset($notices['Count']) ? (int)$notices['Count'] : 0 ?>
                            </span>
                        </div>
                        <div class="cn-stat-row">
                            <span class="cn-stat-label">Sent Today</span>
                            <span class="cn-stat-val" id="statToday" style="color:var(--green)">—</span>
                        </div>
                        <div class="cn-stat-row">
                            <span class="cn-stat-label">This Week</span>
                            <span class="cn-stat-val" id="statWeek" style="color:#2a8fbf">—</span>
                        </div>
                    </div>
                </div>

                <!-- Priority legend -->
                <!-- <div class="cn-card" style="margin-top:14px">
                    <div class="cn-card-head">
                        <div class="cn-card-icon" style="background:rgba(224,92,111,.12);color:#e05c6f">
                            <i class="fa fa-info-circle"></i>
                        </div>
                        <div class="cn-card-head-text">
                            <h4>Delivery Paths</h4>
                            <p>Where notices are stored</p>
                        </div>
                    </div>
                    <div class="cn-card-body" style="padding:12px 16px">
                        <div class="cn-path-item">
                            <span class="cn-path-dot" style="background:#e05c6f"></span>
                            <div>
                                <div class="cn-path-label">All School / All Students</div>
                                <div class="cn-path-desc">Announcements node + every Class → Section → Notification</div>
                            </div>
                        </div>
                        <div class="cn-path-item">
                            <span class="cn-path-dot" style="background:#4ab5e3"></span>
                            <div>
                                <div class="cn-path-label">By Class / Section</div>
                                <div class="cn-path-desc">Class → Section → Notification</div>
                            </div>
                        </div>
                        <div class="cn-path-item">
                            <span class="cn-path-dot" style="background:#3dd68c"></span>
                            <div>
                                <div class="cn-path-label">Individual Student</div>
                                <div class="cn-path-desc">Class → Section → Students → {id} → Notification</div>
                            </div>
                        </div>
                        <div class="cn-path-item">
                            <span class="cn-path-dot" style="background:#0f766e"></span>
                            <div>
                                <div class="cn-path-label">Teacher / Admin</div>
                                <div class="cn-path-desc">Teachers / Admins → {id} → Received</div>
                            </div>
                        </div>
                    </div>
                </div> -->

            </div>
        </div>
    </div>
</div>
<div id="cnToastWrap"></div>


<script>
(function () {
    'use strict';

    var SITE        = '<?= rtrim(site_url(), '/') ?>';
    var selectedSet = {}, searchTimer;

    var $form       = document.getElementById('cnForm');
    var $tagWrap    = document.getElementById('cnTagsWrap');
    var $tagList    = document.getElementById('cnTagsList');
    var $hidden     = document.getElementById('cnToIdJson');
    var $classDD    = document.getElementById('cnClassDD');
    var $search     = document.getElementById('cnSearch');
    var $results    = document.getElementById('cnSearchResults');
    var $submit     = document.getElementById('cnSubmitBtn');
    var $badge      = document.getElementById('cnRecipBadge');
    var $badgeCnt   = document.getElementById('cnRecipCount');
    var $desc       = document.getElementById('cnDesc');
    var $descCount  = document.getElementById('cnDescCount');
    var $clearAll   = document.getElementById('cnClearAll');

    /* ── Toast ───────────────────────────────────────────────── */
    function toast(msg, type) {
        var el = document.createElement('div');
        el.className = 'cn-toast cn-toast-' + (type || 'info');
        el.innerHTML = '<i class="fa fa-' + (type === 'success' ? 'check' : 'times') + '-circle"></i> ' + msg;
        document.getElementById('cnToastWrap').appendChild(el);
        setTimeout(function () { el.classList.add('hide'); setTimeout(function () { el.remove(); }, 260); }, 3200);
    }

    /* ── Char counter ────────────────────────────────────────── */
    if ($desc) {
        $desc.addEventListener('input', function () {
            var len = $desc.value.length;
            $descCount.textContent = len;
            $descCount.style.color = len > 900 ? '#e05c6f' : len > 700 ? '#d97706' : '';
        });
    }

    /* ── Priority pills ──────────────────────────────────────── */
    document.querySelectorAll('.cn-priority-pill input[type=radio]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.cn-priority-pill').forEach(function (p) { p.classList.remove('cn-pri-active'); });
            radio.closest('.cn-priority-pill').classList.add('cn-pri-active');
        });
    });

    /* ── Sync hidden field ───────────────────────────────────── */
    function sync() { $hidden.value = JSON.stringify(selectedSet); }

    /* ── Update recipient count badge ────────────────────────── */
    function updateBadge() {
        var n = Object.keys(selectedSet).length;
        if (n > 0) {
            $badgeCnt.textContent = n;
            $badge.style.display = 'inline-flex';
        } else {
            $badge.style.display = 'none';
        }
    }

    /* ── Tag colour by recipient type ────────────────────────── */
    function tagColor(key) {
        if (/^All School/.test(key))    return '#e05c6f';
        if (/^All Students/.test(key))  return '#3dd68c';
        if (/^All Teachers/.test(key))  return '#4ab5e3';
        if (/^All Admins/.test(key))    return '#0f766e';
        if (/^All/.test(key))           return '#e05c6f';
        if (/^STU/.test(key))           return '#3dd68c';
        if (/^STA/.test(key))           return '#4ab5e3';
        if (/^ADM/.test(key))           return '#0f766e';
        return '#0f766e'; // class/section
    }

    /* ── Add tag ─────────────────────────────────────────────── */
    function addTag(key, label) {
        if (selectedSet[key] !== undefined) return;
        selectedSet[key] = label;
        sync();

        var span = document.createElement('span');
        span.className = 'cn-tag';
        span.dataset.key = key;
        span.innerHTML =
            '<span class="cn-tag-dot" style="background:' + tagColor(key) + '"></span>' +
            '<span class="cn-tag-text" title="' + label + '">' + label + '</span>' +
            '<button type="button" class="cn-tag-remove" aria-label="Remove"><i class="fa fa-times"></i></button>';
        span.querySelector('.cn-tag-remove').addEventListener('click', function () {
            removeTag(key);
            // uncheck bulk checkbox if applicable
            var cb = document.querySelector('input[type=checkbox][value="' + CSS.escape(label) + '"]');
            if (cb) cb.checked = false;
        });
        $tagList.appendChild(span);
        $tagWrap.style.display = 'block';
        updateBadge();
        updateControls();
    }

    /* ── Remove tag ──────────────────────────────────────────── */
    function removeTag(key) {
        delete selectedSet[key];
        sync();
        var el = $tagList.querySelector('[data-key="' + CSS.escape(key) + '"]');
        if (el) el.remove();
        if (!Object.keys(selectedSet).length) $tagWrap.style.display = 'none';
        updateBadge();
        updateControls();
    }

    /* ── Clear all tags ──────────────────────────────────────── */
    if ($clearAll) {
        $clearAll.addEventListener('click', function () {
            Object.keys(selectedSet).forEach(function (k) { removeTag(k); });
            document.querySelectorAll('input[type=checkbox][name="to_option[]"]').forEach(function (cb) { cb.checked = false; });
            $classDD.value = '';
        });
    }

    /* ── Disable/enable controls based on "All School" ───────── */
    function updateControls() {
        var isAll    = selectedSet['All School']   !== undefined;
        var isAllStu = selectedSet['All Students'] !== undefined;
        document.querySelectorAll('input[type=checkbox][name="to_option[]"]').forEach(function (cb) {
            cb.disabled = isAll && cb.value !== 'All School';
        });
        $classDD.disabled = isAll || isAllStu;
        $search.disabled  = isAll;
    }

    /* ── Bulk checkboxes ─────────────────────────────────────── */
    document.querySelectorAll('input[type=checkbox][name="to_option[]"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var val = cb.value;
            if (val === 'All School' && cb.checked) {
                Object.keys(selectedSet).forEach(function (k) { if (k !== 'All School') removeTag(k); });
                document.querySelectorAll('input[type=checkbox][name="to_option[]"]').forEach(function (c) {
                    if (c.value !== 'All School') c.checked = false;
                });
                $classDD.value = '';
                $search.value  = '';
                $results.style.display = 'none';
                addTag(val, val);
            } else if (cb.checked) {
                if (selectedSet['All School'] !== undefined) {
                    document.querySelector('input[value="All School"]').checked = false;
                    removeTag('All School');
                }
                addTag(val, val);
            } else {
                removeTag(val);
            }
            updateControls();
        });
    });

    /* ── Class dropdown ──────────────────────────────────────── */
    $classDD.addEventListener('change', function () {
        var v = $classDD.value;
        if (!v) return;
        // v = "Class 8th/Section A"
        var parts   = v.split('/');
        var display = parts[0].trim() + ' / ' + (parts[1] || '').trim();
        addTag(v, display);
        $classDD.value = '';
    });

    /* ── Individual search ───────────────────────────────────── */
    $search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var q = $search.value.trim();
        if (!q) { $results.style.display = 'none'; $results.innerHTML = ''; return; }

        searchTimer = setTimeout(function () {
            fetch(SITE + '/NoticeAnnouncement/search_users?query=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var isExStu = selectedSet['All Students'] !== undefined;
                    var isExTea = selectedSet['All Teachers'] !== undefined;
                    var isExAdm = selectedSet['All Admins']   !== undefined;

                    // Build list of already-selected class keys for duplicate prevention
                    var selCls = Object.keys(selectedSet)
                        .filter(function (k) { return !/^(STU|STA|ADM|All)/.test(k); })
                        .map(function (k)    { return (k.split('/')[0] || '').trim(); });

                    if (!data.length) {
                        $results.innerHTML = '<div class="cn-no-results">No matches found</div>';
                        $results.style.display = 'block';
                        return;
                    }

                    var html = '';
                    data.forEach(function (item) {
                        if (item.type === 'Student' && (isExStu || selCls.includes(item.class_key || ''))) return;
                        if (item.type === 'Teacher' && isExTea) return;
                        if (item.type === 'Admin'   && isExAdm) return;
                        if (selectedSet[item.id] !== undefined) return; // already added

                        var bc = item.type === 'Admin'   ? 'cn-badge-admin' :
                                 item.type === 'Teacher' ? 'cn-badge-teacher' : 'cn-badge-student';
                        html +=
                            '<div class="cn-result" data-id="' + item.id +
                            '" data-label="' + encodeURIComponent(item.label) + '">' +
                            '<span class="cn-badge ' + bc + '">' + item.type.charAt(0) + '</span>' +
                            '<span class="cn-result-text">' + item.label + '</span>' +
                            '</div>';
                    });
                    $results.innerHTML = html || '<div class="cn-no-results">No valid matches</div>';
                    $results.style.display = 'block';
                })
                .catch(function () {
                    $results.innerHTML = '<div class="cn-no-results" style="color:var(--rose)">Search failed</div>';
                    $results.style.display = 'block';
                });
        }, 280);
    });

    $results.addEventListener('click', function (e) {
        var item = e.target.closest('.cn-result');
        if (!item) return;
        addTag(item.dataset.id, decodeURIComponent(item.dataset.label));
        $results.style.display = 'none';
        $results.innerHTML = '';
        $search.value = '';
    });
    document.addEventListener('click', function (e) {
        if (!$search.contains(e.target) && !$results.contains(e.target)) $results.style.display = 'none';
    });

    /* ── Form submit ─────────────────────────────────────────── */
    $form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!Object.keys(selectedSet).length) {
            toast('Please select at least one recipient.', 'error');
            return;
        }
        sync();
        $submit.disabled = true;
        $submit.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending…';

        fetch($form.action, { method: 'POST', body: new FormData($form) })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.status === 'success') {
                    toast('Notice sent successfully!', 'success');
                    setTimeout(function () { location.reload(); }, 1400);
                } else {
                    toast(resp.message || 'Failed to send.', 'error');
                    $submit.disabled = false;
                    $submit.innerHTML = '<i class="fa fa-paper-plane"></i> Send Notice';
                }
            })
            .catch(function (err) {
                toast('Network error: ' + err.message, 'error');
                $submit.disabled = false;
                $submit.innerHTML = '<i class="fa fa-paper-plane"></i> Send Notice';
            });
    });

    /* ── Priority / Category colour maps ─────────────────────── */
    var PRIORITY_COLORS = { High: '#e05c6f', Normal: '#0f766e', Low: '#94a3b8' };
    var CATEGORY_COLORS = {
        General: '#64748b', Academic: '#2563eb', Administrative: '#7c3aed',
        Holiday: '#d97706', Exam: '#e05c6f', Event: '#059669'
    };

    /* ── Load recent notices ─────────────────────────────────── */
    function loadRecent() {
        fetch(SITE + '/NoticeAnnouncement/fetch_recent_notices')
            .then(function (r) { return r.json(); })
            .then(function (notices) {
                var el = document.getElementById('cnRecentList');
                if (!notices || !notices.length) {
                    el.innerHTML = '<p style="font-size:12px;color:var(--muted);text-align:center;padding:10px 0">No notices yet</p>';
                    return;
                }
                var today   = new Date(); today.setHours(0, 0, 0, 0);
                var weekAgo = new Date(today); weekAgo.setDate(weekAgo.getDate() - 7);
                var todayCnt = 0, weekCnt = 0, html = '';

                notices.forEach(function (n) {
                    var ts  = n.Time_Stamp || n.Timestamp || 0;
                    var d   = ts ? new Date(ts) : new Date();
                    var str = d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });

                    var keys = n['To Id'] ? Object.keys(n['To Id']) : [];
                    var rec  = keys.length > 1 ? keys.length + ' recipients' :
                               keys.length === 1 ? keys[0] : 'All';

                    var pri     = n.Priority || 'Normal';
                    var cat     = n.Category || '';
                    var priCol  = PRIORITY_COLORS[pri]  || '#0f766e';
                    var catCol  = CATEGORY_COLORS[cat]  || '#64748b';

                    if (d >= today)   todayCnt++;
                    if (d >= weekAgo) weekCnt++;

                    html +=
                        '<div class="cn-notice-item">' +
                            '<div class="cn-notice-top">' +
                                '<div class="cn-notice-title">' + (n.Title || 'Untitled') + '</div>' +
                                '<span class="cn-pri-dot" style="background:' + priCol + '" title="' + pri + ' Priority"></span>' +
                            '</div>' +
                            '<div class="cn-notice-meta">' +
                                '<span class="cn-notice-time"><i class="fa fa-clock-o"></i> ' + str + '</span>' +
                                (cat ? '<span class="cn-cat-badge" style="background:' + catCol + '22;color:' + catCol + '">' + cat + '</span>' : '') +
                                '<span class="cn-notice-recip">' + rec + '</span>' +
                            '</div>' +
                        '</div>';
                });

                el.innerHTML = html;
                var st = document.getElementById('statToday'); if (st) st.textContent = todayCnt;
                var sw = document.getElementById('statWeek');  if (sw) sw.textContent = weekCnt;
            })
            .catch(function () {
                document.getElementById('cnRecentList').innerHTML =
                    '<p style="font-size:12px;color:var(--rose);text-align:center">Could not load</p>';
            });
    }

    loadRecent();
    updateControls();
})();
</script>


<style>
/* ═══════════════════════════════════════════════════════
   Create Notice Page — ERP theme (teal/navy global vars)
═══════════════════════════════════════════════════════ */
.cn-wrap {
    --gold:      #0f766e;
    --gold2:     #0d6b63;
    --gold-dim:  rgba(15,118,110,.10);
    --gold-ring: rgba(15,118,110,.22);
    --green:     #3dd68c;
    --blue:      #4ab5e3;
    --rose:      #e05c6f;
    --border:    rgba(0,0,0,.09);
    --muted:     #6b7593;
    --r:         10px;
    padding: 24px;
}

/* ── Page head ─────────────────────────────────────── */
.cn-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 22px;
}
.cn-page-head-left h2 {
    font-size: 20px;
    font-weight: 800;
    color: var(--t1, #0d1117);
    margin: 0 0 4px;
    display: flex;
    align-items: center;
    gap: 9px;
}
.cn-page-head-left h2 i { color: var(--gold); }
.cn-breadcrumb {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    gap: 6px;
    font-size: .78rem;
    color: var(--muted);
}
.cn-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.cn-breadcrumb a { color: var(--gold); text-decoration: none; }

.cn-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--bg2, #fff);
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    transition: all .18s;
}
.cn-back-btn:hover { border-color: var(--gold); color: var(--gold); }

/* ── Grid ──────────────────────────────────────────── */
.cn-grid { display: grid; grid-template-columns: 1fr 300px; gap: 18px; align-items: start; }
@media (max-width:1024px) { .cn-grid { grid-template-columns: 1fr; } }

/* ── Card ──────────────────────────────────────────── */
.cn-card {
    background: var(--bg2, #fff);
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    overflow: hidden;
}
.cn-card-head {
    padding: 14px 18px 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}
.cn-card-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: var(--gold-dim);
    color: var(--gold);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}
.cn-card-head-text h4 { font-size: 13.5px; font-weight: 700; color: var(--t1, #0d1117); margin: 0; }
.cn-card-head-text p  { font-size: 11px; color: var(--muted); margin: 0; }
.cn-card-body { padding: 18px; }

/* ── Form fields ───────────────────────────────────── */
.cn-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 6px;
}
.cn-label .req { color: var(--rose); }
.cn-field { margin-bottom: 15px; }

.cn-input, .cn-textarea, .cn-select {
    width: 100%;
    border: 1px solid rgba(0,0,0,.13);
    border-radius: var(--r);
    padding: 9px 12px;
    font-size: 13px;
    font-family: inherit;
    background: var(--bg3, #fafafa);
    color: var(--t1, #1a1f36);
    outline: none;
    transition: border-color .18s, box-shadow .18s;
    box-sizing: border-box;
}
.cn-input:focus, .cn-textarea:focus, .cn-select:focus {
    border-color: var(--gold);
    box-shadow: 0 0 0 3px var(--gold-ring);
    background: var(--bg2, #fff);
}
.cn-textarea { resize: vertical; min-height: 96px; }
.cn-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7593' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    cursor: pointer;
}

/* Char counter */
.cn-char-row {
    text-align: right;
    font-size: 11px;
    color: var(--muted);
    margin-top: 4px;
}

/* Priority + Category row */
.cn-meta-row { display: flex; gap: 14px; }
.cn-field-half { flex: 1; min-width: 0; }

/* Priority pills */
.cn-priority-group {
    display: flex;
    gap: 6px;
}
.cn-priority-pill {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 7px 6px;
    border-radius: 8px;
    border: 1.5px solid var(--border);
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all .16s;
    background: var(--bg3, #f7f8fc);
    color: var(--muted);
    white-space: nowrap;
    user-select: none;
}
.cn-priority-pill input[type=radio] { display: none; }
.cn-pri-high.cn-pri-active  { background: rgba(224,92,111,.12); border-color: #e05c6f; color: #e05c6f; }
.cn-pri-normal.cn-pri-active{ background: var(--gold-dim);      border-color: var(--gold); color: var(--gold); }
.cn-pri-low.cn-pri-active   { background: rgba(148,163,184,.15); border-color: #94a3b8; color: #64748b; }

/* ── Recipients section ────────────────────────────── */
.cn-divider { height: 1px; background: var(--border); margin: 18px 0 14px; }
.cn-recip-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.cn-recip-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--gold-dim);
    color: var(--gold);
    border-radius: 20px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 700;
}

.cn-recip-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }
@media (max-width:768px) { .cn-recip-row { grid-template-columns: 1fr; } }

.cn-check-group {
    background: var(--bg3, #f7f8fc);
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 12px;
}
.cn-group-title {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: var(--muted);
    margin-bottom: 9px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.cn-check-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 0;
    border-bottom: 1px solid rgba(0,0,0,.05);
    cursor: pointer;
}
.cn-check-row:last-child { border-bottom: none; }
.cn-check-row input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--gold); cursor: pointer; flex-shrink:0; }
.cn-check-row label { font-size: 12.5px; color: var(--t2, #444); cursor: pointer; flex: 1; display:flex; align-items:center; gap:6px; }
.cn-check-row:hover label { color: var(--t1, #111); }
.cn-check-row input:disabled + label { opacity: .45; cursor: not-allowed; }

/* Individual search */
.cn-search-wrap { position: relative; }
.cn-search-icon {
    position: absolute;
    left: 11px; top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 12px;
    pointer-events: none;
}
.cn-search-wrap .cn-input { padding-left: 32px; }
#cnSearchResults {
    display: none;
    position: absolute;
    left: 0; right: 0;
    top: calc(100% + 4px);
    background: var(--bg2, #fff);
    border: 1px solid rgba(0,0,0,.12);
    border-radius: 10px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 999;
    box-shadow: 0 6px 24px rgba(0,0,0,.10);
}
.cn-result {
    padding: 9px 12px;
    font-size: 12.5px;
    color: var(--t2, #444);
    cursor: pointer;
    border-bottom: 1px solid rgba(0,0,0,.05);
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background .12s;
}
.cn-result:last-child { border-bottom: none; }
.cn-result:hover { background: var(--gold-dim); color: var(--t1, #111); }
.cn-result-text { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cn-badge { font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 4px; flex-shrink: 0; }
.cn-badge-admin   { background: rgba(15,118,110,.15); color: var(--gold); }
.cn-badge-teacher { background: rgba(74,181,227,.15);  color: #2a8fbf; }
.cn-badge-student { background: rgba(61,214,140,.15);  color: #1fa86a; }
.cn-no-results { padding: 10px 12px; font-size: 12px; color: var(--muted); text-align: center; }

.cn-empty-hint { font-size: 11px; color: var(--muted); margin: 6px 0 0; display:flex; align-items:center; gap:4px; }

/* ── Tags (selected recipients) ────────────────────── */
.cn-tags-wrap {
    display: none;
    margin-top: 14px;
    padding: 12px;
    background: var(--bg3, #f7f8fc);
    border: 1px solid var(--border);
    border-radius: var(--r);
}
.cn-tags-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 9px;
}
.cn-tags-title {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 5px;
}
.cn-tags-title i { color: var(--green); }
.cn-clear-all {
    font-size: 11px;
    color: var(--rose);
    background: none;
    border: none;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    border-radius: 4px;
    transition: background .14s;
}
.cn-clear-all:hover { background: rgba(224,92,111,.1); }

.cn-tags-list { display: flex; flex-wrap: wrap; gap: 6px; }
.cn-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--bg2, #fff);
    border: 1px solid rgba(0,0,0,.11);
    border-radius: 50px;
    padding: 4px 10px 4px 10px;
    font-size: 12px;
    color: var(--t1, #333);
    animation: cnTagIn .16s ease;
}
@keyframes cnTagIn { from { opacity:0; transform:scale(.85) } to { opacity:1; transform:scale(1) } }
.cn-tag-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.cn-tag-text { max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cn-tag-remove {
    background: rgba(224,92,111,.12);
    color: var(--rose);
    border: none;
    border-radius: 50%;
    width: 16px; height: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    transition: background .14s;
    flex-shrink: 0;
}
.cn-tag-remove:hover { background: rgba(224,92,111,.28); }

/* ── Submit button ─────────────────────────────────── */
.cn-submit {
    width: 100%;
    background: var(--gold);
    color: #fff;
    border: none;
    border-radius: var(--r);
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    margin-top: 18px;
    transition: background .18s, box-shadow .18s, transform .12s;
}
.cn-submit:hover { background: var(--gold2); box-shadow: 0 5px 18px rgba(15,118,110,.3); transform: translateY(-1px); }
.cn-submit:disabled { opacity: .55; cursor: not-allowed; transform: none; }

/* ── Recent notices list ───────────────────────────── */
.cn-notice-item { padding: 10px 0; border-bottom: 1px solid var(--border); }
.cn-notice-item:last-child { border-bottom: none; }
.cn-notice-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 6px; }
.cn-notice-title {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--t1, #1a1f36);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}
.cn-pri-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
.cn-notice-meta { display: flex; align-items: center; gap: 6px; margin-top: 4px; flex-wrap: wrap; }
.cn-notice-time { font-size: 11px; color: var(--muted); display:flex; align-items:center; gap:3px; }
.cn-notice-recip {
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 4px;
    background: var(--gold-dim);
    color: var(--gold);
    font-weight: 600;
    margin-left: auto;
}
.cn-cat-badge {
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 4px;
    font-weight: 600;
}

/* ── Stats ─────────────────────────────────────────── */
.cn-stat-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 9px 0;
    border-bottom: 1px solid var(--border);
}
.cn-stat-row:last-child { border-bottom: none; }
.cn-stat-label { font-size: 12px; color: var(--muted); }
.cn-stat-val { font-size: 17px; font-weight: 800; font-family: 'JetBrains Mono', monospace; }

/* ── Delivery path info card ───────────────────────── */
.cn-path-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}
.cn-path-item:last-child { border-bottom: none; }
.cn-path-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 3px; }
.cn-path-label { font-size: 12px; font-weight: 600; color: var(--t1, #1a1f36); }
.cn-path-desc  { font-size: 10.5px; color: var(--muted); margin-top: 1px; }

/* ── Toast ─────────────────────────────────────────── */
#cnToastWrap {
    position: fixed;
    bottom: 24px; right: 24px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    z-index: 9999;
    pointer-events: none;
}
.cn-toast {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 11px 16px;
    border-radius: 10px;
    background: var(--bg2, #fff);
    border: 1px solid rgba(0,0,0,.1);
    box-shadow: 0 6px 24px rgba(0,0,0,.12);
    font-size: 13px;
    color: var(--t1, #1a1f36);
    pointer-events: all;
    animation: cnToastIn .26s ease;
}
@keyframes cnToastIn { from { opacity:0; transform:translateY(10px) } to { opacity:1; transform:translateY(0) } }
.cn-toast.hide { opacity: 0; transform: translateY(8px); transition: all .24s; }
.cn-toast-success i { color: var(--green); }
.cn-toast-error   i { color: var(--rose); }
</style>
