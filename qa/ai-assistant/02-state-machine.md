# A6 · MODELLER — AI Assistant ("Ask ZenXii") state machine

**Agent:** A6 · MODELLER · reports to QA-LEAD
**Date:** 2026-08-31
**Evidence ceiling: E2** — static source trace of `functions/studentAssistant.js` (649 L, branch
`yug_testing`, UNCOMMITTED) and `ZenXII_Parent/.../ui/assistant/` (branch `main`, UNTRACKED).
Nothing was executed, deployed or observed. Every runtime consequence is `REQUIRES VERIFICATION`.

**Path prefixes used below**
- `CF:` → `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/studentAssistant.js`
- `VM:` → `/Users/yuggi/AndroidStudioProjects/ZenXII_Parent/app/src/main/java/com/schoolsync/parent/ui/assistant/AssistantViewModel.kt`
- `SC:` → `.../ui/assistant/AssistantScreen.kt`
- `RP:` → `.../data/repository/AssistantRepository.kt`
- `AM:` → `.../data/model/AssistantMessage.kt`
- `NG:` → `.../ui/navigation/NavGraph.kt`

**Layering note.** This document layers on `_global-invariants.md` (G1–G5, Z1–Z9, C1–C5) and does
not restate it. Where a transition breaks a global invariant it is named, not re-explained.

---

## 0. Actors

| Actor | What it can move | Citation |
|---|---|---|
| **Student/parent** (one household credential — the Parent app logs in *as* the student) | Conversation states, by typing/tapping/leaving | `NG:197-199`; `CF:129-131` |
| **Android OS** | `Conversation → destroyed` (process death); `→ recreated` (config change) | `AM:5-9`; A3 Q1 |
| **The callable** `studentAssistant` | All Server-turn states; the sole writer of Quota and Logs | `CF:478-612` |
| **Vertex AI** (`gemini-3.1-flash-lite`, `global`, ADC) | `model-loop → answered` / `→ internal` | `CF:517-521, 534-545` |
| **Firestore** (Admin SDK, bypasses rules — Z9) | Quota commit; Log append | `CF:107, 194-208, 622` |
| **School operator** | School-enablement states — **but has no in-product surface**; console/`gcloud` only | `CF:158`; A1 A-3; A4 F-01; A5 D-04 |
| **Wall clock (UTC)** | `Quota bucket → rolled` at 00:00 UTC = 05:30 IST | `CF:192` |

**No actor is a background job.** `grep -rn "onSchedule" functions/*.js` returns `feeOpsSweep`,
`sweepExpiredStories`, `closeStaleTickets` and nothing else — no state in this module is ever moved
by a scheduled process. `[CONFIRMED]` (own verification, corroborating A1 A-12 / A5 D-05).

---

## 1. CONVERSATION (client-held, in the ViewModel)

State is one `MutableStateFlow(AssistantUiState())` — `VM:32-33`; fields `messages`, `isThinking`,
`unavailableReason`, `input` — `VM:18-24`. It is held nowhere else: no `SavedStateHandle`, no
`rememberSaveable`, no disk, no Firestore (`AM:5-9`, `RP:17-19`).

### 1.1 States

| State | Predicate | Citation |
|---|---|---|
| `empty` | `messages.isEmpty() && !isThinking && unavailableReason == null` | `VM:18-24`; rendered `SC:112, 117` |
| `composing` | `empty ∨ answered ∨ error` with `input.isNotBlank()` | `VM:35`; `SC:313-330` |
| `in-flight` | `isThinking == true` | `VM:53`; `SC:114, 121` |
| `answered` | last message `Role.ASSISTANT && !isError` | `VM:60-71` |
| `error` | last message `Role.ASSISTANT && isError` | `VM:112-121` |
| `unavailable` | `unavailableReason != null` — **absorbing** | `VM:93-99`; `SC:99-102` |
| `destroyed` | ViewModel gone (process death, or `popBackStack`) | `AM:5-9`; A3 Q1(b) |

### 1.2 Legal transitions

