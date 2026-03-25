<?php $at = isset($active_tab) ? $active_tab : 'dashboard'; ?>

<style>
.ops-wrap{padding:20px;max-width:1400px;margin:0 auto}
.ops-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.ops-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.ops-header-icon i{color:var(--gold);font-size:1.1rem}
.ops-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.ops-breadcrumb a{color:var(--gold);text-decoration:none}
.ops-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}

.ops-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;margin-bottom:30px}
.ops-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:22px;cursor:pointer;transition:all var(--ease);text-decoration:none;display:block}
.ops-card:hover{border-color:var(--gold);box-shadow:0 6px 24px var(--gold-glow);transform:translateY(-2px)}
.ops-card-icon{width:48px;height:48px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:14px}
.ops-card-icon.lib{background:rgba(59,130,246,.12);color:#3b82f6}
.ops-card-icon.trn{background:rgba(234,179,8,.12);color:#eab308}
.ops-card-icon.hst{background:rgba(168,85,247,.12);color:#a855f7}
.ops-card-icon.inv{background:rgba(34,197,94,.12);color:#22c55e}
.ops-card-icon.ast{background:rgba(239,68,68,.12);color:#ef4444}
.ops-card h3{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin:0 0 6px}
.ops-card p{font-size:13px;color:var(--t3);margin:0 0 14px;line-height:1.45;font-family:var(--font-b)}
.ops-card-stats{display:flex;gap:16px;flex-wrap:wrap}
.ops-stat{text-align:center}
.ops-stat-val{font-family:var(--font-m);font-size:1.6rem;font-weight:700;color:var(--t1);line-height:1}
.ops-stat-lbl{font-size:12px;color:var(--t3);margin-top:2px;font-family:var(--font-b)}

.ops-alert{background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.25);border-radius:var(--r-sm);padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:20px;color:var(--amber);font-size:13px;font-family:var(--font-b)}
.ops-alert i{font-size:1.1rem}
.ops-loading{text-align:center;padding:40px;color:var(--t3)}
</style>

<div class="content-wrapper">
<section class="content">
<div class="ops-wrap">

  <div class="ops-header">
    <div>
      <div class="ops-header-icon"><i class="fa fa-cog"></i> Operations Management</div>
      <ol class="ops-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Operations</li>
      </ol>
    </div>
  </div>

  <div id="opsAlerts"></div>

  <div class="ops-grid" id="opsGrid">
    <!-- Library -->
    <a class="ops-card" href="<?= base_url('library') ?>">
      <div class="ops-card-icon lib"><i class="fa fa-book"></i></div>
      <h3>Library</h3>
      <p>Book catalog, issue/return, fines, and reports</p>
      <div class="ops-card-stats">
        <div class="ops-stat"><div class="ops-stat-val" id="sBooks">-</div><div class="ops-stat-lbl">Books</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sIssued">-</div><div class="ops-stat-lbl">Issued</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sOverdue">-</div><div class="ops-stat-lbl">Overdue</div></div>
      </div>
    </a>

    <!-- Transport -->
    <a class="ops-card" href="<?= base_url('transport') ?>">
      <div class="ops-card-icon trn"><i class="fa fa-bus"></i></div>
      <h3>Transport</h3>
      <p>Vehicles, routes, stops, and student assignments</p>
      <div class="ops-card-stats">
        <div class="ops-stat"><div class="ops-stat-val" id="sVehicles">-</div><div class="ops-stat-lbl">Vehicles</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sRoutes">-</div><div class="ops-stat-lbl">Routes</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sTrnStudents">-</div><div class="ops-stat-lbl">Students</div></div>
      </div>
    </a>

    <!-- Hostel -->
    <a class="ops-card" href="<?= base_url('hostel') ?>">
      <div class="ops-card-icon hst"><i class="fa fa-building"></i></div>
      <h3>Hostel</h3>
      <p>Buildings, rooms, bed allocation, and attendance</p>
      <div class="ops-card-stats">
        <div class="ops-stat"><div class="ops-stat-val" id="sBuildings">-</div><div class="ops-stat-lbl">Buildings</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sRooms">-</div><div class="ops-stat-lbl">Rooms</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sOccupants">-</div><div class="ops-stat-lbl">Occupants</div></div>
      </div>
    </a>

    <!-- Inventory -->
    <a class="ops-card" href="<?= base_url('inventory') ?>">
      <div class="ops-card-icon inv"><i class="fa fa-cubes"></i></div>
      <h3>Inventory</h3>
      <p>Items, vendors, purchases, and stock tracking</p>
      <div class="ops-card-stats">
        <div class="ops-stat"><div class="ops-stat-val" id="sItems">-</div><div class="ops-stat-lbl">Items</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sLowStock">-</div><div class="ops-stat-lbl">Low Stock</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sVendors">-</div><div class="ops-stat-lbl">Vendors</div></div>
      </div>
    </a>

    <!-- Assets -->
    <a class="ops-card" href="<?= base_url('assets') ?>">
      <div class="ops-card-icon ast"><i class="fa fa-laptop"></i></div>
      <h3>Asset Tracking</h3>
      <p>Registry, assignments, maintenance, and depreciation</p>
      <div class="ops-card-stats">
        <div class="ops-stat"><div class="ops-stat-val" id="sAssets">-</div><div class="ops-stat-lbl">Assets</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sAssigned">-</div><div class="ops-stat-lbl">Assigned</div></div>
        <div class="ops-stat"><div class="ops-stat-val" id="sMaintDue">-</div><div class="ops-stat-lbl">Maint. Due</div></div>
      </div>
    </a>
  </div>

</div>
</section>
</div>

<script>
var _OPS_CFG = {
  BASE: '<?= base_url() ?>',
  CSRF_NAME: '<?= $this->security->get_csrf_token_name() ?>',
  CSRF_HASH: '<?= $this->security->get_csrf_hash() ?>'
};

document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';
  var BASE = _OPS_CFG.BASE;

  function getJSON(url){
    return $.getJSON(BASE + url);
  }

  function loadSummary(){
    getJSON('operations/get_summary').done(function(r){
      if(r.status!=='success') return;
      var s = r.stats;
      // Library
      $('#sBooks').text(s.library.books);
      $('#sIssued').text(s.library.issued);
      $('#sOverdue').text(s.library.overdue);
      // Transport
      $('#sVehicles').text(s.transport.vehicles);
      $('#sRoutes').text(s.transport.routes);
      $('#sTrnStudents').text(s.transport.students);
      // Hostel
      $('#sBuildings').text(s.hostel.buildings);
      $('#sRooms').text(s.hostel.rooms);
      $('#sOccupants').text(s.hostel.occupants);
      // Inventory
      $('#sItems').text(s.inventory.items);
      $('#sLowStock').text(s.inventory.low_stock);
      $('#sVendors').text(s.inventory.vendors);
      // Assets
      $('#sAssets').text(s.assets.total);
      $('#sAssigned').text(s.assets.assigned);
      $('#sMaintDue').text(s.assets.maintenance_due);

      // Alerts
      var alerts = [];
      if(s.library.overdue > 0) alerts.push('<i class="fa fa-book"></i> '+s.library.overdue+' overdue library book(s)');
      if(s.inventory.low_stock > 0) alerts.push('<i class="fa fa-cubes"></i> '+s.inventory.low_stock+' inventory item(s) below minimum stock');
      if(s.assets.maintenance_due > 0) alerts.push('<i class="fa fa-wrench"></i> '+s.assets.maintenance_due+' asset(s) have maintenance due');
      if(alerts.length){
        var html = alerts.map(function(a){ return '<div class="ops-alert">'+a+'</div>'; }).join('');
        $('#opsAlerts').html(html);
      }
    });
  }

  loadSummary();
})();
});
</script>
