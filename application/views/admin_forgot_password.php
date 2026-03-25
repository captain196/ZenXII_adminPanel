<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
<meta name="csrf-name"  content="<?= $this->security->get_csrf_token_name() ?>">
<title>Reset Password — GraderIQ</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Satoshi:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --brand:     #0d9488;
    --brand2:    #0f766e;
    --brand3:    #2dd4bf;
    --brand-dim: rgba(13,148,136,.12);
    --forest:    #1a2e1a;
    --forest2:   #0f1f10;
    --sans:      'Satoshi','Plus Jakarta Sans',system-ui,sans-serif;
    --mono:      'JetBrains Mono',ui-monospace,monospace;
    --display:   'Clash Display','Satoshi',system-ui,sans-serif;
    --ease:      .22s cubic-bezier(.4,0,.2,1);

    --bg:        #080e08;
    --surface:   rgba(20,35,20,.92);
    --border:    rgba(45,212,191,.12);
    --border2:   rgba(45,212,191,.22);
    --text:      #e4f0e4;
    --text2:     #8ab88a;
    --muted:     #4a6a4a;
    --input-bg:  rgba(20,40,20,.8);
    --input-foc: rgba(25,50,25,.95);
    --red:       #f87171;
    --red-bg:    rgba(248,113,113,.07);
    --red-brd:   rgba(248,113,113,.2);
    --green-ok:  #4ade80;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;display:flex;align-items:center;justify-content:center;}