| # | From → To | Trigger | Citation |
|---|---|---|---|
| C-L1 | `empty → composing` | typing | `VM:35` |
| C-L2 | `composing → in-flight` | send / IME / suggestion chip; user turn appended optimistically **before** the call | `VM:37-55`; `SC:117, 123, 323` |
| C-L3 | `in-flight → answered` | callable resolves | `VM:59-71` |
| C-L4 | `in-flight → error` | non-terminal `FirebaseFunctionsException` (`RESOURCE_EXHAUSTED`, `UNAUTHENTICATED`, `DEADLINE_EXCEEDED`, else-branch) | `VM:102-121` |
| C-L5 | `in-flight → unavailable` | `FAILED_PRECONDITION` **or** `PERMISSION_DENIED` | `VM:90-99` |
| C-L6 | `answered ∨ error → composing` | typing again | `VM:35` |
| C-L7 | `answered → (navigate away)` | handoff button → `support_compose` | `SC:206-220`; `NG:837-845` |
| C-L8 | `* → destroyed` | Back (`NG:839` `popBackStack`) or process death | A3 Q1(b) |

`in-flight` is correctly guarded against re-entry: `VM:39` `if (q.isEmpty() || _ui.value.isThinking) return`,
reinforced by `SC:121, 334`. Concurrency-safe only because every caller is a main-thread Compose
callback — A3 Q2 establishes this and I concur; it is an unenforced assumption, not a structural one.

### 1.3 ILLEGAL transitions the implementation nevertheless permits

**C-I1 · `answered → unavailable` destroys the visible transcript.** `[CONFIRMED]`
`SC:99-102` is `if (ui.unavailableReason != null) { UnavailableNotice(...); return@Column }` — an
early return **before** the `LazyColumn` (`SC:104-115`) is composed. Every answer already received
is still in `ui.messages` and is unreachable. A school disabled between question 3 and question 4
(`CF:158-160`, re-evaluated per request at `CF:482`) erases three answers from the screen and
replaces them with "The assistant is not enabled for your school." No state machine should let a
*capability* change retroactively delete *content*.

**C-I2 · `unavailable` is absorbing, and it absorbs two different conditions.** `[CONFIRMED]`
`unavailableReason` has exactly one writer in the entire app (`VM:96`) and no clearing path — the
only other occurrences are the declaration `VM:22` and the two reads `SC:99-100`. A3 M-05 establishes
this; I extend it with the transition consequence: `PERMISSION_DENIED` (`VM:91`) is emitted by
`CF:132-134` (wrong role) and `CF:172-174` (`student.status != 'active'`), i.e. by conditions that a
re-login or a re-activation clears — yet the client models them as permanent. The **server condition
can clear while the client state cannot**. This is the mandate's named concern and it is real.

**C-I3 · `in-flight → error` fires on coroutine cancellation.** `[CONFIRMED]` code, `[INFERRED]` effect.
`VM:72-74` is a bare `catch (e: Exception) { handleFailure(e) }` with no `is CancellationException`
rethrow and no `ensureActive()`. `kotlinx.coroutines.CancellationException` is an `Exception`, so a
`viewModelScope` cancellation — Back pressed mid-turn (`NG:839`) — is caught and routed through
`VM:86-122`, appending a generic error bubble to a ViewModel that is being cleared. Harmless today
because the VM dies immediately after; structurally wrong, and it would surface the moment any
recoverable cancellation is introduced (a Stop button, a retry, a test dispatcher). Nobody else flagged this.

**C-I4 · `error → in-flight` replays a user turn that has no answer, producing two consecutive `user` turns.** `[CONFIRMED]`
On a failure, `VM:49-55` has already appended the user's question and `VM:112-121` appends the error
bubble. The next `send()` builds history with `filterNot { it.isError }` (`VM:43-47`) — which removes
the error but **keeps the orphaned user turn**. `CF:504-513` then appends the new question as another
`{role:'user'}`, so the `contents` array handed to `generateContent` (`CF:534-536`) contains two
adjacent user turns. `[UNKNOWN]` whether Gemini 3.1 Flash-Lite tolerates or rejects this shape —
`REQUIRES VERIFICATION`; if it rejects, the turn fails as `internal` **after** quota was consumed
(`CF:483`), i.e. one failure permanently poisons the conversation until the screen is left.

