<?php
$p  = $profile ?? [];
$nm = htmlspecialchars($p['name']     ?? 'Admin', ENT_QUOTES, 'UTF-8');
$rl = htmlspecialchars($p['role']     ?? '',      ENT_QUOTES, 'UTF-8');
$em = htmlspecialchars($p['email']    ?? '',      ENT_QUOTES, 'UTF-8');
$ph = htmlspecialchars($p['phone']    ?? '',      ENT_QUOTES, 'UTF-8');
$gn = htmlspecialchars($p['gender']   ?? '',      ENT_QUOTES, 'UTF-8');
$id = htmlspecialchars($p['admin_id'] ?? '',      ENT_QUOTES, 'UTF-8');
$st = $p['status'] ?? 'Active';
$stOk = strtolower($st) === 'active';
$initials = '';
foreach (explode(' ', $p['name'] ?? 'A') as $w) { if ($w !== '') $initials .= mb_strtoupper(mb_substr($w,0,1)); }
$initials = mb_substr($initials, 0, 2);
?>

<style>
.ap-hero{
    background:linear-gradient(135deg, var(--gold) 0%, var(--gold2) 60%, rgba(20,184,166,.7) 100%);
    border-radius:16px;
    padding:0;
    overflow:hidden;
    position:relative;
    margin-bottom:24px;
}
.ap-hero::before{
    content:'';position:absolute;inset:0;
    background:url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M30 0L60 30L30 60L0 30z' fill='rgba(255,255,255,0.03)'/%3E%3C/svg%3E");
    pointer-events:none;
}
.ap-hero-inner{
    display:flex;align-items:center;gap:24px;padding:32px 36px;position:relative;z-index:1;
    flex-wrap:wrap;
}
.ap-avatar{
    width:90px;height:90px;border-radius:50%;
    background:rgba(255,255,255,.18);
    border:3px solid rgba(255,255,255,.35);
    display:flex;align-items:center;justify-content:center;
    font-size:32px;font-weight:800;color:#fff;
    font-family:var(--font-b,'DM Sans',sans-serif);
    letter-spacing:1px;
    flex-shrink:0;
    text-shadow:0 2px 8px rgba(0,0,0,.15);
}
.ap-hero-info h2{margin:0 0 2px;color:#fff;font-weight:800;font-size:22px;letter-spacing:-.3px;}
.ap-hero-info .ap-role{
    display:inline-block;
    padding:3px 12px;border-radius:20px;
    background:rgba(255,255,255,.18);
    color:rgba(255,255,255,.92);font-size:12px;font-weight:600;
    margin-top:4px;
    backdrop-filter:blur(4px);
}
.ap-hero-meta{
    margin-left:auto;display:flex;gap:24px;flex-wrap:wrap;
}
.ap-hero-meta .ap-meta-item{text-align:center;}
.ap-hero-meta .ap-meta-val{
    font-size:13px;font-weight:600;color:#fff;display:block;
}
.ap-hero-meta .ap-meta-lbl{
    font-size:9.5px;text-transform:uppercase;letter-spacing:.8px;
    color:rgba(255,255,255,.6);font-family:var(--font-m,monospace);
}
.ap-card{
    background:var(--bg2);border:1px solid var(--border);border-radius:14px;
    padding:28px 30px;margin-bottom:20px;
    transition:box-shadow .2s var(--ease,ease);
}
.ap-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.06);}
.ap-card-title{
    display:flex;align-items:center;gap:10px;
    margin-bottom:22px;padding-bottom:14px;
    border-bottom:1px solid var(--border);
}
.ap-card-title i{
    width:32px;height:32px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;flex-shrink:0;
}
.ap-card-title h4{margin:0;font-weight:700;color:var(--t1);font-size:15px;}
.ap-card-title small{font-size:11.5px;color:var(--t3);font-weight:400;display:block;margin-top:1px;}
.ap-label{
    font-size:11.5px;font-weight:600;color:var(--t2);margin-bottom:5px;display:block;
}
.ap-label span{color:#ef4444;}
.ap-input{
    height:42px;border-radius:10px;
    border:1px solid var(--border);
    background:var(--bg);color:var(--t1);
    padding:0 14px;font-size:13px;
    transition:border-color .2s, box-shadow .2s;
    width:100%;
}
.ap-input:focus{
    border-color:var(--gold);
    box-shadow:0 0 0 3px var(--gold-ring,rgba(15,118,110,.15));
    outline:none;
}
select.ap-input{padding-right:30px;-webkit-appearance:none;appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 12px center;
}
.ap-grid{display:grid;gap:16px;}
.ap-grid-2{grid-template-columns:1fr 1fr;}
.ap-grid-3{grid-template-columns:1fr 1fr 1fr;}
@media(max-width:768px){
    .ap-grid-2,.ap-grid-3{grid-template-columns:1fr;}
    .ap-hero-inner{padding:24px 20px;}
    .ap-hero-meta{margin-left:0;width:100%;}
    .ap-card{padding:20px;}
}
.ap-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:9px 20px;border-radius:10px;border:none;
    font-size:13px;font-weight:600;cursor:pointer;
    transition:all .2s var(--ease,ease);
}
.ap-btn-primary{background:var(--gold);color:#fff;}
.ap-btn-primary:hover{background:var(--gold2);color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px var(--gold-glow,rgba(15,118,110,.25));}
.ap-btn-outline{background:transparent;color:var(--gold);border:1.5px solid var(--gold);}
.ap-btn-outline:hover{background:var(--gold-dim);color:var(--gold);}
.ap-stat-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;
}
</style>

