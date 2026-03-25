<?php
/**
 * Event Circular / Advertisement — standalone printable page.
 *
 * Variables: $event (array), $school_name (string), $school_logo (string), $participant_count (int)
 */
$e = $event;
$title      = htmlspecialchars($e['title'] ?? 'Event', ENT_QUOTES, 'UTF-8');
$desc       = htmlspecialchars($e['description'] ?? '', ENT_QUOTES, 'UTF-8');
$category   = $e['category'] ?? 'event';
$status     = $e['status'] ?? 'scheduled';
$startDate  = $e['start_date'] ?? '';
$endDate    = $e['end_date'] ?? '';
$location   = htmlspecialchars($e['location'] ?? '', ENT_QUOTES, 'UTF-8');
$organizer  = htmlspecialchars($e['organizer'] ?? '', ENT_QUOTES, 'UTF-8');
$maxP       = (int) ($e['max_participants'] ?? 0);
$schoolName = htmlspecialchars($school_name, ENT_QUOTES, 'UTF-8');
$logo       = $school_logo;
$pCount     = (int) $participant_count;

// Category styling
$catMap = [
    'event'    => ['label' => 'School Event',       'icon' => 'fa-star',          'accent' => '#0f766e', 'accent2' => '#14b8a6', 'bg' => '#f0fdfa'],
    'cultural' => ['label' => 'Cultural Program',   'icon' => 'fa-paint-brush',   'accent' => '#7c3aed', 'accent2' => '#a78bfa', 'bg' => '#f5f3ff'],
    'sports'   => ['label' => 'Sports Competition', 'icon' => 'fa-trophy',        'accent' => '#d97706', 'accent2' => '#fbbf24', 'bg' => '#fffbeb'],
];
$cat = $catMap[$category] ?? $catMap['event'];

