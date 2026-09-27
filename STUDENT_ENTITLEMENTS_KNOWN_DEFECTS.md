# Student Entitlements — Known Defects (own-module)

**Opened:** 2026-08-09 · Defects **inside** Student Entitlements (Phases A–F), found during certification.
Defects belonging to other modules live in `FEES_VERIFIED_DEFECTS_BACKLOG.md`.
Proration defects live in `SERVICE_PRORATION_ENTITLEMENT_GATE.md`.

| ID | Title | Severity | Status |
|---|---|---|---|
| SE-001 | `Entitlements::preview()` returned ₹0 for every admitted student | High | ✅ **Resolved 2026-08-09** |

---

## SE-001 — `Entitlements::preview()` could not price an already-admitted student

| Field | Value |
|---|---|
| **Severity** | High (wrong figure on a live screen) |
| **Phase** | E |
| **Type** | Implementation / architectural mismatch |
| **Status** | ✅ **RESOLVED 2026-08-09** — implemented under explicit authorization, UAT-verified against production data |
| **Blocked certification?** | No — Gate 5 was completed via the canonical promotion path while this was open |

---

## Part 1 — The original defect (retained for history)

### Root cause

`previewDemandSpecs()` delegates to `_routedBuildAdmissionSpecs()` → `buildAdmissionDemandSpecs()`, which
carries **deliberate duplicate detection** (BUG-075 / Fees V7): it seeds a suppression set from existing
*non-archived* demands, keyed `periodKey|canonicalFeeHeadId`, and skips any chart head already present.

That is correct for a **generator** — `assignInitialFees()` must be safe to re-run without double-billing.
It was wrong for a **preview**, which must price the chart unconditionally.

Since every admitted student already holds demands, **every** head was suppressed, `specs` came back empty,
and the endpoint reported `status:"success"` with `totalNet: 0`.

**A duplicate-aware generator had been reused as a pricing preview.** The duplicate detection was never at
fault.

### Why it was worse than it first appeared

`canonicalFeeHeadId` resolves from the school-level registry **by head name**, so a student's Class 8th
"Tuition Fee" also suppressed Class 9th's "Tuition Fee". Previewing against a *different* class did not
help either — verified against `Class 9th / Section A`, which has a valid 4-head chart. There was
therefore **no student state** in which the preview returned a non-zero figure after admission.

### Original evidence

```
POST entitlements/preview {student_id: STU0160}            → lines:[] totalNet:0
POST entitlements/preview {student_id: STU0160, class:9th} → lines:[] totalNet:0
STU0160: 37 existing demands · feeStructure "Class 8th/Section A" exists, 4 heads
No exception logged — the builder returned empty by design; it did not fail.
```

### Compounding defect, found and fixed alongside

The original code additionally skipped the whole tuition leg when class/section were blank:

```php
if ($class !== '' && $section !== '') { ...resolve chart... }
```

so a missing class produced a confident **₹0** rather than an error — the "phantom success" pattern this
project's own trap list warns against. Fixed: class/section now fall back to the **student record**, are
normalised with the same `Firestore_service::classKey()/sectionKey()` helpers `Sis::save_admission()` uses,
and the method **fails loudly** if they still cannot be resolved.

---

## Part 2 — Implementation Summary (the resolution)

**Design chosen: A — preview-only suppression bypass.** Authorized on the principle:

> Duplicate detection is a **write-path** concern. Pricing is a **read-path** concern.
> A preview is a read-only pricing simulation: it must calculate what the generator *would* produce, not
> what the database currently permits to be written.

### Changes — 4 edits across 3 files

| File | Change |
|---|---|
| `Fee_lifecycle::buildAdmissionDemandSpecs()` | added `bool $forPreview = false`; the suppression-seeding loop wrapped in `if (!$forPreview)` |
| `Fee_lifecycle::_routedBuildAdmissionSpecs()` | param added, forwarded down **both** branches (flag on and off) |
| `Fee_generation_service::generateDemandsForStudent()` | param added, forwarded verbatim |
| `Fee_lifecycle::previewDemandSpecs()` | passes `true` — **the only call site that does** |

### Why the solution is architecturally correct

* **No second pricing implementation.** Not one line of pricing was copied or altered. The preview flows
  through the *same* `Fee_generation_service` — same concession resolution, same `_applyDeductions`, same
  rounding, same `fullExempt` drop. A preview and a real generation cannot disagree, because they are the
  same code.
