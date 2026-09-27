# ZENX HRMS — POLICY MODEL COMPARISON & DECISION BRIEF

**Date:** 2026-08-10 · **Type:** decision support · **No code modified**
**Purpose:** decide the policy architecture BEFORE any further payroll fix or UAT test.
Every scope claim below is verified against source and cited.

---

## 1. THE CENTRAL FINDING — we already run two policy architectures side by side

| Policy domain | Where it is stored | Scope | Effective-dated | Verified at |
|---|---|---|---|---|
| **Attendance policy** | `schools/{id}.attendancePolicy` **map** | **TENANT** | NO | `MY_Controller.php:1681`, `Attendance.php:524,2457` |
| **Leave types / policy** | `schools/{id}.leaveTypes` **map** | **TENANT** | NO | `Hr.php:282,291-294` |
| **Work schedule** | `workSchedules` + `staffScheduleAssignments` | **EMPLOYEE** | YES | `Work_schedule_service` |
| **Salary / compensation** | `staffSalaryAssignments` | **EMPLOYEE** | YES | W2 compensation core |

**Two of four domains are modern; two are legacy.** The two legacy ones are precisely
attendance and leave. This is not a design decision anyone made — it is the seam left by
building compensation (W2) and schedules (N-31…N-33) forward while leaving the older maps
untouched.

**Consequence:** a tenant can already give two employees different *schedules* and different
*salaries*, but cannot give them different shift timings, grace periods, overtime rules, or
leave quotas. The half-migration is the problem, not the direction.

## 2. Attendance policy — what a tenant-wide map cannot express

Fields currently in the single `attendancePolicy` map, and who really needs to differ:

| Setting | Realistic divergence inside ONE school |
|---|---|
| `weeklyOffs` | teaching Mon–Sat · support staff Mon–Fri · security 6-day rotating |
| shift start / end | teaching 08:00–15:00 · office 09:00–17:00 · security night shift |
| grace period | often stricter for teaching than for support |
| `allowWorkOnOff` | security/maintenance yes · teaching no |
| `autoAbsent` | usually uniform — genuinely tenant-level |
| half / full-day hours | differs for part-time and visiting faculty |
| capture method | GPS for field staff · biometric on campus |

Six of seven need to vary **within** a single school. Only `autoAbsent` is genuinely tenant-wide.

**This is the same root cause as N-23.** Payroll invented a calendar because no schedule
relationship existed. Attendance will keep hitting the same wall for shifts (P-012), rosters
(P-013) and per-employee policy (P-017) for exactly the same reason.

## 3. Leave policy — the same shape, different blast radius

`leaveTypes` is one map per school. That forces every employee onto the same quota table.

| Reality | Blocked today |
|---|---|
| Permanent 12 CL / Contract 6 CL | one quota table only |
| Probation: no EL until confirmation | no eligibility rule per group |
| Teaching gets vacation leave; support does not | type list is global |
| Maternity 26 wk only for eligible staff | type exists, eligibility not modelled |
| Quota changed for next leave-year only | no effective dating — editing rewrites history |

**The effective-dating absence is the more serious half.** Changing a quota today silently
changes what last year's balances *would* have been. Compensation already solved this with
`staffSalaryAssignments`; leave has no equivalent.

## 4. Payroll — the engine is not the gap; the surrounding model is

| Layer | Status |
|---|---|
| Money precision, `gross == net + deductions` | COMPLETE — integer paise |
| Attendance → payroll contract (`M` / `W`) | COMPLETE, guarded |
| Payroll → accounting (`Dr == Cr`) | COMPLETE |
| Salary assignment per employee, effective-dated | COMPLETE |
| **Working calendar per employee** | **fixed in this gate (N-23)** — 0 hardcoded rules remain |
| Pay period model | coupled to `session_year` (**academic** year) |
| Off-cycle / arrears / revisions | MISSING — all three |
| Statutory config per legal entity + state | ST-11 — no tenant `state` field exists |
| Bank disbursement export | MISSING — payment recorded, nothing exported |

