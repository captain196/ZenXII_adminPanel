<?php defined('BASEPATH') or exit('No direct script access allowed');
// Safety defaults
$schoolData = $schoolData ?? [];
$daysLeft   = $daysLeft   ?? null;
$sd = is_array($schoolData) ? $schoolData : (array)$schoolData;
?>


<?php
// ── Helper: safe get nested ──────────────────────────────────────────────
function sp_get($arr, ...$keys)
{
    $cur = $arr;
    foreach ($keys as $k) {
        if (!is_array($cur) || !isset($cur[$k])) return '';
        $cur = $cur[$k];
    }
    return $cur ?? '';
}

// ── Subscription calculations ────────────────────────────────────────────
$startDate    = sp_get($sd, 'subscription', 'duration', 'startDate');
$endDate      = sp_get($sd, 'subscription', 'duration', 'endDate');
$planName     = sp_get($sd, 'subscription', 'planName')  ?: 'N/A';
$subStatus    = sp_get($sd, 'subscription', 'status')    ?: 'N/A';
$months       = (int)(sp_get($sd, 'subscription', 'duration', 'periodInMonths') ?: 0);
$totalAmt     = sp_get($sd, 'subscription', 'amount', 'totalAmount') ?: 0;
$monthlyAmt   = sp_get($sd, 'subscription', 'amount', 'monthly')     ?: 0;
$features     = sp_get($sd, 'subscription', 'features');
if (!is_array($features)) $features = [];

$startTs = $startDate ? strtotime($startDate) : null;
$endTs   = $endDate   ? strtotime($endDate)   : null;

$pct = 0;
$barClass = 'sp-bar-green';
if ($startTs && $endTs && $endTs > $startTs) {
    $elapsed = time() - $startTs;
    $total_d = $endTs - $startTs;
    $pct     = max(0, min(100, round(($elapsed / $total_d) * 100)));
    if ($pct >= 90)      $barClass = 'sp-bar-red';
    elseif ($pct >= 70)  $barClass = 'sp-bar-amber';
}

$dl = $daysLeft;
$subBadgeClass = 'sp-sub-active';
$subBadgeLabel = 'Active';
if ($dl !== null) {
    if ($dl <= 0) {
        $subBadgeClass = 'sp-sub-expired';
        $subBadgeLabel = 'Expired';
    } elseif ($dl <= 30) {
        $subBadgeClass = 'sp-sub-warning';
        $subBadgeLabel = 'Expiring Soon';
    }
}

// ── School meta ──────────────────────────────────────────────────────────
$schoolName   = sp_get($sd, 'School Name')       ?: 'Your School';
$principal    = sp_get($sd, 'School Principal')  ?: '—';
$address      = sp_get($sd, 'Address')           ?: '—';
$phone        = sp_get($sd, 'Phone Number')      ?: '—';
$mobile       = sp_get($sd, 'Mobile Number')     ?: '—';
$email        = sp_get($sd, 'Email')             ?: '—';
$website      = sp_get($sd, 'Website')           ?: '';
$affiliated   = sp_get($sd, 'Affiliated To')     ?: '—';
$affNo        = sp_get($sd, 'Affiliation Number') ?: '—';
$logo         = sp_get($sd, 'Logo');
$logoValid    = $logo && filter_var($logo, FILTER_VALIDATE_URL);

// ── Payment ──────────────────────────────────────────────────────────────
$lastAmt      = sp_get($sd, 'payment', 'lastPaymentAmount') ?: '0';
$lastDate     = sp_get($sd, 'payment', 'lastPaymentDate')   ?: '—';
$payStatus    = sp_get($sd, 'payment', 'paymentStatus')     ?: '—';
$billingCycle = sp_get($sd, 'payment', 'billingCycle')       ?: '—';
// Format the date nicely if it's a valid date string
if ($lastDate && $lastDate !== '—' && strtotime($lastDate)) {
    $lastDate = date('d M Y', strtotime($lastDate));
}

// ── Activities ───────────────────────────────────────────────────────────
$activities = sp_get($sd, 'Activities');
if (!is_array($activities)) $activities = [];
?>

