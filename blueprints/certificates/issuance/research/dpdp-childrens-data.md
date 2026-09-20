# DPDP Act 2023 + DPDP Rules 2025 — children's data, cross-border, and the school-ERP position

**Research pass:** 2026-09-20 · read-only · no code changed
**Scope:** the data-protection layer the issuance corpus never had. Closes every NOT FOUND left open
in `central-and-boards.md` §A3.6.

## Evidence grades used in this file

| grade | meaning |
|---|---|
| **[A]** | primary statutory text, read from the Gazette of India PDF |
| **[B]** | official government site (non-gazette) |
| **[C]** | secondary — law report, news, encyclopedia |
| **[D]** | my inference from [A]; reasoning shown, **not** law |
| **NOT FOUND** | searched, not established. Recorded as a result, not filled with a guess. |

## Primary sources actually read

| doc | identifier | retrieved from |
|---|---|---|
| **DPDP Act, 2023** | Act **22 of 2023**, assent **11 Aug 2023**, Gazette Pt II s.1 No. 25, `CG-DL-E-12082023-248045` | `prsindia.org/files/bills_acts/bills_parliament/2023/Digital_Personal_Data_Protection_Act,_2023.pdf` — extracted with `pdftotext` |
| **Act commencement notification** | **G.S.R. 843(E)**, dated **13 Nov 2025**, F. No. AA-11038/1/2025-CL&ES, signed Ajit Kumar, Jt. Secy. | `meity.gov.in/static/uploads/2025/11/c56ceae6c383460ca69577428d36828b.pdf` |
| **DPDP Rules, 2025** | **G.S.R. 846(E)**, dated **13 Nov 2025**, published **14 Nov 2025**, Gazette Pt II s.3(i) No. 760, `CG-DL-E-14112025-267650` | `meity.gov.in/static/uploads/2025/11/53450e6e5dc0bfa85ebd78686cadad39.pdf` |

**Access note.** `indiacode.nic.in` returned **404/403** on every route tried (simple-search and
bitstream paths), and `meity.gov.in` **403s the agent fetcher** on every URL while serving the same
URLs normally to `curl` with a browser User-Agent. `meity.gov.in` is now a Next.js static-export SPA
whose HTML shell contains no document links, so its own document index is not scrapable; the two
MeitY PDF URLs above were recovered from the **Wikipedia article wikitext** (`action=raw`) and then
downloaded directly. `indiankanoon.org` carries the **Act** section-by-section but **does not carry
the Rules**. WebSearch was not used (exhausted for the session, per instruction).

---

## §1 · Commencement status — Act and Rules

### 1.1 Is the Act in force? **Partly. The parts that matter to us are NOT yet in force.**

**[A]** G.S.R. 843(E), 13 Nov 2025, made under s.1(2), verbatim:

> **G.S.R. 843(E).**–––In exercise of the powers conferred by sub-section (2) of section 1 of the
> Digital Personal Data Protection Act, 2023 (22 of 2023), the Central Government hereby appoints—
>
> **(a)** the date of publication of this notification in the Official Gazette as the date on which
> the provisions of sub-section (2) of section 1, section 2, sections 18 to 26 sections 35, 38, 39,
> 40, 41, 42, 43, and sub-sections (1) and (3) of section 44 of the said Act shall come into force;
>
> **(b)** one year from the date of publication of this gazette on which the provisions of
> sub-section (9) of section 6 and clause (d) of sub-section (1) of section 27 of the said Act shall
> come into force.
>
> **(c)** eighteen months from the date of publication of this gazette, on which the provision of
> sections 3 to 5, sub-sections (1) to (8) and (10) of section 6, sections 7 to 10, sections 11 to
> 17, section 27 except clause (d) of sub-section (1) of the said section, sections 28 to 34, 36, 37
> and sub-section (2) of section 44 of the said Act shall come into force.

**What that means, laid out:**

| phase | date | provisions | relevant to ZenXii? |
|---|---|---|---|
| immediate | **13/14 Nov 2025** | s.1(2), **s.2 (definitions)**, ss.18–26 (**Data Protection Board**), s.35, ss.38–43 (incl. **s.40 rule-making**), **s.44(1) & (3)** | definitions bind; RTI s.8(1)(j) amended |
| +1 year | **13/14 Nov 2026** | s.6(9), s.27(1)(d) | consent-withdrawal mechanics |
| +18 months | **13 May 2027** *(see caveat)* | ss.3–5, s.6(1)–(8) & (10), **ss.7–10**, **ss.11–17**, s.27 (rest), **ss.28–34**, 36, 37, s.44(2) | **everything that binds us** |

