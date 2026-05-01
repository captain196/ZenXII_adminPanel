<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Student Leave — uses the sa- (student attendance) design system ── */
.sl-page-head {
    display:flex; align-items:center; justify-content:space-between;
    gap:14px; margin-bottom:20px; flex-wrap:wrap;
}
.sl-page-title {
    font-family:var(--sa-font,var(--font-b)); font-size:18px; font-weight:800;
    color:var(--t1); margin:0; display:flex; align-items:center; gap:10px;
}
.sl-page-sub { font-size:13px; color:var(--t3); margin:4px 0 0; }
.sl-count-badge {
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 14px; border-radius:20px; font-size:13px; font-weight:700;
    background:rgba(217,119,6,.12); color:#d97706;
    font-family:var(--font-m,monospace);
}

/* ── Filter Bar ── */
.sl-filter-card {
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    padding:14px 18px; margin-bottom:20px;
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--sa-r,10px);
}
.sl-filter-card select {
    padding:8px 14px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg); color:var(--t1); font-size:13px; font-family:var(--font-b);
    min-width:130px;
}
.sl-filter-card .sl-load-btn {
    padding:8px 20px; border:none; border-radius:8px;
    background:var(--gold); color:#fff; font-size:13px; font-weight:700;
    cursor:pointer; display:flex; align-items:center; gap:6px;
    font-family:var(--font-b);
}
.sl-filter-card .sl-load-btn:hover { opacity:.9; }

/* ── Cards ── */
.sl-card {
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--sa-r,10px);
    padding:18px 22px; margin-bottom:14px;
    transition:all .2s ease;
}
.sl-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.06); }
.sl-card-head {
    display:flex; justify-content:space-between; align-items:center;
    margin-bottom:12px; gap:10px;
}
.sl-student-info { display:flex; align-items:center; gap:10px; }
.sl-avatar {
    width:36px; height:36px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:800; color:#fff;
    background:var(--gold);
}
.sl-name { font-weight:700; font-size:14px; color:var(--t1); }
.sl-class { font-size:12px; color:var(--t3); }

