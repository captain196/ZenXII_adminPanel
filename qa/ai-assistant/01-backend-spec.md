# A4 · BACKEND-SPEC — `studentAssistant` Gen-2 callable

**Target:** `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/studentAssistant.js` (649 lines)
**Wiring:** `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/index.js:663-672`
**Evidence ceiling:** E2 — static source trace only. Nothing was executed. Every runtime
assertion below is marked `REQUIRES VERIFICATION`.
**Date:** 2026-08-31

---

## 0. Contract summary (for reference; not a finding)

| Aspect | Observed | Citation |
|---|---|---|
| Trigger | `onCall`, `region us-central1`, `timeoutSeconds 120`, `memory 512MiB` | `studentAssistant.js:478-480` |
| Secrets declared | **none** — Vertex via ADC | `:478-480`, `:515-521` |
| Inputs read | `request.data.message` (string), `request.data.messages` (array) | `:486-489` |
| Inputs ignored | everything else; no `schoolId`/`studentId` accepted (correct) | `:481-482`, `:211-215` |
| Outputs | `{ reply: string, toolsUsed: string[], handoff: object\|null }` | `:560-564` |
| Identity source | `request.auth.token` only | `:120-140` |
| Error path | 7 distinct `HttpsError` sites | see §2 |

### Error taxonomy — every `HttpsError` emitted

| Code | Condition | Citation | Quota consumed first? |
|---|---|---|---|
| `unauthenticated` | no `request.auth` | `:121-123` | no |
| `permission-denied` | `role` not in `['student','parent']` | `:132-134` | no |
| `failed-precondition` | blank `school_id` or `student_id` claim | `:135-138` | no |
| `not-found` | school doc missing | `:153` | no |
| `failed-precondition` | `school.ai_assistant_enabled !== true` | `:158-160` | no |
| `failed-precondition` | blank `currentSession` (fails closed — correct) | `:162-167` | no |
| `not-found` | student doc missing | `:169` | no |
| `permission-denied` | `student.status != 'active'` | `:172-174` | no |
| `resource-exhausted` | daily quota reached | `:197-199` | n/a |
| `invalid-argument` | blank `message` | `:490` | **YES** |
| `deadline-exceeded` | tool loop exhausted | `:609-610` | **YES** |
| *(unmapped)* → `internal` | any Vertex/`generateContent` throw | `:534-545` | **YES** |

The ordering at `:481-490` is the root of findings F-03 and F-04: **quota is consumed at
`:483`, before the payload is validated at `:490` and before the model is ever called.**

---

## 1. FINDINGS

---

### F-01 — The feature cannot be enabled: no code anywhere writes `ai_assistant_enabled`

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Gating chain in `loadContext`; defect pattern P-01 / P-11.
- **OBSERVATION:** The per-school kill switch is read at `:158` and denies unless the flag is
  **strictly** `true`. A repo-wide search across PHP, JS and rules finds exactly two occurrences
  of the string `ai_assistant_enabled` — the read itself, and a comment in `index.js`. There is
  no admin-panel setting, no controller, no migration, no backfill script and no Cloud Function
  that ever writes it. No Firestore rule mentions it either, so no client can set it.
- **EVIDENCE:**
  - `functions/studentAssistant.js:158` — `if (school.ai_assistant_enabled !== true) {`
  - Repo grep (`--include=*.php --include=*.js --include=*.rules`, whole
    `Zennxii_adminPanel` tree) returns only `functions/index.js:669` (a comment) and
    `functions/studentAssistant.js:158`.
  - `firebase-rules/firestore.rules` — zero matches for `assistant`.
- **CLASSIFICATION:** `[CONFIRMED]` that no code path writes the flag.
  `[INFERRED]` that the branch therefore always fires in production.
- **CONFIDENCE:** High on the static fact; Medium on the production consequence — the flag
  could have been set by hand in the Firebase console on a pilot school.
- **IS-SHOULD:** IS — every call terminates at `:159` with `failed-precondition` unless a
  human hand-edited Firestore. SHOULD — a school-settings toggle in the admin panel writes the
  flag, and that write is the point at which the consent conversation is recorded (which is
  what the comment at `:156-157` says is supposed to happen).
- **RISK:** The entire 649-line function, all six tools, the quota and the audit trail are
  unreachable. Any UAT that "passes" was run against a hand-set flag, i.e. against a state no
  onboarding path produces.
- **IMPACT:** **P0** — a shipped feature with no activation path.
- **INVARIANT AT RISK:** Feature reachability. Also the consent record the comment claims the
  flag carries.
- **DEPENDENCIES:** Blocks every other finding from mattering in production. A1 (prompt/UI) and
  A2 (app) will observe "assistant not enabled for your school" on any un-doctored tenant.
- **RECOMMENDATION:** Build the admin-panel toggle before anything else, or accept the flag as
  a deliberate manual-pilot gate and document it as such.
- **REQUESTED VERIFICATION:** Read `schools/{schoolId}.ai_assistant_enabled` on the live
  `graderadmin` Firestore for the test tenant. If absent/false, no UAT of this function has
  ever executed a model call.

---

