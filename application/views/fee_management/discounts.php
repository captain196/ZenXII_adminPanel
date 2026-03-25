<div class="content-wrapper">
<div class="fm-wrap">
  <!-- Top Bar -->
  <div class="fm-topbar">
    <div class="fm-topbar-left">
      <h1 class="fm-page-title">Discount Management</h1>
      <nav class="fm-breadcrumb">
        <a href="<?= base_url('dashboard') ?>">Dashboard</a>
        <span class="fm-bc-sep">/</span>
        <a href="<?= base_url('fee_management') ?>">Fee Management</a>
        <span class="fm-bc-sep">/</span>
        <span>Discounts</span>
      </nav>
    </div>
  </div>

  <!-- Stats Row -->
  <div class="fm-stats-row">
    <div class="fm-stat-card">
      <div class="fm-stat-icon fm-stat-icon--total"><i class="fa fa-tags"></i></div>
      <div class="fm-stat-body">
        <span class="fm-stat-val" id="statTotal">0</span>
        <span class="fm-stat-label">Total Policies</span>
      </div>
    </div>
    <div class="fm-stat-card">
      <div class="fm-stat-icon fm-stat-icon--active"><i class="fa fa-check-circle"></i></div>
      <div class="fm-stat-body">
        <span class="fm-stat-val" id="statActive">0</span>
        <span class="fm-stat-label">Active Policies</span>
      </div>
    </div>
    <div class="fm-stat-card">
      <div class="fm-stat-icon fm-stat-icon--pct"><i class="fa fa-percent"></i></div>
      <div class="fm-stat-body">
        <span class="fm-stat-val" id="statPct">0</span>
        <span class="fm-stat-label">Percentage-based</span>
      </div>
    </div>
    <div class="fm-stat-card">
      <div class="fm-stat-icon fm-stat-icon--fixed"><i class="fa fa-inr"></i></div>
      <div class="fm-stat-body">
        <span class="fm-stat-val" id="statFixed">0</span>
        <span class="fm-stat-label">Fixed Amount</span>
      </div>
    </div>
  </div>

  <!-- Add / Edit Form -->
  <div class="fm-card">
    <div class="fm-card-hdr">
      <h2 class="fm-card-title" id="formTitle"><i class="fa fa-plus-circle"></i> Add Discount Policy</h2>
      <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" id="btnResetForm" style="display:none" onclick="resetForm()">
        <i class="fa fa-times"></i> Cancel Edit
      </button>
    </div>
    <form id="discountForm" autocomplete="off" novalidate>
      <input type="hidden" id="editId" name="id" value="">
      <div class="fm-form-grid">
        <!-- Row 1 -->
        <div class="fm-field">
          <label class="fm-label" for="policyName">Policy Name <span class="fm-req">*</span></label>
          <input type="text" class="fm-input" id="policyName" name="policy_name" placeholder="e.g. Sibling 10% Off" required maxlength="100">
        </div>
        <div class="fm-field">
          <label class="fm-label" for="discountType">Discount Type <span class="fm-req">*</span></label>
          <select class="fm-select" id="discountType" name="discount_type" required>
            <option value="">Select Type</option>
            <option value="percentage">Percentage</option>
            <option value="fixed">Fixed Amount</option>
          </select>
        </div>
        <div class="fm-field">
          <label class="fm-label" for="discountValue">Value <span class="fm-req">*</span></label>
          <div class="fm-input-group">
            <span class="fm-input-addon" id="valuePrefix" style="display:none">&rupee;</span>
            <input type="number" class="fm-input" id="discountValue" name="value" placeholder="0" required min="0" step="0.01">
            <span class="fm-input-addon" id="valueSuffix" style="display:none">%</span>
          </div>
        </div>

        <!-- Row 2 -->
        <div class="fm-field">
          <label class="fm-label" for="criteria">Criteria <span class="fm-req">*</span></label>
          <select class="fm-select" id="criteria" name="criteria" required>
            <option value="">Select Criteria</option>
            <option value="sibling">Sibling Discount</option>
            <option value="early_bird">Early Bird</option>
            <option value="merit">Merit Based</option>
            <option value="staff_ward">Staff Ward</option>
            <option value="custom">Custom</option>
          </select>
        </div>
        <div class="fm-field">
          <label class="fm-label" for="maxCap">Max Discount Amount</label>
          <div class="fm-input-group">
            <span class="fm-input-addon">&rupee;</span>
            <input type="number" class="fm-input" id="maxCap" name="max_cap" placeholder="Optional cap" min="0" step="1">
          </div>
          <span class="fm-hint">Caps percentage-based discounts. Leave blank for no cap.</span>
        </div>
        <div class="fm-field fm-field--toggle">
          <label class="fm-label">Status</label>
          <label class="fm-toggle">
            <input type="checkbox" id="isActive" name="is_active" value="1" checked>
            <span class="fm-toggle-track"></span>
            <span class="fm-toggle-label" id="toggleLabel">Active</span>
          </label>
        </div>
      </div>

      <!-- Applicable Categories -->
      <div class="fm-field fm-field--wide">
        <label class="fm-label">Applicable Categories</label>
        <div class="fm-check-grid" id="categoriesGrid">
          <?php if (!empty($categories)): ?>
            <?php foreach ($categories as $cat): ?>
              <?php $catName = is_object($cat) ? $cat->name : (is_array($cat) ? $cat['name'] : $cat); ?>
              <label class="fm-check-item">
                <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($catName) ?>">
                <span class="fm-check-box"><i class="fa fa-check"></i></span>
                <span class="fm-check-text"><?= htmlspecialchars($catName) ?></span>
              </label>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="fm-hint">No categories configured.</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Applicable Fee Titles -->
      <div class="fm-field fm-field--wide">
        <label class="fm-label">Applicable Fee Titles</label>
        <div class="fm-check-sections" id="feeTitlesGrid">
          <?php if (!empty($feesStructure)): ?>
            <?php foreach ($feesStructure as $group => $titles): ?>
              <?php if (!empty($titles)): ?>
                <div class="fm-check-section">
                  <div class="fm-check-section-hdr"><?= htmlspecialchars($group) ?></div>
                  <div class="fm-check-grid">
                    <?php foreach ($titles as $title => $val): ?>
                      <label class="fm-check-item">
                        <input type="checkbox" name="fee_titles[]" value="<?= htmlspecialchars($group . '/' . $title) ?>">
                        <span class="fm-check-box"><i class="fa fa-check"></i></span>
                        <span class="fm-check-text"><?= htmlspecialchars($title) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="fm-hint">No fee structure configured.</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Actions -->
      <div class="fm-form-actions">
        <button type="submit" class="fm-btn fm-btn--primary" id="btnSave">
          <i class="fa fa-save"></i> Save Policy
        </button>
        <button type="button" class="fm-btn fm-btn--ghost" onclick="resetForm()">
          <i class="fa fa-refresh"></i> Reset
        </button>
      </div>
    </form>
  </div>

  <!-- Policies Table -->
  <div class="fm-card">
    <div class="fm-card-hdr">
      <h2 class="fm-card-title"><i class="fa fa-list"></i> Discount Policies</h2>
      <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" onclick="loadDiscounts()">
        <i class="fa fa-refresh"></i> Refresh
      </button>
    </div>
    <div class="fm-table-wrap">
      <div id="tableLoader" class="fm-loader" style="display:none">
        <div class="fm-spinner"></div>
        <span>Loading policies...</span>
      </div>
      <table class="fm-table" id="discountsTable">
        <thead>
          <tr>
            <th width="45">S.No</th>
            <th>Policy Name</th>
            <th width="100">Type</th>
            <th width="90">Value</th>
            <th width="110">Criteria</th>
            <th>Applicable To</th>
            <th width="90">Max Cap</th>
            <th width="80">Status</th>
            <th width="140">Actions</th>
          </tr>
        </thead>
        <tbody id="discountsBody">
          <tr class="fm-empty-row">
            <td colspan="9">
              <div class="fm-empty">
                <i class="fa fa-inbox"></i>
                <p>No discount policies found.</p>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div><!-- /.content-wrapper -->

