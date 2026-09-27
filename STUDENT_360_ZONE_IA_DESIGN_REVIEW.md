# Student 360 — 5-Zone Information Architecture Review

**Date:** 2026-08-10 · **Branch:** `integration/phase2` · **Working tree HELD — nothing modified.**
**Gate:** pre-UAT design review. No code, CSS, JS, controller, service, query, RBAC, registry or
Firestore change was made. This document is analysis only.

Reviewed against the **actual** implementation: 13 panels, 16 registry descriptors, the eager/lazy
split in `Student_context` / `Student_panel_reader`, the alert strip, and the current responsive
behaviour — not against a generic ERP checklist.

---

## A · EXECUTIVE VERDICT

### **MODIFY 5-ZONE DESIGN** — approve the five-zone model, change three panel placements.

The five-zone *concept* is sound and I recommend building it before UAT. The *mapping* has three
defects, all found by inspecting the code rather than reasoning about it:

| # | Finding | Severity | Evidence |
|---|---|---|---|
| **F-1** | The **“Academic” panel contains no academic data.** It is admission number, DOB, blood group, religion, guardian phone, address, SR/APAAR — a **profile**. Placing it in ACADEMICS is a category error: an operator asking “how is this child performing?” would find their blood group. | **High** | 17 fields, zero performance fields |
| **F-2** | **RECORDS cannot produce a folded summary at all.** 0 of its 3 panels are eager: Documents is lazy, Certificates is blocked by CERT-001, History is a separate fetch. Requirement §8 forbids an empty folded header; §15 forbids new reads. As proposed, those two requirements are in direct conflict. | **High** | eager keys: `academic, fees, entitlements, transport, discipline, defaulter` |
| **F-3** | **Parent Requests and Entitlements are the same collection.** A parent request *is* a `studentEntitlements` row with `source='parent_request'`. Splitting them across two zones means an **approved request silently moves from ENGAGEMENT to MONEY**. | **Medium** | `Student_context.php:528-546`, `Entitlement_requests.php:144` |

**F-1 and F-2 solve each other.** Moving the profile panel into RECORDS removes the category error
*and* gives RECORDS the only eager data source it could have. That single move fixes both.

---

## B · EXACT FIVE-ZONE MAPPING (recommended)

| Zone | Panels | Actions | Folds to (eager only) |
|---|---|---|---|
| **1 · MONEY** | Fees · Receipts · Entitlements | 7 | ✅ `₹35,500 outstanding` |
| **2 · ACADEMICS** | Results · Attendance | 2 | ⚠️ `Result withheld` / `—` |
| **3 · STUDENT & FAMILY** | Discipline · Parent Requests | 2 | ✅ `No flags · 1 request pending` |
| **4 · SERVICES** | Transport · Hostel | 4 | ✅ `South Circle` |
| **5 · RECORDS** | **Profile** · Documents · Certificates · History | 3 | ✅ `ADM-2026-159 · Class 8th` |

**13 / 13 panels · 16 / 16 actions.** Three changes from the proposal:

1. **“Academic” → RECORDS, renamed “Profile”** (F-1 + F-2)
2. **Attendance → ACADEMICS** (consequence of 1 — otherwise ACADEMICS holds one panel)
3. **“Student & Engagement” → “Student & Family”** (naming, §D)

**Parent Requests stays in Student & Family** — your instinct is right, with one condition (§C).

---

## C · PANEL-BY-PANEL JUSTIFICATION

### ZONE 1 · MONEY — Fees · Receipts · Entitlements ✅ **as proposed**

| Panel | Question answered | Verdict |
|---|---|---|
| **Fees** | “What is owed?” | ✅ Anchor panel. Eager, drives the primary alert. |
| **Receipts** | “What came in, when, by whom?” | ✅ Same object seen from the other side. Belongs beside Fees. |
| **Entitlements** | “What reduces the bill?” | ✅ Directly modifies the number Fees shows. |

**No ambiguity.** All three are billing artefacts. 7 of 16 actions live here, all `Fees:*` — the
zone boundary is identical to the RBAC boundary, which is the strongest possible signal that a
grouping is real.

