# A1 · CARTOGRAPHER — AI Assistant ("Ask ZenXii") dependency graph

**Agent:** A1 · CARTOGRAPHER · reports to QA-LEAD
**Date:** 2026-08-31
**Evidence ceiling:** E2 (full static path traced). Nothing here was executed. No runtime claim is made.
**Repos in scope:** `~/Desktop/Zennxii_adminPanel` (branch `yug_testing`) · `~/AndroidStudioProjects/ZenXII_Parent` (branch `main`)

---

## 0. Working-tree state (context for every claim below)

`[CONFIRMED]` **The entire module is uncommitted on both repos.**

Admin repo — `git status --porcelain` in `/Users/yuggi/Desktop/Zennxii_adminPanel`:
```
 M functions/_smoke_assistant.js
 M functions/package.json
 M functions/studentAssistant.js
```
Parent repo — `git status --porcelain` in `/Users/yuggi/AndroidStudioProjects/ZenXII_Parent`:
```
 M app/src/main/java/com/schoolsync/parent/ui/navigation/NavGraph.kt
 M app/src/main/java/com/schoolsync/parent/ui/search/SearchViewModel.kt
?? app/src/main/java/com/schoolsync/parent/data/model/AssistantMessage.kt
?? app/src/main/java/com/schoolsync/parent/data/repository/AssistantRepository.kt
?? app/src/main/java/com/schoolsync/parent/ui/assistant/
?? app/src/main/res/values{,-gu,-hi,-mr,-ta,-te}/strings_assistant.xml
```
`[INFERRED]` Nothing is deployed and nothing is in either repo's history. Every artefact below exists on disk only. QA-LEAD should treat "live behaviour" as **[UNKNOWN]** for the whole module.

---

## 1. Artefact inventory by surface

### 1.1 Cloud Functions — `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/` (4 artefacts)

| # | Path | Lines | Role |
|---|---|---|---|
| CF-1 | `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/studentAssistant.js` | 648 | The whole server side: identity resolution, per-school gate, quota, 6 tool declarations + implementations, system prompt, the Gen-2 `onCall` agentic loop, audit logging |
| CF-2 | `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/index.js` | 663–672 | Wiring: `require('./studentAssistant')` and `exports.studentAssistant` — the only export of this module |
| CF-3 | `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/_smoke_assistant.js` | 245 | Local harness. Imports the **real** `SYSTEM_PROMPT`/`TOOLS`/`MODEL` via `studentAssistant.js:648` `exports._test`, runs them against the Gemini **Developer API with an API key** and **fixture** Firestore data |
| CF-4 | `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/package.json` | 9–13 | Declares `@google/genai ^2.19.0`, `firebase-admin ^12.7.0`, `firebase-functions ^5.0.0`; `engines.node = 20` |

Deploy config: `/Users/yuggi/Desktop/Zennxii_adminPanel/firebase-rules/firebase.json` — `functions[0].source = "../functions"`, `codebase "notice-circular-push"`, `runtime nodejs20`.

Key server constants — `studentAssistant.js:79-92`:
```js
const VERTEX_LOCATION = process.env.VERTEX_LOCATION || 'global';
const MODEL = process.env.VERTEX_MODEL || 'gemini-3.1-flash-lite';
const MAX_TOOL_ITERATIONS = 6;   const MAX_TURNS = 20;
const DAILY_QUOTA = 30;          const MAX_OUTPUT_TOKENS = 1024;   const QUERY_LIMIT = 25;
```
Callable options — `studentAssistant.js:478-479`: `onCall({ region: 'us-central1', timeoutSeconds: 120, memory: '512MiB' })`.

### 1.2 Parent Android app — `/Users/yuggi/AndroidStudioProjects/ZenXII_Parent/app/src/main/` (12 artefacts)

