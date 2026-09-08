# North India — school recognition and certificate-issuance eligibility

**Scope** Jammu & Kashmir · Ladakh · Himachal Pradesh · Punjab · Haryana · Delhi (NCT) ·
Uttarakhand · Uttar Pradesh · Rajasthan · Chandigarh
**Researched** 2026-09-08 · every claim below carries an evidence level and a URL.

| | meaning |
|---|---|
| **A** | the primary legal text was read directly |
| **B** | an official government portal or circular states it |
| **C** | a credible secondary source |
| **D** | unverified, or a single weak source |
| **NOT FOUND** | searched and not established — *a result, not a failure* |

> **Read this before encoding anything below into a gate.** Where this document says NOT FOUND, the
> product must enforce nothing rather than guess.
>
> Two concrete near-misses from this research show why. **First:** a search result confidently
> attributed *"the certificate shall be for a period of three years and shall be issued within 45
> days"* to **Delhi**. On retrieval the text turned out to be **Madhya Pradesh's** RTE Rules r.11.
> Copied across, it would have told every Delhi school a false expiry date — and Delhi in fact has
> **no fixed statutory term at all**. **Second:** the natural assumption that **Chandigarh follows
> Punjab law** is wrong; Chandigarh applies the **central** RTE Rules 2010 verbatim, because it is a
> UT without a legislature.
>
> Both errors share a shape: **filling a gap by inheriting from a plausible neighbour.** That is the
> specific failure mode this document exists to prevent.

---

## Summary table

| State / UT | Recognition authority | Validity period | TC countersignature required? | Evidence |
|---|---|---|---|---|
| **Delhi (NCT)** | *Elementary/RTE:* Director of Education (Form 1B → Form 2); withdrawal by DEO. *DSEA track:* the "appropriate authority", Form I | **No fixed term in any rule.** Granted "for a limited period" set in the order. Renewal application due **6 months** before expiry | **Yes — but only for a TC issued outside Delhi**, and countersigned by the education authority of the **origin** district. Absence downgrades admission to *provisional*, never blocks it | A |
| **Uttar Pradesh** | *Elementary:* UP RTE Rules r.11 / UP Basic Education Act 1972. *Secondary:* the **UP Board** — s.2(d) defines "recognition" as recognition **for the Board's examinations** | **Provisional 3 years** (r.11(4)). Full-recognition term NOT FOUND | NOT FOUND | C (rules) / A (1921 Act) |
| **Rajasthan** | RTE r.14 **cross-refers out** to the Rajasthan Non-Government Educational Institutions Act 1989. *Elementary:* BEEO → DEEO via rajpsp.nic.in. *Secondary:* RBSE | *Elementary:* **temporary → permanent after 3 years** satisfactory working (1993 Rules r.4). *RBSE:* temporary 3 yrs + 2 yrs relaxation; inspection every 5 yrs | NOT FOUND for Rajasthan specifically | A (Act/Rules) / B (RBSE) |
| **Haryana** | **By class-range** (Rules 2003 r.34): I–V & I–VIII → **DEO**; I–X → **Joint Director**; I–XII → **Director Secondary Education**. *RTE track:* DEEO → Addl./Joint Director (Admin) | **Reviewed every 10 years** (r.39). Lapses if not availed within 1 year (r.41). RTE-track term NOT FOUND | NOT FOUND — the state's own SLC form has a "entries checked and found correct" line but names no BEO/DEO | A |
| **Uttarakhand** | *Elementary:* **Chief Education Officer**, district, Form-2(a) under **r.17(A)** of the UK RTE Rules 2011. *Secondary:* the Board / State Govt under the UK School Education Act 2006 | **5 years** — from two real issued certificates | NOT FOUND | A |
| **Jammu & Kashmir** | **Tiered by class band** (J&K School Education Rules 2010, r.2(b)): classes **9–12 → Administrative Secretary**; **6–8 → Director School Education**; **≤5 → district CEO**. Plus a separate **JKBOSE** recognition per subject and examination (reg. 8) | **No numeric term found** in either instrument. JKBOSE **temporary up to 1 year** (reg. 9); re-inspection every **3 years** (reg. 12) | NOT FOUND as a rule — but J&K has a **stricter gate instead**: no TC may issue until the **Board sanctions the migration** (reg. 19(I)) | A/B |
| **Himachal Pradesh** | *Classes 1–5:* **BEEO**; *classes 1–8:* **Deputy Director Elementary Education**, FORM-I → FORM-II (RTE HP Rules 2011 r.9). *Secondary:* Deputy Director Higher Education | **No fixed period stated in the HP RTE Rules** (confirmed by direct reading). A 5-year cycle is reported for secondary | NOT FOUND (HP-specific) | A (elementary) / B (secondary) |
| **Punjab** | **District Education Officer**, FORM-II within 15 days of inspection (Punjab RTE Rules 2011 r.11; r.12 withdrawal) | **3 years** — Form II text reads *"for a period of three years"* | NOT FOUND (Punjab-specific) | B |
| **Chandigarh** | **District Education Officer**, FORM-2 — under the **central RTE Rules 2010**, which Chandigarh applies verbatim (no UT-specific rules exist) | No general term; the central rules' 3-year window applies to pre-existing schools only | NOT FOUND (Chandigarh-specific) | A |
| **Ladakh** | **NOT FOUND** — CEO structure confirmed administratively for Kargil, but not as the *legal* authority | **NOT FOUND** | **NOT FOUND** | — / B (admin only) |

---

## 0 · The central floor that overrides every state below

Three central provisions bind all ten jurisdictions and are the safest things in this document
to encode, because they were read verbatim.

**RTE Act 2009 s.5(3) — a missing TC may never block admission.** [A]
> *"For seeking admission in such other school, the Head-teacher or in-charge of the school where
> such child was last admitted, shall immediately issue the transfer certificate: Provided that
> **delay in producing transfer certificate shall not be a ground for either delaying or denying
> admission** in such other school: Provided further that the Head-teacher or in-charge of the
> school delaying issuance of transfer certificate shall be liable for disciplinary action…"*
> — https://indiankanoon.org/doc/154784849/

s.5(2) extends the right to transfer *"either within a State or outside"*. **Scope limit:** s.5 sits
in the elementary-education chapter, so it protects a child through **class VIII**. No equivalent
central protection for classes IX–XII was found.

**Design consequence, and it cuts against the obvious build.** A certificate module must never
make TC production a hard precondition of admission for an elementary student, and the *issuing*
school is the party under legal duty — with disciplinary liability for delay. A "withhold the TC
until dues are cleared" feature is unlawful at elementary level in every state in this document.

**RTE Act 2009 s.18 — recognition is the licence, and running without it is an offence.** [A]
> s.18(1) *"No school, other than a school established, owned or controlled by the appropriate
> Government or the local authority, shall, after the commencement of this Act, be established or
> function, without obtaining a certificate of recognition…"*
> s.18(4) *"With effect from the date of withdrawal of the recognition under sub-section (3), no
> such school shall continue to function."*
> s.18(5) penalty — fine up to **₹1 lakh**, plus **₹10,000 per day** of continuing contravention.
> — https://indiankanoon.org/doc/125645684/

This confirms `HOW_ELIGIBILITY_ACTUALLY_WORKS.md`: recognition, not affiliation, is the anchor.
Note s.18(1) exempts **government and local-authority schools** — they function without a
recognition certificate at all. A model that demands a recognition order from every school will
fail on the entire government sector.

---

## 1 · Delhi (NCT)

### 1.1 Which rules govern

Two regimes run in parallel and this research did **not** establish how a K–12 Delhi school
reconciles them:

- **Delhi School Education Act 1973 + Delhi School Education Rules 1973**, Chapter IV
  "Recognition Of Schools", **rules 49–58**. [A — full text read]
  https://www.indiacode.nic.in/ViewFileUploaded?path=AC_DL_64_802_00001_00001_1547200163161%2Frulesindividualfile%2F&file=rule.pdf
- **Delhi Right of Children to Free and Compulsory Education Rules 2011**, rules 12–15. [B]
  https://www.legitquest.com/act/delhi-right-of-children-to-free-and-compulsory-education-rules-2011/C1E4

### 1.2 Recognition process

*RTE track* [B] — r.14(1) existing recognised schools file a **self-declaration in Form 1(A)** with
the concerned **District Education Officer** within two months. r.14(5) schools established after
commencement *"shall apply for recognition in **Form 1(B)** to the **Director of Education** or any
person authorised by him"*; conforming schools are *"granted recognition by Appropriate Authority
in **Form 2**"*. r.15(1) the **DEO** may withdraw recognition; r.15(2) withdrawal *"shall be
operative from the immediately succeeding academic year."*

*DSER track* [A] — r.49 application in **Form I** to the "appropriate authority", delivered by hand
or by registered post AD. r.50 sets ~20 conditions: society registered under the Societies
Registration Act 1860 or a public trust; an approved scheme of management; the school *"serves a
real need of the locality"*; not run for profit; non-discriminatory admission; adequate building;
r.50(xix) *"all records of the school are open to inspection by any officer authorised by the
Director"*. r.51 facilities. r.52 the authority may **provisionally exempt** a school from r.50/r.51
for a period. r.53 recognition is effective from a date decided by the authority, ordinarily the
start of the school year.

