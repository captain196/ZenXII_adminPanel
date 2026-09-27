# M5 — FEE HEAD REGISTRY → PRODUCTION BILLING INTEGRATION

## FINAL STATUS: **PASS — READY FOR UAT / FLAG-OFF SOAK**

`USE_FEE_HEAD_REGISTRY = false`. Nothing committed. 2026-08-17

---

## 1. Executive summary

The registry is now wired into **all three live new-demand paths** behind one flag. With the flag OFF
every path is byte-identical to before; with it ON the school-scoped registry supplies identity and
classification, and an unresolvable head **aborts generation** rather than guessing.

## 2. Pre-implementation reconciliation

HEAD `758c924`, 0 commits. Rules `1090ecbf`, indexes `11ad46ea` — matching the gate, no concurrent
drift. Pre-change snapshot: 9 `feeStructures`, 28 rows, 28 with `feeHeadRef`.

## 3. Generation-path inventory

| Path | Creates new demand? | feeHeadRef source | category source (flag ON) | Gated |
| --- | --- | --- | --- | --- |
| `Fee_lifecycle::assignInitialFees` (admission/chart) | **Yes** | chart row via `headRefs` | registry | **Yes** |
| `Fee_lifecycle::createModuleFee` (Transport/Hostel/ID-card) | **Yes** | registry by exact name | registry | **Yes** |
| `Fees::_generateDemandsForMonth` (3 callers) | **Yes** | registry by exact name | registry | **Yes** |
| `Fee_lifecycle` promotion re-key | re-keys existing | n/a | unchanged | n/a |
| `Fees::_auto_generate_student_demands` | **ZERO CALLERS — dead code** | n/a | n/a | n/a |
| `Fee_late_fee_service` | **No** — updates `fine_amount` | n/a | n/a | n/a |

**No path bypasses the gate.** `_auto_generate_student_demands` (the random-id path) and
`Fees::_buildDemandId` are both dead; I left them untouched and re-registered them for removal.

## 4. Files changed (5 production, 2 test, 1 config)

`Fee_lifecycle.php` · `Fee_firestore_txn.php` · `Fees.php` · `Fee_head_resolver.php` ·
`Fee_firestore_sync.php` (prerequisite pass) · new `config/fee_head_registry.php` ·
new `tests/Unit/M5RegistryIntegrationTest.php` · `tests/Unit/FeeHeadRefDurabilityTest.php`.

## 5. Resolver integration

**One resolver, no second implementation.** Added `resolveByName()` for module and monthly callers,
which have no `feeHeadRef` yet. It reads the registry's **own name-claim document** — an exact-match
lookup of an authoritative record, **not** a keyword heuristic: `Transport`, `Bus Fee`,
`Transport Fees` and `School Transport Fee` all fail against a registered `Transport Fee` (tested).
Classification still comes from the registry entry.

`readFeeStructureWithIds()` now returns `headRefs` (name → registry id), because the chart expansion
previously collapsed to `name => amount` and dropped the only authoritative binding.

## 6. Feature flag

`USE_FEE_HEAD_REGISTRY`, default **false**, in its own config file. All three paths consult the same
gate, so the system is never half-registry / half-heuristic. OFF preserves legacy behaviour exactly —
including the legacy `_resolveFeeCategory()` heuristic, which now survives **only** on the flag-off
branch (asserted: exactly one call site, positioned after the gate).

## 7. New-demand data contract (flag ON)

`feeHeadRef` (registry identity) · `feeHead` (name snapshot at generation) · `category` (registry
classification snapshot) · `categorySource = 'registry'`.

`feeHeadRef` was added to the canonical writer whitelist — **without that it would have been silently
dropped**, the exact trap documented earlier. All three registry fields are conditional: empty ⇒ no
field, so flag-off documents stay byte-identical.

## 8. Legacy-demand protection

No migration, no backfill, no normalisation. A demand with `category=''` stays `''` — never promoted
to `academic` (tested). The out-of-contract legacy values (`Academic`, `Transport`, `General`) pass
through unchanged. No balance, payment state, due date or demand id touched.

## 9. Fail-closed behaviour

Admission returns `ok=false, error=fee_head_unresolved` and writes **zero** demands; module fees
return `null`; the monthly path returns `ok=false` and aborts. All three log
`ACC_FEEHEAD_UNRESOLVED` with path, school, head and reason. Refused: unknown ref, missing ref,
invalid/`unknown` category, archived head, unregistered module head, registry read failure.