<section class="content" style="padding:20px 24px;">

    <!-- ═══ HERO ═══ -->
    <div class="ap-hero">
        <div class="ap-hero-inner">
            <div class="ap-avatar"><?= $initials ?></div>
            <div class="ap-hero-info">
                <h2 id="profileDisplayName"><?= $nm ?></h2>
                <span class="ap-role"><i class="fa fa-shield" style="margin-right:4px;"></i><?= $rl ?></span>
            </div>
            <div class="ap-hero-meta">
                <div class="ap-meta-item">
                    <span class="ap-meta-val"><code style="color:#fff;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:6px;font-size:12px;"><?= $id ?></code></span>
                    <span class="ap-meta-lbl">Admin ID</span>
                </div>
                <div class="ap-meta-item">
                    <span class="ap-meta-val">
                        <span class="ap-stat-badge" style="background:<?= $stOk ? 'rgba(34,197,94,.2)' : 'rgba(239,68,68,.2)' ?>;color:<?= $stOk ? '#22c55e' : '#ef4444' ?>;">
                            <i class="fa fa-circle" style="font-size:7px;"></i><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </span>
                    <span class="ap-meta-lbl">Status</span>
                </div>
                <div class="ap-meta-item">
                    <span class="ap-meta-val" id="profileDisplayEmail" style="font-size:12px;"><?= $em ?: '—' ?></span>
                    <span class="ap-meta-lbl">Email</span>
                </div>
                <div class="ap-meta-item">
                    <span class="ap-meta-val" id="profileDisplayPhone" style="font-size:12px;"><?= $ph ?: '—' ?></span>
                    <span class="ap-meta-lbl">Phone</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ CARDS ═══ -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        <!-- Edit Profile -->
        <div class="ap-card" style="grid-column:1/2;">
            <div class="ap-card-title">
                <i class="fa fa-edit" style="background:var(--gold-dim);color:var(--gold);"></i>
                <div><h4>Edit Profile</h4><small>Update your personal information</small></div>
            </div>
            <form id="profileForm">
                <input type="hidden" name="action" value="update_profile">
                <div class="ap-grid ap-grid-2" style="margin-bottom:16px;">
                    <div>
                        <label class="ap-label">Full Name <span>*</span></label>
                        <input type="text" class="ap-input" name="name" id="profName" value="<?= $nm ?>" required>
                    </div>
                    <div>
                        <label class="ap-label">Email</label>
                        <input type="email" class="ap-input" name="email" id="profEmail" value="<?= $em ?>">
                    </div>
                    <div>
                        <label class="ap-label">Phone</label>
                        <input type="tel" class="ap-input" name="phone" id="profPhone" value="<?= $ph ?>">
                    </div>
                    <div>
                        <label class="ap-label">Gender</label>
                        <select class="ap-input" name="gender">
                            <option value="">-- Select --</option>
                            <option value="Male"   <?= $gn === 'Male'   ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $gn === 'Female' ? 'selected' : '' ?>>Female</option>
                            <option value="Other"  <?= $gn === 'Other'  ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="ap-btn ap-btn-primary" id="profSaveBtn"><i class="fa fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>

        <!-- Change Password -->
        <div class="ap-card" style="grid-column:2/3;">
            <div class="ap-card-title">
                <i class="fa fa-lock" style="background:rgba(239,68,68,.08);color:#ef4444;"></i>
                <div><h4>Change Password</h4><small>Keep your account secure</small></div>
            </div>
            <form id="passwordForm">
                <input type="hidden" name="action" value="change_password">
                <div class="ap-grid" style="gap:14px;margin-bottom:16px;">
                    <div>
                        <label class="ap-label">Current Password <span>*</span></label>
                        <input type="password" class="ap-input" name="current_password" required autocomplete="current-password">
                    </div>
                    <div>
                        <label class="ap-label">New Password <span>*</span></label>
                        <input type="password" class="ap-input" name="new_password" id="newPwd" required minlength="8" maxlength="72" autocomplete="new-password">
                        <div id="pwdStrength" style="margin-top:6px;display:none;"></div>
                    </div>
                    <div>
                        <label class="ap-label">Confirm New Password <span>*</span></label>
                        <input type="password" class="ap-input" name="confirm_password" id="confirmPwd" required minlength="8" maxlength="72" autocomplete="new-password">
                        <div id="pwdMatch" style="margin-top:6px;display:none;"></div>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="ap-btn ap-btn-outline" id="pwdSaveBtn" style="border-color:#ef4444;color:#ef4444;"><i class="fa fa-lock"></i> Change Password</button>
                </div>
            </form>
        </div>

        <!-- Role & Permissions -->
        <div class="ap-card" style="grid-column:1/-1;">
            <div class="ap-card-title" style="margin-bottom:14px;padding-bottom:12px;">
                <i class="fa fa-shield" style="background:rgba(139,92,246,.08);color:#8b5cf6;"></i>
                <div><h4>Role &amp; Permissions</h4><small>Your access level in this school</small></div>
            </div>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:10px;padding:12px 20px;background:var(--bg3,var(--bg));border-radius:10px;border:1px solid var(--border);">
                    <i class="fa fa-user-circle" style="font-size:22px;color:var(--gold);"></i>
                    <div>
                        <div style="font-size:10px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);letter-spacing:.5px;">Current Role</div>
                        <div style="font-size:15px;font-weight:700;color:var(--t1);"><?= $rl ?></div>
                    </div>
                </div>
                <div style="font-size:13px;color:var(--t3);line-height:1.5;">
                    Role and permissions are managed by the school administrator.<br>
                    Contact your admin if you need additional access.
                </div>
            </div>
        </div>

    </div>