* **Additive and default-preserving.** The flag defaults to `false`, so every pre-existing caller is
  byte-identical to its pre-SE-001 behaviour.
* **One flag, one behaviour.** The parameter guards exactly one `foreach` loop. It changes nothing else.

### Why duplicate detection remains a write-path concern

Suppression exists to stop a re-run creating a **second demand document** for a head the student already
holds — a double-billing guard. It is meaningful only where a write can occur. The preview path performs
**no writes at all**, so suppression protects nothing there while actively producing a wrong number.

The guard is untouched on every write path. Verified call-site audit:

| Call site | Flag | Path |
|---|---|---|
| `Fee_lifecycle:482` — `assignInitialFees()` | **default `false`** | production write (admission + promotion) |
| `Fee_generation_ab_verify:97,98` | **default `false`** | A/B probe |
| `Fee_lifecycle:405` — `previewDemandSpecs()` | **`true`** | read-only, specs discarded |

### Why preview can safely bypass suppression

`previewDemandSpecs()` returns specs to `Entitlements::preview()`, which reads them, totals them, and
discards them. Grep for write primitives (`firestoreSet`, `setEntity`, `writeDemand`, `commitBatch`,
`delete`) across all three methods in the chain returns **zero**. `buildAdmissionDemandSpecs()` *returns*
`batchEntries`; only `assignInitialFees()` commits them, and the preview never calls it.

**One subtlety, deliberately preserved:** `$haveKeys` is still populated *as heads are emitted* during the
build — that part sits outside the flag. A preview therefore cannot list the same head twice within one
build. Only the "already in the database" seeding is skipped. Disabling the whole mechanism would have
introduced a subtler bug.

### Confirmation that production write behaviour is unchanged

| Check | Result |
|---|---|
| Write-path call sites using default | 3 / 3 ✅ |
| Pricing logic modified | none ✅ |
| Preview writes | 0 ✅ |
| Demand hashes across 3 students, before vs after preview calls | **byte-identical** ✅ |
| Global counts (demands / entitlements / receipts / accounting) | unchanged ✅ |
| Gate 5 evidence | remains valid — no generator behaviour altered ✅ |

### UAT evidence (live production data, 2026-08-09)

```
STU0160 | lines: 37 | gross: 37200 | net: 37200 | concession: 0   ← was lines:0 net:0
STU0001 | lines: 37 | gross: 34600 | net: 34600 | concession: 0   ← control, no entitlements
```

Hashes after these calls: `f9680b70…`, `6cb287a8…`, `ad58aad9…` — **all identical to the pre-UAT
fingerprint.** Zero writes.

**On `concession: 0` for STU0160, which holds an active sibling 10% entitlement — this is correct, not a
residual defect.** The preview runs through `Fee_generation_service`, which reads the **legacy**
`studentConcessions` (the current billing authority). STU0160 has zero legacy concessions; its entitlement
lives in `studentEntitlements`, read only by the shadow, whose output is discarded. The preview therefore
shows **exactly what production will bill**. Showing a 10% discount would have been a lie — promising a
reduction the generator will not apply.

After the Gate 8 reader swap, this preview will show the discount **automatically**, because it uses the
same reader the generator does. No preview change is required at cutover.

### Verified structurally rather than live

Two items rest on code inspection rather than a live run, recorded honestly:

* **Duplicate detection on write paths after the change** — proven by the call-site audit above (PHP
  default parameters are deterministic), but no post-change promotion was executed. A promotion producing
  37 rather than 74 active demands would be the live confirmation.
* **The multi-entitlement case (STU0159)** — its preview line was not captured in the UAT console output.
  Expected to behave as STU0160 does; not independently observed.

Neither affects the resolution: both concern confirming unchanged behaviour, not the fix itself.

### Files

`application/libraries/Fee_lifecycle.php` · `application/libraries/Fee_generation_service.php` ·
`application/controllers/Entitlements.php`

---

## Cross-references

* `SERVICE_PRORATION_ENTITLEMENT_GATE.md` — U1/U2/U3, live proration defects (Gate 6)
* `FEES_VERIFIED_DEFECTS_BACKLOG.md` — FEE-001…005, other-module defects
* `STUDENT_ENTITLEMENTS_LEGACY_RETIREMENT_PLAN.md` — Gate 8 sequencing
* `LATE_FEE_PENALTY_MODULE_PREREQUISITES.md` — coupled `dueDate` + fine-awareness gates