.fp-card{background:var(--surface);border:1px solid var(--border2);border-radius:16px;padding:36px;width:100%;max-width:420px;box-shadow:0 32px 80px rgba(0,0,0,.6);}
.fp-logo{text-align:center;margin-bottom:24px;}
.fp-logo h1{font-family:var(--display);font-size:22px;color:var(--brand);}
.fp-logo p{font-size:12px;color:var(--muted);margin-top:4px;}
.fp-step{display:none;}
.fp-step.active{display:block;}
.fp-label{font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;display:block;}
.fp-input{width:100%;padding:12px 14px;background:var(--input-bg);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:14px;font-family:var(--sans);outline:none;transition:border var(--ease);}
.fp-input:focus{border-color:var(--brand);background:var(--input-foc);}
.fp-input.otp{text-align:center;font-size:24px;letter-spacing:12px;font-family:var(--mono);font-weight:700;}
.fp-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--brand),var(--brand2));border:none;border-radius:10px;color:#fff;font-weight:700;font-size:14px;cursor:pointer;margin-top:16px;transition:opacity var(--ease);font-family:var(--sans);}
.fp-btn:hover{opacity:.9;}
.fp-btn:disabled{opacity:.5;cursor:not-allowed;}
.fp-btn.loading .fp-btn-text{display:none;}
.fp-btn.loading .fp-btn-spin{display:inline-block;}
.fp-btn-spin{display:none;width:18px;height:18px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
.fp-alert{padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:14px;display:none;line-height:1.5;}
.fp-alert.error{background:var(--red-bg);border:1px solid var(--red-brd);color:var(--red);}
.fp-alert.success{background:rgba(74,222,128,.07);border:1px solid rgba(74,222,128,.2);color:var(--green-ok);}
.fp-info{font-size:12px;color:var(--muted);text-align:center;margin-top:12px;line-height:1.6;}
.fp-info strong{color:var(--brand3);}
.fp-back{text-align:center;margin-top:20px;}
.fp-back a{color:var(--muted);font-size:12px;text-decoration:none;transition:color var(--ease);}
.fp-back a:hover{color:var(--brand);}
.fp-back a i{margin-right:4px;}
.fp-field{margin-bottom:14px;}
.fp-pw-wrap{position:relative;}
.fp-pw-wrap .fp-eye{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;}
.fp-success-icon{text-align:center;padding:20px 0;}
.fp-success-icon i{font-size:48px;color:var(--green-ok);}
.fp-success-icon p{margin-top:12px;font-size:14px;color:var(--text);}
.fp-timer{font-size:11px;color:var(--muted);text-align:center;margin-top:8px;}
.fp-resend{background:none;border:none;color:var(--brand);cursor:pointer;font-size:12px;font-weight:600;text-decoration:underline;margin-top:8px;display:block;text-align:center;}
.fp-resend:disabled{color:var(--muted);cursor:not-allowed;text-decoration:none;}
</style>
</head>
<body>

<div class="fp-card">
    <div class="fp-logo">
        <h1><i class="fas fa-shield-halved" style="margin-right:8px;"></i>GraderIQ</h1>
        <p>Password Reset</p>
    </div>

    <div class="fp-alert" id="fpAlert"><span id="fpAlertMsg"></span></div>

    <!-- STEP 1: Enter Admin ID -->
    <div class="fp-step active" id="step1">
        <div class="fp-field">
            <label class="fp-label"><i class="fas fa-id-badge" style="margin-right:4px;"></i>Admin ID</label>
            <input type="text" class="fp-input" id="fpAdminId" placeholder="e.g. ADM0001" maxlength="32" autofocus>
        </div>
        <button class="fp-btn" id="btnSendOtp" onclick="sendOtp()">
            <span class="fp-btn-text"><i class="fas fa-paper-plane" style="margin-right:6px;"></i>Send OTP</span>
            <span class="fp-btn-spin"></span>
        </button>
        <div class="fp-info">Enter your Admin ID. An OTP will be sent to your registered email.</div>
    </div>

    <!-- STEP 2: Enter OTP -->
    <div class="fp-step" id="step2">
        <div class="fp-info" style="margin-bottom:14px;">
            OTP sent to <strong id="fpEmailMasked"></strong>
        </div>
        <div class="fp-field">
            <label class="fp-label"><i class="fas fa-key" style="margin-right:4px;"></i>Enter 6-digit OTP</label>
            <input type="text" class="fp-input otp" id="fpOtp" placeholder="------" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
        </div>
        <button class="fp-btn" id="btnVerifyOtp" onclick="verifyOtp()">
            <span class="fp-btn-text"><i class="fas fa-check-circle" style="margin-right:6px;"></i>Verify OTP</span>
            <span class="fp-btn-spin"></span>
        </button>
        <div class="fp-timer" id="fpTimer"></div>
        <button class="fp-resend" id="btnResend" onclick="sendOtp()" disabled>Resend OTP</button>
    </div>

    <!-- STEP 3: New Password -->
    <div class="fp-step" id="step3">
        <div class="fp-field">
            <label class="fp-label"><i class="fas fa-lock" style="margin-right:4px;"></i>New Password</label>
            <div class="fp-pw-wrap">
                <input type="password" class="fp-input" id="fpNewPw" placeholder="Min 8 characters" minlength="8">
                <button type="button" class="fp-eye" onclick="togglePw('fpNewPw',this)"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <div class="fp-field">
            <label class="fp-label"><i class="fas fa-lock" style="margin-right:4px;"></i>Confirm Password</label>
            <div class="fp-pw-wrap">
                <input type="password" class="fp-input" id="fpConfirmPw" placeholder="Re-enter password" minlength="8">
                <button type="button" class="fp-eye" onclick="togglePw('fpConfirmPw',this)"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <button class="fp-btn" id="btnResetPw" onclick="resetPassword()">
            <span class="fp-btn-text"><i class="fas fa-shield-halved" style="margin-right:6px;"></i>Reset Password</span>
            <span class="fp-btn-spin"></span>
        </button>
    </div>

    <!-- STEP 4: Success -->
    <div class="fp-step" id="step4">
        <div class="fp-success-icon">
            <i class="fas fa-circle-check"></i>
            <p>Password reset successfully!</p>
        </div>
        <a href="<?= base_url('admin_login') ?>" class="fp-btn" style="display:block;text-align:center;text-decoration:none;">
            <i class="fas fa-arrow-right-to-bracket" style="margin-right:6px;"></i>Back to Login
        </a>
    </div>

    <div class="fp-back" id="fpBackLink">
        <a href="<?= base_url('admin_login') ?>"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var adminId = '';
var resetToken = '';
var timerInterval = null;

function showStep(n){
    document.querySelectorAll('.fp-step').forEach(function(s){s.classList.remove('active');});
    document.getElementById('step'+n).classList.add('active');
    hideAlert();
    if(n===4) document.getElementById('fpBackLink').style.display='none';
}

function showAlert(msg,type){
    var el=document.getElementById('fpAlert');
    el.className='fp-alert '+(type||'error');
    document.getElementById('fpAlertMsg').textContent=msg;
    el.style.display='block';
}
function hideAlert(){document.getElementById('fpAlert').style.display='none';}

function btnLoad(id,loading){
    var b=document.getElementById(id);
    if(loading){b.classList.add('loading');b.disabled=true;}
    else{b.classList.remove('loading');b.disabled=false;}
}

function postJson(url,data,cb){
    var fd=new FormData();
    fd.append(csrfName,csrfToken);
    for(var k in data) fd.append(k,data[k]);
    fetch(BASE+url,{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(r){
            if(r[csrfName]) csrfToken=r[csrfName];
            cb(r);
        })
        .catch(function(e){cb({status:'error',message:'Server error. Please try again.'});});
}

function sendOtp(){
    adminId = document.getElementById('fpAdminId').value.trim();
    if(!adminId){showAlert('Please enter your Admin ID.');return;}

    btnLoad('btnSendOtp',true);
    postJson('admin_login/send_otp',{admin_id:adminId},function(r){
        btnLoad('btnSendOtp',false);
        if(r.status==='success'){
            document.getElementById('fpEmailMasked').textContent=r.email_masked||'your email';
            showStep(2);
            startTimer();
        } else {
            showAlert(r.message||'Failed to send OTP.');
        }
    });
}

function verifyOtp(){
    var otp=document.getElementById('fpOtp').value.trim();
    if(!otp||otp.length!==6){showAlert('Please enter the 6-digit OTP.');return;}

    btnLoad('btnVerifyOtp',true);
    postJson('admin_login/verify_otp',{admin_id:adminId,otp:otp},function(r){
        btnLoad('btnVerifyOtp',false);
        if(r.status==='success'){
            resetToken=r.resetToken;
            showStep(3);
        } else {
            showAlert(r.message||'Invalid OTP.');
        }
    });
}

function resetPassword(){
    var pw=document.getElementById('fpNewPw').value;
    var cf=document.getElementById('fpConfirmPw').value;
    if(!pw||pw.length<8){showAlert('Password must be at least 8 characters.');return;}
    if(pw!==cf){showAlert('Passwords do not match.');return;}

    btnLoad('btnResetPw',true);
    postJson('admin_login/reset_password',{admin_id:adminId,reset_token:resetToken,new_password:pw},function(r){
        btnLoad('btnResetPw',false);
        if(r.status==='success'){
            showStep(4);
        } else {
            showAlert(r.message||'Reset failed.');
        }
    });
}

function startTimer(){
    var sec=60;
    var btn=document.getElementById('btnResend');
    var el=document.getElementById('fpTimer');
    btn.disabled=true;
    clearInterval(timerInterval);
    timerInterval=setInterval(function(){
        sec--;
        el.textContent='Resend available in '+sec+'s';
        if(sec<=0){
            clearInterval(timerInterval);
            el.textContent='';
            btn.disabled=false;
        }
    },1000);
}

function togglePw(id,btn){
    var inp=document.getElementById(id);
    var show=inp.type==='password';
    inp.type=show?'text':'password';
    btn.querySelector('i').className=show?'fas fa-eye-slash':'fas fa-eye';
}

document.getElementById('fpOtp').addEventListener('input',function(){
    this.value=this.value.replace(/[^0-9]/g,'');
});
</script>
</body>
</html>
