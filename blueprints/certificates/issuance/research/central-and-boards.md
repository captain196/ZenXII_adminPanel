# Central compliance and examination boards — what governs a school's authority to ISSUE documents

**Verification date** 2026-09-08 · **Scope** Indian CENTRAL statutes/schemes + every examination board
· **Purpose** ZenXii certificate-issuance module (TC, Bonafide, Character, Migration, Study)

## How to read this file

Every finding carries an **evidence level**:

| level | meaning |
|---|---|
| **A** | I read the primary legal/regulatory text myself (gazette, Act, bye-laws, notification) |
| **B** | Official portal, circular or specification — authoritative but not the statute |
| **C** | Credible secondary source (news, law-firm note, reputable summary) |
| **D** | Unverified — asserted somewhere but I could not stand it up |

**The rule that governs this document: NEVER INVENT A REQUIREMENT.** Where I could not verify
something it says **NOT FOUND** and names what I searched. We are building software that asserts
legal requirements to schools; a confidently stated wrong rule is worse than enforcing nothing.

**Conflicts are recorded as conflicts, not resolved by preference.** See §B1.4 and §D.

### Research constraints on this pass (stated so the next pass knows what to redo)

- `indiacode.nic.in` and `egazette.gov.in` direct PDF paths returned 403/404; RTE text was taken
  from a full-Act reproduction (Indian Kanoon) rather than the gazette — marked **B** not A.
- `cbse.gov.in`, `meity.gov.in`, `education.gov.in` block the fetch tool with HTTP 403 but serve
  fine to `curl` with a browser user-agent. **That technique works and should be the default next
  time.**
- `udiseplus.gov.in`, `apaar.education.gov.in` and `meity.gov.in/data-protection-framework` are
  JavaScript single-page apps that serve no static HTML — nothing could be extracted from them.
- The session's web-search budget was exhausted partway through, so §A2 (NEP), §A5 (UDISE+),
  §A6 (APAAR/ABC) and parts of §B5–B7 are **materially incomplete**. They are marked NOT FOUND
  rather than filled with plausible text.

---

# PART A — CENTRAL STATUTES AND SCHEMES

## A1 · Right of Children to Free and Compulsory Education Act, 2009 (Act 35 of 2009)

Published in Gazette 35 on 26 August 2009; assented and commenced 26 August 2009. Amended by the
RTE (Amendment) Act 2017 (Act 24 of 2017, 9 Aug 2017) and the RTE (Amendment) Act 2019
(Act 1 of 2019, 10 Jan 2019). Text below is the version current from 10 January 2019.

**Evidence B** — full-Act reproduction at <https://indiankanoon.org/doc/30032725/> (s.5 also at
<https://indiankanoon.org/doc/154784849/>). I could not open `indiacode.nic.in` or the gazette PDF
directly (403/404), so this is B, not A. **Re-verify against the gazette before shipping any rule
that depends on exact wording.**

### A1.1 Section 5 — the single most important provision for this module

> **5. Right of transfer to other school. —**
>
> **(1)** Where in a school, there is no provision for completion of elementary education, a child
> shall have a right to seek transfer to any other school, excluding the school specified in
> sub-clauses (iii) and (iv) of clause (n) of section 2, for completing his or her elementary
> education.
>
> **(2)** Where a child is required to move from one school to another, either within a State or
> outside, **for any reason whatsoever**, such child shall have a right to seek transfer to any
> other school, excluding the school specified in sub-clauses (iii) and (iv) of clause (n) of
> section 2, for completing his or her elementary education.
>
> **(3)** For seeking admission in such other school, the Head-teacher or in-charge of the school
> where such child was last admitted, **shall immediately issue the transfer certificate**:
>
> **Provided that** delay in producing transfer certificate shall not be a ground for either
> delaying or denying admission in such other school:
>
> **Provided further that** the Head-teacher or in-charge of the school delaying issuance of
> transfer certificate **shall be liable for disciplinary action** under the service rules
> applicable to him or her.

**What s.5(3) actually says — read precisely, because the popular summary overstates it:**

1. The duty is **"shall immediately issue"**. There is no qualifying condition in the text — no
   "on payment of dues", no "on clearance", no discretion, no time window. This is the strongest
   possible statutory formulation.
2. **The text contains no express words about withholding for fees.** The non-withholdability is
   the *necessary effect* of an unconditional "shall immediately issue", not a separate clause
   saying "shall not be withheld for non-payment". **Do not let the product quote s.5(3) as if it
   said "a TC shall not be withheld for dues" — it does not use those words.** That proposition is
   sound but it is reached by construction plus case law, not by a literal clause.
3. **The first proviso binds the RECEIVING school, not the issuing one.** It says delay in
   *producing* a TC cannot delay or deny admission elsewhere. This is a distinct and independently
   useful rule: the receiving school must admit without the TC in hand.
4. **The second proviso creates personal liability** for the head-teacher who delays — disciplinary
   action under their service rules. Note it is disciplinary, not penal; there is no fine in s.5.
