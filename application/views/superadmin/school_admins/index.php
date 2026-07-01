<!-- Page Header -->
<section class="content-header">
    <h1><i class="fa fa-user" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>School Super Admin Management</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('superadmin/school_admins') ?>">Admin Access</a></li>
        <li class="active">School Super Admins</li>
    </ol>
</section>

<section class="content" style="padding:16px 24px;">

<!-- Action Bar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
    <div style="color:var(--t2);font-size:13px;">
        <i class="fa fa-info-circle" style="color:var(--sa3);margin-right:4px;"></i>
        The School Super Admin created for each school at onboarding. They log in with their <strong>School Code</strong> + <strong>SSA ID</strong>.
    </div>
</div>

<!-- ═══ Platform Password-Recovery Contact (shown to School Super Admins) ═══ -->
<div class="sa-card" style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:18px;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
        <i class="fa fa-life-ring" style="color:var(--sa3);font-size:15px;"></i>
        <span style="font-weight:700;color:var(--t1);font-size:14px;">Password-Recovery Contact</span>
    </div>
    <div style="color:var(--t3);font-size:12px;margin-bottom:14px;line-height:1.6;">
        Shown to a <strong style="color:var(--t2);">School Super Admin</strong> when they tap &ldquo;Forgot password&rdquo; &mdash; they have no admin above them inside their school, so they reach the ZenXii platform team. This is one global contact for the whole platform.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
        <div>
            <label style="color:var(--t2);font-weight:600;font-size:12px;display:block;margin-bottom:5px;">Name <span style="color:#ef4444;">*</span></label>
            <input type="text" id="recName" class="form-control sa-inp" maxlength="100" placeholder="e.g. ZenXii Support" style="width:100%;">
        </div>
        <div>
            <label style="color:var(--t2);font-weight:600;font-size:12px;display:block;margin-bottom:5px;">Phone</label>
            <input type="text" id="recNumber" class="form-control sa-inp" maxlength="20" placeholder="e.g. 9876543210" style="width:100%;">
        </div>
        <div>
            <label style="color:var(--t2);font-weight:600;font-size:12px;display:block;margin-bottom:5px;">Email</label>
            <input type="email" id="recEmail" class="form-control sa-inp" maxlength="120" placeholder="e.g. support@zenxii.com" style="width:100%;">
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;margin-top:14px;">
        <button type="button" id="btnSaveRecovery" class="btn btn-sm" style="background:var(--sa3);color:#fff;border:none;border-radius:6px;padding:7px 22px;font-weight:600;">
            <i class="fa fa-floppy-o" style="margin-right:5px;"></i>Save contact
        </button>
        <span id="recStatus" style="font-size:12px;color:var(--t3);"></span>
    </div>
</div>

<!-- Search Bar -->
<div style="margin-bottom:14px;">
    <div style="position:relative;max-width:420px;">
        <i class="fa fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--t4);font-size:13px;z-index:2;pointer-events:none;"></i>
        <input type="text" id="adminSearch" class="form-control sa-inp sa-search-inp" placeholder="Search by ID, name, email, phone or school...">
        <i id="adminSearchClear" class="fa fa-times" style="display:none;position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--t4);cursor:pointer;font-size:13px;z-index:2;"></i>
    </div>
</div>

<!-- SSA Table -->
<div class="sa-card" style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;">
    <div id="adminTableWrap" style="padding:16px;">
        <table id="adminsTable" class="display" style="width:100%;">
            <thead>
                <tr>
                    <th>SSA ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>School</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th style="width:110px;">Actions</th>
                </tr>
            </thead>
            <tbody id="adminsTbody"></tbody>
        </table>
    </div>
    <div id="adminLoader" style="text-align:center;padding:40px;display:none;">
        <i class="fa fa-spinner fa-spin" style="font-size:24px;color:var(--sa3);"></i>
        <div style="margin-top:8px;color:var(--t3);font-size:13px;">Loading school super admins...</div>
    </div>
    <div id="adminEmpty" style="text-align:center;padding:40px;display:none;color:var(--t3);font-size:13px;">
        <i class="fa fa-inbox" style="font-size:24px;color:var(--t4);"></i>
        <div style="margin-top:8px;">No school super admins found. Onboard a school to create one.</div>
    </div>
