# CONTROLLED PRODUCTION ADMISSION — PATH CENSUS AND EXECUTION MANIFEST (v2)

**Tenant:** `SCH_D94FE8F7AD` (IIT Kanpur) — the ONLY tenant this plan may touch
**Project:** `graderadmin` · **Session:** `2026-27`
**Status:** AWAITING APPROVAL — **no production write has been performed**
**Branch:** `integration/phase2` · nothing pushed, nothing deployed

v2 replaces v1 after an independent review and a full emulator dress rehearsal.
v1 was materially incomplete: it listed 12 writes; the real chain writes **20
collections**. Every expected document below is now **empirically derived** from
a rehearsal of the identical chain, not inferred from source.

`Production_admission_probe` hashes this file; editing it voids every
outstanding authorisation ticket.

---

## 0. AUTHORISATION GATE (unchanged, proven)

Five conditions; the fifth is a single-use ticket minted locally by
`php tools/prod_ticket.php issue <stage>`, bound to tenant + stage + this file's
sha256 + a short expiry, consumed **before** the write. No secret is ever
transmitted or quoted. Proven to fail closed on 9 negative cases and to pass on
the positive case with `writes: 0`.

---

## 1. REVIEW FINDINGS (v1 → v2)

### BLOCKER — must be decided before execution

**R1 · The emulator harness does not sandbox Firebase Auth.**
`Firebase::createFirebaseUser()` calls `$this->auth->createUser()` with **no
emulator seam**; `FIRESTORE_EMULATOR_HOST` governs Firestore only, and
`FIREBASE_AUTH_EMULATOR_HOST` is set nowhere in this repository. So
`Lifecycle_probe`'s destination guard — the fix for the 2026-08-31 incident —
protects Firestore and *nothing else*. Every "emulator" admission run has been
issuing **real production Auth calls**. Today's rehearsal attempted to create
`stu0001@schoolsync.app` in production Auth at 09:23:38; it failed **only**
because `_generatePassword()` returned `Zxp@` (4 chars) and Firebase requires 6.
Read-only Auth lookups confirm `stu0001@` and `stu0190@` are both **absent** —
nothing landed. But this is the same class of hole as the incident, and it was
luck, not design, that closed it.

**Consequence for this run:** the password is `Ucfirst(3 letters) + 4 DOB digits
+ '@'` = 8 characters whenever a DOB is supplied. A real admission supplies one.
So the production run **will create a real Firebase Auth account**
`stu0190@schoolsync.app`, uid `STU0190`, carrying `role=student` and
`school_id=SCH_D94FE8F7AD` custom claims. **No Firestore cleanup rail can remove
it.**

### HIGH

**R2 · No cleanup tool exists for this tenant.** v1 cited "the `incident_cleanup`
rails". That script is hard-coded to `SCH_GATE1/2/3` and the 2026-08-31 window,
and its deletable list omits `feeDemands`, `feeReceipts`, `accounting`,
`parents` and every index. It **cannot** clean this run. A tenant-scoped,
exact-path cleanup tool must be written and dry-run **before** execution, or
cleanup is not actually available.

**R3 · Chart of Accounts is mandatory, and v1 under-stated why.**
`Admission_collection::record_payment` writes the receipt **before** posting the
journal and **never throws**. With the tenant's current **0** `chartOfAccounts`,
the admission would produce a real ₹100 receipt with `journalPosted=false` and
only an error log — a receipt with no journal, in a live tenant. Seeding via the
real endpoint `Accounting::seed_default_chart` is therefore a hard prerequisite.
**Proven in rehearsal:** 65 accounts seeded; "Registration Fee" resolves to
`4020` by keyword; the journal posted balanced 100/100.

**R4 · Class-name drift.** `Classes::add_section` canonicalises `5` → **`Class
5th`**; `Fees::save_updated_fees` stores whatever it is given (it only validates
`^Class \S+$`). A chart saved as `Class 5` is orphaned and the admission is
refused with "No fee structure exists". Hit in rehearsal. **The production run
must use the canonical ordinal form `Class 5th` everywhere.** (Observation, not
fixed here: the saver should normalise. Whether the real UI can emit the
non-canonical form is unverified.)

