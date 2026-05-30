# Fees Exemption v2 — Phase 2 (Option A) — cutover checklist & operator runbook

> **Audience:** Engineer + School Super Admin authorizing the production flag flip.
> **Status:** READY FOR REVIEW. Not yet executed. No flag in this runbook has been flipped.

---

## 0. Scope of this cutover

What gets turned ON:
- **Concession & Service capture UI** — staff can record per-student concessions with effective dates, types (percent / fixed / full-exempt), and approver.
- **Concession-aware fee generation** on Admission and Promotion only.

What stays OFF (deferred):
- Chart-Save (Save All Fees) — legacy gen — **Phase 2.5**
- Manual / Bulk Generate Demands — legacy gen — **Phase 3**
- Recalc Unpaid Discounts — legacy gen — **Phase 3**
- Service-Enrollment UI (transport/hostel/meals) — **Phase 3**

After this cutover: existing students with zero concessions on file see **byte-identical** behavior (gate-verified). Students with a recorded concession see a reduced demand the next time their fees are generated via Admission or Promotion.

---

## 1. Pre-flight checklist

Tick every box before flipping any flag. Skip nothing.

- [ ] **HEAD is `5709df6c` or later on `ankit/my-feature`.** Verify with `git log --oneline -1`. Earlier commits do not have the per-view smart-confirm wiring.
- [ ] **All Phase 2 flags currently OFF.** Confirm `application/config/fees_exemption_v2_flags.php` shows:
  ```
  USE_UNIFIED_FEE_GEN          = false
  CONCESSION_UI_ENABLED        = false
  SERVICE_ENROLLMENT_UI_ENABLED= false
  PHASE_3_CONVERGED            = false
  ```
- [ ] **A/B byte-identical probe is green RIGHT NOW.** Run:
  ```
  SCHOOL_ID=SCH_D94FE8F7AD php index.php fee_generation_ab_verify check
  ```
  Expected last line: `GATE: PASS — safe to authorize P2 flag flip`.
  If FAIL → STOP. Do not flip. Open an incident.
- [ ] **Firestore composite indexes for studentConcessions are deployed.** `firebase-rules/firestore.indexes.json` includes 3 indexes; check Firebase console → Firestore → Indexes that they are `Enabled`, not `Building`.
- [ ] **Firestore rules deployed.** `firebase-rules/firestore.rules` has `studentConcessions` and `studentServiceEnrollments` as server-only (`allow read, write: if false`). Verify in Firebase console.
- [ ] **Most recent Firestore backup is < 24h old.** This is a routing flip, not a data write; backups are the rollback fallback if something downstream breaks unexpectedly.
- [ ] **One operator standing by for canary verification** for the next 60 minutes after the lockstep flag flip.
- [ ] **Branch deployed to production server** (`/git pull` on the prod host). `php -l` clean across the 8 touched files (already verified during commit).
- [ ] **No active fee-generation operation in flight.** Check no school is currently in the middle of Promotion / Chart-save / Bulk Generate. Wait for any in-progress operation to drain.

---

## 2. Cutover sequence — LOCKSTEP (Option L)

