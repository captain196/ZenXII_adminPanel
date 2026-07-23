<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!-- Unified Staff Access — built to the prototype (artifact 515af012), every class
     namespaced `ua-` so nothing collides with Bootstrap 3 / AdminLTE. Chrome (rail,
     topbar, theme) comes from the panel; this is the content only. Wired to real
     Staff_access endpoints. -->
<div class="content-wrapper" id="ua-cw"><div id="ua">
<style>
/* Sit inside the AdminLTE content area (beside the fixed sidebar / below the header),
   with our own light ground so panel day/night themes don't bleed through. Uses the
   LOW-specificity `.content-wrapper` selector (exactly like admin_users) — NOT an id —
   so the panel's `.sidebar-collapse .content-wrapper` rule can still override margin-left
   when the sidebar is minimised (an id selector would win and freeze the margin). */
.content-wrapper{margin-left:var(--sw,248px)!important;margin-top:var(--hh,58px)!important;
  min-height:calc(100vh - var(--hh,58px))!important;background:#F7F4F1!important;padding:22px 26px}
#ua{max-width:1240px;margin:0 auto;background:transparent}
#ua{
  --clay:#BC5A3C;--clay-deep:#A24A30;--clay-soft:#E7C4B6;
  --ground:#F7F4F1;--surface:#FFFFFF;--surface-2:#FBF9F7;
  --border:#E8E2DC;--border-strong:#D8CFC6;--ink:#2B2521;--muted:#7A716A;--faint:#A79E96;
  --ok:#3E8E5A;--ok-bg:#E4F1E9;--edit:#B9812A;--edit-bg:#F6EBD5;
  --view:#3E6FA8;--view-bg:#E2ECF6;--deny:#C0453B;--deny-bg:#F7E1DE;
  --manage:#3E8E5A;--manage-bg:#E4F1E9;                 /* tile level chip alias */
  --app:#2C8686;--app-bg:#DDEFEF;--web:#586A79;--web-bg:#E5EAEE;
  --shadow:0 1px 2px rgba(43,37,33,.06),0 8px 24px rgba(43,37,33,.06);
  --radius:12px;--mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  --sans:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  color:var(--ink);font-family:var(--sans);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;
}
#ua *{box-sizing:border-box}
#ua .ua-mono{font-family:var(--mono)}
#ua h2,#ua h3,#ua h4{margin:0;font-weight:650;letter-spacing:-.01em;text-wrap:balance}
#ua .ua-tabs{display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:22px}
#ua .ua-tab{padding:12px 16px;border:none;background:none;color:var(--muted);font-size:13.5px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit}
#ua .ua-tab:hover{color:var(--ink)}#ua .ua-tab.ua-on{color:var(--clay);border-bottom-color:var(--clay)}
#ua .ua-screen{display:none}#ua .ua-screen.ua-on{display:block;animation:uafade .25s ease}
#ua .ua-subview{display:none}#ua .ua-subview.ua-on{display:block;animation:uafade .2s ease}
@keyframes uafade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){#ua .ua-screen,#ua .ua-subview{animation:none}}
/* Professional page header — icon + title + one-line subtitle, actions right-aligned,
   separated from the content by a hairline. Replaces the old marketing eyebrow block. */
#ua .ua-phead{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;
  padding-bottom:16px;margin-bottom:20px;border-bottom:1px solid var(--border)}
#ua .ua-phead-id{display:flex;align-items:flex-start;gap:13px;min-width:0}
#ua .ua-phead-ic{width:40px;height:40px;border-radius:10px;background:var(--clay-soft);color:var(--clay-deep);
  display:flex;align-items:center;justify-content:center;flex:none}
