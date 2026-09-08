# Conflicts between research streams

Recorded rather than resolved. Where two streams disagree, picking a winner would manufacture
certainty neither earned — and the whole point of the evidence discipline is that we would
rather say "unsettled" than assert a rule a school might act on.

---

## C-1 · Is countersignature interstate-only, or general with a carve-out?

**West & Central concluded** it is an **inbound-admission rule scoped to out-of-state
arrivals**, and therefore not a gate on issuance at all:

> MH SS Code r.22.1 · GJ reg 12(9)(ક) · Goa r.116(2) · DNHDD r.97(2) — all triggered when a
> pupil arrives from outside the state. No state in the region requires a BEO/DEO to
> countersign a TC its own school issues to its own pupil.

**North concluded the opposite about the CBSE layer**:

> Delhi r.139(2) is origin-district and state-boundary triggered, and only makes admission
> **provisional**. But **CBSE Annexure-I reads as general**, with a carve-out — not an
> exemption — for CBSE→CBSE. *"Only for interstate transfer" is unsupported by either primary
> text.*

**These are not the same claim and may both be true.** West & Central read **state codes**;
North read **Delhi's rule plus the CBSE bye-law**. A state rule scoped to interstate arrivals
and a board rule that is general would coexist — the school would face whichever is stricter.

**Consequence for us:** countersignature must be modelled as **layered (state rule + board
rule)**, not as one per-state boolean. Neither stream supports a single flag.

**~~Unresolved and blocking~~ — RESOLVED 2026-09-08 by `central-and-boards.md` §C.**

The CBSE circulars have now been read directly, off CBSE's own server. **The countersignature
requirement is SUPERSEDED.** Bye-law r.8(vii) and the Annexure-I footnote (*"the student shall
not be admitted to a school without such a counter signature"*) survive in the printed 1995
bye-laws but were abolished by a chain of five circulars, most recently
**CBSE/Coord/Countersignature/2025/ dated 31.10.2025**, signed by the Controller of
Examinations: *"all schools are once again reminded that **there is no need of countersignature
of any transfer certificate**."* The operative replacement is the SOP in **COORD/PR UNIT/2020
dated 04.02.2020**. Evidence A —
<https://www.cbse.gov.in/cbsenew/documents/Subject_Reminder_practice_countersigning_Transfer_Certificates_03112025.pdf>

> ⚠️ **The corpus is currently wrong on this.** `AUTHORITIES` in `designer.js` records
> `r.8(vii)` as **current** at Level A, `verifiedOn 2026-08-16`. It must be re-marked as
> superseded — this is exactly the error `verifiedOn` exists to catch.

**What survives, and must not be over-corrected away:**

- **The state layer is untouched.** CBSE can only abolish countersignature *by CBSE*. The state
  rules North and West & Central read (MH r.22.1, GJ reg 12(9)(ક), Goa r.116(2), DNHDD r.97(2),
  Delhi r.139(2)) are unaffected by a CBSE circular. **The layered model in this entry stands.**
- **Two residual signatures remain inside the CBSE layer**, neither of them the abolished one:
  an **optional** countersignature by the Manager/Secretary/Member of the School Managing
  Committee (2020 SOP I(d) — *"only if required"*), and a **verification endorsement** by the
  *admitting* school recording that it checked the issuer's affiliation status (2014 circular
  step 7).