<div class="content-wrapper">
    <div class="sp-wrap">

        <!-- ── TOP BAR ── -->
        <div class="sp-topbar">
            <div>
                <h1 class="sp-page-title"><i class="fa fa-id-card-o"></i> School Profile</h1>
                <ol class="sp-breadcrumb">
                    <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                    <li>Schools</li>
                    <li>Profile</li>
                </ol>
            </div>
            <div style="display:flex;gap:10px;">
                <a href="<?= site_url('schools/schoolgallery') ?>" class="sp-btn sp-btn-ghost">
                    <i class="fa fa-picture-o"></i> Gallery
                </a>
            </div>
        </div>

        <!-- ── HERO ── -->
        <div class="sp-hero">
            <div class="sp-hero-logo">
                <?php if ($logoValid): ?>
                <img src="<?= htmlspecialchars($logo) ?>" alt="School Logo">
                <?php else: ?>
                <i class="fa fa-university"></i>
                <?php endif; ?>
            </div>
            <div class="sp-hero-info">
                <h2 class="sp-hero-name"><?= htmlspecialchars($schoolName) ?></h2>
                <div class="sp-hero-meta">
                    <span class="sp-hero-tag"><i class="fa fa-user"></i> <?= htmlspecialchars($principal) ?></span>
                    <span class="sp-hero-tag"><i class="fa fa-certificate"></i>
                        <?= htmlspecialchars($affiliated) ?></span>
                    <span class="sp-hero-tag"><i class="fa fa-map-marker"></i> <?= htmlspecialchars($address) ?></span>
                    <?php if ($email !== '—'): ?>
                    <span class="sp-hero-tag"><i class="fa fa-envelope-o"></i> <?= htmlspecialchars($email) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sp-hero-right">
                <span class="sp-sub-badge <?= $subBadgeClass ?>">
                    <i class="fa fa-circle" style="font-size:8px;"></i>
                    <?= $subBadgeLabel ?>
                </span>
                <?php if ($dl !== null): ?>
                <span class="sp-days-badge">
                    <?= $dl > 0 ? $dl . ' days remaining' : 'Subscription expired' ?>
                </span>
                <?php endif; ?>
                <span class="sp-days-badge"
                    style="color:rgba(255,255,255,.5);"><?= htmlspecialchars($planName) ?></span>
            </div>
        </div>

        <!-- ── STAT STRIP ── -->
        <div class="sp-stat-strip">
            <div class="sp-stat sp-stat-blue">
                <div class="sp-stat-icon"><i class="fa fa-calendar"></i></div>
                <div>
                    <div class="sp-stat-label">Subscription Plan</div>
                    <div class="sp-stat-val" style="font-size:14px;"><?= htmlspecialchars($planName) ?></div>
                </div>
            </div>
            <div class="sp-stat sp-stat-green">
                <div class="sp-stat-icon"><i class="fa fa-inr"></i></div>
                <div>
                    <div class="sp-stat-label">Total Amount Paid</div>
                    <div class="sp-stat-val">₹<?= number_format((float)$totalAmt) ?></div>
                </div>
            </div>
            <div class="sp-stat sp-stat-amber">
                <div class="sp-stat-icon"><i class="fa fa-clock-o"></i></div>
                <div>
                    <div class="sp-stat-label">Duration</div>
                    <div class="sp-stat-val"><?= $months ?> <span style="font-size:13px;font-weight:500;">months</span>
                    </div>
                </div>
            </div>
            <div class="sp-stat sp-stat-teal">
                <div class="sp-stat-icon"><i class="fa fa-calendar-check-o"></i></div>
                <div>
                    <div class="sp-stat-label">Days Left</div>
                    <div class="sp-stat-val"><?= $dl !== null ? $dl : '—' ?></div>
                </div>
            </div>
        </div>

        <!-- ── MAIN LAYOUT ── -->
        <div class="sp-layout">

            <!-- ── School Info ── -->
            <div class="sp-card">
                <div class="sp-card-head">
                    <i class="fa fa-info-circle"></i>
                    <h3>School Information</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-info-list">
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-user"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Principal</div>
                                <div class="sp-info-val"><?= htmlspecialchars($principal) ?></div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-map-marker"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Address</div>
                                <div class="sp-info-val"><?= htmlspecialchars($address) ?></div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-phone"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Phone / Mobile</div>
                                <div class="sp-info-val"><?= htmlspecialchars($phone) ?> /
                                    <?= htmlspecialchars($mobile) ?></div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-envelope-o"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Email</div>
                                <div class="sp-info-val"><a
                                        href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>
                                </div>
                            </div>
                        </div>
                        <?php if ($website): ?>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-globe"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Website</div>
                                <div class="sp-info-val"><a href="<?= htmlspecialchars($website) ?>" target="_blank"
                                        rel="noopener"><?= htmlspecialchars($website) ?></a></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-certificate"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Affiliated To</div>
                                <div class="sp-info-val"><?= htmlspecialchars($affiliated) ?></div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-hashtag"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Affiliation Number</div>
                                <div class="sp-info-val"><?= htmlspecialchars($affNo) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Subscription ── -->
            <div class="sp-card">
                <div class="sp-card-head">
                    <i class="fa fa-credit-card"></i>
                    <h3>Subscription Details</h3>
                </div>
                <div class="sp-card-body">

                    <div class="sp-sub-section">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:600;color:var(--sp-navy);">Subscription Usage</span>
                            <span class="sp-sub-days <?= $pct >= 90 ? 'sp-bar-red' : ($pct >= 70 ? '' : '') ?>"
                                style="font-size:12px;font-weight:700;color:<?= $pct >= 90 ? 'var(--sp-red)' : ($pct >= 70 ? 'var(--sp-amber)' : 'var(--sp-green)') ?>;">
                                <?= $pct ?>% used
                            </span>
                        </div>
                        <div class="sp-sub-progress">
                            <div class="sp-sub-bar <?= $barClass ?>" style="width:<?= $pct ?>%;"></div>
                        </div>
                        <div class="sp-sub-dates">
                            <span><i class="fa fa-play-circle"></i>
                                <?= $startDate ? date('d M Y', strtotime($startDate)) : '—' ?></span>
                            <span><i class="fa fa-flag-checkered"></i>
                                <?= $endDate ? date('d M Y', strtotime($endDate)) : '—' ?></span>
                        </div>
                    </div>

                    <hr class="sp-divider">

                    <div class="sp-info-list">
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-tag"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Plan</div>
                                <div class="sp-info-val"><?= htmlspecialchars($planName) ?></div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-circle" style="font-size:10px;"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Status</div>
                                <div class="sp-info-val">
                                    <span class="sp-sub-badge <?= $subBadgeClass ?>"
                                        style="font-size:12px;padding:3px 10px;">
                                        <?= $subBadgeLabel ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-calendar"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Duration</div>
                                <div class="sp-info-val"><?= $months ?> months</div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-inr"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Monthly Amount</div>
                                <div class="sp-info-val">₹<?= number_format((float)$monthlyAmt, 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <hr class="sp-divider">

                    <div
                        style="margin-bottom:10px;font-size:12px;font-weight:700;color:var(--sp-teal);text-transform:uppercase;letter-spacing:.5px;">
                        Last Payment
                    </div>
                    <div class="sp-payment-grid sp-payment-grid-4">
                        <div class="sp-pay-item">
                            <div class="sp-pay-label">Amount</div>
                            <div class="sp-pay-val">₹<?= number_format((float)$lastAmt) ?></div>
                        </div>
                        <div class="sp-pay-item">
                            <div class="sp-pay-label">Date</div>
                            <div class="sp-pay-val" style="font-size:13px;"><?= htmlspecialchars($lastDate) ?></div>
                        </div>
                        <div class="sp-pay-item">
                            <div class="sp-pay-label">Status</div>
                            <div class="sp-pay-val" style="font-size:13px;">
                                <?php
                                    $statusColor = 'var(--sp-muted)';
                                    $statusLower = strtolower($payStatus);
                                    if ($statusLower === 'paid')    $statusColor = 'var(--sp-green)';
                                    elseif ($statusLower === 'pending') $statusColor = 'var(--sp-amber)';
                                    elseif ($statusLower === 'overdue' || $statusLower === 'failed') $statusColor = 'var(--sp-red)';
                                ?>
                                <span style="color:<?= $statusColor ?>;font-weight:700;">
                                    <?= htmlspecialchars(ucfirst($payStatus)) ?>
                                </span>
                            </div>
                        </div>
                        <div class="sp-pay-item">
                            <div class="sp-pay-label">Billing Cycle</div>
                            <div class="sp-pay-val" style="font-size:13px;"><?= htmlspecialchars(ucfirst($billingCycle)) ?></div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── Features ── -->
            <div class="sp-card">
                <div class="sp-card-head">
                    <i class="fa fa-th-large"></i>
                    <h3>Active Modules / Features</h3>
                </div>
                <div class="sp-card-body">
                    <?php if (!empty($features)): ?>
                    <div class="sp-features-wrap">
                        <?php
                            $featureIcons = [
                                'School Management'  => 'university',
                                'Class Management'   => 'chalkboard',
                                'Student Management' => 'user-graduate',
                                'Staff Management'   => 'users',
                                'Account Management' => 'book',
                                'Fees Management'    => 'rupee',
                                'Exam Management'    => 'pencil-square-o',
                                'Admin Management'   => 'cog',
                            ];
                            foreach ($features as $feat):
                                $feat = is_string($feat) ? $feat : (string)$feat;
                                $icon = $featureIcons[$feat] ?? 'check';
                            ?>
                        <span class="sp-feature-pill">
                            <i class="fa fa-<?= $icon ?>"></i>
                            <?= htmlspecialchars($feat) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p style="font-size:13px;color:var(--sp-muted);margin:0;">No features configured.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Documents ── -->
            <div class="sp-card">
                <div class="sp-card-head">
                    <i class="fa fa-file-text-o"></i>
                    <h3>School Documents</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-info-list">
                        <?php
                        $holidaysUrl = sp_get($sd, 'Holidays');
                        $academicUrl = sp_get($sd, 'Academic calendar');
                        ?>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-calendar-o"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Holidays Calendar</div>
                                <div class="sp-info-val">
                                    <?php if ($holidaysUrl && filter_var($holidaysUrl, FILTER_VALIDATE_URL)): ?>
                                    <a href="<?= htmlspecialchars($holidaysUrl) ?>" target="_blank"
                                        class="sp-btn sp-btn-ghost" style="padding:5px 12px;font-size:12px;">
                                        <i class="fa fa-download"></i> Download
                                    </a>
                                    <?php else: ?>
                                    <span style="color:var(--sp-muted);">Not uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-icon"><i class="fa fa-book"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Academic Calendar</div>
                                <div class="sp-info-val">
                                    <?php if ($academicUrl && filter_var($academicUrl, FILTER_VALIDATE_URL)): ?>
                                    <a href="<?= htmlspecialchars($academicUrl) ?>" target="_blank"
                                        class="sp-btn sp-btn-ghost" style="padding:5px 12px;font-size:12px;">
                                        <i class="fa fa-download"></i> Download
                                    </a>
                                    <?php else: ?>
                                    <span style="color:var(--sp-muted);">Not uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Activities ── -->
            <?php if (!empty($activities)): ?>
            <div class="sp-card sp-card-full">
                <div class="sp-card-head">
                    <i class="fa fa-image"></i>
                    <h3>School Activities</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-activities-grid">
                        <?php foreach ($activities as $key => $imgUrl): ?>
                        <?php if (filter_var($imgUrl, FILTER_VALIDATE_URL)): ?>
                        <div class="sp-activity-thumb">
                            <img src="<?= htmlspecialchars($imgUrl) ?>"
                                alt="Activity <?= htmlspecialchars((string)$key) ?>"
                                onerror="this.parentElement.style.display='none'">
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.sp-layout -->

    </div><!-- /.sp-wrap -->
</div><!-- /.content-wrapper -->




<style>
/* ── School Profile — matches ERP theme ── */
:root {
    --sp-navy: #1a2332;
    --sp-teal: #0d9488;
    --sp-teal-lt: #ccfbf1;
    --sp-amber: #d97706;
    --sp-red: #dc2626;
    --sp-green: #16a34a;
    --sp-muted: #6b7280;
    --sp-border: #e5e7eb;
    --sp-bg: #f4f6f9;
    --sp-white: #ffffff;
    --sp-shadow: 0 2px 8px rgba(0, 0, 0, .08);
    --sp-radius: 10px;
}

.sp-wrap {
    padding: 20px 24px;
    background: var(--sp-bg);
    min-height: 100vh;
}

/* ── Top bar ── */
.sp-topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 22px;
    flex-wrap: wrap;
    gap: 12px;
}