**C-I5 · `in-flight → destroyed` while the server proceeds to `answered`.** `[CONFIRMED]` for the
absence of persistence; `[INFERRED]` for the server continuing. Process death or Back cancels the
client coroutine; nothing cancels the callable. The server completes, consumes quota (`CF:483`, already
committed), writes `assistantLogs` (`CF:557`) and returns an answer into a closed socket. On restore,
`rememberNavController()` (`NG:464`) re-enters `Route.Assistant` and a **fresh** ViewModel is built
with `AssistantUiState()` defaults (`VM:18-24, 32-33`): Intro screen, empty transcript.

The synthesis that matters, and which no single agent could make: after this transition the student
has **no route on any surface** to discover that a question was billed or what the answer was.
(a) the client persists nothing (`AM:5-9`); (b) `assistantLogs` has no reader anywhere in the
ecosystem (A5 D-05, A1 A-12); (c) the client cannot read `assistantQuota` because both collections
fall to the rules catch-all deny (A5 Q7, `firestore.rules:3355-3357`) and no counter is returned in
the payload (`CF:560-564`). The record exists only for someone with Admin-SDK access.

**C-I6 · `in-flight → error("That took too many steps")` when nothing took any steps.** — see §5, DISPUTE 1.

**C-I7 · `in-flight → answered` with an empty bubble.** `[CONFIRMED]`
`RP:47, 58` coerce a malformed payload to `""`; `VM:60-71` appends unconditionally; `SC:189-199`
renders it. The machine reaches `answered` having communicated nothing, and that blank turn is then
replayed as context forever (`VM:43-47`). A3 M-07 owns this; recorded here because it is a reachable
`answered` state that carries no answer — the only silent transition in the module.

### 1.4 Transitions the business requires that the implementation cannot perform

| # | Required | Why impossible | Citation |
|---|---|---|---|
| C-B1 | `unavailable → composing` once the school re-enables, or once a re-login fixes claims | no writer sets `unavailableReason = null`; no retry control exists on the screen | `VM:22, 96`; `SC:99-102, 349-354` |
| C-B2 | `destroyed → answered` (resume an interrupted turn) | no `SavedStateHandle`, no request id to reconcile against, no server-side transcript | `VM:27-33`; `RP:34-39`; `CF:485` |
| C-B3 | `in-flight → cancelled` (user stops a slow turn) | no `Job` handle, no stop control; Back is the only exit and it abandons a billed call | `VM:57`; `SC:119-124`; A3 M-18 |
| C-B4 | `error → in-flight` as an explicit *retry of the same question* | no retry affordance and no idempotency key, so a retry is a new billed question | `VM:112-121`; `RP:34-39`; A4 F-04 |

---

## 2. SERVER TURN

One invocation of `CF:478-612`. Stateless between turns by design (`CF:485`).

### 2.1 States and the strict order they occur in

| # | State | Entry | Exits |
|---|---|---|---|
| S1 | `authenticating` | `CF:481` `resolveIdentity` | → S2, or `unauthenticated` (`CF:121-123`), `permission-denied` (`CF:132-134`), `failed-precondition` (`CF:135-138`) |
| S2 | `gated` | `CF:482` `loadContext` | → S3, or `not-found` (`CF:153`, `CF:169`), `failed-precondition` school-off (`CF:158-160`), `failed-precondition` blank session (`CF:162-167`), `permission-denied` inactive student (`CF:172-174`) |
| S3 | `quota-consumed` | `CF:483` `consumeQuota` | → S4, or `resource-exhausted` (`CF:197-200`) |
| S4 | `validated` | `CF:489-490` | → S5, or `invalid-argument` (`CF:490`) |
| S5 | `model-loop(i)`, `i ∈ 1..6` | `CF:531-532` | → S6 answered, → S5(i+1), → S7 exhausted, → `internal` |
| S6 | `answered` | `CF:554` `calls.length === 0` | log (`CF:557-558`) then `return` (`CF:560-564`) |
| S7 | `deadline-exceeded` | loop falls through `CF:605` | log `ok:false` (`CF:607-608`) then throw (`CF:609-610`) |

### 2.2 `tool-failed` is NOT a terminal state — a correction to the assumed model