#ua .ua-phead-ic svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:1.85;stroke-linecap:round;stroke-linejoin:round}
#ua .ua-phead-tx{min-width:0}
#ua .ua-phead-tx h2{font-size:20px;font-weight:680;letter-spacing:-.02em;color:var(--ink);line-height:1.25}
#ua .ua-phead-tx p{margin:4px 0 0;color:var(--muted);font-size:13px;line-height:1.5;max-width:74ch}
#ua .ua-phead-actions{display:flex;gap:8px;flex-wrap:wrap;flex:none}
@media(max-width:640px){#ua .ua-phead{gap:14px}#ua .ua-phead-actions{width:100%}}
#ua .ua-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow)}
#ua .ua-btn{display:inline-flex;align-items:center;gap:7px;background:var(--clay);color:#fff;border:none;border-radius:9px;padding:9px 15px;font-family:inherit;font-size:13px;font-weight:650;cursor:pointer;white-space:nowrap;line-height:1.3}
#ua .ua-btn:hover{background:var(--clay-deep)}
#ua .ua-btn.ua-ghost{background:var(--surface-2);color:var(--ink);border:1px solid var(--border-strong)}
#ua .ua-btn.ua-ghost:hover{border-color:var(--clay);color:var(--clay)}
#ua .ua-btn.ua-danger{background:var(--deny-bg);color:var(--deny);border:1px solid transparent}
#ua .ua-btn.ua-danger:hover{background:var(--deny);color:#fff}
#ua .ua-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:650;padding:3px 8px;border-radius:100px;line-height:1.4;white-space:nowrap}
#ua .ua-chip.app{background:var(--app-bg);color:var(--app)}#ua .ua-chip.web{background:var(--web-bg);color:var(--web)}
#ua .ua-chip.both{background:linear-gradient(90deg,var(--app-bg) 50%,var(--web-bg) 50%);color:var(--ink)}
#ua .ua-chip.manage{background:var(--ok-bg);color:var(--ok)}#ua .ua-chip.edit{background:var(--edit-bg);color:var(--edit)}
#ua .ua-chip.view{background:var(--view-bg);color:var(--view)}#ua .ua-chip.deny{background:var(--deny-bg);color:var(--deny)}
#ua .ua-chip.sys{background:var(--clay-soft);color:var(--clay-deep)}
#ua .ua-chip.mut{background:var(--surface-2);color:var(--faint);border:1px solid var(--border)}
/* Unified department section — header (identity + inline controls) over its role grid.
   One place per department: no separate manager list. */
#ua .ua-dept{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);margin-bottom:14px;overflow:hidden;transition:border-color .15s}
#ua .ua-dept:hover{border-color:var(--border-strong)}
#ua .ua-dept-head{display:flex;align-items:center;gap:12px;padding:12px 15px;border-bottom:1px solid var(--border);background:var(--surface-2)}
#ua .ua-dept-id{display:flex;align-items:center;gap:11px;min-width:0}
#ua .ua-dept-badge{width:34px;height:34px;border-radius:9px;background:var(--clay-soft);color:var(--clay-deep);display:flex;align-items:center;justify-content:center;font-weight:750;font-size:15px;flex:none;text-transform:uppercase}
#ua .ua-dept-meta{min-width:0}
#ua .ua-dept-name{font-size:15px;font-weight:680;display:flex;align-items:center;gap:9px;letter-spacing:-.01em}
#ua .ua-dept-name .ua-count{background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:11px;padding:1px 8px;border-radius:100px;font-weight:650;flex:none}
#ua .ua-dept-sub{font-size:11.5px;color:var(--faint);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:52ch}
#ua .ua-dept-tools{margin-left:auto;display:flex;gap:5px;align-items:center;opacity:.6;transition:opacity .15s}
#ua .ua-dept-tools .ua-icbtn i{font-size:12px}
#ua .ua-dept:hover .ua-dept-tools,#ua .ua-dept-tools:focus-within{opacity:1}
#ua .ua-dept-body{padding:14px 15px}
/* drag-and-drop: role card is the source, whole section is the drop target */
#ua .ua-rolecard{position:relative}
#ua .ua-rc-drag{position:absolute;bottom:11px;right:11px;color:var(--faint);opacity:0;transition:opacity .15s;cursor:grab;font-size:12px}
#ua .ua-rolecard:hover .ua-rc-drag{opacity:.65}
#ua .ua-rolecard.ua-dragging{opacity:.4;border-color:var(--clay)!important;transform:none!important}
#ua .ua-deptrender.ua-dnd-on .ua-dept{transition:box-shadow .12s,border-color .12s}
#ua .ua-dept.ua-drop-hover{border-color:var(--clay);box-shadow:0 0 0 2px var(--clay-soft)}
#ua .ua-dept.ua-drop-hover .ua-dept-head{background:var(--clay-soft)}
#ua .ua-dept-un.ua-drop-hover{border-color:var(--edit)}
#ua .ua-dept-un.ua-drop-hover .ua-dept-head{background:#FBF2DE}
/* Unassigned — synthetic bucket, amber "needs attention" tone, no reorder/rename/delete */
#ua .ua-dept-un{border-color:var(--edit-bg);background:linear-gradient(180deg,#FEFAF1,var(--surface) 46px)}
#ua .ua-dept-un .ua-dept-head{background:#FBF2DE;border-bottom-color:#F0E1BF}
#ua .ua-dept-un .ua-dept-badge{background:var(--edit-bg);color:var(--edit)}
#ua .ua-dept-hint{font-size:12px;color:var(--edit);background:var(--edit-bg);border-radius:8px;padding:8px 11px;margin-bottom:12px;display:flex;gap:7px;align-items:center}
#ua .ua-rolegrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(258px,1fr));gap:12px}
#ua .ua-rolecard{padding:14px;cursor:pointer;transition:border-color .15s,transform .15s}
#ua .ua-rolecard:hover{border-color:var(--clay);transform:translateY(-2px)}
#ua .ua-rc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
#ua .ua-rolecard h4{font-size:15px}#ua .ua-rid{font-size:10.5px;color:var(--faint);margin-top:2px}
#ua .ua-rc-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
#ua .ua-modline{font-size:12px;color:var(--muted);margin-top:10px;line-height:1.6}
#ua .ua-badge-sys{font-size:9.5px;font-weight:700;color:var(--clay-deep);background:var(--clay-soft);padding:2px 6px;border-radius:5px;letter-spacing:.04em}
#ua .ua-badge-custom{font-size:9.5px;font-weight:700;color:var(--app);background:var(--app-bg);padding:2px 6px;border-radius:5px;letter-spacing:.04em}
#ua .ua-newrole-card{border-style:dashed;border-color:var(--border-strong);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;color:var(--muted);min-height:120px;cursor:pointer;font-weight:600;text-align:center}
#ua .ua-newrole-card:hover{border-color:var(--clay);color:var(--clay)}#ua .ua-newrole-card .ua-plus{font-size:26px;line-height:1}
#ua .ua-editor-wrap{display:grid;grid-template-columns:230px 1fr;gap:16px}
#ua .ua-rolelist{display:flex;flex-direction:column;gap:3px;align-content:start;padding:8px}
#ua .ua-rl{text-align:left;border:1px solid transparent;background:none;color:var(--ink);padding:9px 11px;border-radius:9px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:550;width:100%}
#ua .ua-rl small{display:block;color:var(--faint);font-size:10.5px;font-weight:500}
#ua .ua-rl:hover{background:var(--surface-2)}#ua .ua-rl.ua-on{background:var(--clay);color:#fff;border-color:var(--clay)}
#ua .ua-rl.ua-on small{color:rgba(255,255,255,.75)}
#ua .ua-grouptag{font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);font-weight:700;padding:10px 11px 4px}
#ua .ua-newbtn{margin-top:6px;border:1px dashed var(--border-strong);border-radius:9px;background:none;color:var(--clay);padding:8px;font-weight:650;cursor:pointer;font-family:inherit;font-size:12.5px}
#ua .ua-newbtn:hover{background:var(--surface-2)}
#ua .ua-matrix{padding:0;overflow:hidden}
#ua .ua-mtop{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
#ua .ua-mtop h3{font-size:16px;display:flex;align-items:center;gap:8px}#ua .ua-mtop p{margin:3px 0 0;font-size:12px;color:var(--muted)}
#ua .ua-mactions{display:flex;gap:6px}
#ua .ua-mscroll{overflow-x:auto}
#ua table.ua-perm{border-collapse:collapse;width:100%;min-width:560px}
#ua table.ua-perm th{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);text-align:left;padding:9px 14px;font-weight:700;border-bottom:1px solid var(--border)}
#ua table.ua-perm td{padding:9px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
#ua table.ua-perm tr:last-child td{border-bottom:none}
#ua .ua-modname{font-weight:600;font-size:13.5px}
#ua .ua-grouprow td{background:var(--surface-2);font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:700;padding:7px 14px}
#ua .ua-seg{display:inline-flex;border:1px solid var(--border-strong);border-radius:8px;overflow:hidden}
#ua .ua-seg button{border:none;background:var(--surface);color:var(--muted);font-family:inherit;font-size:11.5px;font-weight:650;padding:5px 11px;cursor:pointer;border-right:1px solid var(--border)}
#ua .ua-seg button:last-child{border-right:none}#ua .ua-seg button:hover{background:var(--surface-2)}
#ua .ua-seg button.ua-on[data-l="none"]{background:var(--faint);color:#fff}
#ua .ua-seg button.ua-on[data-l="view"]{background:var(--view);color:#fff}
#ua .ua-seg button.ua-on[data-l="edit"]{background:var(--edit);color:#fff}
#ua .ua-seg button.ua-on[data-l="manage"]{background:var(--ok);color:#fff}
#ua .ua-seg.ua-small button{padding:4px 10px;font-size:11px}
/* directory */
#ua .ua-dirbar{display:flex;gap:10px;align-items:center;margin-bottom:14px}
#ua .ua-search{flex:1;display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--border-strong);border-radius:10px;padding:8px 12px}
#ua .ua-search input{border:none;background:none;outline:none;color:var(--ink);font-family:inherit;font-size:13.5px;width:100%}
#ua table.ua-dirtable{width:100%;border-collapse:collapse}
#ua table.ua-dirtable th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);font-weight:700;padding:11px 16px;background:var(--surface-2);border-bottom:1px solid var(--border)}
#ua table.ua-dirtable td{padding:12px 16px;border-bottom:1px solid var(--border)}
#ua table.ua-dirtable tbody tr{cursor:pointer}#ua table.ua-dirtable tbody tr:hover td{background:var(--surface-2)}
#ua table.ua-dirtable tbody tr.ua-dirrow:focus-visible{outline:2px solid var(--brand,#BC5A3C);outline-offset:-2px}#ua table.ua-dirtable tbody tr.ua-dirrow:focus-visible td{background:var(--surface-2)}
#ua table.ua-dirtable tr:last-child td{border-bottom:none}
#ua .ua-who{display:flex;align-items:center;gap:11px}
#ua .ua-who .ua-av{width:36px;height:36px;border-radius:10px;background:var(--clay);color:#fff;display:grid;place-items:center;font-weight:650;font-size:13px}
#ua .ua-who .ua-nm{font-weight:600}#ua .ua-who .ua-id{font-size:11px;color:var(--faint)}
#ua .ua-rchips{display:flex;flex-wrap:wrap;gap:5px}
#ua .ua-rchip{font-size:11px;font-weight:600;padding:2px 8px;border-radius:100px;background:var(--surface-2);border:1px solid var(--border);color:var(--muted)}
#ua .ua-surf-badges{display:flex;gap:5px}
#ua .ua-backlink{display:inline-flex;align-items:center;gap:7px;background:none;border:none;color:var(--clay);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;padding:0;margin-bottom:14px}
#ua .ua-backlink:hover{text-decoration:underline}
/* staff detail */
#ua .ua-staff-wrap{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:18px;align-items:start}
#ua .ua-panel{padding:16px 18px}
#ua .ua-panel h3{font-size:15px;display:flex;align-items:center;gap:8px}#ua .ua-panel .ua-sub{font-size:12px;color:var(--muted);margin:3px 0 14px}
#ua .ua-staffcard{display:flex;align-items:center;gap:13px;margin-bottom:6px}
#ua .ua-staffcard .ua-av{width:46px;height:46px;border-radius:12px;background:var(--clay);color:#fff;display:grid;place-items:center;font-weight:700;font-size:17px}
#ua .ua-staffcard .ua-nm{font-size:16px;font-weight:650}#ua .ua-staffcard .ua-id{font-size:11.5px;color:var(--faint)}
#ua .ua-section-label{font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);font-weight:700;margin:16px 0 8px;display:flex;align-items:center;gap:8px}
/* Assigned-role rows — each role is an explicit row with visible actions */
#ua .ua-rolepills{display:flex;flex-direction:column;gap:7px}
#ua .ua-rolerow{display:flex;align-items:center;gap:10px;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);transition:border-color .15s}
#ua .ua-rolerow.ua-prim{border-color:var(--clay-soft);background:linear-gradient(0deg,var(--surface),var(--surface)) padding-box,var(--surface-2)}
#ua .ua-rr-main{min-width:0;display:flex;flex-direction:column}
#ua .ua-rr-name{font-size:13.5px;font-weight:650;display:flex;align-items:center;gap:7px;flex-wrap:wrap;line-height:1.3}
#ua .ua-rr-sub{font-size:11px;color:var(--faint);margin-top:2px}
#ua .ua-rr-badge{font-size:8.5px;font-weight:800;letter-spacing:.05em;padding:2px 6px;border-radius:5px}
#ua .ua-rr-badge.prim{background:var(--clay);color:#fff}
#ua .ua-rr-badge.auto{background:var(--clay-soft);color:var(--clay-deep)}
#ua .ua-rr-actions{margin-left:auto;display:flex;gap:6px;align-items:center;flex:none}
#ua .ua-rr-btn{border:1px solid var(--border-strong);background:var(--surface);color:var(--muted);border-radius:7px;padding:5px 9px;font-family:inherit;font-size:11.5px;font-weight:600;cursor:pointer;white-space:nowrap;line-height:1;transition:all .13s}
#ua .ua-rr-btn:hover{border-color:var(--clay);color:var(--clay)}
#ua .ua-rr-btn.rm:hover{border-color:var(--deny);color:var(--deny);background:var(--deny-bg)}
#ua .ua-rr-locked{font-size:11px;color:var(--faint);display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
#ua .ua-addrow{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
#ua .ua-addrow select,#ua .ua-mini-btn{font-family:inherit;font-size:12.5px;padding:7px 10px;border-radius:8px;border:1px solid var(--border-strong);background:var(--surface);color:var(--ink)}
#ua .ua-mini-btn{cursor:pointer;font-weight:600;background:var(--surface-2)}#ua .ua-mini-btn:hover{border-color:var(--clay);color:var(--clay)}
/* Extra-access / prohibited override rows — card row + explicit Remove button */
#ua .ua-ovr{display:flex;align-items:flex-start;gap:10px;padding:10px 11px;border:1px solid var(--border);border-radius:10px;margin-bottom:7px;background:var(--surface-2)}
#ua .ua-ovr:last-child{margin-bottom:0}
#ua .ua-ovr.deny{border-color:var(--deny-bg);background:var(--deny-bg)}
#ua .ua-ovr-main{min-width:0;flex:1}
#ua .ua-ovr-top{display:flex;align-items:center;gap:8px;font-size:13px;flex-wrap:wrap}
#ua .ua-ovr-top b{font-weight:650}
#ua .ua-ovr-audit{font-size:10.5px;color:var(--faint);margin-top:4px;line-height:1.45}#ua .ua-ovr-audit i{color:var(--muted)}
#ua .ua-ovr-rm{flex:none;border:1px solid var(--border-strong);background:var(--surface);color:var(--muted);border-radius:7px;padding:6px 11px;font-family:inherit;font-size:11.5px;font-weight:650;cursor:pointer;white-space:nowrap;line-height:1;transition:all .13s}
#ua .ua-ovr-rm:hover{border-color:var(--deny);color:var(--deny);background:var(--deny-bg)}
#ua .ua-ovr.deny .ua-ovr-rm{border-color:var(--ok);color:var(--ok)}
#ua .ua-ovr.deny .ua-ovr-rm:hover{background:var(--ok-bg);border-color:var(--ok)}
#ua .ua-add-trigger{width:100%;margin-top:10px;border:1px dashed var(--border-strong);border-radius:10px;background:var(--surface-2);color:var(--clay);padding:10px 12px;font-family:inherit;font-size:12.5px;font-weight:650;cursor:pointer;text-align:left;display:flex;align-items:center;gap:8px}
#ua .ua-add-trigger:hover{border-color:var(--clay)}#ua .ua-add-trigger.ua-deny{color:var(--deny)}#ua .ua-add-trigger.ua-deny:hover{border-color:var(--deny)}
#ua .ua-add-trigger .ua-caret{margin-left:auto;transition:transform .18s}#ua .ua-add-trigger.ua-open .ua-caret{transform:rotate(180deg)}
#ua .ua-picker{margin-top:10px;padding:13px;border:1px solid var(--border-strong)!important;box-shadow:none}
#ua .ua-picker-search{display:flex;align-items:center;gap:8px;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:7px 11px;margin-bottom:8px}
#ua .ua-picker-search input{border:none;background:none;outline:none;color:var(--ink);font-family:inherit;font-size:13px;width:100%}
#ua .ua-pk-grid{max-height:210px;overflow-y:auto;display:flex;flex-wrap:wrap;gap:6px;padding:2px}
#ua .ua-pk-gtag{width:100%;font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);font-weight:700;margin:7px 2px 1px}
#ua .ua-mchip{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border-radius:8px;border:1px solid var(--border-strong);background:var(--surface);color:var(--ink);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
#ua .ua-mchip:hover{border-color:var(--clay)}#ua .ua-mchip.ua-sel{background:var(--clay);color:#fff;border-color:var(--clay)}
#ua .ua-mchip.ua-deny-sel{background:var(--deny);color:#fff;border-color:var(--deny)}#ua .ua-mchip.ua-held{opacity:.72}
#ua .ua-ms{width:8px;height:8px;border-radius:50%;display:inline-block;flex:none}
#ua .ua-ms-app{background:var(--app)}#ua .ua-ms-web{background:var(--web)}#ua .ua-ms-both{background:linear-gradient(90deg,var(--app) 50%,var(--web) 50%)}
#ua .ua-mchip-held{font-size:8.5px;font-weight:800;background:var(--surface-2);color:var(--faint);padding:1px 5px;border-radius:4px;text-transform:uppercase}
#ua .ua-mchip.ua-sel .ua-mchip-held,#ua .ua-mchip.ua-deny-sel .ua-mchip-held{background:rgba(255,255,255,.25);color:#fff}
#ua .ua-pk-hint{font-size:12px;color:var(--faint);font-style:italic}
#ua .ua-pk-foot{margin-top:11px;border-top:1px dashed var(--border);padding-top:11px}
#ua .ua-pk-selrow{display:flex;align-items:center;gap:8px;font-size:13.5px;margin-bottom:10px;flex-wrap:wrap}
#ua .ua-pk-selrow .ua-lbl{font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-left:auto}
#ua .ua-pk-reason{width:100%;margin-bottom:10px;font-family:inherit;font-size:12.5px;padding:7px 10px;border-radius:8px;border:1px solid var(--border-strong);background:var(--surface);color:var(--ink);outline:none}
#ua .ua-pk-reason:focus{border-color:var(--clay)}
#ua .ua-pk-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center}
#ua .ua-surface-panel{border:1px solid var(--border);border-radius:11px;overflow:hidden;margin-bottom:14px}
#ua .ua-sp-head{padding:11px 14px;display:flex;align-items:center;gap:9px;font-weight:650;font-size:13.5px;border-bottom:1px solid var(--border)}
#ua .ua-sp-head.app{background:var(--app-bg);color:var(--app)}#ua .ua-sp-head.web{background:var(--web-bg);color:var(--web)}
#ua .ua-sp-head .ua-badge2{margin-left:auto;font-size:11px;font-weight:700;background:rgba(0,0,0,.06);padding:2px 8px;border-radius:100px}
#ua .ua-tilewrap{padding:12px 14px;display:flex;flex-wrap:wrap;gap:8px;min-height:44px}
#ua .ua-tile{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:9px;background:var(--surface-2);border:1px solid var(--border);font-size:12.5px;font-weight:600}
#ua .ua-tile .ua-lvl{font-size:9.5px;font-weight:800;letter-spacing:.03em;padding:1px 5px;border-radius:4px}
#ua .ua-tile.ua-own{border-style:dashed;border-color:var(--clay-soft);background:transparent}#ua .ua-tile.ua-own .ua-pin{font-size:10px;color:var(--clay)}
#ua .ua-empty{color:var(--faint);font-size:12.5px;font-style:italic;padding:4px 0}
#ua .ua-noaccess{background:var(--deny-bg);color:var(--deny);border-radius:9px;padding:11px 13px;font-size:12.5px;font-weight:600;display:flex;gap:9px;align-items:center}
#ua .ua-log{margin-top:4px;border-top:1px dashed var(--border);padding-top:12px}
#ua .ua-log h4{font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
#ua .ua-logrow{font-size:12px;padding:4px 0;display:flex;gap:8px;align-items:baseline;color:var(--muted)}
#ua .ua-logrow .ua-lm{font-weight:650;color:var(--ink);min-width:104px}
#ua .ua-logrow.ua-killed .ua-lm{text-decoration:line-through;color:var(--faint)}#ua .ua-arrow{color:var(--faint)}
#ua .ua-callout{margin-top:16px;background:var(--surface-2);border:1px solid var(--border);border-left:3px solid var(--clay);border-radius:9px;padding:12px 14px;font-size:12.5px;color:var(--muted)}
#ua .ua-callout b{color:var(--ink)}
#ua .ua-footer-note{margin-top:26px;padding-top:16px;border-top:1px solid var(--border);color:var(--faint);font-size:11.5px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between}
#ua .ua-legend{display:flex;gap:14px;flex-wrap:wrap}#ua .ua-legend span{display:inline-flex;align-items:center;gap:5px}
/* modal */
#ua .ua-overlay{position:fixed;inset:0;background:rgba(20,15,12,.5);display:none;align-items:center;justify-content:center;z-index:9000;padding:20px}
#ua .ua-overlay.ua-open{display:flex;animation:uafade .18s ease}
#ua .ua-modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.35);width:100%;max-width:480px;max-height:88vh;overflow:auto;position:relative}
#ua .ua-mh{padding:18px 20px 4px}#ua .ua-mh h3{font-size:18px}#ua .ua-mh p{margin:4px 0 0;color:var(--muted);font-size:12.5px}
#ua .ua-mb{padding:14px 20px}
#ua .ua-mf{padding:14px 20px 18px;display:flex;gap:9px;justify-content:flex-end;border-top:1px solid var(--border);margin-top:6px}
#ua .ua-field{margin-bottom:13px}
#ua .ua-field label{display:block;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:5px}
#ua .ua-field input,#ua .ua-field textarea,#ua .ua-field select{width:100%;font-family:inherit;font-size:13.5px;padding:9px 11px;border-radius:9px;border:1px solid var(--border-strong);background:var(--surface-2);color:var(--ink);outline:none}
#ua .ua-field input:focus,#ua .ua-field textarea:focus,#ua .ua-field select:focus{border-color:var(--clay)}
#ua .ua-field textarea{resize:vertical;min-height:56px}#ua .ua-field .ua-hint{font-size:11px;color:var(--faint);margin-top:4px}
#ua .ua-row2{display:grid;grid-template-columns:1fr 1fr;gap:11px}
#ua .ua-toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:600;z-index:9500;opacity:0;transition:opacity .2s,transform .2s;pointer-events:none}
#ua .ua-toast.ua-show{opacity:1;transform:translateX(-50%) translateY(-4px)}#ua .ua-toast.ua-err{background:var(--deny)}
/* global loading indicator (driven by every api() call): animated top bar + a
   floating "Working…" spinner pill so it's clearly visible anywhere on the page. */
#ua .ua-loadbar{position:fixed;top:0;left:0;right:0;height:4px;background:rgba(188,90,60,.14);z-index:99999;opacity:0;transition:opacity .2s;overflow:hidden;pointer-events:none}
#ua .ua-loadbar.ua-go{opacity:1}
#ua .ua-loadbar::before{content:"";position:absolute;top:0;height:100%;width:38%;left:-38%;
  background:linear-gradient(90deg,transparent,var(--clay),var(--clay-deep),var(--clay),transparent);
  box-shadow:0 0 10px 1px var(--clay);animation:ua-slide 1.05s ease-in-out infinite}
