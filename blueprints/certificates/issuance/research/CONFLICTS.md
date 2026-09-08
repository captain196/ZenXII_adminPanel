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

**Unresolved and blocking:** whether CBSE's *current* bye-laws still carry the countersignature
clause at all. North reports every current edition returned 404/403; the earlier central
research found reporting that CBSE **abolished** Regional Officer countersignature. Until a
current CBSE circular is read directly, **we enforce nothing here.**

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
