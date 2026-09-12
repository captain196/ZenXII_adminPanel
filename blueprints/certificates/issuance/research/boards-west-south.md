# The BOARD layer — West & South India state examination boards

**Scope.** MSBSHSE (Maharashtra) · GSEB/GSHSEB (Gujarat) · GBSHSE (Goa) · KSEAB (Karnataka) ·
Kerala — both the SSLC authority (Pareeksha Bhavan / "Board of Public Examinations") and DHSE
(Higher Secondary) · Tamil Nadu DGE · BSEAP + BIEAP (Andhra Pradesh) · BSE Telangana + TSBIE.

**Question.** Not "may a school issue?" — that is settled elsewhere and flows from **state
RECOGNITION**, not board affiliation. This file asks the narrower thing the first research wave
named but never researched: **what does the EXAMINATION BOARD itself impose on a school-issued
Transfer / Leaving Certificate?**

**Verified 2026-09-12.** Companion to `west-central-india.md` (state layer, West/Central),
`south-india.md` (state layer, South) and `central-and-boards.md` (CBSE/CISCE/NIOS/IB/KVS/NVS —
central boards only; it contains no state board).

## Evidence levels

| | Meaning |
|---|---|
| **A** | The primary regulation / rule / form text was read directly |
| **B** | An official board or government portal, circular or order |
| **C** | Credible secondary source |
| **D** | Unverified — **never encode** |
| **NOT FOUND** | Searched and not established. *A result, not a failure.* |

**Discipline.** No rule in this file is inferred. Where a rule could not be found it is recorded as
NOT FOUND with the searches attempted. Conflicts are recorded, not resolved by preference.

> ⚠️ **Research constraint on this pass.** The session's **WebSearch budget was exhausted (200/200)
> before the Kerala and Maharashtra work began**. Those two boards were therefore researched by
> direct retrieval of known primary documents (board regulation PDFs, the Kerala Education Rules
> chapter and forms PDFs, official department portals) rather than by search. That is *stronger*
> evidence, not weaker — every Maharashtra and Kerala claim below is level **A** read first-hand
> from the regulation or the statutory form. But it means **negative findings for those two boards
> rest on a full-text grep of the primary document rather than on a web sweep**, and a rule living
> in a later circular outside those documents would not have been seen.

---

## THE HEADLINE, STATED ONCE

**Across this region the examination board is almost never the author of the TC — with exactly one
real exception.** The TC is normally a creature of the **state school-education code** (Maharashtra
SS Code Appendix Four; Kerala KER Form 5; Tamil Nadu TNER Appendix-5/5-A and the Matriculation Code
Annexure V).

> ### ⚠️ THE EXCEPTION: GUJARAT
> **GSHSEB prescribes the leaving-certificate form in its OWN regulations** — *Gujarat Secondary &
> Higher Secondary Education Regulations 1974*, **reg 12(14)** and form **નમૂનો-૯**, made by the
> Board under the 1972 Act. Thirteen mandated fields, three signatories, the school seal, and a
> validity rule that voids a non-conforming LC outright (§2.1). **Gujarat is the only jurisdiction
> in this file where "the board prescribes the TC format" is true**, and it means a per-board
> `prescribesFormat` flag is required — a regional default would be wrong here.

Everywhere else, the board's certificate-layer footprint is confined to four things, and they are
consistent across boards:

1. **Migration / eligibility certificates for candidates coming from ANOTHER board** — a board
   instrument, issued by the board, never by a school.
2. **Duplicates of the board's OWN certificate** (SSC/HSC/SSLC marksheets and certificates) —
   routed through the head of the school that presented the candidate.
3. **A school identifier** the board allots (index number / school code / DISE code) which the
   *state* rules then require to be printed on the TC.
4. **Countersignature demanded only on an INBOUND certificate from outside the state.**

A product that models "board prescribes the TC" would be wrong nearly everywhere here. **The
correct model is: the STATE prescribes the TC; the BOARD supplies an identifier that goes on it and
owns the migration/eligibility instruments.**

---

## Summary table

| Board | Prescribes a TC format? | Mandatory printed identifiers | Countersignature of a school TC | Post-issuance duty | Evidence |
|---|---|---|---|---|---|
| **MSBSHSE** (Maharashtra) | **No.** The word "Transfer Certificate" appears **exactly once** in the whole 1977 Regulations, and only for inbound out-of-state pupils. The LC is prescribed by the **SS Code Appendix Four**, not the board | **None board-imposed.** Reg 67(xvii) instead binds junior colleges to the *Education Department's* Secondary School Code | **No** for an intra-Maharashtra LC. **Yes** under **reg 79(7)** for a Std-XI pass from another State/UT — by "the Educational Inspectors or the equivalent authority of the District concerned in that State or Union Territory" | **Generic only** — reg 29(2)(i)-(ii): supply such returns and maintain such registers "as may be required by the Divisional Board". No TC-specific duty | **A** |
| **GSEB / GSHSEB** (Gujarat) | ✅ **YES — the only one.** Regulations 1974 **reg 12(14)** + form **નમૂનો-૯**: 13 fields, 3 signatories (Clerk + Class Teacher + Principal), school seal. **Reg 12(14)(5)**: in નમૂના-૯, hand-written in ink, signed by the head in his own ink, sealed — *"only then shall it be legally valid"* | **Masthead: S.S.C. Index No. · H.S.C. Index No. · G.R. No. · Medium · certificate serial.** The Index Nos. are the school's **GSHSEB board index numbers** — Gujarat puts the board identity on the LC **through the board's own form** | **No** within Gujarat. **Reg 12(14)(6)** (DEO approval of a substitute signatory) is advance approval of a **person**, not per-certificate. **Yes inbound** — reg 12(9)(ક) State/UT, 12(9)(ઘ) other country | **General Register in નમૂનો-૧૦** (reg 12(13), reg 38); state RTE r.21(3)(a) adds a **"File of Leaving Certificate"** and bars destroying the General Register. **No upload/return duty found** | **A** |
| **GBSHSE** (Goa) | **No format** — *"leaving certificate"* appears **once** in 47 recovered rules, incidentally (r.77(3)). **But it mandates a FIELD**: Circular 31/2015 makes the **parent's name mandatory** on a school LC, enforced by requiring receiving HS schools to **refuse** an LC without it | **Parent's name only.** No board name, index, recognition number or seal wording on a school LC. **r.81** puts the **school Index number** on the **BOARD's own** passing certificate, not on the LC | **No** on an outbound Goa LC. ⚠️ **YES on inbound — including WITHIN Goa**: Circular 14/2016 requires a CBSE/ICSE LC issued *in Goa* to be countersigned by Education/Zonal Authorities. **The trigger is a BOARD boundary, not a state boundary — unique in this file** | **r.6(2)** generic returns/registers power only. **But Circular 33/2017 is a real annual return** — other-board joiners reported to the Board **by 20 August**. And Circular 14/2016 **bars issuing an LC** to a student whose Final Eligibility is not yet granted | **A** (rules, 2014 archive) / **B** (circulars, live) |
| **KSEAB** (Karnataka) | **No.** By-laws page and amendments page **publish zero documents** (proved against a populated control page); **~160 SSLC circulars across 4 years enumerated — not one concerns TCs** | **None board-imposed.** KSEAB allots a **"New School Code"** annually, but only for **exam registration**. ⚠️ The mandatory **DISE code on every TC** is a **DEPARTMENTAL** act (Commissioner for Public Instruction, 01.06.2016), **not a KSEAB act** | **NOT FOUND at board level.** Both the 25.05.2016 imposition and the 01.06.2016 withdrawal were departmental | **NOT FOUND** | **B** (exhaustively enumerated) |
| **Kerala — Pareeksha Bhavan (SSLC)** | **No.** The only prescribed Kerala TC is **KER Form 5**, made by the **State Government** under the Kerala Education Act 1958 — not by the examination authority | **No code or number.** Form 5's only status line is *"Whether the School is a Government, Aided or Recognised School"*. Every certificate must be **sealed with the school seal before issue** (note to Form 5A) | **No** — Form 5 has no countersignature block; signature is *Principal / Headmaster / Headmistress* alone. **Yes inbound**: KER Ch.VI **r.10** requires an out-of-State TC to be *"countersigned by the Inspecting Officer"* | **Yes, a register** — KER Ch.VI **r.2(1)**: every school shall maintain an **Admission Register in Form 4**, whose **item 14** is *"No. and date of transfer certificate granted on leaving"* | **A** |
| **Kerala — DHSE (Higher Secondary)** | **No.** `dhsekerala.gov.in` **no longer resolves**; HSE is now the Higher Secondary branch of the **Directorate of General Education**. **3,000 archived URLs of the dead domain were enumerated — no TC format, no TC rule, no migration page.** DHSE's certificate footprint is **duplicates and corrections of its OWN HSE certificate** | **None found** | **None found** | **None found** | **B** (evidenced negative via archive) |
| **TN DGE** | **Not DGE — TNER does.** DGE's own published functions cover **exam conduct only** and say nothing about school TCs. The formats are **TNER Appendix-5 / 5-A** and the **Matriculation Code Annexure V** — two different forms that **disagree on the identifiers** | ⚠️ **DISPUTED — do not ship as asserted.** The claimed *"recognition number + DGE school number"* rests on **one uncorroborated 2003 compilation**; the previously cited primary source **provably lacks the text** (§6.8). Certain on the same form: **Head's ink signature + school seal + pupil's and parent's signatures on receipt** | **No** within TN. **Yes inbound**: Matriculation Code r.12(x) — *"counter signature of the Inspecting Officers of the concerned State"* | **TNER:** TCs *"separately filed and shall be shown to the Inspecting officers"*; office copy marked **"Office Copy"**; endorsed with the admission number. **Matriculation Code:** a **counterfoil for all TCs issued**, and *"this will be one of the conditions of recognition"*. ⚠️ **The counterfoil duty is Matriculation-only — TNER has none** | **A** (Matric Code) / **C** (TNER) |
| **BSEAP** (Andhra SSC) | **No** — the Board's own **RTI s.4(1)(b) service catalogue was enumerated and TC is absent**; corroborated by the services page. There is **no "A.P. Board of Secondary Education Rules"** in the Board's own list of governing instruments | **None board-imposed** — but ⚠️ **the recognition number IS mandatory on a private school's TC** under **State** rule 10(8), AP Private Managements Rules **1993** (§7.0). **Right requirement, wrong layer** | **NOT FOUND** — BSEAP offers no such service | **NOT FOUND** at board level; the 1993 Rules impose only a **generic** records-and-registers duty, no TC register | **A** |
| **BIEAP** (Andhra Intermediate) | **NOT FOUND** | **NOT FOUND** | **NOT FOUND**, but on **weak** negative evidence — the JS menu could not be enumerated. **Do not assert as settled** | **NOT ESTABLISHED** | **B / D** |
| **BSE Telangana** (SSC) | **No** — **doubly evidenced**: the service catalogue *and* the **complete 12-item G.O. list** were both enumerated, and neither contains a TC rule | **None for school TCs.** One board-certificate rule: **mother's name printed** on the Board's own Pass Certificate / Marks Memo from **March 2011** | **NOT FOUND** | **NOT FOUND.** `childinfo.telangana.gov.in` **does not resolve**; ISMS **404** — online-TC mandate **NOT ESTABLISHED either way** | **A** |
| **TSBIE** (Telangana Intermediate) | **NOT FOUND** | **NOT FOUND** | ⚠️ **A "Counter signature on Transfer Certificate" service EXISTS** (service id 15, for **outbound** inter-state moves) — **but [B, portal UI only], uncorroborated, and its legal compulsion is NOT ESTABLISHED.** Do not build a mandatory step | **Online "Issue TC" exists**; **legal status NOT ESTABLISHED** — the Board's GO/Acts page was never readable (403) | **B, unverified** |

---

## 1 · MSBSHSE — Maharashtra State Board of Secondary and Higher Secondary Education

**Instrument read in full this session: *The Maharashtra Secondary and Higher Secondary Education
Boards Regulations, 1977 (as amended up to 31st October 1990)*, 147 pp.** Retrieved from the
Board's own site and text-extracted locally. **[A throughout]** ·
https://www.mahahsscboard.in/rules.pdf · verified 2026-09-12.

⚠️ **Currency caveat, stated up front:** the document is the 1977 Regulations **amended only to
31 October 1990**. It is the text the Board itself publishes today, but any post-1990 amendment
would not appear in it. Fees quoted (Rs. 10) are self-evidently stale in amount; the *structure* is
what is citable.

### 1.1 Q1 — Does MSBSHSE prescribe a TC format? **NO.**

**This is a strong, machine-checked negative.** A full-text grep of the Regulations for
`transfer certificate` returns **exactly one hit** — reg 79(7), the inbound out-of-state admission
rule quoted at §1.3. A grep for `leaving certificate` returns **two hits**, both historical
references to the *old School Leaving Certificate examination* of the former Bombay/Poona boards
(regs on equivalence), **not** to a school-issued document.

**There is no MSBSHSE TC form, no schedule of TC fields, and no MSBSHSE rule on issuing one.**

Where the obligation actually lives: **reg 67(xvi)-(xvii)**, the conditions of recognition for a
junior college, verbatim —

> *"(xvi) A junior college maintains registers and records **prescribed by the Education
> Department** in a proper manner;*
> *(xvii) The junior college shall comply with the provisions of service conditions laid down in
> the **Secondary School Code** by the State Government in so far as they are not inconsistent with
> the provisions of the Act and the Regulations;"*

**The Board delegates the document layer to the Education Department's Secondary School Code.** That
is where the Leaving Certificate lives — **SS Code r.32.1 + Appendix Four, the 12-field
manuscript-only LC already documented in `west-central-india.md` §1.6.** The board adds nothing.

### 1.2 Q2 — Anything required to be PRINTED on the certificate? **NOT FOUND — and the grep is clean.**

Grepping the Regulations for `index no | index number | school code | school number | UDISE`
returns **two hits, neither of which touches a school-issued certificate**:

- one in reg 67(xvii) — the Secondary School **Code** (a rulebook, not a number);
- one in the HSC mark-statement rule, requiring *"the name of the candidate, his Seat No. and
  **Index No. of the junior college**, total marks…"* — i.e. the index number goes on **the
  Board's own statement of marks**, not on a school TC.

