# FEE HEAD + CHARGE ARCHITECTURE — RESEARCH AND DESIGN REVIEW

**READ-ONLY RESEARCH. No code, schema, registry, demand, rules or index changed in this pass.**
2026-08-17

Evidence is tagged: **[ZC]** verified from ZenX code · **[ZD]** verified from ZenX live data ·
**[ER]** external ERP research (sourced) · **[AI]** architectural inference · **[REC]** recommendation.

---

## 0. STATE CONFLICT — READ FIRST

This brief says "do not implement `chargeInstance`" and "do not proceed to M4". **Both are already
done**, in the previous turn, under your authorisation that began *"I APPROVE the §B decision. Proceed
with adding `chargeInstance` to the canonical feeDemand writer whitelist"* and whose §12 said
*"proceed to M4"*.

Currently modified and **uncommitted**: `Fee_firestore_txn.php` (+`chargeInstance`, conditional),
`Fee_lifecycle.php` (`_demandId()` optional instance; `createModuleFee()` forwards it),
`Id_card_fee_policy.php` (stable head + `instanceKey()`), `Operations_accounting.php` (comment only),
3 updated + 2 new test files, and **28 chart rows carrying `feeHeadRef`** (M4).

Nothing is committed and rollback is exact (§21 below). **The research below is what should decide
whether that code stays.** I have made no further change.

---

## 1. Executive conclusion

**[REC] Keep the current architecture. Make `chargeInstance` explicitly generic. Build nothing else
now.**

External research validates ZenX's core split rather than overturning it: mature school ERPs separate a
**reusable, session-independent fee head catalogue** from **session-scoped pricing**, exactly as ZenX
now does. Neither of the two systems I examined has anything resembling a separate "Charge Instance"
entity — so **Architecture 3 is over-engineering** and should be rejected.

The one substantive correction the research produces is not about `chargeInstance` at all. It is this:
**ZenX's module demand ids still derive from the fee head NAME**, and my change fixed that only for
ID-card. Transport and Hostel remain name-derived, so renaming "Transport Fee" would change future
demand ids and duplicate live charges. That is a real, currently-unmitigated fragility (§15), and it
matters more than anything in this brief's question list.

School-configurable fee heads: **yes, and ZenX is already 90% there** — but confined to
**Fee Head + Fee Structure**. No charge-policy fields on the head yet.

## 2. External ERP research findings

### 2.1 ERPNext / Frappe Education

