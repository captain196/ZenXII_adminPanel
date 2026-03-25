<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ══════════════════════════════════════════════════════════════════
   Message Monitor — Premium SPA View
   ══════════════════════════════════════════════════════════════════ */
:root {
    --mm-primary: var(--gold);
    --mm-primary2: var(--gold2);
    --mm-primary-dim: var(--gold-dim);
    --mm-primary-ring: var(--gold-ring);
    --mm-primary-glow: var(--gold-glow);
    --mm-bg: var(--bg);
    --mm-bg2: var(--bg2);
    --mm-bg3: var(--bg3);
    --mm-card: var(--card);
    --mm-border: var(--border);
    --mm-brd2: var(--brd2);
    --mm-t1: var(--t1);
    --mm-t2: var(--t2);
    --mm-t3: var(--t3);
    --mm-t4: var(--t4);
    --mm-shadow: var(--sh);
    --mm-r: 12px;
    --mm-r-sm: 8px;
    --mm-green: #16a34a;
    --mm-green-dim: rgba(22,163,74,.10);
    --mm-red: #dc2626;
    --mm-red-dim: rgba(220,38,38,.10);
    --mm-amber: #d97706;
    --mm-amber-dim: rgba(217,119,6,.10);
    --mm-blue: #2563eb;
    --mm-blue-dim: rgba(37,99,235,.10);
    --mm-purple: #7c3aed;
    --mm-purple-dim: rgba(124,58,237,.10);
    --mm-rose: #e05c6f;
    --mm-rose-dim: rgba(224,92,111,.10);
    --mm-teal: #0f766e;
    --mm-teal-dim: rgba(15,118,110,.15);
    --mm-teal-bright: #14b8a6;
}

.mm-wrap { padding: 24px 28px 40px; font-family: var(--font-b, 'Plus Jakarta Sans', sans-serif); }

/* ── Page Header ────────────────────────────────────────────── */
.mm-page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
}
.mm-page-header h2 {
    margin: 0; font-size: 1.6rem; font-weight: 700; color: var(--mm-t1);
    display: flex; align-items: center; gap: 12px; font-family: var(--font-d);
}
.mm-page-header h2 i {
    color: var(--mm-primary); font-size: 1.3rem; width: 44px; height: 44px;
    display: flex; align-items: center; justify-content: center;
    background: var(--mm-primary-dim); border-radius: 10px;
}
.mm-page-sub {
    font-size: 0.85rem; color: var(--mm-t3); font-weight: 400;
    font-family: var(--font-b); margin-top: 4px;
}
.mm-breadcrumb {
    list-style: none; display: flex; gap: 6px; margin: 4px 0 0; padding: 0;
    font-size: 12px; color: var(--mm-t3); font-family: var(--font-b);
}
.mm-breadcrumb a { color: var(--mm-primary); text-decoration: none; }
.mm-breadcrumb li+li::before { content: ">"; margin-right: 6px; color: var(--mm-t4); }

