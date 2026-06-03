<?php
/**
 * B2.3.4-A Phase 1D — School Search spoke view.
 *
 * Sidebar filter form + results area (card/table toggle) + pagination
 * + saved-searches panel + CSV/XLSX export. Drill-down compatible with
 * Phase 1C chart links (?states=, ?plans=, ?cities=).
 *
 * Variables in scope (from Superadmin_analytics::schools_search):
 *   $payload         — search_schools() result
 *   $options         — search_schools_options() result
 *   $saved_searches  — list_saved_searches() result
 *   $saved_meta      — ['slug', 'name'] or null
 *   $filters         — current filter map
 *   $sort            — ['field', 'order']
 *   $view_mode       — 'card' or 'table'
 */
$rows       = $payload['rows']      ?? [];
$total      = (int) ($payload['total']     ?? 0);
$page       = (int) ($payload['page']      ?? 1);
$pageSize   = (int) ($payload['pageSize']  ?? 25);
$pageCount  = (int) ($payload['pageCount'] ?? 0);
$sortField  = (string) ($sort['field'] ?? 'schoolName');
$sortOrder  = (string) ($sort['order'] ?? 'asc');

$states_opts  = $options['states']  ?? [];
$plans_opts   = $options['plans']   ?? [];
$cities_opts  = $options['cities']  ?? [];
$regions_opts = $options['regions'] ?? [];

