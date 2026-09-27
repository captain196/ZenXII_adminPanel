# TMS F12.1 — CBSE Transport Safety Compliance
## Architecture specification + pre-implementation analysis

**Date:** 2026-08-06 · **Amended:** 2026-08-07 (authorization sections only — see §11)
**Status: ✅ ACCEPTED — DESIGN FROZEN.** Implement **exactly** to this specification, in the
phased milestones of §10. Any deviation requires re-approval — this document, not a fresh design
pass, is the contract.
**Start condition:** F12.1 begins on the **`integration/phase2`** branch (operator, 2026-08-07).
**Scope decision:** CBSE pack only. Engine data-driven so ICSE/State are seed data, not code.
**Research basis:** `TRANSPORT_RESEARCH_AND_DESIGN.md` · prototype
https://claude.ai/code/artifact/a73c7bd8-8385-446f-b5bd-66daed2ce91d

> **Reconstruction note (2026-08-07):** this file was lost from the working tree before it was ever
> committed. It has been reconstructed verbatim from the accepted 2026-08-06 version, with only the
> authorization amendments of §11 applied. Business rules, workflows, data model, evaluation logic,
> UI behaviour, Firestore schema and milestone definitions are unchanged.

---

## 1. Pre-implementation analysis (7 fields)

**1 · Problem.** CBSE mandates GPS, CCTV, speed governor ≤40 km/h, a trained lady attendant,
first-aid, fire extinguisher, alarm bell, window grills, door locks, an emergency phone and a ≤1.5×
under-12 occupancy ratio in every school bus, in working condition at all times. The head of school
is personally liable and non-compliance can escalate to disaffiliation. ZenXII tracks 5 document
expiries on `vehicles` (`insurance_expiry`, `fitness_expiry`, `permit_expiry`, `pollution_expiry`,
`road_tax_expiry` — `Transport_service.php:585-615`) and **zero safety-equipment state**. There is
no way for a principal to answer "would we pass an inspection today?".

**2 · Root cause.** F1 modelled compliance as a side-effect of document expiry. Equipment, personnel
and operational requirements were never modelled at all, because no reference ERP models them either.

**3 · Change.** A data-driven rule pack evaluated per vehicle, producing per-rule state and a derived
posture with a disaffiliation-risk tier. New library `Transport_compliance_service`, new controller
`Transport_compliance`, 14 additive `vehicles` fields, 3 new collections, a compliance tab in the
existing Transport view, 4 verifier gates and 1 runtime probe.

**4 · Blast radius.** Admin-only. Zero Parent-app references, zero Teacher-app references, no new
notification categories at F12.1, no Fees or Accounting writer, no change to any existing write path.
Vehicle-field additions go through the existing `saveVehicle` merge-true path and are guarded by the
existing `_assertNoReservedGpsWrites`.

**5 · Cross-module verdict.** Consumes the Canonical Document Service (link-only, refcount — **no
service modification**); references HR staff ids for the lady attendant without copying records (the
F8 escort pattern); reads `vehicles` and driver `driver_meta`. Authorization consumes the shared RBAC
engine unchanged (§4). Nothing else touched. **CLEAR.**

**6 · Rollback.** Purely additive. Hide the compliance tab and the feature is inert — no destructive
migration, no backfill required, existing vehicles simply read `Not_Assessed`.

**7 · Residual risk.** Attestation can degrade into a rubber stamp. Mitigated by requiring an
evidence-document link for document-backed rules, capping waivers at 90 days with a mandatory
≥20-character reason, and auditing every actor.

---

## 2. Data model

*(Unchanged by the 2026-08-07 amendment.)*

### 2.1 `transportComplianceRules/{schoolId}_{ruleCode}`

Seeded per tenant on first pack selection; school-overridable for `severity_weight` and
`blocks_dispatch` only.

| Field | Type | Notes |
|---|---|---|
| `schoolId` | string | tenant scope |
| `rule_code` | string | see §2.4 |
| `label` | string | display name |
| `category` | string | `Equipment` \| `Document` \| `Personnel` \| `Operational` |
| `pack` | string | `CBSE` (F12.1) |
| `severity_weight` | int | 1–10 |
| `evaluation` | string | `manual_attest` \| `document_expiry` \| `field_check` \| `derived` |
| `source_field` | string | `field_check` only — the `vehicles` field read |
| `blocks_dispatch` | bool | default per §2.4 |
| `active` | bool | |
| `created_at` / `updated_at` | string | ISO-8601 |

