# 04 — Cross-Implementation Parity Matrix

**Agent:** A7 · DIFF-ANALYST
**Module:** AI Assistant (`studentAssistant` Cloud Function)
**Date:** 2026-08-31
**Evidence ceiling:** **E2** — static source trace only. Every claim below is read off checked-out
source at the cited `path:line`. Nothing here was executed, no live Firestore document was read, and
no deployed index or ruleset was queried. Anything that would require runtime confirmation is marked
`[UNVERIFIED-AT-E2]`.

**Independence:** I read `01-backend-spec.md` and `01-data-spec.md` only after completing my own
trace. No verdict below rests on another agent's conclusion; every cell was re-derived from source.

---

## 0. Why this matrix exists

The AI Assistant lives on one surface, so conventional surface parity is thin (§7). The valuable
differential is different: **the Cloud Function's six tools re-implement read paths the Parent app
already implements, against collections the admin panel writes.** That is two to four independent
implementations of one rule per domain — a free oracle. Where they disagree, at least one is wrong,
and the writer usually decides it.

Implementations compared:

| # | Implementation | Root |
|---|---|---|
| **A** | Parent Android repositories | `/Users/yuggi/AndroidStudioProjects/ZenXII_Parent/app/src/main/java/com/schoolsync/parent/data/repository/firestore/` |
| **B** | Cloud Function tools | `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/studentAssistant.js` (`TOOL_IMPL`, lines 289–426) |
| **C** | Admin panel (writer + reader) | `/Users/yuggi/Desktop/Zennxii_adminPanel/application/` |
| **D** | iOS `ZenXiiCore` (partial — attendance key only) | `/Users/yuggi/AndroidStudioProjects/zenxii-ios/ZenXiiCore/` |

**C is authoritative wherever it is the writer.** A field name or doc-id shape is not a matter of
opinion: whatever `Exam_result_store::buildResultDoc` emits *is* the schema, and a reader that reads
a different key reads `null`.

---

## 1. The matrix

### 1.1 Attendance

| | App repository | CF tool | Panel |
|---|---|---|---|
| **Path:lines** | `AttendanceFirestoreRepository.kt:42-68`, key conversion `:70-87` | `studentAssistant.js:290-304`, default month `:428-433` | writer `Attendance.php:1305-1332`; id helper `Firestore_service.php:186-189`; month key `Attendance.php:912, 1473, 1935, 3352` |
| **Doc id / query** | `{schoolCode}_{studentId}_{YYYY-MM}` — `:49-50` | `{schoolId}_{studentId}_{"August 2026"}` — `:292-293` | `docId2($studentId,$monthKey)` = `{schoolId}_{studentId}_{YYYY-MM}` — `Attendance.php:1305` |
| **Fields read** | full `AttendanceSummaryDoc` — `:53-56` | `percentage`, `dayWise` — `:300-301` | writes `month`, `monthLabel`, `session`, `dayWise`, `percentage`, `totalDays`, … — `Attendance.php:1321-1333` |
| **Filters** | none (doc-id addressed); `getAttendanceSummary` deliberately drops `session` — `:90-96, 107-108` | none (doc-id addressed) — `:292-294` | doc-id addressed — `Attendance.php:916` |
| **Verdict** | — | **DIVERGENT — CF is wrong** | authoritative |

**D1 · Attendance month key — DIVERGENT. `get_attendance_summary` is 100 % dead.**
*Priority class: data behaviour (effect: total feature failure).*

The panel builds the document id with an ISO month:

```php
$monthKey = sprintf('%04d-%02d', $year, $monthNum);   // Attendance.php:912
$summaryDocId = $this->fs->docId2($studentId, $monthKey);  // :1305
```

`docId2` is `"{$this->schoolId}_{$part1}_{$part2}"` (`Firestore_service.php:186-189`), so the real
key is `attendanceSummary/SCH_D94FE8F7AD_STU0001_2026-05`. That exact string appears in the panel's
own error log (`application/logs/log-2026-05-15.php:348`) — a production artifact, not an inference.

The app converts a label to that form before addressing the doc (`AttendanceFirestoreRepository.kt:49`,
`monthLabelToKey` `:70-87`). iOS canonicalises identically and asserts it (`AttendanceMonthKeyTests.swift:12-18`,
`"2026-04"`). Three implementations agree.

The CF concatenates the label **raw**, and its own default produces the label form:

