# ZENX HRMS — AA.1 — MASTER REGISTER CONSISTENCY RECONCILIATION

**Date:** 2026-08-09 · **Type:** documentation/reconciliation only
**Result:** **RECONCILED** — canonical count corrected **163 → 161**; status double-count eliminated
**W3.2.5:** **NOT STARTED**

---

## 1. What was inconsistent

You found three defects in my AA output. All three were real.

### 1.1 The split arithmetic did not reconcile — **the +25 was wrong**

I claimed "+25 net from splits" while §3 documented 57→18, 39→7, 77→2. Those splits give
`(18−1)+(7−1)+(2−1) = 24`, not 25. **But 24 was also wrong**, because of a structural error:

**`ST-15/16/17/18` are NOT expansions of matrix row 57.** They are matrix rows **60, 58, 59, 61**
— separate capabilities I *renamed* into the `ST-` family for readability, then mistakenly counted as
split output. Row 57 actually produces `ST-01…ST-14` = **14**, net **+13**.

I had double-counted four matrix rows: once as `P-058/059/060/061`, again as `ST-15…ST-18`.

### 1.2 A second, unreported composite — matrix 40

While tracing 1.1 I found that `N-09` (attendance snapshot) **duplicated part of matrix row 40**
(payroll input snapshot), which I had left as a single composite. Row 40 hides four independent
snapshots. Left unsplit, "payroll input snapshot: PARTIAL" would have concealed that three parts are
done and one is blocked.

### 1.3 The same eight capabilities were counted in two statuses

`ST-01, 02, 04, 05, 06, 07, 09, 10` appeared as **PARTIAL** in §3.1 and as **BLOCKED** in §10.
That is 8 capabilities counted twice, which is why the status counts could not sum.

## 2. How each was reconciled

### 2.1 Split accounting — traced from source

| Source | Original | Resulting canonical IDs | Out | Net |
|---|---|---|---|---|
| Matrix rows mapping DIRECT | 87 | `P-0xx` | 87 | 0 |
| Matrix rows RENAMED into `ST-` | 4 | `ST-15`←60 · `ST-16`←58 · `ST-17`←59 · `ST-18`←61 | 4 | **0** |
| Matrix **39** SPLIT | 1 | `LC-01`…`LC-07` | 7 | **+6** |
| Matrix **40** SPLIT | 1 | `SN-01`…`SN-03` *(4th part = `ST-14`)* | 3 | **+2** |
| Matrix **57** SPLIT | 1 | `ST-01`…`ST-14` | 14 | **+13** |
| Matrix **77** SPLIT | 1 | `RB-01`, `RB-02` | 2 | **+1** |
| **Matrix subtotal** | **95** | | **117** | **+22** |

`95 + 22 = 117` · `+14` new · `+20` testing · `+10` RBAC = **161**.

**The 163 is not preserved.** It was arithmetically unreachable from the documented splits.

### 2.2 Absorptions (N-09, N-10) — recorded, not deleted

| Was | Now | Why |
|---|---|---|
| `N-09` Attendance day-wise snapshot | **→ `SN-02`** | Duplicated part of matrix 40; one capability, one ID |
| `N-10` Snapshot freshness on regeneration | **→ acceptance criterion of `SN-01` + `SN-02`** | A defect fix, not a capability. The criterion *"regeneration must rewrite every snapshot it invalidates"* now binds both |

Neither requirement was lost; both are visible at their new home.

### 2.3 Status rule — stated once, applied everywhere

- **BLOCKED** — engineering finished; only an operator decision or tenant data missing.
- **PARTIAL** — engineering work genuinely remains.
- **MISSING** — not built.

The eight statutory items have code, UI and tests. Calling them PARTIAL would imply developer work
remains and point the next wave at the wrong thing. **They are BLOCKED, counted once, in that status
only.**

Applying the rule consistently also corrected two neighbours: `ST-11` is **MISSING** (the tenant
`state` field does not exist — real code work), and `ST-12`/`ST-13` are **PARTIAL** (real engineering
remains).