### F-02 — `get_attendance_summary` builds a document key that can never exist

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 6 + tool query shapes; defect pattern P-11 (a branch that always fires).
- **OBSERVATION:** The tool composes `attendanceSummary/{schoolId}_{studentId}_{monthLabel}`
  where `monthLabel` is a **human month name**, e.g. `"August 2026"`, produced by
  `currentMonthLabel()`. The canonical key across the rest of the codebase uses **`YYYY-MM`**.
  The Parent app performs an explicit `"April 2026" → "2026-04"` conversion before building the
  same key; the Cloud Function omits that conversion entirely. The `!snap.exists` branch at
  `:295` therefore fires on every call, and the model is told `{found:false}` — which the system
  prompt (`:458`) instructs it to narrate as "there is nothing recorded", i.e. the failure is
  laundered into a plausible, wrong answer.
- **EVIDENCE:**
  - `functions/studentAssistant.js:291-295` —
    `.doc(`${C.ATTENDANCE_SUMMARY}/${ctx.schoolId}_${ctx.studentId}_${monthLabel}`)`
  - `functions/studentAssistant.js:428-433` — `currentMonthLabel()` returns `` `${months[now.getMonth()]} ${now.getFullYear()}` ``
  - Canonical writer/reader: `application/controllers/Staff_role_check.php:82-83` —
    `$monthKey = substr($date, 0, 7); ... $docId = "{$schoolId}_{$sid}_{$monthKey}";`
  - `application/controllers/Sis.php:3226-3229` — `sprintf('%04d-%02d', …)` then `docId2(…)`
  - Live doc id in the error log: `application/logs/log-2026-05-15.php:348` —
    `attendanceSummary/SCH_D94FE8F7AD_STU0001_2026-05`
  - The reference conversion the CF is missing:
    `ZenXII_Parent/.../firestore/AttendanceFirestoreRepository.kt:49-50, 69` —
    `val monthKey = monthLabelToKey(month)` / `/** "April 2026" → "2026-04" … */`
- **CLASSIFICATION:** `[CONFIRMED]`
- **CONFIDENCE:** High. Four independent writers agree on `YYYY-MM`; one log line shows the
  literal live key.
- **IS-SHOULD:** IS — attendance, the single most-asked-about record, always answers "nothing
  recorded". SHOULD — normalise `input.month` to `YYYY-MM` (reuse the app's mapping) and
  reject anything that does not match `^\d{4}-\d{2}$` after normalisation.
- **RISK:** Silent wrong answer, not an error. A student told "no attendance is recorded for
  August" may act on it (and a parent may escalate to the school) — this is the classic
  phantom-success shape from CLAUDE.md, relocated into an AI narration.
- **IMPACT:** **P0** — flagship tool is inert and fails deceptively.
- **INVARIANT AT RISK:** "Never claim to have taken an action you did not take / say so when a
  tool returns nothing" (`:449`) is technically honoured while being materially false.
- **DEPENDENCIES:** A2 (Parent app) will see this as an empty attendance answer, not an error.
  A1 may misattribute it to the prompt.
- **RECOMMENDATION:** Fix the key format and add a unit test asserting the composed id against
  the literal `SCH_..._STU0001_2026-05` shape.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — confirm on live Firestore that no
  `attendanceSummary` doc uses a `"Month YYYY"` suffix.

---

### F-03 — Quota is consumed before validation and before the model call; a failed question is billed

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Questions 3 and 8.
- **OBSERVATION:** `consumeQuota` runs at `:483`. The blank-message check runs at `:490`, seven
  lines later. The Vertex call runs at `:534`. The transaction at `:194-208` commits
  `count: used + 1` before any of them, and nothing decrements it on failure — there is no
  compensating write, no try/finally, no refund path in the file. So an empty payload
  (`invalid-argument`), a Vertex outage (`internal`), a loop exhaustion
  (`deadline-exceeded`) and a 120 s container kill all leave the student one question poorer
  out of 30 with nothing to show for it.
- **EVIDENCE:**
  - `functions/studentAssistant.js:481-490` — `await consumeQuota(...)` precedes
    `if (!question) throw new HttpsError('invalid-argument', 'Ask a question.');`
  - `functions/studentAssistant.js:201-207` — `tx.set(ref, { … count: used + 1 … })`
  - No `count: used - 1`, `FieldValue.increment(-1)` or refund appears anywhere in the file.
- **CLASSIFICATION:** `[CONFIRMED]` (ordering and absence of a refund are textual).
  `[INFERRED]` that the transaction is durable at the moment the later throw occurs.
- **CONFIDENCE:** High.
- **IS-SHOULD:** IS — failures are billed to the child. SHOULD — validate the payload before
  the transaction (free), and either consume the quota after a successful model call or
  compensate on the failure paths.
- **RISK:** A Vertex incident silently burns every active student's daily allowance; the
  quota is UTC-day keyed (`:192`) so there is no recovery until 05:30 IST the next morning.
- **IMPACT:** **P1**
- **INVARIANT AT RISK:** Fairness of the 30/day cap; the ~₹3/student/month cost model is
  unaffected (over-billing is conservative) but the student-facing contract is not.
- **DEPENDENCIES:** Compounds F-06 (a `deadline-exceeded` an app maps to "retry" costs a
  second unit).
- **RECOMMENDATION:** Move `:489-490` above `:483`. Wrap the model call so a Vertex failure
  compensates the counter.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — force a Vertex error and confirm the
  `assistantQuota/{school}_{student}_{day}.count` still incremented.