| # | Path | Lines | Role |
|---|---|---|---|
| P-1 | `java/com/schoolsync/parent/ui/assistant/AssistantScreen.kt` | 354 | The chat UI: top bar, intro + AI disclosure, message list, tool chips, handoff button, suggestion chips, composer, unavailable notice |
| P-2 | `java/com/schoolsync/parent/ui/assistant/AssistantViewModel.kt` | 123 | `@HiltViewModel`; owns `AssistantUiState`, replays history, maps `FirebaseFunctionsException` codes to user-facing strings |
| P-3 | `java/com/schoolsync/parent/data/repository/AssistantRepository.kt` | 68 | `@Singleton`; the single call site of the callable — `Firebase.functions.getHttpsCallable("studentAssistant")` (`:41-44, :66`) |
| P-4 | `java/com/schoolsync/parent/data/model/AssistantMessage.kt` | 29 | `AssistantMessage` (role/text/toolsUsed/handoffRoute/handoffLabel/isError) + `AssistantReply` |
| P-5 | `java/com/schoolsync/parent/ui/navigation/NavGraph.kt` | 85, 205–207, 837–845 | `import …assistant.AssistantScreen`; `data object Assistant : Route("assistant")`; the `composable(Route.Assistant.route)` that wires `onNavigateRoute` straight into `navController.navigate(route)` |
| P-6 | `java/com/schoolsync/parent/ui/search/SearchViewModel.kt` | 231–238 | The **only** discoverable entry point: a static feature-index row `feature("AI Assistant", "✨", Route.Assistant.route, …)` |
| P-7 | `res/values/strings_assistant.xml` | 43 (25 strings) | Base copy, incl. the mandated AI disclosure at `:22` |
| P-8..P-12 | `res/values-{hi,gu,mr,ta,te}/strings_assistant.xml` | 25 strings each | Hindi, Gujarati, Marathi, Tamil, Telugu translations — **full parity, 25/25 in every locale** (`grep -c "<string"`) |

`[CONFIRMED]` No Hilt module is needed or present: `AssistantRepository` uses `@Singleton class AssistantRepository @Inject constructor()` (`AssistantRepository.kt:21-22`) — pure constructor injection. `grep -rln "AssistantRepository" di/` in `java/com/schoolsync/parent` returns nothing; `di/` contains only `AppModule.kt`.

### 1.3 Firestore rules / indexes — **0 artefacts**

See §4 Absence register, A-1 and A-2.

### 1.4 Boundary artefact — Support Desk

| Path | Role |
|---|---|
| `/Users/yuggi/Desktop/Zennxii_adminPanel/functions/studentAssistant.js:92` | `const SUPPORT_COMPOSE_ROUTE = 'support_compose';` — the entire coupling, a string |
| `/Users/yuggi/AndroidStudioProjects/ZenXII_Parent/.../ui/navigation/NavGraph.kt:200` | `data object SupportCompose : Route("support_compose")` — the matching literal |

`[CONFIRMED]` The assistant writes nothing to any `support*` collection. `studentAssistant.js:385-403` returns a `{handoff:true, route, buttonLabel, suggestedSubject, suggestedDetails, category, guidance}` object and nothing else; the block comment at `:383-397` records that a `helpdeskTickets` write was removed on 2026-08-30 because ticket numbering, reporter identity and the push chain are owned by the Support Desk module.

`[CONFIRMED]` The two string literals are **coupled only by convention** — nothing in either repo asserts they match. A rename on either side fails silently (the app would `navigate("support_compose")` into a route that no longer exists, or the CF would emit a route the NavGraph cannot resolve).

---

## 2. Dependency graph