</section>

<script>
$(function(){

    $('#profileForm').on('submit', function(e){
        e.preventDefault();
        var $b = $('#profSaveBtn').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        $.post(BASE_URL+'admin/profile', $(this).serialize(), function(r){
            $b.prop('disabled',false).html('<i class="fa fa-save"></i> Save Changes');
            if(r.status==='success'){
                saToast ? saToast(r.message,'success') : alert(r.message);
                $('#profileDisplayName').text($('#profName').val());
                $('#profileDisplayEmail').text($('#profEmail').val() || '—');
                $('#profileDisplayPhone').text($('#profPhone').val() || '—');
            } else {
                saToast ? saToast(r.message,'error') : alert(r.message);
            }
        },'json').fail(function(){
            $b.prop('disabled',false).html('<i class="fa fa-save"></i> Save Changes');
            saToast ? saToast('Network error','error') : alert('Network error');
        });
    });

    // Password strength
    $('#newPwd').on('input', function(){
        var v = $(this).val(), el = $('#pwdStrength');
        if(!v){ el.hide(); return; }
        el.show();
        var score = 0;
        if(v.length >= 8) score++;
        if(v.length >= 12) score++;
        if(/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if(/[0-9]/.test(v)) score++;
        if(/[^A-Za-z0-9]/.test(v)) score++;
        var labels = ['','Weak','Fair','Good','Strong','Excellent'];
        var colors = ['','#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
        var pct = Math.min(100, score * 20);
        el.html('<div style="display:flex;align-items:center;gap:8px;">'
            +'<div style="flex:1;height:4px;background:var(--border);border-radius:2px;overflow:hidden;">'
            +'<div style="width:'+pct+'%;height:100%;background:'+colors[score]+';border-radius:2px;transition:width .3s;"></div></div>'
            +'<span style="font-size:11px;font-weight:600;color:'+colors[score]+';">'+labels[score]+'</span></div>');
        checkMatch();
    });

    $('#confirmPwd').on('input', checkMatch);

    function checkMatch(){
        var n = $('#newPwd').val(), c = $('#confirmPwd').val(), el = $('#pwdMatch');
        if(!c){ el.hide(); return; }
        el.show();
        if(n === c) el.html('<span style="font-size:11px;color:#22c55e;"><i class="fa fa-check"></i> Passwords match</span>');
        else el.html('<span style="font-size:11px;color:#ef4444;"><i class="fa fa-times"></i> Passwords do not match</span>');
    }

    $('#passwordForm').on('submit', function(e){
        e.preventDefault();
        if($('#newPwd').val() !== $('#confirmPwd').val()){
            saToast ? saToast('Passwords do not match','error') : alert('Passwords do not match');
            return;
        }
        var $b = $('#pwdSaveBtn').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Changing...');
        $.post(BASE_URL+'admin/profile', $(this).serialize(), function(r){
            $b.prop('disabled',false).html('<i class="fa fa-lock"></i> Change Password');
            if(r.status==='success'){
                saToast ? saToast(r.message,'success') : alert(r.message);
                $('#passwordForm')[0].reset();
                $('#pwdStrength,#pwdMatch').hide();
            } else {
                saToast ? saToast(r.message,'error') : alert(r.message);
            }
        },'json').fail(function(){
            $b.prop('disabled',false).html('<i class="fa fa-lock"></i> Change Password');
            saToast ? saToast('Network error','error') : alert('Network error');
        });
    });

});
</script>
