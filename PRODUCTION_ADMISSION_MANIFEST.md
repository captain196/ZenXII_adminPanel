# CONTROLLED PRODUCTION ADMISSION — FINAL PRE-EXECUTION MANIFEST (v4)

**Tenant:** `SCH_D94FE8F7AD` (IIT Kanpur) — the ONLY tenant this plan may touch
**Project:** `graderadmin` · **Session:** `2026-27` · **Actor:** `ZXPRODTEST-UAT`
**Status:** **NO-GO** — see the blocker below. **No production write has been performed.**
**Branch:** `integration/phase2` · commit `d049647` · nothing pushed, nothing deployed

---

## ⛔ BLOCKER — THE ADMISSION CHAIN IS NOT REPRODUCIBLE FROM COMMITTED STATE

A clean worktree at the committed HEAD was built and the full chain re-run
against fresh emulators. **It does not work**, because the admission chain is
itself uncommitted working-tree code belonging to other workstreams:

* `application/libraries/Sr_no_service.php`, `Admission_no_service.php` and
  `Section_seat_guard.php` are **UNTRACKED — they exist in no commit at all.**
* `Sis.php` differs from HEAD by **+4102 / −289** lines. The committed copy has
  no `_admission_fee_precheck`, no `_admission_fee_apply`, no
  `_claimIdentityAttributes`, no `_fee_structure_missing`, no seat guard and no
  admission-number allocation.
* `Classes.php` (+421/−133), `Fee_management.php` (+2896/−224),
  `Operations_accounting.php` (+276/−34), `Admission_collection.php` (+28/−3),
  `Entity_firestore_sync.php` (+51/−1) and `FeeCollectionService.php` (+570/−64)
  all differ from HEAD.

**What the committed code actually did** on the emulator: the fee chart was
rejected (`Class/section not found`), and the admission *still reported success*
— producing a student with **no SR number, no admission number, no fee demands
and no admission fee**. 10 collections / 73 documents instead of 23 / 121, with
no `feeStructures`, `feeDemands`, `srNoIndex`, `admissionNoIndex`,
`admissionReceipts`, `accounting`, `accountingClosingBalances` or
`accountingIdempotency`. A Firebase Auth account was still created.

That is precisely the Active-with-zero-demands failure that BUG-076 Part 2-A
exists to prevent — and that guard is itself uncommitted.

**Consequence:** a production write would be executed by code that exists
nowhere but this machine's working tree. It could not be reviewed as a version,
reproduced, or rolled back to. This is an external blocker owned by other
workstreams, not something to work around.

---

v3 supersedes v2. R1 (Auth sandbox) and R2 (tenant-scoped cleanup) are complete,
committed and independently re-proven. Every expected document below comes from
a **clean emulator run using the exact parameters approved for production** —
not from reading source.

`Production_admission_probe` hashes this file; editing it voids every
outstanding authorisation ticket.

---

## 1. R1 — FIREBASE AUTH IS NOW SANDBOXED (COMPLETE)

**The defect.** `Firebase::createFirebaseUser()` calls the Admin SDK with no
emulator seam. `FIRESTORE_EMULATOR_HOST` governs Firestore only, and
`FIREBASE_AUTH_EMULATOR_HOST` was set nowhere in the repository. So the harness
guard sandboxed Firestore and nothing else, and every rehearsal admission issued
a **real production Auth create**. They failed only because
`_generatePassword()` returns four characters when no date of birth is supplied,
below Firebase's six-character minimum — luck, not design.

**The fix.** `Firebase::resolvedAuthRoot()` / `isAuthEmulatorTarget()` ask
Kreait for the exact URL its Auth calls will use — the same builder Kreait
consults at call time, so the answer cannot drift from real behaviour.
`Lifecycle_probe::_assertEmulator()` now proves the Auth destination the same
way it proves the Firestore one, and fails closed on every uncertainty.

**Proof (post-commit, running the committed harness):**

| `FIREBASE_AUTH_EMULATOR_HOST` | result |
|---|---|
| unset | **exit 3 — REFUSED** |
| `10.20.30.40:9099` (remote) | **exit 3 — REFUSED** |
| `auth.example.com:9099` (hostname) | **exit 3 — REFUSED** |
| `8.8.8.8:9099` | **exit 3 — REFUSED** |
| `127.0.0.1:9199` (loopback) | exit 0 — allowed |

