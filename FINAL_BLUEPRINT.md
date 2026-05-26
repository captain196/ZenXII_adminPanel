# SchoolSync — Final Blueprint (North-Star Spec)

> *"One School. Three Apps. One Source of Truth."*
> The operating system for modern schools.

This document is the **NORTH_STAR_SPEC** referenced by the Quality Hardening Autopilot. It is the canonical statement of what this project aspires to be and the quality bar every change is measured against. It does NOT describe current state — see `PROJECT_STATUS.md` for that.

---

## 1. North Star (one sentence)

A **cloud-native, real-time, multi-tenant ERP for Indian K-12 schools (300–5000 students)** delivered as **three first-class applications — Web Admin, Teacher Android, Parent Android — sharing a single Firebase/Firestore backbone**, priced per-student annually, that replaces the "seven tabs + three vendor logins + two spreadsheets + WhatsApp" status quo with one audit-trail-first, offline-capable, export-anything platform.

---

## 2. The Product

### Customer-facing module catalogue (20 modules, "out of the box")
**Academic & Student Lifecycle** — Admissions · SIS · Academic Planner · Examinations & Results · Report Cards
**Financial** — Fees · Accounting · Payroll
**Operations** — HR · Attendance · Homework · Communication · PTM · Transport · Hostel · Library · Events & Engagement · Safety · Certificates · Inventory & Procurement · Audit & Compliance

### Three-app surface (final shape)
| Surface | Stack | Role |
|---|---|---|
| **Admin Web** | PHP 8 / CodeIgniter 3 on Nginx+PHP-FPM (AWS EC2) | 57 controllers; the audit-writing, idempotent, transactional authority. Service-account holder. |
| **Teacher Android** | Kotlin · Jetpack Compose · Hilt · Firestore offline-cache · Firebase BoM 32.7.4 · minSdk 24 / compileSdk 35 | 25+ feature screens, 23 Firestore repositories. |
| **Parent Android** | same stack as Teacher | 24+ feature screens, 23 Firestore repositories. |
| **iOS (Teacher + Parent)** | planned late 2026 | Platform parity on the mobile surface. |

### Data fabric
- **47 top-level Firestore collections** spanning core entities, academics, attendance, finance, communications, campus life, ops, transport, library, HR/payroll, infra
- **8 Cloud Functions** for FCM dispatch, fee-generation batches, ops alerts, cleanup, smoke validators
- **Firebase Storage** with MIME+size-capped modern paths `lowercase-prefix/{schoolId}/...`; legacy `{schoolName}/...` paths still served during migration
- **No Realtime Database, ever** (absolute policy)

---

## 3. Architecture Commitments

### "Firebase is the bus"
No API gateway. No message queue. No separate sync service. No dedicated push-notification dispatcher. Mobile apps subscribe directly to Firestore — updates arrive in milliseconds. PHP exists as the *audit-writing, idempotent, transactional* writer for everything that can't be safely written from a phone (payments, payroll, journals, multi-doc transactions).

### Tenant isolation
- Every doc keyed by `schoolId` (`SCH_XXXXXX`, 6 hex)
- Firestore Security Rules enforce `request.auth.token.schoolId == resource.data.schoolId` via `isSameSchoolStrict()` / `isSameSchoolWrite()` helpers
- `parent_db_key` vs `school_id` distinction preserved (non-obvious convention)
- Multi-branch school chains run as N tenants under one Super-Admin umbrella

### Identity
- Firebase Auth with **custom claims** (role + schoolId + sub-role)
- No Node/MongoDB auth (removed 2026-03-27)
- Service account for PHP; Firebase ID-token auth for parent-app PHP endpoints
- Staff Active/Inactive lifecycle enforced at login + session + FCM cleanup + subjectAssignments cascade

### Canonical schemas (shape-drift-resistant)
- **Class/Section:** `"Class 8th"` + `"Section A"` exact, via `Entity_firestore_sync::normalizeClassSection`
- **Fees (post-TC-3/3D):** `feeDemands` authoritative, `feeDefaulters` self-healing projection
- **HR:** dual-emit snake+camelCase during migration window; teacher screens read camelCase
- **Messaging:** camelCase only, lowercase inbox role paths

### Money correctness
- Accounting engine with **idempotency, forensics, period locks, balance reconciler**
- **21-scenario simulator** replays accounting before every deploy
- Razorpay (PCI-DSS handled by gateway; system sees only IDs)
- "Freeze → forensic → package → apply" choreography is mandatory for Fees/TC/payment/accounting changes