@keyframes ua-slide{0%{left:-38%}100%{left:100%}}
#ua .ua-working{position:fixed;top:calc(var(--hh,58px) + 16px);right:30px;z-index:99999;display:none;
  align-items:center;gap:9px;background:var(--clay);color:#fff;font-size:13px;font-weight:700;
  padding:9px 15px;border-radius:100px;box-shadow:0 6px 18px rgba(188,90,60,.45);pointer-events:none}
#ua .ua-working.ua-go{display:inline-flex;animation:uafade .2s ease}
#ua .ua-spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:ua-rot .7s linear infinite}
@keyframes ua-rot{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){#ua .ua-loadbar::before,#ua .ua-spin{animation-duration:0s}}
#ua .ua-btn:disabled,#ua .ua-mini-btn:disabled,#ua .ua-seg button:disabled{opacity:.6;cursor:progress}
#ua .ua-detail-loading{padding:44px;text-align:center;color:var(--muted);font-style:italic}
/* summary strip above the sections */
#ua .ua-deptsummary{display:flex;gap:16px;align-items:center;margin-bottom:16px;font-size:12.5px;color:var(--muted);flex-wrap:wrap}
#ua .ua-deptsummary b{color:var(--ink);font-weight:680}
#ua .ua-deptsummary .ua-dot{width:4px;height:4px;border-radius:50%;background:var(--border-strong)}
#ua .ua-icbtn{border:1px solid var(--border-strong);background:var(--surface);color:var(--muted);width:29px;height:29px;border-radius:7px;cursor:pointer;font-size:13px;line-height:1;padding:0;display:inline-flex;align-items:center;justify-content:center}
#ua .ua-icbtn:hover{border-color:var(--clay);color:var(--clay)}#ua .ua-icbtn:disabled{opacity:.4;cursor:not-allowed}
#ua .ua-icbtn-d:hover{border-color:var(--deny);color:var(--deny)}
@media(max-width:1080px){#ua .ua-staff-wrap,#ua .ua-editor-wrap{grid-template-columns:1fr}}
</style>

<div class="ua-tabs">
  <button class="ua-tab ua-on" data-s="roles">Departments &amp; Roles</button>
  <button class="ua-tab" data-s="editor">Role Editor</button>
  <button class="ua-tab" data-s="staff">Staff Access</button>
</div>

<!-- SCREEN 1 -->
<section class="ua-screen ua-on" id="ua-s-roles">
  <div class="ua-phead">
    <div class="ua-phead-id">
      <span class="ua-phead-ic"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/></svg></span>
      <div class="ua-phead-tx">
        <h2>Departments &amp; Roles</h2>
        <p>Organise roles into departments shared with HR and Staff Import. Every role belongs to exactly one department.</p>
      </div>
    </div>
    <div class="ua-phead-actions">
      <button class="ua-btn ua-ghost" id="ua-addDept">＋ Add department</button>
      <button class="ua-btn" id="ua-newRoleTop">＋ New role</button>
    </div>
  </div>
  <div class="ua-deptsummary" id="ua-deptsummary"></div>
  <div id="ua-deptrender"><div class="ua-empty">Loading catalogue…</div></div>
</section>

<!-- SCREEN 2 -->
<section class="ua-screen" id="ua-s-editor">
  <div class="ua-phead">
    <div class="ua-phead-id">
      <span class="ua-phead-ic"><svg viewBox="0 0 24 24"><line x1="4" y1="7" x2="20" y2="7"/><circle cx="9" cy="7" r="2.5"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="15" cy="17" r="2.5"/></svg></span>
      <div class="ua-phead-tx">
        <h2>Role Editor</h2>
        <p>Set an access level per module for each role — <b>None, View, Edit, or Manage</b>. Changes apply instantly to every staff member holding the role. Ownership items (own payslip &amp; attendance) sit outside roles and stay always on.</p>
      </div>
    </div>
  </div>
  <div class="ua-editor-wrap">
    <div class="ua-rolelist ua-card" id="ua-rolelist"></div>
    <div class="ua-matrix ua-card">
      <div class="ua-mtop"><div><h3 id="ua-ed-name"></h3><p id="ua-ed-desc"></p></div><div class="ua-mactions" id="ua-ed-actions"></div></div>
      <div class="ua-mscroll">
        <table class="ua-perm"><thead><tr><th style="width:44%">Module</th><th>Surface</th><th>Access level</th></tr></thead>
        <tbody id="ua-matrixbody"></tbody></table>
      </div>
    </div>
  </div>
  <div class="ua-callout">Editing a level updates the shared catalogue — any staff holding this role changes instantly on both surfaces. Single source of truth.</div>
</section>

<!-- SCREEN 3 -->
<section class="ua-screen" id="ua-s-staff">
  <div class="ua-subview ua-on" id="ua-sv-list">
    <div class="ua-phead">
      <div class="ua-phead-id">
        <span class="ua-phead-ic"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c0-3 2.5-5.2 5.5-5.2s5.5 2.2 5.5 5.2"/><path d="M16.2 5.4a3.1 3.1 0 0 1 0 5.9"/><path d="M17.4 14.6c2.1.6 3.6 2.5 3.6 4.9"/></svg></span>
        <div class="ua-phead-tx">
          <h2>Staff Access</h2>
          <p>Directory of all teaching and non-teaching staff. Select a person to manage their roles and review effective access.</p>
        </div>
      </div>
      <div class="ua-phead-actions">
        <button class="ua-btn" id="ua-onboardBtn">＋ Onboard staff</button>
      </div>
    </div>
    <div class="ua-dirbar"><div class="ua-search">🔍 <input id="ua-dirsearch" placeholder="Search by name, ID, department or role…"></div></div>
    <div class="ua-card" style="overflow:hidden">
      <table class="ua-dirtable"><thead><tr><th>Staff member</th><th>Department</th><th>Roles</th><th>Access</th></tr></thead>
      <tbody id="ua-dirbody"><tr><td colspan="4" class="ua-empty" style="padding:16px">Loading staff…</td></tr></tbody></table>
    </div>
  </div>
  <div class="ua-subview" id="ua-sv-detail">
    <button class="ua-backlink" id="ua-backBtn">← All staff</button>
    <div class="ua-staff-wrap">
      <div class="ua-panel ua-card">
        <div class="ua-staffcard"><div class="ua-av" id="ua-d-av"></div><div><div class="ua-nm" id="ua-d-nm"></div><div class="ua-id ua-mono" id="ua-d-id"></div></div></div>
        <div class="ua-section-label">Assigned roles <span style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--faint)">— set primary or remove</span></div>
        <div class="ua-rolepills" id="ua-rolepills"></div>
        <div class="ua-addrow"><select id="ua-addrole"></select><button class="ua-mini-btn" id="ua-addrolebtn">＋ Add role</button></div>
        <div class="ua-section-label">Extra access <span style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--faint)">— per-person grant (exception)</span></div>
        <div id="ua-extralist"></div>
        <button class="ua-add-trigger" id="ua-extraTrigger">＋ Add a module for this person <span class="ua-caret">▾</span></button>
        <div class="ua-picker ua-card" id="ua-extraPicker" hidden></div>
        <div class="ua-section-label">Prohibited <span style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--faint)">— absolute deny, wins over every role</span></div>
        <div id="ua-denylist"></div>
        <button class="ua-add-trigger ua-deny" id="ua-denyTrigger">⛔ Prohibit a module for this person <span class="ua-caret">▾</span></button>
        <div class="ua-picker ua-card" id="ua-denyPicker" hidden></div>
      </div>
      <div class="ua-panel ua-card">
        <h3>Effective access <span id="ua-eff-count" class="ua-chip sys"></span></h3>
        <div class="ua-sub">What this person can actually do, from the shared resolver.</div>
        <div class="ua-surface-panel"><div class="ua-sp-head app">📱 Staff App <span class="ua-badge2" id="ua-app-badge"></span></div><div class="ua-tilewrap" id="ua-app-tiles"></div></div>
        <div class="ua-surface-panel"><div class="ua-sp-head web">🖥️ Admin Panel <span class="ua-badge2" id="ua-web-badge"></span></div><div class="ua-tilewrap" id="ua-web-tiles"></div></div>
        <div class="ua-log"><h4>Resolution trace</h4><div id="ua-trace"></div></div>
      </div>
    </div>
    <div class="ua-callout" id="ua-scenario"></div>
  </div>