**Lapse and withdrawal are unusually aggressive here.** r.55(1): if a recognised school ceases to
function, **is shifted to a different locality**, or is transferred to a different trust or society
without prior approval, *"its recognition shall lapse … and it shall, for the purpose of future
recognition, be treated as a new school."* r.55(2): 60 days after an unremedied non-compliance
notice, recognition *"shall … stand lapsed."* r.56 withdrawal or suspension after show-cause,
reasons communicated within 7 days; r.58 appeal within 30 days.

⇒ **Premises-bound, exactly as the Hyderabad order in `HOW_ELIGIBILITY_ACTUALLY_WORKS.md` was.**
A school that moves address has *no* recognition, immediately and automatically, with no order
required. That is a status change the product cannot detect from any field it currently stores.

### 1.3 Validity period — a conflict between two published texts, and no fixed term either way

| source | rule 54(2) |
|---|---|
| **indiacode.nic.in** [A] | *"[* * * * * * * *]"* — **omitted by DSE(A)R 1990, R.12** |
| **legitquest** [C] | present: *"Where a recognition has been granted to a private school for a limited period, such recognition shall lapse on the expiry of that period unless such recognition is renewed before the expiry of that period"* |

**CONFLICT RECORDED, NOT RESOLVED.** The two published versions of the same rule disagree on
whether 54(2) survives the 1990 amendment.

**It does not matter for our purpose, because neither text states a number of years.** Confirmed
against both. Recognition may be granted *"for a limited period"* whose length is set in the
order itself — which is precisely the "read the period off the order, never assume" finding
already recorded for Andhra Pradesh.

**The one hard number, and it is not 90 days** [A] — r.54 first proviso:
> *"no recognition shall be renewed unless an application for such renewal has been made, in Form
> I, **not less than six months before** the date on which the recognition is to expire"*

second proviso: the appropriate authority may relax that time limit for sufficient cause.

⇒ **The renewal warning window is per-state data, not a constant.** Delhi is 6 months; the Andhra
order said 90 days. Hard-coding either is wrong.

### 1.4 Recognition is per stage, and above primary per subject [A]

Form I proforma, DSER 1973:
- item 5 *"State upto which educational facilities provided (primary, middle or higher secondary)"*
- item 7 *"**Stage of education upto which recognition desired** (primary, middle or higher secondary)"*
- item 8 *"In case recognition is desired upto middle and higher secondary stages, **subjects in which recognition is desired**"*

Stages are defined in r.2: pre-primary; primary I–V; middle VI–VIII; secondary IX–X; senior
secondary above X.

### 1.5 Transfer certificates — rule 139, and the countersignature is not what it is assumed to be

DSER 1973 **rule 139 "Admission on transfer certificate"** [A — verbatim]:

- **139(1)** *"No student who had previously attend any recognized school shall be admitted to any
  aided school unless he produces a transfer or school leaving certificate from the school which
  was last attended by him."*
- **139(2)** where the TC was granted by a school in a State or UT **other than Delhi**, it shall
  *"be sent, for verification and counter signature, by the head of the school in which admission
  is sought, **to the education authority of the district in which the school from which the
  transfer certificate was obtained, is situated**."*
- **139(3)** *"If such transfer certificate has not already been countersigned or verified by such
  authority, the student **may be admitted provisionally** pending the verification … and his
  admission shall be confirmed only on the receipt of the verified transfer certificate."*
- **r.140** a student from another recognised school *"shall not be admitted to a class higher than
  the one in which he was studying at his former school unless the transfer certificate states
  that he has been promoted to the next higher class."*
- **r.141** a candidate who never attended a recognised school, seeking classes II–VIII, must
  furnish a **notarised affidavit** giving full previous-education history and exact date of birth,
  plus pass a **suitability test** arranged by the head in consultation with the **Zonal Education
  Officer**. This is the closest thing found in any of the ten jurisdictions to an explicit rule
  for a child arriving from an unrecognised school.
- **r.142** no admission to class IX without having passed class VIII.
- **r.145(2)** the whole chapter applies to **recognised unaided schools** as it does to aided ones.

**Three corrections to the naive model:**

1. The countersigning officer sits in the **origin district**, not the destination. The receiving
   school posts the TC back toward the issuing school's district education authority. Any UI that
   asks a Delhi school to "get the local DEO to countersign" has the direction wrong.
2. The trigger is **crossing a state or UT boundary** — not school type, not board.
3. A missing countersignature **does not block admission**; it makes the admission *provisional*
   until verification returns. Fail-closed is the wrong posture here.

### 1.6 No prescribed TC format exists at rules level [A — confirmed absence]

Grepping the complete DSER 1973 text: **no Form for a transfer certificate or school leaving
certificate appears among the annexed Forms.** The Forms are for recognition (Form I), grant-in-aid
and inspection. The registers a school must keep are those *"specified by the Director from time to
time"* (r.100(iii), head-of-school duties) — i.e. fixed by **departmental instruction, not by rule**.

⇒ **Delhi mandates no TC field list we can enforce.** *NOT FOUND:* the DoE circular specifying the
register and TC proforma.

### 1.7 Board

Delhi has **no state school board**; government and most private schools affiliate to **CBSE**.
Delhi TC practice is therefore CBSE bye-law practice plus DSER r.139. [B — inference from the
absence of a state board; flagged as inference]

### 1.8 A time-limited relaxation that must not be encoded as standing law

DoE circular **No. DE.23(363)/Sch.Br./21**, dated 15.07.2021, continuing circular
No. DE.23(363)/Sch.Br./2020-21/737 of 16.10.2020 [A — circular read]:
> *"no provisional admission in any class shall be denied to the student who has been allotted
> Govt. School of Directorate of Education if parents are unable to provide TC/SLC from the
> previous private recognised school"*

— expressly **for Academic Session 2021-22 only**, a COVID measure.
https://www.edudel.nic.in/upload/upload_2017_18/349_dt_15072021.PDF

---

## 2 · Uttar Pradesh

### 2.1 The structural point that matters most: "recognition" means two different things in UP

- **Secondary (IX–XII)** — **UP Intermediate Education Act 1921, s.2(d)** [A]:
  > *"'Recognition' means recognition for the purpose of preparing candidates for admission to the
  > Board's examinations"*

  and **s.7(4)**: the Board has power *"to recognise institutions for the purposes of its
  examinations."* **s.16-D(3)**: the Director may refer a case to the Board for **withdrawal** of
  recognition. https://indiankanoon.org/doc/169562622/
- **Elementary (I–VIII)** — recognition is the RTE s.18 licence to operate, under UP RTE Rules 2011
  r.11 and the UP Basic Education Act 1972.

⇒ **In UP, a secondary school's "recognition" is exam-eligibility granted by the UP Board — much
closer to what other states call affiliation than to a Delhi or Andhra recognition order.** A UP
"recognition number" is not the same object as a Delhi one. This is a live modelling hazard: a
single `recognitionOrder` field will silently mean different things in different states.

The 1921 Act contains **no section on transfer or migration certificates**. s.7(2) covers
conferment of diplomas and certificates generally; s.7B prohibits unauthorised conferment.
[A — confirmed absence]

### 2.2 UP RTE Rules 2011 — recognition [C throughout]

All of the following is from the Centre for Civil Society, *UP State Regulatory Profile 2024*,
which cites the rule numbers but is a think-tank report, not the rules:
https://ccs.in/sites/default/files/2024-12/SRP_Uttar%20pradesh_CCS%20.pdf

- **r.11** governs recognition. **r.11(2)** the criteria **do not apply** to government or
  local-authority schools.
- **r.11(3)** conditions for private schools — (a) fire-safety certificate per the National
  Building Code; (b) **building held for not less than 10 years**; (c) non-profit operation;
  (d) open to inspection by any government officer.
- **r.11(4)** **provisional recognition for three years**, within which the school must meet the
  standards to obtain full recognition.
- Catchment minimums: **200** for pre-primary and primary, **225** for junior high.
- **Government Order 419/79-6-201318(20)/91** adds classroom, sanitation, drinking-water, teacher
  qualification and PTR requirements plus periodic returns.
- **UP Basic Education Act 1972 s.12** — the Director of Basic Education may inspect and examine
  records; **s.12(2)** provides for **withdrawal of recognition** where defects found on inspection
  are not remedied.

**Why this is only Level C.** The single copy of the UP RTE Rules 2011 located
(righttoeducation.in, Hindi) is a **scanned image with no text layer** (CCITT/JBIG2) and could not
be read by any tool available. The three-year provisional period is therefore **uncorroborated by
primary text**.

### 2.3 UP Board (UPMSP, Prayagraj) — the TC is a board-registration artefact

UPMSP requires the **TC to be uploaded during advance registration for classes 9 and 11**; relaxed
in Aug 2025 so upload is mandatory **only for students arriving from another school**, not for
students continuing in the same school. [C — news reporting only]
https://www.indiatvnews.com/news/india/up-board-relaxes-transfer-certificate-upload-rule-for-classes-9-and-11-registration-2025-08-05-1002149 ·
https://www.thehansindia.com/news/national/up-board-eases-rule-for-uploading-tcs-994404

⇒ Product-relevant even at Level C: in UP the TC is **consumed by an upload workflow at classes 9
and 11**, not merely handed to a parent. A TC that cannot be produced as a file at registration
time is an operational failure regardless of what the rules say.

**Migration certificate** — issued by UPMSP for class 10/12 leavers through the school or board
office, distributed via DigiLocker. [C/D — secondary sources only; no UPMSP regulation retrieved]

### 2.4 NOT FOUND for UP