**R5 · Total billed is ₹18,000, not ₹4,500.** The generator expands the fee
chart across the **full 12-month session**, not only the months present in the
chart. Rehearsal: a 3-month chart produced **24 demands** (12 months × 2 heads),
gross = net = balance = **18,000**. v1 implied only the charted months.

### MEDIUM

**R6 · Eight write surfaces missing from v1**, all confirmed in rehearsal:
`srNoIndex`, `admissionNoIndex`, `indexPhones`, `studentHistory`,
`feeAuditLogs`, `accountingClosingBalances`, `accountingIdempotency`,
`accountingAudit`, `accountingForensics`, `accountingCounters`,
`systemCounters`, plus a second counter family `feeCounters/_sys_SRNO_*`.

**R7 · The admission receipt counter is in `systemCounters`, not `feeCounters`.**
The source comment says `feeCounters/{schoolId}_admissionReceiptNo`; the
persisted reality is `systemCounters/{schoolId}_admissionReceiptNo` (+ `_claim_1`).
v1 would have verified the wrong path.

**R8 · Deleting a journal without its closing balances corrupts the books.**
`accountingClosingBalances/{school}_{session}_{code}` accumulates
`period_dr`/`period_cr` under CAS. Cleanup must remove journal **and** balances
together, or the tenant's books stay overstated. Safe here only because the
tenant has zero prior accounting history.

**R9 · Contradictory rollback rule.** v1 said "never touch `feeCounters`" while
the run *creates* `_sys_SRNO_*` and consumes `_sys_STU`. Restated: **never
rewind or delete allocator state; newly created counters remain.**

**R10 · Actor attribution.** The tenant has exactly one real administrator:
`SSA0001` (School Super Admin, *Shivam Rathore*) — the same person who
configured the admission fee. Attributing a synthetic test admission to a real
named human in a permanent `auditLogs` record would be a false record.
**Recommendation: run as `ZXPRODTEST-UAT` with role `School Super Admin`**, so
the permanent audit trail truthfully names a controlled harness.

**R11 · A failed stage still burns a global student ID.** `_nextStudentId()`
runs **before** the seat guard and the SRNO claim. `atomic_capacity_enabled` is
`true`, so the seat guard is live. A seat conflict or SRNO failure consumes
`STU0190` and refuses. The counter is never rewound; the gap is intentional.

### CLEARED (checked, no issue)

* **No notifications.** No `emit_push`, SMS or email anywhere in the admission
  chain — nothing reaches a real parent device.
* **`append_timeline` is a true no-op** when the `crmApplications` doc is absent
  (`casAppendBounded` returns `ok:false` and writes nothing). Rehearsal:
  `crmApplications` = 0 documents. No phantom CRM record.
* **No APAAR claim** — APAAR is optional and will not be supplied, so no
  national-identity index doc is written.
* **Tenant scoping is sound.** `school_name == school_id`, `parent_db_key =
  school_code ?: school_id`; the probe seeds all three to the one tenant.
  `parents`, `srNoIndex`, `admissionNoIndex`, `indexPhones` are all
  school-prefixed. Rehearsal produced **zero** documents under any other tenant.
* **Duplicate authorities are deliberate, not accidental.** `admissionReceipts`
  + `ADM{n}` (from `systemCounters`) is a separate series from `feeReceipts` +
  `F{n}` (from `feeCounters.receipt_seq`). Documented as intentional; must not
  be conflated during verification.

---

## 2. ADMISSION-FEE PROVENANCE — VERIFIED GENUINE

Persisted at `schools/SCH_D94FE8F7AD.admissionFee`:

```
enabled: true   amount: 100   currency: "INR"   label: "Registration Fee"
updated_at: "2026-07-16 14:15:41"   updated_by: "Shivam Rathore"
```

* **Not a code default.** No hard-coded `100` exists anywhere in the source; the
  only writer of this field is `School_config.php:6502` — the admin config
  screen.
