# A9 · PERF-ANALYST — `studentAssistant` performance, scale and cost

**Targets:**
- `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/studentAssistant.js` (649 lines)
- `/Users/yuggi/AndroidStudioProjects/ZenXII_Parent/app/src/main/java/com/schoolsync/parent/ui/assistant/AssistantViewModel.kt` (123 lines)
- Supporting: `.../data/repository/AssistantRepository.kt`, `.../ui/assistant/AssistantScreen.kt`,
  `~/Desktop/Zennxii_adminPanel/firebase-rules/firestore.indexes.json`

**Evidence ceiling: E2.** Nothing was executed against Vertex, Firestore or a device. Every
number below is a **model with stated assumptions**, not an observation. The only measurements
in this document are static ones taken from the source itself (character counts, cap constants,
index declarations); those are marked `[MEASURED-STATIC]`.

**Date:** 2026-08-31 · **Production frame:** 1,000 students · 30 sections · 220 school days ·
peak = the hour after results publish or fees fall due.

---

## 0. The two static measurements everything rests on

Taken by loading the module's own `_test` export and counting — no network, no deploy:

| Quantity | Measured | Task brief said | Citation |
|---|---|---|---|
| `SYSTEM_PROMPT` | **5,090 chars / 921 words → ~1,273 tokens** | ~1,300 | `studentAssistant.js:440-473` |
| `TOOLS` (6 declarations, serialised) | **2,466 chars → ~617 tokens** | ~700 | `studentAssistant.js:216-286` |
| **Stable cacheable prefix** | **~1,890 tokens** | ~2,000 | sum of the two |

`[MEASURED-STATIC]` on the character counts. `[INFERRED]` on the token conversion — 4 chars/token
is the standard English heuristic; 921 words × ~1.35 tokens/word = ~1,240 corroborates it.
The real tokeniser count `REQUIRES VERIFICATION` (row V-1). **This ~1,890 figure is load-bearing
for finding A9-01 below and it is uncomfortably close to a threshold.**

### Every cap the code declares — and every one it does not

| Cap | Value | Citation | Bounds what |
|---|---|---|---|
| `MAX_TOOL_ITERATIONS` | 6 | `:81` | model round-trips per question |
| `MAX_TURNS` | 20 | `:82` | **messages** (not turns) replayed — see A9-04 |
| `DAILY_QUOTA` | 30 | `:83` | questions per student per UTC day |
| `MAX_OUTPUT_TOKENS` | 1024 | `:84` | output tokens **per call**, not per question |
| `QUERY_LIMIT` | 25 | `:85` | rows per tool query |
| — | **absent** | — | per-message character/token cap |
| — | **absent** | — | total input-token cap per request |
| — | **absent** | — | tool-result size cap (see A9-02) |
| — | **absent** | — | per-school or project spend cap / budget alarm |
| — | **absent** | — | `minInstances` / `maxInstances` / `concurrency` |
| — | **absent** | — | TTL on `assistantLogs` or `assistantQuota` |

---

## 1. FINDINGS

---

### A9-01 — The whole cost model hinges on a ~1,890-token prefix clearing an implicit-cache minimum, and nothing verifies it

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q3 — implicit caching. Token spend as a performance characteristic.
- **OBSERVATION:** The header comment at `:41-46` states the economics outright: implicit
  caching gives "a 90% discount on repeated prefixes, automatic, no storage charge, nothing to
  configure. Unlike Anthropic's manual cache there is no minimum-prefix cliff to fall off."
  The second half of that sentence is the risk. Gemini's implicit caching does have a
  **minimum cacheable prefix** (documented at 1,024 tokens for the 2.5 Flash family, 2,048 for
  2.5 Pro). The measured stable prefix here is **~1,890 tokens** — between those two numbers.
  If `gemini-3.1-flash-lite` carries a 2,048-token minimum, **implicit caching never fires at
  all**, silently, and every cost figure in §2 multiplies by roughly 4×. The margin is ~158
  tokens: one paragraph added to `SYSTEM_PROMPT` would cross it in the safe direction; one
  paragraph trimmed, or one tool removed, could cross it the other way.
- **EVIDENCE:**
  - `studentAssistant.js:41-46` — the "no minimum-prefix cliff" claim.
  - `:440-473` + `:216-286` — measured 5,090 + 2,466 chars = ~1,890 tokens.
  - `:80` — `const MODEL = process.env.VERTEX_MODEL || 'gemini-3.1-flash-lite';`
  - `:550` — `cacheRead += u.cachedContentTokenCount || 0;` — the counter exists.
  - `:620-642` `writeLog` persists it to `assistantLogs.usage.cacheReadTokens`.
- **CLASSIFICATION:** `[CONFIRMED]` that the prefix measures ~1,890 tokens and that the source
  comment asserts no minimum exists. `[UNKNOWN]` whether `gemini-3.1-flash-lite`'s implicit-cache
  minimum is 1,024, 2,048, or something else, and `[UNKNOWN]` whether `tools` counts toward the
  cacheable prefix at all — if only `systemInstruction` is cached, the prefix is **1,273 tokens**
  and the margin over 1,024 shrinks to 249.
- **CONFIDENCE:** High that the risk exists; the resolution is a single measurement.
- **IS-SHOULD:** IS — a 4×-to-10× cost swing rests on an undocumented threshold with a <10%
  margin, and the code's own comment asserts the threshold does not exist. SHOULD — the prefix is
  padded to comfortably clear the largest plausible minimum (≥2,500 tokens), **or** an explicit
  cached-content handle is created so the discount is contractual rather than emergent.
- **RISK:** The `₹3–9/student/year` design target is stated for the *cached* case. Uncached, the
  ceiling bill is ~₹81,000/month/school (§2.4) instead of ~₹29,500.
