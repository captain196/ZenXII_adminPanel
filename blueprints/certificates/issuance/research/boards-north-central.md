# State boards — north & central India: what the BOARD layer requires of a school-issued certificate

**Scope** UPMSP (UP) · PSEB (Punjab) · BSEH/HBSE (Haryana) · RBSE (Rajasthan) · HPBOSE (Himachal) ·
JKBOSE (J&K) · UBSE (Uttarakhand) · MPBSE (Madhya Pradesh) · CGBSE (Chhattisgarh)

**Researched / verified 2026-09-12.** Companion to `north-india.md` (state RECOGNITION) and
`west-central-india.md`. Those two answered "who may issue". This one answers "what the *board*
demands be on the paper".

| | meaning |
|---|---|
| **A** | the board's own regulation/bye-law text was read directly |
| **B** | the board's official portal, form or circular states it |
| **C** | a credible secondary source |
| **D** | unverified or single weak source — **never encode** |
| **NOT FOUND** | searched and not established — *a result, not a failure* |

> **Carried forward, not re-litigated.** Eligibility to ISSUE flows from state RECOGNITION, not from
> board affiliation; boards govern EXAMINATIONS. CBSE abolished TC countersignature across five
> circulars 2014–2025. Migration Certificates are board documents — a school never issues one.
> Recognition is per class-range and lapses on events as well as dates.

> ## The one finding that changes the build
>
> **CBSE abolished TC countersignature. HPBOSE did not — and its rule is stricter than the CBSE one
> ever was.** HPBOSE Examination Regulation **3.5.7**, amended as recently as **18 January 2012**,
> requires a TC from any other board to be countersigned by an officer not below District Education
> Officer / District Inspector of Schools, and says in terms that *"the scholar **shall not be
> admitted** to an institution without such countersignature."*
>
> So "countersignature is dead" is **false as a national statement**. It is dead for CBSE and alive
> for HPBOSE. A single global `countersignatureRequired = false` is a wrong rule in Himachal
> Pradesh. The correct model is **per-board**, and the *receiving* board's rule is the one that
> binds — HPBOSE's rule fires when a student arrives *into* an HPBOSE school, whatever board the
> TC came from.

---

## Summary table

| Board | Prescribes a TC format? | Mandatory printed fields | Countersignature of a school TC | Post-issuance duty | Evidence |
|---|---|---|---|---|---|
| **UPMSP** (UP) | **NOT FOUND** — regulations exist but are not published online | **NOT FOUND** | **NOT FOUND** | Advance online registration of every class-9/11 student; school identified by district+school code | B / NOT FOUND |
| **PSEB** (Punjab) | **NOT FOUND — technical block** | NOT FOUND | NOT FOUND | NOT FOUND | — |
| **BSEH** (Haryana) | **No** — confirmed absence in the Board's own regulations | **NOT FOUND** | **Not for issuance.** DEO countersignature required only when an SLC is *used as evidence* (record correction; Open-School age proof) | Maintain Admission-and-Withdrawal Register, Attendance Register and SLC, producible in original; enrol migrating students within 20 days | **A** |
| **RBSE** (Rajasthan) | **NOT FOUND** | **NOT FOUND** | **NOT FOUND** | NOT FOUND | — / B (migration fee) |
| **HPBOSE** (Himachal) | **No format — but a prescribed Scholar's Register (Annexure-I)** | **Affiliation number on all official stationery** | **YES — mandatory and blocking.** Officer not below DEO / DIS; carve-out for HPBOSE→HPBOSE | Report every withdrawal/striking-off to the Board **within 15 days**; file countersigned copies with exam admission forms | **A** |
| **JKBOSE** (J&K) | Reg. 19(x) names a *"form prescribed"* — **the form itself NOT FOUND** | NOT FOUND | NOT FOUND (only a **[D]** claim) | **No TC may issue until the Board sanctions the migration** (reg. 19(I)) | **A** (prior stream) |
| **UBSE** (Uttarakhand) | **NOT FOUND — technical block** | NOT FOUND | NOT FOUND | NOT FOUND | — |
| **MPBSE** (MP) | **No** — confirmed absence | State rule, not board: RTE r.19 Form-4 must carry **recognition number (stamped prominently) + DISE code** | **No — confirmed absence, any school type** | NOT FOUND (state runs TCMS instead) | **A** (prior stream) |
| **CGBSE** (Chhattisgarh) | **No full proforma** | **YES — the TC must state that the school is CGBSE-recognised and print its मान्यता कोड** (Manyata Nirdesh cl. 16(4)) | **No — confirmed absence** | Receiving side: school (never the parent) completes online ग्राह्यता | **A** (prior stream) |

**Two boards out of nine mandate something printed on the certificate**, and they reached it
independently: **CGBSE** (recognition statement + recognition code on the TC itself) and **HPBOSE**
(affiliation number on all official stationery, which a TC is). **MP** requires the same thing by
*state* rule on a different document. Three jurisdictions converging on "print the recognition
identity" is the strongest cross-state pattern in the corpus and the safest default to ship.

---

## 1 · HPBOSE — Himachal Pradesh Board of School Education