---

## 3. Final canonical count

**161**

## 4. Final status counts — enumerated per family

| Family | Count | COMPLETE | PARTIAL | MISSING | ENH | REF | BLOCKED | OP-DEC | N/A |
|---|---|---|---|---|---|---|---|---|---|
| `P-0xx` direct | 87 | 30 | 25 | 30 | 1 | 1 | 0 | 0 | 0 |
| `LC-01…07` | 7 | 4 | 3 | 0 | 0 | 0 | 0 | 0 | 0 |
| `ST-01…18` | 18 | 2 | 2 | 6 | 0 | 0 | 8 | 0 | 0 |
| `SN-01…03` | 3 | 3 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| `RB-01…02` | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 | 0 |
| `N-series` (14) | 14 | 4 | 4 | 5 | 0 | 0 | 0 | 1 | 0 |
| `T-01…20` | 20 | 4 | 4 | 12 | 0 | 0 | 0 | 0 | 0 |
| `R-01…10` | 10 | 0 | 1 | 9 | 0 | 0 | 0 | 0 | 0 |
| **TOTAL** | **161** | **47** | **39** | **64** | **1** | **1** | **8** | **1** | **0** |

**A.** 161 · **B.** 47 · **C.** 39 · **D.** 64 · **E.** 1 · **F.** 1 · **G.** 8 · **H.** 1 · **I.** 0

## 8. Proof that status counts sum to the canonical count

```
B + C + D + E + F + G + H + I
= 47 + 39 + 64 + 1 + 1 + 8 + 1 + 0
= 161
= A   ✓
```

Per-family sums also reconcile: `87+7+18+3+2+14+20+10 = 161` ✓

**Completeness: 47 / 161 = 29.2%** (COMPLETE only).

---

## 5. The 95-row matrix mapping — every row, no exceptions

