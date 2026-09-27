<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
/* ============================================================================
   MASTER TIMETABLE — canonical lifecycle screen.

   Built entirely on the global design system in tools/css/style.css. It shows
   the lifecycle the architecture actually has, in the order it happens:

     what applies today  →  versions  →  candidate  →  check  →  publish

   It never reads or writes the legacy `timetables` collection. Everything here
   goes through /timetable/* , which reaches the canonical version architecture.

   Deliberately ABSENT, and said out loud rather than faked:
     - no combined "score": ranking soft violations needs weights nobody has
       chosen, and a number on this page would be acted on as if it meant
       something.
     - no day-grid editor yet. Generate → check → publish works end to end here;
       hand-editing a single period is still on the legacy Academic screen until
       that grid is ported.
     - no candidate limit and no delete: nothing on this page removes a version.

   It is a PANEL, not a page: it renders inside Academic Planner's Master
   Timetable tab, beside Period Scheduling and Subject Assignment — the two
   things a timetable is built from. Keeping it in a separate sidebar entry
   asked an administrator to choose between two screens for one job.

   It computes its own permissions rather than depending on whoever includes it,
   so it cannot be embedded somewhere that forgets to pass them and silently
   render every control.
============================================================================ */

$tt_can_edit   = function_exists('has_permission') ? has_permission('Academic', 'edit')   : false;
$tt_can_manage = function_exists('has_permission') ? has_permission('Academic', 'manage') : false;
?>

<style>
/* ── Master Timetable panel ───────────────────────────────────────────────
   Everything here is drawn from the host page's own variables (--bg2, --border,
   --gold, --t1/2/3, --font-b/m) so the tab is indistinguishable from its
   siblings. Only what the page has no equivalent for is defined. */

.ac-tt-bar{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.ac-tt-lede{margin:0;flex:1 1 320px;color:var(--t2);font-size:12.5px;line-height:1.6}
.ac-tt-actions{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}

.ac-tt-cardtitle{margin:0;font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.ac-tt-tablewrap{overflow-x:auto}

/* Buttons: the page's .ac-btn already has a .loading spinner, so the panel's
   label/spinner spans only need to stay out of its way. */
.ac-tt-btnlabel{display:inline}

/* Empty state — same proportions as .ac-empty, with room for a title + action */
.ac-tt-emptyicon{font-size:30px;color:var(--t3);opacity:.55;margin-bottom:10px}
.ac-tt-emptytitle{margin:0 0 6px;font-size:14px;font-weight:700;color:var(--t2)}
.ac-tt-emptymsg{margin:0 auto;max-width:460px;font-size:12px;line-height:1.6;color:var(--t3)}

/* Forms */
.ac-tt-row{margin-bottom:14px}
.ac-tt-label{display:block;margin-bottom:5px;font-size:11px;font-weight:700;
    color:var(--t2);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}
.ac-tt-req::after{content:' *';color:#dc2626}
.ac-tt-input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;
    background:var(--bg2);color:var(--t1);font-size:13px;font-family:inherit}
.ac-tt-input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim)}

/* Modal — the page has none of its own, so this is defined here and kept
   deliberately plain: it is a dialog, not a feature. */
.ac-tt-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9400;
    display:none;align-items:center;justify-content:center;padding:20px}