The mandate lists `tool-failed` as an alternative terminus to `answered`. **The implementation has no
such terminus.** `[CONFIRMED]` Every tool call is individually wrapped (`CF:583-602`); a throw is
caught at `CF:596-602`, logged to Cloud Logging, and converted into a `functionResponse` part reading
`'That lookup failed. Tell the student it could not be fetched right now.'`. The loop then continues
(`CF:604`, back to `CF:531`). Consequences that belong in the test plan:

- A tool failure is **absorbed into `answered`**. The student receives a fluent turn; the HTTP result
  is success.
- `toolsUsed.push(call.name)` sits **inside** the `try` at `CF:585`, after the await — so a failed
  tool never appears in `toolsUsed`, never appears in `assistantLogs` (`CF:628`), and never renders a
  ToolChip (`SC:165-168`). The audit trail records a turn that used *fewer* tools than it attempted.
- An unknown tool name short-circuits identically (`CF:578, 582`) with no log line at all.
- Because failures are silent, probing the tool layer is silent. A4 F-08 makes this point for
  `input.month`; I extend it to the whole dispatch: **there is no state, log or metric that
  distinguishes a healthy turn from a turn where every tool failed.**

### 2.3 ILLEGAL transitions the implementation permits

**S-I1 · `quota-consumed → invalid-argument`.** `[CONFIRMED]` `CF:483` precedes `CF:490` by seven
lines. A blank `message` burns one of thirty units, invokes no model, and writes no log row. The
transaction has already committed (`CF:201-207`); no compensating write exists anywhere in
`functions/` — verified independently: `grep -rn "increment(-" functions/` returns only
`storyCounters.js:68`. **Rank 1 by ease of triggering** (any client that omits `message`).

**S-I2 · `quota-consumed → internal` with no log row.** `[CONFIRMED]` `CF:534` is an unwrapped
`await client.models.generateContent(...)`. A Vertex throw propagates past both `writeLog` sites
(`CF:557`, `CF:607`). The bucket is at N+1; `assistantLogs` says the question was never asked.
**Rank 1 by blast radius**: a Vertex incident, an expired ADC grant, or a missing
`roles/aiplatform.user` (A4 F-12, `[UNKNOWN]`) burns every active student's full daily allowance
within minutes, unrecoverable until 05:30 IST.

**S-I3 · `answered` with no audit row.** `[CONFIRMED]` `writeLog` swallows its own failure
(`CF:639-641`) while `CF:560` returns regardless. A4 F-10(a) owns this; I record it as a state
transition because it is the one that breaks reconciliation (see §3.4).

**S-I4 · `model-loop(6) → deadline-exceeded` discards a completed handoff.** `[CONFIRMED]`
`handoff` is assembled at `CF:586-594` inside the loop but is only ever read at `CF:563`, which the
exhaustion path never reaches. If `raise_helpdesk_ticket` runs on iteration 6, the drafted support
request is destroyed and the student is shown an error instead of the "Open Support" button — the
feature's only escape hatch. A4 F-06 owns the control flow; the state-machine framing is that S7 is
reachable from a state that already holds a *successful* side-effect-free result and throws it away.

**S-I5 · a stale `className`/`section` silently widens the read.** `[CONFIRMED]` for the mechanism.
`ctx.className`/`ctx.section` come from the student doc (`CF:180-181`) and are composed into
`sectionKey` (`CF:111-113, 308, 354`). If a student is promoted and the doc lags, `get_homework` and
`get_timetable` read a section the student is not in. `CF:110` states the consequence in its own
comment — "does not error; it silently returns an empty result set" — but that is only the *empty*
case; the *wrong-section* case returns a full, plausible result set. Not another student's personal
record (so not a G1/I1 breach) but data outside entitlement, narrated as fact.

**S-I6 · `answered` reached, delivered to nobody.** — see §5, DISPUTE 1.

### 2.4 Required transitions the implementation cannot perform

