<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<!--
    Phase 9 (2026-08-13) — SRNO re-admission confirm screen.

    Pure UI. All business logic (eligibility checks, atomic SRNO relink,
    APAAR carry-forward, previousStudentId write, audit trail) lives
    entirely in Readmission::confirm() — this page only collects the two
    required inputs plus an optional reason and submits to that existing,
    unmodified endpoint, then renders whatever it returns. The server-side
    Principal/Super Admin/School Super Admin role gate remains authoritative
    (Sis::readmission_ui() already re-checks it before this view is even
    reached; Readmission::confirm() checks it again independently).
-->
<style>
html { font-size: 16px !important; }
.rm-wrap { max-width: 640px; margin: 0 auto; padding: 24px 20px; }
.rm-hdr { margin-bottom: 20px; }
.rm-hdr h1 { margin: 0 0 4px; font-size: 1.2rem; color: var(--t1); font-family: var(--font-b); }
.rm-hdr p { margin: 0; color: var(--t3); font-size: .85rem; }
.rm-card { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
.rm-field { margin-bottom: 16px; }
.rm-field label { display: block; font-size: .82rem; font-weight: 600; color: var(--t2); margin-bottom: 6px; }
.rm-field input, .rm-field textarea {
    width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px;
    font-size: .9rem; color: var(--t1); background: var(--bg);
}
.rm-hint { font-size: .78rem; color: var(--t3); margin-top: 4px; }
.rm-actions { display: flex; gap: 10px; margin-top: 20px; }
.rm-btn { padding: 9px 18px; border-radius: 6px; border: none; font-size: .88rem; font-weight: 600; cursor: pointer; }
.rm-btn-primary { background: var(--gold, #BC5A3C); color: #fff; }
.rm-btn-primary:disabled { opacity: .55; cursor: not-allowed; }
.rm-btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--t2); }
.rm-result { margin-top: 16px; padding: 12px 14px; border-radius: 8px; font-size: .85rem; display: none; }
.rm-result.ok { background: #dcfce7; color: #166534; display: block; }
.rm-result.err { background: #fee2e2; color: #991b1b; display: block; }
.rm-result.warn { background: #fef9c3; color: #92400e; display: block; margin-top: 8px; }
</style>

<div class="content-wrapper">
    <div class="rm-wrap">
        <div class="rm-hdr">
            <h1><i class="fa fa-history"></i> Confirm Re-admission</h1>
            <p>Link a fresh admission record to a prior Student ID so the school's SRNO (and, if on
               file, the government APAAR ID) carries forward to the same person. Student ID itself is
               never changed by this action — the new admission keeps its own Student ID.</p>
        </div>

        <div class="rm-card">
            <div class="rm-field">
                <label for="rmNewId">New Student ID <span style="color:#dc2626;">*</span></label>
                <input type="text" id="rmNewId" placeholder="e.g. STU0047" autocomplete="off">
                <p class="rm-hint">The already-created admission record this re-admission link applies to.</p>
            </div>
            <div class="rm-field">
                <label for="rmOldId">Previous Student ID <span style="color:#dc2626;">*</span></label>
                <input type="text" id="rmOldId" placeholder="e.g. STU0001" autocomplete="off">
                <p class="rm-hint">The prior record for the SAME real-world student, in THIS school. Must
                    already be Withdrawn/Inactive with an SRNO on file — found via ordinary SIS search.</p>
            </div>
            <div class="rm-field">
                <label for="rmReason">Reason <span style="color:var(--t3);font-weight:400;">(optional)</span></label>
                <textarea id="rmReason" rows="2" placeholder="e.g. Family returned after relocation"></textarea>
            </div>
            <div class="rm-actions">
                <button type="button" class="rm-btn rm-btn-primary" id="rmSubmitBtn">Confirm Re-admission</button>
                <button type="button" class="rm-btn rm-btn-ghost" id="rmResetBtn">Reset</button>
            </div>
            <div class="rm-result" id="rmResult"></div>
            <div class="rm-result warn" id="rmApaarWarn" style="display:none;"></div>
        </div>
    </div>
</div>

<script>
(function () {
    var submitBtn = document.getElementById('rmSubmitBtn');
    var resetBtn  = document.getElementById('rmResetBtn');
    var resultEl  = document.getElementById('rmResult');
    var apaarWarnEl = document.getElementById('rmApaarWarn');

    function showResult(kind, msg) {
        resultEl.className = 'rm-result ' + kind;
        resultEl.textContent = msg;
    }

    resetBtn.addEventListener('click', function () {
        document.getElementById('rmNewId').value = '';
        document.getElementById('rmOldId').value = '';
        document.getElementById('rmReason').value = '';
        resultEl.className = 'rm-result';
        resultEl.textContent = '';
        apaarWarnEl.style.display = 'none';
    });

    submitBtn.addEventListener('click', function () {
        var newId  = (document.getElementById('rmNewId').value || '').trim();
        var oldId  = (document.getElementById('rmOldId').value || '').trim();
        var reason = (document.getElementById('rmReason').value || '').trim();

        if (!newId || !oldId) {
            showResult('err', 'Both the new and previous Student ID are required.');
            return;
        }

        submitBtn.disabled = true;
        var origText = submitBtn.textContent;
        submitBtn.textContent = 'Confirming…';
        apaarWarnEl.style.display = 'none';

        var payload = new URLSearchParams();
        payload.append(csrfName, csrfToken);
        payload.append('student_id', newId);
        payload.append('previous_student_id', oldId);
        if (reason) payload.append('reason', reason);

        fetch('<?= base_url("readmission/confirm") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: payload
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            submitBtn.disabled = false;
            submitBtn.textContent = origText;
            if (data && data.status === 'success') {
                // MY_Controller::json_success() merges fields at the top level
                // of the response object — not nested under a "data" key.
                // Phase 10.2 — surface the NEW Admission Number issued for
                // this re-admission event alongside the PRESERVED SRNO, so
                // the operator can see at a glance that one carried forward
                // and the other is deliberately new.
                var okMsg = 'Re-admission confirmed. SRNO ' + (data.srNo || '') + ' relinked from '
                    + (data.previousStudentId || oldId) + ' to ' + (data.newStudentId || newId) + '.';
                if (data.admissionNo) {
                    okMsg += ' New Admission Number for this admission event: ' + data.admissionNo + '.';
                }
                showResult('ok', okMsg);
                if (data.admissionNoWarning) {
                    apaarWarnEl.style.display = 'block';
                    apaarWarnEl.textContent = data.admissionNoWarning;
                }
                if (data.apaarWarning) {
                    apaarWarnEl.style.display = 'block';
                    apaarWarnEl.textContent = data.apaarWarning;
                } else if (data.apaarLinked) {
                    apaarWarnEl.style.display = 'block';
                    apaarWarnEl.className = 'rm-result ok';
                    apaarWarnEl.textContent = 'The prior record\'s APAAR ID was also carried forward to the new Student ID.';
                }
            } else {
                showResult('err', (data && data.message) ? data.message : 'Could not confirm the re-admission link.');
            }
        })
        .catch(function (e) {
            submitBtn.disabled = false;
            submitBtn.textContent = origText;
            showResult('err', 'Network error: ' + (e && e.message ? e.message : 'unknown'));
        });
    });
})();
</script>
