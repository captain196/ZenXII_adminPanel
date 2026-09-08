# How a school actually becomes eligible to issue documents

**Researched** 2026-09-08 · from statute, a real recognition order, CBSE bye-laws and the
UDISE+ registration process.

**A correction first.** Earlier notes in this repo used the nine school records in Firestore
as evidence — malformed affiliation numbers, a misplaced UDISE code. **That is test data, not
the world.** Those observations are withdrawn as evidence about how schools behave. The
validation rules they prompted are still correct on their own merits, but they are justified
below from statute and from a real recognition order instead.

---

## 1 · The chain, on the ground

```
Society / Trust / Sec-8 company registered
        ↓
Land, building, fire & safety approvals
        ↓
RECOGNITION ORDER  ← the licence to run a school and issue its documents
   Mandal/Block Education Officer verifies
   District Education Officer recommends
   Regional Joint Director / competent authority accords
        ↓
UDISE+ registration  ← BEO enters it after PHYSICAL VERIFICATION → 11-digit code
        ↓
Board affiliation (CBSE / CISCE / state board)  ← licence to present students for BOARD EXAMS
```

**RTE s.18** is the statutory hook: no school other than one established or controlled by
government *"shall … be established or function, without obtaining a certificate of
recognition"*, and recognition requires the s.19 norms.

### The distinction that decides the whole design

| | grants | granted by |
|---|---|---|
| **Recognition** | the right to **run a school and issue its documents** | the **state** education department |
| **Affiliation** | the right to **present students for that board's examinations** | CBSE / CISCE / state board |

**Authority to issue a Transfer Certificate flows from RECOGNITION, not affiliation.** A
recognised state-board school with no CBSE affiliation issues entirely valid certificates.
CBSE's own instruction is to accept a TC *"signed and sealed by the principal of any
**recognized** school"*.

This is the correction that matters most: the model built so far treats the affiliation
number as the anchor and UDISE as an optional extra. **It is the other way round.**

---

## 2 · What a recognition order actually is

From a real order — Regional Joint Director of School Education, Hyderabad, Proc. No.
`RR-SLGP-022-0034`, dated 28-05-2022, for Delhi Public School Hyderabad:

| property | what the order shows | consequence for us |
|---|---|---|
| **Identity** | a **Proceedings Number** and a date | the recognition's own identifier — not the affiliation number |
| **Granted to** | *"the Vidyananda Educational Society"* | recognition attaches to the **management**, not only the school |
| **Scope** | *"classes VIII (E.M) **with one section**"* | recognition is **per class range and per section**, never blanket |
| **Validity** | *"for a period of ten (10) years from 2020-21 to 2029-30"* | a start and end **academic year**, not a calendar date |
| **Premises** | *"to run the school in the locality and there shall not be any change in the premises"* | **premises-bound**; a move invalidates it |
| **Nature** | *"accorded purely on a temporary basis … liable to be withdrawn at any time"* | never permanent, revocable without notice |
| **Renewal** | *"submit Renewal of Recognition proposals **before 90 days** of the expiry"* | there is a **statutory warning window** |
| **Chain** | MEO verified → DEO recommended → RJD accorded | three officers, each named |

Validity periods vary by state — commonly 3–5 years, ten in this order, and Andhra Pradesh
amended its rule from three years to eight. **So the period must be read off the order, never
assumed.**

---

## 3 · The register is the authority

A TC is not a document a school composes. It **transcribes an entry in the Scholar Register /
Admission & Withdrawal Register**, and that is exactly what a countersignature checks:

> the school sends the TC and **Scholar Register details** to the DEO or BEO office, and the
> document is countersigned **upon matching the entries in the register** with those on the TC.

So the register is the source of truth and the certificate is evidence of it. Any issuance
design that lets a certificate be produced without a register entry has the relationship
backwards.

### A staleness flag for the compliance corpus

Reporting indicates **CBSE has abolished the requirement of countersignature by Board Regional
Officers on Transfer Certificates**, instructing affiliated schools to accept a TC signed and
sealed by the principal of any recognised school.

Our corpus records `r.8(vii)` — *"A TC originating outside CBSE additionally needs a
countersignature"* — as current, at evidence Level A, `verifiedOn: 2026-08-16`.

**These may conflict.** This must be checked against the current bye-laws and any superseding
circular before we enforce a countersignature rule. Per the corpus's own discipline, an
authority whose text has moved is exactly what `verifiedOn` exists to catch — and enforcing a
withdrawn requirement is the same error as inventing one.

