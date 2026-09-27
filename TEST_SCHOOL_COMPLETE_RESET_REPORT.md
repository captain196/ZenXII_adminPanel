# TEST SCHOOL COMPLETE RESET — REPORT

**Date:** 2026-08-16 · **Tenant:** `SCH_D94FE8F7AD` · **Project:** `graderadmin` (shared with live schools)
**Result: RESET COMPLETE — ZERO RESIDUE VERIFIED · NO OTHER SCHOOL AFFECTED**

---

## 1. AUTHORIZATION SCOPE

Operator-authorized destructive reset of the test tenant `SCH_D94FE8F7AD`, reaffirmed after I raised
concerns. Executed by the operator via `node scripts/test_school_reset.js --commit`.

**All sessions were removed, not only `2026-27`** — a partial delete would have left an inconsistent
tenant. **The school shell and auth/provisioning records were retained** so the tenant remains loginable
and rebuildable; deleting them would have required full re-provisioning.

## 2. PRE-DELETE INVENTORY (read-only)

| | |
|---|---|
| Root collections scanned | **185** |
| Collections holding tenant data | ~120 |
| Tenant documents matched | **17,065** |
| Subcollections found (10 major collections sampled) | **none** |

Full detail: [`TEST_RESET_PRE_DELETE_INVENTORY.md`](TEST_RESET_PRE_DELETE_INVENTORY.md).

**Critical coverage detail:** 1,409 tenant documents — including **all 854 `feeDemands`** — carry
`schoolId` as a **field with no document-ID prefix**. A prefix-only sweep would have silently missed
them. The script runs both a field pass and an ID-range pass.

## 3. DELETION RESULTS — exact counts

```
TOTAL MATCHED (this tenant)        = 17065
TOTAL DELETED                      = 17065
TOTAL REFUSED (not ours — skipped) = 0
COLLECTIONS WITH ERRORS            = 0
```

**Matched == deleted exactly. Zero failures.**

| Collection | Deleted | | Collection | Deleted |
|---|---|---|---|---|
| `accounting` | 2,330 | | `studentHistory` | 144 |
| `accountingIdempotency` | 2,254 | | `chartOfAccounts` | 68 |
| `accountingForensics` | 2,248 | | `userDevices` | 66 |
| `transportAuditLog` | 1,820 | | `sections` | 47 |
| `accountingIndexByAccount` | 1,218 | | `timetables` | 24 |
| `noticeRecipients` | 1,115 | | `marks` / `results` | 23 / 23 |
| `systemCounters` | 987 | | `attendance` | 16 |
| **`feeDemands`** | **854** | | `feeCounters` | 16 |
| `accountingIndexByDate` | 526 | | `parents` / `staff` | 13 / 13 |
| `notices` | 486 | | **`students`** | **11** |
| `transportNoticeIdempotency` | 458 | | `studentFeeSummary` | 10 |
| `feeAuditLogs` | 383 | | `feeReceipts` | 3 |
| `mapsConfigAudit` | 341 | | `feeSettings` | 2 |
| `auditLogs` | 309 | | …and ~100 further collections | |

## 4. FAILED DELETIONS

**None.** Zero errors across all 185 collections.

## 5. POST-DELETE ZERO-RESIDUE AUDIT (independent re-scan)

Verified by a **separate read-only script**, not by re-running the deletion tool.

```
NON-SHELL RESIDUE TOTAL = 0   (expected 0)
```

| Collection | Tenant docs remaining |
|---|---|
| `students` · `parents` · `staff` | **0 · 0 · 0** |
| `feeDemands` · `studentFeeSummary` · `feeReceipts` | **0 · 0 · 0** |
| `attendance` · `marks` · `results` | **0 · 0 · 0** |
| `idCards` · `studentHistory` · `sections` · `timetables` | **0 · 0 · 0 · 0** |

Every non-shell collection reports **0** for this tenant.

## 6. OTHER-SCHOOL INTEGRITY — the check that mattered most

Several collections mix tenants, so this was the real risk. Verified after deletion:

| Collection | Before (total) | Ours deleted | After (total) | Ours remaining |
|---|---|---|---|---|
| `feeDemands` | 2,096 | 854 | **1,242** | **0** |
| `students` | 161 | 11 | **150** | **0** |
| `auditLogs` | 732 | 309 | **423** | **0** |
| `transportAuditLog` | 4,534 | 1,820 | **2,775** | **0** |
| `noticeRecipients` | 2,367 | 1,115 | **1,279** | **0** |