.ac-tt-backdrop.is-open{display:flex}
.ac-tt-modal{background:var(--bg1,#fff);border:1px solid var(--border);border-radius:10px;
    width:100%;max-width:520px;max-height:90vh;overflow:auto;
    box-shadow:0 18px 48px rgba(0,0,0,.22)}
.ac-tt-mhd{display:flex;align-items:center;gap:10px;padding:14px 18px;
    border-bottom:1px solid var(--border)}
.ac-tt-mtitle{margin:0;font-size:15px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.ac-tt-mclose{margin-left:auto;background:none;border:0;cursor:pointer;
    color:var(--t3);font-size:15px;line-height:1;padding:4px}
.ac-tt-mclose:hover{color:var(--t1)}
.ac-tt-mbody{padding:18px}
.ac-tt-mfoot{display:flex;justify-content:flex-end;gap:8px;padding:14px 18px;
    border-top:1px solid var(--border);background:var(--bg3)}

/* ── The week grid ──────────────────────────────────────────────────────
   Periods down, days across. A timetable is read at a glance or it is not
   read at all, so the grid gets fixed row heights, a sticky period column
   and enough contrast between subject and teacher to scan a column. */
/* Comparison: one row per candidate, one column per fact.
   No score column, and no "best" badge -- which candidate wins depends on
   priorities the school has not been asked for yet. The columns are sortable
   so an administrator can rank by whichever fact they care about, which is an
   honest substitute for a ranking nobody has approved. */
.ac-tt-cmp{width:100%;border-collapse:collapse;font-size:12px}
.ac-tt-cmp th{text-align:left;font-size:9.5px;text-transform:uppercase;
    letter-spacing:.5px;color:var(--t3);font-weight:700;padding:7px 10px;
    border-bottom:1px solid var(--border);white-space:nowrap;cursor:pointer;
    user-select:none}
.ac-tt-cmp th:hover{color:var(--gold)}
.ac-tt-cmp th[aria-sort]{color:var(--gold)}
.ac-tt-cmp th .ar{font-size:8px;margin-left:3px}
.ac-tt-cmp td{padding:8px 10px;border-bottom:1px solid var(--border);color:var(--t2)}
.ac-tt-cmp tr:last-child td{border-bottom:none}
.ac-tt-cmp td.n{text-align:right;font-family:var(--font-m);
    font-variant-numeric:tabular-nums;white-space:nowrap}
.ac-tt-cmp th.n{text-align:right}
.ac-tt-cmpname{color:var(--t1);font-weight:600}
.ac-tt-best{color:#16a34a;font-weight:700}
.ac-tt-worst{color:#d97706;font-weight:700}
.ac-tt-cmpnote{font-size:11px;color:var(--t3);line-height:1.5;margin:0 0 10px}

/* A choice between two ways of filling a week. Radios alone read as jargon;
   each option carries the sentence that explains what it will do. */
.ac-tt-choice{display:flex;gap:9px;align-items:flex-start;padding:9px 11px;
    border:1px solid var(--border);border-radius:8px;cursor:pointer;
    margin-bottom:7px;background:var(--bg2)}
.ac-tt-choice:hover{border-color:var(--gold)}
.ac-tt-choice input{margin-top:2px;flex:none}
.ac-tt-choicet{font-size:12px;font-weight:600;color:var(--t1);display:block}
.ac-tt-choicem{font-size:11px;color:var(--t3);line-height:1.45;display:block;
    margin-top:2px}

/* Report blocks: curriculum delivered, and how the teaching is spread. */
.ac-tt-repblock{margin-bottom:18px}
.ac-tt-rephead{font-size:11px;font-weight:700;color:var(--t2);
    text-transform:uppercase;letter-spacing:.6px;margin:0 0 6px}
.ac-tt-repnote{font-size:11px;color:var(--t3);line-height:1.5;margin:0 0 9px}
.ac-tt-repsec{font-size:11.5px;font-weight:700;color:var(--t1);margin:12px 0 4px}
.ac-tt-reptbl{width:100%;border-collapse:collapse;font-size:11.5px}
.ac-tt-reptbl th{text-align:left;font-size:9.5px;text-transform:uppercase;
    letter-spacing:.5px;color:var(--t3);font-weight:700;
    padding:3px 10px 3px 0;border-bottom:1px solid var(--border)}
.ac-tt-reptbl td{padding:5px 10px 5px 0;border-bottom:1px solid var(--border);
    color:var(--t2)}
.ac-tt-reptbl th.ac-tt-num,.ac-tt-reptbl td.ac-tt-num{text-align:right;
    font-variant-numeric:tabular-nums;font-family:var(--font-m);width:74px}
.ac-tt-repname{color:var(--t1);font-weight:600}
.ac-tt-flag{font-size:9.5px;font-weight:700;padding:1px 8px;border-radius:20px;
    white-space:nowrap}
.ac-tt-flag-ok{background:rgba(22,163,74,.12);color:#16a34a}
.ac-tt-flag-short{background:rgba(220,38,38,.12);color:#dc2626}
.ac-tt-flag-over{background:rgba(217,119,6,.12);color:#d97706}
.ac-tt-meter{height:5px;border-radius:3px;background:var(--bg3);overflow:hidden;
    min-width:80px}
.ac-tt-meterfill{height:100%;background:var(--gold);border-radius:3px}

/* Check results.
   Eighteen sentences that each differ by one word hide the thing worth
   knowing: that period 6 is empty all week. Group by TYPE, then by section,
   and show each occurrence as a slot chip -- the shape of the problem then
   reads at a glance instead of having to be assembled from prose. */
.ac-tt-finds{display:flex;flex-direction:column;gap:10px}
.ac-tt-find{border:1px solid var(--border);border-left:3px solid var(--t3);
    border-radius:8px;padding:11px 13px;background:var(--bg2)}
.ac-tt-find-hard{border-left-color:#dc2626}
.ac-tt-find-soft{border-left-color:#d97706}
.ac-tt-findhead{display:flex;align-items:center;justify-content:space-between;
    gap:10px}
.ac-tt-findname{font-size:12.5px;font-weight:700;color:var(--t1);
    letter-spacing:.1px}
.ac-tt-findcount{font-size:10.5px;font-weight:700;padding:1px 8px;
    border-radius:20px;background:var(--bg3);color:var(--t2);
    font-variant-numeric:tabular-nums;flex:none}
.ac-tt-find-hard .ac-tt-findcount{background:rgba(220,38,38,.12);color:#dc2626}
.ac-tt-find-soft .ac-tt-findcount{background:rgba(217,119,6,.12);color:#d97706}
.ac-tt-findwhy{font-size:11px;color:var(--t3);line-height:1.5;margin:4px 0 0}
.ac-tt-findwhere{display:flex;flex-direction:column;gap:5px;margin-top:9px}
.ac-tt-findrow{display:flex;align-items:baseline;gap:9px;flex-wrap:wrap}
.ac-tt-findsec{font-size:11px;font-weight:600;color:var(--t2);min-width:150px;
    flex:none}
.ac-tt-findslots{display:flex;gap:4px;flex-wrap:wrap}
.ac-tt-slot{font-size:10px;font-family:var(--font-m);padding:1px 6px;
    border-radius:4px;background:var(--bg3);color:var(--t2);
    border:1px solid var(--border);white-space:nowrap}
.ac-tt-badge-soft{background:rgba(217,119,6,.12);color:#d97706}
.ac-tt-findtitle{font-size:11px;font-weight:700;color:var(--t2);
    text-transform:uppercase;letter-spacing:.6px;margin:0 0 7px}

.ac-tt-week{table-layout:fixed}
.ac-tt-week th{text-align:center}
.ac-tt-week td{vertical-align:middle;padding:6px 8px}
.ac-tt-pcol{width:88px;text-align:center;background:var(--bg3);
    position:sticky;left:0;z-index:2}
.ac-tt-week td.ac-tt-pcol{border-right:1px solid var(--border)}
.ac-tt-time{font-size:9.5px;color:var(--t3);line-height:1.35;margin-top:2px;
    font-family:var(--font-m)}
.ac-tt-cellwrap{min-width:150px}
/* A break is a GAP between periods, not a slot: nothing is ever scheduled
   in it. But a week that jumps from 10:15 to 11:00 with no explanation is a
   week a teacher has to guess at, so the gap is drawn. */
.ac-tt-break td{background:var(--bg3);color:var(--t3);font-size:10px;
    font-style:italic;text-align:center;letter-spacing:.3px;padding:4px 8px}
.ac-tt-breakname{font-style:normal;font-weight:600;letter-spacing:.4px;
    text-transform:uppercase;font-size:9px}
.ac-tt-subj{display:block;font-size:12px;font-weight:600;color:var(--t1);
    line-height:1.3}
.ac-tt-teacher{display:block;font-size:10.5px;color:var(--t3);line-height:1.3;
    margin-top:1px}
.ac-tt-free{color:var(--t3);opacity:.6}
.ac-tt-cell{width:100%;padding:5px 6px;border:1px solid var(--border);
    border-radius:5px;background:var(--bg2);color:var(--t1);font-size:11.5px;
    font-family:inherit}
.ac-tt-cell:focus{outline:none;border-color:var(--gold);
    box-shadow:0 0 0 3px var(--gold-dim)}
/* A free period should read as empty at a glance, not as a filled cell. */
.ac-tt-week td:has(.ac-tt-free){background:repeating-linear-gradient(
    45deg,transparent,transparent 6px,var(--bg3) 6px,var(--bg3) 7px)}

/* Toasts. The global system reserves --z-toast but ships no component, and the
   attendance module's .att-toast belongs to its own stylesheet — so this panel
   carries a small one built from the host page's variables. */
.tt-toasts {
    position: fixed; right: 1.25rem; bottom: 1.25rem;
    z-index: var(--z-toast, 9500);
    display: flex; flex-direction: column; gap: .625rem;
    max-width: min(28rem, calc(100vw - 2.5rem));
}
.tt-toast {
    display: flex; align-items: flex-start; gap: .625rem;
    padding: .875rem 1rem;
    border-radius: 8px;
    border: 1px solid var(--border);
    border-left-width: 4px;
    background: var(--bg1,#fff);
    color: var(--t1);
    box-shadow: 0 8px 24px rgba(0,0,0,.14);
    font-size: 12.5px; line-height: 1.45;
    opacity: 0; transform: translateY(.5rem);
    transition: opacity .18s ease, transform .18s ease;
}
.tt-toast.is-in { opacity: 1; transform: translateY(0); }
.tt-toast__icon  { flex: 0 0 auto; margin-top: .1rem; }
.tt-toast__body  { flex: 1 1 auto; white-space: pre-line; }
.tt-toast__close {
    flex: 0 0 auto; background: none; border: 0; cursor: pointer;
    color: var(--t3); font-size: 1rem; line-height: 1; padding: .125rem;
}
.tt-toast--success { border-left-color: var(--success, #2e7d32); }
.tt-toast--success .tt-toast__icon { color: var(--success, #2e7d32); }
.tt-toast--error   { border-left-color: var(--danger, #c62828); }
.tt-toast--error   .tt-toast__icon { color: var(--danger, #c62828); }
.tt-toast--info    { border-left-color: var(--info, #0277bd); }
.tt-toast--info    .tt-toast__icon { color: var(--info, #0277bd); }
@media (prefers-reduced-motion: reduce) {
    .tt-toast { transition: none; }
}
</style>
<div class="ac-tt">

    <!-- ── Toolbar. The tab is already the title; a second page heading here
             would be duplication rather than hierarchy. ─────────────────── -->
    <div class="ac-tt-bar">
        <p class="ac-tt-lede">
            Build a timetable, check it, then publish it from a chosen date.
            Nothing reaches teachers until you publish.
        </p>
        <div class="ac-tt-actions">
            <button type="button" class="ac-btn ac-btn-s" id="tt_refresh">
                <i class="fa fa-refresh"></i> Refresh
            </button>
            <?php if ($tt_can_edit): ?>
            <button type="button" class="ac-btn ac-btn-p" data-app-modal-open="tt_generate_modal">
                <i class="fa fa-plus"></i> New timetable
            </button>
            <?php endif; ?>
        </div>
    </div>
        <!-- ── What applies today ──────────────────────────────────────── -->
        <div class="ac-card u-mb-6">
            <div class="ac-card-hd">
                <h2 class="ac-tt-cardtitle">In force</h2>
                <div class="u-flex u-gap-2 u-items-center">
                    <label class="u-text-tertiary u-text-sm" for="tt_date">on</label>
                    <input type="date" id="tt_date" class="ac-tt-input" style="width:auto">
                </div>
            </div>
            <div class="ac-card-body" id="tt_resolution">
                <div class="u-text-tertiary">Loading…</div>
            </div>
        </div>

        <!-- ── Versions ────────────────────────────────────────────────── -->
        <div class="ac-card u-mb-6">
            <div class="ac-card-hd">
                <h2 class="ac-tt-cardtitle">Versions</h2>
                <div class="u-flex u-gap-2 u-items-center">
                    <span class="u-text-tertiary u-text-sm" id="tt_version_count"></span>
                    <button type="button" class="ac-btn ac-btn-s ac-btn-sm" id="tt_capacity">
                        <i class="fa fa-database"></i> Free up room
                    </button>
                    <button type="button" class="ac-btn ac-btn-s ac-btn-sm" id="tt_compare">
                        <i class="fa fa-columns"></i> Compare
                    </button>
                </div>
            </div>
            <div class="ac-tt-tablewrap" style="border:0;border-radius:0;border-top:1px solid var(--border)">
                <table class="ac-table">
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Built</th>
                            <th>Applies from</th>
                            <th>Until</th>
                            <th>Checked</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tt_versions">
                        <tr><td colspan="7" class="u-text-tertiary">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Day / period grid editor ────────────────────────────────── -->
        <div class="ac-card u-mb-6" id="tt_editor_card" style="display:none">
            <div class="ac-card-hd">
                <h2 class="ac-tt-cardtitle" id="tt_editor_title">Timetable</h2>
                <div class="u-flex u-gap-2 u-items-center" id="tt_editor_context"></div>
            </div>
            <div class="ac-card-body">
                <div class="u-flex u-gap-3 u-items-end u-mb-4" style="flex-wrap:wrap">
                    <div>
                        <label class="ac-tt-label" for="tt_editor_section">Class &amp; section</label>
                        <select id="tt_editor_section" class="ac-tt-input"></select>
                        <!-- A timetable covers the classes it was BUILT for, which in a
                             school of 35 sections may be three. Without this the operator
                             reads the short list as a fault in the editor and has nowhere
                             to go (UAT 2026-09-12). -->
                        <div id="tt_editor_coverage" class="u-text-tertiary u-text-sm"
                             style="margin-top:4px"></div>
                    </div>

                </div>
                <div id="tt_editor_grid"><div class="u-text-tertiary">Select a class to see its week.</div></div>
            </div>
        </div>
        <!-- ── The selected version's check results ────────────────────── -->
        <div class="ac-card u-mb-6" id="tt_verdict_card" style="display:none">
            <div class="ac-card-hd">
                <h2 class="ac-tt-cardtitle">Timetable report — <span id="tt_verdict_version"></span></h2>
                <div class="u-flex u-gap-2" id="tt_verdict_pills"></div>
            </div>
            <div class="ac-card-body" id="tt_verdict_body"></div>
        </div>

</div>

<!-- ── Generate ────────────────────────────────────────────────────────── -->
<div class="ac-card u-mb-6" id="tt_capacity_card" style="display:none">
    <div class="ac-card-hd">
        <h2 class="ac-tt-cardtitle">Free up room</h2>
        <button type="button" class="ac-btn ac-btn-s ac-btn-sm" id="tt_capacity_close">Close</button>
    </div>
    <div class="ac-card-body">
        <p class="ac-tt-cmpnote" id="tt_capacity_note"></p>
        <div class="ac-tt-tablewrap" style="border:0">
            <table class="ac-tt-cmp" id="tt_capacity_tbl"></table>
        </div>
        <div class="u-flex u-gap-2 u-mt-4" id="tt_capacity_actions" style="display:none">
            <button type="button" class="ac-btn ac-btn-d ac-btn-sm" id="tt_reclaim">
                Remove the selected timetables
            </button>
            <span class="u-text-tertiary u-text-sm" id="tt_reclaim_count"></span>
        </div>
    </div>
</div>

<div class="ac-card u-mb-6" id="tt_compare_card" style="display:none">
    <div class="ac-card-hd">
        <h2 class="ac-tt-cardtitle">Compare timetables</h2>
        <button type="button" class="ac-btn ac-btn-s ac-btn-sm" id="tt_compare_close">Close</button>
    </div>
    <div class="ac-card-body">
        <p class="ac-tt-cmpnote" id="tt_compare_note"></p>
        <div class="ac-tt-tablewrap" style="border:0">
            <table class="ac-tt-cmp" id="tt_compare_tbl"></table>
        </div>
    </div>
</div>

<div id="tt_generate_modal" class="ac-tt-backdrop" role="dialog" aria-modal="true" aria-labelledby="tt_generate_title">
    <div class="ac-tt-modal ac-tt-modal">
        <header class="ac-tt-mhd">
            <h2 class="ac-tt-mtitle" id="tt_generate_title">Create a new timetable</h2>
            <button type="button" class="ac-tt-mclose" data-app-modal-close aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </header>
        <form id="tt_generate_form">
            <div class="ac-tt-mbody">
                <p class="u-text-secondary u-mb-4">
                    This creates a <strong>new proposed timetable</strong> and builds
                    it. Nothing that is already published changes, and no timetable
                    that teachers can see is touched.
                </p>
                <div class="ac-tt-row">
                    <span class="ac-tt-label">How should it be filled?</span>
                    <label class="ac-tt-choice">
                        <input type="radio" name="tt_fill" value="auto" checked>
                        <span>
                            <span class="ac-tt-choicet">Fill it automatically</span>
                            <span class="ac-tt-choicem">Places every subject from Subject
                            Assignments, keeping a teacher out of two rooms at once.</span>
                        </span>
                    </label>
                    <label class="ac-tt-choice">
                        <input type="radio" name="tt_fill" value="blank">
                        <span>
                            <span class="ac-tt-choicet">Leave it blank, I will type it in</span>
                            <span class="ac-tt-choicem">An empty week for every class, with the
                            periods and times already set. Works even before subjects are
                            assigned.</span>
                        </span>
                    </label>
                </div>
                <div class="ac-tt-row" id="tt_count_row">
                    <label class="ac-tt-label" for="tt_count">How many to build</label>
                    <select id="tt_count" class="ac-tt-input">
                        <option value="1">One timetable</option>
                        <option value="3" selected>Three, so I can compare them</option>
                        <option value="5">Five</option>
                    </select>
                    <small class="u-text-tertiary">Each one is built separately and is a
                    real alternative &mdash; not the same timetable rearranged. A large
                    school may be told a smaller number is possible.</small>
                </div>
                <div class="ac-tt-row">
                    <label class="ac-tt-label" for="tt_scope_class">Limit to one class (optional)</label>
                    <input type="text" id="tt_scope_class" class="ac-tt-input"
                           placeholder="Leave blank to cover every class">
                </div>
            </div>
            <footer class="ac-tt-mfoot">
                <button type="button" class="ac-btn ac-btn-s" data-app-modal-close>Cancel</button>
                <button type="submit" class="ac-btn ac-btn-p">
                    <span class="ac-tt-btnlabel">Generate</span>
                </button>
            </footer>
        </form>
    </div>
</div>

<!-- ── Publish ─────────────────────────────────────────────────────────── -->
<div id="tt_publish_modal" class="ac-tt-backdrop" role="dialog" aria-modal="true" aria-labelledby="tt_publish_title">
    <div class="ac-tt-modal ac-tt-modal">
        <header class="ac-tt-mhd">
            <h2 class="ac-tt-mtitle" id="tt_publish_title">Publish timetable</h2>
            <button type="button" class="ac-tt-mclose" data-app-modal-close aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </header>
        <form id="tt_publish_form">
            <input type="hidden" id="tt_publish_version">
            <div class="ac-tt-mbody">
                <p class="u-text-secondary u-mb-4">
                    Publishing <strong id="tt_publish_label"></strong> makes it the
                    timetable for the dates below. <strong>A published timetable cannot
                    be edited.</strong> To change it later, create a new version from it
                    and publish that.
                </p>
                <div class="ac-tt-row">
                    <label class="ac-tt-label ac-tt-label ac-tt-req" for="tt_effective_from">Applies from</label>
                    <input type="date" id="tt_effective_from" class="ac-tt-input" required>
                </div>
                <div class="ac-tt-row">
                    <label class="ac-tt-label" for="tt_effective_to">Until (optional)</label>
                    <input type="date" id="tt_effective_to" class="ac-tt-input">
                    <small class="u-text-tertiary">Leave blank to apply from that date onwards.</small>
                </div>
            </div>
            <footer class="ac-tt-mfoot">
                <button type="button" class="ac-btn ac-btn-s" data-app-modal-close>Cancel</button>
                <button type="submit" class="ac-btn ac-btn-p">
                    <span class="ac-tt-btnlabel">Publish</span>
                </button>
            </footer>
        </form>
    </div>
</div>

<div id="tt_supersede_modal" class="ac-tt-backdrop" role="dialog" aria-modal="true" aria-labelledby="tt_supersede_title">
    <div class="ac-tt-modal ac-tt-modal">
        <header class="ac-tt-mhd">
            <h2 class="ac-tt-mtitle" id="tt_supersede_title">Set an end date</h2>
            <button type="button" class="ac-tt-mclose" data-app-modal-close aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </header>
        <form id="tt_supersede_form">
            <input type="hidden" id="tt_supersede_version">
            <div class="ac-tt-mbody">
                <p class="u-text-secondary u-mb-4">
                    <strong id="tt_supersede_label"></strong> currently applies from its
                    start date <strong>onwards, with no end</strong>. A school can have
                    only one timetable in force on any day, so nothing can be published
                    after it until it has a last day.
                </p>
                <p class="u-text-secondary u-mb-4">
                    This changes <strong>when</strong> it applies, never what it contains,
                    and no lesson is altered. Teachers keep seeing it up to and including
                    the day below.
                </p>
                <div class="ac-tt-row">
                    <label class="ac-tt-label ac-tt-req" for="tt_supersede_last">Last day it applies</label>
                    <!-- min/value come from the SERVER's own date(), the same value
                         supersedeVersion() compares against, so the browser refuses
                         exactly what the server would. Deliberately NOT
                         `new Date().toISOString()` — that is UTC, and this server runs
                         Europe/Berlin, so between midnight and 02:00 it yields
                         YESTERDAY and would offer a date the server then rejects.
                         The same trap cost the Calendar programme a day. -->
                    <input type="date" id="tt_supersede_last" class="ac-tt-input" required
                           min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                    <small class="u-text-tertiary">Today at the earliest &mdash; an end date
                    in the past would change which timetable applied on days that have
                    already happened. Publish the next one from the day after.</small>
                </div>
                <div class="ac-tt-row">
                    <!-- REQUIRED, not optional: supersedeVersion() refuses an empty
                         reason because once a timetable is ended the reason is the
                         only record of why. Labelling it optional produced a server
                         refusal the operator could not have predicted. -->
                    <label class="ac-tt-label ac-tt-req" for="tt_supersede_reason">Why is it being ended?</label>
                    <input type="text" id="tt_supersede_reason" class="ac-tt-input" required
                           maxlength="200"
                           placeholder="e.g. replaced by the timetable that includes Class 8th">
                    <small class="u-text-tertiary">Once this timetable is ended, this
                    note is the only record of why.</small>
                </div>
            </div>
            <footer class="ac-tt-mfoot">
                <button type="button" class="ac-btn ac-btn-s" data-app-modal-close>Cancel</button>
                <button type="submit" class="ac-btn ac-btn-p">
                    <span class="ac-tt-btnlabel">Set end date</span>
                </button>
            </footer>
        </form>
    </div>
</div>

<div id="tt_rename_modal" class="ac-tt-backdrop" role="dialog" aria-modal="true" aria-labelledby="tt_rename_title">
    <div class="ac-tt-modal ac-tt-modal">
        <header class="ac-tt-mhd">
            <h2 class="ac-tt-mtitle" id="tt_rename_title">Name this timetable</h2>
            <button type="button" class="ac-tt-mclose" data-app-modal-close aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </header>
        <form id="tt_rename_form">
            <input type="hidden" id="tt_rename_version">
            <div class="ac-tt-mbody">
                <p class="u-text-secondary u-mb-4">
                    Give this timetable a name your school uses &mdash; <em>Winter</em>,
                    <em>Summer</em>, <em>Exam weeks</em>. The name is only a label:
                    it does not change any lesson, and which timetable applies is
                    still decided by its dates.
                </p>
                <div class="ac-tt-row">
                    <label class="ac-tt-label ac-tt-req" for="tt_rename_label">Name</label>
                    <input type="text" id="tt_rename_label" class="ac-tt-input"
                           maxlength="60" required placeholder="Winter timetable">
                </div>
            </div>
            <footer class="ac-tt-mfoot">
                <button type="button" class="ac-btn ac-btn-s" data-app-modal-close>Cancel</button>
                <button type="submit" class="ac-btn ac-btn-p">
                    <span class="ac-tt-btnlabel">Save name</span>
                </button>
            </footer>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    var BASE       = '<?= rtrim(base_url(), '/') ?>/';
    var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_TOKEN = '<?= $this->security->get_csrf_hash() ?>';
    var CAN_EDIT   = <?= $tt_can_edit ? 'true' : 'false' ?>;
    var CAN_MANAGE = <?= $tt_can_manage ? 'true' : 'false' ?>;

    /* The server's today, not the browser's: a device with a wrong clock (or a
       different timezone) would otherwise offer to send a timetable the server
       will refuse, or hide the button on the one day it works. */
    var TODAY      = '<?= date('Y-m-d') ?>';

    /**
     * The design system defines AppModal and AppBtn in tools/js/admin-system.js
     * — but NO view loads that file, so `data-app-modal-open` had nothing bound
     * to it and the Generate button did nothing when clicked.
     *
     * Rather than add a dependency on a script that is not loaded (and that the
     * production vhost may refuse under tools/), this page carries its own
     * minimal implementations. They follow the same contract the stylesheet
     * expects — `.is-open` on the backdrop, `.loading` on the button — so if
     * admin-system.js is ever loaded, the two agree and either can drive them.
     */
    var Modal = window.AppModal || {
        open: function (el) {
            if (!el) { return; }
            el.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            var first = el.querySelector('input, select, textarea, button:not([disabled])');
            if (first) { setTimeout(function () { first.focus(); }, 100); }
        },
        close: function (el) {
            if (!el) { return; }
            el.classList.remove('is-open');
            if (!document.querySelector('.ac-tt-backdrop.is-open')) {
                document.body.style.overflow = '';
            }
        }
    };

    /**
     * A themed yes/no, resolving true or false.
     *
     * window.confirm() renders the browser's own dialog, which on this box reads
     * "localhost says" — it names the developer's machine to a school office and
     * cannot be styled or worded properly (operator feedback, 2026-09-12). This
     * builds the same modal the rest of the page uses, so a destructive question
     * looks like it belongs to ZenXii and can say what actually happens.
     *
     * Built and torn down per call: nothing is left in the DOM to go stale, and
     * two confirms cannot share one set of handlers.
     */
    function confirmModal(opt) {
        return new Promise(function (resolve) {
            var back = document.createElement('div');
            back.className = 'ac-tt-backdrop';
            back.setAttribute('role', 'dialog');
            back.setAttribute('aria-modal', 'true');
            back.innerHTML =
                '<div class="ac-tt-modal">'
              + '<header class="ac-tt-mhd"><h2 class="ac-tt-mtitle">'
              + esc(opt.title || 'Are you sure?') + '</h2></header>'
              + '<div class="ac-tt-mbody"><p class="u-text-secondary">'
              + esc(opt.body || '') + '</p></div>'
              + '<footer class="ac-tt-mfoot">'
              + '<button type="button" class="ac-btn ac-btn-s" data-x-no>'
              + esc(opt.cancelText || 'Cancel') + '</button>'
              + '<button type="button" class="ac-btn '
              + (opt.dangerous ? 'ac-btn-d' : 'ac-btn-p') + '" data-x-yes>'
              + esc(opt.confirmText || 'Continue') + '</button>'
              + '</footer></div>';

            var done = function (answer) {
                document.removeEventListener('keydown', onKey);
                Modal.close(back);
                if (back.parentNode) { back.parentNode.removeChild(back); }
                resolve(answer);
            };
            var onKey = function (e) { if (e.key === 'Escape') { done(false); } };

            back.querySelector('[data-x-yes]').addEventListener('click', function () { done(true); });
            back.querySelector('[data-x-no]').addEventListener('click', function () { done(false); });
            // Clicking the backdrop itself cancels; clicking inside must not.
            back.addEventListener('click', function (e) {
                if (e.target === back) { done(false); }
            });
            document.addEventListener('keydown', onKey);

            document.body.appendChild(back);
            Modal.open(back);
        });
    }

    var Btn = window.AppBtn || {
        start: function (b) {
            if (!b) { return; }
            b.classList.add('loading');
            b.setAttribute('aria-busy', 'true');
            b.dataset._wasDisabled = b.disabled ? '1' : '0';
            b.disabled = true;
        },
        stop: function (b) {
            if (!b) { return; }
            b.classList.remove('loading');
            b.removeAttribute('aria-busy');
            b.disabled = b.dataset._wasDisabled === '1';
        }
    };

    // Bind the declarative openers/closers ourselves, but only if nothing else
    // already has — double-binding would toggle the modal open then shut.
    if (!window.AppModal) {
        document.addEventListener('click', function (e) {
            var opener = e.target.closest('[data-app-modal-open]');
            if (opener) {
                e.preventDefault();
                Modal.open(document.getElementById(
                    opener.getAttribute('data-app-modal-open')));
                return;
            }
            var closer = e.target.closest('[data-app-modal-close]');
            if (closer) {
                e.preventDefault();
                Modal.close(closer.closest('.ac-tt-backdrop'));
                return;
            }
            if (e.target.classList &&
                e.target.classList.contains('ac-tt-backdrop')) {
                Modal.close(e.target);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            var open = document.querySelectorAll('.ac-tt-backdrop.is-open');
            if (open.length) { Modal.close(open[open.length - 1]); }
        });
    }
    /**
     * Toasts, replacing window.alert().
     *
     * alert() blocks the page, cannot be styled, and looks like a browser
     * warning rather than the application answering. It is also modal, so it
     * covers the very thing the message is about.
     *
     * Errors here carry the server's own words — "Period Scheduling is not
     * configured", "3 problems must be fixed" — which the operator often needs
     * while looking at the screen, so an error stays until dismissed. Successes
     * are transient.
     */
    function toast(message, type) {
        var host = document.getElementById('tt_toasts');
        if (!host) {
            host = document.createElement('div');
            host.id = 'tt_toasts';
            host.className = 'tt-toasts';
            host.setAttribute('role', 'status');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }

        type = type || 'info';
        var icon = type === 'error' ? 'exclamation-circle'
                 : (type === 'success' ? 'check-circle' : 'info-circle');

        var el = document.createElement('div');
        el.className = 'tt-toast tt-toast--' + type;
        el.innerHTML =
            '<span class="tt-toast__icon"><i class="fa fa-' + icon + '"></i></span>' +
            '<div class="tt-toast__body"></div>' +
            '<button type="button" class="tt-toast__close" aria-label="Dismiss">' +
            '<i class="fa fa-times"></i></button>';
        // textContent, not innerHTML: the message may contain a version id or a
        // subject name that came from the server.
        el.querySelector('.tt-toast__body').textContent = String(message == null ? '' : message);

        var remove = function () {
            el.classList.remove('is-in');
            setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 200);
        };
        el.querySelector('.tt-toast__close').addEventListener('click', remove);

        host.appendChild(el);
        setTimeout(function () { el.classList.add('is-in'); }, 10);

        // An error is usually actionable and often long; it waits to be read.
        if (type !== 'error') { setTimeout(remove, 5000); }
        return el;
    }
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    /**
     * FAIL CLOSED.
     *
     * fetch() does not reject on 403 or 500, so a denied action would otherwise
     * render as success. Two things are checked, not one: the HTTP status AND
     * the payload's own status field, because an endpoint can answer 200 with
     * {status:'error'} and that must not be drawn as if it worked.
     */
    function api(url, params) {
        var opt = { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (params) {
            params[CSRF_NAME] = CSRF_TOKEN;
            opt.method = 'POST';
            opt.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            opt.body = new URLSearchParams(params).toString();
        }
        return fetch(BASE + url, opt).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
                if (d && d.csrf_token) { CSRF_TOKEN = d.csrf_token; }
                if (!r.ok || !d || d.status === 'error') {
                    var e = new Error((d && (d.message || d.error)) ||
                                      ('Request failed (' + r.status + ')'));
                    e.httpStatus = r.status;
                    throw e;
                }
                return d;
            });
        });
    }

    function fail(where, err) {
        var el = document.getElementById(where);
        if (el) {
            el.innerHTML = '<div class="u-text-danger"><i class="fa fa-exclamation-triangle"></i> ' +
                esc(err && err.message ? err.message : 'Something went wrong.') + '</div>';
        }
    }

    // ── what applies today ───────────────────────────────────────────────

    var RESOLUTION_PILL = {
        resolved:   ['ac-badge-done', 'In force'],
        none:       ['ac-badge-ip',    'Nothing published'],
        ambiguous:  ['ac-badge-hol',  'Ambiguous'],
        unreadable: ['ac-badge-hol',  'Could not be determined'],
        invalid:    ['ac-badge-hol',  'Invalid date']
    };

    function renderResolution(r) {
        var meta = RESOLUTION_PILL[r.status] || ['ac-badge-ip', esc(r.status)];
        var html = '<div class="u-flex u-gap-3 u-items-center u-mb-2">' +
                   '<span class="ac-badge ' + meta[0] + '">' + esc(meta[1]) + '</span>';
        if (r.version && r.version.versionId) {
            html += '<strong>' + esc(labelFor(r.version.versionId)) + '</strong>';
        }
        html += '</div><div class="u-text-secondary">' + esc(resolutionSentence(r)) + '</div>';

        // Ambiguous and unreadable are never resolved silently — the operator is
        // told which versions are competing, because nothing else can decide.
        if (r.status === 'ambiguous' && r.candidates && r.candidates.length) {
            html += '<ul class="u-mt-3 u-text-secondary">';
            r.candidates.forEach(function (c) {
                html += '<li>' + esc(labelFor(c.versionId)) + ' — from ' +
                        esc(fmtDate(c.effectiveFrom)) +
                        (c.effectiveTo ? ' to ' + esc(fmtDate(c.effectiveTo)) : ' onwards') + '</li>';
            });
            html += '</ul><p class="u-mt-2 u-text-secondary">Teachers keep seeing the ' +
                    'previously published timetable until this is resolved.</p>';
        }
        document.getElementById('tt_resolution').innerHTML = html
            + '<div id="tt_visibility" class="u-mt-3"></div>';
        loadVisibility();
    }

    /**
     * Are teachers actually seeing this timetable yet?
     *
     * Publishing is instant for the resolver and invisible to everything that
     * reads the legacy collection — the Teacher app, the Parent app, Substitute,
     * Lesson Planner. Those change only when the projection cron runs, and this
     * repository owns no scheduler. So a correct, in-force timetable can sit
     * unmaterialised while every app shows the previous one, with nothing
     * saying so: on 2026-09-12 the only symptom was a teacher missing from a
     * workload table.
     *
     * Read-only, and it decides nothing — the server asks the resolver, then
     * asks the projection for a dry run. Shown here because this is where the
     * gap is created.
     */
    /**
     * What is actually wrong, in the office's words.
     *
     * The server sends a diagnosis sentence; it is already written for a reader
     * rather than a log. Falling back to the pending count keeps the panel
     * honest against an older server that does not send one yet.
     */
    function whatIsWrong(d) {
        if (d.diagnosis) { return d.diagnosis; }
        return String(d.pending) + ' class page(s) do not match it.';
    }

    /** When the apps were last written to, if they ever were. */
    function lastSentNote(d) {
        if (!d.lastProjectedAt) { return ''; }
        return '<div class="u-text-tertiary u-text-sm u-mt-1">Last sent to the apps '
             + esc(fmtDateTime(d.lastProjectedAt)) + '.</div>';
    }

    function loadVisibility() {
        var host = document.getElementById('tt_visibility');
        if (!host) { return; }
        var date = document.getElementById('tt_date').value || '';

        api('timetable/visibility?date=' + encodeURIComponent(date))
            .then(function (d) {
                if (!d || d.comparable !== true) { host.innerHTML = ''; return; }

                // `current` is the server's EQUIVALENCE verdict — every class
                // page compared, content and origin — not "a projection command
                // completed". Those differ in the case that matters: a run from
                // the wrong timetable completes just as successfully.
                if (d.current) {
                    host.innerHTML =
                        '<div class="u-text-tertiary u-text-sm">'
                      + '<i class="fa fa-check-circle" style="color:var(--gold)"></i> '
                      + 'Teachers and parents are seeing this timetable'
                      + (d.expected ? ' — all ' + esc(String(d.expected))
                                      + ' class page(s) match it' : '')
                      + '.' + lastSentNote(d) + '</div>';
                    return;
                }

                // The apps can only be sent the timetable that applies TODAY —
                // they hold one copy per weekday with no date on it. So the
                // message for today (something you can fix now) and the message
                // for another day (something you cannot yet) are different, and
                // showing a button that would be refused is worse than none.
                var isToday = (d.date === TODAY);
                var pending = esc(String(d.pending));

                host.innerHTML =
                    '<div class="ac-tt-find ac-tt-find-hard u-mt-2">'
                  + (isToday
                      ? '<strong>Teachers and parents are still seeing a different '
                        + 'timetable.</strong><br>'
                        // The server's diagnosis, which distinguishes a class
                        // whose page is MISSING from one that is merely a day
                        // behind. A bare count reads the same for both, and the
                        // difference is what the office needs in order to act.
                        + esc(whatIsWrong(d))
                        + ' Their apps keep their own copy; the office screen '
                        + 'changed the moment you published.'
                        + lastSentNote(d)
                        // Offered only to someone who may actually do it. A
                        // button that answers 403 teaches the office that the
                        // screen lies; the sentence stays either way, because
                        // knowing the apps are behind matters to everyone.
                        + (CAN_MANAGE
                            ? '<div class="u-mt-2">'
                              + '<button type="button" class="ac-btn ac-btn-p ac-btn-sm" '
                              + 'id="tt_send_apps">Send to teachers and parents now</button>'
                              + '</div>'
                            : '<div class="u-mt-2 u-text-sm u-text-tertiary">Someone who '
                              + 'can publish timetables needs to send this.</div>')
                      : '<strong>On ' + esc(fmtDate(d.date)) + ', teachers and '
                        + 'parents will still be seeing a different timetable.'
                        + '</strong><br>'
                        + pending + ' class page(s) in their apps would not match '
                        + 'this timetable. Open this screen on '
                        + esc(fmtDate(d.date)) + ' and press <em>Send to teachers '
                        + 'and parents</em> — it can only be sent on the day it '
                        + 'applies, because the apps hold one timetable per weekday '
                        + 'with no date attached.')
                  // The command is the right answer for whoever runs the server
                  // and the wrong thing to put in front of a school office, which
                  // cannot run it and should not have to read a tenant code to
                  // learn its own timetable is stale. Kept, not shown by default.
                  + (d.command
                      ? '<details class="u-mt-2"><summary class="u-text-sm '
                        + 'u-text-tertiary">For whoever manages your server</summary>'
                        + '<code class="u-text-sm">' + esc(d.command) + '</code>'
                        + '</details>'
                      : '')
                  + '</div>';

                var btn = document.getElementById('tt_send_apps');
                if (btn) { btn.addEventListener('click', function () { sendToApps(btn); }); }
            })
            // Never claim "all good" because the check itself failed — that is
            // the silent-stale failure this exists to prevent, one level up.
            .catch(function (e) {
                host.innerHTML =
                    '<div class="u-text-tertiary u-text-sm">'
                  + '<i class="fa fa-question-circle"></i> Whether teachers are seeing '
                  + 'this timetable could not be checked'
                  + (e && e.message ? ' — ' + esc(e.message) : '') + '.</div>';
            });
    }

    /**
     * Send today's timetable to the Teacher and Parent apps.
     *
     * The office and the apps read different copies. Publishing updates the
     * office immediately; the apps' copy is updated here. Before this existed
     * the only way to do it was a command line, which a school cannot run — so
     * a timetable published to start on a later date reached nobody.
     *
     * Confirmed first: this changes what every teacher and parent in the school
     * opens, and there is no undo — the apps hold one timetable, so putting the
     * previous one back means sending it again.
     */
    function sendToApps(btn) {
        if (!CAN_MANAGE) { return; }

        confirmModal({
            title: 'Send this timetable to teachers and parents?',
            body: 'Every teacher and parent will see the timetable that applies '
                + 'today, replacing what their apps show now. There is no undo.',
            confirmText: 'Send now'
        }).then(function (ok) {
            if (!ok) { return; }

            Btn.start(btn);
            // The date goes with the request so a screen left open overnight
            // cannot send yesterday's timetable: the server compares it against
            // its own today and refuses rather than guessing which was meant.
            api('timetable/apply_projection', { date: TODAY })
                .then(function (d) {
                    toast((d && d.message) || 'Sent.', 'success');
                    // Re-ask rather than assume: the banner must reflect what the
                    // server now reports, not what we hoped the write achieved.
                    loadVisibility();
                })
                .catch(function (e) {
                    Btn.stop(btn);
                    toast(e.message
                        || 'It could not be sent. Teachers and parents still see '
                         + 'what they saw before.', 'error');
                });
        });
    }

    /**
     * What is in force, said plainly.
     *
     * The server also sends an `explain` string, but it is written for a log and
     * the CLI projection cron: it carries the document id, ISO dates, the plan
     * key and words like AMBIGUOUS and open-ended. All of that is right for a
     * cron transcript and wrong for a school office, and the screen already has
     * the structured fields, so it writes its own sentence rather than degrading
     * the one an engineer relies on.
     */
    function resolutionSentence(r) {
        var on = fmtDate(document.getElementById('tt_date').value) || 'this date';
        var st = String(r.status || '');

        if (st === 'resolved') {
            var v = r.version || {};
            var to = String(v.effectiveTo || '');
            return labelFor(v.versionId) + ' applies on ' + on + ' — in force from ' +
                   fmtDate(v.effectiveFrom) +
                   (to ? ' to ' + fmtDate(to) : ' onwards') + '.';
        }
        if (st === 'none') {
            return 'No timetable has been published for ' + on + '. ' +
                   'Teachers continue to see whatever they saw before.';
        }
        if (st === 'ambiguous') {
            return 'More than one published timetable covers ' + on + ', so it is ' +
                   'not clear which applies. Nothing changes for teachers until ' +
                   'the overlap is resolved.';
        }
        if (st === 'unreadable') {
            return 'The timetable for ' + on + ' could not be read, so it is not ' +
                   'known which one applies. This is a failure to look, not a ' +
                   'confirmed absence.';
        }
        if (st === 'invalid') {
            return 'That date could not be understood.';
        }
        return String(r.explain || '');
    }
    // ── versions ─────────────────────────────────────────────────────────

    /**
     * How a version is NAMED to a person.
     *
     * The stored id is a document key — unreadable, unsayable, and impossible to
     * tell apart at a glance from another made the same day. A school identifies
     * a timetable two ways: a published one by WHEN IT APPLIES, and an
     * unfinished one by WHICH ATTEMPT it is and WHEN it was built.
     *
     * The id is unchanged in the data and in every request; it survives on
     * screen only as a tooltip, for support.
     */
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    /** '2026-09-01' → '1 Sep 2026'. Sliced, not parsed: a Date would shift it. */
    function fmtDate(iso) {
        iso = String(iso || '');
        if (iso.length < 10) { return ''; }
        var y = iso.slice(0, 4), m = parseInt(iso.slice(5, 7), 10), d = parseInt(iso.slice(8, 10), 10);
        if (!m || !d) { return ''; }
        return d + ' ' + (MONTHS[m - 1] || '?') + ' ' + y;
    }

    /** '2026-08-29T15:49:43+05:30' → '29 Aug 2026, 3:49 pm'. */
    function fmtDateTime(iso) {
        iso = String(iso || '');
        var date = fmtDate(iso);
        if (!date || iso.length < 16) { return date; }
        var hh = parseInt(iso.slice(11, 13), 10);
        var mm = iso.slice(14, 16);
        if (isNaN(hh)) { return date; }
        var ampm = hh < 12 ? 'am' : 'pm';
        var h12 = hh % 12; if (h12 === 0) { h12 = 12; }
        return date + ', ' + h12 + ':' + mm + ' ' + ampm;
    }

    /** versionId → the name a person sees. Rebuilt on every load. */
    var VERSION_LABEL = {};

    function labelFor(id) {
        return VERSION_LABEL[id] || 'this timetable';
    }

    /**
     * Name every version in the list.
     *
     * Proposals are numbered in the order they were built, so "Proposal 3" means
     * the same thing to everyone looking at the screen.
     */
    var VERSION_OWN = {};
    var VERSIONS = [];

    function buildLabels(list) {
        VERSION_LABEL = {};

        var proposals = list.filter(function (v) {
            var st = String(v.status || '');
            return st === 'draft' || st === 'candidate';
        }).slice().sort(function (a, b) {
            return String(a.createdAt || '').localeCompare(String(b.createdAt || ''));
        });

        proposals.forEach(function (v, i) {
            var when = fmtDateTime(v.createdAt);
            VERSION_LABEL[v.versionId] = 'Proposal ' + (i + 1) + (when ? ' \u00b7 built ' + when : '');
        });

        // A name the school typed always wins over anything generated here.
        VERSION_OWN = {};
        list.forEach(function (v) {
            var own = String(v.label || '').trim();
            if (own) { VERSION_OWN[v.versionId] = own; }
        });

        list.forEach(function (v) {
            var st = String(v.status || '');
            if (st === 'published') {
                var from = fmtDate(v.effectiveFrom);
                VERSION_LABEL[v.versionId] = from ? 'Timetable from ' + from : 'Published timetable';
            } else if (st === 'superseded') {
                var f2 = fmtDate(v.effectiveFrom);
                VERSION_LABEL[v.versionId] = f2 ? 'Replaced timetable from ' + f2 : 'Replaced timetable';
            }
        });

        Object.keys(VERSION_OWN).forEach(function (id) {
            VERSION_LABEL[id] = VERSION_OWN[id];
        });
    }
    var STATUS_PILL = {
        draft:      'ac-badge-exam',
        candidate:  'ac-badge-ip',
        published:  'ac-badge-done',
        superseded: 'ac-badge-ns'
    };

    /**
     * What a school calls these, as opposed to what the system stores.
     *
     * "candidate" is our internal lifecycle word and means nothing to a school
     * office. The STORED values are unchanged — these are labels only, so the
     * data, the API and the rules keep their vocabulary while the screen speaks
     * the operator's.
     */
    var STATUS_LABEL = {
        draft:      'Draft',
        candidate:  'Proposed',
        published:  'Published',
        superseded: 'Replaced'
    };

    function statusLabel(st) {
        return STATUS_LABEL[st] || (st ? st.charAt(0).toUpperCase() + st.slice(1) : '—');
    }

    function renderVersions(list) {
        buildLabels(list);

        var tb = document.getElementById('tt_versions');
        document.getElementById('tt_version_count').textContent =
            list.length + (list.length === 1 ? ' version' : ' versions');

        if (!list.length) {
            tb.innerHTML = '<tr><td colspan="7">' +
                '<div class="ac-empty"><div class="ac-tt-emptyicon">' +
                '<i class="fa fa-calendar-o"></i></div>' +
                '<h3 class="ac-tt-emptytitle">No timetable versions yet</h3>' +
                '<p class="ac-tt-emptymsg">Generate one to get started. It is created as a ' +
                'proposal, so nothing goes live until you publish it.</p></div></td></tr>';
            return;
        }

        // Newest first. effectiveFrom alone is not enough to order by: every
        // unpublished version carries the same placeholder date, so two
        // proposals built on one day tied and the order was whatever the query
        // happened to return.
        list.sort(function (a, b) {
            var d = String(b.effectiveFrom || '').localeCompare(String(a.effectiveFrom || ''));
            if (d !== 0) { return d; }
            return String(b.createdAt || '').localeCompare(String(a.createdAt || ''));
        });

        tb.innerHTML = list.map(function (v) {
            var st  = String(v.status || '');
            var pill = STATUS_PILL[st] || 'ac-badge-ns';
            // How this version was BUILT, and whether that finished. A version
            // left behind by a failed run looks identical to a good one in a
            // status column, and publishing it is refused with no clue why —
            // so the run's own state is shown here.
            var gen   = v.generation || {};
            var state = String(gen.state || '');
            var built;
            if (state === 'complete') {
                var kind = (gen.context && gen.context.kind) === 'change_version_copy'
                    ? 'Copied' : 'Generated';
                built = '<span class="ac-badge ac-badge-done">' + esc(kind) + '</span>';
            } else if (state === 'failed') {
                built = '<span class="ac-badge ac-badge-hol">Build failed</span>' +
                        '<div class="u-text-tertiary u-text-sm">' +
                        esc(gen.reason || 'It stopped part-way, so it cannot be used.') +
                        '</div>';
            } else if (state === 'running') {
                built = '<span class="ac-badge ac-badge-ip">Building…</span>';
            } else {
                built = '<span class="u-text-tertiary">By hand</span>';
            }

            // WHEN it was built. Two proposals made on the same day were
            // otherwise identical on screen, and the wrong one got checked.
            var made = String(v.createdAt || '');
            if (made.length >= 16) {
                built += '<div class="u-text-tertiary u-text-sm">' +
                         esc(made.slice(0, 10) + ' ' + made.slice(11, 16)) + '</div>';
            }

            // Provenance only — it says where this came from and nothing more.
            //
            // The source is named, never its identifier: "from
            // CAND_20260829154943_f16245" is a database key on an
            // administrator's screen. And because the source is a proposal like
            // any other, it can since have been discarded — in which case
            // saying so is the honest answer, not printing an id that resolves
            // to nothing.
            if (v.regeneratedFrom) {
                var srcName = VERSION_LABEL[v.regeneratedFrom];
                built += '<div class="u-text-tertiary u-text-sm">' +
                         (srcName
                            ? 'from ' + esc(srcName)
                            : 'copied from a timetable that has since been removed') +
                         '</div>';
            }

            var checked = v.validation
                ? (v.validationStale
                    ? '<span class="ac-badge ac-badge-ip">Needs re-check</span>'
                    : (v.validation.publishable
                        ? '<span class="ac-badge ac-badge-done">No problems</span>'
                        : '<span class="ac-badge ac-badge-hol">' +
                          esc(v.validation.hardCount) + ' to fix</span>'))
                : '<span class="u-text-tertiary">Not checked</span>';

            var actions = '<button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                          'data-edit="' + esc(v.versionId) + '">' +
                          (st === 'published' ? 'View' : 'Edit') + '</button>' +
                          ' <button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                          'data-verdict="' + esc(v.versionId) + '">Results</button>' +
                          // Printing reveals nothing the screen does not, so this
                          // follows the `view` grade. An unpublished timetable is
                          // stamped as a proposal on the page itself.
                          ' <button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                          'data-pdf="' + esc(v.versionId) + '" title="Download as PDF">' +
                          '<i class="fa fa-file-pdf-o"></i> PDF</button>';

            // Naming is offered at every status, published included: the label
            // is not the timetable, and naming the one that is actually running
            // is the whole reason a school wants this.
            if (CAN_EDIT) {
                actions += ' <button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                           'data-rename="' + esc(v.versionId) + '" title="Name this timetable">' +
                           '<i class="fa fa-pencil"></i></button>';
            }

            // A run that failed or is still going cannot be checked or published,
            // and the server refuses both. Offering the buttons anyway would let
            // the operator collect two refusals before finding out why.
            var usable = (state !== 'failed' && state !== 'running');

            if (CAN_EDIT && usable && (st === 'draft' || st === 'candidate')) {
                actions += ' <button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                           'data-validate="' + esc(v.versionId) + '">' +
                           '<span class="ac-tt-btnlabel">Check</span>' +
                           '</button>';
            }
            if (CAN_MANAGE && usable && (st === 'draft' || st === 'candidate')) {
                actions += ' <button type="button" class="ac-btn ac-btn-p ac-btn-sm" ' +
                           'data-publish="' + esc(v.versionId) + '">Publish</button>';
            }
            // Discard is offered for UNPUBLISHED work only. A published
            // timetable is the record of what was in force, and deleting it
            // would destroy the answer to "what was the timetable that day" —
            // which attendance and substitution are audited against.
            if (CAN_EDIT && (st === 'draft' || st === 'candidate')) {
                actions += ' <button type="button" class="ac-btn ac-btn-d ac-btn-sm" ' +
                           'data-discard="' + esc(v.versionId) + '">Discard</button>';
            }

            if (CAN_EDIT && st === 'published') {
                actions += ' <button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                           'data-change="' + esc(v.versionId) + '">' +
                           '<span class="ac-tt-btnlabel">Create new version from this</span>' +
                           '</button>';

                // Only an OPEN-ENDED published timetable blocks a later one: a
                // school may have just one in force on any day, so publishing
                // anything after it was refused with "end the existing timetable
                // first" and no control existed to do that (UAT 2026-09-12).
                // The endpoint was already built, permissioned and audited; only
                // the button was missing.
                if (!v.effectiveTo) {
                    actions += ' <button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                               'data-supersede="' + esc(v.versionId) + '" ' +
                               'title="Set the last day this timetable applies, so a later one can take over">' +
                               '<span class="ac-tt-btnlabel">Set an end date</span>' +
                               '</button>';
                }
            }

            return '<tr>' +
                '<td title="' + esc(v.versionId) + '"><strong>' +
                    esc(labelFor(v.versionId)) + '</strong></td>' +
                '<td><span class="ac-badge ' + pill + '">' + esc(statusLabel(st)) + '</span></td>' +
                '<td>' + built + '</td>' +
                // An unpublished version's dates are a placeholder, not a
                // promise: it applies from nothing until it is published, and
                // printing today's date there reads as a commitment it has not
                // made.
                '<td>' + (st === 'published'
                    ? esc(v.effectiveFrom || '—')
                    : '<span class="u-text-tertiary">set when published</span>') + '</td>' +
                '<td>' + (st === 'published'
                    ? esc(v.effectiveTo || 'onwards')
                    : '<span class="u-text-tertiary">—</span>') + '</td>' +
                '<td>' + checked + '</td>' +
                '<td style="text-align:right">' + actions + '</td>' +
                '</tr>';
        }).join('');
    }

    // ── check results ────────────────────────────────────────────────────

    /* What each finding IS, and what the operator is meant to do about it.
       `unallocated_period` means nothing to a head teacher; "Free periods"
       plus one line of guidance means something. */
    var FINDING = {
        teacher_double_booked:   ['Teacher in two places at once',
            'The same teacher is timetabled for two sections in the same slot. One of those lessons will not be taught.'],
        slot_duplicated:         ['Two lessons in one slot',
            'A section has more than one lesson stored for the same period.'],
        subject_without_teacher: ['Subject with nobody assigned',
            'The Teacher app finds a lesson by its teacher, so a lesson with nobody assigned reaches no one.'],
        day_duplicated:          ['Day stored twice',
            'The same day appears more than once for a section.'],
        section_has_no_days:     ['Section with no timetable',
            'This section has no days at all.'],
        unreadable_input:        ['Could not be read',
            'Part of the timetable could not be loaded, so these results are incomplete.'],
        unallocated_period:      ['Free periods',
            'Nothing is scheduled here. That is fine where the day ends early \u2014 otherwise assign a subject.'],
        subject_twice_in_a_day:  ['Same subject twice in a day',
            'Deliberate for a double period, worth confirming otherwise.'],
        missing_day_for_section: ['Missing day',
            'Other sections have a timetable for this day and this one does not.'],
        teacher_long_stretch:    ['Long stretch without a break',
            'A teacher is scheduled for several periods back to back.']
    };

    var DAY_SHORT = { Monday: 'Mon', Tuesday: 'Tue', Wednesday: 'Wed',
                      Thursday: 'Thu', Friday: 'Fri', Saturday: 'Sat',
                      Sunday: 'Sun' };

    /* Teacher IDs are for the database, not the screen. Names are learnt from
       whatever has already been loaded, and an unknown ID falls back to itself
       rather than being hidden. */
    var TEACHER_NAME = {};
    function learnTeachers(rows) {
        (rows || []).forEach(function (r) {
            if (!r) { return; }
            if (r.teacherId && r.teacher) { TEACHER_NAME[r.teacherId] = r.teacher; }
            (r.periods || []).forEach(function (p) {
                if (p && p.teacherId && p.teacher) { TEACHER_NAME[p.teacherId] = p.teacher; }
            });
        });
    }
    function teacherName(id) { return TEACHER_NAME[id] || id; }

    /* ── BREAKS ──────────────────────────────────────────────────────────
       A recess is configured in Period Scheduling as "after period N, D
       minutes", and the times already encode it: period N ends, period N+1
       starts D minutes later. Nothing is ever scheduled in between, because a
       break is not a slot.

       The break is therefore DERIVED from the stored times rather than read
       from settings again. That way the row always describes the timetable that
       actually exists, even for a version generated before the settings changed.

       "Break" is the word the timetable has always used for this. A school that
       calls it Lunch, Recess or Tiffin cannot say so yet — no name is stored
       anywhere — which is a small per-school setting, not something to invent
       here. */

    /** "10:15AM" -> minutes since midnight, or null if it cannot be read. */
    function timeToMin(s) {
        var m = /^(\d{1,2}):(\d{2})\s*(AM|PM)$/i.exec(String(s || '').trim());
        if (!m) { return null; }
        var h = parseInt(m[1], 10) % 12;
        if (/PM/i.test(m[3])) { h += 12; }
        return h * 60 + parseInt(m[2], 10);
    }

    /**
     * What to call the break after period `n` (1-based).
     *
     * A school may call it Lunch, Recess, Tiffin or Interval; one that has
     * named nothing keeps the generic word. The lookup is by the period the
     * break follows, so a school that later MOVES its break finds no match
     * against an older timetable's gap and falls back to "Break" -- a
     * historical timetable is labelled generically, never wrongly.
     */
    function breakName(n) {
        var name = (EDIT.breaks || {})[n] || (EDIT.breaks || {})[String(n)];
        return (typeof name === 'string' && name.trim()) ? name.trim() : 'Break';
    }

    /** Minutes of break between two consecutive periods, or 0 if none. */
    function breakAfter(thisPeriod, nextPeriod) {
        if (!thisPeriod || !nextPeriod) { return 0; }
        var end   = timeToMin(thisPeriod.endTime);
        var start = timeToMin(nextPeriod.startTime);
        if (end === null || start === null) { return 0; }
        var gap = start - end;
        return gap > 0 ? gap : 0;
    }

    /** Where one finding happens, as short as it can be and stay unambiguous. */
    function slotLabel(x) {
        var w = x.where || {}, bits = [];
        if (w.day)     { bits.push(DAY_SHORT[w.day] || w.day); }
        if (w.period)  { bits.push('P' + w.period); }
        if (w.subject) { bits.push(w.subject + (w.count ? ' \u00d7' + w.count : '')); }
        // Nothing locatable: show the sentence rather than an empty chip.
        return bits.length ? bits.join(' ') : (x.message || '');
    }

    /**
     * Group findings by type, then by whoever they belong to -- a section, or a
     * teacher for the two findings that are about a person rather than a class.
     * Order is preserved throughout so the same timetable always reads the same
     * way.
     */
    function renderFindings(list, severity) {
        var order = [], byCode = {};
        list.forEach(function (x) {
            var code = x.code || 'other';
            if (!byCode[code]) { byCode[code] = []; order.push(code); }
            byCode[code].push(x);
        });

        var html = '';
        order.forEach(function (code) {
            var items = byCode[code], meta = FINDING[code] || [code, ''];

            html += '<div class="ac-tt-find ac-tt-find-' + severity + '">' +
                    '<div class="ac-tt-findhead">' +
                    '<span class="ac-tt-findname">' + esc(meta[0]) + '</span>' +
                    '<span class="ac-tt-findcount">' + esc(items.length) + '</span>' +
                    '</div>';
            if (meta[1]) { html += '<p class="ac-tt-findwhy">' + esc(meta[1]) + '</p>'; }

            var who = [], byWho = {};
            items.forEach(function (x) {
                var w = x.where || {};
                var k = w.section ? w.section
                      : (w.teacherId ? teacherName(w.teacherId) : '');
                if (!byWho[k]) { byWho[k] = []; who.push(k); }
                byWho[k].push(x);
            });

            html += '<div class="ac-tt-findwhere">';
            who.forEach(function (k) {
                html += '<div class="ac-tt-findrow">';
                if (k) { html += '<span class="ac-tt-findsec">' + esc(k) + '</span>'; }
                html += '<span class="ac-tt-findslots">';
                byWho[k].forEach(function (x) {
                    html += '<span class="ac-tt-slot">' + esc(slotLabel(x)) + '</span>';
                });
                html += '</span></div>';
            });
            html += '</div></div>';
        });
        return html;
    }

    /* The report.
       A verdict says what is WRONG. A school also needs to know whether the
       timetable delivers the curriculum it asked for, and how the teaching is
       spread across its staff -- the two questions a head teacher actually has
       once a timetable exists. Both are computed from the timetable itself and
       the subject assignments the school already set, so neither invents a
       standard ZenX does not hold. */

    /** The class-sections present in a set of days, in reading order. */
    function sectionsOf(days) {
        var seen = {}, out = [];
        (days || []).forEach(function (d) {
            var s = d && d.classSection;
            if (s && !seen[s]) { seen[s] = true; out.push(s); }
        });
        return out;
    }

    /** Periods actually scheduled, by section and subject, and per teacher. */
    function tally(days) {
        var bySection = {}, load = {};
        (days || []).forEach(function (d) {
            var sec = d && d.classSection;
            if (!sec) { return; }
            bySection[sec] = bySection[sec] || {};
            (d.periods || []).forEach(function (p) {
                if (!p) { return; }
                if (p.subject)   { bySection[sec][p.subject] = (bySection[sec][p.subject] || 0) + 1; }
                if (p.teacherId) { load[p.teacherId] = (load[p.teacherId] || 0) + 1; }
            });
        });
        return { bySection: bySection, load: load };
    }

    /**
     * Curriculum delivered. The target for a subject is the SUM of its
     * periodsPerWeek across that subject's assignment rows, which is exactly
     * what the generator places from -- so "asked for" here means the same
     * thing it meant when the timetable was built.
     */
    function renderCurriculum(t, quotas) {
        var html = '<div class="ac-tt-repblock">' +
            '<h3 class="ac-tt-rephead">Curriculum delivered</h3>' +
            '<p class="ac-tt-repnote">Every subject is compared with the periods a week ' +
            'set for it in Subject Assignments. A subject that is short has lost ' +
            'teaching time, and will not make it up on its own.</p>';

        quotas.forEach(function (q) {
            html += '<div class="ac-tt-repsec">' + esc(q.section) + '</div>';

            // A quota we could not read is SAID, never shown as a zero target
            // that would make a correct timetable look wrong.
            if (!q.options) {
                html += '<p class="ac-tt-repnote u-text-warn">' +
                        '<i class="fa fa-exclamation-triangle"></i> ' +
                        esc(q.error || 'The subject assignments could not be read') +
                        ' \u2014 this section cannot be checked against its curriculum.</p>';
                return;
            }

            var asked = {}, order = [];
            q.options.forEach(function (o) {
                if (!o || !o.subject) { return; }
                if (!(o.subject in asked)) { asked[o.subject] = 0; order.push(o.subject); }
                asked[o.subject] += (o.periodsPerWeek || 0);
            });
            // A subject ON the timetable but not in the assignments still counts:
            // dropping it would hide teaching nobody asked for.
            var got = t.bySection[q.section] || {};
            Object.keys(got).forEach(function (sub) {
                if (!(sub in asked)) { asked[sub] = 0; order.push(sub); }
            });

            if (!order.length) {
                html += '<p class="ac-tt-repnote">No subjects are assigned to this section.</p>';
                return;
            }

            html += '<table class="ac-tt-reptbl"><thead><tr><th>Subject</th>' +
                    '<th class="ac-tt-num">Asked</th><th class="ac-tt-num">Scheduled</th>' +
                    '<th></th></tr></thead><tbody>';
            order.forEach(function (sub) {
                var a = asked[sub], g = got[sub] || 0, flag;
                if (a === 0)    { flag = ['over',  'not assigned']; }
                else if (g < a) { flag = ['short', (a - g) + ' short']; }
                else if (g > a) { flag = ['over',  (g - a) + ' extra']; }
                else            { flag = ['ok',    'as asked']; }
                html += '<tr><td class="ac-tt-repname">' + esc(sub) + '</td>' +
                        '<td class="ac-tt-num">' + (a === 0 ? '\u2014' : a) + '</td>' +
                        '<td class="ac-tt-num">' + g + '</td>' +
                        '<td><span class="ac-tt-flag ac-tt-flag-' + flag[0] + '">' +
                        esc(flag[1]) + '</span></td></tr>';
            });
            html += '</tbody></table>';
        });
        return html + '</div>';
    }

    /** How the teaching is spread. Heaviest first, which is what gets checked. */
    function renderLoad(t) {
        var ids = Object.keys(t.load);
        if (!ids.length) { return ''; }
        ids.sort(function (a, b) { return t.load[b] - t.load[a]; });
        var max = t.load[ids[0]] || 1;

        var html = '<div class="ac-tt-repblock">' +
            '<h3 class="ac-tt-rephead">Teaching load</h3>' +
            '<p class="ac-tt-repnote">Periods a week in this timetable. It counts only ' +
            'the classes timetabled here, so a teacher who also teaches years that are ' +
            'not yet timetabled will carry more than this.</p>' +
            '<table class="ac-tt-reptbl"><tbody>';
        ids.forEach(function (id) {
            var n = t.load[id];
            html += '<tr><td class="ac-tt-repname">' + esc(teacherName(id)) + '</td>' +
                    '<td class="ac-tt-num">' + n + '</td>' +
                    '<td><div class="ac-tt-meter"><div class="ac-tt-meterfill" style="width:' +
                    Math.round(n / max * 100) + '%"></div></div></td></tr>';
        });
        return html + '</tbody></table></div>';
    }

    /**
     * Fetch what the report needs, then draw it. A failure here costs the
     * summary, not the findings: the verdict already stands on its own, and
     * the panel says which half is missing rather than showing a blank.
     */
    function showReport(versionId, verdict) {
        return api('timetable/days?version_id=' + encodeURIComponent(versionId))
            .then(function (d) {
                var days = d.days || [];
                learnTeachers(days);
                return Promise.all(sectionsOf(days).map(function (sec) {
                    return api('timetable/editor_options?class_section=' + encodeURIComponent(sec))
                        .then(function (o) { return { section: sec, options: o.options || [] }; })
                        .catch(function (e) { return { section: sec, options: null, error: e.message }; });
                })).then(function (quotas) {
                    renderVerdict(versionId, verdict, { days: days, quotas: quotas });
                });
            })
            .catch(function (e) {
                renderVerdict(versionId, verdict, { error: e.message });
            });
    }

    function renderVerdict(versionId, payload, extra) {
        var card = document.getElementById('tt_verdict_card');
        card.style.display = '';
        document.getElementById('tt_verdict_version').textContent = labelFor(versionId);

        var v = payload.verdict || payload;
        var hard = v.hard || [], soft = v.soft || [];

        document.getElementById('tt_verdict_pills').innerHTML =
            '<span class="ac-badge ' + (hard.length ? 'ac-badge-hol' : 'ac-badge-done') + '">' +
            (hard.length ? hard.length + ' must be fixed' : 'Nothing blocking') + '</span>' +
            '<span class="ac-badge ac-tt-badge-soft">' + esc(soft.length) + ' to consider</span>';

        var html = '';

        // An untrusted verdict must say so before anything else — the numbers
        // above describe an arrangement that may no longer exist.
        if (payload.trusted === false && payload.reason) {
            html += '<div class="u-mb-4 u-text-warn"><i class="fa fa-exclamation-triangle"></i> ' +
                    esc(payload.reason) + '</div>';
        }

        if (extra && extra.error) {
            html += '<div class="u-mb-4 u-text-warn"><i class="fa fa-exclamation-triangle"></i> ' +
                    esc(extra.error) + ' \u2014 the curriculum and teaching-load ' +
                    'summary could not be built. The findings below are unaffected.</div>';
        } else if (extra && extra.days) {
            // Learn the names HERE rather than relying on the caller having
            // done it, or the load table falls back to staff IDs.
            learnTeachers(extra.days);
            var t = tally(extra.days);
            html += renderCurriculum(t, extra.quotas || []) + renderLoad(t);
        }

        if (hard.length) {
            html += '<h3 class="ac-tt-findtitle">Must be fixed before publishing</h3>' +
                    '<div class="ac-tt-finds u-mb-4">' + renderFindings(hard, 'hard') + '</div>';
        }
        if (soft.length) {
            html += '<h3 class="ac-tt-findtitle">Worth a look</h3>' +
                    '<div class="ac-tt-finds u-mb-4">' + renderFindings(soft, 'soft') + '</div>';
        }
        if (!hard.length && !soft.length) {
            html += '<p class="u-text-secondary">No problems found.</p>';
        }

        // Why there is no single score. Stated on the page, not hidden in code.
        if (v.scoreReason) {
            html += '<p class="u-text-tertiary u-text-sm u-mt-4">' + esc(v.scoreReason) + '</p>';
        }
        document.getElementById('tt_verdict_body').innerHTML = html;
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── the timetable itself ─────────────────────────────────────────────

    var EDIT = { versionId: null, editable: false, status: '', days: [], options: [] };

    /**
     * Cells changed but not yet saved, keyed "day|periodIndex".
     *
     * Only these are ever sent. The whole week is deliberately NOT posted back:
     * the browser's copy may be minutes old, and writing it over what is stored
     * would silently revert anyone else's changes — the ACAD-D21 shape the
     * legacy whole-week save had.
     */
    var DIRTY = {};

    var DAY_ORDER = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    function dayOf(sectionKey, day) {
        for (var i = 0; i < EDIT.days.length; i++) {
            var d = EDIT.days[i];
            if (d.classSection === sectionKey && d.day === day) { return d; }
        }
        return null;
    }

    function sectionsIn(days) {
        var seen = {}, out = [];
        days.forEach(function (d) {
            if (d.classSection && !seen[d.classSection]) { seen[d.classSection] = 1; out.push(d.classSection); }
        });
        return out.sort();
    }

    function daysIn(days) {
        var seen = {}, out = [];
        days.forEach(function (d) { if (d.day && !seen[d.day]) { seen[d.day] = 1; out.push(d.day); } });
        return out.sort(function (a, b) { return DAY_ORDER.indexOf(a) - DAY_ORDER.indexOf(b); });
    }

    /** How many periods the school runs, taken from the timetable itself. */
    function periodCount(sectionKey) {
        var n = 0;
        EDIT.days.forEach(function (d) {
            if (d.classSection !== sectionKey) { return; }
            var p = (d.periods || []).length;
            if (p > n) { n = p; }
        });
        return n;
    }

    function refreshDirtyNote() {
        var n    = Object.keys(DIRTY).length;
        var btn  = document.getElementById('tt_save_all');
        var note = document.getElementById('tt_dirty_note');
        if (btn)  { btn.disabled = (n === 0); }
        if (note) {
            note.textContent = n === 0
                ? 'No unsaved changes.'
                : n + (n === 1 ? ' unsaved change.' : ' unsaved changes.');
        }
    }

    function openEditor(versionId) {
        EDIT.versionId = versionId;
        DIRTY = {};
        var card = document.getElementById('tt_editor_card');
        card.style.display = '';
        document.getElementById('tt_editor_grid').innerHTML =
            '<div class="u-text-tertiary">Loading…</div>';

        return api('timetable/days?version_id=' + encodeURIComponent(versionId))
            .then(function (d) {
                EDIT.days     = d.days || [];
                // afterPeriod => what this school calls that break.
                EDIT.breaks   = d.breaks || {};
                learnTeachers(EDIT.days);
                EDIT.editable = d.editable === true;
                EDIT.status   = d.status || '';

                // The operator must be able to see WHICH timetable they are
                // looking at. A published one is shown read-only rather than
                // letting them try and be refused cell by cell.
                document.getElementById('tt_editor_title').textContent =
                    EDIT.editable ? 'Edit timetable' : 'Timetable';
                document.getElementById('tt_editor_context').innerHTML =
                    '<strong>' + esc(labelFor(versionId)) + '</strong>' +
                    '<span class="ac-badge ' +
                        (EDIT.editable ? 'ac-badge-ip' : 'ac-badge-done') + '">' +
                        esc(statusLabel(EDIT.status)) + '</span>' +
                    (EDIT.editable
                        ? '<span class="u-text-tertiary u-text-sm">Changes here are not live</span>'
                        : '<span class="u-text-tertiary u-text-sm">Published — read only</span>');

                var secSel   = document.getElementById('tt_editor_section');
                var covered  = sectionsIn(EDIT.days);
                secSel.innerHTML = covered.map(function (s) {
                    return '<option value="' + esc(s) + '">' + esc(s) + '</option>';
                }).join('');

                // Say what this timetable covers. The dropdown lists the sections
                // this VERSION stores, not the sections the school has, and a
                // class absent from it looks like a missing option rather than a
                // class that was never timetabled.
                var cov = document.getElementById('tt_editor_coverage');
                if (cov) {
                    cov.innerHTML = covered.length
                        ? 'This timetable covers ' + covered.length + ' class-section' +
                          (covered.length === 1 ? '' : 's') + '. A class that is not ' +
                          'listed was not part of it when it was built — to include one, ' +
                          'build a new timetable from the Versions list above.'
                        : '';
                }

                if (!EDIT.days.length) {
                    document.getElementById('tt_editor_grid').innerHTML =
                        '<div class="ac-empty"><div class="ac-tt-emptyicon">' +
                        '<i class="fa fa-table"></i></div>' +
                        '<h3 class="ac-tt-emptytitle">This timetable has no days yet</h3>' +
                        '<p class="ac-tt-emptymsg">Generate into it, or create it from an ' +
                        'existing timetable, and the week will appear here.</p></div>';
                    return;
                }
                return loadOptionsThenGrid();
            })
            .catch(function (e) {
                // A failed load is a FAILURE, never an empty timetable: an empty
                // week would invite the operator to fill in cells over data they
                // simply could not see.
                document.getElementById('tt_editor_grid').innerHTML =
                    '<div class="u-text-danger"><i class="fa fa-exclamation-triangle"></i> ' +
                    esc(e.message) + ' — nothing is shown because nothing could be read.</div>';
            });
    }

    function loadOptionsThenGrid() {
        var sectionKey = document.getElementById('tt_editor_section').value;
        if (!sectionKey) { return Promise.resolve(); }
        return api('timetable/editor_options?class_section=' + encodeURIComponent(sectionKey))
            .then(function (d) {
                EDIT.options = d.options || [];
                learnTeachers(EDIT.options);
                renderWeek();
            })
            .catch(function (e) {
                EDIT.options = [];
                renderWeek(esc(e.message));
            });
    }

    /** The value a cell currently holds, as "subject|teacherId". */
    function cellKey(p) {
        return (p && p.subject ? p.subject : '') + '|' + (p && p.teacherId ? p.teacherId : '');
    }

    function cellSelect(day, idx, p) {
        var current = cellKey(p);
        var opts = '<option value="">— free —</option>';
        var known = false;
        EDIT.options.forEach(function (o) {
            var val = o.subject + '|' + o.teacherId;
            if (val === current) { known = true; }
            opts += '<option value="' + esc(val) + '"' +
                    (val === current ? ' selected' : '') + '>' +
                    esc(o.subject) + ' — ' + esc(o.teacher) + '</option>';
        });
        // Never silently drop what is stored just because no assignment backs it
        // any more; show it and mark it.
        if (!known && (p.subject || p.teacherId)) {
            opts += '<option value="' + esc(current) + '" selected>' +
                    esc(p.subject || '(no subject)') + ' — ' +
                    esc(p.teacher || p.teacherId) + ' (not assigned)</option>';
        }
        return '<select class="ac-tt-cell" data-day="' + esc(day) +
               '" data-cell="' + idx + '">' + opts + '</select>';
    }

    function cellRead(p) {
        if (!p || !p.subject) {
            return '<span class="ac-tt-free">—</span>';
        }
        return '<span class="ac-tt-subj">' + esc(p.subject) + '</span>' +
               '<span class="ac-tt-teacher">' + esc(p.teacher || p.teacherId) + '</span>';
    }

    /**
     * THE TIMETABLE: periods down, days across, for one class-section.
     *
     * This is what a school means by a timetable, and what the earlier screen
     * was missing — a list of findings and a one-day dropdown column are both
     * true and neither is readable as a week.
     */
    function renderWeek(optionWarning) {
        var sectionKey = document.getElementById('tt_editor_section').value;
        var days       = daysIn(EDIT.days.filter(function (d) { return d.classSection === sectionKey; }));
        var grid       = document.getElementById('tt_editor_grid');

        if (!days.length) {
            grid.innerHTML = '<div class="u-text-tertiary">Nothing is stored for ' +
                esc(sectionKey) + ' yet.</div>';
            return;
        }

        var periods = periodCount(sectionKey);
        var html = '';

        if (optionWarning) {
            html += '<div class="u-text-warn u-mb-3"><i class="fa fa-exclamation-triangle"></i> ' +
                    optionWarning + ' You can still edit, but the suggested ' +
                    'subject and teacher list is unavailable.</div>';
        }

        html += '<div class="ac-tt-tablewrap"><table class="ac-table ac-tt-week"><thead><tr>' +
                '<th class="ac-tt-pcol">Period</th>';
        days.forEach(function (d) { html += '<th>' + esc(d) + '</th>'; });
        html += '</tr></thead><tbody>';

        for (var i = 0; i < periods; i++) {
            html += '<tr><td class="ac-tt-pcol"><strong>' + (i + 1) + '</strong>';
            // The time comes from the slot, so any day that has it will do.
            var t = null;
            days.forEach(function (d) {
                var doc = dayOf(sectionKey, d);
                var p = doc && doc.periods ? doc.periods[i] : null;
                if (!t && p && p.startTime) { t = p; }
            });
            if (t) {
                html += '<div class="ac-tt-time">' + esc(t.startTime) + '<br>' +
                        esc(t.endTime) + '</div>';
            }
            html += '</td>';

            days.forEach(function (d) {
                var doc = dayOf(sectionKey, d);
                var p = (doc && doc.periods) ? (doc.periods[i] || {}) : {};
                html += '<td class="ac-tt-cellwrap">' +
                        (EDIT.editable ? cellSelect(d, i, p) : cellRead(p)) + '</td>';
            });
            html += '</tr>';

            // A break sits BETWEEN two periods. Take the times from any day
            // that has them -- a recess applies to the whole week, so the first
            // day that can answer is as good as any.
            var nextT = null;
            days.forEach(function (d) {
                var doc = dayOf(sectionKey, d);
                var np = doc && doc.periods ? doc.periods[i + 1] : null;
                if (!nextT && np && np.startTime) { nextT = np; }
            });
            var gap = breakAfter(t, nextT);
            if (gap > 0) {
                html += '<tr class="ac-tt-break"><td colspan="' + (days.length + 1) + '">' +
                        '<span class="ac-tt-breakname">' + esc(breakName(i + 1)) +
                        '</span> \u00b7 ' +
                        esc(t.endTime) + ' \u2013 ' + esc(nextT.startTime) +
                        ' \u00b7 ' + gap + ' min</td></tr>';
            }
        }
        html += '</tbody></table></div>';

        if (EDIT.editable) {
            html += '<div class="u-flex u-gap-3 u-items-center u-mt-3">' +
                    '<button type="button" class="ac-btn ac-btn-p ac-btn-sm" ' +
                    'id="tt_save_all" disabled>' +
                    '<span class="ac-tt-btnlabel">Save changes</span>' +
                    '</button>' +
                    '<span class="u-text-tertiary u-text-sm" id="tt_dirty_note">' +
                    'No unsaved changes.</span></div>' +
                    '<p class="u-text-tertiary u-text-sm u-mt-3">Saving marks this ' +
                    'timetable as needing another check before it can be published.</p>';
        }

        grid.innerHTML = html;
        DIRTY = {};
        refreshDirtyNote();
    }

    function cellValue(sel) {
        var parts   = String(sel.value || '').split('|');
        var subject = parts[0] || '';
        var tid     = parts[1] || '';
        var teacher = '';
        EDIT.options.forEach(function (o) {
            if (o.subject === subject && o.teacherId === tid) { teacher = o.teacher; }
        });
        return { subject: subject, teacherId: tid, teacher: teacher };
    }

    /**
     * Save the changed cells, one request per DAY.
     *
     * The store replaces a day at a time and re-reads it server-side, so each
     * request carries only the cells of that day the operator actually changed.
     * A week with edits on two days is two requests, not one big overwrite.
     */
    function saveAll(btn) {
        var keys = Object.keys(DIRTY);
        if (!keys.length) { return; }

        var sectionKey = document.getElementById('tt_editor_section').value;

        var byDay = {};
        keys.forEach(function (k) {
            var bits = k.split('|');
            var day  = bits[0], idx = bits[1];
            if (!byDay[day]) { byDay[day] = {}; }
            byDay[day][idx] = DIRTY[k];
        });

        Btn.start(btn);
        var days = Object.keys(byDay);
        Promise.all(days.map(function (day) {
            return api('timetable/save_day', {
                version_id:    EDIT.versionId,
                class_section: sectionKey,
                day:           day,
                cells:         JSON.stringify(byDay[day])
            }).then(function (d) {
                var doc = dayOf(sectionKey, day);
                if (doc && d.day && d.day.periods) { doc.periods = d.day.periods; }
            });
        })).then(function () {
            toast('Saved ' + keys.length + ' change' + (keys.length === 1 ? '' : 's') +
                  ' across ' + days.length + ' day' + (days.length === 1 ? '' : 's') +
                  '. Check the timetable again before publishing.', 'success');
            renderWeek();
            load();
        }).catch(function (e) { toast(e.message, 'error'); })
          .finally(function () { Btn.stop(btn); });
    }
    // ── loading ──────────────────────────────────────────────────────────

    /* ── COMPARING CANDIDATES ────────────────────────────────────────────
       One row per proposal, one column per FACT. There is deliberately no
       score column and no "best" badge: which timetable is best depends on
       priorities the school has not been asked for, and inventing them here
       would be exactly the hidden policy this work removed from the engine.

       Instead every column sorts. An administrator who cares most about
       teacher load sorts by teacher load; one who cares about curriculum
       sorts by shortfall. That is a ranking the school chose, made from
       numbers it can check. */

    var CMP_SORT = { key: 'hard', dir: 1 };

    /* Each column: how to read it, and which direction is BETTER. `better`
       exists only to colour the best and worst cell in a column -- it never
       adds numbers together. */
    var CMP_COLS = [
        { key: 'name',     label: 'Timetable',        num: false },
        { key: 'hard',     label: 'Blocking',         num: true,  better: 'low' },
        { key: 'short',    label: 'Curriculum short', num: true,  better: 'low' },
        { key: 'interior', label: 'Gaps mid-day',     num: true,  better: 'low' },
        { key: 'trailing', label: 'Free at day end',  num: true,  better: null },
        { key: 'spread',   label: 'Load spread',      num: true,  better: 'low' },
        { key: 'stretch',  label: 'Longest run',      num: true,  better: 'low' },
        { key: 'diff',     label: 'Differs by',       num: true,  better: 'high' }
    ];

    /**
     * The comparable facts of one version.
     *
     * A proposal is ALWAYS returned, even when nothing about it has been
     * measured. Leaving it out would mean an administrator who built three
     * timetables saw two rows and had no idea the third existed — a silently
     * missing option is worse than an option with blanks in it.
     */
    function cmpRow(v) {
        var f     = v.facts || null;
        var m     = (f && f.metrics) || null;
        var val   = v.validation || {};
        var d     = (f && f.distinctness) || {};

        // A run that failed or has not finished is not a timetable anybody can
        // choose. It was previously shown as merely "not measured", which reads
        // as a usable option nobody got round to checking.
        var genState = String((v.generation || {}).state || '');
        // Measurements describe the timetable as it was when they were taken.
        // Once it has been edited by hand they describe something that no longer
        // exists, and showing them beside current numbers would mislead.
        var stale = v.factsStale === true;

        return {
            versionId: v.versionId,
            status:    String(v.status || ''),
            name:      labelFor(v.versionId),
            genState:  genState,
            usable:    genState !== 'failed' && genState !== 'running',
            measured:  !!m,
            stale:     stale,
            hard:      val.hardCount === undefined ? null : val.hardCount,
            hardStale: v.validationStale === true,
            short:     (m && m.shortfall && m.shortfall.known) ? m.shortfall.total : null,
            interior:  m ? m.freeInterior : null,
            trailing:  m ? m.freeTrailing : null,
            spread:    (m && m.teacherLoad) ? m.teacherLoad.spread : null,
            stretch:   m ? m.longestStretch : null,
            diff:      (d.ratio === null || d.ratio === undefined) ? null : d.ratio,
            diffCells: d.differing,
            cells:     d.cells
        };
    }

    /** Every proposal, measured or not. Published versions are not proposals. */
    function cmpRows() {
        var out = [];
        (VERSIONS || []).forEach(function (v) {
            var st = String(v.status || '');
            if (st !== 'draft' && st !== 'candidate') { return; }
            out.push(cmpRow(v));
        });
        return out;
    }

    function fmtCell(col, r) {
        var x = r[col.key];

        if (col.key === 'name') {
            var html = esc(r.name);
            if (r.genState === 'failed') {
                html += ' <span class="ac-badge ac-badge-hol">did not finish</span>';
            } else if (r.genState === 'running') {
                html += ' <span class="ac-badge ac-badge-ip">still building</span>';
            } else if (!r.measured) {
                html += ' <span class="ac-badge ac-tt-badge-soft">not measured</span>';
            } else if (r.stale) {
                html += ' <span class="ac-badge ac-tt-badge-soft">edited — re-check</span>';
            }
            return html;
        }

        if (!r.usable) {
            return '<span class="u-text-tertiary">' +
                   (r.genState === 'running' ? 'building…' : 'unavailable') + '</span>';
        }

        // Numbers taken before a hand edit do not describe this timetable any
        // more. Showing them next to current ones would invite a comparison
        // between a timetable and a memory of one.
        if (r.stale && col.key !== 'hard') {
            return '<span class="u-text-tertiary">needs re-check</span>';
        }
        if (col.key === 'hard' && r.hardStale) {
            return '<span class="u-text-tertiary">needs re-check</span>';
        }

        // A fact that was never measured is said to be unknown, never shown as
        // a zero that would read as "nothing wrong".
        if (x === null || x === undefined) { return '<span class="u-text-tertiary">not measured</span>'; }
        if (col.key === 'diff') {
            return Math.round(x * 100) + '% <span class="u-text-tertiary">(' +
                   esc(r.diffCells) + ' of ' + esc(r.cells) + ')</span>';
        }
        return esc(x);
    }

    /**
     * How much room is left before timetables stop resolving.
     *
     * Past the scan limit the store cannot prove which timetable is in force and
     * correctly refuses to resolve ANY of them. Generation already refuses to
     * cross that line, but a refusal arriving without warning is a surprise —
     * so the count is shown from the first proposal onwards, and the warning
     * appears at the point where a full batch would no longer fit.
     *
     * That threshold is derived, not chosen: it is exactly the moment the
     * largest generation this school can request stops being possible.
     */
    function renderHeadroom(d) {
        var el = document.getElementById('tt_version_count');
        if (!el) { return; }

        var used  = Number(d.versionCount || 0);
        var limit = Number(d.versionLimit || 0);
        var batch = Number(d.batchMax || 0);

        if (!limit) {
            el.textContent = used + ' version' + (used === 1 ? '' : 's');
            el.className   = 'u-text-tertiary u-text-sm';
            return;
        }

        var tight = (used + batch) > limit;
        el.innerHTML = esc(used) + ' of ' + esc(limit) + ' versions' +
            (tight
                ? ' <span class="ac-badge ac-badge-hol">running out of room</span>'
                : '');
        el.className = 'u-text-tertiary u-text-sm';
        el.title = tight
            ? 'There is no longer room to build ' + batch + ' timetables at once. '
              + 'Past ' + limit + ' versions no timetable can be resolved at all — '
              + 'discard proposals you no longer need.'
            : 'Each proposal and each published timetable is a version.';
    }

    /* ── FREEING UP ROOM ─────────────────────────────────────────────────
       Past the version ceiling no timetable resolves at all, and the only way
       back is removing proposals. This lists what CAN go and what cannot, with
       the reason for each — so the decision is made against reasons rather
       than a list of identifiers.

       Nothing here runs on a timer and nothing is pre-selected. How long a
       school keeps its proposals is a business question with no answer in the
       architecture, so the operator chooses every time. */

    var RECLAIM_PICK = {};

    function openCapacity() {
        var card = document.getElementById('tt_capacity_card');
        var note = document.getElementById('tt_capacity_note');
        card.style.display = '';
        note.textContent = 'Checking…';
        document.getElementById('tt_capacity_tbl').innerHTML = '';
        RECLAIM_PICK = {};

        return api('timetable/capacity')
            .then(function (d) { renderCapacity(d); })
            .catch(function (e) {
                note.innerHTML = '<span class="u-text-danger">' + esc(e.message) +
                    ' — nothing is listed, because a list that might include a ' +
                    'timetable in use is worse than no list.</span>';
            });
    }

    function renderCapacity(d) {
        var cap  = d.capacity || {};
        var rows = d.rows || [];
        var note = document.getElementById('tt_capacity_note');

        note.innerHTML =
            '<strong>' + esc(cap.used) + ' of ' + esc(cap.limit) + ' versions used.</strong> ' +
            esc(cap.protected) + ' cannot be removed and ' + esc(cap.reclaimable) +
            ' could be. Past ' + esc(cap.limit) + ' no timetable can be resolved at all, ' +
            'so the room matters more than the tidiness.' +
            (cap.failed
                ? ' ' + esc(cap.failed) + ' of them never finished building and can ' +
                  'never be used.'
                : '');

        var html = '<thead><tr><th style="width:34px"></th><th>Timetable</th>' +
                   '<th>Status</th><th>Why</th></tr></thead><tbody>';

        rows.forEach(function (r) {
            var can = r.class === 'reclaimable';
            html += '<tr>' +
                '<td>' + (can && CAN_MANAGE
                    ? '<input type="checkbox" data-reclaim="' + esc(r.versionId) + '"' +
                      (RECLAIM_PICK[r.versionId] ? ' checked' : '') + '>'
                    : '') + '</td>' +
                '<td><span class="ac-tt-cmpname">' + esc(labelFor(r.versionId)) + '</span></td>' +
                '<td><span class="ac-badge ' +
                    (can ? 'ac-tt-badge-soft' : 'ac-badge-done') + '">' +
                    esc(can ? 'can be removed' : 'kept') + '</span></td>' +
                '<td class="u-text-secondary">' + esc(r.why) +
                    // A build that never finished is the one state an operator
                    // cannot otherwise escape: it can never be checked, never
                    // published, and never reclaimed. Offer the way out here,
                    // where they are already looking at why it is stuck.
                    ((r.reason === 'generating' && CAN_MANAGE)
                        ? ' <button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                          'data-abandon="' + esc(r.versionId) + '">Mark as not finished</button>'
                        : '') +
                '</td>' +
                '</tr>';
        });

        document.getElementById('tt_capacity_tbl').innerHTML = html + '</tbody>';
        document.getElementById('tt_capacity_actions').style.display =
            (CAN_MANAGE && cap.reclaimable > 0) ? '' : 'none';
        refreshReclaimCount();
    }

    function refreshReclaimCount() {
        var n = Object.keys(RECLAIM_PICK).filter(function (k) { return RECLAIM_PICK[k]; }).length;
        var el = document.getElementById('tt_reclaim_count');
        if (el) { el.textContent = n ? n + ' selected' : 'Nothing selected'; }
        var btn = document.getElementById('tt_reclaim');
        if (btn) { btn.disabled = n === 0; }
    }

    function renderCompare() {
        var card = document.getElementById('tt_compare_card');
        var note = document.getElementById('tt_compare_note');
        var tbl  = document.getElementById('tt_compare_tbl');
        var rows = cmpRows();

        card.style.display = '';

        if (rows.length < 2) {
            note.textContent = rows.length === 1
                ? 'Only one proposal has been measured, so there is nothing to compare it with. Build a few at once to see alternatives side by side.'
                : 'No measured proposals yet. Build timetables with "New timetable" and they will appear here.';
            tbl.innerHTML = '';
            return;
        }

        note.innerHTML = 'Sorted by <strong>' + esc(
            (CMP_COLS.filter(function (c) { return c.key === CMP_SORT.key; })[0] || {}).label ||
            CMP_SORT.key) + '</strong>. Every column can be sorted \u2014 ZenX does not pick a ' +
            'winner, because which timetable is best depends on what your school ' +
            'values most, and that has not been set.';

        rows.sort(function (a, b) {
            // A row whose numbers cannot be trusted sorts last whichever way
            // the column points, so it never appears to win or lose.
            var aOk = a.usable && a.measured && !a.stale;
            var bOk = b.usable && b.measured && !b.stale;
            if (aOk !== bOk) { return aOk ? -1 : 1; }
            var x = a[CMP_SORT.key], y = b[CMP_SORT.key];
            if (x === null || x === undefined) { return 1; }
            if (y === null || y === undefined) { return -1; }
            if (typeof x === 'string') { return CMP_SORT.dir * x.localeCompare(y); }
            return CMP_SORT.dir * (x - y);
        });

        // Best and worst per column, for a glance. Colour only; no arithmetic.
        var edge = {};
        CMP_COLS.forEach(function (c) {
            if (!c.better) { return; }
            var vals = rows.filter(function (r) { return r.usable && r.measured && !r.stale; })
                           .map(function (r) { return r[c.key]; })
                           .filter(function (v) { return typeof v === 'number'; });
            if (vals.length < 2) { return; }
            var lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
            if (lo === hi) { return; }
            edge[c.key] = c.better === 'low' ? { good: lo, bad: hi } : { good: hi, bad: lo };
        });

        var html = '<thead><tr>';
        CMP_COLS.forEach(function (c) {
            var active = CMP_SORT.key === c.key;
            html += '<th class="' + (c.num ? 'n' : '') + '" data-cmp-sort="' + esc(c.key) + '"' +
                    (active ? ' aria-sort="' + (CMP_SORT.dir === 1 ? 'ascending' : 'descending') + '"' : '') +
                    '>' + esc(c.label) +
                    (active ? '<span class="ar">' + (CMP_SORT.dir === 1 ? '\u25B2' : '\u25BC') + '</span>' : '') +
                    '</th>';
        });
        html += '<th></th></tr></thead><tbody>';

        rows.forEach(function (r) {
            html += '<tr>';
            CMP_COLS.forEach(function (c) {
                var cls = c.num ? 'n' : '';
                var e = edge[c.key];
                if (e && r.usable && r.measured && !r.stale && typeof r[c.key] === 'number') {
                    if (r[c.key] === e.good) { cls += ' ac-tt-best'; }
                    else if (r[c.key] === e.bad) { cls += ' ac-tt-worst'; }
                }
                html += '<td class="' + cls.trim() + '">' +
                        (c.key === 'name' ? '<span class="ac-tt-cmpname">' + fmtCell(c, r) + '</span>'
                                          : fmtCell(c, r)) + '</td>';
            });
            html += '<td class="n"><button type="button" class="ac-btn ac-btn-s ac-btn-sm" ' +
                    'data-edit="' + esc(r.versionId) + '">View</button>';
            if (CAN_MANAGE) {
                html += ' <button type="button" class="ac-btn ac-btn-p ac-btn-sm" ' +
                        'data-publish="' + esc(r.versionId) + '">Publish</button>';
            }
            html += '</td></tr>';
        });

        tbl.innerHTML = html + '</tbody>';
    }

    /**
     * Remove the chosen proposals.
     *
     * A dry run goes first and its answer is what the confirmation quotes, so
     * the operator is told what the SERVER will actually do rather than what
     * the screen believed a moment ago — a version can have been published
     * since the list was drawn.
     */
    /**
     * Give up on a build that never finished.
     *
     * Warned rather than refused: if the build is somehow still alive, removing
     * the version afterwards could leave days behind it. The operator is the
     * one who can tell, so they are told what they are deciding.
     */
    function doAbandon(btn) {
        var id = btn.getAttribute('data-abandon');

        confirmModal({
            title: 'Mark this timetable as not finished?',
            body: 'Only do this if it has clearly stopped building. It can then be '
                + 'removed. If it is somehow still running, removing it afterwards '
                + 'may leave part of it behind.',
            confirmText: 'Mark as not finished',
            dangerous: true
        }).then(function (ok) {
            if (!ok) { return; }

            Btn.start(btn);
            api('timetable/abandon', { version_id: id })
                .then(function () {
                    toast('Marked as not finished. It can now be removed.', 'success');
                    return load().then(openCapacity);
                })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { Btn.stop(btn); });
        });
    }

    function doReclaim(btn) {
        var ids = Object.keys(RECLAIM_PICK).filter(function (k) { return RECLAIM_PICK[k]; });
        if (!ids.length) { return; }

        Btn.start(btn);
        api('timetable/reclaim', { version_ids: ids.join(','), apply: '0' })
            .then(function (dry) {
                var n = (dry.removed || []).length;
                if (!n) {
                    toast('None of those can be removed. ' +
                          ((dry.refused || [])[0] || {}).reason || '', 'error');
                    return null;
                }
                return confirmModal({
                    title: 'Remove ' + n + ' timetable(s)?',
                    body: 'They have never been published, so nothing teachers can '
                        + 'see will change. This cannot be undone.',
                    confirmText: 'Remove',
                    dangerous: true
                }).then(function (ok) {
                    if (!ok) { return null; }

                    return api('timetable/reclaim', { version_ids: ids.join(','), apply: '1' })
                    .then(function (d) {
                        var msg = 'Removed ' + (d.removed || []).length + ' timetable(s)';
                        if (d.daysFreed) { msg += ' and ' + d.daysFreed + ' day(s)'; }
                        msg += '.';
                        if ((d.refused || []).length) {
                            msg += '\n\n' + d.refused.length + ' were kept: ' +
                                   d.refused[0].reason;
                        }
                        if ((d.failed || []).length) {
                            msg += '\n\n' + d.failed.length + ' could not be removed and '
                                 + 'are still there.';
                        }
                        toast(msg, (d.refused || []).length || (d.failed || []).length
                            ? 'info' : 'success');
                        RECLAIM_PICK = {};
                        return load().then(openCapacity);
                    });
                });
            })
            .catch(function (e) { toast(e.message, 'error'); })
            .finally(function () { Btn.stop(btn); });
    }

    function load() {
        var date = document.getElementById('tt_date').value || '';
        return api('timetable/versions?date=' + encodeURIComponent(date))
            .then(function (d) {
                renderResolution(d.resolution || {});
                VERSIONS = d.versions || [];
                renderVersions(VERSIONS);
                renderHeadroom(d);
                if (document.getElementById('tt_compare_card').style.display !== 'none') {
                    renderCompare();
                }
            })
            .catch(function (e) {
                fail('tt_resolution', e);
                document.getElementById('tt_versions').innerHTML =
                    '<tr><td colspan="7" class="u-text-danger">' +
                    esc(e.message) + '</td></tr>';
            });
    }

    // ── actions ──────────────────────────────────────────────────────────

    document.addEventListener('click', function (ev) {
        // Comparison controls first: a sortable column header is a <th>, not a
        // button, so it is matched separately before the row-action lookup.
        var th = ev.target.closest('[data-cmp-sort]');
        if (th) {
            var k = th.getAttribute('data-cmp-sort');
            if (CMP_SORT.key === k) { CMP_SORT.dir = -CMP_SORT.dir; }
            else { CMP_SORT.key = k; CMP_SORT.dir = 1; }
            renderCompare();
            return;
        }
        if (ev.target.closest('#tt_compare')) { renderCompare(); return; }
        if (ev.target.closest('#tt_capacity')) { openCapacity(); return; }
        if (ev.target.closest('#tt_capacity_close')) {
            document.getElementById('tt_capacity_card').style.display = 'none';
            return;
        }
        var pick = ev.target.closest('[data-reclaim]');
        if (pick) {
            RECLAIM_PICK[pick.getAttribute('data-reclaim')] = pick.checked;
            refreshReclaimCount();
            return;
        }
        var ab = ev.target.closest('[data-abandon]');
        if (ab) { doAbandon(ab); return; }
        if (ev.target.closest('#tt_reclaim')) {
            doReclaim(ev.target.closest('#tt_reclaim'));
            return;
        }
        if (ev.target.closest('#tt_compare_close')) {
            document.getElementById('tt_compare_card').style.display = 'none';
            return;
        }

        var btn = ev.target.closest('[data-verdict],[data-validate],[data-publish],[data-change],[data-edit],[data-discard],[data-rename],[data-supersede],[data-pdf],#tt_save_all');
        if (!btn) { return; }

        if (btn.dataset.edit) {
            Btn.start(btn);
            openEditor(btn.dataset.edit).finally(function () { Btn.stop(btn); });
            document.getElementById('tt_editor_card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }


        if (btn.id === 'tt_save_all') {
            saveAll(btn);
            return;
        }

        if (btn.dataset.pdf) {
            // A download, not a fetch: the response is a file, so it goes to a
            // new tab rather than through api(), which expects JSON.
            window.open(BASE + 'timetable/pdf?version_id=' +
                        encodeURIComponent(btn.dataset.pdf), '_blank');
            return;
        }

        if (btn.dataset.supersede) {
            document.getElementById('tt_supersede_version').value = btn.dataset.supersede;
            document.getElementById('tt_supersede_label').textContent =
                labelFor(btn.dataset.supersede);
            document.getElementById('tt_supersede_reason').value = '';
            Modal.open(document.getElementById('tt_supersede_modal'));
            return;
        }

        if (btn.dataset.rename) {
            document.getElementById('tt_rename_version').value = btn.dataset.rename;
            // Seed with the name the school gave it, NOT the generated label —
            // otherwise the first rename silently adopts "Proposal 2 · built …"
            // as a real name.
            document.getElementById('tt_rename_label').value =
                VERSION_OWN[btn.dataset.rename] || '';
            Modal.open(document.getElementById('tt_rename_modal'));
            return;
        }

        if (btn.dataset.verdict) {
            Btn.start(btn);
            var rvid = btn.dataset.verdict;
            api('timetable/verdict?version_id=' + encodeURIComponent(rvid))
                .then(function (d) { return showReport(rvid, d); })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { Btn.stop(btn); });
            return;
        }

        if (btn.dataset.validate) {
            Btn.start(btn);
            var cvid = btn.dataset.validate;
            api('timetable/validate_candidate', { version_id: cvid })
                .then(function (d) { load(); return showReport(cvid, d); })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { Btn.stop(btn); });
            return;
        }

        if (btn.dataset.publish) {
            document.getElementById('tt_publish_version').value = btn.dataset.publish;
            document.getElementById('tt_publish_label').textContent =
                labelFor(btn.dataset.publish);
            Modal.open(document.getElementById('tt_publish_modal'));
            return;
        }

        if (btn.dataset.discard) {
            var label = labelFor(btn.dataset.discard);
            var discardId = btn.dataset.discard;

            confirmModal({
                title: 'Discard ' + label + '?',
                body: 'It has never been published, so nothing teachers can see '
                    + 'will change. This cannot be undone.',
                confirmText: 'Discard',
                dangerous: true
            }).then(function (ok) {
                if (!ok) { return; }

                Btn.start(btn);
                // The id is read BEFORE the await: this handler is bound to a row
                // that load() re-renders, so by the time the answer arrives the
                // button may belong to a different version — or to nothing.
                api('timetable/discard', { version_id: discardId })
                    .then(function (d) {
                        toast('Discarded ' + label + '.', 'success');
                        document.getElementById('tt_editor_card').style.display = 'none';
                        document.getElementById('tt_verdict_card').style.display = 'none';
                        load();
                    })
                    .catch(function (e) { toast(e.message, 'error'); })
                    .finally(function () { Btn.stop(btn); });
            });
            return;
        }

        if (btn.dataset.change) {
            Btn.start(btn);
            api('timetable/create_change_version', { source_version_id: btn.dataset.change })
                .then(function (d) {
                    toast('New draft created with ' + d.days_copied +
                          ' day(s) copied from ' + labelFor(btn.dataset.change) +
                          '. The published timetable was not changed.', 'success');
                    load();
                })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { Btn.stop(btn); });
        }
    });

    var genForm = document.getElementById('tt_generate_form');
    if (genForm) {
        genForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var sub = this.querySelector('button[type=submit]');
            Btn.start(sub);
            var picked = this.querySelector('input[name=tt_fill]:checked');
            var blank  = picked && picked.value === 'blank';

            // Both endpoints are named as literals rather than chosen inside
            // the call. A computed URL is invisible to the guard that proves
            // this screen only reaches the canonical architecture, and an
            // unseen endpoint is exactly what that guard exists to catch.
            var scope = { scope_class: document.getElementById('tt_scope_class').value || '' };
            var howMany = parseInt(document.getElementById('tt_count').value, 10) || 1;

            // Both endpoints are named as literals rather than chosen inside
            // the call. A computed URL is invisible to the guard that proves
            // this screen only reaches the canonical architecture, and an
            // unseen endpoint is exactly what that guard exists to catch.
            var call = blank
                ? api('timetable/create_blank', scope)
                : api('timetable/generate_candidates',
                      { scope_class: scope.scope_class, count: howMany });

            call.then(function (d) {
                Modal.close(document.getElementById('tt_generate_modal'));
                var n = (blank ? d.days : d.days_written);
                toast(blank
                    ? ('Blank timetable created for ' + d.sections + ' class(es). '
                       + 'Open it with Edit and fill it in.')
                    : ('New proposal created with ' + n + ' day(s). It is at the top '
                       + 'of the list — check it before publishing.'),
                    'success');
                load();
            }).catch(function (e) { toast(e.message, 'error'); })
              .finally(function () { Btn.stop(sub); });
        });
    }

    var renForm = document.getElementById('tt_rename_form');
    if (renForm) {
        renForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var sub = this.querySelector('button[type=submit]');
            Btn.start(sub);
            api('timetable/rename', {
                version_id: document.getElementById('tt_rename_version').value,
                label:      document.getElementById('tt_rename_label').value
            }).then(function (d) {
                Modal.close(document.getElementById('tt_rename_modal'));
                toast('Renamed to \u201c' + d.label + '\u201d.', 'success');
                load();
            }).catch(function (e) { toast(e.message, 'error'); })
              .finally(function () { Btn.stop(sub); });
        });
    }

    var supForm = document.getElementById('tt_supersede_form');
    if (supForm) {
        supForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var sub = this.querySelector('button[type=submit]');
            Btn.start(sub);
            api('timetable/supersede', {
                version_id:          document.getElementById('tt_supersede_version').value,
                last_effective_date: document.getElementById('tt_supersede_last').value,
                reason:              document.getElementById('tt_supersede_reason').value
            }).then(function () {
                Modal.close(document.getElementById('tt_supersede_modal'));
                toast('End date set. You can now publish a timetable that starts after it.',
                      'success');
                load();
            }).catch(function (e) { toast(e.message, 'error'); })
              .finally(function () { Btn.stop(sub); });
        });
    }

    var pubForm = document.getElementById('tt_publish_form');
    if (pubForm) {
        pubForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var sub = this.querySelector('button[type=submit]');
            Btn.start(sub);
            api('timetable/publish', {
                version_id:     document.getElementById('tt_publish_version').value,
                effective_from: document.getElementById('tt_effective_from').value,
                effective_to:   document.getElementById('tt_effective_to').value || ''
            }).then(function (d) {
                Modal.close(document.getElementById('tt_publish_modal'));
                // Built here, not taken from d.explain: that string names the
                // document id and an ISO date, which is what the cron log wants
                // and not what an administrator should be handed.
                var msg = 'Published. This timetable applies from ' +
                          fmtDate(d.effectiveFrom) +
                          (d.effectiveTo ? ' to ' + fmtDate(d.effectiveTo) : ' onwards') +
                          ' (' + d.dayCount + ' day(s)).';
                // A timetable that does not deliver the curriculum the school
                // asked for is publishable — that has not changed — but the
                // operator hears about it at the moment it goes live.
                if (d.shortfall && d.shortfall.known && d.shortfall.total > 0) {
                    msg += '\n\nIt is ' + d.shortfall.total + ' period(s) short of the '
                         + 'curriculum set in Subject Assignments';
                    var names = (d.shortfall.bySubject || [])
                        .map(function (r) { return r.subject; })
                        .filter(function (v, i, a) { return v && a.indexOf(v) === i; });
                    if (names.length) { msg += ' (' + names.join(', ') + ')'; }
                    msg += '. Those lessons will not be taught unless you publish a '
                         + 'corrected version.';
                }

                if (d.overlaps && d.overlaps.length) {
                    msg += '\n\nIt overlaps ' + d.overlaps.length + ' other published ' +
                           'timetable(s), so some dates now have more than one and it ' +
                           'is not clear which applies. Teachers will not see a change ' +
                           'on those dates until the overlap is resolved.';
                }
                var noteworthy = (d.overlaps && d.overlaps.length)
                    || (d.shortfall && d.shortfall.known && d.shortfall.total > 0);
                toast(msg, noteworthy ? 'info' : 'success');
                load();
            }).catch(function (e) { toast(e.message, 'error'); })
              .finally(function () { Btn.stop(sub); });
        });
    }

    // A changed dropdown is an unsaved change until it is sent.
    document.addEventListener('change', function (ev) {
        var sel = ev.target.closest('[data-cell][data-day]');
        if (!sel) { return; }
        DIRTY[sel.getAttribute('data-day') + '|' + sel.getAttribute('data-cell')] =
            cellValue(sel);
        refreshDirtyNote();
    });

    document.getElementById('tt_editor_section')
        .addEventListener('change', loadOptionsThenGrid);

    // Refresh gave no feedback at all: with no versions yet, a successful
    // refresh re-rendered the identical empty state and looked broken. It now
    // shows the spinner for as long as the request is actually in flight.
    document.getElementById('tt_refresh').addEventListener('click', function () {
        var b = this;
        Btn.start(b);
        load().finally(function () { Btn.stop(b); });
    });
    document.getElementById('tt_date').addEventListener('change', load);

    /**
     * Started by the tab, not by the page.
     *
     * This panel lives in a tab that may never be opened, so loading versions on
     * every visit to Academic Planner would be a request nobody asked for. The
     * tab handler calls init() the first time it is opened, the same way every
     * other pane on that page is lazy-loaded.
     */
    window.ZXTimetable = {
        _loaded: false,
        init: function () {
            if (this._loaded) { return; }
            this._loaded = true;
            document.getElementById('tt_date').value =
                new Date().toISOString().slice(0, 10);
            load();
        },
        reload: load
    };
})();
</script>
