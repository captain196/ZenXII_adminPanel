# SchoolSync — Comprehensive Project Analysis

**A unified School Management Ecosystem spanning three integrated applications**

---

**Document Version:** 1.0
**Date:** 2026-05-15
**Audience:** Website copy, investor pitch, technical submission
**Format:** Markdown structured for Word import (H1 → Heading 1, H2 → Heading 2, etc.)

---

## Table of Contents

1. Executive Summary
2. Project Overview
3. The Three-System Architecture
4. Technology Stack
5. Backend & Data Architecture
6. Module Catalog
7. Cross-System Data Flows
8. Security, Multi-Tenancy & Compliance
9. Operations, DevOps & Reliability
10. Roadmap & Recent Milestones
11. Appendices

---

# 1. Executive Summary

SchoolSync is a **multi-tenant, cloud-native School Enterprise Resource Planning (ERP)** system designed to digitise the full lifecycle of K-12 schools — from admissions through alumni records — while delivering real-time engagement to two of the school's most important stakeholders: **teachers and parents**.

The product is composed of three tightly integrated applications:

| System | Stack | Primary Users | Role |
|---|---|---|---|
| **Admin Panel** | CodeIgniter 3 / PHP (web) | School administrators, accountants, principals, super-admins | Authoritative source-of-truth; writes flow from here |
| **Teacher App** | Android (Kotlin, Jetpack Compose) | Class teachers, subject teachers, HOD | Real-time class operations |
| **Parent App** | Android (Kotlin, Jetpack Compose) | Parents and guardians | Engagement, payments, communication |

All three share a **single Firebase project** (Firestore + Authentication + Cloud Messaging + Cloud Storage) — meaning a fee paid by a parent appears instantly on the admin dashboard *and* on the teacher's class screen, with no separate sync infrastructure to run.

**Key numbers (as of 2026-05-15):**

- **59** PHP controllers covering ~50 functional modules in the Admin panel
- **23–25** Firestore-backed repositories per mobile app
- **78+** Firestore document schemas shared across all three systems
- **9** subscription plan tiers managed by a Super-Admin SaaS control plane
- **Multi-tenant** — every school is a fully isolated tenant keyed by `school_id` (`SCH_XXXXXX`)
- **Offline-first** mobile apps with persistent Firestore cache
- **Razorpay** integration for online fee collection
- **Biometric / RFID / Face-recognition** device integration for attendance

---

# 2. Project Overview

## 2.1 Problem Statement

Indian K-12 schools typically run on a patchwork of spreadsheets, paper registers, WhatsApp groups, and disjointed point-solutions — one tool for fees, another for attendance, a third for marks. The data fragments. Parents call the office to ask about fee dues; teachers chase paper attendance sheets; administrators spend their afternoons reconciling cash with ledgers.

SchoolSync collapses this into **one tenant-isolated platform** where:

- **Administrators** get a single web console for every operational concern — admissions, fees, accounting, HR, payroll, transport, hostel, library, certificates, communication, and compliance.
- **Teachers** carry a mobile app that lets them take attendance in seconds, push homework with attachments, enter marks, view their timetable, raise red-flags, and message parents — all from the classroom.
- **Parents** carry a mobile app that surfaces their child's attendance, marks, homework, fee dues, school notices, photo galleries, and a direct line to the class teacher — and lets them pay fees in two taps via Razorpay.

## 2.2 Design Principles

1. **Firestore-first.** All new data flows through Firestore. The Firebase Realtime Database (RTDB) is being eliminated; it currently survives only as a backwards-compatibility mirror for legacy fields the mobile apps still read.
2. **Admin panel is authoritative.** Mobile apps read what the admin panel writes. Schema drift between the writer (PHP) and readers (Kotlin) is the recurring failure mode and the one the team actively defends against.
3. **Multi-tenant by default.** Every document, every query, every Firebase Storage path is keyed by `school_id`. There is no shared-tenant data path.
4. **Observe → verify → classify → decide.** Especially in financial modules (Fees, Accounting, Payroll), changes follow a strict freeze-and-soak choreography rather than ad-hoc edits.
5. **No mocked tests for financial paths.** Integration tests hit a real Firestore project; mock divergence has historically masked production-breaking bugs.
6. **Backwards-compatibility via dual-write, never schema break.** When migrating from snake_case to camelCase or PascalCase to lowercase, fields are dual-emitted for at least one release cycle.

## 2.3 Who It's For

- **Private K-12 schools (300–5,000 students)** that have outgrown spreadsheets and want one consolidated platform.
- **School chains / trusts** running multiple branches — each branch is its own tenant under one Super-Admin umbrella.
- **Coaching institutes and pre-schools** can use a subset (admissions, fees, communication) via plan-gating.

---

# 3. The Three-System Architecture

## 3.1 Topology Diagram