```js
const monthLabel = String(input.month || '').trim() || currentMonthLabel();
.doc(`${C.ATTENDANCE_SUMMARY}/${ctx.schoolId}_${ctx.studentId}_${monthLabel}`)
```
`studentAssistant.js:291-293`; `currentMonthLabel()` returns `"August 2026"` (`:428-433`), and the
tool schema instructs the model to send `"Month YYYY"` (`:229`).

⇒ The address is `attendanceSummary/SCH_..._STU0001_August 2026`. **No such document is ever
written.** `snap.exists` is false on every call, the tool returns `{found:false}` (`:295`), and the
prompt then obliges the model to say there is nothing recorded (`:458`). The assistant will tell
every student, every time, that no attendance exists — confidently, and without any error a log
would catch.

The doc *does* carry a `monthLabel` field (`Attendance.php:1322`), which is almost certainly where
this mistake came from — but it is a field, not the id.

**Correct implementation: the panel (C), corroborated by A and D. Fix: `2026-08`.**

**D1b · "current month" is computed in the wrong timezone.** *Priority: data behaviour.*
`currentMonthLabel()` uses the runtime clock (`new Date()`, `:429`). The function runs in
`us-central1` (`:479`), i.e. UTC. Between 00:00 and 05:30 IST on the 1st of a month, UTC is still in
the previous month, so an unqualified "what's my attendance" resolves to the wrong month. iOS treats
this as a named bug class and tests against it — *"the current month depends on the school's zone not
the device's"* (`AttendanceMonthKeyTests.swift:57-60`). The month must come from the school's zone.
This survives the D1 fix and must be fixed with it.

---

### 1.2 Homework

| | App repository | CF tool | Panel |
|---|---|---|---|
| **Path:lines** | `HomeworkFirestoreRepository.kt:64-135` (+ live `:322-411`) | `studentAssistant.js:306-328` | writer `Homework.php:929-946`; key `:737`; reader `:657` |
| **Query** | `schoolId` + `sectionKey` + `status=="active"` + `session`, `orderBy createdAt DESC`, `limit 200` — `:86-91`, cap `:57` | `schoolId` + `sectionKey` + `status=="active"` + `session`, `orderBy createdAt DESC`, `limit 25` — `:310-315` | `session` + `sectionKey` equality — `Homework.php:657, 744` |
| **sectionKey** | `"${classKey(cls)}/${sectionKey(sec)}"` — `:80` | `compositeSectionKey(cls, sec)` — `:308`, `:111-113` | `"{$cls}/{$secFull}"` — `Homework.php:737, 934` |
| **Fields read** | full `HomeworkDoc` | `title`, `subject`, `description`, `dueDate` — `:320-324` | writes `title, description, subject, dueDate, teacherId, status, submissionCount, …` — `:935-946` |
| **Session fail-closed** | yes — `:77-78` | yes, at `loadContext` — `:162-167` | n/a (server session) |
| **Verdict** | — | **IDENTICAL on query shape; DIVERGENT on limit + index fallback** | authoritative |

**D6 · `sectionKey` construction — IDENTICAL in effect across all three.** *Answers question 5.*

All three produce `"Class 10th/Section A"`:
- Panel: `normalizeClassSection` emits `className = "Class " . ordinalSuffix(n)` and
  `section = "Section {$token}"` (`Entity_firestore_sync.php:164-209`), joined at `Homework.php:737`.
- App: `classKey`/`sectionKey` prefix-if-missing, case-insensitive (`Constants.kt:198-203`), joined
  at `HomeworkFirestoreRepository.kt:80` and `:323`.
- CF: same prefix-if-missing via `/^class /i` and `/^section /i` (`studentAssistant.js:111-113`).

The inputs are already normalised — the panel stores `students.className = "Class 10th"` and
`students.section = "Section A"` (`Entity_firestore_sync.php:312-326`) — so all three guards are
pass-throughs. **No divergence.**

Two residual notes, both worth a UAT row, neither a divergence today:

- **CF-only legacy fallback.** `String(student.className || student.class || '')`
  (`studentAssistant.js:180`). A legacy doc carrying only `class: "10"` yields `"Class 10"`, which
  will never equal the canonical `"Class 10th"` — a silent empty homework/timetable result. The app
  has no such fallback (it decodes a typed `StudentDoc`). Low severity, legacy-only.
- **All three re-derive a value that is stored.** The student doc already carries a canonical
  `sectionKey` field (`Entity_firestore_sync.php:323-326`). Nobody reads it. Three re-derivations of
  one stored string is a standing drift risk; both readers should prefer the field.