.sl-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700;
}
.sl-badge-pending  { background:rgba(217,119,6,.12); color:#d97706; }
.sl-badge-approved { background:rgba(22,163,74,.12);  color:#16a34a; }
.sl-badge-rejected { background:rgba(220,38,38,.12);  color:#dc2626; }
.sl-badge-cancelled{ background:rgba(100,116,139,.12); color:#64748b; }

.sl-meta {
    display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr));
    gap:8px 16px; margin-bottom:12px;
}
.sl-meta-item { font-size:12px; color:var(--t3); }
.sl-meta-item strong { color:var(--t1); font-weight:600; }

.sl-reason {
    font-size:13px; color:var(--t2); padding:10px 14px;
    background:var(--bg); border-radius:8px; margin-bottom:14px;
    border-left:3px solid var(--gold-dim,rgba(15,118,110,.2));
}
.sl-approver { font-size:12px; color:var(--t3); margin-bottom:12px; }
.sl-approver em { color:var(--t2); }

/* ── Actions ── */
.sl-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.sl-remarks-input {
    flex:1; min-width:200px; padding:8px 14px;
    border:1px solid var(--border); border-radius:8px;
    font-size:13px; color:var(--t1); background:var(--bg);
    font-family:var(--font-b);
}
.sl-remarks-input:focus { outline:none; border-color:var(--gold); }
.sl-btn {
    padding:8px 20px; border-radius:8px; border:none;
    font-size:13px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; gap:6px;
    transition:all .15s ease; font-family:var(--font-b);
}
.sl-btn-approve { background:#16a34a; color:#fff; }
.sl-btn-approve:hover { background:#15803d; }
.sl-btn-reject { background:#dc2626; color:#fff; }
.sl-btn-reject:hover { background:#b91c1c; }
.sl-btn:disabled { opacity:.5; cursor:not-allowed; }

/* ── States ── */
.sl-empty {
    text-align:center; padding:70px 20px; color:var(--t3);
    font-family:var(--font-b);
}
.sl-empty i { font-size:48px; display:block; margin-bottom:14px; opacity:.3; }
.sl-loading { text-align:center; padding:50px; color:var(--t3); }

.sl-toast {
    position:fixed; bottom:24px; right:24px; z-index:9999;
    padding:14px 24px; border-radius:10px; font-size:13px; font-weight:600;
    color:#fff; box-shadow:0 10px 30px rgba(0,0,0,.2);
    transform:translateY(120px); opacity:0; transition:all .35s ease;
    pointer-events:none; font-family:var(--font-b);
}
.sl-toast.show { transform:translateY(0); opacity:1; }
.sl-toast.success { background:#16a34a; }
.sl-toast.error { background:#dc2626; }
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="sl-page-head">
        <div>
            <h4 class="sl-page-title">
                <i class="fa fa-calendar-check-o"></i> Student Leave Applications
            </h4>
            <p class="sl-page-sub">Review, approve, or reject student leave requests</p>
        </div>
        <span class="sl-count-badge" id="slCountBadge" style="display:none;">
            <i class="fa fa-hourglass-half"></i> <span id="slCountNum">0</span> pending
        </span>
    </div>

    <!-- Filters -->
    <div class="sl-filter-card">
        <select id="slStatus">
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="all">All Status</option>
        </select>
        <select id="slClass">
            <option value="">All Classes</option>
            <?php
            $seen = [];
            foreach ($Classes as $cls) {
                $cn = $cls['class_name'];
                if (!in_array($cn, $seen)) {
                    $seen[] = $cn;
                    echo '<option value="' . htmlspecialchars($cn) . '">' . htmlspecialchars(str_replace('Class ', '', $cn)) . '</option>';
                }
            }
            ?>
        </select>
        <button class="sl-load-btn" onclick="loadLeaves()"><i class="fa fa-search"></i> Load</button>
    </div>

    <!-- Content -->
    <div id="slLoading" class="sl-loading" style="display:none;"><i class="fa fa-spinner fa-spin fa-2x"></i><br><br>Loading leave applications...</div>
    <div id="slEmpty" class="sl-empty" style="display:none;">
        <i class="fa fa-inbox"></i>
        <p><strong>No leave applications found</strong></p>
        <p>Try changing the filters above</p>
    </div>
    <div id="slList"></div>

</div>
</section>
</div>

<div class="sl-toast" id="slToast"></div>

<script>
(function() {
    var csrfToken = '<?= $this->security->get_csrf_hash() ?>';
    var AVATAR_COLORS = ['#0f766e','#2563eb','#7c3aed','#c2410c','#0891b2','#4f46e5','#059669','#b91c1c'];

    function postData(url, params) {
        params.csrf_token = csrfToken;
        return fetch('<?= base_url() ?>' + url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(params)
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.csrf_token) csrfToken = d.csrf_token;
            return d;
        });
    }

    function showToast(msg, type) {
        var t = document.getElementById('slToast');
        t.textContent = msg;
        t.className = 'sl-toast show ' + type;
        setTimeout(function() { t.className = 'sl-toast'; }, 3500);
    }

    function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function getInitials(name) {
        if (!name) return '?';
        var p = name.trim().split(/\s+/);
        return p.length >= 2 ? (p[0][0] + p[p.length-1][0]).toUpperCase() : p[0].substring(0,2).toUpperCase();
    }

    window.loadLeaves = function() {
        var status = document.getElementById('slStatus').value;
        var cls = document.getElementById('slClass').value;
        document.getElementById('slLoading').style.display = 'block';
        document.getElementById('slEmpty').style.display = 'none';
        document.getElementById('slList').innerHTML = '';
        document.getElementById('slCountBadge').style.display = 'none';

        postData('attendance/list_student_leaves', {
            status_filter: status,
            'class': cls
        }).then(function(res) {
            document.getElementById('slLoading').style.display = 'none';
            if (!res || res.status !== 'success' || !res.leaves || res.leaves.length === 0) {
                document.getElementById('slEmpty').style.display = 'block';
                return;
            }
            var pending = res.leaves.filter(function(l) { return l.status === 'pending'; }).length;
            if (pending > 0) {
                document.getElementById('slCountNum').textContent = pending;
                document.getElementById('slCountBadge').style.display = 'inline-flex';
            }
            renderLeaves(res.leaves);
        }).catch(function() {
            document.getElementById('slLoading').style.display = 'none';
            showToast('Failed to load leave applications.', 'error');
        });
    };

    function renderLeaves(leaves) {
        var html = '';
        leaves.forEach(function(l, idx) {
            var badgeClass = 'sl-badge-' + l.status;
            var isPending = l.status === 'pending';
            var avColor = AVATAR_COLORS[idx % AVATAR_COLORS.length];

            html += '<div class="sl-card" data-id="' + esc(l.id) + '">';

            // Head: avatar + name + badge
            html += '<div class="sl-card-head">';
            html += '<div class="sl-student-info">';
            html += '<div class="sl-avatar" style="background:' + avColor + '">' + getInitials(l.studentName) + '</div>';
            html += '<div><div class="sl-name">' + esc(l.studentName || l.studentId) + '</div>';
            html += '<div class="sl-class">' + esc((l.className || '')) + ' / ' + esc((l.section || '')) + '</div></div>';
            html += '</div>';
            html += '<span class="sl-badge ' + badgeClass + '"><i class="fa fa-' + (l.status === 'approved' ? 'check' : l.status === 'rejected' ? 'times' : l.status === 'cancelled' ? 'ban' : 'clock-o') + '"></i> ' + esc(l.status.charAt(0).toUpperCase() + l.status.slice(1)) + '</span>';
            html += '</div>';

            // Meta: type, dates, days
            html += '<div class="sl-meta">';
            html += '<div class="sl-meta-item"><strong>Type:</strong> ' + esc(l.leaveType) + '</div>';
            html += '<div class="sl-meta-item"><strong>From:</strong> ' + esc(l.startDate) + '</div>';
            html += '<div class="sl-meta-item"><strong>To:</strong> ' + esc(l.endDate) + '</div>';
            html += '<div class="sl-meta-item"><strong>Days:</strong> ' + l.numberOfDays + '</div>';
            html += '</div>';

            // Reason
            html += '<div class="sl-reason"><i class="fa fa-quote-left" style="opacity:.3;margin-right:6px"></i>' + esc(l.reason) + '</div>';

            // Approver info (if processed)
            if (l.approvedBy) {
                html += '<div class="sl-approver"><i class="fa fa-user-circle"></i> Processed by <strong>' + esc(l.approvedBy) + '</strong>';
                if (l.remarks) html += ' &mdash; <em>"' + esc(l.remarks) + '"</em>';
                html += '</div>';
            }

            // Actions (only for pending)
            if (isPending) {
                html += '<div class="sl-actions">';
                html += '<input type="text" class="sl-remarks-input" placeholder="Add remarks (optional for approve, required for reject)" data-id="' + esc(l.id) + '">';
                html += '<button class="sl-btn sl-btn-approve" onclick="approveLeave(\'' + esc(l.id) + '\')"><i class="fa fa-check"></i> Approve</button>';
                html += '<button class="sl-btn sl-btn-reject" onclick="rejectLeave(\'' + esc(l.id) + '\')"><i class="fa fa-times"></i> Reject</button>';
                html += '</div>';
            }

            html += '</div>';
        });
        document.getElementById('slList').innerHTML = html;
    }

    window.approveLeave = function(leaveId) {
        var remarks = document.querySelector('.sl-remarks-input[data-id="' + leaveId + '"]');
        var remarksVal = remarks ? remarks.value.trim() : '';
        var btns = document.querySelectorAll('.sl-card[data-id="' + leaveId + '"] .sl-btn');
        btns.forEach(function(b) { b.disabled = true; });

        postData('attendance/approve_student_leave', {
            leave_id: leaveId,
            remarks: remarksVal
        }).then(function(res) {
            if (res && res.status === 'success') {
                showToast(res.message || 'Leave approved!', 'success');
                loadLeaves();
            } else {
                showToast(res ? res.message : 'Failed to approve.', 'error');
                btns.forEach(function(b) { b.disabled = false; });
            }
        }).catch(function() {
            showToast('Network error.', 'error');
            btns.forEach(function(b) { b.disabled = false; });
        });
    };

    window.rejectLeave = function(leaveId) {
        var remarks = document.querySelector('.sl-remarks-input[data-id="' + leaveId + '"]');
        var remarksVal = remarks ? remarks.value.trim() : '';
        if (!remarksVal) {
            showToast('Please enter remarks before rejecting.', 'error');
            if (remarks) { remarks.focus(); remarks.style.borderColor = '#dc2626'; }
            return;
        }
        var btns = document.querySelectorAll('.sl-card[data-id="' + leaveId + '"] .sl-btn');
        btns.forEach(function(b) { b.disabled = true; });

        postData('attendance/reject_student_leave', {
            leave_id: leaveId,
            remarks: remarksVal
        }).then(function(res) {
            if (res && res.status === 'success') {
                showToast('Leave rejected.', 'success');
                loadLeaves();
            } else {
                showToast(res ? res.message : 'Failed to reject.', 'error');
                btns.forEach(function(b) { b.disabled = false; });
            }
        }).catch(function() {
            showToast('Network error.', 'error');
            btns.forEach(function(b) { b.disabled = false; });
        });
    };

    // Auto-load on page open
    loadLeaves();
})();
</script>
