<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html data-theme="night">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?= htmlspecialchars($page_title ?? 'Super Admin') ?> — GraderIQ SA</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="csrf-token" content="<?= htmlspecialchars($sa_csrf_token ?? '', ENT_QUOTES) ?>">
<meta name="csrf-name"  content="csrf_token">

<link rel="stylesheet" href="<?= base_url() ?>tools/bower_components/bootstrap/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= base_url() ?>tools/bower_components/font-awesome/css/font-awesome.min.css">
<link rel="stylesheet" href="<?= base_url() ?>tools/dist/css/AdminLTE.min.css">
<link rel="stylesheet" href="<?= base_url() ?>tools/dist/css/skins/_all-skins.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.0.2/css/buttons.dataTables.css">
<!-- [FIX-9] Removed duplicate Font Awesome 6.x CDN — FA 4.x above covers all fa-* icons used -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<!-- jQuery loaded early so inline page scripts can use $ -->
<script src="<?= base_url() ?>tools/bower_components/jquery/dist/jquery.min.js"></script>

<style>
/* ═══════════════════════════════════════════════════════════════════════
   GRADERIQ SUPER ADMIN — Professional ERP Theme
   Font: Inter (enterprise standard) + JetBrains Mono
   Accent: Violet/Purple — distinct from school admin teal panel
   ═══════════════════════════════════════════════════════════════════════ */
:root{
    /* Brand accent */
    --sa:#6d28d9;--sa2:#5b21b6;--sa3:#7c3aed;--sa4:#a78bfa;
    --sa-dim:rgba(109,40,217,.08);--sa-ring:rgba(109,40,217,.18);--sa-glow:rgba(109,40,217,.12);
    /* Semantic colors */
    --green:#16a34a;--green-dim:rgba(22,163,74,.1);--green-ring:rgba(22,163,74,.22);
    --blue:#0284c7;--blue-dim:rgba(2,132,199,.1);--blue-ring:rgba(2,132,199,.22);
    --rose:#dc2626;--rose-dim:rgba(220,38,38,.1);--rose-ring:rgba(220,38,38,.22);
    --amber:#d97706;--amber-dim:rgba(217,119,6,.1);--amber-ring:rgba(217,119,6,.22);
    /* Layout */
    --sw:256px;--hh:60px;--r:10px;--r-sm:7px;
    --ease:.18s cubic-bezier(.4,0,.2,1);
    /* Typography — Inter only */
    --font-b:'Inter',system-ui,-apple-system,sans-serif;
    --font-m:'JetBrains Mono',ui-monospace,monospace;
    --font-d:'Inter',system-ui,-apple-system,sans-serif;
}

