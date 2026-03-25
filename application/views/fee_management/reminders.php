<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
    <div class="fm-wrap">
        <!-- Top bar -->
        <div class="fm-topbar">
            <h1 class="fm-page-title">
                <i class="fa fa-bell"></i> Fee Reminders
            </h1>
            <ul class="fm-breadcrumb">
                <li><a href="<?= base_url() ?>">Dashboard</a></li>
                <li>Fees &amp; Finance</li>
                <li>Reminders</li>
            </ul>
        </div>

        <!-- Tab Navigation -->
        <div class="fm-rem-tabs">
            <button class="fm-rem-tab active" data-tab="settings" onclick="switchTab('settings')">
                <i class="fa fa-cog"></i> Settings
            </button>
            <button class="fm-rem-tab" data-tab="due" onclick="switchTab('due')">
                <i class="fa fa-users"></i> Due Students
            </button>
            <button class="fm-rem-tab" data-tab="log" onclick="switchTab('log')">
                <i class="fa fa-history"></i> Reminder Log
            </button>
        </div>

        <!-- ==================== TAB 1: SETTINGS ==================== -->
        <div class="fm-rem-panel active" id="panel-settings">
            <div class="fm-card">
                <div class="fm-card-head">
                    <i class="fa fa-sliders"></i>
                    <h3>Reminder Settings</h3>
                </div>
                <div class="fm-card-body">
                    <form id="reminderSettingsForm" autocomplete="off" onsubmit="saveSettings(event)">

                        <div class="fm-form-grid fm-form-grid--2">
                            <!-- Auto Remind -->
                            <div class="fm-form-col fm-form-col--full">
                                <label class="fm-toggle-row">
                                    <input type="checkbox" name="auto_remind" id="auto_remind" value="1"
                                        <?= (!empty($settings['auto_remind'])) ? 'checked' : '' ?>>
                                    <span class="fm-toggle-slider"></span>
                                    <span class="fm-toggle-label">Enable Auto Reminders</span>
                                </label>
                            </div>

                            <!-- Due Day -->
                            <div class="fm-form-col">
                                <label class="fm-label">Due Day of Month <span class="fm-req">*</span></label>
                                <input type="number" name="due_day" id="due_day" class="fm-input"
                                    min="1" max="28" placeholder="e.g. 10"
                                    value="<?= isset($settings['due_day']) ? htmlspecialchars($settings['due_day']) : '10' ?>">
                            </div>

                            <!-- Days Before Due -->
                            <div class="fm-form-col">
                                <label class="fm-label">Days Before Due to Remind</label>
                                <input type="text" name="remind_days" id="remind_days" class="fm-input"
                                    placeholder="e.g. 7,3,1"
                                    value="<?= isset($settings['remind_days']) ? htmlspecialchars($settings['remind_days']) : '7,3,1' ?>">
                                <span class="fm-hint">Comma-separated days, e.g. 7,3,1</span>
                            </div>

                            <!-- Reminder Message Template -->
                            <div class="fm-form-col fm-form-col--full">
                                <label class="fm-label">Reminder Message Template</label>
                                <textarea name="message_template" id="message_template" class="fm-textarea" rows="4"
                                    placeholder="Dear {student_name}, your fee of &#8377;{amount} for {month} is due on {due_date}. Please pay on time."><?= isset($settings['message_template']) ? htmlspecialchars($settings['message_template']) : 'Dear {student_name}, your fee of ₹{amount} for {month} is due on {due_date}. Please pay on time to avoid late charges.' ?></textarea>
                                <span class="fm-hint">Placeholders: {student_name}, {amount}, {month}, {due_date}</span>
                            </div>
                        </div>

                        <!-- Late Fee Section -->
                        <div class="fm-divider"></div>
                        <h4 class="fm-section-title"><i class="fa fa-percent"></i> Late Fee Configuration</h4>

                        <div class="fm-form-grid fm-form-grid--2">
                            <div class="fm-form-col fm-form-col--full">
                                <label class="fm-toggle-row">
                                    <input type="checkbox" name="late_fee_enabled" id="late_fee_enabled" value="1"
                                        onchange="toggleLateFeeSec()"
                                        <?= (!empty($settings['late_fee_enabled'])) ? 'checked' : '' ?>>
                                    <span class="fm-toggle-slider"></span>
                                    <span class="fm-toggle-label">Enable Late Fee</span>
                                </label>
                            </div>

                            <div class="fm-late-fee-fields" id="lateFeeFields" style="<?= empty($settings['late_fee_enabled']) ? 'display:none' : '' ?>">
                                <div class="fm-form-grid fm-form-grid--2">
                                    <div class="fm-form-col">
                                        <label class="fm-label">Late Fee Type</label>
                                        <select name="late_fee_type" id="late_fee_type" class="fm-select">
                                            <option value="Percentage" <?= (isset($settings['late_fee_type']) && $settings['late_fee_type'] === 'Percentage') ? 'selected' : '' ?>>Percentage (%)</option>
                                            <option value="Fixed" <?= (isset($settings['late_fee_type']) && $settings['late_fee_type'] === 'Fixed') ? 'selected' : '' ?>>Fixed Amount (&#8377;)</option>
                                        </select>
                                    </div>
                                    <div class="fm-form-col">
                                        <label class="fm-label">Late Fee Value</label>
                                        <input type="number" name="late_fee_value" id="late_fee_value" class="fm-input"
                                            min="0" step="0.01" placeholder="e.g. 5"
                                            value="<?= isset($settings['late_fee_value']) ? htmlspecialchars($settings['late_fee_value']) : '' ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="fm-form-actions">
                            <button type="submit" class="fm-btn fm-btn--primary" id="btnSaveSettings">
                                <i class="fa fa-save"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ==================== TAB 2: DUE STUDENTS ==================== -->
        <div class="fm-rem-panel" id="panel-due">
            <!-- Stats bar -->
            <div class="fm-stats" id="fmDueStats" style="display:none;">
                <div class="fm-stat teal">
                    <div class="fm-stat-label">Due Students</div>
                    <div class="fm-stat-value" id="statDueCount">0</div>
                </div>
                <div class="fm-stat gold">
                    <div class="fm-stat-label">Total Amount Due</div>
                    <div class="fm-stat-value" id="statDueAmount">&#8377;0</div>
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card-head">
                    <i class="fa fa-search"></i>
                    <h3>Due Students</h3>
                    <button type="button" class="fm-btn fm-btn--primary fm-btn--sm fm-ml-auto" onclick="scanDueStudents()">
                        <i class="fa fa-refresh" id="scanIcon"></i> Scan Due Fees
                    </button>
                </div>
                <div class="fm-card-body">
                    <!-- Spinner -->
                    <div class="fm-rem-spinner" id="dueSpinner" style="display:none;">
                        <div class="fm-rem-spinner-ring"></div>
                        <p>Scanning fee records...</p>
                    </div>

                    <!-- Empty state -->
                    <div class="fm-rem-empty" id="dueEmpty">
                        <i class="fa fa-info-circle"></i>
                        <p>Click "Scan Due Fees" to find students with pending payments.</p>
                    </div>

                    <!-- Results table -->
                    <div class="fm-table-wrap" id="dueTableWrap" style="display:none;">
                        <table class="fm-table" id="dueTable">
                            <thead>
                                <tr>
                                    <th class="fm-th-check">
                                        <label class="fm-check-wrap">
                                            <input type="checkbox" id="selectAllDue" onchange="toggleSelectAll(this)">
                                            <span class="fm-checkmark"></span>
                                        </label>
                                    </th>
                                    <th>Student Name</th>
                                    <th>Class / Section</th>
                                    <th>Unpaid Months</th>
                                    <th>Total Due</th>
                                    <th>Last Reminder</th>
                                </tr>
                            </thead>
                            <tbody id="dueTableBody"></tbody>
                        </table>

                        <div class="fm-form-actions">
                            <button type="button" class="fm-btn fm-btn--primary" id="btnSendReminders" onclick="sendReminders()">
                                <i class="fa fa-paper-plane"></i> Send Reminder to Selected
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== TAB 3: REMINDER LOG ==================== -->
        <div class="fm-rem-panel" id="panel-log">
            <div class="fm-card">
                <div class="fm-card-head">
                    <i class="fa fa-list-alt"></i>
                    <h3>Reminder Log</h3>
                </div>
                <div class="fm-card-body">
                    <!-- Date filter -->
                    <div class="fm-rem-log-filter">
                        <div class="fm-form-col">
                            <label class="fm-label">From</label>
                            <input type="date" class="fm-input" id="logDateFrom">
                        </div>
                        <div class="fm-form-col">
                            <label class="fm-label">To</label>
                            <input type="date" class="fm-input" id="logDateTo">
                        </div>
                        <div class="fm-form-col fm-filter-btn-col">
                            <button type="button" class="fm-btn fm-btn--outline fm-btn--sm" onclick="filterLog()">
                                <i class="fa fa-filter"></i> Filter
                            </button>
                        </div>
                    </div>

                    <!-- Spinner -->
                    <div class="fm-rem-spinner" id="logSpinner" style="display:none;">
                        <div class="fm-rem-spinner-ring"></div>
                        <p>Loading reminder log...</p>
                    </div>

                    <!-- Table -->
                    <div class="fm-table-wrap" id="logTableWrap" style="display:none;">
                        <table class="fm-table" id="logTable">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Date Sent</th>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Month</th>
                                    <th>Amount Due</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="logTableBody"></tbody>
                        </table>
                    </div>

                    <!-- Empty -->
                    <div class="fm-rem-empty" id="logEmpty">
                        <i class="fa fa-inbox"></i>
                        <p>No reminder logs found.</p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.fm-wrap -->
</div><!-- /.content-wrapper -->

<!-- Toast -->
<div class="fm-toast-container" id="fmToastContainer"></div>

<!-- ======================== JAVASCRIPT ======================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    var BASE = '<?= base_url() ?>';
    var CSRF_NAME = document.querySelector('meta[name="csrf-name"]').content;
    var CSRF_HASH = document.querySelector('meta[name="csrf-token"]').content;

    /* ---------- Tab switching ---------- */
    window.switchTab = function(tab) {
        document.querySelectorAll('.fm-rem-tab').forEach(function(t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === tab);
        });
        document.querySelectorAll('.fm-rem-panel').forEach(function(p) {
            p.classList.toggle('active', p.id === 'panel-' + tab);
        });
        if (tab === 'log') loadReminderLog();
    };

    /* ---------- Toggle late fee section ---------- */
    window.toggleLateFeeSec = function() {
        var el = document.getElementById('lateFeeFields');
        el.style.display = document.getElementById('late_fee_enabled').checked ? '' : 'none';
    };

    /* ---------- Save Settings ---------- */
    window.saveSettings = function(e) {
        e.preventDefault();
        var btn = document.getElementById('btnSaveSettings');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        var fd = new FormData(document.getElementById('reminderSettingsForm'));
        fd.append(CSRF_NAME, CSRF_HASH);
        if (!document.getElementById('auto_remind').checked) fd.set('auto_remind', '0');
        if (!document.getElementById('late_fee_enabled').checked) fd.set('late_fee_enabled', '0');

        fetch(BASE + 'fee_management/save_reminder_settings', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_hash) CSRF_HASH = data.csrf_hash;
                showToast(data.message || 'Settings saved.', data.status ? 'success' : 'error');
            })
            .catch(function() { showToast('Network error. Try again.', 'error'); })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-save"></i> Save Settings';
            });
    };

    /* ---------- Scan Due Students ---------- */
    window.scanDueStudents = function() {
        var spinner = document.getElementById('dueSpinner');
        var empty = document.getElementById('dueEmpty');
        var wrap = document.getElementById('dueTableWrap');
        var stats = document.getElementById('fmDueStats');
        var icon = document.getElementById('scanIcon');

        spinner.style.display = '';
        empty.style.display = 'none';
        wrap.style.display = 'none';
        stats.style.display = 'none';
        icon.className = 'fa fa-spinner fa-spin';

        fetch(BASE + 'fee_management/fetch_due_students?<?= $this->security->get_csrf_token_name() ?>=<?= $this->security->get_csrf_hash() ?>')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_hash) CSRF_HASH = data.csrf_hash;
                spinner.style.display = 'none';
                icon.className = 'fa fa-refresh';

                var students = data.students || [];
                if (!students.length) {
                    empty.innerHTML = '<i class="fa fa-check-circle" style="color:var(--fm-green)"></i><p>All students are up to date!</p>';
                    empty.style.display = '';
                    return;
                }

                // Stats
                var totalAmt = 0;
                students.forEach(function(s) { totalAmt += parseFloat(s.total_due) || 0; });
                document.getElementById('statDueCount').textContent = students.length;
                document.getElementById('statDueAmount').textContent = '\u20B9' + totalAmt.toLocaleString('en-IN');
                stats.style.display = '';

                // Build rows
                var html = '';
                students.forEach(function(s) {
                    var months = (s.unpaid_months || []).map(function(m) {
                        return '<span class="fm-badge fm-badge--gold">' + m + '</span>';
                    }).join(' ');
                    html += '<tr>' +
                        '<td class="fm-th-check"><label class="fm-check-wrap"><input type="checkbox" class="due-check" value="' + (s.user_id || '') + '"><span class="fm-checkmark"></span></label></td>' +
                        '<td>' + (s.student_name || '--') + '</td>' +
                        '<td>' + (s.class_section || '--') + '</td>' +
                        '<td>' + months + '</td>' +
                        '<td class="fm-text-bold">\u20B9' + (parseFloat(s.total_due) || 0).toLocaleString('en-IN') + '</td>' +
                        '<td>' + (s.last_reminder || '<span class="fm-text-muted">Never</span>') + '</td>' +
                        '</tr>';
                });
                document.getElementById('dueTableBody').innerHTML = html;
                wrap.style.display = '';
                document.getElementById('selectAllDue').checked = false;
            })
            .catch(function() {
                spinner.style.display = 'none';
                icon.className = 'fa fa-refresh';
                showToast('Failed to fetch due students.', 'error');
            });
    };

    /* ---------- Select All ---------- */
    window.toggleSelectAll = function(el) {
        document.querySelectorAll('.due-check').forEach(function(c) { c.checked = el.checked; });
    };

    /* ---------- Send Reminders ---------- */
    window.sendReminders = function() {
        var ids = [];
        document.querySelectorAll('.due-check:checked').forEach(function(c) { ids.push(c.value); });
        if (!ids.length) { showToast('Select at least one student.', 'error'); return; }

        var btn = document.getElementById('btnSendReminders');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';

        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        ids.forEach(function(id) { fd.append('student_ids[]', id); });

        fetch(BASE + 'fee_management/send_reminder', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_hash) CSRF_HASH = data.csrf_hash;
                showToast(data.message || 'Reminders sent.', data.status ? 'success' : 'error');
                if (data.status) scanDueStudents();
            })
            .catch(function() { showToast('Failed to send reminders.', 'error'); })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-paper-plane"></i> Send Reminder to Selected';
            });
    };

    /* ---------- Load Reminder Log ---------- */
    window.loadReminderLog = function(from, to) {
        var spinner = document.getElementById('logSpinner');
        var wrap = document.getElementById('logTableWrap');
        var empty = document.getElementById('logEmpty');

        spinner.style.display = '';
        wrap.style.display = 'none';
        empty.style.display = 'none';

        var url = BASE + 'fee_management/fetch_reminder_log?' + CSRF_NAME + '=' + encodeURIComponent(CSRF_HASH);
        if (from) url += '&from=' + from;
        if (to) url += '&to=' + to;

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_hash) CSRF_HASH = data.csrf_hash;
                spinner.style.display = 'none';

                var logs = data.logs || [];
                if (!logs.length) { empty.style.display = ''; return; }

                var html = '';
                logs.forEach(function(l, i) {
                    var typeCls = (l.type || '').toLowerCase() === 'sms' ? 'fm-badge--teal' :
                                  (l.type || '').toLowerCase() === 'email' ? 'fm-badge--navy' : 'fm-badge--default';
                    var statusCls = (l.status || '').toLowerCase() === 'sent' ? 'fm-badge--green' : 'fm-badge--red';

                    html += '<tr>' +
                        '<td>' + (i + 1) + '</td>' +
                        '<td>' + (l.date_sent || '--') + '</td>' +
                        '<td>' + (l.student_name || '--') + '</td>' +
                        '<td>' + (l.class || '--') + '</td>' +
                        '<td>' + (l.month || '--') + '</td>' +
                        '<td>\u20B9' + (parseFloat(l.amount_due) || 0).toLocaleString('en-IN') + '</td>' +
                        '<td><span class="fm-badge ' + typeCls + '">' + (l.type || 'Manual') + '</span></td>' +
                        '<td><span class="fm-badge ' + statusCls + '">' + (l.status || '--') + '</span></td>' +
                        '</tr>';
                });
                document.getElementById('logTableBody').innerHTML = html;
                wrap.style.display = '';
            })
            .catch(function() {
                spinner.style.display = 'none';
                showToast('Failed to load reminder log.', 'error');
            });
    };

    /* ---------- Filter Log ---------- */
    window.filterLog = function() {
        var from = document.getElementById('logDateFrom').value;
        var to = document.getElementById('logDateTo').value;
        loadReminderLog(from, to);
    };

    /* ---------- Toast ---------- */
    window.showToast = function(msg, type) {
        var container = document.getElementById('fmToastContainer');
        var toast = document.createElement('div');
        toast.className = 'fm-toast fm-toast--' + (type || 'info');
        var icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
        toast.innerHTML = '<i class="fa ' + icon + '"></i> ' + msg;
        container.appendChild(toast);
        setTimeout(function() { toast.classList.add('fm-toast--visible'); }, 30);
        setTimeout(function() {
            toast.classList.remove('fm-toast--visible');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3500);
    };

}); // DOMContentLoaded
</script>

