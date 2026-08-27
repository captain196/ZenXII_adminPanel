# Compliance architecture — central, state, and the honest gaps

**Date:** 2026-08-18 · **Status:** DESIGN + working prototype. Nothing committed, nothing deployed.
**Supersedes** the single-`complianceProfileId` model in `FINAL_BLUEPRINT.md` S7.2 and
`COLLECTION_SHAPES.md` §5.

---

## 1 · The defect: one profile cannot describe a real school

Both blueprint documents model compliance as **one profile per document type**, resolved by
`board + state → profile`, falling back to `generic`.

That is wrong about the world. A school is not under one authority; it is under several at once,
with different scopes:

| Layer | Example | Scope |
|---|---|---|
| **National** | RTE Act 2009, s.5(3) | Classes I–VIII only. Reaches every school regardless of board. |
| **Central board** | CBSE Examination Bye-Laws, Annexure-I | CBSE-affiliated schools, in every state. |
| **State** | Kerala Education Rules 1959, Ch. VI | Schools in Kerala, whatever their board. |

A CBSE school in Kerala is bound by **all three simultaneously**. `board + state → profile` forces a
choice between the board rule and the state rule, and whichever loses is silently not enforced.
Worse, the losing one is invisible — the UI shows a single confident profile name, so nobody
discovers the omission until an audit.

**The fix is a stack, not a lookup.** Requirements are the **union** of every applicable layer, and
every individual rule renders the authority it came from.

```
resolveStack(docType, school) = [ national… , board… , state… ]   filtered by scope
requiredKeys = ⋃ layer.requiredKeys
```

Consequences that fall out for free:

- A **state with no transcribed rule** is no longer an invisible `generic` — it is a *named layer*
  that says "Jharkhand — no verified authority", which is a finding rather than a silence.
- **RTE disappears automatically at the secondary stage**, because its scope is classes I–VIII.
  Under the old model that had to be remembered by a human.
- A **document type can be state-specific**. The Kerala Certificate of School Education simply does
  not exist for a school in Jharkhand, and the hub says why rather than hiding it.

---

## 2 · What is actually verified

The evidence discipline from the blueprint is unchanged and, if anything, tightened: each layer
carries its own level, and the levels are not averaged.

| Level | Meaning |
|---|---|
| **A** | Read from the primary text this pass, or previously verified against it. |
| **B** | Cited from the research corpus; the primary text was **not** read this pass. |
| **C** | Practice, not law. |
| **D** | Our own recommendation. Renders with a dashed border so it looks provisional. |

### 2.1 Seeded authorities

| Authority | Tier | Level | What it contributes |
|---|---|---|---|
| RTE Act 2009 s.5(3) | national | A | Constraints only — TC immediate, non-withholdable, classes I–VIII |
| CBSE Examination Bye-Laws Annexure-I | board | A | 19 required keys *(illustrative — see §2.3)*, 3 signatures, seal, stationery serials |
| CISCE Regulations (Jan 2026) | board | A | Constraints only — **no prescribed format**; regulates content |
| **Kerala Education Rules 1959 Ch. VI** | state | **A** | TC = Form 5; six constraints; **and a fully transcribed field list for r.22A** |
| Tamil Nadu Educational Rules | state | B | Duplicate in red ink, once only; conduct+character in one form |
| Delhi School Education Rules 1973 | state | A | A verified **negative** — no TC provision exists at all |
| A.P. G.O.P. 646 (1979) | state | B | Study Certificate is a distinct, retrospective instrument |

Everything else — Maharashtra, Karnataka, Uttar Pradesh, Jharkhand and the rest — resolves to
**no verified state authority**, stated plainly in the panel.

### 2.2 New this pass — Kerala, from the primary source

Retrieved from `education.kerala.gov.in/wp-content/uploads/2019/11/Chapter_6.pdf` and read
directly. This closes part of blueprint **Q9**, which recorded that "Kerala Form 5 / TN Appendix 5
field lists were never retrieved".

- **r.17(1)** — the transfer certificate is issued **in Form 5**, by the Headmaster, during the
  summer vacation or at other times for sufficient reason; at any time for a pupil who has sat a
  public examination.
