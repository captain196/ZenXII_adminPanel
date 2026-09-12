# Conflict register

**46 entries, IDs unique.** They were not: successive research streams each appended without
checking the highest number in use, so `C-9`, `C-19`, `C-20` and `C-16b` were each issued twice.
The second of each is now `C-9a`, `C-19a`, `C-20a`, `C-16c`. **The `C-16b` collision was the
damaging one — the two entries sharing that ID contradicted each other**, so a citation of "C-16b"
resolved to either a claim or its own refutation depending on which one you scrolled to.

This is a register, not a resolution. **Where two streams disagree, both readings stay recorded
with their evidence** — the convenient one was never allowed to quietly win.

## Index

| id | subject | status |
|---|---|---|
| C-1 | Countersignature: interstate-only or general? | superseded by **C-15** |
| C-2 | "Recognition" denotes two different things | established |
| C-2a | "Recognised" = the board's own grant (3rd instance) | confirms C-2 |
| C-3 | Recognition validity has no common shape | established |
| C-3a | **Correction** — "Punjab: 3 years" was wrong | corrects C-3 |
| C-4 | Recognition lapses on EVENTS, not only dates | established |
| C-5 | The granting officer is not fixed | established |
| C-6 | Validity may live in the FORM, not the rules | lead, confirmed by C-3a |
| C-7 | There is a fourth identifier | established |
| C-8 | Countersignature: a third position | superseded by **C-15** |
| C-9 | Autonomous councils unresolved | **resolved by C-9a** |
| C-9a | Autonomous councils have no certificate role | **RESOLVED** |
| C-10 | Four premises I supplied came back altered | **correction to me** |
| C-11 | The no-dues position is more precise than "courts gutted it" | established |
| C-12 | Countersignature is being replaced by printed identifiers | established |
| C-13 | Validity cannot be one field | **design-binding** |
| C-14 | Per-section recognition does NOT generalise | **correction to me** |
| C-15 | Countersignature is the RECEIVING board's rule, and not dead | **RESOLVES C-1/C-8** |
| C-15a | Confirmed again from a second region | confirms C-15 |
| C-15b | A countersignature that runs the other way | refines C-15 |
| C-16 | No state board prescribes a TC format | **WRONG — see C-16c** |
| C-16a | Negative extended to fifteen more boards | superseded |
| C-16b | Negative "regional-complete" | **WRONG — see C-16c** |
| C-16c | **Prescribed formats DO exist — mostly STATE-made** | **SUPERSEDES C-16/16a/16b** |
| C-17 | Two more boards mandate printed identifiers | **overstated — see C-19** |
| C-18 | Affiliation is downstream of recognition | established |
| C-19 | The Tamil Nadu printed-identifier rule is NOT established | **corrects C-17** |
| C-19a | Sikkim — two of four claims overturned | **correction** |
| C-20 | Printed-identifier mandates are STATE rules, not board rules | **layer correction** |
| C-20a | Uttar Pradesh — officer CLOSED, validity a NEW conflict | **validity OPEN** |
| C-21 | TNER Rule 40 — resolved, premise was backwards | **RESOLVED** |
| C-22 | Maharashtra GR number not obtained | superseded by **C-23** |
| C-23 | The Maharashtra GR is CAPTCHA-gated | **HUMAN-ONLY** |
| C-24 | KVS/NVS — my question rested on a false premise | **UNSUPPORTED, not disproved** |
| C-25 | Sainik/RMS/AWES/CTSA not researched | **outstanding** |
| C-26 | **Identifier tally recounted — the trend is not universal** | **corrects C-12/C-17/C-24** |
| C-27 | **Countersignature trigger is not always a State line** | **schema-binding · refines C-8/12/15** |
| C-28 | "No prescribed format" conflates three situations | **3rd correction to C-16** |
| C-29 | Two mechanisms the taxonomy has no slot for | **new** |
| C-30 | Identifier pattern: +RBSE, +WB, −Odisha | updates C-26 |
| C-31 | **The RBSE "Helvetica cipher" was my misdiagnosis** | **correction to me** |
| C-32 | **No "legacy Devanagari font" file exists — both are Canon scans** | **closes C-31's action** |
| C-33 | **Per-section recognition established in the north at [A]** | **extends C-14 · schema-binding** |
| C-34 | **The legacy-font class is real — it is Kruti Dev, and searchable** | **refines C-32 · method** |
| C-35 | UPMSP — the negative narrows and still holds | narrowed NOT FOUND |
| C-36 | **Filenames are not evidence — two near-miss fabrications** | **method · binding** |

### What is actually still open

- **C-20a** — UP recognition validity: two primary instruments disagree on the provisional term.
- **C-23** — the Maharashtra GR, behind a CAPTCHA. **A person must run this search.**
- **C-24** — the KVS Asstt. Commissioner countersignature is *unsupported*, which is not the same
  as disproved; the Education Code is a ~1980s edition and no post-2014 circular was found either way.
- **C-25** — four central school systems, under research.

### The corrections worth carrying into implementation

**Five entries correct something I asserted** (C-10, C-14, C-16c, C-19, C-19a, C-3a). The pattern in
all of them is the same: I generalised from one instance, or stated a negative from a search that
had not actually covered the ground. **C-16c is the sharpest** — I claimed CBSE's Annexure-I was the
only prescribed TC format in India while a stream I had already committed recorded Gujarat's
**નમૂનો-૯**. Any code that assumes one national format is wrong.

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

## C-9a · RESOLVED — autonomous councils have no certificate role

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

---

## C-19 · ⚠️ The Tamil Nadu printed-identifier rule is NOT established — C-17 overstates it

*Added 2026-09-12 by `boards-west-south.md` §6.8.*

**C-17 lists "Tamil Nadu's recognition + DGE numbers" alongside CGBSE cl.16(4) and the HPBOSE
affiliation condition, as though all three were equally evidenced. They are not.**

