# A3 · MOBILE-SPEC — Document Engine (Certificates) mobile-absence trace

**Evidence ceiling:** E2 (static source read only — neither app was built or run).
**Repos traced:** `ZenXII_Teacher` (`com.schoolsync.teacher`), `ZenXII_Parent` (`com.schoolsync.parent`).
Stale forks (`Grader_Teacher`, `Grader_T`, `Grader_t2`, `Grader_Teacherv3`, `Grader_S`,
`Grader_school`, `SchoolSyncTeacher`, `SchoolSyncParent`, `ZenXII-Parent`) were **not** searched —
out of scope per mandate.

## 1. Search methodology (so the negative is reproducible)

Searched with `grep -rniE` (case-insensitive, extended regex) over `app/src/**/*.kt` in both repos
(main + test sources; excludes `app/build/`, `.gradle/`, `.git/`). Ran per-app, then split into
individual term passes where the combined pass needed disambiguation.

Terms, run against both `ZenXII_Teacher/app/src` and `ZenXII_Parent/app/src`:

```
documentTemplates | documentTemplateVersions | reusableBlocks | templateSessions
complianceAuthorities | doc_templates | DocTemplate | Certificate | certificate
transfer_certificate | bonafide | Annexure | fee_receipt
```
plus separate word-boundary passes for `TC` (`grep -rn -w "TC"`, to avoid matching inside longer
identifiers) and `receipt` (broad, to catch anything fee/document-adjacent).

Also searched, for the legacy-RTDB check (§2): `Certificates`, `Templates`, `Issued`, `Counters`
against both `Constants.kt` files and both `app/src` trees, and manually read both apps'
`object Firebase { … }` RTDB path blocks in full.

## 2. Per-app findings — Document Engine terms

**Zero hits, either app**, for: `documentTemplates`, `documentTemplateVersions`, `reusableBlocks`,
`templateSessions`, `complianceAuthorities`, `doc_templates`, `DocTemplate`, `bonafide`, `Annexure`,
`transfer_certificate`/`transferCertificate`.

`Certificate`/`certificate` (case-insensitive) — 3 hits total, all in `ZenXII_Parent`, all Support
Desk ticket-category strings, unrelated to the Document Engine:

- `ZenXII_Parent/app/src/main/java/com/schoolsync/parent/ui/support/SupportViewModel.kt:151` —
  `"certificates" -> R.string.supcat_certificates` (a `when` branch mapping a support-ticket
  category key to a localized label).
- `ZenXII_Parent/app/src/main/java/com/schoolsync/parent/data/repository/firestore/SupportFirestoreRepository.kt:80` —
  `"certificates", "health", "app", "conduct", "other"` — inside the fixed `CATEGORIES` list
  (line 78: `val CATEGORIES = listOf(...)`) a parent picks when raising a support ticket.
- `SupportFirestoreRepository.kt:552` — `"certificates" -> "Certificates & Documents"`, the
  un-localized label written into the ticket's `subject` field for the school's triage queue.

This is the parent-facing Support Desk module (`supportTickets`) offering "Certificates &
Documents" as one of ten fixed ticket categories — a parent *asks staff about* a certificate by
filing a ticket. It is not a certificate viewer, generator, or any Document Engine client. No
`Certificate`/`certificate` hit exists anywhere in `ZenXII_Teacher`.

`TC` (word-boundary) — 6 hits, all comments/doc-strings, no code reference to a TC feature:

- `ZenXII_Teacher/app/src/main/java/com/schoolsync/teacher/data/repository/StudentRepository.kt:37`
  and `StudentFirestoreRepository.kt:51` — comments noting student status values excluded from a
  roster (`TC, Withdrawn, …`), i.e. the *student status enum*, not a document.
- `ZenXII_Parent/.../ui/fees/FeeBlockedBanner.kt:34,36,46` — doc-comment on a fee-dues warning
  banner: *"Reused across Dashboard + Results + (future) TC screen"* and example `scope` text
  `"TC cannot be issued"`. This is a **named future intent, not an existing surface** — no `TC`
  screen, route, ViewModel, or Firestore read exists anywhere in the Parent app; the banner itself
  only fires from Dashboard/Results today. Worth flagging to QA-LEAD as evidence the product team
  has at least sketched a TC surface, but it is unbuilt.