The cutover is a **single lockstep flag flip**. `CONCESSION_UI_ENABLED` and `USE_UNIFIED_FEE_GEN` are flipped in the **same edit and same Apache restart**. We never ship a capture-without-billing state (operator's binding Path-B requirement).

### Step 2.1 — Lockstep flag flip

Edit [application/config/fees_exemption_v2_flags.php](application/config/fees_exemption_v2_flags.php) — flip **both** values in one save:

```diff
- $config['USE_UNIFIED_FEE_GEN']   = false; // Phase 2 cutover: true
- $config['CONCESSION_UI_ENABLED']  = false; // Phase 2 cutover: true (in lockstep with USE_UNIFIED_FEE_GEN)
+ $config['USE_UNIFIED_FEE_GEN']   = true;  // Phase 2 ACTIVATED YYYY-MM-DD HH:MM by <operator>
+ $config['CONCESSION_UI_ENABLED']  = true;  // Phase 2 ACTIVATED YYYY-MM-DD HH:MM by <operator>
```

Save the file, then Apache restart (`sudo service apache2 restart` or equivalent for the prod host).

Proceed **immediately** to §4 verification — do not wait. Total time from save → fully verified should be < 30 min.

### Step 2.2 — Cleanup canary data (after §4 passes)

- Revoke the canary concession created during V4 (Concessions screen → revoke), OR delete the doc from Firestore Console.
- If the V9 canary student got concession-reduced demands written, decide: keep (they really do deserve the concession) or undo (delete those demand docs from Firestore Console, then re-promote the student so the legacy path regenerates at gross).

### Step 2.3 — Broaden communication

Send to staff:
- The release note at [docs/FEES_EXEMPTION_V2_PHASE2_RELEASE_NOTE.md](FEES_EXEMPTION_V2_PHASE2_RELEASE_NOTE.md).
- Explicit note: "Phase 2 means Admission + Promotion apply concessions. To apply a concession to an existing student today, record it then re-promote them into the same class. Chart-Save, Manual Generate, Bulk Generate, and Recalc do not apply concessions yet — that's Phase 2.5/3."

### Step 2.4 — Soak

Watch for **7 days** before authorizing Phase 2.5. During soak:
- Daily: A/B probe should still be green (with the caveat that any students with concessions will legitimately diverge — that's a probe-mode question, not a regression).
- Daily: review the `studentConcessions` collection in Firestore for entries that look wrong (e.g., 200% percent, impossible dates, missing reason).
- Anyone reports billing surprise → investigate immediately, do not wait for the next day.

---

## 3. Rollback procedure (LOCKSTEP)

Three layers, escalating from cheapest to heaviest. Always start at Layer 1.

### 3.1 — Layer 1 · Instant rollback (under 60 seconds)

The lockstep cutover flipped both `USE_UNIFIED_FEE_GEN` and `CONCESSION_UI_ENABLED`. The lockstep rollback flips **both back together** in the same edit and same Apache restart — we never sit in a half-rolled-back state.

Edit [application/config/fees_exemption_v2_flags.php](application/config/fees_exemption_v2_flags.php):

```diff
- $config['USE_UNIFIED_FEE_GEN']   = true;
- $config['CONCESSION_UI_ENABLED']  = true;
+ $config['USE_UNIFIED_FEE_GEN']   = false; // Phase 2 rolled back YYYY-MM-DD HH:MM by <operator>
+ $config['CONCESSION_UI_ENABLED']  = false; // Phase 2 rolled back YYYY-MM-DD HH:MM by <operator>
```

Apache restart. Outcome:
- `_routedBuildAdmissionSpecs` immediately delegates to legacy `buildAdmissionDemandSpecs` — admission + promotion revert to pre-Phase-2 behavior.
- Concessions sidebar entry disappears; capture endpoints reject as "feature disabled".
- Smart-confirm modal goes dormant (`smartConfirmEnabled = false` after this flip).
- Concession docs already in Firestore are retained but inert. Demands already written during the live window with `concessionApplied` set remain — handle via Layer 2 if real billing impact.

### 3.2 — Layer 2 · Demand data rollback

Only run if a concession was applied to a **real (non-canary) student** during the live window and you need to undo:

1. Identify affected demands: in Firestore Console, filter `feeDemands` for the live-window time range; demands carrying the `concessionApplied` field.
2. For each affected **unpaid** demand: either delete the doc and re-promote the student into the same class+section (legacy path regenerates at gross), or hand-edit the amount back to gross via Firestore Console.
3. For each affected **paid** demand: leave it. Money already moved at the concession-reduced amount — the operator and the family both agreed at point of sale. Revoke the concession going forward instead.

### 3.3 — Layer 3 · Code revert

Only run if a structural bug surfaces in the routing delegate (not for operator-decision rollbacks).

```
git revert 5709df6c 51d7a048
git push origin ankit/my-feature
```

Then redeploy + Apache restart. This rips out the per-view comms (`5709df6c`) + the routing core (`51d7a048`). The P1 library code (`d8a7d490`) is dead code with the router gone — safe to leave or revert separately. P0-a (collections + capture controller, `02ff09da`) can also stay; concessions in Firestore remain but are not read by anything.

---

## 4. Activation verification — V1–V11 (run within 30 min of §2.1 Apache restart)

Run these in order. Failing any step → rollback per §3 Layer 1 and open an incident.

| # | Check | How | Expected |
|---|---|---|---|
| **V1** | A/B byte-identical probe still green | `SCHOOL_ID=SCH_D94FE8F7AD php index.php fee_generation_ab_verify check` | `pass: 9 / 9 · fail: 0 · GATE: PASS` (the 9 baseline students have zero concessions, so byte-identical) |
| **V2** | Concessions sidebar appears | Log in as Super Admin → look at left nav under Fees | "Concessions & Services" link visible |
| **V3** | Concessions screen renders active form | Click the new sidebar link | Student selector + concession form visible (NOT the "Under construction" placeholder) |
| **V4** | Capture round-trip works | Create a CANARY concession on a designated test student. Reason = "CUTOVER CANARY — delete after". | Concession appears in the list; visible in Firestore `studentConcessions` |
| **V5** | School-scope smart-confirm fires on Generate Demands | Fees → Generate Demands → pick the canary student's class → click Generate Demands | Amber modal: "This school has 1 active concession on file across 1 student". Click Cancel. |
| **V5b** | Recalc wrapper is wired (per-student smart-confirm path) | Fees → Scholarships → Awards tab → pick any existing award row → click the refresh icon. If that student has zero concessions, the modal does not fire and recalc runs (toast appears). If that student happens to have a concession, the per-student modal fires: "This student has 1 active concession on file:" with the concession listed. | Either outcome above is acceptable — both prove the wrapper is in place without breaking the original flow. |
| **V6** | Concession-aware green badge visible | Open student admission form | Green pill "Concession-aware" appears next to the Preview & Submit button |
| **V7** | Legacy-gen amber badge visible | Open Fees → Class Fee Setup, then Scholarships, then Generate Demands | Amber pill "Phase 2 — legacy gen" on Save All Fees, Scholarship Awards card header, Generate Demands button |
| **V8** | Canary 1 — no-concession student re-promotes byte-identically | Pick an Active student with NO concession; re-promote forward into same class+section then reverse | Firestore Console: that student's new demands unchanged in count, demandId pattern, head amounts vs. pre-flip state |
| **V9** | Canary 2 — concession applied via promotion | Re-promote the V4 canary student (who has the canary concession) | New demands reflect the concession (reduced amount); demand doc carries `concessionApplied` field |
| **V10** | Canary 3 — chart-save smart-confirm fires AND stays legacy by design | Fees → Class Fee Setup for the canary class → click Save All Fees | Amber modal fires. Click Save anyway. Chart-save proceeds; new demands do NOT carry concession reduction. Expected — chart-save is Phase 2.5 scope. |
| **V11** | Canary cleanup | Revoke the V4 canary concession; if V9 wrote concession-reduced demands, decide keep vs. undo (delete + re-promote) | Clean ledger; canary student returns to expected state |

All V1–V11 pass → broadcast the operator release note ([docs/FEES_EXEMPTION_V2_PHASE2_RELEASE_NOTE.md](FEES_EXEMPTION_V2_PHASE2_RELEASE_NOTE.md)) to staff. Enter §5 daily soak.

---

## 5. Health checks (daily during 7-day soak)

Run these every morning:

```bash
# 1. A/B byte-identical probe
SCHOOL_ID=SCH_D94FE8F7AD php index.php fee_generation_ab_verify check
# Expected: pass count matches the count of students with ZERO concessions.

# 2. Active concession count
# (Read in Firebase Console → Firestore → studentConcessions, filter status==active)
# Or via the school-scope endpoint:
#   POST /fee_concessions/check_school_active_concessions
# Watch for unexpected spikes — staff might be over-recording.

# 3. Recent demand audit
# Spot-check 3 random students in Firestore Console:
#   - schools/SCH_xxx/students/STUxxxx exists
#   - feeDemands for that student in the current session show coherent amounts
#   - if studentConcessions has an active concession for that student, the
#     latest demand should carry concessionApplied
```

Escalate if:
- A/B probe FAILs unexpectedly (i.e., a student with no concession diverges).
- A demand has a negative amount.
- Any student is double-billed (two demands for the same period+head).
- A staff member reports a parent disputing an unexpected charge.

---

## 6. Promotion to Phase 2.5

When 7 days have soaked clean:
- Read [memory/fees_exemption_v2_phase_2_5_chart_save_proposal.md](../memory/fees_exemption_v2_phase_2_5_chart_save_proposal.md).
- Answer the 5 D1–D5 operator decisions in that file.
- Authorize Strategy A implementation.
- Phase 2.5 needs no additional flag — the adapter activates inside chart-save once shipped, and only fires when the unified generator is already on (which it is post-Phase-2).

---

## 7. Quick-reference flag table (LOCKSTEP)

| Flag | Pre-cutover | After §2.1 lockstep flip | After Phase 3 |
|---|---|---|---|
| `USE_UNIFIED_FEE_GEN` | false | **true** | true |
| `CONCESSION_UI_ENABLED` | false | **true** | true |
| `SERVICE_ENROLLMENT_UI_ENABLED` | false | false | **true** |
| `PHASE_3_CONVERGED` | false | false | **true** |

The two Phase-2 flags above the dividing line **always move together** by operator policy. Never edit one without the other in the same save.

The single source of truth is [application/config/fees_exemption_v2_flags.php](../application/config/fees_exemption_v2_flags.php). Do not duplicate flag state anywhere else.

---

## 8. People to notify on cutover

- School Super Admin: pre-flight sign-off.
- All admin + accounts users: at §2.3 broadcast (capture + concession-aware billing both live).
- Engineering: throughout the 7-day soak — anyone gets a billing complaint, escalate same day.

---

## 9. Sign-off

This runbook is reviewed and approved by:

- [ ] Engineering lead (name, date): _________________________
- [ ] School Super Admin (name, date): _________________________

Cutover authorized to proceed on date: _________________________

Once both signatures are present, the engineer may execute §2.

---

*Runbook version: 1.1 — 2026-05-30. Revised to LOCKSTEP (Option L) per operator decision. Update on each phase cutover.*
