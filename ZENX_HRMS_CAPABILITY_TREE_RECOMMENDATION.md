# ZENX HRMS — FINAL CAPABILITY TREE RECOMMENDATION

**Status: RECOMMENDATION ONLY.** No capability key was created. `Superadmin_plans::AVAILABLE_MODULES` is unmodified. No plan, subscription, entitlement, or billing record was touched. Awaiting review of the capability boundaries before any implementation.

**Purpose:** confirm the corrected HRMS capability tree against the *actual* existing ZenX capability system, resolve the collisions with `staff_management` / `attendance` / `student_management`, and state the exact final key set.

---

## 0. Headline: the tree is right, but one boundary in your spec collides with live behaviour

Your corrected tree — **HRMS Core + Payroll as two cross-vertical engines, with thin vertical packs on top, and no Education pack** — is the correct architecture and it fits the existing plan-key capability system cleanly.

One correction is required, and it is significant:

> **Your spec places Attendance and Leave inside HRMS Core. In the live system, staff attendance and leave are entitled by *two different, already-existing keys* — and neither is `staff_management` alone.** Re-gating them onto new HRMS keys would **remove working functionality from all 8 live tenants**.

Specifically **[REPO]**:

| Capability | Entitled today by | RBAC gate today |
|---|---|---|
| Staff **records** + HR + Recruitment + **Leave** + **Payroll** + Appraisals | plan key `staff_management` → label `Staff Management` (`header.php:591`, `:612`) | `HR` |
| **Attendance module** (student *and* staff registers) | label `Student Management` — produced by **either** plan key `student_management` **or** plan key `attendance` (`header.php:732`) | `Attendance` |

So **staff attendance is currently entitled by a student-oriented key**, and **payroll has no key or RBAC module of its own** — `Payroll.php` gates on RBAC `'HR'` (`Payroll.php:84,102,123,149`) and `Payroll` is absent from the 26-module RBAC catalogue (`functions/rbac_modules.json`).

This does not invalidate your tree. It means the tree describes the **target engine decomposition**, while the existing keys describe **what tenants already bought** — and the two must be reconciled by *layering*, never by re-gating. §4 gives the rule.

---

## 1. The recommended capability tree

```
                         ZENX HRMS
                            │
              ┌─────────────┴─────────────┐
              │                           │
       hrms_core                    hrms_payroll
   (common HR engine)           (common payroll engine)
   all verticals                 all verticals
              │                           │
              └─────────────┬─────────────┘
                            │
                  Optional Vertical Packs
                            │
              ┌─────────────┴─────────────┐
              │                           │
      hrms_corporate               hrms_retail
   corporate-specific ONLY      retail-specific ONLY

   Education: NO pack — integrates with existing School ERP
```

**Four new keys. Nothing else.**

| Key | UI label (for `AVAILABLE_MODULES`) | Contains |
|---|---|---|
| `hrms_core` | `HRMS Core (Workforce)` | Employee mgmt & profiles · Departments · Designations · Employment types · Organisation & Locations · Reporting manager · Employee documents · Lifecycle (joining→probation→confirmation→transfer→promotion→salary revision→exit/offboarding) · Recruitment/onboarding integration · Employee attendance · Shift mgmt · Work schedules · Roster foundation · Leave mgmt · Leave & attendance policies · Holiday calendar · Requests & approvals · Employee & manager self-service · Notifications · HR dashboard · HR reports · Audit trail · HR configuration |
| `hrms_payroll` | `HRMS Payroll` | Salary structures & components · Earnings · Deductions · Employer contributions · CTC · LOP · Overtime · Payroll inputs · Payroll runs · Calculation · Calculation trace · Review · Lock/finalisation · Payslip generation · Payroll queries · Statutory/tax configuration · Payroll↔Accounting integration · Payroll audit · Historical payroll |
| `hrms_corporate` | `HRMS Corporate Pack` | Timesheets · Project & client assignment · Billable/non-billable hours · Project & resource allocation · Utilisation · Client-wise & project-wise workforce cost · Cost-centre & business-unit extensions · Corporate workforce analytics · Corporate travel/expense extensions |
| `hrms_retail` | `HRMS Retail Pack` | Store assignment · Employee→store mapping · Multi-store & temporary assignment · Inter-store transfer · Store roster · Opening/closing shifts · Rotating shifts · Rotating weekly-offs · Store-wise attendance views · Store-manager workforce view · Store staffing requirements · Peak-hour staffing · Store-wise workforce cost · Retail workforce analytics |

**Independence rules (as you specified):**
- `hrms_core` ON does **not** imply any pack.
- `hrms_payroll` is **not** corporate-only — it is available to School, Corporate, and Retail alike.
- Packs **require** `hrms_core` (they extend it); a pack without core is an invalid combination and must be rejected at grant time.
- `hrms_payroll` requires `hrms_core` (payroll consumes employees/attendance/leave).

---

## 2. Naming: why these four names, checked against the existing vocabulary

