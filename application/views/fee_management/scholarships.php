<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper" style="min-height:100vh;">

<!-- ── Top Bar ── -->
<section class="fm-topbar">
    <div class="fm-topbar-inner">
        <h1 class="fm-page-title"><i class="fa fa-graduation-cap"></i> Discounts &amp; Scholarships</h1>
        <ol class="fm-breadcrumb">
            <li><a href="<?= base_url('dashboard') ?>">Dashboard</a></li>
            <li><a href="<?= base_url('fee_management') ?>">Fee Management</a></li>
            <li class="active">Scholarships</li>
        </ol>
    </div>
</section>

<!-- Sub-tabs: Discounts / Scholarships — consolidated under one sidebar entry -->
<div class="fm-subtabs" style="display:flex;gap:4px;margin:4px 0 16px;border-bottom:1px solid var(--border,#e5e7eb);">
    <a href="<?= base_url('fee_management/discounts') ?>"
       class="fm-subtab"
       style="padding:10px 18px;color:var(--t2,#64748b);text-decoration:none;font-weight:500;">
        <i class="fa fa-tags"></i> Discounts
    </a>
    <a href="<?= base_url('fee_management/scholarships') ?>"
       class="fm-subtab fm-subtab--active"
       style="padding:10px 18px;border-bottom:2px solid var(--gold,#0f766e);color:var(--gold,#0f766e);font-weight:600;text-decoration:none;">
        <i class="fa fa-graduation-cap"></i> Scholarships
    </a>
</div>

<!-- ── Stats Row ── -->
<section class="fm-stats-row" id="fmStatsRow">
    <div class="fm-stat-card">
        <div class="fm-stat-icon fm-stat-icon--navy"><i class="fa fa-list-alt"></i></div>
        <div class="fm-stat-body">
            <span class="fm-stat-val" id="statTotal">0</span>
            <span class="fm-stat-lbl">Total Scholarships</span>
        </div>
    </div>
    <div class="fm-stat-card">
        <div class="fm-stat-icon fm-stat-icon--teal"><i class="fa fa-check-circle"></i></div>
        <div class="fm-stat-body">
            <span class="fm-stat-val" id="statActive">0</span>
            <span class="fm-stat-lbl">Active</span>
        </div>
    </div>
    <div class="fm-stat-card">
        <div class="fm-stat-icon fm-stat-icon--gold"><i class="fa fa-trophy"></i></div>
        <div class="fm-stat-body">
            <span class="fm-stat-val" id="statAwarded">0</span>
            <span class="fm-stat-lbl">Total Awarded</span>
        </div>
    </div>
    <div class="fm-stat-card">
        <div class="fm-stat-icon fm-stat-icon--green"><i class="fa fa-inr"></i></div>
        <div class="fm-stat-body">
            <span class="fm-stat-val" id="statAmount">0</span>
            <span class="fm-stat-lbl">Total Amount Awarded</span>
        </div>
    </div>
</section>