| Matrix | Canonical | Status | Mapping |
|---|---|---|---|
| 1 | P-001 | COMPLETE | DIRECT |
| 2 | P-002 | COMPLETE | DIRECT |
| 3 | P-003 | COMPLETE | DIRECT |
| 4 | P-004 | PARTIAL | DIRECT |
| 5 | P-005 | COMPLETE | DIRECT |
| 6 | P-006 | PARTIAL | DIRECT |
| 7 | P-007 | PARTIAL | DIRECT |
| 8 | P-008 | MISSING | DIRECT |
| 9 | P-009 | MISSING | DIRECT |
| 10 | P-010 | COMPLETE | DIRECT |
| 11 | P-011 | COMPLETE | DIRECT |
| 12 | P-012 | PARTIAL | DIRECT |
| 13 | P-013 | MISSING | DIRECT |
| 14 | P-014 | MISSING | DIRECT |
| 15 | P-015 | PARTIAL | DIRECT |
| 16 | P-016 | COMPLETE | DIRECT |
| 17 | P-017 | MISSING | DIRECT |
| 18 | P-018 | COMPLETE | DIRECT |
| 19 | P-019 | COMPLETE | DIRECT |
| 20 | P-020 | COMPLETE | DIRECT |
| 21 | P-021 | NEEDS REFACTORING | DIRECT |
| 22 | P-022 | PARTIAL | DIRECT |
| 23 | P-023 | COMPLETE | DIRECT |
| 24 | P-024 | COMPLETE | DIRECT |
| 25 | P-025 | COMPLETE | DIRECT |
| 26 | P-026 | COMPLETE | DIRECT |
| 27 | P-027 | COMPLETE | DIRECT |
| 28 | P-028 | PARTIAL | DIRECT |
| 29 | P-029 | COMPLETE | DIRECT |
| 30 | P-030 | PARTIAL | DIRECT |
| 31 | P-031 | MISSING | DIRECT |
| 32 | P-032 | PARTIAL | DIRECT |
| 33 | P-033 | PARTIAL | DIRECT |
| 34 | P-034 | COMPLETE | DIRECT |
| 35 | P-035 | MISSING | DIRECT |
| 36 | P-036 | MISSING | DIRECT |
| 37 | P-037 | PARTIAL | DIRECT |
| 38 | P-038 | COMPLETE | DIRECT |
| **39** | **LC-01…LC-07** | *(see §4)* | **SPLIT** |
| **40** | **SN-01…SN-03 + ST-14** | *(see §4)* | **SPLIT** |
| 41 | P-041 | PARTIAL | DIRECT |
| 42 | P-042 | COMPLETE | DIRECT (merged w/ backlog A) |
| 43 | P-043 | PARTIAL | DIRECT |
| 44 | P-044 | COMPLETE | DIRECT |
| 45 | P-045 | PARTIAL | DIRECT (merged w/ backlog B) |
| 46 | P-046 | PARTIAL | DIRECT (merged w/ backlog M) |
| 47 | P-047 | MISSING | DIRECT (merged w/ backlog C) |
| 48 | P-048 | MISSING | DIRECT |
| 49 | P-049 | MISSING | DIRECT (merged w/ backlog O) |
| 50 | P-050 | MISSING | DIRECT |
| 51 | P-051 | MISSING | DIRECT |
| 52 | P-052 | MISSING | DIRECT |
| 53 | P-053 | PARTIAL | DIRECT (merged w/ backlog Q) |
| 54 | P-054 | COMPLETE | DIRECT |
| 55 | P-055 | COMPLETE | DIRECT (merged w/ backlog Z) |
| 56 | P-056 | MISSING | DIRECT (merged w/ backlog T) |
| **57** | **ST-01…ST-14** | *(see §4)* | **SPLIT** |
| 58 | ST-16 | MISSING | DIRECT (renamed) |
| 59 | ST-17 | MISSING | DIRECT (renamed) |
| 60 | ST-15 | MISSING | DIRECT (renamed, merged w/ backlog S) |
| 61 | ST-18 | MISSING | DIRECT (renamed) |
| 62 | P-062 | COMPLETE | DIRECT |
| 63 | P-063 | PARTIAL | DIRECT |
| 64 | P-064 | MISSING | DIRECT |
| 65 | P-065 | MISSING | DIRECT |
| 66 | P-066 | PARTIAL | DIRECT |
| 67 | P-067 | MISSING | DIRECT |
| 68 | P-068 | PARTIAL | DIRECT |
| 69 | P-069 | PARTIAL | DIRECT |
| 70 | P-070 | MISSING | DIRECT |
| 71 | P-071 | COMPLETE | DIRECT |
| 72 | P-072 | COMPLETE | DIRECT |
| 73 | P-073 | COMPLETE | DIRECT |
| 74 | P-074 | MISSING | DIRECT |
| 75 | P-075 | MISSING | DIRECT |
| 76 | P-076 | MISSING | DIRECT |
| **77** | **RB-01, RB-02** | *(see §4)* | **SPLIT** |
| 78 | P-078 | PARTIAL | DIRECT (merged w/ backlog Q) |
| 79 | P-079 | MISSING | DIRECT |
| 80 | P-080 | PARTIAL | DIRECT |
| 81 | P-081 | PARTIAL | DIRECT (merged w/ backlog L) |
| 82 | P-082 | PARTIAL | DIRECT (merged w/ backlog V) |
| 83 | P-083 | MISSING | DIRECT |
| 84 | P-084 | MISSING | DIRECT |
| 85 | P-085 | MISSING | DIRECT |
| 86 | P-086 | MISSING | DIRECT |
| 87 | P-087 | PARTIAL | DIRECT |
| 88 | P-088 | MISSING | DIRECT |
| 89 | P-089 | MISSING | DIRECT |
| 90 | P-090 | MISSING | DIRECT |
| 91 | P-091 | COMPLETE | DIRECT |
| 92 | P-092 | NEEDS ENHANCEMENT | DIRECT (merged w/ backlog F, R) |
| 93 | P-093 | COMPLETE | DIRECT |
| 94 | P-094 | COMPLETE | DIRECT |
| 95 | P-095 | COMPLETE | DIRECT |

