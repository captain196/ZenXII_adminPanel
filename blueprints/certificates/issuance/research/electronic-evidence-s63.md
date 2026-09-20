# Is what we issue admissible? — electronic evidence, s.63 BSA

Closes the dimension C-64 recorded as having **zero** coverage. The programme had researched who may
issue a certificate and what it must say, and had never asked whether the artefact is admissible as
evidence — which matters because **C-62 established that the TC's most litigated function in India is
proof of date of birth.**

## 1 · The statute everyone still cites is repealed

**The Indian Evidence Act 1872 has been replaced by the Bharatiya Sakshya Adhiniyam 2023, and the
successor to s.65B is s.63 BSA.** [A]

Anything in this codebase, or in advice given to a school, that cites *"section 65B"* is citing a
provision that no longer governs. The case law survives — courts decided under s.65B are applied to
s.63 — but the **certificate requirement has materially changed**, so the old form is not sufficient.

## 2 · The certificate is mandatory for us, and the one exception cannot apply

The leading authority is **Arjun Panditrao Khotkar v. Kailash Kushanrao Gorantyal**
(2020 SCC OnLine SC 571), applied in **Sundar @ Sundarrajan v. State by Inspector of Police**
(SC, 21.03.2023, Chandrachud CJI, Kohli and Narasimha JJ) **[A]**:

> *"The required certificate under Section 65-B(4) is unnecessary if the original device itself is
> produced."* (§36)

— and the certificate is **mandatory** where the device *cannot* be brought to court because it is
part of a larger system or network.

**That exception can never be available to ZenXii.** A certificate we render is produced by a PHP
application on a Lightsail instance in Ohio, writing to Firestore in `nam5`. **No one is producing
that device in an Indian courtroom.** So for every document this system issues, the statutory
certificate is **always required** — the convenient escape route in the case law is closed to a
SaaS product by construction.

The same judgment settled the field: **Shafhi Mohammad overruled**, **Tomaso Bruno declared
*per incuriam***, **Anvar P.V. reaffirmed** (§36). *"Oral evidence in the place of certificate cannot
possibly suffice as Section 65B(4) is a mandatory requirement of law."*

## 3 · What s.63 BSA actually demands — and it is heavier than s.65B was

**[A]**, from a Delhi District Court judgment of 19.09.2026 (CS SCJ 609/25):

> *"Section 63(4) BSA mandates a standard-form certificate prescribed in the Schedule which inter
> alia requires the **disclosure of hash value** of the electronic/digital record along with a
> **further certification by an expert**."*

> *"Hash value of an electronic data is synonymous with an electronic fingerprint and provides a
> sure way of identifying and verifying digital data."*

The Schedule certificate is in **two parts**, and both are required **[A/B]**:

| part | what it carries | who |
|---|---|---|
| **Part A** | identification of the record, **including its hash value** | the party tendering |
| **Part B** | *"certification by an expert"* — described as *"an additional layer of authenticity to the secondary electronic evidence"* | an expert |

**Consequence of omission, stated by the court:** without compliance *"the document becomes
inadmissible"* and *"fails to meet the statutory threshold of admissibility and hence, cannot be
considered in evidence."*

**Honest limit:** the judgments retrieved describe Part A and Part B and confirm both are required,
but **none quotes the Schedule's text or defines the expert's qualifications.** The precise wording
of the form, and what kind of expert Part B demands, are **NOT ESTABLISHED** here.

## 4 · Where this leaves the Document Engine — better placed than expected, with one real gap

**The primitive s.63 Part A needs already exists, and is already used with discipline.**
`Doc_templates.php:1132/1141` computes `'sha256:' . hash('sha256', $pdf)` over **real PDF bytes**,
and `Doc_template_service.php:697` freezes it into the immutable publish snapshot as
`proofPdfHash`. There is even a guard against a fabricated hash reaching the snapshot — the module
already treats a hash as something that must have been *produced*, not asserted.

`contentHash()` is a different thing and should not be confused with it: a canonical hash of the
**design**, deliberately excluding status, `lockVersion` and timestamps, answering *"is the proof on
record still a proof of THIS document?"*

**The gap is the one that matters for admissibility.**

| we hash | scope | persisted |
|---|---|---|
| the **proof PDF** | one specimen render per **published template version** | ✅ `proofPdfHash` |
| the **design** | the template | ✅ `contentHash` |
| **the certificate actually handed to a student** | — | ❌ **nothing** |