```
 ENTRY POINT (the only one)
 ┌──────────────────────────────────────────────────────────────┐
 │ Dashboard search row  ──onNavigateToSearch──▶  Route.Search  │  NavGraph.kt:587
 │ SearchScreen  ──result tap──▶ onNavigateRoute(route)         │  NavGraph.kt:726-737
 │   feature index row "AI Assistant ✨" → Route.Assistant       │  SearchViewModel.kt:236-238
 └──────────────────────────────────────────────────────────────┘
                              │
                              ▼
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ PARENT ANDROID APP  (com.schoolsync.parent, Compose + Hilt)                   │
 │                                                                              │
 │  composable(Route.Assistant.route)                          NavGraph.kt:837   │
 │      │  onBack → popBackStack                                                │
 │      │  onNavigateRoute → navController.navigate(route)     NavGraph.kt:843   │
 │      ▼                                                                       │
 │  AssistantScreen  (hiltViewModel())                    AssistantScreen.kt:45  │
 │    · Intro + assistant_ai_disclosure                              :129-155    │
 │    · SuggestionChips → vm::send                                   :118,266    │
 │    · Composer (imePadding, single scroll region)                  :96,104,119 │
 │    · MessageRow → ToolChip(toolsUsed)                             :158,225    │
 │    · MessageRow → FilledTonalButton(handoffRoute) ──────┐         :203-219    │
 │      │                                                  │                     │
 │      ▼ vm.send(text)                                    │                     │
 │  AssistantViewModel                                     │  AssistantVM.kt:37  │
 │    · builds history = messages.filterNot{isError}       │              :43-47 │
 │    · handleFailure maps FN exception codes → strings    │              :86-122│
 │      ▼ repo.ask(q, history)                             │              :59    │
 │  AssistantRepository                                    │  AssistantRepo.kt:30│
 │    payload = {message, messages:[{role,content}]}       │              :34-39 │
 │    NOTE: no schoolId / studentId is ever sent           │              :13-15 │
 └────────────────────┬────────────────────────────────────┼─────────────────────┘
                      │ Firebase Functions SDK (firebase-functions-ktx, BOM 32.7.4)
                      │ getHttpsCallable("studentAssistant")     AssistantRepo.kt:41-44,66
                      ▼                                          │
 ┌──────────────────────────────────────────────────────────────┼───────────────┐
 │ CLOUD FUNCTION  studentAssistant                             │               │
 │ onCall · us-central1 · 120s · 512MiB          studentAssistant.js:478-479     │
 │                                                              │               │
 │  1. resolveIdentity(request)                                 │       :120-141│
 │       school_id||schoolId , student_id||studentId , role     │       :126-128│
 │       role ∈ {student, parent} else permission-denied        │       :133-135│
 │  2. loadContext(schoolId, studentId)  ──READ──▶ schools/{schoolId}    :147-186│
 │                                       ──READ──▶ students/{sid}_{stu} │        │
 │       gate: school.ai_assistant_enabled !== true → FAILED_PRECONDITION :157-160│
 │       gate: blank currentSession → fail CLOSED                        :163-168│
 │       gate: student.status != 'active' → permission-denied            :173-175│
 │  3. consumeQuota()  ──TXN R/W──▶ assistantQuota/{sid}_{stu}_{UTCday}  :191-214│
 │       DAILY_QUOTA = 30 → RESOURCE_EXHAUSTED                           :198-200│
 │  4. agentic loop, max 6 iterations                                    :515-608│
 │       │                                                                       │
 │       ├── generateContent ─────────────────────────────────────┐              │
 │       │     systemInstruction = SYSTEM_PROMPT (tenant-agnostic,│      :440-476│
 │       │       byte-identical → implicit cache)                 │      :527-531│
 │       │     tools = [{functionDeclarations: TOOLS}] (6 tools)  │      :216-287│
 │       │     maxOutputTokens = 1024                             │              │
 │       │                                                        ▼              │
 │       │                                        ┌──────────────────────────┐   │
 │       │                                        │ EXTERNAL: Vertex AI      │   │
 │       │                                        │ @google/genai ^2.19.0    │   │
 │       │                                        │ GoogleGenAI({vertexai:   │   │
 │       │                                        │  true, project, location})│  │
 │       │                                        │ model gemini-3.1-flash-  │   │
 │       │                                        │  lite · location 'global'│   │
 │       │                                        │ auth = ADC, NO API KEY   │   │
 │       │                                        │      studentAssistant.js │   │
 │       │                                        │      :51,79-80,509-513   │   │
 │       │                                        └──────────────────────────┘   │
 │       │                                                                       │
 │       └── functionCalls → TOOL_IMPL dispatch (Promise.all)          :574-604  │
 │             get_attendance_summary ──GET───▶ attendanceSummary      :291-304  │
 │             get_homework           ──QUERY─▶ homework               :306-328  │
 │             get_fee_status         ──QUERY─▶ feeDemands             :330-350  │
 │             get_timetable          ──QUERY─▶ timetables             :352-364  │
 │             get_exam_results       ──QUERY─▶ results                :366-386  │
 │             raise_helpdesk_ticket  ──NO IO──▶ {handoff:true,        :404-424  │
 │                                       route:'support_compose'} ─────┼──▶ back │
 │                                                                     │  to app │
 │  5. writeLog()  ──ADD──▶ assistantLogs         (best-effort, try/catch) :620-645│
 │       question(≤500 chars), toolsUsed, iterations, ok, usage{...}             │
 │  6. return { reply, toolsUsed, handoff }                              :557-561│
 └───────────────────────────────────────────────────────────────────────────────┘
                                             │
                       handoff.route = "support_compose"
                                             ▼
 ┌───────────────────────── SUPPORT DESK BOUNDARY ────────────────────────────┐
 │ Route.SupportCompose = "support_compose"                    NavGraph.kt:200 │
 │ composable(Route.SupportCompose.route) → SupportComposeScreen  NavGraph:847 │
 │                                                                             │
 │ The assistant NEVER writes supportTickets / supportMessages /               │
 │ supportNotes / supportCounters / supportReporterIdentity.                   │
 │ studentAssistant.js:383-403 documents the removed write.                    │
 │ The Parent app does NOT pass suggestedSubject/suggestedDetails through:      │
 │ AssistantRepository.kt:57-62 reads only route + buttonLabel from handoff.   │
 └─────────────────────────────────────────────────────────────────────────────┘
```

