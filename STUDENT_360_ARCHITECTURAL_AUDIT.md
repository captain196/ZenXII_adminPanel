# Student 360 — Architectural Audit & Defect Register

**Date:** 2026-08-09 · **Branch:** `integration/phase2` · **Working tree: HELD, nothing staged**
**Purpose:** the pre-UAT audit requested alongside the UAT plan.

---

## PART 1 — SCOPE RECONCILIATION

**UPDATED 2026-08-10 (second pass) — the five commercial-parity gaps are now closed.**
After the feature-completeness audit against PowerSchool/Fedena/Teachmint/Entab, you authorised
A-1 (Results), A-2 (Discipline), A-3 (Receipts), B-1 (photo) and E-1 (alert strip). All five are
implemented. **13 panels, 16 registry actions.** Deferred to Student 360 Phase 2 by your
instruction: inline fee collection, inline TC, inline transport, inline document upload, Library,
Medical, LMS, Inventory.

| # | Area you listed | Status | Implementation |
|---|---|---|---|
| 1 | Student Context loading | ✅ BUILT | `Student_context` (eager, 4 queries) |
| 2 | Student Profile | ✅ BUILT | identity header + **Academic panel** |
| 3 | Academic information | ✅ **BUILT (new)** | projection off the student doc — **0 extra reads** |
| 4 | Fee Summary | ✅ BUILT | Fees panel |
| 5 | Fee Preview | ✅ BUILT | capture partial → `entitlements/preview` |
| 6 | Transport section | ✅ BUILT | `Transport_service::getStudentAssignment()` |
| 7 | Hostel section | ✅ **BUILT (new)** | lazy — `hostelAllocations` point read |
| 8 | Attendance | ✅ **BUILT (new)** | lazy — `attendanceSummary`, stored values only |
| 9 | Documents | ✅ **BUILT (new)** | lazy — `documentLinks` + `Document_service::getMetadata()` |
| 10 | Certificates | ⚠️ **BLOCKED — CERT-001** | panel built; **cannot be served, store is RTDB-only** |
| 11 | Entitlements | ✅ BUILT | shared capture partial |
| 12 | Parent Requests | ✅ **BUILT (new)** | filter over entitlements — **0 extra reads** |
| 13 | Approval workflow | ✅ BUILT | Approvals block |
| 14 | Timeline | ✅ BUILT | 2 sources (D-01) |
| 15 | Action Registry | ✅ BUILT | **16 descriptors** (was 13, was 10) |
| 16 | Cross-module navigation | ✅ BUILT | rail links |
| 17 | RBAC enforcement | ✅ BUILT | graded, **per action AND per panel** |
| 18 | Empty states | ✅ BUILT | every panel |
| 19 | Error handling | ✅ BUILT | fail-closed + per-panel retry |
| 20 | Performance / read budget | ✅ **RESOLVED** | see §1.2 |
| 21 | Firestore queries | ✅ VERIFIED | equality-only, no index needed |
| 22 | Mobile responsiveness | ✅ BUILT | grid collapses at 900px |
| 23 | Parent/Teacher app integration | ✅ N/A — verified | read-only, no schema change |
| 24 | **Examinations / Results** | ✅ **BUILT (A-1)** | lazy — `results`; stored marks/grade/rank/subjects |
| 25 | **Discipline / Red Flags** | ✅ **BUILT (A-2)** | eager — `studentFlags` per-student query + pattern |
| 26 | **Fee Receipts** | ✅ **BUILT (A-3)** | lazy — `Fee_firestore_txn::listReceiptsForStudent()` |
| 27 | **Student photo** | ✅ **BUILT (B-1)** | stored `profilePic`, initials fallback on 404 |
| 28 | **Operational alert strip** | ✅ **BUILT (E-1)** | aggregation over the eager context — **0 reads** |

**Score: 22 built · 1 blocked by an external module defect · 0 missing.**

### 1.1 — The one thing that could not be built: CERT-001

`Certificates::generate_certificate()` persists issued certificates with
`$this->firebase->set("Schools/{school}/{session}/Certificates/Issued/{id}")`, and `Firebase::set()`
is `$this->database->getReference($path)->set()` — **the Realtime Database**. There is no Firestore
collection holding issued certificates.

This platform's rule is absolute: RTDB does not exist. No fallback, no mirror, no "just one read".
So the panel is built and wired, and reports an explicit reason instead of data. It renders *"the
record exists, but in a store this surface is not permitted to read"* — deliberately **not** an
empty list, because "no certificates issued" and "cannot read certificates" are different facts and
an empty table would assert the wrong one.

