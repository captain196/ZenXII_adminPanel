# Calendar — Integration Readiness & Sequencing

**AUTHORITATIVE.** Where this document conflicts with
`CALENDAR_PROGRAMME_CLOSURE.md`, `CALENDAR_PROGRAMME_FINAL_CLOSURE.md`,
`CALENDAR_ISOLATION_PROOF.md` or `CALENDAR_DEPENDENCY_AUDIT.md`, **this document
wins.** Those four are superseded on every point about dependencies, isolation
and closure.

Verified against **HEAD `5136f0d`** (`test(placement): make the atomic write
ceiling testable without weakening it`), re-checked immediately before writing.
HEAD has moved three times during this programme; every claim below was
re-derived at this SHA, not carried forward.

---

## 1 · CURRENT AUTHORITATIVE STATUS

| state | value | how it is known |
|---|---|---|
| Feature logic complete | **YES** | resolver, reader, store, controller, view all implemented |
| Tests passing | **YES** | **370 tests / 1169 assertions OK**, executed this round (§12.4) |
| Mutation proven | **YES** | 67/67, 0 survived, 0 not applied, executed this round (§12.4) |
| **Dependencies landed at HEAD** | **NO** | 7 of 8 required symbols absent — §5 |
| **Clean-base integration works** | **NO** | gate exits 1 — §4 |
| **Commit can be safely created** | **NO** | §8 conditions unmet |
| Browser verified | **NO** | needs credentials — external boundary |
| Independently reviewed | **NO** | self-review only |
| Rules security suite | **BLOCKED** | environmental JVM memory, not a defect (§12.5) |
| Merged | **NO** | — |
| Deployed | **NO** | — |

### **PROGRAMME STATUS: NOT CLOSED.**

Calendar's own code is finished. That is one of ten states above, and it is not
the one that decides closure. Repository integration is blocked by other
programmes' unlanded work, and no Calendar code change can resolve it.

---

## 2 · WHAT CHANGED IN THIS AUDIT — TWO CORRECTIONS TO MY OWN PRIOR REPORTS

**Correction 1 — `schoolWhereOrFail` IS required.**
`CALENDAR_DEPENDENCY_AUDIT.md` §1 stated it was "not a blocker — the HEAD
version of `Holiday_service` predates it". That reasoning inspected the wrong
file. `special_working_days_in_month` exists only in the *working-tree*
`Holiday_service`, and that version reaches it:

```
special_working_days_in_month()   line 188
  └── _load_swd()
        └── _load_all_types()      line 367
              └── _query_firestore()  line 396
                    └── $this->fs->schoolWhereOrFail('calendarEvents', [])   line 422
```

Proven by execution in §6: landing `Holiday_service.php` alone leaves SWD
**FAIL**; it passes only once `Firestore_service.php` lands with it. **The two
must land together.**

**Correction 2 — an eighth symbol exists, and it is already satisfied.**
`Firebase::firestoreCommitBatchStatus`, used by the store's commit path, was
absent from every earlier list. It **is present at HEAD**. Not a blocker, but
its omission means the earlier lists were assembled from memory rather than
from the source. This audit derived the symbol list by grepping the Calendar
runtime files directly.

---

## 3 · CALENDAR-OWNED vs EXTERNALLY OWNED

### Calendar-owned — mine to commit

```
application/libraries/Student_calendar_service.php     pure resolver
application/libraries/Student_calendar_reader.php      settings I/O
application/libraries/Student_calendar_store.php       the only writer
application/controllers/Student_calendar.php           admin surface
application/views/student_calendar/index.php           School Week screen
application/controllers/Calendar_perf_probe.php        diagnostic (optional)
scripts/calendar_integration_gate.php                  THIS gate (optional)
scripts/student_calendar_mutation.php                  67-mutant battery (optional)
scripts/calendar_view_js_lint.php                      JS gate (optional)
scripts/calendar_js_php_agreement.php                  agreement sweep (optional)
scripts/calendar_commit_isolation.php                  hunk classifier (optional)
firebase-rules/tests/cal_d1_tenant_binding.test.js     26 security tests (optional)
tests/Unit/*  ×12                                      (optional at runtime)
```

Plus Calendar's share of shared files: `routes.php` (10 contiguous lines),
`header.php` (3 lines), `Attendance.php` (6 hunks).

