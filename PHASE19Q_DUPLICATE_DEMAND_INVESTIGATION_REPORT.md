# PHASE 19Q-A — DUPLICATE DEMAND INVESTIGATION

**Date:** 2026-08-16 · **Branch:** `integration/phase2` · **Tenant:** `SCH_D94FE8F7AD` / `2026-27`

# NO PRODUCTION WRITES WERE PERFORMED.

Read-only throughout. No `--commit`, no `dueDate` written, no demand modified, no summary repair, no
delete, merge, balance, status, concession, receipt or accounting change. Nothing committed, pushed or
deployed. No other module touched.

---

## 1. HEADLINE — I WAS WRONG, AND THE BLOCK IS LIFTED

**The "duplicate demands" I reported do not exist. My analysis was flawed.**

I grouped the dry-run rows by **(student, fee head, amount)** and **omitted `periodKey`** — the single
field that distinguishes them. STU0004's twelve "duplicate" Tuition Fee ₹2000 rows are **twelve distinct
months**:

```
2026-04 April      2026-08 August     2026-12 December
2026-05 May        2026-09 September  2027-01 January
2026-06 June       2026-10 October    2027-02 February
2026-07 July       2026-11 November   2027-03 March
```

That is an ordinary monthly tuition schedule for the academic year. The same is true of the Library and
Computer Fee "duplicates".

**Decisive query — eligible demands sharing the same student + head + period: `0`.**

I halted a correctly-functioning repair on a bad grep. The dry-run gate worked; my reading of it did not.

## 2. PRODUCTION DRY-RUN EVIDENCE (read-only, unchanged)

```
scanned=780  eligible=334  ineligible=446  review=0  due_day=11  new_due=2026-09-11
```

## 3. DB-006 RESULTS — **it cannot see this data at all** (a real, separate finding)

DB-006 reads `Schools/{school}/{session}/Fees/Demands` via `shallow_get()` — the **retired RTDB** tree.
Live demands are in **Firestore `feeDemands`**.

**Every pre-existing integrity check reads RTDB:**

| Check | Data source |
|---|---|
| DB-001…DB-010 (all ten) | **RTDB `sessionRoot`** |
| **DB-011** (added Phase 19P) | **Firestore** |

Under the project's ZERO-RTDB rule that tree no longer holds fee data, so **`shallow_get()` returns
empty and all ten checks return `pass` with zero violations — vacuously.**

> **INTEG-RTDB-1 — HIGH.** The Fees integrity report has been giving **false assurance**. It reports
> "pass" not because the data is clean but because it is looking at a store that no longer exists.
> DB-011 is currently the only check reading live data. **This is unrelated to Target #1's repair and
> does not block it**, but it is a serious finding in its own right.

## 4. DUPLICATE-GROUP ANALYSIS

**Across all 780 demands** (grouping by student + head + amount + period):

| | |
|---|---|
| Duplicate groups | 229 |
| Demands in groups | 482 |
| Students affected | 9 |
| Nominal | ₹237,600 · **paid ₹0** |

**Among the 334 ELIGIBLE demands — the only ones the repair would touch:**

| Grouping | Duplicate groups |
|---|---|
| student + head **name** + amount + period | **0** |
| student + **`feeHeadId`** + period | **0** |
| student + head + period | **0** |

**The eligible set contains no duplicates by any definition tested.**

### What the 229 groups actually are

The largest group, field by field:

```
group "STU0005 | Computer Fee | 500 | 2026-04"   n=3
  018440ff52546db5d827               headId=FH_82AEA50B2D21EA8D  status=unpaid    created 2026-08-09 by SSA0001
  DEM_STU0005_202604_FH_E31856DB64CB headId=FH_E31856DB64CB      status=ARCHIVED  created 2026-05-28 by bug076b-class8-regen
  DEM_STU0005_202604_FH_E8C7CC38CF78 headId=FH_E8C7CC38CF78      status=ARCHIVED  created 2026-05-27 by SSA0001
```

Three **different `feeHeadId`s**, three **different `createdAt`**, and **two already `archived`**.

These are **superseded regeneration generations**, not duplicates: the fee chart was rebuilt (each rebuild
minting new fee-head IDs for the same logical head), demands were regenerated, and **the older ones were
correctly archived**. The archive mechanism did its job.

**Status distribution across all 780:** `archived 407 · unpaid 363 · paid 8 · partial 1 · cancelled 1`.
The repair already refuses archived, paid and cancelled — which is exactly why only 334 are eligible.

**Answering §2's questions:** (A) no — not true duplicates · (B) yes, superseded regeneration runs ·
(C) yes for the monthly instalments, which are legitimate · (D)/(E) partly, see §7 · (F) yes, archived ·
(G) no duplicate IDs · (H) only among archived · (I) not examined — single session in scope · (J) none
among eligible · (K) they differ by `feeHeadId`, `createdAt`, `createdBy` and `status`.

## 5. FIVE-STUDENT RECONSTRUCTION

| Student | Eligible pattern | Verdict |
|---|---|---|
| **STU0004** | Tuition ×12, Library ×12, Computer ×12 — **12 distinct months each** | legitimate monthly schedule |
| **STU0160** | Tuition/Library/Computer + Annual Fee, distinct periods | legitimate |
| **STU0005** | as above; older generations archived | legitimate |
| **STU0159** | as above | legitimate |
| **STU0006** | as above | legitimate |

**No student has two eligible demands for the same head in the same period.**

## 6. WRITE-PATH TRACING — eligible demands by `createdBy`