.sp-page-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--sp-navy);
    margin: 0 0 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sp-page-title i {
    color: var(--sp-teal);
}

.sp-breadcrumb {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--sp-muted);
}

.sp-breadcrumb li:not(:last-child)::after {
    content: '/';
    margin-left: 6px;
}

.sp-breadcrumb a {
    color: var(--sp-teal);
    text-decoration: none;
}

.sp-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all .18s;
}

.sp-btn-primary {
    background: var(--sp-teal);
    color: #fff;
}

.sp-btn-primary:hover {
    background: #0f766e;
}

.sp-btn-ghost {
    background: #fff;
    color: var(--sp-navy);
    border: 1.5px solid var(--sp-border);
}

.sp-btn-ghost:hover {
    border-color: var(--sp-teal);
    color: var(--sp-teal);
}

.sp-btn-amber {
    background: var(--sp-amber);
    color: #fff;
}

.sp-btn-amber:hover {
    background: #b45309;
}

/* ── Hero banner ── */
.sp-hero {
    background: linear-gradient(135deg, var(--sp-navy) 0%, #243450 60%, #1f3a5f 100%);
    border-radius: var(--sp-radius);
    padding: 28px 32px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    box-shadow: 0 4px 20px rgba(26, 35, 50, .3);
    position: relative;
    overflow: hidden;
}

.sp-hero::after {
    content: '';
    position: absolute;
    right: -40px;
    top: -40px;
    width: 220px;
    height: 220px;
    background: rgba(13, 148, 136, .12);
    border-radius: 50%;
}

.sp-hero-logo {
    width: 80px;
    height: 80px;
    border-radius: 16px;
    object-fit: cover;
    border: 3px solid rgba(255, 255, 255, .25);
    flex-shrink: 0;
    background: rgba(255, 255, 255, .1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, .5);
    font-size: 32px;
    overflow: hidden;
}

.sp-hero-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sp-hero-info {
    flex: 1;
    min-width: 200px;
}

.sp-hero-name {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    margin: 0 0 6px;
}

.sp-hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.sp-hero-tag {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: rgba(255, 255, 255, .7);
}

.sp-hero-tag i {
    color: var(--sp-teal);
}

.sp-hero-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
    flex-shrink: 0;
}

