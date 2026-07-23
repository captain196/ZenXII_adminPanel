<?php
defined('BASEPATH') or exit('No direct script access allowed');
// LEAD SYSTEM — Admission analytics (rebuilt to the CRM prototype, 2026-07).
$esc = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };

$statusColors = [
    'new'=>'#4E82E0','contacted'=>'#DD9433','interested'=>'#9576DA','approved'=>'#2FA875',
    'admitted'=>'#C05A3B','enrolled'=>'#2AA79C','rejected'=>'#DB5A48',
];
$sourceMeta = [
    'public_form' => ['label'=>'Public form', 'color'=>'#4E82E0'],
    'crm'         => ['label'=>'CRM',         'color'=>'#9576DA'],
    'manual'      => ['label'=>'Manual',      'color'=>'#DD9433'],
];
$thisMonth = !empty($by_month) ? (int) end($by_month) : 0;
$avPal = ['#4E82E0','#DD9433','#9576DA','#2FA875','#C05A3B','#3BB0C9','#E06A9B','#6E8AA8'];
$tint = function($hex,$a){ $h=ltrim($hex,'#'); if(strlen($h)===3)$h=$h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; $n=hexdec($h); return 'rgba('.(($n>>16)&255).','.(($n>>8)&255).','.($n&255).','.$a.')'; };
?>
<style>
.aa-wrap { padding:24px 22px 52px; max-width:1240px; }
.ac-back { color:var(--t3); font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; }
.ac-back:hover { color:var(--gold); }
.aa-hdr { display:flex; align-items:center; gap:14px; padding:18px 22px; margin-bottom:18px;
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); }
.aa-hdr .ico { width:44px; height:44px; border-radius:10px; background:var(--gold); display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:18px; box-shadow:0 0 18px var(--gold-glow); flex:0 0 auto; }
.aa-hdr h1 { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); margin:0; }
.aa-hdr .sub { font-size:12px; color:var(--t3); margin-top:2px; }
.aa-hdr .sess { margin-left:auto; font-size:12px; color:var(--t2); background:var(--bg3); border:1px solid var(--border); padding:5px 12px; border-radius:20px; }

.aa-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:13px; margin-bottom:18px; }
.aa-card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:15px 16px; box-shadow:var(--sh); }
.aa-card .lbl { font-size:11.5px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.04em; display:flex; align-items:center; gap:7px; }
.aa-card .dot { width:8px; height:8px; border-radius:50%; flex:0 0 auto; }
.aa-card .val { font-size:27px; font-weight:750; margin-top:8px; color:var(--t1); line-height:1; letter-spacing:-.02em; }
.aa-card .sub { font-size:11.5px; color:var(--t3); margin-top:3px; }

.aa-charts { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
@media(max-width:900px){ .aa-charts { grid-template-columns:1fr; } .aa-cards { grid-template-columns:repeat(2,1fr); } }
.aa-box { background:var(--bg2); border:1px solid var(--border); border-radius:14px; box-shadow:var(--sh); }
.aa-box-h { padding:14px 18px; border-bottom:1px solid var(--border); font-size:14px; font-weight:700; color:var(--t1); }
.aa-box-b { padding:16px 18px; }

.bar-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; font-size:12.5px; }
.bar-row:last-child { margin-bottom:0; }
.bar-k { width:84px; color:var(--t2); text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:0 0 auto; }
.bar-track { flex:1; height:22px; background:var(--bg3); border-radius:6px; overflow:hidden; }
.bar-track i { display:block; height:100%; border-radius:6px; }
.bar-v { width:30px; text-align:right; font-weight:700; color:var(--t1); }