### ZONE 2 · ACADEMICS — Results · Attendance ⚠️ **changed**

| Panel | Question answered | Verdict |
|---|---|---|
| **Results** | “How did they score?” | ✅ Core. |
| ~~Academic~~ | ~~“How are they performing?”~~ | ❌ **Move to RECORDS** — see F-1. |
| **Attendance** ← from Engagement | “Are they here?” | ✅ Attendance is a performance signal and sits on the report card beside marks. |

**Why Attendance moves — and this is not for symmetry.** Once the profile panel leaves, ACADEMICS
would hold exactly one panel. A one-panel zone is a tab wearing a zone costume: it costs a
top-level choice and returns nothing. Attendance is the only panel that is *substantively*
academic, and “performing academically” legitimately includes being present.

**Honest counter-argument:** your framing put Attendance under “day-to-day”, which is also true. If
you prefer it in Student & Family, keep it there and accept ACADEMICS = Results only — but then
consider whether ACADEMICS deserves to be a zone at all rather than a panel inside RECORDS.

### ZONE 3 · STUDENT & FAMILY — Discipline · Parent Requests ⚠️ **renamed, Attendance out**

| Panel | Question answered | Verdict |
|---|---|---|
| **Discipline** | “Any concerns?” | ✅ Anchor. Eager, feeds an alert. |
| **Parent Requests** | “What has the family asked for?” | ✅ **Keep here — with a condition.** |

**F-3 in full, because it is the subtlest thing in this review.** `Entitlement_requests::submit()`
calls `Entitlement_service::propose()` with `source='parent_request'`. It is **not a separate
collection** — it is a `studentEntitlements` row, served from the *same eager read* as Entitlements
and filtered by one field.

The consequence is a **lifecycle boundary crossing a zone boundary**: a pending request lives in
Student & Family; the moment it is approved it becomes an active entitlement and appears in MONEY.
To an operator, the row they just approved **vanishes from the zone they approved it in**.

That is defensible — arguably even correct, since a request and a granted concession *are*
different things to a school office — but only if the UI is explicit. **Condition for keeping this
placement:**

1. The **approve / reject actions must be reachable from inside Student & Family.** They are
   `Fees:manage` and today resolve to MONEY. Stranding the decision in another zone would be worse
   than the split itself.
2. On approval the UI must **say where it went** — “Approved. Now active under Money.” Silence here
   reads as data loss.

If either condition is unacceptable, move Parent Requests to MONEY and accept the weaker grouping.

### ZONE 4 · SERVICES — Transport · Hostel ✅ **as proposed**

| Panel | Question answered | Verdict |
|---|---|---|
| **Transport** | “Which bus?” | ✅ Eager, feeds an alert. |
| **Hostel** | “Boarding or day scholar?” | ✅ Peer concept: an optional, billable, suspendable service. |

**The cleanest zone in the model.** Both are opt-in services with a monthly fee, a status, and a
suspension lifecycle. 4 actions, all `Transport:*` / `Hostel:*`.

### ZONE 5 · RECORDS — Profile · Documents · Certificates · History ⚠️ **gains Profile**

| Panel | Question answered | Verdict |
|---|---|---|
| **Profile** ← was “Academic” | “Who is this student?” | ✅ **The move that fixes two findings.** |
| **Documents** | “What is on file?” | ✅ |
| **Certificates** | “What has been issued?” | ✅ (blocked by CERT-001, still belongs here) |
| **History** | “What happened, and when?” | ✅ |

**Why the profile panel belongs here.** Its 17 fields are admission number, admission date, roll
no, house, DOB, gender, blood group, category, religion, mother, father, phone, email, address,
previous school, SR number, APAAR ID. That is **the student's file** — the paper record a school
office keeps. It answers “who is this student?”, which is RECORDS' question, not ACADEMICS'.

**And it is the only eager panel that can fold RECORDS.** See §K.

---

## D · ZONE NAMING REVIEW