**D7 · Index-failure handling — DIVERGENT (error handling).** *Priority: error handling.*
The app carries two FAILED_PRECONDITION fallbacks for exactly this query, dropping the session filter
server-side and re-applying it client-side (`HomeworkFirestoreRepository.kt:94-131` and `:367-408`).
The CF has none; a missing index becomes the generic `"That lookup failed."` (`studentAssistant.js:596-602`).
The required index **is declared** — `homework [schoolId, sectionKey, status, session, createdAt DESC]`
in `firebase-rules/firestore.indexes.json` — but declared ≠ deployed `[UNVERIFIED-AT-E2]`.

**Which is correct: the CF.** The app's fallback deliberately widens a query and re-filters on the
client, which is the pattern CLAUDE.md names as this codebase's most repeated bug; failing closed is
right. The CF's *message* is the defect, not its behaviour — it tells the student nothing actionable.

---

### 1.3 Fees

| | App repository | CF tool | Panel |
|---|---|---|---|
| **Path:lines** | `FeeFirestoreRepository.kt:76-106`, pending `:112-138` | `studentAssistant.js:330-350` | writer `Fees.php:4782-4805` and `:1550-1567` |
| **Query** | `schoolId` + `session` + `studentId` + `status != "archived"` — `:86-97` | `schoolId` + `session` + `studentId`, `limit 25` — `:332-335` | — |
| **Fields read** | full `FeeDemandDoc` | `feeHead\|head`, **`amount`**, `paidAmount\|paid`, `balance`, `dueDate`, `status` — `:341-346` | writes `feeHead`, `grossAmount`, `discountAmount`, **`netAmount`**, `paidAmount`, `balance`, `status`, `dueDate`, `period`, `periodKey`, … — `Fees.php:4788-4802` |
| **Verdict** | authoritative on the archived filter | **DIVERGENT ×2 — CF is wrong on both** | authoritative on field names |

**D3a · `amount` does not exist. Every demand reaches the model with `amount: null`.**
*Priority: data behaviour.*

The canonical demand doc has `grossAmount`, `discountAmount` and `netAmount` (`Fees.php:4795-4797`;
same triple at `:1563-1565`). There is **no `amount` field**. The CF reads:

```js
amount: f.amount ?? null,     // studentAssistant.js:342
```

`balance`, `paidAmount`, `dueDate`, `status` and `feeHead` are all correct, so the tool is not
useless — but the headline "what is this fee" number is null on every row, on the one topic the
system prompt singles out as sensitive: *"Money is sensitive and a parent may be reading over the
student's shoulder"* (`:462`). Should be `netAmount` (with `grossAmount`/`discountAmount` if the
model is to explain a concession).

**D3b · The CF counts archived demands as live dues.** *Priority: business rule.*

The app excludes them, server-side, in all three read paths, with the reason recorded inline —
archived rows are post-promotion audit residue preserved for refund/receipt lineage
(`FeeFirestoreRepository.kt:89-97`, `:125-130`, `:266-270`). The CF applies no status filter at all
(`studentAssistant.js:331-335`). ⇒ **the assistant over-states what a family owes**, in prose, on a
money question. Priority order puts this above D3a: it is a business rule, not a field name.

**Correct implementation: the app (A) for the filter, the panel writer (C) for the field names.**

**D8 · Silent truncation at 25 rows.** *Priority: data behaviour.*
`QUERY_LIMIT = 25` applies to every tool (`studentAssistant.js:85`). The app caps homework at 200
(`HomeworkFirestoreRepository.kt:57`) and caps nothing else. A 12-month session with 3 fee heads is
36 demands: **11 are dropped and the payload says nothing about it.** The model is instructed to
state what is recorded (`:462`) and has no way to know it saw a truncated set, so it will present a
confidently wrong total. The cap itself is right; the omission of a `truncated: true` flag is not.
This is the cheapest high-value fix in the file, and it applies to all five read tools.

---

### 1.4 Timetable

