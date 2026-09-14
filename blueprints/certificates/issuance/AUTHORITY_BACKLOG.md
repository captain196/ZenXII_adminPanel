# Authority backlog — turning 15,000 lines of research into enforceable profiles

The research is ahead of the code, and this file says by exactly how much so the gap is a plan
rather than a feeling.

**The designer's `AUTHORITIES` corpus encodes 7 authorities.** The research carries **52 with an
`[A]`-graded summary row** — a primary official instrument, cited to clause or page.

| encoded now | id |
|---|---|
| national | `rte` |
| board | `cbse`, `cisce` |
| state | `ker` (Kerala), `tner` (Tamil Nadu), `dser` (Delhi), `apgo` (Andhra) |

**~45 `[A]`-evidenced authorities are researched and unencoded.** Nothing is wrong with the current
7 — a school outside them falls to the generic profile, which enforces nothing and says so, exactly
as `FINAL_BLUEPRINT` designed. The cost of the gap is **silence, not error**: a Himachal school is
told nothing rather than told something false. That is the right failure direction, and it is still
a gap.

## Priority, and why this order

### P0 — authorities whose rule CONTRADICTS the CBSE default already encoded

The corpus tells the designer, under `id: cbse`, that *"COUNTERSIGNATURE IS NO LONGER REQUIRED …
do not enforce it."* **That is correct, and correctly scoped to CBSE.** But it is the only
countersignature statement in the code, so it is the only one a reader sees — and in several
jurisdictions the opposite is true and blocking.

| authority | the rule | evidence |
|---|---|---|
| **HPBOSE** (Himachal) | Regulation **3.5.7**, amended **18.01.2012** — countersignature **mandatory and blocking**, officer **not below DEO/DIS**, carve-out only for HPBOSE→HPBOSE. Plus: report every withdrawal to the Board **within 15 days**, and file countersigned copies with exam admission forms | `[A]`, hpbose.org regulation PDF |
| **Kerala** Ch.VI r.10 | inbound out-of-state TC countersigned by the Inspecting Officer, within 2 months | `[A]` |
| **Puducherry** 1996 r.62 proviso | inbound countersignature | `[A]` |
| **Maharashtra** r.22.1 | inbound countersignature | `[A]` |
| **Gujarat** reg 12(9) | inbound countersignature | `[A]` |
| **Goa** r.116(2) | inbound countersignature | `[A]` |
| **DNH & DD** r.97(2) | inbound countersignature | `[A]` |
| **Bihar** s.272 | inbound countersignature | `[A]` |
| **Delhi** r.139(2) DSER 1973 | inbound countersignature — **and already an encoded authority**, so this omission sat in shipped code. Judicially read in *Kumari Uzma Bano v. GNCTD* (05.08.2010), which also requires the child to be **provisionally admitted while verification proceeds** | `[A]` |

**HPBOSE is first** because it is the one case where the encoded CBSE statement and a live state
regulation point opposite ways for the same act, and the state one blocks.

**Kerala, Tamil Nadu, Delhi and Andhra are already encoded** — but as *state* authorities. Check
whether their inbound-countersignature clauses are represented, because C-15 established that
countersignature is the **receiving** authority's rule, which is a different axis from the issuing
profile these entries model.

### P1 — the printed-identifier mandates (C-26)

Five, all `[A]`, and **not** interchangeable with P0 — Himachal is in both lists, which is the whole
point of C-26:

Karnataka (DISE code, CPI circular 01.06.2016) · Madhya Pradesh (r.19) · Chhattisgarh (CGBSE
cl.16(4), recognition statement **+ मान्यता कोड**) · Himachal (affiliation number on all official
stationery) · Andhra (AP Private Managements Rules).

**Tamil Nadu is deliberately excluded** — C-19 found the citation did not contain the sentence.

### P2 — prescribed formats, which exist and are mostly state-made (C-16c)

I asserted that CBSE's Annexure-I was the only prescribed TC format in India. That was wrong.
**Prescribed formats exist and are mostly made by states, not boards** — Gujarat's **નમૂનો-૯**,
Kerala's **Form 5**, HPBOSE's prescribed **Scholar's Register (Annexure-I)**. Any renderer that
assumes one national format is wrong; any that assumes the board supplies the format is wrong in a
different way.

### P3 — the remaining ~30 state and board authorities

ASSEB Div-I/II · BSEB · BSEH · BSE Telangana · BSEAP · CHSE Odisha · COHSEM · GSEB/GSHSEB · JAC ·
MBSE · MSBSHSE · NBSE · TBSE · WBBSE · Chandigarh · J&K · Jharkhand · Manipur · Meghalaya ·
Mizoram · Nagaland · Odisha · Tripura · Uttarakhand · West Bengal · NIOS · IB.

## Two things this backlog must not become

**Do not encode `[B]`, `[C]` or `[D]` claims as enforceable rules.** Arunachal, Sikkim, Punjab,
Lakshadweep and WBBSE sit at `[B]` or below. The ladder in `Issuer_identity` exists precisely so
that an unverified basis enforces nothing; feeding it weaker evidence defeats it.

**Do not model countersignature as one boolean, and do not key it on the state.** There are now
**two** established ways to get this wrong:

- **As one flag** — a global `countersignatureRequired = false` is **a wrong rule in Himachal**
  (C-26): HPBOSE Reg. 3.5.7 makes it mandatory and blocking.
- **As a state comparison** — `sendingState != receivingState` gets a common Rajasthan case wrong
  (C-27). **A CBSE school in Jaipur to an RBSE school in Jaipur is intra-State but inter-Board, and
  it triggers the full inbound gate**: eligibility certificate plus a DEO-countersigned TC.

