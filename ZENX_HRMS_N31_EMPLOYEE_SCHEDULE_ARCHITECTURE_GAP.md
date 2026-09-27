# ZENX HRMS — ARCHITECTURE GAP REPORT
## EMPLOYEE-LEVEL WORK SCHEDULES (N-31) — correction to N-23/P-021/N-30

**Date:** 2026-08-09 · **Status:** **INSPECTION ONLY — implementation STOPPED per §12**
**Tenant data written:** **NONE** · PR0003 preserved · UAT paused at Test 4 · nothing committed

---

## 1. What already exists

| Component | State | Reusable? |
|---|---|---|
| `attendancePolicy.shifts` — a **map keyed by shiftId** | **Container exists**, `'default'` is the only key ever written | **YES — this is the multi-schedule container** |
| `Attendance_policy::_resolve_windows($policy, $shiftId)` | Already **resolves per-shift**; takes a shiftId parameter | **YES — already shift-aware** |
| `shiftId` at the punch call site | **Hardcoded `'default'`** (`Staff_attendance.php:222`) — matrix `P-012` | Needs the assignment to feed it |
| `attendancePolicy.weeklyOffs` | **Tenant root**, outside `shifts` | **NO — wrong level** (see §2) |
| `workingWeek.versions[]` (built this gate) | Effective-dated version list + `ed_select_for()` resolution | **YES — mechanism sound, attached at the wrong level** |
| `Working_calendar_service` | Resolver, nth-weekday, validation, blocked-state | **YES — needs a schedule argument, not a rewrite** |
| **Employee → schedule/policy assignment** | **DOES NOT EXIST ANYWHERE** — zero hits repo-wide | Must be built |
| `staffSalaryAssignments` (W2) | Effective-dated employee assignment: `staffId` + `effectiveFrom`/`effectiveTo` + `status` + overlap detection | **YES — proven pattern to copy** |
| `ed_select_for()` / `ed_find_overlaps()` | Effective-date selection + overlap detection | **YES — already used by W2 and by this gate** |

**Key finding:** ZenX already has the *container* for multiple schedules (`policy.shifts`) and a
*shift-aware resolver* (`_resolve_windows`). What is missing is (a) weekly-offs **per schedule**, and
(b) any **employee → schedule** link.

## 2. The precise architectural fault in what I built

```php
'shifts' => [ 'default' => ['name'=>'General','windows'=>…,'schedule'=>…] ],  // TIMINGS only
'weeklyOffs' => ['Sun'],                                                       // ← TENANT ROOT
'workingWeek' => ['versions' => [...]],                                        // ← I ADDED THIS AT ROOT TOO
```

`shifts` carries **timings**; weekly-offs sit at the **tenant root**. So today two shifts *can*
differ in start/end time but **cannot** differ in which days are non-working — which is exactly what
"Teachers Mon–Fri, Admin Mon–Sat" requires.

**My `workingWeek.versions[]` inherited that fault.** The effective-dating mechanism is correct and
reusable; it is simply attached to the tenant instead of to a schedule.

## 3. What must change — the delta

```
BEFORE (this gate)                     AFTER (N-31)
──────────────────                     ────────────
school.attendancePolicy                school.attendancePolicy
  └── workingWeek.versions[]             └── shifts / workSchedules
        weeklyOffs, nthWeekdayOffs             ├── 'teaching'  → versions[] (weeklyOffs, nth, timings)
        ← tenant-global                        ├── 'admin'     → versions[]
                                               └── 'transport' → versions[]
                                       staffScheduleAssignments   ← NEW collection
                                         staffId · scheduleId · effectiveFrom/To · status
                                                        ↓
resolveForMonth(policy, …)             resolveEmployeeCalendar(schoolId, staffId, period)
```

| Item | Verdict |
|---|---|
| `Working_calendar_service` resolver logic | **REUSE ~90%** — nth-weekday, holiday/weekly-off distinction, masks, window handling, validation all stand. It gains a schedule argument |
| `workingWeek.versions[]` shape | **MOVE** from tenant root to per-schedule. Same shape, same `ed_select_for()` |
| `Working_week` controller + UI (N-30) | **EXTEND** — from one policy to a named-schedule list plus an assignment surface |
| `working_mask` freezing | **KEEP**, add `schedule_id` + `assignment_effective_from` to the frozen evidence (§8) |
| Payroll parse-loop gate + `W` exemption | **UNCHANGED** — N-23 fix is orthogonal and stays fixed |
| `_staff_working_days()` delegation | **KEEP**, resolve per employee instead of per tenant |
| 23 policy tests | **~18 survive**; ~5 need a schedule wrapper; **17 new** per §13 |

## 4. What genuinely does not exist

1. **Weekly-offs per schedule** — the model cannot express it at all.
2. **Employee → schedule assignment** — zero hits repo-wide. Matrix `P-017` correctly says MISSING.
3. **Assignment history / effective dating for the employee link.**
4. **Assignment RBAC and audit** (no surface exists to secure yet).
5. **Overlap rejection** for employee assignments — `ed_find_overlaps()` exists but has no caller here.

