<?php $plans = $plans ?? []; ?>

<section class="content-header">
    <h1><i class="fa fa-plus-circle" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Onboard New School</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('superadmin/schools') ?>">School Management</a></li>
        <li class="active">Onboard School</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">
<div class="row">
<div class="col-md-8">

<div class="box box-primary">
    <div class="box-header">
        <i class="fa fa-building" style="color:var(--sa3);margin-right:8px;"></i>
        <span class="box-title">School Onboarding Wizard</span>
    </div>
    <div class="box-body">

        <div id="onboardAlert" style="display:none;" class="alert"></div>

        <form id="onboardForm">

            <!-- Step indicator -->
            <div style="display:flex;gap:0;margin-bottom:28px;border-radius:10px;overflow:hidden;border:1px solid var(--border);">
                <?php foreach(['School Profile','Admin Account','Subscription','Review'] as $i => $step): ?>
                <div class="sa-step" data-step="<?= $i+1 ?>" style="flex:1;padding:10px 12px;text-align:center;cursor:pointer;font-size:12px;font-family:var(--font-m);font-weight:600;border-right:<?= $i<3?'1px solid var(--border)':'none' ?>;background:<?= $i===0?'var(--sa-dim)':'transparent' ?>;color:<?= $i===0?'var(--sa3)':'var(--t3)' ?>;">
                    <span style="display:block;font-size:9px;opacity:.6;margin-bottom:2px;"><?= sprintf('%02d', $i+1) ?></span>
                    <?= $step ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- Step 1: School Profile                                         -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="step1">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>School Name <span style="color:var(--rose);">*</span></label>
                            <input type="text" class="form-control" id="schoolName" name="school_name"
                                   placeholder="e.g. Sunrise Public School" required>
                            <small id="nameAvailMsg" style="font-size:11px;display:block;margin-top:4px;"></small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" class="form-control" name="city" placeholder="e.g. New Delhi">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Street Address</label>
                            <input type="text" class="form-control" name="street"
                                   placeholder="e.g. 12, Main Road, Sector 5">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Contact Email <span style="color:var(--rose);">*</span></label>
                            <input type="email" class="form-control" id="schoolEmail" name="email"
                                   placeholder="contact@school.com" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" class="form-control" name="phone" placeholder="+91 98765 43210">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-7">
                        <div class="form-group">
                            <label>Logo URL <span style="color:var(--t3);font-size:11px;font-weight:400;">(optional)</span></label>
                            <input type="url" class="form-control" name="logo_url" id="logoUrlInput"
                                   placeholder="https://example.com/logo.png">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group">
                            <label>— or upload an image
                                <span style="color:var(--t3);font-size:11px;font-weight:400;">(uploads automatically once selected)</span>
                            </label>
                            <input type="file" id="logoFileInput" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="width:100%;min-width:0;overflow:hidden;font-size:12px;">
                            <span id="logoUploadMsg" style="font-size:11px;display:block;margin-top:4px;"></span>
                        </div>
                    </div>
                </div>
                <div id="logoPreview" style="margin-bottom:12px;display:none;align-items:center;gap:10px;">
                    <img id="logoImg" src="" alt="Logo preview"
                         style="height:48px;border-radius:6px;border:1px solid var(--border);padding:4px;background:var(--bg2);">
                    <span id="logoSourceTag" style="font-size:11px;color:var(--t3);"></span>
                    <button type="button" id="logoClearBtn" class="btn btn-default btn-xs" style="white-space:nowrap;">
                        <i class="fa fa-times"></i> Remove
                    </button>
                </div>
                <div id="logoPreviewErr" style="display:none;font-size:11px;color:#ef4444;margin-bottom:12px;">
                    <i class="fa fa-exclamation-triangle"></i> That image URL couldn't be loaded — check the link.
                </div>
                <div style="text-align:right;">
                    <button type="button" class="btn btn-primary" id="toStep2Btn">
                        Next: Admin Account <i class="fa fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- Step 2: Admin Account                                          -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="step2" style="display:none;">
                <div style="padding:12px 14px;background:var(--sa-dim);border:1px solid var(--sa-ring);border-radius:8px;margin-bottom:18px;font-size:12.5px;color:var(--t2);line-height:1.7;">
                    <i class="fa fa-info-circle" style="color:var(--sa3);margin-right:6px;"></i>
                    <strong>School Code</strong> and <strong>SSA ID</strong> are auto-generated by the system.
                    The school admin logs in using the School Code and their SSA ID.
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>School Code</label>
                            <input type="text" class="form-control" id="schoolCode" name="school_code"
                                   value="Auto-generated" disabled
                                   style="background:var(--bg3);color:var(--t3);font-style:italic;">
                            <small style="color:var(--t3);font-size:11px;">Unique school code is auto-generated (e.g. 10001, 10002...).</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>SSA ID</label>
                            <input type="text" class="form-control" id="adminLoginId" name="admin_login_id"
                                   value="Auto-generated (SSA0001, SSA0002...)" disabled
                                   style="background:var(--bg3);color:var(--t3);font-style:italic;">
                            <small style="color:var(--t3);font-size:11px;">School Super Admin ID is auto-generated by the system.</small>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Admin Full Name <span style="color:var(--rose);">*</span></label>
                            <input type="text" class="form-control" name="admin_name"
                                   placeholder="e.g. Mr. Ramesh Kumar" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Admin Email <span style="color:var(--rose);">*</span></label>
                            <input type="email" class="form-control" name="admin_email"
                                   placeholder="principal@school.com" required>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Admin Password <span style="color:var(--rose);">*</span></label>
                            <div style="position:relative;">
                                <input type="password" class="form-control" id="adminPass"
                                       name="admin_password" placeholder="Min. 8 characters" required
                                       style="padding-right:36px;">
                                <i class="fa fa-eye" id="togglePass"
                                   style="position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--t3);font-size:14px;"></i>
                            </div>
                            <div style="margin-top:6px;height:4px;border-radius:2px;background:var(--border);overflow:hidden;">
                                <div id="passBar" style="height:100%;width:0%;transition:width .3s,background .3s;border-radius:2px;"></div>
                            </div>
                            <small id="passStrengthLabel" style="font-size:11px;color:var(--t3);"></small>
                        </div>
                    </div>
                    <div class="col-md-6" style="display:flex;align-items:flex-end;padding-bottom:14px;">
                        <button type="button" class="btn btn-default" id="genPassBtn" style="font-size:12px;">
                            <i class="fa fa-random"></i> Generate Password
                        </button>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <button type="button" class="btn btn-default" id="backToStep1Btn">
                        <i class="fa fa-arrow-left"></i> Back
                    </button>
                    <button type="button" class="btn btn-primary" id="toStep3Btn">
                        Next: Subscription <i class="fa fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- Step 3: Subscription & Session                                 -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="step3" style="display:none;">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Subscription Plan <span style="color:var(--rose);">*</span></label>
                            <select class="form-control" name="plan_id" id="planSelect" required>
                                <option value="">— Select Plan —</option>
                                <?php foreach($plans as $pid => $pname): ?>
                                <option value="<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars($pname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Expiry Date <span style="color:var(--rose);">*</span></label>
                            <input type="date" class="form-control" name="expiry_date"
                                   min="<?= date('Y-m-d') ?>"
                                   value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
                        </div>
                    </div>
                </div>
                <!-- Plan details preview -->
                <div id="planPreview" style="display:none;padding:14px;background:var(--bg3);border:1px solid var(--brd2);border-radius:10px;margin-bottom:16px;">
                    <div style="font-size:12px;font-weight:700;color:var(--sa3);margin-bottom:10px;font-family:var(--font-m);">PLAN DETAILS</div>
                    <div id="planPreviewContent"></div>
                </div>
                <div class="form-group">
                    <label>First Session Year <span style="color:var(--rose);">*</span></label>
                    <input type="text" class="form-control" name="session_year"
                           placeholder="e.g. 2025-26"
                           value="<?= date('Y') . '-' . substr(date('Y', strtotime('+1 year')), -2) ?>" required>
                    <small style="color:var(--t3);">Format: YYYY-YY (e.g. 2025-26). Must match the format used by Admin Login.</small>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <button type="button" class="btn btn-default" id="backToStep2Btn">
                        <i class="fa fa-arrow-left"></i> Back
                    </button>
                    <button type="button" class="btn btn-primary" id="toStep4Btn">
                        Review &amp; Confirm <i class="fa fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- Step 4: Review & Submit                                        -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="step4" style="display:none;">
                <div id="reviewContent" style="margin-bottom:20px;border:1px solid var(--border);border-radius:10px;overflow:hidden;"></div>
                <div style="display:flex;justify-content:space-between;">
                    <button type="button" class="btn btn-default" id="backToStep3Btn">
                        <i class="fa fa-arrow-left"></i> Back
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitOnboardBtn">
                        <i class="fa fa-check"></i> Onboard School
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>