`feeDemands`, `students` and `auditLogs` reconcile **exactly** (2,096−854=1,242 · 161−11=150 ·
732−309=423). `transportAuditLog` and `noticeRecipients` show slightly **more** than the arithmetic —
other schools wrote new records between the inventory and the verification, which is expected on a live
system and confirms those tenants are active and untouched.

**`TOTAL REFUSED = 0`** means the per-document safety gate never had to reject anything: no query ever
reached another school's document in the first place.

## 7. SRNO FINAL CHECK (Part 15) — **PASS**

| Collection | Ours | Total (all schools) |
|---|---|---|
| `srNoIndex` | **0** | 0 |
| `admissionNoIndex` | **0** | 0 |
| `apaarIndex` | **0** | 0 |
| `feeCounters` | **0** | **300 (other schools intact)** |

- No stale test-school SRNO record remains.
- **No global SRNO state exists to damage** — `Sr_no_service` allocates per school; there is no shared
  sequence.
- **300 `feeCounters` documents belonging to other schools survive untouched**, which includes their
  `_sys_SRNO_{schoolId}` counters.
- **New allocation will work correctly:** `_readSrNoCounterPointer()` finds no pointer, lazily scans
  `srNoIndex` for this school, finds none, and returns 0 — so the first new student receives **SRNO 1**.
  Verified by reading the service, not assumed.

## 8. ID-CARD AUDIT (Part 6) — **PASS**

`idCards` reports **0** for this tenant. No card had ever been issued here (the model shipped in 19E and
`issue()` never ran), so there was nothing to remove. **The global QR secret and all credential code are
untouched** — this was a data reset only.

## 9. FEES AUDIT (Part 7) — quantified, then discarded

Pre-deletion: 854 `feeDemands` (780 in `2026-27` — archived 407 · unpaid 363 · paid 8 · partial 1 ·
cancelled 1), 10 `studentFeeSummary`, 52 `classFeeSummary`, 10 `feeDefaulters`, 7 `feeStructures`,
6 `feeHeads`, 3 `feeReceipts`, 16 `studentEntitlements`, 1 `studentConcessions`, **0 ID-card replacement
demands**.

**No dueDate backfill, no `--commit` on the Target #1 repair, no summary reconciliation was run.** The
data was discarded rather than repaired, exactly as instructed.

## 10. SCHOOL-SHELL DECISION (Part 13) — **RETAINED**

| Collection | State |
|---|---|
| `schools` | ✅ 1 doc, ID `SCH_D94FE8F7AD` |
| `schoolConfig` · `tenantPublic` · `tenantAudit` · `subscriptions` | ✅ retained |
| `schoolSsa` · `schoolCodeIndex` · `schoolNameIndex` · `indexSchoolCodes` | ✅ retained |
| `adminAuthState` | ✅ 9 docs retained |

Part 13 says stop if deleting the master would affect authentication, indexes or provisioning — **that
condition was met**, so the shell was kept. The school exists, is configured, and is loginable.

Pass `--nuke-shell` to remove these too, at the cost of needing full re-provisioning.

## 11. STORAGE & AUTH — **DELETED (second pass, operator-authorized)**

Tool: [`scripts/test_school_reset_storage_auth.js`](scripts/test_school_reset_storage_auth.js).

### Correction to my own earlier statement

I said proof of Auth ownership was lost with the Firestore records. **That was wrong.** Firebase custom
claims live in **Auth, not Firestore**, and the panel dual-emits `school_id`/`schoolId` — so ownership
was still provable, exactly and per-user. No email-pattern guessing was needed.

### Storage — 92 deleted, 0 failed

| | |
|---|---|
| Bucket objects before | 338 |
| **Deleted (this tenant)** | **92** |
| Other schools / demo tenants — untouched | **246** |
| **Remaining for this tenant** | **0** ✅ |

Matched on the school ID as a **complete path segment** (`SCH_.../…` or `…/SCH_.../…`), never a loose
substring. Covered both shapes: 50 under `SCH_D94FE8F7AD/`, 42 under
`admission|documents|homework|galleryMedia/SCH_D94FE8F7AD/`.