`[CONFIRMED]` **The handoff drafting is dropped on the floor.** The CF returns `suggestedSubject`, `suggestedDetails` and `category` (`studentAssistant.js:594-600`), but `AssistantRepository.kt:57-62` maps only `route` and `buttonLabel` into `AssistantReply`, and `AssistantMessage.kt:16-17` carries only `handoffRoute`/`handoffLabel`. `NavGraph.kt:843` navigates with a bare route string and no arguments. So the drafted subject/details reach the app and are discarded; the student retypes them in SupportCompose.

---

## 3. Firestore collections

| Collection | Op | By whom | Key / shape | Citation |
|---|---|---|---|---|
| `schools` | READ (doc get) | CF, Admin SDK | `schools/{schoolId}` — reads `ai_assistant_enabled`, `currentSession` | `studentAssistant.js:149,157-168` |
| `students` | READ (doc get) | CF, Admin SDK | `students/{schoolId}_{studentId}` — reads `className`/`class`, `section`, `name`, `status` | `studentAssistant.js:150,173-185` |
| `attendanceSummary` | READ (doc get) | CF tool `get_attendance_summary` | `attendanceSummary/{schoolId}_{studentId}_{Month YYYY}` | `studentAssistant.js:292-294` |
| `homework` | READ (query) | CF tool `get_homework` | `where schoolId, sectionKey, status=='active', session` + `orderBy createdAt desc` + `limit 25` | `studentAssistant.js:309-316` |
| `feeDemands` | READ (query) | CF tool `get_fee_status` | `where schoolId, session, studentId` + `limit 25` | `studentAssistant.js:331-336` |
| `timetables` | READ (query) | CF tool `get_timetable` | `where schoolId, sectionKey` + `limit 25` — **no session filter** | `studentAssistant.js:355-359` |
| `results` | READ (query) | CF tool `get_exam_results` | `where schoolId, session, studentId` + `limit 25` | `studentAssistant.js:367-371` |
| `assistantQuota` | **READ + WRITE** (transaction, merge) | CF `consumeQuota` | `assistantQuota/{schoolId}_{studentId}_{YYYY-MM-DD}`; fields `schoolId, studentId, day, count, updatedAt` | `studentAssistant.js:192-213` |
| `assistantLogs` | **WRITE** (`.add()`, auto-id) | CF `writeLog` | fields `schoolId, studentId, session, role, question(≤500), toolsUsed, iterations, ok, usage{4 counters}, createdAt` | `studentAssistant.js:620-644` |

`[CONFIRMED]` All nine touches go through `admin.firestore()` (`studentAssistant.js:107`), i.e. the Admin SDK, which bypasses security rules.

`[CONFIRMED]` `get_timetable` is the one tool whose query carries **no `session` equality** — the other three query tools all filter `session` (`:313, :333, :369`). Cited as a mapped fact; A1 does not rule on whether that is correct.

`[CONFIRMED]` Composite indexes that the four tool queries would need already exist in `firestore.indexes.json`:
- `homework [schoolId ASC, sectionKey ASC, status ASC, session ASC, createdAt DESC]`
- `feeDemands [schoolId ASC, session ASC, studentId ASC, month ASC]` and `[…, status ASC]`
- `timetables [schoolId ASC, sectionKey ASC]`
- `results [schoolId ASC, session ASC, studentId ASC]`

