# PHASE 1 — SCHEDULES + ASSIGNMENTS ADMIN SURFACE — GATE REPORT

**Date:** 2026-08-10 · **Status: COMPLETE (browser-unverified)** · **UNCOMMITTED, NOT PUSHED, NOT DEPLOYED**

---

## 1. What was built

| File | State |
|---|---|
| `application/controllers/Work_schedules.php` | new — 7 endpoints |
| `application/views/work_schedules/index.php` | new — full admin screen |
| `application/config/routes.php` | +7 routes |
| `firebase-rules/firestore.rules` | +1 match block — **WRITTEN, NOT DEPLOYED** |
| `tests/Unit/WorkSchedulesSurfaceTest.php` | new — 18 tests / 37 assertions |

**This is the unblock.** Before it, no tenant could declare a schedule and payroll was blocked for
all 8 tenants.

## 2. Endpoints

| Route | Permission | Purpose |
|---|---|---|
| `work_schedules` | HR view | the page |
| `work_schedules/get_data` | HR view | catalogue + assignments + departments + **blocked employees** |
| `work_schedules/preview` | HR view | resolve one employee on one date, with the reasoning chain |
| `work_schedules/save_schedule` | HR **manage** | create / edit |
| `work_schedules/delete_schedule` | HR **manage** | refused while in use |
| `work_schedules/save_assignment` | HR **manage** | attach to department or staff |
| `work_schedules/delete_assignment` | HR **manage** | remove |

## 3. D4 as implemented — offered, never adopted

The tenant's declared `attendancePolicy.weeklyOffs` appears on the **new-schedule form** as an
amber suggestion naming the days, with the sentence *"That was declared for attendance — click the
days above to use it for pay as well, or choose different ones."*

The chips start **unselected**. Until an administrator picks days and saves, no schedule exists and
payroll stays blocked. Two guards hold this:
`test_weekly_offs_is_never_written_into_a_schedule` asserts the controller never copies the field
into `workSchedules`, and `test_surface_never_invents_a_schedule` asserts 0 × `defaultSchedule` /
`satCount` / `date('w'`.

**Payroll now uses your weekly-offs — but only once a human has confirmed them for pay.**

## 4. Back-compatibility — no migration

Assignments written before Phase 1 carry only `staffId` / `scheduleId`.
`_normaliseAssignment()` reads those as `attachmentType: 'staff'`, and every write mirrors the
legacy fields alongside the new ones. **Nothing is migrated, no existing payslip changes** (B7).

## 5. Bulletproof criteria — evidence

| # | Property | Evidence |
|---|---|---|
| B1 | one policy or BLOCK | resolution delegated to Phase 0; blocked employees listed by name with reason |
| B2/B3 | overlaps + ties refused | `findOverlaps()` runs **before** the write — `test_assignment_save_refuses_overlaps_before_writing` asserts the ordering, so an ambiguous state is never persisted |
| B4 | no invented schedule | 4 source guards over comment-stripped code |
| B5 | explainable | the blocked panel renders `Policy_assignment_service::explain()` per employee; `preview` returns the chain |
| B6 | frozen evidence | **unchanged** — Phase 1 touches no payroll writer |
| **B7** | historical reproduction | **no payroll path modified**; legacy assignment shape still read identically |
| **B8** | tenant isolation | 0 × raw `query(`; `schoolWhere()` for reads; `docId()` for writes; `schools` addressed by `$this->school_id` — 4 guards |
| B9 | Teacher + Parent apps | **no impact** — new collection reads only; no app reads `staffScheduleAssignments` or `workSchedules` |

## 6. Gate results

| Step | Result |
|---|---|
| `php -l` controller, view, routes, test | **PASS** |
| New suite | **18 tests / 37 assertions — OK** |
| Full Unit suite | **564 tests / 1,760 assertions / 10 failures** |
| vs prior (546 / 10) | **546 + 18 = 564 ✓ · failures unchanged ✓ — no regression** |
| CSRF | **not required** — session-authenticated admin POSTs under `work_schedules/*`, not `superadmin/*`. Panel sends the token from the page meta tags |
| Firestore indexes | **none required** — `schoolWhere` equality only |
| Firestore rules | **1 block added, NOT DEPLOYED.** `allow write: if false` — the payroll denominator must never be client-writable |

## 7. Guards worth naming

- **Delete-in-use refusal.** Deleting a schedule someone still uses would silently block every
  employee on it at the next run. `delete_schedule` counts assignments and refuses with the count.
- **Overlap before write.** The check precedes `set()`; the test asserts the *ordering*, not merely
  the presence of the call.
- **Fail-closed panel JS.** Every request checks `r.ok` **and** `{status:'error'}` — `fetch()` does
  not reject on 403/500, so checking either alone would report a denied action as done.

## 8. HONEST LIMITS

1. **Browser-unverified.** I cannot operate a browser. Every claim above is source-, test- or
   lint-derived. The screen has not been loaded, no schedule has been created, and no assignment
   has been written. **This is why Phase 1 is not "verified".**
2. **The blocked-employee panel passes `organisationPolicyId: null`.** It therefore lists everyone
   with no department or staff assignment — the strictest reading. Once Phase 5 moves the
   organisation policy into the catalogue this becomes a real policy id and the list shrinks. The
   list is currently a **superset** of who payroll would actually refuse.
3. **Rules written, not deployed.** PHP writes with the service account and bypasses rules, so the
   surface works without the deploy — but the read guard is not live until deployed.
4. **`preview` reads department assignments with a second unfiltered `schoolWhere`.** Correct but
   not the cheapest query; acceptable at current tenant sizes, worth revisiting if assignment
   counts grow.