- The validity period of **full** (non-provisional) recognition, and the renewal lead time.
- **Which named officer** signs an elementary recognition. The BSA (Basic Shiksha Adhikari) is the
  obvious candidate and is widely assumed, but **no source read said so** — not asserted here.
- Any prescribed **TC format or mandated field list**.
- Any rule on **countersignature** of TCs.
- Any rule on **admission without a TC** or from an **unrecognised school**.

---

## 3 · Rajasthan

### 3.1 The RTE Rules delegate recognition entirely to a 1989 Act [A]

**Rajasthan RTE Rules 2011, r.14 (Recognition to School)** — https://indiankanoon.org/doc/68041874/
> *"No school, other than a school established, owned or controlled by the Central Government,
> State Government or the local authority, shall be established or function without obtaining
> recognition under the **Rajasthan Non-Government Educational Institutions Act, 1989**."*

**r.15** — recognition *"may be withdrawn, at any time, as per the provisions of the said Act."*

⇒ Rajasthan's RTE Rules create **no recognition regime of their own**; they cross-refer out. The
1989 Act and the **Rajasthan Non-Government Educational Institutions Rules 1993** are the operative
law. The RTE Rules 2011 contain **no** provision on transfer, migration or leaving certificates.
[A — confirmed absence by full-text search]

The exact **gazette notification number and date** of the Rajasthan RTE Rules 2011 could not be
confirmed from a .gov.in source; a 29-03-2011 date is inferred from document titles only. [C]

### 3.2 Recognition process

- The 1989 Act names the granting body only as the defined term **"Competent Authority"** (s.3) —
  **no specific designation** appears in the retrieved text. [A]
  https://indiankanoon.org/doc/12808186/
- **1993 Rules r.5 — the elementary application calendar** [A]
  https://indiankanoon.org/doc/162552089/
  application by **28 February** (Form Appendix-I) → scrutiny by **31 March** → inspection by a
  committee including the Director of Education, an educationist and an accounts officer →
  inspection report by **30 April** → further information by **15 June** → decision communicated by
  **30 June**.
- *Primary I–V and Upper Primary VI–VIII:* the **Block Elementary Education Officer (BEEO)**
  physically verifies and reports to the **District Elementary Education Officer (DEEO)**, who
  processes the grant; applications run through the **Raj PSP portal**
  (https://rajpsp.nic.in/PSP3/home/PrivateSchoolPortal.aspx), **not** Shala Darpan. [C — a
  secondary source consistent with the statutory chain; the portal itself refused connection]
- *Secondary IX–X and Senior Secondary XI–XII:* a **separate track through RBSE**, governed by
  *सम्बद्धता सम्बन्धी विनियम — 2016* (Affiliation Regulations 2016), covering institutions
  preparing candidates for the Secondary, Praveshika, Senior Secondary and Varishtha Upadhyay
  examinations; an inspection team reports to a statutory recognition committee. [B — RBSE's own
  page] https://rajeduboard.rajasthan.gov.in/5.htm

**NOT CONFIRMED:** that the Joint Director / Director of Secondary Education, Bikaner personally
grants secondary recognition. Only the directorate's location and the existence of the rank were
verified. Not asserted.

### 3.3 Validity

- **1993 Rules r.4 — two kinds of recognition** [A]: **temporary** (initial grant, on application
  with affidavit) and **permanent**, for which an institution qualifies only after it *"has worked
  satisfactorily fulfilling the terms and conditions … for at least three years"* under temporary
  recognition (r.4(ii)(a)).
- **RBSE, in the Board's own words** [B]: *"Temporary recognition granted for 3 years with specific
  relaxation of 2 years. Periodic inspection in 5 years is obligatory."*
  https://rajeduboard.rajasthan.gov.in/5.htm
- Annual re-login on rajpsp.nic.in is described by secondary sources but appears tied to the RTE
  25% admission cycle, **not necessarily to the legal validity of the recognition certificate**.
  [C — do not conflate the two]
- **NOT FOUND:** any 2019–2024 amendment changing the validity period.

### 3.4 Per class-range — supported, not nailed down

Two independent tracks are confirmed (elementary via the 1989 Act machinery; secondary/senior
secondary via RBSE), and the 1993 Rules' standards table (r.10(ix)(a)) lists class bands as
separate categories. **But no single primary document was found stating in terms that a school
must hold separate recognition per level.** Treat as **[B/C composite]**, not A. The retrieved
standards table also showed an internally inconsistent band ("Upper Primary VI–X" overlapping
"Secondary IX–X") that looks like an extraction artefact and needs a human re-read.

### 3.5 Certificates — the 1989 Act and 1993 Rules are silent [A — confirmed absence]

Neither the Act (ss.3–7, 33–34) nor the Rules contain **any** provision about transfer, migration
or other student-facing certificates. They govern *institutional* recognition, grant-in-aid and
staff service conditions. What exists instead: **s.7(2)** — an unrecognised institution is
ineligible for aid; **ss.33–34** — fines up to ₹1,000 for contravening the transfer/closure and
secretary-duty provisions. Notably, **no section criminalises operating without recognition
outright** in the retrieved text (the central RTE s.18(5) penalty does that job).

### 3.6 NOT FOUND / low-grade for Rajasthan

- **No Rajasthan-specific TC format or mandated field list** at any evidence level.
- **Countersignature:** only pan-India commentary found, none of it Rajasthan-sourced. **[D — not
  usable]**
- RBSE **migration certificates** for class 10/12 candidates are issued via the Vidhyarthi Seva
  Kendra, Ajmer, with duplicates for a fee. [C — secondary only; no primary RBSE circular reached]
- **Shala Darpan TC/CC generation** for government schools is asserted by SEO aggregator sites
  only; **no official documentation reached**. [C, not B]
- The **RBSE Affiliation Regulations 2016** full text was **not retrieved** — only its name. The
  site is a legacy frameset that defeats plain fetching.
- No rule found on **admission of a student from an unrecognised school**. Rules 12–13 address only
  age proof (hospital/ANM record, anganwadi record, parental declaration) and an extended admission
  window. [A for what those rules do say]

---

## 4 · Haryana

### 4.1 Two recognition regimes in parallel — an unresolved conflict

- **Haryana RTE Rules 2011** (03.06.2011) [A]
  https://cdnbbsr.s3waas.gov.in/s3cf2226ddd41b1a2d0ae51dab54d32c36/uploads/2020/12/2020121534.pdf
  **r.12(1)** private schools submit a **self-declaration in Form 1** to the **District Elementary
  Education Officer**; an inspection committee verifies on-site within two months; the **Additional
  Director (Administration) / Joint Director (Administration), Directorate of Elementary
  Education** decides within 45 days of inspection. **r.13** governs withdrawal.
- **Haryana School Education Rules 2003** [A] https://indiankanoon.org/doc/138920847/
  **r.31(1)** application in **Form II** to the "appropriate authority" with a fee scaled by stage
  (₹1,000 primary / ₹2,500 middle / ₹5,000 high / ₹10,000 senior secondary), filed **6 months before
  the academic session** (by 30 September).

**CONFLICT, RECORDED NOT RESOLVED:** neither source explains how a K–12 Haryana school reconciles
the two applications, or whether RTE recognition for I–VIII is subsumed once Rules-2003 recognition
covering the same range is granted. Treat as an open legal question; do not assume either way.

### 4.2 The clearest per-class-range rule found anywhere in North India [A]

**Rules 2003, r.34(1)** — the granting officer **escalates with the class range**:

| classes | grants recognition |
|---|---|
| I–V and I–VIII | **District Education Officer** |
| I–X | **Joint Director**, Director Secondary Education |
| I–XII | **Director Secondary Education** |

and **r.34, Note 2** makes the ladder explicit and sequential:
> *"In case of recognition of school stage-wise recognition shall be considered only i.e. if the
> school has got permanent recognition for primary school (I to V) only then it can apply for the
> recognition of middle school (VI-VIII) and so on."*

⇒ **Recognition in Haryana is a ladder, not a single grant.** A school cannot be recognised for
IX–X without already holding permanent recognition for I–VIII. This is the strongest available
evidence for the "recognition is per class range" model — and it means a school can legitimately
hold recognition for some classes and not others, so a certificate issued for a class outside the
recognised range is not covered.

### 4.3 Validity [A]

- **r.39**: *"The recognition granted to schools affiliated to any board shall be **reviewed after
  every ten years**. If the managing committee fails to comply with any of the conditions and
  facilities specified in these rules, the appropriate authority can withdraw its recognition…"* —
  framed as a **periodic review**, not as an expiry with a renewal application. No separate renewal
  rule was found.
- **r.41**: *"The recognition granted to a school shall lapse unless it is availed of within a year
  from the date on which it is to be effective"* — the same lapse-if-unused rule as Delhi r.54(1).
- **RTE-track validity/renewal period: NOT FOUND** in r.12/r.13.

### 4.4 Certificates

- **Rules 2003 contain no TC / school-leaving-certificate rule.** [A — confirmed absence; the terms
  "transfer certificate", "leaving certificate", "unrecognised" and "unrecognized" do not appear
  anywhere in the document.]
