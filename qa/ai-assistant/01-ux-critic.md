# A10 · UX-CRITIC — "Ask ZenXii" on Parent Android

**Agent:** A10 · UX-CRITIC · reports to QA-LEAD
**Date:** 2026-08-31
**Scope:** the *experience* of the AI Assistant in the Parent app — discoverability, states,
refusal, handoff, disclosure, terminology, accessibility, layout, dead ends.
**Evidence ceiling: E2** — static source reading only. The app was not built, installed or
rendered. No contrast figure below was measured on a device; each is computed from the
declared hex values by the WCAG 2.1 relative-luminance formula. Every runtime consequence is
marked `REQUIRES VERIFICATION`.

**Overlap declared once:** A3 · MOBILE-SPEC already filed M-02 (ViewModel `getString`
localization), M-03 (English `buttonLabel` overriding the localized string) and M-05
(`unavailableReason` never resets). I do not re-derive those. Where a UX finding below shares
a root cause I say so and analyse only the *experience* consequence.

## Surface read

| File | Lines relied on |
|---|---|
| `.../ui/assistant/AssistantScreen.kt` | 1-354 (whole file) |
| `.../ui/assistant/AssistantViewModel.kt` | 1-123 (whole file) |
| `.../data/repository/AssistantRepository.kt` | 30-63 |
| `.../data/model/AssistantMessage.kt` | 10-29 |
| `.../ui/search/SearchViewModel.kt` | 26-33, 76, 212-238 |
| `.../ui/search/SearchScreen.kt` | 53-179, 194-236 |
| `.../ui/navigation/NavGraph.kt` | 205-207, 587, 828-844, 847-858 |
| `.../ui/dashboard/DashboardScreen.kt` | 200, 303-308 |
| `.../ui/support/SupportComposeScreen.kt` | 51-55 |
| `.../ui/support/SupportViewModel.kt` | 32-83, 277 |
| `.../ui/theme/ThemeVariants.kt` | 392-412, 434-435, 452-472, 494-495 |
| `.../ui/theme/Theme.kt` | 26-70, 91 |
| `res/values{,-hi,-mr,-gu,-ta,-te}/strings_assistant.xml` | 25 strings × 6, all present |
| `~/Desktop/Zennxii_adminPanel/functions/studentAssistant.js` | 404-424, 446-447, 584-595 |

All paths under `/Users/yuggi/AndroidStudioProjects/ZenXII_Parent/app/src/main/java/com/schoolsync/parent/`
unless shown otherwise.

---

# Findings (§9 schema)

Nine fields each: **ID · Title · Severity · Classification · Evidence · Trigger · Impact ·
Invariant/Rule · Remedy.**

---

## UX-01 — The assistant says it prepared a support request; the app throws the draft away and shows an empty form
- **Severity:** CRITICAL
- **Classification:** `[CONFIRMED]` — the discard is provable across four files; the user-facing
  wording is provable from the server prompt.
- **Evidence:** the server returns five handoff fields —
  `functions/studentAssistant.js:588-593` → `{ route, buttonLabel, suggestedSubject, suggestedDetails, category }`,
  built at `:413-419` from a 200-char subject and a 2000-char details body. The client reads
  **two**: `AssistantRepository.kt:60-61` → `handoffRoute = handoff?.get("route")`,
  `handoffLabel = handoff?.get("buttonLabel")`. `AssistantMessage.kt:16-17` declares only those
  two fields, so `suggestedSubject`, `suggestedDetails` and `category` have nowhere to land.
  `NavGraph.kt:205-207` — `data object Assistant : Route("assistant")`; `Route.SupportCompose`
  (`:200`) is `"support_compose"` with **no arguments**. `SupportComposeScreen.kt:51-55` takes
  `onBack` and `onSent` only — no prefill parameter. And the server explicitly instructs the
  model to promise the draft: `studentAssistant.js:421-423` — *"Tell the student you have
  prepared this for them … Say what the subject line will be."*
- **Trigger:** any conversation in which the model calls `raise_helpdesk_ticket`. The student
  reads "I've prepared this for you — the subject will be *Bus did not arrive on Tuesday*",
  taps **Open Support**, and lands on a blank compose form.
- **Impact:** this is the single worst moment in the feature. The user was told, in the AI's
  own voice, that work was done on their behalf; the app then silently discards it and asks
  them to do that work again from memory. It is a broken promise, not a missing convenience —
  and the population most likely to hit it is the one that came in with a problem. Worse, the
  category the model chose is also lost, so `SupportViewModel`'s validity rule ("valid when a
  category and a body exist", `SupportViewModel.kt:67`) leaves them staring at an invalid,
  empty form. `REQUIRES VERIFICATION` that the model actually names the subject line out loud
  in practice, but the prompt commands it.
- **Invariant:** if any surface tells a user something has been prepared, the next screen must
  contain it. An assistant may not make a claim the app cannot honour.
- **Remedy (UX terms).** Carry the draft, and make the arrival *legible*:
  1. Widen `AssistantMessage` / `AssistantReply` with `handoffSubject`, `handoffDetails`,
     `handoffCategory`, and read all three in `AssistantRepository`.
  2. Make `Route.SupportCompose` argument-bearing (`support_compose?subject=&details=&category=`,
     URL-encoded) and seed `SupportViewModel` through its existing `updateSubject` /
     `updateBody` / category setters (`SupportViewModel.kt:277` et seq.). The draft-persistence
     keys already there (`KEY_SUBJECT`, `:83, :395`) mean this survives process death for free.
  3. On arrival, show a one-line banner at the top of the compose form — *"Filled in from your
     chat with ZenXii. Check it before sending."* — so the prefill reads as **a draft the user
     owns**, not as something already submitted. Both fields stay fully editable.
  4. Change the button label from "Open Support" to **"Review and send"**. "Open Support" names
     a destination; "Review and send" names the user's remaining job and sets the expectation
     that nothing has been filed — which is exactly the wording rule
     `strings_assistant.xml:6` already states.
  - *Interim, if (1)-(3) cannot ship:* stop the promise instead. Amend the server guidance so
    the model says "I can't file this for you — tap below and I'll take you to the form" and
    relabel the button "Go to Support". A smaller promise kept beats a larger one broken.

