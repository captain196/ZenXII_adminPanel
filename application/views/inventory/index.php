<?php
$at = isset($active_tab) ? $active_tab : 'items';
$tab_map = [
    'items'      => ['panel'=>'panelItems',     'icon'=>'fa-cubes',     'label'=>'Items'],
    'categories' => ['panel'=>'panelCats',      'icon'=>'fa-tags',      'label'=>'Categories'],
    'vendors'    => ['panel'=>'panelVendors',   'icon'=>'fa-truck',     'label'=>'Vendors'],
    'purchases'  => ['panel'=>'panelPurchases', 'icon'=>'fa-shopping-cart','label'=>'Purchases'],
    'stock'      => ['panel'=>'panelStock',     'icon'=>'fa-exchange',  'label'=>'Stock Issues'],
];
?>
<style>
.inv-wrap{padding:20px;max-width:1400px;margin:0 auto}
.inv-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.inv-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.inv-header-icon i{color:var(--gold);font-size:1.1rem}
.inv-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.inv-breadcrumb a{color:var(--gold);text-decoration:none} .inv-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}
.inv-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.inv-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.inv-tab:hover{color:var(--t1)} .inv-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .inv-tab i{margin-right:6px;font-size:14px}
.inv-panel{display:none} .inv-panel.active{display:block}
.inv-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:18px}
.inv-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.inv-btn{padding:8px 16px;border-radius:var(--r-sm);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.inv-btn-primary{background:var(--gold);color:#fff} .inv-btn-primary:hover{background:var(--gold2)}
.inv-btn-danger{background:var(--rose);color:#fff} .inv-btn-sm{padding:6px 12px;font-size:12px}
.inv-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.inv-table th,.inv-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.inv-table th{color:var(--t3);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.inv-table td{color:var(--t1)} .inv-table tr:hover td{background:var(--gold-dim)}
.inv-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;font-family:var(--font-b)}
.inv-badge-green{background:rgba(34,197,94,.12);color:#22c55e} .inv-badge-red{background:rgba(239,68,68,.12);color:#ef4444}
.inv-badge-amber{background:rgba(234,179,8,.12);color:#eab308} .inv-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.inv-form-group{margin-bottom:14px}
.inv-form-group label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:4px;font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.inv-form-group input,.inv-form-group select,.inv-form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.inv-form-group input:focus,.inv-form-group select:focus{border-color:var(--gold);outline:none}
.inv-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.inv-modal-bg{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center} .inv-modal-bg.show{display:flex}
.inv-modal{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);width:520px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:24px}
.inv-modal-title{font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.inv-modal-close{cursor:pointer;color:var(--t3);font-size:1.2rem;background:none;border:none}
</style>

<div class="content-wrapper"><section class="content"><div class="inv-wrap">
  <div class="inv-header"><div>
    <div class="inv-header-icon"><i class="fa fa-cubes"></i> Inventory Management</div>
    <ol class="inv-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('operations') ?>">Operations</a></li><li>Inventory</li></ol>
  </div></div>
  <nav class="inv-tabs">
    <?php foreach ($tab_map as $slug => $t): ?>
    <a class="inv-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('inventory/' . $slug) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
  </nav>

  <!-- ITEMS -->
  <div class="inv-panel<?= $at === 'items' ? ' active' : '' ?>" id="panelItems">
    <div class="inv-card"><div class="inv-card-title"><span>Inventory Items</span><button class="inv-btn inv-btn-primary" onclick="INV.openItemModal()"><i class="fa fa-plus"></i> Add Item</button></div>
    <table class="inv-table"><thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Unit</th><th>Stock</th><th>Min</th><th>Location</th><th>Actions</th></tr></thead><tbody id="itemsTbody"></tbody></table></div>
  </div>

  <!-- CATEGORIES -->
  <div class="inv-panel<?= $at === 'categories' ? ' active' : '' ?>" id="panelCats">
    <div class="inv-card"><div class="inv-card-title"><span>Categories</span><button class="inv-btn inv-btn-primary" onclick="INV.openCatModal()"><i class="fa fa-plus"></i> Add</button></div>
    <table class="inv-table"><thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Actions</th></tr></thead><tbody id="invCatsTbody"></tbody></table></div>
  </div>

  <!-- VENDORS -->
  <div class="inv-panel<?= $at === 'vendors' ? ' active' : '' ?>" id="panelVendors">
    <div class="inv-card"><div class="inv-card-title"><span>Vendors</span><button class="inv-btn inv-btn-primary" onclick="INV.openVndModal()"><i class="fa fa-plus"></i> Add Vendor</button></div>
    <table class="inv-table"><thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Email</th><th>GST</th><th>Actions</th></tr></thead><tbody id="vndTbody"></tbody></table></div>
  </div>

  <!-- PURCHASES -->
  <div class="inv-panel<?= $at === 'purchases' ? ' active' : '' ?>" id="panelPurchases">
    <div class="inv-card"><div class="inv-card-title"><span>Purchase Records</span><button class="inv-btn inv-btn-primary" onclick="INV.openPoModal()"><i class="fa fa-plus"></i> Record Purchase</button></div>
    <table class="inv-table"><thead><tr><th>ID</th><th>Date</th><th>Item</th><th>Vendor</th><th>Qty</th><th>Total</th><th>Invoice</th><th>Journal</th></tr></thead><tbody id="poTbody"></tbody></table></div>
  </div>

  <!-- STOCK ISSUES -->
  <div class="inv-panel<?= $at === 'stock' ? ' active' : '' ?>" id="panelStock">
    <div class="inv-card"><div class="inv-card-title"><span>Stock Issues</span><button class="inv-btn inv-btn-primary" onclick="INV.openIssModal()"><i class="fa fa-plus"></i> Issue Stock</button></div>
    <table class="inv-table"><thead><tr><th>ID</th><th>Date</th><th>Item</th><th>Issued To</th><th>Qty</th><th>Purpose</th><th>Status</th><th>Actions</th></tr></thead><tbody id="issTbody"></tbody></table></div>
  </div>
</div></section></div>

<!-- ITEM MODAL -->
<div class="inv-modal-bg" id="itemModal"><div class="inv-modal">
  <div class="inv-modal-title"><span id="itemModalTitle">Add Item</span><button class="inv-modal-close" onclick="INV.closeItemModal()">&times;</button></div>
  <input type="hidden" id="itmId">
  <div class="inv-form-group"><label>Name *</label><input type="text" id="itmName"></div>
  <div class="inv-form-row"><div class="inv-form-group"><label>Category</label><select id="itmCatId"><option value="">-- None --</option></select></div><div class="inv-form-group"><label>Unit</label><input type="text" id="itmUnit" value="Pcs"></div></div>
  <div class="inv-form-row"><div class="inv-form-group"><label>Min Stock Alert</label><input type="number" id="itmMin" value="0" min="0"></div><div class="inv-form-group"><label>Location</label><input type="text" id="itmLoc"></div></div>
  <div class="inv-form-group"><label>Description</label><textarea id="itmDesc" rows="2"></textarea></div>
  <button class="inv-btn inv-btn-primary" onclick="INV.saveItem()" style="width:100%;margin-top:8px">Save Item</button>
</div></div>

<!-- CATEGORY MODAL -->
<div class="inv-modal-bg" id="invCatModal"><div class="inv-modal" style="width:420px">
  <div class="inv-modal-title"><span id="invCatModalTitle">Add Category</span><button class="inv-modal-close" onclick="INV.closeCatModal()">&times;</button></div>
  <input type="hidden" id="icatId">
  <div class="inv-form-group"><label>Name *</label><input type="text" id="icatName"></div>
  <div class="inv-form-group"><label>Description</label><textarea id="icatDesc" rows="2"></textarea></div>
  <button class="inv-btn inv-btn-primary" onclick="INV.saveCat()" style="width:100%;margin-top:8px">Save</button>
</div></div>

<!-- VENDOR MODAL -->
<div class="inv-modal-bg" id="vndModal"><div class="inv-modal">
  <div class="inv-modal-title"><span id="vndModalTitle">Add Vendor</span><button class="inv-modal-close" onclick="INV.closeVndModal()">&times;</button></div>
  <input type="hidden" id="vndId">
  <div class="inv-form-group"><label>Name *</label><input type="text" id="vndName"></div>
  <div class="inv-form-row"><div class="inv-form-group"><label>Contact</label><input type="text" id="vndContact"></div><div class="inv-form-group"><label>Email</label><input type="email" id="vndEmail"></div></div>
  <div class="inv-form-group"><label>Address</label><textarea id="vndAddress" rows="2"></textarea></div>
  <div class="inv-form-group"><label>GST Number</label><input type="text" id="vndGst"></div>
  <button class="inv-btn inv-btn-primary" onclick="INV.saveVnd()" style="width:100%;margin-top:8px">Save Vendor</button>
</div></div>

<!-- PURCHASE MODAL -->
<div class="inv-modal-bg" id="poModal"><div class="inv-modal">
  <div class="inv-modal-title"><span>Record Purchase</span><button class="inv-modal-close" onclick="INV.closePoModal()">&times;</button></div>
  <div class="inv-form-row"><div class="inv-form-group"><label>Item *</label><select id="poItemId"></select></div><div class="inv-form-group"><label>Vendor</label><select id="poVndId"><option value="">-- None --</option></select></div></div>
  <div class="inv-form-row"><div class="inv-form-group"><label>Quantity *</label><input type="number" id="poQty" value="1" min="1"></div><div class="inv-form-group"><label>Unit Price *</label><input type="number" id="poPrice" value="0" min="0" step="0.01"></div></div>
  <div class="inv-form-row"><div class="inv-form-group"><label>Date</label><input type="date" id="poDate"></div><div class="inv-form-group"><label>Invoice No.</label><input type="text" id="poInvoice"></div></div>
  <div class="inv-form-group"><label>Payment Mode</label><select id="poMode"><option value="Cash">Cash</option><option value="Bank">Bank</option></select></div>
  <button class="inv-btn inv-btn-primary" onclick="INV.savePo()" style="width:100%;margin-top:8px">Save Purchase</button>
</div></div>

<!-- STOCK ISSUE MODAL -->
<div class="inv-modal-bg" id="issModal"><div class="inv-modal" style="width:420px">
  <div class="inv-modal-title"><span>Issue Stock</span><button class="inv-modal-close" onclick="INV.closeIssModal()">&times;</button></div>
  <div class="inv-form-group"><label>Item *</label><select id="issItemId"></select></div>
  <div class="inv-form-row"><div class="inv-form-group"><label>Quantity *</label><input type="number" id="issQty" value="1" min="1"></div><div class="inv-form-group"><label>Issued To *</label><input type="text" id="issTo" placeholder="Person / Department"></div></div>
  <div class="inv-form-group"><label>Purpose</label><input type="text" id="issPurpose"></div>
  <button class="inv-btn inv-btn-primary" onclick="INV.saveIss()" style="width:100%;margin-top:8px">Issue</button>
</div></div>

<script>
var _INV_CFG={BASE:'<?= base_url() ?>',CSRF_NAME:'<?= $this->security->get_csrf_token_name() ?>',CSRF_HASH:'<?= $this->security->get_csrf_hash() ?>',activeTab:'<?= $at ?>'};
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';
  var BASE=_INV_CFG.BASE,CN=_INV_CFG.CSRF_NAME,CH=_INV_CFG.CSRF_HASH;
  var categories=[],items=[],vendors=[];
  function getJSON(u){return $.getJSON(BASE+u)} function post(u,d){d[CN]=CH;return $.post(BASE+u,d)}
  function toast(m,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;background:'+(ok?'var(--green)':'var(--rose)')+'">'+m+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3000)}
  function escH(s){return $('<span>').text(s||'').html()}
  function catName(id){for(var i=0;i<categories.length;i++) if(categories[i].id===id) return categories[i].name; return id||'—'}
  function vndName(id){for(var i=0;i<vendors.length;i++) if(vendors[i].id===id) return vendors[i].name; return id||'—'}
  window.INV={};

  function loadCats(){getJSON('inventory/get_categories').done(function(r){if(r.status!=='success')return;categories=r.categories||[];
    var html='';categories.forEach(function(c){html+='<tr><td>'+escH(c.id)+'</td><td>'+escH(c.name)+'</td><td>'+escH(c.description)+'</td><td><button class="inv-btn inv-btn-sm inv-btn-primary" onclick="INV.editCat(\''+c.id+'\')"><i class="fa fa-pencil"></i></button> <button class="inv-btn inv-btn-sm inv-btn-danger" onclick="INV.delCat(\''+c.id+'\')"><i class="fa fa-trash"></i></button></td></tr>'});
    $('#invCatsTbody').html(html||'<tr><td colspan="4" style="text-align:center;color:var(--t3)">No categories</td></tr>');
    var o='<option value="">-- None --</option>';categories.forEach(function(c){o+='<option value="'+c.id+'">'+escH(c.name)+'</option>'});$('#itmCatId').html(o);
  })}
  function loadItems(){getJSON('inventory/get_items').done(function(r){if(r.status!=='success')return;items=r.items||[];
    var html='';items.forEach(function(it){
      var stockBadge=it.current_stock<=it.min_stock?'<span class="inv-badge inv-badge-red">'+it.current_stock+'</span>':'<span class="inv-badge inv-badge-green">'+it.current_stock+'</span>';
      html+='<tr><td>'+escH(it.id)+'</td><td>'+escH(it.name)+'</td><td>'+escH(catName(it.category_id))+'</td><td>'+escH(it.unit)+'</td><td>'+stockBadge+'</td><td>'+it.min_stock+'</td><td>'+escH(it.location)+'</td>';
      html+='<td><button class="inv-btn inv-btn-sm inv-btn-primary" onclick="INV.editItem(\''+it.id+'\')"><i class="fa fa-pencil"></i></button> <button class="inv-btn inv-btn-sm inv-btn-danger" onclick="INV.delItem(\''+it.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
    });
    $('#itemsTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No items</td></tr>');
    var o='';items.forEach(function(it){o+='<option value="'+it.id+'">'+escH(it.name)+' (Stock: '+it.current_stock+')</option>'});$('#poItemId,#issItemId').html(o);
  })}
  function loadVendors(){getJSON('inventory/get_vendors').done(function(r){if(r.status!=='success')return;vendors=r.vendors||[];
    var html='';vendors.forEach(function(v){
      html+='<tr><td>'+escH(v.id)+'</td><td>'+escH(v.name)+'</td><td>'+escH(v.contact)+'</td><td>'+escH(v.email)+'</td><td>'+escH(v.gst)+'</td>';
      html+='<td><button class="inv-btn inv-btn-sm inv-btn-primary" onclick="INV.editVnd(\''+v.id+'\')"><i class="fa fa-pencil"></i></button> <button class="inv-btn inv-btn-sm inv-btn-danger" onclick="INV.delVnd(\''+v.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
    });
    $('#vndTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No vendors</td></tr>');
    var o='<option value="">-- None --</option>';vendors.forEach(function(v){o+='<option value="'+v.id+'">'+escH(v.name)+'</option>'});$('#poVndId').html(o);
  })}
  function loadPurchases(){getJSON('inventory/get_purchases').done(function(r){if(r.status!=='success')return;
    var html='';(r.purchases||[]).forEach(function(p){
      html+='<tr><td>'+escH(p.id)+'</td><td>'+escH(p.date)+'</td><td>'+escH(p.item_name)+'</td><td>'+escH(p.vendor_name)+'</td><td>'+p.qty+'</td><td>Rs '+p.total+'</td><td>'+escH(p.invoice_no)+'</td><td style="font-size:.75rem">'+escH(p.journal_id)+'</td></tr>';
    });
    $('#poTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No purchases</td></tr>');
  })}
  function loadIssues(){getJSON('inventory/get_issues').done(function(r){if(r.status!=='success')return;
    var html='';(r.issues||[]).forEach(function(iss){
      var stBadge=iss.status==='Issued'?'<span class="inv-badge inv-badge-blue">Issued</span>':'<span class="inv-badge inv-badge-green">Returned</span>';
      var act=iss.status==='Issued'?'<button class="inv-btn inv-btn-sm inv-btn-primary" onclick="INV.returnIss(\''+iss.id+'\','+iss.qty+')"><i class="fa fa-undo"></i></button>':'—';
      html+='<tr><td>'+escH(iss.id)+'</td><td>'+escH(iss.date)+'</td><td>'+escH(iss.item_name)+'</td><td>'+escH(iss.issued_to)+'</td><td>'+iss.qty+'</td><td>'+escH(iss.purpose)+'</td><td>'+stBadge+'</td><td>'+act+'</td></tr>';
    });
    $('#issTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No issues</td></tr>');
  })}

  // CRUD helpers
  INV.openCatModal=function(){$('#icatId,#icatName,#icatDesc').val('');$('#invCatModalTitle').text('Add Category');$('#invCatModal').addClass('show')};
  INV.closeCatModal=function(){$('#invCatModal').removeClass('show')};
  INV.editCat=function(id){var c=categories.find(function(x){return x.id===id});if(!c)return;$('#icatId').val(c.id);$('#icatName').val(c.name);$('#icatDesc').val(c.description);$('#invCatModalTitle').text('Edit Category');$('#invCatModal').addClass('show')};
  INV.saveCat=function(){post('inventory/save_category',{id:$('#icatId').val(),name:$('#icatName').val(),description:$('#icatDesc').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);INV.closeCatModal();loadCats()}else toast(r.message,false)})};
  INV.delCat=function(id){if(!confirm('Delete?'))return;post('inventory/delete_category',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadCats()}else toast(r.message,false)})};

  INV.openItemModal=function(){$('#itmId,#itmName,#itmLoc,#itmDesc').val('');$('#itmCatId').val('');$('#itmUnit').val('Pcs');$('#itmMin').val(0);$('#itemModalTitle').text('Add Item');$('#itemModal').addClass('show')};
  INV.closeItemModal=function(){$('#itemModal').removeClass('show')};
  INV.editItem=function(id){var it=items.find(function(x){return x.id===id});if(!it)return;$('#itmId').val(it.id);$('#itmName').val(it.name);$('#itmCatId').val(it.category_id);$('#itmUnit').val(it.unit);$('#itmMin').val(it.min_stock);$('#itmLoc').val(it.location);$('#itmDesc').val(it.description);$('#itemModalTitle').text('Edit Item');$('#itemModal').addClass('show')};
  INV.saveItem=function(){post('inventory/save_item',{id:$('#itmId').val(),name:$('#itmName').val(),category_id:$('#itmCatId').val(),unit:$('#itmUnit').val(),min_stock:$('#itmMin').val(),location:$('#itmLoc').val(),description:$('#itmDesc').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);INV.closeItemModal();loadItems()}else toast(r.message,false)})};
  INV.delItem=function(id){if(!confirm('Delete item?'))return;post('inventory/delete_item',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadItems()}else toast(r.message,false)})};

  INV.openVndModal=function(){$('#vndId,#vndName,#vndContact,#vndEmail,#vndAddress,#vndGst').val('');$('#vndModalTitle').text('Add Vendor');$('#vndModal').addClass('show')};
  INV.closeVndModal=function(){$('#vndModal').removeClass('show')};
  INV.editVnd=function(id){var v=vendors.find(function(x){return x.id===id});if(!v)return;$('#vndId').val(v.id);$('#vndName').val(v.name);$('#vndContact').val(v.contact);$('#vndEmail').val(v.email);$('#vndAddress').val(v.address);$('#vndGst').val(v.gst);$('#vndModalTitle').text('Edit Vendor');$('#vndModal').addClass('show')};
  INV.saveVnd=function(){post('inventory/save_vendor',{id:$('#vndId').val(),name:$('#vndName').val(),contact:$('#vndContact').val(),email:$('#vndEmail').val(),address:$('#vndAddress').val(),gst:$('#vndGst').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);INV.closeVndModal();loadVendors()}else toast(r.message,false)})};
  INV.delVnd=function(id){if(!confirm('Delete vendor?'))return;post('inventory/delete_vendor',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadVendors()}else toast(r.message,false)})};

  INV.openPoModal=function(){$('#poQty').val(1);$('#poPrice').val(0);$('#poDate').val(new Date().toISOString().slice(0,10));$('#poInvoice').val('');$('#poMode').val('Cash');$('#poModal').addClass('show')};
  INV.closePoModal=function(){$('#poModal').removeClass('show')};
  INV.savePo=function(){post('inventory/save_purchase',{item_id:$('#poItemId').val(),vendor_id:$('#poVndId').val(),qty:$('#poQty').val(),unit_price:$('#poPrice').val(),date:$('#poDate').val(),invoice_no:$('#poInvoice').val(),payment_mode:$('#poMode').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);INV.closePoModal();loadPurchases();loadItems()}else toast(r.message,false)})};

  INV.openIssModal=function(){$('#issQty').val(1);$('#issTo,#issPurpose').val('');$('#issModal').addClass('show')};
  INV.closeIssModal=function(){$('#issModal').removeClass('show')};
  INV.saveIss=function(){post('inventory/save_issue',{item_id:$('#issItemId').val(),qty:$('#issQty').val(),issued_to:$('#issTo').val(),purpose:$('#issPurpose').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);INV.closeIssModal();loadIssues();loadItems()}else toast(r.message,false)})};
  INV.returnIss=function(id,qty){var rq=prompt('Return how many?',qty);if(!rq)return;post('inventory/return_issue',{issue_id:id,return_qty:rq}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadIssues();loadItems()}else toast(r.message,false)})};

  // Init
  loadCats();loadItems();loadVendors();
  if(_INV_CFG.activeTab==='purchases') loadPurchases();
  if(_INV_CFG.activeTab==='stock') loadIssues();
  $('.inv-tab').on('click',function(){var h=$(this).attr('href');if(h.indexOf('/purchases')>-1) loadPurchases();if(h.indexOf('/stock')>-1) loadIssues()});
})();
});
</script>