### Externally owned — **NOT mine to commit under any circumstances**

| file | owning programme |
|---|---|
| `application/helpers/effective_dating_helper.php` | HRMS W2.3 / Compensation |
| `application/libraries/Leave_year_service.php` | Workforce Policy, Leave Phase 1 |
| `application/libraries/Firebase.php` | platform (currently staged by Fees/PayOps) |
| `application/libraries/Firestore_service.php` | Attendance hardening (ACAD-D17) |
| `application/libraries/Holiday_service.php` | Workforce Policy Phase D |

No Calendar-local replacement has been written for any of these, and none will
be. `effective_dating_helper.php`'s own docblock warns that a second
implementation would silently disagree; duplicating it to make Calendar
committable would manufacture exactly that defect.

---

## 4 · CLEAN-WORKTREE EXECUTION EVIDENCE

`scripts/calendar_integration_gate.php` creates its own detached worktree at
HEAD, copies **only Calendar-owned files**, copies **no dependency**, executes
nine runtime entry points in separate PHP processes, then removes the worktree.

### Real run at HEAD `5136f0d` — **exit 1**

```
--- 1. REQUIRED SYMBOLS AT COMMITTED HEAD ---
  [CORE     ] ed_select_for                                    ABSENT
  [CORE     ] ed_is_iso_date                                   ABSENT
  [CORE     ] ed_find_overlaps                                 ABSENT
  [SAVE     ] Firebase::firestoreGetOrFail                     ABSENT
  [SAVE     ] Firebase::firestoreCommitBatchStatus             PRESENT
  [SWD      ] Holiday_service::special_working_days_in_month   ABSENT
  [SWD      ] Firestore_service::schoolWhereOrFail             ABSENT
  [READINESS] Leave_year_service::boundsForSession             ABSENT
  [READINESS] Leave_year_service::DEFAULT_START_MONTH          ABSENT

--- 3. RUNTIME ENTRY POINTS (executed, not inspected) ---
  PASS [CORE     ] resolveForDate() pure path        attendance=true
  FAIL [CORE     ] effectiveVersion()                Failed opening required …
  FAIL [CORE     ] validateVersion()                 Failed opening required …
  FAIL [CORE     ] validateVersionAgainst()          Failed opening required …
  FAIL [CORE     ] reader->resolveMonth()            Failed opening required …
  PASS [CORE     ] reader->resolveDate()             instruction=true source=legacy
  FAIL [SAVE     ] store loads + save path wired     Firebase::firestoreGetOrFail ABSENT
  FAIL [SWD      ] special_working_days_in_month     ABSENT
  FAIL [READINESS] Leave_year_service                file ABSENT

  clean-base LOADS + RESOLVES (CORE) : NO
  clean-base FULLY FUNCTIONAL        : NO
```

### The gate is falsifiable — capability check, **exit 2**

A red gate that could never go green is no better evidence than a green suite
that could never go red. `--simulate-landed` copies the dependency files in and
re-runs. All nine pass:

```
  PASS [CORE] resolveForDate           attendance=true
  PASS [CORE] effectiveVersion         selected=true
  PASS [CORE] validateVersion          ok=true
  PASS [CORE] validateVersionAgainst   ok=true
  PASS [CORE] reader->resolveMonth     nonWorking=4 source=configured
  PASS [CORE] reader->resolveDate      instruction=true source=legacy
  PASS [SAVE] store + save path wired  save path wired
  PASS [SWD ] special_working_days…    SWD reader present
  PASS [READ] boundsForSession         session authority present

  SIMULATION ONLY … Calendar is NOT integrated. Exit forced to 2.
```

**This is the load-bearing result of the whole audit:** with the dependencies
present and nothing else changed, every Calendar entry point executes. The only
thing between Calendar and clean-base runnable is five files landing through
their owners. Simulation deliberately exits **2**, never 0, so it can never be
mistaken for an integration pass.

---

## 5 · SYMBOL-BY-SYMBOL READINESS TABLE

Presence measured in all three git states at HEAD `5136f0d`.

