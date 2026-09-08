# School types × Document types — what actually differs

**Purpose.** ZenXii's certificate-issuance module asserts rules to schools. This file records what
the law and the boards actually say, with the evidence level for each claim, so the product never
enforces an invented requirement.

**Verification date:** 2026-09-08. **Researcher:** Claude (Opus 5), primary-source pass.

## How to read the evidence levels

| Level | Meaning |
|---|---|
| **A** | I read the primary legal text myself — bare act, statutory rule, education code, board bye-law, prescribed form, or judgment text |
| **A−** | Primary statutory text read through an aggregator (indiankanoon bare-act page, advocatekhoj) rather than the official gazette PDF |
| **B** | Official government/board portal or circular; or rule text reproduced *inside* a judgment |
| **C** | Credible secondary source |
| **D** | Unverified / inference — **never encode a D as a product rule** |

**Rule for the build: nothing below level B may become a hard block in the software.** A C or D
finding may inform a warning at most. Everything in the "NOT FOUND" register at the end is
explicitly *not* established and must not be asserted to a school.

**Age of sources.** Several of the richest primary sources here are old consolidations (Maharashtra
Secondary Schools Code, revised edition 1979; Kerala Education Rules 1959; CBSE Examination
Bye-Laws 1995 updated to Dec 2004). They are cited because they are the authoritative *text* of the
rule and because their structure is what state practice still follows — but **every fee figure and
every time-limit in them must be re-verified against a current edition before the product quotes a
number to a user.** Where a later amendment is known, it is flagged inline.

---

# PART A — SCHOOL TYPES

<!--PART_A_TABLE-->

<!--PART_A_DETAIL-->

---

# PART B — DOCUMENT TYPES

## B.0 Summary table

| Document | Issued by | Prescribed format? | Duplicate rule | Evidence |
|---|---|---|---|---|
| **Transfer Certificate (TC)** | The school — Head/Principal of the school last attended | **YES** where a board or state prescribes one. CBSE: Examination Bye-Laws **Annexure-I**, 22 numbered fields. Kerala: **Form 5** (KER Ch. VI r.17). Maharashtra: **Appendix Four** (leaving certificate). Tamil Nadu matriculation: **Annexure-V** of the Code of Regulations | Duplicate permitted where the head is satisfied the original is lost, and **"it shall always be so marked"** (CBSE Aff. Bye-Laws 8(vi)) | **A** |
| **School Leaving Certificate (SLC)** | The school — Head personally | In most states the SLC *is* the TC under another name. **Maharashtra is genuinely different**: the "Leaving Certificate" is the canonical instrument, form prescribed at **Appendix Four**, and r.32.1 makes an LC **invalid** unless in that form and signed personally by the Head. Kerala keeps them *separate*: TC = Form 5, **leaving certificate = Form 5A** (r.17(3)) | Maharashtra r.30: written explanation, affidavit before a stipendiary Magistrate if the Head is unsatisfied, and **"marked with the word 'Duplicate' in red ink at the top"** | **A** |
| **Migration Certificate** | **The BOARD or university — not the school.** CBSE Examination Bye-Law 2(ix) defines it as "a certificate issued by the Central Board of Secondary Education" | Board-controlled; no school-side format | Bye-Law Annexure-II priced "Migration Certificate **or a duplicate copy thereof**" identically — duplicates contemplated, issued by the Board | **A** |
| **Bonafide Certificate** | The school — Head/Principal | **NOT FOUND** — no statutory or board-prescribed format located | **NOT FOUND** | — |
| **Character / Conduct Certificate** | The school — Head/Principal | **NOT FOUND** as a standalone prescribed format. But conduct **is a prescribed field on the TC/LC itself**: CBSE Annexure-I field 18 "General conduct"; Maharashtra Appendix Four field 8 "Conduct"; Maharashtra General Register field 12 "Conduct" | **NOT FOUND** | **A** for the TC-field finding |
| **Study Certificate** | The school — Head/Principal | **NOT FOUND** — no prescribed format located. Kerala has a near neighbour: **r.22A "Certificate of School Education"**, a prescribed form for a pupil who left before the SSLC exam, fee Rs 10 | **NOT FOUND** | **A** for Kerala r.22A only |
| **Provisional certificate** | **The BOARD** — CBSE Annexure-II prices a "Provisional certificate of passing the examination" | Board-controlled | n/a | **A** for existence; process NOT FOUND |
| **Duplicate (any certificate)** | Same issuer as the original | See the per-document rows. The recurring statutory pattern is: **proof of loss + marked as a duplicate** | See §B.7 — three independent primary sources all require the duplicate be *marked* | **A** |
| **Caste / Income / Domicile** | **Revenue authorities — never the school.** Tahsildar / Mamlatdar / SDM / RDO / Deputy Commissioner, through state citizen-service channels | State revenue forms | n/a | **B** |

## B.1 Transfer Certificate (TC)

**What it is.** CBSE Examination Bye-Law 2(xx) (**A**): *"'Transfer Certificate' means a certificate
issued to a student by the school on his seeking a transfer to another institution by termination of
his studies in the previous institution."*
Source: CBSE Examination Bye-Laws 1995 (updated Dec 2004),
<https://cbseacademic.nic.in/web_material/publication/archive/byelawsenglish.pdf>

### The statutory duty to issue — RTE s.5 (**A−**)

> **5. Right of transfer to other school.**
> (1) Where in a school, there is no provision for completion of elementary education, a child shall
> have a right to seek transfer to any other school, excluding the school specified in sub-clauses
> (iii) and (iv) of clause (n) of section 2, for completing his or her elementary education.
> (2) Where a child is required to move from one school to another, either within a State or outside,
> for any reason whatsoever, such child shall have a right to seek transfer to any other school,
> excluding the school specified in sub-clauses (iii) and (iv) of clause (n) of section 2, for
> completing his or her elementary education.
> (3) For seeking admission in such other school, the Head-teacher or in-charge of the school where
> such child was last admitted, shall immediately issue the transfer certificate:
> *Provided that* delay in producing transfer certificate shall not be a ground for either delaying or
> denying admission in such other school:
> *Provided further that* the Head-teacher or in-charge of the school delaying issuance of transfer
> certificate shall be liable for disciplinary action under the service rules applicable to him or her.

Source: <https://indiankanoon.org/doc/30032725/>, section page <https://indiankanoon.org/doc/154784849/>

Three things the product must get right about this section:

1. **The exclusion in 5(1)/(5)(2) limits where a child may transfer *to*, not who must issue.** The
   RTE transfer *right* does not run into specified-category or unaided schools. The s.5(3) *duty to
   issue* is unqualified — it binds "the school where such child was last admitted", including an
   unaided one. Do not model the exclusion as an issuer exemption. (**A−**)
2. **The first proviso is the load-bearing one.** Admission at the *receiving* school may not be
   delayed or denied for want of a TC. **A module that hard-blocks admission until a TC is uploaded
   contradicts the statute.** (**A−**)
