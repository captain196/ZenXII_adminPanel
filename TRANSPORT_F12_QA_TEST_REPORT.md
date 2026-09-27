# F12.1 CBSE Compliance — QA Test Report

**Date:** 2026-08-08
**Branch:** `integration/phase2` @ `758c924` (working tree — uncommitted)
**Tenant:** `SCH_D94FE8F7AD`, session `2026-27`
**Mode:** Adversarial QA. Transport module is frozen — **no production code was changed.**

> Release constraints honoured: no commits, no rebases, no release branch, no push, no deployment.

---

## 1. Verdict

**F12.1 PASSES. 149 assertions, zero functional defects.**

| Suite | Assertions | Result |
|---|---|---|
| `transport_probe_cmp` — functional probe (28 gates) | 33 | **33 pass / 0 fail** |
| `transport_qa_f12` — new adversarial harness (§3) | 56 | **56 pass / 0 fail** |
| Verifier CMP gates `CH-TRN-CMP-1..4` | 4 | **4 PASS** |
| HTTP authorization — 8 endpoints × 7 personas | 56 | **56 pass / 0 fail** |
| **Total** | **149** | **149 pass / 0 functional fail** |

Two additional assertions (`H1`, `H2`) report pre-existing **test-data orphans**. They are storage
residue with **no functional impact** — proven in §5, not assumed.

---

## 2. What was already covered, and what was missing

`transport_probe_cmp` (28 gates) covers the happy paths and the main rejects: rule-pack seeding,
evaluation, posture recomputation, fleet sweep isolation, attestation superseding evaluation,
waiver bounds, audit rows, and evidence discipline.

Gaps I found in that coverage, and closed with a new harness:

* **No boundary testing.** The probe checked "91 days rejected" but never "exactly 90 accepted", and
  "<20 chars rejected" but never "exactly 20 accepted". An off-by-one in either direction would pass.
* **No tier-boundary testing.** Tiers were only observed via whatever score the fixture happened to
  produce. The 85/84 and 65/64 edges were never exercised.
* **No status-factor testing.** The 1.0 / 0.75 / 0.5 / 0.0 mapping was never asserted directly, nor
  was fail-closed behaviour for an unknown status.
* **No multi-byte testing** of the reason-length bound.
* **No case-sensitivity testing** of status matching.
* **No orphan audit.** The probe reports orphaned compliance rows but never posture docs.

---

## 3. New adversarial harness — `Transport_qa_f12`

**READ-ONLY. Performs zero Firestore writes.** That constraint is deliberate: an earlier F12.1 probe
run left 114 orphaned rows behind, and a frozen module must not be mutated by its own QA.

### 3.1 How write paths were tested without writing

`attestRule()` / `waiveRule()` validate in a fixed order and throw before persisting:

```
_assertRuleTarget(vehicle, rule)  →  input validation  →  setEntity
```

Every **reject** case is therefore safe against a real vehicle — the exception fires before the write.

Proving the **accept** side is harder, because a fully valid call *would* write. The harness uses
**paired isolation**: make the bound under test valid and a *different* bound invalid, then assert
which error surfaced. If the other error is raised, the bound under test necessarily passed.

> To prove a 20-character reason is accepted, send 20 characters with a **+91-day** expiry and
> require the *90-day* error. The reason check must have passed for execution to reach the date
> check. Nothing is persisted either way.

Pure logic (`_statusFactor`, `_tierFor`) is invoked directly through reflection, so scoring and tier
boundaries are tested as functions rather than inferred from fixture data.

### 3.2 Results — 56 / 56

