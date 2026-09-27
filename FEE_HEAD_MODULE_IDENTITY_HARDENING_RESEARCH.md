# MODULE-GENERATED FEE IDENTITY HARDENING — RESEARCH & DESIGN

**READ-ONLY. No code, schema, registry, demand, rules or index changed.**
2026-08-17 · **[ZC]** ZenX code · **[ZD]** ZenX data · **[ER]** external ERP · **[AI]** inference · **[REC]** recommendation

---

## 1. Executive conclusion

The Registry + `chargeInstance` architecture **survives adversarial review**. Nothing found argues for
redesigning it.

But the brief's premise — that the rename hazard is *one* defect in `createModuleFee()` — is **wrong,
and understated**. Tracing every identity path found **rename is unsafe through at least four
independent mechanisms**, three of which were not previously identified:

| # | Mechanism | Effect of a rename | New? |
| --- | --- | --- | --- |
| 1 | module `demandId` derives from the head name **[ZC]** | future module demands get a new id → duplicates | known |
| 2 | `canonicalFeeHeadId` is resolved **owner-by-name** **[ZC]** | auto-generate dedupe key changes → **duplicate chart demands** | **NEW** |
| 3 | concession `head:` scope falls back to **matching by name** **[ZC]** | a head-scoped concession silently stops applying → **money changes** | **NEW** |
| 4 | `proRateFees()` / `cancelSessionDemands()` select on `category`/name **[ZC]** | proration and rollover-cancel miss demands | **NEW** |

**[REC] Therefore: Q4 is NO — module fee heads cannot be safely renamed today, and neither can
ordinary chart heads.** Rename must be **blocked** (not warned) until every name-resolution site reads
`feeHeadRef`. The smallest safe correction for mechanism 1 is **not** feeding `feeHeadRef` into
`_demandId()` — it is **pinning an immutable `demandKey` on the registry entry** (§8), which changes
no existing id and needs no migration. Mechanisms 2–4 need their own conversions.

**Do not enable M5, and do not ship a rename UI, until §13's preconditions are met.**

## 2–3. Current architecture and identity flow

**[ZC]** `feeHeadRegistry/{school}_{feeHeadId}` → `feeStructures/{school}_{session}_{class}_{section}
.feeHeads[]` (now carrying `feeHeadRef`) → `Fee_lifecycle` spec → `Fee_firestore_txn::_buildDemandDoc()`
→ `feeDemands`. **[ZD]** 12 entries + 12 claims; 7 charts / 28 rows; 1,242 demands; one session.

## 4. Complete demand-writer map — **three identity schemes coexist**

**[ZC]** Every writer of `feeDemands`:

| Path | Id scheme | Idempotency | Rename-safe? |
| --- | --- | --- | --- |
| `Fee_lifecycle::assignInitialFees` (admission/chart) | `_demandId(stu, period, **feeHeadId**, name)` | same id ⇒ merge | **safe** — uses the chart row's id |
| `Fee_lifecycle::createModuleFee` (Transport/Hostel/ID-card) | `_demandId(stu, period, **''**, name+module, instance)` | same id ⇒ merge | **UNSAFE** (mech. 1) |
| `Fee_lifecycle` promotion re-key (`:1264`) | `_demandId(stu, period, feeHeadId, feeHead)` | same id ⇒ merge | safe |
| **`Fees::_auto_generate_student_demands`** | **`newDemandId()` = `bin2hex(random_bytes(10))`** | **pre-read dedupe on `(periodKey, canonicalFeeHeadId)`** | **UNSAFE** (mech. 2) |
| `Fees.php:5053` monthly loop | keys of `$newDemands` | — | inherits the above |
| `Fee_late_fee_service` | **writes no demand** — updates `fine_amount` on the existing one | n/a | n/a |

**[AI] This is the single most important structural fact in the review:** ZenX already contains **two
different, both-legitimate identity strategies** — a deterministic composite doc id, and a random doc
id guarded by an application-level natural key. Any hardening design must serve both; a change aimed
only at `_demandId()` would leave half the system untouched.

**Also found [ZC]:**
- `Fees::_buildDemandId()` is a **second, near-duplicate deterministic id builder with ZERO callers** —
  dead code, and a live trap: it keys on `feeHeadId` where `createModuleFee` keys on the name, so a
  future caller would silently produce a *different* id for the same charge. **[REC] Delete it.**
- Its `[DEM ID FALLBACK]` safety net uses `log_message('warning', …)`, and CI3's levels are
  `ERROR/DEBUG/INFO/ALL` **[ZC** `system/core/Log.php:106`**]** — so **that warning is silently
  dropped and has never been emitted.**