| # | Required | Why impossible | Citation |
|---|---|---|---|
| S-B1 | compensate the quota on any failure terminus | no decrement path exists in the file or the codebase | `CF:191-209`; own grep |
| S-B2 | return a partial answer on loop exhaustion | S7 throws; nothing re-calls the model with tools disabled | `CF:605-610` |
| S-B3 | recognise a duplicate submission (G5) | no request id / nonce accepted; `.add()` auto-id | `CF:486-490, 622`; `RP:34-39` |
| S-B4 | withhold results for a fee-defaulter, as `Result.php` does three times | no defaulter check in `get_exam_results`; the `published` field it *does* read is always `null` | `CF:366-386`; A4 F-07; A5 D-02b |
| S-B5 | record an attempted-but-failed tool | `toolsUsed.push` is inside the success branch | `CF:583-585` |

---

## 3. QUOTA BUCKET — `assistantQuota/{schoolId}_{studentId}_{UTC yyyy-mm-dd}`

Sole writer: `consumeQuota` (`CF:191-209`). Sole reader: the same function. No client can read it
(rules catch-all deny — A5 Q7). No admin surface reads it (A1 A-12).

### 3.1 States

| State | Predicate | Citation |
|---|---|---|
| `absent` | doc does not exist; `used = 0` | `CF:196` |
| `partial(n)`, `n ∈ 1..29` | `count = n < DAILY_QUOTA` | `CF:197, 201-207`; `DAILY_QUOTA = 30` at `CF:83` |
| `exhausted` | `count = 30` | `CF:197-200` |
| `rolled` | wall clock crosses 00:00 UTC → a **different document key** is addressed | `CF:192-193` |
| `orphaned` | permanent, for every past day, every deleted student, every offboarded school | A5 D-08 |

### 3.2 Legal transitions

- `absent → partial(1)` — first successful call of the UTC day, `CF:201-207` with `{merge:true}`.
- `partial(n) → partial(n+1)` — same.
- `partial(29) → exhausted` — same.
- `exhausted → exhausted` — the guard at `CF:197-200` throws **inside** the transaction, before
  `tx.set`, so the counter never reaches 31. This property holds and should be an explicit test.
- `* → rolled` — implicit; the old document is simply never addressed again.

### 3.3 The mandate's question, answered precisely

> *quota consumed then model fails — which state is the bucket left in, and can the user reach
> `answered` without a quota decrement or vice versa?*

**The bucket is left at `partial(n+1)` / `exhausted`, permanently.** The transaction commits at
`CF:483` before any failure is possible, and there is no path back. `[CONFIRMED]`

**`answered ⇒ decremented` HOLDS.** `[CONFIRMED]` Every route to the return at `CF:560` passes
`CF:483`; there is no branch, no cache, no early return, and no second entry point to the model loop.
This is a genuine structural property of the ordering and deserves to be stated as a *pass*.

**`decremented ⇒ answered` FAILS on five distinct paths.** `[CONFIRMED]` for four, `[INFERRED]` for the fifth:

| # | Path | Bucket after | Log row? |
|---|---|---|---|
| 1 | blank `message` → `invalid-argument` | n+1 | none |
| 2 | Vertex throws at `CF:534` → `internal` | n+1 | none |
| 3 | six iterations → `deadline-exceeded` (`CF:609`) | n+1 | yes, `ok:false` |
| 4 | 120 s container kill (`CF:479`) mid-loop | n+1 | none |
| 5 | client aborts at 70 s while the server completes (A3 M-01) | n+1 | yes, `ok:true` — for an answer nobody saw |

Paths 1, 2 and 4 are the dangerous set: **quota moved, audit trail silent.** An incident review
reading `assistantLogs` would conclude the question was never asked, while the meter says it was.

### 3.4 The reconciliation test QA-LEAD should run

Because `assistantLogs` over-counts on retries (no idempotency — A4 F-04) and under-counts on
paths 1/2/4 above, **neither collection can validate the other in either direction**:

```
count(assistantLogs where schoolId=X, studentId=Y, day=D)  ≠  assistantQuota/X_Y_D.count
```
…and the sign of the difference is not diagnostic. There is today no third source. `[CONFIRMED]`

### 3.5 The roll edge is a defect, not a boundary condition

