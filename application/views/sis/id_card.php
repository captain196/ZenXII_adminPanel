<?php
defined('BASEPATH') or exit('No direct script access allowed');

// QR identity helper — produces the URL-safe `base64("{schoolId}|{studentId}")`
// token used by the attendance scan endpoint. Loaded here (not auto-loaded)
// so the helper stays opt-in for views that actually need it.
$CI =& get_instance();
$CI->load->helper('qr_token');

$students       = $students       ?? [];
$card_versions  = $card_versions  ?? [];   // ID-MINT-1 — {studentId: activeCardVersion}
$session_year   = $session_year   ?? '';
$school_profile = $school_profile ?? [];

$schoolN    = $school_profile['school_name'] ?? $school_name ?? '';
$schoolAddr = $school_profile['address']     ?? '';
$schoolLogo = $school_profile['logo']        ?? base_url('tools/image/default-school.jpeg');
$schoolPhone= $school_profile['phone']       ?? '';
$fallback   = base_url('tools/image/default-school.jpeg');

// schoolId for QR encoding — `$school_name` in this codebase IS the SCH_xxx
// schoolId per the project's non-obvious-conventions memory. Falls back to
// an empty string if the controller didn't pass it; the per-card render
// then skips the QR (no token, no img).
$qrSchoolId = (string) ($school_name ?? '');

function idc_photo($s) {
    if (!empty($s['Profile Pic']) && is_string($s['Profile Pic'])) return $s['Profile Pic'];
    if (!empty($s['Doc']['Photo'])) {
        $e = $s['Doc']['Photo'];
        return is_array($e) ? ($e['url'] ?? '') : $e;
    }
    return '';
}

function idc_dob($raw) {
    if (!$raw) return '';
    foreach (['Y-m-d','d-m-Y','d/m/Y','m/d/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('d M Y');
    }
    return $raw;
}

/**
 * Idempotent prefix normaliser. Adds the prefix only when missing —
 * post Phase-1 canonical migration most rows arrive as "Class 8th" /
 * "Section A" already, but legacy / partial rows may carry just
 * "8th" / "A". Either shape now produces the canonical "Class 8th" /
 * "Section A" once and only once. Pre-fix the display template did
 * `Class <?= $cls ?>` which double-prefixed canonical rows into
 * "Class Class 8th".
 */
function idc_with_prefix($val, $prefix) {
    $v = trim((string) $val);
    if ($v === '') return '';
    return (stripos($v, $prefix . ' ') === 0) ? $v : ($prefix . ' ' . $v);
}

$filterClasses  = [];
$filterSections = [];
foreach ($students as $s) {
    // Normalise once so dropdown options + per-card data-attrs use the
    // same canonical "Class 8th" / "Section A" strings (filter equality
    // depends on this match — if dropdown carries "8th" but data-class
    // carries "Class 8th", filtering silently breaks).
    $c   = idc_with_prefix($s['Class']   ?? '', 'Class');
    $sec = idc_with_prefix($s['Section'] ?? '', 'Section');
    if ($c)   $filterClasses[$c]    = true;
    if ($sec) $filterSections[$sec] = true;
}
ksort($filterClasses);
ksort($filterSections);
?>

<style>
:root { --idc: var(--gold, #BC5A3C); --idc-lt: rgba(188,90,60,.1); }

.idc-wrap { max-width:1280px; margin:0 auto; padding:24px 20px; }

/* ── Top Bar ── */
.idc-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.idc-topbar h1 { margin:0; font-size:1.2rem; color:var(--t1); font-family:var(--font-b); display:flex; align-items:center; gap:8px; }
.idc-topbar h1 i { color:var(--idc); }
.idc-actions { display:flex; gap:8px; }
.idc-btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-size:.82rem; font-weight:600;
    display:inline-flex; align-items:center; gap:6px; transition:all .2s; }
.idc-btn-primary { background:var(--idc); color:#fff; }
.idc-btn-primary:hover { opacity:.85; }
.idc-btn-outline { background:transparent; color:var(--idc); border:1.5px solid var(--idc); }
.idc-btn-outline:hover { background:var(--idc-lt); }

/* ── Filter Bar ── */
.idc-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px;
    padding:12px 16px; background:var(--bg2); border:1px solid var(--border); border-radius:10px; }
.idc-search-wrap { position:relative; flex:1; min-width:180px; }
.idc-search-wrap i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--t3); font-size:.85rem; }
.idc-search { width:100%; padding:8px 12px 8px 34px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--bg); color:var(--t1); font-size:.85rem; outline:none; }
.idc-search:focus { border-color:var(--idc); }
.idc-select { padding:8px 12px; border:1.5px solid var(--border); border-radius:8px; background:var(--bg);
    color:var(--t1); font-size:.82rem; min-width:110px; }
.idc-counter { font-size:.82rem; color:var(--t2); white-space:nowrap; }
.idc-counter b { color:var(--idc); }

.idc-empty { text-align:center; padding:60px 20px; color:var(--t3); background:var(--bg2);
    border:1px solid var(--border); border-radius:10px; }
.idc-empty i { font-size:2.5rem; display:block; margin-bottom:10px; }

/* ── Cards Grid ── */
/* IDC-PRINT-1 — physical geometry in ONE place, so the screen card, the print
   rules and the bulk-print window cannot drift apart.
     CR80 portrait      53.98 x 85.6mm — what badge holders and card printers take
     card on screen     260 x 412px    — the same 1:1.5858 ratio
     print scale        53.98mm / 68.79mm (260px) = 0.785 */

.idc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:20px; justify-items:center; }

<?php
/* IDC-CSS-1 — the card's own rules come from the shared partial so the page and
   the bulk-print window cannot drift. Layout around the card (grid, toolbar,
   filters, modals) stays here: only the print window needs the card itself. */
include APPPATH . 'views/sis/_id_card_css.php';
?>
<?= $IDC_CARD_CSS ?>

/* Print one btn */
.idc-print-one { margin-top:6px; padding:5px 14px; border:1px solid var(--border); border-radius:6px;
    background:var(--bg2); color:var(--t2); cursor:pointer; font-size:.75rem; transition:all .2s; }
.idc-print-one:hover { border-color:var(--idc); color:var(--idc); }

/* ── IDC-PRINT-1 · PRINT GEOMETRY ─────────────────────────────────────────

   The old rule laid out three 260px columns with 8mm gaps and 8mm padding:

       3 x 68.79mm  = 206.4mm   (260px at 96dpi is 68.79mm, not 260 "units")
       2 x 8mm gap  =  16.0mm
       2 x 8mm pad  =  16.0mm
       ------------------------
                      238.4mm   against an A4 width of 210mm

   — so the third column ran off the page on every print. There was also no
   @page rule at all, which left paper size and margins to whatever the browser
   defaulted to; Chrome and Firefox disagree.

   At true CR80 the same three columns fit comfortably:
       3 x 53.98mm + 2 x 8mm gap = 177.9mm  within A4's 190mm printable width
       3 rows x (85.6 + 6mm)     = 274.8mm  within A4's 277mm printable height
   — nine cards per sheet, and each one fits an off-the-shelf badge holder. */
@page { size:A4 portrait; margin:10mm; }

@media print {
    .no-print { display:none !important; }

    .idc-grid {
        grid-template-columns:repeat(3, var(--idc-w));
        gap:6mm 8mm; padding:0; justify-content:center; justify-items:start;
    }
    /* The wrapper holds the true physical footprint; the card is scaled into it.
       Scaling rather than re-laying-out in mm keeps the on-screen design and the
       printed card identical — one design, two renderings — instead of a second
       set of print-only sizes that would drift from the first. */
    .idc-card-wrap { width:var(--idc-w); height:var(--idc-h); overflow:hidden; }
    .idc-card {
        box-shadow:none; border:1px solid #ccc;
        transform:scale(var(--idc-print-scale)); transform-origin:top left;
    }
    .idc-card:hover { transform:scale(var(--idc-print-scale)); }
    .idc-print-one { display:none; }
}
</style>