| | App repository | CF tool | Panel |
|---|---|---|---|
| **Path:lines** | `TimetableFirestoreRepository.kt:35-222` | `studentAssistant.js:352-364` | writer `Timetable_service.php:391-422`; readers `:348-349, 521-522, 607-609, 636-638` |
| **Doc id** | n/a (query) | n/a (query) | `{schoolId}_{session}_{safeSectionKey}_{day}` — `:392` |
| **Query** | `schoolId` + `sectionKey` — `:61-66` | `schoolId` + `sectionKey`, `limit 25` — `:355-359` | `schoolId` + `session` (+ `sectionKey` / `day`) — `:636-638` |
| **Session filter** | **yes, client-side after fetch** — `:67` | **NONE** — `:356-358` | **yes, server-side, on every read** — `:348-349, 521-522, 607-609, 636-638` |
| **Fields read** | typed `TimetableDoc.periods`, + teacher-name resolution `:107-116`, + substitute overlay `:145-216` | raw `d.data()` — whole doc, verbatim — `:361` | typed |
| **Verdict** | correct (mechanism differs) | **DIVERGENT — CF is wrong** | authoritative |

**D5 · Timetable has no session filter — the CF violates its own stated contract.**
*Priority: business rule. This is the clearest defect after D1 and needs no judgement call.*

The file's own header states the rule:

```
//  3. EVERY QUERY IS SCOPED TWICE — by schoolId AND by session.
```
`studentAssistant.js:17-19`

Four of the five read tools honour it. `get_timetable` does not (`:355-359`). Timetable documents
carry `session` (`Timetable_service.php:414`) and every panel reader filters on it. After a session
rollover the assistant merges last year's periods with this year's, and because it returns **raw
document data** (`:361`) rather than a typed projection, two documents both saying `day: "Monday"`
arrive with nothing to distinguish them. The model cannot choose correctly and is not told there is a
choice.

There is no index cost to fixing it: `timetables [schoolId, sectionKey, session]` is already declared
in `firestore.indexes.json`. That the cheaper-but-correct query was available and not used points to
an oversight rather than a decision — which is why I am not marking this `[CONTESTED]`.

**Correct implementation: the panel (C).** The app also filters, but *client-side after fetching all
sessions* (`:61-67`), which is weaker than the panel and interacts badly with a limit; the CF should
copy the panel, not the app.

Secondary: the raw `d.data()` passthrough also hands the model `teacherId` and every internal field,
and skips the app's two enrichments — teacher-name resolution (`:107-116`) and the substitute-teacher
overlay for today (`:145-216`). **The assistant will name an absent teacher for a period that has a
substitute.** Priority: data behaviour. `[CONTESTED]` only in scope (is substitute-awareness in scope
for v1?), not in fact.

**D10 · App-internal: timetable is the only repo keyed on `schoolCode`.** `[CONTESTED]`, low.
`TimetableFirestoreRepository.kt:37` uses `user.schoolCode` for the `schoolId` predicate, whereas
Homework/Fee/Exam use `user.schoolId` (`:69`, `:531`, `:135`). `User.schoolCode` is documented as a
different value — *"Firebase school key resolved from Indexes/School_codes"* (`User.kt:26`) — and
`HomeworkFirestoreRepository.kt:327-329` explicitly warns against using it. In practice they appear
to coincide, because `schoolCode` is used as the `schools/{id}` doc id (`AuthRepository.kt:131-134`)
and the panel sets `school_name = school_id` (`MY_Controller.php:297, 1184`). Candidate expectations:
(a) they are the same value and the naming is vestigial — no action; (b) they can diverge for some
tenant and the Parent timetable silently returns empty for that school. Deciding evidence is one live
`users/{uid}` doc `[UNVERIFIED-AT-E2]`. Does not affect the CF, which uses the token claim.

---

### 1.5 Results

| | App repository | CF tool | Panel |
|---|---|---|---|
| **Path:lines** | `ExamFirestoreRepository.kt:114-132` (+ per-exam `:88-108`) | `studentAssistant.js:366-386` | writer `Exam_result_store.php:157-180`; reader `Exam_read.php:417-437`, mapper `:445-475` |
| **Query** | `schoolId` + `session` + `studentId` — `:122-127` | `schoolId` + `session` + `studentId`, `limit 25` — `:367-371` | `schoolId` + `examId` + `studentId` — `Exam_read.php:421-425` |
| **Fields read** | `totalMarks`, `maxMarks`, `percentage`, `grade`, `passFail`, `rank`, `subjects{}`, `absent` — `ResultDoc.kt:22-37` | `examName`, **`subject`**, **`marksObtained`**, `maxMarks`, `grade`, **`published`** — `:377-382` | `totalMarks`, `maxMarks`, `percentage`, `grade`, `passFail`, `rank`, `subjects{}` — `Exam_read.php:458-471` |
| **Verdict** | correct | **DIVERGENT — 3 of 6 fields do not exist** | authoritative |