| symbol | owning file | owning programme | HEAD | staged only | unstaged only | Calendar function affected | failure mode if absent | safely caught? | condition to satisfy |
|---|---|---|---|---|---|---|---|---|---|
| `ed_select_for` | `effective_dating_helper.php` | HRMS W2.3 | ✗ | ✗ | **✓** | `effectiveVersion()` — policy selection | **FATAL at load** — `require` fails | **NO** | file committed to HEAD |
| `ed_is_iso_date` | same | HRMS W2.3 | ✗ | ✗ | **✓** | `validateVersion()` | **FATAL at load** | **NO** | same file |
| `ed_find_overlaps` | same | HRMS W2.3 | ✗ | ✗ | **✓** | `validateVersionAgainst()` | **FATAL at load** | **NO** | same file |
| `Firebase::firestoreGetOrFail` | `Firebase.php` | platform / PayOps | ✗ | ✗ | **✓** | `store::saveVersion()` | `Error` at call | **YES** — store catch → `FAILED` + message | function present at HEAD (**see §7**) |
| `Firebase::firestoreCommitBatchStatus` | `Firebase.php` | platform | **✓** | ✓ | — | store commit | — | — | **already satisfied** |
| `Holiday_service::special_working_days_in_month` | `Holiday_service.php` | Workforce Phase D | ✗ | ✗ | **✓** | SWD override in Attendance/Exam/Academic | `Error` at call | **YES** — caller catch → legacy fallback | file + `Firestore_service` together |
| `Firestore_service::schoolWhereOrFail` | `Firestore_service.php` | Attendance ACAD-D17 | ✗ | ✗ | **✓** | transitive via `_query_firestore()` line 422 | `Error` at call | **YES** — same catch | **must land with `Holiday_service`** |
| `Leave_year_service::boundsForSession` | `Leave_year_service.php` | Workforce Leave P1 | ✗ | ✗ | **✓** | `_session_window()` → readiness banner | `Error` at call | **YES** — `_calendar_readiness()` catch (line 10→34) → `checked=false` | file committed to HEAD |
| `Leave_year_service::DEFAULT_START_MONTH` | same | Workforce Leave P1 | ✗ | ✗ | **✓** | session fallback | `Error` at call | **YES** — same catch | same file |

**Every absent symbol is "unstaged only".** Not one is staged. Nothing is one
`git commit` away from landing by accident — except the hazard in §7, which cuts
the other way.

---

## 6 · MINIMUM LANDING SEQUENCE — PROVED BY INCREMENTAL EXECUTION

Dependencies added one file at a time to a clean-HEAD worktree, re-running the
tier checks after each:

```
AFTER ADDING   FILE                                 CORE  SAVE  SWD   READY
---------------------------------------------------------------------------
(none)         (Calendar files only)                FAIL  FAIL  FAIL  FAIL
CORE           effective_dating_helper.php          PASS  FAIL  FAIL  FAIL
SAVE           Firebase.php                         PASS  PASS  FAIL  FAIL
SWD-partial    Holiday_service.php                  PASS  PASS  FAIL  FAIL   ← note
SWD-full       Firestore_service.php                PASS  PASS  PASS  FAIL
READINESS      Leave_year_service.php               PASS  PASS  PASS  PASS
```

The `SWD-partial` row is the empirical proof of Correction 1: `Holiday_service`
alone does **not** unlock special working days.

### The sequence, with what each step buys

| step | owner | lands | Calendar gains | Calendar state after |
|---|---|---|---|---|
| **1** | HRMS / Compensation | `effective_dating_helper.php` | policy selection + validation | screen loads, previews, resolves attendance. **Cannot save.** |
| **2** | platform / PayOps | `firestoreGetOrFail` in `Firebase.php` | the write path | admins can save a school week |
| **3** | Workforce + Attendance **together** | `Holiday_service.php` **and** `Firestore_service.php` | special-working-day correctness | SWD overrides honoured |
| **4** | Workforce Leave P1 | `Leave_year_service.php` | readiness / rollover banner | **fully functional** |
| **5** | Calendar | owned files + `routes.php` 10 lines + `header.php` 3 lines + `Attendance.php` 6 hunks | reachability | committed |
| **6** | security | CAL-D1 rules, separate authorisation | tenant binding | deployed |

**Steps 1–4 are the smallest set that yields a fully working feature.** Not one
is optional if "fully functional" is the bar; step 1 alone gives only a partially
functional page, which is explicitly *not* what was asked for.