## 5. Migration risk — the part that worries me

**No tenant has any policy configured (8/8), and no employee has any assignment.** So there is
**no migration of existing configuration** — nothing to move, nothing to reinterpret.

**But** the blast radius is now larger than before:

| Risk | Assessment |
|---|---|
| Historical payroll reinterpretation | **NONE** — nothing configured today, and April's slips are already priced and frozen |
| Payroll currently blocked for all 8 tenants | **UNCHANGED** — already true after N-23; N-31 adds *per-employee* assignment as a second precondition |
| **Payroll now blocked per-employee, not just per-tenant** | **NEW** — a tenant could configure schedules and still be blocked for an unassigned employee. §10 requires five distinct blocker states |
| Attendance daily marking | **UNTOUCHED** — `Staff_attendance_finaliser` keeps reading root `weeklyOffs`; changing that is a separate consumer migration, and I am **not** doing it in this gate |

> **Genuine divergence risk worth your attention:** if payroll resolves weekly-offs per employee
> schedule while daily attendance marking still uses the tenant-root `weeklyOffs`, the two disagree —
> attendance could mark `O` on a day payroll considers working. That is a **new P-021-shaped split**,
> just at a different level. It must be closed, but it touches Attendance's daily path and belongs in
> its own gate.

## 6. Existing tests needing update

| Test | Change |
|---|---|
| `WorkingCalendarPolicyTest` (23) | ~18 unchanged; the 5 policy-shape tests wrap the version list in a schedule |
| `PayrollAttendanceSnapshotTest` (24) | Unchanged — mask encoding is unaffected |
| `PayrollCalculationTraceTest` (28) | Unchanged unless the trace surfaces schedule identity |
| Verifiers | Unchanged |

## 7. Capabilities to register

| ID | Capability | Status |
|---|---|---|
| **N-31** | Multiple named work schedules per tenant (weekly-offs **per schedule**) | **MISSING** |
| **N-32** | Employee → schedule assignment, effective-dated | **MISSING** |
| **N-33** | Employee-specific calendar resolution (`resolveEmployeeCalendar`) | **MISSING** |
| **N-34** | Schedule/assignment admin surface + RBAC + audit | **MISSING** |
| **N-35** | Attendance daily-marking consumer migration to per-schedule weekly-offs | **MISSING** — the §5 divergence risk |
| **P-012** shift assignment | MISSING → **now a dependency of N-32** (`shiftId` hardcoded) |
| **P-017** per-employee policy | MISSING → **superseded by N-32** |
| **N-29/N-30** | **PARTIAL, not complete** — mechanism correct, attached at the wrong level |

**I am explicitly NOT marking N-30 complete** because a tenant-level resolver exists (§16).

## 8. Stop conditions triggered (§18)

- **The existing model cannot represent employee-specific weekly-offs** — weekly-offs live at the
  tenant root, outside `shifts`.
- **A default calendar would otherwise be required** for an employee with no assignment — refused.

Neither is fatal; both mean the design must change before more code.

---

## 9. What I recommend

**Reuse, do not restart.** The N-23 payroll fix is correct and independent — keep it. Relocate the
version list from tenant root into named schedules, add the employee assignment collection modelled
directly on `staffSalaryAssignments`, and give the resolver a `staffId`. That is a **contained
refactor of ~1 file plus 2 new ones**, not a rewrite.

**Sequence I propose:**
1. Move `workingWeek.versions[]` → `workSchedules[{id}].versions[]`
2. New `staffScheduleAssignments` collection (copying the W2 assignment pattern, incl. overlap rejection)
3. `resolveEmployeeCalendar(schoolId, staffId, period)`; payroll calls it per employee
4. Freeze `schedule_id` + `assignment_effective_from` into `_attendance_snapshot`
5. Five distinct blocker states (§10)
6. Extend the N-30 UI to schedules + assignments
7. All 17 §13 tests, including **two employees, same run, different masks**

## 10. Decisions I need before implementing

1. **Storage shape** — extend the existing `attendancePolicy.shifts` map (reuses the shift-aware
   `_resolve_windows`, keeps one policy document), or a **separate `workSchedules` collection**
   (cleaner, queryable, but a second document)? **I lean toward extending `shifts`** — it reuses the
   container that already exists and keeps timings and weekly-offs on the same schedule object.
2. **Attendance daily marking (N-35)** — in this gate, or its own? **I recommend its own gate**;
   pulling Attendance's daily path in here makes the change unbounded and risks the punch flow.
3. **Unassigned-employee behaviour** — block that employee only (run proceeds for others), or block
   the whole run? §10 implies per-employee blockers; confirm.

**No code written since the correction. PR0003 intact. UAT paused. Nothing committed or pushed.**
