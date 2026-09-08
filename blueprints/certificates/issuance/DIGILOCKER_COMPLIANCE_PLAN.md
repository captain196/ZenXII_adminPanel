# Government-compliant issuance — the plan

**Prepared** 2026-09-08 · **Basis** DigiLocker *Issuer API Specification v1.13* (May 2024,
API Setu) and the DigiLocker partner-onboarding FAQ (updated 24.03.2025), read directly ·
**Live data** 9 school records read 2026-09-08

Everything marked **[SPEC]** is quoted or paraphrased from the primary documents. Everything
marked **[OURS]** is a design decision. Nothing here transcribes law the compliance corpus has
not verified.

---

## 1 · What the specification actually requires

### 1.1 The issuer is the school, not ZenXii **[SPEC]**

> *"**Issuer** — An entity/organization/department issuing e-documents to individuals in DLTS
> compliant format and making them electronically available within a repository of their
> choice."*

Each school onboards as its own issuer. ZenXii is the technology partner that hosts the
repository and exposes the API **on the school's behalf**. This single fact decides the data
model: `issuerId` belongs to the school record, not to ZenXii.

### 1.2 Becoming an issuer is a request, not an API **[SPEC]**

The onboarding FAQ is explicit:

> Q55 · *"How can we issue student documents for institutions on DigiLocker?"*
> **"Need to become an issuer first in order to push student documents."**
>
> Q61 · *"What is the process to become an issuer?"*
> **"You must submit a request with a detailed use case."** Approval is by DigiLocker.

So **there is no self-service issuer registration**, and no API that makes a school an issuer.
Verification of issuer status is therefore a *lookup against the published issuer list*, plus
the school's own onboarding evidence — not something ZenXii can grant.

> Q43 · a partner wanting to push an **education certificate** was **diverted to the NAD team**.
> The National Academic Depository is the route for academic awards.

### 1.3 The document URI, and the number that must NOT be sequential **[SPEC]**

Every DLTS document carries a URI of the form

```
IssuerId - DocType - DocId
```

| part | rule |
|---|---|
| `IssuerId` | Unique **across India**, pure alpha, case-insensitive. Derived from the issuer's domain where available — the spec's own examples are `cbse.nic.in`, `kseeb.kar.nic.in`, `maharashtra.gov.in`, `UDEL`. **Published via a central e-governance codification scheme.** |
| `DocType` | Issuer-defined, **≤ 5 characters**, pure alpha, unique within that issuer |
| `DocId` | Alphanumeric, **≤ 10 characters**, unique within issuer + doctype |

And the requirement that matters most to us:

> *"It is recommended that issuers define document IDs … using a **RANDOM** number/string
> generator. **Using random string eliminates the possibility of 'guessing' next sequence
> number and accessing a list of documents in a sequential way. This is critical to ensure
> security** … issuer needing to issue a total of **n** documents … use at least **10n**
> random space."*

**This conflicts with how we number today, and both are right.**

`Sis::_get_tc_number()` allocates a **sequential** number by atomic CAS. That is correct and
statutorily expected — CBSE requires a pre-printed Book No. and Sl. No., and a register is
sequential by nature. But a sequential value **must not** become the DigiLocker `DocId`,
because the spec forbids exactly that: a guessable id would let anyone enumerate a school's
certificates.

**[OURS]** They are two identifiers with two jobs and must never be conflated:

| | purpose | shape |
|---|---|---|
| **Certificate number** | the statutory serial, printed, register-ordered | sequential, atomic — as today |
| **DocId** | the DigiLocker handle | random, ≥ 10× the issued space |

### 1.4 The e-document is signed XML, not a PDF **[SPEC]**

> *"**Electronic Document or E-Document** — A **digitally signed** electronic document in
> **XML** format issued to one or more individuals (Aadhaar holders) in appropriate format
> compliant to DLTS specifications."*

We render PDF through mPDF. A DLTS e-document is **signed XML**. The PDF remains the human
artefact; the XML is the machine one. This is additional work, not a substitution.

### 1.5 The pull model **[SPEC]**

Issuance flow, §6:

1. Create a digitally signed e-document with a unique URI
2. **The issuer creates a document repository** storing documents and making them available
   online
3. **Issue the printed document to the individual with a human-readable document URI** — and
   offer to push that URI to the resident's DigiLocker

DigiLocker then **calls the issuer's REST Pull URI Request API** to fetch the document.

**[OURS]** Three consequences. The repository must be internet-reachable and highly available.
The **printed certificate must carry its own URI** — the engine already has a `qr` object
type, and `FINAL_BLUEPRINT` already deferred a "QR verification endpoint"; this is what goes
in it. And ZenXii would operate that endpoint for every tenant, which is a multi-tenant
availability commitment, not a feature flag.

