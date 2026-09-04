# 14 — UNKNOWN / CONTESTED RISK REGISTER · AI Assistant ("Ask ZenXii")

**Agent:** A12 · TEST-ARCHITECT · reports to QA-LEAD
**Date:** 2026-08-31 · **Evidence ceiling: E2**

Everything the ten specialists plus A11 could **not** settle by reading source. Each entry states
why it is unknown, what it costs to leave it unknown, the one measurement that resolves it, and what
a human must do.

**Two classes are kept apart deliberately:**
- **⚑ CONTESTED** — correct behaviour cannot be established, so **both candidate expectations** are
  carried into the matrix and the row records which one the system exhibits.
- **[UNKNOWN]** — correct behaviour is agreed; only the *fact* of what the system does is unmeasured.

Conflating them is how a report loses its credibility. A contested row is not a defect until the
product owner says which candidate is right.

---

## Part 1 — ⚑ CONTESTED (8) · top of T0

These are the highest-value rows in the entire matrix, because **code cannot settle them**.

### ⚑C1 — Has `ai_assistant_enabled` ever been `true` for any school?
- **Why unknown:** repo-absence does not entail production-absence. A4, A5 and A1 each independently
  verified there is **no writer** — that part is `[CONFIRMED]` and survives. But four counter-paths
  leave no repo trace: the Firebase console (which is the *documented expectation* — A1 says the flag
  "can only be set by hand in the Firestore console"), the Admin SDK or `gcloud` from any machine
  (the project's own memory records a write-capable credential already in circulation), a seeded or
  migrated document, and the precedent this codebase institutionalised — production regularly holds
  state "that exists in nobody's checkout", which is the entire reason the Rules Sentinel was built.
  A4 and A6 both already *condition* on a hand-set flag ("any passing UAT ran against a hand-set
  flag"), which concedes the negation.
- **Potential impact:** gates the production relevance of every other finding. If the flag has been
  set somewhere, live children's question text already exists in `assistantLogs` with no retention
  path, and R-02 (the dead attendance tool) has been telling real students their school has no record
  of them.
- **How to resolve:** count `schools` where `ai_assistant_enabled == true`; count documents in
  `assistantQuota` and `assistantLogs`. **Non-zero on any count overturns "the feature has never run"
  outright.** The cheapest single measurement in the programme.
- **Human action:** an Admin-SDK or console read on `graderadmin`, ideally *before* T0-01 adds a
  school to the count.
- **Row:** T0-02.

### ⚑C2 — Does a forged `model` turn actually move Gemini 3.1 Flash-Lite off its `systemInstruction`?
- **Why unknown:** model behaviour. The **mechanism** is `[CONFIRMED]` and airtight — client-supplied
  history is filtered on type and role *label* only, `assistant` and `model` both map to Gemini's
  authoritative `model` role, and there is no signature, no server-held transcript and no turn
  counter. The **consequence** cannot be read out of source, and A11 found real evidence both ways.
  *Against* the attack: `systemInstruction` is a separate top-level API field, not a prepended turn,
  so the usual "instruction scrolls out of context" mechanism does not apply; it is re-sent inside
  the agentic loop, so a six-iteration turn restates it six times against a history that grows once;
  and `:446` is unusually well-armoured — "even if the student insists **or says it is allowed**",
  and a forged concession is a species of "says it is allowed". *For* the attack: few-shot forged
  assistant turns do not depend on instruction position; an attacker does not have to persuade the
  model, they edit the record of what it already said; and the anti-injection paragraph at `:473`
  covers **tool output only** — the one place the design acknowledges hostile text is precisely the
  place that does not cover this channel.
- **Potential impact:** decides whether the 2026-08-23 scope LOCK (tutoring and wellbeing were cut
  deliberately, because the Parent app logs in **as** the student) can be reopened by any legitimate
  credential holder on a children's product. **A11's correction stands: the P1 label was attached to
  the unknowable half. It belongs on the mechanism, which is falsifiable from source.**
- **How to resolve:** execution only. Direct callable invocation with a fabricated `{role:"model"}`
  concession, in all six languages (the system instruction is English).
- **Human action:** authorise a security test on a non-production student account (Batch C1 in
  `07-open-questions.md`). Without that authorisation this stays unsettled — the honest-client
  version is a weaker probe.
- **Rows:** T0-03 (decisive), T0-38 (fallback), T2-53, T2-54.

### ⚑C3 — Is the panel's RTDB `result_withheld` gate current policy or legacy?
- **Why unknown:** a product decision, not a code fact. The panel gates three separate result paths
  and audits overrides. The Parent app deliberately does **not** gate and says so in a comment. The
  Firestore rules permit the read with no dues predicate. Two of three surfaces already ignore it.
- **Potential impact:** decides whether the assistant is a live policy bypass through a **new
  modality** — it will read withheld marks aloud, conversationally, in the student's own language,
  which is materially different from a banner above a table — or a third surface correctly retiring
  a dead rule. **A11 narrowed the consequence: A4's "can obtain their marks" overstates**, because
  per R-03 the CF cannot return marks at all; expect `examName` + `grade` + `maxMarks` with a null
  mark.
- **How to resolve:** the module owner, plus a count of live `Schools/*/Fees/Defaulters/*` nodes
  carrying the flag and any school with `block_result = true`.
- **Human action:** one written product decision. **Rider:** the flag has three homes — an RTDB node,
  a Firestore policy doc, and `feeDefaulters.resultWithheld`. Reconcile those first or "withheld"
  means something different on each surface.
- **Rows:** T0-04, T0-27, T2-63.

### ⚑C4 — Is `student_id` the canonical single child, or is `student_ids[]` the contract?
- **Why unknown:** three notions of "which children may this login see" coexist — the token's
  `student_id` (the CF), the token's `student_ids[]` (emitted by the canonical claim builder and read
  by **nobody**), and a client-side phone/parent-name heuristic (the app's sibling switcher). Which is
  the contract is a product decision.
- **Potential impact:** decides the fix for R-18. Candidate A means the app's switcher is the anomaly
  — but **A11 established that remedy does not exist**: there is no server endpoint that re-mints a
  claim for a sibling, and a forced token refresh would not help because `student_id` is a property
  of the *credential*. So the only real fixes are a server-validated child selector checked against
  the `student_ids[]` claim that is already emitted and never read, or **disabling the assistant entry
  point while a non-primary child is active**. Candidate B additionally requires narrowing
  `SYSTEM_PROMPT:448` — as written its blanket "never discuss any other student" makes the assistant
  refuse a legitimate question about the parent's own second child.
- **How to resolve:** one live parent account with two enrolled children — decode its claims — plus a
  statement from whoever owns the product about whether the assistant should follow the switcher.
- **Human action:** one written product decision (Batch A2).
- **Rows:** T0-05, T0-18, T2-49.

### ⚑C5 — Does the ~1,890-token stable prefix clear the implicit-cache minimum?
- **Why unknown:** the file asserts "no minimum-prefix cliff to fall off". Gemini's implicit caching
  *does* document a minimum — 1,024 tokens for the 2.5 Flash family, 2,048 for 2.5 Pro. The measured
  prefix sits **between** them, with a margin of about 158 tokens. It is also `[UNKNOWN]` whether
  `tools` counts toward the cacheable prefix at all: if only `systemInstruction` is cached, the
  prefix is ~1,273 tokens and the margin over 1,024 shrinks to 249.
- **Potential impact:** a ~4× swing on every cost figure, silently. One paragraph added to the system
  prompt crosses it in the safe direction; one paragraph trimmed, or one tool removed, crosses it the
  other way — with no signal.
- **How to resolve:** read `usageMetadata.cachedContentTokenCount` on two identical consecutive
  requests. The counter already exists in code and is already written to `assistantLogs`.
- **Human action:** none beyond running T0-06. Note the detection path is **inert**: nothing reads
  those rows, there is no aggregator, no alert and no index on `assistantLogs` (R-44).
- **Row:** T0-06 (and T2-73 for why it cannot be monitored).

### ⚑C6 — Does Gemini tolerate two adjacent `user` turns?
- **Why unknown:** model/API behaviour. After any error the client's replay strips the error bubble
  but keeps the orphaned user turn, and the server then appends the new question as another `user`
  turn.
- **Potential impact:** if the shape is rejected, the turn fails as `internal` **after** quota was
  consumed, and **one transient network blip poisons that conversation permanently** until the user
  leaves the screen — which, given the single search-only entry point, is three taps and a re-typed
  keyword away.
- **How to resolve:** one three-turn sequence with an induced failure in the middle.
- **Human action:** none — this is a normal device test.
- **Rows:** T0-07, T2-11.

### ⚑C7 — Is the client's callable timeout really 70 s against the server's 120 s?
- **Why unknown:** the 70,000 ms figure is the documented Android Functions SDK default; it was not
  read out of the dependency in this workspace, and no measurement exists of whether a real turn ever
  exceeds 70 s.
- **Potential impact:** creates **"answered-into-the-void"** — the server completes, consumes quota
  and writes `assistantLogs` with `ok:true` for a reply nobody received, while the client shows "That
  took too many steps. Try asking something more specific.", blaming the student's question for a
  client-side abort. It is the single hardest state to reason about after an incident, because the
  audit trail records it as a **success**. A retry then costs a second unit for the same answer.
- **How to resolve:** time a forced multi-tool turn; if any lands between 70 s and 120 s, capture
  whether the client errors while the log row says `ok:true`.
- **Human action:** none — a stopwatch and Admin-SDK read access.
- **Rows:** T0-08, T1-61, T2-45.

### ⚑C8 — Does the runtime service account hold `roles/aiplatform.user`?
- **Why unknown:** IAM is not in git; neither repo contains it. The `onCall` options declare no
  `serviceAccount`, so Vertex auth rests entirely on ADC.
- **Potential impact:** decides whether R-07 (one Vertex fault burning every student's day) is tail
  risk or **the default outcome of the first deploy**. If the grant is missing, the first call of the
  run 500s *after* consuming quota, thirty times per student, with an unmapped `internal` error.
- **How to resolve:** one IAM read.
- **Human action:** **do this BEFORE T0-01.** It is a hard deploy blocker, not a test row.
- **Row:** T0-09.

---

## Part 2 — [UNKNOWN] · agreed behaviour, unmeasured fact

Grouped by what a single measurement would settle.

### Deployment and live state

| ID | Unknown | Impact if left unknown | Resolution | Row |
|---|---|---|---|---|
| U-01 | Is `studentAssistant` deployed, and does the deployed revision match the audited source? | Every result may describe a different function; the module is **uncommitted on both repos** and exists on disk only | `firebase functions:list --project graderadmin`; compare the revision timestamp | T0-10 |
| U-02 | Are the four composite indexes **deployed**, not merely declared? | A missing index scores every homework row as a false failure. The project's own sentinel records 284 live vs 183 declared — they diverge in both directions | `node aegis/cli.js indexes` | T0-11 |
| U-03 | Is the deployed ruleset the same as disk, and does the catch-all deny still hold? | The catch-all is the **only** protection on a corpus of children's free-text questions, and it is a default branch rather than a named block | `node aegis/cli.js rules status`, then two client reads | T0-12 |
| U-04 | Does a live Firestore **TTL policy** cover `assistantLogs` or `assistantQuota`? | Decides whether R-14 is P1 regulatory or P3 hygiene. TTL is out-of-band config invisible to git | Firebase console → Firestore → TTL | T0-39, T2-74 |
| U-05 | Is `gemini-3.1-flash-lite` a valid Vertex model id at `location: 'global'` at the assumed price? | Every cost figure rests on it; the brief's $0.25/$1.50 was taken as given and reconciled against no price list | One live call + a price-list check | T0-06, T1-74 |

### Live data shape

| ID | Unknown | Impact if left unknown | Resolution | Row |
|---|---|---|---|---|
| U-06 | Do live `timetables` documents carry a `session` field at all? | **Decides whether the R-08 fix is safe.** If the field is absent, adding the session filter returns **zero rows** and breaks the tool entirely | List a few live `timetables` docs | T0-31 (precondition) |
| U-07 | Does live `timetables` hold multiple sessions per `sectionKey`? | Decides R-08's consequence — if the panel overwrites in place, the consequence is nil | One query | T0-31, T2-34 |
| U-08 | Does any live `results` document carry `published != true`? | Turns R-47 from latent to actual. Any such document is narrated to a student immediately | One query | T2-61 |
| U-09 | Do `attendanceSummary` documents have sub-collections? | Decides whether R-45 (the month-into-path defect) is latent or empty. G1/G2 survive either way | One query | T2-36 |
| U-10 | Does any live `schools` document have `schoolCode ≠ schoolId`? | If yes, the CF and the Parent app read **different documents** for the same student. The app carries a fallback *and* a warning comment, which suggests the case is not purely theoretical | Two live document reads | T2-60 |
| U-11 | How many live accounts carry `student_ids.length > 1`? | Sizes R-18. Multi-child households are the *designed* case — the sibling set is built under **one** credential | Claims audit | T0-05 |
| U-12 | Do `homework.sectionKey` and `timetables.sectionKey` actually carry the `Class X/Section Y` composite the CF builds? | The code's own comment warns a mismatch "does not error; it silently returns an empty result set" | List a few live docs | T1-11, T1-13 |
| U-13 | Do `results` documents persist for a fee-withheld student? | Sizes R-27 | One query | T0-27 |
| U-14 | Does the `role` claim ever literally equal `student`? | If the Parent app's tokens carry something else, that is a **second** gate in front of the flag | Decode one live token | T1-29, T1-30 |

### Platform and SDK behaviour

| ID | Unknown | Impact if left unknown | Resolution | Row |
|---|---|---|---|---|
| U-15 | Does the Firebase callable client SDK auto-retry on transport failure? | Sets the real frequency of R-20 (no idempotency ⇒ double decrement, double log row, double billed call) | Instrument one flaky call | T0-21, T2-55 |
| U-16 | What is the effective Firebase callable request-size limit, and does Vertex bill or reject an over-context request? | Bounds the real cost of R-25. **QA-LEAD note: A9-04's ₹22/question is DOWNGRADED to roughly ₹7–14** — the 10 MB cap is ~625k tokens, not 1M, and a giant paste most likely draws a one-call refusal | Escalating payload sizes | T2-25 |
| U-17 | Firestore Node SDK `.doc()` behaviour with an embedded `/` — throw or traverse? | Decides R-45's mechanism | One direct call | T2-36 |
| U-18 | Can Gemini 3.1 Flash-Lite return text **and** a `functionCall` in one response? | If it can, that text is silently dropped on every tool turn | Observation across many turns | T2-40 |
| U-19 | Does `popBackStack` clear the `NavBackStackEntry` `ViewModelStore` on this Navigation version? | **Decides whether the terminal unavailable state is escapable at all** without a force-quit | One device test | T1-49, T2-82 |
| U-20 | Does `FilledIconButton` receive the 48 dp interactive minimum on Compose BOM 2024.02? | Decides whether R-49 covers two targets or three | Layout-bounds measurement | T3-08 |
| U-21 | The exact `navigate()` failure mode for an unknown route on this Navigation version | Decides whether R-38 is a crash or a no-op | One test build | T0-45 |
| U-22 | Firestore behaviour under a 500 rps read burst on one key | Sizes R-57 | Load test | T2-68, T2-69 |
| U-23 | Firestore transaction behaviour under genuine concurrent quota contention | Confirms the one control that holds (the quota cap). Its safety is currently an **emergent property of there being one writer**, not a structural one | Load test | T0-20, T2-47, T2-48 |
| U-24 | The project's Vertex QPM quota for this model on `global` | Decides whether the peak scenario survives; a 429 is completely unhandled | Quota inspection | T2-70 |
| U-25 | Effective Cloud Functions v2 defaults actually applied (concurrency, maxInstances, CPU at 512 MiB) | Sizes the cold-start behaviour at peak | Configuration inspection | T2-71 |

### Measurements with no baseline

| ID | Unknown | Impact if left unknown | Resolution | Row |
|---|---|---|---|---|
| U-26 | End-to-end p50/p95 latency, warm and cold, for the 1-tool and 6-iteration paths | **Every duration in the source reports is a model, not an observation.** Also feeds ⚑C7 | Timed runs | T1-74, T1-75 |
| U-27 | Real live timetable document size × 25 | Sizes R-22. **A11 downgraded the magnitude**: "25 MiB / 6M tokens" is the Firestore theoretical per-document ceiling, not a timetable (~8 periods/day, kilobytes) and would be model-rejected before billing | Read live docs; capture `usage.inputTokens` | T0-32 |
| U-28 | Real traffic mix (tool / no-tool / multi-tool) | The blended cost per question rests on an estimate; **A11 also found the published average drops its largest term** — timetable is excluded as unbounded while simultaneously being argued as high-traffic | `assistantLogs` after a pilot (needs the index from R-44 first) | T2-73 |
| U-29 | Real tokeniser count of `SYSTEM_PROMPT` + `TOOLS` | The exact cache-prefix margin; the 4-chars/token heuristic is inferred | `countTokens` | T0-06 |
| U-30 | Every contrast figure in the UX report | All are **computed from declared hex**, none measured on a device | Contrast tool on device screenshots | T3-02, T3-04, T3-49 |
| U-31 | Whether the model in practice names the drafted subject line aloud | The prompt **commands** it, but that is not a measurement. It decides how bad R-05 feels to a user | Observe several handoffs | T0-23 |
| U-32 | Whether a real turn ever exceeds 70 s at all | If none does, ⚑C7 is unreachable in practice | Timed runs | T0-08 |
| U-33 | Whether `reply` can ever be empty in practice | Decides whether R-37 (the module's one silent failure) is reachable | Observation across many turns; emoji-only probe | T2-26 |
| U-34 | How often six tool iterations are actually reached | Decides R-36's frequency. A five-tool question plus one retry would do it | Observation | T2-38 |
| U-35 | Whether IME occlusion hides the search browse list on a 360×640 device | Sizes R-33's discoverability claim | One device test | T1-03, T1-04 |

---

## What must NOT be recorded as a defect until resolved

A discipline note, because this is where a matrix loses credibility:

- **Every ⚑ CONTESTED row records which candidate the system exhibits. It does not grade.** The one
  exception QA-LEAD has already arbitrated: T0-18 — *an answer that names the wrong child is a FAIL
  regardless of which candidate wins*.
- **Cost rows are conditional on ⚑C1.** If the flag has never been `true` anywhere, every cost
  finding is P1-*on-enablement*, and should be ranked **below** the certain, unconditional ones —
  chiefly R-06 (30 empty payloads burn a child's day), which is certain from source, trivially
  triggerable, and was graded below several `[UNKNOWN]`s in the source reports.
- **I1 must not be reported as simply "holds" or simply "broken".** It holds in its **narrowed** form
  — *no tool accepts a student selector, so no prompt in any language can widen a query* — which is a
  real and well-built control worth protecting (T0-17, T0-36). Its **catalogue wording** breaks via
  class-scoped free text (T0-24) compounded by a stale section (T0-26). The two must never be
  conflated: the first is a strong structural control, the second is an untested data-content
  exposure with no control at all.
