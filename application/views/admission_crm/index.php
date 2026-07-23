<?php defined('BASEPATH') or exit('No direct script access allowed');
if (!function_exists('ov_initials')) { function ov_initials($n){ $n=trim((string)$n); if($n==='')return '?'; $p=preg_split('/\s+/',$n); $a=mb_substr($p[0],0,1); $b=count($p)>1?mb_substr($p[count($p)-1],0,1):''; return strtoupper($a.$b); } }
if (!function_exists('ov_color')) { function ov_color($s){ $pal=['#4E82E0','#DD9433','#9576DA','#2FA875','#C05A3B','#3BB0C9','#E06A9B','#6E8AA8']; $s=(string)$s; $h=0; for($i=0;$i<strlen($s);$i++){$h=($h*31+ord($s[$i]))&0x7fffffff;} return $pal[$h%count($pal)]; } }
if (!function_exists('ov_ago')) { function ov_ago($ts){ $t=strtotime((string)$ts); if(!$t)return ''; $d=time()-$t; if($d<3600)return max(1,floor($d/60)).'m ago'; if($d<86400)return floor($d/3600).'h ago'; return floor($d/86400).'d ago'; } }
if (!function_exists('ov_tint')) { function ov_tint($hex,$a){ $h=ltrim($hex,'#'); if(strlen($h)===3)$h=$h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; $n=hexdec($h); return 'rgba('.(($n>>16)&255).','.(($n>>8)&255).','.($n&255).','.$a.')'; } }

$kpis = [
    ['Inquiries',        (int)($stats['total_inquiries']??0),   '#4E82E0', 'this session'],
    ['Applications',     (int)($stats['total_applications']??0),'#9576DA', 'this session'],
    ['In Review',        (int)($stats['in_review']??0),         '#DD9433', 'docs + interview'],
    ['Pending Decision', (int)($stats['pending_approval']??0),  '#C05A3B', 'awaiting sign-off'],
    ['Approved',         (int)($stats['approved']??0),          '#2FA875', 'ready to enrol'],
    ['Waitlisted',       (int)($stats['total_waitlist']??0),    '#8E72C8', 'held for a seat'],
];
$funnel = [
    ['Inquiries',    (int)($stats['total_inquiries']??0),    '#4E82E0'],
    ['Applications', (int)($stats['total_applications']??0), '#9576DA'],
    ['In Review',    (int)($stats['in_review']??0),          '#DD9433'],
    ['Decision',     (int)($stats['pending_approval']??0),   '#C05A3B'],
    ['Approved',     (int)($stats['approved']??0),           '#2FA875'],
    ['Enrolled',     (int)($stats['enrolled']??0),           '#2AA79C'],
];
$fmax = max(1, $funnel[0][1]);
?>
<style>
html { font-size:16px !important; }
.ac-wrap { padding:24px 22px 52px; min-height:100vh; }
.ac-head { display:flex; align-items:center; gap:14px; padding:18px 22px; margin-bottom:18px;
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); }
.ac-head-icon { width:44px; height:44px; border-radius:10px; background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow); }
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }
.ac-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:9px; font-size:13px; font-weight:600;
    border:1px solid var(--gold); background:var(--gold); color:#fff; text-decoration:none; box-shadow:0 2px 10px var(--gold-ring); }
.ac-btn:hover { background:var(--gold2); color:#fff; }

/* KPI tiles */
.ov-kpis { display:grid; grid-template-columns:repeat(6,1fr); gap:13px; margin-bottom:18px; }
.ov-kpi { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:15px 16px; box-shadow:var(--sh); }
.ov-kpi .lbl { font-size:11.5px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.04em; display:flex; align-items:center; gap:7px; }
.ov-kpi .dot { width:8px; height:8px; border-radius:50%; flex:0 0 auto; }
.ov-kpi .val { font-size:27px; font-weight:750; margin-top:8px; color:var(--t1); line-height:1; letter-spacing:-.02em; }
.ov-kpi .sub { font-size:11.5px; color:var(--t3); margin-top:3px; }

.ac-card { background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); }
.ac-card-h { display:flex; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid var(--border); }
.ac-card-h h3 { font-size:14px; font-weight:700; color:var(--t1); margin:0; }
.ac-card-h .hint { margin-left:auto; font-size:12px; color:var(--t3); }
.ac-card-b { padding:16px 18px; }

