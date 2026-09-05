# Known / accepted risks

Risks that were found, named, and then consciously carried — deferred, accepted-by-design, or blocked
on something outside the module. Each: the risk, the module, why it's open or accepted, and a
citation. This is not a TODO list of everything imperfect in the codebase; it's the set of things
someone already looked at and made an explicit call about.

---

### Examination module still runs on legacy RTDB, not the Firestore-canonical pattern
Every other migrated module (Fees, Accounting, Communication, Attendance, HR/payroll) is
Firestore-first; Examination still writes exam metadata to
`Schools/{schoolName}/{year}/Exams/{examId}` via the RTDB primitive. Formally classified as an
architectural finding, not a bug, and deliberately not remediated this cycle — "do NOT invest in
RTDB-aware validation infrastructure (would reinforce deprecated path)."
Module: Examination. Status: **open**, pending a dedicated Firestore-migration cycle.
Cited: `BUG_LEDGER.md` POLICY-FINDING-001, lines 1211–1225.

### Firestore rules don't check tenant lifecycle state — a suspended school's mobile sessions keep working
`firestore.rules` enforces `schoolId == request.auth.token.school_id` but has no
`get('schoolControl/{schoolId}')` check on `lifecycle.state`. A mobile user with a still-valid Firebase
Auth token (~1h TTL) can keep reading/writing after their school is suspended. The admin panel's login
gate and per-request gate are already hardened; only the rules layer and both apps' reactive-logout
listeners are the gap. A 3-phase fix (H1 rules gate → H2 Parent reactive logout → H3 Teacher reactive
logout) is designed but explicitly gated on an unrelated module (B2.3.2-FIX) reaching completion first
— deferred by operator decision, not forgotten.
Module: platform-wide (Firestore rules + both mobile apps). Status: **open**, three-phase fix
designed but not shipped.
Cited: `BUG_LEDGER.md` H-LIFECYCLE, lines 2042–2093.

### RTDB rule-tightening beyond "authenticated" is incomplete
The RTDB was hardened from fully world-open to `auth != null` (Stage 1.A), which closes anonymous
access but still leaves 14 catalogued CRITICAL/HIGH paths (admin credentials, backups, payment
records, API keys, RBAC roles) readable/writable by *any* authenticated user, not scoped by role or
tenant. Stage 2 (per-namespace authorization) and Stage 3 (full tenant isolation) are explicitly
sequenced as future, multi-week work.
Module: platform-wide RTDB. Status: **open**, Stage 1.A closed, Stage 2/3 not started.
Cited: `BUG_LEDGER.md` CARRY-013, lines 1644–1701, esp. "known follow-ons" at 1691–1694.

### `syncExamScheduleFull` still hardcodes the writer's current session
8 of 9 `Entity_firestore_sync.php` methods were fixed to prefer a caller-supplied session
(BUG-052); the 9th, `syncExamScheduleFull`, needs a signature change (its parameter is a typed list,
not a `$data` envelope) and was carried forward instead. Accepted because cross-session exam
scheduling isn't currently exercised by any live workflow.
Module: Examination / SIS session sync. Status: **deferred** (BUG-052-companion).
Cited: `BUG_LEDGER.md` lines 1906, 1933, 1958.

### Dormant RTDB reads left in the session pipeline on purpose
Three call sites still read from RTDB after the Firestore migration (a session-whitelist-miss
fallback in `MY_Controller.php`, a stale docblock reference in `School_config.php`, and dead code in
the Parent app's `AuthRepository`), all classified low-impact/low-frequency and deliberately bundled
into a future dedicated RTDB-elimination pass rather than fixed piecemeal — "defer all RTDB-removal
work for a dedicated hardening phase later" (operator decision).
Module: session/config pipeline (admin + Parent app). Status: **deferred by design**.
Cited: `BUG_LEDGER.md` TECH-DEBT-001/002/003, lines 1996–2033.

### `feeSettings` plaintext Razorpay secret is readable by any same-school actor; `students` is school-wide readable
Explicitly flagged as **known open, shared infrastructure, not fixed** during the Admission CRM
hardening pass — out of scope for that module because the exposure isn't CRM-specific.
Module: Fees / shared Firestore schema (surfaced during Admission CRM investigation). Status: **open**,
flagged not fixed.
Cited: `ADMISSION_CRM_MODULE_INVESTIGATION_AND_UAT.md` §1.7 "Known open (shared infra, NOT fixed —
flagged)".

