# Student 360 — Implementation Record

**Built:** 2026-08-09 · **Branch:** `integration/phase2` · **Status:** implemented, **UNCOMMITTED, held**
**Scope authorization:** "Implement Student 360 as the next planned component, using the existing
`Entitlement_service`, `Entitlements` controller, preview endpoint, and capture component as the
canonical backend. Do not duplicate business logic."

---

## 1. What was built

> **UPDATED 2026-08-10 — FEATURE-COMPLETE (10 panels).** The six remaining panels (Academic,
> Attendance, Hostel, Documents, Certificates, Parent Requests) were built on operator instruction.
> `STUDENT_360_ARCHITECTURAL_AUDIT.md` §1 carries the authoritative scope table.

| File | Role |
|---|---|
| `application/libraries/Student_context.php` | EAGER snapshot — identity, academic, fees, entitlements, parent requests, transport |
| `application/libraries/Student_panel_reader.php` | **LAZY** panels — attendance, hostel, documents, certificates |
| `application/libraries/Action_registry.php` | **13** declarative ActionDescriptors + resolver |
| `application/libraries/Student_timeline_reader.php` | Read-only fan-out across **2** student-keyed audit collections (D-01) |
| `application/controllers/Student360.php` | Orchestration only — 1 page route + 3 JSON endpoints |
| `application/views/student360/workspace.php` | Rendering surface — 10 tabs |
| `application/config/routes.php` | +11 lines, 4 GET routes |
| `application/views/sis/students.php` | +1 — entry point in the SIS row menu |

**Nothing else was touched.** No Fees file, no `Entitlement_service`, no capture partial, no
`Hostel`/`Attendance`/`Certificates` controller, no `Document_service`, no flag.

**Panels are lazy by design.** Academic and Parent Requests cost **zero** extra reads (both are
projections over data the eager context already holds); attendance, hostel and documents are fetched
only when their tab is opened. The eager per-page cost is therefore **unchanged** by adding six
panels — see the audit §1.2.

---

## 2. The architectural claim, and its evidence

Student 360 is an **orchestration surface**: it renders what other modules own and dispatches to
their implementations. The claim is verifiable, not stylistic:

| Property | Check | Result |
|---|---|---|
| No Firestore writes | grep `firestoreSet\|firestoreCreate\|firestoreUpdate\|firestoreDelete\|setEntity\|updateEntity\|deleteEntity\|commitBatch` across all 4 PHP files | **0** |
| No duplicated business logic | grep `_applyDeductions\|proRateFees\|classKey(\|buildAdmissionDemandSpecs\|createModuleFee\|approvalRequired =\|govtReceivable` | **0** |
| No per-module branch in the rail | the view loops `$actions`; the only `if` is on the declared `target` field | **0 module branches** |
| RBAC keys canonical | all 10 descriptors checked against `functions/rbac_modules.json` | **10/10** |
| Registry routes resolve | all 10 checked against `routes.php` | **10/10** |
| Descriptor integrity | all 12 required fields, unique priorities, callable preconditions | **10 descriptors, 0 problems** |
| Unit suite | 327 tests, 10 failures — **identical with the change stashed** | **no regression** |
| Lint | all 6 files | **clean** |

Adding a module to Student 360 means adding rows to `Action_registry`. No Student 360 file changes.
That is the test of whether the abstraction is real.

---

## 3. Reuse, specifically

* **Entitlements** — the workspace mounts `_entitlements_capture.php`, the same partial the three
  admission pathways use, and posts to `entitlements/propose|preview|for_student|approve|reject|
  discontinue`. That controller's own header anticipated this: *"Adding a fourth surface later
  (Student 360) requires no change here."* It was correct — **no change was needed**.
* **Transport** — reads go through `Transport_service::getStudentAssignment()`, the module's own
  single-student accessor (which is itself labelled "Student 360 (2026-08)"). Student 360 does not
  read `studentRoutes` directly; that collection is `COL_STUDENT_ROUTES_LEGACY` and a direct read
  would pin this surface to a collection Transport intends to move off.
* **Pricing** — never computed here. The preview action routes to the SE-001 preview endpoint.
* **RBAC** — `has_permission()`, the same graded helper the controllers use.

---

## 4. The performance guarantee

`Student_context` is built **once** and handed to every precondition. Preconditions are pure
functions of that snapshot and **may not read Firestore** — the rule is stated in the class header
because breaking it silently reintroduces an N+1 that worsens with every action added.

**Read budget: 4 QUERIES per page load, fixed** — student doc, demands query, entitlements query,
transport doc. The count does not grow as the registry grows, and the timeline is split behind its
own route so the counter summary never waits on audit reads.

> ⚠️ **CORRECTED — see D-04 in `STUDENT_360_ARCHITECTURAL_AUDIT.md`.** An earlier version of this
> section said "4 reads". That is 4 **queries**; Firestore bills per **document**, and the worst
> case is **~1,100 document reads** per page load. The architectural claim above still holds; the
> figure did not.

**No composite index is required.** Every query is equality-only with `orderBy = null`, so Firestore
index merging applies. This is why the timeline sorts in PHP — see §6.

---

## 5. Defects found and fixed during integration

These were found by verifying against the real code rather than assuming, and each would have
shipped broken:

1. **`Action_registry` would have fatalled.** CI3 does not autoload `application/libraries/`.
   A static `Action_registry::resolveFor()` call fatals unless something else loaded the file first
   — a failure that appears only on the one route that forgot. Converted to an instance method
   loaded via `$this->load->library()`.

2. **`'Sis'` would have denied every non-bypass role.** `has_permission()` compares with
   `in_array(..., true)`; the canonical key is `SIS`. The near-miss does not fail loudly — it just
   silently refuses everyone. Fixed at all 3 gates.