### 2.2 `vehicleCompliance/{schoolId}_{vehicleId}_{ruleCode}`

| Field | Type | Notes |
|---|---|---|
| `schoolId` · `vehicleId` · `rule_code` | string | |
| `status` | string | `Not_Assessed` \| `Compliant` \| `Non_Compliant` \| `Expiring_Soon` \| `Waived` |
| `evidence_document_id` | string | Canonical Document Service ref — **link, never copy** |
| `attested_by` · `attested_at` | string | |
| `expires_on` | string | date; drives `Expiring_Soon` |
| `waiver_reason` · `waiver_by` · `waiver_expires_on` | string | required when `Waived` |
| `last_evaluated_at` · `notes` | string | |

### 2.3 `vehicleCompliancePosture/{schoolId}_{vehicleId}`

Derived, always rebuildable, never authoritative.

`schoolId` · `vehicleId` · `score` int 0–100 · `tier` (`Compliant` \| `Minor_Gaps` \| `Major_Gaps` \|
`At_Risk`) · `failing_rule_codes[]` · `expiring_within_30d[]` · `blocks_dispatch` bool ·
`rule_count` · `computed_at`

### 2.4 CBSE rule pack (19 rules)

| Code | Category | W | Eval | Blocks |
|---|---|---|---|---|
| `CBSE_GPS` | Equipment | 10 | field_check `gps_enabled` | Yes |
| `CBSE_CCTV` | Equipment | 10 | field_check `cctv_working` | Yes |
| `CBSE_SPEED_GOVERNOR` | Equipment | 10 | derived (`fitted` && `limit<=40`) | Yes |
| `CBSE_LADY_ATTENDANT` | Personnel | 9 | derived (HR active + female) | Yes |
| `CBSE_FIRST_AID` | Equipment | 7 | field_check | No |
| `CBSE_FIRE_EXT` | Equipment | 7 | document_expiry `fire_ext_expiry` | No |
| `CBSE_ALARM_BELL` | Equipment | 5 | field_check | No |
| `CBSE_WINDOW_GRILL` | Equipment | 5 | field_check | No |
| `CBSE_DOOR_LOCK` | Equipment | 6 | manual_attest | No |
| `CBSE_EMERGENCY_PHONE` | Equipment | 5 | field_check | No |
| `CBSE_OCCUPANCY_RATIO` | Operational | 8 | derived (assigned ≤ 1.5× capacity for under-12) | No |
| `DOC_FITNESS` | Document | 9 | document_expiry | Yes |
| `DOC_INSURANCE` | Document | 9 | document_expiry | Yes |
| `DOC_PERMIT` | Document | 8 | document_expiry | No |
| `DOC_POLLUTION` | Document | 6 | document_expiry | No |
| `DOC_ROAD_TAX` | Document | 5 | document_expiry | No |
| `DRV_LICENCE` | Personnel | 10 | derived (driver_meta) | Yes |
| `DRV_BADGE` | Personnel | 8 | derived (driver_meta) | Yes |
| `DRV_MEDICAL` | Personnel | 7 | derived (driver_meta) | No |

Total weight **144** (corrected 2026-08-07 — the prose previously read 154, which did not match the
19 tabulated weights; the table is authoritative and the rule definitions are unchanged. Verified by
hand, by `Transport_probe_cmp`, and against seeded Firestore data). `CH-TRN-CMP-1` must compute this
total FROM `Transport_compliance_service::CBSE_RULE_PACK` rather than hardcode it, so the gate cannot
drift permanently red the way `CH-TRN-SEC1-1` did after the RBAC claim migration.
`DRV_*` reuse the same `driver_meta` fields `_validateDriverReadiness` already checks — one source of
truth, two consumers.

### 2.5 Scoring

```
score = round( 100 * Σ(weight × factor) / Σ(weight) )
  factor: Compliant 1.0 · Waived 0.5 · Expiring_Soon 0.75 · Non_Compliant 0 · Not_Assessed 0

tier  = At_Risk      if any rule with blocks_dispatch is Non_Compliant   (override)
      | Compliant    if score == 100
      | Minor_Gaps   if score >= 85
      | Major_Gaps   if score >= 65
      | At_Risk      otherwise
```

`Waived` at 0.5 is deliberate: a waiver relieves the dispatch block but must never read as fully
compliant.

### 2.6 Vehicle field additions (14, additive)