</div>

</section>

<!-- ═══════════════════════ RESET PASSWORD MODAL ═══════════════════════ -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog" style="max-width:440px;">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:14px 20px;">
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" style="color:var(--t1);font-weight:700;font-size:15px;">
                    <i class="fa fa-key" style="color:var(--sa3);margin-right:6px;"></i>Reset Password — <span id="rAdminLabel"></span>
                </h4>
            </div>
            <div class="modal-body" style="padding:20px;">
                <input type="hidden" id="rSsaId">
                <input type="hidden" id="rSchoolUid">
                <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:8px;padding:10px 12px;margin-bottom:16px;color:var(--t2);font-size:12px;">
                    <i class="fa fa-exclamation-triangle" style="color:#f59e0b;margin-right:4px;"></i>
                    The admin will be required to change this password on next login, and all their active sessions will end.
                </div>
                <div class="form-group">
                    <label style="color:var(--t2);font-weight:600;font-size:12px;">New Password <span style="color:#ef4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" id="rPassword" class="form-control sa-inp" placeholder="Min 8 chars, upper+lower+number" maxlength="72" style="padding-right:40px;">
                        <i class="fa fa-eye-slash pwd-toggle" data-target="rPassword" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--t4);"></i>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="color:var(--t2);font-weight:600;font-size:12px;">Confirm Password <span style="color:#ef4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" id="rConfirm" class="form-control sa-inp" placeholder="Re-enter password" maxlength="72" style="padding-right:40px;">
                        <i class="fa fa-eye-slash pwd-toggle" data-target="rConfirm" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--t4);"></i>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal" style="border-radius:6px;">Cancel</button>
                <button type="button" id="btnResetPw" class="btn btn-sm" style="background:#ef4444;color:#fff;border:none;border-radius:6px;padding:6px 20px;font-weight:600;">Reset Password</button>
            </div>
        </div>
    </div>
</div>

<style>
.sa-inp{background:var(--bg) !important;border:1px solid var(--border) !important;color:var(--t1) !important;border-radius:7px !important;padding:8px 12px !important;font-size:13px !important;font-family:var(--font-m) !important;}
.sa-inp:focus{border-color:var(--sa4) !important;box-shadow:0 0 0 2px rgba(99,102,241,.15) !important;}
/* Leave room for the leading search icon / trailing clear button — longhand !important
   overrides the .sa-inp `padding` shorthand so the placeholder no longer sits under the icon. */
