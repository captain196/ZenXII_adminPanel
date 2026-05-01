<?php defined('BASEPATH') or exit('No direct script access allowed');

$months = $academic_months ?? [];
$cs     = $class_sections  ?? [];

// Derive unique classes for the cascading class → section filter.
$classes = [];
foreach ($cs as $row) {
    if (!in_array($row['class'], $classes, true)) $classes[] = $row['class'];
}
?>

<div class="content-wrapper">
<div class="fm-wrap fm-page">

    <!-- ── Top Bar ── -->
    <div class="fm-topbar">
        <div class="fm-topbar-left">
            <h1 class="fm-page-title">Generate Fee Demands</h1>
            <nav class="fm-breadcrumb">
                <a href="<?= base_url('dashboard') ?>">Dashboard</a>
                <span class="fm-bc-sep">/</span>
                <a href="<?= base_url('fees/dashboard') ?>">Fees</a>
                <span class="fm-bc-sep">/</span>
                <span>Generate Demands &mdash; <?= htmlspecialchars($session_year ?? '') ?></span>
            </nav>
        </div>
        <div class="fm-topbar-right">
            <a href="<?= base_url('fees/fees_chart') ?>" class="fm-btn fm-btn-ghost">
                <i class="fa fa-table"></i> Fee Chart
            </a>
            <a href="<?= base_url('fees/student_ledger') ?>" class="fm-btn fm-btn-ghost">
                <i class="fa fa-book"></i> Student Ledger
            </a>
        </div>
    </div>

    <!-- ── Explainer ── -->
    <div class="fm-card">
        <div class="fm-explainer">
            <div class="fm-explainer-icon"><i class="fa fa-lightbulb-o"></i></div>
            <div class="fm-explainer-body">
                <div class="fm-explainer-title">How this works</div>
                <div class="fm-explainer-text">
                    The <strong>Fee Chart</strong> defines per-class rates (Tuition ₹X/month, Library ₹Y/month, …).
                    This page <strong>materialises those rates</strong> into unpaid-demand rows for every student &mdash; which is what parents pay against.
                    Re-running is safe: existing demands are <strong>skipped</strong>, never duplicated.
                </div>
            </div>
        </div>
    </div>

    <!-- ── Scope Form ── -->
    <div class="fm-card">
        <div class="fm-card-hdr">
            <h2 class="fm-card-title"><i class="fa fa-sliders"></i> Scope</h2>
            <span class="fm-card-hint">Pick the students and periods you want billed.</span>
        </div>

        <div class="gd-form">
            <div class="gd-field">
                <label>Month</label>
                <select id="gdMonth" class="fm-select gd-select-wide">
                    <option value="all">All months (April &rarr; March)</option>
                    <?php foreach ($months as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="gd-hint">"All months" materialises the whole session's monthly demands at once, plus Yearly Fees.</div>
            </div>

            <div class="gd-field">
                <label>Class</label>
                <select id="gdClass" class="fm-select gd-select-wide">
                    <option value="">All classes (with a fee chart)</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="gd-hint">Only classes that have a configured fee chart appear here.</div>
            </div>

            <div class="gd-field">
                <label>Section</label>
                <select id="gdSection" class="fm-select gd-select-wide">
                    <option value="">All sections</option>
                </select>
                <div class="gd-hint">Cascades from the class above. Leave blank for every section.</div>
            </div>
        </div>

        <div class="gd-actions">
            <button class="fm-btn fm-btn-primary" id="btnPreview"><i class="fa fa-eye"></i> Preview</button>
            <div class="gd-actions-hint" id="gdHint">Click <strong>Preview</strong> to see how many students will be billed before committing.</div>
        </div>
    </div>

    <!-- ── Preview Card ── -->
    <div class="fm-card" id="gdPreviewCard" style="display:none;">
        <div class="fm-card-hdr">
            <h2 class="fm-card-title"><i class="fa fa-check-square-o"></i> Preview</h2>
            <span class="fm-card-hint">Review the scope. Existing demands are always skipped.</span>
        </div>
        <div class="gd-preview-body" id="gdPreviewBody"></div>
        <div class="gd-card-footer">
            <button class="fm-btn fm-btn-primary" id="btnGenerate"><i class="fa fa-magic"></i> Generate Demands</button>
            <button class="fm-btn fm-btn-ghost" id="btnCancelPreview"><i class="fa fa-times"></i> Cancel</button>
        </div>
    </div>

    <!-- ── Result Card ── -->
    <div class="fm-card" id="gdResultCard" style="display:none;">
        <div class="fm-card-hdr">
            <h2 class="fm-card-title" id="gdResultTitle"><i class="fa fa-spinner fa-spin"></i> Generating…</h2>
        </div>
        <div class="gd-result-body" id="gdResultBody"></div>
    </div>

</div>
</div>

<script>
const GD_CS = <?= json_encode($cs ?? []) ?>;
const BASE  = '<?= rtrim(base_url(), '/') ?>/';
// CSRF — the global fetch wrapper only injects into string bodies,
// so FormData POSTs must append the token manually. Server rotates
// the hash after every request; refreshCsrf() keeps us in sync.
let GD_CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
let GD_CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
</script>

<style>
/* ═══ Reuse the fm- design tokens from defaulter_report.php ═══ */
.fm-wrap { max-width:1280px; margin:0 auto; padding:20px 24px 40px; color:var(--t1,#0f172a); font-family:'Plus Jakarta Sans',var(--font-b,sans-serif); }
.fm-topbar { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.fm-page-title { font-family:'Fraunces',serif; font-size:1.35rem; font-weight:600; color:var(--t1,#0f172a); margin:0; letter-spacing:-.01em; }
.fm-breadcrumb { font-size:.78rem; color:var(--t3,#94a3b8); margin-top:3px; }
.fm-breadcrumb a { color:var(--gold,#0f766e); text-decoration:none; }
.fm-breadcrumb a:hover { text-decoration:underline; }
.fm-bc-sep { margin:0 5px; color:var(--t3,#94a3b8); }
.fm-topbar-right { display:flex; gap:8px; }

.fm-card { background:var(--bg2,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; box-shadow:var(--sh,0 1px 3px rgba(15,31,61,.08)); margin-bottom:18px; overflow:hidden; }
.fm-card-hdr { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid var(--border,#e5e7eb); gap:10px; flex-wrap:wrap; }
.fm-card-title { font-family:'Fraunces',serif; font-size:15px; font-weight:600; margin:0; color:var(--t1,#0f172a); display:flex; align-items:center; gap:8px; }
.fm-card-title i { color:var(--gold,#0f766e); font-size:14px; }
.fm-card-hint { font-size:12px; color:var(--t3,#94a3b8); }

.fm-select { height:38px; padding:0 34px 0 12px; border:1.5px solid var(--border,#e5e7eb); border-radius:8px; font-size:13.5px; color:var(--t1,#0f172a); background:var(--bg2,#fff); cursor:pointer; outline:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; transition:border-color .2s; font-family:inherit; }
.fm-select:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px rgba(15,118,110,.15); }

.fm-btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid transparent; transition:all .15s; text-decoration:none; line-height:1; font-family:inherit; }
.fm-btn-ghost { background:var(--bg2,#fff); border-color:var(--border,#e5e7eb); color:var(--t1,#0f172a); }
.fm-btn-ghost:hover { border-color:var(--gold,#0f766e); color:var(--gold,#0f766e); }
.fm-btn-primary { background:var(--gold,#0f766e); color:#fff; border-color:var(--gold,#0f766e); }
.fm-btn-primary:hover { background:#0d6961; }
.fm-btn[disabled] { opacity:.55; cursor:not-allowed; }

/* ═══ Generate-Demands-specific tweaks (gd- prefix) ═══ */
.fm-explainer { display:flex; gap:14px; padding:16px 20px; align-items:flex-start; }
.fm-explainer-icon { width:38px; height:38px; border-radius:10px; background:rgba(15,118,110,.10); color:#0f766e; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.fm-explainer-title { font-family:'Fraunces',serif; font-size:14px; font-weight:600; color:var(--t1,#0f172a); margin-bottom:4px; }
.fm-explainer-text { font-size:13px; color:var(--t2,#475569); line-height:1.6; }

.gd-form { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; padding:20px; }
.gd-field label { display:block; font-weight:700; font-size:11.5px; color:var(--t2,#475569); margin-bottom:8px; text-transform:uppercase; letter-spacing:.5px; }
.gd-select-wide { width:100%; }
.gd-hint { margin-top:6px; font-size:11.5px; color:var(--t3,#94a3b8); line-height:1.45; }

.gd-actions { display:flex; align-items:center; gap:12px; padding:14px 20px; border-top:1px solid var(--border,#e5e7eb); background:var(--bg,#f8fafc); }
.gd-actions-hint { margin-left:auto; color:var(--t3,#94a3b8); font-size:12.5px; }
.gd-card-footer { display:flex; gap:10px; padding:14px 20px; border-top:1px solid var(--border,#e5e7eb); background:var(--bg,#f8fafc); }

.gd-preview-body { padding:20px; }
.gd-result-body  { padding:20px; }

/* Summary numbers row */
.gd-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:14px; }
.gd-sum-card { padding:16px 14px; border-radius:10px; background:var(--bg,#f8fafc); border:1px solid var(--border,#e5e7eb); text-align:center; transition:transform .15s ease; }
.gd-sum-card:hover { transform:translateY(-1px); }
.gd-sum-num { font-size:1.8rem; font-weight:800; color:var(--gold,#0f766e); line-height:1.1; font-family:'Plus Jakarta Sans',sans-serif; font-variant-numeric:tabular-nums; }
.gd-sum-num--warn { color:#d97706; }
.gd-sum-num--error { color:#dc2626; }
.gd-sum-lbl { font-size:11px; color:var(--t2,#475569); font-weight:700; margin-top:4px; text-transform:uppercase; letter-spacing:.4px; }

.gd-scope-pill { margin-top:6px; padding:10px 14px; border-radius:8px; background:rgba(15,118,110,.05); border:1px solid rgba(15,118,110,.15); font-size:13px; color:var(--t1,#0f172a); line-height:1.55; }
.gd-scope-pill strong { color:var(--gold,#0f766e); font-weight:700; }

.gd-breakdown { margin-top:14px; border:1px solid var(--border,#e5e7eb); border-radius:8px; overflow:hidden; }
.gd-breakdown table { width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed; }
.gd-breakdown colgroup col.c-class   { width:30%; }
.gd-breakdown colgroup col.c-section { width:30%; }
.gd-breakdown colgroup col.c-num     { width:20%; }
.gd-breakdown th,
.gd-breakdown td { padding:12px 16px; text-align:left !important; vertical-align:middle; }
.gd-breakdown th { background:var(--bg,#f8fafc); font-size:11px; text-transform:uppercase; color:var(--t2,#475569); letter-spacing:.5px; font-weight:700; border-bottom:1px solid var(--border,#e5e7eb); }
.gd-breakdown td { border-top:1px solid var(--border,#f1f5f9); color:var(--t1,#0f172a); }
.gd-breakdown td.num { font-variant-numeric:tabular-nums; font-weight:600; }
.gd-breakdown tr:hover td { background:rgba(15,118,110,.03); }

.gd-warn { margin-top:12px; padding:12px 14px; background:rgba(217,119,6,.08); border:1px solid rgba(217,119,6,.25); border-left:4px solid #d97706; border-radius:8px; font-size:13px; color:#92400e; }
.gd-warn strong { color:#78350f; }
.gd-err { margin-top:12px; padding:12px 14px; background:rgba(220,38,38,.08); border:1px solid rgba(220,38,38,.25); border-left:4px solid #dc2626; border-radius:8px; font-size:13px; color:#7f1d1d; }
.gd-err strong { color:#7f1d1d; }

.gd-progress { padding:16px 18px; background:rgba(15,118,110,.06); border:1px solid rgba(15,118,110,.20); border-radius:8px; color:#134e4a; font-size:13.5px; line-height:1.6; }
.gd-prog-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.gd-prog-step { display:inline-block; padding:3px 10px; background:var(--gold,#0f766e); color:#fff; border-radius:12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
.gd-prog-pct { font-size:14px; font-weight:800; color:var(--gold,#0f766e); font-variant-numeric:tabular-nums; }
.gd-prog-bar { height:8px; background:rgba(15,118,110,.12); border-radius:4px; overflow:hidden; margin-bottom:8px; }
.gd-prog-bar-fill { height:100%; background:linear-gradient(90deg,#0f766e,#14b8a6); transition:width .4s ease-out; border-radius:4px; }
.gd-prog-text { font-size:13px; color:#0f766e; }

.gd-success { padding:16px 18px; background:rgba(22,163,74,.08); border:1px solid rgba(22,163,74,.25); border-left:4px solid #16a34a; border-radius:8px; color:#14532d; font-size:13.5px; font-weight:600; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.gd-success i { font-size:18px; color:#16a34a; }
.gd-success-meta { color:#166534; font-weight:500; font-size:12.5px; margin-left:auto; }

.gd-error-list { margin-top:12px; padding:10px 14px; background:rgba(220,38,38,.04); border:1px solid rgba(220,38,38,.18); border-radius:6px; font-size:12.5px; }
.gd-error-list summary { cursor:pointer; font-weight:600; color:#7f1d1d; padding:4px 0; }
.gd-error-list ul { margin:8px 0 0; padding-left:20px; color:#64748b; max-height:200px; overflow-y:auto; }
.gd-error-list code { background:rgba(220,38,38,.10); padding:1px 6px; border-radius:3px; font-family:'JetBrains Mono',monospace; font-size:11px; color:#7f1d1d; }

.gd-actions-row { display:flex; gap:8px; flex-wrap:wrap; padding-top:12px; border-top:1px dashed var(--border,#e5e7eb); }
.gd-footnote { margin-top:12px; font-size:12px; color:var(--t3,#94a3b8); }

@media (max-width:980px) {
    .gd-form { grid-template-columns:1fr 1fr; }
    .gd-summary { grid-template-columns:1fr 1fr; }
}
@media (max-width:640px) {
    .gd-form { grid-template-columns:1fr; }
    .gd-summary { grid-template-columns:1fr 1fr; }
}
</style>

<script>
(function(){
    // Build { class -> [sections] } lookup for cascading filter
    const sectionsByClass = {};
    const allSections = new Set();
    GD_CS.forEach(r => {
        if (!sectionsByClass[r.class]) sectionsByClass[r.class] = [];
        if (!sectionsByClass[r.class].includes(r.section)) sectionsByClass[r.class].push(r.section);
        allSections.add(r.section);
    });
    Object.keys(sectionsByClass).forEach(c => sectionsByClass[c].sort());

    const $ = id => document.getElementById(id);
    const $month = $('gdMonth'), $class = $('gdClass'), $section = $('gdSection');
    const $btnPrev = $('btnPreview'), $btnGen = $('btnGenerate'), $btnCancel = $('btnCancelPreview');
    const $previewCard = $('gdPreviewCard'), $previewBody = $('gdPreviewBody');
    const $resultCard  = $('gdResultCard'),  $resultTitle = $('gdResultTitle'), $resultBody = $('gdResultBody');
    const $hint = $('gdHint');

    const escHtml = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
    const fmtNum  = n => Number(n || 0).toLocaleString('en-IN');

    // Build a FormData pre-loaded with the rotating CSRF token.
    function buildFD() {
        const fd = new FormData();
        fd.append(GD_CSRF_NAME, GD_CSRF_HASH);
        return fd;
    }
    // Update token if the response rotated it (CI rotates on each POST).
    function absorbCsrf(j) {
        if (j && j.csrf_hash) GD_CSRF_HASH = j.csrf_hash;
        if (j && j.csrf_token) GD_CSRF_HASH = j.csrf_token;
    }

    function rebuildSections() {
        const c = $class.value;
        const list = c === '' ? Array.from(allSections).sort() : (sectionsByClass[c] || []);
        $section.innerHTML = '<option value="">All sections</option>' +
            list.map(s => `<option value="${escHtml(s)}">${escHtml(s)}</option>`).join('');
    }
    $class.addEventListener('change', rebuildSections);
    rebuildSections();

    // ── Preview ────────────────────────────────────────────
    $btnPrev.addEventListener('click', async () => {
        $btnPrev.disabled = true;
        $btnPrev.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Loading…';
        $resultCard.style.display = 'none';
        try {
            const fd = buildFD();
            fd.append('month',   $month.value);
            fd.append('class',   $class.value);
            fd.append('section', $section.value);

            const res = await fetch(BASE + 'fees/preview_demand_generation', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });
            const j = await res.json();
            absorbCsrf(j);
            if (j.status !== 'success') throw new Error(j.message || 'Preview failed');
            renderPreview(j.data || j);
            $previewCard.style.display = '';
            $previewCard.scrollIntoView({ behavior:'smooth', block:'start' });
            $hint.innerHTML = 'Review the counts below, then click <strong>Generate Demands</strong> to commit.';
        } catch (e) {
            alert('Could not load preview: ' + e.message);
        } finally {
            $btnPrev.disabled = false;
            $btnPrev.innerHTML = '<i class="fa fa-eye"></i> Preview';
        }
    });

    function renderPreview(d) {
        const monthLbl   = d.month === 'all' ? 'All 12 months' : d.month;
        const classLbl   = d.class   || 'All classes';
        const sectionLbl = d.section || 'All sections';
        const hasWork    = (d.student_count || 0) > 0;

        let html = `
            <div class="gd-summary">
                <div class="gd-sum-card">
                    <div class="gd-sum-num">${fmtNum(d.student_count)}</div>
                    <div class="gd-sum-lbl">Students</div>
                </div>
                <div class="gd-sum-card">
                    <div class="gd-sum-num">${fmtNum(d.class_section_count)}</div>
                    <div class="gd-sum-lbl">Class / Sections</div>
                </div>
                <div class="gd-sum-card">
                    <div class="gd-sum-num">${fmtNum(d.month_count)}</div>
                    <div class="gd-sum-lbl">Months</div>
                </div>
                <div class="gd-sum-card">
                    <div class="gd-sum-num">~${fmtNum(d.estimated_demands)}</div>
                    <div class="gd-sum-lbl">Estimated Demands</div>
                </div>
            </div>
            <div class="gd-scope-pill">
                Scope: <strong>${escHtml(monthLbl)}</strong>
                &nbsp;·&nbsp; <strong>${escHtml(classLbl)}</strong>
                &nbsp;·&nbsp; <strong>${escHtml(sectionLbl)}</strong>
            </div>
        `;

        if (d.class_sections && d.class_sections.length) {
            html += `
                <div class="gd-breakdown">
                    <table>
                        <colgroup>
                            <col class="c-class">
                            <col class="c-section">
                            <col class="c-num">
                            <col class="c-num">
                        </colgroup>
                        <thead><tr>
                            <th>Class</th><th>Section</th>
                            <th class="num">Students</th><th class="num">Fee Heads</th>
                        </tr></thead><tbody>
            `;
            d.class_sections.forEach(r => {
                html += `<tr>
                    <td>${escHtml(r.class)}</td>
                    <td>${escHtml(r.section)}</td>
                    <td class="num">${fmtNum(r.students)}</td>
                    <td class="num">${fmtNum(r.heads)}</td>
                </tr>`;
            });
            html += `</tbody></table></div>`;
        }

        if (d.no_chart_sections && d.no_chart_sections.length) {
            html += `<div class="gd-warn">
                <strong>${d.no_chart_sections.length} section(s) have no fee chart</strong> &mdash; will be skipped:<br>
                ${d.no_chart_sections.map(escHtml).join(', ')}
            </div>`;
        }
        if (d.no_roster_sections && d.no_roster_sections.length) {
            html += `<div class="gd-warn">
                <strong>${d.no_roster_sections.length} section(s) have no students enrolled</strong>:<br>
                ${d.no_roster_sections.map(escHtml).join(', ')}
            </div>`;
        }
        if (!hasWork) {
            html += `<div class="gd-warn">
                <strong>No students match this scope.</strong>
                Check that the selected class has a fee chart <em>and</em> students enrolled.
            </div>`;
        }

        $previewBody.innerHTML = html;
        $btnGen.disabled = !hasWork;
    }

    $btnCancel.addEventListener('click', () => {
        $previewCard.style.display = 'none';
        $hint.innerHTML = 'Click <strong>Preview</strong> to see how many students will be billed before committing.';
    });

    // ── Generate (async job + poll) ────────────────────────
    let pollTimer = null;

    function noun(n, singular, plural) {
        return Number(n) === 1 ? singular : (plural || singular + 's');
    }

    function renderRunningState(job) {
        const total   = Number(job.totalStudents    || 0);
        const done    = Number(job.processedStudents || 0);
        const created = Number(job.demandsCreated   || 0);
        const skipped = Number(job.demandsSkipped   || 0);
        const pct     = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        const phase   = job.status === 'pending' ? 'Queued' : 'Processing';

        return `
            <div class="gd-progress">
                <div class="gd-prog-head">
                    <span class="gd-prog-step">${escHtml(phase)}</span>
                    <span class="gd-prog-pct">${pct}%</span>
                </div>
                <div class="gd-prog-bar"><div class="gd-prog-bar-fill" style="width:${pct}%"></div></div>
                <div class="gd-prog-text">
                    ${fmtNum(done)} / ${fmtNum(total)} ${noun(total, 'student')} processed
                    &nbsp;·&nbsp; ${fmtNum(created)} ${noun(created, 'demand')} created
                    ${skipped > 0 ? `&nbsp;·&nbsp; ${fmtNum(skipped)} skipped` : ''}
                </div>
            </div>`;
    }

    function renderDoneState(job) {
        const students = Number(job.processedStudents || 0);
        const created  = Number(job.demandsCreated   || 0);
        const skipped  = Number(job.demandsSkipped   || 0);
        const failed   = Number(job.failureCount     || 0);
        const startedAt   = job.startedAt   ? new Date(job.startedAt).getTime()   : null;
        const completedAt = job.completedAt ? new Date(job.completedAt).getTime() : null;
        const secs = (startedAt && completedAt) ? Math.max(1, Math.round((completedAt - startedAt) / 1000)) : null;

        return `
            <div class="gd-success">
                <i class="fa fa-check-circle"></i>
                ${fmtNum(created)} ${noun(created,'demand')} created across
                ${fmtNum(students)} ${noun(students,'student')}.
                ${skipped > 0 ? `${fmtNum(skipped)} skipped (already existed).` : ''}
                ${secs !== null ? `<span class="gd-success-meta">Completed in ${secs}s.</span>` : ''}
            </div>
            <div class="gd-summary" style="margin-top:14px;">
                <div class="gd-sum-card">
                    <div class="gd-sum-num">${fmtNum(students)}</div>
                    <div class="gd-sum-lbl">Students Processed</div>
                </div>
                <div class="gd-sum-card">
                    <div class="gd-sum-num">${fmtNum(created)}</div>
                    <div class="gd-sum-lbl">Demands Created</div>
                </div>
                <div class="gd-sum-card">
                    <div class="gd-sum-num gd-sum-num--warn">${fmtNum(skipped)}</div>
                    <div class="gd-sum-lbl">Skipped (existing)</div>
                </div>
                <div class="gd-sum-card">
                    <div class="gd-sum-num ${failed > 0 ? 'gd-sum-num--error' : ''}">${fmtNum(failed)}</div>
                    <div class="gd-sum-lbl">${noun(failed,'Error','Errors')}</div>
                </div>
            </div>
            ${failed > 0 && Array.isArray(job.errors) && job.errors.length ? `
                <details class="gd-error-list">
                    <summary>Show ${failed > job.errors.length ? 'latest ' + job.errors.length + ' of ' + failed : failed} failures</summary>
                    <ul>
                        ${job.errors.map(e => `<li><code>${escHtml(e.studentId || '?')}</code> &mdash; ${escHtml(e.error || '?')}</li>`).join('')}
                    </ul>
                </details>` : ''}
            <div class="gd-footnote">
                Parent apps receive the new demands via Firestore listeners within ~1 second.
            </div>
            <div class="gd-actions-row" style="margin-top:14px;">
                <button class="fm-btn fm-btn-ghost fm-btn-sm" id="btnGenerateAnother">
                    <i class="fa fa-refresh"></i> Generate Another
                </button>
                <a href="${BASE}fees/student_ledger" class="fm-btn fm-btn-ghost fm-btn-sm">
                    <i class="fa fa-book"></i> Open Student Ledger
                </a>
                <a href="${BASE}fees/fees_counter" class="fm-btn fm-btn-ghost fm-btn-sm">
                    <i class="fa fa-desktop"></i> Go to Fee Counter
                </a>
            </div>
        `;
    }

    function renderFailedState(job) {
        return `
            <div class="gd-err">
                <strong>Generation failed.</strong><br>
                ${escHtml(job.failureReason || 'Unknown error.')}
            </div>
            ${Array.isArray(job.errors) && job.errors.length ? `
                <details class="gd-error-list">
                    <summary>Show per-student failures (${job.errors.length})</summary>
                    <ul>
                        ${job.errors.map(e => `<li><code>${escHtml(e.studentId||'?')}</code> &mdash; ${escHtml(e.error||'?')}</li>`).join('')}
                    </ul>
                </details>` : ''}
        `;
    }

    function stopPoll() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    async function pollJob(jobId) {
        try {
            const res = await fetch(BASE + 'fees/get_generation_job?jobId=' + encodeURIComponent(jobId), {
                method: 'GET',
                credentials: 'same-origin',
            });
            const j = await res.json();
            if (j.status !== 'success') throw new Error(j.message || 'Poll failed');
            const job = j.data || j;

            if (job.status === 'pending' || job.status === 'running') {
                $resultTitle.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' +
                    (job.status === 'pending' ? 'Queued&hellip;' : 'Generating&hellip;');
                $resultBody.innerHTML = renderRunningState(job);
            } else if (job.status === 'completed') {
                stopPoll();
                $resultTitle.innerHTML = '<i class="fa fa-check-circle" style="color:#16a34a"></i> Done';
                $resultBody.innerHTML = renderDoneState(job);
                // Hide preview card; it's stale once the job landed.
                $previewCard.style.display = 'none';
                $btnGen.disabled = false;
                $btnGen.innerHTML = '<i class="fa fa-magic"></i> Generate Demands';
                const btnAgain = document.getElementById('btnGenerateAnother');
                if (btnAgain) btnAgain.onclick = () => {
                    $resultCard.style.display = 'none';
                    $btnPrev.scrollIntoView({ behavior:'smooth', block:'start' });
                };
            } else if (job.status === 'failed') {
                stopPoll();
                $resultTitle.innerHTML = '<i class="fa fa-exclamation-triangle" style="color:#dc2626"></i> Failed';
                $resultBody.innerHTML = renderFailedState(job);
                $btnGen.disabled = false;
                $btnGen.innerHTML = '<i class="fa fa-magic"></i> Generate Demands';
            }
        } catch (e) {
            // Transient network issue — keep polling.
            console.warn('[generate_demands] poll error', e);
        }
    }

    $btnGen.addEventListener('click', async () => {
        $btnGen.disabled = true;
        $btnGen.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Queuing&hellip;';

        $resultCard.style.display = '';
        $resultTitle.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Queuing&hellip;';
        $resultBody.innerHTML = `
            <div class="gd-progress">
                <span class="gd-prog-step">Submitting</span>
                Creating the generation job&hellip;
            </div>`;
        $resultCard.scrollIntoView({ behavior:'smooth', block:'start' });

        try {
            const fd = buildFD();
            fd.append('month',   $month.value);
            fd.append('class',   $class.value);
            fd.append('section', $section.value);

            const res = await fetch(BASE + 'fees/generate_monthly_demands', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });
            const j = await res.json();
            absorbCsrf(j);
            if (j.status !== 'success') throw new Error(j.message || 'Could not queue job');

            const payload = j.data || j;
            const jobId = payload.jobId;
            if (!jobId) throw new Error('Server did not return a jobId.');

            // PHP now runs generation synchronously — the POST response
            // already carries the completed job doc. If we have
            // jobStatus + counts in the payload, render the Done card
            // directly and skip polling. Otherwise fall back to the
            // legacy poll loop (when we eventually migrate to Cloud
            // Function, processing happens out-of-band → polling kicks in).
            if (payload.jobStatus === 'completed') {
                stopPoll();
                // Synthesize the job-doc shape pollJob expects.
                const done = Object.assign({}, payload, { status: 'completed' });
                $resultTitle.innerHTML = '<i class="fa fa-check-circle" style="color:#16a34a"></i> Done';
                $resultBody.innerHTML = renderDoneState(done);
                $previewCard.style.display = 'none';
                $btnGen.disabled = false;
                $btnGen.innerHTML = '<i class="fa fa-magic"></i> Generate Demands';
                const btnAgain = document.getElementById('btnGenerateAnother');
                if (btnAgain) btnAgain.onclick = () => {
                    $resultCard.style.display = 'none';
                    $btnPrev.scrollIntoView({ behavior:'smooth', block:'start' });
                };
            } else if (payload.jobStatus === 'failed') {
                stopPoll();
                $resultTitle.innerHTML = '<i class="fa fa-exclamation-triangle" style="color:#dc2626"></i> Failed';
                const failed = Object.assign({}, payload, { status: 'failed' });
                $resultBody.innerHTML = renderFailedState(failed);
                $btnGen.disabled = false;
                $btnGen.innerHTML = '<i class="fa fa-magic"></i> Generate Demands';
            } else {
                // Async path (Cloud Function worker) — poll as before.
                pollJob(jobId);
                stopPoll();
                pollTimer = setInterval(() => pollJob(jobId), 2000);
            }
        } catch (e) {
            $resultTitle.innerHTML = '<i class="fa fa-exclamation-triangle" style="color:#dc2626"></i> Failed to queue';
            $resultBody.innerHTML = `<div class="gd-err"><strong>Error:</strong> ${escHtml(e.message)}</div>`;
            $btnGen.disabled = false;
            $btnGen.innerHTML = '<i class="fa fa-magic"></i> Generate Demands';
        }
    });
})();
</script>