- The Department of Secondary Education nonetheless publishes a standardised **"Form for School
  Leaving Certificate"** [A for the form's content, **D / NOT FOUND for its legal basis** — no rule
  citation is printed on it and none was found in the Rules 2003 text]:
  https://cdnbbsr.s3waas.gov.in/s3cf2226ddd41b1a2d0ae51dab54d32c36/uploads/2021/06/2021060454.pdf
  Fields: SRN / admission register number · pupil name · father's or guardian's name · date of
  birth · school name · **UDISE code** · academic year · admission and withdrawal dates ·
  attendance · exam / promotion status · scholarship information · *"Signatures and seal of Head of
  Institution"* · and a verification line *"Entries checked and found correct."*
- **Countersignature: NOT FOUND / ambiguous.** The verification line names no signatory; a BEO/DEO
  countersignature can be neither confirmed nor ruled out.
- Annual **Form-VI** filing on the portal (fee/compliance self-declaration, uploads the recognition
  certificate) is a portal step, not the recognition grant. [B]
  https://schooleducationharyana.gov.in/regarding-filling-of-form-vi-for-session-2025-26-16-04-2025/

⇒ Note that this form is the one place in North India where a state's own TC proforma **names the
UDISE code as a field** — corroborating the plan to promote UDISE to primary identifier.

### 4.5 Board of School Education Haryana (BSEH), Bhiwani [A]

From the Board's own *Rules & Regulations (updated Feb-2024)*:
https://bseh.org.in/uploads/files/9828270854fa03ddc9174ec82e57e592.pdf
- **Reg. 5(k)** — heads of institutions are **personally responsible** for verifying the
  authenticity of migrating students' documents; *"Students seeking admission in class X or XII
  after passing IX and XI from any other state or Central Board of Secondary Education must get
  enrolled **within 20 days** after getting SLC from the previous school."*
- **Reg. 13(II)** — on migration or change of school, an enrolment number is issued after
  verification of the SLC plus a certified copy of the Board certificate; *"migration certificate
  will be obtained in the absence of father's name, date of birth etc. only"* — i.e. the migration
  certificate is a **remedy for missing particulars**, not a routine requirement.
- **Reg. 13(I)(C)** — permits late enrolment for classes 8–12 for students *"admitted on account of
  migration."*
- **Migration certificate**: ₹300, applied for online via the Antyodaya Saral Portal, Family
  Identity Number mandatory. [A] https://bseh.org.in/certificate-branch

Governing statute: the **Haryana Board of School Education Act 1969** — title confirmed [B], but
section text could not be extracted. The **Haryana School Education Act 1995** (notified as Haryana
Act No. 12 of 1999) could only be reached through search snippets, since indiacode and casemine
both returned HTTP 403 — **[C], not verified against primary text.**

### 4.6 NOT FOUND for Haryana

Admission without a TC, and admission from an unrecognised school — absent from Rules 2003 by
direct full-text search. BSEH Reg. 13(I)(C) is the nearest neighbour but governs late enrolment via
migration, not either question.

---

## 5 · Uttarakhand

### 5.1 The UP-era laws were repealed — this had to be checked, not assumed [A]

**Uttarakhand School Education Act 2006** (Uttarakhand Act No. 8 of 2006, amended by Act 25 of 2015
and Act 32 of 2016), **s.60(1)**:
> *"The Uttarakhand (The Uttar Pradesh Intermediate Education Act, 1921) … and The Uttar Pradesh
> Basic Education Act, 1972 are hereby repealed."*

https://cdnbbsr.s3waas.gov.in/s3bc7f621451b4f5df308a8e098112185d/uploads/2025/03/20250325111930402.pdf

⇒ **Uttarakhand does not inherit UP law.** The two states' certificate regimes must be modelled
separately despite the shared history — and in particular the UP 1921 Act's peculiar
"recognition = exam eligibility" definition (§2.1) does **not** carry into Uttarakhand.

### 5.2 Recognition — elementary [A, from a real issued certificate]

An actual valid recognition ("Manyata") certificate cites the rules directly:
- Issued on **Form-2(a)**, under **rule 17(A)** of the Uttarakhand RTE Rules 2011.
- Withdrawal governed by **rule 18**: *"if prescribed norms and standards of schedule of the Act are
  violated in future, then action shall be taken for the withdrawal of recognition."*
- Granting officer: **"Chief Education Officer", District Haridwar**.
- Scope: **"Pre-Primary to Class VIII"** as a single recognised block.
- Validity: **5 years** (18-03-2024 → 18-03-2029). A second certificate corroborates the five-year
  pattern (03-04-2023 → 03-04-2028).

https://thga.ac.in/wp-content/uploads/2025/02/UK-I-8-MANYATA.pdf

**Caveats stated plainly:** the officer designation is Level A *for this district only* — Level D if
generalised to all districts. The single-block elementary scope rests on **one certificate**. The
Uttarakhand RTE Rules 2011 themselves could **not** be read: the only located copy
(righttoeducation.in) resisted every extraction attempt, so rules 17A and 18 are known only through
the certificate that cites them.

### 5.3 Recognition — secondary [A]

Uttarakhand School Education Act 2006:
- **s.10(e)** — a Board function is *"to recognize institutions for the purpose of adopting its
  curriculum and its examinations."*
- **s.11** — the Board may, with prior State Government approval, recognise an institution *"in any
  new subject or group of subjects or for a higher class"*; separately, *"the District Education
  Officer may permit an institution to open a new section in an existing class."*
- **s.29(1)** — the scheme of administration accompanies the recognition application, for sanction
  of the **Director**.

