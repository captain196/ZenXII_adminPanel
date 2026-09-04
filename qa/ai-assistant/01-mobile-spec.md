# A3 · MOBILE-SPEC — "Ask ZenXii" on Parent Android

**Agent:** A3 · MOBILE-SPEC · reports to QA-LEAD
**Date:** 2026-08-31
**Scope:** the Parent Android implementation of the AI Assistant only.
**Evidence ceiling: E2** — static source reading. Nothing here was executed, installed,
rotated, or profiled. Every runtime consequence is marked `REQUIRES VERIFICATION`.

## Parity note (stated once, not analysed — belongs to another agent)

The AI Assistant exists on **Parent Android only**.

- Teacher Android: `ZenXII_Teacher/app/src/main/java/com/schoolsync/teacher/ui/` contains no
  `assistant` package — verified by directory listing; there is no Teacher implementation.
- iOS: neither product has an iOS surface in this workspace at all.

That is a parity gap, not a Parent defect. It is not analysed further below.

## Surface under test — 7 files, ~600 LOC

| File | Role |
|---|---|
| `ZenXII_Parent/app/src/main/java/com/schoolsync/parent/ui/assistant/AssistantScreen.kt` (354L) | all UI |
| `.../ui/assistant/AssistantViewModel.kt` (123L) | all state + error mapping |
| `.../data/repository/AssistantRepository.kt` (68L) | the one callable |
| `.../data/model/AssistantMessage.kt` (29L) | in-memory turn model |
| `.../ui/navigation/NavGraph.kt:205-207, 837-844` | route + composable |
| `.../ui/search/SearchViewModel.kt:231-238` | the **only** entry point |
| `app/src/main/res/values{,-hi,-mr,-gu,-ta,-te}/strings_assistant.xml` | 25 strings × 6 |

---

# Findings (§9 schema)

Each finding carries nine fields: **ID · Title · Severity · Classification · Evidence
(path:line) · Trigger · Impact · Invariant/Rule · Remedy.**

---

## M-01 — Client aborts at 70 s; the server is budgeted for 120 s
- **Severity:** HIGH
- **Classification:** `[INFERRED]` (code confirmed; the 70 s figure is the Firebase SDK default)
- **Evidence:** `AssistantRepository.kt:41-44` — `Firebase.functions.getHttpsCallable(CALLABLE).call(payload).await()`, no `.withTimeout(...)` anywhere in the file. Server: `~/Desktop/Zennxii_adminPanel/functions/studentAssistant.js:479` → `{ region: 'us-central1', timeoutSeconds: 120, memory: '512MiB' }`.
- **Trigger:** any multi-tool turn that takes more than 70 s server-side.
- **Impact:** the Android SDK's `HttpsCallableReference` default timeout is 70 000 ms. A turn between 70 s and 120 s is aborted **client-side** while the function is still running. The user is shown `assistant_too_long` ("That took too many steps. Try asking something more specific.") — which blames their question for a client timeout — and the server continues to completion, consuming model tokens and the daily quota for an answer nobody sees. `REQUIRES VERIFICATION` that a real turn ever exceeds 70 s.
- **Invariant:** client deadline must be ≥ server deadline, or the error message must not attribute the failure to the user.
- **Remedy:** `.withTimeout(130, TimeUnit.SECONDS)` on the callable reference, or reduce the CF budget below 70 s.