`[INFERRED]` No new index is required by this module. `attendanceSummary` is a direct doc get and needs none. Not verified against live Firestore — that is A-2's job, not mine.

---

## 4. Absence register

Every item below was searched for and **not found**. The search is given verbatim.

| ID | What was sought | Search run | Result |
|---|---|---|---|
| A-1 | Firestore **rules** for `assistantQuota` / `assistantLogs` | `grep -n -i "assistant" firebase-rules/firestore.rules` | Only hit is line 244, the string `"Lab Assistant"` inside a comment about staff role titles. **No `match /assistantQuota` or `match /assistantLogs` block exists in the 3359-line file.** Both collections therefore fall to the catch-all `match /{document=**} { allow read, write: if false; }` at the file's tail. |
| A-2 | Firestore **indexes** naming the assistant | `grep -n -i "assistant" firebase-rules/firestore.indexes.json` | Zero matches (exit 1). No index was added for this module. |
| A-3 | **Admin Web (CodeIgniter) controller / view / setting** that sets `schools/{id}.ai_assistant_enabled` | `grep -rn "ai_assistant_enabled" --include="*.php" --include="*.js" --include="*.json" --include="*.html" ~/Desktop/Zennxii_adminPanel` | Only two hits, both in `functions/`: `index.js:669` (a comment) and `studentAssistant.js:158` (the read). **No PHP writes this flag. There is no UI, no controller, no model, no migration.** The per-school kill switch has no operator surface — it can only be set by hand in the Firestore console. |
| A-4 | Any admin-panel screen for the assistant | `grep -rn -i "ask zenxii\|ai assistant\|askZenxii" application/ assets/` | Zero matches. |
| A-5 | Assistant in the **RBAC catalogue / sidebar** | `grep -n -i "assistant" functions/rbac_modules.json application/helpers/rbac_helper.php` | Zero matches. The module is not an RBAC module and appears in no sidebar. |
| A-6 | **Teacher app** implementation | `grep -rn -il "assistant\|studentAssistant\|askZenxii" ~/AndroidStudioProjects/ZenXII_Teacher/app/src` | Zero matches. **Not built for staff at all.** |
| A-7 | **iOS** implementation | `grep -rn -il "assistant" ~/AndroidStudioProjects/zenxii-ios` (1490 `.swift` files present) | The only non-vendor hit is `ios-engineering/18_KNOWN_ISSUES.md:1468`, the unrelated string `'Lab Assistant'` in an RBAC discussion. Remaining hits are inside `ZenXiiCore/.build/checkouts/firebase-ios-sdk/` vendor code. `grep -rn -i "assistant\|studentAssistant" ZenXiiParent ios ZenXiiCore/Sources` → zero. **No iOS implementation.** |
| A-8 | A second Android copy | `find ~/AndroidStudioProjects/zenxii-ios/parent-android/app/src -iname "*ssistant*"` | Zero. That nested checkout (`origin = /Users/yuggi/AndroidStudioProjects/ZenXII_Parent`, HEAD `b54cc4b`) predates the feature. |
| A-9 | **Landing page / marketing** mention | `grep -rn -il "assistant\|ask zenxii" ~/AndroidStudioProjects/zenxii-landing` | Zero matches. |
| A-10 | **Analytics / Crashlytics** instrumentation | `grep -rn -i "analytics\|logEvent\|Crashlytics" ui/assistant/ data/repository/AssistantRepository.kt` | Zero matches. The app links `firebase-analytics-ktx` (`app/build.gradle.kts:190`) but the assistant emits **no** analytics event — not screen view, not send, not error, not handoff tap. |
| A-11 | **Client-side feature flag** | `grep -rn -i "assistant" --include="*.kt"` across `com/schoolsync/parent` | The search index row (`SearchViewModel.kt:236`) is unconditional. No client check of `ai_assistant_enabled`. `[INFERRED]` the feature is therefore always listed in search for every school and only fails at the callable, surfacing `AssistantUiState.unavailableReason` (`AssistantViewModel.kt:90-99`). |
| A-12 | **Background worker / scheduled job / retention** for `assistantLogs` or `assistantQuota` | `grep -rn "assistantQuota\|assistantLogs\|ASSISTANT_QUOTA\|ASSISTANT_LOGS" --include="*.js" functions/` | Only the four lines inside `studentAssistant.js` (103, 104, 193, 622). `grep -n "onSchedule\|pubsub\|schedule(" functions/index.js` → zero. **Nothing ever deletes a quota doc or a log doc.** Both collections grow without bound. |
| A-13 | **Push notification** from the assistant | `grep -c "pushRequests\|emit_push" functions/studentAssistant.js` | `0`. The module adds no `MARK_REGISTRY` row and sends no push. |
| A-14 | `test_assistant.js`, the harness named in the code | `ls functions/test_assistant.js` | `No such file or directory`. `studentAssistant.js:646-648` says `exports._test` exists "ONLY so the local harness (test_assistant.js) can drive…" — the file is named `_smoke_assistant.js`. **Stale reference; the file the comment names does not exist.** |
| A-15 | An **npm script** to run the smoke harness | `grep -n "scripts" functions/package.json` | No `scripts` block at all. The harness is run only by hand: `GEMINI_API_KEY=... node _smoke_assistant.js` (`_smoke_assistant.js:17`). |
| A-16 | **Unit tests** for the module (JVM or Jest) | `find` for `*ssistant*` across both repos | The only test-shaped artefact is `_smoke_assistant.js`, which requires a live Gemini key and a network call. **No offline test, no Jest rules test, no Kotlin unit test.** |
| A-17 | An entry point other than search — dashboard tile, bottom bar, drawer, FAB, deep link | `grep -rn -i "assistant" --include="*.kt"` across the whole app package | `Route.Assistant` is referenced in exactly **two** places: `NavGraph.kt:837` (the composable itself) and `SearchViewModel.kt:236` (the index row). Nothing else navigates to it. |