</div><!-- /.col-md-8 -->

<!-- ── Sidebar ─────────────────────────────────────────────────────────────── -->
<div class="col-md-4">
    <div class="box">
        <div class="box-header">
            <i class="fa fa-info-circle" style="color:var(--sa3);margin-right:8px;"></i>
            <span class="box-title">What Gets Created</span>
        </div>
        <div class="box-body" style="font-size:12.5px;color:var(--t2);line-height:1.8;">
            <div style="margin-bottom:12px;">
                <div style="font-size:10px;font-weight:700;color:var(--sa3);margin-bottom:6px;font-family:var(--font-m);letter-spacing:.5px;">FIRESTORE COLLECTIONS</div>
                <ul style="margin:0;padding-left:16px;">
                    <li><code style="font-size:11px;">schools/{schoolId}</code> — tenant profile, logo, statsCache</li>
                    <li><code style="font-size:11px;">schoolControl/{schoolId}</code> — lifecycle state, subscription pointer, admin-disable flag</li>
                    <li><code style="font-size:11px;">subscriptions/{subscriptionId}</code> — plan, period start/end, billing</li>
                    <li><code style="font-size:11px;">schoolCodeIndex/{code}</code> — login fast-path lookup</li>
                    <li><code style="font-size:11px;">schoolNameIndex/{slug}</code> — name uniqueness lookup</li>
                    <li><code style="font-size:11px;">schoolSsa/{ssaId}</code> — primary Super Admin pointer</li>
                    <li><code style="font-size:11px;">tenantPublic/{schoolId}</code> — public-readable activeModules mirror</li>
                    <li>Default fee categories &amp; account heads (Firestore)</li>
                </ul>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:var(--sa3);margin-bottom:6px;font-family:var(--font-m);letter-spacing:.5px;">ADMIN LOGIN CREDENTIALS</div>
                <ul style="margin:0;padding-left:16px;">
                    <li>School Code → the 5-digit code you set above (universal, unique)</li>
                    <li>Admin ID → auto-minted SSA id (e.g. <code>SSA0003</code>)</li>
                    <li>Password → as entered, stored via Firebase Auth</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="box">
        <div class="box-header">
            <i class="fa fa-lightbulb-o" style="color:var(--amber);margin-right:8px;"></i>
            <span class="box-title">Important Notes</span>
        </div>
        <div class="box-body" style="font-size:12.5px;color:var(--t2);line-height:1.7;">
            <p>The <strong>school name</strong> is the primary Firebase key — it cannot be changed after onboarding.</p>
            <p>The <strong>school code</strong> must be unique and is used for admin authentication. Share it with the school.</p>
            <p>Session year must be <strong>YYYY-YY</strong> format (e.g. 2025-26) to match the system's login logic.</p>
        </div>
    </div>
    <!-- Credentials box (shown after successful onboard) -->
    <div class="box" id="credentialsBox" style="display:none;">
        <div class="box-header" style="background:var(--sa-dim);">
            <i class="fa fa-key" style="color:var(--sa3);margin-right:8px;"></i>
            <span class="box-title" style="color:var(--sa3);">Admin Credentials</span>
        </div>
        <div class="box-body" id="credentialsContent" style="font-size:12.5px;"></div>
    </div>