## 5. The exact name-derived identity problem

**[ZC]** `createModuleFee()` calls `_demandId($studentId, $periodKey, '', $headName.'_'.$moduleType,
$chargeInstance)`. With an empty `feeHeadId`, the key is the **uppercased head name**:
`Transport Fee` → `DEM_{stu}_{YYYYMM}_TRANSPORT_FEE_TRANSPORT`. Rename → `SCHOOL_BUS_FEE_TRANSPORT` →
a *different* demand, written alongside the original.

## 6. Existing-demand compatibility

**[ZC/ZD]** Nothing parses or reconstructs a demand id: accounting references `demand_id` only as a
value; Parent and Teacher store `demandId` as an opaque `String`; no regex, split or prefix test exists
outside probes. **[ZC]** Transport persists `fee_demand_ref` on the assignment doc — but it is written
only, read solely by `Transport_verifier`, and the functional reconciler keys on the sentinel
`'pending'`, never on the id. **[AI]** So a demand id is an opaque key with **one audit-trail
consumer**; changing future ids would leave stale breadcrumbs, not broken logic.

## 7. Recommended future identity model

**[REC] Do NOT put `feeHeadRef` into `_demandId()`.** It re-identifies every future module demand
against unchanged historical ones — the exact duplicate-charge outcome we must avoid — and buys nothing
that §8 doesn't buy for free.

**[REC] The correct model: the demand key becomes a *pinned, immutable string owned by the registry*,
not a live read of the display name.**

## 8. Controlled cutover — the smallest safe correction

**[REC]** Add one immutable field to each registry entry, set at mint time to *exactly the key existing
demands already use*:

```
feeHeadRegistry/{school}_{feeHeadId}
  demandKey: "TRANSPORT_FEE_TRANSPORT"     // immutable, pinned, never re-derived
```

`createModuleFee()` then uses `registry.demandKey` in place of `$headName.'_'.$moduleType`.

Why this is the right shape:

- **Existing ids are preserved by construction** — the pinned value *is* the current key, so
  before/after ids are byte-identical. No migration, no dual-read, no duplicates. **[AI]**
- **Rename becomes harmless for mechanism 1** — `name` changes, `demandKey` does not.
- **No flag-gated divergence**, so §5's "one process old, one process new" hazard cannot arise: both
  produce the same id.
- **Concurrency-safe** — identical ids still merge under FIN-1.
- For a *new* head, `demandKey` is minted from its name once and frozen.

**It does not fix mechanisms 2, 3 or 4.** Those need: `canonicalFeeHeadId` resolved from `feeHeadRef`
rather than name (2); concession scopes migrated from `head:<name>` to `head:<feeHeadRef>` (3);
category populated so `proRateFees`/`cancelSessionDemands` stop depending on names (4 — the registry
already enables this).

Answers to §5 A–L: existing demands untouched (A); future demands use the pinned key (B); no
old-vs-new branch is needed at all (C, D) — that is the design's main virtue; rename is safe once
2–4 land (E); archive keeps `demandKey` resolvable (F); a new head mints its own (G); rollover is
unaffected because the key has no session component (H); recalculation re-derives the same id from the
same pinned key (I); retry merges (J); a partial rollout is safe because both code paths compute the
same id (K, L).

## 9–10. Rename and archive policy