**Payroll's remaining gaps are almost all "no effective-dated relationship" gaps.** Arrears,
retro revisions and salary-revision workflow are the same missing capability: *recompute a past
period against the policy that was in force then.* That is unbuildable while two of four policy
domains have no version history at all.

## 5. Structural comparison with the Asanify benchmark

Feature counts were given in the Master Register (50/164 COMPLETE). The **shape** difference
underneath them:

| Dimension | Asanify | ZenX today |
|---|---|---|
| Policy binding | policy objects **assigned** to employee groups | tenant map — *except* schedules + salary |
| Effective dating | throughout the employment record | only where W2 / N-31 added it |
| Pay period | independent payroll calendar | coupled to academic `session_year` |
| Legal entity | multi-entity, multi-state; drives statutory applicability | `school_id` **is** the entity; no `state` field |
| Employment record | employee ↔ assignment ↔ contract relationships | largely flat fields on a staff doc |

**Every feature gap in §4 and in the attendance list descends from rows 1, 2 and 5.**
Asanify models employment as effective-dated *relationships*; ZenX models it as *fields on a
document*. Closing feature gaps one at a time without closing that shape gap means rebuilding
the same scoping logic separately for shifts, leave, overtime and arrears.

## 6. THE DECISION

### Option A — leave policies tenant-wide, close feature gaps individually

Cheapest per feature. But P-012 shifts, P-013 rosters, P-017 per-employee policy, P-049 arrears
and P-051 revisions each then need their own scoping mechanism — **four to five bespoke
systems.** Not recommended, on maintenance grounds rather than preference.

### Option B — one assignable-policy pattern, generalised from what already exists (RECOMMENDED)

```
   catalogue      workSchedules · attendancePolicies · leavePolicies · salaryStructures
       |
       |  effective-dated ASSIGNMENT   (staff*Assignments)
       v
   resolution order:  employee  >  employment type  >  department / location  >  org default
       v
   ONE resolved policy for (employee, date)
```

Reuses `Work_schedule_service::effectiveAssignment()` semantics already built and tested:
refuses overlaps rather than resolving by read order, and returns a block state rather than a
silent default. The two remaining domains adopt an existing pattern instead of inventing one.

### Explicitly NOT to be made per-employee

| Domain | Correct scope | Why |
|---|---|---|
| Holiday calendar | organisation; location override at most | a holiday is a property of a place |
| Statutory config (PF / ESI / PT / TDS) | legal entity + state | legally scoped; per-person is wrong and unsafe |
| `autoAbsent`, month-lock | tenant | operational switches, not employment terms |

Scope each policy by what the real-world rule follows. Over-scoping is as wrong as under-scoping.

## 7. Recommended sequence — dependencies, not preference

```
1. Schedules + assignments admin surface, built as the GENERAL policy-assignment surface
   -> unblocks payroll for all 8 tenants · unblocks UAT Test 5 · enables N-36
2. N-35: migrate the 3 competing Attendance calendar authorities to the resolved calendar
3. Attendance policy -> assignable object    (closes P-012 / P-017, enables P-013)
4. Leave policy -> assignable + effective-dated   (closes P-028 / P-030 / P-032)
5. Effective-dated recompute   (unlocks P-049 arrears, P-051 revisions)
6. Tenant `state` field (ST-11) -> the BLOCKED 8 statutory items become configurable
```

Step 1 is small relative to the rest and is a hard prerequisite for 2–5. Steps 3 and 4 are where
"school vs organisational vs staff policy" is actually answered — as a resolution order, not as
three separate systems.

## 8. What is NOT recommended

- Do **not** build three parallel scoping mechanisms for attendance / leave / staff.
- Do **not** create a default schedule or policy to unblock payroll or UAT.
- Do **not** infer statutory policy, PT state, or leave eligibility from tenant data.
- Do **not** retrofit effective dating by editing existing maps in place — that rewrites history
  and breaks reproducibility of already-issued payslips.

---

**Nothing modified. No code, config, tenant data, seed, migration, commit, push or deploy.
PR0003 and all April evidence untouched. UAT remains paused at Test 4.**

**Decision required:** Option A or Option B, and whether step 1 begins now.