The most completely documented board in this study, because its **Examination Regulations** were
retrieved and text-extracted in full.
**Source [A]** https://hpbose.org/Admin/Upload/Regulations/EXAMINATION.REGULATIONS.pdf
(reached via https://hpbose.org/ExamReg.aspx)
**Affiliation source [A]** *Rules and Regulations with application forms for 9th–12th Affiliation
(Fresh/Up-gradation and Renewal) of Private Institutions 2026-2027*,
https://hpbose.org/Admin/Upload/Noti.19.09.2025.10.PDF (via https://hpbose.org/AffiliationRegulations.aspx)

### 1.1 Q3 — countersignature: YES, and it blocks admission [A, verbatim]

**Reg. 3.5.7**, marked as amended vide the **99th Board Meeting, item 12(1), dated 18.1.2012**:

> *"In case a scholar from an institution affiliated to any recognized Board/University seeks
> admission to an institution affiliated to the Board, the Transfer Certificate and detail marks
> certificate of lower examination of the previous institution of such a student **shall be
> countersigned by an officer not below the rank of District Education Officer / District Inspector
> of Schools of the Education Department or any officer authorized for this purpose by the Education
> Department of the State/Union Territory concerned and the scholar shall not be admitted to an
> institution without such countersignature.** Copy of such documents shall be submitted to the Board
> by the head of the concerned institution alongwith examination admission forms. **Counter
> signatures are not necessary if candidate is admitted from one institution affiliated to the Board
> to another.**"*

Three things to note, because each one is a modelling decision:

1. **The trigger is crossing a board, not crossing a state line.** A CBSE school in Shimla feeding an
   HPBOSE school in Shimla is a countersignature case; two HPBOSE schools in different districts are
   not. This is the same shape as CBSE's old Annexure-I rule (general requirement, same-board
   carve-out) — HPBOSE simply never repealed it.
2. **It is the *receiving* board's rule.** The school that must obtain it is the admitting HPBOSE
   school; the officer who signs sits in the previous school's state education department. A UI that
   asks the *issuing* school to obtain a countersignature before handing over the TC has the actor
   wrong.
3. **It is fail-closed by its own terms** — *"shall not be admitted … without"*. That is the opposite
   posture from Delhi r.139(3), where a missing countersignature only makes admission provisional.
   **Do not generalise Delhi's forgiving rule to Himachal.**

A second, narrower countersignature sits at **reg. 4.9.2** [A]: a private candidate from a State/UT
where the Middle Standard examination is not conducted by a Board/University must get the
certificate/SLC issued by his institution *"counter-signature[d] of the District Education Officer or
any equivalent officer of the State/U.T."*

### 1.2 Q1 — format: no TC proforma, but a prescribed register [A]

**Reg. 3.5.1**: *"A scholar's register in the form prescribed by the Board (**Annexure I**) or an
admission and withdrawal register in the form prescribed by the Education Department shall be
maintained by the institution where the name of every scholar joining an institution shall be
entered."*

**Annexure-I is the only annexure in the Examination Regulations** (verified by scanning the whole
extracted text for annexure headings). There is **no Transfer Certificate proforma**. So HPBOSE
mandates the *use* and *countersignature* of a TC in detail while prescribing **no field list for
it** — a distinction the product must keep, because it means there is nothing to validate a TC's
fields against in HP.

Annexure-I Scholar's Register fields, transcribed [A] — **Section A:** registration no. · name ·
caste · religion · nationality · date of birth · age on date of first admission (year, month) ·
father's name · mother's name · occupation · permanent address · correspondence address · local
guardian name and address · *"the last school, if any, which the scholar attended before joining the
school"* · *"the highest class from which the scholar was fit for promotion on leaving his last
school"* · date of marriage if married. **Section B:** date of admission · date of leaving · cause of
leaving …

⇒ Section B is effectively the data a TC is drawn from, and item 14 of Section A is the field that
makes reg. 3.5.4 enforceable.

### 1.3 Q2 — what must be printed [A]

Affiliation Regulations, condition **(f)**:
> *"The school granted affiliation shall **depict the affiliation number on its official stationery**
> and a copy of the affiliation letter shall be conspicuously displayed on the notice board of the
> school. This certificate/document should reflect not only the affiliation granted, it should clearly
> state that affiliation is granted for particular classes and streams. This document shall also
> clearly mention the maximum number of students that can be admitted in each class and in each
> subject."*

A TC printed on school stationery therefore carries the affiliation number in HP. Condition **(e)**
confirms affiliation is granted **per class and per stream**, with a per-class/per-subject admission
cap — the same "recognition is scoped, never blanket" pattern already established for recognition.

### 1.4 Other TC rules, all [A]

- **3.5.3** — a scholar who has attended another institution may not be entered in the scholar's
  register until *"an authenticated copy of the transfer certificate from his last school"* is
  produced.
- **3.5.4** — *"In no case shall a scholar be admitted to a class higher than that for which he is
  eligible according to the Transfer Certificate."*
- **3.5.5** — no migration between affiliated institutions **after the name has been sent up for the
  Board examination**; waivable only by the **Chairman**, in special circumstances.
- **3.5.6** — *"A scholar leaving his institution at the end of a session or who is permitted to leave
  his institution during the session shall, **on payment of all dues**, shall receive an authenticated
  copy of the Transfer Certificate up-to-date. **A duplicate copy may be issued if the Head of the
  institution is satisfied that the original is lost but it shall always be so marked.**"*
- **3.5.8** — wilful misrepresentation by parent or scholar at admission: the Head *"may punish him by
  expulsion and report the matter to the Board."*

**Q7 answered at [A].** HPBOSE 3.5.6 is the **only explicit duplicate-marking rule found in any of
the nine boards**: a duplicate school TC must be marked as a duplicate, and the issuing test is the
Head's satisfaction that the original is lost. That is directly implementable.

⚠️ **3.5.6 conditions the TC on payment of all dues.** For classes IX–XII that is the board's own
rule. For elementary classes RTE s.5(3) points the other way. Same building, opposite defaults —
the J&K conflict recorded in `north-india.md` §6.5 repeats here, and is again **recorded, not
resolved**.

### 1.5 Q4 — post-issuance duty [A]