</section>

<div class="ua-footer-note">
  <div class="ua-legend">
    <span><span class="ua-chip app">app</span></span><span><span class="ua-chip web">web</span></span>
    <span><span class="ua-chip both">both</span></span><span><span class="ua-chip manage">manage</span></span>
    <span><span class="ua-chip edit">edit</span></span><span><span class="ua-chip view">view</span></span>
    <span><span class="ua-chip deny">prohibited</span></span>
  </div>
  <div>ZenXii · Unified Staff Access</div>
</div>

<!-- ROLE MODAL -->
<div class="ua-overlay" id="ua-roleOverlay">
  <div class="ua-modal">
    <div class="ua-mh"><h3 id="ua-rm-title">New role</h3><p>Roles are shared across the admin panel and the staff app. Set modules after creating.</p></div>
    <div class="ua-mb">
      <div class="ua-field"><label>Role name</label><input id="ua-rm-name" placeholder="e.g. Sports Coordinator" maxlength="60"><div class="ua-hint" id="ua-rm-id">Generates ROLE_…</div></div>
      <div class="ua-row2">
        <div class="ua-field"><label>Department</label><select id="ua-rm-dept"></select></div>
        <div class="ua-field"><label>Tier <span style="text-transform:none;letter-spacing:0;font-weight:500;color:var(--faint)">(seniority)</span></label><input id="ua-rm-tier" type="number" min="1" max="9" value="5"></div>
      </div>
      <div class="ua-field"><label>Description</label><textarea id="ua-rm-desc" placeholder="What this role is responsible for…"></textarea></div>
    </div>
    <div class="ua-mf"><button class="ua-btn ua-ghost" id="ua-rm-cancel">Cancel</button><button class="ua-btn" id="ua-rm-save">Create role</button></div>
  </div>
</div>

<!-- STAFF MODAL -->
<div class="ua-overlay" id="ua-staffOverlay">
  <div class="ua-modal">
    <div class="ua-mh"><h3>Onboard staff member</h3><p>Every new person becomes a staff record first, auto-gets the App Baseline role, then you assign their department role.</p></div>
    <div class="ua-mb">
      <div class="ua-field"><label>Full name</label><input id="ua-sm-name" placeholder="e.g. Meera Joshi" maxlength="100"></div>
      <div class="ua-row2">
        <div class="ua-field"><label>Department</label><select id="ua-sm-dept"></select></div>
        <div class="ua-field"><label>Starting role</label><select id="ua-sm-role"></select></div>
      </div>
      <div class="ua-callout" style="margin:4px 0 0"><b>Auto-added:</b> App Baseline (Stories, Events, Communication) + ownership (own payslip, attendance, leave, profile). A temporary password is set — the person resets it on first login.</div>
    </div>
    <div class="ua-mf"><button class="ua-btn ua-ghost" id="ua-sm-cancel">Cancel</button><button class="ua-btn" id="ua-sm-save">Create &amp; open</button></div>
  </div>
</div>
<!-- DEPARTMENT MODAL -->
<div class="ua-overlay" id="ua-deptOverlay">
  <div class="ua-modal">
    <div class="ua-mh"><h3 id="ua-dm-title">New department</h3><p>Departments group roles and place staff. Shared with HR &amp; Staff Import.</p></div>
    <div class="ua-mb">
      <div class="ua-field"><label>Department name</label><input id="ua-dm-name" placeholder="e.g. Science" maxlength="60"></div>
      <div class="ua-field"><label>Description <span style="text-transform:none;letter-spacing:0;font-weight:500;color:var(--faint)">(optional)</span></label><input id="ua-dm-desc" placeholder="What this department covers…"></div>
    </div>
    <div class="ua-mf"><button class="ua-btn ua-ghost" id="ua-dm-cancel">Cancel</button><button class="ua-btn" id="ua-dm-save">Create department</button></div>
  </div>
</div>
<div class="ua-toast" id="ua-toast"></div>
<div class="ua-loadbar" id="ua-loadbar"></div>
<div class="ua-working" id="ua-working"><span class="ua-spin"></span>Working…</div>
</div></div>

