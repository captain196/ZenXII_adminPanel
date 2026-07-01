<!-- Page Header -->
<section class="content-header">
    <h1><i class="fa fa-user-secret" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Super Admin Management</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('superadmin/admins') ?>">Admin Access</a></li>
        <li class="active">Super Admins</li>
    </ol>
</section>

<section class="content" style="padding:16px 24px;">

<!-- Action Bar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
    <div style="color:var(--t2);font-size:13px;">
        <i class="fa fa-info-circle" style="color:var(--sa3);margin-right:4px;"></i>
        Developer super admins can log in with School ID = <strong>"Our Panel"</strong>
    </div>
    <button type="button" id="btnNewAdmin" class="btn btn-sm" style="background:var(--sa4);color:#fff;border:none;border-radius:6px;padding:6px 16px;font-weight:600;">
        <i class="fa fa-plus" style="margin-right:4px;"></i> New Super Admin
    </button>
</div>

<?php if (($sa_id ?? '') === 'SUP0001'): ?>
<!-- ═══ Super-Admin Recovery Contact (SUP0001 only) ═══ -->
<div class="sa-card" style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:18px;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
        <i class="fa fa-life-ring" style="color:var(--sa3);font-size:15px;"></i>
        <span style="font-weight:700;color:var(--t1);font-size:14px;">Super-Admin Recovery Contact</span>
    </div>
    <div style="color:var(--t3);font-size:12px;margin-bottom:14px;line-height:1.6;">
        Shown to <strong style="color:var(--t2);">other super admins</strong> when they tap &ldquo;Forgot password&rdquo; on the panel login. Only you (<strong style="color:var(--t2);">SUP0001</strong>) can edit this; you reset your own password via OTP.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
        <div>
            <label style="color:var(--t2);font-weight:600;font-size:12px;display:block;margin-bottom:5px;">Name <span style="color:#ef4444;">*</span></label>
            <input type="text" id="srecName" class="form-control sa-inp" maxlength="100" placeholder="e.g. ZenXii Owner" style="width:100%;">
        </div>
        <div>
            <label style="color:var(--t2);font-weight:600;font-size:12px;display:block;margin-bottom:5px;">Phone</label>
            <input type="text" id="srecNumber" class="form-control sa-inp" maxlength="20" placeholder="e.g. 9876543210" style="width:100%;">
        </div>
        <div>
            <label style="color:var(--t2);font-weight:600;font-size:12px;display:block;margin-bottom:5px;">Email</label>
            <input type="email" id="srecEmail" class="form-control sa-inp" maxlength="120" placeholder="e.g. owner@zenxii.com" style="width:100%;">
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;margin-top:14px;">
        <button type="button" id="btnSaveSrec" class="btn btn-sm" style="background:var(--sa3);color:#fff;border:none;border-radius:6px;padding:7px 22px;font-weight:600;">
            <i class="fa fa-floppy-o" style="margin-right:5px;"></i>Save contact
        </button>
        <span id="srecStatus" style="font-size:12px;color:var(--t3);"></span>
    </div>
</div>
<?php endif; ?>

<!-- Search Bar -->
<div style="margin-bottom:14px;">
    <div style="position:relative;max-width:360px;">
        <i class="fa fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--t4);font-size:13px;z-index:2;pointer-events:none;"></i>
        <input type="text" id="adminSearch" class="form-control sa-inp sa-search-inp" placeholder="Search by Admin ID or Name...">
        <i id="adminSearchClear" class="fa fa-times" style="display:none;position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--t4);cursor:pointer;font-size:13px;z-index:2;"></i>
    </div>
</div>

<!-- Admins Table -->
<div class="sa-card" style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;">
    <div id="adminTableWrap" style="padding:16px;">
        <table id="adminsTable" class="display" style="width:100%;">
            <thead>
                <tr>
                    <th>Admin ID</th>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Created</th>
                    <th style="width:140px;">Actions</th>
                </tr>
            </thead>
            <tbody id="adminsTbody"></tbody>
        </table>
    </div>
    <div id="adminLoader" style="text-align:center;padding:40px;display:none;">
        <i class="fa fa-spinner fa-spin" style="font-size:24px;color:var(--sa3);"></i>
        <div style="margin-top:8px;color:var(--t3);font-size:13px;">Loading admins...</div>
    </div>