/* ── Tabs ───────────────────────────────────────────────────── */
.mm-tabs {
    display: flex; gap: 4px; margin-bottom: 24px; flex-wrap: wrap;
    background: var(--mm-bg2); border-radius: 12px; padding: 6px;
    box-shadow: var(--mm-shadow); border: 1px solid var(--mm-border);
}
.mm-tab {
    padding: 11px 22px; cursor: pointer; font-weight: 600; color: var(--mm-t2);
    border-radius: 8px; transition: all .2s; font-size: 0.88rem; white-space: nowrap;
    display: flex; align-items: center; gap: 8px; text-decoration: none; border: none;
    background: transparent; font-family: var(--font-b);
}
.mm-tab i { font-size: 1rem; opacity: .7; }
.mm-tab:hover { color: var(--mm-primary); background: var(--mm-primary-dim); }
.mm-tab:hover i { opacity: 1; }
.mm-tab.active { color: #fff; background: var(--mm-primary); box-shadow: 0 2px 8px var(--mm-primary-glow); }
.mm-tab.active i { opacity: 1; }
.mm-tab .mm-tab-badge {
    font-size: 10px; padding: 2px 7px; border-radius: 10px;
    background: var(--mm-primary-dim); color: var(--mm-primary); font-family: var(--font-m);
}
.mm-tab.active .mm-tab-badge { background: rgba(255,255,255,.2); color: #fff; }

/* Panes */
.mm-pane { display: none; animation: mmFadeIn .25s ease; }
.mm-pane.active { display: block; }
@keyframes mmFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* ── Stat Cards ─────────────────────────────────────────────── */
.mm-stat-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px; margin-bottom: 24px;
}
.mm-stat {
    background: var(--mm-card, var(--mm-bg2)); border: 1px solid var(--mm-border);
    border-radius: var(--mm-r); padding: 20px; display: flex; align-items: center;
    gap: 16px; transition: transform .15s, box-shadow .15s;
}
.mm-stat:hover { transform: translateY(-2px); box-shadow: var(--mm-shadow); }
.mm-stat-icon {
    width: 48px; height: 48px; border-radius: 12px; display: flex;
    align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
}
.mm-stat-icon.teal   { background: var(--mm-teal-dim); color: var(--mm-teal-bright); }
.mm-stat-icon.blue   { background: var(--mm-blue-dim); color: var(--mm-blue); }
.mm-stat-icon.amber  { background: var(--mm-amber-dim); color: var(--mm-amber); }
.mm-stat-icon.rose   { background: var(--mm-red-dim); color: var(--mm-red); }
.mm-stat-icon.green  { background: var(--mm-green-dim); color: var(--mm-green); }
.mm-stat-icon.purple { background: var(--mm-purple-dim); color: var(--mm-purple); }
.mm-stat-val { font-size: 24px; font-weight: 700; color: var(--mm-t1); font-family: var(--font-b); line-height: 1; }
.mm-stat-lbl { font-size: 11px; color: var(--mm-t3); margin-top: 3px; font-family: var(--font-b); }

/* ── Cards ──────────────────────────────────────────────────── */
.mm-card {
    background: var(--mm-card, var(--mm-bg2)); border: 1px solid var(--mm-border);
    border-radius: var(--mm-r); padding: 20px; margin-bottom: 18px;
    box-shadow: var(--mm-shadow); transition: box-shadow .2s;
}
.mm-card-title {
    font-family: var(--font-b); font-size: 14px; font-weight: 700; color: var(--mm-t1);
    margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;
    gap: 10px; flex-wrap: wrap;
}
.mm-card-title i { color: var(--mm-primary); margin-right: 6px; }

/* ── Buttons ────────────────────────────────────────────────── */
.mm-btn {
    padding: 8px 16px; border-radius: var(--mm-r-sm); font-size: 12.5px;
    font-weight: 600; cursor: pointer; border: none; transition: all .15s;
    font-family: var(--font-b); display: inline-flex; align-items: center; gap: 6px;
}
.mm-btn-primary { background: var(--mm-primary); color: #fff; }
.mm-btn-primary:hover { background: var(--mm-primary2); }
.mm-btn-danger { background: var(--mm-red); color: #fff; }
.mm-btn-danger:hover { background: #b91c1c; }
.mm-btn-outline { background: transparent; border: 1px solid var(--mm-primary); color: var(--mm-primary); }
.mm-btn-outline:hover { background: var(--mm-primary-dim); }
.mm-btn-sm { padding: 5px 10px; font-size: 11px; }
.mm-btn-gray { background: var(--mm-bg3); color: var(--mm-t2); border: 1px solid var(--mm-border); }
.mm-btn-gray:hover { border-color: var(--mm-primary-ring); }
.mm-btn[disabled] { opacity: .5; cursor: not-allowed; }

/* ── Badges ─────────────────────────────────────────────────── */
.mm-badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 10.5px; font-weight: 600; letter-spacing: .3px; font-family: var(--font-b);
}
.mm-badge-teal   { background: var(--mm-teal-dim); color: var(--mm-teal-bright); }
.mm-badge-green  { background: var(--mm-green-dim); color: var(--mm-green); }
.mm-badge-blue   { background: var(--mm-blue-dim); color: var(--mm-blue); }
.mm-badge-amber  { background: var(--mm-amber-dim); color: var(--mm-amber); }
.mm-badge-red    { background: var(--mm-red-dim); color: var(--mm-red); }
.mm-badge-purple { background: var(--mm-purple-dim); color: var(--mm-purple); }
.mm-badge-gray   { background: rgba(107,114,128,.12); color: #9ca3af; }

/* ── Tables ─────────────────────────────────────────────────── */
.mm-table-wrap { overflow-x: auto; border: 1px solid var(--mm-border); border-radius: var(--mm-r-sm); }
.mm-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.mm-table th {
    padding: 10px 14px; background: var(--mm-bg3); color: var(--mm-t3);
    font-size: 10.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .4px; border-bottom: 2px solid var(--mm-border);
    text-align: left; position: sticky; top: 0; z-index: 1;
}
.mm-table td { padding: 10px 14px; border-bottom: 1px solid var(--mm-border); color: var(--mm-t1); }
.mm-table tr:hover td { background: var(--mm-primary-dim); }

/* ── Form Controls ──────────────────────────────────────────── */
.mm-fg { display: flex; flex-direction: column; gap: 4px; }
.mm-fg label {
    font-size: 10px; font-weight: 700; color: var(--mm-t3);
    text-transform: uppercase; letter-spacing: .4px; font-family: var(--font-m);
}
.mm-fg input, .mm-fg select, .mm-fg textarea {
    padding: 8px 12px; border: 1px solid var(--mm-border); border-radius: 6px;
    background: var(--mm-bg); color: var(--mm-t1); font-size: 12.5px;
    font-family: var(--font-b); transition: border-color .15s;
}
.mm-fg input:focus, .mm-fg select:focus, .mm-fg textarea:focus {
    border-color: var(--mm-primary); outline: none;
    box-shadow: 0 0 0 3px var(--mm-primary-ring);
}

/* ── Toast ──────────────────────────────────────────────────── */
.mm-toast {
    position: fixed; top: 20px; right: 20px; z-index: 10000;
    padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 600;
    font-family: var(--font-b); color: #fff; display: none; max-width: 400px;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
    transition: opacity .3s, transform .3s;
}
.mm-toast.show { display: block; animation: mmToastIn .3s ease; }
.mm-toast.success { background: var(--mm-green); }
.mm-toast.error { background: var(--mm-red); }
@keyframes mmToastIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

/* ── Empty / Loading ────────────────────────────────────────── */
.mm-empty {
    text-align: center; padding: 40px 20px; color: var(--mm-t3); font-family: var(--font-b);
}
.mm-empty i { font-size: 36px; display: block; margin-bottom: 12px; opacity: .5; }
.mm-loading {
    text-align: center; padding: 30px; color: var(--mm-t3); font-family: var(--font-b);
}
.mm-loading i { margin-right: 6px; }

/* ── Grid Helpers ───────────────────────────────────────────── */
.mm-row { display: grid; gap: 18px; }
.mm-row-2 { grid-template-columns: 1fr 1fr; }
.mm-row-3 { grid-template-columns: 1fr 1fr 1fr; }
@media (max-width: 768px) {
    .mm-row-2, .mm-row-3 { grid-template-columns: 1fr; }
}

/* ══════════════════════════════════════════════════════════════
   TAB 2 — CONVERSATIONS SPLIT LAYOUT
   ══════════════════════════════════════════════════════════════ */
.mm-conv-split {
    display: grid; grid-template-columns: 40% 60%; gap: 0;
    border: 1px solid var(--mm-border); border-radius: var(--mm-r);
    overflow: hidden; height: 640px; box-shadow: var(--mm-shadow);
}

/* Left Panel — Conversation List */
.mm-conv-left {
    background: var(--mm-bg2); border-right: 1px solid var(--mm-border);
    display: flex; flex-direction: column; overflow: hidden;
}
.mm-conv-search {
    padding: 14px; border-bottom: 1px solid var(--mm-border);
    display: flex; flex-direction: column; gap: 10px; flex-shrink: 0;
}
.mm-conv-search input, .mm-conv-search select {
    width: 100%; padding: 8px 12px; border: 1px solid var(--mm-border);
    border-radius: 6px; background: var(--mm-bg); color: var(--mm-t1);
    font-size: 12px; font-family: var(--font-b);
}
.mm-conv-search input:focus, .mm-conv-search select:focus {
    border-color: var(--mm-primary); outline: none;
}
.mm-conv-list {
    flex: 1; overflow-y: auto; overflow-x: hidden;
}
.mm-conv-list::-webkit-scrollbar { width: 5px; }
.mm-conv-list::-webkit-scrollbar-track { background: transparent; }
.mm-conv-list::-webkit-scrollbar-thumb { background: var(--mm-brd2); border-radius: 3px; }
.mm-conv-item {
    padding: 14px 16px; border-bottom: 1px solid var(--mm-border);
    cursor: pointer; transition: background .15s;
}
.mm-conv-item:hover { background: var(--mm-primary-dim); }
.mm-conv-item.active { background: var(--mm-teal-dim); border-left: 3px solid var(--mm-primary); }
.mm-conv-item-header {
    display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;
}
.mm-conv-teacher {
    font-size: 13px; font-weight: 700; color: var(--mm-t1); font-family: var(--font-b);
}
.mm-conv-time {
    font-size: 10px; color: var(--mm-t4); font-family: var(--font-m);
}
.mm-conv-parent {
    font-size: 11px; color: var(--mm-t3); margin-bottom: 3px;
}
.mm-conv-student {
    font-size: 10.5px; color: var(--mm-teal-bright); margin-bottom: 4px;
    font-family: var(--font-m);
}
.mm-conv-preview {
    font-size: 11.5px; color: var(--mm-t3); white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis; max-width: 100%;
}
.mm-conv-unread {
    display: inline-block; background: var(--mm-primary); color: #fff;
    font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px;
    min-width: 18px; text-align: center; font-family: var(--font-b);
}

/* Right Panel — Chat Thread */
.mm-conv-right {
    background: var(--mm-bg); display: flex; flex-direction: column; overflow: hidden;
}
.mm-chat-header {
    padding: 16px 20px; border-bottom: 1px solid var(--mm-border);
    background: var(--mm-bg2); flex-shrink: 0; display: flex;
    align-items: center; justify-content: space-between; gap: 12px;
}
.mm-chat-header-info { flex: 1; }
.mm-chat-header-names {
    font-size: 14px; font-weight: 700; color: var(--mm-t1); font-family: var(--font-b);
}
.mm-chat-header-names .mm-arrow { color: var(--mm-primary); margin: 0 8px; }
.mm-chat-header-meta {
    font-size: 11px; color: var(--mm-t3); margin-top: 3px;
}
.mm-chat-messages {
    flex: 1; overflow-y: auto; padding: 20px; display: flex;
    flex-direction: column; gap: 14px;
}
.mm-chat-messages::-webkit-scrollbar { width: 5px; }
.mm-chat-messages::-webkit-scrollbar-track { background: transparent; }
.mm-chat-messages::-webkit-scrollbar-thumb { background: var(--mm-brd2); border-radius: 3px; }

/* Chat Bubbles */
.mm-bubble-row { display: flex; flex-direction: column; max-width: 75%; }
.mm-bubble-row.teacher { align-self: flex-end; align-items: flex-end; }
.mm-bubble-row.parent { align-self: flex-start; align-items: flex-start; }
.mm-bubble-sender {
    font-size: 10px; font-weight: 600; margin-bottom: 3px; padding: 0 8px;
    font-family: var(--font-m); text-transform: uppercase; letter-spacing: .3px;
}
.mm-bubble-row.teacher .mm-bubble-sender { color: var(--mm-teal-bright); }
.mm-bubble-row.parent .mm-bubble-sender { color: var(--mm-t3); }
.mm-bubble {
    padding: 10px 14px; border-radius: 14px; font-size: 13px; line-height: 1.5;
    word-break: break-word; position: relative;
}
.mm-bubble-row.teacher .mm-bubble {
    background: var(--mm-teal-dim); color: var(--mm-t1);
    border-bottom-right-radius: 4px;
    border: 1px solid rgba(15,118,110,.2);
}
.mm-bubble-row.parent .mm-bubble {
    background: var(--mm-bg2); color: var(--mm-t1);
    border-bottom-left-radius: 4px;
    border: 1px solid var(--mm-border);
}
.mm-bubble-time {
    font-size: 10px; color: var(--mm-t4); margin-top: 3px; padding: 0 8px;
    font-family: var(--font-m);
}
.mm-bubble-deleted {
    font-style: italic; opacity: .5;
}

/* Chat Admin Bar */
.mm-chat-actions {
    padding: 12px 20px; border-top: 1px solid var(--mm-border);
    background: var(--mm-bg2); flex-shrink: 0;
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
}

/* Empty chat state */
.mm-chat-empty {
    flex: 1; display: flex; flex-direction: column; align-items: center;
    justify-content: center; color: var(--mm-t4); text-align: center; padding: 40px;
}
.mm-chat-empty i { font-size: 48px; margin-bottom: 16px; opacity: .3; }
.mm-chat-empty p { font-size: 14px; margin: 0; }

@media (max-width: 900px) {
    .mm-conv-split { grid-template-columns: 1fr; height: auto; }
    .mm-conv-left { max-height: 350px; }
}

/* ══════════════════════════════════════════════════════════════
   TAB 3 — SEARCH
   ══════════════════════════════════════════════════════════════ */
.mm-search-bar {
    display: flex; gap: 10px; margin-bottom: 20px;
}
.mm-search-bar input {
    flex: 1; padding: 10px 14px; border: 1px solid var(--mm-border);
    border-radius: var(--mm-r-sm); background: var(--mm-bg2); color: var(--mm-t1);
    font-size: 13px; font-family: var(--font-b);
}
.mm-search-bar input:focus {
    border-color: var(--mm-primary); outline: none;
    box-shadow: 0 0 0 3px var(--mm-primary-ring);
}
.mm-search-result {
    background: var(--mm-card); border: 1px solid var(--mm-border);
    border-radius: var(--mm-r-sm); padding: 16px; margin-bottom: 12px;
    cursor: pointer; transition: border-color .15s, transform .15s;
}
.mm-search-result:hover {
    border-color: var(--mm-primary); transform: translateY(-1px);
}
.mm-search-result-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px; font-size: 12px;
}
.mm-search-context {
    font-weight: 600; color: var(--mm-t2); font-family: var(--font-b);
}
.mm-search-time { color: var(--mm-t4); font-family: var(--font-m); }
.mm-search-text {
    font-size: 13px; color: var(--mm-t1); line-height: 1.5;
}
.mm-search-text mark {
    background: rgba(15,118,110,.3); color: var(--mm-teal-bright);
    padding: 1px 3px; border-radius: 3px;
}

/* ══════════════════════════════════════════════════════════════
   TAB 4 — ANALYTICS
   ══════════════════════════════════════════════════════════════ */
.mm-chart-container {
    position: relative; height: 280px; width: 100%;
}
.mm-chart-container-sm {
    position: relative; height: 240px; width: 100%;
}

/* ══════════════════════════════════════════════════════════════
   TAB 5 — MODERATION
   ══════════════════════════════════════════════════════════════ */
.mm-kw-input-row {
    display: flex; gap: 10px; align-items: center; margin-bottom: 14px;
}
.mm-kw-input-row input {
    flex: 1; padding: 8px 12px; border: 1px solid var(--mm-border);
    border-radius: 6px; background: var(--mm-bg); color: var(--mm-t1);
    font-size: 12.5px; font-family: var(--font-b);
}
.mm-kw-input-row input:focus {
    border-color: var(--mm-primary); outline: none;
}
.mm-kw-tags {
    display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; min-height: 32px;
}
.mm-kw-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--mm-red-dim); color: var(--mm-red);
    padding: 5px 12px; border-radius: 20px; font-size: 12px;
    font-weight: 600; font-family: var(--font-b); transition: background .15s;
}
.mm-kw-tag .mm-kw-remove {
    cursor: pointer; width: 16px; height: 16px; border-radius: 50%;
    background: rgba(220,38,38,.2); display: flex; align-items: center;
    justify-content: center; font-size: 10px; transition: background .15s;
}
.mm-kw-tag .mm-kw-remove:hover { background: rgba(220,38,38,.4); }

/* ── Recent Messages Feed ───────────────────────────────────── */
.mm-feed-item {
    display: flex; align-items: flex-start; gap: 12px; padding: 10px 0;
    border-bottom: 1px solid var(--mm-border); transition: background .1s;
}
.mm-feed-item:last-child { border-bottom: none; }
.mm-feed-avatar {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff;
}
.mm-feed-avatar.teacher { background: var(--mm-teal); }
.mm-feed-avatar.parent { background: var(--mm-purple); }
.mm-feed-body { flex: 1; min-width: 0; }
.mm-feed-sender {
    font-size: 12px; font-weight: 700; color: var(--mm-t1); font-family: var(--font-b);
}
.mm-feed-sender span { font-weight: 400; color: var(--mm-t4); margin-left: 6px; }
.mm-feed-text {
    font-size: 12px; color: var(--mm-t2); white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis; margin-top: 2px;
}
.mm-feed-time {
    font-size: 10px; color: var(--mm-t4); white-space: nowrap; flex-shrink: 0;
    padding-top: 2px; font-family: var(--font-m);
}

/* ── Leaderboard ranking ────────────────────────────────────── */
.mm-rank-list { list-style: none; padding: 0; margin: 0; }
.mm-rank-item {
    display: flex; align-items: center; gap: 12px; padding: 10px 0;
    border-bottom: 1px solid var(--mm-border);
}
.mm-rank-item:last-child { border-bottom: none; }
.mm-rank-pos {
    width: 26px; height: 26px; border-radius: 50%; display: flex;
    align-items: center; justify-content: center; font-size: 11px;
    font-weight: 700; background: var(--mm-primary-dim); color: var(--mm-primary);
    flex-shrink: 0; font-family: var(--font-b);
}
.mm-rank-info { flex: 1; }
.mm-rank-name { font-size: 13px; font-weight: 600; color: var(--mm-t1); }
.mm-rank-sub { font-size: 11px; color: var(--mm-t3); }
.mm-rank-stat { text-align: right; }
.mm-rank-val { font-size: 16px; font-weight: 700; color: var(--mm-t1); font-family: var(--font-b); }
.mm-rank-lbl { font-size: 10px; color: var(--mm-t4); }

/* ── Status dot ─────────────────────────────────────────────── */
.mm-dot {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px;
}
.mm-dot-green { background: var(--mm-green); box-shadow: 0 0 6px rgba(22,163,74,.4); }
.mm-dot-red   { background: var(--mm-red); box-shadow: 0 0 6px rgba(220,38,38,.4); }
.mm-dot-amber { background: var(--mm-amber); box-shadow: 0 0 6px rgba(217,119,6,.4); }
.mm-dot-teal  { background: var(--mm-teal-bright); box-shadow: 0 0 6px rgba(20,184,166,.4); }

/* ── DataTables overrides ───────────────────────────────────── */
.mm-card .dataTables_wrapper { font-size: 12px; }
.mm-card .dataTables_wrapper .dataTables_filter input {
    border: 1px solid var(--mm-border); border-radius: 6px; padding: 5px 10px;
    background: var(--mm-bg); color: var(--mm-t1); font-family: var(--font-b);
}
.mm-card .dataTables_wrapper .dataTables_length select {
    border: 1px solid var(--mm-border); border-radius: 4px;
    background: var(--mm-bg); color: var(--mm-t1);
}
.mm-card .dataTables_wrapper .dataTables_info,
.mm-card .dataTables_wrapper .dataTables_paginate { color: var(--mm-t3); font-size: 12px; margin-top: 10px; }
.mm-card .dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 4px 10px; border-radius: 4px; border: 1px solid var(--mm-border) !important;
    background: var(--mm-bg2) !important; color: var(--mm-t2) !important; margin: 0 2px;
}
.mm-card .dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: var(--mm-primary) !important; color: #fff !important; border-color: var(--mm-primary) !important;
}
</style>