---

### F-04 — No idempotency: a resubmitted question double-decrements quota and writes two audit rows

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 4 / invariant G5.
- **OBSERVATION:** The callable accepts no request id, dedupe key or nonce — the entire input
  surface is `message` and `messages` (`:486-489`). The quota document is keyed only by
  `{schoolId}_{studentId}_{day}` (`:193`) and blindly increments. The audit row is created with
  `.add()` (`:622`), an auto-id, so two identical calls produce two documents. Nothing in the
  file compares a new request against a recent one.
- **EVIDENCE:**
  - `functions/studentAssistant.js:486-489` — only `messages` and `message` are read.
  - `functions/studentAssistant.js:193` — `db().doc(`${C.ASSISTANT_QUOTA}/${schoolId}_${studentId}_${day}`)`
  - `functions/studentAssistant.js:622` — `db().collection(C.ASSISTANT_LOGS).add({`
  - Client side sends no id either: `ZenXII_Parent/.../AssistantRepository.kt:34-38` —
    the payload is exactly `{"message", "messages"}`.
- **CLASSIFICATION:** `[CONFIRMED]` for the absence of any dedupe mechanism.
  `[UNKNOWN]` for how often duplicates actually arrive.
- **CONFIDENCE:** High on the design gap; Low on frequency.
- **IS-SHOULD:** IS — two decrements, two `assistantLogs` rows, two billed Vertex calls for
  one logical question. SHOULD — G5 says a duplicate submission never produces duplicate
  business records.
- **RISK:** Mitigated in severity by the records being read-only — no ticket is filed twice,
  no money moves. The damage is confined to quota and audit-log accuracy (a reviewer counting
  `assistantLogs` rows will over-count questions asked).
- **IMPACT:** **P2**
- **INVARIANT AT RISK:** **G5**.
- **DEPENDENCIES:** Whether the Parent app disables the send button while in flight is A2's
  call — but a client-side guard is not a server invariant, and the callable is directly
  reachable with any valid ID token.
- **RECOMMENDATION:** Accept a client-generated `requestId`; key `assistantLogs` on
  `{schoolId}_{studentId}_{requestId}` with `create`-semantics and short-circuit a repeat.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — whether the Firebase Functions client
  SDK retries a callable on transport failure by default. I could not establish this from
  source in this repo.

---

### F-05 — `get_timetable` is not session-scoped (Z3), and has no ordering

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 7 / invariant Z3.
- **OBSERVATION:** Five of the six tools are audited below (§2 Q7). `get_timetable` filters on
  `schoolId` and `sectionKey` **only** — no `session` — directly contradicting the file's own
  header contract #3 at `:17-19` ("EVERY QUERY IS SCOPED TWICE"). It also has no `orderBy`, so
  the 25 returned rows are ordered by document name; if the section carries timetable rows from
  a prior session, last year's periods can be returned as this year's. The composite index
  `timetables: schoolId + sectionKey + session` already exists, so adding the filter costs
  nothing.
- **EVIDENCE:**
  - `functions/studentAssistant.js:355-359` —
    `.where('schoolId','==',ctx.schoolId).where('sectionKey','==',key).limit(QUERY_LIMIT)`
  - `functions/studentAssistant.js:17-19` — the contract the code breaks.
  - `firebase-rules/firestore.indexes.json` — declares `timetables [schoolId, sectionKey, session]`
    (and `[schoolId, className, session]`, `[schoolId, session]`).
- **CLASSIFICATION:** `[CONFIRMED]` for the missing filter.
  `[INFERRED]` that stale-session rows exist for a given section.
- **CONFIDENCE:** High on the omission; Medium on the practical leak (depends on whether
  timetable rows are retained across sessions).
- **IS-SHOULD:** IS — a widened query. SHOULD — `.where('session','==',ctx.session)`, matching
  the other four collection queries.
- **RISK:** Wrong schedule presented as fact. Cross-session data surfacing is the exact bug
  class CLAUDE.md names "the single most repeated bug in this codebase".
- **IMPACT:** **P1**
- **INVARIANT AT RISK:** **Z3**.
- **DEPENDENCIES:** None — one-line fix, index already deployed.
- **RECOMMENDATION:** Add the session filter and an `orderBy` on the period ordering field.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — do `timetables` docs carry a `session`
  field at all? I found no `'session'` write in `application/controllers/Timetable.php`. If the
  field is absent on live docs, adding the filter would return **zero** rows — verify before
  shipping the fix.

---

### F-06 — Loop exhaustion returns an error, discards all work, and uses a misleading code

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 5.
- **OBSERVATION:** When `iterations` reaches `MAX_TOOL_ITERATIONS = 6` the loop falls through
  to `:607-610`: an audit row with `ok:false`, then a thrown `deadline-exceeded`. The caller
  receives an **exception, not a partial answer**. Everything accumulated is lost: up to six
  billed Vertex calls, every Firestore read the tools performed, the `toolsUsed` array, and —
  materially — any `handoff` object assembled at `:586-594`. If the model calls
  `raise_helpdesk_ticket` on the sixth iteration, the prepared support draft is discarded and
  the student sees an error instead of the "Open Support" button. Separately, `deadline-exceeded`
  is semantically wrong: nothing timed out, the loop hit an iteration cap. `resource-exhausted`
  or `failed-precondition` would describe it; `deadline-exceeded` invites a client to retry,
  which costs another quota unit (F-03).