### Admission CRM: several hardening items explicitly deferred
Enroll orphan-reorder (F4), full write atomicity, the Razorpay webhook path (blocked on needing
`webhook_secret`), the remainder of session-scoping (rest of F5), and removal of the dead
`online_form` path (F9) were all named and consciously left for a later pass alongside the items that
were fixed this session.
Module: Admission CRM. Status: **deferred**.
Cited: `ADMISSION_CRM_MODULE_INVESTIGATION_AND_UAT.md` §1.9 "Deferred".

### Red Flags: RTDB root is world-open; module can't fix it from inside the module
The deployed RTDB rules root is `auth != null` read+write across *all* tenants; a properly
schoolId-scoped `StudentFlags/{schoolCode}` rule exists only in a non-deployed file. Because RTDB
rules cascade, a child-level deny under a permissive root is a no-op — this can only be closed by a
platform-level RTDB rules refactor (which would also touch Chat/class-lists) plus a one-time
production RTDB purge of legacy flag data, both out of this module's scope.
Module: Red Flags (platform RTDB dependency). Status: **open**, tracked as a platform-level item, not
module-fixable.
Cited: `REDFLAGS_MODULE_INVESTIGATION_AND_UAT.md` §1.10/1.11 finding **F2**.

### Red Flags: rules deploy skipped to avoid shipping unrelated in-flight work
Owner-scoped moderation hardening and the parent tenant-gate for Red Flags are committed at HEAD but
the deploy was **skipped by decision** because `firestore.rules` also carried unrelated Notice
(X7/D7) work-in-progress at the time — deploying would have shipped someone else's half-finished
change along with this fix.
Module: Red Flags / Notice (shared rules file). Status: **open**, deploy deliberately deferred.
Cited: `REDFLAGS_MODULE_INVESTIGATION_AND_UAT.md` §1.11 finding **F4**.

### Red Flags: Teacher app's whole-school 60-day read doesn't scale
`observeFlagsForClass` reads *all* school flags for a 60-day window then filters to the teacher's
roster client-side — functional today, explicitly flagged as a performance risk that scales poorly
on large tenants, and deferred as "non-blocking."
Module: Red Flags (Teacher app). Status: **deferred**, tracked for a later perf pass.
Cited: `REDFLAGS_MODULE_INVESTIGATION_AND_UAT.md` §1.9, §1.10/1.11 finding **F6**.

### Staff Roles: no CAS on whole-map `staffRoles`/`departments` writes
Two admins editing different roles/departments concurrently can clobber each other's edit with no
error, the same shape as the school_config lock+CAS gap. Named as a known bug-prone area to probe in
UAT, not yet hardened.
Module: Staff & Roles. Status: **open**.
Cited: `STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.9, §1.10 finding **I2**.

### Staff Roles: department-id minting collision between two independent counters
`Staff_access::save_department` mints `DEPT_%04d` from max-existing-id, but never advances HR's
atomic claim-doc counter (`nextSchoolCounter('hr_Department')`). Once that counter is seeded, HR can
later re-mint the same id, causing an overwrite or an orphaned department. Confirmed but explicitly
deferred pending testing of the proper fix (Staff_access minting through the same atomic counter).
Module: Staff & Roles / HR. Status: **confirmed, deferred**.
Cited: `STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 finding **I4**.