<!-- ══════════════════════════════════════════════════════════════
     HTML STRUCTURE
     ══════════════════════════════════════════════════════════════ -->
<div class="content-wrapper"><section class="content"><div class="mm-wrap">

<!-- Page Header -->
<div class="mm-page-header">
    <div>
        <h2><i class="fa fa-eye"></i> Message Monitor</h2>
        <ol class="mm-breadcrumb">
            <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
            <li>Message Monitor</li>
        </ol>
    </div>
    <div style="display:flex;gap:8px;">
        <button class="mm-btn mm-btn-gray mm-btn-sm" onclick="MM.refresh()"><i class="fa fa-refresh"></i> Refresh</button>
    </div>
</div>

<!-- Tabs -->
<div class="mm-tabs">
    <div class="mm-tab active" data-tab="dashboard"><i class="fa fa-dashboard"></i> Dashboard</div>
    <div class="mm-tab" data-tab="conversations"><i class="fa fa-comments"></i> Conversations</div>
    <div class="mm-tab" data-tab="search"><i class="fa fa-search"></i> Search</div>
    <div class="mm-tab" data-tab="analytics"><i class="fa fa-bar-chart"></i> Analytics</div>
    <div class="mm-tab" data-tab="moderation"><i class="fa fa-shield"></i> Moderation</div>
</div>


<!-- ════════════════════════════════════════════════════════════
     TAB 1 — DASHBOARD
     ════════════════════════════════════════════════════════════ -->