/* ── NIGHT THEME (default) ── */
:root,[data-theme="night"],body[data-theme="night"]{
    --bg:#0d1117;--bg2:#161b22;--bg3:#1c2128;--bg4:#21262d;
    --border:rgba(109,40,217,.14);--brd2:rgba(109,40,217,.24);
    --t1:#e6edf3;--t2:#8d96a0;--t3:#656d76;--t4:#30363d;
    --sh:0 2px 12px rgba(0,0,0,.4),0 1px 3px rgba(0,0,0,.3);
    --sh-lg:0 8px 32px rgba(0,0,0,.5);
}
/* ── DAY THEME ── */
[data-theme="day"],body[data-theme="day"]{
    --bg:#f4f5f7;--bg2:#ffffff;--bg3:#f0f1f5;--bg4:#e8eaf0;
    --border:rgba(109,40,217,.10);--brd2:rgba(109,40,217,.18);
    --t1:#1a1d23;--t2:#4b5563;--t3:#6b7280;--t4:#9ca3af;
    --sh:0 1px 3px rgba(0,0,0,.06),0 2px 8px rgba(0,0,0,.04);
    --sh-lg:0 4px 20px rgba(0,0,0,.08),0 1px 4px rgba(0,0,0,.04);
}
/* Day sidebar — keep dark for contrast/legibility */
[data-theme="day"] .main-sidebar{
    background:#1e1b4b !important;
    border-right:none !important;
    box-shadow:2px 0 12px rgba(30,27,75,.18) !important;
    --t1:#f5f3ff;--t2:#ddd6fe;--t3:#a78bfa;--t4:rgba(167,139,250,.35);
    --border:rgba(167,139,250,.12);--brd2:rgba(167,139,250,.22);
    --bg2:#1e1b4b;--bg3:rgba(255,255,255,.06);--bg4:rgba(255,255,255,.10);
    --sa:#a78bfa;--sa2:#c4b5fd;--sa3:#c4b5fd;
    --sa-dim:rgba(167,139,250,.12);--sa-ring:rgba(167,139,250,.22);
}
[data-theme="day"] .g-sb-foot{background:#1e1b4b !important;border-top-color:rgba(167,139,250,.15) !important;}
[data-theme="day"] .main-sidebar::after{background:linear-gradient(90deg,rgba(167,139,250,.4),transparent);}
[data-theme="night"] .main-sidebar{background:#161b22 !important;}

/* Smooth transitions on theme switch */
html,body,.main-header,.main-sidebar,.content-wrapper,.main-footer,
.box,.box-header,.box-body,.box-footer,.modal-content,.form-control,.btn,.label,.badge{
    transition:background var(--ease),background-color var(--ease),
               border-color var(--ease),color var(--ease),box-shadow var(--ease) !important;
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:var(--font-b) !important;background:var(--bg) !important;color:var(--t1) !important;font-size:14px !important;line-height:1.5 !important;}
body.hold-transition{visibility:visible !important;}
a{text-decoration:none !important;}

/* ── SA IDENTITY STRIPE ── */
.sa-context-banner{
    position:fixed;top:0;left:0;right:0;height:3px;
    background:linear-gradient(90deg,var(--sa2) 0%,var(--sa4) 50%,var(--sa2) 100%);
    z-index:9999;
}

/* ── TOPBAR ── */
.main-header{
    position:fixed !important;top:3px;left:0;right:0;
    height:var(--hh) !important;
    background:var(--bg2) !important;
    border-bottom:1px solid var(--border) !important;
    box-shadow:var(--sh) !important;
    z-index:1040 !important;
    display:flex !important;align-items:center !important;
}
.main-header .logo{
    width:var(--sw) !important;height:var(--hh) !important;
    background:var(--bg2) !important;
    border-right:1px solid var(--border) !important;border-bottom:none !important;
    display:flex !important;align-items:center !important;
    padding:0 14px !important;flex-shrink:0;gap:0 !important;
}
.g-logo-link{display:flex !important;align-items:center !important;gap:9px !important;flex:1 !important;min-width:0;text-decoration:none !important;}
.g-mark{
    width:30px;height:30px;border-radius:7px;
    background:var(--sa);
    display:flex;align-items:center;justify-content:center;
    font-family:var(--font-b);font-size:15px;font-weight:800;color:#fff;
    flex-shrink:0;letter-spacing:-.5px;
}
.g-logotext{line-height:1.15;overflow:hidden;}
.g-logoname{font-family:var(--font-b);font-size:14.5px;font-weight:700;color:var(--t1);letter-spacing:-.2px;}
.g-logoname b{color:var(--sa3);font-weight:800;}
.g-logosub{font-family:var(--font-m);font-size:9px;color:var(--t3);margin-top:1px;white-space:nowrap;letter-spacing:.3px;}
.g-sa-pill{
    background:var(--sa-dim);border:1px solid var(--sa-ring);
    border-radius:4px;padding:2px 6px;
    font-family:var(--font-m);font-size:8px;font-weight:700;
    color:var(--sa4);letter-spacing:.8px;flex-shrink:0;
}
.sidebar-toggle{
    width:28px !important;height:28px !important;border-radius:6px !important;
    border:1px solid var(--border) !important;
    color:var(--t3) !important;font-size:12px !important;
    margin:0 !important;margin-right:6px !important;
    display:flex !important;align-items:center !important;justify-content:center !important;
    background:transparent !important;
    transition:all var(--ease) !important;flex-shrink:0 !important;
}
.sidebar-toggle:hover{background:var(--sa-dim) !important;color:var(--sa4) !important;border-color:var(--sa-ring) !important;}
.main-header .navbar{
    background:transparent !important;border:none !important;
    min-height:var(--hh) !important;margin-left:var(--sw) !important;
    padding:0 20px !important;
    display:flex !important;align-items:center !important;flex:1 !important;
    transition:margin-left var(--ease) !important;
}
/* Search bar */
.g-search{
    flex:1;max-width:340px;margin-right:auto !important;
    display:flex;align-items:center;
    background:var(--bg3) !important;border:1px solid var(--border) !important;
    border-radius:8px !important;padding:0 12px !important;height:34px;
    transition:all var(--ease);
}
.g-search:focus-within{border-color:var(--sa-ring) !important;box-shadow:0 0 0 3px var(--sa-dim) !important;}
.g-search i{color:var(--t3);font-size:12px;margin-right:8px;flex-shrink:0;}
.g-search input{
    background:none !important;border:none !important;outline:none !important;
    color:var(--t1) !important;font-family:var(--font-b) !important;
    font-size:13px !important;flex:1;
}
.g-search input::placeholder{color:var(--t3) !important;opacity:.6;}
.g-actions{display:flex;align-items:center;gap:8px;margin-left:auto;}
/* Theme toggle */
.g-theme-pill{
    display:flex;align-items:center;gap:6px;
    padding:5px 10px 5px 8px;
    background:var(--bg3);border:1px solid var(--border);border-radius:7px;
    color:var(--t2);font-size:12px;font-weight:500;
    cursor:pointer;transition:all var(--ease);white-space:nowrap;user-select:none;
    font-family:var(--font-b);
}
.g-theme-pill:hover{border-color:var(--sa-ring);color:var(--t1);}
.g-track{width:32px;height:17px;border-radius:20px;background:var(--bg4);border:1px solid var(--border);position:relative;flex-shrink:0;}
.g-knob{position:absolute;top:2px;left:2px;width:11px;height:11px;border-radius:50%;background:var(--sa);box-shadow:0 1px 4px rgba(0,0,0,.2);transition:transform .28s cubic-bezier(.34,1.4,.64,1);}
[data-theme="day"] .g-knob{transform:translateX(15px);}
[data-theme="day"] .g-track{background:var(--sa-dim);border-color:var(--sa-ring);}
/* User dropdown */
.user-menu>a{display:flex !important;align-items:center !important;gap:8px !important;padding:0 12px !important;height:var(--hh) !important;color:var(--t1) !important;}
.user-menu>a .user-image{width:28px !important;height:28px !important;border-radius:6px !important;border:1.5px solid var(--brd2) !important;margin:0 !important;}
.user-menu>a span{font-size:13px !important;font-weight:500 !important;color:var(--t1) !important;}
.navbar-nav .dropdown-menu{background:var(--bg2) !important;border:1px solid var(--brd2) !important;border-radius:10px !important;box-shadow:var(--sh-lg) !important;padding:6px !important;min-width:175px;}
.navbar-nav .dropdown-menu>li>a{color:var(--t2) !important;border-radius:6px !important;padding:7px 11px !important;font-size:13px !important;display:block !important;transition:all .12s !important;}
.navbar-nav .dropdown-menu>li>a:hover{background:var(--bg3) !important;color:var(--t1) !important;}
.user-header{background:var(--bg3) !important;padding:12px 14px !important;border-radius:6px 6px 0 0 !important;}
.user-header p{color:var(--t1) !important;font-size:13px !important;font-weight:600 !important;margin:0 !important;}
.user-header p small{color:var(--t3) !important;display:block;margin-top:2px;font-weight:400;}
.user-footer{background:var(--bg3) !important;padding:8px 10px !important;border-top:1px solid var(--border) !important;border-radius:0 0 8px 8px !important;display:flex !important;justify-content:space-between !important;}
.user-footer .btn{font-size:12px !important;padding:5px 10px !important;}
.navbar-nav .open>a{background:transparent !important;}

/* ── SIDEBAR ── */
.main-sidebar{
    position:fixed !important;top:calc(var(--hh) + 3px) !important;
    left:0 !important;bottom:0 !important;width:var(--sw) !important;
    background:var(--bg2) !important;
    border-right:1px solid var(--border) !important;
    z-index:1038 !important;
    display:flex !important;flex-direction:column !important;
    overflow:hidden !important;transition:width var(--ease) !important;
}
.main-sidebar::after{
    content:'';position:absolute;top:0;left:0;right:0;height:1px;
    background:linear-gradient(90deg,var(--sa-ring),transparent);
}
.sidebar{flex:1 !important;overflow-y:auto !important;padding:10px 0 4px !important;position:relative !important;z-index:1 !important;}
.sidebar::-webkit-scrollbar{width:2px;}
.sidebar::-webkit-scrollbar-thumb{background:var(--sa-ring);border-radius:2px;}
.user-panel{display:none !important;}
.sidebar-menu{list-style:none !important;margin:0 !important;padding:0 !important;}
.sidebar-menu .g-sec{
    padding:16px 16px 5px !important;font-size:9px !important;font-weight:700 !important;
    color:var(--t4) !important;text-transform:uppercase !important;
    letter-spacing:1.2px !important;font-family:var(--font-m) !important;pointer-events:none !important;
}
.sidebar-menu .g-sec:first-child{padding-top:8px !important;}
.sidebar-menu>li{margin:1px 8px !important;position:relative !important;}
.sidebar-menu>li>a{
    font-family:var(--font-b) !important;font-size:13px !important;font-weight:500 !important;
    color:var(--t2) !important;padding:8px 12px !important;border-radius:7px !important;
    display:flex !important;align-items:center !important;gap:10px !important;
    transition:all var(--ease) !important;background:transparent !important;
}
.sidebar-menu>li>a:hover{color:var(--t1) !important;background:var(--bg3) !important;}
.sidebar-menu>li.active>a,.sidebar-menu>li.active>a:hover{
    color:var(--sa4) !important;background:var(--sa-dim) !important;font-weight:600 !important;
}
.sidebar-menu>li.active>a::before{
    content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);
    width:2.5px;height:20px;border-radius:0 2px 2px 0;background:var(--sa3);
}
.sidebar-menu>li>a>.fa,.sidebar-menu>li>a>i{
    width:16px !important;font-size:13.5px !important;text-align:center !important;
    flex-shrink:0 !important;color:var(--t3) !important;transition:color var(--ease) !important;
}
.sidebar-menu>li>a:hover>i{color:var(--t2) !important;}
.sidebar-menu>li.active>a>i{color:var(--sa4) !important;}
.sidebar-menu>li>a>span:not(.pull-right-container){flex:1;}
.g-nb{background:var(--rose);color:#fff;font-size:9px;font-weight:700;font-family:var(--font-m);padding:1px 5px;border-radius:4px;flex-shrink:0;}
.sidebar-menu .fa-angle-left{font-size:10px !important;color:var(--t4) !important;transition:transform .2s !important;}
.sidebar-menu li.menu-open>a>.pull-right-container .fa-angle-left{transform:rotate(-90deg) !important;}
.treeview-menu{
    background:transparent !important;padding:3px 0 4px 14px !important;
    margin:0 !important;list-style:none !important;
    border-left:1.5px solid var(--border) !important;margin-left:20px !important;
}
.treeview-menu>li>a{
    font-family:var(--font-b) !important;font-size:12.5px !important;
    color:var(--t3) !important;padding:5px 10px !important;border-radius:6px !important;
    display:flex !important;align-items:center !important;gap:7px !important;transition:all .12s !important;
}
.treeview-menu>li>a:hover{color:var(--t1) !important;background:var(--bg3) !important;}
.treeview-menu>li.active>a{color:var(--sa4) !important;font-weight:600 !important;}
.treeview-menu .fa-circle-o{font-size:5px !important;opacity:.4 !important;}
/* Sidebar footer */
.g-sb-foot{
    position:relative;z-index:1;
    border-top:1px solid var(--border);padding:12px 12px;
    display:flex;align-items:center;gap:10px;background:var(--bg2);flex-shrink:0;
}
.g-av{
    width:32px;height:32px;border-radius:7px;
    background:var(--sa);
    display:flex;align-items:center;justify-content:center;
    font-family:var(--font-b);font-size:13px;font-weight:700;color:#fff;flex-shrink:0;
}
.g-av-name{font-size:12.5px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:116px;}
.g-av-role{font-size:10px;color:var(--t3);font-family:var(--font-m);margin-top:1px;}
.g-av-out{
    margin-left:auto;width:28px;height:28px;border-radius:6px;
    background:transparent;border:1px solid var(--border);
    display:flex;align-items:center;justify-content:center;
    color:var(--t3);font-size:12px;cursor:pointer;transition:all var(--ease);flex-shrink:0;
}
.g-av-out:hover{background:var(--rose-dim);color:var(--rose);border-color:var(--rose-ring);}

/* Collapsed sidebar */
.sidebar-collapse .main-sidebar{width:56px !important;}
.sidebar-collapse .content-wrapper,.sidebar-collapse .main-footer{margin-left:56px !important;}
.sidebar-collapse .main-header .navbar{margin-left:56px !important;}
.sidebar-collapse .main-header .logo{width:56px !important;padding:0 !important;justify-content:center !important;}
.sidebar-collapse .main-header .logo .g-logo-link{display:none !important;}
.sidebar-collapse .g-logosub,.sidebar-collapse .g-sec,
.sidebar-collapse .sidebar-menu>li>a>span:not(.pull-right-container),
.sidebar-collapse .sidebar-menu>li>a>.pull-right-container,
.sidebar-collapse .treeview-menu,
.sidebar-collapse .g-sb-foot .g-av-name,.sidebar-collapse .g-sb-foot .g-av-role,.sidebar-collapse .g-sb-foot .g-av-out{display:none !important;}
.sidebar-collapse .sidebar-menu>li>a{justify-content:center !important;padding:10px !important;}
.sidebar-collapse .g-sb-foot{justify-content:center !important;}

/* ── LAYOUT ── */
.content-wrapper{
    background:var(--bg) !important;
    margin-left:var(--sw) !important;
    margin-top:calc(var(--hh) + 3px) !important;
    min-height:calc(100vh - var(--hh) - 3px) !important;
    color:var(--t1) !important;font-family:var(--font-b) !important;
    transition:margin-left var(--ease),background var(--ease) !important;
}
.main-footer{
    background:var(--bg2) !important;border-top:1px solid var(--border) !important;
    color:var(--t3) !important;font-size:12px !important;
    margin-left:var(--sw) !important;padding:10px 24px !important;
    transition:background var(--ease),margin-left var(--ease) !important;
    font-family:var(--font-b) !important;
}

/* ── CARD / BOX ── */
.box{background:var(--bg2) !important;border:1px solid var(--border) !important;border-radius:var(--r) !important;box-shadow:var(--sh) !important;color:var(--t1) !important;margin-bottom:20px;}
.box-header{background:transparent !important;border-bottom:1px solid var(--border) !important;padding:13px 18px !important;}
.box-title{font-family:var(--font-b) !important;font-size:13.5px !important;font-weight:600 !important;color:var(--t1) !important;letter-spacing:-.1px;}
.box-body{padding:18px !important;}
.box-footer{background:var(--bg3) !important;border-top:1px solid var(--border) !important;border-radius:0 0 var(--r) var(--r) !important;padding:10px 18px !important;}
/* Box accent borders */
.box-primary{border-top:2px solid var(--sa) !important;}
.box-success{border-top:2px solid var(--green) !important;}
.box-danger{border-top:2px solid var(--rose) !important;}
.box-warning{border-top:2px solid var(--amber) !important;}
.box-info{border-top:2px solid var(--blue) !important;}

/* ── FORMS ── */
.form-control{
    background:var(--bg3) !important;border:1px solid var(--border) !important;
    color:var(--t1) !important;border-radius:var(--r-sm) !important;
    height:36px !important;font-size:13px !important;font-family:var(--font-b) !important;
    transition:border-color var(--ease),box-shadow var(--ease) !important;
}
.form-control:focus{
    border-color:var(--sa3) !important;
    box-shadow:0 0 0 2px var(--sa-dim) !important;
    background:var(--bg2) !important;color:var(--t1) !important;outline:none !important;
}
.form-control::placeholder{color:var(--t3) !important;opacity:.65;}
[data-theme="day"] .form-control::placeholder{color:var(--t3) !important;opacity:.8;}
textarea.form-control{height:auto !important;}
select.form-control option{background:var(--bg2);color:var(--t1);}
[data-theme="day"] select.form-control option{background:#fff;color:#1a1d23;}
label,.control-label{
    font-size:11px !important;font-weight:600 !important;color:var(--t2) !important;
    text-transform:uppercase !important;letter-spacing:.5px !important;
    font-family:var(--font-b) !important;
}

/* ── BUTTONS ── */
.btn{
    border-radius:var(--r-sm) !important;font-size:13px !important;
    font-weight:500 !important;font-family:var(--font-b) !important;
    padding:6px 14px !important;letter-spacing:-.1px;
    transition:all var(--ease) !important;
}
.btn-primary{background:var(--sa) !important;color:#fff !important;border:none !important;box-shadow:none !important;}
.btn-primary:hover,.btn-primary:focus{background:var(--sa2) !important;box-shadow:0 3px 12px var(--sa-glow) !important;}
.btn-success{background:var(--green-dim) !important;color:var(--green) !important;border:1px solid var(--green-ring) !important;}
.btn-success:hover{background:rgba(22,163,74,.16) !important;}
.btn-danger{background:var(--rose-dim) !important;color:var(--rose) !important;border:1px solid var(--rose-ring) !important;}
.btn-danger:hover{background:rgba(220,38,38,.16) !important;}
.btn-default{background:var(--bg3) !important;color:var(--t2) !important;border:1px solid var(--border) !important;}
.btn-default:hover{color:var(--t1) !important;border-color:var(--brd2) !important;background:var(--bg4) !important;}
.btn-info{background:var(--blue-dim) !important;color:var(--blue) !important;border:1px solid var(--blue-ring) !important;}
.btn-info:hover{background:rgba(2,132,199,.16) !important;}
.btn-warning{background:var(--amber-dim) !important;color:var(--amber) !important;border:1px solid var(--amber-ring) !important;}
.btn-warning:hover{background:rgba(217,119,6,.16) !important;}

/* ── TABLES ── */
.table{color:var(--t1) !important;font-size:13px !important;font-family:var(--font-b) !important;}
.table>thead>tr>th{
    background:var(--bg3) !important;color:var(--t3) !important;
    font-size:10.5px !important;font-weight:700 !important;
    text-transform:uppercase !important;letter-spacing:.7px !important;
    border-bottom:1px solid var(--border) !important;white-space:nowrap;
}
.table>tbody>tr>td{border-color:var(--border) !important;vertical-align:middle !important;color:var(--t1) !important;}
.table>tbody>tr:hover>td{background:var(--bg3) !important;}
.table-striped>tbody>tr:nth-of-type(odd)>td{background:rgba(109,40,217,.03) !important;}
[data-theme="day"] .table>tbody>tr:hover>td{background:#f0f1f5 !important;}

/* ── MODALS ── */
.modal-content{background:var(--bg2) !important;border:1px solid var(--brd2) !important;border-radius:12px !important;color:var(--t1) !important;box-shadow:var(--sh-lg) !important;}
.modal-header{border-bottom:1px solid var(--border) !important;padding:16px 20px !important;}
.modal-title{font-family:var(--font-b) !important;font-size:15px !important;font-weight:700 !important;color:var(--t1) !important;letter-spacing:-.2px;}
.modal-body{padding:20px !important;}
.modal-footer{border-top:1px solid var(--border) !important;padding:12px 20px !important;}
.modal-backdrop{background:rgba(0,0,0,.6) !important;}
.close{color:var(--t2) !important;opacity:1 !important;text-shadow:none !important;font-size:18px !important;}
.close:hover{color:var(--t1) !important;opacity:1 !important;}
[data-theme="day"] .modal-backdrop{background:rgba(26,29,35,.5) !important;}

/* ── BADGES & LABELS ── */
.label,.badge{
    border-radius:4px !important;font-size:10px !important;
    font-family:var(--font-m) !important;padding:2px 7px !important;
    font-weight:600 !important;letter-spacing:.2px;
}
.label-primary,.badge-primary{background:rgba(109,40,217,.15) !important;color:#a78bfa !important;border:1px solid rgba(109,40,217,.25) !important;}
[data-theme="day"] .label-primary,[data-theme="day"] .badge-primary{background:rgba(109,40,217,.1) !important;color:var(--sa) !important;}
.label-success,.badge-success{background:var(--green-dim) !important;color:var(--green) !important;border:1px solid var(--green-ring) !important;}
.label-danger,.badge-danger{background:var(--rose-dim) !important;color:var(--rose) !important;border:1px solid var(--rose-ring) !important;}
.label-warning,.badge-warning{background:var(--amber-dim) !important;color:var(--amber) !important;border:1px solid var(--amber-ring) !important;}
.label-info,.badge-info{background:var(--blue-dim) !important;color:var(--blue) !important;border:1px solid var(--blue-ring) !important;}
.label-default,.badge-default{background:var(--bg4) !important;color:var(--t2) !important;border:1px solid var(--border) !important;}

/* ── ALERTS ── */
.alert{border-radius:8px !important;border:none !important;font-size:13px !important;font-family:var(--font-b) !important;}
.alert-success{background:var(--green-dim) !important;color:var(--green) !important;border-left:3px solid var(--green) !important;}
.alert-danger{background:var(--rose-dim) !important;color:var(--rose) !important;border-left:3px solid var(--rose) !important;}
.alert-warning{background:var(--amber-dim) !important;color:var(--amber) !important;border-left:3px solid var(--amber) !important;}
.alert-info{background:var(--blue-dim) !important;color:var(--blue) !important;border-left:3px solid var(--blue) !important;}

/* ── DATATABLES ── */
.dataTables_wrapper .dataTables_length select,
.dataTables_wrapper .dataTables_filter input{
    background:var(--bg3) !important;border:1px solid var(--border) !important;
    color:var(--t1) !important;border-radius:6px !important;padding:4px 10px !important;
    font-family:var(--font-b) !important;font-size:12.5px !important;
}
.dataTables_wrapper .dataTables_length select:focus,
.dataTables_wrapper .dataTables_filter input:focus{border-color:var(--sa3) !important;outline:none !important;}
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter{color:var(--t2) !important;font-size:12px !important;font-family:var(--font-b) !important;}
.dataTables_wrapper .dataTables_paginate .paginate_button{
    border-radius:6px !important;color:var(--t2) !important;
    font-size:12px !important;border:none !important;font-family:var(--font-b) !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current,
.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover{
    background:var(--sa) !important;color:#fff !important;border:none !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button:hover{
    background:var(--bg3) !important;color:var(--t1) !important;border:none !important;
}
.dt-buttons .dt-button{
    background:var(--bg3) !important;border:1px solid var(--border) !important;
    color:var(--t2) !important;border-radius:6px !important;
    font-size:12px !important;font-family:var(--font-b) !important;padding:4px 10px !important;
}
.dt-buttons .dt-button:hover{background:var(--bg4) !important;color:var(--t1) !important;}

/* ── PAGE HEADER ── */
.content-header{padding:22px 24px 0 !important;display:block !important;}
.content-header h1{
    font-family:var(--font-b) !important;font-size:20px !important;
    font-weight:700 !important;color:var(--t1) !important;
    letter-spacing:-.3px;line-height:1.3;float:none !important;
}
.content-header .breadcrumb{
    background:transparent !important;padding:4px 0 0 !important;margin:0 !important;
    float:none !important;display:block !important;
}
.content-header .breadcrumb>li>a{color:var(--sa3) !important;font-size:12.5px !important;}
.content-header .breadcrumb>li.active{color:var(--t3) !important;font-size:12.5px !important;}
.content-header .breadcrumb>li+li::before{color:var(--t4) !important;}

/* ── SA STAT CARDS ── */
.sa-stat{
    background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);
    padding:18px 20px;display:flex;align-items:center;gap:16px;
    transition:all var(--ease);margin-bottom:0;
}
.sa-stat:hover{border-color:var(--brd2);box-shadow:var(--sh) !important;}
.sa-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.sa-stat-icon.purple{background:rgba(109,40,217,.1);color:#a78bfa;}
.sa-stat-icon.green{background:var(--green-dim);color:var(--green);}
.sa-stat-icon.blue{background:var(--blue-dim);color:var(--blue);}
.sa-stat-icon.amber{background:var(--amber-dim);color:var(--amber);}
.sa-stat-val{font-family:var(--font-b);font-size:24px;font-weight:700;color:var(--t1);line-height:1;letter-spacing:-.5px;}
.sa-stat-label{font-size:12px;color:var(--t3);margin-top:4px;font-family:var(--font-b);font-weight:400;}

/* ── FILTER BUTTONS ── */
.sa-filter-btn.active{background:rgba(109,40,217,.12) !important;color:#a78bfa !important;border-color:rgba(109,40,217,.25) !important;font-weight:600 !important;}
[data-theme="day"] .sa-filter-btn.active{color:var(--sa) !important;}

/* ── SCROLLBAR ── */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:var(--brd2);}

/* ── FORM GROUP SPACING ── */
.form-group{margin-bottom:16px !important;}
.content-header+.content{padding:18px 24px !important;}
.content{padding:18px 24px !important;}

/* ═══════════════════════════════════════════════════════════════════════
   RESPONSIVE — ISSUE 9: Mobile & tablet breakpoints
   ═══════════════════════════════════════════════════════════════════════ */

/* Tablet (768px — 1024px): narrow sidebar, reduce paddings */
@media (max-width:1024px){
    :root{--sw:220px;}
    .content,.content-header+.content{padding:14px 16px !important;}
    .box-body{padding:14px !important;}
    .g-search{display:none !important;}
    .sa-stat-val{font-size:20px !important;}
    .g-sb-foot .g-sb-foot-name{display:none !important;}
}

/* Mobile (<768px): collapse sidebar, full-width content */
@media (max-width:767px){
    :root{--sw:0px;--hh:56px;}

    /* Hide sidebar by default on mobile — AdminLTE handles toggle */
    .main-sidebar{transform:translateX(-256px) !important;transition:transform var(--ease) !important;}
    .sidebar-open .main-sidebar{transform:translateX(0) !important;}

    /* Content fills full width */
    .content-wrapper{margin-left:0 !important;}
    .main-footer{margin-left:0 !important;}

    /* Topbar logo compacts */
    .main-header .logo{width:56px !important;padding:0 10px !important;}
    .g-logotext,.g-sa-pill{display:none !important;}

    /* Stack AdminLTE grid columns */
    .row>[class*="col-md-"]{width:100% !important;float:none !important;}
    .row>[class*="col-sm-"]{width:100% !important;float:none !important;}

    /* Content paddings */
    .content,.content-header+.content{padding:10px 12px !important;}
    .box-body{padding:12px !important;}

    /* Tables: horizontal scroll */
    .table-responsive{overflow-x:auto !important;-webkit-overflow-scrolling:touch;}
    table.dataTable{min-width:600px;}

    /* KPI card grid: 2 columns */
    .row>[class*="col-xs-6"]{width:50% !important;}
    .row>[class*="col-xs-12"]{width:100% !important;}

    /* Buttons: full width in forms */
    .box-footer .btn,.box-body>[style*="text-align:right"] .btn{width:100% !important;margin-bottom:6px !important;}

    /* Hide helper text that clutters small screens */
    .content-header .breadcrumb{display:none !important;}
    .sa-stat-label{font-size:11px !important;}
    .sa-stat-val{font-size:18px !important;}

    /* Modal full-width */
    .modal-dialog{margin:8px !important;width:calc(100vw - 16px) !important;}
    .modal-content{border-radius:8px !important;}

    /* Sidebar open overlay */
    .sidebar-open::before{
        content:'';position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1039;
    }
}

/* Very small screens (<480px) */
@media (max-width:479px){
    .content,.content-header+.content{padding:8px !important;}
    .box{border-radius:8px !important;}
    .form-control{height:38px !important;font-size:14px !important;}
    .btn{padding:7px 12px !important;font-size:13px !important;}
}
</style>

<script>
var BASE_URL = '<?= base_url() ?>';
/* Apply theme immediately to prevent flash */
(function(){
    var t = localStorage.getItem('sa_theme') || 'night';
    document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>

<body class="hold-transition skin-purple sidebar-mini" data-theme="night">

<!-- SA Context Banner -->
<div class="sa-context-banner"></div>

<div class="wrapper">

<!-- HEADER -->
<header class="main-header">
    <div class="logo">
        <a class="g-logo-link" href="<?= base_url('superadmin/dashboard') ?>">
            <div class="g-mark">G</div>
            <div class="g-logotext">
                <div class="g-logoname">Grader<b>IQ</b></div>
                <div class="g-logosub">Super Admin</div>
            </div>
        </a>
        <div class="g-sa-pill">SA</div>
        <a class="sidebar-toggle" data-toggle="push-menu" role="button"><i class="fa fa-bars"></i></a>
    </div>
    <nav class="navbar navbar-static-top">
        <div class="g-search">
            <i class="fa fa-search"></i>
            <input type="text" placeholder="Search schools, plans, logs...">
        </div>
        <div class="g-actions">
            <!-- Theme Toggle -->
            <div class="g-theme-pill" id="saThemeToggle">
                <i class="fa fa-moon-o" id="saThemeIcon"></i>
                <div class="g-track"><div class="g-knob"></div></div>
                <span id="saThemeLabel">Night</span>
            </div>
            <!-- User Menu -->
            <ul class="nav navbar-nav navbar-custom-menu">
                <li class="dropdown user user-menu">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                        <img src="<?= base_url() ?>tools/image/user.jpg" class="user-image" alt="">
                        <span><?= htmlspecialchars($sa_name ?? 'Super Admin') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <li class="user-header">
                            <img src="<?= base_url() ?>tools/image/user.jpg" style="width:52px;height:52px;" alt="">
                            <p><?= htmlspecialchars($sa_name ?? 'Super Admin') ?><small><?= htmlspecialchars($sa_role ?? 'superadmin') ?></small></p>
                        </li>
                        <li class="user-footer">
                            <a href="<?= base_url('superadmin/dashboard') ?>" class="btn btn-default">Dashboard</a>
                            <a href="<?= base_url('superadmin/logout') ?>"    class="btn btn-danger">Logout</a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
</header>

<!-- SIDEBAR -->
<aside class="main-sidebar">
    <section class="sidebar">
        <ul class="sidebar-menu" data-widget="tree">

            <li class="g-sec">Overview</li>
            <li class="<?= ($this->router->fetch_class() === 'superadmin') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/dashboard') ?>">
                    <i class="fa fa-th-large"></i><span>Dashboard</span>
                </a>
            </li>

            <li class="g-sec">School Management</li>
            <li class="<?= ($this->router->fetch_class() === 'superadmin_schools' && $this->router->fetch_method() !== 'create') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/schools') ?>">
                    <i class="fa fa-building"></i><span>All Schools</span>
                </a>
            </li>
            <li class="<?= ($this->router->fetch_class() === 'superadmin_schools' && $this->router->fetch_method() === 'create') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/schools/create') ?>">
                    <i class="fa fa-plus-circle"></i><span>Onboard School</span>
                </a>
            </li>

            <li class="g-sec">Subscription</li>
            <?php
            $isPlans = ($this->router->fetch_class() === 'superadmin_plans');
            $plansMethod = $this->router->fetch_method();
            ?>
            <li class="treeview <?= $isPlans ? 'active' : '' ?>">
                <a href="#">
                    <i class="fa fa-tags"></i><span>Plans &amp; Billing</span>
                    <span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span>
                </a>
                <ul class="treeview-menu">
                    <li class="<?= ($isPlans && $plansMethod === 'index') ? 'active' : '' ?>">
                        <a href="<?= base_url('superadmin/plans') ?>"><i class="fa fa-circle-o"></i> Plan Catalogue</a>
                    </li>
                    <li class="<?= ($isPlans && $plansMethod === 'subscriptions') ? 'active' : '' ?>">
                        <a href="<?= base_url('superadmin/plans/subscriptions') ?>"><i class="fa fa-circle-o"></i> Subscriptions</a>
                    </li>
                    <li class="<?= ($isPlans && $plansMethod === 'payments') ? 'active' : '' ?>">
                        <a href="<?= base_url('superadmin/plans/payments') ?>"><i class="fa fa-circle-o"></i> Payments</a>
                    </li>
                </ul>
            </li>

            <li class="g-sec">Analytics</li>
            <li class="<?= ($this->router->fetch_class() === 'superadmin_reports') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/reports') ?>">
                    <i class="fa fa-bar-chart"></i><span>Global Reports</span>
                </a>
            </li>

            <?php if (($sa_role ?? '') === 'developer'): ?>
            <li class="g-sec">Admin Access</li>
            <li class="<?= ($this->router->fetch_class() === 'superadmin_admins') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/admins') ?>">
                    <i class="fa fa-user-secret"></i><span>Super Admins</span>
                </a>
            </li>
            <?php endif; ?>

            <li class="g-sec">System</li>
            <li class="<?= ($this->router->fetch_class() === 'superadmin_monitor') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/monitor') ?>">
                    <i class="fa fa-heartbeat"></i><span>System Monitor</span>
                </a>
            </li>
            <li class="<?= ($this->router->fetch_class() === 'superadmin_backups') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/backups') ?>">
                    <i class="fa fa-database"></i><span>Backup & Restore</span>
                </a>
            </li>

            <li class="<?= ($this->router->fetch_class() === 'superadmin_migration') ? 'active' : '' ?>">
                <a href="<?= base_url('superadmin/migration') ?>">
                    <i class="fa fa-database"></i><span>Migration Tool</span>
                </a>
            </li>

            <li class="<?= ($this->router->fetch_class() === 'superadmin_debug') ? 'active' : '' ?>"
                style="<?= defined('GRADER_DEBUG') && GRADER_DEBUG ? 'border-left:2px solid var(--green);' : '' ?>">
                <a href="<?= base_url('superadmin/debug') ?>">
                    <i class="fa fa-bug" style="<?= defined('GRADER_DEBUG') && GRADER_DEBUG ? 'color:var(--green);' : '' ?>"></i>
                    <span>Debug Panel<?= defined('GRADER_DEBUG') && GRADER_DEBUG ? ' <span style="font-size:9px;background:var(--green);color:#fff;padding:1px 5px;border-radius:8px;margin-left:4px;">ON</span>' : '' ?></span>
                </a>
            </li>

            <li class="g-sec">Navigation</li>
            <li>
                <a href="<?= base_url('admin_login') ?>" target="_blank">
                    <i class="fa fa-external-link"></i><span>School Admin Panel</span>
                </a>
            </li>

        </ul>
    </section>
    <!-- Sidebar footer -->
    <div class="g-sb-foot">
        <div class="g-av"><?= strtoupper(substr($sa_name ?? 'S', 0, 1)) ?></div>
        <div>
            <div class="g-av-name"><?= htmlspecialchars($sa_name ?? 'Super Admin') ?></div>
            <div class="g-av-role">Super Admin</div>
        </div>
        <a href="<?= base_url('superadmin/logout') ?>" class="g-av-out" title="Logout">
            <i class="fa fa-sign-out"></i>
        </a>
    </div>
</aside>

<!-- CONTENT WRAPPER -->
<div class="content-wrapper">