<script>
(function(){
  "use strict";
  const BASE="<?= base_url() ?>";
  const $=id=>document.getElementById(id), qsa=s=>Array.from(document.querySelectorAll(s));
  const esc=s=>String(s==null?"":s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
  const LV={none:0,view:1,edit:2,manage:3},LVNAME=["none","view","edit","manage"];
  const maxL=(a,b)=>LVNAME[Math.max(LV[a||"none"],LV[b||"none"])];
  const initials=n=>String(n||"?").split(" ").filter(Boolean).map(w=>w[0]).slice(0,2).join("").toUpperCase();
  function toast(m,err){const t=$("ua-toast");t.textContent=m;t.className="ua-toast ua-show"+(err?" ua-err":"");setTimeout(()=>t.className="ua-toast",1900);}
  let _busy=0;
  function busyStart(){_busy++;const b=$("ua-loadbar"),w=$("ua-working");if(b)b.classList.add("ua-go");if(w)w.classList.add("ua-go");}
  function busyEnd(){_busy=Math.max(0,_busy-1);if(_busy>0)return;const b=$("ua-loadbar"),w=$("ua-working");if(b)b.classList.remove("ua-go");if(w)w.classList.remove("ua-go");}
  async function api(path,body){
    busyStart();
    try{
      const opt={headers:{"X-Requested-With":"XMLHttpRequest"}};
      if(body){opt.method="POST";const f=new URLSearchParams();
        if(typeof csrfName!=="undefined"&&csrfName)f.append(csrfName,csrfToken);
        for(const k in body)f.append(k,body[k]);opt.body=f;}
      const r=await fetch(BASE+path,opt);let j={};try{j=await r.json();}catch(e){}
      if(!r.ok||j.success===false||j.status==="error")throw new Error(j.message||j.error||("HTTP "+r.status));
      return j.data||j;
    } finally { busyEnd(); }
  }

  /* ---------- data (loaded from server, shaped like the prototype) ---------- */
  let MODULES=[], OWNERSHIP=[], DEPTS=[], ROLES={}, STAFF=[], curRole=null, curStaff=null;
  let REALDEPTS=[], ROLECATS=["Teaching","Non-Teaching","Administrative","Support"];
  const surfaceOf=m=>{const r=MODULES.find(x=>x[1]===m);return r?r[2]:"web";};
  const surfChip=s=>`<span class="ua-chip ${s}">${s}</span>`, lvlChip=l=>`<span class="ua-chip ${l}">${l}</span>`;

  function buildFromCatalogue(cat){
    MODULES=(cat.modules||[]).map(m=>[m.group,m.name,m.surface]);
    OWNERSHIP=cat.ownership||[];
    ROLES={};
    (cat.roles||[]).forEach(r=>{
      const perms={}; (r.permissions||[]).forEach(m=>{perms[m]=(r.permissionLevels&&r.permissionLevels[m])||"manage";});
      ROLES[r.id]={label:r.label,dept:(r.department||r.category||"Administrative"),tier:r.tier,desc:r.description,sys:r.is_system,custom:!r.is_system,auto:r.auto_assign,perms:perms};
    });
    // Departments (configurable) = the SINGLE grouping (Tab 1) + staff placement (onboard).
    REALDEPTS=Array.isArray(cat.departments)?cat.departments:[];
    DEPTS=REALDEPTS.map(d=>[d.name,d.name,false]);   // grouping keys = department names, in sort order
  }
  const deptName=k=>{const d=DEPTS.find(x=>x[1]===k);return d?d[0]:k;};

  async function load(){
    let cat;
    try{ cat=await api("staff_access/get_catalogue"); }
    catch(e){ $("ua-deptrender").innerHTML='<div class="ua-empty">Could not load catalogue: '+esc(e.message)+'</div>'; return; }
    buildFromCatalogue(cat);
    const first=Object.keys(ROLES).find(k=>!ROLES[k].sys)||Object.keys(ROLES)[0]||null;
    if(first)loadRoleIntoEditor(first);
    renderDepts(); renderRoles(); renderRoleList(); renderMatrix();
  }
  let dirLoaded=false;
  async function loadDirectory(){
    try{ const d=await api("staff_access/get_staff_directory"); STAFF=d.staff||[]; dirLoaded=true; renderDirectory(); }
    catch(e){ $("ua-dirbody").innerHTML='<tr><td colspan="4" class="ua-empty" style="padding:16px">Could not load staff: '+esc(e.message)+'</td></tr>'; }
  }

  /* ---------- SCREEN 1 ---------- */
  function renderRoleCard(id,r){
    const mods=Object.keys(r.perms);
    const app=mods.filter(m=>surfaceOf(m)!=="web").length,web=mods.filter(m=>surfaceOf(m)!=="app").length;
    const c=document.createElement("div");c.className="ua-rolecard ua-card";
    c.innerHTML=`<div class="ua-rc-top"><div><h4>${esc(r.label)}</h4><div class="ua-rid ua-mono">${esc(id)}</div></div>
      ${r.sys?'<span class="ua-badge-sys">SYSTEM</span>':'<span class="ua-badge-custom">CUSTOM</span>'}</div>
      <div class="ua-rc-meta">${r.auto?'<span class="ua-chip sys">auto-assigned</span>':`<span class="ua-chip sys">tier ${r.tier}</span>`}
        ${app?`<span class="ua-chip app">${app} app</span>`:''}${web?`<span class="ua-chip web">${web} web</span>`:''}
        ${!mods.length?'<span class="ua-chip mut">baseline only</span>':''}</div>
      <div class="ua-modline">${mods.length?mods.map(esc).join(" · "):"No module grants — relies on baseline"}</div>
      <div class="ua-rc-drag" title="Drag to move to another department"><i class="fa fa-arrows"></i></div>`;
    c.onclick=e=>{ if(c.classList.contains("ua-dragging"))return; selectRole(id);switchTab("editor"); };
    // Drag source — move this role into a different department (drop on any section).
    c.setAttribute("draggable","true");c.dataset.roleId=id;
    c.addEventListener("dragstart",e=>{e.dataTransfer.setData("text/plain",id);e.dataTransfer.effectAllowed="move";c.classList.add("ua-dragging");document.getElementById("ua-deptrender").classList.add("ua-dnd-on");});
    c.addEventListener("dragend",()=>{c.classList.remove("ua-dragging");const h=document.getElementById("ua-deptrender");if(h)h.classList.remove("ua-dnd-on");});
    return c;
  }
  // Move a role to another department (drag-drop). Empty dept = Unassigned. Optimistic-safe:
  // relies on server truth (returns fresh role + departments) then re-renders.
  async function moveRoleTo(rid,deptName){
    if(!ROLES[rid])return;
    if(deptKey(ROLES[rid].dept||"")===deptKey(deptName||""))return; // same place — no-op
    try{
      const res=await api("staff_access/move_role",{role_id:rid,department:deptName||""});
      ROLES[rid].dept=(res&&res.role&&res.role.department)||deptName||"";
      if(res&&Array.isArray(res.departments)){REALDEPTS=res.departments;DEPTS=REALDEPTS.map(d=>[d.name,d.name,false]);}
      renderRoles();renderRoleList();
      toast(deptName?("Moved to "+deptName):"Moved to Unassigned");
    }catch(e){ toast(e.message||"Could not move role",true); }
  }
  // One self-contained section per department: identity + inline controls in the header,
  // role cards (plus an inline "new role") in the body. `unassigned` renders the synthetic
  // amber bucket with no reorder/rename/delete (it isn't a real department).
  function deptSection({name,desc,rs,dept,index,unassigned}){
    const isSys=!!(dept&&dept.is_system);
    const sec=document.createElement("div");sec.className="ua-dept"+(unassigned?" ua-dept-un":"");
    const head=document.createElement("div");head.className="ua-dept-head";
    const badge=unassigned?"?":((name.trim()[0])||"·");
    head.innerHTML=`<div class="ua-dept-id">
        <div class="ua-dept-badge">${esc(badge)}</div>
        <div class="ua-dept-meta">
          <div class="ua-dept-name">${esc(name)}<span class="ua-count">${rs.length} role${rs.length===1?"":"s"}</span>${isSys?'<span class="ua-badge-sys" title="System department — cannot be deleted">SYSTEM</span>':''}</div>
          <div class="ua-dept-sub">${esc(desc||(unassigned?"Roles with no department — assign each below":"App + web role grouping"))}</div>
        </div></div>`;
    if(!unassigned){
      const tools=document.createElement("div");tools.className="ua-dept-tools";
      const mk=(icon,title,fn,dis,extra)=>{const b=document.createElement("button");b.className="ua-icbtn"+(extra||"");b.title=title;b.setAttribute("aria-label",title);b.innerHTML='<i class="fa '+icon+'"></i>';if(dis)b.disabled=true;else b.onclick=fn;return b;};
      tools.appendChild(mk("fa-arrow-up","Move up",()=>moveDept(index,-1),index===0));
      tools.appendChild(mk("fa-arrow-down","Move down",()=>moveDept(index,1),index===REALDEPTS.length-1));
      tools.appendChild(mk("fa-pencil","Rename department",()=>openDeptModal(dept)));
      // System departments (canonical categories) can never be deleted — the button
      // is disabled with a lock, mirroring how system ROLES are protected.
      tools.appendChild(isSys
        ? mk("fa-lock","System department — cannot be deleted",null,true)
        : mk("fa-trash-o","Delete department",()=>delDept(dept),false," ua-icbtn-d"));
      head.appendChild(tools);
    }
    sec.appendChild(head);
    // Whole section is a drop target — dropping a role card here re-files it into
    // this department (or Unassigned for the synthetic bucket).
    const dropName=unassigned?"":name;
    sec.addEventListener("dragover",e=>{e.preventDefault();e.dataTransfer.dropEffect="move";sec.classList.add("ua-drop-hover");});
    sec.addEventListener("dragleave",e=>{if(!sec.contains(e.relatedTarget))sec.classList.remove("ua-drop-hover");});
    sec.addEventListener("drop",e=>{e.preventDefault();sec.classList.remove("ua-drop-hover");const rid=e.dataTransfer.getData("text/plain");if(rid)moveRoleTo(rid,dropName);});
    const body=document.createElement("div");body.className="ua-dept-body";
    if(unassigned){const h=document.createElement("div");h.className="ua-dept-hint";h.innerHTML="⚠&nbsp; These roles aren't in any department. Open a role and choose its department to file it away.";body.appendChild(h);}
    const g=document.createElement("div");g.className="ua-rolegrid";
    rs.forEach(([id,r])=>g.appendChild(renderRoleCard(id,r)));
    if(!unassigned){const nc=document.createElement("div");nc.className="ua-rolecard ua-card ua-newrole-card";
      nc.innerHTML=`<i class="fa fa-plus ua-plus"></i>New role in ${esc(name)}`;
      nc.onclick=()=>openRoleModal(null,name);g.appendChild(nc);}
    body.appendChild(g);sec.appendChild(body);return sec;
  }
  function renderSummary(nDepts,nRoles,nUn){
    const el=$("ua-deptsummary");if(!el)return;
    el.innerHTML=`<span><b>${nDepts}</b> department${nDepts===1?"":"s"}</span><span class="ua-dot"></span>`
      +`<span><b>${nRoles}</b> role${nRoles===1?"":"s"}</span>`
      +(nUn?`<span class="ua-dot"></span><span style="color:var(--edit)"><b style="color:var(--edit)">${nUn}</b> unassigned</span>`:"");
  }
  function renderRoles(){
    const host=$("ua-deptrender");host.innerHTML="";
    const seen=new Set();
    REALDEPTS.forEach((d,i)=>{
      const rs=Object.entries(ROLES).filter(([id,r])=>deptKey(r.dept)===deptKey(d.name));
      rs.forEach(([id])=>seen.add(id));
      host.appendChild(deptSection({name:d.name,desc:d.description,rs,dept:d,index:i}));
    });
    const un=Object.entries(ROLES).filter(([id])=>!seen.has(id));
    if(un.length)host.appendChild(deptSection({name:"Unassigned",rs:un,unassigned:true}));
    if(!REALDEPTS.length&&!un.length)host.innerHTML='<div class="ua-empty" style="padding:34px;text-align:center;color:var(--muted)">No departments yet — click <b>Add department</b> to start grouping roles.</div>';
    renderSummary(REALDEPTS.length,Object.keys(ROLES).length,un.length);
  }

  /* ---------- SCREEN 2 ---------- */
  function renderRoleList(){
    const host=$("ua-rolelist");host.innerHTML="";
    const seen=new Set();
    const group=(dname,rs)=>{
      if(!rs.length)return;
      const t=document.createElement("div");t.className="ua-grouptag";t.textContent=dname;host.appendChild(t);
      rs.forEach(([id,r])=>{seen.add(id);const b=document.createElement("button");b.className="ua-rl"+(id===curRole?" ua-on":"");
        b.innerHTML=`${esc(r.label)}<small class="ua-mono">${esc(id)}</small>`;b.onclick=()=>selectRole(id);host.appendChild(b);});
    };
    DEPTS.forEach(([dname,dkey])=>group(dname,Object.entries(ROLES).filter(([id,r])=>deptKey(r.dept)===deptKey(dkey))));
    group("Unassigned",Object.entries(ROLES).filter(([id])=>!seen.has(id)));
    const nb=document.createElement("button");nb.className="ua-newbtn";nb.textContent="＋ New custom role";
    nb.onclick=()=>openRoleModal();host.appendChild(nb);
  }
  // Matrix edits are STAGED locally; nothing persists until "Save changes".
  let staged={}, matrixDirty=false;
  function loadRoleIntoEditor(id){curRole=id;staged=Object.assign({},ROLES[id]?ROLES[id].perms:{});matrixDirty=false;}
  function selectRole(id){
    if(matrixDirty && id!==curRole && !confirm("Discard unsaved level changes to this role?"))return;
    loadRoleIntoEditor(id);renderRoleList();renderMatrix();
  }
  function matrixIsDirty(){const saved=ROLES[curRole]?ROLES[curRole].perms:{};const a=Object.keys(staged),b=Object.keys(saved);
    if(a.length!==b.length)return true;for(const k of a)if(staged[k]!==saved[k])return true;return false;}
  function renderMatrixActions(){
    const r=ROLES[curRole];const acts=$("ua-ed-actions");acts.innerHTML="";if(!r)return;
    if(matrixDirty){
      const s=document.createElement("button");s.className="ua-btn";s.textContent="Save changes";s.onclick=saveMatrix;acts.appendChild(s);
      const rv=document.createElement("button");rv.className="ua-btn ua-ghost";rv.textContent="Revert";rv.onclick=()=>{loadRoleIntoEditor(curRole);renderMatrix();};acts.appendChild(rv);
      const n=document.createElement("span");n.className="ua-chip edit";n.style.marginLeft="4px";n.textContent="unsaved changes";acts.appendChild(n);return;
    }
    if(!r.sys){const e=document.createElement("button");e.className="ua-btn ua-ghost";e.textContent="✎ Details";e.onclick=()=>openRoleModal(curRole);acts.appendChild(e);
      const d=document.createElement("button");d.className="ua-btn ua-danger";d.textContent="Delete";d.onclick=()=>deleteRole(curRole);acts.appendChild(d);}
    else acts.innerHTML='<span class="ua-chip sys">system role · levels editable</span>';
  }
  function renderMatrix(){
    const r=ROLES[curRole];if(!r){$("ua-ed-name").textContent="—";$("ua-ed-desc").textContent="";$("ua-matrixbody").innerHTML="";$("ua-ed-actions").innerHTML="";return;}
    $("ua-ed-name").innerHTML=`${esc(r.label)} ${r.sys?'<span class="ua-badge-sys">SYSTEM</span>':'<span class="ua-badge-custom">CUSTOM</span>'}`;
    $("ua-ed-desc").textContent=r.desc||"";
    renderMatrixActions();
    const body=$("ua-matrixbody");body.innerHTML="";let curg=null;const baseline=curRole==="ROLE_BASELINE_APP";
    MODULES.forEach(([grp,name,surf])=>{
      if(grp!==curg){curg=grp;const gr=document.createElement("tr");gr.className="ua-grouprow";gr.innerHTML=`<td colspan="3">${esc(grp)}</td>`;body.appendChild(gr);}
      const lvl=staged[name]||"none";const tr=document.createElement("tr");
      tr.innerHTML=`<td><span class="ua-modname">${esc(name)}</span></td><td>${surfChip(surf)}</td>
        <td><div class="ua-seg" data-mod="${esc(name)}">${["none","view","edit","manage"].map(l=>`<button data-l="${l}" class="${l===lvl?'ua-on':''}"${baseline?" disabled":""}>${l==='none'?'—':l[0].toUpperCase()+l.slice(1)}</button>`).join("")}</div></td>`;
      body.appendChild(tr);
    });
    if(!baseline)qsa("#ua-matrixbody .ua-seg button").forEach(b=>b.onclick=()=>{
      const seg=b.closest(".ua-seg"),mod=seg.dataset.mod,l=b.dataset.l;
      seg.querySelectorAll("button").forEach(x=>x.classList.remove("ua-on"));b.classList.add("ua-on");
      if(l==="none")delete staged[mod];else staged[mod]=l;
      matrixDirty=matrixIsDirty();renderMatrixActions();
    });
  }
  async function saveMatrix(){
    const btn=$("ua-ed-actions").querySelector(".ua-btn");if(btn){btn.disabled=true;btn.textContent="Saving…";}
    try{ const res=await api("staff_access/save_role_access",{role_id:curRole,levels:JSON.stringify(staged)});
      const rr=res.role;const perms={};(rr.permissions||[]).forEach(m=>perms[m]=(rr.permissionLevels&&rr.permissionLevels[m])||"manage");
      ROLES[curRole].perms=perms;staged=Object.assign({},perms);matrixDirty=false;
      renderRoles();renderRoleList();renderMatrix();toast("Role access saved"); }
    catch(e){ toast(e.message,true);renderMatrixActions(); }
  }
  async function deleteRole(id){
    if(!confirm('Delete role "'+(ROLES[id]?.label||id)+'"? This cannot be undone.'))return;
    try{ await api("staff_access/delete_role",{role_id:id}); delete ROLES[id];
      const next=Object.keys(ROLES).find(k=>!ROLES[k].sys)||Object.keys(ROLES)[0]||null;
      if(next)loadRoleIntoEditor(next);else{curRole=null;staged={};matrixDirty=false;}
      renderRoles();renderRoleList();renderMatrix();toast("Role deleted"); }
    catch(e){ toast(e.message,true); }
  }

  /* ---------- SCREEN 3a directory ---------- */
  function renderDirectory(){
    const q=($("ua-dirsearch").value||"").toLowerCase();
    const body=$("ua-dirbody");body.innerHTML="";
    const rows=STAFF.filter(s=>{const hay=(s.name+" "+s.id+" "+(s.department||"")+" "+(s.roles||[]).map(r=>ROLES[r]?.label||r).join(" ")).toLowerCase();return hay.includes(q);});
    if(!rows.length){body.innerHTML='<tr><td colspan="4" class="ua-empty" style="padding:16px">'+(STAFF.length?"No staff match.":"No staff yet.")+'</td></tr>';return;}
    rows.forEach(s=>{
      const web=(s.modules||[]).filter(m=>surfaceOf(m)!=="app").length;
      const rchips=(s.roles||[]).filter(r=>!ROLES[r]?.auto).map(r=>`<span class="ua-rchip">${esc(ROLES[r]?.label||r)}</span>`).join("")||`<span class="ua-rchip">baseline only</span>`;
      const tr=document.createElement("tr");tr.className="ua-dirrow";tr.tabIndex=0;tr.setAttribute("role","button");tr.setAttribute("aria-label","Open "+s.name);tr.onclick=()=>openStaff(s.id);tr.onkeydown=(e)=>{if(e.key==="Enter"||e.key===" "){e.preventDefault();openStaff(s.id);}};
      tr.innerHTML=`<td><div class="ua-who"><div class="ua-av">${esc(initials(s.name))}</div><div><div class="ua-nm">${esc(s.name)}</div><div class="ua-id ua-mono">${esc(s.id)}</div></div></div></td>
        <td>${esc(s.department)||'<span class="ua-empty">—</span>'}</td><td><div class="ua-rchips">${rchips}</div></td>
        <td><div class="ua-surf-badges"><span class="ua-chip app">app</span>${web?'<span class="ua-chip web">panel</span>':'<span class="ua-chip mut">app-only</span>'}</div></td>`;
      body.appendChild(tr);
    });
  }
  async function openStaff(id){
    let d;try{ d=await api("staff_access/get_staff_detail/"+encodeURIComponent(id)); }catch(e){ toast(e.message,true); return; }
    curStaff=d; closePickers();
    $("ua-sv-list").classList.remove("ua-on");$("ua-sv-detail").classList.add("ua-on");renderStaffDetail();
  }
  function backToList(){closePickers();$("ua-sv-detail").classList.remove("ua-on");$("ua-sv-list").classList.add("ua-on");loadDirectory();}
  function applyDetail(d){curStaff.roles=d.roles;curStaff.primary=d.primary;curStaff.extra=d.extra;curStaff.deny=d.deny;curStaff.effective=d.effective;renderStaffDetail();}

  /* ---------- SCREEN 3b detail ---------- */
  function renderStaffDetail(){
    const st=curStaff;if(!st)return;const info=st.staff||{};
    $("ua-d-av").textContent=initials(info.name);$("ua-d-nm").textContent=info.name;
    $("ua-d-id").textContent=`${info.id} · ${info.department||"—"}`;
    const pills=$("ua-rolepills");pills.innerHTML="";
    (st.roles||[]).forEach(id=>{
      const r=ROLES[id];const auto=id==="ROLE_BASELINE_APP"||(r&&r.auto);const isPrim=id===st.primary;
      const row=document.createElement("div");row.className="ua-rolerow"+(isPrim&&!auto?" ua-prim":"");
      const badge=auto?'<span class="ua-rr-badge auto">AUTO</span>':(isPrim?'<span class="ua-rr-badge prim">PRIMARY</span>':'');
      row.innerHTML=`<div class="ua-rr-main">
          <div class="ua-rr-name">${esc(r?r.label:id)} ${badge}</div>
          <div class="ua-rr-sub">${r?("tier "+r.tier+" · "+esc(deptName(r.dept))):esc(id)}</div>
        </div>`;
      const act=document.createElement("div");act.className="ua-rr-actions";
      if(auto){act.innerHTML='<span class="ua-rr-locked">🔒 auto-assigned</span>';}
      else{
        if(!isPrim){const mk=document.createElement("button");mk.className="ua-rr-btn";mk.textContent="★ Make primary";mk.onclick=()=>setPrimary(id);act.appendChild(mk);}
        const rm=document.createElement("button");rm.className="ua-rr-btn rm";rm.textContent="✕ Remove";rm.title="Remove this role";rm.onclick=()=>toggleRole(id,false);act.appendChild(rm);
      }
      row.appendChild(act);pills.appendChild(row);
    });
    const addSel=$("ua-addrole");addSel.innerHTML="";
    Object.entries(ROLES).filter(([id,r])=>!(st.roles||[]).includes(id)&&!r.auto).forEach(([id,r])=>{const o=document.createElement("option");o.value=id;o.textContent=r.label+" · "+deptName(r.dept);addSel.appendChild(o);});
    addSel.parentElement.style.display=addSel.options.length?"flex":"none";
    // extra
    const el=$("ua-extralist");el.innerHTML="";const ek=Object.keys(st.extra||{});
    if(!ek.length)el.innerHTML='<div class="ua-empty">None — access comes entirely from the roles above.</div>';
    ek.forEach(m=>{const g=st.extra[m]||{};const d=document.createElement("div");d.className="ua-ovr";
      d.innerHTML=`<div class="ua-ovr-main">
          <div class="ua-ovr-top">${surfChip(surfaceOf(m))} <b>${esc(m)}</b> ${lvlChip(g.level||"view")}</div>
          <div class="ua-ovr-audit">granted by ${esc(g.by||"—")}${g.at?" · "+esc(g.at):""}${g.reason?` · <i>“${esc(g.reason)}”</i>`:""}</div>
        </div>
        <button class="ua-ovr-rm" title="Remove this extra grant">✕ Remove</button>`;
      d.querySelector(".ua-ovr-rm").onclick=()=>removeExtra(m);el.appendChild(d);});
    // deny
    const dl=$("ua-denylist");dl.innerHTML="";const dk=Object.keys(st.deny||{});
    if(!dk.length)dl.innerHTML='<div class="ua-empty">None — nothing is blocked for this person.</div>';
    dk.forEach(m=>{const den=st.deny[m]||{};const d=document.createElement("div");d.className="ua-ovr deny";
      d.innerHTML=`<div class="ua-ovr-main">
          <div class="ua-ovr-top">${surfChip(surfaceOf(m))} <b>${esc(m)}</b> <span class="ua-chip deny">prohibited</span></div>
          <div class="ua-ovr-audit">by ${esc(den.by||"—")}${den.at?" · "+esc(den.at):""}${den.reason?` · <i>“${esc(den.reason)}”</i>`:""}</div>
        </div>
        <button class="ua-ovr-rm" title="Lift this prohibition">✓ Allow again</button>`;
      d.querySelector(".ua-ovr-rm").onclick=()=>removeDeny(m);dl.appendChild(d);});
    renderEffective(st);
  }
  function computeTrace(st){
    const trace=[];(st.roles||[]).forEach(id=>{const r=ROLES[id];if(!r)return;for(const m in r.perms)trace.push({m,lvl:r.perms[m],src:r.label});});
    for(const m in (st.extra||{}))trace.push({m,lvl:(st.extra[m].level||"view"),src:"extra grant"});
    return trace;
  }
  function renderEffective(st){
    const acc={};const eff=st.effective||{modules:[],levels:{}};(eff.modules||[]).forEach(m=>acc[m]=eff.levels[m]||"manage");
    const killed=Object.keys(st.deny||{});const trace=computeTrace(st);
    const mods=Object.keys(acc);const appMods=mods.filter(m=>surfaceOf(m)!=="web").sort(),webMods=mods.filter(m=>surfaceOf(m)!=="app").sort();
    const tile=(m,l)=>`<span class="ua-tile">${esc(m)}<span class="ua-lvl ua-chip ${l}">${l}</span></span>`;
    const at=$("ua-app-tiles");at.innerHTML="";
    OWNERSHIP.forEach(o=>at.innerHTML+=`<span class="ua-tile ua-own"><span class="ua-pin">📌</span>${esc(o)}</span>`);
    appMods.forEach(m=>at.innerHTML+=tile(m,acc[m]));
    $("ua-app-badge").textContent=(appMods.length+OWNERSHIP.length)+" tiles";
    const wt=$("ua-web-tiles");wt.innerHTML="";
    if(!webMods.length){wt.innerHTML='<div class="ua-noaccess">⛔ No admin-panel access — this staff cannot log into the panel. They still use the app.</div>';$("ua-web-badge").textContent="no access";}
    else{webMods.forEach(m=>wt.innerHTML+=tile(m,acc[m]));$("ua-web-badge").textContent=webMods.length+" menus";}
    $("ua-eff-count").textContent=`${mods.length} modules · ${(st.roles||[]).length} roles`;
    const tr=$("ua-trace");tr.innerHTML="";
    killed.forEach(m=>tr.innerHTML+=`<div class="ua-logrow ua-killed"><span class="ua-lm">${esc(m)}</span><span class="ua-arrow">✕ prohibited — overrides all roles</span></div>`);
    mods.forEach(m=>{const srcs=trace.filter(t=>t.m===m);const win=srcs.reduce((a,b)=>!a||LV[b.lvl]>LV[a.lvl]?b:a,null);
      tr.innerHTML+=`<div class="ua-logrow"><span class="ua-lm">${esc(m)}</span><span>${lvlChip(acc[m])}</span><span class="ua-arrow">← ${esc(win?win.src:"—")}${srcs.length>1?` (+${srcs.length-1} more)`:""}</span></div>`;});
    const sc=$("ua-scenario");const assigned=(st.roles||[]).filter(r=>!ROLES[r]?.auto&&r!=="ROLE_BASELINE_APP").length;
    const bits=[`<b>${assigned} assigned role(s)</b> + auto baseline`];
    if(killed.length)bits.push(`<b>${killed.map(esc).join(", ")}</b> prohibited (deny wins over roles)`);
    if(Object.keys(st.extra||{}).length)bits.push(`extra grant of <b>${Object.keys(st.extra).map(esc).join(", ")}</b>`);
    bits.push(webMods.length?`logs into <b>both</b> app &amp; panel`:`<b>app-only</b>, no panel`);
    sc.innerHTML=`${esc(info_name(st))} has `+bits.join("; ")+`. Toggle a role, prohibit a granted module, or grant one no role has — the split updates live.`;
  }
  const info_name=st=>(st.staff&&st.staff.name)||"This person";

  /* ---------- mutations ---------- */
  async function toggleRole(id,on){try{applyDetail(await api("staff_access/toggle_staff_role",{staff_id:curStaff.staff.id,role_id:id,on:on?"1":"0"}));toast(on?"Role added":"Role removed");}catch(e){toast(e.message,true);}}
  async function setPrimary(id){try{applyDetail(await api("staff_access/set_primary_role",{staff_id:curStaff.staff.id,role_id:id}));toast("Primary role updated");}catch(e){toast(e.message,true);}}
  async function removeExtra(m){try{applyDetail(await api("staff_access/remove_extra",{staff_id:curStaff.staff.id,module:m}));toast("Grant removed");}catch(e){toast(e.message,true);}}
  async function removeDeny(m){try{applyDetail(await api("staff_access/remove_deny",{staff_id:curStaff.staff.id,module:m}));toast("Prohibition removed");}catch(e){toast(e.message,true);}}
  $("ua-addrolebtn").onclick=()=>{const v=$("ua-addrole").value;if(v)toggleRole(v,true);};

  /* ---------- role modal (Department = configurable) ---------- */
  const deptKey=s=>String(s==null?"":s).trim().toLowerCase();
  function fillRoleDeptSelect(sel,current){sel.innerHTML="";
    if(!REALDEPTS.length){const o=document.createElement("option");o.value="";o.textContent="(add a department first)";sel.appendChild(o);return;}
    REALDEPTS.forEach(d=>{const o=document.createElement("option");o.value=d.name;o.textContent=d.name;if(deptKey(d.name)===deptKey(current))o.selected=true;sel.appendChild(o);});}
  let editingRole=null;
  function openRoleModal(id,presetDept){
    editingRole=id||null;
    if(id){const r=ROLES[id];$("ua-rm-title").textContent="Edit role";$("ua-rm-name").value=r.label;
      fillRoleDeptSelect($("ua-rm-dept"),r.dept);$("ua-rm-tier").value=r.tier;$("ua-rm-desc").value=r.desc||"";$("ua-rm-id").textContent=id;$("ua-rm-save").textContent="Save changes";}
    else{$("ua-rm-title").textContent="New role";$("ua-rm-name").value="";
      fillRoleDeptSelect($("ua-rm-dept"),presetDept||(REALDEPTS[0]&&REALDEPTS[0].name));$("ua-rm-tier").value=5;$("ua-rm-desc").value="";$("ua-rm-id").textContent="Generates ROLE_…";$("ua-rm-save").textContent="Create role";}
    $("ua-roleOverlay").classList.add("ua-open");$("ua-rm-name").focus();
  }
  $("ua-rm-name").oninput=e=>{if(!editingRole)$("ua-rm-id").textContent="ROLE_"+(e.target.value.trim().toUpperCase().replace(/[^A-Z0-9]+/g,"_").replace(/^_|_$/g,"")||"…");};
  function closeRole(){$("ua-roleOverlay").classList.remove("ua-open");}
  $("ua-rm-cancel").onclick=closeRole;
  $("ua-rm-save").onclick=async()=>{
    const name=$("ua-rm-name").value.trim();if(!name){toast("Role name is required",true);return;}
    const dept=$("ua-rm-dept").value;if(!dept){toast("Add a department first (Departments panel above)",true);return;}
    const btn=$("ua-rm-save");const orig=btn.textContent;btn.disabled=true;btn.textContent="Saving…";
    try{ const res=await api("staff_access/save_role",{role_id:editingRole||"",label:name,department:dept,tier:$("ua-rm-tier").value,description:$("ua-rm-desc").value.trim()});
      const rr=res.role;const perms={};(rr.permissions||[]).forEach(m=>perms[m]=(rr.permissionLevels&&rr.permissionLevels[m])||"manage");
      ROLES[rr.id]={label:rr.label,dept:(rr.department||rr.category),tier:rr.tier,desc:rr.description,sys:rr.is_system,custom:!rr.is_system,auto:rr.auto_assign,perms:perms};
      closeRole();loadRoleIntoEditor(rr.id);renderDepts();renderRoles();renderRoleList();renderMatrix();toast("Role saved"); }
    catch(e){ toast(e.message,true); }
    finally{ btn.disabled=false;btn.textContent=orig; }
  };

  /* ---------- departments manager (Tab 1) ----------
     Departments now render inline as section headers within renderRoles(); this alias
     keeps every existing caller (moveDept/delDept/save handlers/load) working. */
  function renderDepts(){ renderRoles(); }
  async function moveDept(i,dir){
    const j=i+dir;if(j<0||j>=REALDEPTS.length)return;
    const t=REALDEPTS[i];REALDEPTS[i]=REALDEPTS[j];REALDEPTS[j]=t;
    DEPTS=REALDEPTS.map(d=>[d.name,d.name,false]);renderDepts();renderRoles();renderRoleList();
    try{ await api("staff_access/reorder_departments",{order:JSON.stringify(REALDEPTS.map(d=>d.id))}); }
    catch(e){
      // Roll back the optimistic swap so the UI and server don't silently diverge.
      const b=REALDEPTS[i];REALDEPTS[i]=REALDEPTS[j];REALDEPTS[j]=b;
      DEPTS=REALDEPTS.map(d=>[d.name,d.name,false]);renderDepts();renderRoles();renderRoleList();
      toast(e.message,true);
    }
  }
  async function delDept(d){
    const count=Object.values(ROLES).filter(r=>deptKey(r.dept)===deptKey(d.name)).length;
    if(count>0){toast(`Move its ${count} role(s) to another department first`,true);return;}
    if(!confirm('Delete department "'+d.name+'"? This cannot be undone.'))return;
    try{ await api("staff_access/delete_department",{dept_id:d.id});
      REALDEPTS=REALDEPTS.filter(x=>x.id!==d.id);DEPTS=REALDEPTS.map(x=>[x.name,x.name,false]);
      renderDepts();renderRoles();renderRoleList();toast("Department deleted"); }
    catch(e){ toast(e.message,true); }
  }
  let editingDept=null;
  function openDeptModal(d){editingDept=d||null;
    $("ua-dm-title").textContent=d?"Rename department":"New department";
    $("ua-dm-name").value=d?d.name:"";$("ua-dm-desc").value=d?(d.description||""):"";
    $("ua-dm-save").textContent=d?"Save changes":"Create department";
    $("ua-deptOverlay").classList.add("ua-open");$("ua-dm-name").focus();}
  $("ua-dm-cancel").onclick=()=>$("ua-deptOverlay").classList.remove("ua-open");
  $("ua-deptOverlay").onclick=e=>{if(e.target===$("ua-deptOverlay"))$("ua-deptOverlay").classList.remove("ua-open");};
  $("ua-addDept").onclick=()=>openDeptModal();
  $("ua-dm-save").onclick=async()=>{
    const name=$("ua-dm-name").value.trim();if(!name){toast("Department name is required",true);return;}
    const btn=$("ua-dm-save");const orig=btn.textContent;btn.disabled=true;btn.textContent="Saving…";
    try{ const res=await api("staff_access/save_department",{dept_id:editingDept?editingDept.id:"",name:name,description:$("ua-dm-desc").value.trim()});
      const nd=res.department;
      if(editingDept){const old=editingDept.name;const idx=REALDEPTS.findIndex(x=>x.id===nd.id);if(idx>=0)REALDEPTS[idx]=nd;
        Object.values(ROLES).forEach(r=>{if(deptKey(r.dept)===deptKey(old))r.dept=nd.name;});}
      else REALDEPTS.push(nd);
      DEPTS=REALDEPTS.map(x=>[x.name,x.name,false]);
      $("ua-deptOverlay").classList.remove("ua-open");
      renderDepts();renderRoles();renderRoleList();renderMatrix();toast("Department saved"); }
    catch(e){ toast(e.message,true); }
    finally{ btn.disabled=false;btn.textContent=orig; }
  };

  /* ---------- staff modal (Department = real schools.departments; role filtered by dept.role_ids) ---------- */
  function fillRealDeptSelect(sel){sel.innerHTML="";
    if(!REALDEPTS.length){const o=document.createElement("option");o.value="";o.textContent="(no departments configured — add them in Departments & Roles)";sel.appendChild(o);return;}
    REALDEPTS.forEach(d=>{const o=document.createElement("option");o.value=d.name;o.textContent=d.name;sel.appendChild(o);});}
  function fillStaffRoleSelect(){
    const dep=REALDEPTS.find(d=>d.name===$("ua-sm-dept").value);
    const allowed=(dep&&Array.isArray(dep.role_ids)&&dep.role_ids.length)?new Set(dep.role_ids):null;
    const sel=$("ua-sm-role");sel.innerHTML="";
    Object.entries(ROLES).filter(([id,r])=>!r.auto&&!r.sys&&(!allowed||allowed.has(id))).forEach(([id,r])=>{const o=document.createElement("option");o.value=id;o.textContent=r.label;sel.appendChild(o);});
    if(!sel.options.length){const o=document.createElement("option");o.value="";o.textContent=allowed?"(no roles assigned to this department — baseline only)":"(no custom role — baseline only)";sel.appendChild(o);}}
  function openStaffModal(){fillRealDeptSelect($("ua-sm-dept"));$("ua-sm-name").value="";fillStaffRoleSelect();$("ua-staffOverlay").classList.add("ua-open");$("ua-sm-name").focus();}
  $("ua-sm-dept").onchange=fillStaffRoleSelect;
  $("ua-sm-cancel").onclick=()=>$("ua-staffOverlay").classList.remove("ua-open");
  $("ua-sm-save").onclick=async()=>{
    const name=$("ua-sm-name").value.trim();if(!name){toast("Full name is required",true);return;}
    const btn=$("ua-sm-save");const orig=btn.textContent;btn.disabled=true;btn.textContent="Creating…";
    try{ const res=await api("staff_access/onboard_staff",{name,department:$("ua-sm-dept").value,role_id:$("ua-sm-role").value});
      $("ua-staffOverlay").classList.remove("ua-open");toast("Staff onboarded");dirLoaded=false;openStaff(res.staff_id); }
    catch(e){ toast(e.message,true); }
    finally{ btn.disabled=false;btn.textContent=orig; }
  };

  /* ---------- module picker ---------- */
  let pk={mode:null,mod:null,surf:null,level:"view"};
  function closePickers(){$("ua-extraPicker").hidden=true;$("ua-denyPicker").hidden=true;$("ua-extraTrigger").classList.remove("ua-open");$("ua-denyTrigger").classList.remove("ua-open");pk={mode:null,mod:null,surf:null,level:"view"};}
  function openPicker(mode){
    const pane=mode==="grant"?$("ua-extraPicker"):$("ua-denyPicker"),trig=mode==="grant"?$("ua-extraTrigger"):$("ua-denyTrigger");
    const wasOpen=!pane.hidden;closePickers();if(wasOpen)return;
    pk={mode,mod:null,surf:null,level:"view"};pane.hidden=false;trig.classList.add("ua-open");renderPicker(pane,mode);
  }
  function renderPicker(pane,mode){
    const acc={};(curStaff.effective.modules||[]).forEach(m=>acc[m]=curStaff.effective.levels[m]||"manage");
    const denied=Object.keys(curStaff.deny||{});
    pane.innerHTML=`<div class="ua-picker-search">🔍<input class="ua-pk-search" placeholder="Search modules…"></div><div class="ua-pk-grid"></div><div class="ua-pk-foot"></div>`;
    const grid=pane.querySelector(".ua-pk-grid");let curg=null;
    MODULES.forEach(([grp,name,surf])=>{
      if(mode==="grant"&&denied.includes(name))return;                 // can't grant a prohibited module
      if(grp!==curg){curg=grp;const h=document.createElement("div");h.className="ua-pk-gtag";h.textContent=grp;grid.appendChild(h);}
      const held=mode==="grant"?acc[name]:null;const c=document.createElement("button");
      c.className="ua-mchip"+(held?" ua-held":"");c.dataset.mod=name;c.dataset.hay=(name+" "+grp).toLowerCase();
      c.innerHTML=`<span class="ua-ms ua-ms-${surf}"></span>${esc(name)}${held?`<span class="ua-mchip-held">${held}</span>`:""}`;
      c.onclick=()=>{pk.mod=name;pk.surf=surf;grid.querySelectorAll(".ua-mchip").forEach(x=>{x.classList.remove("ua-sel","ua-deny-sel");if(x.dataset.mod===name)x.classList.add(mode==="grant"?"ua-sel":"ua-deny-sel");});renderFoot(pane,mode);};
      grid.appendChild(c);
    });
    const s=pane.querySelector(".ua-pk-search");
    s.oninput=()=>{const q=s.value.toLowerCase();grid.querySelectorAll(".ua-mchip").forEach(x=>x.style.display=x.dataset.hay.includes(q)?"":"none");
      grid.querySelectorAll(".ua-pk-gtag").forEach(t=>{let sib=t.nextElementSibling,vis=false;while(sib&&!sib.classList.contains("ua-pk-gtag")){if(sib.classList.contains("ua-mchip")&&sib.style.display!=="none")vis=true;sib=sib.nextElementSibling;}t.style.display=vis?"":"none";});};
    renderFoot(pane,mode);
  }
  function renderFoot(pane,mode){
    const f=pane.querySelector(".ua-pk-foot");
    if(!pk.mod){f.innerHTML=`<div class="ua-pk-hint">Pick a module above to ${mode==="grant"?"grant it to this person":"prohibit it for this person"}.</div>`;return;}
    f.innerHTML=`<div class="ua-pk-selrow"><span class="ua-ms ua-ms-${pk.surf}"></span><b>${esc(pk.mod)}</b> <span class="ua-chip ${pk.surf}">${pk.surf}</span>
        ${mode==="grant"?'<span class="ua-lbl">Access level</span>':'<span class="ua-chip deny">will be prohibited</span>'}</div>
      ${mode==="grant"?`<div class="ua-pk-lvlrow" style="margin-bottom:10px"></div>`:""}
      <input class="ua-pk-reason" placeholder="Reason ${mode==="grant"?"for this exception":"for prohibiting"}…">
      <div class="ua-pk-actions"><button class="ua-btn ua-ghost" id="ua-pk-cancel">Cancel</button>
        <button class="ua-btn ${mode==="grant"?"":"ua-danger"}" id="ua-pk-confirm">${mode==="grant"?"Grant access":"⛔ Prohibit"}</button></div>`;
    if(mode==="grant"){const lr=f.querySelector(".ua-pk-lvlrow");
      lr.innerHTML=`<span class="ua-seg ua-small">${["view","edit","manage"].map(l=>`<button data-l="${l}" class="${l===pk.level?"ua-on":""}">${l}</button>`).join("")}</span>`;
      lr.querySelectorAll("button").forEach(b=>b.onclick=()=>{pk.reason=f.querySelector(".ua-pk-reason").value;pk.level=b.dataset.l;renderFoot(pane,mode);});}
    const rin=f.querySelector(".ua-pk-reason");if(pk.reason)rin.value=pk.reason;rin.oninput=()=>pk.reason=rin.value;
    f.querySelector("#ua-pk-cancel").onclick=closePickers;
    f.querySelector("#ua-pk-confirm").onclick=async()=>{const rs=rin.value.trim();
      try{ if(mode==="grant")applyDetail(await api("staff_access/add_extra",{staff_id:curStaff.staff.id,module:pk.mod,level:pk.level,reason:rs}));
        else applyDetail(await api("staff_access/add_deny",{staff_id:curStaff.staff.id,module:pk.mod,reason:rs}));
        closePickers();toast(mode==="grant"?"Module granted":"Module prohibited"); }
      catch(e){ toast(e.message,true); }
    };
  }
  $("ua-extraTrigger").onclick=()=>openPicker("grant");
  $("ua-denyTrigger").onclick=()=>openPicker("deny");

  /* ---------- tabs & wiring ---------- */
  function switchTab(s){
    /* Audit M9: guard unsaved Role-Editor level edits when LEAVING the tab too
       (role-to-role switches were already guarded at loadRoleIntoEditor). */
    if(matrixDirty && !confirm("Discard unsaved level changes to this role?"))return;
    matrixDirty=false;
    qsa("#ua .ua-tab").forEach(t=>t.classList.toggle("ua-on",t.dataset.s===s));
    qsa("#ua .ua-screen").forEach(sc=>sc.classList.remove("ua-on"));$("ua-s-"+s).classList.add("ua-on");
    if(s==="staff"){$("ua-sv-detail").classList.remove("ua-on");$("ua-sv-list").classList.add("ua-on");if(!dirLoaded)loadDirectory();}}
  qsa("#ua .ua-tab").forEach(t=>t.onclick=()=>switchTab(t.dataset.s));
  $("ua-newRoleTop").onclick=()=>openRoleModal();
  $("ua-onboardBtn").onclick=openStaffModal;
  $("ua-backBtn").onclick=backToList;
  $("ua-dirsearch").oninput=renderDirectory;
  [$("ua-roleOverlay"),$("ua-staffOverlay")].forEach(o=>o.onclick=e=>{if(e.target===o)o.classList.remove("ua-open");});

  load();
})();
</script>