Affiliation Regulations, condition **(h)**:
> *"The schools shall **within 15 days of the withdrawal of a student or the striking off his name
> from the rolls** of the school, inform the Board about the same along with complete details such as
> parentage, date of birth, residence address etc."*

Plus **3.5.7**'s filing duty (countersigned TC + DMC copies go to the Board with the exam admission
forms) and **3.5.1**'s register-maintenance duty. Condition **(g)**: class/subject/stream-wise
student list filed online by **31 May**, which is also the admission cut-off (extendable to 30 June
with late fee in special cases).

⇒ **In HP, issuing a TC creates a 15-day reporting obligation to the Board.** That is a real workflow
the ERP can own, and the only "tell the board you issued it" duty found in the nine.

### 1.6 Q6 — Migration Certificate [A]

**Reg. 12.4.1** — granted by the **Controller of Examination** to a student migrating to another
Board/University after passing a Board examination, on the prescribed form and fee. The migration
fee is collected **from all 10+2 candidates at the time of admission**, so the certificate issues on
demand. **12.4.2** extends it to failed / compartment / re-appear candidates. Applications more than
one year after the session need a notarised affidavit (or attested certificate No. 1 on the migration
form) that the applicant did not join any HP college or recognised institution.
**12.4.3** — a **duplicate** migration certificate is issued by the Controller of Examination on fee
plus the same affidavit.

Confirms the settled rule: **the school never issues a Migration Certificate in HP.**

### 1.7 Q7 — duplicate BOARD certificates [B]

https://hpbose.org/InstructionsDupCert.aspx — duplicate certificate **₹1,200 / ₹2,400 / ₹4,800** for
the 1st/2nd/3rd copy; duplicate fail/compartment card ₹400 / ₹600; **after the 3rd copy no duplicate
is issued in any condition**; application attested by any recognised school; FIR/DDR copy for loss,
affidavit if destroyed otherwise, surrender of the damaged certificate if legible; issued within two
weeks; sent by registered post. (Reg. **12.5** in the regulations carries the same scheme at [A].)

### 1.8 Q8 — board affiliation is separate from, and gated on, the state [A]

- **16.2.13** defines *"No Objection Certificate"* as a letter issued by the appropriate authority of
  the **Education Department of the State Government** for affiliation of the school to the Board.
- **16.6.1** — a school already affiliated to HPBOSE that wants another board's affiliation needs an
  **NOC from HPBOSE** (₹1,50,000, non-refundable, and only after seven continuous years of
  provisional affiliation); a **new** school seeking another board's affiliation takes its NOC from
  the **H.P. Education Department**.
- Affiliation condition **(d)**: *"No school shall admit any student unless it is affiliated to H.P
  Board of School Education."*
- **16.13.3** — provisional/regular/permanent affiliation may be withdrawn for serious irregularities.

⇒ Two distinct instruments, held simultaneously, each able to kill the other's practical value.

---

## 2 · BSEH / HBSE — Board of School Education Haryana, Bhiwani

**Source [A]** *Rules & Regulations*, 116 pp., https://bseh.org.in/uploads/files/9828270854fa03ddc9174ec82e57e592.pdf
— downloaded and text-extracted locally (WebFetch could not parse it; `pdftotext` could).

### 2.1 Q8 — the cleanest statement in the corpus that affiliation ≠ recognition [A]

**Reg. 5(a)**: *"The institution applying for affiliation should have **obtained recognition from the
competent authority of Department of Education, of the state**."*

**Reg. 8** — affiliation granted by the **Chairman**. **Reg. 9** — *permanent* affiliation goes only
to *permanently recognised* schools (₹2,000 annual continuation fee); **temporary affiliation is
annual**, tracking temporary recognition. **Reg. 7** prices affiliation by the recognised class range
(₹8,000 up to class 8; ₹20,000 up to 10 or 12).

⇒ Haryana makes the dependency explicit and *fee-visible*: the board's affiliation is a second,
downstream grant whose class range and term are inherited from the state's recognition. This is the
best available corroboration of `HOW_ELIGIBILITY_ACTUALLY_WORKS.md`'s core claim.

Note also **reg. 5(m) / (o)**: a school in one building may not be affiliated to two boards
concurrently, and *"the Branch of the school running in a separate building shall be treated as a new
school for affiliation purpose."* — affiliation, like recognition, is **premises-bound**.

### 2.2 Q1 / Q2 — no TC format, nothing mandated in print [A — confirmed absence]

The regulations carry **no SLC/TC proforma**. The only annexures in the document (Annexure-A and
Annexure-B) are schedules of exam concessions for candidates with disabilities. No requirement to
print an affiliation number, board name or school code on any school-issued document was found.

⇒ Haryana's SLC proforma remains the **departmental** form already recorded in `north-india.md` §4.4
(fields include SRN, UDISE code, *"Signatures and seal of Head of Institution"*, and an unattributed
*"Entries checked and found correct"* line). **Its legal basis is still NOT FOUND** — and it is now
confirmed *not* to come from the Board.

### 2.3 Q3 — countersignature: not for issuance, but real in two evidentiary uses [A]

There is **no rule requiring a school SLC to be countersigned before issue or before admission**.
But the Board twice demands a countersigned SLC when one is offered *as proof*:

- **Certificate-correction procedure, clause (v)**: *"The original certificate awarded by any other
  recognized boards and **school leaving certificate (SLC) countersigned by the District Education
  Officer/relevant authority** shall be acceptable to the Board."*
- **Haryana Open School, reg. 101 eligibility**, age proof: *"School leaving certificate of
  Government/Recognized School **duly countersigned by the District Education Officer or equivalent
  rank of the area concerned**."*