## M-02 — Every ViewModel-produced string uses `getString` on the Application context
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]` code deviation; `[INFERRED]` user impact
- **Evidence:** `AssistantViewModel.kt:87` `val ctx = getApplication<Application>()`, then `ctx.getString(...)` at `:96, :104, :106, :108, :109`. The codebase's own rule, at `util/LocaleManager.kt:197-199`: *"Use this instead of `Context.getString` anywhere the Context might be the application one — i.e. in every ViewModel."* `LocaleManager.kt:159-169` documents the exact failure and says it was **found on device**. Twenty-plus sibling ViewModels (`DashboardViewModel`, `FeesViewModel`, `NoticesViewModel`, `SearchViewModel`, …) use `localizedString`; `AssistantViewModel` is the outlier.
- **Trigger:** user changes language in-app (Profile → language, `ProfileScreen.kt:466`), which recreates the Activity but **not** the Application object, then triggers an assistant error.
- **Impact:** all five assistant error strings — unavailable, quota, signed-out, too-long, generic — render in the **process-start** language, not the chosen one. A parent who switched to Tamil sees a Tamil UI with English (or previous-language) error bubbles until the process is killed.
- **Invariant:** ViewModel strings resolve through `LocaleManager.resContext`.
- **Remedy:** replace the five `ctx.getString(` with `ctx.localizedString(`.

## M-03 — The handoff button label is server-supplied English and overrides the localized string
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]`
- **Evidence:** `AssistantScreen.kt:216` → `m.handoffLabel ?: stringResource(R.string.assistant_open_support)`. Server: `functions/studentAssistant.js:414-416` → `{ handoff: true, route: SUPPORT_COMPOSE_ROUTE, buttonLabel: 'Open Support' }`.
- **Trigger:** any handoff, in any locale.
- **Impact:** `handoffLabel` is **always** non-null, so the `?:` fallback is dead code and `assistant_open_support` — which is translated into all five Indian locales — is **never rendered**. The single most important call-to-action in the feature is permanently English. Translation work for that string is wasted.
- **Invariant:** user-visible text must come from resources, not from the server.
- **Remedy:** ignore `buttonLabel` and always use the resource, or have the server send a label *key*.

## M-04 — `PERMISSION_DENIED` is misreported as "your school hasn't enabled this" and is terminal
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]`
- **Evidence:** `AssistantViewModel.kt:90-99` — both `FAILED_PRECONDITION` **and** `PERMISSION_DENIED` set `unavailableReason` and return. The KDoc at `:78-85` justifies only `FAILED_PRECONDITION` ("the school has not switched the assistant on, or has no academic session set"); `PERMISSION_DENIED` is folded in silently with no stated rationale.
- **Trigger:** any genuine authorisation denial — expired/incorrect custom claims, a stale `school_id`/`schoolId` pair, a rules or CF guard rejecting this specific student.
- **Impact:** two structurally different conditions ("feature off for the tenant" and "you are not allowed to ask this") collapse into one screen state whose fallback text says the feature isn't available *for the school*. Support will be told the school never bought the feature when the real cause is a claims problem fixable by re-login. Worse, it is **terminal** (see M-05) — the user cannot retry after a token refresh.
- **Invariant:** an authz failure and a feature-flag failure must be distinguishable to the user and to support.
- **Remedy:** map `PERMISSION_DENIED` to a recoverable error bubble (or to the `assistant_signed_out` re-login path), leaving `unavailableReason` for `FAILED_PRECONDITION` alone.

## M-05 — The unavailable state is absorbing: no retry, no dismissal, raw server text
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]` for the state machine; `[INFERRED]` for recovery-by-navigation
- **Evidence:** `unavailableReason` has exactly **one** writer in the whole app (`AssistantViewModel.kt:96`) and is never reset — grep across `app/src/main/java/com/schoolsync/parent/` returns only the declaration `:22`, the write `:96`, and the two reads `AssistantScreen.kt:99-100`. `AssistantScreen.kt:99-102` does `if (ui.unavailableReason != null) { UnavailableNotice(...); return@Column }` — the early `return@Column` means the `LazyColumn`, the suggestion chips and the `Composer` are **never composed**.
- **Trigger:** one `FAILED_PRECONDITION` or `PERMISSION_DENIED`.
- **Impact:** (a) the composer is genuinely gone, not merely disabled — confirmed by the early return, so there is no hidden text field and no way to type; (b) the transcript is destroyed from view along with it, so the user loses any answers already received in that session; (c) there is no Retry control anywhere on the screen; (d) the **only** in-session escape is Back → re-navigate to `assistant`, which pops the `NavBackStackEntry`, clears the ViewModel and yields a fresh empty state — so recovery works, but only by accident and only if the underlying condition has cleared. `REQUIRES VERIFICATION` that popping actually clears the ViewModel on this Navigation version.
- **Secondary:** the text shown is `e.message` from the server, unlocalized and unbounded — see M-10.
- **Invariant:** no terminal UI state without an explicit recovery affordance.
- **Remedy:** add a Retry button that nulls `unavailableReason`; render the notice above the thread rather than replacing it.

## M-06 — The whole transcript is replayed with no client-side cap
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]`
- **Evidence:** `AssistantViewModel.kt:43-47` — `_ui.value.messages.filterNot { it.isError }.map { ... }`. No `takeLast`, no character budget, no token estimate anywhere in the file. `AssistantRepository.kt:34-39` forwards the list whole. `AssistantMessage.kt:5-9` asserts the design "keeps replayed token cost bounded" — nothing in the client bounds it.
- **Trigger:** a long session.
- **Impact:** **turn 40 sends 78 prior messages** (39 user + 39 assistant, minus any error bubbles, which *are* excluded) plus the new question. Payload and per-turn token cost grow linearly; total session cost grows quadratically. Cost and any server-side context limit are entirely the server's problem, and the client gives it no help. Note the transcript is correctly *not* double-counted: `history` is computed at `:43` **before** the new user turn is appended at `:49-55`, so `q` appears once. `REQUIRES VERIFICATION` of where the server truncates, if it does.
- **Invariant:** replayed context must be bounded client-side.
- **Remedy:** `takeLast(N)` (or a character budget) before mapping.

## M-07 — An empty or malformed `reply` renders a blank bubble — the one silent failure
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]`
- **Evidence:** `AssistantRepository.kt:58` → `text = (data["reply"] as? String).orEmpty()`. `:47` → `result.data as? Map<String, Any?> ?: emptyMap()`. `AssistantViewModel.kt:60-71` appends the reply unconditionally with no emptiness check; `AssistantScreen.kt:189-199` renders `m.text` with no empty guard.
- **Trigger:** the callable returns a non-map payload, or a map with `reply` absent, null, or non-String.
- **Impact:** the thinking indicator clears and an **empty assistant bubble** is added. To the user the assistant answered with nothing; nothing is logged, no error is shown, and the empty turn is then replayed to the server as context on every subsequent turn (M-06). This is the only path in the feature that fails without telling anyone.
- **Invariant:** fail closed and visibly.
- **Remedy:** treat a blank `reply` as a failure and route it through `handleFailure`.