The four keys enter the **plan-key vocabulary** (snake_case, produced solely by `Superadmin_plans::AVAILABLE_MODULES`, consumed by `tenantPublic.activeModules` → `has_capability()`). That is the correct namespace — it is per-tenant, it now has an enforced consumer, and it is *not* the RBAC namespace (26 Title-Case names, hard-coupled across PHP + Cloud Functions + Firestore rules; adding there is a cross-system deploy).

Checked for collision against all 19 existing plan keys (`student_management, staff_management, admission, attendance, timetable, homework, exams, results, fees, accounts, library, events, ptm, id_cards, gallery, notices, sms_alerts, parent_app, teacher_app`) — **no collision.** The `hrms_` prefix also makes the vertical/engine grouping self-evident in the plan modal, which is a flat checkbox list.

**Deliberately rejected names:**

| Rejected | Why |
|---|---|
| `hrms_attendance`, `hrms_leave` | Would duplicate capabilities that exist (`attendance`) or are already entitled (`staff_management`). Per your instruction, and confirmed necessary: two entitlements for one capability. Both live **inside** `hrms_core`. |
| `hrms_documents`, `hrms_requests`, `hrms_lifecycle`, `hrms_onboarding`, `hrms_expenses` | Over-granular. All are common HR functionality → inside `hrms_core`. Granular keys recreate the combinatorial problem that per-tenant add-ons exist to solve. (Expenses: see §7 open decision.) |
| `hrms_education`, `hrms_vertical_education` | You ruled this out and it is right — Education is the base case `hrms_core` already serves. A key for it would be permanently dead weight. |
| `workforce_*`, `hr_*` | `hr` risks confusion with RBAC module `HR` (a *user*-permission name); `workforce` diverges from the product term. |

---

## 3. Collision analysis with existing keys

### 3.1 `staff_management` — **RETAIN unchanged, deprecate in place. Do not replace.**

It currently entitles staff records, HR, recruitment, leave, payroll, and appraisals for all 8 live tenants. It is the de-facto pre-HRMS "HR" entitlement.