**D2 · Results field names — DIVERGENT. The CF reads three fields that were never written.**
*Priority: data behaviour. Answers question 2.*

The canonical writer emits exactly this shape (`Exam_result_store.php:157-180`):

```php
'subjects' => $subjects,   // {subject:{total,maxMarks,percentage,grade,passFail,absent}}
'totalMarks' => $totalMarks, 'maxMarks' => $maxMarks, 'percentage' => $percentage,
'grade' => $grade, 'passFail' => $passFail, 'absent' => $absent,
```

plus `schoolId, session, examId, examName, className, section, sectionKey, studentId, studentName,
rollNo, absentSubjects, rank, gradingScale, passingPercent, computedAt, computedBy`.

**There is no `marksObtained`. There is no top-level `subject`. There is no `published`.**

The app's `ResultDoc` mirrors the writer field-for-field (`ResultDoc.kt:11-38`, `SubjectResultDoc`
`:46-53`), down to preserving `percentage` as nullable for absentees. The panel's own reader maps
`totalMarks` / `maxMarks` / `percentage` (`Exam_read.php:463-471`). Two readers agree with the
writer; the CF is the only dissenter.

⇒ Each row reaches the model as `{examName:"Half Yearly", subject:null, marksObtained:null,
maxMarks:500, grade:"A", published:null}`. **It is told the maximum but not the mark.** Best case,
the prompt's "never guess a number" (`:443`) makes it report a published result as unrecorded; worst
case it narrates `maxMarks` as the score. It also drops `subjects{}` entirely, so "what did I get in
Maths" — the most likely question a student asks — is unanswerable even after the top-level fields
are fixed.

**So, to question 2 explicitly: `results` carries `totalMarks` / `maxMarks` / `percentage`, not
`marksObtained`, and it carries no `published` field.** The app reads the former; the CF reads the
latter.

**D2b · `published` is not merely absent — it is architecturally meaningless.**
`results` holds published results *by construction*: compute writes to `resultsStaging` and publish
promotes (`Result.php:1075-1097`; `Exam_read.php:393-399`; `Examination.php:945-969`), and the rules
deny clients `resultsStaging` outright (`firestore.rules:1116-1118`). Presence in `results` **is**
publication. The CF's `published: null` risks the model suppressing a legitimately published result
because a field it invented came back empty. Delete the read; do not add the field.

**Correct implementation: the panel writer (C), corroborated by the app (A).**

---

## 2. Session filtering — the three-way answer (question 4)

| Domain | Panel (C) | Parent app (A) | CF (B) | Verdict |
|---|---|---|---|---|
| attendanceSummary | doc-id addressed; `session` stored as a field — `Attendance.php:916, 1323` | doc-id addressed; `getAttendanceSummary` **deliberately dropped** session — `:90-96, 107-108` | doc-id addressed — `:292-294` | **IDENTICAL / acceptable** — the ISO month in the key is session-disambiguating |
| homework | server-side — `Homework.php:657` | server-side + fail-closed — `:77-78, 89` | server-side + fail-closed — `:162-167, 313` | **IDENTICAL** |
| feeDemands | server-side — `Fees.php:966` | server-side — `:87, 123` | server-side — `:333` | **IDENTICAL** |
| timetables | server-side, every read — `:348-349, 521-522, 607-609, 636-638` | **client-side, post-fetch** — `:61-67` | **absent** — `:356-358` | **DIVERGENT — see D5** |
| results | `session` or `examId` — `Exam_read.php:421-425` | server-side — `:122-127` | server-side — `:367-371` | **IDENTICAL** |

Four of five agree. The CF's session discipline is genuinely good — `loadContext` fails closed on a
blank `currentSession` with an explicit rationale (`studentAssistant.js:162-167`), which is stronger
than the app's attendance path. **Timetable is the single hole, and it contradicts the file's own
header contract.**

Both the app and the CF take `session` from the same origin — the school doc's `currentSession`
(`studentAssistant.js:162`; mirrored into `TokenManager` per `AuthRepository.kt:129-134`) — so there
is no risk of the two disagreeing about *which* session, only about whether to filter.

---

## 3. `[CONTESTED]` — promoted to QA-LEAD as the highest-value UAT rows