<!-- ── Tab Navigation ── -->
<section class="fm-content-wrap">
    <div class="fm-tab-nav">
        <button class="fm-tab-btn fm-tab-btn--active" data-tab="scholarships" onclick="switchTab('scholarships')">
            <i class="fa fa-list"></i> Scholarships
        </button>
        <button class="fm-tab-btn" data-tab="awards" onclick="switchTab('awards')">
            <i class="fa fa-award"></i> Awards
        </button>
        <div class="fm-tab-indicator" id="fmTabIndicator"></div>
    </div>

    <!-- ════════════════════════════════════════════ -->
    <!-- TAB 1: Scholarships                          -->
    <!-- ════════════════════════════════════════════ -->
    <div class="fm-tab-panel fm-tab-panel--active" id="panel-scholarships">

        <!-- Add / Edit Form -->
        <div class="fm-card fm-form-card" id="fmScholarshipForm">
            <div class="fm-card-head">
                <h3 id="fmFormTitle"><i class="fa fa-plus-circle"></i> Add Scholarship</h3>
            </div>
            <div class="fm-card-body">
                <input type="hidden" id="fmEditId" value="">
                <div class="fm-form-grid">
                    <div class="fm-fg">
                        <label class="fm-label">Scholarship Name <span class="fm-req">*</span></label>
                        <input type="text" class="fm-input" id="fmName" placeholder="e.g. Merit Scholarship" maxlength="120">
                    </div>
                    <div class="fm-fg">
                        <label class="fm-label">Type <span class="fm-req">*</span></label>
                        <select class="fm-input fm-select" id="fmType">
                            <option value="">-- Select --</option>
                            <option value="Percentage">Percentage</option>
                            <option value="Fixed">Fixed Amount</option>
                        </select>
                    </div>
                    <div class="fm-fg">
                        <label class="fm-label">Value <span class="fm-req">*</span></label>
                        <input type="number" class="fm-input" id="fmValue" placeholder="e.g. 50 or 2000" min="0" step="any">
                    </div>
                    <div class="fm-fg">
                        <label class="fm-label">Max Beneficiaries</label>
                        <input type="number" class="fm-input" id="fmMaxBeneficiaries" placeholder="Leave empty for unlimited" min="0">
                    </div>
                    <div class="fm-fg fm-fg--full">
                        <label class="fm-label">Criteria</label>
                        <textarea class="fm-input fm-textarea" id="fmCriteria" placeholder="Eligibility criteria..." rows="2"></textarea>
                    </div>
                    <div class="fm-fg fm-fg--toggle">
                        <label class="fm-label">Active</label>
                        <label class="fm-toggle">
                            <input type="checkbox" id="fmActive" checked>
                            <span class="fm-toggle-track"></span>
                        </label>
                    </div>
                </div>
                <div class="fm-form-actions">
                    <button class="fm-btn fm-btn--primary" onclick="saveScholarship()"><i class="fa fa-save"></i> Save</button>
                    <button class="fm-btn fm-btn--ghost" onclick="resetForm()"><i class="fa fa-times"></i> Cancel</button>
                </div>
            </div>
        </div>

        <!-- Scholarships Table -->
        <div class="fm-card">
            <div class="fm-card-head">
                <h3><i class="fa fa-table"></i> Scholarships</h3>
            </div>
            <div class="fm-card-body fm-card-body--table">
                <div class="fm-table-wrap">
                    <table class="fm-table" id="fmScholarshipsTable">
                        <thead>
                            <tr>
                                <th width="50">S.No</th>
                                <th>Name</th>
                                <th width="110">Type</th>
                                <th width="100">Value</th>
                                <th>Criteria</th>
                                <th width="100">Max Benef.</th>
                                <th width="90">Awarded</th>
                                <th width="90">Status</th>
                                <th width="110">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fmScholarshipsBody">
                            <tr><td colspan="9" class="fm-empty">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════ -->
    <!-- TAB 2: Awards                                -->
    <!-- ════════════════════════════════════════════ -->
    <div class="fm-tab-panel" id="panel-awards">

        <div class="fm-card">
            <div class="fm-card-head">
                <h3><i class="fa fa-award"></i> Scholarship Awards</h3>
                <button class="fm-btn fm-btn--primary fm-btn--sm" onclick="showAwardModal()"><i class="fa fa-plus"></i> Award Scholarship</button>
            </div>
            <div class="fm-card-body fm-card-body--table">
                <div class="fm-table-wrap">
                    <table class="fm-table" id="fmAwardsTable">
                        <thead>
                            <tr>
                                <th width="50">S.No</th>
                                <th>Student Name</th>
                                <th width="130">Class / Section</th>
                                <th>Scholarship</th>
                                <th width="110">Amount</th>
                                <th width="120">Awarded Date</th>
                                <th width="90">Status</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fmAwardsBody">
                            <tr><td colspan="8" class="fm-empty">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</section>

<!-- ════════════════════════════════════════════ -->
<!-- Award Modal                                  -->
<!-- ════════════════════════════════════════════ -->
<div class="fm-modal-overlay" id="fmAwardOverlay" onclick="closeAwardModal(event)">
    <div class="fm-modal" onclick="event.stopPropagation()">
        <div class="fm-modal-head">
            <h3><i class="fa fa-award"></i> Award Scholarship</h3>
            <button class="fm-modal-close" onclick="closeAwardModal()">&times;</button>
        </div>
        <div class="fm-modal-body">
            <div class="fm-fg">
                <label class="fm-label">Scholarship <span class="fm-req">*</span></label>
                <select class="fm-input fm-select" id="fmAwardScholarship">
                    <option value="">-- Select Scholarship --</option>
                </select>
            </div>
            <div class="fm-fg">
                <label class="fm-label">Student ID <span class="fm-req">*</span></label>
                <input type="text" class="fm-input" id="fmAwardStudentId" placeholder="Enter Student ID and press Tab">
                <small class="fm-hint" id="fmLookupHint"></small>
            </div>
            <div class="fm-fg">
                <label class="fm-label">Student Name</label>
                <input type="text" class="fm-input fm-input--ro" id="fmAwardStudentName" readonly placeholder="Auto-filled">
            </div>
            <div class="fm-fg">
                <label class="fm-label">Class / Section</label>
                <input type="text" class="fm-input fm-input--ro" id="fmAwardClass" readonly placeholder="Auto-filled">
            </div>
            <div class="fm-fg">
                <label class="fm-label">Amount <span class="fm-req">*</span></label>
                <input type="number" class="fm-input" id="fmAwardAmount" placeholder="Auto-calculated, editable" min="0" step="any">
            </div>
        </div>
        <div class="fm-modal-foot">
            <button class="fm-btn fm-btn--primary" onclick="awardScholarship()"><i class="fa fa-check"></i> Award</button>
            <button class="fm-btn fm-btn--ghost" onclick="closeAwardModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="fm-toast-wrap" id="fmToastWrap"></div>

</div><!-- /content-wrapper -->


