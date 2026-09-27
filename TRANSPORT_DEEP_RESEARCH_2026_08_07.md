# Transport Module — Deep Research Refresh (2026-08-07)

**Supersedes:** `TRANSPORT_RESEARCH_AND_DESIGN.md` (2026-08-06) for competitive/gap findings.
**New in this pass:** RBAC Phase A integration audit, local-work-vs-canonical-branch comparison,
updated F12.1/F13 status, refreshed competitive check.
**Method:** codebase re-audit (git, verifier, rules) + web refresh, not a cold restart.

---

## Executive summary

1. Competitive landscape is **unchanged** since 2026-08-06 — no material Edunext/Fedena/ZenoxERP
   updates found. The two real gaps stand: **live GPS (F13)** and **CBSE compliance (F12.1, now
   spec-frozen and RBAC-aligned)**.
2. **The genuinely new finding is RBAC integration health, not a competitive gap.** The TL's RBAC
   Phase A landed across the whole ERP on 2026-08-06–07, and Transport absorbed it correctly at the
   platform layer — `driverIs()` migrated to `auth.uid`, a real regression (dropped `user_id` claim
   default) was caught and fixed during the TL's own Gate-9 certification, and 39 Transport endpoints
   are now graded `view`/`edit`/`manage`.
3. **But that grading exists only in the local working tree — HEAD still has the TL's original 14.**
   This is the actual integration gap right now: not a feature gap, an **unshipped-work gap**. 25
   graded endpoints, the view's RBAC/UX layer (12 files), and the F12.1 spec are uncommitted.
4. **Verifier holds at 94/5**, unchanged from the last two checks — the same five test-tenant data
   artifacts, zero code regressions across the RBAC merge, the Dual_write RTDB retirement, and my
   own RBAC alignment work.
5. **F12.1 is now the most concretely specified item in the entire Transport backlog** — 19-rule CBSE
   pack, full RBAC authorization model reusing `Transport` (no new module), 5 milestones, ready to
   implement. **F13 (GPS) is unchanged**: fully schema'd, zero telemetry, same design.
6. **Recommendation unchanged: ship F12.1 M1 next.** It is the only backlog item that is simultaneously
   fully specified, RBAC-aligned, zero app impact, and blocked on nothing but a go-ahead.

---

## 1. What's actually new since 2026-08-06

| Area | 2026-08-06 state | 2026-08-07 state |
|---|---|---|
| Branch | `integration/phase1` | `integration/phase2` (Phase 1 closed **NO-GO**, merged into `ank-yug_b1`, then merged into Phase 2) |
| RBAC | Legacy role-name lists only | **Graded RBAC Phase A** live platform-wide: `Transport` module, `view`/`edit`/`manage`, umbrella inheritance, per-staff `extra`/`deny` |
| Dual_write | RTDB engine present, my removal reverted twice | **Committed** (`758c924`) — RTDB engine gone, 0 executable RTDB calls |
| Transport.php | 14 graded endpoints (TL only) | **39 graded** in worktree, still **14 in HEAD** (uncommitted) |
| Transport view + 10 partials | No RBAC/UX layer | Full `$can_edit`/`$can_manage` layer applied, **uncommitted** |
| F12.1 spec | Frozen, legacy-role authorization model | **Amended** — canonical graded RBAC, `Transport` module reused, waiver = `Transport:manage` |
| Verifier | 94/5 | **94/5, unchanged** — zero regressions through all of the above |
| Known regression | — | `148c791`: RBAC merge dropped the `user_id` claim default on staff creation; **caught and fixed by the TL during their own Gate-9 cert**, before I even started this pass |

### 1.1 The `148c791` regression, resolved correctly

Worth stating plainly because it's a genuine positive signal: the TL's Staff.php merge silently
dropped `user_id => $staffId` from three `setCanonicalClaims()` calls. I verified the fix
(`Firebase.php:892-905`) defaults `ctx['user_id']` to the Auth `uid` centrally, so it can't be
dropped again by a future merge. **This is now provably harmless rather than merely fixed**: I
traced the actual live mechanism and confirmed `driverIs()` (`firestore.rules:235-239`) reads
`request.auth.uid`, not the custom `user_id` claim — and `auth.uid` is set explicitly to `staffId`
at account creation (`Staff.php:1469`, `:2346`). The claim default is defensive redundancy, not the
active mechanism. Two independent hardening passes, both correct, converged without conflict.

---

## 2. RBAC integration audit — Transport specifically

### 2.1 What the platform now provides

- **Single source of truth**: `functions/rbac_modules.json`, read byte-for-byte by both
  `rbac_helper.php` (PHP) and `functions/staffCapabilities.js` (Cloud Function) — the exact
  mechanism that once let Device Management/Message Monitor silently drift between the two.
- **`Transport`** is a registered module: group `Operations`, surface **`web`** (admin-only —
  confirms zero app impact for any Transport RBAC work).