## UX-02 — One entry point, and it is behind an auto-raised keyboard
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]` (single entry point) · `[INFERRED]` (the keyboard occlusion)
- **Evidence:** grep for `Route.Assistant` across `app/src/main/java/com/schoolsync/parent/`
  returns exactly two hits: the `composable` registration (`NavGraph.kt:837`) and the search
  catalogue row (`SearchViewModel.kt:236`). There is no dashboard tile, no drawer item, no
  bottom-nav slot, no Academics/Categories grid entry. The one path is
  `DashboardScreen.kt:303-306` (`DashboardSearchRow(onSearch = onNavigateToSearch)`) →
  `NavGraph.kt:587` → `SearchScreen`, which at `:64` does
  `LaunchedEffect(Unit) { focusRequester.requestFocus() }` — the field takes focus and the IME
  rises on entry. The browse list below it opens with a hint paragraph
  (`SearchScreen.kt:155-162`) and then the `FEATURE` section, which is ordinal 0
  (`SearchViewModel.kt:27`) with "AI Assistant" as its first row (`:236`).
- **Trigger:** cold start, any user who has not been told the feature exists.
- **Impact:** **two taps** — Dashboard → search row → "AI Assistant". That count flatters it.
  The screen the second tap happens on is a *search* screen that has just asked for typed
  input; the affordance says "type", and the list of things you could tap instead is partly
  under the keyboard on a short phone. A parent who has never heard of the feature has no
  reason to open search, and no reason once there to scroll a list rather than type. The
  synonym list (`:237-238`: "ai, assistant, ask, ask zenxii, chat, help, question, doubt,
  query, bot, helpdesk") is good, but it only rewards someone who already knows. A feature
  reachable only from a search box is a feature for people who were told about it by a human.
  `REQUIRES VERIFICATION` of the occlusion on a 360×640 device.
- **Invariant:** a flagship feature needs at least one entry point that is *visible without
  input*.
- **Remedy:** add a persistent, visible entry — in descending order of value:
  1. A dashboard row, placed under the search row where discovery attention already is:
     a single-line card, sparkle glyph, "Ask ZenXii — attendance, homework, fees…". One tap
     from cold start.
  2. An "AI Assistant" tile in the Categories/Academics grid, which is where the app's own
     comment (`SearchViewModel.kt:231-235`) already imagines it lives — but no tile exists.
  3. A contextual entry inside Support: on the Support list's empty state, "Not sure who to
     ask? Ask ZenXii first." This puts it in front of the exact user it is designed to deflect.
  Keep the search row regardless; it costs nothing.

## UX-03 — Refusals, including self-harm disclosures, arrive as ordinary grey chat text with an un-tappable helpline
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]` (rendering) · `[INFERRED]` (distress impact)
- **Evidence:** the server's safety instruction, `studentAssistant.js:447`, requires the model
  to say *"in India they can call Tele-MANAS on 14416 at any time"* — as prose, inside the
  normal `reply` string. On the client that string reaches `AssistantScreen.kt:189-199` as a
  plain `Text(m.text)` in the standard received bubble: `chatReceived` fill, `glassBorder`
  outline, `textPrimary` at 14.2sp. There is no `AnnotatedString`, no `LinkAnnotation`, no
  `autoLink`, no `ACTION_DIAL` intent anywhere in the file. `isError` is false — a refusal is
  not an error — so no alternate styling applies (`:175-179`, `:194-198`). Grep for `14416`
  and `MANAS` across `app/src/main/` returns **nothing**: the number exists only in
  model-generated text, so it is absent from all six `strings_assistant.xml` files and cannot
  be localized, verified, or updated without a function deploy.
- **Trigger:** a student types something about being unsafe, or about self-harm. Also, far more
  often, the ordinary tutoring refusal (`studentAssistant.js:446`).
- **Impact — modelled.** A distressed student, on a phone, at night. They type the hardest
  sentence they have typed all week. What comes back is visually **identical to the bubble
  that told them their fee balance five minutes ago**: same grey, same rounded corner, same
  size. The one actionable thing in it — a six-digit number — is not tappable. To act on it
  they must memorise or hand-copy the digits, leave the app, open the dialer, and type them.
  Each of those steps is a place to stop, and stopping is the default state of a person in
  distress. Meanwhile the composer sits below, enabled, inviting them to continue a
  conversation the model has been told to end (`:447`, "Then stop that line of conversation") —
  so the interface's affordance and the model's instruction point in opposite directions.
  The two refusal classes are also indistinguishable to the eye: "I can't solve your maths
  problem" and "I can't help you with this, please talk to an adult right now" render the same.
- **Invariant:** a safety referral is not a chat message. When the product's answer to a user
  is "a human, now", the interface must make reaching that human the easiest thing on screen.
- **Remedy.** Make the refusal a *typed* reply, not a styled string:
  1. **Server:** add `refusal: { kind: "tutoring" | "wellbeing" | "safety" }` to the callable's
     response alongside `handoff`, set whenever the model declines. It costs one field and
     removes all client-side text sniffing (never parse the model's prose for "14416").
  2. **Client, `kind = "safety"`:** render a **card, not a bubble** — full content width,
     `warningBg`/`accentBg` fill, a visible left rule, the message at normal body size, and
     beneath it a stack of real buttons, each ≥48dp:
     · **Call Tele-MANAS 14416** → `Intent(ACTION_DIAL, "tel:14416")` (DIAL, not CALL: no
     permission, and the user still presses the green button — consent preserved).
     · **Tell someone at school** → the Support handoff, with the details field left blank and
     a hint, never auto-filled from what they just said. The server already forbids recording
     it without consent (`:447`); the UI must not undo that.
     Put the helpline **first**. Suppress the suggestion chips. Do not disable the composer —
     silencing someone mid-disclosure is its own harm — but the card must out-weigh it visually.
  3. **Client, `kind = "wellbeing"`:** the same card, softer, helpline present but secondary to
     "talk to a teacher".
  4. **Client, `kind = "tutoring"`:** leave it an ordinary bubble. It is an ordinary "no".
  5. **Strings:** the helpline label, number and the card's framing move into
     `strings_assistant.xml` so all six locales carry them and the number is auditable in the
     repo. Region-gate it: `14416` is India-specific and is currently hardcoded in a prompt.
  6. Never truncate or ellipsize a refusal card.

## UX-04 — The required AI disclosure is the lowest-contrast text on the screen, and it disappears after the first message
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]` (both the removal and the computed ratios)
- **Evidence:** `AssistantScreen.kt:112` — `if (ui.messages.isEmpty()) item { Intro() }`. The
  `Intro()` block holds the disclosure (`:148-153`, `R.string.assistant_ai_disclosure`), and
  the moment the first message lands `ui.messages` is non-empty, so the whole block —
  disclosure included — is dropped from the list. It never returns for the life of the screen.
  It is styled at `:151` as `fontSize = 11.sp, color = c.textTertiary`, the smallest and
  faintest text in the file. Computed against the Warm Sand palettes:
  · Light — `textTertiary #9C8A7C` (`ThemeVariants.kt:406`) on `bgMid #F5ECE1` (`:395`) =
  **2.83:1**. · Dark — `#7D6E5F` (`:466`) on `#1E1811` (`:455`) = **3.57:1**. WCAG AA for text
  under 18.66px bold / 24px regular requires **4.5:1**. Both fail. The string itself is good —
  *"You're chatting with an AI assistant, not a member of school staff. It can see this
  student's records only."* (`strings_assistant.xml:22`) — and the file's own header calls it
  *"required, not decorative"* (`:6-8`).