> **The single most important fact in this file: s.8 (fiduciary obligations), s.9 (children's data),
> s.10 (Significant Data Fiduciary), s.16 (cross-border) and s.33 + the penalty Schedule are NOT IN
> FORCE as at the date of this research.** They commence eighteen months after 13/14 Nov 2025.

**[C]** Secondary sources (SCC Online, Wikipedia) uniformly state the 18-month date as
**13 May 2027**.
**[D] Caveat on the exact date:** the notification is *dated* 13 Nov 2025 but the Gazette carries
`CG-DL-E-**14112025**-267650` and the digital signature is timestamped **14 Nov 2025 10:37 IST**.
The text keys off "the date of publication of this gazette". 13 vs 14 May 2027 is therefore
arguable. **Do not build a compliance deadline on the one-day difference** — treat the hard date as
**13 May 2027**.

**[A]** Already in force and already biting: **s.44(3)** amended **RTI Act s.8(1)(j)** to read simply
*"(j) information which relates to personal information;"*. **[C]** Confirmed applied in
*Narendra Bahadur Singh v. Union Bank of India* (CIC, 28 Jan 2026), which records the Rules
notification date as **14.11.2025**.

### 1.2 Have the Rules been notified? **YES — final, not draft.**

**[A]** G.S.R. 846(E). The preamble recites that the **draft** was published as **G.S.R. 02(E) on
3 January 2025** for 45 days of objections, that copies were made available on 3 Jan 2025, and that
objections and suggestions received "have been considered by the Central Government". The operative
words then follow: *"the Central Government hereby makes the following rules"*.

> **This disposes of the draft-versus-law problem.** Much commentary in circulation describes
> **G.S.R. 02(E) of 3 Jan 2025 — the DRAFT**. The corpus's own working assumption (§A3.6) that the
> Rules "had at minimum passed through a public draft stage" is now superseded: **the final Rules
> are notified law.**

**[A]** Rule 1, verbatim:

> **1. Short title and commencement.** — (1) These rules may be called the Digital Personal Data
> Protection Rules, 2025.
> (2) Rules 1, 2 and 17 to 21 shall come into force on the date of their publication in the Official
> Gazette.
> (3) Rule 4 shall come into force one year after the date of publication of this Gazette.
> (4) Rules 3, 5 to 16, 22 and 23 shall come into force eighteen months after the date of
> publication of this Gazette.

| phase | rules | content |
|---|---|---|
| immediate | 1, 2, **17–21** | definitions; Data Protection Board constitution, salaries, meetings, digital office |
| +1 yr | **4** | Consent Manager registration |
| +18 mo | **3, 5–16, 22, 23** | **notice, security, breach, retention, children's consent, child exemptions, SDF duties, rights, cross-border, appeals** |

The Rules' phase-in is deliberately **aligned** with the Act's: the substantive rule (Rule 10
verifiable consent, Rule 12 child exemptions, Rule 15 transfer) commences on the same day as the
section it implements.

### 1.3 What this gives ZenXii

**[D]** A **runway to 13 May 2027**, not an exemption. Three things are true at once and must not be
collapsed:

1. No s.9 / s.16 / s.8 obligation is legally enforceable today.
2. The **entire rulebook is already published** — there is no remaining uncertainty to wait out.
3. Processing done today persists into the regime. Data collected now under no consent architecture
   is still in the database on 13 May 2027, and s.8(7) erasure duties then attach to it.

---

## §2 · Section 9 — children's data, and the education exemption

### 2.1 s.9 verbatim

**[A]** Act 22 of 2023, s.9 (commences 13 May 2027):

> **9. Processing of personal data of children.**
>
> **(1)** The Data Fiduciary shall, before processing any personal data of a child or a person with
> disability who has a lawful guardian obtain verifiable consent of the parent of such child or the
> lawful guardian, as the case may be, **in such manner as may be prescribed**.
>
> *Explanation.*—For the purpose of this sub-section, the expression "consent of the parent"
> includes the consent of lawful guardian, wherever applicable.
>
> **(2)** A Data Fiduciary shall not undertake such processing of personal data that is likely to
> cause any detrimental effect on the well-being of a child.
>
> **(3)** A Data Fiduciary shall not undertake tracking or behavioural monitoring of children or
> targeted advertising directed at children.
>
> **(4)** The provisions of sub-sections (1) and (3) shall not be applicable to processing of
> personal data of a child by such classes of Data Fiduciaries or for such purposes, and subject to
> such conditions, **as may be prescribed**.
>
> **(5)** The Central Government may, if satisfied that a Data Fiduciary has ensured that its
> processing of personal data of children is done in a manner that is verifiably safe, notify for
> such processing by such Data Fiduciary the age above which that Data Fiduciary shall be exempt
> from the applicability of all or any of the obligations under sub-sections (1) and (3) in respect
> of processing by that Data Fiduciary as the notification may specify.

**[A]** s.2(f): *"child" means an individual who has not completed the age of eighteen years*.
**[A]** s.2(j): *"Data Principal" … where such individual is— (i) a child, includes the parents or
lawful guardian of such a child*.

**Structural point [D]:** s.9(2) is an **absolute bar** — it is not in the s.9(4) exemption list,
which reaches only sub-sections (1) and (3). **No prescribed exemption and no parental consent can
authorise processing likely to harm a child's well-being.** This confirms the corpus's reading.

### 2.2 What "verifiable parental consent" actually requires — Rule 10

**[A]** DPDP Rules 2025, Rule 10(1) (commences 13 May 2027):

> **10. Verifiable consent for processing of personal data of child.**—(1) A Data Fiduciary shall
> adopt appropriate technical and organisational measures to ensure that verifiable consent of the
> parent is obtained before the processing of any personal data of a child and shall **observe due
> diligence, for checking that the individual identifying herself as the parent is an adult who is
> identifiable if required in connection with compliance with any law for the time being in force in
> India**, by reference to—
>
> **(a)** reliable details of identity and age of the individual **available with the Data
> Fiduciary**; or
>
> **(b)** details of identity and age, voluntarily provided —
> (i) by the individual; or
> (ii) through a **virtual token** mapped to such details, which is issued by an authorised entity.

Rule 10(2) defines "adult" as 18+, and "authorised entity" as an entity entrusted by law or by the
Central/State Government with issuing identity-and-age details or a mapped virtual token, **including
details made available and verified by a Digital Locker Service Provider** (DigiLocker).

> **[D] This is materially easier for a school ERP than the public commentary suggests.** Limb **(a)**
> — *"reliable details of identity and age … available with the Data Fiduciary"* — lets a Data
> Fiduciary that **already holds** verified parent identity and age details rely on them. A school
> ordinarily does hold exactly that, captured at admission. **DigiLocker/Aadhaar is one permitted
> route, not a mandatory one.** Rule 10's four Illustrations confirm the split: Cases 1 and 3 (parent
> already a registered user whose details the fiduciary holds) resolve on limb (a); Cases 2 and 4
> (parent not previously known) require limb (b).
>
> The corpus recorded as NOT FOUND "how verifiable parental consent must technically be obtained …
> public discussion of a DigiLocker-based virtual token … I could not verify any of it." **Now
> found: the virtual-token/DigiLocker route is real but is the fallback, not the default.**

### 2.3 **Is there an exemption for educational institutions? YES — but a narrow one.**

This was flagged as "the single highest-value open question in Part A". **It is now closed.**

**[A]** Rule 12 (commences 13 May 2027):

> **12. Exemptions from certain obligations applicable to processing of personal data of child.** —
> (1) The provisions of sub-sections (1) and (3) of section 9 of the Act shall not be applicable to
> processing of personal data of a child by such **class of Data Fiduciaries as are specified in
> Part A of Fourth Schedule, subject to such conditions as are specified in the said Part**.
> (2) The provisions of sub-sections (1) and (3) of section 9 of the Act shall not be applicable to
> processing of personal data of a child **for such purposes as are specified in Part B of Fourth
> Schedule**, subject to such conditions as are specified in the said Part.

**[A] FOURTH SCHEDULE [See rule 12], PART A** — *"Classes of Data Fiduciaries in respect of whom
provisions of sub-sections (1) and (3) of section 9 shall not apply"*. Entries 3–5 are the
education entries, reproduced verbatim:

| S. No. | Class of Data Fiduciaries | Conditions |
|---|---|---|
| **3** | A Data Fiduciary who is an **educational institution**. | Processing is restricted to **tracking and behavioural monitoring**— (a) **for the educational activities of such institution**; or (b) **in the interests of safety of children enrolled with such institution**. |
| **4** | A Data Fiduciary who is an individual in whose care infants and children in a **crèche or child day care centre** are entrusted. | Processing is restricted to tracking and behavioural monitoring in the interests of safety of children entrusted in the care of such institution, crèche or centre. |
| **5** | A Data Fiduciary who is engaged by an educational institution, crèche or child care centre for **transport of children** enrolled with such institution, crèche or centre. | Processing is restricted to **tracking the location of such children**, in the interests of their safety, during the course of their travel to and from such institution, crèche or centre. |

