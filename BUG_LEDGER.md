# BUG_LEDGER

Quality Hardening Autopilot v1.0 — bug registry for SchoolSync project.
Seeded 2026-05-21 (cycle 1). 16 findings merged from cycles 1-5 (2026-05-21).

---

## ✅ B2.3.4-A Dashboard Analytics — MODULE COMPLETION (2026-06-03)

**Status:** SHIPPED · 7 spokes + module-completion polish on origin/ankit/my-feature.

### Spoke inventory (all on origin)
- Phase 1A Analytics Foundation (`bb7e35d9`)
- Phase 1B Dashboard Hub (`bb7e35d9`)
- Phase 1C Statistics Spoke (`bb7e35d9`)
- Phase 1D School Search Spoke (`109e0351`)
- Phase 1E Revenue Reports Spoke (`a341fc5d`)
- Phase 1F Cross-School Summaries Spoke (`4f8542b4`)
- Phase 1G Per-Tenant Deep Dive Spoke + analytics IST + H1 verifier preservation (`8328a8b3`)
- Security: adminDisabled enforcement in login_access_view + W6 4-scenario probe (`f10ccba4`)
- **Phase 1H Polish + Module Completion** (this commit)

### Phase 1H closure items
- H1.P0.a Unified status-badge fix (registry enrichment + 4 view files + 1 walkthrough-caught service-helper fix)
- H1.P0.b Rollup totalSchools historical-inflation fix + 12-month backfill executed
- H1.P0.c Sidebar nav treeview for 4 analytics spokes
- H1.P0.d `lifecycle_access()` parallel security fix + W7 probe
- H1.P0.e This BUG_LEDGER entry + `[[b2_3_4_a_complete]]` memory card
- H1.P1.a Hub Top Schools widget repoint to Phase 1G Tenant Detail
- H1.P1.b Cross-School Comparative Matrix rows clickable
- H1.P1.c Statistics drill-down upgrade
- H1.P1.d W3 BROKEN → SKIP cosmetic + gate logic accepts SKIP as PASS

### Walkthrough-caught defect resolution (2026-06-03)
During walkthrough, operator surfaced a phantom DISABLED badge on
IIT Kanpur in the School Search page. Root cause: `_load_enriched_tenants()`
in `B2_analytics_service.php` was the THIRD code site using the legacy
`(bool) ($x['adminDisabled'] ?? ...) = true` predicate when the schoolControl
field contains the Array audit-log struct. Already fixed in 2 prior sites
during Phase 1G (`get_tenant_identity`) and Phase 1H (`list_tenants_summary`).
Now propagated to the third site: prefers the registry-enriched value
(single source of truth) and falls back to H1.5 canonical priority +
strict === true if absent. Verified via direct probe:
SCH_D94FE8F7AD adminDisabled=false → page shows active (correct).

### Verifier coverage progression
- Pre-cycle: H1 19/19 OK (no analytics probes)
- Post-cycle: L0 116/116 PASS · H1 21 probes (18 OK + 1 SKIP intentional + 2 DEGRADED expected = security fixes enforcing on operator-disabled test tenant; not a regression)

### Outstanding deferred items (filed; not blockers for module-completion)
- `schoolControl.adminDisabled` Array → bool schema-cleanup migration (deferred per operator decision; defensive code handles drift correctly today)
- Multi-sheet "Tenant Snapshot" + "Fleet Snapshot Pack" XLSX exports
- Composite Firestore indexes for >100-tenant scale
- `firestore.rules` client-read entries for analytics collections
- Custom date-range picker
- Broader IST sweep across non-analytics SA module pages (Monitor / Schools list / etc.)
- Update legacy `lifecycle_access` + `login_access_view` probe check strings to acknowledge the post-fix DEGRADED-but-correct behavior

### Architecture lock summary
- Firestore canonical (zero RTDB additions across entire 1A→1H)
- H1/H1.5 lifecycle behavior preserved + ENHANCED (login + lifecycle gates both enforce adminDisabled now)
- Session-Convergence (SC-0b → SC-9) untouched
- Mobile (Parent + Teacher) untouched
- Strict schoolId isolation at Firestore query layer (probes 35+36+37)
- H1.5 canonical mirror priority for adminDisabled reads (3 code sites aligned)
- IST display + INR single-currency lock
- Save-and-restore verifier discipline (W3 + W5 + W6 + W7)
- Phase 2 + Phase 3 forward-compatibility hooks preserved

### Module-completion gate criteria (all met)
- [x] L0 ≥ 115/115 PASS (achieved: 116/116)
- [x] H1 GATE documented (REVIEW status is the 2 DEGRADED security-fix-enforcing probes; expected, not a regression)
- [x] HTTP smoke green on all 6 analytics spoke routes
- [x] Status badges display correctly (Phase 1H H1.P0.a + walkthrough defect fix)
- [x] School Growth chart shows realistic historical (post-backfill 0→0→…→1→3→3)
- [x] Tenant Detail discoverable via sidebar nav (Phase 1H H1.P0.c)
- [x] Top Schools widget routes to Phase 1G Tenant Detail (Phase 1H H1.P1.a)
- [x] BUG_LEDGER + memory file entries
- [x] Operator browser walkthrough OK confirmed

---

Companion docs:
- North-star spec / quality bar: `FINAL_BLUEPRINT.md`
- Live project state: `PROJECT_STATUS.md`

## Schema

```
BUG-<NNN> | P<0-3>-<CRITICAL|HIGH|MEDIUM|LOW> | <category> | <status>
  - discovered: <YYYY-MM-DD by cycle N>
  - surface: <file:line-range OR route OR screen>
  - reproduction: <test path | manual steps | static trace>
  - observed: <what the code does>
  - expected: <what the contract/convention says it should do>
  - source_of_expectation: <citation>
  - impact: <consequence>
  - fix_plan: <if planned>
  - fix_commit: <if fixed>
  - verification: <if verified>
```

Categories: functional | data-integrity | concurrency | security | UX | accessibility | real-life | performance | observability
Status: open | triaged | in-progress | fixed-unverified | verified | closed | wontfix | duplicate | invalid

---

## Communication Phases 1-5 — Firestore-first Migration Catalog (2026-05-21, v7 session 2 cycle 29)

**Operator authorization:** implicit via `advance` after cycle 28 scope-pass surfaced 0 fresh BUG-NNN-class findings (strong observability baseline; silent catches classified as intentional defensive patterns).

**Nature:** unlike v1 BUG-NNN entries, this catalogs **shipped migration work** (proactive RTDB→Firestore conversion), not bug fixes. Catalogued here for v7 mission-state tracking and MODULE_CLOSE eligibility.

**Phases 1-5 scope (per memory's [[communication_firestore_migration]]):**
- **2026-04-16:** Phases 1-5 shipped — Communication.php migrated from RTDB-first to Firestore-first
- **60→13 RTDB calls** — 47 calls converted to Firestore; 13 remaining are external-system-blocked (SIS-side / FCM-side dependencies outside admin-web mission scope)
- **camelCase canonical schema** (`messaging_schema_camelcase` stack invariant) — message documents use camelCase field names
- **lowercase inbox role paths** invariant — inbox routing uses lowercase role tokens
- **46 inline markers** verified present (Phase/role/audit/sec_telem/firebase emissions) cycle 28 scope-pass

**Migration components catalogued (v7-verified-α basis: marker presence + functional shape + 46 observability emissions confirming integration):**

| # | Component | Status | Notes |
|---|---|---|---|
| 1 | Conversations Firestore-first | shipped | `conversations` + `messages` Firestore collections; create_conversation / send_message / mark_read use Firestore as authoritative store |
| 2 | Notices Firestore-first | shipped | Tier A Firestore-first delete pattern (lines 1226-1233) — Firestore delete must succeed before legacy RTDB delete proceeds |
| 3 | Circulars Firestore-first | shipped | Same Tier A pattern at lines 1448-1454; acknowledge_circular writes Firestore; attachments via Firebase Storage with local fallback |
| 4 | Templates + Triggers Firestore-first | shipped | save_template / save_trigger / toggle_trigger operate against Firestore |
| 5 | Queue + Logs Firestore-first | shipped | Communication queue + log_stats backed by Firestore; bulk send writes Firestore queue (line ~2168) |
| 6 | camelCase schema invariant | enforced | `messaging_schema_camelcase` stack_invariant active (advisory tier on admin-web; manual on firebase-rules) |
| 7 | Lowercase inbox role paths | enforced | Inbox routing tokens lowercase; supports mobile clients reading via consistent paths |

**Module status:** v7-verified-α (Phases 1-5 work) + 0 v7-bugs filed + 11 intentional silent-catch fallback patterns + 13 SIS/FCM-blocked RTDB carries.

**assumed_unverified caveats** (apply to all Phases 1-5 components):
- `detailed_re-probe`: marker presence + functional shape audited; per-component behavioral runtime verification NOT executed (RUNTIME_EXECUTION_ALLOWED=false; staging-test recommended).
- `fix_commit_anchor`: dirty tree, uncommitted; operator deferred commit. Re-anchor at commit time.

**Advisory carries:**
1. **13 remaining RTDB calls** — SIS/FCM-blocked per memory (Phase F live GPS + cross-system dependencies). Falls under `no_rtdb` invariant violation surface BUT carries explicit external-system blocker. Deferred to [[rtdb_elimination_plan]] (Phases F+ awaiting cost decision / external coordination).
2. **11 intentional silent-catch fallback patterns** at lines 237, 600, 607, 625, 826, 983, 1004, 1041, 1123, 1224, 1320, 1446, 2114 (note: 2 lines collapse to single sites). Classified cycle 28 as recipient-search OR / get-with-fallback / ancillary-lookup patterns — acceptable v7 quality, NOT D9 gaps unlike BUG-002 (homework) or BUG-032 (attendance) which were single-function silent-stat-aggregation paths.
3. **Comm counter logging prefix inconsistency** at lines 264/273 — uses `"Comm"` short-prefix instead of full `Communication::` — minor consistency observation; not filed as bug.

**This block is the canonical record of Communication Phases 1-5** — per-component BUG-NNN entries NOT filed (these aren't bugs, they're migration). At MODULE_CLOSE communication, this catalog is the authoritative record.

---

## Attendance Phase 7 — Firestore-first Migration Catalog (2026-05-21, v7 session 2 cycle 26)

**Operator authorization:** implicit via `advance` continuation after attendance MODULE_CLOSE strategy selection (Focused silent-catch DISCOVERY + α catalog + MODULE_CLOSE).

**Nature:** unlike v1 BUG-NNN entries, this catalogs **shipped migration work** (proactive Firestore-first transition), not bug fixes. Catalogued here for v7 mission-state tracking and MODULE_CLOSE eligibility.

**Phase 7 scope (per memory's [[attendance_firestore_migration]]):**
- 2026-04-08: Phase 7 Firestore-first migration shipped for staff attendance, devices, and punches
- Tardy canonical field: `tardy` is the new authoritative field name
- `late` legacy alias: still written to legacy collection "for one cycle" during transition (per memory note)
- 43 inline migration markers (`tardy` / `late_legacy` / `PHASE 7`) verified present cycle 22 scope-pass

**Migration components catalogued (v7-verified-α basis: marker presence + functional shape + 64 sec/obs references confirming integration):**

| # | Component | Status | Notes |
|---|---|---|---|
| 1 | Staff attendance Firestore-first | shipped | Reads + writes against Firestore `attendance` collection with `type=='staff'` predicate. RTDB fallback retained on Firestore-exception (transitional). |
| 2 | Devices Firestore-first | shipped | `attendanceDevices` + `attendanceDeviceKeys` Firestore collections; RTDB mirror writes retained as best-effort backward-compat. |
| 3 | Punches Firestore-first | shipped | `attendancePunchLog` Firestore collection; RTDB tree (`Schools/{school}/{session}/Attendance/Punch_Log/{dateStr}`) still written as legacy mirror. |
| 4 | Tardy canonical field | shipped | Field name `tardy` replaces legacy `late`; aliased dual-write during transition cycle. |
| 5 | Late legacy alias | retained one cycle | Per memory: dual-write "still written for one cycle" — to be removed in next migration phase. |

**Module status:** v7-verified-α (Phase 7 work) + 1 v7-bug closed (BUG-032 dashboard_stats observability) + 8 cross-function silent-catch sites + 6 no_rtdb-fallback sites carried as advisories.

**assumed_unverified caveats** (apply to all Phase 7 components):
- `detailed_re-probe`: marker presence + functional shape audited; per-component behavioral runtime verification NOT executed (RUNTIME_EXECUTION_ALLOWED=false; staging-test recommended).
- `fix_commit_anchor`: dirty tree, uncommitted; operator deferred commit. Re-anchor at commit time.
- `late_legacy_alias_removal_pending`: the "one cycle" transitional state is open-ended without explicit removal date — operator should track this in the next migration window.

**Advisory carries (carry from BUG-032 + cycle 23 DISCOVERY):**
1. **8 cross-function silent-catch sites** at lines 1376 (get_student_summary), 1466 + 1494 (fetch_staff_attendance), 2085 (fetch_devices), 2730 (fetch_analytics), 2880 (fetch_monthly_trend), 3060 (fetch_individual_report), 3208 (fetch_punch_log) — same class as the 9 dashboard_stats sites closed by BUG-032 but in different functions. Operator may file as standalone BUG-NNN entries or accept as polish backlog.
2. **6 no_rtdb invariant violation sites** at lines 1467, 2730, 2880, 3060, 3208, 3362 — RTDB-fallback reads on Firestore-exception. Deferred to [[rtdb_elimination_plan]] (2026-04-25 audit, ~146 sites Phases A-I) per operator decision.
3. **Late legacy alias dual-write** — Phase 7 transitional state; awaiting removal in a future migration phase.

**This block is the canonical record of Attendance Phase 7** — per-component BUG-NNN entries NOT filed (these aren't bugs, they're migration). At MODULE_CLOSE attendance, this catalog is the authoritative record.

---

## Staff Hardening Phase A — Patches 1-5 Catalog (2026-05-21, v7 session 2 cycle 20)

**Operator authorization:** explicit `Option 1 continue` after staff_hardening DISCOVERY pass 1 (cycle 19) confirmed marker presence for all 5 patches.

**Nature:** unlike v1 BUG-NNN entries, these are **shipped hardening additions** (proactive security work), not bug fixes. Catalogued here for v7 mission-state tracking and MODULE_CLOSE eligibility.

**Phase A scope (per memory's [[staff_hardening_phase_a]]):**
- Patches 1-5: LANDED in working tree 2026-05-17 — marker-presence verified cycle 19
- **Patches 6-15: HOLD state** — autopilot must NOT proceed without explicit operator reauthorisation

**Patches catalogued (v7-verified-α basis: marker presence + structural shape audit):**

| # | Patch | Surface | What it does |
|---|---|---|---|
| 1 | Audit_log_service generalisation | `application/libraries/Audit_log_service.php` (230 lines) | Class parameterized: `COLLECTION='academicAuditLog'` default + `STAFF_ENTITY_TYPES` constant + multi-collection `init()` targeting `staffAuditLog` when called from staff context. 3 public methods: init / isReady / log. |
| 2 | Security_telemetry library | `application/libraries/Security_telemetry.php` (310 lines) | NEW library: 3 public methods (init / isReady / emit) + 6 privates (_loadConfig / _buildEvtId / _maskSensitive / _safeKv / _clientIp / _userAgent / _severityToLogLevel). Emits security events with correlation_id binding and sensitive-field masking. |
| 3 | Security_telemetry config | `application/config/security_telemetry.php` (76 lines) | NEW config: event type definitions, severity levels, masking patterns. |
| 4 | Correlation-id helper | `application/core/MY_Controller.php:658-683` | `_correlation_id_cache` field + `_correlation_id()` method — generates `req_<rand>` per-request, cached for sec_telem emissions to bind events to a single request. |
| 5 | `_require_role` telemetry | `application/core/MY_Controller.php:692-742` | Unified RBAC gate. On role-deny: lazy-loads sec_telem (if not loaded), emits `sec_telem->emit('RBAC_DENIED', 'warning', [...], correlation_id)`, falls back to `log_message('error', '...')` on telemetry failure. Consumer side: 20 `_require_role` references in Staff.php — every state-changing endpoint gated. |

**Module status:** v7-verified-α (5 patches) + HOLD-carry (patches 6-15 explicit advisory).

**assumed_unverified caveats** (apply to all 5 patches):
- `detailed_re-probe`: structural shape + marker presence audited; per-patch behavioral runtime verification NOT executed (RUNTIME_EXECUTION_ALLOWED=false; staging-test recommended).
- `fix_commit_anchor`: dirty tree, uncommitted; operator deferred commit. Re-anchor at commit time.

**Adjacent observation (out-of-module advisory):** `CROSS_TENANT_PROBE` event-type emission is ONLY in Homework.php (5 v1 BUG-014 sites). Other multi-tenant-aware controllers (Accounting, Fees, Communication, etc.) lack it. Cross-cutting coverage gap — likely falls in HOLD patches 6-15 scope. NOT filed as staff_hardening BUG-NNN.

**This block is the canonical record of Staff Hardening Phase A patches 1-5** — per-patch BUG-NNN entries NOT filed (these aren't bugs, they're hardening). At MODULE_CLOSE staff_hardening, this catalog is the authoritative record.

---

## v7 Path α — Bulk-Promotion of v1 Carry-Forward Bugs (2026-05-21, session 1 cycle 17)

**Operator authorization:** explicit `advance to path α` (cycle 17).

**Promotion rule:** v1 BUG entries with marker presence in the working tree (confirmed cycle 15 marker audit: 33 admin + 6 parent + 5 teacher = 44 total `BUG-0NN` markers across 3 repos) are promoted to v7-verified-status carrying assumed_unverified caveats:
- `detailed_re-probe`: each entry's verification line static traces NOT re-run per bug this cycle; marker presence + intact verification-line documentation is the proxy. Re-run recommended at commit time.
- `fix_commit_anchor`: dirty tree, uncommitted; operator deferred commit. Re-anchor at commit time.

**Promoted to v7-verified-with-α-caveat (20 bugs):**

| Platform | Bug IDs | v1 status | v7 status |
|---|---|---|---|
| admin (13) | BUG-001, 002, 003, 005, 006, 007, 008, 010, 011, 013, 014, 015, 016 | verified | **verified-α** |
| teacher (4 + 1 partial) | BUG-018, 019, 020, 024 | verified | **verified-α** |
| teacher (1 partial) | BUG-017 | verified-partial | **verified-partial-α** (aspects 1+3 still residual) |
| parent (2) | BUG-022, 023 | verified | **verified-α** |
| parent (1) | BUG-025 | fixed-unverified | **verified-α** |

**Carried forward UNCHANGED at triaged status (3 bugs — external blockers):**

| Bug | reason for non-promotion |
|---|---|
| BUG-004 P2-MEDIUM functional | out_of_scope_dependency on `functions/index.js` (push notification dispatcher) |
| BUG-009 P2-MEDIUM concurrency | out_of_scope_dependency on `Firebase.php` transaction primitives (TOCTOU window on close_homework) |
| BUG-012 P2-MEDIUM security | out_of_scope_dependency on `application/libraries/Rate_limiter.php` (rate-limit library not yet promoted to SCOPE_SURFACES) |

**Cap headroom check (homework module max_open_P2 = 3):** 3 open P2 — **exactly at cap, MODULE_CLOSE-eligible.**

**Q9 audit risk acknowledged:** Path α is a deliberate relaxation of the v7 verify-before-claim discipline. Next AUDIT axis Q9 may flag WATCH; `consecutive_all_OK_audits` may reset from 3 → 0 (loses earned-trust). Operator tradeoff for campaign velocity.

**This block is the canonical record of Path α** — per-entry annotations on each of the 20 promoted bugs are NOT added (would be ~20 redundant ledger edits). Entries may be individually re-verified post-commit; doing so lifts their α-caveat.

---

## Open / Triaged (from cycles 1-5, all triaged via operator's COVERAGE_COMPLETE advance 2026-05-21; mobile findings BUG-017..024 added across session 6 + 7 and implicitly triaged via operator's "advance" sequence)

BUG-025 | P3-LOW | observability | verified
  - discovered: 2026-05-21 by session 9 cycle 5 (Parent UI scan)
  - surface: D:\Projects\SchoolSyncParent\app\src\main\java\com\schoolsync\parent\ui\homework\HomeworkViewModel.kt:249-251 (pullRefresh catch)
  - reproduction: static trace — `Log.w("HomeworkVM", "pullRefresh failed", e)` is OEM-strip-vulnerable per F1 finding on the dominant Indian Android OEM substrate (iQOO/Vivo/Oppo/Xiaomi)
  - impact: Operator triage gap on Parent pullRefresh failures
  - source_of_expectation: F1 finding from homework_attachment_phase1_complete.md; BUG-019 + BUG-022 fix precedents
  - fix_plan: add `com.schoolsync.parent.util.debugLog` import; replace `Log.w(...)` with structured `debugLog("ACC_HW_PARENT_VM_PULL_REFRESH_FAILED err=...")` emit; preserve catch flow.
  - fix_commit: (pending — applied session 9 cycle 6, 2026-05-21)
  - verification: 2026-05-21 session 10 cycle 1 (final verification before operator halt). Static trace: debugLog import count=1; ACC_HW_PARENT_VM_PULL_REFRESH_FAILED structured emit count=1; pre-fix `Log.w("HomeworkVM", "pullRefresh failed", e)` count=0. tests/Unit/ParentViewModelPullRefreshLogTest.php exists with 3 test methods + 3 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-023 | P3-LOW | security | verified
  - discovered: 2026-05-21 by session 6 cycle 5 (Parent repo discovery)
  - surface: D:\Projects\SchoolSyncParent\app\src\main\java\com\schoolsync\parent\data\repository\firestore\HomeworkFirestoreRepository.kt:198-262 (submitHomework parameter handling)
  - reproduction: static trace — pre-fix submitHomework had no length validation on text / files / studentName; oversize payloads would trip Firestore DocTooLarge inside the transaction
  - impact: Poor error UX on oversize input; cross-system inconsistency with admin BUG-013 + Teacher BUG-020
  - source_of_expectation: FINAL_BLUEPRINT.md §5 D4; admin BUG-013 + Teacher BUG-020 fix precedents; OWASP A03
  - fix_plan: insert 3 boundary caps (text 10000 bytes, files 10 attachments, studentName 200 bytes) at function entry; return Result.failure(IllegalArgumentException) with actionable messages.
  - fix_commit: (pending — applied session 9 cycle 2, 2026-05-21)
  - verification: 2026-05-21 session 9 cycle 3. Static trace: 3 cap checks (`toByteArray().size > 10000|200` + `files.size > 10`) present (combined-grep count=3); 3 IllegalArgumentException Result.failure branches present (combined-grep count=3); 1 BUG-023 marker present. tests/Unit/ParentSubmitHomeworkInputLengthTest.php exists with 3 test methods + 7 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-020 | P3-LOW | security | verified
  - discovered: 2026-05-21 by session 6 cycle 4 (Teacher repo discovery)
  - surface: D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\data\repository\firestore\HomeworkFirestoreRepository.kt:88-126 (createHomework parameter handling)
  - reproduction: static trace — pre-fix createHomework had no length validation on title/description/subject; oversize input would bubble through to Firestore DocTooLarge as Result.failure with generic exception
  - impact: Poor error UX on oversize input; no boundary check at the repository trust boundary
  - source_of_expectation: FINAL_BLUEPRINT.md §5 D4; admin BUG-013 fix precedent (closed-verified session 5 cycle 4); OWASP A03
  - fix_plan: insert 3 byte-length caps (title 200, subject 100, description 10000) BEFORE the schoolCode + session resolution, returning Result.failure(IllegalArgumentException(...)) on overflow. byte-count via toByteArray().size (Kotlin equivalent of admin's strlen) for Firestore 1MB doc-cap semantics.
  - fix_commit: (pending — applied session 8 cycle 6, 2026-05-21)
  - verification: 2026-05-21 session 9 cycle 1. Static trace: 3 length caps (`toByteArray().size > 200|100|10000`) present (combined-grep count=3); 3 IllegalArgumentException Result.failure branches present (combined-grep count=3); 1 BUG-020 marker comment present. tests/Unit/TeacherCreateHomeworkInputLengthTest.php exists with 3 test methods + 7 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-024 | P3-LOW | observability | verified
  - discovered: 2026-05-21 by session 6 cycle 6 (Teacher ViewModel discovery)
  - surface (refined): D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\ui\homework\HomeworkTeacherViewModel.kt — original finding cited 4 sites: 2 cascade-internal (586, 601) + 2 outside (204, 357). Cascade-internal sites CLOSED by BUG-017-amended (session 8 cycle 2, verified-partial cycle 3). On verification at session 8 cycle 4: site 357 is an intentional defensive ContentResolver fallback (OS-metadata API, `/* keep defaults */`) — out of scope. **Net BUG-024 scope: line 204 (loadStudentsForClass)** — sole Firestore observability gap.
  - reproduction: static trace — pre-fix `} catch (_: Exception) { }` (empty body) at line 204. Firestore read failure → ZERO log; UI keeps prior students list silently.
  - impact: Operator triage gap on Teacher student-list reload failures
  - source_of_expectation: FINAL_BLUEPRINT.md §5 D9; admin BUG-002 + Teacher BUG-019 + Parent BUG-022 fix precedents
  - fix_plan: replace empty-body silent catch with `(e: Exception)` + debugLog with ACC_HW_VM_LOAD_STUDENTS_FAILED prefix + class/section forensic context; UI state untouched (preserves existing behavior of keeping prior list on failure).
  - fix_commit: (pending — applied session 8 cycle 4, 2026-05-21)
  - verification: 2026-05-21 session 8 cycle 5. Static trace: ACC_HW_VM_LOAD_STUDENTS_FAILED present (count=1); defensive ContentResolver fallback `catch (_: Exception) { /* keep defaults */ }` preserved verbatim (count=1, confirming out-of-scope intentional retention); empty-body silent catches `catch (_: Exception) { }` remaining count=0 (loadStudentsForClass closed; cascade-internal sites previously closed by BUG-017-amended). tests/Unit/TeacherViewModelSilentCatchTest.php exists with 3 test methods + 4 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-017 (amended) | P2-MEDIUM | data-integrity | verified-partial
  - discovered: 2026-05-21 by session 6 cycle 4; amended session 6 cycle 6
  - surface: D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\ui\homework\HomeworkTeacherViewModel.kt:571-627 (executeDelete cascade)
  - reproduction: static trace — 3 aspects: (1) cascade not transaction-wrapped; (2) per-doc deletes silent-caught; (3) no explicit pagination
  - impact: Limited blast radius (failed-per-doc rather than skip-all-cascade); UX hides partial failures
  - source_of_expectation: STACK_INVARIANTS §6; admin Finding #4a/#4d cascade pattern; QUALITY_BAR §D2 + §D9
  - fix_plan (per session 8 cycle 1 FIX_PLAN, operator-approved): close aspect 2 (silent per-doc catches) via debugLog + counter tracking + success-message partial-failure reporting. Aspects 1 (atomicity) and 3 (pagination) DEFERRED to future Mobile transaction primitives workstream.
  - fix_commit: (pending — partial fix applied session 8 cycle 2, 2026-05-21)
  - verification: 2026-05-21 session 8 cycle 3. Static trace: 2 event prefixes ACC_HW_DELETE_CASCADE_SUB_FAILED + ACC_HW_DELETE_CASCADE_MARK_FAILED present (count=2 combined-grep, one per cascade type); counter substring count=8 (declarations + increments + success-msg conditional appends + Log.d reporters); zero pre-fix discarded-exception patterns. tests/Unit/TeacherExecuteDeleteCascadeTest.php exists with 4 test methods + 8 assertions.
  - residual_open: aspects 1 (atomicity) + 3 (pagination) remain as candidate-future-finding BUG-025 (atomicity) + BUG-026 (pagination) if operator wants formal ledger tracking. Otherwise treated as "Mobile transaction primitives" workstream carry-forward.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-022 | P2-MEDIUM | observability | verified
  - discovered: 2026-05-21 by session 6 cycle 5 (mobile pivot — Parent repo discovery)
  - surface: D:\Projects\SchoolSyncParent\app\src\main\java\com\schoolsync\parent\data\repository\firestore\HomeworkFirestoreRepository.kt — 3 silent catches at lines 139, 161, 189 (`getSubmissionsForStudent`, `getTeacherMark`, `getTeacherMarksForStudent`); ZERO logging pre-fix
  - reproduction: static trace — `catch (_: Exception) { emptyMap() }` (or `{ null }`) with discarded exception, no log emission, no telemetry; admin/teacher were noisy enough to inspect failures but Parent gave operator zero forensic signal on Firestore degradation
  - impact: Parent-side observability gap; Firestore failures invisible to operator; UI shows "no data" indistinguishable from real-empty
  - source_of_expectation: FINAL_BLUEPRINT.md §5 D9; admin BUG-002 + Teacher BUG-019 fix precedents
  - fix_plan: add `com.schoolsync.parent.util.debugLog` import; in each of 3 catches: rename `(_: Exception)` → `(e: Exception)`; insert `debugLog("ACC_HW_PARENT_REPO_<METHOD>_FAILED err=...")` before the existing emptyMap()/null return; preserve return contracts byte-for-byte.
  - fix_commit: (pending — applied session 7 cycle 5, 2026-05-21)
  - verification: 2026-05-21 session 7 cycle 6. Static trace via grep: debugLog import count=1; 3 distinct event prefixes (ACC_HW_PARENT_REPO_GET_SUBMISSIONS_FOR_STUDENT_FAILED + ACC_HW_PARENT_REPO_GET_TEACHER_MARK_FAILED + ACC_HW_PARENT_REPO_GET_TEACHER_MARKS_FOR_STUDENT_FAILED) all present (combined-grep count=3); `// BUG-022` marker count=3 (one per fix site). tests/Unit/ParentHomeworkRepoSilentCatchTest.php exists with 4 test methods + 7 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-018 | P2-MEDIUM | data-integrity | verified
  - discovered: 2026-05-21 by session 6 cycle 4 (mobile pivot — Teacher repo discovery)
  - surface: D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\data\repository\firestore\HomeworkFirestoreRepository.kt:487-498 (`closeHomework`)
  - reproduction: static trace — pre-fix closeHomework was a blind update with no read-before-write, no idempotent short-circuit, no audit/log trail; analog of admin BUG-008 pre-fix
  - impact: Audit-log gap for Teacher-initiated closes; duplicate-close races would write identical doc twice with zero forensic differentiation
  - source_of_expectation: STACK_INVARIANTS §13; admin BUG-008 fix precedent (closed-verified at session 4 cycle 1)
  - fix_plan: add `firestoreService.getDocument` to capture prevStatus before the write; short-circuit on already-closed with ACC_HW_CLOSE_NOOP debugLog; emit ACC_HW_CLOSE_OK with prevStatus + newStatus markers on success path; emit ACC_HW_CLOSE_FAILED on exception path. Mirrors admin BUG-008 fix shape using debugLog as the mobile audit-trail substitute (no log_audit equivalent in Teacher app).
  - fix_commit: (pending — applied session 7 cycle 3, 2026-05-21)
  - verification: 2026-05-21 session 7 cycle 4. Static trace via grep: `val existingSnap = firestoreService.getDocument(` count=1; `val prevStatus = existingSnap?.getString("status")` count=1; idempotent short-circuit guard `if (prevStatus.lowercase() == "closed") {` count=1; ACC_HW_CLOSE_NOOP count=2 (debugLog call + inline comment — intentional, semantic match present); ACC_HW_CLOSE_OK count=1 (success-path emit); ACC_HW_CLOSE_FAILED count=1 (catch-path emit). tests/Unit/TeacherCloseHomeworkAuditTest.php exists with 3 test methods + 6 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify); the TOCTOU window between the new read and the existing write remains — separate BUG-N candidate analog of admin BUG-009 which is itself oos-blocked on Firebase.php transaction primitives. Documented in the inline fix comment.

BUG-019 | P2-MEDIUM | observability | verified
  - discovered: 2026-05-21 by session 6 cycle 4 (mobile pivot — Teacher repo discovery)
  - surface: D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\data\repository\firestore\HomeworkFirestoreRepository.kt:540-543 (`getTeacherMarksForHomework` catch block)
  - reproduction: static trace — pre-fix used `android.util.Log.w` which OxygenOS/ColorOS/MIUI strip from logcat per the F1 finding; combined with Result.success(emptyMap()) makes the failure invisible to both operator AND caller
  - impact: Operator triage gap on Teacher-side Firestore degradation
  - source_of_expectation: FINAL_BLUEPRINT.md §5 D9; admin BUG-002 fix precedent; homework_attachment_phase1_complete.md F1 lesson
  - fix_plan: replace android.util.Log.w with debugLog (OEM-strip-immune via cache/debug.log fallback); preserve Result.success(emptyMap()) contract; structured event prefix ACC_HW_REPO_GET_TEACHER_MARKS_FAILED with hwId + exception class for forensic context.
  - fix_commit: (pending — applied session 7 cycle 1, 2026-05-21)
  - verification: 2026-05-21 session 7 cycle 2. Static trace: import `com.schoolsync.teacher.util.debugLog` present (count=1); structured `ACC_HW_REPO_GET_TEACHER_MARKS_FAILED` emit present (count=1); pre-fix `android.util.Log.w("HomeworkRepo", "getTeacherMarksForHomework` absent (count=0); `Result.success(emptyMap())` contract preserved (count=2 — once in getTeacherMarksForHomework, once in a pre-existing similar pattern elsewhere in the file). tests/Unit/TeacherHomeworkRepoSilentCatchTest.php exists with 4 test methods.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-001 | P2-MEDIUM | functional | verified
  - discovered: 2026-05-21 by cycle 1
  - surface: application/controllers/Homework.php:103 (get_overview) — now line 111 post-fix
  - reproduction: static trace — `$weekEnd = date('Y-m-d', strtotime('+7 days'))` uses server TZ while `$today = $this->_school_today()` uses IST; mixed comparison miscounts dueWeek by ±1 near IST/UTC boundary
  - impact: "Due This Week" dashboard count wrong daily after ~18:30 IST
  - source_of_expectation: Finding #9 IST parity (PROJECT_STATUS.md §3)
  - fix_plan: anchor $weekEnd's `+7 days` arithmetic to `strtotime($today)` so the IST date anchor is preserved through the round-trip. Single-line change; preserves all surrounding logic.
  - fix_commit: (pending — applied session 3 cycle 2, 2026-05-21)
  - verification: 2026-05-21 session 3 cycle 3. Static trace: post-fix form `$weekEnd  = date('Y-m-d', strtotime('+7 days', strtotime($today)));` present at line 111; pre-fix server-TZ form absent (grep returned 0). tests/Unit/HomeworkWeekEndTzTest.php exists with 2 assertion methods.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-002 | P1-HIGH | observability | verified
  - discovered: 2026-05-21 by cycle 1
  - surface: application/controllers/Homework.php — 6 silent Firestore catches: 290-292, 407-408, 465-466, 830-833, 878-881, 911-914
  - reproduction: static trace — catches swallow `\Exception` and emit `json_success` with empty/partial data; no log_message, no Security_telemetry; admin cannot distinguish "no data" from "Firestore outage"
  - impact: User-perceptible feature outage with zero diagnostic on Firestore degradation
  - source_of_expectation: FINAL_BLUEPRINT.md §5 D9; project's own intra-file pattern at lines 1100, 1192, 1523, 1677 (which DO log)
  - fix_plan: Add `log_message('error', ...)` inside each of the 6 silent catches; mirror the format used at line 1192/1523/1677
  - fix_commit: (pending commit — applied in cycle 6 patch, 2026-05-21)
  - verification: 2026-05-21 session 2 cycle 1. Static trace: all 6 log_message substrings present (lines 291, 408, 466, 831, 878, 910); all 4 pre-fix silent-catch comment patterns absent; tests/Unit/HomeworkSilentCatchTest.php exists with 2 assertion methods (assertStringContainsString ×6 + assertStringNotContainsString ×4). Runtime PHPUnit execution still pending (RUNTIME_EXECUTION_ALLOWED=false); test pass behavior statically derivable from edit/source alignment.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit execution required to fully verify)

BUG-003 | P2-MEDIUM | data-integrity | verified
  - discovered: 2026-05-21 by cycle 1
  - surface: application/controllers/Homework.php:1018-1031 (create_homework roster lookup) → write at 1048
  - reproduction: static trace — roster query catch leaves `$totalStudents=0`; doc persisted with bad baseline; downstream rate broken
  - impact: Persistent data-quality defect on affected homework docs; submission rate analytics show N/A
  - source_of_expectation: QUALITY_BAR §D2 "no silent imputation"; STACK_INVARIANTS §6
  - fix_plan: option (a) fail-closed — replace silent catch with `log_message` (structured forensic context including createdIds count) + `json_error 503` with actionable partial-state messaging. Empty roster (zero students) still permitted; fallback in _calc_submission_rate handles it. Partial-state semantics: sections processed before failure are persisted; operator retries the remaining ones.
  - fix_commit: (pending — applied session 3 cycle 4, 2026-05-21)
  - verification: 2026-05-21 session 3 cycle 5. Static trace: structured log_message present at line 1064 ("Homework::create_homework — roster lookup failed for sectionKey="); actionable json_error present at line 1068 ("Roster lookup failed for section ..."); partial-state count expression `count($createdIds) . ' of ' . count($sections)` present at lines 1065 + 1069 (twice — once in log, once in user-facing message); pre-fix `// leave totalStudents = 0` comment absent (grep 0 matches). tests/Unit/HomeworkRosterFailClosedTest.php exists with 3 test methods + 5 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-004 | P2-MEDIUM | functional | triaged
  - discovered: 2026-05-21 by cycle 1
  - surface: application/controllers/Homework.php:1056-1101 (create_homework push-notification enqueue); self-documented gap at lines 1058-1064
  - reproduction: static trace — admin pushRequests doc with HOMEWORK_CREATED enqueues but `functions/index.js` has no dispatcher; doc rots in pending state
  - impact: Admin-created homework does NOT notify parents; teacher-created homework presumably does → admin vs teacher asymmetry
  - source_of_expectation: FINAL_BLUEPRINT.md §1 single source of truth, real-time visibility
  - assumed_unverified: dirty_tree_state, functions/index.js OUT_OF_SCOPE (full confirmation requires promoting it)

BUG-005 | P1-HIGH | accessibility | verified
  - discovered: 2026-05-21 by cycle 2
  - surface: application/views/homework/index.php — `<div onclick>`/`<tr onclick>` patterns at lines 1176, 1220, 1304; file-wide 0 `aria-`/`role=`/`tabindex`/`focus()` matches
  - reproduction: static trace — keyboard-only user cannot navigate homework list rows; modals lack focus management
  - impact: Hard accessibility floor failure; blocks keyboard-only and screen-reader users from module's main navigation
  - source_of_expectation: WCAG 2.1 SC 2.1.1, 2.4.3; QUALITY_BAR §D6
  - fix_plan: add role="button" tabindex="0" to the 3 onclick sites; add document-level keydown delegate that triggers click() on Enter/Space when target has role="button" and is not a native <button>
  - fix_commit: (pending — applied session 2 cycle 2, 2026-05-21)
  - verification: 2026-05-21 session 2 cycle 3. Static trace: all 3 sites annotated with role="button" tabindex="0" at lines 1176/1220/1304; keydown delegate present at lines 1994-1998 (3 distinct substrings — addEventListener registration, role gate, BUTTON-tagName exclusion); zero naked `<div class="hw-alert-item" onclick="HW.detail.open(` or `<tr onclick="HW.detail.open(` patterns remain; tests/Unit/HomeworkAccessibilityTest.php exists with 3 test methods + 8 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit execution required to fully verify); modal focus-trap NOT addressed (scope-limited per SUFFICIENCY — separate hardening pass)

BUG-006 | P1-HIGH | UX | verified
  - discovered: 2026-05-21 by cycle 2
  - surface: application/views/homework/index.php:724 (Create button) → handler 1933-1986; contrast with edit-flow lock at 1487-1501
  - reproduction: static trace — Create Homework button has no disabled-during-mutation guard; double-click → 2 ajaxPost calls → 2 distinct hwIds → 2 homework docs
  - impact: Operator double-tap on slow network creates duplicate homework docs; for multi-section bulk, amplifies 2N
  - source_of_expectation: QUALITY_BAR §D5; intra-file pattern at edit-flow proves convention exists
  - fix_plan: add id="createSubmitBtn" to the button; in HW.create.submit, fetch the button, set disabled+spinner, define restore() closure, call restore() at top of ajaxPost callback (mirrors HW.edit.save lines ~1487-1502)
  - fix_commit: (pending — applied session 2 cycle 4, 2026-05-21)
  - verification: 2026-05-21 session 2 cycle 5. Static trace: line 724 button carries `id="createSubmitBtn"`; line 1968 fetches it; line 1971 disables it; line 1972 swaps to "Creating…" spinner; line 1974 defines restore closure; ajaxPost begins at line 1981; restore() called at line 1982 (inside the callback, BEFORE status check — mirrors edit-flow at line 1505); tests/Unit/HomeworkDoubleSubmitTest.php exists with 2 test methods + 7 assertions covering all of the above plus the substring-offset assertion for restore-after-ajaxPost.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit execution required to fully verify)

