<?php
$school     = $school     ?? [];
$school_uid = $school_uid ?? '';
$plans      = $plans      ?? [];
$profile    = $school['profile']      ?? [];
$sub        = $school['subscription'] ?? [];
$cache      = $school['stats_cache']  ?? [];
?>

<section class="content-header">
    <h1>
        <i class="fa fa-building" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>
        <?= htmlspecialchars($profile['name'] ?? $school_uid) ?>
    </h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('superadmin/schools') ?>">School Management</a></li>
        <li><a href="<?= base_url('superadmin/schools') ?>">All Schools</a></li>
        <li class="active"><?= htmlspecialchars($profile['name'] ?? $school_uid) ?></li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">
<div class="row">

    <!-- Left: Profile + Subscription -->
    <div class="col-md-8">

        <!-- Profile Card -->
        <div class="box box-primary">
            <div class="box-header">
                <i class="fa fa-info-circle" style="color:var(--sa3);margin-right:8px;"></i>
                <span class="box-title">School Profile</span>
                <div style="float:right;">
                    <?php
                    $rawStatus = strtolower($sub['status'] ?? 'inactive');
                    $stNorm    = ($rawStatus === 'active') ? 'active' : (($rawStatus === 'suspended') ? 'suspended' : 'inactive');
                    $cls       = ['active'=>'label-success','inactive'=>'label-default','suspended'=>'label-danger'];
                    ?>
                    <span class="label <?= $cls[$stNorm] ?? 'label-default' ?>"><?= ucfirst($stNorm) ?></span>
                </div>
            </div>
            <div class="box-body">
                <form id="profileForm">
                    <input type="hidden" name="school_uid" value="<?= htmlspecialchars($school_uid) ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>School Name</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($profile['name'] ?? '') ?>" disabled>
                                <small style="color:var(--t3);font-size:11px;">Name cannot be changed (Firebase key).</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" class="form-control" name="city" value="<?= htmlspecialchars($profile['city'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Admin Email</label>
                                <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Street Address</label>
                                <input type="text" class="form-control" name="street" value="<?= htmlspecialchars($profile['street'] ?? '') ?>" placeholder="e.g. 12, Main Road, Sector 5">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>School Code</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($profile['school_code'] ?? '') ?>" disabled>
                                <small style="color:var(--t3);font-size:11px;">Used as School ID at admin login.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Domain Identifier</label>
                                <input type="text" class="form-control" name="domain_identifier"
                                       value="<?= htmlspecialchars($profile['domain_identifier'] ?? '') ?>"
                                       placeholder="e.g. springdaleschool" maxlength="40"
                                       pattern="[a-z0-9]+" title="Lowercase letters and digits only">
                                <small style="color:var(--t3);font-size:11px;">Lowercase alphanumeric, used for subdomain routing.</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Logo URL</label>
                                <input type="url" class="form-control" name="logo_url" id="logoUrlInput"
                                       value="<?= htmlspecialchars($profile['logo_url'] ?? '') ?>"
                                       placeholder="https://... or upload below">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Upload Logo</label>
                                <input type="file" id="logoFileInput" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="display:block;margin-bottom:6px;">
                                <button type="button" class="btn btn-default btn-xs" id="uploadLogoBtn"><i class="fa fa-upload"></i> Upload</button>
                                <span id="logoUploadMsg" style="margin-left:8px;font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($profile['logo_url'])): ?>
                    <div style="margin-bottom:12px;">
                        <img id="logoPreview" src="<?= htmlspecialchars($profile['logo_url']) ?>" alt="School Logo"
                             style="max-height:80px;max-width:200px;border:1px solid var(--border);border-radius:8px;padding:4px;background:var(--bg2);">
                    </div>
                    <?php else: ?>
                    <div style="margin-bottom:12px;display:none;" id="logoPreviewWrap">
                        <img id="logoPreview" src="" alt="School Logo"
                             style="max-height:80px;max-width:200px;border:1px solid var(--border);border-radius:8px;padding:4px;background:var(--bg2);">
                    </div>
                    <?php endif; ?>
                    <div style="text-align:right;">
                        <button type="submit" class="btn btn-primary btn-sm" id="saveProfileBtn">
                            <i class="fa fa-save"></i> Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Subscription Card -->
        <div class="box box-warning">
            <div class="box-header">
                <i class="fa fa-tags" style="color:var(--amber);margin-right:8px;"></i>
                <span class="box-title">Subscription</span>
            </div>
            <div class="box-body">
                <form id="planForm">
                    <input type="hidden" name="school_uid" value="<?= htmlspecialchars($school_uid) ?>">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Current Plan</label>
                                <select class="form-control" name="plan_id">
                                    <?php foreach($plans as $pid => $pname): ?>
                                    <option value="<?= htmlspecialchars($pid) ?>"
                                        <?= ($sub['plan_id'] ?? '') === $pid ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pname) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Expiry Date</label>
                                <input type="date" class="form-control" name="expiry_date"
                                       value="<?= htmlspecialchars($sub['expiry_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Sub Status</label>
                                <div style="padding:8px 0;">
                                    <?php $ss = $sub['status'] ?? 'expired';
                                    $sc = ['active'=>'label-success','grace'=>'label-warning','expired'=>'label-danger','suspended'=>'label-default','inactive'=>'label-default'];
                                    ?>
                                    <span class="label <?= $sc[$ss] ?? 'label-default' ?>"><?= htmlspecialchars($ss) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <button type="submit" class="btn btn-warning btn-sm" id="savePlanBtn">
                            <i class="fa fa-refresh"></i> Update Subscription
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div><!-- /.col-md-8 -->

    <!-- Right: Stats + Quick Actions -->
    <div class="col-md-4">

        <!-- Stats Cache -->
        <div class="box">
            <div class="box-header">
                <i class="fa fa-bar-chart" style="color:var(--sa3);margin-right:8px;"></i>
                <span class="box-title">Cached Stats</span>
                <button class="btn btn-default btn-xs" style="float:right;" id="refreshSchoolStatsBtn">
                    <i class="fa fa-refresh" id="refreshSchoolIcon"></i>
                </button>
            </div>
            <div class="box-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div style="text-align:center;padding:14px;background:var(--bg3);border-radius:10px;">
                        <div style="font-family:var(--font-d);font-size:24px;font-weight:800;color:var(--t1);" id="cacheStudents"><?= number_format($cache['total_students'] ?? 0) ?></div>
                        <div style="font-size:11px;color:var(--t3);">Students</div>
                    </div>
                    <div style="text-align:center;padding:14px;background:var(--bg3);border-radius:10px;">
                        <div style="font-family:var(--font-d);font-size:24px;font-weight:800;color:var(--t1);" id="cacheStaff"><?= number_format($cache['total_staff'] ?? 0) ?></div>
                        <div style="font-size:11px;color:var(--t3);">Staff</div>
                    </div>
                </div>
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-top:10px;text-align:center;">
                    Last updated: <span id="cacheLastUpdated"><?= htmlspecialchars($cache['last_updated'] ?? 'Never') ?></span>
                </div>
            </div>
        </div>

        <!-- Status Toggle -->
        <?php
        // Disable the button whose target status equals the current status
        // so the SA can't "activate an already-active school" etc. The
        // currently-active state button shows a checkmark + " (current)"
        // tag and is muted, signalling read-only state at a glance.
        $curStatus = strtolower((string) ($profile['status'] ?? 'inactive'));
        // `action` is the verb shown on the button when it's the available
        // ACTION (e.g. "Activate"); `state` is the past participle shown
        // when it's the CURRENT state (e.g. "Activated"). UX nit, but it
        // makes the "(current)" tag unambiguous — a button labelled
        // "Activate (current)" reads as an action that's already current,
        // which is confusing.
        $stBtns = [
            'active'    => ['class' => 'btn-success', 'icon' => 'fa-check', 'action' => 'Activate',   'state' => 'Activated'],
            'inactive'  => ['class' => 'btn-warning', 'icon' => 'fa-pause', 'action' => 'Deactivate', 'state' => 'Deactivated'],
            'suspended' => ['class' => 'btn-danger',  'icon' => 'fa-ban',   'action' => 'Suspend',    'state' => 'Suspended'],
        ];
        ?>
        <div class="box box-danger">
            <div class="box-header"><i class="fa fa-toggle-on" style="color:var(--rose);margin-right:8px;"></i><span class="box-title">Access Control</span></div>
            <div class="box-body">
                <p style="font-size:12.5px;color:var(--t2);margin-bottom:12px;">Change this school's access status. Deactivating will immediately lock out all school admins.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;" id="saStatusBtnRow">
                    <?php foreach ($stBtns as $target => $cfg):
                        $isCurrent = ($curStatus === $target);
                        $cls = 'btn btn-sm sa-st-btn ' . $cfg['class'];
                        if ($isCurrent) $cls .= ' active';
                        // Past participle when current ("Activated"), action verb otherwise ("Activate").
                        $btnLabel = $isCurrent ? $cfg['state'] : $cfg['action'];
                    ?>
                    <button class="<?= $cls ?>" data-status="<?= $target ?>" data-label="<?= htmlspecialchars($cfg['action']) ?>"
                            <?= $isCurrent ? 'disabled aria-pressed="true" title="Current state"' : '' ?>>
                        <i class="fa <?= $cfg['icon'] ?>"></i> <?= htmlspecialchars($btnLabel) ?>
                        <?php if ($isCurrent): ?><span style="font-size:10px;opacity:.85;margin-left:4px;">(current)</span><?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Created Info -->
        <div class="box">
            <div class="box-body" style="font-size:12px;color:var(--t3);font-family:var(--font-m);">
                <div><span style="color:var(--t4);">UID:</span> <span style="color:var(--sa3);"><?= htmlspecialchars($school_uid) ?></span></div>
                <div style="margin-top:6px;"><span style="color:var(--t4);">Created:</span> <span style="color:var(--t2);"><?= htmlspecialchars($profile['created_at'] ?? 'N/A') ?></span></div>
                <div style="margin-top:6px;"><span style="color:var(--t4);">By:</span> <span style="color:var(--t2);"><?= htmlspecialchars($profile['created_by'] ?? 'SA') ?></span></div>
            </div>
        </div>

    </div><!-- /.col-md-4 -->

</div><!-- /.row -->
</section>

<script>
$(function(){
    // Save profile. The Firestore round-trip is fast on local (~200ms),
    // so without a minimum spinner duration the user often misses the
    // visual feedback — the button flickers spinner → original almost
    // instantly. We hold the spinner for a fixed minimum (700ms) so the
    // "Saving…" state is always perceptible.
    $('#profileForm').on('submit', function(e){
        e.preventDefault();
        var $btn   = $('#saveProfileBtn');
        var origHtml = '<i class="fa fa-save"></i> Save Profile';
        var startT = Date.now();
        $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        function restore(){
            var wait = Math.max(0, 700 - (Date.now() - startT));
            setTimeout(function(){
                $btn.prop('disabled',false).html(origHtml);
            }, wait);
        }
        $.ajax({ url: BASE_URL+'superadmin/schools/update_profile', type:'POST', data:$(this).serialize(),
            success: function(r){
                saToast(r.message || (r.status==='success'?'Saved.':'Error'), r.status);
                restore();
            },
            error: function(){ saToast('Server error.','error'); restore(); }
        });
    });

    // Save plan
    $('#planForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $('#savePlanBtn').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
        $.ajax({ url: BASE_URL+'superadmin/schools/assign_plan', type:'POST', data:$(this).serialize(),
            success: function(r){
                saToast(r.message || (r.status==='success'?'Updated.':'Error'), r.status);
                $btn.prop('disabled',false).html('<i class="fa fa-refresh"></i> Update Subscription');
                if(r.status==='success') setTimeout(function(){location.reload();},1200);
            }, error:function(){ saToast('Server error.','error'); $btn.prop('disabled',false).html('<i class="fa fa-refresh"></i> Update Subscription'); }
        });
    });

    // Logo URL preview on input change
    $('#logoUrlInput').on('input', function(){
        var url = $(this).val().trim();
        if(url){ $('#logoPreview, #logoPreviewWrap').show(); $('#logoPreview').attr('src', url); }
        else   { $('#logoPreviewWrap').hide(); }
    });

    // Upload logo file
    $('#uploadLogoBtn').on('click', function(){
        var file = $('#logoFileInput')[0].files[0];
        if(!file){ saToast('Select a file first.','error'); return; }
        var fd = new FormData();
        fd.append('logo', file);
        fd.append('school_uid', '<?= addslashes($school_uid) ?>');
        $('#logoUploadMsg').html('<i class="fa fa-spinner fa-spin"></i>');
        $.ajax({
            url: BASE_URL+'superadmin/schools/upload_logo',
            type:'POST', data:fd, contentType:false, processData:false,
            success:function(r){
                if(r.status==='success'){
                    $('#logoUrlInput').val(r.logo_url);
                    $('#logoPreview').attr('src', r.logo_url);
                    $('#logoPreview, #logoPreviewWrap').show();
                    $('#logoUploadMsg').html('<span style="color:#22c55e;"><i class="fa fa-check"></i> Uploaded</span>');
                    saToast('Logo uploaded.','success');
                } else {
                    $('#logoUploadMsg').html('<span style="color:#ef4444;">'+escHtml(r.message)+'</span>');
                    saToast(r.message,'error');
                }
            },
            error:function(){ $('#logoUploadMsg').html('<span style="color:#ef4444;">Upload failed.</span>'); }
        });
    });

    function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    // Status toggle. While the AJAX is in flight, lock the whole button
    // row (no spam-click, no accidental click on a sibling state) and
    // show a spinner on the clicked button with action text ("Suspending…"
    // etc). On success the page reloads, which re-renders the row with
    // the new "(current)" disabled button — so we don't manually restore.
    $('#saStatusBtnRow').on('click', '.sa-st-btn', function(){
        var $btn   = $(this);
        if ($btn.prop('disabled')) return; // already current state
        var status = $btn.data('status');
        var label  = String($btn.data('label') || status);
        var verb   = {active:'activate', inactive:'deactivate', suspended:'suspend'}[status] || 'change';
        var gerund = {active:'Activating', inactive:'Deactivating', suspended:'Suspending'}[status] || 'Updating';
        if(!confirm('Are you sure you want to '+verb+' this school?')) return;

        // Lock the whole row so the sibling buttons aren't clickable.
        var $row    = $('#saStatusBtnRow');
        var $others = $row.find('.sa-st-btn').not($btn);
        var origBtnHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + gerund + '…');
        $others.prop('disabled', true).addClass('sa-st-locked');

        function restore(){
            $btn.prop('disabled', false).html(origBtnHtml);
            $others.prop('disabled', false).removeClass('sa-st-locked');
        }

        $.ajax({ url: BASE_URL+'superadmin/schools/toggle_status', type:'POST',
            data:{ school_uid:'<?= addslashes($school_uid) ?>', status:status },
            success:function(r){
                saToast(r.message, r.status);
                if(r.status === 'success'){
                    // Keep buttons locked through the reload so user sees
                    // a stable "in-progress" state until the new page
                    // paints with the new (current) disabled button.
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    restore();
                }
            },
            error:function(){ saToast('Server error.','error'); restore(); }
        });
    });

    // Refresh stats. Update ALL three readouts on success — including
    // the timestamp (which had no id before, so prior refreshes left
    // the panel looking unchanged when counts happened to be 0/0).
    // Also flash the panel border briefly so the user can't miss the
    // visual confirmation even when the numbers themselves haven't
    // changed (e.g. tenant with 0 students/0 staff like ZZ B1).
    function fmtIST(iso){
        if(!iso) return 'Never';
        var d = new Date(iso);
        if(isNaN(d.getTime())){
            // Server may return "Y-m-d H:i:s" instead of ISO 8601 —
            // treat it as IST wall-clock and display verbatim.
            return iso + ' IST';
        }
        return d.toLocaleString('en-IN', {
            timeZone:'Asia/Kolkata',
            day:'2-digit', month:'short', year:'numeric',
            hour:'2-digit', minute:'2-digit', second:'2-digit',
            hour12:true
        }) + ' IST';
    }
    $('#refreshSchoolStatsBtn').on('click', function(){
        var $btn=$(this).prop('disabled',true);
        $('#refreshSchoolIcon').addClass('fa-spin');
        $.ajax({ url: BASE_URL+'superadmin/schools/refresh_school_stats', type:'POST',
            data:{ school_uid:'<?= addslashes($school_uid) ?>' },
            success:function(r){
                if(r.status==='success'){
                    $('#cacheStudents').text(parseInt(r.total_students||0).toLocaleString('en-IN'));
                    $('#cacheStaff').text(parseInt(r.total_staff||0).toLocaleString('en-IN'));
                    $('#cacheLastUpdated').text(fmtIST(r.last_updated));
                    // Brief border flash on the parent box so the user
                    // gets clear visual feedback even when counts didn't
                    // change.
                    var $box = $('#refreshSchoolStatsBtn').closest('.box');
                    $box.css('transition','box-shadow .3s ease, border-color .3s ease')
                        .css({'box-shadow':'0 0 0 2px var(--sa3, #7c3aed)','border-color':'var(--sa3, #7c3aed)'});
                    setTimeout(function(){ $box.css({'box-shadow':'','border-color':''}); }, 900);
                    saToast('Stats refreshed.','success');
                } else {
                    saToast(r.message || 'Refresh failed.','error');
                }
            },
            error:function(){ saToast('Server error.','error'); },
            complete:function(){ $btn.prop('disabled',false); $('#refreshSchoolIcon').removeClass('fa-spin'); }
        });
    });
});
</script>
