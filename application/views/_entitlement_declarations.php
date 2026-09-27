<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * SHARED PARTIAL — Entitlement DECLARATIONS (Phase E).
 *
 * Used by the two PRE-ADMISSION surfaces, where no student record exists yet
 * and the person filling the form may not be staff:
 *   1. Public online admission form  (admission/public_form.php)  — parent, UNAUTHENTICATED
 *   2. CRM walk-in application       (admission_crm/*)            — front office
 *
 * ── WHY THIS IS NOT THE ENTITLEMENT CAPTURE COMPONENT ──────────────────
 * These fields record what the FAMILY DECLARES ("we have a sibling here",
 * "we're applying under RTE", "we need transport"). A declaration is NOT an
 * entitlement — it grants nothing and reduces no fee. It is an input.
 *
 * Two hard reasons it cannot be one:
 *   • Admission_public extends CI_Controller — it is entirely unauthenticated.
 *     The entitlements/* endpoints require graded Fees:edit. An unauthenticated
 *     parent minting a financial grant would be a security defect, and no
 *     commercial ERP permits self-granted concessions.
 *   • No studentId exists yet at this point in either pathway.
 *
 * The declaration is carried on the crmApplications document and PRE-FILLS
 * `_entitlements_capture.php` at the moment staff admit the student — which is
 * where the one canonical Entitlement_service actually creates the entitlement,
 * under maker-checker. One creation path, fed by several declaration surfaces.
 *
 * Expects (all optional):
 *   $decl_prefix   — form-field name prefix (default 'decl_')
 *   $decl_values   — existing values when re-rendering a saved application
 */
$p  = $decl_prefix ?? 'decl_';
$v  = is_array($decl_values ?? null) ? $decl_values : [];
$gv = function (string $k, $d = '') use ($v) { return isset($v[$k]) ? $v[$k] : $d; };
$ck = function (string $k) use ($v) { return !empty($v[$k]) ? 'checked' : ''; };
?>

<style>
/* Scoped so the partial renders correctly inside BOTH the public form and the
   admin CRM shell without inheriting or fighting either one's utilities. */
.entdecl{border:1px solid rgba(188,90,60,.28);border-radius:10px;padding:16px 18px;margin:18px 0;background:rgba(188,90,60,.05)}
.entdecl h4{margin:0 0 4px;font-size:15px;font-weight:700}
.entdecl .entdecl-hint{font-size:12.5px;opacity:.75;margin:0 0 14px}
.entdecl-row{display:flex;align-items:flex-start;gap:9px;margin-bottom:11px}
.entdecl-row input[type=checkbox]{margin-top:3px;flex:0 0 auto}
.entdecl-row label{font-size:13.5px;line-height:1.45;cursor:pointer}
.entdecl-sub{margin:7px 0 0 27px;display:none}
.entdecl-sub input,.entdecl-sub select{width:100%;max-width:340px;padding:7px 9px;border:1px solid rgba(0,0,0,.2);border-radius:6px;font-size:13px}
.entdecl-note{font-size:12px;opacity:.7;margin-top:12px;border-top:1px dashed rgba(0,0,0,.15);padding-top:10px}
.entdecl-lookup{display:flex;gap:7px;align-items:center;max-width:340px}
.entdecl-lookup input{flex:1 1 auto}
.entdecl-find{flex:0 0 auto;padding:7px 13px;border:1px solid rgba(188,90,60,.5);background:rgba(188,90,60,.09);
  border-radius:6px;font-size:12.5px;cursor:pointer;white-space:nowrap}
.entdecl-find:disabled{opacity:.55;cursor:default}
.entdecl-result{margin:6px 0 0;font-size:12.5px;min-height:1em}
.entdecl-result.good{color:#1d7a44}
.entdecl-result.bad{color:#b23a2f}
</style>

<div class="entdecl" id="entdeclBlock">
    <h4>Concession &amp; Service Requests</h4>
    <p class="entdecl-hint">
        Tell us if any of these apply. Nothing is applied automatically — the school
        reviews and approves each request before it affects any fee.
    </p>

    <div class="entdecl-row">
        <input type="checkbox" id="<?= $p ?>sibling" name="<?= $p ?>sibling" value="1" <?= $ck('sibling') ?>>
        <label for="<?= $p ?>sibling">A brother or sister already studies at this school</label>
    </div>
    <div class="entdecl-sub" data-for="<?= $p ?>sibling">
        <?php /* Was a free-text "Sibling's name / admission number" that NO code
                 ever read. SR Number, because an admission number is not
                 something a parent can recite at the counter. On the staff-facing
                 form this resolves live so the operator confirms a PERSON; on the
                 unauthenticated public form there is no lookup and the number is
                 carried as a CLAIM, verified by staff at enrolment. */ ?>
        <div class="entdecl-lookup">
            <input type="text" name="<?= $p ?>sibling_srno" id="<?= $p ?>sibling_srno"
                   inputmode="numeric" placeholder="Sibling's SR Number"
                   value="<?= htmlspecialchars((string) $gv('sibling_srno'), ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!empty($decl_can_lookup)): ?>
                <button type="button" class="entdecl-find" data-find="sibling">Find</button>
            <?php endif; ?>
        </div>
        <p class="entdecl-result" id="<?= $p ?>sibling_result"></p>
    </div>

    <div class="entdecl-row">
        <input type="checkbox" id="<?= $p ?>rte" name="<?= $p ?>rte" value="1" <?= $ck('rte') ?>>
        <label for="<?= $p ?>rte">Applying under RTE / EWS (government reserved category)</label>
    </div>
    <div class="entdecl-sub" data-for="<?= $p ?>rte">
        <input type="text" name="<?= $p ?>rte_ref" placeholder="RTE / EWS certificate or reference number"
               value="<?= htmlspecialchars((string) $gv('rte_ref'), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="entdecl-row">
        <input type="checkbox" id="<?= $p ?>staff_ward" name="<?= $p ?>staff_ward" value="1" <?= $ck('staff_ward') ?>>
        <label for="<?= $p ?>staff_ward">A parent or guardian is employed by this school</label>
    </div>
    <div class="entdecl-sub" data-for="<?= $p ?>staff_ward">
        <?php /* Previously a bare checkbox that identified nobody and stored
                 nothing. An employment claim is only meaningful against a real,
                 ACTIVE employee of THIS school, so it is resolved by Staff ID —
                 the canonical identifier — rather than by name, which would
                 invite "there are three people called Sharma, pick one". */ ?>
        <div class="entdecl-lookup">
            <input type="text" name="<?= $p ?>staff_ward_id" id="<?= $p ?>staff_ward_id"
                   placeholder="Employee's Staff ID"
                   value="<?= htmlspecialchars((string) $gv('staff_ward_id'), ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!empty($decl_can_lookup)): ?>
                <button type="button" class="entdecl-find" data-find="staff">Find</button>
            <?php endif; ?>
        </div>
        <p class="entdecl-result" id="<?= $p ?>staff_ward_result"></p>
    </div>

    <div class="entdecl-row">
        <input type="checkbox" id="<?= $p ?>transport" name="<?= $p ?>transport" value="1" <?= $ck('transport') ?>>
        <label for="<?= $p ?>transport">School transport is required</label>
    </div>
    <div class="entdecl-sub" data-for="<?= $p ?>transport">
        <input type="text" name="<?= $p ?>transport_area" placeholder="Pick-up area / locality"
               value="<?= htmlspecialchars((string) $gv('transport_area'), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="entdecl-row">
        <?php /* ── HOSTEL · DISABLED, NOT DELETED (2026-08-30) ──────────────
                 Ticking this wrote a declaration that NOTHING consumed. There is
                 no hostel request model at all: `hostelRequests` has no writer
                 anywhere in the codebase, so the collection does not exist. A
                 family could ask for a bed and no queue, no operator screen and
                 no allocation would ever see it.

                 Left VISIBLE but disabled, and labelled as such, rather than
                 removed: a school evaluating ZenX should see that hostel is a
                 known, named gap rather than silently missing — and staff who
                 used to tick it need to know why it stopped, not wonder where it
                 went. Building the hostel lifecycle is explicitly out of scope
                 for this workstream; the Transport request lifecycle next to it
                 is the model it should follow when authorised. */ ?>
        <input type="checkbox" id="<?= $p ?>hostel" name="<?= $p ?>hostel" value="1" disabled>
        <label for="<?= $p ?>hostel" style="opacity:.6">
            Hostel accommodation is required
            <span style="font-size:11.5px;opacity:.85">— not yet available; please record hostel requests outside ZenX for now</span>
        </label>
    </div>

    <p class="entdecl-note">
        These are requests only. Supporting documents may be asked for, and the school's
        decision is final. Fees shown before approval do not include any concession.
    </p>
</div>

<script>
(function () {
    var block = document.getElementById('entdeclBlock');
    if (!block) return;
    // Reveal the follow-up input only when its checkbox is ticked. Pure
    // progressive disclosure — no state is submitted that the server does not
    // independently re-read from the posted fields.
    block.querySelectorAll('.entdecl-sub').forEach(function (sub) {
        var box = document.getElementById(sub.getAttribute('data-for'));
        if (!box) return;
        var sync = function () { sub.style.display = box.checked ? 'block' : 'none'; };
        box.addEventListener('change', sync);
        sync();
    });

    // ── VERIFIED LOOKUPS ────────────────────────────────────────────────
    // Only rendered on the STAFF-facing form (decl_can_lookup). The public
    // form is unauthenticated, so it must not be able to enumerate a school's
    // students or staff by trying numbers — there the value is carried as a
    // claim and verified by staff at enrolment.
    //
    // This is DISPLAY CONFIRMATION ONLY. Nothing here is trusted: the server
    // re-resolves the SR No / Staff ID when the admission is saved, so a
    // tampered field cannot fabricate a link.
    var LOOKUPS = {
        sibling: { url: 'sis/lookup_sibling',      field: '<?= $p ?>sibling_srno',
                   out: '<?= $p ?>sibling_result', param: 'sr_no',
                   render: function (d) {
                       var s = d.student || {};
                       var left = (s.status && s.status.toLowerCase() !== 'active')
                                ? ' — NOTE: this student is ' + s.status : '';
                       return '✓ ' + (s.name || '(unnamed)') + ' · '
                            + (s.className || '') + ' ' + (s.section || '')
                            + ' · SR ' + (s.srNo || '') + left;
                   } },
        staff:   { url: 'sis/lookup_staff_member', field: '<?= $p ?>staff_ward_id',
                   out: '<?= $p ?>staff_ward_result', param: 'staff_id',
                   render: function (d) {
                       var s = d.staff || {};
                       return '✓ ' + (s.name || '(unnamed)') + ' · Staff ID ' + (s.staffId || '');
                   } }
    };

    function baseUrl() {
        if (window.BASE_URL) return window.BASE_URL;
        var b = document.querySelector('base');
        return b ? b.href : '/';
    }

    block.querySelectorAll('.entdecl-find').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cfg = LOOKUPS[btn.getAttribute('data-find')];
            if (!cfg) return;
            var input = document.getElementById(cfg.field);
            var out   = document.getElementById(cfg.out);
            if (!input || !out) return;

            var val = (input.value || '').trim();
            if (val === '') { out.className = 'entdecl-result bad'; out.textContent = 'Enter a value first.'; return; }

            out.className = 'entdecl-result'; out.textContent = 'Searching…';
            btn.disabled = true;

            var fd = new FormData();
            fd.append(cfg.param, val);
            var tok = document.querySelector('input[name=csrf_test_name]');
            if (tok) fd.append('csrf_test_name', tok.value);

            fetch(baseUrl() + cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) {
                    // Phantom-success guard: fetch() does not reject on 403/500,
                    // so a denied lookup would otherwise render as a match.
                    return r.json().then(function (b) { return { ok: r.ok, body: b }; })
                            .catch(function () { return { ok: false, body: null }; });
                })
                .then(function (res) {
                    if (!res.ok || !res.body || res.body.status !== 'success') {
                        out.className = 'entdecl-result bad';
                        out.textContent = (res.body && res.body.message) ? res.body.message : 'Not found.';
                        return;
                    }
                    out.className = 'entdecl-result good';
                    out.textContent = cfg.render(res.body);
                })
                .catch(function () {
                    out.className = 'entdecl-result bad';
                    out.textContent = 'Lookup failed. The link will still be verified when you save.';
                })
                .then(function () { btn.disabled = false; });
        });
    });
})();
</script>
