# A5 · DATA-SPEC — AI Assistant ("Ask ZenXii")

**Agent:** A5 · DATA-SPEC · **Date:** 2026-08-31 · **Evidence ceiling:** E2 (static; no live
Firestore). Anything requiring the deployed ruleset, the live index set, or a production
document is marked `REQUIRES VERIFICATION`.

**Standing question:** *can the UI look correct while the stored data is wrong?*
**Answer: yes, and it does — in four independent places.** Three of the five read tools return
structurally empty or null-filled payloads against production-shaped documents, and the model is
instructed to narrate whatever it gets. Nothing throws. The chat renders a fluent, confident,
wrong answer.

Branch: `yug_testing`. `functions/studentAssistant.js` is **UNCOMMITTED** (`git status` shows
` M functions/studentAssistant.js`); `firebase-rules/` is clean.

---

## §9 — Schema findings

Severity: **P0** = wrong data reaches a child/parent, or a legal exposure. **P1** = the feature
silently does not work. **P2** = correctness/hygiene.

---

### D-01 · P0 · `get_attendance_summary` reads a document ID that has never existed

The CF builds the doc key from a **human month label**:

```js
const monthLabel = String(input.month || '').trim() || currentMonthLabel();   // "August 2026"
.doc(`${C.ATTENDANCE_SUMMARY}/${ctx.schoolId}_${ctx.studentId}_${monthLabel}`)
```
`functions/studentAssistant.js:291-295`, with `currentMonthLabel()` at `:428-433`.

The canonical key is **`{schoolId}_{studentId}_{YYYY-MM}`**. Three independent confirmations:

- Parent app: *"Doc id format: `{schoolId}_{studentId}_{YYYY-MM}`"* plus an explicit
  `monthLabelToKey()` that converts `"April 2026" → "2026-04"` —
  `ZenXII_Parent/.../data/repository/firestore/AttendanceFirestoreRepository.kt:35-50, 70-86`.
- The model pins the two apart: `month = "2026-04"` vs `monthLabel = "April 2026"` —
  `.../data/model/firestore/AttendanceSummaryDoc.kt:19-20`.
- **Production evidence** — a real write in the panel's own error log:
  `attendanceSummary/SCH_D94FE8F7AD_STU0001_2026-05`, `application/logs/log-2026-05-15.php:348`.
  The panel writer keys on `$monthKeyIso`, `application/controllers/Attendance.php:1475`.

**Consequence.** `snap.exists` is **always false**. The tool returns `{found:false}` on every call,
for every student, in every month. Per the system prompt (`:458`) the model then says *"there is
nothing recorded"* — so a student with perfect attendance is told the school has no record of them.
Attendance is the assistant's headline capability.

This is **P-01 (the feature that has never once run)** in its purest form: the code path executes,
returns 200, logs nothing, and produces a plausible sentence.

Aggravating: the tool's own schema *teaches the model the wrong format* — `'formatted as "Month
YYYY" (for example "August 2026")'` (`:229`) — so a model-supplied month is wrong too. There is no
input format that reaches a document.

`[CONFIRMED]`

---

### D-02 · P0 · `get_exam_results` cannot return a single mark

The CF projects six fields (`:374-383`):

| CF reads | Exists on a `results` doc? | Actual field |
|---|---|---|
| `r.examName` | ✅ | `examName` |
| `r.subject` | ❌ **never** | per-subject data is the `subjects` **map** |
| `r.marksObtained` | ❌ **never** | `totalMarks` (and `subjects[x].total`) |
| `r.maxMarks` | ✅ | `maxMarks` |
| `r.grade` | ✅ | `grade` |
| `r.published` | ❌ **never** | no such field; publication is by *collection membership* |

Writer, authoritative: `application/libraries/Exam_result_store.php:164-176` (`buildResultDoc`) —
emits `subjects`, `totalMarks`, `maxMarks`, `percentage`, `grade`, `passFail`, `absent`,
`absentSubjects`, `rank`, `gradingScale`, `passingPercent`. Consumer, confirming:
`ZenXII_Parent/.../data/model/firestore/ResultDoc.kt:11-38` — same field set, no `marksObtained`,
no top-level `subject`, no `published`.