```
                       ┌─────────────────────────────────────────┐
                       │      Super-Admin SaaS Control Plane     │
                       │      (plans, schools, monitoring)       │
                       └───────────────┬─────────────────────────┘
                                       │
                                       ▼
            ┌──────────────────────────────────────────────────┐
            │           FIREBASE — single shared project       │
            │                                                  │
            │   ┌──────────────┐    ┌──────────────────────┐  │
            │   │  Firestore   │    │  Firebase Auth       │  │
            │   │  (primary)   │    │  (custom claims)     │  │
            │   └──────────────┘    └──────────────────────┘  │
            │   ┌──────────────┐    ┌──────────────────────┐  │
            │   │  Cloud       │    │  Cloud Messaging     │  │
            │   │  Storage     │    │  (FCM push)          │  │
            │   └──────────────┘    └──────────────────────┘  │
            │   ┌──────────────────────────────────────────┐  │
            │   │  Realtime DB (legacy, eliminating)       │  │
            │   └──────────────────────────────────────────┘  │
            └──────────────────────────────────────────────────┘
                  ▲                  ▲                    ▲
                  │                  │                    │
                  │ writes/reads     │ reads (mostly)     │ reads (mostly)
                  │ AUTHORITATIVE    │                    │
                  │                  │                    │
        ┌─────────┴────────┐ ┌───────┴────────┐  ┌────────┴────────┐
        │   ADMIN PANEL    │ │  TEACHER APP   │  │   PARENT APP    │
        │   CodeIgniter 3  │ │  Android       │  │   Android       │
        │   PHP            │ │  Kotlin /      │  │   Kotlin /      │
        │   XAMPP / EC2    │ │  Compose       │  │   Compose       │
        └──────────────────┘ └────────────────┘  └─────────────────┘
              │                       │                    │
              │                       ▼                    ▼
              │             ┌────────────────┐   ┌──────────────────┐
              │             │ Biometric /    │   │ Razorpay         │
              │             │ RFID devices   │   │ Checkout         │
              │             │ (attendance)   │   │ (fee payment)    │
              │             └────────────────┘   └──────────────────┘
              ▼
        ┌────────────────────┐
        │  Razorpay Webhook  │
        │  (payment_intent)  │
        └────────────────────┘
```

## 3.2 Why Firebase as the Bus

In a traditional 3-system architecture, you would need:
- A REST API gateway between PHP and the mobile apps
- A message queue (RabbitMQ / Kafka) to push real-time updates
- A separate sync service to keep mobile and web in lockstep
- A dedicated push notification dispatcher

By using **Firebase as the shared backbone**:
- Mobile apps subscribe directly to Firestore — real-time updates arrive in milliseconds with no API layer to maintain.
- A fee paid by a parent on the Android app triggers a webhook → admin panel → Firestore write → Teacher app's class-fees screen re-renders automatically.
- FCM handles push notifications uniformly across both apps.
- Cloud Storage delivers homework attachments, gallery photos, certificate PDFs via signed URLs.

**Trade-off accepted:** Firestore reads are billable. The team operates within a documented quota budget and uses sharded counters + denormalised projections (e.g. `feeDefaulters`) to keep aggregate reads cheap.

## 3.3 Tenant Isolation Model

Every document in Firestore is keyed by `schoolId` (format `SCH_XXXXXX`, 6 hex chars). Every Firestore Security Rule begins with a `schoolId` membership check derived from the user's custom Auth claims. There is no global collection that mixes tenants.

```
System/Schools/{school_id}/           — canonical school profile
System/Plans/{plan_id}/               — subscription plans
Indexes/School_codes/{login_code}     — fast school lookup at login
Indexes/School_names/{name_key}       — uniqueness enforcement
Schools/{school_id}/{session_year}/   — academic data (RTDB legacy)
Users/Admin/{school_code}/{admin_id}  — admin credentials
Users/Parents/{school_id}/{user_id}   — parent/student profiles
```

---

# 4. Technology Stack

## 4.1 Admin Panel (Web)

| Layer | Technology |
|---|---|
| Language | PHP 7.4+ |
| Framework | CodeIgniter 3 |
| Server | XAMPP (dev) / Nginx + PHP-FPM on AWS EC2 (prod) |
| Primary store | Firebase Firestore (via REST API client) |
| Legacy store | Firebase Realtime Database (read-mostly, being eliminated) |
| PDF | DomPDF |
| Spreadsheet | PhpSpreadsheet |
| Cache | File-based per-school JSON snapshots in `application/cache/` |
| Auth | Firebase Auth (server-side) with custom claims |
| Payment | Razorpay webhooks |

## 4.2 Teacher App (Android)

| Layer | Technology |
|---|---|
| Language | Kotlin |
| UI | Jetpack Compose (Material 3) |
| Architecture | MVVM + Repository |
| DI | Dagger Hilt |
| Navigation | Compose Navigation |
| Async | Kotlin Coroutines + Flow |
| Local | DataStore (preferences) |
| Network | Retrofit 2 + OkHttp + Gson |
| Firebase | BoM 32.7.4 — Firestore, Auth, RTDB, Storage, Messaging, Analytics |
| Media | Coil (images), Media3 / ExoPlayer (video), Lottie (animations) |
| Build | Gradle KTS, Compose BoM 2024.02.00 |
| SDK | min 24, target 34, compile 35 |

## 4.3 Parent App (Android)

| Layer | Technology |
|---|---|
| Language | Kotlin |
| UI | Jetpack Compose (Material 3) |
| Architecture | MVVM + Repository |
| DI | Dagger Hilt |
| Payment | Razorpay Checkout (v1.6.38) |
| Media | Coil + Media3 + Lottie + Shimmer |
| Firebase | Same shared project as Teacher app |
| Offline | Persistent Firestore cache enabled |

Both Android apps share an architectural skeleton: same packages, same DI modules, same Firebase wrapper utilities. They diverge in surface area (Teacher has marks/attendance writers; Parent has payment + read-only views).

---

# 5. Backend & Data Architecture

## 5.1 Firestore Collections (top-level)

The schemas are shared across all three systems. The Parent and Teacher apps each consume 70+ document types; this list groups them by domain.

### Academic & Results
`students`, `sections`, `subjectAssignments`, `timetables`, `curriculumTopics`, `lessonPlans`, `exams`, `examSchedules`, `marks`, `results`, `attendance`, `attendanceSummaries`, `homework`, `submissions`, `teacherMarks`