## M-08 — Handoff route is an unvalidated server string handed to `navController.navigate`
- **Severity:** MEDIUM
- **Classification:** `[INFERRED]` for the crash; `[CONFIRMED]` for the absent validation
- **Evidence:** `AssistantRepository.kt:60` → `handoffRoute = handoff?.get("route") as? String` — accepted as-is, no allowlist. `AssistantScreen.kt:206-210` → `FilledTonalButton(onClick = { onNavigateRoute(route) })`. `NavGraph.kt:840-843` → `onNavigateRoute = { route -> navController.navigate(route) }`, with a comment conceding *"Navigating by name keeps the AI feature's only coupling to Support Desk a string."*
- **Trigger:** the server returns any route string the graph does not declare.
- **Impact:** `NavController.navigate(String)` throws `IllegalArgumentException("Navigation destination that matches route <x> cannot be found")` for an unregistered route — an uncaught exception on the main thread, i.e. an **app crash**, from a tap on an AI-produced button. Today's server only ever emits `'support_compose'` (`studentAssistant.js:92, 415`), which does match `NavGraph.kt:200 Route.SupportCompose = "support_compose"`, so the live path is safe. The exposure is that a model-influenced or future server value reaches `navigate()` with zero client validation, and parameterised routes exist in the graph (`support_thread/{ticketId}`, `receipt_detail/{receiptId}`) that a crafted string could target. `REQUIRES VERIFICATION` of the exact exception on this Navigation version.
- **Invariant:** never navigate to a server-supplied destination without an allowlist.
- **Remedy:** `when (route) { Route.SupportCompose.route -> navigate(...); else -> ignore }`, or wrap in `runCatching`.

## M-09 — No `SavedStateHandle`; process death silently empties the screen
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]` for absence; `[INFERRED]` for the restore behaviour
- **Evidence:** grep for `SavedStateHandle` and `rememberSaveable` across `ui/assistant/` returns **nothing**. State lives entirely in `AssistantViewModel.kt:32-33` (`MutableStateFlow(AssistantUiState())`) — messages, `isThinking`, `unavailableReason`, and the draft `input` (`:24`). `AssistantMessage.kt:5-8` states the transcript "lives only in memory for the life of the screen… not persisted to disk or to Firestore — a deliberate choice on a credential the whole household shares." The nav host is `rememberNavController()` (`NavGraph.kt:464`), which is saveable, so the *back stack* restores.
- **Impact:** see Q1 below. The loss is **intentional and documented for disk/Firestore persistence** (a defensible privacy choice on a shared household credential) — but process-death loss of an *in-flight* session is a different thing and is **nowhere communicated to the user**. After a restore they land on the Intro screen as if they had never asked anything; a half-typed question is gone too.
- **Invariant:** either restore transient session state or tell the user it is transient.
- **Remedy:** if the privacy stance forbids `SavedStateHandle` (which writes to the saved-instance bundle, not disk), say so in the Intro copy: "this conversation isn't saved."

## M-10 — `UnavailableNotice` has no scroll region — the documented clipping bug class
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]` for the layout; `REQUIRES VERIFICATION` for the clip
- **Evidence:** `AssistantScreen.kt:349-354` — `Box(Modifier.fillMaxSize().padding(32.dp), contentAlignment = Center) { Text(reason, …) }`. No `verticalScroll`. The `reason` is `e.message` from the server (`AssistantViewModel.kt:96`) and is therefore unbounded in length.
- **Trigger:** a long `FAILED_PRECONDITION` message, in landscape, or at a large system font scale.
- **Impact:** a centred `Text` in a `fillMaxSize` `Box` with 32 dp padding cannot scroll; overflow is clipped top and bottom with no indication. Landscape on a short device plus `fontScale 1.3+` is the realistic case. This is precisely the recurring bug class the file's own header comment at `:36-42` claims the screen avoids — and the header's third promise, *"nothing has a fixed height that could exceed a short landscape viewport,"* is honoured everywhere **except** here.
- **Remedy:** add `verticalScroll(rememberScrollState())`.

