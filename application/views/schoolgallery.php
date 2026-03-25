<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ═══════════════════════════════════════════════════════════════════════
   School Gallery — Themed with global CSS vars (day + night mode)
   ═══════════════════════════════════════════════════════════════════════ */
:root {
    --sg-teal:    var(--gold, #0f766e);
    --sg-teal-lt: var(--gold-dim, rgba(15,118,110,.10));
    --sg-navy:    var(--t1, #1a2332);
    --sg-text:    var(--t1, #1a2332);
    --sg-muted:   var(--t3, #6b7280);
    --sg-border:  var(--border, #e5e7eb);
    --sg-bg:      var(--bg, #f4f6f9);
    --sg-white:   var(--bg2, #ffffff);
    --sg-bg3:     var(--bg3, #e6f4f1);
    --sg-shadow:  var(--sh, 0 2px 8px rgba(0,0,0,.08));
    --sg-radius:  10px;
    --sg-amber:   #d97706;
    --sg-red:     #dc2626;
    --sg-green:   #16a34a;
    --sg-purple:  #7c3aed;
    --sg-font:    var(--font-b, 'Plus Jakarta Sans', sans-serif);
}

.sg-wrap { padding: 20px 24px; background: var(--sg-bg); min-height: 100vh; font-family: var(--sg-font); }

/* ── Top bar ── */
.sg-topbar {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 22px; flex-wrap: wrap; gap: 12px;
}
.sg-page-title {
    font-size: 22px; font-weight: 700; color: var(--sg-navy); margin: 0 0 4px;
    display: flex; align-items: center; gap: 8px;
}
.sg-page-title i { color: var(--sg-teal); }
.sg-breadcrumb {
    list-style: none; padding: 0; margin: 0; display: flex;
    align-items: center; gap: 6px; font-size: 12px; color: var(--sg-muted);
}
.sg-breadcrumb li:not(:last-child)::after { content: '/'; margin-left: 6px; }
.sg-breadcrumb a { color: var(--sg-teal); text-decoration: none; }
.sg-topbar-right { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* ── Buttons ── */
.sg-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 7px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: none; transition: all .18s; text-decoration: none;
    font-family: var(--sg-font);
}
.sg-btn-primary { background: var(--sg-teal); color: #fff; }
.sg-btn-primary:hover { filter: brightness(1.1); }
.sg-btn-danger { background: var(--sg-red); color: #fff; }
.sg-btn-danger:hover { background: #b91c1c; }
.sg-btn-ghost { background: var(--sg-white); color: var(--sg-navy); border: 1.5px solid var(--sg-border); }
.sg-btn-ghost:hover { border-color: var(--sg-teal); color: var(--sg-teal); }
.sg-btn-sm { padding: 6px 12px; font-size: 12px; }
.sg-btn:disabled { opacity: .55; cursor: not-allowed; }

/* ── Stat strip ── */
.sg-stat-strip {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px; margin-bottom: 22px;
}
.sg-stat {
    background: var(--sg-white); border-radius: var(--sg-radius); padding: 16px 18px;
    display: flex; align-items: center; gap: 14px; box-shadow: var(--sg-shadow);
    border-left: 4px solid transparent;
}
.sg-stat-blue   { border-left-color: #3b82f6; }
.sg-stat-green  { border-left-color: var(--sg-green); }
.sg-stat-amber  { border-left-color: var(--sg-amber); }
.sg-stat-purple { border-left-color: var(--sg-purple); }
.sg-stat-icon {
    width: 42px; height: 42px; border-radius: 10px; display: flex;
    align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
}
.sg-stat-blue   .sg-stat-icon { background: rgba(59,130,246,.1); color: #3b82f6; }
.sg-stat-green  .sg-stat-icon { background: rgba(22,163,74,.1); color: var(--sg-green); }
.sg-stat-amber  .sg-stat-icon { background: rgba(217,119,6,.1); color: var(--sg-amber); }
.sg-stat-purple .sg-stat-icon { background: rgba(124,58,237,.1); color: var(--sg-purple); }
.sg-stat-label { font-size: 11px; color: var(--sg-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.sg-stat-val { font-size: 22px; font-weight: 800; color: var(--sg-navy); line-height: 1.2; }

/* ── Card ── */
.sg-card {
    background: var(--sg-white); border-radius: var(--sg-radius);
    box-shadow: var(--sg-shadow); margin-bottom: 22px; overflow: hidden;
    border: 1px solid var(--sg-border);
}
.sg-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1.5px solid var(--sg-border); flex-wrap: wrap; gap: 10px;
}
.sg-card-head-left { display: flex; align-items: center; gap: 10px; }
.sg-card-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--sg-navy); }
.sg-card-head i { color: var(--sg-teal); font-size: 16px; }
.sg-card-head-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sg-card-body { padding: 20px; }

/* ── Albums grid ── */
.sg-albums-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px;
}
.sg-album-card {
    border-radius: var(--sg-radius); overflow: hidden;
    background: var(--sg-white); box-shadow: var(--sg-shadow);
    border: 1.5px solid var(--sg-border); cursor: pointer; transition: all .2s;
}
.sg-album-card:hover {
    transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,.12);
    border-color: var(--sg-teal);
}
.sg-album-cover { width: 100%; height: 160px; overflow: hidden; background: var(--sg-bg3); }
.sg-album-cover img {
    width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .3s;
}
.sg-album-card:hover .sg-album-cover img { transform: scale(1.05); }
.sg-album-placeholder {
    width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
    color: var(--sg-muted); font-size: 40px;
}
.sg-album-info { padding: 14px 16px; }
.sg-album-name {
    font-size: 14px; font-weight: 700; color: var(--sg-navy);
    margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sg-album-counts { display: flex; gap: 14px; font-size: 12px; color: var(--sg-muted); margin-bottom: 10px; }
.sg-album-counts i { margin-right: 4px; }
.sg-album-bottom { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sg-album-date { font-size: 11px; color: var(--sg-muted); }

/* ── Badges ── */
.sg-badge {
    display: inline-block; font-size: 10px; font-weight: 700; padding: 3px 8px;
    border-radius: 4px; text-transform: uppercase; letter-spacing: .3px;
}
.sg-badge-teal  { background: var(--sg-teal-lt); color: var(--sg-teal); }
.sg-badge-green { background: rgba(22,163,74,.1); color: var(--sg-green); }
.sg-badge-muted { background: var(--sg-bg3); color: var(--sg-muted); }

/* ── Album detail header ── */
.sg-album-header {
    background: var(--sg-white); border-radius: var(--sg-radius); box-shadow: var(--sg-shadow);
    padding: 20px 24px; margin-bottom: 22px; border: 1px solid var(--sg-border);
}
.sg-album-title-row { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
.sg-album-title-row h2 { margin: 0; font-size: 20px; font-weight: 700; color: var(--sg-navy); }
.sg-album-badge {
    font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 5px;
    background: var(--sg-teal-lt); color: var(--sg-teal);
}
.sg-album-badge.sg-badge-muted { background: var(--sg-bg3); color: var(--sg-muted); }
.sg-album-meta { font-size: 13px; color: var(--sg-muted); }
.sg-album-meta i { margin-right: 3px; }

/* ── Upload zone ── */
.sg-upload-zone {
    border: 2px dashed var(--sg-border); border-radius: var(--sg-radius);
    padding: 32px 20px; text-align: center; cursor: pointer;
    transition: all .2s; background: var(--sg-bg);
}
.sg-upload-zone:hover, .sg-upload-zone.drag-over {
    border-color: var(--sg-teal); background: var(--sg-teal-lt);
}
.sg-upload-icon { font-size: 36px; color: var(--sg-teal); margin-bottom: 10px; }
.sg-upload-hint { font-size: 13px; color: var(--sg-muted); margin: 4px 0 0; }
.sg-upload-progress {
    display: none; margin-top: 14px;
    background: var(--sg-border); border-radius: 20px; height: 6px; overflow: hidden;
}
.sg-upload-bar { height: 100%; background: var(--sg-teal); width: 0%; transition: width .3s; border-radius: 20px; }
.sg-upload-status { font-size: 12px; color: var(--sg-teal); margin-top: 8px; display: none; }

/* ── Filter / search ── */
.sg-filter-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.sg-search-wrap { position: relative; flex: 1; min-width: 180px; }
.sg-search-sm { max-width: 260px; }
.sg-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--sg-muted); font-size: 13px; }
.sg-search {
    width: 100%; padding: 8px 12px 8px 32px; border: 1.5px solid var(--sg-border);
    border-radius: 7px; font-size: 13px; outline: none; transition: border .18s;
    background: var(--sg-white); color: var(--sg-text); font-family: var(--sg-font);
}
.sg-search:focus { border-color: var(--sg-teal); }
.sg-tab-pills { display: flex; gap: 4px; background: var(--sg-bg); border-radius: 8px; padding: 3px; }
.sg-tab {
    padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; border: none; background: transparent; color: var(--sg-muted);
    transition: all .18s; font-family: var(--sg-font);
}
.sg-tab.active { background: var(--sg-white); color: var(--sg-navy); box-shadow: 0 1px 4px rgba(0,0,0,.1); }
.sg-media-count { font-size: 12px; color: var(--sg-muted); white-space: nowrap; }

/* ── Media grid ── */
.sg-category { margin-bottom: 28px; }
.sg-section-title {
    font-size: 13px; font-weight: 700; color: var(--sg-navy); margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px;
}
.sg-section-toggle { cursor: pointer; color: var(--sg-muted); font-size: 12px; margin-left: auto; }
.sg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }

/* ── Grid item ── */
.sg-item {
    border-radius: 10px; overflow: hidden;
    background: var(--sg-white); box-shadow: var(--sg-shadow);
    border: 1.5px solid var(--sg-border); transition: all .18s; position: relative;
}
.sg-item:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.12); border-color: var(--sg-teal); }
.sg-item.selected { border-color: var(--sg-teal); box-shadow: 0 0 0 3px rgba(13,148,136,.2); }
.sg-item-media { width: 100%; height: 150px; object-fit: cover; display: block; cursor: pointer; }
.sg-item-video-wrap { position: relative; width: 100%; height: 150px; overflow: hidden; cursor: pointer; }
.sg-item-video-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.sg-play-btn { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,.3); }
.sg-play-btn i { font-size: 36px; color: #fff; text-shadow: 0 2px 8px rgba(0,0,0,.4); }
.sg-duration { position: absolute; bottom: 6px; right: 8px; background: rgba(0,0,0,.7); color: #fff; font-size: 11px; font-weight: 600; padding: 2px 6px; border-radius: 4px; }
.sg-item-check {
    position: absolute; top: 8px; left: 8px; z-index: 2;
    width: 20px; height: 20px; accent-color: var(--sg-teal); cursor: pointer;
    opacity: 0; transition: opacity .15s;
}
.sg-item:hover .sg-item-check, .sg-item.selected .sg-item-check { opacity: 1; }
.sg-item-footer { padding: 10px 12px; border-top: 1px solid var(--sg-border); }
.sg-item-name { font-size: 11px; font-weight: 600; color: var(--sg-navy); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px; }
.sg-item-date { font-size: 10px; color: var(--sg-muted); margin-bottom: 8px; }
.sg-item-actions { display: flex; gap: 6px; }
.sg-item-actions .sg-btn { flex: 1; justify-content: center; }

/* ── Empty state ── */
.sg-empty { text-align: center; padding: 48px 20px; color: var(--sg-muted); }
.sg-empty i { font-size: 40px; margin-bottom: 12px; opacity: .4; display: block; }

/* ── Lightbox ── */
.sg-lightbox {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.88); z-index: 2000;
    align-items: center; justify-content: center; padding: 20px;
}
.sg-lightbox.open { display: flex; }
.sg-lightbox-inner { position: relative; max-width: 90vw; max-height: 90vh; }
.sg-lightbox-inner img { max-width: 90vw; max-height: 85vh; border-radius: 8px; display: block; }
.sg-lightbox-inner video { max-width: 90vw; max-height: 85vh; border-radius: 8px; display: block; }
.sg-lightbox-close {
    position: absolute; top: -14px; right: -14px;
    width: 36px; height: 36px; border-radius: 50%; background: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; border: none; font-size: 18px; color: var(--sg-navy);
    box-shadow: 0 2px 8px rgba(0,0,0,.3); z-index: 10;
}
.sg-lightbox-close:hover { background: var(--sg-red); color: #fff; }

/* ── Loading overlay ── */
.sg-loading {
    display: none; position: fixed; inset: 0; z-index: 1999;
    align-items: center; justify-content: center;
}
.sg-loading.active { display: flex; }
.sg-loading-box {
    display: flex; flex-direction: column; align-items: center; gap: 12px;
    background: var(--sg-white); border: 1px solid var(--sg-border);
    border-radius: 12px; padding: 28px 36px;
    box-shadow: 0 8px 32px rgba(0,0,0,.12);
}
.sg-spinner {
    width: 36px; height: 36px;
    border: 3.5px solid var(--sg-border);
    border-top-color: var(--sg-teal);
    border-radius: 50%;
    animation: sgSpin .7s linear infinite;
}
.sg-spinner-text {
    font-size: 13px; font-weight: 600; color: var(--sg-muted);
}
@keyframes sgSpin { to { transform: rotate(360deg); } }

/* ── Toast ── */
.sg-toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.sg-toast {
    display: flex; align-items: center; gap: 10px; padding: 12px 18px;
    border-radius: 8px; font-size: 13px; font-weight: 600; font-family: var(--sg-font);
    box-shadow: 0 4px 16px rgba(0,0,0,.15); animation: sgToastIn .25s ease; min-width: 240px;
}
@keyframes sgToastIn { from { transform: translateX(60px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.sg-toast-success { background: #f0fdf4; color: var(--sg-green); border-left: 4px solid var(--sg-green); }
.sg-toast-error   { background: #fef2f2; color: var(--sg-red);   border-left: 4px solid var(--sg-red); }
.sg-toast-info    { background: var(--sg-teal-lt); color: var(--sg-teal); border-left: 4px solid var(--sg-teal); }
.sg-toast-hide    { animation: sgToastOut .3s ease forwards; }
@keyframes sgToastOut { to { transform: translateX(60px); opacity: 0; } }

/* ── Responsive ── */
@media (max-width: 767px) {
    .sg-wrap { padding: 14px 12px; }
    .sg-page-title { font-size: 18px; }
    .sg-stat-strip { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .sg-albums-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
    .sg-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
    .sg-album-cover { height: 120px; }
    .sg-filter-bar { gap: 8px; }
}
@media (max-width: 479px) {
    .sg-stat-strip { grid-template-columns: 1fr; }
    .sg-topbar { flex-direction: column; }
}
</style>

<div class="content-wrapper">
<div class="sg-wrap">

    <!-- ── TOP BAR ── -->
    <div class="sg-topbar">
        <div>
            <h1 class="sg-page-title"><i class="fa fa-picture-o"></i> School Gallery</h1>
            <ol class="sg-breadcrumb">
                <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li><a href="#" onclick="showAlbumsView(); return false;">Gallery</a></li>
                <li class="sg-bc-album" style="display:none;"></li>
            </ol>
        </div>
        <div class="sg-topbar-right">
            <!-- Back button (visible in album detail view) -->
            <button class="sg-btn sg-btn-ghost" id="sgBackBtn" style="display:none;" onclick="showAlbumsView()">
                <i class="fa fa-arrow-left"></i> Back to Albums
            </button>
            <!-- Upload button (visible only in album detail view) -->
            <button class="sg-btn sg-btn-primary" id="sgUploadBtn" style="display:none;" onclick="document.getElementById('sgFileInput').click()">
                <i class="fa fa-cloud-upload"></i> Upload Media
            </button>
            <input type="file" id="sgFileInput" multiple accept="image/*,video/*" style="display:none;">
        </div>
    </div>

    <!-- ── STAT STRIP ── -->
    <div class="sg-stat-strip">
        <div class="sg-stat sg-stat-purple">
            <div class="sg-stat-icon"><i class="fa fa-folder-open"></i></div>
            <div>
                <div class="sg-stat-label">Albums</div>
                <div class="sg-stat-val" id="statAlbums">0</div>
            </div>
        </div>
        <div class="sg-stat sg-stat-blue">
            <div class="sg-stat-icon"><i class="fa fa-picture-o"></i></div>
            <div>
                <div class="sg-stat-label">Total Images</div>
                <div class="sg-stat-val" id="statImages">0</div>
            </div>
        </div>
        <div class="sg-stat sg-stat-amber">
            <div class="sg-stat-icon"><i class="fa fa-film"></i></div>
            <div>
                <div class="sg-stat-label">Total Videos</div>
                <div class="sg-stat-val" id="statVideos">0</div>
            </div>
        </div>
        <div class="sg-stat sg-stat-green">
            <div class="sg-stat-icon"><i class="fa fa-th-large"></i></div>
            <div>
                <div class="sg-stat-label">Total Media</div>
                <div class="sg-stat-val" id="statTotal">0</div>
            </div>
        </div>
    </div>

    <!-- ── STORAGE QUOTA BAR ── -->
    <div class="sg-card" id="sgQuotaCard" style="margin-bottom:16px;display:none;">
        <div class="sg-card-body" style="padding:14px 20px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                        <span style="font-size:12px;font-weight:600;color:var(--sg-text);">Storage Usage</span>
                        <span style="font-size:11px;color:var(--sg-muted);" id="sgQuotaText">—</span>
                    </div>
                    <div style="height:6px;background:rgba(15,118,110,.1);border-radius:3px;overflow:hidden;">
                        <div id="sgQuotaBar" style="height:100%;width:0%;border-radius:3px;background:var(--sg-teal);transition:width .5s;"></div>
                    </div>
                </div>
                <div style="display:flex;gap:14px;font-size:11.5px;color:var(--sg-muted);">
                    <span><i class="fa fa-picture-o" style="color:var(--sg-teal);margin-right:3px;"></i> <span id="sgQuotaImg">0</span> / <span id="sgQuotaImgMax">200</span> images</span>
                    <span><i class="fa fa-video-camera" style="color:#d97706;margin-right:3px;"></i> <span id="sgQuotaVid">0</span> / <span id="sgQuotaVidMax">30</span> videos</span>
                    <span style="font-size:10px;color:var(--sg-muted);opacity:.7;">Max <span id="sgQuotaFileSize">3</span>MB/img &middot; <span id="sgQuotaVidSize">25</span>MB/vid</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!--  VIEW 1: ALBUMS GRID                                             -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <div id="sgAlbumsView">
        <div class="sg-card">
            <div class="sg-card-head">
                <div class="sg-card-head-left">
                    <i class="fa fa-th"></i>
                    <h3>Event Albums</h3>
                </div>
                <div class="sg-card-head-right">
                    <div class="sg-search-wrap sg-search-sm">
                        <i class="fa fa-search sg-search-icon"></i>
                        <input type="text" class="sg-search" id="sgAlbumSearch" placeholder="Search albums..." autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="sg-card-body">
                <div class="sg-albums-grid" id="sgAlbumsGrid"></div>
                <div class="sg-empty" id="sgAlbumsEmpty" style="display:none;">
                    <i class="fa fa-folder-open-o"></i>
                    <p style="font-size:15px;font-weight:600;margin:0 0 6px;">No albums yet</p>
                    <p style="font-size:13px;margin:0;">Create an event and upload media to start building your gallery.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════ -->
    <!--  VIEW 2: ALBUM DETAIL (media grid)                               -->
    <!-- ══════════════════════════════════════════════════════════════════ -->
    <div id="sgAlbumDetailView" style="display:none;">

        <!-- Album header -->
        <div class="sg-album-header" id="sgAlbumHeader">
            <div class="sg-album-title-row">
                <h2 id="sgAlbumTitle">Album</h2>
                <span class="sg-album-badge" id="sgAlbumCategory"></span>
            </div>
            <div class="sg-album-meta" id="sgAlbumMeta"></div>
        </div>

        <!-- Upload zone -->
        <div class="sg-card" id="sgUploadCard">
            <div class="sg-card-head">
                <div class="sg-card-head-left">
                    <i class="fa fa-cloud-upload"></i>
                    <h3>Upload to Album</h3>
                </div>
            </div>
            <div class="sg-card-body">
                <div class="sg-upload-zone" id="sgDropZone" onclick="document.getElementById('sgFileInput').click()">
                    <div class="sg-upload-icon"><i class="fa fa-cloud-upload"></i></div>
                    <p style="font-size:14px;font-weight:600;color:var(--sg-navy);margin:0 0 4px;">
                        Drop images &amp; videos here or click to browse
                    </p>
                    <p class="sg-upload-hint">Images: JPG, PNG, WEBP (max 5MB) &nbsp;·&nbsp; Videos: MP4, MOV, AVI (max 50MB)</p>
                </div>
                <div class="sg-upload-progress" id="sgUploadProgress">
                    <div class="sg-upload-bar" id="sgUploadBar"></div>
                </div>
                <div class="sg-upload-status" id="sgUploadStatus"></div>
            </div>
        </div>

        <!-- Media grid -->
        <div class="sg-card">
            <div class="sg-card-head">
                <div class="sg-card-head-left">
                    <i class="fa fa-th"></i>
                    <h3>Media</h3>
                </div>
                <div class="sg-card-head-right">
                    <button class="sg-btn sg-btn-danger sg-btn-sm" id="sgDeleteSelBtn" style="display:none;"
                        onclick="deleteSelectedFiles()">
                        <i class="fa fa-trash-o"></i> Delete Selected (<span id="sgSelCount">0</span>)
                    </button>
                </div>
            </div>
            <div class="sg-card-body">
                <div class="sg-filter-bar">
                    <div class="sg-search-wrap">
                        <i class="fa fa-search sg-search-icon"></i>
                        <input type="text" class="sg-search" id="sgSearch" placeholder="Search by filename or date..." autocomplete="off">
                    </div>
                    <div class="sg-tab-pills">
                        <button class="sg-tab active" data-tab="all">All</button>
                        <button class="sg-tab" data-tab="images">Images</button>
                        <button class="sg-tab" data-tab="videos">Videos</button>
                    </div>
                    <span class="sg-media-count" id="sgMediaCount">Loading...</span>
                </div>

                <!-- Images section -->
                <div class="sg-category" id="sgImagesSection">
                    <div class="sg-section-title">
                        <i class="fa fa-picture-o" style="color:var(--sg-teal);"></i> Images
                        <span class="sg-section-toggle" onclick="toggleSection('sgImagesGrid','sgImgArrow')">
                            <i class="fa fa-chevron-up" id="sgImgArrow"></i>
                        </span>
                    </div>
                    <div class="sg-grid" id="sgImagesGrid"></div>
                </div>

                <!-- Videos section -->
                <div class="sg-category" id="sgVideosSection">
                    <div class="sg-section-title">
                        <i class="fa fa-film" style="color:var(--sg-amber);"></i> Videos
                        <span class="sg-section-toggle" onclick="toggleSection('sgVideosGrid','sgVidArrow')">
                            <i class="fa fa-chevron-up" id="sgVidArrow"></i>
                        </span>
                    </div>
                    <div class="sg-grid" id="sgVideosGrid"></div>
                </div>

                <!-- Empty state -->
                <div class="sg-empty" id="sgEmptyState" style="display:none;">
                    <i class="fa fa-picture-o"></i>
                    <p style="font-size:15px;font-weight:600;margin:0 0 6px;">No media in this album</p>
                    <p style="font-size:13px;margin:0 0 16px;">Upload images and videos to this event album.</p>
                    <button class="sg-btn sg-btn-primary" onclick="document.getElementById('sgFileInput').click()">
                        <i class="fa fa-cloud-upload"></i> Upload Now
                    </button>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.sg-wrap -->
</div><!-- /.content-wrapper -->


<!-- ── Lightbox ── -->
<div class="sg-lightbox" id="sgLightbox">
    <div class="sg-lightbox-inner" id="sgLightboxInner">
        <button class="sg-lightbox-close" onclick="closeLightbox()">&times;</button>
    </div>
</div>

<!-- ── Loading overlay ── -->
<div class="sg-loading" id="sgLoading">
    <div class="sg-loading-box">
        <div class="sg-spinner"></div>
        <div class="sg-spinner-text">Loading gallery...</div>
    </div>
</div>

<!-- Toast -->
<div class="sg-toast-wrap" id="sgToastWrap"></div>


<script>
/* ═══════════════════════════════════════════════════════════════════════
   School Gallery — Event-Based Albums
   ═══════════════════════════════════════════════════════════════════════ */

var SG = {
    albums: [],
    images: [],
    videos: [],
    activeTab: 'all',
    currentAlbum: null   // { event_id, title, category, ... }
};

var BASE = '<?= rtrim(base_url(), '/') ?>';
var CSRF_NAME = document.querySelector('meta[name="csrf-name"]').content;
var CSRF_HASH = document.querySelector('meta[name="csrf-token"]').content;

/* ── Utilities ── */
function showToast(msg, type) {
    var wrap = document.getElementById('sgToastWrap');
    var el = document.createElement('div');
    el.className = 'sg-toast sg-toast-' + (type || 'info');
    var icons = { success: 'check-circle', error: 'times-circle', info: 'info-circle' };
    el.innerHTML = '<i class="fa fa-' + (icons[type] || 'info-circle') + '"></i> ' + msg;
    wrap.appendChild(el);
    setTimeout(function() {
        el.classList.add('sg-toast-hide');
        setTimeout(function() { el.remove(); }, 350);
    }, 3500);
}

function showLoading(v) {
    document.getElementById('sgLoading').classList[v ? 'add' : 'remove']('active');
}

function extractFileName(url) {
    try {
        var dec = decodeURIComponent(url);
        var parts = dec.split('/');
        return parts[parts.length - 1].split('?')[0];
    } catch (e) { return 'Unknown'; }
}

function formatDate(ts) {
    if (!ts) return '';
    var d = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);
    return d.toLocaleDateString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric'
    });
}

function formatDateTime(ts) {
    if (!ts) return '';
    return new Date(ts * 1000).toLocaleDateString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });
}

/* ── Toggle section ── */
function toggleSection(gridId, arrowId) {
    var grid  = document.getElementById(gridId);
    var arrow = document.getElementById(arrowId);
    if (!grid) return;
    var collapsed = grid.style.display === 'none';
    grid.style.display = collapsed ? 'grid' : 'none';
    arrow.className = collapsed ? 'fa fa-chevron-up' : 'fa fa-chevron-down';
}


/* ══════════════════════════════════════════════════════════════════════
   VIEW 1: ALBUMS
   ══════════════════════════════════════════════════════════════════════ */

var SG_LIMITS = {}; // storage limits from server

function fetchAlbums() {
    showLoading(true);
    fetch(BASE + '/schools/fetchGalleryAlbums')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            SG.albums = data.albums || [];
            SG_LIMITS = data.limits || {};
            renderAlbums();
            updateStats();
            updateQuotaBar(data.total_images || 0, data.total_videos || 0);
        })
        .catch(function(e) {
            console.error('fetchAlbums:', e);
            showToast('Failed to load albums.', 'error');
        })
        .finally(function() { showLoading(false); });
}

function updateQuotaBar(totalImg, totalVid) {
    var L = SG_LIMITS;
    if (!L.max_images_per_school) return;
    document.getElementById('sgQuotaCard').style.display = '';
    document.getElementById('sgQuotaImg').textContent = totalImg;
    document.getElementById('sgQuotaVid').textContent = totalVid;
    document.getElementById('sgQuotaImgMax').textContent = L.max_images_per_school;
    document.getElementById('sgQuotaVidMax').textContent = L.max_videos_per_school;
    document.getElementById('sgQuotaFileSize').textContent = L.max_image_size_mb;
    document.getElementById('sgQuotaVidSize').textContent = L.max_video_size_mb;

    var totalFiles = totalImg + totalVid;
    var maxFiles = L.max_images_per_school + L.max_videos_per_school;
    var pct = maxFiles > 0 ? Math.min(100, Math.round(totalFiles / maxFiles * 100)) : 0;
    var bar = document.getElementById('sgQuotaBar');
    bar.style.width = pct + '%';
    bar.style.background = pct > 90 ? '#ef4444' : (pct > 70 ? '#f97316' : 'var(--sg-teal)');
    document.getElementById('sgQuotaText').textContent = totalFiles + ' / ' + maxFiles + ' files (' + pct + '%)';
}

function renderAlbums() {
    var grid  = document.getElementById('sgAlbumsGrid');
    var empty = document.getElementById('sgAlbumsEmpty');

    if (!SG.albums.length) {
        grid.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    grid.innerHTML = SG.albums.map(function(album) {
        var isDef = album.is_default;
        var isPhotos = album.event_id === '__photos__';
        var isVideos = album.event_id === '__videos__';

        var cover;
        if (isDef && !album.cover) {
            // Stylized placeholder for default albums
            var defIcon = album.icon || (isPhotos ? 'fa-camera' : 'fa-video-camera');
            var defColor = isPhotos ? 'linear-gradient(135deg,#0f766e,#14b8a6)' : 'linear-gradient(135deg,#d97706,#f59e0b)';
            cover = '<div class="sg-album-placeholder" style="background:' + defColor + ';"><i class="fa ' + defIcon + '" style="font-size:36px;color:#fff;"></i></div>';
        } else if (album.cover) {
            cover = '<img src="' + album.cover + '" alt="' + album.title + '">';
        } else {
            cover = '<div class="sg-album-placeholder"><i class="fa fa-picture-o"></i></div>';
        }

        var badge;
        if (isDef) {
            badge = '<span class="sg-badge sg-badge-teal" style="background:' + (isPhotos ? 'rgba(15,118,110,.12)' : 'rgba(217,119,6,.12)') + ';color:' + (isPhotos ? '#0f766e' : '#d97706') + ';">' + (isPhotos ? 'Photos' : 'Videos') + '</span>';
        } else if (album.category === 'general') {
            badge = '<span class="sg-badge sg-badge-muted">Legacy</span>';
        } else if (album.status === 'completed') {
            badge = '<span class="sg-badge sg-badge-green">Completed</span>';
        } else {
            badge = '<span class="sg-badge sg-badge-teal">' + (album.status || 'Event') + '</span>';
        }

        var dateStr = (album.start_date && album.start_date !== '9999-99-99') ? formatDate(album.start_date) : '';

        var cardStyle = isDef ? 'border:2px solid ' + (isPhotos ? 'rgba(15,118,110,.3)' : 'rgba(217,119,6,.3)') + ';' : '';

        return '<div class="sg-album-card" style="' + cardStyle + '" data-id="' + album.event_id + '" data-title="' + album.title.toLowerCase() + '" onclick="openAlbum(\'' + album.event_id + '\')">'
            + '<div class="sg-album-cover">' + cover + '</div>'
            + '<div class="sg-album-info">'
            +   '<div class="sg-album-name">' + (isDef ? '<i class="fa ' + (album.icon||'fa-folder') + '" style="margin-right:5px;color:' + (isPhotos?'#0f766e':'#d97706') + ';"></i>' : '') + album.title + '</div>'
            +   '<div class="sg-album-counts">'
            +       '<span><i class="fa fa-picture-o"></i> ' + album.image_count + '</span>'
            +       '<span><i class="fa fa-film"></i> ' + album.video_count + '</span>'
            +   '</div>'
            +   '<div class="sg-album-bottom">'
            +       badge
            +       (dateStr ? '<span class="sg-album-date">' + dateStr + '</span>' : '')
            +   '</div>'
            + '</div>'
            + '</div>';
    }).join('');
}

function updateStats() {
    var totalImages = 0, totalVideos = 0;
    SG.albums.forEach(function(a) {
        totalImages += a.image_count || 0;
        totalVideos += a.video_count || 0;
    });
    document.getElementById('statAlbums').textContent = SG.albums.length;
    document.getElementById('statImages').textContent = totalImages;
    document.getElementById('statVideos').textContent = totalVideos;
    document.getElementById('statTotal').textContent  = totalImages + totalVideos;
}

/* Album search */
document.getElementById('sgAlbumSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('.sg-album-card').forEach(function(card) {
        card.style.display = !q || card.dataset.title.includes(q) ? '' : 'none';
    });
});


/* ══════════════════════════════════════════════════════════════════════
   VIEW 2: ALBUM DETAIL
   ══════════════════════════════════════════════════════════════════════ */

function openAlbum(eventId) {
    var album = SG.albums.find(function(a) { return a.event_id === eventId; });
    if (!album) return;

    SG.currentAlbum = album;
    SG.activeTab = 'all';

    // Update breadcrumb
    document.querySelector('.sg-bc-album').style.display = '';
    document.querySelector('.sg-bc-album').textContent = album.title;

    // Update album header
    document.getElementById('sgAlbumTitle').textContent = album.title;
    var catEl = document.getElementById('sgAlbumCategory');
    catEl.textContent = album.category === 'general' ? 'Legacy Gallery' : (album.category || 'Event');
    catEl.className = 'sg-album-badge' + (album.category === 'general' ? ' sg-badge-muted' : '');
    var metaParts = [];
    if (album.start_date) metaParts.push('<i class="fa fa-calendar"></i> ' + formatDate(album.start_date));
    metaParts.push('<i class="fa fa-picture-o"></i> ' + album.image_count + ' images');
    metaParts.push('<i class="fa fa-film"></i> ' + album.video_count + ' videos');
    document.getElementById('sgAlbumMeta').innerHTML = metaParts.join(' &nbsp;&middot;&nbsp; ');

    // Show upload for event albums and default albums; hide for legacy
    var isLegacy = eventId === '__legacy__';
    var allowUpload = !isLegacy; // default albums (__photos__, __videos__) + event albums can upload
    document.getElementById('sgUploadCard').style.display = allowUpload ? '' : 'none';
    document.getElementById('sgUploadBtn').style.display = allowUpload ? '' : 'none';

    // Switch views
    document.getElementById('sgAlbumsView').style.display = 'none';
    document.getElementById('sgAlbumDetailView').style.display = '';
    document.getElementById('sgBackBtn').style.display = '';

    // Reset tabs
    document.querySelectorAll('.sg-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelector('.sg-tab[data-tab="all"]').classList.add('active');

    fetchAlbumMedia(eventId);
}

function showAlbumsView() {
    SG.currentAlbum = null;
    document.getElementById('sgAlbumsView').style.display = '';
    document.getElementById('sgAlbumDetailView').style.display = 'none';
    document.getElementById('sgBackBtn').style.display = 'none';
    document.getElementById('sgUploadBtn').style.display = 'none';
    document.querySelector('.sg-bc-album').style.display = 'none';
}

function fetchAlbumMedia(eventId) {
    showLoading(true);
    document.getElementById('sgMediaCount').textContent = 'Loading...';

    fetch(BASE + '/schools/fetchAlbumMedia?event_id=' + encodeURIComponent(eventId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            SG.images = data.images || [];
            SG.videos = data.videos || [];
            renderMedia();
        })
        .catch(function(e) {
            console.error('fetchAlbumMedia:', e);
            showToast('Failed to load album media.', 'error');
        })
        .finally(function() { showLoading(false); });
}


/* ── Render media grid ── */
function renderMedia() {
    renderGrid(SG.images, 'sgImagesGrid', 'image');
    renderGrid(SG.videos, 'sgVideosGrid', 'video');

    updateMediaCount();
    applyTabFilter(SG.activeTab);

    var empty = SG.images.length === 0 && SG.videos.length === 0;
    document.getElementById('sgEmptyState').style.display   = empty ? 'block' : 'none';
    document.getElementById('sgImagesSection').style.display = empty ? 'none' : '';
    document.getElementById('sgVideosSection').style.display = empty ? 'none' : '';
}

function renderGrid(items, gridId, type) {
    var grid = document.getElementById(gridId);
    grid.innerHTML = '';

    if (!items.length) {
        grid.innerHTML = '<p style="font-size:13px;color:var(--sg-muted);padding:16px 0;">No ' + type + 's found.</p>';
        return;
    }

    var isLegacy = SG.currentAlbum && SG.currentAlbum.event_id === '__legacy__';

    items.forEach(function(media) {
        var name = extractFileName(media.url);
        var date = formatDateTime(media.timestamp);
        var safeUrl = media.url.replace(/'/g, "\\'");

        var mediaHtml = '';
        if (type === 'image') {
            mediaHtml = '<img src="' + media.url + '" class="sg-item-media" alt="' + name + '" onclick="openLightbox(\'' + safeUrl + '\',\'image\')">';
        } else {
            var thumb = media.thumbnail || media.url;
            var duration = media.duration || '';
            mediaHtml = '<div class="sg-item-video-wrap" onclick="openLightbox(\'' + safeUrl + '\',\'video\')">'
                + '<img src="' + thumb + '" alt="' + name + '">'
                + '<div class="sg-play-btn"><i class="fa fa-play-circle"></i></div>'
                + (duration ? '<span class="sg-duration">' + duration + '</span>' : '')
                + '</div>';
        }

        var coverBtn = '';
        if (!isLegacy && type === 'image') {
            coverBtn = '<button class="sg-btn sg-btn-ghost sg-btn-sm" title="Set as cover" onclick="setCoverImage(\'' + safeUrl + '\', this)"><i class="fa fa-bookmark-o"></i></button>';
        }

        var item = document.createElement('div');
        item.className = 'sg-item';
        item.dataset.url  = media.url;
        item.dataset.name = name.toLowerCase();
        item.dataset.date = date.toLowerCase();
        item.dataset.type = type;
        item.dataset.mediaId = media.media_id || '';

        item.innerHTML =
            '<input type="checkbox" class="sg-item-check" onclick="handleCheck(event, this)" data-url="' + media.url + '" data-media-id="' + (media.media_id || '') + '">' +
            mediaHtml +
            '<div class="sg-item-footer">' +
                '<div class="sg-item-name" title="' + name + '">' + name + '</div>' +
                '<div class="sg-item-date">' + date + '</div>' +
                '<div class="sg-item-actions">' +
                    '<button class="sg-btn sg-btn-ghost sg-btn-sm" onclick="openLightbox(\'' + safeUrl + '\',\'' + type + '\')"><i class="fa fa-eye"></i></button>' +
                    coverBtn +
                    '<button class="sg-btn sg-btn-danger sg-btn-sm" onclick="deleteFile(\'' + safeUrl + '\', \'' + (media.media_id || '') + '\', this)"><i class="fa fa-trash-o"></i></button>' +
                '</div>' +
            '</div>';

        grid.appendChild(item);
    });
}


/* ── Search ── */
document.getElementById('sgSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    var items = document.querySelectorAll('#sgAlbumDetailView .sg-item');
    var shown = 0;
    items.forEach(function(item) {
        var match = !q || item.dataset.name.includes(q) || item.dataset.date.includes(q);
        var tabOk = SG.activeTab === 'all' || item.dataset.type === SG.activeTab.replace('s','');
        item.style.display = (match && tabOk) ? '' : 'none';
        if (match && tabOk) shown++;
    });
    document.getElementById('sgMediaCount').textContent = shown + ' item(s)';
});

/* ── Tab filter ── */
document.querySelectorAll('.sg-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.sg-tab').forEach(function(t) { t.classList.remove('active'); });
        this.classList.add('active');
        SG.activeTab = this.dataset.tab;
        applyTabFilter(SG.activeTab);
    });
});

function applyTabFilter(tab) {
    var imgSec = document.getElementById('sgImagesSection');
    var vidSec = document.getElementById('sgVideosSection');
    if (tab === 'all')    { imgSec.style.display = ''; vidSec.style.display = ''; }
    if (tab === 'images') { imgSec.style.display = ''; vidSec.style.display = 'none'; }
    if (tab === 'videos') { imgSec.style.display = 'none'; vidSec.style.display = ''; }
    updateMediaCount();
}

function updateMediaCount() {
    var total = SG.activeTab === 'images' ? SG.images.length
              : SG.activeTab === 'videos' ? SG.videos.length
              : SG.images.length + SG.videos.length;
    document.getElementById('sgMediaCount').textContent = total + ' item(s)';
}


/* ── Checkbox selection ── */
function handleCheck(e, cb) {
    e.stopPropagation();
    var item = cb.closest('.sg-item');
    if (cb.checked) item.classList.add('selected');
    else            item.classList.remove('selected');
    updateSelCount();
}

function updateSelCount() {
    var count = document.querySelectorAll('.sg-item-check:checked').length;
    var btn   = document.getElementById('sgDeleteSelBtn');
    var span  = document.getElementById('sgSelCount');
    span.textContent = count;
    btn.style.display = count > 0 ? 'inline-flex' : 'none';
}

function deleteSelectedFiles() {
    var cbs = document.querySelectorAll('.sg-item-check:checked');
    if (!cbs.length) return;
    if (!confirm('Delete ' + cbs.length + ' selected file(s)? This cannot be undone.')) return;

    var promises = Array.from(cbs).map(function(cb) {
        return deleteFilePromise(cb.dataset.url, cb.dataset.mediaId);
    });

    Promise.all(promises).then(function() {
        showToast('Selected files deleted.', 'success');
        if (SG.currentAlbum) fetchAlbumMedia(SG.currentAlbum.event_id);
        updateSelCount();
    });
}


/* ── Delete ── */
function deleteFile(fileUrl, mediaId, btnEl) {
    if (!confirm('Delete this file permanently?')) return;

    var item = btnEl.closest('.sg-item');
    btnEl.disabled = true;

    deleteFilePromise(fileUrl, mediaId).then(function(ok) {
        if (ok) {
            item.style.transition = 'all .3s';
            item.style.opacity = '0';
            item.style.transform = 'scale(.9)';
            setTimeout(function() {
                item.remove();
                SG.images = SG.images.filter(function(m) { return m.url !== fileUrl; });
                SG.videos = SG.videos.filter(function(m) { return m.url !== fileUrl; });
                updateMediaCount();
                updateSelCount();
            }, 300);
            showToast('File deleted.', 'success');
        }
    });
}

function deleteFilePromise(fileUrl, mediaId) {
    var eventId = SG.currentAlbum ? SG.currentAlbum.event_id : '';
    var qs = 'url=' + encodeURIComponent(fileUrl)
           + '&event_id=' + encodeURIComponent(eventId)
           + '&media_id=' + encodeURIComponent(mediaId || '');

    return fetch(BASE + '/schools/deleteMedia?' + qs, { method: 'DELETE' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status !== 'success') { showToast('Delete failed: ' + d.message, 'error'); return false; }
            return true;
        })
        .catch(function(e) { console.error('deleteFile:', e); showToast('Delete error.', 'error'); return false; });
}


/* ── Set cover image ── */
function setCoverImage(imageUrl, btnEl) {
    if (!SG.currentAlbum || SG.currentAlbum.event_id === '__legacy__') return;

    btnEl.disabled = true;
    var fd = new FormData();
    fd.append('event_id', SG.currentAlbum.event_id);
    fd.append('cover_url', imageUrl);
    fd.append(CSRF_NAME, CSRF_HASH);

    fetch(BASE + '/schools/setEventCover', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status === 'success') {
                showToast('Cover image updated!', 'success');
                // Update local state
                SG.currentAlbum.cover = imageUrl;
                var a = SG.albums.find(function(x) { return x.event_id === SG.currentAlbum.event_id; });
                if (a) a.cover = imageUrl;
            } else {
                showToast('Failed: ' + d.message, 'error');
            }
        })
        .catch(function(e) { showToast('Error setting cover.', 'error'); })
        .finally(function() { btnEl.disabled = false; });
}