A full rehearsal admission then ran **with a date of birth**, producing the
production-length password `Zxp1204@`, and the Auth account landed **in the
emulator**: `localId=STU0001`, `stu0001@schoolsync.app`, custom claims
`role=student`, `school_id=SCH_D94FE8F7AD`. Read-only production Auth lookups
confirm **0 accounts** carry `school_id=SCH_D94FE8F7AD`, and both
`stu0001@` and `stu0190@` are absent. Before this fix, that same call would have
created a live production account.

One correction found by running the positive case: my first guard tested the
whole URL for `googleapis.com`, which the emulator's own URL format embeds in
its *path* — it rejected the destination it was meant to accept. Now scoped to
the host. A negative-only proof would have shipped that bug.

**Not committed:** `application/libraries/Firebase.php`, which holds the
reporter. That file carries another session's staged **and** unstaged work
(50 staged lines, ~40 unstaged lines that are not mine) and cannot be committed
without sweeping it in. **Consequence: until it is committed, a clean checkout
fails CLOSED** — `method_exists` returns false and the guard refuses to run
rather than running unsandboxed. That is the safe direction, but it means the
harness will not run from a clean checkout until that file is committed by
whoever owns those changes.

---

## 2. R2 — CLEANUP IS REAL, AND THERE IS NOTHING STALE TO CLEAN

### 2a. Read-only production census — no stale test data exists

`tools/tenant_census.php` enumerates collections rather than trusting a list
(the v1 manifest named 12 write targets where the real chain writes 20+).

Against production, **164 collections enumerated**; `SCH_D94FE8F7AD` owns
**168 documents**, all legitimate tenant infrastructure:

| collection | n | what it is |
|---|---|---|
| `security_events` | 131 | real login/security telemetry, Jun–Aug |
| `mapsConfigAudit` | 11 | maps configuration history |
| `adminAuthState` | 9 | admin auth state |
| `analyticsRollups` | 3 | derived analytics |
| `schools` | 2 | tenant doc + `_profile` |
| `feeCollectionRollup`, `indexSchoolCodes`, `mapsConfig`, `mapsSecrets`, `schoolCodeIndex`, `schoolConfig`, `schoolNameIndex`, `schoolSsa`, `staff`, `subscriptions`, `tenantAudit`, `tenantPublic` | 1 each | provisioning, config, and the tenant's one real administrator |

**Documents in any collection this test writes: 0.**
**Production Auth accounts for this tenant: 0.**

**Verdict: there is no stale or test-only data in this tenant.** Nothing is to
be deleted, and none has been. The cleanup tool refuses the resulting empty
manifest, which is the correct outcome — I will not manufacture a cleanup to
demonstrate one.

### 2b. The cleanup tool exists and is proven end-to-end

`tools/tenant_cleanup.php` replaces the assumed rollback of v1 (which pointed at
`incident_cleanup`, hard-coded to the three `SCH_GATE` tenants and the
2026-08-31 window, with a deletable list omitting every collection an admission
writes).

Rails: **one tenant** (a constant); a **collection allow-list**, not a deny-list
— every audit, history, forensics, counter and provisioning collection is absent
by construction; **exact paths only** from a run manifest, no prefix, wildcard,
collection-wide or timestamp-only form exists in the file; **three proofs
re-checked immediately before each delete** (allow-listed collection · tenant
ownership · createTime inside the declared run window); **dry run by default**;
and deletion **verified by re-reading the path**, never from the DELETE response.

Proven on the emulator against real rehearsal data:

| test | result |
|---|---|
| live delete of a full run | **103 deleted, each verified absent** |
| evidence + counters in the same manifest | **16 refused** — `auditLogs` ×5, `feeCounters` ×2, `systemCounters` ×2, `accountingCounters` ×2, `accountingAudit`, `accountingForensics`, `feeAuditLogs`, `studentHistory`, `schools` |
| foreign-tenant document (`SCH_B56BB9A401`) | **refused**, and confirmed still present afterwards |
| out-of-window document | **refused** |
| manifest with no window header | **exit 3** — origin unprovable |
| `FIRESTORE_EMULATOR_HOST` non-loopback | **exit 3** — never a remote delete tool |
| Auth account with foreign-tenant claims | **exit 3**, account confirmed still present |
| Auth account with this tenant's claims | deleted and **verified absent** |

