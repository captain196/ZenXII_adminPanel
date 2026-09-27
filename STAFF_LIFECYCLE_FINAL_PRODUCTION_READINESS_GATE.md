# Staff Lifecycle — final production-readiness gate

**Regenerated 2026-08-23 from the repository.**

| | |
|---|---|
| Branch / HEAD | `integration/phase2` @ **`4afcf50`** |
| Committed | **nothing** |
| Admin suite | **4871 tests · 21,191 assertions · 10 failures · 5 intentional incompletes · 450 MB** |
| Statutory parity harness | 33 PASS, 1 pre-existing group-C limitation |
| `firestore.rules` | last written **2026-08-22 12:53** (D-004); untouched since |
| `firestore.indexes.json` | last written **2026-08-19**; never touched by this programme |
| JDK | **17.0.18** — rules emulator needs 21+ |
| Flags | all four verified `false` |

All 10 failures are pre-existing (`BackupTest` format drift; cross-repo tests grepping Kotlin source
via a hardcoded path from another machine). **Zero caused by this programme.**

---

## Status key

| Status | Meaning |
|---|---|
| **PASS** | Engineering complete |
| **CONFIGURATION REQUIRED** | Machinery exists and works; the tenant has not supplied a value |
| **OWNER/CA REQUIRED** | A real-world decision or fact is needed |
| **ENVIRONMENT BLOCKED** | Toolchain or deployment limitation |

---

## 1. Configuration completeness

Every tenant-configurable value, and whether a school can actually supply it.

| Value | Screen | Validated | Dated | Audited | Status |
|---|---|---|---|---|---|
| PF rates / ceiling / apply-ceiling / above-ceiling | Statutory tab | ✅ | ✅ | ✅ | **CONFIGURATION REQUIRED** |
| ESI rates / threshold | Statutory tab | ✅ | ✅ | ✅ | **CONFIGURATION REQUIRED** |
| **ESI applicability per location** | School Config → Locations | ✅ | n/a | ✅ | **CONFIGURATION REQUIRED** |
| PT slabs / TDS | Statutory tab | ✅ | ✅ | ✅ | **CONFIGURATION REQUIRED** |
| **Add-back threshold (CA-4)** | Statutory tab | ✅ | ✅ | ✅ | **PASS** — defaults to one-half, the position in force |
| **Gratuity policy (CA-7)** | Statutory tab | ✅ | ✅ | ✅ | **OWNER/CA REQUIRED** — transition treatment unset |
| Component wage classification | Wage Definition tab | ✅ | ✅ | ✅ | **OWNER/CA REQUIRED** (CA-1, CA-2) |
| **Employee PF membership (CA-6)** | Employee Salary tab | ✅ | ✅ | ✅ | **CONFIGURATION REQUIRED** |
| F&F notice-recovery basis | Full & Final tab | ✅ | n/a | ✅ | **OWNER REQUIRED** (DR-09) |
| F&F approval threshold | Full & Final tab | ✅ | n/a | ✅ | **OWNER REQUIRED** (DR-10) |
| F&F clearance scope / override | Full & Final tab | ✅ | n/a | ✅ | **CONFIGURATION REQUIRED** |

### Three gaps found and closed in this pass

**CA-4 — the add-back threshold was a developer setting.** It lived only in
`config/hrms_statutory_flags.php`: tenant-wide, undated, editable only by changing PHP. The proviso
to s.2(y) says the percentage may be **notified**, so a change must apply from its own date and must
not reprice earlier periods. Moved into the dated statutory configuration, where a tenant value now
outranks the PHP fallback and the fallback reports itself as such.

**CA-7 — gratuity had no configuration at all.** Now captured: qualifying years, maximum payable,
and the Nov-2025 transition treatment. **The statutory floor is enforced as a ceiling on what a
school may require** — paying after 3 years is accepted, requiring 7 is refused and clamped back to
5. The death/disablement waiver is recorded, not offered, because s.4(1) makes it unconditional.

**CA-8 — ESI per location had no form field.** Accepted, persisted and projected, but unsettable.
Now a tri-state control on the Locations screen.

### A defect the CA-8 work itself introduced

Writing `esiApplicable` unconditionally on update meant **renaming a location would wipe its ESI
determination** — the rename control posts only `{location_id, name}` — and everybody posted there
would quietly start or stop having ESI deducted. Fixed: an absent field now means "leave it alone",
and the audit line distinguishes *unchanged* from *set to inherit*.

---

## 2. UI/UX — **PASS**

Reached via **HR & Payroll → Compensation & Statutory**, added this session after the operator
reported the page had no menu entry at all.

| Check | Result |
|---|---|
| Page in the sidebar, gate matching the controller | ✅ |
| Every allowed tab has a route | ✅ — derived from the controller's own `$allowed` list |
| Every routed tab renders a panel | ✅ |
| Every endpoint is called from a screen | ✅ |
| Every `cmp-` class has CSS behind it | ✅ |
| Saves carry the CSRF token and fail closed | ✅ |
| Screens explain *why*, not just *what* | ✅ |