3. **Scope.** RTE covers *elementary* education (classes I–VIII). Above class VIII the RTE duty does
   not apply and board/state rules take over. This elementary/secondary split governs most of the
   hard cases below.

### Prescribed formats

**CBSE — Examination Bye-Laws, Annexure-I, "FORMAT OF TRANSFER CERTIFICATE" (A).**
Header: `Book No. …` `Sl. No. …` `Admission No. …`, then 22 numbered fields:

1. Name of Pupil · 2. Father's/Guardian's Name · 3. Nationality · 4. Whether the candidate belongs to
Scheduled Caste or Scheduled Tribe · 5. Date of first admission in the School with class · 6. **Date
of birth (in Christian Era) according to Admission Register** (in figures and in words) · 7. Class in
which the pupil last studied (figures and words) · 8. School/Board Annual examination last taken
with result · 9. Whether failed, if so once/twice in the same class · 10. Subjects studied (5 slots)
· 11. Whether qualified for promotion to the higher class; if so to which class (figures and words) ·
12. Month up to which the school dues paid · 13. Any fee concession availed of; if so the nature ·
14. Total No. of working days · 15. Total No. of working days present · 16. Whether NCC Cadet / Boy
Scout / Girl Guide · 17. Games played or extra-curricular activities (with achievement level) ·
18. **General conduct** · 19. Date of application for certificate · 20. Date of issue of certificate
· 21. Reasons for leaving the school · 22. Any other remarks.

Signature block: *Signature of class teacher* · *Checked by (state full name and designation)* ·
*Principal* · **SEAL**.

Note field 6 — the DOB is expressly "**according to Admission Register**". The prescribed form itself
declares the TC to be a transcription of the register. (**A**)

**Kerala** — Transfer certificate in **Form 5**, Kerala Education Rules 1959, Chapter VI r.17(1);
separate **leaving certificate in Form 5A** under r.17(3). (**A**)
Source: <https://education.kerala.gov.in/wp-content/uploads/2019/11/Chapter_6.pdf>

**Maharashtra** — **Appendix Four, "Form of School Leaving Certificate" [Vide Rule 17]** (**A**), see
§B.2.