// Format dates
$fmtDate = function($d) {
    if (!$d) return '';
    $ts = strtotime($d);
    return $ts ? date('l, F j, Y', $ts) : $d;
};
$dateStr = $fmtDate($startDate);
if ($endDate && $endDate !== $startDate) {
    $dateStr .= ' &mdash; ' . $fmtDate($endDate);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title ?> — Circular</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Inter:wght@400;500;600;700&display=swap');

:root{
    --accent:<?= $cat['accent'] ?>;
    --accent2:<?= $cat['accent2'] ?>;
    --cat-bg:<?= $cat['bg'] ?>;
}

body{
    font-family:'Inter',system-ui,-apple-system,sans-serif;
    background:#f1f5f9;
    display:flex;justify-content:center;align-items:flex-start;
    min-height:100vh;padding:30px 16px;
    color:#1e293b;
}

.circ-actions{
    position:fixed;top:16px;right:16px;display:flex;gap:8px;z-index:100;
}
.circ-actions button{
    padding:10px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;
    cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;
    display:inline-flex;align-items:center;gap:6px;
}
.btn-print{background:var(--accent);color:#fff}
.btn-print:hover{opacity:.9}
.btn-back{background:#fff;color:#475569;border:1px solid #e2e8f0}
.btn-back:hover{background:#f8fafc}

.circ{
    width:700px;max-width:100%;background:#fff;
    border-radius:16px;overflow:hidden;
    box-shadow:0 4px 24px rgba(0,0,0,.08),0 1px 3px rgba(0,0,0,.04);
}

/* ── Top decorative banner ── */
.circ-banner{
    background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    padding:10px 0;position:relative;overflow:hidden;
}
.circ-banner::after{
    content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;
    border-radius:50%;background:rgba(255,255,255,.08);
}
.circ-banner::before{
    content:'';position:absolute;bottom:-20px;left:40px;width:80px;height:80px;
    border-radius:50%;background:rgba(255,255,255,.06);
}

/* ── Header ── */
.circ-header{
    padding:28px 36px 20px;text-align:center;border-bottom:2px dashed #e2e8f0;
}
.circ-school{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:6px}
.circ-school-logo{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--accent)}
.circ-school-name{
    font-family:'Playfair Display',serif;font-size:20px;font-weight:700;
    color:var(--accent);letter-spacing:.02em;
}
.circ-label{
    display:inline-block;margin-top:8px;padding:4px 16px;border-radius:20px;
    font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;
    background:var(--cat-bg);color:var(--accent);border:1px solid var(--accent2);
}

/* ── Body ── */
.circ-body{padding:28px 36px 24px}

.circ-title{
    font-family:'Playfair Display',serif;font-size:30px;font-weight:900;
    line-height:1.2;text-align:center;color:#0f172a;margin-bottom:6px;
}
.circ-cat-icon{
    display:block;text-align:center;font-size:36px;color:var(--accent);margin-bottom:14px;
    opacity:.8;
}

.circ-desc{
    text-align:center;font-size:14px;line-height:1.7;color:#475569;
    max-width:560px;margin:0 auto 24px;
}

/* ── Details grid ── */
.circ-details{
    display:grid;grid-template-columns:1fr 1fr;gap:0;
    border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:24px;
}
.circ-detail{
    padding:16px 20px;border-bottom:1px solid #e2e8f0;
    display:flex;align-items:flex-start;gap:12px;
}
.circ-detail:nth-child(odd){border-right:1px solid #e2e8f0}
.circ-detail:nth-last-child(-n+2){border-bottom:none}
.circ-detail-icon{
    width:34px;height:34px;border-radius:8px;background:var(--cat-bg);
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
    color:var(--accent);font-size:14px;
}
.circ-detail-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;font-weight:600;margin-bottom:2px}
.circ-detail-value{font-size:14px;font-weight:600;color:#1e293b}

/* ── CTA / highlight strip ── */
.circ-cta{
    background:linear-gradient(135deg, var(--cat-bg) 0%, #fff 100%);
    border:1px solid var(--accent2);border-radius:12px;
    padding:20px 24px;text-align:center;margin-bottom:24px;
}
.circ-cta-title{font-size:15px;font-weight:700;color:var(--accent);margin-bottom:4px}
.circ-cta-sub{font-size:12px;color:#64748b}

/* ── Decorative divider ── */
.circ-divider{
    display:flex;align-items:center;gap:12px;margin:20px 0;color:#cbd5e1;
}
.circ-divider::before,.circ-divider::after{content:'';flex:1;height:1px;background:#e2e8f0}
.circ-divider i{font-size:14px;color:var(--accent2)}

/* ── Footer ── */
.circ-footer{
    padding:18px 36px;background:#f8fafc;border-top:1px solid #e2e8f0;
    display:flex;align-items:center;justify-content:space-between;
    font-size:11px;color:#94a3b8;
}
.circ-footer-left{display:flex;align-items:center;gap:6px}
.circ-footer-stamp{
    font-family:'Playfair Display',serif;font-size:10px;font-weight:700;
    color:var(--accent);opacity:.6;letter-spacing:.05em;
}

/* ── Watermark pattern ── */
.circ-body{position:relative}
.circ-body::before{
    content:'<?= $cat['icon'] === 'fa-trophy' ? '\\f091' : ($cat['icon'] === 'fa-paint-brush' ? '\\f1fc' : '\\f005') ?>';
    font-family:FontAwesome;position:absolute;
    right:20px;top:50%;transform:translateY(-50%);
    font-size:160px;color:var(--accent);opacity:.03;pointer-events:none;
}

@media print{
    body{background:#fff;padding:0}
    .circ-actions{display:none!important}
    .circ{box-shadow:none;border-radius:0;width:100%}
    .circ-banner{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .circ-detail-icon{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .circ-cta{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .circ-body::before{display:none}
}

@media(max-width:600px){
    .circ-header,.circ-body,.circ-footer{padding-left:20px;padding-right:20px}
    .circ-title{font-size:22px}
    .circ-details{grid-template-columns:1fr}
    .circ-detail:nth-child(odd){border-right:none}
    .circ-detail{border-bottom:1px solid #e2e8f0}
    .circ-detail:last-child{border-bottom:none}
}
</style>
</head>
<body>

<div class="circ-actions">
    <button class="btn-back" onclick="window.close()"><i class="fa fa-arrow-left"></i> Back</button>
    <button class="btn-print" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
</div>

<div class="circ">
    <!-- Decorative top banner -->
    <div class="circ-banner"></div>

    <!-- School header -->
    <div class="circ-header">
        <div class="circ-school">
            <?php if ($logo): ?>
            <img class="circ-school-logo" src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
            <?php endif; ?>
            <div class="circ-school-name"><?= $schoolName ?></div>
        </div>
        <div class="circ-label"><i class="fa <?= $cat['icon'] ?>"></i>&nbsp; <?= htmlspecialchars($cat['label'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <!-- Body -->
    <div class="circ-body">
        <div class="circ-cat-icon"><i class="fa <?= $cat['icon'] ?>"></i></div>
        <div class="circ-title"><?= $title ?></div>

        <?php if ($desc): ?>
        <div class="circ-desc"><?= nl2br($desc) ?></div>
        <?php endif; ?>

        <div class="circ-divider"><i class="fa fa-circle"></i></div>

        <!-- Details grid -->
        <div class="circ-details">
            <?php if ($dateStr): ?>
            <div class="circ-detail">
                <div class="circ-detail-icon"><i class="fa fa-calendar"></i></div>
                <div><div class="circ-detail-label">Date</div><div class="circ-detail-value"><?= $dateStr ?></div></div>
            </div>
            <?php endif; ?>

            <?php if ($location): ?>
            <div class="circ-detail">
                <div class="circ-detail-icon"><i class="fa fa-map-marker"></i></div>
                <div><div class="circ-detail-label">Venue</div><div class="circ-detail-value"><?= $location ?></div></div>
            </div>
            <?php endif; ?>

            <?php if ($organizer): ?>
            <div class="circ-detail">
                <div class="circ-detail-icon"><i class="fa fa-user"></i></div>
                <div><div class="circ-detail-label">Organized By</div><div class="circ-detail-value"><?= $organizer ?></div></div>
            </div>
            <?php endif; ?>

            <div class="circ-detail">
                <div class="circ-detail-icon"><i class="fa fa-info-circle"></i></div>
                <div><div class="circ-detail-label">Status</div><div class="circ-detail-value" style="text-transform:capitalize"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></div></div>
            </div>

            <?php if ($maxP > 0): ?>
            <div class="circ-detail">
                <div class="circ-detail-icon"><i class="fa fa-users"></i></div>
                <div><div class="circ-detail-label">Capacity</div><div class="circ-detail-value"><?= $pCount ?> / <?= $maxP ?> Registered</div></div>
            </div>
            <?php elseif ($pCount > 0): ?>
            <div class="circ-detail">
                <div class="circ-detail-icon"><i class="fa fa-users"></i></div>
                <div><div class="circ-detail-label">Registered</div><div class="circ-detail-value"><?= $pCount ?> Participants</div></div>
            </div>
            <?php endif; ?>

            <div class="circ-detail">
                <div class="circ-detail-icon"><i class="fa fa-tag"></i></div>
                <div><div class="circ-detail-label">Category</div><div class="circ-detail-value"><?= htmlspecialchars($cat['label'], ENT_QUOTES, 'UTF-8') ?></div></div>
            </div>
        </div>

        <!-- CTA -->
        <div class="circ-cta">
            <div class="circ-cta-title">
                <?php if ($status === 'scheduled' || $status === 'ongoing'): ?>
                    All Students &amp; Staff are cordially invited to participate!
                <?php elseif ($status === 'completed'): ?>
                    Thank you to all participants for making this event a success!
                <?php else: ?>
                    This event has been <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>.
                <?php endif; ?>
            </div>
            <div class="circ-cta-sub">For registration and queries, please contact the organizing committee.</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="circ-footer">
        <div class="circ-footer-left">
            <i class="fa fa-calendar-check-o"></i>
            <span>Ref: <?= htmlspecialchars($e['id'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="circ-footer-stamp"><?= $schoolName ?> &bull; <?= date('d M Y') ?></div>
    </div>
</div>

</body>
</html>