$f_states  = (string) ($filters['states']  ?? '');
$f_plans   = (string) ($filters['plans']   ?? '');
$f_cities  = (string) ($filters['cities']  ?? '');
$f_regions = (string) ($filters['regions'] ?? '');

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); };
$is_selected = function (string $needle, string $csv): bool {
    if ($csv === '') return false;
    $parts = array_map('trim', explode(',', $csv));
    return in_array($needle, $parts, true);
};
?>
<style>
.b2ss-shell{display:grid;grid-template-columns:280px 1fr;gap:18px;padding:18px 24px;}
.b2ss-side{background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:16px;}
.b2ss-side h4{margin:0 0 10px 0;font-size:13px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.3px;}
.b2ss-side .grp{margin-bottom:14px;}
.b2ss-side label{display:block;font-size:12px;font-weight:500;color:#475569;margin-bottom:4px;}
.b2ss-side input[type=text],.b2ss-side input[type=number],.b2ss-side input[type=date]{width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;}
.b2ss-chips{display:flex;flex-wrap:wrap;gap:4px;}
.b2ss-chip{display:inline-block;padding:3px 8px;font-size:11px;border:1px solid #cbd5e1;border-radius:11px;cursor:pointer;background:#f8fafc;color:#475569;user-select:none;}
.b2ss-chip.active{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2ss-range{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.b2ss-actions{display:flex;gap:6px;margin-top:8px;}
.b2ss-actions .btn{flex:1;font-size:12px;padding:6px 10px;}
.b2ss-main{display:flex;flex-direction:column;min-width:0;}
.b2ss-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:12px;background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:10px 14px;}
.b2ss-toolbar .stat{font-size:13px;color:#475569;}
.b2ss-toolbar .stat strong{color:#0f172a;}
.b2ss-toolbar .spacer{flex:1;}
.b2ss-toolbar .toggle button{border:1px solid #cbd5e1;background:#fff;padding:5px 10px;font-size:12px;cursor:pointer;color:#475569;}
.b2ss-toolbar .toggle button.active{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2ss-toolbar .toggle button:first-child{border-radius:5px 0 0 5px;}
.b2ss-toolbar .toggle button:last-child{border-radius:0 5px 5px 0;border-left:none;}
.b2ss-toolbar select{padding:5px 8px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px;background:#fff;}
.b2ss-toolbar .btn{font-size:12px;padding:5px 10px;}
.b2ss-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;}
.b2ss-card{background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:14px;display:flex;flex-direction:column;gap:8px;}
.b2ss-card .hd{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;}
.b2ss-card .nm{font-weight:600;font-size:13.5px;color:#0f172a;line-height:1.2;}
.b2ss-card .sub{font-size:11px;color:#64748b;}
.b2ss-card .lc{font-size:10px;font-weight:600;padding:2px 7px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;}
.b2ss-card .lc.active{background:#dcfce7;color:#166534;}
.b2ss-card .lc.disabled{background:#fee2e2;color:#991b1b;font-weight:600;}
.b2ss-card .lc.trialing{background:#dbeafe;color:#1e40af;}
.b2ss-card .lc.expiring_soon{background:#fef3c7;color:#92400e;}
.b2ss-card .lc.grace{background:#fef3c7;color:#92400e;}
.b2ss-card .lc.past_due{background:#fee2e2;color:#991b1b;}
.b2ss-card .lc.suspended{background:#e5e7eb;color:#374151;}
.b2ss-card .lc.expired{background:#e5e7eb;color:#374151;}
.b2ss-card .meta{display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:11.5px;color:#475569;border-top:1px solid #f1f5f9;padding-top:8px;}
.b2ss-card .meta div span{display:block;color:#94a3b8;font-size:10px;margin-bottom:1px;}
.b2ss-card .row-acts{display:flex;gap:6px;border-top:1px solid #f1f5f9;padding-top:8px;flex-wrap:wrap;}
.b2ss-card .row-acts a{font-size:11px;color:#0f172a;text-decoration:none;padding:3px 9px;border:1px solid #cbd5e1;border-radius:5px;background:#f8fafc;}
.b2ss-card .row-acts a:hover{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2ss-table{width:100%;background:#fff;border:1px solid #e6e8eb;border-radius:8px;overflow:hidden;font-size:13px;border-collapse:collapse;}
.b2ss-table th,.b2ss-table td{padding:8px 12px;text-align:left;border-bottom:1px solid #f1f5f9;}
.b2ss-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#475569;font-weight:600;cursor:pointer;user-select:none;}
.b2ss-table th .arr{font-size:9px;color:#94a3b8;margin-left:3px;}
.b2ss-table tr:hover td{background:#f8fafc;}
.b2ss-table .lc{font-size:10px;font-weight:600;padding:2px 7px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;display:inline-block;}
.b2ss-table .lc.active{background:#dcfce7;color:#166534;}
.b2ss-table .lc.disabled{background:#fee2e2;color:#991b1b;font-weight:600;}
.b2ss-table .lc.trialing{background:#dbeafe;color:#1e40af;}
.b2ss-table .lc.expiring_soon{background:#fef3c7;color:#92400e;}
.b2ss-table .lc.grace{background:#fef3c7;color:#92400e;}
.b2ss-table .lc.past_due{background:#fee2e2;color:#991b1b;}
.b2ss-table .lc.suspended{background:#e5e7eb;color:#374151;}
.b2ss-table .lc.expired{background:#e5e7eb;color:#374151;}
.b2ss-empty{text-align:center;padding:50px 20px;background:#fff;border:1px solid #e6e8eb;border-radius:8px;color:#64748b;}
.b2ss-empty i{font-size:36px;color:#cbd5e1;margin-bottom:10px;display:block;}
.b2ss-pager{display:flex;align-items:center;gap:8px;margin-top:14px;justify-content:flex-end;}
.b2ss-pager button{border:1px solid #cbd5e1;background:#fff;padding:5px 10px;font-size:12px;cursor:pointer;border-radius:5px;color:#475569;}
.b2ss-pager button:disabled{opacity:.45;cursor:not-allowed;}
.b2ss-pager .pg-info{font-size:12px;color:#64748b;margin:0 6px;}
.b2ss-saved{margin-bottom:14px;}
.b2ss-saved h4{display:flex;justify-content:space-between;align-items:center;}
.b2ss-saved h4 a{font-size:11px;font-weight:500;color:#0f172a;cursor:pointer;text-transform:none;letter-spacing:0;}
.b2ss-saved-list{display:flex;flex-direction:column;gap:4px;}
.b2ss-saved-item{display:flex;justify-content:space-between;align-items:center;padding:5px 8px;background:#f8fafc;border:1px solid #e6e8eb;border-radius:5px;font-size:12px;}
.b2ss-saved-item a{color:#0f172a;text-decoration:none;flex:1;}
.b2ss-saved-item .del{color:#94a3b8;cursor:pointer;padding:0 4px;}
.b2ss-saved-item .del:hover{color:#dc2626;}
.b2ss-saved-empty{font-size:11px;color:#94a3b8;font-style:italic;padding:4px;}
@media (max-width:1024px){.b2ss-shell{grid-template-columns:1fr;}}
</style>

<section class="content-header" style="padding:18px 24px 8px 24px;">
  <h1 style="margin:0;font-size:20px;color:#0f172a;">
    <i class="fa fa-search" style="color:#0f172a;margin-right:10px;"></i>School Search
    <?php if ($saved_meta): ?>
      <small style="font-size:13px;color:#475569;font-weight:400;">·
        Applied saved search: <strong><?= $h($saved_meta['name']) ?></strong>
      </small>
    <?php endif; ?>
  </h1>
  <ol class="breadcrumb" style="margin-top:6px;background:transparent;padding:0;">
    <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
    <li class="active">School Search</li>
  </ol>
</section>

<form id="b2ssForm" method="get" action="<?= base_url('superadmin/dashboard/schools-search') ?>">
<div class="b2ss-shell">

  <!-- ── SIDEBAR FILTERS ──────────────────────────────────────────── -->
  <aside class="b2ss-side">
    <div class="b2ss-saved">
      <h4>Saved searches <a id="b2ssSaveBtn" title="Save current filter set">+ save</a></h4>
      <div class="b2ss-saved-list" id="b2ssSavedList">
        <?php if (empty($saved_searches)): ?>
          <div class="b2ss-saved-empty">No saved searches yet</div>
        <?php else: foreach ($saved_searches as $ss): ?>
          <div class="b2ss-saved-item" data-slug="<?= $h($ss['slug'] ?? '') ?>">
            <a href="<?= base_url('superadmin/dashboard/schools-search') ?>?saved=<?= urlencode($ss['slug'] ?? '') ?>">
              <?= $h($ss['name'] ?? '') ?>
            </a>
            <span class="del" title="Delete saved search">&times;</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <h4>Filters</h4>

    <div class="grp">
      <label>Free text</label>
      <input type="text" name="q" value="<?= $h($filters['q'] ?? '') ?>" placeholder="Name, code, city, ID…">
    </div>

    <div class="grp">
      <label>Lifecycle state</label>
      <div class="b2ss-chips" data-multi="states">
        <?php foreach ($states_opts as $s): ?>
          <span class="b2ss-chip <?= $is_selected($s, $f_states) ? 'active' : '' ?>" data-val="<?= $h($s) ?>"><?= $h($s) ?></span>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="states" value="<?= $h($f_states) ?>">
    </div>

    <div class="grp">
      <label>Plan</label>
      <div class="b2ss-chips" data-multi="plans">
        <?php foreach ($plans_opts as $p): ?>
          <span class="b2ss-chip <?= $is_selected($p, $f_plans) ? 'active' : '' ?>" data-val="<?= $h($p) ?>"><?= $h($p) ?></span>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="plans" value="<?= $h($f_plans) ?>">
    </div>

    <?php if (!empty($cities_opts)): ?>
    <div class="grp">
      <label>City</label>
      <div class="b2ss-chips" data-multi="cities">
        <?php foreach ($cities_opts as $c): ?>
          <span class="b2ss-chip <?= $is_selected($c, $f_cities) ? 'active' : '' ?>" data-val="<?= $h($c) ?>"><?= $h($c) ?></span>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="cities" value="<?= $h($f_cities) ?>">
    </div>
    <?php endif; ?>

    <?php if (!empty($regions_opts)): ?>
    <div class="grp">
      <label>Region / State</label>
      <div class="b2ss-chips" data-multi="regions">
        <?php foreach ($regions_opts as $r): ?>
          <span class="b2ss-chip <?= $is_selected($r, $f_regions) ? 'active' : '' ?>" data-val="<?= $h($r) ?>"><?= $h($r) ?></span>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="regions" value="<?= $h($f_regions) ?>">
    </div>
    <?php endif; ?>

    <div class="grp">
      <label>Student count</label>
      <div class="b2ss-range">
        <input type="number" min="0" name="students_min" placeholder="min" value="<?= $h($filters['students_min'] ?? '') ?>">
        <input type="number" min="0" name="students_max" placeholder="max" value="<?= $h($filters['students_max'] ?? '') ?>">
      </div>
    </div>

    <div class="grp">
      <label>Staff count</label>
      <div class="b2ss-range">
        <input type="number" min="0" name="staff_min" placeholder="min" value="<?= $h($filters['staff_min'] ?? '') ?>">
        <input type="number" min="0" name="staff_max" placeholder="max" value="<?= $h($filters['staff_max'] ?? '') ?>">
      </div>
    </div>

    <div class="grp">
      <label>Created date</label>
      <div class="b2ss-range">
        <input type="date" name="created_from" value="<?= $h($filters['created_from'] ?? '') ?>">
        <input type="date" name="created_to"   value="<?= $h($filters['created_to'] ?? '') ?>">
      </div>
    </div>

    <div class="grp">
      <label>Subscription expiry</label>
      <div class="b2ss-range">
        <input type="date" name="expiry_from" value="<?= $h($filters['expiry_from'] ?? '') ?>">
        <input type="date" name="expiry_to"   value="<?= $h($filters['expiry_to'] ?? '') ?>">
      </div>
    </div>

    <input type="hidden" name="sort"  id="b2ssSortField" value="<?= $h($sortField) ?>">
    <input type="hidden" name="order" id="b2ssSortOrder" value="<?= $h($sortOrder) ?>">
    <input type="hidden" name="page"  id="b2ssPage"      value="1">
    <input type="hidden" name="view"  id="b2ssView"      value="<?= $h($view_mode) ?>">
    <input type="hidden" name="page_size" id="b2ssPageSize" value="<?= (int) $pageSize ?>">

    <div class="b2ss-actions">
      <button type="submit" class="btn btn-primary">Apply</button>
      <a href="<?= base_url('superadmin/dashboard/schools-search') ?>" class="btn btn-default">Reset</a>
    </div>
  </aside>

  <!-- ── MAIN RESULTS ─────────────────────────────────────────────── -->
  <div class="b2ss-main">
    <div class="b2ss-toolbar">
      <span class="stat">
        <strong><?= $total ?></strong> result<?= $total === 1 ? '' : 's' ?>
        <?php if ($total > 0): ?>
          · page <strong><?= $page ?></strong> of <strong><?= max(1, $pageCount) ?></strong>
        <?php endif; ?>
      </span>
      <span class="spacer"></span>

      <span class="stat">Sort</span>
      <select id="b2ssSortSel">
        <option value="schoolName|asc"   <?= ($sortField==='schoolName'   && $sortOrder==='asc')  ? 'selected':'' ?>>Name ↑</option>
        <option value="schoolName|desc"  <?= ($sortField==='schoolName'   && $sortOrder==='desc') ? 'selected':'' ?>>Name ↓</option>
        <option value="totalStudents|desc" <?= ($sortField==='totalStudents' && $sortOrder==='desc') ? 'selected':'' ?>>Students ↓</option>
        <option value="totalStudents|asc"  <?= ($sortField==='totalStudents' && $sortOrder==='asc')  ? 'selected':'' ?>>Students ↑</option>
        <option value="totalStaff|desc"  <?= ($sortField==='totalStaff'  && $sortOrder==='desc') ? 'selected':'' ?>>Staff ↓</option>
        <option value="createdAt|desc"   <?= ($sortField==='createdAt'   && $sortOrder==='desc') ? 'selected':'' ?>>Created ↓</option>
        <option value="subscriptionPeriodEnd|asc" <?= ($sortField==='subscriptionPeriodEnd' && $sortOrder==='asc') ? 'selected':'' ?>>Expiry ↑</option>
        <option value="lifecycleState|asc" <?= ($sortField==='lifecycleState' && $sortOrder==='asc') ? 'selected':'' ?>>Lifecycle ↑</option>
      </select>

      <span class="stat">Show</span>
      <select id="b2ssPageSizeSel">
        <?php foreach ([25, 50, 100, 1000] as $sz): ?>
          <option value="<?= $sz ?>" <?= ((int) $pageSize === $sz) ? 'selected' : '' ?>>
            <?= $sz === 1000 ? 'All' : $sz ?>
          </option>
        <?php endforeach; ?>
      </select>

      <span class="toggle">
        <button type="button" id="b2ssViewCard"  class="<?= $view_mode==='card'  ? 'active':'' ?>">Cards</button>
        <button type="button" id="b2ssViewTable" class="<?= $view_mode==='table' ? 'active':'' ?>">Table</button>
      </span>

      <a class="btn btn-default" id="b2ssExportCsv"  href="#" title="Export filtered results as CSV"><i class="fa fa-file-text-o"></i> CSV</a>
      <a class="btn btn-default" id="b2ssExportXlsx" href="#" title="Export filtered results as Excel"><i class="fa fa-file-excel-o"></i> XLSX</a>
    </div>

    <?php if (empty($rows)): ?>
      <div class="b2ss-empty">
        <i class="fa fa-search"></i>
        <h4 style="margin:0 0 4px 0;color:#475569;">No schools match the current filters</h4>
        <p style="margin:0;font-size:12px;">Try widening the filter set or clearing some criteria.</p>
      </div>
    <?php elseif ($view_mode === 'table'): ?>
      <table class="b2ss-table">
        <thead>
          <tr>
            <th data-sort="schoolName">Name <span class="arr"><?= $sortField==='schoolName' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></span></th>
            <th data-sort="schoolCode">Code</th>
            <th>City</th>
            <th>Plan</th>
            <th data-sort="lifecycleState">Lifecycle</th>
            <th data-sort="totalStudents" style="text-align:right;">Students</th>
            <th data-sort="totalStaff"    style="text-align:right;">Staff</th>
            <th data-sort="subscriptionPeriodEnd">Expiry</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $st = strtolower((string) ($r['lifecycleState'] ?? '')); ?>
            <tr>
              <td><strong><?= $h($r['schoolName'] ?? '') ?></strong></td>
              <td><?= $h($r['schoolCode'] ?? '') ?></td>
              <td><?= $h($r['city'] ?? '') ?></td>
              <td><?= $h($r['planName'] ?? '') ?></td>
              <td>
                <?php // Phase 1H H1.P0.a unified status badge: DISABLED dominant
                      // over lifecycle when adminDisabled=true. Matches Phase 1G
                      // tenant detail UX pattern. ?>
                <?php if (!empty($r['adminDisabled'])): ?>
                  <span class="lc disabled" title="underlying lifecycle: <?= $h($st) ?>">disabled</span>
                <?php else: ?>
                  <span class="lc <?= $h($st) ?>"><?= $h($st) ?></span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;"><?= (int) ($r['totalStudents'] ?? 0) ?></td>
              <td style="text-align:right;"><?= (int) ($r['totalStaff'] ?? 0) ?></td>
              <td><?= $h(substr((string) ($r['subscriptionPeriodEnd'] ?? ''), 0, 10)) ?></td>
              <td>
                <a href="<?= $h($r['_detail_url'] ?? '#') ?>" title="School detail"><i class="fa fa-info-circle"></i></a>
                &nbsp;<a href="<?= $h($r['_subscription_url'] ?? '#') ?>" title="Subscription"><i class="fa fa-credit-card"></i></a>
                &nbsp;<a href="<?= $h($r['_analytics_url'] ?? '#') ?>" title="Analytics"><i class="fa fa-line-chart"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="b2ss-cards">
        <?php foreach ($rows as $r):
          $st = strtolower((string) ($r['lifecycleState'] ?? '')); ?>
          <div class="b2ss-card">
            <div class="hd">
              <div>
                <div class="nm"><?= $h($r['schoolName'] ?? '') ?></div>
                <div class="sub">
                  <?= $h($r['schoolCode'] ?? '') ?>
                  <?= !empty($r['city']) ? ' · ' . $h($r['city']) : '' ?>
                </div>
              </div>
              <?php if (!empty($r['adminDisabled'])): ?>
                <span class="lc disabled" title="underlying lifecycle: <?= $h($st) ?>">disabled</span>
              <?php else: ?>
                <span class="lc <?= $h($st) ?>"><?= $h($st) ?></span>
              <?php endif; ?>
            </div>
            <div class="meta">
              <div><span>Plan</span><?= $h($r['planName'] ?? '—') ?></div>
              <div><span>Expiry</span><?= $h(substr((string) ($r['subscriptionPeriodEnd'] ?? '—'), 0, 10)) ?></div>
              <div><span>Students</span><?= (int) ($r['totalStudents'] ?? 0) ?></div>
              <div><span>Staff</span><?= (int) ($r['totalStaff'] ?? 0) ?></div>
            </div>
            <div class="row-acts">
              <a href="<?= $h($r['_detail_url'] ?? '#') ?>"><i class="fa fa-info-circle"></i> Detail</a>
              <a href="<?= $h($r['_subscription_url'] ?? '#') ?>"><i class="fa fa-credit-card"></i> Subscription</a>
              <a href="<?= $h($r['_analytics_url'] ?? '#') ?>"><i class="fa fa-line-chart"></i> Analytics</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($pageCount > 1): ?>
      <div class="b2ss-pager">
        <button type="button" id="b2ssPrev" <?= $page <= 1 ? 'disabled' : '' ?>>&laquo; Prev</button>
        <span class="pg-info">Page <?= $page ?> of <?= $pageCount ?></span>
        <button type="button" id="b2ssNext" <?= $page >= $pageCount ? 'disabled' : '' ?>>Next &raquo;</button>
      </div>
    <?php endif; ?>
  </div>
</div>
</form>

<script>
(function(){
  var form = document.getElementById('b2ssForm');
  if (!form) return;

  // ── chip multi-select sync to hidden input ────────────────────────
  document.querySelectorAll('.b2ss-chips').forEach(function(group){
    var hidden = form.querySelector('input[name="' + group.dataset.multi + '"]');
    if (!hidden) return;
    group.addEventListener('click', function(ev){
      var chip = ev.target.closest('.b2ss-chip');
      if (!chip) return;
      chip.classList.toggle('active');
      var vals = Array.from(group.querySelectorAll('.b2ss-chip.active'))
                       .map(function(c){return c.dataset.val;});
      hidden.value = vals.join(',');
    });
  });

  // ── sort dropdown → hidden field, submit ──────────────────────────
  var sortSel = document.getElementById('b2ssSortSel');
  if (sortSel) sortSel.addEventListener('change', function(){
    var parts = sortSel.value.split('|');
    document.getElementById('b2ssSortField').value = parts[0] || 'schoolName';
    document.getElementById('b2ssSortOrder').value = parts[1] || 'asc';
    document.getElementById('b2ssPage').value = '1';
    form.submit();
  });

  // ── page-size dropdown ────────────────────────────────────────────
  var psSel = document.getElementById('b2ssPageSizeSel');
  if (psSel) psSel.addEventListener('change', function(){
    document.getElementById('b2ssPageSize').value = psSel.value;
    document.getElementById('b2ssPage').value = '1';
    form.submit();
  });

  // ── view toggle ───────────────────────────────────────────────────
  var vc = document.getElementById('b2ssViewCard');
  var vt = document.getElementById('b2ssViewTable');
  function setView(mode){ document.getElementById('b2ssView').value = mode; form.submit(); }
  if (vc) vc.addEventListener('click', function(){ setView('card'); });
  if (vt) vt.addEventListener('click', function(){ setView('table'); });

  // ── pagination ────────────────────────────────────────────────────
  var prev = document.getElementById('b2ssPrev');
  var next = document.getElementById('b2ssNext');
  var curPage = parseInt(document.getElementById('b2ssPage').value, 10) || 1;
  if (prev) prev.addEventListener('click', function(){
    document.getElementById('b2ssPage').value = Math.max(1, curPage - 1);
    form.submit();
  });
  if (next) next.addEventListener('click', function(){
    document.getElementById('b2ssPage').value = curPage + 1;
    form.submit();
  });

  // ── table column sort ─────────────────────────────────────────────
  document.querySelectorAll('.b2ss-table th[data-sort]').forEach(function(th){
    th.addEventListener('click', function(){
      var field = th.dataset.sort;
      var cur = document.getElementById('b2ssSortField').value;
      var order = (cur === field && document.getElementById('b2ssSortOrder').value === 'asc') ? 'desc' : 'asc';
      document.getElementById('b2ssSortField').value = field;
      document.getElementById('b2ssSortOrder').value = order;
      document.getElementById('b2ssPage').value = '1';
      form.submit();
    });
  });

  // ── export buttons ────────────────────────────────────────────────
  function exportUrl(format){
    var fd = new FormData(form);
    fd.set('format', format);
    fd.delete('page'); fd.delete('page_size'); fd.delete('view');
    var qs = new URLSearchParams();
    fd.forEach(function(v, k){ if (v !== '' && v !== null) qs.append(k, v); });
    return '<?= base_url("superadmin/dashboard/schools-search/export") ?>?' + qs.toString();
  }
  var ec = document.getElementById('b2ssExportCsv');
  var ex = document.getElementById('b2ssExportXlsx');
  if (ec) ec.addEventListener('click', function(e){ e.preventDefault(); window.location.href = exportUrl('csv'); });
  if (ex) ex.addEventListener('click', function(e){ e.preventDefault(); window.location.href = exportUrl('xlsx'); });

  // ── saved-search save ─────────────────────────────────────────────
  var saveBtn = document.getElementById('b2ssSaveBtn');
  if (saveBtn) saveBtn.addEventListener('click', function(){
    var name = prompt('Name this saved search:');
    if (!name || !name.trim()) return;
    var fd = new FormData(form);
    fd.set('name', name.trim());
    // Strip transient UI state from the saved filter blob.
    fd.delete('page'); fd.delete('page_size'); fd.delete('view');
    fetch('<?= base_url("superadmin/dashboard/saved_search_save") ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.status === 'success') { window.location.reload(); }
        else { alert('Save failed: ' + (j && j.message ? j.message : 'unknown error')); }
      })
      .catch(function(err){ alert('Save error: ' + err); });
  });

  // ── saved-search delete ───────────────────────────────────────────
  document.querySelectorAll('.b2ss-saved-item .del').forEach(function(x){
    x.addEventListener('click', function(){
      var item = x.closest('.b2ss-saved-item');
      if (!item) return;
      var slug = item.dataset.slug;
      if (!slug || !confirm('Delete this saved search?')) return;
      var fd = new FormData();
      fd.set('slug', slug);
      fetch('<?= base_url("superadmin/dashboard/saved_search_delete") ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j && j.status === 'success') { item.remove(); }
          else { alert('Delete failed: ' + (j && j.message ? j.message : 'unknown error')); }
        })
        .catch(function(err){ alert('Delete error: ' + err); });
    });
  });

})();
</script>