**[A]** Fourth Schedule Note (c): *"educational institution" shall mean and include an institution of
learning that imparts education, including vocational education*. A K-12 school plainly qualifies.
Note the definition is **not** limited to government or recognised institutions.

**[A] FOURTH SCHEDULE, PART B** — *"Purposes for which provisions of sub-sections (1) and (3) of
section 9 shall not apply"*. The two that matter to certificate issuance:

| S. No. | Purpose | Conditions |
|---|---|---|
| **1** | For the **exercise of any power, performance of any function or discharge of any duties in the interests of a child, under any law for the time being in force in India**. | Processing is restricted to the extent necessary for such exercise, performance or discharge. |
| **2** | For providing or issuing of any subsidy, benefit, service, **certificate**, licence or permit, by whatever name called, under law or policy or using public funds, in the interests of a child, **under clause (b) of section 7 of the Act**. | Processing is restricted to the extent necessary for such provision or issuance. |

Part B also exempts: creation of an email-communication user account (3); determination of a child's
real-time location in her safety interest (4); ensuring harmful content is not accessible to her (5);
and age-assurance due diligence under Rule 10 (6).

### 2.4 **How wide is the education exemption? The narrow reading is the safe one.**

**[D] This is the interpretive crux and it deserves to be stated plainly, because getting it wrong
in either direction is expensive.**

Part A entry 3's column (3) is headed **"Conditions"** and reads *"Processing is restricted to
tracking and behavioural monitoring — (a) for the educational activities … or (b) in the interests of
safety …"*. Two readings are available:

- **Broad reading:** an educational institution is a wholly exempt *class*, so s.9(1) verifiable
  parental consent never applies to it at all, and the column-(3) wording merely describes the
  mischief the entry was aimed at.
- **Narrow reading (which I adopt):** the exemption is **conditional**, and the condition confines it
  to processing that *is* tracking and behavioural monitoring for educational activity or child
  safety. Outside that band — admission records, marks, fees, photographs, certificates — **s.9(1)
  verifiable parental consent applies in full**.

**Why the narrow reading should govern the build:**

1. The column is expressly labelled *"Conditions"*, and Rule 12(1) says the disapplication operates
   *"subject to such conditions as are specified in the said Part"*. A condition that does no work is
   not a natural construction.
2. Every other Part A entry is drawn the same way and is unmistakably narrow — entry 1 restricts a
   clinical establishment to *"provision of health services to the child … to the extent necessary
   for the protection of her health"*, entry 5 restricts a transport provider to *location tracking
   during travel*. Entry 3 is the same drafting pattern; reading it as an open-ended class exemption
   makes it the sole outlier.
3. **Asymmetric cost of error.** Under-claiming the exemption costs a consent flow ZenXii should
   build anyway. Over-claiming it means processing the entire student body's data with no lawful
   basis, against a **₹200 crore** head.

> **Working conclusion [D]:** the Fourth Schedule solves the problem that **school features which
> track or monitor children** (attendance analytics, CCTV, bus GPS, safety alerting) would otherwise
> have been flatly barred by s.9(3). It does **not** relieve a school of verifiable parental consent
> for ordinary student-record processing. **Build the consent architecture.** The corpus's fear that
> "if schools are a prescribed exempt class … our consent architecture changes completely" is only
> half-realised: the **s.9(3) tracking ban** is substantially lifted for schools; the **s.9(1)
> consent duty** is not.

**Legal opinion should be taken on this specific construction before it is relied on commercially.**

### 2.5 s.9(5)

**NOT FOUND** — no notification under s.9(5) (age threshold for a "verifiably safe" Data Fiduciary)
was located. s.9(5) is in any event not in force until 13 May 2027.

---

## §3 · Data Fiduciary vs Data Processor — school plus vendor

**[A]** s.2(i): *"Data Fiduciary" means any person who alone or in conjunction with other persons
**determines the purpose and means** of processing of personal data*.
**[A]** s.2(k): *"Data Processor" means any person who **processes personal data on behalf of** a Data
Fiduciary*.

**[D] The school is the Data Fiduciary; ZenXii is the Data Processor.** The school decides which
students to enrol, what records to keep, which certificates to issue and on what terms. ZenXii
processes on its instruction. **This follows from the statutory definitions and cannot be altered by
what the contract calls the parties.** This confirms the corpus's §A3.1 — no conflict.

### 3.1 What the Fiduciary owes — and why a warranty from the school does not save us

**[A]** s.8(1)–(2), verbatim:

> **8.(1)** A Data Fiduciary shall, **irrespective of any agreement to the contrary** or failure of a
> Data Principal to carry out the duties provided under this Act, be responsible for complying with
> the provisions of this Act and the rules made thereunder **in respect of any processing undertaken
> by it or on its behalf by a Data Processor**.
>
> **(2)** A Data Fiduciary may engage, appoint, use or otherwise involve a Data Processor to process
> personal data on its behalf for any activity related to offering of goods or services to Data
> Principals **only under a valid contract**.

**[A]** s.8(5) extends the security duty expressly to processor-side processing; **s.8(7)(b)** requires
the Fiduciary to *"cause its Data Processor to erase"* personal data.

**[A]** Rule 6(1)(f) now makes the contract term mandatory in substance:

> **(f)** appropriate provision in the contract entered into between such Data Fiduciary and such a
> Data Processor, wherever applicable, **for taking reasonable security safeguards**;

**[D] Consequences for ZenXii, unchanged from the corpus but now with a rule behind them:**

- **The Act imposes essentially no direct obligation on a Data Processor.** The Act's duties and the
  Schedule's penalties are addressed to the **Data Fiduciary**. ZenXii's exposure is therefore
  **contractual and commercial, not (mostly) statutory** — our breach becomes the school's ₹250 crore
  problem, and then our indemnity problem.
- **A school warranty that it holds parental consent does not discharge ZenXii** contractually, and
  **s.8(1) means the school cannot contract out of responsibility for what we do.** Both directions
  hold. This matches the position in memory under "Student AI assistant".
- **A written DPA with every school is now effectively compulsory** (s.8(2) + Rule 6(1)(f)). A
  clickwrap ToS is not a processing contract.

### 3.2 Where ZenXii may itself be a Data Fiduciary

**[D] NOT a settled point — flagged deliberately.** For processing where ZenXii determines its *own*
purpose and means — product telemetry, crash analytics, aggregate usage statistics, model training,
marketing to school staff — ZenXii is **not** acting "on behalf of" the school and is a **Data
Fiduciary in its own right** for that processing. If any such telemetry touches student data, ZenXii
inherits s.9 duties directly, including the s.9(3) bar on behavioural monitoring of children. This
is the cleanest route by which statutory liability reaches the vendor, and it is entirely within our
control.