Steps 1–4 may land in any order — each is independently useful and none depends
on another — **except that step 3's two files must land together.** Step 5 must
come last among code steps.

**Optional, needed by nobody:** the diagnostics and 12 test files. They can ship
with step 5 or later; they are not runtime dependencies.

---

## 7 · THE `Firebase.php` HAZARD — AUDITED

**Question: can the owner commit `Firebase.php` without `firestoreGetOrFail`?**

**Answer: YES, and it is the most likely outcome right now.**

`Firebase.php` is currently **staged** by another session (Fees/PayOps, 22 files).
Measured at HEAD `5136f0d`:

```
firestoreGetOrFail in STAGED  Firebase.php : 0
firestoreGetOrFail in WORKING Firebase.php : 1
```

The function is an **82-line unstaged addition**. The staged copy does not
contain it. A `git commit` from the current index lands `Firebase.php` at HEAD
**without** it, leaving the function stranded as a working-tree diff against a
freshly rewritten base.

The rewrite makes this harder to spot: the staged diff reads
`1402 insertions(+), 1423 deletions(-)`, but `--ignore-cr-at-eol` reduces it to
**25 insertions, 46 deletions**. It is overwhelmingly a CRLF shift. Anyone
eyeballing that diff for a missing function will not find it.

**I have not modified, unstaged, or touched their work in any way.** This is
reported, not fixed — it is theirs.

### VERIFICATION RULE (binding)

> **Calendar integration is not considered unblocked until the required symbol
> exists in committed HEAD and is proven by execution in a clean worktree.**

Corollaries, each of which this hazard would defeat:

1. **"The file was committed" is not evidence.** `Firebase.php` can be committed
   with the symbol absent.
2. **A working-tree grep is not evidence.** The working tree contains five other
   programmes' unlanded files.
3. **A staged-copy check is not evidence.** Staged ≠ committed.
4. **Static inspection of HEAD is necessary but not sufficient.** Only execution
   catches a symbol that exists but is unreachable or misnamed.

The single command that satisfies the rule:

```bash
php scripts/calendar_integration_gate.php    # exit 0 = unblocked; anything else = still blocked
```

Per-symbol spot check, if the gate cannot be run:

```bash
git show HEAD:application/libraries/Firebase.php | grep -c 'function firestoreGetOrFail'   # must be 1
```

---

## 8 · EXACT CONDITIONS

### "Repository integrated" — ALL must hold

1. `php scripts/calendar_integration_gate.php` exits **0** (not 1, not 2).
2. All 8 required symbols report **PRESENT** in gate section 1.
3. All 9 runtime entry points report **PASS**.
4. The gate copied **no** dependency file (guaranteed unless `--simulate-landed`).
5. The gate ran against the true current HEAD, re-checked that day.

**Current: condition 1 fails (exit 1). NOT integrated.**

### "Commit-ready" — ALL must hold

1. Repository-integrated, above.
2. Calendar's commit contains **only** Calendar-owned files plus its three
   shared-hunk groups — never a shared file wholesale.
3. No file authored by another programme appears in the commit.
4. `scripts/calendar_commit_isolation.php` still reports 41 pure / 1 mixed, and
   the mixed `routes.php` hunk is lifted as the 10 contiguous lines only.
5. Full Unit suite matches the recorded baseline; 67/67 mutants; both JS gates clean.
6. Branch confirmed with the owner before staging anything.

**Current: condition 1 fails. NOT commit-ready.**

### "Closed" — ALL must hold

Repository-integrated **and** commit-ready **and** browser-verified **and**
independently reviewed. **Current: three of four fail. NOT CLOSED.**

---

## 9 · REMAINING HUMAN VERIFICATION

| item | why it cannot be done here | procedure |
|---|---|---|
| **Browser verification** | needs real credentials on a real tenant; I must not create accounts or probe the operator's live tenant | `CALENDAR_BROWSER_VERIFICATION.md`, 16 steps |
| **Dependency landing** | five files belong to three other programmes | §6 sequence — their owners, their review |
| **CAL-D1 rules deploy** | deployment authorisation | `CAL-D1-DEPLOY-PROCEDURE.md`; never the repo file wholesale |
| **Independent review** | self-review is not review | reviewer pack in `CALENDAR_COMMIT_PLAN_AND_REVIEW.md` §4 |
| **Branch decision** | ownership | ask before any staging |