/* ── Lightbox ── */
function openLightbox(url, type) {
    var lb    = document.getElementById('sgLightbox');
    var inner = document.getElementById('sgLightboxInner');
    var btnHtml = '<button class="sg-lightbox-close" onclick="closeLightbox()">&times;</button>';

    if (type === 'image') {
        inner.innerHTML = btnHtml + '<img src="' + url + '" alt="Preview">';
    } else {
        inner.innerHTML = btnHtml + '<video controls autoplay style="max-width:90vw;max-height:85vh;border-radius:8px;background:#000;">' +
            '<source src="' + url + '" type="video/mp4">Your browser does not support video.</video>';
    }

    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    var lb = document.getElementById('sgLightbox');
    lb.classList.remove('open');
    document.body.style.overflow = 'auto';
    setTimeout(function() { document.getElementById('sgLightboxInner').innerHTML = ''; }, 300);
}

document.getElementById('sgLightbox').addEventListener('click', function(e) {
    if (e.target === this) closeLightbox();
});


/* ── Drag & drop ── */
var dropZone = document.getElementById('sgDropZone');
dropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
dropZone.addEventListener('drop', function(e) {
    e.preventDefault(); this.classList.remove('drag-over');
    handleFiles(e.dataTransfer.files);
});

/* ── File input ── */
document.getElementById('sgFileInput').addEventListener('change', function(e) {
    handleFiles(e.target.files);
    this.value = '';
});