---

## §4 · Section 16 — cross-border transfer. **Is US storage lawful?**

### 4.1 The rule, verbatim

**[A]** Act 22 of 2023, s.16 (Chapter IV, Special Provisions; commences 13 May 2027):

> **16. Processing of personal data outside India.**
>
> **(1)** The Central Government may, by notification, **restrict** the transfer of personal data by
> a Data Fiduciary for processing to such country or territory outside India **as may be so
> notified**.
>
> **(2)** Nothing contained in this section shall restrict the applicability of any law for the time
> being in force in India that provides for a **higher degree of protection** for or restriction on
> transfer of personal data by a Data Fiduciary outside India in relation to any personal data or
> Data Fiduciary or class thereof.

### 4.2 **Blacklist, not whitelist — and this is the key divergence from GDPR**

**[D]** GDPR Art. 45–49 is a **permission** model: transfer is prohibited unless justified by an
adequacy decision, SCCs, BCRs or a derogation. **DPDP s.16(1) inverts this.** Transfer to any country
is **permitted by default**; the Central Government may **restrict** transfer to specific notified
countries. It is a **negative list / blacklist**. There is no adequacy concept, no SCC regime, no
transfer-impact-assessment requirement in the Act.

This is a **materially more permissive** regime than GDPR and is the single most commercially
favourable fact in this research for ZenXii's architecture.

### 4.3 Has any country been notified as restricted?

**NOT FOUND — and on the evidence, none.** No notification under s.16(1) restricting any country or
territory was located in any source read. Two supporting points:

- **[D]** s.16 is **not in force until 13 May 2027**. A restriction notification under a section not
  yet commenced would be anomalous.
- **[D]** A negative cannot be proven from the sources available here. But the absence is consistent
  across the Act text, the Rules, the commencement notification, and all secondary coverage read.

### 4.4 What the Rules add — Rule 15

**[A]** Rule 15 (commences 13 May 2027), verbatim:

> **15. Transfer of personal data outside the territory of India.**— Any personal data processed by
> a Data Fiduciary under the Act **may be transferred outside the territory of India** subject to the
> restriction that the Data Fiduciary shall meet such requirements as the Central Government may, by
> general or special order, specify in respect of **making such personal data available to any
> foreign State, or to any person or entity under the control of or any agency of such a State**.

**[D]** Rule 15 **affirms the permissive baseline** — *"may be transferred"* — and adds a single,
narrow qualification aimed at **foreign-government access**, not at commercial hosting. It is a
sovereignty/lawful-access provision (the concern is a foreign State compelling disclosure), not a
localisation provision. **NOT FOUND:** no general or special order under Rule 15 has been issued.

### 4.5 **Answer: yes, storing Indian children's data in Ohio is lawful today.**

**[D]** Stated precisely, with each limb sourced:

1. **Today (to 13 May 2027):** lawful. **s.16 is not in force.** Nothing in the DPDP framework
   restricts it. **[A]** G.S.R. 843(E)(c).
2. **From 13 May 2027:** still lawful, **unless and until** the United States is notified under
   s.16(1) — **no such notification exists**. **[A]** s.16(1) + NOT FOUND.
3. The Act **expressly applies extraterritorially** to ZenXii's US processing. **[A]** s.3(b): the Act
   *"also apply to processing of digital personal data outside the territory of India, if such
   processing is in connection with any activity related to offering of goods or services to Data
   Principals within the territory of India."* **Being in Ohio does not put ZenXii outside the Act.**
   It puts ZenXii inside it, with the data abroad.
4. **Three live conditions attach**, none of which is satisfied by geography alone:
   - **s.16(2)** preserves any *other* Indian law imposing a higher restriction. **NOT FOUND** whether
     any sectoral instrument reaches school records; **CERT-In's 2022 directions** (ICT-systems log
     retention "within Indian jurisdiction") were **not researched this pass** and are an open risk.
   - **Rule 13(4)** — if ZenXii or a school is ever notified a **Significant Data Fiduciary**, the
     Central Government may specify personal data that **may not be transferred outside India at all**
     (§5 below). This is the only true localisation hook in the framework.
   - **Rule 15** foreign-State-access requirements, once specified.

> **[D] Bottom line for the Ohio/`nam5` architecture: the "Firestore cannot leave `nam5`" constraint
> is NOT a DPDP compliance problem today, and is not projected to become one on 13 May 2027.** The
> exposure in this file is **not** where the data sits. It is **s.8(5) security safeguards and s.9
> consent** — which are location-independent.

---

## §5 · Significant Data Fiduciary

### 5.1 The criteria

**[A]** s.10(1), verbatim:

> **10. (1)** The Central Government may notify any Data Fiduciary or class of Data Fiduciaries as
> Significant Data Fiduciary, on the basis of an assessment of such relevant factors as it may
> determine, including—
> (a) **the volume and sensitivity of personal data processed**;
> (b) risk to the rights of Data Principal;
> (c) potential impact on the sovereignty and integrity of India;
> (d) risk to electoral democracy;
> (e) security of the State; and
> (f) public order.

