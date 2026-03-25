<?php
$at = isset($active_tab) ? $active_tab : 'vehicles';
$tab_map = [
    'vehicles'    => ['panel'=>'panelVehicles',    'icon'=>'fa-bus',           'label'=>'Vehicles'],
    'routes'      => ['panel'=>'panelRoutes',      'icon'=>'fa-road',          'label'=>'Routes & Stops'],
    'assignments' => ['panel'=>'panelAssignments',  'icon'=>'fa-users',         'label'=>'Assignments'],
];
?>
<style>
.trn-wrap{padding:20px;max-width:1400px;margin:0 auto}
.trn-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.trn-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.trn-header-icon i{color:var(--gold);font-size:1.1rem}
.trn-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.trn-breadcrumb a{color:var(--gold);text-decoration:none}
.trn-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}
.trn-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.trn-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.trn-tab:hover{color:var(--t1)} .trn-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .trn-tab i{margin-right:6px;font-size:14px}
.trn-panel{display:none} .trn-panel.active{display:block}
.trn-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:18px}
.trn-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.trn-btn{padding:8px 16px;border-radius:var(--r-sm);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.trn-btn-primary{background:var(--gold);color:#fff} .trn-btn-primary:hover{background:var(--gold2)}
.trn-btn-danger{background:var(--rose);color:#fff} .trn-btn-sm{padding:6px 12px;font-size:12px}
.trn-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.trn-table th,.trn-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.trn-table th{color:var(--t3);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.trn-table td{color:var(--t1)} .trn-table tr:hover td{background:var(--gold-dim)}
.trn-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;font-family:var(--font-b)}
.trn-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.trn-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.trn-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.trn-badge-red{background:rgba(239,68,68,.12);color:#ef4444}
.trn-form-group{margin-bottom:14px}
.trn-form-group label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:4px;font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.trn-form-group input,.trn-form-group select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.trn-form-group input:focus,.trn-form-group select:focus{border-color:var(--gold);outline:none}
.trn-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.trn-modal-bg{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center}
.trn-modal-bg.show{display:flex}
.trn-modal{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);width:560px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:24px}
.trn-modal-title{font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.trn-modal-close{cursor:pointer;color:var(--t3);font-size:1.2rem;background:none;border:none}
.trn-search-box{position:relative}
.trn-search-results{position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:0 0 var(--r-sm) var(--r-sm);max-height:200px;overflow-y:auto;z-index:100;display:none}
.trn-search-results.show{display:block}
.trn-search-item{padding:8px 12px;cursor:pointer;font-size:13px;color:var(--t1);border-bottom:1px solid var(--border);font-family:var(--font-b)}
.trn-search-item:hover{background:var(--gold-dim)}
</style>

<div class="content-wrapper"><section class="content"><div class="trn-wrap">
  <div class="trn-header"><div>
    <div class="trn-header-icon"><i class="fa fa-bus"></i> Transport Management</div>
    <ol class="trn-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('operations') ?>">Operations</a></li><li>Transport</li></ol>
  </div></div>

  <nav class="trn-tabs">
    <?php foreach ($tab_map as $slug => $t): ?>
    <a class="trn-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('transport/' . $slug) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
  </nav>

  <!-- VEHICLES -->
  <div class="trn-panel<?= $at === 'vehicles' ? ' active' : '' ?>" id="panelVehicles">
    <div class="trn-card">
      <div class="trn-card-title"><span>Vehicles</span><button class="trn-btn trn-btn-primary" onclick="TRN.openVehModal()"><i class="fa fa-plus"></i> Add Vehicle</button></div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Number</th><th>Type</th><th>Capacity</th><th>Driver</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead><tbody id="vehTbody"></tbody></table>
    </div>
  </div>

  <!-- ROUTES & STOPS -->
  <div class="trn-panel<?= $at === 'routes' ? ' active' : '' ?>" id="panelRoutes">
    <div class="trn-card">
      <div class="trn-card-title"><span>Routes</span><button class="trn-btn trn-btn-primary" onclick="TRN.openRouteModal()"><i class="fa fa-plus"></i> Add Route</button></div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Name</th><th>Vehicle</th><th>Distance</th><th>Monthly Fee</th><th>Actions</th></tr></thead><tbody id="routesTbody"></tbody></table>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Stops</span><button class="trn-btn trn-btn-primary" onclick="TRN.openStopModal()"><i class="fa fa-plus"></i> Add Stop</button></div>
      <div class="trn-form-group" style="max-width:300px;margin-bottom:14px">
        <label>Filter by Route</label><select id="stopRouteFilter"><option value="">All Routes</option></select>
      </div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Route</th><th>Stop Name</th><th>Pickup</th><th>Drop</th><th>Order</th><th>Actions</th></tr></thead><tbody id="stopsTbody"></tbody></table>
    </div>
  </div>

  <!-- ASSIGNMENTS -->
  <div class="trn-panel<?= $at === 'assignments' ? ' active' : '' ?>" id="panelAssignments">
    <div class="trn-card">
      <div class="trn-card-title"><span>Student Transport Assignments</span><button class="trn-btn trn-btn-primary" onclick="TRN.openAsnModal()"><i class="fa fa-plus"></i> Assign Student</button></div>
      <table class="trn-table"><thead><tr><th>Student</th><th>Class</th><th>Route</th><th>Stop</th><th>Type</th><th>Fee/Month</th><th>Assigned</th><th>Actions</th></tr></thead><tbody id="asnTbody"></tbody></table>
    </div>
  </div>
</div></section></div>

<!-- VEHICLE MODAL -->
<div class="trn-modal-bg" id="vehModal"><div class="trn-modal">
  <div class="trn-modal-title"><span id="vehModalTitle">Add Vehicle</span><button class="trn-modal-close" onclick="TRN.closeVehModal()">&times;</button></div>
  <input type="hidden" id="vhId">
  <div class="trn-form-row"><div class="trn-form-group"><label>Vehicle Number *</label><input type="text" id="vhNumber"></div><div class="trn-form-group"><label>Type</label><select id="vhType"><option>Bus</option><option>Van</option><option>Auto</option><option>Car</option></select></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Capacity</label><input type="number" id="vhCapacity" value="40" min="1"></div><div class="trn-form-group"><label>Driver Name</label><input type="text" id="vhDriverName"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Driver Phone</label><input type="text" id="vhDriverPhone"></div><div class="trn-form-group"><label>GPS Enabled</label><select id="vhGps"><option value="0">No</option><option value="1">Yes</option></select></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Insurance No.</label><input type="text" id="vhInsNo"></div><div class="trn-form-group"><label>Insurance Expiry</label><input type="date" id="vhInsExp"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Fitness Expiry</label><input type="date" id="vhFitExp"></div><div class="trn-form-group"><label>Status</label><select id="vhStatus"><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Maintenance">Maintenance</option></select></div></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveVehicle()" style="width:100%;margin-top:8px">Save Vehicle</button>
</div></div>

<!-- ROUTE MODAL -->
<div class="trn-modal-bg" id="routeModal"><div class="trn-modal" style="width:480px">
  <div class="trn-modal-title"><span id="routeModalTitle">Add Route</span><button class="trn-modal-close" onclick="TRN.closeRouteModal()">&times;</button></div>
  <input type="hidden" id="rtId">
  <div class="trn-form-group"><label>Route Name *</label><input type="text" id="rtName"></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Vehicle</label><select id="rtVehicleId"><option value="">-- None --</option></select></div><div class="trn-form-group"><label>Monthly Fee (Rs)</label><input type="number" id="rtFee" value="0" min="0"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Start Point</label><input type="text" id="rtStart"></div><div class="trn-form-group"><label>End Point</label><input type="text" id="rtEnd"></div></div>
  <div class="trn-form-group"><label>Distance (km)</label><input type="number" id="rtDistance" value="0" min="0" step="0.1"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveRoute()" style="width:100%;margin-top:8px">Save Route</button>
</div></div>

<!-- STOP MODAL -->
<div class="trn-modal-bg" id="stopModal"><div class="trn-modal" style="width:420px">
  <div class="trn-modal-title"><span id="stopModalTitle">Add Stop</span><button class="trn-modal-close" onclick="TRN.closeStopModal()">&times;</button></div>
  <input type="hidden" id="stId">
  <div class="trn-form-group"><label>Route *</label><select id="stRouteId"></select></div>
  <div class="trn-form-group"><label>Stop Name *</label><input type="text" id="stName"></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Pickup Time</label><input type="time" id="stPickup"></div><div class="trn-form-group"><label>Drop Time</label><input type="time" id="stDrop"></div></div>
  <div class="trn-form-group"><label>Order</label><input type="number" id="stOrder" value="0" min="0"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveStop()" style="width:100%;margin-top:8px">Save Stop</button>
</div></div>

<!-- ASSIGNMENT MODAL -->
<div class="trn-modal-bg" id="asnModal"><div class="trn-modal" style="width:480px;overflow:visible">
  <div class="trn-modal-title"><span>Assign Student</span><button class="trn-modal-close" onclick="TRN.closeAsnModal()">&times;</button></div>
  <div class="trn-form-group trn-search-box"><label>Student</label><input type="text" id="asnStudentSearch" placeholder="Search by name or ID..." autocomplete="off"><input type="hidden" id="asnStudentId"><div class="trn-search-results" id="asnStudentResults"></div></div>
  <div class="trn-form-group"><label>Route *</label><select id="asnRouteId"></select></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Stop</label><select id="asnStopId"><option value="">-- Select --</option></select></div><div class="trn-form-group"><label>Type</label><select id="asnType"><option value="both">Both (Pickup & Drop)</option><option value="pickup">Pickup Only</option><option value="drop">Drop Only</option></select></div></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveAssignment()" style="width:100%;margin-top:8px">Assign</button>
</div></div>

<script>
var _TRN_CFG={BASE:'<?= base_url() ?>',CSRF_NAME:'<?= $this->security->get_csrf_token_name() ?>',CSRF_HASH:'<?= $this->security->get_csrf_hash() ?>',activeTab:'<?= $at ?>'};
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';
  var BASE=_TRN_CFG.BASE,CSRF_NAME=_TRN_CFG.CSRF_NAME,CSRF_HASH=_TRN_CFG.CSRF_HASH;
  var vehicles=[],routes=[],stops=[];
  function getJSON(u){return $.getJSON(BASE+u)} function post(u,d){d[CSRF_NAME]=CSRF_HASH;return $.post(BASE+u,d)}
  function toast(m,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;background:'+(ok?'var(--green)':'var(--rose)')+'">'+m+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3000)}
  function escH(s){return $('<span>').text(s||'').html()}
  function vehName(id){for(var i=0;i<vehicles.length;i++) if(vehicles[i].id===id) return vehicles[i].number; return id||'—'}
  function routeName(id){for(var i=0;i<routes.length;i++) if(routes[i].id===id) return routes[i].name; return id||'—'}

  window.TRN={};

  // ── Vehicles ──
  function loadVehicles(){
    getJSON('transport/get_vehicles').done(function(r){
      if(r.status!=='success') return; vehicles=r.vehicles||[];
      var html=''; vehicles.forEach(function(v){
        var stCls=v.status==='Active'?'trn-badge-green':v.status==='Maintenance'?'trn-badge-amber':'trn-badge-red';
        html+='<tr><td>'+escH(v.id)+'</td><td>'+escH(v.number)+'</td><td>'+escH(v.type)+'</td><td>'+v.capacity+'</td><td>'+escH(v.driver_name)+'</td><td>'+escH(v.driver_phone)+'</td><td><span class="trn-badge '+stCls+'">'+v.status+'</span></td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editVeh(\''+v.id+'\')"><i class="fa fa-pencil"></i></button> <button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.delVeh(\''+v.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#vehTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No vehicles</td></tr>');
      var opts='<option value="">-- None --</option>'; vehicles.forEach(function(v){opts+='<option value="'+v.id+'">'+escH(v.number)+'</option>'});
      $('#rtVehicleId').html(opts);
    });
  }
  TRN.openVehModal=function(){$('#vhId,#vhNumber,#vhDriverName,#vhDriverPhone,#vhInsNo,#vhInsExp,#vhFitExp').val('');$('#vhCapacity').val(40);$('#vhType').val('Bus');$('#vhGps').val('0');$('#vhStatus').val('Active');$('#vehModalTitle').text('Add Vehicle');$('#vehModal').addClass('show')};
  TRN.closeVehModal=function(){$('#vehModal').removeClass('show')};
  TRN.editVeh=function(id){var v=vehicles.find(function(x){return x.id===id});if(!v)return;$('#vhId').val(v.id);$('#vhNumber').val(v.number);$('#vhType').val(v.type);$('#vhCapacity').val(v.capacity);$('#vhDriverName').val(v.driver_name);$('#vhDriverPhone').val(v.driver_phone);$('#vhGps').val(v.gps_enabled?'1':'0');$('#vhInsNo').val(v.insurance_no);$('#vhInsExp').val(v.insurance_expiry);$('#vhFitExp').val(v.fitness_expiry);$('#vhStatus').val(v.status||'Active');$('#vehModalTitle').text('Edit Vehicle');$('#vehModal').addClass('show')};
  TRN.saveVehicle=function(){post('transport/save_vehicle',{id:$('#vhId').val(),number:$('#vhNumber').val(),type:$('#vhType').val(),capacity:$('#vhCapacity').val(),driver_name:$('#vhDriverName').val(),driver_phone:$('#vhDriverPhone').val(),gps_enabled:$('#vhGps').val(),insurance_no:$('#vhInsNo').val(),insurance_expiry:$('#vhInsExp').val(),fitness_expiry:$('#vhFitExp').val(),status:$('#vhStatus').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeVehModal();loadVehicles()}else toast(r.message,false)})};
  TRN.delVeh=function(id){if(!confirm('Delete vehicle?'))return;post('transport/delete_vehicle',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadVehicles()}else toast(r.message,false)})};

  // ── Routes ──
  function loadRoutes(){
    getJSON('transport/get_routes').done(function(r){
      if(r.status!=='success') return; routes=r.routes||[];
      var html=''; routes.forEach(function(rt){
        html+='<tr><td>'+escH(rt.id)+'</td><td>'+escH(rt.name)+'</td><td>'+escH(vehName(rt.vehicle_id))+'</td><td>'+rt.distance_km+' km</td><td>Rs '+rt.monthly_fee+'</td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editRoute(\''+rt.id+'\')"><i class="fa fa-pencil"></i></button> <button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.delRoute(\''+rt.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#routesTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No routes</td></tr>');
      var opts='<option value="">All Routes</option>',selOpts='';
      routes.forEach(function(rt){opts+='<option value="'+rt.id+'">'+escH(rt.name)+'</option>';selOpts+='<option value="'+rt.id+'">'+escH(rt.name)+'</option>'});
      $('#stopRouteFilter').html(opts);$('#stRouteId').html(selOpts);$('#asnRouteId').html(selOpts);
    });
  }
  TRN.openRouteModal=function(){$('#rtId,#rtName,#rtStart,#rtEnd').val('');$('#rtFee,#rtDistance').val(0);$('#rtVehicleId').val('');$('#routeModalTitle').text('Add Route');$('#routeModal').addClass('show')};
  TRN.closeRouteModal=function(){$('#routeModal').removeClass('show')};
  TRN.editRoute=function(id){var r=routes.find(function(x){return x.id===id});if(!r)return;$('#rtId').val(r.id);$('#rtName').val(r.name);$('#rtVehicleId').val(r.vehicle_id);$('#rtFee').val(r.monthly_fee);$('#rtStart').val(r.start_point);$('#rtEnd').val(r.end_point);$('#rtDistance').val(r.distance_km);$('#routeModalTitle').text('Edit Route');$('#routeModal').addClass('show')};
  TRN.saveRoute=function(){post('transport/save_route',{id:$('#rtId').val(),name:$('#rtName').val(),vehicle_id:$('#rtVehicleId').val(),monthly_fee:$('#rtFee').val(),start_point:$('#rtStart').val(),end_point:$('#rtEnd').val(),distance_km:$('#rtDistance').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeRouteModal();loadRoutes()}else toast(r.message,false)})};
  TRN.delRoute=function(id){if(!confirm('Delete route and its stops?'))return;post('transport/delete_route',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadRoutes();loadStops()}else toast(r.message,false)})};

  // ── Stops ──
  function loadStops(){
    var routeId=$('#stopRouteFilter').val()||'';
    getJSON('transport/get_stops?route_id='+routeId).done(function(r){
      if(r.status!=='success') return; stops=r.stops||[];
      var html=''; stops.forEach(function(s){
        html+='<tr><td>'+escH(s.id)+'</td><td>'+escH(routeName(s.route_id))+'</td><td>'+escH(s.name)+'</td><td>'+escH(s.pickup_time)+'</td><td>'+escH(s.drop_time)+'</td><td>'+s.order+'</td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editStop(\''+s.id+'\')"><i class="fa fa-pencil"></i></button> <button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.delStop(\''+s.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#stopsTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No stops</td></tr>');
      // populate assignment stop select
      var stOpts='<option value="">-- Select --</option>';stops.forEach(function(s){stOpts+='<option value="'+s.id+'">'+escH(s.name)+'</option>'});$('#asnStopId').html(stOpts);
    });
  }
  $('#stopRouteFilter').on('change', loadStops);
  TRN.openStopModal=function(){$('#stId,#stName,#stPickup,#stDrop').val('');$('#stOrder').val(0);$('#stopModalTitle').text('Add Stop');$('#stopModal').addClass('show')};
  TRN.closeStopModal=function(){$('#stopModal').removeClass('show')};
  TRN.editStop=function(id){var s=stops.find(function(x){return x.id===id});if(!s)return;$('#stId').val(s.id);$('#stRouteId').val(s.route_id);$('#stName').val(s.name);$('#stPickup').val(s.pickup_time);$('#stDrop').val(s.drop_time);$('#stOrder').val(s.order);$('#stopModalTitle').text('Edit Stop');$('#stopModal').addClass('show')};
  TRN.saveStop=function(){post('transport/save_stop',{id:$('#stId').val(),route_id:$('#stRouteId').val(),name:$('#stName').val(),pickup_time:$('#stPickup').val(),drop_time:$('#stDrop').val(),order:$('#stOrder').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeStopModal();loadStops()}else toast(r.message,false)})};
  TRN.delStop=function(id){if(!confirm('Delete stop?'))return;post('transport/delete_stop',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadStops()}else toast(r.message,false)})};

  // ── Assignments ──
  var assignments=[];
  function loadAssignments(){
    getJSON('transport/get_assignments').done(function(r){
      if(r.status!=='success') return; assignments=r.assignments||[];
      var html=''; assignments.forEach(function(a){
        var typeBadge=a.type==='both'?'Pickup & Drop':a.type==='pickup'?'Pickup Only':'Drop Only';
        html+='<tr><td>'+escH(a.student_name)+'</td><td>'+escH(a.student_class)+'</td><td>'+escH(a.route_name)+'</td><td>'+escH(a.stop_name||'—')+'</td><td><span class="trn-badge trn-badge-blue">'+typeBadge+'</span></td><td>Rs '+a.monthly_fee+'</td><td>'+escH(a.assigned_date)+'</td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editAsn(\''+a.student_id+'\')"><i class="fa fa-pencil"></i></button> <button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.delAsn(\''+a.student_id+'\')"><i class="fa fa-times"></i></button></td></tr>';
      });
      $('#asnTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No assignments</td></tr>');
    });
  }
  TRN.openAsnModal=function(editData){
    $('#asnStudentSearch,#asnStudentId').val('');$('#asnType').val('both');$('#asnStopId').html('<option value="">-- Select --</option>');
    if(editData){
      // Edit mode: pre-fill fields
      $('#asnStudentId').val(editData.student_id);$('#asnStudentSearch').val(editData.student_name).prop('disabled',true);
      $('#asnRouteId').val(editData.route_id);$('#asnType').val(editData.type||'both');
      // Load stops for selected route, then select the stop
      loadStopsForAssignment(editData.route_id,editData.stop_id);
    } else {
      $('#asnStudentSearch').prop('disabled',false);
      // Load stops for first route in dropdown
      var firstRoute=$('#asnRouteId').val();
      if(firstRoute) loadStopsForAssignment(firstRoute);
    }
    $('#asnModal').addClass('show');
  };
  TRN.editAsn=function(sid){var a=assignments.find(function(x){return x.student_id===sid});if(!a)return;TRN.openAsnModal(a)};
  TRN.closeAsnModal=function(){$('#asnStudentSearch').prop('disabled',false);$('#asnModal').removeClass('show')};

  // Load stops for a route and optionally pre-select a stop
  function loadStopsForAssignment(routeId,selectStopId){
    if(!routeId)return;
    getJSON('transport/get_stops?route_id='+routeId).done(function(r){
      if(r.status!=='success')return;
      var o='<option value="">-- Select --</option>';
      (r.stops||[]).forEach(function(s){o+='<option value="'+s.id+'"'+(selectStopId===s.id?' selected':'')+'>'+escH(s.name)+'</option>'});
      $('#asnStopId').html(o);
    });
  }

  // Student search for assignments
  var asnTimer;
  $('#asnStudentSearch').on('input',function(){clearTimeout(asnTimer);var q=$(this).val();if(q.length<2){$('#asnStudentResults').removeClass('show');return}
    asnTimer=setTimeout(function(){getJSON('transport/search_students?q='+encodeURIComponent(q)).done(function(r){if(r.status!=='success'||!r.students.length){$('#asnStudentResults').html('<div class="trn-search-item" style="color:var(--t3);cursor:default">No students found</div>').addClass('show');return}var h='';r.students.forEach(function(s){h+='<div class="trn-search-item" data-id="'+s.id+'" data-name="'+escH(s.name)+'">'+escH(s.name)+' <small style="opacity:.6">'+escH(s.id)+'</small> <small>'+escH(s.class)+'</small></div>'});$('#asnStudentResults').html(h).addClass('show')}).fail(function(xhr){$('#asnStudentResults').html('<div class="trn-search-item" style="color:var(--rose);cursor:default">Search failed: '+(xhr.responseJSON?xhr.responseJSON.message:xhr.statusText)+'</div>').addClass('show')})},300)});
  $(document).on('click','#asnStudentResults .trn-search-item',function(){$('#asnStudentId').val($(this).data('id'));$('#asnStudentSearch').val($(this).data('name'));$('#asnStudentResults').removeClass('show')});
  $(document).on('click',function(e){if(!$(e.target).closest('.trn-search-box').length) $('#asnStudentResults').removeClass('show')});
  // Route change → load stops for assignment
  $('#asnRouteId').on('change',function(){loadStopsForAssignment($(this).val())});
  TRN.saveAssignment=function(){
    var sid=$('#asnStudentId').val();if(!sid){toast('Please select a student.',false);return}
    var rid=$('#asnRouteId').val();if(!rid){toast('Please select a route.',false);return}
    post('transport/save_assignment',{student_id:sid,route_id:rid,stop_id:$('#asnStopId').val(),type:$('#asnType').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeAsnModal();loadAssignments()}else toast(r.message,false)})};
  TRN.delAsn=function(sid){if(!confirm('Remove transport assignment?'))return;post('transport/delete_assignment',{student_id:sid}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadAssignments()}else toast(r.message,false)})};

  // Init
  loadVehicles();loadRoutes();
  setTimeout(function(){loadStops();loadAssignments()},500);
})();
});
</script>