---

## 4. The Customer

| Segment | Plan tier | Notes |
|---|---|---|
| Pre-schools <100 | Starter | |
| Primary 100–500 | Primary | |
| K-12 500–1500 | Standard | bulk of market |
| 1500–3000 | Premium | |
| 3000+ | Enterprise | |
| Multi-branch trusts | Chain | multi-tenant umbrella |
| Coaching institutes | Coaching | subset of modules |
| Bespoke | Custom | |
| 0–3 months | Pilot | free, no setup |

**Geography:** India-first. Data residency `Asia-South1 (Mumbai)` available on request. No on-prem.
**Buyer:** Principal / owner / trustee. **Users:** office staff, accountants, teachers, parents.
**Pricing:** per-student annual, all staff + parent accounts included, no per-user fees.

---

## 5. The Quality Bar (used by Autopilot as the standard)

Every change is measured against these nine dimensions:

### D1. Functional correctness
- Every documented behavior works as documented
- No silent failure paths; no error swallowing
- API/UI/test contracts stay aligned

### D2. Data integrity
- Multi-step writes are atomic (transaction-wrapped)
- Foreign keys preserved; cascades correct
- Idempotency tokens on user-initiated mutations
- No orphan records possible
- No silent data loss

### D3. Concurrency
- Shared-state mutations protected (counters, status fields, queue heads)
- No read-modify-write without lock or optimistic version
- State-machine transitions guarded
- Inventory decrements, status transitions, queue accepts protected

### D4. Security & authorization
- Every route has appropriate auth middleware
- Every resource lookup verifies ownership (no IDOR)
- No secrets in logs or error responses
- Rate limits on sensitive endpoints
- No SQL injection, XSS, command injection

### D5. UX professionalism
- Every screen handles **loading / empty / error / success** states
- Toasts accurately reflect outcomes
- Forms validate inline
- Destructive actions confirmed
- Buttons disabled during pending operations
- No broken links/buttons; no double-submit

### D6. Accessibility
- Semantic HTML (`<button>` not `<div onClick>`)
- Keyboard navigable
- Focus management on modals/route changes
- Sufficient contrast on text/background pairs
- ARIA labels on interactive elements
- Alt text on images

### D7. Real-life conditions
- Works on slow networks (loading states visible, optimistic UI rolls back on error)
- Dark mode coherent (if supported)
- Works on small screens (touch targets ≥44px, no horizontal scroll)
- Long strings handled (no overflow/overlap)
- Empty data states helpful (not blank)
- Offline reconnect resyncs state cleanly
- Mobile keyboards do not occlude active input

### D8. Performance
- No N+1 queries in list endpoints
- No unbounded loops on user-supplied data
- No blocking ops on UI thread
- setTimeout/setInterval cleanup present
- Bundle sizes within budget

### D9. Observability
- Errors logged with context (ids, timestamps, correlation keys)
- Audit-worthy actions emit audit events (status transitions, deletions, role changes, payments)
- User-facing errors are actionable (not generic "Something went wrong")
- Correlation IDs present in error logs

---

## 6. Trust Posture (customer-facing promises)

| Promise | Source |
|---|---|
| TLS in transit + at-rest encryption | always |
| Tenant isolation at DB layer (not app layer) | rules-enforced |
| Audit log on every state-changing admin action with before/after | `auditLogs`, `academicAuditLog`, `feeAuditLogs`, `attendanceAuditLog` |
| Security telemetry (RBAC denials, sensitive ops) | `security_events` |
| Per-school backups, schedulable, restorable, exportable | always |
| Force password change on first login | always |
| PCI-DSS handled by Razorpay | system sees only IDs |
| Export every dataset to Excel / PDF on demand | always |
| Working toward ISO 27001 + SOC 2 | roadmap commitment |
| Field-level PII encryption (Staff records) | Phase D of Staff Hardening Programme |
| Data residency in `Asia-South1 (Mumbai)` available on request | always |
| No vendor lock-in — full data export on cancellation | always |

**Audit posture is built in, not bolted on.**

---

## 7. Stack Invariants (do NOT change without HIGH-risk FIX_PLAN approval)