<!-- ════════════════════════════════════════════ -->
<!-- JavaScript                                   -->
<!-- ════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    var BASE = '<?= base_url() ?>';
    var CSRFN = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRFT = '<?= $this->security->get_csrf_hash() ?>';

    /* ── Cached Data ── */
    var scholarshipsCache = [];

    /* ── CSRF helpers ── */
    function csrfData() {
        var d = {};
        d[CSRFN] = CSRFT;
        return d;
    }
    function refreshCsrf(resp) {
        if (resp && resp.csrf_token) CSRFT = resp.csrf_token;
    }

    /* ── Tab Switching ── */
    window.switchTab = function(tab) {
        document.querySelectorAll('.fm-tab-btn').forEach(function(b) { b.classList.remove('fm-tab-btn--active'); });
        document.querySelectorAll('.fm-tab-panel').forEach(function(p) { p.classList.remove('fm-tab-panel--active'); });
        document.querySelector('[data-tab="' + tab + '"]').classList.add('fm-tab-btn--active');
        document.getElementById('panel-' + tab).classList.add('fm-tab-panel--active');
        positionIndicator();
        if (tab === 'awards') loadAwards();
    };

    function positionIndicator() {
        var active = document.querySelector('.fm-tab-btn--active');
        var indicator = document.getElementById('fmTabIndicator');
        if (active && indicator) {
            indicator.style.width = active.offsetWidth + 'px';
            indicator.style.left = active.offsetLeft + 'px';
        }
    }

    /* ── Load Scholarships ── */
    window.loadScholarships = function() {
        $.ajax({
            url: BASE + 'fee_management/fetch_scholarships',
            type: 'GET',
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                var rows = res.data || [];
                scholarshipsCache = rows;
                var totalCount = rows.length;
                var activeCount = 0, awardedCount = 0, amountTotal = 0;

                rows.forEach(function(s) {
                    if (s.active) activeCount++;
                    awardedCount += parseInt(s.awarded_count || 0);
                });

                document.getElementById('statTotal').textContent = totalCount;
                document.getElementById('statActive').textContent = activeCount;
                document.getElementById('statAwarded').textContent = awardedCount;

                var tbody = document.getElementById('fmScholarshipsBody');
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="9" class="fm-empty">No scholarships found. Add one above.</td></tr>';
                    return;
                }
                var html = '';
                rows.forEach(function(s, i) {
                    var typeBadge = s.type === 'Percentage'
                        ? '<span class="fm-badge fm-badge--teal">' + s.type + '</span>'
                        : '<span class="fm-badge fm-badge--navy">' + s.type + '</span>';
                    var valDisplay = s.type === 'Percentage' ? s.value + '%' : '&#8377;' + Number(s.value).toLocaleString('en-IN');
                    var statusBadge = s.active
                        ? '<span class="fm-badge fm-badge--green">Active</span>'
                        : '<span class="fm-badge fm-badge--red">Inactive</span>';
                    var maxB = s.max_beneficiaries ? s.max_beneficiaries : '<span class="fm-muted">--</span>';
                    var criteria = s.criteria || '<span class="fm-muted">--</span>';
                    html += '<tr>'
                        + '<td>' + (i + 1) + '</td>'
                        + '<td class="fm-cell-name">' + escHtml(s.name) + '</td>'
                        + '<td>' + typeBadge + '</td>'
                        + '<td>' + valDisplay + '</td>'
                        + '<td class="fm-cell-criteria">' + escHtml(criteria) + '</td>'
                        + '<td class="text-center">' + maxB + '</td>'
                        + '<td class="text-center">' + (s.awarded_count || 0) + '</td>'
                        + '<td>' + statusBadge + '</td>'
                        + '<td class="fm-cell-actions">'
                            + '<button class="fm-act-btn fm-act-btn--edit" title="Edit" onclick="editScholarship(\'' + s.id + '\')"><i class="fa fa-pencil"></i></button>'
                            + '<button class="fm-act-btn fm-act-btn--del" title="Delete" onclick="deleteScholarship(\'' + s.id + '\')"><i class="fa fa-trash"></i></button>'
                        + '</td>'
                        + '</tr>';
                });
                tbody.innerHTML = html;
            },
            error: function() { showToast('Failed to load scholarships', 'error'); }
        });
    };

    /* ── Save Scholarship ── */
    window.saveScholarship = function() {
        var name  = document.getElementById('fmName').value.trim();
        var type  = document.getElementById('fmType').value;
        var value = document.getElementById('fmValue').value;
        var criteria = document.getElementById('fmCriteria').value.trim();
        var maxB  = document.getElementById('fmMaxBeneficiaries').value;
        var active = document.getElementById('fmActive').checked ? 1 : 0;
        var editId = document.getElementById('fmEditId').value;

        if (!name) { showToast('Scholarship name is required', 'error'); return; }
        if (!type) { showToast('Select a type', 'error'); return; }
        if (!value || parseFloat(value) <= 0) { showToast('Enter a valid value', 'error'); return; }
        if (type === 'Percentage' && parseFloat(value) > 100) { showToast('Percentage cannot exceed 100', 'error'); return; }

        var payload = csrfData();
        payload.name = name;
        payload.type = type;
        payload.value = value;
        payload.criteria = criteria;
        payload.max_beneficiaries = maxB;
        payload.active = active;
        if (editId) payload.id = editId;

        $.ajax({
            url: BASE + 'fee_management/save_scholarship',
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                if (res.status === 'success') {
                    showToast(editId ? 'Scholarship updated' : 'Scholarship created', 'success');
                    resetForm();
                    loadScholarships();
                } else {
                    showToast(res.message || 'Save failed', 'error');
                }
            },
            error: function() { showToast('Server error', 'error'); }
        });
    };

    /* ── Edit Scholarship ── */
    window.editScholarship = function(id) {
        var s = scholarshipsCache.find(function(x) { return x.id === id; });
        if (!s) return;
        document.getElementById('fmEditId').value = s.id;
        document.getElementById('fmName').value = s.name;
        document.getElementById('fmType').value = s.type;
        document.getElementById('fmValue').value = s.value;
        document.getElementById('fmCriteria').value = s.criteria || '';
        document.getElementById('fmMaxBeneficiaries').value = s.max_beneficiaries || '';
        document.getElementById('fmActive').checked = !!s.active;
        document.getElementById('fmFormTitle').innerHTML = '<i class="fa fa-pencil"></i> Edit Scholarship';
        document.getElementById('fmScholarshipForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    /* ── Delete Scholarship ── */
    window.deleteScholarship = function(id) {
        if (!confirm('Delete this scholarship? This cannot be undone.')) return;
        var payload = csrfData();
        payload.id = id;
        $.ajax({
            url: BASE + 'fee_management/delete_scholarship',
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                if (res.status === 'success') {
                    showToast('Scholarship deleted', 'success');
                    loadScholarships();
                } else {
                    showToast(res.message || 'Delete failed', 'error');
                }
            },
            error: function() { showToast('Server error', 'error'); }
        });
    };

    /* ── Reset Form ── */
    window.resetForm = function() {
        document.getElementById('fmEditId').value = '';
        document.getElementById('fmName').value = '';
        document.getElementById('fmType').value = '';
        document.getElementById('fmValue').value = '';
        document.getElementById('fmCriteria').value = '';
        document.getElementById('fmMaxBeneficiaries').value = '';
        document.getElementById('fmActive').checked = true;
        document.getElementById('fmFormTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Add Scholarship';
    };

    /* ── Load Awards ── */
    window.loadAwards = function() {
        $.ajax({
            url: BASE + 'fee_management/fetch_awards',
            type: 'GET',
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                var rows = res.data || [];
                var amountTotal = 0;
                rows.forEach(function(a) { amountTotal += parseFloat(a.amount || 0); });
                document.getElementById('statAmount').textContent = '₹' + amountTotal.toLocaleString('en-IN');

                var tbody = document.getElementById('fmAwardsBody');
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="8" class="fm-empty">No awards yet.</td></tr>';
                    return;
                }
                var html = '';
                rows.forEach(function(a, i) {
                    var statusBadge = a.status === 'active'
                        ? '<span class="fm-badge fm-badge--green">Active</span>'
                        : '<span class="fm-badge fm-badge--red">Revoked</span>';
                    // Refresh button — refreshes UNPAID demands so the
                    // award savings retroactively apply.
                    var refreshBtn = '<button class="fm-act-btn fm-act-btn--refresh" '
                        + 'title="Recalculate unpaid demands for this student" '
                        + 'onclick="recalcUnpaidDemands(\'' + (a.student_id || '') + '\', \'' + (a.student_name || '').replace(/'/g, "\\'") + '\')">'
                        + '<i class="fa fa-refresh"></i></button>';
                    var actions = a.status === 'active'
                        ? refreshBtn
                          + '<button class="fm-act-btn fm-act-btn--del" title="Revoke" onclick="revokeAward(\'' + a.id + '\')"><i class="fa fa-ban"></i></button>'
                        : '<span class="fm-muted">--</span>';
                    html += '<tr>'
                        + '<td>' + (i + 1) + '</td>'
                        + '<td>' + escHtml(a.student_name || '--') + '</td>'
                        + '<td>' + escHtml(a.class_section || '--') + '</td>'
                        + '<td>' + escHtml(a.scholarship_name || '--') + '</td>'
                        + '<td>&#8377;' + Number(a.amount || 0).toLocaleString('en-IN') + '</td>'
                        + '<td>' + escHtml(a.awarded_date || '--') + '</td>'
                        + '<td>' + statusBadge + '</td>'
                        + '<td class="fm-cell-actions">' + actions + '</td>'
                        + '</tr>';
                });
                tbody.innerHTML = html;
            },
            error: function() { showToast('Failed to load awards', 'error'); }
        });
    };

    /* ── Show Award Modal ── */
    window.showAwardModal = function() {
        document.getElementById('fmAwardOverlay').classList.add('fm-modal-overlay--show');
        document.getElementById('fmAwardStudentId').value = '';
        document.getElementById('fmAwardStudentName').value = '';
        document.getElementById('fmAwardClass').value = '';
        document.getElementById('fmAwardAmount').value = '';
        document.getElementById('fmLookupHint').textContent = '';
        // Populate scholarship dropdown
        var sel = document.getElementById('fmAwardScholarship');
        sel.innerHTML = '<option value="">-- Select Scholarship --</option>';
        scholarshipsCache.forEach(function(s) {
            if (!s.active) return;
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name + ' (' + (s.type === 'Percentage' ? s.value + '%' : '₹' + s.value) + ')';
            opt.setAttribute('data-type', s.type);
            opt.setAttribute('data-value', s.value);
            sel.appendChild(opt);
        });
    };

    window.closeAwardModal = function(evt) {
        if (evt && evt.target !== evt.currentTarget) return;
        document.getElementById('fmAwardOverlay').classList.remove('fm-modal-overlay--show');
    };

    /* ── Scholarship change: auto-calc amount ── */
    document.getElementById('fmAwardScholarship').addEventListener('change', function() {
        calcAwardAmount();
    });

    function calcAwardAmount() {
        var sel = document.getElementById('fmAwardScholarship');
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) { document.getElementById('fmAwardAmount').value = ''; return; }
        var sType = opt.getAttribute('data-type');
        var sVal  = parseFloat(opt.getAttribute('data-value')) || 0;
        if (sType === 'Fixed') {
            document.getElementById('fmAwardAmount').value = sVal;
        }
        // For percentage, amount depends on fee context; leave empty or user enters manually
    }

    /* ── Student Lookup ── */
    document.getElementById('fmAwardStudentId').addEventListener('blur', function() {
        lookupStudent();
    });

    window.lookupStudent = function() {
        var uid = document.getElementById('fmAwardStudentId').value.trim();
        if (!uid) return;
        var hint = document.getElementById('fmLookupHint');
        hint.textContent = 'Looking up...';
        hint.className = 'fm-hint';

        var fd = new FormData();
        fd.append('user_id', uid);
        fd.append(CSRFN, CSRFT);

        $.ajax({
            url: BASE + 'fees/lookup_student',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                if (res.status === 'success' || res.name) {
                    document.getElementById('fmAwardStudentName').value = res.name || '';
                    document.getElementById('fmAwardClass').value = res.class || '';
                    hint.textContent = 'Student found';
                    hint.className = 'fm-hint fm-hint--ok';
                    calcAwardAmount();
                } else {
                    document.getElementById('fmAwardStudentName').value = '';
                    document.getElementById('fmAwardClass').value = '';
                    hint.textContent = res.message || 'Student not found';
                    hint.className = 'fm-hint fm-hint--err';
                }
            },
            error: function() {
                hint.textContent = 'Lookup failed';
                hint.className = 'fm-hint fm-hint--err';
            }
        });
    };

    /* ── Award Scholarship ── */
    window.awardScholarship = function() {
        var scholarshipId = document.getElementById('fmAwardScholarship').value;
        var studentId  = document.getElementById('fmAwardStudentId').value.trim();
        var studentName = document.getElementById('fmAwardStudentName').value.trim();
        var classSection = document.getElementById('fmAwardClass').value.trim();
        var amount = document.getElementById('fmAwardAmount').value;

        if (!scholarshipId) { showToast('Select a scholarship', 'error'); return; }
        if (!studentId) { showToast('Enter a student ID', 'error'); return; }
        if (!studentName) { showToast('Look up the student first', 'error'); return; }
        if (!amount || parseFloat(amount) <= 0) { showToast('Enter a valid amount', 'error'); return; }

        var payload = csrfData();
        payload.scholarship_id = scholarshipId;
        payload.student_id = studentId;
        payload.student_name = studentName;
        payload.class_section = classSection;
        payload.amount = amount;

        $.ajax({
            url: BASE + 'fee_management/award_scholarship',
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                if (res.status === 'success') {
                    showToast('Scholarship awarded', 'success');
                    closeAwardModal();
                    loadAwards();
                    loadScholarships();

                    // Phase 19 — auto-recalc this student's UNPAID demands
                    // so the new scholarship reduces future dues. Existing
                    // partial/paid demands are preserved by the endpoint.
                    if (confirm(
                      'Award saved.\n\n' +
                      'Refresh ' + (studentName || 'this student') + '\'s unpaid demands now ' +
                      'so the new scholarship reduces future fees?\n\n' +
                      '(Paid + partial demands are preserved unchanged.)'
                    )) {
                        recalcUnpaidDemands(studentId, studentName);
                    }
                } else {
                    showToast(res.message || 'Award failed', 'error');
                }
            },
            error: function() { showToast('Server error', 'error'); }
        });
    };

    /* ── Recalc unpaid demands (after award/revoke or manual button) ── */
    window.recalcUnpaidDemands = function(studentId, studentName) {
        if (!studentId) return;
        var payload = csrfData();
        payload.student_id = studentId;
        $.ajax({
            url: BASE + 'fees/recalc_unpaid_discounts',
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                if (res.status === 'success' || res.success) {
                    showToast(
                        'Refreshed ' + (studentName || studentId) + ': ' +
                        (res.deleted || 0) + ' refreshed, ' +
                        (res.preserved || 0) + ' preserved.',
                        'success'
                    );
                } else {
                    showToast(res.message || 'Recalc failed.', 'error');
                }
            },
            error: function() { showToast('Server error during recalc.', 'error'); }
        });
    };

    /* ── Revoke Award ── */
    window.revokeAward = function(id) {
        if (!confirm('Revoke this scholarship award?')) return;
        var payload = csrfData();
        payload.id = id;
        $.ajax({
            url: BASE + 'fee_management/revoke_scholarship',
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(res) {
                refreshCsrf(res);
                if (res.status === 'success') {
                    showToast('Award revoked', 'success');
                    loadAwards();
                    loadScholarships();
                } else {
                    showToast(res.message || 'Revoke failed', 'error');
                }
            },
            error: function() { showToast('Server error', 'error'); }
        });
    };

    /* ── Toast ── */
    window.showToast = function(msg, type) {
        var wrap = document.getElementById('fmToastWrap');
        var t = document.createElement('div');
        t.className = 'fm-toast fm-toast--' + (type || 'info');
        var icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
        t.innerHTML = '<i class="fa ' + icon + '"></i> ' + escHtml(msg);
        wrap.appendChild(t);
        requestAnimationFrame(function() { t.classList.add('fm-toast--show'); });
        setTimeout(function() {
            t.classList.remove('fm-toast--show');
            setTimeout(function() { t.remove(); }, 300);
        }, 3200);
    };

    /* ── Util ── */
    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /* ── Init ── */
    loadScholarships();
    positionIndicator();
    window.addEventListener('resize', positionIndicator);

});
</script>