/* Funnel strip */
.ov-funnel { display:flex; gap:6px; align-items:stretch; }
.ov-step { flex:1; background:var(--bg3); border:1px solid var(--border); border-radius:10px; padding:11px 12px; min-width:0; }
.ov-step .fn { font-size:11.5px; color:var(--t2); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ov-step .fv { font-size:22px; font-weight:750; margin-top:3px; color:var(--t1); }
.ov-step .fp { font-size:11px; color:var(--t3); margin-top:1px; }
.ov-step .fb { height:4px; border-radius:3px; margin-top:8px; background:var(--border); overflow:hidden; }
.ov-step .fb i { display:block; height:100%; border-radius:3px; }
.ov-arrow { align-self:center; color:var(--t3); font-size:13px; flex:0 0 auto; }

/* Two columns */
.ov-cols { display:grid; grid-template-columns:1.35fr 1fr; gap:16px; margin-top:16px; }
.ov-row { display:flex; align-items:center; gap:12px; padding:11px 2px; border-bottom:1px solid var(--border); }
.ov-row:last-child { border-bottom:0; }
.ov-av { width:34px; height:34px; border-radius:9px; display:grid; place-items:center; font-weight:700; font-size:12px; color:#fff; flex:0 0 auto; }
.ov-nm { font-weight:600; font-size:13.5px; color:var(--t1); }
.ov-mt { font-size:12px; color:var(--t3); }
.ov-chip { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:650; padding:2px 9px; border-radius:20px; white-space:nowrap; }
.ov-stripe { width:3px; align-self:stretch; border-radius:3px; flex:0 0 auto; }
.ov-empty { text-align:center; padding:26px; color:var(--t3); font-size:12.5px; }

@media(max-width:1100px){ .ov-kpis { grid-template-columns:repeat(3,1fr); } .ov-cols { grid-template-columns:1fr; } }
@media(max-width:720px){ .ov-kpis { grid-template-columns:repeat(2,1fr); } .ac-head { flex-wrap:wrap; } .ov-funnel { flex-wrap:wrap; } .ov-arrow { display:none; } .ov-step { flex:1 1 30%; } }
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-graduation-cap"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Admission Overview</div>
            <div class="ac-head-sub">Session <?= htmlspecialchars($session_year) ?> — where every applicant stands, capture to enrolment</div>
        </div>
        <a href="<?= base_url('sis/applications') ?>" class="ac-btn"><i class="fa fa-list"></i> View Applications</a>
    </div>

    <!-- KPI tiles -->
    <div class="ov-kpis">
        <?php foreach ($kpis as $k): ?>
        <div class="ov-kpi">
            <div class="lbl"><span class="dot" style="background:<?= $k[2] ?>"></span><?= htmlspecialchars($k[0]) ?></div>
            <div class="val"><?= $k[1] ?></div>
            <div class="sub"><?= htmlspecialchars($k[3]) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Conversion funnel -->
    <div class="ac-card" style="margin-bottom:0;">
        <div class="ac-card-h"><h3>Conversion funnel</h3><span class="hint">this session</span></div>
        <div class="ac-card-b">
            <div class="ov-funnel">
                <?php foreach ($funnel as $i => $f):
                    $prev = $i > 0 ? max(1, $funnel[$i-1][1]) : $f[1];
                    $pct  = $i === 0 ? '' : round($f[1] / $prev * 100) . '% of prev';
                    $w    = round($f[1] / $fmax * 100);
                ?>
                <div class="ov-step">
                    <div class="fn"><?= htmlspecialchars($f[0]) ?></div>
                    <div class="fv"><?= $f[1] ?></div>
                    <div class="fp"><?= $pct ?: '&nbsp;' ?></div>
                    <div class="fb"><i style="width:<?= max($w,4) ?>%;background:<?= $f[2] ?>"></i></div>
                </div>
                <?php if ($i < count($funnel)-1): ?><div class="ov-arrow">&rsaquo;</div><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Attention + Activity -->
    <div class="ov-cols">
        <div class="ac-card">
            <div class="ac-card-h"><h3>Needs your attention</h3><span class="hint">aging &gt; 7d &middot; unpaid fee</span></div>
            <div class="ac-card-b">
                <?php if (empty($attention)): ?>
                    <div class="ov-empty"><i class="fa fa-check-circle" style="color:#2FA875;"></i> Nothing needs attention — every applicant is on track.</div>
                <?php else: foreach ($attention as $a):
                    $col = ov_color($a['name']); $d = $a['days'];
                    $ac  = ($d !== null && $d > 14) ? '#C0402E' : (($d !== null && $d > 7) ? '#B5741F' : 'var(--t3)');
                ?>
                <div class="ov-row">
                    <div class="ov-stripe" style="background:<?= $ac ?>"></div>
                    <div class="ov-av" style="background:<?= $col ?>"><?= htmlspecialchars(ov_initials($a['name'])) ?></div>
                    <div>
                        <div class="ov-nm"><?= htmlspecialchars($a['name'] ?: 'Unnamed applicant') ?></div>
                        <div class="ov-mt"><?= htmlspecialchars(($a['class']?'Grade '.$a['class']:'—')) ?> &middot; <?= htmlspecialchars(str_replace('_',' ', $a['stage'] ?: 'application')) ?></div>
                    </div>
                    <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                        <?php if ($d !== null && $d > 7): ?><span class="ov-chip" style="background:<?= ov_tint($d>14?'#DB5A48':'#DD9433',.13) ?>;color:<?= $ac ?>"><?= (int)$d ?>d in stage</span><?php endif; ?>
                        <?php if (!empty($a['unpaid'])): ?><span class="ov-chip" style="background:<?= ov_tint('#DD9433',.14) ?>;color:#B5741F">fee unpaid</span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="ac-card">
            <div class="ac-card-h"><h3>Recent activity</h3></div>
            <div class="ac-card-b">
                <?php if (empty($activity)): ?>
                    <div class="ov-empty">No activity yet this session.</div>
                <?php else: foreach ($activity as $a): $col = ov_color($a['name']); ?>
                <div class="ov-row">
                    <div class="ov-av" style="background:<?= $col ?>"><?= htmlspecialchars(ov_initials($a['name'])) ?></div>
                    <div>
                        <div class="ov-nm"><?= htmlspecialchars($a['name'] ?: 'Applicant') ?></div>
                        <div class="ov-mt"><?= htmlspecialchars($a['action']) ?></div>
                    </div>
                    <div class="ov-mt" style="margin-left:auto;white-space:nowrap;"><?= htmlspecialchars(ov_ago($a['ts'])) ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

</div>
</div>
