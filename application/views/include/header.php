<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>SchoolX</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

    <!-- CSRF tokens — must be before any scripts -->
    <meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
    <meta name="csrf-name"  content="<?= $this->security->get_csrf_token_name() ?>">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/Ionicons/css/ionicons.min.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/dist/css/AdminLTE.min.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/dist/css/skins/_all-skins.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.0.2/css/buttons.dataTables.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/morris.js/morris.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/bootstrap-daterangepicker/daterangepicker.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold:#0f766e;--gold2:#0d6b63;--gold3:#14b8a6;
            --gold-dim:rgba(15,118,110,.10);--gold-ring:rgba(15,118,110,.22);--gold-glow:rgba(15,118,110,.18);
            --green:#15803d;--blue:#4AB5E3;--rose:#E05C6F;--amber:#d97706;
            --sw:248px;--hh:58px;--r:12px;--r-sm:8px;
            --ease:.22s cubic-bezier(.4,0,.2,1);
            --font-d:'Syne',sans-serif;--font-b:'Plus Jakarta Sans',sans-serif;--font-m:'JetBrains Mono',monospace;
        }
        :root,[data-theme="night"],body[data-theme="night"]{
            --bg:#070f1c;--bg2:#0c1e38;--bg3:#0f2545;--bg4:#1a3555;
            --card:rgba(12,30,56,.92);--border:rgba(15,118,110,.12);--brd2:rgba(15,118,110,.25);
            --t1:#e6f4f1;--t2:#94c9c3;--t3:#5a9e98;--t4:#2e6b65;--sh:0 4px 32px rgba(0,0,0,.55);
        }
        [data-theme="day"],body[data-theme="day"]{
            --bg:#f0f7f5;--bg2:#ffffff;--bg3:#e6f4f1;--bg4:#cce9e4;--card:#ffffff;
            --border:rgba(15,118,110,.15);--brd2:rgba(15,118,110,.28);
            --t1:#0c1e38;--t2:#1a5c56;--t3:#5a9e98;--t4:#94c9c3;--sh:0 2px 16px rgba(0,0,0,.08);
        }
        /* ── Day mode: rich brand-teal sidebar — white text, high contrast ── */
        [data-theme="day"] .main-sidebar {
            --t1: #ffffff;
            --t2: rgba(230,244,241,.88);
            --t3: rgba(148,201,195,.72);
            --t4: rgba(148,201,195,.45);
            --bg2: #0d5c56;
            --bg3: rgba(255,255,255,.07);
            --bg4: rgba(255,255,255,.12);
            --border: rgba(255,255,255,.09);
            --brd2: rgba(255,255,255,.14);
            /* Hover uses white so it pops on dark-teal bg */
            --gold: #ffffff;
            --gold2: rgba(255,255,255,.85);
            --gold3: rgba(255,255,255,.65);
            --gold-dim: rgba(255,255,255,.10);
            --gold-ring: rgba(255,255,255,.22);
            --gold-glow: rgba(255,255,255,.10);
            background: #0d5c56 !important;
            border-right: none !important;
            box-shadow: 4px 0 24px rgba(13,92,86,.22) !important;
        }
        [data-theme="day"] .main-sidebar .g-sb-foot {
            background: rgba(0,0,0,.14) !important;
            border-top-color: rgba(255,255,255,.08) !important;
        }
        [data-theme="day"] .main-sidebar::after {
            background: linear-gradient(90deg,rgba(255,255,255,.18),rgba(255,255,255,.04),transparent);
        }
        /* ── Day mode: navbar search bar polish ── */
        [data-theme="day"] .g-search {
            background: #f5fbf9 !important;
            border: 1.5px solid rgba(15,118,110,.28) !important;
            box-shadow: 0 1px 6px rgba(15,118,110,.10), inset 0 1px 2px rgba(15,118,110,.04) !important;
        }
        [data-theme="day"] .g-search i {
            color: #0d6b63 !important;
        }
        [data-theme="day"] .g-search input {
            color: #0c1e38 !important;
        }
        [data-theme="day"] .g-search input::placeholder {
            color: #5a9e98 !important;
        }
        [data-theme="day"] .g-search:focus-within {
            border-color: rgba(15,118,110,.55) !important;
            box-shadow: 0 0 0 3px rgba(15,118,110,.12), 0 1px 6px rgba(15,118,110,.12) !important;
        }
        /* ── Night mode: sidebar slightly deeper than navbar for layered depth ── */
        [data-theme="night"] .main-sidebar {
            background: #091729 !important;
        }
        [data-theme="night"] .main-sidebar .g-sb-foot {
            background: #091729 !important;
        }
        html,body,.main-header,.main-sidebar,.content-wrapper,.main-footer,.box,.modal-content,.form-control,.btn{
            transition:background-color var(--ease),background var(--ease),border-color var(--ease),color var(--ease),box-shadow var(--ease) !important;
        }
        *,*::before,*::after{box-sizing:border-box;}
        body{font-family:var(--font-b) !important;background:var(--bg) !important;color:var(--t1) !important;}
        body.hold-transition{visibility:visible !important;}
        a{text-decoration:none !important;}

        /* HEADER */
        .main-header{position:fixed !important;top:0;left:0;right:0;height:var(--hh) !important;background:var(--bg2) !important;border-bottom:1px solid var(--border) !important;box-shadow:var(--sh) !important;z-index:1040 !important;display:flex !important;align-items:center !important;}
        .main-header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold) 0%,rgba(15,118,110,.25) 60%,transparent 100%);}
        .main-header .logo{width:var(--sw) !important;height:var(--hh) !important;background:var(--bg2) !important;border-right:1px solid var(--border) !important;border-bottom:none !important;display:flex !important;align-items:center !important;padding:0 12px 0 16px !important;flex-shrink:0;transition:width var(--ease) !important;justify-content:space-between !important;gap:0 !important;}
        .g-logo-link{display:flex !important;align-items:center !important;gap:10px !important;flex:1 !important;min-width:0 !important;text-decoration:none !important;}
        .g-mark{width:32px;height:32px;border-radius:8px;background:var(--gold);display:flex;align-items:center;justify-content:center;font-family:var(--font-d);font-size:16px;font-weight:800;color:#ffffff;flex-shrink:0;box-shadow:0 0 16px rgba(15,118,110,.4);}
        .g-logotext{line-height:1;overflow:hidden;}
        .g-logoname{font-family:var(--font-d);font-size:15px;font-weight:800;color:var(--t1);letter-spacing:-.3px;}
        .g-logoname b{color:var(--gold);}
        .g-logosub{font-family:var(--font-m);font-size:9px;color:var(--t3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
        .main-header .logo-mini{display:none !important;}
        /* sidebar-toggle now lives inside .logo — style it there */
        .main-header .logo .sidebar-toggle{flex-shrink:0 !important;width:30px !important;height:30px !important;border-radius:7px !important;display:flex !important;align-items:center !important;justify-content:center !important;border:1px solid rgba(15,118,110,.25) !important;color:var(--t3) !important;font-size:13px !important;margin:0 !important;transition:all var(--ease) !important;background:rgba(15,118,110,.06) !important;}
        .main-header .logo .sidebar-toggle:hover{background:rgba(15,118,110,.15) !important;color:var(--gold) !important;border-color:rgba(15,118,110,.4) !important;}
        .main-header .navbar{background:transparent !important;border:none !important;box-shadow:none !important;min-height:var(--hh) !important;margin-left:var(--sw) !important;padding:0 18px !important;display:flex !important;align-items:center !important;flex:1 !important;transition:margin-left var(--ease) !important;}
        .sidebar-toggle{width:34px !important;height:34px !important;border-radius:8px !important;background:transparent !important;border:1px solid var(--border) !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;color:var(--t3) !important;font-size:13px !important;margin-right:16px !important;flex-shrink:0;transition:all var(--ease) !important;}
        .sidebar-toggle:hover{background:var(--gold-dim) !important;color:var(--gold) !important;border-color:var(--gold-ring) !important;}
        .g-search{flex:1;max-width:480px;margin:0 auto !important;display:flex;align-items:center;background:var(--bg3) !important;border:1px solid var(--brd2) !important;border-radius:10px !important;padding:0 12px !important;height:36px;transition:all var(--ease);}
        .g-search:focus-within{border-color:rgba(15,118,110,.45) !important;box-shadow:0 0 0 3px var(--gold-glow) !important;}
        .g-search i{color:var(--t3);font-size:12px;margin-right:8px;flex-shrink:0;}
        .g-search input{background:none !important;border:none !important;outline:none !important;color:var(--t1) !important;font-family:var(--font-b) !important;font-size:13px !important;flex:1;min-width:0;}
        .g-search input::placeholder{color:var(--t4) !important;}
        .navbar-custom-menu{margin-left:0 !important;}
        .g-actions{display:flex;align-items:center;gap:6px;}
        .g-ibtn{position:relative;width:36px;height:36px;border-radius:9px;background:transparent;border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;color:var(--t3);font-size:15px;cursor:pointer;transition:all var(--ease);}
        .g-ibtn:hover{background:var(--gold-dim);color:var(--gold);border-color:var(--gold-ring);}
        .g-ibtn .g-dot{position:absolute;top:-3px;right:-3px;min-width:16px;height:16px;border-radius:8px;background:var(--rose);color:#fff;font-size:8.5px;font-weight:700;font-family:var(--font-m);display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid var(--bg2);line-height:1;}
        .g-ibtn .g-dot[data-n="0"]{display:none;}
        .g-theme-pill{display:flex;align-items:center;gap:7px;padding:6px 12px 6px 9px;background:var(--bg3);border:1px solid var(--brd2);border-radius:9px;color:var(--t2);font-size:12px;font-weight:600;font-family:var(--font-b);cursor:pointer;transition:all var(--ease);white-space:nowrap;user-select:none;}
        .g-theme-pill:hover{border-color:var(--gold);color:var(--gold);}
        .g-track{width:34px;height:18px;border-radius:20px;background:var(--bg4);border:1px solid var(--brd2);position:relative;flex-shrink:0;}
        .g-knob{position:absolute;top:2px;left:2px;width:12px;height:12px;border-radius:50%;background:var(--gold);box-shadow:0 1px 5px rgba(0,0,0,.25);transition:transform .3s cubic-bezier(.34,1.56,.64,1);}
        [data-theme="day"] .g-knob{transform:translateX(16px);}
        [data-theme="night"] .g-knob{transform:translateX(0);}
        .g-bell-wrap{position:relative;}
        .g-bell-panel{display:none;position:absolute;top:calc(100% + 8px);right:0;width:320px;background:var(--bg2);border:1px solid var(--brd2);border-radius:14px;box-shadow:var(--sh);z-index:9999;overflow:hidden;animation:panelIn .18s ease;}
        .g-bell-panel.open{display:block;}
        @keyframes panelIn{from{opacity:0;transform:translateY(-8px) scale(.96)}to{opacity:1;transform:none}}
        .g-bell-hd{padding:10px 16px 8px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;}
        .g-bell-title{font-family:var(--font-d);font-size:14px;font-weight:700;color:var(--t1);}
        .g-bell-tabs{display:flex;gap:2px;background:var(--bg);border-radius:6px;padding:2px;}
        .g-bell-tab{font:600 11px/1 var(--font-b);padding:5px 12px;border-radius:5px;border:none;background:transparent;color:var(--t3);cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:4px;}
        .g-bell-tab.active{background:var(--gold);color:#fff;}
        .g-bell-tab:not(.active):hover{color:var(--t1);}
        .g-bell-tab-badge{font:600 9px/1 var(--font-m);background:rgba(220,38,38,.15);color:#dc2626;padding:2px 5px;border-radius:8px;min-width:14px;text-align:center;}
        .g-bell-tab.active .g-bell-tab-badge{background:rgba(255,255,255,.25);color:#fff;}
        .g-bell-mark-btn{font-size:10px;font-family:var(--font-m);color:var(--t3);background:none;border:none;cursor:pointer;padding:3px 7px;border-radius:4px;transition:all .14s;}
        .g-bell-mark-btn:hover{background:var(--gold-dim);color:var(--gold);}
        .g-task-item{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--t2);transition:background .12s;}
        .g-task-item:hover{background:var(--gold-dim);}
        .g-task-icon{width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;color:#fff;flex-shrink:0;}
        .g-task-title{font:600 12px/1.3 var(--font-b);color:var(--t1);}
        .g-task-detail{font:400 10px/1.3 var(--font-m);color:var(--t3);margin-top:1px;}
        .g-task-pri{font:600 9px/1 var(--font-m);padding:2px 6px;border-radius:3px;flex-shrink:0;}
        .g-task-pri.high{background:rgba(220,38,38,.1);color:#dc2626;}
        .g-task-pri.medium{background:rgba(217,119,6,.1);color:#d97706;}
        .g-task-pri.low{background:rgba(15,118,110,.1);color:#0f766e;}
        .g-bell-list{max-height:290px;overflow-y:auto;}
        .g-bell-list::-webkit-scrollbar{width:3px;}
        .g-bell-list::-webkit-scrollbar-thumb{background:var(--bg4);border-radius:3px;}
        .g-bell-item{padding:11px 15px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:flex-start;cursor:pointer;transition:background .12s;text-decoration:none !important;}
        .g-bell-item:last-child{border-bottom:none;}
        .g-bell-item:hover{background:var(--bg3);}
        .g-bell-item.unread{background:rgba(15,118,110,.06);}
        .g-bld{width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:4px;}
        .g-bld.new{background:var(--gold);box-shadow:0 0 6px rgba(15,118,110,.55);}
        .g-bld.old{background:var(--bg4);}
        .g-bell-nt{font-size:12.5px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:238px;}
        .g-bell-nd{font-size:11.5px;color:var(--t2);overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:238px;margin-top:2px;}
        .g-bell-nt2{font-size:10px;color:var(--t3);font-family:var(--font-m);margin-top:3px;}
        .g-bell-empty{padding:26px 16px;text-align:center;color:var(--t3);font-size:12.5px;}
        .g-bell-empty i{font-size:22px;display:block;margin-bottom:8px;opacity:.35;}
        .g-bell-ft{border-top:1px solid var(--border);}
        .g-bell-ft a{display:block;text-align:center;padding:9px;font-size:12px;font-weight:600;color:var(--gold) !important;transition:background .13s;}
        .g-bell-ft a:hover{background:var(--gold-dim);}
        .user-menu>a{display:flex !important;align-items:center !important;gap:7px !important;padding:0 10px !important;height:var(--hh) !important;color:var(--t1) !important;}
        .user-menu>a .user-image{width:28px !important;height:28px !important;border-radius:7px !important;border:1.5px solid var(--gold) !important;margin:0 !important;}
        .user-menu>a span{font-size:13px !important;font-weight:600 !important;color:var(--t1) !important;}
        .navbar-nav .open>a{background:transparent !important;}
        .navbar-nav .dropdown-menu{background:var(--bg2) !important;border:1px solid var(--brd2) !important;border-radius:12px !important;box-shadow:var(--sh) !important;padding:6px !important;min-width:180px;top:calc(100% + 4px) !important;}
        .navbar-nav .dropdown-menu>li>a{color:var(--t2) !important;border-radius:7px !important;padding:8px 12px !important;font-size:12.5px !important;display:block !important;transition:all .13s !important;}
        .navbar-nav .dropdown-menu>li>a:hover{background:var(--gold-dim) !important;color:var(--gold) !important;}
        .user-header{background:var(--bg3) !important;padding:14px !important;border-radius:8px 8px 0 0 !important;}
        .user-header img{border-radius:8px !important;border:2px solid var(--gold) !important;}
        .user-header p{color:var(--t1) !important;font-size:13px !important;margin:0 !important;}
        .user-header p small{color:var(--gold) !important;display:block;margin-top:2px;}
        .user-footer{background:var(--bg3) !important;padding:8px 10px !important;border-top:1px solid var(--border) !important;border-radius:0 0 9px 9px !important;display:flex !important;justify-content:space-between !important;}
        .user-footer .btn{background:var(--gold-dim) !important;border:1px solid var(--gold-ring) !important;color:var(--gold) !important;border-radius:7px !important;font-size:12px !important;padding:5px 11px !important;}
        .user-footer .btn:hover{background:var(--gold-ring) !important;}

        /* SIDEBAR */
        .main-sidebar{position:fixed !important;top:var(--hh) !important;left:0 !important;bottom:0 !important;width:var(--sw) !important;background:var(--bg2) !important;border-right:1px solid var(--border) !important;z-index:1038 !important;display:flex !important;flex-direction:column !important;overflow:hidden !important;transition:width var(--ease),background var(--ease) !important;}
        .main-sidebar::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),rgba(15,118,110,.2),transparent);}
        .main-sidebar::before{content:'';position:absolute;inset:0;z-index:0;pointer-events:none;background-image:radial-gradient(circle,rgba(15,118,110,.04) 1px,transparent 1px);background-size:22px 22px;}
        .sidebar{flex:1 !important;overflow-y:auto !important;padding:8px 0 4px !important;position:relative !important;z-index:1 !important;}
        .sidebar::-webkit-scrollbar{width:2px;}
        .sidebar::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px;}
        .user-panel{display:none !important;}
        .sidebar-menu{list-style:none !important;margin:0 !important;padding:0 !important;}
        .sidebar-menu .g-sec{padding:14px 16px 5px !important;font-size:9.5px !important;font-weight:700 !important;color:var(--t4) !important;text-transform:uppercase !important;letter-spacing:1.1px !important;font-family:var(--font-m) !important;pointer-events:none !important;cursor:default !important;}
        .sidebar-menu .g-sec:first-child{padding-top:6px !important;}
        .sidebar-menu>li{margin:1px 10px !important;position:relative !important;}
        .sidebar-menu>li>a{font-family:var(--font-b) !important;font-size:13px !important;font-weight:500 !important;color:var(--t2) !important;padding:9px 12px !important;border-radius:9px !important;display:flex !important;align-items:center !important;gap:10px !important;transition:all var(--ease) !important;background:transparent !important;}
        .sidebar-menu>li>a:hover{color:var(--gold) !important;background:var(--gold-dim) !important;border-color:var(--gold-ring) !important;}
        .sidebar-menu>li.active>a,.sidebar-menu>li.active>a:hover{color:var(--t1) !important;background:var(--gold-dim) !important;font-weight:600 !important;}
        .sidebar-menu>li.active>a::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:22px;border-radius:0 3px 3px 0;background:var(--gold);box-shadow:0 0 8px rgba(15,118,110,.6);}
        .sidebar-menu>li>a>.fa,.sidebar-menu>li>a>i{width:18px !important;font-size:14px !important;text-align:center !important;flex-shrink:0 !important;color:var(--t3) !important;transition:color var(--ease) !important;}
        .sidebar-menu>li>a:hover>i,.sidebar-menu>li>a:hover>.fa,.sidebar-menu>li.active>a>i,.sidebar-menu>li.active>a>.fa{color:var(--gold) !important;}
        .sidebar-menu>li>a>span:not(.pull-right-container):not(.g-nb){flex:1;}
        .g-nb{background:var(--rose);color:#fff;font-size:9px;font-weight:700;font-family:var(--font-m);padding:1px 5px;border-radius:5px;flex-shrink:0;}
        .g-nb.gold{background:var(--gold);color:#ffffff;}
        .sidebar-menu>li>a>.pull-right-container{margin-left:auto !important;float:none !important;}
        .sidebar-menu .fa-angle-left{font-size:11px !important;color:var(--t4) !important;transition:transform .2s !important;}
        .sidebar-menu li.menu-open>a>.pull-right-container .fa-angle-left{transform:rotate(-90deg) !important;}
        .treeview-menu{background:transparent !important;padding:3px 0 5px 15px !important;margin:0 !important;list-style:none !important;border-left:1.5px solid rgba(15,118,110,.25) !important;margin-left:22px !important;}
        .treeview-menu>li{margin:1px 0 !important;}
        .treeview-menu>li>a{font-family:var(--font-b) !important;font-size:12.5px !important;color:var(--t3) !important;padding:6px 10px !important;border-radius:7px !important;display:flex !important;align-items:center !important;gap:7px !important;transition:all .14s !important;}
        .treeview-menu>li>a:hover{color:var(--gold) !important;background:var(--gold-dim) !important;}
        .treeview-menu>li.active>a{color:var(--gold) !important;font-weight:600 !important;}
        .treeview-menu .fa-circle-o{font-size:5px !important;opacity:.45 !important;flex-shrink:0 !important;}
        .treeview-menu>li>a:hover .fa-circle-o,.treeview-menu>li.active>a .fa-circle-o{color:var(--gold) !important;opacity:1 !important;}
        .g-sb-foot{position:relative;z-index:1;border-top:1px solid var(--border);padding:12px 14px;display:flex;align-items:center;gap:10px;background:var(--bg2);transition:background var(--ease);}
        .g-av{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:var(--gold);display:flex;align-items:center;justify-content:center;font-family:var(--font-d);font-size:13px;font-weight:800;color:#ffffff;box-shadow:0 0 10px rgba(15,118,110,.3);}
        .g-av-name{font-size:12.5px;font-weight:700;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px;}
        .g-av-role{font-size:10px;color:var(--t3);font-family:var(--font-m);}
        .g-av-out{margin-left:auto;width:29px;height:29px;border-radius:7px;background:transparent;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--t3);font-size:13px;cursor:pointer;transition:all var(--ease);flex-shrink:0;}
        .g-av-out:hover{background:rgba(224,92,111,.1);color:var(--rose);border-color:rgba(224,92,111,.25);}

        /* LAYOUT */
        .content-wrapper{background:var(--bg) !important;margin-left:var(--sw) !important;margin-top:var(--hh) !important;min-height:calc(100vh - var(--hh)) !important;color:var(--t1) !important;font-family:var(--font-b) !important;transition:margin-left var(--ease),background var(--ease) !important;}
        .main-footer{background:var(--bg2) !important;border-top:1px solid var(--border) !important;color:var(--t3) !important;font-size:12px !important;margin-left:var(--sw) !important;padding:11px 24px !important;transition:background var(--ease),margin-left var(--ease) !important;}

        /* ── FIX: Neutralize AdminLTE transform on content-wrapper ──
           AdminLTE applies transform:translate(230px,0) on .sidebar-open .content-wrapper
           at mobile breakpoint. Any CSS transform on an ancestor creates a new containing
           block, which breaks position:fixed modals (they become relative to the shifted
           content-wrapper instead of the viewport). Override with !important to ensure
           all fixed-position modals/toasts render correctly. */
        @media (max-width: 767px) {
            .sidebar-open .content-wrapper,
            .sidebar-open .main-footer {
                -webkit-transform: none !important;
                -ms-transform: none !important;
                -o-transform: none !important;
                transform: none !important;
                margin-left: 230px !important;
            }
        }

        /* ── Global modal overlay & container ──
           Shared base styles for ALL module modals (ev-, cm-, ast-, inv-, hst-, lib-, trn-)
           so fixed positioning, centering, close buttons, and fonts
           are consistent everywhere. */
        .ev-modal-bg,.cm-modal-bg,.ast-modal-bg,.inv-modal-bg,.hst-modal-bg,.lib-modal-bg,.trn-modal-bg,.lms-modal-bg,.cert-modal-bg {
            position: fixed !important;
            top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
            background: rgba(0,0,0,.55) !important;
            z-index: 9999 !important;
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
        }
        .ev-modal-bg.show,.cm-modal-bg.show,.ast-modal-bg.show,.inv-modal-bg.show,.hst-modal-bg.show,.lib-modal-bg.show,.trn-modal-bg.show,.lms-modal-bg.show,.cert-modal-bg.show { display: flex !important; }

        .ev-modal,.cm-modal,.ast-modal,.inv-modal,.hst-modal,.lib-modal,.trn-modal {
            background: var(--bg2) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--r, 10px) !important;
            max-width: 95vw !important;
            max-height: 85vh !important;
            overflow-y: auto !important;
            padding: 24px !important;
            font-family: var(--font-b) !important;
            font-size: 14px !important;
            color: var(--t1) !important;
            box-shadow: 0 16px 48px rgba(0,0,0,.25) !important;
        }

        /* ── Modal close button ── */
        .ev-modal-close,.cm-modal-close,.ast-modal-close,.inv-modal-close,.hst-modal-close,.lib-modal-close,.trn-modal-close {
            cursor: pointer !important;
            color: var(--t3) !important;
            font-size: 22px !important;
            line-height: 1 !important;
            background: none !important;
            border: none !important;
            width: 32px !important;
            height: 32px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 6px !important;
            transition: all .15s !important;
            flex-shrink: 0 !important;
        }
        .ev-modal-close:hover,.cm-modal-close:hover,.ast-modal-close:hover,.inv-modal-close:hover,.hst-modal-close:hover,.lib-modal-close:hover,.trn-modal-close:hover {
            background: rgba(224,92,111,.12) !important;
            color: var(--rose) !important;
        }

        /* ── Modal title ── */
        .ev-modal-title,.cm-modal-title,.ast-modal-title,.inv-modal-title,.hst-modal-title,.lib-modal-title,.trn-modal-title {
            font-family: var(--font-b) !important;
            font-size: 17px !important;
            font-weight: 700 !important;
            color: var(--t1) !important;
            margin-bottom: 20px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 12px !important;
        }

        /* ── Modal form elements ── */
        .ev-form-group,.cm-form-group,.ast-form-group,.inv-form-group,.hst-form-group,.lib-form-group,.trn-form-group { margin-bottom: 16px !important; }
        .ev-form-group label,.cm-form-group label,.ast-form-group label,.inv-form-group label,.hst-form-group label,.lib-form-group label,.trn-form-group label {
            display: block !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            color: var(--t3) !important;
            margin-bottom: 6px !important;
            font-family: var(--font-b) !important;
            text-transform: uppercase !important;
            letter-spacing: .3px !important;
        }
        .ev-form-group input,.ev-form-group select,.ev-form-group textarea,
        .cm-form-group input,.cm-form-group select,.cm-form-group textarea,
        .ast-form-group input,.ast-form-group select,.ast-form-group textarea,
        .inv-form-group input,.inv-form-group select,.inv-form-group textarea,
        .hst-form-group input,.hst-form-group select,.hst-form-group textarea,
        .lib-form-group input,.lib-form-group select,.lib-form-group textarea,
        .trn-form-group input,.trn-form-group select,.trn-form-group textarea {
            width: 100% !important;
            padding: 10px 12px !important;
            border: 1px solid var(--border) !important;
            border-radius: 8px !important;
            background: var(--bg) !important;
            color: var(--t1) !important;
            font-size: 14px !important;
            font-family: var(--font-b) !important;
            transition: border-color .15s !important;
        }
        .ev-form-group input:focus,.ev-form-group select:focus,.ev-form-group textarea:focus,
        .cm-form-group input:focus,.cm-form-group select:focus,.cm-form-group textarea:focus,
        .ast-form-group input:focus,.ast-form-group select:focus,.ast-form-group textarea:focus,
        .inv-form-group input:focus,.inv-form-group select:focus,.inv-form-group textarea:focus,
        .hst-form-group input:focus,.hst-form-group select:focus,.hst-form-group textarea:focus,
        .lib-form-group input:focus,.lib-form-group select:focus,.lib-form-group textarea:focus,
        .trn-form-group input:focus,.trn-form-group select:focus,.trn-form-group textarea:focus {
            border-color: var(--gold) !important;
            outline: none !important;
            box-shadow: 0 0 0 3px var(--gold-dim) !important;
        }

        /* ── Toast notifications ── */
        .ev-toast,.cm-toast,.ast-toast,.inv-toast,.hst-toast,.lib-toast,.trn-toast {
            position: fixed !important;
            top: 20px !important; right: 20px !important;
            z-index: 10000 !important;
            padding: 12px 20px !important;
            border-radius: 8px !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            font-family: var(--font-b) !important;
            color: #fff !important;
            display: none;
            max-width: 400px !important;
            box-shadow: 0 8px 24px rgba(0,0,0,.15) !important;
        }
        .sidebar-collapse .main-sidebar{width:56px !important;}
        .sidebar-collapse .content-wrapper,.sidebar-collapse .main-footer{margin-left:56px !important;}
        .sidebar-collapse .main-header .navbar{margin-left:56px !important;}
        .sidebar-collapse .main-header .logo{width:56px !important;padding:0 !important;justify-content:center !important;}
        /* Collapsed: hide brand link, show only the toggle centered */
        .sidebar-collapse .main-header .logo .g-logo-link{display:none !important;}
        .sidebar-collapse .main-header .logo .sidebar-toggle{margin:0 !important;width:34px !important;height:34px !important;}
        .sidebar-collapse .g-logotext,.sidebar-collapse .g-sec,.sidebar-collapse .sidebar-menu>li>a>span:not(.pull-right-container),.sidebar-collapse .sidebar-menu>li>a>.pull-right-container,.sidebar-collapse .treeview-menu,.sidebar-collapse .g-sb-foot .g-av-name,.sidebar-collapse .g-sb-foot .g-av-role,.sidebar-collapse .g-sb-foot .g-av-out{display:none !important;}
        .sidebar-collapse .sidebar-menu>li>a{justify-content:center !important;padding:10px !important;}
        .sidebar-collapse .sidebar-menu>li{margin:2px 5px !important;}
        .sidebar-collapse .g-sb-foot{justify-content:center !important;}

        /* COMPONENTS */
        .box{background:var(--bg2) !important;border:1px solid var(--border) !important;border-radius:var(--r) !important;box-shadow:var(--sh) !important;color:var(--t1) !important;}
        .box-header{background:transparent !important;border-bottom:1px solid var(--border) !important;padding:13px 18px !important;}
        .box-title{font-family:var(--font-d) !important;font-size:14px !important;font-weight:700 !important;color:var(--t1) !important;}
        .box-body{padding:18px !important;}
        .box-footer{background:var(--bg3) !important;border-top:1px solid var(--border) !important;border-radius:0 0 var(--r) var(--r) !important;padding:11px 18px !important;}
        .box-primary{border-top:3px solid var(--gold) !important;}
        .box-success{border-top:3px solid var(--green) !important;}
        .box-danger{border-top:3px solid var(--rose) !important;}
        .box-info{border-top:3px solid var(--blue) !important;}
        .form-control{background:var(--bg3) !important;border:1px solid var(--brd2) !important;color:var(--t1) !important;border-radius:var(--r-sm) !important;height:38px !important;font-size:13px !important;font-family:var(--font-b) !important;}
        .form-control:focus{border-color:rgba(15,118,110,.5) !important;box-shadow:0 0 0 3px var(--gold-glow) !important;background:var(--bg3) !important;color:var(--t1) !important;}
        .form-control::placeholder{color:var(--t4) !important;}
        textarea.form-control{height:auto !important;}
        select.form-control option{background:var(--bg3);color:var(--t1);}
        label,.control-label{font-size:11px !important;font-weight:700 !important;color:var(--t2) !important;text-transform:uppercase !important;letter-spacing:.5px !important;}
        .btn{border-radius:var(--r-sm) !important;font-size:13px !important;font-weight:600 !important;font-family:var(--font-b) !important;padding:7px 16px !important;}
        .btn-primary{background:var(--gold) !important;color:#ffffff !important;border:none !important;}
        .btn-primary:hover{background:var(--gold2) !important;box-shadow:0 4px 14px rgba(15,118,110,.4) !important;}
        .btn-success{background:rgba(61,214,140,.12) !important;color:var(--green) !important;border:1px solid rgba(61,214,140,.25) !important;}
        .btn-danger{background:rgba(224,92,111,.12) !important;color:var(--rose) !important;border:1px solid rgba(224,92,111,.25) !important;}
        .btn-default{background:var(--bg3) !important;color:var(--t2) !important;border:1px solid var(--border) !important;}
        .btn-default:hover{color:var(--gold) !important;border-color:var(--gold-ring) !important;background:var(--gold-dim) !important;}
        .btn-info{background:rgba(74,181,227,.12) !important;color:var(--blue) !important;border:1px solid rgba(74,181,227,.25) !important;}
        .btn-warning{background:var(--gold-dim) !important;color:var(--gold) !important;border:1px solid var(--gold-ring) !important;}
        .table{color:var(--t1) !important;font-size:13px !important;}
        .table>thead>tr>th{background:var(--bg3) !important;color:var(--t3) !important;font-size:10.5px !important;font-weight:700 !important;text-transform:uppercase !important;letter-spacing:.6px !important;border-bottom:1px solid var(--border) !important;}
        .table>tbody>tr>td{border-color:var(--border) !important;vertical-align:middle !important;}
        .table-striped>tbody>tr:nth-of-type(odd){background:rgba(15,118,110,.03) !important;}
        .table-hover>tbody>tr:hover{background:var(--gold-dim) !important;}
        /* ── Fees module font-size floor — prevents tiny text across all fee views ── */
        .fc-wrap,.fm-wrap,.sfr-page,.fr-wrap,.cf-wrap,.fd-wrap,
        .fm-page,[class*="fee_management"]{font-size:14px !important;}
        /* Page titles */
        .fc-wrap .fc-title,.fm-wrap .fm-page-title,.sfr-page h1,.fr-wrap h1,.cf-wrap h1,.fd-wrap h1,
        .fc-wrap h1,.fm-wrap h1{font-size:1.6rem !important;}
        /* Breadcrumbs */
        .fc-wrap .fc-breadcrumb,.fm-wrap .fm-breadcrumb,.sfr-page .breadcrumb,.fr-wrap .breadcrumb,.cf-wrap .breadcrumb{font-size:13px !important;}
        /* Tables: header + body */
        .fc-wrap table th,.fm-wrap table th,.sfr-page table th,.fr-wrap table th,.cf-wrap table th,.fd-wrap table th{font-size:12px !important;}
        .fc-wrap table td,.fm-wrap table td,.sfr-page table td,.fr-wrap table td,.cf-wrap table td,.fd-wrap table td{font-size:13px !important;}
        /* Buttons */
        .fc-wrap .btn,.fc-wrap button,.fm-wrap .btn,.fm-wrap button,
        .fr-wrap .btn,.cf-wrap .btn,.fd-wrap .btn{font-size:13px !important;}
        /* Form labels & inputs */
        .fc-wrap label,.fm-wrap label,.fr-wrap label,.cf-wrap label,.fd-wrap label{font-size:12.5px !important;}
        .fc-wrap input,.fc-wrap select,.fm-wrap input,.fm-wrap select,
        .fr-wrap input,.fr-wrap select,.cf-wrap input,.cf-wrap select{font-size:13.5px !important;}
        /* Stat cards / amounts */
        .fc-wrap .fc-stat-value,.fd-wrap .fd-stat-val{font-size:20px !important;}
        /* Badge / pill text */
        .fc-wrap .badge,.fm-wrap .badge,.fr-wrap .badge,.cf-wrap .badge{font-size:11.5px !important;}
        /* Fix critically small rem values in student_fees */
        .sfr-page table td{font-size:13px !important;}
        .sfr-page table th{font-size:12px !important;}
        .sfr-page .sfr-student-name{font-size:14px !important;}
        .sfr-page .sfr-amount,.sfr-page .sfr-balance{font-size:14px !important;}
        /* fee_management suite */
        .fm-page .fm-page-title{font-size:1.5rem !important;}
        .fm-page table td{font-size:13px !important;}
        .fm-page table th{font-size:12px !important;}
        .fm-page label{font-size:12.5px !important;}
        .fm-page input,.fm-page select,.fm-page textarea{font-size:13.5px !important;}
        .fm-page .fm-stat-label{font-size:12px !important;}
        .fm-page .fm-stat-value{font-size:18px !important;}

        .modal-content{background:var(--bg2) !important;border:1px solid var(--brd2) !important;border-radius:14px !important;color:var(--t1) !important;}
        .modal-header{border-bottom:1px solid var(--border) !important;}
        .modal-title{font-family:var(--font-d) !important;font-size:16px !important;font-weight:700 !important;color:var(--t1) !important;}
        .modal-footer{border-top:1px solid var(--border) !important;}
        .modal-backdrop{background:rgba(5,4,1,.8) !important;}
        .close{color:var(--t2) !important;opacity:1 !important;text-shadow:none !important;}
        .close:hover{color:var(--rose) !important;}
        .label,.badge{border-radius:5px !important;font-size:9.5px !important;font-family:var(--font-m) !important;padding:2px 7px !important;font-weight:700 !important;}
        .label-primary,.badge-primary{background:var(--gold) !important;color:#ffffff !important;}
        .label-success,.badge-success{background:rgba(61,214,140,.13) !important;color:var(--green) !important;border:1px solid rgba(61,214,140,.25) !important;}
        .label-danger,.badge-danger{background:rgba(224,92,111,.13) !important;color:var(--rose) !important;border:1px solid rgba(224,92,111,.25) !important;}
        .label-warning,.badge-warning{background:var(--gold-dim) !important;color:var(--gold) !important;border:1px solid var(--gold-ring) !important;}
        .label-info,.badge-info{background:rgba(74,181,227,.13) !important;color:var(--blue) !important;border:1px solid rgba(74,181,227,.25) !important;}
        .alert{border-radius:10px !important;border:none !important;font-size:13px !important;}
        .alert-success{background:rgba(61,214,140,.1) !important;color:var(--green) !important;border-left:3px solid var(--green) !important;}
        .alert-danger{background:rgba(224,92,111,.1) !important;color:var(--rose) !important;border-left:3px solid var(--rose) !important;}
        .alert-warning{background:var(--gold-dim) !important;color:var(--gold) !important;border-left:3px solid var(--gold) !important;}
        .alert-info{background:rgba(74,181,227,.1) !important;color:var(--blue) !important;border-left:3px solid var(--blue) !important;}
        .dataTables_wrapper .dataTables_length select,.dataTables_wrapper .dataTables_filter input{background:var(--bg3) !important;border:1px solid var(--brd2) !important;color:var(--t1) !important;border-radius:7px !important;padding:4px 10px !important;}
        .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{color:var(--t2) !important;font-size:12px !important;}
        .dataTables_wrapper .dataTables_paginate .paginate_button{border-radius:7px !important;color:var(--t2) !important;font-size:12px !important;border:none !important;}
        .dataTables_wrapper .dataTables_paginate .paginate_button.current{background:var(--gold) !important;color:#ffffff !important;border-color:var(--gold) !important;}
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover{background:var(--gold-dim) !important;color:var(--gold) !important;}
        .content-header{padding:24px 24px 0 !important;}
        .content-header h1{font-family:var(--font-d) !important;font-size:22px !important;font-weight:800 !important;color:var(--t1) !important;}
        .content-header .breadcrumb{background:transparent !important;padding:0 !important;}
        .content-header .breadcrumb>li+li::before{color:var(--t4) !important;}
        .content-header .breadcrumb>li>a{color:var(--gold) !important;}
        .content-header .breadcrumb>li.active{color:var(--t3) !important;}
        ::-webkit-scrollbar{width:5px;height:5px;}
        ::-webkit-scrollbar-track{background:var(--bg);}
        ::-webkit-scrollbar-thumb{background:rgba(15,118,110,.20);border-radius:4px;}
        ::-webkit-scrollbar-thumb:hover{background:rgba(15,118,110,.40);}
        /* ── Session Switcher ──────────────────────────────────────────────── */
        .g-sess-wrap{position:relative;}
        .g-sess-panel{position:absolute;top:calc(100% + 8px);right:0;min-width:176px;background:var(--bg2);border:1px solid var(--brd2);border-radius:12px;box-shadow:var(--sh);z-index:1050;display:none;overflow:hidden;}
        .g-sess-panel.open{display:block;}
        .g-sess-hd{padding:10px 14px 8px;font-size:10px;font-weight:700;font-family:var(--font-m);color:var(--t3);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--border);}
        .g-sess-list{list-style:none;margin:0;padding:6px 0;}
        .g-sess-item{padding:8px 14px;font-size:13px;font-family:var(--font-m);color:var(--t2);cursor:pointer;display:flex;align-items:center;gap:8px;transition:all var(--ease);}
        .g-sess-item:hover{background:var(--gold-dim);color:var(--gold);}
        .g-sess-item--active{color:var(--gold);font-weight:700;}
        .g-sess-check{font-size:10px;opacity:0;color:var(--gold);flex-shrink:0;}
        .g-sess-item--active .g-sess-check{opacity:1;}
        .g-sess-ft{padding:8px 14px;border-top:1px solid var(--border);}
        .g-sess-new-btn{width:100%;background:none;border:1px dashed var(--brd2);border-radius:8px;padding:6px 10px;color:var(--t3);font-size:12px;font-family:var(--font-b);cursor:pointer;transition:all var(--ease);}
        .g-sess-new-btn:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-dim);}
    </style>

    <!-- Global JS variables — available on every page that loads this header -->
    <script>
        var BASE_URL  = '<?= base_url() ?>';
        var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var csrfName  = document.querySelector('meta[name="csrf-name"]').getAttribute('content');

        // Auto-attach CSRF to ALL jQuery $.ajax() and $.post() calls project-wide
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof $ !== 'undefined') {
                $.ajaxSetup({
                    beforeSend: function (xhr, settings) {
                        if (settings.type === 'POST' || settings.type === 'post') {
                            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
                            if (typeof settings.data === 'string' && settings.data.length > 0) {
                                settings.data += '&' + csrfName + '=' + encodeURIComponent(csrfToken);
                            } else if (settings.data instanceof FormData) {
                                // FormData (file uploads, multipart forms) — must use .append()
                                // NOT settings.data[key] = val (that just sets a JS property)
                                if (!settings.data.has(csrfName)) {
                                    settings.data.append(csrfName, csrfToken);
                                }
                            } else if (typeof settings.data === 'object' && settings.data !== null) {
                                settings.data[csrfName] = csrfToken;
                            } else {
                                settings.data = csrfName + '=' + encodeURIComponent(csrfToken);
                            }
                        }
                    }
                });
            }
        });

        // Also patch native fetch() so POST requests carry the CSRF token both
        // in the header (for MY_Controller fallback) and in the body string
        // (for CI's built-in csrf_protection which reads $_POST, not headers).
        (function () {
            var _fetch = window.fetch;
            window.fetch = function (input, init) {
                init = init || {};
                if ((init.method || 'GET').toUpperCase() === 'POST') {
                    // 1. Add to request header
                    if (init.headers instanceof Headers) {
                        if (!init.headers.has('X-CSRF-Token')) {
                            init.headers.set('X-CSRF-Token', csrfToken);
                        }
                    } else {
                        init.headers = init.headers || {};
                        init.headers['X-CSRF-Token'] = init.headers['X-CSRF-Token'] || csrfToken;
                    }
                    // 2. Also inject into POST body string so CI's built-in
                    //    csrf_protection (which reads $_POST) can verify it.
                    if (typeof init.body === 'string' &&
                        init.body.indexOf(csrfName + '=') === -1) {
                        init.body += '&' + csrfName + '=' + encodeURIComponent(csrfToken);
                    }
                }
                return _fetch.call(this, input, init);
            };
        }());
    </script>
</head>


<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper">

<!-- ═══════════════════ TOP NAVBAR ═══════════════════ -->
<header class="main-header">
    <div class="logo">
        <a href="<?= base_url('admin') ?>" class="g-logo-link">
            <div class="g-mark">G</div>
            <div class="g-logotext">
                <div class="g-logoname"><b>School</b>X</div>
                <div class="g-logosub">
                    <?= isset($school_display_name) ? strtoupper(htmlspecialchars($school_display_name, ENT_QUOTES, 'UTF-8')) : (isset($school_name) ? strtoupper(htmlspecialchars($school_name, ENT_QUOTES, 'UTF-8')) : 'SCHOOL ERP') ?>
                    <?= isset($session_year) ? ' · ' . htmlspecialchars($session_year, ENT_QUOTES, 'UTF-8') : '' ?>
                </div>
            </div>
        </a>
        <a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button" title="Toggle sidebar"><i class="fa fa-bars"></i></a>
    </div>

    <nav class="navbar navbar-static-top">

        <div class="g-search">
            <i class="fa fa-search"></i>
            <input type="text" id="globalSearch" placeholder="Search anything…" autocomplete="off">
        </div>

        <div class="navbar-custom-menu">
            <div class="g-actions">

                <button class="g-theme-pill" id="themeToggle" title="Toggle day/night theme">
                    <div class="g-track"><div class="g-knob"></div></div>
                    <i id="themeIcon" class="fa fa-moon-o" style="font-size:11px;"></i>
                    <span id="themeLabel">Night</span>
                </button>

                <div class="g-bell-wrap" id="gBellWrap">
                    <button class="g-ibtn" id="gBellBtn" title="Notices">
                        <i class="fa fa-bell-o"></i>
                        <span class="g-dot" id="gBadge" data-n="0">0</span>
                    </button>
                    <div class="g-bell-panel" id="gBellPanel">
                        <div class="g-bell-hd">
                            <div class="g-bell-tabs">
                                <button class="g-bell-tab active" data-tab="notices" onclick="gSwitchBellTab('notices',this)">Notices</button>
                                <button class="g-bell-tab" data-tab="tasks" onclick="gSwitchBellTab('tasks',this)">
                                    Tasks <span class="g-bell-tab-badge" id="gTaskBadge" style="display:none">0</span>
                                </button>
                            </div>
                            <button class="g-bell-mark-btn" onclick="gMarkAllRead()">✓ Mark all read</button>
                        </div>
                        <div class="g-bell-list" id="gBellList">
                            <div class="g-bell-empty"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
                        </div>
                        <div class="g-bell-list" id="gTaskList" style="display:none">
                            <div class="g-bell-empty"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
                        </div>
                        <div class="g-bell-ft" id="gBellFtNotices">
                            <a href="<?= base_url('communication/notices') ?>">
                                <i class="fa fa-list-ul" style="margin-right:5px"></i>View All Notices
                            </a>
                        </div>
                        <div class="g-bell-ft" id="gBellFtTasks" style="display:none">
                            <a href="<?= base_url('admin') ?>">
                                <i class="fa fa-tachometer" style="margin-right:5px"></i>View Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ── Session Switcher ──────────────────────────────────── -->
                <div class="g-sess-wrap" id="gSessWrap">
                    <button class="g-ibtn g-sess-btn" id="gSessBtn" title="Switch Academic Session"
                            style="width:auto;padding:0 10px;gap:5px;font-size:12px;font-family:var(--font-m);">
                        <i class="fa fa-calendar-o"></i>
                        <span id="gSessLabel"><?= htmlspecialchars($session_year ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        <i class="fa fa-chevron-down" style="font-size:9px;opacity:.5;"></i>
                    </button>
                    <div class="g-sess-panel" id="gSessPanel">
                        <div class="g-sess-hd">Academic Session</div>
                        <ul class="g-sess-list">
                            <?php foreach ($available_sessions ?? [] as $yr):
                                $isActive = ($yr === ($session_year ?? ''));
                            ?>
                            <li class="g-sess-item<?= $isActive ? ' g-sess-item--active' : '' ?>"
                                data-year="<?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fa fa-check g-sess-check"></i>
                                <?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (in_array(($admin_role ?? ''), ['Super Admin', 'School Super Admin'])): ?>
                        <div class="g-sess-ft">
                            <button class="g-sess-new-btn" id="gSessNewBtn">
                                <i class="fa fa-plus"></i> New Session
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <ul class="nav navbar-nav" style="list-style:none;margin:0;padding:0">
                    <li class="dropdown user user-menu">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                            <img src="<?= base_url() ?>tools/dist/img/user2-160x160.jpg" class="user-image" alt="">
                            <span class="hidden-xs"><?= htmlspecialchars($admin_name ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></span>
                            <i class="fa fa-angle-down" style="font-size:10px;opacity:.4;margin-left:3px"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-right">
                            <li class="user-header">
                                <img src="<?= base_url() ?>tools/dist/img/user2-160x160.jpg" class="img-circle" style="width:52px;height:52px" alt="">
                                <p><?= htmlspecialchars($admin_name ?? 'Admin', ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars($admin_role ?? '', ENT_QUOTES, 'UTF-8') ?></small></p>
                            </li>
                            <li class="user-footer">
                                <div><a href="<?= base_url('admin/profile') ?>" class="btn btn-flat"><i class="fa fa-user" style="margin-right:5px"></i>Profile</a></div>
                                <div><a href="<?= base_url('admin_login/logout') ?>" class="btn btn-flat"><i class="fa fa-sign-out" style="margin-right:5px"></i>Logout</a></div>
                            </li>
                        </ul>
                    </li>
                </ul>

            </div>
        </div>
    </nav>
</header>

<!-- ── Create Session Modal — at body level so Bootstrap stacking works ── -->
<?php if (in_array(($admin_role ?? ''), ['Super Admin', 'School Super Admin'])): ?>
<div class="modal fade" id="gCreateSessModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Create New Session</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Session Year <small class="text-muted">(YYYY-YY format)</small></label>
                    <input type="text" id="gNewSessInput" class="form-control"
                           placeholder="e.g. 2026-27" maxlength="7">
                    <small class="help-block text-muted" id="gNewSessHint"></small>
                </div>
                <div id="gCreateSessError" class="alert alert-danger"
                     style="display:none;margin-bottom:0;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="gCreateSessSubmit">
                    <i class="fa fa-plus"></i> Create &amp; Switch
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════ SUBSCRIPTION WARNING BANNER ═══════════════════ -->
<?php if (!empty($subscription_warning)): ?>
<div id="subWarnBanner" style="
    position:fixed;top:var(--hh);left:0;right:0;z-index:1039;
    background:linear-gradient(90deg,#053d38,#0a5c55,#053d38);
    border-bottom:1px solid rgba(15,118,110,.4);
    padding:8px 20px;display:flex;align-items:center;gap:10px;
    font-family:var(--font-b);font-size:12.5px;color:#b2e0da;
    box-shadow:0 2px 12px rgba(0,0,0,.35);">
    <i class="fa fa-exclamation-triangle" style="color:#14b8a6;font-size:14px;flex-shrink:0;"></i>
    <span style="flex:1;"><?= htmlspecialchars($subscription_warning, ENT_QUOTES, 'UTF-8') ?></span>
    <a href="<?= base_url('admin_login/logout') ?>"
       style="background:rgba(15,118,110,.25);border:1px solid rgba(15,118,110,.5);color:#14b8a6;
              padding:3px 10px;border-radius:6px;font-size:11.5px;font-weight:700;white-space:nowrap;
              text-decoration:none;">
        Renew Now
    </a>
    <button onclick="document.getElementById('subWarnBanner').style.display='none'"
        style="background:none;border:none;color:#ffe9a0;opacity:.6;cursor:pointer;
               font-size:16px;line-height:1;padding:0;flex-shrink:0;"
        title="Dismiss">×</button>
</div>
<style>
    /* Push content down by the banner height (~38px) when warning is visible */
    #subWarnBanner ~ .main-sidebar { top: calc(var(--hh) + 38px) !important; }
    #subWarnBanner ~ * .content-wrapper,
    .sub-warn-offset .content-wrapper { margin-top: calc(var(--hh) + 38px) !important; }
</style>
<script>
    // Shift sidebar + content-wrapper down when banner is present
    document.addEventListener('DOMContentLoaded', function() {
        var banner = document.getElementById('subWarnBanner');
        if (!banner) return;
        var bh = banner.offsetHeight;
        var sidebar = document.querySelector('.main-sidebar');
        var cw = document.querySelector('.content-wrapper');
        if (sidebar) sidebar.style.top = (58 + bh) + 'px';
        if (cw) cw.style.marginTop = (58 + bh) + 'px';
        // Re-apply if dismissed
        banner.querySelector('button').addEventListener('click', function() {
            if (sidebar) sidebar.style.top = '58px';
            if (cw) cw.style.marginTop = '58px';
        });
    });
</script>
<?php endif; ?>

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside class="main-sidebar">
    <section class="sidebar">
        <?php
        // ── RBAC sidebar helper ──────────────────────────────────────
        // $rbac_permissions is shared by MY_Controller. Bypass roles
        // (Super Admin, Admin) already have all modules in the array.
        $rp = isset($rbac_permissions) && is_array($rbac_permissions) ? $rbac_permissions : [];
        $can = function(string $module) use ($rp, $admin_role) {
            // Bypass roles always see everything
            if (isset($admin_role) && in_array($admin_role, ['Super Admin', 'School Super Admin', 'Admin'], true)) return true;
            return in_array($module, $rp, true);
        };
        ?>
        <ul class="sidebar-menu" data-widget="tree">

            <!-- ═══════════════════════════════════════════════════════════
                 OVERVIEW
                 ═══════════════════════════════════════════════════════════ -->
            <li class="g-sec">Overview</li>
            <li class="sidebar-single">
                <a href="<?= base_url('admin') ?>"><i class="fa fa-th-large"></i><span>Dashboard</span></a>
            </li>

            <?php if (isset($school_features) && in_array('School Management', $school_features) && $can('Configuration')): ?>
            <li class="sidebar-single">
                <a href="<?= base_url('schools/schoolProfile') ?>"><i class="fa fa-building-o"></i><span>School Profile</span></a>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('School Management', $school_features) && $can('Configuration')): ?>
            <li class="sidebar-single">
                <a href="<?= base_url('school_config') ?>"><i class="fa fa-university"></i><span>Academic Setup</span></a>
            </li>
            <li class="sidebar-single">
                <a href="<?= base_url('school_config/admission_payment') ?>"><i class="fa fa-credit-card"></i><span>Admission Payment</span></a>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 ACADEMICS — classes, subjects, students, exams, results
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && (in_array('Student Management',$school_features)||in_array('Staff Management',$school_features)||in_array('Class Management',$school_features)||in_array('Subject Management',$school_features)||in_array('Exam Management',$school_features)) && ($can('Academic') || $can('LMS') || $can('Examinations') || $can('Results'))): ?>
            <li class="g-sec">Academics</li>
            <?php endif; ?>

           

            <?php if (isset($school_features) && in_array('Class Management', $school_features) && $can('Academic')): ?>
            <li class="sidebar-single"><a href="<?= base_url('academic') ?>"><i class="fa fa-university"></i><span>Academic Planner</span></a></li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Class Management', $school_features) && $can('LMS')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-laptop"></i><span>LMS</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('lms') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('lms/classes') ?>"><i class="fa fa-circle-o"></i>Online Classes</a></li>
                    <li><a href="<?= base_url('lms/materials') ?>"><i class="fa fa-circle-o"></i>Study Materials</a></li>
                    <li><a href="<?= base_url('lms/assignments') ?>"><i class="fa fa-circle-o"></i>Assignments</a></li>
                    <li><a href="<?= base_url('lms/quizzes') ?>"><i class="fa fa-circle-o"></i>Quizzes</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Exam Management', $school_features) && $can('Examinations')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-pencil-square-o"></i><span>Examinations</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('examination') ?>"><i class="fa fa-circle-o"></i>Exam Dashboard</a></li>
                    <li><a href="<?= base_url('exam') ?>"><i class="fa fa-circle-o"></i>Manage Exams</a></li>
                    <li><a href="<?= base_url('examination/tabulation') ?>"><i class="fa fa-circle-o"></i>Tabulation Sheet</a></li>
                    <li><a href="<?= base_url('examination/merit_list') ?>"><i class="fa fa-circle-o"></i>Merit Lists</a></li>
                    <li><a href="<?= base_url('examination/analytics') ?>"><i class="fa fa-circle-o"></i>Analytics</a></li>
                </ul>
            </li>

            <?php endif; ?>
            <?php if (isset($school_features) && in_array('Exam Management', $school_features) && $can('Results')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-bar-chart"></i><span>Results</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('result') ?>"><i class="fa fa-circle-o"></i>Results Hub</a></li>
                    <li><a href="<?= base_url('result/template_designer') ?>"><i class="fa fa-circle-o"></i>Template Designer</a></li>
                    <li><a href="<?= base_url('result/marks_entry') ?>"><i class="fa fa-circle-o"></i>Marks Entry</a></li>
                    <li><a href="<?= base_url('result/class_result') ?>"><i class="fa fa-circle-o"></i>Class Results</a></li>
                    <li><a href="<?= base_url('result/cumulative') ?>"><i class="fa fa-circle-o"></i>Cumulative</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 PEOPLE — students, staff, attendance, HR, admissions
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && (in_array('Student Management',$school_features)||in_array('Staff Management',$school_features)) && ($can('SIS') || $can('HR') || $can('Attendance') || $can('Certificates'))): ?>
            <li class="g-sec">People</li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Student Management', $school_features) && $can('SIS')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-id-badge"></i><span>Student Info System</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('sis') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('sis/students') ?>"><i class="fa fa-circle-o"></i>Student Records</a></li>
                    <li><a href="<?= base_url('sis/studentAdmission') ?>"><i class="fa fa-circle-o"></i>Admission</a></li>
                    <li><a href="<?= base_url('sis/master_student') ?>"><i class="fa fa-circle-o"></i>Import Students</a></li>
                    <li><a href="<?= base_url('sis/id_card') ?>"><i class="fa fa-circle-o"></i>ID Cards</a></li>
                    <li><a href="<?= base_url('sis/promote') ?>"><i class="fa fa-circle-o"></i>Promotion</a></li>
                    <li><a href="<?= base_url('sis/tc') ?>"><i class="fa fa-circle-o"></i>Transfer Certificates</a></li>
                </ul>
            </li>
            <li class="treeview">
                <a href="#"><i class="fa fa-funnel-dollar fa-filter"></i><span>Admission CRM</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('sis/crm') ?>"><i class="fa fa-circle-o"></i>CRM Dashboard</a></li>
                    <li><a href="<?= base_url('sis/admission_leads') ?>"><i class="fa fa-circle-o"></i>Admission Leads</a></li>
                    <li><a href="<?= base_url('sis/inquiries') ?>"><i class="fa fa-circle-o"></i>Inquiries</a></li>
                    <li><a href="<?= base_url('sis/applications') ?>"><i class="fa fa-circle-o"></i>Applications</a></li>
                    <li><a href="<?= base_url('sis/pipeline') ?>"><i class="fa fa-circle-o"></i>Pipeline</a></li>
                    <li><a href="<?= base_url('sis/waitlist') ?>"><i class="fa fa-circle-o"></i>Waiting List</a></li>
                    <li><a href="<?= base_url('sis/crm_settings') ?>"><i class="fa fa-circle-o"></i>CRM Settings</a></li>
                    <li><a href="<?= base_url('sis/admission_analytics') ?>"><i class="fa fa-circle-o"></i>Analytics</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- Certificate Management -->
            <?php if ($can('Certificates')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-certificate"></i><span>Certificates</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('certificates') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('certificates/templates') ?>"><i class="fa fa-circle-o"></i>Templates</a></li>
                    <li><a href="<?= base_url('certificates/generate') ?>"><i class="fa fa-circle-o"></i>Generate</a></li>
                    <li><a href="<?= base_url('certificates/issued') ?>"><i class="fa fa-circle-o"></i>Issued</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Staff Management', $school_features) && ($can('HR') || $can('SIS'))): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-user-o"></i><span>Staff</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('staff/all_staff') ?>"><i class="fa fa-circle-o"></i>All Staff</a></li>
                    <li><a href="<?= base_url('staff/new_staff') ?>"><i class="fa fa-circle-o"></i>New Staff</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Student Management', $school_features) && $can('Attendance')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-calendar-check-o"></i><span>Attendance</span>
                    <span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span>
                </a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('attendance') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('attendance/student') ?>"><i class="fa fa-circle-o"></i>Student Attendance</a></li>
                    <li><a href="<?= base_url('attendance/staff') ?>"><i class="fa fa-circle-o"></i>Staff Attendance</a></li>
                    <li><a href="<?= base_url('attendance/analytics') ?>"><i class="fa fa-circle-o"></i>Analytics</a></li>
                    <li><a href="<?= base_url('attendance/settings') ?>"><i class="fa fa-circle-o"></i>Settings</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if ($can('Red Flags')): ?>
            <li class="sidebar-single"><a href="<?= base_url('red_flags') ?>"><i class="fa fa-flag"></i><span>Red Flags</span></a></li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Staff Management', $school_features) && $can('HR')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-id-card-o"></i><span>HR &amp; Payroll</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('hr') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('hr/departments') ?>"><i class="fa fa-circle-o"></i>Departments</a></li>
                    <li><a href="<?= base_url('hr/recruitment') ?>"><i class="fa fa-circle-o"></i>Recruitment</a></li>
                    <li><a href="<?= base_url('ats') ?>"><i class="fa fa-circle-o"></i>Applicant Tracking</a></li>
                    <li><a href="<?= base_url('hr/leaves') ?>"><i class="fa fa-circle-o"></i>Leave Management</a></li>
                    <li><a href="<?= base_url('hr/payroll') ?>"><i class="fa fa-circle-o"></i>Payroll</a></li>
                    <li><a href="<?= base_url('hr/appraisals') ?>"><i class="fa fa-circle-o"></i>Appraisals</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 FINANCE — fees, accounts, accounting
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && (in_array('Fees Management',$school_features)||in_array('Account Management',$school_features)) && ($can('Fees') || $can('Accounting'))): ?>
            <li class="g-sec">Finance</li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Fees Management', $school_features) && $can('Fees')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-inr"></i><span>Fees</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('fees/dashboard') ?>"><i class="fa fa-dashboard"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('fees/fees_counter') ?>"><i class="fa fa-circle-o"></i>Fee Counter</a></li>
                    <li><a href="<?= base_url('fee_management/categories') ?>"><i class="fa fa-circle-o"></i>Titles &amp; Categories</a></li>
                    <li><a href="<?= base_url('fees/fees_chart') ?>"><i class="fa fa-circle-o"></i>Chart</a></li>
                    <li><a href="<?= base_url('fees/fees_records') ?>"><i class="fa fa-circle-o"></i>Records</a></li>
                    <li><a href="<?= base_url('fees/student_ledger') ?>"><i class="fa fa-circle-o"></i>Student Ledger</a></li>
                    <li><a href="<?= base_url('fees/defaulter_report') ?>"><i class="fa fa-circle-o"></i>Defaulters</a></li>
                    <li><a href="<?= base_url('fee_management/discounts') ?>"><i class="fa fa-circle-o"></i>Discounts</a></li>
                    <li><a href="<?= base_url('fee_management/scholarships') ?>"><i class="fa fa-circle-o"></i>Scholarships</a></li>
                    <li><a href="<?= base_url('fee_management/refunds') ?>"><i class="fa fa-circle-o"></i>Refunds</a></li>
                    <li><a href="<?= base_url('fee_management/reminders') ?>"><i class="fa fa-circle-o"></i>Reminders</a></li>
                    <li><a href="<?= base_url('fee_management/gateway') ?>"><i class="fa fa-circle-o"></i>Payment Gateway</a></li>
                    <li><a href="<?= base_url('fee_management/online_payments') ?>"><i class="fa fa-circle-o"></i>Online Payments</a></li>
                    <li><a href="<?= base_url('fees/transaction_audit') ?>"><i class="fa fa-shield"></i>Transaction Audit</a></li>
                    <li><a href="<?= base_url('fee_management/payment_reconciliation') ?>"><i class="fa fa-balance-scale"></i>Reconciliation</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Account Management', $school_features) && $can('Accounting')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-briefcase"></i><span>Accounts</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('account/account_book') ?>"><i class="fa fa-circle-o"></i>Account Book</a></li>
                    <li><a href="<?= base_url('account/view_accounts') ?>"><i class="fa fa-circle-o"></i>View Accounts</a></li>
                    <li><a href="<?= base_url('account/vouchers') ?>"><i class="fa fa-circle-o"></i>Create Vouchers</a></li>
                    <li><a href="<?= base_url('account/view_voucher') ?>"><i class="fa fa-circle-o"></i>View Vouchers</a></li>
                    <li><a href="<?= base_url('account/day_book') ?>"><i class="fa fa-circle-o"></i>Day Book</a></li>
                    <li><a href="<?= base_url('account/cash_book') ?>"><i class="fa fa-circle-o"></i>Cash Book</a></li>
                </ul>
            </li>

            <li class="treeview">
                <a href="#"><i class="fa fa-calculator"></i><span>Accounting</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('accounting') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('accounting/chart') ?>"><i class="fa fa-circle-o"></i>Chart of Accounts</a></li>
                    <li><a href="<?= base_url('accounting/ledger') ?>"><i class="fa fa-circle-o"></i>Journal Entries</a></li>
                    <li><a href="<?= base_url('accounting/income-expense') ?>"><i class="fa fa-circle-o"></i>Income & Expense</a></li>
                    <li><a href="<?= base_url('accounting/cash-book') ?>"><i class="fa fa-circle-o"></i>Cash Book</a></li>
                    <li><a href="<?= base_url('accounting/bank-recon') ?>"><i class="fa fa-circle-o"></i>Bank Reconciliation</a></li>
                    <li><a href="<?= base_url('accounting/reports') ?>"><i class="fa fa-circle-o"></i>Financial Reports</a></li>
                    <li><a href="<?= base_url('accounting/settings') ?>"><i class="fa fa-circle-o"></i>Settings</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 CAMPUS — communication, events, gallery, operations
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && (in_array('Notice and Announcement',$school_features)||in_array('School Management',$school_features)) && ($can('Communication') || $can('Events') || $can('Operations'))): ?>
            <li class="g-sec">Campus</li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Notice and Announcement', $school_features) && $can('Communication')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-comments"></i><span>Communication</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('communication') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('communication/messages') ?>"><i class="fa fa-circle-o"></i>Messages</a></li>
                    <li><a href="<?= base_url('communication/notices') ?>"><i class="fa fa-circle-o"></i>Notice Board</a></li>
                    <li><a href="<?= base_url('communication/circulars') ?>"><i class="fa fa-circle-o"></i>Circulars</a></li>
                    <li><a href="<?= base_url('communication/templates') ?>"><i class="fa fa-circle-o"></i>Templates</a></li>
                    <li><a href="<?= base_url('communication/triggers') ?>"><i class="fa fa-circle-o"></i>Alert Triggers</a></li>
                    <li><a href="<?= base_url('communication/logs') ?>"><i class="fa fa-circle-o"></i>Delivery Logs</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if ($can('Message Monitor')): ?>
            <li class="sidebar-single"><a href="<?= base_url('message_monitor') ?>"><i class="fa fa-eye"></i><span>Message Monitor</span></a></li>
            <?php endif; ?>

            <?php if ($can('Homework')): ?>
            <li class="sidebar-single"><a href="<?= base_url('homework') ?>"><i class="fa fa-book"></i><span>Homework</span></a></li>
            <?php endif; ?>

            <?php if ($can('Stories')): ?>
            <li class="sidebar-single"><a href="<?= base_url('stories') ?>"><i class="fa fa-camera-retro"></i><span>Stories</span></a></li>
            <?php endif; ?>


            <?php if (isset($school_features) && in_array('Notice and Announcement', $school_features) && $can('Events')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-calendar"></i><span>Events</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('events') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('events/list') ?>"><i class="fa fa-circle-o"></i>All Events</a></li>
                    <li><a href="<?= base_url('events/calendar') ?>"><i class="fa fa-circle-o"></i>Calendar</a></li>
                    <li><a href="<?= base_url('events/participation') ?>"><i class="fa fa-circle-o"></i>Participation</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('School Management', $school_features) && $can('Events')): ?>
            <li class="sidebar-single">
                <a href="<?= base_url('schools/schoolgallery') ?>"><i class="fa fa-picture-o"></i><span>Gallery</span></a>
            </li>
            <?php endif; ?>

            <?php if ($can('Operations')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-cog"></i><span>Operations</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('operations') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('library') ?>"><i class="fa fa-circle-o"></i>Library</a></li>
                    <li><a href="<?= base_url('transport') ?>"><i class="fa fa-circle-o"></i>Transport</a></li>
                    <li><a href="<?= base_url('hostel') ?>"><i class="fa fa-circle-o"></i>Hostel</a></li>
                    <li><a href="<?= base_url('inventory') ?>"><i class="fa fa-circle-o"></i>Inventory</a></li>
                    <li><a href="<?= base_url('assets') ?>"><i class="fa fa-circle-o"></i>Assets</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 SETTINGS & SYSTEM
                 ═══════════════════════════════════════════════════════════ -->
            <?php if ($can('Admin Users') || $can('Reports')): ?>
            <li class="g-sec">Settings</li>
            <?php endif; ?>

            <?php if ($can('Admin Users')): ?>
            <li class="sidebar-single"><a href="<?= base_url('admin_users') ?>"><i class="fa fa-user-circle-o"></i><span>Admin Users</span></a></li>
            <li class="sidebar-single"><a href="<?= base_url('audit_logs') ?>"><i class="fa fa-shield"></i><span>Audit Logs</span></a></li>
            <li class="sidebar-single"><a href="<?= base_url('school_backup') ?>"><i class="fa fa-cloud-download"></i><span>Backup</span></a></li>
            <?php endif; ?>

            <?php if ($can('Device Management')): ?>
            <li class="sidebar-single"><a href="<?= base_url('device_management') ?>"><i class="fa fa-mobile"></i><span>Device Management</span></a></li>
            <?php endif; ?>

            <?php if ($can('Admin Users')): ?>
            <li class="sidebar-single"><a href="<?= base_url('health_check') ?>"><i class="fa fa-heartbeat"></i><span>Health Check</span></a></li>
            <?php endif; ?>

            <!-- SA Panel link removed from school admin sidebar.
                 The SA panel is a separate platform-level tool accessed via /superadmin/login.
                 School admins (even with "Super Admin" role) should not see or access it from here. -->

        </ul>
    </section>

    <div class="g-sb-foot">
        <?php
            $ini = 'AD';
            if (!empty($admin_name)) {
                $parts = explode(' ', trim($admin_name));
                $ini = strtoupper(substr($parts[0],0,1).(isset($parts[1])?substr($parts[1],0,1):''));
            }
        ?>
        <div class="g-av"><?= htmlspecialchars($ini, ENT_QUOTES, 'UTF-8') ?></div>
        <div style="flex:1;min-width:0">
            <div class="g-av-name"><?= htmlspecialchars($admin_name ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="g-av-role"><?= htmlspecialchars($admin_role ?? 'Administrator', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <a href="<?= base_url('admin_login/logout') ?>" class="g-av-out" title="Logout"><i class="fa fa-sign-out"></i></a>
    </div>
</aside>

        <!-- ═══════════════════ THEME + BELL SCRIPT ═══════════════════ -->
<script>
(function () {
    'use strict';
    var html=document.documentElement, body=document.body;
    var btn=document.getElementById('themeToggle');
    var tIcon=document.getElementById('themeIcon'), tLbl=document.getElementById('themeLabel');

    /* THEME */
    function getAutoTheme(){var h=new Date().getHours();return(h>=6&&h<18)?'day':'night';}
    var saved=localStorage.getItem('graderiq_theme')||getAutoTheme();
    function applyTheme(t){
        html.setAttribute('data-theme',t); body.setAttribute('data-theme',t);
        var dbRoot=document.getElementById('dbRoot');
        if(dbRoot) dbRoot.setAttribute('data-theme',t==='night'?'dark':'light');
        if(tIcon){tIcon.className='fa '+(t==='day'?'fa-sun-o':'fa-moon-o');tIcon.style.fontSize='11px';}
        if(tLbl)  tLbl.textContent=(t==='day')?'Day':'Night';
        localStorage.setItem('graderiq_theme',t);
    }
    applyTheme(saved);
    /* Sync dbRoot after content-wrapper is parsed (fixes dashboard flash) */
    document.addEventListener('DOMContentLoaded',function(){
        var dbRoot=document.getElementById('dbRoot');
        if(dbRoot) dbRoot.setAttribute('data-theme',html.getAttribute('data-theme')==='day'?'light':'dark');
    });
    if(btn) btn.addEventListener('click',function(){applyTheme(html.getAttribute('data-theme')==='night'?'day':'night');});

    /* ACTIVE SIDEBAR LINK */
    var curPath=window.location.pathname.replace(/\/$/,'');
    var menu=document.querySelector('.sidebar-menu');
    if(menu){
        menu.querySelectorAll('li.active').forEach(function(e){e.classList.remove('active');});
        var best=null, bestLen=0;
        menu.querySelectorAll('a[href]').forEach(function(a){
            var base=BASE_URL.replace(/\/$/,'');
            var rel=(a.getAttribute('href')||'').replace(base,'').replace(/\/$/,'')||'/';
            if(curPath===rel||curPath.indexOf(rel+'/')===0){
                if(rel.length>bestLen){bestLen=rel.length;best=a;}
            }
        });
        if(best){
            var li=best.closest('li');
            if(li){
                li.classList.add('active');
                var pUl=li.closest('.treeview-menu');
                if(pUl){var pLi=pUl.closest('.treeview');if(pLi)pLi.classList.add('active','menu-open');}
            }
        }
    }

    /* BELL */
    var RK='gbell_<?= md5(($school_firebase_key ?? $school_id ?? '') . ($session_year ?? '')) ?>';
    var readIds=JSON.parse(localStorage.getItem(RK)||'[]'), bData=[];
    var $bellBtn=document.getElementById('gBellBtn');
    var $panel=document.getElementById('gBellPanel');
    var $list=document.getElementById('gBellList');
    var $badge=document.getElementById('gBadge');
    var $nbadge=document.getElementById('gNavBadge');

    if($bellBtn) $bellBtn.addEventListener('click',function(e){e.stopPropagation();$panel.classList.toggle('open');});
    document.addEventListener('click',function(e){
        var wrap=document.getElementById('gBellWrap');
        if($panel&&wrap&&!wrap.contains(e.target))$panel.classList.remove('open');
    });

    var _hasCommunication = <?= json_encode(has_permission('Communication')) ?>;
    function fetchBell(){
        if(!_hasCommunication){ if($list) $list.innerHTML='<div class="g-bell-empty">No notifications</div>'; return; }
        fetch('<?= base_url("NoticeAnnouncement/fetch_recent_notices") ?>',{cache:'no-store'})
            .then(function(r){return r.json();})
            .then(function(d){bData=Array.isArray(d)?d:[];renderBell();updateBadge();})
            .catch(function(){if($list)$list.innerHTML='<div class="g-bell-empty"><i class="fa fa-exclamation-circle"></i> Could not load</div>';});
    }

    function renderBell(){
        if(!$list) return;
        if(!bData.length){$list.innerHTML='<div class="g-bell-empty"><i class="fa fa-bell-slash-o"></i> No notices yet</div>';return;}
        var h='';
        bData.forEach(function(n){
            var isNew=readIds.indexOf(n.id)===-1;
            var ts=n.Time_Stamp||n.Timestamp||0;
            var ago=timeAgo(ts?new Date(ts):new Date());
            var desc=(n.Description||'').substring(0,60);
            h+='<a class="g-bell-item'+(isNew?' unread':'')+'" href="<?= base_url("communication/notices") ?>" data-id="'+esc(n.id)+'">'
              +'<span class="g-bld '+(isNew?'new':'old')+'"></span>'
              +'<div style="flex:1;min-width:0">'
              +'<div class="g-bell-nt">'+esc(n.Title||'Untitled')+'</div>'
              +'<div class="g-bell-nd">'+esc(desc)+'</div>'
              +'<div class="g-bell-nt2"><i class="fa fa-clock-o" style="margin-right:3px"></i>'+ago+'</div>'
              +'</div></a>';
        });
        $list.innerHTML=h;
        $list.querySelectorAll('a[data-id]').forEach(function(a){
            a.addEventListener('click',function(){
                var id=a.getAttribute('data-id');
                if(id&&readIds.indexOf(id)===-1){readIds.push(id);localStorage.setItem(RK,JSON.stringify(readIds));updateBadge();}
            });
        });
    }

    function updateBadge(){
        var u=bData.filter(function(n){return readIds.indexOf(n.id)===-1;}).length;
        if($badge){$badge.textContent=u>9?'9+':String(u);$badge.setAttribute('data-n',u);$badge.style.display=u?'flex':'none';}
        if($nbadge){$nbadge.textContent=u>9?'9+':String(u);$nbadge.style.display=u?'inline-block':'none';}
    }

    window.gMarkAllRead=function(){
        bData.forEach(function(n){if(readIds.indexOf(n.id)===-1)readIds.push(n.id);});
        localStorage.setItem(RK,JSON.stringify(readIds));updateBadge();renderBell();
        if($panel)$panel.classList.remove('open');
    };

    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
    function timeAgo(d){
        var diff=Math.floor((Date.now()-d.getTime())/1000);
        if(diff<60) return 'Just now';
        if(diff<3600) return Math.floor(diff/60)+'m ago';
        if(diff<86400) return Math.floor(diff/3600)+'h ago';
        return d.toLocaleDateString('en-IN',{day:'numeric',month:'short'});
    }

    /* ── Workflow tasks in bell panel ── */
    var tData=[];
    var $tList=document.getElementById('gTaskList');
    var $tBadge=document.getElementById('gTaskBadge');

    function fetchTasks(){
        fetch('<?= base_url("notifications/get_tasks") ?>',{cache:'no-store'})
            .then(function(r){return r.json();})
            .then(function(d){
                if(d.status!=='success')return;
                tData=(d.tasks||[]).concat((d.alerts||[]).map(function(a){
                    return{id:a.key,icon:a.icon,color:a.type==='error'?'#dc2626':'#d97706',title:a.title,detail:a.detail,action:a.action,priority:'high',module:a.module};
                }));
                renderTasks();
                updateTaskBadge();
            })
            .catch(function(){});
    }

    function renderTasks(){
        if(!$tList)return;
        if(!tData.length){$tList.innerHTML='<div class="g-bell-empty"><i class="fa fa-check-circle" style="color:var(--gold)"></i> All caught up!</div>';return;}
        var base='<?= base_url() ?>';
        var h='';
        tData.slice(0,8).forEach(function(t){
            var href=t.action?(base+t.action):'#';
            h+='<a class="g-task-item" href="'+esc(href)+'">'
              +'<div class="g-task-icon" style="background:'+(t.color||'#0f766e')+'"><i class="fa '+(t.icon||'fa-tasks')+'"></i></div>'
              +'<div style="flex:1;min-width:0">'
              +'<div class="g-task-title">'+esc(t.title)+'</div>'
              +'<div class="g-task-detail">'+esc(t.detail||'')+'</div>'
              +'</div>'
              +'<span class="g-task-pri '+(t.priority||'low')+'">'+(t.priority||'low').toUpperCase()+'</span>'
              +'</a>';
        });
        $tList.innerHTML=h;
    }

    function updateTaskBadge(){
        var c=tData.filter(function(t){return t.priority==='high';}).length;
        if($tBadge){$tBadge.textContent=String(c);$tBadge.style.display=c?'inline-block':'none';}
        // Also add task count to bell badge total
        updateBadge();
    }

    // Override updateBadge to merge notice + task counts
    var _origUpdateBadge = updateBadge;
    updateBadge = function(){
        var noticeUnread=bData.filter(function(n){return readIds.indexOf(n.id)===-1;}).length;
        var taskHigh=tData.filter(function(t){return t.priority==='high';}).length;
        var total=noticeUnread+taskHigh;
        if($badge){$badge.textContent=total>9?'9+':String(total);$badge.setAttribute('data-n',total);$badge.style.display=total?'flex':'none';}
        if($nbadge){$nbadge.textContent=total>9?'9+':String(total);$nbadge.style.display=total?'inline-block':'none';}
    };

    window.gSwitchBellTab=function(tab,btn){
        var tabs=document.querySelectorAll('.g-bell-tab');
        tabs.forEach(function(t){t.classList.remove('active');});
        if(btn)btn.classList.add('active');
        var showNotices=(tab==='notices');
        if($list)$list.style.display=showNotices?'':'none';
        if($tList)$tList.style.display=showNotices?'none':'';
        var ftN=document.getElementById('gBellFtNotices');
        var ftT=document.getElementById('gBellFtTasks');
        if(ftN)ftN.style.display=showNotices?'':'none';
        if(ftT)ftT.style.display=showNotices?'none':'';
    };

    fetchBell();
    fetchTasks();
    setInterval(fetchBell,90000);
    setInterval(fetchTasks,120000);
})();
</script>

<script>
/* ── Session Switcher ────────────────────────────────────────────────────── */
(function () {
    var btn   = document.getElementById('gSessBtn');
    var panel = document.getElementById('gSessPanel');
    if (!btn || !panel) return;

    // Toggle dropdown
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('open');
    });
    document.addEventListener('click', function () { panel.classList.remove('open'); });

    // Switch session on item click
    document.querySelectorAll('.g-sess-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var year = this.dataset.year;
            if (this.classList.contains('g-sess-item--active')) {
                panel.classList.remove('open');
                return;
            }
            $.post(BASE_URL + 'admin/switch_session', { session_year: year })
             .done(function (res) {
                 if (res && res.status === 'success') { window.location.reload(); }
             })
             .fail(function () { alert('Failed to switch session. Please try again.'); });
        });
    });

    // New session button → open modal with auto-suggested next year
    var newBtn = document.getElementById('gSessNewBtn');
    if (newBtn) {
        newBtn.addEventListener('click', function () {
            panel.classList.remove('open');
            var items = document.querySelectorAll('.g-sess-item');
            var latest = '';
            items.forEach(function (i) { if (i.dataset.year > latest) latest = i.dataset.year; });
            var suggestion = '';
            if (latest) {
                var base = parseInt(latest.split('-')[0]) + 1;
                suggestion = base + '-' + String(base + 1).slice(-2);
            }
            document.getElementById('gNewSessInput').value = suggestion;
            document.getElementById('gNewSessHint').textContent = suggestion ? 'Suggested: ' + suggestion : '';
            document.getElementById('gCreateSessError').style.display = 'none';
            $('#gCreateSessModal').modal('show');
        });
    }

    // Create session submit
    var createBtn = document.getElementById('gCreateSessSubmit');
    if (createBtn) {
        createBtn.addEventListener('click', function () {
            var year   = document.getElementById('gNewSessInput').value.trim();
            var errBox = document.getElementById('gCreateSessError');
            errBox.style.display = 'none';

            if (!/^\d{4}-\d{2}$/.test(year)) {
                errBox.textContent = 'Format must be YYYY-YY (e.g. 2026-27)';
                errBox.style.display = 'block';
                return;
            }
            createBtn.disabled = true;
            createBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating\u2026';

            $.post(BASE_URL + 'admin/create_session', { session_year: year })
             .done(function (res) {
                 if (res && res.status === 'success') {
                     $('#gCreateSessModal').modal('hide');
                     // Switch to the newly created session then reload
                     $.post(BASE_URL + 'admin/switch_session', { session_year: year })
                      .always(function () { window.location.reload(); });
                 } else {
                     errBox.textContent = (res && res.message) || 'Failed to create session.';
                     errBox.style.display = 'block';
                     createBtn.disabled = false;
                     createBtn.innerHTML = '<i class="fa fa-plus"></i> Create &amp; Switch';
                 }
             })
             .fail(function (xhr) {
                 var msg = 'Server error. Please try again.';
                 try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
                 errBox.textContent = msg;
                 errBox.style.display = 'block';
                 createBtn.disabled = false;
                 createBtn.innerHTML = '<i class="fa fa-plus"></i> Create &amp; Switch';
             });
        });
    }
})();
</script>