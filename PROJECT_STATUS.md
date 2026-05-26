# SchoolSync — Project Status (LIVE_ROADMAP)

**Snapshot date:** 2026-05-21
**Owner:** captain196 (ankitprajapati8134@gmail.com)
**Companion docs:** `FINAL_BLUEPRINT.md` (north-star spec), `BUG_LEDGER.md` (TBD)

This document is the **LIVE_ROADMAP** referenced by the Quality Hardening Autopilot. It describes current state — what is shipped, in-flight, blocked, and queued — for milestone context only. The autopilot does NOT use this as the quality bar; that lives in `FINAL_BLUEPRINT.md`.

---

## 1. Repository State (three systems)

| System | Path | Branch | Working tree | Last commit |
|---|---|---|---|---|
| **Admin** (PHP/CI) | `C:\xampp\htdocs\Grader\school` | `Academic_planner` | 18 modified + 14 untracked (mostly WEBSITE_*.md docs + telemetry library/config files for Staff Phase A) | `83e97f82 Academic_Planner Updates` |
| **Parent** (Kotlin) | `D:\Projects\SchoolSyncParent` | `main` | 5 mod + 2 untracked — Homework Attachment Phase 1 client files | `4ac726a Academic_Planner Updates on parent app` |
| **Teacher** (Kotlin) | `D:\Projects\SchoolSyncTeacher` | `main` | 11 mod + 3 untracked — Homework Attachment + Phase 1b detail-view fix | `28606bb Academic_Planner Updates on Teacher app` |

**Canonical remotes** (all three on captain196 GitHub account):
- Admin → `captain196/ZenXII_adminPanel`
- Parent → `captain196/ZenXII_Parent`
- Teacher → `captain196/ZenXII_Teacher`

**Untracked stabilization code** (`AttachmentUrlValidator.kt`, `HomeworkAttachmentUploader.kt`, `Attachment.kt`) on Parent + Teacher is the shipped Phase 1 MVP work — verified end-to-end but NOT yet committed.

---

## 2. Active Workstreams (THREE concurrent soaks — all in observe mode)