.donut { display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
.donut .ring { width:140px; height:140px; border-radius:50%; flex:0 0 auto; }
.donut .leg { display:flex; flex-direction:column; gap:9px; }
.donut .leg div { display:flex; align-items:center; gap:9px; font-size:12.5px; color:var(--t2); }
.donut .leg .sw { width:11px; height:11px; border-radius:3px; }
.donut .leg b { color:var(--t1); margin-left:6px; }

.trend { display:flex; align-items:flex-end; gap:10px; height:140px; padding-top:8px; }
.trend .t { flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; justify-content:flex-end; height:100%; }
.trend .t i { width:100%; max-width:38px; border-radius:6px 6px 0 0; background:var(--gold); opacity:.9; }
.trend .t .v { font-size:11px; color:var(--t2); font-weight:700; }
.trend .t .m { font-size:11px; color:var(--t3); }

.aa-empty { text-align:center; padding:48px; color:var(--t3); }
.aa-empty i { font-size:2.2rem; margin-bottom:10px; display:block; opacity:.5; }
</style>

<div class="content-wrapper">
<div class="aa-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Overview</a>

    <div class="aa-hdr">
        <div class="ico"><i class="fa fa-line-chart"></i></div>
        <div>
            <h1>Admission Analytics</h1>
            <div class="sub">Conversion, sources &amp; demand — the deep numbers behind the Overview</div>
        </div>
        <span class="sess"><i class="fa fa-calendar"></i> <?= $esc($session_year) ?></span>
    </div>

<?php if ($total === 0): ?>
    <div class="aa-box"><div class="aa-empty"><i class="fa fa-inbox"></i>No admission data for this session yet.<br><span style="font-size:12px;">Leads from the public form &amp; CRM will appear here.</span></div></div>
<?php else: ?>

    <!-- Metric tiles -->
    <div class="aa-cards">
        <div class="aa-card"><div class="lbl"><span class="dot" style="background:#4E82E0"></span>Total applications</div><div class="val"><?= (int)$total ?></div><div class="sub">this session</div></div>
        <div class="aa-card"><div class="lbl"><span class="dot" style="background:#2FA875"></span>Conversion rate</div><div class="val"><?= $esc($conversion_rate) ?>%</div><div class="sub">admitted / total</div></div>
        <div class="aa-card"><div class="lbl"><span class="dot" style="background:#C05A3B"></span>Admitted</div><div class="val"><?= (int)$admitted ?></div><div class="sub"><?= (int)($by_status['approved'] ?? 0) ?> approved pending</div></div>
        <div class="aa-card"><div class="lbl"><span class="dot" style="background:#DD9433"></span>This month</div><div class="val"><?= (int)$thisMonth ?></div><div class="sub">new applications</div></div>
    </div>

    <div class="aa-charts">
        <!-- Applications by class -->
        <div class="aa-box">
            <div class="aa-box-h">Applications by class</div>
            <div class="aa-box-b">
                <?php if (empty($by_class)): ?><div class="aa-empty" style="padding:24px">No class data</div><?php else:
                    $maxC = max(1, max($by_class)); $ci = 0;
                    foreach ($by_class as $cls => $count): $col = $avPal[$ci++ % count($avPal)]; ?>
                <div class="bar-row">
                    <div class="bar-k" title="<?= $esc($cls) ?>"><?= $esc($cls) ?></div>
                    <div class="bar-track"><i style="width:<?= max(round($count / $maxC * 100),3) ?>%;background:<?= $col ?>"></i></div>
                    <div class="bar-v"><?= (int)$count ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- By source (donut) -->
        <div class="aa-box">
            <div class="aa-box-h">By source</div>
            <div class="aa-box-b">
                <?php
                $srcActive = array_filter($by_source, fn($v) => $v > 0);
                if (empty($srcActive)): ?><div class="aa-empty" style="padding:24px">No source data</div><?php else:
                    $srcTot = max(1, array_sum($srcActive)); $acc = 0; $segs = [];
                    foreach ($srcActive as $k => $v) { $col = $sourceMeta[$k]['color'] ?? '#6E8AA8'; $a0 = $acc / $srcTot * 360; $acc += $v; $a1 = $acc / $srcTot * 360; $segs[] = "{$col} {$a0}deg {$a1}deg"; }
                ?>
                <div class="donut">
                    <div class="ring" style="background:conic-gradient(<?= implode(',', $segs) ?>);-webkit-mask:radial-gradient(circle 42px at center,transparent 98%,#000 100%);mask:radial-gradient(circle 42px at center,transparent 98%,#000 100%)"></div>
                    <div class="leg">
                        <?php foreach ($srcActive as $k => $v): $m = $sourceMeta[$k] ?? ['label'=>ucfirst($k),'color'=>'#6E8AA8']; ?>
                        <div><span class="sw" style="background:<?= $m['color'] ?>"></span><?= $esc($m['label']) ?><b><?= (int)$v ?></b></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Applications over time -->
    <?php if (!empty($by_month)): ?>
    <div class="aa-box" style="margin-bottom:16px;">
        <div class="aa-box-h">Applications over time <span style="font-weight:500;color:var(--t3);font-size:12px;">— last <?= count($by_month) ?> months</span></div>
        <div class="aa-box-b">
            <?php $maxM = max(1, max($by_month)); ?>
            <div class="trend">
                <?php foreach ($by_month as $mk => $cnt): $h = round($cnt / $maxM * 100); ?>
                <div class="t"><span class="v"><?= (int)$cnt ?></span><i style="height:<?= max($h,4) ?>%"></i><span class="m"><?= date('M y', strtotime($mk.'-01')) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent leads -->
    <?php if (!empty($recent_leads)): ?>
    <div class="aa-box">
        <div class="aa-box-h">Recent leads</div>
        <div class="aa-box-b" style="padding:0;overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr><?php foreach (['Student','Class','Source','Status','Date'] as $h): ?><th style="text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);font-weight:700;padding:11px 18px;border-bottom:1px solid var(--border);"><?= $h ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($recent_leads as $l): $sc = $statusColors[$l['status']] ?? '#6E8AA8'; ?>
                    <tr>
                        <td style="padding:11px 18px;border-bottom:1px solid var(--border);font-weight:600;"><?= $esc($l['name']) ?> <span style="font-weight:400;color:var(--t3);font-size:11px;"><?= $esc($l['id']) ?></span></td>
                        <td style="padding:11px 18px;border-bottom:1px solid var(--border);"><?= $esc($l['class']) ?></td>
                        <td style="padding:11px 18px;border-bottom:1px solid var(--border);"><?= $l['source'] === 'public_form' ? 'Public' : 'CRM' ?></td>
                        <td style="padding:11px 18px;border-bottom:1px solid var(--border);"><span style="display:inline-flex;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:650;background:<?= $tint($sc,.14) ?>;color:<?= $sc ?>;"><?= $esc($l['status']) ?></span></td>
                        <td style="padding:11px 18px;border-bottom:1px solid var(--border);color:var(--t3);font-size:12px;"><?= $esc($l['date']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php endif; /* total === 0 */ ?>

</div>
</div>
