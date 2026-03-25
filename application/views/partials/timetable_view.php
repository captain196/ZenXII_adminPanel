<div class="timetable-wrapper">
    <!-- SCROLL CONTAINER -->
    <div class="timetable-scroll">
        <div class="timetable-grid" id="timetableGrid">
            <!-- header + rows injected by JS -->
        </div>
    </div>

    <!-- FOOTER ACTIONS -->
    <div class="timetable-footer">
        <div id="timetableEditActions" class="hidden" style="gap:10px;">
            <button class="btn btn-outline-secondary" id="cancelTimetableEdit">
                <i class="fa fa-times"></i> Cancel
            </button>
            <button class="btn btn-success" id="saveTimetableEdit">
                <i class="fa fa-save"></i> Save
            </button>
        </div>
        <button class="btn btn-warning" id="editTimetableBtn">
            <i class="fa fa-pencil"></i> Edit Timetable
        </button>
    </div>
</div>

<div class="modal fade" id="subjectSelectModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered subject-dialog">
        <div class="modal-content subject-modal">

            <!-- HEADER -->
            <div class="modal-header subject-modal-header">

                <button type="button"
                    class="close subject-close"
                    data-dismiss="modal">&times;</button>

                <h4 class="modal-title">Subjects</h4>

                <!-- SEARCH BELOW TITLE -->
                <div class="subject-search-wrap" style="margin-top:12px;">
                    <input type="text"
                        class="form-control subject-search-input"
                        placeholder="Search subjects"
                        id="subjectSearch">
                    <i class="fa fa-search subject-search-icon"></i>
                </div>

            </div>

            <!-- BODY -->
            <div class="modal-body subject-modal-body">

                <!-- CLASS TITLE -->
                <h3 class="subject-section-title" id="classSubjectTitle"></h3>

                <!-- CLASS SUBJECTS -->
                <div class="subject-grid class-subjects"></div>

                <!-- ALL SUBJECTS -->
                <h3 class="subject-section-title mt-3">
    All Subjects
    <small class="text-muted" id="allSubjectCount"></small>
</h3>

                <div class="subject-grid all-subjects"></div>

            </div>

        </div>
    </div>
</div>






<style>
/* ══════════════════════════════════════════════════════
   TIMETABLE — Professional ERP Design
   Uses global theme vars from header.php so it responds
   to the day/night theme toggle automatically.
══════════════════════════════════════════════════════ */

/* ── Wrapper ── */
.timetable-wrapper {
    background: var(--bg2);
    border-radius: 14px;
    border: 1px solid var(--border);
    overflow: hidden;
}

/* ── Scroll container ── */
.timetable-scroll {
    width: 100%;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
}
.timetable-scroll::-webkit-scrollbar { height: 5px; }
.timetable-scroll::-webkit-scrollbar-track { background: transparent; }
.timetable-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

/* ── Grid ── */
.timetable-grid {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 600px;
    padding: 16px;
}

/* One row = day label + period cells */
.tt-row {
    display: grid;
    grid-template-columns: 100px repeat(auto-fill, minmax(110px, 1fr));
    gap: 4px;
    align-items: stretch;
}

/* ── Base cell ── */
.tt-cell {
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 6px;
    font-family: var(--font-b);
    transition: all var(--ease);
}

/* ── Header row ── */
.tt-head {
    position: sticky;
    top: 0;
    z-index: 20;
}
.tt-head .tt-cell { height: 42px; }

/* Day | Time corner cell */
.day-time-head { border-radius: 8px; overflow: hidden; }
.day-time-head span { height: 100%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; font-family: var(--font-b); letter-spacing: .4px; }
.day-label { background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%); color: #fff; }
.time-label { background: var(--bg3); color: var(--t3); border: 1px solid var(--border); }

/* Period time header cells */
.time-head {
    background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .3px;
}

/* ── Day column (left) ── */
.tt-cell.day {
    background: var(--bg3);
    color: var(--t1);
    font-weight: 700;
    font-size: 12px;
    border: 1px solid var(--border);
    border-left: 3px solid var(--gold);
}

/* ── Subject cells ── */
.tt-cell.subject {
    background: var(--bg3);
    color: var(--t2);
    border: 1px solid var(--border);
    font-size: 12px;
    font-weight: 500;
}
.tt-cell.subject:not(:empty) {
    background: var(--bg4, var(--bg3));
    color: var(--t1);
    font-weight: 600;
}