The TN claim was cited in `south-india.md` §3.3a/§3.4 to
<https://indiankanoon.org/doc/24617366/>. **That page was downloaded and searched this session and
it does not contain the sentence** — `grep -i "school number"` returns **zero hits**. Indian
Kanoon carries an **older recension** of TNER r.34: "recognised *secondary* school", Appendix 5
alone, a rule-92 case-sheet, and the **DEO** — with no school number, no recognition wording, no
"Office Copy" rule and no seal specification.

The claim's actual support is **one** document: a consolidated *Tamil Nadu Educational Manual*
(`educationalrules.PDF`, author "Thamizhagam", created **2003-05-05**) hosted on a **private
service-provider site**. Corroboration was attempted exhaustively and **failed** — Indian Kanoon
phrase searches return nothing (a control query confirms the search works), the Matriculation Code
contains no such identifier, eleven search engines were CAPTCHA-walled, Wayback CDX on three TN
government domains returned nothing, and the one apparent second copy proved **byte-identical**
(same MD5).

**Worse, the single source contradicts itself.** Its **Appendix-5-A** note requires the words
*"Recognized by the Department of Education Chennai"* **with Recognition Number**; its
**Appendix-5** note requires the words **without** the Recognition Number, and narrows the
invalidity sanction to private **Higher Secondary** schools only.

> **Action:** re-mark the TN entry in C-17 as **DISPUTED, level C**. Ship the field
> **configurable and off by default** — never as an assertion that the law requires it.

**A likely origin for the error, which makes this worth remembering:** **Andhra Pradesh really does
require it** (C-20). A rule imported from AP into a TN template would look entirely plausible and
be wrong.

---

## C-20 · Printed-identifier mandates are STATE rules, not board rules — a layer correction

*Added 2026-09-12 by `boards-west-south.md` §4.2, §7.0.*

Two mandates in the corpus were being carried as board-layer facts. **Both are state or
departmental rules, and the board imposed neither.**

| Mandate | Was attributed to | Actually |
|---|---|---|
| **DISE code on every Karnataka TC** | KSEAB | **Commissioner for Public Instruction**, circular 01.06.2016 — School Education Department. KSEAB's by-laws page publishes **zero documents**, and ~160 SSLC circulars over four years contain nothing on TCs |
| **Recognition number on an AP TC** | *recorded as NOT FOUND* | **AP Private Managements Rules 1993, r.10(8)** — a **State** rule under the AP Education Act 1982 |

**The AP rule, now read first-hand [A]** · <https://indiankanoon.org/doc/90101170/> ·
G.O.Ms.No.1, Education (P.S.2), 1-1-1994, AP Gazette Extraordinary 3-1-1994:

> *"that the **Name Board of the school, the Transfer Certificate issued by the School**, the
> applications prescribed for admission of students and the advertisements calling for the
> applications **shall invariably contain the recognition number given.**"*
> *(r.10(8), as substituted by G.O.Ms.No.74, Education (SE[PS-1]), 11-9-2006)*

**Three consequences.**

1. **It is a CONDITION OF RECOGNITION** (r.10 chapeau; r.11 makes breach a withdrawal ground).
   Omitting it is recognition-threatening, not cosmetic.
2. **It covers four surfaces**, not one — name board, TC, admission form, advertisements.
3. **It is scoped to PRIVATE-management schools** and **its Telangana application is unverified**
   (24 Telangana-classified documents cite the 1993 Rules — level C — but no adaptation
   notification was found).

⚠️ **Also a citation correction:** the operative instrument is the **1993** Rules. They expressly
**supersede** the 1988 Rules (G.O.Ms.No.524, 20-12-1988) that earlier briefs named.

**The general lesson, which C-12 half-anticipated:** the printed-identifier trend is real, but it
is being driven by **state education departments**, not by examination boards. A data model that
hangs "required printed identifiers" off the board record will attach them to the wrong parent.

---

## C-16b · No board prescribes a TC format — eleven more, and the negative is now regional-complete

*Added 2026-09-12 by `boards-west-south.md`.*

**Eleven more board entities checked. Ten prescribe no TC format — and ONE does.**

