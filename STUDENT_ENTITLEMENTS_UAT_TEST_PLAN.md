# Student Entitlements — Operator UAT Test Plan

**Date:** 2026-08-09
**Build:** Phases A–F, uncommitted, on `integration/phase2`
**App URL:** `http://localhost/ZenX/school/`

---

## READ THIS FIRST — what "working" looks like

Both feature flags are **deliberately false**:

| Flag | State | Consequence during testing |
|---|---|---|
| `ENTITLEMENT_SHADOW_COMPARE` | `false` | No A/B logging. Expected. |
| `USE_ENTITLEMENT_SERVICE_PRICING` | `false` | **A transport/hostel concession will NOT reduce the bus fee yet.** Expected. |

So: **entitlements you create will be saved, listed, and approved correctly — but they will NOT change any fee amount.** That is the designed state, not a bug. Billing still runs entirely on the legacy `studentConcessions` path.

**Do not report "the discount didn't apply" as a failure.** The only fee behaviour under test here is Phase A (transport billing + admission fee), which *is* live.

**Bonus:** every concession you create becomes the representative data that makes certification Gate 5 meaningful. Please **do not delete** them when you finish.

---

## Test data to create

Use a **non-production tenant** if you can — `SCH_B56BB9A401` (26 demands) or `SCH_B9161BBE3C` are far quieter than `SCH_218AAF5C23` (1,118) or `SCH_D94FE8F7AD` (704).

Pick **one existing student** and note their details before starting:

```
Tenant (school)   : ____________________
Student ID        : ____________________   (e.g. STU00xx)
Class / Section   : ____________________
Current total dues: ____________________   (Fees → Student Ledger, note it)
```

---

## TEST 1 — Direct Admission charges the admission fee (G10)

**Why:** before this build, a walk-in admitted through the Admission screen was **never charged** the admission fee. Pure revenue loss.

**Steps**
1. `SIS → Admission` (New Admission).
2. Fill the required fields for a throwaway student. Pick a class that has an admission fee configured.
3. Scroll to the **admission fee** section.

| # | Expected | Pass? |
|---|---|---|
| 1.1 | An admission fee amount is shown, resolved from the class's fee structure | ☐ |
| 1.2 | Payment mode is **required** — you cannot submit without choosing one | ☐ |
| 1.3 | Choosing a non-cash mode **requires** a bank ledger | ☐ |
| 1.4 | On save, a receipt is generated with an **`ADM…`** number (not `F…`) | ☐ |

**Then verify the money landed:** `Accounting → Journals`, find the entry for that receipt. It must be tagged `collection_type = admission` and credit the admission income account.

> ⚠️ This creates a **real student and a real receipt**. Use an obviously fake name (e.g. "UAT Test One") so it's identifiable later.

---

## TEST 2 — Waive Admission Fee is permission-gated and audited (AC13)

**Why:** the waive must be a deliberate, logged exception — never a silent skip, and never available to anyone who can merely admit a student.

**Steps** — needs **two** logins.

**2A — as a user WITHOUT `Fees: manage`** (e.g. Front Office):

| # | Expected | Pass? |
|---|---|---|
| 2.1 | The **Waive** control is not usable/visible | ☐ |
| 2.2 | You still cannot submit without paying (no bypass) | ☐ |

**2B — as a user WITH `Fees: manage`** (Admin / Accountant):

| # | Expected | Pass? |
|---|---|---|
| 2.3 | The **Waive Admission Fee** option is available | ☐ |
| 2.4 | Ticking it **requires a reason** — blank is rejected | ☐ |
| 2.5 | With a reason, admission completes with **no receipt and no journal** | ☐ |
| 2.6 | The waive appears in the student's history / audit log with **who, when, and the reason** | ☐ |

> **Note:** by default `Principal` has `SIS` but **not** `Fees`, so a Principal can admit but **cannot** waive. If you want Principals to waive, that's a role-catalogue change, not a bug.

---

## TEST 3 — Transport suspend/resume actually changes billing (G1/G2)

**Why:** the headline defect. Suspending a student's transport previously left the family **billed in full** for a service they'd stopped.

**Setup:** assign your test student to a transport route with a monthly fee. Note it:
```
Transport fee: ____________   Demand balance before: ____________
```

**Steps**

| # | Action | Expected | Pass? |
|---|---|---|---|
| 3.1 | `Transport → Suspend` the student (give a from-date + reason) | Succeeds | ☐ |
| 3.2 | Check the student's transport fee demand | **`netAmount` has DECREASED** (prorated to remaining months) | ☐ |
| 3.3 | `Transport → Resume` | Succeeds | ☐ |
| 3.4 | Re-check the demand | **`netAmount` back to the FULL fee**; `prorated` flag cleared | ☐ |