`cctv_installed` bool · `cctv_working` bool · `cctv_last_checked` date ·
`speed_governor_fitted` bool · `speed_governor_limit_kmph` int · `lady_attendant_staff_id` string ·
`first_aid_kit` bool · `fire_extinguisher` bool · `fire_ext_expiry` date · `alarm_bell` bool ·
`window_grills` bool · `door_lock_verified` bool · `emergency_phone_no` string ·
`seating_capacity_under12` int

---

## 3. Service API — `Transport_compliance_service`

*(Unchanged by the 2026-08-07 amendment.)*

```php
init($firestoreService, $schoolId, $adminId): self
seedRulePack(string $pack = 'CBSE'): array        // idempotent; never overwrites overrides
listRules(): array
updateRule(string $ruleCode, array $input): array // severity_weight + blocks_dispatch only

evaluateVehicle(string $vehicleId): array         // runs all active rules, writes vehicleCompliance
attestRule(string $vehicleId, string $ruleCode, array $input): array   // CAS
waiveRule(string $vehicleId, string $ruleCode, array $input): array    // CAS, ≤90d, reason ≥20
computePosture(string $vehicleId): array          // derived write to vehicleCompliancePosture
rebuildFleetPosture(): array                      // per-vehicle isolated, never aborts

getVehicleCompliance(string $vehicleId): array
getFleetPosture(array $filters = []): array
isDispatchBlocked(string $vehicleId): bool        // consumed by createTrip when the toggle is on
```

**Concurrency.** `attestRule` / `waiveRule` use the existing RC-1 `casUpdateEntity`. `computePosture`
is last-write-wins — it is derived and idempotent.

**Audit.** Every mutation emits through the existing `emitAudit` into `transportAuditLog` with
`['domain' => 'compliance']`.

> **Authorization note.** The service layer performs **no** authorization of its own. All access
> control lives at the controller boundary (§4) and is delegated entirely to the shared RBAC engine.
> The service must never read session roles, never call `has_permission()`, and never implement a
> capability check — a service-level gate would be exactly the special-case authorization §4 forbids.

---

## 4. Controller — `Transport_compliance`  *(AMENDED 2026-08-07)*

### 4.1 Authorization model

F12.1 authorization is **entirely inherited** from the platform's canonical graded RBAC engine.
It introduces no module, no role, no level, and no special case.

- **Module:** the existing **`Transport`** module. F12.1 **must not** introduce a Compliance RBAC
  module. The catalogue lives in `functions/rbac_modules.json` and is read byte-for-byte by both
  `rbac_helper.php` (`RBAC_MODULES`) and `functions/staffCapabilities.js`; adding a module there is a
  cross-system change requiring a Cloud Function redeploy and a role backfill, and is out of scope.
- **Levels:** the canonical `view` < `edit` < `manage` (`RBAC_LEVELS`), monotonic — a `manage` grant
  satisfies `edit` and `view`.
- **Enforcement:** every endpoint calls
  `_require_role(self::VIEW_ROLES|MANAGE_ROLES, '<action>', 'Transport', '<level>')`
  — the canonical 4-arg form already used by all 39 endpoints in `Transport.php`.

### 4.2 Endpoint permission matrix

| Endpoint | Method | Module | Level |
|---|---|---|---|
| `dashboard` | GET | `Transport` | `view` |
| `vehicle/{id}` | GET | `Transport` | `view` |
| `attest` | POST | `Transport` | `edit` |
| `evaluate/{id}` | POST | `Transport` | `edit` |
| `waive` | POST | `Transport` | **`manage`** |
| `rebuild` | POST | `Transport` | `manage` |
| `seed_pack` | POST | `Transport` | `manage` |
| `rules` | GET/POST | `Transport` | `manage` |

### 4.3 Platform behaviours F12.1 inherits — and must not override

These are properties of the shared engine. F12.1 relies on them and adds nothing:

1. **Bypass roles.** `Super Admin` and `School Super Admin` pass everything (`RBAC_BYPASS_ROLES`).
   `Admin` is deliberately **not** a bypass — it resolves from its catalogue entry.
2. **Umbrella inheritance.** `Operations` is the parent of `Transport`
   (`RBAC_MODULE_CHILDREN`), so an `Operations` holder reaches compliance at the Operations level.
3. **Graded-authoritative denial.** If a caller holds `Transport` at a level *below* the requirement,
   `_require_role` denies and does **not** fall through to the legacy role-name list
   (`MY_Controller.php:1200-1216`). F12.1 depends on this; it must not be worked around.