- **EVIDENCE:**
  - `functions/studentAssistant.js:531` — `while (iterations < MAX_TOOL_ITERATIONS) {`
  - `functions/studentAssistant.js:607-610` — `writeLog({… ok: false}); throw new HttpsError('deadline-exceeded', …)`
  - `functions/studentAssistant.js:528` / `:586-594` — `handoff` is built inside the loop and
    only returned at `:563`, which the exhaustion path never reaches.
  - Secondary: `:552-554` treats a response as final only when `calls.length === 0`, so text
    returned *alongside* a function call is dropped every iteration.
- **CLASSIFICATION:** `[CONFIRMED]` for the control flow.
  `[UNKNOWN]` for how often six iterations are actually reached.
- **CONFIDENCE:** High on the trace; Low on frequency (a five-tool question plus one retry
  would do it).
- **IS-SHOULD:** IS — total loss on exhaustion. SHOULD — on the last iteration, re-call the
  model with tools disabled to force a text answer from what was already retrieved, and return
  it with the `handoff` intact.
- **RISK:** Worst on exactly the questions that matter most (multi-record, "what do I owe and
  what's due tomorrow"), and on the helpdesk handoff, which is the feature's only escape hatch.
- **IMPACT:** **P2**
- **INVARIANT AT RISK:** No partial-work loss on a bounded loop; correct error semantics.
- **DEPENDENCIES:** A2 must be told which `FirebaseFunctionsException` codes to map; the
  Kotlin repo currently documents only `RESOURCE_EXHAUSTED` and `FAILED_PRECONDITION`
  (`AssistantRepository.kt:26-28`) — `DEADLINE_EXCEEDED` and `INTERNAL` are unhandled there.
- **RECOMMENDATION:** Final-iteration forced-answer, plus a distinct error code.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — whether Gemini 3.1 Flash-Lite ever
  emits text and a functionCall in one response (if it does, `:552-554` silently drops text on
  every tool turn).

---

### F-07 — The fee-defaulter result-withhold gate has no twin in `get_exam_results` (P-04)

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Defect pattern P-04; tool query shapes.
- **OBSERVATION:** The panel enforces, in three separate places, that a student flagged as a
  fee defaulter with `result_withheld` receives **no results at all** — `Result.php:347` returns
  an empty array and sets `result_withheld`. `TOOL_IMPL.get_exam_results` reads the `results`
  collection directly with no defaulter check and no `published` filter; it merely *returns*
  `published` as a field (`:382`) and relies on the system prompt's instruction to "report only
  what is recorded as published" (`:464`). A prompt instruction is not an access control.
- **EVIDENCE:**
  - `functions/studentAssistant.js:367-385` — query is `schoolId + session + studentId`; no
    `published` filter, no withhold check.
  - `functions/studentAssistant.js:382` — `published: r.published ?? null,`
  - `application/controllers/Result.php:307-319, 347` — `if (!empty($defaulterNode['result_withheld'])) { $resultWithheld = true; … }` … `'results' => $resultWithheld ? [] : $results,`
  - Same gate repeated at `Result.php:446-459` and `Result.php:1693-1704`.
- **CLASSIFICATION:** `[CONFIRMED]` for the missing gate.
  `[INFERRED]` that a withheld student's docs are present in `results` — mitigated because
  unpublished computation lands in `resultsStaging`, not `results`
  (`Result.php:1075` — "PUBLISH GATE: compute writes to `resultsStaging` (NOT the …").
- **CONFIDENCE:** Medium-High. The staging split limits this to the *withhold* case, not the
  unpublished case, but the withhold case is real and deliberate school policy.
- **IS-SHOULD:** IS — a student whose results the school has deliberately withheld over unpaid
  fees can obtain their marks by asking the assistant. SHOULD — the AI surface enforces the
  same gate as every other surface.
- **RISK:** Policy bypass through a new channel; directly embarrassing for a school that chose
  to withhold. Also note the assistant will happily read the fee dues that triggered the
  withhold via `get_fee_status` in the same conversation.
- **IMPACT:** **P1**
- **INVARIANT AT RISK:** P-04 — a rule enforced on one surface and absent on its twin.
- **DEPENDENCIES:** Needs the defaulter-node source (RTDB/Firestore) that `Result.php:317`
  reads; that lookup must be ported into `loadContext` or into the tool.
- **RECOMMENDATION:** Add the withhold check to `loadContext` (once, cheap) and make
  `get_exam_results` return `{ withheld: true, items: [] }` when set. Also add a hard
  `.where('published','==',true)` as defence in depth rather than trusting `:464`.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — confirm on live data that `results`
  docs persist for a withheld student.

---

### F-08 — Model-supplied `input.month` is interpolated into a Firestore path unvalidated

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 6.
- **OBSERVATION:** `call.args` is forwarded raw at `:584` — there is no schema validation layer
  between the model's output and the tool implementations; the `parametersJsonSchema` blocks
  (`:223-234`, `:272-284`) are advertised to the model but never enforced server-side.
  `get_attendance_summary` applies `String(...).trim()` only (`:291`) and splices the result
  straight into a `.doc()` path (`:293`). A value containing `/` changes the path's segment
  count: `"a/b/c"` yields `attendanceSummary/{key}_a/b/c`, a syntactically valid four-segment
  document path pointing at an arbitrary subcollection under an `attendanceSummary` parent.
  Likewise `raise_helpdesk_ticket` coerces `category` with `String(input.category || 'other')`
  (`:419`) without checking it against its own declared `enum`.
- **EVIDENCE:**
  - `functions/studentAssistant.js:584` — `const out = await impl(ctx, call.args || {});`
  - `functions/studentAssistant.js:291-293` — `const monthLabel = String(input.month || '').trim() || currentMonthLabel();` then the interpolated `.doc(...)`
  - `functions/studentAssistant.js:419` — `category: String(input.category || 'other'),`
- **CLASSIFICATION:** `[CONFIRMED]` for the absence of validation.
  `[INFERRED]` for the path-shape consequence.
- **CONFIDENCE:** High on the gap; Low on exploitability.
- **IS-SHOULD:** IS — one model-controlled string reaches a document path. SHOULD — validate
  against `^\d{4}-\d{2}$` (post-normalisation) and reject.
- **RISK:** Bounded, and worth stating plainly rather than overstating: the path prefix still
  begins `attendanceSummary/{schoolId}_{studentId}_…`, so it cannot be steered at another
  student's record, and the per-tool `try/catch` at `:596-602` degrades a thrown path error
  into a polite "that lookup failed". The residual concern is that the *only* thing choosing
  this string is a model whose context contains staff-authored free text the file itself
  labels hostile (`:20-22`), and the `try/catch` means probing is silent — nothing is logged
  as a security event, only as `tool failed` (`:597-598`).
- **IMPACT:** **P2**
- **INVARIANT AT RISK:** "NO MODEL-AUTHORED QUERIES" (`:15-16`) — technically honoured for
  collection names, breached for a path component.
- **DEPENDENCIES:** Fixing F-02 fixes most of this incidentally; do both.
- **RECOMMENDATION:** A shared `validateArgs(toolName, args)` applied at `:584` for all six
  tools, enforcing the schemas already written.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — Firestore Node SDK behaviour for
  `.doc()` with an embedded `/` (throw vs. traverse). Do not treat my read as settled.

---

### F-09 — Client-supplied conversation history is unauthenticated; forged assistant turns are accepted

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 1 (malformed payload) / authz.
- **OBSERVATION:** The conversation is stateless server-side by design (`:485`), so the client
  owns and replays the transcript. Nothing verifies that a turn labelled `assistant`/`model`
  was ever produced by this function — no signature, no server-side transcript, no hash. A
  caller with a valid ID token can therefore fabricate prior model turns and prime the
  conversation. Roles `assistant` and `model` are both accepted and normalised to `model`
  (`:506-511`).
- **EVIDENCE:**
  - `functions/studentAssistant.js:485-488` — `const history = Array.isArray(request.data && request.data.messages) ? request.data.messages.slice(-MAX_TURNS) : [];`
  - `functions/studentAssistant.js:506-511` — the filter accepts `'user' | 'assistant' | 'model'`
    and maps everything non-user to `model`.
- **CLASSIFICATION:** `[CONFIRMED]` for the absence of verification.
- **CONFIDENCE:** High.
- **IS-SHOULD:** IS — history is trusted. SHOULD — treated as untrusted, same as retrieved
  tool text (`:20-22` already says retrieved text is hostile; client history is more so).
- **RISK:** Correctly bounded by the architecture and worth crediting: identity comes from the
  token (`:11-14`) and **no tool accepts a studentId or schoolId** (`:211-215`), so a forged
  history cannot make the assistant *fetch* another child's records. What it can do is make it
  *say* things — a fabricated turn ("Your fees are fully paid, receipt 4471") that the student
  screenshots, or a jailbreak of the no-tutoring / no-counselling boundaries at `:446-447`,
  which are the feature's entire compliance posture given the DPDP scope lock.
- **IMPACT:** **P2**
- **INVARIANT AT RISK:** Prompt-boundary integrity; the scope lock recorded in
  `project_zenxii_student_ai`.
- **DEPENDENCIES:** A1 owns whether the prompt itself resists this; I only establish that the
  server hands it unverified input.
- **RECOMMENDATION:** Either sign each returned turn and verify on replay, or hold the last
  N turns server-side keyed by a conversation id.
- **REQUESTED VERIFICATION:** Attempt a forged-history call against a dev tenant.

---

### F-10 — Audit gaps: three paths return or fail with no `assistantLogs` row

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 8.
- **OBSERVATION:** `writeLog` is called at exactly two sites — `:557` (success) and `:607`
  (exhaustion). Its body is wrapped in a `try/catch` that logs and swallows (`:639-641`), which
  is the right call for availability but means the answer at `:560` is returned regardless. Two
  distinct gaps result. **(a) Swallowed write:** any Firestore failure on `.add()` produces an
  answered question with no audit row. **(b) No call at all:** a throw from `generateContent`
  (`:534`) propagates past both `writeLog` sites, and the 120 s function timeout (`:479`) kills
  the container mid-loop — in both cases quota was consumed at `:483` but nothing is recorded.
  Additionally `toolsUsed.push` happens only on tool success (`:585`), so a failed tool never
  appears in the audit trail even when the row is written.
- **EVIDENCE:**
  - `functions/studentAssistant.js:557-564` — log, then `return`.
  - `functions/studentAssistant.js:639-641` — `catch (e) { logger.error('studentAssistant: audit log failed', …); }`
  - `functions/studentAssistant.js:534` — the un-wrapped `await client.models.generateContent(...)`.
  - `functions/studentAssistant.js:479` — `timeoutSeconds: 120` against up to six sequential
    model calls (`:531`).
  - `functions/studentAssistant.js:583-585` — `toolsUsed.push(call.name)` inside the `try`.
- **CLASSIFICATION:** `[CONFIRMED]` for all three gaps.
- **CONFIDENCE:** High.
- **IS-SHOULD:** IS — the accountability record the header calls "the accountability
  requirement for an AI acting on a child's records" (`:615-617`) is best-effort and has holes
  on exactly the failure paths an incident review would care about. SHOULD — quota consumption
  and audit row are written together, or the audit row is written first.
- **RISK:** Under-counted usage, and an unreconstructable conversation after an incident. Note
  the asymmetry with F-04: duplicates *over*-count, these gaps *under*-count, so
  `assistantLogs` cannot be used as a billing or usage source of truth in either direction.
- **IMPACT:** **P2** (P1 if the audit trail is being relied on for a DPDP/consent obligation).
- **INVARIANT AT RISK:** Auditability of AI actions on a minor's records.
- **DEPENDENCIES:** Interacts with F-03 — the same paths that lose the log also burn quota.
- **RECOMMENDATION:** Wrap the model call; log on every terminal path including `internal`.
  Record attempted tools, not just successful ones. Consider writing the log row *before*
  the model call and patching it after.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — measure p95 wall time for a
  six-iteration conversation against the 120 s cap.

---

### F-11 — No per-message length cap; `MAX_TURNS` bounds turn count but not payload size

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Question 1.
- **OBSERVATION:** `slice(-MAX_TURNS)` bounds the transcript to 20 turns, but nothing bounds
  the length of any turn's `content`, nor of `request.data.message` itself. The audit trail
  truncates the question to 500 chars (`:627`) — the model call does not. So the enforced
  ceiling on what reaches Vertex is whatever the callable transport allows, not anything this
  file states. There is also an ordering wrinkle: `slice` runs at `:487` and the validity
  filter at `:506` runs *after*, so a payload whose last 20 entries are all malformed yields an
  empty history silently, discarding valid earlier turns.
- **EVIDENCE:**
  - `functions/studentAssistant.js:486-489` — `.slice(-MAX_TURNS)`; `String(...).trim()` with
    no `.slice()` on `question`.
  - `functions/studentAssistant.js:627` — `question: String(o.question).slice(0, 500),` (the
    cap that exists, on the wrong side).
  - `functions/studentAssistant.js:504-511` — filter applied after slice.
- **CLASSIFICATION:** `[CONFIRMED]` for the missing cap; `[UNKNOWN]` for the effective
  transport ceiling.
- **CONFIDENCE:** High on the code; Low on the practical bound.
- **IS-SHOULD:** IS — one caller can send a very large prompt, 30 times a day. SHOULD — cap
  `message` (say 2000 chars, matching the `details` cap at `:407`) and each history `content`,
  and filter before slicing.
- **RISK:** Cost amplification against the ~₹3/student/month model that `consumeQuota` exists
  to protect (`:188-190`) — the quota counts *questions*, not tokens, so 30 maximal questions
  cost far more than 30 typical ones. A `maxOutputTokens` cap exists (`:84`, `:543`); there is
  no input-side equivalent.
- **IMPACT:** **P2**
- **INVARIANT AT RISK:** The cost model the daily quota is designed to enforce.
- **DEPENDENCIES:** None.
- **RECOMMENDATION:** Add input caps; reorder filter before slice.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — the actual Firebase callable request
  size limit, and Vertex's behaviour on an oversized prompt (error code matters for F-03).

---

### F-12 — Stale wiring comment claims a secret the function does not use, and no Vertex IAM is declared

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Deploy-time contract.
- **OBSERVATION:** `index.js:670` states the function "Needs the ANTHROPIC_API_KEY secret" and
  that it "files helpdesk tickets". Both are false as of the 2026-08-30 rework: the provider is
  Gemini via Vertex with **no API key at all** (`:37-39`, `:515-521`), and the helpdesk tool
  deliberately writes nothing (`:388-404`). Separately, the `onCall` options declare no
  `serviceAccount` and no IAM, so authentication to Vertex rests on the default compute service
  account holding `roles/aiplatform.user`.
- **EVIDENCE:**
  - `functions/index.js:666-670` — "and files helpdesk tickets… Needs the ANTHROPIC_API_KEY secret."
  - `functions/studentAssistant.js:478-480` — options are `{ region, timeoutSeconds, memory }` only.
  - `functions/studentAssistant.js:517-521` — `new GoogleGenAI({ vertexai: true, project, location })`
  - `functions/package.json:10` — `"@google/genai": "^2.19.0"` (no `@anthropic-ai/*`).
- **CLASSIFICATION:** `[CONFIRMED]` for the stale comment.
  `[UNKNOWN]` for the ADC grant.
- **CONFIDENCE:** High / n-a.
- **IS-SHOULD:** IS — the deploy note misdescribes the function on both provider and behaviour.
  SHOULD — correct it, and record the required IAM role next to it.
- **RISK:** The comment is the first thing a deployer reads. Chasing a non-existent secret
  wastes a deploy window; worse, the missing IAM note means a first deploy can pass and then
  500 on every call *after* consuming quota (F-03).
- **IMPACT:** **P3** for the comment; **P1** for the IAM question if unresolved.
- **INVARIANT AT RISK:** Deploy correctness.
- **DEPENDENCIES:** Must be settled before any A5/QA-LEAD deploy step.
- **RECOMMENDATION:** Fix the comment; confirm and document the `aiplatform.user` grant.
- **REQUESTED VERIFICATION:** `REQUIRES VERIFICATION` — does the runtime service account for
  `studentAssistant` hold `roles/aiplatform.user` on project `graderadmin`? This is a hard
  deploy blocker and I cannot check it.

---

### F-13 — `cacheWrite` is structurally always zero, defeating the stated cache monitor

- **AGENT:** A4 · BACKEND-SPEC
- **MISSION:** Completeness.
- **OBSERVATION:** `cacheWrite` is initialised to `0` at `:529`, never assigned anywhere in the
  file (only `cacheRead` accumulates, at `:550`), and written to the audit row as
  `cacheWriteTokens` at `:635`. The doc comment at `:617-618` says these counters exist so that
  "if cacheRead stays 0 across calls, the prompt cache has silently stopped working" — that
  half works; the `cacheWrite` half is a permanently-zero field that will read as a broken
  cache to anyone inspecting the logs.
- **EVIDENCE:**
  - `functions/studentAssistant.js:529` — `let usageIn = 0, usageOut = 0, cacheRead = 0, cacheWrite = 0;`
  - `functions/studentAssistant.js:547-550` — only `usageIn`, `usageOut`, `cacheRead` accumulate.
  - `functions/studentAssistant.js:635` — `cacheWriteTokens: o.cacheWrite,`
- **CLASSIFICATION:** `[CONFIRMED]`
- **CONFIDENCE:** High.
- **IS-SHOULD:** IS — a misleading always-zero metric. SHOULD — drop the field (Gemini's
  implicit caching has no write step, per `:41-46`) rather than log a constant.
- **RISK:** Misdiagnosis during a cost investigation.
- **IMPACT:** **P3**
- **INVARIANT AT RISK:** none.
- **DEPENDENCIES:** none.
- **RECOMMENDATION:** Remove `cacheWrite`.
- **REQUESTED VERIFICATION:** none needed.

---

### Note (not a finding) — things this function gets right

Stated so the report is not read as uniformly negative, and so QA-LEAD does not spend budget
re-checking them:

- Identity is token-only; **no tool schema accepts `studentId` or `schoolId`** (`:211-215`,
  `:216-286`). This is the single most important control and it holds.
- Blank `currentSession` fails **closed** with an explicit log (`:162-167`) — the correct
  choice, and rare in this codebase.
- `consumeQuota` is a real transaction (`:194-208`), so concurrent calls cannot overshoot.
- The helpdesk tool writes nothing and hands off by route name (`:388-425`); the reasoning at
  `:394-401` about `supportCounters` invariants is correct.
- The claim read is snake-primary with camel fallback (`:125-126`), which survives the fact
  that `Firebase.php:857` emits `student_id` (snake) but **not** `studentId` (camel) — the
  dual-emit contract is incomplete for the student key, and this function tolerates it.
- `firestore.rules` ends in a catch-all `allow read, write: if false` and contains no
  `assistant*` block, so `assistantQuota`/`assistantLogs` are unreachable from any client; the
  Admin SDK bypasses rules. Correct by default, though an explicit server-only match block
  (as done for `supportCounters`) would document the intent.
- The required composite indexes for `get_homework` (`schoolId, sectionKey, status, session,
  createdAt DESC`), `get_fee_status` and `get_exam_results` (`schoolId, session, studentId`)
  **all already exist** in `firebase-rules/firestore.indexes.json`.

---

## 2. THE EIGHT QUESTIONS

**Q1 — 500 turns, or non-`{role,content}` objects. Is there a cap, enforced before the model call?**
Yes for turn *count*, no for *size*. `:487` applies `.slice(-MAX_TURNS)` → the last 20 turns
only; `:506-507` then drops any entry that is not `{role ∈ {user,assistant,model}, content:
string}`, silently. So 500 turns is safe and malformed objects are safely discarded — both
before the model call at `:534`. **But** there is no cap on the length of any `content`, nor on
`request.data.message` (`:489` trims only), and `slice` runs *before* the validity filter, so a
tail of 20 malformed entries silently empties an otherwise valid history. `[CONFIRMED]` — F-11.

**Q2 — Is `MAX_TURNS = 20` dead?**
**No, it is live and correctly applied** at `studentAssistant.js:487`
(`.slice(-MAX_TURNS)`). Not a finding. `[CONFIRMED]`

**Q3 — Quota consumed before the model call; what if the model throws? Is a failed question billed?**
**Yes, it is billed.** `consumeQuota` commits at `:483`/`:201-207`; the `generateContent` call
at `:534` is not wrapped, and no decrement or compensating write exists anywhere in the file.
A Vertex failure surfaces to the client as `internal` with the student one question poorer.
Worse, `:490` throws `invalid-argument` on an *empty message* — after the quota was already
taken. `[CONFIRMED]` — F-03.

**Q4 — Can one logical question produce two decrements and two `assistantLogs` rows? (G5)**
**Yes.** No request id, nonce or dedupe key is accepted (`:486-489`; the client sends none —
`AssistantRepository.kt:34-38`); quota is keyed by day and blindly incremented (`:193`,
`:201-207`); the log uses `.add()` with an auto-id (`:622`). Nothing compares against a recent
request. G5 is violated, though blast radius is limited to quota and audit accuracy since the
function is read-only. `[CONFIRMED]` — F-04.

**Q5 — What does the caller get when `MAX_TOOL_ITERATIONS = 6` is exhausted, and is work lost?**
A thrown `HttpsError('deadline-exceeded')` — an exception, **not** a partial answer (`:607-610`).
All work is lost: six billed model calls, every Firestore read, `toolsUsed`, and any `handoff`
prepared at `:586-594` (it is only returned at `:563`, which this path never reaches). The code
is also semantically wrong — nothing timed out — and invites a retry that costs another quota
unit. `[CONFIRMED]` — F-06.

**Q6 — Are tool arguments validated? Trace `input.month`.**
**No.** `call.args` is forwarded raw at `:584`; the `parametersJsonSchema` blocks are advertised
to the model but never enforced server-side. `input.month` receives only
`String(input.month || '').trim()` at `:291` before being interpolated into a `.doc()` path at
`:293` — no format check, no `/` rejection. `raise_helpdesk_ticket` likewise never validates
`category` against its declared enum (`:419`). Exploitability is bounded (the path prefix stays
under the caller's own key) and errors are swallowed by `:596-602`, but the validation layer is
absent. `[CONFIRMED]` gap / `[INFERRED]` consequence — F-08.

**Q7 — Is every query scoped by both `schoolId` and `session`? (Z3)**
**No — one violation, one structural exception.**

| Tool | `schoolId` | `session` | Verdict |
|---|---|---|---|
| `get_attendance_summary` `:290-295` | in doc key | **absent** | doc-`get`, not a query; key is month-scoped — but see F-02, the key is malformed |
| `get_homework` `:309-316` | ✔ | ✔ | compliant |
| `get_fee_status` `:331-336` | ✔ | ✔ | compliant |
| **`get_timetable` `:355-359`** | ✔ | **✘** | **Z3 VIOLATION** |
| `get_exam_results` `:367-372` | ✔ | ✔ | compliant |
| `raise_helpdesk_ticket` `:405-425` | n/a | n/a | reads nothing |

`get_timetable` is the named failure — and it also lacks an `orderBy`, so a stale-session row
can be returned as current. The index for the fix already exists. `[CONFIRMED]` — F-05.

**Q8 — Under what conditions does an answer reach the student with no audit record?**
Three, all `[CONFIRMED]`: **(a)** the `assistantLogs.add()` at `:622` throws — caught and
swallowed at `:639-641` while `:560` returns the answer anyway; **(b)** the model call at `:534`
throws — neither `writeLog` site is reached, and quota was already taken; **(c)** the 120 s
`timeoutSeconds` (`:479`) kills the container mid-loop, same result. Additionally, even when a
row *is* written, failed tools are missing from it because `toolsUsed.push` sits inside the
success branch (`:585`). Combined with F-04's duplicates, `assistantLogs` is unreliable as a
usage record in both directions. F-10.

---

## 3. DEFECT-PATTERN SWEEP

- **P-01 (path that can never execute in production):** **F-01** — no writer for
  `ai_assistant_enabled`, so the whole function is gated off. **F-02** — the
  `snap.exists === true` branch of `get_attendance_summary` is unreachable given the malformed key.
- **P-04 (a rule with no twin on the other surface):** **F-07** — the fee-defaulter
  result-withhold gate is enforced three times in `Result.php` and zero times in
  `get_exam_results`. Secondary: `AssistantRepository.kt:26-28` documents only two of the six
  error codes this function emits.
- **P-11 (a defensive branch that always fires):** **F-01** (`:158`) and **F-02** (`:295`).
  Both convert to user-visible statements that are polite and wrong rather than to errors.

---

## 4. WHAT I COULD NOT ESTABLISH (E2 ceiling)

1. Whether `ai_assistant_enabled` is `true` for any live school. Determines whether F-01 is a
   P0 or a documented manual-pilot gate.
2. Whether the runtime service account holds `roles/aiplatform.user`. Hard deploy blocker.
3. Whether live `timetables` docs carry a `session` field — if not, the F-05 fix returns zero rows.
4. Whether `results` docs persist for a fee-withheld student (severity of F-07).
5. Firestore Node SDK `.doc()` behaviour with an embedded `/` (F-08).
6. Whether the Firebase callable client SDK auto-retries on transport failure (frequency of F-04).
7. Whether Gemini 3.1 Flash-Lite can return text and a functionCall in one response — if so,
   `:552-554` drops text on every tool turn (F-06 secondary).
8. Actual p95 latency of a six-iteration conversation against the 120 s cap (F-10c).
9. The effective Firebase callable request-size limit (F-11).