</div>

</section>

<!-- ═══════════════════════ CREATE MODAL ═══════════════════════ -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog" style="max-width:480px;">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:14px 20px;">
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" style="color:var(--t1);font-weight:700;font-size:15px;">
                    <i class="fa fa-user-plus" style="color:var(--sa3);margin-right:6px;"></i>Create Super Admin
                </h4>
            </div>
            <div class="modal-body" style="padding:20px;">
                <form id="createForm">
                    <div class="form-group">
                        <label style="color:var(--t2);font-weight:600;font-size:12px;">Full Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="cName" class="form-control sa-inp" placeholder="Full name" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label style="color:var(--t2);font-weight:600;font-size:12px;">Email</label>
                        <input type="email" id="cEmail" class="form-control sa-inp" placeholder="email@example.com">
                    </div>
                    <div class="form-group">
                        <label style="color:var(--t2);font-weight:600;font-size:12px;">Phone</label>
                        <input type="text" id="cPhone" class="form-control sa-inp" placeholder="+91XXXXXXXXXX" maxlength="15">
                    </div>
                    <div class="form-group">
                        <label style="color:var(--t2);font-weight:600;font-size:12px;">Password <span style="color:#ef4444;">*</span></label>
                        <div style="position:relative;">
                            <input type="password" id="cPassword" class="form-control sa-inp" placeholder="Min 8 chars, upper+lower+number" maxlength="72" style="padding-right:40px;">
                            <i class="fa fa-eye-slash pwd-toggle" data-target="cPassword" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--t4);"></i>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="color:var(--t2);font-weight:600;font-size:12px;">Confirm Password <span style="color:#ef4444;">*</span></label>
                        <div style="position:relative;">
                            <input type="password" id="cConfirm" class="form-control sa-inp" placeholder="Re-enter password" maxlength="72" style="padding-right:40px;">
                            <i class="fa fa-eye-slash pwd-toggle" data-target="cConfirm" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--t4);"></i>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal" style="border-radius:6px;">Cancel</button>
                <button type="button" id="btnCreate" class="btn btn-sm" style="background:var(--sa4);color:#fff;border:none;border-radius:6px;padding:6px 20px;font-weight:600;">
                    <i class="fa fa-check" style="margin-right:4px;"></i>Create
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════ EDIT PROFILE MODAL ═══════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog" style="max-width:440px;">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:14px 20px;">
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" style="color:var(--t1);font-weight:700;font-size:15px;">
                    <i class="fa fa-pencil" style="color:var(--sa3);margin-right:6px;"></i>Edit Profile
                </h4>
            </div>
            <div class="modal-body" style="padding:20px;">
                <input type="hidden" id="eAdminId">
                <div class="form-group">
                    <label style="color:var(--t2);font-weight:600;font-size:12px;">Full Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="eName" class="form-control sa-inp" maxlength="100">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="color:var(--t2);font-weight:600;font-size:12px;">Email</label>
                    <input type="email" id="eEmail" class="form-control sa-inp">
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal" style="border-radius:6px;">Cancel</button>
                <button type="button" id="btnSaveEdit" class="btn btn-sm" style="background:var(--sa4);color:#fff;border:none;border-radius:6px;padding:6px 20px;font-weight:600;">Save</button>
            </div>
        </div>
    </div>
</div>

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
                <input type="hidden" id="rAdminId">
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

