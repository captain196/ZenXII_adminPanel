# Exam Track 1 (UX-2.0.1-B Scheduling) — D1–D5 Validation Ledger

> **Status:** Track 1 = IMPLEMENTED but HELD. This ledger records the real browser/mobile
> validation pass. Track 1 stays HELD until **every** row reads PASS with evidence attached.
> Workflow boundary: Build → Validate (this) → Audit → Commit → Push, assessed at the
> **whole Exam Module** completion boundary — not the scheduling phase alone.

## Run metadata (fill at start of pass)

| Field | Value |
|---|---|
| Date of pass | _____ |
| Tester | _____ |
| Tenant | SCH_D94FE8F7AD |
| Session | 2026-27 |
| Login | SSA0001 |
| Browser + version | _____ |
| Base URL | http://localhost/ZenX/school |

## PASS/FAIL ledger

| Item | Scope | Method | Result | Evidence files | Notes |
|---|---|---|---|---|---|
| **D1** | Review/coverage/conflict/unscheduled + publish gate blocks | Manual (browser) | ☐ PENDING | D1-coverage.png, D1-conflict-blocked.png, D1-unscheduled.png, D1-ready-enabled.png, D1-console.png, D1-serializer.json | |
| **D2** | Mobile renderer (cards/sheet) + 768px boundary + no overflow | Manual (device emu) | ☐ PENDING | D2-390-cards.png, D2-sheet-open.png, D2-sheet-edit.png, D2-767.png, D2-769-table.png | |
| **D3** | Edit-mode hydrate + single-field round-trip, no collateral mutation | Manual (browser) + serializer diff | ☐ PENDING | D3-hydrated.png, D3-before.json, D3-after.json, D3-reopened.png | |
| **D4** | Auto-seq/shift (weekend+holiday rules) + clone (datesheet_json) + copy-class | Manual (browser) + network capture | ☐ PENDING | D4-autoseq.png, D4-shift-fwd.png, D4-shift-back.png, D4-clone-network.png, D4-copyclass.png, D4-applyall.png | |
| **D5** | Default-OFF legacy preserved; builder only via flag; legacy save intact; no state leak | Manual (browser) | ☐ PENDING | D5-noparam-legacy.png, D5-uxlegacy.png, D5-uxdatesheet-builder.png, D5-localstorage-builder.png, D5-legacy-save-in-list.png | |

Mark each Result as **PASS** or **FAIL** (replace ☐ PENDING). One FAIL ⇒ Track 1 stays NOT validated.

## Sign-off block (complete only when all rows PASS)

- [ ] D1 PASS  · [ ] D2 PASS · [ ] D3 PASS · [ ] D4 PASS · [ ] D5 PASS
- [ ] Zero uncaught Console errors across D1–D5
- [ ] Serializer-equivalence note attached (builder vs legacy → same row set for one equivalent input)
- [ ] Evidence bundle stored at: ________________________

**Track 1 Validate stage:** ☐ NOT SATISFIED → ☐ SATISFIED (date: _____, signed: _____)

---

## Evidence-capture helper (paste into browser DevTools Console)

**A. Capture the serializer output** (run on the builder page after building/saving):
```js
copy(JSON.stringify(JSON.parse(document.getElementById('examScheduleInput').value), null, 2));
// → clipboard now holds the pretty serializer JSON; paste into D{n}-serializer.json / D3-before.json / D3-after.json
```

**B. Confirm which UI is active (D5 flag check):**
```js
console.log({
  ZXB_ON: (typeof ZXB_ON !== 'undefined') ? ZXB_ON : 'undefined',
  builderVisible: !!document.querySelector('#zxb-scope'),
  legacyVisible: !!document.querySelector('#ec-legacy-schedule'),
  localStorage_zxb: localStorage.getItem('zxb'),
  url: location.href
});
```

**C. Confirm mobile/desktop renderer (D2 boundary check):**
```js
console.log({ isMobileMatch: window.matchMedia('(max-width:768px)').matches,
              cards: document.querySelectorAll('.zxb-card').length,
              rows: document.querySelectorAll('#zxb-rows tr').length,
              innerWidth: window.innerWidth });
```

**D. Console error watch (leave open during every script):**
```js
window.addEventListener('error', e => console.error('UNCAUGHT', e.message, e.filename, e.lineno));
```

> Reference scripts (exact URLs/actions/expected/failure/pass-fail) are in the prior
> "Track 1 validation execution package" message — D1..D5 Validation Scripts.
