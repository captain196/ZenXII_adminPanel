# PSEB — gap closed from a real browser

**Retrieved** 2026-09-12 · **Evidence A** (primary PSEB document, read directly)

The north/central board stream diagnosed PSEB as unreachable — *"a Cloudflare bot challenge,
not absence, and defeatable from a real browser"*. That diagnosis was correct. Driving Chrome
reached the site with no challenge at all, and `static.pseb.ac.in` then served the PDF to a
plain `curl`.

**Source:** *ਸਕੂਲ ਤੋਂ ਸਕੂਲ ਟਰਾਂਸਫਰ ਦਾ ਸ਼ਡਿਊਲ* — Punjab School Education Board, Registration
Branch, signed **Superintendent (Regn.), 09.09.2026**.
<https://static.pseb.ac.in/uploads/1788950587_schtosch.pdf>

---

## The finding: Punjab has replaced the paper TC with a portal transaction

For registered students in **classes 8 to 12**, a transfer is not a document a school writes.
It is a two-sided workflow inside PSEB's own portal, and **the certificate is printed out of
the board's system**, not composed by the school.

**New school** — student applies → school logs in → *School Transfer* → *Apply for Transfer* →
search by **Student Unique Id / Reg. No / Aadhaar No / Mobile No** → the student's record
appears → check registration-fee status → *Transfer* → upload the candidate's application and
a **reason of transfer** → *Apply Transfer*.

**Previous school** — *Received List* → *Accept/Reject by School*, with remarks.

**Then** — the new school pays the transfer fee online, the student moves, and **the transfer
certificate is printed from the portal** by both schools.

| | |
|---|---|
| Fee | **₹1,000** to 30-10-2026; **₹2,000** from 31-10-2026 to 31-12-2026 |
| Hard deadline | the whole process, including payment, **before 31 December 2026** |
| Signature | the Principal **e-signs** the Declaration — e-sign is already in production use here |

### The sanction, which is unusually direct

> If a school head admits a student on their own paper **without** doing the school-to-school
> transfer, the student's online entry **remains with the previous school** — so the new school
> cannot generate examination forms for classes 8/10/12, nor upload results for 9/11.

And:

> After the Board's admission deadline, **no student is to be admitted without the
> school-to-school transfer.** A head who does so is **personally responsible** and action will
> be taken against them under the rules.

---

## What this means for ZenXii — a product constraint, not a nicety

**For a PSEB school, our Document Engine must not be the source of the Transfer Certificate.**
The board portal is. A school that prints ours instead of completing the portal transfer does
not merely produce a second document — it **leaves the child registered at the old school** and
loses the ability to enter them for their own board examinations.

This is the first jurisdiction found where issuing our own TC would cause concrete harm. It
belongs in the same class as the Migration Certificate: a document the school has no authority
to originate, where the right behaviour is to **not offer the template** and say why.

It also refines the "printed verifiable identifiers" pattern the other streams found. Punjab has
gone one step further than Karnataka's DISE code or CBSE's website upload: **the board holds the
record and issues the artefact**, and the school's role is to request and accept.

---

## A tension to record, not resolve

The transfer carries a **fee (₹1,000–2,000)**, a **deadline**, and — per the process — cannot
proceed while the **registration fee is pending or unpaid**. It covers **class 8**, which is
inside RTE's elementary band (I–VIII).

RTE **s.5(3)** says the head teacher *"shall immediately issue the transfer certificate"* and
attaches no condition, and four High Courts have held that dues may not block a TC while
preserving the school's right to recover them.

These may be reconcilable — the fee appears to be levied on the *school* for the portal
transaction rather than on the child, and a board registration fee is not school dues. **I have
not established that**, and reading the Punjabi text alone cannot settle it. Recorded as an open
question rather than asserted either way.

---

## Also visible on the PSEB site, and relevant

- **PSEB certificates are on DigiLocker** for classes 8, 10 and 12 (session 2026) — so PSEB is
  already an onboarded issuer, and the national-rails work has a live precedent in one of our
  own target states.
- PSEB runs a **certificate-verification service** that departments and organisations register
  for — again the verification-by-lookup pattern rather than countersignature.
- Duplicate / second-copy certificates for classes 10 and 12 are obtained **through Seva
  Kendras**, not from the school.

## Still open for Punjab

The **PSEB affiliation regulations** themselves — whether the board prescribes a TC format for
classes below 8, mandates printed identifiers, or requires countersignature — were not located
in this pass. The site's affiliation section surfaced schedules and circulars, not the
regulations volume.
