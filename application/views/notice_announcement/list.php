<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php
// Process notices: strip Count key, sort by Timestamp desc
$noticesList = [];
foreach ($notices as $id => $notice) {
    if ($id === 'Count' || !is_array($notice)) continue;
    $notice['_id'] = $id;
    $notice['_ts'] = $notice['Timestamp'] ?? $notice['Time_Stamp'] ?? 0;
    $noticesList[] = $notice;
}
usort($noticesList, function($a, $b) { return $b['_ts'] <=> $a['_ts']; });
$totalCount = count($noticesList);
?>

<style>
/* ═══════════════════════════════════════════════
   All Notices Page  —  .an-* namespace
═══════════════════════════════════════════════ */
.an-wrap {
    max-width: 1060px;
    margin: 0 auto;
    padding: 28px 20px 60px;
}

/* ── Page header ── */
.an-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 26px;
    flex-wrap: wrap;
}
.an-page-head-left h2 {
    font-family: var(--font-b, 'Fraunces', serif);
    font-size: 1.55rem;
    font-weight: 700;
    color: var(--t1, #fff);
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.an-page-head-left h2 i { color: var(--gold, #0f766e); }

.an-breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    list-style: none;
    padding: 0; margin: 0;
    font-size: 12px;
    color: var(--t3, #7A6E54);
    font-family: 'JetBrains Mono', monospace;
}
.an-breadcrumb li::after { content: '/'; margin-left: 6px; opacity: .4; }
.an-breadcrumb li:last-child::after { display: none; }
.an-breadcrumb a { color: var(--gold, #0f766e); text-decoration: none; }
.an-breadcrumb a:hover { text-decoration: underline; }

.an-create-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 18px;
    border-radius: 10px;
    background: var(--gold, #0f766e);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background .2s, transform .15s, box-shadow .2s;
    box-shadow: 0 4px 12px rgba(15,118,110,.25);
    white-space: nowrap;
}
.an-create-btn:hover {
    background: var(--gold2, #0d6b63);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(15,118,110,.35);
    color: #fff;
    text-decoration: none;
}

/* ── Stats bar ── */
.an-stats-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.an-stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-family: 'JetBrains Mono', monospace;
    font-weight: 600;
    border: 1px solid var(--border, rgba(15,118,110,.18));
    background: var(--bg3, #0f2545);
    color: var(--t2, #94c9c3);
}
.an-stat-pill i { color: var(--gold, #0f766e); }
.an-stat-pill span { color: var(--t1, #fff); }

/* ── Filter bar ── */
.an-filters {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
    flex-wrap: wrap;
    padding: 14px 16px;
    background: var(--bg2, #0c1e38);
    border: 1px solid var(--border, rgba(15,118,110,.14));
    border-radius: 12px;
}
.an-search-wrap {
    flex: 1;
    min-width: 200px;
    position: relative;
}
.an-search-wrap i {
    position: absolute;
    left: 11px; top: 50%;
    transform: translateY(-50%);
    color: var(--t3, #7A6E54);
    font-size: 13px;
    pointer-events: none;
}
.an-search {
    width: 100%;
    padding: 9px 12px 9px 33px;
    border-radius: 8px;
    border: 1px solid var(--border, rgba(15,118,110,.18));
    background: var(--bg3, #0f2545);
    color: var(--t1, #fff);
    font-size: 13px;
    outline: none;
    transition: border-color .2s;
    box-sizing: border-box;
}
.an-search:focus { border-color: var(--gold, #0f766e); }
.an-search::placeholder { color: var(--t3, #7A6E54); }

.an-filter-select {
    padding: 9px 12px;
    border-radius: 8px;
    border: 1px solid var(--border, rgba(15,118,110,.18));
    background: var(--bg3, #0f2545);
    color: var(--t2, #94c9c3);
    font-size: 12.5px;
    outline: none;
    cursor: pointer;
    min-width: 130px;
}
.an-filter-select option { background: #0c1e38; }
.an-filter-select:focus { border-color: var(--gold, #0f766e); }

.an-filter-count {
    font-size: 11.5px;
    font-family: 'JetBrains Mono', monospace;
    color: var(--t3, #7A6E54);
    white-space: nowrap;
}
.an-filter-count strong { color: var(--gold, #0f766e); }

/* ── Notice list ── */
.an-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ── Notice card ── */
.an-card {
    background: var(--bg2, #0c1e38);
    border: 1px solid var(--border, rgba(15,118,110,.12));
    border-left: 4px solid var(--gold, #0f766e);
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: box-shadow .2s, transform .15s, border-color .2s;
}
.an-card:hover {
    box-shadow: 0 6px 24px var(--sh, rgba(0,0,0,.4));
    transform: translateY(-2px);
}
.an-card[data-priority="High"]   { border-left-color: #E05C6F; }
.an-card[data-priority="Normal"] { border-left-color: #0f766e; }
.an-card[data-priority="Low"]    { border-left-color: #6B8DB8; }

.an-card-inner { padding: 18px 20px; flex: 1; }

.an-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.an-card-title {
    font-family: var(--font-b, 'Fraunces', serif);
    font-size: 15.5px;
    font-weight: 700;
    color: var(--t1, #fff);
    line-height: 1.35;
    flex: 1;
}
.an-card-badges {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
    flex-wrap: wrap;
}

/* Priority badge */
.an-badge-pri {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 12px;
    font-size: 10.5px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    letter-spacing: .3px;
}
.an-badge-pri.high   { background: rgba(224,92,111,.12); color: #E05C6F; border: 1px solid rgba(224,92,111,.25); }
.an-badge-pri.normal { background: rgba(15,118,110,.12); color: #0f766e; border: 1px solid rgba(15,118,110,.25); }
.an-badge-pri.low    { background: rgba(107,141,184,.12); color: #6B8DB8; border: 1px solid rgba(107,141,184,.25); }

/* Category badge */
.an-badge-cat {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 12px;
    font-size: 10.5px;
    font-weight: 600;
    font-family: 'DM Sans', sans-serif;
}
.an-badge-cat.cat-general        { background: rgba(107,114,128,.12); color: #9CA3AF; border: 1px solid rgba(107,114,128,.2); }
.an-badge-cat.cat-academic       { background: rgba(15,118,110,.12); color: #0f766e; border: 1px solid rgba(15,118,110,.2); }
.an-badge-cat.cat-administrative { background: rgba(124,58,237,.12); color: #A78BFA; border: 1px solid rgba(124,58,237,.2); }
.an-badge-cat.cat-holiday        { background: rgba(217,119,6,.12); color: #D97706; border: 1px solid rgba(217,119,6,.2); }
.an-badge-cat.cat-exam           { background: rgba(224,92,111,.12); color: #E05C6F; border: 1px solid rgba(224,92,111,.2); }
.an-badge-cat.cat-event          { background: rgba(5,150,105,.12); color: #10B981; border: 1px solid rgba(5,150,105,.2); }

.an-card-desc {
    font-size: 13px;
    color: var(--t2, #94c9c3);
    line-height: 1.65;
    margin-bottom: 14px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.an-card-meta {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.an-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-family: 'JetBrains Mono', monospace;
    color: var(--t3, #7A6E54);
}
.an-meta-item i { color: var(--gold, #0f766e); font-size: 11px; }

.an-recipients-tags {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.an-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-family: 'JetBrains Mono', monospace;
    font-weight: 600;
    background: var(--gold-dim, rgba(15,118,110,.10));
    color: var(--gold3, #14b8a6);
    border: 1px solid var(--gold-ring, rgba(15,118,110,.18));
}

/* ── Card footer ── */
.an-card-foot {
    padding: 11px 20px;
    border-top: 1px solid var(--border, rgba(15,118,110,.08));
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    background: var(--bg3, #0f2545);
    flex-wrap: wrap;
}
.an-card-id {
    font-size: 10.5px;
    font-family: 'JetBrains Mono', monospace;
    color: var(--t3, #7A6E54);
    display: flex;
    align-items: center;
    gap: 5px;
}
.an-card-id strong { color: var(--gold3, #14b8a6); }

.an-delete-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    border-radius: 7px;
    background: rgba(224,92,111,.08);
    border: 1px solid rgba(224,92,111,.2);
    color: #E05C6F;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s, border-color .2s;
}
.an-delete-btn:hover {
    background: rgba(224,92,111,.16);
    border-color: rgba(224,92,111,.4);
}

/* ── Empty state ── */
.an-empty {
    text-align: center;
    padding: 72px 24px;
    color: var(--t3, #7A6E54);
}
.an-empty-icon {
    width: 68px; height: 68px;
    border-radius: 50%;
    background: var(--gold-dim, rgba(15,118,110,.08));
    border: 1px solid var(--gold-ring, rgba(15,118,110,.18));
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    font-size: 28px;
    color: var(--gold, #0f766e);
}
.an-empty h4 {
    font-family: var(--font-b, 'Fraunces', serif);
    font-size: 17px;
    color: var(--t2, #94c9c3);
    margin: 0 0 8px;
}
.an-empty p { font-size: 13px; margin: 0 0 22px; }

/* ── No-results row ── */
.an-no-results {
    display: none;
    text-align: center;
    padding: 48px 24px;
    color: var(--t3, #7A6E54);
    font-size: 13.5px;
}
.an-no-results i { font-size: 26px; display: block; margin-bottom: 10px; opacity: .45; }

/* ── Delete confirm modal ── */
.an-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(3px);
    z-index: 9998;
    align-items: center;
    justify-content: center;
}
.an-modal-overlay.open { display: flex; }
.an-modal {
    background: var(--bg2, #0c1e38);
    border: 1px solid var(--border, rgba(15,118,110,.18));
    border-radius: 16px;
    padding: 28px 28px 22px;
    max-width: 380px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,.7);
    animation: anModalIn .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes anModalIn {
    from { opacity: 0; transform: scale(.93) translateY(10px); }
    to   { opacity: 1; transform: scale(1)   translateY(0); }
}
.an-modal-icon {
    width: 50px; height: 50px;
    border-radius: 50%;
    background: rgba(224,92,111,.10);
    border: 1px solid rgba(224,92,111,.25);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    font-size: 20px;
    color: #E05C6F;
}
.an-modal h4 {
    font-family: var(--font-b, 'Fraunces', serif);
    font-size: 17px;
    font-weight: 700;
    color: var(--t1, #fff);
    text-align: center;
    margin: 0 0 8px;
}
.an-modal p {
    font-size: 13px;
    color: var(--t3, #7A6E54);
    text-align: center;
    margin: 0 0 22px;
    line-height: 1.6;
}
.an-modal-btns { display: flex; gap: 10px; }
.an-modal-cancel {
    flex: 1;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid var(--border, rgba(15,118,110,.18));
    background: var(--bg3, #0f2545);
    color: var(--t2, #94c9c3);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s;
}
.an-modal-cancel:hover { background: var(--bg4, #1a3555); }
.an-modal-confirm {
    flex: 1;
    padding: 10px;
    border-radius: 8px;
    border: none;
    background: #E05C6F;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background .2s;
}
.an-modal-confirm:hover { background: #c44d60; text-decoration: none; color: #fff; }

@media (max-width: 640px) {
    .an-page-head { flex-direction: column; }
    .an-filters { flex-direction: column; align-items: stretch; }
    .an-search-wrap { min-width: auto; }
    .an-card-header { flex-direction: column; }
    .an-card-foot { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="content-wrapper">
<div class="an-wrap">

    <!-- ── Page header ── -->
    <div class="an-page-head">
        <div class="an-page-head-left">
            <h2><i class="fa fa-bell"></i> All Notices</h2>
            <ol class="an-breadcrumb">
                <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                <li>All Notices</li>
            </ol>
        </div>
        <a href="<?= base_url('NoticeAnnouncement/create_notice') ?>" class="an-create-btn">
            <i class="fa fa-plus"></i> Create Notice
        </a>
    </div>

    <!-- ── Stats bar ── -->
    <?php if ($totalCount > 0):
        $highCount   = count(array_filter($noticesList, function($n){ return ($n['Priority'] ?? '') === 'High'; }));
        $normalCount = count(array_filter($noticesList, function($n){ return ($n['Priority'] ?? 'Normal') === 'Normal'; }));
        $lowCount    = count(array_filter($noticesList, function($n){ return ($n['Priority'] ?? '') === 'Low'; }));
    ?>
    <div class="an-stats-bar">
        <div class="an-stat-pill">
            <i class="fa fa-bell"></i> Total: <span><?= $totalCount ?></span>
        </div>
        <?php if ($highCount > 0): ?>
        <div class="an-stat-pill" style="color:#E05C6F;border-color:rgba(224,92,111,.25);background:rgba(224,92,111,.06)">
            <i class="fa fa-exclamation-circle" style="color:#E05C6F"></i>
            High: <span style="color:#E05C6F"><?= $highCount ?></span>
        </div>
        <?php endif; ?>
        <?php if ($normalCount > 0): ?>
        <div class="an-stat-pill">
            <i class="fa fa-minus-circle"></i> Normal: <span><?= $normalCount ?></span>
        </div>
        <?php endif; ?>
        <?php if ($lowCount > 0): ?>
        <div class="an-stat-pill" style="color:#6B8DB8;border-color:rgba(107,141,184,.25);background:rgba(107,141,184,.06)">
            <i class="fa fa-arrow-circle-down" style="color:#6B8DB8"></i>
            Low: <span style="color:#6B8DB8"><?= $lowCount ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Filter bar ── -->
    <?php if ($totalCount > 0): ?>
    <div class="an-filters">
        <div class="an-search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" class="an-search" id="anSearch"
                placeholder="Search by title or description…">
        </div>
        <select class="an-filter-select" id="anFilterCat">
            <option value="">All Categories</option>
            <option value="General">General</option>
            <option value="Academic">Academic</option>
            <option value="Administrative">Administrative</option>
            <option value="Holiday">Holiday</option>
            <option value="Exam">Exam</option>
            <option value="Event">Event</option>
        </select>
        <select class="an-filter-select" id="anFilterPri">
            <option value="">All Priorities</option>
            <option value="High">High</option>
            <option value="Normal">Normal</option>
            <option value="Low">Low</option>
        </select>
        <div class="an-filter-count" id="anFilterCount">
            Showing <strong><?= $totalCount ?></strong> notice<?= $totalCount !== 1 ? 's' : '' ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Notice list ── -->
    <div class="an-list" id="anList">

        <?php if (empty($noticesList)): ?>
        <!-- Empty state -->
        <div class="an-empty">
            <div class="an-empty-icon"><i class="fa fa-bell-slash-o"></i></div>
            <h4>No notices yet</h4>
            <p>Create your first notice and it will appear here.</p>
            <a href="<?= base_url('NoticeAnnouncement/create_notice') ?>" class="an-create-btn">
                <i class="fa fa-plus"></i> Create Notice
            </a>
        </div>

        <?php else: ?>

        <!-- No-results row (shown by JS when filters match nothing) -->
        <div class="an-no-results" id="anNoResults">
            <i class="fa fa-search"></i>
            No notices match your filters.
        </div>

        <?php
        $priIconMap = [
            'High'   => 'fa-exclamation-circle',
            'Normal' => 'fa-minus-circle',
            'Low'    => 'fa-arrow-circle-down',
        ];
        $catClassMap = [
            'General'        => 'cat-general',
            'Academic'       => 'cat-academic',
            'Administrative' => 'cat-administrative',
            'Holiday'        => 'cat-holiday',
            'Exam'           => 'cat-exam',
            'Event'          => 'cat-event',
        ];
        $catIconMap = [
            'General'        => 'fa-info-circle',
            'Academic'       => 'fa-graduation-cap',
            'Administrative' => 'fa-cogs',
            'Holiday'        => 'fa-star',
            'Exam'           => 'fa-pencil-square-o',
            'Event'          => 'fa-calendar',
        ];

        foreach ($noticesList as $notice):
            $id          = $notice['_id'];
            $title       = htmlspecialchars($notice['Title']       ?? 'Untitled');
            $description = htmlspecialchars($notice['Description'] ?? '');
            $priority    = $notice['Priority'] ?? 'Normal';
            $category    = $notice['Category'] ?? 'General';
            $fromId      = htmlspecialchars($notice['From Id']   ?? '—');
            $fromType    = htmlspecialchars($notice['From Type'] ?? 'Admin');
            $ts          = $notice['_ts'];
            $toIds       = is_array($notice['To Id'] ?? null) ? array_keys($notice['To Id']) : [];

            $timeStr  = $ts > 0 ? date('d M Y, h:i A', (int)($ts / 1000)) : '—';
            $priClass = strtolower($priority);
            $catClass = $catClassMap[$category] ?? 'cat-general';
            $catIcon  = $catIconMap[$category]  ?? 'fa-info-circle';
            $priIcon  = $priIconMap[$priority]  ?? 'fa-minus-circle';
        ?>
        <div class="an-card"
             data-priority="<?= $priority ?>"
             data-category="<?= $category ?>"
             data-title="<?= strtolower($title) ?>"
             data-desc="<?= strtolower(htmlspecialchars($description)) ?>">

            <div class="an-card-inner">
                <!-- Header: title + badges -->
                <div class="an-card-header">
                    <div class="an-card-title"><?= $title ?></div>
                    <div class="an-card-badges">
                        <span class="an-badge-pri <?= $priClass ?>">
                            <i class="fa <?= $priIcon ?>"></i> <?= $priority ?>
                        </span>
                        <span class="an-badge-cat <?= $catClass ?>">
                            <i class="fa <?= $catIcon ?>"></i> <?= $category ?>
                        </span>
                    </div>
                </div>

                <!-- Description preview -->
                <div class="an-card-desc"><?= $description ?></div>

                <!-- Meta row -->
                <div class="an-card-meta">
                    <span class="an-meta-item">
                        <i class="fa fa-clock-o"></i> <?= $timeStr ?>
                    </span>
                    <span class="an-meta-item">
                        <i class="fa fa-user-o"></i> <?= $fromId ?> (<?= $fromType ?>)
                    </span>
                    <?php if (!empty($toIds)): ?>
                    <span class="an-meta-item">
                        <i class="fa fa-users"></i>
                        <div class="an-recipients-tags">
                            <?php foreach (array_slice($toIds, 0, 3) as $toKey): ?>
                                <span class="an-tag"><?= htmlspecialchars(str_replace('|', ' / ', $toKey)) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($toIds) > 3): ?>
                                <span class="an-tag">+<?= count($toIds) - 3 ?> more</span>
                            <?php endif; ?>
                        </div>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer: ID + delete -->
            <div class="an-card-foot">
                <div class="an-card-id">
                    <i class="fa fa-tag"></i> ID: <strong><?= htmlspecialchars($id) ?></strong>
                </div>
                <button class="an-delete-btn"
                    onclick="anConfirmDelete('<?= htmlspecialchars($id) ?>', '<?= addslashes($title) ?>')">
                    <i class="fa fa-trash-o"></i> Delete
                </button>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>

</div><!-- .an-wrap -->
</div><!-- .content-wrapper -->

<!-- ── Delete confirm modal ── -->
<div class="an-modal-overlay" id="anModal">
    <div class="an-modal">
        <div class="an-modal-icon"><i class="fa fa-trash-o"></i></div>
        <h4>Delete Notice</h4>
        <p id="anModalText">Are you sure you want to delete this notice? This cannot be undone.</p>
        <div class="an-modal-btns">
            <button class="an-modal-cancel" onclick="anCloseModal()">Cancel</button>
            <a href="#" class="an-modal-confirm" id="anModalConfirm">
                <i class="fa fa-trash-o"></i> Delete
            </a>
        </div>
    </div>
</div>

<script>
(function(){
    var BASE_URL = '<?= rtrim(base_url(), '/') ?>';

    /* ── Delete modal ── */
    window.anConfirmDelete = function(id, title) {
        document.getElementById('anModalText').textContent =
            'Are you sure you want to delete "' + title + '"? This cannot be undone.';
        document.getElementById('anModalConfirm').href =
            BASE_URL + '/NoticeAnnouncement/delete/' + id;
        document.getElementById('anModal').classList.add('open');
    };

    window.anCloseModal = function() {
        document.getElementById('anModal').classList.remove('open');
    };

    document.getElementById('anModal').addEventListener('click', function(e){
        if (e.target === this) anCloseModal();
    });

    /* ── Client-side filtering ── */
    var $search    = document.getElementById('anSearch');
    var $catSelect = document.getElementById('anFilterCat');
    var $priSelect = document.getElementById('anFilterPri');
    var $countEl   = document.getElementById('anFilterCount');
    var $noResults = document.getElementById('anNoResults');
    var $cards     = document.querySelectorAll('#anList .an-card');

    if ($search) {
        function applyFilters() {
            var q   = ($search.value || '').toLowerCase().trim();
            var cat = $catSelect.value;
            var pri = $priSelect.value;
            var visible = 0;

            $cards.forEach(function(card){
                var matchQ   = !q   || card.dataset.title.includes(q) || card.dataset.desc.includes(q);
                var matchCat = !cat || card.dataset.category === cat;
                var matchPri = !pri || card.dataset.priority === pri;
                var show = matchQ && matchCat && matchPri;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if ($countEl) {
                $countEl.innerHTML =
                    'Showing <strong>' + visible + '</strong> notice' + (visible !== 1 ? 's' : '');
            }
            if ($noResults) {
                $noResults.style.display = (visible === 0 && $cards.length > 0) ? 'block' : 'none';
            }
        }

        $search.addEventListener('input', applyFilters);
        $catSelect.addEventListener('change', applyFilters);
        $priSelect.addEventListener('change', applyFilters);
    }
})();
</script>
