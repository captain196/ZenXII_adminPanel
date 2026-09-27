# TEST SCHOOL RESET — PRE-DELETE INVENTORY

**Date:** 2026-08-16 · **Tenant:** `SCH_D94FE8F7AD` · **Session named:** `2026-27`

# NOTHING HAS BEEN DELETED. NO WRITES PERFORMED.

Read-only inventory only. Part 1 says *"DO NOT DELETE YET"*, and Part 10 says *"If any item is
uncertain: STOP."* **Four items are uncertain (§5). I am stopping there.**

---

## 1. SCALE

| | |
|---|---|
| Root collections in the database | **185** |
| Collections holding data for this tenant | **~120** |
| **Documents matching `schoolId == SCH_D94FE8F7AD`** | **17,077** |
| Documents matching doc-ID prefix `SCH_D94FE8F7AD_` | **15,668** |

The **1,409-document gap** matters: those collections key the tenant **only by field**, not by document
ID. Deleting by ID prefix alone would silently miss them.

## 2. LARGEST TENANT-OWNED COLLECTIONS (by `schoolId`)

| Collection | Tenant docs | ID-prefixed | Note |
|---|---|---|---|
| `accounting` | 2,330 | 2,330 | |
| `accountingIdempotency` | 2,254 | 2,254 | |
| `accountingForensics` | 2,248 | 2,248 | |
| `transportAuditLog` | 1,820 | 1,820 | of 4,534 total — **other schools present** |
| `accountingIndexByAccount` | 1,218 | 1,218 | |
| `noticeRecipients` | 1,115 | 1,115 | |
| `systemCounters` | 987 | 987 | |
| **`feeDemands`** | **854** | **0** | **field-only — see §5.1** |
| `accountingIndexByDate` | 526 | 526 | |
| `notices` | 486 | 486 | |
| `transportNoticeIdempotency` | 458 | 458 | |
| `feeAuditLogs` | 383 | **0** | field-only |
| `mapsConfigAudit` | 341 | 341 | |
| `auditLogs` | 309 | 309 | of 732 — other schools present |
| `studentHistory` | 144 | 144 | |
| `chartOfAccounts` | 68 | 68 | school-scoped CoA, not global |
| `userDevices` | 66 | **0** | field-only |
| `students` | **11** | 11 | |
| `parents` | 13 | 13 | |
| `staff` | 13 | 13 | |

Full 185-collection listing captured in the inventory run.

## 3. FIELD-ONLY COLLECTIONS — deletion MUST filter on `schoolId`

| Collection | Tenant docs |
|---|---|
| `feeDemands` | 854 |
| `feeAuditLogs` | 383 |
| `userDevices` | 66 |
| `systemLogs` | 29 |
| `fee_audit_logs` | 17 |
| `processedPayments` | 11 |
| `staffCapabilities` | 10 |
| `adminAuthState` | 9 |
| `admissionSlugs`, `communicationCounters`, `indexSchoolCodes`, `schoolCodeIndex`, `schoolNameIndex`, `schoolSsa`, `subscriptions`, `tenantAudit`, `tenantPublic` | 1 each |

## 4. COLLECTIONS WITH **NO** TENANT DATA — must not be touched

`plans` · `systemPlans` · `platformSettings` · `systemConfig` · `superAdmins` · `superadmins` ·
`superadminAuthState` · `schoolControl` · `bookCategories` · `importMappings` · `opsCounters` ·
`rateLimits` · `saIpRateLimit` · `adminIpRateLimit` · `indexAdminIds` · `schoolIdIndex` — all report
**0** for this tenant. These are global/shared and are excluded.

Note `transportAuditLog` (4,534 total / 1,820 ours), `auditLogs` (732 / 309), `noticeRecipients`
(2,367 / 1,115) and others **contain other schools' data in the same collection** — every delete must be
filtered, never collection-wide.

---

## 5. ⚠ FOUR UNCERTAINTIES — STOPPING PER PART 10

### 5.1 Session scope — `feeDemands` shows 854, but only **780** are session `2026-27`

**~74 fee demands belong to other sessions.** The brief names `SESSION = 2026-27` but also asks to
"completely remove the test school's operational/test data" for a clean rebuild.

**I will not guess.** Which do you want?
- **(a)** Delete **all sessions** for this tenant — truest "clean rebuild".
- **(b)** Delete **only `2026-27`** — leaves prior-session data intact.

This affects many collections, not just fees.

### 5.2 The school master + tenant-identity records (Part 13)

`schools` holds **1** document for this tenant. Additionally these carry exactly **1** tenant doc each and
look like **provisioning / identity / auth infrastructure**, not operational data:

`schoolCodeIndex` · `schoolNameIndex` · `indexSchoolCodes` · `schoolSsa` · `subscriptions` ·
`tenantAudit` · `tenantPublic` · `admissionSlugs` · `schoolConfig` · `notificationPreferences`

