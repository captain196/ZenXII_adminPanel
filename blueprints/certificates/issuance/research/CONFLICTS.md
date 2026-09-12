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

---

## C-10 · Four premises I supplied were tested and four came back altered

The South stream was briefed with claims taken from this repo's compliance corpus and from my
own earlier summaries. It checked them against primary text. **Two were wrong, one was half
right, one was right for a different reason than assumed** — and each would have shipped as an
enforced rule.

| what I asserted | what the primary text shows |
|---|---|
| *Kerala 2025:KER:69076 gutted KER Ch.VI r.17(2)* | **Wrong.** The judgment never mentions r.17(2). It was a **CBSE** case where KER did not apply, argued on CBSE Bye-law Ch.3 r.8(vi). **r.17(2) stands unamended on the books.** |
| *Tamil Nadu historically required countersignature* | **Wrong.** TNER prescribes none — a grep of the full text found no TC-related countersignature at all. The real rule in that pair is **Puducherry's**. |
| *TNER rr.40–42 permit withholding a TC for unpaid fees* | **Half right, and unresolved.** **Rule 40 exists in two circulating, opposite texts.** Either way rr.41–43 allow only **one term's special fee**, never accumulated arrears. |
| *Madras HC DB ruled 22.07.2024* | **Right, wrong date.** It is **W.A. 3075/2021, pronounced 19.07.2024**, and it does **not cite TNER rr.40–42 by number at all**. |

I had repeated the first of these in a published artifact. It has been corrected there.

**The methodological finding matters as much as the corrections.** The stream reports that its
own first pass got the TNER question wrong *in the opposite direction* — read one source,
declared the premise withdrawn, and only a second independent retrieval found that both texts
exist. Its conclusion is the right rule for this whole programme:

> **One source is not verification.**

---

## C-11 · The no-dues position is more precise than "the courts gutted it"

**Four judgments at Level A**, four independent rationales — Tamil Nadu (DB, W.A. 3075/2021,
19.07.2024), Kerala (2025:KER:69076), Karnataka (2025:KHC:5986), Telangana (W.P. 34185/2023).
**Withholding is prohibited; recovery of dues is preserved.**

But **no enabling rule was repealed.** Kerala's KER VI-17(2) — *"No transfer certificate shall
be issued to a pupil from whom there are any dues"* — stands unamended, and Tamil Nadu's
ordered amendments appear unmade.

**Consequence for the product:** we must not implement a no-dues gate, *and* we must expect a
school to cite a rule that genuinely still exists in its own state's code. The honest framing
is not "the rule was struck down" but **"the rule stands and is unenforceable"** — which is a
harder thing to explain, and the true one.

---

## C-12 · Countersignature resolves — and is being replaced by printed identifiers

With four streams in, the picture is coherent and C-1 can be narrowed:

| | |
|---|---|
| **Intra-state TC** | **no countersignature** anywhere researched — Karnataka, Tamil Nadu, Kerala (Form 5 has no countersign block), and all six west/central states |
| **Inbound out-of-state TC** | **often yes** — Kerala Ch.VI r.10 (Inspecting Officer, within 2 months), Puducherry 1996 r.62 proviso, MH r.22.1, GJ reg 12(9), Goa r.116(2), DNHDD r.97(2), Bihar s.272 |
| **Board layer** | **still unread** — CBSE Annexure-I appears general; current edition 404/403 in two streams |

**And the mechanism is being replaced.** Karnataka imposed BEO countersignature on 25.05.2016
and **withdrew it a week later, on 01.06.2016**, substituting a **mandatory DISE code on every
TC, verifiable online**. Tamil Nadu never required countersignature and instead prints the
recognition number and DGE school number. MP r.19 proviso and CG clause 16(4) independently
mandate printing the recognition number.

**Four states, four sources, one direction: printed verifiable identifiers are replacing a
human countersignature.** That is a strong argument for putting the recognition number and
UDISE code on the document — which is what a QR verification endpoint would carry — rather
than building a countersignature workflow.

---

## C-13 · Validity cannot be one field

Eight southern jurisdictions produced **six models**:

- **Karnataka** — 5 years → 10 on first renewal → then **permanent** (26.08.2024)
- **Andhra Pradesh** — 3 → **8 years** (G.O.Ms.No.38, 22.04.2023). *A further 8→10 claim is
  **unsupported — do not model it***