### 1.6 Owner matching **[SPEC]**

> §7.2 · *"DigiLocker ensures that the individual can access the document from issuer's
> repository only when the owner uniquely identifies a document that belongs to him/her and
> the individual's profile data matches the document data in the issuer's repository."*

**[OURS]** Our student records must therefore carry enough matched identity for the pull to
resolve — and that is personal data, which brings DPDP obligations with it. Not a field to add
casually.

### 1.7 Infrastructure **[SPEC]**

> Q9 · *"A registered Indian mobile number and **a server located in India** are mandatory for
> API access."*

Asked about firms outside India, so its reach is unclear — but our server is in **Ohio
(us-east-2)**, placed there deliberately for `nam5` Firestore latency
(`PATH_A_US_SERVER_RUNBOOK.md`). **This must be resolved with DigiLocker before any commitment
is made**, not discovered during integration. It is the single largest unknown in this plan.

---

## 2 · What this means for the ladder

The four rungs already built stay, and DigiLocker slots in as a **fifth**, above them —
because being a registered issuer is strictly more than us having checked an affiliation.

| | state | what it means | may issue? |
|---|---|---|---|
| 0 | Unrecorded | nothing well-formed | no |
| 1 | Claimed | shape is right for the board | no |
| 2 | Evidenced | the instrument is on file | **local only** |
| 3 | Verified | checked against the board directory, named person, dated | **local only** |
| 4 | **Registered issuer** | present in DigiLocker's published issuer list, with an `IssuerId` | **local + DigiLocker** |

**[OURS] Level 4 gates DigiLocker publication, not local issuance.** Making level 4 a
precondition for issuing *any* certificate would be wrong on the law: RTE s.5(3) makes a
Transfer Certificate **immediate and non-withholdable** at the elementary stage, and the
courts have refused even a fee-dues gate (Delhi HC LPA 393/2014, Kerala HC 2025:KER:69076,
Madras HC DB 22.07.2024, Karnataka HC 2025:KHC:5986). A school cannot be told to wait for a
DigiLocker approval before giving a child their TC.

So: **level 2 issues on paper; level 4 additionally issues into DigiLocker.**

---

## 3 · Sequence

Ordered so each step is safe alone, and so nothing gates a document before the gate can be
passed.

**A · Repair `tc_print.php`** — every TC printed today reads *"Affiliated to C.B.S.E, New
Delhi"* regardless of board or state, and never prints the affiliation number, because the
view reads snake_case keys off a camelCase document (L33). No dependencies. Stops a false
statutory claim now.

**B · Complete the ladder to level 3** — evidence upload, verification record, seal. Until
these exist the ladder cannot be climbed and any gate is an outage.

**C · Gate local issuance at level 2, warn-first** — `Sis::issue_tc()` consults `mayIssue()`.
Following this repo's own convention that hooks warn rather than block, log and surface first,
then enforce once schools have climbed.

**D · Separate the two identifiers** — keep the sequential certificate number; add a random
`docId` per issued document, ≥ 10× space. Cheap now, and impossible to retrofit cleanly after
documents exist.

**E · Print the verification URI** — a QR carrying the document URI, using the object type the
engine already has. Useful immediately as our own verification endpoint; becomes the DigiLocker
URI at level 4.

**F · Resolve the India-server question** — before any DigiLocker commitment.

**G · Issuer registration workflow** — capture `IssuerId`, the onboarding request and its
approval evidence; verify against the published issuer list; reach level 4.

**H · Signed-XML e-document + Pull URI API** — the actual integration, and the largest piece.

---

## 4 · Compliance obligations to carry through every step

- **DPDP Act 2023** — owner-matching data (§1.6) is personal data about children. Purpose
  limitation, retention and the school-as-fiduciary relationship all apply. Our own memory
  already records that a school's warranty does not discharge the vendor.
- **IT Act digital signature** — a DLTS e-document is *digitally signed*. Which signing
  instrument (DSC vs eSign) and who holds it is a question for counsel, not for us to assume.
- **RTE s.5(3)** — no gate we add may delay a TC at the elementary stage. This constrains the
  whole design and is why level 4 does not gate paper.
- **CBSE r.8(vi)/(vii)** — duplicate marking and countersignature survive into any digital
  issuance.
- **Corpus honesty rule** — where an authority is unverified, enforce nothing and say so.
  Maharashtra, Karnataka and Uttar Pradesh still have no verified authority, and three of the
  nine schools in this project are in UP.