<div class="content-wrapper">
<div class="idc-wrap">

    <div class="idc-topbar no-print">
        <h1><i class="fa fa-id-card-o"></i> Student ID Cards</h1>
        <div class="idc-actions">
            <?php /* ID-UI-2 (Phase 19H) — selective print. Placed alongside the
                     existing actions rather than replacing them: Filtered and
                     All are the fast paths for a whole class or the whole
                     school, and Selected covers the reprint-these-six case
                     after a batch of replacements. */ ?>
            <button class="idc-btn idc-btn-outline" id="idcSelectAll"><i class="fa fa-check-square-o"></i> Select All</button>
            <button class="idc-btn idc-btn-outline" id="idcClearAll"><i class="fa fa-square-o"></i> Clear</button>
            <button class="idc-btn idc-btn-primary" id="idcPrintSelected" disabled>
                <i class="fa fa-print"></i> Print Selected (<span id="idcSelCount">0</span>)
            </button>
            <?php /* ID-CFG-1 — replacement charges. Shown only to an operator
                     who can actually change them (Fees:manage); the server
                     gate is authoritative regardless. */ ?>
            <?php if (function_exists('has_permission') && has_permission('Fees', 'manage')): ?>
            <button class="idc-btn idc-btn-outline" id="idcFeeSettingsBtn">
                <i class="fa fa-inr"></i> Replacement Charges
            </button>
            <?php endif; ?>
            <?php /* IDC-TOOLBAR-1 (2026-09-19) — "Print Filtered" REMOVED.

                     It printed every card whose wrapper was not display:none — which
                     is exactly what Select All does, because Select All deliberately
                     selects only what is VISIBLE. So "Select All -> Print Selected"
                     and "Print Filtered" produced byte-identical output, and the
                     toolbar offered two routes to one result plus a third that looked
                     like a third result.

                     "Print All" stays because it is genuinely different — it ignores
                     the filters — but it now says how many cards that is, and asks
                     before printing a set the operator has not looked at. Cards are
                     PVC: printing 400 when you meant 12 costs real money. */ ?>
            <button class="idc-btn idc-btn-primary" id="idcPrintAllBtn">
                <i class="fa fa-print"></i> Print All (<span id="idcAllCount">0</span>)
            </button>
        </div>
    </div>

    <div class="idc-filters no-print">
        <div class="idc-search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" class="idc-search" id="idcSearch" placeholder="Search by name, SR No., Adm No. or Student ID...">
        </div>
        <select class="idc-select" id="filterClass">
            <option value="">All Classes</option>
            <?php foreach ($filterClasses as $c => $_): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="idc-select" id="filterSection">
            <option value="">All Sections</option>
            <?php foreach ($filterSections as $s => $_): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
        <?php /* Phase 19F — card-status filter. Values match
                 Id_card_service::validStatuses() plus a synthetic 'none' for
                 students who have never been issued a managed card. */ ?>
        <select class="idc-select" id="filterCardStatus">
            <option value="">All Card Statuses</option>
            <option value="active">Active card</option>
            <option value="replaced">Replaced</option>
            <option value="revoked">Revoked</option>
            <option value="none">No card issued</option>
        </select>
        <span class="idc-counter">Showing <b id="idcCount"><?= count($students) ?></b> of <?= count($students) ?></span>
    </div>

    <?php if (empty($students)): ?>
    <div class="idc-empty">
        <i class="fa fa-id-card-o"></i>
        No enrolled students found for <?= htmlspecialchars($session_year) ?>.
    </div>
    <?php else: ?>
    <div class="idc-grid" id="idcGrid">
        <?php foreach ($students as $s):
            $uid     = $s['User Id'] ?? '';
            $name    = $s['Name'] ?? '';
            // Same normalisation as the filter loop above — keeps
            // data-attrs in sync with dropdown values + the per-card
            // display label.
            $cls     = idc_with_prefix($s['Class']   ?? '', 'Class');
            $sec     = idc_with_prefix($s['Section'] ?? '', 'Section');
            $photo   = idc_photo($s);
            $initial = mb_strtoupper(mb_substr($name, 0, 1));
            $safeUid = preg_replace('/[^a-z0-9]/i', '_', $uid);
            $dob     = idc_dob($s['DOB'] ?? $s['Date of Birth'] ?? '');
            $blood   = $s['Blood Group'] ?? $s['BloodGroup'] ?? '';
            $father  = $s['Father Name'] ?? '';
            $phone   = $s['Phone Number'] ?? $s['Phone'] ?? '';
            $addrRaw = $s['Address'] ?? $s['address'] ?? '';
            $addr    = is_array($addrRaw) ? implode(', ', array_filter($addrRaw)) : (string)$addrRaw;
            // Phase 11 — SRNO is the school's OWN register number, which is what
            // an office or a gate check actually reconciles a physical card
            // against; Student ID alone is a system identifier. Roll No is the
            // classroom-scoped number and belongs with Class/Section, not with
            // identity. Both come from the student document already loaded for
            // this card — zero extra reads. Both render only when present, so
            // cards for students without them are unchanged.
            //
            // Deliberately NOT on the card: Admission Number (an admission-office
            // / certificate artefact with no card-side use; it also becomes
            // stale-looking after re-admission, when the card is not reissued),
            // APAAR (PII), previousStudentId (historical linkage).
            $srNo    = trim((string) ($s['srNo'] ?? $s['srNumber'] ?? $s['SR Number'] ?? ''));
            $rollNo  = trim((string) ($s['Roll No'] ?? $s['rollNo'] ?? ''));
            // ── SRNO-5 (Phase 19F) — ADMISSION NUMBER IS NOW PRINTED ──────
            // This reverses the deliberate exclusion documented immediately
            // above, on explicit instruction, and one half of that original
            // rationale no longer holds: it argued the number "becomes
            // stale-looking after re-admission, when the card is not
            // reissued". Since Phase 19E, re-admission mints a NEW studentId
            // and therefore a new card, so a re-admitted student is never
            // carrying a card with their old admission number. The remaining
            // argument (that it is an admission-office artefact) is a
            // presentation preference, and the school-facing convention is to
            // show it. SR No. remains the PRIMARY identity — see below.
            $admNo   = trim((string) ($s['admissionNo'] ?? $s['admission_no'] ?? ''));
        ?>
        <div class="idc-card-wrap"
             data-class="<?= htmlspecialchars($cls) ?>"
             data-section="<?= htmlspecialchars($sec) ?>"
             data-name="<?= htmlspecialchars(strtolower($name)) ?>"
             data-uid="<?= htmlspecialchars(strtolower($uid)) ?>"
             <?php /* SRNO-4 (Phase 19F) — search previously matched only name
                      and Student ID, so an operator who knows a child by SR No.
                      (what the school actually uses) had to look the internal
                      ID up elsewhere before they could reprint a card. */ ?>
             data-srno="<?= htmlspecialchars(strtolower($srNo)) ?>"
             data-admno="<?= htmlspecialchars(strtolower($admNo)) ?>"
             <?php /* Display-cased copies for the confirmation modal. The
                      lowercase ones above are for matching only — showing a
                      lowercased name/SR No. back to the operator would look
                      broken. */ ?>
             data-display-name="<?= htmlspecialchars($name) ?>"
             data-srno-disp="<?= htmlspecialchars($srNo) ?>"
             data-admno-disp="<?= htmlspecialchars($admNo) ?>">

            <div class="idc-card" id="card-<?= $safeUid ?>">

                <!-- Header: logo left, school name + address right -->
                <div class="idc-hdr">
                    <img class="idc-hdr-logo" src="<?= htmlspecialchars($schoolLogo) ?>"
                         onerror="this.src='<?= $fallback ?>'" alt="">
                    <div class="idc-hdr-info">
                        <div class="idc-hdr-name"><?= htmlspecialchars($schoolN) ?></div>
                        <?php if ($schoolAddr): ?>
                        <div class="idc-hdr-addr"><?= htmlspecialchars(mb_strimwidth($schoolAddr, 0, 55, '...')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Photo — below header -->
                <div class="idc-photo-area">
                    <?php if ($photo): ?>
                    <img class="idc-photo" src="<?= htmlspecialchars($photo) ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" alt="">
                    <div class="idc-photo-fb" style="display:none"><?= $initial ?></div>
                    <?php else: ?>
                    <div class="idc-photo-fb"><?= $initial ?></div>
                    <?php endif; ?>
                </div>

                <!-- Name + QR (replaces CODE128 barcode, 2026 redesign) -->
                <div class="idc-body">
                    <div class="idc-name"><?= htmlspecialchars($name) ?></div>

                    <?php
                    // QR is shown only when we have BOTH a studentId and the
                    // controller passed the schoolId. Without the schoolId
                    // the token is meaningless to the attendance scanner,
                    // so we'd rather print no QR than print a broken one.
                    //
                    // Inline SVG via `qr_svg_data_uri()` — entire QR is
                    // embedded as `data:image/svg+xml;base64,…` so:
                    //   - zero network calls (no ad-blocker / firewall risk)
                    //   - vector → sharp at any print size
                    //   - works offline / air-gapped
                    if ($uid && $qrSchoolId !== ''):
                        // ID-MINT-1 — print the credential the card record
                        // actually names. A managed card mints V2 bound to its
                        // version (so replacing it can retire it); a student
                        // with no managed card keeps V1, which names no version
                        // and is still accepted everywhere. Without this the
                        // register and the printed artefact silently disagree
                        // and migration can never complete.
                        $__cardVer = (int) ($card_versions[$uid] ?? 0);
                        $qrToken   = $__cardVer > 0
                            ? qr_token_encode_v2($qrSchoolId, $uid, $__cardVer)
                            : qr_token_encode($qrSchoolId, $uid);
                        $qrDataUri = qr_svg_data_uri($qrToken);
                    ?>
                    <div class="idc-qr-wrap">
                        <?php if ($qrDataUri !== ''): ?>
                        <img src="<?= htmlspecialchars($qrDataUri) ?>"
                             alt="QR <?= htmlspecialchars($uid) ?>"
                             title="Scan for attendance">
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="idc-class"><?= htmlspecialchars($cls) ?> &middot; <?= htmlspecialchars($sec) ?><?php
                        // Roll No is free text and may be alphanumeric ("A12") —
                        // printed verbatim, never parsed as a number.
                        if ($rollNo !== '') echo ' &middot; Roll ' . htmlspecialchars($rollNo);
                    ?></div>
                </div>

                <!-- Info rows -->
                <div class="idc-info">
                    <?php
                    /* ── SRNO-5 (Phase 19F) — IDENTITY HIERARCHY CORRECTED ──
                     * Previously Student ID was rendered first and styled as
                     * the card's primary identity (accent colour, bold) while
                     * SR No. sat below it as plain text — the hierarchy was
                     * inverted relative to ZenX's own identity model:
                     *
                     *   SR No.       permanent, school-scoped, survives
                     *                promotion / section transfer /
                     *                re-admission → THE human-facing identity
                     *   Admission No. admission/register identity
                     *   Student ID   INTERNAL identifier and foreign key —
                     *                a system detail, not what a school calls
                     *                a child
                     *   Roll No.     session/class-scoped, reassigned on
                     *                promotion → contextual only, printed
                     *                with class/section above, never here
                     *
                     * Student ID is kept on the card (an operator does need it
                     * for support and it is what the QR binds) but is now
                     * visually subordinate.
                     */
                    ?>
                    <?php if ($srNo !== ''): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-list-ol"></i></span>
                        <span class="idc-info-lbl">SR No.</span>
                        <span class="idc-info-val" style="color:var(--idc);font-weight:700"><?= htmlspecialchars($srNo) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($admNo !== ''): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-hashtag"></i></span>
                        <span class="idc-info-lbl">Adm No.</span>
                        <span class="idc-info-val"><?= htmlspecialchars($admNo) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php
                    /* ── IDC-SRNO-1 (2026-09-19) — SR No. REPLACES Student ID ──────────
                     *
                     * SRNO-5 above already demoted Student ID to a subordinate row but
                     * kept printing it. On a card it should not be there at all:
                     *
                     *   - It is an INTERNAL foreign key. A school does not call a child
                     *     STU0014, and a parent, a gate guard and a receiving school have
                     *     no use for it.
                     *   - The argument for keeping it was operator support — but the QR
                     *     on this very card encodes the studentId, so scanning resolves
                     *     it instantly. Printing it as well spends a row on a fixed-height
                     *     card to duplicate what the QR already carries.
                     *   - Two ID-looking numbers on one card invites the wrong one being
                     *     quoted on a form.
                     *
                     * FALLBACK, deliberately: a student with no SR No. would otherwise
                     * carry NO human-readable identifier. Those students exist — SR
                     * numbers became mandatory only at Phase 9.1, so most of the existing
                     * roster predates it. They get the Student ID, labelled "ID" rather
                     * than "SR No.", so nobody transcribes an internal key into a
                     * register believing it is the school's own number.
                     *
                     * The SR No. row itself is rendered above, in accent colour.
                     */
                    ?>
                    <?php if ($srNo === '' && $uid): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-id-badge"></i></span>
                        <span class="idc-info-lbl">ID</span>
                        <span class="idc-info-val" style="opacity:.7"><?= htmlspecialchars($uid) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($father): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-user-o"></i></span>
                        <span class="idc-info-lbl">Father</span>
                        <span class="idc-info-val"><?= htmlspecialchars($father) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($dob): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-calendar"></i></span>
                        <span class="idc-info-lbl">DOB</span>
                        <span class="idc-info-val"><?= htmlspecialchars($dob) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($blood): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-tint"></i></span>
                        <span class="idc-info-lbl">Blood</span>
                        <span class="idc-info-val idc-blood"><?= htmlspecialchars($blood) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($phone): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-phone"></i></span>
                        <span class="idc-info-lbl">Phone</span>
                        <span class="idc-info-val idc-phone"><?= htmlspecialchars($phone) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($addr): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-map-marker"></i></span>
                        <span class="idc-info-lbl">Address</span>
                        <span class="idc-info-val"><?= htmlspecialchars(mb_strimwidth($addr, 0, 40, '...')) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Footer -->
                <div class="idc-ftr">
                    <span class="idc-ftr-label">Student ID Card</span>
                    <span class="idc-ftr-valid">Valid: <?= htmlspecialchars($session_year) ?></span>
                </div>

            </div>

            <?php /* ── Phase 19F — per-card operational controls ──────────
                     Rendered EMPTY and populated by idcApplyRegister() once
                     the register has actually loaded. Deliberate: showing an
                     "Issue"/"Revoke" button before we know the card's real
                     state would let an operator act on a guess, and a control
                     that cannot yet succeed is a fake control. If the register
                     request fails, the strip says so instead of offering
                     actions. */ ?>
            <?php /* ID-UI-2 — selection checkbox. Uses the SAME uid the print
                     and lifecycle paths use, so a selected card can never
                     print a different student's data. */ ?>
            <label class="idc-sel no-print" style="display:block;text-align:center;margin-top:6px;font-size:12px;cursor:pointer">
                <input type="checkbox" class="idc-sel-box" value="<?= htmlspecialchars($uid) ?>"> Select
            </label>

            <div class="idc-ops no-print" data-uid="<?= htmlspecialchars($uid) ?>"></div>

            <button class="idc-print-one no-print" onclick="idcPrintOne('<?= $safeUid ?>')">
                <i class="fa fa-print"></i> Print
            </button>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php /* ══════════════════════════════════════════════════════════════════
        ID-UI-1 (Phase 19H) — professional modals replacing prompt()/alert()
        ──────────────────────────────────────────────────────────────────
        Bootstrap 3 / AdminLTE MARKUP so the dialogs inherit the panel's own
        styling, but shown/hidden with VANILLA JS. That is deliberate, not a
        workaround: jQuery is loaded in include/footer.php, which is parsed
        AFTER this view, and every other sis/* view is vanilla-only. A
        $('#modal').modal('show') here would throw at parse time. Toggling the
        same classes Bootstrap's own plugin toggles keeps the look native with
        no load-order dependency.
     ══════════════════════════════════════════════════════════════════ */ ?>

<div class="modal fade" id="idcReasonModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-idc-dismiss="1" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="idcReasonTitle">Replace ID Card</h4>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger" id="idcReasonError" style="display:none"></div>

        <dl class="dl-horizontal" style="margin-bottom:14px">
          <dt>Student</dt><dd id="idcRmName">—</dd>
          <dt>SR No.</dt><dd id="idcRmSr">—</dd>
          <dt>Adm No.</dt><dd id="idcRmAdm">—</dd>
          <dt>Student ID</dt><dd id="idcRmUid" style="opacity:.7">—</dd>
          <dt>Current card</dt><dd id="idcRmCard">—</dd>
        </dl>

        <div class="form-group">
          <label for="idcReasonSelect">Reason <span class="text-danger">*</span></label>
          <?php /* CONTROLLED selection. The canonical reason must never come
                   from typed text — it drives the audit trail, reporting, and
                   (in 19I) whether a replacement is chargeable. */ ?>
          <select class="form-control" id="idcReasonSelect">
            <option value="">— Select a reason —</option>
            <option value="lost">Lost</option>
            <option value="stolen">Stolen</option>
            <option value="damaged">Damaged</option>
            <option value="wear">Wear and tear</option>
            <option value="data_correction">Data correction</option>
            <option value="admin">Administrative</option>
          </select>
        </div>

        <div class="form-group">
          <label for="idcReasonNote">Note <small class="text-muted">(optional — e.g. police report reference)</small></label>
          <input type="text" class="form-control" id="idcReasonNote" maxlength="300"
                 placeholder="Recorded alongside the reason, never instead of it">
        </div>

        <div class="callout callout-info" id="idcReasonOutcome" style="margin-bottom:12px"></div>

        <?php /* Replacement-charge preview (Part 8). The operator must know
                 whether this action bills the family BEFORE confirming it —
                 discovering a charge only from a later receipt is exactly the
                 kind of surprise that erodes trust in the panel. */ ?>
        <div id="idcReasonFee" class="callout callout-warning" style="display:none;margin-bottom:0"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-idc-dismiss="1">Cancel</button>
        <button type="button" class="btn btn-primary" id="idcReasonConfirm">Confirm</button>
      </div>
    </div>
  </div>
</div>

<?php /* ID-CFG-1 — per-school replacement charges. Before this the amounts
        were a DEAD READ: Id_card_fee_policy looked for them in feeSettings,
        nothing ever wrote them, and no screen existed — so a school could
        never actually set a price and every replacement was silently free. */ ?>
<?php /* IDC-TOOLBAR-1 — Print All confirmation. Same Bootstrap-3 markup /
         vanilla-control convention as the two dialogs around it. */ ?>
<div class="modal fade" id="idcPrintAllModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" onclick="idcHidePrintAll()" aria-label="Close">
          <span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">Print every card?</h4>
      </div>
      <div class="modal-body" id="idcPrintAllBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" onclick="idcHidePrintAll()">Cancel</button>
        <button type="button" class="btn btn-primary" id="idcPrintAllGo">Print all of them</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="idcFeeModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-idc-dismiss="1" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">ID Card Replacement Charges</h4>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger" id="idcFeeError" style="display:none"></div>
        <div id="idcFeeLoading"><i class="fa fa-spinner fa-spin"></i> Loading…</div>

        <div id="idcFeeForm" style="display:none">
          <?php /* IDC-FREE-1 — filled by the loader from the endpoint's own
                   `configured` flag plus the amounts, so the dialog states the
                   CONSEQUENCE ("replacements are currently free") rather than
                   leaving the operator to infer it from three zeros. */ ?>
          <div id="idcFeeState" style="display:none"></div>
          <p class="text-muted" style="margin-bottom:12px">
            Amounts apply to <b><?= htmlspecialchars($session_year) ?></b>.
            Leave a charge at <b>0</b> to keep that reason free — no fee demand is raised at all.
          </p>

          <div class="form-group">
            <label for="idcFeeLost">Lost card</label>
            <div class="input-group">
              <span class="input-group-addon">&#8377;</span>
              <input type="number" min="0" max="100000" step="1" class="form-control" id="idcFeeLost" value="0">
            </div>
          </div>
          <div class="form-group">
            <label for="idcFeeStolen">Stolen card</label>
            <div class="input-group">
              <span class="input-group-addon">&#8377;</span>
              <input type="number" min="0" max="100000" step="1" class="form-control" id="idcFeeStolen" value="0">
            </div>
          </div>
          <div class="form-group">
            <label for="idcFeeDamaged">Damaged card</label>
            <div class="input-group">
              <span class="input-group-addon">&#8377;</span>
              <input type="number" min="0" max="100000" step="1" class="form-control" id="idcFeeDamaged" value="0">
            </div>
          </div>

          <?php /* Stated rather than silently omitted, so the absence of these
                   rows reads as a deliberate policy and not an oversight. */ ?>
          <div class="callout callout-info" style="margin-bottom:0">
            <b>Never chargeable.</b> First issue is part of admission. Normal
            <b>wear</b>, a <b>data correction</b> and an <b>administrative</b>
            reissue are not the family's responsibility, so no amount can be set
            for them.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-idc-dismiss="1">Cancel</button>
        <button type="button" class="btn btn-primary" id="idcFeeSave" disabled>Save charges</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="idcHistoryModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-idc-dismiss="1" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">Card History — <span id="idcHistWho"></span></h4>
      </div>
      <div class="modal-body" style="max-height:60vh;overflow:auto">
        <div id="idcHistLoading"><i class="fa fa-spinner fa-spin"></i> Loading history…</div>
        <div class="alert alert-danger" id="idcHistError" style="display:none"></div>
        <?php /* The empty state is rendered ONLY after the server confirms an
                 empty result — never as a default, which would show "no
                 history" for a failed request. */ ?>
        <div id="idcHistEmpty" style="display:none">This student has no card history yet.</div>
        <div class="table-responsive" id="idcHistWrap" style="display:none">
          <table class="table table-bordered table-striped" style="font-size:13px">
            <thead><tr>
              <th>Ver</th><th>Event</th><th>Status</th><th>Reason</th>
              <th>Issued</th><th>Issued by</th><th>Replaced</th><th>Revoked</th>
            </tr></thead>
            <tbody id="idcHistBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" id="idcHistRetry" style="display:none">Retry</button>
        <button type="button" class="btn btn-default" data-idc-dismiss="1">Close</button>
      </div>
    </div>
  </div>
</div>

</div>
</div>

<script>
(function(){
    'use strict';
    var grid    = document.getElementById('idcGrid');
    var search  = document.getElementById('idcSearch');
    var fClass  = document.getElementById('filterClass');
    var fSec    = document.getElementById('filterSection');
    var counter = document.getElementById('idcCount');

    var fCardStatus = document.getElementById('filterCardStatus');

    function applyFilters() {
        if (!grid) return;
        var q   = (search.value || '').toLowerCase().trim();
        var cls = fClass.value;
        var sec = fSec.value;
        var cst = fCardStatus ? fCardStatus.value : '';
        var n   = 0;
        Array.prototype.forEach.call(grid.querySelectorAll('.idc-card-wrap'), function(w) {
            // SRNO-4 — match name, Student ID, SR No. and Admission No.
            // SR No. and Adm No. are matched with startsWith as well as
            // substring: "12" should surface SR 12 and SR 120 near the top
            // rather than only every number that happens to contain "12".
            // Both attributes are always present (possibly empty), so no
            // undefined access — an empty one simply never matches.
            var sr = w.dataset.srno || '', adm = w.dataset.admno || '';
            var ok = (!q
                      || w.dataset.name.indexOf(q) > -1
                      || w.dataset.uid.indexOf(q) > -1
                      || (sr !== '' && sr.indexOf(q) > -1)
                      || (adm !== '' && adm.indexOf(q) > -1))
                  && (!cls || w.dataset.class === cls)
                  && (!sec || w.dataset.section === sec)
                  // Card-status filter. `cardStatus` is set by
                  // idcApplyRegister(); until the register loads it is
                  // undefined, so this filter simply matches nothing rather
                  // than silently showing a wrong subset.
                  && (!cst || (w.dataset.cardStatus || 'none') === cst);
            w.style.display = ok ? '' : 'none';
            if (ok) n++;
        });
        counter.textContent = n;
        // Keep the Print Selected button honest as the visible set changes.
        // Called directly rather than by wrapping applyFilters afterwards:
        // the listeners below capture THIS function object, so a later
        // reassignment would never reach them.
        if (typeof refreshSelection === 'function') refreshSelection();
    }
    if (search) search.addEventListener('input', applyFilters);
    if (fCardStatus) fCardStatus.addEventListener('change', applyFilters);
    if (fClass) fClass.addEventListener('change', applyFilters);
    if (fSec)   fSec.addEventListener('change', applyFilters);

    /* ── QR codes ── No client-side init needed; QR PNGs are rendered
       server-side via qrserver.com and embedded as <img>. The legacy
       JsBarcode CDN was removed in the 2026 QR redesign. */

    /* ── Card CSS for print ── */
    /**
     * IDC-CSS-1 — the SAME stylesheet the page uses, not a copy of it.
     *
     * This used to be a hand-maintained JS-string paraphrase of the card CSS,
     * kept because the print window is a blank document that inherits nothing.
     * Two definitions of one card is a drift hazard, and it had drifted: the
     * copy still styled `.idc-sid*`, elements the card stopped rendering when
     * SR No. became the printed identity, while missing the CR80 geometry and
     * the print-color-adjust that keep the printed artifact faithful.
     *
     * json_encode() so the CSS crosses into JS exactly as written — quotes,
     * newlines and all — rather than being re-escaped by hand.
     */
    function cardCSS() {
        return "*{margin:0;padding:0;box-sizing:border-box}\n"
             + "body{font-family:sans-serif}\n"
             + <?= json_encode($IDC_CARD_CSS, JSON_UNESCAPED_SLASHES) ?>;
    }

    window.idcPrintOne = function(safeUid) {
        var el = document.getElementById('card-' + safeUid);
        if (!el) return;
        openPrint([el.outerHTML]);
    };

    /* IDC-TOOLBAR-1 — idcPrintFiltered() removed with its button. It was the
       same set Select All produces, so keeping the function "just in case"
       would leave a second implementation of the visible-set rule to drift
       from the one the selection model uses. There is one now. */

    /* Every card on the page, FILTERS IGNORED. That is the whole reason it still
       exists — and the whole reason it has to announce itself. */
    function allCards() {
        if (!grid) return [];
        return Array.prototype.slice.call(grid.querySelectorAll('.idc-card'));
    }
    function visibleCardCount() {
        if (!grid) return 0;
        return Array.prototype.slice.call(grid.querySelectorAll('.idc-card-wrap'))
            .filter(function (w) { return w.style.display !== 'none'; }).length;
    }

    /* Keeps the button label truthful as the roster loads. The count is the
       TOTAL, not the filtered count, because that is what the button does. */
    window.idcRefreshAllCount = function () {
        var el = document.getElementById('idcAllCount');
        if (el) el.textContent = allCards().length;
    };

    window.idcPrintAll = function() {
        var cards = allCards().map(function (c) { return c.outerHTML; });
        if (!cards.length) { flash('No cards to print.', 'warning'); return; }

        // If a filter is hiding anything, the operator is about to print a set
        // they have not reviewed. Ask — naming BOTH numbers, so the choice is
        // informed rather than a yes/no on an unstated quantity.
        var visible = visibleCardCount();
        if (visible !== cards.length) {
            idcConfirmPrintAll(visible, cards.length, function () { openPrint(cards); });
            return;
        }
        openPrint(cards);
    };

    /* Bootstrap-3 markup, vanilla control — the convention this view already
       uses (see the modal note above). NOT window.confirm(): it cannot show the
       two counts side by side, and the panel does not use it anywhere. */
    function idcConfirmPrintAll(visible, total, onYes) {
        var body = document.getElementById('idcPrintAllBody');
        if (!body) { onYes(); return; }
        body.innerHTML =
            '<p style="margin:0 0 10px">Your filters are showing <b>' + visible +
            '</b> card' + (visible === 1 ? '' : 's') + ', but <b>Print All</b> prints ' +
            'every card on this page — <b>' + total + '</b> in total.</p>' +
            '<p style="margin:0;opacity:.8">To print only what you can see, close this ' +
            'and use <b>Select All</b> then <b>Print Selected</b>.</p>';

        var go = document.getElementById('idcPrintAllGo');
        // Replace the node to drop any handler from a previous open, rather than
        // stacking listeners and printing twice on the second use.
        var fresh = go.cloneNode(true);
        go.parentNode.replaceChild(fresh, go);
        fresh.addEventListener('click', function () {
            modalHide('idcPrintAllModal');
            onYes();
        });
        modalShow('idcPrintAllModal');
    }

    function openPrint(cards) {
        var w = window.open('', '_blank');
        w.document.write(
            '<!DOCTYPE html><html><head><title>Student ID Cards</title>' +
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">' +
            '<style>' + cardCSS() +
            /* IDC-PRINT-1 — three 260px columns is 238mm of content on a 210mm
               page, so the third card ran off every sheet. Cells are the true
               CR80 footprint now and the card is scaled into them, which is the
               same approach the on-page print rules take. */
            /* :root geometry now arrives with cardCSS() — see _id_card_css.php.
               A second declaration here is exactly how the two used to diverge. */
            '.pg{display:grid;grid-template-columns:repeat(3,var(--idc-w));gap:6mm 8mm;'
              + 'padding:10mm;justify-content:center;justify-items:start}' +
            '.pc{width:var(--idc-w);height:var(--idc-h);overflow:hidden}' +
            '.pc > .idc-card{transform:scale(var(--idc-print-scale));transform-origin:top left}' +
            /* IDC-PRINT-2 (2026-09-20) — the print window is a BLANK document and
               does NOT inherit the app theme's :root, so the card's COLOUR tokens
               (--idc header/footer band, --bg2 card background, text/border) were
               undefined and the card printed all-white. Paper is always light, so
               inject the light-theme values here (the geometry vars --idc-w/h/scale
               already arrive with cardCSS()). This is the "white background in the
               print preview" fix. */
            ':root{--idc:#BC5A3C;--idc-lt:rgba(188,90,60,.1);--bg2:#ffffff;--bg3:#f7ece7;' +
              '--t1:#1f2937;--t2:#4b5563;--t3:#9ca3af;--border:#e5e7eb;--font-b:Arial,sans-serif}' +
            /* Colours must actually print (background bands), not just show on screen. */
            'html,body,.idc-card{-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
            '@page{size:A4 portrait;margin:10mm}' +
            /* height:auto stops a stray trailing blank page; the single card stays
               on page 1 instead of paginating. */
            '@media print{html,body{margin:0;height:auto}.pg{padding:0}}' +
            '</style></head><body><div class="pg">'
            + cards.map(function (c) { return '<div class="pc">' + c + '</div>'; }).join('')
            + '</div></body></html>'
        );
        w.document.close();
        w.focus();
        setTimeout(function(){ w.print(); }, 500);
    }

    /* ══════════════════════════════════════════════════════════════════
       PHASE 19F — ID CARD OPERATIONAL LIFECYCLE
       ──────────────────────────────────────────────────────────────────
       Every control below is wired to a real endpoint. Nothing renders a
       button that cannot succeed, and no operation reports success it did
       not verify:

       • CSRF — sis/* is NOT in csrf_exclude_uris, so the token from the
         header meta tags is posted with every request (same pattern as
         sis/promote.php). csrf_regenerate is FALSE, but json_success()
         returns a fresh hash anyway and we adopt it, so this keeps working
         if that config is ever flipped.
       • FAIL CLOSED — fetch() does NOT reject on 403/500, so every reply is
         checked for r.ok AND payload.status === 'success' before anything
         is treated as done. This is the "phantom success" trap: without
         both checks a denied action renders as completed.
       • DUPLICATE CLICKS — the strip is locked for the duration of a
         request. Issue is not naturally idempotent (a second call would
         claim another card version), so this is a correctness guard, not
         cosmetics.
    ══════════════════════════════════════════════════════════════════ */

    var csrfName  = document.querySelector('meta[name="csrf-name"]');
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfName  = csrfName  ? csrfName.content  : '';
    csrfToken = csrfToken ? csrfToken.content : '';

    var IDC_CAN_MANAGE = <?= (function_exists('has_permission') && has_permission('SIS', 'manage')) ? 'true' : 'false' ?>;
    // Mirrors Id_card_service::validReasons() minus first_issue, which is
    // never operator-selectable — the service derives it.
    var VALID_REASONS = ['lost', 'stolen', 'damaged', 'wear', 'data_correction', 'admin'];

    /* IDC-TOOLBAR-1 — Print All is now a wired button rather than an inline
       onclick, because it has a confirmation path and a live count. */
    window.idcHidePrintAll = function () { modalHide('idcPrintAllModal'); };

    document.addEventListener('DOMContentLoaded', function () {
        var b = document.getElementById('idcPrintAllBtn');
        if (b) b.addEventListener('click', function () { window.idcPrintAll(); });
        if (window.idcRefreshAllCount) window.idcRefreshAllCount();
    });

    /* ── Vanilla Bootstrap-3 modal control (see markup note above) ─────── */
    function modalShow(id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.style.display = 'block';
        m.classList.add('in');
        m.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        if (!document.getElementById('idcBackdrop')) {
            var b = document.createElement('div');
            b.id = 'idcBackdrop';
            b.className = 'modal-backdrop fade in';
            document.body.appendChild(b);
        }
    }
    function modalHide(id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.style.display = 'none';
        m.classList.remove('in');
        m.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        var b = document.getElementById('idcBackdrop');
        if (b) b.parentNode.removeChild(b);
    }
    document.addEventListener('click', function (e) {
        var t = e.target;
        while (t && t !== document) {
            if (t.getAttribute && t.getAttribute('data-idc-dismiss')) {
                var host = t.closest('.modal');
                if (host) modalHide(host.id);
                return;
            }
            t = t.parentNode;
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { modalHide('idcReasonModal'); modalHide('idcHistoryModal'); }
    });

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function shortDate(v) { return v ? String(v).substring(0, 10) : '—'; }

    function idcPost(url, payload) {
        var fd = new FormData();
        Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
        if (csrfName) fd.append(csrfName, csrfToken);
        return fetch('<?= base_url() ?>' + url, {
            method: 'POST', body: fd, credentials: 'same-origin'
        }).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (j) {
                // Adopt any refreshed token before deciding success/failure,
                // so a rejected call doesn't also break the NEXT one.
                if (j && j.csrf_token) csrfToken = j.csrf_token;
                if (!r.ok || !j || j.status !== 'success') {
                    throw new Error((j && j.message) ? j.message : ('Request failed (' + r.status + ')'));
                }
                return j;
            });
        });
    }

    var REGISTRY = {};   // studentId -> card pointer row

    function badge(status) {
        var map = {
            active:   ['#0a7d32', 'Active'],
            replaced: ['#8a6d1f', 'Replaced'],
            revoked:  ['#a11', 'Revoked'],
            expired:  ['#666', 'Expired']
        };
        var m = map[status] || ['#777', 'No card'];
        return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;'
             + 'font-weight:700;color:#fff;background:' + m[0] + '">' + m[1] + '</span>';
    }

    function renderOps(strip, uid) {
        var card = REGISTRY[uid];
        var st   = card ? (card.status || '') : '';
        var ver  = card ? (card.currentVersion || 0) : 0;

        var html = '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;'
                 + 'justify-content:center;margin-top:8px;font-size:12px">';
        html += badge(st);
        if (ver) html += '<span style="opacity:.7">v' + ver + '</span>';

        if (IDC_CAN_MANAGE) {
            if (st === 'active') {
                html += '<button type="button" data-act="replace">Replace</button>'
                      + '<button type="button" data-act="revoke">Revoke</button>';
            } else {
                // Covers "never issued", "revoked" and "replaced" — in every
                // one of those the student holds no live card, so the correct
                // single action is to issue one.
                html += '<button type="button" data-act="issue">'
                      + (ver ? 'Issue new card' : 'Issue first card') + '</button>';
            }
        }
        // History is a read — available to anyone who can view this page.
        if (ver) html += '<button type="button" data-act="history">History</button>';
        html += '</div>';
        strip.innerHTML = html;

        Array.prototype.forEach.call(strip.querySelectorAll('button'), function (b) {
            b.addEventListener('click', function () { idcAction(strip, uid, b.dataset.act); });
        });

        // Drive the card-status filter from real data.
        var wrap = strip.closest ? strip.closest('.idc-card-wrap') : null;
        if (wrap) wrap.dataset.cardStatus = st || 'none';
    }

    function lock(strip, msg) {
        strip.dataset.busy = '1';
        strip.innerHTML = '<div style="margin-top:8px;font-size:12px;opacity:.7">' + msg + '</div>';
    }

    /* ── History modal (ID-UI-1) ───────────────────────────────────────── */

    var HIST_UID = '';

    function openHistory(uid) {
        HIST_UID = uid;
        var wrap = document.querySelector('.idc-card-wrap[data-uid="' + uid.toLowerCase() + '"]');
        document.getElementById('idcHistWho').textContent =
            (wrap ? wrap.dataset.displayName : '') || uid;
        modalShow('idcHistoryModal');
        loadHistory();
    }

    function loadHistory() {
        var L = document.getElementById('idcHistLoading'),
            E = document.getElementById('idcHistError'),
            M = document.getElementById('idcHistEmpty'),
            W = document.getElementById('idcHistWrap'),
            R = document.getElementById('idcHistRetry'),
            B = document.getElementById('idcHistBody');

        L.style.display = ''; E.style.display = 'none';
        M.style.display = 'none'; W.style.display = 'none'; R.style.display = 'none';
        B.innerHTML = '';

        idcPost('sis/id_card_history', { student_id: HIST_UID }).then(function (j) {
            L.style.display = 'none';
            var rows = j.history;
            // A non-array reply is MALFORMED, not "no history" — surfacing it
            // as empty would hide a broken endpoint behind a clean UI.
            if (!Array.isArray(rows)) throw new Error('Malformed response from the server.');
            if (!rows.length) { M.style.display = ''; return; }
            B.innerHTML = rows.map(function (h) {
                return '<tr>'
                    + '<td>v' + esc(h.version) + '</td>'
                    + '<td>' + esc(h.event || '—') + '</td>'
                    + '<td>' + esc(h.status || '—') + '</td>'
                    + '<td>' + esc(h.reason || '—')
                    + (h.reasonNote ? '<br><small class="text-muted">' + esc(h.reasonNote) + '</small>' : '')
                    + '</td>'
                    + '<td>' + esc(shortDate(h.issuedAt)) + '</td>'
                    + '<td>' + esc(h.issuedBy || '—') + '</td>'
                    + '<td>' + esc(shortDate(h.replacedAt))
                    + (h.replacedBy ? '<br><small>' + esc(h.replacedBy) + '</small>' : '') + '</td>'
                    + '<td>' + esc(shortDate(h.revokedAt))
                    + (h.revokedBy ? '<br><small>' + esc(h.revokedBy) + '</small>' : '') + '</td>'
                    + '</tr>';
            }).join('');
            W.style.display = '';
        }).catch(function (e) {
            L.style.display = 'none';
            E.textContent = 'Could not load history: ' + e.message;
            E.style.display = '';
            R.style.display = '';       // explicit retry, not a silent dead end
        });
    }
    document.getElementById('idcHistRetry').addEventListener('click', loadHistory);

    /* ── Reason modal (ID-UI-1) ────────────────────────────────────────── */

    var PENDING = null;   // { uid, act, strip }

    function openReasonModal(strip, uid, act) {
        var card = REGISTRY[uid] || {};
        var wrap = document.querySelector('.idc-card-wrap[data-uid="' + uid.toLowerCase() + '"]');
        var isRevoke = (act === 'revoke');

        PENDING = { uid: uid, act: act, strip: strip };

        document.getElementById('idcReasonTitle').textContent =
            isRevoke ? 'Revoke ID Card' : 'Replace ID Card';
        document.getElementById('idcRmName').textContent = (wrap ? wrap.dataset.displayName : '') || '—';
        document.getElementById('idcRmSr').textContent   = (wrap && wrap.dataset.srnoDisp)  || '—';
        document.getElementById('idcRmAdm').textContent  = (wrap && wrap.dataset.admnoDisp) || '—';
        document.getElementById('idcRmUid').textContent  = uid;
        document.getElementById('idcRmCard').textContent =
            card.currentVersion ? ('v' + card.currentVersion + ' · ' + (card.status || '—')) : 'No card issued';

        var outcome = document.getElementById('idcReasonOutcome');
        outcome.className = isRevoke ? 'callout callout-danger' : 'callout callout-info';
        outcome.innerHTML = isRevoke
            ? '<b>This cannot be undone.</b> The current card will be marked <b>revoked</b> and the '
              + 'student will hold no active card. A new card can only be created by issuing one.'
            : 'The current card will be marked <b>replaced</b> and a new card will become <b>active</b>.';

        document.getElementById('idcReasonSelect').value = '';
        document.getElementById('idcReasonNote').value = '';
        var err = document.getElementById('idcReasonError');
        err.style.display = 'none'; err.textContent = '';
        var btn = document.getElementById('idcReasonConfirm');
        btn.disabled = false;
        btn.textContent = isRevoke ? 'Revoke card' : 'Replace card';

        // Charge preview starts hidden (no reason chosen yet) and appears as
        // soon as one is selected. Amounts are fetched lazily and cached.
        document.getElementById('idcReasonFee').style.display = 'none';
        if (FEE_AMOUNTS === null && !isRevoke) {
            loadFeeSettings().then(refreshFeePreview).catch(function () {
                FEE_AMOUNTS = null;   // stays unknown; preview says so honestly
            });
        }

        modalShow('idcReasonModal');
    }

    document.getElementById('idcReasonConfirm').addEventListener('click', function () {
        if (!PENDING) return;
        var btn = this;
        if (btn.disabled) return;                       // double-click guard

        var reason = document.getElementById('idcReasonSelect').value;
        var note   = document.getElementById('idcReasonNote').value;
        var err    = document.getElementById('idcReasonError');

        if (VALID_REASONS.indexOf(reason) === -1) {
            err.textContent = 'Select a reason before continuing.';
            err.style.display = '';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Working…';
        submitLifecycle(PENDING.strip, PENDING.uid, PENDING.act, reason, note)
            .then(function () { modalHide('idcReasonModal'); })
            .catch(function (e) {
                // Keep the modal open on failure so the operator can correct
                // and retry without re-entering everything.
                err.textContent = e.message; err.style.display = '';
                btn.disabled = false;
                btn.textContent = (PENDING && PENDING.act === 'revoke') ? 'Revoke card' : 'Replace card';
            });
    });

    /* ── ID-CFG-1 — replacement charges ────────────────────────────────── */

    var FEE_AMOUNTS = null;
    var FEE_CONFIGURED = false;   // IDC-FREE-1   // {lost, stolen, damaged} — null until loaded

    function feeInputs() {
        return {
            lost:    document.getElementById('idcFeeLost'),
            stolen:  document.getElementById('idcFeeStolen'),
            damaged: document.getElementById('idcFeeDamaged')
        };
    }

    function loadFeeSettings() {
        return idcPost('sis/id_card_fee_settings', {}).then(function (j) {
            FEE_AMOUNTS = j.amounts || {};
            // IDC-FREE-1 — the endpoint has always returned `configured` and
            // `never_chargeable`; this dialog ignored both, so a school that had
            // never set a price saw three zeros and no explanation of what that
            // means in practice.
            FEE_CONFIGURED = !!j.configured;
            return FEE_AMOUNTS;
        });
    }

    var feeBtn = document.getElementById('idcFeeSettingsBtn');
    if (feeBtn) feeBtn.addEventListener('click', function () {
        var L = document.getElementById('idcFeeLoading'),
            F = document.getElementById('idcFeeForm'),
            E = document.getElementById('idcFeeError'),
            S = document.getElementById('idcFeeSave');
        L.style.display = ''; F.style.display = 'none';
        E.style.display = 'none'; S.disabled = true;
        modalShow('idcFeeModal');

        loadFeeSettings().then(function (a) {
            var i = feeInputs();
            i.lost.value    = a.lost    || 0;
            i.stolen.value  = a.stolen  || 0;
            i.damaged.value = a.damaged || 0;

            // IDC-FREE-1 — state the consequence, not just the numbers. Three
            // zeros do not read as "every replacement is free"; this does.
            var total = (+a.lost || 0) + (+a.stolen || 0) + (+a.damaged || 0);
            var banner = document.getElementById('idcFeeState');
            if (banner) {
                if (!FEE_CONFIGURED || total === 0) {
                    banner.className = 'alert alert-warning';
                    banner.innerHTML = '<b>No replacement charge is set.</b> Lost, stolen and '
                        + 'damaged cards are currently reissued free of charge. Enter amounts '
                        + 'below to start billing for them.';
                    banner.style.display = '';
                } else {
                    banner.className = 'alert alert-info';
                    banner.innerHTML = 'These amounts are raised as a fee demand on the '
                        + 'student\'s ledger when a card is replaced. First issue, normal wear, '
                        + 'data corrections and admin reissues are never charged.';
                    banner.style.display = '';
                }
            }
            L.style.display = 'none'; F.style.display = ''; S.disabled = false;
        }).catch(function (e) {
            L.style.display = 'none';
            E.textContent = 'Could not load charges: ' + e.message;
            E.style.display = '';
        });
    });

    var feeSave = document.getElementById('idcFeeSave');
    if (feeSave) feeSave.addEventListener('click', function () {
        if (feeSave.disabled) return;                      // duplicate-click guard
        var i = feeInputs(), E = document.getElementById('idcFeeError');
        var payload = {};
        var bad = null;
        ['lost', 'stolen', 'damaged'].forEach(function (k) {
            var v = i[k].value === '' ? '0' : i[k].value;
            if (isNaN(Number(v)) || Number(v) < 0) bad = k;
            payload[k] = v;
        });
        if (bad) {
            E.textContent = 'Enter a valid amount for "' + bad + '".';
            E.style.display = ''; return;
        }
        E.style.display = 'none';
        feeSave.disabled = true;
        var original = feeSave.textContent;
        feeSave.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

        idcPost('sis/id_card_fee_settings_save', payload).then(function (j) {
            FEE_AMOUNTS = j.amounts || FEE_AMOUNTS;
            modalHide('idcFeeModal');
            flash(j.message || 'Replacement charges saved.', 'success');
        }).catch(function (e) {
            E.textContent = e.message; E.style.display = '';
        }).then(function () {
            feeSave.disabled = false; feeSave.textContent = original;
        });
    });

    /**
     * Tell the operator whether the reason they picked bills the family,
     * BEFORE they confirm. Discovering a charge only from a later receipt is
     * the kind of surprise that erodes trust in the panel.
     */
    function refreshFeePreview() {
        var box = document.getElementById('idcReasonFee');
        if (!box || !PENDING) return;
        var reason = document.getElementById('idcReasonSelect').value;

        // Revoking issues no new card, so it never raises a replacement charge.
        if (PENDING.act === 'revoke' || !reason) { box.style.display = 'none'; return; }

        if (FEE_AMOUNTS === null) {
            // Never guess at money. Say the amount is unknown rather than
            // implying "free".
            box.className = 'callout callout-info';
            box.innerHTML = 'Replacement charge for this school could not be read — the demand, if any, '
                          + 'will still be raised correctly by the server.';
            box.style.display = '';
            return;
        }
        var amt = Number(FEE_AMOUNTS[reason] || 0);
        if (amt > 0) {
            box.className = 'callout callout-warning';
            box.innerHTML = 'This will raise a replacement charge of <b>&#8377;' + amt
                          + '</b> on the student\'s fee account, due in 30 days.';
        } else {
            box.className = 'callout callout-success';
            box.innerHTML = 'No charge — this reason is free under your school\'s policy.';
        }
        box.style.display = '';
    }
    document.getElementById('idcReasonSelect').addEventListener('change', refreshFeePreview);

    /* ── Shared submit path ────────────────────────────────────────────── */

    function submitLifecycle(strip, uid, act, reason, note) {
        var url = (act === 'revoke') ? 'sis/id_card_revoke' : 'sis/id_card_issue';
        var payload = { student_id: uid, reason: reason, note: note || '' };

        // ID-RACE-1 — assert the version we displayed. The client-side busy
        // lock stops the common double-click, but only the server can stop a
        // submit that beat it, a retry, or a second operator. Without this a
        // duplicate submit mints a SECOND card and a SECOND replacement fee
        // for one real event.
        if (act !== 'issue' || (REGISTRY[uid] && REGISTRY[uid].currentVersion)) {
            payload.expected_version = (REGISTRY[uid] && REGISTRY[uid].currentVersion) || 0;
        }

        lock(strip, 'Working…');
        return idcPost(url, payload)
            .then(function (j) {
                // Re-read the register rather than patching local state, so
                // what the operator sees is the server's actual result.
                return refreshRegistry().then(function () {
                    strip.dataset.busy = ''; renderOps(strip, uid); applyFilters();
                    flash(j.message || 'Done.', j.already ? 'warning' : 'success');
                });
            })
            .catch(function (e) {
                strip.dataset.busy = ''; renderOps(strip, uid); applyFilters();
                throw e;
            });
    }

    /* Non-blocking feedback — replaces alert() for success paths. */
    function flash(msg, kind) {
        var host = document.getElementById('idcFlash');
        if (!host) {
            host = document.createElement('div');
            host.id = 'idcFlash';
            host.className = 'no-print';
            host.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:2000;max-width:340px';
            document.body.appendChild(host);
        }
        var el = document.createElement('div');
        el.className = 'alert alert-' + (kind === 'success' ? 'success' : (kind === 'warning' ? 'warning' : 'danger'));
        el.style.cssText = 'box-shadow:0 2px 10px rgba(0,0,0,.2);margin-bottom:8px';
        el.textContent = msg;
        host.appendChild(el);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 6000);
    }

    function idcAction(strip, uid, act) {
        if (strip.dataset.busy === '1') return;          // duplicate-click guard

        if (act === 'history') { openHistory(uid); return; }

        var card = REGISTRY[uid];
        if (act === 'issue' && !(card && card.currentVersion)) {
            // A genuine first issue — the service refuses any other reason
            // here, so there is nothing to ask and no modal to show.
            submitLifecycle(strip, uid, 'issue', 'first_issue', '')
                .catch(function (e) { flash('Failed: ' + e.message, 'danger'); });
            return;
        }
        openReasonModal(strip, uid, act);
    }

    function applyRegistry() {
        Array.prototype.forEach.call(document.querySelectorAll('.idc-ops'), function (strip) {
            if (strip.dataset.busy === '1') return;
            renderOps(strip, strip.dataset.uid);
        });
        applyFilters();
    }

    function refreshRegistry() {
        return idcPost('sis/id_card_register', {}).then(function (j) {
            REGISTRY = j.cards || {};
        });
    }

    /* ══════════════════════════════════════════════════════════════════
       ID-UI-2 — SELECTIVE PRINTING
       ──────────────────────────────────────────────────────────────────
       Operates purely on already-rendered DOM. ZERO additional Firestore
       reads: the card markup is already on the page, so printing a
       selection is a client-side clone of the same nodes Print Filtered
       and Print All already use. Select All deliberately selects only
       what is currently VISIBLE — selecting hidden rows the operator
       cannot see would print a set they never reviewed.
    ══════════════════════════════════════════════════════════════════ */

    var selAll   = document.getElementById('idcSelectAll'),
        clearAll = document.getElementById('idcClearAll'),
        printSel = document.getElementById('idcPrintSelected'),
        selCount = document.getElementById('idcSelCount');

    function boxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.idc-sel-box'));
    }
    function visibleBoxes() {
        return boxes().filter(function (b) {
            var w = b.closest('.idc-card-wrap');
            return w && w.style.display !== 'none';
        });
    }
    function selectedBoxes() {
        return boxes().filter(function (b) { return b.checked; });
    }
    function refreshSelection() {
        var n = selectedBoxes().length;
        if (selCount) selCount.textContent = n;
        if (printSel) printSel.disabled = (n === 0);   // zero-selection guard
    }

    boxes().forEach(function (b) { b.addEventListener('change', refreshSelection); });

    if (selAll) selAll.addEventListener('click', function () {
        visibleBoxes().forEach(function (b) { b.checked = true; });
        refreshSelection();
    });
    if (clearAll) clearAll.addEventListener('click', function () {
        boxes().forEach(function (b) { b.checked = false; });   // clears hidden too
        refreshSelection();
    });

    if (printSel) printSel.addEventListener('click', function () {
        if (printSel.dataset.busy === '1') return;              // duplicate-print guard
        var chosen = selectedBoxes();
        if (!chosen.length) { flash('Select at least one card to print.', 'warning'); return; }

        printSel.dataset.busy = '1';
        var original = printSel.innerHTML;
        printSel.disabled = true;
        printSel.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Preparing…';

        try {
            var cards = chosen.map(function (b) {
                var w = b.closest('.idc-card-wrap');
                var c = w ? w.querySelector('.idc-card') : null;
                return c ? c.outerHTML : '';
            }).filter(Boolean);

            if (!cards.length) throw new Error('Selected cards could not be rendered.');
            openPrint(cards);
        } catch (e) {
            // A blocked pop-up or a render failure must say so, not silently
            // look like a completed print.
            flash('Could not open the print view: ' + e.message, 'danger');
        } finally {
            printSel.dataset.busy = '';
            printSel.innerHTML = original;
            refreshSelection();
        }
    });

    refreshSelection();

    // ONE query for the whole school, joined against the already-rendered
    // rows — never one read per student.
    refreshRegistry().then(applyRegistry).catch(function (e) {
        // Say so rather than leaving empty strips that look like "no card".
        Array.prototype.forEach.call(document.querySelectorAll('.idc-ops'), function (strip) {
            strip.innerHTML = '<div style="margin-top:8px;font-size:12px;color:#a11">'
                            + 'Card status unavailable (' + e.message + ')</div>';
        });
    });
})();
</script>