.sa-search-inp{width:100%;padding-left:36px !important;padding-right:36px !important;}
#adminsTable{font-family:var(--font-m);font-size:13px;color:var(--t1);}
#adminsTable thead th{background:var(--bg3) !important;color:var(--t2) !important;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid var(--border) !important;padding:10px 12px !important;}
#adminsTable tbody td{padding:10px 12px !important;border-bottom:1px solid var(--border) !important;vertical-align:middle;}
#adminsTable tbody tr:hover{background:var(--bg3) !important;}
.sa-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.3px;}
.sa-badge-active{background:rgba(16,185,129,.12);color:#10b981;}
.sa-badge-inactive{background:rgba(239,68,68,.12);color:#ef4444;}
.sa-abtn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--t3);cursor:pointer;transition:all .15s;font-size:12px;margin-right:3px;}
.sa-abtn:hover{background:var(--bg3);color:var(--t1);border-color:var(--t4);}
.sa-abtn.warn:hover{background:rgba(245,158,11,.1);color:#f59e0b;border-color:#f59e0b;}
.sa-abtn.ok:hover{background:rgba(16,185,129,.1);color:#10b981;border-color:#10b981;}
.sa-abtn.danger:hover{background:rgba(239,68,68,.1);color:#ef4444;border-color:#ef4444;}
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var BASE = '<?= base_url() ?>';
    var CSRF = '<?= $sa_csrf_token ?>';
    var dt = null;

    // ── Custom search: match ID / name / email / phone / school across the row ──
    // Phone is not a visible column, so we match a data-search blob on the <tr>
    // (built in loadAdmins) rather than the rendered cell text.
    $.fn.dataTable.ext.search.push(function(settings, searchData, index) {
        if (settings.nTable.id !== 'adminsTable') return true;
        var q = ($('#adminSearch').val() || '').toLowerCase().trim();
        if (!q) return true;
        var node = settings.aoData[index].nTr;
        var hay = (node && node.getAttribute('data-search') || '').toLowerCase();
        return hay.indexOf(q) !== -1;
    });

    $('#adminSearch').on('keyup input', function() {
        $('#adminSearchClear').toggle(!!this.value);
        if (dt) dt.draw();
    });
    $('#adminSearchClear').on('click', function() {
        $('#adminSearch').val('').trigger('input').focus();
    });

    // ── Helpers ──
    function post(url, data, cb) {
        data.csrf_token = CSRF;
        $.post(BASE + url, data, function(r){
            if (typeof r === 'string') try { r = JSON.parse(r); } catch(e){}
            cb(r);
        }).fail(function(xhr){
            var msg = 'Request failed';
            try { var err = JSON.parse(xhr.responseText); msg = err.message || msg; } catch(e){}
            cb({status: 'error', message: msg});
        });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function fmtDate(iso) {
        if (!iso) return '<span style="color:var(--t4);">Never</span>';
        var d = new Date(iso);
        if (isNaN(d)) return esc(iso);
        return d.toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'})
             + ' ' + d.toLocaleTimeString('en-IN', {hour:'2-digit',minute:'2-digit'});
    }

    function statusBadge(s) {
        var cls = s === 'Active' ? 'sa-badge-active' : 'sa-badge-inactive';
        return '<span class="sa-badge ' + cls + '">' + esc(s) + '</span>';
    }

    // ── Load SSAs ──
    function loadAdmins() {
        $('#adminLoader').show();
        $('#adminTableWrap').hide();
        $('#adminEmpty').hide();

        post('superadmin/school_admins/fetch', {}, function(r) {
            $('#adminLoader').hide();

            if (dt) { dt.destroy(); $('#adminsTbody').empty(); dt = null; }

            // Surface a real failure instead of silently showing "none found".
            if (r && r.status === 'error') {
                $('#adminEmpty').html('<i class="fa fa-exclamation-triangle" style="font-size:24px;color:#ef4444;"></i>' +
                    '<div style="margin-top:8px;">Fetch failed: ' + esc(r.message || 'unknown error') + '</div>').show();
                return;
            }

            var admins = (r && r.admins) || [];
            if (!admins.length) { $('#adminEmpty').show(); return; }
            $('#adminTableWrap').show();

            var html = '';
            for (var i = 0; i < admins.length; i++) {
                var a = admins[i];
                var isActive = a.status === 'Active';
                var toggleBtn = isActive
                    ? '<button class="sa-abtn danger" title="Deactivate" onclick="toggleStatus(\'' + esc(a.school_uid) + '\',\'' + esc(a.ssa_id) + '\',\'' + esc(a.school_name) + '\',\'Inactive\')"><i class="fa fa-power-off"></i></button>'
                    : '<button class="sa-abtn ok" title="Activate" onclick="toggleStatus(\'' + esc(a.school_uid) + '\',\'' + esc(a.ssa_id) + '\',\'' + esc(a.school_name) + '\',\'Active\')"><i class="fa fa-power-off"></i></button>';
                var searchBlob = esc([a.ssa_id, a.name, a.email, a.phone, a.school_name, a.school_code].join(' '));
                html += '<tr data-search="' + searchBlob + '">' +
                    '<td><code style="background:var(--bg3);padding:2px 8px;border-radius:4px;font-size:12px;">' + esc(a.ssa_id) + '</code></td>' +
                    '<td>' + (esc(a.name) || '-') + '</td>' +
                    '<td style="font-size:12px;color:var(--t3);">' + (esc(a.email) || '-') + '</td>' +
                    '<td>' + esc(a.school_name) +
                        ' <span style="font-size:11px;color:var(--t4);">(' + esc(a.school_code) + ')</span></td>' +
                    '<td>' + statusBadge(a.status) + '</td>' +
                    '<td style="font-size:12px;">' + fmtDate(a.last_login) + '</td>' +
                    '<td>' +
                        '<button class="sa-abtn warn" title="Reset password" onclick="resetPw(\'' + esc(a.school_uid) + '\',\'' + esc(a.ssa_id) + '\')"><i class="fa fa-key"></i></button>' +
                        toggleBtn +
                    '</td>' +
                '</tr>';
            }
            $('#adminsTbody').html(html);

            dt = $('#adminsTable').DataTable({
                paging: false,
                info: false,
                searching: true,
                dom: 't',
                order: [[3, 'asc']]
            });
        });
    }

    loadAdmins();

    // ── Platform recovery contact (shown to SSAs on forgot-password) ──
    function loadRecovery() {
        post('superadmin/school_admins/get_recovery', {}, function(r){
            if (r && r.status === 'success' && r.recovery) {
                $('#recName').val(r.recovery.name || '');
                $('#recNumber').val(r.recovery.number || '');
                $('#recEmail').val(r.recovery.email || '');
            }
        });
    }
    loadRecovery();

    $('#btnSaveRecovery').click(function(){
        var name   = $.trim($('#recName').val());
        var number = $.trim($('#recNumber').val());
        var email  = $.trim($('#recEmail').val());
        if (!name) return alert('Name is required.');
        if (!number && !email) return alert('Provide at least a phone number or an email.');

        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        $('#recStatus').css('color', 'var(--t3)').text('Saving…');
        post('superadmin/school_admins/update_recovery', { name: name, number: number, email: email }, function(r){
            $btn.prop('disabled', false).html('<i class="fa fa-floppy-o" style="margin-right:5px;"></i>Save contact');
            if (r.status === 'success') {
                $('#recStatus').css('color', '#10b981').text('Saved ✓');
                setTimeout(function(){ $('#recStatus').text(''); }, 2500);
            } else {
                $('#recStatus').css('color', '#ef4444').text(r.message || 'Failed.');
            }
        });
    });

    // ── Password toggle ──
    $(document).on('click', '.pwd-toggle', function(){
        var t = document.getElementById($(this).data('target'));
        if (!t) return;
        if (t.type === 'password') { t.type = 'text'; $(this).removeClass('fa-eye-slash').addClass('fa-eye'); }
        else { t.type = 'password'; $(this).removeClass('fa-eye').addClass('fa-eye-slash'); }
    });

    // ── Reset Password ──
    window.resetPw = function(schoolUid, ssaId) {
        $('#rSchoolUid').val(schoolUid);
        $('#rSsaId').val(ssaId);
        $('#rAdminLabel').text(ssaId);
        $('#rPassword').val('');
        $('#rConfirm').val('');
        $('#resetModal').modal('show');
    };

    $('#btnResetPw').click(function(){
        var schoolUid = $('#rSchoolUid').val();
        var ssaId     = $('#rSsaId').val();
        var pw        = $('#rPassword').val();
        var cf        = $('#rConfirm').val();
        if (!pw) return alert('Enter a new password.');
        if (pw !== cf) return alert('Passwords do not match.');

        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        post('superadmin/school_admins/reset_password', { school_uid: schoolUid, ssa_id: ssaId, new_password: pw }, function(r){
            $btn.prop('disabled', false).html('Reset Password');
            if (r.status === 'success') {
                $('#resetModal').modal('hide');
                alert(r.message);
            } else {
                alert(r.message || 'Failed.');
            }
        });
    });

    // ── Toggle Status ──
    window.toggleStatus = function(schoolUid, ssaId, schoolName, target) {
        var verb = (target === 'Inactive') ? 'deactivate' : 'activate';
        if (!confirm('Are you sure you want to ' + verb + ' the School Super Admin "' + ssaId + '" for ' + schoolName + '?')) return;
        post('superadmin/school_admins/toggle_status', { school_uid: schoolUid, ssa_id: ssaId }, function(r){
            if (r.status === 'success') loadAdmins();
            else alert(r.message || 'Failed.');
        });
    };
});
</script>