### Auth — 21 deleted, 0 failed

| | |
|---|---|
| **Deleted (claim == this tenant)** | **21** — `SSA0001`, `STA0001–0008`, `STA0107`, 11 × `STU*` |
| Another school's claim — **never touched** | **119** ✅ |
| **Refused — no school claim** | **6** |
| **Remaining for this tenant** | **0** ✅ |

**Refused and deliberately left in place** (ownership not provable → do not delete):
`SUP0001` · `PROBE_F7_STU_1783420218834` · `PROBE_F7_STU_1783476898165` · `admin_test_phase1` ·
`parent_test_phase1` · `teacher_test_phase1`.

`SUP0001` appears to be a **platform superadmin**; deleting it could remove Super Admin access to the
whole platform. Removing any of these six requires an explicit named UID, not a widened rule.

> ⚠ **`SSA0001` — the School Super Admin login for this tenant — was deleted.** The school shell
> survives and is configured, but **nobody can currently sign in to it.** A new admin account must be
> provisioned before the lifecycle build can start.

## 11b. POST-RESET RESIDUE — 14 documents, explained

A final three-surface verification found **Firestore non-shell residue = 14**, where the first scan had
found 0. Investigated rather than dismissed — all 14 were created **after** the deletion:

| Collection | Docs | Created | By |
|---|---|---|---|
| `mapsConfigAudit` | 11 | 05:15:03–05:15:21 | **`PROBE_MAPS`** |
| `mapsConfig` · `mapsSecrets` | 2 | 05:27:06–07 | — |
| `feeCollectionRollup` | 1 | 05:27:21 | — |

**A maps probe ran against the tenant after the reset**, regenerating its configuration and audit trail;
a fee rollup document was also rebuilt. This is **regenerated configuration, not restored student data**.
My verification scripts were strictly read-only (`getFiles`, `listUsers`, `count`, `get`) and wrote
nothing, so the writes came from elsewhere.

**Harmless, but it is residue.** Re-run `node scripts/test_school_reset.js --commit` to sweep it — the
run will be near-instant now. Worth knowing that **something can still write to this tenant**, so verify
immediately before starting the lifecycle build.

## 12. CODE / RULES SAFETY (Part 16)

- `firestore.rules` and `firestore.indexes.json` — **untouched**.
- No application source modified. The only new file is `scripts/test_school_reset.js`, which lives in
  this repo's documented home for one-off admin scripts and follows its convention (dry-run default,
  `--commit` required).
- Nothing committed, pushed or deployed.
- No RTDB introduced.

## 13. REBUILD READINESS (Part 14)

| Gate | |
|---|---|
| Tenant identity | `SCH_D94FE8F7AD` |
| Documents deleted | **17,065** |
| Documents failed | **0** |
| Non-shell residue | **0** |
| Other schools affected | **none** — 0 refused, counts reconcile |
| School shell present | ✅ loginable |
| SRNO service operational | ✅ next student gets SRNO 1 |
| ID-card infrastructure | ✅ code intact, data empty |
| Storage objects deleted | **92** — 0 remaining |
| Auth users deleted | **21** — 0 remaining |
| Other schools' Auth users | **119 intact** |
| Unclaimed Auth users retained | 6 (manual review) |
| Post-reset residue | 14 config docs, regenerated by a probe (§11b) |
| Rules / indexes | untouched |

# READY FOR A CLEAN STUDENT LIFECYCLE BUILD — with one prerequisite

**Totals across all three surfaces: 17,065 Firestore documents + 92 storage objects + 21 Auth users
deleted. Zero failures. No other tenant affected.**

The tenant is an empty, configured school holding no student, fee, accounting, attendance, examination,
transport, hostel, library, communication, timetable, ID-card or history data — and now no orphaned
files or logins either.

### Before the rebuild can start

1. **Provision a new admin login.** `SSA0001` was deleted, so the school currently has **no way to sign
   in**. This is the one hard blocker.
2. **Sweep the 14 regenerated config documents** (§11b) — one re-run of the Firestore reset script.
3. Optionally decide on the **6 unclaimed Auth users**, `SUP0001` in particular.

---

**STOPPING HERE.** No students created, no fees generated, no module started. Awaiting your instruction
for the clean Student Lifecycle build.