**This is a pre-existing Certificates-module defect that Student 360 surfaced, not a Student 360
defect.** It unblocks itself the moment that module moves to Firestore — this method becomes a
normal equality query and nothing else in Student 360 changes.

### 1.2 — Read budget after adding six panels (D-04 resolved)

Adding six panels eagerly would have multiplied the D-04 figure by roughly six. Instead the panels
are **lazy**: fetched once, only when their tab is opened.

| | Reads |
|---|---|
| **EAGER — every page load** | student doc 1 · feeDemands ≤500 · entitlements ≤100 · transport 1 · **studentFlags ≤200** · **feeDefaulters 1** = **≤803** |
| Academic | **0** — projection off the doc already read |
| Parent Requests | **0** — filter over entitlements already fetched |
| Discipline | **0** — eager (drives the alert strip and tab dot) |
| Alert strip | **0** — pure aggregation over the eager context |
| Hostel | 1 (point read) |
| Attendance | ≤24 |
| Documents | ≤41 |
| Results | ≤40 |
| Receipts | ≤50 |
| Certificates | 0 (not served) |
| History | ≤500 |

**On the +2 eager reads (2026-08-10).** Discipline and defaulter status moved into the eager context
because the **alert strip depends on them**: an alert that only appears after you open the tab it is
warning you about is not an alert. Discipline is two capped equality queries (the dual-key OR the
canonical reader also performs), defaulter is a single point read. Both were weighed against the
alternative — a lazy fetch — and the alert strip is the entire point of E-1.

**Discipline is ~1,000 reads cheaper than the module's own accessor.** `Red_flags::get_student_flags()`
delegates to `_collect_all_flags()`, which fetches the **whole school's** flags (2 × 500 rows) and
filters to one student in PHP. Correct for the dashboard it serves; unusable per student. Student 360
queries the same collection with `studentId` pushed down.

**The eager cost is unchanged from the 4-panel version.** A clerk opening a student to take a
payment pays exactly what they paid before; only someone who opens the Documents tab pays for
documents. D-02 (adopting `Fee_summary_reader`) would cut the eager ≤500 to 1 and remains the single
largest remaining win.

---

## PART 2 — ARCHITECTURAL AUDIT (the four checks you asked for)

### ✅ Check 1 — One canonical implementation per concern?

| Concern | Implementations | Verdict |
|---|---|---|
| **Pricing** | `Fee_generation_service::_applyDeductions()` — **1 definition, 2 call sites**, both internal | ✅ single |
| **Pricing (service fees)** | `Service_entitlement_pricing` re-states the accumulation rule | ⚠️ **known + accepted** |
| **Entitlement resolution** | `Fee_entitlement_reader` (pricing) + `Entitlement_service` (display) | ✅ two roles, one collection owner each |
| **Approval logic** | `Entitlement_service::approve/reject/discontinue` | ✅ single |
| **Business rules (I1/I3/I4/I5)** | `Entitlement_service` + `Fee_entitlement_reader` | ✅ single |