<div class="mm-pane active" id="pane-dashboard">

    <!-- KPI Stats -->
    <div class="mm-stat-grid">
        <div class="mm-stat">
            <div class="mm-stat-icon teal"><i class="fa fa-comments"></i></div>
            <div><div class="mm-stat-val" id="kpi-conversations">--</div><div class="mm-stat-lbl">Total Conversations</div></div>
        </div>
        <div class="mm-stat">
            <div class="mm-stat-icon blue"><i class="fa fa-envelope"></i></div>
            <div><div class="mm-stat-val" id="kpi-messages">--</div><div class="mm-stat-lbl">Total Messages</div></div>
        </div>
        <div class="mm-stat">
            <div class="mm-stat-icon green"><i class="fa fa-bolt"></i></div>
            <div><div class="mm-stat-val" id="kpi-active-today">--</div><div class="mm-stat-lbl">Active Today</div></div>
        </div>
        <div class="mm-stat">
            <div class="mm-stat-icon amber"><i class="fa fa-clock-o"></i></div>
            <div><div class="mm-stat-val" id="kpi-avg-response">--</div><div class="mm-stat-lbl">Avg Response Time</div></div>
        </div>
    </div>

    <!-- Message Volume Chart -->
    <div class="mm-card">
        <div class="mm-card-title"><span><i class="fa fa-line-chart"></i> Message Volume (Last 30 Days)</span></div>
        <div class="mm-chart-container"><canvas id="chartDashVolume"></canvas></div>
    </div>

    <!-- Top Teachers / Top Parents / Recent Feed -->
    <div class="mm-row mm-row-3">
        <div class="mm-card">
            <div class="mm-card-title"><span><i class="fa fa-user"></i> Top Teachers</span></div>
            <ul class="mm-rank-list" id="topTeachersList">
                <li class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</li>
            </ul>
        </div>
        <div class="mm-card">
            <div class="mm-card-title"><span><i class="fa fa-users"></i> Top Parents</span></div>
            <ul class="mm-rank-list" id="topParentsList">
                <li class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</li>
            </ul>
        </div>
        <div class="mm-card" style="grid-column:span 1;">
            <div class="mm-card-title"><span><i class="fa fa-feed"></i> Recent Messages</span></div>
            <div id="recentFeed" style="max-height:340px;overflow-y:auto;">
                <div class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     TAB 2 — CONVERSATIONS
     ════════════════════════════════════════════════════════════ -->
<div class="mm-pane" id="pane-conversations">

    <div class="mm-conv-split">
        <!-- Left: Conversation List -->
        <div class="mm-conv-left">
            <div class="mm-conv-search">
                <input type="text" id="convSearchInput" placeholder="Search conversations...">
                <select id="convTeacherFilter">
                    <option value="">All Teachers</option>
                </select>
            </div>
            <div class="mm-conv-list" id="convList">
                <div class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading conversations...</div>
            </div>
        </div>

        <!-- Right: Chat Thread -->
        <div class="mm-conv-right" id="chatPanel">
            <div class="mm-chat-empty" id="chatEmpty">
                <i class="fa fa-comments-o"></i>
                <p>Select a conversation to view messages</p>
            </div>
            <div id="chatContent" style="display:none;flex:1;display:none;flex-direction:column;height:100%;">
                <div class="mm-chat-header" id="chatHeader">
                    <div class="mm-chat-header-info">
                        <div class="mm-chat-header-names">
                            <span id="chatTeacherName">--</span>
                            <span class="mm-arrow"><i class="fa fa-exchange"></i></span>
                            <span id="chatParentName">--</span>
                        </div>
                        <div class="mm-chat-header-meta" id="chatMeta">--</div>
                    </div>
                    <button class="mm-btn mm-btn-outline mm-btn-sm" onclick="MM.exportConversation()" title="Export conversation">
                        <i class="fa fa-download"></i> Export
                    </button>
                </div>
                <div class="mm-chat-messages" id="chatMessages"></div>
                <div class="mm-chat-actions" id="chatActions">
                    <div style="font-size:11px;color:var(--mm-t4);">
                        <span id="chatMsgCount">0</span> messages in this conversation
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button class="mm-btn mm-btn-danger mm-btn-sm" id="btnDeleteMsg" disabled onclick="MM.deleteSelectedMessage()">
                            <i class="fa fa-trash"></i> Delete Selected
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     TAB 3 — SEARCH
     ════════════════════════════════════════════════════════════ -->
<div class="mm-pane" id="pane-search">

    <div class="mm-card">
        <div class="mm-card-title"><span><i class="fa fa-search"></i> Search Messages</span></div>
        <div class="mm-search-bar">
            <input type="text" id="searchInput" placeholder="Search by keyword, sender name, or message content...">
            <button class="mm-btn mm-btn-primary" onclick="MM.searchMessages()"><i class="fa fa-search"></i> Search</button>
        </div>
        <div id="searchCount" style="font-size:12px;color:var(--mm-t3);margin-bottom:14px;display:none;"></div>
        <div id="searchResults">
            <div class="mm-empty"><i class="fa fa-search"></i><p>Enter a keyword to search across all messages.</p></div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     TAB 4 — ANALYTICS
     ════════════════════════════════════════════════════════════ -->
<div class="mm-pane" id="pane-analytics">

    <div class="mm-row mm-row-2">
        <div class="mm-card">
            <div class="mm-card-title"><span><i class="fa fa-line-chart"></i> Daily Message Volume</span></div>
            <div class="mm-chart-container"><canvas id="chartDailyVolume"></canvas></div>
        </div>
        <div class="mm-card">
            <div class="mm-card-title"><span><i class="fa fa-bar-chart"></i> Hourly Distribution (Last 7 Days)</span></div>
            <div class="mm-chart-container"><canvas id="chartHourly"></canvas></div>
        </div>
    </div>

    <div class="mm-card">
        <div class="mm-card-title"><span><i class="fa fa-trophy"></i> Teacher Communication Leaderboard</span></div>
        <div class="mm-table-wrap">
            <table class="mm-table" id="teacherLeaderboard" style="width:100%;">
                <thead><tr>
                    <th>#</th>
                    <th>Teacher</th>
                    <th>Conversations</th>
                    <th>Messages Sent</th>
                    <th>Avg Response Time</th>
                    <th>Activity</th>
                </tr></thead>
                <tbody id="teacherLbBody">
                    <tr><td colspan="6" class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mm-row mm-row-2">
        <div class="mm-card">
            <div class="mm-card-title"><span><i class="fa fa-pie-chart"></i> Class-wise Communication</span></div>
            <div class="mm-chart-container-sm"><canvas id="chartClasswise"></canvas></div>
        </div>
        <div class="mm-card">
            <div class="mm-card-title"><span><i class="fa fa-clock-o"></i> Activity Timeline</span></div>
            <div id="activityTimeline" style="max-height:240px;overflow-y:auto;">
                <div class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     TAB 5 — MODERATION
     ════════════════════════════════════════════════════════════ -->
<div class="mm-pane" id="pane-moderation">

    <!-- Keyword Management -->
    <div class="mm-card">
        <div class="mm-card-title"><span><i class="fa fa-exclamation-triangle"></i> Flagged Keyword Management</span></div>
        <div class="mm-kw-input-row">
            <input type="text" id="kwInput" placeholder="Enter a keyword to flag...">
            <button class="mm-btn mm-btn-primary mm-btn-sm" onclick="MM.addKeyword()"><i class="fa fa-plus"></i> Add</button>
        </div>
        <div class="mm-kw-tags" id="kwTags">
            <!-- Keywords rendered here -->
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="mm-btn mm-btn-primary" onclick="MM.saveKeywords()"><i class="fa fa-save"></i> Save Keywords</button>
        </div>
    </div>

    <!-- Flagged Messages Table -->
    <div class="mm-card">
        <div class="mm-card-title">
            <span><i class="fa fa-flag"></i> Flagged Messages</span>
            <button class="mm-btn mm-btn-outline mm-btn-sm" onclick="MM.loadFlagged()"><i class="fa fa-refresh"></i> Refresh</button>
        </div>
        <div class="mm-table-wrap">
            <table class="mm-table" id="flaggedTable" style="width:100%;">
                <thead><tr>
                    <th>Keyword</th>
                    <th>Message</th>
                    <th>Sender</th>
                    <th>Conversation</th>
                    <th>Time</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody id="flaggedBody">
                    <tr><td colspan="6" class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading flagged messages...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div></section></div>

<!-- Toast -->
<div class="mm-toast" id="mmToast"></div>