**So MSBSHSE does impose an index number on a junior college — but only on Board documents.** No
MSBSHSE rule requires it on an LC. *(Contrast Gujarat, where the state's own LC form નમૂનો-૯ puts
the S.S.C. and H.S.C. Index Nos. in the masthead — there the board's identifier reaches the
certificate through the STATE's form, not through a board rule. Same pattern, different route.)*

### 1.3 Q3 — Countersignature. **No for local; yes for inbound out-of-state.**

**Reg 79(7)**, verbatim — the only occurrence of "Transfer Certificate" in the Regulations:

> *"Students who have passed XI Std. examination in the new pattern of 10+2+3 adopted by different
> recognised All India or State Bodies from any other State or Union Territory will be held eligible
> for admission to the second year of a junior college if it is the public examination. If
> examination at the end of Std. XI is not a public examination, the candidates should be admitted
> to second year of junior college (Std. XII) on reciprocal basis. **The Transfer Certificate of
> such students should however be countersigned by the Educational Inspectors or the equivalent
> authority of the District concerned in that State or Union Territory.**"*

**Read the scope precisely.** This is (a) an **admission-side** duty on the receiving Maharashtra
junior college, (b) confined to **Std XI→XII entrants from outside Maharashtra**, and (c) confined
to the case where the other state's Std XI exam was **not a public examination**. It is **not** a
condition on any certificate a Maharashtra school issues, and it does not apply to intra-state
transfers at all. It is the board-level twin of SS Code r.22.1 (`west-central-india.md` §1.6).

### 1.4 Q4 — Post-issuance duty. **Generic register/returns power only.**

**Reg 29(2)**, verbatim:

> *"A recognised Secondary School —
> (i) shall supply to the Divisional Board concerned on or before such dates as may be fixed by the
> Divisional Board, **such returns and information as may be required**.
> (ii) shall **maintain such registers and records as may be required by the Divisional Board**
> concerned from time to time.
> (iii) shall afford all facilities and co-operation for the conduct of the final Examinations…
> (iv) shall carry out and observe such instructions as may be issued by the Divisional Board…"*

This is an **enabling power, not a specified duty**. It creates no TC register, no TC return and no
upload obligation on the face of the Regulations. **Do not build a Maharashtra board-return feature
on reg 29.** The real Maharashtra register duty is the SS Code's **counterfoil of every LC issued**
(`west-central-india.md` §1.6) — a *state* duty.

**Reg 29(5)** adds a commercially sharp condition unrelated to TCs but worth recording: a recognised
school must pay the **annual registration fee by 10 August**, and *"if the registration fee … is not
paid by the Schools by the prescribed date, the application of candidates for the Secondary School
Certificate Examination **shall not be accepted** by the Divisional Boards."*

### 1.5 Q5 — Admission from another board or state

Three distinct instruments, all board-side:

| Reg | Instrument | Text |
|---|---|---|
| **79(7)** | Countersigned TC | see §1.3 |
| **80** | **Eligibility Certificate** | *"The students, seeking admission to the junior college classes and who have passed the public examinations of the Statutory Boards; Recognised Bodies and Universities **outside the Maharashtra state** will have to produce the Eligibility Certificate."* Applied for **in a prescribed form to the Divisional Secretary** with a fee; *"The fees remitted for issue of eligibility certificate shall not be refunded."* The Divisional Secretary issues it per State Board instructions |
| **81** | **Migration Certificate for admission** | *"A migration certificate from any other Statutory Board, Recognised Body or University conducting the examination passed by the candidate **shall have to be produced by candidates coming from other States** and seeking admission to first year or second year of a junior college."* |

Reg 79 also fixes cross-board equivalence directly: **79(6)** ICSE passes → eligible for FY junior
college; **79(9)** old 11-year Indian School Certificate → second year; **79(10)** CBSE Higher
Secondary 11th class → second year; **79(16)** Std XI Science entry needs **≥40% in science
subject(s)** at SSC or equivalent. **[A]**

### 1.6 Q6 — Does MSBSHSE issue Migration Certificates itself? **Yes — two regulations.**

> **Reg 60 (SSC).** *"A Migration Certificate may, on application and payment of a fee of Rs. 10/-,
> be granted to a candidate who has appeared at the examination conducted by the Divisional Board.
> An application for such a certificate shall be made to the **Divisional Secretary** of the
> Divisional Board concerned and shall be accompanied by a Bank Draft or P.O. for the prescribed
> fee."*

> **Reg 107 (HSC).** *"A Migration Certificate may, on application and payment of a fee of Rs.10/-
> be granted to a candidate who has appeared at the **Higher Secondary Certificate examination**
> conducted by the Divisional Board. An application … shall be made to the Divisional Secretary of
> the Board concerned and shall be accompanied by a Bank Draft or Indian Postal Order…"*

**Note the eligibility trigger is "has appeared at", not "has passed".** Both regs. **[A]**

**Hard product rule, now evidenced twice for Maharashtra: a school must never issue a Migration
Certificate.** It is the Divisional Secretary's instrument under regs 60 and 107.

### 1.7 Q7 — Duplicates

MSBSHSE governs duplicates of **its own** certificate, not of a school TC:

> **Reg 61.** *"A copy of the Secondary School Certificate already granted, shall be issued by the
> Divisional Secretary **on receipt of an application through the head of the secondary school
> which had presented the candidate** for the examination, accompanied by a fee of Rs. 10/— for each
> such copy … The copy of the certificate **will be supplied only through the head of the secondary
> school concerned**; provided that copies … of candidates presented … by secondary schools **which
> have ceased to exist or to be recognised** … shall be issued **directly to the candidates**."*

**Reg 108** is the identical rule for the Higher Secondary Certificate through the head of the
junior college. Fee schedule (regs 47 / 94) lists *"(ii) Duplicate Certificate — Rs. 10/-"* and
*"(iii) Migration Certificate — Rs. 10/-"*. **[A]**

**No MSBSHSE rule requires a duplicate to be marked "Duplicate".** The "Duplicate in red ink at the
top" rule for Maharashtra is **SS Code r.30** — a *state* rule (`west-central-india.md` §1.6), not a
board rule. **Do not attribute it to the board.**

**Design consequence:** a school's role in a Maharashtra duplicate-SSC request is as a **conduit**,
not an issuer — and that conduit **breaks when the school is de-recognised**, at which point the
Board deals with the candidate directly. If the module ever models "request duplicate board
certificate", the school is a forwarding step, not the author.

### 1.8 One more MSBSHSE rule worth recording — correction of name/DOB on a board certificate

**Reg 59(3) (SSC) / reg 106 (HSC)** — a correction to a name or date of birth on a **statement of
marks or board certificate** is admitted **only when the entry differs from the school register**,
must be applied for **through the head of the school**, and when made *"shall be indicated **on the
reverse** … by an endorsement in such form as may be prescribed by the State Board."* **[A]**

This is the board-side mirror of the register-is-authority model: **the school's General Register is
the fact; the board certificate is a projection of it.** It also corroborates the Maharashtra and
Gujarat case-law position that post-departure certificate corrections track the register.

---

## 2 · GSEB / GSHSEB — Gujarat

> **Provenance note.** The Gujarat board record was already established at level **A** by the first
> wave, which read the *Gujarat Secondary & Higher Secondary Education Regulations, 1974* (GSHSEB
> 5th edition as amended to 31 Dec 2021, 124 pp, Gujarati) in full —
> https://www.gsebeservice.com/assets/pdf/rules/RLS621063.pdf, indexed at
> https://www.gsebeservice.com/Web/rules. **§2 below carries that forward and re-frames it against
> the seven board questions**; it is not a re-derivation. Where this pass adds nothing new, it says
> so rather than dressing the old finding as fresh.

### 2.1 Q1 — Does the board prescribe a TC format? **YES — and Gujarat is the exception in this file.**

**GSHSEB is the one board in the west/south whose OWN regulations carry the leaving-certificate
form.** The instrument is **Regulation 12(14)**, *"શાળા છોડ્યા અંગેના પ્રમાણપત્ર કાઢી આપવા અંગે"*
(amended by Education Dept resolution **મશબ/૧૨૨૦/૮૪૩/છ dated 24-05-2021**), and the prescribed form
is **નમૂનો-૯**, *"[જુઓ વિનિયમ-૧૨(૪), ૧૨(૧૪)(૫) અને વિનિયમ-૧૩]"* (amended by **મશબ/૧૨૧૧/૩૪૭/છ dated
04-05-2011**). **[A]**

> ⚠️ **This is a genuine qualification on the file's headline.** Everywhere else the *state* wrote
> the TC form and the board wrote nothing. **In Gujarat the two are the same document** — the 1974
> Regulations are made by the Board under the Gujarat Secondary and Higher Secondary Education Act
> 1972, and it is those Regulations that prescribe the LC. Gujarat is therefore the **only**
> jurisdiction in this file where "the board prescribes the TC format" is a true statement — and
> even here the form is invoked by the *state's* schooling rules as well. *(`CONFLICTS.md` C-16b
> should be read with this carve-out.)*

**Reg 12(14)(5) is the validity rule and it is unusually strict:** the LC must be in **નમૂના-૯**,
**hand-written in ink** (શાહીથી હાથે લખેલું), **signed by the head of the school in his own ink**,
and bear the **school's seal** — *"તો જ તે કાયદેસર ગણાશે"* (**"only then shall it be legally
valid"**). **[A]**

**The 13 mandated body fields of નમૂનો-૯**, in printed order: Full Name of the Student (**Surname
first**) · Religion and Caste · **Mother's Name** · Place of Birth (with Taluka/District) · Date of
Birth **in figures and words, Christian Calendar** · Last School Attended · Date of Admission (with
class) · Date of leaving school · Standard studying in & since when · Reason for Leaving · Progress
· Conduct · Remark. Then the certification line *"Certify that the above information is verified by
me with school register and found to be correct."* **[A]**

**Three signatories — Clerk (ક્લાર્ક) · Class Teacher (વર્ગ શિક્ષક) · Principal (આચાર્ય) — plus the
school seal.** **[A]**

### 2.2 Q2 — Printed particulars? **YES — and Gujarat's route is unique.**

**નમૂનો-૯'s masthead carries: Name and address of the school · S.S.C. Index No. · H.S.C. Index No. ·
G.R. No. (જી.આર.નં.) · Medium · No. ____ (certificate serial).** **[A]**

**The S.S.C. / H.S.C. Index Nos. are the school's GSHSEB board index numbers.** So a Gujarat
secondary school's LC carries a **board-assigned identifier on its face** — achieving what Karnataka
does with a DISE code and Andhra with a recognition number, **but through the board's own
identifier, embedded in the board's own form.** **[A]**

> ### 🔴 CORRECTION — the Index No. is NOT the school's registration number
>
> **The brief's working assumption, and an earlier phrasing of this section, were wrong.** The
> Board's own forms carry the two as **separate fields**: **[A]**
> - **નમૂનો-૨, "માધ્યમિક અને ઉચ્ચતર માધ્યમિક શાળા નોંધણી રજિસ્ટર"** *[જુઓ વિનિયમ 9(9) સાથે વાંચતાં
>   કલમ 31(3)]* — columns include *"શાળાનો ઇન્ડેક્સ નંબર"* **split SSC / HSC**, **and separately**
>   *"શાળા નોંધણી નંબર"* and *"શાળા નોંધણી કર્યાની તારીખ"*.
> - **નમૂનો-૩, "નોંધણી પ્રમાણપત્ર"** *[જુઓ વિનિયમ 9(11) સાથે વાંચતાં કલમ 31(8)]*, issued by the
>   Secretary under s.31 of the 1972 Act — lists *"શાળા સંચાલક મંડળનો નોંધણી નંબર"*, *"શાળા નોંધણી
>   નંબર"* **and** *"શાળા ઇન્ડેક્સ નંબર"* as **three different fields**.
>
> **નમૂનો-૯ asks only for the two Index Nos. — never the registration number.** Corroborated at
> **[B]**: GSEB's live affiliated-school registry https://schoolreg.gseb.org/ViewSchool.aspx searches
> **by School Index** and validates the format as **`00.000` or `000.000`** — the Index is the
> board's operational school code, not its registration number. Verified 2026-09-12.
>
> **A product that models one Gujarat identifier is modelling the wrong thing**, and a school holds
> at least three: society registration number, school registration number, and SSC/HSC Index.

**⚠️ There is NO free-standing board regulation commanding "print the index number".** The
obligation is **entirely derivative of the form** — reg 12(14)(5) binds the LC to *"નિયત નમૂના-૯માં"*
and the form's masthead does the rest. **[A]**