**On the pricing duality:** `Service_entitlement_pricing` duplicates the fullExempt/percent/fixed
accumulation rule from frozen Phase C code. This is your explicit 2026-08-08 decision (documented in
that file's header), it is gated behind `USE_ENTITLEMENT_SERVICE_PRICING = false`, and Student 360
did not touch it. **It remains a Gate 6 cleanup item and the two must stay in sync — they price real
money.** Not a Student 360 finding.

### ✅ Check 2 — Did Student 360 introduce duplicated business logic?

grep for `_applyDeductions | proRateFees | classKey( | buildAdmissionDemandSpecs | createModuleFee |
approvalRequired = | govtReceivable` across all four PHP files: **0 hits.**

**But one honest exception — see D-02.** The dues aggregation in `Student_context::_fees()` is a
second implementation of "what does this student owe", parallel to
`Fee_summary_reader::getStudentSummary()`. I am reporting it rather than defending it.

### ✅ Check 3 — Any write path outside canonical services?

grep for `firestoreSet|firestoreCreate|firestoreUpdate|firestoreDelete|setEntity|updateEntity|
deleteEntity|commitBatch` **and** direct `->propose(|->approve(|->discontinue(` calls across all
four PHP files: **0 hits.** Every mutation the workspace offers is performed by the owning module's
own endpoint, reached over HTTP.

### ⚠️ Check 4 — Hidden coupling that could affect Gate 6 / Gate 8?

| Coupling | Risk | Status |
|---|---|---|
| Direct `studentEntitlements` query in `Student_context` | **Gate 8** — reader swap would leave this panel reporting the pre-swap answer with nothing failing | ✅ **FIXED** (D-05) |
| Entitlements panel shows entitlements with **zero billing effect today** | **Gate 8** — reads as an applied discount when legacy is still the authority | ⚠️ **OPEN — D-03** |
| Transport panel shows `monthly_fee` (gross) | **Gate 6** — after the proration redesign, displayed ≠ billed | ⚠️ **OPEN — D-07** |
| Dues aggregation independent of `Fee_summary_reader` | Divergence from the Fees ledger | ⚠️ **OPEN — D-02** |

---

## PART 3 — DEFECT REGISTER

### 3.1 — Student 360 defects

| ID | Title | Sev | Status |
|---|---|---|---|
| D-01 | Timeline returned **zero rows from every source** — all field mappings wrong | **Critical** | ✅ **FIXED** |
| D-05 | `Student_context` queried `studentEntitlements` directly (3rd query shape, Gate 8 drift) | High | ✅ **FIXED** |
| D-06 | Timeline sort interleaved sources wrongly (two incompatible timestamp formats) | High | ✅ **FIXED** |
| D-02 | Dues aggregation duplicates `Fee_summary_reader` and will disagree with it | High | ⚠️ **OPEN — decision needed** |
| D-03 | Entitlements panel implies a discount that is **not being billed** | High | ⚠️ **OPEN — decision needed** |
| D-04 | Read budget documented as "4 reads"; actual worst case ≈ **1,350 document reads** | Medium | ⚠️ **OPEN — decision needed** |
| D-07 | Transport panel shows gross `monthly_fee`, not the billed amount | Medium | ⚠️ OPEN (Gate 6-linked) |
| D-08 | After approve/discontinue the action rail is stale; the UI asks the operator to reload | Low | ⚠️ OPEN (accepted) |
| D-09 | Hostel action cannot gate on "is allocated" — hostel state is lazy, preconditions may not read | Low | ⚠️ ACCEPTED BY DESIGN |

**D-03 — DECIDED 2026-08-10 (operator).** No transitional status chip. Recorded as expected
transitional behaviour, not a production defect, on the grounds that Gate 8 removes the condition
entirely and temporary UI logic would become dead code at cutover. Re-reviewed after the full UAT
with all other findings. *No code was written for D-03.*

**D-09 — accepted by design.** The `manage_hostel` action gates only on `isActive`, not on whether
the student actually has an allocation. It cannot do better: hostel state is deliberately lazy, and
the registry's hard rule is that **preconditions may not read Firestore** — honouring it here would
have meant an eager hostel read on every page load, reintroducing exactly the N+1 the snapshot
exists to prevent. The Hostel screen shows the operator the real allocation on arrival. Recorded
rather than silently accepted.

---

**D-01 — Timeline returned zero rows (Critical, FIXED).** I wrote the three source field-mappings
from assumption instead of reading the writers. Every one was wrong:

| Collection | I assumed | Writer actually emits |
|---|---|---|
| `studentHistory` | `userId` / `timestamp` / `performedBy` | `studentId` / `changed_at` / `changed_by` |
| `feeAuditLogs` | `studentId` / `description` / `userName` | `entityId` / *(no description field)* / `performedBy` |
| `auditLogs` | `entityId` | `documentId` — **not student-keyed at all** |

Consequence: the student filter matched nothing, and rows that did match were dropped by the
empty-description guard. **The Timeline panel would have shown "No recorded history" for every
student, forever, with no error.** Fixed by verifying against `Sis::_log_history()` and
`Fee_audit_logger::record()`, composing a line from `action`/`entity`/`reason`/`changedKeys` where no
description exists, and **dropping `auditLogs`** — it is keyed by document, so it can only be scanned,
never filtered. The timeline now has **2 real sources, not 3.**

**D-06 — Timeline sort was wrong across sources (High, FIXED).** `studentHistory` writes
`Y-m-d H:i:s`; `feeAuditLogs` writes ISO-8601 with a `T`. Since `' ' < 'T'`, a raw string sort put
*every* same-day fee event above *every* history event regardless of actual time. Fixed with a
normalizer; unparseable stamps render blank and sort last rather than being assigned a fabricated
"now" that would silently move a real event to the top of a student's history.

**D-05 — Third query shape on `studentEntitlements` (High, FIXED).** Only two places may shape that
query: `Fee_entitlement_reader` (pricing; applies I3 + effective windows) and `Entitlement_service`
(display). `Student_context` had added a third. At the Gate 8 reader swap it would have kept
reporting the pre-swap answer with nothing failing to reveal it. Now consumes
`Entitlement_service::forStudent()` — the same accessor the capture partial uses. **Verified: zero
direct `studentEntitlements` queries remain outside the two owners.**

---

**D-02 — Duplicate dues aggregation (High, OPEN).** `Student_context::_fees()` sums `feeDemands`
itself. `Fee_summary_reader::getStudentSummary()` already exists, is the canonical per-student
summary, and is **cheaper** (1 point read on a denormalized `studentFeeSummary` doc vs my 500-doc
query). I did not use it, and the two will disagree:

* Mine **excludes** archived/cancelled demands. `Fee_summary_reader` **does not** — that is FEE-002.
* So Student 360 will read **lower** than the Fees ledger, and be **arithmetically right**.

**The trade-off is real and it is yours to make**, which is why I have not silently switched it:
switching to the canonical reader makes Student 360 consistent with Fees but *inherits FEE-002* and
shows an inflated figure at the counter. Keeping mine keeps the figure correct but leaves two
implementations of the same question. **Recommendation: keep mine for UAT, and converge both on
`Fee_summary_reader` as part of fixing FEE-002 — one fix, one truth.**

**D-03 — The entitlements panel implies a discount that is not applied (High, OPEN).** Today,
`studentConcessions` is the billing authority and `studentEntitlements` is not read by the
generator. Student 360 displays `studentEntitlements`. So a student with an active 10% sibling
entitlement shows **"1 active entitlement"** in the workspace while the fee summary shows **no
discount** — and both are correct.

This is the *same* class of issue SE-001 resolved for the preview, where the answer was "show what
will actually be billed." The panel currently does not meet that bar. An operator will reasonably
read "active" as "applied".

**Recommendation:** add a status chip to the entitlements panel — *"Recorded, not yet applied to
billing (pending Gate 8 cutover)"* — driven by the flag, so it disappears automatically at cutover.
Small, honest, no business logic. **Not implemented: it is a visible product-copy change and I want
your call.**

**D-04 — Read budget misdocumented (Medium, OPEN).** I wrote "4 reads per page load" in
`STUDENT_360_IMPLEMENTATION.md`. That is **4 queries**, not 4 reads. Firestore bills per document:

| Source | Limit | Worst case |
|---|---|---|
| student doc | point read | 1 |
| `feeDemands` | 500 | 500 |
| entitlements | 100 | 100 |
| transport | point read | 1 |
| timeline (2 sources × 250) | 500 | 500 |
| **Total** | | **≈1,102 document reads** |

The *architectural* claim still holds — the count does not grow as the registry grows. But the
figure was wrong and I have corrected it here. Adopting `Fee_summary_reader` (D-02) would cut ~500
of these to 1. **A busy front office opening 200 student pages a day ≈ 220,000 reads/day** — worth
your attention before this ships widely.

---

### 3.1a — UAT-FOUND defects (Student 360's own) — fixed as critical blockers

Both found on the **first real page load**, both fatal, both fixed under the "critical blocker"
exception. Static verification could not have caught either: they are properties of real stored data,
not of the code.

| ID | Title | Sev | Status |
|---|---|---|---|
| S360-U1 | `Array to string conversion` — the workspace would not render | **Critical** | ✅ Fixed 2026-08-10 |
| S360-U2 | Identity header blank for students on the capitalised schema | **High** | ✅ Fixed 2026-08-10 |

**S360-U1 — root cause.** `_academic()` cast every student field with `(string)`. But `Sis` writes
`"Address" => ["Street"=>…, "City"=>…, "State"=>…, "PostalCode"=>…]` (`Sis.php:7534`) — a nested
array. Casting it raised a PHP warning and rendered the literal word `Array`.
**Fix:** all student-doc reads now go through `Student_context::_val()`, which flattens structured
values generically (not special-cased to `Address`, because `Doc` is also an array and any field may
become one). Scalar leaves join in insertion order — for an address that is already
Street → City → State → PostalCode — with empty parts dropped, so a half-filled address renders
`Kanpur, 208001`, not `, Kanpur, , 208001`.

**S360-U2 — found while fixing U1, and the more dangerous of the two.** There are **two key
spellings** in the `students` collection: modern camelCase (`name`, `className`, `fatherName`) and a
legacy capitalised set (`Name`, `Class`, `Section`, `FatherName`, `Profile Pic`) written by the older
admission path. `Red_flags.php:654` already reads both, for exactly this reason. Student 360 read only
camelCase — so every student admitted through the legacy path would have shown a **blank name, class
and father** on the 360 header. That reads as data loss to an operator, when it is only a reader bug.
**Fix:** `_val()` takes a list of candidate spellings, most-canonical first.

**Regression-tested** across both schemas plus 8 value shapes (structured, half-filled, entirely
empty, scalar, both-present, deeply nested, boolean, absent): **zero PHP warnings, both schemas
render.**

**Lesson worth keeping:** a projection that only *displays* data must never be the thing that breaks
the page. Both defects were in reading, not logic — and both were invisible until real data arrived.

### 3.1b — Defects in OTHER modules surfaced by building the panels

| ID | Module | Title | Sev | Status |
|---|---|---|---|---|
| CERT-001 | Certificates | Issued certificates are stored **only in RTDB** (`Firebase::set()` → `getReference()->set()`), violating the platform's zero-RTDB rule. No Firestore collection exists. | **High** | ⚠️ OPEN — blocks the Certificates panel |
| ATT-001 | Attendance | The attendance-percentage policy flag `include_leave_in_percent` is readable **only from RTDB** (`attendance_helper.php:145`). | Medium | ⚠️ OPEN — worked around, not hit |
| RES-001 | Result | `Result::index()` reads the result-withheld flag from **RTDB** — `firebase->get("Schools/{school}/{session}/Fees/Defaulters/{id}")` (`Result.php:314`) — even though `Fee_defaulter_check` states "Firestore is the SOLE source of truth for defaulter status" and writes it to `feeDefaulters`. | Medium | ⚠️ OPEN — Student 360 reads Firestore instead |
| RF-001 | Red Flags | `_collect_all_flags()` fetches the whole school (2 × 500 rows) for every call, including `get_student_flags()` which needs one student. ~1,000 document reads to show a handful of rows. | Medium | ⚠️ OPEN — Student 360 does not use it |

**RES-001 is worth a decision at some point:** two surfaces now answer "is this result withheld?" from
two different stores. Student 360 reads the Firestore document the canonical writer maintains; the
Result module still reads the legacy RTDB path. They will agree only while both are in sync. Student
360 took the compliant path and did not touch the Result module.

**On ATT-001 and why Student 360 does not trip it:** recomputing an attendance percentage would
require that policy flag, and reading it would breach the zero-RTDB rule. Student 360 therefore
displays the **stored** `percentage` written by the Attendance module — which already had the policy
applied at write time. This is both the correct architecture (no duplicated business logic) and the
only compliant one. The session headline deliberately shows **counts, not an overall percentage**:
summing counts is arithmetic, but choosing a percentage denominator is policy that belongs to the
Attendance module.

Neither defect was introduced here. Both are recorded because building the panels is what revealed
them.

### 3.2 — Student Entitlement defects (pre-existing, not introduced here)

| ID | Title | Sev | Status |
|---|---|---|---|
| SE-002 | `entitlements/approve`, `reject`, `discontinue` had **no UI consumer anywhere** — a proposal could be raised and never actioned from anywhere in the product | High | ✅ surfaced; Student 360 is now that surface |
| SE-003 | No admin-side surface for **Parent Requests** | High | 🟡 **HALF-CLOSED** — see below |
| SE-001 | Preview returned ₹0 for admitted students | High | ✅ resolved 2026-08-09 |

**SE-003 — half-closed, and the remaining half matters.**

A useful discovery while building the panel: **parent requests are not a separate collection.**
`Entitlement_requests::submit()` calls `Entitlement_service::propose()` with
`source='parent_request'`, `value=0` and `approvalRequired` forced true — so a parent request *is* a
`studentEntitlements` row. The Parent Requests panel is therefore a filter over rows the context has
already fetched: **zero extra reads, and it cannot drift from the entitlement record because it is
the entitlement record.**

**Closed:** per-student visibility. Open a student and you now see every request the family raised,
its reason, and its status — with an amber dot on the tab and a summary card when any await a
decision.

**STILL OPEN:** there is **no cross-student queue**. `Entitlement_service::pendingQueue()` and
`entitlements/queue` exist and still have no caller. Student 360 answers *"what has this family
asked for?"* — it cannot answer *"what is waiting on me today?"* An office cannot work a request
inbox by opening students one at a time and guessing which ones have pending items.

**Recommendation: a queue screen is required before the parent-request feature is genuinely usable
in production.** It is small (the endpoint exists), but it is not something Student 360 can be made
to do, because Student 360 is by definition per-student.

### 3.3 — Existing Fees defects (unchanged, not touched)

| ID | Title | Sev | Status |
|---|---|---|---|
| FEE-002 | Ledger summary aggregates **archived/cancelled** demands (₹1,09,000 vs ₹37,200) | High | ⚠️ OPEN — confirmed again in `Fee_summary_reader::_recomputeStudent()`, which has no status filter |
| FEE-001 | `submit_fees` 500 via `method_exists()` on a protected method | Medium | ⚠️ OPEN |
| — | `Fee_summary_reader` + `Fee_audit_logger` log at `log_message('warning')` — **not a CI3 level; silently dropped** | Low | ⚠️ OPEN (repo-wide, 75 sites) |

### 3.4 — Existing Transport defects (unchanged, not touched)

| ID | Title | Sev | Status |
|---|---|---|---|
| U1/U2 | All 5 call sites re-derive `netAmount` from `grossAmount`, erasing Phase D discounts | High | ⚠️ OPEN — **Gate 6**, inert while the flag is false |
| — | Promotion → Transport billing recovery | Medium | ⚠️ backlogged |
| — | Student exit → Transport cleanup | Medium | ⚠️ backlogged |

---

## PART 4 — PRODUCTION READINESS

| Gate | Status | Evidence |
|---|---|---|
| Lint (5 files) | ✅ PASS | clean |
| Unit suite | ✅ PASS | 327 tests, 10 failures — **identical set with changes stashed** |
| No writes | ✅ PASS | 0 write primitives |
| No duplicated business logic | ⚠️ **1 exception** | D-02 |
| Single canonical pricing | ✅ PASS | 1 definition (+1 known, flag-gated, accepted) |
| Single approval authority | ✅ PASS | `Entitlement_service` |
| Tenant isolation | ✅ PASS | every read school-scoped + defensive guard |
| RBAC | ✅ PASS | graded, canonical keys, 10/10 |
| Firestore rules | ✅ N/A | Admin SDK — rules not evaluated |
| Firestore indexes | ✅ N/A | equality-only queries |
| Cross-platform (Parent/Teacher) | ✅ PASS | read-only, no schema change |
| Flags unchanged | ✅ PASS | both still `false` |
| Legacy authority preserved | ✅ PASS | `studentConcessions` untouched |
| Working tree | ✅ HELD | nothing staged |
| **Runtime execution** | ❌ **NOT DONE** | zero browser loads, zero live endpoint calls |

### Verdict (updated 2026-08-10, feature-complete)

**FEATURE-COMPLETE. Ready for the comprehensive runtime UAT. Not yet production-ready.**

Static verification passes in full. What stands between here and production:

1. **Runtime UAT has not been run.** Everything above is lint, static analysis, harness-driven
   resolver tests, and call-site verification against real code. **Zero browser loads, zero live
   endpoint calls.** This is the single largest gap and it is the whole point of the next step.
2. **D-02 / D-04** — the dues-reader decision (correctness *and* ~500 reads per page load).
3. **CERT-001** — Certificates cannot be shown until that module leaves RTDB.
4. **SE-003 remainder** — no cross-student request queue exists.

Per your 2026-08-10 direction, all verified findings are collected and fixed together in a
**stabilization phase after** the UAT, rather than interrupting development for incremental fixes.

Two honest caveats about the UAT itself:

* **Production holds one `studentEntitlements` document, `status=discontinued` and inert.** Nothing
  is pending to approve and nothing is active to discontinue, so the Approvals and Parent Requests
  panels **cannot pass or fail — they can only render empty states.** Every such test is marked
  **[NEEDS SEED]**.
* **The Certificates panel is expected to report unavailable.** That is a PASS, not a failure.

---

## Cross-references

`STUDENT_360_UAT_TEST_PLAN.md` (the execution plan) · `STUDENT_360_IMPLEMENTATION.md` (§4 read budget
superseded by D-04) · `FEES_VERIFIED_DEFECTS_BACKLOG.md` (FEE-001/002) ·
`SERVICE_PRORATION_ENTITLEMENT_GATE.md` (U1/U2, Gate 6) ·
`STUDENT_ENTITLEMENTS_LEGACY_RETIREMENT_PLAN.md` (Gate 8)