### Financial
`feeStructures`, `feeDemands` (**authoritative**), `feeDefaulters` (operational projection), `feeReceipts`, `feeRefundVouchers`, `feeCarryForward`, `feeAdvanceBalance`, `feeOnlineOrders`, `paymentIntents`, `scholarshipAwards`

### Accounting
`journalEntries`, `chartOfAccounts`, `ledgerBalances`, `periodLocks`, `bankReconciliations`, `auditLogs` (financial-specific)

### Human Resources
`staff`, `salarySlips`, `appraisals`, `recruitment`, `training`, `leaveApplications`, `rbacRoles`

### Communication
`messages`, `messageTemplates`, `conversations`, `inbox`, `notifications`, `circulars`, `circularReads`

### Engagement & Content
`events`, `galleries`, `stories`, `storySharedConfig`, `dashboards`

### Campus Life
`hostelAllocations`, `hostelRooms`, `hostelComplaints`, `mealMenus`, `incidents`, `sosAlerts`, `lostFound`, `merit`, `behaviorSummaries`, `redFlags`

### Transport
`routes`, `vehicles`, `studentRoutes`, `geoFences`, `tripLogs`

### Library & Inventory
`libraryBooks`, `libraryIssues`, `libraryFines`, `assets`, `inventory`, `vendors`, `purchaseOrders`

### Parent-Teacher Meetings
`ptm`, `ptmBookings`, `ptmConfig`

### Audit & Surveys
`auditLogs`, `surveys`, `surveyResponses`

## 5.2 The Source-of-Truth Hierarchy

A repeated pattern across the codebase is **authoritative source + operational projection**:

- **Fees:** `feeDemands` is authoritative. `feeDefaulters/{schoolId}_{session}_{studentId}` is a self-healing denormalised cache that the parent dashboard banner, teacher class-fees screen, and admin defaulter list read for fast aggregate queries. Writers converge through `Fee_firestore_sync::syncDefaulterStatus`.
- **Subject assignments:** Previously the same teacher-subject mapping lived in 3 places (staff capability, academic allocation, timetable). Now `subjectAssignments` is the single source of truth; the rest reference it.
- **Attendance:** Daily `attendance` records are authoritative; `attendanceSummaries` is the per-month aggregate read by parents.

This pattern keeps the system fast and operable while preserving a single point of truth for audits and reconciliation.

## 5.3 Authentication & Authorisation

- **Firebase Auth** issues ID tokens for both web admins and mobile users.
- **Custom claims** on each user record include `schoolId`, `role` (Teacher / Parent / Admin / Super-Admin), and `staffId` or `studentIds[]`.
- **Firestore Security Rules** enforce tenant isolation: every read/write rule begins with `request.auth.token.schoolId == resource.data.schoolId`.
- **Force password change** on first login (`ForceChangePasswordScreen` in the Parent app, enforced server-side too).
- The string `"School Super Admin"` is a hard-coded RBAC bypass role for the school-level account; the Super-Admin SaaS control plane uses a separate auth flow.

---

# 6. Module Catalog

This section catalogues every functional module, what it does, and which of the three systems participates. Each module shows admin write capability (W), teacher read/write (T-R, T-W), parent read (P-R), and parent write (P-W) where applicable.

## 6.1 Admissions & Student Lifecycle (SIS)

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Public inquiry form | — | — | (public form) |
| Admission CRM (inquiry → follow-up → conversion) | W | — | — |
| Enrollment (admission) | W | — | — |
| Student profile management | W | T-R | P-R |
| Class promotion | W | — | — |
| Transfer Certificate (TC) generation | W | — | — |
| Alumni records | W | — | — |

The `Sis.php` controller (Admin) is the consolidated student lifecycle entry point. Phase 3D (closed 2026-05-09) wired admission → fee-demand creation via `Sis.php::save_admission` so newly admitted students get a defaulter document immediately, closing a previously latent gap.

## 6.2 Fees & Online Payment

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Fee structure CRUD | W | — | — |
| Demand generation (monthly) | W | — | — |
| Cash / cheque receipt | W | — | — |
| Online payment | W (record) | T-R (class-level) | P-W |
| Defaulter dashboard | W | T-R | P-R |
| Receipt PDF generation | W | — | P-R (download) |
| Refund processing | W | — | — |
| Fee simulation (sandbox) | W | — | — |