### C1 — Multi-child households: the CF and the app disagree about *who the student is*
*Priority class: **authorization** (identity scope) — the highest in the order.*

- The app has an active-child switcher. `switchToSibling` rebuilds the local `User` and persists it
  to DataStore (`DashboardViewModel.kt:259-284`, esp. `tokenManager.saveUserDirect(next)` at `:283`).
  Every repository then reads the new child, because they all key off `tokenManager.user`.
- **It does not re-mint or refresh the ID token.** Nothing in that method touches Firebase Auth.
- The CF takes identity exclusively from `request.auth.token.student_id` (`studentAssistant.js:126`),
  by design (`:11-14`).

⇒ A parent switches to child B, the whole app shows child B, and the assistant answers about child A
— while naming child A in its context line (`:496`), so the model will assert the wrong name. Within
one household this is not an unauthorised disclosure, but it is an identity-resolution rule that two
implementations answer differently, and the failure is silent and confident.

It is sharpened by two further facts:
- The canonical claim builder emits **`student_ids` (plural)** for exactly this case
  (`Firebase.php:855-865`) — and nobody reads it: not the CF, not the app.
- The app's sibling set is not derived from that claim at all; it is a **phone + parent-name
  heuristic** (`StudentFirestoreRepository.kt:125-215`, esp. `:189`).

So there are three notions of "which children may this login see": the token's `student_id`
(CF), the token's `student_ids` (unused), and a client-side heuristic (app).

**Candidate expectations:**
- **(a)** `student_id` is the household's single canonical child and the app's switcher is the
  anomaly. Then the assistant is correct and the *app* needs to re-mint on switch.
- **(b)** Multi-child is real and `student_ids` is the contract. Then the CF must accept a
  child selector validated against `student_ids`, and the system prompt's blanket "never discuss any
  other student" (`:448`) must be narrowed — as written, it makes the assistant refuse a legitimate
  question about the parent's own second child.

**Cannot decide at E2.** Deciding evidence: one live parent account with two enrolled children — what
its claims contain, and whether staff expect the assistant to follow the switcher.

### C2 — Fee-defaulter result withholding: is it a visibility rule or a report rule?
*Priority class: **business rule**. Answers question 3.*

**The panel withholds.** `student_result` blanks the payload outright:

```php
'results' => $resultWithheld ? [] : $results,   // Result.php:347
```
gated on a defaulter node's `result_withheld` (`Result.php:305-323`). A separate policy engine also
exists — `Fee_dues_check::check()` with `ACTIONS = ['result','tc','hall_ticket','library']`
(`Fee_dues_check.php:57, 180-211`), defaulting to `block_result => false` (`:48`).

**The rules do not.** `match /results` is `allow read: if isStaffOrOwnStudent()`
(`firestore.rules:1095-1098`) — no dues predicate anywhere in the block.

**The app does not**, and says so in as many words: *"The actual blocking decision is made
server-side via Fee_dues_check::check(). This banner is the client-side pre-emptive nudge"*
(`FeeBlockedBanner.kt:33-45`; wired at `ResultsScreen.kt:95-105`, `ResultsViewModel.kt:37-42`). The
app shows a warning and then shows the result.

**The CF does not**, and has no dues awareness at all (`studentAssistant.js:366-386`).

**Candidate expectations:**
- **(a)** Withholding is a *panel report-issuance* policy — marksheets, TCs, hall tickets — never a
  data-visibility policy. Then the app and the CF are both right and nothing changes.
- **(b)** Withholding is a *student-visibility* policy that the mobile surfaces silently never
  implemented. Then the assistant is the third channel leaking a withheld result and the most
  quotable of the three, because it renders marks as prose a parent can screenshot.

**Cannot decide at E2.** Deciding evidence: one school with `block_result = true` (or a populated
`result_withheld` defaulter node) plus a statement from whoever owns Fees about whether the Parent
app is *supposed* to hide that result.

**C2b — the flag has three homes.** Independently contested and worth its own row:
`Result.php:307-310` reads an **RTDB** node
`Schools/{school}/{session}/Fees/Defaulters/{userId}.result_withheld`; `Fee_dues_check` reads a
**Firestore policy** doc (`:180-211`); the app models
`feeDefaulters/{schoolId}_{session}_{studentId}.resultWithheld` (`FeeDefaulterDoc.kt:24`). Three
storage locations for one boolean. Whichever way C2 resolves, these must be reconciled first —
otherwise "withheld" means something different on each surface.

