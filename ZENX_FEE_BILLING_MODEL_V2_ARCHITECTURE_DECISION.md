# ZENX FEE BILLING MODEL — V2 ARCHITECTURE DECISION

**READ-ONLY. Nothing implemented.** 2026-08-17
**[F]** verified from code/data · **[O]** observed · **[ER]** external ERP · **[I]** inference · **[R]** recommendation · **[?]** business decision

---

## VERDICT

> **The current billing engine can be retained with specific changes — but the Fee *Structure* layer must be replaced.**

The demand layer is sound: deterministic ids, per-period separation, payment-state protection (FIN-1 / BUG-075), concession plumbing, `chargeInstance`. **The structure layer cannot represent the financial reality** — it conflates *annual*, *one-time* and *installment*, and it silently multiplies charges by 12 on a plausible operator mistake.

**Crucially, the fix is confined.** **[F]** `Fee_firestore_txn::readFeeStructure()` is the **single expansion point** where stored structure becomes the month-keyed chart the generator consumes. A richer structure model can be introduced **behind that one function**, leaving demand ids, dedupe, payment state, receipts, concessions and both apps untouched. **This is not an engine rebuild.**

---

## PART 1 — What the engine actually does

**[F]** The whole billing model is these ten lines (`readFeeStructure()`):

```php
$freq = strtolower((string) ($h['frequency'] ?? 'monthly'));
if ($freq === 'annual' || $freq === 'yearly') {
    $chart['Yearly Fees'][$name] = $amt;          // ONE entry
} else {
    foreach ($months as $m) $chart[$m][$name] = $amt;   // SAME amount × 12 months
}
```

then (`Fee_lifecycle`): `$targetMonths = $isYearly ? ['April'] : [$monthOrYearly];` — one demand per chart entry.

### A. What "Yearly Fees" means today

**[F]** A **generation bucket**, not a financial concept: *"emit exactly one demand for this session,
stamped to April."* It is not "annual tuition", not "one-time", and carries no once-ever semantics.
It is a `$isYearly` boolean plus a special key in a month map.

### B. What `frequency` means today

**[F]** A **binary switch** with exactly two outcomes — replicate to 12 months, or emit once. It is
**not** payment frequency, installment count, or recurrence configuration.

**[F] And it fails unsafely:** the branch tests only `annual|yearly`. **Every other value — including
`Quarterly`, `One-time`, `Half-yearly`, or a typo — falls to the ELSE branch and is replicated across
all 12 months.** Setting a ₹25,000 Admission Fee to `One-time` would generate **12 demands totalling
₹300,000**, silently. **[F]** `FREQUENCY_MAP` (`Monthly=>12, Quarterly=>4, Annual=>1, One-time=>1`) is
declared and **never referenced** — dead config that makes the system look more capable than it is.

### C. Tuition ₹48,000, frequency = Monthly

**[F] ZenX creates 12 demands of ₹48,000 each — ₹576,000.** It does **not** divide. The operator is
expected to enter ₹4,000. **This is a live financial trap**, reachable by an ordinary, reasonable
operator action, with no validation and no warning.

### D. Admission Fee ₹25,000, frequency = Yearly

**[F]** One demand **this session** — behaviourally correct today. **But "Yearly" means once *per
session*, not once *ever*.** **[I]** On rollover the next session's structure regenerates it, so a
true one-time Admission Fee is **re-charged every academic year**. Dedupe cannot prevent this:
`periodKey` embeds the year, so the new demand is legitimately distinct.

**[F] Confirmed conflation: ZenX has no way to express "charge once, ever".**

---

## PART 2 — Correct vocabulary (five distinct concepts, currently two)

| Concept | Question it answers | ZenX today **[F]** |
| --- | --- | --- |
| **Charge type** | *Does this recur?* recurring · one-time · repeatable | **absent** — inferred from the frequency bucket |
| **Billing frequency** | *How often is it billed?* monthly · quarterly · annual | **binary only** |
| **Installment plan** | *How is one amount split into scheduled payments?* | **absent** |
| **Applicability** | *Who owes it?* school/class/section/opt-in | class+section via the chart doc; opt-in via modules |
| **Amount model** | *Fixed / scheduled / per-student?* | fixed per period only |

