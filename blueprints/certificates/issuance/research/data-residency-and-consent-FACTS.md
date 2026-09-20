# Where the data actually is, and what consent actually exists

**This file is facts about the system, not law.** The legal analysis lives in
`dpdp-childrens-data.md`. This establishes the predicate that analysis has to be applied to, because
a data-protection conclusion is worthless against a guessed architecture. Everything here was read
out of the repository.

## 1 · The entire data plane is in the United States

| component | where | evidence |
|---|---|---|
| PHP application | **AWS Lightsail, Ohio** (`us-east-2`, `3.138.59.194`) | `CLAUDE.md`, `PATH_A_US_SERVER_RUNBOOK.md` |
| Firestore | **`nam5` (US multi-region)** — and *"cannot move"* | `CLAUDE.md`; the server was relocated to Ohio **to sit next to it** |
| Cloud Functions | **`us-central1`** | 8 files: `index.js:408,545`, `storyCounters.js:36`, `storyAudience.js:34`, `supportDesk.js:51`, `staffCapabilities.js:38`, `recoveryContact.js:37`, `fee_generation_worker.js:289` |
| Firebase Storage | same Google project, bucket `graderadmin.appspot.com` | `CLAUDE.md` |

**There is no Indian region anywhere in the stack**, and the Firestore location is not a
configuration choice that can be revisited — Firestore regions are fixed at project creation. The
Ohio server exists *because* of it: cross-region reads were costing ~1.7 s each.

**So every record this system holds about an Indian schoolchild — name, parents' names, date of
birth, address, photograph, attendance, marks, fees, and caste category where recorded — is stored
and processed outside India.** Whether that is lawful is the s.16 question, and it is answered
elsewhere. **That it is the case is settled here.**

## 2 · Consent exists at exactly one door

**It is real, and someone built it deliberately.** `application/views/admission/public_form.php:292`
carries the comment *"Consent — required by India's DPDP Act and GDPR"*, and
`Admission_public.php:253-260` refuses the submission without it:

> *"storing PII without explicit consent is a compliance …"* — and the request is rejected with
> *"You must accept the consent statement to submit the application."*

It records **`consentGivenAt`** as an ISO-8601 timestamp on the document. That is better than most of
this codebase does with anything.

### The wording, verbatim

> *"I confirm the information above is correct and consent to **{school}** storing and processing
> this application data for **admission purposes**. I understand my contact details may be used to
> communicate about this application."*

### Four properties of that sentence, stated without legal conclusion

1. **It names the school, not the vendor.** A parent consents to the school storing data. ZenXii is
   not mentioned, and neither is any processor.
2. **Its stated purpose is "admission purposes."** Not enrolment, not the years of records that
   follow, not certificate issuance, not the Admission & Withdrawal Register that a transfer
   certificate transcribes years later.
3. ~~**It says nothing about where the data goes.**~~ **CORRECTED 2026-09-20 — this is not a
   defect.** I listed the absence of any cross-border disclosure as a property worth noting, with the
   implication it was a gap. The DPDP research establishes that **neither s.5 nor Rule 3 requires a
   Data Fiduciary to disclose cross-border transfer** — a real divergence from GDPR Art. 13(1)(f),
   and a place where importing European intuitions would have manufactured a finding. The consent is
   silent on this because Indian law does not ask it to speak.
4. **It is one checkbox**, not an itemised notice, and the person ticking it is whoever filled the
   form — there is no mechanism that establishes them as a parent or guardian.

## 3 · Most students never pass through that door

**The consent is on the public admission form only.** Checked directly:

| path a student can enter by | consent references |
|---|---|
| public admission form (`Admission_public.php`) | **yes — enforced, timestamped** |
| **`Sis.php`** — student CRUD and bulk import | **0** |
| `Sis_tier2_verify.php` | **0** |

`Sis.php` is the main student module and owns the import path. **A school that onboards by
spreadsheet — which is the normal way a school with existing students starts on an ERP — captures no
consent for any of them.** So does a school whose office staff add students by hand.

**The consent covers the one entry path a greenfield admission uses, and none of the paths an
existing school uses to load the students it already has.**

## 4 · What this file does NOT claim

It does not say any of this is unlawful. DPDP has legitimate-use grounds that may not need consent
at all, education may be exempt from parts of s.9, and s.16's cross-border rule is **not** the
GDPR's and may permit US storage outright. **Those are live questions and they are being researched
separately.**

What is settled is the factual predicate: **US-only data plane, one consent door, an
admission-scoped purpose, and no consent on the paths most students actually arrive through.**