| § | Area | Tests | Notable assertions |
|---|---|---|---|
| A | Rule-pack integrity | 11 | 19 rules; weight **exactly 144**; 8 dispatch-blocking; codes unique; all 6 required keys; every `evaluation` in the enum; weights positive ints; both `DEFERRED_DERIVED_RULES` present **and** declared `evaluation=derived`; **A11** — every pack `code` is persisted as `rule_code` |
| B | Status → score factor | 8 | Compliant 1.0 · Expiring_Soon 0.75 · **Waived 0.5 (partial credit, never full)** · Non_Compliant 0.0 · Not_Assessed 0.0 · unknown **fails closed at 0.0** · empty 0.0 · match is case-sensitive |
| C | Score → tier boundaries | 10 | 100→Compliant, 99→Minor, **85→Minor / 84→Major**, **65→Major / 64→At_Risk**, 0→At_Risk; and the safety override: **blocked forces At_Risk even at a perfect 100** |
| D | Waiver bounds | 10 | 19 chars rejected / **exactly 20 accepted**; +91d rejected / **exactly +90d accepted**; missing, unparseable and back-dated expiries rejected; unknown rule and missing vehicle rejected; **reason length counted in characters, not bytes** |
| E | Attestation | 10 | `Waived`, `Not_Assessed`, `Expiring_Soon`, empty, and lowercase `compliant` all rejected; `Compliant`/`Non_Compliant` accepted; missing **and cross-tenant** evidence documents refused |
| F | Evidence discipline | 4 | link()/getMetadata() only; no Storage calls; no direct `documents`/`documentLinks` writes; no service-layer authorization |
| G | Tenant isolation | 3 | reads school-scoped; **zero** unscoped `query()`; row id `{vehicleId}_{ruleCode}` — no hand-rolled prefix, so no RC-3 double-prefix |

### 3.3 Findings worth calling out

* **Multi-byte safety confirmed (D8).** `WAIVER_REASON_MIN` is enforced with `mb_strlen`, so five
  emoji (20 *bytes*, 5 *characters*) are correctly rejected. A `strlen` implementation would have
  accepted that as a valid justification for waiving a safety rule.
* **Waived scores 0.5, not 1.0 (B3).** A waiver is partial credit — a fleet cannot reach a
  Compliant tier by waiving everything. Correct and deliberate.
* **`Not_Assessed` does not block dispatch, `Non_Compliant` does.** The blocking override is scoped
  to `Non_Compliant` only, so a fleet that has simply never run an evaluation is not hard-blocked.
  Verified in source and consistent with spec §2.5.
* **The dispatch block outranks the score (C8).** A vehicle with a perfect 100 is still `At_Risk`
  if any blocking rule is `Non_Compliant`. The safety rule cannot be out-scored.
* **Cross-tenant evidence is indistinguishable from missing (E8).** `getMetadata()` enforces
  `schoolId`, so both return null and both are refused with the same message. That is the correct
  fail-closed behaviour and avoids leaking the existence of another tenant's document.

---

## 4. QA integrity — my own harness defects

The harness reported **23 failures on its first run and 17 on its second. Every one was a bug in my
test code, not in F12.1.** Recording them because a QA report that hides its own false positives is
not trustworthy:

| # | Symptom | Root cause | Fix |
|---|---|---|---|
| 1 | `A4/A5/A6/A9` — "rule_code missing from all 19 rules" | `CBSE_RULE_PACK` declares **`code`**; I read `rule_code` | read `code` from the constant |
| 2 | `D1–D9`, `E1–E10` — `Class "Transport_service" not found` | `_assertRuleTarget()` references `Transport_service::COL_VEHICLES`; I never loaded that library | load `transport_service` |
| 3 | `F4` — "service performs its own authorization" | The only `has_permission()` occurrence is a **comment** stating it never calls it. The real gate (`CH-TRN-CMP-4`) strips comments; I did not | strip comments before scanning |
| 4 | `D10`/`E9` **passed falsely** | needle `not found` also matched `Class "Transport_service" not found` | tightened to `Vehicle not found` |
| 5 | Second run: all D/E failed with `ruleCode is required.` | my global find-replace also hit `pickTarget()`, which reads **persisted** rules (`rule_code`), not the constant (`code`) | reverted `pickTarget()`; added **A11** to pin the mapping |

