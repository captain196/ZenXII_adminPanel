<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Professional "Access restricted" screen — shown on a full-page RBAC denial
 * instead of the old silent redirect to the dashboard. Rendered in the panel
 * chrome (sidebar stays visible) by Admin::access_denied(). Theme-aware via the
 * panel's data-theme (day/night); clay accent, `acc-` namespaced so nothing
 * collides with Bootstrap 3 / AdminLTE.
 *
 * Vars: $denied_module (string, may be ''), $denied_need (view|edit|manage|''),
 *       $denied_have (view|edit|manage|'' = none).
 */
$mod  = isset($denied_module) ? trim((string) $denied_module) : '';
$need = isset($denied_need)   ? trim((string) $denied_need)   : '';
$have = isset($denied_have)   ? trim((string) $denied_have)   : '';
$lvlLabel = ['view' => 'View', 'edit' => 'Edit', 'manage' => 'Manage'];
$needTxt = $lvlLabel[$need] ?? '';
$haveTxt = $have === '' ? 'No access' : ($lvlLabel[$have] ?? ucfirst($have));
?>
<div class="content-wrapper" id="acc-cw"><div id="acc">
<style>
#acc-cw{display:flex;align-items:center;justify-content:center;min-height:calc(100vh - var(--hh,58px))!important;padding:32px 22px}
#acc{--acc-clay:#BC5A3C;--acc-clay-deep:#A24A30;--acc-clay-soft:#F0DAD1;--acc-rose:#E05C6F;
  --acc-surface:#FFFFFF;--acc-border:#E8E2DC;--acc-ink:#2B2521;--acc-muted:#7A716A;--acc-faint:#A79E96;
  width:100%;max-width:520px;text-align:center;
  font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
[data-theme="night"] #acc{--acc-surface:#241a14;--acc-border:rgba(188,90,60,.22);--acc-ink:#F3E9E2;--acc-muted:#B7A99E;--acc-faint:#8C7E73;--acc-clay-soft:rgba(188,90,60,.20)}
#acc *{box-sizing:border-box}
#acc .acc-card{background:var(--acc-surface);border:1px solid var(--acc-border);border-radius:18px;
  padding:40px 34px 34px;box-shadow:0 1px 2px rgba(43,37,33,.06),0 18px 46px rgba(43,37,33,.10)}
#acc .acc-ic{width:72px;height:72px;margin:0 auto 20px;border-radius:50%;background:var(--acc-clay-soft);
  color:var(--acc-clay-deep);display:flex;align-items:center;justify-content:center}
#acc .acc-ic svg{width:34px;height:34px;stroke:currentColor;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
#acc h1{margin:0 0 8px;font-size:23px;font-weight:700;letter-spacing:-.02em;color:var(--acc-ink)}
#acc .acc-sub{margin:0 auto;max-width:40ch;font-size:14.5px;line-height:1.55;color:var(--acc-muted)}
#acc .acc-sub b{color:var(--acc-ink);font-weight:680}
#acc .acc-levels{display:flex;align-items:center;justify-content:center;gap:10px;margin:22px 0 4px;flex-wrap:wrap}
#acc .acc-lv{display:inline-flex;flex-direction:column;gap:3px;align-items:center;min-width:104px;
  padding:11px 16px;border-radius:12px;border:1px solid var(--acc-border);background:rgba(0,0,0,.015)}
[data-theme="night"] #acc .acc-lv{background:rgba(255,255,255,.03)}
#acc .acc-lv .acc-k{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:var(--acc-faint)}
#acc .acc-lv .acc-v{font-size:15px;font-weight:700;color:var(--acc-ink)}
#acc .acc-lv.req{border-color:var(--acc-clay);background:var(--acc-clay-soft)}
#acc .acc-lv.req .acc-v{color:var(--acc-clay-deep)}
#acc .acc-arrow{color:var(--acc-faint);font-size:18px}
#acc .acc-note{margin:20px auto 0;max-width:42ch;font-size:12.5px;line-height:1.5;color:var(--acc-faint)}
#acc .acc-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:26px}
#acc .acc-btn{display:inline-flex;align-items:center;gap:7px;border-radius:10px;padding:11px 20px;
  font-size:13.5px;font-weight:650;cursor:pointer;text-decoration:none;line-height:1.2;border:1px solid transparent;transition:.15s}
#acc .acc-btn.primary{background:var(--acc-clay);color:#fff}
#acc .acc-btn.primary:hover{background:var(--acc-clay-deep)}
#acc .acc-btn.ghost{background:transparent;color:var(--acc-ink);border-color:var(--acc-border)}
#acc .acc-btn.ghost:hover{border-color:var(--acc-clay);color:var(--acc-clay)}
</style>
  <div class="acc-card">
    <div class="acc-ic" aria-hidden="true">
      <svg viewBox="0 0 24 24"><rect x="4" y="10.5" width="16" height="10" rx="2.2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/><circle cx="12" cy="15.4" r="1.35" fill="currentColor" stroke="none"/></svg>
    </div>
    <h1>Access restricted</h1>
    <p class="acc-sub">
      <?php if ($mod !== ''): ?>
        You don’t have permission to open the <b><?= htmlspecialchars($mod) ?></b> module<?= $needTxt ? ' at the required level' : '' ?>.
      <?php else: ?>
        You don’t have permission to view this page.
      <?php endif; ?>
    </p>

    <?php if ($mod !== '' && $needTxt): ?>
    <div class="acc-levels">
      <div class="acc-lv"><span class="acc-k">You have</span><span class="acc-v"><?= htmlspecialchars($haveTxt) ?></span></div>
      <span class="acc-arrow" aria-hidden="true">→</span>
      <div class="acc-lv req"><span class="acc-k">Requires</span><span class="acc-v"><?= htmlspecialchars($needTxt) ?></span></div>
    </div>
    <?php endif; ?>

    <p class="acc-note">Need access? Ask a Super Admin, or whoever manages <b>Staff&nbsp;&amp;&nbsp;Roles</b>, to grant you this permission.</p>

    <div class="acc-actions">
      <a href="#" class="acc-btn ghost" onclick="if(history.length>1){history.back();return false;}return true;">← Go back</a>
      <a href="<?= base_url('admin') ?>" class="acc-btn primary">Back to dashboard</a>
    </div>
  </div>
</div></div>