**A hash of the template proof authenticates the design. It says nothing about the document a court
is holding.** Part A wants the hash of *the record being tendered* — this student's transfer
certificate, on this date.

**The timing is the opportunity.** The pipeline research established that **no Document Engine print
path is wired** — all 8 rows in `document_targets.php` are `wired => false` and `Doc_resolver` has
zero production callers. So **nothing has been issued through it yet, and there is no backlog of
unhashable documents.** Designing a per-issued-document hash now costs a field; adding it after a
year of issuance means a year of certificates that cannot be authenticated.

## 4a · The field list, and proof that Part B omission is fatal

**The certificate's substantive contents**, paraphrased from the statute by the court in
**H.S. Rai v. Paramjeet Singh Oberoi** (CS 873/18, Delhi District Court, 20.02.2026) **[A]** — s.63(4)
requires *"a certificate in prescribed form … submitted along with the electronic record"*, which must

> *"identify the electronic record, describe manner of production, provide device details, state
> **hash value and algorithm**, and be signed by person responsible for operation of relevant
> device."*

**"Hash value AND algorithm"** is a concrete field pair, and it happens to be the shape the module
already emits: the stored value is literally `'sha256:' . hash('sha256', $pdf)`, so the algorithm
travels with the digest rather than being implied. That part of the requirement is already met in
form — it is the *scope* of what we hash that is wrong (§4).

**And Part B is not a formality.** In **State v. Aman @ Chinu & Anr** (Cr. Case 286/2025, Delhi
District Court, ACJM-01, Tis Hazari, 01.08.2026) **[A]** the certificates were *"either in general or
… given only in Part-A"*, and the court held the

> *"absence of Part-B of the certificate which is also a necessity under Section 63 BSA and its form
> provided in the Schedule"*

meant *"such certificates cannot render the electronic records liable for being admitted in
evidence."*

**That is a decided criminal case in which electronic evidence was excluded because Part B was
missing.** So the expert limb is not a drafting nicety that courts overlook in practice — it has
already been the reason evidence failed.

## 5 · The question that is not technical

**Part B wants an expert. Who is it?**

s.65B's signatory test — *"a person occupying a responsible official position in relation to the
operation of the relevant device or management of the relevant activities"* **[A]** — was already
awkward for SaaS. For a school issuing through ZenXii, the device is operated by the vendor and the
activity is managed by the school.

**Part B's expert makes it sharper, because an expert is a person with qualifications, not a role
somebody happens to hold.** A school clerk is not one. This is a **contractual and commercial
question before it is an engineering one** — if schools cannot produce an expert, the product either
supplies one or ships documents its own users cannot tender in court.

**Recorded, not answered.**

## 6 · Open

- The **verbatim Schedule text** for Part A and Part B — **not retrieved**, and the negative is now
  better founded: five judgments that discuss the certificate in detail were read, and **none
  reproduces the Schedule**. They paraphrase it. Getting the form itself needs the Act text, and
  `indiacode.nic.in` returned **HTTP 403**.
- **What qualifies as the Part B expert** — **not established.** H.S. Rai gestures at *"an expert
  from postal authorities or the courier company"* for the facts before it, which is domain-specific
  and no guide to ours.
- ~~Whether the **DigiLocker/DLTS** route sidesteps this~~ — **answered in §7, and the answer is no.**
- Whether a **hash chain** across issued documents is warranted, or a per-document hash suffices.


---

## 7 · DigiLocker does NOT sidestep this — and I had it backwards

I proposed that the DigiLocker route might authenticate differently and be *"the safer issuance
channel for exactly the documents that end up in court."* **The evidence says the opposite, and it
says it about our document specifically.**

### Rule 9A is permissive, not mandatory

**Rule 9A**, *Information Technology (Preservation and Retention of Information by Intermediaries
Providing Digital Locker Facilities) Rules 2016*, notified **8.2.2017**, effective **21.7.2016** —
titled *"Issuing certificates or documents in Digital Locker System and accepting certificates or
documents shared from Digital Locker Account at par with Physical Documents"* **[A]**:

> *"Issuers **may** start issuing and Requesters **may** start accepting digitally (or
> electronically) signed certificates or documents shared from subscribers' Digital Locker accounts
> **at par with the physical documents**."*

> *"…when accessed or accepted by a requester through the URI, it shall be deemed to have been shared
> by the issuer directly in electronic form."*