- **Trigger:** sending one message. Also: returning to the screen later — the transcript is
  in-memory (`AssistantMessage.kt:5-8`), so on process death the disclosure returns, meaning
  its presence is a function of process lifetime rather than of anything the user did.
- **Impact:** the disclosure is shown exactly once, in the faintest type on the screen, in the
  seconds when a new user is reading the *title and the chips* — and is then removed for the
  entire remainder of the conversation, including every turn where the AI states a fact about
  a child's records or declines a wellbeing topic. A minor who returns to a screen already
  populated with a transcript is never told they are talking to a machine. For a feature whose
  users are children, whose refusals depend on the user understanding this is not a person,
  and which operates under DPDP, "shown once, faintly, then removed" is not a disclosure.
- **Invariant:** the AI disclosure must be reachable at every point in the conversation, and
  must meet AA contrast like any other functional text.
- **Remedy:**
  1. **Make it durable and ambient.** Replace the top bar's subtitle
     (`AssistantScreen.kt:72-77`, currently "Your school assistant" / "Looking that up…") with
     a persistent **"AI · not school staff"** line at `textSecondary`. It is always on screen,
     costs no vertical space, and reads as identity rather than as a warning.
  2. Keep the full sentence in the intro, but raise it to `textSecondary` (light: `#6B564A` on
     `#F5ECE1` ≈ 5.9:1 — passes) and 12sp.
  3. Add a small **ⓘ** affordance in the top bar (≥48dp) opening a short sheet: what it can
     see, what it cannot do, that it is not a counsellor, that nothing here is a filed ticket.
     One durable place a user can always re-check what they are talking to.
  4. Re-show the intro disclosure whenever the screen is entered with an empty transcript —
     already true — *and* make the persistent line the thing that carries the burden, so
     lifetime never determines whether disclosure happened.

## UX-05 — `unavailableReason` is a terminal screen with nothing to do, in server English, that cannot scroll
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]`
- **Evidence:** `AssistantScreen.kt:99-102` — `if (ui.unavailableReason != null) {
  UnavailableNotice(...); return@Column }`. The `return@Column` means the `LazyColumn`, the
  chips and the `Composer` are never composed. `UnavailableNotice` (`:348-353`) is a
  `Box(Modifier.fillMaxSize().padding(32.dp))` containing one centred `Text` — **no
  `verticalScroll`**, no button, no icon, no illustration. The state has one writer
  (`AssistantViewModel.kt:96`) and is never cleared (A3 · M-05). That writer prefers
  `e.message` — the raw server string — over the localized `R.string.assistant_unavailable`,
  so the six translated `strings_assistant.xml` files are bypassed in the common case.
- **Trigger:** the school has not enabled the assistant, or has no `currentSession`
  (`studentAssistant.js:164`), or a `PERMISSION_DENIED` — which `AssistantViewModel.kt:90-92`
  folds into the same bucket despite the KDoc justifying only `FAILED_PRECONDITION`.
- **Impact:** the user reaches a screen that is one sentence of grey text and a back arrow.
  There is no retry, no "contact the school", no route to Support — which is the *one thing*
  this user still needs and which is fully working. Because the flag never clears, a transient
  `PERMISSION_DENIED` (an expired token, a claims refresh) converts a working feature into a
  permanently dead screen for the rest of the process; the user's only recovery is force-quit,
  which nothing tells them. In landscape, or at large font scale, a long server sentence in a
  non-scrolling centred `Box` with 32dp padding will clip with no way to read the rest.
- **Invariant:** no terminal state without a next action. Every full-screen message must scroll.
- **Remedy:** turn it into a proper empty state — glyph, a localized headline, one line of
  explanation, and **two buttons**: **Try again** (clears `unavailableReason`, which requires
  the reset A3 already asked for) and **Go to Support** (`Route.Support`). Wrap the column in
  `verticalScroll`. Prefer the localized string and demote `e.message` to a small
  `textTertiary` detail line, or drop it. Stop routing `PERMISSION_DENIED` here — an auth
  failure is transient and belongs in the retryable error bubble with the sign-in message that
  already exists (`assistant_signed_out`).

## UX-06 — Quota exhaustion looks like a transient failure, so the user keeps trying
- **Severity:** HIGH
- **Classification:** `[CONFIRMED]` (rendering path) · `[INFERRED]` (the retry loop)
- **Evidence:** `AssistantViewModel.kt:103-104` maps `RESOURCE_EXHAUSTED` to
  `e.message ?: assistant_quota_reached` and then falls through to `:112-121`, which appends it
  as an ordinary `AssistantMessage(isError = true)`. On screen that is one bubble
  (`AssistantScreen.kt:174-200`) tinted `errorBg` — **8% alpha** (`0x14B4472F`,
  `ThemeVariants.kt:412`) over `chatReceived`, i.e. a barely-perceptible wash — with the text
  in `error`. Crucially the composer is untouched: `enabled = !ui.isThinking`
  (`AssistantScreen.kt:121`), and `isThinking` is false, so the field and the send button are
  fully live.
- **Trigger:** the student hits the daily cap.
- **Impact:** the copy is good — *"You've reached today's question limit. It resets tomorrow."*
  (`strings_assistant.xml:39`) — but everything around it contradicts it. A faint one-line
  bubble amid other faint one-line bubbles, under a composer that still says "Ask about your
  school…", reads as *something went wrong, try again*. The user retries; each retry costs a
  round trip, produces an identical bubble, and pushes the earlier explanation off screen. The
  quota is the one error where the correct user action is **stop**, and it is the one rendered
  as though the correct action were **retry**. There is no reset time, no count remaining
  beforehand, and no warning as the limit approaches. `REQUIRES VERIFICATION` that repeated
  calls after exhaustion still round-trip rather than being short-circuited.
- **Invariant:** an exhausted state must disable the action that is exhausted.
- **Remedy:** treat quota as a *mode*, not a message. Add `quotaExhausted: Boolean` to
  `AssistantUiState`. When set: disable the composer and replace its placeholder with **"Daily
  limit reached — resets tomorrow"**; render the explanation as a full-width inset strip above
  the composer, not a bubble; and put one button in it — **Ask the school instead** → Support.
  The transcript stays readable and scrollable, so nothing the user already got is lost.
  Optionally surface remaining questions in the top-bar subtitle once the count is low
  (requires the server to return it).

## UX-07 — An error and an answer are the same shape, and neither can be retried
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]`
- **Evidence:** the only difference between an error and an answer is fill and text colour —
  `AssistantScreen.kt:175-179` (`errorBg` vs `chatReceived`) and `:194-198` (`error` vs
  `textPrimary`). Same bubble, same corner radius, same border, same 14.2sp, same position.
  `errorBg` is 8% alpha in every variant (`ThemeVariants.kt:412, :472`). No icon, no retry
  affordance, no `onRetry` anywhere in the screen or the ViewModel. The failed user turn stays
  in `messages` and is replayed as history on the next send (`AssistantViewModel.kt:43-47`
  filters out `isError` assistant turns but keeps the user turn), so re-asking means retyping.