| Count | `createdBy` | Interpretation |
|---|---|---|
| 185 | `bug076b-class8-regen` | a **named regeneration run** from the BUG-076 investigation |
| 112 | `SSA0001` | School Super Admin — normal panel generation |
| 37 | `Shivam Rathore` | a named operator |

Every eligible demand traces to an identifiable generation event. **No orphaned or unattributable
creation path was found.**

## 7. TEST DATA vs REAL DATA

Evidence this is a **development/test tenant**:

- `scripts/check_stu0001_dues.js` is **hardcoded to `SCH_D94FE8F7AD` / `2026-27`** and describes itself
  as *"Nuke all fee-related Firestore data … so admin can re-generate demands and start a clean payment
  test flow … Only safe when the school has no other real receipts — **use in dev only**."*
- `createdBy=bug076b-class8-regen` — a bug-investigation regeneration.
- Only **12 students**; **₹0 paid** against the entire duplicate population; 8 paid demands in total.

**Assessment: development/UAT tenant, not a live fee-collecting school.** `SOURCE-VERIFIED` from the
repo, not inferred from the ID. I have **not** seen a definitive tenant-type flag, so this is stated as
strong evidence rather than proof. **The severity of INTEG-RTDB-1 is unaffected** — that defect is in
shared code and would apply to every school.

## 8. FINANCIAL EXPOSURE (read-only)

| Measure | Value |
|---|---|
| Eligible demands | **334** |
| Students affected | **9** |
| **Outstanding across eligible** | **₹320,000.00** |
| Paid against duplicate groups | **₹0.00** |
| Would be exposed to reminders after repair | 334 |
| Would be exposed to late fees **after 2026-09-11** | 334 |

**No loss is claimed.** ₹237,600 is a **nominal** figure spanning archived generations; it is not money
owed. The genuine outstanding figure for eligible demands is **₹320,000**, and — critically — it is
**not inflated by duplication**.

## 9. CLASSIFICATION

| | Category | Count | Blocks Target #1? |
|---|---|---|---|
| **A** | **Legitimate — safe to receive the dueDate repair** | **334** | **No** |
| B | Duplicate but harmless (superseded, archived) | 407 archived | No — already refused |
| C | Duplicate and financially dangerous | **0** | No |
| D | Ambiguous / needs a human decision | **0** (`review=0`) | No |
| E | Structural defect needing code correction | **INTEG-RTDB-1** | **No** — separate from the repair |
| F | Historical data needing separate migration | 0 | No |

## 10–11. REMEDIATION / CODE CHANGES

**No change to the repair is warranted.** Section 10 of the brief warned against reflexively adding
"if duplicate then skip" — and the evidence shows there is nothing to skip. Adding duplicate suppression
would have **hidden legitimate monthly instalments** and under-billed families.

**Recommended, but NOT implemented here (out of this phase's scope):** repoint DB-001…DB-010 at Firestore,
or retire them. They currently provide false assurance. That is a separate, authorized piece of work.

## 12. SRNO AUDIT — **PASS**

| Check | Result |
|---|---|
| Fee-demand identity uses `studentId`/`demandId` | **PASS** — confirmed in live documents |
| SRNO used as a financial foreign key | **PASS** — never |
| Roll No substituted for SRNO | **PASS** — no `rollNo` in any fee-repair file |
| SRNO changed by the repair | **PASS** — impossible; only `due_date` is written |
| Fee reports mislabelling Roll No as Adm No./SRNO | **PASS** — none touched |
| Student lookup confusing the four identifiers | **PASS** — queries key on `studentId` |

**No SRNO issue found. Does not block Target #1.**

## 13. ID-CARD REGRESSION — **PASS**

Not reopened. ID-card replacement demands carry their own `dueDate` and are therefore **inherently
ineligible** for this repair; version-scoped fee heads remain separate; the `4140` mapping is intact.
**No ID-card demand appeared in the eligible set.**

## 14. TESTS

**No code was changed in this phase**, so results are unchanged from the engineering phase:

| Metric | Value |
|---|---|
| TOTAL TESTS | 1469 |
| ASSERTIONS | 4057 |
| BASELINE FAILURES | **10** |
| NEW FAILURES | **0** |
| NEW TESTS | 0 |
| LINT | clean |
| RTDB SCAN | clean (no RTDB introduced) |
| RULES / INDEXES | untouched |

**Not "all tests pass"** — the 10 pre-existing failures remain.

## 15. EXACT BLOCKERS

| # | Item | State |
|---|---|---|
| 1 | Duplicate demands | **RESOLVED — they were not duplicates** |
| 2 | Production commit | **Awaiting your authorization** |
| 3 | Summary reconciliation | follows the commit |
| 4 | Post-run DB-011 | follows |
| 5 | UAT | no browser/device |
| 6 | **INTEG-RTDB-1** | **new, HIGH, separate** — does not block Target #1 |

## 16. TARGET #1 RECOMMENDATION

# A. SAFE TO RESUME TARGET #1

The eligible set is **334 demands across 9 students, ₹320,000 outstanding, zero duplicates, zero
`REQUIRES_REVIEW`**, every one traceable to a named generation event, on what the repository's own
tooling identifies as a development tenant.

The repair would set `dueDate = 2026-09-11` on those 334 and write nothing else.

**Target #1 remains READY WITH CONDITIONS — not CLOSED.** Remaining gates: your authorization for
`--commit`, the summary reconciliation, post-run DB-011, and UAT.

**Stopping here as instructed. No writes performed. Awaiting your decision.**