**The verb is "may", in both limbs.** Rule 9A permits a requester to accept; it does not oblige one.
The parity is an entitlement to rely, not a duty to accept — and everything in the corpus about
schools demanding originals survives it.

### A High Court has already refused to enforce that parity — for a School Leaving Certificate

**Hritika Mitra v. The Registrar, Ravenshaw University**, High Court of Orissa at Cuttack,
**W.P.(C) 8537 of 2021**, decided **25.06.2021** **[A]**.

The petitioner argued precisely the proposition I was testing — *"documents issued under the
DigiLocker system are deemed to be at par with the original physical documents in terms of
Rule 9A."*

**The court rejected it.** It held that while digitally certified documents might suffice for some
items such as mark sheets, mandatory documents requiring physical verification — **specifically the
School Leaving Certificate** — must be produced in original. Relief of the kind sought *"can be
granted only in exceptional circumstances and in the rarest of rare cases."* **Admission denied.**

**Of every document class this could have turned on, it turned on the leaving certificate.**

### The distinction that keeps this honest

**Hritika Mitra is an admission-requirement case, not an evidence case.** It decides whether an
institution may insist on a physical original, not whether a DigiLocker document is admissible under
s.63 BSA. Those are different questions and I am not going to collapse them.

**But the search for the evidence question returned nothing at all**: a query for judgments accepting
or rejecting a DigiLocker document as evidence, or requiring a s.65B/s.63 certificate for one,
returned **no matching results**. So:

| question | answer |
|---|---|
| Does Rule 9A *oblige* acceptance? | **No — "may", both limbs** |
| Has a court enforced Rule 9A parity for a leaving certificate? | **No — Orissa HC declined** |
| Has any court ruled on DigiLocker documents as *evidence* under s.65B/s.63? | **Not found** |

**So DigiLocker cannot be relied on to avoid the s.63 certificate.** It may still be worth building
for reach, convenience and issuer verification — the compliance plan's reasons are untouched — but
**not on the theory that it solves admissibility. It does not, on anything established here.**

**Correcting the record:** my suggestion that DigiLocker would be the safer channel for
court-bound documents was a hypothesis, and testing it produced the reverse. The one judgment on
point went against parity, on our exact document type.


---

## 8 · Who the Part B expert is — two readings, and the difference is commercial

**Not resolved. Framed, because the two candidate readings have very different consequences and it
is worth knowing which question is open.**

### Reading (a) — any person with relevant expertise

The judgments say only *"an expert"*. In **H.S. Rai** the court gestured at *"an expert from postal
authorities or the courier company"* for the facts before it — a domain person, not a credentialed
forensic examiner. On this reading a suitably qualified technical person could sign Part B, and a
vendor could plausibly supply one.

### Reading (b) — a notified Examiner of Electronic Evidence under s.79A IT Act

**s.79A IT Act 2000 [A]:**

> *"79A. Central Government to notify Examiner of Electronic Evidence.—The Central Government may,
> for the purposes … agency of the Central Government or a State Government as an Examiner …"*

And courts hold the notification is constitutive, not descriptive: **unless an agency is formally
notified under s.79A it cannot act in that capacity** — *Shyam Sunder Prasad v. Central Bureau of
Investigation* (Allahabad HC, 2022) **[A]**.

**On this reading a school could not self-certify at all**, nor could a vendor: Part B would require
routing every issued certificate — or at least every one that ends up in evidence — through one of a
small number of notified government agencies.

### What is actually established

**Nothing links the two.** A targeted search for judgments connecting the s.63 BSA Part B expert to
a s.79A Examiner returned **no such judgment**. The BSA cases discuss *"an expert"*; the s.79A cases
discuss notification; **no retrieved authority joins them.**

| question | status |
|---|---|
| Does Part B require *an* expert? | **Established [A]** — and its absence is fatal (*State v. Aman*) |
| Must that expert be a s.79A notified Examiner? | **NOT ESTABLISHED — no authority found either way** |
| Can a school or its vendor supply one? | **Follows from the above; therefore open** |

### Why this is the right place to stop

The gap between (a) and (b) is the gap between *"add a signatory step"* and *"every certificate that
might be litigated must pass through a government forensic agency."* **Guessing which one is true
would be worse than leaving it open**, because both are plausible on the text and the product
decision is not reversible once schools are told which it is.

**This needs a lawyer, not another search.** It is the single highest-value question in the whole
electronic-evidence thread, and it is the one thing here that research of this kind cannot close.