/* ── Upload ── */
function handleFiles(files) {
    if (!files.length) return;
    if (!SG.currentAlbum || SG.currentAlbum.event_id === '__legacy__') {
        showToast('Cannot upload to this album.', 'error');
        return;
    }

    var L = SG_LIMITS;
    var maxImgMB = L.max_image_size_mb || 3;
    var maxVidMB = L.max_video_size_mb || 25;

    var validFiles = [];
    Array.from(files).forEach(function(f) {
        var ext = f.name.split('.').pop().toLowerCase();
        var isImg = ['jpg','jpeg','png','webp'].includes(ext);
        var isVid = ['mp4','mov','avi','mkv','webm'].includes(ext);
        if (!isImg && !isVid) { showToast('Skipped ' + f.name + ' — unsupported format.', 'error'); return; }
        if (isImg && f.size > maxImgMB*1024*1024) { showToast(f.name + ' exceeds ' + maxImgMB + 'MB limit.', 'error'); return; }
        if (isVid && f.size > maxVidMB*1024*1024) { showToast(f.name + ' exceeds ' + maxVidMB + 'MB limit.', 'error'); return; }
        validFiles.push(f);
    });

    if (!validFiles.length) return;

    var progress = document.getElementById('sgUploadProgress');
    var bar      = document.getElementById('sgUploadBar');
    var statusEl = document.getElementById('sgUploadStatus');
    progress.style.display = 'block';
    statusEl.style.display = 'block';
    bar.style.width = '0%';

    var total = validFiles.length, done = 0, failed = 0;

    function next(i) {
        if (i >= total) {
            bar.style.width = '100%';
            statusEl.textContent = failed ? (total - failed) + ' uploaded, ' + failed + ' failed.' : total + ' file(s) uploaded!';
            statusEl.style.color = failed ? 'var(--sg-red)' : 'var(--sg-green)';
            if (!failed) showToast(total + ' file(s) uploaded!', 'success');
            setTimeout(function() {
                progress.style.display = 'none';
                statusEl.style.display = 'none';
                bar.style.width = '0%';
                fetchAlbumMedia(SG.currentAlbum.event_id);
                // Also refresh albums for updated counts
                fetch(BASE + '/schools/fetchGalleryAlbums').then(function(r) { return r.json(); }).then(function(d) {
                    SG.albums = d.albums || [];
                    updateStats();
                });
            }, 1800);
            return;
        }

        var file = validFiles[i];
        var type = file.type.startsWith('image') ? '1' : '2';
        var fd = new FormData();
        fd.append('file', file);
        fd.append('type', type);
        fd.append('event_id', SG.currentAlbum.event_id);
        fd.append(CSRF_NAME, CSRF_HASH);

        statusEl.textContent = 'Uploading ' + (i+1) + ' of ' + total + ': ' + file.name;
        statusEl.style.color = 'var(--sg-teal)';

        fetch(BASE + '/schools/uploadMedia', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.status !== 'success') { failed++; showToast('Failed: ' + file.name, 'error'); }
            })
            .catch(function() { failed++; })
            .finally(function() {
                done++;
                bar.style.width = Math.round((done / total) * 100) + '%';
                next(i + 1);
            });
    }

    next(0);
}


/* ── Init ── */
document.addEventListener('DOMContentLoaded', function() {
    fetchAlbums();
});
</script>