Collateral: **zero**.

---

## 3. R10 — ACTOR

Every write runs as **`ZXPRODTEST-UAT`**, role `School Super Admin`. The
tenant's only real administrator (`SSA0001`, Shivam Rathore — the same person
who configured the admission fee) is **not** used, so the permanent `auditLogs`
trail truthfully records controlled UAT activity rather than falsely
attributing a synthetic admission to a real human. The student's name carries
the marker `ZXPRODTEST Controlled UAT` for the same reason.

---

## 4. APPROVED CONFIGURATION

| item | value |
|---|---|
| class / section | **`Class 5th` / `Section A`**, max strength 40 |
| tuition | ₹1,000 / month |
| transport | ₹500 / month |
| admission fee | **₹100 "Registration Fee"** — the genuine configured policy |
| payment mode | cash (needs no bank ledger; non-cash would also require an active bank account) |
| date of birth | 2015-04-12 |
| phone | 9000000190 |
| APAAR | **not supplied** — no national-identity index document is written |

**Canonical class name matters.** `Classes::add_section` normalises `5` to
`Class 5th`; `Fees::save_updated_fees` stores what it is given. A chart saved as
`Class 5` is orphaned and the admission is refused. The stage code hard-codes
the canonical form; it is not configurable at run time.

**Admission-fee provenance, re-verified.** `enabled: true, amount: 100,
currency INR, label "Registration Fee", updated_by "Shivam Rathore", updated_at
2026-07-16 14:15:41`. No hard-coded `100` exists in the source; the only writer
is the admin config screen (`School_config.php:6502`); the setter is the
tenant's sole administrator; and I have never written to this tenant. Genuine
active configuration — provenance proven, business intent not something I can
prove.

---

## 5. EXACT EXPECTED WRITE SET

Derived from a **clean emulator run with these exact parameters**:
**121 documents across 23 collections** (120 created + the pre-existing
`schools` document, which is read and not modified). `{S}` = `SCH_D94FE8F7AD`,
`{Y}` = `2026-27`; the production student will be **`STU0190`**, not `STU0001`.

| stage | collection | n | exact path shape | reversible |
|---|---|---|---|---|
| S1 `seed_coa` | `chartOfAccounts` | 65 | `{S}_{code}` | yes |
| S2 `add_section` | `sections` | 1 | `{S}_{Y}_Class 5th_Section A` | yes |
| S3 `fee_chart` | `feeStructures` | 1 | `{S}_{Y}_Class 5th_Section A` | yes |
| S4 `admit` | `feeCounters` | 4 | `_sys_STU` (**189→190**), `_sys_STU_claim_190`, `_sys_SRNO_{S}`, `_sys_SRNO_{S}_claim_1` | **NO** |
| S4 | `students` | 1 | `{S}_STU0190` | yes |
| S4 | `parents` | 1 | `{S}_STU0190` | yes |
| S4 | `srNoIndex` | 1 | `{S}_1` | doc yes; **SR No never reused** |
| S4 | `admissionNoIndex` | 1 | `{S}_2026_{6-char token}` | yes |
| S4 | `indexPhones` | 1 | `{S}_9000000190` | yes |
| S4 | `studentHistory` | 1 | `{S}_STU0190_{ts}_{hash}` | **NO — evidence** |
| S4 | `feeDemands` | **24** | random 20-hex id | yes |
| S4 | `feeAuditLogs` | 1 | `AUD_{ts}_DEMAND_{hash}` | **NO — evidence** |
| S4 | `feeDefaulters` | 1 | `{S}_{Y}_STU0190` | yes |
| S4 | `systemCounters` | 2 | `{S}_admissionReceiptNo`, `…_claim_1` | **NO** |
| S4 | `admissionReceipts` | 1 | `{S}_ADM000001` | yes, with §7 caveat |
| S4 | `accounting` | 1 | `{S}_{Y}_JE_FEE_ADM000001` | yes, with §7 caveat |
| S4 | `accountingClosingBalances` | 2 | `{S}_{Y}_1010`, `{S}_{Y}_4020` | yes, with §7 caveat |
| S4 | `accountingIdempotency` | 2 | `{S}_ADM_COLLECT_STU0190`, `{S}_JE_FEE_ADM000001` | yes |
| S4 | `accountingCounters` | 2 | `{S}_{Y}_Fee`, `{S}_{Y}_Fee_1` | **NO** |
| S4 | `accountingAudit` | 1 | `{S}_AL_{ts}_{hash}_0` | **NO — evidence** |
| S4 | `accountingForensics` | 1 | `{S}_{Y}_JE_FEE_ADM000001_{ms}` | **NO — evidence** |
| all | `auditLogs` | 5 | `{S}_AL_{ts}_{seq}` | **NO — evidence** |
| S4 | **Firebase Auth** | 1 account | uid `STU0190`, `stu0190@schoolsync.app` | see §6 |