- **r.17(2)** — *"No transfer certificate shall be issued to a pupil from whom there are any dues to
  the school."* Now confirmed **at source** rather than from a secondary reproduction. The judicial
  read-down travels with it in the UI, permanently attached to the rule.
- **r.17(3)** — a pupil removed from the rolls who is **over 20** receives **not** a transfer
  certificate but a **leaving certificate in Form 5A**. Form 5 and Form 5A are different
  instruments. The synonym table's warning against aliasing "leaving certificate" onto "transfer
  certificate" is now backed by the rule text, with the precise trigger (age 20, removed from rolls).
- **r.18(2)** — the dues condition again, for pupils removed under r.15(iii)/(iv).
- **r.20** — refusal or delay gives the parent a **right of appeal to the Educational Officer**.
- **r.21** — where the Director has grouped neighbouring schools, TCs between them may be barred.
- **r.22** — a duplicate may issue on loss or irremediable damage, on a fee, and on an attestation
  by a Gazetted Officer, a local-authority President, an MLA or an MP; it *"should be clearly marked
  'Duplicate'"*.
- **r.15** — the eight enumerated grounds for removal from the rolls, which are the real vocabulary
  behind "reason for leaving".

**And one document we did not know existed:**

> **r.22A — Certificate of School Education.** Issued to a pupil who left before appearing for the
> S.S.L.C. examination, on application and on remittance of the prescribed fee. **The rule prints
> the form itself**, so its field list is Level A and transcribable exactly:
>
> name (block capitals, full address) · parentage · pupil of this school **from** … **to** …  ·
> the manner of leaving — *left after passing Standard X* / *removed from the rolls for long
> absence while in Standard X* / *discontinued after failing Standard X* — with the standard **in
> words** · **date of birth in words** as per school records · Station · Date · Headmaster · seal.
>
> The fee is waived for the daughters of widows where the certificate supports an application for
> marriage financial assistance — **and the certificate must state that this is its purpose.**

This is the **first state document whose field list we hold at Level A** — ahead of CBSE's, which is
still untranscribed. It ships as a real starter template with the prescribed wording.

### 2.3 What is still not verified, and is labelled so

- **CBSE Annexure-I's 22 field names.** The *existence* of the 22-field format is Level A; the field
  list in the prototype is illustrative and the panel says so. Transcription is gate 0.3.
- **Kerala Form 5's own fields.** Printed in an appendix we have not retrieved. The Kerala TC layer
  therefore enforces **nothing** and contributes constraints only. It does not guess.
- **Tamil Nadu Appendix 5 / 5-B field lists.** Same position, at Level B.
- **Maharashtra's LC as date-of-birth evidence.** Searched again this pass; the governing
  resolution was not retrieved. Remains an open question, not a shipped rule.

---

## 3 · Flexibility — changing the basis, and overriding a layer

Two distinct controls, deliberately separated.

**Change the basis** (`Change` in the compliance panel) sets board, state and the classes taught.
The modal previews the resolved stack live as you change any of the three, so the consequence is
visible *before* it is applied — including the moment RTE drops out when a school is set to
secondary-only.

**Exclude a layer** (the toggle on each layer) suppresses one authority for this template. This is
the "flexibility to change type for compliance" the operator asked for, with one condition: **a
written reason is required and is stored.** Without it the action is refused. An unexplained
exclusion is precisely what an auditor looks for, and a tool that lets a school quietly switch off a
statutory requirement is a liability.

The boundary the blueprint draws is preserved: a school may **exclude** a layer; it may not
**rewrite** one. Authority definitions stay platform-super-admin-only.

---

## 4 · Data-model change required

`COLLECTION_SHAPES.md` §5 embeds `complianceProfiles[]` inside `documentTypes`. That does not
survive the stack, because a state authority applies across many document types and a document type
applies across many states. The relation is many-to-many.

**Proposed — a new platform collection:**