- **A new sub-conflict, recorded not resolved:** the 2014 circular step 8 preserved
  countersignature for a TC arriving **from another recognised Board** (*"as per past
  practice"*), and bye-law r.7.3(c)/7.5(ii) say the same for Class X/XII admissions — but the
  2020 and 2025 circulars use unrestricted language (*"any transfer certificate"*). See
  `central-and-boards.md` §C.4 and conflict **D2** there.

**Consequence for us:** the CBSE layer now defaults to **not required**. Model countersignature
as an optional, route-dependent annotation. **Do not hard-require it in any direction, and do
not hard-block on its absence.**

---

## C-2 · "Recognition" denotes two different things

**North** found that in **Uttar Pradesh (s.2(d))** and **J&K (reg. 8)**, "recognition" means
**board-granted examination eligibility** — not the state's licence to operate a school.

Everywhere else researched so far it means the RTE s.18 licence to *function*.

**Consequence:** a single `recognitionOrder` field would silently hold two different legal
objects. The model needs to record **which kind** — operating recognition vs examination
recognition — or a UP school's exam recognition will be read as its licence to exist.

---

## C-3 · Recognition validity has no common shape

| | |
|---|---|
| Punjab | 3 years |
| Uttarakhand | 5 years |
| Haryana | 10-year review |
| Andhra Pradesh | 8 years (amended from 3) |
| Telangana (order read) | 10 years |
| **Delhi, Himachal, J&K** | **no numeric term in any rule** |

Renewal lead time also differs — Delhi **6 months**, Andhra **90 days**.

**Consequence:** validity cannot default. Where no term exists, the field must be *absent*
rather than filled with a plausible number.

---

## C-4 · Recognition lapses on EVENTS, not only on dates

**North:** Delhi r.55(1) and Haryana r.41 — a change of **premises or management voids
recognition automatically**.
**West & Central:** Maharashtra Form-2 cond. 19 and CGBSE clause 20 — two years' non-renewal
and recognition stands **automatically cancelled**.

**Consequence:** an expiry date alone cannot model eligibility. Both streams reached this
independently, from different rules, which is the strongest signal either produced.

---

## C-5 · The granting officer is not fixed

**North:** in **Himachal** (BEEO → Dy. Director → Dy. Director Higher Ed) and **J&K**
(CEO → Director → Administrative Secretary) the **granting authority changes with the class
band**.

**Consequence:** "recognition authority" is not one value per state. It is a function of state
× class range.

---

## C-6 · The validity period may live in the FORM, not the rules — a lead, not a conflict

**East & North-East** found that Assam, Meghalaya and Tripura annex a recognition certificate
whose text is word-identical across all three:

> *"provisional recognition … for Class ___ to Class ___ for a period of **three years**"*,
> *"not extendable"*, and nothing beyond Class VIII.

**Their rule bodies state no period at all.** Read the rules and you conclude "no expiry";
read the annexed form and it is three years. This traces to the MHRD model form the states
adopted.

**This may resolve C-3.** North reported that **Delhi, Himachal and J&K have no numeric term
in any rule**. If those states also adopted the model form, the term is in the *form* and the
finding is an artefact of reading only the rule body.

**Action:** before recording "no validity period" for any state, check its annexed recognition
FORM. Meghalaya is already flagged as a conflict on exactly this point.

---

## C-7 · There is a fourth identifier

**East & North-East** found a **recognition Code Number** — distinct from the UDISE code and
from the affiliation number — issued in Assam, Meghalaya, Odisha and Nagaland.

So a school may hold **four** identifiers, and the model currently has three:

| identifier | issued by | what it proves |
|---|---|---|
| Recognition **Code Number** | state, on recognition | *(newly found — not modelled)* |
| Recognition **order/proceedings number** | state | the order granting recognition |
| **UDISE+ code** | BEO after physical verification | the school exists nationally |
| **Affiliation number** | board | exam eligibility |

---

## C-8 · Countersignature: a third position, and it narrows the field

**East & North-East** found state-level countersignature in **exactly one of twelve states** —
Bihar (Education Code 1961 ss.272, 276, 280, 282) — and it is **condition-based, not
school-type-based**: same-day for middle school, three days for high, with the reason for
leaving and last-terminal marks on the reverse, plus separate rules for inter-State transfer
and for an unrecognised sending school.

Taken with West & Central (interstate-triggered in all six) and North (Delhi origin-district,
CBSE general), the pattern is now:

- **State-level countersignature is rare and condition-triggered**, not a standing requirement
- **The board layer is separate** and its current status is still unread

This strengthens C-1's conclusion — model it as **layered and conditional**, never a per-state
boolean — and it means the *state* layer is mostly absent, so the unresolved **CBSE** question
carries almost all the weight.

---

## C-9 · Autonomous councils are genuinely unresolved, and were left that way

**Assam:** the RTE Rules name "Autonomous Council" ~20 times as co-equal with the State, and a
Gauhati HC judgment shows the **Secretary, BTC actually accorded recognition**.

**Meghalaya:** the only state entirely under the Sixth Schedule — yet it **defines "local
authority" as the Joint Director, not the ADC**, while KHADC runs its own Education Department
and para 6 of the Sixth Schedule gives ADCs primary-school power.

The stream **did not encode either answer**, which is right: a school in Shillong may be
recognised by a body our model does not know exists, and guessing which would be worse than
recording that we do not know.

**Manipur** is the one state whose text expressly includes the ADC.

**Madrasa regimes split four ways:** separate boards (West Bengal, Bihar), run by the general
board (Jharkhand/JAC, Tripura/TBSE), and **abolished** (Assam, 2021).