**Tamil Nadu (matriculation schools)** — prescribed TC at **Annexure-V of the Code of Regulations for
Matriculation Schools**; serial 8 is "Whether the pupil has paid all the fees due to the School"
(**B**, reproduced in a Madras HC judgment, <https://indiankanoon.org/doc/104968571/>). Full field
list NOT FOUND.

### Countersignature — the rule, and how it has changed

**CBSE Annexure-I footnote (A)** — footnote to the prescribed format, as amended by the Examination
Committee 7.5.1999 / Governing Body 13.5.1999:

> *Transfer certificate should be issued only under the signatures of the regular Principal/Vice
> Principal and it should be counter-signed by an officer not below the rank of District Inspector of
> Schools/Deputy Director of Education/Education Officer of the Education Deptt. of the State/Union
> Territory concerned. In case of a student migrating from one CBSE affiliated school to another CBSE
> affiliated school the transfer certificate of a previous school of such a student may be
> countersigned by the Regional Officer of the Board or the Asstt. Commissioner of the KVS or the
> Deputy Director, Navodaya Vidyalaya Samiti in India or an officer of the Board at the Head Quarters
> and by the First Secretary/Attache/Cultural Attache or an equivalent officer of the Embassy/High
> Commission of India in the concerned country in respect of students studying in an affiliated
> school of the CBSE situated outside the country and the student shall not be admitted to a school
> without such a counter signature.*

**⚠ SUPERSEDED IN PART — CBSE circular CBSE/PR/UT/CI/2019/892 dated 04.10.2019 (A/B).** Subject:
*"Withdrawal of practice of countersigning of the Transfer Certificates and mandatory uploading of
scanned copy of T.C."*

> *As already informed to all, the practice of the countersignature of the Transfer Certificates from
> one CBSE affiliated school to another has been done away with.*

It cites three earlier circulars (Coord/EC-30.7/2014 of 26.11.2014; CBSE T.C. uploading/2016 of
01.10.2018; Coord./ROs/Admission-IX/2019 of 18.07.2019) and directs schools to stop sending TCs to
CBSE HQ and Regional Offices for countersignature.
Source: <https://www.cbse.gov.in/cbsenew/documents/Subject_Reminder_practice_countersigning_Transfer_Certificates_03112025.pdf>
(this file, re-circulated under a 2025 filename, contains the 04.10.2019 circular).

**So the current CBSE position (B):**

| Move | Countersignature |
|---|---|
| CBSE school → CBSE school | **None.** Withdrawn 2019. Scanned TC uploaded to the school's website instead |
| Non-CBSE institution → CBSE school | **Required** — Affiliation Bye-Laws 8(vii): "such a student shall produce a transfer certificate duly countersigned by an authority as indicated in the format given in Annexure-I" (**A**) |
| Other Indian board, into class X or XII mid-stream (on parental transfer) | TC "duly countersigned by the Educational Authorities of the Board concerned", plus post-facto CBSE approval within one month (Aff. Bye-Laws 7.3(c) and 7.4(ii)) (**A**) |

**Countersignature is a *receiving-side* condition, not an issuing duty.** Two state codes confirm the
pattern and both attach it to an inter-State move:

- **Bihar Education Code 1961, s.272 (A−)**: *"A pupil from a school in another State who wishes to
  join a recognised school in Bihar must produce a transfer certificate countersigned— (a) if the
  school is in another State, by the Inspector of Schools in charge of the area within which the
  school is situated; (b) if the school is in an Indian State, by the officer of the State authorised
  in this behalf."* <https://indiankanoon.org/doc/141774149/>
- **Maharashtra Secondary Schools Code r.22.1 (A)**: *"Admission of a pupil from any other State or
  Union Territory shall be made only if the leaving certificate of the pupil is countersigned by the
  Education Officer or an equivalent authority in that State/Union Territory; but if the leaving
  certificate is not so countersigned, the Head of the school may admit the pupil provisionally
  pending such countersignature, reporting at the same time, full particulars of the case to the
  appropriate authority."* Note the **provisional-admission escape valve** — the missing
  countersignature delays confirmation, not admission.
  Source: <https://www.pcer.ac.in/wp-content/uploads/2022/01/Secondary-Schools-Code-Revised-Edition-1979-D-1192.pdf>

Countersignature rules for other states: **NOT FOUND**. Do not generalise Bihar's or Maharashtra's
rule nationally.

### Can a school withhold a TC for unpaid fees? — a genuine, unresolved conflict

**This is the sharpest conflict in the whole research.** The product must not pick a side silently.

**Rules that expressly permit refusal:**

- **Kerala Education Rules 1959, Ch. VI r.17(2) (A)**: *"No transfer certificate shall be issued to a
  pupil from whom there are any dues to the school."*
- **Maharashtra Secondary Schools Code r.29.1 (A)**: *"Refusal to issue a leaving certificate without
  adequate justification or delay of over one week in issuing it or in giving a reply explaining why
  it cannot be issued may entail action against the school. **The only grounds on which a leaving
  certificate may be refused are: (i) Non-payment of fee and/or other dues; and (ii) Rustication by
  the Director under Rule 34.**"*
- **CBSE Affiliation Bye-Laws 8(vi) (A)**: a leaving student *"shall on a payment of all dues, receive
  an authenticated copy of the Transfer certificate up to date."*

**Courts that have held the opposite:**

- **Madras HC**, *All India Private Schools Legal Protection Society v. State of Tamil Nadu*, W.P.
  16581 & 18275/2021, N. Anand Venkatesh J, 28.10.2021 (**B**): *"This Court has already held that
  the Transfer Certificate cannot be retained on the ground of non-payment of fees and the
  institution can only proceed against the students for recovery of the fees in accordance with
  law."* <https://indiankanoon.org/doc/104968571/>
- **Rajasthan HC (DB)**, *Rajkumar v. Headmaster, Gudha Public School*, Mohammad Rafiq & Narendra
  Singh Dhaddha JJ, 23.07.2019 (**B**): no justification for withholding TCs; issue within three
  days; recover fees "by way of filing civil suit or availing any other remedy as per the law"; DEO
  "shall initiate action against the concerned school for its de-recognition" on failure.
  <https://indiankanoon.org/doc/186061426/>
- **Delhi HC**, *Advika Bansal v. Maxfort School*, Mini Pushkarna J, 29.05.2023 (**B**): TCs to issue
  in 10 days, following a Division Bench holding that School Leaving Certificates cannot be withheld.
  <https://indiankanoon.org/doc/150614996/>
- **RTE s.5(3)** independently forbids delay for a child in elementary education, on pain of
  disciplinary action, and forbids the *receiving* school from denying admission for want of a TC.
  (**A−**)

**The reconciliation the Madras HC actually adopted (B), and the one the product should implement:**
the school **may record the arrears on the certificate but may not retain the certificate**. The court
noted the school "can always indicate that it has not been paid and can mention the arrears of fees",
ordered clause 8 (fees-due) *added* to the EMIS-portal TC, and added that recording arrears "does not
automatically result in the liability being accepted".

> **Product rule: disclose dues on the document; never gate the document.** Both prescribed formats
> already carry a dues field for exactly this purpose (CBSE field 12 "Month up to which the school
> dues paid"; Maharashtra General Register field 15 "fees paid or unpaid"). Surface a warning
> citing the state rule where one permits refusal; never make non-payment a hard block on
> generating a TC.

### Serial number and register linkage

The CBSE prescribed format carries `Book No.` / `Sl. No.` / `Admission No.` in its header, and its DOB
field is expressly taken "according to Admission Register" (**A**). Maharashtra's prescribed LC opens
with "Register No. of the Pupil" and closes with the certification *"Certified that above information
is in accordance with the school register."* (**A**). A general statutory rule requiring a TC to cite
a register *page* number was **NOT FOUND**, but the register-linkage itself is established on the face
of two prescribed forms. See Part C.

## B.2 School Leaving Certificate (SLC), and how Maharashtra differs

**In most of India, SLC and TC name the same instrument.** CBSE's own Affiliation Bye-Laws 6.1(iv)(a)
treats them as alternatives — *"The School Leaving Certificate/Transfer Certificate signed by the
Head of the Institution last attended"* (**A**). CBSE's Exam Bye-Law 69.1(ii)(b) likewise calls the
document received from the previous school "The School Leaving Certificate of the previous school"
(**A**). Delhi School Education Rules 1973 r.139 uses "a transfer or school leaving certificate"
(**B**, snippet only; full rule NOT FOUND).

**Maharashtra is genuinely different — the Leaving Certificate is the canonical instrument, and it is
form-validated.** From the Secondary Schools Code (**A**, all quotes from the 1979 revised edition at
<https://www.pcer.ac.in/wp-content/uploads/2022/01/Secondary-Schools-Code-Revised-Edition-1979-D-1192.pdf>):

- **r.17** — *"No recognised school shall admit a pupil without a leaving certificate from the last
  recognised school which he had attended. The form of leaving certificate prescribed by Government is
  given in appendix Four. If no leaving certificate is produced on the ground that the pupil has not
  previously attended such a school, a declaration to that effect should be obtained from the parent
  or guardian."*
- **r.32.1** — *"**No leaving certificate is valid unless it is in the form prescribed in this Code
  (vide appendix Four) and is signed personally by the Head of the school.**"* r.32.2 allows a person
  authorised by the management to sign as in-charge Head only in the Head's absence and on urgent
  demand.
- **r.28** — every application for an LC shall be in writing by the parent or guardian (a major pupil
  may apply personally); *"School should issue leaving certificates without unnecessary delay."*
- **r.18** — if the previous school **refuses** an LC, the new Head writes to the former Head; if no
  satisfactory explanation arrives **within ten days** the new Head *"will be entitled to admit the
  pupil provisionally"* and must report to the appropriate authority. This is Maharashtra's built-in
  remedy for a withheld certificate.
- **r.27** — suspected unauthorised addition/alteration in an LC must be reported to the appropriate
  authority; the pupil is not admitted pending reply, or if already admitted is allowed to sit the
  annual exam provisionally with the result withheld.
- **r.33** — promotion/detention noted in the LC's remarks column.
- **r.34** — rustication is a listed penalty for securing admission by a **false or forged leaving
  certificate**, or where LC entries have been tampered with.

**Prescribed form — Appendix Four "FORM OF SCHOOL LEAVING CERTIFICATE" [Vide Rule 17] (A).**
Warning printed on the form itself: *"(No change in any entry in this certificate shall be made except
by the authority issuing it and any infringement of this requirement is liable to involve the
imposition of penalty such as that of rustication.)"*
Header: **Register No. of the Pupil**, Name of School. Fields: 1. Name of pupil in full · 2. Caste and
sub-caste **only in the case of pupils belonging to Backward Classes** and category among Backward
Classes · 3. Place of birth · 4. Date of birth, month and year according to the Christian era, **both
in words and figures** · 5. Last school attended · 6. Date of admission · 7. Progress · 8. Conduct ·
9. Date of leaving school · 10. Standard in which studying and since when · 11. Reason of leaving
school · 12. Remarks. Then: **"Certified that above information is in accordance with the school
register."** Signed by *Class Master* and *Head of the School*.
Notes on the form: entries in columns 4 and 10 in both figures and words; **"These entries shall be in
manuscript and not typewritten"**; accelerated promotions specified in Remarks; named scholarships
noted in Remarks with the prescribed Index Card attached.

> The manuscript-not-typewritten note is a 1979 provision and is plainly in tension with any
> computer-generated certificate. **NOT VERIFIED** whether Maharashtra has since relaxed it —
> treat as an open question before shipping printed LCs into Maharashtra. (see NOT FOUND register)

**Fees — Maharashtra r.31 (A):** *"No fee shall, in any circumstances, be charged for a leaving
certificate, if asked for, within a year from the date of leaving a school or from the date of the
result of the public examination at which the pupil appeared from the school. After this period, a fee
of Re. 1 may be charged for every subsequent year, subject to a maximum of Rs. 5. A fee of Rs. 3 may
be charged for a duplicate copy of the leaving certificate or the date of birth or any other extract
from the General Register."* **The free-within-one-year rule is the structurally important part; the
rupee amounts are 1979 figures and must be re-verified.**

**Kerala keeps TC and leaving certificate as two different instruments (A):** KER Ch. VI r.17(3) —
where a pupil removed from the rolls is over 20 and no sanction has been obtained, *"no transfer
certificate shall be issued to him from that school for admission to any other school … But a leaving
certificate in form 5A may be issued, if required."* Kerala also has **r.22A "Issue of Certificate of
School Education"** — a prescribed certificate for a pupil who left before the SSLC examination, on
application and a fee of Rs 10 into the Government treasury.

**Maharashtra LC as date-of-birth proof: NOT FOUND / DO NOT ASSERT.** It is widely claimed that a
Maharashtra LC is accepted DOB proof for passport and PAN. `passportindia.gov.in` could not be reached
(404 on the document-advisor path) and no current official document list was verified. What *is*
established is only that Maharashtra treats the General Register DOB entry as authoritative and the LC
as its transcript (r.26.2, r.26.3, Appendix Four). **The product must not tell a school that its LC is
valid passport/PAN DOB proof.**

## B.3 Migration Certificate

**Issued by the board or university, not by the school. (A)**

CBSE Examination Bye-Law 2(ix): *"'Migration Certificate' means a certificate issued by the Central
Board of Secondary Education at the request of a candidate passing out from Secondary/Senior School
Certificate Examination of the Board for seeking admission to the examinations of another
Board/University."*

CBSE Examination Bye-Law 66 (**A**):
> 66. Migration Certificate
> (i) A candidate who has appeared in an examination of the Board and has passed the examination may
> obtain a Migration Certificate on payment of the prescribed fee.
> (ii) A candidate placed in Compartment may also be issued a Migration Certificate indicating his/her
> status as such.

The mirror-image rule confirms its purpose — CBSE Bye-Law 27, *"Submission of Migration Certificate by
Private/Teacher Candidates"*: candidates coming from other recognised Boards/Universities must submit
a Migration Certificate **from the concerned Board/University** along with the examination form
(**A**).

**Fee (archival, A):** Annexure-II priced *"Migration Certificate or a duplicate copy thereof — Rs
50.00"*. This is a Dec-2004 figure. **The current CBSE fee is NOT FOUND** — cbse.gov.in blocks
WebFetch and the current duplicate-documents portal (`cbseit.in/cbse/web/dcs/`) returned 503.

**TC vs Migration Certificate — the distinction to encode:**

| | Transfer Certificate | Migration Certificate |
|---|---|---|
| Issuer | The school (Head/Principal) | The board / university |
| Triggered by | Leaving one school for another | Leaving one board's examination system for another's |
| Purpose | Admission to the next *school* | Registration for the next *board's/university's examination* |
| Typical timing | Any time a pupil leaves | After passing/appearing at a board examination |

**A school never issues a migration certificate.** Established for CBSE at level **A** by the
definition above. Whether any state board delegates issuance to schools: **NOT FOUND** — no state
board's regulation on migration certificates was read. Do not present the "boards only" rule as
nationally universal until at least two state boards are checked.

**DigiLocker delivery of migration certificates: NOT FOUND.**

## B.4 Bonafide Certificate

**What it is (C/D, convention not law).** A statement by the Head that a named person is a currently
enrolled student of the school, typically with class, section, admission number and period of study.
Used for bank accounts, passports, visas, scholarships, travel concessions and court/administrative
purposes.

**Prescribed format: NOT FOUND.** No statutory, board or state-prescribed bonafide-certificate form was
located in any source read (CBSE Examination Bye-Laws 1995/2004 full text; CBSE Affiliation Bye-Laws
admission chapter; Kerala Education Rules Ch. VI; Maharashtra Secondary Schools Code including its full
appendix list). **The absence of a find is weak evidence here** — the Maharashtra and Kerala codes both
enumerate their prescribed forms and neither lists a bonafide certificate, which is suggestive, but the
searches that would settle it could not be run (see method note).

> **Do not ship the assertion "there is no prescribed format for a bonafide certificate."** Ship
> instead: "no prescribed format was located; ZenXii supplies a conventional template."

**Validity period: NOT FOUND** as a statutory matter. Recency requirements in practice are imposed by
the *requesting* authority, not by school law. No specific requesting-authority checklist was verified.

**Nearest verified relative:** Kerala's **r.22A Certificate of School Education** (**A**) — a
prescribed form, issued by the Headmaster to a pupil who left before the SSLC examination, on
application and a Rs 10 treasury fee. That is a *study/leaving* attestation, not a bonafide
certificate; it is noted here because it is the only prescribed "the pupil was here" certificate found
outside the TC/LC family.

## B.5 Character / Conduct Certificate

**Conduct is a prescribed field on the leaving document itself, in every prescribed format read (A):**

- CBSE Examination Bye-Laws Annexure-I, **field 18 — "General conduct"**
- Maharashtra Appendix Four (LC), **field 8 — "Conduct"**
- Maharashtra Appendix Eighteen (General Register), **field 12 — "Conduct"**

So in the prescribed-form world, conduct travels **on the TC/LC**, and a separate character certificate
is a convention layered on top. That is a real, citable finding and it should shape the data model:
conduct is an attribute of the leaving record, not an independent document.

**Standalone character-certificate format: NOT FOUND.** No issuing rule, contents rule or prescribed
form located.

**Restrictions on adverse remarks about a minor: NOT FOUND — and do not invent one.**
RTE s.16 (bar on holding back and expulsion till completion of elementary education) and s.17 (bar on
physical punishment and mental harassment, with disciplinary action for contravention) were read
(**A−**, <https://indiankanoon.org/doc/30032725/>) and **neither addresses remarks in school records**.
Citing s.17 as a rule against adverse conduct remarks would be an inference, not the text. NCPCR
guidance and JJ Act provisions on this: not searched.

What *is* established, and is the better hook for a product guard-rail: Maharashtra's prescribed LC
carries the printed warning that no entry may be changed except by the issuing authority, on pain of
rustication (**A**), and Maharashtra r.26.3/26.4 lock General Register entries (**A**). The design
consequence is about **immutability and provenance of the conduct entry**, not about censoring it.

## B.6 Study Certificate

**Prescribed format: NOT FOUND.** No TN, AP, Telangana or Karnataka prescribed study-certificate form
was located, and no rule text distinguishing a study certificate from a bonafide certificate was found.

**The working understanding — that it certifies class-wise years of study and is demanded for
caste/nativity/domicile and reservation claims — is UNVERIFIED (D).** Do not encode it as a legal
description. What *is* verified is the underlying reason such a document has value: school records are
the primary documentary evidence in caste-validity adjudication (see §B.8).

**Nearest verified relative (A):** Kerala **r.22A, "Certificate of School Education"** — prescribed
form, Headmaster-issued, for pupils who left before the SSLC examination, fee Rs 10.

**Karnataka (B):** the Nadakacheri / Atalji Janasnehi Kendra portal is the revenue channel for caste,
income and residence certificates and publishes a "Documents Required for Online Application" list
(<https://nadakacheri.karnataka.gov.in/>); the document list itself could not be retrieved, so
**which** school document it demands is NOT FOUND.

## B.7 Provisional and Duplicate certificates

### Provisional certificate

**Board-issued.** CBSE Examination Bye-Laws Annexure-II prices *"Fee for Provisional certificate of
passing the examination — Rs 50.00"* (**A**, Dec-2004 figure). Its purpose — an interim attestation of
having passed, pending the final certificate — follows from the fee-line description, but **the
governing rule, contents and validity were NOT FOUND.**

Distinguish this from **provisional *admission***, which is a different concept and is well evidenced:
Maharashtra r.18 (admit provisionally when the previous school will not send an LC within ten days),
r.22.1 (admit provisionally pending inter-State countersignature), r.24.2/24.3 (admit provisionally
after a test, for pupils from unrecognised schools), and r.27 (provisional exam entry where an LC is
suspected of tampering) — all **A**.

### Duplicate certificates — the rule is real and it is consistent across sources

**Three independent primary sources all require a duplicate to be marked as a duplicate:**

1. **CBSE Affiliation Bye-Laws 8(vi) (A)**: *"A duplicate copy may be issued if the head of the
   institution is satisfied that the original is lost but **it shall always be so marked**."*
   <https://www.cbse.gov.in/cbsenew/Exambylaws_archive/ADMISSION%20OF%20STUDENTS%20TO%20A%20SCHOOL.pdf>
2. **Maharashtra Secondary Schools Code r.30 (A)**: *"In the case of a request for a duplicate copy of
   the leaving certificate once issued, the parent or the guardian should be asked to state in writing
   what happened to the original certificate already issued and why a duplicate is required. If the
   Head of the school is not satisfied with the adequacy of the reason, he may ask the parent or
   guardian to make an affidavit before a stipendiary Magistrate. **Every duplicate copy of a leaving
   certificate shall be marked with the word "Duplicate" in red ink at the top.**"*
3. **Kerala Education Rules 1959, Ch. VI r.22 (A)**: *"Issue of duplicate transfer certificate — In
   cases of loss or irremediable damage to transfer certificates, duplicate may be issued by the
   Headmaster on payment of a fee of Rupee one. No application for a duplicate transfer certificate
   shall be entertained unless it is accompanied by a chalan for Rupee one **and a certificate from a
   Gazetted Officer or the President of a local authority or a member of Legislative Assembly or a
   member of Parliament to the effect that the original is irrecoverably lost or damaged**. **Duplicate
   certificate issued should be clearly marked 'Duplicate'.**"*

**The common statutory shape, safe to encode (A):**

| Element | Requirement | Strength |
|---|---|---|
| Trigger | Original lost / irremediably damaged | All three sources |
| Proof of loss | Written explanation from parent/guardian (MH); affidavit before a stipendiary Magistrate if the Head is unsatisfied (MH); certificate from a Gazetted Officer / local-authority President / MLA / MP (KL) | **A**, but the *form* of proof is state-specific — do not impose one state's proof on another |
| Head's satisfaction | Required before issue (CBSE, MH) | **A** |
| **Marking** | **Must be marked as a duplicate.** Maharashtra specifies the word "Duplicate" **in red ink at the top** | **A** — this is the one element all three agree on |
| Fee | MH Rs 3; KL Re 1 (both archival) | **A** as text, figures stale |
| Signatory | Same authority — the Head. No source requires a higher signatory | **A** by absence in three codes |

**Limit on the number of duplicates: NOT FOUND** in school rules. CBSE at *board*-certificate level
expressly contemplates a third copy — Bye-Law 67 speaks of "duplicate / triplicate certificate" — so
a one-duplicate-only rule would be wrong at least for CBSE documents. **Do not implement a hard cap.**

**Board-level duplicates — CBSE Examination Bye-Law 67 (A):**
> *"A Candidate may obtain duplicate / triplicate certificate on payment of the prescribed fee and
> submission of an application on a prescribed form in the event of loss/theft/mutilation of the
> original certificate provided that an affidavit is filed to that effect before an official not below
> the rank of a first class Magistrate or a Member of the Governing Body of the Board. Further the
> person requesting for duplicate or triplicate certificate would notify the loss/theft/mutilation of
> the certificate through Press Note/advertisement in some leading Newspaper and shall submit the
> Press Clipping to the Board along with application and the affidavit."*

So the **newspaper-advertisement requirement is real but belongs to the BOARD's certificates, not to a
school's TC.** Conflating the two would impose a burden the school-level rules do not.

Archival CBSE fees (Annexure-II, Dec 2004, **A**): duplicate copy of certificate/marks-sheet Rs 50;
migration certificate or duplicate thereof Rs 50; date-of-birth certificate Rs 50; provisional
certificate Rs 50; duplicate admission card Rs 20; duplicate result card Rs 20; duplicate registration
card Rs 50; **correction in certificate/marksheet (Date of Birth, Name etc.) Rs 500**. **Current fees
NOT FOUND.**

### Correction of a certificate (name / date of birth)

**CBSE Examination Bye-Law 69 (A).** Rule 69.1 — correction of name means correcting spelling,
factual and typographical errors *"to make it consistent with what is given in the school record"*;
change of name (as opposed to correction) is considered only where permitted by a court and notified
in a Government Gazette. The documents required for either are (**A**):

> (a) Admission form(s) filled in by the parents at the time of admission.
> (b) The School Leaving Certificate of the previous school submitted by the parents of the candidate
> at the time of admission.
> (c) **Portion of the page of admission and withdrawal register of the school where the entry has been
> made in respect of the candidate.**

Rule 69.2 — no change in a recorded DOB; only corrections of typographical/other errors to make the
certificate consistent with the **school records**, and only if the school record was not itself
altered after the exam application was submitted. Same three documents required.

**⚠ CONFLICT — the time limit has changed and the current value is unverified.**

| Source | Limit |
|---|---|
| Examination Bye-Laws 1995 (updated Dec 2004), r.69.2(iv) (**A**) | *"within **two years** of the date of declaration of result of Class X examination … effective from the examination to be held in March, 1995"* |
| *Chirag Jain v. CBSE*, Delhi HC, Kailash Gambhir J, 10.05.2011 (**B**) — <https://indiankanoon.org/doc/176985087/> | two years; refusal upheld, limitation held reasonable |
| *Spriha Choudhary v. CBSE*, Delhi HC, C. Hari Shankar J, 20.12.2018 (**B**) — <https://indiankanoon.org/doc/46694985/> | quotes r.69.2(iv) as *"within **five years** of the date of declaration of result"* |

The bye-law was evidently amended between 2011 and 2018 from two years to five. **The current 2026
text was not read (cbse.gov.in blocks WebFetch). Do not hardcode either number.** Courts enforce the
bye-law rather than override it — *Chirag Jain* upheld a refusal; *Spriha Choudhary* granted relief
only by fitting the case inside the "typographical error" limb.

**Maharashtra's school-side rule is stricter than CBSE's (A/B):** Secondary Schools Code cl. 26.3 —
*"No alteration in the date of birth or other entries in the General Register, including correction of
spelling shall be allowed without the previous permission of the appropriate authority. **No such
alteration in the figure of Date of Birth shall, however, be allowed even with such permission after
the student has left secondary school.** This shall not however preclude corrections of obvious
mistakes, that is the date of a particular month which does not exist in the calendar."* And cl. 26.4
— applications for change of DOB, name, surname or caste in the General Register *"shall be entertained
from or on behalf of a pupil who is attending a school"* and not from one who has left. (Text read
directly in the Code (**A**) and reproduced in *Rakesh Ramlal Gujar v. State of Maharashtra*, Bombay HC
Aurangabad, Ghuge & Mehare JJ, 13.07.2021, <https://indiankanoon.org/doc/58184525/> (**B**).)

**Kerala's rule differs again (A):** KER Ch. VI r.3 — name, religion and DOB once entered may not be
altered except with the sanction of the notified authority, on an application forwarded through the
Headmaster with satisfactory evidence; r.3(1A) fixes a limit of **fifteen years** from leaving school
or last appearing for the SSLC examination, whichever is earlier, with a Government power to condone
delay where the applicant is within 50 years of age as per the original entry. And when an alteration
is sanctioned it must be made *"in the Admission Register and the other connected records of the
schools previously attended by the pupil as well as in the school in which he was studying at the
time"* — i.e. the correction propagates backwards across schools.

> **Three different limits — CBSE 2→5 years, Maharashtra "never after leaving", Kerala 15 years.**
> Correction windows are irreducibly jurisdiction-specific. The product must make this configurable
> per school and must not ship a default number.

**Controlling Maharashtra authority not yet read:** *Janabai Himmatrao Thakur v. State of Maharashtra*,
Bombay HC Full Bench, 17.10.2019, reported **2019 (6) Mh.L.J. 769 (FB)** (**C** — citation only, ~78
citing cases). Read this before building Maharashtra correction logic.

## B.8 Caste / Income / Domicile certificates and the school's role

**Issuing authority is the revenue administration, never the school (B).**
- Maharashtra — caste certificate is a **Revenue Department** notified service delivered through Aaple
  Sarkar, <https://aaplesarkar.mahaonline.gov.in/en>
- Karnataka — caste, income and residence certificates issued through **Nadakacheri / Atalji Janasnehi
  Kendra**, the revenue citizen-service channel, <https://nadakacheri.karnataka.gov.in/>

Statutory competent-authority provisions (e.g. the Maharashtra caste certificate Act/Rules) and the
per-service document checklists for Maharashtra, TN e-Sevai, MeeSeva, Delhi e-District and Nadakacheri
were **NOT FOUND** — the portal landing pages were reachable but the checklists were not. So **which
school document each state demands is not established**, and that is the operationally useful part
still missing.

**Is a school ever the issuing authority? No evidence that one is — and no evidence ruling it out.**
Treat as **NOT FOUND**, not as "no".

**The school's real role: the register entry is evidence, and it is litigated (B).**
*Vilas s/o Nagorao Dhanorkar v. State of Maharashtra*, Bombay HC (Nagpur), 05.03.2026 —
<https://indiankanoon.org/doc/26780395/>:
- cites ***Kumari Madhuri Patil v. Addl. Commissioner, Tribal Development*, (1994) 6 SCC 241** for the
  principle that pre-Constitutional documents carry the highest probative value in caste claims and
  that "the oldest documents carry the greatest probative value and must be given precedence over
  subsequent entries";
- the decisive document was the applicant's **grandfather's school leaving certificate dated
  30.06.1921**, recording caste as "Halba";
- instructively, the father's school entries (1946–54) recording "Koshti" were **discounted as denoting
  occupation rather than caste**, Koshti having become an independent caste category only in 1995.

*Madhuri Patil* itself was not read (**NOT FOUND** — citation captured, judgment text not retrieved).

> **Product implication, and it is a significant one.** The caste field in an admission register is not
> an administrative label. It is evidence that may decide a person's claim three generations later.
> Entry provenance, entry date, who made the entry, and immutability with a full audit trail matter far
> more than how the field is displayed. Note also that both prescribed forms read here restrict the
> field: Maharashtra's LC and General Register both say caste and sub-caste are to be recorded **"only
> in the case of pupils belonging to Backward Classes"** and the category among them (**A**); CBSE's TC
> field 4 asks only *"Whether the candidate belongs to Scheduled Caste or Scheduled Tribe"* (**A**).

**Income certificate — NOT FOUND.** Not verified that it is purely revenue-issued (though nothing
suggests otherwise), and **no state's RTE s.12(1)(c) admission document list was retrieved**. RTE
s.12(1)(c) itself mandates the 25% intake at 2(n)(iii)/(iv) schools but **prescribes no documents**
(**A−**) — the document lists live in state RTE rules and notifications.

**Domicile / nativity — NOT FOUND.** No state nativity checklist naming a school document was
retrieved.

---

# PART C — THE STATUTORY REGISTERS

## C.1 Is a register mandatory? Yes — under state education rules and under board bye-laws

| Source | Register | Mandate | Evidence |
|---|---|---|---|
| **Kerala Education Rules 1959, Ch. VI r.2(1)** | Admission Register | *"Every School shall maintain an Admission Register in **Form 4**."* | **A** |
| **Maharashtra Secondary Schools Code r.83(A)** | General Register | *"Every school shall maintain in situ and produce at the time of inspection or visit the following records and registers:— (A) Pertaining to Pupils— 1. **General Register in the form given in appendix Eighteen**; 2. Attendance Register … appendix Nineteen; 3. Leaving Certificates received from other schools; 4. **Counterfoils of Leaving Certificates issued to pupils**; 5. Records of pupils' attainments and/or examination results; 6. Records of health and medical examination of pupils; 7. Answer-books of the annual examination of the preceding year; 8. Record of the pupils admitted with test prior to the inspection."* | **A** |
| **CBSE Affiliation Bye-Laws, Admission Procedure 8(i)** | Admission Register | *"Admission register **in the form prescribed by the State Government concerned/Kendriya Vidyalaya Sangathan/Navodaya Vidyalaya Samiti** as the case may be, shall be maintained by the 'School' where the name of every student joining 'the School' shall be entered."* | **A** |
| **CBSE Examination Bye-Laws 2(i)** | definition | *"'Admission Register or **Admission & Withdrawal Register**' means a register maintained by the school indicating the admission of candidates to various classes in the institution."* | **A** |

Note what CBSE 8(i) does *and does not* do: it makes the register **mandatory** but **delegates its
form to the State/KVS/NVS**. There is no single national register format. The product must treat the
register schema as jurisdiction-configurable, with the state form as the authority.

**Names in use for the same artefact:** Admission Register (Kerala, CBSE), Admission & Withdrawal
Register (CBSE definition), General Register (Maharashtra), Scholar Register / General Register
(common usage in north-Indian states — **NOT FOUND** as a rule-defined term in any source read).

## C.2 What it must contain

**Kerala, Form 4 (r.2)** — the rule text specifies the entries even where the form itself was not
read: on admission, *"his name, date of birth, religion, community and other particulars as given in
the application for admission shall be entered in the Admission Register **and attested by the
Headmaster**"* (r.2(2)); and *"The date of birth of the pupil shall be entered **in words as well as
figures** and the entry **shall not bear any marks of erasure or overwriting**"* (r.2(3)). (**A**)

**Maharashtra, Appendix Eighteen — "FORM OF GENERAL REGISTER" [Vide Rule 83-A.1] (A)** — 15 columns:

1. Register No. · 2. Name in full · 3. Caste with sub-caste [only in the case of pupils belonging to
Backward Classes and category among Backward Classes] · 4. Place of birth · 5. Date of birth, month
and year according to the Christian era, **both in words and figures** · 6. **Attestation of parent or
guardian** · 7. Last school attended · 8. Date of admission · 9. [Paying or Free] · 10. Standard and
class into which admitted · 11. Progress · 12. Conduct · 13. Date of leaving · 14. Standard and class
from which left · 15. Remarks (**reason for leaving, fees paid or unpaid etc.**).

Note column 6: the **parent's attestation lives in the register**, which is what makes the register —
not the certificate — the primary record.

**Maharashtra entry discipline, rules 26.1–26.4 (A):**
- 26.1 — a pupil's name is not entered until formally admitted.
- 26.2 — DOB entered in words and figures **from the date given in the school leaving certificate**;
  for a first-time entrant, the parent produces satisfactory evidence (extract from the municipal or
  village birth register, vaccination certificate or baptismal certificate) **and the nature of the
  evidence produced is itself recorded in the remarks column**; for a returning pupil the DOB is taken
  from the last recognised school's leaving certificate.
- 26.3 — no alteration to DOB or other entries without prior permission of the appropriate authority;
  when made, the number and date of the sanctioning order go into the remarks column and **"The written
  order should be preserved as permanent record."**
- 26.4 — applications for change of DOB, caste etc. entertained only for a pupil still attending.

**Maharashtra numbering / CBSE numbering (A):** CBSE Affiliation Bye-Laws 8(ii) — *"Successive numbers
must be allotted to students on their admission and **each student should retain this number throughout
the whole of his career in the school**. A student returning to the school after absence of any duration
**shall resume his original admission number**."* This is a hard constraint on any ERP that mints a new
ID on re-admission.

## C.3 How long must it be preserved? — Permanently

**Maharashtra Secondary Schools Code, Annexure (15) [Vide Rules 3.2(11) and 83] (A)** — *"A Statement
showing the particulars of some of the Important Registers and Records maintained in Non-Government
Secondary Schools and the minimum period of their preservation"*, issued by the Director of Education's
letter No. S-67(c)-C dated 25 April 1965. Retention categories: **A = Permanent; B = 30 years;
C(1) = 10 years; C(2) = 5 years; D = 18 months.**

| # | Record | Retention |
|---|---|---|
| 1 | **General Admission Register** | **A — Permanent** |
| 3 | Circular and order files | A — Permanent |
| 4 | Provident Fund account register | A — Permanent |
| 5 | Head Master's Log Book | A — Permanent |
| 6 | Cash book | B — 30 years |
| 12 | **Leaving Certificates received from other schools for incoming students** | **C(1) — 10 years** |
| 13 | **Leaving certificate counterfoils issued to outgoing pupils** | **C(1) — 10 years** |
| 17 | Catalogue and attendance registers of pupils and staff | C(1) — 10 years |
| 21 | Ledger of receipts and expenditure | C(2) — 5 years |
| 23 | Answer books of annual examinations | D — 18 months after the result is declared |

The register is **permanent**; the certificates derived from it are retained only ten years. That
asymmetry is the whole architecture: **the register is the record of truth and the certificate is a
disposable extract of it.**

Preservation periods for other states: **NOT FOUND.** Kerala Ch. VI mentions preserving private-study
examination records separately and preserving the affidavit and confirmation letter with the leaving
certificate (r.24.4), but no general retention schedule was located in the chapter read.

## C.4 The TC/register relationship — the brief's hypothesis, verified

**Hypothesis: "a TC transcribes a register entry, and a countersignature is a register match."**

**First half: VERIFIED at level A, on the face of the prescribed forms themselves.**

- Maharashtra's prescribed leaving certificate (Appendix Four) ends with the printed certification
  **"Certified that above information is in accordance with the school register."** The signature is
  attesting the *match*, not the facts.
- Maharashtra's LC opens with **"Register No. of the Pupil"** — the certificate carries the register
  key.
- CBSE's prescribed TC (Annexure-I) takes the DOB **"according to Admission Register"** (field 6) and
  carries **Admission No.** in its header.
- CBSE Affiliation Bye-Laws 8(iii): a TC from the last school *"must be produced **before his name can
  be entered in the Admission Register**"* — the incoming certificate is the source of the new register
  entry. And 8(iv): *"In no case shall a student be admitted into a class higher than that for which he
  is entitled according to the transfer certificate."*
- Maharashtra r.26.2: the register's DOB is entered **from the leaving certificate**. Kerala r.3(2):
  a sanctioned correction must be carried into *"the Admission Register and the other connected records
  of the schools previously attended by the pupil as well as in the school in which he was studying at
  the time."*
- CBSE Examination Bye-Law 69.1/69.2 requires, for any name or DOB correction, **"portion of the page of
  admission and withdrawal register of the school where the entry has been made"** — the board treats
  the register page, not the certificate, as the proof.

**So the register→certificate→register chain is statutory, not conventional.** A pupil's identity
propagates: register (school A) → leaving certificate → register (school B). Each certificate is a
signed snapshot of a register row at a moment in time, and its authority is entirely derivative.

**Second half — "a countersignature is a register match": NOT VERIFIED.** No rule read describes what
the countersigning officer actually checks. What the rules do establish (**A**) is *when*
countersignature is required — an inter-State move (Bihar s.272, Maharashtra r.22.1) or entry from an
institution outside the receiving board (CBSE 8(vii)) — i.e. **precisely the cases where the receiving
school cannot itself reach the issuing school's register.** That is strong circumstantial support for
reading countersignature as an external authentication standing in for a register check, but it is an
inference (**D**) and must be labelled as one. The Maharashtra provisional-admission valve (r.22.1)
also shows countersignature is treated as *confirmatory*, not as a precondition to the child sitting in
class.

## C.5 What this means for the product

1. **Model the register as the primary entity and the certificate as a generated, versioned extract of
   it.** Every certificate should record which register row and which version of that row it rendered.
2. **Never let a certificate be edited independently of the register.** Maharashtra's LC carries a
   printed warning that an unauthorised change risks rustication (**A**); CBSE requires the register
   page as proof for any correction (**A**). Corrections must flow register → certificate, never the
   reverse.
3. **Admission numbers are permanent and are reused on re-admission** (CBSE 8(ii), **A**).
4. **Retain register data permanently; certificate copies may age out** (Maharashtra Annexure 15,
   **A**). This is a data-retention requirement, not a preference.
5. **Register schema is jurisdiction-specific** (CBSE 8(i) delegates the form to the State/KVS/NVS,
   **A**). Ship the state form as configuration, not the schema as a constant.
6. **The caste field is legal evidence with a multi-generational life** (§B.8). It needs provenance,
   entry date, author and an immutable audit trail — and it is restricted to Backward-Class pupils in
   the Maharashtra forms (**A**).
7. **Correction windows differ by jurisdiction and must not have a shipped default** (CBSE 2→5 years,
   Maharashtra never-after-leaving, Kerala 15 years — all **A** except the current CBSE figure).
8. **Never hard-block admission on a missing TC** (RTE s.5(3) first proviso, **A−**; Maharashtra r.18
   and r.22.1 provisional admission, **A**).
9. **Never hard-block TC generation on unpaid fees** — disclose dues on the document instead (§B.1).

---

# NOT FOUND — the explicit register of what is NOT established

Nothing in this list may be asserted to a school by the software. Each entry names what was searched.

## Method note — why this list is long

Two tooling failures shaped this research and must be recorded honestly:

1. **The session's WebSearch budget (200/200) was exhausted before this task began**, so keyword
   discovery had to be improvised through a Brave-over-curl helper that was itself repeatedly
   rate-limited (HTTP 429), and through indiankanoon's internal search.
2. **Several primary hosts block automated fetching**: `indiacode.nic.in`, `cbse.gov.in`,
   `education.gov.in` and `legislative.gov.in` return **403** to the HTML fetcher (though
   `cbse.gov.in` PDFs *were* retrievable by curl, which is how the bye-laws above were read).
   `passportindia.gov.in` returned 404 on the document-advisor path.

Several "NOT FOUND" entries below are therefore gaps of **discovery**, not evidence of **absence**.
They are worth a second pass with search restored.

## Part B gaps

- **CBSE's current (2026) Examination Bye-Law 69.2(iv) time limit for DOB correction.** Two years in the
  Dec-2004 text; five years per a 2018 Delhi HC judgment. Searched: cbse.gov.in current bye-laws (403 to
  HTML fetch; the 2018 Affiliation Bye-Laws PDF retrieved but is a scanned image with no text layer).
- **CBSE's current fee schedule** for duplicates, migration, provisional and correction. Annexure-II
  figures above are Dec 2004. Searched: cbse.gov.in/cbsenew/{duplicate,migration,certificate,documents}.html
  (all 404); cbseit.in/cbse/web/dcs/ (503).
- **CBSE's current migration-certificate process** — whether it ships with the result package or is
  applied for from a Regional Office, and whether DigiLocker delivers it.
- **Whether any state board delegates migration-certificate issuance to schools.** No state board
  regulation on migration certificates was read (MSBSHSE, UP Board, RBSE, BSEB, KSEAB, TN DGE, CISCE all
  unchecked).
- **Any prescribed format for a bonafide certificate**, anywhere. Absent from the enumerated form lists
  of the Kerala and Maharashtra codes and from the CBSE bye-laws, which is suggestive but not
  conclusive.
- **Any prescribed format for a standalone character/conduct certificate.**
- **Any prescribed format for a study certificate** in TN, AP, Telangana or Karnataka; and any rule text
  defining it or distinguishing it from a bonafide certificate.
- **Any rule restricting adverse or stigmatising remarks about a minor** on a school certificate. RTE
  ss.16 and 17 were read and do **not** cover it. NCPCR guidance and JJ Act provisions not searched.
- **The governing rule, contents and validity of a board "provisional certificate"** — only its
  existence and archival fee are established.
- **Any limit on the number of duplicate TCs** a school may issue. (CBSE contemplates duplicate *and*
  triplicate at board level, so a cap would likely be wrong.)
- **Whether Maharashtra still requires LC entries to be "in manuscript and not typewritten"**
  (Appendix Four note 2, 1979 text). Directly affects printed/computer-generated certificates in
  Maharashtra.
- **Whether a school leaving certificate is accepted DOB proof for passport, PAN or Aadhaar.** Widely
  claimed; nothing verified. passportindia.gov.in document-advisor 404; UIDAI and PAN lists not reached.
- **The full field list of the Tamil Nadu matriculation TC (Annexure-V)** — only serial 8 was quoted in
  the judgment that reproduced it.
- **The full text of Delhi School Education Rules 1973 r.139** and Delhi's prescribed TC form, if any.
- **Prescribed TC forms for UP, Karnataka, Punjab, Haryana, Rajasthan, Telangana, AP, West Bengal,
  Gujarat, Madhya Pradesh.**
- **Countersignature rules for any state other than Bihar and Maharashtra.**
- **Per-service document checklists** for caste/income/domicile on Aaple Sarkar, TN e-Sevai, MeeSeva,
  Nadakacheri and Delhi e-District — i.e. exactly *which* school document each state demands.
- **Statutory competent-authority provisions** for caste/income/domicile certificates in any state
  (portal pages reached; the underlying Acts/Rules not read).
- **Whether a school head is ever asked to countersign or attest a revenue-certificate application.** No
  evidence found either way.
- ***Kumari Madhuri Patil v. Addl. Commissioner, Tribal Development*, (1994) 6 SCC 241** — cited at
  second hand through a 2026 Bombay HC judgment; the original was not read.
- ***Janabai Himmatrao Thakur v. State of Maharashtra*, 2019 (6) Mh.L.J. 769 (FB)** — the Full Bench
  authority on Maharashtra LC/General Register corrections. Citation captured; text not retrieved.
- **Any state's RTE s.12(1)(c) admission document list** (relevant to income certificates).

## Part C gaps

- **Register preservation periods for any state other than Maharashtra.** Kerala Ch. VI contains no
  general retention schedule.
- **A rule requiring a TC to cite the register *page* number.** The register *key* (Admission No. /
  Register No.) is on both prescribed forms read; a page-number rule is not established.
- **What a countersigning officer actually verifies.** No rule read describes the check. The
  "countersignature = register match" reading is an inference (**D**), supported only circumstantially
  by *when* countersignature is required.
- **"Scholar Register" as a rule-defined term.** Common in usage; not found defined in any rule read.
- **The Kerala Admission Register Form 4 field list** — rule 2 specifies the entries but the form itself
  was not read.
- **Any national/central rule mandating school registers.** The mandate found is state rules + board
  bye-laws; no central statutory mandate was located, and RTE does not appear to impose one.

<!--PART_A_NOTFOUND-->