- **IMPACT:** **P1** — not a correctness break, but the single largest lever on whether this
  feature is economically shippable, and it is currently unverified.
- **INVARIANT AT RISK:** The cost model in `project_zenxii_student_ai`.
- **DEPENDENCIES:** Compounds A4's F-13 (`cacheWrite` is structurally always 0). Note the
  detection path *does* exist and is correct — `cacheRead` staying 0 across `assistantLogs` rows
  is exactly the tell described at `:617-618`. But nothing **reads** those rows: there is no
  aggregator, no alert, and no index on `assistantLogs` (see A9-06).
- **RECOMMENDATION:** Before any pilot, issue one request and read back
  `usageMetadata.cachedContentTokenCount`. If it is 0 on a second identical request within the
  TTL window, pad the prefix or switch to explicit caching. Then wire a daily check on
  `assistantLogs` that alerts when the cache-hit ratio drops.

---

### A9-02 — `get_timetable` returns whole raw documents into the prompt: the one tool with no field projection, and the largest uncontrolled token source

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q1/Q5 — token growth and read amplification.
- **OBSERVATION:** Five of the six tools project a fixed, small field set before returning
  (`get_homework` returns 4 fields, `get_fee_status` 6, `get_exam_results` 6). `get_timetable`
  does not. It returns up to 25 **complete Firestore documents, every field**:

  ```js
  periods: q.docs.map((d) => d.data() || {}),
  ```

  Whatever a timetable document carries — periods array, teacher ids, room codes, audit fields,
  `createdAt`/`updatedAt`, denormalised class metadata — all of it is serialised into a
  `functionResponse` and sent to the model, and then re-sent on every subsequent iteration of the
  same request. A second, smaller instance of the same shape: `get_attendance_summary` returns
  `dayWise` verbatim (`:301`), an unbounded month map, again with no projection or size cap.
  `QUERY_LIMIT = 25` bounds the **row count** and nothing bounds the **row size**.
- **EVIDENCE:**
  - `studentAssistant.js:360-363` — the unprojected map, quoted above.
  - `:301` — `dayWise: d.dayWise ?? null,` returned raw.
  - Contrast `:318-326`, `:337-348`, `:373-384` — all three project explicitly.
  - `:85` — `const QUERY_LIMIT = 25; // rows any one tool may return` — the comment says *rows*.
- **CLASSIFICATION:** `[CONFIRMED]` that no projection exists. `[UNKNOWN]` the actual live
  timetable document size — `REQUIRES VERIFICATION` (row V-3). A Firestore document may reach
  1 MiB; 25 of them is 25 MiB, ≈ 6M tokens, far beyond any context window.
- **CONFIDENCE:** High on the code shape; the magnitude is entirely unmeasured.
- **IS-SHOULD:** IS — a timetable question's input token count is a function of whatever fields
  the panel happens to write, and a schema change in the Timetable module silently changes this
  feature's bill with no code change here. SHOULD — project the same handful of fields the other
  five tools do (day, period, subject, time), matching the note already returned at `:362`.
- **RISK:** Timetable is one of the four suggestion chips shown on the empty screen
  (`AssistantScreen.kt:268-274`), so it will be among the most-asked questions, not a rare path.
- **IMPACT:** **P1** — direct, unbounded, and on a high-traffic path.
- **INVARIANT AT RISK:** The bounded-cost premise behind `DAILY_QUOTA`.
- **DEPENDENCIES:** Interacts with A4's F-05 (`get_timetable` is also the one tool missing the
  session filter). The fix for both is the same rewrite of `:352-364`.
- **RECOMMENDATION:** Project fields; add a byte cap on every `functionResponse` payload
  (truncate + tell the model it was truncated) as a structural backstop for all six tools.

---

### A9-03 — The client's 70s callable timeout is shorter than the function's 120s: the user is told it failed while it keeps running and keeps billing

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q8 — the 120s timeout vs the 6-iteration loop.
- **OBSERVATION:** The function declares `timeoutSeconds: 120` (`:479`). The client calls it with
  no timeout override:

  ```kotlin
  Firebase.functions.getHttpsCallable(CALLABLE).call(payload).await()
  ```

  The Firebase Android Functions SDK's `HttpsCallableReference` defaults to **70 seconds**. There
  is a 50-second window in which the client has already thrown `DEADLINE_EXCEEDED` — which
  `AssistantViewModel` maps to a "took too long" message (`:107-108`) — while the container is
  still executing, still issuing Vertex calls, and still being billed for them. The user's quota
  was consumed at `:483`, before any of this.
- **EVIDENCE:**
  - `functions/studentAssistant.js:479` — `{ region: 'us-central1', timeoutSeconds: 120, memory: '512MiB' }`
  - `AssistantRepository.kt:41-44` — the call, with no `.withTimeout(...)`.
  - `ZenXII_Parent/app/build.gradle.kts:186,193` — `firebase-bom:32.7.4`, `firebase-functions-ktx`.
  - `AssistantViewModel.kt:107-108` — `Code.DEADLINE_EXCEEDED -> assistant_too_long`.
  - `studentAssistant.js:483` — `await consumeQuota(...)` precedes everything.
- **CLASSIFICATION:** `[CONFIRMED]` that the function is 120s and the client sets no timeout.
  `[INFERRED]` that the SDK default is 70s — this is the documented default for the Android
  Functions SDK but was not read out of the dependency here. `REQUIRES VERIFICATION` (row V-4).
- **CONFIDENCE:** High.
- **IS-SHOULD:** IS — two independently-chosen deadlines that disagree, with the shorter one on
  the side that cannot cancel the work. SHOULD — the client timeout is set explicitly and set
  *longer* than the server's, so the server's own `deadline-exceeded` at `:609-610` is what the
  user sees. That path at least writes an audit row (`:607-608`); the 70s path writes nothing.