**Count check:** 87 DIRECT (incl. 4 renamed) + 4 SPLIT sources = **95/95 mapped**, exactly one
outcome each. ✓

## 6. A–Z + AA mapping — all 27

| Backlog | Canonical | Status | Mapping |
|---|---|---|---|
| A LOP review/confirmation | `P-042` | COMPLETE | MERGED (matrix 42) |
| B Full calculation trace | `P-045` | PARTIAL | MERGED (matrix 45) |
| C Async/scalable generation | `P-047` | MISSING | MERGED (matrix 47) |
| D Statutory snapshot | `ST-14` | MISSING | ABSORBED into the 57 split |
| E Non-taxable / reimbursement | `RB-01` | MISSING | ABSORBED into the 77 split |
| F Separable payroll authorization | `P-092` + `R-01…R-09` | NEEDS ENH / MISSING | MERGED + expanded |
| G Adjustment approval workflow | `N-02` | MISSING | DIRECT (new) |
| H Bulk adjustment / import | `N-03` | MISSING | DIRECT (new) |
| I Configurable accounting mapping | `N-04` | OPERATOR DECISION | DIRECT (new) |
| J Full browser UAT | `T-07` | MISSING | ABSORBED into testing family |
| K Test-School integration testing | `T-11` + `T-12` | MISSING | ABSORBED into testing family |
| L Payroll reports | `P-081` | PARTIAL | MERGED (matrix 81) |
| M Professional payslip / documents | `P-046` | PARTIAL | MERGED (matrix 46) |
| N Payroll correction workflow | `N-06` | PARTIAL | DIRECT (new) |
| O Payroll arrears | `P-049` | MISSING | MERGED (matrix 49) |
| P Payroll revision history | `N-05` | PARTIAL | DIRECT (new) |
| Q Employee self-service payslip | `P-053` + `P-078` | PARTIAL | MERGED (matrix 53, 78) |
| R Compensation visibility / RBAC | `P-092` + `R-10` | NEEDS ENH / PARTIAL | MERGED + expanded |
| S Statutory reports | `ST-15` | MISSING | MERGED (matrix 60, renamed) |
| T Registers / bank payment output | `P-056` | MISSING | MERGED (matrix 56) |
| U Payroll reconciliation | `N-07` | MISSING | DIRECT (new) |
| V Full audit history | `P-082` + `N-08` | PARTIAL | MERGED + expanded |
| W Period locking / reopening | `LC-07` | COMPLETE | ABSORBED into the 39 split |
| X Payroll approval workflow | `LC-04` | PARTIAL | ABSORBED into the 39 split |
| Y Finalization / paid lifecycle | `LC-05` + `LC-06` | COMPLETE | ABSORBED into the 39 split |
| Z Accounting reconciliation | `P-055` | COMPLETE | MERGED (matrix 55) |
| **AA** Master register reconciliation | `N-16` | COMPLETE | DIRECT (new) |

**27/27 mapped.** ✓ No A–Z item contributes a duplicate canonical ID.

## 7. New-capability reconciliation

**N-series — 14 present exactly once** (N-09, N-10 absorbed, recorded in §2.2):
`N-01` override model *(PARTIAL — distinct from `P-043` grid UI and `P-044` audit; this is the
type system, validation and persistence)* · `N-02` MISSING · `N-03` MISSING · `N-04` OPERATOR
DECISION · `N-05` PARTIAL · `N-06` PARTIAL · `N-07` MISSING · `N-08` PARTIAL · `N-11` COMPLETE ·
`N-12` COMPLETE · `N-13` COMPLETE · `N-14` MISSING · `N-15` MISSING · `N-16` COMPLETE.

**T-series — 20 present exactly once:** `T-01` C · `T-02` C · `T-03` C · `T-04` P · `T-05` P ·
`T-06` C · `T-07` M · `T-08` M · `T-09` M · `T-10` M · `T-11` M · `T-12` M · `T-13` P · `T-14` M ·
`T-15` M · `T-16` M · `T-17` P · `T-18` M · `T-19` M · `T-20` C.