---

## 5. Entry points

`[CONFIRMED]` There is exactly **one** path a user can take to this feature, and it is three taps deep:

1. **Dashboard search row** → `onNavigateToSearch = { navController.navigate(Route.Search.route) }` — `NavGraph.kt:587`
2. **SearchScreen** — a result tap calls `onNavigateRoute(route)`, which navigates and pops Search off the back stack — `NavGraph.kt:726-737`
3. **The feature-index row** the user must find by typing or scrolling: `feature("AI Assistant", "✨", Route.Assistant.route, "ai", "assistant", "ask", "ask zenxii", "chat", "help", "question", "doubt", "query", "bot", "helpdesk")` — `SearchViewModel.kt:236-238`
4. → `composable(Route.Assistant.route) { AssistantScreen(...) }` — `NavGraph.kt:837-845`

`[CONFIRMED]` No deep link, no notification tap-through, no dashboard tile, no bottom-nav destination (A-17).

`[CONFIRMED]` The exit point is the reverse of the boundary: the handoff button at `AssistantScreen.kt:206-219` calls `onNavigateRoute(route)` → `NavGraph.kt:843` → `Route.SupportCompose`.

---

## 6. External dependencies

| Dependency | Version | Surface | Citation |
|---|---|---|---|
| `@google/genai` | `^2.19.0` | Cloud Function → Vertex AI | `functions/package.json:10`; used at `studentAssistant.js:51,509-513` |
| Vertex AI — model `gemini-3.1-flash-lite`, location `global`, project from `GCLOUD_PROJECT` (default `graderadmin`) | env-overridable via `VERTEX_MODEL` / `VERTEX_LOCATION` | Cloud Function | `studentAssistant.js:55,79-80,509-513` |
| Vertex auth | **Application Default Credentials — no API key** | Cloud Function | `studentAssistant.js:507-513` (`GoogleGenAI({vertexai:true, project, location})`, no `apiKey`) |
| `firebase-admin` | `^12.7.0` | Cloud Function Firestore access | `functions/package.json:11`; `studentAssistant.js:49,107` |
| `firebase-functions` | `^5.0.0` (v2 `onCall`) | Cloud Function | `functions/package.json:12`; `studentAssistant.js:48,50` |
| Node runtime | `20` | Cloud Function | `functions/package.json:6`; `firebase-rules/firebase.json` `runtime: nodejs20` |
| Gemini **Developer** API + `GEMINI_API_KEY` | n/a | **Smoke harness only** — a different auth surface from production, stated as such | `_smoke_assistant.js:26-32` |
| Firebase BOM | `32.7.4` | Parent app | `app/build.gradle.kts:186` |
| `firebase-functions-ktx` | (from BOM 32.7.4) | Parent app callable client | `app/build.gradle.kts:193`; `AssistantRepository.kt:3-4,41` |
| Hilt | `2.50` + KSP compiler | Parent app DI | `app/build.gradle.kts:182-183` |
| `hilt-navigation-compose` | `1.1.0` | `hiltViewModel()` in `AssistantScreen.kt:48` | `app/build.gradle.kts:179` |
| Compose BOM | `2024.02.00` · Material3 | Parent app UI | `app/build.gradle.kts:160,164` |
| `navigation-compose` | `2.7.7` | Route wiring | `app/build.gradle.kts:178` |
| Parent app targets | `compileSdk 36`, `minSdk 24`, `targetSdk 36`, `versionCode 4`, `versionName 1.0.2`, `applicationId com.schoolsync.parent` | Parent app | `app/build.gradle.kts:20,34-38` |