1. Admin runs PHP 8 + CodeIgniter 3 + Nginx + PHP-FPM on AWS EC2.
2. Mobile apps are Kotlin + Jetpack Compose + Hilt + Firestore offline-cache.
3. Firestore is the single live datastore. **No RTDB usage, anywhere, ever.**
4. Firebase Auth with custom claims is the only identity layer.
5. Every Firestore doc carries `schoolId`; rules enforce same-school equality on read and write.
6. PHP is the only writer for accounting journals, payroll, payment intents, multi-doc transactions, and anything requiring `serverTimestamp()` with idempotency.
7. Razorpay is the only payment gateway. System never stores card data.
8. Class/Section canonical format is `"Class 8th"` + `"Section A"` exact via `Entity_firestore_sync::normalizeClassSection`. Any new writer must call the normalizer.
9. Fees: `feeDemands` is authoritative, `feeDefaulters` is a self-healing projection. Never write `feeDefaulters` directly.
10. HR canonical: dual-emit snake_case + camelCase; teacher screens read camelCase.
11. Messaging: camelCase fields only; inbox role paths lowercase.
12. Accounting changes follow "freeze → forensic → package → apply" choreography. Engine, reconciler, balance-engine, idempotency are frozen.
13. Every state-changing admin action MUST emit an audit log entry with before/after values.
14. Storage modern paths use `lowercase-prefix/{schoolId}/...`; legacy `{schoolName}/...` paths are deprecated but still served.
15. Risk vocabulary is fixed: `NORMAL` / `WATCH` / `INVESTIGATE` / `FREEZE_REQUIRED`. No invented levels.

---

## 8. Roadmap Arc (visible path)

| Window | Deliverables |
|---|---|
| **Now → Q2 2026** | Close three concurrent stabilization soaks (Homework Attachment Phase 1, school_config Phase 3, Staff Hardening Phase A). Accounting Stages 1–4 confirmed in soak. |
| **Q3 2026** | Homework Phase 2 (parent submission attachments). Live transport GPS tracking (pending cost decision). Denormalization Phases 2-4 (reader cutover gated on Phase 1 drift report). |
| **Q4 2026** | AI-assisted lesson plan recommendations. Inter-school analytics for chains. iOS apps GA. Staff Hardening through Phase D (PII encryption). |
| **2027** | Public API for SIS imports, payroll bureau, accounting exports. Third-party integration marketplace. WhatsApp Business API for admissions. Tally two-way integration. Staff Hardening Phases E–I complete. |
| **Indefinite** | Complete 9-phase RTDB elimination. Retire legacy Storage `{schoolName}/...` paths. Continue Firestore-rules tightening. |

---

## 9. Operating Discipline (part of the blueprint)

The hardening discipline IS a design commitment, not a process artifact:

1. **observe → verify → classify → decide** — no mutations during soak windows
2. **Authorization is scope-bounded** — "acknowledged" is NOT "authorized"; every stage names exactly what is and is not permitted
3. **Verification-first analysis** — audits get corrections logged; claims get verified against running code before action
4. **Rollback-safe additive changes** — every patch reversible in <30 seconds where possible
5. **Risk vocabulary fixed** — `NORMAL` / `WATCH` / `INVESTIGATE` / `FREEZE_REQUIRED`
6. **Cross-system shape-drift checked first** — class/section, fees, messaging, HR schemas verified across all three apps before any new writer ships
7. **Telemetry is a feature, not an afterthought** — every new surface ships with structured event vocabulary (`ACC_*`, `STAFF_SECURITY`, `RBAC_DENIED`) and forensic grep recipes
8. **Empirical proof > architectural prediction** — phase gates require observed data (drift reports, soak metrics, simulator passes)

---

## 10. Brand

- **Name:** SchoolSync
- **Palette:** Deep teal `#0F766E` (primary) · Amber `#F59E0B` (CTA accent) · Indigo `#4338CA` (Admin sub-accent) · Warm amber `#B45309` (Parent sub-accent)
- **Naming quirk preserved:** CSS `--gold-*` variables hold teal values (historical rebrand artifact; intentionally not refactored to avoid cross-file breakage)
- **Voice:** premium SaaS, sophisticated, enterprise-grade — not bright, not playful

---

## 11. The One-Line Test

> A school's principal opens one URL, two of the school's apps are already on every staff member's and parent's phone, every action everyone takes is one Firestore write away from being visible to everyone else who needs to see it, every state-changing action is audited, no data ever leaves the school's tenant boundary, and on the day the school stops paying we hand them every byte back in Excel.

That is the project SchoolSync aspires to be. Every quality bar entry in this document supports that promise. Every change that erodes that promise is a bug.