<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════════ -->
<script>
(function(){
'use strict';

/* ── Globals ──────────────────────────────────────────────── */
var BASE = '<?= base_url() ?>';
var CSRF = {
    name:  document.querySelector('meta[name=csrf-name]') ? document.querySelector('meta[name=csrf-name]').getAttribute('content') : '',
    token: document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').getAttribute('content') : ''
};

function ajaxPost(url, data, cb) {
    data[CSRF.name] = CSRF.token;
    $.ajax({
        url: BASE + url, type: 'POST', data: data, dataType: 'json',
        success: function(r) {
            if (r.csrf_token) CSRF.token = r.csrf_token;
            cb(r);
        },
        error: function(x) {
            if (x.responseJSON && x.responseJSON.csrf_token) CSRF.token = x.responseJSON.csrf_token;
            cb({ status: 'error', message: x.responseJSON ? x.responseJSON.message : 'Request failed' });
        }
    });
}

function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
function $1(sel) { return document.querySelector(sel); }
function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
function setHtml(id, v) { var el = document.getElementById(id); if (el) el.innerHTML = v; }

/* ── Module Namespace ─────────────────────────────────────── */
window.MM = {};

/* ── Toast ────────────────────────────────────────────────── */
MM.toast = function(msg, type) {
    var t = document.getElementById('mmToast');
    t.textContent = msg;
    t.className = 'mm-toast show ' + (type || 'success');
    clearTimeout(MM._toastTimer);
    MM._toastTimer = setTimeout(function() { t.className = 'mm-toast'; }, 3500);
};

/* ── Utility: Time Ago ────────────────────────────────────── */
MM.timeAgo = function(dateStr) {
    if (!dateStr) return '--';
    var now = new Date();
    var then = new Date(dateStr);
    if (isNaN(then.getTime())) return dateStr;
    var diff = Math.floor((now - then) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return then.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
};

MM.formatTs = function(ts) {
    if (!ts) return '--';
    var d = new Date(ts);
    if (isNaN(d.getTime())) return ts;
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' +
           d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
};

/* ── Tab Switching ────────────────────────────────────────── */
MM.activeTab = 'dashboard';
MM.tabLoaded = {};

MM.switchTab = function(tab) {
    if (MM.activeTab === tab) return;
    MM.activeTab = tab;

    // Update tab buttons
    document.querySelectorAll('.mm-tab').forEach(function(t) {
        t.classList.toggle('active', t.getAttribute('data-tab') === tab);
    });

    // Update panes
    document.querySelectorAll('.mm-pane').forEach(function(p) {
        p.classList.toggle('active', p.id === 'pane-' + tab);
    });

    // Lazy load tab data
    if (!MM.tabLoaded[tab]) {
        MM.tabLoaded[tab] = true;
        switch (tab) {
            case 'conversations': MM.loadConversations(); break;
            case 'search': document.getElementById('searchInput').focus(); break;
            case 'analytics': MM.loadAnalytics(); break;
            case 'moderation': MM.loadModeration(); break;
        }
    }
};

document.querySelectorAll('.mm-tab').forEach(function(t) {
    t.addEventListener('click', function() {
        MM.switchTab(this.getAttribute('data-tab'));
    });
});


/* ══════════════════════════════════════════════════════════════
   DASHBOARD
   ══════════════════════════════════════════════════════════════ */
MM.dashChart = null;

MM.loadDashboard = function() {
    ajaxPost('message_monitor/get_analytics', {}, function(r) {
        if (r.status !== 'success') {
            MM.toast(r.message || 'Failed to load dashboard', 'error');
            return;
        }
        var d = r.data || {};

        // KPIs
        setText('kpi-conversations', (d.total_conversations || 0).toLocaleString('en-IN'));
        setText('kpi-messages', (d.total_messages || 0).toLocaleString('en-IN'));
        setText('kpi-active-today', (d.active_today || 0).toLocaleString('en-IN'));
        setText('kpi-avg-response', d.avg_response_time || '--');

        // Volume chart
        MM.renderDashVolumeChart(d.daily_volume || []);

        // Top teachers
        MM.renderRankList('topTeachersList', d.top_teachers || [], 'teacher');

        // Top parents
        MM.renderRankList('topParentsList', d.top_parents || [], 'parent');

        // Recent feed
        MM.renderRecentFeed(d.recent_messages || []);
    });
};

MM.renderDashVolumeChart = function(data) {
    var ctx = document.getElementById('chartDashVolume');
    if (!ctx) return;
    if (MM.dashChart) MM.dashChart.destroy();

    var labels = data.map(function(d) { return d.date || d.label; });
    var values = data.map(function(d) { return d.count || d.value || 0; });

    MM.dashChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Messages',
                data: values,
                borderColor: '#14b8a6',
                backgroundColor: 'rgba(20,184,166,.08)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointBackgroundColor: '#14b8a6',
                pointBorderColor: '#fff',
                pointBorderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(7,15,28,.95)',
                    titleFont: { size: 12 },
                    bodyFont: { size: 12 },
                    padding: 10,
                    cornerRadius: 8,
                    borderColor: 'rgba(15,118,110,.3)',
                    borderWidth: 1
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(15,118,110,.06)' },
                    ticks: { color: '#5a9e98', font: { size: 10 }, maxTicksLimit: 10 }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(15,118,110,.06)' },
                    ticks: { color: '#5a9e98', font: { size: 10 } }
                }
            }
        }
    });
};

MM.renderRankList = function(elId, items, type) {
    var el = document.getElementById(elId);
    if (!el) return;

    if (!items.length) {
        el.innerHTML = '<li class="mm-empty"><i class="fa fa-inbox"></i>No data available</li>';
        return;
    }

    var html = '';
    items.slice(0, 5).forEach(function(item, idx) {
        html += '<li class="mm-rank-item">'
            + '<div class="mm-rank-pos">' + (idx + 1) + '</div>'
            + '<div class="mm-rank-info">'
            +   '<div class="mm-rank-name">' + esc(item.name) + '</div>'
            +   '<div class="mm-rank-sub">' + esc(item.conversations || item.conv_count || '0') + ' conversations</div>'
            + '</div>'
            + '<div class="mm-rank-stat">'
            +   '<div class="mm-rank-val">' + (item.messages || item.msg_count || 0) + '</div>'
            +   '<div class="mm-rank-lbl">messages</div>'
            + '</div>'
            + '</li>';
    });
    el.innerHTML = html;
};

MM.renderRecentFeed = function(messages) {
    var el = document.getElementById('recentFeed');
    if (!el) return;

    if (!messages.length) {
        el.innerHTML = '<div class="mm-empty"><i class="fa fa-inbox"></i><p>No recent messages.</p></div>';
        return;
    }

    var html = '';
    messages.slice(0, 20).forEach(function(msg) {
        var isTeacher = (msg.sender_type || '').toLowerCase() === 'teacher';
        var avatarClass = isTeacher ? 'teacher' : 'parent';
        var initial = (msg.sender_name || '?').charAt(0).toUpperCase();

        html += '<div class="mm-feed-item">'
            + '<div class="mm-feed-avatar ' + avatarClass + '">' + esc(initial) + '</div>'
            + '<div class="mm-feed-body">'
            +   '<div class="mm-feed-sender">' + esc(msg.sender_name || 'Unknown')
            +     '<span>' + esc(msg.sender_type || '') + '</span></div>'
            +   '<div class="mm-feed-text">' + esc(msg.message || msg.text || '') + '</div>'
            + '</div>'
            + '<div class="mm-feed-time">' + MM.timeAgo(msg.created_at || msg.time) + '</div>'
            + '</div>';
    });
    el.innerHTML = html;
};


/* ══════════════════════════════════════════════════════════════
   CONVERSATIONS
   ══════════════════════════════════════════════════════════════ */
MM.conversations = [];
MM.activeConvId = null;
MM.selectedMsgId = null;

MM.loadConversations = function() {
    setHtml('convList', '<div class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading conversations...</div>');

    ajaxPost('message_monitor/get_conversations', {}, function(r) {
        if (r.status !== 'success') {
            setHtml('convList', '<div class="mm-empty"><i class="fa fa-exclamation-circle"></i><p>Failed to load.</p></div>');
            return;
        }

        MM.conversations = r.conversations || [];
        MM.renderConversationList(MM.conversations);
        MM.populateTeacherFilter(MM.conversations);
    });
};

