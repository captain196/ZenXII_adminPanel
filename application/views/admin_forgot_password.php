<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
<meta name="csrf-name"  content="<?= $this->security->get_csrf_token_name() ?>">
<title>Password Recovery — ZenXii</title>
<link rel="icon" type="image/png" href="<?= base_url('Designs/favicon.png?v=2') ?>">
<link rel="apple-touch-icon" href="<?= base_url('Designs/favicon.png?v=2') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Satoshi:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --brand:     #0d9488;
    --brand2:    #0f766e;
    --brand3:    #2dd4bf;
    --brand-dim: rgba(13,148,136,.12);
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
    --wa:        #25D366;
    --wa-bg:     rgba(37,211,102,.12);
}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}

.fp-card{background:var(--surface);border:1px solid var(--border2);border-radius:16px;padding:32px;width:100%;max-width:440px;box-shadow:0 32px 80px rgba(0,0,0,.6);}
.fp-logo{text-align:center;margin-bottom:22px;}
.fp-logo img{width:60px;height:60px;object-fit:contain;display:block;margin:0 auto 10px;}
.fp-logo h1{font-family:var(--display);font-size:21px;color:var(--brand);}
.fp-logo p{font-size:12px;color:var(--muted);margin-top:4px;}
.fp-step{display:none;}
.fp-step.active{display:block;}
.fp-label{font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;display:block;}
.fp-input{width:100%;padding:12px 14px;background:var(--input-bg);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:14px;font-family:var(--sans);outline:none;transition:border var(--ease);text-transform:uppercase;}
.fp-input:focus{border-color:var(--brand);background:var(--input-foc);}
.fp-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--brand),var(--brand2));border:none;border-radius:10px;color:#fff;font-weight:700;font-size:14px;cursor:pointer;margin-top:16px;transition:opacity var(--ease);font-family:var(--sans);}
.fp-btn:hover{opacity:.9;}
.fp-btn:disabled{opacity:.5;cursor:not-allowed;}
.fp-btn.loading .fp-btn-text{display:none;}
.fp-btn.loading .fp-btn-spin{display:inline-block;}
.fp-btn-spin{display:none;width:18px;height:18px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
.fp-alert{padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:14px;display:none;line-height:1.5;}
.fp-alert.error{background:var(--red-bg);border:1px solid var(--red-brd);color:var(--red);}
.fp-alert.warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);color:#fbbf24;}
.fp-info{font-size:12px;color:var(--muted);text-align:center;margin-top:12px;line-height:1.6;}
.fp-field{margin-bottom:4px;}
.fp-back{text-align:center;margin-top:20px;}
.fp-back a{color:var(--muted);font-size:12px;text-decoration:none;transition:color var(--ease);}
.fp-back a:hover{color:var(--brand);}
.fp-back a i{margin-right:4px;}