⇒ **The honest product statement for Haryana is: countersignature is not required to issue a TC, but
an un-countersigned TC may be refused by the Board later when the family needs it for a correction or
an Open-School admission.** That is a *warning*, not a gate. Encoding it as a blocking requirement
would be wrong; suppressing it entirely leaves families stranded.

### 2.4 Q5 — admission from another board or state [A]

- **Reg. 5(k)** — the Head, admitting a migrating student *"irrespective of whether the migration is
  from within the State or outside the State, shall ensure authenticity of the documents produced
  before him/her … and shall be **personally responsible** for the same."* Students entering class X
  or XII after passing IX or XI from another state or CBSE *"must get enrolled **within 20 days**
  after getting SLC from the previous school."*
- **Reg. 5(l)** — the verification is filed with the Board as an attested photocopy with the enrolment
  return, plus an undertaking by the Head owning responsibility; disciplinary liability follows an
  adverse finding.
- **Reg. 13(I)(B)(iii)** — a student from a Sainik School, an ICPS-affiliated school, an Anglo-Indian
  school or a foreign institution needs an **SLC attested by the Principal** of that school (or an
  equivalent pass certificate) **and** must be *"adjudged on merit to be a fit student … by the
  District Education Officer, concerned."*
- **Reg. 13(I)(B)(iv)** — residual cases are decided by the **DEO** on merit.
- **Reg. 13(I)(D)** — migrants admitted within the prescribed dates or **within 20 days from the last
  date of issue of the SLC**; *"at the time of issuing of SLC the student should be on roll in the
  School"*; the Head files the enrolment return within **15 days from the date of admission**.
- **Reg. 13(II)(b)** — for classes other than Secondary/Senior Secondary, **Heads of Institutions are
  themselves authorised** to allow migration. *"There is no restriction on migration/change of school
  … either within the State or from outside."*

### 2.5 Q6 — Migration Certificate [A]

**Reg. 13(II), Note**: *"If school leaving certificate is received along with the certified copy of
Board Certificate, Enrolment Number will be issued after verification of particulars from School
Leaving Certificate, **migration certificate will be obtained in the absence of father's name, date
of birth etc. only**."*

**Reg. 50 (Submission of Migration Certificate)**: *"A candidate who has passed the qualifying
examination from any Other Board/University will be required to submit a Migration certificate from
the concerned board/University… In case the Migration Certificate is not received at least fifteen
days before the commencement of the examination, his/her candidature will be cancelled. In the
absence of the Migration Certificate the admit card/Roll No. slip … will not be issued."*

⇒ Two different objects. **Inbound from another board: migration certificate is mandatory and its
absence cancels candidature** (reg. 50). **Within BSEH: it is a fallback used only when the SLC lacks
particulars** (reg. 13(II)). The 2024 reading recorded in `north-india.md` §4.5 captured the second
and missed the first. Corrected here.

BSEH issues its own migration certificates: ₹300 via the Antyodaya Saral Portal, Family Identity
Number mandatory. [A] https://bseh.org.in/certificate-branch

### 2.6 Q7 — duplicates [A]

**Reg. 66** covers duplicates of **Board examination certificates only** — application attested by
the head of the school from which the candidate passed (or, for private candidates, by a gazetted
officer of the Education Department / university officer / Board member); no one may apply on
another's behalf; normally sent by registered post; no attestation needed for detailed marks
certificates. **No rule on duplicate school-issued SLCs, and no marking requirement.** [A — absence]

### 2.7 Q4 — post-issuance duties [A]

The Board does not ask to be told when an SLC is issued, but it **requires the underlying records to
exist and to be producible in original**. **Reg. 14(II)(i)–(iii)**: late enrolment returns must be
accompanied by certified copies of the **Admission and Withdrawal Register, Attendance Register and
S.L.C.**, and *"The school will also submit its original record for verification."*

And **reg. 21 — the sharpest enforcement found anywhere in this study**: if an SLC or lower-exam
certificate is *"found bogus/fake"*, a **₹1,000 per candidate** penalty for legal costs where the
matter goes to court, **₹1,00,000** on the institution (in government schools, disciplinary action
recommended against the Headmaster/Principal), **₹3,00,000** on repetition, and on a **third
occurrence the affiliation is withdrawn**.

⇒ **In Haryana the school that issues a false SLC loses its affiliation.** That is the strongest
available argument for making TC issuance an audited, non-repudiable action in the ERP rather than a
free-text print job.

---

## 3 · CGBSE — Chhattisgarh (already established; carried forward)

Fully researched in `west-central-india.md` §4.6–4.8 at **[A]** against the *नवीन मान्यता निर्देश*
क्रमांक **3335/अशास. मान्यता/2026 dated 7/9/2026**,
https://cgbse.nic.in/Documents/2026/new_manyata_26.pdf

- **Q2 — the strongest printed-field mandate in the nine.** Clause **16(4)**: the school's name board,
  admission application form, letterhead, **marksheet and Transfer Certificate** must each clearly
  state that the institution is **CGBSE-recognised** and print its **मान्यता कोड (recognition code)**.
- **Q1** — no full TC proforma. **Do not synthesise one.**
- **Q3** — countersignature **NOT FOUND for any school type**; the only reference traced was a
  non-authoritative article citing a *"जिला विद्यालय निरीक्षक"*, an office that does not exist in the
  CG structure **[D]**. Enforce nothing.