MM.renderConversationList = function(list) {
    var el = document.getElementById('convList');
    if (!el) return;

    if (!list.length) {
        el.innerHTML = '<div class="mm-empty"><i class="fa fa-comments-o"></i><p>No conversations found.</p></div>';
        return;
    }

    var html = '';
    list.forEach(function(c) {
        var isActive = MM.activeConvId === c.id;
        html += '<div class="mm-conv-item' + (isActive ? ' active' : '') + '" data-id="' + esc(c.id) + '" onclick="MM.selectConversation(\'' + esc(c.id) + '\')">'
            + '<div class="mm-conv-item-header">'
            +   '<span class="mm-conv-teacher">' + esc(c.teacher_name || 'Unknown Teacher') + '</span>'
            +   '<span class="mm-conv-time">' + MM.timeAgo(c.last_message_time || c.updated_at) + '</span>'
            + '</div>'
            + '<div class="mm-conv-parent"><i class="fa fa-user-o" style="margin-right:4px;font-size:10px;"></i>' + esc(c.parent_name || 'Unknown Parent') + '</div>'
            + '<div class="mm-conv-student">' + esc(c.student_name || '') + (c.class_name ? ' - ' + esc(c.class_name) : '') + '</div>'
            + '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">'
            +   '<div class="mm-conv-preview">' + esc(c.last_message || '') + '</div>'
            +   (parseInt(c.unread_count || 0) > 0 ? '<span class="mm-conv-unread">' + c.unread_count + '</span>' : '')
            + '</div>'
            + '</div>';
    });
    el.innerHTML = html;
};

MM.populateTeacherFilter = function(convs) {
    var sel = document.getElementById('convTeacherFilter');
    if (!sel) return;
    var teachers = {};
    convs.forEach(function(c) {
        if (c.teacher_name && !teachers[c.teacher_name]) {
            teachers[c.teacher_name] = true;
        }
    });
    var html = '<option value="">All Teachers</option>';
    Object.keys(teachers).sort().forEach(function(name) {
        html += '<option value="' + esc(name) + '">' + esc(name) + '</option>';
    });
    sel.innerHTML = html;
};

MM.filterConversations = function() {
    var query = (document.getElementById('convSearchInput').value || '').toLowerCase();
    var teacher = document.getElementById('convTeacherFilter').value;

    var filtered = MM.conversations.filter(function(c) {
        var matchQuery = !query ||
            (c.teacher_name || '').toLowerCase().indexOf(query) > -1 ||
            (c.parent_name || '').toLowerCase().indexOf(query) > -1 ||
            (c.student_name || '').toLowerCase().indexOf(query) > -1 ||
            (c.last_message || '').toLowerCase().indexOf(query) > -1;
        var matchTeacher = !teacher || c.teacher_name === teacher;
        return matchQuery && matchTeacher;
    });

    MM.renderConversationList(filtered);
};

// Bind filter events
document.getElementById('convSearchInput').addEventListener('input', function() {
    clearTimeout(MM._convSearchTimer);
    MM._convSearchTimer = setTimeout(MM.filterConversations, 250);
});
document.getElementById('convTeacherFilter').addEventListener('change', MM.filterConversations);

MM.selectConversation = function(convId) {
    MM.activeConvId = convId;
    MM.selectedMsgId = null;
    document.getElementById('btnDeleteMsg').disabled = true;

    // Highlight active
    document.querySelectorAll('.mm-conv-item').forEach(function(el) {
        el.classList.toggle('active', el.getAttribute('data-id') === convId);
    });

    // Show loading in chat
    document.getElementById('chatEmpty').style.display = 'none';
    var chatContent = document.getElementById('chatContent');
    chatContent.style.display = 'flex';
    setHtml('chatMessages', '<div class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading messages...</div>');

    ajaxPost('message_monitor/get_conversation_detail', { conversation_id: convId }, function(r) {
        if (r.status !== 'success') {
            setHtml('chatMessages', '<div class="mm-empty"><i class="fa fa-exclamation-circle"></i><p>Failed to load messages.</p></div>');
            return;
        }

        var conv = r.conversation || {};
        var msgs = r.messages || [];

        // Update header
        setText('chatTeacherName', conv.teacher_name || 'Teacher');
        setText('chatParentName', conv.parent_name || 'Parent');
        setText('chatMeta', (conv.student_name || '') + (conv.class_name ? ' | ' + conv.class_name : ''));
        setText('chatMsgCount', msgs.length);

        // Render bubbles
        MM.renderChatBubbles(msgs);
    });
};

MM.renderChatBubbles = function(messages) {
    var container = document.getElementById('chatMessages');
    if (!container) return;

    if (!messages.length) {
        container.innerHTML = '<div class="mm-empty"><i class="fa fa-comment-o"></i><p>No messages in this conversation.</p></div>';
        return;
    }

    var html = '';
    messages.forEach(function(msg) {
        var isTeacher = (msg.sender_type || '').toLowerCase() === 'teacher';
        var rowClass = isTeacher ? 'teacher' : 'parent';
        var isDeleted = msg.is_deleted == 1 || msg.is_deleted === true;
        var msgText = isDeleted ? 'This message has been deleted.' : (msg.message || msg.text || '');
        var bubbleExtra = isDeleted ? ' mm-bubble-deleted' : '';

        html += '<div class="mm-bubble-row ' + rowClass + '" data-msg-id="' + esc(msg.id || '') + '" onclick="MM.selectMessage(\'' + esc(msg.id || '') + '\')">'
            + '<div class="mm-bubble-sender">' + esc(msg.sender_name || (isTeacher ? 'Teacher' : 'Parent')) + '</div>'
            + '<div class="mm-bubble' + bubbleExtra + '">' + esc(msgText) + '</div>'
            + '<div class="mm-bubble-time">' + MM.formatTs(msg.created_at || msg.time) + '</div>'
            + '</div>';
    });

    container.innerHTML = html;

    // Scroll to bottom
    container.scrollTop = container.scrollHeight;
};

MM.selectMessage = function(msgId) {
    if (!msgId) return;
    MM.selectedMsgId = msgId;
    document.getElementById('btnDeleteMsg').disabled = false;

    // Highlight selected bubble
    document.querySelectorAll('.mm-bubble-row').forEach(function(el) {
        el.style.opacity = el.getAttribute('data-msg-id') === msgId ? '1' : '0.6';
    });
};

MM.deleteSelectedMessage = function() {
    if (!MM.selectedMsgId) return;
    if (!confirm('Are you sure you want to delete this message? This action cannot be undone.')) return;

    ajaxPost('message_monitor/delete_message', { message_id: MM.selectedMsgId }, function(r) {
        if (r.status === 'success') {
            MM.toast('Message deleted successfully.');
            // Reload the conversation
            if (MM.activeConvId) MM.selectConversation(MM.activeConvId);
        } else {
            MM.toast(r.message || 'Failed to delete message.', 'error');
        }
    });
};

MM.exportConversation = function() {
    if (!MM.activeConvId) return;
    ajaxPost('message_monitor/export_conversation', { conversation_id: MM.activeConvId }, function(r) {
        if (r.status === 'success' && r.download_url) {
            window.open(BASE + r.download_url, '_blank');
            MM.toast('Export ready.');
        } else if (r.status === 'success' && r.data) {
            // Download as text blob
            var blob = new Blob([r.data], { type: 'text/plain' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = 'conversation_' + MM.activeConvId + '.txt';
            a.click(); URL.revokeObjectURL(url);
            MM.toast('Export downloaded.');
        } else {
            MM.toast(r.message || 'Export failed.', 'error');
        }
    });
};


/* ══════════════════════════════════════════════════════════════
   SEARCH
   ══════════════════════════════════════════════════════════════ */
MM.searchMessages = function() {
    var query = (document.getElementById('searchInput').value || '').trim();
    if (!query) {
        MM.toast('Please enter a search keyword.', 'error');
        return;
    }

    setHtml('searchResults', '<div class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Searching...</div>');
    document.getElementById('searchCount').style.display = 'none';

    ajaxPost('message_monitor/search_messages', { keyword: query }, function(r) {
        if (r.status !== 'success') {
            setHtml('searchResults', '<div class="mm-empty"><i class="fa fa-exclamation-circle"></i><p>Search failed.</p></div>');
            return;
        }

        var results = r.results || [];

        if (!results.length) {
            setHtml('searchResults', '<div class="mm-empty"><i class="fa fa-search"></i><p>No messages found matching "' + esc(query) + '".</p></div>');
            document.getElementById('searchCount').style.display = 'none';
            return;
        }

        var countEl = document.getElementById('searchCount');
        countEl.textContent = results.length + ' result' + (results.length !== 1 ? 's' : '') + ' found';
        countEl.style.display = 'block';

        var html = '';
        results.forEach(function(r) {
            var msgText = r.message || r.text || '';
            // Highlight keyword in message
            var regex = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            var highlighted = esc(msgText).replace(regex, '<mark>$1</mark>');

            html += '<div class="mm-search-result" onclick="MM.jumpToConversation(\'' + esc(r.conversation_id || '') + '\')">'
                + '<div class="mm-search-result-header">'
                +   '<span class="mm-search-context">'
                +     esc(r.teacher_name || 'Teacher') + ' <i class="fa fa-exchange" style="color:var(--mm-primary);margin:0 6px;font-size:10px;"></i> ' + esc(r.parent_name || 'Parent')
                +   '</span>'
                +   '<span class="mm-search-time">' + MM.timeAgo(r.created_at || r.time) + '</span>'
                + '</div>'
                + '<div class="mm-search-text">' + highlighted + '</div>'
                + '<div style="margin-top:6px;font-size:10.5px;color:var(--mm-t4);">'
                +   '<span class="mm-badge mm-badge-' + ((r.sender_type || '').toLowerCase() === 'teacher' ? 'teal' : 'purple') + '">'
                +     esc(r.sender_name || 'Unknown') + '</span>'
                +   (r.student_name ? ' &middot; ' + esc(r.student_name) : '')
                +   (r.class_name ? ' &middot; ' + esc(r.class_name) : '')
                + '</div>'
                + '</div>';
        });
        setHtml('searchResults', html);
    });
};

// Search on enter
document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') MM.searchMessages();
});