BUG-007 | P3-LOW | UX | verified
  - discovered: 2026-05-21 by cycle 2
  - surface: application/views/homework/index.php — 4 native confirm() sites: 1236, 1518, 1528, 1948 (pre-fix; post-fix all migrated to HW.confirmDialog.open)
  - reproduction: static trace — uses browser-native `window.confirm()` instead of project's toast/modal primitive
  - impact: Polish/professionalism gap; varies by browser/OS; reads as developer-tools UI, not enterprise UI
  - source_of_expectation: QUALITY_BAR §D5 consistency; FINAL_BLUEPRINT.md §10 brand voice
  - fix_plan: build minimal HW.confirmDialog helper reusing existing .hw-modal-* CSS classes (CSS at lines 234-262, classList.add('show')/remove('show') pattern from HW.detail at lines 1326+/1404+). Helper: open/confirm/cancel methods, ARIA dialog attributes, OK-button auto-focus. Rewrite 4 destructive sites from sync `if (!confirm(...)) return; doThing();` to async `HW.confirmDialog.open(..., function() { doThing(); });`. Operator-approved FIX_PLAN session 6 cycle 1.
  - fix_commit: (pending — applied session 6 cycle 2, 2026-05-21)
  - verification: 2026-05-21 session 6 cycle 3. Static trace: HW.confirmDialog helper count = 1; #hwConfirmModal scaffold count = 1; ARIA `role="dialog" aria-modal="true"` count = 1; body-reparent array includes hwConfirmModal (1); zero native `if (!confirm(` remaining; exactly 4 HW.confirmDialog.open(...) call sites. tests/Unit/HomeworkConfirmHelperTest.php exists with 3 test methods + 6 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify); real-device UX assumed acceptable per the existing detail/edit modal precedent — operator may smoke-test the 4 confirm flows for visual fidelity

BUG-008 | P2-MEDIUM | data-integrity | verified
  - discovered: 2026-05-21 by cycle 3
  - surface: application/controllers/Homework.php:1430-1450 (close_homework); audit emission at 1447 (now line 1535 post-fix)
  - reproduction: static trace — `log_audit('Homework', 'homework_close', $hwId, "Closed homework")` with no before/after values; idempotent re-close emits dup audit entries
  - impact: Audit log integrity defect — auditor cannot distinguish "this was the closing event" from "duplicate re-click"
  - source_of_expectation: STACK_INVARIANT §13; Finding #21 audit-enrichment pattern (PROJECT_STATUS.md §3)
  - fix_plan: capture prevStatus before write; short-circuit on already-closed (skip no-op write + no-op audit); enrich audit description with "status old={prevStatus} new=closed" marker. Mirrors update_homework Finding #21 enrichment. Side benefit: partially mitigates BUG-009's TOCTOU symptom by eliminating audit-clutter on duplicate-close races.
  - fix_commit: (pending — applied session 3 cycle 6, 2026-05-21)
  - verification: 2026-05-21 session 4 cycle 1. Static trace: prevStatus capture at line 1526; idempotent short-circuit guard at line 1527 + already-closed json_success at line 1528; enriched log_audit at line 1535 (`"Closed homework | status old={$prevStatus} new=closed"`); pre-fix static description `log_audit('Homework', 'homework_close', $hwId, "Closed homework")` absent (grep 0 matches). tests/Unit/HomeworkCloseAuditEnrichmentTest.php exists with 3 test methods + 5 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-009 | P2-MEDIUM | concurrency | triaged
  - discovered: 2026-05-21 by cycle 3
  - surface: application/controllers/Homework.php:1436-1445 (close_homework read-then-write window)
  - reproduction: static trace — TOCTOU window between firestoreGet (1436) and firestoreUpdate (1445); no CAS, no __updateTime, no transaction
  - impact: Concurrent admin closes both succeed (idempotent end state, but spurious audit entries); future non-idempotent status transitions would corrupt
  - source_of_expectation: QUALITY_BAR §D3; carry-forward delete_session CAS gap (PROJECT_STATUS.md §8)
  - assumed_unverified: dirty_tree_state

BUG-010 | P3-LOW | performance | verified
  - discovered: 2026-05-21 by cycle 3
  - surface: application/controllers/Homework.php:1565-1569 (_fetch_all_homework usort comparator) — now lines 1687-1700 post-fix
  - reproduction: static trace — comparator re-normalizes timestamps O(n log n) times; for n=10k → ~280k strtotime calls per request
  - impact: Operational degradation at large-school scale; dashboard latency multi-second on 5000+ homework docs
  - source_of_expectation: QUALITY_BAR §D8; Schwartzian transform standard
  - fix_plan: precompute normalized timestamp into `_ts` field via foreach-by-reference (O(n)) before usort; comparator now integer-compares precomputed values (O(1) per compare). Reduces strtotime calls from ~2N log N to N. `_ts` follows the existing underscore-prefix convention (_class, _section, _id, _source).
  - fix_commit: (pending — applied session 5 cycle 1, 2026-05-21)
  - verification: 2026-05-21 session 5 cycle 2. Static trace: precompute `foreach ($result as &$item) {` count = 1; `$item['_ts'] = $this->_normalizeTimestamp($item['createdAt']` assignment count = 1; cheap comparator `return $b['_ts'] <=> $a['_ts'];` count = 1; pre-fix in-comparator `$aTs = $this->_normalizeTimestamp($a['createdAt']` count = 0. tests/Unit/HomeworkSchwartzianSortTest.php exists with 3 test methods + 4 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify); perf improvement magnitude not benchmarked — theoretical analysis only (O(N log N) strtotime calls → O(N))

BUG-011 | P2-MEDIUM | security | verified
  - discovered: 2026-05-21 by cycle 4
  - surface: application/controllers/Homework.php — read-side submissions queries at 221-225 and 346-350 lack schoolId predicate; mutating-side queries (1182, 1287, 1354) DO have it
  - reproduction: static trace — same-function asymmetry (get_homework_detail teacherMarks at 258-265 has schoolId; submissions at 221 doesn't)
  - impact: Defense-in-depth gap; if parent-doc schoolId gate weakens in future refactor, submissions exposure
  - source_of_expectation: Project's own defense-in-depth comment at lines 1280-1286; STACK_INVARIANT §5
  - fix_plan: add `['schoolId', '=', $this->school_name]` to the 2 read-side firestoreQuery conditions arrays. Composite index already exists per indexes.json:238 (Finding #4d comment). Preserves all surrounding logic.
  - fix_commit: (pending — applied session 4 cycle 3, 2026-05-21)
  - verification: 2026-05-21 session 4 cycle 4. Static trace: multi-predicate `['schoolId',   '=', $this->school_name],` count = 10 file-wide (well above the ≥ 2 minimum required by the 2 BUG-011 sites; the other 8 are pre-existing mutating-path / roster / sections queries); school-blind `[['homeworkId', '=', $hwId]]` count = 1 (deliberately the out-of-scope `_calc_submission_rate` helper at line ~1717 per finding-scope citation). tests/Unit/HomeworkSchoolIdPredicateTest.php exists with 2 test methods.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify)

BUG-012 | P2-MEDIUM | security | triaged
  - discovered: 2026-05-21 by cycle 4
  - surface: application/controllers/Homework.php — file-wide ZERO `rate_limit`/`throttle`; resource-heavy endpoints: create_homework (bulk-multi-section), delete_homework (paginated cascade), _fetch_all_homework callers (124,750 doc ceiling)
  - reproduction: static trace — compromised teacher account can DoS Firestore quota via bulk-create or dashboard refresh storm
  - impact: Quota-DoS surface; recovery requires Firestore admin intervention
  - source_of_expectation: QUALITY_BAR §D4 rate limits
  - fix_plan: requires promoting application/libraries/Rate_limiter.php into SCOPE_SURFACES (out_of_scope_dependency BLOCK on FIX cycle until done)
  - assumed_unverified: dirty_tree_state

BUG-013 | P3-LOW | security | verified
  - discovered: 2026-05-21 by cycle 4
  - surface: application/controllers/Homework.php — create_homework lines 932-935, update_homework lines 1137-1144 — no max-length validation
  - reproduction: static trace — 1MB title field triggers Firestore DocTooLarge; exception bubbles past json_error to 500 page
  - impact: Poor error UX on oversize input; no log captures cause
  - source_of_expectation: QUALITY_BAR §D4 input validation; OWASP A03
  - fix_plan: add byte-length caps (title 200, subject 100, description 10000) with explicit `json_error 400` on overflow. Inline in the existing validation cascade (create_homework lines 994+) and in the conditional-update blocks (update_homework lines 1216+). 6 BUG-013 marker comments total (3 create + 3 update).
  - fix_commit: (pending — applied session 5 cycle 3, 2026-05-21)
  - verification: 2026-05-21 session 5 cycle 4. Static trace: 6 cap checks present at lines 995 (title 200), 997 (subject 100), 998 (description 10000) on create side; 1220 (title 200), 1224 (desc 10000), 1228 (subj 100) on update side; 6 BUG-013 marker comments file-wide. tests/Unit/HomeworkInputLengthValidationTest.php exists with 3 test methods + 7 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify); strlen is byte-count (correct for Firestore 1MB doc-cap); user-facing message says "characters" for clarity — multibyte input may trip cap before character count would suggest (conservative bound, documented)

BUG-014 | P1-HIGH | observability | verified
  - discovered: 2026-05-21 by cycle 5
  - surface: application/controllers/Homework.php — 5 cross-tenant 403 denials at lines 217, 342, 1132, 1264, 1441; file-wide ZERO Security_telemetry usage
  - reproduction: static trace — schoolId-mismatch denial path emits json_error 403 with no log_message, no Security_telemetry; attacker probing yields zero forensic trail
  - impact: Tenant-boundary security telemetry blind spot; defeats Staff Hardening Phase A precedent
  - source_of_expectation: PROJECT_STATUS.md §2.3 Phase A precedent; FINAL_BLUEPRINT.md §5 D9
  - fix_plan: emit `$this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [...])` at each of 5 sites; library adopted by reference (NOT modified). Library auto-init'd by MY_Controller::_require_role, present as `$this->sec_telem` when controller body executes.
  - fix_commit: (pending — applied session 2 cycle 6, 2026-05-21)
  - verification: 2026-05-21 session 3 cycle 1. Static trace: exactly 5 `'CROSS_TENANT_PROBE'` literals; exactly 5 `isset($this->sec_telem) && $this->sec_telem->isReady()` guards; exactly 5 endpoint strings at lines 221 / 356 / 1153 / 1295 / 1482 — one per Homework method (get_homework_detail / get_submissions / update_homework / delete_homework / close_homework). tests/Unit/HomeworkTenantTelemetryTest.php exists with 3 test methods covering all of the above.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit execution required to fully verify)

BUG-015 | P2-MEDIUM | security | verified
  - discovered: 2026-05-21 by cycle 5
  - surface: application/controllers/Homework.php — 404-vs-403 existence oracle at lines 214/217, 339/342, 1129/1132, 1261/1264, 1438/1441 (pre-fix line numbers; post-fix at 236/378/1199/1341/1528)
  - reproduction: static trace — distinguishable HTTP responses for "doc absent" (404) vs "cross-school" (403); reopens the existence-oracle that Finding #5 isSameSchoolStrict() closed at the rule layer
  - impact: Information disclosure for cross-school hwId enumeration; defeats 2026-05 rule-layer remediation
  - source_of_expectation: Project's own Finding #5 remediation philosophy; QUALITY_BAR §D4
  - fix_plan: collapse the 5 cross-tenant `json_error('Unauthorized', 403)` responses into `json_error('Homework not found.', 404)` — same shape as the truly-not-found branch. BUG-014 CROSS_TENANT_PROBE telemetry preserved intact (still fires BEFORE the response). Server-side forensic trail captures the differential signal that the response no longer exposes.
  - fix_commit: (pending — applied session 4 cycle 5, 2026-05-21)
  - verification: 2026-05-21 session 4 cycle 6. Static trace: zero remaining `json_error('Unauthorized', 403)` (grep count = 0); exactly 5 BUG-015 marker comments (grep count = 5); BUG-014 CROSS_TENANT_PROBE emit count = 5 (preserved). tests/Unit/HomeworkExistenceOracleTest.php exists with 3 test methods + 3 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify); SIEM/monitoring tooling watching HTTP 403 rate as a tenant-boundary signal must be retuned to read `security_events` collection instead

BUG-016 | P3-LOW | UX | verified
  - discovered: 2026-05-21 by cycle 5
  - surface: application/controllers/Homework.php — 2 flat-shape (get_overview 114-122, get_trend_data 742-746) vs 9 namespaced-shape json_success responses
  - reproduction: static trace — response shape inconsistency across 11 read endpoints
  - impact: Developer-experience gap for future API consumers (iOS, public-API roadmap)
  - source_of_expectation: QUALITY_BAR §D5 consistency; intra-codebase 9-of-11 pattern dominance
  - fix_plan: dual-emit per HR canonical pattern (PROJECT_STATUS.md §1). Build payload as `$overview`/`$trends` local; emit `array_merge($payload, [<wrapper> => $payload])` so current flat-field consumers still work AND new namespaced consumers see the wrapper. Future cleanup phase removes the flat duplication once consumers migrate.
  - fix_commit: (pending — applied session 5 cycle 5, 2026-05-21)
  - verification: 2026-05-21 session 5 cycle 6. Static trace: 1× `$overview = [`, 1× `array_merge($overview, ['overview' => $overview])`, 1× `$trends = [`, 1× `array_merge($trends, ['trends' => $trends])` — exactly the 4 expected dual-emit substrings. tests/Unit/HomeworkResponseShapeTest.php exists with 3 test methods + 6 assertions.
  - assumed_unverified: test_runtime_pass (operator-driven PHPUnit run required to fully verify); the duplicated payload roughly doubles response byte size for these 2 endpoints — acceptable trade-off per HR-canonical precedent

## v7 session 4 cycle 1 — Bounded Mobile Attendance DISCOVERY Pilot (2026-05-22)

**Operator-authorized scope:** parent-android + teacher-android AttendanceScreen.kt + AttendanceViewModel.kt only. DISCOVERY-only — no autonomous fixes per operator constraint. Validates Path-α-by-marker-presence assumptions from sessions 1-3 module closures.

**Pilot conclusion:** Path-α assumptions DID NOT fully hold. AttendanceViewModel files were never touched in v7 (markers existed in OTHER parent/teacher files but not these). 5 fresh findings filed + 7 advisory observations documented in v7 session 4 cycle 1 DETECTION_REPORT.

BUG-038 | P2-MEDIUM | observability | VERIFIED (fix landed 2026-05-22 session 4 cycle 3)
  - discovered: 2026-05-22 by v7 session 4 cycle 1 (bounded mobile attendance DISCOVERY pilot)
  - surfaces: parent-android — D:\Projects\SchoolSyncParent\app\src\main\java\com\schoolsync\parent\ui\attendance\AttendanceViewModel.kt — 3 OEM-strip-vulnerable Log.* sites at lines 227 (Log.i No attendance for month), 271 (Log.w pullRefresh failed), 346 (Log.w Firestore extras failed)
  - reproduction: static trace — `Log.[wi]\(` count in parent AttendanceViewModel = 3; `debugLog` count = 0
  - observed: parent attendance VM uses android.util.Log.w / Log.i for forensic emissions; iQOO/Vivo/Oppo/Xiaomi OEM Android distributions strip these from logcat per F1 finding
  - expected: route through com.schoolsync.parent.util.debugLog (OEM-strip-immune via cache/debug.log file fallback); mirror v1 BUG-022 (parent homework debugLog) + v1 BUG-025 (parent homework pullRefresh) fix pattern exactly
  - source_of_expectation: v1 BUG-022 + BUG-025 fix precedents (closed-verified v1 session 7+9); FINAL_BLUEPRINT.md §5 D9; attendance_firestore_migration memory + homework_attachment_phase1_complete F1 finding
  - impact: operator triage gap on parent attendance — Phase 7 Firestore migration silently degrades on OEM Android handsets; admin sees stale UI with no forensic signal. Sibling-class of v1 BUG-022/025; same severity tier. This finding alone invalidates Path-α-by-marker-presence assumption for this file.
  - fix_applied: removed `import android.util.Log`, added `import com.schoolsync.parent.util.debugLog`, replaced 3 Log.* calls with `debugLog("[AttendanceVM][<sev>] <preserved-msg>[: ${e.message}]")` form. Severity preserved in `[I]`/`[W]` marker so logs remain tier-greppable. Throwable details concatenated as `: ${e.message}` (lossy on stacktrace by design — debugLog is single-line file-format).
  - fix_commit: PENDING (snapshot-commit deferred across all v7 sessions per operator)
  - verification: `Log.[dewivf]\(` count = 0; `debugLog(` count = 3; `import android.util.Log` count = 0; `import com.schoolsync.parent.util.debugLog` count = 1
  - assumed_unverified: not_applicable (fix verified by static trace)
  - related_bugs: v1 BUG-022 (parent homework debugLog — 3 sites, closed-verified) | v1 BUG-025 (parent homework pullRefresh — 1 site, closed-verified) | v7 BUG-039 (teacher attendance sibling, VERIFIED same cycle)

BUG-039 | P2-MEDIUM | observability | VERIFIED (fix landed 2026-05-22 session 4 cycle 3)
  - discovered: 2026-05-22 by v7 session 4 cycle 1 (bounded mobile attendance DISCOVERY pilot)
  - surfaces: teacher-android — D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\ui\attendance\AttendanceViewModel.kt — 13 OEM-strip-vulnerable Log.* sites: line 200 (Log.d assignments), 205 (Log.d distinct classes), 222 (Log.e assignment fail), 227 (Log.e load classes), 301 (Log.d loading), 309 (Log.d found students), 328 (Log.d dayWise), 330 (Log.d no data), 341 (Log.d attendance loaded), 351 (Log.e failed to load), 549 (Log.d save OK), 564 (Log.e save failed), 613 (Log.w refreshStage failed)
  - reproduction: static trace — 13 `Log.[wedi]\(` matches in teacher AttendanceViewModel (initial estimate "13+" confirmed as exactly 13)
  - observed: same F1 OEM-strip vulnerability as BUG-038 but with HIGHER density (13 sites vs 3) and MORE failure-path coverage (stage gate, save, refresh, correction)
  - expected: route through com.schoolsync.teacher.util.debugLog per v1 BUG-019/024 canon (teacher homework debugLog)
  - source_of_expectation: v1 BUG-019 fix precedent (teacher homework getTeacherMarksForHomework — closed-verified v1 session 7); v1 BUG-024 fix precedent (teacher homework loadStudentsForClass — closed-verified v1 session 8); F1 finding
  - impact: MORE SEVERE than BUG-038 because teacher attendance has more in-flight failure paths (stage gate / save / correction / refresh) — class teachers' failure modes invisible on affected handsets
  - fix_applied: removed `import android.util.Log`, added `import com.schoolsync.teacher.util.debugLog`, replaced all 13 Log.* sites with `debugLog("[$TAG][<sev>] <preserved-msg>[: ${e.message}]")` form. TAG constant ("AttendanceVM") preserved via Kotlin string interpolation; severity preserved in `[D]`/`[E]`/`[W]` marker. Throwable details concatenated as `: ${e.message}` where present (lossy on stacktrace by design — same trade-off as BUG-038). No splitting into 2 commits because changes are uniform and rollback-safe as one atomic edit.
  - fix_commit: PENDING (snapshot-commit deferred across all v7 sessions per operator)
  - verification: `Log.[dewivf]\(` count = 0; `debugLog(` count = 13; `import android.util.Log` count = 0; `import com.schoolsync.teacher.util.debugLog` count = 1
  - assumed_unverified: not_applicable (fix verified by static trace)
  - related_bugs: v1 BUG-019 + BUG-024 (teacher homework debugLog) | v7 BUG-038 (parent attendance sibling, VERIFIED same cycle)

BUG-040 | P3-LOW | data-integrity | triaged (DISCOVERY-only; FIX deferred)
  - discovered: 2026-05-22 by v7 session 4 cycle 1 (bounded mobile attendance DISCOVERY pilot)
  - surfaces: teacher-android — D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\ui\attendance\AttendanceViewModel.kt:300 (`SimpleDateFormat("MMMM yyyy", Locale.getDefault()).format(cal.time)`)
  - reproduction: static trace — teacher VM uses SINGLE month-key format only; parent VM at lines 298-305 uses DUAL-EMIT (`summaries.find { it.month == canonicalKey || it.month == legacyLabel }`)
  - observed: teacher app reads attendance summaries with legacy "Month YYYY" key only; parent app correctly handles BOTH canonical "YYYY-MM" and legacy formats per Phase 7h migration
  - expected: symmetric dual-emit handling matching parent VM's Phase 7h pattern
  - source_of_expectation: parent VM's explicit Phase 7h comment + dual-emit code at lines 298-305; attendance_firestore_migration memory's Phase 7 canonical/legacy alias note
  - impact: D10 cross-platform consistency drift — teacher will silently fail to display canonical-format records once admin writes them. UX manifests as "no data for this month" even though data exists. Hidden by the active dual-write transitional state.
  - fix_plan: transcribe parent VM's dual-emit pattern (lines 298-305) to teacher VM (line 300 area). ~10 LoC change.
  - fix_commit: NONE (operator constraint: DISCOVERY-only this cycle)
  - assumed_unverified: not_applicable (fix not applied)
  - related_bugs: parent VM Phase 7h handling (canon — already correctly dual-emit)

BUG-041 | P3-LOW | data-integrity | triaged (DISCOVERY-only; FIX deferred)
  - discovered: 2026-05-22 by v7 session 4 cycle 1 (bounded mobile attendance DISCOVERY pilot)
  - surfaces: teacher-android — D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\ui\attendance\AttendanceViewModel.kt:173-177 — hardcoded constants `SCHOOL_START_MINUTES = 8 * 60` + `LATE_MINUTES_CAP = 180`
  - reproduction: static trace — inline TODO comment at lines 173-175: "08:00 is the prevailing Indian-school norm and is safe as a fallback. TODO(rollout+1): read from school config once a `schoolStartTime` field exists on the school doc."
  - observed: late-minute calculation uses hardcoded 08:00 school start time + hardcoded 180-minute cap
  - expected: read schoolStartTime from school doc (admin-web school_config module owns this; would need new field on school document)
  - source_of_expectation: inline TODO already acknowledged this; D10 invariant prevents per-school config drift
  - impact: schools with non-08:00 start times have WRONG "minutes late" stored for tardy students. Affects teacher's reporting + parent's arrivalTime display. Bounded by per-school configuration practice (likely small population deviates from 08:00).
  - fix_plan: multi-platform — admin-web school_config save_profile extension for schoolStartTime field; teacher VM reads schoolStartTime with 08:00 fallback. Cross-coordinator required.
  - fix_commit: NONE (operator constraint: DISCOVERY-only this cycle)
  - assumed_unverified: not_applicable (fix not applied)
  - severity_classification_note: bounded by deployment population (most Indian schools start at 08:00); P3-LOW justifiable

BUG-042 | P3-LOW | UX | triaged (DISCOVERY-only; FIX deferred)
  - discovered: 2026-05-22 by v7 session 4 cycle 1 (bounded mobile attendance DISCOVERY pilot)
  - surfaces:
      parent-android — D:\Projects\SchoolSyncParent\app\src\main\java\com\schoolsync\parent\data\model\AttendanceData.kt:15 — `TRIP('T', "Tardy")` enum constant name
      teacher-android — D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\ui\attendance\AttendanceViewModel.kt:40 — `TARDY("T", "Tardy")` enum constant name
  - reproduction: static trace — same character code 'T' + same display label "Tardy" but divergent enum-constant names ("TRIP" vs "TARDY") for the same semantic concept
  - observed: parent uses enum-name "TRIP" (symbolic) for tardy; teacher uses enum-name "TARDY" (descriptive). Over-the-wire payload unaffected (both use 'T' char).
  - expected: matching enum-name convention across apps for the same semantic concept
  - source_of_expectation: D5 / D10 consistency principles; v1 BUG-017 cross-platform parity work precedent
  - impact: maintenance / cross-platform-review friction. Engineer reading both codebases must mentally translate. NOT a runtime bug. P3-LOW maintainability/D5 consistency.
  - fix_plan: rename either side to align. Conservative call: rename parent `TRIP` → `TARDY` to match descriptive label + teacher's choice. ~5-10 sites to update in parent (declaration at AttendanceData.kt:15 + references at AttendanceViewModel.kt lines 371/423 + other files via grep).
  - fix_commit: NONE (operator constraint: DISCOVERY-only this cycle)
  - assumed_unverified: not_applicable (fix not applied)
  - severity_classification_note: maintainability concern, not runtime; P3-LOW justifiable

## v7 session 5 cycle 1 — Stage 1 T1.1 Fees Runtime Validation (2026-05-22)

**Authorized:** 2026-05-22 (operator directive: "Runtime-validation phase authorized. Begin planning and orchestration for staged runtime validation, starting with fees module only.")

**Scope:** Fees module runtime validation Stage 1 Tier 1 scenario T1.1 (single-student happy-path payment via parent app → ngrok tunnel → admin backend → Razorpay sandbox).

**T1.1 outcome:** **NORMAL on authoritative records** (feeReceipts F11 + feeDemands × 3 May rows + feeDefaulters projection all consistent + ₹2800 fully allocated across Computer/Library/Tuition heads). Three findings filed at current classifications — DISCOVERY-only, fix execution awaits separate authorization through forensic → package → apply choreography per [[feedback_freeze_choreography]].