**3.5 — the rollover trap (highest value here).** While the student is **suspended**, edit the assignment (change route or subscription).

| Expected | Pass? |
|---|---|
| The fee must **stay reduced** — editing must NOT silently restore full billing | ☐ |

> This was a real defect found during certification: five separate code paths could silently un-suspend billing while the assignment still showed "suspended."

---

## TEST 4 — Concession capture (Phase E)

**Where:** `SIS → Admission` (after saving a student) **or** the CRM conversion screen. Both mount the *same* component — worth testing both to confirm.

**4A — create a sibling concession**

Enter exactly:
```
Type            : Concession
Concession type : Sibling
Applies to      : All fees
Value type      : Percent
Value           : 10
Billing frequency: Monthly
Effective from  : today
Reason          : UAT sibling test
```

| # | Expected | Pass? |
|---|---|---|
| 4.1 | Saves successfully | ☐ |
| 4.2 | Appears in the list below with a status pill | ☐ |
| 4.3 | Status is **`pending approval`** if you lack `Fees: manage`, **`active`** if you have it | ☐ |
| 4.4 | **The student's fee total does NOT change** — correct, flags are off | ☐ |

**4B — invariant I1: frequency cannot be skipped**

Repeat 4A but leave **Billing frequency** on `Select…`.

| Expected | Pass? |
|---|---|
| **Rejected** with a message about frequency being required | ☐ |

> This is deliberate. Silently defaulting frequency is precisely what made transport proration a no-op for months.

**4C — invariant I4: RTE/EWS needs a receivable**

Set concession type to **RTE / EWS**, leave `Govt. receivable` at `0`.

| Expected | Pass? |
|---|---|
| **Rejected** — an RTE grant without a receivable is an unrecorded government debt | ☐ |
| Entering a real amount (e.g. `5000`) → saves | ☐ |

**4D — bad scope is refused**

Choose *Applies to* = `A fee category`, leave the target blank.

| Expected | Pass? |
|---|---|
| **Rejected** — a scoped concession with no target would match nothing or everything | ☐ |

---

## TEST 5 — Approval queue + maker-checker (I5)

**Why:** nobody may approve their own concession.

**Steps**
1. As **User A** (no `Fees: manage`), create a concession → lands `pending approval`.
2. Log in as **User B** (with `Fees: manage`), open the approval queue.

| # | Expected | Pass? |
|---|---|---|
| 5.1 | User A's request is listed, **oldest first** | ☐ |
| 5.2 | User B can **Approve** → status becomes `active`, approver recorded | ☐ |
| 5.3 | Create one as User B, then try to approve it **as User B** → **BLOCKED**: *"You cannot approve an entitlement you requested yourself."* | ☐ |
| 5.4 | **Reject** requires a reason — blank rejected | ☐ |
| 5.5 | A rejected/approved item leaves the pending queue | ☐ |

---

## TEST 6 — Live fee preview

On the capture screen, press **Preview fees**.

| # | Expected | Pass? |
|---|---|---|
| 6.1 | A per-head breakdown and a total appear | ☐ |
| 6.2 | The note says it was computed by the same engine that generates demands | ☐ |
| 6.3 | **Nothing is saved** — re-open the ledger, dues unchanged | ☐ |

> Known and expected: the preview covers **tuition only**. Transport/hostel fees are not in the total — documented, scheduled for a later phase.

---

## TEST 7 — Fee Concessions screen access (G3)

**Why:** the legacy screen previously locked out valid roles.

| # | As | Expected | Pass? |
|---|---|---|---|
| 7.1 | Admin / Accountant | `Fees → Concessions` loads, can create | ☐ |
| 7.2 | A role with **no** Fees permission | Access denied cleanly (no blank page, no 500) | ☐ |

---

## TEST 8 — Nothing else regressed

| # | Check | Expected | Pass? |
|---|---|---|---|
| 8.1 | Admit a student normally, view their ledger | Fee demands generate as before | ☐ |
| 8.2 | Collect a fee payment | Receipt + journal as before | ☐ |
| 8.3 | Promote a student | Demands reassign as before | ☐ |
| 8.4 | Issue a TC | Dues check behaves as before | ☐ |
| 8.5 | Parent app — open the student's fees | Same amounts as the admin panel | ☐ |

---

## What to send back

1. This file with the boxes filled in.
2. For any failure: **what you did, what you expected, what happened**, and a screenshot if it's UI.
3. Confirmation you did **not** delete the concessions you created (Gate 5 needs them).
4. The tenant + student ID you used.

## What NOT to do

- Do not flip either feature flag.
- Do not delete `studentConcessions` records.
- Do not commit anything.

## After you return

With real concession data present, we can finally run **Gate 5** as a meaningful comparison, then close **Gate 6** (proration) and perform **Gates 6 + 8 together** as one coordinated cutover — never partially.