4. **Per-staff overrides.** `extra` raises and `deny` is absolute and applied last
   (`apply_access_overrides`). A staff member denied `Transport` cannot reach compliance by any path.
5. **Legacy unleveled grants.** A held module with no explicit level resolves to `manage`
   (`rbac_granted_level`). On an un-folded tenant a `Transport` holder therefore satisfies `manage`
   and can waive. **This is the platform contract and F12.1 inherits it unchanged** — no F12.1-local
   floor, downgrade, or extra role check may be added to alter it.
6. **Denial UX.** Denials route to `admin/access_denied?module=Transport&need=<level>` via
   `rbac_denied_url()` — inherited automatically, nothing to implement.
7. **Surface.** `Transport` is surface `web` (`RBAC_MODULE_META`), so compliance never appears in the
   staff app. This is what makes the spec's zero-Kotlin-impact claim true.

### 4.4 UI authorization

The compliance tab reuses the Transport view's existing graded-RBAC layer — `$can_edit` /
`$can_manage` derived from `has_permission('Transport', …)`, the `rbac_ui_kit.css` read-only banner,
and `EDIT_ATTR` / `DEL_ATTR` on row actions. Waiver controls gate on `$can_manage`. This is UI
affordance only; §4.2 remains the enforcement boundary.

### 4.5 Prohibited

- No new RBAC module, role, level, or capability.
- No role-name authorization (`Principal`-only and equivalents are not expressible and would be
  skipped by the graded-authoritative rule in 4.3.3).
- No service-layer or Firestore-rules authorization exception for compliance beyond §5.
- No bypass, override, floor, or downgrade of any behaviour in §4.3.

---

## 5. Rules and indexes

*(Unchanged by the 2026-08-07 amendment.)*

```
match /transportComplianceRules/{id} {
  allow read:  if isSameSchool() && isStaff();
  allow write: if false;
}
match /vehicleCompliance/{id} {
  allow read:  if isSameSchool() && isStaff();
  allow write: if false;
}
match /vehicleCompliancePosture/{id} {
  allow read:  if isSameSchool() && isStaff();
  allow write: if false;
}
```

All three are server-only writes — the admin panel writes via the service with Admin SDK credentials,
exactly like `transportNoticeIdempotency`. Client-side capability grading is a panel concern (§4);
the rules layer stays a simple staff-read / server-write boundary and must not attempt to mirror
RBAC levels.

```
vehicleCompliance:        (schoolId ASC, status ASC, expires_on ASC)
vehicleCompliance:        (schoolId ASC, vehicleId ASC, rule_code ASC)
vehicleCompliancePosture: (schoolId ASC, tier ASC, score ASC)
```

DocIds use a **single** `{schoolId}_` prefix per `TMS_RC3_DOCID_CONVENTION.md`. No double-prefixing.

---

## 6. Read/write budget

| Operation | Reads | Writes |
|---|---|---|
| Dashboard (30 vehicles) | 30 posture docs, 1 query | 0 |
| Vehicle detail | 19 rule docs + 1 vehicle + 1 posture | 0 |
| Single attestation | 1 CAS read | 1 + 1 posture |
| `evaluateVehicle` | 1 vehicle + 1 driver + 19 rules | ≤19 + 1 |
| `rebuildFleetPosture` (30) | ~630 | ~600 |

Nightly rebuild extends the existing `Transport_expiry_cron` rather than adding a controller. No N+1:
the dashboard reads one posture doc per vehicle, never per-rule.

---

## 7. Verification

**Verifier gates (4)** — `CH-TRN-CMP-1` rule-pack completeness (19 CBSE rules, weights sum 154, every
`blocks_dispatch` intentional) · `CH-TRN-CMP-2` posture recomputability (stored == recomputed for
every vehicle) · `CH-TRN-CMP-3` waiver bounds (every `Waived` row has reason ≥20, actor, and
`waiver_expires_on` ≤ attest+90d) · `CH-TRN-CMP-4` evidence link-not-copy (source-scan: no Storage
write, no document duplication, `documentLinks` only).

**Runtime probe `Transport_probe_cmp`** (self-provisioning, CLI-gated, `removeEntity` teardown): seed
idempotency · attest→posture recompute · waiver bound rejection at 91 days · reason rejection at 19
chars · blocks_dispatch override forces `At_Risk` at score 90+ · rebuild isolation (one poisoned
vehicle does not abort the sweep) · tenant isolation (two schools, no bleed).

