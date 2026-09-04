# 13 — Coverage Ledger · AI Assistant ("Ask ZenXii")

Per §16.1: counts and **named** gaps. No completeness percentages — the denominator (the true total
of workflows, error paths, transitions and model behaviours) is exactly what is unknown, and a
manufactured percentage gets read as a measurement.

Where a real denominator exists it is stated as a fraction with both numbers visible.

**Evidence ceiling for the entire run: E2.** Nothing was executed. All 258 rows ship
`Status = NOT TESTED`, `Evidence Level = E2`.

---

## COVERAGE LEDGER — AI Assistant

```
                             Discovered  Modelled  Rows   Open gaps
Product surfaces                  2/5        2      258   Admin panel: NO surface exists
                                                          at all (the finding, not a gap
                                                          in coverage). iOS: not yet due.
                                                          Teacher: deliberate, server-enforced.
Cloud Functions                   1/1        1       —    —
CF tools                          6/6        6       —    —
Firestore collections             9/9        9       —    2 have no rules block of their
                                                          own (assistantQuota, assistantLogs)
Composite indexes needed          4/4        4       —    declared ≠ deployed (T0-11)
Assistant-specific indexes        0          0       —    0 declared for either assistant
                                                          collection ⇒ telemetry unqueryable
Rules match blocks                0          0       —    both collections rely on the
                                                          CATCH-ALL, not a named block
Storage paths                     0          0       —    module touches no Storage
Push marks                        0          0       —    correct: Support Desk owns that chain
RBAC modules                      0          0       —    not an RBAC module, no sidebar entry
Entry points                      1/1        1        5   English-only keywords ⇒ effectively
                                                          unreachable in 5 of 6 locales
Client conversation states        7          7       —    —
Client legal transitions          8          8       —    —
Client ILLEGAL-but-permitted      7          7       —    C-I1…C-I7 all carry rows
Client REQUIRED-but-impossible    4          4       —    C-B1…C-B4 all carry rows
Server turn states                7          7       —    tool-failed is NOT a terminus
Server illegal transitions        6          6       —    S-I1…S-I6 all carry rows
Quota bucket states               5          5       —    —
Enablement states                 3          3       —    never-enabled → enabled has NO
                                                          in-product actor
Module invariants               11/11       11       —    2 hold (I1 narrowed, I5); both are
                                                          undefended by any test
Global invariants (G/Z)         14/14        —       —    G3 (locked period) and Z5/Z6/Z7
                                                          are structurally inapplicable here
HttpsError emission sites         7/7        7       —    —
Firebase codes mapped in app      5/17        5       —    12 fall to one generic message,
                                                          incl. UNAVAILABLE and INTERNAL
UX states (A10 table)           16/16       16       —    5 have no treatment of their own;
                                                          2 are dead ends
Locales                          6/6         6       19   see NAMED GAP 3 (cross-product)
Roles                            3/3         3        4   student, parent accepted;
                                                          staff refused
⚑ CONTESTED items                8/8         8        8   all at the top of T0
[UNKNOWN] items                  35         35       —    every one names its measurement
Distinct risks registered        80         80      258   every risk maps to ≥1 row
A11 hostile rows (Part B)      76+R0        77       —    all 77 mapped into the matrix
```

### Row counts by tier (budget §15.1)

| Tier | Budget | Authored | Notes |
|---|---|---|---|
| **T0** — certification blockers | 30–60 | **45** | R0 is row #1; all 8 ⚑ CONTESTED are rows 2–9; every T0 row names an invariant |
| **T1** — core journeys, per surface, per role | 60–120 | **75** | |
| **T2** — negative, boundary, failure, offline, concurrency, lifecycle | 80–150 | **88** | |
| **T3** — UI states, navigation, a11y, responsive, dark mode, landscape, locales | remainder | **50** | |
| **Total** | — | **258** | |

---

## Agents spawned / skipped