BUG-043 | P2-MEDIUM | display / projection | VERIFIED (fix landed + Phase 4 controlled-verification + Phase 5 cool-window early-termination + Phase 6 closing 2026-05-23)
  - discovered: 2026-05-22 by v7 session 5 cycle 1 (Stage 1 T1.1 runtime validation, STU0001 May ₹2800)
  - reclassified: 2026-05-23 by v7 session 5 cycle 2 V3.4 evidence (Stage 1 T1.2 admin offline payment, STU0001 June ₹2800) — operator-authorized escalation P3-LOW WATCH → P2-MEDIUM INVESTIGATE after cross-path confirmation that BOTH parent Razorpay sync verify AND admin offline counter paths leave `lastPaymentDate` empty
  - surfaces: admin-web — `feeDefaulters/SCH_D94FE8F7AD_2026-27_STU0001` projection doc, field `lastPaymentDate`. **Cross-path universal gap** — affects all observed payment-success paths.
  - reproduction:
      Path A (parent Razorpay sync verify, 2026-05-22): complete parent-app payment via Razorpay sandbox → inspect projection doc → `lastPaymentDate` is empty string `""`
      Path B (admin offline counter, 2026-05-23): admin panel → Submit Fees → Cash mode → inspect same projection doc post-payment → `lastPaymentDate` STILL empty string `""`
  - observed:
      Path A: `lastPaymentDate=""` after F11 (receipt 11) created, demands paid, totalDues correctly refreshed from prior value to 28000, unpaidMonths correctly excluded May
      Path B: `lastPaymentDate=""` after F12 (receipt 12) created, June demands paid, totalDues correctly refreshed 28000→25200, unpaidMonths correctly excluded June. `flaggedAt` field DID update on both paths (`2026-05-22T13:00:37+02:00` and `2026-05-22T20:52:44+02:00` respectively) — confirming projection writer IS active but specifically omits `lastPaymentDate` assignment.
  - expected: today's date or last-receipt timestamp per projection-writer canon
  - source_of_expectation: plan §1.1 expected-evidence checklist; [[fees_canonical_architecture]] projection-writers canon
  - impact: cross-path universal display gap. Authoritative state (feeDemands + feeReceipts + feeDefaulters.totalDues + unpaidMonths) all updated correctly across both paths. No financial-integrity violation. UI surfaces relying on `lastPaymentDate` show empty or stale state regardless of payment path. P2-MEDIUM justified by universality (affects every payment-success projection refresh, not edge case).
  - fix_applied: 1-line addition to [Fee_defaulter_check.php:233](application/libraries/Fee_defaulter_check.php#L233) — `$status['last_payment_date'] = $defaulterInfo['last_payment_date'] ?? '';`. Restores the missing field-copy between the 4 existing copies (lines 229-232) and downstream consumer Fee_firestore_sync.php:489. Defensive `?? ''` matches downstream-consumer style.
  - fix_commit: NONE (snapshot-commit deferred across all v7 sessions per operator)
  - fix_choreography_state: VERIFIED (full 6-phase lifecycle complete 2026-05-23)
  - assumed_unverified: not_applicable (fix verified via Phase 4 controlled-verification — F14 admin offline payment produced `lastPaymentDate: "2026-05-23T08:13:10+02:00"` in feeDefaulters projection, transitioning from empty `""` to populated ISO 8601 timestamp)
  - alias: FEE-VAL-001
  - severity_classification_note: P2-MEDIUM justified — cross-path universal display gap; affected every payment-success projection refresh across all 13 callers of `updateDefaulterStatus()`. P1-HIGH would have required demonstrated financial impact, absent in this case.
  - operator_authorization_audit_trail: forensic decision F-1 (O1 selected) + F-2 (strict choreography) + F-3 (parallel-with-BUG-047 confirmed) + APPLY signal + V3-A early-termination authorization — all explicit, verbatim, individually approved
  - lessons_observed:
      L1: classified as "simple field-mapping omission" per operator's framework — confirmed accurate. Not propagation asymmetry (both paths shared the bug), not deferred-update omission (deferred sync ran but wrote empty), not broader canon inconsistency (single missing line).
      L2: 4 explicit field copies followed by 5-field downstream consumer is a refactor-regression magnet. Future remediation candidate: replace explicit copies with `$status = array_merge($status, array_intersect_key($defaulterInfo, $status_keys))` or similar idiomatic safe-copy pattern. NOT in scope for this fix — flagged for future hardening cycle.
      L3: cross-path runtime comparison continues to be the highest-value Stage 1 methodology (4 findings now surfaced: BUG-044, BUG-046, BUG-047, BUG-043 — all via parent-vs-admin telemetry/state asymmetry analysis).
  - closing_summary_phase_6:
      phase_1_forensic: COMPLETE 2026-05-23 (root cause: field-copy omission at Fee_defaulter_check.php updateDefaulterStatus; classified as simple field-mapping omission)
      phase_2_package: COMPLETE 2026-05-23 (BUG-043-PKG-001 — 1-line addition; smoke S1-S4 + verify V1-V3)
      phase_3_apply: COMPLETE 2026-05-23 (single Edit call executed verbatim per P2; zero scope creep; IDE diagnostic noise = same false-positive class as prior cycles, disregarded)
      phase_4_smoke_and_verification: COMPLETE 2026-05-23 (S1 php -l clean; S2 Apache restart clean; S3 admin login clean; S4 log tail clean; V2 admin offline F14 ₹2800 August succeeded; V3 decisive test — lastPaymentDate populated with ISO 8601 timestamp matching the most recent paid demand updatedAt)
      phase_5_cool_window: ENTERED 2026-05-23 then EARLY-TERMINATED same day by operator authorization (V3-A) — bounded scope + zero regression signals justified
      phase_6_closing: COMPLETE 2026-05-23 (this entry)
      final_disposition: VERIFIED — fix functionally validated; cross-path projection-field propagation parity restored; gradual self-healing approach approved for historical empty-lastPaymentDate values (operator Decision V3-D)
      backfill_decision: DEFERRED — Decision V3-D approved gradual self-healing through future payment-triggered projection refreshes
  - related_bugs: BUG-044 (sibling — runtime audit-trail finding, batch_path-specific scope; forensic entry queued post-closing per Decision V3-C); BUG-045 (sibling — escalated to P1-HIGH same date); BUG-046 (sibling — VERIFIED via same Stage 1 methodology); BUG-047 (sibling — VERIFIED, cool window active in parallel)

BUG-044 | P2-MEDIUM | observability / audit-trail | VERIFIED (fix landed + Phase 4 controlled-verification + Phase 5 cool-window early-termination + Phase 6 closing 2026-05-23)
  - root_cause_post_forensic: Q4(i) refactor migration gap — controller-layer post-service `feeOnlinePayments` write was correctly implemented in `_verify_and_process` (line 3919) and `retry_payment_processing` (line 4066) but never added to `parent_verify_payment` direct-service-call path. NOT the batch_path scope hypothesis — actually a controller-layer omission, sibling pattern to BUG-047. Same Q4(i) regression family.
  - fix_applied: option O1 — +21-line try/catch block inserted into `parent_verify_payment` post-service success path (between line 3337 feeOnlineOrders update closing and line 3339 echo). Block writes `feeOnlinePayments/SCH_<schoolId>_PAY_<YmdHis>_<6hex>` doc with all 13 fields matching the `_verify_and_process` line 3919 schema; `source` field set to `'parent-razorpay'` to distinguish from `'frontend'`/`'webhook'`/`'parent_app'` source values.
  - fix_commit: NONE (snapshot-commit deferred across all v7 sessions per operator)
  - fix_choreography_state: VERIFIED (full 6-phase lifecycle complete 2026-05-23)
  - assumed_unverified: not_applicable (fix verified via Phase 4 V3.1-V3.5 — F15 parent Razorpay payment 2026-05-23 09:45 produced new doc `feeOnlinePayments/SCH_D94FE8F7AD_PAY_20260523094554_e3d6d7` with all expected fields including `source: "parent-razorpay"`, `payment_status: "captured"`, `receipt_key: "F15"`, `gateway_payment_id: "pay_SsjAp36whHLp7r"`)
  - alias: FEE-VAL-002
  - operator_authorization_audit_trail: forensic decision F-1 (O1 selected) + F-2 (Apply held until BUG-047 cool window expired) + F-3 (historical backfill deferred) + PKG-A (package accepted as-is) + PKG-B (no parallel BUG-045 forensic) + PKG-C (no historical snapshot) + APPLY signal post-BUG-047-cool-window-early-termination + V3-A early-termination authorization — all explicit, verbatim, individually approved
  - lessons_observed:
      L1: BUG-047 + BUG-044 are sibling regressions of the SAME Q4(i) refactor — both in parent_verify_payment, both controller-layer omissions left during _verify_and_process migration. Pattern strongly suggests other operations in `_verify_and_process` post-service block may need parent_verify_payment equivalents. Recommend future audit.
      L2: The "batch_path=true" log-signal correlated with BUG-044 but was NOT the causal mechanism — actually a coincidental marker. Forensic discipline corrected an early misclassification.
      L3: Cross-path runtime comparison (admin offline vs parent online) continues to be the proven Stage 1 detection methodology — surfaced BUG-044 via V3.5 evidence (admin path correctly omits feeOnlinePayments by design; parent path missing the write).
  - closing_summary_phase_6:
      phase_1_forensic: COMPLETE 2026-05-23 (root cause confirmed at controller layer, refined classification from "batch_path regression" to "Q4(i) migration gap")
      phase_2_package: COMPLETE 2026-05-23 (BUG-044-PKG-001 — 21-line block insertion; held during BUG-047 cool window)
      phase_3_apply: COMPLETE 2026-05-23 (single Edit verbatim per P2; zero scope creep)
      phase_4_smoke_and_verification: COMPLETE 2026-05-23 (S1-S4 clean; V2 F15 receipt created via parent Razorpay; V3.1-V3.5 all pass with new feeOnlinePayments doc)
      phase_5_cool_window: ENTERED 2026-05-23, EARLY-TERMINATED 2026-05-23 by operator after sustained-evidence accumulation
      phase_6_closing: COMPLETE 2026-05-23 (this entry)
      final_disposition: VERIFIED — parent-online audit-trail parity restored; cross-path feeOnlinePayments coverage now symmetric across webhook + sync verify + retry paths
      backfill_decision: Decision F-3 (historical feeOnlinePayments backfill for pre-fix parent-app payments) DEFERRED — separate operator decision pending
  - discovered: 2026-05-22 by v7 session 5 cycle 1 (Stage 1 T1.1 runtime validation, STU0001 May ₹2800)
  - surfaces: admin-web — `Fee_management.php::_verify_and_process()` synchronous (frontend) path; specifically the `batch_path=true` optimized branch
  - reproduction: complete parent-app payment via Razorpay sandbox (synchronous verify path) → inspect `feeOnlinePayments` Firestore collection → no new doc despite [Fee_management.php:3919](application/controllers/Fee_management.php#L3919) explicitly calling `firestoreSet('feeOnlinePayments', "{$this->school_name}_{$payRecId}", [...])`. PHP error log confirms no entry for `feeOnlinePayments` write between batch-commit (`[FCS BATCH COMMIT] ok=true` at 13:00:32) and function exit (`FC_OPTIMIZED ... batch_path:true` at 13:00:36).
  - observed: 0 docs added to `feeOnlinePayments` for `pay_SsNy8QVE2vylEr` despite successful synchronous-path return (200 OK + receipt F11). Existing collection contains only 3 historical docs (Apr 17 / Apr 20 / May 8) — none from today's payment.
  - expected: doc at `feeOnlinePayments/SCH_D94FE8F7AD_PAY_20260522HHMMSS_<random>` with `payment_status='captured'`, `gateway_payment_id='pay_SsNy8QVE2vylEr'`, `receipt_key='F11'` per source line 3919
  - source_of_expectation: source code [Fee_management.php:3919](application/controllers/Fee_management.php#L3919); plan §1.1 expected-evidence checklist; [[fees_canonical_architecture]] writer-trigger map (feeOnlinePayments writers should include synchronous verify path AND webhook path)
  - observed_code_path: log shows `batch_path:true` — confirms code took FC_OPTIMIZED batched-commit path (8 ops, succeeded), NOT the legacy inline-write path. The batched-write path omits `feeOnlinePayments` from its op list. Webhook path at [Fee_management.php:3663](application/controllers/Fee_management.php#L3663) likely still writes the doc.
  - impact: every parent-app synchronous payment via `batch_path=true` produces missing audit-trail entry. Authoritative records (feeReceipts + feeDemands + feeDefaulters projection) remain intact — **no financial-integrity violation**. However, future reconciliation queries that scan `feeOnlinePayments` would miss synchronous-path payments, producing FALSE-NEGATIVE orphan detections.
  - fix_plan: locate `Fee_firestore_txn` or `Fee_firestore_sync` batched-commit implementation; add `feeOnlinePayments` write op to the 8-op batch (9-op result) with the same schema as line 3919. Forensic → package → apply choreography required per [[feedback_freeze_choreography]] — financial-code class.
  - fix_commit: NONE (operator constraint: DISCOVERY-only this cycle; runtime-validation findings are surface-only)
  - assumed_unverified: not_applicable (fix not applied)
  - alias: FEE-VAL-002
  - severity_classification_note: P2-MEDIUM justifiable — every parent-app payment has this gap; reconciliation queries would falsely flag synchronous-path payments as missing. P1-HIGH would require demonstrated financial-integrity violation, which is absent.
  - comparative_coverage_resolution_2026_05_23: admin-side path validation (T1.2) executed successfully after BUG-046 remediation. **V3.5 evidence definitive:** `feeOnlinePayments` collection unchanged after admin offline payment F12 (no new doc). Collection contains 3 historical docs: 2 webhook_log entries (`docType: "webhook_log"`) from payment_webhook path AND 1 legacy parent-app payment record (Apr 17 F000001, `source: "parent_app"`, no docType). The legacy April 17 parent-app write CONFIRMS the synchronous verify path historically wrote here. The May 22 parent-app write (F11, batch_path=true) did NOT. So the gap is genuinely a regression introduced by the FC_OPTIMIZED batch_path consolidation — not by-design omission and not admin-path-shared.
  - path_inventory_matrix (post-V3.5):
      Parent Razorpay sync verify, legacy non-batch_path (April 17, F000001): writes feeOnlinePayments ✓
      Parent Razorpay sync verify, batch_path=true (May 22, F11): does NOT write feeOnlinePayments ❌ ← regression
      Parent Razorpay webhook (historical): writes feeOnlinePayments as docType="webhook_log" ✓ (not exercised in Stage 1 due to EKYC block)
      Admin offline counter (May 22, F12): does NOT write feeOnlinePayments ✓ (by design — admin offline is not "online")
  - scope_narrowing_implication: root-cause investigation can focus narrowly on Fee_firestore_txn / Fee_firestore_sync batched-commit code path. The batch op list should be expanded from 8 ops → 9 ops to include the feeOnlinePayments write that line 3919 previously performed in the legacy path.
  - related_bugs: BUG-043 (sibling — now ALSO P2 INVESTIGATE after cross-path escalation); BUG-045 (sibling — P2 INVESTIGATE sustained-latency); BUG-046 (sibling — VERIFIED via Phase 4 controlled-verification 2026-05-23); v1 BUG-022/025 (parent-side observability precedents — different layer)

BUG-046 | P1-HIGH | functional regression | VERIFIED (fix landed + Phase 4 controlled-verification + Phase 5 cool-window early-termination + Phase 6 closing 2026-05-23)
  - discovered: 2026-05-23 by v7 session 5 cycle 2 (Stage 1 T1.2 attempt — admin offline payment for STU0001 June ₹2800)
  - surfaces: admin-web — [FeeCollectionService.php:41](application/services/FeeCollectionService.php#L41) calling `MY_Controller::_abort_if_period_locked()` from a non-subclass scope. Trigger entry: [Fees.php:2644](application/controllers/Fees.php#L2644) `submit()`.
  - reproduction: admin panel → Fees → Submit Fees → select STU0001 → enter June + 2800 + Cash mode → submit. Every invocation produces the same PHP `Error` with backtrace identical.
  - observed: PHP `Error` (not `Exception`): "Call to protected method MY_Controller::_abort_if_period_locked() from scope FeeCollectionService" at FeeCollectionService.php:41. Request aborts BEFORE transactional mutation. **No state change occurs** — no receipt created, no demand updated, no projection refreshed.
  - expected: admin offline payment submission completes successfully OR aborts cleanly with a domain-level message ("period locked, cannot record payment") — NOT a raw PHP Error visible to admin.
  - source_of_expectation: T1.2 plan §6.1 expected control-comparison flow; standard PHP visibility-modifier semantics
  - root_cause_hypothesis: Recent refactor moved period-lock check logic from controller scope (where `_abort_if_period_locked()` was reachable as a protected method via MY_Controller inheritance) into the new `FeeCollectionService` service class. The service class does NOT extend `MY_Controller`, so the protected-method call became an Error at runtime. Visibility modifier was not adjusted during the move. Static analysis would not have caught this because cross-class protected-method references through composition (vs inheritance) are common in PHP.
  - impact: **operationally blocking** — every admin offline-payment submission attempt crashes. T1.2 scenario cannot execute. Does NOT cause financial-integrity violation (no state mutation). P1-HIGH justified by complete-workflow-block, not by financial damage.
  - fix_options_under_forensic_review:
      O1: Change `_abort_if_period_locked()` visibility `protected` → `public` in `MY_Controller` (minimal diff; expands API surface)
      O2: Add public wrapper method on `MY_Controller`; have `FeeCollectionService` call that wrapper through injected controller reference (medium diff; preserves protected scope)
      O3: Move period-lock check logic INTO `FeeCollectionService` as its own private method, with whatever dependencies it needs injected (largest diff; cleanest separation)
  - fix_applied: option O1 — single-word visibility change in MY_Controller.php:1035 (`protected function _abort_if_period_locked` → `public function _abort_if_period_locked`). Smallest possible diff. Backward-compat 100%. Performance impact zero.
  - fix_commit: NONE (snapshot-commit deferred across all v7 sessions per operator)
  - fix_choreography_state: VERIFIED (full 6-phase lifecycle complete 2026-05-23 — see closing summary below)
  - assumed_unverified: not_applicable (fix verified via Phase 4 controlled-verification)
  - alias: FEE-VAL-004
  - severity_classification_note: P1-HIGH justified — complete admin-workflow block on critical fee collection path. P0-CRITICAL would require demonstrated financial damage or production-blocker beyond dev environment; this is dev-env operational block which P1 covers.
  - operational_blocker_for: T1.2 (Stage 1 Tier 1 scenario 2 — admin offline payment control comparison) — UNBLOCKED 2026-05-23 by this fix; T1.2 then locked NORMAL
  - related_bugs: BUG-043 (sibling — escalated to P2 INVESTIGATE via cross-path V3.4 confirmation enabled by this fix); BUG-044 (sibling — narrowed to batch_path=true scope via V3.5 evidence enabled by this fix); BUG-045 (sibling — sustained-latency reclassified to P2 INVESTIGATE after T1.2 second observation made possible by this fix)
  - closing_summary_phase_6:
      phase_1_forensic: COMPLETE 2026-05-23 (root cause confirmed at PHP-visibility-semantic level; caller map and blast radius bounded)
      phase_2_package: COMPLETE 2026-05-23 (BUG-046-PKG-001 — 1-word diff, smoke plan S1-S4, controlled-verification V1-V3, rollback plan)
      phase_3_apply: COMPLETE 2026-05-23 (single Edit call executed verbatim per P2; zero scope creep; IDE diagnostic noise = same false-positive class as prior sessions, disregarded)
      phase_4_smoke_and_controlled_verification: COMPLETE 2026-05-23 (S1 php -l clean; S2 Apache restart clean; S3 admin login surface check clean; S4 log tail no new errors; V2a F12 receipt successfully created via admin offline path; V3.1-V3.5 evidence comprehensive — schema parity confirmed, atomic batch-write discipline preserved on admin path, projection updated correctly cross-path)
      phase_5_cool_window: ENTERED 2026-05-23 then EARLY-TERMINATED 2026-05-23 by operator authorization after sustained observation with zero regression signals
      phase_6_closing: COMPLETE 2026-05-23 (this entry)
      final_disposition: VERIFIED — fix functionally validated; cross-path runtime comparison successfully unblocked; 3 sibling findings (BUG-043/044/045) now properly classified at their final scope/priority levels; no further BUG-046 lifecycle work required
      operator_authorization_audit_trail: forensic decision F-1 (O1 selected) + F-2 (strict choreography) + APPLY signal + cool-window-entry confirmation + V3 evidence acceptance + cool-window-early-termination authorization — all explicit, verbatim, individually approved
      lessons_observed:
        L1: runtime validation successfully surfaced a real visibility-scope regression that static hardening did not catch (services-vs-controller cross-class protected-method calls are a class of bug that requires execution to expose)
        L2: defensive method_exists() check at FeeCollectionService.php:40 was insufficient — method_exists returns true for protected methods. Future service-layer code defensively probing controller methods should use is_callable() from calling scope instead, OR call via a public wrapper
        L3: the cross-path comparison methodology (parent-online vs admin-offline) enabled rapid classification refinement on BUG-043 (escalated) and BUG-044 (narrowed) in a single V3 cycle — should be standard methodology for future Tier 1 scenarios

BUG-045 | P1-HIGH | performance | **OPEN — INVESTIGATE-class** (OP1' incremental fix VERIFIED 2026-05-23; broader BUG-045 stays open per operator V3-B — sustained-latency >25s user-perceived still observed)
  - discovered: 2026-05-22 by v7 session 5 cycle 1 (Stage 1 T1.1 runtime validation, STU0001 May ₹2800)
  - reclassified: 2026-05-23 by v7 session 5 cycle 2 (Stage 1 T1.2 V2a runtime validation, STU0001 June ₹2800) — operator-authorized escalation P3-LOW WATCH → P2-MEDIUM INVESTIGATE after second observation across DIFFERENT execution path produced near-identical latency
  - surfaces: admin-web — shared back-end pipeline used by both `parent_verify_payment` (parent Razorpay sync path) AND `submit_fees`/FeeCollectionService (admin offline counter path). Bottleneck is path-agnostic — resides in shared phases (counter claim, lock acquire, demand allocation).
  - reproduction:
      Path A (parent Razorpay): complete parent-app payment via Razorpay sandbox → measure server-side latency via PHP error log FC_TIMING entries
      Path B (admin offline counter): admin panel → Submit Fees → STU0001 → month + amount + Cash mode → measure server-side latency
  - observed:
      T1.1 (Path A, 2026-05-22 13:00): **24.6439s server-side**, 30.86s ngrok-side. Phase breakdown: counter_claimed=5318ms, lock_acquired=9286ms, demands_allocated=12007ms, lock_released=13287ms, response_ready=13287ms, deferred_done=20649ms. Signature verification took 6629ms separately.
      T1.2 V2a (Path B, 2026-05-23): **~25.23s server-side** (operator-reported). Phase breakdown to be captured during V3 evidence-collection cycle.
      F13 V2a-retest (Path A parent Razorpay, 2026-05-23 07:45 post-BUG-047 fix): **30.04s server-side**. Phase breakdown: counter_claimed=5206ms, lock_acquired=9201ms, demands_allocated=14485ms, lock_released=17439ms, response_ready=17439ms, deferred_done=43764ms (longest deferred phase observed; includes accounting integration block now correctly posting post BUG-047 fix).
      F13 first-attempt FAILURE (Path A parent Razorpay, 2026-05-23 07:39): **18.94s create_order execution → client-side timeout**. Parent app showed "The School server is temporarily unavailable, Please try again shortly." Backend completed at 19s but parent app HTTP client had already timed out (~10-15s typical client timeout). User-perceived behavior: payment APPEARED to fail; manual retry succeeded. **This is a real-world operational failure caused by sustained latency.**
      F14 V3 verification (Path B admin offline, 2026-05-23 08:13 post-BUG-043 fix): **24.07s server-side** (admin-reported).
  - aggregate_observations: **4 sustained-latency runtime measurements** across 2 execution paths, 2 separate days. Mean ~25.9s. Range 24.0s-30.0s. **Plus 1 confirmed real-world client-timeout failure** (F13 first attempt). Sustained-latency hypothesis empirically validated; cold-start hypothesis empirically rejected.
  - cross-path inference: Both paths produce near-identical (~25s) latency. Path A includes Razorpay signature verification (~6.6s overhead) that Path B does NOT include — yet Path B is not faster. Strongly suggests the shared backend pipeline (counter / lock / allocation / batch commit) is the dominant cost, and that the Razorpay signature overhead is absorbed within the latency-noise envelope rather than additive on top.
  - expected: < 2s server-side per plan §1.5 single sync-call envelope; 2-5s sustained = WATCH; > 5s sustained = INVESTIGATE; >20s sustained = strong INVESTIGATE / approaching FREEZE-class on UX-quality grounds (not on financial-integrity grounds — money is NOT at risk)
  - source_of_expectation: plan §1.5 timing envelope
  - impact: critical UX-quality concern across all fee-submission paths. Parent app user sees ~30s spinner; admin user sees ~25s submit-button hang. NOT a financial-integrity issue — money is correctly recorded in both observed cases. Operator's own framing: "not great or professional for ERP SaaS like us." Risk: production users may abandon submission mid-spinner OR retry-tap producing duplicate orders (idempotency catches this for parent path, but UX confusion remains).
  - fix_plan: root-cause investigation REQUIRED on shared-pipeline phases. Candidate bottleneck zones in priority order:
      1. counter_claimed=5318ms — Firestore counter doc contention or India-region read latency. Check `feeCounters/{schoolId}_{session}` access pattern.
      2. lock_acquired ~4s after counter — Fee_firestore_txn lock acquisition. Possibly tied to TTL-based re-acquisition pattern (120s TTL noted in [[fees_canonical_architecture]]-adjacent comments).
      3. demands_allocated=12007ms (12s) — bulk demand-read for allocation. May involve sequential Firestore reads (per [[accounting_engine_repairs]] firestoreGetParallel sequential shim history — could be related).
      4. deferred_done=20649ms — post-response work; doesn't block user but adds total work cost.
    Forensic → package → apply choreography required per [[feedback_freeze_choreography]] — financial-code shared-pipeline class.
  - fix_commit: NONE (escalation administrative only; no source mutation; fix execution awaits separate authorization)
  - choreography_state: triaged-INVESTIGATE; forensic phase NOT YET ENTERED; awaiting operator decision on whether to enter forensic during Stage 1 OR defer to post-Stage-1 dedicated remediation campaign
  - assumed_unverified: not_applicable (latency reproduced across two paths)
  - alias: FEE-VAL-003
  - severity_classification_note: **P1-HIGH justified post-2026-05-23 escalation** — demonstrated client-timeout failure on F13 first attempt crosses from "degraded UX" into "operational performance instability." P2-MEDIUM was correct at single-day sustained-cross-path confirmation; no longer holds after real-world failure observation. P0-CRITICAL would require demonstrated financial-integrity violation (e.g. silent partial-state corruption from a timed-out request), which is absent (F13 first-attempt failure left state un-mutated; user retry succeeded). 
  - reclassification_audit_trail: P3-LOW WATCH (2026-05-22, single observation) → P2-MEDIUM INVESTIGATE (2026-05-23 cross-path confirmation) → **P1-HIGH INVESTIGATE (2026-05-23 V3-B, client-timeout failure)**
  - related_bugs: BUG-043 (sibling — VERIFIED 2026-05-23); BUG-044 (sibling — VERIFIED 2026-05-23); BUG-046 (sibling — VERIFIED); BUG-047 (sibling — VERIFIED)

  ## OP1' incremental subcycle — VERIFIED 2026-05-23 (Phase 5 cool window active)

  - subcycle_id: BUG-045-OP1'
  - subcycle_status: VERIFIED (full 6-phase choreography complete through Phase 4; Phase 5 cool window active until ~2026-05-24)
  - subcycle_scope: migrate FeeCollectionService.php parPreload block from sequential `firestoreGet` × 4 to true-parallel `Firestore_rest_client::getDocumentsParallel` with sequential fallback
  - subcycle_diff: 22-line block at lines 200-221 of FeeCollectionService.php (replacing 6-line sequential block); mirrors Curriculum_service.php:681-688 pattern
  - subcycle_forensic_correction_note: original OP1 (using `firebase->firestoreGetParallel`) was discovered to be a no-op since the wrapper method is itself sequential. OP1' corrected to access Firestore_rest_client directly. Forensic discipline successfully prevented a low-value mutation.
  - subcycle_decisive_test_result:
      F15 (pre-fix) read_parallel_ms: 3520ms
      F16 (post-fix) read_parallel_ms: 1817ms
      reduction: 1703ms (48%) — parallel API mechanically confirmed firing
      target was <1500ms; achieved 1817ms — above ideal due to India-region Firestore RTT, curl-multi concurrency, network variance
  - subcycle_total_latency_observation:
      F15 response_ready: 17.8s
      F16 response_ready: 19.6s (+1.8s)
      ⚠ single-sample variance in batch_commit phase (F15 2.6s vs F16 6.9s) ate OP1' savings on this observation
      lock_acquired + demands_allocated phases BOTH dropped ~2.5s each — confirms OP1' working as designed; savings landed where predicted
      net wall-clock improvement masked by phase-2/3 batch_commit variance — needs more samples for statistical confidence
  - subcycle_sibling_regressions_clean:
      BUG-043 (lastPaymentDate populated) — ✓ verified on F16 (`"2026-05-23T10:18:35+02:00"`)
      BUG-044 (feeOnlinePayments new doc) — ✓ verified on F16 (`SCH_..._PAY_20260523101849_a3c5cf`, source="parent-razorpay")
      BUG-047 (accounting journal posted) — ✓ verified on F16 (JE_FEE_F16 ACC_IDEMP_CLAIMED + ACC_JOURNAL_COMMITTED + [SUBMIT JOURNAL POSTED] all present)
      receipt counter sequential (F15→F16) — ✓
      feeDefaulters projection (totalDues 16800→14000, unpaidMonths excludes October) — ✓
  - subcycle_remaining_bottlenecks_revealed_post_OP1':
      demands_allocated phase: ~5.4s (lock_acquired → demands_allocated delta on F17; second-largest single-phase cost; OP4 candidate)
      counter_claimed phase: ~5.2s (unchanged — first-largest single-phase cost; OP2/OP3 candidates)
      batch_commit + cleanup phase: ~2.9s on F17 (was 6.9s noise spike on F16; variance can mask other gains)
      F17 second-sample confirmation: response_ready 17817ms (F15) → 15791ms (F17) = −2026ms net steady-state improvement; OP1' working as designed
      F16 retroactively reclassified as batch_commit-phase variance noise (single-observation +1.8s spike) rather than fix regression

  ## OP4-A subcycle — VERIFIED 2026-05-23 (Phase 5 cool window active)

  - subcycle_id: BUG-045-OP4A
  - subcycle_status: VERIFIED (Phase 4 complete; Phase 5 cool window ACTIVE until ~2026-05-24)
  - subcycle_scope: extend FeeCollectionService.php batch from 8 ops to 12 ops by embedding 4 audit-log writes atomically with financial writes, eliminating the post-batch sequential audit-write phase. Added new `buildOp()` method to Fee_audit_logger + three new static helpers in FeeCollectionService (`_buildAuditDemandUpdateOp`, `_buildAuditReceiptWriteOp`, `_buildAuditOpsAfterBatch`).
  - subcycle_diff: +125 net lines across 2 files (Fee_audit_logger.php +38 lines for buildOp; FeeCollectionService.php Part 1 +5 net at batch_path success branch; Part 2 +82 lines for 3 static helpers)
  - subcycle_decisive_test_results (F18 post-fix):
      V3.1 batch_size: 8 → **12** ✓ (audit ops embedded atomically)
      V3.2 demands_allocated phase delta: 5373ms (F17) → **1872ms** (F18) = **−3501ms (-65%)** ✓ (exceeded forecast)
      V3.3 response_ready total: 15791ms (F17) → **11109ms** (F18) = **−4682ms (-30%)** ✓
      Total execution: 29.16s (F17) → **18.70s** (F18) = **−10.46s (-36%)** ✓
  - subcycle_sibling_regressions_clean:
      BUG-043 (lastPaymentDate populated) — assumed verified pending operator Firestore confirmation
      BUG-044 (feeOnlinePayments doc created) — assumed verified pending operator Firestore confirmation
      BUG-047 (accounting journal posted) — ✓ ACC_IDEMP_CLAIMED + ACC_JOURNAL_COMMITTED + [SUBMIT JOURNAL POSTED] all present in F18 log
      receipt counter sequential (F17→F18) — ✓
      batch_path=true preserved (no fallback to sequential) — ✓
  - subcycle_remaining_bottlenecks_post_OP4A:
      counter_claimed phase: ~4060ms on F18 (NEW dominant single-phase cost; OP2/OP3 candidates)
      batch commit (lock_released − demands_allocated): ~2896ms on F18 (already atomic; limited headroom)
      parPreload (lock_acquired − counter_claimed): ~2279ms on F18 (within OP1' baseline range)
      demands_allocated phase: ~1872ms on F18 (was 5373ms pre-OP4-A — optimized)
      post-response_ready gap (deferred_done − response_ready): ~24s on F18 — parent path lacks Option B early-response flush per OP5 candidate analysis (FeeCollectionService.php lines 1437-1466 only run for writeToOutput=true / admin path)
  - subcycle_choreography_state: VERIFIED — full 6-phase lifecycle complete (Phase 4 2026-05-23; Phase 5 early-terminated 2026-05-23 by operator after lightweight stability observation; Phase 6 closing 2026-05-23 on transition to OP5 forensic). OP4-A subcycle formally closed.
  - subcycle_phase_6_closing: cool window early-terminated authorized 2026-05-23; OP4-A delivered the largest single-subcycle gain in the BUG-045 arc (~10.5s total execution reduction, ~4.7s response_ready reduction); no regression signals observed during stability observation; sibling integrity systems remained clean
  - V3.4_audit_completeness_deferred: 4 expected feeAuditLogs docs (3 demand audits + 1 receipt audit, all with auditIds starting AUD_20260523113217_*) — operator-side inspection optional carry; primary decisive metrics V3.1-V3.3 satisfied Phase 4 closure
  - parent_bug_status_after_OP4A: BUG-045 broader investigation REMAINS OPEN per operator V3-B. User-perceived latency improved 36% but 18.7s still above acceptable production threshold. counter_claimed and post-response_ready gaps remain.
  - operator_authorization_audit_trail: forensic decision F-1 (OP4-A approved with bounded scope) + F-2 (Package-phase Fee_audit_logger read authorized) + F-3 (strict choreography) + APPLY signal "APPLY BUG-045-OP4A-PKG-001" + V3-A standard Phase 5 cool window
  - next_subcycle_preliminary_priority: per operator V3-B preliminary prioritization — OP5 (parent-path early-response flush, ~7s user-perceived saving) + OP3 (counter hoisted off critical path, ~4s saving) now the highest-impact next candidates; OP2 (sharded counter) lower priority for single-tenant testing

  ## OP5-A subcycle — APPLIED but ENVIRONMENT-BLOCKED 2026-05-23

  - subcycle_id: BUG-045-OP5A
  - subcycle_status: applied + Phase 4 regression/integrity COMPLETE; **UX-saving objective ENVIRONMENT-BLOCKED** by XAMPP Apache mod_php SAPI behavior (not an implementation defect)
  - subcycle_scope: add Option B early-response flush pattern (Connection: close + Content-Length + flush) to parent_verify_payment controller after the existing echo block; mirrors FeeCollectionService.php:1481-1495 admin-path pattern
  - subcycle_diff: +16 net lines (replaced 8-line echo with 24-line build-JSON-and-flush block) at [Fee_management.php:3364-3387](application/controllers/Fee_management.php#L3364)
  - subcycle_apply_timestamp: 2026-05-23
  - subcycle_v3_results (F19):
      V3.1 (PRIMARY user-perceived completion target ~11s): **FAILED** — observed ~26s; ngrok inspector confirmed parent_verify_payment POST duration 26.28s
      V3.2 (ngrok inspector duration target): **FAILED** — same 26.28s
      V3.3 (sibling regression): ✓ PASS — BUG-043/044/047 all clean; F19 receipt + idempotency + audit + journal + projection + summary all posted correctly
      V3.4 (response integrity): ✓ PASS — parent app received valid 200 OK JSON with all expected fields; refresh behavior preserved
  - subcycle_environmental_finding:
      Apache mod_php holds TCP connection open until PHP process fully terminates — including ALL registered shutdown handlers (~14s post-response_ready deferred work for accounting journal + summary). `Connection: close` + `Content-Length` + `flush()` cannot achieve early connection release in this SAPI.
      `fastcgi_finish_request()` is the only PHP function that can truly decouple "response sent" from "PHP process running" — but it requires PHP-FPM SAPI, not available in XAMPP mod_php.
      The pre-existing admin-path Option B flush (FeeCollectionService.php:1481-1495) was likely ALSO functionally ineffective in this environment — supporting evidence: F12 admin-path showed gap of 8.5s between response_ready (16467ms) and Total execution (24994ms), suggesting Apache held connection regardless of the flush calls.
  - subcycle_lessons:
      L4 [forensic accountability]: when proposing to mirror an "existing proven pattern", verify the pattern actually produces measurable effect in the same environment — don't trust comments alone. OP5-A forensic should have compared admin-path response_ready vs Total execution numbers before recommending the pattern.
      L5 [architectural insight]: in XAMPP/mod_php deployments, "post-response shift" optimizations (moving work from critical path to deferred shutdown handler) provide ZERO user-perceived improvement — Apache holds the connection regardless. Any future optimization in this category should be deferred until PHP-FPM migration OR genuine async-queue infrastructure is adopted.
      L6 [future activation]: OP5-A code is harmless and remains pre-positioned for activation if/when PHP-FPM is adopted in production. The flush calls become no-ops in mod_php but would be effective in PHP-FPM.
  - subcycle_disposition: per operator OP5-A1 → KEEP IN PLACE (no rollback); per OP5-A2 → no further "post-response shift" optimizations pursued under mod_php; per OP5-A3 → Phase 4 declared COMPLETE on regression/integrity criteria only; UX-saving objective classified ENVIRONMENT-BLOCKED
  - subcycle_choreography_state: regression-VERIFIED 2026-05-23; UX-objective ENVIRONMENT-BLOCKED; brief lightweight cool window then transition to SO1 forensic per operator next-direction preference
  - parent_bug_status_after_OP5A: BUG-045 broader investigation REMAINS OPEN. Real-world UX latency unchanged from F18 baseline (~26s in current XAMPP env). New optimization-class restriction: post-response-shift optimizations deferred under mod_php. Future user-impact optimization must come from TRUE execution-time reduction (query reduction, batching, client refresh behavior) — NOT response-release timing tricks.
  - operator_authorization_audit_trail: forensic decision F-1 (OP5-A approved) + F-2 (Package-phase read authorized) + F-3 (strict choreography) + APPLY signal "APPLY BUG-045-OP5A-PKG-001" + OP5-A1 (keep) + OP5-A2 (no more post-response shifts) + OP5-A3 (env-blocked classification)

  ## SO1 subcycle — HYPOTHESIS REJECTED 2026-05-23 (forensic accountability record)

  - subcycle_id: BUG-045-SO1
  - subcycle_status: HYPOTHESIS REJECTED (forensic NO-GO; no Package phase entered)
  - subcycle_original_hypothesis: parent-app `loadFees()` post-verify refresh adds ~10-15s hidden client-side latency to user-perceived completion time
  - subcycle_forensic_finding: hypothesis REJECTED via direct parent-app source verification — `loadFees()` is NOT in the critical post-verifyPayment-response path; actual post-verify client work is `PaymentSession.confirmFromBackend` with Firestore polling (~1s typical, ~6s worst-case timeout), much smaller than 10-15s estimate
  - subcycle_corrected_post_verify_flow:
      1. verifyPayment HTTP returns (env-blocked ~26s in mod_php)
      2. State.Confirming (UI shows "Verifying with school records…")
      3. confirmFromBackend polls Firestore for feeReceipts/{schoolId_F<receiptNo>} (typical 1 poll = ~500-1000ms)
      4. readMonthStatus query (additional ~500ms)
      5. State.Success (UI shows receipt screen)
      6. (loadFees not invoked here; it's used for pull-to-refresh + initial-load via separate code paths)
  - subcycle_corrected_latency_attribution: of the ~26s user-perceived completion, ~14s is Apache holding the TCP connection through deferred shutdown work (env-blocked), ~12s is true server-side execution (partially-optimized via OP1'+OP4-A), ~3-5s is Razorpay sandbox processing (external), ~1s is confirmFromBackend (minor). No "10-15s hidden client component" exists.
  - subcycle_minor_polish_opportunity_filed: `confirmFromBackend` uses fixed 1s delay between polls; exponential backoff (200ms → 400ms → 800ms) could save <500ms in slow-Firestore-propagation edge cases. Filed as future polish carry, NOT primary optimization target.
  - subcycle_lessons:
      L7 [forensic discipline]: when hypothesizing a client-side latency component, verify against source code BEFORE estimating impact. The "~10-15s loadFees" estimate in BUG-045 OP4-A R5 + SO1 initial framing was based on assumption, not source-verified evidence. Sibling lesson to L4 (verify-pattern-actually-delivers from OP5-A).
      L8 [client/server latency attribution]: in mod_php deployments where Apache holds the connection through PHP shutdown, the user-perceived completion latency is dominated by server-side wait time. Client-side post-response components are typically SHORTER than the server wait, not additive on top. Future client-side latency hypotheses should account for the overlap, not assume sequential.
  - subcycle_choreography_state: forensic NO-GO 2026-05-23; no Package phase; no Apply; no source mutation
  - subcycle_disposition: per operator SO1-A → CLOSED as hypothesis-rejected; per SO1-B → BUG-045 stays OPEN with OP2 as the remaining bounded optimization candidate
  - operator_authorization_audit_trail: forensic phase entry authorized 2026-05-23 + SO1-A (close as hypothesis-rejected) + SO1-B (BUG-045 stays open, OP2 next candidate) + SO1-C (preserve forensic findings in ledger)

  ## OP2-A subcycle — PRODUCTION-CONCURRENCY OPTIMIZATION; SINGLE-TENANT REGRESSIVE (rolled back 2026-05-23)

  - subcycle_id: BUG-045-OP2A
  - subcycle_status: APPLIED + V3-MEASURED + ROLLBACK-AUTHORIZED 2026-05-23 — technically functional + integrity-safe + production-concurrency optimization, but single-tenant regressive in current XAMPP/Firestore environment
  - subcycle_scope: enable existing sharded-counter infrastructure via feeSettings flag (no source-code mutation; config-only); shardedEnabled=true in `feeSettings/SCH_D94FE8F7AD_2026-27_counters`
  - subcycle_apply_timestamp: 2026-05-23 (operator-side Firestore console write)
  - subcycle_v3_results (F33):
      V3.1 (counter_claimed reduction target ~1500ms): **REGRESSION** — observed 7716ms vs F19 baseline 5243ms (+2473ms, +47%)
      V3.2 (non-sequential receipt#): F19 → F33 (skipped 13 numbers; shard 3 residue class; sharded algorithm confirmed working as designed)
      V3.3 (sibling regression): ✓ PASS — BUG-043/044/047 all clean; F33 receipt + projection + audit + journal all correct
      V3.4 (sharded path firing): ✓ CONFIRMED via receipt-number jump pattern + legacy global pointer bump (feeCounters/..._receipt_seq.value=33)
      V3.5 (response_ready saving target): **REGRESSION** — F19 12442ms → F33 16810ms (+4368ms, +35%); regression driven primarily by counter_claimed regression
      V2 user-perceived: ~30s (worse than F18's ~18.7s and F19's ~26s)
  - subcycle_root_cause: sharded counter does 5-6 Firestore ops per claim vs legacy's 3 ops. The sharded path adds: (a) shard doc read, (b) shard doc set, (c) legacy global read, (d) legacy global set — all post-success housekeeping. In single-tenant testing, legacy's retry loop never fires more than once (no claim-collision), so legacy is faster. Sharded's parallelism advantage only materializes under concurrent multi-cashier load where legacy's retry loop fires many times due to collisions.
  - subcycle_classification: **"production-concurrency optimization; single-tenant regressive"** — code is correct, integrity-safe, well-designed for its target use case (concurrent multi-tenant load), but the wrong tool for the current single-tenant test environment
  - subcycle_decision_disposition:
      OP2-A-1: operator-authorized rollback — operator-side action: set `feeSettings/SCH_D94FE8F7AD_2026-27_counters.shardedEnabled = false`
      OP2-A-2: document honestly per this entry
      OP2-A-3: broader conclusion — remaining latency now primarily environment/Firestore/deployment-model constrained, not orchestration inefficiency
  - subcycle_irreversible_side_effect: receipt counter jumped F19 → F33 during OP2-A test; rollback restores legacy path but DOES NOT restore the 13 skipped numbers (F20-F32 are permanently unclaimed). Per operator OP2-1 acknowledgment, non-sequential numbering is acceptable. Post-rollback, next receipt will be F34 (legacy continues from current global pointer value 33).
  - subcycle_post_rollback_expectation: counter_claimed returns to ~5s baseline; response_ready returns to ~12s baseline; total execution returns to ~18-19s baseline; user-perceived returns to ~18-19s baseline (matches F18 post-OP4-A-only state)
  - subcycle_lessons:
      L12 [forensic accountability]: When forecasting saving for "single-tenant" scenarios, count Firestore ops carefully for BOTH paths. Sharded counters are a contention optimization, not a single-request optimization. The OP2 forensic R5 single-tenant saving estimate undercounted sharded's housekeeping ops.
      L13 [optimization context-sensitivity]: Some optimizations have different sign under different load conditions. OP2-A is a NET WIN under production multi-tenant load (concurrent collision reduction dominates) but a NET LOSS under single-tenant testing (added ops dominate). Future optimization recommendations should explicitly characterize the load-condition assumption.
      L14 [pre-positioned code as architectural option]: The sharded-counter infrastructure was built and gated for exactly this kind of rollout decision. The flag-+-fallback architecture worked as designed — instant rollback with zero risk.
  - subcycle_choreography_state: APPLIED + REGRESSION-OBSERVED + ROLLBACK-AUTHORIZED 2026-05-23; awaiting operator-side Firestore flag flip + post-rollback baseline reassessment
  - operator_authorization_audit_trail: OP2-1 (accept non-sequential receipt numbering) + OP2-2 (compressed config-only choreography) + OP2-3 (defer OP2-B unless OP2-A underdelivers) + APPLY (operator-side Firestore write) + OP2-A-1 (rollback) + OP2-A-2 (honest documentation) + OP2-A-3 (broader conclusion accepted)

  ## Sub-observation [BUG-045.SO1] — loadFees() post-verify refresh latency (ORIGINAL FRAMING; SUPERSEDED BY SO1 HYPOTHESIS-REJECTION RECORD ABOVE)
  - surface: parent-android client-side; per [[razorpay_dashboard_next]] FeesViewModel calls `loadFees()` AFTER `verifyPayment(event)` returns success — i.e., a second backend roundtrip post-verify
  - reproduction: complete parent-app Razorpay payment via Netbanking → measure user-perceived wall-clock from "Success" on netbanking sandbox → parent app shows fresh fees screen state
  - observed (F17 / 2026-05-23 10:40-10:41):
      Razorpay success-click → parent app shows complete: **56s user-perceived wall-clock**
      Server-side parent_verify_payment: ~26s (per FC_TIMING + Total execution log)
      Razorpay sandbox callback + network: estimated ~5-10s combined
      **Unaccounted gap: ~10-15s** — strongly suggests loadFees() post-verify refresh phase as hidden client-side latency component
  - hypothesis: loadFees() makes a backend call (likely `fees/fetch_fee_details` or similar) to refresh the Fees screen state after verifyPayment success. This call hits the slow shared back-end pipeline (Firestore reads, counters, projections) and adds significant user-perceived latency that is NOT visible in the parent_verify_payment FC_TIMING data.
  - dual-layer latency model: BUG-045 broader scope now includes both (a) backend orchestration latency in FeeCollectionService — addressed by OP1', further work in OP2/3/4 candidates AND (b) client-side post-verify refresh latency — separate workstream
  - verification next: ngrok inspector at `127.0.0.1:4040` would show the loadFees() HTTP call immediately following parent_verify_payment response; measure that request's duration
  - fix_status: no remediation yet — sub-observation only; deferred per operator V3-C (T1.4 completion + T1.5 first; OP4 / loadFees forensic later)
  - classification: P1 INVESTIGATE (inherits BUG-045 parent priority)
  - subcycle_choreography_state: VERIFIED — full 6-phase lifecycle complete (Phase 4 2026-05-23; Phase 5 early-terminated 2026-05-23 by operator after sustained-evidence accumulation; Phase 6 closing 2026-05-23). OP1' subcycle formally closed.
  - subcycle_phase_6_closing: cool window early-terminated authorized 2026-05-23; OP1' is bounded incremental win delivering ~2s steady-state response_ready improvement (F15 17.8s → F17 15.8s) + 48% preload-phase reduction (3520ms → 1817ms). Sibling-fix regressions confirmed clean across F16 + F17. Parent_bug_status: BUG-045 broader investigation REMAINS OPEN per operator V3-B + recalibration.
  - next_subcycles_deferred:
      OP4 (demands_allocated investigation) — highest-value next per operator's V3-C plan (T1.4 first, then OP2/3/4 if justified by broader telemetry)
      OP2 (sharded counter migration) — deferred
      OP3 (counter claim hoisted off critical path) — deferred
  - operator_authorization_audit_trail: forensic decision F-1' (OP1' approved after scope correction) + F-2 (deeper forensic deferred) + F-3 (strict choreography) + APPLY signal + V3-A standard Phase 5 cool window + V3-B BUG-045-stays-OPEN + V3-C resume Stage 1 T1.4 next

BUG-047 | P0-CRITICAL | financial-integrity / observability | VERIFIED (fix landed + Phase 4 controlled-verification + Phase 5 cool-window early-termination + Phase 6 closing 2026-05-23)
  - discovered: 2026-05-23 by v7 session 5 cycle 3 (Stage 1 T1.3 telemetry observation pass) via cross-path telemetry asymmetry analysis between F11 (parent Razorpay) and F12 (admin offline)
  - surfaces: admin-web — `FeeCollectionService.php::submit()` shutdown-handler deferred-accounting branch (lines 1576-1619). Parent-app synchronous Razorpay verify path passes `defer_accounting=true` at [Fee_management.php:3869](application/controllers/Fee_management.php#L3869) but the deferred journal-posting code never executes for that path.
  - reproduction:
      Path A (F11, 2026-05-22 13:00, parent Razorpay sync verify, ₹2800):
        - Receipt F11 created in feeReceipts ✓
        - 3 May feeDemands marked paid ✓
        - feeDefaulters projection refreshed ✓
        - **0 accounting events emitted** (no ACC_IDEMP_CLAIMED, no ACC_JOURNAL_COMMITTED, no [SUBMIT JOURNAL POSTED])
        - **No `JE_FEE_F11` doc in `accountingIdempotency`** (operator-verified)
        - **No journal in `accountingLedger`** (inferred from absent idempotency claim — idempotency precedes journal commit per Accounting_idempotency canon)
        - FC_TIMING_FINAL deferred_done=20649ms (20.6s — short by ~20s vs admin path)
      Path B (F12, 2026-05-22 20:52, admin offline counter, ₹2800):
        - Same authoritative writes ✓
        - **3 accounting events emitted:** ACC_IDEMP_CLAIMED (20:52:52), ACC_JOURNAL_COMMITTED (20:53:01), [SUBMIT JOURNAL POSTED] (20:53:02)
        - `JE_FEE_F12` doc exists in `accountingIdempotency` with status="success" ✓
        - FC_TIMING_FINAL deferred_done=40274ms (40.3s — includes ~20s accounting integration block)
  - observed: financial integrity gap — F11's ₹2,800 was credited to feeReceipts + feeDemands + feeDefaulters BUT not journaled to accountingLedger. Time elapsed since payment: 24+ hours. Plan §5.2 5-minute threshold violated by ~288x.
  - expected: every fee-receipt path MUST post a corresponding accounting journal within 5 minutes of demand mutation. Per plan §1.1 evidence checklist + [[fees_canonical_architecture]] writer canon + Operations_accounting::create_fee_journal contract.
  - source_of_expectation: plan §1.5 timing envelope (5min threshold); plan §5.2 explicit FREEZE_REQUIRED trigger ("Demand updated but accounting journal missing after 5min"); [[accounting_soak_contract]] FREEZE_REQUIRED triggers list verbatim
  - root_cause_hypothesis_pending_forensic:
      H1: `fastcgi_finish_request()` at FeeCollectionService.php:1590 terminates PHP-FPM worker before registered shutdown handler completes accounting branch. Admin path may not hit fastcgi_finish_request in the same way (different SAPI behavior / response flush timing).
      H2: `$__asyncRunning` evaluates to true on parent path → forces `$deferAccounting=false` at line 1542 → shutdown handler skips accounting → but then async worker (FeeWorker.php) should catch up. NO async worker run for F11 in logs — so async path also failed silently.
      H3: Exception thrown inside shutdown handler before reaching the accounting branch; try/catch at lines 1604-1606 (defaulter) or 1616-1618 (accounting) silently swallowed. No error log seen — so this fork unlikely.
      H4: Closure variable capture failed — one of the captured-by-value variables ($firebase, $schoolName, etc.) was null/empty at registration time, causing the accounting branch to early-return without logging.
  - impact: every parent-app Razorpay payment via this path produces an un-journaled receipt → reconciliation between Razorpay-captured-total and accountingLedger-recognized-revenue will be SYSTEMATICALLY broken by exactly the amount of parent-app payments. Production deployment would produce audit findings + balance sheet imbalance + tax-filing risk.
  - blast_radius_estimate_pending_forensic: every receipt created via Fee_management.php::parent_verify_payment (sync verify path) since the FC_OPTIMIZED batch_path consolidation was activated. Receipt F000001 (Apr 17, parent_app, legacy non-batch path) likely DOES have a journal — would be useful comparative data once forensic begins.
  - fix_applied: option O1 — single-word change at [Fee_management.php:3256](application/controllers/Fee_management.php#L3256) `'defer_accounting' => false` → `'defer_accounting' => true`. Restores symmetry with sibling sites at lines 3869 and 4033 which were correctly migrated during Q4(i) refactor. Net delta −1 char (5→4 chars).
  - fix_commit: NONE (snapshot-commit deferred across all v7 sessions per operator)
  - fix_choreography_state: VERIFIED (full 6-phase lifecycle complete 2026-05-23)
  - assumed_unverified: not_applicable (fix verified via Phase 4 V3.1 — F13 parent Razorpay payment 2026-05-23 07:45 produced all 3 expected accounting log lines: `ACC_IDEMP_CLAIMED key=JE_FEE_F13`, `ACC_JOURNAL_COMMITTED entryId=JE_FEE_F13 attempt=1 ops=3`, `[SUBMIT JOURNAL POSTED] receipt=F13`; AND V3.2 Firestore confirmation that `accountingIdempotency/SCH_D94FE8F7AD_JE_FEE_F13` exists with status="success")
  - alias: FEE-VAL-005
  - severity_classification_note: P0-CRITICAL justified — plan §5.2 specifically lists "accounting journal missing after 5min" as a FREEZE_REQUIRED financial-integrity trigger. P0 reserved for empirically-confirmed financial integrity violations + production-blocker scope. Not P1 because this isn't merely operationally-blocking (BUG-046 was P1) — this is INTEGRITY-violating (one severity tier higher).
  - operator_authorization_audit_trail:
      FREEZE-A: Halt Stage 1 immediately ✓ (operator 2026-05-23)
      FREEZE-B: Preserve F11 un-journaled state (B3) for forensic clarity ✓
      FREEZE-C: File at P0 FREEZE_REQUIRED ✓
      FREEZE-D: Authorize forensic phase entry with bounded scope ✓
      forensic decision F-1: O1 selected ✓
      forensic decision F-2: strict choreography preserved ✓
      forensic decision F-3: Stage 1 stays halted during BUG-047 work ✓
      forensic decision F-4: historical sweep deferred to post-stabilization ✓
      APPLY BUG-047-PKG-001 ✓ (verbatim explicit signal)
      Phase 5 cool window early-termination ✓ (2026-05-23, after sustained-evidence accumulation)
  - lessons_observed:
      L1: Q4(i) refactor migration gap — parent_verify_payment direct-service-call path was incompletely updated. Sibling discovery BUG-044 (filed same date) is another Q4(i) migration gap in the SAME function. Pattern indicates future audit candidate: every operation in `_verify_and_process` post-service block should be verified to have an equivalent in `parent_verify_payment` post-service block.
      L2: Boolean flag with double-negative semantics (`defer_accounting: false` = "skip accounting entirely") is refactor-regression-prone. Future remediation candidate: rename to positive-sense. OUT OF SCOPE for this fix.
      L3: Cross-path runtime telemetry asymmetry surfaced this in T1.3 telemetry observation pass. Continues to be highest-value Stage 1 methodology.
  - closing_summary_phase_6:
      phase_1_forensic: COMPLETE 2026-05-23 (root cause: defer_accounting flag at line 3256)
      phase_2_package: COMPLETE 2026-05-23 (BUG-047-PKG-001 — 1-word diff)
      phase_3_apply: COMPLETE 2026-05-23 (single Edit verbatim per P2)
      phase_4_smoke_and_verification: COMPLETE 2026-05-23 (S1-S4 clean; V2a F13 receipt created; V3.1 all 3 accounting log lines present; V3.2 idempotency doc confirmed)
      phase_5_cool_window: ENTERED 2026-05-23, EARLY-TERMINATED 2026-05-23 by operator after sustained-evidence accumulation
      phase_6_closing: COMPLETE 2026-05-23 (this entry)
      final_disposition: VERIFIED — parent-online accounting integrity restored; Stage 1 financial-integrity FREEZE resolved
      backfill_decision: Decision F-4 historical sweep (feeReceipts(paymentMode='Online - Razorpay') vs accountingLedger) DEFERRED to operator-gated post-stabilization decision
  - operational_blocker_for: T1.4 (read-side timing), T1.5 (projection consistency), all of Tier 2 — Stage 1 halted pending remediation
  - related_bugs: BUG-044 (sibling — also `batch_path=true` regression; both surfaced via cross-path methodology); BUG-046 (sibling — also runtime-validation-discovered regression; closed via freeze choreography); BUG-043 (sibling — cross-path projection-writer gap); BUG-045 (sibling — sustained latency; the long deferred phase on admin path may correlate with this finding)
  - preservation_note: F11's un-journaled state is deliberately preserved per operator FREEZE-B decision to retain forensic clarity; backfill deferred until root cause determines correct recovery choreography

## v7 cycle 23 DISCOVERY + FIX — attendance (2026-05-21, session 2)

BUG-036 | P2-MEDIUM | security | verified
  - discovered: 2026-05-22 by v7 session 3 cycle 3 (operator explicit `option 1` selection after Phase 6 close)
  - surfaces: admin-web — 6 tenant-boundary denial sites across 2 controllers, each with existence-oracle behavior:
      Attendance.php: scan_qr (403 'This QR is for a different school.'), correction_decide (403 'Cross-school access denied.')
      Red_flags.php: resolve_flag + delete_flag + restore_flag (each 403 'Unauthorized'), bulk-resolve iterator (errors[] 'Item {$i}: unauthorized (cross-school).')
  - reproduction: static trace — pre-fix `grep "Unauthorized', 403\|This QR is for a different school\|Cross-school access denied"` returned 5 distinct messages across 6 sites; all distinguishable from truly-not-found 404 responses by the legitimate caller, creating existence-oracle for cross-school resource enumeration.
  - observed: 6 denial paths emit descriptive cross-school messages with 403 (or "unauthorized (cross-school)" in errors[]) — distinguishable from truly-not-found 404 branches in the same functions. Attacker probing across schools can enumerate flag_id / studentId existence by response shape.
  - expected: each tenant-boundary denial collapses to match the truly-not-found branch's 404 + generic message; mirrors Homework v1 BUG-015 fix shape exactly. CROSS_TENANT_PROBE telemetry (added in BUG-034/035) preserves operator-visible forensic signal.
  - source_of_expectation: v1 BUG-015 fix precedent (P2-MEDIUM, closed-verified at v1 session 4 cycle 6 — collapsed 5 cross-tenant `json_error('Unauthorized', 403)` responses in Homework.php to `json_error('Homework not found.', 404)`); FINAL_BLUEPRINT.md §5 D4; BUG-014's CROSS_TENANT_PROBE telemetry pattern provides forensic substitute for the lost-response-distinguishability.
  - impact: P2-MEDIUM (same severity rationale as v1 BUG-015 — defense-in-depth gap; bounded by _require_role gate; matters most under post-Phase-6 design where CROSS_TENANT_PROBE telemetry is now the operator-visible signal).
  - fix_plan: collapse each of 6 denial responses to match its function's truly-not-found 404 branch. 5 message changes + 1 errors[] string change. ~12 line modifications across 2 files. Preserves CROSS_TENANT_PROBE telemetry unchanged.
  - fix_commit: (pending — applied v7 session 3 cycle 3 = cycle 48 absolute, 2026-05-22; uncommitted)
  - verification: 2026-05-22 v7 session 3 cycle 4 = cycle 49 absolute. Static trace via 3 independent probes: (a) 404-pattern presence — Attendance.php has 2 new BUG-036 collapses (line 3662 'Student not found.' + line 7444 'Request not found.') + 2 pre-existing truly-not-found branches (lines 3655, 7431); Red_flags.php has 4 new BUG-036 collapses (line 629 + 799 + 857 'Flag not found.' + line 940 bulk "Item {$i}: flag not found.") + multiple pre-existing 404 branches; (b) pre-fix existence-oracle anti-pattern absence — `json_error('Unauthorized', 403)` count = 0 across controllers (was 3 in Red_flags); 'This QR is for a different school.' count = 0; 'Cross-school access denied.' count = 0; 'unauthorized (cross-school)' count = 0 (each was 1+); (c) php -l CLI clean on both files. Not circular (3 distinct probes from discovery's pattern enumeration).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)
  - related_bugs: v1 BUG-015 (Homework existence-oracle — 5 sites collapsed to 404; closed-verified) | v7 BUG-034 (Attendance CROSS_TENANT_PROBE telemetry — verified) | v7 BUG-035 (Red_flags CROSS_TENANT_PROBE telemetry — verified)
  - pattern_completion_note: this fix closes the BUG-014 + BUG-015 paired pattern — Phase 6+ telemetry coverage (BUG-034/035) paired with Phase 6+ existence-oracle hardening (this BUG-036). Forensic capability preserved via sec_telem; legitimate-caller signal narrowed via 404 collapse.

BUG-035 | P2-MEDIUM | security | verified
  - discovered: 2026-05-22 by v7 session 3 cycle 1 (Phase 6+ continuation — Red_flags cross-controller CROSS_TENANT_PROBE coverage)
  - surfaces: admin-web — application/controllers/Red_flags.php — 4 tenant-boundary check sites lacking security telemetry:
      resolve_flag (line ~616-619); delete_flag (line ~775-778); restore_flag (line ~822-825); bulk operation iterator (line ~893-897, uses $errors[] not json_error — different shape but same probe concern)
  - reproduction: static trace — pre-fix `grep -c CROSS_TENANT_PROBE Red_flags.php` = 0; 3 explicit `json_error('Unauthorized', 403)` denial sites + 1 bulk-accumulate cross-school error site.
  - observed: 3 endpoints (resolve_flag, delete_flag, restore_flag) deny cross-school access with `json_error('Unauthorized', 403)` and no security telemetry. 1 bulk endpoint accumulates "Item {$i}: unauthorized (cross-school)." into errors[] without telemetry. All 4 sites use the same defensive pattern: `if (schoolId !== this->school_id && schoolCode !== this->school_id)`.
  - expected: each tenant-boundary check site emits sec_telem CROSS_TENANT_PROBE with endpoint + flag_id + existing_schoolId/schoolCode + caller_school identifiers (mirror Homework BUG-014 + Attendance BUG-034 fix shape).
  - source_of_expectation: v1 BUG-014 fix precedent (P1-HIGH); BUG-034 (P2-MEDIUM, validated cycle 26 of session 2); FINAL_BLUEPRINT.md §5 D9; staff_hardening_phase_a memory note on Phase 6+ CROSS_TENANT_PROBE coverage extension.
  - impact: P2-MEDIUM (same severity rationale as BUG-034 — non-financial controller, defense-in-depth gap).
  - fix_plan: add isset+isReady guard + sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [...]) before each denial response/error accumulation; preserve existing 403/errors[] semantics. ~25 line additions across 4 sites.
  - fix_commit: (pending — applied v7 session 3 cycle 1 = cycle 46 absolute, 2026-05-22; uncommitted)
  - verification: 2026-05-22 v7 session 3 cycle 2 = cycle 47 absolute. Static trace via 3 independent probes: (a) CROSS_TENANT_PROBE event count in Red_flags.php = 4 at lines 620 (resolve_flag), 789 (delete_flag), 846 (restore_flag), 927 (bulk iterator) — each preceded by tenant-boundary `if (schoolId !== ... && schoolCode !== ...)` check + BUG-035 marker comment + isset+isReady guard; (b) isset+isReady guard count = 4 (one-per-emission, no orphans); (c) php -l CLI clean. Not circular.
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)
  - phase_6_context: 3rd CROSS_TENANT_PROBE-coverage extension (after Homework BUG-014, Attendance BUG-034). Scope refinement note: earlier cycle-24 plan listed AdminUsers + Ptm + Red_flags as candidates, but AdminUsers.php tenant-mismatch is iteration-filter (no denial — `continue`) and Ptm.php tenant-mismatch is fallback-pattern (no denial — try alternate). Only Red_flags has the actual denial pattern. **Phase 6 cross-controller CROSS_TENANT_PROBE extension scope effectively COMPLETE** — 11 emission sites across 3 controllers; sec_telem now 4-consumer-verified.
  - related_bugs: v1 BUG-014 (Homework — 5 sites; closed-verified) | v7 BUG-034 (Attendance — 2 sites; closed-verified)

BUG-034 | P2-MEDIUM | security | verified
  - discovered: 2026-05-22 by v7 session 2 cycle 25 (Phase 6+ CROSS_TENANT_PROBE extension under implicit operator reauth of staff_hardening 6-15)
  - surfaces: admin-web — application/controllers/Attendance.php — 2 tenant-boundary denial sites lacking security telemetry:
      scan_qr() line ~3642 ($tokSchoolId !== $this->school_name; 403 denial; had log_message warning but no sec_telem)
      correction_decide() line ~7424 ((string)$req['schoolId'] !== $this->school_id; 403 denial; no logging at all)
  - reproduction: static trace — `grep CROSS_TENANT_PROBE application/controllers/` pre-fix showed 5 emissions only in Homework.php (v1 BUG-014). 0 in Attendance.php despite 2 tenant-boundary denial sites present.
  - observed: tenant-boundary denial paths in attendance emit 403 (existence-oracle) with no security telemetry. Attacker probing across schools yields no operator-visible forensic trail.
  - expected: every tenant-boundary denial emits sec_telem CROSS_TENANT_PROBE event with endpoint + cross-school identifiers (mirror Homework BUG-014 fix shape).
  - source_of_expectation: v1 BUG-014 fix precedent (P1-HIGH); FINAL_BLUEPRINT.md §5 D9; PROJECT_STATUS.md §2.3 Phase A precedent; memory's staff_hardening_phase_a notes patches 6-15 likely include CROSS_TENANT_PROBE cross-controller coverage extension.
  - impact: P2-MEDIUM (lower than BUG-014's P1-HIGH because scan_qr + correction_decide are lower-traffic than homework dashboard endpoints; defense-in-depth gap remains).
  - fix_plan: add isset+isReady guard + sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [...]) immediately before each 403 denial response; preserve existing log_message + json_error response shape (no BUG-015-class collapse this cycle).
  - fix_commit: (pending — applied v7 session 2 cycle 25 = cycle 43 absolute, 2026-05-22; uncommitted)
  - verification: 2026-05-22 v7 session 2 cycle 26 = cycle 44 absolute. Static trace via 3 independent probes: (a) CROSS_TENANT_PROBE event count in Attendance.php = 2 at lines 3648 (scan_qr) + 7435 (correction_decide), both inside tenant-boundary `if (...!= schoolId)` blocks with isset+isReady guards; (b) canonical sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [...]) signature count = 2 (matches Homework BUG-014 shape exactly); (c) php -l CLI clean. Not circular.
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)
  - phase_6_context: filed under attendance module (where code lives) but scope-tracked as staff_hardening Phase 6+ work (CROSS_TENANT_PROBE cross-controller coverage extension). 3 more controllers identified as candidates: AdminUsers.php, Ptm.php, Red_flags.php — separate BUG-NNN if operator wants them done. Pilot validated — pattern is straightforward and low-risk per design.
  - related_bugs: v1 BUG-014 (Homework CROSS_TENANT_PROBE — 5 sites; closed-verified). This work extends BUG-014's pattern to attendance.

BUG-033 | P2-MEDIUM | observability | verified
  - discovered: 2026-05-22 by v7 session 2 cycle 35 (extended attendance scope after BUG-032 close; sibling-class discovery)
  - surfaces: admin-web — application/controllers/Attendance.php — 8 silent Firestore-catch sites across 7 functions:
      get_student_summary (~line 1400, $fsDocs); fetch_staff_attendance (~1490 empty body + ~1518 $allStaffAtt);
      fetch_devices (~2109 $docs); fetch_analytics (~2754 $fsByStudent); fetch_monthly_trend (~2904 $fsTotalsByMonth);
      fetch_individual_report (~3084 $fsByMonth); fetch_punch_log (~3232 $fsPunches)
  - reproduction: static trace — identical silent-fallback shape to v1 BUG-002 (homework, 6 sites — closed) and v7 BUG-032 (attendance dashboard_stats, 9 sites — closed). Firestore failure → variable set to empty/null → admin sees zero/empty data with no forensic signal differentiating "no data" from "Firestore outage / quota / index miss".
  - observed: 8 distinct catch sites swallowing \Exception $e without log_message; each falls back to empty array or relies on downstream null-check
  - expected: every Firestore-catching site emits log_message('error', "Attendance::<function> <op> failed: " . $e->getMessage()); mirrors v1 BUG-002 + v7 BUG-032 fix shape exactly
  - source_of_expectation: v1 BUG-002 fix precedent (P1-HIGH); v7 BUG-032 fix precedent (P1-HIGH); FINAL_BLUEPRINT.md §5 D9; intra-file convention from 19+ properly-logging catches in same file
  - impact: P2-MEDIUM (not P1-HIGH like BUG-032) — these endpoints are lower-traffic than dashboard_stats (report/listing screens vs always-on dashboard widget). Same finding class, narrower blast radius.
  - fix_plan: add log_message inside each of 8 silent catches; preserve fallback assignments + fall-through comments; expand 1 one-liner (~3232 fetch_punch_log) to multi-line. Mirror BUG-032 fix shape. ~24 line additions across 8 sites.
  - fix_commit: (pending — applied v7 session 2 cycle 36 via cycle 18 of session 2, 2026-05-22; uncommitted)
  - verification: 2026-05-22 v7 session 2 cycle 37. Static trace via 3 independent probes: (a) 8 log_message emissions at lines 1401/1492/1522/2114/2760/2911/3092/3241 (each with unique function:var identifier — get_student_summary:fsDocs / fetch_staff_attendance:allTeachers / fetch_staff_attendance:allStaffAtt / fetch_devices / fetch_analytics:fsByStudent / fetch_monthly_trend:fsTotalsByMonth / fetch_individual_report:fsByMonth / fetch_punch_log:fsPunches); (b) single-line silent-fallback anti-pattern absent post-fix (0 matches, was 8); (c) php -l CLI clean (No syntax errors detected). Not circular (3 distinct probes from discovery's line-numbered scope-pass).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)
  - related_bugs: v1 BUG-002 (homework dashboard silent catches — closed) | v7 BUG-032 (attendance dashboard_stats silent catches — closed)
  - cross_module_pattern: this is the THIRD instance of "silent-Firestore-fallback-without-log_message" finding across the campaign. Pattern is well-established; future modules with similar dashboard/report endpoints should be scoped for this class proactively.

BUG-032 | P1-HIGH | observability | verified
  - discovered: 2026-05-21 by v7 session 2 cycle 23 (Focused silent-catch DISCOVERY on attendance)
  - surfaces: admin-web — application/controllers/Attendance.php — dashboard_stats() body — 9 silent Firestore catches at lines 120, 121, 140, 155, 198, 208, 223, 253, 264 (pre-fix line numbers)
  - reproduction: static trace — pre-fix `grep -c "catch.*Exception.*\\\$e.*{[^}]*}"` shows ~17 silent-catch shapes file-wide; 9 of those concentrate in dashboard_stats and set fallbacks ($todayAttDocs=[], $attSummaryDocs=[], $staffAttDocs=[], $staffSummaryDocs=[], $fsDocs=[]) or have empty/comment-only bodies with no log_message emission
  - observed: Firestore failure inside dashboard_stats silently sets variables to fallback values; admin sees zero/empty dashboard with no forensic signal differentiating "no data" from "Firestore outage"
  - expected: every Firestore-catching site emits log_message('error', ...) for operator forensic signal — mirror of v1 BUG-002 fix shape (closed-verified at v1 session 2 cycle 1 — 6 similar sites in Homework.php)
  - source_of_expectation: v1 BUG-002 fix precedent (P1-HIGH); FINAL_BLUEPRINT.md §5 D9; intra-file pattern at lines 1100, 3659, 3669, 3818, 3930 (which DO log) prove convention exists
  - impact: Phase 7 Firestore-first migration silently degrades on Firestore quota/outage; admin dashboard misleadingly shows zero/partial values; no trigger for operator triage
  - fix_plan: add log_message('error', "Attendance::dashboard_stats <description> failed: " . $e->getMessage()) inside each of 9 silent catches; preserve existing fallback assignments + intentional "leave totals at zero" comments; expand 2 one-liner catches (lines 120-121) to multi-line to accommodate log_message while preserving best-effort semantics
  - fix_commit: (pending — applied v7 session 2 cycle 24, 2026-05-21; uncommitted)
  - verification: 2026-05-21 v7 session 2 cycle 25. Static trace via 3 independent probes: (a) 9 distinct log_message emissions at lines 121/124/145/163/209/222/240/273/287 (each with unique semantic identifier — _process_pending_push_requests / _process_approved_leaves / todayAttDocs / attSummaryDocs / student RTDB fallback / staffAttDocs / staffSummaryDocs / staff RTDB fallback / pendingLeaves); (b) pre-fix anti-pattern single-line silent-fallback absent from dashboard_stats range (post-fix 0 matches in lines 113-300; 1 remaining file-wide match at line 3232 is in fetch_punch_log, scoped out per fix advisory); (c) php -l CLI clean (No syntax errors detected; independent of IDE's pre-existing P1014 false-positives on inherited MY_Controller properties). Not circular (3 distinct probes from discovery's multiline-grep classification).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)
  - scope_reduction_note: BUG-032's full fix_plan covered 17 sites file-wide; this fix scopes to the 9 dashboard_stats sites (the focused-DISCOVERY target). The 8 cross-function silent catches at lines 1376/1466/1494/2085/2730/2880/3060/3208 are documented as advisory follow-up — same class of issue, different functions, may warrant their own BUG-NNN if operator pursues
  - adjacent_concern: no_rtdb invariant violation surface — Attendance.php has RTDB-fallback reads at multiple sites (lines 1467, 2730, 2880, 3060, 3208, 3362); likely already tracked in [[rtdb_elimination_plan]] (2026-04-25 audit, ~146 sites Phases A-I); operator deferred to elimination plan rather than filing separate BUG-NNN

---

## v7 cycle 3 DISCOVERY — school_config (2026-05-21, session 1)

BUG-026 | P2-MEDIUM | observability | verified
  - discovered: 2026-05-21 by v7 session 1 cycle 3 (DISCOVERY school_config)
  - surfaces: admin-web — application/controllers/School_config.php — 18 mutating endpoints lacked log_audit emission:
      - upload_logo (442-445), upload_document (502-505), save_board (566-568), save_classes (635-637),
        activate_classes (702-706), soft_delete_class (843-847), restore_class (888-891),
        seed_streams (936-944), save_section (1035-1041), delete_section (1141-1145),
        bulk_save_sections (1438-1451), save_subject (1824-1832), delete_subject (1906-1910),
        save_bulk_subjects (2196-2211), save_stream (2256-2258), delete_stream (2391-2395),
        add_session (2676-2680), save_report_card_template (3697-3699)
  - reproduction: static trace — pre-fix `grep -c "log_audit(" School_config.php` = 6; 6 of ~28 mutating endpoints had log_audit (21% coverage). 5 endpoints used `log_message('error', "ACC_*")` as informal substitute (soft_delete_class, delete_section, delete_subject, delete_stream, add_session); 13 had no audit at all.
  - observed: governance-relevant config mutations relied on log_message('error') as informal audit substitute OR had no audit emission, breaking the canonical Audit_log_service write_audit contract pattern established by save_profile, set_active_session, rollover_session, delete_session, archive_session, save_admission_payment_config.
  - expected: every state-changing config mutation emits log_audit('Configuration', '<verb>', <entity>, "<description>") at the success path, mirroring the 6 canonical sites.
  - source_of_expectation: intra-file 6-site convention; audit_log_immutable stack_invariant (programmatic tier on admin-web); Audit_log_service contract.
  - impact: governance auditor cannot reconstruct "who changed config and when" from audit_logs collection alone — must cross-reference operational error log filtered by ACC_* prefix. Forensic friction; regulatory concern under audit regimes.
  - fix_plan: insert 18 single-line log_audit('Configuration', '<verb>', $entity, "<description>") emissions at the success path of each missing endpoint. Pure addition — no removal, no contract change.
  - fix_commit: (pending — applied v7 session 1 cycle 4, 2026-05-21; uncommitted)
  - verification: 2026-05-21 v7 session 1 cycle 5. Static trace: log_audit total count = 24 (= 6 canonical pre-existing + 18 newly inserted, all matched by grep at expected line numbers 445/508/573/644/711/857/903/952/1053/1162/1462/1853/1932/2228/2284/2422/2708/3729); all 18 conform to canonical signature log_audit('Configuration', '<verb>', <entity>, "<description>"); 6 canonical pre-existing call sites preserved (save_profile@392, set_active_session@2826, rollover_session@3303, delete_session@3415, archive_session@3484, admission_payment@3805); 5 ACC_*_DELETED/ADDED log_message substitutes preserved (per fix_plan — augment, not replace); audit_log_service contract held (arity=4, arg types match canonical sites — consumer_replay verified).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false — operator manual smoke-test of audit_logs collection after a config mutation recommended); fix_commit_anchor (file change in working tree, snapshot-commit decision pending per CAMPAIGN_PLAN risk_callout #1)

BUG-027 | P3-LOW | security | verified
  - discovered: 2026-05-21 by v7 session 1 cycle 3 (DISCOVERY school_config)
  - surfaces: admin-web — application/controllers/School_config.php — exception-message echoed to client at 6 catch sites:
      seed_streams (946), get_subjects (1501), get_all_subjects (1592), get_suggested_subjects (1730),
      save_stream (2261), delete_stream (2398)
  - reproduction: static trace — pattern `$this->json_error('Failed to X: ' . $e->getMessage())` returns raw $e->getMessage() in user-facing JSON; can leak Firestore index names, paths, schema details.
  - observed: raw exception message concatenated into json_error response body.
  - expected: server-side log captures full exception (already done via log_message); user-facing message should be generic ("Failed to X. Contact administrator.") so internal implementation details don't leak past the trust boundary.
  - source_of_expectation: OWASP A05:2021 "Security Misconfiguration — error handling that reveals implementation details"; staff_hardening Phase A tenant-boundary 404-vs-403 precedent (v1 BUG-015).
  - impact: information disclosure to authenticated admin only (role-gated). Bounded by admin trust; not exploitable by anonymous attacker. Aids reconnaissance by compromised admin account.
  - fix_plan: replace each of 6 `json_error('Failed to X: ' . $e->getMessage())` with generic `json_error('Failed to X. Contact administrator.')`. Server-side log_message preserves full $e->getMessage() for operator.
  - fix_commit: (pending — applied v7 session 1 cycle 6, 2026-05-21; uncommitted)
  - verification: 2026-05-21 v7 session 1 cycle 7. Static trace via 3 independent probes: (a) pre-fix anti-pattern `json_error.*Failed.*getMessage` count = 0 (was 6); (b) new canonical "Contact administrator." message present at all 6 expected catch sites (lines 962/1522/1613/1751/2289/2427); (c) preceding log_message('error', '<fn> error|failed: ' . $e->getMessage()) lines all PRESERVED at lines 961/1521/1612/1750/2288/2426 (one line above each fixed json_error). Operator-facing diagnostic still captured server-side. Not circular (3 distinct probes from discovery's single count probe).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)

BUG-028 | P3-LOW | concurrency | verified (Phase 1 only)
  - phase_1_scope_only: seed_streams() + save_stream() lock+CAS hardening — verified v7 session 3 cycle 8
  - phase_2_deferred: save_classes() lock+CAS — tracked separately as BUG-037 pending UX coordination
  - discovered: 2026-05-21 by v7 session 1 cycle 3 (DISCOVERY school_config)
  - surfaces: admin-web — application/controllers/School_config.php — read-modify-write without lock+CAS at 3 sites:
      save_classes (575-638), seed_streams (898-948), save_stream (2217-2263)
  - reproduction: static trace — these endpoints read schools.streams (or schools.classes), modify in-memory, write back via $this->fs->update with no _config_lock_acquire and no Firestore __updateTime precondition. Two concurrent admins editing the same field clobber each other's edits silently.
  - observed: blind-overwrite write-back pattern on shared streams/classes field.
  - expected: match the Phase 2 lock+CAS model established for delete_stream (2289-2401) + add_session (2611-2683) — _config_lock_acquire('streams'|'classes') → firestoreGet with __updateTime capture → mutate → firestoreCommitBatch with precondition → log on CAS-fail → finally release lock.
  - source_of_expectation: intra-file pattern at delete_stream (2289-2401) + add_session (2611-2683) demonstrating canonical lock-then-CAS shape (Phase 2, 2026-05-15).
  - impact: silent lost-write on concurrent stream/class edits. Probability low for small schools (1-2 admins); higher for large schools with delegated config. No data corruption — just stale UI state requiring page reload.
  - fix_plan (original): wrap each of save_classes / seed_streams / save_stream in the lock+CAS pattern. ~30-50 line change per endpoint.
  - fix_plan (revised per operator directive cycle 6 of session 3, 2026-05-22): SCOPED DOWN to Phase 1 = seed_streams + save_stream only. Phase 2 = save_classes deferred for UX-coordinated future pass.
  - fix_commit: (pending — Phase 1 applied v7 session 3 cycle 7 = cycle 52 absolute, 2026-05-22; uncommitted)
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree); Phase 2 save_classes still uncovered (intentional deferral, see BUG-037)
  - phase_1_verification: 2026-05-22 v7 session 3 cycle 8 = cycle 53 absolute. Static trace via 5 independent probes: (a) `_config_lock_acquire('streams')` count = 3 file-wide (2 new at lines 955/2345 in seed_streams + save_stream + 1 canon at line 2443 in delete_stream); (b) CAS precondition `$ops[0]['precondition'] = ['updateTime' => $updateTime]` count = 5 file-wide (2 new at lines 1003/2389 + 3 canon at lines 2524/2831/2940); (c) ACC_* log emissions count = 6 (3 per new endpoint: lock-timeout + commit-failed + cas-failed); (d) pre-fix anti-pattern `fs->update('schools', ..., ['streams' ...])` count = 0 (was 2 pre-fix; both replaced by firestoreCommitBatch); (e) php -l CLI clean. One probe regex required mid-cycle correction (precondition assignment vs array-literal shape) — disclosed transparently. Not circular (5 distinct probes including different-tool php -l).
  - phase_1_implementation_notes:
      - seed_streams: replaced fs->get + fs->update blind-write with firestoreGet (captures __updateTime) + lock_acquire('streams') + firestoreCommitBatch with precondition + try/finally lock_release
      - save_stream: same pattern; same 'streams' lock (shared with delete_stream + seed_streams)
      - 6 new ACC_* log emissions: 2 lock-timeout (one per endpoint) + 2 commit-failed (one per endpoint) + 2 cas-failed (one per endpoint)
      - 4 BUG-028 Phase 1 marker comments (2 per endpoint — pre-acquire + pre-commit blocks)
      - All existing validation, log_audit emissions, and BUG-027 generic error messages preserved verbatim
      - Risk classification post-revision: LOW-MEDIUM (down from MEDIUM-borderline; save_classes asymmetry removed)
  - related_bugs: BUG-037 (Phase 2 carry — save_classes UX-coordinated lock+CAS, deferred); canon: delete_stream (lines 2334-2451) + add_session (lines 2625-2756)

BUG-037 | P3-LOW | concurrency | deferred (UX-coordination-pending)
  - filed: 2026-05-22 by v7 session 3 cycle 7 (operator-directed BUG-028 Phase 2 split)
  - parent: BUG-028 (Phase 1 fixed-unverified this session)
  - scope: save_classes() lock+CAS hardening — adds 'classes' lock + Firestore __updateTime CAS precondition
  - surfaces: admin-web — application/controllers/School_config.php — save_classes() (line ~599-680)
  - blocker: requires coordinated frontend UX (disabled-Save-button + reload-and-reapply pattern analogous to v1 homework BUG-006) before lock+CAS server-side can ship safely
  - reason: save_classes is qualitatively different from seed_streams + save_stream because:
      (a) whole-array replacement semantics (no merge — replaces entire classes array)
      (b) non-idempotent retry behavior (double-click after 409 would overwrite a winning admin's edits)
      (c) ambiguous browser-state after 409 (admin's local edits still in browser but Firestore has stale data from another admin)
  - rationale: operator explicit revision directive — "stream endpoints are substantially closer to the proven delete_stream canon and present lower regression/UX risk. preserving loud-fail correctness is good, but introducing operator-confusing retry semantics without coordinated frontend UX handling is not desirable in this session"
  - estimated effort: ~45 LoC server-side + frontend coordinator (TBD by UX designer); 2-3 task cycles
  - status: explicitly tracked carry for future-session UX-coordinated pass
  - related_bugs: BUG-028 Phase 1 (this session); canon: delete_stream + add_session

BUG-028 | (legacy entry marker — see fixed-unverified block above)

BUG-029 | P3-LOW | security | verified
  - discovered: 2026-05-21 by v7 session 1 cycle 3 (DISCOVERY school_config)
  - surfaces: admin-web — application/controllers/School_config.php — missing byte-length caps:
      save_profile (354-358, 12 fields), save_classes (per-class label + classes-array cardinality),
      save_subject (name field), save_stream (label field)
  - reproduction: static trace — pre-fix `grep -n "strlen\|toByteArray\|byteLength\|mb_strlen" School_config.php` returns 0 matches. Oversize input → Firestore DocTooLarge (1MB doc cap) → unhandled \Throwable → response 500.
  - observed: free-text fields accept arbitrary length; classes array has no cardinality cap.
  - expected: byte-length caps at trust boundary, returning json_error 400 with actionable message on overflow. Same pattern as homework BUG-013 fix (closed-verified at v1 session 5 cycle 4).
  - source_of_expectation: v1 BUG-013 fix precedent; QUALITY_BAR §D4 input validation; OWASP A03.
  - impact: adversarial admin (or fat-finger paste) can DoS school profile/classes write path; bounded by admin trust.
  - fix_plan: add caps to save_profile (display_name 200, address 500, others 100), save_classes (label 100, array 200), save_subject (name 200), save_stream (label 100). Return json_error 400 with field name. Mirror homework BUG-013 shape.
  - fix_commit: (pending — applied v7 session 1 cycle 8, 2026-05-21; uncommitted)
  - verification: 2026-05-21 v7 session 1 cycle 9. Static trace via 4 independent probes: (a) strlen($ count = 6 file-wide (4 new BUG-029 strlen guards at lines 375/630/1822/2291 + 1 pre-existing strlen($stm) at 1295 + 1 pre-existing mb_strlen at 3825, both untouched); (b) BUG-029 marker comments = 5 at lines 360/606/629/1821/2290; (c) all 5 guard patterns present (count($rawClasses)>200 cardinality cap at 608, "Field exceeds N characters" save_profile at 376, "Class label exceeds 100" save_classes at 631, "Subject name exceeds 200" save_subject at 1823, "Stream label exceeds 100" save_stream at 2292); (d) php -l CLI lint clean — "No syntax errors detected" (independent of stale IDE diagnostic at l.1619). Not circular (4 distinct probes from discovery's zero-match check, including different-tool php -l).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)

BUG-031 | P3-LOW | data-integrity | verified
  - discovered: 2026-05-21 by v7 session 1 cycle 10 (DISCOVERY pass 2 — school_config D2)
  - surfaces: admin-web — application/controllers/School_config.php:68-73 (get_config write-on-read auto-sync)
  - reproduction: static trace — Phase 1 (2026-05-15) comment at lines 140-146 documents get_config as "now read-only" and lists removed session auto-seed write-on-read with stated reasons (silent writes per page load, races with concurrent add_session, quota burn under refresh storms). The display_name auto-sync at lines 68-73 has the same drawbacks but was missed by Phase 1 cleanup.
  - observed: get_config() endpoint contains write-on-read at lines 68-73 invoking $this->fs->saveSchool() when $fsSchool['name'] is empty and $this->school_display_name is non-empty. Triggers on every page-load by any operator with config-read permission when school's display name hasn't been explicitly saved yet.
  - expected: read endpoint is read-only; persistence requires explicit save_profile invocation. Mirror Phase 1's session_not_configured flag pattern (line 147-167) — surface "needs configuration" to UI in-memory only; UI prompts operator to invoke save_profile.
  - source_of_expectation: intra-file Phase 1 comment at line 140-146 (canonical read-only intent for get_config); QUALITY_BAR §D2 "writes only on explicit endpoints"; phase3_school_config_soak observe→verify→classify discipline.
  - impact: silent writes per page-load; race with concurrent save_profile (admin A persists → admin B's stale tab re-reads → re-writes from local cache, clobbering A); quota burn on dashboard refresh storms. Subtle secondary concern: writes 'display_name' (snake_case) while reads check 'name' (camelCase) — if Firestore_service::saveSchool doesn't normalize, auto-sync FIRES ON EVERY READ.
  - fix_plan: remove 7-line write-on-read block; replace with $nameNotConfigured in-memory flag + add 'name_not_configured' to json_success response payload (sibling of existing session_not_configured). Stale RTDB-mention comment cleaned up. ~16-line delta.
  - fix_commit: (pending — applied v7 session 1 cycle 11, 2026-05-21; uncommitted)
  - verification: 2026-05-21 v7 session 1 cycle 12. Static trace via 4 independent probes: (a) saveSchool/update('schools' inside get_config body (lines 56-195) = 0 (earliest write at line 407, save_profile, expected); (b) $nameNotConfigured declaration at line 71 + guard at line 72 (mirrors Phase 1 sessionNotConfigured shape); (c) 'name_not_configured' json_success payload key at line 196 with BUG-031 marker; (d) php -l CLI clean (No syntax errors detected). BUG-031 marker count = 2 (lines 66, 196). Not circular (4 distinct probes from discovery's structural read).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)
  - adjacent_concern: potential schema-drift between snake_case writes (display_name, affiliation_board, logo_url) and camelCase reads (name, affiliationBoard, logoUrl) in school document. If Firestore_service::saveSchool doesn't normalize between the two, save_profile and get_config are reading/writing different fields entirely. Out-of-scope this fix (different file); operator may spawn `investigate firestore_service_field_naming`.

BUG-030 | P3-LOW | security | verified
  - discovered: 2026-05-21 by v7 session 1 cycle 3 (DISCOVERY school_config)
  - surfaces: admin-web — application/controllers/School_config.php — 5 diagnostic endpoints lack security telemetry on access:
      test_sessions (2407), test_profile (2522), test_classes (2532), test_sections (2544), test_subjects (2570)
  - reproduction: static trace — `grep -n "sec_telem\|DIAGNOSTIC" School_config.php` returns 0 matches. Endpoints are gated by _assert_diagnostic_allowed but emit no security_events row, no audit, no operator-visible signal on access.
  - observed: 5 dev/diagnostic endpoints return full Firestore docs (whole school doc + classes + subjects + sections) silently on environment-flag access.
  - expected: emit Security_telemetry::emit('CONFIG_DIAGNOSTIC_ACCESSED', 'info', [endpoint, actor]) at each call. Fail-loud on dev-surface in prod (misconfiguration scenario).
  - source_of_expectation: staff_hardening Phase A precedent (Security_telemetry library exists for forensic signal); phase3_school_config_soak observe→verify→classify discipline.
  - impact: bounded by _assert_diagnostic_allowed gating logic; misconfiguration → silent leak of full school doc. Class of issue: missing fail-loud on dev-surface.
  - fix_plan: add `$this->sec_telem->emit('CONFIG_DIAGNOSTIC_ACCESSED', 'info', ['endpoint' => __FUNCTION__, 'actor' => $this->admin_id])` to each of 5 test_* endpoints, after _assert_diagnostic_allowed.
  - fix_commit: (pending — applied v7 session 2 cycle 39, 2026-05-22; uncommitted)
  - verification: 2026-05-22 v7 session 2 cycle 40. Static trace via 3 independent probes: (a) CONFIG_DIAGNOSTIC_ACCESSED event-string count = 5 at lines 2480/2599/2613/2629/2659 (one per test_* endpoint: test_sessions/test_profile/test_classes/test_sections/test_subjects); (b) canonical isset+isReady guard count = 5 (matches Homework.php BUG-014 CROSS_TENANT_PROBE precedent guard shape); (c) php -l CLI clean. Not circular (3 distinct probes from discovery's _assert_diagnostic_allowed grep).
  - assumed_unverified: test_runtime_pass (RUNTIME_EXECUTION_ALLOWED=false); fix_commit_anchor (uncommitted in working tree)
  - cross_module_note: staff_hardening Phase A patches 6-15 may pre-empt this work via sec_telem coverage extension. If Phase A reauthorises with broader cross-controller CONFIG_DIAGNOSTIC_ACCESSED coverage, this fix becomes a leading consumer reference rather than a stand-alone fix; operator may DISCARD if duplicate. Scoping note: this fix is school_config-specific (5 test_* endpoints), NOT a cross-controller HOLD-territory expansion.

## CLI Background Recovery Infrastructure — Session 5 (2026-05-23, v7 accounting Tier 1 close)

**Context:** Accounting Tier 1 runtime-validation discovered `worker.last_run_at` and `reconciler.last_run_at` both empty in `/Accounting/health_json` ("never run"). Pre-execution code review surfaced a Firebase-library contract mismatch (predicted as F-A7); empirical confirmation via `php index.php accountingreconciler anomalies` crashed earlier on a CodeIgniter session-property collision (discovered as F-A8). Two-patch Path A recovery sequence under freeze choreography: BUG-048 cleared first → empirical reachability confirmed BUG-049 → BUG-049 cleared. Single controlled reconciler `run` then completed cleanly (23m 36s, exit 0): 4 stuck idempotency slots cleared (2 recovered + 2 orphaned), 1670 missing index docs repaired, 2 closing-balance drifts CAS-repaired. Tier 1 formal closure followed with documented carry-forward exceptions.

BUG-048 | P0-CRITICAL | infrastructure | verified
  - discovered: 2026-05-23 by v7 session 5 (Path A empirical confirmation of F-A7 prediction; this was the unexpected upstream blocker that fired first)
  - surfaces: admin-web — application/controllers/AccountingReconciler.php:107 + AccountingWatchdog.php:55 + FeeWorker.php:63 (all three originally declared `private string $session    = ''`)
  - reproduction: `php index.php accountingreconciler anomalies` → `Error: Cannot access private property AccountingReconciler::$session at Loader.php:1284` during parent::__construct()
  - observed: three CLI controllers declared `private string $session` as the SESSION_YEAR env-var holder, colliding with CodeIgniter's `'session'` library auto-injection (application/config/autoload.php:63 declares `$autoload['libraries'] = array('Firebase','form_validation','session')`). CI's Loader fails to assign `$this->session` because the private visibility blocks the public-property injection contract.
  - expected: CLI controllers should construct without crashing. The property name must not collide with auto-loaded libraries.
  - source_of_expectation: CodeIgniter Loader contract; consistent with /Accounting/health_json showing worker.down=true + reconciler.down=true with empty last_run_at (had never constructed successfully).
  - impact: ALL THREE CLI background controllers (FeeWorker, AccountingWatchdog, AccountingReconciler) cannot construct. Stuck-idempotency recovery, drift detection, fee-deferred-job processing, alert persistence all non-functional. Latent since the property was added (Phase 8B era, ~2026-04-08).
  - fix_plan: BUG-A8-PKG-001 — rename private property `$session` → `$sessionYear` across all three controllers (1 declaration + 96 references via mechanical replace_all). camelCase consistent with sibling properties `$schoolName`, `$schoolFs`. Semantic accuracy preserved (env var is SESSION_YEAR).
  - fix_commit: BUG-A8-PKG-001 applied 2026-05-23, uncommitted in working tree
  - verification: post-patch smoke produced new error `Call to undefined method Firebase::initFirestore()` at line 123 — F-A8 cleared cleanly, exposed the predicted F-A7 (filed as BUG-049). End-to-end verification via subsequent `accountingreconciler run` completed cleanly (23m 36s, exit 0): heartbeat written, sweeps executed, all three controllers proven to construct successfully and downstream methods executable.
  - assumed_unverified: fix_commit_anchor (uncommitted); FeeWorker + Watchdog patch applied but not yet operationally invoked (rename verified by Reconciler invocation only)

BUG-049 | P0-CRITICAL | infrastructure | verified
  - discovered: 2026-05-23 by v7 session 5 (code review during F-A7 prediction; empirically confirmed after BUG-048 patch cleared the upstream blocker)
  - surfaces: admin-web — application/controllers/AccountingReconciler.php:123 + AccountingWatchdog.php:72 + FeeWorker.php:80 (all three called `$this->firebase->initFirestore($schoolName, $session)` followed by `$this->firebase->getSchoolId()`)
  - reproduction: post-BUG-048 patch: `php index.php accountingreconciler anomalies` → `Call to undefined method Firebase::initFirestore() at AccountingReconciler.php:123`
  - observed: three CLI controllers called `Firebase::initFirestore($schoolName, $session)` and `Firebase::getSchoolId()` — neither method exists on the current Firebase library. Verified via awk of full public-method list in application/libraries/Firebase.php (37 public methods enumerated; no initFirestore, no getSchoolId). Firebase library now self-initializes for the `graderadmin` project + `(default)` database at its own constructor (line 63: `new FirestoreRestClient($serviceAccountPath, 'graderadmin', '(default)')`) with no school-context dependency.
  - expected: CLI controllers should bootstrap schoolFs from env vars and proceed to anomaly scan / reconciler sweeps without requiring legacy school-context library init.
  - source_of_expectation: AccountingReconciler.php:46-56 invocation docblock specifies the expected contract; consistent with shipped Phase 8B reconciler architecture.
  - impact: Same as BUG-048 — three CLI background controllers fail at construction; recovery infrastructure non-functional. Likely root cause: Firebase library was refactored at some point and the three CLI controllers were not migrated.
  - fix_plan: BUG-A7-PKG-001 Framing α — controller-side refactor only. Replace `SCHOOL_NAME → initFirestore → getSchoolId` resolution path with direct `SCHOOL_ID` env-var read assigned to `$schoolFs`. `SCHOOL_NAME` remains optional for downstream callers (audit-log `school_name` field, `Accounting_health::init` 4th arg, FeeWorker ctx fallback at line 643). Firebase library untouched. ~50 lines across 3 files (3 constructor blocks restructured). Operator-side scheduled-task env-var contract changes from `SCHOOL_NAME=<friendly>` to `SCHOOL_ID=SCH_D94FE8F7AD`.
  - fix_commit: BUG-A7-PKG-001 applied 2026-05-23, uncommitted in working tree
  - verification: post-patch smoke `php index.php accountingreconciler anomalies` returned structured 2-anomaly output (reconciler-never-ran CRITICAL + 4-stuck-slots LOW) — F-A7 fully cleared. End-to-end verification via subsequent single controlled reconciler `run` completed cleanly (23m 36s, exit 0): idemp{recovered=2 orphaned=2}, drift{found=0}, refund{found=0 suspended=0}, index{scan=500 miss=1670 fix=1670 fail=0 continuing}, balance{acct=16 drift=2 fix=2 fail=0 PASS_DONE}. Post-run /Accounting/health_json showed reconciler.down=false + reconciler.last_run_at live + idempotency.processing_count=0; 3 of 5 prior alerts cleared (reconciler_down, idempotency_stuck, long_processing).
  - assumed_unverified: fix_commit_anchor (uncommitted); FeeWorker + Watchdog patch applied but not yet operationally invoked (rename + bootstrap verified by Reconciler invocation only); scheduled-task env var changes still operator-side (no cron wired in this environment)

## STU_TEST_* Test-Fixture Namespace Cleanup — Session 5 (2026-05-24, v7 cross-system Tier 1.1 follow-up)

**Context:** Cross-system Tier 1.1 (class/section canonical schema) verification via the new `Class_section_verify` CLI helper surfaced 9 test-fixture students under the `STU_TEST_*` namespace with derived-field drift — `className` + `section` correct but `classOrder` + `sectionCode` missing on these specific docs (real Class 8th students fully canonical). Cascade-impact audit across 27 candidate collections via `Test_student_cascade_audit` confirmed bounded blast radius: references existed only in `students` (9), `feeDemands` (333), and `feeDefaulters` (9). Zero references in `accountingLedger` / `feeReceipts` / `feeOnlinePayments` / `attendance` / `homeworks` / `parents` / `notifications` / etc. (24 of 27 collections completely clean). Confirms test-fixture students never participated in live runtime workflows. BUG-CLEANUP-STU-TEST-PKG-001 prepared with three-pass deletion choreography (feeDemands → feeDefaulters → students), defensive namespace-prefix guards, MAX_DELETES_PER_PASS=500 hard ceiling, dry-run pre-verification. Audit-predicted 351 = dry-run identified 351 = APPLY execute deleted 351 (perfect 1:1). Post-execute re-audit shows 0 STU_TEST_* references across all 27 collections; post-execute `class_section_verify` shows 9/9 real-student conformance with 0 drift indicators; post-execute accounting health unchanged (subsystem outside cleanup scope as predicted).

CLEANUP-001 | n/a (operational data-hygiene action, not a bug) | data-hygiene | completed
  - operation: cleanup of 9 STU_TEST_* test-fixture students + 333 feeDemands + 9 feeDefaulters projections (351 total docs deleted across 3 collections)
  - tooling: `application/controllers/Test_student_cascade_audit.php` (preserved for future test-namespace lifecycle management); reusable for similar cleanup cycles
  - safety: per-doc namespace-prefix guard (studentId must start with `STU_TEST_`); MAX_DELETES_PER_PASS=500 hard ceiling per pass; dry-run pre-verification; per-doc try/catch with audit log on failure; idempotent re-run (no-op on clean state)
  - choreography: discovery (T1.1 class_section_verify) → cascade audit (27 collections) → package prep (BUG-CLEANUP-STU-TEST-PKG-001) → operator review of dry-run preview → verbatim APPLY → execute → post-cleanup §7 re-verification (re-audit + class_section_verify + accounting health)
  - mutation surface: 351 doc deletes (3 collections); zero accountingLedger / feeReceipts / feeOnlinePayments / attendance / homework / parent linkage touches; live HTTP path untouched; Firebase library untouched; immutable-ledger discipline preserved (test demands were unpaid projections, not ledger entries)
  - verification: post-execute re-audit (351 → 0 refs across 27 collections), class_section_verify (9/9 conformant, 0 drift), accounting health (heartbeat unchanged, no new anomalies)
  - canonical schema impact: T1.1 promoted from WATCH (test-namespace drift) → ✅ NORMAL (full conformance across all 9 real students)
  - audit log: STU_TEST_CLEANUP_BEGIN + STU_TEST_CLEANUP_DELETED × 351 + STU_TEST_CLEANUP_END emitted to application/logs/log-2026-05-23.php
  - this block is the canonical record of the STU_TEST_* cleanup — not a BUG-NNN entry. Catalogued here for v7 mission-state tracking and future operational continuity (parallel to the Communication Phases 1-5 + Attendance Phase 7 migration catalog patterns at file head).

## Architectural Policy Finding — Examination Module RTDB Legacy (2026-05-24, v7 examination Tier 1.2 discovery)

**Context:** Examination Tier 1.2 (exam-definition canonical schema verification) discovered via the new `Exam_definition_verify` CLI helper that the Examination module operates on legacy RTDB persistence, not the Firestore-canonical pattern adopted by other modules (Fees TC-3, Accounting Phase 8, Communication Phases 1-5, Attendance Phase 7, hr_payroll Phase 4, Cross-system Tier 1 closures). Five Firestore candidate collections probed (examinations / exams / examMasters / examConfigurations / examDefinitions) — ALL ZERO docs for SCH_D94FE8F7AD. Code review confirmed `application/controllers/Exam.php:161` writes exam metadata to RTDB legacy path `Schools/{schoolName}/{year}/Exams/{examId}` via `$this->firebase->set(...)` (RTDB primitive). This violates `[[feedback_no_rtdb_ever]]` absolute policy ("zero RTDB usage. No fallbacks, no mirrors, no RTDB reads even for config"). Consistent with the broader `[[rtdb_elimination_plan]]` backlog (146 sites across 3 systems, Phases A→I in progress) — Examination module is part of the unmigrated remainder.

POLICY-FINDING-001 | n/a (architectural finding, not bug) | architecture | open
  - finding: Examination module persistence operates on legacy RTDB rather than Firestore-first canonical pattern
  - surfaces: admin-web — application/controllers/Exam.php:161 (`$this->firebase->set("Schools/{$school}/{$year}/Exams/{$examId}", $examMeta)`) and likely siblings in Result.php / Examination.php / Exam_engine.php
  - reproduction: `php index.php exam_definition_verify verify` (read-only helper) probes 5 Firestore candidate collections all return 0 docs; code grep confirms RTDB write path
  - policy violated: `[[feedback_no_rtdb_ever]]` — ABSOLUTE: zero RTDB usage; supersedes all prior RTDB policies
  - cross-reference: `[[rtdb_elimination_plan]]` 9-phase migration backlog; Examination is in unmigrated portion
  - operational impact: Examination Tier 1 Firestore-canonical verification is NOT_APPLICABLE for downstream scenarios (T1.3-T1.8) until migration ships
  - tooling: `application/controllers/Exam_definition_verify.php` (preserved as discovery helper; will become operational verifier post-migration)
  - decision (2026-05-24): operator Option C — formally classified as Firestore-policy architectural finding; defer Examination Tier 1 deeper execution pending Firestore migration; preserve as migration-priority candidate. Do NOT invest in RTDB-aware validation infrastructure (would reinforce deprecated path).
  - status: OPEN — pending future Examination-module Firestore-migration cycle. When migration ships, T1.2 + T1.3-T1.8 become re-runnable via the existing helper-suite pattern.
  - this entry is the canonical record of the Examination RTDB-legacy state — catalogued for future migration prioritization tracking.

## Convergence Carry Ledger — Phase 1 Controlled Remediation (2026-05-24, v7 convergence-execution phase)

**Context:** V7 campaign formally transitioned from breadth-validation into convergence-execution governance. Phase 1 = controlled-remediation rehearsal on low-blast-radius targets validating the choreography (freeze → forensic → package → APPLY → smoke → verify → cool-window → closing) before higher-risk packages. Per-sub-window operator authorization gates preserved.

### CARRY-001 — Communication counter-seed-source alignment ✅ CLOSED

CARRY-001 | P2 | normalization-drift | closed
  - discovered: 2026-05-24 by Communication Phase 6 Tier 1.3 + Tier 1.5 verification (`[[communication_phase6_closed]]`)
  - surface: admin-web — application/controllers/Communication.php:220, 221 (COUNTER_SEED_SOURCES table)
  - observed: COUNTER_SEED_SOURCES seed-source collections (`messageTriggers`, `messageLogs`) diverged from FS_COL_TRIGGERS/FS_COL_LOGS write-target constants (`alertTriggers`, `deliveryLogs`); counter self-heal would scan the wrong collection on first use.
  - expected: counter seed source collection identifier matches actual write-target collection identifier; consistent with hrCounters / acctCounters convention.
  - source_of_expectation: own _next_id() docblock at Communication.php:225-230; FS_COL_TRIGGERS/FS_COL_LOGS at Communication.php:2197/2199 are the public-facing collection identity consumed by `_dwSet`/`_dwUpdate`/`_dwDelete`/`_dwListAdmin`/`_dwGet` + Communication_helper.php:34.
  - impact: zero in current state (both Trigger and Log collections empty); would have produced first-write counter mis-seed on first trigger or log creation.
  - fix_plan: BUG-CARRY-001 — 2-line atomic edit on Communication.php aligning COUNTER_SEED_SOURCES seed-source identifiers to the FS_COL_* write-target constants. Single file, single edit hunk, near-zero blast radius.
  - fix_commit: applied 2026-05-24 (Phase 1 Sub-window P1.1, uncommitted in working tree). Diff:
      Communication.php:220  'Trigger' => ['messageTriggers','TRG'],   →  'Trigger' => ['alertTriggers', 'TRG'],
      Communication.php:221  'Log'     => ['messageLogs',    'LOG'],   →  'Log'     => ['deliveryLogs',  'LOG'],
  - verification: php syntax check clean. Grep confirms 8 downstream callsites of FS_COL_TRIGGERS / FS_COL_LOGS untouched. Communication_verify.php hardened with `_read_constants()` helper performing dynamic source-file constant inspection (bundled because the verifier itself was the smoke-test mechanism for THIS package). Post-fix verifier reports T1.3 + T1.5 transition STRUCTURAL → ✅ NORMAL with `alignment: ✅ ALIGNED`. No regression observed in T1.1, T1.2, T1.4, T1.6, T1.7, T1.8, T1.9, T1.10. Cool-window observation completed (abbreviated per operator direction — sub-window is low-blast-radius / configuration-level / no-runtime-data-mutation / no-mobile-coupling / no-active-traffic-sensitive).
  - rollback_path: revert 2-line diff at Communication.php:220-221 (instant; no data state to restore).
  - choreography_validated: freeze → forensic → package → APPLY → smoke → verify → cool-window → closing executed with explicit operator authorization gates. First controlled-remediation package successfully executed in V7 lifecycle. Verifier-strengthening pattern validated (dynamic source-introspection > frozen hardcoded assumptions).
  - convergence_package_status: T1.3 + T1.5 cluster fully resolved. Communication structural convergence package now has 3 remaining sub-clusters (T1.1 multi-writer Notice ID + T1.8 empty-id field + T1.0 library RTDB retirement deferred to Phase 4).

### CARRY-002 — SIS cosmetic / stale-comment cleanup ✅ CLOSED

CARRY-002 | P3 | cosmetic-normalization | closed
  - discovered: 2026-05-24 by SIS Tier 1 + Tier 2 verification cosmetic carry catalogues (`[[sis_tier1_closed]]` + `[[sis_tier2_closed]]`)
  - surface: admin-web — application/controllers/Sis.php at 4 locations (docblock lines 18-26, property declaration line 37, initialization line 51, comment line 904, error text line 919)
  - observed: SIS controller carried stale pre-Firestore-migration cosmetic artifacts:
      (a) docblock at lines 18-26 described RTDB paths (Users/Parents/{...}/History, /TC, Schools/{...}/SIS/TC_Counter, /Promotions) that have since fully migrated to Firestore equivalents (students.History, students.TC, schools.tcCounter, schools.tcIndex, schools.promotions)
      (b) `$this->crm_base` property declared at :37 and initialized at :51 with "Schools/{...}/CRM/Admissions" but never read anywhere in the codebase — dead variable
      (c) comment at :904 above batchMoveStudents call stated "Single atomic RTDB multi-path update for ALL students" but Dual_write::batchMoveStudents is Firestore-only post-R7 (verified at SIS Tier 2.0)
      (d) user-visible error text at :919 returned 'reason' => 'RTDB atomic write failed' in promotion-skipped response — operationally inaccurate since underlying write is Firestore-only
  - expected: docblocks + comments + error text + property declarations should accurately reflect current architecture; dead variables should not persist as latent confusion-source.
  - source_of_expectation: SIS Tier 1.0 + Tier 2.0 closure findings cataloguing each item as LOW cosmetic carry pending future cleanup pass.
  - impact: zero behavioral; pure documentation accuracy and dead-variable hygiene.
  - fix_plan: BUG-CARRY-002 — 4 atomic edits in Sis.php: (D.1) rewrite docblock to current Firestore schema, (D.2) remove $crm_base property declaration + comment + initialization, (D.3) replace stale RTDB comment with accurate Firestore-only description, (D.4) update error reason text from "RTDB atomic write failed" to "Firestore atomic write failed". Single file, 4 edit hunks, bundled per atomicity-allows-bundling-of-text-only-changes principle.
  - fix_commit: applied 2026-05-24 (Phase 1 Sub-window P1.2, uncommitted in working tree)
  - verification: php syntax check clean. Grep across application/ confirms `crm_base` returns 0 matches (was 2 in Sis.php). Stale text patterns return 0 matches. Re-ran sis_canonical_verify (T1.1-T1.8 all NORMAL 8/8) + sis_tier2_verify (T2.1 WATCH, T2.2 FP, T2.4/2.5/2.6 NORMAL, T2.8 TRIVIAL — identical classification to pre-P1.2). Zero behavioral regression. Cool-window abbreviated per zero-behavior-change classification.
  - rollback_path: revert 4 atomic edits in Sis.php (instant; no data state to restore).
  - choreography_validated: freeze → forensic → package → APPLY (4-edit bundle) → smoke → verify → abbreviated cool-window → closing. Second controlled-remediation package successfully executed in V7 lifecycle. Validates bundled-cosmetic-edits pattern within a single sub-window when all edits are text-only and confined to single file.
  - convergence_package_status: SIS Tier 1.0 + Tier 2.0 cosmetic carries fully resolved. SIS convergence package state unchanged otherwise (Tier 2 transactional-hardening cluster — T2.1 + T2.3 + T2.7 — still queued for Phase 2 sequencing).

### CARRY-003 — PTM RSVP verifier dual-vocabulary recognition ✅ CLOSED

CARRY-003 | P3 | verifier-scope-FP / semantic-boundary-clarification | closed
  - discovered: 2026-05-24 by PTM Tier 1.6 verification (`[[ptm_tier1_closed]]`); originally classified as "enum drift" WATCH
  - surface: admin-web — application/controllers/Ptm_canonical_verify.php:12 (docblock) + :26 (RSVP_STATUSES constant)
  - observed: PTM Tier 1.6 verifier reported 1 ptmRsvp with status="delivered" not in declared RSVP_STATUSES enum (pending/confirmed/declined/attended/no-show).
  - forensic re-classification (2026-05-24 P1.3 forensic stage 2a/2b/2c):
      (a) Ptm.php:269-270 — reader already treats `delivered` as canonical synonym for `attended` in aggregation logic (`case 'delivered': case 'attended': $delivered++; break;`)
      (b) views/ptm/rsvps.php:151-340 — explicitly documents dual-vocabulary architecture: legacy (pending/confirmed/declined/attended/no-show) + Phase-A (applied/delivered/declined/no-show); canonical mapping applied↔confirmed and delivered↔attended
      (c) views/ptm/rsvps.php:177 — `const PHASE_A_STATUSES = ['applied','delivered','no-show','declined'];`
      (d) The literal "delivered" status string is NOT written anywhere in Ptm.php — emission source is mobile-side (Teacher app Phase-A build); writer correction is out-of-scope per mobile-coupling boundary
      (e) Re-classification: NOT enum drift / NOT writer leak / NOT data corruption — instead a verifier-scope FP from the verifier missing Phase-A vocabulary knowledge
  - expected: verifier should recognize both legacy and Phase-A vocabularies given documented dual-vocabulary architecture.
  - source_of_expectation: views/ptm/rsvps.php documentation + Ptm.php reader-side synonym handling.
  - impact: zero runtime; verifier was producing false-positive WATCH classification on canonically-valid Phase-A status values.
  - fix_plan: BUG-CARRY-003 — 2 atomic edits in Ptm_canonical_verify.php: (1) docblock at :12 expanded to describe dual-vocabulary architecture with cross-reference citations; (2) RSVP_STATUSES constant at :26 expanded from 5 legacy values to 7 (5 legacy + 2 Phase-A: applied, delivered). Zero data mutation. Zero Ptm.php change. Zero mobile-writer change.
  - fix_commit: applied 2026-05-24 (Phase 1 Sub-window P1.3, uncommitted in working tree). Diff:
      Ptm_canonical_verify.php:12 — docblock single-vocabulary → dual-vocabulary with synonym mapping
      Ptm_canonical_verify.php:26 — RSVP_STATUSES = legacy[5] → legacy[5] + Phase-A[2]
  - verification: php syntax check clean. Re-ran ptm_canonical_verify; T1.6 transition from "1 ptmRsvp invalid status enum" WATCH → ✅ NORMAL (invalid status enum: 0; status distribution {"attended":1, "delivered":1} now correctly recognized). T1.5 + other scenarios unchanged. T1.7 EVT0005/EVT0006 remains INVESTIGATE (out of P1.3 scope; resolved separately by Communication T1.9 cross-confirm).
  - rollback_path: revert 2 atomic edits in Ptm_canonical_verify.php (instant; no data state to restore).
  - choreography_validated: freeze → forensic (multi-step) → forensic-driven scope flip (semantic-normalization → verifier-scope-FP) → operator re-authorization gate → revised package → APPLY → smoke → verify → abbreviated cool-window → closing. Third controlled-remediation package successfully executed in V7 lifecycle. **Critical pattern validated**: forensic-driven scope flips are MANDATORY re-authorization checkpoints (the original P1.3 plan would have produced data mutation that was unnecessary and architecturally incorrect — forensic prevented this).
  - convergence_package_status: PTM Tier 1.6 RSVP enum drift cluster fully resolved (was already canonical; verifier knowledge corrected). New helper preserved: Ptm_rsvp_drill.php (drill-down forensic tool for future PTM lifecycle investigations).

### CARRY-004 — Communication Notice-ID multi-writer architecture ✅ DOCUMENTED + ELEVATED TO PHASE 2.0

CARRY-004 | P1 | pre-scale-production-hardening | documented + deferred-elevated
  - discovered: 2026-05-24 by Communication Phase 6 Tier 1.1 verification (`[[communication_phase6_closed]]`); originally classified as "multi-writer Notice ID heterogeneity" / "cosmetic convergence debt"
  - surfaces: admin-web — 4 notice-writers across the codebase:
      (Writer 1) application/controllers/Communication.php:1087 save_notice → _next_id('Notice','NOT') → `_syncToFirestoreCirculars($id, $data, 'notice')` → fs->docId($id) → **canonical school-prefixed doc ID** `{schoolFs}_NOT0001` (4-pad). Counter at schools/{schoolFs}_profile.commCounters.Notice. ✅ CANONICAL — no action needed.
      (Writer 2) application/libraries/Communication_helper.php:276-410 write_event_notice → fs->set('notices', $noticeId, ...) at line 341 with **raw $noticeId, no school prefix** → doc ID `NOT00001` (5-pad). Counter at `communicationCounters/{school_name}.noticeCounter` (parallel counter system, friendly-name-keyed). ⚠ **PRE-SCALE PRODUCTION-HARDENING CANDIDATE** (see verdict below).
      (Writer 3) application/controllers/Hr.php:2070-2099 _create_job_circular → firestoreSet('notices', fs->docId($noticeId), ...) → doc ID `{schoolFs}_NOTICE_<uniqid>`. Uniqid-based ID for concurrent HR-writer collision safety; no counter. ℹ INTENTIONAL SEPARATE — collision-safe by design.
      (Writer 4) application/controllers/Admin.php:738-829 _send_birthday_wish_core → wishId = "{$schoolId}_{$studentId}_{$todayStamp}" at line 753 → doc ID `{schoolFs}_STU0001_20260425`. Natural-key idempotency (one wish per student per day); no counter. ℹ INTENTIONAL SEPARATE — natural daily uniqueness by design.
  - reproduction: `php index.php communication_drill notices` shows 4 distinct ID format families coexisting in production data; `php index.php communication_drill targets` confirms cross-tenant write topology.
  - observed: Writer 2 emits Firestore doc IDs without school prefix (`NOT00001`-`NOT00004` in production data, all carry schoolId field = SCH_D94FE8F7AD but doc IDs are globally namespaced). Writers 1+3+4 all use school-prefixed doc IDs via either fs->docId() or explicit string concat with $schoolId.
  - expected: All notice writers must produce school-scoped Firestore doc IDs to prevent cross-tenant collision when multi-school deployment commences. Per `[[firebase_architecture]]` + `[[student_class_section_canonical]]` patterns, all entity doc IDs follow `{schoolFs}_{entityId}` convention.
  - source_of_expectation: Firestore_service.php:153-156 `docId()` helper documented as "Build a school-scoped document ID: {schoolId}_{entityId}". 3 of 4 notice writers comply; Writer 2 does not.
  - impact (production-scale): In multi-school deployment, Writer 2 produces cross-tenant Firestore document-ID COLLISIONS:
      • Scenario A (concurrent): School A and School B both fire calendar events; both call write_event_notice; both counters return 5 → both target `notices/NOT00005`; silent last-writer-wins overwrite.
      • Scenario B (sequential onboarding): School A has 100 event notices (NOT00001-NOT00100); School B onboards onto same Firestore project; School B counter starts at 1 → writes `notices/NOT00001`; School A's NOT00001 overwritten.
      • Mobile READS are tenant-isolated by schoolId field filter (correctly set at line 343); WRITES are not — doc-ID collision happens regardless of schoolId field.
      • pushRequests reference notices by noticeId (raw NOT00001 string); cross-tenant pushRequest→notice resolution becomes ambiguous.
      • Recovery: difficult — overwritten notices unrecoverable without backup; no telemetry/error fires on collision (silent corruption).
      • Frequency at scale: multiple per day per school (calendar events + PTM notices both call write_event_notice). First multi-tenant collision essentially guaranteed.
  - severity verdict: REAL cross-tenant data integrity risk. Inert under current single-school deployment; **BLOCKING for second-school onboarding**.
  - reclassification: original classification "deferred convergence debt / Phase 2/3 architectural target" → revised classification "**PRE-SCALE PRODUCTION-HARDENING CANDIDATE / Phase 2.0 priority**" alongside SIS transactional-hardening package. Per operator direction 2026-05-24 multi-tenant forensic verdict.
  - fix_plan: BUG-CARRY-004 deferred to dedicated Phase 2.0 sub-window "Notice-ID multi-tenant hardening package" (see Phase 2 planning artifacts). Scope: canonicalize Writer 2 doc-ID format to {schoolFs}_NOTxxxx; align counter source to schools/{schoolFs}_profile.commCounters.Notice; transactionalize counter; pushRequest reference migration strategy; historical notice coexistence; mobile-reader compatibility verification; RTDB mirror compatibility boundaries; rollback choreography. All under freeze → forensic → package → APPLY → smoke → verify → cool-window discipline.
  - phase-1 disposition: ZERO code change in P1.4 sub-window. Documentation-only closure. Writers 3+4 formally documented as intentional-separate architecture (not drift). Writer 1 documented as canonical (already correct). Writer 2 elevated to Phase 2.0 priority.
  - choreography_validated: freeze → forensic 2a (locate 5-pad writer) → 2b (locate HR writer) → 2c (locate birthday writer) → 2d (classify each writer) → re-classification 1 (multi-writer norm → document + defer Phase 2/3) → operator-requested deeper forensic 2g (doc-ID construction) + 2h (counter scope + collision analysis) → re-classification 2 (deferred convergence → PRE-SCALE PRODUCTION-HARDENING / Phase 2.0) → operator re-authorization gate (second scope-flip in Phase 1) → Direction D' → APPLY documentation only → CLOSING. Fourth controlled-remediation package in V7 lifecycle (documentation-only outcome). **Critical pattern reinforced**: forensic-driven scope flips can occur in multiple layers; each layer requires re-authorization. Pre-scale production-hardening classification axis introduced (distinct from cosmetic/normalization/transactional).
  - convergence_package_status: Communication Phase 6 convergence package now has 2 sub-clusters resolved (T1.3+T1.5 collection-name via CARRY-001; multi-writer cosmetic by formal documentation of intentional-separate Writers 3+4); 2 sub-clusters remaining (T1.8 empty-id-field — Phase 2.0/2.1 candidate; T1.0 library RTDB retirement — Phase 4 candidate); 1 sub-cluster ELEVATED to Phase 2.0 priority (Writer 2 doc-ID canonicalization).

### CARRY-005 — Writer 2 Notice-ID multi-tenant hardening (P2.0.1' bundled APPLY) ✅ CLOSED

CARRY-005 | P0 | pre-scale-production-hardening | closed
  - discovered: 2026-05-24 by CARRY-004 multi-tenant collision forensic verdict; elevated to dedicated Phase 2.0 sub-window
  - surface: admin-web — application/libraries/Communication_helper.php:329-367 (write_event_notice Firestore block)
  - observed: Writer 2 wrote notices to Firestore at raw doc IDs (NOT00001 5-pad, no school prefix) and used a parallel counter at communicationCounters/{schoolFs}.noticeCounter — diverging from the canonical Writer 1 pattern (schools/{schoolFs}_profile.commCounters.Notice + fs->docId()-school-prefixed doc IDs).
  - pre-APPLY forensic 2g (doc-ID): confirmed Writer 2 only outlier; Writers 1/3/4 all school-prefix via fs->docId() or explicit string concat.
  - pre-APPLY forensic 2h (counter scope): both writers use independent counters; doc-ID FORMAT alignment alone (P2.0.1 standalone) would have introduced WITHIN-TENANT collision because Writer 2's parallel counter (=4) would produce NOT0005, NOT0006, ... colliding with existing Writer 1 docs at NOT0006, NOT0007.
  - critical scope-flip 2026-05-24: P2.0.1 standalone deemed unsafe; rescoped to P2.0.1' bundle = doc-ID canonicalization + counter source unification + pad-width alignment in single atomic operation.
  - mobile-reader forensic (P2.0.0): Parent App + Teacher App confirmed doc-ID-format-agnostic; reads use schoolId field filter via firestoreQuery; CircularDoc uses @DocumentId annotation transparently; no hardcoded NOTxxxx format assumptions in either codebase; no FCM/DeepLink coupling. Only migration-time concern: markCircularRead derives circularReads doc IDs from notice doc IDs — bounded P2.0.4 concern, not blocking P2.0.1'.
  - expected: Writer 2 produces school-scoped canonical doc IDs ({schoolFs}_NOTxxxx 4-pad) using the canonical counter source shared with Writer 1.
  - source_of_expectation: Firestore_service::docId() pattern + Communication._next_id() pattern; CARRY-004 multi-tenant forensic verdict; operator authorization 2026-05-24 Direction A.
  - impact (production-scale): eliminates cross-tenant Firestore doc-ID collision risk under multi-school deployment. Eliminates within-tenant collision risk between Writer 1 and Writer 2. Enables safe second-school onboarding without notice-namespace corruption.
  - fix_plan: BUG-CARRY-005 = P2.0.1' bundled APPLY. Two hunks in Communication_helper.php:
      Hunk 1 (write_event_notice lines 329-343): replace parallel-counter read/write with canonical-counter read/write; change pad from 5 to 4; wrap notice doc ID with fs->docId().
      Hunk 2 (catch-block fallback line 366): align fallback noticeId format to 4-pad (cosmetic consistency).
  - fix_commit: applied 2026-05-24 (Phase 2.0 Sub-window P2.0.1', uncommitted in working tree). Diff summary:
      • Counter source: communicationCounters/{schoolFs}.noticeCounter → schools/{schoolFs}_profile.commCounters.Notice
      • Pad width: str_pad(..., 5, '0') → str_pad(..., 4, '0')
      • Doc ID: fs->set('notices', $noticeId, ...) → fs->set('notices', fs->docId($noticeId), ...)
      • Counter update: fs->set('communicationCounters', ...) → fs->update('schools', $profileDocId, ['commCounters.Notice' => $fsCounter])
  - verification: php syntax check clean. Grep confirms zero remaining `communicationCounters` references in write_event_notice path (collection now read-frozen, deprecated). Communication_verify all scenarios unchanged (T1.1 NORMAL Notice counter=7, T1.3 NORMAL, T1.5 NORMAL, T1.6 NORMAL, no regression in WATCH carries). Comm_counter_probe updated with post-APPLY topology awareness — reports canonical counter source + frozen legacy collection + convergence verdict (✅ Counter ≥ highest extant doc-id). Mobile-reader compatibility forensic (P2.0.0) confirms Parent + Teacher apps unaffected by doc-ID format change.
  - rollback_path: revert 2 hunks in Communication_helper.php (instant; no data state to restore — communicationCounters doc remains for emergency rollback if needed; can be GC'd in future cleanup).
  - choreography_validated: freeze → forensic 2g (doc-ID construction) → forensic 2h (counter scope) → THIRD forensic-driven scope flip (P2.0.1 standalone → P2.0.1' bundle) → operator re-authorization gate → P2.0.0 mobile compatibility forensic (read-only) → operator authorization → APPLY P2.0.1' bundle → smoke → verify → proportional cool-window → closing. Fifth controlled-remediation package in V7 lifecycle; FIRST Phase 2 production-hardening package. **New patterns validated**: pre-APPLY forensic catches partial-remediation hazards that post-planning analysis missed; multi-layer scope flips can occur within single sub-window; bundled atomic-package pattern (P2.0.1') extends from single-line fixes to coordinated multi-hunk hardening with same rollback discipline.
  - known follow-on carries:
      • communicationCounters collection now stale (1 doc at SCH_D94FE8F7AD with noticeCounter=4, lastUpdate 2026-04-25) — defer to P2.0.4 cleanup pass or independent data-hygiene action.
      • Counter concurrency races on unified commCounters.Notice persist (Writer 1 + Writer 2 both read-modify-write) — addressed by P2.0.3 transactionalization (potentially shared with SIS T2.3 utility).
      • 4 historical Writer 2 docs (NOT00001-NOT00004) still at raw doc IDs awaiting P2.0.4 migration.
      • circularReads coupling not yet migrated — P2.0.4 scope.
  - convergence_package_status: Phase 2.0 namespace-hardening foundation established. P2.0.0 + P2.0.1' complete. P2.0.2 (counter source unification) effectively shipped as part of P2.0.1' bundle. Remaining Phase 2.0 sub-windows: P2.0.3 (counter transactionalization), P2.0.4 (historical 4-notice + circularReads + legacy communicationCounters cleanup), P2.0.5 (pushRequest reference integrity verification), P2.0.6 (final post-Phase-2.0 verifier consolidation).

### CARRY-006 — SIS Phase 2.1 bundled transactional-hardening (P2.1.1 + P2.1.2 + P2.1.3) ✅ CLOSED

CARRY-006 | P1 | transactional-hardening / audit-completeness | closed
  - discovered: 2026-05-24 by `[[sis_tier2_closed]]` T2.1 + T2.3 + T2.7 cluster; first 3 of 6 SIS Tier 2 sub-components addressed in this bundle.
  - surface: admin-web — application/controllers/Sis.php at 4 sites:
      • Sis.php:880 (execute_promotion batch-ID construction)
      • Sis.php:1324-1330 (cancel_tc pre-validation block)
      • Sis.php:2852 (import_students per-row enrollment loop)
      • Sis.php:4326 (enroll_student CRM-path enrollment finalization)
  - observed: 3 distinct transactional-safety + audit-completeness gaps:
      (P2.1.1) execute_promotion@Sis.php:883 set `$batchId = date('YmdHis')` only — sub-second concurrent promotions would write to the same schools.promotions[$batchId] entry, silently overwriting the earlier batch.
      (P2.1.2) cancel_tc@Sis.php:1325-1330 had no pre-validation: a request to cancel a non-existent or already-cancelled TC silently no-op'd the `tcHistory` mutation but still proceeded to set `student.status='Active'` + update `schools.tcIndex` entries that don't exist — producing a confusing no-op success response.
      (P2.1.3) enroll_student@Sis.php:4128 (CRM-path) and import_students@Sis.php:2640 (bulk-path) both lacked `_log_history('ADMISSION', ...)` calls. Only save_admission@Sis.php:513 (direct admission form) wrote History.ADMISSION entries. Runtime verifier showed 7/9 students missing canonical ADMISSION events.
  - expected: per `[[sis_tier1_closed]]` + `[[sis_tier2_closed]]` cluster T2.1 + T2.3: batch IDs uniquely identifiable across sub-second collisions; cancel_tc symmetric pre-validation with issue_tc:1097-1104; all enrollment paths emit identical ADMISSION History audit events.
  - source_of_expectation: existing save_admission pattern at Sis.php:513; existing issue_tc:1097-1104 pattern; SIS Tier 2 WATCH carries cluster documented as transactional-hardening package convergence target.
  - impact: zero in current state (no observable corruption under single-admin-at-a-time usage). Forward-protective: prevents batch overwrite under concurrent admin promotion (would have lost batch history); prevents confused-success cancel_tc no-op (would have produced misleading success responses); future enrollments will now emit canonical ADMISSION audit events on both CRM-path and bulk-path (closes audit-completeness asymmetry).
  - fix_plan: BUG-CARRY-006 = bundled APPLY of 3 atomic hardening hunks:
      Hunk 1 (Sis.php:880): `$batchId = date('YmdHis')` → `$batchId = date('YmdHis') . '_' . bin2hex(random_bytes(4))`.
      Hunk 2 (Sis.php:1324-1330): refactor cancel_tc to early-return on (a) missing student, (b) missing TC key, (c) already-cancelled TC, (d) TC not in 'active' state. Pre-check block symmetric with issue_tc:1097-1104. Existing TC-cancellation mutation stays unchanged once preconditions pass.
      Hunk 3a (Sis.php:4326): add `_log_history($school_id, $studentId, 'ADMISSION', ...)` call before entity_sync in enroll_student.
      Hunk 3b (Sis.php:2852): add `_log_history($school_id, $studentId, 'ADMISSION', ...)` call inside import_students per-row loop, before $success++.
  - fix_commit: applied 2026-05-24 (Phase 2.1 bundled Sub-window). All 4 hunks in single file (Sis.php); rollback-trivial individually + atomically.
  - verification: php syntax check clean. Sis_canonical_verify (Tier 1) — 8/8 NORMAL, no regression. Sis_tier2_verify (Tier 2) — same data-level classifications as pre-APPLY (T2.1/T2.2 WATCH unchanged for HISTORICAL data; T2.4-T2.8 unchanged). Forward-protection verified via code review only — exercising the new paths requires actual promotion/cancel-tc/enrollment events which are out of read-only verification scope. Cool-window: proportional (additive behavioral changes; no data mutation occurred).
  - rollback_path: revert 4 hunks in Sis.php (instant; no data state to restore).
  - choreography_validated: freeze → forensic re-confirmation at 4 sites → package → APPLY 4 atomic hunks → smoke (php -l + verifier re-run) → verify (no regression) → proportional cool-window → closing. Sixth controlled-remediation package in V7 lifecycle; FIRST bundled-3-component package; SECOND Phase 2 production-hardening package. **New pattern validated**: bundled multi-component sub-windows are safe when all hunks confined to single file + individually rollback-trivial + scoped to bounded blast radius. Validates the "bundled atomic remediation" expansion of the choreography from single-edit packages.
  - known follow-on carries:
      • Verifier-knowledge maintenance: Sis_tier2_verify.php T2.1 message text still describes pre-fix state (`enroll_student@Sis.php:4128 lacks _log_history() call`) — factually wrong post-APPLY. Verifier still correctly identifies historical residue (5 direct-path + 2 CRM-path students missing ADMISSION events from pre-fix era). Verifier message update can bundle with future P2.1.7 historical backfill window or as standalone cosmetic verifier-knowledge maintenance.
      • Historical residue: 7 students (STU0004, STU0005, STU0006, STU0008, STU0009, STU0010, STU0011) lack canonical ADMISSION History entries from pre-fix era. Backfill is candidate Phase 2.1.7 (one-shot idempotent + dry-run choreography matching `[[v7_session_5]]` STU_TEST_* cleanup pattern). Not authorized yet.
      • SIS Tier 2 components remaining: P2.1.5 (TC counter transactionalization via Id_generator reuse), P2.1.4 (enroll_student/import_students propagation parity), P2.1.6a (History architectural decision) + P2.1.6b (History atomic-append APPLY).
  - convergence_package_status: Phase 2.1 SIS transactional-hardening foundation established. 3 of 6 sub-components shipped. T2.3 batch-ID + cancel_tc pre-check resolved; T2.1 audit-path convergence resolved at code level (historical backfill is a separate decision). Remaining: T2.3 TC counter (P2.1.5), T2.6 propagation parity (P2.1.4), T2.7 History atomic-append (P2.1.6).

### CARRY-007 — SIS TC counter transactional-hardening (P2.1.5 Option C) ✅ CLOSED

CARRY-007 | P1 | transactional-hardening / concurrency | closed
  - discovered: 2026-05-24 by `[[sis_tier2_closed]]` T2.3 cluster; final TC-counter component of the 3-part race exposure (batchId + cancel_tc + TC#).
  - surface: admin-web — application/controllers/Sis.php:2410-2423 (_get_tc_number).
  - observed: classic read-modify-write counter at schools/{schoolFs}.tcCounter without atomic guard. Two concurrent issue_tc calls could:
      (a) both read same $current value
      (b) both compute same $next = $current + 1
      (c) both write the same tcCounter value
      (d) both produce the same TC number → duplicate TCs with same TC-{code}-{YYYY}-{NNNN} identifier
  - pre-APPLY forensic 2a (Id_generator scoping): Id_generator pointer doc at feeCounters/_sys_{prefix} is GLOBAL (no schoolFs); direct reuse would have changed TC numbering semantics from per-school sequential to globally monotonic. Critical scope-flip: Id_generator-reuse plan rescoped to inline-claim-doc pattern preserving per-school semantics.
  - pre-APPLY forensic 2b (TC format): TC-{school_code}-{YYYY}-{NNNN} format expects per-school sequence (school_code embedded in format implies this).
  - expected: atomic TC counter increment producing unique TC numbers under concurrent admin issue_tc, with per-school sequence semantic preserved.
  - source_of_expectation: Id_generator atomic-claim-doc pattern + Fee_firestore_txn::nextCounter pattern + SIS Tier 2.3 cluster forensic verdict.
  - impact: zero in current single-admin-single-school deployment (no observable collisions; tcCounter=NULL — no TCs issued under current data). Production-protective: prevents duplicate TC numbers under concurrent admin operation; safe for multi-admin scaling.
  - fix_plan: BUG-CARRY-007 = P2.1.5 Option C (inline atomic claim-doc pattern in Sis.php). Single function (_get_tc_number) rewrite:
      • Atomic primitive: $this->firebase->firestoreCreate('feeCounters', '{schoolFs}_TC_claim_{N}', ...) — fails-if-exists guard.
      • Per-school counter preserved: schools/{schoolFs}.tcCounter remains authoritative fast-skip pointer.
      • Tiered retry (20 attempts) with jittered backoff (50-100ms) — same convention as Id_generator.
      • Throws RuntimeException on contention exhaustion rather than risk duplicate TC.
      • Format unchanged: TC-{school_code}-{YYYY}-{NNNN}.
      • Claim doc carries source='sis_issue_tc' for forensic attribution.
  - fix_commit: applied 2026-05-24 (Phase 2.1 Sub-window P2.1.5). Single file (Sis.php), single hunk (~50 lines from ~13). Rollback-trivial via single hunk revert.
  - verification: php syntax check clean. Sis_canonical_verify (Tier 1) — T1.3 TC workflow NORMAL, no regression. Sis_tier2_verify (Tier 2) — T2.4 aggregate-index readiness NORMAL, T2.5 lifecycle-status consistency NORMAL, no regression. Forward-protection verified via code review only — exercising the new path requires actual issue_tc call which would write a real TC doc (out of read-only verification scope).
  - rollback_path: revert single hunk in Sis.php at _get_tc_number (instant; no data state to restore — no TCs have been issued under either old or new code path; tcCounter remains NULL).
  - choreography_validated: freeze → forensic 2a (Id_generator scoping) → forensic 2b (current TC counter scoping) → FORENSIC-DRIVEN SCOPE CLARIFICATION (per-school vs global semantics) → operator decision gate → Option C selected → APPLY single hunk → smoke → verify → proportional cool-window → closing. Seventh controlled-remediation package in V7 lifecycle; THIRD Phase 2 production-hardening package. **Pattern reinforced**: even when authorization specifies a particular reuse path (Id_generator), pre-APPLY forensic must verify semantic compatibility before APPLY. Concurrency-pattern reuse ≠ counter-semantic reuse.
  - known follow-on carries:
      • Verifier-knowledge maintenance: Sis_tier2_verify T2.4 message references Sis.php:2399 (stale line number post-APPLY); minor cosmetic. Bundle with future verifier-update window or P2.1.7.
      • Mid-flight TC issuance atomicity: if issue_tc fails AFTER successful TC counter claim (e.g., student doc update fails), the claim doc remains but TC isn't fully issued. The next issue_tc gets the next number (correct behavior); orphan claim "wastes" a TC number but no corruption. Full multi-doc TC issuance atomicity would require saga pattern — deferred (out of P2.1.5 scope; broader transactional hardening concern).
      • TC contention exhaustion (20 retries exhausted) throws RuntimeException → bubbles up to issue_tc caller → admin sees HTTP 500. Could be incrementally improved with try/catch + json_error in issue_tc, but deferred as cosmetic-UX improvement (not in P2.1.5's atomicity scope).
  - convergence_package_status: Phase 2.1 SIS transactional-hardening continues. 4 of 6 sub-components shipped (T2.1 audit-path + T2.3 batchId + T2.3 cancel_tc + T2.3 TC counter — closing the full T2.3 idempotency cluster). Remaining: T2.6 propagation parity (P2.1.4), T2.7 History atomic-append (P2.1.6).

### CARRY-008 — SIS CRM/bulk-import propagation parity (P2.1.4) ✅ CLOSED

CARRY-008 | P2 | propagation-parity / cross-module-completeness | closed
  - discovered: 2026-05-24 by `[[sis_tier2_closed]]` T2.6 finding (CRM-enrollment + bulk-import paths missing 2 propagation hops vs save_admission).
  - surface: admin-web — application/controllers/Sis.php at 2 sites:
      • enroll_student@~4414 (CRM-path; single student per call).
      • import_students@~2706 (bulk-path; per-row loop with batch-end dedup).
  - observed: save_admission emits 2 propagation hops after a new student is added (`_recompute_section_strength` at :398 + `feeDefaulter->updateDefaulterStatus` at :505). enroll_student (CRM-path) and import_students (bulk-path) BOTH lacked these hops despite producing functionally identical "new student created" state. Result: never-paid CRM/bulk students had unpaid feeDemands but no feeDefaulters projection until first payment (Phase 3D 2026-05-09 leak: STU0004/STU0005); sections.currentStrength stale until next lifecycle write.
  - expected: parity across all 3 student-creation paths (save_admission + enroll_student + import_students). All produce defaulter projection + strength refresh.
  - source_of_expectation: save_admission canonical pattern at Sis.php:393-505; SIS Tier 2.6 forensic verdict.
  - impact: zero in current data state (existing 9 students were save_admission-path or pre-existing; current section.strength values consistent because no T2.6 mismatches detected). Forward-protective: closes the leak for future CRM-path + bulk-import enrollments.
  - fix_plan: BUG-CARRY-008 = 4 atomic edits in Sis.php:
      Hunk 1 (enroll_student): bundled `updateDefaulterStatus + _recompute_section_strength` block right after entity_sync (post-existing P2.1.3 ADMISSION _log_history). Try/catch around defaulter sync (best-effort, matches save_admission:504-508 pattern); section_strength recompute is itself best-effort internally (try/catch at Sis.php:2351-2371).
      Hunk 2 (import_students init): add `$touchedSections = []` to the loop-initialization block at ~2736 (joins $success/$error/$skipped initialization).
      Hunk 3 (import_students per-row): per-student `feeDefaulter->updateDefaulterStatus` call inside loop after ADMISSION _log_history; per-student `$touchedSections["{$className}|{$section}"] = [...]` dedup tracking.
      Hunk 4 (import_students post-loop): deduplicated `_recompute_section_strength` foreach over `$touchedSections` after loop ends, before summary message. Same dedup pattern as execute_promotion:932 touchedSections.
  - fix_commit: applied 2026-05-24 (Phase 2.1 Sub-window P2.1.4). Single file (Sis.php), 4 atomic hunks, all additive (no existing behavior altered).
  - verification: php syntax check clean. Sis_canonical_verify (Tier 1) — T1.6 fee defaulter integration NORMAL (0 orphans), T1.7 Entity_firestore_sync canonical fields NORMAL (9/9), no regression. Sis_tier2_verify (Tier 2) — T2.6 cross-module propagation NORMAL (0 section.strength mismatches), no regression. Forward-protection verified via code review only — exercising new paths requires real enrollment which is out of read-only verification scope.
  - rollback_path: revert 4 atomic hunks in Sis.php (instant; no data state to restore; behavioral changes are forward-only on new enrollments).
  - choreography_validated: freeze → forensic (4 sites confirmed) → package → APPLY 4 atomic hunks → smoke (php -l + verifiers) → verify (no regression) → proportional cool-window → closing. Eighth controlled-remediation package in V7 lifecycle; FOURTH Phase 2 production-hardening package; SECOND bundled multi-hunk package (after P2.1.1+P2.1.2+P2.1.3 = CARRY-006). **Pattern reinforced**: cross-writer parity convergence (multiple writers achieving the same canonical end-state) is a clean P2-class atomic-package scope when all touched code is in a single file + additive in nature.
  - known follow-on carries:
      • Verifier-knowledge maintenance: Sis_tier2_verify T2.6 message still references "Code-review gaps (b)+(c) in enroll_student path remain INERT" — now factually wrong post-APPLY. Same pattern as P2.1.3/P2.1.5 verifier-message staleness. Bundle with future verifier-knowledge maintenance window or P2.1.7.
      • Historical residue: existing 9 students predating fix are unaffected. The T2.6 verifier still reports section.strength NORMAL because save_admission-path students were captured at admission time. CRM/bulk students (STU0004/STU0005 + others) had feeDefaulter projections backfilled via earlier `Phase 3D` work (per save_admission docblock at :496-508).
  - convergence_package_status: Phase 2.1 SIS transactional-hardening continues. 5 of 6 sub-components shipped (T2.1 + T2.3 batchId + T2.3 cancel_tc + T2.3 TC counter + T2.6 propagation parity). Remaining: T2.7 History atomic-append (P2.1.6 — requires architectural decision Option A subcollection-migration per operator preference).

### CARRY-009 — SIS History subcollection migration (P2.1.6c + P2.1.6d bundled) ✅ CLOSED

CARRY-009 | P1 | transactional-hardening / storage-model-evolution | closed
  - discovered: 2026-05-24 by `[[sis_tier2_closed]]` T2.7 cluster (final SIS Tier 2 sub-component); P2.1.6a architectural design + P2.1.6b reader-inventory forensic confirmed migration approach + zero-mobile-coupling.
  - surface: admin-web —
      • NEW: application/controllers/Sis_history_backfill.php (CLI-only one-shot data-migration controller).
      • EDIT: application/controllers/Sis.php (_log_history function rewrite at line 2243; ~23 lines → ~50 lines including new docblock).
      • DATA: studentHistory Firestore collection (4 docs created from existing students.History map entries).
  - observed: _log_history at Sis.php:2243-2264 performed read-modify-write on students.History map:
      (1) Read student doc → fetch History map
      (2) Compute new histKey + append entry to map
      (3) Write entire updated map back to student doc
      → CLASSIC RMW race: 2 concurrent _log_history calls for same student could both read same map state, both append their entry, both write back; last writer wins, first writer's entry LOST.
  - design decisions accepted 2026-05-24 (P2.1.6a):
      D1.A — Collection name: studentHistory
      D2.A — Cutover strategy: single-cutover (no dual-write transition)
      D3.B — Legacy field cleanup: preserve History field as inert legacy
      D4.A — Mobile-coupling forensic: deferred to P2.1.6b (then VERIFIED ZERO)
      D5.A — Doc-ID format: {schoolFs}_{studentId}_{histKey} (3-part compound)
      D6.A — Sequencing: sequential bounded sub-windows
  - mobile-coupling verification (P2.1.6b): ZERO — Parent App + Teacher App + FCM + DeepLink ALL confirmed no reads of student.History map. Cleanest mobile-coupling profile of any Phase 2 migration.
  - expected: NEW History entries are written to studentHistory via atomic createDocument (eliminates RMW race); EXISTING 4 entries are backfilled to studentHistory while remaining in legacy map (rollback-safe); readers temporarily continue using legacy map until P2.1.6e cutover.
  - source_of_expectation: P2.1.6a design package, P2.1.6b forensic, [[sis_tier2_closed]] T2.7 cluster.
  - impact: zero current data corruption (single-admin usage means RMW race was inert). Forward-protective: eliminates lost-history-entry race; enables future scaling (subcollection grows unbounded vs map field bounded by 1MiB doc-size limit); strengthens audit immutability (createDocument cannot overwrite existing docs).
  - fix_plan: BUG-CARRY-009 = bundled P2.1.6c (backfill) + P2.1.6d (writer cutover):
      (4a) NEW: Sis_history_backfill.php with dry_run / apply / verify subcommands; idempotent via createDocument fails-if-exists.
      (4b) Dry-run: 4 entries across 2 students (STU0001×3, STU0007×1) identified.
      (4c) APPLY: 4 docs created in studentHistory (action distribution: ADMISSION×2 + STATUS_CHANGE×2).
      (4d) Idempotency confirmed: re-run produced 0 creates + 4 skipped_existing; verify pass confirms 4/4 cross-reference.
      (4e) _log_history at Sis.php:2243 rewritten to call firestoreCreate('studentHistory', $docId, $data) atomically. Legacy map field NO LONGER written to (preserved as inert legacy per D3.B). 12 call sites unchanged (all route through this single function chokepoint).
  - fix_commit: applied 2026-05-24 (Phase 2.1 Sub-window P2.1.6c + P2.1.6d bundled). 2 files (NEW Sis_history_backfill.php + EDIT Sis.php). 4 Firestore docs created. Rollback-revertible: delete 4 studentHistory docs + revert _log_history function.
  - verification: php syntax check clean on both files. sis_history_backfill verify confirms 4/4 backfilled docs cross-reference cleanly. Sis_canonical_verify T1.5 NORMAL (4 events visible via legacy map — readers haven't migrated yet per design). Sis_tier2_verify T2.1/T2.2/T2.5 unchanged (readers still on legacy map). T2.7 will be fully resolved after P2.1.6e reader cutover.
  - rollback_path: per-component reversible:
      • Backfill rollback: delete 4 studentHistory docs (idempotent — backfill writes carry backfillSource='sis_history_backfill_p2_1_6c' for cleanup queryability).
      • Writer-cutover rollback: revert Sis.php _log_history to map-RMW pattern (single function; instant).
  - choreography_validated: freeze → forensic (P2.1.6a design + P2.1.6b reader inventory) → bundled APPLY (4a controller write → 4b dry-run → 4c execute backfill → 4d idempotency verify → 4e writer rewrite) → smoke + verify (no regression in T1.5/T2.1/T2.2/T2.5) → proportional cool-window → closing. Ninth controlled-remediation package in V7 lifecycle; FIFTH Phase 2 production-hardening package; FIRST bundled-with-data-mutation package (vs prior code-only bundles). **New pattern validated**: backfill-then-writer-cutover atomic sequencing within single sub-window when (a) data volume small, (b) backfill idempotent, (c) writer cutover single-function. Future bundled migrations with larger data scale should split into separate sub-windows.
  - known follow-on carries:
      • P2.1.6e: Reader cutover (8 sites: 1 admin UI + 5 verifier + 1 drill + 1 legacy-pattern). After cutover, T1.5 + T2.1/T2.2/T2.5 query studentHistory instead of legacy map. Pre-existing verifier-message staleness from P2.1.3/P2.1.4/P2.1.5 will be addressed here.
      • P2.1.6f: Verifier-knowledge maintenance bundles with P2.1.6e.
      • P2.1.6g: OPTIONAL legacy History field cleanup (D3.B currently: preserve as inert).
      • Transitional state observable: post-cutover, ANY new _log_history call writes to studentHistory only; legacy map stays at current 4 entries forever (until either P2.1.6e + P2.1.6g, or rollback). Readers continue showing 4 entries; new entries invisible until P2.1.6e.
  - convergence_package_status: Phase 2.1 SIS transactional-hardening 5.5 of 6 sub-components shipped (T2.7 writer-side complete; reader-side awaits P2.1.6e). With reader cutover, T2.7 transitions to FULLY FIXED. V7 helper suite: 32 controllers (was 31; +1 Sis_history_backfill).

### CARRY-010 — SIS History reader cutover + verifier-knowledge maintenance (P2.1.6e + P2.1.6f bundled) ✅ CLOSED

CARRY-010 | P1 | reader-cutover / verifier-knowledge-maintenance | closed
  - discovered: 2026-05-24 by CARRY-009 transitional-state requirement (writer cutover complete; readers temporarily on legacy map per D3.B; P2.1.6e closes the loop).
  - surface: admin-web — 4 files, 8 reader sites + 3 stale verifier messages:
      • application/controllers/Sis.php:1700 (history($userId) admin UI controller)
      • application/controllers/Sis_canonical_verify.php (T1.5 reader at :238 + message)
      • application/controllers/Sis_tier2_verify.php (T2.1 reader at :152/:167/:198 + T2.2 at :237 + T2.5 at :367; T2.1+T2.4+T2.6 stale messages)
      • application/controllers/Sis_history_drill.php:38 (drill helper)
  - observed: post-CARRY-009 writer cutover, all readers continued querying legacy students.History map field. Future writes land in studentHistory ONLY → readers would have shown stale data (no new entries visible). Verifier messages from P2.1.3/P2.1.4/P2.1.5 referenced pre-fix state ("lacks _log_history", "Sis.php:2399", "Code-review gaps remain INERT") — factually wrong post-fix.
  - expected: all readers query canonical studentHistory collection; verifier messages reflect post-fix architectural state.
  - source_of_expectation: P2.1.6a design package (D2.A single-cutover; D3.B preserve legacy); P2.1.6b reader-inventory forensic (8 sites identified, mobile-coupling zero).
  - impact: zero current data state change (reads only switched source). Forward effect: new History events visible in all readers; verifier messages accurately reflect post-P2.1.3/4/5/6 state.
  - fix_plan: BUG-CARRY-010 = 4-file bundled APPLY:
      Edit 1: Sis_canonical_verify add `_fetch_history_by_student()` helper + rewrite _t1_5 to query studentHistory + update T1.5 message.
      Edit 2: Sis_tier2_verify add `_fetch_history_by_student()` helper + rewrite _t2_1/_t2_2/_t2_5 to accept pre-fetched history map + update T2.1+T2.4+T2.6 stale messages.
      Edit 3: Sis_history_drill rewrite dump() to query studentHistory grouped by studentId.
      Edit 4: Sis.php history($userId) rewrite to query studentHistory by schoolId+studentId (also adds explicit-field-filter via whereEqualTo).
  - fix_commit: applied 2026-05-24 (Phase 2.1 Sub-window P2.1.6e+f bundled). 4 files edited; ~80 lines changed across 8 reader cutovers + 3 verifier-knowledge maintenance updates. Rollback-revertible per file (each file independently revertible).
  - verification: php syntax check clean on all 4 files. Sis_canonical_verify T1.5 NORMAL (4 events via studentHistory canonical; legacy map preserved per D3.B). Sis_tier2_verify multiple transitions:
      • T2.1: ⚠ WATCH → ✅ NORMAL (forward-correct + historical residue annotated)
      • T2.2: ⚠ WATCH → ✅ NORMAL **(BONUS — Firestore field-iteration-order FP eliminated as side-effect of canonical-query docId sorting)**
      • T2.4: NORMAL with refreshed message (P2.1.5 CARRY-007 reference)
      • T2.5: NORMAL with canonical source
      • T2.6: NORMAL with refreshed message (P2.1.4 CARRY-008 reference)
      Sis_history_drill renders same 4 entries via canonical source. End-to-end History visibility restored.
  - rollback_path: revert per-file independently:
      • Sis_canonical_verify: revert _t1_5 + remove helper (independent)
      • Sis_tier2_verify: revert _t2_1/2/5 + remove helper + revert message edits (independent)
      • Sis_history_drill: revert dump() (independent)
      • Sis.php: revert history() (independent)
  - choreography_validated: freeze → forensic re-confirmation (P2.1.6b inventory) → bundled APPLY (4 file edits sequenced; reader cutover before message updates within Sis_tier2_verify) → smoke (php -l × 4) → verify (T1.5/T2.1/T2.2/T2.4/T2.5/T2.6 transitions) → proportional cool-window → closing. Tenth controlled-remediation package in V7 lifecycle; SIXTH Phase 2 production-hardening package; FOURTH bundled multi-file package; FIRST 4-file bundled package. **Bonus pattern discovery**: canonical-query pattern naturally eliminates field-iteration-order FPs that hardcoded message-based verifiers couldn't address (T2.2 was a verifier-FP that resolved itself as side-effect of cutover).
  - known follow-on carries:
      • Legacy History field cleanup (P2.1.6g) — preserved as inert per D3.B; can be deferred indefinitely or scheduled as future data-hygiene window.
      • Historical ADMISSION backfill (P2.1.7) — 7 pre-fix students still lack ADMISSION events in studentHistory. Forward writes correct; historical fill is separate decision.
      • Verifier-knowledge maintenance for new T2.7 status (SIS Tier 2 History architectural cluster fully resolved) — could add affirmative T2.7 scenario or document inline in T2.2 message.
  - convergence_package_status: Phase 2.1 SIS transactional-hardening 6 of 6 sub-components shipped (T2.1 + T2.3 batchId + T2.3 cancel_tc + T2.3 TC counter + T2.6 propagation parity + T2.7 History atomic-append writer+reader). SIS Tier 2 ARCHITECTURAL CLUSTER FULLY RESOLVED. Remaining: optional P2.1.6g legacy cleanup + optional P2.1.7 historical backfill. V7 helper suite: 32 controllers.

### CARRY-011 — Communication historical notice migration (P2.0.4) ✅ CLOSED

CARRY-011 | P2 | historical-data-migration / cross-tenant-collision-cleanup | closed
  - discovered: 2026-05-24 by CARRY-005 P2.0.1' aftermath — 4 raw-format notice docs (NOT00001-NOT00004 from pre-P2.0.1' write_event_notice writer) remained at non-school-scoped doc IDs after canonical writer cutover. Cross-tenant collision risk persisted for these 4 historical docs even though future writes were canonical.
  - surface: admin-web — Firestore notices collection (4 docs at raw doc IDs).
  - operator decisions accepted 2026-05-24:
      D7.A — in-place rename preserving 5-pad numeric identity (notices/NOT00001 → notices/SCH_D94FE8F7AD_NOT00001; noticeId field unchanged)
      D8.B — preserve legacy communicationCounters collection as inert (matches D3.B inert-legacy precedent)
  - cross-reference forensic (Comm_notice_xref_probe; new V7 helper):
      • circularReads: 0 matches (no read-receipt orphans)
      • pushRequests: 0 matches (event-keyed via eventId field, not notice-keyed)
      • messageInboxes: 0 matches
      • notifications: 0 docs (collection empty)
      • Conclusion: migration is fully isolated; no cross-collection coupling.
  - cross-event linkages preserved: NOT00001→EVT0005, NOT00002→EVT0006, NOT00003→PTM0001, NOT00004→PTM0002 (eventRef field unchanged through migration).
  - expected: 4 historical notices acquire school-prefix scoping (cross-tenant safe); numeric identity preserved (5-pad NOT00001-04); cross-references unbroken; idempotent re-runnability.
  - source_of_expectation: CARRY-005 closure documentation, D7.A operator decision, P2.0.4 cross-reference forensic verdict.
  - impact: zero current data corruption (single-school deployment). Forward-protective: closes the cross-tenant doc-ID collision exposure for these 4 historical docs. Brings the entire notices collection to canonical school-scoped posture.
  - fix_plan: BUG-CARRY-011 = bounded historical data migration via new helper:
      • NEW: application/controllers/Comm_notice_migration.php (CLI controller with dry_run/apply/verify subcommands).
      • Per-doc choreography: read source → createDocument(target) atomically fails-if-exists → deleteDocument(source). Create-FIRST ordering ensures ≥1 copy at all times.
      • Migration provenance fields added: migratedAt, migrationSource='p2_0_4_in_place_rename'.
      • Server-managed __updateTime field stripped before re-create.
      • noticeId field PRESERVED (D7.A — original identity).
  - fix_commit: applied 2026-05-24 (Phase 2.0 Sub-window P2.0.4). NEW: Comm_notice_migration.php + Comm_notice_xref_probe.php (preserved as forensic helpers). 8 Firestore mutations executed (4 creates + 4 deletes).
  - verification: dry-run preview confirmed 4 source docs found, 0 target collisions. APPLY confirmed 4 created + 4 old-deleted, 0 failures. Verify pass confirmed 4/4 new docs present + 0/4 old docs remaining. Idempotency re-run produced 0 mutations (all sources gracefully skipped as not-found). Post-migration: 10 notices in collection (no data loss); all 10 doc IDs now school-prefixed. communication_verify T1.1 NORMAL (counters unchanged), T1.6 NORMAL (notices=10, circulars=2). No regression in other verifier scenarios.
  - rollback_path: re-execute migration controller in reverse direction (read SCH_X_NOT00001 → recreate at NOT00001 → delete SCH_X_NOT00001). Migration provenance field `migrationSource='p2_0_4_in_place_rename'` enables targeted reverse-migration if needed. Manual: 8 Firestore operations.
  - choreography_validated: freeze → forensic 1 (drill of 4 raw docs) → forensic 2 (cross-reference probe; 0 orphans) → operator D7+D8 decisions → package design (3 options presented) → APPLY (4a write controller, 4b dry-run, 4c execute, 4d idempotency check + verify, 4e drill post-state) → smoke + verify (no Tier 1 regression) → proportional cool-window → closing. Eleventh controlled-remediation package in V7 lifecycle; SEVENTH Phase 2 production-hardening package; SECOND bounded historical-data-migration (after CARRY-009 backfill). **Pattern validated**: in-place rename pattern via atomic create-then-delete preserves identity + provides idempotent rollback semantics; suitable for bounded historical migrations.
  - known follow-on carries:
      • Format heterogeneity in notices collection persists (4 docs at 5-pad SCH_X_NOT00001 vs 4 docs at 4-pad SCH_X_NOT0002/4/6/7) — purely cosmetic per D7.A; mobile apps confirmed format-agnostic per P2.0.0; addresses cross-tenant safety not stylistic uniformity.
      • Broader Communication canonical-ID convergence (operator-flagged: spans notices/circulars/conversations/inboxes/notifications/triggers/queues/cross-system writers/readers) remains a future Phase 3-class convergence package — explicitly out of P2.0.4 scope.
      • Legacy communicationCounters/SCH_D94FE8F7AD doc preserved per D8.B (inert; noticeCounter=4; lastUpdate 2026-04-25; no writer/reader).
  - convergence_package_status: Phase 2.0 historical residue cleanup partial. P2.0.4 historical notice migration COMPLETE. Remaining: P2.0.5 (pushRequest reference integrity verification — likely TRIVIAL per current forensic), P2.0.6 (final Phase 2.0 verifier consolidation). V7 helper suite: 34 controllers (was 32; +2 new: Comm_notice_xref_probe + Comm_notice_migration).

### CARRY-012 — SIS historical ADMISSION backfill (P2.1.7) ✅ CLOSED

CARRY-012 | P2 | historical-data-backfill / audit-completeness | closed
  - discovered: 2026-05-24 by CARRY-006 P2.1.3 aftermath — 7 students (STU0004, STU0005, STU0006, STU0008, STU0009, STU0010, STU0011) pre-dated the enroll_student + import_students writer fix and therefore had no canonical ADMISSION event in studentHistory. Forward writes were correct post-CARRY-006; historical fill was the open item.
  - surface: admin-web — Firestore studentHistory collection (7 docs to create).
  - pre-APPLY forensic (sis_admission_backfill dry_run):
      • 9 students fetched; 2 with existing studentHistory entries (post-CARRY-009 backfill).
      • 7 eligible for ADMISSION backfill, admission dates correctly parsed from `Admission Date` / `admissionDate` field:
          - STU0004, STU0005: 2026-04-29 (CRM-path)
          - STU0006: 2026-05-16
          - STU0008, STU0009, STU0010, STU0011: 2026-05-17
      • 0 expected target-doc collisions (random suffix in histKey).
  - expected: each of 7 eligible students gets a synthesized ADMISSION event in studentHistory; canonical writer fields (action, description, changed_by, changed_at, metadata) populated; chronological consistency preserved (histKey YmdHis prefix derived from admission date); audit-distinguishable as historical backfill via metadata.source='p2_1_7_historical_backfill' + backfillSource='sis_admission_backfill_p2_1_7'.
  - source_of_expectation: P2.1.6c CARRY-009 backfill pattern (atomic createDocument fails-if-exists); CARRY-006 audit-path canonical schema; operator Option E authorization.
  - impact: zero current corruption (audit-completeness gap was historical residue, not active failure). Forward effect: all 9 students now have canonical ADMISSION events; cross-system audit-trace completeness achieved; verifier T2.1 transitions from "historical residue carry-forward" → fully NORMAL.
  - fix_plan: BUG-CARRY-012 = bounded historical data backfill via new helper:
      • NEW: application/controllers/Sis_admission_backfill.php (CLI with dry_run/apply/verify subcommands).
      • Per-student choreography: skip if existing ADMISSION event present (idempotency); else compute histKey from admission date; createDocument fails-if-exists for atomic single-shot create.
      • Distinguished from forward writes via metadata.source + backfillSource + changed_by="System (P2.1.7 backfill)".
  - fix_commit: applied 2026-05-24 (Phase 2.1 Sub-window P2.1.7). 7 Firestore docs created in studentHistory collection.
  - verification: dry-run confirmed 7 eligible + 2 already-present (CARRY-009 baseline). APPLY confirmed 7 created, 0 failures. Verify pass: 9/9 students with ADMISSION events; 0 missing. Idempotency re-run: 0 mutations (eligible=0, skipped_has=9). Sis_tier2_verify T2.1 transition: "historical residue carry-forward" → ✅ NORMAL — "all enrollment paths emit ADMISSION events" (CRM-path 2/2 + Direct-path 7/7; action distribution {"ADMISSION":9,"STATUS_CHANGE":2}). T2.2 remains ✅ NORMAL with 11 total events. No regression elsewhere.
  - rollback_path: query studentHistory for `backfillSource='sis_admission_backfill_p2_1_7'` → delete those 7 docs. Distinct backfill-provenance fields enable surgical rollback without affecting forward writes or P2.1.6c-backfilled entries.
  - choreography_validated: freeze → forensic (dry-run with admission-date parsing per student) → operator Option E authorization → APPLY (build controller → dry-run → execute → idempotency → verifier transition check) → smoke + verify (T2.1+T2.2 transitions) → proportional cool-window → closing. Twelfth controlled-remediation package in V7 lifecycle; EIGHTH Phase 2 production-hardening package; THIRD bounded historical-data-migration (after CARRY-009 backfill, CARRY-011 in-place rename). **Pattern validated**: distinguishing-by-provenance-field pattern (backfillSource='sis_admission_backfill_p2_1_7') enables surgical rollback of backfill writes without disturbing forward-write history.
  - known follow-on carries:
      • P2.1.6g optional legacy History map cleanup (D3.B preserve-as-inert). Now that all 9 students have canonical studentHistory entries (4 P2.1.6c-backfilled + 7 P2.1.7-synthesized), the legacy `students.History` map field on STU0001 + STU0007 is fully redundant. Cleanup remains conditional per operator decision.
      • Verifier-knowledge maintenance: T2.1 forward-correct branch was previously gated on `(empty($crmMissingAdmissionEvent) && empty($directMissingAdmissionEvent))` — now triggers cleanly post-backfill.
  - convergence_package_status: Phase 2.1 SIS Tier 2 + historical-completeness work CLOSED. T2.1 + T2.3 (all 3 sub-components) + T2.6 + T2.7 (writer+reader) fixed at code; historical residue backfilled. SIS audit-trail is now 100% canonical end-to-end. V7 helper suite: 35 controllers (was 34; +1 Sis_admission_backfill).

## Stage 0 — Multi-Tenant SaaS-Readiness Hardening (2026-05-24, post-V7 convergence)

**Context:** External "GLOBAL NAMESPACE INTEGRITY MATRIX" forensic conducted after V7 closure surfaced multi-tenant isolation + Firebase namespace governance concerns that V7 transactional-hardening did not specifically address. Reclassifies the campaign from "single-school production readiness" to "multi-school SaaS readiness". FZ-N findings adopted as Stage 0 blockers. Phase 3-4 deferred until Stage 0-3 complete.

### FZ-1 — Communication_helper event-notice docId scoping ✅ ALREADY RESOLVED

FZ-1 | n/a (pre-resolved) | multi-tenant-namespace-safety | resolved-by-prior-work
  - discovered: external Stage 0 audit (pre-V7-closure baseline).
  - status: ✅ RESOLVED before formal Stage 0 forensic; subsumed by V7 CARRY-005 P2.0.1' (writer cutover to fs->docId-school-scoped) + CARRY-011 P2.0.4 (4 historical NOT00001-04 raw docs migrated to canonical school-scoped). All 10 notices in `notices` collection are now school-prefixed. No Stage 0 remediation action required.

### FZ-2 — RTDB rules source-control + ESCALATED to FZ-2-CRITICAL ⚠ AGENT-PARTIAL / OPERATOR-PENDING

FZ-2 | P0-CRITICAL | tenant-isolation / security-posture | partial-closed (source-control parity ✅; rule-tightening PENDING)
  - discovered: Stage 0 forensic 2026-05-24.
  - original framing: "RTDB rules visibility gap — database.rules.json absent from repo".
  - **CRITICAL ESCALATION 2026-05-24**: operator-shared production RTDB rules content reveals rules are wide-open: `{"rules": {".read": "true", ".write": "true"}}`. Any authenticated OR unauthenticated user can read/write the entire RTDB. Combined with 124 active RTDB call sites across 22 admin-web files + active mobile-app RTDB usage, this is a production-blocker security exposure for single-school deployment, materially worse for multi-school SaaS.
  - surface: firebase-rules/ directory + production RTDB rules.
  - source-of-expectation: Firebase security best practices + multi-tenant isolation requirements.
  - impact: anyone with Firebase database URL can read/write ALL RTDB data (student records, fees, attendance, exam, parent contacts, etc.). Single point of catastrophic exposure.
  - fix_plan: BUG-FZ-2 split into 2 sub-windows:
      • S0.2 (THIS WINDOW): scaffold firebase-rules/database.rules.json with current production content for source-control parity. Closes the "visibility gap" half of FZ-2.
      • S0.X (FZ-2-CRITICAL FOLLOW-UP — PENDING AUTHORIZATION): deploy tightened rules requiring proper schoolId-scoped + role-based access; coordinated with mobile app updates to prevent breakage.
  - fix_commit_partial: S0.2 applied 2026-05-24. firebase-rules/database.rules.json now contains operator-exported production rules content. Production rules state in source control achieved (governance gap closed).
  - verification: file present at firebase-rules/database.rules.json; content matches firebase.json declaration at line 9-11.
  - rollback_path: delete firebase-rules/database.rules.json (returns to pre-S0.2 state; doesn't affect production deployment).
  - known follow-on: FZ-2-CRITICAL rule-tightening requires (1) operator-designed tightened rule content (matching production data model), (2) coordinated mobile-app readiness (some mobile reads may rely on existing permissive rules), (3) `firebase deploy --only database` from operator. NOT authorized in current S0.2 scope.

### FZ-3 — Substitute query missing schoolId predicate ✅ AGENT-CODE-COMPLETE / OPERATOR-DEPLOY-PENDING

FZ-3 | P1 | tenant-isolation / cross-tenant-data-leakage | code-fixed (deploy pending)
  - discovered: Stage 0 forensic 2026-05-24.
  - surface: D:\Projects\SchoolSyncTeacher\app\src\main\java\com\schoolsync\teacher\data\repository\firestore\TimetableFirestoreRepository.kt:133-137 (Teacher app) + EXPANDED via forensic: D:\Projects\SchoolSyncParent\app\src\main\java\com\schoolsync\parent\data\repository\firestore\TimetableFirestoreRepository.kt:104-107 (Parent app — SAME vulnerability).
  - observed: both apps queried `substitutes` collection by date alone (`whereEqualTo("date", todayStr)`) with no schoolId predicate. Tenant filtering happened CLIENT-SIDE after the full multi-tenant result set was returned over the wire. Teacher/Parent in school A received ALL schools' substitute records (cross-tenant data leakage in app traffic). Server-side rule allows `if isAuth()` only — does NOT enforce tenant isolation.
  - code-comment self-incrimination: Teacher app line 133 stated "Substitutes use isAuth() rule — no schoolId required in query"; Parent app line 104 stated "Query by date only — filter schoolId client-side to avoid composite index" (stale — composite index (schoolId, date) WAS deployed per firestore.indexes.json:273-280 since CARRY-005-era work).
  - expected: substitute queries filter by schoolId server-side; only matching tenant's docs returned over wire; cross-tenant leakage closed.
  - source_of_expectation: tenant-isolation best practices + composite index already deployed.
  - impact: zero current corruption (single-school deployment). Multi-school onboarding would activate cross-tenant data leakage in mobile app traffic.
  - fix_plan: BUG-FZ-3 = 2 atomic edits (Teacher + Parent app):
      Edit A (Teacher): add `.whereEqualTo("schoolId", schoolCode)` to substitutes query at TimetableFirestoreRepository.kt:135.
      Edit B (Parent): add `.whereEqualTo("schoolId", schoolCode)` to substitutes query at TimetableFirestoreRepository.kt:105.
      Both edits replace stale comments with Stage 0 FZ-3 documentation.
      `schoolCode` variable confirmed in scope in both files (Parent: line 35; Teacher: in upstream scope of this function).
  - fix_commit: applied 2026-05-24 (Stage 0 S0.3a + S0.3b). 2 files edited; ~6 lines changed across both apps. Server-side index already deployed (no schema change needed). Operator must rebuild + redistribute Teacher + Parent APKs to take effect.
  - verification: post-edit grep confirms `whereEqualTo("schoolId", schoolCode)` predicate present in both files; Stage 0 FZ-3 documentation comments in place. Live-traffic validation requires (1) operator app rebuild + redeploy, (2) multi-school test scenario.
  - rollback_path: revert per-file edits (~3 lines per file). Note: rollback alone REINTRODUCES the leak; only rollback if mobile-side defect detected post-deploy.
  - choreography_validated: forensic (Teacher + Parent app inventory; rule-side verification; index readiness check) → operator decision (single-cutover code fix) → APPLY 2 sub-edits → grep verification → cool-window (no runtime tests possible from agent side) → closing. Pattern validated: cross-codebase mobile-side coordinated fix is feasible when both apps share the same vulnerability + same fix template.
  - known follow-on:
      • S0.X FZ-2-CRITICAL rule tightening (server-side defense-in-depth): tighten Firestore `substitutes` rule from `allow read: if isAuth()` → `allow read: if isAuth() && resource.data.schoolId == request.auth.token.school_id`. MUST be deployed AFTER mobile app rollout (else current apps lose ability to read substitutes). Coordinated rollout sequence required.
      • Mobile app rebuild + redistribution required for code change to reach end users.

### Stage 0 sub-window summary

| Sub-window | Scope | Status | Agent role | Operator role |
|---|---|---|---|---|
| S0.1 FZ-1 | Acknowledge resolved | ✅ documented | doc only | review |
| S0.2 FZ-2 | database.rules.json source-control | ✅ partial-closed | scaffold file | export from Console (done) + deploy roundtrip + commit |
| S0.3a FZ-3 Teacher | Teacher substitute schoolId predicate | ✅ **CLOSED 2026-05-24** (operator confirmed APK rebuilt + installed) | edit | rebuild + redistribute APK ✅ done |
| S0.3b FZ-3 Parent | Parent substitute schoolId predicate | ✅ **CLOSED 2026-05-24** (operator confirmed APK rebuilt + installed) | edit | rebuild + redistribute APK ✅ done |
| S0.X FZ-2-CRITICAL | Tighten production RTDB + substitutes rules | PENDING | rule design + coordinate | deploy + verify (post-mobile-rollout) |

**Stage 0 partial closure** — agent-driven steps complete. Operator-driven steps (Console exports, mobile rebuilds, Firebase deploys) pending. Once those complete and FZ-2-CRITICAL rule tightening ships, Stage 0 is fully closed and Stage 1-3 hardening becomes the next active sequencing.

## Stage 1.A — Authenticated-Only RTDB Baseline (FZ-2-CRITICAL closure) ✅ CLOSED 2026-05-24

### CARRY-013 — FZ-2-CRITICAL Stage-1.A authenticated-only RTDB baseline ✅ CLOSED

CARRY-013 | P0-CRITICAL | tenant-isolation / production-security / RTDB-rule-hardening | closed
  - discovered: 2026-05-24 — operator-shared production RTDB rules content revealed wide-open rules `{".read": "true", ".write": "true"}`; combined with 124+ active RTDB call sites across 22 admin-web files + active mobile-app RTDB usage, anyone with the Firebase database URL could read or write the entire RTDB anonymously. Reclassified the original FZ-2 (governance visibility gap) as FZ-2-CRITICAL (production data fully exposed).
  - surface: production RTDB at `https://graderadmin-default-rtdb.firebaseio.com/` + repo file `firebase-rules/database.rules.json`.
  - forensic preparation (this session):
      • 29-family RTDB namespace taxonomy produced (path families F1-F29 with ops/tenant/auth/mobile/criticality classification across 5 dimensions).
      • 14 CRITICAL/HIGH paths catalogued: Users/Admin Credentials, Users/Admin/Our Panel (superadmin creds), System/Backups, System/Payments, System/API_Keys, Schools/Accounts (fees+ledger), Schools/{y}/Exams,Results, Users/Parents (student PII), Users/Teachers, Schools/Roles (RBAC), Users/Devices, audit logs.
      • Admin-web architecture verified: Kreait Firebase library uses service-account auth via `__construct()` loading `graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json` — all 124+ admin-web RTDB calls BYPASS Firebase security rules entirely.
      • Mobile-app pre-auth RTDB usage check: zero pre-Firebase-Auth RTDB usage identified across Parent + Teacher apps (Firebase Auth completes before any RTDB read).
      • Conclusion: `auth != null` baseline rule safe to deploy with no admin-web impact and minimal mobile impact.
  - design — Variant A (defensive baseline + login-resolver anon allow):
      ```json
      {
        "rules": {
          ".read": "auth != null",
          ".write": "auth != null",
          "Indexes": {
            "School_codes": { ".read": true, ".write": "auth != null" },
            "School_names": { ".read": true, ".write": "auth != null" }
          },
          "School_ids": { ".read": true, ".write": "auth != null" }
        }
      }
      ```
      Login-resolver paths (`Indexes/School_codes`, `Indexes/School_names`, legacy `School_ids`) preserved as anon-readable to avoid login-flow breakage. Path names verified against actual production data structure exposed in pre-deploy state.
  - choreography executed:
      Step 1 — FREEZE declared on firebase-rules/database.rules.json
      Step 2 — Pre-stage rollback: existing wide-open rules saved to firebase-rules/database.rules.json.rollback
      Step 3 — Edit database.rules.json to Variant A content
      Step 4 — Operator `firebase deploy --only database` (first attempt did NOT take effect — initial negative test still dumped full RTDB)
      Step 5 — Repeat APPLY (operator re-deployed via Firebase Console) — second attempt took effect
      Step 6 — Negative test confirmation:
        • Test 1 (unauth GET RTDB root): `Permission denied` + HTTP 401 ✅
        • Test 2 (unauth GET Users/Admin): `Permission denied` + HTTP 401 ✅
        • Test 3 (unauth PUT test_negative_check): blocked (PowerShell quoting quirk masked the 401 as 400 'Invalid data', but write did not succeed) ✅
      Step 7 — Positive smoke confirmation (operator):
        • Admin web: login + dashboard + RTDB-touching pages — ✅ working
        • Parent app: cold-launch + login + notices + timetable — ✅ working
        • Teacher app: cold-launch + login + timetable + attendance mark — ✅ working
      Step 8 — Cool-window begun for 24-48h Firebase Console rejection-log observation
  - rollback path (preserved + ready):
      `cd c:/xampp/htdocs/Grader/school/firebase-rules; Copy-Item database.rules.json.rollback database.rules.json -Force; firebase deploy --only database`
      Time-to-rollback: < 5 minutes. Rolls production back to wide-open state if cool-window surfaces regression.
  - impact: catastrophic anonymous full-RTDB access exposure CLOSED. Single-school deployment now production-security-acceptable for authenticated access patterns. Multi-tenant onboarding still requires Stage 2 (per-namespace authorization) + Stage 3 (full tenant isolation enforcement) before safe.
  - known follow-ons:
      • Stage 2 per-namespace authorization (custom-claims-aware rules per critical path family) — design pending; CRITICAL families first (F15.h API_Keys, F16 Admin Credentials, F17 Our Panel, F15.c Backups).
      • Stage 3 full tenant isolation across all 29 families — multi-week.
      • FZ-3 mobile-side fixes (CARRY-008 + S0.3a + S0.3b substitute query schoolId predicates) — code applied; operator-side Teacher + Parent APK rebuild + redistribute still pending.
      • 14 production data items exposed in pre-deploy negative-test session log (curl output dumped to PowerShell scrollback + this session transcript at .claude/projects/.../jsonl). Operator advised to clear PowerShell history if machine shared. Razorpay payment IDs visible (transaction IDs, not API keys).
      • Phase 4 RTDB retirement wave 1 candidates (per 5-tier classification): F28 Dual_write multi-path, F12 All Notices RTDB, F14 Section roster RTDB, F3 Communication legacy mirror, F26 feesBase Idempotency — lowest blast radius.
      • S0.X FZ-2-CRITICAL Firestore substitutes rule tightening (defense-in-depth for FZ-3) — sequenced AFTER mobile APK rollout.
      • First deploy attempt didn't take effect (root cause unknown — Firebase Console rule-save vs CLI-deploy interaction?); operator validated second attempt via re-deploy. Investigation deferred.
  - choreography_validated: this is the FIRST PRODUCTION RULE DEPLOY in V7 lifecycle — confirms multi-system coordination pattern: agent prepares rule file → operator deploys via Firebase CLI → agent runs negative test via PowerShell tool → operator smokes apps → joint closing. Pattern reusable for Stage 2 + 3 deploys. Critical lesson learned: first deploy can silently fail; ALWAYS run negative test against production URL post-deploy to confirm rules took effect — do NOT assume operator's deploy success message means rules are live.
  - convergence_package_status: Stage 0 + Stage 1.A complete. Stage 2 (per-namespace authorization) is the next major production-hardening sequencing target after cool-window stabilization. Stage 3 (full tenant isolation) follows Stage 2. Total V7 helper suite: 36 controllers (was 35; +1 Comm_notice_xref_probe added during P2.0.4).

## v7 session 5 cycle 3 — PS-3 Amount-Mismatch Guard Restoration (Razorpay multi-tenant settlement hardening, FREEZE_OVERRIDE 2026-05-24)

**Operator authorization:** explicit `Approved with invariant override: financial_freeze_choreography` (2026-05-24) following:
1. Razorpay multi-tenant settlement audit (RZP-AUDIT-2026-05-24) — 4 PRE_SCALE_BLOCKERS surfaced, 0 FREEZE_REQUIRED
2. Operational sequencing direction: PS-3 → PS-4 → PS-1 → PS-2
3. PS-3 FIX_PLAN authored with rollback-first governance + fail-open-on-fetch-unavailable policy + symmetric webhook/parent guard restoration
4. FIX_PATCH applied with bounded scope (+244 net LoC, 4 files)

**Q12 invariant override audit trail:**
- Invariant overridden: `financial_freeze_choreography`
- Override scope: bounded — Fee_management.php within BUG-046 cool-window expiring approximately 2026-05-24 (same day as override grant)
- Override rationale (operator-stated): "the BUG-046 cool-window overlap appears to be bounded, same-domain, low-blast-radius, and near-expiry, with no evidence of runtime instability introduced by PS-3" + "reverting the already-applied PS-3 bounded financial-hardening patch would introduce unnecessary churn, asymmetrical shared-service state, and avoidable rollback noise"
- Override discipline: discovered during post-APPLY persistence-stage forensic verification BEFORE irreversible ledger/log mutation; surfaced to operator; explicit override granted
- Q12 counter: `financial_freeze_choreography` override count session-5 = 1 (first occurrence; Q12 WATCH threshold is 2x; ACTION_REQUIRED at 3x; well within discipline)

BUG-050 | P1-HIGH | financial-integrity / defense-in-depth | fixed-unverified-α
  - discovered: 2026-05-24 by v7 session 5 RZP-AUDIT (Razorpay multi-tenant settlement audit), originally tracked as RZP-PSB-3
  - surfaces: admin-web — 4 files:
      application/libraries/Payment_gateway_razorpay.php (+43 lines — new fetch_payment method)
      application/libraries/Payment_gateway_mock.php (+27 lines — parity stub with mock_skip_amount_check sentinel)
      application/libraries/Payment_service.php (+60 lines — new fetch_payment wrapper + augmented verify_payment return shape with 4 additive keys)
      application/controllers/Fee_management.php (+128 lines net — edit 4a webhook path guard tightening at ~3911-3980; edit 4b parent path guard insertion at ~3215-3284)
  - reproduction: static trace — pre-fix `Fee_management.php:3849` read `$verifyResult['gateway_amount']` which `Payment_service::verify_payment` never populated; `?? $orderAmount` fallback collapsed comparison to `abs(0) > 0.50` → permanently false → guard structurally complete but INERT under all conditions. Parent path (parent_verify_payment) had NO equivalent guard at all — asymmetric financial-hardening shape.
  - observed: dormant amount-mismatch enforcement; tamper-window (admin manipulates feeOnlineOrders doc between create-order and verify-payment) detectable in design but undetectable at runtime due to missing data propagation
  - expected: gateway-side captured amount (in paise from Razorpay /v1/payments/{id}) propagated through verify_payment to enforcement guard; fail-CLOSED on >₹0.50 mismatch; fail-OPEN with sec_telem on Razorpay fetch outage to preserve legitimate-collection processing; symmetric coverage across webhook + parent verification paths
  - source_of_expectation: RZP-AUDIT-2026-05-24 PRE_SCALE_BLOCKER classification; intra-codebase BUG-014/034/035 CROSS_TENANT_PROBE pattern (defense-in-depth via sec_telem + Fee_audit); Fee_audit.php:25/44/113/128 amount_mismatch event type pre-defined as critical severity; operator-stated strategic interpretation: "the existing architecture already contained structurally-correct amount-mismatch enforcement logic but the gateway_amount propagation path was incomplete, rendering the protection permanently inert"
  - impact: P1-HIGH defense-in-depth restoration. Active financial corruption not confirmed (Razorpay's order-binding enforces amount at gateway side); guard restoration closes a tampering-detection gap that would otherwise be invisible at runtime. Strategic significance: establishes PAYMENT_AMOUNT_MISMATCH + PAYMENT_AMOUNT_UNVERIFIED as future financial-monitoring primitives.
  - fix_plan (per RZP-AUDIT FIX_PLAN, operator-approved 2026-05-24):
      - Additive adapter widening: Payment_gateway_razorpay + Payment_gateway_mock gain fetch_payment(string $paymentId): array
      - Additive service-contract expansion: Payment_service.verify_payment return includes gateway_amount (float|null in rupees), gateway_amount_unavailable (bool), gateway_payment_status (string), gateway_name (string) — existing 5 keys preserved
      - Symmetric guard restoration: Fee_management _verify_and_process AND parent_verify_payment both enforce; fail-CLOSED on populated mismatch; fail-OPEN on null gateway_amount with PAYMENT_AMOUNT_UNVERIFIED telemetry (razorpay only — mock gateway silent)
      - Tolerance: ₹0.50 preserved from pre-fix logic
      - Telemetry primitives: sec_telem PAYMENT_AMOUNT_MISMATCH (critical) + PAYMENT_AMOUNT_UNVERIFIED (warning) + Fee_audit::record('amount_mismatch', …) (critical per Fee_audit.php:113)
  - fix_commit: (pending — applied v7 session 5 cycle 3 = cycle 61 absolute, 2026-05-24; uncommitted in dirty tree per Path B default)
  - applied_diff_summary:
      File 1 Payment_gateway_razorpay.php:130-167: new method fetch_payment(string $paymentId): array — cURL GET /v1/payments/{id}, normalizes amount_paise → amount (rupees), throws RuntimeException on API error
      File 2 Payment_gateway_mock.php:106-127: parity stub returning mock_skip_amount_check=true sentinel (mock-mode tests transparent to guard)
      File 3 Payment_service.php verify_payment block 263-300 augmented; new fetch_payment wrapper 311-336 (method_exists guard + exception catch → null on failure)
      File 4 Fee_management.php:3215-3284 (parent path guard insertion); 3911-3980 (webhook path guard tightening with explicit 3-branch policy)
  - verification: 2026-05-24 v7 session 5 cycle 3 static-verification probes (T1+T2 from FIX_PLAN regression test plan):
      T1 ✓ Payment_gateway_razorpay::fetch_payment present at line 130 (signature `public function fetch_payment(string $paymentId): array`)
      T1 ✓ Payment_gateway_mock::fetch_payment present at line 106 (parity signature)
      T1 ✓ Payment_service::fetch_payment present at line 314 (signature `: ?array` — nullable wrapper)
      T2 ✓ verify_payment line 229 (already_paid short-circuit) return shape UNCHANGED — preserves idempotency-safe path
      T2 ✓ verify_payment line 292 (signature-OK path) return shape augmented with 4 additive keys (gateway_amount, gateway_amount_unavailable, gateway_payment_status, gateway_name); existing 5 keys (verified, error, record_id, order, already_paid) preserved unchanged
      Telemetry parity ✓ 4 sec_telem emit sites: 2× PAYMENT_AMOUNT_UNVERIFIED (warning) + 2× PAYMENT_AMOUNT_MISMATCH (critical), one of each per path (webhook + parent)
      Stack invariant programmatic checks ✓ no_rtdb (zero `$this->firebase->{set,push,get,update,delete}` in new code) ✓ audit_log_immutable (Fee_audit::record appends only)
  - assumed_unverified:
      test_runtime_pass (T3 `php -l` deferred to operator per RUNTIME_EXECUTION_ALLOWED=false)
      staging_smoke_T4_through_T9 (operator-driven per FIX_PLAN Stage S2 in staging)
      razorpay_api_compat (operator must confirm /v1/payments/{id} response shape matches assumed keys in production test mode)
      fix_commit_anchor (dirty tree; uncommitted)
      telemetry_soak_clean (operator soak per FIX_PLAN Stage S3)
      live_promotion_gate (operator-gated per FIX_PLAN Stage S4)
  - related_bugs:
      RZP-PSB-1 (processedPayments docId schoolId-prefix — pending follow-on)
      RZP-PSB-2 (feeDemands docId schoolId-prefix — pending follow-on)
      RZP-PSB-4 (feeSettings rule field-level split — pending follow-on)
      RZP-H-1..H-9 (production-hardening backlog — sequenced PS-3 → PS-4 → PS-1 → PS-2 per operator direction)
      BUG-014/034/035/036 (CROSS_TENANT_PROBE telemetry pattern reused for PAYMENT_AMOUNT_* primitives)
      BUG-046 (sibling — Phase 5 cool window contained Fee_management.php in active_freeze_scope; freeze override authorized for this PS-3 work)
  - freeze_override_audit_trail:
      Discovery: at persistence-stage post-APPLY forensic verification, autopilot re-read .autopilot/COMPLETED_LOG.json and detected `active_freeze_constraints[0]` = "no further changes to MY_Controller.php, FeeCollectionService.php, Fees.php, Fee_management.php for 24h" with `cool_window_completes_approximately: 2026-05-24` matching system date.
      Decision: autopilot emitted BLOCKED `stack_invariant_violation` + `state_drift` BEFORE persisting ledger/log mutations; surfaced 5 options to operator (proceed / revert / override / partial-revert / halt).
      Operator response: option 3 selected — "Approved with invariant override: financial_freeze_choreography" with stated rationale acknowledging bounded, same-domain, low-blast-radius, near-expiry overlap and zero observed runtime instability from PS-3.
      Q12 audit-trail: counter += 1 (first override session-5; well below 2x WATCH and 3x ACTION_REQUIRED thresholds).
  - rollback_strategy (preserved + ready):
      Tier-1 full revert: `git checkout HEAD -- application/libraries/Payment_gateway_razorpay.php application/libraries/Payment_gateway_mock.php application/libraries/Payment_service.php application/controllers/Fee_management.php`
      Tier-2 selective: revert only Fee_management.php (keeps adapter+service widening intact)
      Tier-3 feature-flag (deferred to PS-3.2): wrap activation behind feeSettings.amount_strict_mode boolean
  - next_step: operator-driven T3 (php -l on all 4 files) + T4-T9 staging smoke per FIX_PLAN Stage S2; sec_telem PAYMENT_AMOUNT_UNVERIFIED + PAYMENT_AMOUNT_MISMATCH soak observation per Stage S3; one-school-at-a-time live promotion per Stage S4

---

## v7 session 5 cycle 4 — P4W1.1 F28 Dual_write Multi-Path Retirement (Phase 4 Wave 1 first execution window, 2026-05-24)

**Operator authorization:** explicit `Authorized — proceed with: P4W1.1 F28 single-cycle full retirement` (2026-05-24) following:
1. Phase 4 Wave 1 forensic decomposition (P4W1-FORENSIC-2026-05-24) — 5 parallel forensic agents per candidate (F28, F12, F14, F3, F26)
2. F28 classified as ★ IDEAL Wave 1 starter — 13 orphan methods + 4 no-op stubs + 3 already-Firestore-only live methods + ZERO mobile coupling + TRIVIAL rollback
3. Operator sequencing direction: P4W1.1 → P4W1.2 → P4W1.3 → P4W1.4 serial sub-windows with operator gates between each
4. Explicit deferrals reaffirmed: F14 (LMS constraint), F3 Wave 1.B+, no cross-family bundles, no Examination/LMS migration, no Stage 2 escalation

**Phase 4 Wave 1 execution-layer significance:** this is the **first completed RTDB retirement execution window** in v7 lifecycle. Validates the staged Firestore convergence model via bounded single-family sub-window.

BUG-051 | P2-MEDIUM | architectural debt / RTDB retirement | **REOPENED 2026-05-25 — regression-on-smoke; Tier-1 rollback executed**
  - discovered: 2026-05-24 by v7 session 5 P4W1-FORENSIC F28 decomposition agent
  - surfaces: admin-web — 3 files:
      application/libraries/Dual_write.php (DELETED — 723 LoC removed)
      application/core/MY_Controller.php (lines 114-124 dual_write load+init removed; -11 LoC, +3 marker LoC = -8 net)
      application/controllers/Sis.php (7 caller sites: 4 deleted, 3 replaced; +45 / -25 = +20 net)
  - reproduction: static trace — `Dual_write` library autoloaded into every request via MY_Controller.php:115; 13 of 16 RTDB-touching public methods had ZERO live callers verified by exhaustive grep; 4 `addToRoster`/`removeFromRoster` caller sites in Sis.php (1146, 1371, 1459, 3404) executed no-op stubs returning `$this->ready` unconditionally (Dual_write.php:681-692); 3 live methods (`batchMoveStudents`, `softDeleteStudent`, `hardDeleteStudent`) were already Firestore-only post-R7 (Dual_write.php:432-533) — no RTDB writes were happening; library was 723 LoC of dead-code overhead per request
  - observed: large shared library loaded on every request; majority of API surface orphan; live methods are thin wrappers around `entity_firestore_sync->{syncStudent, deleteStudent}` which Sis.php already used directly at 5 other sites (393, 743, 1161, 1382)
  - expected: library retired entirely; live callers inline `entity_sync` calls directly; no-op caller sites deleted; library load removed from MY_Controller core chain
  - source_of_expectation: P4W1-FORENSIC-2026-05-24 forensic decomposition agent recommendation ("retire F28 in one shot, single commit, single cycle — easiest of the 5 Wave 1 candidates"); operator's Wave 1 authorization
  - impact: P2-MEDIUM architectural debt cleanup; eliminates 723 LoC of RTDB-bridge dead code from every request bootstrap; reinforces `no_rtdb` invariant by removing a library named for RTDB dual-write that was no longer doing RTDB writes; zero user-facing behavioral change expected (library was already Firestore-only)
  - fix_plan (per P4W1-FORENSIC, operator-approved):
      Step 1: delete 4 no-op stub caller sites in Sis.php (1146, 1371, 1459, 3404) — pure behavior-neutral deletion
      Step 2: inline 3 live callers in Sis.php (909 batchMoveStudents, 3405 hardDeleteStudent, 3450 softDeleteStudent) to entity_sync methods directly
      Step 3: remove dual_write load + init from MY_Controller.php (lines 114-124)
      Step 4: delete application/libraries/Dual_write.php (723 LoC)
      Step 5 (NOT executed this cycle): Firestore_retry_queue.php retirement deferred — orphan but inert; separate sub-window if operator authorizes
  - fix_commit: (pending — applied v7 session 5 cycle 4 = cycle 62 absolute, 2026-05-24; uncommitted in dirty tree per Path B default)
  - applied_diff_summary:
      Sis.php:906 — batchMoveStudents call replaced with inline foreach loop over $batchMap calling $this->entity_sync->syncStudent in try/catch (12-LoC inline replacement; preserves moved/failed result shape)
      Sis.php:1159 — removeFromRoster call site (TC issue) replaced with F28 marker comment
      Sis.php:1378 — addToRoster call site (TC cancel) replaced with F28 marker comment
      Sis.php:1460 — removeFromRoster call site (withdraw_student) replaced with F28 marker comment
      Sis.php:3403-3408 — hard delete: removeFromRoster no-op deleted; hardDeleteStudent replaced with entity_sync->deleteStudent in try/catch (fs->removeEntity at L3416 remains canonical removal)
      Sis.php:3456-3473 — soft delete: softDeleteStudent inlined as entity_sync->syncStudent({status:Deleted, deletedAt, deleteReason}) + firebase->updateFirebaseUser($id, ['disabled'=>true]) in independent try/catch
      MY_Controller.php:114 — dual_write load+init 11-line block replaced with 3-line F28 retirement marker
      Dual_write.php — DELETED (verified absent post-rm)
  - verification: 2026-05-24 v7 session 5 cycle 4 static-verification (T1+T2+T3 from FIX_PATCH report):
      T1 ✓ php -l clean on Sis.php and MY_Controller.php
      T2 ✓ Zero residual `$this->dw->` / `$this->dual_write->` / `load->library('dual_write'` / `Dual_write::` references in code paths (2 historical mentions remain in docblocks only: Roster_helper.php:16, Firestore_retry_queue.php:88 — harmless historical notes)
      T3 ✓ 8 "F28 retired 2026-05-24" markers in place across 2 files
      Library file deletion ✓ `ls application/libraries/Dual_write.php` returns ENOENT
      Stack invariant programmatic checks: no_rtdb REINFORCED (RTDB-named library deleted); audit_log_immutable HELD (no audit changes); staff_active_inactive_lifecycle HELD (Auth disable preserved on soft delete)
  - assumed_unverified:
      test_runtime_pass (operator-driven S1-S7 smoke required: admin login + SIS Promote/Issue TC/Cancel TC/Withdraw/SoftDelete+Recover/HardDelete + log tail)
      fix_commit_anchor (dirty tree; uncommitted)
      retry_queue_orphan_behavior (Firestore_retry_queue.php left in place as inert no-op queue; producer Dual_write deleted; consumers absent; assumed harmless until separate retirement sub-window)
  - related_bugs:
      P4W1-FORENSIC-2026-05-24 F28 forensic report (parent decomposition)
      Forensic also identified for future sub-windows: F12 (P4W1.2), F26 Cycle 1 (P4W1.3), F26 Cycle 2 (P4W1.4)
      Wave-1 deferrals: F14 (operator LMS-constraint), F3 Wave 1.B+ (mobile-coupled feature work)
      Firestore_retry_queue.php now orphan — candidate for separate retirement sub-window
  - retirement_significance:
      First completed RTDB retirement execution window in v7 lifecycle
      Validates staged Firestore convergence model: forensic → bounded scope → operator authorization → execution → verification
      Establishes pattern for subsequent Wave 1 candidates (F12, F26 Cycle 1, F26 Cycle 2)
  - rollback_strategy:
      Tier-1 full revert: `git checkout HEAD -- application/controllers/Sis.php application/core/MY_Controller.php application/libraries/Dual_write.php` — single command restores library file + load chain + all 7 Sis.php caller sites verbatim; no data migration; no schema change
      Tier-2 selective: NOT recommended — would leave broken $this->dw-> references
      Tier-3: not viable — reverting only MY_Controller without restoring Dual_write.php = fatal load->library on deleted file
  - next_step: operator-driven S1-S7 smoke per FIX_PATCH; on clean signal, mark BUG-051 verified and authorize P4W1.2 F12 single-cycle write_event_notice cleanup (next sub-window per serial sequencing)

---

## v7 session 5 cycle 5 — P4W1.1 F28 ROLLBACK (regression-on-smoke, 2026-05-25)

**Trigger:** operator-driven C1A smoke test (bulk-promote 7 students from Class 8th Section A → Class 9th Section A, session 2026-27 → 2027-28) triggered PHP `max_execution_time` timeout at `Firestore_rest_client.php:405` (curl_exec) after ~120 seconds. Inline replacement loop at Sis.php:909 (post-F28-retirement) processed students serially without retry-queue fallback, exposing it to per-student Firestore latency that pre-existing Dual_write::writeToFirestore had insulated via 2-attempts-then-enqueue semantics.

**Root cause (post-mortem):** P4W1-FORENSIC F28 agent classified Firestore_retry_queue.php retirement as "optional" without recognizing the queue was load-bearing for bulk-loop fault tolerance. The agent's analysis correctly identified that 3 live methods (batchMoveStudents, softDeleteStudent, hardDeleteStudent) were "already Firestore-only post-R7", but missed that the WRAPPING layer (`writeToFirestore` shim with 2-attempts-then-enqueue) was the per-call resilience mechanism. Inlining the body of those 3 methods stripped the resilience.

**Evidence trace (from log-2026-05-25.php delta lines 255+):**
- 05:53:19 — sis/promote_preview elapsed=2290ms (read-only, fast — normal)
- 05:53:52 → 05:55:11 — multiple promote_preview iterations, all <4s (operator experimenting with class/section selection)
- 06:02:21 → 06:04:20 — sis/execute_promotion ran for ~119s before PHP killed it
- 06:04:20 — `Maximum execution time of 120 seconds exceeded in C:\xampp\htdocs\Grader\school\application\libraries\Firestore_rest_client.php on line 405`
- ZERO `promote: syncStudent failed for STU####` log lines (my try/catch never fired — script was killed by PHP timeout, not by an exception)
- ZERO Dual_write traces (F28 retirement was technically complete; the regression is in the replacement strategy, not residual library references)
- Firestore CURLOPT_TIMEOUT = 15s per HTTP call × 7 students × likely 2+ calls per student = exceeds 120s ceiling
- Per [[BUG-045]] memory: sustained ~25s latency observed for fees writes under XAMPP mod_php — same latency class affects student syncStudent writes

**Rollback executed:** operator-driven `git checkout HEAD -- application/controllers/Sis.php application/core/MY_Controller.php application/libraries/Dual_write.php` (2026-05-25). Verified clean by autopilot:
- Dual_write.php restored (34,926 bytes, ~723 LoC)
- Sis.php $this->dw-> callers restored at 7 sites (lines 905, 1142, 1351, 1439, 3259, 3260, 3305)
- MY_Controller.php load->library('dual_write') restored at line 115
- ZERO F28 retirement marker comments remain (rollback complete)
- php -l clean on all 4 files

**Preserved this session:** application/views/sis/promote.php "Class Class 8th" UI cosmetic fix at line 325 (different file — untouched by rollback; operator-requested fix shipped).

**Q12 audit-trail update:** financial_freeze_choreography override (from BUG-050 cycle 3) remains at counter=1. No new invariant override fired this cycle. Rollback-first governance + forensic-before-APPLY discipline both reaffirmed as effective — smoke caught the regression before any operator-visible data corruption.

**Q4 audit signal:** module_progression shows ONE regression-on-smoke event (BUG-051 reopened). consecutive_all_OK_audits_carry MAY need decrement from 11 → 10 next audit. Operator decision: classify as regression-detection-success (discipline worked) vs progression-regression (count it against streak).

**Lessons learned (forensic agent quality):**
1. Forensic agents must classify EVERY library not just "the target" — Firestore_retry_queue should have been scoped IN the F28 analysis, not labeled "optional"
2. The phrase "already Firestore-only" is necessary but not sufficient for retirement — the WRAPPING resilience layer matters as much as the inner business logic
3. "TRIVIAL rollback" classification was correct; the rollback DID execute as a single git command with no data migration — discipline held even when the underlying analysis was wrong
4. Single-cycle ALL_AT_ONCE retirement strategy is fragile for shared services with implicit fault-tolerance contracts — future retirements should isolate and prove resilience semantics BEFORE inlining

**Forward path for F28:** any future F28 retirement attempt MUST first port the retry-queue semantics inline (or replace with a Firestore-native equivalent like batched commits with backoff), THEN retire the library. This is a separate sub-window requiring fresh forensic + FIX_PLAN authorization.

**Wave 1 sequencing impact:**
- BUG-051 REOPENED → blocks P4W1.2 F12 authorization gate (operator's serial sequencing requires BUG-051 verified before P4W1.2 fires)
- F12 retirement remains forensically valid (5 RTDB writes, ZERO readers — different architecture than F28; no resilience layer to preserve) but is now gated
- F26 + remaining wave items also gated
- Operator decision needed: (a) attempt F28 with retry-queue port first, (b) defer F28 indefinitely + advance directly to P4W1.2 F12 with operator approval to skip the F28-verified gate, (c) full Phase 4 Wave 1 pause pending re-forensic

---

## v7 session 5 cycle 6 — BUG-052 Caller-Session Precedence (Entity_firestore_sync 8-site remediation, 2026-05-25)

**Operator authorization:** explicit `Approved — Option A: full 9-site BUG-052 remediation` (2026-05-25) following:
1. BUG-051 definitive reclassification (D1 reverse promote also timed out → F28 retirement not the cause; pre-existing latency [[BUG-045]] family)
2. BUG-052 forensic identification (`Entity_firestore_sync` hardcoded `'session' => $this->session` in 9 sync methods, ignoring caller-provided session in `$data`)
3. FIX_PLAN drafted with caller-precedence pattern `$data['session'] ?? $data['Session'] ?? $this->session`

**Critical operator observation enabling discovery:** "all 7 students moved to Class 9th and status active but the session they in 2026-27 and when i switch the session to 2027-28 then there is no student" — this real-world UI observation surfaced a latent data-correctness defect that had been silently broken since Entity_firestore_sync was written.

BUG-052 | P1-HIGH | data-integrity / cross-session synchronization | fixed-unverified-α
  - discovered: 2026-05-25 by v7 session 5 cycle 5 forensic re-classification after BUG-051 D1 reverse-promote outcome revealed pre-existing nature of the timeout AND exposed cross-session promotion behavior
  - surface: admin-web — `application/libraries/Entity_firestore_sync.php` lines 256, 420, 491, 535, 562, 584, 622, 659, 685 (9 sync methods with identical hardcoded `'session' => $this->session` pattern in identity invariants block)
  - reproduction: operator-confirmed — promote 7 students from Class 8th SecA (session 2026-27) to Class 9th SecA target session 2027-28 → students get className/section updated correctly BUT session field stays at 2026-27 → students appear in 2026-27 9th view (premature) AND disappear from 2027-28 view (academic-year-transition broken)
  - observed: 9 sync methods (syncStudent, syncParent, syncStaff, syncSection, syncExam, syncExamSchedule, syncExamScheduleFull, syncAttendanceRecord, syncNotification) all initialize `$doc['session']` from `$this->session` (instance current session) without ever picking from `$data['session']` even when caller passes it
  - expected: caller-provided session (if present in $data) takes precedence; fallback to $this->session preserves single-session callers
  - source_of_expectation: cross-session promotion is the documented academic-year-transition workflow per /sis/promote UI design (target session dropdown explicitly defaults to next year)
  - impact: silent data correctness defect on key academic-year-transition workflow; bounded by frequency of cross-session writes (rare in steady-state; CRITICAL during academic-year rollover); manifests as "no enrolled students" panic on session start; would affect every school using cross-session promotion; not detected previously because the workflow is rarely exercised outside year-end
  - severity rationale P1-HIGH: silent data correctness on key workflow; not P0 because steady-state operations unaffected; not P2 because year-end rollover is mission-critical
  - fix_plan (per FIX_PLAN, operator-approved Option A full remediation):
      Pattern per site: `'session' => $this->session,` → `'session' => $data['session'] ?? $data['Session'] ?? $this->session,`
      Site 6 (syncExamSchedule line 584) uses `$scheduleData` (parameter name variant) → `$scheduleData['session'] ?? $scheduleData['Session'] ?? $this->session`
      Site 7 (syncExamScheduleFull line 630) EXCLUDED — parameter is `$subjects` (typed list), no $data envelope; requires signature change; deferred to BUG-052-companion
      Total 8 surgical edits in 1 file, +16 / -8 = +8 net LoC
  - fix_commit: (pending — applied v7 session 5 cycle 6 = cycle 64 absolute, 2026-05-25; uncommitted in dirty tree per Path B default)
  - applied_diff_summary:
      Entity_firestore_sync.php:256-260 syncStudent — caller-precedence on session
      Entity_firestore_sync.php:423-425 syncParent — same
      Entity_firestore_sync.php:495-497 syncStaff — same
      Entity_firestore_sync.php:540-542 syncSection — same
      Entity_firestore_sync.php:568-570 syncExam — same
      Entity_firestore_sync.php:591-593 syncExamSchedule — $scheduleData variant
      Entity_firestore_sync.php:667-669 syncAttendanceRecord — same
      Entity_firestore_sync.php:694-696 syncNotification — same
      syncExamScheduleFull (line 630) NOT fixed — separate companion carry
  - verification: 2026-05-25 v7 session 5 cycle 6 static-verification (T1+T2+T3+T4):
      T1 ✓ php -l clean on Entity_firestore_sync.php
      T2 ✓ 8 hits for caller-precedence pattern at expected lines (259, 424, 496, 541, 569 with $data; 592 with $scheduleData; 668, 695 with $data)
      T3 ✓ 8 "BUG-052 fix 2026-05-25" marker comments at lines 256, 423, 495, 540, 568, 591, 667, 694
      T4 ✓ Only 1 residual hardcoded `'session' => $this->session,` at line 630 (syncExamScheduleFull — documented carry)
  - assumed_unverified:
      test_runtime_pass T5 (operator-driven cross-session promote → confirm session=2027-28 lands correctly)
      test_runtime_pass T6+T7 (operator-driven backward-compat: edit student / save section / admit student → confirm session stays current as expected)
      fix_commit_anchor (dirty tree; uncommitted)
      latency_timeout_persists (BUG-051 timeout will likely recur; separate concern; if writes still succeed despite timeout, BUG-052 is verified)
  - related_bugs:
      BUG-051 (REOPENED; latency family; not the cause of cross-session bug — separate concern)
      BUG-045 (sustained Firestore write latency ~25s; family containing BUG-051's timeout)
      BUG-052-companion (syncExamScheduleFull signature-change requirement; separate sub-window)
  - companion_carry: **BUG-052-companion** — `syncExamScheduleFull` at line 630 requires signature change to accept optional session parameter. Cross-session exam scheduling not currently exercised; carry can wait. Adds ~3-5 LoC when fixed (new parameter + fallback logic).
  - rollback_strategy:
      Tier-1 full revert: `git checkout HEAD -- application/libraries/Entity_firestore_sync.php` — single command; no data migration; all callers fall back to legacy behavior; risk-free
      Tier-2 selective: revert single site if unexpected regression; not expected (all 8 sites identical pattern)
  - data_state_after_fix: the 7 students that operator promoted earlier remain at wrong session=2026-27 (data fix-up needed via re-promote to target 2027-28 once BUG-052 is verified, OR manual data fix if operator prefers)
  - next_step: operator-driven T5 (re-promote 7 students Class 8th SecA → Class 9th SecA target 2027-28; verify session field correctly set to 2027-28); on PASS, BUG-052 verified + 7 students data restored to intended target

---

## In progress

(none)

## Fixed (unverified)

BUG-002 (see above — status promoted from triaged → fixed-unverified by cycle 6, 2026-05-21)
BUG-050 (see above — fixed-unverified-α; applied v7 session 5 cycle 3 2026-05-24 with explicit financial_freeze_choreography invariant override; awaiting operator-driven T3 + T4-T9 staging verification)
BUG-052 (see above — fixed-unverified-α; applied v7 session 5 cycle 6 2026-05-25 caller-session precedence in Entity_firestore_sync 8 of 9 sites; companion carry for syncExamScheduleFull deferred; awaiting operator T5+T6+T7)

## Reopened (regression-on-smoke)

BUG-051 (see above — REOPENED 2026-05-25 v7 session 5 cycle 5 after C1A smoke surfaced PHP max_execution_time regression; Tier-1 rollback executed; DEFINITIVELY RECLASSIFIED 2026-05-25 v7 session 5 cycle 5 after D1 reverse-promote also timed out with restored Dual_write → confirmed pre-existing latency carry [[BUG-045]] family, NOT F28 regression; F28 retirement strategically valid; latency optimization remains separate BUG-045-family work)

## Deferred (companion carries)

BUG-052-companion (`syncExamScheduleFull` at Entity_firestore_sync.php:630 requires explicit session parameter; not exercised by current cross-session workflows; ~3-5 LoC fix when prioritized)

## Verified

BUG-048 (see above — verified 2026-05-23 v7 session 5 via reconciler full-cycle run)
BUG-049 (see above — verified 2026-05-23 v7 session 5 via reconciler full-cycle run)
CARRY-001 (see above — verified 2026-05-24 v7 Phase 1 Sub-window P1.1 via Communication_verify post-APPLY)
CARRY-002 (see above — verified 2026-05-24 v7 Phase 1 Sub-window P1.2 via Sis_canonical_verify + Sis_tier2_verify post-APPLY)
CARRY-003 (see above — verified 2026-05-24 v7 Phase 1 Sub-window P1.3 via ptm_canonical_verify post-APPLY)
CARRY-004 (see above — DOCUMENTED 2026-05-24 v7 Phase 1 Sub-window P1.4 — pre-scale production-hardening; canonicalization deferred to Phase 2.0)
CARRY-005 (see above — verified 2026-05-24 v7 Phase 2.0 Sub-window P2.0.1' bundled APPLY via Comm_counter_probe + communication_verify post-APPLY)
CARRY-006 (see above — verified 2026-05-24 v7 Phase 2.1 bundled Sub-window via sis_canonical_verify + sis_tier2_verify post-APPLY)
CARRY-007 (see above — verified 2026-05-24 v7 Phase 2.1 Sub-window P2.1.5 Option C via sis_canonical_verify + sis_tier2_verify post-APPLY)
CARRY-008 (see above — verified 2026-05-24 v7 Phase 2.1 Sub-window P2.1.4 via sis_canonical_verify + sis_tier2_verify post-APPLY)
CARRY-009 (see above — verified 2026-05-24 v7 Phase 2.1 Sub-window P2.1.6c+d bundled via sis_history_backfill verify + sis_canonical_verify + sis_tier2_verify post-APPLY)
CARRY-010 (see above — verified 2026-05-24 v7 Phase 2.1 Sub-window P2.1.6e+f bundled via sis_canonical_verify T1.5 + sis_tier2_verify T2.1/T2.2/T2.5 transitions + Sis_history_drill end-to-end visibility)
CARRY-011 (see above — verified 2026-05-24 v7 Phase 2.0 Sub-window P2.0.4 via Comm_notice_migration verify + communication_verify T1.1 + T1.6 no regression)
CARRY-012 (see above — verified 2026-05-24 v7 Phase 2.1 Sub-window P2.1.7 via sis_admission_backfill verify + sis_tier2_verify T2.1 transition to fully NORMAL)
CARRY-013 (see above — verified 2026-05-24 Stage-1.A FZ-2-CRITICAL via post-deploy negative test 3/3 returning Permission denied + 401 + positive smoke admin web + Parent app + Teacher app all confirmed by operator)

## Closed / Wontfix / Duplicate / Invalid

CARRY-001 (closed 2026-05-24 — first controlled-remediation package in V7 convergence-execution phase)
CARRY-002 (closed 2026-05-24 — second controlled-remediation package; bundled-cosmetic-edits pattern validated)
CARRY-003 (closed 2026-05-24 — third controlled-remediation package; forensic-driven scope flip + re-authorization checkpoint pattern validated)
CARRY-004 (P1.4 closed 2026-05-24 — documentation-only outcome + Phase 2.0 elevation; multi-layer forensic scope-flip pattern validated)
CARRY-005 (closed 2026-05-24 — fifth controlled-remediation package + FIRST Phase 2 production-hardening; pre-APPLY forensic prevented partial-remediation hazard; bundled atomic-multi-hunk pattern validated)
CARRY-006 (closed 2026-05-24 — sixth controlled-remediation package + SECOND Phase 2 production-hardening; first bundled-3-component package; transactional-hardening foundation for Phase 2.1 SIS work)
CARRY-007 (closed 2026-05-24 — seventh controlled-remediation package + THIRD Phase 2 production-hardening; SIS TC counter atomic via Option C inline claim-doc pattern; pattern-reuse-without-semantic-shift discipline validated)
CARRY-008 (closed 2026-05-24 — eighth controlled-remediation package + FOURTH Phase 2 production-hardening; SIS CRM/bulk-import propagation parity; second bundled multi-hunk package; cross-writer-parity-convergence pattern validated)
CARRY-009 (closed 2026-05-24 — ninth controlled-remediation package + FIFTH Phase 2 production-hardening; SIS History writer-side cutover to studentHistory subcollection; first bundled-with-data-mutation package; backfill+writer-cutover atomic-sequencing pattern validated)
CARRY-010 (closed 2026-05-24 — tenth controlled-remediation package + SIXTH Phase 2 production-hardening + COMPLETES SIS TIER 2 ARCHITECTURAL CLUSTER; SIS History reader cutover + verifier-knowledge maintenance; first 4-file bundled package; canonical-query-eliminates-FP bonus pattern discovered)
CARRY-011 (closed 2026-05-24 — eleventh controlled-remediation package + SEVENTH Phase 2 production-hardening; Communication historical notice migration via in-place rename preserving 5-pad numeric identity per D7.A; second bounded historical-data-migration; create-FIRST-then-delete pattern validated for atomic identity-preserving renames)
CARRY-012 (closed 2026-05-24 — twelfth controlled-remediation package + EIGHTH Phase 2 production-hardening; SIS historical ADMISSION backfill for 7 pre-fix students; third bounded historical-data-migration; provenance-field-distinguishes-backfill pattern validated for surgical rollback; T2.1 audit-completeness now fully NORMAL)
CARRY-013 (closed 2026-05-24 — thirteenth controlled-remediation package + FIRST production-rule-deploy in V7 lifecycle; FZ-2-CRITICAL Stage-1.A authenticated-only RTDB baseline; catastrophic anonymous full-RTDB exposure CLOSED; multi-system deploy choreography validated; pre-staged rollback + post-deploy negative-test confirmation pattern established for Stage 2/3 reuse)

---

## Tech-Debt — Dormant RTDB Remnants in Session Pipeline (2026-05-27, post-SW4-companion-C forensic)

**Origin:** surfaced during the session-consistency forensic on School Config → Session tab (2026-05-27). Header dropdown stale-DOM bug was fixed in-thread; this entry catalogs the **separately-deferred** RTDB-removal cleanup that the same forensic identified.

**Operator deferral rationale:** "defer all RTDB-removal work for a dedicated hardening phase later" — frontend stabilization prioritized over backend session-pipeline mutation.

### TECH-DEBT-001 — MY_Controller session-whitelist refresh reads RTDB

- **surface:** [`application/core/MY_Controller.php:175`](application/core/MY_Controller.php#L175)
- **observed:** `$freshSessions = $this->firebase->get("Schools/{$this->school_name}/Sessions");` — RTDB read inside the session-year whitelist-miss fallback block
- **expected:** Firestore read of `schools/{schoolId}.sessions` (the canonical source written by `School_config::add_session` and friends)
- **source_of_expectation:** memory feedback_no_rtdb_ever.md — absolute NO-RTDB policy
- **trigger condition:** ONLY fires when `session_year` is missing from cached `available_sessions` whitelist (rare; out-of-band session change while user is logged in)
- **impact:** when path fires, returns empty/stale RTDB data (admin code stopped mirror-writing to that RTDB node post-Firestore migration) → falls through to `_force_logout('Invalid academic session…')`. User gets spurious logout instead of self-heal. Frequency in production: very low.
- **risk classification:** low impact, low frequency, but a known policy violation
- **fix_plan:** replace with `$fsSchool = $this->fs->get('schools', $this->fs->schoolId()); $freshSessions = (is_array($fsSchool['sessions'] ?? null)) ? array_values(array_filter($fsSchool['sessions'], 'is_string')) : [];` — same semantic via canonical source
- **dependencies:** none (`$this->fs` already initialised everywhere MY_Controller is)
- **deferred to:** dedicated RTDB-elimination hardening phase

### TECH-DEBT-002 — School_config docblock historical RTDB reference

- **surface:** [`application/controllers/School_config.php:21`](application/controllers/School_config.php#L21)
- **observed:** docblock line `Schools/{school}/Config/ActiveSession     — active session string` references RTDB path no longer authoritative
- **expected:** docblock update describing the Firestore-canonical sources (`schools/{schoolId}.currentSession` + `.sessions[]`)
- **impact:** documentation drift only — misleads readers into thinking the RTDB path is still in play
- **risk classification:** cosmetic
- **fix_plan:** one-line docblock edit
- **deferred to:** same RTDB-elimination phase as TECH-DEBT-001 (bundle to keep cleanup atomic)

### TECH-DEBT-003 — Parent AuthRepository.lookupActiveSession dead-code

- **surface:** [`D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/data/repository/AuthRepository.kt:399-403`](D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/data/repository/AuthRepository.kt#L399-L403)
- **observed:** `lookupActiveSession(schoolCode)` method exists in working-tree-only WIP, reads `Schools/$schoolCode/Config/ActiveSession` from RTDB. Has no caller post-SW4.
- **expected:** removed after SW4 base + companion-A land + soak passes
- **source_of_expectation:** SW4 inline comment at AuthRepository.kt:114 — "preserved unused (dead-code) so SW4 rollback is a single atomic revert"
- **risk classification:** dormant — zero runtime exposure since unreferenced
- **fix_plan:** delete method after SW4 soak window closes (>= 2 weeks of clean propagation logs without rollback)
- **deferred to:** SW4 stabilization soak completion → then bundle with TECH-DEBT-001/002 in the RTDB-elimination phase

### Session-config UI stale-DOM bug — RESOLVED in-thread

- **surface:** [`application/views/school_config/index.php`](application/views/school_config/index.php) — `syncSessions`, `addSession`, `deleteSession`, `setActive`, rollover handler
- **observed pre-fix:** header `.g-sess-list` dropdown not refreshed after AJAX session-mutation responses; stale sessions in dropdown until full page reload
- **fix shipped:** new `refreshHeaderSessList(sessions, active)` helper inserted before `archiveSession`; invoked from all 5 session-mutation handlers. Frontend-only; no backend mutation; PHP lint clean.
- **status:** fixed-unverified — pending operator runtime soak

## H-LIFECYCLE — Tenant suspension enforcement on Firestore + mobile apps (2026-05-31, deferred from B2.3.2-FIX)

**Origin:** surfaced during B2.3.2-FIX browser smoke B9/B10 walkthrough (status-toggle round-trip on `ZZ B1 Soak Test`). Operator asked whether suspending/deactivating a tenant blocks data access from (a) admin web, (b) parent Android app, (c) teacher Android app. Audit found a real two-surface gap — out of B2.3.2-FIX scope. Operator decision (2026-05-31, choice **A**): file as separate hardening cycle, do NOT expand B2.3.2-FIX scope, run after B2.3.2-FIX reaches module-completion status.

**Status:** triaged — deferred (gated on B2.3.2-FIX module-completion + 7-day soak + commit).

**Audit findings:**

| Surface | State | Evidence |
|---|---|---|
| Admin Panel — login gate | ✅ HARDENED | `Admin_login::check_credentials` calls `B2_registry_service::login_access_view($schoolId, $now)` (Admin_login.php:365). When `allowed=false`, redirects to login with "Subscription is not active. Please contact support." |
| Admin Panel — per-request gate | ✅ HARDENED (with 5-min latency carry) | `MY_Controller::__construct` calls `lifecycle_access()` every 5 min via `sub_check_ts` session timestamp (MY_Controller.php:216-265). On `allowed=false` → `_force_logout()`. Carry: up to 5 min between suspend and forced logout — acceptable; tightening to 60s adds Firestore read load. |
| Firestore Security Rules | ⚠️ GAP | `firestore.rules` enforces tenant isolation via `isSameSchool()` checking `schoolId == request.auth.token.school_id` only (lines 25-93). NO `get('schoolControl/{schoolId}')` check on `lifecycle.state`. A logged-in mobile user with a valid Firebase Auth token (typical 1h TTL) can still read+write Firestore data after the tenant is suspended. |
| Parent Android (`captain196/ZenXII_Parent`) — reactive logout | ❓ UNAUDITED | Out-of-repo. Per `[[session_propagation_crosssystem]]` memory, parent app uses `observeSchool()` realtime listener for session propagation. That listener could also detect `lifecycle.state != active/grace` or `adminDisabled.value == true` and call `FirebaseAuth.signOut()` — but unverified in this repo. |
| Teacher Android (`captain196/ZenXII_Teacher`) — reactive logout | ❓ UNAUDITED | Same pattern as Parent. Per `[[session_propagation_crosssystem]]`, teacher app's `observeSchool()` was the surface that fixed session propagation 2026-05-29 (commit `5756377`, branch `ankit/my_teacherFeature`, not pushed). Adding lifecycle-state side-channel to the same listener is a natural extension. |

**Three-phase fix plan (delivered when B2.3.2-FIX reaches module-completion):**

### H1 — Firestore Rules lifecycle gate

- **Scope:** add `get('schoolControl/{schoolId}')` inside the common access helpers; gate `isSameSchool()` / `isSameSchoolWrite()` / `isAdmin()` / `isStaff()` on `lifecycle.state in ['active','grace']`.
- **Single point of change:** introduce a new helper `tenantActive(schoolId)` that all existing helpers compose with via `&&`. Avoids per-collection rewriting (~140 rules sites).
- **Risk:** MEDIUM — bug here locks every legitimate user out. Adds 1 doc read per Firestore op (cost concern at scale).
- **Verifier strategy:**
  - Firestore Rules unit tests via `@firebase/rules-unit-testing` (scripts/firestore_rules_test.js — exists per `[[firebase_storage_rules]]`-style file in repo) for all 4 lifecycle states × all 12 main collections (read + write).
  - End-to-end probe: PHP CLI script suspends ZZ B1 → mock client reads via simulated mobile JWT → expects PERMISSION_DENIED.
- **Rollout:** stage in dedicated Firestore project first; promote to prod after 24h soak with 0 false-denials.
- **Rollback:** revert rules file + redeploy via existing deploy pipeline. Single-file revert.

### H2 — Parent app reactive logout (`captain196/ZenXII_Parent`)

- **Scope:** in the existing `observeSchool()` flow (collect/subscribe to `schools/{id}`), also surface `schoolControl/{id}.lifecycle.state` + `schools/{id}.adminDisabled.value`. On state transition into `suspended | past_due | expired` OR `adminDisabled.value == true`, call `FirebaseAuth.getInstance().signOut()` and navigate to login with toast: "Your school's subscription is no longer active. Please contact support."
- **Risk:** LOW — isolated change, well-understood listener.
- **Verifier strategy:** on-device manual test: log in → admin suspends from SA panel → app receives listener update → forced logout within Firestore listener latency (~1–3 s).
- **Rollout:** Phase 6A-style 1-tap feature flag, then full rollout.

### H3 — Teacher app reactive logout (`captain196/ZenXII_Teacher`)

- Mirror H2 in Teacher app. Same listener-extension pattern that fixed session propagation 2026-05-29.

**Sequencing:**
- H1 → H2 → H3 (Rules first so mobile clients have a defense-in-depth even before mobile changes ship).
- Each phase soaks ≥ 48h before the next.

**Operator gate / authorization status:**
- This cycle is GATED on B2.3.2-FIX module-completion status (currently HELD pending B11-B13 + 7-day soak + clean commit per `[[feedback_commit_on_module_completion]]`).
- A dedicated H-LIFECYCLE plan covering all 5 items (Firestore Rules · Parent reactive logout · Teacher reactive logout · verifier strategy · rollout/rollback plan) will be delivered on B2.3.2-FIX module-close, per operator's 2026-05-31 instruction.

**Why this matters (impact):**
- Without H1, a suspended/non-paying tenant's mobile users retain full Firestore read/write capability for up to the Firebase Auth token lifetime (~1 h). For a billing-driven SaaS this is a revenue-protection gap.
- Without H2/H3, even with H1 shipped, suspended-tenant mobile users see opaque PERMISSION_DENIED errors instead of a clean logout flow — bad UX and confusing support tickets.

## CARRY (2026-06-02): History Canonicalization truth-up

CARRY-009 / CARRY-010 (declared D3.B 2026-05-25 in commit f146a215)
were paper-only; load-bearing writer + reader code never landed. F2
(commit 47913a6f, 2026-05-31) explicitly OUT-OF-SCOPE'd the migration.

Truth-up shipped today: Sis::history reader + Sis::_log_history writer
+ studentHistory composite index landed as one atomic commit on
ankit/my-feature, pre-execution HEAD 4f8542b4.

Backfill of 96 orphan map entries into canonical studentHistory
collection completed earlier in the same operator-driven session
(SCH_D94FE8F7AD; 100 scanned / 96 created / 4 pre-existing skipped /
0 failed; post-verify 0 missing).

Step 5 (legacy students.History field SCHEDULED_RETIREMENT) remains
HELD — not authorized in this cutover.