.sp-sub-badge {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.sp-sub-active {
    background: rgba(22, 163, 74, .25);
    color: #4ade80;
}

.sp-sub-warning {
    background: rgba(217, 119, 6, .25);
    color: #fbbf24;
}

.sp-sub-expired {
    background: rgba(220, 38, 38, .25);
    color: #f87171;
}

.sp-days-badge {
    font-size: 11px;
    color: rgba(255, 255, 255, .6);
}

/* ── Stat strip ── */
.sp-stat-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}

.sp-stat {
    background: var(--sp-white);
    border-radius: var(--sp-radius);
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: var(--sp-shadow);
    border-left: 4px solid transparent;
}

.sp-stat-blue {
    border-left-color: #3b82f6;
}

.sp-stat-green {
    border-left-color: var(--sp-green);
}

.sp-stat-amber {
    border-left-color: var(--sp-amber);
}

.sp-stat-teal {
    border-left-color: var(--sp-teal);
}

.sp-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.sp-stat-blue .sp-stat-icon {
    background: #eff6ff;
    color: #3b82f6;
}

.sp-stat-green .sp-stat-icon {
    background: #f0fdf4;
    color: var(--sp-green);
}

.sp-stat-amber .sp-stat-icon {
    background: #fffbeb;
    color: var(--sp-amber);
}