**[ER] Fee Category** — the direct analogue of a ZenX Fee Head — holds only **Name, Description**, and
(v15.4+) **Company, Default Income Account, Default Cost Center**. It carries **no amount and no
frequency**, and is **"reusable and flexible… across academic periods without requiring annual
recreation"**. Accounting defaults follow a precedence chain: Company → Item Group → Fee Category.
([Fee Category](https://docs.frappe.io/erpnext/v13/user/manual/en/education/fee-category))

**[ER] Fee Structure** carries **Program, Student Category, Academic Term, Academic Year**, a
**Components Table of (Fees Category, Amount)**, plus **Receivable Account, Income Account, Company,
Cost Center**. v15.1 added **discounts inside fee components**.
([Fee Structure](https://docs.frappe.io/education/fee-structure))

**[ER] Fee Schedule** distributes the structure across payment periods for a Student Group, and on
submit generates **Sales Invoices** per student.
([Fee Schedule](https://docs.frappe.io/education/fee-schedule))

**The architectural pattern, extracted:** catalogue (session-independent) → priced structure
(session-scoped) → schedule (billing timeline) → **document per charge** (Fees / Sales Invoice, with
its own naming-series identity).

### 2.2 OpenEduCat (Odoo)

**[ER]** Fee heads are configured separately "so finance teams can report by category, department,
batch, and academic period", defined "by program, batch, and year". It covers tuition, hostel, lab,
exam, transport, library, activity **and explicitly "one-time charges"**. Installment schedules
(monthly/quarterly/custom) and **late-fee rules** are part of fee *configuration*, not of the head.
([Fees Management](https://doc.openeducat.org/applications/openeducat/fees-openeducat.html),
[Fee Management](https://openeducat.org/fees-management/))

### 2.3 What the research does NOT show

**[ER]** Neither product exposes a generic "charge instance" entity, a per-head `chargeModel` enum, or
`refundable`/`proratable` flags on the head. Repeated charges of one head are handled by **each charge
being its own document**. **[AI]** That is a document-centric answer to the same problem ZenX solves
with a composite natural key — a legitimate difference, not a defect, but it means ZenX carries the
uniqueness burden in the key rather than in a series.

I found no documented product feature for preventing synonym proliferation
("Transport" / "Transport Fee" / "Bus Fee"). **[AI]** In both products that is master-data governance —
a workflow and permissions concern, not a schema one.

## 3. ZenX current architecture — verified

**[ZC]** `feeHeadRegistry/{schoolId}_{feeHeadId}` (school-scoped identity + `category` + `status`) →
`feeStructures/{school}_{session}_{class}_{section}.feeHeads[]` (session pricing: amount, frequency,
billingType, now `feeHeadRef`) → `Fee_lifecycle` spec → `Fee_firestore_txn` → `feeDemands`.

**[ZD]** 12 registry entries + 12 claims; 7 charts / 28 rows; 1,242 demands; one session live.

**[ZC] No hardcoded fee-head names exist in the fee engine** — `Fee_lifecycle`,
`Fee_generation_service` and `Fees.php` mention them only in comments and examples. Custom heads
therefore cannot break generation.

## 4. The ID-card problem — restated precisely

**[ZC]** `createModuleFee()` calls `_demandId($studentId, $periodKey, '', $headName . '_' . $moduleType)`
— an **empty** `feeHeadId`, so the key derives from the **head name**. The card version used to live
inside that name, which is why two replacements produced two demands. That made a mutable display
string load-bearing for financial identity, and produced an unbounded head-name family.

## 5–8. Fee Head vs Fee Structure — the model is validated

**[REC]** A ZenX Fee Head is **(C) a stable school-scoped financial entity** carrying **(B) a
controlled classification** — *not* a reusable charge definition (D). Answer: **C + B**.

The proposed hierarchy in your §8 is **correct and corroborated**: ERPNext's Fee Category ↔ ZenX Fee
Head (name + classification + accounting defaults, no amount, reusable across years); ERPNext's Fee
Structure ↔ ZenX Fee Structure (academic year/term + components + amounts). **[REC] Adopt it as is.**

**Fields evaluated for the head, individually:**

| Field | Verdict |
| --- | --- |
| `chargeModel`, `amountMode`, `defaultFrequency` | **Reject now.** Frequency already lives on the chart row **[ZC]**; ERPNext keeps it out of the category **[ER]**. A second home for one fact is a divergence bug waiting to happen |
| `instanceRequired` | **Defer.** Only justified once a second instance-bearing head exists (Trip). Today it would guard one caller |
| `refundable`, `proratable`, `optional` | **Reject now.** No ZenX code reads any of them **[ZC]**; `proRateFees()` is driven by transport assignment, not head policy |
| accounting mapping | **Defer, but real.** ERPNext puts *default* income account + cost centre on the category **[ER]**. ZenX instead keyword-matches head names in `Operations_accounting` **[ZC]** — the same name-as-authority anti-pattern, in the accounting layer. Worth a later phase; out of scope here |
| `module source` | **Reject.** One head can be raised by more than one path (chart *and* runtime Transport) |

## 9–10. Charge instance — which heads genuinely need one

**[ZC/AI]** The test is: *can the same student legitimately incur this head twice in one billing
period?*

| Head | Instance needed? | Evidence |
| --- | --- | --- |
| ID Card Replacement | **Yes** | `cardVersion` is atomically claimed and monotonic **[ZC]**; two losses in a month are legitimate |
| Educational Trip | **Yes** (future) | two trips in one term are ordinary |
| Competition Fee | **Yes** (future) | same |
| Transport | **No** | one assignment per period; the writer is deliberately re-invoked on the *same* demandId (FIN-1) **[ZC]** |
| Hostel | **No** | same shape |
| **Late Fee** | **No — and it is not a Fee Head at all** | **[ZC]** `Fee_late_fee_service` writes `fine_amount` **onto the existing demand**; it raises no demand and needs no head |
| Tuition/Exam/Registration/Library/Admission | **No** | one per period by construction |

**[REC] `chargeInstance` = option (B), a generic optional `feeDemand` concept.** Generic because Trip
and Competition will need the identical mechanism and an ID-card-specific field would be renamed
within a year; optional because forcing an instance onto Transport or Tuition would invent identity
where none exists. **Not (C)** — a separate entity buys nothing ERPNext-style documents would, at the
cost of a new collection, new rules, new indexes and a new join on every generation.

## 11. Charge model vs billing frequency

**[REC]** These are genuinely two dimensions, and the brief is right to separate them — but ZenX
should model **only one of them now**. Billing frequency (`monthly`/`annual`) already exists on the
chart row **[ZC]**. "Charge model" (recurring / one-time / per-occurrence) is *currently expressible
without a field*: a head that appears in the chart is recurring; a head raised by a module event is
per-occurrence; and whether it repeats is answered by whether the caller supplies an instance.
**Adding an enum now would encode a distinction nothing reads.**

## 12. Identity vs display name — the hard rule

**[REC] A display name must never be the authoritative financial identity.** Tested:

| Scenario | Today |
| --- | --- |
| ID-card version | **Fixed** — version is now data, not name **[ZC]** |
| Head rename → existing demands | Safe — `demandId` is stored, never re-derived **[ZC]** |
| Head rename → **future** module demands | **NOT SAFE — see §15** |
| Session rollover | Safe — head identity is session-free **[ZC]** |
| Accounting | **Not safe** — `Operations_accounting` maps by name keyword **[ZC]** |

## 13. School custom charges

**[REC]** "Annual Smart Classroom Charge" etc. become **Fee Heads + Fee Structure rows** — nothing
more. This is exactly ERPNext's Fee Category + Fee Structure component **[ER]** and needs no new
concept.

**On synonyms** ("Transport" / "Transport Fee" / "Bus Fee" / "Bus Charges"): `nameNorm` uniqueness
**[ZC]** stops exact duplicates only, and no researched product solves the rest in schema **[ER]**.
**[REC]** Handle it in the operator workflow: a create form that searches existing heads first,
warns on near-matches, and requires an explicit "create new anyway"; plus archival rather than
deletion so the catalogue stays small. This is governance, not architecture — do not build a synonym
engine.

## 14. Module-generated fees

**[REC] Yes — one registry for both**, as currently designed. The evidence is that ZenX's Transport
head is *already* both: a chart row and a runtime module head, and M3 correctly collapsed them to one
identity **[ZD]**. Two registries would have made that impossible. The flow
`module → registered head → charge event → optional instance → spec → demand` is right.

## 15. ⚠ The finding that matters most — name-derived module demand ids

**[ZC]** `createModuleFee()` still passes `''` as `feeHeadId`, so **Transport and Hostel demand ids
are derived from the head name.** My ID-card change did not touch this — and deliberately so, because
changing it is what would duplicate every live Transport demand.

Consequence: **if a school renames "Transport Fee", future generations produce a different demandId
than existing demands, and the next cycle writes a second demand alongside each existing one.** With
school-configurable heads and rename allowed, that risk becomes reachable by an ordinary operator
action — where today it is only theoretical.

**[REC]** This is the strongest argument in the whole review, and it argues for finishing the
registry, not for redesigning it: at a controlled cutover, module fees should derive their key from
the **immutable `feeHeadRef`** instead of the name — but **only for demands created after the
cutover**, with existing demands left alone. That is a migration with a mapping table, not a flag
flip, and it is **not authorised or designed here.** Until then, **rename should be blocked or
warned-on for heads that have module-generated demands.**

## 16. Scholarship / concession impact

**[REC]** Classification **must stay a controlled vocabulary** — free-text categories would make
eligibility unpredictable, which is precisely the failure the registry exists to end. Schools choose
*which* category a head has (from `ASSIGNABLE`), never *what categories exist* **[ZC]**. A custom
"Activity Fee → academic" is then automatically scholarship-eligible, and "Smart Classroom →
administrative" automatically is not, with no engine change. Dynamic heads and controlled
classification are compatible precisely because they are separate fields.

## 17. Accounting impact

**[ZC]** `Operations_accounting` maps head → GL code by **keyword matching on the name** (`'id card'`,
`'transport'`, `'hostel'`…). A custom head like "Smart Classroom Charge" matches nothing and falls
through to the default. **[ER]** ERPNext solves this with a Default Income Account on the Fee
Category. **[REC]** ZenX should eventually do the same — accounting mapping belongs on the **Fee
Head**, with the Fee Structure able to override, and the posting layer reading the mapping rather than
the name. **Defer to a dedicated phase**; it is a real gap but not this one.

## 18. Cross-system impact

**[ZC]** Parent app: `FeeDemandDoc` is a Kotlin data class with defaults (unknown fields ignored), and
`TransportFeesRepository` is category-first with a name fallback. A custom head appears normally; an
unknown category simply isn't matched by the transport filter — it **fails safe**, showing the demand
in the general list. Teacher app has no fee-head or category coupling. **No app change is required for
custom heads.** `chargeInstance` is additive and ignored by both.

## 19. Session rollover

**[REC]** Correct as modelled: `FH_123` in 2026-27 and 2027-28 with different amounts. **[ER]** ERPNext
Fee Categories are explicitly reusable across years without recreation — the same model. Archive =
`status='archived'` (excluded from new charts, still resolvable for history) **[ZC]**; rename keeps the
id; a "similar name later" is a new head unless the operator picks the existing one — hence the §13
workflow.

## 20. Real-world workflow

The 11-step accountant workflow maps cleanly onto the current design. Operators need flexibility at
steps 1–5 (choose heads, classify, price, set applicability); ZenX must enforce invariants at 6–11
(identity, demand id, uniqueness, recalculation determinism, accounting linkage).

## 21. Architecture comparison

| | **A1** Registry + ID-card-specific instance | **A2** Registry + generic optional instance | **A3** Registry + separate Charge Instance entity |
| --- | --- | --- | --- |
| Complexity | Low | **Low** | High — new collection, rules, indexes, join |
| Flexibility | Poor — renamed within a year when Trip lands | **Good** | Highest, mostly unused |
| Financial safety | Same | **Same** | Same, more surface to get wrong |
| Implementation cost | ~0 (done) | **~0 (done)** | Weeks |
| Migration | None | **None** | Would re-identify demands |
| Module integration | One caller special-cased | **Uniform** | Uniform |
| Reporting/accounting | Equal | Equal | Marginally better grouping |
| Scholarship | Unaffected | Unaffected | Unaffected |
| ERP corroboration | — | Closest to ERPNext's per-charge document identity **[ER]** | No researched product does this |

**[REC] A2.** A1 is A2 with an artificial restriction; A3 is speculative generality with no evidence
behind it.

## 22–23. Decision matrix

| Q | Answer |
| --- | --- |
| Q1 school-defined heads? | **Yes** — corroborated by both products **[ER]** |
| Q2 rename? | **Yes** — but see Q24/§15 caveat for module heads |
| Q3 identity survives rename? | **Yes** — already true **[ZC]** |
| Q4 school-scoped? | **Yes** |
| Q5 session-independent? | **Yes** **[ER]** confirms |
| Q6 structure session-scoped? | **Yes** **[ER]** confirms |
| Q7 controlled vocabulary? | **Yes — non-negotiable** |
| Q8 schools choose classification? | **Yes, from the controlled list only** |
| Q9 charge behaviour configurable? | **No, not now** |
| Q10 head or structure? | Frequency → **structure** (already). Behaviour → neither yet |
| Q11 frequency ≠ charge model? | **Yes, separate** — but only frequency is modelled |
| Q12 generic `chargeInstance`? | **Yes — generic optional** |
| Q13 ID-card version → instance? | **Yes** |
| Q14 Transport instance? | **No** |
| Q15 Hostel instance? | **No** |
| Q16 trip/event instance? | **Yes, when built** |
| Q17 late fee instance? | **No** — it is a field on the demand, not a head **[ZC]** |
| Q18 one registry for module fees? | **Yes** |
| Q19 custom heads + scholarship? | **Yes**, via controlled category |
| Q20 accounting mapping on every head? | **Eventually yes** **[ER]**; defer |
| Q21 policy fields in registry? | **No, not now** |
| Q22 names in demand identity? | **No — but they still are, for Transport/Hostel (§15)** |
| Q23 `feeHeadRef` in `demandId`? | **Not now.** Only at a controlled migration (§15), never as a silent change |
| Q24 Transport/Hostel ids unchanged? | **Yes — mandatory** |
| Q25 migrate ID-card demands? | **No — zero exist** **[ZD]** |
| Q26 separate Charge Instance entity? | **No** |
| Q27 is `chargeInstance` sufficient? | **Yes** |
| Q28 minimum to implement now? | Registry + classification + generic optional instance — **i.e. what exists** |
| Q29 defer? | Policy fields, accounting mapping, charge-model enum, Charge Instance entity, `feeHeadRef`-in-demandId migration |
| Q30 best future support? | Head + Structure + optional instance, controlled vocabulary, governance workflow for naming |

## 24. Impact on completed work

| Item | Impact |
| --- | --- |
| **M3 registry (24 docs)** | **None — stands unchanged and correct** |
| **`chargeInstance`** | **Keep.** Already generic in the writer and in `_demandId()`; only `Id_card_fee_policy` supplies it. Recommend documenting it as generic rather than ID-card-specific |
| **`demandId`** | **No further change.** ID-card format changed (zero live demands); Transport/Hostel byte-identical |
| **M4 `feeHeadRef`** | Stands. Inert until cutover, and needed by everything above |
| **Migration** | None required |

## 25–27. Test strategy, sequencing, risks

Existing coverage (T39–T44 + writer persistence + the mechanical `feeHeadRef`-never-in-`demandId`
guard) is the right shape. **Add when Trip lands:** a second instance-bearing head proving the
mechanism is genuinely generic.

**Sequencing [REC]:** (1) accept current state; (2) rules + index for `feeHeadRegistry`; (3) M5 behind
the flag; (4) operator UI for heads with the §13 anti-synonym workflow; (5) **then** the §15 rename/
module-id risk; (6) accounting mapping; (7) scholarship Phase C.

**Risks:** §15 rename-vs-module-id is the top one and is currently unmitigated. Then: operator
classification errors (mitigated by provenance, not prevented); catalogue sprawl from synonyms;
accounting fall-through for custom heads (§17).

## 28. Final recommendation

**Keep the current architecture (A2). Do not build a Charge Instance entity. Do not add charge-policy
fields. Do not make `chargeInstance` ID-card-specific.** Allow school-defined heads confined to
Fee Head + Fee Structure with a controlled classification vocabulary.

**The implemented code is what this research recommends** — which I state plainly while noting I have
an obvious interest in that conclusion. The check on it: the recommendation *rejects* two things I
could have argued for (policy fields, generic Charge Instance entity), and surfaces one problem my
implementation does **not** solve and arguably should have flagged earlier — §15.

---

## Your decision

1. **Revert or keep?** The `chargeInstance` / ID-card / M4 work is uncommitted. Rollback: revert 4
   production files + 5 test files, delete 24 registry docs, remove `feeHeadRef` from 28 chart rows.
   No financial record was ever written. **My recommendation is keep**, for the reasons above — but it
   is your call, and I will revert without argument if you prefer to re-approve from a clean base.
2. **Confirm the deferrals** in Q29.
3. **Register §15** (module demand ids still name-derived) as a defect for a later controlled phase.

Nothing further implemented. `USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` ·
no RTDB · no scholarship, B3, N+1 or SRNO work · rules/indexes untouched · no commit, push, deploy,
merge or rebase.

**Sources:**
[ERPNext Fee Category](https://docs.frappe.io/erpnext/v13/user/manual/en/education/fee-category) ·
[ERPNext Fee Structure](https://docs.frappe.io/education/fee-structure) ·
[ERPNext Fee Schedule](https://docs.frappe.io/education/fee-schedule) ·
[OpenEduCat Fees Management](https://doc.openeducat.org/applications/openeducat/fees-openeducat.html) ·
[OpenEduCat Fee Management](https://openeducat.org/fees-management/)