**Consequence.** Every result row the model receives is
`{examName:"Half Yearly", subject:null, marksObtained:null, maxMarks:400, grade:"B", published:null}`.
The student asks *"what did I get in maths?"* and the assistant either says nothing is recorded, or —
the dangerous branch — narrates `maxMarks: 400` as the mark. `percentage`, `totalMarks`, `rank` and
`passFail` are all present in the document and all discarded. **The one field the student asked for
is the one field not read.**

`[CONFIRMED]`

**D-02b · P2 · the `published` gate is a phantom check.** The system prompt orders *"report only
what is recorded as published"* (`:464`) against a field that is always `null`. The **real** gate is
sound by accident: unpublishing *moves documents out of `results` into `resultsStaging`*
(`Exam_result_store.php:567-569`), and `resultsStaging` is `allow read, write: if false`
(`firestore.rules:1117`). So presence in `results` **is** publication and the CF is not leaking
drafts. But the CF does not know that, and a future writer that adds a `published:false` row to
`results` would be exposed with no guard. `[CONFIRMED]` (gate holds) / `[INFERRED]` (fragility).

---

### D-03 · P1 · `get_timetable` is the only query with **no session filter** — and the CF's own header forbids that

```js
.where('schoolId', '==', ctx.schoolId)
.where('sectionKey', '==', key)
.limit(QUERY_LIMIT)
```
`functions/studentAssistant.js:355-358`.

The file's own security contract, four lines of it, says: *"EVERY QUERY IS SCOPED TWICE — by
schoolId AND by session. Forgetting the session filter is the most repeated bug in this codebase"*
(`:17-19`). `get_homework`, `get_fee_status` and `get_exam_results` all comply. `get_timetable`
does not. This is a **Z3 violation** in the one function whose header cites Z3.

Timetable docs carry `session` and are keyed `{schoolId}_{session}_{sectionKey}_{day}` —
`ZenXII_Parent/.../TimetableFirestoreRepository.kt:22`. The Parent app runs the identical
two-field query and then **filters session client-side** (`:59-63, 66`), with a logged
"session filter removed all of them" diagnostic (`:74-82`) showing this has bitten before.
The CF has no such filter, client- or server-side.

**Consequence.** With 7 day-docs per session, `QUERY_LIMIT = 25` (`:85`) is exceeded after ~3.5
sessions. The 25 returned are ordered by `__name__`, i.e. by `{schoolId}_{session}_…` — so
**the oldest sessions sort first** and a school past its third year gets last year's timetable and
nothing else. Below that threshold the model receives every session's periods interleaved with no
way to tell them apart (the CF returns `d.data()` raw, `:361`), and will report a subject the
student no longer takes at a period that no longer exists.

`[CONFIRMED]` (missing filter, key shape) / `[INFERRED]` (ordering consequence — depends on live
document counts, `REQUIRES VERIFICATION`).

---

### D-04 · P1 · The kill switch has no writer anywhere in the ecosystem

Independently verified across all four repos:

```
grep -rn "ai_assistant_enabled|aiAssistantEnabled|ai_assistant" \
  ~/Desktop/Zennxii_adminPanel ZenXII_Parent ZenXII_Teacher
```
Exactly **two** hits, both in the reader's own repo:
- `functions/studentAssistant.js:158` — `if (school.ai_assistant_enabled !== true) throw`
- `functions/index.js:669-670` — a comment describing the flag

Zero writers: no PHP controller, no view, no Cloud Function, no app, no migration, no seed script.
Nor can it be set incidentally — `School_config::save_profile()` writes through an explicit
`$allowed` field whitelist (`application/controllers/School_config.php:383, 401`), not a blind
merge, so no existing form can carry the key through.

**The only way to enable a school today is a hand-written document mutation** — Firebase console,
`gcloud`, or an ad-hoc Admin SDK script.

