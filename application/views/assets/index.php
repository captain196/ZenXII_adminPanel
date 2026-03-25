<?php
$at = isset($active_tab) ? $active_tab : 'registry';
$tab_map = [
    'registry'     => ['panel'=>'panelAssets',  'icon'=>'fa-laptop',    'label'=>'Registry'],
    'categories'   => ['panel'=>'panelCats',    'icon'=>'fa-tags',      'label'=>'Categories'],
    'assignments'  => ['panel'=>'panelAssign',  'icon'=>'fa-share',     'label'=>'Assignments'],
    'maintenance'  => ['panel'=>'panelMaint',   'icon'=>'fa-wrench',    'label'=>'Maintenance'],
    'depreciation' => ['panel'=>'panelDep',     'icon'=>'fa-line-chart','label'=>'Depreciation'],
];
?>
<style>
.ast-wrap{padding:20px;max-width:1400px;margin:0 auto}
.ast-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.ast-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.ast-header-icon i{color:var(--gold);font-size:1.1rem}
.ast-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.ast-breadcrumb a{color:var(--gold);text-decoration:none} .ast-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}
.ast-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.ast-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.ast-tab:hover{color:var(--t1)} .ast-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .ast-tab i{margin-right:6px;font-size:14px}
.ast-panel{display:none} .ast-panel.active{display:block}
.ast-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:18px}
.ast-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.ast-btn{padding:8px 16px;border-radius:var(--r-sm);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.ast-btn-primary{background:var(--gold);color:#fff} .ast-btn-primary:hover{background:var(--gold2)}
.ast-btn-danger{background:var(--rose);color:#fff} .ast-btn-sm{padding:6px 12px;font-size:12px}
.ast-btn-amber{background:var(--amber);color:#fff}
.ast-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.ast-table th,.ast-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.ast-table th{color:var(--t3);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.ast-table td{color:var(--t1)} .ast-table tr:hover td{background:var(--gold-dim)}
.ast-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;font-family:var(--font-b)}
.ast-badge-green{background:rgba(34,197,94,.12);color:#22c55e} .ast-badge-red{background:rgba(239,68,68,.12);color:#ef4444}
.ast-badge-amber{background:rgba(234,179,8,.12);color:#eab308} .ast-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.ast-form-group{margin-bottom:14px}
.ast-form-group label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:4px;font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.ast-form-group input,.ast-form-group select,.ast-form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.ast-form-group input:focus,.ast-form-group select:focus{border-color:var(--gold);outline:none}
.ast-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ast-modal-bg{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center} .ast-modal-bg.show{display:flex}
.ast-modal{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);width:560px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:24px}
.ast-modal-title{font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.ast-modal-close{cursor:pointer;color:var(--t3);font-size:1.2rem;background:none;border:none}
.ast-dep-bar{height:8px;border-radius:4px;background:var(--border);overflow:hidden;width:100px;display:inline-block;vertical-align:middle;margin-left:8px}
.ast-dep-fill{height:100%;border-radius:4px;background:var(--amber)}
</style>

<div class="content-wrapper"><section class="content"><div class="ast-wrap">
  <div class="ast-header"><div>
    <div class="ast-header-icon"><i class="fa fa-laptop"></i> Asset Tracking</div>
    <ol class="ast-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('operations') ?>">Operations</a></li><li>Assets</li></ol>
  </div></div>
  <nav class="ast-tabs">
    <?php foreach ($tab_map as $slug => $t): ?>
    <a class="ast-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('assets/' . $slug) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
  </nav>

  <!-- REGISTRY -->
  <div class="ast-panel<?= $at === 'registry' ? ' active' : '' ?>" id="panelAssets">
    <div class="ast-card"><div class="ast-card-title"><span>Asset Registry</span><button class="ast-btn ast-btn-primary" onclick="AST.openModal()"><i class="fa fa-plus"></i> Register Asset</button></div>
    <table class="ast-table"><thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Purchase</th><th>Cost</th><th>Current Value</th><th>Location</th><th>Condition</th><th>Actions</th></tr></thead><tbody id="astTbody"></tbody></table></div>
  </div>

  <!-- CATEGORIES -->
  <div class="ast-panel<?= $at === 'categories' ? ' active' : '' ?>" id="panelCats">
    <div class="ast-card"><div class="ast-card-title"><span>Asset Categories</span><button class="ast-btn ast-btn-primary" onclick="AST.openCatModal()"><i class="fa fa-plus"></i> Add</button></div>
    <table class="ast-table"><thead><tr><th>ID</th><th>Name</th><th>Dep. Rate</th><th>Method</th><th>Actions</th></tr></thead><tbody id="acatTbody"></tbody></table></div>
  </div>

  <!-- ASSIGNMENTS -->
  <div class="ast-panel<?= $at === 'assignments' ? ' active' : '' ?>" id="panelAssign">
    <div class="ast-card"><div class="ast-card-title"><span>Asset Assignments</span><button class="ast-btn ast-btn-primary" onclick="AST.openAsnModal()"><i class="fa fa-plus"></i> Assign</button></div>
    <table class="ast-table"><thead><tr><th>ID</th><th>Asset</th><th>Assigned To</th><th>Type</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody id="asnTbody"></tbody></table></div>
  </div>

  <!-- MAINTENANCE -->
  <div class="ast-panel<?= $at === 'maintenance' ? ' active' : '' ?>" id="panelMaint">
    <div class="ast-card"><div class="ast-card-title"><span>Maintenance Records</span><button class="ast-btn ast-btn-primary" onclick="AST.openMntModal()"><i class="fa fa-plus"></i> Add Record</button></div>
    <table class="ast-table"><thead><tr><th>ID</th><th>Asset</th><th>Type</th><th>Description</th><th>Cost</th><th>Date</th><th>Next Due</th><th>Status</th></tr></thead><tbody id="mntTbody"></tbody></table></div>
  </div>

  <!-- DEPRECIATION -->
  <div class="ast-panel<?= $at === 'depreciation' ? ' active' : '' ?>" id="panelDep">
    <div class="ast-card"><div class="ast-card-title"><span>Depreciation Report</span><button class="ast-btn ast-btn-amber" onclick="AST.computeDep()"><i class="fa fa-calculator"></i> Run Monthly Depreciation</button></div>
    <table class="ast-table"><thead><tr><th>Asset</th><th>Purchase Cost</th><th>Accumulated</th><th>Current Value</th><th>Rate</th><th>Method</th><th>Depreciated</th></tr></thead><tbody id="depTbody"></tbody></table></div>
  </div>
</div></section></div>

<!-- ASSET MODAL -->
<div class="ast-modal-bg" id="astModal"><div class="ast-modal">
  <div class="ast-modal-title"><span id="astModalTitle">Register Asset</span><button class="ast-modal-close" onclick="AST.closeModal()">&times;</button></div>
  <input type="hidden" id="aId">
  <div class="ast-form-group"><label>Name *</label><input type="text" id="aName"></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Category</label><select id="aCatId"><option value="">-- None --</option></select></div><div class="ast-form-group"><label>Account Code</label><select id="aAcct"><option value="1110">1110 - Furniture</option><option value="1120" selected>1120 - Computer/Equipment</option><option value="1130">1130 - Vehicles</option><option value="1140">1140 - Building</option></select></div></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Purchase Date</label><input type="date" id="aPDate"></div><div class="ast-form-group"><label>Purchase Cost *</label><input type="number" id="aCost" min="0" step="0.01"></div></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Serial Number</label><input type="text" id="aSerial"></div><div class="ast-form-group"><label>Location</label><input type="text" id="aLoc"></div></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Condition</label><select id="aCond"><option>New</option><option>Good</option><option>Fair</option><option>Poor</option></select></div><div class="ast-form-group"><label>Payment Mode</label><select id="aPayMode"><option value="Cash">Cash</option><option value="Bank">Bank</option></select></div></div>
  <div class="ast-form-group"><label>Description</label><textarea id="aDesc" rows="2"></textarea></div>
  <button class="ast-btn ast-btn-primary" onclick="AST.save()" style="width:100%;margin-top:8px">Save Asset</button>
</div></div>

<!-- CATEGORY MODAL -->
<div class="ast-modal-bg" id="acatModal"><div class="ast-modal" style="width:420px">
  <div class="ast-modal-title"><span id="acatModalTitle">Add Category</span><button class="ast-modal-close" onclick="AST.closeCatModal()">&times;</button></div>
  <input type="hidden" id="acId">
  <div class="ast-form-group"><label>Name *</label><input type="text" id="acName"></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Depreciation Rate (%)</label><input type="number" id="acRate" value="10" min="0" step="0.5"></div><div class="ast-form-group"><label>Method</label><select id="acMethod"><option value="SLM">Straight Line (SLM)</option><option value="WDV">Written Down Value (WDV)</option></select></div></div>
  <button class="ast-btn ast-btn-primary" onclick="AST.saveCat()" style="width:100%;margin-top:8px">Save</button>
</div></div>

<!-- ASSIGNMENT MODAL -->
<div class="ast-modal-bg" id="astAsnModal"><div class="ast-modal" style="width:420px">
  <div class="ast-modal-title"><span>Assign Asset</span><button class="ast-modal-close" onclick="AST.closeAsnModal()">&times;</button></div>
  <div class="ast-form-group"><label>Asset *</label><select id="asnAssetId"></select></div>
  <div class="ast-form-group"><label>Assigned To *</label><input type="text" id="asnTo" placeholder="Staff name, room, or department"></div>
  <div class="ast-form-group"><label>Type</label><select id="asnType"><option value="staff">Staff</option><option value="room">Room</option><option value="department">Department</option></select></div>
  <button class="ast-btn ast-btn-primary" onclick="AST.saveAsn()" style="width:100%;margin-top:8px">Assign</button>
</div></div>

<!-- MAINTENANCE MODAL -->
<div class="ast-modal-bg" id="mntModal"><div class="ast-modal">
  <div class="ast-modal-title"><span id="mntModalTitle">Add Maintenance Record</span><button class="ast-modal-close" onclick="AST.closeMntModal()">&times;</button></div>
  <input type="hidden" id="mntId">
  <div class="ast-form-group"><label>Asset *</label><select id="mntAssetId"></select></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Type</label><select id="mntType"><option>Repair</option><option>Service</option><option>Inspection</option><option>Upgrade</option><option>Other</option></select></div><div class="ast-form-group"><label>Cost</label><input type="number" id="mntCost" value="0" min="0"></div></div>
  <div class="ast-form-group"><label>Description *</label><textarea id="mntDesc" rows="2"></textarea></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Date</label><input type="date" id="mntDate"></div><div class="ast-form-group"><label>Next Due</label><input type="date" id="mntNext"></div></div>
  <div class="ast-form-row"><div class="ast-form-group"><label>Vendor</label><input type="text" id="mntVendor"></div><div class="ast-form-group"><label>Status</label><select id="mntStatus"><option>Completed</option><option>Scheduled</option><option>InProgress</option></select></div></div>
  <button class="ast-btn ast-btn-primary" onclick="AST.saveMnt()" style="width:100%;margin-top:8px">Save</button>
</div></div>

<script>
var _AST_CFG={BASE:'<?= base_url() ?>',CSRF_NAME:'<?= $this->security->get_csrf_token_name() ?>',CSRF_HASH:'<?= $this->security->get_csrf_hash() ?>',activeTab:'<?= $at ?>'};
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';
  var BASE=_AST_CFG.BASE,CN=_AST_CFG.CSRF_NAME,CH=_AST_CFG.CSRF_HASH;
  var categories=[],assets=[];
  function getJSON(u){return $.getJSON(BASE+u)} function post(u,d){d[CN]=CH;return $.post(BASE+u,d)}
  function toast(m,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;background:'+(ok?'var(--green)':'var(--rose)')+'">'+m+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3000)}
  function escH(s){return $('<span>').text(s||'').html()}
  function catName(id){for(var i=0;i<categories.length;i++) if(categories[i].id===id) return categories[i].name; return id||'—'}
  window.AST={};

  function loadCats(){getJSON('assets/get_categories').done(function(r){if(r.status!=='success')return;categories=r.categories||[];
    var html='';categories.forEach(function(c){html+='<tr><td>'+escH(c.id)+'</td><td>'+escH(c.name)+'</td><td>'+c.depreciation_rate+'%</td><td>'+escH(c.method)+'</td><td><button class="ast-btn ast-btn-sm ast-btn-primary" onclick="AST.editCat(\''+c.id+'\')"><i class="fa fa-pencil"></i></button> <button class="ast-btn ast-btn-sm ast-btn-danger" onclick="AST.delCat(\''+c.id+'\')"><i class="fa fa-trash"></i></button></td></tr>'});
    $('#acatTbody').html(html||'<tr><td colspan="5" style="text-align:center;color:var(--t3)">No categories</td></tr>');
    var o='<option value="">-- None --</option>';categories.forEach(function(c){o+='<option value="'+c.id+'">'+escH(c.name)+' ('+c.depreciation_rate+'%)</option>'});$('#aCatId').html(o);
  })}
  function loadAssets(){getJSON('assets/get_assets').done(function(r){if(r.status!=='success')return;assets=r.assets||[];
    var html='';assets.forEach(function(a){
      var condBadge=a.condition==='New'||a.condition==='Good'?'ast-badge-green':a.condition==='Fair'?'ast-badge-amber':'ast-badge-red';
      html+='<tr><td>'+escH(a.id)+'</td><td>'+escH(a.name)+'</td><td>'+escH(catName(a.category_id))+'</td><td>'+escH(a.purchase_date)+'</td><td>Rs '+a.purchase_cost+'</td><td>Rs '+a.current_value+'</td><td>'+escH(a.location)+'</td><td><span class="ast-badge '+condBadge+'">'+a.condition+'</span></td>';
      html+='<td><button class="ast-btn ast-btn-sm ast-btn-primary" onclick="AST.edit(\''+a.id+'\')"><i class="fa fa-pencil"></i></button> <button class="ast-btn ast-btn-sm ast-btn-danger" onclick="AST.del(\''+a.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
    });
    $('#astTbody').html(html||'<tr><td colspan="9" style="text-align:center;color:var(--t3)">No assets</td></tr>');
    var o='';assets.forEach(function(a){o+='<option value="'+a.id+'">'+escH(a.name)+'</option>'});$('#asnAssetId,#mntAssetId').html(o);
  })}
  function loadAssignments(){getJSON('assets/get_assignments').done(function(r){if(r.status!=='success')return;
    var html='';(r.assignments||[]).forEach(function(a){
      var stBadge=a.status==='Active'?'<span class="ast-badge ast-badge-green">Active</span>':'<span class="ast-badge ast-badge-amber">Returned</span>';
      var act=a.status==='Active'?'<button class="ast-btn ast-btn-sm ast-btn-danger" onclick="AST.returnAsn(\''+a.id+'\')"><i class="fa fa-undo"></i></button>':'—';
      html+='<tr><td>'+escH(a.id)+'</td><td>'+escH(a.asset_name)+'</td><td>'+escH(a.assigned_to)+'</td><td>'+escH(a.assign_type)+'</td><td>'+escH(a.date)+'</td><td>'+stBadge+'</td><td>'+act+'</td></tr>';
    });
    $('#asnTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No assignments</td></tr>');
  })}
  function loadMaint(){getJSON('assets/get_maintenance').done(function(r){if(r.status!=='success')return;
    var html='';(r.maintenance||[]).forEach(function(m){
      var stBadge=m.status==='Completed'?'ast-badge-green':m.status==='Scheduled'?'ast-badge-blue':'ast-badge-amber';
      html+='<tr><td>'+escH(m.id)+'</td><td>'+escH(m.asset_name)+'</td><td>'+escH(m.type)+'</td><td>'+escH(m.description)+'</td><td>Rs '+m.cost+'</td><td>'+escH(m.date)+'</td><td>'+escH(m.next_due||'—')+'</td><td><span class="ast-badge '+stBadge+'">'+m.status+'</span></td></tr>';
    });
    $('#mntTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No records</td></tr>');
  })}
  function loadDep(){getJSON('assets/get_depreciation_report').done(function(r){if(r.status!=='success')return;
    var html='';(r.depreciation_report||[]).forEach(function(d){
      var pct=d.purchase_cost>0?Math.round((d.accumulated_dep/d.purchase_cost)*100):0;
      html+='<tr><td>'+escH(d.name)+'</td><td>Rs '+d.purchase_cost+'</td><td>Rs '+d.accumulated_dep+'</td><td>Rs '+d.current_value+'</td><td>'+d.rate+'%</td><td>'+escH(d.method)+'</td>';
      html+='<td>'+pct+'% <div class="ast-dep-bar"><div class="ast-dep-fill" style="width:'+pct+'%"></div></div></td></tr>';
    });
    $('#depTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No assets</td></tr>');
  })}

  // Asset CRUD
  AST.openModal=function(){$('#aId,#aName,#aSerial,#aLoc,#aDesc').val('');$('#aCatId').val('');$('#aAcct').val('1120');$('#aCost').val('');$('#aPDate').val(new Date().toISOString().slice(0,10));$('#aCond').val('New');$('#aPayMode').val('Cash');$('#astModalTitle').text('Register Asset');$('#astModal').addClass('show')};
  AST.closeModal=function(){$('#astModal').removeClass('show')};
  AST.edit=function(id){var a=assets.find(function(x){return x.id===id});if(!a)return;$('#aId').val(a.id);$('#aName').val(a.name);$('#aCatId').val(a.category_id);$('#aAcct').val(a.account_code);$('#aPDate').val(a.purchase_date);$('#aCost').val(a.purchase_cost);$('#aSerial').val(a.serial_no);$('#aLoc').val(a.location);$('#aCond').val(a.condition);$('#aDesc').val(a.description);$('#astModalTitle').text('Edit Asset');$('#astModal').addClass('show')};
  AST.save=function(){post('assets/save_asset',{id:$('#aId').val(),name:$('#aName').val(),category_id:$('#aCatId').val(),account_code:$('#aAcct').val(),purchase_date:$('#aPDate').val(),purchase_cost:$('#aCost').val(),serial_no:$('#aSerial').val(),location:$('#aLoc').val(),condition:$('#aCond').val(),payment_mode:$('#aPayMode').val(),description:$('#aDesc').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);AST.closeModal();loadAssets()}else toast(r.message,false)})};
  AST.del=function(id){if(!confirm('Delete asset?'))return;post('assets/delete_asset',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadAssets()}else toast(r.message,false)})};

  // Category CRUD
  AST.openCatModal=function(){$('#acId,#acName').val('');$('#acRate').val(10);$('#acMethod').val('SLM');$('#acatModalTitle').text('Add Category');$('#acatModal').addClass('show')};
  AST.closeCatModal=function(){$('#acatModal').removeClass('show')};
  AST.editCat=function(id){var c=categories.find(function(x){return x.id===id});if(!c)return;$('#acId').val(c.id);$('#acName').val(c.name);$('#acRate').val(c.depreciation_rate);$('#acMethod').val(c.method);$('#acatModalTitle').text('Edit Category');$('#acatModal').addClass('show')};
  AST.saveCat=function(){post('assets/save_category',{id:$('#acId').val(),name:$('#acName').val(),depreciation_rate:$('#acRate').val(),method:$('#acMethod').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);AST.closeCatModal();loadCats()}else toast(r.message,false)})};
  AST.delCat=function(id){if(!confirm('Delete?'))return;post('assets/delete_category',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadCats()}else toast(r.message,false)})};

  // Assignments
  AST.openAsnModal=function(){$('#asnTo').val('');$('#asnType').val('staff');$('#astAsnModal').addClass('show')};
  AST.closeAsnModal=function(){$('#astAsnModal').removeClass('show')};
  AST.saveAsn=function(){post('assets/save_assignment',{asset_id:$('#asnAssetId').val(),assigned_to:$('#asnTo').val(),assign_type:$('#asnType').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);AST.closeAsnModal();loadAssignments()}else toast(r.message,false)})};
  AST.returnAsn=function(id){if(!confirm('Return this asset?'))return;post('assets/return_assignment',{assignment_id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadAssignments()}else toast(r.message,false)})};

  // Maintenance
  AST.openMntModal=function(){$('#mntId,#mntDesc,#mntVendor').val('');$('#mntCost').val(0);$('#mntDate').val(new Date().toISOString().slice(0,10));$('#mntNext').val('');$('#mntType').val('Repair');$('#mntStatus').val('Completed');$('#mntModalTitle').text('Add Maintenance Record');$('#mntModal').addClass('show')};
  AST.closeMntModal=function(){$('#mntModal').removeClass('show')};
  AST.saveMnt=function(){post('assets/save_maintenance',{id:$('#mntId').val(),asset_id:$('#mntAssetId').val(),type:$('#mntType').val(),description:$('#mntDesc').val(),cost:$('#mntCost').val(),date:$('#mntDate').val(),next_due:$('#mntNext').val(),vendor:$('#mntVendor').val(),status:$('#mntStatus').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);AST.closeMntModal();loadMaint()}else toast(r.message,false)})};

  // Depreciation
  AST.computeDep=function(){if(!confirm('Run monthly depreciation for all active assets? This creates an accounting journal entry.'))return;post('assets/compute_depreciation',{}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadDep();loadAssets()}else toast(r.message,false)})};

  // Init
  loadCats();loadAssets();
  var at=_AST_CFG.activeTab;
  if(at==='assignments') loadAssignments();
  if(at==='maintenance') loadMaint();
  if(at==='depreciation') loadDep();
  $('.ast-tab').on('click',function(){var h=$(this).attr('href');if(h.indexOf('/assignments')>-1)loadAssignments();if(h.indexOf('/maintenance')>-1)loadMaint();if(h.indexOf('/depreciation')>-1)loadDep()});
})();
});
</script>