- **Trigger:** any `DEADLINE_EXCEEDED`, `UNAUTHENTICATED`, or unmapped exception.
- **Impact:** a low-attention reader — the normal reader — sees a message from the assistant in
  the assistant's position and takes it as an answer. "Couldn't reach the assistant. Please try
  again." then has to be *acted on* by retyping the whole question, because the only thing that
  could re-send it is the user's memory.
- **Invariant:** a failure must be visually distinct in *form*, not only in hue, and must carry
  its own recovery.
- **Remedy:** give errors their own component: a centred, full-width inset row — not a bubble —
  with a small alert glyph, the message, and a **Retry** text button that re-sends the last
  user turn from state. Raise `errorBg` to ~14-20% alpha so the tint is perceptible. Do not
  append errors to the transcript at all: they are UI state, which the ViewModel's own comment
  (`:41-42`) already asserts — the rendering just doesn't honour it.

## UX-08 — Three of the four interactive targets are below 48dp
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]` (code) · `[INFERRED]` (rendered heights, from M3 defaults)
- **Evidence:** · **Suggestion chips** (`AssistantScreen.kt:281-294`) — a bare `Surface(onClick)`
  whose only sizing is `padding(horizontal = 12.dp, vertical = 6.dp)` around 12.2sp text; no
  `heightIn`, no `minimumInteractiveComponentSize`. Rendered height ≈ 28-30dp.
  · **Send button** (`:332-344`) — `FilledIconButton` with no size modifier; the M3 default
  state-layer is 40dp. · **Handoff button** (`:209-219`) — `FilledTonalButton` with no size
  modifier; M3 default min-height 40dp. The back `IconButton` (`:81-87`) is the only one at the
  48dp default. For contrast, the sibling Support screen sizes its own targets explicitly
  (`SupportThreadScreen.kt:270, :285`).
- **Trigger:** every tap.
- **Impact:** the chips are the *first* thing a new user is invited to touch and are the
  smallest target on the screen, in a horizontally-scrolling row where a slightly-low touch
  becomes a scroll gesture instead of a tap. Below 48dp, mis-taps rise sharply for children,
  for anyone using the phone one-handed, and for users with motor impairment. The send button
  being 40dp is the most-repeated tap in the feature.
- **Invariant:** ≥48×48dp for every interactive element (Material / WCAG 2.5.5).
- **Remedy:** `Modifier.heightIn(min = 48.dp)` on the chip `Surface` with the text vertically
  centred; `Modifier.size(48.dp)` on the `FilledIconButton` (keep the 18dp glyph);
  `Modifier.heightIn(min = 48.dp)` on the handoff button. Visual weight need not change — only
  the touch box.

## UX-09 — TalkBack is never told that the assistant is thinking, or that an answer arrived
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]` (absence) · `[INFERRED]` (screen-reader behaviour)
- **Evidence:** `ThinkingBubble` (`AssistantScreen.kt:250-263`) is a `Surface` containing a
  bare `CircularProgressIndicator` — no `contentDescription`, no `Modifier.semantics`, no
  `liveRegion`. Grep for `liveRegion`, `semantics`, `stateDescription` across
  `ui/assistant/` returns **nothing**. The top-bar subtitle does change to "Looking that up…"
  (`:73`) but it is an ordinary `Text` in the app bar with no live-region annotation, so it is
  not announced on change. New assistant messages are appended to a `LazyColumn`
  (`:113`) with the same absence. The tool-chip icon correctly passes `null`
  (`:243`) — that one is right; it is decorative.
- **Trigger:** any TalkBack user sending a message.
- **Impact:** a blind user sends a question and the interface goes silent. Nothing announces
  that a request is in flight, nothing announces its arrival, and the visual scroll-to-bottom
  (`:55-58`) has no auditory counterpart. The user's only recovery is to swipe repeatedly to
  the end of the list, hunting for a change — during an operation that A3 · M-01 notes may run
  up to 70 seconds. Reading order is otherwise sound: the tool chip precedes its bubble
  (`:165-168`) and the handoff button follows it (`:206-220`), which is the right narrative
  order. `REQUIRES VERIFICATION` on device.
- **Invariant:** asynchronous state changes must be announced.
- **Remedy:** put `Modifier.semantics { liveRegion = LiveRegionMode.Polite;
  contentDescription = <assistant_thinking> }` on the `ThinkingBubble`. Add a polite live
  region to the newest assistant message (or to an off-screen status node updated on arrival),
  so the answer announces itself once. Give each message row a
  `contentDescription` prefixed with the speaker ("You said…" / "ZenXii said…"), since bubble
  alignment and colour carry the speaker visually and nothing carries it aurally. Give the
  handoff button a description that includes the subject once UX-01 lands.

## UX-10 — The tool chip — the feature's whole trust signal — fails AA contrast in both Warm Sand themes
- **Severity:** MEDIUM
- **Classification:** `[CONFIRMED]` (computed from declared hex; not measured on a device)
- **Evidence:** `ToolChip` (`AssistantScreen.kt:235-246`) draws `textTertiary` at **10.5sp**
  on an `accentBg` fill. `accentBg` is a 10%-alpha accent (`0x1AC06B4E` light,
  `ThemeVariants.kt:402`; `0x1AE08E6E` dark, `:462`), so the effective background is that
  accent composited over `bgMid`. Computed:
  · **Warm Sand Light** — `#9C8A7C` on the composite ≈ `#F0DFD2` → **2.55:1**.
  · **Warm Sand Dark** — `#7D6E5F` on ≈ `#1B1815` → **3.04:1**.
  Both are well under the 4.5:1 AA floor for text this size. The check glyph (`:243`) is 11dp
  and shares `textTertiary`.