**[ER]** Mature systems separate exactly these: *"fee heads can be created with their name and type
(recurring or one-time)"*, and separately *"for each fee head, the installment frequency and due dates
are defined for the entire academic year — e.g. quarterly tuition due 15 Apr / 15 Jul / 15 Oct / 15
Jan"*; an annual tuition *"split into 10 monthly installments creates 10 invoice lines … each with its
own due date and reminder sequence"*
([Campus24x7](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india),
[Classter](https://www.classter.com/blog/edtech/school-billing-and-tuition-management-software-a-complete-guide-for-administrators/)).
**[ER]** ERPNext splits this as **Fee Structure** (year/term + components + amounts) → **Fee Schedule**
(the payment timeline) → invoices
([Fee Structure](https://docs.frappe.io/education/fee-structure), [Fee Schedule](https://docs.frappe.io/education/fee-schedule)).

**[R] ZenX needs three of the five: charge type, billing frequency, installment plan.** Applicability
and amount model are adequate. **Do not add more.**

---

## PART 4 — Annual tuition: model comparison

| | **A** annual amount + frequency (system derives) | **B** explicit per-month amounts (**today**) | **C** annual amount + explicit installment schedule | **D** invoice-series (ERPNext) |
| --- | --- | --- | --- | --- |
| Operator effort | low | **high — 12 entries** | low | low |
| Rounding | system-owned, needs a remainder rule | none | **explicit, auditable** | explicit |
| Quarterly / custom splits | yes | no | **yes** | yes |
| Mid-session change | recompute remaining | edit each month | **edit unpaid installments only** | reissue |
| Audit ("why ₹4,000?") | derived, opaque | explicit | **explicit + traceable to the annual figure** | explicit |
| Accounting | needs annual-vs-instalment care | fine | **fine — annual is the contract, installments the schedule** | fine |
| ZenX migration | rewrites the chart shape | none | **additive behind `readFeeStructure()`** | large |

**[R] Model C.** It is what the market does **[ER]**, it keeps the annual contract value (what the
school actually agreed) separate from the payment schedule, it makes rounding explicit rather than
emergent, and — decisive for ZenX — **it can be expanded behind `readFeeStructure()` into exactly the
month-keyed chart the generator already consumes**, so no downstream code changes.

**Model B is not wrong, it is unscalable**: 12 entries per head per class, no quarterly, and it forces
the operator to do arithmetic the system should do — which is precisely how the §C ×12 trap arises.

---

## PART 5 — The ten scenarios

| # | Scenario | Today **[F]** | Under C **[R]** |
| --- | --- | --- | --- |
| 1 | Admission ₹25,000 one-time | one demand/session — **re-charged every session** | `chargeType=one_time` → generated once, never regenerated |
| 2 | Registration ₹2,000 one-time | same defect | same fix |
| 3 | Tuition ₹48,000 in 12 | **operator must enter ₹4,000 × 12** | annual ₹48,000 + monthly plan → 12 × ₹4,000 |
| 4 | Tuition ₹48,000 once | Yearly bucket → 1 demand ✓ | annual + single-installment plan |
| 5 | Transport ₹2,000/mo | ✓ module-generated | unchanged |
| 6 | Hostel ₹8,000/mo | ✓ module-generated | unchanged |
| 7 | ID card ₹500 repeatable | ✓ `chargeInstance` V2/V3 | unchanged — `chargeType=repeatable` |
| 8 | Custom school charge | possible; billing inherits the binary limit | school picks charge type + plan |
| 9 | **Mid-session admission (September)** | **[F]** admission generates **all 12 months including April–August**; `proRateFees()` only scales **annual/yearly** heads, and **[F]** its `category ?? feeHead` filter never matches legacy `category=''`, so it is **effectively inert** | plan-aware: generate from the join period; proration by policy |
| 10 | **Mid-session price change (Oct)** | **[F]** `_recalculateStudentUnpaidDemands()` rewrites **unpaid** demands; paid ones are protected by FIN-1/BUG-075 | unchanged — the protection is already correct |

**[F] Scenario 9 is a real current defect**: a September joiner is billed for April–August.

---

## PART 7 — The Fee Head identity model stands

**[R] No change.** Billing is a *structure* concern; identity is a *head* concern. The approved chain
holds: `School → feeHeadRegistry → feeHeadRef → Fee Structure → Canonical Spec → Fee Demand`.

| Field | Owner | Never owns |
| --- | --- | --- |
| `feeHeadRef` / `feeHeadRegistry` | identity, name, `category`, status | amount, frequency, session |
| **`chargeType`** | **Fee Head** — *what kind of charge is this?* | how much, when |
| **billing frequency, installment plan, amount** | **Fee Structure** (session-scoped) | identity, classification |
| `canonicalFeeHeadId`, chart `feeHeadId` | **frozen legacy provenance** | anything new |
| `demandId`, `chargeInstance` | Fee Demand | classification |

**[R]** `chargeType` belongs on the **head** (an Admission Fee is one-time by nature, in every session);
frequency and installments belong on the **structure** (the same Tuition head may be monthly this year,
quarterly next).

---

## PART 8 — Student Enlightenment needs none of this

**[F]** Eligibility keys on **`category`** only; the allocator consumes the generated spec set. It never
reads frequency, charge type or installments. A monthly Tuition demand and an annual Examination demand
are each evaluated on their own stamped category.

**[R] Student Entitlement requires: demand, amount, `category`, period, `feeHeadRef`, eligibility rules
— and nothing from the billing-model work.** **[F] Its one dependency remains M5** (stamping `category`
from the registry); admission demands still carry `category=''`.

**This is the answer to the sequencing question: the billing rebuild is NOT a prerequisite for Student
Enlightenment.**

---

## PART 9 — Accounting

**[F]** `Operations_accounting` maps head → GL by **name keyword**; custom heads fall through to the
default account. **[R]** Mapping belongs on the registry, keyed by `feeHeadRef` **[ER]** (ERPNext puts a
default income account on the Fee Category). **[R]** Accounting needs head-level GL mapping and the
recurring/one-time distinction for revenue recognition; it does **not** need installment-level mapping —
an installment is a *collection schedule*, not a separate revenue event. Blocker for custom heads only.

---

## PART 10 — Legacy audit

| Existing behaviour | Correct? | Why | Verdict |
| --- | --- | --- | --- |
| `Yearly Fees` bucket | **No** | conflates annual-recurring with one-time-ever | **Fix** — replace with `chargeType` + plan |
| `frequency` binary switch | **No** | unknown values silently ×12 | **Fix** |
| `FREQUENCY_MAP` | No | declared, never referenced | **Retire** |
| month-bucket chart shape | Partly | works, but unscalable and forces operator arithmetic | **Keep as the internal expansion target**, stop storing it |
| `_demandId()` | **Yes** | deterministic; per-period separation correct | **Keep** |
| `newDemandId()` (random) | Partly | idempotent only via pre-read; concurrency-fragile | **Keep**, register |
| `Fees::_buildDemandId()` | No | **zero callers**, divergent keying; its `[DEM ID FALLBACK]` guard uses `log_message('warning')`, and CI3 levels are ERROR/DEBUG/INFO/ALL — **it can never fire** | **Retire** |
| `canonicalFeeHeadId` | No | 3 values per head; never authoritative | **Freeze as legacy** |
| chart `feeHeadId` | Partly | minted by name inside a pricing save | **Keep as legacy demand key**; stop minting by name |
| `feeHeadRef` | **Yes** | the authority | **Keep — and make durable (erased on chart save)** |
| `_titles` | No | **session-keyed**, so it cannot be a head catalogue | **Retire** after the registry UI |
| proration | **No** | annual-only, and its filter never matches `category=''` | **Fix** with the billing work |
| late fees | **Yes** | `fine_amount` on the existing demand; not a head | **Keep** |
| module charges | **Yes** | one registry, `chargeInstance` where repeatable | **Keep** |
| due dates | **Yes** | every demand carries one | **Keep** |

---

## The 40 answers (condensed)

**1–9 — the vocabulary.** Fee Head = *what* the charge is (identity + name + category + **charge
type**). Fee Structure = *how much, for whom, for which session, on what schedule*. Charge type =
recurring / one-time / repeatable. Billing frequency = how often it is billed. Installment plan = how
one amount is split into scheduled, dated payments. Fee Demand = one student's obligation for one
period/occurrence. **"Annual" = billed once per session; "one-time" = charged once ever. [F] These are
different, and ZenX currently cannot distinguish them.** Yes — all distinct concepts.

**10–17.** Monthly tuition = annual amount + monthly plan → 12 dated installments. Annual paid once =
annual amount + single installment. Admission/Registration = `one_time`, generated once and **never
regenerated on rollover**. Transport/Hostel = module-generated recurring, unchanged. ID-card =
`repeatable` + `chargeInstance`. Custom charges = the school picks charge type and plan.

**18–21.** Different heads, different billing models — **[F] yes, already works**. Same head, different
config per session — yes (structure is session-scoped). Monthly ↔ quarterly ↔ annual — **not today**;
yes under C. Installment plans — not today; yes under C.

**22–28.** Mid-session admission should generate from the join period (**[F] today it bills April
onward — a defect**). Mid-session change edits unpaid demands only (**[F] already correct**). Paid
demands protected by FIN-1/BUG-075 (**[F] already correct**). Recalculation stays deterministic.
Proration must become plan-aware and category-correct. Late fees unchanged.

**29–30.** Scholarship interacts **only through `category`** — frequency-agnostic. Accounting should map
by `feeHeadRef`, not names.

**31–35.** `Yearly Fees` → replaced by charge type + plan. **Retain:** demand generation, ids, dedupe,
payment state, receipts, concessions, module charges, `chargeInstance`, the whole Fee Head registry.
**Retire:** `FREQUENCY_MAP`, `_buildDemandId()`, `_titles` as a catalogue. **Rebuild:** only the
structure representation and `readFeeStructure()` expansion. **Do NOT rebuild:** demand identity,
payment, receipts, accounting posting, scholarship, or the apps.

**36–37.** **Migration: none required.** New structure fields are additive; existing charts keep
working through a compatibility branch in `readFeeStructure()` (old shape → today's behaviour; new
shape → plan expansion). **[F]** Both apps read demands, not structures, so coexistence is invisible to
them.

**38–39.** *Current risks:* the ×12 trap; one-time fees re-charged annually; unknown frequency values
silently replicating; mid-session over-billing; proration inert. *Proposed risks:* rounding/remainder
policy must be explicit; two structure shapes during transition; operator retraining.

**40 — recommended canonical architecture:**

```
SCHOOL
  ↓
FEE HEAD REGISTRY     identity · name · category · status · chargeType(recurring|one_time|repeatable)
  ↓ feeHeadRef
FEE STRUCTURE         session · class/section · annual or per-period amount ·
                      billing frequency · INSTALLMENT PLAN (dated, explicit)
  ↓                   ── expanded by readFeeStructure() into the existing month-keyed chart ──
CANONICAL FEE SPEC    feeHeadRef · category · amount · periodKey · dueDate
  ↓
FEE DEMAND            student · period/occurrence · amount · dueDate · payment state ·
                      feeHeadRef · name snapshot · category snapshot · chargeInstance · demandId
  ↓
PAYMENT / ACCOUNTING  receipts · allocations · GL by feeHeadRef
```

---

## Sequencing — billing does **not** block Student Enlightenment

**Before Student Enlightenment (unchanged, still three):** make `feeHeadRef` durable · keep rename
gated · **M5** category stamping + rules/index.

**After:** charge type on the head (fixes one-time-re-charging) · installment plans + `readFeeStructure()`
compatibility branch · frequency validation (close the ×12 trap) · mid-session generation · proration ·
accounting mapping · retire the dead code.

**[?] Business decisions needed before the billing work — not before Phase C:** the rounding/remainder
rule for derived installments; whether `academic` should split into eligible vs non-eligible school
charges; whether one-time-ever is scoped per school or per student career.

---

Nothing implemented. No PHP, Kotlin, rules, indexes, registry records, migration, backfill, flags,
Fee Structure, Fee Demand, billing, scholarship or entitlement changes. No commit, push or deploy.
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE`.

**Sources:** [ERPNext Fee Structure](https://docs.frappe.io/education/fee-structure) ·
[ERPNext Fee Schedule](https://docs.frappe.io/education/fee-schedule) ·
[ERPNext Fee Category](https://docs.frappe.io/erpnext/v13/user/manual/en/education/fee-category) ·
[OpenEduCat](https://openeducat.org/feature-financial-management-system/) ·
[Campus24x7 fee-structure guide](https://campus24x7.in/blogs/school-fee-structure-erp-setup-guide-india) ·
[Classter billing guide](https://www.classter.com/blog/edtech/school-billing-and-tuition-management-software-a-complete-guide-for-administrators/)