- **Q5** — ग्राह्यता (Grahyata): the **school, online, never the student or parent** (*"किसी भी स्थिति
  में छात्र या पालक को ग्राह्यता हेतु मण्डल में न भेजें"*), on **original TC + original Migration
  Certificate + original marksheet** plus principal-attested copies; provisional admission permitted
  meanwhile against a declaration.
- **Q6** — CGBSE regulation governing the **issue** of migration certificates: **NOT FOUND**; only the
  duplicate service at https://vidia.cgbse.nic.in/fms/ [B].
- **Q8** — yes: classes 9–12 are gated by CGBSE under the **माध्यमिक शिक्षा मण्डल मान्यता विनियम 1994**,
  separately from the DEO's RTE recognition for 1–8.

---

## 4 · MPBSE — Madhya Pradesh (already established; carried forward)

Fully researched in `west-central-india.md` §3.5–3.8.

- **Q1 / Q3 — both confirmed absences at [A].** The MP RTE Rules 2011 contain **zero** occurrences of
  "transfer", "transfer certificate", "TC" or "migration"; the 2017 recognition Rules contain no
  student-certificate provision; MPBSE publishes no TC format and no countersignature requirement for
  any school type. **Do not build a countersign gate for MP.**
- **Q2 — the enforceable one is a state rule, not a board rule.** MP RTE **r.19 proviso**: *"the
  recognized school shall stamp prominently on the certificate the number of recognition certificate
  issued by the recognition certificate issuing authority"* — on **Form 4**, the Certificate of
  Completing Elementary Education, which also carries the **DISE code**, school seal and signatory's
  designation. **Extending this to TC/bonafide/character is not supported by any rule found** —
  implement it there as a default-on convention, never as a legal assertion.
- **Q4** — MP runs a state **TCMS** portal keyed to UDISE code + Samagra ID; the authoritative TC for a
  portal-registered school is generated there, not by the school's own ERP **[C]**.
- **Q6** — MPBSE issues migration certificates through the school, ~₹400 **[C]**; the governing
  regulation number is **NOT FOUND**. Real-world defect: MP Board migration certificates have issued
  **without serial numbers**, blocking college admissions — argues for a mandatory, non-blank, unique
  serial on anything migration-adjacent that we print.
- **Q8** — yes, three independent levers: DPI/Joint Director *manyata* (2017 Rules), the Board's own
  **s.8(f)** recognition under the M.P. Madhyamik Shiksha Adhiniyam 1965, and the DEO's RTE
  certificate for I–VIII.

---

## 5 · JKBOSE — Jammu & Kashmir (already established; carried forward)

Fully researched in `north-india.md` §6.3–6.5 at **[A]** against the **J&K State Board of School
Education Regulations 1992**.

- **Q1** — reg. **19(x)** requires the Head to grant a transfer certificate *"in the form prescribed"*.
  **The form is not in the appendix list, which was scanned in full — [A] explicit absence.** The
  board names a format it does not publish.
- **Q3** — **NOT FOUND.** A ZEO/CEO countersignature appears only as an untraced search-synthesis claim
  **[D]** and is explicitly not encoded.
- **Q4 — the strictest post/pre-issuance duty in India.** Reg. **19(I)**: *"The Head of the Institution
  concerned **shall not issue the transfer certificate until the migration has been sanctioned by the
  Board**."* Plus reg. 19(viii) migration once per academic year; 19(v) join within 15 days; 19(ix) fee
  apportionment between outgoing and receiving school.
- **Q5** — reg. **9**: an incoming student from another University/Board *"shall not be registered
  unless their applications for registration are accompanied by a Migration certificate"*, waivable on
  a **reciprocal basis** for sister Indian boards against conduct and no-dues certificates.
- **Q6** — yes. Inter-school migration certificate **₹120**; inter-board/university **₹150**, forwarded
  by the Head, *"ordinarily issued within a week"*, **sent by registered post**.
- **Q7** — reg. **21**: a duplicate inter-board migration certificate issues on payment of the original
  fee and **only on an affidavit** satisfying the Secretary. **No marking requirement is stated** —
  unlike HPBOSE 3.5.6.
- **Q8** — yes, and it is **chained**: reg. **8** grants recognition *"in which subject, on what
  conditions and for what examination"*; reg. **7(ii)** makes it conditional on continued Education
  Department recognition, so the exam-eligibility grant dies with the operating licence.

⚠️ **Staleness.** JKBOSE's own regulations page lists only this 1992 text, uploaded 2018
(http://jkbose.jk.gov.in/BoseRegulations.html — **the host timed out on every attempt in this
session**, so the page could not be re-checked for anything newer on 2026-09-12).

---

## 6 · UPMSP — UP Board (Madhyamik Shiksha Parishad, Prayagraj)

### 6.1 What was newly established [A / B]

- **The Board's Regulations exist and are numbered, but are not published on its site.** The Board's
  own prescribed class-9 registration proforma carries an exam category labelled
  *"विनियम-17(1) / under Regl-17(1)"*, proving a numbered regulation set in live use. No copy of the
  Regulations under the Intermediate Education Act 1921 is hosted at upmsp.edu.in. **[A]** for the
  reference, **NOT FOUND** for the text.
- **The class-9 registration proforma contains no Transfer Certificate field.** Full text extracted
  from https://upmsp.edu.in/Downloads/SchoolFormClass_9th_10th.pdf **[A]**. Its 29 items are: school
  code and name · candidate type (fresh / re-admission in class 9) · minority status · **school
  registration number (SR Number)** · name, mother's name, father's name in English capitals and in
  Hindi · address · nationality · sex · category · medium · exam type · date of birth · seven subject
  slots · vocational trade · Aadhaar · mobile · disability type · e-mail · and a parent's declaration
  that the child has not also applied *"from any other school recognised by the Parishad or from any
  other education board"*. Signature of candidate and father/guardian; the Principal is *"directly
  responsible"* for upload errors.

  ⇒ **This weakens, and does not corroborate, the [C] news claim** in `north-india.md` §2.3 that TC
  upload is mandatory at class 9/11 registration. It may still be true of the **online module** while
  being absent from the **paper proforma** — but the primary artefact does not carry it, and the
  registration Vigyapti PDFs that would settle it
  (`Vigyapti_Class_09th_11th_Registration_2026-27_10092026.pdf`) are **scanned images with no text
  layer**. **Do not assert a UPMSP TC-upload rule.**
- **The Board's own instruction booklet for DIOS and centre superintendents contains no TC
  provision.** *निर्देश-2026* (344 KB of extracted text) was searched for स्थानान्तरण / टी.सी. /
  प्रतिहस्ताक्षर; zero hits — every "Vh0lh0" match is a government-order file-number suffix, not a
  transfer certificate. **[A]** — though its scope is exam conduct, so this is a bounded absence.
  https://upmsp.edu.in/Downloads/NIRDESH_PUSTIKA_2026_PDF.pdf
- **Q4 — the one real board-imposed duty [B].** https://upmsp.edu.in/Instruction.aspx: advance online
  registration of every class-9 and class-11 student is **mandatory for every Parishad-recognised
  school** as the precondition of sitting the 2027 High School / Intermediate examination. The school
  logs in with a **six-digit identity = two-digit district code + the school's own four-digit code
  used in Parishad examinations** (worked example on the page: district Mathura 05 + school 1275 =
  051275), with an 8-digit confidential password supplied **through the District Inspector of
  Schools**, resettable only by him.

  ⇒ **A UP school has a board-issued four-digit school code distinct from UDISE.** If the product ever
  prints a school code on a UP document, it must know which code it is printing.

### 6.2 Q8 — already settled, and it is the modelling hazard [A]

**UP Intermediate Education Act 1921, s.2(d)**: *"'Recognition' means recognition for the purpose of
preparing candidates for admission to the Board's examinations."* **s.7(4)** gives the Board power
*"to recognise institutions for the purposes of its examinations."*

**The brief's premise is verified.** In UP the board *is* the recognising authority for secondary,
and "recognition" denotes exam eligibility — nearer to what other states call affiliation.
Corroborated by the Board's own live usage: the registration page speaks of
*"परिषद् द्वारा नियमानुसार मान्यता प्राप्त प्रत्येक विद्यालय"* (every school recognised by the
Parishad), and the site carries a separate login captioned
*"सीबीएसई / सीआइएससीई / बीएसबी से मान्यता / संबद्धता हेतु"* — recognition/affiliation to other boards
being handled through the Directorate. **[B]**

⇒ A single `recognitionOrder` field means **exam eligibility** in UP and a **licence to operate** in
Delhi. Already flagged in `north-india.md` §2.1; this stream confirms it from the board's own side.

### 6.3 NOT FOUND for UPMSP

Q1 (TC format), Q2 (printed fields), Q3 (countersignature), Q6 (migration-certificate regulation),
Q7 (duplicates) — **all NOT FOUND.** The likely home for every one of them is the **Regulations under
the Intermediate Education Act 1921**, which are published as a printed volume and are not on the
Board's website. Two of the three site PDFs most likely to help
(`NotificationAutoRecognitionTermination2026.pdf`, the class-9/11 registration Vigyapti) are
**scanned images with no text layer** and no OCR tooling was available in this environment.

---

## 7 · RBSE — Board of Secondary Education, Rajasthan, Ajmer

### 7.1 What the Board actually publishes as "rules" — and it is not what the name suggests [A]

https://rajeduboard.rajasthan.gov.in/rules.htm (reached through the frameset at `contents.htm`; the
site's landing page is a frameset, which is why direct fetching has failed in earlier streams) lists
exactly three documents:

| listed as | file | what it actually is |
|---|---|---|
| अधिनियम 1957 | `rules/bser_act.pdf` | the constituting Act — **6.4 MB scanned image, no text layer** |
| राजस्थान सार्वजनिक परीक्षा (अनुचित साधनों की रोकथाम) अधिनियम 1992 | `rules/exam_act.pdf` | exam-malpractice Act |
| **विनियम (संशोधित 2004)** | `rules/viniyam2004.pdf` | **NOT school regulations** — downloaded and read: it is the *माध्यमिक शिक्षा बोर्ड, राजस्थान कर्मचारी सेवा विनियम, 2004*, the Board's **employee service rules** (recruitment, cadre, promotion), made under ss.21(4) and 36(1) of the 1957 Act **[A]** |

⇒ **RBSE publishes no student- or certificate-facing regulations at all.** A researcher who clicked
"विनियम" and reported it as the Board's regulations would have cited staff service rules as the
authority for a certificate requirement. Recorded because it is precisely the shape of error this
corpus exists to prevent.

### 7.2 The Affiliation Regulations 2016 — located at last, still unreadable [B for existence]

**सम्बद्धता सम्बन्धी विनियम — 2016** is at
**https://rajeduboard.rajasthan.gov.in/downloads/hand_book16.pdf** (linked from the Downloads page,
https://rajeduboard.rajasthan.gov.in/8.htm). The previous stream could only establish the title; the
file is now located and downloaded (3.4 MB, 38 pages).

**Its content is still NOT FOUND.** The PDF embeds a legacy Devanagari font while declaring itself
Helvetica, so both `pdftotext` and PyMuPDF return Latin garbage (*"Hand-Book / alwfl{--13,13-A,13-B"*)
rather than text. It is a substitution cipher that could be guessed at — **and deliberately was not**,
because a mis-transliterated legal clause is exactly the confident-wrong-rule failure this research
forbids. It needs OCR or a human reader.

The same defect blocks `ubse_recog` (§8) — both are Rajasthan/Uttarakhand-era government documents
produced the same way.

### 7.3 Q6 — migration certificate [B — the Board's own page]

https://rajeduboard.rajasthan.gov.in/vidhyarthi.htm — **विद्यार्थी सेवा केंद्र**:
**प्रवजन प्रमाण पत्र (migration certificate) ₹200**, duplicate marksheet (urgent) ₹200; applications
in duplicate on the prescribed proforma; for records **2001–2020** the document is issued **the same
day** subject to connectivity; **corrections are accepted only by the Board office itself**. Centres
listed at Ajmer (Board office) and in Jaipur, Chittorgarh, Bhilwara, Kota, Bundi, Udaipur, Rajsamand,
Jhalawar, Baran and others.

⇒ **Upgrades `north-india.md` §3.6 from [C] to [B]** and confirms the Board — not the school — issues
it. Also confirms **Q7 in part**: duplicates ("प्रतिलिपि प्रलेख") are a Board counter service with a
prescribed affidavit (`offorders/affadevite.pdf`) and instruction sheet (`downloads/nirdesh.pdf`); no
rule was found on how a duplicate must be *marked*.

### 7.4 Q8 — yes, a separate board recognition [B]

https://rajeduboard.rajasthan.gov.in/5.htm — recognition is required of *"all educational institutions
preparing regular students"* for the Secondary, Praveshika, Senior Secondary (Academic/Vocational) and
Varishtha Upadhyay examinations. Inspection teams report to a statutory **Recognition Committee**; the
Board finalises. *"Temporary recognition granted for 3 years with specific relaxation of 2 years.
Periodic inspection in 5 years is obligatory."* Separate application forms exist per class range and
per government/non-government status (`downloads/Recog-Form-Ka-Govt-Sec.pdf`, `…-NG-Sec.pdf`,
`ka-kha-Govt.pdf`, `ka-kha-NG.pdf`, `Recog-Form-Ga.pdf` for permanent recognition) — corroborating
per-class-range scope at [B].

### 7.5 NOT FOUND for RBSE

**Q1, Q2, Q3, Q4, Q5 — all NOT FOUND.** No RBSE TC format, no printed-field mandate, no
countersignature rule, no post-issuance filing duty, no cross-board admission rule was located in any
readable RBSE source. The Affiliation Regulations 2016 are the most likely home for Q2 and Q8 detail
and are blocked only by font encoding — **retryable with OCR, not settled.**

---

## 8 · UBSE — Uttarakhand Board of School Education, Ramnagar

**Everything remains NOT FOUND, and the reason is now precisely known rather than guessed.**

The forms page https://ubse.uk.gov.in/forms/ was fetched successfully and its documents enumerated
**[B]**. The two that matter both defeat extraction:

| document | URL | why it is unreadable |
|---|---|---|
| **Procedure (Duplicate Copy, Migration, Correction and Scrutiny)** | `https://cdnbbsr.s3waas.gov.in/s32dbf21633f03afcf882eaf10e4b5caca/uploads/2025/06/202506021287876625.pdf` | 6 pages, **scanned image, zero text** |
| **General and Compulsory condition for Recognition of Inter/High school** | `https://cdnbbsr.s3waas.gov.in/s32dbf21633f03afcf882eaf10e4b5caca/uploads/2025/06/20250602250371228.pdf` | 11 pages, legacy Devanagari font declared as Helvetica → **Latin garbage** |

Also on the page: **Duplicate FORM-D-1** and **Correction FORM-A** — form *numbers* now known, content
not.

⇒ The `north-india.md` §5.4 entry ("resisted extraction on two attempts") is upgraded from a vague
failure to a diagnosed one: **one is a scan needing OCR, the other needs a legacy-font mapping.**
Neither is an absence. **Q1–Q8 for UBSE: NOT FOUND, technical block, retryable.**

---

## 9 · PSEB — Punjab School Education Board

**Total block, and it is now identified.** `pseb.ac.in` and `www.pseb.ac.in` return **HTTP 403 with a
Cloudflare interstitial** — the response body is the *"Just a moment…"* JavaScript challenge page,
over http and https, with and without browser headers and referer. This is a **bot challenge, not an
access restriction and not an absence**: a human browser reaches the site.

The previous stream recorded "403 on every attempt". That is now diagnosed — and it means the block is
**defeatable from a normal browser session**, which is the cheapest way to close this gap.

One adjacent gain: **epunjabschool.gov.in is reachable after all.** The previous stream recorded a TLS
validation failure; the certificate is indeed invalid, but the content serves fine with certificate
verification disabled. https://epunjabschool.gov.in/school-recognition-guidelines.aspx now reads
**[B]**: RTE recognition is applied for **through the school's existing ePunjab portal login** —
*"Apply » School Recognition"*, most fields pre-filled from data the school already filed under other
ePunjab modules, documents uploaded, **fee paid online**, form submitted. Two linked PDFs
(`News/NotificationregardingonlineapplicationsofRTERecognition.pdf`,
`News/ProcedureforRTERecognition.pdf`) now return **HTTP 410 Gone**.

⇒ This is a **state recognition** finding, not a board one, and it belongs to `north-india.md` §9.5 —
recorded here because that entry says the content was totally inaccessible, and it no longer is.

**Q1–Q8 for PSEB: NOT FOUND, every one.** PSEB's TC, migration and affiliation regulations remain
entirely unverified.

---

## NOT FOUND list

Ordered by how much damage a wrong guess would do.

| # | Board | Item | What was tried / why it matters |
|---|---|---|---|
| 1 | **PSEB** | **Everything** — TC format, printed fields, countersignature, post-issuance duty, cross-board admission, migration certificate, duplicates, affiliation process | `pseb.ac.in` serves a **Cloudflare JS challenge** on every request, both hosts, both schemes, with full browser headers. **Defeatable from a real browser** — the single highest-value, lowest-effort gap in this document. Punjab currently has **no board profile at all**. |
| 2 | **UBSE** | All eight questions | Forms page reachable and enumerated; the Procedure PDF is a **pure scan**, the Recognition-conditions PDF a **mis-declared legacy font**. Needs OCR / font mapping. No OCR tooling in this environment. |
| 3 | **RBSE** | TC format, printed fields, countersignature, post-issuance duty, admission from another board | **Affiliation Regulations 2016 located** at `downloads/hand_book16.pdf` — same font defect. The Board's "विनियम" link is **staff service rules**, not school rules; the 1957 Act is a 6.4 MB scan. |
| 4 | **UPMSP** | TC format, printed fields, countersignature, migration regulation, duplicates | The **Regulations under the Intermediate Education Act 1921** are a printed volume, not online; *Regulation 17(1)* is cited on the Board's own form, proving they are live. Two candidate PDFs on the site are scans. |
| 5 | **UPMSP** | Whether TC upload really is mandatory at class-9/11 advance registration | The **prescribed paper proforma has no TC field** [A], which cuts against the [C] news claim. The online module may still demand it. **Do not assert either way.** |
| 6 | **BSEH** | Legal basis of Haryana's departmental SLC proforma | Now confirmed **not** to come from the Board [A]. Still unattributed — as is the *"Entries checked and found correct"* line's signatory. |
| 7 | **JKBOSE** | The TC form that reg. 19(x) prescribes; whether the 1992 Regulations still stand | Form absent from the appendix list [A]. `jkbose.jk.gov.in` **timed out** on 2026-09-12, so "nothing newer than 1992" could not be re-confirmed this session. |
| 8 | **MPBSE** | The regulation governing migration-certificate issue; whether a TC is required for the exam form | MPBSE publishes no regulations; *Pravesh Niti 2025-26* is a scan. **Do not assert an MPBSE TC-for-exam-form rule.** |
| 9 | **CGBSE** | A regulation governing the **issue** (not duplicate) of migration certificates | Only the receiving-side Grahyata rule and the duplicate service were found. |
| 10 | **All nine** | Any board rule requiring a **duplicate school TC** to be marked as such | Found in exactly one: **HPBOSE 3.5.6**. Everywhere else the duplicate rules govern *board* certificates only. |

---

## What generalises

1. **Countersignature is per-board and it is not dead.** CBSE abolished it; **HPBOSE 3.5.7 still
   mandates it and blocks admission without it**; BSEH requires it only when an SLC is used as
   evidence; MPBSE and CGBSE have no rule at all; four boards are NOT FOUND. **A per-board
   `countersignature` policy with a "not established" state is the only honest model** — and the rule
   belongs to the **receiving** board, not the issuing one.
2. **Almost no board prescribes a TC format.** Nine boards, **zero published TC proformas.** JKBOSE
   names a prescribed form and does not publish it; HPBOSE prescribes a **register** instead. The
   CBSE Annexure-I 22-field list remains the only enforceable field schedule in the entire corpus —
   and it binds only CBSE schools, on a 1995/2004 archive text of uncertain currency.
3. **Two boards mandate what must be *printed*, and both chose the same thing:** CGBSE (recognition
   statement + recognition code, on the TC by name) and HPBOSE (affiliation number on all official
   stationery). MP requires the recognition number on Form 4 by state rule. **Printing the school's
   recognition/affiliation identity on a certificate is the one cross-jurisdiction convergence** —
   ship it as a default-on convention, and as a hard requirement only in CG and HP.
4. **Board affiliation is downstream of state recognition, everywhere it was readable.** BSEH reg.
   5(a) (recognition from the state's competent authority is a *condition* of affiliation, with the
   affiliation term mirroring the recognition term), HPBOSE 16.2.13/16.6.1 (NOC from the State
   Education Department), JKBOSE reg. 7(ii) (board recognition dies with departmental recognition),
   MPBSE s.8(f) alongside the DPI's manyata, CGBSE alongside the DEO's RTE certificate. **UP is the
   exception that proves the modelling hazard**: there the board *is* the recogniser for secondary,
   and s.2(d) defines recognition as exam eligibility.
5. **The real board-imposed duties are about registers and reporting, not about the certificate.**
   HPBOSE: report every withdrawal to the Board within **15 days**, maintain the Scholar's Register,
   file countersigned copies with exam forms. BSEH: keep the Admission-and-Withdrawal Register,
   Attendance Register and SLC, producible **in original**, and face a **₹1 lakh → ₹3 lakh →
   loss of affiliation** ladder for a bogus SLC. JKBOSE: do not issue at all until the Board sanctions.
   **This is where a certificate module earns its keep** — provenance, audit trail and
   non-repudiation, not field validation.
6. **The blockers are technical, and they have a shape.** Of the five boards where little was
   established, **none** was blocked by absence of law. Three distinct mechanisms: a **Cloudflare bot
   challenge** (PSEB), **pure scans with no text layer** (UBSE Procedure, RBSE Act, two UPMSP
   notifications, HPBOSE migration form), and **legacy Devanagari fonts declared as Helvetica** (RBSE
   Affiliation Regulations 2016, UBSE recognition conditions). The second and third are solvable with
   OCR; the first with a browser. **None of these should be read as "the board has no such rule."**

**Method note.** Unlike the earlier streams, almost every Level-A claim here was produced by
`curl` + `pdftotext -layout` **locally**, so the quoted regulation text is verbatim page text rather
than a summarising model's paraphrase. The BSEH and HPBOSE sections are consequently the two most
reliable in this document. The session's **WebSearch quota was already exhausted (200/200) before
this stream began**, so every source above was reached by direct fetch of a known or discovered URL —
which biases coverage toward boards with navigable official sites and against PSEB, and means no
search-driven cross-check of the negative findings was possible.