| Agent | Ran | Reason if skipped |
|---|---|---|
| A1 CARTOGRAPHER | ✅ | `00-dependency-graph.md` |
| A3 MOBILE-SPEC | ✅ | `01-mobile-spec.md` |
| A4 BACKEND-SPEC | ✅ | `01-backend-spec.md` |
| A5 DATA-SPEC | ✅ | `01-data-spec.md` |
| A6 MODELLER | ✅ | `02-state-machine.md` + `03-invariants.md` |
| A7 DIFF-ANALYST | ✅ | `04-parity-matrix.md` |
| A8 SECURITY-RED | ✅ | `01-security-red.md` |
| A9 PERF-ANALYST | ✅ | `01-perf.md` |
| A10 UX-CRITIC | ✅ | `01-ux-critic.md` |
| A11 ADVERSARY | ✅ | `11-adversary.md` — attacked every `[CONFIRMED]` and every P0/P1; produced 6 named attacks, 14 verdicts, 8 ⚑ CONTESTED and 76 hostile rows |
| A12 TEST-ARCHITECT | ✅ | this document + `05`, `06`, `07`, `14` |
| A2 WEB-SPEC | ❌ | **There is no admin-panel surface for this module.** A1's absence register searched for a controller, a view, a setting, an RBAC entry and a sidebar item and found zero of each. That absence is itself the P0 finding (R-01), not a coverage gap — but it means **no browser, session, multi-tab, CSRF or form-validation behaviour was examined, because none exists to examine.** The moment an enablement toggle is built, A2 must run before it ships. |

**This is the only run in the programme so far where all eleven analysis agents ran.** No finding in
`05-risk-register.md` is unattacked — A11 ran to completion and its re-grades are carried through.

---

## NAMED GAPS

### 1. Model behaviour cannot be settled by any number of rows
Four of the eight ⚑ CONTESTED items (**C2** forged-history compliance, and by extension the refusal
rows T0-35/T0-36/T2-02/T2-86/T2-87/T2-88) are questions about a language model's disposition. **Every
one of these rows is a single sample, not a proof.** A refusal that holds once may not hold twice; a
refusal that breaks once has proved something, but a refusal that holds is evidence of nothing
stronger than "it held that time." No row in this matrix converts a probabilistic control into a
deterministic one, and none can. The only structural fix is the one the invariant catalogue names:
enforce these boundaries somewhere other than English.

### 2. The full refusal × locale cross-product is NOT authored — deliberately
A11 specified 20 refusal attacks **× 6 languages = 120 rows**, on the grounds that
`SYSTEM_PROMPT:469-470` promises replies in the student's language and *a refusal that only exists in
English is not a refusal*. The matrix authors the 20 refusal attacks (T0-35, T0-36, T0-37, T0-38,
T1-64…T1-67, T2-02, T2-05, T2-08, T2-09, T2-10, T2-29, T2-86, T2-87, T2-88, T0-03, T0-34) and a
separate 6-locale sweep (T1-32…T1-38, T3-38…T3-42), but **does not cross them.** That is a budget
decision under §15.3 rule 5, and it is a real gap: **no row proves that the tutoring, wellbeing or
other-student refusal holds in Tamil, Telugu, Gujarati or Marathi.** The system instruction is
English. If the human has budget for 100 more rows, this is where they should go — ahead of anything
in T3.

### 3. Nothing exercises a deployed Firestore rule for this module
There is no `match` block for `assistantQuota` or `assistantLogs`; both fall to the catch-all deny.
T0-12 confirms the catch-all still holds, but **no row tests a rule written for this feature, because
none exists.** The protection is a default branch, and it survives only until someone adds a broader
ancestor match — an event no test in this matrix would notice.

### 4. Load and volume are analytical, not observed
T2-69 runs 50 concurrent students. The modelled peak is **500** (the hour after results publish).
Nothing in this matrix exercises the real peak, and the number that decides whether the peak survives
— the project's Vertex QPM quota — is `[UNKNOWN]` (U-24). Every latency, cost and concurrency figure
in the source reports is a model with stated assumptions, not a measurement.

### 5. No fabrication oracle exists
T0-37 and T2-09 ask the tester to record fabrication as a FAIL. **There is no automated way to detect
it.** A model that invents "Priya scored 82" surfaces no data, produces no error, writes a normal log
row, and is indistinguishable to the reader from a correct answer. The matrix depends on a human
recognising a plausible falsehood. This is the weakest link in the whole run and it should be said
plainly rather than buried.