3. **The CSRF token had no holder.** The shared capture partial reads its token from a page-level
   `input[name="<csrf>"]` and renders none itself, because every previous host was a form page.
   Student 360 is the first form-less host, so every entitlement POST would have hit CI's global
   CSRF and returned a blank 403 — no log, no console error. The host page supplies it now, which is
   that partial's own design; the frozen component was not touched.

4. **Four actions pointed at JSON endpoints, not pages.** The `Entitlements` controller has **no
   `load->view()` at all**. Rendering those as links would have dropped the operator on a bare
   `{"status":"error","message":"POST required."}`. Introduced the declared `target: page|panel`
   field so the rail focuses a panel instead of navigating — and the rail still branches only on a
   declared field, never on module.

5. **Two transport actions pointed at POST-only endpoints** (`transport/suspend_assignment`,
   `transport/resume_assignment`). Repointed at `transport/assignments`, the screen that collects
   the reason and date window those operations require.

6. **`sis/certificates` does not exist.** TC issuance lives in the `Certificates` module. Repointed
   at `certificates/generate`.

7. **`safe_path_segment()` emits a JSON body and `exit`s** — correct for the two JSON endpoints
   (and identical to `Entitlements.php`), wrong for the HTML page route, which would have sent a
   browser JSON instead of a page. The page route validates and `show_404()`s instead.

8. **A capped query with no `orderBy` returns an arbitrary slice**, ordered by document id — so a
   naive `limit 40` would have shown "some 40 events" while looking exactly like "the 40 most
   recent". See §6.

---

## 6. Deliberate decisions worth challenging later

* **The timeline sorts in PHP, not Firestore.** Ordering by timestamp in Firestore needs a composite
  index on each audit collection, and this module is not authorized to deploy indexes.
  So the per-source cap is set well above realistic per-student volume (250), the merge is sorted
  here, and a source that hits its cap sets `truncated` — the UI says the history is partial rather
  than presenting it as complete. **If per-student volumes approach the cap, the fix is the indexes
  plus a real `orderBy`, not a larger number.**

* **`tenantAudit` and `accountingAudit` are excluded.** They key by tenant and by ledger entry, so
  neither can be filtered to one student without a collection scan whose cost grows with school age,
  to surface rows mostly not about this student.

* **No `studentTimeline` collection was created.** A unified write target would mean every module
  dual-writing its history — a second source of truth guaranteed to drift. Each entry keeps its
  `source` collection badge instead, so provenance is visible.

* **Dues exclude archived and cancelled demands.** `FEE-002` records that the Fees ledger summary
  currently does *not* exclude them. Student 360 does the correct thing rather than reproducing the
  defect, and does not attempt to fix Fees. **The two surfaces will therefore disagree until FEE-002
  is fixed** — Student 360 will read lower and be right.

* **Blocked actions render with their reason; unpermitted actions are hidden entirely.** A disabled
  action that explains itself ("Outstanding dues must be cleared") teaches the operator what the
  system needs. An action they can never perform only advertises someone else's power.

* **Preconditions fail CLOSED.** A precondition that throws disables its action and logs at `error`
  (not `warning` — CI3 silently drops that level).

---

## 7. A gap this surfaced

**`entitlements/approve`, `reject`, and `discontinue` had no UI consumer anywhere.** A grep across
`application/views/` and `application/controllers/` returns zero callers. Phase E shipped them as
working, RBAC-gated endpoints, but a proposal could be raised and then never actioned from anywhere
in the product.

Student 360's Approvals block is that missing surface. It is UI only — it collects an id and a
reason and posts to the canonical endpoints. Approval rules, the self-approval block, and invariants
I1/I4/I5 all remain inside `Entitlement_service`, and maker-checker is preserved because the
endpoints themselves require `Fees:manage`.

---

## 8. What this change does NOT do

Per standing instruction, and verified:

* No commit, stage, push, merge, or branch switch.
* `ENTITLEMENT_SHADOW_COMPARE` and `USE_ENTITLEMENT_SERVICE_PRICING` remain **false** — untouched.
* `studentConcessions` remains the production authority — the reader was **not** swapped.
* No legacy code removed, no historical data deleted.
* Gate 6 and Gate 8 **not** begun.
* No Fees module file modified.
* No `firestore.rules` or `firestore.indexes.json` change — reads go through the Admin SDK (rules
  are not evaluated) and every query is equality-only (no composite index).
* **Cross-platform:** admin-only, read-only, no schema change, no collection added, no field written
  — **no Teacher or Parent app impact.**

---

## 9. Not yet verified

Stated plainly rather than implied:

* **No runtime execution.** Everything above is lint, static analysis, harness-driven resolver
  tests, and call-site verification against the real code. The page has not been loaded in a
  browser, and no endpoint has been exercised against live data.
* **The Approvals block has not been run against a real pending entitlement.** Production currently
  holds one `studentEntitlements` document, `status=discontinued` and inert (the Gate 4B migration
  artifact), so there is nothing pending to approve or active to discontinue. The block will render
  its empty state.
* **The timeline has not been observed against a student with history in all three collections.**

A UAT walkthrough can be written on request.

---

## Cross-references

`STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md` §9 (Action Registry) ·
`STUDENT_ENTITLEMENTS_KNOWN_DEFECTS.md` (SE-001, the preview this surface calls) ·
`FEES_VERIFIED_DEFECTS_BACKLOG.md` (FEE-002, the archived-demand divergence in §6) ·
`STUDENT_ENTITLEMENTS_LEGACY_RETIREMENT_PLAN.md` (Gate 8)