**No browser verification has occurred.** Recorded here explicitly so it is not
inferred from the passing test suites.

---

## 10 · STOP CONDITION

**I have stopped, and this is why.**

Every repository-reachable verification is complete. The remaining work is not
Calendar engineering — it is four other programmes committing five files
through their own review paths. There is no autonomous action left that would
move integration forward without violating a boundary:

- Committing those files = shipping another programme's unreviewed work.
- Writing Calendar-local replacements = manufacturing the duplicate-authority
  defect this programme exists to remove, and explicitly forbidden.
- Unstaging or amending the PayOps index = modifying foreign work.
- Browser verification = credentials.
- Rules deploy = authorisation.

**Resume trigger:** when any dependency lands at HEAD, run

```bash
php scripts/calendar_integration_gate.php
```

Exit **0** means Calendar is clean-base runnable and step 5 can proceed. Any
other exit code means it is still blocked, and the gate names the tier.

---

## 11 · GIT SAFETY — VERIFIED THIS ROUND

| check | result |
|---|---|
| HEAD | `5136f0d`, unchanged by me |
| Calendar files staged | **0** |
| Calendar files at HEAD | **0** |
| Foreign staged files | 22 (Fees/PayOps) — **untouched** |
| Stashes | 3 — unchanged |
| My worktrees remaining | **0** — created and removed under `%TEMP%` |
| Foreign worktrees | ~16 from another session — **not touched** |
| Commits / merges / resets / deploys by me | **none** |
| Emulators or processes killed | **none** |

Both worktrees this audit created were removed and their absence confirmed by
`git worktree list`.

---

## 12 · AUTONOMOUS VERIFICATION ROUND (2026-09-02, HEAD `5136f0d`)

Everything below was executed this round. Nothing is carried forward from an
earlier session's claim.

### 12.1 The gate was incomplete — REACHABILITY tier added

The gate applied only 5 of the 8 components of the Calendar change set, so it
proved the resolver ran but never that the **feature is reachable**. A feature
whose route never landed is not integrated, however green its resolver is.

Added a REACH tier that applies Calendar's own share of `routes.php` and
`header.php` in the clean base and then proves, by execution:

```
--- 3b. REACHABILITY (route -> controller method) ---
  PASS [REACH] routes resolve to public methods    4 routes -> all resolve to public methods
  PASS [REACH] navigation entry present            School Week menu item found
```

Method names are extracted with **`token_get_all`**, not substring matching —
substring collisions have produced seven false results in this programme.

**REACH passes independently of the blocked dependencies.** Calendar's own
reachability wiring is sound; only the external symbols are missing.

The gate is now **11 checks across 5 tiers** (CORE, SAVE, SWD, READINESS, REACH).

### 12.2 The gate was proved red-capable — in both directions

Per the false-proof rule, a new verification mechanism is not accepted until it
is shown able to fail for the defect it claims to detect.

| mutation | expected | result |
|---|---|---|
| rename `resolveForDate` | red | **caught** |
| syntax error in resolver | red | **caught** |
| `reader::init` signature broken | red | **caught** |
| `resolveForDate` returns nothing | red | **caught** |
| route target names a nonexistent method | red | **caught** |
| navigation entry removed | red | **caught** |
| **flip effective-date window comparison** (pure logic) | **survive** | **survived** |

6/6 reachability mutants caught; the logic mutant survived **as required**.
That last row is the point: **this gate is not a correctness check.** It proves
the feature loads, resolves and is reachable. Correctness belongs to the
67-mutant battery. Source sha256 verified identical before and after every
mutation; the gate file restored byte-identically.

**Two false-greens were found and fixed in my own verifiers during this round:**
a NOT APPLIED mutant was not being counted as a failure, and a verdict line
printed a hardcoded claim instead of the measured result. A third — an
injection that silently failed to apply and reported PASS — was caught and
redone. These are the exact failure modes the rule exists to catch, occurring
in the tooling built to enforce it.

### 12.3 No blocker can be legitimately removed

Before accepting the external boundary, each dependency was tested for whether
it is genuinely required or merely theoretical:

| dependency | reachable from Calendar-owned code? | evidence |
|---|---|---|
| `Leave_year_service` | **YES** | `_calendar_readiness()` called at `Student_calendar.php:93` inside a live action |
| `special_working_days_in_month` | **YES** | called at `Student_calendar.php:131` — a Calendar-owned file, not only shared hunks |

Neither is theoretical, neither belongs only to a shared hunk, and no committed
alternative exists. **The boundary is real.** No Calendar-local replacement was
written, and none should be.

### 12.4 Correctness evidence re-executed this round

| check | result |
|---|---|
| Calendar unit tests | **370 tests, 1169 assertions, OK** |
| mutation battery | **67 mutants, 67 killed, 0 survived, 0 not applied** |
| inline JS parse gate | 4 script blocks, **0 failures** |
| JS/PHP agreement sweep | 84 JS skips, **0 disagreements** in either direction |
| commit isolation | **41 pure / 369 other / 1 mixed** — identical to baseline |

The isolation figure matching exactly, after HEAD moved three times, is
evidence that **no foreign commit has disturbed Calendar's hunks.**

### 12.5 CAL-D1 rules suite — NOT RUNNABLE, and it is not a defect

The suite failed 26/26 with `ECONNRESET`. Investigated rather than reported:

1. The emulator starts cleanly on the isolated ports (18080/14400/14500), which
   were verified free — **no other session's emulator was disturbed or killed.**
2. It crashes only when compiling the ~200 KB ruleset, with exit code 1.
3. `firestore-debug.log` gives the true cause:
   `os::commit_memory … The paging file is too small for this operation`
   — **JVM memory exhaustion**, on a box already running another session's
   emulator (ports 8080/4000, PIDs 8432/18568/18812).
4. Ten `hs_err_pid*.log` dumps were present, so this is chronic on this machine,
   not something this round introduced. They were removed.

`firestore.rules` was then verified intact **without** the emulator: 521/521
balanced braces, `rules_version = '2'` first line, single `match /databases`
block, correct terminator. **Not corrupted by concurrent editing.**

**Conclusion: the 26/26 failure is environmental, not a Calendar regression and
not a rules defect.** It cannot be resolved without either killing another
session's emulator (forbidden) or more machine memory. Recorded as unproven
this round rather than claimed from the earlier session's 26/26.

---

## 13 · GATE CHECKLIST

| gate | state | evidence |
|---|---|---|
| Requirements investigated | **PASS** | discovery + design readiness |
| Architecture / authority traced | **PASS** | student vs staff separation; `Leave_year_service` adopted, not duplicated |
| Implementation complete | **PASS** | 5 runtime files + 3 shared-hunk groups |
| Unit tests pass | **PASS** | 370 tests / 1169 assertions, this round |
| Mutation testing passes | **PASS** | 67/67, 0 not applied, this round |
| False-green audit | **PASS** | 6/6 red-capable + limit proven; 3 false-greens found and fixed in my own tooling |
| Integration paths tested | **PASS** | REACH tier: 4 routes → public methods; menu entry |
| **Clean-HEAD dependency test** | **FAIL** | gate exit 1 — 7 of 8 symbols absent |
| Shared-file boundaries verified | **PASS** | 41/369/1 unchanged across three HEAD moves |
| Browser behaviour verified | **NOT DONE** | TRUE STOP A — credentials |
| Rules security suite | **BLOCKED** | environmental JVM memory, §12.5 |
| Independent review | **NOT DONE** | required before closure |
| Deployment authorised | **NOT DONE** | TRUE STOP B |
| Production verification | **NOT DONE** | follows deployment |

**Four gates cannot be satisfied from this environment. The programme is NOT
CLOSED, and the deciding gate remains clean-HEAD integration.**
---

## 14 · STOP E INVESTIGATION + CONTINUOUS MONITORING (2026-09-02, later round)

### 14.1 STOP E (rules suite) — a safe route was built, and it still hits the wall

Attempted resolution **without touching any foreign process**, in order:

1. **Minimal ruleset.** The CAL-D1 suite was designed to accept any ruleset via
   `CAL_D1_RULES`. Built `tests/_build_min_ruleset.php`, which extracts every
   helper function plus only the collection blocks the suite exercises —
   **13.6 KB vs 207 KB (6.6%)**. Two of my own defects were found and fixed en
   route, both instructive:
   - the extractor captured the `match /databases` wrapper and swallowed the
     whole file (0 blocks extracted);
   - my hand-greped collection list **missed `appraisals`** because the suite
     loops over a `COLLECTIONS` array. The extractor now derives the list from
     the test file itself. A hand-written list would have produced a ruleset
     that passed while never testing one of the five arms.
2. **Proof of partial progress, by execution on the isolated emulator
   (ports 18080/14400/14500, verified free — no foreign emulator touched):**
   - the minimal ruleset **COMPILES** (emulator accepted it);
   - `initializeTestEnvironment` + a seed write **succeed** standalone
     (`_env_probe.js`: "RESULT: environment usable").
3. **The wall.** jest + emulator together still kill the JVM:
   `java.lang.OutOfMemoryError: Unable to allocate 4194304 bytes`. Free RAM
   measured at 580 → 410 MB while working; heap caps from 400–700 MB all
   accepted by `java -version` yet the emulator dies under test load. The
   remaining memory belongs to another session's emulator (ports 8080/4000),
   which will not be killed.

**Verdict: STOP E stands, but narrower than before.** The rules are proven
loadable and the harness proven usable; only the 26-assertion differential run
is blocked, purely by machine memory. Resume: re-run
`CAL_D1_RULES=$(pwd)/_cal_d1_min.rules npx firebase emulators:exec --config
firebase.isolated.json …` when the other emulator is gone or memory frees.
**Caveat recorded:** the minimal ruleset is an ARM-LEVEL proof only — a broader
rule elsewhere in the full file could still grant access this subset denies.
The full-file run remains required before deployment. Diagnostic tools kept in
`firebase-rules/tests/`: `_build_min_ruleset.php`, `_cal_d1_min.rules`,
`_env_probe.js`, `_rules_compile_check.js`, `_run_jest.sh`.

### 14.2 New dependency surfaces audited — no new blockers

- **View** (`student_calendar/index.php`): calls `has_permission()` — present
  at HEAD, and the view guards with `function_exists()` anyway. CSRF helpers
  are core CI. Nothing missing.
- **Shared hunks** (Attendance/Exam/Academic/Staff_role_check diffs): every
  external symbol called is either the Calendar-owned reader, or
  `Calendar_service::saveEvent` (**present at HEAD**), or one of three methods
  (`_holiday_calendar_readable`, `_log_if_calendar_unreadable`,
  `_weekly_closure_days`) that the **Calendar diff itself adds** — self-contained
  in the change set, not dependencies. A `Staff_attendance::_resolve_employee_calendar`
  hit turned out to be a comment citation, not a call.

**The dependency matrix in §5 is complete.** No ninth symbol exists.

### 14.3 Continuous re-verification armed

A persistent repository monitor now watches HEAD. On any new commit it reports
the commit, checks all five dependency symbols **at committed HEAD**, announces
any landing with an instruction to run the gate, and **specifically detects the
Firebase.php hazard**: if `Firebase.php` is committed without
`firestoreGetOrFail`, it says so immediately instead of letting "the file was
committed" masquerade as "the dependency landed".

### 14.4 The monitor itself was red-tested (integration completion mode)

The HEAD monitor is a verification mechanism, so the false-proof rule applies
to it. Its detection logic was replayed against a scratch git repository where
commits are safe: **9/9** — HEAD move detected; unrelated commit raises neither
landing nor hazard; `Firebase.php` committed without `firestoreGetOrFail`
raises HAZARD; symbols landing raise DEPENDENCY LANDED and clear the hazard;
quiet when HEAD is unchanged.

The red-test also exposed a latent defect in the live monitor: its
`[ ... ] && echo` idiom returns 1 on every quiet check and, under errexit
semantics, would have killed the monitor silently after the first HEAD move —
a dead monitor that looks armed. The monitor was stopped and re-armed
(task `bx6p88fvh`) with `if/fi` guards and `|| true` on the greps.

A second watcher waits for **STOP E to become executable** (foreign emulator
exiting, or free memory > 1.5 GB) and reports once, so the rules suite is run
at the first safe opportunity rather than on manual re-checks.