| Proposed | Verdict | Recommendation |
|---|---|---|
| **Money** | ✅ **Keep.** | Plain, unambiguous, and what school-office staff actually say. “Finance” reads as accounting/ledgers — a different department. Do not change. |
| **Academics** | ✅ Keep | Clear. Becomes accurate once the profile panel leaves. |
| **Student & Engagement** | ❌ **Change** | **“Student & Family”.** “Engagement” is product jargon; no clerk says it. The zone holds concerns *about* the student and requests *from* the family — the new name says exactly that. |
| **Services** | ✅ Keep | Precise. Both panels are optional billable services. |
| **Records** | ✅ **Keep** — and it stops being too broad | Your concern was fair for Documents + Certificates + History, which share only “official-ish”. Adding **Profile** gives the zone a centre: *the student's file*. Everything in it is now a stored fact about the student rather than a live situation. |

**On “Academic” as a panel name inside “Academics”:** even if you reject the move, **rename the
panel**. A panel and its zone sharing a name, while containing different things, is a guaranteed
support question.

---

## E · FOUR-ZONE vs FIVE-ZONE (validation reference only)

| | Four zones (earlier proposal) | Five zones (this proposal) |
|---|---|---|
| Money | Fees, Receipts, Entitlements, Parent Requests | Fees, Receipts, Entitlements |
| Academics | Results, Attendance, Discipline | Results, Attendance |
| Student & Family | — | Discipline, Parent Requests |
| Services | Transport, Hostel | Transport, Hostel |
| Records | Profile, Documents, Certificates, History | Profile, Documents, Certificates, History |
| Heaviest zone | **Money: 4 panels / 7 actions** | **Records: 4 panels / 3 actions** |
| Foldable zones | 4 / 4 | 4 / 5 |

**Five is better, for one concrete reason:** in the four-zone model Discipline sat under Academics,
where a behaviour flag reads as an academic judgement. Separating *concerns and family* from
*performance* is a real distinction to a school office — a discipline flag is not a bad mark.

**Cost of the fifth zone:** ACADEMICS drops to 2 panels and folds weakly. That is the price, and it
is worth paying.

---

## F · FIVE vs SIX ZONES — **five is sufficient. Do not add a sixth.**

A sixth zone would need all five of your criteria. Testing the strongest candidate:

**Candidate: “Communication / Engagement”** (messages, notices, PTM, homework) —
❌ *no substantial panels exist today.* Communication is broadcast-shaped, PTM is not per-student in
this codebase, Homework is not built into Student 360. A zone with zero panels is not a zone.

**Candidate: “Compliance / Lifecycle”** (TC, certificates, promotion) —
❌ these are **actions**, not panels. `issue_tc` is one descriptor. The Action Registry already
carries them.

No candidate meets the bar. Every future capability maps into the five (§M).

**The real ceiling is not the zone count — it is RECORDS at 4 panels.** Watch that number.

---

## G · ACTION REGISTRY MAPPING — all 16, no second system

| Zone | Actions | Permissions |
|---|---|---|
| **Money** | collect_payment · add_entitlement · approve_entitlement · discontinue_entitlement · preview_fees · view_receipts · view_ledger | all `Fees:*` |
| **Academics** | open_result · mark_attendance | `Results:view` · `Attendance:view` |
| **Student & Family** | open_discipline | `Red Flags:view` |
| **Services** | suspend_transport · resume_transport · change_route · manage_hostel | `Transport:*` · `Hostel:edit` |
| **Records** | manage_documents · issue_tc | `SIS:edit` · `Certificates:edit` |

**16 / 16 mapped. Zone boundaries align exactly with RBAC module boundaries** — every action in a
zone shares that zone's permission family. That is the strongest available evidence that these
groupings are real and not cosmetic.

### The duplication risk, and how to avoid it

**Do not give zones their own action lists.** The correct model:

* `Action_registry::resolveFor()` stays the **single** resolver. Unchanged.
* Zones **filter** the already-resolved list by the existing `category` field. That field already
  exists on every descriptor — no new metadata, no second registry, no second permission check.
* The **global action rail is retained**, not replaced. A zone shows *its* actions; the rail shows
  *all permitted* actions. The rail remains the complete, canonical view.

**Concrete danger to avoid:** if a zone rendered a button the registry had filtered out, Student 360
would have grown a second permission system. The rule that prevents it: **a zone may only ever
render a subset of `resolveFor()`'s output — never an item of its own.**