## 10. Module-fee verification

Transport → `transport`, Hostel → `hostel`, ID-card → `administrative` + `chargeInstance V{n}`, each
resolved from the registry, each failing closed if unregistered. **No runtime head creation.**

## 11. Demand-ID preservation

**`feeHeadRef` never enters `demandId`.** Enforced mechanically across **both** `Fee_lifecycle.php`
and `Fees.php` by a comment-stripped scan of every `_demandId`/`_buildDemandId` call site, plus live
equality on the golden ids: `DEM_STU0012_202604_TRANSPORT_FEE_TRANSPORT` and `…_HOSTEL_FEE_HOSTEL`.
ID-card V2/V3 remain distinct; retry is stable.

**One ordering bug I introduced and caught:** `$moduleM5` was first placed before the array that
hardcodes `'category' => strtolower($moduleType)`, so the module name would have overridden the
registry. Moved to last in the merge.

## 12. Rules / index reconciliation

**Not modified.** Digests unchanged before and after: rules `1090ecbf`, indexes `11ad46ea`. Neither
change requires them — the registry is read server-side via the admin SDK, which bypasses rules. The
rules/index step remains a separate authorisation under reconcile-first.

## 13. Parent / Teacher compatibility

**No change made or required.** Neither app references `feeHeadRef` or `categorySource`; both are
additive and ignored by Firestore `toObject()`. Parent's transport filter is category-first with a
name fallback, so it is unaffected either way. No Kotlin touched.

## 14. Test results

| | Tests | Failures |
| --- | --- | --- |
| Baseline (pass start) | 1,954 | **10** |
| Final | **1,985** | **10** |

**New: 21** (`M5RegistryIntegrationTest`, all pass). **Zero new failures.** No test weakened, no
assertion deleted. The +31 total exceeds my 21 — the remainder is concurrent HRMS work, not mine.

## 15. Independent verification (separate process, pre/post diff)

```
registry 12 entries + 12 claims, identities unique
feeStructures BYTE-IDENTICAL (9 docs, 28 rows, 28 refs)
feeDemands 1242 · gained categorySource: 0 · gained feeHeadRef: 0
category distribution unchanged {(empty):1190, Academic:24, Transport:24, General:4}
net=502048.00 paid=0.00 balance=501950.00
students 150 · srNoIndex 0 · receipts/awards/entitlements 0
ALL CHECKS PASS — no live data changed.
```

## 16. Live-data impact

**None.** The flag is off; no generation ran against live data; no financial record was
read-modified-written.

## 17. Rollback

Flip the flag (already off) → legacy behaviour. Full revert = 5 production files + 1 config; the 24
registry docs and 28 `feeHeadRef` values are additive and deletable. No migration to undo.

## 18. Remaining risks

Module and monthly paths bind by **exact registered name** until they carry a `feeHeadRef` — a
narrower dependency than the old heuristic, but still a name-keyed *identity* lookup; retire it when
module callers carry refs. Chart-only rename still fails closed by design. The 52 out-of-contract
legacy values still need a policy. `academic` remains an eligibility bucket (open business question).
Rules/index still outstanding before any client reads the registry.

## 19–20. Scope confirmations

**Billing V2 NOT touched** — no `chargeType`, installment plans, quarterly, one-time-ever semantics,
annual contract value, due-date schedules, proration or rounding. Billing semantics are unchanged.
**Student Entitlement NOT implemented** — no concession allocation, no eligibility evaluation. M5 only
creates the data contract (`feeHeadRef` + `category` + `categorySource`) that it will consume.

## 21. Exact next authorisation required

1. **Rules + index** for `feeHeadRegistry`, under reconcile-first from the **then-current** digest.
2. **Flag-off soak**, then a controlled UAT that exercises admission, a module fee and monthly
   generation with the flag ON in the UAT tenant — **operator-executed**; I will not run live
   financial generation.
3. Only then: authorise flipping `USE_FEE_HEAD_REGISTRY` to true.

**Git:** HEAD `758c924` on `integration/phase2`, 0 commits. No push, deploy, merge or rebase.
Concurrent HRMS/Staff-Lifecycle work observed in the tree and left untouched.
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` · Firestore-only, no RTDB.