- **Trigger:** every answer that touched a record — i.e. the feature's core case.
- **Impact:** the chip exists to prove the answer came from the school's own data — the code
  comment says so (`:163-164`) — and it is rendered at the smallest size and lowest contrast on
  the screen. On a sunlit phone it will not be read. A trust signal nobody can read is not a
  trust signal; it is decoration, and it degrades to visual noise (see UX-14).
- **Invariant:** functional text meets 4.5:1. A trust affordance is functional text.
- **Remedy:** move the chip to `textSecondary` (light `#6B564A` on the same composite ≈ 5.4:1;
  dark `#B3A498` ≈ 7.5:1 — both pass) and raise it to 11.5-12sp. Keep the fill; only the
  foreground needs to move. The same substitution fixes the check glyph.

## UX-11 — Fixed sp/dp throughout; large font scale will crowd the top bar, the chips and the chip row
- **Severity:** MEDIUM
- **Classification:** `[INFERRED]` — the hardcoding is confirmed; the clipping is not observed.
- **Evidence:** every size in the screen is a literal: 16sp title / 11.5sp subtitle
  (`AssistantScreen.kt:68, 75`), 17sp + 13sp + 11sp intro (`:138, 143, 151`), 14.2sp body
  (`:192`), 10.5sp tool chip (`:245`), 12.2sp suggestion chip (`:292`), 13sp handoff (`:217`),
  13.5sp placeholder (`:318`). Paddings are fixed dp beside them (`:241, :278, :290, :310`), and
  bubbles are capped at a fixed `widthIn(max = 300.dp)` (`:187`). No `TextUnit` is derived from
  the theme's typography scale and nothing uses `MaterialTheme.typography` — unlike
  `SearchScreen.kt:105, 113, 219`, which does.
- **Trigger:** system font size at 1.3× or above; Android 14 allows 2.0×.
- **Impact, ranked by likelihood: · **Top bar** — a two-line title (16sp + 11.5sp) inside a
  56dp `TopAppBar` has almost no headroom; at 1.3× the subtitle is the first thing to clip, and
  the subtitle is where the "Looking that up…" status lives and where I propose the durable
  disclosure go (UX-04). · **Suggestion chips** — 6dp vertical padding around scaled text
  overflows a ~30dp row before the text does. · **Tool chip** — 3dp vertical padding (`:241`)
  around 10.5sp is the tightest box on the screen. · **Bubbles** — text reflows correctly
  inside the 300dp cap, so these are fine.
- **Invariant:** the layout must survive 2.0× font scale without clipping.
- **Remedy:** replace the literals with `MaterialTheme.typography` roles (`titleSmall`,
  `labelSmall`, `bodyMedium`), which scale as a system. Swap fixed vertical paddings on both
  chip types for `heightIn(min = …)` + centred content, which absorbs growth instead of
  fighting it. Give the top bar a single-line title and move the subtitle's content into the
  persistent disclosure line proposed in UX-04, sized from the type scale. Add
  `@Preview(fontScale = 2f)`.

## UX-12 — Landscape: the thread is squeezed from three sides, and the unavailable screen cannot scroll
- **Severity:** MEDIUM
- **Classification:** `[INFERRED]` (layout read; not rendered)
- **Evidence:** the structural discipline is genuinely good and the file documents it
  (`AssistantScreen.kt:36-42`): one scrolling region (`:107` `weight(1f)`), a pinned composer,
  `imePadding()` on the outer column (`:97`), `maxLines = 4` on the field (`:320`), and no fixed
  heights. Two exceptions. (a) In landscape with the IME up on a ~360dp-tall viewport, the
  top bar (56dp) + composer (~68dp) + `imePadding` leave the `LazyColumn` a sliver; and when the
  transcript is empty the suggestion-chip row (`:117`) takes another ~40dp from it, at exactly
  the moment the `Intro()` block (`:130-154`, 26dp top padding + three stacked texts) needs the
  most room. (b) `UnavailableNotice` (`:348-353`) has **no** `verticalScroll` (see UX-05).
- **Trigger:** landscape, split-screen, or a foldable's cover display; any keyboard-up state.
- **Impact:** in landscape the first-run experience — the one that carries the disclosure and
  the example chips — is compressed into a few visible lines. The intro is inside the
  `LazyColumn` so it scrolls and does not clip, which is the right call; but "scrollable" and
  "readable" are not the same for a block whose whole job is orientation. The unavailable
  screen genuinely clips. `REQUIRES VERIFICATION` at 360×640 landscape and at 2.0× font scale.
- **Invariant:** documented recurring bug class in these apps — dialogs, sheets and chat
  screens must fit short landscape viewports.
- **Remedy:** hide the suggestion chips when the IME is visible (`WindowInsets.isImeVisible`) —
  they are a cold-start affordance, not a typing one — which returns ~40dp to the thread
  exactly when it is scarcest. Add `verticalScroll` to `UnavailableNotice`. Reduce the intro's
  26dp top padding in landscape.

## UX-13 — Tablet: chrome stretches, content does not
- **Severity:** LOW
- **Classification:** `[INFERRED]`
- **Evidence:** no `WindowSizeClass`, no `widthIn` on any container, no two-pane treatment
  anywhere in the file. The composer (`AssistantScreen.kt:306-345`) is `fillMaxWidth()`; the
  suggestion row (`:274-279`) is `fillMaxWidth()`; only the bubbles are capped, at 300dp
  (`:187`).
- **Trigger:** a 10-inch tablet, or a landscape foldable's inner display.
- **Impact:** at 1280dp the bubbles correctly stay at a readable 300dp — that cap is the one
  thing already right — but they cling to the left and right edges of a mostly-empty column
  while the composer becomes a single-line text field a metre wide, which is an awkward,
  unmoored thing to type into. The screen reads as a phone layout that was inflated.
- **Invariant:** wide layouts constrain the *content column*, not just the text.
- **Remedy:** wrap the whole `Column` in a centred
  `Modifier.widthIn(max = 640.dp).align(Alignment.CenterHorizontally)`. One modifier; it fixes
  the thread, the chips and the composer together, and phones are unaffected.

## UX-14 — "AI Assistant" vs "Ask ZenXii", and a tool chip written in the wrong person
- **Severity:** LOW
- **Classification:** `[CONFIRMED]` (the strings) · `[INFERRED]` (comprehension)
- **Evidence:** the catalogue row is `feature("AI Assistant", "✨", …)`
  (`SearchViewModel.kt:236`) with a deliberate three-line rationale (`:231-235`): the list is
  nouns, and naming the AI discloses what it is before anyone opens it. The screen title and
  the intro headline are both "Ask ZenXii" (`strings_assistant.xml:14, :20`). Tool chips read
  "Checked **your** attendance", "Checked **your** homework", "Checked **your** fee records"
  (`:29-34`), and suggestion chips read "**My** attendance" (`:24-27`).