<!-- ═══════════════════════ CREDENTIALS SUCCESS MODAL ═══════════════════════ -->
<div class="modal fade" id="credModal" tabindex="-1" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog" style="max-width:440px;">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:14px 20px;background:rgba(16,185,129,.06);">
                <h4 class="modal-title" style="color:#10b981;font-weight:700;font-size:15px;">
                    <i class="fa fa-check-circle" style="margin-right:6px;"></i>Super Admin Created
                </h4>
            </div>
            <div class="modal-body" style="padding:20px;">
                <p style="color:var(--t2);font-size:12.5px;margin-bottom:16px;">
                    Save these credentials now. The password <strong>cannot be recovered</strong> after closing this window.
                </p>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <div>
                            <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;font-weight:600;">Admin ID</div>
                            <div id="credId" style="font-size:18px;font-weight:700;color:var(--sa3);font-family:var(--font-m);margin-top:2px;letter-spacing:1px;"></div>
                        </div>
                        <button type="button" class="sa-abtn" onclick="copyField('credId')" title="Copy ID" style="width:32px;height:32px;">
                            <i class="fa fa-copy"></i>
                        </button>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <div>
                            <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;font-weight:600;">Password</div>
                            <div id="credPw" style="font-size:18px;font-weight:700;color:var(--t1);font-family:var(--font-m);margin-top:2px;letter-spacing:1px;"></div>
                        </div>
                        <button type="button" class="sa-abtn" onclick="copyField('credPw')" title="Copy Password" style="width:32px;height:32px;">
                            <i class="fa fa-copy"></i>
                        </button>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;font-weight:600;">School ID (for login)</div>
                        <div style="font-size:15px;font-weight:600;color:var(--t2);font-family:var(--font-m);margin-top:2px;">Our Panel</div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm" onclick="copyAll()" style="width:100%;background:var(--bg3);border:1px solid var(--border);color:var(--t1);border-radius:6px;padding:8px;font-weight:600;margin-bottom:8px;">
                    <i class="fa fa-clipboard" style="margin-right:4px;"></i> Copy All Credentials
                </button>
                <div id="credCopied" style="display:none;text-align:center;color:#10b981;font-size:12px;font-weight:600;margin-top:4px;">
                    <i class="fa fa-check"></i> Copied to clipboard!
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;">
                <button type="button" class="btn btn-sm" data-dismiss="modal" style="background:var(--sa4);color:#fff;border:none;border-radius:6px;padding:6px 20px;font-weight:600;">
                    <i class="fa fa-check" style="margin-right:4px;"></i> Done
                </button>
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
.sa-badge-primary{background:rgba(245,158,11,.12);color:#f59e0b;}
.sa-badge-dev{background:rgba(99,102,241,.12);color:var(--sa4);}
.sa-abtn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--t3);cursor:pointer;transition:all .15s;font-size:12px;margin-right:3px;}
.sa-abtn:hover{background:var(--bg3);color:var(--t1);border-color:var(--t4);}
.sa-abtn.danger:hover{background:rgba(239,68,68,.1);color:#ef4444;border-color:#ef4444;}
.sa-abtn.warn:hover{background:rgba(245,158,11,.1);color:#f59e0b;border-color:#f59e0b;}
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var BASE = '<?= base_url() ?>';
    var CSRF = '<?= $sa_csrf_token ?>';
    var currentAdmin = '<?= $sa_id ?>';
    var dt = null;

    // ── Custom search: match Admin ID (col 0) or Name (col 1) only ──
    $.fn.dataTable.ext.search.push(function(settings, data) {
        if (settings.nTable.id !== 'adminsTable') return true;
        var q = ($('#adminSearch').val() || '').toLowerCase().trim();
        if (!q) return true;
        var id   = (data[0] || '').toLowerCase();
        var name = (data[1] || '').toLowerCase();
        return id.indexOf(q) !== -1 || name.indexOf(q) !== -1;
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

    function fmtDate(iso) {
        if (!iso) return '<span style="color:var(--t4);">Never</span>';
        var d = new Date(iso);
        if (isNaN(d)) return iso;
        return d.toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'})
             + ' ' + d.toLocaleTimeString('en-IN', {hour:'2-digit',minute:'2-digit'});
    }

    function statusBadge(s) {
        var cls = s === 'Active' ? 'sa-badge-active' : 'sa-badge-inactive';
        return '<span class="sa-badge ' + cls + '">' + s + '</span>';
    }

    function roleBadge(a) {
        return a.is_primary
            ? '<span class="sa-badge sa-badge-primary"><i class="fa fa-star" style="margin-right:4px;"></i>Primary</span>'
            : '<span class="sa-badge sa-badge-dev">Developer</span>';
    }

    // ── Load admins ──
    function loadAdmins() {
        $('#adminLoader').show();
        $('#adminTableWrap').hide();

        post('superadmin/admins/fetch', {}, function(r) {
            $('#adminLoader').hide();
            $('#adminTableWrap').show();

            if (dt) { dt.destroy(); $('#adminsTbody').empty(); }

            var admins = r.admins || [];
            var html = '';
            for (var i = 0; i < admins.length; i++) {
                var a = admins[i];
                var isSelf = a.is_current;
                html += '<tr>' +
                    '<td><code style="background:var(--bg3);padding:2px 8px;border-radius:4px;font-size:12px;">' + a.admin_id + '</code>' +
                        (isSelf ? ' <span style="font-size:10px;color:var(--sa4);font-weight:600;">(you)</span>' : '') + '</td>' +
                    '<td>' + (a.name || '-') + '</td>' +
                    '<td>' + roleBadge(a) + '</td>' +
                    '<td>' + statusBadge(a.status) + '</td>' +
                    '<td style="font-size:12px;">' + fmtDate(a.last_login) + '</td>' +
                    '<td style="font-size:12px;">' + fmtDate(a.created_at) + '</td>' +
                    '<td>' +
                        '<button class="sa-abtn" title="Edit profile" onclick="editAdmin(\'' + a.admin_id + '\',\'' + (a.name||'').replace(/'/g,"\\'") + '\',\'' + (a.email||'').replace(/'/g,"\\'") + '\')"><i class="fa fa-pencil"></i></button>' +
                        '<button class="sa-abtn warn" title="Reset password" onclick="resetPw(\'' + a.admin_id + '\',\'' + (a.name||'').replace(/'/g,"\\'") + '\')"><i class="fa fa-key"></i></button>' +
                        (isSelf || a.is_primary ? '' : '<button class="sa-abtn" title="Toggle status" onclick="toggleStatus(\'' + a.admin_id + '\')"><i class="fa fa-power-off"></i></button>') +
                        (isSelf || a.is_primary ? '' : '<button class="sa-abtn danger" title="Delete" onclick="deleteAdmin(\'' + a.admin_id + '\')"><i class="fa fa-trash"></i></button>') +
                    '</td>' +
                '</tr>';
            }
            $('#adminsTbody').html(html);

            dt = $('#adminsTable').DataTable({
                paging: false,
                info: false,
                searching: true,
                dom: 't',
                order: [[5, 'desc']]
            });
        });
    }

    loadAdmins();

    // ── Super-admin recovery contact (SUP0001 only) ──
    if (document.getElementById('btnSaveSrec')) {
        (function(){
            function loadSrec() {
                post('superadmin/admins/get_super_recovery', {}, function(r){
                    if (r && r.status === 'success' && r.recovery) {
                        $('#srecName').val(r.recovery.name || '');
                        $('#srecNumber').val(r.recovery.number || '');
                        $('#srecEmail').val(r.recovery.email || '');
                    }
                });
            }
            loadSrec();

            $('#btnSaveSrec').click(function(){
                var name   = $.trim($('#srecName').val());
                var number = $.trim($('#srecNumber').val());
                var email  = $.trim($('#srecEmail').val());
                if (!name) return alert('Name is required.');
                if (!number && !email) return alert('Provide at least a phone number or an email.');

                var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
                $('#srecStatus').css('color', 'var(--t3)').text('Saving…');
                post('superadmin/admins/update_super_recovery', { name: name, number: number, email: email }, function(r){
                    $btn.prop('disabled', false).html('<i class="fa fa-floppy-o" style="margin-right:5px;"></i>Save contact');
                    if (r.status === 'success') {
                        $('#srecStatus').css('color', '#10b981').text('Saved ✓');
                        setTimeout(function(){ $('#srecStatus').text(''); }, 2500);
                    } else {
                        $('#srecStatus').css('color', '#ef4444').text(r.message || 'Failed.');
                    }
                });
            });
        })();
    }

    // ── Password toggle ──
    $(document).on('click', '.pwd-toggle', function(){
        var t = document.getElementById($(this).data('target'));
        if (!t) return;
        if (t.type === 'password') { t.type = 'text'; $(this).removeClass('fa-eye-slash').addClass('fa-eye'); }
        else { t.type = 'password'; $(this).removeClass('fa-eye').addClass('fa-eye-slash'); }
    });

    // ── Create ──
    $('#btnNewAdmin').click(function(){
        $('#createForm')[0].reset();
        $('#createModal').modal('show');
    });

    $('#btnCreate').click(function(){
        var name  = $.trim($('#cName').val());
        var email = $.trim($('#cEmail').val());
        var phone = $.trim($('#cPhone').val());
        var pw    = $('#cPassword').val();
        var cf    = $('#cConfirm').val();

        if (!name || !pw) return alert('Name and Password are required.');
        if (pw !== cf) return alert('Passwords do not match.');

        var savedPw = pw;
        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');
        post('superadmin/admins/create', { name: name, email: email, phone: phone, password: pw }, function(r){
            $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Create');
            if (r.status === 'success') {
                $('#createModal').modal('hide');
                $('#credId').text(r.admin_id || '');
                $('#credPw').text(savedPw);
                $('#credCopied').hide();
                $('#credModal').modal('show');
                loadAdmins();
            } else {
                alert(r.message || 'Failed to create admin.');
            }
        });
    });

    // ── Copy helpers for credentials modal ──
    window.copyField = function(elId) {
        var text = document.getElementById(elId).textContent;
        navigator.clipboard.writeText(text).then(function(){
            $('#credCopied').show().delay(2000).fadeOut();
        });
    };
    window.copyAll = function() {
        var id = $('#credId').text();
        var pw = $('#credPw').text();
        var text = 'Admin ID: ' + id + '\nPassword: ' + pw + '\nSchool ID: Our Panel';
        navigator.clipboard.writeText(text).then(function(){
            $('#credCopied').show().delay(2000).fadeOut();
        });
    };

    // ── Edit ──
    window.editAdmin = function(id, name, email) {
        $('#eAdminId').val(id);
        $('#eName').val(name);
        $('#eEmail').val(email);
        $('#editModal').modal('show');
    };

    $('#btnSaveEdit').click(function(){
        var id    = $('#eAdminId').val();
        var name  = $.trim($('#eName').val());
        var email = $.trim($('#eEmail').val());
        if (!name) return alert('Name is required.');

        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        post('superadmin/admins/update_profile', { admin_id: id, name: name, email: email }, function(r){
            $btn.prop('disabled', false).html('Save');
            if (r.status === 'success') {
                $('#editModal').modal('hide');
                loadAdmins();
            } else {
                alert(r.message || 'Failed.');
            }
        });
    });

    // ── Reset Password ──
    window.resetPw = function(id, name) {
        $('#rAdminId').val(id);
        $('#rAdminLabel').text(id);
        $('#rPassword').val('');
        $('#rConfirm').val('');
        $('#resetModal').modal('show');
    };

    $('#btnResetPw').click(function(){
        var id = $('#rAdminId').val();
        var pw = $('#rPassword').val();
        var cf = $('#rConfirm').val();
        if (!pw) return alert('Enter a new password.');
        if (pw !== cf) return alert('Passwords do not match.');

        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        post('superadmin/admins/reset_password', { admin_id: id, new_password: pw }, function(r){
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
    window.toggleStatus = function(id) {
        if (!confirm('Toggle status for "' + id + '"?')) return;
        post('superadmin/admins/toggle_status', { admin_id: id }, function(r){
            if (r.status === 'success') loadAdmins();
            else alert(r.message || 'Failed.');
        });
    };

    // ── Delete ──
    window.deleteAdmin = function(id) {
        if (!confirm('Are you sure you want to permanently delete "' + id + '"?\nThis cannot be undone.')) return;
        post('superadmin/admins/delete', { admin_id: id }, function(r){
            if (r.status === 'success') loadAdmins();
            else alert(r.message || 'Failed.');
        });
    };
});
</script>