**[ER]** Neither ERPNext nor OpenEduCat treats a fee-category rename as a financial event — because in
both, identity is the record's own key and nothing derives identity from the label
([Fee Category](https://docs.frappe.io/erpnext/v13/user/manual/en/education/fee-category)). **[AI]**
ZenX cannot copy that assumption until mechanisms 1–4 are closed.

**[REC]**
- **Case A ordinary academic head — BLOCK today.** Mechanism 2 duplicates chart demands and mechanism 3
  breaks head-scoped concessions. This is *not* safer than the module case, contrary to the brief's
  framing.
- **Case B module head — BLOCK today**, allow after §8 + mechanism 2/3 conversion.
- **Case C archived head, similar name later** — allowed: a new head, new id, new `demandKey`. The
  archived one stays resolvable for history. The §13 anti-synonym workflow applies.
- Archive: `status='archived'` — excluded from new charts/pickers, still resolvable **[ZC]**. Never
  delete.

## 11. Custom school Fee Heads

**[REC]** Fee Head + Fee Structure, no new entity — corroborated by both products **[ER]**. Schools pick
a classification from `ASSIGNABLE` **[ZC]**; they never invent categories, because eligibility,
proration and rollover-cancel all branch on `category` **[ZC]** — a free-text category would make
those unpredictable, which is precisely the failure the registry exists to end.

## 12. `chargeInstance` generality — validated

**[ZC/AI]** The rule holds: an instance is needed only where one student can legitimately incur one head
twice in a period. ID-card **yes** (`cardVersion` is atomically claimed and monotonic); Trip/Competition
**yes**; Transport/Hostel **no** (one assignment per period; the writer is deliberately re-invoked on
the same id under FIN-1); **Late Fee — not a head at all**, it mutates `fine_amount` on the existing
demand **[ZC]**. **No separate Charge Instance collection** — no researched product has one **[ER]**.

## 13. Accounting

**[ZC]** `Operations_accounting` maps head → GL by **keyword-matching the name**; "School Bus Charges"
matches nothing and falls through to the default account. **[ZC]** It does **not** parse `demandId`.
**[ER]** ERPNext puts a Default Income Account on the Fee Category itself.
**[REC]** Mapping should eventually live on the registry entry with a Fee-Structure override. Severity:
**later defect** today (no custom heads exist yet), **immediate blocker the moment custom heads ship.**

## 14. Scholarship / concession

**[ZC]** `Fee_concession_reader::appliesTo()` and `Fee_entitlement_reader::appliesTo()` both implement
`head:<target>` as *"match the feeHeadId, else fall back to matching the head NAME"*. **[AI]** So a
head-scoped concession can be bound by name, and a rename silently unbinds it — the demand stops being
discounted and the family is charged more, with no error. **This is the most financially direct rename
consequence found.** Category on a generated demand is immutable and never re-derived **[ZC]**; unknown
heads fail closed **[ZC]**.

## 15. Parent / Teacher / Admin

**[ZC]** Parent `FeeDemandDoc` and Teacher `FeeDemandDoc` both hold `demandId` as a plain `String`
with a default — opaque, never parsed; unknown fields (including `chargeInstance`) are ignored by
Firestore `toObject()`. Parent's only category consumer is category-first with a name fallback, so a
custom head or unknown category **fails safe**. **No app change required.**

## 16. Session rollover

**[REC]** Invariant holds: one `feeHeadId` across sessions, different session pricing **[ER]** matches
ERPNext. `demandKey` (§8) has no session component, so rollover cannot alter identity. Module heads
roll over unchanged; `cancelSessionDemands` remains category-driven (mechanism 4).

## 17. Security / concurrency (design only — no rules edited)

**[REC]** `match /feeHeadRegistry/{doc}`: same-school read and write, `Fees manage` for writes,
name-claim docs never client-deletable, `status` filtered in the query. Index `schoolId + status`.
Concurrent creation is already handled by create-if-not-exists on the claim **[ZC]**. Stale claim: a
claim whose entry is missing should be reported, never auto-reclaimed. Rules must follow the
reconcile-first procedure — the working-tree digest has moved repeatedly and must be re-checked.

## 18–19. Migration and flag strategy

**[REC] No data migration is required, and none should be performed.** §8 changes no id. Firestore-only;
**no dual-read, no dual-write, no mirror, no bridge, no RTDB.**

**[REC] `USE_FEE_HEAD_REGISTRY` should gate only classification stamping (M5)** — i.e. whether the spec's
`category` comes from the registry. It must **not** gate identity: identity changes should be
correct-by-construction, not flag-dependent, precisely so a partial rollout cannot produce two ids for
one charge.

## 20. Test matrix

T1–T2 existing Transport/Hostel ids byte-identical · T3–T4 **new demand after rename keeps the same id
(the §8 proof)** · T5–T6 retry merges, `paidAmount` preserved · T7–T8 recalculation re-derives the same
id · T9 rollover keeps `feeHeadId` · T10 archived head excluded from new pricing, resolvable for
history · T11 rename leaves `demandKey`, ids and classification unchanged · T12–T14 custom heads
(academic/administrative) generate and classify · T15–T18 ID-card V2/V3/retry/two-in-one-month ·
T19–T20 Trip/Competition instances · T21 ordinary fee has no instance · T22 unknown head fails closed ·
T23 cross-school isolation · T24 concurrent head creation (claim collision) · T25 concurrent generation
(same id merges) · T26 mixed deployment produces identical ids · T27–T28 Parent/Teacher unaffected ·
T29 accounting mapping · T30 concession scope survives rename · T31 receipt/payment state preserved ·
T32 rollback.

**Added by this review:** **T33 auto-generate dedupe survives rename** (mechanism 2) · **T34
head-scoped concession survives rename** (mechanism 3) · **T35 `proRateFees` selects transport demands
after category is populated** (mechanism 4) · **T36 no second demand-id builder exists** (guards the
dead `_buildDemandId`) · **T37 `newDemandId()` randomness cannot produce duplicates under a concurrent
double-run.**

## 21. External comparison

**[ER]** ERPNext: Fee Category = name + description + default income account/cost centre, **no amount,
no frequency, reusable across academic years**; Fee Structure = academic year/term + (category, amount)
+ accounting; Fee Schedule = billing timeline; each charge becomes its own Sales Invoice.
OpenEduCat: heads per program/batch/year, explicit one-time charges, installments and late-fee rules as
configuration. **Neither has a charge-instance entity or per-head policy enums.** ZenX's split matches;
ZenX's difference is that it carries uniqueness in a composite key rather than a document series —
legitimate, but it is why name-in-key matters here and not there.

## 22. Risks

1. **Rename is unsafe through four mechanisms (§1)** — highest.
2. **`newDemandId()` randomness**: `_auto_generate_student_demands` is idempotent only via a pre-read;
   two concurrent runs could both see "absent" and both write → duplicate chart demands. Unproven in
   production, but structurally possible **[AI]**.
3. Dead `_buildDemandId()` with divergent keying.
4. Accounting name-keyword fall-through for custom heads.
5. Stale `fee_demand_ref` breadcrumbs if ids ever change (audit only).

## 23. Open decisions

(a) Adopt the pinned `demandKey` (§8)? (b) Block rename entirely until mechanisms 2–4 close, or ship
heads read-only-named first? (c) Delete `_buildDemandId()`? (d) Schedule the `canonicalFeeHeadId`
by-ref conversion? (e) Migrate concession `head:` scopes from name to ref?

## 24. Implementation sequence (proposed, not authorised)

1. Registry `demandKey`, pinned at mint = current key (proves byte-identical ids)
2. `createModuleFee()` reads `demandKey` — no id change
3. Delete `_buildDemandId()`
4. Rules + index, reconcile-first
5. M5 classification stamping behind the flag
6. `canonicalFeeHeadId` from `feeHeadRef` (mechanism 2) + concession scope migration (mechanism 3)
7. **Only then** unblock rename, and only then ship the operator head UI
8. Accounting mapping · then scholarship Phase C

## 25. DO NOT IMPLEMENT YET

Nothing in §8 or §24 is authorised. **No `_demandId()` change, no `feeHeadRef` in demand identity, no
registry field added, no rename UI, no accounting change, no concession migration, no flag, no rules,
no indexes, no migration, no backfill.** No commit, push, deploy, merge or rebase.

---

## Verdicts

| Q | Answer |
| --- | --- |
| Q1 schools create heads? | **Yes** — Fee Head + Fee Structure, no new entity |
| Q2 classification from controlled vocabulary? | **Yes — mandatory** |
| Q3 rename safe? | **Not today** — for *any* head, not just module heads |
| Q4 module heads renameable today? | **NO** |
| Q5 what's required? | Pinned `demandKey` (§8) **+** `canonicalFeeHeadId` by ref **+** concession scopes by ref. All three |
| Q6 existing Transport/Hostel demands unchanged? | **Yes — guaranteed by §8, which changes no id** |
| Q7 future demands on immutable identity? | **Yes — via the pinned key, NOT by putting `feeHeadRef` in `_demandId()`** |
| Q8 ID-card keeps `chargeInstance`? | **Yes** |
| Q9 Trip/Competition same mechanism? | **Yes** |
| Q10 separate Charge Instance collection? | **No** |
| Q11 accounting name → ref? | **Yes, eventually**; blocker once custom heads ship |
| Q12 scholarship keeps controlled category? | **Yes** |
| Q13 before M5? | Rules + index; confirm M5 gates classification only, never identity; rename blocked; §8 landed |
| Q14 safe to implement now? | §8 pinned `demandKey`; deleting `_buildDemandId()`; the rules/index design |
| Q15 deferred? | `feeHeadRef` in demand identity (never, as designed); rename UI; accounting mapping; concession migration; policy fields; Charge Instance entity |

**Sources:** [ERPNext Fee Category](https://docs.frappe.io/erpnext/v13/user/manual/en/education/fee-category) ·
[ERPNext Fee Structure](https://docs.frappe.io/education/fee-structure) ·
[ERPNext Fee Schedule](https://docs.frappe.io/education/fee-schedule) ·
[OpenEduCat Fees Management](https://doc.openeducat.org/applications/openeducat/fees-openeducat.html) ·
[OpenEduCat](https://openeducat.org/fees-management/)