### C3 — Substitute-teacher awareness in `get_timetable`
*Priority class: data behaviour. Contested in **scope**, not in fact.*
The app overlays today's substitutes onto the timetable (`TimetableFirestoreRepository.kt:145-216`).
The CF does not (`:352-364`). Fact is settled: the assistant will name an absent teacher.
(a) v1 scope is "the printed timetable" and this is acceptable; (b) a student asking "who takes my
next class" expects today's truth, and a wrong teacher name is a wrong answer. Cheap either way —
the substitute query is a two-field lookup the app already demonstrates.

---

## 4. Divergence summary, in priority order

| # | Divergence | Priority class | Authoritative | Status |
|---|---|---|---|---|
| **C1** | Multi-child identity: token `student_id` vs app active-child | authorization | — | **`[CONTESTED]`** |
| **C2** | Fee-defaulter result withholding: panel withholds, rules/app/CF do not | business rule | — | **`[CONTESTED]`** |
| **C2b** | `result_withheld` stored in three places (RTDB / policy doc / `feeDefaulters`) | business rule | — | **`[CONTESTED]`** |
| **D5** | `get_timetable` has no session filter — violates the file's own line 17-19 contract | business rule | Panel | DIVERGENT — CF wrong |
| **D3b** | CF counts `status == "archived"` demands as live dues | business rule | App | DIVERGENT — CF wrong |
| **D1** | Attendance month key `"August 2026"` vs `2026-08` — tool 100 % dead | data behaviour | Panel (+ app, + iOS) | DIVERGENT — CF wrong |
| **D2** | `results`: CF reads `marksObtained` / `subject` / `published`, none of which exist | data behaviour | Panel writer (+ app) | DIVERGENT — CF wrong |
| **D2b** | `published` is meaningless — `results` is published-only by construction | data behaviour | Panel | DIVERGENT — CF wrong |
| **D3a** | `feeDemands.amount` does not exist (`netAmount`); every row is `amount: null` | data behaviour | Panel writer | DIVERGENT — CF wrong |
| **D8** | Silent 25-row truncation with no `truncated` flag, on all five tools | data behaviour | — | DIVERGENT — CF incomplete |
| **D1b** | "Current month" computed in the function's UTC clock, not the school's zone | data behaviour | iOS | DIVERGENT — CF wrong |
| **C3** | Substitute teachers not overlaid | data behaviour | App | **`[CONTESTED]` (scope)** |
| **D7** | No index-failure fallback; opaque error text | error handling | **CF** (behaviour) | DIVERGENT — CF's *message* is the defect |
| **D6-note** | CF-only `student.class` legacy fallback yields `"Class 10"` ≠ `"Class 10th"` | data behaviour | App | latent, legacy-only |
| **D10** | App-internal: timetable repo alone keys on `schoolCode` | data behaviour | — | **`[CONTESTED]`, low; does not affect the CF** |

**Four of the six tools return materially wrong or empty data as written.** `get_attendance_summary`
returns nothing at all; `get_exam_results` returns a mark-less shell; `get_fee_status` returns a
null amount over an inflated row set; `get_timetable` returns unscoped, un-enriched raw documents.
Only `get_homework` and `raise_helpdesk_ticket` are faithful to their collections.

**Things the CF gets right and that should not be regressed while fixing the above:** identity from
the verified token only (`:11-14, 120-140`); the closed typed tool set with no collection or id
accepted from the model (`:15-16`, schemas `:216-286`); fail-closed on a blank session
(`:162-167`); the per-school kill switch (`:158-160`); the student-status gate (`:172-174`); the
transactional daily quota (`:191-209`); the audit log (`:620-642`); and the decision *not* to write
into `supportTickets` (`:388-425`) — the reasoning there is correct and should be left alone.

---

## 5. Verified-identical — no action

Recorded so the next run does not re-litigate them:

| Item | Evidence |
|---|---|
| `sectionKey` shape across app / CF / panel | `Constants.kt:198-203` · `studentAssistant.js:111-113` · `Entity_firestore_sync.php:164-209` |
| Collection names vs `Constants.Firestore` | `studentAssistant.js:95-105` vs `Constants.kt:207-228` — all six match |
| Session filter on homework, fees, results | §2 |
| Session **source** (`schools.currentSession`) | `studentAssistant.js:162` · `AuthRepository.kt:129-134` |
| Claim reading (`school_id` snake-primary, camel fallback) | `studentAssistant.js:125` vs builder `Firebase.php:842-845` (dual-cast, as contracted) |
| Student `status` gate tolerant of the panel's capital-`A` `"Active"` | `studentAssistant.js:172` lowercases; panel writes `'Active'` — `Data_service.php:18` |
| `attendanceSummary` payload keys `percentage` / `dayWise` | CF `:300-301` vs writer `Attendance.php:1324, 1330` — correct; only the *address* is wrong (D1) |
| Read-only posture matches the rules | `firestore.rules:587, 1097, 1921` all `allow write: if false` |