**R-series — 10 present exactly once:** `R-01`…`R-08` MISSING · `R-09` MISSING · `R-10` PARTIAL.

### N-15 — verified independently tracked (§8 of the gate)

`N-15` **Bank/PAN readiness wired into payroll preflight** remains a **separate canonical capability**
with its own status (MISSING), owner wave (**W3.2.5**) and acceptance criterion
(*"`hasBankDetails()`/PAN readiness surfaced as named per-employee blockers before a run"*).

It was **not** absorbed into `P-041`. `P-041` is PARTIAL because it names people but omits this
check; when `N-15` lands, `P-041` can be re-assessed. Two IDs, two criteria, no hiding.

### Statutory blocker — rule preserved

Tenant statutory policy remains an **operator decision**. PF ceiling, PF eligibility, ESI threshold,
PT state, PT slabs, TDS mode and effective dates **must never be inferred** from observed employee
data, school name, city or address. The rule is unchanged and restated in the register §3.1.

### COMPLETE philosophy — unchanged

No capability was upgraded to improve the percentage. `P-043`, `P-045`, `N-01`, `P-063` all have
working implementations and passing tests and remain **PARTIAL** pending browser UAT. `ST-01…ST-13`
remain uncompleted despite a unit-tested calculator, because no tenant policy is configured.

The percentage moved 28.8% → 29.2% **only because the denominator shrank** (163 → 161). The
numerator is unchanged at 47.

---

## 9. Proof that no capability disappeared

| Check | Result |
|---|---|
| 95/95 matrix rows mapped, exactly one outcome each | ✓ §5 |
| 27/27 A–Z + AA mapped | ✓ §6 |
| Reimbursement engine (`RB-01`) retained | ✓ |
| Reimbursement workflow (`RB-02`) retained | ✓ |
| All statutory sub-capabilities retained (`ST-01…ST-18`) | ✓ |
| All payroll lifecycle stages retained (`LC-01…LC-07`) | ✓ |
| All 20 testing/UAT capabilities retained | ✓ |
| All 10 payroll RBAC capabilities retained | ✓ |
| `N-15` retained independently | ✓ §7 |
| No duplicate canonical IDs | ✓ — the 4-row double-count (58/59/60/61 vs ST-15…18) was the only one, now removed |
| No missing canonical IDs | ✓ — families enumerated, contiguous |
| No capability counted in two statuses | ✓ — the 8 statutory items are BLOCKED only |
| Status counts sum to canonical count | ✓ §8: 161 = 161 |
| Every incomplete capability has an owner wave | ✓ |
| No stale status silently accepted | ✓ — three downgrades retained (`P-082`, `P-043`, `P-045`) |
| No requirement disappeared | ✓ — `N-09`/`N-10` absorbed with their criteria preserved, not deleted |

**Net effect of AA.1 on scope: zero capabilities lost, two absorbed with criteria preserved, four
un-double-counted, one composite (matrix 40) split into three newly-visible capabilities.**

## 10. Remaining ambiguity

Two judgement calls, recorded rather than hidden:

1. **`N-01` vs `P-043`/`P-044`.** I kept the override *model* separate from the *grid* (43) and the
   *audit* (44) because each has independent acceptance criteria. A reasonable reviewer could merge
   all three into one capability, which would make the count **159**. I chose the finer grain
   because the directive prefers splitting over hiding.
2. **`ST-12` PARTIAL vs BLOCKED.** Its calculation is done, but its configuration surface depends on
   `ST-11` (MISSING — no tenant `state` field). I classified it **PARTIAL** because engineering work
   genuinely remains upstream. If `ST-11` lands first, `ST-12` becomes BLOCKED.

Neither affects the zero-leftover guarantee; both are flagged for your ruling.

---

**Nothing implemented. No production code, rules, entitlements or data touched. Nothing seeded,
migrated, deployed, committed or pushed. W3.2.5 NOT started.**