**Three reachability defects were found in UAT and fixed**, all the same shape: the page had no menu
entry; `fnf` and `classification` had no routes (so `/compensation/fnf` 404'd); and the employee
statutory profile and F&F preview had no screens. The weak test that let this through
(`assertStringContainsString("'fnf'", $controller)`) is replaced with assertions derived from the
controller's own list.

---

## 3–6. Dating, audit, RBAC, isolation

| Area | Status | Evidence |
|---|---|---|
| Effective dating | **PASS** | Statutory config, component classification and salary assignments all resolve through one shared `ed_select_for`; overlaps refused |
| Historical reproducibility | **PASS** | Editing a later version leaves earlier periods byte-identical; the resolved policy is frozen onto the run |
| Auditability | **PASS** | Every configuration write audited; classification records reason + verifier; arrears link to the run they correct |
| RBAC | **PASS** | Graded RBAC untouched; 54 gates at HEAD → 65 now, none lost |
| Tenant isolation | **PASS** | Every pure service asserted unable to reach tenant data — no `$this->fs`, no `schoolWhere`, no hard-coded `SCH_` |

**One near-miss worth recording:** a patch script removed the authorization gate from
`Hr::generate_payroll`. Two RBAC tests that *count* gates failed immediately. Restored, and the file
swept against HEAD.

---

## 7–8. Payroll, F&F, accounting, documents, notifications

| Area | Status |
|---|---|
| Payroll correctness | **PASS** (arithmetic) / **CONFIGURATION REQUIRED** (statutory base is flag-gated) |
| Off-cycle payroll | **PASS** — creatable, scoped, isolated; monthly uniqueness preserved |
| Arrears | **PASS** — per-period, prorated by that month's own length, linked to the original run |
| F&F | **PASS** engineering / **OWNER REQUIRED** for DR-09, DR-10 |
| Accounting | **PASS** — untouched; journals run-scoped |
| Documents / PDF | **PASS** — untouched |
| Notifications | **PASS** engineering / **OWNER REQUIRED** — `SALARY_PROCESSED` trigger resolved (fully-paid only); whether it fires is a product choice |

---

## 9–10. Adversarial, concurrency, cross-system — **PASS**

Gap between two closed statutory versions resolves to **null**, not the nearest. Employee crossing
the PF ceiling stays a member. Para 26(6) never gets EPS. Membership never applies before it begins.
Approval threshold boundary is exclusive. A threshold mode with no threshold fails closed to **two**
approvals. Clearance override needs both a nominated role and a reason. No projection can produce a
negative net. Nothing fatals on empty or hostile input. All resolvers deterministic.

**Cross-system: PASS.** No collection, field, endpoint, rule or index was removed or repurposed; new
fields are additive on existing documents. Teacher and Parent suites not re-run because no surface
they observe changed — stating that is more honest than quoting a stale pass count.

---

## 11. Firestore rules — **ENVIRONMENT BLOCKED**

JDK **17.0.18**; the emulator needs 21+. Verified this session, not assumed, not faked.

D-004 (staff leave read scope) is applied to the working tree and **not deployed**. D-003 and D-007
remain drafted. The local rules file is ~1,078 insertions ahead of HEAD, mostly other programmes' —
**review the whole-file diff before any deploy.**

---

## 12. Final re-verification — **PASS**

4871 tests, 10 pre-existing failures, 5 deliberate incompletes, 450 MB (62 MB headroom). No test
weakened or deleted; obsolete pins **inverted with written reasons**.

---

## What remains, by category

### OWNER/CA REQUIRED — real-world facts

| ID | Question | Who |
|---|---|---|
| OWN-1 | Accept the PF cost impact? | Owner |
| OWN-4 | Are pay-scale / DA structures used? | Owner |
| CA-1 | Which allowances are paid universally, uniformly, necessarily, ordinarily? | CA |
| CA-2 | Medical: fixed allowance or reimbursement? | CA |
| CA-3 | Current PF/ESI rates, ceilings, thresholds | CA |
| CA-4 | Is one-half still the notified percentage? | CA *(machinery complete, default correct)* |
| CA-5 | Restrict PF to the ceiling, or contribute above? | Owner + CA |
| CA-6 | Existing members above the ceiling / Para 26(6) | CA |
| CA-7 | Gratuity for service spanning 21 Nov 2025 | CA |
| CA-8 | Which locations are in implemented areas? | CA |
| DR-09 | Notice-recovery basis, per appointment letters | Owner |
| DR-10 | Approval threshold / delegation of authority | Owner |

**Every one now has a working screen behind it.** None blocks engineering; all block *activation*.

### ENVIRONMENT BLOCKED

Rules emulator (JDK 21+) · rules deployment authorization.

### Reported, not touched — other programmes

`student360` and `campus_access` have views but no menu entry — the same reachability defect,
belonging to SIS and Visitor Management.

---

## Safety state — explicitly confirmed

- **NO COMMIT · NO PUSH · NO DEPLOYMENT · NO MIGRATION**
- **NO STATUTORY ACTIVATION** — all four flags verified `false`
- **NO SHARED RULE MODIFICATION** — rules untouched since 2026-08-22 12:53; indexes never touched
- **NO UNRELATED PROGRAMME CHANGES** — the 3 staged files are another programme's

---

## Honest headline

**Engineering is complete. Every remaining item is a value a school or its accountant must supply,
and every one of them now has a screen, validation, dating and an audit trail waiting for it.**

The most important thing this pass produced is not a feature — it is that **a value can no longer be
"configurable" only in principle.** Three gaps closed here (add-back in a PHP file, gratuity absent,
ESI unsettable) plus three reachability defects found in UAT were all the same failure: machinery
built, and no way for a school to reach it. Tests now assert reachability end to end — menu entry,
tab route, screen, and CSS — so the next one fails in CI rather than in your browser.
