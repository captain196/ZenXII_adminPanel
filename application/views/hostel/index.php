<?php
$at = isset($active_tab) ? $active_tab : 'buildings';
$tab_map = [
    'buildings'   => ['panel'=>'panelBuildings',  'icon'=>'fa-building',   'label'=>'Buildings'],
    'rooms'       => ['panel'=>'panelRooms',      'icon'=>'fa-bed',        'label'=>'Rooms'],
    'allocations' => ['panel'=>'panelAlloc',      'icon'=>'fa-users',      'label'=>'Allocations'],
    'attendance'  => ['panel'=>'panelAtt',        'icon'=>'fa-check-square-o','label'=>'Attendance'],
];
?>
<style>
.hst-wrap{padding:20px;max-width:1400px;margin:0 auto}
.hst-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.hst-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.hst-header-icon i{color:var(--gold);font-size:1.1rem}
.hst-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.hst-breadcrumb a{color:var(--gold);text-decoration:none} .hst-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}
.hst-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.hst-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.hst-tab:hover{color:var(--t1)} .hst-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .hst-tab i{margin-right:6px;font-size:14px}
.hst-panel{display:none} .hst-panel.active{display:block}
.hst-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:18px}
.hst-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.hst-btn{padding:8px 16px;border-radius:var(--r-sm);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.hst-btn-primary{background:var(--gold);color:#fff} .hst-btn-primary:hover{background:var(--gold2)}
.hst-btn-danger{background:var(--rose);color:#fff} .hst-btn-sm{padding:6px 12px;font-size:12px}
.hst-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.hst-table th,.hst-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.hst-table th{color:var(--t3);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.hst-table td{color:var(--t1)} .hst-table tr:hover td{background:var(--gold-dim)}
.hst-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;font-family:var(--font-b)}
.hst-badge-green{background:rgba(34,197,94,.12);color:#22c55e} .hst-badge-red{background:rgba(239,68,68,.12);color:#ef4444}
.hst-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6} .hst-badge-amber{background:rgba(234,179,8,.12);color:#eab308}
.hst-form-group{margin-bottom:14px}
.hst-form-group label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:4px;font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.hst-form-group input,.hst-form-group select,.hst-form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.hst-form-group input:focus,.hst-form-group select:focus{border-color:var(--gold);outline:none}
.hst-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.hst-modal-bg{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center} .hst-modal-bg.show{display:flex}
.hst-modal{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);width:520px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:24px}
.hst-modal-title{font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.hst-modal-close{cursor:pointer;color:var(--t3);font-size:1.2rem;background:none;border:none}
.hst-occ-bar{height:6px;border-radius:3px;background:var(--border);overflow:hidden;margin-top:4px}
.hst-occ-fill{height:100%;border-radius:3px;background:var(--gold);transition:width .3s}
.hst-stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.hst-stat{background:var(--bg3);border-radius:var(--r-sm);padding:14px;text-align:center}
.hst-stat-val{font-family:var(--font-m);font-size:1.6rem;font-weight:700;color:var(--gold);line-height:1}
.hst-stat-lbl{font-size:12px;color:var(--t3);margin-top:4px;font-family:var(--font-b)}
.hst-att-mark{width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;cursor:pointer;border:2px solid var(--border);margin:2px;transition:all var(--ease)}
.hst-att-mark.P{background:rgba(34,197,94,.15);color:#22c55e;border-color:#22c55e}
.hst-att-mark.A{background:rgba(239,68,68,.15);color:#ef4444;border-color:#ef4444}
.hst-att-mark.L{background:rgba(59,130,246,.15);color:#3b82f6;border-color:#3b82f6}
.hst-search-box{position:relative} .hst-search-results{position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:0 0 var(--r-sm) var(--r-sm);max-height:200px;overflow-y:auto;z-index:100;display:none} .hst-search-results.show{display:block} .hst-search-item{padding:8px 12px;cursor:pointer;font-size:13px;color:var(--t1);border-bottom:1px solid var(--border);font-family:var(--font-b)} .hst-search-item:hover{background:var(--gold-dim)}
</style>

<div class="content-wrapper"><section class="content"><div class="hst-wrap">
  <div class="hst-header"><div>
    <div class="hst-header-icon"><i class="fa fa-building"></i> Hostel Management</div>
    <ol class="hst-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('operations') ?>">Operations</a></li><li>Hostel</li></ol>
  </div></div>

  <nav class="hst-tabs">
    <?php foreach ($tab_map as $slug => $t): ?>
    <a class="hst-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('hostel/' . $slug) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
  </nav>

  <!-- BUILDINGS -->
  <div class="hst-panel<?= $at === 'buildings' ? ' active' : '' ?>" id="panelBuildings">
    <div id="hostelStats"></div>
    <div class="hst-card">
      <div class="hst-card-title"><span>Buildings</span><button class="hst-btn hst-btn-primary" onclick="HST.openBldModal()"><i class="fa fa-plus"></i> Add Building</button></div>
      <table class="hst-table"><thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Floors</th><th>Warden</th><th>Status</th><th>Actions</th></tr></thead><tbody id="bldTbody"></tbody></table>
    </div>
  </div>

  <!-- ROOMS -->
  <div class="hst-panel<?= $at === 'rooms' ? ' active' : '' ?>" id="panelRooms">
    <div class="hst-card">
      <div class="hst-card-title"><span>Rooms</span><button class="hst-btn hst-btn-primary" onclick="HST.openRmModal()"><i class="fa fa-plus"></i> Add Room</button></div>
      <div class="hst-form-group" style="max-width:300px;margin-bottom:14px"><label>Filter by Building</label><select id="rmBldFilter"><option value="">All Buildings</option></select></div>
      <table class="hst-table"><thead><tr><th>ID</th><th>Building</th><th>Floor</th><th>Room#</th><th>Type</th><th>Beds</th><th>Occupied</th><th>Fee/Month</th><th>Actions</th></tr></thead><tbody id="rmTbody"></tbody></table>
    </div>
  </div>

  <!-- ALLOCATIONS -->
  <div class="hst-panel<?= $at === 'allocations' ? ' active' : '' ?>" id="panelAlloc">
    <div class="hst-card">
      <div class="hst-card-title"><span>Student Allocations</span><button class="hst-btn hst-btn-primary" onclick="HST.openAllocModal()"><i class="fa fa-plus"></i> Allocate Student</button></div>
      <table class="hst-table"><thead><tr><th>Student</th><th>Class</th><th>Building</th><th>Room</th><th>Bed</th><th>Check In</th><th>Fee</th><th>Status</th><th>Actions</th></tr></thead><tbody id="allocTbody"></tbody></table>
    </div>
  </div>

  <!-- ATTENDANCE -->
  <div class="hst-panel<?= $at === 'attendance' ? ' active' : '' ?>" id="panelAtt">
    <div class="hst-card">
      <div class="hst-card-title"><span>Hostel Attendance</span>
        <div style="display:flex;gap:10px;align-items:center">
          <input type="date" id="attDate" value="<?= date('Y-m-d') ?>" style="padding:6px 10px;border:1px solid var(--border);border-radius:var(--r-sm);background:var(--bg2);color:var(--t1);font-size:.84rem">
          <button class="hst-btn hst-btn-primary" onclick="HST.loadAtt()"><i class="fa fa-refresh"></i> Load</button>
          <button class="hst-btn hst-btn-primary" onclick="HST.saveAtt()"><i class="fa fa-save"></i> Save</button>
        </div>
      </div>
      <table class="hst-table"><thead><tr><th>Student</th><th>Room</th><th>Building</th><th style="text-align:center">Status</th></tr></thead><tbody id="attTbody"></tbody></table>
    </div>
  </div>
</div></section></div>

<!-- BUILDING MODAL -->
<div class="hst-modal-bg" id="bldModal"><div class="hst-modal">
  <div class="hst-modal-title"><span id="bldModalTitle">Add Building</span><button class="hst-modal-close" onclick="HST.closeBldModal()">&times;</button></div>
  <input type="hidden" id="bldId">
  <div class="hst-form-group"><label>Name *</label><input type="text" id="bldName"></div>
  <div class="hst-form-row"><div class="hst-form-group"><label>Type</label><select id="bldType"><option value="boys">Boys</option><option value="girls">Girls</option><option value="mixed">Mixed</option></select></div><div class="hst-form-group"><label>Floors</label><input type="number" id="bldFloors" value="1" min="1"></div></div>
  <div class="hst-form-row"><div class="hst-form-group"><label>Warden Name</label><input type="text" id="bldWarden"></div><div class="hst-form-group"><label>Address</label><input type="text" id="bldAddress"></div></div>
  <button class="hst-btn hst-btn-primary" onclick="HST.saveBld()" style="width:100%;margin-top:8px">Save Building</button>
</div></div>

<!-- ROOM MODAL -->
<div class="hst-modal-bg" id="rmModal"><div class="hst-modal" style="width:480px">
  <div class="hst-modal-title"><span id="rmModalTitle">Add Room</span><button class="hst-modal-close" onclick="HST.closeRmModal()">&times;</button></div>
  <input type="hidden" id="rmId">
  <div class="hst-form-group"><label>Building *</label><select id="rmBldId"></select></div>
  <div class="hst-form-row"><div class="hst-form-group"><label>Floor</label><input type="number" id="rmFloor" value="1" min="0"></div><div class="hst-form-group"><label>Room Number *</label><input type="text" id="rmNo"></div></div>
  <div class="hst-form-row"><div class="hst-form-group"><label>Type</label><select id="rmType"><option value="single">Single</option><option value="double">Double</option><option value="triple">Triple</option><option value="dormitory">Dormitory</option></select></div><div class="hst-form-group"><label>Beds</label><input type="number" id="rmBeds" value="2" min="1"></div></div>
  <div class="hst-form-group"><label>Monthly Fee (Rs)</label><input type="number" id="rmFee" value="0" min="0"></div>
  <div class="hst-form-group"><label>Facilities</label><input type="text" id="rmFacilities" placeholder="AC, Geyser, WiFi..."></div>
  <button class="hst-btn hst-btn-primary" onclick="HST.saveRm()" style="width:100%;margin-top:8px">Save Room</button>
</div></div>

<!-- ALLOCATION MODAL -->
<div class="hst-modal-bg" id="allocModal"><div class="hst-modal" style="width:480px;overflow:visible">
  <div class="hst-modal-title"><span>Allocate Student</span><button class="hst-modal-close" onclick="HST.closeAllocModal()">&times;</button></div>
  <div class="hst-form-group hst-search-box"><label>Student</label><input type="text" id="allocStudentSearch" placeholder="Search by name or ID..." autocomplete="off"><input type="hidden" id="allocStudentId"><div class="hst-search-results" id="allocStudentResults"></div></div>
  <div class="hst-form-group"><label>Room *</label><select id="allocRoomId"></select></div>
  <div class="hst-form-group"><label>Bed Number</label><input type="number" id="allocBed" value="1" min="1"></div>
  <button class="hst-btn hst-btn-primary" onclick="HST.saveAlloc()" style="width:100%;margin-top:8px">Allocate</button>
</div></div>

<script>
var _HST_CFG={BASE:'<?= base_url() ?>',CSRF_NAME:'<?= $this->security->get_csrf_token_name() ?>',CSRF_HASH:'<?= $this->security->get_csrf_hash() ?>',activeTab:'<?= $at ?>'};
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';
  var BASE=_HST_CFG.BASE,CN=_HST_CFG.CSRF_NAME,CH=_HST_CFG.CSRF_HASH;
  var buildings=[],rooms=[];
  function getJSON(u){return $.getJSON(BASE+u)} function post(u,d){d[CN]=CH;return $.post(BASE+u,d)}
  function toast(m,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;background:'+(ok?'var(--green)':'var(--rose)')+'">'+m+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3000)}
  function escH(s){return $('<span>').text(s||'').html()}
  function bldName(id){for(var i=0;i<buildings.length;i++) if(buildings[i].id===id) return buildings[i].name; return id||'—'}
  window.HST={};

  // ── Buildings ──
  function loadBuildings(){
    getJSON('hostel/get_buildings').done(function(r){if(r.status!=='success') return; buildings=r.buildings||[];
      var html='';buildings.forEach(function(b){
        html+='<tr><td>'+escH(b.id)+'</td><td>'+escH(b.name)+'</td><td>'+escH(b.type)+'</td><td>'+b.floors+'</td><td>'+escH(b.warden_name)+'</td><td><span class="hst-badge hst-badge-green">'+b.status+'</span></td>';
        html+='<td><button class="hst-btn hst-btn-sm hst-btn-primary" onclick="HST.editBld(\''+b.id+'\')"><i class="fa fa-pencil"></i></button> <button class="hst-btn hst-btn-sm hst-btn-danger" onclick="HST.delBld(\''+b.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#bldTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No buildings</td></tr>');
      var opts='<option value="">All Buildings</option>',selO='';
      buildings.forEach(function(b){opts+='<option value="'+b.id+'">'+escH(b.name)+'</option>';selO+='<option value="'+b.id+'">'+escH(b.name)+'</option>'});
      $('#rmBldFilter').html(opts);$('#rmBldId').html(selO);
    });
    // Load stats
    getJSON('hostel/get_stats').done(function(r){if(r.status!=='success')return;var s=r.stats;
      var h='<div class="hst-stat"><div class="hst-stat-val">'+s.total_buildings+'</div><div class="hst-stat-lbl">Buildings</div></div>';
      h+='<div class="hst-stat"><div class="hst-stat-val">'+s.total_rooms+'</div><div class="hst-stat-lbl">Rooms</div></div>';
      h+='<div class="hst-stat"><div class="hst-stat-val">'+s.total_beds+'</div><div class="hst-stat-lbl">Total Beds</div></div>';
      h+='<div class="hst-stat"><div class="hst-stat-val">'+s.total_occupied+'</div><div class="hst-stat-lbl">Occupied</div></div>';
      h+='<div class="hst-stat"><div class="hst-stat-val">'+s.occupancy_pct+'%</div><div class="hst-stat-lbl">Occupancy</div></div>';
      $('#hostelStats').html('<div class="hst-stats-row">'+h+'</div>');
    });
  }
  HST.openBldModal=function(){$('#bldId,#bldName,#bldWarden,#bldAddress').val('');$('#bldType').val('mixed');$('#bldFloors').val(1);$('#bldModalTitle').text('Add Building');$('#bldModal').addClass('show')};
  HST.closeBldModal=function(){$('#bldModal').removeClass('show')};
  HST.editBld=function(id){var b=buildings.find(function(x){return x.id===id});if(!b)return;$('#bldId').val(b.id);$('#bldName').val(b.name);$('#bldType').val(b.type);$('#bldFloors').val(b.floors);$('#bldWarden').val(b.warden_name);$('#bldAddress').val(b.address);$('#bldModalTitle').text('Edit Building');$('#bldModal').addClass('show')};
  HST.saveBld=function(){post('hostel/save_building',{id:$('#bldId').val(),name:$('#bldName').val(),type:$('#bldType').val(),floors:$('#bldFloors').val(),warden_name:$('#bldWarden').val(),address:$('#bldAddress').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);HST.closeBldModal();loadBuildings()}else toast(r.message,false)})};
  HST.delBld=function(id){if(!confirm('Delete building?'))return;post('hostel/delete_building',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadBuildings()}else toast(r.message,false)})};

  // ── Rooms ──
  function loadRooms(){
    var bid=$('#rmBldFilter').val()||'';
    getJSON('hostel/get_rooms?building_id='+bid).done(function(r){if(r.status!=='success') return; rooms=r.rooms||[];
      var html='';rooms.forEach(function(rm){
        var pct=rm.beds>0?Math.round((rm.occupied/rm.beds)*100):0;
        var occBadge=pct>=90?'hst-badge-red':pct>=50?'hst-badge-amber':'hst-badge-green';
        html+='<tr><td>'+escH(rm.id)+'</td><td>'+escH(bldName(rm.building_id))+'</td><td>'+rm.floor+'</td><td>'+escH(rm.room_no)+'</td><td>'+escH(rm.type)+'</td><td>'+rm.beds+'</td>';
        html+='<td><span class="hst-badge '+occBadge+'">'+rm.occupied+'/'+rm.beds+'</span></td><td>Rs '+rm.monthly_fee+'</td>';
        html+='<td><button class="hst-btn hst-btn-sm hst-btn-primary" onclick="HST.editRm(\''+rm.id+'\')"><i class="fa fa-pencil"></i></button> <button class="hst-btn hst-btn-sm hst-btn-danger" onclick="HST.delRm(\''+rm.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#rmTbody').html(html||'<tr><td colspan="9" style="text-align:center;color:var(--t3)">No rooms</td></tr>');
      // populate alloc room select
      var ro='';rooms.forEach(function(rm){if(rm.occupied<rm.beds) ro+='<option value="'+rm.id+'">'+escH(bldName(rm.building_id))+' - '+escH(rm.room_no)+' ('+rm.occupied+'/'+rm.beds+')</option>'});
      $('#allocRoomId').html(ro||'<option value="">No available rooms</option>');
    });
  }
  $('#rmBldFilter').on('change',loadRooms);
  HST.openRmModal=function(){$('#rmId,#rmNo,#rmFacilities').val('');$('#rmFloor').val(1);$('#rmType').val('double');$('#rmBeds').val(2);$('#rmFee').val(0);$('#rmModalTitle').text('Add Room');$('#rmModal').addClass('show')};
  HST.closeRmModal=function(){$('#rmModal').removeClass('show')};
  HST.editRm=function(id){var r=rooms.find(function(x){return x.id===id});if(!r)return;$('#rmId').val(r.id);$('#rmBldId').val(r.building_id);$('#rmFloor').val(r.floor);$('#rmNo').val(r.room_no);$('#rmType').val(r.type);$('#rmBeds').val(r.beds);$('#rmFee').val(r.monthly_fee);$('#rmFacilities').val(r.facilities||'');$('#rmModalTitle').text('Edit Room');$('#rmModal').addClass('show')};
  HST.saveRm=function(){post('hostel/save_room',{id:$('#rmId').val(),building_id:$('#rmBldId').val(),floor:$('#rmFloor').val(),room_no:$('#rmNo').val(),type:$('#rmType').val(),beds:$('#rmBeds').val(),monthly_fee:$('#rmFee').val(),facilities:$('#rmFacilities').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);HST.closeRmModal();loadRooms()}else toast(r.message,false)})};
  HST.delRm=function(id){if(!confirm('Delete room?'))return;post('hostel/delete_room',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadRooms()}else toast(r.message,false)})};

  // ── Allocations ──
  function loadAllocations(){
    getJSON('hostel/get_allocations').done(function(r){if(r.status!=='success') return;
      var html='';(r.allocations||[]).forEach(function(a){
        var stBadge=a.status==='Active'?'<span class="hst-badge hst-badge-green">Active</span>':'<span class="hst-badge hst-badge-amber">'+a.status+'</span>';
        var actions=a.status==='Active'?'<button class="hst-btn hst-btn-sm hst-btn-danger" onclick="HST.checkout(\''+a.student_id+'\')"><i class="fa fa-sign-out"></i> Check Out</button>':'—';
        html+='<tr><td>'+escH(a.student_name)+'</td><td>'+escH(a.student_class)+'</td><td>'+escH(a.building_name)+'</td><td>'+escH(a.room_no)+'</td><td>'+a.bed_no+'</td><td>'+escH(a.check_in)+'</td><td>Rs '+a.monthly_fee+'</td><td>'+stBadge+'</td><td>'+actions+'</td></tr>';
      });
      $('#allocTbody').html(html||'<tr><td colspan="9" style="text-align:center;color:var(--t3)">No allocations</td></tr>');
    });
  }
  // Student search
  var allocTimer;
  $('#allocStudentSearch').on('input',function(){clearTimeout(allocTimer);var q=$(this).val();if(q.length<2){$('#allocStudentResults').removeClass('show');return}
    allocTimer=setTimeout(function(){getJSON('hostel/search_students?q='+encodeURIComponent(q)).done(function(r){if(r.status!=='success'||!r.students.length){$('#allocStudentResults').html('<div class="hst-search-item" style="color:var(--t3);cursor:default">No students found</div>').addClass('show');return}var h='';r.students.forEach(function(s){h+='<div class="hst-search-item" data-id="'+s.id+'" data-name="'+escH(s.name)+'">'+escH(s.name)+' <small style="opacity:.6">'+escH(s.id)+'</small> <small>'+escH(s.class)+'</small></div>'});$('#allocStudentResults').html(h).addClass('show')}).fail(function(xhr){$('#allocStudentResults').html('<div class="hst-search-item" style="color:var(--rose);cursor:default">Search failed: '+(xhr.responseJSON?xhr.responseJSON.message:xhr.statusText)+'</div>').addClass('show')})},300)});
  $(document).on('click','#allocStudentResults .hst-search-item',function(){$('#allocStudentId').val($(this).data('id'));$('#allocStudentSearch').val($(this).data('name'));$('#allocStudentResults').removeClass('show')});
  $(document).on('click',function(e){if(!$(e.target).closest('.hst-search-box').length) $('.hst-search-results').removeClass('show')});
  HST.openAllocModal=function(){$('#allocStudentSearch,#allocStudentId').val('');$('#allocBed').val(1);$('#allocModal').addClass('show')};
  HST.closeAllocModal=function(){$('#allocModal').removeClass('show')};
  HST.saveAlloc=function(){post('hostel/save_allocation',{student_id:$('#allocStudentId').val(),room_id:$('#allocRoomId').val(),bed_no:$('#allocBed').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);HST.closeAllocModal();loadAllocations();loadRooms()}else toast(r.message,false)})};
  HST.checkout=function(sid){if(!confirm('Check out this student?'))return;post('hostel/delete_allocation',{student_id:sid}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadAllocations();loadRooms()}else toast(r.message,false)})};

  // ── Attendance ──
  var attData=[];
  HST.loadAtt=function(){
    var date=$('#attDate').val();
    getJSON('hostel/get_attendance?date='+date).done(function(r){if(r.status!=='success') return;
      var existing={};(r.attendance||[]).forEach(function(a){existing[a.student_id]=a.status||'P'});
      attData=[];var html='';(r.roster||[]).forEach(function(s){
        var st=existing[s.student_id]||'P';
        attData.push({student_id:s.student_id,status:st});
        html+='<tr><td>'+escH(s.student_name)+'</td><td>'+escH(s.room_no)+'</td><td>'+escH(s.building_name)+'</td>';
        html+='<td style="text-align:center">';
        ['P','A','L'].forEach(function(m){html+='<span class="hst-att-mark'+(st===m?' '+m:'')+'" data-sid="'+s.student_id+'" data-mark="'+m+'">'+m+'</span>'});
        html+='</td></tr>';
      });
      $('#attTbody').html(html||'<tr><td colspan="4" style="text-align:center;color:var(--t3)">No hostel residents</td></tr>');
    });
  };
  $(document).on('click','.hst-att-mark',function(){
    var sid=$(this).data('sid'),mark=$(this).data('mark');
    $(this).siblings().removeClass('P A L');$(this).addClass(mark);
    for(var i=0;i<attData.length;i++){if(attData[i].student_id===sid){attData[i].status=mark;break}}
  });
  HST.saveAtt=function(){
    if(!attData.length){toast('Load attendance first',false);return}
    post('hostel/save_attendance',{date:$('#attDate').val(),attendance:JSON.stringify(attData)}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success') toast(r.message,true); else toast(r.message,false);
    });
  };

  // Init
  loadBuildings();
  setTimeout(function(){loadRooms();if(_HST_CFG.activeTab==='allocations')loadAllocations();if(_HST_CFG.activeTab==='attendance')HST.loadAtt()},500);
  $('.hst-tab').on('click',function(){var h=$(this).attr('href');if(h.indexOf('/allocations')>-1) loadAllocations();if(h.indexOf('/attendance')>-1) HST.loadAtt()});
})();
});
</script>