**The correct key is the sending BOARD/jurisdiction**, with **document language** (RBSE §1.8(2) — a
vernacular certificate needs a DEO-countersigned English attested copy) and **institution type**
(BSE Odisha, Social Welfare Organisation) as additional triggers. Direction matters too: C-15
established countersignature is the **receiving** authority's rule.

**The format model needs three states, not two** (C-28). "No prescribed format" hides a legally
distinct third case: **Goa r.127** makes a format *mandatory but unpublished* — *"No leaving
certificate is valid unless it is in the form prescribed by the Director of Education"*, with no form
annexed. **A Goa LC in a free-text layout is invalid on the face of the rule.** Offering a generic
template to a Goa school is a compliance risk; the same template in MP is merely unregulated. So:
`noFormat` · `formatPublished` · `formatPrescribedButUnpublished`.

**And the taxonomy needs more than two mechanisms** (C-29). Beyond countersignature and printed
identifiers, two more are evidenced at `[A]`: the **board-issued eligibility certificate as a hard
inbound gate** (GBSHSE and RBSE independently, both at +2 entry, RBSE carrying a ₹1,000 per-pupil
penalty and personal liability on the head who admits without one), and **return-to-issuer
verification** (RBSE §1.8(1) — on an *intra*-Board transfer the receiving school sends the TC back to
the issuing head before permanent admission). The second is the only mechanism in the corpus that
polices intra-jurisdiction transfers — exactly the case every state-line rule treats as needing no
check.

## Status

**Nothing in this backlog is built.** This file is the hand-off, not a change. The 7 encoded
authorities are unchanged and the generic profile still covers everything else.

---

# What the research demands of the product

Ten constraints the corpus has established that **no amount of encoding authorities will satisfy**,
because each is a shape the data model has to have. They are scattered across the conflict register
by the order they were discovered; collected here by what they touch.

## The model is wrong in five specific ways

| # | constraint | why the obvious model fails | source |
|---|---|---|---|
| 1 | **Validity cannot be one field** | recognition terms have no common shape across states | C-13 |
| 2 | **Recognition cannot be one flag per school** | UBSE grants and prices it **per stage × per stream × per subject**, each with its own two-year evidence burden. A school is "recognised for Science, not Commerce" | **C-33** |
| 3 | **Countersignature cannot be one boolean** | `countersignatureRequired = false` is **a wrong rule in Himachal** — HPBOSE Reg. 3.5.7 is mandatory and blocking | **C-26** |
| 4 | **…and cannot be keyed on the state** | RBSE's trigger is a **board** line: CBSE→RBSE within Jaipur is intra-State, inter-Board, and fires the full inbound gate. Add **document language** and **institution type** as triggers | **C-27** |
| 5 | **Format needs three states, not two** | `noFormat` · `formatPublished` · **`formatPrescribedButUnpublished`** — Goa r.127 makes a free-text LC **invalid on the face of the rule** | **C-28** |

## The enforcement taxonomy needs five mechanisms, not two

Countersignature and printed identifiers were the original pair. Three more are evidenced:

| mechanism | effect on the check | instance |
|---|---|---|
| printed verifiable identifier | **replaces** it | Karnataka, MP, CG, HP, AP (C-26/C-30) |
| UDISE waiver | **removes** it | NVS PAP §34 |
| board eligibility certificate | **adds a prior gate** | GBSHSE, RBSE (C-29) |
| return-to-issuer verification | **redirects** it to the sender | RBSE §1.8(1) (C-29) |
| **provisional admission pending verification** | **keeps it, strips its power to block** | Delhi r.139(2), *Kumari Uzma Bano* (**C-60**) |

**The last row is the only one that protects the child without weakening verification.** Any inbound
TC flow must **admit first and verify alongside** — never gate admission on a countersignature
returning.

## Three hard rules about issuance itself

**6 · Never build a no-dues gate.** Seven High Courts across four regions (C-39). There is **no
Supreme Court ruling**, so this is a convergent line rather than binding law — but the product's
answer is the same, and the risk is asymmetric: in Rajasthan, withholding exposes the school to
**de-recognition proceedings**. The lawful alternatives the courts *name* are Rule 167 DSEAR
(strike off the rolls), a civil suit, and "other means" — **none involves the certificate.**

**7 · A school may not manufacture a precondition out of its own form.** Delhi LPA 393/2014 found a
school's self-prescribed TC form demanding both parents' signatures **lacked legal basis**. ZenXii
lets schools compose templates, so this distinction is ours to hold: **composing fields is lawful;
gating issuance on a self-authored field is not established as lawful.**

**8 · Certificate fees may be statutorily capped.** Arunachal r.48: **₹20 government / ₹50 private,
per document**, across transfer/provisional/appearing/character/duplicate (C-37). Only one state so
far — **do not generalise** — but a single product-wide price would breach it there.

## Two about the artefact's real function

**9 · The date of birth is the field that carries the legal weight.** Courts encounter the TC mostly
as **proof of a minor's age** (33 of 33 HP judgments) and as a document that can **correct a Board's
own certificate** (UP Reg. 7 Ch. III). CBSE field 4 names its source: the **Admission & Withdrawal
Register**. So the DOB deserves a **render-time integrity check against its source** and the
**strongest audit trail on amendment**; it is currently treated as an ordinary merge field (**C-62**).

**10 · An instrument on file is not a subsisting entitlement.** Uttarakhand Reg. 5(थ): a false
particular lets granted recognition be **withdrawn**, with personal liability under the IPC. The
`EVIDENCED` rung means "the document exists", never "the entitlement holds" (C-33, C-4).

---

**None of these is built.** They are what the ~45 unencoded authorities will run into, and each is
cheaper to design for now than to retrofit.
