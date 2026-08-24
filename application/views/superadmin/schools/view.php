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
                    <?php $__logo = $profile['logo_url'] ?? ''; ?>
                    <div id="logoPreviewWrap" style="margin-bottom:12px;align-items:center;gap:10px;display:<?= $__logo ? 'flex' : 'none' ?>;">
                        <img id="logoPreview" src="<?= htmlspecialchars($__logo) ?>" alt="School Logo"
                             style="max-height:80px;max-width:200px;border:1px solid var(--border);border-radius:8px;padding:4px;background:var(--bg2);">
                        <span id="logoSourceTag" style="font-size:11px;color:var(--t3);"></span>
                        <button type="button" id="logoClearBtn" class="btn btn-default btn-xs" style="white-space:nowrap;"><i class="fa fa-times"></i> Remove</button>
                    </div>
                    <div id="logoPreviewErr" style="display:none;font-size:11px;color:#ef4444;margin-bottom:12px;">
                        <i class="fa fa-exclamation-triangle"></i> That image URL couldn't be loaded — check the link.
                    </div>
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

        <!-- ══ INSTITUTION LIFECYCLE CONSOLE ══════════════════════════
             Separate from Access Control above ON PURPOSE. That panel is the
             COMMERCIAL axis ("has this customer paid?"); this one is the
             INSTITUTION axis ("does this school still operate?"). Showing
             them as one control is what made "closed" and "unpaid"
             indistinguishable in the first place. -->
        <div class="box box-primary" id="saLifecycleBox">
            <div class="box-header">
                <i class="fa fa-university" style="color:var(--sa3);margin-right:8px;"></i>
                <span class="box-title">Institution Lifecycle</span>
                <button type="button" class="btn btn-xs btn-default pull-right" id="saLcRefresh">
                    <i class="fa fa-refresh"></i> Refresh
                </button>
            </div>
            <div class="box-body" style="padding:12px 14px 14px;">
                <p style="font-size:12.5px;color:var(--t2);margin:0 0 12px;">
                    The real-world operating status of the institution. This is <strong>not</strong> the
                    billing status — a school can be fully paid up and closed, or unpaid and still teaching.
                </p>

                <div id="saLcLoading" style="font-size:12px;color:var(--t3);padding:8px 0;">
                    <i class="fa fa-spinner fa-spin"></i> Loading lifecycle…
                </div>

                <div id="saLcBody" style="display:none;">
                    <!-- State strip -->
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                        <div style="flex:1;min-width:150px;border:1px solid var(--border);border-radius:8px;padding:10px;background:var(--bg3);">
                            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);">Institution</div>
                            <div id="saLcState" style="font-size:16px;font-weight:700;margin-top:3px;">—</div>
                            <div id="saLcStateNote" style="font-size:11px;color:var(--t3);margin-top:2px;"></div>
                        </div>
                        <div style="flex:1;min-width:150px;border:1px solid var(--border);border-radius:8px;padding:10px;background:var(--bg3);">
                            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);">Commercial</div>
                            <div id="saLcCommercial" style="font-size:16px;font-weight:700;margin-top:3px;">—</div>
                            <div id="saLcCommercialNote" style="font-size:11px;color:var(--t3);margin-top:2px;"></div>
                        </div>
                        <div style="flex:1;min-width:150px;border:1px solid var(--border);border-radius:8px;padding:10px;background:var(--bg3);">
                            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);">Academic session</div>
                            <div id="saLcSession" style="font-size:16px;font-weight:700;margin-top:3px;">—</div>
                            <div style="font-size:11px;color:var(--t3);margin-top:2px;">Independent of both axes</div>
                        </div>
                    </div>

                    <!-- Effective verdict -->
                    <div id="saLcVerdict" style="display:none;font-size:12.5px;padding:9px 11px;border-radius:8px;margin-bottom:14px;"></div>

                    <!-- Readiness -->
                    <div style="margin-bottom:14px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                            <span style="font-size:12px;font-weight:600;">Operational readiness</span>
                            <span id="saLcScore" style="font-size:12px;color:var(--t3);">—</span>
                        </div>
                        <div style="height:7px;background:var(--bg3);border-radius:4px;overflow:hidden;">
                            <div id="saLcScoreBar" style="height:100%;width:0;background:#22c55e;transition:width .3s;"></div>
                        </div>
                        <div id="saLcChecks" style="margin-top:9px;"></div>
                    </div>

                    <!-- Closure checklist (only shown while CLOSING) -->
                    <div id="saLcClosureWrap" style="display:none;margin-bottom:14px;">
                        <div style="font-size:12px;font-weight:600;margin-bottom:6px;">Closure checklist</div>
                        <div id="saLcClosure"></div>
                    </div>

                    <!-- Next permitted transitions -->
                    <div style="font-size:12px;font-weight:600;margin-bottom:6px;">Next permitted transitions</div>
                    <div id="saLcNext" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;"></div>

                    <!-- History -->
                    <div style="font-size:12px;font-weight:600;margin-bottom:6px;">Lifecycle history</div>
                    <div id="saLcHistory" style="font-size:11.5px;"></div>
                </div>
            </div>
        </div>

        <!-- Lifecycle transition modal -->
        <div class="modal fade" id="saLcModal" tabindex="-1" role="dialog">
          <div class="modal-dialog" role="document">
            <div class="modal-content">
              <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Change institution state</h4>
              </div>
              <div class="modal-body">
                <p id="saLcModalIntro" style="font-size:13px;"></p>
                <div id="saLcImpact" style="font-size:12px;border:1px solid var(--border);border-radius:8px;padding:10px;background:var(--bg3);margin-bottom:12px;"></div>

                <div class="form-group">
                  <label style="font-size:12px;">Reason <span style="color:#ef4444;">*</span></label>
                  <textarea id="saLcReason" class="form-control" rows="2"
                            placeholder="Recorded permanently in the audit trail"></textarea>
                </div>
                <div class="form-group">
                  <label style="font-size:12px;">Effective date</label>
                  <input type="date" id="saLcEffective" class="form-control">
                  <span style="font-size:11px;color:var(--t3);">Closures are usually announced ahead of time. Defaults to today.</span>
                </div>
                <div class="form-group" id="saLcApproverWrap" style="display:none;">
                  <label style="font-size:12px;">Second authorizer (Superadmin ID) <span style="color:#ef4444;">*</span></label>
                  <input type="text" id="saLcApprover" class="form-control" placeholder="e.g. SA0002">
                  <span style="font-size:11px;color:var(--t3);">This transition requires two people. Must not be you.</span>
                </div>
                <div class="checkbox" id="saLcOverrideWrap" style="display:none;">
                  <label style="font-size:12px;">
                    <input type="checkbox" id="saLcOverride"> Override the blocking items (recorded permanently)
                  </label>
                </div>
                <div id="saLcModalErr" style="display:none;font-size:12px;color:#ef4444;margin-top:6px;"></div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="saLcConfirm">Apply change</button>
              </div>
            </div>
          </div>
        </div>

        <!-- School Super Admins -->
        <div class="box">
            <div class="box-header">
                <i class="fa fa-user-shield" style="color:var(--sa3);margin-right:8px;"></i>
                <span class="box-title">School Super Admins (<?= count($ssas ?? []) ?>)</span>
            </div>
            <div class="box-body" style="padding:8px 14px 14px;">
                <p style="font-size:12px;color:var(--t3);margin:0 0 10px;">Resets the SSA's password and forces re-auth on every device. They must choose a new password on next login.</p>
                <?php if (empty($ssas)): ?>
                    <div style="font-size:12px;color:var(--t3);padding:10px 4px;">No School Super Admins found for this school.</div>
                <?php else: foreach ($ssas as $s):
                    $isActive = strcasecmp($s['status'], 'Active') === 0;
                ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;background:var(--bg3);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-family:var(--font-m);font-size:12.5px;color:var(--sa3);font-weight:700;"><?= htmlspecialchars($s['id']) ?></div>
                            <div style="font-size:12.5px;color:var(--t1);font-weight:600;"><?= htmlspecialchars($s['name']) ?></div>
                            <?php if ($s['email']): ?>
                                <div style="font-size:11px;color:var(--t3);"><?= htmlspecialchars($s['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="label label-<?= $isActive ? 'success' : 'danger' ?>" style="align-self:flex-start;"><?= htmlspecialchars($s['status'] ?: 'Active') ?></span>
                        <button class="btn btn-warning btn-xs sa-ssa-reset"
                            data-id="<?= htmlspecialchars($s['id']) ?>"
                            data-name="<?= htmlspecialchars($s['name']) ?>">
                            <i class="fa fa-key"></i> Reset
                        </button>
                    </div>
                <?php endforeach; endif; ?>
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

<!-- SSA Reset Password Modal -->
<div class="modal fade" id="saSsaResetModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-color:var(--border);">
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" style="font-family:var(--font-b);color:var(--t1);">
                    <i class="fa fa-key" style="color:var(--sa3);margin-right:8px;"></i>Reset SSA Password
                </h4>
            </div>
            <form id="saSsaResetForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="saSsaResetId" value="">
                    <p style="font-size:12.5px;color:var(--t2);margin-bottom:12px;">
                        Reset password for <strong id="saSsaResetName"></strong>.
                        They will be forced to change it on next login.
                    </p>
                    <div class="form-group">
                        <label style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;">New Password</label>
                        <span style="position:relative;display:block">
                            <input type="password" class="form-control" id="saSsaResetPw" minlength="8" required style="padding-right:40px">
                            <button type="button" aria-label="Show password" title="Show/hide password" onclick="var i=document.getElementById('saSsaResetPw'),s=i.type==='password';i.type=s?'text':'password';this.firstElementChild.className=s?'fa fa-eye-slash':'fa fa-eye';this.setAttribute('aria-label',s?'Hide password':'Show password')" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--t3);cursor:pointer;padding:6px;font-size:14px"><i class="fa fa-eye"></i></button>
                        </span>
                        <small style="color:var(--t3);">Min 8 chars, one upper, one lower, one digit.</small>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fa fa-key"></i> Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

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

    // ── Logo widget — URL field and uploader kept in sync (mirrors create.php) ──
    // The URL field is the submitted truth (logo_url); both paths write to it,
    // so the user can switch between pasting a URL and uploading a file freely.
    // An upload also persists logoUrl server-side immediately (real school);
    // a typed URL is persisted on "Save Profile".
    (function(){
        var $url  = $('#logoUrlInput');
        var $file = $('#logoFileInput');
        var $img  = $('#logoPreview');
        var $wrap = $('#logoPreviewWrap');
        var $err  = $('#logoPreviewErr');
        var $tag  = $('#logoSourceTag');
        var $msg  = $('#logoUploadMsg');
        var $btn  = $('#uploadLogoBtn');

        function isHttp(u){ return /^https?:\/\//i.test(u); }
        function isStored(u){ return /firebasestorage\.googleapis\.com/i.test(u); }
        function labelFor(u){ return isStored(u) ? 'Stored logo' : 'External URL'; }

        function showPreview(u, label){
            $err.hide();
            if(!u || !isHttp(u)){ $wrap.hide(); $tag.text(''); return; }
            $tag.text(label || '');
            $img.attr('src', u);
            $wrap.css('display','flex');
        }
        function clearAll(){
            $url.val(''); $file.val(''); $msg.html('');
            $wrap.hide(); $err.hide(); $tag.text('');
        }

        $img.on('load',  function(){ $err.hide(); });
        $img.on('error', function(){ if($url.val().trim()){ $wrap.hide(); $err.show(); } });

        $url.on('input blur paste', function(){
            setTimeout(function(){
                var u = $url.val().trim();
                $msg.html('');
                if(u){ showPreview(u, labelFor(u)); }
                else { $wrap.hide(); $err.hide(); $tag.text(''); }
            }, 0);
        });

        function doUpload(file){
            if(!file){ return; }
            var fd = new FormData();
            fd.append('logo', file);
            fd.append('school_uid', '<?= addslashes($school_uid) ?>');
            $btn.prop('disabled', true);
            $msg.html('<i class="fa fa-spinner fa-spin"></i>');
            $.ajax({
                url: BASE_URL+'superadmin/schools/upload_logo',
                type:'POST', data:fd, contentType:false, processData:false,
                success:function(r){
                    if(r && r.status==='success'){
                        $url.val(r.logo_url);
                        showPreview(r.logo_url, 'Uploaded');
                        $msg.html('<span style="color:#22c55e;"><i class="fa fa-check"></i> Uploaded</span>');
                        saToast('Logo uploaded.','success');
                    } else {
                        $msg.html('<span style="color:#ef4444;">'+escHtml((r&&r.message)||'Upload failed.')+'</span>');
                        saToast((r&&r.message)||'Upload failed.','error');
                    }
                },
                error:function(){ $msg.html('<span style="color:#ef4444;">Upload failed.</span>'); },
                complete:function(){ $btn.prop('disabled', false); }
            });
        }

        $btn.on('click', function(){
            var file = $file[0].files[0];
            if(!file){ saToast('Select a file first.','error'); return; }
            doUpload(file);
        });
        $file.on('change', function(){ if(this.files[0]) doUpload(this.files[0]); });
        $('#logoClearBtn').on('click', clearAll);

        if($url.val().trim()){ showPreview($url.val().trim(), labelFor($url.val().trim())); }
    })();

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
        // Reason capture (G8). Suspending or deactivating a school revokes
        // access for every parent, teacher and admin in it. That act is now
        // recorded with WHO did it and WHY, and the server refuses it
        // without a reason — so this prompt is a real gate, not a courtesy.
        // Reactivation stays a one-click confirm: restoring access is not
        // the dangerous direction.
        var reason = '';
        if (status === 'suspended' || status === 'inactive') {
            reason = window.prompt(
                'You are about to ' + verb + ' this school.\n\n'
                + 'All parent, teacher and staff access will be blocked, and fee/accounting '
                + 'background jobs will stop for this tenant.\n\n'
                + 'Enter the reason (recorded permanently in the audit trail):', '');
            if (reason === null) return;            // cancelled
            reason = String(reason).trim();
            if (reason.length < 5) {
                saToast('A reason of at least 5 characters is required.', 'error');
                return;
            }
        } else if(!confirm('Are you sure you want to '+verb+' this school?')) {
            return;
        }

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
            data:{ school_uid:'<?= addslashes($school_uid) ?>', status:status, reason:reason },
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

    // ══ INSTITUTION LIFECYCLE CONSOLE ══════════════════════════════
    (function(){
        var SID = '<?= addslashes($school_uid) ?>';
        var state = { current: null, target: null, twoPerson: false, blocking: 0 };

        function esc(s){ return escHtml(s); }

        // Every panel here checks BOTH r.ok and r.status before rendering
        // success. A denied lifecycle action that painted as "done" would be
        // the worst possible failure mode for this console.
        function getJson(url){
            return $.ajax({ url: url, type: 'GET', dataType: 'json' })
                .then(function(r){
                    if (!r || r.status !== 'success') {
                        return $.Deferred().reject(r && r.message ? r.message : 'Request failed').promise();
                    }
                    return r;
                });
        }

        var STATE_NOTE = {
            PROSPECT:   'Record exists; nothing configured yet.',
            ONBOARDING: 'Being configured. Not open for day-to-day use.',
            ACTIVE:     'Operating normally.',
            CLOSING:    'Closure announced. No new admissions; everything else continues.',
            CLOSED:     'Operations ended. Records remain readable.',
            ARCHIVED:   'Retention only. Access is administrator-mediated.'
        };
        var STATE_COLOR = {
            PROSPECT:'#94a3b8', ONBOARDING:'#f59e0b', ACTIVE:'#22c55e',
            CLOSING:'#f97316', CLOSED:'#ef4444', ARCHIVED:'#64748b'
        };

        function badge(text, color){
            return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10.5px;'
                 + 'font-weight:600;color:#fff;background:' + color + ';">' + esc(text) + '</span>';
        }

        function renderStatus(d){
            var inst = d.institution.state;
            state.current = inst;

            $('#saLcState').html('<span style="color:' + (STATE_COLOR[inst]||'#64748b') + ';">' + esc(inst) + '</span>');
            $('#saLcStateNote').text(
                STATE_NOTE[inst] + (d.institution.explicit ? '' : ' (never explicitly set — defaults to ACTIVE)')
            );

            $('#saLcCommercial').text(d.commercial.state || 'unknown');
            $('#saLcCommercialNote').text(
                d.commercial.adminDisabled ? 'Operator toggle: DISABLED' : 'Billing/subscription axis'
            );
            $('#saLcSession').text(d.session.current || '—');

            if (d.effective && d.effective.code && d.effective.code !== 'OK') {
                $('#saLcVerdict').show()
                    .css({background:'#fef3c7', color:'#92400e', border:'1px solid #fde68a'})
                    .html('<i class="fa fa-info-circle"></i> ' + esc(d.effective.message));
            } else { $('#saLcVerdict').hide(); }

            var $n = $('#saLcNext').empty();
            if (!d.nextStates.length) {
                $n.html('<span style="font-size:12px;color:var(--t3);">No transitions available from ' + esc(inst) + '.</span>');
            }
            d.nextStates.forEach(function(ns){
                $('<button class="btn btn-sm btn-default"/>')
                    .html('<i class="fa fa-arrow-right"></i> ' + esc(ns.state)
                        + (ns.twoPerson ? ' <span style="font-size:9.5px;opacity:.75;">(2-person)</span>' : ''))
                    .on('click', function(){ openModal(ns.state, ns.twoPerson); })
                    .appendTo($n);
            });

            var $h = $('#saLcHistory').empty();
            if (!d.history || !d.history.length) {
                $h.html('<span style="color:var(--t3);">No lifecycle changes recorded yet.</span>');
            } else {
                d.history.forEach(function(e){
                    var when = (e.ts || '').replace('T',' ').substring(0,16);
                    $h.append(
                        '<div style="padding:7px 0;border-bottom:1px solid var(--border);">'
                        + badge(e.fromState + ' → ' + e.toState, '#475569')
                        + ' <span style="color:var(--t3);">' + esc(when) + '</span><br>'
                        + '<span style="color:var(--t2);">by ' + esc(e.actorId || '—')
                        + (e.approverId ? ' · approved by ' + esc(e.approverId) : '')
                        + (e.effectiveDate ? ' · effective ' + esc(e.effectiveDate) : '')
                        + (e.outcome && e.outcome !== 'applied' ? ' · ' + badge(e.outcome, '#ef4444') : '')
                        + '</span><br><em style="color:var(--t3);">' + esc(e.reason || '') + '</em></div>'
                    );
                });
            }

            if (inst === 'CLOSING') { $('#saLcClosureWrap').show(); loadClosure(); }
            else { $('#saLcClosureWrap').hide(); }
        }

        function renderReadiness(rd){
            state.blocking = rd.blocking;
            var color = rd.blocking > 0 ? '#ef4444' : (rd.warnings > 0 ? '#f59e0b' : '#22c55e');
            $('#saLcScore').html(rd.score + '% · ' + rd.blocking + ' blocking · ' + rd.warnings + ' warning(s)');
            $('#saLcScoreBar').css({ width: rd.score + '%', background: color });

            var $c = $('#saLcChecks').empty();
            rd.checks.forEach(function(c){
                if (c.status === 'PASS' || c.status === 'NOT_APPLICABLE') return;
                var col = c.status === 'BLOCKING' ? '#ef4444' : '#f59e0b';
                $c.append(
                    '<div style="padding:6px 0;border-bottom:1px dashed var(--border);">'
                    + badge(c.status, col) + ' <strong>' + esc(c.label) + '</strong>'
                    + '<div style="color:var(--t2);margin-top:2px;">' + esc(c.what) + '</div>'
                    + '<div style="color:var(--t3);font-size:11px;">Why: ' + esc(c.why) + '</div>'
                    + (c.fix ? '<div style="font-size:11px;">Fix: ' + esc(c.fix)
                        + (c.url ? ' <a href="' + BASE_URL + esc(c.url) + '" target="_blank">Open</a>' : '')
                        + '</div>' : '')
                    + '</div>'
                );
            });
            if (!$c.children().length) {
                $c.html('<span style="font-size:12px;color:#22c55e;">Everything applicable is configured.</span>');
            }
        }

        function renderClosure(cl){
            var $c = $('#saLcClosure').empty();
            cl.items.forEach(function(i){
                if (i.status === 'CLEAR') return;
                var col = i.status === 'BLOCKING' ? '#ef4444'
                        : (i.status === 'UNKNOWN' ? '#7c3aed' : '#f59e0b');
                $c.append(
                    '<div style="padding:6px 0;border-bottom:1px dashed var(--border);font-size:11.5px;">'
                    + badge(i.status, col) + ' <strong>' + esc(i.label) + '</strong>'
                    + (i.count > 0 ? ' <span style="color:var(--t3);">(' + i.count + ')</span>' : '')
                    + '<div style="color:var(--t2);margin-top:2px;">' + esc(i.what) + '</div>'
                    + (i.fix ? '<div style="font-size:11px;">Fix: ' + esc(i.fix) + '</div>' : '')
                    + '</div>'
                );
            });
            if (!$c.children().length) {
                $c.html('<span style="font-size:12px;color:#22c55e;">Nothing outstanding — this school can be closed.</span>');
            }
        }

        function loadClosure(){
            getJson(BASE_URL + 'superadmin/schools/lifecycle_closure_check/' + SID)
                .then(function(r){ renderClosure(r.closure); })
                .fail(function(m){ $('#saLcClosure').html('<span style="color:#ef4444;">' + esc(m) + '</span>'); });
        }

        function loadAll(){
            $('#saLcLoading').show(); $('#saLcBody').hide();
            $.when(
                getJson(BASE_URL + 'superadmin/schools/lifecycle_status/' + SID),
                getJson(BASE_URL + 'superadmin/schools/lifecycle_readiness/' + SID)
            ).done(function(s, rd){
                renderStatus(s[0] !== undefined ? s[0] : s);
                var rdData = (rd[0] !== undefined ? rd[0] : rd);
                renderReadiness(rdData.readiness);
                $('#saLcLoading').hide(); $('#saLcBody').show();
            }).fail(function(m){
                $('#saLcLoading').html('<span style="color:#ef4444;">Could not load lifecycle: '
                    + esc(typeof m === 'string' ? m : 'request failed') + '</span>');
            });
        }

        function openModal(target, twoPerson){
            state.target = target; state.twoPerson = twoPerson;
            $('#saLcReason').val(''); $('#saLcApprover').val('');
            $('#saLcOverride').prop('checked', false);
            $('#saLcModalErr').hide().text('');
            $('#saLcEffective').val(new Date().toISOString().substring(0,10));
            $('#saLcApproverWrap').toggle(!!twoPerson);

            // Impact preview: say plainly what changes, before it changes.
            var impact = {
                ONBOARDING: 'Admin configuration continues. Parents and teachers cannot log in.',
                ACTIVE:     'Normal school operations resume for everyone.',
                CLOSING:    '<strong>New admissions stop immediately.</strong> Everything else — exams, results, '
                          + 'fee settlement, transport, certificates — continues until the effective date.',
                CLOSED:     '<strong>All operational writes stop.</strong> Records stay readable, and certificates '
                          + 'can still be issued. Scheduled jobs stop except retention. Nothing is deleted.',
                ARCHIVED:   '<strong>Client access ends entirely.</strong> Data is retained and reachable only '
                          + 'through a platform administrator. Nothing is deleted.'
            };
            $('#saLcModalIntro').html('Move this school from <strong>' + esc(state.current)
                + '</strong> to <strong>' + esc(target) + '</strong>.');
            $('#saLcImpact').html('<strong>What this does:</strong><br>' + (impact[target] || ''));

            $('#saLcOverrideWrap').toggle(target === 'CLOSED' || (target === 'ACTIVE' && state.current === 'ONBOARDING'));
            $('#saLcModal').modal('show');
        }

        $('#saLcConfirm').on('click', function(){
            var $btn = $(this).prop('disabled', true).text('Applying…');
            $('#saLcModalErr').hide();
            $.ajax({
                url: BASE_URL + 'superadmin/schools/lifecycle_transition',
                type: 'POST',
                data: {
                    school_uid: SID,
                    to_state: state.target,
                    reason: $('#saLcReason').val(),
                    effective_date: $('#saLcEffective').val(),
                    approver_id: $('#saLcApprover').val(),
                    override: $('#saLcOverride').is(':checked') ? 1 : 0
                },
                dataType: 'json'
            }).done(function(r){
                // Phantom-success guard: a 200 with status:'error' is a denial.
                if (!r || r.status !== 'success') {
                    $('#saLcModalErr').show().text((r && r.message) ? r.message : 'The change was refused.');
                    return;
                }
                $('#saLcModal').modal('hide');
                saToast(r.message, 'success');
                loadAll();
            }).fail(function(xhr){
                var msg = 'The change was refused.';
                try { var j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch(e){}
                $('#saLcModalErr').show().text(msg);
            }).always(function(){
                $btn.prop('disabled', false).text('Apply change');
            });
        });

        $('#saLcRefresh').on('click', loadAll);
        loadAll();
    })();

    // SSA reset — open modal
    $('.sa-ssa-reset').on('click', function(){
        $('#saSsaResetId').val($(this).data('id'));
        $('#saSsaResetName').text($(this).data('id') + ' — ' + $(this).data('name'));
        $('#saSsaResetPw').val('');
        $('#saSsaResetModal').modal('show');
        setTimeout(function(){ $('#saSsaResetPw').focus(); }, 200);
    });

    // SSA reset — submit
    $('#saSsaResetForm').on('submit', function(e){
        e.preventDefault();
        var id = $('#saSsaResetId').val();
        var pw = $('#saSsaResetPw').val();
        if (!pw || pw.length < 8) { saToast('Password must be at least 8 characters.','error'); return; }
        var $btn = $(this).find('button[type=submit]').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Resetting...');
        $.ajax({
            url: BASE_URL+'superadmin/schools/reset_ssa_password', type:'POST',
            data: { school_uid:'<?= addslashes($school_uid) ?>', ssa_id:id, new_password:pw },
            success: function(r){
                saToast(r.message || (r.status==='success'?'Reset done.':'Error'), r.status);
                if (r.status==='success') $('#saSsaResetModal').modal('hide');
            },
            error: function(xhr){
                var m = 'Server error.';
                try { var j = JSON.parse(xhr.responseText); if (j && j.message) m = j.message; } catch(_) {}
                saToast(m, 'error');
            },
            complete: function(){ $btn.prop('disabled',false).html('<i class="fa fa-key"></i> Reset'); }
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