**One placement note.** `mark_attendance` and `open_discipline` are declared `category: 'Records'`
today while belonging to Academics and Student & Family. That is a one-line data correction in the
registry at implementation time — **not** a code change, and not something to do now.

---

## H · DESKTOP UX

### 1366 × 768 — the school-office laptop, and the binding constraint

Usable height after browser chrome and the 58px app header: **≈ 590px.**

| Element | Height |
|---|---|
| Identity header | ~86px |
| Alert strip | ~44px |
| 5 folded zone bars @ ~52px | ~260px |
| **Remaining for the expanded zone** | **≈ 200px** |

**200px is not enough** for a 38-row demand table, and the operator will scroll on every student.

**Required mitigations, all layout-only:**

1. **Fold the four inactive zones to ~44px, not 52px** — one line, headline right-aligned.
2. **Drop the five summary stat cards.** They duplicate what the folded zones now show — that is
   ~90px returned, and it removes a genuine redundancy the zone model creates.
3. **Move the action rail from a 300px right column to a horizontal strip inside the expanded
   zone.** At 1366px a fixed 300px column costs 22% of width for buttons.

With those three: expanded working area ≈ **380px**. Workable.

### 1920 × 1080

Comfortable. Cap the content column at ~1400px; do not let a 5-column zone strip stretch — the
existing dashboard already caps its stat grid for exactly this reason (`home.php:176-186`).

**Money remains prominent without dominating** because it is first in reading order and carries the
only red figure. It does not need to be visually larger.

---

## I · MOBILE UX (375px)

**Do not render five zones as five collapsed bars on a phone.** Five headers plus an identity block
plus alerts fills the viewport before any content appears — the operator scrolls to reach a fold.

**Recommended adaptation — same IA, different mechanic:**

* Zones become a **single-open accordion**, full width, in the same order. Spatial memory is
  preserved by *order*, which is what survives on a narrow screen; side-by-side position is not.
* **No zone is open by default** on mobile. The first screen is: identity → alerts → five labelled
  rows with their folded values. That is a complete, useful summary in one viewport.
* Inner panel tabs become a horizontally-scrollable chip row, not a wrapping grid.
* Tables get `overflow-x: auto` per-table (already the current pattern) — **never** a horizontally
  scrolling page.
* Touch targets: zone headers **≥ 48px**; inner tabs ≥ 44px.
* The action rail moves to the **bottom of the expanded zone**, not a floating bar.

**The five-zone concept does not become confusing on mobile** — it becomes *more* useful, because
five labelled rows are a better phone summary than thirteen tabs ever were.

---

## J · EXPANSION / COLLAPSE — assessed against all five options

| Model | Verdict |
|---|---|
| **A · Accordion** | Same mechanic, no spatial layer. Fine on mobile (recommended there), loses the desktop advantage. |
| **B · Tabs** | What exists. 13 tabs wrap to 2 rows at 1180px — measured, not estimated. |
| **C · Full-page switching** | ❌ Rejected. Destroys the single-workspace premise; every switch is a context loss. |
| **D · Current flat 13-tab** | Works, 1 click to anything, but no hierarchy: 13 equal choices with no grouping. |
| **E · Five-zone spatial** | ✅ **Recommended, with the §H mitigations.** |

**Why E wins — the honest version.** It is not faster. It trades **1 click to any panel** for
**0 clicks to any summary**, which is the better trade *only because* the alert strip already proved
operators need the summary more often than the detail. The zone model makes that permanent instead
of a strip of pills.

**Where E is genuinely worse:** an operator who knows exactly which panel they want now needs two
clicks instead of one. See §L.

---

## K · FOLDED-ZONE SUMMARY STRATEGY

Requirement §8 (no empty headers) and §15 (no new reads) constrain each other. Feasibility, from the
actual eager context:

| Zone | Eager sources | Folded value | Feasible? |
|---|---|---|---|
| **Money** | `fees` | `₹35,500 outstanding` | ✅ Full |
| **Academics** | `defaulter.resultWithheld` only | `Result withheld` — or `—` when clear | ⚠️ **Weak** |
| **Student & Family** | `discipline`, `entitlements` | `No flags · 1 request pending` | ✅ Full |
| **Services** | `transport` | `South Circle` (hostel unknown) | ⚠️ Partial |
| **Records** | `academic` *(after the F-1 move)* | `ADM-2026-159 · Class 8th` | ✅ **Only because of the move** |