`CF:192` `new Date().toISOString().slice(0,10)` on a UTC-clocked runtime; the bucket therefore runs
**05:30 IST → 05:30 IST**. The user-facing text on both surfaces promises a midnight reset:
`CF:199` "Please try again tomorrow" and `strings_assistant.xml:40` "It resets tomorrow." A5 D-06
traces this fully and I concur; the state-machine framing is that the `rolled` transition fires
5.5 hours later than every clock the student owns, so `exhausted → absent(next day)` is observably
false for a 20½-hour window after a late-evening exhaustion, and questions asked 00:00–05:29 IST are
billed to *yesterday's* bucket.

### 3.6 Illegal transitions permitted / required transitions impossible

| # | Item | Verdict | Citation |
|---|---|---|---|
| Q-I1 | `partial(n+1) → partial(n)` (refund) | **required, impossible** — no decrement anywhere | `CF:191-209`; own grep |
| Q-I2 | `exhausted → partial(0)` on a school transfer | **permitted and illegal** — the key embeds `schoolId` (`CF:193`), so a mid-day transfer resets the allowance to zero used | A5 D-08 |
| Q-I3 | `orphaned → deleted` (retention) | **required, impossible** — no `onSchedule`, no TTL field, invisible to `Data_service` | A5 D-05; own grep |
| Q-I4 | "questions remaining" surfaced to the student | **required, impossible** — client cannot read the doc (catch-all deny) and no counter is returned at `CF:560-564` | A5 Q7 |

---

## 4. SCHOOL ENABLEMENT — `schools/{schoolId}.ai_assistant_enabled`

### 4.1 States and the actor problem

| State | Predicate | Citation |
|---|---|---|
| `never-enabled` | field absent — the guard is `!== true`, so absent ≡ false | `CF:158` |
| `enabled` | field strictly `true` | `CF:158` |
| `disabled` | field set to anything else, or deleted | `CF:158` |

**`never-enabled → enabled` has no in-product actor.** `[CONFIRMED]` Three agents converged on this
independently (A1 A-3, A4 F-01, A5 D-04) and I confirm it as a *state-machine* fact rather than a
gap-in-the-UI fact: **the transition that turns the feature on cannot be performed by anything in
this codebase.** The only actor is a human with Firebase console or Admin-SDK access. A5 adds the
decisive corroboration that it cannot happen incidentally either — `School_config::save_profile()`
writes through an explicit `$allowed` whitelist (`School_config.php:383, 401`).

The consequence for the whole model: **states S4–S7 of §2, and every state of §3, are unreachable in
production today.** Any UAT that exercised them ran against a hand-mutated document, i.e. against a
state no onboarding path produces. `[INFERRED]`, `REQUIRES VERIFICATION` — read
`schools/{id}.ai_assistant_enabled` live; A5's item 5 (does any `assistantQuota` doc exist at all?)
is the cheaper single confirmation and I endorse it.

### 4.2 `enabled → disabled` mid-conversation — the mandate's named case

`loadContext` runs on **every** request (`CF:482`), so the flag is re-read per question and a disable
takes effect on the very next turn. There is no session, no lease, no grace window. Trace:

1. Student asks Q1–Q3, receives three answers. Client is in `answered`.
2. An operator (console only) sets `ai_assistant_enabled = false`.
3. Student asks Q4. `CF:481` passes, `CF:482` throws `failed-precondition` at `CF:158-160`.
4. **Quota is NOT consumed** — `CF:483` is never reached. This is correct and is the one thing the
   ordering gets right; contrast S-I1.
5. `VM:90-99` sets `unavailableReason` → client enters `unavailable`.
6. `SC:99-102` early-returns → **the three earlier answers vanish** (C-I1).
7. `disabled → enabled` five minutes later is **invisible to that client** (C-I2): no polling, no
   re-check, no reset path. The student must guess to press Back and re-navigate through search —
   which per A3 M-12 is the feature's only entry point, three taps deep.

`[CONFIRMED]` for every step from code; `[INFERRED]` for the user-observable sequence.

### 4.3 The parallel machine nobody modelled: `student.status`