MM.jumpToConversation = function(convId) {
    if (!convId) return;
    MM.switchTab('conversations');
    // Ensure conversations are loaded, then select
    if (!MM.conversations.length) {
        ajaxPost('message_monitor/get_conversations', {}, function(r) {
            if (r.status === 'success') {
                MM.conversations = r.conversations || [];
                MM.renderConversationList(MM.conversations);
                MM.populateTeacherFilter(MM.conversations);
            }
            MM.selectConversation(convId);
        });
    } else {
        MM.selectConversation(convId);
    }
};


/* ══════════════════════════════════════════════════════════════
   ANALYTICS
   ══════════════════════════════════════════════════════════════ */
MM.charts = {};

MM.loadAnalytics = function() {
    ajaxPost('message_monitor/get_analytics', {}, function(r) {
        if (r.status !== 'success') {
            MM.toast('Failed to load analytics.', 'error');
            return;
        }
        var d = r.data || {};

        // Daily volume chart
        MM.renderDailyChart(d.daily_volume || []);

        // Hourly chart
        MM.renderHourlyChart(d.hourly_distribution || []);

        // Class-wise pie
        MM.renderClassPie(d.class_stats || []);
    });

    // Teacher leaderboard
    ajaxPost('message_monitor/get_teacher_stats', {}, function(r) {
        if (r.status !== 'success') {
            setHtml('teacherLbBody', '<tr><td colspan="6" class="mm-empty">Failed to load.</td></tr>');
            return;
        }
        MM.renderTeacherLeaderboard(r.teachers || []);
    });

    // Activity timeline
    ajaxPost('message_monitor/get_activity_timeline', {}, function(r) {
        if (r.status !== 'success') return;
        MM.renderTimeline(r.timeline || []);
    });
};

MM.renderDailyChart = function(data) {
    var ctx = document.getElementById('chartDailyVolume');
    if (!ctx) return;
    if (MM.charts.daily) MM.charts.daily.destroy();

    var labels = data.map(function(d) { return d.date || d.label; });
    var values = data.map(function(d) { return d.count || d.value || 0; });

    MM.charts.daily = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Messages',
                data: values,
                borderColor: '#14b8a6',
                backgroundColor: 'rgba(20,184,166,.08)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 2,
                pointHoverRadius: 5,
                pointBackgroundColor: '#14b8a6'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(7,15,28,.95)',
                    padding: 10, cornerRadius: 8,
                    borderColor: 'rgba(15,118,110,.3)', borderWidth: 1
                }
            },
            scales: {
                x: { grid: { color: 'rgba(15,118,110,.06)' }, ticks: { color: '#5a9e98', font: { size: 10 }, maxTicksLimit: 10 } },
                y: { beginAtZero: true, grid: { color: 'rgba(15,118,110,.06)' }, ticks: { color: '#5a9e98', font: { size: 10 } } }
            }
        }
    });
};

MM.renderHourlyChart = function(data) {
    var ctx = document.getElementById('chartHourly');
    if (!ctx) return;
    if (MM.charts.hourly) MM.charts.hourly.destroy();

    // Build 24-hour labels
    var labels = [];
    var values = [];
    for (var h = 0; h < 24; h++) {
        labels.push(h.toString().padStart(2, '0') + ':00');
        var found = data.find(function(d) { return parseInt(d.hour) === h; });
        values.push(found ? (found.count || found.value || 0) : 0);
    }

    MM.charts.hourly = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Messages',
                data: values,
                backgroundColor: values.map(function(v, i) {
                    // Gradient effect based on hour activity
                    return i >= 8 && i <= 17 ? 'rgba(20,184,166,.6)' : 'rgba(20,184,166,.25)';
                }),
                borderColor: 'rgba(20,184,166,.8)',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(7,15,28,.95)',
                    padding: 10, cornerRadius: 8,
                    borderColor: 'rgba(15,118,110,.3)', borderWidth: 1
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#5a9e98', font: { size: 9 }, maxRotation: 45 } },
                y: { beginAtZero: true, grid: { color: 'rgba(15,118,110,.06)' }, ticks: { color: '#5a9e98', font: { size: 10 } } }
            }
        }
    });
};

MM.renderClassPie = function(data) {
    var ctx = document.getElementById('chartClasswise');
    if (!ctx) return;
    if (MM.charts.classPie) MM.charts.classPie.destroy();

    if (!data.length) {
        setHtml('chartClasswise', '<div class="mm-empty"><i class="fa fa-pie-chart"></i><p>No class data.</p></div>');
        return;
    }

    var labels = data.map(function(d) { return d.class_name || d.label || 'Unknown'; });
    var values = data.map(function(d) { return d.count || d.value || 0; });
    var colors = [
        '#14b8a6', '#2563eb', '#d97706', '#dc2626', '#7c3aed',
        '#16a34a', '#e05c6f', '#0ea5e9', '#f59e0b', '#8b5cf6',
        '#06b6d4', '#a855f7', '#ec4899', '#84cc16', '#f97316'
    ];

    MM.charts.classPie = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors.slice(0, labels.length),
                borderColor: 'rgba(7,15,28,.5)',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#94c9c3', font: { size: 11 }, padding: 12, usePointStyle: true, pointStyleWidth: 10 }
                },
                tooltip: {
                    backgroundColor: 'rgba(7,15,28,.95)',
                    padding: 10, cornerRadius: 8,
                    borderColor: 'rgba(15,118,110,.3)', borderWidth: 1
                }
            },
            cutout: '55%'
        }
    });
};

MM.renderTeacherLeaderboard = function(teachers) {
    var el = document.getElementById('teacherLbBody');
    if (!el) return;

    if (!teachers.length) {
        el.innerHTML = '<tr><td colspan="6" class="mm-empty"><i class="fa fa-users"></i> No teacher data.</td></tr>';
        return;
    }

    var html = '';
    teachers.forEach(function(t, idx) {
        var actBadge = t.is_active ? '<span class="mm-dot mm-dot-green"></span>Active' : '<span class="mm-dot mm-dot-red"></span>Inactive';
        html += '<tr>'
            + '<td style="font-weight:700;color:var(--mm-primary);">' + (idx + 1) + '</td>'
            + '<td><strong>' + esc(t.name) + '</strong></td>'
            + '<td>' + (t.conversations || t.conv_count || 0) + '</td>'
            + '<td>' + (t.messages_sent || t.msg_count || 0) + '</td>'
            + '<td>' + esc(t.avg_response_time || '--') + '</td>'
            + '<td style="font-size:11px;">' + actBadge + '</td>'
            + '</tr>';
    });
    el.innerHTML = html;

    // Initialize DataTable
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#teacherLeaderboard')) {
        $('#teacherLeaderboard').DataTable({
            paging: true,
            pageLength: 10,
            searching: true,
            ordering: true,
            info: true,
            language: {
                search: '<i class="fa fa-search" style="color:var(--mm-t4);margin-right:4px;"></i>',
                emptyTable: 'No data available'
            }
        });
    }
};