</div>

</div><!-- /.row -->

<!-- ── Onboarding success confirmation dialog ── -->
<div class="modal fade" id="onboardSuccessModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--green-dim);">
        <h4 class="modal-title" style="color:var(--green);"><i class="fa fa-check-circle"></i> School Onboarded Successfully</h4>
      </div>
      <div class="modal-body">
        <p style="color:var(--t2);margin-bottom:14px;font-size:13px;">Share these login credentials securely with the school administrator. The password is shown here only once.</p>
        <table class="table" style="margin-bottom:10px;">
          <tr><td style="color:var(--t3);width:140px;">School Name</td><td id="osmName" style="font-weight:600;"></td></tr>
          <tr><td style="color:var(--t3);">School ID</td><td><code id="osmSchoolId" style="background:var(--bg3);padding:2px 8px;border-radius:4px;color:var(--sa4);font-weight:600;"></code></td></tr>
          <tr><td style="color:var(--t3);">School Code</td><td><code id="osmCode" style="background:var(--bg3);padding:2px 8px;border-radius:4px;color:var(--sa4);font-weight:600;"></code></td></tr>
          <tr><td style="color:var(--t3);">SSA Login ID</td><td><code id="osmSsa" style="background:var(--bg3);padding:2px 8px;border-radius:4px;color:var(--sa4);font-weight:600;"></code></td></tr>
          <tr><td style="color:var(--t3);">Password</td><td><code id="osmPass" style="background:var(--bg3);padding:2px 8px;border-radius:4px;color:var(--sa4);font-weight:600;"></code></td></tr>
        </table>
        <button type="button" class="btn btn-default btn-sm" id="osmCopy"><i class="fa fa-copy"></i> Copy all credentials</button>
      </div>
      <div class="modal-footer">
        <a href="<?= base_url('superadmin/schools') ?>" class="btn btn-default">View all schools</a>
        <button type="button" class="btn btn-primary" data-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>