`CF:172-174` denies when `String(student.status).toLowerCase() !== 'active'`. Its transitions
(`active → inactive`, e.g. a TC issued or a record archived mid-term) map through `PERMISSION_DENIED`
to the **same** absorbing client state as a school-level disable (`VM:91`), and the fallback text
tells the student the *school* has not enabled the feature (`strings_assistant.xml:38`). A3 M-04
identifies the mislabelling; the state-machine point is stronger: **two machines with different
owners, different remedies and different urgencies are collapsed into one absorbing client state
with one message.** A support engineer receiving "the school hasn't enabled it" cannot tell whether
the fix is a console flag, a re-login, or a student re-activation.

### 4.4 Required transitions impossible

| # | Required | Why impossible | Citation |
|---|---|---|---|
| E-B1 | an operator enables/disables a school from the panel | no controller, view, model, migration or RBAC module exists | A1 A-3/A-5; A5 D-04 |
| E-B2 | the enable action records who consented and when | `CF:156-157` claims the flag is "where the consent conversation is recorded"; a console edit records nothing — no audit row, no actor, no timestamp | `CF:156-160` |
| E-B3 | the client learns the feature is off *before* the student types a question | `SearchViewModel.kt:236` lists the feature unconditionally; there is no client-side flag read | A1 A-11 |

---

## 5. DISPUTES

I am obliged to form my own view and to say where it differs. Three disputes, all additive rather
than contradictory — in each case the other agent's fact is right and its *scope* is too narrow.

### DISPUTE 1 — against A3 M-01 + A3 Q4, and against A4 F-03/Q3: `DEADLINE_EXCEEDED` is two states wearing one name, and the second one bills for an answer that is delivered to nobody

A3's Q4 table (its line 345) maps `DEADLINE_EXCEEDED → assistant_too_long` as a single condition, and
A4's Q3 enumerates the billed-failure paths without it. **There are two structurally different
producers of that one code, and they leave the system in opposite states:**

| Producer | Server ends in | Quota | Log row | What the student is told |
|---|---|---|---|---|
| Iteration cap, `CF:609-610` | S7 | consumed | `ok:false` (`CF:607`) | "That took too many steps" — accurate |
| Firebase Android SDK's own 70 s timeout on `RP:41-44` (no `.withTimeout`), against a 120 s server budget (`CF:479`) | **S6 `answered`** | consumed | **`ok:true`** | "That took too many steps" — **false; nothing took any steps** |

The second row is a state neither A3 nor A4 named: **`answered-into-the-void`.** The server completed
successfully, billed a unit, and wrote an audit row asserting a successful answer — for a reply that
was never delivered. It is the single hardest state to reason about after an incident, because
`assistantLogs` records it as a *success* while the student experienced a failure and, per A3, was
told the fault was in their question. A retry then costs a second unit for the same answer.

A3 has the client half (M-01) and A4 has the quota half (F-03); neither joined them, and A3's own
error table then re-flattens the code back to one cause. `[CONFIRMED]` for both producers from code;
`[INFERRED]` for the 70 000 ms SDK default, which A3 also marks `REQUIRES VERIFICATION` — this is
the highest-value single runtime check in the whole module.

### DISPUTE 2 — against A4's "things this function gets right": *"`consumeQuota` is a real transaction, so concurrent calls cannot overshoot"* is true of the situation, not of the code

I agree the property holds **today**. I dispute that the transaction is what secures it. `CF:195-207`
is a read-modify-write — `used = snap.data().count` then `tx.set(..., { count: used + 1 }, {merge:true})`
— not `FieldValue.increment(1)`. Serialisability protects it only against *other transactions on the
same document*. It does not protect against a non-transactional writer, and `{merge:true}` on a
scalar means such a writer's value is silently overwritten rather than combined.

There is no such writer today (own grep: `assistantQuota` appears at `CF:103, 193` only). But every
change A5 correctly says this module needs — a retention sweep (D-05), an "admin reset a student's
quota" tool, a DPDP erasure path (D-08) — is exactly such a writer. The invariant "count is
monotonic and capped at `DAILY_QUOTA`" is currently an emergent property of there being one writer,
and is enforced nowhere. Filing it under "gets right" invites the next author to add the second
writer without noticing. `[CONFIRMED]` for the code shape; `[INFERRED]` for the future hazard.

### DISPUTE 3 — against A5 D-03 and its per-collection table: `get_timetable`'s raw `d.data()` is an *over-projection* defect independent of the missing session filter