- `ZenXII_Parent/.../data/repository/AuthRepository.kt:104` — comment on account-status filtering
  (`status='Active'` vs `TC issued, withdrawn, …`), same student-status-enum usage as above.

`fee_receipt`/`receipt` — many hits, all belong to the **Fees module** (`feeReceipts` collection,
`FeeFirestoreRepository`, `ReceiptDetailScreen/ViewModel`, `ReceiptPdfGenerator`) — a real,
pre-existing, unrelated feature. Detailed under §3 as the integration-seam exemplar.

**Verdict: no Document Engine terminology reference in either app.**

## 3. Legacy RTDB certificate surface — not present

Searched for the legacy panel path shape `Schools/{school}/{session}/Certificates/{Templates|Issued|Counters}`
by grepping `Certificates`, `Templates`, `Issued`, `Counters` and by reading both apps' RTDB path
tables in full:

- `ZenXII_Teacher/app/src/main/java/com/schoolsync/teacher/util/Constants.kt:9-53` — the entire
  `object Firebase { … }` RTDB path block (root nodes, Students, Attendance, Timetable, Exams,
  Communication, Social, HR, Fees, Homework, Gallery). No `Certificates` node anywhere in the block.
- `ZenXII_Parent/app/src/main/java/com/schoolsync/parent/util/Constants.kt:15-…` — same shape,
  builder-function style (`schoolCodePath`, `studentProfilePath`, `attendancePath`, …). No
  certificate path builder exists.
- Every `Issued`/`Counters` hit found by the broad grep (both apps) belongs to the **Library**
  module (`LibraryIssueDoc`, `status = "issued"`, book issuing/return — e.g.
  `ZenXII_Teacher/.../data/repository/firestore/LibraryFirestoreRepository.kt:76-136`,
  `ZenXII_Parent/.../ui/library/LibraryViewModel.kt:78-90`) — unrelated homonym, not a
  Certificates counter.
- No `FEE_RECEIPTS`-style RTDB constant, no Storage path, no download/view/share UI referencing a
  certificate exists in either app.

**Verdict: no legacy or new certificate viewing surface in either app — the "parent already sees
certificates from RTDB" risk does not materialize.**

## 4. Integration-seam inventory (what a future print point would plug into)

Evidence for what already exists app-side, for the team to match against if/when a certificate
surface is built:

**Firestore access.** Both apps go through a generic, collection-name-string-based service —
nothing in it whitelists or blocks collection names, so adding a Document Engine repository is a
config-only addition, not a plumbing change:
- `ZenXII_Teacher/app/src/main/java/com/schoolsync/teacher/data/firebase/FirestoreService.kt:29-234`
  — `getDocument`, `getDocumentAs<T>`, `queryDocuments`, `queryDocumentsAs<T>`, `observeDocument`,
  `observeQuery`, `setDocument`, `updateDocument`, `deleteDocument`, all taking a raw
  `collection: String`.