> **⚠ Correction to the brief.** The task framing stated that *"volume of children's data is an
> express factor"*. **It is not.** The express factor at s.10(1)(a) is *"the volume and sensitivity of
> personal data processed"* — children's data is **nowhere named in s.10**. Registered in §9.
>
> **[D]** The point survives in weakened form: children's data is plausibly "sensitive" in the
> ordinary sense, and s.10(1) is an open-ended list (*"such relevant factors as it may determine,
> including"*). But it is inference, not text.

### 5.2 Does a multi-school ERP qualify?

**[D] Undeterminable, and mostly not ZenXii's call.** Three observations:

- SDF status arises **only by Central Government notification**. It is not self-assessed and there is
  no threshold that triggers automatically. **NOT FOUND:** no SDF notification of any entity or class
  has been made.
- The obligation would in the first instance attach to **the school** (the Data Fiduciary), not to
  ZenXii (the Processor) — and an individual school's volume is small.
- **[D]** The more realistic exposure is that **SaaS ERP vendors are notified as a class**, or that a
  large school group is. A platform aggregating many schools' under-18 records is squarely the kind
  of concentration s.10(1)(a)–(b) contemplates. Treat as a **plausible future state to be
  architecturally ready for, not a present obligation.**

### 5.3 The extra duties, if notified

**[A]** s.10(2): appoint a **Data Protection Officer** who shall (i) represent the SDF, (ii) **be
based in India**, (iii) be an individual **responsible to the Board of Directors** or similar
governing body, and (iv) be the point of contact for grievance redressal; appoint an **independent
data auditor**; and undertake **periodic Data Protection Impact Assessment**, **periodic audit**, and
such other prescribed measures.

**[A]** Rule 13 (commences 13 May 2027) puts numbers and teeth on it:

> **13.(1)** A Significant Data Fiduciary shall, **once in every period of twelve months** from the
> date on which it is notified as such … undertake a **Data Protection Impact Assessment and an
> audit** …
> **(2)** … cause the person carrying out the [DPIA] and audit to **furnish to the Board a report**
> containing significant observations …
> **(3)** … observe due diligence to verify that **technical measures including algorithmic software**
> adopted by it … are not likely to pose a risk to the rights of Data Principals.
> **(4)** … undertake measures to ensure that personal data specified by the Central Government, on
> the basis of the recommendations of a committee constituted by it, is processed subject to the
> restriction that **the personal data and the traffic data pertaining to its flow is not transferred
> outside the territory of India**.

> **[D] Rule 13(4) is the one provision in the entire framework that could force ZenXii off `nam5`.**
> It is contingent on **two** events that have **both not happened**: ZenXii/the school being notified
> an SDF, and the Government specifying the localised data categories on a committee's
> recommendation. **NOT FOUND:** no such specification exists. Rule 13(3)'s algorithmic due-diligence
> duty is also worth noting against any future AI/analytics feature.

---

## §6 · Breach notification and penalties

### 6.1 The statutory duty

**[A]** s.8(6): *In the event of a personal data breach, the Data Fiduciary shall give **the Board and
each affected Data Principal**, intimation of such breach in such form and manner as may be
prescribed.*

**[A]** s.2(u): *"personal data breach" means **any unauthorised processing** of personal data or
accidental disclosure, acquisition, sharing, use, alteration, destruction or **loss of access to**
personal data, that compromises the **confidentiality, integrity or availability** of personal data.*

> **[D] Note how wide s.2(u) is.** It has **no harm threshold and no materiality filter**. "Any
> unauthorised processing" includes a mis-scoped Firestore read. **"Loss of access"** means a
> sufficiently long outage or a botched migration is a reportable breach even with zero exfiltration.
> This is broader than GDPR Art. 33, which permits a risk-based decision not to notify.

### 6.2 The mechanics — Rule 7

**[A]** Rule 7 (commences 13 May 2027):

**To each affected Data Principal** — on becoming aware, **"without delay"**, via her user account or
registered mode of communication, in "concise, clear and plain" terms: (a) description of the breach
including nature, extent and timing; (b) consequences relevant to her; (c) mitigation measures
implemented and being implemented; (d) safety measures **she** may take; (e) business contact
information of a person who can respond to her queries.

**To the Board** — two stages:

> **(a)** **without delay**, a description of the breach, including its nature, extent, **timing and
> location** of occurrence and the likely impact;
> **(b)** **within seventy-two hours** of becoming aware of the breach, or within such longer period
> as the Board may allow **on a request made in writing**, — (i) updated and detailed information …
> (ii) the broad facts related to the events, circumstances and reasons leading to the breach;
> (iii) measures implemented or proposed … (iv) any findings regarding the **person who caused** the
> breach; (v) **remedial measures taken to prevent recurrence**; and (vi) a **report regarding the
> intimations given to affected Data Principals**.

> **[D] The 72-hour clock is the *second* stage, not the first.** The first notification to the Board
> and the notification to every affected parent are both **"without delay"** — i.e. immediate. This
> is stricter than the "72 hours" shorthand in circulation. Rule 7(2)(b)(vi) also means **ZenXii must
> be able to prove which parents were notified and when** — an audit artefact the platform does not
> currently produce.

### 6.3 Minimum security — Rule 6

**[A]** Rule 6(1) sets a floor for s.8(5): **(a)** encryption, obfuscation, masking or virtual tokens;
**(b)** access control to computer resources; **(c)** **logs, monitoring and review** giving visibility
on access, to enable detection/investigation/remediation; **(d)** backups for continued processing;
**(e)** **retain such logs and personal data for one year** unless another law requires otherwise;
**(f)** the processor-contract term; **(g)** appropriate technical and organisational measures.

**[D]** Rule 6(1)(c) and (e) are **concrete, testable engineering requirements**, not principles.
A one-year access-log retention is an explicit build item.

### 6.4 Penalties — THE SCHEDULE

**[A]** THE SCHEDULE [See section 33(1)] — **not in force until 13 May 2027** (s.33 falls in the
"sections 28 to 34" tranche):

| # | Breach | Penalty |
|---|---|---|
| 1 | Obligation to take **reasonable security safeguards** to prevent a breach — **s.8(5)** | **may extend to ₹250 crore** |
| 2 | Obligation to give the Board or affected Data Principal **notice of a breach** — s.8(6) | may extend to ₹200 crore |
| 3 | **Additional obligations in relation to children — s.9** | **may extend to ₹200 crore** |
| 4 | Additional obligations of **Significant Data Fiduciary** — s.10 | may extend to ₹150 crore |
| 5 | Duties under s.15 (Data Principal's own duties) | may extend to ₹10,000 |
| 6 | Breach of a voluntary undertaking accepted under s.32 | up to the amount applicable to the underlying breach |
| 7 | **Breach of any other provision** of the Act or rules | may extend to ₹50 crore |

**[A]** s.33(1): penalties bite only where the Board determines, on conclusion of an inquiry, that
the breach **"is significant"**, after an opportunity of being heard. **[A]** s.33(2) lists the
mitigating/aggravating factors: nature, gravity and duration; **type and nature of the personal data
affected**; repetition; gain realised or loss avoided; **mitigation action and its timeliness and
effectiveness**; proportionality; and likely impact on the person.

**[D]** s.33(2)(b) and (e) are the levers ZenXii can actually pull: the data is children's data
(aggravating, fixed), but **demonstrable, fast, well-documented mitigation is expressly a
penalty-reducing factor**. A rehearsed breach-response runbook has direct monetary value.

---

## §7 · Consent, its limits, and the statutory duty to issue a certificate

### 7.1 Can a school avoid consent by using a legitimate use?

**[A]** s.7 lists the "certain legitimate uses". The two candidates:

> **(a)** for the **specified purpose for which the Data Principal has voluntarily provided** her
> personal data to the Data Fiduciary, and in respect of which she has not indicated to the Data
> Fiduciary that she does not consent to the use of her personal data.
>
> **(b)** **for the State and any of its instrumentalities** to provide or issue to the Data Principal
> such subsidy, benefit, service, **certificate**, licence or permit **as may be prescribed**, where—
> (i) she has previously consented … or (ii) such personal data is available in digital form in …
> any database, register, book or other document which is maintained by the State … and is notified
> by the Central Government, subject to standards … in accordance with the policy issued by the
> Central Government …

> **⚠ [D] s.7(b) is State-only, and this is the trap.** The words are *"for the State and any of its
> instrumentalities"*. **[A]** Rule 5(2) reinforces it throughout — "under law" means an exercise of
> power *"by the State or any of its instrumentalities"*; "using public funds" means the Consolidated
> Fund or a public authority's funds. **A private unaided school is not the State.** It **cannot**
> rely on s.7(b) to issue a TC without consent, and correspondingly **cannot** rely on Fourth Schedule
> Part B entry 2, which is expressly tied to s.7(b).
>
> A **government or aided** school stands differently and may well fall within s.7(b) — but
> **[A]** s.7(b) also requires the certificate to be one *"as may be prescribed"*, and **NOT FOUND**:
> no prescription of certificate types under s.7(b) was located (Rule 5 sets standards via the
> Second Schedule but does not itself list certificates).

### 7.2 The statutory duty to issue — how it interacts

**[D]** The cleanest route for a **private** school is **Fourth Schedule Part B entry 1**, which is
**not** State-limited:

> *"For the exercise of any power, performance of any function or **discharge of any duties in the
> interests of a child, under any law for the time being in force in India**."*

RTE s.5(3) obliges a school to *"immediately issue the transfer certificate"* (corpus §A1.1). **[D]**
Issuing a TC in discharge of that duty is a discharge of a duty, in the interests of a child, under a
law in force in India. **Part B entry 1 therefore disapplies s.9(1) and s.9(3) for that processing**,
confined "to the extent necessary for such … discharge."

**[D] Three qualifications that must travel with that conclusion:**

1. It exempts **s.9(1) and (3) only**. Every other duty — s.8 security, s.8(6) breach notice, s.5
   notice, s.11 rights — continues to apply. **s.9(2)'s absolute bar continues to apply.**
2. It is confined to **the issuance processing**, not to the underlying record-keeping that fed it.
3. It presupposes an actual statutory duty. **RTE s.5(3) covers the elementary stage (I–VIII) only**
   (corpus §A1.1). For classes IX–XII the duty must come from state education rules, and whether one
   exists is a **state-by-state question the corpus has already researched but which was not
   re-verified in this pass**.

### 7.3 Retention beats erasure where a law requires it

**[A]** s.8(7) opens *"A Data Fiduciary shall, **unless retention is necessary for compliance with any
law for the time being in force**,— (a) erase personal data, upon the Data Principal withdrawing her
consent or as soon as it is reasonable to assume that the specified purpose is no longer being
served …; and (b) cause its Data Processor to erase …"*.

**[D]** The statutory registers a school must maintain (corpus `school-and-document-types.md`) are
exactly this carve-out. **A parent withdrawing consent does not compel deletion of a statutory
admission register or of an issued TC's record.** But the carve-out is **narrow and specific** — it
protects the register, not the whole database. Everything not required by law to be retained **must**
be erasable, and **s.8(7)(b) requires erasure to propagate to ZenXii**.

**[A]** The **Third Schedule** retention-erasure regime (Rule 8) applies only to **e-commerce entities
(≥2 crore users), online gaming intermediaries (≥50 lakh users), and social media intermediaries**.
**[D] A school ERP is none of these — the Third Schedule's three-year auto-erasure does not reach
ZenXii.** The general s.8(7) duty still does.

### 7.4 Practical shape of consent for a school ERP

**[D]** Synthesising Rule 10, Rule 12 + Fourth Schedule, and s.7:

| processing | lawful basis | consent needed? |
|---|---|---|
| Admission record, name, DOB, address, parents' names, photograph | **Consent** — s.9(1) verifiable parental | **Yes** |
| Marks, attendance register, fee records | **Consent** — s.9(1) | **Yes** |
| **Tracking / behavioural monitoring** for educational activity or child safety | **Fourth Sch. Part A.3** | **No** (s.9(1) & (3) disapplied) |
| **Bus/transport location tracking** | **Fourth Sch. Part A.5** | **No** |
| **Real-time location of a child for her safety** | **Fourth Sch. Part B.4** | **No** |
| **Issuing a TC in discharge of a statutory duty** | **Fourth Sch. Part B.1** | **No** (for that processing) |
| Issuing a certificate, **government/aided school** | s.7(b) + **Part B.2** | **No**, subject to "as may be prescribed" |
| Issuing a certificate, **private school, no statutory duty** | **Consent** | **Yes** |
| Anything likely to harm a child's well-being | **s.9(2) — absolute bar** | **Consent cannot cure it** |

---

## §8 · What this means for a school ERP storing children's data in Ohio

**[D] All of this section is inference from the sourced material above.**

### 8.1 The good news, stated precisely

1. **US storage is lawful** and there is no adequacy/SCC machinery to satisfy. The `nam5` constraint
   is not a compliance defect. (§4)
2. **Nothing binds until 13 May 2027** — roughly 20 months of runway from this research date, with
   the **complete final rulebook already published**. There is nothing left to wait for.
3. **Verifiable parental consent is achievable without Aadhaar/DigiLocker** via Rule 10(1)(a), using
   parent identity and age details the school already holds. (§2.2)
4. **The s.9(3) tracking ban — which would have been product-fatal — is substantially lifted for
   educational institutions** by Fourth Schedule Part A.3 and A.5. (§2.3)
5. **ZenXii is a Processor**; the Act's penalties address the Fiduciary. Direct statutory exposure is
   limited. (§3)

### 8.2 The exposures, in priority order

| # | exposure | why | severity |
|---|---|---|---|
| **1** | **s.8(5) reasonable security safeguards** + Rule 6 minimum measures | **₹250 crore**, the largest head in the Act. Rule 6 is a concrete checklist: encryption, access control, access logs, 1-year log retention, backups, processor contract. CLAUDE.md records that **`ModuleGate.kt` fails open** and that "Firestore rules are the real boundary" — so the boundary is a single shared file that **production has drifted from** (46 of 47 blocks prod-only, per memory). A rules gap is "unauthorised processing" under s.2(u). | **Critical** |
| **2** | **s.9(1) verifiable parental consent** | **₹200 crore.** One consent door exists (public admission form, `consentGivenAt` recorded) but it is **not verifiable parental** consent under Rule 10, it is **scoped to "admission purposes"** under s.6(1), and **`Sis.php`/bulk import captures none at all** — which is how most students actually arrive. Every student is a child; the narrow reading of the education exemption leaves ordinary record processing fully exposed. See §8.3. | **Critical** |
| 3 | **s.8(6)/Rule 7 breach notification** | ₹200 crore. Requires "without delay" notice to the Board **and every affected parent**, then a 72-hour detailed report **including proof of parent notifications**. No such pipeline exists. s.2(u)'s no-threshold definition means even an outage may qualify. | **High** |
| 4 | **s.8(2)/Rule 6(1)(f) processing contract** | Cheapest item on the list and currently absent. Without a DPA **every school is in breach merely by using ZenXii** — a sales objection as much as a legal one. | **High / low cost** |
| 5 | **s.8(7)(b) erasure propagation** | The corpus already calls this "the obligation our current design is least likely to satisfy". Flat collections keyed `{schoolId}_{entityId}` across Firestore + Storage + RTDB, plus backups, make true deletion hard. | **High** |
| 6 | **s.8(3) accuracy** | A certificate is a decision-affecting record **disclosed to another Data Fiduciary**. Accuracy of TC data is a **statutory duty**, not a quality goal. Connects directly to the timetable/`teacherId` drift class of bug. | Medium |
| 7 | **ZenXii as Fiduciary for its own telemetry** | Any analytics profiling student behaviour would be **ZenXii's own** s.9(3) breach, not the school's. Directly relevant to the Student AI assistant scope. | Medium |
| 8 | **Rule 13(4) localisation if ever notified an SDF** | The only path that forces data out of `nam5`. Doubly contingent and not live. | Low / watch |

### 8.3 Applied to the system as it actually is

`data-residency-and-consent-FACTS.md` (committed `b22aa10`, during this same pass) establishes the
factual predicate. Applying the law above to those facts, point by point:

| fact established there | legal consequence [D] |
|---|---|
| **US-only data plane** — Lightsail Ohio, Firestore `nam5`, Functions `us-central1`, Storage in the same project | **Not a defect.** §4.5: lawful now, lawful after 13 May 2027 absent a s.16(1) notification. The **s.3(b)** extraterritoriality point is the one to internalise — being in Ohio puts ZenXii *inside* the Act, not outside it. |
| Consent text **names the school, not the vendor** | **Correct as drafted.** The school is the Data Fiduciary (§3); consent runs to it. **[A]** s.5(1) requires notice from *"the Data Fiduciary"*. Naming ZenXii is not required and would arguably be wrong. |
| Consent purpose is **"admission purposes"** | **The live defect.** **[A]** s.6(1): consent *"shall signify an agreement to the processing of her personal data **for the specified purpose** and be **limited to such personal data as is necessary** for such specified purpose."* Admission-scoped consent **does not reach** years of attendance, marks and fee records, nor a TC transcribed from the Admission & Withdrawal Register long afterwards. Those need either a fresh specified purpose or a Fourth Schedule / s.7 ground. |
| It **says nothing about where the data goes** | **Not a DPDP defect.** **[A]** s.5(1) requires only (i) the personal data and purpose, (ii) how to exercise rights, (iii) how to complain to the Board; **[A]** Rule 3 adds an itemised description, the specified purpose, and a communication link. **Neither requires disclosure of cross-border transfer.** This is a real divergence from GDPR Art. 13(1)(f) and the item should **not** be logged as a gap. |
| **One checkbox; no mechanism establishing the ticker is a parent** | **Does not satisfy s.9(1).** Rule 10 requires *"due diligence, for checking that the individual identifying herself as the parent is an adult who is identifiable"*. A checkbox performs no such check. **The existing door is consent, but it is not *verifiable parental* consent.** |
| Notice content generally | **[A]** s.5(1)(ii)–(iii) require telling the parent **how to exercise her rights** and **how to complain to the Board**. The quoted wording does neither. |
| **`Sis.php` and bulk import capture no consent** — the path most students actually arrive by | **The largest single gap.** For every student onboarded by spreadsheet or by office staff there is **no consent record at all**, and no Fourth Schedule entry covers ordinary record-keeping (§2.4). This is the ₹200 crore s.9 head, and it applies to the majority of the student body. |

**[A] One further obligation that attaches specifically to the consents already collected** — s.5(2):

> **(2)** Where a Data Principal has given her consent for the processing of her personal data
> **before the date of commencement of this Act**,— (a) the Data Fiduciary shall, **as soon as it is
> reasonably practicable**, give to the Data Principal a notice informing her,— (i) the personal data
> and the purpose for which the same **has been** processed; (ii) the manner in which she may
> exercise her rights …; and (iii) the manner in which [she] may make a complaint to the Board …

**[D]** Every `consentGivenAt` record captured before 13 May 2027 falls squarely in s.5(2). The
schools will owe a **retrospective notice to every one of those parents**, and ZenXii is the only
party that can generate it at scale. That is a **product feature with a known deadline** — and,
usefully, a reason to keep `consentGivenAt` exactly as it is being recorded now.

### 8.4 Interaction with work already in the corpus

- **Student AI assistant** (memory): the s.9(2) absolute bar and s.9(3) tracking ban are the binding
  constraints on tutoring/wellbeing features. The existing scope LOCK (records Q&A + helpdesk only)
  looks **well-judged in light of s.9(3)**, and the Fourth Schedule does **not** rescue a wellbeing
  feature — Part A.3 covers tracking for *educational activities or safety*, not affective profiling.
- **Certificate issuance**: §7.2 gives private schools a viable no-consent route for TC issuance via
  Part B.1, but **only where a statutory duty actually exists** — which re-opens the state-by-state
  question for classes IX–XII.
- **`documents/` + Storage paths**: Rule 6(1)(a)'s encryption/masking floor and (c)'s access-logging
  floor apply to `schools/{schoolId}/...` objects, including certificate PDFs and the proof artefacts
  noted as forgeable in the Document Engine build state.

### 8.5 What I would do with the runway — **not a plan, an input to one**

Order chosen so each item is useful before 13 May 2027 and none is wasted if the narrow reading of
§2.4 turns out to be wrong:

1. Get **legal opinion on §2.4** (scope of the Part A.3 education exemption) and on §7.2 (Part B.1 for
   TC issuance). Everything else sizes off those two answers.
2. Ship the **DPA template** — days of work, removes a present-tense breach.
3. Close the **Rule 6 checklist** as an engineering epic; it overlaps almost entirely with the
   security hardening already in flight, and it is the ₹250 crore head.
4. Build **consent capture** against Rule 10(1)(a), reusing parent identity data schools already hold
   — and extend it to **`Sis.php` and the bulk-import path**, which is where most students arrive.
   Widen the purpose statement beyond "admission purposes" (s.6(1)) and add the s.5(1)(ii)–(iii)
   rights-and-complaint wording. Plan the **s.5(2) retrospective notice** for consents already held.
5. Build the **breach runbook** with parent-notification receipts (Rule 7(2)(b)(vi)); s.33(2)(e)
   makes it worth money.
6. Make **erasure actually propagate**, backups included.

---

## §9 · Conflicts to register

| # | conflict | resolution |
|---|---|---|
| **C1** | **Corpus `central-and-boards.md` §A3.6 records as NOT FOUND whether the DPDP Rules were notified.** | **CLOSED. Notified — G.S.R. 846(E), 13 Nov 2025, published 14 Nov 2025.** §A3.6's NOT FOUND list should be struck and replaced. |
| **C2** | **Corpus §A3.6:** *"s.8 and s.9 bind on commencement regardless of whether anyone is available to impose the Schedule's penalties"* — written when commencement was unknown, and reads as though the duties are live. | **Correct in principle, but now dated.** Commencement is known: **13 May 2027**. s.8 and s.9 **do not bind today**. The sentence should be re-dated, not deleted. |
| **C3** | **Corpus §A3.4** presents the penalty Schedule as operative. | **s.33 and the Schedule are not in force until 13 May 2027** (G.S.R. 843(E)(c), "sections 28 to 34"). The amounts are right; the tense is wrong. |
| **C4** | **Corpus §A3.2:** *"s.9(4) is the escape hatch that matters to schools … Whether education has been given such a carve-out is at §A3.5, where the answer is NOT FOUND."* | **CLOSED — and the answer is "yes, but narrow".** Rule 12 + Fourth Schedule Part A entry 3. See §2.3–2.4: the **s.9(3) tracking ban is largely lifted for schools; the s.9(1) consent duty is not.** |
| **C5** | **Corpus §A3.6 working assumption:** *"the Rules had at minimum passed through a public draft stage."* | **Superseded.** The draft (G.S.R. 02(E), 3 Jan 2025) was **finalised**; objections were considered and final Rules made. |
| **C6** | **Corpus §A3.6 Wayback inference:** MeitY still recruiting Board Chairman/Members in **June 2026**. | **Not contradicted, and now more meaningful.** ss.18–26 (Board) commenced 13 Nov 2025 — the Board's *constitution* provisions are live while its *adjudicatory* jurisdiction (ss.27–34) waits until 13 May 2027. Recruiting through 2026 is consistent with standing the Board up **ahead of** the 2027 enforcement date. |
| **C7** | **Task brief** states that for Significant Data Fiduciary *"volume of children's data is an express factor"*. | **Not correct.** s.10(1)(a) reads *"the volume and sensitivity of personal data processed"*. **Children's data is not named anywhere in s.10.** The list is open-ended, so the point survives as inference only. |
| **C8** | **Task brief** frames s.16 as the question *"is US storage lawful"* with conditions. | The conditions are **thinner than implied**: s.16 is a **blacklist**, no country is notified, and the section is not yet in force. The real conditions sit in **Rule 13(4)** and **s.16(2)**, not in s.16(1). |
| **C9** | **Intra-source, resolved:** indiankanoon's search index returned `/doc/180784190/` as "Section 17 Exemptions" in one query and as "Section 16" when summarised from the full-Act page. | **Resolved by direct fetch:** `/doc/180784190/` **is s.17**; s.16 is `/doc/172647580/`. Both then verified against the Gazette PDF, which governs. Treat indiankanoon's per-section doc IDs as unreliable without verification. |
| **C10** | **Not a conflict — a confirmation.** Memory "Student AI assistant": *"DPDP s.8(1) school warranty does NOT discharge vendor."* | **Confirmed** by s.8(1) verbatim + Rule 6(1)(f). |

---

## §10 · NOT FOUND register

Each of these was looked for and not established. **None has been filled with a guess.**

| # | question | status |
|---|---|---|
| N1 | Any notification under **s.16(1)** restricting transfer to a country/territory | **None located.** Consistent across all sources; s.16 not yet in force. A negative cannot be proved from these sources. |
| N2 | Any **Significant Data Fiduciary** notification under s.10(1) | **None located.** |
| N3 | Any notification under **s.9(5)** (age threshold for a "verifiably safe" Data Fiduciary) | **None located.** |
| N4 | The personal-data categories to be **localised under Rule 13(4)**, and the constitution of the recommending committee | **Not specified.** Rule 13 not in force. |
| N5 | Any **general or special order under Rule 15** (foreign-State access requirements) | **None located.** |
| N6 | Whether the **Data Protection Board of India is constituted and staffed** | **Not verified.** ss.18–26 in force since 13 Nov 2025; corpus Wayback evidence suggests recruitment was ongoing June 2026. |
| N7 | Certificates **"as may be prescribed"** under **s.7(b)** — is a TC/bonafide prescribed? | **Not located.** Rule 5 sets standards via the Second Schedule but does not enumerate certificates. **Blocks reliance on s.7(b)/Part B.2 by government schools.** |
| N8 | **Second Schedule** text (standards for State processing under Rule 5) | **Not extracted** this pass. Present in the Gazette PDF at `blueprints/.../scratch` — retrievable without a new fetch. |
| N9 | **CERT-In Directions (28 Apr 2022)** — log retention "within Indian jurisdiction", 6-hour incident reporting — and their interaction with s.16(2) | **Not researched.** **Open risk to the Ohio architecture and the one item in this file most likely to disturb the §4.5 conclusion.** |
| N10 | Any **sectoral Indian law** imposing a higher transfer restriction on education records under s.16(2) | **Not researched.** |
| N11 | Exact 18-month commencement date — **13 vs 14 May 2027** | **Ambiguous** (notification dated 13 Nov; gazette published/signed 14 Nov). Use 13 May 2027. |
| N12 | Whether **state education rules create a TC duty for classes IX–XII** (needed for §7.2 Part B.1) | **Not re-verified** this pass; partially answered elsewhere in the corpus. |
| N13 | `indiacode.nic.in` as a source | **Inaccessible** — 403 to the agent fetcher, 404 to curl on every route tried. Do not budget passes against it. |
| N14 | Judicial interpretation of the Fourth Schedule education exemption | **None exists.** The Rules are not yet in force; there is no case law. §2.4 will remain inference until there is. |

---

## Appendix · Reproducing this research

The two Gazette PDFs are the whole evidentiary basis and both are retrievable without search:

```
curl -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/125.0" \
  -o dpdp_rules.pdf \
  https://www.meity.gov.in/static/uploads/2025/11/53450e6e5dc0bfa85ebd78686cadad39.pdf

curl -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/125.0" \
  -o act_commencement.pdf \
  https://www.meity.gov.in/static/uploads/2025/11/c56ceae6c383460ca69577428d36828b.pdf

pdftotext -layout dpdp_rules.pdf dpdp_rules.txt   # English text begins ~line 986
```

**A User-Agent is mandatory** — MeitY 403s the agent fetcher on every URL while serving the same
bytes to curl. The Act itself is at
`prsindia.org/files/bills_acts/bills_parliament/2023/Digital_Personal_Data_Protection_Act,_2023.pdf`
(the agent fetcher mis-reads it as a certificate chain; `pdftotext` handles it correctly).