### Staff Roles: Cloud Function fan-out is serial and misses snake_case `school_id` staff
The staff-capability Cloud Function does 2N reads + N writes serially (large-school timeout risk) and
queries staff by camelCase `schoolId` only, missing any staff doc that still carries only the legacy
snake_case `school_id` key. Both gated on a CF/rules deploy that hasn't happened.
Module: Staff & Roles (Cloud Function). Status: **deferred**, CF/deploy-gated.
Cited: `STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 finding **I9**.

### Staff Roles: rules-side "umbrella" permission expansion not implemented
PHP expands an umbrella grant (e.g. `Operations` → its child modules) but the Firestore rules'
`hasCapability` does not. Currently harmless because the affected children are web-surface-only and
ungated, but latent — the moment a child module gets an app-side rule of its own, this gap becomes
live.
Module: RBAC / Firestore rules. Status: **open, currently latent**.
Cited: `STAFF_ROLES_MODULE_INVESTIGATION_AND_UAT.md` §1.10 finding **I12**.

### Stories: 6 Cloud Functions + composite indexes not confirmed deployed to prod
If undeployed, scoped push silently never fires and the parent's story ring is silently empty — no
error surfaces anywhere. Named as the module's top risk and explicitly blocked on deploy permission,
not on missing code.
Module: Stories. Status: **open**, blocked on deploy.
Cited: `STORIES_MODULE_INVESTIGATION_AND_UAT.md` §8 finding **G1**.

### Stories: audience-key canonicalization is independently re-implemented in PHP, Kotlin, and JS
Verified byte-equivalent across all three today, but flagged as a standing maintenance risk precisely
because any future edit to one implementation and not the other two would silently reintroduce a
scoping mismatch, with no test currently wired to catch cross-language drift.
Module: Stories. Status: **accepted risk, currently correct**.
Cited: `STORIES_MODULE_INVESTIGATION_AND_UAT.md` §8 finding **G5**.

### Stories: rate-limit fail-open/fail-closed asymmetry is intentional but easy to forget
Teacher-app story posting fails **closed** on a Firestore/index error (blocks a legitimate post);
the admin panel's equivalent check fails **open** (lets an admin exceed the 10/day cap during a
Firestore blip). Both are explicitly by-design, not oversights — but the asymmetry itself is a fact a
future change could accidentally invert or "fix" without realizing it was deliberate.
Module: Stories. Status: **accepted by design**.
Cited: `STORIES_MODULE_INVESTIGATION_AND_UAT.md` §8 findings **G8** and **G9**.

### Certificate Designer: an abandoned in-flight proof still unlocks publish
`openProof()`'s handler schedules 5 chained timers and retains no timer ids; closing the modal cancels
nothing, so a cancelled proof still lands and sets the hash that gates `publish()`. Harmless against
today's mock proof generator, but recorded as a live defect that becomes real (a phantom-success class
bug) the moment `proof_pdf()` is wired to a real client — **recorded, not fixed**.
Module: Certificate Designer. Status: **open**.
Cited: `blueprints/certificates/TEST_DOSSIER.md` lines 280–285.

### Certificate Designer: Phase 9 hardening — 6 of 7 rows deliberately not counted as proven
Concurrency under real contention (P9.2 — proven only against a test double, not the Firestore
emulator with genuinely concurrent clients), resource caps as actual throwing paths under load (P9.3
beyond what real-render caps testing already closed), a metrics sink that lives outside this module
(P9.4), a restore drill (P9.5 — blocked on having no test school), and full human UAT (P9.7) are all
named as the honest remaining gap rather than claimed done.
Module: Certificate Designer. Status: **open**, explicitly the dossier's own "attack these first in
UAT" list.
Cited: `blueprints/certificates/TEST_DOSSIER.md` lines 205–214.

### Certificate Designer: 4 of 14 endpoints still stubs; module ships dormant
`create`, `validate`, `proof_pdf`, and `upload_asset` remain unwired as of the dossier's last update.
The whole module is currently not linked from any nav include and is capability-gated behind
`Certificates` — i.e. the exposure of the unfinished parts is intentionally zero until launch.
Module: Certificate Designer. Status: **open, but unlaunched — accepted for now**.
Cited: `blueprints/certificates/TEST_DOSSIER.md` lines 329, 343–344.

### Certificate Designer: no true Indic bold face; CBSE required-key list is human-unverified
The bundled Lohit font family ships Regular only — mPDF synthesizes bold, which will not match a true
Indic bold if a template needs one; a per-script bold source is only needed "if rejected" at UAT. The
CBSE Transfer Certificate's 19-of-22 required-key list is explicitly flagged `fieldListVerified:false`
and blocked on a human transcription/sign-off step, not on code.
Module: Certificate Designer. Status: **accepted pending UAT / human sign-off**.
Cited: `blueprints/certificates/TEST_DOSSIER.md` lines 335, 330.