### 6. Panel-side and iOS coverage is structurally empty
- **Admin panel:** zero rows, because zero surface exists (see A2 above). The enablement toggle, the
  consent record, and any reader for `assistantLogs`/`assistantQuota` are all unbuilt, so nothing can
  be tested about them beyond confirming their absence (T0-01, T0-39).
- **iOS:** no implementation on either product. A7 assessed this as *not yet due* — `ZenXiiCore`
  covers auth, dashboard, notice, homework and attendance models only, so the assistant is ahead of
  the iOS feature set rather than missing from it. Filed under the general iOS backlog.
- **Teacher Android:** no surface, deliberately, and correctly enforced server-side (T0-16). Not a
  parity defect.

### 7. Nothing verifies the deployed source
The module is **uncommitted on both repos** and exists on disk only. T0-10 asks the tester to compare
the deployed revision against the audited source — but if they diverge, **every row in this matrix is
testing something other than what the ten reports read.** There is no automated coupling.

### 8. Retention and subject-access cannot be tested, only observed as absent
T0-39 asks the tester to *attempt* a subject-access request and *attempt* an erasure through any
existing product path. Both attempts are expected to fail because no path exists. That is a
confirmation of absence, not a test of behaviour, and it cannot become a real test until something is
built.

### 9. Two controls that hold are protected by rows that would not catch their most likely repeal
T0-17/T0-36 protect I1's structural core and T0-33 protects I5 — but both would be repealed by a
*code edit* (one argument added to a tool schema; one write added to `TOOL_IMPL`), and a UAT row only
notices after that edit has already shipped. **The correct defence is the offline test the module
does not have** (T2-76 records its absence). A matrix cannot substitute for a unit test here, and
pretending otherwise would be the single most misleading claim this run could make.

### 10. Fixture data is thin for every truncation and volume row
T2-31…T2-35, T2-66 and T1-55 all require a student or section with **more than 25** of something.
If no such fixture exists in the test school, those rows become unexecutable and every truncation
finding stays analytical. Building that fixture is a prerequisite, not a step.

---

## Explicitly unexamined areas

- **The 05:30 IST quota boundary in both directions across a real day** (T2-12) requires a tester
  awake at 00:30 and 05:35 IST. If that is not practical, the finding stays analytical.
- **Sustained multi-day usage.** No row runs the feature across more than one UTC day except T1-42.
  Quota-document growth, log growth and the roll edge are otherwise unobserved.
- **Real household dynamics.** The credential is shared. No row models two family members using the
  same account in the same hour, beyond T2-47's two-device probe.
- **The relationship between the assistant and the Support Desk after the handoff.** T1-28 confirms a
  ticket is created normally, but nothing follows that ticket through triage, reply and closure — the
  Support Desk certification owns that.
- **Interaction with other modules' session rollover, promotion and TC issuance.** T2-15 and T0-41
  probe the edges; the full cross-module workflow is out of scope.
- **Any behaviour of the `_smoke_assistant.js` harness**, which requires a live Gemini key against the
  Developer API — a different auth surface from production — and is wired to no npm script.

## Out of scope — do not re-flag

- **A9's "33–99× over cost target."** QA-LEAD has **OVERTURNED** it. The target the code states is
  `~₹3/student/month` (`studentAssistant.js:189`), giving ~10× at the quota ceiling, and the correct
  framing is *"the 30/day quota is a fair-use control, not a cost control."* No row is authored
  against the overturned number, and no result should be scored against it.
- **"The feature has never run in production."** **DOWNGRADED to `[UNKNOWN]`.** The *mechanism* (no
  writer, therefore no consent record) survives and is R-01. The *consequence* is settled only by
  T0-02. A6's knock-on claim that "S4–S7 and every state of §3 are unreachable in production" inherits
  the same broken entailment and is downgraded with it.
- **A7's C1 as an authorization finding.** **RE-CLASSED to correctness/identity** — G1 and G2 are
  intact. It stays high (T0-18), because the assistant names the wrong child, but it is not a
  cross-family leak and must not be reported as one.
- **Index gaps.** A11 checked all five tool queries against `firestore.indexes.json` and **REFUTED**
  the hypothesis: every required index is declared with an exact match. Only *deployment* is open
  (T0-11).
- **`assistantLogs` / `assistantQuota` client exposure.** **REFUTED** — the catch-all deny covers
  both. The residual finding is that it is a default branch rather than a named block (T0-12).