<!-- ======================== STYLES ======================== -->
<style>
/* ---------- Variables ---------- */
.fm-wrap {
    --fm-navy: var(--t1, #0f1f3d);
    --fm-teal: var(--gold, #0f766e);
    --fm-sky: var(--gold-dim, rgba(15,118,110,.10));
    --fm-gold: #d97706;
    --fm-red: #E05C6F;
    --fm-green: #15803d;
    --fm-border: var(--border, #e2e8f0);
    --fm-bg: var(--bg, #f7f8fc);
    --fm-white: var(--bg2, #ffffff);
    --fm-radius: var(--r-sm, 8px);
    --fm-shadow: var(--sh, 0 1px 3px rgba(0,0,0,.06));
    --fm-shadow-lg: 0 4px 14px rgba(0,0,0,.08);
    --fm-font: 'Plus Jakarta Sans', var(--font-b, sans-serif);
    --fm-font-display: 'Fraunces', serif;
    --fm-ease: cubic-bezier(.4,0,.2,1);
}
@import url('https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&display=swap');

.fm-wrap { font-family: var(--fm-font); color: var(--fm-navy); padding: 18px 22px 30px; background: var(--fm-bg); min-height: calc(100vh - 50px); }

/* ---------- Top bar ---------- */
.fm-topbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; }
.fm-page-title { font-family: var(--fm-font-display); font-size: 1.3rem; font-weight: 700; color: var(--fm-navy); margin: 0; display: flex; align-items: center; gap: 8px; }
.fm-page-title i { color: var(--fm-teal); font-size: 1.1rem; }
.fm-breadcrumb { list-style: none; display: flex; align-items: center; gap: 4px; margin: 0; padding: 0; font-size: .78rem; color: #94a3b8; }
.fm-breadcrumb li + li::before { content: '/'; margin-right: 4px; color: #cbd5e1; }
.fm-breadcrumb a { color: var(--fm-teal); text-decoration: none; }
.fm-breadcrumb a:hover { text-decoration: underline; }

/* ---------- Tab nav ---------- */
.fm-rem-tabs { display: flex; gap: 2px; background: var(--fm-white); border-radius: var(--fm-radius) var(--fm-radius) 0 0; box-shadow: var(--fm-shadow); overflow: hidden; margin-bottom: 0; }
.fm-rem-tab { flex: 1; padding: 11px 14px; border: none; background: transparent; cursor: pointer; font-family: var(--fm-font); font-size: .82rem; font-weight: 600; color: #64748b; display: flex; align-items: center; justify-content: center; gap: 6px; position: relative; transition: color .2s var(--fm-ease), background .2s var(--fm-ease); }
.fm-rem-tab::after { content: ''; position: absolute; bottom: 0; left: 20%; right: 20%; height: 2.5px; background: var(--fm-teal); border-radius: 2px 2px 0 0; transform: scaleX(0); transition: transform .25s var(--fm-ease); }
.fm-rem-tab:hover { color: var(--fm-navy); background: rgba(13,115,119,.04); }
.fm-rem-tab.active { color: var(--fm-teal); }
.fm-rem-tab.active::after { transform: scaleX(1); }

/* ---------- Panels ---------- */
.fm-rem-panel { display: none; animation: fmFadeIn .25s var(--fm-ease); }
.fm-rem-panel.active { display: block; }
@keyframes fmFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* ---------- Card ---------- */
.fm-card { background: var(--fm-white); border-radius: 0 0 var(--fm-radius) var(--fm-radius); box-shadow: var(--fm-shadow); margin-bottom: 18px; overflow: hidden; }
.fm-rem-panel .fm-card:first-child { border-radius: 0 0 var(--fm-radius) var(--fm-radius); }
.fm-rem-panel .fm-stats + .fm-card { border-radius: var(--fm-radius); }
.fm-card-head { display: flex; align-items: center; gap: 8px; padding: 13px 18px; border-bottom: 1px solid var(--fm-border); font-size: .88rem; }
.fm-card-head i { color: var(--fm-teal); }
.fm-card-head h3 { margin: 0; font-family: var(--fm-font); font-size: .88rem; font-weight: 700; }
.fm-card-body { padding: 18px; }

/* ---------- Form elements ---------- */
.fm-form-grid { display: grid; gap: 14px; }
.fm-form-grid--2 { grid-template-columns: 1fr 1fr; }
.fm-form-col--full { grid-column: 1 / -1; }
.fm-label { display: block; font-size: .73rem; font-weight: 600; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .3px; }
.fm-req { color: var(--fm-red); }
.fm-input, .fm-select, .fm-textarea { width: 100%; padding: 8px 11px; font-family: var(--fm-font); font-size: .82rem; border: 1.5px solid var(--fm-border); border-radius: 6px; background: var(--fm-white); color: var(--fm-navy); transition: border-color .2s var(--fm-ease), box-shadow .2s var(--fm-ease); outline: none; box-sizing: border-box; }
.fm-input:focus, .fm-select:focus, .fm-textarea:focus { border-color: var(--fm-teal); box-shadow: 0 0 0 3px rgba(13,115,119,.12); }
.fm-textarea { resize: vertical; min-height: 70px; line-height: 1.5; }
.fm-hint { display: block; font-size: .7rem; color: #94a3b8; margin-top: 3px; }
.fm-divider { border: 0; border-top: 1px dashed var(--fm-border); margin: 18px 0 14px; }
.fm-section-title { font-size: .82rem; font-weight: 700; color: var(--fm-navy); margin: 0 0 12px; display: flex; align-items: center; gap: 6px; }
.fm-section-title i { color: var(--fm-gold); font-size: .78rem; }
.fm-late-fee-fields { grid-column: 1 / -1; }

/* ---------- Toggle switch ---------- */
.fm-toggle-row { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: .82rem; font-weight: 600; color: var(--fm-navy); user-select: none; }
.fm-toggle-row input { display: none; }
.fm-toggle-slider { position: relative; width: 38px; height: 20px; background: #cbd5e1; border-radius: 20px; flex-shrink: 0; transition: background .2s var(--fm-ease); }
.fm-toggle-slider::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: var(--fm-white); border-radius: 50%; transition: transform .2s var(--fm-ease); box-shadow: 0 1px 3px rgba(0,0,0,.15); }
.fm-toggle-row input:checked + .fm-toggle-slider { background: var(--fm-teal); }
.fm-toggle-row input:checked + .fm-toggle-slider::after { transform: translateX(18px); }

/* ---------- Buttons ---------- */
.fm-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-family: var(--fm-font); font-size: .8rem; font-weight: 600; border: none; border-radius: 6px; cursor: pointer; transition: background .2s var(--fm-ease), transform .1s var(--fm-ease), box-shadow .2s var(--fm-ease); }
.fm-btn:active { transform: scale(.97); }
.fm-btn--primary { background: var(--fm-teal); color: #fff; }
.fm-btn--primary:hover { background: #0a5f62; box-shadow: 0 2px 8px rgba(13,115,119,.25); }
.fm-btn--primary:disabled { opacity: .55; pointer-events: none; }
.fm-btn--outline { background: transparent; color: var(--fm-teal); border: 1.5px solid var(--fm-teal); }
.fm-btn--outline:hover { background: rgba(13,115,119,.06); }
.fm-btn--sm { padding: 6px 13px; font-size: .76rem; }
.fm-ml-auto { margin-left: auto; }
.fm-form-actions { margin-top: 18px; display: flex; justify-content: flex-end; gap: 10px; }

/* ---------- Stats ---------- */
.fm-stats { display: flex; gap: 12px; margin-bottom: 14px; margin-top: 4px; }
.fm-stat { flex: 1; background: var(--fm-white); border-radius: var(--fm-radius); padding: 13px 16px; box-shadow: var(--fm-shadow); border-left: 3px solid var(--fm-border); }
.fm-stat.teal { border-left-color: var(--fm-teal); }
.fm-stat.gold { border-left-color: var(--fm-gold); }
.fm-stat.green { border-left-color: var(--fm-green); }
.fm-stat-label { font-size: .7rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
.fm-stat-value { font-size: 1.15rem; font-weight: 700; color: var(--fm-navy); }

/* ---------- Table ---------- */
.fm-table-wrap { overflow-x: auto; }
.fm-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.fm-table thead th { background: #f8fafc; padding: 9px 12px; font-size: .7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; border-bottom: 2px solid var(--fm-border); text-align: left; white-space: nowrap; }
.fm-table tbody td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.fm-table tbody tr:hover { background: rgba(13,115,119,.02); }
.fm-text-bold { font-weight: 700; }
.fm-text-muted { color: #94a3b8; font-style: italic; }

/* ---------- Checkbox ---------- */
.fm-th-check { width: 36px; text-align: center !important; }
.fm-check-wrap { display: inline-flex; align-items: center; justify-content: center; position: relative; cursor: pointer; }
.fm-check-wrap input { position: absolute; opacity: 0; width: 0; height: 0; }
.fm-checkmark { width: 16px; height: 16px; border: 1.5px solid #cbd5e1; border-radius: 3px; display: inline-block; transition: all .15s var(--fm-ease); position: relative; }
.fm-check-wrap input:checked + .fm-checkmark { background: var(--fm-teal); border-color: var(--fm-teal); }
.fm-check-wrap input:checked + .fm-checkmark::after { content: ''; position: absolute; left: 4.5px; top: 1px; width: 4px; height: 8px; border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg); }

/* ---------- Badges ---------- */
.fm-badge { display: inline-block; padding: 2px 8px; font-size: .7rem; font-weight: 600; border-radius: 20px; line-height: 1.5; white-space: nowrap; }
.fm-badge--gold { background: rgba(217,119,6,.12); color: #b45309; }
.fm-badge--teal { background: rgba(13,115,119,.12); color: var(--fm-teal); }
.fm-badge--navy { background: rgba(15,31,61,.1); color: var(--fm-navy); }
.fm-badge--green { background: rgba(39,174,96,.12); color: #1a7a42; }
.fm-badge--red { background: rgba(229,62,62,.12); color: #c53030; }
.fm-badge--default { background: #f1f5f9; color: #64748b; }

/* ---------- Spinner ---------- */
.fm-rem-spinner { text-align: center; padding: 40px 20px; }
.fm-rem-spinner p { margin-top: 12px; font-size: .82rem; color: #64748b; }
.fm-rem-spinner-ring { display: inline-block; width: 32px; height: 32px; border: 3px solid var(--fm-border); border-top-color: var(--fm-teal); border-radius: 50%; animation: fmSpin .7s linear infinite; }
@keyframes fmSpin { to { transform: rotate(360deg); } }

/* ---------- Empty state ---------- */
.fm-rem-empty { text-align: center; padding: 36px 20px; color: #94a3b8; }
.fm-rem-empty i { font-size: 1.6rem; margin-bottom: 8px; display: block; }
.fm-rem-empty p { margin: 0; font-size: .82rem; }

/* ---------- Log filter ---------- */
.fm-rem-log-filter { display: flex; gap: 10px; align-items: flex-end; margin-bottom: 16px; flex-wrap: wrap; }
.fm-rem-log-filter .fm-form-col { flex: 0 0 160px; }
.fm-filter-btn-col { padding-bottom: 1px; }

/* ---------- Toast ---------- */
.fm-toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 8px; }
.fm-toast { padding: 10px 18px; border-radius: 6px; font-family: var(--fm-font); font-size: .8rem; font-weight: 600; color: #fff; box-shadow: var(--fm-shadow-lg); transform: translateX(110%); transition: transform .3s var(--fm-ease); display: flex; align-items: center; gap: 7px; max-width: 360px; }
.fm-toast--visible { transform: translateX(0); }
.fm-toast--success { background: var(--fm-green); }
.fm-toast--error { background: var(--fm-red); }
.fm-toast--info { background: var(--fm-teal); }

/* ---------- Responsive ---------- */
@media (max-width: 767px) {
    .fm-wrap { padding: 12px 10px 24px; }
    .fm-topbar { flex-direction: column; align-items: flex-start; }
    .fm-form-grid--2 { grid-template-columns: 1fr; }
    .fm-stats { flex-direction: column; gap: 8px; }
    .fm-rem-tabs { flex-wrap: wrap; }
    .fm-rem-tab { flex: 1 1 auto; min-width: 0; padding: 9px 8px; font-size: .76rem; }
    .fm-rem-log-filter { flex-direction: column; }
    .fm-rem-log-filter .fm-form-col { flex: 1 1 100%; }
    .fm-table { font-size: .74rem; }
    .fm-table thead th, .fm-table tbody td { padding: 7px 8px; }
}
@media (max-width: 479px) {
    .fm-page-title { font-size: 1.1rem; }
    .fm-card-body { padding: 12px; }
    .fm-btn { padding: 7px 12px; font-size: .76rem; }
}
</style>