Note on claims: `student_id` is emitted **snake-only** (`Firebase.php:855-857`), unlike
`school_id`/`schoolId` which are dual-cast (`:844-845`). The CF's `t.studentId` fallback (`:126`) is
therefore dead code. Harmless, but it should not be read as evidence that a camel variant exists.

---

## 6. Recommended fix order

Ordered by the priority sequence, not by effort. All are one-file changes in `studentAssistant.js`.

1. **D5** — add `.where('session','==',ctx.session)` to `get_timetable` (`:355-359`). The index
   `timetables [schoolId, sectionKey, session]` already exists. *Business rule; restores the file's
   stated contract.*
2. **D3b** — add `.where('status','!=','archived')` to `get_fee_status` (`:331-335`), matching
   `FeeFirestoreRepository.kt:97`. *Business rule; money.*
3. **D1 + D1b** — build the doc id from an ISO month derived in the school's timezone (`:291-293`,
   `:428-433`). Accept either input form from the model and normalise, as the app does
   (`AttendanceFirestoreRepository.kt:70-87`). *Un-breaks a tool that has never once returned data.*
4. **D2 + D2b** — read `totalMarks`, `maxMarks`, `percentage`, `grade`, `passFail`, `rank` and
   project `subjects{}`; delete the `published` read (`:377-382`). *Un-breaks results.*
5. **D3a** — `amount: f.netAmount` (`:342`), optionally with `grossAmount`/`discountAmount`.
6. **D8** — return `truncated: q.size === QUERY_LIMIT` from all five read tools and teach the prompt
   to disclose it. *Prevents confidently wrong totals.*
7. **D7** — replace the generic failure text (`:600-601`) with something the student can act on.
8. **C3 / D6-note** — after the `[CONTESTED]` items are resolved.

Each of 1–5 is independently testable against a live student record and belongs in the UAT matrix as
its own row.

---

## 7. Surface parity (secondary)

The module is scoped to **students and parents** — decided 2026-08-23, recorded in the file header
(`studentAssistant.js:5-8`) and *enforced*, not merely documented:

```js
if (!['student', 'parent'].includes(role)) {
  throw new HttpsError('permission-denied', 'This assistant is for students and parents.');
}
```
`studentAssistant.js:132-134`

| Surface | Implementation | Assessment |
|---|---|---|
| **Parent Android** | Present — `AssistantRepository.kt` calls the callable (`data/repository/AssistantRepository.kt:22-50`); the CF's handoff route matches `Route.SupportCompose` (`studentAssistant.js:88-92`) | The reference surface. |
| **Teacher Android** | None (no reference to `studentAssistant` / `AiAssistant` anywhere under `ZenXII_Teacher/app/src/main/java/`) | **Deliberate, and correctly enforced.** A staff token is rejected at `:132-134`, so absence of UI is consistent with the server contract rather than an unguarded gap. Not a parity defect. |
| **Parent iOS** | None (`zenxii-ios/ZenXiiParent` + `ZenXiiCore`, 101 non-vendor Swift files) | **Not yet due.** iOS Parent is broadly pre-feature — `ZenXiiCore` covers auth, dashboard, notice, homework and attendance models only. The assistant is ahead of the iOS feature set, not missing from it. File under the general iOS backlog, not as an assistant gap. |
| **Teacher iOS** | Does not exist as a product | N/A. |

One genuine cross-surface coupling: the handoff constant
`SUPPORT_COMPOSE_ROUTE = 'support_compose'` (`studentAssistant.js:92`) must equal `Route.SupportCompose`
in the Parent app. **Verified matching** — `data object SupportCompose : Route("support_compose")`
(`ui/navigation/NavGraph.kt:200`), consumed at `:847`. It is a bare string on both sides with no
shared test, and it becomes a third copy if iOS ever implements the assistant. Correct today; the
only string in this module that must match across repos, so worth one UAT row asserting the handoff
actually navigates.