**Expected financial state**

| quantity | expected |
|---|---|
| demands | **24** = 12 months × {Tuition 1000, Transport 500} |
| gross / net / balance | **18,000 / 18,000 / 18,000** |
| admission receipt | `ADM000001`, ₹100, cash, `journalPosted=true` |
| journal | `JE_FEE_ADM000001`, Dr 100 / Cr 100 |
| closing balances | `1010` Dr 100 · `4020` Cr 100 (Registration Fee → 4020 by keyword) |
| global STU counter | 189 → **190** |

Rehearsal invariants: **I-1 PASS** (gross = net), **I-2 PASS** (balance = net),
journal **balanced PASS**.

---

## 6. FIREBASE AUTH ACCOUNT — FULL LIFECYCLE

| phase | detail |
|---|---|
| **created** | during S4, uid `STU0190`, email `stu0190@schoolsync.app`, password `Zxp1204@` (8 chars, from name + DOB), claims `role=student`, `school_id={S}`, `schoolId={S}`, `must_change_password=true` |
| **why it exists** | it is what the real admission chain does; suppressing it would mean testing something other than the real chain |
| **reach** | Firestore rails cannot touch it — it is a different service |
| **removal** | `ZX_CONFIRM=DELETE php tools/tenant_cleanup.php --auth STU0190`, which first proves the account's claims name this tenant, then deletes and **re-reads to prove absence**. Proven on the emulator, including refusal of a foreign-tenant account |
| **if left** | a login credential for a synthetic student, with production tenant claims. **Recommendation: delete it as part of cleanup** |

---

## 7. IRREVERSIBLE SIDE EFFECTS — COMPLETE LIST

1. **Global student ID `189 → 190`.** Never rewound, never reused. Burned even
   if a later stage refuses, because `_nextStudentId()` runs *before* the seat
   guard and the SRNO claim, and `atomic_capacity_enabled` is `true`.
2. **School SR-No counter `0 → 1`** and the `srNoIndex` claim. SR No never reused.
3. **Admission-number token** and **`systemCounters/{S}_admissionReceiptNo` `0 → 1`**.
4. **`accountingCounters`** voucher sequence `0 → 1`.
5. **Evidence, never deleted under any circumstances:** `auditLogs`,
   `studentHistory`, `feeAuditLogs`, `accountingAudit`, `accountingForensics`.
6. **The Firebase Auth account** — removable only through the separate Auth
   action in §6.
7. **Accounting caveat:** a receipt and its journal are accounting records.
   `accountingClosingBalances` *accumulates*, so deleting a journal without its
   balances would leave the books overstated — the cleanup manifest must carry
   both. This is acceptable here **only** because the tenant has never held a
   single financial record.

---

## 8. DESTINATION PROOF FOR EVERY EXTERNAL SERVICE