Fee architecture is documented in detail in [Section 7.1](#71-fee-payment-flow). The PHP side has 15+ specialised libraries (`Fee_cache`, `Fee_audit`, `Fee_lifecycle`, `Fee_defaulter_check`, `Fee_generation_engine`, `Fee_firestore_sync`, etc.).

## 6.3 Accounting & Payroll

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Double-entry journal | W | — | — |
| Chart of accounts | W | — | — |
| Trial balance, P&L, balance sheet, cash flow | W | — | — |
| Bank reconciliation | W | — | — |
| Period locks | W | — | — |
| Salary slip generation | W | T-R (own) | — |
| Statutory deductions | W | — | — |
| Audit trail | W | — | — |

Accounting is a mature subsystem: 8 dedicated libraries, simulator infrastructure for regression testing, forensic composite indexes, and explicit period-lock state machines. Payroll Stages 2–4 are flag-gated and in active soak.

## 6.4 Attendance

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Student attendance marking | W | T-W (own class) | P-R |
| Biometric / RFID / Face | W | — | — |
| Staff attendance (devices) | W | T-R (own) | — |
| Monthly summary | W | T-R | P-R |
| Late-arrival tracking | W | T-W | P-R |
| Analytics dashboards | W | — | — |

Attendance writes `tardy` as the canonical field; `late` is a deprecated alias still emitted for one release cycle for backward compatibility with older parent app installs.

## 6.5 Academic Planner (Subject Assignments + Timetable + Curriculum)

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Subject assignment (teacher → class × subject) | W | T-R | — |
| Class teacher designation (CT) | W | T-R | — |
| Timetable creation | W | T-R | P-R |
| Curriculum topic tree | W | T-R (own subjects) | — |
| Daily lesson plans | W | T-W (own classes) | P-R (child's class) |
| Substitute teacher allocation | W | T-R | — |

The `subjectAssignments` collection is the single source of truth (see [Section 5.2](#52-the-source-of-truth-hierarchy)). One Class Teacher is enforced per (class, section) at validation time.

## 6.6 Homework

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Create homework with attachments | — | T-W | — |
| File attachment (Storage + Firestore dual-emit) | — | T-W | — |
| View assigned homework | — | T-R | P-R |
| Open/download attachment | — | T-R | P-R (validated) |
| Mark student submission status | — | T-W | — |
| Submission analytics (rate, divergence) | W | T-R | — |

Homework Attachment Phase 1 closed end-to-end on 2026-05-15 with validated attachment opens (HTTPS-only, `firebasestorage.googleapis.com` allowlist, safe ACTION_VIEW dispatch). Phase 2 (parent submissions) is queued behind the hwId entropy-suffix gate to prevent collision-driven attachment loss.

## 6.7 Marks, Examinations & Results

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Exam template / schedule | W | T-R | P-R |
| Marks entry | W | T-W (own subject) | — |
| Grade computation | W | — | — |
| Hall-ticket eligibility (fee gate) | W | — | — |
| Cumulative / progressive results | W | — | P-R |
| Result sheet PDF | W | — | P-R |
| Competition ranking (1, 1, 3 — not dense) | W | — | — |

The grade engine has a PHP source and a mirrored JavaScript implementation inside `marks_sheet.php`; both must move together.

## 6.8 Communication

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Notice / circular publishing | W | T-R | P-R |
| Bulk SMS / Email | W | — | — |
| Parent-teacher messaging | W (monitor) | T-W | P-W |
| Message templates | W | T-R | — |
| Delivery logs | W | — | — |
| Push notifications (FCM) | W (trigger) | T-R | P-R |
| Real-time chat (presence, badges) | — | T-W (RTDB) | P-W (RTDB) |

Communication.php has migrated from 60 RTDB calls down to 13 in Phases 1–5 (Communication RTDB → Firestore migration), with the remaining 13 blocked on SIS/FCM dependencies.

## 6.9 Human Resources

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Staff onboarding / profile | W | T-R (own) | — |
| Multi-role staff (`staff_roles[]` + `primary_role`) | W | T-R | — |
| Leave applications | W | T-W (own) | — |
| Appraisals | W | T-R (own) | — |
| Recruitment pipeline (ATS) | W | T-R (own apps) | — |
| Salary slips | W | T-R (own) | — |
| Training records | W | T-R (own) | — |

Staff Active/Inactive lifecycle (2026-04-28) covers four phases: toggle → login enforcement → session/FCM cleanup → subjectAssignments cascade.

## 6.10 Campus Life

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Hostel allocation, rooms, complaints | W | T-R | P-R |
| Meal menus | W | T-R | P-R |
| Library catalogue, issues, fines | W | T-R | P-R |
| Incidents | W | T-W | P-R |
| Red Flags (behavioural alerts) | W | T-W (1-tap) | P-R |
| SOS alerts | W | T-R | P-R (trigger) |
| Lost & Found | W | T-R | P-R |
| Events & gallery | W | T-W | P-R |
| Stories (short video content) | — | T-W | P-R |

Phase 6A (Teacher 1-tap Red Flag) shipped 2026-05-11.

## 6.11 Transport

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Routes & stops | W | — | P-R |
| Vehicle fleet | W | — | — |
| Student-route assignment | W | — | P-R |
| Driver assignment | W | — | — |
| Geofences | W | — | P-R |
| Trip logs | W | — | P-R |

Live GPS (Phase F of RTDB elimination) is blocked on a Blaze-plan cost decision.

## 6.12 Parent-Teacher Meetings (PTM)

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| PTM scheduling | W | T-R | P-R |
| Slot booking | — | T-W (configure slots) | P-W (book) |
| Attendance | W | T-W | P-R |
| Configuration | W | T-W | — |

## 6.13 Super-Admin SaaS Control Plane

A dedicated control plane (10 controllers under `Superadmin_*`) manages the multi-school product:

- School onboarding, profile, plan assignment, logo upload
- Subscription plans (9 tiers), payment collection, invoice generation
- Cross-school analytics & reports
- System monitoring (login logs, activity logs, error logs, API logs, Firebase usage logs)
- Backup / restore + scheduled backups per school
- Migration tools (RTDB → Firestore, phone-index refactor, auth sync)
- Debug panel with log inspection and schema checks

This is the SaaS layer above the school admin layer.

---

# 7. Cross-System Data Flows

This section shows representative end-to-end flows where state moves across all three systems.

## 7.1 Fee Payment Flow

A parent pays fees online; admin and teacher see it instantly.

```
   PARENT APP                       FIREBASE                   ADMIN PANEL          TEACHER APP
   ──────────                       ────────                   ───────────          ───────────
1. FeesScreen.kt
   "Pay ₹15,000"
        │
        ▼
2. PaymentFlowOverlay
   creates paymentIntent
        │
        ▼
3. Razorpay Checkout ────────────────────────────────────► Razorpay Servers
                                                                  │
                                                                  │ webhook
                                                                  ▼
4.                                                           Payment_intent_listener.php
                                                                  │
                                                                  │ writes
                                                                  ▼
5.                                                           feeReceipts/{id}
                                                                  │ + updates
                                                                  ▼
                                                             feeDemands/{id}.balance
                                                                  │ + syncs
                                                                  ▼
                                                             feeDefaulters/{schoolId}_{session}_{studentId}
                                                                  │
                                                                  │ Firestore push
                          ┌───────────────────────────────────────┤
                          │                                       │
                          ▼                                       ▼
6. PaymentSuccessScreen                                    FeesTeacherViewModel
   FeeFirestoreRepository                                  observes feeDefaulters
   .observeDefaulterStatus()                               class-aggregate counts
   re-renders banner                                       re-render
                                                                  │
                                                                  ▼
                                                             FCM push to all 3 sides
                                                             (parent receipt, teacher
                                                             aggregate, admin dashboard)
```

**Key files involved:**
- Parent: `FeesScreen.kt`, `PaymentFlowOverlay.kt`, `FeeFirestoreRepository.kt`
- Admin: `Payment_intent_listener.php`, `Fees.php` (record payment), `Fee_firestore_sync.php`
- Teacher: `FeesTeacherViewModel.kt`, `FeeRepository.kt`

**Architectural notes:**
- `feeDemands` is the authoritative source; `feeDefaulters` is the cached projection that mobile clients listen to for cheap aggregate reads.
- Hostel and Transport fees are partitioned out of the main fees sum (they have dedicated sub-checks) — including them would double-count.

## 7.2 Homework Assignment Flow

Teacher uploads homework with an attachment; parent sees it on the dashboard.

```
   TEACHER APP                  FIREBASE                          PARENT APP
   ───────────                  ────────                          ──────────

1. HomeworkTeacherScreen
   compose body + pick file
        │
        ▼
2. Upload to Cloud Storage
        │ path: schools/{schoolId}/homework/{hwId}/{filename}
        │
        ▼ returns download URL
3. Firestore dual-emit:
   homework/{hwId} {
     attachments: [...]            ◄── legacy field
     attachmentObjects: [...]      ◄── new structured field
     submittedAt: serverTimestamp()
     dueDate, classId, sectionId,
     teacherId, schoolId
   }
        │
        │ Firestore push notifies all parents
        │ in matching (class, section)
        │
        └───────────────────────────────────────────────► HomeworkScreen.kt
                                                          HomeworkFirestoreRepository
                                                          .observeForStudent(...)
                                                                  │
                                                                  ▼
                                                          Parent taps attachment
                                                                  │
                                                                  ▼
                                                          Validate URL:
                                                          - https:// only
                                                          - firebasestorage.googleapis.com
                                                            host allowlist
                                                                  │
                                                                  ▼
                                                          ACTION_VIEW dispatch
                                                          (system browser or
                                                          installed viewer)
```

**Security guarantees:**
- Firestore Security Rule `isSameSchoolStrict()` blocks cross-tenant existence-oracle attacks.
- Submission writes enforce `submittedAt == request.time` (server-time equality) to prevent backdating.
- Attachment opens are restricted to the firebasestorage.googleapis.com host to prevent malicious redirects.

## 7.3 Attendance Flow

Teacher takes class attendance; parent's dashboard updates the same minute.

```
   TEACHER APP                  FIREBASE                          PARENT APP
   ───────────                  ────────                          ──────────

1. AttendanceScreen
   roster loaded from
   sections + students
        │
        ▼
2. Tap "Present" / "Absent" /
   "Tardy" per student
        │
        ▼
3. AttendanceFirestoreRepository
   batch-write per student:
   attendance/{schoolId}_{studentId}_{YYYY-MM-DD} {
     status: "present" | "absent" | "tardy"
     lateMinutes: 0..N
     markedBy: staffId
     markedAt: serverTimestamp()
   }
        │
        │ AND update aggregate:
        ▼
4. attendanceSummaries/{schoolId}_{studentId}_{YYYY-MM} {
     presentDays, absentDays, tardyDays, totalDays
   }
        │
        │ Firestore push
        │
        └───────────────────────────────────────────────► AttendanceScreen.kt
                                                          observes
                                                          attendanceSummaries
                                                          (monthly view)
                                                          + attendance docs
                                                          (per-day calendar)
```

**Note on canonical fields:** The Teacher app writes `tardy` as the canonical status. Older parent app installs still read `late` — the writer dual-emits both for one release cycle, then `late` will be removed.

## 7.4 Subject Assignment Flow (Admin → both Apps)

Admin assigns Mr. Singh to teach Maths for Class 8 Section A.

```
   ADMIN PANEL                  FIREBASE                          TEACHER APP / PARENT APP
   ───────────                  ────────                          ────────────────────────

1. Academic Planner UI
   Class 8, Section A,
   Subject = Maths,
   Teacher = Mr. Singh (filtered
   dropdown — only teachers with
   "Maths" in staff.teaching_subjects)
   ☑ CT (Class Teacher)
        │
        ▼
2. Subject_assignment_service.php
   - Validate teacher capability
   - Enforce single CT per (class, section)
   - Delete existing for (class, section)
   - Write Firestore
        │
        ▼
3. subjectAssignments/{schoolId}_{session}_Class 8th_Section A_MATH {
     teacherId: SSA0007
     teacherName: "Mr. Singh"
     subjectCode: "MATH"
     subjectName: "Mathematics"
     className: "Class 8th"
     section: "Section A"
     isClassTeacher: true
   }
        │
        │ Firestore push
        │
        ├──────────────────────────────────────────────► TEACHER APP
        │                                                TeacherRepository
        │                                                .observeAssignedClasses()
        │                                                → Mr. Singh sees
        │                                                  "Class 8 - A - Maths"
        │                                                  in his Dashboard
        │
        └──────────────────────────────────────────────► PARENT APP
                                                         MyTeachersFirestoreRepository
                                                         .getTeachersForStudent()
                                                         → Class 8-A parents
                                                           see Mr. Singh as
                                                           Maths teacher + CT badge
```

**The class/section three-representation trap:** Same logical class appears as `"Class 8th"` in Firestore keys, `Class="8th" Section="A"` in student profile fields, and `"8th 'A'"` in fee-path keys. Writers must match the target path's convention; `Entity_firestore_sync::normalizeClassSection()` is the canonical helper.

## 7.5 Red Flag (1-tap) Flow

Teacher flags a behavioural concern; admin and parent are notified.

```
   TEACHER APP                  FIREBASE                          ADMIN + PARENT
   ───────────                  ────────                          ──────────────

1. RedFlagTeacherScreen
   long-press on a student card
   → QuickFlagViewModel
        │
        ▼
2. Pick category (Behaviour /
   Academic / Health), severity,
   optional note
        │
        ▼
3. RedFlagRepository.createFlag(
     studentDoc, severity, ...)
        │
        ▼
4. Firestore write:
   redFlags/{flagId} {
     studentId, classId, section,
     flaggedBy, severity, category,
     note, createdAt: serverTimestamp(),
     schoolId
   }
        │
        │ FCM push notification
        ├──────────────────────────────────────────────► PARENT APP
        │                                                RedFlagScreen.kt
        │                                                shows on dashboard
        │
        └──────────────────────────────────────────────► ADMIN PANEL
                                                         Red_flags.php
                                                         alert thresholds engine
                                                         + counsellor workflow
```

**Known limitation:** Teacher RedFlagRepository.kt long-form path does not yet apply the canonical class/section normaliser (no Kotlin port of `normalizeClassSection`). Documented in `feedback_class_section_first_check.md`.

---

# 8. Security, Multi-Tenancy & Compliance

## 8.1 Authentication

- **Firebase Auth** for all three systems.
- **Custom claims** issued at login carry `schoolId`, `role`, and entity identifiers (`staffId`, `studentIds[]`).
- **Force password change** on first login enforced both client-side (Parent app: `ForceChangePasswordScreen`) and server-side (admin gate before any data write).
- **Tenant-scoped phone index** (`Schools/{school_name}/Phone_Index/{phone}` → userId) replaces an older global index that caused cross-tenant conflicts. Migration tools live in `Superadmin_migration/migrate_phone_index`.

## 8.2 Firestore Security Rules

Recent hardening (2026-05-15 Homework module work):
- `isSameSchoolStrict()` — fail-closed null-resource guard, blocks cross-tenant existence oracle attacks.
- `isSameSchoolWrite()` — guards writes from forging cross-tenant payloads.
- Server-time equality enforcement (`request.resource.data.submittedAt == request.time`) prevents backdating.
- Existing-status guards (`resource.data.get('status', '') in ['', 'submitted']`) enforce state-machine finality.
- Composed `isStaff() || isOwnStudent()` predicates close cross-student parent reads.

## 8.3 Audit & Forensics

- `audit_log_service` writes structured audit entries for every admin state-changing action with before/after values.
- `Accounting_forensics` records disputed-entry chains for financial dispute resolution.
- `AccountingSimulator` is a staging-only CLI replaying 21 scenarios across groups A/B/C/D/E/F/I before production deploys.
- `Accounting_watchdog` runs background integrity checks against the journal.

## 8.4 Multi-Tenancy

- Every Firestore document, every Storage path, every Firebase RTDB node is keyed by `schoolId`.
- Super-admin tools use service-account credentials with broader scope but the school-level auth tokens cannot cross tenants.
- Backup/restore is per-school; there is no cross-school data flow path.

## 8.5 Payment Compliance

- Razorpay handles card storage and PCI-DSS compliance.
- The admin panel only sees payment IDs and order IDs, never raw card data.
- Webhooks are signature-validated before any state mutation.

---

# 9. Operations, DevOps & Reliability

## 9.1 Deployment Topology

- **Admin panel:** XAMPP for dev, Nginx + PHP-FPM on AWS EC2 for production.
- **Android apps:** Distributed via Play Store; teacher app via internal track, parent app via production.
- **Firebase:** Single project; security rules and indexes versioned in `firebase-rules/` inside the admin repo.

## 9.2 The Soak / Freeze Choreography

For financial code (Fees, Accounting, Payroll, TC), the team operates a strict **observe → forensic → package → apply** sequence:

1. **Observe** — Telemetry-only window. No mutations.
2. **Forensic** — Detailed analysis of discrepancies; build a fix package.
3. **Package** — All changes assembled atomically with rollback steps documented.
4. **Apply** — One controlled deploy window; immediate verification.

This is documented in `feedback_freeze_choreography.md` and `accounting_soak_contract.md` (locked 2026-05-10).

## 9.3 Migration Discipline

When schema migrates (e.g. snake_case → camelCase for HR, RTDB → Firestore for Communication, global phone index → tenant-scoped):

- **Dual-write** for at least one release cycle.
- **Legacy paths preserved** until the last mobile-app version reading them is forced-upgraded.
- **One-shot migration scripts** under `scripts/` (Node + firebase-admin) are idempotent and dry-run by default.
- **Verification scripts** read both old and new shapes and report drift before flipping the reader.

The RTDB elimination plan documents 9 phases (A→I) covering ~146 sites across the three systems.

## 9.4 Caching Strategy

- **Per-school cache files** under `application/cache/dashboard/`, `application/cache/attendance/`, etc. give the admin panel sub-100ms dashboard render times even on cold load.
- **Firestore offline persistence** is enabled in both mobile apps for cold-start data.
- **Sharded counters** keep receipt-number generation collision-free under concurrent writes.
- **Dashboard cache** invalidates on financial state change events.

## 9.5 Observability

- `Debug_tracker` library logs per-request timing.
- Structured log lines (e.g. `ACC_DENORM_DIVERGENCE schoolId=X hwId=Y...`) feed a denormalisation observability window.
- Firestore `count()` aggregation used for server-side reconciliation between cached counts and authoritative roster size.
- Recent Phase 1 observability (Homework denormalisation) is currently in a 2–4 week window; Phase 2 reader-cutover is gated on the empirical drift report.

---

# 10. Roadmap & Recent Milestones

## 10.1 Recently Shipped (last 60 days)

| Date | Milestone |
|---|---|
| 2026-05-15 | Homework Attachment Phase 1 MVP — end-to-end Teacher upload → Parent validated open |
| 2026-05-15 | school_config Phase 3 — six priority areas (attack-surface lockdown, XSS removal, MIME caps, class normalisation, partial-failure UI) |
| 2026-05-11 | Teacher pre-rollout cleanup (stale RTDB TODOs cleared, late-time wiring via `lateMinutes`) |
| 2026-05-11 | Phase 6A — Teacher 1-tap Red Flag entry point |
| 2026-05-10 | Accounting stabilisation milestones — Concession Stage 1 + Payroll Stages 2–4 in soak; 21 simulator scenarios PASS |
| 2026-05-10 | Accounting engine repairs — firestoreGetParallel sequential shim, R1 historical-imbalance reversal, forensic composite index |
| 2026-05-09 | Fees canonical architecture closed (TC-3 + Phase 3D) |
| 2026-05-07 | Teacher auth gate migrated to Firestore `subjectAssignments` |
| 2026-04-28 | Staff Active/Inactive lifecycle — 4 phases (toggle, login enforcement, session/FCM cleanup, subjectAssignments cascade) |
| 2026-04-25 | RTDB elimination plan published (9 phases, ~146 sites) |
| 2026-04-17 | Razorpay test-mode live (admin + parent); Parent ERP-bento Dashboard |
| 2026-04-16 | Communication RTDB → Firestore Phases 1–5 (60 → 13 RTDB calls) |
| 2026-04-15 | HR canonical schema (dual-emit snake + camelCase); Firestore rules + indexes deployed |
| 2026-04-08 | Attendance Firestore migration Phase 7 (staff/devices/punches Firestore-first) |
| 2026-04-07 | Subject Assignments single-source-of-truth (6 phases) |
| 2026-03-27 | Firebase Auth migration (Node/MongoDB auth REMOVED) |
| 2026-03-18 | Staff role system (job-function roles separate from admin RBAC) |
| 2026-03-11 | Firebase architecture refactor (`SCH_XXXXXX` primary keys) |

## 10.2 Active Workstreams

1. **Homework Attachment Phase 1 stabilisation** — observe → measure → classify → decide; no new mutations.
2. **school_config Phase 3 soak** — six areas in stabilisation; S1+C1 queued for Phase 4.
3. **Accounting stabilisation soak** — Concession + Payroll in 2–4 week observation window.
4. **Denormalisation Integrity Phase 1** — observability instrumentation for Homework rate-divergence; Phase 2 reader cutover gated on drift report.

## 10.3 Queued (not yet authorised)

- Denormalisation Phases 2–4 (Homework counts)
- DueDate governance hardening
- Listener Phases L2 / L3 / L4
- Teacher hwId entropy gap (must close before Homework Phase 2 — parent submissions)
- TC-4 — canonicalise `Fee_dues_check::check`
- Fee_lifecycle::reassignFeesOnPromotion placeholder fix
- Live GPS in transport (blocked on Blaze cost decision)

## 10.4 Forward-Looking Roadmap

- **Q3 2026:** Parent app submission of homework (Phase 2)
- **Q3 2026:** Live transport GPS tracking
- **Q4 2026:** AI-assisted lesson-plan recommendations
- **Q4 2026:** Inter-school analytics for school chains (Super-Admin)
- **2027:** Public API for third-party integrations (SIS imports, payroll bureau, accounting export)

---

# 11. Appendices

## 11.1 Glossary

| Term | Meaning |
|---|---|
| `SCH_XXXXXX` | School identifier — 6 hex chars; primary tenant key |
| `parent_db_key` | Legacy school login code (used under `Users/Parents/`, `Users/Admin/`) |
| `school_name` | Used in legacy `Schools/{name}/` paths; not the human display name |
| `school_display_name` | Human-readable name shown in UI |
| Session year | `YYYY-YY` format, e.g. `2025-26` — NOT `2025-2026` |
| `feeDemands` | Authoritative fee source |
| `feeDefaulters` | Operational projection; self-healing cache |
| `subjectAssignments` | Single source of truth for teacher-class-subject mapping |
| CT | Class Teacher (one per class+section) |
| TC | Transfer Certificate |
| PTM | Parent-Teacher Meeting |
| RBAC | Role-Based Access Control |
| RTDB | Firebase Realtime Database (legacy, eliminating) |
| FCM | Firebase Cloud Messaging |
| SIS | Student Information System |
| ATS | Applicant Tracking System (recruitment) |

## 11.2 Non-Obvious Conventions

These are the traps that confuse new readers:

1. **CSS `--gold` variables hold TEAL values.** The theme was repainted gold → teal but variable names were preserved to avoid a cross-file rename.
2. **Three class/section representations:** `"Class 9th"` (Firebase keys), `Class="9th" Section="A"` (student profile), `"9th 'A'"` (fee paths).
3. **No `/Classes` sub-node.** Classes live directly as `Class 9th` under the session root.
4. **Competition ranking is 1, 1, 3 — not dense (1, 1, 2).** Used throughout result computation.
5. **`School Super Admin` role bypasses ALL RBAC checks** — hard-coded in `_require_role()` and every const role array.
6. **Dates stored as `d-m-Y` strings** (e.g. `07-04-2026`), not ISO-8601. Android parsers using `LocalDate.parse()` will silently fail.

## 11.3 Key File-Tree Highlights

### Admin Panel
```
C:\xampp\htdocs\Grader\school\
├── application\
│   ├── controllers\      (59 controllers — Admin_login, Fees, Academic, Sis, Staff,
│   │                      Accounting, Payroll, Hr, Homework, Attendance,
│   │                      Communication, Superadmin_*, etc.)
│   ├── libraries\        (55+ domain libraries — Fee_*, Accounting_*, Firestore_*,
│   │                      Subject_assignment_service, Curriculum_service, ...)
│   ├── models\           (Common_model, Common_model1, Common_sql_model)
│   ├── views\            (~50 view directories, one per feature module)
│   ├── config\           (routes.php, database, services)
│   ├── cache\            (per-school JSON snapshots — dashboard, attendance,
│   │                      firebase_auth, dompdf, school_config_locks, simulator)
│   └── core\             (MY_Controller — auth gate, session, school context)
├── firebase-rules\       (firestore.rules, firestore.indexes.json, storage.rules)
├── functions\            (Cloud Functions — payment_intent listener etc.)
├── scripts\              (one-shot Node scripts for migration / verification /
│                          inspection — uses firebase-admin SDK)
└── assets\               (static CSS / JS / images)
```

### Teacher App
```
D:\Projects\SchoolSyncTeacher\
└── app\src\main\java\com\schoolsync\teacher\
    ├── ui\screens\        (~26 screens — Dashboard, Attendance, Marks, Homework,
    │                       Students, RedFlag, Messages, Notices, MyProfile,
    │                       Payslips, Appraisals, Leave, Library, Gallery,
    │                       Stories, Events, MyPtm, ...)
    ├── viewmodel\         (~26 ViewModels — one per screen, Hilt-injected)
    ├── data\
    │   ├── repository\    (23 repositories — *FirestoreRepository,
    │   │                   ChatRtdbRepository, AuthRepository, ...)
    │   └── model\firestore\  (78+ document classes)
    ├── di\                (Hilt modules — FirebaseModule, RepositoryModule, ...)
    └── service\           (FCMService, FirestoreService, ...)
```

### Parent App
```
D:\Projects\SchoolSyncParent\
└── app\src\main\java\com\schoolsync\parent\
    ├── ui\screens\        (~25 screens — Splash, Walkthrough, Login,
    │                       ForceChangePassword, Dashboard, Attendance, Results,
    │                       Homework, Timetable, Fees, ReceiptDetail,
    │                       PaymentFlowOverlay, PaymentSuccess, Messages, Notices,
    │                       Events, Gallery, PtmList, PtmDetail, Library, Leave,
    │                       RedFlag, MyTeachers, MyLessons, Stories, Profile)
    ├── viewmodel\         (36 ViewModels)
    ├── data\
    │   ├── repository\    (32 repositories)
    │   └── model\firestore\  (72+ document classes)
    └── ...
```

## 11.4 Statistics Summary

| Metric | Count |
|---|---|
| Admin panel controllers | 59 |
| Admin panel view directories | ~50 |
| Admin panel libraries | 55+ |
| Teacher app Kotlin files | 209 |
| Teacher app screens (Compose) | ~26 |
| Teacher app ViewModels | ~26 |
| Teacher app Repositories | 23 |
| Teacher app Firestore document models | 78+ |
| Parent app screens (Compose) | ~25 |
| Parent app ViewModels | 36 |
| Parent app Repositories | 32 |
| Parent app Firestore document models | 72+ |
| Subscription plan tiers (Super-Admin) | 9 |
| Default staff roles | 9 |
| Accounting simulator scenarios | 21 |
| Active concurrent soaks (2026-05-15) | 2 |

## 11.5 Conversion to Word (.docx)

This file is structured for direct import into Microsoft Word:

1. Open Word → File → Open → select `PROJECT_ANALYSIS.md`. Modern Word (2021+, M365) imports markdown directly with heading levels mapped to Heading 1/2/3 styles.
2. *Alternatively:* `pandoc PROJECT_ANALYSIS.md -o PROJECT_ANALYSIS.docx --toc --toc-depth=2` produces a Word file with an auto-generated table of contents.
3. *Or:* paste this file into Google Docs, then File → Download → Word (.docx).
4. ASCII diagrams render correctly in Word's default monospace; set the diagram blocks to **Consolas 9pt** for a tighter look.
5. For a presentation deck (PowerPoint / Google Slides), copy Section 1 (Executive Summary), Section 3 (Architecture diagram), and Section 6 (Module Catalog tables) — these slide naturally into 8–12 slides.

---

*End of document. Total length: ~25 pages when imported to Word with default styles.*