⇒ Recognition here is incremental — extended class by class and subject by subject — echoing Delhi
Form I item 8 and Haryana's ladder. **But no explicit "recognition is granted stage-wise" statement
comparable to Haryana r.34 Note 2 was found: [C — inference from the Act's structure].**

**Validity for IX–XII: NOT FOUND.** No validity clause in ss.10/11/24/29/60; it likely sits in the
"Regulation-2009" made under the Act, which could not be extracted.

### 5.4 NOT FOUND for Uttarakhand

- Renewal lead time (for either stage).
- Any prescribed **TC format** or **countersignature** requirement.
- **UBSE (Ramnagar) TC/migration procedure.** A document titled *"Procedure (Duplicate Copy,
  Migration, Correction and Scrutiny)"* exists on the official forms page
  (https://ubse.uk.gov.in/forms/) [B for existence] but resisted extraction on two attempts — so
  signatory, documents, fee and class eligibility are **all unknown**.
- Any rule on **admission without a TC** or from an **unrecognised school** — nothing found at any
  level, across the Act, the RTE Rules and UBSE materials.
- A located UBSE document, *"General and Compulsory conditions for Recognition of Inter/High
  school"*, returned only a hedged non-verbatim paraphrase on extraction. **Its claims are
  deliberately excluded from this report rather than reported at a flattering evidence level.**

---

## 6 · Jammu & Kashmir

### 6.1 Which regime applies — and the answer is "two, and the RTE one is not finished"

The erstwhile State of J&K was outside the central RTE Act. That changed with the **J&K
Reorganisation Act 2019 (effective 31.10.2019)**, which extended ~106 central laws — the RTE Act
2009 among them — to the UTs of J&K and Ladakh. [C — PRS India and secondary sources; the
**schedule number is unverified**, so it is *not* cited here as "the Fifth Schedule"]
https://prsindia.org/billtrack/the-jammu-and-kashmir-reorganisation-bill-2019
The current RTE Act text extends *"to the whole of India"* with no J&K carve-out. [A]
https://indiankanoon.org/doc/30032725/

**But the RTE regime is not operational in J&K.** The J&K Education Minister stated in the Assembly
(reported 17 Feb 2026) that the RTE Act *"has not yet been fully operationalised"* because the state
RTE Rules remain **draft only** — placed in the public domain on 20 Aug 2025 and **never
notified**. [B]
https://www.greaterkashmir.com/kashmir/rte-act-not-fully-operational-in-jk-as-rules-yet-to-be-notified/

⇒ **So J&K has no notified RTE Rules at all.** The operative recognition law is the pre-existing
state regime, below. This resolves what was previously the largest open question about J&K — and it
resolves it in an unexpected direction: not "which RTE rules apply" but "none do yet."

### 6.2 The instrument that actually grants recognition [A/B]

**J&K School Education Act 2002** — still in force, no repeal found [A for the text]
https://indiankanoon.org/doc/53808055/ — with the **J&K School Education Rules 2010**
(**SRO 123, dated 18-03-2010**, made under s.29 of the 2002 Act) [A/B]
https://indiankanoon.org/doc/35337092/ · https://schedujammu.nic.in/orders_pvt.htm

**r.2(b) — the granting authority is tiered by class band:**

| classes | grants recognition |
|---|---|
| **9–12** | **Administrative Secretary** |
| **6–8** | **Director, School Education** |
| **up to 5** | **district Chief Education Officer (CEO)** |

**r.8** — the competent authority must decide within **30 days** of the inspection report.

**Online portal** — https://schedujammu.nic.in/pvtschool/ ("Recognition & Affiliation of Schools"),
login-based. [A — portal fetched directly]

**Added conditions since 2022** — **S.O. 177 of 2022 (15 Apr 2022)** layered land-title / NOC /
10-year-lease requirements onto recognition. [C — the primary S.O. text was not read]
Compliance deadlines tied to it were extended by Circulars **06-JK(Edu) of 2024** and
**07-JK(Edu) of 2025** from 31 Mar 2026 to **31 Mar 2027**. [B]
https://www.greaterkashmir.com/education/govt-extends-affiliation-relaxation-of-private-schools-till-march-2027-psajk-welcomes-move

**Two approvals, not one.** JKBOSE **Regulation 7(ii)** makes the Board's recognition *conditional
on continued Education-Department recognition*. [A] A J&K school therefore holds a departmental
recognition (licence to operate, §6.2) **and** a JKBOSE recognition (exam eligibility, §6.3), and
the second dies with the first.

**Certificates are absent from both instruments.** The Act's ss.2, 11–13, 16 and 29 govern
permission, recognition and de-recognition and contain **no mention of "certificate", "transfer
certificate" or "leaving certificate"** — confirmed by direct text search, i.e. a real absence, not
an unsearched gap. The 2010 Rules likewise do not address TCs. [A]

### 6.3 The Board side is documented in unusual detail [A — full regulations text read]

**J&K State Board of School Education Regulations 1992**, made under the **J&K Board of School
Education Act 1975 (Act XXVIII of 1975)**.
Source PDF, text extracted locally: JKBOSE Regulations 1992.

**Recognition is granted by the Board, per subject and per examination** — reg. 8:
> *"If the Board is satisfied that an educational Institution deserves recognition, it shall direct
> the Additional/Joint Secretary to enter its name in the list of affiliation Institution … and the
> Additional/Joint Secretary shall inform the Institution through the Director School Education
> **in which subject, on what conditions and for what examination or examinations** the instruction
> has been recognized."*

- **reg. 9 — temporary recognition**: the Chairman may, where there is no time for the full
  procedure and he is satisfied of the urgency, *"grant temporary recognition for a period which
  may extend to **one year**."*
- **reg. 10** — adding a subject requires running the recognition procedure again for it.
- **reg. 11** — annual returns showing staff personnel and pay, through the Director School
  Education; staff and management changes to be communicated.
- **reg. 12 — periodic inspection**: *"A roaster of institutions shall be prepared for conduct of
  periodical inspection **once in every three years**."* A **Recognition Committee** may select
  institutions for shorter-interval inspection.
- **reg. 13 — withdrawal**: initiated by a report from the **Director of Education** to the
  Chairman → inquiry → **Recognition Committee** → Board; show-cause and representation required;
  the Board issues a warning fixing a period to remove defects, failing which the institution's
  *"name will be struck off the list of Recognised Institutions or … its recognition will be
  withdrawn **in one or more subjects**."*
- **reg. 14** — restoration possible after a further report.
- **reg. 16** — the Board *"shall withdraw the recognition of an Institution in a subject or
  subjects in which it does not adopt the Text Books prescribed by the Board."*
- Recognition conditions include open admission irrespective of religion, belief, caste or colour;
  no recognition for a school involved in activity detrimental to the National Policy on Education;
  audited accounts for middle/high/higher secondary; ~200 working days a year (relaxable).
- **reg. 17** categorises institutions A–D by roll (500+, 300–499, 150–299, under 150).

⇒ **Subject-level recognition is real and it is revocable subject by subject.** J&K and Delhi
(Form I item 8) are the two jurisdictions here where recognition scope is finer than a class range.

### 6.4 Transfer certificates — J&K is the strictest regime found in North India [A]

**Reg. 19 "Inter School/College Migration"** — the operative gate:
> **19(I)** *"No student shall be allowed to migrate from one Institution to another without inter
> migration having been sanctioned in his/her favour. **The Head of the Institution concerned shall
> not issue the transfer certificate until the migration has been sanctioned by the Board.**"*

with a proviso that a student shall not ordinarily migrate mid-session after the admission form for
the ensuing examination has been forwarded to the Board — relaxable in genuine cases such as a
parent's or guardian's transfer, with attendance carried over to the receiving institution.

- **19(ii)** the student must apply on the **prescribed form**, pay all fees due, and refund any
  scholarship or bursary if required.
- **19(iii)** the prescribed fee is **not refunded even if the application is rejected**.
- **19(iv)** *"Migration shall not be sanctioned unless the **Head of both** the recognized
  Institutions agree and the prescribed fee has been paid."*
- **19(v)** once sanctioned, the student *"must join the new institution immediately and not later
  than the **15th day** after the migration certificate has been issued."*
- **19(vii)** *"No migration certificate can be issued unless the student has been registered
  already."*
- **19(viii)** *"Inter-School/College migration shall be allowed **only once in an academic
  year**."*
- **19(ix)** tuition fee is payable to the *outgoing* school up to and including the month in which
  the migration certificate is obtained; the receiving school **shall not charge for that same
  month**.
- **19(x)** *"When migration has been sanctioned by the Board and the student has made all payment
  required by these regulations the Head of the Institution shall grant a transfer certificate **in
  the form prescribed**."*
- **19(xi)** a student not promoted after failing a terminal examination *"shall not be admitted in
  to higher class in another recognized Institution."*

**Reg. 20 — Inter-University/Board migration** (leaving JKBOSE's jurisdiction): migration
certificate on the prescribed form, fee **₹150**, application forwarded by the Head of the
institution; *"ordinarily issued within a week"*; **sent by registered post**, personal delivery
only if the Additional/Joint Secretary authorises it in exceptional circumstances. Where the
receiving board does not demand a migration certificate, the Head of the last institution instead
certifies (a) that the student has not been debarred, rusticated or disqualified, and (b) that the
student owes the Board nothing.

**Reg. 9 — incoming students**: a student admitted from another University or Board *"shall not be
registered unless their applications for registration are accompanied by a Migration certificate
from the previous University/Board"*, with a waiver on a **reciprocal basis** for sister Indian
boards (substituted by conduct and no-dues certificates from the head of the last institution), and
a special rule for students from Pakistan-administered institutions requiring a first-class
magistrate's affidavit.

**Reg. 21** — duplicate inter-board migration certificate on payment of the original fee, granted
only on an affidavit satisfying the Secretary.

**Fee schedule** [A]: inter-school migration certificate **₹120** (duplicate ₹120); inter-board /
university migration certificate **₹150** (duplicate ₹150); date-of-birth certificate ₹100;
certificate of particulars ₹100; provisional certificate ₹100.

### 6.5 The conflict this creates, stated plainly

**JKBOSE reg. 19(I) forbids the school to issue a TC until the Board sanctions the migration, and
reg. 19(ii)(b) conditions it on payment of all fees due. Central RTE s.5(3) requires the head
teacher to issue the transfer certificate *immediately*, makes delay a disciplinary matter, and
bars delay in producing a TC as a ground for denying admission.**

These pull in opposite directions. The likely reconciliation is that the 1992 regulations bind
*Board-registered* students, i.e. the secondary stage, where RTE s.5 does not reach — but **this
research did not establish that**, and the interaction is exactly the kind of question a product
must not resolve by assumption. **CONFLICT RECORDED, NOT RESOLVED.**

If ZenXii ever ships a "block TC until dues cleared" toggle, J&K secondary is the only place in
this document with affirmative rule support for it — and elementary classes in the same school are
governed by the opposite rule.


### 6.6 NOT FOUND for J&K

- **A numeric validity term** for a departmental recognition, under either the 2010 Rules or the
  post-2022 regime. JKBOSE Appendix 5 contemplates recognition as **temporary or permanent**, and
  reg. 12 mandates re-inspection every 3 years with continuance unless formally withdrawn — but no
  "X years" first-grant or renewal term exists in any text read.
- **The prescribed TC form** named in JKBOSE reg. 19(x) — the regulation names it; the form is not
  in the appendix list, which was scanned in full. [A — explicit absence]
- **Whether a ZEO/CEO countersignature is required.** Found only as an untraced search-synthesis
  claim. **[D] — explicitly not encoded.** J&K's real gate is the Board-sanction rule (§6.4), which
  is a different mechanism.
- **Whether the JKBOSE 1992 Regulations have been amended or superseded.** The Board's own
  regulations page lists only this document, "uploaded 09/10/2018", with nothing newer.
  http://jkbose.jk.gov.in/BoseRegulations.html — a 1992 text is being relied on in 2026.
- **Whether RTE ss.5/14/15 are actually enforced in J&K today**, given the Minister's own statement
  that the Act is not fully operationalised and the Rules are unnotified. **This is the single most
  consequential J&K unknown for us**: it decides whether the RTE s.5(3) "issue the TC immediately"
  duty overrides JKBOSE reg. 19(I)'s "no TC until the Board sanctions migration" in practice.
  [C/D — inference only]
- Rules for **admission without a TC** or from an **unrecognised school**, specific to J&K.
- The primary text of the relevant **Schedule to the J&K Reorganisation Act 2019** — indiacode
  returned HTTP 403 on every attempt; a 9.9 MB mirror downloaded but was unparseable.

---

## 7 · Ladakh

**Still largely NOT FOUND — but the shape of the gap is now known, and it is not simply "J&K's rules
apply."**

### 7.1 What was established

- Ladakh is a **UT without a legislature**: it cannot pass its own Acts; only Parliament or the
  Administrator can. A structural fact, not a finding about which instrument governs schools.
- The **RTE Act 2009 was extended to Ladakh** alongside J&K by the Reorganisation Act 2019. [C —
  same PRS source and caveat as §6.1]
- A **UT-level School Education Department** exists under an Administrative Secretary, with separate
  Leh-district and Kargil-district structures. [B] https://ladakh.gov.in/school-education-department/
- **Kargil district**: a **Chief Education Officer** at CEO Office Baroo Kargil, over a Deputy CEO,
  **7 Zonal Education Officers** and 14 Principals. [B] https://kargil.nic.in/education/
  ⚠️ This is the *administrative* structure. **Whether the CEO is the statutory
  recognition-granting authority was not established** — do not infer it from the J&K r.2(b) tier
  table, however tempting the match.

### 7.2 The board position — and why it matters more here than anywhere else

- JKBOSE-affiliated schools existed in Ladakh as recently as **Aug 2023**, when the JKBOSE Chairman
  visited and met heads of affiliated institutions. [C]
  https://www.dailyexcelsior.com/jkbose-chairman-visits-ladakh-assesses-functioning-of-affiliated-schools-sub-centres/
- But some Ladakh private schools hold **CBSE** affiliation instead — Druk Padma Karpo School, Leh
  operated under JKBOSE and needed a JKBOSE NOC to pursue CBSE affiliation (Jan 2022), which it
  obtained around 2024/25. [C]
  https://www.tribuneindia.com/news/j-k/3-idiots-fame-school-awaits-cbse-affiliation-363875 ·
  https://www.deccanherald.com/india/ladakh/3-idiots-fame-ladakh-school-gets-cbse-affiliation-over-two-decades-after-its-inception-3512922
- **No separate "Ladakh Board of School Education"** was found as notified or operational. This is
  an **explicit no-results search finding, not proof of non-existence.**

⇒ **Board affiliation in Ladakh is not uniform.** TC format and countersigning authority will
therefore differ *school by school* within the same UT, depending on whether it sits under JKBOSE or
CBSE. Ladakh is the clearest case in this document where a per-state profile is the wrong unit — the
board matters more than the territory.

### 7.3 What remains NOT FOUND, and the honest reading of it

Which rule-set governs recognition (own rules / inherited J&K rules / central Model Rules) and its
rule numbers; the statutory granting authority and LAHDC Leh/Kargil's role; any recognition portal;
validity period and renewal; class-range scope; TC format, fields or countersignature; admission
without a TC or from an unrecognised school; and JKBOSE's *current* (2026) relationship to Ladakh —
the most recent concrete confirmation dates to 2023.

**Continuation of the J&K School Education Act 2002 / SRO 123 of 2010 in Ladakh is plausible only by
structural inference** — there is no legislature that could have replaced them. That is **[D]
reasoning, not a source**, and it is exactly the inheritance shortcut that produced the wrong answer
for Chandigarh (§10.1). **Ladakh must remain a no-profile jurisdiction that enforces nothing.**

*Research constraint, recorded so this is not mistaken for a settled empty result:* the session's
shared WebSearch quota was exhausted before the Ladakh work began, leaving only WebFetch against
search engines. **Ladakh is under-researched rather than researched-and-empty**, and needs a
dedicated pass.

---

## 8 · Himachal Pradesh

### 8.1 RTE Rules [A — full PDF read]

**"Right of Children to Free and Compulsory Education, Himachal Pradesh Rules, 2011"** —
Notification **No. EDN-C-F(10)-8/09**, Shimla, **5 March 2011**, Department of Elementary
Education, made under RTE Act s.38.
http://righttoeducation.in/sites/default/files/Himachal-pradesh-notified-rte-rules%2C%202011.pdf

- **r.9** — recognition (elementary only, classes 1–8). **r.10** — withdrawal.
- **r.9(1)–(2)** — self-declaration in **FORM-I**, filed with a *different officer depending on the
  class range*: **classes 1–5 → the Block Elementary Education Officer (BEEO)**; **classes 1–8 →
  the Deputy Director, Elementary Education**.
- **r.9(3)–(5)** — inspection within **3 months**; recognition issued in **FORM-II** within **15
  days** of inspection.

### 8.2 Validity — no fixed period, confirmed by reading rather than by failing to find one [A]

The HP RTE Rules state **no validity period** for recognition. The only three-year figure in the
rules is the **compliance window for pre-existing non-conforming schools**, which is a different
thing entirely and is the single most likely figure to be mis-copied into a product as an expiry.

For **secondary** schools a **5-year cycle** is reported (an example cycle 2026–2031 with a renewal
deadline of 15 March 2026). [C — news]
https://www.tribuneindia.com/news/himachal/schools-asked-to-apply-online-for-renewal-of-recognition

### 8.3 Per class-range

**Yes for elementary, and unusually explicit** — the *granting officer itself changes* between
classes 1–5 and classes 1–8. [A] Secondary and senior secondary recognition sits with the
**Deputy Director of Higher Education** at district level. [B — inferred from district portal
pages, e.g. hpkinnaur.nic.in/education, "deals with schools from 9th to 10+2"; the rule number was
not read]

### 8.4 A lead in the brief that turned out to be wrong

**The HP Private Educational Institutions (Regulatory Commission) Act 2010 does NOT cover K–12
schools.** Confirmed by direct read of hpperc.hp.gov.in — it is **higher-education / college
only**. [A] Worth recording because it is a plausible-looking Act whose title invites exactly the
wrong inference.

### 8.5 NOT FOUND for HP

- **No "HP School Education Act"** was found to exist. The **HP Board of School Education Act
  1968** governs HPBOSE. [B — existence only]
- The **HP Secondary Education Code (2012)** reportedly carries the real TC rule — a reported
  clause 2.18 (*"no admission without a TC in prescribed form"*) is **[C/D] only**; primary text
  could not be obtained (404s and non-extractable mirrors).
- **HPBOSE** issues **Migration Certificates** post class 10/12; the form exists on hpbose.org but
  is an unreadable binary PDF. [B for existence, content NOT FOUND] **A migration certificate is
  not a school TC — the two must not be conflated in the model.**
- No HP-specific **TC format**, **countersignature** rule, or rule on **admission without a TC** /
  from an unrecognised school.

---

## 9 · Punjab

### 9.1 RTE Rules [B — read via a legal database, not the gazette]

**Punjab RTE Rules 2011** — **r.11** recognition, **r.12** withdrawal.
https://www.legitquest.com/act/punjab-right-of-children-to-free-and-compulsory-education-rules-2011/cdd8
Exact **gazette notification number and date: NOT FOUND.**

- Recognition is granted by the **District Education Officer** in **FORM-II**, within **15 days** of
  inspection; self-declaration in **FORM-I** within 3 months; the school must be a registered
  society or trust and not-for-profit. [B]
- **r.12(d)** — an order of **the Director** may be challenged by the school management before the
  **Punjab State Commission for Protection of Child Rights within one month**. [C — from a High
  Court judgment that turns on rr.11–12 but does **not** reproduce their text]
  https://indiankanoon.org/doc/40851379/
- **CONFLICT / ambiguity recorded:** the rules as read give the grant to the **DEO**, while the
  appeal provision refers to an order of **the Director**. These two sources were not reconciled.

### 9.2 Validity — 3 years, read off the certificate's own wording [B]

Form II text reads *"…for Class…to Class…for a period of **three years**"*. Whether *full* or
permanent recognition carries a separate validity, or whether three-year cycles simply repeat, is
**NOT FOUND**.

### 9.3 Per class-range

**Yes** — Form II is issued *"for Class X to Class Y"*, i.e. the class range is written into the
grant. [B]

### 9.4 Confirmed amendment history

The **Punjab RTE (First Amendment) Rules 2023** were retrieved in full [A] but amend only **r.13(4)**
(School Management Committee composition) and contain nothing on recognition or certificates.
https://indiankanoon.org/doc/7144431/
Separately, **r.7(4)** (the 25%-reimbursement provision) has been litigated and, per retrieved case
titles, subsequently **omitted** — relevant to fee modules, not certificates. [D]

### 9.5 NOT FOUND for Punjab — much of it a technical block, not an absence

- **epunjabschool.gov.in** hosts a `school-recognition-guidelines.aspx` page confirmed to exist,
  but its **TLS certificate fails validation** on every fetch over both http and https. Content
  totally inaccessible. [existence C; content **NOT FOUND — technical block, not absence**]
- **pseb.ac.in returned HTTP 403 on every attempt**, www and non-www. **PSEB's own TC and migration
  regulations are therefore entirely unverified.**
- **Punjab School Education Board Act** primary text — indiacode.nic.in returned 403.
- **Punjab Regulation of Fee of Unaided Educational Institutions Act 2016** — scope not
  independently confirmed; widely believed fee-only, but that is **[C/D], unverified**.
- Any Punjab-specific **TC format** or **countersignature** rule.
- Any rule on **admission without a TC** or from an **unrecognised school**. One unread headline
  surfaced — *"No provisional recognition for schools set up post RTE Act: HC"* — **[D, headline
  only]**.

---

## 10 · Chandigarh (UT)

### 10.1 The structural finding: Chandigarh has no rules of its own [A — full text read]

**Chandigarh has NO UT-specific RTE rules.** The document hosted as "RTE Rules" on the
Administration's own site is **verbatim the central "Right of Children to Free and Compulsory
Education Rules, 2010"** — Gazette of India Extraordinary Part II s.3(i) No. 180, notified
**8 April 2010** by MHRD under RTE Act **s.38**.
https://chdeducation.gov.in/uploads/17204911647a41837a7f34fb9fb9cddf.pdf

This follows from Chandigarh's constitutional status: a **UT without a legislature**, so s.38 puts
it under the central rules directly — unlike Punjab and Himachal Pradesh, which are states with
their own notified RTE rules.

⇒ **The widely repeated assumption that "Chandigarh follows Punjab law" is wrong for RTE
purposes.** It follows the **Centre**. This is exactly the kind of inherited-by-neighbour guess
that would have shipped a wrong rule.

- **r.15** — recognition: the **District Education Officer** grants, in **FORM-2**, within **15
  days** of inspection.
- **r.16** — withdrawal: notice + hearing + DEO order, **effective from the next academic year**.
- **No TC provision anywhere in this text** — confirmed by two independent readings.

### 10.2 Recognition in practice

A real withdrawal occurred (St Kabir School, 2023) carried out by "Chandigarh Administration's
Education Department", without the report naming an officer title. [C]
https://www.tribuneindia.com/news/chandigarh/st-kabir-recognition-withdrawn-506635

The same case shows recognitions **do carry expiry dates in practice** — St Kabir's *provisional*
recognition ran *"until March 31, 2023"* [C] — though no general "X years" rule was found beyond
what the central rules imply.

It also shows the **consequence pattern** of withdrawal, which is the most useful part for us:
withdrawal takes effect from the **next academic year**; current students may finish the session
and sit board examinations; **no new admissions**; existing students then transfer to government
schools with parental consent. [C]

⇒ Withdrawal is not an instant kill-switch on issuance. A school whose recognition is withdrawn
still legitimately issues documents for the remainder of that session.

### 10.3 NOT FOUND for Chandigarh

- The **exact officer title in practice** (DEO vs "DPI Schools") — chdeducation.gov.in publishes no
  organogram.
- Whether Chandigarh ever issued an **adoption or modification notification** of the central rules.
- Confirmation that Chandigarh schools are **CBSE-affiliated by default**. No Chandigarh-specific
  school board was found anywhere, which is *consistent with* direct CBSE affiliation but is
  absence of evidence. **[D — must not be encoded.]**
- The relevance (if any) of the **Capital of Punjab (Development & Regulation) Act 1952**, which
  appears cited alongside the RTE Act on the EWS portal. Publicly known as an urban-planning Act,
  so **probably a red herring — but unverified either way.**
- Chandigarh's **Fee Regulation Act 2016 / Fee Regulation Rule 2019** exist for unaided schools
  [B — PDFs located, content unread]; titles suggest fee-only.
- Any Chandigarh-specific **TC rule**, format or countersignature requirement.
- The only Chandigarh portal found — online.chdeducation.gov.in/rte/ — is the **EWS/25% quota
  admission** portal, **not** a recognition portal.

---

## 11 · Cross-cutting: the CBSE Examination Bye-Laws, and a correction to the received wisdom

This matters for every jurisdiction here, because **Delhi and Chandigarh have no state board at
all** and CBSE-affiliated schools are common everywhere else.

**Source** [A — read directly, corroborated by a second reading]:
`cbseacademic.nic.in/web_material/publication/archive/byelawsenglish.pdf` — *Examination Bye-Laws
1995, updated upto December 2004*.

> ⚠️ **Staleness caveat, and it is the same one already flagged in
> `HOW_ELIGIBILITY_ACTUALLY_WORKS.md`.** This is an **archive-path** document. Every attempt to
> locate a current or superseding edition failed (404/403). These clause numbers are attested for
> the **1995/2004 text only** and are **not confirmed unchanged** in CBSE's current bye-laws.

- **2(xx)** defines "Transfer Certificate" as the certificate issued to a student by the school on
  seeking transfer, by termination of studies in the previous institution.
- **8(iii)/(vii)** — the TC must be in the **Annexure-I format**; a TC from a **non-CBSE-affiliated**
  institution must be *"duly countersigned by an authority as indicated in the format given in
  Annexure-I."*
- **7.3(c)/7.5(ii)** — transfer into **class X or XII** on family relocation needs the TC *"duly
  countersigned by the Educational Authorities of the Board concerned"*, with post-facto CBSE
  approval within one month.
- **Annexure-I — 22 mandated fields**: name · father's/guardian's name · nationality · SC/ST status
  · date of first admission with class · date of birth · class last studied · exam result · failure
  history · subjects studied · promotion status · **fee dues paid up to** · fee concession · total
  working days · days present · NCC/scout · games · general conduct · application date · issue date
  · reason for leaving · remarks — plus **Class Teacher / Checked-by / Principal + seal** signature
  blocks.
- **Countersignature footnote to Annexure-I** (amended 7.5.1999), verbatim:
  > *"Transfer certificate should be issued only under the signatures of the regular
  > Principal/Vice Principal and it should be counter-signed by an officer not below the rank of
  > District Inspector of Schools/Deputy Director of Education/Education Officer of the Education
  > Deptt. of the State/Union Territory concerned. In case of a student migrating from one CBSE
  > affiliated school to another CBSE affiliated school the transfer certificate…may be
  > countersigned by the Regional Officer of the Board or the Asstt. Commissioner of the KVS or the
  > Deputy Director, Navodaya Vidyalaya Samiti…and the student shall not be admitted to a school
  > without such a counter signature."*

### The correction

**This reads as a GENERAL countersignature requirement, not one gated on interstate or cross-board
transfer.** The CBSE-internal officer chain (Regional Officer / KVS / NVS) is a **carve-out
specifically for CBSE-to-CBSE moves** — it is not evidence that other transfers skip
countersignature. **The commonly repeated claim that "countersignature is only needed for
interstate transfer" is NOT supported by this primary text** and must not be encoded as a rule
without a state-specific primary source. None was found for HP, Punjab or Chandigarh.

**How this layers with Delhi r.139.** These are two different instruments doing two different jobs
and they do not contradict each other:

| | instrument | trigger | who countersigns |
|---|---|---|---|
| **Delhi r.139(2)** | state law, binds Delhi schools admitting a student | TC issued **outside Delhi** | education authority of the **origin district** |
| **CBSE Annexure-I** | board bye-law, binds CBSE-affiliated schools | reads as **general** | DIS / Dy. Director / Education Officer of the State or UT concerned; CBSE RO for CBSE→CBSE |

A CBSE school in Delhi is subject to **both**. The product must therefore treat countersignature as
**layered** — state rule *plus* board bye-law — not as a single per-state boolean.

**Also confirmed [A, from two independently read primary texts]:** the RTE Rules — in both the HP
state form and the central 2010 form — contain **no Transfer Certificate provision at all**. TC
requirements are a **board bye-law or state Education Code** matter, **never** an RTE-rules matter.
That is a useful negative result: it tells us where *not* to keep looking.

**One gap that this stream reported but that was closed elsewhere in this document.** That research
could not fetch **RTE Act s.5** (every mirror returned 403) and flagged it as NOT VERIFIED. It was
subsequently retrieved and read here — see §0. **s.5(3) is confirmed at Level A**, and the
"non-availability of a TC is not a ground for delaying admission" provision is real.

---


## What generalises, and what does not

**Patterns that held across every jurisdiction examined.**

1. **Recognition, not affiliation, is the licence to issue** — and the central RTE s.18(1)
   carve-out means **government and local-authority schools hold no recognition certificate at
   all**. Any eligibility gate that demands a recognition order universally will fail on the entire
   government sector.
2. **Recognition is scoped, never blanket.** Every jurisdiction where the granting instrument or
   form was actually read scopes it: Delhi by stage *and subject* (Form I items 7–8), Haryana by a
   sequential class ladder (r.34 Note 2), J&K by subject and examination (reg. 8), HP by a class
   range that even changes *which officer grants it* (r.9), Punjab by a range written into Form II,
   Uttarakhand incrementally "for a higher class" or new subject (s.11). **A certificate issued for
   a class outside the recognised range is not covered by the recognition.**
3. **Recognition lapses on events, not only on dates.** Delhi r.55(1) and Haryana r.41 both make it
   lapse automatically — on a change of premises, a change of management, or simply not being
   availed within a year. **An expiry date alone cannot model eligibility.**
4. **The elementary and secondary regimes are different laws with different officers**, everywhere.
   A K–12 school is usually recognised twice, by two authorities. **HP and J&K are the clearest
   cases, and they rhyme**: HP's granting officer changes at classes 1–5 → 1–8 → 9–12 (r.9), and
   J&K's at ≤5 → 6–8 → 9–12 (2010 Rules r.2(b), CEO → Director → Administrative Secretary). In J&K
   the two grants are also **chained**: JKBOSE reg. 7(ii) makes Board recognition conditional on
   continued departmental recognition, so the exam-eligibility grant dies with the operating
   licence.
5. **The RTE Rules — state or central — never mention transfer certificates.** Confirmed by direct
   reading of the Delhi, Rajasthan, HP and central 2010 rules. **TC obligations live in board
   bye-laws and state Education Codes.** A useful negative result: it says where to stop looking.
6. **The recognition→UDISE→board chain in `HOW_ELIGIBILITY_ACTUALLY_WORKS.md` survived contact.**
   Nothing found contradicts it, and Haryana's own SLC form naming the **UDISE code** as a field
   corroborates promoting UDISE to primary identifier.

**The biggest variations — none of these can be a constant in code.**

- **Renewal lead time**: Delhi **6 months** (r.54); the Andhra order in the existing corpus says
  **90 days**. Both are rules. It is per-state data.
- **Validity period**: Punjab **3 years** (written into Form II); Uttarakhand **5 years** (from
  issued certificates); Haryana **10-year review**; UP **3-year provisional**; Rajasthan
  **temporary → permanent after 3 years**; J&K Board **temporary up to 1 year**, inspection every
  3 years; **Delhi and HP have no fixed term in any rule at all**. The existing "read the period
  off the order" rule is vindicated — and Delhi and HP prove the period sometimes *only* exists in
  the order.
- **Where the rules even come from** — three different answers among ten jurisdictions:
  **Chandigarh has no rules of its own** and applies the **central** RTE Rules 2010 verbatim under
  s.38 (UT without a legislature); **Rajasthan's** RTE rules exist but **cross-refer out** to a 1989
  Act; and **J&K has no notified RTE Rules at all** — the Act is officially *"not fully
  operationalised"* and the state Rules have sat in draft since Aug 2025, so a 2002 Act and 2010
  SRO still govern. **"Inherit the neighbouring state's law" is the single most dangerous shortcut
  available here** — it would have given Chandigarh Punjab's rules (wrong) and would give Ladakh
  J&K's (unproven).
- **What "recognition" even denotes**: in **UP** (s.2(d)) and **J&K** (reg. 8) it is *exam
  eligibility granted by the board* — nearer to affiliation than to a licence to operate. A single
  `recognitionOrder` field will silently mean two different things across states.
- **Countersignature** — the most consequential finding, and it contradicts the received wisdom:
  - **Delhi r.139(2)** is the only *state* rule found in the ten. It is **origin-district**,
    triggered by **crossing a state line**, and **never blocks admission** — it only makes it
    provisional.
  - **CBSE Annexure-I** (bye-laws 1995/2004) reads as a **general** requirement — countersignature
    by an officer not below DIS / Deputy Director of Education / Education Officer — with a
    **carve-out**, not an exemption, for CBSE→CBSE moves.
  - ⇒ **The widely repeated "countersignature is only needed for interstate transfer" is not
    supported by either primary text.** It is a conflation of the Delhi state rule with the CBSE
    board rule. Countersignature must be modelled as **layered** (state rule + board bye-law), not
    as one per-state boolean.
- **Whether a school may withhold a TC**: **J&K reg. 19(I)/(ii)** affirmatively forbids issuing one
  until the Board sanctions migration and dues are paid; **central RTE s.5(3)** requires immediate
  issue and makes delay a disciplinary offence. Opposite defaults in the same country, plausibly in
  the same school building.
- **What withdrawal does to issuance**: it is **not** an instant kill-switch. Delhi r.15(2) and
  central r.16 both make withdrawal effective **from the next academic year**, and the Chandigarh
  St Kabir case shows current students finishing the session and sitting board exams. A withdrawn
  school still legitimately issues documents for the remainder of that session.

---

## NOT FOUND / needs primary-source retrieval

Ordered by how much damage a wrong guess would do.

| # | Jurisdiction | Item | What was tried |
|---|---|---|---|
| 1 | **Ladakh** | Which rule-set governs recognition and its rule numbers; statutory granting authority (CEO confirmed *administratively* for Kargil, not legally) and LAHDC's role; validity; class-range scope; TC rules; JKBOSE's current status there (last confirmation 2023) | **Under-researched, not researched-and-empty** — the shared WebSearch quota was exhausted before this work began. **Treat as no-profile and enforce nothing; do not inherit J&K's rules** (that is the Chandigarh error, §10.1). |
| 2 | **J&K** | Whether **RTE ss.5/14/15 are actually enforced**, given that the Act is officially "not fully operationalised" and the state RTE Rules remain **draft, never notified** | **The single most consequential J&K unknown.** It decides whether RTE s.5(3) ("issue the TC immediately") overrides JKBOSE reg. 19(I) ("no TC until the Board sanctions migration") in practice — see §6.5. |
| 3 | **J&K** | A numeric validity term for a departmental recognition under the 2010 Rules or the post-2022 S.O. 177 regime | JKBOSE contemplates temporary *or* permanent recognition with 3-yearly re-inspection, but **no "X years" term exists in any text read.** |
| 4 | **J&K** | The prescribed TC form named in reg. 19(x); any ZEO/CEO countersignature rule; whether the **1992** JKBOSE regulations have been superseded; admission from an unrecognised school | The regulation names the form; it is absent from the appendix list (scanned in full). The countersignature claim is **[D], not encoded.** JKBOSE's own page lists only the 1992 text, uploaded 2018 — a 1992 instrument is being relied on in 2026. |
| 5 | **CBSE (all states)** | Whether the current bye-laws still carry the 1995/2004 TC and countersignature clauses unchanged | Every attempt to find a current/superseding edition returned 404/403. **This is the `r.8(vii)` staleness question the README flags — still open.** Clause numbers attested for the archive text only. |
| 6 | **Punjab** | PSEB's own TC/migration regulations; epunjabschool recognition guidelines; gazette number/date; Punjab School Education Board Act text | **pseb.ac.in returned 403 on every attempt**; epunjabschool.gov.in fails TLS validation on http and https; indiacode 403. **Technical blocks, not absence** — retryable from a different network. |
| 7 | **HP** | HP Secondary Education Code (2012) — reportedly holds the real TC rule (a reported cl. 2.18); secondary recognition rule numbers; HPBOSE TC bye-law | Code text: 404s and non-extractable mirrors. HPBOSE migration form located but an unreadable binary PDF. Secondary officer is **inferred from district portals, not read**. |
| 8 | **UP** | Validity of **full** recognition; renewal lead time; the named officer who grants elementary recognition | The only located copy of the UP RTE Rules 2011 is a scanned Hindi image with no text layer. BSA is widely assumed but unsourced — **deliberately not asserted**. |
| 9 | **Chandigarh** | Any local adoption/modification notification of the central rules; the exact officer title in practice (DEO vs DPI Schools); whether CBSE affiliation is the default | Rules text read [A]; the practice questions are unresolved. **"CBSE by default" is Level D and must not be encoded.** |
| 10 | **Haryana** | How the RTE Rules 2011 r.12 track and the School Education Rules 2003 r.34 track reconcile for a K–12 school | Neither source explains it. **Open legal question — do not assume either way.** |
| 11 | **Haryana** | Legal basis of the department's "Form for School Leaving Certificate"; whether countersignature is mandated; Haryana School Education Act 1995 verbatim text | Form retrieved and fields read, but it prints no rule citation and names no countersignatory. indiacode and casemine both 403. |
| 12 | **Uttarakhand** | UK RTE Rules 2011 verbatim text; IX–XII validity period; UBSE TC/migration procedure; admission without a TC | Rules PDF resisted every extraction — rr.17A/18 are known only from a certificate that cites them. Validity likely sits in "Regulation-2009", not extractable. UBSE "Procedure" PDF unreadable on two attempts. |
| 13 | **Rajasthan** | The named designation of the 1989 Act's "Competent Authority" per level; RBSE Affiliation Regulations 2016 text | Act names only the defined term. RBSE site is a legacy frameset that defeats fetching. |
| 14 | **Rajasthan** | Whether Shala Darpan really generates TCs/CCs; whether rajpsp renewal is annual | Asserted by SEO aggregators only; rajpsp.nic.in refused connection. **Level C, not B.** |
| 15 | **Delhi** | Whether r.54(2) survives the 1990 amendment (indiacode says omitted, legitquest prints it); the DoE circular specifying registers and the TC proforma | Conflict recorded. Immaterial to the validity-period conclusion, which holds on both texts. |
| 16 | **All ten** | A machine-readable way to confirm a recognition has **not since been withdrawn** | Nothing found. This remains the hard limit on what an automated eligibility check can honestly claim — as already recorded in `HOW_ELIGIBILITY_ACTUALLY_WORKS.md` §6. |
| 17 | **8 of 10** | Any prescribed TC format or mandated field list | Found in only two: **Delhi has none at rules level** (confirmed absence), **Haryana's SLC form** exists but with no traceable legal basis. **CBSE Annexure-I is the only enforceable 22-field list located.** |

**Method caveat that applies to this whole document.** Much of the "primary text" here was
retrieved through a fetch tool that passes documents through a summarising model rather than
returning verbatim page text. The exceptions — downloaded and text-extracted locally, then read
directly — are the **Delhi School Education Rules 1973** and the **JKBOSE Regulations 1992**, which
are consequently the two most reliable sections in this document. The HP RTE Rules 2011, the
central RTE Rules 2010 as applied to Chandigarh, and the CBSE bye-laws were read in full by the
research stream that reported them. Before any claim below Level A is turned into a hard
eligibility gate, the cited URL should be opened and read by a human.

**Coverage note.** This stream exhausted the session's WebSearch quota (200/200). Everything above
that is marked NOT FOUND for a *technical* reason — Punjab's PSEB (403) and epunjabschool (TLS
failure), the HP Secondary Education Code, the UP and Uttarakhand rules PDFs (scanned images with no
text layer), the relevant Schedule to the J&K Reorganisation Act (indiacode 403) — is **retryable**,
and should be attempted from a fresh session or a different network rather than treated as settled.
**Ladakh is the one jurisdiction that is under-researched rather than researched-and-empty** — the
quota ran out before that work began — and it needs a dedicated pass.

**A note on how this document changed while being written**, because it is the strongest argument
for its own discipline. Three claims were reversed by later retrieval: a "three-year validity" was
about to be attributed to **Delhi** before the source turned out to be **Madhya Pradesh**;
**Chandigarh** was assumed to follow **Punjab** before its rules were read and found to be the
**central** ones; and **J&K's** biggest open question was framed as *"which RTE rules apply"* before
the answer turned out to be ***none are notified yet***. In all three the plausible inference was
wrong and only retrieval caught it. **Every remaining NOT FOUND in the table above should be read as
a claim that could break the same way.**