**Under the proposal as written, RECORDS folds to nothing** — 0 of 3 panels eager. That is a direct
§8 violation, and it cannot be fixed without either a new read (§15 violation) or the F-1 move.

**ACADEMICS is the remaining weak spot and I will not paper over it.** §8's own example — “latest
result / percentage / grade” — is **not available**: `results` is lazy. Options:

1. **Accept a state-only fold** — `Result withheld` when true, `—` otherwise. *Recommended.* Honest,
   costs nothing, and the withheld case is the one that matters operationally.
2. Add a `results` read to the eager context. **Costs ≤40 documents on every page load** for a
   figure most visits never use. **Not recommended.**
3. Show the class/section from `academic`. Rejected — that is profile data pretending to be
   performance data.

**Rule for implementation: a folded zone shows a real stored value or an em dash. It never shows a
computed placeholder, and it never triggers a fetch.**

---

## L · CLICK DEPTH

| Path | Today | Five zones |
|---|---|---|
| See any zone's headline | 1 click | **0 clicks** ✅ |
| Reach a zone's **primary** panel | 1 click | **1 click** (expand; first inner tab pre-selected) |
| Reach a **secondary** panel | 1 click | **2 clicks** ⚠️ |
| Panels at 1 click | 13 | **5** (one per zone) |
| Panels at 2 clicks | 0 | **8** |

**Assessment: acceptable, conditional on inner-tab ordering.** The primary panel of each zone must
be pre-selected — Fees, Results, Discipline, Transport, Profile. Get that ordering right and the
five most-used panels stay at one click while every summary drops to zero.

**Get it wrong and this design is strictly worse than what exists.** That is the single highest
implementation risk in this review.

Six zones would push a sixth choice into the top level for no gain. Four would overload Money.
**Five is the right number.**

---

## M · FUTURE EXTENSIBILITY — every candidate has a home

| Future capability | Zone | Confidence |
|---|---|---|
| Library (issues, fines) | **Services** — opt-in, billable, has dues | High |
| Medical (allergies, nurse visits) | **Student & Family** — a day-to-day concern | High |
| Activities / clubs | **Student & Family** | High |
| Communication history | **Student & Family** — what we told the family | Medium |
| Sibling information | **Records** — a stored fact about the student | High |
| Behaviour expansion | **Student & Family** — extends Discipline | High |
| Counselling | **Student & Family** — ⚠️ confidentiality may need its own RBAC | Medium |
| Hostel expansion | **Services** | High |
| Transport GPS | **Services** | High |
| Examination expansion | **Academics** | High |
| Parent communication history | **Student & Family** | Medium |

**No candidate is homeless. No sixth zone is required.**

⚠️ **One architectural warning.** Student & Family absorbs *six* of eleven future candidates. It is
the growth zone. If Medical, Counselling and Communication all land, it becomes the new Money —
5+ panels — and will need internal grouping. **Watch it; do not pre-empt it.**

---

## N · PERFORMANCE / READ-BUDGET IMPACT

| Guarantee | Impact |
|---|---|
| No new Firestore reads | ✅ **Zero.** Zones regroup rendered output; the context object is unchanged. |
| Lazy stays lazy | ✅ Attendance, Hostel, Documents, Certificates, Results, Receipts unchanged. **A zone must fetch its lazy panel on inner-tab click, never on zone expand** — otherwise expanding Money would pull Receipts, converting lazy to eager by accident. |
| Eager unchanged | ✅ Still ≤803 documents worst case. |
| No duplicated pricing / entitlement / RBAC / action logic | ✅ Zones filter `resolveFor()` output by existing `category`. |
| No writes, no RTDB, no schema change | ✅ Presentation only. |

**The one real risk: expand-triggers-fetch.** If a zone eagerly loads all its panels on expand,
opening Money costs Receipts, opening Records costs Documents *and* History. **Fetch must stay bound
to the inner tab, exactly as it is bound to the current tab.**