<!-- Apply Discount Modal -->
<div class="modal fade" id="applyModal" tabindex="-1" role="dialog" aria-labelledby="applyModalLabel">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content fm-modal">
      <div class="fm-modal-hdr">
        <h3 class="fm-modal-title" id="applyModalLabel"><i class="fa fa-user-plus"></i> Apply Discount</h3>
        <button type="button" class="fm-modal-close" data-dismiss="modal" aria-label="Close"><i class="fa fa-times"></i></button>
      </div>
      <div class="fm-modal-body">
        <div class="fm-modal-info" id="modalPolicyInfo"></div>
        <div id="modalLoader" class="fm-loader" style="display:none">
          <div class="fm-spinner"></div>
          <span>Loading eligible students...</span>
        </div>
        <div id="studentListWrap" style="display:none">
          <div class="fm-modal-toolbar">
            <label class="fm-check-item fm-check-item--all">
              <input type="checkbox" id="selectAll">
              <span class="fm-check-box"><i class="fa fa-check"></i></span>
              <span class="fm-check-text">Select All</span>
            </label>
            <span class="fm-selected-count" id="selectedCount">0 selected</span>
          </div>
          <div class="fm-student-list" id="studentList"></div>
        </div>
        <div id="noStudents" class="fm-empty" style="display:none">
          <i class="fa fa-users"></i>
          <p>No eligible students found for this policy.</p>
        </div>
      </div>
      <div class="fm-modal-footer">
        <button type="button" class="fm-btn fm-btn--ghost" data-dismiss="modal">Cancel</button>
        <button type="button" class="fm-btn fm-btn--primary" id="btnApplyDiscount" disabled onclick="applyDiscount()">
          <i class="fa fa-check"></i> Apply to Selected
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="fmToast" class="fm-toast"></div>

<style>
/* === Discount Management — fm-* namespace === */
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,600&display=swap');