| service | used? | how the destination is proven |
|---|---|---|
| **Firestore** | yes | `FirestoreRestClient::resolvedApiRoot()` — asked of the client, must be `firestore.googleapis.com`; the emulator harness inverts the same check |
| **Firebase Auth** | yes | `Firebase::resolvedAuthRoot()` — asked of Kreait's own URL builder (R1) |
| **Google OAuth token endpoint** | yes | fixed `oauth2.googleapis.com`, read-only credential exchange |
| **Razorpay / any payment gateway** | **no** | the admission fee is collected as **cash**; no online payment path is invoked, no order is created, no real money moves |
| **FCM / push** | **no** | there is no `emit_push` anywhere in the admission chain — verified by search |
| **Email / SMS** | **no** | no sender is invoked in the chain |
| **Firebase Storage** | **no** | no document upload is supplied |
| **`crmApplications` timeline** | **no write** | `casAppendBounded` returns `ok:false` on a missing document and writes nothing — rehearsal produced 0 `crmApplications` |

---

## 9. PERSISTED-STATE VERIFICATION PLAN

Endpoint success is never proof. After **every** stage, independently:

1. **Re-read** each expected path with `tools/tenant_census.php --detail`
   (a separate REST reader, not the writing client).
2. **Count** documents per collection against §5. Any extra document is a stop.
3. **Scope:** every document must carry `schoolId={S}` and, where applicable,
   `session={Y}`. Any other tenant is a stop.
4. **Money invariants:** I-1 `gross − discount == net`; I-2 `balance == max(0,
   net + fine − paid)`; I-3 no negative payable; I-6 status agrees with numbers.
5. **Accounting:** `total_dr == total_cr`; `journalPosted == true`;
   `journalLastError == ''`; closing-balance deltas equal the journal lines
   (`1010` Dr 100, `4020` Cr 100).
6. **Idempotency:** `accountingIdempotency` holds exactly the two expected
   claims; exactly one `admissionReceipts` document; exactly one journal.
7. **Counters:** `_sys_STU` moved by **exactly +1**, to 190.
8. **Auth:** exactly one account for this tenant, uid `STU0190`, correct claims.

Stage order and authorisation: `seed_coa` → `add_section` → `fee_chart` →
`admit`. Each requires its **own** freshly-minted single-use ticket
(`php tools/prod_ticket.php issue <stage>`), consumed before the write.

**Stop conditions — halt and report, do not act:** any document under another
`schoolId` · any unexpected document · counter movement other than +1 · any
invariant failure · `total_dr != total_cr` · `journalPosted=false` · a duplicate
receipt or journal · any read-after-write mismatch · any destination mismatch ·
any value differing from §5. On a stop: **no repair, no delete, no compensating
entry, no retry by another route.**

---

## 10. EXACT CLEANUP MANIFEST (only if cleanup is later authorised)

Recorded during the run and passed to `tools/tenant_cleanup.php`. Deletable
(103-equivalent set): `students`, `parents`, `sections`, `feeStructures`,
`chartOfAccounts` ×65, `feeDemands` ×24, `feeDefaulters`, `admissionReceipts`,
`accounting`, `accountingClosingBalances` ×2, `accountingIdempotency` ×2,
`srNoIndex`, `admissionNoIndex`, `indexPhones` — plus the Auth account via the
separate `--auth STU0190` action.

**Never deleted:** `auditLogs`, `studentHistory`, `feeAuditLogs`,
`accountingAudit`, `accountingForensics`, `accountingCounters`, `feeCounters`,
`systemCounters`, `schools`, and every pre-existing provisioning, config, staff
and security-telemetry document. The allow-list makes these unreachable rather
than merely discouraged.

---

## 11. WHAT IS NOT PROVEN

* The **stage dispatch code inside `Production_admission_probe`** has never
  executed, because that controller refuses any emulator by design. Its
  parameters and the resulting document set are proven — the emulator rehearsal
  drove the same controllers and methods with the same values — but the wrapper
  itself will run for the first time in production.
* **`STU0190`-specific ids** (admission-number token, receipt sequence
  `ADM000001`) are predicted from a clean tenant, not observed in production.
* **Business intent** of the ₹100 fee — provenance is proven, intent is not
  mine to assert.

## 12. WHAT THIS RUN DELIBERATELY DOES NOT DO

Never: touch Vikrant Public School or any other tenant · modify another tenant's
data · roll back or reuse the global student ID · perform a broad, prefix,
wildcard, collection-wide or timestamp-only delete · manufacture a refund,
cancellation, overpayment, void or discount · weaken or bypass a safety guard ·
commit, push or deploy without explicit permission for that specific change.
