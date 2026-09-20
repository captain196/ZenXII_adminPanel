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

- The **verbatim Schedule text** for Part A and Part B — not retrieved.
- **What qualifies as the Part B expert** — not established.
- Whether the **DigiLocker/DLTS** route sidesteps this: there the issued artefact is **signed XML**
  held by a government intermediary, which may authenticate differently from a PDF we render. The
  two paths were researched separately and have never been compared on admissibility.
- Whether a **hash chain** across issued documents is warranted, or a per-document hash suffices.