- **Do NOT** rename, remove, or re-point it.
- **Do NOT** make `hrms_core` a replacement that must be granted for existing surfaces to keep working.
- `hrms_core` **layers on top**: it gates only new HRMS-engine surfaces (Phase 1's Organisation/Location/terminology, and future engine features).
- Terminal state (a much later phase): once every legacy surface has been rebuilt on the HRMS engine and migrated, `staff_management` becomes a pure alias of `hrms_core` and only *then* is it retired — with an entitlement backfill, never a silent drop.

### 3.2 `attendance` — **RETAIN, meaning unchanged (student attendance). Do not duplicate.**

Note the pre-existing wart, for the record: `attendance` and `student_management` both map to the same `Student Management` sidebar label (`MY_Controller::_normalize_features()`), and that label gates the Attendance module which serves **both** student and staff registers (`header.php:732`). So staff attendance is entitled by a student-oriented key today.

- `hrms_core` covers **employee** attendance as an engine concept (shifts, work schedules, roster foundation, employee attendance policies) — all of which are new functionality.
- The **existing** Attendance module keeps its **existing** gate. Not re-gated.
- Not recommended now, but worth recording as future hygiene: when employee attendance is rebuilt on the HRMS engine, it moves to `hrms_core` with a backfill grant so no tenant loses it.

### 3.3 `student_management` — untouched, out of scope.

---

## 4. The layering rule (the crisp rule this needs)

> **1. A surface that exists today keeps the gate it has today. Never re-gate an existing surface onto a new HRMS key.**
> **2. A new HRMS surface gates on its HRMS key (`hrms_core` / `hrms_payroll` / pack key).**
> **3. When a legacy surface is later rebuilt on the HRMS engine, it moves to its HRMS key in that same change — and that change ships a one-time entitlement backfill granting the HRMS key to every tenant that held the legacy key, so no tenant loses access.**
> **4. Both gates always apply independently: tenant capability (`has_capability`) AND user permission (`has_permission`). Neither substitutes for the other.**

Rule 3 is what makes this safe. Rule 1 is what makes it non-breaking.

---

## 5. The backward-compatibility trap you must decide on

**This is the single highest-risk item in the whole capability design.**

Your commercial model says `Payroll = ON/OFF` independently. But payroll works **today** for all 8 tenants, entitled via `staff_management` and gated by RBAC `HR`.

> **If the existing payroll surfaces (`hr/payroll`, `Payroll.php`, `Hr.php`'s payroll endpoints) are re-gated onto a new `hrms_payroll` key, all 8 live tenants immediately lose payroll.** That is a production regression, not a migration.

Two acceptable resolutions — **your call**:

| Option | Behaviour | Trade-off |
|---|---|---|
| **A — Grandfather by backfill (recommended)** | At the moment the keys ship, grant `hrms_core` + `hrms_payroll` to every tenant currently holding `staff_management`. Existing payroll keeps working; the keys become the forward-looking gate. | One audited one-time entitlement write per tenant. Means HRMS Core is ON for the current 8 — acceptable, since they already have staff+HR+payroll today. But it also means Phase 1's new Organisation/Location surfaces light up for all 8 at once. |
| **B — Layer strictly (no backfill)** | Existing payroll stays on `staff_management` forever; `hrms_payroll` gates only *new* payroll-engine surfaces. Nothing changes for anyone. | Two payroll gates coexist during the transition — needs discipline, and the "Payroll ON/OFF" commercial lever does not become real for existing tenants until their payroll is migrated to the engine. |

**My recommendation: B for Phase 1 (zero blast radius, keeps validation clean), then A at the moment payroll is actually consolidated onto the canonical engine** — because that is the change that genuinely moves payroll onto the new gate, and the backfill belongs with it.

---

## 6. Education integration — no pack, no generic re-implementation

Per your instruction, and consistent with the existing code:

- `staff` remains the single employee master (already unified — no separate teacher table).
- Education-specific entities (Teacher / Principal / Class Teacher / subject relationships / academic session / school calendar) remain owned by the **existing School ERP**, which stays the source of truth.
- HRMS supplies the reusable engine; the Education role vocabulary is already data-driven per tenant via `schools.staffRoles` (`ROLE_TEACHER`, `ROLE_LIBRARIAN`, `ROLE_SECURITY`, …), so *Employee → Teacher/Driver/Librarian/Support Staff* needs **no new capability and no new schema** — it is already how the system works.
- `orgType` + `labels` (Phase 1) handle vertical terminology and must **never** gate behaviour — presentation only.

---

## 7. Open decisions for you

Four boundary calls I should not make unilaterally:

1. **Payroll grandfathering — Option A or B (§5).** The one with real production consequences.
2. **Should `Payroll` become its own RBAC module?** It is not one today (`Payroll.php` uses `'HR'`), so any user with HR permission gets payroll wherever it is enabled — salary data is the platform's most sensitive, and "can administer HR" ≠ "may see everyone's pay". Splitting it is the right call on security grounds, but it is a cross-system change (`rbac_modules.json` is read byte-for-byte by PHP, the `staffCapabilities` Cloud Function, and transitively the Firestore rules — a known past drift incident). **Recommend: yes, but as its own authorized change, not bundled into the capability-key work.**
3. **Expenses / reimbursement — Core or packs?** Your Core list omits it; Corporate lists "corporate travel/expense extensions". Since no expense-claim workflow exists anywhere today (confirmed), the generic engine would be new build. **Recommend: generic expense claims in `hrms_core`; only corporate-specific extensions (project/client-coded expenses) in `hrms_corporate`.** Confirm.
4. **Timesheets — strictly Corporate?** Your spec places them in Corporate Pack. Some school and retail tenants may want hourly timesheets (e.g. visiting faculty, part-time associates). **Recommend: keep in `hrms_corporate` as specified for now** — do not pre-build for a demand that has not appeared. Flagging only so it is a decision, not an oversight.

---

## 8. Exact change required to make these keys real (not executed)

For reference only — one file, four lines, plus the §11 wiring from the entitlement analysis:

```php
// application/controllers/Superadmin_plans.php — AVAILABLE_MODULES (:16-41), appended:
        // HRMS (cross-vertical engines + optional vertical packs)
        'hrms_core'      => 'HRMS Core (Workforce)',
        'hrms_payroll'   => 'HRMS Payroll',
        'hrms_corporate' => 'HRMS Corporate Pack',
        'hrms_retail'    => 'HRMS Retail Pack',
```

Adding them here surfaces them in the plan-create/edit modals automatically (the view iterates the constant) and permits them through the POST whitelist. **On its own this grants nothing to anyone** — every key is absent from every existing plan, and the capability service is fail-closed, so all four are OFF for all 8 tenants until deliberately granted. Per-tenant grant still requires the add-on/override wiring from `ZENX_MULTI_TENANT_HRMS_ENTITLEMENT_ANALYSIS.md` §11 (steps 1–3), which remains unapproved and unimplemented.

Also required, per that analysis: grant-time validation rejecting invalid combinations (a pack without `hrms_core`; `hrms_payroll` without `hrms_core`).

---

## 9. Confirmations

- Four keys only: `hrms_core`, `hrms_payroll`, `hrms_corporate`, `hrms_retail`.
- HRMS Core and Payroll are **cross-vertical engines**, not per-vertical systems.
- Vertical packs contain **only** vertical-specific functionality — Employee / Attendance / Leave / Payroll / Documents / Onboarding / Lifecycle are **not** duplicated in any pack.
- **No Education pack.** Education integrates with the existing School ERP, which remains the source of truth for Education-specific entities.
- `attendance` is **not** duplicated. `staff_management` is **not** replaced. `student_management` is untouched.
- No separate HRMS system, tenant model, employee master, capability system, or accounting duplication. No RTDB.
- Backward compatibility preserved by the §4 layering rule; the one genuine regression risk is isolated and surfaced as §5 for your decision.
- Nothing implemented. `AVAILABLE_MODULES` unmodified. No live entitlement data touched.

**Stopping here for review of the capability boundaries.**