:root {
  --fm-navy: var(--t1, #0f1f3d);
  --fm-navy2: var(--t2, #1a2d52);
  --fm-teal: var(--gold, #0f766e);
  --fm-teal-dim: var(--gold-dim, rgba(13,115,119,.10));
  --fm-teal-ring: var(--gold-ring, rgba(13,115,119,.22));
  --fm-sky: var(--gold-dim, rgba(15,118,110,.10));
  --fm-gold: #d97706;
  --fm-red: #E05C6F;
  --fm-green: #15803d;
  --fm-bg: var(--bg, #f0f4f8);
  --fm-white: var(--bg2, #ffffff);
  --fm-border: var(--border, #dce3ed);
  --fm-t1: var(--t1, #0f1f3d);
  --fm-t2: var(--t2, #475569);
  --fm-t3: var(--t3, #94a3b8);
  --fm-shadow: var(--sh, 0 1px 3px rgba(15,31,61,.08));
  --fm-shadow-lg: 0 4px 16px rgba(15,31,61,.10);
  --fm-radius: var(--r-sm, 8px);
  --fm-radius-sm: 5px;
  --fm-font: 'Plus Jakarta Sans', var(--font-b, sans-serif);
  --fm-font-h: 'Fraunces', serif;
  --fm-ease: cubic-bezier(.4,0,.2,1);
}

/* Wrap */
.fm-wrap { max-width: 1280px; margin: 0 auto; padding: 20px 24px 40px; font-family: var(--fm-font); color: var(--fm-t1); }

/* Top Bar */
.fm-topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.fm-page-title { font-family: var(--fm-font-h); font-size: 1.3rem; font-weight: 600; color: var(--fm-navy); margin: 0; }
.fm-breadcrumb { font-size: .78rem; color: var(--fm-t3); margin-top: 2px; }
.fm-breadcrumb a { color: var(--fm-teal); text-decoration: none; }
.fm-breadcrumb a:hover { text-decoration: underline; }
.fm-bc-sep { margin: 0 5px; }

/* Stats */
.fm-stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
.fm-stat-card { background: var(--fm-white); border: 1px solid var(--fm-border); border-radius: var(--fm-radius); padding: 14px 16px; display: flex; align-items: center; gap: 12px; box-shadow: var(--fm-shadow); }
.fm-stat-icon { width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.fm-stat-icon--total { background: var(--fm-teal-dim); color: var(--fm-teal); }
.fm-stat-icon--active { background: rgba(39,174,96,.10); color: var(--fm-green); }
.fm-stat-icon--pct { background: rgba(217,119,6,.10); color: var(--fm-gold); }
.fm-stat-icon--fixed { background: rgba(15,31,61,.07); color: var(--fm-navy); }
.fm-stat-body { display: flex; flex-direction: column; }
.fm-stat-val { font-size: 1.25rem; font-weight: 700; line-height: 1.1; color: var(--fm-navy); }
.fm-stat-label { font-size: 12.5px; color: var(--fm-t3); font-weight: 500; margin-top: 1px; }

/* Card */
.fm-card { background: var(--fm-white); border: 1px solid var(--fm-border); border-radius: var(--fm-radius); box-shadow: var(--fm-shadow); margin-bottom: 20px; }
.fm-card-hdr { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--fm-border); }
.fm-card-title { font-family: var(--fm-font-h); font-size: 15px; font-weight: 600; margin: 0; color: var(--fm-navy); }
.fm-card-title i { margin-right: 6px; color: var(--fm-teal); font-size: 14px; }

/* Form */
.fm-form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 18px; padding: 18px; }
.fm-field { display: flex; flex-direction: column; }
.fm-field--wide { padding: 0 18px 14px; }
.fm-field--toggle { justify-content: flex-start; }
.fm-label { font-size: .73rem; font-weight: 600; color: var(--fm-t2); margin-bottom: 5px; letter-spacing: .02em; }
.fm-req { color: var(--fm-red); }
.fm-hint { font-size: 12.5px; color: var(--fm-t3); margin-top: 3px; }
.fm-input, .fm-select { height: 36px; padding: 0 10px; font-size: 13px; font-family: var(--fm-font); color: var(--fm-t1); background: var(--fm-white); border: 1px solid var(--fm-border); border-radius: var(--fm-radius-sm); outline: none; transition: border-color .2s var(--fm-ease), box-shadow .2s var(--fm-ease); width: 100%; }
.fm-input:focus, .fm-select:focus { border-color: var(--fm-teal); box-shadow: 0 0 0 3px var(--fm-teal-ring); }
.fm-input.fm-invalid, .fm-select.fm-invalid { border-color: var(--fm-red); box-shadow: 0 0 0 3px rgba(229,62,62,.15); }
.fm-input-group { display: flex; align-items: stretch; }
.fm-input-group .fm-input { border-radius: 0; flex: 1; min-width: 0; }
.fm-input-group .fm-input:first-child { border-radius: var(--fm-radius-sm) 0 0 var(--fm-radius-sm); }
.fm-input-group .fm-input:last-child { border-radius: 0 var(--fm-radius-sm) var(--fm-radius-sm) 0; }
.fm-input-group .fm-input:only-child { border-radius: var(--fm-radius-sm); }
.fm-input-addon { display: flex; align-items: center; justify-content: center; padding: 0 10px; font-size: 13px; font-weight: 600; color: var(--fm-t2); background: var(--fm-sky); border: 1px solid var(--fm-border); white-space: nowrap; }
.fm-input-addon:first-child { border-right: 0; border-radius: var(--fm-radius-sm) 0 0 var(--fm-radius-sm); }
.fm-input-addon:last-child { border-left: 0; border-radius: 0 var(--fm-radius-sm) var(--fm-radius-sm) 0; }

/* Toggle */
.fm-toggle { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; }
.fm-toggle input { display: none; }
.fm-toggle-track { width: 36px; height: 20px; background: var(--fm-border); border-radius: 10px; position: relative; transition: background .2s var(--fm-ease); flex-shrink: 0; }
.fm-toggle-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: var(--fm-white); border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,.15); transition: transform .2s var(--fm-ease); }
.fm-toggle input:checked + .fm-toggle-track { background: var(--fm-green); }
.fm-toggle input:checked + .fm-toggle-track::after { transform: translateX(16px); }
.fm-toggle-label { font-size: 12px; font-weight: 600; color: var(--fm-t2); }

/* Checkboxes Grid */
.fm-check-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.fm-check-section { margin-bottom: 10px; }
.fm-check-section-hdr { font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--fm-teal); margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px dashed var(--fm-border); }
.fm-check-sections { display: flex; flex-direction: column; gap: 4px; }
.fm-check-item { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none; padding: 5px 10px; border: 1px solid var(--fm-border); border-radius: var(--fm-radius-sm); background: var(--fm-white); transition: all .15s var(--fm-ease); font-size: 12px; }
.fm-check-item:hover { border-color: var(--fm-teal); background: var(--fm-teal-dim); }
.fm-check-item input { display: none; }
.fm-check-box { width: 16px; height: 16px; border: 1.5px solid var(--fm-border); border-radius: 3px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: transparent; transition: all .15s var(--fm-ease); flex-shrink: 0; }
.fm-check-item input:checked ~ .fm-check-box { background: var(--fm-teal); border-color: var(--fm-teal); color: #fff; }
.fm-check-item input:checked ~ .fm-check-text { color: var(--fm-teal); font-weight: 600; }
.fm-check-text { color: var(--fm-t2); }

/* Form Actions */
.fm-form-actions { display: flex; gap: 10px; padding: 0 18px 18px; }

/* Buttons */
.fm-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: .8rem; font-weight: 600; font-family: var(--fm-font); border: none; border-radius: var(--fm-radius-sm); cursor: pointer; transition: all .2s var(--fm-ease); outline: none; white-space: nowrap; }
.fm-btn--primary { background: var(--fm-teal); color: #fff; }
.fm-btn--primary:hover { background: #0a5f62; box-shadow: 0 2px 8px rgba(13,115,119,.25); }
.fm-btn--primary:disabled { opacity: .5; cursor: not-allowed; }
.fm-btn--ghost { background: transparent; color: var(--fm-t2); border: 1px solid var(--fm-border); }
.fm-btn--ghost:hover { border-color: var(--fm-teal); color: var(--fm-teal); background: var(--fm-teal-dim); }
.fm-btn--sm { padding: 5px 10px; font-size: 12px; }
.fm-btn--danger { background: var(--fm-red); color: #fff; }
.fm-btn--danger:hover { background: #c53030; }
.fm-btn--gold { background: var(--fm-gold); color: #fff; }
.fm-btn--gold:hover { background: #b45309; }
.fm-btn--icon { width: 30px; height: 30px; padding: 0; justify-content: center; border-radius: var(--fm-radius-sm); }

/* Table */
.fm-table-wrap { overflow-x: auto; position: relative; }
.fm-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.fm-table thead th { background: var(--fm-sky); padding: 9px 12px; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--fm-t2); text-align: left; border-bottom: 2px solid var(--fm-border); white-space: nowrap; }
.fm-table tbody td { padding: 9px 12px; border-bottom: 1px solid var(--fm-border); vertical-align: middle; color: var(--fm-t1); }
.fm-table tbody tr:hover { background: rgba(13,115,119,.03); }
.fm-table .fm-name-cell { font-weight: 600; }

/* Badges */
.fm-badge { display: inline-block; padding: 2px 8px; font-size: .68rem; font-weight: 600; border-radius: 10px; line-height: 1.5; white-space: nowrap; }
.fm-badge--pct { background: rgba(217,119,6,.10); color: var(--fm-gold); }
.fm-badge--fixed { background: rgba(15,31,61,.07); color: var(--fm-navy); }
.fm-badge--active { background: rgba(39,174,96,.10); color: var(--fm-green); }
.fm-badge--inactive { background: rgba(229,62,62,.08); color: var(--fm-red); }
.fm-badge--sibling { background: rgba(13,115,119,.10); color: var(--fm-teal); }
.fm-badge--early_bird { background: rgba(217,119,6,.10); color: var(--fm-gold); }
.fm-badge--merit { background: rgba(39,174,96,.10); color: var(--fm-green); }
.fm-badge--staff_ward { background: rgba(15,31,61,.08); color: var(--fm-navy2); }
.fm-badge--custom { background: rgba(148,163,184,.15); color: var(--fm-t2); }

/* Actions Cell */
.fm-actions { display: flex; gap: 4px; }

/* Empty State */
.fm-empty { text-align: center; padding: 32px 16px; color: var(--fm-t3); }
.fm-empty i { font-size: 32px; margin-bottom: 8px; display: block; opacity: .4; }
.fm-empty p { margin: 0; font-size: 13px; }
.fm-empty-row td { border-bottom: none !important; }

/* Loader */
.fm-loader { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 32px 16px; gap: 10px; color: var(--fm-t3); font-size: 13px; }
.fm-spinner { width: 28px; height: 28px; border: 3px solid var(--fm-border); border-top-color: var(--fm-teal); border-radius: 50%; animation: fmSpin .7s linear infinite; }
@keyframes fmSpin { to { transform: rotate(360deg); } }

/* Applicable-to cell */
.fm-applicable { display: flex; flex-wrap: wrap; gap: 3px; max-width: 220px; }
.fm-applicable-tag { display: inline-block; padding: 1px 6px; font-size: 10px; background: var(--fm-sky); color: var(--fm-teal); border-radius: 3px; white-space: nowrap; }
.fm-applicable-more { font-size: 10px; color: var(--fm-t3); font-weight: 600; cursor: default; }

/* === Modal === */
.fm-modal { border: none; border-radius: var(--fm-radius); overflow: hidden; font-family: var(--fm-font); }
.fm-modal-hdr { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; background: var(--fm-teal); color: #fff; }
.fm-modal-title { font-family: var(--fm-font-h); font-size: 15px; font-weight: 600; margin: 0; }
.fm-modal-title i { margin-right: 6px; }
.fm-modal-close { background: none; border: none; color: rgba(255,255,255,.6); font-size: 16px; cursor: pointer; padding: 4px; }
.fm-modal-close:hover { color: #fff; }
.fm-modal-body { padding: 16px 18px; max-height: 460px; overflow-y: auto; }
.fm-modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--fm-border); }
.fm-modal-info { font-size: 13px; color: var(--fm-t2); padding: 10px 12px; background: var(--fm-sky); border-radius: var(--fm-radius-sm); margin-bottom: 14px; line-height: 1.5; }
.fm-modal-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid var(--fm-border); }
.fm-check-item--all { font-weight: 600; }
.fm-selected-count { font-size: 12px; color: var(--fm-t3); font-weight: 500; }

/* Student list */
.fm-student-list { display: flex; flex-direction: column; gap: 4px; }
.fm-student-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--fm-border); border-radius: var(--fm-radius-sm); transition: all .15s var(--fm-ease); }
.fm-student-row:hover { border-color: var(--fm-teal); background: var(--fm-teal-dim); }
.fm-student-row input[type="checkbox"] { accent-color: var(--fm-teal); width: 15px; height: 15px; cursor: pointer; flex-shrink: 0; }
.fm-student-name { font-size: 13px; font-weight: 600; color: var(--fm-t1); }
.fm-student-meta { font-size: 12.5px; color: var(--fm-t3); margin-left: auto; }

/* Toast */
.fm-toast { position: fixed; bottom: 24px; right: 24px; min-width: 260px; max-width: 380px; padding: 12px 16px; border-radius: var(--fm-radius); font-family: var(--fm-font); font-size: 13px; font-weight: 500; color: #fff; opacity: 0; transform: translateY(12px); transition: all .3s var(--fm-ease); z-index: 99999; pointer-events: none; box-shadow: var(--fm-shadow-lg); }
.fm-toast.fm-toast--show { opacity: 1; transform: translateY(0); pointer-events: auto; }
.fm-toast--success { background: var(--fm-green); }
.fm-toast--error { background: var(--fm-red); }
.fm-toast--info { background: var(--fm-teal); }

/* Responsive */
@media (max-width: 1024px) {
  .fm-form-grid { grid-template-columns: repeat(2, 1fr); }
  .fm-stats-row { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 767px) {
  .fm-wrap { padding: 14px 12px 32px; }
  .fm-form-grid { grid-template-columns: 1fr; }
  .fm-stats-row { grid-template-columns: 1fr 1fr; }
  .fm-page-title { font-size: 18px; }
  .fm-table { font-size: 12px; }
  .fm-table thead th, .fm-table tbody td { padding: 7px 8px; }
}
@media (max-width: 479px) {
  .fm-stats-row { grid-template-columns: 1fr; }
  .fm-form-actions { flex-direction: column; }
  .fm-form-actions .fm-btn { width: 100%; justify-content: center; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var BASE = '<?= base_url() ?>';
  var csrfName = '<?= $this->security->get_csrf_token_name() ?>';
  var csrfHash = '<?= $this->security->get_csrf_hash() ?>';
  var currentApplyId = null;

  // CSRF refresh helper
  function csrf() { var o = {}; o[csrfName] = csrfHash; return o; }
  function updateCsrf(data) {
    if (data && data.csrf_hash) csrfHash = data.csrf_hash;
    if (data && data[csrfName]) csrfHash = data[csrfName];
  }

  // Init
  loadDiscounts();
  bindEvents();

  function bindEvents() {
    document.getElementById('discountForm').addEventListener('submit', saveDiscount);
    document.getElementById('discountType').addEventListener('change', toggleValueDisplay);
    document.getElementById('isActive').addEventListener('change', function() {
      document.getElementById('toggleLabel').textContent = this.checked ? 'Active' : 'Inactive';
    });
    document.getElementById('selectAll').addEventListener('change', function() {
      var checks = document.querySelectorAll('#studentList input[type="checkbox"]');
      for (var i = 0; i < checks.length; i++) checks[i].checked = this.checked;
      updateSelectedCount();
    });
  }

  function toggleValueDisplay() {
    var type = document.getElementById('discountType').value;
    var prefix = document.getElementById('valuePrefix');
    var suffix = document.getElementById('valueSuffix');
    var input = document.getElementById('discountValue');
    prefix.style.display = 'none';
    suffix.style.display = 'none';
    if (type === 'percentage') {
      suffix.style.display = 'flex';
      input.setAttribute('max', '100');
      input.setAttribute('placeholder', '0');
    } else if (type === 'fixed') {
      prefix.style.display = 'flex';
      input.removeAttribute('max');
      input.setAttribute('placeholder', '0');
    }
  }

  // ---- Load Discounts ----
  window.loadDiscounts = function() {
    var tbody = document.getElementById('discountsBody');
    var loader = document.getElementById('tableLoader');
    loader.style.display = 'flex';
    tbody.innerHTML = '';

    $.ajax({
      url: BASE + 'fee_management/fetch_discounts',
      type: 'GET',
      dataType: 'json',
      success: function(res) {
        loader.style.display = 'none';
        updateCsrf(res);
        if (!res.status || !res.discounts || res.discounts.length === 0) {
          tbody.innerHTML = '<tr class="fm-empty-row"><td colspan="9"><div class="fm-empty"><i class="fa fa-inbox"></i><p>No discount policies found.</p></div></td></tr>';
          updateStats([]);
          return;
        }
        updateStats(res.discounts);
        var html = '';
        for (var i = 0; i < res.discounts.length; i++) {
          var d = res.discounts[i];
          html += renderRow(i + 1, d);
        }
        tbody.innerHTML = html;
      },
      error: function() {
        loader.style.display = 'none';
        tbody.innerHTML = '<tr class="fm-empty-row"><td colspan="9"><div class="fm-empty"><i class="fa fa-exclamation-triangle"></i><p>Failed to load policies.</p></div></td></tr>';
        showToast('Failed to load discount policies.', 'error');
      }
    });
  };

  function renderRow(num, d) {
    var typeBadge = d.discount_type === 'percentage'
      ? '<span class="fm-badge fm-badge--pct">Percentage</span>'
      : '<span class="fm-badge fm-badge--fixed">Fixed</span>';
    var valueStr = d.discount_type === 'percentage' ? d.value + '%' : '\u20B9' + parseFloat(d.value).toLocaleString('en-IN');
    var critBadge = '<span class="fm-badge fm-badge--' + d.criteria + '">' + formatCriteria(d.criteria) + '</span>';
    var statusBadge = d.is_active
      ? '<span class="fm-badge fm-badge--active">Active</span>'
      : '<span class="fm-badge fm-badge--inactive">Inactive</span>';
    var capStr = d.max_cap ? '\u20B9' + parseFloat(d.max_cap).toLocaleString('en-IN') : '<span style="color:var(--fm-t3)">—</span>';

    var applicable = buildApplicableTags(d);

    return '<tr>' +
      '<td>' + num + '</td>' +
      '<td class="fm-name-cell">' + escHtml(d.policy_name) + '</td>' +
      '<td>' + typeBadge + '</td>' +
      '<td>' + valueStr + '</td>' +
      '<td>' + critBadge + '</td>' +
      '<td>' + applicable + '</td>' +
      '<td>' + capStr + '</td>' +
      '<td>' + statusBadge + '</td>' +
      '<td><div class="fm-actions">' +
        '<button class="fm-btn fm-btn--ghost fm-btn--icon" title="Edit" onclick="editDiscount(\'' + d.id + '\')"><i class="fa fa-pencil"></i></button>' +
        '<button class="fm-btn fm-btn--icon fm-btn--danger" title="Delete" onclick="deleteDiscount(\'' + d.id + '\')"><i class="fa fa-trash"></i></button>' +
        '<button class="fm-btn fm-btn--icon fm-btn--gold" title="Apply" onclick="showApplyModal(\'' + d.id + '\')"><i class="fa fa-user-plus"></i></button>' +
      '</div></td>' +
      '</tr>';
  }

  function buildApplicableTags(d) {
    var items = [];
    if (d.categories && d.categories.length) {
      for (var i = 0; i < d.categories.length && i < 3; i++) {
        items.push('<span class="fm-applicable-tag">' + escHtml(d.categories[i]) + '</span>');
      }
      if (d.categories.length > 3) items.push('<span class="fm-applicable-more">+' + (d.categories.length - 3) + '</span>');
    }
    if (d.fee_titles && d.fee_titles.length) {
      for (var j = 0; j < d.fee_titles.length && j < 2; j++) {
        var label = d.fee_titles[j].split('/').pop();
        items.push('<span class="fm-applicable-tag">' + escHtml(label) + '</span>');
      }
      if (d.fee_titles.length > 2) items.push('<span class="fm-applicable-more">+' + (d.fee_titles.length - 2) + '</span>');
    }
    if (!items.length) return '<span style="color:var(--fm-t3)">All</span>';
    return '<div class="fm-applicable">' + items.join('') + '</div>';
  }

  function updateStats(list) {
    var total = list.length, active = 0, pct = 0, fixed = 0;
    for (var i = 0; i < list.length; i++) {
      if (list[i].is_active) active++;
      if (list[i].discount_type === 'percentage') pct++;
      else fixed++;
    }
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statActive').textContent = active;
    document.getElementById('statPct').textContent = pct;
    document.getElementById('statFixed').textContent = fixed;
  }

  function formatCriteria(c) {
    var map = { sibling: 'Sibling', early_bird: 'Early Bird', merit: 'Merit', staff_ward: 'Staff Ward', custom: 'Custom' };
    return map[c] || c;
  }

  // ---- Save ----
  function saveDiscount(e) {
    e.preventDefault();
    var form = document.getElementById('discountForm');

    // Validate required fields
    var name = document.getElementById('policyName');
    var type = document.getElementById('discountType');
    var val = document.getElementById('discountValue');
    var crit = document.getElementById('criteria');
    var valid = true;

    [name, type, val, crit].forEach(function(el) {
      el.classList.remove('fm-invalid');
      if (!el.value.trim()) { el.classList.add('fm-invalid'); valid = false; }
    });

    if (type.value === 'percentage' && parseFloat(val.value) > 100) {
      val.classList.add('fm-invalid');
      showToast('Percentage cannot exceed 100%.', 'error');
      return;
    }

    if (!valid) { showToast('Please fill all required fields.', 'error'); return; }

    var payload = csrf();
    payload.id = document.getElementById('editId').value;
    payload.policy_name = name.value.trim();
    payload.discount_type = type.value;
    payload.value = val.value;
    payload.criteria = crit.value;
    payload.max_cap = document.getElementById('maxCap').value || '';
    payload.is_active = document.getElementById('isActive').checked ? '1' : '0';

    // Gather checked categories
    payload.categories = [];
    var catChecks = document.querySelectorAll('input[name="categories[]"]:checked');
    for (var i = 0; i < catChecks.length; i++) payload.categories.push(catChecks[i].value);

    // Gather checked fee titles
    payload.fee_titles = [];
    var ftChecks = document.querySelectorAll('input[name="fee_titles[]"]:checked');
    for (var j = 0; j < ftChecks.length; j++) payload.fee_titles.push(ftChecks[j].value);

    var btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    $.ajax({
      url: BASE + 'fee_management/save_discount',
      type: 'POST',
      data: payload,
      dataType: 'json',
      traditional: true,
      success: function(res) {
        updateCsrf(res);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save Policy';
        if (res.status) {
          showToast(res.message || 'Discount policy saved.', 'success');
          resetForm();
          loadDiscounts();
        } else {
          showToast(res.message || 'Failed to save policy.', 'error');
        }
      },
      error: function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save Policy';
        showToast('Server error. Please try again.', 'error');
      }
    });
  }

  // ---- Edit ----
  window.editDiscount = function(id) {
    $.ajax({
      url: BASE + 'fee_management/fetch_discounts',
      type: 'GET',
      dataType: 'json',
      success: function(res) {
        updateCsrf(res);
        if (!res.status || !res.discounts) return showToast('Failed to load policy.', 'error');
        var d = null;
        for (var i = 0; i < res.discounts.length; i++) {
          if (res.discounts[i].id === id) { d = res.discounts[i]; break; }
        }
        if (!d) return showToast('Policy not found.', 'error');

        document.getElementById('editId').value = d.id;
        document.getElementById('policyName').value = d.policy_name;
        document.getElementById('discountType').value = d.discount_type;
        toggleValueDisplay();
        document.getElementById('discountValue').value = d.value;
        document.getElementById('criteria').value = d.criteria;
        document.getElementById('maxCap').value = d.max_cap || '';
        document.getElementById('isActive').checked = !!d.is_active;
        document.getElementById('toggleLabel').textContent = d.is_active ? 'Active' : 'Inactive';

        // Check categories
        var catChecks = document.querySelectorAll('input[name="categories[]"]');
        for (var c = 0; c < catChecks.length; c++) {
          catChecks[c].checked = d.categories && d.categories.indexOf(catChecks[c].value) !== -1;
        }

        // Check fee titles
        var ftChecks = document.querySelectorAll('input[name="fee_titles[]"]');
        for (var f = 0; f < ftChecks.length; f++) {
          ftChecks[f].checked = d.fee_titles && d.fee_titles.indexOf(ftChecks[f].value) !== -1;
        }

        document.getElementById('formTitle').innerHTML = '<i class="fa fa-pencil"></i> Edit Discount Policy';
        document.getElementById('btnResetForm').style.display = '';
        document.getElementById('policyName').focus();
        document.querySelector('.fm-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
      },
      error: function() { showToast('Failed to load policy data.', 'error'); }
    });
  };

  // ---- Delete ----
  window.deleteDiscount = function(id) {
    if (!confirm('Are you sure you want to delete this discount policy?')) return;
    var payload = csrf();
    payload.id = id;
    $.ajax({
      url: BASE + 'fee_management/delete_discount',
      type: 'POST',
      data: payload,
      dataType: 'json',
      success: function(res) {
        updateCsrf(res);
        if (res.status) {
          showToast(res.message || 'Policy deleted.', 'success');
          loadDiscounts();
        } else {
          showToast(res.message || 'Failed to delete policy.', 'error');
        }
      },
      error: function() { showToast('Server error. Please try again.', 'error'); }
    });
  };

  // ---- Apply Modal ----
  window.showApplyModal = function(id) {
    currentApplyId = id;
    document.getElementById('modalPolicyInfo').textContent = 'Loading policy details...';
    document.getElementById('studentListWrap').style.display = 'none';
    document.getElementById('noStudents').style.display = 'none';
    document.getElementById('modalLoader').style.display = 'none';
    document.getElementById('btnApplyDiscount').disabled = true;
    document.getElementById('selectAll').checked = false;
    document.getElementById('selectedCount').textContent = '0 selected';
    document.getElementById('studentList').innerHTML = '';
    $('#applyModal').modal('show');
    loadEligibleStudents(id);
  };

  function loadEligibleStudents(discountId) {
    var loader = document.getElementById('modalLoader');
    loader.style.display = 'flex';

    $.ajax({
      url: BASE + 'fee_management/fetch_eligible_students',
      type: 'POST',
      data: $.extend({ discount_id: discountId }, csrf()),
      dataType: 'json',
      success: function(res) {
        updateCsrf(res);
        loader.style.display = 'none';

        if (res.policy) {
          var info = '<strong>' + escHtml(res.policy.policy_name) + '</strong> &mdash; ';
          info += res.policy.discount_type === 'percentage'
            ? res.policy.value + '% off'
            : '\u20B9' + parseFloat(res.policy.value).toLocaleString('en-IN') + ' off';
          if (res.policy.max_cap) info += ' (max \u20B9' + parseFloat(res.policy.max_cap).toLocaleString('en-IN') + ')';
          document.getElementById('modalPolicyInfo').innerHTML = info;
        }

        if (!res.status || !res.students || res.students.length === 0) {
          document.getElementById('noStudents').style.display = 'block';
          return;
        }

        var html = '';
        for (var i = 0; i < res.students.length; i++) {
          var s = res.students[i];
          html += '<div class="fm-student-row">' +
            '<input type="checkbox" class="student-check" value="' + escAttr(s.id) + '" onchange="updateSelectedCount()">' +
            '<div><span class="fm-student-name">' + escHtml(s.name) + '</span></div>' +
            '<span class="fm-student-meta">' + escHtml(s.class || '') + (s.section ? ' / ' + escHtml(s.section) : '') + '</span>' +
            '</div>';
        }
        document.getElementById('studentList').innerHTML = html;
        document.getElementById('studentListWrap').style.display = 'block';
      },
      error: function() {
        loader.style.display = 'none';
        document.getElementById('noStudents').style.display = 'block';
        showToast('Failed to load eligible students.', 'error');
      }
    });
  }

  window.updateSelectedCount = function() {
    var checks = document.querySelectorAll('#studentList .student-check:checked');
    var total = document.querySelectorAll('#studentList .student-check');
    document.getElementById('selectedCount').textContent = checks.length + ' selected';
    document.getElementById('btnApplyDiscount').disabled = checks.length === 0;
    document.getElementById('selectAll').checked = checks.length === total.length && total.length > 0;
  };

  window.applyDiscount = function() {
    var checks = document.querySelectorAll('#studentList .student-check:checked');
    if (checks.length === 0) return showToast('Select at least one student.', 'error');

    var studentIds = [];
    for (var i = 0; i < checks.length; i++) studentIds.push(checks[i].value);

    var btn = document.getElementById('btnApplyDiscount');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Applying...';

    var payload = csrf();
    payload.discount_id = currentApplyId;
    payload.student_ids = studentIds;

    $.ajax({
      url: BASE + 'fee_management/apply_discount',
      type: 'POST',
      data: payload,
      dataType: 'json',
      traditional: true,
      success: function(res) {
        updateCsrf(res);
        btn.innerHTML = '<i class="fa fa-check"></i> Apply to Selected';
        btn.disabled = false;
        if (res.status) {
          showToast(res.message || 'Discount applied to ' + studentIds.length + ' student(s).', 'success');
          $('#applyModal').modal('hide');
        } else {
          showToast(res.message || 'Failed to apply discount.', 'error');
        }
      },
      error: function() {
        btn.innerHTML = '<i class="fa fa-check"></i> Apply to Selected';
        btn.disabled = false;
        showToast('Server error. Please try again.', 'error');
      }
    });
  };

  // ---- Reset Form ----
  window.resetForm = function() {
    document.getElementById('discountForm').reset();
    document.getElementById('editId').value = '';
    document.getElementById('formTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Add Discount Policy';
    document.getElementById('btnResetForm').style.display = 'none';
    document.getElementById('valuePrefix').style.display = 'none';
    document.getElementById('valueSuffix').style.display = 'none';
    document.getElementById('toggleLabel').textContent = 'Active';

    var allChecks = document.querySelectorAll('.fm-check-item input[type="checkbox"]');
    for (var i = 0; i < allChecks.length; i++) allChecks[i].checked = false;

    var invalids = document.querySelectorAll('.fm-invalid');
    for (var j = 0; j < invalids.length; j++) invalids[j].classList.remove('fm-invalid');
  };

  // ---- Toast ----
  window.showToast = function(msg, type) {
    var toast = document.getElementById('fmToast');
    toast.className = 'fm-toast fm-toast--' + (type || 'info');
    toast.textContent = msg;
    toast.classList.add('fm-toast--show');
    clearTimeout(toast._timer);
    toast._timer = setTimeout(function() { toast.classList.remove('fm-toast--show'); }, 3500);
  };

  // ---- Helpers ----
  function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
  }
  function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
});
</script>