> ### ⚠️ GUJARAT IS A REAL EXCEPTION, AND IT BREAKS THE CLEAN NEGATIVE
>
> **GSHSEB prescribes the leaving-certificate form in its OWN regulations.** *Gujarat Secondary &
> Higher Secondary Education Regulations 1974*, **reg 12(14)** and form **નમૂનો-૯** — made by the
> Board under the Gujarat Secondary and Higher Secondary Education Act 1972. **[A]** Thirteen
> mandated fields, three signatories (Clerk + Class Teacher + Principal), the school seal, and
> **reg 12(14)(5)'s validity rule**: in નમૂના-૯, hand-written in ink, signed by the head in his own
> ink, sealed — *"તો જ તે કાયદેસર ગણાશે"*, **"only then shall it be legally valid."**
>
> **So CBSE's Annexure-I is no longer the only prescribed TC format found in India.** C-16 and
> C-16a must be read with this carve-out. *(The earlier streams recorded Gujarat's form correctly as
> a state-layer finding in `west-central-india.md` §2.6; what was missed is that the instrument
> prescribing it **is the board's own regulation**, which makes it a board-layer finding too.)*

The other ten: MSBSHSE, GBSHSE, KSEAB, Kerala Pareeksha Bhavan, Kerala DHSE, TN DGE, BSEAP, BIEAP,
BSE Telangana, TSBIE.

Several of these are **evidenced** negatives rather than unsearched ones, which is what makes the
entry worth recording:

- **MSBSHSE** — a full-text grep of the 1977 Regulations returns *"transfer certificate"* **exactly
  once**, and only for inbound out-of-state pupils. The Board instead defers to the Education
  Department's **Secondary School Code** (reg 67(xvii)).
- **BSE Telangana** — both the RTI service catalogue **and the complete 12-item G.O. list** were
  enumerated; neither contains a TC rule.
- **BSEAP** — the RTI s.4(1)(b) service catalogue was enumerated; TC is absent. The Board's own list
  of governing instruments contains **no "A.P. Board of Secondary Education Rules"** — an instrument
  earlier briefs assumed exists.
- **KSEAB** — by-laws and amendments pages render **zero content items**, proved against a populated
  control page.
- **Kerala DHSE** — **3,000 archived URLs** of the now-dead `dhsekerala.gov.in` enumerated; no TC
  format, no TC rule, no migration page.

**CBSE's Annexure-I no longer stands alone — GSHSEB's નમૂનો-૯ joins it.** Those are the only two
prescribed Transfer/Leaving Certificate formats the research has found anywhere in India.

**Where a format exists elsewhere in the south and west, the STATE wrote it** — Maharashtra SS Code
Appendix Four, Gujarat નમૂનો-૯, Kerala KER Form 5, Tamil Nadu TNER Appendix-5/5-A and the
Matriculation Code Annexure V. **The corrected model is: USUALLY the STATE prescribes the TC and the
BOARD supplies an identifier that may go on it and owns the migration/eligibility instruments —
EXCEPT in Gujarat, where the board's own regulation is the prescribing instrument.** A per-board
`prescribesFormat` flag is therefore required; a regional default would be wrong for Gujarat.

---

## C-16c · CORRECTION TO MY OWN C-16 — prescribed formats DO exist; they are mostly STATE-made

I wrote, twice, that *"CBSE's Annexure-I remains the only prescribed Transfer Certificate
format found in India."* **That is wrong, and it contradicted a stream I had already
committed.**

The west/central stream had told me in its very first report that **Gujarat prescribes a form
with 13 fields, three signatories, ink and seal.** I recorded that, then generalised a
board-layer negative into an all-India one and lost it.

**What is actually true**, and the distinction matters:

| prescribed format | made by | instrument |
|---|---|---|
| **CBSE Annexure-I** | a **board** | Examination Bye-Laws |
| **Gujarat નમૂનો-૯** | a **board** — the only state board that does | GSEB Regulations 1974, reg 12(14): 13 fields, 3 signatories (Clerk + Class Teacher + Principal), seal |
| **Maharashtra Appendix Four** | the **state** | SS Code r.32.1 — an LC is invalid in any other form |
| **Kerala Form 5** | the **state** | made under the Kerala Education Act 1958 — *not* by Pareeksha Bhavan |
| **Tamil Nadu Appendix-5 / 5-A** | the **state** | TNER — *not* by the DGE, whose published functions cover exam conduct only |

So the correct statement is: **almost no examination board prescribes a TC format — Gujarat is
the single exception — and the prescribed formats that do exist are overwhelmingly made by
STATE governments under their education Acts.**

That is a different design conclusion from the one I drew. It is not "only CBSE matters"; it is
**"look to the state, not the board"** — which is the same lesson the recognition research
produced, arriving from the other direction.

---

## C-15b · A countersignature that runs the other way

**TSBIE (Telangana Intermediate)** publishes a service — id 15 — *"Counter signature on Transfer
Certificate"*, and it is for **OUTBOUND** inter-state moves: the board countersigns a TC a
Telangana school has issued, for a pupil leaving the state.

Every other instance found is **receiving-side**. This one is **issuing-side**, performed by the
board on request.

It does not overturn C-15 — nothing here requires the issuing school to obtain a
countersignature before handing over the certificate — but it shows the space has a fourth
shape, and a model that assumes countersignature is always a destination concern would have no
place to put it.

**Caveat carried from the stream:** BIEAP and TSBIE negatives rest on **weak evidence** — their
JS menus could not be enumerated. Not to be asserted as settled.

---

## C-19a · Sikkim — two of four claims OVERTURNED

The first wave reported Sikkim as having no RTE Rules, no Education Act, no recognition officer
and **no state board**. Two do not survive:

- **"No state board" is WRONG.** The **Sikkim Board of School Education Act, 1978** (Act 19 of
  1978) establishes one, and it is **absent from the State's own repealed-Acts register**, so it
  stands. Its recognition is **examination** recognition — a **fourth** instance of C-2, after
  UP, J&K and MBOSE.
- **"No Education Act" is EXPLAINED, not confirmed.** The Sikkim Education Act **2002** existed
  and has been **repealed** per the official state list.

**Which sharpens the open question rather than closing it: what supplies operating recognition
in Sikkim at all?** An Act repealed and a board that only recognises for examinations leaves a
gap nobody has identified.

---

## C-3a · CORRECTION — "Punjab: 3 years" was wrong, and the right answer confirms C-6

C-3 recorded Punjab's recognition validity as **3 years**. The gap-closure stream recovered the
**PSEB Affiliation Regulations 1988 (amended to 2013)** from Wayback captures of PSEB-published
PDFs and found:

- **PSEB affiliation has NO validity term.** Reg. 11 makes it **continuing**, on an annual
  ₹5,000 continuation fee (or ₹45,000 for twelve years), **suspended on non-payment**.
- The only "three years" in the Affiliation Regulations is **Reg. 22's debarment ceiling** — a
  penalty period, not a validity term.
- The real three-year term is in the **Punjab RTE Rules 2011, Form-II** — provisional, *"not
  extendable"*, granted by the **District Education Officer** within 15 days.

**This is C-6 again, and it is now confirmed rather than suspected:** the validity period lives
in the annexed **FORM**, not in the rule body — exactly as Assam, Meghalaya and Tripura showed,
and exactly as the MHRD model form predicted.

**And a product finding:** PSEB **gates the TC on a Board migration certificate** — which sits
alongside the portal-transfer regime found independently in the browser.

---

## C-20a · Uttar Pradesh — officer CLOSED, validity a NEW conflict

**Officer, closed at Evidence A on two independent instruments** — G.O. 575/68-3-2018-2041/2023
(26.09.2023, s.13 UP Basic Education Act 1972) and UP RTE Rules 2011 r.11 + r.2(1)(j):

> The BSA assumption is **right for basic/elementary and wrong for secondary** — *"Zila Shiksha
> Adhikari"* resolves by class band. The **BSA issues**, but a **Recognition Committee decides**,
> chaired by the BSA for primary and by the **Divisional Assistant Director of Education (Basic)**
> for upper primary.

**Validity — a new conflict, both limbs Evidence A.** The 2023 G.O. says provisional **1 year →
permanent**; the RTE Rules 2011 **Form-II** says provisional **three years, "not extendable"**,
nothing beyond Class VIII. Different Acts, different purposes, **neither repeals the other**.

**Do not default this field. Surface both.**

---

## C-21 · TNER Rule 40 — RESOLVED, and the premise was backwards

C-10 recorded that rule 40 exists in two opposite circulating texts. The gap-closure stream
settled it:

> **Rule 40 FORBIDS refusing a TC over arrears.**

"Text one" — the version permitting withholding — **was not found as rule 40 anywhere**, and
appears to be a **conflation** of r.34 / Appendix 5 item 8 with the rr.41–43 provisos. The
numbering was verified three ways, specifically excluding the r.39-is-"Deleted" off-by-one.

**Caveat, stated by the stream and kept here:** a single host (Indian Kanoon); **no gazette copy
of TNER exists online**, so this is **not Evidence A**.

**And a larger point:** for private schools TNER is likely **superseded** by the **TN Private
Schools (Regulation) Rules 2023, r.18**, which contains **no withholding power at all**. The
2024 Division Bench's directed amendment appears never to have been made.

---

## C-22 · Still open, and deliberately not guessed

- **Maharashtra GR on admission without a TC.** `gr.maharashtra.gov.in` is reachable, but its
  search is an ASP.NET WebForms POST requiring a scraped `__VIEWSTATE`, and no search engine was
  usable to find the GR code. **Number and date not obtained — and deliberately not guessed.**
- **Arunachal's Education Rules 2010** — proven to exist via their 2015 amendment
  (No. SED-143/2015, 30.04.2015); they hold both the TC regime and probably the validity term.
  Also open: whether a state board now exists (the Act says **CBSE applies until one is
  constituted**), and the RTE Rules, found nowhere.
- **Goa RTE Rules 2012** — could not be retrieved from any host, so the 1986/2012 conflict was
  never compared directly. The 1986 Rules do carry an **express power under r.36 for the Director
  to authorise a subordinate**, which plausibly explains the Deputy Director. **Record both.**

---

## C-23 · The Maharashtra GR is CAPTCHA-gated — a human-only gap, not an unreachable one

The gap-closure stream recorded this as *"reachable but its GR search is an ASP.NET WebForms POST
requiring a scraped `__VIEWSTATE`"*. That was half the picture. Scraped directly on 2026-09-12:

`gr.maharashtra.gov.in/1145/Government-Resolutions` **is** ASP.NET WebForms — `__VIEWSTATE`,
`__EVENTVALIDATION` and `__VIEWSTATEGENERATOR` are all present and scrapeable, and the search form
has exactly the fields needed: `txtKeywords`, `txtTitle`, `txtGRNo`, `ddlDepartmentType`,
`txtFromDate`/`txtToDate`.

**But it also has `txtimgcode`, marked required, backed by**

```html
<img id="SitePH_ImgCaptcha" src="../Site/Information/captcha.aspx">
```

labelled *"Captcha (पडताळणी संकेतांक कोड) *"*.

**So the obstacle is not a technical one we should engineer around — it is a bot check, and
completing it is not something I will do.** The `__VIEWSTATE` is scrapeable and irrelevant: the
CAPTCHA gates the query regardless.

**Status: HUMAN-ONLY.** Someone with a browser can run this search in under a minute —
department *School Education*, keyword *शाळा सोडल्याचा दाखला* or *प्रवेश* — and the GR number and
date would close the gap. It cannot be closed by automation, and it should stop being retried.

This matters beyond one GR: **any state portal behind a CAPTCHA is permanently out of reach for
this research method**, and those gaps should be routed to a person rather than re-queued.

---

## C-24 · KVS and NVS — my question rested on a false premise

I briefed the research to ask whether *"the KVS Asstt. Commissioner / NVS Deputy Director
countersignature survived CBSE's abolition."* The premise was wrong, and correcting it answers it.

**Those officers appear in CBSE's Annexure-I footnote as people whose signature CBSE would
ACCEPT on an inbound TC — never as officers KVS or NVS required a signature BY.** Once separated,
there are three countersignatures running in different directions with different fates:

| countersignature | whose rule | direction | status |
|---|---|---|---|
| KVS Asstt. Commissioner / NVS Deputy Director on a CBSE→CBSE TC | **CBSE** | inbound | **abolished** |
| **Chairman, VMC** on a **KV-issued** TC | **KVS** (Education Code, Appendix XXXIII) | **outbound** | **still on the books — but only when the TC is signed by an Officiating/Incharge Principal**, not a regular one |
| countersignature on a TC presented to a **JNV** | **NVS** (PAP 2026-27 §34) | inbound | **waived** — *"No need to countersign the same if the school is registered in UDISE portal."* |

**CBSE's abolition has no bearing on the Chairman-VMC rule.** They are different requirements, on
different documents, borne by different officers, running in opposite directions.

**The NVS waiver is the finding with the widest reach.** NVS drops countersignature *specifically
because the school is on UDISE* — a national identifier standing in for a human check. That is the
same substitution Karnataka made with the DISE code and CBSE made with website upload, but stated
in one clause. **Five independent jurisdictions have now replaced countersignature with a
verifiable identifier.** It is the strongest pattern the entire programme has produced.

### How the negative was established, and its honest limit

All **285 pages** of the KVS Education Code were extracted and grepped: *"countersign"* occurs on
four pages. Page 246 is Appendix XXXIII — the TC form, countersigned by **Chairman, VMC**. Pages
257/258/262 are the **National Award for Teachers** proformas, where the Asstt. Commissioner
countersigns a **teacher's award nomination**, not a student's certificate.

**Residual, stated rather than smoothed over:** the Education Code is a ~1980s second edition, and
no post-2014 KVS circular on TC countersignature was found in either direction. So
*"Asstt. Commissioner countersignature required"* is **UNSUPPORTED, not DISPROVED.**

**A second, independent reason CBSE's footnote is dead letter for NVS:** across PAP 2026-27,
*"Deputy Commissioner"* appears 21 times and *"Deputy Director"* once — in legacy copy inside a
death-incident SOP. **NVS Regional Offices are headed by a Deputy Commissioner.** CBSE's footnote
names a designation **NVS no longer uses.**

---

## C-25 · Sainik Schools, RMS, AWES and CTSA — not researched

The central-completion file ends at §7 (NVS). Its own summary table points Sainik/RMS/AWES and
CTSA to sections that **were never written** — the agent stalled at the watchdog during write-up.

**These are outstanding, not negative.** Nothing about them has been established either way.

---

## C-26 · The identifier tally, consolidated — and the trend is NOT universal

*Added 2026-09-12. Audits three counts that had drifted apart across C-12, C-17 and C-24.*

Three entries each asserted a count of jurisdictions replacing human countersignature with a
printed, verifiable identifier — **"six jurisdictions now, one direction"** (C-17), the mechanism
"being replaced" (C-12), and **"five independent jurisdictions"** (C-24, mine). Those counts were
written by different streams at different times and **none of them is still right.**

### The [A]-evidenced set, recounted

| jurisdiction | mandate | instrument |
|---|---|---|
| **Karnataka** | DISE code on every TC | Commissioner for Public Instruction circular 01.06.2016 — **state, not KSEAB** (C-20) |
| **Madhya Pradesh** | r.19 | state rules |
| **Chhattisgarh** | recognition statement **+ मान्यता कोड** on the TC | CGBSE cl.16(4) |
| **Himachal Pradesh** | affiliation number on **all official stationery** | HPBOSE affiliation condition (f) |
| **Andhra Pradesh** | recognition number on the TC | AP Private Managements Rules (C-20 upgraded this from NOT FOUND) |

**Five, not six. Tamil Nadu is out** — C-19 established that the cited Indian Kanoon page does not
contain the sentence attributed to it (`grep -i "school number"` → zero hits).

Separately, and a different mechanism: **NVS PAP 2026-27 §34(i)** waives countersignature outright
*because* the school is on UDISE — *"No need to countersign the same if the school is registered in
UDISE portal"* (Evidence A, quoted verbatim in `boards-central-completion.md` §7.4).

### The finding that matters more than the count

**Himachal Pradesh appears in the identifier table AND requires blocking countersignature.**

HPBOSE Examination Regulation **3.5.7**, amended as recently as **18 January 2012**, makes
countersignature **mandatory and blocking**, by an officer **not below DEO/DIS**, with a carve-out
only for HPBOSE→HPBOSE transfers. It also requires every withdrawal to be reported to the Board
**within 15 days**, and countersigned copies filed with exam admission forms.

**So a printed identifier is not, in general, a replacement for countersignature — in Himachal the
two coexist, and the countersignature is the stricter of the pair.** C-12's framing ("the mechanism
is being replaced") and C-17's ("six jurisdictions, one direction") both read the trend as
universal. It is not. It is a real trend with at least one jurisdiction moving the other way, and
**the one moving the other way strengthened its rule more recently than most of the others adopted
theirs.**

### Binding consequence for implementation

`boards-north-central.md` §1 already states it, and it is the correct rule:

> **A single global `countersignatureRequired = false` is a wrong rule in Himachal.**

Countersignature is **per-jurisdiction and directional** (C-15: it is the *receiving* authority's
rule). It cannot be a product-wide boolean, a per-board boolean, or a national default. Any schema
that models it as one field will be wrong in Himachal on day one — and this is the second time the
corpus has had to say so, which is why it is being recorded as a conflict rather than a footnote.

### A note on how two of these three counts went wrong

C-17 counted TN on a citation nobody had opened, and C-24 (mine) generalised a direction of travel
from the cases that pointed one way. **Both are the same error the register keeps catching: a tally
assembled from agreeing sources without going back for the disagreeing one.** The count is now
stated with its instruments so the next revision can check it rather than inherit it.

---

## C-27 · The countersignature trigger is NOT always a State line — and this one has product bite

*Added 2026-09-12 by `gap-closure-west-east.md` §3.5. Refines C-8/C-12/C-15.*

C-12 and `boards-east-northeast.md` §2.2 both state the pattern as countersignature being
*"condition-triggered by the pupil crossing a State line, never a standing requirement of the
document."* **Four distinct triggers are now evidenced at [A], and only one of them is a state line:**

| trigger | instance |
|---|---|
| **State line** | Goa r.116(2); Kerala; Puducherry; MH; GJ; DNHDD; Bihar s.272; JAC; NBSE |
| **BOARD line** — *"बोर्ड के कार्य क्षेत्र से बाहर अर्थात अन्य बोर्ड/विश्वविद्यालयों से"* | **RBSE Anudeshika §1.7** |
| **Document language** — a vernacular certificate needs a DEO-countersigned English attested copy | **RBSE Anudeshika §1.8(2)** |
| **Institution type** — a school managed by a Social Welfare Organisation | **BSE Odisha admission form, field 07** |
| **Unrecognised sending school** | Bihar Education Code s.280 |

**The consequence is a schema consequence, not a note.** A rule engine keyed on
`sendingState != receivingState` gets a common Rajasthan case wrong: **a CBSE school in Jaipur to an
RBSE school in Jaipur is intra-State but inter-Board, and it triggers the full inbound gate** —
eligibility certificate plus a DEO-countersigned TC.

**The correct key is the sending BOARD/jurisdiction, with document language and institution type as
additional triggers. Not the sending state.**

Taken with C-26 (a global boolean is wrong in Himachal), countersignature now has **two** established
ways to be modelled wrongly: as one flag, and as a state comparison.

---

## C-28 · "No prescribed format" conflates three legally distinct situations

*Added 2026-09-12 by `gap-closure-west-east.md` §3.6. Third correction to C-16.*

C-16 said Annexure-I was the only prescribed TC format; C-16c corrected that to "look to the state,
not the board." **The negative is still too coarse — "no prescribed format" covers three situations
with different legal consequences:**

| situation | meaning | instances |
|---|---|---|
| **No format exists** | nothing prescribes the document's fields | MP, Chhattisgarh |
| **A format exists and is published** | a citable form with mandatory fields | CBSE Annexure-I · Gujarat નમૂનો-૯ · MH Appendix Four · Kerala Form 5 · TN Appendix-5/5-A · WB Schedule II *(completion certificate)* |
| **A format is legally MANDATORY but NOT PUBLISHED** | validity turns on a form whose text cannot be obtained | **Goa r.127** |

**Goa r.127 is the clean third case and the corpus files it as a plain NOT FOUND, which understates
it:** *"No leaving certificate is valid unless it is in the form prescribed by the Director of
Education"* — with no form annexed. So **a Goa LC in a free-text layout is invalid on the face of the
rule.** Offering a generic template to a Goa school is a compliance risk; the same template in MP is
merely unregulated. Those cannot share a state in the model.

**Action: the format model needs a `formatPrescribedButUnpublished` state**, and WB Schedule II
belongs in the prescribed table scoped to the **completion certificate, not the TC**.

---

## C-29 · Two enforcement mechanisms the taxonomy has no slot for

*Added 2026-09-12 by `gap-closure-west-east.md` §3.8.*

C-12's taxonomy runs *human countersignature* → *printed verifiable identifier*. **Two further
mechanisms are established at [A], and a two-category model has nowhere to put either.**

**1 · The board-issued eligibility certificate, as a hard inbound gate.** **GBSHSE** — pupils
*"shall be admitted to the institutions recognized only on production of eligibility certificate
issued only by this Board"*. **RBSE** — *"पात्रता प्रमाण पत्र के अभाव में प्रवेश नहीं दिया जायेगा"*,
carrying a **₹1,000 per-pupil penalty on the school and personal liability on the head** who admits
without one. **Two independent jurisdictions, the same instrument, the same stage (+2 entry).**
It is not a countersignature — nothing is endorsed on the pupil's TC — and not an identifier —
nothing is printed on it. It is **a separate document the receiving board issues before admission may
occur at all.**

**2 · Return-to-issuer verification.** **RBSE §1.8(1)** — on an **intra-Board** transfer the
receiving school must send the TC and marksheet **back to the issuing head**, obtain verification,
and only then grant permanent admission. **No prior instance anywhere in the corpus**, and it is the
only mechanism found that polices **intra-jurisdiction** transfers — precisely the case every
state-line rule treats as needing no check at all.

---

## C-30 · Identifier pattern — two additions, one exclusion, and more evidence against a clean substitution

*Added 2026-09-12 by `gap-closure-west-east.md` §3.7. Updates C-26.*

- **ADD, RBSE [A]** — the Board issues a temporary/permanent affiliation number **plus a school code
  and publishes both on its website** (cl.4(i), 8(iv), 9(iii): *"बोर्ड की वेब साईट पर भी प्रदर्शित
  किया जाएगा"*). Board-published, therefore externally verifiable — the closest structural match in
  the corpus to Karnataka's DISE-code substitution.
- **ADD, West Bengal [A]** — Schedule II **cannot be completed without the school's Index number**.
  But it **coexists with** an outbound counter-authentication rather than replacing it.
- **EXCLUDE, BSE Odisha** — the WhatsApp/portal digitised-certificate service is **delivery of the
  Board's own certificates**, gated by roll number + DOB + OTP. It verifies **a candidate's identity
  to obtain their own certificate**; it does not let a receiving school verify a TC. Counting it
  would overstate the pattern.

**West Bengal is the second jurisdiction after Himachal where a printed identifier and a human
countersignature coexist.** C-26 held that the substitution trend is real but not universal on one
counter-example; it now has two, and the Odisha exclusion shows how easily the count inflates if
"digital certificate service" is read as "verification mechanism."

---

## C-31 · The RBSE "Helvetica font cipher" was MY misdiagnosis, and it misdirected the remedy

*Added 2026-09-12 by `gap-closure-west-east.md` §3.1.*

I carried into the research brief, as fact, that RBSE's `hand_book16.pdf` *"embeds a legacy Devanagari
font while declaring itself Helvetica"* — *"a substitution cipher that could be guessed at"*, needing
*"OCR or a legacy-font mapping"*.

**Direct inspection of the file's font table and image objects [A]:** creator **Canon**, **one image
per page**, 38 pages, a single **Helvetica/WinAnsiEncoding** font. It is **a scan with a bad Latin OCR
layer.** There is no embedded Devanagari font and no cipher — the garbage is an OCR engine's lossy
Latin guess. The remedy is to **render the pages and read them**, which is what closed the gap.

**The practical instruction was right and the reason was wrong**, and that combination is the
dangerous one: *"do not guess at it"* saved the corpus from asserting a mis-transliterated clause, but
*"needs a legacy-font mapping"* pointed at a fix that cannot work, and the same label had been applied
to **two different defects**. The corpus separately records UBSE's recognition conditions as
*"legacy Devanagari font declared as Helvetica"* — **that one may be the genuine article**, and RBSE
publishes `MFDEV010.TTF` which is the mapping for that class only.

**Action: re-diagnose every "Helvetica" entry individually** (`get_fonts()` + `get_images()`) and split
the register into *"scan — needs rendering"* vs *"mis-declared font — needs mapping"*. They are not
the same problem and they do not have the same fix.

---

## C-32 · There was never a "legacy Devanagari font" file — BOTH candidates are Canon scans

*Added 2026-09-12. Closes the action C-31 left open.*

C-31 established that RBSE's `hand_book16.pdf` was a scan, not a font cipher, and left an action:
re-diagnose every *"Helvetica"* entry individually, because the corpus separately recorded UBSE's
*General and Compulsory condition for Recognition* as *"legacy Devanagari font declared as
Helvetica"* — **"that one may be the genuine article."**

**It is not.** Direct inspection **[A]**:

| | RBSE `hand_book16.pdf` | UBSE recognition conditions |
|---|---|---|
| creator | `Canon` | **`Canon SC1011`** |
| producer | — | **`MP Navigator EX`** |
| pages / images | 38 / one per page | **11 / 11** |
| fonts | single `Helvetica/WinAnsiEncoding` | **single `Helvetica/WinAnsiEncoding`** |
| text layer | Latin OCR garbage | **23,601 chars of Latin OCR garbage** |

**Same defect, same scanner software, same wrong diagnosis.** The category "legacy Devanagari font
declared as Helvetica" is **empty** — no file in the corpus is an instance of it. `MFDEV010.TTF`
would not have helped either document.

**The remedy for both is to render the pages and read them**, which is how the UBSE instrument was
read (§ `ubse-recognition-conditions.md`). Every gap that cited this diagnosis as the reason a
document was unobtainable should be reopened — **the corpus abandoned at least two primary
instruments on a font theory that was never true of either.**

**The lesson is narrower than "check your diagnosis."** The original inference — garbled Devanagari
in a PDF claiming Helvetica means a mis-declared legacy font — is *a reasonable inference*. It was
stated once, then **cited as established by later streams**, and the corpus's honesty rule
("do not guess at the clause") protected the *content* while letting the *diagnosis* harden
unexamined. A wrong reason that recommends the wrong remedy is more expensive than an admitted gap,
because it makes the gap look closed-by-impossibility.

---

## C-33 · Per-section recognition, established in a NORTHERN state at [A] — twice over

*Added 2026-09-12. Extends C-14.*

C-14 is my own correction, recording that I over-generalised per-section recognition from one
Telangana order and could confirm it in **only 2 of 8 southern states**. **UBSE now establishes it
outside the south, on two independent mechanisms in one instrument [A]:**

**By fee** (Reg 3(ख)) — first Intermediate recognition ₹10,000; **any additional वर्ग ₹5,000**;
one-time recognition **₹10,000 per वर्ग**; **₹2,500 per subject**, minimum ₹5,000.

**By evidence burden** (Reg 5(ढ)) — an additional stream or subject requires *"विद्यालय के विगत दो
वर्षों का इण्टरमीडिएट परीक्षा वर्षवार पृथक-2 परीक्षाफल"* — **the school's own last two years of
Intermediate results, year by year, separately.**

**A school is therefore never simply "UBSE-recognised."** It holds recognition for a stage, for
particular streams, and for particular subjects — each bought separately and each earned separately.
Reg 9 adds that no school is recognised **directly for classes 11–12**, so the grant is also
stage-laddered.

**Two conditions found alongside it that no stream had anticipated:** recognition is granted **only
for Hindi-medium teaching** (Reg 8), and the society's registration must be **currently renewed**
(Reg (क)1) — so the *society's* standing is a live component of the school's entitlement, not
background paperwork.

**And recognition is withdrawable on a false particular** (Reg 5(थ)): the manager's ₹10 stamp-paper
affidavit accepts that a false annexure or particular may cause the granted recognition to be
**प्रत्याहरित** (withdrawn), with **personal liability under the IPC 1860**. That is **C-4 on a new
instrument, in its sharpest form** — the triggering event is a false statement in the school's own
application, and the consequence reaches a named individual.

**Consequence for `Issuer_identity`.** The `EVIDENCED` rung means "the affiliation instrument is on
file." Uttarakhand shows an instrument on file is **not** proof of a subsisting entitlement — the
same instrument is revocable retroactively on a defect in how it was obtained. The ladder's refusal
to treat a document as proof of truth is the right shape; this is evidence for that design, not
against it. **But a model that stores one `recognitionValid` per school cannot express "recognised
for Science, not for Commerce", which UBSE grants and prices separately.**

---

## C-34 · The legacy-font class is real — but it is Kruti Dev, and it is fully searchable

*Added 2026-09-12. Refines C-32.*

C-32 concluded the category *"legacy Devanagari font declared as Helvetica"* was **empty**, because
both files the corpus assigned to it are Canon scans. **That conclusion was right about those two
files and wrong as a generalisation.** The class exists; the corpus had simply never identified a
true member.

**UPMSP's `NIRDESH_PUSTIKA_2026_PDF.pdf` is one [A]:**

| | |
|---|---|
| pages | **101** |
| creator / producer | `Microsoft® Word 2010` |
| fonts | `ABCDEE+Kruti Dev 010`, `ABCDEE+Kruti Dev 016`, Calibri, Arial, Times, Symbol |
| images | 6 (not a scan) |
| text layer | **257,512 characters — real, extractable, complete** |
| Kruti Dev pages | **99 of 101** |

**Kruti Dev is a legacy Devanagari font that maps Devanagari onto Latin codepoints**, so
`pdftotext` returns Latin gibberish — the same *symptom* the corpus attributed to a mis-declared
Helvetica, from a completely different *cause*. And unlike a scan, **the text is right there.**

### The practical consequence: these documents are searchable without OCR

Devanagari terms have stable Kruti Dev spellings, so the raw extracted text can be grepped directly.
Verified against this file **[A]** — every probe below returned page hits:

| Kruti Dev | Devanagari | pages |
|---|---|---|
| `ijh{kk` | परीक्षा | 81 |
| `fo|ky;` | विद्यालय | 52 |
| `ftyk` | जिला | 42 |
| `gLrk{kj` | हस्ताक्षर | 38 |
| `izek.k` | प्रमाण | 13 |
| `LFkkuk` | स्थाना | 5 |
| `ekU;rk` | मान्यता | **1** |

**101 pages were triaged in seconds, with no OCR, no font mapping and no rendering** — then the one
page that mattered was rendered and read. This is strictly better than the corpus's recommended
remedy for this class, and it should be the first thing tried on any Devanagari PDF returning Latin
garbage: **check the font table; if it names Kruti Dev, grep the text layer.**

---

## C-35 · UPMSP — the negative NARROWS, and it is still a negative

*Added 2026-09-12.*

The corpus records: *"UPMSP — NOT FOUND — regulations exist but are not published online"*, with TC
format, printed fields, countersignature, migration regulation and duplicates all NOT FOUND, and the
standing instruction **"Do not assert a UPMSP TC-upload rule."**

**That stands, and is now better founded.** The one substantial UPMSP document online was read
(C-34), and its अनुक्रमणिका settles what it is **[A]** — 15 sections, all examination conduct:

> general examination rules (pp. 5–51) · centre inspection report + CCTV certificate (52–56) · blank
> answer-book stock register (57–58) · remuneration schedules (59–65) · seating maps (65–66) ·
> distribution certificates (66–67) · collection form 3 (68) · souvenir (68–69) · art-paper
> distribution (70–73) · मा0शि0प0 79 (74–82) · मा0शि0प0 80 (83) · question-paper envelope seals (84)
> · supplementary paper demand (85) · subject/paper code list (86–95) · High School/Intermediate
> combined exam schedule (96–101)

**No section concerns transfer certificates, recognition, or certificate issuance.** The single
`मान्यता` occurrence in 101 pages is incidental — page 48's centre-inspection form counts
*"invigilators from other recognised institutions."*

**So the negative is narrowed rather than closed:** UPMSP's certificate-facing rules are not merely
un-found, they are **not in the one document that is online**. The Regulations under the
Intermediate Education Act 1921 remain a printed volume.

**This is the opposite outcome to RBSE**, and the contrast is the useful part. There, the
equivalently-named booklet — the **अनुदेशिका** — *was* the student- and certificate-facing document
and closed three open questions (C-31/§3.2). **An instruction booklet is not a document type with a
consistent scope**: Rajasthan's carries admission, TC verification and countersignature rules;
Uttar Pradesh's carries exam logistics. Neither can be inferred from the other's name, and the UP
booklet should not now be cited as evidence that UPMSP *has* no TC rule — only that it is not there.

---

## C-36 · Filenames on education portals are not evidence of content — two near-misses in one session

*Added 2026-09-12. A method entry, recorded because both cases would have produced a fabricated
citation, which is the one failure this programme treats as worse than an admitted gap.*

Reopening the documents the corpus abandoned (C-32) meant working from filenames, because the
portals publish little else. **Two filenames on `hpbose.org` read as exactly the thing being hunted
and were neither.** Both were fetched and read rather than cited.

| filename | reads as | actually is |
|---|---|---|
| `Noti.SOS.T.C.24.07.2025.pdf` | a notification on **T**ransfer **C**ertificates | *"HIMACHAL PRADESH STATE OPEN SCHOOL — **Terms & Conditions** for Center Creation (Private Schools)"* — examination-centre creation: application windows, ₹5,000/₹10,000 late fees, a 35-candidate minimum, a ₹7,000 centre-creation fee **[A]** |
| `Noti_Cond_I_Booklet_compressed_2026.pdf` | the affiliation **Cond**itions booklet | the **Conduct-I** booklet — 48 pages of examination-conduct instructions, footer *"परीक्षा संचालन बारे निर्देश"*, opening with HPBOSE's section-officer contact directory **[A]** |

**"T.C." in an Indian education filename is ambiguous between *Transfer Certificate* and *Terms &
Conditions*, and "Cond" between *Conditions* and *Conduct*.** Both expansions are idiomatic on these
portals; neither is guessable from the string.

**Had either been cited unread, the corpus would carry a primary-source attribution for a rule that
document does not contain** — indistinguishable, to any later reader, from a real citation. That is
strictly worse than the NOT FOUND it would have replaced, because a NOT FOUND invites another look
and a false citation closes the question.

**Rule going forward: a filename may direct a fetch; it may never support a claim.** Every `[A]`
citation in this corpus must rest on text actually read — and on these portals that increasingly
means rendering a scan, since a scan is what the filename is attached to.

### What this leaves for HPBOSE

**Unchanged.** HPBOSE's countersignature rule still rests where the corpus already put it —
**Examination Regulation 3.5.7**, amended 18.01.2012, mandatory and blocking, officer not below
DEO/DIS **[A]**. The **HP Secondary Education Code 2012** and its reported **cl. 2.18** remain
**unread**, and the two documents above are not substitutes for it. HPBOSE stays P0 in
`AUTHORITY_BACKLOG.md` on the strength of 3.5.7 alone.