.sp-stat-teal .sp-stat-icon {
    background: #f0fdfa;
    color: var(--sp-teal);
}

.sp-stat-label {
    font-size: 11px;
    color: var(--sp-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .5px;
}

.sp-stat-val {
    font-size: 20px;
    font-weight: 800;
    color: var(--sp-navy);
    line-height: 1.2;
}

/* ── Layout: 2 col ── */
.sp-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .sp-layout {
        grid-template-columns: 1fr;
    }
}

/* ── Cards ── */
.sp-card {
    background: var(--sp-white);
    border-radius: var(--sp-radius);
    box-shadow: var(--sp-shadow);
    overflow: hidden;
}

.sp-card-full {
    grid-column: 1 / -1;
}

.sp-card-head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 20px;
    border-bottom: 1.5px solid var(--sp-border);
    background: #f8fafc;
}

.sp-card-head h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: var(--sp-navy);
}

.sp-card-head i {
    color: var(--sp-teal);
}

.sp-card-body {
    padding: 20px;
}

/* ── Info list ── */
.sp-info-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.sp-info-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.sp-info-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: var(--sp-teal-lt);
    color: var(--sp-teal);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
    margin-top: 2px;
}

.sp-info-body {
    flex: 1;
}

.sp-info-label {
    font-size: 11px;
    color: var(--sp-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 2px;
}

.sp-info-val {
    font-size: 13px;
    font-weight: 600;
    color: var(--sp-navy);
}

.sp-info-val a {
    color: var(--sp-teal);
    text-decoration: none;
}

.sp-info-val a:hover {
    text-decoration: underline;
}

/* ── Subscription progress ── */
.sp-sub-section {
    margin-bottom: 16px;
}

.sp-sub-progress {
    background: #e5e7eb;
    border-radius: 20px;
    height: 8px;
    overflow: hidden;
    margin: 8px 0;
}

.sp-sub-bar {
    height: 100%;
    border-radius: 20px;
    transition: width .6s ease;
}

.sp-bar-green {
    background: var(--sp-green);
}

.sp-bar-amber {
    background: var(--sp-amber);
}

.sp-bar-red {
    background: var(--sp-red);
}

.sp-sub-dates {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: var(--sp-muted);
}

.sp-sub-days {
    font-size: 13px;
    font-weight: 700;
}

/* ── Features pills ── */
.sp-features-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.sp-feature-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    background: var(--sp-teal-lt);
    color: var(--sp-teal);
    font-size: 12px;
    font-weight: 600;
    border: 1px solid rgba(13, 148, 136, .2);
}

.sp-feature-pill i {
    font-size: 11px;
}

/* ── Activities ── */
.sp-activities-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 10px;
}

.sp-activity-thumb {
    border-radius: 8px;
    overflow: hidden;
    aspect-ratio: 4/3;
    border: 1.5px solid var(--sp-border);
}

.sp-activity-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* ── Payment row ── */
.sp-payment-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.sp-payment-grid-4 {
    grid-template-columns: repeat(4, 1fr);
}

@media (max-width: 600px) {
    .sp-payment-grid,
    .sp-payment-grid-4 {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 380px) {
    .sp-payment-grid,
    .sp-payment-grid-4 {
        grid-template-columns: 1fr;
    }
}

.sp-pay-item {
    background: #f8fafc;
    border-radius: 8px;
    padding: 14px 16px;
    border: 1px solid var(--sp-border);
}

.sp-pay-label {
    font-size: 11px;
    color: var(--sp-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 4px;
}

.sp-pay-val {
    font-size: 15px;
    font-weight: 800;
    color: var(--sp-navy);
}

/* ── Divider ── */
.sp-divider {
    border: none;
    border-top: 1px solid var(--sp-border);
    margin: 16px 0;
}
</style>