**PDF generation/handling — existing precedent to imitate.** The Parent app already renders and
shares a PDF client-side from a Firestore document, with no external library:
- `ZenXII_Parent/app/src/main/java/com/schoolsync/parent/util/ReceiptPdfGenerator.kt` (431 lines)
  — renders a `FeeReceiptDoc` (from the `feeReceipts` collection) to an A4-ish PDF using
  `android.graphics.pdf.PdfDocument` (native, no deps; see file header comment lines 1-14: the PDF
  path is **deliberately not localized** — English/Latin-digit only, "forwarded to employers,
  shown to auditors" — a policy note directly relevant to certificate documents), then shares it
  via `androidx.core.content.FileProvider` + `Intent` (`sharePdf`, imports at lines 17-28).
  Consuming screens: `ZenXII_Parent/.../ui/fees/ReceiptDetailScreen.kt`,
  `ReceiptDetailViewModel.kt`.
- Homework attachments are the other precedent, for arbitrary-file (not generated-PDF) download/
  view: `AttachmentUrlValidator.kt` in both apps (Teacher:
  `ZenXII_Teacher/app/src/main/java/com/schoolsync/teacher/util/AttachmentUrlValidator.kt:1-40`) —
  validates a Storage download URL (scheme must be `https`, host must be exactly
  `firebasestorage.googleapis.com`) before dispatching `Intent.ACTION_VIEW`, guarding against a
  hostile URL smuggled through a Firestore round-trip. A certificate "download proof PDF" surface
  would need the same allowlist-before-open discipline.

**RBAC/capability gating.** `ZenXII_Teacher/app/src/main/java/com/schoolsync/teacher/util/ModuleGate.kt`
maps nav routes to RBAC module names (`ROUTE_MODULE`, lines 43-60) and fails **open** when
capabilities haven't loaded (`Capabilities.UNKNOWN`, lines 8-20; `canAccess`, lines 83-90). No
`"certificate"`/`"document"` entry exists in `ROUTE_MODULE` (confirmed absent by grep — 0 hits) —
consistent with there being no surface to gate yet. A future certificate route would need a new
`ROUTE_MODULE` entry and a matching RBAC module name in the panel's `schools.staffRoles` catalogue
(the panel side is out of this agent's scope). No equivalent `ModuleGate.kt` exists in
`ZenXII_Parent` — the Parent app doesn't gate by staff RBAC (parents aren't staff), so the
Document Engine's client-facing constraints for Parent would need a different mechanism
(module not searched further — out of this trace's scope).

**Collection-name source of truth.** `Constants.kt` `object Firestore` in each app — see §5.

## 5. Constants drift

`ZenXII_Teacher/app/src/main/java/com/schoolsync/teacher/util/Constants.kt:56-155` — the full
`object Firestore { … }` block, 12 phases, ~65 named collection constants (SCHOOLS, STAFF,
STUDENTS, … through Phase 12 Analytics). **None** of `documentTemplates`,
`documentTemplateVersions`, `reusableBlocks`, `templateSessions`, `complianceAuthorities`, or any
certificate-shaped name is declared.

`ZenXII_Parent/app/src/main/java/com/schoolsync/parent/util/Constants.kt:207-…` — same check, same
result: no Document Engine collection name declared.

**No drift** — this is the trivial/clean case: the panel and apps agree by mutual absence. Neither
side has staked a name the other side would need to match. (Contrast with a drift finding, which
would require the apps to declare a certificate-related name the panel doesn't use, or vice versa
— neither condition holds.)

## 6. Counts

- Document Engine term hits across both apps: **0** (of 13 search terms × 2 apps = 26 term/app
  combinations, plus 2 additional word-boundary passes for `TC` and `receipt`)
- `Certificate`/`certificate` hits: **3**, all in `ZenXII_Parent`, all Support Desk ticket-category
  strings (§2) — 0 in `ZenXII_Teacher`
- `TC` word-boundary hits: **6**, all comments (student-status enum ×4, one future-intent doc-comment
  cluster ×3 lines in one file) — 0 code references to a TC feature
- Legacy RTDB `Certificates/Templates/Issued/Counters` path hits: **0** (all `Issued`/`Counters`
  hits, both apps, belong to the unrelated Library module)
- Constants.kt collection constants declared by each app: Teacher ~65, Parent (not fully counted,
  same shape) — Document Engine collections among them: **0**, both apps
- Existing PDF-generation precedent found: **1** (`ReceiptPdfGenerator.kt`, Parent app, 431 lines)
- Existing file-open/download validators found: **2** (Teacher and Parent `AttachmentUrlValidator.kt`)
- `ModuleGate.kt` instances: **1** (Teacher only; none in Parent)

## 7. Named gaps / items for QA-LEAD attention

1. **Parent `FeeBlockedBanner.kt` comment names a "(future) TC screen"** that does not exist yet —
   product intent recorded in a doc-comment, zero implementation. Not a defect; a scope note.
2. **Parent Support Desk offers a "Certificates & Documents" ticket category** — the actual current
   parent-facing path for a certificate request is "file a support ticket," not any Document
   Engine surface. If the Document Engine ships a Parent-visible request flow later, this ticket
   category likely needs to route into it (or the two systems will silently duplicate).
3. **No RBAC module scaffolding exists yet in either app** for a certificate surface (`ModuleGate.kt`
   has no entry; Parent has no gating mechanism at all) — noted as a build-time TODO, not a defect.