- **Trigger:** any use.
- **Impact.** *The name split is fine and should stay.* "AI Assistant" is a category label doing
  disclosure work in a list of categories; "Ask ZenXii" is the product's voice once you are
  inside. That is a normal and well-reasoned pattern, and the sparkle-not-speech-bubble choice
  is right — a bubble would read as messaging a teacher. Two smaller things do misfire:
  · **Person.** This is the **Parent** app. A parent reading "Checked *your* attendance" and
  tapping "*My* attendance" is being addressed as the student. The pronoun is wrong for the
  logged-in user on the app it ships in, and it is confusing on a household-shared credential
  where a parent may be checking one of several wards.
  · **Chip noise.** The chip shows `tools.firstOrNull()` only (`AssistantScreen.kt:227`), so an
  answer that read attendance *and* the timetable claims only the first, and any unrecognised
  tool degrades to "Checked your records" (`:233`), which asserts nothing. Combined with UX-10's
  unreadable contrast, the chip currently trends toward noise. Fixed — legible, accurate,
  plural — it is genuine reassurance, and it is the single cheapest trust affordance the
  feature has.
- **Invariant:** copy addresses the person holding the phone.
- **Remedy:** keep both names. Re-voice the chips to the ward: "Checked **Aarav's** attendance"
  where a name is available, else "Checked attendance records"; suggestion chips become
  "Attendance", "Homework due", "Fees", "Timetable" — nouns, matching the catalogue, and
  shorter, which also helps UX-08 and UX-12. Render **all** tools used, deduplicated
  ("Checked attendance and timetable"), and drop the chip entirely when the only tool is
  unrecognised rather than claiming a vague "records".

## UX-15 — After the first send the affordances vanish and never come back; there is no way to start over
- **Severity:** LOW
- **Classification:** `[CONFIRMED]`
- **Evidence:** `AssistantScreen.kt:112` and `:117` are both gated on `ui.messages.isEmpty()` —
  the intro *and* the suggestion chips are removed together on the first message and cannot
  return while the screen lives. There is no "clear", "new chat" or overflow action anywhere in
  the top bar (`:62-91`), and no reset path in the ViewModel.
- **Trigger:** the second question onward.
- **Impact:** the chips are the only thing teaching users what this feature can do, and they are
  withdrawn the instant the user demonstrates interest — precisely when the "what else can I
  ask?" question arrives. A user who wanders off-scope, gets refused, and wants to restart has
  no affordance for it; their only option is Back and re-enter, and whether that clears
  anything depends on the back stack. Minor, but it caps the feature's discovered surface at
  whatever the user thought of unaided.