**Authorization verification (added 2026-08-07).** The M4 exit gate additionally requires a source
scan proving every endpoint supplies `('Transport', <level>)` per §4.2, and that no compliance file
contains a role-name authorization check or a `has_permission` call outside the view layer.

**Regression.** Full 94-gate verifier plus all existing Transport probes must stay green.

---

## 8. Estimate and gates

| File | Change | LoC |
|---|---|---|
| `application/libraries/Transport_compliance_service.php` | **new** | +450 |
| `application/libraries/Transport_service.php` | `saveVehicle` +14 fields; `isDispatchBlocked` hook in `createTrip` | +180 |
| `application/controllers/Transport_compliance.php` | **new**, 8 endpoints | +200 |
| `application/views/transport/index.php` + `partials/compliance_modal.php` | Compliance tab | +250 |
| `application/controllers/Transport_verifier.php` | +4 gates | +120 |
| `application/controllers/Transport_probe_cmp.php` | **new** | +150 |
| `application/controllers/Transport_expiry_cron.php` | nightly re-eval | +40 |
| `application/config/numbering.php` | `trn_compliance_waiver` | +5 |
| `firebase-rules/firestore.rules` | 3 blocks | +25 |
| `firebase-rules/firestore.indexes.json` | 3 indexes | +20 |

**~1,440 LoC across 10 files (4 new).**

| Gate | Satisfied by |
|---|---|
| 1 Functional correctness | probe `cmp` + 4 verifier gates |
| 2 Cross-module impact | §1.5 — additive; Document Service consumed not modified |
| 3 Tenant isolation | single `{schoolId}_` prefix, `schoolWhere` everywhere, probe case |
| 4 Rules + indexes | shipped in the same commit |
| 5 Both apps | **certified no impact** — `Transport` is surface `web`; zero Kotlin references |
| 6 Regression audit | full verifier + all existing probes re-run |
| 7 Rollback | hide the tab; feature inert; no migration |
| 8 Performance | posture precomputed; 1 read/vehicle; budget §6 |

---

## 9. Open decisions for the operator

1. **Dispatch blocking** — default `warn-only`, or hard-block trip creation for a non-compliant
   vehicle? Spec ships warn-only with a per-school toggle; hard-block is one line.
2. ~~**Waiver authority**~~ — **RESOLVED 2026-08-07.** Canonical `Transport:manage`, exactly as the
   shared RBAC engine defines. No role-name gate, no F12.1 exception. See §4.2 and §4.3.5.
3. **Lady-attendant check** — requires a gender field on the HR staff record. Does one exist, or does
   the rule degrade to "an attendant is assigned" without the gender assertion?
4. **Occupancy ratio** — needs `seating_capacity_under12` per vehicle *and* student age. Derive age
   from DOB on `students`, or attest manually at F12.1 and automate at F12.2?

Decisions 3 and 4 affect whether two rules are `derived` or `manual_attest`. Everything else is
unblocked. **17 of 19 rules can be built without any of these answers.**

---

## 10. Phased implementation milestones

Execute in order. **Do not merge milestones together.** Each is a separate work unit with its own
review. Do not start the next until the previous is explicitly approved.

### 10.0 Start condition and standing constraints

**Trigger: SATISFIED.** F12.1 begins on the **`integration/phase2`** branch (operator, 2026-08-07).
The previous "Phase 1 has shipped" condition is void — Phase 1 closed NO-GO and Phase 2 integration
is the release vehicle.

**Constraints in force for the entire duration of F12.1 — no exceptions, no expiry:**

| | |
|---|---|
| Commits | **None** |
| Rebases | **None** |
| Release branch | **Do not create** |
| Push | **None** |
| Deployment | **None** |

All work accumulates in the working tree. If any milestone appears to require one of the above,
**stop and ask** — do not proceed on the assumption that it was implied.

### 10.1 Per-milestone exit protocol (mandatory, all six, every milestone)

No milestone is complete until all six are performed and reported:

1. **Impact analysis** — inbound/outbound deps, shared collections, services, helpers touched.
2. **Runtime validation** — the milestone's own probe assertions executed and green.
3. **Regression testing** — all existing Transport probes re-run clean.
4. **Verifier validation** — the full gate suite green (94 baseline, rising to 98 by M5).
5. **Cross-module review** — Fees, Accounting, HR, SIS, Communication, Document Service, Parent app,
   Teacher app, crons, reports, rules, indexes, tenant isolation. State an explicit verdict; a
   "not applicable" must be justified, never assumed.