---

## O · ACCESSIBILITY & KEYBOARD

* Zone headers are **buttons** with `aria-expanded`, in a `role="region"` with `aria-labelledby` —
  not clickable `<div>`s.
* **Tab** moves between the five zone headers; **Enter/Space** expands; **Arrow keys** move between
  inner tabs (the current tab row already implements this — reuse it).
* Expanding must **move focus into the zone**, and collapsing must **return it to the header**.
  Without this, keyboard users lose their place on every expand.
* The folded headline must be **inside** the header's accessible name: *“Money, ₹35,500
  outstanding, collapsed”* — not a visually adjacent span a screen reader announces separately.
* Severity must never be **colour alone** — the current alert strip already pairs colour with text;
  folded zones must do the same.
* `prefers-reduced-motion`: expand/collapse becomes instant. Already respected in the current CSS.

---

## P · RISKS AND TRADE-OFFS

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R-1 | **Wrong inner-tab order makes the design slower than today.** | **High** | Fix the primary panel per zone before building (§L). |
| R-2 | **1366×768 leaves ~200px of working area.** | **High** | The three §H mitigations. Non-optional. |
| R-3 | Zone expand triggers lazy fetches. | **High** | Bind fetch to inner tab only (§N). |
| R-4 | ACADEMICS folds weakly. | Medium | Accept state-only fold; do not buy a summary with 40 reads. |
| R-5 | Approved parent request appears to vanish. | Medium | Approve/reject reachable in-zone + explicit confirmation (§C). |
| R-6 | Zones grow their own action lists → second permission system. | **High** | Zones may only render a subset of `resolveFor()` (§G). |
| R-7 | Student & Family becomes the new overloaded zone. | Low now | Monitor; revisit at 5 panels. |
| R-8 | Redesign lands before UAT ⇒ every UAT screenshot is stale. | Medium | Exactly why it should land first (§16). |

**The honest overall trade:** this design is **better for the common case and worse for the
specialist case.** An operator scanning a student gains a great deal. An operator who knows they
want Receipts pays one extra click, every time.

---

## Q · FINAL RECOMMENDATION

### **MODIFY 5-ZONE DESIGN — then implement before UAT.**

**Approve:** the five-zone model, the expand-in-place interaction, the folded-summary requirement,
the retained global action rail, and “Money” / “Academics” / “Services” / “Records” as names.

**Modify — three changes:**

| # | Change | Reason |
|---|---|---|
| 1 | **“Academic” panel → RECORDS, renamed “Profile”** | Contains zero academic data (17 profile fields). Fixes F-1 **and** F-2 — it is the only eager source that can fold RECORDS. |
| 2 | **Attendance → ACADEMICS** | Not for symmetry: without it ACADEMICS holds one panel. Attendance is a performance signal and belongs beside marks. |
| 3 | **“Student & Engagement” → “Student & Family”** | “Engagement” is jargon no clerk uses. The zone holds concerns about the student and requests from the family. |

**Conditional:** Parent Requests stays in Student & Family **only if** approve/reject is reachable
in-zone and approval says where the row went (§C). Otherwise move it to MONEY.

**Final mapping:**

```
1 · MONEY             Fees · Receipts · Entitlements                     7 actions
2 · ACADEMICS         Results · Attendance                               2 actions
3 · STUDENT & FAMILY  Discipline · Parent Requests                       1 action
4 · SERVICES          Transport · Hostel                                 4 actions
5 · RECORDS           Profile · Documents · Certificates · History       2 actions
                      ─────────────────────────────────────────────────────────
                      13 / 13 panels          16 / 16 actions
```

**On sequencing:** implement before UAT. The alternative — UAT the current UI, redesign, UAT again —
invalidates every screenshot and repeats a three-hour run. The change is presentation-only: no new
reads, no logic touched, no RBAC change. It is a low-risk change to make *now* and an expensive one
to make later.

**Prerequisite before implementation:** decide **R-1** (primary panel per zone) and **R-5** (Parent
Requests condition). Both are yours to call, and both change what gets built.

---

**Working tree HELD. Nothing modified. Awaiting explicit authorization.**