* **Set by a named human**, six weeks before this workstream, and that human
  (`SSA0001`) is the tenant's sole registered administrator.
* **Not mine.** I have never written to this tenant; my activity begins
  2026-08-31.

**Verdict: genuine active configuration, not leftover UAT/test data.** Caveat
stated plainly: this proves *provenance*, not *business intent* — I cannot prove
the school really intends to charge ₹100, only that a real administrator
deliberately configured it through the real screen.

---

## 3. PATH CENSUS (unchanged from v1 in substance)

**Admission:** execute A1 `Sis::admission()` only. Excluded: public form, public
Razorpay path (real money; already live-proven), offline choice, lead
conversion. Already proven: bulk promotion/enrolment.
**Generation:** execute G2 (fee chart) → G1 (`assignInitialFees`) → G3
(admission fee). Excluded: bulk/whole-session generation, transport route
billing. Proven: manual generate, entitlement-priced generation.
**Collection:** execute C1 (cash counter receipt) and C4 (admission receipt).
Excluded: online payment, refunds, voids — all would manufacture real financial
events. Proven: discounted collection, refund-to-journal.
**Negative N1–N11:** all proven in emulator; **none to be tested in production**.
N1 (no fee structure) and N5 (no payment mode) were re-proven in today's
rehearsal, both refusing cleanly.

---

## 4. EXACT EXPECTED DOCUMENT SET — EMPIRICALLY DERIVED

Every line below was produced by the rehearsal of the identical chain. Doc-id
shapes are literal; `{S}` = `SCH_D94FE8F7AD`, `{Y}` = `2026-27`.

| # | stage | collection | expected docs | id shape | reversible |
|---|---|---|---|---|---|
| 1 | S3 | `chartOfAccounts` | **65** | `{S}_{code}` | yes |
| 2 | S1 | `sections` | 1 | `{S}_{Y}_Class 5th_Section A` | yes |
| 3 | S2 | `feeStructures` | 1 | `{S}_{Y}_Class 5th_Section A` | yes |
| 4 | S4 | `feeCounters` | 2 | `_sys_STU` (**189→190**), `_sys_STU_claim_190` | **NO** |
| 5 | S6 | `feeCounters` | 2 | `_sys_SRNO_{S}`, `_sys_SRNO_{S}_claim_1` | **NO** |
| 6 | S5 | `students` | 1 | `{S}_STU0190` | yes |
| 7 | S7 | `parents` | 1 | `{S}_STU0190` | yes |
| 8 | S6 | `srNoIndex` | 1 | `{S}_1` | doc yes; **SR No never reused** |
| 9 | S6 | `admissionNoIndex` | 1 | `{S}_2026_{6-char token}` | yes |
| 10 | S5 | `indexPhones` | 1 | `{S}_{phone}` | yes |
| 11 | S10 | `studentHistory` | 1 | `{S}_STU0190_{ts}_{hash}` | **NO — evidence** |
| 12 | S8 | `feeDemands` | **24** | random 20-hex | yes |
| 13 | S8 | `feeAuditLogs` | 1 | `AUD_{ts}_DEMAND_{hash}` | **NO — evidence** |
| 14 | S9 | `feeDefaulters` | 1 | `{S}_{Y}_STU0190` | yes |
| 15 | S12 | `systemCounters` | 2 | `{S}_admissionReceiptNo`, `_claim_1` | **NO** |
| 16 | S12 | `admissionReceipts` | 1 | `{S}_ADM000001` | yes (see R8) |
| 17 | S12 | `accounting` | 1 | `{S}_{Y}_JE_FEE_ADM000001` | yes (see R8) |
| 18 | S12 | `accountingClosingBalances` | 2 | `{S}_{Y}_1010`, `{S}_{Y}_4020` | yes (see R8) |
| 19 | S12 | `accountingIdempotency` | 2 | `{S}_ADM_COLLECT_STU0190`, `{S}_JE_FEE_ADM000001` | yes |
| 20 | S12 | `accountingAudit` / `accountingForensics` / `accountingCounters` | 1 / 1 / 2 | — | **audit: NO** |
| 21 | all | `auditLogs` | **6** | `{S}_AL_{ts}_{seq}` | **NO — evidence** |
| 22 | S5 | **Firebase Auth** | **1 account** | uid `STU0190`, `stu0190@schoolsync.app`, claims `role=student` + `school_id={S}` | **NOT Firestore — see R1** |