---

## 4 · What this changes in the model

| built so far | should be |
|---|---|
| `affiliationBoard` + `affiliationNo` are the anchor | **recognition** is the anchor; affiliation is additional and governs exams |
| `recognitionOrder {number, date, authority}` | add **validity from/to (academic years)**, **classes and sections covered**, **granting society**, **premises**, **status** |
| UDISE optional | UDISE is the **strongest machine-checkable identifier**, and is downstream of recognition |
| verification expires on **our** review interval | eligibility must expire on the **recognition's own validity**, with the **90-day renewal warning** surfaced |
| one board per school | recognition is per **class range**; a school may be recognised for some classes and not others |

### Level 2 evidence should be the recognition order

The instrument that proves a school may issue is the **recognition order**, not the
affiliation letter. The affiliation letter proves something real but different. Both should be
storable; the recognition order is the one that gates.

---

## 5 · Plan

**Phase 0 — stop printing something false.** `tc_print.php` reads snake_case keys off a
camelCase document and prints a hardcoded *"Affiliated to C.B.S.E, New Delhi"*. Independent of
everything below.

**Phase 1 — model recognition properly.** Order number, date, granting authority, granting
society, validity from/to as academic years, classes and sections covered, premises, status
(active / expired / withdrawn). Store the order as evidence.

**Phase 2 — make eligibility expire.** Derived from the recognition's validity, not our review
timer. Surface the **90-day renewal window** the order itself demands, and treat *withdrawn*
as immediate.

**Phase 3 — anchor on UDISE.** Promote it to the primary identifier; validate the state prefix
against the school's declared state; verify against the public *Know Your School* directory,
which needs no login.

**Phase 4 — bind issuance to the register.** A certificate may only be issued against a
register entry, because that is what a countersignature verifies.

**Phase 5 — re-verify the countersignature rule** before enforcing `r.8(vii)`.

**Phase 6 — then the national rails** (DigiLocker / NAD), per
`DIGILOCKER_COMPLIANCE_PLAN.md`. Note this ordering is not optional: **DigiLocker issuer
onboarding itself requires the recognition certificate and UDISE code**, so Phases 1–3 are its
prerequisites rather than a parallel track.

---

## 6 · What is still unverified

- **Per-state recognition rules.** Recognition is a state subject and the AP order above is
  one state's practice. Kerala and Tamil Nadu field lists were never retrieved; Maharashtra,
  Karnataka and Uttar Pradesh have no verified authority in the corpus at all.
- **The countersignature position**, per §3.
- **Whether a UDISE code can be verified programmatically**, or only by a human reading the
  public directory.
- **Recognition status is not published** anywhere machine-readable that this research found.
  A school can show us an order; nothing lets us confirm it has not since been withdrawn.
  That is a real limit on what level 3 can honestly claim.


---

## 7 · Corrections from the regional research (2026-09-08)

Four premises in the sections above were tested against primary text by the South India stream
and came back altered. They are corrected here rather than silently edited, because two of them
were repeated in a published artifact.

- **Kerala 2025:KER:69076 did NOT gut KER Ch.VI r.17(2).** The judgment never mentions that
  rule. It was a **CBSE** case where KER did not apply. **r.17(2) stands unamended.**
- **Tamil Nadu never required TC countersignature.** TNER prescribes none. The rule in that
  pair is **Puducherry's** (1996 Rules r.62 proviso), and only for a TC from outside the UT.
- **TNER r.40 exists in two circulating, opposite texts.** Unresolved. Either way rr.41–43
  permit only one term's special fee, never accumulated arrears.
- **The Madras HC date 22.07.2024 was the reporting date.** The judgment is
  **W.A. 3075/2021, pronounced 19.07.2024**, and does not cite TNER rr.40–42 by number.
- **Per-section recognition does not generalise.** Confirmed in only Telangana and Kerala;
  Karnataka, TN, Puducherry and AP are searched negatives. **Per class-range does generalise** —
  four streams agree.

The no-dues position is therefore more precise than "the courts gutted it": **the rules stand
and are unenforceable.** Four judgments prohibit withholding and preserve recovery, but no
enabling rule was repealed — so a school may cite a rule that genuinely still exists in its own
state's code, and our answer must account for that rather than deny it.