**Board name is NOT required on the LC.** નમૂનો-૯'s masthead carries only the school's name and
address. *(Contrast નમૂનો-૩, the Board's own registration certificate, which IS headed **"ગુજરાત
માધ્યમિક અને ઉચ્ચતર માધ્યમિક શિક્ષણ બોર્ડ, ગાંધીનગર"**.)* **No seal WORDING is prescribed anywhere in
the Regulations** — only that a seal be affixed. **[A]**

**Also printed, and product-relevant:**
- A standing warning above the field table: *"આ પ્રમાણપત્રમાંની કોઈ નોંધમાં તે કાઢી આપનાર સત્તાધિકારી
  સિવાય બીજા કોઈથી કશો ફેરફાર થઈ શકશે નહિ…"* — no one but the issuing authority may alter an entry.
- A sub-header instruction: *"(આ પ્રમાણપત્ર શાહીથી ભરવું.)"* — fill in ink.
- A statutory warning that only the headmaster (or the person authorised in his absence) may issue
  the certificate or change an entry.

### 2.3 Q3 — Countersignature? **No for a Gujarat-issued LC. Yes inbound.**

- **Within Gujarat: NO countersignature** by BEO/DEO/DPEO. The signature block is **Clerk + Class
  Teacher + Principal + seal**, and nothing in reg 12(14) or નમૂનો-૯ adds an external signature.
  **[A]**
- **Reg 12(14)(6)** — if the **head is absent**, the substitute signatory must be authorised by the
  management **and approved by the DEO**. ⚠️ **This is advance approval of a PERSON, not
  countersignature of each certificate.** Do not model it as a per-document step.
- **Reg 12(9)(ક)** — **inbound**: a student from another **State/UT** may be admitted only if the LC
  bears the **સામી સહી (countersignature) of that State/UT's education officer**; if absent, the head
  shall obtain it and may admit **provisionally** meanwhile, with a full report. **Reg 12(9)(ઘ)** is
  the analogue for another **country** (valid visa for the study period, a test before admission, and
  an LC countersigned by that country's education authorities). **[A]**
- **Per school type (govt / grant-in-aid / self-financed / CBSE): NOT FOUND.** The Regulations draw
  the aided/non-aided line for **class permission** (reg 10(1)), **not for LC signature.**

### 2.4 Q4 — Post-issuance duty

- **Reg 12(13)** — after admission, enter the student in the **General Register in નમૂનો-૧૦**
  *[જુઓ વિનિયમ-૧૨(૧૩) અને ૩૮(૧)(ક)(૧)]*, whose 13 columns **correspond almost exactly to નમૂનો-૯'s
  fields**, closing with *"Remarks (reason for leaving, school fees paid or unpaid)"*. **The LC is a
  projection of the General Register row.** **[A]**
- **Reg 38 — and this is the strongest post-issuance duty in the whole file. [A]** Chapter 7,
  *"38. (1) દરેક રજિસ્ટર થયેલી શાળાએ નીચેનાં રેકર્ડો અને રજિસ્ટરો રાખવા અને નિરીક્ષણ માટે રજૂ કરવાં
  જોઈશે"* — every registered school **shall keep the following records and produce them for
  inspection**. Table (ક), *"વિદ્યાર્થીઓને લગતાં રજિસ્ટર"*, as substituted by resolution
  **મશબ/૧૨૨૦/૯૮૪/અ dated 24-05-2021**:

  | # | Record | Retention |
  |---|---|---|
  | 1 | સામાન્ય રજિસ્ટર (વયપત્રક) **નમૂના-૧૦ મુજબ** — General Register | **કાયમી** (permanent) |
  | 2 | વિદ્યાર્થીઓનું માસિક હાજરી રજિસ્ટર **નમૂના-૧૧ મુજબ** | દસ વર્ષ (10 yrs) |
  | **3** | **બીજી શાળામાંથી મળેલાં શાળા છોડ્યાના પ્રમાણપત્રો** — **LCs RECEIVED from other schools** | **કાયમી** |
  | **4** | **વિદ્યાર્થીઓને કાઢી આપેલા શાળા છોડ્યાના પ્રમાણપત્રોની સ્થળપ્રતો** — **office copies / counterfoils of LCs ISSUED to students** | **કાયમી** |
  | 5 | વિદ્યાર્થીઓએ પ્રાપ્ત કરેલ ગુણ અને પરીક્ષાના પરિણામના રેકર્ડ | કાયમી |
  | 6 | વિદ્યાર્થીઓના સ્વાસ્થ્ય અને તબીબી તપાસના રેકર્ડ | કાયમી |
  | 7 | આગલા વર્ષની વાર્ષિક પરીક્ષાના જવાબપત્રો | ત્રણ વર્ષ |
  | 8 | નિરીક્ષણ પહેલાંની કસોટી પછી દાખલ કરેલાં વિદ્યાર્થીઓના રેકર્ડ | ત્રણ વર્ષ |
  | 9 | વિદ્યાર્થીઓને લગતી સ્કોલરશીપ અને અન્ય સહાય અંગેનો રેકર્ડ | દસ વર્ષ |

  **Items 3 and 4 are explicit and PERMANENT: a counterfoil file of every LC issued, and a file of
  every LC received.** **Reg 39** makes schools and hostels open to inspection. *(This is the same
  two-directional shape as Kerala's Form 4 columns 13 and 14 — §5.5.)*
- **Reg 12(13)** — on regular admission the student's name must be entered in the **General Register
  in નમૂનો-૧૦**. **[A]**
- **Gujarat RTE Rules 2012, r.21(3)(a)** — school records include **"(11) File of Leaving
  Certificate"** and "(10) File of Age Certificates", and the **"(1) General Register"** *"shall on no
  account be destroyed"*. **[A]** *(Note this is the STATE rule, corroborating the board's reg 38.)*
- **A TC-upload or online-TC duty: NOT FOUND — and the archive was enumerated.** The Board's circular
  archive (`Web/get_circular_list`, all four categories) returns 12 items under **Exam**, 21 under
  **Research**, and **"No records available"** under both **School Control** and **Student Service**.
  **Nothing on LC/TC at all.**

### 2.5 Q5 — Admission from another board or state

- **Reg 12(4)** — a student who left another school must submit, **with the admission application,
  the last school's LC in નમૂનો-૯**. If the child never attended any school, take a **declaration**;
  if the previous head has not issued the LC, the head **may admit provisionally (કામચલાઉ)** after
  consulting the previous head, report to the officer, and act on the officer's order. **[A]**
- **Reg 12(9)(ક)/(ઘ)** — the inbound countersignature rules at §2.3. **[A]**
- **Reg 12(10)** — without special approval, no placement in a class **higher** than the LC shows the
  student eligible for. **[A]**
- **Reg 12(11)(ક)** — *"માન્ય ન થયેલી શાળામાંથી શાળા છોડ્યાના પ્રમાણપત્રના આધારે પ્રવેશ આપી શકાશે
  નહિ."* — **admission may NOT be granted on an unrecognised school's leaving certificate.** **[A]**
- **Equivalency Certificate** is offered as a GSHSEB e-service. **[B]**

### 2.6 Q6 — Migration Certificates — **YES, GSHSEB issues them; the regulation is NOT FOUND**

The e-service portal offers **"10th Pass Migration Certificate"**, **"12th Pass Migration
Certificate"**, duplicate marksheet/certificate and **"Equivalency Certificate"**. **[B]** ·
https://www.gsebeservice.com/

**⚠️ Board regulation text on migration certificates: NOT FOUND.** The 1974 Regulations index
(regs 1–43) contains **no migration-certificate regulation** — the service is most likely handled by
**board resolution** rather than by the Regulations. A secondary report of a **₹100** fee plus a
document list is **[C]** and **was not confirmed against a board circular**.

**The Board does not issue LCs — schools do, under reg 12(14). [A]**

### 2.7 Q7 — Duplicates

**Reg 12(14)(3):** a **duplicate LC** requires an application **plus an affidavit before an Executive
Magistrate** stating where the original went and why a duplicate is needed. **[A]**

**⚠️ NOT FOUND: any Gujarat rule on how a duplicate must be MARKED.** Unlike Maharashtra ("Duplicate"
in red ink at the top), Kerala ("clearly marked 'Duplicate'") and one TNER recension (red ink, once
only), **no marking requirement was located for Gujarat.** Do not apply a Maharashtra-style
watermark to a Gujarat LC on the assumption that the rule travels.

### 2.8 Fee and refusal, for completeness **[A]**

- **12(14)(1)** — application **in writing by the guardian/parent** (an adult student may apply
  himself); the school **must issue the LC in નમૂના-૯ within at most SEVEN DAYS**.
- **12(14)(2)** — the school **cannot refuse** without proper reasons; if it does not issue one, the
  **reasons must be given in writing within seven days**. ⚠️ **Gujarat gives NO exhaustive list of
  lawful refusal grounds** — unlike Maharashtra, which whitelists exactly two. **Do not port
  Maharashtra's "unpaid dues" ground into Gujarat; it has no Gujarat basis.**
- **12(14)(4)** — **no fee in any circumstances** if demanded **within two years** of leaving or of
  the public-exam result; thereafter **₹1 per year, maximum ₹5**.

**Duplicate affidavit competence — reg 12(અ)(7), Note 1 [A]:** *"માનદ પ્રેસિડેન્સી મેજિસ્ટ્રેટ, જસ્ટિસ
ઓફ પીસ અને માનદ મેજિસ્ટ્રેટ, સોગંદ લેવડાવી શકશે નહિ… માત્ર **વૈતનિક મેજિસ્ટ્રેટ અથવા નોટરી** સમક્ષ
સોગંદનામું જાહેર કરેલું હોવું જોઈશે."* — Honorary Presidency Magistrates, Justices of the Peace and
Honorary Magistrates **cannot** administer the oath; **only a stipendiary (વૈતનિક) Magistrate or a
Notary**. Stamp duty under **Art. 4, Sch. I, Indian Stamp Act**.

### 2.9 ⚠️ Gujarat conflicts — recorded, not resolved

**G1 — "Handwritten in ink" versus the existence of any printed or ERP-generated LC. THE MOST
PRODUCT-CRITICAL ITEM IN THIS FILE.**
Reg 12(14)(5) makes a Form-9 LC legally valid **only** if **handwritten in ink**, personally signed
in ink by the head, and sealed. **On the face of the current (2021) text, a machine-printed Gujarat
LC is not "કાયદેસર" — not legally valid.** Yet reg 12(14)(1)–(2), **inserted by the same 2021
resolution**, impose a 7-day SLA and written-reasons-for-refusal — provisions that read as modern
administrative practice. The two sit in the same sub-regulation.
**No amendment or circular relaxing the ink requirement was found** — but see NOT FOUND #G-3: the
post-2021 amendments PDF is an unreadable scan, so a later relaxation **cannot be ruled out**.
*(This is the exact twin of Maharashtra SS Code Appendix Four N.B. 2, "These entries shall be in
manuscript and not typewritten" — `west-central-india.md` §1.6. **Two of the three states in this
file that prescribe an LC form require it to be handwritten.** A digital-certificate product must
confront this directly rather than assume it is obsolete.)*

**G2 — Form-9 cites its own authority two different ways, on the same printed page.** The Gujarati
banner reads **"[જુઓ વિનિયમ-૧૨(૪), ૧૨(૧૪)(૫) અને વિનિયમ-૧૩]"**; the inner caption beneath the English
title "SCHOOL LEAVING CERTIFICATE" reads **"(જુઓ વિનિયમ-૧૩ નમૂનો-૯)"** alone. Both appear in the 2021
official edition. *(Compounding it: per the index, **reg 13 is "કપટથી પ્રવેશ મળ્યો હોય તો પ્રવેશ
બિન-અમલી ગણાશે"** — admission obtained by fraud is void — which does not obviously govern the LC.)*
**Reproduced as printed; a probable drafting artefact; not resolved.**

**G3 — The three signature lines are not equally load-bearing.** The form prints **Clerk + Class
Teacher + Principal**; reg 12(14)(5) requires the **head's own** ink signature and the seal; the
form's footer warning admits only the headmaster or an authorised person; reg 12(14)(6) narrows
"authorised person" to one **authorised by the management AND approved by the DEO**. Consistent in
outcome, but **only the Principal's signature carries legal weight** — the Clerk and Class Teacher
lines are form convention, not validity conditions. **Do not build a blocking three-way sign-off.**

---

## 3 · GBSHSE — Goa Board of Secondary and Higher Secondary Education

> **The first wave recorded GBSHSE as wholly NOT FOUND because its hosts were unreachable. That
> was a domain problem, not an absence of law.** This pass found the live site and recovered the
> Board's own Rules.

### 3.0 🟢 Reachability — the first wave was looking at the wrong domain

All verified **2026-09-12**:

| Host | State |
|---|---|
| **`https://www.gbshse.in/`** | ✅ **HTTP 200 — THIS IS THE BOARD'S LIVE CURRENT SITE** (65.1.104.144), an Angular SPA; data API `https://www.gbshse.in/Admin_website_api/index.php/MainApi/*` (POST) |
| `gbshse.in` *(bare, no `www`)* | **DNS does not resolve** — which is why the first wave, and my own first probe, both concluded the domain was dead |
| `gbshse.gov.in` / `www.gbshse.gov.in` | resolve to **164.100.228.159** (NIC) but **TCP timeout / ECONNREFUSED**. The **old** site; available only via Wayback |
| **`gbshse.org`** | ⚠️ resolves (108.165.135.3) and **301/302-redirects to `colowinnext.com`, a parked/spam host.** **NOT the board. Never link it from the product.** |

**Lesson worth keeping:** the earlier "board unreachable ⇒ nothing to find" conclusion was produced
by testing two dead hostnames and one bare domain. **A dead `.gov.in` is not evidence that a board
has no published rules.**

### 3.1 The Board's primary instrument

**Goa, Daman and Diu Secondary and Higher Secondary Education Rules, 1975**, made by the
Administrator under **s.46 of the Goa, Daman and Diu Secondary and Higher Secondary Education Board
Act, 1975 (Act 13 of 1975)**, Notification **LD/1786/75**. Published by the Board as
`rules.html` + `rule1.html … rule93.html`. **47 of the 93 rule pages were recovered** from Wayback
snapshots of `www.gbshse.gov.in` (Jan–Feb 2014) — including the complete blocks that matter
(rr. 1–21, 24, 26–32, 38, 45, 50, 52, 60, 63, 65, **70–89**, 91–93). **[A]** — primary rule text,
archived from the board's own former site.

⚠️ **Two provenance caveats that travel with every Goa rule quoted below.**
1. **The rule pages are a 2014 snapshot.** Live 2026 circulars reference Grade 9 semester
   examinations, ATKT variations, Holistic Progress Cards and PARAKH taxonomy that post-date it, so
   **the Rules have very likely been amended since**, and no consolidated current edition was found.
   Rules 81–85 are nevertheless corroborated as operative by live 2023–2026 circulars and portals.
2. **All rule text comes from ONE source lineage.** Rules **84** and **85** are independently
   corroborated by live portals and circulars. **Rules 81, 82, 83 and 87 rest on that lineage alone
   — treat them as A-minus.**

### 3.2 Q1 — Does GBSHSE prescribe a TC/LC format? **No format — but it DOES mandate a field.**

Across all 47 recovered rules the phrase *"leaving certificate"* occurs **exactly once**, and
incidentally: **r.77(3)**, on repeaters applying through the last school attended *"even if the
leaving certificate is obtained by him/her"*. **There is no rule prescribing a school Leaving
Certificate form, no schedule or appendix for one, and no field list.** **[A]**

The school-level LC remains governed by the **Goa, Daman & Diu School Education Rules, 1986**
(rr. 116, 122, 124, 125) — the *state* layer, already covered in `west-central-india.md` §5.

**But the Board reaches onto the school's LC by circular, and this is a genuine board-imposed
field. [B]** — retrieved from the live board site, 2026-09-12:

> **Circular No. 31 of 04-09-2015**, ref. **GBSHSE/CERT-CELL/2015**, *"Regarding entry of parents'
> name on the Leaving Certificate"* ·
> https://www.gbshse.in/Admin_website_api/Files/circular/912077-circular312015.pdf
>
> *"1. It has come to the notice of this Office that some schools are not entering the parents' name
> on the leaving certificate issued by them.*
> *2. Since the Marksheet / Marksheet cum Passing Certificate of the candidate shows the parents'
> name which is as per the entry on the School General Register, **it is mandatory that name of the
> parent should be reflected on the leaving certificate issued by the school.***
> *[3]. Further, Head of Higher Secondary schools are instructed **not to accept any leaving
> certificate issued by schools which does not carry the parents' name.**"*
>
> *(Copy forwarded to the Director of Education "with a request to give directives to **CBSE and
> ICSE schools** in the state of Goa.")*

**This is the only instance in the whole file of a board mandating a specific FIELD on a
school-issued LC without prescribing the form.** Note the enforcement design: the sanction is not on
the issuer but on the **receiving** higher secondary school, which must **refuse** a non-compliant
LC. **A Goa LC template that omits the parent's name will be rejected at the receiving school.**

**And a hard issuance duty — Circular No. 40 of 21-06-2024**, ref. **GBSHSE/EXAM/School Leaving
Cert./2024** ·
https://www.gbshse.in/Admin_website_api/Files/circular/788753-2024circularno40.pdf · **[B]**:

> *"2. This is to instruct all schools Heads that **they must issue the School Leaving Certificates
> to any Class XII or Class X student who requests for** School Leaving Certificate, enabling them
> to pursue further education elsewhere, including polytechnic or ITI or any other institutions.
> Schools are reminded that there is no impediment for these students to fill Class X or Class XII
> online exam forms through their own school as repeater candidates and retake the exams they have
> previously failed.*
> *3. So, Heads of the Institutions are informed to ensure **strict compliance with this directive
> immediately**…"*

### 3.3 Q2 — Printed particulars

**On a school-issued LC: only the parents' name** (Circular 31/2015). **No board name, index number,
recognition number or seal wording is prescribed for a school LC** — NOT FOUND after reading all 47
recovered rules and scanning **all 1,016 circular titles** in the live board index.

**On the BOARD's own passing certificate — r.81 "Award of Certificates", verbatim [A]:**

> *"(1) The Board shall award the certificates of passing to the successful candidates of secondary
> and higher secondary school certificate examinations **in the specified form** indicating therein
> (a) the name of the candidate; (b) date of birth; (c) seat number of the candidate; **(d) school
> Index number;** (e) subjects offered; (f) the grade secured by the candidate.*
> *(2) The certificate shall be issued **over the signature of Secretary of the Board with seal**
> through the head of the institution. It shall bear the **signature of the candidate and the Head
> of the school with the seal of the school.**"*

**So Goa does operate a school Index number** — corroborated independently by the board's own
institution URLs (e.g. `…/institution/hs066-vishwanath-mahadev-parulekar-higher-secondary-school`,
index **HS066**). **But r.81 puts it on the BOARD's certificate, not on the school's LC.** **[A]/[B]**

**r.81(5)–(7):** a name/DOB correction is admissible only where the entry **differs from the school
register**; a correction once made *"shall be indicated on the **reverse** of the Certificate by an
endorsement"* in the Board's specified form; errors found after issue are corrected by Board
endorsement **routed through the head of the institution**. *(The same reverse-endorsement mechanism
as MSBSHSE reg 59(3) — §1.8.)*

### 3.4 Q3 — Countersignature. **Not on an outbound Goa LC. Required on inbound — including WITHIN Goa.**

Nothing in the Rules or circulars requires countersignature of a Goa school's own outbound LC.

**Inbound — Circular No. 14 of 3 May 2016, "Issue of Eligibility Certificates"**, which states
*"This Circular supercedes all other circulars issued in this regard"* ·
https://www.gbshse.in/Admin_website_api/Files/circular/82260-circular142016.pdf · **[B]**:

| Origin | Requirement |
|---|---|
| **CBSE/ICSE *within Goa*** | *"School Leaving Certificate/TC in Original **countersigned by Education/Zonal Authorities**"* |
| **Other Boards / CBSE / ICSE *outside Goa*** | *"…in Original **countersigned by Education/Zonal Authorities of the State/Country where the school is located**"* |
| **Foreign** | *"4. Foreign cases: The LC/TC should bear the **counter-signature of the authority of Indian Embassy/Consulate in that country**."* |

⚠️ **Goa is the ONE jurisdiction in this file that demands countersignature on a certificate issued
INSIDE its own state** — a CBSE or ICSE school in Goa must have its LC countersigned by the
Education/Zonal Authorities before a Goa Board higher secondary school may use it. **Every other
state in this corpus triggers countersignature only at a state boundary.** This is a real exception
to the "inbound-only, interstate-only" model in `CONFLICTS.md` C-1 — **the trigger in Goa is
crossing a BOARD boundary, not a state boundary.**

Corroborated by **Circular No. 24 of 24-06-2009** ·
https://www.gbshse.in/Admin_website_api/Files/circular/268478-circular242009.pdf — documents to be
enclosed include *"iii. **School Leaving Certificate/Transfer Certificate with the counter signature
of the Educational Inspector** (to be produced by the students seeking admission in Std. XI or XII);
**iv. Migration Certificate in original.**"* **[B]**

### 3.5 Q4 — Post-issuance duties

**No rule requires a school to keep an LC counterfoil or file a return of LCs issued.** The only
board-level records duty is generic — **r.6(2)** *(the exact analogue of MSBSHSE reg 29(2), §1.4)*:

> *"(i) shall supply to the Board on or before the dates as may be fixed by the Board such **returns
> and information** as may be required; (ii) shall **maintain such registers and records as may be
> required by the Board from time to time**"* **[A]**

**r.87** ("Maintenance and disposal of records") governs the **Board's own office**, not schools —
office registers preserved as permanent record; exam material disposed of 90 days after results,
**by pulping under written agreement**. **[A]**

**Two real duties do exist, by circular [B]:**
- **Circular 14/2016, operative conditions:** *"3. The School shall **not issue the Leaving
  Certificate** of students whose Final Eligibility have not been granted by the Board."* and
  *"4. The School shall not declare Std. XI results of such students whose Final Eligibility
  Certificate have not been issued by the Board."*
- **Circular No. 33 of 19-07-2017** — every higher secondary school must **submit to the Board by
  20 August** the details of students who joined from other Boards (Sr. No. / Name / Class joined XI
  or XII / Name of the Board through which the student passed X or XI / Provisional Certificate
  Number if applicable). *"Failure to comply will be viewed seriously."* **This is a genuine annual
  board return, and the only one found in this file.**

**No TC-upload portal exists.** The Board's live e-services (`MainApi/getService`, verified
2026-09-12) are: Online Registration (IX & XI) `studentreg.gbshse.in`; **Eligibility Portal**
`studentreg.gbshse.in/eligibility-candidate`; **Apply for Migration Certificate**
`service2.gbshse.in/cert_issue/document-issuance-portal/`; Institution Services
`recognition.gbshse.in`; Shikshak/TIS portals; GTET. **None handles school leaving certificates.**

### 3.6 Q5 — Admission from another board or state — **Goa has the most developed regime in this file**

**r.85, "Eligibility certificate", verbatim [A]:**
> *"(1) The Board shall issue Eligibility Certificate to a student seeking admission in any
> recognised higher secondary school of this Board on his/her application in specified form
> alongwith required documents and on payment of specified fees…*
> *(2) **A student who has passed the qualifying examination from any statutory Board, recognised
> bodies and Universities other than Board shall be admitted to the institutions recognised only on
> production of eligibility certificate issued by this Board.**"*

Operationalised by **Circular 14/2016** into **eight categories [B]**:

| Cat. | Who | Board eligibility needed |
|---|---|---|
| 1 | Passed Std X of **Goa Board** | **None** |
| 2 | Passed X (ICSE/CBSE) **in a Goa school** | Final EC — X marks list, LC/TC **countersigned**, **Migration Certificate in original**, X passing certificate. Due **30 Oct** |
| 3 | Passed X of **NIOS** | Final EC — X marks list, **Migration Certificate in original**, X passing certificate. Due **30 Dec** |
| 4 | Passed X of **any other Board outside Goa** | ⚠️ **Provisional EC must be obtained BEFORE admission (by 15 July)**; then Final EC by **30 Dec** with countersigned LC + Migration Certificate in original + Provisional EC copy |
| 5 | Passed XI under **Goa Board** | **None** |
| 6 | Passed XI, ICSE/CBSE in Goa | Final EC, due **30 June** |
| 7 | Passed XI ICSE/CBSE in Goa but **SSC from Goa Board** | Eligibility (no LC/migration in the Board's list), due **30 June** |
| 8 | Passed XI other Boards outside Goa | Final EC with countersigned LC + Migration Certificate in original, due **30 June** |

Fees per Circular 14/2016: **₹100** Provisional EC (cat. 4); **₹200** Final EC (cats. 2,3,4,6,7,8);
**₹100/month** late; *"No application for Eligibility shall be received after the last date."*
Revised by **Circular No. 53 of 07-10-2021** *(image-only PDF — amount not read)*. **Circular No. 35
of 28-05-2025** states the current online fee: *"Payment fees for Indian Students: **₹400** (for
Academic year 2024-25)."*

**Circular No. 35 of 28-05-2025**, *"Implementation of Online Provisional and Final Eligibility
System…"* · https://www.gbshse.in/Admin_website_api/Files/circular/276177-2025circularno35.pdf —
the **current** process **[B]**: register on `www.gbshse.in` → "Eligibility Certificate Application"
→ UR No. + OTP → upload *"Class X marksheet or equivalent certificate"* and *"Valid ID proof
(Aadhaar card, passport, etc.)"* → pay ₹400 → download the Provisional Eligibility Certificate →
submit the print to the higher secondary school, which *"will verify the certificate through the
Board's system."* Institutions enrol such students via **"ADD Candidate → Import Other Board
Candidate."**

**Also [A]:** **rr. 17(1)(d)(i) / 18(1)(d)(i)** — an Indian citizen who was a regular student of a
**secondary/higher secondary school overseas** on an equivalent course may appear at the Board's
SSC/HSSC **as a private candidate**. **r.18(2)(a)** — a private HSSC candidate is eligible if
*"he/she has passed his/her secondary school certificate examination **or equivalent examination of
any Board**"* and **≥3 years** have intervened.

Related equivalence circulars **[B]**: **No. 55 of 13-08-2024** and **No. 48 of 01-09-2021**
("Scheme of Private Candidate & ITI Candidate (Applying for Equivalence) of SSC/HSSC");
**No. 38 of 16-06-2026** ("Equivalence Certificate for Grade 10 for persons with Intellectual
Disabilities").

### 3.7 Q6 — Migration Certificates — **YES, and Goa has the cleanest rule in the file**

**r.84, "Migration certificate", verbatim and in full [A]:**

> *"**84. Migration certificate.** - A migration certificate shall be issued to a candidate who has
> passed the secondary or higher secondary examination of the Board on receipt of application from
> the candidates after paying of specified fees."*

That is the entire rule. Note the eligibility trigger is **"has passed"** — *narrower than
MSBSHSE regs 60/107, which say **"has appeared at"***. **Two neighbouring boards, two different
thresholds; do not generalise either.**

Corroborated **[B]**, live site 2026-09-12: service tile **"Apply for Migration Certificate"** →
`https://service2.gbshse.in/cert_issue/document-issuance-portal/#/Login` (published 2023-05-10);
**Circular No. 32 of 20-05-2023** *"Online Migration certificate for SSC/HSSC 2023 onwards"*;
**Circular No. 16 of 21-02-2024** *"Revision of Migration fees for SSC/HSSC"*.

### 3.8 Q7 — Duplicates

**r.83, "Supply of duplicate passing certificate", verbatim [A]:**

> *"**83.** The Board shall issue a duplicate passing certificate on receipt of application **through
> the head of the institution from which the candidate appeared for the examination** on payment of
> specified fees. However, in the event of the **non-existence of the school** through which the
> candidate/s appeared for the examination, the duplicate certificate to such candidate/s shall be
> issued **directly by the Secretary on production of authentic identity.**"*

**This is word-for-word the same design as MSBSHSE regs 61/108 (§1.7)** — the school is a conduit,
and the conduit **breaks when the school ceases to exist**, at which point the Board deals with the
candidate directly. **Two independent boards, the same structure: that is now a pattern, not a
coincidence.**

**r.82, "Provisional certificate":** *"A candidate who has been declared successful at the
examination shall be issued a provisional passing certificate on application with the specified fees
through the head of the school."* **[A]**

⚠️ **No marking requirement, and no rule on duplicates of a SCHOOL-issued LC at all.** r.83
prescribes no "DUPLICATE" overprint, no serial cross-reference and no endorsement. *(Contrast
r.81(6), which **does** prescribe an endorsement — but that is for **corrections**, and on the
**reverse of the original**, not on a duplicate.)* **NOT FOUND.**

---

## 4 · KSEAB — Karnataka School Examination and Assessment Board

**Method note, because the negatives here are only as good as the method.** KSEAB's site is a
government CMS. Pages were fetched directly and **diffed against a known-populated control page** to
*prove* emptiness rather than infer it from a failed render. All items verified **2026-09-12**.

### 4.1 Q1 — Prescribes a TC format? **NO. [B, exhaustively enumerated]**

- **By-laws page publishes ZERO documents.** https://kseab.karnataka.gov.in/59/board-by-laws/en (and
  `/kn`) returns the full site template — 307 text lines — with exactly **one** document link, the
  site-wide footer RTI PDF, which is not a by-law.
- **Amendments page also empty** — byte-for-byte the same 307 template lines.
  https://kseab.karnataka.gov.in/62/amendments/en
- **The control test that makes this meaningful:** the same template at
  https://kseab.karnataka.gov.in/521/circulars%28sslc%29-/en renders **9 extra content lines**
  (year-wise circular groups). **The template does render content when content exists.** By-laws and
  Amendments genuinely have none published.
- **~160 circulars enumerated across four years** (SSLC Circulars 2025-26, 2024-25, 2023-24,
  2022-23). **Not one concerns transfer certificates, TC format or TC fields.** They are exclusively
  exam administration: student registration, admission tickets, evaluator orders, answer-key
  objections, revaluation/retotalling, internal-assessment marks entry, model papers, fee payment,
  and "New School Code" issuance.

**KSEAB prescribes no TC format and mandates no TC fields. Its remit is exam conduct and the
certificates IT issues.**

### 4.2 Q2 — Printed particulars? **NOT FOUND at board level — and the distinction matters. [B]**

Nothing in KSEAB's published output requires a recognition number, school code, board name or seal
wording on a school-issued TC.

**KSEAB does allot a "New School Code" annually** (repeated circulars dated 27-07-2022, 01-09-2023,
18-09-2024, 10-10-2025) — **but that code is for exam registration, and no KSEAB instrument requires
it on a TC.**

> ⚠️ **Correction of attribution — important for how we cite Karnataka.** The mandatory **DISE code
> on every TC** (Commissioner for Public Instruction circular **01.06.2016**, withdrawing the
> **25.05.2016** BEO-countersignature direction) is a **DEPARTMENTAL act of the School Education
> Department, not a KSEAB act.** KSEAB has no parallel or overlapping instrument. **In Karnataka the
> TC mandate does not come from the examination board at all.** `south-india.md` §1.5 records the
> circular correctly; this file fixes the layer it belongs to.

### 4.3 Q3 — Countersignature? **NOT FOUND at board level. [B]**
No KSEAB by-law or circular addresses TC countersignature. Both the 25.05.2016 imposition and the
01.06.2016 withdrawal were **departmental** acts.

### 4.4 Q4 — Post-issuance duty? **NOT FOUND. [B]**
Absent from the (empty) by-laws page, the (empty) amendments page, and four years of SSLC circulars.

### 4.5 Q5 — Admission from another board/state? **NOT FOUND as a KSEAB rule. [B]**
KSEAB circulars govern **exam-registration eligibility**, not school admission. No KSEAB equivalence
or eligibility-certificate instrument was located.

### 4.6 Q6 — Migration Certificates — **YES, KSEAB issues them. [B]**

https://kseeb.karnataka.gov.in/onlinemigration/ · 2026-09-12:

> *"ವಲಸೆ ಪ್ರಮಾಣಪತ್ರವನ್ನು … ಬೇರೆ ರಾಜ್ಯಗಳಲ್ಲಿ ವ್ಯಾಸಂಗ ಮುಂದುವರೆಸಲು ಅಗತ್ಯವಿರುವ …"*
> ("Candidates who have passed the SSLC examination from 2003 can obtain a Migration certificate
> online in order to continue their study in other states.")

Pre-2003 SSLC passes must apply physically. Flow: register number → OTP → online or challan payment
→ instant download.

**Under what regulation: NOT FOUND.** The service page cites no by-law, rule or G.O.; it is presented
as an administrative service only. (`Migration Letter_2.pdf`, the "Letter to Receiving Authority",
is a **scanned image with no text layer** and is in any case a covering letter, not a regulation.)

### 4.7 Q7 — Duplicates? **NOT FOUND. [B]**
KSEAB circulars cover **marks-card correction** (10-10-2023 online application for marks-card
correction; 26-10-2015 circular ED100DTB2014 on court-decreed name/DOB corrections) — **no
duplicate-certificate rule and no marking convention was found.**

---

## 5 · KERALA — the SSLC authority and DHSE

> **Kerala was the board barely touched by the first wave. It is given full treatment here.**
> Everything in §5 is **[A]**, read first-hand this session from the Kerala Education Rules chapter
> and forms PDFs published by the General Education Department, except where marked otherwise.

### 5.0 First, a naming trap that will otherwise corrupt the data model

**"SSLC" in Kerala is a BOARD examination certificate, not a school leaving certificate.** The
Kerala SSLC is the public Class-X qualification conducted by the Commissioner for Government
Examinations. The **school-issued** instrument is the **Transfer Certificate in KER Form 5** — and,
for over-age removed pupils, the **Leaving Certificate in Form 5A**.

Three Kerala documents therefore contain the words "leaving certificate" or behave like one, and
they are **legally distinct**:

| Instrument | Who issues | Authority |
|---|---|---|
| **SSLC** (Secondary School Leaving Certificate) | Commissioner for Government Examinations / Pareeksha Bhavan | board examination |
| **Transfer Certificate, Form 5** | Headmaster of the school | KER Ch.VI r.17(1) |
| **Leaving Certificate, Form 5A** | Headmaster, only for removed pupils **over 20** | KER Ch.VI r.17(3) |

**A module that maps "Kerala leaving certificate" to one record type will conflate a board
qualification with a school transfer document.**

### 5.1 Who the Kerala "board" actually is — and a correction

The official page reads: **"Pareekshabhavan (Office of the Commissioner, Government Examinations)
… the institution for conducting various Government examinations including SSLC, THSSLC, KGTE,
K-TET, D.Ed/D.El.Ed, Scholarship exams etc."** **[B]** ·
https://education.kerala.gov.in/pareekshabhavan/ · 2026-09-12. The board's own portal headers read
*"Kerala Pareeksha Bhavan, Office of The Commissioner of Government Examinations"*. **[B]** ·
https://www.keralapareekshabhavan.in/ and https://pareekshabhavan.kerala.gov.in/ · 2026-09-12.

⚠️ **"Kerala Board of Public Examinations (KBPE)" was NOT confirmed as a self-describing entity on
any official page read this session.** The operating authority everywhere in the primary material
is the **Commissioner for Government Examinations**. Treat "KBPE" as a colloquial label; **cite the
Commissioner for Government Examinations / Pareeksha Bhavan** in anything user-facing.

#### 5.1a Kerala DHSE — what the dead domain's archive does and does not show **[B]**

Because `dhsekerala.gov.in` is gone, the Internet Archive was used as the retrieval route.
**3,000 archived URLs from the domain were enumerated** (Wayback CDX, `matchType=domain`,
2026-09-12). Filtering for `migrat|equival|eligib|transfer|certif|admis|prospect` yields, in total:

- `…/downloads/ApplicationformforDuplicateCertificateFlood2019.pdf` — **DHSE issued DUPLICATES of
  its own HSE certificate** (the 2019 floods scheme). *(Retrieved — it is a scanned image with no
  text layer, so its contents are not quoted.)*
- `…/downloads/Certificatecorrectionsform.pdf` — **corrections to DHSE's own certificate**.
- `…/admission_ticket.aspx` — exam admission tickets.
- `…/ general transfer`, `…/downloads/Admissionobserves.doc` — **staff** transfers and admission
  monitoring, not student certificates.

**No TC format, no TC rule, and NO MIGRATION-CERTIFICATE page appears anywhere in 3,000 archived
DHSE URLs.** That converts DHSE's Q1/Q2/Q3/Q6 from "unsearched" to **an evidenced negative at level
B** — with the caveat that a rule buried inside one of the ~hundreds of date-coded circular PDFs
(`downloads/circulars/0101190110_HSE-PE-CC.pdf` and the like, whose filenames encode a date, not a
subject) would not have been seen.

**Kerala Higher Secondary — the directorate has been absorbed.** `dhsekerala.gov.in` **does not
resolve** (DNS failure on repeated attempts, 2026-09-12; the link is still present but dead on the
department's own DHSE page). Live HSE circulars are now issued by the **"Office of the Director of
General Education, Higher Secondary Branch"** — the letterhead on the 2026-27 single-window
circulars, e.g. **No. ICT Cell/1771/1/DGE-HSS/2026 dated 25/05/2026**, citing **G.O.(Rt)
No. 3529/2026/Gen.Edn. dated 25/05/2026**. **[B]** ·
https://control.hscap.kerala.gov.in/admin/uploads/cms/20260525141037.pdf · 2026-09-12.
*(⚠️ Those circulars are published in a legacy non-Unicode Malayalam font; the letterhead, file
number and G.O. reference are legible but the body text was **not** reliably decodable and nothing
from it is asserted here.)*

### 5.2 Q1 — Does the Kerala board prescribe a TC format? **No — the STATE does, and it is Form 5.**

**KER Chapter VI r.17(1)**, verbatim:

> *"Transfer certificate **in form 5** may be issued by the Headmaster on any day during the summer
> vacation **and for sufficient reasons at other times**. But Transfer certificates may be issued by
> the Headmaster **at any time to pupils who have appeared for a public examination**;"*

**r.17(3):** *"If a pupil who has been removed from the rolls of a school is **over 20 years of
age**, no transfer certificate shall be issued to him from that school for admission to any other
school unless previous sanction under sub-rule (2) of Rule 5 has been obtained. But a **leaving
certificate in form 5A** may be issued, if required."*

**[A]** · https://education.kerala.gov.in/wp-content/uploads/2019/11/Chapter_6.pdf · 2026-09-12.

**Form 5 is made by the State Government under the Kerala Education Act 1958. No examination board
authored it, and no board rule adds to it.**

#### FORM 5 — the prescribed Kerala Transfer Certificate, transcribed first-hand **[A]**

Heading: **"FORM- 5 · [See rule VI-17(1)] · TRANSFER CERTIFICATE"**. Bilingual English/Malayalam,
every field label repeated in Malayalam. Source:
https://education.kerala.gov.in/wp-content/uploads/2019/11/Forms.pdf · 2026-09-12.

**Header block (2 entries):**
1. **Name of School**
2. **Whether the School is a Government, Aided or Recognised School**

**Body fields, in printed order:**
3. Name of Pupil
4. Name of Parent / guardian **and relationship of the pupil to the guardian** *(inserted by
   G.O.(P) 260/75/G.Edn. dt. 24-10-75, gazette 11-11-75)*
5. **Identification marks, if any, of the pupil**
6. Nationality
7. Religion *(the words "community and" were **deleted** by G.O.(P) 99/62 dt. 07-02-62, gazette
   13-2-62)*
8. Whether the candidate belongs to Scheduled Castes / Scheduled Tribes / other Backward
   Communities, or is a **convert from** SC/ST
9. **Date of birth according to Admission Register (in words)**
10. **Standard in which the pupil was last enrolled (in words)**
11. Date of admission or promotion to that standard
12. Whether qualified for promotion to a higher standard
13. **Whether the pupil has paid all the fees due to the school**
14. Whether the pupil was in receipt of fee concession
15. Date of pupil's last attendance at school
16. Date on which the name was removed from rolls
17. Date of application for certificate
18. Date of issue of the certificate
19. Reason for leaving
20. School to which the pupil intends proceeding
21. Date of last successful vaccination
22. Number of School days up to the date
23. Number of School days the pupil attended

**Signature block:** *"Principal Headmaster/Headmistress"* — **one signatory, no countersignature
line.**

**Notes printed on the form:**
> *"N.B. 1. Fee concession/Scholarship history of the pupil may be entered below when necessary.
> 2. In the case of pupils of Higher Standards, **details of the courses of the studies should be
> furnished below**."*

**FORM 5A — "[See Rule VI-17(3)] LEAVING CERTIFICATE ISSUED TO OVER AGED PUPILS REMOVED FROM THE
ROLLS OF SCHOOLS"** is a **single-sentence narrative certificate**, not a field table: name, school,
standard admitted/promoted to and date, date left, standard then reading in, and date of birth
per the school admission register — all standards **in words**. Signed *"Headmaster … School"*.
*(Inserted by notification gazetted 6-2-62.)*

**The note that follows Form 5A and governs both:**
> *"[Note:- **All certificates to be sealed with the school seal before issue**]"*

### 5.3 Q2 — What must be PRINTED? **A status line and a seal. No code, no number, no board name.**

- **Status line, mandatory, on the face of Form 5:** *"Whether the School is a Government, Aided or
  Recognised School"*. This is Kerala's functional equivalent of Tamil Nadu's *"Recognized by the
  Department of School Education"* line — a **declaration of recognition status**, not an
  identifier. **[A]**
- **School seal, mandatory** — the Form 5A note, *"All certificates to be sealed with the school
  seal before issue."* **[A]**
- **A TC number exists and is tracked**, because the Admission Register Form 4 records *"No. and
  date of transfer certificate granted on leaving"* (§5.5). The **number is therefore required by
  implication of the register**, though Form 5 itself prints no numbered slot for it. Recorded as
  such — **do not state that Form 5 mandates a serial field; it does not.** **[A]**
- **NOT FOUND:** any requirement that a **school code**, **SSLC school number**, **UDISE/DISE code**,
  **recognition order number**, or the **board's name** appear on a Kerala TC. Searched: the full
  KER Chapter VI text and the full KER Forms PDF (greps for code/number/register/index), the
  Pareeksha Bhavan portals, and the General Education Department site search. **Kerala has made no
  move equivalent to Karnataka's 2016 DISE-code mandate or Tamil Nadu's recognition-number
  requirement.**

### 5.4 Q3 — Countersignature. **No for a Kerala TC. Yes for an inbound out-of-State one.**

**KER Chapter VI r.10**, verbatim:

> *"**Admission of pupils migrating from other States** – Pupils migrating from schools in other
> States of India or outside India **with transfer certificate or other equivalent document
> countersigned by the Inspecting Officer** may be admitted to the Standard corresponding to the one
> to which they are eligible according to the transfer certificate or equivalent certificate
> provided:- (1) those schools are institutions **recognised by the respective Governments**;
> (2) that **not more than two months have elapsed** since the issue of the transfer certificate or
> equivalent document; … (4) that the pupils are **tested and found fit** for admission to that
> Standard; (5) that the pupils have completed the minimum age as prescribed in rule 5 or 9."*
>
> *"Note:- Such admission **after the lapse of two months require the sanction of the Educational
> Officer**."*

**[A]** — same source as above.

Again: this is an **inbound-admission** duty on the receiving Kerala school, not a precondition on
any TC a Kerala school issues. **Form 5 has no countersignature block at all.**

**One internal Kerala countersignature-adjacent rule, which is different and must not be confused
with it — r.19(2):**
> *"When a transfer certificate is issued **with the sanction of the Educational Officer or the
> Director**, the **number and date of the sanction shall be entered in the transfer certificate
> over the signature of the Headmaster**."*

That is an **endorsement by the Headmaster of a sanction already obtained**, not a signature by the
officer. It bites in the cases where sanction is needed: r.17(3) (removed pupil over 20),
r.18(4) (pupils removed under r.15(vi)-(vii)), and r.21 (grouped neighbouring schools).

### 5.5 Q4 — Post-issuance duty. **A statutory register, first-hand.**

**KER Chapter VI r.2(1):** *"**Every School shall maintain an Admission Register in Form 4.**"*
r.2(2) requires the pupil's particulars to be entered and **attested by the Headmaster**; r.2(3)
requires the date of birth in **words as well as figures** and that *"the entry shall not bear any
marks of erasure or overwriting."* **[A]**

**FORM 4 — ADMISSION REGISTER [See Rule VI-2(1)]**, transcribed first-hand. 16 numbered columns:
No. · Name · Name of parent or guardian and relationship · Occupation of parent/guardian and his
residence · Schools previously attended and periods spent in each standard · Date of Admission ·
Date of Birth · Religion · SC/ST/OBC or convert status · Standard on admission · **10A** Mother
tongue · **10B** Language in which the pupil desires to be instructed · Standard on leaving · Date
of leaving · **13 · "No. and date of transfer certificate PRODUCED ON ADMISSION"** · **14 · "No. and
date of transfer certificate GRANTED ON LEAVING"** · Reasons for leaving · Date of vaccination ·
**16A** immunisation details · **16B** *"Whatever be the entry under item 16A admission shall be
given to the applicant"* · Remarks. **[A]**

**Columns 13 and 14 are the Kerala post-issuance duty, and they are two-directional** — the register
records both the TC the school **received** and the TC it **granted**, each with a number and date.
This is the same register-is-authority model as Maharashtra's General Register and Gujarat's
નમૂનો-૧૦, and it is the reason a Kerala TC must carry a number even though Form 5 prints no slot for
one.

**16B is independently notable** *(inserted by G.O.(P) 173/87/G.Edn. dt. 17-8-87, gazette 1-9-87)*:
immunisation status **can never be a ground to refuse admission**. The identical note appears on the
application for admission form. A product must not gate Kerala admission on vaccination data.

**NOT FOUND — any duty to UPLOAD a TC or file a return with the board.** Kerala runs **Sampoorna**,
the state school-management system, described officially as *"a one-stop source for all details of
students such as **Transfer Certificate**, various reports, entry forms"* **[B]** ·
https://education.kerala.gov.in/2019/12/10/sampoorna-2/ · 2026-09-12. **But no G.O. or circular
making Sampoorna-generated TCs mandatory was located**, and the page itself cites none. Searched:
the General Education Department site search for "transfer certificate", "Sampoorna TC" and
"migration certificate" (the only hits are the two Sampoorna pages and the Citizens' Charter); the
DGE Citizens' Charter page (renders as navigation only — **the charter's service table was not
retrievable**); the Kerala Right to Service notification for the department
(https://kite.kerala.gov.in/KITE/SEVANAAVAKASHANIYAMAM-DGE.pdf — **an 8 MB scanned image with no
text layer**, not readable without OCR). **⚠️ Sampoorna is very likely the system of record for
Kerala TCs in government and aided schools; if so our module is a parallel writer, not the
authority. This is the largest commercial gap in the Kerala record and should be closed before
selling into Kerala.**

### 5.6 Q5 — Admission from another board or state

**The TC gate, and its out-of-state exemption — KER Ch.VI r.6:**
> *"(2) **No pupil shall be admitted to any Standard other than Standard I without the production of
> a transfer certificate** from a school except as a private study pupil under Rule 7.*
> *Note:- The Director may grant exemption in suitable cases taking into consideration the merits
> thereof.*
> *(3) **No pupil who has previously attended any school shall be admitted to another school without
> the production of a transfer certificate** from the school last attended by him.*
> *(4) **Nothing in this rule shall apply to pupils migrating from other States with T.C. who have
> completed S.S.L.C or equivalent course or appeared for S.S.L.C or equivalent Examination.**"*

**[A]**. r.14(3) carries the identical carve-out for the sanction machinery.

⚠️ **Note the direct tension with RTE s.5(3)** (`central-and-boards.md` A1.1): KER r.6(2)-(3) makes
a TC a **precondition of admission** for every standard above I. For classes I-VIII that cannot
stand against RTE s.5(3)'s *"delay in producing transfer certificate shall not be a ground for
either delaying or denying admission"*. **Recorded as a conflict — the rule is unamended on the
books.** It is the same shape as the KER r.17(2) no-dues conflict already documented in
`south-india.md` §2.5.

**A procedural rule with real product consequence — r.14(2):**
> *"The Headmaster of the school in which a pupil seeks admission **shall not apply for a transfer
> certificate to the Headmaster of the school which the pupil is leaving**, but shall **leave it to
> the parent or guardian** of the pupil to apply for and produce such certificates."*

**[A]**. **A school-to-school "request TC from previous school" feature is affirmatively prohibited
in Kerala.** The parent is the only lawful requester. This is the sharpest single constraint the
Kerala record puts on a TC workflow, and no other state in the corpus states it so explicitly.

**Board-level equivalence for other-board entrants: NOT FOUND.** Pareeksha Bhavan's public service
list does **not** include an equivalency certificate for CBSE/ICSE/other-state Class X passes. Its
*"Standard Xth Equivalency"* programme (http://xequivalency.kerala.gov.in, 2026-09-12) is something
else entirely — a **qualifying examination for dropouts aged 17+ who passed Class VII or the
Class VII equivalency course**, offered in Malayalam, Kannada and Tamil, issued by the Office of the
Commissioner of Government Examinations. **[B]** — **do not model it as an inter-board equivalence
route.** The Higher Secondary single-window admission (HSCAP) requirements for other-board
candidates were **NOT retrievable in English** (see the NOT FOUND list).

### 5.7 Q6 — Does the Kerala board issue Migration Certificates? **Yes — Pareeksha Bhavan does.**

The Pareeksha Bhavan portal lists, under certificate services: **"Duplicate Certificate"**,
**"Migration Certificate"**, "Date of Birth Correction", "Other Corrections", plus duplicate
certificates for KTET and KGTE. **[B]** · https://www.keralapareekshabhavan.in/ · 2026-09-12.

**The regulation or G.O. authorising the Kerala migration certificate was NOT FOUND** — the portal
cites none, and no fee, eligibility condition or rule number was obtained. **Level B, and thinner
than the Maharashtra equivalent (regs 60/107) — do not state a Kerala migration-certificate rule,
only that the board provides the service.**

**What is nonetheless safe to enforce:** the migration certificate is a **board** service in Kerala
as everywhere else in this corpus. **A Kerala school must not issue one.**

### 5.8 Q7 — Duplicates. **KER r.22, and it is unusually specific.**

> *"**22. Issue of duplicate transfer certificate** - In cases of loss or irremediable damage to
> transfer certificates, duplicate may be issued by the Headmaster **on payment of a fee of Rupee
> one**. No application for a duplicate transfer certificate shall be entertained unless it is
> accompanied by a **chalan for Rupee one** and a **certificate from a Gazetted Officer or the
> President of a local authority or a member of Legislative Assembly or a member of Parliament** to
> the effect that the original is **irrecoverably lost or damaged**. **Duplicate certificate issued
> should be clearly marked 'Duplicate'.**"*

**[A]**. Note the contrast: Maharashtra SS Code r.30 requires *"'Duplicate' in **red ink at the
top**"*; Kerala requires only *"clearly marked 'Duplicate'"* with no colour or position. Tamil Nadu
(TNER r.44, compilation version) requires *"duplicate" in red ink* and **once only**. **Three
states, three different marking rules — a single hard-coded duplicate watermark will be wrong in at
least one of them.**

### 5.9 A prescribed Kerala certificate the first wave missed — **KER r.22A, Certificate of School Education**

*(Inserted by amendment; the form is printed in the rule itself.)* **[A]**

> *"**22A. Issue of Certificate of School Education** - A Certificate in the form given below may be
> issued by the Headmaster of the school to any pupil who left/leave the school **before appearing
> for the S.S.L.C Examination**. The Certificate shall be issued only on application and on
> **remittance of a fee of rupees ten into Government treasury and production of the chalan
> receipt** thereof."*
>
> *"Provided that the **daughters of widows need not pay the prescribed fee** for the certificate,
> if it is to be produced along with the application for financial assistance for their marriage.
> The Headmaster shall mention in such certificate that the same is issued for the purpose of
> applying for financial assistance for marriage."*

**The prescribed text, verbatim:**
> *"**CERTIFICATE OF SCHOOL EDUCATION** — This is to certify that \*------------------- son/daughter
> of ----------------------- was pupil of this school from -------------- to ------------------ and
> that he/she left the school on ---------- after having passed from Standard ---------------- (in
> words) / he/she was removed from the rolls on -------------- due to long absence while he/she was
> studying in standard --------------- (in words) / he/she discontinued his/her studies after having
> failed in standard --------------- (in words). His/Her date of birth is ------------- (in words)
> as per school records.  Station: … Date: … **seal** … Headmaster, ----------- School"*
>
> *"\* Here enter the name of the pupil **in block capitals with full address**"*

**This is a fourth distinct Kerala school-issued instrument**, alongside Form 5, Form 5A and the
bonafide/conduct documents — and it is **statutorily worded**, so it can be templated exactly. Note
the three mutually exclusive leaving branches (passed / removed for long absence / discontinued
after failing) — a template must select one, not print all three.

### 5.10 Kerala TC timing and refusal rules, for completeness **[A]**

Read first-hand this session and consistent with `south-india.md` §2.4:

- **r.17(2)** — *"No transfer certificate shall be issued to a pupil from whom there are any dues to
  the school."* **Still unamended.** The Note apportions the monthly fee instalment between the old
  and the new school by transfer date. *(The live conflict with the Kerala High Court line is fully
  documented in `south-india.md` §2.5 and is not re-litigated here.)*
- **r.18(1)** — TCs of pupils removed under r.15(i) *"may be issued by the Headmaster **at any
  time**"* on application.
- **r.18(2)** — TCs of pupils removed under r.15(iii)/(iv) issue *"on payment of all dues to the
  school."*
- **r.18(3)** — **no TC during a period of suspension** (r.15(v)).
- **r.18(4)** — TCs of pupils removed under r.15(vi)/(vii) *"shall not be issued without sanction of
  competent authority."*
- **r.18(5)** — where the guardian **must change place of residence**, the Headmaster may issue *"at
  any time of the year"* on being *"satisfied about the bonafides of the case"*.
- **r.19(1)** — a sanctioned TC needs **no separate sanction** for admission elsewhere if admission
  is sought **within two months**; after two months, separate sanction is required.
- **r.20** — **right of appeal to the Educational Officer** against refusal *or delay*, whose
  decision is final unless he refers it upward.
- **r.21** — the Director may **group** neighbouring schools so that no TC issues between schools of
  the same type within a group without the Educational Officer's sanction (anti-poaching).
- **r.15** — a pupil is removed from the rolls when he has passed the highest class, **or his
  transfer certificate has been issued**, or he has been absent without leave for **fifteen
  consecutive working days**, or **continuously absent for 5 working days from the re-opening day**.

**r.15(ii) matters for the data model: in Kerala, issuing the TC is itself the event that removes
the pupil from the rolls.** Enrolment status and TC issuance are not independent fields.

### 5.11 Q8 — How KER Form 5 and the board's requirements interact — **the answer**

**They barely interact, and that is the finding.**

1. **Form 5 is a State Government instrument**, prescribed by KER Chapter VI r.17(1) under the
   Kerala Education Act 1958. **Neither Pareeksha Bhavan nor DHSE prescribes, approves, formats or
   countersigns it.** No board rule adds a field to it, and Form 5 carries **no board name, no
   school code and no exam-registration number**.
2. **The board's only reach onto Form 5 is temporal, through r.17(1)**: the ordinary rule confines
   TC issue to the **summer vacation** (plus "sufficient reasons at other times"), **but that
   restriction is lifted "at any time" for pupils who have appeared for a public examination** —
   i.e. **the board examination is what unlocks year-round TC issuance.** That is the single
   operative link between the two regimes, and it is a *state* rule referring to a *board* event.
3. **The board owns the instruments Form 5 is not**: the SSLC certificate itself, its duplicates,
   and the Migration Certificate (§5.7). A Kerala school issues Form 5 / Form 5A / the r.22A
   Certificate of School Education and nothing further.
4. **Direction of travel is the reverse of Karnataka and Tamil Nadu.** Those two states pushed a
   board/department identifier *onto* the school TC (DISE code in 2016; recognition number + DGE
   school number). **Kerala has not.** Its authenticity mechanism is the older one — a **status
   declaration** ("Government, Aided or Recognised"), the **school seal**, the **Educational
   Officer's sanction number endorsed under r.19(2)** where sanction was needed, and the **Form 4
   register** behind it.
5. **Practical consequence for the module:** a Kerala TC template must **not** print a board name or
   a school code (there is no legal basis and it would misrepresent the instrument), **must** print
   the Government/Aided/Recognised status line, **must** carry the school seal, and must record the
   TC number and date into the Form 4 admission register at issue.

---

## 6 · TAMIL NADU — Directorate of Government Examinations (DGE)

> ### 🔴 THIS SECTION CORRECTS `south-india.md` §3.3a AND §3.4. READ IT BEFORE SHIPPING ANY TN RULE.
>
> The prior wave asserted, citing **https://indiankanoon.org/doc/24617366/**, that TNER **rule 34**
> requires *"the school number assigned by the Director of Government Examinations"* on every TC.
> **That page does not contain that sentence.** It was downloaded and searched this session:
> `grep -i "school number"` returns **zero hits**. The claim is supported by a **different, single,
> uncorroborated document** — and corroboration was attempted and failed. Details at §6.8.

### 6.0 The framing that the first wave got right and must be preserved

**DGE prescribes nothing about school-issued TCs.** Tamil Nadu's TC rules come from **TNER (the
Tamil Nadu Educational Rules — a *departmental* code)** and from the **Code of Regulations for
Matriculation Schools**. DGE is *named inside* TNER as the assigner of a school number — which is a
very different thing from DGE issuing the rule.

This was checked directly against DGE's own published statement of functions:
https://dge.tn.gov.in/function.html and https://dge.tn.gov.in/certificate.html · 2026-09-12. DGE's
stated functions are exclusively **exam conduct** (SSLC, HSE, DEE, ESLC, Technical, TRUST, NMMS,
NTSE) and issuing or duplicating **its own** mark certificates. **Neither page contains any
instruction about school-issued transfer certificates.** *(⚠️ DGE's site is stale — its latest news
items date to 2019.)*

### 6.1 Q1 — Is a TC format prescribed? **Yes — by TNER and the Matriculation Code, not by DGE.**

#### (a) TNER rule 34 and Appendix-5 / 5-A — **[C]** (see §6.8 for why this is C, not A)

Source: the consolidated *Tamil Nadu Educational Manual*, `educationalrules.PDF`, 123 pp, PDF author
"Thamizhagam", created **2003-05-05**, retrieved from
https://www.johnsonasirservices.org/web/Downloads5/29.R.TN%20educational%20rules%20.pdf ·
verified 2026-09-12.

> *"34. No pupil who has previously studied in a recognized high and higher secondary school shall be
> admitted to another recognized high and higher Secondary school unless he/she presents a transfer
> certificate in the prescribed form **(appendix-5 & 5A)** from that school showing (a) the date of
> his/her birth (b) that he/she has paid all fees due to that school (c) the standard in which
> he/she studied at the time of leaving it, and (d) if he/she has completed the course in that
> standard, whether he/she is qualified for promotion to a higher standard…"*
>
> *"A common transfer certificate form for standards I to X and XI and XII as prescribed in
> Appendix-5 and 5A should be adopted. **All columns in the transfer certificate must be filled up
> without any omission.** Father or mother or guardian **and the pupil** should sign the transfer
> certificate at the time of receiving transfer certificate."*

**APPENDIX-5 — field list, transcribed exactly.**

*Masthead identifier boxes (eight, and they are separate fields):* `Hr.Secy.TMR Code No.` ·
`Hr.Secy. Certificate Sl.No.` · `Hr.Secy.Reg.No.` · `School No :` · `SSLC Marksheet SL.No.` ·
`TMR Code No.` · `SSLC Reg. No.` · `Admission No.`

*Printed header:* `GOVERNMENT OF TAMIL NADU / DEPARTMENT OF SCHOOL EDUCATION / TRANSFER CERTIFICATE
/ Recognized by the Department of School Education`

1. a. Name of the School; b. Name of Educational District; c. Name of the Revenue District
2. Name of the Pupil (in Block Letters) — **Tamil / English**
3. a. Name of the Father of the Pupil; b. Name of the Mother of the Pupil
4. Nationality, Religion and Caste
5. Community — a. Adi Dravidar (SC/ST); b. Backward Class; c. Most Backward Class; d. Converted to
   Christianity from Scheduled Caste; e. Denotified Communities
6. Sex
7. **Date of Birth, as entered in the admission Register, in figures and words**
8. Personal marks of identification as in SSLC / Matric / TC (a) (b)
9. Date of admission and standard in which admitted (**the year to be entered in words**)
10. a. Standard in which the pupil was studying at the time of leaving (**in words**); b. The course
    offered, i.e. General Education or Vocational Education; c. …subjects offered under Part III
    Group-A and Medium of Instruction; d. …Vocational subject under Part III Group-B and the related
    subject under Part III Group-A; e. Language offered under Part I; f. Medium of Study
11. Whether qualified for promotion to higher standard under Higher Secondary Education rules
12. **Whether the pupil has paid all the fees due to the school**
13. Whether the pupil was in receipt of any Scholarship (nature to be specified) or any Educational
    concession
14. Whether the pupil has undergone medical inspection during the academic year (first or repeat to
    be specified)
15. Date on which the pupil actually left the school — **15(a)** No. of working days, days attended
    with percentage
16. The pupil's conduct and character
17. Date on which application for Transfer Certificate was made on behalf of the pupil by his Father
    or Mother or Guardian
18. Date of the Transfer Certificate
19. Course of study *(table: Name of the School | Academic Year(s) studied | Standard(s) | First
    Language | Medium of Instruction)*
20. **Signature of the Head of the Institution, Date and School Seal**

⚠️ *The source document's own numbering is faulty — it prints "11" twice. The list above is
renumbered to match the sequence. **Flag this if the form is ever encoded.***

**APPENDIX-5-A** is a shorter variant with the same substance, header *"Recognized by the Department
of School Education of Tamil Nadu"*, masthead `TMR Code No.` · `Serial No :` · `SSLC Reg. No.` ·
`Admission No.` · `School No :`, items 1–19.

#### (b) Matriculation schools are a SEPARATE regime — **[A]**, primary regulation read in full

*Code of Regulations for Matriculation Schools*, **Annexure V** ·
https://indiankanoon.org/doc/37801442/ · verified 2026-09-12:

> *"Annexure V — Form of Transfer Certificate — **Number:** 1. Name of the school which the pupil is
> leaving 2. Name of the pupil 3. (a) Name of the father (b) Nationality, Religion and Caste
> (c) Community — State whether the pupil belongs to — (i) Adi-Dravidar (SC or ST) (ii) Backward
> Class (iii) Most Backward Class (iv) Converts to Christianity from SC or ST (v) Denotified
> Communities 4. Date of Birth **in words** as entered in Admission Register. 5. Standard in which
> the pupil was reading at the time of leaving (**in words**). 6. Date of admission or promotion to
> that standard. **The year to be entered in words.** 7. Whether qualified for promotion to a higher
> standard under the Code of Regulations of Matriculation Schools. 8. **Whether the pupil has paid
> all the fees due to the school.** 9. Date on which the pupil actually left the school. 10. Date on
> which application for transfer certificate was made on behalf of the pupil by his guardian/parent.
> 11. Date of Transfer Certificate 12. Signature of the Principal."*

**⚠️ The Matriculation form carries NO recognition number and NO school number — only "Number:" at
the top.** A single "Tamil Nadu TC template" is therefore wrong: **the TNER form and the
Matriculation form differ precisely on the identifiers.**

### 6.2 Q2 — Printed particulars — **see the verdict at §6.8. Do not ship as an assertion.**

### 6.3 Q3 — Countersignature. **No for a TC issued within Tamil Nadu; yes for an inbound one.**

**TNER [C]:** rule 34 / the Appendix notes require only *"Should be **signed in ink by the Head of
the Institution** who will be held responsible for the correctness of the entries"*, **plus the
pupil's and the parent's signatures at the time of receipt**, plus the designation seal. **No
countersigning authority appears anywhere.**

**Matriculation Code r.12(x) [A]** · https://indiankanoon.org/doc/37801442/ · 2026-09-12:
> *"**Transfer certificates received from other States should bear the counter signature of the
> Inspecting Officers of the concerned State.**"*

That is a countersignature on the **incoming** certificate, executed **in the sending state** — not
on a TC a Tamil Nadu school issues. **This is the same inbound-only pattern as every other
jurisdiction in this corpus.**

*(Note: the parent-and-pupil signature requirement in TNER is unusual and worth implementing — TN is
the only state in the corpus where the **pupil** signs the TC on receipt.)*

### 6.4 Q4 — Post-issuance duties. **Mixed, and the two codes must not be merged.**

**TNER rule 34 [C]:**
> *"All transfer certificates must be **endorsed with the admission number** under which the pupil is
> enrolled. **They shall be separately filed and shall be shown to the Inspecting officers.**"*
>
> *"**Office copy of the transfer certificate should bear the words "Office Copy" in bold letters.**
> The **designation seal** of the Head of the Institution should bear the **full name and full postal
> address of the institution with pin code.**"*

**Matriculation Code [A]:**
> *"**The school will maintain counter-foil for all transfer certificates issued.** The Registers will
> be maintained properly and the returns required by the Department will be furnished promptly by the
> management. **This will be one of the conditions of recognition.**"*

⚠️ **Do not merge these.** The **TC counterfoil duty is Matriculation-Code-specific.** The TNER copy
read has **no** TC counterfoil requirement — the only "counterfoil" in that document is at **rule
88** and concerns **fee receipts**. Attributing a counterfoil duty to TNER would be inventing a rule.

**No upload duty and no TC-specific return duty** in either instrument.

### 6.5 Q5 — Admission from another board or state — **[A] Matriculation Code**

https://indiankanoon.org/doc/37801442/ · 2026-09-12:
> *"12(viii) **Transfer certificate received from other States for admission into Standard IX and X
> will be sent to the Inspector for evaluation.**"*
> *"12(x) Transfer certificates received from other States should bear the counter signature of the
> Inspecting Officers of the concerned State."*
> *"12(iv) **Age rules need not be applied** in other standards for pupils who are coming for
> admission with Transfer Certificates from **recognised** schools."*
> *"12(v) **Age rules should be applied** for pupils coming from **unrecognised** private schools…"*
> *"12(ii) A pupil with a valid Transfer Certificate shall be admitted to the Standard to which the
> Transfer Certificate declare him/her fit. **The pupil should not be placed in a class higher or
> lower.**"*

**TNER rule 37(a) [C]:**
> *"No student shall be admitted in any Standard without proper transfer certificate or Record sheet
> issued by a recognized institution. **Students coming from Unrecognized schools or after Private
> study should not be admitted.**"*

**No eligibility or equivalence certificate requirement was found.** The Tamil Nadu mechanism is
**Inspector evaluation of the foreign TC + sending-state countersignature** — not an equivalence
certificate. *(Contrast Maharashtra regs 80/81 and the AP/TG Intermediate boards, all of which do use
eligibility/equivalency instruments.)*

### 6.6 Q6 — Does DGE issue Migration Certificates? **Yes, as an administrative service. [B]**

https://dge.tn.gov.in/docs/services/mig_information.pdf · verified 2026-09-12:
> *"Name of the Scheme: **Issuing Migration Certificates** for Students desirous of continuing studies
> in abroad or in other states of India."*
> *"Object: To help the students those who wish to go for further studies after completing
> **SSLC/OSLC/Matriculation/Anglo Indian/Higher Secondary** Examinations conducted by this
> Department."*
> *"Fees: … demand draft for **Rs.505/-** … or challan for Rs.505/- … in favour of Director of
> Government Examinations, Chennai – 600 006."*
> *"Officer to be approached: **Deputy Director of Government Examinations, Chennai – 6.**"*

**The regulation behind it: NOT FOUND.** The document is written as a citizen-service scheme sheet
and cites no rule, regulation or G.O.

### 6.7 Q7 — Duplicates. **Two regimes — do not conflate.**

**(a) Duplicate school TC — TNER rule 44 [C]:**
> *"When a pupil applies for Duplicate Transfer Certificate a fee at the above rate shall be
> collected. **The copy should clearly bear the mark "duplicate" in red ink. It shall be issued only
> once.**"*

Fee slab in the same rule: *within a year — no fee; after one year up to 5 years — ₹10; above 5
years — ₹50.*
⚠️ **This conflicts with the Indian Kanoon TNER text — see C3 at §6.9.**

**(b) Duplicate DGE-issued mark certificate [B]** ·
https://dge.tn.gov.in/docs/services/Dup_information.pdf · 2026-09-12: **₹505** first duplicate,
**₹755 "Triplicate"**; routed **through the Headmaster → DEO/DIET**; requires a loss certificate from
a Revenue official *"not below the rank of Thasildhar"*; private candidates apply direct *"with the
**Counter Signature of the Head Master of the nearby school**."* **No red-ink or marking convention
is specified for these.**

*(Note the "nearby school" countersignature — an identity-attestation device, not a validity
requirement, and the only place in the whole corpus where a school vouches for a stranger.)*

### 6.8 🔴 VERDICT — the "recognition number + DGE school number" question

**PARTIALLY CONFIRMED IN SUBSTANCE, BUT THE PRIOR CITATION IS WRONG AND CORROBORATION FAILED.
Do not ship this as an asserted legal requirement.**

#### (1) The cited source does not say what it was said to say — **verified false**

https://indiankanoon.org/doc/24617366/ was cited for rule 34's school-number sentence. It **does not
contain it**. The page was downloaded and its raw text searched: `grep -i "school number"` → **zero
hits**. Indian Kanoon's rule 34 reads, verbatim (verified 2026-09-12):

> *"34. No pupil who has previously studied in a recognised **secondary** school shall be admitted to
> another recognised secondary school unless he presents a transfer certificate in the prescribed
> form (**Appendix 5**) from that school showing (a) the date of his birth, (b) that he has paid all
> fees due to that school, (c) the standard in which he studied at the time of leaving it, and (d) if
> he has completed the course in that standard, whether he is qualified for promotion to a higher
> standard and in the case of a pupil who has at any time received a fee concession **under rule 92**
> in the school previously attended by him, also his **case-sheet (Appendix 19)**. No pupil shall be
> allowed to attend school pending formal admission or enrollment… All transfer certificates shall be
> endorsed with the admission number under which the pupil is enrolled. They shall be separately
> filed and shall be shown to the **District Educational Officer** when required."*

**It ends there.** No school number. No Appendix 5-A. No recognition wording. No "Office Copy". No
designation-seal spec. **This is an older recension** — "secondary school" not "high and higher
secondary", Appendix 5 alone, a rule-92 case-sheet the newer text drops, and the **DEO** rather than
"Inspecting officers".

#### (2) The substance rests on exactly ONE source

The johnsonasir *Tamil Nadu Educational Manual*, rule 34, verbatim:
> *"Office copy of the transfer certificate should bear the words "Office Copy" in bold letters. The
> designation seal of the Head of the Institution should bear the full name and full postal address
> of the institution with pin code. **The transfer certificate should also possess the school number
> assigned by the Director of Government examinations.** The Head of the institution in Appendix 5 B
> shall issue separate conduct certificate on demand."*

And the **Appendix-5-A** note, verbatim:
> *"Note: 1. **School under Private management shall have the words "Recognized by the Department of
> Education Chennai" with Recognition Number printed on the Transfer Certificate to be issued by
> them.** 2. **The transfer certificates issued by the School under private management without the
> word "Recognized by the Department of Education Chennai" shall not be considered valid.**"*

**But the Appendix-5 note in the SAME document is materially different:**
> *"Note: Schools under Private Management shall have the words "Recognized by the Department of
> Education Chennai" printed in the Transfer Certificate to be issued by them. 1. Transfer
> Certificates issued by the **Higher Secondary Schools** under Private Management without the words
> "Recognized by the Department of Education, Chennai" shall not be considered valid."*

**Appendix-5 omits the Recognition Number entirely**, and narrows the invalidity sanction to private
**Higher Secondary** schools. **The document contradicts itself between its own two prescribed
forms.**

#### (3) Corroboration was attempted and FAILED

| Attempt | Result |
|---|---|
| Indian Kanoon phrase search `"school number assigned by the Director of Government examinations"` | **"No matching results"** |
| Control query `"transfer certificate" "Director of Government Examinations" doctypes:tamilnadu` | **65 results** — so the search works; the absence is real |
| IK `"Recognized by the Department of Education, Chennai"` | **no results** |
| IK `"Appendix 5-A" OR "Appendix 5A" "transfer certificate" doctypes:tamilnadu` | **no results** |
| IK `"recognition number" "transfer certificate" doctypes:tamilnadu` | 5 results — **all from OTHER states** (AP ×2, Gujarat, Uttarakhand, Jharkhand) |
| Matriculation Code, read in full | **no recognition number, no school number, no DGE identifier** |
| Search engines | **all blocked**: DDG html+lite (CAPTCHA), Google (block page), Bing (ignored the quoted phrase), Brave (429+CAPTCHA), Yandex (SmartCaptcha), Startpage (Anubis), Ecosia (403), Mojeek (JS challenge), 3× SearXNG (JS challenge/429) |
| Wayback CDX on `tn.gov.in`, `tn.nic.in`, `tnschools.gov.in` for `educationalrules` | **nothing** |
| An apparent second copy in this project's fetch cache | **byte-identical** to the johnsonasir file (both MD5 `596e66a782f1eae1d98e37b75fdc9d14`) — **one document fetched twice, not two sources** |

**Provenance of the single source:** metadata reads `Title: educationalrules.PDF`, `Author:
Thamizhagam`, `Producer: Acrobat PDFWriter 3.02 for Windows NT`, `CreationDate: 2003-05-05`, 123 pp.
"Thamizhagam" *suggests* a TN government origin but is **not proof**, and the host is a private
service-provider site. **Evidence level C.** It is a ~2003 compilation whose current force is
unverified — TNER has been amended since, and the **TN Private Schools (Regulation) Rules 2023** now
occupy adjacent ground.

#### (4) Is "School No." the same as "TMR Code No." / "Hr.Secy. Code No."? **NO — separate fields. [C]**

Appendix-5's masthead lists them as **distinct boxes**:

```
Hr.Secy.TMR Code No.
Hr.Secy. Certificate Sl.No.
Hr.Secy.Reg.No.
School No :                        SSLC Marksheet SL.No.
                                   TMR Code No.
                                   SSLC Reg. No.
                                   Admission No.
```

**TNER nowhere defines "TMR Code No."** — no expansion, no cross-reference. The plausible reading is
that "TMR" pairs with the Matriculation regime and "Hr.Secy." with Higher Secondary (stream-specific
exam codes), while "School No." is the DGE-assigned school number of rule 34. **That is inference,
not established. Do not encode "School No. == TMR Code" or the reverse.**

#### (5) 🔧 What the product must actually do

**Implementing "print recognition number + DGE school number on private-school TCs" as a hard,
asserted legal requirement is NOT supported at our evidence standard.** It rests on one
uncorroborated 2003 secondary compilation whose own two prescribed forms contradict each other on
the recognition number, and whose cited primary source demonstrably lacks the text.

**Ship it as a configurable, OFF-BY-DEFAULT field if at all — never as an assertion to the school
that the law requires it.**

### 6.9 Tamil Nadu conflicts register

**C1 — TNER rule 34 exists in two substantively different recensions. UNRESOLVED.**
Indian Kanoon: *"recognised **secondary** school"*, Appendix 5 only, rule-92 case-sheet, **DEO**, and
**no** school number / recognition wording / Office Copy rule / seal spec.
johnsonasir: *"recognized **high and higher secondary** school"*, *"appendix-5 & 5A"*, **Inspecting
officers**, **plus** the school-number sentence, the Office Copy rule, the designation-seal spec, the
all-columns rule and the pupil/parent signature requirement. The second is plainly a later
consolidation, but it **cannot be dated or authenticated**. *Not resolved by preference.*

**C2 — TNER rule 40 circulates in two OPPOSITE texts. CONFIRMED (this re-verifies `south-india.md` §3.3).**
Indian Kanoon: *"…a transfer certificate **shall not be refused on the plea that such arrears
exist**."*
johnsonasir: *"…a transfer certificate **shall be issued after payment of all arrears** by the pupil
including the previous terms."*
Same rule number, same opening clause, **inverted outcome**. **Never cite TNER r.40 as authority in
either direction, and keep the no-dues TC gate defaulted OFF.**

**C3 — TNER duplicate-TC rule 44 differs between copies.**
Indian Kanoon: *"**A fee of fifty paise** may be levied … which should be **clearly marked
'duplicate'**."* — no red ink, no once-only limit.
johnsonasir: tiered fee (nil / ₹10 / ₹50), *"clearly bear the mark 'duplicate' **in red ink**"*,
*"**It shall be issued only once.**"*

**C4 — Appendix-5 vs Appendix-5-A disagree within the same document** on whether the Recognition
Number must be printed, and on whether the invalidity sanction reaches all private schools or only
private *Higher Secondary* schools (§6.8(2)).

**C5 — The printed header wording and the note wording are different strings.** The form bodies print
*"Recognized by the Department of **School** Education"* / *"…of Tamil Nadu"*, while the notes make
the words *"Recognized by the Department of Education **Chennai**"* the validity test. **A template
generator must use the NOTE's wording, not the header's.**

**C6 — Cross-state conflation risk, and a likely origin for the error.** Andhra Pradesh rules
explicitly require the recognition number on a TC (*"shall invariably contain the recognition…number
given"*, surfaced via Indian Kanoon). **Tamil Nadu has no comparable judicially-visible text.** A
rule imported from AP into a TN template would look plausible and be wrong. *(This is very likely how
the TN premise entered the corpus in the first place.)*

---

## 7 · ANDHRA PRADESH — BSEAP and BIEAP

> **The AP/Telangana headline, stated once for §7 and §8: none of the four boards was shown to
> prescribe a TC format, and for the two SSC boards that negative is *evidenced*, not merely
> unsearched.** In both states the school TC sits under the **A.P. Education Act 1982 and the A.P.
> Education Code**, administered by the **School Education Department** — not by the examination
> board. **Do not let the module assert a board-mandated TC format, board-required printed
> particulars, or a board-required countersignature for AP or Telangana.**

### 7.0 🟢 THE ONE PRINTED-IDENTIFIER RULE IN THIS REGION THAT IS FULLY EVIDENCED — and it is a STATE rule, not a board rule

**This was found by following up a cross-reference the Tamil Nadu cluster surfaced incidentally, and
it materially corrects the "NOT FOUND" that the Andhra cluster returned for Q2.** It was retrieved
and read first-hand this session. **[A]**

**Instrument.** *Andhra Pradesh Educational Institutions (Establishment, Recognition, Administration
and Control of Schools Under Private Managements) Rules, **1993***, published vide **G.O.Ms.No.1,
Education (P.S.2), dated 1-1-1994**, in the **Andhra Pradesh Gazette R.S. to Part 1, Extraordinary,
dated 3-1-1994**. Made *"In exercise of the powers conferred by **Section 99 read with Sections 20,
21, 79, 80 and 83 of the Andhra Pradesh Education Act, 1982 (Act 1 of 1982)**"*. ·
https://indiankanoon.org/doc/90101170/ · verified 2026-09-12.

> ⚠️ **Correction to the brief's premise.** The instrument is the **1993** Rules, not the 1988 Rules.
> The preamble expressly supersedes *"Andhra Pradesh Educational Institutions (Establishment
> Recognition, Administration and Control) Rules, **1988** issued in G.O.Ms.No. 524, Education
> Department, dated the 20th December, 1988"* (and the 1983 Disciplinary Control Rules, and the 1988
> Minority Educational Institutions Rules "in so far as schools are concerned"). **Citing the 1988
> Rules would be citing a superseded instrument.**

**Rule 10 — "Conditions governing Permission/Recognition" — sub-rule (8), verbatim:**

> *"that the **Name Board of the school, the Transfer Certificate issued by the School**, the
> applications prescribed for admission of students and the advertisements calling for the
> applications **shall invariably contain the recognition number given.** Vide [Rule 8 and 9] above"*
>
> *[the words "Rule 8 and 9" were **substituted for "Rule 9(4)" by G.O. Ms. No. 74, Education (SE
> [PS-1]), dated 11-9-2006**]*

**Four things make this the strongest printed-identifier finding in the file:**

1. **It is primary rule text**, read in a gazette-cited compilation — not a portal screen, not a
   secondary compilation.
2. **It is a CONDITION OF RECOGNITION.** Rule 10's chapeau: *"every permission/recognition granted to
   the schools under these rules shall be subject to the following conditions"*, and rule 11 makes
   permission/recognition *"liable for withdrawal by the competent authority for violation of these
   rules."* **Omitting the recognition number from a TC is therefore a recognition-threatening
   default, not a formatting lapse.**
3. **Its scope is four surfaces, not one** — name board, TC, admission application form, and
   recruitment/admission advertisements. A compliance feature that covers only the TC covers a
   quarter of the rule.
4. **It applies to schools under PRIVATE managements** (r.1(2): all categories of private-management
   schools including minority institutions, pre-primary through Class X and the oriental/special
   school categories). **It is not evidenced for government or local-body schools.**

**⚠️ Does it apply in Telangana? NOT VERIFIED — flagged, not asserted.** The 1993 Rules are made
under the **A.P. Education Act 1982**, and DGE Telangana's own RTI manual states that Act was
*"adopted by Government of Telangana"* (§8.0). Rules made under an adopted Act would ordinarily
continue by operation of the AP Reorganisation Act 2014, **but no Telangana adaptation notification
for these specific Rules was located.**

*Partial corroboration, level **C**:* an Indian Kanoon search for `"Private Managements) Rules, 1993"
restricted to `doctypes:telangana` returns **24 documents** (verified 2026-09-12) — i.e. the 1993
Rules are **actively cited in Telangana-classified case law**, which is strong practical evidence
that they continue to apply there. **The result titles were not read** (the results list did not
render to text), so this raises confidence without establishing the adaptation instrument.
**Do not switch this requirement on for a Telangana tenant without closing that gap.**

**⚠️ And note the connection to Tamil Nadu.** This AP rule is almost certainly the origin of the
cross-state conflation flagged at §6.9 C6: **AP demonstrably requires a recognition number on the
TC; Tamil Nadu's equivalent requirement rests on a single uncorroborated 2003 compilation.** The two
must not be treated as equally established.

**Also in rule 10, and relevant to Q4:** *"that the educational agency shall **maintain all the
records and registers indicated as prescribed by the competent authorities** and they shall be made
available to the concerned inspecting officers for inspection/surprise checks."* This is a generic
records duty — **it prescribes no TC register and no counterfoil.** A separate condition requires
*"the records/accounts shall be furnished to the D.E.O. every year"*, which is an accounts return,
not a certificate return. **[A]**

**No duplicate-certificate rule and no migration-certificate rule exist anywhere in the 1993 Rules**
— greps for `duplicate` and `migration` across the full text return **zero hits**. **[A, negative]**

### 7.1 BSEAP — Board of Secondary Education / Directorate of Government Examinations, AP

#### Q1 — Prescribes a TC format? **NO — and this is a positive negative, not an absence of searching.**

The Board's own **RTI s.4(1)(b) departmental manual** enumerates its *complete* service catalogue.
**TC is absent from it:**

> *"(1) Issue of Duplicate Pass Certificates. (2) Issue of Duplicate Marks Memos, (3) Age
> Certificates (4) Migration Certificates (5) Recounting of Marks… (6) Corrections in Certificates.
> (7) Verification of genuineness of Certificates. (8) Finalization and Disposal of Malpractice
> Cases. (9) Issue of Age Condonation orders. (10) Permission to outside state / country candidates
> to appear for SSC Exams. (11) Concessions to Physically Handicapped Candidates"*

**[A]** · https://bse.ap.gov.in/Downloads/RTI_Act_DepartmentalManual_2026.pdf · 2026-09-12.
Independently corroborated by the public services page, which likewise lists only duplicate SSC,
duplicate memo/DOB/migration and re-verification — **no TC**. **[B]** ·
https://bse.ap.gov.in/services_offered.aspx · 2026-09-12.

**⚠️ A named instrument in the brief does not appear to exist.** The same manual lists the Board's
governing instruments: *A.P. Education Act 1982; A.P. Public Examinations Act 1997; Tabulation
Registers; Fundamental Rules; A.P. State and Subordinate Service Rules 1996; District Office
Manual; A.P.C.S. (CCA) Rules 1991; A.P.C.S. (Conduct) Rules 1964; A.P. Leave Rules; A.P. Revised
Pension Rules; **A.P. Education Code**; A.P. Government Examination Service Rules 2001.* **There is
no "A.P. Board of Secondary Education Rules" in the Board's own list of what governs it.** **[A]**

#### Q2 — Printed particulars? **NOT FOUND at BOARD level — but see §7.0, the requirement exists at STATE level**

No **BSEAP** requirement was found for anything printed on a school-issued TC. Searched: the RTI
manual full text, `services_offered.aspx`, and the Board's G.O. portal.

**⚠️ This negative must not be reported as "AP requires nothing on a TC".** The
**recognition number IS mandatory** on a private-management school's TC in Andhra Pradesh — under
**rule 10(8) of the AP Private Managements Rules 1993 (§7.0)**, a **State** rule under the AP
Education Act 1982. **Right requirement, wrong layer** — exactly the same layering as Karnataka's
DISE-code mandate (§4.2), which is departmental rather than KSEAB's.

#### Q3 — Countersignature of a school TC? **NOT FOUND**
BSEAP offers **no** TC countersignature service — a notable contrast with TSBIE (§8.2), which does.
The manual's only "countersign" duty is financial: *"To scrutinise and countersign all the bills
including T.A. bills…"*. **[A]**

#### Q4 — Post-issuance duty / is online TC mandatory? **NOT ESTABLISHED — neither way.**
Attempted: `childinfo.telangana.gov.in` (**NXDOMAIN**), `cse.ap.gov.in` (navigation enumerated —
RTE, PSIS, APMS, teacher transfers; **no TC module surfaced**), `schooledu.telangana.gov.in/ISMS/`
(**404**). **Neither "online TC is mandatory in AP" nor "online TC is optional in AP" may be
shipped.**

#### Q5 — Admission from another board/state **[A, partial]**
The Board's function list includes **"Permission to outside state / country candidates to appear for
SSC Exams"** — a **Board permission to sit the exam**, not a school-level eligibility certificate and
not a migration-certificate demand on inbound students. No inbound migration-certificate requirement
was found.

#### Q6 — Migration Certificate — **YES, BSEAP issues it. [A + B, two independent sources]**
Service (4) in the RTI manual, with a **4-working-day** disposal standard; and live as a portal
service:
- Eligible: SSC Public Examinations **2004 onwards** (Regular, Private, OSSC, ASE)
- Fee **₹80**, paid online; *"Application fee once paid will not be refunded/returned under any
  circumstances."*
- *"The Migration Certificate will be available for downloading for '30' Days only, from the date of
  payment of the Fee."*
- **Digitally signed; no school or district-office visit needed.**

**⚠️ No regulation or rule number is cited anywhere for this power.** **[B]** ·
https://bse.ap.gov.in/migration/frmhome.aspx · 2026-09-12.

*(The 30-day download window is a real product constraint: a Migration Certificate captured in our
document vault must be stored at issue, because the board's own copy expires.)*

#### Q7 — Duplicates **[B]**
*"The application for issuance of Duplicate Certificate should be made in the prescribed format"* —
requires a police/tahsildar certificate, a notarised affidavit, a school declaration and a photo
extract, with a **₹250** treasury challan. Duplicate memo / DOB / migration: **₹80**.
**No rule found on how a duplicate must be MARKED** — no "DUPLICATE" legend requirement located.

### 7.2 BIEAP — Board of Intermediate Education, Andhra Pradesh

**The regulation layer is established; the regulations themselves are not.** **[A]** — *Intermediate
Education Act, 1971*, full text · https://indiankanoon.org/doc/70034660/ · 2026-09-12:

- **s.9 (Powers of the Board)** — conduct examinations and *"grant certificates to the candidates who
  have passed"*; affiliation/recognition; and **s.9(2)(b)** recognising *"diploma or certificate
  granted by any other Board"* as equivalent.
- **s.12 (regulation-making power)** — covers affiliation/recognition conditions, courses of study and
  **admission eligibility**, conduct of the Intermediate examination, examination fees and conditions
  of admission, and *"Standards of proficiency required for the grant of certificates"*.

**⚠️ The enabling power exists, but the REGULATIONS made under s.12 were never obtained.** BIEAP's
site is JS-rendered and its regulation repository was not reachable. **This is the single biggest
hole in the AP/Telangana record** — it is where an Intermediate eligibility-certificate rule would
live for both BIEAP and TSBIE.

| Q | Finding |
|---|---|
| **Q1** TC format | **NOT FOUND** |
| **Q2** Printed particulars | **NOT FOUND** |
| **Q3** Countersignature | **NOT FOUND for BIEAP** — two independent renderings of the service list name only migration, equivalency, eligibility and application-status tracking. ⚠️ **This is WEAK negative evidence** (the JS menu could not be enumerated directly) — **do not assert it as a settled negative.** **[B/D]** https://bie.ap.gov.in/ 2026-09-12 |
| **Q4** Post-issuance | **NOT ESTABLISHED** |
| **Q5** Other board/state | **Eligibility Certificate and Equivalency Certificate both exist as BIEAP services [B]**, consistent with s.9(2)(b). **The governing regulation number, the trigger conditions and the document list were NOT FOUND** |
| **Q6** Migration | **Issued by BIEAP [B]**; no regulation number found |
| **Q7** Duplicates | **NOT FOUND** |

---

## 8 · TELANGANA — BSE Telangana and TSBIE

### 8.0 Bifurcation — established verbatim, and it explains everything below

The DGE Telangana RTI manual reproduces the AP manual and **states the adoption item by item**:

> *"i) **A.P. Education Act, 1982 adopted by Government of Telangana**"*
> *"ii) A.P. Public Examinations, Act 1997 (prevention of Malpractices and unfair means) **adapted by
> Government of Telangana**"*
> *"x) **A.P. Education Code adapted by Government of Telangana**"*
> *"xi) A.P. Government Examination Service Rules, 2001 **adapted by Government of Telangana**"*

**[A]** · https://bse.telangana.gov.in/PDF/RTI_Act_DGE_TS_Hyderabad.pdf · 2026-09-12.

**This is why every Telangana rule reads like an AP rule — it *is* the AP text, adopted under the
AP Reorganisation Act 2014.** For the module this means AP and Telangana share a document-law
baseline and **diverge only where a Telangana G.O. has since amended it**.

### 8.1 BSE Telangana — Board of Secondary Education / DGE

#### Q1 — Prescribes a TC format? **NO — and doubly evidenced.**

Identical service catalogue to AP, **TC absent**: *"(1) Issue of Duplicate Pass Certificates.
(2) Issue of Duplicate Marks Memos. (3) Age Certificates (4) Migration Certificates (5) Recounting…
(6) Corrections in Certifications. (7) Verification of genuineness of Certificates. (8) Finalization
and Disposal of Malpractice Cases. (9) Issue of Age Condonation orders. (10) Concession to
Physically Handicapped Candidates"*. **[A]**

**Second, independent corroboration:** the Board's **complete published G.O. list — all 12 G.O.s and
Memos — was enumerated, and none concerns the TC, its format, countersignature, or migration.**
(G.O.Ms.No.02 School Education; 06 Composite Course; 10 2nd-language pass marks; 12 PH amendment;
15 Compulsory Telugu; 17 SSC/9th reforms; 23 SSC 7 Papers; 27 CwSN exemptions; 33 SSC 6 papers;
Memo 10047/2023; Memo 14572/2024 Telugu extension; Memo 17120/2006 age exemption.) **[B]** ·
https://bse.telangana.gov.in/ssc_gos.htm · 2026-09-12.

*(This is the strongest board-level negative in the whole file: both the service catalogue and the
full G.O. list were enumerated, and neither contains a TC rule.)*

#### Q2 — Printed particulars? **NOT FOUND for school TCs.** One *board-certificate* rule located:

> *"Mother's name along with Father's name is being printed in the Pass Certificates / Memorandum of
> Marks from SSC Public Examination, **March 2011 onwards**."*

This governs the **board's own** pass certificate and marks memo, **not** a school TC. **[A]**

Also relevant to any verification design we build:
> *"Data of the candidates appeared SSC Public Examinations from the year **2004 onwards** is hosted
> in the office website to facilitate the Recruiting Authorities to **check the genuinity of the
> Certificate**."* **[A]**

*(Same pattern as Karnataka's DISE substitution and Tamil Nadu's printed identifiers: the direction
of travel on authenticity is **machine-verifiable lookup**, not a wet countersignature.)*

#### Q3 — Countersignature of a school TC? **NOT FOUND.** No such service; only the financial
countersign duty. **[A]**

#### Q4 — Online TC mandatory? **NOT ESTABLISHED.** `childinfo.telangana.gov.in` **does not resolve**
(2026-09-12); `schooledu.telangana.gov.in/ISMS/` returns **404**; the department root is JS-gated.
**No finding either way.**

#### Q5 — Admission from another state **[A]** — and it is **not** a certificate rule

The Board's only published rule for inbound out-of-state students concerns the **second language**:

> *"Opting English as Second Language for the students admitting in our State from other States: If
> the students of other State and take admission in VI Standard in any … schools of Telangana, it
> should be made mandatory that they have to study 3 Languages opting 09T (Telugu) as 2nd Language.
> Further, in case of students of other states take admission in the state whose 2nd Language is
> Hindi in the previous schools they may be continued with learning Hindi as 2nd Language… Those
> students who join in higher classes from VII onwards coming from other states with other than
> Telugu Medium study may be exempted from studying 2nd Language."*

*(Product note: this is a **curriculum** consequence of an inter-state transfer, not a document
consequence. If the module ever models "student arriving from another state", Telangana attaches a
second-language determination to that event — worth surfacing, but it is not a certificate rule.)*

#### Q6 — Migration Certificate — **YES**, service (4), **2-day** disposal standard. **No regulation
number cited.** **[A]**

#### Q7 — Duplicates — **the Board's own prescribed proforma was read verbatim. [A]**

`Duplicate_SSC_latestProforma.pdf` · https://bse.telangana.gov.in/images/Duplicate_SSC_latestProforma.pdf
· 2026-09-12. Load-bearing mechanics:

- **Routing through the school is mandatory:** *"The application along with the required document
  should be sent to the Addl. Joint Secretary to the Director of Government Examinations, Chapel
  Road, Telangana, Hyderabad-500001 **through the Headmaster where the candidate last studied**."*
- **15 numbered fields**, including the Sl. No. of the original SSC, every appearance with month/year
  and Register No., and **marks of identification**.
- **Gazette publication of the loss:** *"…since the particulars of loss are to be furnished will be
  published in the Gazette"*.
- **HM Identification Certificate** — a separate signed block under school stamp: *"…is the same
  identical person to whom SSC was originally issued and that the issue of duplicate SSC to the
  candidate is recommended."*
- **Six mandatory enclosures:** ₹250 treasury challan (SBI; head of account 0202-01-102-006-800, DDO
  25000303001); **affidavit on ₹50 stamp paper attested by a Junior Civil Judge or Notary**; a
  declaration re suspension/cancellation; **"True extract of the original Secondary School
  Certificate (it will be issued by the Headmaster)"**; an HM covering letter; and a certificate from
  a **Sub-Inspector of Police or Deputy Tahsildar**.
- Affidavit undertaking: *"In case, if it is traced in future I shall submit it to the Board of
  Secondary Education for cancellation."*

**How the issued duplicate must be MARKED: NOT FOUND.** The proforma prescribes the *application*,
not any endorsement or legend on the certificate that comes back.

**Two things here are school-side obligations our module could legitimately support:** the
**HM Identification Certificate** and the **"True extract of the original SSC issued by the
Headmaster"**. Both are school-issued documents in a board process — the same conduit pattern as
MSBSHSE regs 61/108 (§1.7).

### 8.2 TSBIE / TGBIE — Telangana State Board of Intermediate Education

**Host note:** `tsbie.cgg.gov.in` **no longer resolves**; the live portal is
**`tgbienew.cgg.gov.in`** (2026-09-12).

| Q | Finding |
|---|---|
| **Q1** TC format | **NOT FOUND** — no field list or proforma located |
| **Q2** Printed particulars | **NOT FOUND** |
| **Q4** Post-issuance | **Partially established, legal status NOT ESTABLISHED** — see below |
| **Q5** Other board/state | **Eligibility Certificate (id 7) and Equivalency Certificate (id 6) are live TSBIE services [B].** Published applicant conditions: *"All applicants requested to upload original colour scanned documents and enter correct details without spelling mistakes"*; *"If rejected twice, candidates must pay fee to reapply"*; *"After verification, if certificates found fake/fabricated, criminal action initiated against candidates."* **Regulation number, precise trigger and document checklist NOT FOUND** |
| **Q6** Migration | **YES**, service id 1 **[B]**. No regulation number; enabling power is Intermediate Education Act 1971 ss.9/12 **[A]** |
| **Q7** Duplicates | *"Duplicate/Triplicate Pass Certificate"* and *"Duplicate Memorandum of Marks"* (id 2) are live **[B]**. **The Board recognises a TRIPLICATE tier** — worth noting, as no other board in this corpus does. **No marking rule found** |

#### Q3 — ⚠️ TSBIE runs a **"Counter signature on Transfer Certificate"** service — **but treat it as unverified**

From the Board's Student Online Services menu, **service id 15, "Counter signature on Transfer
Certificate"**. The form requires the **TC scanned front and back** (PDF, <100 KB), selection of the
**state in which admission is sought**, a mobile number, and a fee. The surrounding service set also
includes **"Issue TC" (id 32)** and **"TC/RE Admission Request" (id 19)**, the latter gated on the
institution: *"Candidate Please Approach to Your College Principal for Applying to this service."*

**[B, portal UI — NOT established as a prescribed legal format and NOT established as a legal
mandate.]** · https://tgbienew.cgg.gov.in/studentServices.do · 2026-09-12.

**Why this is being flagged rather than shipped.** The *"state in which admission is sought"* field
shows this is countersignature for **OUTBOUND inter-state** migration — i.e. the Telangana mirror of
the inbound rules in Maharashtra (SS Code r.22.1, MSBSHSE reg 79(7)), Gujarat (reg 12(9)(ક)) and
Kerala (KER Ch.VI r.10). **But whether it is legally compulsory or merely offered could not be
established, and a second independent source could not be found.** Under the one-source rule this
stays unverified. **Do not build a mandatory TSBIE countersignature step.**

#### Q4 detail — online TC
TSBIE demonstrably **provides** online TC issuance ("Issue TC") and an online TC/RE admission request
routed through the college principal. **Whether either is legally mandatory is NOT ESTABLISHED** — no
G.O., regulation or circular mandating online-only TC was located. The Board's own GO/Acts page
(`gosandActs.do`) returned only a footer on direct fetch and **403 via reader proxy**, so **TSBIE's
regulation repository was never read.**

---

## 9 · WHAT THIS CHANGES FOR THE MODULE

### 9.1 Corrections to the existing corpus

| Where | What must change |
|---|---|
| **`south-india.md` §3.3a, §3.4** | The TN *"school number assigned by the Director of Government Examinations"* claim cites https://indiankanoon.org/doc/24617366/. **That page does not contain the sentence** (§6.8). Re-cite to the johnsonasir compilation and **downgrade the claim from A to C, DISPUTED.** |
| **`CONFLICTS.md` C-17** | Lists *"Tamil Nadu's recognition + DGE numbers"* alongside genuinely-evidenced mandates (CGBSE cl.16(4), HPBOSE, Karnataka DISE). **TN does not belong in that company at the same confidence.** Karnataka's and CGBSE's are evidenced; TN's is one uncorroborated 2003 compilation that contradicts itself. |
| **`CONFLICTS.md` C-16 / C-16a** | **Strengthened.** Eleven more board entities checked here — MSBSHSE, GSEB/GSHSEB, GBSHSE, KSEAB, Kerala Pareeksha Bhavan, Kerala DHSE, TN DGE, BSEAP, BIEAP, BSE Telangana, TSBIE — and **none prescribes a TC format.** CBSE's Annexure-I still stands alone. |
| **Layer attribution generally** | Karnataka's DISE mandate and AP's recognition-number mandate were both being carried as board-layer facts. **Both are STATE/departmental rules** (§4.2, §7.0). The board did not impose either. |

### 9.2 The rules that are safe to enforce from this file

**Enforce (evidence A, primary text read):**
- **A school must never issue a Migration Certificate.** Now evidenced for MSBSHSE (regs 60, 107),
  KSEAB, Pareeksha Bhavan, BSEAP, BSE Telangana, BIEAP, TSBIE and TN DGE — every board in scope.
- **Maharashtra:** the LC needs **two signatures** (Class Master + Head) and is invalid otherwise
  (SS Code r.32.1); duplicates marked **"Duplicate" in red ink at the top** (r.30).
- **Kerala:** Form 5's **Government/Aided/Recognised status line** and the **school seal**; the
  **Form 4 admission-register entry** (No. and date of TC granted on leaving); duplicates **"clearly
  marked 'Duplicate'"** (r.22); and **never offer a school-to-school TC request** — KER r.14(2)
  forbids it, the parent must apply.
- **Andhra Pradesh, private-management schools:** the **recognition number** on the TC, the name
  board, the admission application form and advertisements — **AP Rules 1993 r.10(8)**, a condition
  of recognition.

**Do NOT enforce:**
- Any **board-prescribed TC format** in any of these states.
- **TN's recognition-number / DGE-school-number printing** as a legal requirement — **configurable,
  off by default** (§6.8(5)).
- **TNER r.40** in either direction — it exists in two opposite texts (§6.9 C2).
- A **TSBIE countersignature** step — portal UI only, legal compulsion not established (§8.2).
- A **counterfoil duty under TNER** — that duty is **Matriculation-Code-only** (§6.4).
- The AP recognition-number rule for a **Telangana** tenant until the adaptation gap is closed
  (§7.0).

### 9.3 Three cross-cutting design consequences

1. **Duplicate marking is not one rule.** Maharashtra: *"Duplicate" in red ink at the top*. Kerala:
   *clearly marked "Duplicate"*, no colour or position. Tamil Nadu (one recension): *red ink,
   **issued only once***. AP/Telangana/KSEAB: **no marking rule found at all**. **A single hard-coded
   watermark will be wrong in at least one state.**
2. **The school is a CONDUIT for board documents, not an issuer.** MSBSHSE regs 61/108 (duplicate
   SSC/HSC only through the head of the school) and BSE Telangana's duplicate proforma (routed
   *"through the Headmaster"*, plus an **HM Identification Certificate** and a **"True extract of the
   original SSC issued by the Headmaster"**) both put the school in the middle of a board process.
   **And the conduit breaks on de-recognition** — MSBSHSE reg 61 then sends the certificate direct to
   the candidate.
3. **Board-issued artefacts expire from the board's side.** BSEAP's Migration Certificate is
   downloadable **for 30 days only** from payment. Anything the module ingests from a board portal
   must be **stored at capture**, not re-fetched on demand.

---

## NOT FOUND — consolidated, with the searches actually attempted

**Read this as a work list, not as an apology.** Several entries below are *evidenced* negatives
(the catalogue was enumerated and the thing is not in it); those are findings. The genuinely open
items are marked **OPEN**.

### Maharashtra / MSBSHSE
1. **Any post-1990 MSBSHSE amendment.** The Board publishes the 1977 Regulations *"as amended up to
   31st October 1990"*. **OPEN** — an amendment after that date would not have been seen.
   *Retrieve:* mahahsscboard.in circulars, and the Divisional Boards' own notifications.
2. **The prescribed FORM for an Eligibility Certificate application** (reg 80(2) says *"in a
   prescribed form"* and the Regulations do not print it). **OPEN.**
3. **Current fees.** Regs 60/61/107/108 quote ₹10; certainly stale in amount. The *structure* is
   citable, the numbers are not.

### Kerala
4. **Whether Sampoorna-generated TCs are MANDATORY.** *Searched:* the General Education Department
   site search for "transfer certificate" / "Sampoorna TC" / "migration certificate" (only the two
   Sampoorna pages and the Citizens' Charter return); the Sampoorna page itself (cites no G.O.); the
   **DGE Citizens' Charter** page (renders as navigation only — the service table was not
   retrievable); the **Kerala Right to Service notification for the department**
   (https://kite.kerala.gov.in/KITE/SEVANAAVAKASHANIYAMAM-DGE.pdf — **8 MB scanned image, no text
   layer**; would need OCR). **OPEN, and commercially the most important Kerala gap** — if Sampoorna
   is the system of record, our module is a parallel writer.
5. **The regulation or G.O. behind Kerala's board Migration Certificate.** The Pareeksha Bhavan
   portal lists the service but cites no rule, fee or eligibility condition. **OPEN.**
6. **HSCAP / Ekajalakam requirements for candidates from other boards.** The 2026-27 circulars were
   downloaded (21 PDFs from `control.hscap.kerala.gov.in`) but are published in a **legacy
   non-Unicode Malayalam font**; only letterheads, file numbers and G.O. references were legible.
   Nothing from their bodies is asserted. **OPEN** — needs a Malayalam reader or the English
   prospectus.
7. **Whether KER applies to Higher Secondary schools.** Not established either way this session.
   **OPEN.**
8. **"Kerala Board of Public Examinations" as a legal entity** — not confirmed on any official page;
   the operating authority everywhere is the **Commissioner for Government Examinations**.
9. **A DHSE rule inside the archived date-coded circular PDFs** — ~hundreds of files whose names
   encode a date, not a subject, were not opened individually.

### Karnataka / KSEAB
10. **KSEAB by-laws — they appear not to be published at all.** *Evidenced:* the by-laws and
    amendments pages render the site template with **zero content items**, proved against a
    populated control page. ~160 circulars over four years enumerated; none touches TCs.
11. **The regulation behind KSEAB's migration certificate.** The service page cites none;
    `Migration Letter_2.pdf` is a scanned image with no text layer; `/LetterToReceivingAuthority`
    returns 404.

### Tamil Nadu
12. **A second independent copy of TNER rule 34.** *Exhaustively attempted* — see the table at
    §6.8(3). Indian Kanoon phrase search returns nothing (control query confirms the search works);
    all eleven search engines tried were CAPTCHA-walled or blocked; Wayback CDX on `tn.gov.in`,
    `tn.nic.in`, `tnschools.gov.in` returned nothing; the one apparent second copy was
    **byte-identical** (same MD5). **OPEN and load-bearing.**
13. **TN Private Schools (Regulation) Rules 2023, rule 18 — and what *"the instructions issued in
    this regard"* refers to.** The Rules exist and are judicially cited (rr. 8, 8-A, 24(6), 27,
    28(2), 32 appear in Madras HC judgments 2024–2026), **but Indian Kanoon hosts only the judgments
    citing them, not the Rules.** The referent of that phrase **remains unresolved** — and it is the
    operative TC instruction for TN private schools. *Retrieve:* the TN Gazette or the G.O. PDF.
    **OPEN.**
14. **Which TNER recension is currently in force** (§6.9 C1) — the amending G.O. was not found.
15. **What "TMR Code No." stands for** — TNER never defines it.

### Andhra Pradesh / Telangana
16. **The Intermediate Education Regulations made under s.12 of the 1971 Act** — i.e. the actual
    regulation governing the BIEAP/TSBIE Eligibility Certificate. *Searched:* Indian Kanoon
    `"Intermediate Education Act, 1971" "eligibility certificate"` (**0 results**);
    `"Intermediate Education Act, 1971"` (126 sections indexed, none on admission/eligibility/
    migration); ss.9 and 12 read via /doc/70034660/; TGBIE `gosandActs.do` (**403 / JS-gated**).
    **OPEN — this is the biggest hole in the AP/TG record.**
17. **Whether the AP Private Managements Rules 1993 were adapted for Telangana.** 24
    Telangana-classified documents cite them (level C), but the adaptation notification was not
    found. **OPEN, and it gates §7.0 for Telangana tenants.**
18. **Whether online TC is legally mandatory** in either state. `childinfo.telangana.gov.in`
    (**NXDOMAIN**), `schooledu.telangana.gov.in/ISMS/` (**404**), department root JS-gated,
    `cse.ap.gov.in` navigation enumerated with no TC module. **Neither claim may be shipped.**
19. **Any rule on how a duplicate must be MARKED** — for any of the four AP/TG boards.
20. **BIEAP's service menu, enumerated directly** — only reader-proxy renderings were obtained, so
    the "BIEAP has no TC countersignature" negative is **weak**.
21. **The AP board's G.O. list** — `portal.bseap.org/BSEAPGO/Default.aspx` exposes only JS buttons
    and `SSCGO.aspx` returns **HTTP 500**. (Telangana's equivalent *was* enumerated in full.)
22. **`goir.ap.gov.in`** — not reached.
23. **"A.P. Board of Secondary Education Rules"** — **this instrument was not found to exist.** It is
    absent from the Board's own RTI list of governing instruments, which names the **A.P. Education
    Code** instead. *Recorded as a correction to the brief's premise, not as a gap.*

### Method-level
24. **The session's WebSearch budget was exhausted (200/200) before this file's research began.**
    Everything here was obtained by direct retrieval, Indian Kanoon's search endpoint, archive.org
    CDX, and reader proxies. **Every general-purpose search engine tried was CAPTCHA-walled or
    blocked** (DuckDuckGo html + lite, Google, Bing, Brave, Yandex, Startpage, Ecosia, Mojeek, three
    SearXNG instances). A re-run with search budget would most likely close items 12, 13, 16 and 17.