/* ── Contact card ── */
.cc{border:1px solid var(--border2);border-radius:14px;padding:16px;background:rgba(15,30,15,.5);}
.cc-head{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.cc-head i{color:var(--brand);font-size:18px;}
.cc-head .cc-title{font-size:15px;font-weight:600;color:var(--text);}
.cc-sub{font-size:11.5px;color:var(--muted);margin:-6px 0 12px 28px;}
.cc-div{height:1px;background:var(--border);margin:0 0 14px;}
.cc-row{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;}
.cc-row:last-child{margin-bottom:0;}
.cc-row > i.lead{color:var(--text2);font-size:15px;width:18px;text-align:center;margin-top:2px;}
.cc-meta{flex:1;min-width:0;}
.cc-meta .cc-k{font-size:11px;color:var(--muted);letter-spacing:.3px;}
.cc-meta .cc-v{font-size:13px;color:var(--text);word-break:break-word;margin-top:2px;}
.cc-actions{display:flex;gap:10px;margin:10px 0 0 30px;}
.cc-act{width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;background:var(--brand-dim);color:var(--brand3);font-size:14px;text-decoration:none;transition:filter var(--ease);}
.cc-act:hover{filter:brightness(1.25);}
.cc-act.wa{background:var(--wa-bg);color:var(--wa);}
</style>
</head>
<body>

<div class="fp-card">
    <div class="fp-logo">
        <img src="<?= base_url('Designs/zenxii_logo_2.png') ?>" alt="ZenXii">
        <h1>ZenXii</h1>
        <p>Password Recovery</p>
    </div>

    <div class="fp-alert" id="fpAlert"><span id="fpAlertMsg"></span></div>

    <!-- STEP 1: Enter ID -->
    <div class="fp-step active" id="step1">
        <div class="fp-field">
            <label class="fp-label"><i class="fas fa-id-badge" style="margin-right:4px;"></i>Your Login ID</label>
            <input type="text" class="fp-input" id="fpAdminId" placeholder="e.g. SSA0001 / ADM0001" maxlength="32" autofocus>
        </div>
        <button class="fp-btn" id="btnFind" onclick="findContact()">
            <span class="fp-btn-text"><i class="fas fa-life-ring" style="margin-right:6px;"></i>Find recovery contact</span>
            <span class="fp-btn-spin"></span>
        </button>
        <div class="fp-info">Passwords are reset by your administrator. Enter your ID to see who to contact.</div>
    </div>

    <!-- STEP 2: Contact card -->
    <div class="fp-step" id="step2">
        <div id="ccHost"></div>
        <button class="fp-btn" style="background:none;border:1px solid var(--border2);color:var(--text2);" onclick="resetFlow()">
            <i class="fas fa-rotate-left" style="margin-right:6px;"></i>Look up a different ID
        </button>
    </div>

    <div class="fp-back" id="fpBackLink">
        <a href="<?= base_url('admin_login') ?>"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function showStep(n){
    document.querySelectorAll('.fp-step').forEach(function(s){s.classList.remove('active');});
    document.getElementById('step'+n).classList.add('active');
    hideAlert();
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
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

function postJson(url,data,cb){
    var fd=new FormData();
    fd.append(csrfName,csrfToken);
    for(var k in data) fd.append(k,data[k]);
    fetch(BASE+url,{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(r){ if(r[csrfName]) csrfToken=r[csrfName]; cb(r); })
        .catch(function(){cb({status:'error',message:'Server error. Please try again.'});});
}

function copyText(txt,btn){
    var done=function(){ var i=btn.querySelector('i'); var old=i.className; i.className='fas fa-check'; setTimeout(function(){i.className=old;},1200); };
    if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(txt).then(done).catch(done); }
    else { var t=document.createElement('textarea'); t.value=txt; document.body.appendChild(t); t.select(); try{document.execCommand('copy');}catch(e){} document.body.removeChild(t); done(); }
}

function digits(s){return String(s||'').replace(/[^0-9]/g,'');}

function renderCard(r){
    var rows='';
    // Name
    if(r.name){
        rows+='<div class="cc-row"><i class="fas fa-user lead"></i><div class="cc-meta">'+
              '<div class="cc-k">Contact</div><div class="cc-v">'+esc(r.name)+'</div></div></div>';
    }
    // Phone + actions
    if(r.number){
        var d=digits(r.number);
        rows+='<div class="cc-row" style="flex-direction:column;align-items:stretch;">'+
              '<div style="display:flex;gap:12px;"><i class="fas fa-phone lead"></i><div class="cc-meta">'+
              '<div class="cc-k">Phone</div><div class="cc-v">'+esc(r.number)+'</div></div></div>'+
              '<div class="cc-actions">'+
                '<a class="cc-act" title="Call" href="tel:'+esc(r.number)+'"><i class="fas fa-phone"></i></a>'+
                (d?'<a class="cc-act wa" title="WhatsApp" target="_blank" rel="noopener" href="https://wa.me/'+d+'"><i class="fab fa-whatsapp"></i></a>':'')+
                '<button type="button" class="cc-act" title="Copy" onclick="copyText(\''+esc(r.number).replace(/'/g,"\\'")+'\',this)"><i class="fas fa-copy"></i></button>'+
              '</div></div>';
    }
    // Email + actions
    if(r.email){
        rows+='<div class="cc-row" style="flex-direction:column;align-items:stretch;">'+
              '<div style="display:flex;gap:12px;"><i class="fas fa-envelope lead"></i><div class="cc-meta">'+
              '<div class="cc-k">Email</div><div class="cc-v">'+esc(r.email)+'</div></div></div>'+
              '<div class="cc-actions">'+
                '<a class="cc-act" title="Email" href="mailto:'+esc(r.email)+'?subject=Password%20reset%20request"><i class="fas fa-envelope"></i></a>'+
                '<button type="button" class="cc-act" title="Copy" onclick="copyText(\''+esc(r.email).replace(/'/g,"\\'")+'\',this)"><i class="fas fa-copy"></i></button>'+
              '</div></div>';
    }
    var sub = r.subtitle ? '<div class="cc-sub">'+esc(r.subtitle)+'</div>' : '';
    document.getElementById('ccHost').innerHTML =
        '<div class="cc">'+
            '<div class="cc-head"><i class="fas fa-building-shield"></i><span class="cc-title">'+esc(r.title||'Recovery contact')+'</span></div>'+
            sub+
            '<div class="cc-div"></div>'+
            rows+
        '</div>';
}

function findContact(){
    var id=document.getElementById('fpAdminId').value.trim();
    if(!id){showAlert('Please enter your ID.');return;}
    btnLoad('btnFind',true);
    postJson('admin_login/recovery_contact',{admin_id:id},function(r){
        btnLoad('btnFind',false);
        if(r.status!=='success'){ showAlert(r.message||'Lookup failed.'); return; }
        if(r.found===false){
            document.getElementById('ccHost').innerHTML =
                '<div class="fp-alert warn" style="display:block;">'+esc(r.message||'No recovery contact is on file.')+'</div>';
            showStep(2);
            return;
        }
        renderCard(r);
        showStep(2);
    });
}

function resetFlow(){
    document.getElementById('ccHost').innerHTML='';
    showStep(1);
    document.getElementById('fpAdminId').focus();
}

document.getElementById('fpAdminId').addEventListener('keydown',function(e){ if(e.key==='Enter') findContact(); });
</script>
</body>
</html>