- **Invariant:** capability hints belong wherever the user might need to re-orient.
- **Remedy:** keep the chip row permanently visible above the composer, rotating to
  context-appropriate follow-ups after an answer ("Show last month", "Ask the school about
  this"). Add a "New conversation" overflow item that clears `messages` and `input` and brings
  the intro — and its disclosure — back.

## UX-16 — Send while thinking is silently swallowed
- **Severity:** LOW
- **Classification:** `[CONFIRMED]`
- **Evidence:** the send *button* is correctly disabled while thinking
  (`AssistantScreen.kt:334`, `enabled = enabled && value.isNotBlank()`), but the **IME action**
  is not: `keyboardActions = KeyboardActions(onSend = { if (enabled) onSend() })` (`:323`) —
  and `enabled` there is `!ui.isThinking` (`:121`), so the guard holds. The deeper swallow is in
  the ViewModel: `send()` returns immediately if `isThinking` (`AssistantViewModel.kt:39`) with
  no feedback of any kind. The text field itself also stays editable while thinking — only the
  buttons gate — so a user can type a second question, press the keyboard's Send key, and have
  it discarded.
- **Trigger:** an impatient second question during a slow turn (which A3 · M-01 says may be up
  to 70 s).
- **Impact:** the typed text stays in the field, so nothing is lost — but nothing acknowledges
  the press either, and a 70-second wait is exactly when people press again. Empty input is
  handled correctly: `value.isNotBlank()` (`:334`) plus `q.isEmpty()` (`:39`) means whitespace
  cannot be sent — though the disabled button is never explained.
- **Invariant:** a rejected input action must be visibly rejected.
- **Remedy:** dim the text field's border while thinking so the whole composer visibly reads as
  busy, not just the button; or queue the second question and send it when the turn completes.
  Do not silently drop it.

---

# State coverage table

Every state the screen can be in, its trigger, what it renders today, and the gap.

| # | State | Trigger | Visual treatment today | Gap |
|---|---|---|---|---|
| 1 | **First open** | `messages` empty, no error (`AssistantScreen.kt:112, 117`) | `Intro()` — title, body, faint disclosure — plus 4 suggestion chips | Disclosure at 2.83:1 / 3.57:1 and 11sp (UX-04); chips <48dp (UX-08); intro crowded in landscape (UX-12) |
| 2 | **Empty input** | field blank or whitespace | Send button disabled (`:334`) | No explanation of *why* it is disabled. Correct but mute (UX-16) |
| 3 | **Typing** | any text | Field grows to `maxLines = 4` (`:320`); send enables | Adequate. Field stays editable during a turn (UX-16) |
| 4 | **Thinking** | `isThinking = true` (`AssistantViewModel.kt:54`) | Spinner-only bubble (`:250-263`) + top-bar subtitle "Looking that up…" (`:73`) | **Not announced to TalkBack** (UX-09). No elapsed-time or cancel affordance on a turn that may run 70 s |
| 5 | **Answered (no tool)** | reply, `toolsUsed` empty | Standard received bubble | Adequate |
| 6 | **Answered (tool used)** | `toolsUsed` non-empty (`:165`) | Tool chip above the bubble | 2.55:1 / 3.04:1 at 10.5sp (UX-10); only the *first* tool shown (UX-14) |
| 7 | **Tutoring refusal** | model declines (`studentAssistant.js:446`) | **Ordinary bubble — no distinct treatment** | Indistinguishable from an answer. Acceptable for this class, but see #8 |
| 8 | **Wellbeing / safety refusal** | model declines (`:447`); may contain Tele-MANAS 14416 | **Ordinary bubble — no distinct treatment.** Number is plain text, not tappable | **The largest gap in the screen.** No card, no call action, no localized helpline string, no signal that this refusal differs from #7 (UX-03) |
| 9 | **Handoff offered** | server returns `handoff` (`studentAssistant.js:588`) | `FilledTonalButton` under the bubble (`:209-219`) | Server-drafted subject/details **discarded** (UX-01); button 40dp (UX-08); label is server English |
| 10 | **Handoff taken** | button tapped → `Route.SupportCompose` | **Empty compose form** | The promise made in #9 is broken on arrival (UX-01) |
| 11 | **Error — generic / timeout / signed-out** | `AssistantViewModel.kt:102-109` | Bubble tinted `errorBg` at 8% alpha, error-coloured text | Same *shape* as an answer; tint near-invisible; **no retry** (UX-07) |
| 12 | **Quota exhausted** | `RESOURCE_EXHAUSTED` (`:103`) | Same error bubble as #11 | Not distinguished from a retryable failure; **composer stays enabled**, inviting a futile retry loop; no reset time (UX-06) |
| 13 | **Unavailable (terminal)** | `FAILED_PRECONDITION` / `PERMISSION_DENIED` (`:90-92`) | One centred sentence, server English, no scroll; thread, chips and composer never composed (`:99-102`) | **Dead end** — no retry, no Support route, cannot scroll, never clears (UX-05) |
| 14 | **Empty reply** | server returns `reply: ""` (`AssistantRepository.kt:58`) | **No treatment** — an empty bubble is appended and rendered | A blank bubble reads as a bug. No guard anywhere (A3 · M-07) |
| 15 | **Returning to a live transcript** | back-and-forward within the process | Thread only — no intro, no chips, no disclosure | Disclosure never re-shown (UX-04); no way to restart (UX-15) |
| 16 | **After process death** | transcript is in-memory (`AssistantMessage.kt:5-8`) | Screen restores empty; intro returns | Silent loss of the conversation, unexplained (A3 · M-08). Perverse upside: the disclosure comes back |

Sixteen states; **five** have no treatment distinguishing them from an ordinary answer
(7, 8, 11, 12, 14) and **two** are dead ends (12, 13).

---

# The nine questions, answered

### 1 · Discoverability — "Is a feature nobody can find a feature?"
No. `Route.Assistant` has exactly **two** references in the whole app: its `composable`
registration and one row in the search catalogue (`SearchViewModel.kt:236`) — no dashboard
tile, no drawer entry, no Categories tile, no bottom-nav slot. Cold start → Dashboard → tap the
search row (`DashboardScreen.kt:303-306`) → tap "AI Assistant". **Two taps** — but the second
happens on a screen that auto-focuses its field and raises the keyboard (`SearchScreen.kt:64`),
so the affordance says *type*, and the row you actually want sits below a hint paragraph,
partly under the IME on a short phone. The synonyms are well chosen but only reward someone who
already knows the feature exists. Right now this is a feature you find because a human told you
about it. **Proposal:** a dashboard row directly beneath the search row (one tap, visible with
no input), plus a Categories tile — which the code's own comment already assumes exists — plus a
contextual "Ask ZenXii first" on the Support empty state. Keep the search row. (UX-02)

### 2 · State coverage — which states have no visual treatment?
Sixteen states (table above). **Five have no treatment of their own**: both refusal classes,
generic errors, quota exhaustion, and an empty reply — all render as the same bubble in the same
place. **Is an error distinct from an answer?** Only by hue: identical shape, radius, size and
position, differing by an `errorBg` tint at **8% alpha** and a text colour
(`AssistantScreen.kt:175-179, 194-198`). That is not a distinction a glancing reader will make.
**Proposal:** errors become a centred full-width inset with an alert glyph and a **Retry**, not
a bubble; quota becomes a composer-disabling *mode*; refusals become typed cards (Q3). (UX-07)

### 3 · The refusal experience — model what a distressed student sees.
They send the hardest sentence they have written all week, and get back a bubble **visually
identical to the one that told them their fee balance**. Tele-MANAS **14416 is not tappable** —
grep for `14416` and `MANAS` across `app/src/main/` returns nothing; the number exists only
inside model-generated prose from `studentAssistant.js:447`, so it is in no string resource, is
in none of the six locales, and cannot be dialled without leaving the app and hand-copying six
digits. Below it, the composer stays enabled, inviting them to continue a conversation the model
has been instructed to end. **Should it be tappable? Yes — emphatically.** **Proposal:** the
server returns `refusal: {kind}`; a `safety` kind renders a full-width card, not a bubble, with
**Call Tele-MANAS 14416** (`ACTION_DIAL`, so the user still presses call) as the first and
largest action, **Tell someone at school** second (Support handoff, details deliberately blank),
the helpline text moved into `strings_assistant.xml` for all six locales and region-gated, and
the suggestion chips suppressed. A tutoring refusal stays an ordinary bubble — it is an ordinary
no. Never let the two look alike. (UX-03)

### 4 · The handoff — arriving at an empty form.
The server sends `suggestedSubject` (200 chars) and `suggestedDetails` (2000 chars) plus a
category (`studentAssistant.js:588-593`); `AssistantRepository.kt:60-61` reads only `route` and
`buttonLabel`; `AssistantMessage` has nowhere to put them; `Route.SupportCompose` takes no
arguments; `SupportComposeScreen.kt:51-55` accepts no prefill. **All of it is discarded** — and
the server prompt explicitly orders the model to promise it: *"Tell the student you have
prepared this for them… Say what the subject line will be"* (`:421-423`). The user is told, in
the AI's voice, that their problem was written up; they tap; they get a blank form and must
retype from memory the thing they just explained. That is a broken promise aimed squarely at the
user who arrived with a problem. **Proposal:** carry subject, details and category through as
route arguments into `SupportViewModel`'s existing setters; head the form with *"Filled in from
your chat with ZenXii — check it before sending"* so it reads as **a draft they own**; relabel
the button **"Review and send"**, which names their remaining job and honours the file's own
rule against implying anything was filed. If that cannot ship, shrink the promise instead of
breaking it. (UX-01)

### 5 · Trust and disclosure — durable enough for a feature minors use?
No. `AssistantScreen.kt:112` gates the entire `Intro()` — disclosure included — on
`messages.isEmpty()`, so the required notice is removed by the user's first message and never
returns. It is also the **lowest-contrast text on the screen**: 11sp `textTertiary`, computing
to **2.83:1** (Warm Sand Light) and **3.57:1** (Warm Sand Dark) against a 4.5:1 floor. Shown
once, faintly, then withdrawn — for the whole remainder of a conversation in which the AI states
facts about a child's records and declines wellbeing topics. Its presence currently depends on
process lifetime rather than on anything the user did. **Where else it belongs:** (a) the top-bar
subtitle, as a permanent **"AI · not school staff"** — always visible, zero extra height, reads
as identity rather than warning; (b) behind a ⓘ affordance opening a short sheet on what it can
see and cannot do; (c) the intro keeps the full sentence, raised to `textSecondary`/12sp so it
passes AA. (UX-04)

### 6 · Terminology — does the split help or confuse?
**It helps — keep it.** "AI Assistant" is a category label doing disclosure work in a list of
category nouns; "Ask ZenXii" is the product's voice once you are inside. The reasoning is
already written down (`SearchViewModel.kt:231-235`), and the sparkle-over-speech-bubble choice
is right: a bubble would read as messaging a teacher, a different feature. Two adjacent things
do misfire. **Person:** this is the *Parent* app, yet chips read "Checked **your** attendance"
and "**My** attendance" — the parent is being addressed as the student, on a
household-shared credential where several wards may exist. **Reassurance or noise?** Today,
drifting to noise: at 10.5sp and 2.55:1 it is barely legible (Q7), it reports only
`tools.firstOrNull()` so multi-tool answers under-claim, and unknown tools degrade to "Checked
your records", which asserts nothing. Fixed — legible, accurate, plural, in the ward's name —
it becomes the cheapest genuine trust affordance the feature has. **Proposal:** "Checked
Aarav's attendance and timetable"; suggestion chips become plain nouns matching the catalogue.
(UX-14)

### 7 · Accessibility.
- **Content descriptions:** back and send are described (`:84, :341`); the tool-chip icon
  correctly passes `null` as decorative (`:243`). Message bubbles carry no speaker prefix — the
  speaker is conveyed only by alignment and colour, neither of which is audible.
- **Touch targets:** three of four fail. Suggestion chips ≈28-30dp (`:281-294`, padding only, no
  `heightIn`), send `FilledIconButton` 40dp (M3 default, `:332`), handoff `FilledTonalButton`
  40dp (`:209`). Only the back button is 48dp. (UX-08)
- **TalkBack ordering:** genuinely good — tool chip → bubble → handoff button (`:165-220`) is
  the right narrative order.
- **Thinking indicator:** does **not** announce. Bare `CircularProgressIndicator`, no
  `contentDescription`, no `liveRegion`; grep for `liveRegion`/`semantics` across `ui/assistant/`
  returns nothing. A blind user sends a question into silence for up to 70 s, and the answer
  never announces its arrival. (UX-09)
- **Dynamic type:** every size is a hardcoded literal (10.5-17sp) beside fixed dp paddings;
  nothing derives from `MaterialTheme.typography`, unlike `SearchScreen`. At 1.3× the two-line
  top-bar title clips first; both chip types crowd. (UX-11)
- **Contrast (computed from declared hex, not measured):** tool chip **2.55:1** light /
  **3.04:1** dark — fails. AI disclosure **2.83:1** / **3.57:1** — fails. Body text
  (`textPrimary` on `chatReceived`) passes comfortably. The **placeholder passes**: it resolves
  through `onSurfaceVariant = c.textSecondary` (`Theme.kt:42, :70`) — `#6B564A` on `bgStart
  #FBF6F0` — which is fine; the composer's omission of an explicit placeholder colour is
  harmless here. `REQUIRES VERIFICATION` on device.

### 8 · Landscape, small screens, tablet.
The structural discipline is real and the file states it (`:36-42`): one scrolling region, a
pinned `imePadding` composer, `maxLines = 4`, no fixed heights. Three things still bite.
**Landscape:** top bar + composer + IME on a ~360dp-tall viewport leave the thread a sliver, and
when the transcript is empty the suggestion row takes another ~40dp at exactly the moment the
intro needs room — fix by hiding the chips while the IME is visible; they are a cold-start
affordance, not a typing one. **Clipping:** `UnavailableNotice` (`:348-353`) is a centred `Box`
with **no `verticalScroll`** holding an arbitrary-length server string — it will clip in
landscape and at large font scale. **Tablet:** no `WindowSizeClass` and no container width cap;
bubbles correctly hold at 300dp but the composer stretches to the full width of a 10-inch
display, an unmoored thing to type into. One centred `widthIn(max = 640.dp)` on the outer
`Column` fixes thread, chips and composer together and leaves phones untouched. (UX-12, UX-13)

### 9 · Dead ends.
Two, both terminal, both recoverable only by leaving.
**`unavailableReason`** (`:99-102`) early-returns before the thread, chips and composer are ever
composed, leaving one grey sentence — in server English, un-scrollable — and a back arrow. No
retry, no "contact the school", and no route to **Support**, which is the one thing this user
still needs and which works perfectly. Because the flag has one writer and is never cleared
(A3 · M-05), a transient `PERMISSION_DENIED` — which `:90-92` wrongly routes here — kills the
feature for the rest of the process, and nothing tells the user that force-quitting would fix
it. **Fix:** a proper empty state — glyph, localized headline, **Try again** + **Go to
Support** — wrapped in `verticalScroll`; and stop sending auth failures here.
**Quota exhausted** is the subtler dead end: correct copy ("resets tomorrow") delivered in the
*retryable* error costume, under a composer that stays fully enabled. The interface's affordance
says try again; the truth is stop. Each retry burns a round trip and pushes the explanation off
screen. **Fix:** make it a mode — disable the composer, change its placeholder to "Daily limit
reached — resets tomorrow", show the explanation as a strip above it, and offer **Ask the school
instead** → Support. In both cases the principle is the same: never leave a user on a screen
whose only control is Back. (UX-05, UX-06)

---

# Severity roll-up

| Severity | IDs |
|---|---|
| CRITICAL | UX-01 |
| HIGH | UX-02, UX-03, UX-04, UX-05, UX-06 |
| MEDIUM | UX-07, UX-08, UX-09, UX-10, UX-11, UX-12 |
| LOW | UX-13, UX-14, UX-15, UX-16 |

**Highest-value single change:** UX-01 — carry `suggestedSubject` / `suggestedDetails` /
`category` into a pre-filled Support compose form. It is the only finding where the product
makes a promise in the AI's own voice and then breaks it; it is contained (four small edits, all
in Parent, no rules and no function deploy); and it converts the feature's *reason to exist* —
turning a confused student into a well-formed ticket — from a dead end into the thing it was
designed to be.

**Not verifiable at E2 — for a device pass:** every contrast figure (computed, not measured);
IME occlusion of the search browse list; TalkBack announcement behaviour; landscape clipping at
360×640 and at 2.0× font scale; whether the model in practice names the drafted subject aloud;
whether post-quota sends still round-trip.