A5 notes "(the CF returns `d.data()` raw, `:361`)" as a parenthetical supporting its
session-interleaving argument. I dispute the scoping: this is a second, separable defect and A5's
own Q4 field-drift audit could not have caught it, because a tool that projects nothing cannot
exhibit field drift.

Four of the five read tools project an explicit allow-list before the payload enters the model
prompt — `CF:297-303` (attendance), `CF:318-326` (homework), `CF:338-348` (fees), `CF:374-384`
(results). `get_timetable` alone returns `q.docs.map((d) => d.data() || {})` (`CF:361`) — the whole
document. Per the canonical shape
(`ZenXII_Parent/.../data/model/firestore/TimetableDoc.kt:13-24` and `PeriodDoc` at `:29-38`) that
ships `periods[].teacherId` — a `STA…` staff identifier — plus `periods[].teacher`, `room`, `type`
and `updatedAt` into a child-facing model prompt, none of which any answer needs.

Two consequences A5's framing misses:
1. **A staff identifier is now model-visible and therefore narratable.** Not a G1 or I1 breach — same
   tenant, and the student sees the teacher's name on the Timetable screen anyway — but `teacherId`
   is an internal key with no place in a chat reply, and `CF:472-473` already concedes that retrieved
   text is hostile.
2. **It is the module's only unbounded input surface.** Any field a future writer adds to
   `timetables` reaches Vertex automatically, with no code change, no review and no test. Every other
   tool would require an edit. Fixing A5's D-03 session filter does not fix this; they need separate
   remedies.

`[CONFIRMED]` for the raw return and the document shape; `[INFERRED]` for what live docs carry.

---

## 6. Illegal-but-permitted transitions, ranked for QA-LEAD

Ranked by (blast radius × ease of triggering), not by severity label.

| Rank | ID | Transition | Why it ranks here |
|---|---|---|---|
| 1 | S-I2 | `quota-consumed → internal`, no log | one Vertex/IAM fault burns every active student's full day, with no audit record of what happened; unrecoverable until 05:30 IST |
| 2 | DISPUTE 1 | `answered → delivered-to-nobody` (70 s vs 120 s) | bills and logs a *success* the student never saw, then blames their question; invisible in every existing record |
| 3 | S-I1 | `quota-consumed → invalid-argument` | trivially triggerable by any client; seven lines of reordering fixes it |
| 4 | C-I1 + C-I2 | `answered → unavailable`, absorbing, transcript erased | a console flag flip retroactively deletes a student's answers with no recovery path even after the condition clears |
| 5 | C-I5 | `in-flight → destroyed` while the server bills | no surface anywhere can tell the student what happened (client + logs + quota all unreadable) |
| 6 | S-I4 | `model-loop(6) → deadline-exceeded` discards a built handoff | destroys the feature's only escape hatch at the exact moment it was needed |
| 7 | S-I5 | stale `className`/`section` widens the read | returns a *full, plausible, wrong* result set rather than an empty one |
| 8 | C-I4 | two consecutive `user` turns after an error | may poison a conversation permanently; `[UNKNOWN]` model tolerance |
| 9 | C-I3 | `in-flight → error` on coroutine cancellation | latent today; becomes live the moment a Stop/retry control is added |
| 10 | Q-I2 | school transfer resets the day's allowance | low frequency, no data harm |

---

## 7. What this model could not establish (E2 ceiling)

1. The Firebase Android SDK's effective callable timeout (70 000 ms assumed) — decides DISPUTE 1.
2. Whether Gemini 3.1 Flash-Lite accepts two adjacent `user` turns — decides C-I4's severity.
3. Whether `popBackStack` clears the `NavBackStackEntry` `ViewModelStore` on this Navigation
   version — decides whether C-I2 has *any* escape. (A3 also flags this.)
4. Whether the runtime service account holds `roles/aiplatform.user` — decides whether S-I2 is a
   tail risk or the default outcome of the first deploy.
5. Whether any live `schools` doc has `ai_assistant_enabled === true` — decides whether §2 S4–S7 and
   all of §3 have ever executed.
6. p95 wall time of a six-iteration turn against both the 70 s and 120 s deadlines.