## M-11 — Accessibility: unlabelled decorations, unannounced progress, sub-48 dp chips
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]` for the code; `[INFERRED]` for TalkBack behaviour
- **Evidence:**
  - `AssistantScreen.kt:243` — `Icon(Icons.Filled.Check, null, …)`: null content description. Correct for a decoration, but the chip's meaning then rests entirely on the adjacent `Text` at `:245`, which is `10.5.sp` — below any legibility floor.
  - `AssistantScreen.kt:250-263` `ThinkingBubble` — a bare `CircularProgressIndicator` with no `contentDescription` and no `semantics { liveRegion }`. Nothing announces that the assistant is working. The `TopAppBar` subtitle does swap to `assistant_thinking` (`:73`), but a subtitle change in a non-live region is not announced either.
  - `AssistantScreen.kt:282-294` — suggestion chips are `Surface(onClick = …)` with `padding(horizontal = 12.dp, vertical = 6.dp)` around `12.2.sp` text. A clickable `Surface` does **not** receive Material3's `minimumInteractiveComponentSize()`; measured height is roughly 28–30 dp, below the 48 dp WCAG/Material target.
  - Font sizes throughout are `sp` (`14.2`, `13`, `12.2`, `11.5`, `11`, `10.5`) so they do scale — good — but the 10.5 sp and 11 sp values start below the accessible floor.
- **Impact:** a TalkBack user gets no signal that a request is in flight, and the tool chip — the feature's provenance disclosure — is a decoration plus 10.5 sp text.
- **Remedy:** `Modifier.semantics { liveRegion = LiveRegionMode.Polite; contentDescription = <thinking> }` on the bubble; `sizeIn(minHeight = 48.dp)` on the chips; raise the chip label.

## M-12 — One entry point: in-app search only
- **Severity:** LOW (discoverability) / relevant to UAT coverage
- **Classification:** `[CONFIRMED]`
- **Evidence:** an exhaustive grep for `ssistant` across `app/src/main/java/com/schoolsync/parent/` (excluding the feature's own package) returns matches in exactly **two** files: `NavGraph.kt` (import, route, composable) and `SearchViewModel.kt:236`. No dashboard tile (`DashboardScreen` passes 15 `onNavigateTo*` callbacks at `NavGraph.kt:572-606`; none is the assistant), no bottom-nav item (`NavGraph.kt:453-457`), and **no deep link** — no `assistant` match in `AndroidManifest.xml` or `util/DeepLinkBridge.kt`.
- **Impact:** the feature is reachable only by opening search and typing. Push cannot open it. A UAT script that starts from the dashboard will never find it.
- **Note:** the back-stack handling of that one path is correct — `NavGraph.kt:731-736` navigates with `popUpTo(Route.Search.route) { inclusive = true }`, so Back from the assistant lands on Dashboard, not on a stale search box.

## M-13 — The feature's own search entry is hardcoded English
- **Severity:** LOW
- **Classification:** `[CONFIRMED]`
- **Evidence:** `SearchViewModel.kt:236-238` → `feature("AI Assistant", "✨", Route.Assistant.route, "ai", "assistant", "ask", "ask zenxii", "chat", "help", "question", "doubt", "query", "bot", "helpdesk")`. Compare the sibling at `:241` which uses `appContext.localizedString(R.string.dash_pay_fees)`.
- **Impact:** the title and **every** search keyword are English. In the app's other five locales, a user searching in Hindi/Tamil/Marathi/Gujarati/Telugu cannot find the assistant — and since search is its only entry point (M-12), the feature is effectively **unreachable in 5 of 6 supported languages** unless the user happens to type Latin-script English. This is the highest-impact consequence of an otherwise cosmetic finding. Several sibling entries ("Attendance", "Homework", "Results") share the flaw, so it is a pre-existing pattern, not a new regression.
- **Remedy:** resource the title and add per-locale keyword sets.

## M-14 — `common_back` is declared inside a feature strings file
- **Severity:** LOW
- **Classification:** `[CONFIRMED]`
- **Evidence:** `values/strings_assistant.xml:12` (and `:16` in each of the five locale copies) declares `common_back`. A grep for `"common_back"` across `app/src/main/res/` shows it is declared **only** in the six `strings_assistant.xml` files.
- **Impact:** a generically named, app-wide string is owned by one feature's file. Deleting the assistant would delete `common_back` for whatever adopts it later; a future `strings.xml` adding the same name is a duplicate-resource build error. No current conflict.

## M-15 — Support back-stack comment does not hold when entered from the assistant
- **Severity:** LOW
- **Classification:** `[CONFIRMED]`
- **Evidence:** `NavGraph.kt:849-857` — the comment says *"Back from a freshly-raised ticket lands on the list rather than reopening an empty form,"* implemented as `navigate(SupportThread) { popUpTo(SupportCompose) { inclusive = true } }`.
- **Impact:** entered via the assistant handoff, the stack is Dashboard → Assistant → SupportCompose. After sending, it becomes Dashboard → Assistant → SupportThread, so Back lands on the **assistant** (with its transcript intact — arguably the better outcome), not on the ticket list the comment promises. Behaviour is fine; the comment is now inaccurate for the new caller.

## M-16 — P-05 (`is`-prefixed Boolean vs Firestore mapper drift): **NOT APPLICABLE**, checked explicitly
- **Severity:** INFO
- **Classification:** `[CONFIRMED]`
- **Evidence:** the only `is`-prefixed Boolean in the feature is `AssistantMessage.isError` (`AssistantMessage.kt:18`). It is written **only** locally at `AssistantViewModel.kt:118` and read only at `AssistantScreen.kt:176, 195` and `AssistantViewModel.kt:44`. It never crosses the wire: `AssistantReply` (`AssistantMessage.kt:24-29`) carries no Boolean at all, and `AssistantRepository.kt:47-61` parses the callable payload by **explicit map key** (`"reply"`, `"toolsUsed"`, `"handoff"`, `"route"`, `"buttonLabel"`) rather than by reflective deserialisation. The feature touches no Firestore document and no `data/model/firestore/` class. Manual key-based parsing is structurally immune to the `isX` → `x` mangling that P-05 describes.
- **Note for QA-LEAD:** this immunity is incidental, not designed. Any future move to a `@DocumentId`/POJO mapper for assistant data reintroduces the hazard.

## M-17 — No App Check on a paid, model-backed callable
- **Severity:** LOW (app-side observation; server enforcement is A2's call)
- **Classification:** `[CONFIRMED]` for the app
- **Evidence:** grep for `AppCheck` across `app/src/main/java/com/schoolsync/parent/` and `app/build.gradle.kts` returns **nothing** — no App Check dependency, no initialisation.
- **Impact:** the client presents no attestation to `studentAssistant`. The function still authenticates the user by ID token (`AssistantRepository.kt:12-14`), so this is not an authz hole, but a costed LLM endpoint has no client-integrity signal. Whether the server compensates is outside my mandate.

## M-18 — No way to cancel a turn; leaving the screen abandons a paid request
- **Severity:** LOW
- **Classification:** `[INFERRED]`
- **Evidence:** `AssistantViewModel.kt:57` launches in `viewModelScope`; there is no `Job` handle, no cancel entry point, and the UI offers no stop control (`AssistantScreen.kt:119-124` only disables the composer via `enabled = !ui.isThinking`).
- **Impact:** during a slow turn the user's only options are wait or press Back. Back pops the `NavBackStackEntry`, which clears the ViewModel and cancels `viewModelScope` — the client stops waiting, but the Cloud Function runs to completion and the daily quota is spent on a discarded answer. `REQUIRES VERIFICATION` on device.

---

# UI-element inventory

Every control, state and message a user can encounter. `path:line` for each.

### Chrome — always present unless noted

| Element | Evidence | Notes |
|---|---|---|
| `TopAppBar` title "Ask ZenXii" | `Screen:66-71` / `assistant_title` | 16 sp Medium |
| Subtitle — idle: "Your school assistant" | `Screen:72-77` / `assistant_subtitle` | 11.5 sp |
| Subtitle — busy: "Looking that up…" | `Screen:73` / `assistant_thinking` | swaps on `isThinking`; not a live region (M-11) |
| Back arrow (`ArrowBack`, auto-mirrored) | `Screen:80-88` | CD = `common_back`; `popBackStack()` at `NavGraph:839` |

### Empty state (`messages.isEmpty()`)

| Element | Evidence |
|---|---|
| `Intro` heading "Ask ZenXii" | `Screen:136-139` / `assistant_intro_title` |
| Intro body — attendance/homework/timetable/fees/results + "send it to the office" | `Screen:141-146` / `assistant_intro_body` |
| **AI disclosure** — "You're chatting with an AI assistant, not a member of school staff. It can see this student's records only." | `Screen:149-153` / `assistant_ai_disclosure`; 11 sp, `textTertiary` — legally load-bearing text rendered at the smallest size on the screen |
| 4 suggestion chips: My attendance · Homework due · Fees · Timetable | `Screen:266-296`; horizontally scrollable; `onPick = vm::send` (`Screen:117`) — the chip label **is** the question sent |

Chips vanish permanently after the first message (`Screen:117` is gated on `messages.isEmpty()`); the Intro likewise (`Screen:112`).

### Thread

| Element | Evidence |
|---|---|
| User bubble — right-aligned, `chatSent`, no border, corner 16/16/16/5 | `Screen:170-201` |
| Assistant bubble — left-aligned, `chatReceived`, 1 dp `glassBorder` | same |
| Error bubble — `errorBg` / `error` text, otherwise an assistant bubble | `Screen:176, 195`; set only via `VM:118` |
| Bubble max width 300 dp, text 14.2 sp / 20 sp line height | `Screen:187, 191-193` |
| **Tool chip** (assistant turns with `toolsUsed`) — check icon + one of 6 labels | `Screen:224-247` |
| — "Checked your attendance" | `get_attendance_summary` → `Screen:228` |
| — "Checked your homework" | `get_homework` → `:229` |
| — "Checked your fee records" | `get_fee_status` → `:230` |
| — "Checked your timetable" | `get_timetable` → `:231` |
| — "Checked your results" | `get_exam_results` → `:232` |
| — "Checked your records" (fallback) | `:233` |
| Only `tools.firstOrNull()` is shown — a multi-tool turn discloses **one** source | `Screen:227` |
| **Thinking bubble** — 14 dp `CircularProgressIndicator`, no text | `Screen:250-263` |
| **Handoff button** — `FilledTonalButton`, label from server ("Open Support"), M-03 | `Screen:206-220` |
| Auto-scroll to newest turn | `Screen:55-58`, `animateScrollToItem` |

### Composer (hidden entirely when `unavailableReason != null`)

| Element | Evidence |
|---|---|
| `OutlinedTextField`, placeholder "Ask about your school…" | `Screen:313-330` / `assistant_input_hint` |
| `maxLines = 4`, 22 dp rounded | `Screen:320-321` |
| IME action = Send; Enter/Send key submits when `enabled` | `Screen:322-323` |
| Send `FilledIconButton`, CD "Send", 18 dp icon | `Screen:332-344` / `assistant_send` |
| Send enabled iff `!isThinking && value.isNotBlank()` | `Screen:334` |
| Text field itself is **never** disabled — the user can keep typing while thinking | `Screen:313-330` (no `enabled` param) |

### Terminal state

| Element | Evidence |
|---|---|
| `UnavailableNotice` — centred 14 sp text, replaces the entire body | `Screen:349-354`, gated `Screen:99-102` |
| Default text: "The assistant isn't available for your school yet." | `assistant_unavailable`, used only as `?:` fallback (`VM:96`) — the server message usually wins |

### The five error messages

| Firebase code | User sees | Evidence |
|---|---|---|
| `FAILED_PRECONDITION` | terminal notice, server text | `VM:90-99` |
| `PERMISSION_DENIED` | terminal notice, server text — **misleading**, M-04 | `VM:91` |
| `RESOURCE_EXHAUSTED` | server text, else "You've reached today's question limit. It resets tomorrow." | `VM:103-104` |
| `UNAUTHENTICATED` | "Please sign in again to use the assistant." | `VM:105-106` |
| `DEADLINE_EXCEEDED` | "That took too many steps. Try asking something more specific." | `VM:107-108` |
| everything else | "Couldn't reach the assistant. Please try again." | `VM:109` |

---

# The nine questions

## Q1 — Rotation and process death

**(a) Rotation: nothing is lost.** `[CONFIRMED]` `AndroidManifest.xml:32` declares
`android:configChanges="orientation|screenSize|screenLayout|smallestScreenSize|keyboardHidden"`,
so `MainActivity` is **not recreated** on rotation at all. Transcript, draft, thinking state,
`unavailableReason` and the `LazyListState` all survive trivially. Even if the Activity *were*
recreated (it still is for `uiMode`, `fontScale`, `density`, `locale` — none of which are in that
list), the ViewModel is `hiltViewModel()` scoped to the `NavBackStackEntry`
(`AssistantScreen.kt:48`), whose `ViewModelStore` outlives a configuration change, so the transcript
survives that too. `REQUIRES VERIFICATION` on device.

**(b) Process death while backgrounded: everything is lost, silently.** `[INFERRED]`
`rememberNavController()` (`NavGraph.kt:464`) is saveable, so Android restores the back stack and
re-enters `Route.Assistant`. But the `ViewModelStore` does not survive process death, so a **fresh**
`AssistantViewModel` is constructed with `AssistantUiState()` — its defaults (`VM:18-24`) are an
empty message list, `isThinking = false`, `unavailableReason = null`, `input = ""`. The user is
returned to the Intro screen: **entire transcript gone, half-typed question gone, and an in-flight
request orphaned** (M-18). Because `unavailableReason` also resets, process death is ironically the
only reliable way out of the terminal state (M-05).

**Is `SavedStateHandle` used?** **No.** `[CONFIRMED]` — grep for `SavedStateHandle` and
`rememberSaveable` across `ui/assistant/` returns nothing. The ViewModel constructor
(`VM:27-30`) takes only `Application` and `AssistantRepository`.

**Is the loss intentional per the comments?** **Partly.** `[CONFIRMED]` `AssistantMessage.kt:5-8`
and `AssistantRepository.kt:17-19` both state that non-persistence is deliberate — a privacy choice
on a household-shared credential, plus cost. But both talk about **disk and Firestore**.
`SavedStateHandle` is neither; it is the saved-instance bundle. No comment anywhere addresses
process-death loss of an active session, so this is best read as an unexamined consequence of a
deliberate stance, not a decision.

**Is it communicated to the user?** **No.** `[CONFIRMED]` — none of the 25 strings in
`values/strings_assistant.xml` mentions that the conversation is not saved. The Intro
(`assistant_intro_body`, `assistant_ai_disclosure`) discloses that it is an AI and what it can see,
but says nothing about persistence.

## Q2 — Double submission (invariant G5)

**The guard is `AssistantViewModel.kt:39`: `if (q.isEmpty() || _ui.value.isThinking) return`.**
`[INFERRED]` — it closes the double-tap window, and it does so by accident of threading rather than
by design.

Trace: `send()` is not `suspend`. Between the `_ui.value.isThinking` read at `:39` and the
`_ui.update { … isThinking = true }` at `:49-55` there is no suspension point — only a
`filterNot`/`map` over an in-memory list (`:43-47`). Every caller is a Compose callback on the main
thread: `Composer.onSend` (`Screen:123`), the IME action (`Screen:323`), and `SuggestionChips.onPick`
(`Screen:117`). Since they cannot interleave on a single thread, the check-then-set is effectively
atomic. A fast double-tap, and Enter-plus-button, are both rejected: the second call reads
`isThinking = true` and returns.

Three layers of defence in depth reinforce it: the send button is `enabled = enabled &&
value.isNotBlank()` (`Screen:334`), `input` is cleared to `""` at `:52` so `isNotBlank()` fails, and
`enabled` is `!ui.isThinking` (`Screen:121`). The IME path also re-checks `if (enabled)`
(`Screen:323`).

**Where it does not close:**
1. `[CONFIRMED]` The guard is **not** thread-safe as written. `_ui.value` read → compute → `_ui.update` is a non-atomic read-modify-write on a `MutableStateFlow`. It holds today only because all callers are main-thread. Any future caller off the main thread (a retry, a voice input, a test) reopens the window with no compiler or lint signal. A `compareAndSet` on `isThinking` would make the invariant structural.
2. `[CONFIRMED]` There is **no idempotency key**. `AssistantRepository.ask` (`:30-39`) sends `{message, messages}` only. If the SDK retries the callable, or the user retypes the same question after a timeout, the server has no way to recognise a duplicate — so G5 holds on the client but is not enforceable end-to-end.

**Verdict: G5 holds for the stated attack (double-tap, Enter+button) `[INFERRED]`, but rests on an implicit single-thread assumption that is nowhere documented.**

## Q3 — History replay growth

**No cap of any kind.** `[CONFIRMED]` — see M-06. `VM:43-47` maps the full non-error transcript;
`Repository:34-39` forwards it whole; there is no `take`, `takeLast`, character budget or token
estimate anywhere in the three files.

**Turn 40 sends:** 39 prior user turns + 39 prior assistant turns = **78 messages** in the `messages`
array (fewer only if some turns were errors, which are filtered at `:44`), **plus** the 40th question
in the top-level `message` field. Error bubbles are excluded; handoff route/label are dropped
(only `m.text` is mapped at `:46`), so the assistant's prose is replayed but its chrome is not.
No duplication of the current question: `history` is snapshotted at `:43` before the append at
`:49-55`.

## Q4 — Error handling

| `FirebaseFunctionsException.Code` | Handled? | User sees | Evidence |
|---|---|---|---|
| `FAILED_PRECONDITION` | explicit | **terminal** notice; server `e.message`, else `assistant_unavailable` | `VM:90, 96` |
| `PERMISSION_DENIED` | explicit | **terminal** notice, same text — wrong story, M-04 | `VM:91` |
| `RESOURCE_EXHAUSTED` | explicit | server `e.message`, else `assistant_quota_reached` | `VM:103-104` |
| `UNAUTHENTICATED` | explicit | `assistant_signed_out` | `VM:105-106` |
| `DEADLINE_EXCEEDED` | explicit | `assistant_too_long` | `VM:107-108` |
| `OK`, `CANCELLED`, `UNKNOWN`, `INVALID_ARGUMENT`, `NOT_FOUND`, `ALREADY_EXISTS`, `ABORTED`, `OUT_OF_RANGE`, `UNIMPLEMENTED`, `INTERNAL`, `UNAVAILABLE`, `DATA_LOSS` | **generic** | `assistant_generic_error` | `VM:109` |
| non-`FirebaseFunctionsException` (`IOException`, `JSONException`, network, `ClassCastException`) | **generic** — `code` is `null` via the safe cast at `VM:88`, so `when` falls to `else` | same | `VM:88, 109` |

**Twelve of the seventeen codes fall to the generic branch**, including the two most common real-world
failures: `UNAVAILABLE` (no network / cold start) and `INTERNAL` (server threw). Both render
"Couldn't reach the assistant. Please try again." — acceptable for `UNAVAILABLE`, actively misleading
for `INTERNAL`, where retrying will fail identically.

**Is any failure silent?** **Yes, one.** `[CONFIRMED]` — M-07: a missing/empty/non-String `reply`
produces an **empty assistant bubble** with no error, because `Repository:58` coerces via `.orEmpty()`
and neither the ViewModel nor the screen checks for blank text. Every *exception* path is visible;
the malformed-success path is not. There is also **no logging at all** in the feature — no `Log.e`,
no crash reporter call in any of the four files — so a support engineer has nothing to work from.

## Q5 — Is the unavailable state terminal?

**Yes, in-session. And the composer is genuinely gone, not disabled.** `[CONFIRMED]` — see M-05.

- `unavailableReason` has exactly one writer (`VM:96`) and **no reset path** — verified by grep across the whole app, which returns only the declaration, that write, and two reads.
- `AssistantScreen.kt:99-102` early-returns from the `Column` before the `LazyColumn`, the chips and the `Composer` are composed. They are not merely `enabled = false` — they do not exist in the tree. Nothing can be typed and no prior answer can be re-read.
- There is no Retry, no dismiss, no pull-to-refresh anywhere on the screen.
- **The only escape** is to leave the screen and come back: Back (`NavGraph:839`) pops the `NavBackStackEntry`, which clears its `ViewModelStore`, so a fresh ViewModel is built on re-entry from search. `[INFERRED]` — `REQUIRES VERIFICATION`. This is undiscoverable, and given M-12 it means navigating to search and re-typing "assistant".
- Because `PERMISSION_DENIED` also lands here (M-04), a transient claims problem — exactly the thing a re-login fixes — permanently bricks the screen for the session.

## Q6 — Handoff with an unrecognised route

`[INFERRED]` — see M-08. `AssistantRepository.kt:60` accepts `handoff.route` with no validation;
`AssistantScreen.kt:210` passes it to the callback; `NavGraph.kt:843` calls
`navController.navigate(route)` on it directly. For a route not declared in the graph, Navigation
Compose throws `IllegalArgumentException("Navigation destination that matches route <x> cannot be
found in the navigation graph")` from the main thread with no `try`/`catch` in the chain →
**app crash**. `REQUIRES VERIFICATION` of the exact exception on this version.

The live path is safe today: the server's only emission is `'support_compose'`
(`functions/studentAssistant.js:92, 415`), which matches `NavGraph.kt:200` exactly. The residual
risk is that a *model-influenced* string reaches `navigate()` unfiltered, and that the graph contains
parameterised destinations (`support_thread/{ticketId}` at `NavGraph.kt:201`,
`receipt_detail/{receiptId}`) that a crafted value could reach. A one-line `when` allowlist in
`NavGraph.kt:843` closes it.

## Q7 — IME, small screens, landscape

**The three promises in the file header (`Screen:36-42`) hold for the main screen; the fourth surface breaks the third.** `[CONFIRMED]` for structure, `REQUIRES VERIFICATION` for rendering.

- **Single scrolling region:** yes. The `LazyColumn` at `Screen:104-115` carries `weight(1f)` and is the only vertical scroller in the body. The chips at `:274-279` scroll **horizontally** only, which does not conflict.
- **`imePadding`:** applied at `Screen:97` on the body `Column`, i.e. outside the composer, so the keyboard lifts the whole body and the composer rides above it. This is only correct because `MainActivity.kt:65` calls `enableEdgeToEdge()` — without `decorFitsSystemWindows = false` the IME insets never reach Compose and `imePadding()` is a no-op. `AndroidManifest.xml` declares no `windowSoftInputMode`, so the default `adjustResize` applies. No double-inset: Scaffold's default `contentWindowInsets` is system-bars, not IME.
- **Fixed heights:** none on the main path. `widthIn(max = 300.dp)` (`Screen:187`) is a *width*. `maxLines = 4` (`Screen:320`) caps composer growth. Sizes at `:243` (11 dp icon) and `:259` (14 dp spinner) are icons, not containers.
- **The exception — M-10:** `UnavailableNotice` (`Screen:349-354`) is a `fillMaxSize` `Box` with 32 dp padding and a centred, unscrollable `Text` whose content is unbounded server text. In landscape at a raised font scale this clips with no affordance. It is the one place the header's "nothing has a fixed height that could exceed a short landscape viewport" is violated in spirit.
- **Landscape squeeze:** `[INFERRED]` in landscape with the keyboard open, the top bar + `imePadding` + a 4-line composer can drive the `weight(1f)` thread toward zero height, so the user types with no visible conversation. Compose will not crash — `weight` floors at 0 — but the screen becomes unusable. `REQUIRES VERIFICATION` on a short device.
- **Auto-scroll:** `Screen:55-58` indexes `messages.size + isThinking - 1`. The index is correct in every reachable state because the `Intro` item (`:112`) is present only when `messages.isEmpty()`, and `send()` appends the user turn *before* setting `isThinking` (`VM:49-55`), so an empty-list-plus-thinking state cannot occur.

## Q8 — Accessibility

See M-11. Summary:

- **Content descriptions:** present and correct on the two real controls — Back (`Screen:84`, `common_back`) and Send (`Screen:341`, `assistant_send`). Absent by design on the tool-chip check icon (`Screen:243`, `null`) — defensible as decoration. **Missing where it matters:** the thinking indicator (`Screen:250-263`) has none, and the suggestion chips rely on their `Text` alone.
- **Touch targets:** Back and Send are `IconButton`/`FilledIconButton`, which Material3 expands to the 48 dp interactive minimum — `[INFERRED]`, `REQUIRES VERIFICATION` for `FilledIconButton` on Compose BOM 2024.02. The **suggestion chips fail**: a clickable `Surface` (`Screen:282-287`) receives no `minimumInteractiveComponentSize()`, and 12.2 sp text with 6 dp vertical padding measures roughly 28–30 dp. The handoff `FilledTonalButton` (`Screen:209`) meets the target.
- **Is the tool chip announced?** `[INFERRED]` **Partially and poorly.** The icon is silent; the label text is readable by TalkBack, but the chip is a plain `Row` with no `Modifier.semantics(mergeDescendants = true)`, so it is announced as a loose text node adjacent to the bubble rather than as one labelled unit. Its provenance meaning — "this answer came from your school's records" — is not conveyed as such.
- **Is the thinking indicator announced?** `[INFERRED]` **No.** A bare `CircularProgressIndicator` with no `contentDescription` and no `liveRegion`. The top-bar subtitle swap to `assistant_thinking` (`Screen:73`) is also not in a live region, so a screen-reader user gets **no** signal that their question is being processed — arguably the single worst a11y outcome here, since the wait can be tens of seconds (M-01).
- **Font scaling:** all sizes are `sp` and will scale — but `10.5.sp` (tool chip, `:245`) and `11.sp` (the AI disclosure, `:151`) start below the accessible floor, and the disclosure is compliance text.
- **Colour:** `errorBg`/`error` (`Screen:176, 195`) distinguish error bubbles by colour alone; there is no icon or text prefix.

## Q9 — Localisation

**Coverage is complete and disciplined; three leaks are outside the resource system.**

- **Complete:** all six `strings_assistant.xml` files carry exactly **25** strings each (`values`, `-hi`, `-mr`, `-gu`, `-ta`, `-te`) — verified by count. Those six locales are exactly `LocaleManager.SUPPORTED` (`util/LocaleManager.kt:47-54`: en, hi, mr, gu, ta, te), so there is no unshipped locale and no missing translation. The locale files carry a compliance header (`values-hi/strings_assistant.xml:5-13`) forbidding softening of the AI disclosure or promising a ticket was filed — genuinely good practice — and are honestly marked `MACHINE-ASSISTED, PENDING NATIVE REVIEW`.
- **The screen is correct:** `AssistantScreen.kt` resolves everything through `stringResource(...)` against `LocalContext`, which is the Activity context wrapped by `LocaleManager.wrap` in `MainActivity.kt:54-55`.

**Hardcoded user-visible strings in Kotlin — named:**

1. **`"AI Assistant"`** — `SearchViewModel.kt:236`, the feature's display name in search results. Its eleven search keywords on `:237-238` (`"ai"`, `"assistant"`, `"ask"`, `"ask zenxii"`, `"chat"`, `"help"`, `"question"`, `"doubt"`, `"query"`, `"bot"`, `"helpdesk"`) are likewise English-only. Since search is the sole entry point (M-12), **the feature is effectively unfindable in the five non-English locales** — the most consequential localisation defect in this report. `[CONFIRMED]`
2. **`"Open Support"`** — not in Kotlin but functionally identical: `functions/studentAssistant.js:416` sends it, and `AssistantScreen.kt:216` prefers it over the translated `assistant_open_support`, which is therefore **dead in all six locales**. M-03. `[CONFIRMED]`
3. **Server exception text** — `VM:96` (`unavailableReason = e.message`) and `VM:104` (quota) display the raw `HttpsError` message in preference to the translated resource. Whatever `studentAssistant.js` throws is English. So the two most likely failure messages a real user meets are untranslated. `[CONFIRMED]`

**Plus the wrong-context bug, M-02:** all five ViewModel error strings use `ctx.getString` on the
**Application** context instead of the codebase's mandatory `ctx.localizedString`
(`LocaleManager.kt:197-199`), against a documented, device-observed failure
(`LocaleManager.kt:159-169`). After an in-app language change without a process restart they render
in the previous language. `AssistantViewModel` is the only ViewModel in the app that does this.

**Not found:** no hardcoded strings inside `AssistantScreen.kt`, `AssistantRepository.kt` or
`AssistantMessage.kt`. Tool-name keys (`"get_attendance_summary"` etc., `Screen:228-232`) are
protocol identifiers matched against server values, not display text — correctly so.

---

# Unresolved / requires device verification

1. The 70 s callable default vs the 120 s CF budget (M-01) — needs a slow turn on device.
2. Whether popping the assistant off the back stack really clears the ViewModel, i.e. whether the terminal state (M-05) is escapable at all.
3. Whether `FilledIconButton` gets the 48 dp minimum on Compose BOM 2024.02.
4. Landscape + keyboard: does the thread `weight(1f)` collapse to unusable on a short device (Q7)?
5. The exact `navigate()` failure mode for an unknown route on this Navigation version (M-08).
6. Whether the server ever truncates the replayed history, which would bound M-06 in practice.
7. Whether `reply` can be empty in practice (M-07) — that is a server question for A2.