<!-- ════════════════════════════════════════════ -->
<!-- Styles                                       -->
<!-- ════════════════════════════════════════════ -->
<style>
/* ── Variables (maps to global theme vars) ── */
:root {
    --fm-navy: var(--t1, #0c1e38);
    --fm-teal: var(--gold, #0f766e);
    --fm-teal2: var(--gold2, #0d6b63);
    --fm-teal3: var(--gold3, #14b8a6);
    --fm-sky: var(--gold-dim, rgba(15,118,110,.10));
    --fm-gold: #d97706;
    --fm-red: #E05C6F;
    --fm-green: #15803d;
    --fm-bg: var(--bg, #070f1c);
    --fm-bg2: var(--bg2, #0c1e38);
    --fm-bg3: var(--bg3, #0f2545);
    --fm-card: var(--bg2, rgba(12,30,56,.92));
    --fm-border: var(--border, rgba(15,118,110,.12));
    --fm-brd2: rgba(15,118,110,.25);
    --fm-t1: var(--t1, #e6f4f1);
    --fm-t2: var(--t2, #94c9c3);
    --fm-t3: var(--t3, #5a9e98);
    --fm-sh: var(--sh, 0 4px 32px rgba(0,0,0,.55));
    --fm-r: var(--r, 10px);
    --fm-r-sm: var(--r-sm, 6px);
    --fm-ease: .22s cubic-bezier(.4,0,.2,1);
    --fm-font: 'Plus Jakarta Sans', var(--font-b, sans-serif);
    --fm-font-d: 'Fraunces', 'Syne', serif;
}

/* ── Topbar ── */
.fm-topbar {
    padding: 18px 28px 14px;
}
.fm-topbar-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.fm-page-title {
    font-family: var(--fm-font-d);
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--fm-t1);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.fm-page-title i {
    color: var(--fm-teal);
    font-size: 1.1em;
}
.fm-breadcrumb {
    list-style: none;
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    padding: 0;
    font-size: .78rem;
    color: var(--fm-t3);
}
.fm-breadcrumb li + li::before {
    content: '/';
    margin-right: 6px;
    opacity: .45;
}
.fm-breadcrumb a {
    color: var(--fm-t2);
    transition: color var(--fm-ease);
}
.fm-breadcrumb a:hover {
    color: var(--fm-teal);
}
.fm-breadcrumb .active {
    color: var(--fm-t1);
    font-weight: 600;
}

/* ── Stats Row ── */
.fm-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    padding: 0 28px 18px;
}
.fm-stat-card {
    background: var(--fm-card);
    border: 1px solid var(--fm-border);
    border-radius: var(--fm-r);
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: var(--fm-sh);
    transition: border-color var(--fm-ease), transform var(--fm-ease);
}
.fm-stat-card:hover {
    border-color: var(--fm-brd2);
    transform: translateY(-2px);
}
.fm-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.fm-stat-icon--navy  { background: rgba(12,30,56,.35); color: var(--fm-sky); }
.fm-stat-icon--teal  { background: rgba(15,118,110,.15); color: var(--fm-teal); }
.fm-stat-icon--gold  { background: rgba(217,119,6,.12); color: var(--fm-gold); }
.fm-stat-icon--green { background: rgba(21,128,61,.12); color: var(--fm-green); }
.fm-stat-body {
    display: flex;
    flex-direction: column;
}
.fm-stat-val {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--fm-t1);
    line-height: 1.2;
    font-family: var(--fm-font);
}
.fm-stat-lbl {
    font-size: .72rem;
    color: var(--fm-t3);
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 600;
    margin-top: 2px;
}

/* ── Content Wrap ── */
.fm-content-wrap {
    padding: 0 28px 32px;
}

/* ── Tab Nav ── */
.fm-tab-nav {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--fm-border);
    margin-bottom: 20px;
    position: relative;
}
.fm-tab-btn {
    background: none;
    border: none;
    padding: 10px 22px;
    font-family: var(--fm-font);
    font-size: .85rem;
    font-weight: 600;
    color: var(--fm-t3);
    cursor: pointer;
    transition: color var(--fm-ease);
    display: flex;
    align-items: center;
    gap: 7px;
    position: relative;
    z-index: 1;
}
.fm-tab-btn:hover {
    color: var(--fm-t1);
}
.fm-tab-btn--active {
    color: var(--fm-teal);
}
.fm-tab-indicator {
    position: absolute;
    bottom: -2px;
    height: 2px;
    background: var(--fm-teal);
    border-radius: 2px 2px 0 0;
    transition: left .28s cubic-bezier(.4,0,.2,1), width .28s cubic-bezier(.4,0,.2,1);
}

/* ── Tab Panels ── */
.fm-tab-panel {
    display: none;
}
.fm-tab-panel--active {
    display: block;
    animation: fmFadeIn .22s ease;
}
@keyframes fmFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Card ── */
.fm-card {
    background: var(--fm-card);
    border: 1px solid var(--fm-border);
    border-radius: var(--fm-r);
    box-shadow: var(--fm-sh);
    margin-bottom: 20px;
    overflow: hidden;
}
.fm-card-head {
    padding: 14px 20px;
    border-bottom: 1px solid var(--fm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.fm-card-head h3 {
    margin: 0;
    font-size: .92rem;
    font-weight: 700;
    color: var(--fm-t1);
    font-family: var(--fm-font);
    display: flex;
    align-items: center;
    gap: 8px;
}
.fm-card-head h3 i {
    color: var(--fm-teal);
    font-size: .95em;
}
.fm-card-body {
    padding: 20px;
}
.fm-card-body--table {
    padding: 0;
}

/* ── Form Elements ── */
.fm-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
    gap: 14px 18px;
}
.fm-fg {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.fm-fg--full {
    grid-column: 1 / -1;
}
.fm-fg--toggle {
    flex-direction: row;
    align-items: center;
    gap: 12px;
}
.fm-label {
    font-size: .73rem;
    font-weight: 600;
    color: var(--fm-t2);
    text-transform: uppercase;
    letter-spacing: .4px;
}
.fm-req {
    color: var(--fm-red);
}
.fm-input {
    background: var(--fm-bg3);
    border: 1px solid var(--fm-border);
    border-radius: var(--fm-r-sm);
    padding: 8px 12px;
    font-family: var(--fm-font);
    font-size: .84rem;
    color: var(--fm-t1);
    outline: none;
    transition: border-color var(--fm-ease), box-shadow var(--fm-ease);
    width: 100%;
}
.fm-input:focus {
    border-color: var(--fm-teal);
    box-shadow: 0 0 0 3px rgba(15,118,110,.15);
}
.fm-input--ro {
    opacity: .7;
    cursor: not-allowed;
}
.fm-textarea {
    resize: vertical;
    min-height: 44px;
}
.fm-select {
    cursor: pointer;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394c9c3' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 28px;
}
.fm-hint {
    font-size: .72rem;
    color: var(--fm-t3);
    margin-top: 2px;
}
.fm-hint--ok  { color: var(--fm-green); }
.fm-hint--err { color: var(--fm-red); }

/* ── Toggle Switch ── */
.fm-toggle {
    position: relative;
    display: inline-block;
    width: 40px;
    height: 22px;
    cursor: pointer;
}
.fm-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
    position: absolute;
}
.fm-toggle-track {
    position: absolute;
    inset: 0;
    background: var(--fm-bg3);
    border: 1px solid var(--fm-border);
    border-radius: 22px;
    transition: background var(--fm-ease), border-color var(--fm-ease);
}
.fm-toggle-track::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 16px;
    height: 16px;
    background: var(--fm-t3);
    border-radius: 50%;
    transition: transform var(--fm-ease), background var(--fm-ease);
}
.fm-toggle input:checked + .fm-toggle-track {
    background: var(--fm-teal);
    border-color: var(--fm-teal2);
}
.fm-toggle input:checked + .fm-toggle-track::after {
    transform: translateX(18px);
    background: #fff;
}

/* ── Buttons ── */
.fm-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 18px;
    border: none;
    border-radius: var(--fm-r-sm);
    font-family: var(--fm-font);
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: background var(--fm-ease), transform .12s ease, box-shadow var(--fm-ease);
}
.fm-btn:active {
    transform: scale(.97);
}
.fm-btn--primary {
    background: var(--fm-teal);
    color: #fff;
}
.fm-btn--primary:hover {
    background: var(--fm-teal2);
    box-shadow: 0 2px 12px rgba(15,118,110,.35);
}
.fm-btn--ghost {
    background: transparent;
    color: var(--fm-t2);
    border: 1px solid var(--fm-border);
}
.fm-btn--ghost:hover {
    background: var(--fm-bg3);
    color: var(--fm-t1);
}
.fm-btn--sm {
    padding: 6px 14px;
    font-size: .78rem;
}
.fm-form-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid var(--fm-border);
}

/* ── Table ── */
.fm-table-wrap {
    overflow-x: auto;
}
.fm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .8rem;
}
.fm-table th {
    background: var(--fm-bg3);
    padding: 10px 14px;
    text-align: left;
    font-weight: 700;
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--fm-t2);
    border-bottom: 1px solid var(--fm-border);
    white-space: nowrap;
}
.fm-table td {
    padding: 10px 14px;
    color: var(--fm-t1);
    border-bottom: 1px solid var(--fm-border);
    vertical-align: middle;
}
.fm-table tbody tr:hover {
    background: rgba(15,118,110,.04);
}
.fm-table tbody tr:last-child td {
    border-bottom: none;
}
.fm-empty {
    text-align: center !important;
    color: var(--fm-t3) !important;
    padding: 32px 14px !important;
    font-style: italic;
}
.fm-cell-name {
    font-weight: 600;
}
.fm-cell-criteria {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.fm-cell-actions {
    display: flex;
    gap: 6px;
    align-items: center;
}
.fm-muted {
    color: var(--fm-t3);
    opacity: .5;
}

/* ── Action Buttons ── */
.fm-act-btn {
    width: 30px;
    height: 30px;
    border: none;
    border-radius: var(--fm-r-sm);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .78rem;
    cursor: pointer;
    transition: background var(--fm-ease), transform .12s ease;
}
.fm-act-btn:active { transform: scale(.9); }
.fm-act-btn--edit {
    background: rgba(15,118,110,.12);
    color: var(--fm-teal);
}
.fm-act-btn--edit:hover {
    background: rgba(15,118,110,.25);
}
.fm-act-btn--del {
    background: rgba(224,92,111,.1);
    color: var(--fm-red);
}
.fm-act-btn--del:hover {
    background: rgba(224,92,111,.22);
}
.fm-act-btn--refresh {
    background: rgba(37,99,235,.1);
    color: #2563eb;
    margin-right: 4px;
}
.fm-act-btn--refresh:hover {
    background: rgba(37,99,235,.22);
}

/* ── Badges ── */
.fm-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .3px;
    text-transform: uppercase;
    white-space: nowrap;
}
.fm-badge--teal  { background: rgba(15,118,110,.15); color: var(--fm-teal); }
.fm-badge--navy  { background: rgba(74,181,227,.12); color: var(--fm-sky); }
.fm-badge--green { background: rgba(21,128,61,.12); color: var(--fm-green); }
.fm-badge--red   { background: rgba(224,92,111,.12); color: var(--fm-red); }
.fm-badge--gold  { background: rgba(217,119,6,.12); color: var(--fm-gold); }

/* ── Modal ── */
.fm-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(7,15,28,.7);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transition: opacity .25s ease, visibility .25s ease;
}
.fm-modal-overlay--show {
    opacity: 1;
    visibility: visible;
}
.fm-modal {
    background: var(--fm-card);
    border: 1px solid var(--fm-brd2);
    border-radius: var(--fm-r);
    width: 460px;
    max-width: 94vw;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 12px 48px rgba(0,0,0,.5);
    transform: translateY(16px) scale(.97);
    transition: transform .25s cubic-bezier(.4,0,.2,1);
}
.fm-modal-overlay--show .fm-modal {
    transform: translateY(0) scale(1);
}
.fm-modal-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--fm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.fm-modal-head h3 {
    margin: 0;
    font-size: .95rem;
    font-weight: 700;
    color: var(--fm-t1);
    font-family: var(--fm-font);
    display: flex;
    align-items: center;
    gap: 8px;
}
.fm-modal-head h3 i {
    color: var(--fm-teal);
}
.fm-modal-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    color: var(--fm-t3);
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    transition: color var(--fm-ease);
}
.fm-modal-close:hover {
    color: var(--fm-red);
}
.fm-modal-body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.fm-modal-foot {
    padding: 14px 20px;
    border-top: 1px solid var(--fm-border);
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* ── Toast ── */
.fm-toast-wrap {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}
.fm-toast {
    padding: 10px 18px;
    border-radius: var(--fm-r-sm);
    font-family: var(--fm-font);
    font-size: .82rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    pointer-events: auto;
    opacity: 0;
    transform: translateX(24px);
    transition: opacity .25s ease, transform .25s ease;
    box-shadow: 0 4px 20px rgba(0,0,0,.35);
}
.fm-toast--show {
    opacity: 1;
    transform: translateX(0);
}
.fm-toast--success {
    background: var(--fm-green);
    color: #fff;
}
.fm-toast--error {
    background: var(--fm-red);
    color: #fff;
}
.fm-toast--info {
    background: var(--fm-sky);
    color: #fff;
}

/* ── Responsive ── */
@media (max-width: 1024px) {
    .fm-stats-row { grid-template-columns: repeat(2, 1fr); }
    .fm-form-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 767px) {
    .fm-topbar { padding: 14px 16px 10px; }
    .fm-topbar-inner { flex-direction: column; align-items: flex-start; }
    .fm-stats-row { grid-template-columns: 1fr; padding: 0 16px 14px; }
    .fm-content-wrap { padding: 0 16px 24px; }
    .fm-form-grid { grid-template-columns: 1fr; }
    .fm-table { font-size: .76rem; }
    .fm-table th, .fm-table td { padding: 8px 10px; }
    .fm-card-head { padding: 12px 14px; }
    .fm-card-body { padding: 14px; }
}
@media (max-width: 479px) {
    .fm-page-title { font-size: 1.1rem; }
    .fm-tab-btn { padding: 8px 14px; font-size: .78rem; }
    .fm-modal { width: 98vw; }
}
</style>
