# Support Desk — Data Retention Policy

**Status: DRAFT FOR LEGAL REVIEW. This is not policy until signed off.**
Drafted 2026-08-25 alongside Support Desk P0. Nothing here has been reviewed by a lawyer.
Where a duration is borrowed from another jurisdiction's convention, that is stated explicitly
rather than presented as an Indian requirement.

---

## 1. Why this document exists

The Support Desk stores, by design, free-text complaints about children. Under the Digital
Personal Data Protection Act 2023 a "child" is anyone under eighteen, so **every ticket
concerns a child's personal data, always** — there is no low-sensitivity subset.

Three requirements in the design pull against each other, and until now nothing reconciled them:

| Source | Requires |
|---|---|
| DPDP §8(7) | Erase personal data on consent withdrawal, **or once the specified purpose is no longer served — whichever is earlier** |
| Build Book E-12 | Never cascade-delete a ticket; it is the audit trail for a dispute |
| Build Book §06 | The confidential lane must be immutable — POSH and POCSO evidence cannot be destroyed |

Without a written position, the default behaviour is "keep everything forever across every
tenant", which is both an unbounded cost line and an unexamined DPDP posture.

**The reconciliation this policy proposes:** "purpose no longer served" is the operative test.
A live dispute, and anything carrying a statutory retention or evidentiary duty, still serves
its purpose. Once it does not, the record is **redacted, not deleted** — see §4.

---

## 2. Proposed retention, per lane

Durations run from **closure**, not creation.

| Lane | Proposed retention | Reasoning | Confidence |
|---|---|---|---|
| `normal` — fees, transport, academics, attendance, exams, certificates, app/login | **3 years**, then redact | Fee and academic disputes resurface across sessions; three years spans a typical multi-session grievance without holding free text indefinitely | Proposal only — no statutory basis identified |
| `general` — confidential non-statutory grievance | **7 years**, then redact | Longer tail: employment and conduct matters can be litigated well after the event | Proposal only |
| `posh` — POSH Act complaints | **Indefinite pending legal advice; not less than 7 years** | POSH carries annual reporting obligations (§21/22) and an appeal window (§18, 90 days), and civil claims are long-tail. Deleting a POSH record early is the failure mode with real consequences | **Needs legal direction** |
| `safeguarding` — POCSO / child protection | **Indefinite pending legal advice** | POCSO §21(2) exposure for institutional heads persists; a record that no longer exists cannot demonstrate that a report was made | **Needs legal direction** |

**On the safeguarding duration.** UK practice commonly retains safeguarding records until the
subject's 25th birthday or for decades beyond. I could not identify an equivalent published
Indian standard, and this session could not reach `cbse.gov.in` or NCPCR to check. **Do not
adopt the UK convention as though it were an Indian requirement** — it is offered only as
evidence that "indefinite" is not an unusual answer in comparable regimes.

---

## 3. What is retained after redaction

Redaction keeps the ticket as a countable, auditable event and removes the person from it.