MM.renderTimeline = function(events) {
    var el = document.getElementById('activityTimeline');
    if (!el) return;

    if (!events.length) {
        el.innerHTML = '<div class="mm-empty"><i class="fa fa-clock-o"></i><p>No activity.</p></div>';
        return;
    }

    var html = '';
    events.slice(0, 20).forEach(function(ev) {
        var iconMap = {
            'message': 'fa-comment', 'conversation_start': 'fa-plus-circle',
            'delete': 'fa-trash', 'flag': 'fa-flag', 'default': 'fa-circle-o'
        };
        var icon = iconMap[ev.type] || iconMap['default'];
        var colorMap = {
            'message': 'teal', 'conversation_start': 'green',
            'delete': 'red', 'flag': 'amber', 'default': 'gray'
        };
        var color = colorMap[ev.type] || 'gray';

        html += '<div class="mm-feed-item">'
            + '<div class="mm-feed-avatar" style="background:var(--mm-' + color + '-dim,var(--mm-bg3));width:30px;height:30px;">'
            +   '<i class="fa ' + icon + '" style="font-size:12px;color:var(--mm-' + color + ',var(--mm-t3));"></i>'
            + '</div>'
            + '<div class="mm-feed-body">'
            +   '<div class="mm-feed-sender">' + esc(ev.description || ev.title || '') + '</div>'
            +   '<div class="mm-feed-text">' + esc(ev.detail || '') + '</div>'
            + '</div>'
            + '<div class="mm-feed-time">' + MM.timeAgo(ev.created_at || ev.time) + '</div>'
            + '</div>';
    });
    el.innerHTML = html;
};


/* ══════════════════════════════════════════════════════════════
   MODERATION
   ══════════════════════════════════════════════════════════════ */
MM.keywords = [];

MM.loadModeration = function() {
    MM.loadFlaggedKeywords();
    MM.loadFlagged();
};

MM.loadFlaggedKeywords = function() {
    ajaxPost('message_monitor/get_flagged_content', {}, function(r) {
        if (r.status === 'success') {
            MM.keywords = r.keywords || [];
            MM.renderKeywords();
        }
    });
};

MM.renderKeywords = function() {
    var el = document.getElementById('kwTags');
    if (!el) return;

    if (!MM.keywords.length) {
        el.innerHTML = '<span style="font-size:12px;color:var(--mm-t4);font-style:italic;">No flagged keywords configured.</span>';
        return;
    }

    var html = '';
    MM.keywords.forEach(function(kw, idx) {
        html += '<span class="mm-kw-tag">'
            + esc(kw)
            + '<span class="mm-kw-remove" onclick="MM.removeKeyword(' + idx + ')" title="Remove"><i class="fa fa-times"></i></span>'
            + '</span>';
    });
    el.innerHTML = html;
};

MM.addKeyword = function() {
    var input = document.getElementById('kwInput');
    var kw = (input.value || '').trim().toLowerCase();
    if (!kw) return;
    if (MM.keywords.indexOf(kw) > -1) {
        MM.toast('Keyword already exists.', 'error');
        return;
    }
    MM.keywords.push(kw);
    MM.renderKeywords();
    input.value = '';
    input.focus();
};

// Add on enter
document.getElementById('kwInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') MM.addKeyword();
});

MM.removeKeyword = function(idx) {
    MM.keywords.splice(idx, 1);
    MM.renderKeywords();
};

MM.saveKeywords = function() {
    ajaxPost('message_monitor/save_flagged_keywords', { keywords: MM.keywords.join(',') }, function(r) {
        if (r.status === 'success') {
            MM.toast('Keywords saved successfully.');
            // Reload flagged messages with new keywords
            MM.loadFlagged();
        } else {
            MM.toast(r.message || 'Failed to save keywords.', 'error');
        }
    });
};

MM.loadFlagged = function() {
    setHtml('flaggedBody', '<tr><td colspan="6" class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Loading flagged messages...</td></tr>');

    ajaxPost('message_monitor/get_flagged_content', {}, function(r) {
        if (r.status !== 'success') {
            setHtml('flaggedBody', '<tr><td colspan="6" class="mm-empty">Failed to load flagged content.</td></tr>');
            return;
        }

        // Update keywords too
        if (r.keywords) {
            MM.keywords = r.keywords;
            MM.renderKeywords();
        }

        var flagged = r.flagged_messages || [];
        if (!flagged.length) {
            setHtml('flaggedBody', '<tr><td colspan="6" class="mm-empty"><i class="fa fa-check-circle" style="color:var(--mm-green);"></i> No flagged messages found.</td></tr>');
            // Re-init DataTable if needed
            MM.initFlaggedTable();
            return;
        }

        var html = '';
        flagged.forEach(function(f) {
            html += '<tr>'
                + '<td><span class="mm-badge mm-badge-red">' + esc(f.keyword || '--') + '</span></td>'
                + '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(f.message || f.text || '') + '</td>'
                + '<td>'
                +   '<span class="mm-badge mm-badge-' + ((f.sender_type || '').toLowerCase() === 'teacher' ? 'teal' : 'purple') + '">'
                +     esc(f.sender_name || 'Unknown')
                +   '</span>'
                + '</td>'
                + '<td style="font-size:11px;">'
                +   esc(f.teacher_name || '') + ' / ' + esc(f.parent_name || '')
                + '</td>'
                + '<td style="white-space:nowrap;font-size:11px;">' + MM.formatTs(f.created_at || f.time) + '</td>'
                + '<td>'
                +   '<div style="display:flex;gap:4px;">'
                +     '<button class="mm-btn mm-btn-danger mm-btn-sm" onclick="MM.deleteFlaggedMessage(\'' + esc(f.message_id || f.id || '') + '\')" title="Delete"><i class="fa fa-trash"></i></button>'
                +     '<button class="mm-btn mm-btn-outline mm-btn-sm" onclick="MM.jumpToConversation(\'' + esc(f.conversation_id || '') + '\')" title="View conversation"><i class="fa fa-eye"></i></button>'
                +   '</div>'
                + '</td>'
                + '</tr>';
        });
        setHtml('flaggedBody', html);
        MM.initFlaggedTable();
    });
};

MM.initFlaggedTable = function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#flaggedTable')) {
        $('#flaggedTable').DataTable({
            paging: true,
            pageLength: 15,
            searching: true,
            ordering: true,
            info: true,
            order: [[4, 'desc']],
            language: {
                search: '<i class="fa fa-search" style="color:var(--mm-t4);margin-right:4px;"></i>',
                emptyTable: 'No flagged messages'
            }
        });
    }
};

MM.deleteFlaggedMessage = function(msgId) {
    if (!msgId) return;
    if (!confirm('Delete this flagged message permanently?')) return;

    ajaxPost('message_monitor/delete_message', { message_id: msgId }, function(r) {
        if (r.status === 'success') {
            MM.toast('Message deleted.');
            // Destroy and reinit DataTable
            if ($.fn.DataTable && $.fn.DataTable.isDataTable('#flaggedTable')) {
                $('#flaggedTable').DataTable().destroy();
            }
            MM.loadFlagged();
        } else {
            MM.toast(r.message || 'Failed to delete.', 'error');
        }
    });
};


/* ══════════════════════════════════════════════════════════════
   REFRESH & INIT
   ══════════════════════════════════════════════════════════════ */
MM.refresh = function() {
    MM.tabLoaded = {};
    MM.tabLoaded[MM.activeTab] = true;

    // Destroy existing charts
    if (MM.dashChart) { MM.dashChart.destroy(); MM.dashChart = null; }
    Object.keys(MM.charts).forEach(function(k) {
        if (MM.charts[k]) { MM.charts[k].destroy(); delete MM.charts[k]; }
    });

    // Destroy DataTables
    if ($.fn.DataTable) {
        if ($.fn.DataTable.isDataTable('#teacherLeaderboard')) $('#teacherLeaderboard').DataTable().destroy();
        if ($.fn.DataTable.isDataTable('#flaggedTable')) $('#flaggedTable').DataTable().destroy();
    }

    switch (MM.activeTab) {
        case 'dashboard':
            MM.loadDashboard();
            break;
        case 'conversations':
            MM.activeConvId = null;
            document.getElementById('chatEmpty').style.display = '';
            document.getElementById('chatContent').style.display = 'none';
            MM.loadConversations();
            break;
        case 'search':
            setHtml('searchResults', '<div class="mm-empty"><i class="fa fa-search"></i><p>Enter a keyword to search across all messages.</p></div>');
            document.getElementById('searchCount').style.display = 'none';
            break;
        case 'analytics':
            MM.loadAnalytics();
            break;
        case 'moderation':
            MM.loadModeration();
            break;
    }

    MM.toast('Refreshed.');
};

/* ── Boot ─────────────────────────────────────────────────── */
$(document).ready(function() {
    MM.loadDashboard();
    MM.tabLoaded['dashboard'] = true;
});

})();
</script>