5. **Scope: the elementary stage only.** Section 5(1) and 5(2) both end with the words *"for
   completing his or her elementary education"*. "Elementary education" is education from Class I
   to Class VIII (RTE s.2(f), read with the Act's title and s.3). **Section 5 therefore does not,
   by its own terms, reach Classes IX–XII.** For those classes the source of the obligation is the
   board's bye-laws (§B1), the state education rules, and case law — not RTE s.5.
6. **Two categories of school are carved out** — those in s.2(n)(iii) and (iv), i.e. unaided
   schools not receiving aid and certain specified-category schools. The exclusion is on the
   *destination* of the transfer in 5(1) and 5(2). **The precise practical effect of that carve-out
   is NOT FOUND** — I did not verify s.2(n) sub-clause text on this pass, and it should be read
   before any rule is coded on it.

> **Product consequence.** For Classes I–VIII, no gate ZenXii adds — dues, DigiLocker readiness,
> issuer verification, approval workflow — may stand between a child and their TC. A "blocked
> because fees outstanding" state is unlawful at the elementary stage. Above Class VIII the
> analysis is different and is governed by §B1.2 below (CBSE r.8(vi) *does* say "on payment of all
> dues").

### A1.2 Section 13 — no capitation fee and no screening

> **13. No capitation fee and screening procedure for admission. —**
>
> **(1)** No school or person shall, while admitting a child, collect any capitation fee and
> subject the child or his or her parents or guardian to any screening procedure.
>
> **(2)** Any school or person, if in contravention of the provisions of sub-section (1),—
> **(a)** receives capitation fee, shall be punishable with fine which may extend to **ten times
> the capitation fee** charged;
> **(b)** subjects a child to screening procedure, shall be punishable with fine which may extend
> to **twenty-five thousand rupees for the first contravention** and **fifty thousand rupees for
> each subsequent contravention**.

Relevance to us is indirect: an admission workflow that conditions entry on a test or on a parent
interview is a "screening procedure". Not a certificate-issuance rule.

### A1.3 Section 18 — recognition is the licence to run a school

> **18. No school to be established without obtaining certificate of recognition. —**
>
> **(1)** No school, other than a school established, owned or controlled by the appropriate
> Government or the local authority, shall, after the commencement of this Act, be established or
> function, **without obtaining a certificate of recognition** from such authority, by making an
> application in such form and manner, as may be prescribed.
>
> **(2)** The authority prescribed under sub-section (1) shall issue the certificate of recognition
> in such form, within such period, in such manner, and subject to such conditions, as may be
> prescribed: **Provided that** no such recognition shall be granted to a school unless it fulfils
> norms and standards specified under section 19.
>
> **(3)** On the contravention of the conditions of recognition, the prescribed authority shall, by
> an order in writing, **withdraw recognition**: Provided that such order shall contain a direction
> as to which of the neighbourhood school, the children studying in the de-recognised school, shall
> be admitted: Provided further that no recognition shall be so withdrawn without giving an
> opportunity of being heard to such school, in such manner, as may be prescribed.
>
> **(4)** With effect from the date of withdrawal of the recognition under sub-section (3), no such
> school shall continue to function.
>
> **(5)** Any person who establishes or runs a school without obtaining certificate of recognition,
> or continues to run a school after withdrawal of recognition, shall be liable to fine which may
> extend to **one lakh rupees** and in case of continuing contraventions, to a fine of **ten
> thousand rupees for each day** during which such contravention continues.

**This is the statutory hook for the whole eligibility model.** Note carefully what s.18 does and
does not say:

- It regulates **establishing and functioning** as a school. It says nothing whatsoever about
  issuing certificates.
- **There is no central statute that says "only a recognised school may issue a Transfer
  Certificate."** The link is indirect but firm: recognition is what makes the institution a
  "school" whose records have standing, and the receiving side of the transaction is where the
  requirement actually bites — CBSE bye-law 6.1 Explanation (a) refuses admission on certificates
  of an unrecognised institution (§B1.2), and CBSE's own instruction is to accept a TC signed by
  the principal of any *recognised* school (§C).
- Government and local-authority schools are **outside** s.18(1) — they need no recognition
  certificate. A product that demands a recognition instrument from every tenant will be wrong for
  every government school.

### A1.4 Section 19 — norms and standards, and the withdrawal ladder

> **19. Norms and standards for school. —**
> **(1)** No school shall be established, or recognised, under section 18, unless it fulfils the
> norms and standards specified in the Schedule.
> **(2)** Where a school established before the commencement of this Act does not fulfil the norms
> and standards specified in the Schedule, it shall take steps to fulfil such norms and standards
> at its own expenses, within a period of **three years** from the date of such commencement.
> **(3)** Where a school fails to fulfil the norms and standards within the period specified under
> sub-section (2), the authority prescribed under sub-section (1) of section 18 shall **withdraw
> recognition** granted to such school in the manner specified under sub-section (3) thereof.
> **(4)** With effect from the date of withdrawal of recognition under sub-section (3), no school
> shall continue to function.
> **(5)** Any person who continues to run a school after the recognition is withdrawn, shall be
> liable to fine which may extend to **one lakh rupees** and in case of continuing contraventions,
> to a fine of **ten thousand rupees for each day** during which such contravention continues.

### A1.5 Case law on withholding a TC for fee dues

The repo's existing `DIGILOCKER_COMPLIANCE_PLAN.md` cites four High Court decisions refusing a
fee-dues gate on a TC: Delhi HC LPA 393/2014; Kerala HC 2025:KER:69076; Madras HC DB 22.07.2024;
Karnataka HC 2025:KHC:5986. **Evidence D as cited here** — I did not open any of these judgments on
this pass. One is corroborated at **Evidence C**: SCC Online reports a Madras High Court holding
that a "Transfer Certificate cannot form basis for denying admission in school or to collect arrear
fees from parents"
(<https://www.scconline.com/blog/post/2024/07/22/transfer-certificate-cannot-form-basis-for-denying-admission-school-to-collect-arrear-fees-parents-madras-high-court/>).

**Before ZenXii asserts to a school that it may not withhold a TC for dues above Class VIII, these
judgments must be read.** Below Class VIII, s.5(3) carries the point on its own.

---

## A2 · NEP 2020 — credentials, APAAR, Academic Bank of Credits

**NOT FOUND on this pass.** I could not retrieve the NEP 2020 PDF. Searched/attempted:
`education.gov.in/sites/upload_files/mhrd/files/NEP_Final_English_0.pdf` (404),
`.../NEP_final_English.pdf` (404), `education.gov.in/nep/nep-2020` (403 to fetch tool, no static
HTML to curl), `ugc.gov.in/pdfnews/5294663_Final-English-NEP-2020.pdf` (returned HTML, not the PDF).
The web-search budget was exhausted before I could locate a working mirror.

**What is NOT asserted:** any NEP paragraph number. NEP is a policy document, not law — it creates
no issuance obligation on a school in any event. Its relevance is as the origin of the ABC/APAAR
programme, covered (also incompletely) at §A6.

**Next pass:** curl `https://www.education.gov.in` with a browser user-agent and walk the NEP
landing page for the real asset path, or use the PIB release announcing NEP 2020.

---

## A3 · Digital Personal Data Protection Act, 2023 — children's data

**Evidence A.** Read from the Gazette of India Extraordinary, Part II Section 1, No. 25, New Delhi,
Friday, 11 August 2023 / Sravana 20, 1945 (Saka), document ID `CG-DL-E-12082023-248045`, retrieved
from <https://www.meity.gov.in/static/uploads/2024/06/2bf1f0e9f04e6fb4f8fef35e82c42aa5.pdf> (identical
bytes served at <https://egazette.gov.in/WriteReadData/2023/248045.pdf>).

### A3.1 The definitions that fix everyone's role

> **s.2(f)** *"child" means an individual who has not completed the age of eighteen years;*
>
> **s.2(i)** *"Data Fiduciary" means any person who alone or in conjunction with other persons
> determines the purpose and means of processing of personal data;*
>
> **s.2(k)** *"Data Processor" means any person who processes personal data on behalf of a Data
> Fiduciary;*
>
> **s.2(j)** *"Data Principal" means the individual to whom the personal data relates and where
> such individual is— (i) a child, includes the parents or lawful guardian of such a child; …*
>
> **s.2(g)** *"Consent Manager" means a person registered with the Board, who acts as a single
> point of contact to enable a Data Principal to give, manage, review and withdraw her consent
> through an accessible, transparent and interoperable platform;*
>
> **s.2(u)** *"personal data breach" means any unauthorised processing of personal data or
> accidental disclosure, acquisition, sharing, use, alteration, destruction or loss of access to
> personal data, that compromises the confidentiality, integrity or availability of personal data;*

**Consequences that are not negotiable:**

- **"Child" is under 18.** Not under 13, not under 16. **Effectively the entire student body of a
  K-12 school is children under this Act** — s.9 is not an edge case for us, it is the default.
- **The school is the Data Fiduciary** (it determines purpose and means). **ZenXii is the Data
  Processor.** This follows from the definitions and is not a matter of contractual labelling.
- For a child, the **Data Principal includes the parent/guardian** — so rights requests can come
  from a parent and must be honoured as the Principal's.

### A3.2 Section 9 — children's data, verbatim

> **9.** **(1)** The Data Fiduciary shall, **before processing any personal data of a child** or a
> person with disability who has a lawful guardian **obtain verifiable consent of the parent** of
> such child or the lawful guardian, as the case may be, **in such manner as may be prescribed**.
>
> *Explanation.*— For the purpose of this sub-section, the expression "consent of the parent"
> includes the consent of lawful guardian, wherever applicable.
>
> **(2)** A Data Fiduciary **shall not undertake such processing of personal data that is likely to
> cause any detrimental effect on the well-being of a child**.
>
> **(3)** A Data Fiduciary **shall not undertake tracking or behavioural monitoring of children or
> targeted advertising directed at children**.
>
> **(4)** The provisions of sub-sections (1) and (3) **shall not be applicable** to processing of
> personal data of a child by **such classes of Data Fiduciaries or for such purposes, and subject
> to such conditions, as may be prescribed**.
>
> **(5)** The Central Government may, if satisfied that a Data Fiduciary has ensured that its
> processing of personal data of children is done in a manner that is verifiably safe, notify for
> such processing by such Data Fiduciary **the age above which** that Data Fiduciary shall be
> exempt from the applicability of all or any of the obligations under sub-sections (1) and (3).

**Read s.9(2) carefully.** It is an **absolute prohibition with no consent override**. Parental
consent does not cure processing that is likely to harm a child's well-being. This is the one DPDP
obligation that cannot be contracted or consented around.

**s.9(3)** is the one that constrains product features: no tracking, no behavioural monitoring, no
targeted advertising at children. Analytics that profile a student's behaviour are squarely in
scope. Note the prohibition is on *tracking or behavioural monitoring*, not on record-keeping —
an attendance register is not behavioural monitoring; an engagement-scoring model is.

**s.9(4) is the escape hatch that matters to schools** — and it operates *only through prescribed
rules*. Whether education has been given such a carve-out is at §A3.5, where the answer is
NOT FOUND.

### A3.3 Section 8 — the Data Fiduciary's obligations, and why our contract cannot shield the school

> **8.(1)** A Data Fiduciary shall, **irrespective of any agreement to the contrary** or failure of
> a Data Principal to carry out the duties provided under this Act, be responsible for complying
> with the provisions of this Act and the rules made thereunder **in respect of any processing
> undertaken by it or on its behalf by a Data Processor**.
>
> **(2)** A Data Fiduciary may engage, appoint, use or otherwise involve a Data Processor to
> process personal data on its behalf for any activity related to offering of goods or services to
> Data Principals **only under a valid contract**.
>
> **(3)** Where personal data processed by a Data Fiduciary is likely to be— (a) used to make a
> decision that affects the Data Principal; or (b) **disclosed to another Data Fiduciary**, the
> Data Fiduciary processing such personal data **shall ensure its completeness, accuracy and
> consistency**.
>
> **(4)** A Data Fiduciary shall implement appropriate technical and organisational measures to
> ensure effective observance of the provisions of this Act…
>
> **(5)** A Data Fiduciary shall protect personal data in its possession or under its control,
> **including in respect of any processing undertaken by it or on its behalf by a Data Processor**,
> by taking **reasonable security safeguards** to prevent personal data breach.
>
> **(6)** In the event of a personal data breach, the Data Fiduciary shall give **the Board and
> each affected Data Principal**, intimation of such breach in such form and manner as may be
> prescribed.
>
> **(7)** A Data Fiduciary shall, unless retention is necessary for compliance with any law for the
> time being in force,— (a) **erase** personal data, upon the Data Principal withdrawing her
> consent or as soon as it is reasonable to assume that the specified purpose is no longer being
> served, whichever is earlier; and (b) **cause its Data Processor to erase** any personal data
> that was made available by the Data Fiduciary for processing to such Data Processor.
>
> **(9)** A Data Fiduciary shall publish… the business contact information of a **Data Protection
> Officer**, if applicable, or a person who is able to answer on behalf of the Data Fiduciary…
>
> **(10)** A Data Fiduciary shall establish an **effective mechanism to redress the grievances** of
> Data Principals.

**Four hard product requirements fall straight out of s.8:**

| provision | what ZenXii must build |
|---|---|
| s.8(2) | A **written processing contract** with every school. Not a ToS — a data-processing agreement. Without it the school is in breach merely by using us. |
| s.8(3) | A certificate **is** a decision-affecting, disclosed-to-third-party record. Completeness/accuracy of the data printed on a TC is a statutory duty, not a quality goal. |
| s.8(6) | Breach notification runs to **the Board *and* every affected Data Principal**. Our contract must give the school what it needs to do that, fast. |
| s.8(7)(b) | **Erasure must propagate to us on the school's instruction.** A delete in the panel must actually delete, including backups policy. This is the obligation our current design is least likely to satisfy. |

**s.8(1) is the one to quote back at any school that offers a warranty.** The Data Fiduciary is
responsible "irrespective of any agreement to the contrary" for processing done *on its behalf by a
Data Processor*. Two readings follow and both matter:

- The **school cannot contract out** of responsibility for what ZenXii does with the data.
- Symmetrically, **a school's warranty that it holds parental consent does not discharge ZenXii.**
  We remain a Data Processor with our own exposure, and the Act's structure means our breach becomes
  the school's liability — which is a commercial risk to us even where it is not a direct statutory
  penalty on us.

*(This confirms the position already recorded in this project's memory under "Student AI assistant":
a DPDP s.8(1) school warranty does NOT discharge the vendor.)*

### A3.4 Penalties — the Schedule

**Evidence A**, THE SCHEDULE [See section 33(1)]:

| # | breach | maximum penalty |
|---|---|---|
| 1 | Failure to take **reasonable security safeguards** to prevent a personal data breach — **s.8(5)** | **₹250 crore** |
| 2 | Failure to give the Board / affected Data Principal **notice of a breach** — s.8(6) | ₹200 crore |
| 3 | **Breach of the additional obligations in relation to children — s.9** | **₹200 crore** |
| 4 | Breach of Significant Data Fiduciary obligations — s.10 | ₹150 crore |
| 5 | Breach of the Data Principal's duties — s.15 | ₹10,000 |
| 6 | Breach of a voluntary undertaking accepted under s.32 | up to the amount applicable to the underlying breach |
| 7 | **Breach of any other provision** of the Act or rules | ₹50 crore |

The two largest exposures in the entire Act — ₹250 crore and ₹200 crore — are **exactly the two
that a school ERP sits on**: security safeguards, and children's data.

### A3.5 Section 17 exemptions — is there one for schools?

**Evidence A.** s.17(1) disapplies Chapter II (except s.8(1) and s.8(5)), Chapter III and s.16 where
processing is for: enforcing a legal right or claim (a); processing by a court/tribunal or a body
with judicial, quasi-judicial, **regulatory or supervisory** functions (b); prevention/detection/
investigation/prosecution of an offence (c); non-resident data under a foreign contract (d);
merger/amalgamation approved by a court or authority (e); ascertaining assets of a loan defaulter (f).
s.17(2)(a) allows the Central Government to notify an instrumentality of the State as wholly exempt.

**There is no exemption for educational institutions in s.17.** A private school is not a body
"entrusted by law with a regulatory or supervisory function". A *government* school might fall under
a s.17(2)(a) notification, but only if one is made naming it — **NOT FOUND** whether any such
notification exists.

Note that **even where s.17(1) applies, s.8(1) and s.8(5) survive** — responsibility, and reasonable
security safeguards, are never exempted.

### A3.6 Commencement and the DPDP Rules — **PARTIALLY NOT FOUND · treat as OPEN**

The Act received assent on 11 August 2023 but **s.1(2) provides for commencement on notified dates,
different dates for different provisions**. The substantive obligations therefore bind only when
notified, and s.9(1) in particular is expressly *"in such manner as may be prescribed"* — meaning
**s.9(1) cannot operate at all until rules prescribe how verifiable parental consent is obtained.**

**NOT FOUND on this pass:**
- whether the **Digital Personal Data Protection Rules** have been notified, and their date;
- the **phase-in schedule** for s.8 / s.9 obligations;
- **how verifiable parental consent must technically be obtained** under the Rules (there has been
  public discussion of a DigiLocker-based virtual token and of "due diligence" duties — I could not
  verify any of it);
- **whether the Rules carve education out of s.9(1)/(3) under s.9(4)**. This is the single highest-value
  open question in Part A for our product: if schools are a prescribed exempt class for
  education purposes, our consent architecture changes completely.

Searched: `meity.gov.in/data-protection-framework` (JS SPA, no static content extractable);
web search for "Digital Personal Data Protection Rules 2025 notified gazette educational institution
exemption" — **the session's search budget was exhausted at this exact query**, so this was never run.

**Do not code a consent flow against a guess here.** Re-run this one query first next pass.

---

## A4 · Information Technology Act, 2000 — what makes a digital certificate legally valid

**Evidence A.** Read from the gazette text hosted by the **Controller of Certifying Authorities**
(<https://www.cca.gov.in/sites/files/pdf/ACT/ACT2000.pdf>), plus the Second Schedule amending
notifications from the CCA's own gazette register
(<https://www.cca.gov.in/eSign_gazette_notification.html>).

### A4.1 The four operative sections

> **s.3 Authentication of electronic records.** **(1)** Subject to the provisions of this section
> any subscriber may authenticate an electronic record by affixing his digital signature.
> **(2)** The authentication of the electronic record shall be effected by the use of **asymmetric
> crypto system and hash function** which envelop and transform the initial electronic record into
> another electronic record. … **(3)** Any person by the use of a public key of the subscriber can
> verify the electronic record. **(4)** The private key and the public key are unique to the
> subscriber and constitute a functioning key pair.

> **s.4 Legal recognition of electronic records.** Where any law provides that information or any
> other matter shall be **in writing** or in the typewritten or printed form, then, notwithstanding
> anything contained in such law, such requirement shall be deemed to have been satisfied if such
> information or matter is— (a) rendered or made available in an **electronic form**; and (b)
> **accessible so as to be usable for a subsequent reference**.

> **s.5 Legal recognition of digital signatures.** Where any law provides that information or any
> other matter shall be authenticated by affixing the signature or **any document shall be signed
> or bear the signature of any person** then, notwithstanding anything contained in such law, such
> requirement shall be deemed to have been satisfied, if such information or matter is
> **authenticated by means of digital signature affixed in such manner as may be prescribed by the
> Central Government**.
>
> *Explanation.*—For the purposes of this section, "signed", with its grammatical variations and
> cognate expressions, shall, with reference to a person, mean **affixing of his hand written
> signature or any mark** on any document and the expression "signature" shall be construed
> accordingly.

**s.3A (electronic signature)** was inserted by the IT (Amendment) Act 2008 (Act 10 of 2009, in
force 27 Oct 2009 vide S.O. 2689). Its **sub-section (4)** is the power under which the Second
Schedule is amended — every notification below is expressly made "in exercise of the powers
conferred by sub-section (4) of section 3A". **The verbatim text of s.3A is NOT FOUND on this pass**
— the CCA's copy of the 2008 Amendment Act (`ACT2008 .pdf`) extracted as a scanned image with no
text layer. The existence and operation of s.3A(4) is nonetheless established at Evidence A by the
notifications themselves, which recite it.

**The chain that makes a digital certificate valid**, put plainly:

1. **s.4** solves "must be in writing" — an electronic TC satisfies a writing requirement provided
   it is retrievable for later reference.
2. **s.5** solves "must be signed" — but *only* if authenticated by digital signature **affixed in
   the manner prescribed by the Central Government**. A scanned image of the principal's signature
   pasted into a PDF is **not** a digital signature and does **not** get s.5's benefit. It is,
   at best, evidence like any other photocopy.
3. The prescribed manner is: **a Digital Signature Certificate issued by a licensed Certifying
   Authority** (s.35), used per the IT (Certifying Authorities) Rules 2000; **or** one of the
   electronic-signature techniques listed in the **Second Schedule**.

### A4.2 The Second Schedule — the full amendment chain

**Evidence A/B** — titles and dates from the CCA's official gazette register
(<https://www.cca.gov.in/eSign_gazette_notification.html>); the two most important read in full.

| notification | date | what it did to the Second Schedule |
|---|---|---|
| **G.S.R. 61(E)** | **27 Jan 2015** (gazetted 28 Jan 2015, No. 59) | **Inserted entry 1: "e-authentication technique using Aadhaar e-KYC services"** — i.e. Aadhaar eSign. Made under s.3A(4). Titled the *Electronic Signature or Electronic Authentication Technique and Procedure Rules, 2015*. |
| G.S.R. 539(E) | 30 Jun 2015 | Amendment re HSM secure storage |
| G.S.R. 446(E) | 27 Apr 2016 | *Electronic Signature or Electronic Authentication Technique and Procedure Rules, 2016* — linking End-entity signature rules |
| **S.O. 1119(E)** | **1 Mar 2019** | Amendment to the Second Schedule **for including other e-KYC services along with Aadhaar** — eSign is no longer Aadhaar-only |
| **S.O. 3472(E)** | **29 Sep 2020** | **Inserted entry 2:** *"e-authentication technique and procedure for creating and accessing subscriber's signature key facilitated by trusted third party"* — i.e. **remote key storage** |

**G.S.R. 61(E), Second Schedule entry 1, verbatim** (the procedure column):

> **1. e-authentication technique using Aadhaar e-KYC services** — Authentication of an electronic
> record by e-authentication Technique which shall be done by-
> **(a)** the applicable use of e-authentication, hash, and asymmetric crypto system techniques,
> **leading to issuance of Digital Signature Certificate by Certifying Authority**
> **(b)** **a trusted third party service by subscriber's key pair-generation, storing of key pairs
> on hardware security module and creation of digital signature provided that the trusted third
> party shall be offered by the certifying authority.** The trusted third party shall send
> application form and certificate signing request to the Certifying Authority for issuing a Digital
> Signature Certificate to the subscriber.
> **(c)** Issuance of Digital Signature Certificate by Certifying Authority shall be based on
> e-authentication, particulars specified in **Form C of Schedule IV of the Information Technology
> (Certifying Authorities) Rules, 2000**, digitally signed verified information from Aadhaar e-KYC
> services and **electronic consent of Digital Signature Certificate applicant**.
> **(d)** The manner and requirements for e-authentication shall be as issued by the Controller from
> time to time. **(e)** The security procedure for creating the subscriber's key pair shall be in
> accordance with the **e-authentication guidelines issued by the Controller**. …

**S.O. 3472(E), Second Schedule entry 2, verbatim** (the procedure column, abridged only where marked):

> **2. e-authentication technique and procedure for creating and accessing subscriber's signature
> key facilitated by trusted third party** — Authentication of an electronic record by
> e-authentication technique which shall be done by-
> **(a)** the applicable use of e-authentication, hash and asymmetric crypto system techniques
> leading to issuance of Digital Signature Certificate by Certifying Authority, **provided that
> Certifying Authority shall ensure the subscriber identity verification, secure storage of the keys
> by trusted third party and subscriber's sole authentication control to the signature key.**
> **(b)** Identity verification of Digital Signature Certificate applicant shall be in accordance
> with the **Identity Verification Guidelines issued by Controller** from time-to-time.
> **(c)** The requirement to operate as trusted third party shall be specified under
> e-authentication guidelines issued by the Controller.
> **(d)** a trusted third party shall — **i)** facilitate Identity verification of Digital Signature
> Certificate applicant; **ii)** **establish secure storage for subscriber to have sole control for
> creation and subsequent usage of subscriber's signature key by sole authentication of
> subscriber**; **iii)** facilitate key pair-generation, secure storage of subscriber's signature
> key and facilitate signature creation functions; **iv)** facilitate the submission of DSC
> application form and certificate signing request to the Certifying Authority…; and **v)**
> facilitate revocation of Digital Signature Certificate and **destruction of subscriber's signature
> key**.
> **(e)** Issuance of Digital Signature Certificate shall be based on verification of credentials of
> Digital Signature Certificate applicant by Certifying Authority… **(f)/(g)** manner, requirements
> and security procedure as issued by the Controller under e-authentication guidelines.

### A4.3 WHO MAY HOLD THE SIGNING KEY — the decisive answer

This is the question the brief asked, and the Act answers it directly.

> **s.42 Control of private key.** **(1)** Every subscriber shall **exercise reasonable care to
> retain control of the private key** corresponding to the public key listed in his Digital
> Signature Certificate and **take all steps to prevent its disclosure to a person not authorised to
> affix the digital signature of the subscriber**.
> **(2)** If the private key… has been compromised, then, the subscriber shall communicate the same
> without any delay to the Certifying Authority… *Explanation.*—For the removal of doubts, it is
> hereby declared that **the subscriber shall be liable till he has informed the Certifying
> Authority that the private key has been compromised.**

> **s.40** Where a Digital Signature Certificate… has been accepted by a subscriber, then, **the
> subscriber shall generate the key pair by applying the security procedure.**

> **s.41(2)** By accepting a Digital Signature Certificate the subscriber certifies to all who
> reasonably rely on it that **the subscriber holds the private key** corresponding to the public
> key listed in the Digital Signature Certificate **and is entitled to hold the same**…

**Verdict, at Evidence A:**

| question | answer |
|---|---|
| May the **principal** hold a DSC and sign certificates with it? | **Yes.** This is the ordinary Class 3 DSC route. The principal is the subscriber; s.42 places the duty of control on them personally. |
| May **ZenXii hold the principal's private key** and sign on their behalf? | **No — not as an ordinary SaaS vendor.** s.42(1) obliges the subscriber to prevent disclosure of the key "to a person not authorised to affix the digital signature of the subscriber", and s.41(2)(a) has the subscriber certify to the world that *they* hold the key. A vendor holding the key defeats both. |
| Is there **any** lawful route to server-side signing? | **Yes, but only one.** Second Schedule entry 1(b) permits a trusted third party to generate, store and sign with the key — **"provided that the trusted third party shall be offered by the certifying authority."** Entry 2 (S.O. 3472(E)) extends this to remote key storage generally, but conditions it on the CA ensuring **"subscriber's sole authentication control to the signature key"** and on the TTP establishing storage giving the subscriber **"sole control … by sole authentication of subscriber"**. |

> **The product consequence is sharp.** ZenXii **cannot** hold or operate a principal's signing key.
> The only compliant server-side model is to integrate with a **licensed CA's eSign / ESP service**,
> where the key is generated inside the ESP's HSM, released only on the principal's own
> authentication (Aadhaar OTP/biometric or other approved e-KYC per S.O. 1119(E)), used once, and
> destroyed. ZenXii is then an **ASP (Application Service Provider)** calling that ESP — never the
> key holder. Any design in which ZenXii can produce a signed certificate without a live, per-signature
> authentication act by the principal is outside the Second Schedule and gets no s.5 benefit.

Related instruments to read before building: the **IT (Certifying Authorities) Rules, 2000**
(G.S.R. 789(E), 17 Oct 2000 — <https://www.cca.gov.in/sites/files/pdf/ACT/GSR788-789E.pdf>), whose
rule 6 sets the standards the Second Schedule entries repeatedly cross-refer to and whose Schedule IV
Form C is the eSign DSC application form; and the CCA's **e-authentication guidelines** and
**Identity Verification Guidelines**, which both Second Schedule entries make binding but which are
issued administratively "from time to time" — **their current versions are NOT FOUND** (searched
`cca.gov.in/eSign.html`, which lists CCA-ASP, CCA-ESP, CCA-EAUTH and ESIGNFAQ PDFs I did not open).

### A4.4 What this means for a ZenXii-issued certificate today

At Evidence A, the ladder of legal weight is:

| what we produce | legal status |
|---|---|
| PDF with a typed name, or a pasted signature image | **Not a signature under the IT Act.** No s.5 benefit. It is an electronic record (s.4) and nothing more. |
| PDF with a QR pointing at a ZenXii verification endpoint | Same — the QR is an *evidentiary convenience*, not a signature. Useful, but do not describe it to schools as making the document "legally valid". |
| PDF/XML signed with the **principal's own DSC** | **Valid signature under s.5.** |
| Document signed via a **CA-offered eSign/ESP** with the principal authenticating per signature | **Valid signature under s.5** via Second Schedule entry 1 or 2. |

**Marketing and UI copy must not claim legal validity for the first two rows.**

---

## A5 · UDISE+ — **LARGELY NOT FOUND**

**What I could not verify on this pass, and must not be asserted by the product:**

- the **official decomposition of the 11-digit UDISE code** (state / district / block / village-cluster
  / school serial). **NOT FOUND.** I will not reproduce a blog's guess at the digit layout.
- **who assigns the code and by what process** (block/district education officer, physical
  verification). **NOT FOUND** at Evidence A/B on this pass.
- **whether state recognition is a prerequisite for a UDISE code**, or an unrecognised school can
  hold one — **NOT FOUND**. This is the question that decides whether a UDISE code is usable as an
  eligibility signal at all, and it is unanswered.
- whether any rule **mandates the UDISE code on a TC**. **NOT FOUND.**
- **PEN (Permanent Education Number)** and its relation to UDISE+/APAAR. **NOT FOUND.**

Searched: `udiseplus.gov.in` (Angular single-page app — serves no static HTML; nothing extractable
by curl or by the fetch tool). The web-search budget was exhausted before alternate routes (UDISE+
user manual, Data Capture Format, SDMS documentation, state circulars) could be tried.

**What is established, and only from this repo's own prior work** (`HOW_ELIGIBILITY_ACTUALLY_WORKS.md`,
researched 2026-09-08 from a real recognition order — **Evidence B/C, not re-verified here**): UDISE+
registration is entered by the Block Education Officer after physical verification, and sits *after*
the recognition order in the chain

```
Society/Trust registered → land, building, fire & safety → RECOGNITION ORDER → UDISE+ code → board affiliation
```

with the load-bearing distinction that **recognition (from the State) grants the right to run a
school and issue its documents, while affiliation (from CBSE/CISCE/state board) grants only the right
to present students for that board's examinations.** That distinction is independently corroborated at
Evidence A/B by §C below — CBSE's own instruction is to accept a TC from any *recognised* school, not
merely an affiliated one.

**Next pass:** find the UDISE+ Data Capture Format PDF; it carries the recognition-status and
management-type fields that answer the prerequisite question directly.

---

## A6 · APAAR ID and Academic Bank of Credits — **LARGELY NOT FOUND**

**NOT FOUND on this pass:** what APAAR's 12-digit ID is and its relation to Aadhaar/UDISE/PEN;
current scale (IDs generated) as of 2026; **whether APAAR/ABC covers SCHOOL (K-12) documents or only
higher education**; the UGC (Establishment and Operation of Academic Bank of Credits in Higher
Education) Regulations 2021 gazette citation; and **whether APAAR is mandatory or voluntary**, plus
the Ministry of Education circulars on parental consent for APAAR.

Searched: `apaar.education.gov.in` (JavaScript single-page app, no static content). The web-search
budget was exhausted before `abc.gov.in`, `ugc.gov.in` and PIB could be tried.

**One inference that is safe on the face of the name, and nothing more:** the Academic Bank of
Credits was constituted by **UGC** regulations, and UGC's remit is **higher education**. That makes
it *unlikely* that school certificates deposit into ABC. **But "unlikely" is not a finding** — the
APAAR programme has been pushed hard into schools through UDISE+, so the two may well have been
bridged. **Do not build against either assumption.**

**This matters commercially**: if APAAR/ABC does not accept school documents, then DigiLocker
(§A7) is the only national rail for a school TC, and the DigiLocker analysis is the whole story.

---

## A7 · DigiLocker vs NAD — which is the route for school certificates

### A7.1 NAD

**Evidence B**, read from <https://nad.digilocker.gov.in/>: NAD is a "24×7 online depository to
Academic institutions to store and publish their academic awards." It is an initiative of the
**Ministry of Human Resource Development** (now Ministry of Education), while **DigiLocker is a
MeitY initiative**; **Digital India Corporation** provides technical operations.

**On K-12 coverage the site is silent.** Its examples are universities, IITs and diploma programmes,
which *suggests* a post-secondary focus, but **the page does not state a limitation and I will not
assert one.** **NOT FOUND:** the current NAD operator as of 2026 (NDML / CVL / CDSL — the depository
model changed over time) and NAD's formal onboarding eligibility criteria.

### A7.2 DigiLocker as the route — corroborated from this repo's prior primary reading

The strongest evidence available to us is already in this repo. `DIGILOCKER_COMPLIANCE_PLAN.md`
(2026-09-08) was written **directly from the DigiLocker *Issuer API Specification v1.13* (May 2024,
API Setu) and the DigiLocker partner-onboarding FAQ (updated 24 March 2025)**. Treating that as a
faithful transcription of primary specs — **Evidence B, not re-verified on this pass** — it
establishes:

- **The issuer is the school, not the vendor.** *"Issuer — An entity/organization/department issuing
  e-documents to individuals in DLTS compliant format…"* Each school onboards as its own issuer;
  ZenXii would host the repository and expose the API **on the school's behalf**. `issuerId` belongs
  to the school record.
- **There is no self-service issuer registration.** FAQ Q55: *"Need to become an issuer first in
  order to push student documents."* Q61: *"You must submit a request with a detailed use case."*
  Approval is by DigiLocker. **So issuer status can only ever be *verified* by ZenXii, never granted.**
- **Q43: a partner wanting to push an *education certificate* was diverted to the NAD team.** This is
  the closest thing to a direct answer on the DigiLocker-vs-NAD question, and it points at **NAD for
  academic awards** — while §A7.1 shows NAD presenting itself as higher-education-shaped. **These two
  facts are in tension and I am recording that tension rather than resolving it.**
- **A DLTS e-document is *digitally signed XML*, not a PDF.** *"Electronic Document or E-Document — A
  digitally signed electronic document in XML format…"* Our mPDF output is the human artefact; the
  XML is the machine one. **This is where §A4.3 bites**: that XML must be signed by a lawful key
  holder, so the eSign/ESP question is on the critical path to DigiLocker, not after it.
- **Document URI is `IssuerId-DocType-DocId`**, and the spec **requires `DocId` to be random**:
  *"Using random string eliminates the possibility of 'guessing' next sequence number and accessing a
  list of documents in a sequential way. This is critical to ensure security … use at least 10n
  random space."* **This conflicts with our sequential TC numbering — and both are right.** CBSE's
  Annexure-I format requires a pre-printed **Book No. and Sl. No.** (§B1.5), which is sequential by
  nature. They are two identifiers with two jobs and must never be conflated.
- **Infrastructure:** FAQ Q9 — *"A registered Indian mobile number and a server located in India are
  mandatory for API access."* **ZenXii's server is in Ohio (us-east-2)**, placed there deliberately
  for `nam5` Firestore latency. **This is an unresolved blocker** and must be settled with DigiLocker
  before any commitment.

**Which route for school certificates: UNRESOLVED, and recorded as a conflict.** DigiLocker's own FAQ
sends education certificates to NAD; NAD presents as higher-education. **NOT FOUND:** whether an
individual school (as opposed to a board) has ever been onboarded as a DigiLocker issuer, and the
published eligibility criteria. Searched `digilocker.gov.in/about/partners` (403 to the fetch tool);
search budget exhausted.

**What IS established at Evidence A/B (§B1.4):** boards already push into DigiLocker — CBSE states
in its own 2024 notification that *"the CBSE also provides the soft copy of the certificates in the
DigiLocker of the concerned students."*

---

# PART B — EVERY BOARD

## B1 · CBSE — fully evidenced

Two primary documents were read in full:

- **Examination Bye-Laws 1995 (updated up to December 2004)** —
  <https://cbseacademic.nic.in/web_material/publication/archive/byelawsenglish.pdf> · **Evidence A**
- the **Transfer Certificate circular bundle** (2025 reminder + four annexed circulars) —
  <https://www.cbse.gov.in/cbsenew/documents/Subject_Reminder_practice_countersigning_Transfer_Certificates_03112025.pdf>
  · **Evidence A/B**

> ⚠️ **Edition caveat.** The bye-laws PDF above is the **1995 edition updated to December 2004**, which
> is what CBSE publishes at that path. **Whether a later consolidated edition exists is NOT FOUND.**
> Rule numbers below are correct *for that edition*. Because several 2014–2025 circulars visibly
> override its text (§C), treat the printed bye-law as the baseline and the circulars as controlling.

### B1.1 The chapter, and the definitions

**Chapter 3** is titled **"ADMISSION OF STUDENTS TO A SCHOOL, TRANSFER/MIGRATION OF STUDENTS"** —
exactly the chapter named in the brief. It contains rules **6 (Admission: General Conditions)**,
**7 (Admission: Specific Requirements)** and **8 (Admission Procedure)**.

Definitions (Chapter 1, rule 2):

> **(xx)** *"Transfer Certificate" means a certificate issued to a student **by the school** on his
> seeking a transfer to another institution by termination of his studies in the previous institution.*
>
> **(ix)** *"Migration Certificate" means a certificate issued **by the Central Board of Secondary
> Education** at the request of a candidate passing out from Secondary/Senior School Certificate
> Examination of the Board for seeking admission to the examinations of another Board/University.*
>
> **(xvi)** *"School" means a school affiliated to the Central Board of Secondary Education.*
> **(xii)** *"Recognised Board" means an education Board recognised by the CBSE and/or by the
> Union/State Government in India; and includes Universities recognised as such by the UGC.*

> **Design consequence, and it is a big one.** A **TC is a school document**; a **Migration Certificate
> is a BOARD document**. ZenXii can issue a TC. **ZenXii cannot issue a CBSE Migration Certificate** —
> that is CBSE's own instrument, and a school purporting to issue one is issuing a forgery. If our
> module offers a "Migration certificate" template it must be scoped to boards/states that actually
> let a school issue one, and **never** to a CBSE school.

### B1.2 Rule 6 — admission general conditions

> **6.1** A student seeking admission to any class in a 'School' will be eligible… only if he:
> **(i)** has been studying in a **School recognised by or affiliated to this Board or any other
> recognised Board** of Secondary Education in India; **(ii)** has passed qualifying or equivalent
> qualifying examination…; **(iii)** satisfies the requirements of age limits…; **(iv)** produces:
> **(a)** the **School Leaving Certificate/Transfer Certificate signed by the Head of the Institution
> last attended and countersigned, if required as provided elsewhere, in these Byelaws**;
> **(b)** document(s) in support of his having passed the qualifying examination; and **(c)** Date of
> Birth Certificate issued by the Registrar of Birth and Deaths, where-ever existing…
>
> *Explanation (a):* A person who has been studying in an institution **which is not recognised** by
> this Board or by any other recognised Board or by the State/U.T. Government of the concerned place,
> **shall not be admitted to any class of a "School" on the basis of Certificate(s) of such
> unrecognised institution**.

**Explanation (a) is the real teeth behind recognition.** Not "an unrecognised school may not issue a
TC" — but "**a CBSE school may not admit on one**". The sanction lands on the receiving side. This is
precisely the shape our eligibility ladder should mirror.

**6.2** — a student migrating from a school in a foreign country (other than a CBSE-affiliated one)
needs an **eligibility certificate from the Board**. **6.5** — no admission in Class IX and above
after **31 August** without the Chairman's/competent authority's prior permission.

### B1.3 Rule 8 — THE issuance rules, with the numbers the brief asked for

> **8. Admission Procedure**
> **(i)** Admission register in the form prescribed by the State Government concerned/**Kendriya
> Vidyalaya Sangathan/Navodaya Vidyalaya Samiti** as the case may be, shall be maintained…
> **(ii)** Successive numbers must be allotted to students on their admission and each student should
> retain this number throughout the whole of his career in the school…
> **(iii)** If a student applying for admission to a school has attended any other school, **an
> authenticated copy of the Transfer certificate in the format given in Annexure I**, from his last
> school **must be produced before his name can be entered in the Admission Register**.
> **(iv)** In no case shall a student be admitted into a class **higher than that for which he is
> entitled according to the transfer certificate**.
> **(v)** A student shall not be allowed to migrate from one "School" to another **during the session
> after his name has been sent up for the examination** of the Board. This condition may be waived
> only in special circumstances by the Chairman.
> **(vi)** **A student leaving his school at the end of a session or who is permitted to leave his
> school during the session shall on a payment of all dues, receive an authenticated copy of the
> Transfer certificate up to date. A duplicate copy may be issued if the head of the institution is
> satisfied that the original is lost but it shall always be so marked.**
> **(vii)** **In case a student from an institution not affiliated to the Board seeks admission in a
> school affiliated to the Board, such a student shall produce a transfer certificate duly
> countersigned by an authority as indicated in the format given in Annexure-I.**
> **(viii)** If the statement made by the parent or guardian… is found to contain any **wilful
> misrepresentation of facts** regarding the student's career, the head of the institution may punish
> him/her as per the Education Act of the State/UT or **KVS/NVS rules**, as the case may be, and
> **report the matter to the Board**.

**The brief's three requested rule numbers, confirmed:**

| what | rule | note |
|---|---|---|
| **Issue of TC on leaving** | **r.8(vi)** | and it *does* say "on a payment of all dues" — see the conflict at §B1.6. **Amended 2012 — see §B1.3a; the text above is the superseded version.** |
| **Duplicate TC and its marking** | **r.8(vi), second sentence** | head must be satisfied the original is lost; **"it shall always be so marked"** — the duplicate must be marked as such. No Board permission is required by this rule. **This sentence survived the 2012 amendment verbatim.** |
| **Countersignature** | **r.8(vii)** | **the brief's guess of "r.8(vii)" is CORRECT.** But see §C — it is superseded in practice. |

### B1.3a · r.8(vi) was AMENDED in 2012 — the published bye-laws carry the OLD text

**Evidence A.** CBSE circular **COORD/AS/2011, dated 28.06.2012**
(<https://www.cbse.gov.in/cbsenew/bylawspdf/Amendment_Addition_Exam_byelaws_280602012.pdf>).
Resolved by the Examination Committee on 30.5.2012, approved by the Governing Body on 04.6.2012.

> **II. ISSUANCE OF TRANSFER CERTIFICATE ONLY ON THE GROUNDS AS STATED IN RULE 8(vi) OF THE
> EXAMINATION BYELAWS**
>
> It has come to the notice of the Board that **some schools deliberately issue Transfer
> Certificates to students especially in cases IX and XI to show high achievement record in
> classes X and XII.** Heads of the institutions are requested to **desist from this practice and
> no student should be forced to leave the school especially in classes IX to XII** except on the
> grounds as stated in the amended rule 8(vi)…

**Amended r.8(vi), verbatim** (the added words in **bold**):

> **8(vi)** a student leaving his school at the end of a session or who is permitted to leave his
> school during the session **on account of migration from one city/State to another on the
> transfer of the parent(s) or shifting of their families from one place to another or parents'
> request, especially in classes IX/X/XI/XII, as the case may be,** shall on payment of all dues,
> receive an authenticated copy of the Transfer Certificate up to date. A Duplicate copy may be
> issued if the head of the institution is satisfied that the original is lost but **it shall
> always be so marked.**

**Why this matters, and it cuts against the obvious reading:**

- The amendment is **not a restriction on students** — it is a **restriction on schools**. Its
  stated purpose is to stop schools **pushing weak students out** before the Class X/XII board
  examinations to flatter their results. "No student should be forced to leave."
- **It therefore does not give a school a new ground to refuse a TC to a family that wants one.**
  *"or parents' request"* is one of the enumerated grounds, so a parent asking is sufficient.
- The **duplicate-marking sentence is unchanged**, so the corpus quote at §F.1 remains correct.
- **The bye-laws PDF CBSE publishes still shows the pre-2012 text.** Anyone reading only that PDF
  — as the corpus did — is reading a superseded rule. This is the same failure mode as the
  countersignature question, and it is the second instance found on one pass.

> **Product consequence.** If our TC form ever collects a "reason for leaving" (Annexure-I field 21
> requires one), the CBSE-tenant option list should reflect the r.8(vi) grounds — parent transfer,
> family relocation, parents' request, end of session — but must **not** be used to *block* issuance,
> because the rule exists to protect students from schools, not to arm schools against students.

Also relevant: **r.7.3(c)** (Class X) and **r.7.5(ii)** (Class XII) each require, for a student coming
from a *non-CBSE* recognised board, the mark sheet **and "the Transfer Certificate duly countersigned
by the Educational Authorities of the Board concerned"**, with **post facto approval of the Board
within one month** of admission.

### B1.4 Migration Certificate — CBSE has abolished the hard copy

**Evidence A** — CBSE **Notification No. CE/CBSE/2024/(F.No. 163685), 4 September 2024**, signed by
**Dr. Sanyam Bhardwaj, Controller of Examinations**
(<https://www.cbse.gov.in/cbsenew/documents/Doing_away_Migration_Certificate_X_XII_DADS_04092024.pdf>):

> **Subject: Doing away with the hard copy of the Migration Certificate for both classes X & XII**
>
> The CBSE is issuing the following documents to students who have passed in Class X/XII
> Examinations: 1. Marks sheet-cum-Passing Certificate 2. Migration Certificate to Class X students
> who wish to obtain the Migration Certificate 3. Migration Certificate to all the students of Class XII
>
> Besides providing the hard copy of the Migration Certificate to the students, **the CBSE also
> provides the soft copy of the certificates in the DigiLocker of the concerned students.**
>
> The University Grants Commission has already issued a letter to all institutions of higher learning
> that **digital copies of the certificates issued by the Board will be accepted** for admission to
> institutions of higher learning. CBSE has therefore decided that **from the Examinations - 2025
> onwards, the hard copy of the Migration Certificate will not be issued** to the students of Class
> XII and the students of Class X. **However, a digital copy of the Migration Certificate will be
> used by the students** for admission to other educational institutions. The fee charged by the CBSE
> for issuing the Migration Certificate alongwith the Examination fee in the LOC will also not be
> charged by the Board.
>
> During the transition period, in case of any requirement for the hard copy… students will be allowed
> to make the requests to the CBSE only at link: <https://cbseit.in/cbse/web/dads/home.aspx> i.e.
> **Duplicate Academic Document System** on CBSE website…
>
> The above decisions have been approved by the Examination Committee meeting held on **May 24th,
> 2024** and duly ratified in the Governing Body meeting held on **June 24th, 2024**.

**This supersedes the fee entry in bye-law Annexure-II** ("Fee for Migration Certificate or a duplicate
copy thereof — Rs. 50.00") and modifies rule 66. Bye-law **r.66** reads: *"(i) A candidate who has
appeared in an examination of the Board and has passed the examination may obtain a Migration
Certificate on payment of the prescribed fee. (ii) A candidate placed in Compartment may also be
issued a Migration Certificate indicating his/her status as such."*

Also **r.27**: private/teacher candidates who passed Secondary from another recognised Board/University
**must submit a Migration Certificate from that Board/University** with the examination form, failing
which (if not received 15 days before the exam) **candidature is cancelled**.

> **Product consequence.** For a CBSE school the "Migration certificate" is (a) not the school's to
> issue, and (b) now a **digital-only, DigiLocker-delivered** Board document. Our template library must
> not offer it for CBSE tenants.

### B1.5 The prescribed TC format — Annexure-I

**Evidence A.** Bye-Laws **Annexure-I, "FORMAT OF TRANSFER CERTIFICATE"**. Header fields:
**Book No. · Sl. No. · Admission No.** Then 22 numbered fields:

1. Name of Pupil · 2. Father's/Guardian's Name · 3. Nationality · 4. Whether the candidate belongs to
Scheduled Caste or Scheduled Tribe · 5. Date of first admission in the School with class · 6. Date of
birth (in Christian Era) according to Admission Register, **in figures and in words** · 7. Class in
which the pupil last studied, **in figures and in words** · 8. School/Board Annual examination last
taken with result · 9. Whether failed, if so once/twice in the same class · 10. Subjects Studied (five
slots) · 11. Whether qualified for promotion to the higher class; if so, to which class, **in figures
and in words** · 12. Month upto which the school dues paid · 13. Any fee concession availed of; if so,
the nature of such concession · 14. Total No. of working days · 15. Total No. of working days present ·
16. Whether NCC Cadet/Boy Scout/Girl Guide · 17. Games played or extra-curricular activities…
(mention achievement level therein) · 18. General conduct · 19. **Date of application for certificate** ·
20. **Date of issue of certificate** · 21. Reasons for leaving the school · 22. Any other remarks

Signature block: **Signature of class teacher · Checked by (state full name and designation) ·
Principal · SEAL**.

**The Annexure-I footnote** (rule amended in the Examination Committee's meeting of 7.5.1999, approved
by the Governing Body 13.5.1999) — **this is where the countersignature obligation actually lives**:

> \* Transfer certificate should be issued **only under the signatures of the regular Principal/Vice
> Principal** and it **should be counter-signed by an officer not below the rank of District Inspector
> of Schools/Deputy Director of Education/Education Officer of the Education Deptt. of the State/Union
> Territory concerned. In case of a student migrating from one CBSE affiliated school to another CBSE
> affiliated school the transfer certificate of a previous school of such a student may be
> countersigned by the Regional Officer of the Board or the Asstt. Commissioner of the KVS or the
> Deputy Director, Navodaya Vidyalaya Samiti in India or an officer of the Board at the Head Quarters**
> and by the First Secretary/Attache/Cultural Attache or an equivalent officer of the Embassy/High
> Commission of India in the concerned country in respect of students studying in an affiliated school
> of the CBSE situated outside the country **and the student shall not be admitted to a school without
> such a counter signature.**

**That last clause — "the student shall not be admitted to a school without such a counter signature"
— is the requirement that CBSE has since abolished.** See §C.

### B1.6 CONFLICT — "on payment of all dues" (r.8(vi)) vs RTE s.5(3)

**Recorded, not resolved.**

- **Bye-law r.8(vi)** conditions the TC on payment of all dues: *"shall **on a payment of all dues**,
  receive an authenticated copy of the Transfer certificate"*.
- **RTE s.5(3)** requires the head-teacher to *"immediately issue the transfer certificate"* with no
  condition, for the **elementary stage**.

**Resolution on the law is not genuinely in doubt** — a statute overrides a board's subordinate
bye-law, and RTE s.5 covers Classes I–VIII. **But the bye-law is not thereby void for Classes IX–XII**,
where RTE s.5 does not reach and where r.8(vi) still speaks. Add the High Court decisions at §A1.5
(unread) which appear to refuse a dues gate more broadly.

> **What the product should do:** never block a TC below Class IX; for Classes IX–XII surface dues as
> a **warning to the school, not a hard block**, and do not tell the school it is legally required to
> withhold. We do not have the case law read well enough to assert more.

---

## C · THE COUNTERSIGNATURE QUESTION — VERDICT

**This was the brief's single most important question. It is now settled at Evidence A.**

### C.1 Verdict

> **The countersignature requirement is SUPERSEDED — comprehensively, repeatedly, and by name.**
>
> The requirement in **Examination Bye-Laws r.8(vii) and the Annexure-I footnote** (*"the student
> shall not be admitted to a school without such a counter signature"*) is **no longer operative**.
> CBSE has abolished it by a chain of five circulars spanning 2014–2025, the most recent of which was
> issued **31 October 2025** and exists precisely because schools kept complying with the dead rule.
>
> **The correct current rule: a Transfer Certificate signed and sealed by the Principal/Head of any
> recognised school is to be accepted as it stands. No countersignature by any CBSE Regional Officer
> or Head Office is required or should be sought.**

The bye-law text was never formally rewritten — which is exactly why the confusion persists, and why
schools reading the published bye-laws still send TCs to Regional Offices. **The circulars control.**

### C.2 The evidence chain, in order

All five documents are bundled in one CBSE-published PDF
(<https://www.cbse.gov.in/cbsenew/documents/Subject_Reminder_practice_countersigning_Transfer_Certificates_03112025.pdf>).
Read in full. **Evidence A** for text I read directly off the CBSE-hosted document.

| # | circular no. | date | what it did |
|---|---|---|---|
| 1 | **COORD/EC-30.7/2014** | **26.11.2014** | The origin. Examination Committee resolved 30.07.2014, Governing Body approved 06.08.2014, **that countersignature of TCs from one CBSE school to another be done away with**. Sets out 10 steps. Signed **K.K. Choudhury, Controller of Examinations**. |
| 2 | **CBSE/T.C Uploading/2018** | **01.10.2018** | Mandatory uploading of scanned TCs on the school website; TC format "strictly as per the Proforma already provided"; receiving schools must verify the TC from the issuing school's website. Signed **Dr. Sanyam Bhardwaj, COE**. |
| 3 | **CBSE/PRU/TC/2019/1692** | **14.10.2019** | Reminder — practice "has been done away with", yet HQ and ROs still receive TCs by post and in person. Signed **Anurag Tripathi, Secretary, CBSE**. |
| 4 | **COORD/PR UNIT/2020** | **04.02.2020** | **The operative SOP.** Extends the abolition beyond TCs and sets out what a school must do instead. Signed **Dr. Sanyam Bhardwaj, COE**. |
| 5 | **CBSE/Coord/Countersignature/2025/** | **31.10.2025** | The current reminder. Signed **Dr. Sanyam Bhardwaj, Controller of Examinations**. |

**The 31.10.2025 circular, verbatim in its operative parts:**

> **Subject: Reminder of the practice of countersigning of the Transfer Certificates and mandatory
> uploading of scanned copy of T.C.- reg.**
>
> This is a reminder of the earlier issued CBSE directive through **circular No. COORD/PR UNIT/2020
> dated 04.02.2020**, vide which **CBSE had discontinued the practice of countersignature of Transfer
> Certificates issued to the students and experience certificates issued to the teachers by all CBSE
> affiliated schools.**
>
> As already informed to all, the practice of countersignature of the Transfer Certificates from one
> CBSE affiliated school to another has been done away with vide earlier Circular no. Coord./EC-30.7/2014
> dated 26.11.2014, Circular No. CBSE/T.C uploading/2016 dated 01.10.2018, Circular no CBSE/PRU/TC/2019/1692
> dt. 14.10.2019 and circular No. COORD/PR UNIT/2020 dated 04.02.2020. (All circulars attached for ready
> reference)
>
> However, CBSE Headquarters and Regional Offices still receive requests for countersignature of Transfer
> Certificates. Non-compliance of CBSE Guidelines by schools causes huge inconvenience to stakeholders and
> is also a hindrance to facilitate all in this age of digitization. Hence, **all schools are once again
> reminded that there is no need of countersignature of any transfer certificate. In case of any
> requirement the S.O.P as mentioned in circular dated 04.02.2020 is to be followed.**
>
> It is also re-iterated that **all such certificates are to be uploaded on the individual school's
> website.**

**The 04.02.2020 SOP, verbatim** — this is what ZenXii should actually implement, because it tells us
what must appear **on the document**:

> **Sub: Certificates based on school information to be issued by Principals/Head of schools only —
> no countersign required**
>
> It has been noticed that few schools affiliated to CBSE issue various certificates based on
> information which is available only in the school and such certificates are being sent to CBSE
> offices for countersigning. **It is clear that since CBSE does not hold the original information,
> countersigning should also not be done by CBSE.**
>
> In this regard, CBSE has discontinued countersigning the Transfer Certificates issued by the schools
> affiliated to their students joining another school…
>
> Similarly, it has also been decided that the following Certificates shall be issued and countersigned
> (if required) by schools themselves: 1. **Experience Certificates** in respect of teachers working in
> CBSE affiliated schools 2. **Certificates issued by the schools to the students for the purpose of
> employment or obtaining any type of government concessions viz. student tours or Railway journeys etc.**
>
> **I. Steps to be adhered to by the Principal/Head of School while issuing above certificates:**
> **(a)** The Certificates should be issued **on the official school letterhead only** and **signed by
> Principal or Head of school**.
> **(b)** School letterhead should invariably contain the following information below the name and
> address of the school: **"AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION / AFFILIATION NO.----------"**
> **(c)** **School name and address should be as available in the CBSE records.**
> **(d)** **Only if required**, the issued certificate **can be countersigned by the Manager/Secretary/Member
> of the School Managing Committee.**
> **(e)** In case the certificate is issued on a prescribed format, **the stamp and seal of the school should
> contain the information at (b) above.**
> **(f)** Copy may be directly sent to the authority concerned by the school.
> **(g)** **A record of such cases shall be maintained by the school.**
>
> **II. Steps to be adhered to by any Organization/Authority accepting the above certificates:**
> **(a)** To ensure that the Certificate issued is **on the official school letterhead only**.
> **(b)** School letterhead contains "AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION / AFFILIATION NO.----------"
> **(c)** School name and address be **verified from the link
> <http://cbseaff.nic.in/cbse_aff/schdir_Report/userview.aspx>**
> **(d)** In case the certificate is issued on a prescribed format, it should be ensured that the stamp and
> seal of the school contains the information at (b) above and should be **signed by Principal/Head of school**.
>
> **III** Affiliated schools of CBSE may also note that **any other certificate issued by school based on
> information available in school, shall henceforth be issued by Principal/Head of School and no
> countersignature by CBSE shall be required.**
>
> **IV** It is also reiterated that **all such certificates shall be uploaded on school website also.**

### C.3 What the 26.11.2014 circular required instead — and the residual countersignatures

The 2014 circular's ten steps (verbatim, abridged) are the substantive replacement regime:

> **1)** all the schools affiliated to the Board shall **upload scanned copy of the Transfer
> Certificate(s) issued by them on their official school website**;
> **2)** all schools affiliated to the Board shall **issue the Transfer Certificate as per the format
> given in Annexure 1 of the Examination Byelaws**;
> **3)** the schools shall also mention in the Transfer Certificate **AFFILIATED TO THE CENTRAL BOARD OF
> SECONDARY EDUCATION below the name and address of the school alongwith the Affiliation Code No.**;
> **4)** in case of transfer from one CBSE affiliated school to another, **affiliation status of the
> school be verified from the Board's website** cbse.nic.in > e-affiliation > list of affiliated schools
> as also from the school's website and **the affiliation number be recorded on the Transfer Certificate**;
> **5)** the head of the school shall ensure that the name of the school from where the Transfer
> Certificate has been issued appears on the Board's website as an affiliated school and **does not appear
> in the List of Disaffiliated Schools**;
> **6)** the Transfer Certificate **shall be countersigned by the Manager/Secretary/Member of the School
> Managing Committee and the head of the school while forwarding the same to the Board in cases of direct
> admission and seeking approval from the Board**;
> **7)** while countersigning it shall be mentioned **"Verified from (cbse.nic.in/source from where
> verified i.e. website etc.) on (date of accessing the source of verification) that the issuing school's
> name appears in the list of affiliated schools and does not appear in the list of disaffiliated schools
> and Countersigned"**;
> **8)** **in case of transfer from a school recognized by/affiliated to any other recognized Board, the
> genuineness of the Transfer Certificate be got ascertained and countersigned from the authority
> controlling the school, as per past practice**;
> **9)** in case of doubt/apprehension about the Transfer Certificate, the matter be referred to the Board
> for clarification;
> **10)** school managements shall make all out efforts to admit students having valid Transfer Certificate
> from a school recognized by/affiliated to recognized Board(s).

**Two residual countersignatures survive in the 2014 text, and they are NOT the abolished one:**

- **step 6 — an internal countersignature** by the Manager/Secretary/Member of the School Managing
  Committee, **only when forwarding to the Board for direct admission / Board approval**. Reaffirmed
  as optional-on-demand by the 2020 SOP step I(d): *"Only if required, the issued certificate can be
  countersigned by the Manager/Secretary/Member of the School Managing Committee."* **This is a
  school-internal signature, not a Board countersignature**, and it is permissive, not mandatory.
- **step 7 — a verification endorsement**, which is a *statement by the admitting school* that it
  checked the issuing school's affiliation status. Not a countersignature by an external authority.

### C.4 CONFLICT — other-board TCs

**Recorded explicitly, as the brief requires.**

- **2014 circular step 8** preserved countersignature *"in case of transfer from a school recognized
  by/affiliated to any other recognized Board"* — the genuineness *"be got ascertained and countersigned
  from the authority controlling the school, as per past practice."* Bye-law **r.7.3(c)/7.5(ii)** say the
  same for Class X/XII admissions from another board.
- **The 2020 and 2025 circulars use unrestricted language**: *"no countersignature of any transfer
  certificate"* (2025); *"no countersignature by CBSE shall be required"* for any school-information
  certificate (2020 para III).

**These two are in tension and I am not resolving it by preference.** A defensible reading is that the
2020/2025 language abolishes countersignature **by CBSE** (which is all CBSE can abolish — it cannot
waive another board's authority's practice), leaving step 8's *other-board* countersignature by *that
board's controlling authority* formally alive but widely disused. **That reading is my inference, not a
finding**, and it is flagged as such.

> **What the product must therefore NOT do:** hard-block or hard-require a countersignature field in
> any direction. Treat countersignature as an **optional, board- and route-dependent annotation**,
> defaulting to absent, never to required.

### C.5 What ZenXii must actually implement for a CBSE school

Distilled from the 2014 + 2020 + 2025 circulars, all Evidence A:

| # | requirement | source |
|---|---|---|
| 1 | TC in the **Annexure-I format** (Book No./Sl. No./Admission No. + 22 fields + class teacher/checked-by/Principal/SEAL) | 2014 step 2; bye-law r.8(iii) |
| 2 | **"AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION"** and **"AFFILIATION NO. ____"** printed **below the name and address of the school** | 2014 step 3; 2020 I(b) |
| 3 | **School name and address exactly as in CBSE records** | 2020 I(c) |
| 4 | Signed by **Principal/Head of school** (regular Principal/Vice-Principal per Annexure-I footnote); **school stamp and seal must itself carry the affiliation information** where a prescribed format is used | 2020 I(a), I(e), II(d) |
| 5 | **Scanned copy uploaded to the school's own official website** — mandatory, restated in every circular including 31.10.2025 | 2014 step 1; 2018; 2020 IV; 2025 |
| 6 | **A record of issued certificates maintained by the school** | 2020 I(g) |
| 7 | On **admitting**, verify the issuing school's affiliation at <http://cbseaff.nic.in/cbse_aff/schdir_Report/userview.aspx> and that it is not disaffiliated; **record the affiliation number on the TC** | 2014 steps 4, 5; 2020 II(c) |
| 8 | **Do NOT** send TCs to CBSE HQ or Regional Offices for countersignature | 2019, 2020, 2025 |

> **Item 5 is a genuine product gap and a compliance obligation, not a nice-to-have.** CBSE has now
> restated "upload to the school website" four times in eleven years. Our module produces a PDF; it does
> not publish it anywhere a third party can verify it. The §A7.2 QR/verification-endpoint work would
> satisfy the *spirit* of this, but the letter says **the individual school's website**.
>
> **Item 2 is a live defect.** `HOW_ELIGIBILITY_ACTUALLY_WORKS.md` and `DIGILOCKER_COMPLIANCE_PLAN.md`
> record that `tc_print.php` prints *"Affiliated to C.B.S.E, New Delhi"* for **every** tenant regardless
> of board or state, **and never prints the affiliation number**. Against the 2020 SOP that is both a
> **false statutory claim** for non-CBSE schools and a **missing mandatory field** for CBSE ones.

---

## B2 · CISCE (ICSE / ISC)

Researched from primary regulatory text. **Evidence A** — the ICSE Regulations, the ISC Regulations
and the Guidelines for Affiliation were read directly (reached via the Wayback Machine, as `cisce.org`
returns Cloudflare 403 to both curl and the fetch tool). *Exact URLs and verbatim quotes are being
appended by the strand that read them; the findings below are its report.*

**The headline is a negative finding, and it is a valuable one.**

- **There is no CISCE document titled "Rules of the Council."** The governing texts are the **ICSE
  Regulations**, the **ISC Regulations**, and the **Guidelines for Affiliation**. Anything citing
  "CISCE Rules of the Council" for a TC rule is citing a document that does not exist.
- **CISCE prescribes NO Transfer Certificate format.** — **NOT FOUND**, and this is a clean negative,
  searched for directly in all three governing texts.
- **CISCE imposes NO countersignature requirement on a TC.** — **NOT FOUND**, same basis.
- **"Transfer Certificate" appears exactly ONCE in each Regulations document — and as a
  *prohibition*, not a format:** *"Transfer Certificate should not be issued with 'Promoted to Class
  X' if the student has not met the required promotion criteria."* That is the entirety of CISCE's
  regulation of the TC.
- **CISCE DOES issue a Migration Certificate** — **ICSE Regulations Ch. II §E.4** and **ISC
  Regulations §3** — alongside the Statement of Marks and the Pass Certificate.
- **Cross-board transfer does not run on a TC.** CISCE uses a **Clearance Certificate** and an
  **online eligibility check** instead.

> **Product consequence.** For a CISCE tenant the module must **not** impose CBSE's Annexure-I
> 22-field format, and must **not** offer a countersignature field — neither exists in CISCE's rules.
> The one enforceable CISCE constraint is a **content prohibition**: do not print *"Promoted to Class
> X"* on a TC for a student who did not meet the promotion criteria. That is a validation rule on the
> promotion field, not a layout rule.
>
> **As with CBSE, the Migration Certificate is a BOARD document, not a school document.** Do not offer
> that template to a CISCE school either.

## B3 · NIOS

**Evidence A** — the **NIOS Bye-laws Governing Examinations and Certification (Revised & Amended upto
2021)** and the NIOS Prospectus were read directly. *URLs and verbatim quotes appended by the reading
strand.*

**The answer to the brief's question — "how does a TC work when there is no conventional school?" — is
that it does not, and NIOS was built that way deliberately.**

- **The NIOS Bye-laws contain ZERO occurrences of "Transfer Certificate."** NIOS **does not issue a
  TC at all.**
- **NIOS issues a Migration Certificate** — **Bye-laws §14.3** — not a Transfer Certificate.
- **NIOS ACCEPTS a TC at admission, but only as one of four alternative proofs of date of birth** —
  not as a mandatory academic-transfer instrument. And proof of Class VIII study **may be a "Self
  Certificate."** *That is the open-admission mechanism*: a candidate with no school, no records and
  no former head-teacher willing to sign anything can still enrol.
- **Transfer of Credit (TOC)** — **Bye-laws §3.9.2**, **Prospectus §2.6** — is the mechanism that does
  the work a conventional transfer would do: subjects already passed at another recognised board are
  carried across, rather than a certificate being transferred.
- **Cross-strand finding of direct relevance to §B4:** NIOS's own recognised-boards appendix lists
  **code 9808 "IGCSE Programme from University of Cambridge"** and **code 9809 "International
  Baccalaureate – Asia Pacific"** as boards it accepts credit transfer from. This is a **central
  Indian body formally recognising both foreign awarding bodies** for academic-credit purposes.

> **Product consequence.** A NIOS tenant needs **no TC template at all**, and offering one would
> invite a school to issue a document its board does not recognise. What a NIOS learner needs is the
> **TOC** path and the Migration Certificate — and the latter is NIOS's to issue, not the study
> centre's.

## B4 · IB and Cambridge / CAIE

**Still pending.** The reading strand has delegated this sub-strand and is holding for it; findings
will be appended. **Nothing about IB or CAIE is asserted here.**

Two things are nonetheless already established and can be relied on:

1. **NIOS recognises both** for Transfer of Credit — IGCSE/Cambridge as code **9808**, IB Asia Pacific
   as code **9809** (§B3, Evidence A). That is an Indian statutory body treating both as recognised
   boards.
2. **The load-bearing question remains open**: whether an IB/IGCSE school in India **also** needs
   state recognition under RTE s.18 to operate and to issue a TC. On the face of s.18 the answer must
   be yes — it applies to *any* school other than a government or local-authority one and makes no
   exception for foreign curricula (§A1.3) — **but that is reasoning from the statute, not a verified
   finding about how states actually treat international schools.** It is **NOT ASSERTED** until the
   sub-strand reports.

**Also still NOT FOUND:** whether IB or CAIE prescribe any TC format (expected to be *no* — they award
qualifications, not school-leaving documents — but a clear negative must be *verified*, not assumed);
and the AIU equivalence route for IB Diploma and Cambridge qualifications.

---

## B5 · Kendriya Vidyalaya Sangathan (KVS) — partially found

**What IS established, at Evidence A, from the CBSE documents themselves:**

- **KV schools are CBSE-affiliated and sit inside CBSE's TC regime.** Every circular in the §C chain is
  formally copied to *"The Commissioner, Kendriya Vidyalaya Sangathan, 18-Institutional Area, Shaheed
  Jeet Singh Marg, New Delhi-110016"* — in the 2014, 2019, 2020 and 2025 circulars alike (the 2025 one
  addresses the Commissioner at `commissioner-kvs@gov.in`). **So CBSE Examination Bye-Laws Chapter 3 and
  the countersignature abolition apply to KVs.**
- **The bye-laws name KVS explicitly** in three places: **r.8(i)** — the admission register is in the form
  prescribed by the State Government **/ Kendriya Vidyalaya Sangathan / Navodaya Vidyalaya Samiti** as the
  case may be; **r.8(viii)** — punishment for wilful misrepresentation is per the State/UT Education Act
  **or KVS/NVS rules**; and the **Annexure-I footnote**, which named the **Asstt. Commissioner of the KVS**
  as a permitted countersigning authority (now abolished along with the rest — §C).

> **Product consequence: KVS needs no separate TC rule set.** It inherits CBSE's, with the one caveat that
> its **admission register form** is KVS-prescribed rather than state-prescribed.

**NOT FOUND:** the KVS Education Code article/para numbers governing TC issue; whether KVS prescribes its
own TC proforma over and above Annexure-I; the KVS rule on **priority admission for children of
transferable central-government employees against a TC**; and any KVS rule on withholding a TC for dues or
on duplicate TCs. Searched: `kvsangathan.nic.in/en/education-code` — reachable, and I downloaded **all seven
PDFs** it links (KVS Training Policy, space-requirement annexure, website input material, CPD Guidelines
2025, an office-bearers list, the 2026 holiday list, and a 76-page scanned compilation). **None of the seven
contains the phrase "transfer certificate"** — the Education Code proper is not published at that path.
`kvsangathan.nic.in/en/admission-guidelines` then began refusing connections (HTTP 000, likely rate-limiting).
Note also that KVS's `/en/transfer-policy/` page concerns **staff transfers**, not student TCs — an easy trap.

## B6 · Navodaya Vidyalaya Samiti (NVS) / Jawahar Navodaya Vidyalayas

**What IS established, at Evidence A, from the CBSE documents:** identical position to KVS. NVS is on the
distribution list of the 2014, 2019, 2020 and 2025 circulars (*"The Director, Navodaya Vidyalaya Samiti,
B-15, Institutional Area, Sector 62, Noida-201 307"*), and is named in bye-law **r.8(i)**, **r.8(viii)** and
the **Annexure-I footnote** (*"the Deputy Director, Navodaya Vidyalaya Samiti in India"* as a former
countersigning authority). **JNVs are CBSE-affiliated and follow CBSE Chapter 3.**

**NOT FOUND:** NVS's own TC rules and para numbers; whether NVS prescribes a TC proforma; and the
governing document for the **JNV Class-IX inter-region migration scheme** (a student-relocation scheme
distinct from any "migration certificate" — the distinction matters and should not be conflated in our
template naming). Searched `navodaya.gov.in/nvs/en/Admission-JNVST/JNV-Migration/` — returned HTTP 200 but
no extractable static content; search budget exhausted.

## B7 · Sainik Schools and Rashtriya Military Schools

**Partially established, at Evidence A.** The CBSE 04.02.2020 SOP is copied to the military education
chain — *"The Additional Director General of Army Education, A-Wing, Sena Bhawan"*, *"The Secretary AWES,
Integrated Headquarters of MoD (Army)… Delhi Cantt"*, *"The Director General, Integrated HQ of Ministry of
Defence (Navy)"*, and (in the 2014/2019 circulars) *"The Deputy Director of Education, Border Security
Force"*. **This places Army/AWES/Navy-run schools inside CBSE's circulation and therefore its TC regime.**

**NOT FOUND:** Sainik Schools Society's own rules on TC issuance and their rule numbers; the position for the
**New Sainik Schools (PPP)** scheme; and Rashtriya Military Schools' own rules. Note that AWES (Army Public
Schools) and the Sainik Schools Society are **different bodies** — the circulars evidence AWES, not the
Sainik Schools Society, and I am not extending the inference. Search budget exhausted before
`sainikschoolsociety.in` could be reached.

## B8 · Central Tibetan Schools Administration (CTSA)

**Partially established, at Evidence A.** CTSA is on the distribution list of **every** circular in the §C
chain — 2014, 2019, 2020 and 2025 (*"The Director/Secretary, Central Tibetan School Administration, ESS Plaza,
Community Centre, Sector 3, Rohini, Delhi-85"*). Its presence on the **31.10.2025** list is meaningful
evidence that **CTSA still existed as an addressable body in late 2025** and that its schools are treated as
CBSE-affiliated for TC purposes.

**NOT FOUND:** CTSA's current operating status as of 2026 (a substantial number of CTSA schools were
transferred to the Tibetan Sambhota Schools / Department of Education, CTA — **I could not verify the extent
or the date**); and any CTSA-specific TC rule. **Do not assert the transfer as fact.**

---

# D · CONFLICTS REGISTER

Recorded rather than resolved, per the brief.

| # | conflict | status |
|---|---|---|
| **D1** | **CBSE r.8(vi) "on payment of all dues" vs RTE s.5(3) "shall immediately issue".** | Statute prevails for **Classes I–VIII**. Above Class VIII the bye-law still speaks, and the High Court decisions at §A1.5 are **unread**. **Never hard-block a TC below Class IX.** §B1.6 |
| **D2** | **Other-board TCs: 2014 circular step 8 (countersignature preserved) + bye-law r.7.3(c)/7.5(ii) vs 2020/2025 blanket "no countersignature of any transfer certificate".** | **Unresolved.** My reading — CBSE abolished countersignature *by CBSE*, which is all it can abolish — is an inference, not a finding. §C.4 |
| **D3** | **DigiLocker FAQ Q43 routes education certificates to NAD, but NAD presents as higher-education-shaped.** | **Unresolved.** Neither route confirmed for a school TC. §A7 |
| **D4** | **DigiLocker requires a RANDOM `DocId`; CBSE Annexure-I requires a sequential Book No./Sl. No.** | **Both are right — they are two identifiers with two jobs.** Keep the sequential statutory serial; add a separate random `DocId`. §A7.2 |
| **D5** | **DigiLocker requires a server located in India; ZenXii's server is in Ohio.** | **Unresolved blocker** for DigiLocker integration. §A7.2 |
| **D6** | **Bye-laws edition.** The published Examination Bye-Laws are the **1995 edition updated to Dec 2004**, yet circulars from 2014–2025 visibly override them and were never folded in. | Treat the printed bye-law as baseline, circulars as controlling. Whether a newer consolidated edition exists is **NOT FOUND**. §B1 |

---

# E · "NOT FOUND" LIST

Everything below was looked for and **not established**. None of it may be asserted by the product.

### Part A
1. **NEP 2020** — every paragraph reference. Could not retrieve the PDF (four URL attempts, all 404/403). §A2
2. **DPDP commencement status** — whether the Act's substantive provisions are in force as of 2026-09-08. §A3.6
3. **DPDP Rules** — whether notified, their date, and the phase-in schedule. §A3.6
4. **How verifiable parental consent must be obtained** under the Rules. §A3.6
5. **Whether education/schools are a prescribed exempt class under DPDP s.9(4).** ← **highest-value open question in Part A.** §A3.6
6. Whether any **s.17(2)(a) notification** exempts government schools. §A3.5
7. **IT Act s.3A verbatim text** — CCA's copy of the 2008 Amendment Act is a scanned image with no text layer. §A4.1
8. **CCA e-authentication guidelines and Identity Verification Guidelines** — current versions. Both are made binding by the Second Schedule entries. §A4.3
9. **UDISE code 11-digit structure** — official decomposition. §A5
10. **Who assigns a UDISE code and by what process.** §A5
11. **Whether recognition is a prerequisite for a UDISE code.** ← decides whether UDISE is usable as an eligibility signal. §A5
12. Whether any rule **mandates the UDISE code on a TC**. §A5
13. **PEN (Permanent Education Number)** — definition and relation to UDISE+/APAAR. §A5
14. **APAAR** — ID structure, Aadhaar relationship, 2026 scale, mandatory-vs-voluntary status, consent circulars. §A6
15. **Whether APAAR/ABC covers school (K-12) documents at all.** §A6
16. **UGC ABC Regulations 2021** — gazette citation. §A6
17. **NAD's current operator** (NDML/CVL/CDSL) as of 2026, and its formal onboarding eligibility. §A7.1
18. **Whether an individual school can become a DigiLocker issuer**, and the published eligibility criteria. §A7.2
19. **RTE s.2(n)(iii)/(iv)** sub-clause text — needed to understand the s.5 carve-out. §A1.1
20. The four **High Court judgments** on withholding a TC for dues — none read. §A1.5

### Part B
21. **CISCE** — all of it (task still running). §B2
22. **NIOS** — all of it (task still running). §B3
23. **IB / CAIE** — all of it, including whether Indian state recognition is required. §B4
24. **KVS Education Code** — article/para numbers for TC; own proforma; transferable-employee TC rule; withholding/duplicate rules. §B5
25. **NVS** — own TC rules and para numbers; TC proforma; the JNV Class-IX migration scheme's governing document. §B6
26. **Sainik Schools Society** and **Rashtriya Military Schools** — own TC rules; the New Sainik Schools (PPP) position. §B7
27. **CTSA** — current 2026 operating status and the extent of the transfer to Tibetan Sambhota Schools/SED-CTA. §B8
28. Whether a **CBSE Examination Bye-Laws edition later than "1995 updated to Dec 2004"** exists. §B1

---

# F · WHAT THIS CHANGES FOR THE MODULE

Only findings at Evidence A/B are listed. Nothing here rests on a NOT FOUND.

1. **Never block a Transfer Certificate for Classes I–VIII.** RTE s.5(3) — *"shall immediately issue"* —
   admits no condition. No dues gate, no eligibility-ladder gate, no DigiLocker gate. §A1.1
2. **Print the affiliation line and number.** *"AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION /
   AFFILIATION NO. ____"* below the school's name and address, and in the seal. `tc_print.php` currently
   prints a hardcoded CBSE claim for every tenant and omits the number — a false statutory claim for
   non-CBSE schools and a missing mandatory field for CBSE ones. §C.5
3. **Do not model countersignature as required.** It is abolished for CBSE. Model it as an optional,
   route-dependent annotation, defaulting to absent. §C
4. **Mark duplicates.** CBSE r.8(vi): a duplicate *"shall always be so marked"*, issued only where the head
   is satisfied the original is lost. §B1.3
5. **Do not offer a "Migration Certificate" template to CBSE schools.** It is a Board document, not a school
   document, and since Examinations-2025 it is digital-only via DigiLocker. §B1.1, §B1.4
6. **Two identifiers, never one.** Sequential statutory serial (Book No./Sl. No.) for the register; separate
   random `DocId` for any DigiLocker handle. §D4
7. **ZenXii may never hold a principal's signing key.** The only lawful server-side route is a CA-offered
   eSign/ESP where the principal authenticates each signature and the key is destroyed after use; ZenXii is
   the ASP. Until then, our PDFs are electronic records under IT Act s.4 but **carry no signature under
   s.5** — and must not be described to schools as legally valid signed documents. §A4.3, §A4.4
8. **Get a data-processing agreement in place with every school.** DPDP s.8(2) makes a school's use of a
   processor lawful *only under a valid contract*; s.8(1) means no warranty from the school discharges us.
   Erasure must genuinely propagate (s.8(7)(b)). Exposure is ₹250 crore (security) and ₹200 crore
   (children's data). Every student in a K-12 school is a "child" — under 18. §A3
9. **Publish issued TCs where they can be verified.** CBSE has required upload to the school's own website
   in 2014, 2018, 2020 and again on 31.10.2025. We produce a PDF and publish nothing. §C.5

## F.1 · Corrections to the existing `AUTHORITIES` corpus

The corpus lives in `blueprints/certificates/design/prototype.html` (and `prototype.artifact.html`),
`const AUTHORITIES`, entries `rte` and `cbse`, both `evidence:"A", verifiedOn:"2026-08-16"`. Having now
read both primary texts, here is what holds and what does not. **I have not edited the prototype** —
this is the change list for whoever owns that corpus.

### CONFIRMED — no change needed

| corpus claim | verdict |
|---|---|
| `duplicateMark: {required:true, text:"Duplicate", citation:"CBSE r.8(vi)", quote:"…it shall always be so marked."}` | **Exactly right**, citation and quote both. §B1.3 |
| "22 mandated fields, plus pre-printed Book No. and Sl. No." | **Confirmed** against Annexure-I. §B1.5 |
| `requiredSignatures:["class_teacher","checked_by","principal"], sealRequired:true` | **Confirmed** — Annexure-I's block is *Signature of class teacher · Checked by (state full name and designation) · Principal · SEAL*. §B1.5 |
| `rte.appliesWhen: sc.stage!=="secondary"` + `scopeNote` "classes I–VIII, does not reach IX–XII" | **Correct.** s.5(1) and 5(2) both end *"for completing his or her elementary education"*. §A1.1 |
| "No numeric turnaround deadline and no issuance register are set by the Act" | **Correct** — s.5(3) says *"immediately"* and nothing more. §A1.1 |

**`fieldListVerified:false, illustrative:true` can now be flipped to verified** — the 22-field list at
§B1.5 was transcribed from the Annexure-I text itself. Compare field-by-field before flipping; the corpus
list includes `student.motherName`, which **Annexure-I does not have** (its field 2 is
*"Father's/Guardian's Name"* only; mother's name is a Board-record option under bye-law r.68, not a TC field).

### MUST CHANGE

1. **`cbse` constraint "A TC originating outside CBSE additionally needs a countersignature (r.8(vii))"
   — demote from a requirement to a recorded conflict.** r.8(vii) is superseded for CBSE→CBSE and the
   2020/2025 circulars use unrestricted language (*"no countersignature of any transfer certificate"*),
   while the 2014 circular step 8 preserved it for other-board arrivals. **Both positions are on CBSE
   letterhead.** Model it as optional/route-dependent, defaulting to absent. §C.4, §D2
2. **`rte` constraint "It cannot be withheld for any reason, including unpaid fees" — soften the
   provenance.** The proposition is sound but **s.5(3) does not say it in those words**; it says
   *"shall immediately issue"* with no condition. The no-dues-gate reading is construction plus case
   law, not a literal clause. Keep the rule; stop presenting it as a quotation. §A1.1
3. **Two mandatory CBSE requirements are missing from the corpus entirely** — both at Evidence A from
   the 04.02.2020 SOP:
   - **`"AFFILIATED TO CENTRAL BOARD OF SECONDARY EDUCATION"` + `"AFFILIATION NO. ____"` must appear
     below the school's name and address on the letterhead, and inside the stamp/seal** where a
     prescribed format is used (SOP I(b), I(e); 2014 step 3). This belongs in `requiredKeys` —
     `school.affiliationNumber` is not currently there at all.
   - **The issued TC must be uploaded to the school's own official website** (2014 step 1; 2018; SOP IV;
     restated 31.10.2025). This is a *post-issuance obligation* the corpus has no shape for.
4. **`authority:"CBSE Examination Bye-Laws, Annexure-I"` should record the edition** — the published
   bye-laws are the **1995 edition updated to December 2004**, and several 2014–2025 circulars override
   them without being folded in. Citing "the Bye-Laws" without the edition hides that. §B1, §D6
5. **Add a `migration_certificate` guard for CBSE.** A Migration Certificate is a **Board** document
   (bye-law def. (ix)), not a school document, and since Examinations-2025 it is digital-only via
   DigiLocker. The module must not offer that template to a CBSE tenant. §B1.1, §B1.4