## 9. What to test in the browser — operator, when ready

1. Open `/work_schedules` as an HR-permissioned admin. Expect: empty catalogue, and a blocked list
   naming every active employee.
2. Click **New schedule** — confirm the amber weeklyOffs suggestion appears and the day chips start
   **unselected**.
3. Create *Teaching — Mon to Fri* (offs: Sat, Sun). Save.
4. **Assign a schedule** → a department → confirm the blocked list shrinks by that department's headcount.
5. Assign an individual override for one employee; confirm it appears and the person leaves the blocked list.
6. Try an overlapping assignment for the same person → expect a **refusal naming the clashing start date**.
7. Try deleting a schedule that is in use → expect **refusal with the usage count**.

## 10. Next

**Phase 2 — N-35:** migrate the three competing Attendance calendar authorities (finaliser `O`
overlay, punch-time `W`, and the grid's hardcoded-Sunday edit lock) onto the resolved employee
calendar. That is where attendance and payroll finally agree, and where decision **D2** is due.

---

**UNCOMMITTED. Not pushed, not deployed, not migrated, not seeded. `firestore.rules` edited but NOT
deployed. PR0003 and all April evidence untouched. UAT remains paused at Test 4.**

---

# ADDENDUM — BROWSER UAT COMPLETE (2026-08-11)

**Operator-executed on the live IIT Kanpur tenant. Phase 1 is VERIFIED, not merely built.**

## Results

| Test | Result |
|---|---|
| 1 · Page loads, blocked panel lists unpayable staff by name | **PASS** |
| 2 · Modal opens with all chips unticked (**D4**) | **PASS** |
| 3 · Create schedule; blocked *reason* changes `no_policies` → `no_assignment` | **PASS** |
| 4 · Attach to department — 6 staff cleared by one action | **PASS** |
| 5 · Individual assignment; card correctly stays amber over 2 unpayable records | **PASS** |
| 6 · Overlap refused, naming the clashing start date | **PASS** |
| 7 · Delete-in-use refused with usage count | **PASS** |
| 7b · Delete succeeds when nothing is attached | **PASS** |
| 8 · Configuration survives hard reload | **PASS** |

**Live configuration:** 3 schedules (Academic, Support, Administration), 3 assignments
(Dept·Academic, Dept·Support, Employee·SSA0001), 10 active staff, 2 blocked.

## Defects found BY the browser and fixed during UAT

Every one passed the unit suite. None was findable without a browser.

| # | Defect | Why the suite missed it |
|---|---|---|
| 1 | Modals rendered permanently — `display:flex` beats `[hidden]`; overlays swallowed every click | CSS cascade is not source-inspectable |
| 2 | `Working_calendar_service` never required — `save_schedule` fatal | Static call, only fatal on that endpoint |
| 3 | Staff `status` is `'Active'`; compared to `'active'` — **all staff skipped** | Data-value convention |
| 4 | `where()` returns `['id','data']`; every field read one level too shallow | Return shape |
| 5 | **False green "every employee resolves"** on a tenant with zero schedules | Arithmetic invariant, not syntax |
| 6 | `$staffId === '' → continue` silently dropped 12 staff | Fail-open, looked like success |
| 7 | `render()` early-returned; two tables stuck on "Loading…" forever | Control flow |
| 8 | `Firestore_service::delete()` does not exist — `remove()` does | Wrong method name |
| 9 | `!r.ok` threw before reading the body — **every** server message replaced by "Request failed (400)" | Error path |
| 10 | Errors rendered in a page-top bar hidden behind the modal | Layout |
| 11 | Schedule dropdown pre-selected the first entry — wrong schedule saved silently | Default value |
| 12 | Chips asked for weekly *offs*; operator naturally ticks *working* days → inverted schedule | Question framing |

**Defect 5 is the one that matters.** The panel claimed every employee resolved while zero
schedules existed. That is the N-23 failure shape exactly — a confident wrong answer where a
refusal belonged. It is now structurally impossible: the UI refuses to render an all-clear when the
catalogue is empty, and unidentifiable staff are reported rather than skipped.

## Guards added so none can return

12 new assertions, including: `[hidden]` rules on every container with an author `display`;
every `$this->fs->` method must exist in `Firestore_service`; no case-sensitive status compare; no
native browser dialogs; response bodies read on every HTTP status. **Suite: 572 tests / 10 failures
— documented baseline unchanged throughout.**

Guards now read **comment-stripped** source in both PHP (`token_get_all`) and the view
(`viewCode()` strips HTML/CSS/JS comments) — prose tripped a literal guard four times before this
was made structural.

## OPEN — operator decisions, not defects

1. **`Support` has no weekly off** — all 7 days working. Vipul Tiwari (STA0001) is live on it. Every
   day counts as working, which changes what a single absence deducts.
2. **All assignments start `2026-08-11`.** Payroll resolves **August onward only**. April — the month
   behind PR0003 and the ₹0-instead-of-₹1,250 payslip — remains blocked. Re-adding with
   `2026-04-01` would enable it, **but only if that pattern was genuinely in force then.**
3. **2 records with no staff ID** (`Diag Staff`, `OK Escort`) — resolve in Staff Records. Not touched.

Item 2 gates whether PR0003 can be re-verified at all.

## Status

**Phase 1: COMPLETE and BROWSER-VERIFIED.** B1–B5, B8, B9 proven; B6/B7 untouched (no payroll
writer modified). Payroll has a resolvable calendar for the first time since N-23.

**Still UNCOMMITTED, unpushed, undeployed. `firestore.rules` edited but NOT deployed.**