</section>

<script>
$(function(){

    // ── Step navigation ───────────────────────────────────────────────────────
    function goToStep(n){
        $('#step1,#step2,#step3,#step4').hide();
        $('#step' + n).show();
        $('.sa-step').css({background:'transparent',color:'var(--t3)'});
        $('.sa-step[data-step="'+n+'"]').css({background:'var(--sa-dim)',color:'var(--sa3)'});
        if (n === 4) buildReview();
        $('html,body').animate({scrollTop: $('.box-body').offset().top - 80}, 200);
    }

    // Direct step-indicator navigation. The top breadcrumb chips
    // (.sa-step) carry cursor:pointer to signal clickability, but had
    // no click handler — so the only way forward was via the per-step
    // Next button, which fires required-field validation. SA wants to
    // be able to inspect/jump freely; final required-field validation
    // still happens on the Submit handler at the end of step 4 (and
    // the per-step Next buttons retain their guards for users who
    // prefer the linear flow). Clicking a chip just jumps — no toast,
    // no validation, no scroll-lock.
    $('.sa-step').on('click', function(){
        var n = parseInt($(this).data('step'), 10);
        if (n >= 1 && n <= 4) goToStep(n);
    });

    // ── Real-time availability check ──────────────────────────────────────────
    var nameTimer, codeTimer;

    function checkAvail(val, msgEl, param){
        if (!val){ msgEl.text('').css('color',''); return; }
        msgEl.html('<i class="fa fa-spinner fa-spin"></i> Checking...').css('color','var(--t3)');
        var data = {};
        data[param] = val;
        $.post(BASE_URL + 'superadmin/schools/check_availability', data, function(r){
            if (r.status === 'success'){
                msgEl.html('<i class="fa fa-check-circle"></i> Available').css('color','var(--green, #22c55e)');
            } else {
                msgEl.html('<i class="fa fa-times-circle"></i> ' + r.message).css('color','var(--rose, #ef4444)');
            }
        }, 'json').fail(function(){ msgEl.text(''); });
    }

    $('#schoolName').on('blur', function(){
        checkAvail($(this).val().trim(), $('#nameAvailMsg'), 'school_name');
    }).on('input', function(){
        clearTimeout(nameTimer);
        $('#nameAvailMsg').text('');
    });

    $('#schoolCode').on('input', function(){
        this.value = this.value.toUpperCase();
        clearTimeout(codeTimer);
        $('#codeAvailMsg').text('');
        var v = this.value.trim();
        if (v.length >= 3){
            codeTimer = setTimeout(function(){ checkAvail(v, $('#codeAvailMsg'), 'school_code'); }, 700);
        }
    });

    // ── Logo URL preview ──────────────────────────────────────────────────────
    // ───────────────────────────────────────────────────────────────────────
    // Logo widget — the URL field and the file uploader are kept in sync.
    // The single source of truth is #logoUrlInput's value (submitted as
    // logo_url). Both paths write to it, so the user can freely switch:
    //   • paste/type a URL                → that becomes the logo
    //   • pick/upload a file              → returned Storage URL fills the field
    //   • upload, then type a URL         → the typed URL supersedes the upload
    //   • type a URL, then upload a file  → the upload supersedes the URL
    //   • Remove button                   → clears everything
    // The preview validates the image actually loads and flags broken links.
    // ───────────────────────────────────────────────────────────────────────
    (function(){
        var $url     = $('#logoUrlInput');
        var $file    = $('#logoFileInput');
        var $img     = $('#logoImg');
        var $preview = $('#logoPreview');
        var $err     = $('#logoPreviewErr');
        var $tag     = $('#logoSourceTag');
        var $msg     = $('#logoUploadMsg');

        function isHttp(u){ return /^https?:\/\//i.test(u); }
        function isStored(u){ return /firebasestorage\.googleapis\.com/i.test(u); }

        // Render the preview for whatever URL is current. label describes source.
        function showPreview(u, label){
            $err.hide();
            if(!u || !isHttp(u)){ $preview.hide(); $tag.text(''); return; }
            $tag.text(label || '');
            $img.attr('src', u);
            $preview.css('display','flex');
        }
        function clearAll(){
            $url.val(''); $file.val(''); $msg.html('');
            $preview.hide(); $err.hide(); $tag.text('');
        }
        function labelFor(u){ return isStored(u) ? 'Stored logo' : 'External URL'; }

        // Image actually loaded vs. broken link feedback.
        $img.on('load',  function(){ $err.hide(); });
        $img.on('error', function(){
            if($url.val().trim()){ $preview.hide(); $err.show(); }
        });

        // Typing / pasting a URL supersedes any prior upload.
        $url.on('input blur paste', function(){
            setTimeout(function(){                 // defer so pasted value is in
                var u = $url.val().trim();
                $msg.html('');                     // drop any stale "Uploaded ✓"
                if(u){ showPreview(u, labelFor(u)); }
                else { $preview.hide(); $err.hide(); $tag.text(''); }
            }, 0);
        });

        function doUpload(file){
            if(!file){ return; }
            var fd = new FormData();
            fd.append('logo', file);
            // temp prefix → server stages to Storage temp path (no SCH_ id yet)
            fd.append('school_uid', 'temp_' + ($('#schoolName').val().trim() || 'upload'));
            $file.prop('disabled', true);
            $msg.html('<i class="fa fa-spinner fa-spin"></i> Uploading...');
            $.ajax({
                url: BASE_URL+'superadmin/schools/upload_logo',
                type:'POST', data:fd, contentType:false, processData:false,
                success:function(r){
                    if(r && r.status==='success'){
                        $url.val(r.logo_url);              // sync: upload fills URL field
                        showPreview(r.logo_url, 'Uploaded');
                        $msg.html('<span style="color:#22c55e;"><i class="fa fa-check"></i> Uploaded</span>');
                    } else {
                        $msg.html('<span style="color:#ef4444;">'+((r&&r.message)||'Upload failed.')+'</span>');
                    }
                },
                error:function(){ $msg.html('<span style="color:#ef4444;">Upload failed.</span>'); },
                complete:function(){ $file.prop('disabled', false); }
            });
        }

        // Single, clear behavior: selecting a file uploads it immediately.
        // (No separate Upload button — a picked-but-not-uploaded file can't be lost.)
        $file.on('change', function(){ if(this.files[0]) doUpload(this.files[0]); });
        // Remove clears both inputs and the preview.
        $('#logoClearBtn').on('click', clearAll);

        // Initial paint (e.g. returning to step 1 with a value already set).
        if($url.val().trim()){ showPreview($url.val().trim(), labelFor($url.val().trim())); }
    })();

    // ── Password strength meter ───────────────────────────────────────────────
    var strengthLabels = ['Weak','Fair','Good','Strong'];
    var strengthColors = ['#ef4444','#f97316','#eab308','#22c55e'];

    $('#adminPass').on('input', function(){
        var v = $(this).val(), s = 0;
        if (v.length >= 8)            s++;
        if (/[A-Z]/.test(v))          s++;
        if (/[0-9]/.test(v))          s++;
        if (/[^A-Za-z0-9]/.test(v))   s++;
        var idx = Math.max(0, s - 1);
        $('#passBar').css({width: (s * 25) + '%', background: strengthColors[idx]});
        $('#passStrengthLabel').text(v.length ? strengthLabels[idx] : '').css('color', strengthColors[idx]);
    });

    $('#togglePass').on('click', function(){
        var inp = $('#adminPass');
        var toText = inp.attr('type') === 'password';
        inp.attr('type', toText ? 'text' : 'password');
        $(this).toggleClass('fa-eye fa-eye-slash');
    });

    $('#genPassBtn').on('click', function(){
        var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#$!';
        var pw = '';
        for (var i = 0; i < 12; i++) pw += chars[Math.floor(Math.random() * chars.length)];
        $('#adminPass').val(pw).attr('type','text').trigger('input');
        $('#togglePass').removeClass('fa-eye').addClass('fa-eye-slash');
    });

    // ── Plan preview ──────────────────────────────────────────────────────────
    $('#planSelect').on('change', function(){
        var pid = $(this).val();
        if (!pid){ $('#planPreview').hide(); return; }
        $.post(BASE_URL + 'superadmin/plans/fetch', {plan_id: pid}, function(r){
            if (r.status === 'success' && r.plan){
                var p = r.plan;
                var mods = [];
                if (p.modules) $.each(p.modules, function(k, v){ if(v) mods.push(k); });
                var html = '<div style="font-size:12.5px;color:var(--t2);">'
                    + '<strong>' + (p.name||'') + '</strong>'
                    + (p.price ? ' &mdash; &#8377;' + p.price + '/yr' : '') + '<br>'
                    + (p.description ? '<span style="opacity:.8;">' + p.description + '</span><br>' : '')
                    + (mods.length ? '<span style="color:var(--sa3);font-size:11px;">Modules: ' + mods.join(', ') + '</span>' : '')
                    + '</div>';
                $('#planPreviewContent').html(html);
                $('#planPreview').show();
            }
        }, 'json');
    });

    // ── Step navigation buttons ───────────────────────────────────────────────
    $('#toStep2Btn').on('click', function(){
        var name  = $('#schoolName').val().trim();
        var email = $('#schoolEmail').val().trim();
        if (!name)  { saToast('School name is required.','warning'); return; }
        if (!email) { saToast('Contact email is required.','warning'); return; }
        goToStep(2);
    });

    $('#toStep3Btn').on('click', function(){
        var aname   = $('input[name="admin_name"]').val().trim();
        var aemail  = $('input[name="admin_email"]').val().trim();
        var pass    = $('#adminPass').val();
        if (!aname)              { saToast('Admin name is required.','warning'); return; }
        if (!aemail)             { saToast('Admin email is required.','warning'); return; }
        if (pass.length < 8)     { saToast('Password must be at least 8 characters.','warning'); return; }
        goToStep(3);
    });

    $('#toStep4Btn').on('click', function(){
        if (!$('#planSelect').val()){ saToast('Please select a subscription plan.','warning'); return; }
        var sy = $('input[name="session_year"]').val().trim();
        if (!/^\d{4}-\d{2}$/.test(sy)){ saToast('Session year must be in YYYY-YY format (e.g. 2025-26).','warning'); return; }
        goToStep(4);
    });

    $('#backToStep1Btn').on('click', function(){ goToStep(1); });
    $('#backToStep2Btn').on('click', function(){ goToStep(2); });
    $('#backToStep3Btn').on('click', function(){ goToStep(3); });

    // ── Build review summary ──────────────────────────────────────────────────
    function reviewRow(label, value, highlight){
        if (!value) return '';
        return '<div style="display:flex;align-items:flex-start;padding:9px 14px;border-bottom:1px solid var(--border);font-size:12.5px;">'
            + '<div style="width:150px;flex-shrink:0;color:var(--t3);">' + label + '</div>'
            + '<div style="color:var(--t1);font-weight:' + (highlight?'700':'500') + ';word-break:break-all;">' + value + '</div>'
            + '</div>';
    }

    function reviewSection(title, rows){
        return '<div style="padding:10px 14px 2px;background:var(--bg3);">'
            + '<div style="font-size:10px;font-weight:700;color:var(--sa3);letter-spacing:.5px;font-family:var(--font-m);">' + title + '</div>'
            + '</div>' + rows;
    }

    function buildReview(){
        var f = $('#onboardForm');
        var html = '';

        html += reviewSection('SCHOOL PROFILE',
            reviewRow('Name',    f.find('[name=school_name]').val(), true)
            + reviewRow('City',  f.find('[name=city]').val())
            + reviewRow('Street',f.find('[name=street]').val())
            + reviewRow('Email', f.find('[name=email]').val())
            + reviewRow('Phone', f.find('[name=phone]').val())
            + reviewRow('Logo URL', f.find('[name=logo_url]').val())
        );

        html += reviewSection('ADMIN ACCOUNT',
            reviewRow('School Code', 'Auto-generated', true)
            + reviewRow('SSA ID', 'Auto-generated', true)
            + reviewRow('Admin Name',  f.find('[name=admin_name]').val())
            + reviewRow('Admin Email', f.find('[name=admin_email]').val())
            + reviewRow('Password',    '••••••••')
        );

        html += reviewSection('SUBSCRIPTION & SESSION',
            reviewRow('Plan',         $('#planSelect option:selected').text())
            + reviewRow('Expiry Date',f.find('[name=expiry_date]').val())
            + reviewRow('Session Year',f.find('[name=session_year]').val(), true)
        );

        $('#reviewContent').html(html);
    }

    // ── Form submit ───────────────────────────────────────────────────────────
    $('#onboardForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $('#submitOnboardBtn').prop('disabled', true)
            .html('<i class="fa fa-spinner fa-spin"></i> Onboarding...');

        $.ajax({
            url:  BASE_URL + 'superadmin/schools/onboard',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(r){
                if (r.status === 'success'){
                    // Show success alert
                    $('#onboardAlert').removeClass('alert-danger').addClass('alert-success')
                        .html('<i class="fa fa-check-circle"></i> ' + r.message
                            + ' <a href="' + BASE_URL + 'superadmin/schools" '
                            + 'style="color:inherit;text-decoration:underline !important;margin-left:8px;">'
                            + 'View all schools &rarr;</a>')
                        .show();

                    // Show credentials in sidebar
                    var schoolCode = r.school_code || '';
                    var ssaId = r.admin_id || '';
                    $('#credentialsContent').html(
                        '<p style="margin-bottom:6px;"><strong>School Code:</strong> '
                        + '<code style="background:var(--bg3);padding:2px 6px;border-radius:4px;">' + schoolCode + '</code></p>'
                        + '<p style="margin-bottom:6px;"><strong>SSA ID:</strong> '
                        + '<code style="background:var(--bg3);padding:2px 6px;border-radius:4px;">' + ssaId + '</code></p>'
                        + '<p style="margin-bottom:0;color:var(--t3);font-size:11px;">Share these credentials with the school administrator.</p>'
                    );
                    $('#credentialsBox').show();

                    // Prominent confirmation dialog (id + password + school name + schoolId)
                    $('#osmName').text($('#schoolName').val().trim());
                    $('#osmSchoolId').text(r.school_id || '');
                    $('#osmCode').text(schoolCode);
                    $('#osmSsa').text(ssaId);
                    $('#osmPass').text($('#adminPass').val());
                    $('#osmCopy').off('click').on('click', function(){
                        var txt = 'School: ' + $('#osmName').text()
                                + '\nSchool ID: ' + $('#osmSchoolId').text()
                                + '\nSchool Code: ' + $('#osmCode').text()
                                + '\nSSA Login ID: ' + $('#osmSsa').text()
                                + '\nPassword: ' + $('#osmPass').text();
                        if (navigator.clipboard) { navigator.clipboard.writeText(txt).then(function(){ if (window.saToast) saToast('Credentials copied', 'success'); }); }
                    });
                    try { $('#onboardSuccessModal').modal('show'); } catch(e) {}

                    $btn.hide();
                    $('#backToStep3Btn').hide();
                } else {
                    $('#onboardAlert').removeClass('alert-success').addClass('alert-danger')
                        .html('<i class="fa fa-times-circle"></i> ' + r.message).show();
                    $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Onboard School');
                }
            },
            error: function(xhr){
                var msg = 'Server error. Please try again.';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r && r.message) msg = r.message;
                } catch(e){}
                $('#onboardAlert').removeClass('alert-success').addClass('alert-danger')
                    .html('<i class="fa fa-times-circle"></i> ' + msg).show();
                saToast(msg, 'error');
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Onboard School');
            }
        });
    });

});
</script>