- **Kerala** — permanent never expires; temporary is 1 year at a time, max 3 without Director approval
- **Tamil Nadu** — **tied to another document**: the structural stability certificate or
  building licence, *whichever expires first*. Pre-2023 permanent recognitions stay permanent
- **Puducherry** — 3 years + 3, but apply **by 30 November of the preceding year**
- **Telangana** — 10 years in the order read; the rule fixing it NOT FOUND

**Two consequences.** Tamil Nadu alone defeats a `validUntil` date field — validity is a
*reference to another instrument*. And renewal triggers come in two incompatible shapes: an
**offset** (90 days, 3 months) versus a **fixed calendar date** (Puducherry). An offset-only
reminder engine never fires for Puducherry.

---

## C-14 · Per-section recognition does NOT generalise — a correction to my own reading

I read a real Telangana order granting recognition for *"classes VIII (E.M) with one section"*
and generalised **per-section** recognition from it.

The South stream checked: **per-section is confirmed in only two of eight** — Telangana (in the
order) and Kerala (divisions sanctioned separately by the AEO/DEO). **Karnataka, Tamil Nadu,
Puducherry and Andhra Pradesh are searched negatives.**

**Per class-range generalises — four streams now agree. Per-section does not.** Modelling
sections as a recognition dimension everywhere would impose a constraint most states do not
have.

---

## C-15 · Countersignature RESOLVES — it is the RECEIVING board's rule, and it is not dead

This supersedes C-1, C-8 and C-12. Six streams have now read the state codes, the CBSE
circulars and the state-board regulations, and the shape is finally clear.

**It was never one rule.** It is a rule the **receiving** institution applies to an **incoming**
certificate, and it therefore lives in whichever board or state the child is *arriving* at.

| layer | position |
|---|---|
| **CBSE, as receiver** | **Abolished.** Five circulars, 26.11.2014 → 31.10.2025. *"There is no need of countersignature of any transfer certificate."* |
| **HPBOSE, as receiver** | **Alive and fail-closed.** Examination Reg. **3.5.7**, amended 18 Jan 2012: a cross-board TC must be countersigned by an officer not below DEO/DIS, and the scholar *"shall not be admitted… without such countersignature"*. Carve-out only for HPBOSE→HPBOSE. |
| **BSEH (Haryana)** | No countersignature at issuance, but a DEO-countersigned SLC is demanded when one is later used as **evidence** (record correction, Open-School age proof). A warning, not a gate. |
| **MPBSE, CGBSE** | Confirmed absence. |
| **state codes** | Triggered by **inbound out-of-state** arrival — Kerala Ch.VI r.10, Puducherry r.62 proviso, MH r.22.1, GJ reg 12(9), Goa r.116(2), DNHDD r.97(2), Bihar s.272. |

**So every earlier framing was partly wrong, including mine.** It is not "abolished" (HPBOSE
still enforces it), not "general" (CBSE does not), not "interstate-only" (HPBOSE's trigger is
cross-*board*, not cross-*state*), and not a per-state boolean.

**What the product must do:** never gate ISSUANCE on countersignature — no researched authority
requires the issuing school to obtain one. Model it as a property of the **destination**, shown
as guidance when known. A Himachal school admitting a CBSE child needs it; a CBSE school
admitting anyone does not.

---

## C-16 · No state board prescribes a TC format — CBSE's Annexure-I stands alone

Nine north/central boards checked: **none publishes a TC proforma.** JKBOSE reg. 19(x) names
*"the form prescribed"* and does not publish it; HPBOSE prescribes a **Scholar's Register**
(Annexure-I) rather than a TC.

Taken with the twelve east/north-eastern states (no prescribed format) and four of six
west/central states (none), **CBSE's Annexure-I is the only enforceable field schedule the
research has found anywhere in India.**

That is a strong finding for the engine: outside CBSE, the field set is ours to choose, and
our contracts for bonafide/character/study — which carry `requiredKeys: []` — are right to.

---

## C-17 · Two more boards mandate printed identifiers

Adding to Karnataka's DISE code, Tamil Nadu's recognition + DGE numbers, and MP r.19 / CG 16(4):

- **CGBSE cl. 16(4)** [A] — the TC must state that the school is CGBSE-recognised **and print
  its मान्यता कोड (recognition code)**.