---

## 7. Notable findings (§9 schema)

**F-A1-01 · Doc/code drift: `index.js` names the wrong secret** — `[CONFIRMED]`
`functions/index.js:669-670` reads: `// school by schools/{id}.ai_assistant_enabled. Needs the` / `// ANTHROPIC_API_KEY secret. See ./studentAssistant.js.` The implementation uses `@google/genai` against Vertex with ADC and **no key of any kind** (`studentAssistant.js:507-513`, and the header at `:36-40` states "NO API KEY EXISTS"). The wiring comment describes a provider the code no longer uses. Evidence E2.

**F-A1-02 · The per-school kill switch has no operator surface** — `[CONFIRMED]`
`schools/{id}.ai_assistant_enabled` is read at `studentAssistant.js:157-160` and written **nowhere** in any repo (A-3). Evidence E2.

**F-A1-03 · The Support Desk draft is generated and discarded** — `[CONFIRMED]`
CF returns `suggestedSubject` / `suggestedDetails` / `category` (`studentAssistant.js:594-600`); the client reads only `route` and `buttonLabel` (`AssistantRepository.kt:57-62`) and navigates with no arguments (`NavGraph.kt:843`). Evidence E2.

**F-A1-04 · `assistantLogs` and `assistantQuota` have no rules block and no retention** — `[CONFIRMED]`
No `match` block (A-1) — they fall to the catch-all deny, which is consistent with Admin-SDK-only access. No cleanup job of any kind exists (A-12); `assistantLogs` stores the student's question text (≤500 chars, `studentAssistant.js:628`) with no expiry. Evidence E2.

**F-A1-05 · Discovery is search-only** — `[CONFIRMED]`
One entry point, three taps deep, behind a static index row the user must guess the name of (§5, A-17). Evidence E2.

**F-A1-06 · Whole module is uncommitted and undeployed** — `[CONFIRMED]` (§0). Evidence E2.

---

## 8. Open `[UNKNOWN]` — beyond A1's evidence ceiling

| ID | Question | Why A1 cannot answer |
|---|---|---|
| U-1 | Is `studentAssistant` deployed to `graderadmin`? | Requires the Functions API. Local git says it has never been committed (§0), but a colleague could have deployed from another machine. |
| U-2 | Do the four composite indexes exist **live**? | §3 shows them declared in `firestore.indexes.json`; live state needs `aegis indexes`. The index sentinel memo records 284 live vs 183 declared, so declared≠live in both directions. |
| U-3 | Is the deployed `firestore.rules` the same as disk? | A-1 is a claim about the file on disk only. `aegis rules status` is the authority; A1 cannot run it. |
| U-4 | Does any school actually have `ai_assistant_enabled: true`? | Requires a live Firestore read. Given A-3 (nothing writes it), `[INFERRED]` the answer is likely none, but that is unverified. |
| U-5 | Whether `gemini-3.1-flash-lite` is a valid Vertex model id at `location: 'global'` | External API surface; not statically checkable. |
| U-6 | Whether the CF's runtime service account holds `roles/aiplatform.user` | Not in any file in either repo; IAM is not in git. |
| U-7 | Do `homework.sectionKey`, `timetables.sectionKey` etc. actually use the `Class X/Section Y` composite the CF builds (`studentAssistant.js:111-113`)? | The code's own comment at `:110` warns a mismatch "does not error; it silently returns an empty result set". Confirming needs live data. |