```jsonc
complianceAuthorities/{authorityId}          // platform, no schoolId
{
  id: "ker", tier: "national" | "board" | "state",
  label: "Kerala Education Rules 1959",
  authority: "Kerala Education Rules 1959, Chapter VI",
  evidenceLevel: "A",
  verifiedOn: "2026-08-18",
  owner: "platform-compliance",
  sourceRef: "gs://…/ker_chapter6.pdf",       // the artefact actually read
  reviewIntervalMonths: 12,
  scope: { board: null, state: "Kerala", stages: ["elementary","secondary"] },
  docs: {
    transfer_certificate: {
      form: "Form 5",
      requiredKeys: [],                        // empty = enforces nothing, and says so
      fieldListVerified: false,
      requiredSignatures: [], sealRequired: false,
      constraints: [ { text, citation, judicialNote? } ]
    },
    school_education_certificate: {
      form: "r.22A", fieldListVerified: true,
      requiredKeys: [ "student.fullName", … ],
      requiredSignatures: ["headmaster"], sealRequired: true,
      constraints: [ … ]
    }
  }
}
```

And on the template head, replacing `complianceProfileId` / `complianceProfileVersion`:

```jsonc
complianceBasis: { board: "CBSE", state: "Kerala", stage: "secondary" },
complianceLayers: [                                  // frozen into the published snapshot
  { authorityId: "cbse", version: 4, applied: true },
  { authorityId: "ker",  version: 2, applied: true },
  { authorityId: "rte",  version: 1, applied: false,
    excludedReason: "school teaches IX–XII only" }
]
```

**Why the layers are frozen into the version snapshot:** the question a published template must be
able to answer years later is not "what does Kerala require today?" but *"what was this document
validated against when it was issued?"* Storing only the basis would re-resolve against a corpus
that has since moved. `documentTemplateVersions` already records `fontManifest` and `mpdfVersion`
for exactly this reason; the compliance layers belong in the same place, for the same reason.

Two indexes follow: `complianceAuthorities` by `scope.state`, and by `scope.board`.

---

## 5 · Templates — authored, not sliced

The gallery previously showed the same object array truncated at different lengths. Five real
starters now exist, each declaring the basis it is written for, and the gallery offers only the ones
that match:

| Starter | Type | Written for |
|---|---|---|
| Annexure-I form | Transfer Certificate | CBSE |
| Plain letterhead | Transfer Certificate | any board — prose form, no stationery serial axis |
| **Certificate of School Education** | KER r.22A | **Kerala** — wording transcribed from the rule |
| Classic bonafide | Bonafide | any — free format, bilingual |
| Conduct and character | Character | any — one instrument, per TNER r.34 |

A school in Kerala on the State Board sees the Kerala certificate and the plain TC. A CBSE school in
Jharkhand sees the Annexure-I form and the plain TC, and no Kerala certificate at all.

---

## 6 · Activation — the missing step

Publishing and activating were conflated. They are different acts with different blast radii:

- **Publish** freezes an immutable version with its proof hash, font manifest and engine version.
  Nothing prints because of it.
- **Activate** points the document type at one published version. **This is what every print point
  resolves** — the office button, the Teacher app, a parent's download — and it takes effect
  everywhere at once, with no per-surface rollout.

So activation now has its own confirmation naming what it replaces, and publishing offers it as a
clearly separate second step rather than doing it silently. Deactivation is possible and warns
honestly: with nothing active the print point **fails closed** — it refuses to render rather than
falling back to another template — which is correct behaviour and also a visible outage for the
office.

Certificates already issued are unaffected: each records the version that produced it, and that
record never changes.

---

## 6A · Compliance as routing — when the rule changes *which* document you issue

Every rule so far constrains what a document must **contain**. KER r.17(3) does something different:

> A pupil removed from the rolls who is **over 20** may not be given a transfer certificate at all.
> They receive a **leaving certificate in Form 5A** — a distinct statutory instrument.

No amount of correcting fields on a TC makes it the right document for that pupil. So an authority's
rule may now declare a **route**:

```jsonc
routesTo: [{
  toType:  "leaving_certificate_5a",
  label:   "Leaving Certificate (Form 5A)",
  citation:"KER r.17(3)",
  test:    age at leaving > 20 && removed from the rolls,
  plain:   "A pupil removed from the rolls who is over 20 may not be given a transfer certificate at all."
}]
```

Three surfaces, one rule:

1. **Dormant.** The Kerala TC layer always shows a routing card — *"Sometimes this is the wrong
   instrument"* — with the condition, the citation, and a button that opens the other type. The
   clerk learns the branch exists before they hit it.
2. **Fired.** When the condition holds at the current sample data the card turns statutory-red —
   *"⚠ At this data, the correct instrument is Leaving Certificate (Form 5A)"* — and a **blocking**
   finding appears: *Wrong instrument for this pupil*.
3. **Publish gate.** The row reads *"This is the wrong instrument for this pupil"* and refuses.

Paired with the p95 stress mode this is checkable at design time: switch the sample data and watch
the rule fire, rather than waiting for a real 21-year-old to walk into the office.

**Why this belongs in a template designer**, given the standing rule that compliance validates the
template and never the issuance: it does not gate issuance. It tells the *designer* that this
template will be reached by pupils it must not serve, and points at the instrument that has to exist
alongside it. The gate it closes is **publish**, not **issue**.

`leaving_certificate_5a` ships as a Kerala-gated document type with its own starter. Like the Kerala
TC it **enforces no fields** — Form 5A's list is in the same unretrieved appendix — and says so.

---

## 6B · Contracts are per document type, and reusable blocks couple into them

The field contract was one global list, so the Kerala school-education certificate was offered CBSE
attendance and promotion fields its contract never declares. Contracts are now **per document type**
— 21 fields for a TC, 11 for the Kerala certificate, 16 for Form 5A — and a template binding a key
its own type does not declare raises a **blocking** finding: *"Field not declared by this document
type — the bundle will not carry it, so the render fails closed."*

That check found a real defect in our own starters within a minute of existing:

> The shared **letterhead block** binds `school.affiliationNo`. Every document type whose starter
> uses that block must therefore declare `school.affiliationNo` — even a Kerala government school
> that will leave it blank.

**A reusable block imposes its bound keys on every contract that uses it, and the coupling is
one-way.** Nothing in the blueprint says so; it only surfaces once both mechanisms exist together.
It belongs in `mergeFieldContracts` as a rule: **publishing a block that binds a new key is a
contract change for every type that references it.**

---

## 6C · Language is a mode, resolved by inheritance

`content.i18n` was a flat per-object map with one global fallback. It now resolves:

```
object.lang  →  region language (header / body / footer)  →  template default
```

An object on **Auto** inherits; pinning one shows its language as a tag in the Layers list, exactly
as Figma tags an explicitly-moded layer. Coverage counting was corrected at the same time: an object
deliberately pinned to another language is **not** "untranslated" in this one, so a bilingual
template stops nagging.

The payoff is the case `FINAL_BLUEPRINT` S13.2 costed as **High** retrofit. Genuine side-by-side
text still is. But *a Hindi letterhead over an English body* — which is what most schools mean — is
now one region setting on one template: pin `header` to `हिन्दी`, leave the body on Auto. No second
template, no change to the layout model.

---

## 6D · C2 closed — Kerala Form 5 and Form 5A, transcribed

The forms were not in Chapter VI; they are in a separate appendix,
`education.kerala.gov.in/wp-content/uploads/2019/11/Forms.pdf`. Retrieved and read 2026-08-23.
**Both instruments move from "constraints only" to enforced**, and Kerala becomes the only state
whose transfer certificate we can actually validate.

### FORM 5 — Transfer Certificate *[See rule VI-17(1)]* · 23 particulars

Name of School · Whether the School is Government, Aided or Recognised · Name of Pupil · Name of
Parent/guardian **and relationship to the guardian** · Identification marks, if any · Nationality ·
Religion · Whether the candidate belongs to SC / ST / OBC or is a convert from SC or ST · Date of
birth per the Admission Register **(in words)** · Standard last enrolled **(in words)** · Date of
admission or promotion to that standard · Whether qualified for promotion to a higher standard ·
Whether the pupil has paid all fees due · Whether in receipt of fee concession · Date of last
attendance · Date the name was removed from rolls · Date of application for the certificate · Date
of issue · Reason for leaving · School the pupil intends proceeding to · **Date of last successful
vaccination** · Number of school days up to the date · Number of school days the pupil attended.