- **HPBOSE affiliation condition (f)** [A] — the affiliation number on **all official
  stationery**.

**Six jurisdictions now, one direction.** Printed, verifiable identifiers are the mechanism
replacing human countersignature — which is the argument for the QR/verification endpoint the
blueprint deferred.

---

## C-18 · Affiliation is downstream of recognition — now stated by a board itself

**BSEH reg. 5(a)** is the clearest evidence in the corpus that a board will not affiliate a
school the state has not already recognised. Until now this was inferred from the sequence
(recognition → UDISE → affiliation); a board's own regulation now says it.

Also from this stream, both new:

- **BSEH reg. 21** — a bogus SLC escalates ₹1 lakh → ₹3 lakh → **loss of affiliation**. The
  first found penalty attached to issuing a false certificate.
- **HPBOSE** — every withdrawal must be **reported to the Board within 15 days**. A
  post-issuance duty, like CBSE's website upload.
- **HPBOSE 3.5.6** — the **only duplicate-TC marking rule found in any state board**. CBSE
  r.8(vi) had been the sole source.

---

## C-9 · RESOLVED — autonomous councils have no certificate role

The first wave found this genuinely unresolved and **correctly declined to encode either
answer**. It is now settled, in the negative, at Evidence A.

**MBOSE Act s.12, proviso** — read directly — states the Board's power

> *"shall not extend to the Primary Schools established, constructed or managed by the District
> Councils"*

reproducing the **Sixth Schedule para 6** formula verbatim. So the two domains are **mutually
exclusive by statute**: ADCs run *primary* schools; boards govern *secondary* examinations.

Corroborating, independently:

- **KHADC's full legislation index** — 71+ instruments, 1951–2024 — contains **zero** education
  entries.
- **ASSEB's own forms** treat an autonomous council as an **address field**, nothing more.

**No ADC certificate role exists anywhere in the region.** The caution was right and the answer
is now known — which is the ideal outcome of recording a gap instead of filling it.

**Still unresearched:** Assam's BTC, KAAC and Dima Hasao councils specifically. Note the first
wave found a Gauhati HC judgment showing the **Secretary, BTC actually according recognition** —
so the negative above covers *certificates*, and the *recognition* question for Assam's councils
remains open.

---

## C-15a · Countersignature — confirmed again, from a second region

Two more boards, both Evidence A, both pointing exactly where HPBOSE did:

- **JAC (Jharkhand) Reg. 5(iv)(a)** — the TC is signed by the head of institution and
  **countersigned by the DEO for out-of-State candidates**.
- **NBSE (Nagaland), Management of Examinations 2023, Ch. 8** — the TC must be **countersigned
  by the Inspector of Schools / DEO of the State last studied**, inter-State only.

**Four boards now agree on the shape:** it is a **receiving-side, inter-State-triggered** check,
and it belongs on the **admission** flow, never on the issuer's document.

### A trap worth recording

**ASSEB's Inspector-of-Schools countersignature sits on the change-of-institution APPLICATION,
not on the TC.** Read quickly, that looks like a TC countersignature requirement and would have
produced a wrong rule. The stream caught it; anyone re-reading these sources should expect the
same shape elsewhere.

---

## C-16a · No board prescribes a TC format — now fifteen more searched

**Fifteen east/north-eastern boards checked; zero prescribe a format.** With nine north/central
(zero) and the west/central and southern findings, the negative now holds at **both the state
layer and the board layer**, across every region.

**CBSE's Annexure-I remains the only prescribed Transfer Certificate format found in India.**

Four boards touch the TC narrowly, never as a layout: JAC (signature and countersignature,
plus a duplicate on **affidavit + FIR copy**, Reg. 8(iv)), NBSE (countersignature), TBSE (the TC
must state the class the pupil may enter), and ASSEB/MBSE/COHSEM, for which the TC is a document
the board **consumes** rather than designs.

---

## C-2a · "Recognised" means the board's own grant — a third instance

**MBOSE Act s.2(j)** defines *"recognised"* as **recognised by the Board**.

That is now the third jurisdiction where the word denotes board-granted standing rather than the
state's RTE s.18 licence to operate — after **UP (s.2(d))** and **J&K (reg. 8)**. C-2 holds and
strengthens: a single `recognitionOrder` field would silently carry two different legal objects,
and the model must record **which kind**.