6. **Stop for approval.** Report and wait. Do not begin the next milestone.

If any of the six fails, the milestone is not done — fix it inside the milestone rather than carrying
the defect into the next one.

### M1 — Data foundation *(~500 LoC · no UI)*
`Transport_compliance_service` skeleton + `seedRulePack('CBSE')` + `listRules` / `updateRule`; the 3
collections; the 14 additive `vehicles` fields in `saveVehicle`; rules + indexes.
**Exit:** pack seeds idempotently for a test tenant; `php -l` clean; existing 94 gates still green;
no behaviour change anywhere else.

### M2 — Evaluation engine *(~400 LoC · no UI)*
`evaluateVehicle` across all four `evaluation` kinds; `computePosture`; `rebuildFleetPosture` with
per-vehicle isolation. Build the 17 rules unaffected by open decisions 3–4; stub
`CBSE_LADY_ATTENDANT` and `CBSE_OCCUPANCY_RATIO` as `manual_attest` pending answers.
**Exit:** scoring and tiering match §2.5 exactly, including the `blocks_dispatch` override; posture
is provably recomputable.

### M3 — Attestation + waivers *(~250 LoC · no UI)*
`attestRule` / `waiveRule` on RC-1 `casUpdateEntity`; the 90-day and ≥20-character bounds; evidence
linking through the Canonical Document Service (**link-only, refcount — no service modification**);
`transportAuditLog` emission on every mutation.
**Exit:** every §7 waiver-bound case rejected correctly; audit rows present for all mutations.

### M4 — Surface *(~450 LoC)*
`Transport_compliance` controller with the 8 endpoints, each authorized **exactly** per §4.2 using the
canonical 4-arg `_require_role(..., 'Transport', <level>)` form. Compliance tab + modal, built to the
approved prototype, reusing the Transport view's `$can_edit` / `$can_manage` layer per §4.4.

> **Authorization note (amended 2026-08-07).** The former note here — "use the current role strings
> and honour the RC-5 `role` vs `admin_role` session-key drift" — is **obsolete and removed**.
> `_require_role` reads `$this->admin_role` directly and the graded RBAC engine is now canonical.
> Implement §4 as written; add no authorization logic of your own.

**Exit:** every endpoint reachable by an authorized level and fail-closed below it, proven **over
HTTP** — not only by CLI probe. Source scan confirms no role-name checks and no authorization outside
the §4 boundary.

### M5 — Assurance + cron *(~190 LoC)*
The 4 verifier gates; `Transport_probe_cmp`; nightly re-evaluation folded into
`Transport_expiry_cron`; `trn_compliance_waiver` numbering kind.
**Exit:** 98 gates green (94 + 4); probe green; all existing probes re-run clean; full 8-gate review;
one local feature commit. **No push.**

---

## 11. Amendment log

**2026-08-07 — authorization alignment (operator-approved).** Scope limited to authorization
sections. Business rules, workflows, data model, evaluation logic, UI behaviour, Firestore schema and
milestone definitions untouched.

| § | Change |
|---|---|
| Header | Start condition set to `integration/phase2`; obsolete Phase 1 precondition removed |
| 1.5 | Cross-module verdict notes authorization is inherited unchanged |
| 3 | Added service-layer no-authorization rule |
| **4** | **Rewritten.** Legacy role-name model replaced with the canonical `Transport` module × `view`/`edit`/`manage`. Added §4.1 model, §4.2 endpoint matrix, §4.3 seven inherited platform behaviours, §4.4 UI authorization, §4.5 prohibitions |
| 5 | Clarified the rules layer must not mirror RBAC levels |
| 7 | Added the M4 authorization source-scan requirement |
| 8 | Gate 5 rationale now cites `Transport` surface `web` |
| 9 | Decision 2 (waiver authority) marked RESOLVED — canonical `Transport:manage` |
| 10.0 | Trigger marked SATISFIED |
| 10-M4 | Obsolete RC-5 `role` vs `admin_role` note removed and replaced |

**2026-08-07 — §2.4 total-weight correction (operator-approved, documentation only).** Prose figure
"154" corrected to **144** to match the 19 tabulated weights. Discovered during M1 implementation.
Rule definitions, weights, categories, evaluation kinds and `blocks_dispatch` flags are all
unchanged — this was a prose arithmetic error, not a data error. `CH-TRN-CMP-1` guidance added:
derive the total from the constant, never hardcode it.

*Design frozen. Specification only — no application code written.*