**Retained permanently (non-personal, statistically useful):**
`ticketNo` · `lane` · `category` · `ticketType` · `status` · `closureReason` ·
`createdAt` · `resolvedAt` · `closedAt` · `messageCount` · `firstStaffReplyAt` ·
`assignedTo` (staff id — employment record, not the complainant's data)

This preserves every §14 dashboard metric — volume by category, median first response,
median resolution, reopen rate — without retaining a single word a parent wrote.

---

## 4. What "erasure" means here

A parent's erasure request cannot be honoured by deletion: `allow delete: if false` is correct,
and E-12's never-cascade rule is correct. Both conflict with a deletion request, and the
conflict is resolved the same way DPDP resolves it — **the purpose test**.

**Erasure = field-level redaction, record retained.** Personal fields are overwritten with a
tombstone (`[redacted 2029-08-25]`), never nulled, so the redaction is itself visible and
auditable.

**A redaction request on a `posh` or `safeguarding` ticket must be refused** while a statutory
duty subsists, and the refusal recorded with its reason. This is the point most likely to be
challenged, and the one most needing a lawyer's wording.

---

## 5. Personal-data field register

**This is the expensive part to reconstruct later, which is why it is written now, before P0
ships the schema.** Every field below is personal data and in scope for redaction.

### `supportTickets`
| Field | Kind | On redaction |
|---|---|---|
| `reporterId` | Identifier (parent) | Tombstone |
| `reporterName` | Name | Tombstone |
| `studentId` | Identifier (child) | Tombstone |
| `studentName` | Name (child) | Tombstone |
| `className` | Quasi-identifier | Tombstone |
| `subject` | Free text, may name people | Tombstone |
| `assignedName` | Name (staff) | Retain — employment record |
| `attachments[]` | Images; may show faces, documents | Delete objects from Storage, clear array |
| `keywords[]` | Derived from name + subject | Rebuild from retained fields only |
| `locale` | Weak quasi-identifier | Retain |

### `supportMessages`
| Field | Kind | On redaction |
|---|---|---|
| `senderId` · `senderName` | Identifier / name | Tombstone if parent; retain if staff |
| `reporterId` | Identifier (denormalised for the C-05 read rule) | Tombstone |
| `body` | **Highest sensitivity.** Free text about a child | Tombstone |
| `attachments[]` | Media | Delete objects, clear array |

### `supportNotes` — staff-internal
| Field | Kind | On redaction |
|---|---|---|
| `authorId` · `authorName` | Staff identity | Retain — employment record |
| `body` | Free text about a parent or child | Tombstone |

### `supportReporterIdentity` — anonymous-lane identity
Entirely personal data. Deleted outright at end of retention, not tombstoned — the collection
exists solely to hold an identity, so a tombstone would preserve nothing of value.

---

## 6. Residency position

Firestore is in **`nam5` (United States)** and **cannot be moved** — location is immutable per
database, so the only remedy is a new database and a migration.

DPDP §16 operates as a **negative list**: the Central Government "may restrict the transfer of
personal data by a Data Fiduciary for processing to such country or territory outside India as
may be so notified." Transfer abroad is therefore permitted **unless** the destination is
notified. **No notification restricting the United States was identified**, so the current
arrangement appears lawful — but §16(2) preserves any sector-specific law imposing a higher
restriction, and a future notification would be an architectural event, not a configuration change.

**Recommended:** treat a §16 notification naming the US as a standing risk with a named owner,
and do not add further collections holding children's free text without revisiting it.

---

## 7. Controller / processor position

Schools determine the purpose and means of processing; ZenXii processes on their behalf. On the
DPDP §2 definitions that makes each **school the Data Fiduciary** and **ZenXii the Data
Processor**.

DPDP §8(2) permits a Fiduciary to engage a Processor **"only under a valid contract."**
A data-processing agreement with each school is therefore a precondition, and it is a commercial
artefact this policy cannot supply. **Flagged as an open commercial gap.**

---

## 8. Open questions for legal

1. Are the §2 durations defensible, and what should `posh` and `safeguarding` actually be?
2. Is redaction-with-retention an acceptable response to a DPDP erasure request, or must some
   categories be deleted outright?
3. On what basis may we refuse redaction where a statutory duty subsists, and how should that
   refusal be worded to the parent?
4. Does §9(1) verifiable parental consent require anything beyond consent captured at compose
   time by the parent themselves?
5. Is a per-school DPA in place or drafted? Without one, §8(2) is not satisfied today.
6. Does any Indian instrument prescribe a minimum retention for school grievance or child
   protection records? This session could not reach `cbse.gov.in` or NCPCR to check.

---

*Draft — Support Desk P0, 2026-08-25, branch `yug_testing`. UNCOMMITTED. Not reviewed by counsel.
Statutory text underlying this draft was read from Indian Kanoon, not the official Gazette,
because `indiacode.nic.in` and `legislative.gov.in` block automated access.*