/* Edit mode */
.timetable-wrapper.edit-mode .subject {
    cursor: pointer;
    border: 1.5px dashed var(--gold);
    background: var(--gold-dim);
}
.timetable-wrapper.edit-mode .subject:hover {
    background: rgba(15,118,110,.22);
    border-color: var(--gold);
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(15,118,110,.2);
}

/* ── Break cells ── */
.time-head.break-head {
    background: var(--gold) !important;
    color: #fff !important;
    font-weight: 700;
}
.tt-cell.break-cell {
    background: var(--gold-dim) !important;
    color: var(--gold) !important;
    font-weight: 700;
    font-size: 11px;
    border: 1px solid rgba(15,118,110,.35) !important;
}

/* ── Footer buttons ── */
.timetable-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 16px;
    border-top: 1px solid var(--border);
    background: var(--bg);
}
/* When timetableEditActions is visible (Bootstrap removes .hidden) → flex */
.timetable-footer #timetableEditActions { display: flex; gap: 10px; }
.timetable-footer .btn {
    font-size: 13px; font-weight: 600; border-radius: 8px; padding: 7px 20px;
    display: inline-flex; align-items: center; gap: 6px; transition: all var(--ease);
    font-family: var(--font-b);
}
.timetable-footer .btn-warning {
    background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%);
    color: #fff; border: none; box-shadow: 0 2px 8px rgba(15,118,110,.3);
}
.timetable-footer .btn-warning:hover { opacity: .88; color: #fff; transform: translateY(-1px); }
.timetable-footer .btn-success {
    background: linear-gradient(135deg, #15803d 0%, #166534 100%);
    color: #fff; border: none; box-shadow: 0 2px 8px rgba(21,128,61,.28);
}
.timetable-footer .btn-success:hover { opacity: .88; color: #fff; transform: translateY(-1px); }
.timetable-footer .btn-outline-secondary {
    background: var(--bg3); border: 1px solid var(--border); color: var(--t2);
}
.timetable-footer .btn-outline-secondary:hover { background: var(--bg4, var(--bg3)); color: var(--t1); }

/* ── Responsive ── */
@media (max-width: 768px) {
    .timetable-grid { padding: 10px; min-width: 500px; }
    .tt-cell { height: 40px; font-size: 10px; }
    .tt-head .tt-cell { height: 36px; }
}

/* ══════════════════════════════════════════════════════
   SUBJECT MODAL
══════════════════════════════════════════════════════ */
.subject-dialog { max-width: 860px; }

.subject-modal {
    border-radius: 16px;
    border: 1px solid var(--border);
    background: var(--bg2);
    box-shadow: var(--sh);
}

.subject-modal-header {
    border-bottom: 1px solid var(--border);
    padding: 18px 24px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%);
    border-radius: 16px 16px 0 0;
    position: relative;
}
.subject-modal-header .modal-title {
    font-size: 18px; font-weight: 700; color: #fff; font-family: var(--font-b);
}

.subject-search-wrap {
    position: relative; width: 100%; margin-top: 12px;
}
.subject-search-input {
    width: 100%; height: 36px; border-radius: 20px;
    font-size: 13px; padding: 0 32px 0 14px;
    border: none; background: rgba(255,255,255,.9);
    color: #333; font-family: var(--font-b);
}
.subject-search-input:focus {
    outline: none; background: #fff;
    box-shadow: 0 0 0 3px rgba(255,255,255,.3);
}
.subject-search-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #888; }

.subject-close {
    position: absolute; right: 18px; top: 14px;
    font-size: 24px; color: rgba(255,255,255,.8); background: none; border: none; padding: 0; cursor: pointer;
}
.subject-close:hover { color: #fff; }

.subject-modal-body { padding: 20px 24px 24px; background: var(--bg2); border-radius: 0 0 16px 16px; }

.subject-section-title {
    font: 700 13px/1 var(--font-b);
    color: var(--t3); text-transform: uppercase; letter-spacing: .7px;
    margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--border);
}

.subject-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }

.subject-item {
    padding: 7px 14px; border-radius: 8px;
    border: 1.5px solid var(--border);
    background: var(--bg3);
    color: var(--t2); font: 500 13px/1 var(--font-b);
    cursor: pointer; transition: all var(--ease);
}
.subject-item:hover { background: var(--gold-dim); color: var(--gold); border-color: var(--gold); }
.subject-item.selected {
    background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%);
    border-color: var(--gold); color: #fff; font-weight: 700;
    box-shadow: 0 2px 8px rgba(15,118,110,.3);
}

@media (max-width: 576px) {
    .subject-modal-body { padding: 16px; }
    .subject-dialog { max-width: 100%; }
}
</style>