### 2.1 Homework Attachment Phase 1 — MVP COMPLETE, stabilization active
**Shipped 2026-05-15.** End-to-end lifecycle exists:
- **Teacher:** file picker → MIME/size validation → sequential upload to Storage → `deleteByPath` rollback → Firestore dual-write (`attachments` + `attachmentObjects`) → telemetry via `debugLog()`
- **Parent:** dual-shape parser → click-site validator (https + `firebasestorage.googleapis.com` allowlist) → safe `ACTION_VIEW` dispatch
- **Hot-fixes same-day:** F1 (OEM logcat-strip telemetry gap on iQOO/Vivo) and F2 (Teacher detail-view attachment render)
- **Teacher hwId entropy gap closed** (Finding #8 sibling) — Phase 2 hard-prerequisite cleared

**Posture:** observe → measure → classify → decide. Phase 2 (parent submission attachments) gated on:
- `UPLOAD_FAIL` rate < 2% on normal network
- `OPEN_OK` ratio > 95%
- Zero `HOST_NOT_ALLOWED` events from legitimate teacher activity
- Storage orphan ratio bounded (1:1 with rollback `DELETE_OK`)
- No UX friction on real devices
- Large-file (near 10 MB cap) behavior verified on slow network

### 2.2 school_config Phase 3 hardening — soak in progress
**Shipped 2026-05-15.** 6 priority areas across 5 files:
- **Attack-surface lockdown:** diagnostic routes gated behind `GRADER_DEBUG`; redundant `csrf_token` endpoint removed
- **Firestore rules:** legacy permissive `/curriculum` block removed
- **Storage rules:** `/schools/{schoolId}/logo|holidays|academic` blocks added with MIME + size caps
- **Class label normalization:** `_normalize_class_label()` server-side rewrite
- **Inline-onclick XSS removal:** delegated handler with data-attrs
- **Partial-failure UI:** for bulk section/subject saves

**Queued for Phase 4 (NOT authorized):**
- **S1** (MODERATE): filter `already_exists`/`not_found` from failure panel — client-side ~6 lines
- **C1** (low): server returns canonical classes after save; JS adopts them

### 2.3 Staff Security Hardening Programme — Phase A FOUNDATIONAL soak
**Shipped 2026-05-17.** 5 additive patches:
- New: `application/libraries/Security_telemetry.php`, `application/config/security_telemetry.php`
- Modified: `application/libraries/Audit_log_service.php` (collection + entity-type generalisation)
- Modified: `application/core/MY_Controller.php` (`_require_role` RBAC_DENIED telemetry + `_correlation_id()` helper)
- Every `_require_role` denial now writes `security_events/{ts}_{actor}_{8hex}` doc + structured log line

**Programme scope:** 9 phases (A→I), ~30 weeks, driven by `STAFF_RECORDS_AUDIT_PROMPT.md` (35 findings: 10 P0 / 15 P1 / 9 P2 / 1 P3).

**HOLD discipline:** patches 6–15 require canary verification + explicit reauthorisation. Do NOT modify `Staff.php` or touch rules/auth/mobile until then.

### 2.4 Accounting stabilization soak (long-running, separate)
- Concession Stage 1 + Payroll Stages 2-4 shipped 2026-05-10; flag-gated and in soak
- 21 simulator scenarios all PASS
- `firestoreGetParallel` sequential shim landed (8 broken call sites restored)
- R1 historical-imbalance reversal of `JE_20260414193106_f55be96d` applied
- Forensic composite index deployed
- **Locked response mode** for soak telemetry: Observed Signal → Classification → Risk Level → Likely Cause → Recommended Action
- **Risk vocabulary fixed:** `NORMAL` / `WATCH` / `INVESTIGATE` / `FREEZE_REQUIRED`

---

## 3. Recent Module-Level Shipped Work (since 2026-04-15)

| Module | Status |
|---|---|
| **Homework remediation** | 14 findings shipped, 9 documented deferrals, 4 audit corrections logged; operationally stable |
| **Denormalization Integrity Phase 1** | Observability instrumentation live; 2-4 week window from 2026-05-15 collecting drift telemetry; Phase 2 reader cutover gated on data |
| **Fees Phase 8** | FCM push reminders end-to-end; `feeReminderLog` rules + index; ISO-8601 `sent_date`; refund timeout 120s→600s |
| **Fees Phase 9** | Wallet/advance-balance subsystem removed (admin) |
| **Razorpay** | Test-mode live admin + parent; parent PHP endpoints use Firebase ID-token auth |
| **Attendance Firestore migration** | Phase 7 complete; `tardy` canonical, `late` legacy alias one cycle |
| **Communication RTDB → Firestore** | 60→13 RTDB calls; remaining 13 are SIS/FCM-blocked |
| **Staff Active/Inactive lifecycle** | 4 phases shipped 2026-04-28 |
| **Teacher auth gate** | `_get_teacher_assignments` now reads Firestore `subjectAssignments`, not RTDB |
| **Phase 6A Teacher 1-tap Red Flag** | Shipped 2026-05-11; repository-level defense still pending |
| **HR canonical (dual-emit)** | Payroll/Appraisals/Recruitment dual-emit snake+camelCase since 2026-04-15 |

---

## 4. Cross-Cutting Policies (standing contracts)

- **NO RTDB EVER** — absolute, supersedes prior policies. Zero usage, no fallbacks, no mirrors.
- **Freeze → forensic → package → apply** choreography for any Fees/TC/payment/accounting change.
- **Class/section canonical** — `"Class 8th"` + `"Section A"` exact format via `Entity_firestore_sync::normalizeClassSection`.
  - *Latent gap:* Teacher `RedFlagRepository.kt` long-form path has no Kotlin normalizer — CHECK FIRST on any new writer.
- **Messaging:** camelCase only, lowercase inbox role paths.
- **HR (Payroll/Appraisals/Recruitment):** dual-emit snake+camelCase; teacher screens read camelCase.
- **Fees canonical (post-TC-3/3D):** `feeDemands` authoritative, `feeDefaulters` self-healing projection. Hostel/Transport partition rules apply.

---

## 5. What's Blocking What

| Gate | Blocks |
|---|---|
| Staff Phase A canary verification | Patches 6–15 → Phase B (identity plumbing) → entire 9-phase programme |
| school_config Phase 3 soak triggers | S1 client-side filter + C1 canonical class echo (Phase 4 entry) |
| Homework Phase 1 telemetry criteria (5 thresholds) | Phase 2 parent submission attachments |
| Denormalization Phase 1 drift report (window ends ~2026-05-29 to ~2026-06-12) | Phases 2-4 reader cutover |
| Operator cost decision | RTDB Phase F (live GPS) |
| Operator policy decision | DueDate governance hardening (#1C, #28) |
| Operator UX prioritization | 11 remaining UX polish items |

---

## 6. Notable Uncommitted Local State

- **Admin:** stale dashboard cache JSONs modified; new `application/cache/school_config_locks/` directory + 4 simulator runs in `cache/simulator/`; `firebase-rules/storage.rules.recovery` present; 12 untracked WEBSITE_*.md and PROJECT_*.md analysis docs from prior sessions
- **Parent:** Phase 1 client files unstaged (`Attachment.kt`, `AttachmentUrlValidator.kt`)
- **Teacher:** Phase 1 client + Phase 1b detail-view + multiple `ui/` and `repository/firestore/` modifications unstaged (`Attachment.kt`, `AttachmentUrlValidator.kt`, `HomeworkAttachmentUploader.kt`)

---

## 7. Queued Workstreams (analyzed, not yet authorized)

| Workstream | Findings | Gating condition |
|---|---|---|
| Denormalization Phases 2-4 | #2, #20, #22, #37 | Phase 1 observation window completion + drift report |
| DueDate governance hardening | #1C, #28 | Operator policy decision + dueDate-format canonicalization survey |
| Listener Phase L2 / L3 | #12 (Teacher detail-sheet leak), #35 (Parent leak) | Empirical telemetry showing measurable leak frequency |
| Listener Phase L4 | #43 read-after-write | Future feature requiring write-then-immediately-read semantics |
| UX consistency / polish | #13, #18, #29, #31, #32, #33, #38, #40, #41, #42, #44 | Operator UX prioritization |
| Staff Hardening Phases B-I | 30 remaining findings | Phase A canary verification |
| school_config Phase 4 | S1, C1 + carry-forward items | Phase 3 soak completion |

---

## 8. Carry-Forward (NOT in current scope, NOT in immediate queue)

- `delete_session` CAS gap (read-then-modify-write without `__updateTime` precondition)
- `subjectName`-only assignment edge case in `delete_subject`
- `feeReceipts` dependency gap in `delete_section`
- `save_bulk_subjects` silently drops blank-name rows (line ~2115)
- `display_errors()` may leak server paths in upload error responses
- `getDownloadUrl()` long-lived bearer-token URLs bypass Storage rules (signed-URL candidate)
- Storage rule `request.resource.contentType` is client-asserted (magic-bytes revalidation deferred)

---

## 9. Operator's Working Directories

- **Admin:** `C:\xampp\htdocs\Grader\school`
- **Parent:** `D:\Projects\SchoolSyncParent`
- **Teacher:** `D:\Projects\SchoolSyncTeacher`

All three are reachable from the operator's primary session; treat as one codebase for shape-drift fixes.

---

## 10. Resume Anchors for Future Sessions

If the operator says "resume Staff hardening" → read `staff_hardening_phase_a` memory; HOLD state.
If the operator says "resume Phase 3" → read `phase3_school_config_soak` memory; await Phase 4 authorization.
If the operator says "resume Homework Attachment" → read `homework_attachment_phase1_complete` memory; classify against signal map before any Phase 2 talk.
If the operator says "what happened to my work" → reference WIP snapshot commit `820a2ec6` (10 days of pre-hardening work captured 2026-05-01).