- **RISK:** A container killed at 120s never reaches `writeLog` at `:607`, so the most expensive
  requests in the system are precisely the ones absent from `assistantLogs` — cost telemetry is
  biased low exactly where it matters. This is the P-01 shape from `_patterns.md` applied to a
  log: the artefact is missing, not wrong.
- **IMPACT:** **P2** — rare in the happy path, systematically wrong when it fires.
- **INVARIANT AT RISK:** Audit completeness (A4's F-10) and cost observability.
- **DEPENDENCIES:** A2/A5 own the app-side UX; A4 owns the audit gap. The timeout mismatch is the
  mechanism joining them.
- **RECOMMENDATION:** `getHttpsCallable(CALLABLE).withTimeout(130, TimeUnit.SECONDS)`, and add a
  wall-clock budget check inside the loop at `:531` that exits cleanly at ~90s.

---

### A9-04 — There is no token cap, only a message cap: one long paste costs ~₹22 against a single quota unit

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q1 — "is there any cap?"
- **OBSERVATION:** `MAX_TURNS = 20` is applied as `history.slice(-MAX_TURNS)` (`:487`) — it caps
  the **number of messages**, not their size, and note it is 20 messages ≈ 10 exchanges, not 20
  exchanges as the constant's name suggests. No code anywhere limits the length of
  `request.data.message` or of any history entry. The only ceiling is Firebase's callable request
  limit (10 MB) and then the model's context window. A caller can therefore submit ~1M tokens of
  input — the model's ceiling — and be charged one quota unit for it. At $0.25/1M input that is
  **$0.25 ≈ ₹22 for a single question**; ×30 daily quota = **₹660/student/day**; across 1,000
  students, **₹660,000/day**. The daily quota, the one control designed to keep the cost model
  honest (`:188-190`), counts *questions* and is therefore blind to the entire attack.
- **EVIDENCE:**
  - `studentAssistant.js:486-489` — `.slice(-MAX_TURNS)` and `String(...).trim()` with no length check.
  - `:82` — `const MAX_TURNS = 20; // conversation length cap sent by the client`
  - `:504-513` — history is mapped into `parts` with no truncation.
  - `AssistantViewModel.kt:37-47` — the client replays **all** non-error messages, unbounded;
    the server discards the excess, so a long thread also wastes uplink on a mobile connection.
  - Contrast `:406-407` — `raise_helpdesk_ticket` *does* cap its strings (`.slice(0,200)` /
    `.slice(0,2000)`). The pattern is known to this file; it just is not applied to the input.
- **CLASSIFICATION:** `[CONFIRMED]` that no length cap exists. `[INFERRED]` on the 10 MB callable
  limit and the 1M-token context window — `REQUIRES VERIFICATION` (row V-5). `[UNKNOWN]` whether
  Vertex bills a request that exceeds context or rejects it unbilled (it should reject, which
  caps the loss at the context window rather than at 10 MB).
- **CONFIDENCE:** High on the gap; the exact rupee ceiling depends on V-5.
- **IS-SHOULD:** IS — quota is denominated in the wrong unit. A question is not a unit of cost;
  a token is. SHOULD — cap `message` at ~2,000 chars and each history entry at ~4,000, reject
  above that with `invalid-argument` **before** `consumeQuota`, and consider debiting quota by
  estimated tokens rather than by 1.
- **RISK:** This is reachable by any authenticated student or parent — the population most likely
  to paste a whole chapter in and ask "explain this", which the prompt refuses (`:446`) *after*
  paying to read it.
- **IMPACT:** **P1** — an authenticated cost-amplification path with a 1,000×+ multiplier.
- **INVARIANT AT RISK:** The per-student cost ceiling `DAILY_QUOTA` exists to enforce.
- **DEPENDENCIES:** Same root as A4's F-11; A9 owns the cost magnitude, A4 owns the input-validation
  shape. Also compounds A4's F-03 (quota consumed before validation).
- **RECOMMENDATION:** Length caps before `consumeQuota`, and a project-level Cloud Billing budget
  alert on the Vertex SKU as the outer backstop — there is currently **no spend ceiling of any
  kind** between a bug and the invoice.

---

### A9-05 — `schools/{schoolId}` is re-read on every single question: one document, ~30,000 reads/day/school, ~500 reads/second at peak

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q5/Q7 — read amplification and shared hot documents.
- **OBSERVATION:** `loadContext` reads the school document on every request to fetch two values —
  `ai_assistant_enabled` and `currentSession` — neither of which changes more than a few times a
  year. There is no in-instance memo, no TTL cache, nothing. At the theoretical ceiling (30,000
  questions/day/school) that is 30,000 reads of **one document key**. In the stated peak scenario
  — results published, a large fraction of 1,000 students opening the app within the hour — the
  same key takes the entire burst. The student document (`:150`) is equally cacheable within a
  request's lifetime but at least varies per student.
- **EVIDENCE:**
  - `studentAssistant.js:148-151` — both `get()`s, on every invocation.
  - `:158`, `:162` — the only two fields used from `schoolSnap`.
  - `:107` — `const db = () => admin.firestore();` — a fresh handle per call site, no caching layer.
- **CLASSIFICATION:** `[CONFIRMED]` that the read is unconditional and uncached. `[INFERRED]`
  that Firestore serves this without throttling — reads on a single key are not subject to the
  1-write/second per-document guidance, so this is a **cost and latency** finding, not a
  correctness one. `REQUIRES VERIFICATION` (row V-6) that no per-key read hot-spotting appears
  under a 500 rps burst.
- **CONFIDENCE:** High.
- **IS-SHOULD:** IS — the most-read document in the feature is the least-changing one. SHOULD —
  a module-scope `Map` cache keyed by `schoolId` with a 5-minute TTL, which survives across
  requests on a warm instance and costs nothing. This also removes ~20-50ms from every request's
  critical path (§Q6).
- **RISK:** Modest in rupees (≈₹976/month at ceiling for *all* reads, §2.5) but it sits on the
  latency critical path before the model is ever called, and it is the one place a single key
  absorbs the whole peak.
- **IMPACT:** **P3** — inefficiency, not a break.
- **INVARIANT AT RISK:** None. Pure waste.
- **DEPENDENCIES:** Fixing this also fixes half of the pre-model latency in Q6.
- **RECOMMENDATION:** Memoise the school config; leave the student read live (status can change).

---

### A9-06 — `assistantLogs` and `assistantQuota` grow without bound, without TTL, and without an index — the cost telemetry is unqueryable

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q4/Q5 — quota economics, storage growth.
- **OBSERVATION:** Every answered question writes an `assistantLogs` document carrying up to 500
  characters of a child's question text plus the usage counters. Every student-day writes an
  `assistantQuota` document. Neither collection has a declared composite index, and neither has a
  TTL policy in the repo. At the ceiling that is 660,000 log documents per school per month and
  220,000 quota documents per school per year, retained forever. The `usage` block at `:631-636`
  is the *only* record of what this feature costs — and with no index on
  `(schoolId, createdAt)` any attempt to aggregate it will either fail
  `FAILED_PRECONDITION` or require a full collection scan.
- **EVIDENCE:**
  - `studentAssistant.js:622-638` — `.add(...)` per answered question, including `question`.
  - `:193` — quota doc key `{schoolId}_{studentId}_{day}`, one per student-day, never deleted.
  - `firebase-rules/firestore.indexes.json` — **0 indexes** for `assistantLogs`, **0** for
    `assistantQuota` (308 indexes declared in total; none for either).
  - `:617-618` — the comment states the purpose: "if `cacheRead` stays 0 across calls, the prompt
    cache has silently stopped working." Nothing can currently run that query.
- **CLASSIFICATION:** `[CONFIRMED]` — the index file was parsed; both collections return zero
  rows. `[INFERRED]` that no TTL policy exists (TTL is console/gcloud-configured and would not
  appear in this repo) — `REQUIRES VERIFICATION` (row V-7).
- **CONFIDENCE:** High on the indexes; Medium on TTL.
- **IS-SHOULD:** IS — the feature writes its own cost telemetry into a collection nobody can
  query, and retains children's question text indefinitely. SHOULD — a
  `(schoolId ASC, createdAt DESC)` index, a Firestore TTL policy on both collections
  (`assistantQuota` needs ~7 days; `assistantLogs` needs whatever the DPDP retention decision
  says), and a scheduled aggregator.
- **RISK:** Storage cost is small (~660 MB/month/school at ceiling). The real cost is that
  A9-01's detection path is inert and that indefinite retention of a child's free-text questions
  is a DPDP exposure A6/A8 should be told about.
- **IMPACT:** **P2** — blocks the only instrumentation this feature has.
- **INVARIANT AT RISK:** Observability of the cost model; retention minimisation.
- **DEPENDENCIES:** A9-01 cannot be monitored until this is fixed. Overlaps A4's F-10 and F-13.
- **RECOMMENDATION:** Declare both indexes and both TTL policies **before** the pilot, not after —
  a TTL applied later does not retroactively purge, and the index must be deployed before any
  code that queries it (CLAUDE.md deploy ordering).

---

### A9-07 — No streaming and no progressive feedback: a bare spinner for the full 3–9s, and up to ~30s on the tool loop

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q6 — perceived latency.
- **OBSERVATION:** The server calls `client.models.generateContent(...)` (`:534`) — the
  non-streaming method. There is no `generateContentStream`, and the callable returns a single
  JSON blob (`:560-564`), so streaming is not merely unused, it is structurally unavailable
  through this transport. The app therefore shows `ThinkingBubble()` — a 14dp
  `CircularProgressIndicator` with **no text inside it at all** — for the entire wall-clock
  duration. Crucially, the server already knows something the user would value: it knows which
  tools it is running (`toolsUsed`, `:524`), and it returns them, but only *after* the answer
  arrives, where they render as retrospective chips. Nothing tells the user "checking your
  attendance…" while they wait.
- **EVIDENCE:**
  - `studentAssistant.js:534-545` — `generateContent`, not `generateContentStream`.
  - `:560-564` — single-shot return shape.
  - `AssistantScreen.kt:250-263` — `ThinkingBubble` is a spinner and nothing else.
  - `AssistantScreen.kt:114` — `if (ui.isThinking) item { ThinkingBubble() }`
  - `AssistantScreen.kt:73` — an `assistant_thinking` string exists but is used in the **top bar
    subtitle**, not in the bubble.
  - `AssistantViewModel.kt:53` — `isThinking = true` is the only progress state that exists.
- **CLASSIFICATION:** `[CONFIRMED]` — no streaming, one boolean progress state.
  `[INFERRED]` on the 3–9s figure; see §Q6 for the model. `REQUIRES VERIFICATION` (row V-8).
- **CONFIDENCE:** High on the mechanism; the latency number is a model.
- **IS-SHOULD:** IS — the two-call tool path is the *common* path (any records question), and it
  is the slow one, so the median interaction is the one that sits on a featureless spinner.
  SHOULD — at minimum, animated dots plus a rotating "checking your records…" line; properly, a
  streaming transport (callable → HTTP function with SSE, or Firestore-document streaming) so
  first token appears in <1s.
- **RISK:** On the stated peak — the hour after results publish, on Indian mobile networks — a
  6-second blank spinner is where a user taps back, retries, and (per A4's F-04) burns a second
  quota unit for the same question.
- **IMPACT:** **P2** — the dominant UX characteristic of the feature.
- **INVARIANT AT RISK:** None. Product quality.
- **DEPENDENCIES:** A2/A5 own the Compose side; A9 supplies the latency budget. Note the fix
  interacts with A9-03: a longer perceived wait needs the timeout mismatch fixed first.
- **RECOMMENDATION:** Cheapest meaningful win is not streaming — it is cutting the pre-model
  Firestore latency (A9-05) and putting words in the bubble.

---

### A9-08 — The Vertex client is constructed inside the request handler, and no Vertex error is caught or retried

- **AGENT:** A9 · PERF-ANALYST
- **MISSION:** Q6/Q7 — cold start, concurrency, quota exhaustion.
- **OBSERVATION:** `new GoogleGenAI({...})` is executed per request (`:517-521`), inside the
  handler, so nothing the client caches internally — ADC token, HTTP agent, connection pool —
  survives between requests on a warm instance. Separately, the `generateContent` call at `:534`
  sits in **no** `try/catch`. A Vertex 429 (project QPM quota exceeded — exactly what the stated
  peak produces), a 503, or a transient network error propagates out of the callable as an
  unmapped `internal` error, which `AssistantViewModel` renders as
  `assistant_generic_error` (`:109`) — after quota was consumed at `:483`. There is no retry, no
  backoff, and no distinction between "the model is busy, try again in 5 seconds" and "something
  is broken". Note the contrast: individual **tool** failures *are* caught and degraded
  gracefully (`:596-602`); the model call, the only network call that can rate-limit, is not.
- **EVIDENCE:**
  - `studentAssistant.js:517-521` — client constructed in the handler body.
  - `:534-545` — bare `await client.models.generateContent(...)`.
  - `:577-603` — the tool loop's careful per-tool `try/catch`, for contrast.
  - `:479` — no `minInstances`, no `maxInstances`, no `concurrency` set.
  - `functions/index.js` — no `setGlobalOptions` (grep for `setGlobalOptions|maxInstances|minInstances|concurrency` returns only an unrelated comment at `:642`).
  - `AssistantViewModel.kt:102-110` — the `when` has no branch for `UNAVAILABLE`.
- **CLASSIFICATION:** `[CONFIRMED]` — per-request construction, no catch, no options.
  `[UNKNOWN]` the project's actual Vertex QPM quota for `gemini-3.1-flash-lite` on the `global`
  endpoint — `REQUIRES VERIFICATION` (row V-9), and it is the number that decides whether the
  peak scenario survives.
- **CONFIDENCE:** High.
- **IS-SHOULD:** IS — the failure mode with the highest probability at peak (Vertex 429) is the
  one with no handling, and it costs the student a quota unit each time. SHOULD — hoist the
  client to module scope; wrap `generateContent` in a catch that maps 429/503 to
  `unavailable` with a retry hint, refunds the quota unit, and applies one bounded backoff.
- **RISK:** At peak this converts a recoverable rate-limit into a wave of "something went wrong"
  plus silently-consumed quota, during the exact hour the feature is most wanted.
- **IMPACT:** **P1** — peak-load failure mode with a user-visible cost.
- **INVARIANT AT RISK:** Quota fairness (a student pays for the platform's rate limit).
- **DEPENDENCIES:** Quota refund requires A4's F-03 ordering fix (validate → call → then debit).
- **RECOMMENDATION:** Request a Vertex quota increase sized to the peak (§Q7) before pilot, and
  set `maxInstances` so a runaway cannot outrun the Vertex quota and turn a cost problem into an
  outage.

---

## 2. THE COST MODEL

### 2.0 Assumptions — every one of these is an input, not an observation

| # | Assumption | Value | Basis |
|---|---|---|---|
| A-1 | Input price | $0.25 / 1M tokens | **given in the brief**; the model id at `:80` was not verified against a live price list |
| A-2 | Output price | $1.50 / 1M tokens | given in the brief |
| A-3 | FX | ₹88 / USD | given in the brief |
| A-4 | Implicit cache discount | 90% off cached input → $0.025/1M | `:41-46`; **contingent on A9-01** |
| A-5 | Cacheable prefix | 1,890 tokens (system 1,273 + tools 617) | `[MEASURED-STATIC]` §0 |
| A-6 | Per-request context line | ~45 tokens | `:495-498`, counted from the format string |
| A-7 | Typical student question | ~15 tokens | estimate |
| A-8 | Typical answer | ~120 tokens | the prompt demands brevity (`:452-454`); `MAX_OUTPUT_TOKENS` is 1024 |
| A-9 | A function-call turn | ~25 tokens | small JSON |
| A-10 | A typical tool result | ~800 tokens | midpoint: attendance ~300, fees 25 rows ~600, homework 25 rows ~1,200. **`get_timetable` is excluded — it is unbounded, see A9-02** |
| A-11 | Conversation shape | each extra exchange adds ~135 tokens to the replayed transcript (15 user + 120 model) | tool traffic is **not** replayed — the client stores only `reply` (`AssistantViewModel.kt:63-69`), so intra-request tool turns do not accumulate across turns. This is a genuine and non-obvious design win. |
| A-12 | School month | 22 school days; 10 school months/year | brief: 220 school days |
| A-13 | Traffic mix | 60% one-tool · 30% no-tool · 10% multi-tool (3 calls) | estimate; `REQUIRES VERIFICATION` (row V-10) |

### 2.1 Q1 — Input tokens per conversation turn

Turn *k* first model call = prefix (1,890) + context line (45) + question (15) + (k−1)×135.

| Turn | Input tokens (call 1) | Cumulative input across the conversation (1-tool per turn, 2 calls each) |
|---|---|---|
| 1 | **1,950** | ~4,790 |
| 5 | **2,490** | ~26,800 |
| 10 | **3,165** | ~59,400 |
| 20 | **4,515** (capped — see below) | ~139,000 |

**Growth per turn is linear (+135 tokens/turn); cumulative spend across a conversation is
quadratic — O(n²).** That is where it becomes superlinear: not in any single turn, but in the
total. Doubling conversation length quadruples the bill for it.

**Is there a cap?** Yes and no. `history.slice(-MAX_TURNS)` at `:487` holds the transcript at 20
**messages** (10 exchanges), so from turn 11 onward the input plateaus at ~1,950 + 10×135 ≈
**3,300 tokens** rather than growing forever. The turn-20 figure above is therefore a ceiling the
code actually enforces. But the cap counts messages, not tokens — see **A9-04**, where a single
oversized message defeats it entirely.

### 2.2 Q2 — Cost per interaction

Cached and uncached given side by side, because **A9-01 decides which column is real**.

| Scenario | Model calls | Input tok (total) | Output tok | Cost **cached** | Cost **uncached** |
|---|---|---|---|---|---|
| **(b) No-tool refusal** | 1 | 1,950 | 60 | $0.000155 · **₹0.014** | $0.000605 · **₹0.053** |
| **(a) One-tool lookup** | 2 | 4,775 | 175 | $0.000580 · **₹0.051** | $0.001499 · **₹0.132** |
| **(c) 6-iteration worst case** | 6 | 24,735 | 150 (calls) | $0.001785 · **₹0.157** | $0.006409 · **₹0.564** |
| **(c′) 6-iteration, max output every call** | 6 | 24,735 | 6,144 | $0.010800 · **₹0.950** | $0.015400 · **₹1.356** |
| **(d) A9-04 abuse: one context-window question** | 2 | ~1,000,000 | 120 | ~$0.250 · **₹22.0** | ~$0.250 · **₹22.0** |

Worked example for (a), cached: call 1 = 1,890 cached + 60 fresh = (1,890×0.025 + 60×0.25)/1e6 =
$0.000065; call 2 = 2,085 cached + 800 fresh (the tool result) = $0.000252; output 175 ×
$1.50/1M = $0.000263. Total $0.00058 = ₹0.051.

Note (d) is unaffected by caching — the cache discount applies to the 1,890-token prefix, which
is 0.19% of that request.

### 2.3 Blended cost per question

Using A-13: **cached ₹0.045/question · uncached ₹0.123/question.**

### 2.4 Q4 — Worst-case monthly bill for one school

Ceiling = 30 questions × 1,000 students × 22 school days = **660,000 questions/month**.

| | Cached (A9-01 holds) | Uncached (A9-01 fails) |
|---|---|---|
| Vertex, per month | **₹29,700** | **₹81,180** |
| Vertex, per student per month | ₹29.7 | ₹81.2 |
| Vertex, per student per **year** (10 months) | **₹297** | **₹812** |
| **vs. the ₹3–9/student/year design target** | **33–99× over** | **90–271× over** |

The design target is only reachable at a far lower *average* usage. Solving for it:

| Average questions/student/day | ₹/student/year (cached) | Verdict vs ₹3–9/yr |
|---|---|---|
| 30 (the quota ceiling) | ₹297 | 33–99× over |
| 5 | ₹49.5 | 5–16× over |
| 1.5 | ₹14.9 | 1.7–5× over |
| **0.5** | **₹4.95** | **within target** |

**The quota of 30/day is ~60× the rate the stated budget sustains.** The quota is not a cost
control; it is a fair-use control that happens to sit far above the budget line. `[INFERRED]`.

**Unresolved:** the brief states ₹3–9/student/**year**; `project_zenxii_student_ai` records
"~₹3/student/**mo**". Under the monthly reading (₹30/yr) the ceiling is 10× over and average
usage of ~3/day fits. The intended target `REQUIRES VERIFICATION` (row V-11) — the two readings
differ by 12× and change whether this feature ships.

### 2.5 Non-Vertex costs at the same ceiling (one school, per month)

| Component | Quantity | Cost |
|---|---|---|
| Firestore reads | 660k × ~28 = 18.5M | ~$11.1 · **₹976** |
| Firestore writes | 660k × 2 = 1.32M | ~$2.4 · **₹209** |
| `assistantLogs` storage | ~660 MB/month, **cumulative, no TTL** (A9-06) | small but monotonic |
| Cloud Functions vCPU-s | 660k × ~4s, ÷ achieved concurrency | **₹30 – ₹2,150** (see Q7) |

Firestore and compute together are ~4–10% of the Vertex bill in the cached case. **The model is
the cost. Everything else is rounding** — which is why A9-01, A9-02 and A9-04 are the findings that
matter and A9-05 is only P3.

---

## 3. READS PER QUESTION

| Stage | Reads | Writes | Bounded? | Citation |
|---|---|---|---|---|
| `loadContext` — school doc | 1 | 0 | yes (1 doc) — but **shared hot key**, A9-05 | `:148-149` |
| `loadContext` — student doc | 1 | 0 | yes | `:150` |
| `consumeQuota` transaction | 1 | 1 | yes; **per-student key**, no shared doc | `:193-207` |
| `get_attendance_summary` | 1 | 0 | row-bounded; **payload unbounded** (`dayWise`), A9-02 | `:292-294`, `:301` |
| `get_homework` | ≤25 | 0 | `QUERY_LIMIT` ✅ | `:309-316` |
| `get_fee_status` | ≤25 | 0 | `QUERY_LIMIT` ✅ | `:331-336` |
| `get_timetable` | ≤25 | 0 | rows ✅ / **fields ❌**, A9-02 | `:355-359` |
| `get_exam_results` | ≤25 | 0 | `QUERY_LIMIT` ✅ | `:367-372` |
| `raise_helpdesk_ticket` | 0 | 0 | pure function — writes nothing | `:405-425` |
| `writeLog` | 0 | 1 | yes; auto-id ⇒ no write hot-spot | `:622-638` |

| Case | Reads | Writes |
|---|---|---|
| Minimum (no-tool refusal) | **3** | 2 |
| Typical (one query tool, full page) | **28** | 2 |
| **Ceiling** (6 iterations × 5 read tools × 25 rows) | **753** | 2 |

At 1,000 concurrent students on the typical path: **28,000 reads + 2,000 writes** in the burst,
of which **1,000 are the same `schools/{schoolId}` key**.

**Unbounded query check:** none. All four collection queries carry `.limit(QUERY_LIMIT)`
(`:315, :335, :358, :371`) and `get_attendance_summary` is a single-document `get`. `QUERY_LIMIT`
is applied to **every** query that can return more than one row. The gap is not row count —
it is **payload size**, which nothing bounds (A9-02).

**Index check (I executed this):** all four queries have a declared serving index —
`homework (schoolId, sectionKey, status, session, createdAt DESC)`,
`feeDemands (schoolId, session, studentId, …)`,
`results (schoolId, session, studentId)`,
`timetables (schoolId, sectionKey)`. **No P-02-pattern index break here.**
`[CONFIRMED]` by parsing `firestore.indexes.json` (308 indexes). Note the index
`timetables (schoolId, sectionKey, session)` already exists, so A4's F-05 session-filter fix
needs no new index — worth telling QA-LEAD, since it removes a deploy-ordering dependency.

---

## 4. THE EIGHT QUESTIONS

**Q1 · Token growth.** Turn 1 = ~1,950 input tokens; turn 5 = ~2,490; turn 10 = ~3,165; turn 20 =
~4,515, but `slice(-20)` (`:487`) plateaus it at ~3,300 from turn 11. Per-turn growth is linear
(+135/turn); **cumulative conversation cost is quadratic**. The cap is real but counts *messages*,
not tokens — see A9-04. `[CONFIRMED]` mechanism / `[INFERRED]` magnitudes.

**Q2 · Cost per interaction (cached / uncached).** No-tool refusal ₹0.014 / ₹0.053. One-tool
lookup (2 calls) ₹0.051 / ₹0.132. Six-iteration worst case ₹0.157 / ₹0.564, rising to ₹0.95 /
₹1.36 if every call emits its full 1,024-token budget. Assumptions A-1…A-13, §2.0. `[INFERRED]`.

**Q3 · Implicit caching.** **The ordering is correct.** `systemInstruction` and `tools` are passed
in `config` (`:541-542`) and `contents` is passed separately (`:536`); in the assembled prompt the
system instruction and tool declarations precede all content, so the stable ~1,890-token prefix is
genuinely first. Nothing per-request precedes it: the student name, class, section and date are
confined to `contextLine`, which is appended to the **current user turn** (`:495-498`, `:512`) —
the file's own contract at `:436-438`, and it is honoured. Within one request's tool loop the
prefix only ever grows by appending (`:570`, `:604`), so iterations 2-6 should hit an ever-larger
cached prefix. **The risk is not ordering, it is the threshold** — see A9-01. `[CONFIRMED]` ordering
/ `[UNKNOWN]` whether the cache actually engages.

**Q4 · Quota economics.** 30 × 1,000 × 22 = 660,000 questions/month ⇒ **₹29,700/month/school
cached, ₹81,180 uncached** — ₹297 or ₹812 per student per year against a ₹3–9/student/year target,
i.e. **33–99× over** in the good case. The target is only met at ≈0.5 questions/student/day; the
quota sits ~60× above that. `[INFERRED]`; target definition itself is disputed (row V-11).

**Q5 · Firestore read amplification.** 3 reads minimum, 28 typical, **753 ceiling** per question;
2 writes always. ×1,000 concurrent = 28,000 reads/burst, 1,000 of them on one shared school-doc
key (A9-05). **No unbounded query exists** — `QUERY_LIMIT = 25` is applied to all four queries and
the fifth tool is a doc `get`; the unbounded dimension is payload *size*, not row count (A9-02).
`[CONFIRMED]` from source and the index file.

**Q6 · Latency.** Warm two-call tool path: school+student gets (parallel, ~20-50ms) → quota
transaction (~50-100ms) → model call 1 (~0.6-1.5s) → tool query (~30-80ms) → model call 2
(~1.0-2.5s) ⇒ **≈2.5-4.5s**. Cold start (Node 20 + firebase-admin + `@google/genai`, 512MiB,
client constructed per request per A9-08) adds ~2-5s ⇒ **≈5-9s**. us-central1 sits inside nam5, so
Firestore hops are cheap; the model calls are ~85% of the wait. **No streaming, no progressive
feedback** — `generateContent` (`:534`), single-blob return (`:560-564`), and a wordless spinner
(`AssistantScreen.kt:250-263`). The user watches a blank spinner for the whole duration, and up to
~15-30s on the 6-iteration path. `[INFERRED]` — every number here `REQUIRES VERIFICATION`.

**Q7 · Concurrency at 500 simultaneous.** Requests are I/O-bound (~85% spent awaiting Vertex), so
with the Cloud Functions v2 default of 80 concurrent requests per instance at 512MiB/1vCPU, 500
concurrent needs ~7 instances — and with **no `minInstances`** (`:479`) all 7 cold-start at once,
so the peak's first users see the 5-9s path. Neither `maxInstances` nor `concurrency` is set
anywhere (no `setGlobalOptions` in `index.js`). **Firestore contention is genuinely per-student**:
the quota doc key is `{schoolId}_{studentId}_{day}` (`:193`), so the transaction contends only
with the same student's own parallel taps, and `assistantLogs` uses random auto-ids — no write
hot-spot. **The one shared hot doc is `schools/{schoolId}`, read-only, ~500 reads/s at peak**
(A9-05). **The real ceiling is Vertex QPM**, which is per-project and shared across the whole
`global` endpoint, unknown (V-9), and completely unhandled — a 429 propagates as `internal` after
quota was already burned (A9-08). `[INFERRED]`; concurrency defaults `REQUIRE VERIFICATION` (V-12).

**Q8 · 120s vs the 6-iteration loop.** Yes, it can exceed it: 6 iterations at a slow tail
(~15s/call when the model emits near 1,024 tokens against a growing context) ≈ 90s + Firestore
overhead, and there is **no wall-clock budget check** in the loop (`:531`) and **no timeout on the
Vertex call**. But the binding deadline is the client's, not the server's: the app sets no timeout
(`AssistantRepository.kt:41-44`), so the SDK default (~70s) fires first. The user sees
`assistant_too_long` (`AssistantViewModel.kt:107-108`) while the container runs on for up to
another 50s, still billing Vertex. If the container is then killed at 120s it never reaches
`writeLog` (`:607`) — **the most expensive requests in the system leave no audit row**. See A9-03.
`[CONFIRMED]` mismatch / `[INFERRED]` SDK default (V-4).

---

## 5. DEFECT-PATTERN SWEEP (`_patterns.md`)

- **P-01 (never once run):** the cache discount is asserted, never verified, and the counter that
  would prove it (`cacheRead`, `:550`) is written to a collection with no index and no reader —
  the artefact exists but nothing checks it. → **A9-01, A9-06**.
- **P-04 (client rule with no server twin):** the client sets no call timeout and the server sets
  120s; two layers with disagreeing deadlines, the shorter on the side that cannot cancel. → **A9-03**.
- **P-07 (denormal with two owners):** none found. `assistantQuota` has exactly one writer.
- **P-08 (non-idempotent key):** the quota key is `{schoolId}_{studentId}_{day}` — **stable and
  correct**. The audit log uses `.add()` (auto-id) and is therefore duplicate-prone on retry
  (A4's F-04), but that is a correctness finding, not a cost one.

**Things this function gets right, on the cost axis, that deserve saying:** `MAX_OUTPUT_TOKENS`
caps the expensive side of the ledger; the transcript is client-held so tool traffic never
accumulates across turns (A-11); `QUERY_LIMIT` is applied to every query without exception;
every tool query has a serving index; the quota key avoids a shared hot doc; the system prompt is
deliberately tenant-agnostic, which is the *correct* cache design even if the threshold is
unverified; and the parallel `Promise.all` over tool calls (`:577`) genuinely saves a full
Firestore round-trip per extra tool.

---

## 6. VERIFICATION ROWS — what must be measured to lift this above E2

| Row | What to measure | Decides |
|---|---|---|
| **V-1** | Real tokeniser count of `SYSTEM_PROMPT` + `TOOLS` via `countTokens` | the exact cache-prefix margin (A9-01) |
| **V-2** | `usageMetadata.cachedContentTokenCount` on two identical back-to-back requests | **whether implicit caching fires at all** (A9-01) — the single highest-value measurement in this document |
| **V-3** | Live timetable document size × 25 | the magnitude of A9-02 |
| **V-4** | The Android Functions SDK's actual default call timeout in bom 32.7.4 | A9-03 |
| **V-5** | Firebase callable max request size, and Vertex behaviour on an over-context request (billed or rejected) | the rupee ceiling of A9-04 |
| **V-6** | Firestore behaviour under a 500 rps read burst on one key | A9-05 severity |
| **V-7** | Whether a TTL policy exists on `assistantLogs` / `assistantQuota` | A9-06 |
| **V-8** | End-to-end p50/p95 latency, warm and cold, for the 1-tool and 6-iteration paths | Q6, A9-07 |
| **V-9** | The project's Vertex QPM quota for this model on `global` | **whether the peak scenario survives** (A9-08) |
| **V-10** | Real traffic mix (tool / no-tool / multi-tool) from `assistantLogs` after pilot | §2.3 blended cost |
| **V-11** | The intended budget: ₹3–9/student/**year** or ~₹3/student/**month** | §2.4 — the two differ by 12× |
| **V-12** | Effective v2 defaults actually applied (concurrency, maxInstances, CPU at 512MiB) | Q7 |
| **V-13** | Whether `gemini-3.1-flash-lite` exists on Vertex `global` at the assumed price | every number in §2 |

---

## 7. WHAT I COULD NOT ESTABLISH

1. Whether the cache ever engages — the whole cost model is one unverified boolean (V-2).
2. Whether the assumed model id and prices are real (V-13). The brief's $0.25/$1.50 was taken as
   given; it was not reconciled against any price list.
3. Actual latency, anywhere. Every duration in §Q6 is a model.
4. Live document sizes for `timetables` and `attendanceSummary.dayWise` — A9-02's magnitude.
5. The project's Vertex quota — A9-08's severity, and the answer to Q7's real ceiling.
6. Whether any school has `ai_assistant_enabled = true` (A4's F-01). If none does, **every number
   in this document describes a feature that has never executed in production**, and the first
   real measurement will also be the first real invocation.