Signed **Principal / Headmaster / Headmistress**. Two notes are printed on the form: fee-concession
and scholarship history may be entered below when necessary, and for higher standards the details of
the courses of study should be furnished below.

**Four things nobody would have guessed, and all four matter:**

1. **The form is printed bilingually — English and Malayalam, every label.** A Kerala TC template
   shipped English-only does not reproduce the prescribed form. This is the first hard evidence that
   the multilingual requirement is *statutory* rather than an operator preference, and it validates
   the language-mode work in §6C. ⚠ **We cannot transcribe the Malayalam mechanically**: the source
   PDF stores it in a legacy ASCII-mapped encoding (ML-TTKarthika family), so extraction yields
   mojibake, not Unicode. The starter therefore declares `ml` and leaves it **untranslated** rather
   than inventing text — the coverage indicator reads 2/7 and is telling the truth.
2. **"Whether the pupil has paid all the fees due to the school" is a FIELD on the form, not a
   gate.** The rule records the fact; r.17(2) is what withholds, and the courts read *that* down.
   The distinction our design already drew is confirmed by the form's own structure.
3. **Religion and SC/ST/OBC status are prescribed onto the certificate** — sensitive personal data,
   printed, handed to the pupil, and carried to another school. The rule requires the field; it says
   nothing about who may retain a copy, for how long, or where it is stored. The panel now raises
   this as an advisory rather than silently printing it.
4. **"Date of last successful vaccination" is on a transfer certificate.** A health field, on a
   school-leaving document. No contract we would have designed from first principles contains it.

### FORM 5A — Leaving Certificate *[See Rule VI-17(3)]*

> "This is to certify that ………… was a pupil of the ………… school. He/She was admitted/promoted to
> standard ………… (in words) on ………… He/She left the school on ………… while he/she was reading in
> ………… (in words). His/her date of birth according to the school admission register is …………
> (in words)." — Station, Date, Headmaster, School.
>
> **Note: All certificates to be sealed with the school seal before issue.**

**Form 5A is prose; Form 5 is a particulars table.** They differ in *shape*, not only in when they
are issued — a further, independent reason the two must never be aliased onto one another, on top
of the r.17(3) trigger already modelled as a route in §6A. The sealing note also settles
`sealRequired` for Kerala at Level A.

---

## 7 · Open

| # | Item | Blocks |
|---|---|---|
| C1 | Transcribe CBSE Annexure-I's 22 field names from the source PDF | The only board profile that enforces fields is currently illustrative |
| ~~C2~~ | ~~Retrieve Kerala Form 5 / 5A field lists~~ — **done**, §6D. Both now enforced | — |
| C2b | **Transcribe Form 5's Malayalam labels.** The source PDF's Malayalam is in a legacy non-Unicode encoding; this needs someone who reads Malayalam, not a parser | A Kerala TC cannot reproduce the prescribed bilingual form without it |
| C2c | Decide retention and disclosure for **Religion / SC-ST-OBC** on a printed TC — prescribed by the form, unaddressed by the rule | §6D.3 |
| C3 | Retrieve Tamil Nadu Appendix 5 / 5-B | Moves TNER from Level B to A |
| C4 | Maharashtra LC as DOB evidence — governing resolution not retrieved | Whether the LC is a high-integrity legal record |
| C5 | Karnataka, Uttar Pradesh, West Bengal, Gujarat, Rajasthan — no authority held | ~98% of the market is state boards |
| ~~C6~~ | ~~Model `r.17(3)` as a conditional document-type switch~~ — **done**, §6A | — |
| C7 | Per-state configurable no-dues setting, default off, impossible for classes I–VIII | Carried from the ADR; not yet built |

**C3 is now the sharpest item.** Kerala is now fully modelled — three instruments, all
Level A. Tamil Nadu is the mirror image: we hold r.34, r.40–42 and r.44 by citation at Level B, and
the Appendix 5 / 5-B field lists are unretrieved. The Kerala result suggests where to look — the
forms were not in the chapter, they were in a separate appendix file. The same is likely true of
TNER, and one retrieval would move a second state from constraints-only to enforced.