**Expected financial state after the run**

| quantity | expected |
|---|---|
| demands | 24 (12 months × {Tuition 1000, Transport 500}) |
| gross / net / balance | **18,000 / 18,000 / 18,000** |
| admission receipt | ADM000001, ₹100, cash, `journalPosted=true` |
| journal | `JE_FEE_ADM000001`, Dr 100 / Cr 100, `status=active` |
| closing balances | `1010` Dr 100 · `4020` Cr 100 |
| global STU counter | 189 → **190** |

**Invariants checked after every stage:** I-1 `gross − discount == net`;
I-2 `balance == max(0, net + fine − paid)`; I-3 no negative payable;
I-5 every document carries `schoolId={S}` and `session={Y}`; I-6 status agrees
with the numbers; plus `total_dr == total_cr` and the closing-balance deltas
equal the journal lines.

---

## 5. IRREVERSIBLE SIDE EFFECTS — THE COMPLETE LIST

1. Global student ID `189 → 190`. Never rewound, never reused. Burned even if a
   later stage refuses.
2. School SR-No counter `0 → 1` and the `srNoIndex` claim. SR No is never
   reused.
3. Admission-number token, and `systemCounters/{S}_admissionReceiptNo` `0 → 1`.
4. `auditLogs`, `studentHistory`, `feeAuditLogs`, `accountingAudit`,
   `accountingForensics` — evidence, deleted under no circumstances.
5. **The Firebase Auth account** — outside every Firestore rail (R1).

---

## 6. STAGE PROTOCOL, STOP CONDITIONS, ROLLBACK

One stage at a time: mint ticket → write → **independent** persisted-state read
→ invariant validation → internal report → next stage. Endpoint success is never
proof.

**Stop immediately** on: any document under a `schoolId` other than `{S}` · any
unexpected document · counter movement other than exactly +1 · any invariant
failure · `total_dr != total_cr` · `journalPosted=false` · a duplicate receipt or
journal · any read-after-write mismatch · any destination mismatch · any value
differing from §4. On a stop: **no repair, no delete, no compensating entry, no
retry by another route** — report and wait.

**Rollback**, if later authorised: exact-path deletes only, each re-verified
immediately before deletion, journal **and** its closing balances together,
never `auditLogs` / history / forensics, never a counter. Requires the
tenant-scoped cleanup tool of R2, which **does not yet exist**.

---

## 7. OPEN DECISIONS BEFORE EXECUTION

1. **R1 — the Firebase Auth account.** Options: (a) accept it as intentional
   test data and plan an explicit Auth deletion; (b) add a `FIREBASE_AUTH_EMULATOR_HOST`
   seam first so the harness genuinely sandboxes Auth; (c) proceed knowingly and
   leave the account. **Recommendation: (b) then (a)** — the seam closes a live
   hole of the same family as the 2026-08-31 incident, and it is the reason this
   run is currently stopped.
2. **R2 — build the tenant-scoped cleanup tool and dry-run it before writing**,
   so cleanup is real rather than assumed.
3. **R10 — actor id.** Recommend `ZXPRODTEST-UAT`, not `SSA0001`.
4. Confirm the class to admit into (`Class 5th` / `Section A`) and the fee-head
   amounts, given §4's **₹18,000** total rather than a 3-month figure.

---

## 8. WHAT THIS RUN DELIBERATELY DOES NOT DO

Never: touch Vikrant Public School or any other tenant · modify another tenant's
data · roll back or reuse the global student ID · perform a broad, prefix,
wildcard, collection-wide or timestamp-only delete · manufacture a refund,
cancellation, overpayment or any other financial event that did not naturally
occur · weaken, bypass or modify a safety guard · commit, push or deploy without
explicit permission for that specific change.