> **Part 13 explicitly warns:** *"If deleting the school master would affect authentication, indexes,
> rules, or tenant provisioning, STOP and recommend retaining/resetting the shell instead."*
>
> **That condition is met.** `schoolSsa`, `schoolCodeIndex` and `adminAuthState` are plainly
> authentication/provisioning surfaces. **My recommendation: retain the school shell and these identity
> records; delete operational data only.** That gives a clean tenant you can log into and rebuild from —
> deleting them risks an unloginable school that must be re-provisioned.
>
> **Confirm: retain shell (recommended) or delete everything including identity records?**

### 5.3 Firebase Storage — **not yet inventoried**

Part 3 requires a Storage manifest. I have not enumerated Storage objects. Doing so needs a bucket
listing, which I have not run. **No Storage object will be deleted until inventoried and confirmed.**

### 5.4 Firebase Auth — **not yet inventoried, and likely ambiguous**

Part 4 requires that an Auth user be deleted **only if** the school relationship is provable from
canonical data. With `parents` (13), `staff` (13) and `students` (11) present, candidate users exist —
but **shared-database Auth carries real cross-tenant risk**, and Part 4 says ambiguity ⇒ do not delete.

**My recommendation: do NOT delete Auth users in this pass.** Report them as REQUIRES MANUAL REVIEW.

---

## 6. SRNO PRE-CHECK (Part 5)

- Ownership index `srNoIndex` — tenant-scoped by `{schoolId}_{srNoNorm}` doc IDs.
- The **school-scoped counter** lives in `feeCounters` as `_sys_SRNO_{schoolId}` plus `_claim_{N}` docs —
  `feeCounters` shows **16** tenant docs.
- `Sr_no_service` allocates **per school**; there is **no global SRNO sequence** to damage.

**Deleting this tenant's SRNO records cannot affect another school.** After deletion the counter
lazily re-initialises from the highest existing `srNoIndex` value — which, once empty, restarts cleanly
at 1. Verified in `Sr_no_service::_readSrNoCounterPointer()`.

## 7. ID-CARD PRE-CHECK (Part 6)

`idCards` did **not** appear in the tenant inventory — consistent with the fact that **no card has ever
been issued** on this tenant (the model shipped in 19E and nothing has run `issue()`). Nothing to delete.
Global QR secret and credential code untouched.

## 8. FEES PRE-CHECK (Part 7) — quantified, **not repaired**

| | |
|---|---|
| `feeDemands` (all sessions) | **854** · session `2026-27`: **780** |
| Status split (2026-27) | archived 407 · unpaid 363 · paid 8 · partial 1 · cancelled 1 |
| `studentFeeSummary` | 10 |
| `classFeeSummary` | 52 |
| `feeDefaulters` | 10 |
| `feeStructures` | 7 · `feeHeads` 6 |
| `studentConcessions` 1 · `studentEntitlements` 16 · `studentDiscounts` 0 |
| `feeCounters` 16 · `feeIdempotency` 47 · `feeReceiptIndex` 22 |
| `feeAuditLogs` 383 · `fee_audit_logs` 17 · `feeRefundAudit` 7 |
| ID-card replacement demands | **0** |

**No dueDate backfill, no `--commit`, no summary reconciliation was run.** Target #1's repair is
suspended as instructed — the data is being discarded instead.

## 9. CODE / RULES SAFETY (Part 16)

No source file, `firestore.rules` or `firestore.indexes.json` was modified. Nothing committed, pushed or
deployed. No deletion-safety defect was found that would require a code change.

---

## 10. STATUS

# STOPPED AT THE PART 10 AUTHORIZATION GATE — NOTHING DELETED

| Gate | |
|---|---|
| Every target belongs to `SCH_D94FE8F7AD` | ✅ verified by field + ID prefix |
| No real school included | ✅ shared collections filtered, never collection-wide |
| Shared/global excluded | ✅ §4 |
| Session-scoped correctly identified | ❌ **§5.1 undecided** |
| Subcollections accounted for | ⏸ pending, after scope is fixed |
| Storage paths tenant-specific | ❌ **§5.3 not inventoried** |
| Auth users unambiguous | ❌ **§5.4 recommend no deletion** |
| SRNO scope safe | ✅ §6 |
| ID-card records tenant-specific | ✅ none exist |
| Fee records tenant-specific | ✅ §8 |
| Rules/indexes untouched | ✅ |

### The four answers I need before deleting anything

1. **Session scope** — all sessions, or only `2026-27`?
2. **School shell** — retain the `schools` doc + identity/auth records **(recommended)**, or delete them
   too?
3. **Storage** — authorize a read-only bucket inventory first?
4. **Auth users** — leave untouched **(recommended)**, or in scope?

With those settled I will produce `TEST_RESET_DELETE_MANIFEST.md` with exact per-collection counts, then
execute in filtered, verified batches with a zero-residue re-scan.

**I deliberately have not attempted a 17,000-document destructive operation on a database shared with
real schools while four scope questions are open.**