- **Umbrella inheritance**: `Operations → [Library, Transport, Hostel, Inventory, Assets]`.
- **Graded levels**: `view(1) < edit(2) < manage(3)`, monotonic, resolved via `resolve_role_access()`
  (union over multi-role) → `apply_access_overrides()` (`extra` raises, `deny` absolute, applied
  last) → session materialisation each request.
- **Graded-authoritative denial**: if a caller holds `Transport` below the required level,
  `_require_role()` denies and does **not** fall through to the legacy role-name list
  (`MY_Controller.php:1200-1216`) — this is what makes role-name gates like "Principal only"
  inexpressible, and it's why F12.1's waiver logic had to change from role-name to
  `Transport:manage`.
- **Legacy unleveled grants default to `manage`** — an explicit, documented platform contract, not
  an oversight.

### 2.2 Transport's absorption of it

| Surface | Canonical (HEAD) | My local work | Gap |
|---|---|---|---|
| `Transport.php` endpoints | 14/39 graded | 39/39 graded | **25 endpoints uncommitted** |
| `views/transport/index.php` | No RBAC/UX layer | Full layer (banners, `EDIT_ATTR`/`DEL_ATTR`, guards) | **Uncommitted** |
| 10 view partials | No RBAC/UX layer | Full layer, self-contained (recomputed per-partial since `load->view()` passes no data) | **Uncommitted** |
| F12.1 spec | N/A (new feature) | Fully aligned to graded RBAC, `Transport:manage` for waiver | Design-only, not a gap |

**This table is the actual finding of this research pass.** There is no RBAC *design* gap in
Transport — there is an **unshipped-work gap**. The alignment work is done, verified (94/5, zero
regressions), and sitting in the working tree exactly where the Dual_write fix sat before it was
discarded twice.

### 2.3 One RBAC-adjacent judgment call worth re-surfacing

`CBSE_LADY_ATTENDANT` in F12.1 still needs a decision on whether HR staff records carry a gender
field. Unrelated to RBAC Phase A, but it's the only remaining item that blocks 2 of 19 compliance
rules — everything else in F12.1 is unblocked.

---

## 3. Competitive landscape — refreshed, materially unchanged

Web refresh against Edunext, Fedena, and general school-ERP RBAC practice found **no updates that
change the prior capability inventory**:

- Edunext's transport marketing is unchanged: GPS tracking, seat monitoring, route/stop management,
  parent avail/withdraw requests, hardware-coupled tracking device.
- Fedena's transport marketing is unchanged: stop-based fare, academic-year-scoped routes, vehicle
  document expiry, staff+student allotment.
- General RBAC best-practice search (2026) confirms the *shape* of what the platform just built is
  correct industry practice: deny-wins-absolute, continuous re-evaluation per request, a single
  shared capability source feeding both the web and mobile/app surfaces — this is precisely the
  `rbac_modules.json` dual-consumption design.

**No new anti-features, no new differentiators, no reason to revise the Phase 1 capability
inventory** (table stakes / competitive / differentiator / anti-feature) from the 2026-08-06 report.
It stands as written there.

---

## 4. Gap analysis — updated

### 4.1 Closed since last report
None of the substantive gaps closed — F12.1 and F13 are still specced, not built. What changed is
**readiness**: F12.1 went from "designed" to "designed, RBAC-amended, milestone-ready."

### 4.2 Still open (unchanged from 2026-08-06)

| Gap | Status |
|---|---|
| **Live GPS tracking (F13)** | Only table-stakes gap. Fully schema'd (`gps_session_id`, reserved null fields, Maps SDK already a Parent-app dependency). Zero telemetry. |
| **CBSE compliance (F12.1)** | Differentiator. **Spec frozen and RBAC-amended.** Ready for M1. |
| Stop-based fare policy | Improvement. Still zero `fare` construct in the service. Untouched, own cycle. |
| Parent-initiated avail/withdraw request | Improvement. Untouched. |
| Staff transport allotment | Improvement. Untouched. |

### 4.3 New item surfaced by this pass

| ID | Finding | Class | Severity |
|---|---|---|---|
| **P3-1** | 25 graded Transport endpoints + full view RBAC/UX layer + F12.1 spec sit uncommitted in the working tree, on a branch where two prior artifacts (the Dual_write fix, the spec file itself) were already lost or discarded once each | **Process risk**, not a code defect | **High** — not because the work is wrong, but because it has now demonstrably been the failure mode twice this week |

**This is the most actionable finding in this pass.** No amount of further design work fixes it;
only staging/committing does.

---

## 5. Recommendation — unchanged, now stronger

Ship **F12.1 M1** next. The case is stronger than on 2026-08-06:

- Fully specified, including the authorization model the TL's own RBAC work now requires
- Zero app impact, confirmed twice (surface `web`, and again via umbrella/module-reuse analysis)
- Verifier holds at 94/5 through every intervening change — the base is stable
- The RBAC alignment work Transport needed is *already done*, just needs to be staged so it survives

Nothing else in the backlog (F13, fare policy, parent requests, staff allotment) is this close to
buildable.

---

*Research refresh only. No application code written or modified in this pass. Awaiting go-ahead for
F12.1 M1.*