**Consequence.** The check is `!== true`, so *absent* and *false* are identical. **Every school in
production fails it.** The assistant is unreachable for 100% of tenants and returns
`failed-precondition` — *"The assistant is not enabled for your school"* — to every caller.
`loadContext()` is the second statement in the callable (`:482`), so this fires **before** the quota
write and before any tool runs. It is a P-01 that hides all the other P-01s below it: D-01, D-02
and D-03 cannot be observed in production because nothing reaches them.

The comment at `:156-157` calls this *"where the consent conversation is recorded"* — for
children's data that consent has no UI, no audit row, and no record of who granted it.

`[CONFIRMED]`

---

### D-05 · P0 · `assistantLogs` stores children's question text with no retention, no export, no erasure

`writeLog()` persists `question` (500 chars of a child's free text), `studentId`, `schoolId`,
`session`, `role`, `toolsUsed` and token counts — `functions/studentAssistant.js:620-638`.

Absences, each verified:
- **No TTL field.** `createdAt` is a `serverTimestamp` (`:637`); nothing named `expireAt`/`ttl`
  is written. A Firestore TTL policy is per-field and could target `createdAt`, but no such policy
  is declared in the repo. Whether one exists on the live database is `REQUIRES VERIFICATION`.
- **No cleanup job.** The only `onSchedule` exports in `functions/` are `feeOpsSweep`
  (`ops_sweep_worker.js:283`), `sweepExpiredStories` (`storiesCleanup.js:131`) and
  `closeStaleTickets` (`supportDesk.js:479`). None touches `assistantLogs`.
- **Invisible to the data pipeline.** `Data_service::_fsDocId()`'s school-scoped collection list —
  the spine of backup/export/delete — does not name `assistantLogs` or `assistantQuota`
  (`application/libraries/Data_service.php:344-346`). So these documents are not backed up, not
  exported, and **not deleted when anything else is**.
- **No admin reader.** Zero occurrences of either collection outside `studentAssistant.js`.

**Consequence.** An append-only, permanently-growing corpus of what children asked their school
assistant — including the counselling-adjacent disclosures the system prompt explicitly anticipates
(`:447`: self-harm, family, safety) — with no deletion path, no subject-access path, and no screen
that can even read it. Under DPDP the school is the warranting party but **the vendor is not
discharged** (per the project's own scope lock). This is a standing liability that grows per call.

Mitigating: the CF truncates to 500 chars (`:627`) and stores no reply text — only the question.

`[CONFIRMED]` (all four absences) / `REQUIRES VERIFICATION` (live TTL policy).

---

### D-06 · P1 · Two independent UTC-vs-IST date bugs; the quota one contradicts its own error message

**(a) Quota bucket.** `const day = new Date().toISOString().slice(0, 10);  // UTC day`
(`functions/studentAssistant.js:192`) keys `assistantQuota/{schoolId}_{studentId}_{day}`
(`:193`). Cloud Functions run UTC; users are IST (UTC+5:30).

The bucket therefore runs **05:30 IST → 05:30 IST**, not midnight to midnight.

Trace, a student asking at 23:00 IST on the 12th:
1. 23:00 IST 12th = 17:30 UTC 12th → bucket `…_2026-09-12`. Fine.
2. They exhaust 30 questions and get *"You have reached today's limit … **Please try again
   tomorrow**"* (`:198-199`).
3. At 00:30 IST on the **13th** — tomorrow by every clock the student owns — it is still 19:00 UTC
   on the 12th. **Same bucket. Still blocked.** The message told them a falsehood.
4. The block persists until **05:30 IST on the 13th**.
5. Symmetrically, questions asked 00:00–05:29 IST on the 13th are billed to the 12th's bucket, so a
   student who used 25 questions "yesterday evening" starts the 13th with 5.

The quota is not wrong in *size*, it is wrong in *phase* — and the user-facing message describes a
midnight reset that does not happen.

**(b) The model is told the wrong date — a second, independent instance.** The per-request context
line uses the same UTC expression: `` `Today is ${new Date().toISOString().slice(0, 10)}.` ``
(`:498`). Between 00:00 and 05:29 IST the model is told **yesterday's date**, and it uses that date
to reason about "is this homework due today / overdue / due tomorrow" against `dueDate` strings the
panel writes in local terms (`sprintf('%04d-%02d-%02d', …)`,
`application/libraries/Fee_generation_engine.php:580`). Late-night homework checking — the single
most likely time a student opens this — gets off-by-one due-date answers.

**(c) Same root, third site.** `currentMonthLabel()` (`:428-433`) uses `getMonth()`/`getFullYear()`
on a UTC-clocked server. On 1 September 00:00–05:29 IST it yields `"August 2026"`. Currently
harmless only because D-01 makes the label unusable anyway.

`[CONFIRMED]`

---

### D-07 · P2 · `feeDemands.amount` does not exist

`f.amount ?? null` (`:342`) has no source field. The writer emits `grossAmount`, `discountAmount`,
`fineAmount`, `netAmount`, `paidAmount`, `balance` — `Fee_generation_engine.php:607-613` — mirrored
exactly by `FeeDemandDoc.kt:27-33`. **`amount` is always `null`.**

The tool description promises *"amounts due, amounts paid and due dates"* (`:246-247`), and the
system prompt tells the model to be careful because *"a parent may be reading over the student's
shoulder"* (`:462`) — while the amount is structurally absent. `balance` and `paidAmount` do
resolve, so the answer degrades rather than fails; the risk is the model narrating `balance` as the
bill, or filling the gap.

Everything else in this tool is clean: `feeHead` ✅ (`Fee_generation_engine.php:600` — the `?? f.head`
fallback is dead but harmless), `paidAmount` ✅, `balance` ✅, `status` ✅, **`dueDate` ✅**
(`:614`, contra any assumption that it is missing).

`[CONFIRMED]`

---

### D-08 · P2 · Quota and log documents orphan permanently

No writer, no reader, no deleter, and unknown to `Data_service` (D-05). Therefore:

- **Student deleted** → their `assistantLogs` rows and `assistantQuota` docs survive, keyed by a
  `studentId` that resolves to nothing. Question text outlives the student record — the erasure
  problem in D-05, made concrete.
- **Student moves school** → new `schoolId`, so a new quota key. Same-UTC-day quota **resets to
  zero** on transfer. Old-school logs remain filed under the old tenant, readable by whoever later
  builds a reader for it. Cross-tenant residue (G1-adjacent, though not a live read path today).
- **School offboarded** → the tenant's whole log history persists, unnamed by any backup or purge
  list.
- **Growth is unbounded.** One `assistantQuota` doc per student per active day, forever.

`[CONFIRMED]` (absence of every path) / `[INFERRED]` (transfer semantics — no live data,
`REQUIRES VERIFICATION`).

---

### D-09 · Clean — recorded so it is not re-investigated

- **`sectionKey` construction is byte-identical.** CF: `/^class /i.test(s) ? s : 'Class ' + s`,
  `/^section /i.test(s) ? s : 'Section ' + s`, joined `${cls}/${sec}`
  (`functions/studentAssistant.js:111-113`). App: `startsWith("Class ", ignoreCase = true)` /
  `startsWith("Section ", ignoreCase = true)` (`ZenXII_Parent/.../util/Constants.kt:198-203`),
  joined `"${classKey(className)}/${sectionKey(section)}"`
  (`HomeworkFirestoreRepository.kt:80`; `TimetableFirestoreRepository.kt:49-50`). The regex and the
  `startsWith` agree on the trailing space and on case-insensitivity. **No divergence.**
- **`get_homework` field mapping is fully clean** — `title`, `subject`, `description`, `dueDate`
  all present on `HomeworkDoc.kt:13-19`. It is the only tool of the five with no field drift.
- **`students.status` casing is handled** — the panel writes `'Active'`; the CF lowercases before
  comparing (`:172`).
- **No index gap** — see Q2.

---

### D-10 · P2 · `schoolCode` vs `schoolId` divergence in doc-key construction

The CF keys `students/{school_id}_{studentId}` and `attendanceSummary/{school_id}_…` from the
`school_id` claim alone (`:150, 193, 293`). Both Parent repositories resolve the key as
**`schoolCode ?: schoolId`** — with an explicit warning that *"a mismatch here would silently read
the wrong document and render a blank month"* (`AttendanceFirestoreRepository.kt:117-126`). These
are two distinct claims, both emitted (`application/libraries/Firebase.php:835-847`).

For any tenant where `schoolCode` is non-empty and differs from `schoolId`, the CF and the app read
**different documents**. Doc-id evidence (`SCH_D94FE8F7AD_STU0001_2026-05`) says the prefix is the
`SCH_`-style `schoolId`, so `schoolCode` is presumably blank or equal in practice — but the app
would not carry a fallback and a warning comment for a case that cannot occur.

`[INFERRED]` — `REQUIRES VERIFICATION` against a live `schools` document.

---

## Per-collection table

| Collection | R/W | Doc-id shape used by the CF | Index required | Index exists? | Rules reachability (client) | Retention |
|---|---|---|---|---|---|---|
| `schools` | read | `schools/{schoolId}` — `:150` | none (doc get) | n/a | per existing block | n/a |
| `students` | read | `students/{schoolId}_{studentId}` — `:150` | none (doc get) | n/a | per existing block | n/a |
| `attendanceSummary` | read | `{schoolId}_{studentId}_{"August 2026"}` — `:293` **✗ canonical is `{YYYY-MM}`** | none (doc get) | n/a | per existing block | n/a |
| `homework` | read | query: `schoolId`+`sectionKey`+`status`+`session`, `orderBy createdAt DESC` — `:309-315` | **yes** (has an ordering) | ✅ `[schoolId, sectionKey, status, session, createdAt DESC]` — exact | per existing block | n/a |
| `feeDemands` | read | query: `schoolId`+`session`+`studentId` — `:331-335` | no (equality-only) | ✅ also `[schoolId, session, studentId, month]` | per existing block | n/a |
| `timetables` | read | query: `schoolId`+`sectionKey` — **no `session`** — `:355-358` | no (equality-only) | ✅ `[schoolId, sectionKey]` | per existing block | n/a |
| `results` | read | query: `schoolId`+`session`+`studentId` — `:367-371` | no (equality-only) | ✅ `[schoolId, session, studentId]` | `allow read: if isStaffOrOwnStudent(); write: if false` — `firestore.rules:1095-1098` | n/a |
| **`assistantQuota`** | **write** | `{schoolId}_{studentId}_{UTC yyyy-mm-dd}` — `:193` | none (never queried) | **0 declared** | **catch-all deny** — `firestore.rules:3355-3357` | **none** |
| **`assistantLogs`** | **write** | auto-id — `:622` | none (never queried) | **0 declared** | **catch-all deny** — `firestore.rules:3355-3357` | **none** |

Index source: `firebase-rules/firestore.indexes.json`, 308 declared indexes, clean in git.

---

## The 8 questions

### Q1 — The kill switch has no writer

**Confirmed independently.** See **D-04**. Two hits ecosystem-wide, both in the reader's own repo
(`functions/studentAssistant.js:158`, `functions/index.js:669-670`); zero writers in the panel, the
Cloud Functions, either app, or any migration. `School_config::save_profile()`'s `$allowed`
whitelist (`School_config.php:383, 401`) blocks incidental writes too.

**The only way to enable a school is a manual document mutation** — console, `gcloud`, or an
ad-hoc Admin SDK script. Since the guard is `!== true`, absent ≡ false, so **reachability is zero:
every tenant fails it, and it fails at `loadContext()` (`:482`) before quota or any tool.** The
feature cannot have run in production, which also means D-01, D-02, D-03 and D-06 have never been
observed — they are latent behind this gate and will all surface on the same day the first school
is switched on. `[CONFIRMED]`

### Q2 — Serving indexes

**No gap.** Four of the five reads need no composite index at all, and the fifth has an exact one.

| Tool | Query | Verdict |
|---|---|---|
| `get_attendance_summary` | doc `get()` | no index — but reads a key that never exists (D-01) |
| `get_homework` | 4 equalities + `orderBy createdAt DESC` | **the only composite-requiring query.** Declared exactly: `[schoolId, sectionKey, status, session, createdAt DESC]` ✅ |
| `get_fee_status` | 3 equalities, no ordering | equality-only ⇒ servable by single-field indexes; `[schoolId, session, studentId, month]` also covers it ✅ |
| `get_timetable` | 2 equalities, no ordering | equality-only ⇒ servable; `[schoolId, sectionKey]` declared ✅ |
| `get_exam_results` | 3 equalities, no ordering | equality-only ⇒ servable; `[schoolId, session, studentId]` declared exactly ✅ |

**P-02 does not apply here.** The pattern targets queries that *lack* a `schoolId` equality and so
cannot use a `schoolId`-leading index. **Every one of the CF's five queries leads with
`schoolId` equality**, sourced from the verified token (`:125`) and never from a request field.
There is no cross-tenant sweep in this module.

Two caveats: (a) whether these indexes are **deployed** is `REQUIRES VERIFICATION` — run
`node aegis/cli.js indexes`; the project's own history records 284 live vs 183 declared, so live is
likely a superset, but "likely" is not a pre-deploy answer. (b) `assistantQuota` and `assistantLogs`
have **0 declared indexes** — fine today (the CF only does a keyed `get`/`add`), but any retention
sweep, any admin viewer, and any DPDP subject-access query will need one, and a scheduled sweep that
hits `FAILED_PRECONDITION` is precisely the second confirmed instance of P-01 in this codebase.

### Q3 — `sectionKey` construction

**Identical. No mismatch.** See **D-09**. CF `:111-113` vs `Constants.kt:198-203` — same trailing
space, same case-insensitivity, same `"{cls}/{sec}"` join; the app's own call sites
(`HomeworkFirestoreRepository.kt:80`, `TimetableFirestoreRepository.kt:49-50`) compose them the same
way. This is the one thing the CF got right about keys — which makes D-01 (the *other* key, built
from a month label) the more striking, since both carry the same "a mismatch silently returns empty"
comment (`:110`). `[CONFIRMED]`

### Q4 — Field-name drift

**Four divergences, in three tools.**

| CF reads | Site | Reality | Effect |
|---|---|---|---|
| `r.marksObtained` | `:377` | `totalMarks` / `subjects[x].total` | **always `null` — the mark is unreachable** (D-02) |
| `r.subject` | `:376` | no top-level field; `subjects` is a map | always `null` (D-02) |
| `r.published` | `:381` | no such field; publication = membership of `results` | always `null`; the prompt's publish check is a no-op (D-02b) |
| `f.amount` | `:343` | `grossAmount` / `netAmount` | always `null` (D-07) |

**Not divergences** (checked, clean): `d.dayWise` ✅ and `d.percentage` ✅ both exist on
`AttendanceSummaryDoc.kt:31, 39` — though D-01 means the document is never fetched, so they are
correct field names on a read that never happens. `f.feeHead` ✅ (`Fee_generation_engine.php:600`);
the `?? f.head` fallback is dead but harmless. `f.dueDate` ✅ (`:614`). `f.paidAmount`, `f.balance`,
`f.status` ✅. All four homework fields ✅. `[CONFIRMED]`

### Q5 — `assistantLogs` retention

**None, on every axis.** No TTL field written, no TTL policy in the repo, no cleanup job among the
three `onSchedule` exports, no admin reader, and invisible to `Data_service`'s backup/export/delete
spine (`Data_service.php:344-346`). Children's question text — including the self-harm and family
disclosures the prompt anticipates at `:447` — accumulates permanently with no erasure or
subject-access path. **The absence is the finding.** See **D-05**. `[CONFIRMED]`; a live TTL policy
is `REQUIRES VERIFICATION`.

### Q6 — UTC quota vs IST

The bucket runs **05:30 IST → 05:30 IST**. A student blocked at 23:00 IST is told *"try again
tomorrow"* (`:198-199`), tries at 00:30 IST — genuinely tomorrow — and is **still blocked**, until
05:30. Conversely, 00:00–05:29 IST usage is billed to the previous day. The quota is right in size,
wrong in phase, and its error message describes a midnight reset that does not exist. **Two further
instances of the same root:** the model is told the wrong `Today is …` between 00:00–05:29 IST
(`:498`), corrupting every due-date answer at exactly the hours a student checks homework; and
`currentMonthLabel()` (`:428-433`) crosses months 5.5 h early. See **D-06**. `[CONFIRMED]`

### Q7 — Client reachability of `assistantQuota` / `assistantLogs`

**Unreachable, correctly.** Neither collection has a `match` block in
`firebase-rules/firestore.rules` (3,359 lines; `grep -n "assistant"` → 0 hits), so both fall to the
catch-all `match /{document=**} { allow read, write: if false; }` at **`firestore.rules:3355-3357`**.
No client can read or write either — this is the right posture and it is **fail-closed by default**,
not by an explicit block, so it survives only as long as nobody adds a broader ancestor match.

**Z9 applies, and it is the whole story here:** the CF writes via the Admin SDK
(`admin.initializeApp()`, `:53`), which **bypasses rules entirely** — so the deny is not a control
over the writer, only over clients. Two consequences worth recording: (a) the deny is the *only*
protection on the log corpus, and it is the default branch rather than a named intent-lock, unlike
the sibling collections at `:3350-3352` which are explicitly `allow read, write: if false` with a
comment; **an explicit named block for both would be strictly better** and matches the file's
established convention. (b) The Parent app cannot read its own quota — `AssistantRepository.kt`
never tries, relying on the `RESOURCE_EXHAUSTED` code instead
(`ZenXII_Parent/.../data/repository/AssistantRepository.kt:26-28`) — so a "questions left today"
indicator is impossible without a rule change or a returned counter. `[CONFIRMED]`

### Q8 — Orphans and staleness

**Nothing cleans up either collection.** Student deleted → logs and quota docs survive under a dead
`studentId`, question text outliving the student record. Student moves school → new `schoolId` key,
so the same-UTC-day quota **silently resets to zero** on transfer; old-school logs stay filed under
the old tenant. School offboarded → the whole log history persists, unnamed by any purge list.
Growth is unbounded: one quota doc per student per active day, forever. See **D-08**.
`[CONFIRMED]` (absence of every path) / `[INFERRED]` (transfer semantics).

---

## Unresolved — needs E3+ (live) or another agent

1. **Are the declared indexes deployed?** `node aegis/cli.js indexes`. HEAD is complete; live is
   unverified. `REQUIRES VERIFICATION`
2. **Is the live ruleset == disk?** The catch-all deny is the sole protection on `assistantLogs`;
   the project's own history records 46 of 47 blocks as prod-only. `node aegis/cli.js rules status`.
   `REQUIRES VERIFICATION`
3. **Does any live `schools` doc have `schoolCode ≠ schoolId`?** Decides whether D-10 is real or
   theoretical.
4. **Is there a live Firestore TTL policy on `assistantLogs`?** Repo says no; console may differ.
5. **Does any production `assistantQuota` / `assistantLogs` document exist at all?** Per D-04 the
   answer should be **zero**, and that is the cheapest single confirmation of the whole finding set
   — P-08 strategy #3, *ask whether the artefact has ever existed*.
6. **Does the `role` claim ever equal `student`?** `Firebase.php:842` emits `role` from `$ctx`; the
   CF requires `role ∈ {student, parent}` (`:132`). If the Parent app's tokens carry `Parent`, the
   lowercase compare handles it — but if they carry something else, that is a **second** gate in
   front of D-04. **A1/A2 territory — flagging the dependency, not claiming it.**
7. **`student_id` is emitted without a `studentId` camel twin** (`Firebase.php:857`). The CF's
   `t.student_id || t.studentId` fallback (`:126`) absorbs it, so nothing breaks today, but it is a
   **Z1 dual-emit asymmetry** and the next reader that checks camel-first will fail closed.
   `[CONFIRMED]` (asymmetry) / `[INFERRED]` (risk).