Defect #4 is the important one. A loose assertion that passes for the wrong reason is worse than a
failing one — it is the same false-positive class that made an earlier HTTP-probe revision report 23
phantom passes. Defect #5 turned out to be productive: the `code` → `rule_code` shift between the
constant and the persisted document is a real contract that nothing was testing, and **A11** now
pins it. A regression that persisted `code` verbatim would silently break posture, attest, waive and
the admin tab at once.

---

## 5. Orphan audit — `H1` / `H2`

| Check | Result |
|---|---|
| `H1` orphaned `vehicleCompliance` rows | **114** across 6 dead vehicles — `VH0106`–`VH0111` |
| `H2` orphaned `vehicleCompliancePosture` docs | **6** — same vehicles |

`H2` is new information: previous reports enumerated only the compliance rows, never the posture
docs.

**Functional impact: none — verified, not assumed.** `Transport_compliance::dashboard()` drives from
`listVehicles()` and looks up posture **per live vehicle**; it never enumerates the posture
collection. `rebuildFleetPosture()` iterates vehicles the same way, and `isDispatchBlocked()` is
per-vehicle by id. An orphan therefore cannot appear in the dashboard, cannot skew the tier counts,
and cannot block a dispatch. It is inert storage residue.

**Cause:** a probe teardown that deletes its fixture vehicles but not their compliance children.
This is test-harness behaviour, not production code — production vehicle deletion was not implicated
by these tests, and testing it would require writes, which the freeze forbids.

**Recommendation:** delete the 114 rows and 6 posture docs for `VH0106`–`VH0111` as an operator
cleanup. Not blocking; consistent with your ruling that verifier data artifacts do not block closure.

---

## 6. Authorization (already certified, re-confirmed)

The 8 compliance endpoints were exercised across all 7 personas in the module-wide HTTP probe —
**56 assertions, 0 failures**, with `Principal` (a role formerly in every allow-list, holding no
Transport capability) correctly denied on all of them.

| Endpoint | Required |
|---|---|
| `transport_compliance/dashboard` | view |
| `transport_compliance/vehicle` | view |
| `transport_compliance/attest` | edit |
| `transport_compliance/evaluate` | edit |
| `transport_compliance/waive` | **manage** |
| `transport_compliance/rebuild` | **manage** |
| `transport_compliance/seed_pack` | **manage** |
| `transport_compliance/rules` | **manage** |

`CH-TRN-CMP-4` pins this matrix and fails if any endpoint's level drifts or if the service
reintroduces authorization of its own.

---

## 7. Not tested, and why

Stated explicitly so the coverage claim is not read as broader than it is:

* **Write-path persistence** (a valid attest/waive actually landing, superseding, and emitting an
  audit row) — covered by `transport_probe_cmp` gates G18–G27, not re-tested here, because the
  freeze forbids writes.
* **The 2 deferred derived rules** (`CBSE_LADY_ATTENDANT`, `CBSE_OCCUPANCY_RATIO`) — declared
  `derived` and not auto-evaluated by design; their evaluation logic does not exist yet.
* **Production vehicle-deletion cascade** — whether deleting a vehicle through the UI cleans up its
  compliance children. Requires writes. This is the one genuinely open question behind §5.
  **Recorded 2026-08-08 as PF-1 in `TRANSPORT_POST_FREEZE_VALIDATION_BACKLOG.md`** — deferred rather
  than actioned, to keep the module frozen.
* **Concurrent attest/waive on the same row** — CAS behaviour is covered generally by
  `CH-TRN-CON*` gates but not specifically for compliance rows.

---

## 8. Artefacts

**New:** `application/controllers/Transport_qa_f12.php` — read-only QA harness, CLI-only, zero
writes. Re-runnable as regression coverage for F12.1, or deletable on request.

```bash
SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 php index.php transport_qa_f12 run
```

**Also awaiting your decision:** `Rbac_levels_inspect.php`, `Rbac_transport_impact.php` (both
CLI-only, read-only).

---

**Stopped for approval.** No commits, rebases, branches, pushes, or deployments.
