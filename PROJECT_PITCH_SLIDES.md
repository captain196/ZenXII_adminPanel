# SchoolSync — Pitch Deck (for Schools)

**13 slides. One idea per slide. Each `---` is a slide break.**

> **How to present this:**
> - **Marp / Marpit:** `marp PROJECT_PITCH_SLIDES.md -o deck.pptx`
> - **Pandoc → PowerPoint:** `pandoc PROJECT_PITCH_SLIDES.md -o deck.pptx`
> - **Reveal.js:** `pandoc -t revealjs -s PROJECT_PITCH_SLIDES.md -o deck.html`
> - **Manual:** copy each slide block into PowerPoint / Google Slides one-by-one. Each is sized to fit a single 16:9 slide.

---

## Slide 1 — Title

# SchoolSync

### One School. Three Apps. One Source of Truth.

A unified School Management ERP for K-12 schools — combining a web admin panel, a teacher mobile app, and a parent mobile app on a single real-time backbone.

*2026-05-15*

---

## Slide 2 — The Problem

# Schools Run On Chaos

Indian K-12 schools today juggle:

- **Spreadsheets** for fees and marks
- **WhatsApp groups** for parent communication
- **Paper registers** for attendance
- **Point solutions** that don't talk to each other
- **Office phone calls** to ask "what's my fee due?"

**The cost:**
- Administrators reconcile data manually for hours every day
- Teachers carry paper attendance sheets to the office
- Parents lose visibility into their child's school life
- Schools lose money — and reputation — to operational friction

---

## Slide 3 — The Solution

# Three Apps. One Backbone.

| | Admin | Teacher | Parent |
|---|---|---|---|
| **Platform** | Web (any browser) | Android | Android |
| **Stack** | CodeIgniter 3 / PHP | Kotlin / Compose | Kotlin / Compose |
| **Role** | Authoritative writer | Real-time class ops | Engagement + payments |
| **Users** | Principals, accountants, super-admins | Class & subject teachers | Parents / guardians |

All three share **one Firebase project** — so a fee paid by a parent appears on the admin dashboard *and* the teacher's class screen within a second, with zero sync infrastructure to maintain.

---

## Slide 4 — Architecture at a Glance

# Firebase Is The Bus

```
                ┌─────────────────────────────────────┐
                │     FIREBASE (single project)       │
                │  Firestore + Auth + Storage + FCM   │
                └─────────────────────────────────────┘
                  ▲              ▲              ▲
                  │ writes       │ reads        │ reads
        ┌─────────┴─────┐ ┌──────┴───────┐ ┌────┴─────────┐
        │ ADMIN PANEL   │ │ TEACHER APP  │ │ PARENT APP   │
        │ (web)         │ │ (Android)    │ │ (Android)    │
        └───────────────┘ └──────────────┘ └──────────────┘
              │                  │                 │
              ▼                  ▼                 ▼
         Razorpay         Biometric /         Razorpay
         webhook          RFID devices        checkout
```

**Why this matters:**
- No REST gateway to operate
- No message queue to scale
- No sync service to debug
- Multi-tenant by default — every doc keyed by `schoolId`

---

## Slide 5 — Technology Stack

# Modern. Mobile-First. Cloud-Native.

| Concern | Choice |
|---|---|
| Web | **CodeIgniter 3 / PHP** on Nginx + PHP-FPM (AWS EC2) |
| Mobile | **Kotlin + Jetpack Compose** (Material 3) |
| Architecture | MVVM + Repository + Hilt DI |
| Primary store | **Firebase Firestore** (real-time, offline-first) |
| Auth | Firebase Auth + custom claims (school + role + entity IDs) |
| Payments | **Razorpay** (PCI-DSS handled by gateway) |
| Push | Firebase Cloud Messaging (FCM) |
| Media | Coil (images), Media3 / ExoPlayer (video) |
| PDFs | DomPDF (server-side) + Razorpay receipts (client-side) |
| Devices | Biometric / RFID / Face-recognition attendance integration |

**Code size today:** 59 PHP controllers • 78+ Firestore schemas • 200+ Kotlin files per mobile app

---

## Slide 6 — Module Map

# Everything A School Needs

| Domain | Modules |
|---|---|
| **Admissions & Students** | Inquiry CRM, Admission, Profile, Promotion, TC, Alumni |
| **Academic** | Subject Assignments, Timetable, Curriculum, Lesson Plans, Substitutes |
| **Assessment** | Exams, Marks Entry, Result Computation, Hall Tickets, PDFs |
| **Fees** | Structures, Demands, Collection (cash + online), Defaulters, Refunds |
| **Accounting** | Double-entry Journal, P&L, Balance Sheet, Bank Recon, Period Locks |
| **Payroll** | Salary Sheets, Statutory Deductions, Salary Slips |
| **HR** | Staff Onboarding, Multi-role, Leave, Appraisals, Recruitment (ATS), Training |
| **Attendance** | Student + Staff (Biometric / RFID / Face / Manual) |
| **Communication** | Notices, SMS, Email, Parent-Teacher Chat, FCM Push |
| **Engagement** | Events, Galleries, Stories, PTM Booking |
| **Operations** | Hostel, Transport, Library, Inventory, Certificates |
| **Safety** | Red Flags, Incidents, SOS, Lost & Found |
| **Super-Admin** | 9 Plan Tiers, Backups, Monitoring, Migration, Cross-School Reports |

---

## Slide 7 — Cross-System In Action

# A Fee Payment, End-To-End

```
 PARENT taps "Pay ₹15,000"
         │
         ▼
 Razorpay Checkout → Razorpay Servers
         │
         ▼ webhook
 ADMIN PANEL writes:
   • feeReceipts/{id}
   • feeDemands/{id}.balance
   • feeDefaulters/{id}.totalDues
         │
         ▼ Firestore real-time push
 ┌───────────────────┐  ┌────────────────────┐
 │ PARENT APP        │  │ TEACHER APP        │
 │ banner updates    │  │ class-fees count   │
 │ + FCM receipt     │  │ re-renders         │
 └───────────────────┘  └────────────────────┘
```

**Latency:** parent sees receipt in ~2s. Teacher sees updated class-aggregate in ~1s. Zero polling. Zero manual reconciliation.

**Architectural elegance:** `feeDemands` is authoritative. `feeDefaulters` is a self-healing denormalised cache that mobile clients listen to for cheap aggregate reads.

---

## Slide 8 — Data & Security

# Multi-Tenant. Audit-Logged. Compliance-Ready.

**Tenant Isolation**
- Every document, every Storage path, every query keyed by `schoolId` (`SCH_XXXXXX`)
- Firestore Security Rules enforce: `request.auth.token.schoolId == resource.data.schoolId`
- Tenant-scoped phone indexes (no cross-tenant conflicts)

**Hardening (last 30 days)**
- `isSameSchoolStrict()` — fail-closed cross-tenant guard
- Server-time equality on submissions (no backdating)
- Existing-status guards on state-machine writes
- Composed `isStaff() || isOwnStudent()` for parent reads

**Auditability**
- Every state-changing admin action logged with before/after values
- Accounting forensics chains for dispute resolution
- 21 simulator scenarios replay accounting before every deploy

**Payment**
- Razorpay holds card data (PCI-DSS); admin sees only payment + order IDs
- Webhooks signature-validated before any state mutation

---

## Slide 9 — Traction & Milestones

# What We Shipped In The Last 60 Days

| Date | Milestone |
|---|---|
| **2026-05-15** | Homework Attachment Phase 1 — Teacher upload → Parent validated open |
| **2026-05-11** | Teacher 1-tap Red Flag |
| **2026-05-10** | Accounting stabilisation — 21 simulator scenarios PASS |
| **2026-05-09** | Fees canonical architecture closed (TC-3 + Phase 3D) |
| **2026-05-07** | Teacher auth gate → Firestore single-source-of-truth |
| **2026-04-28** | Staff Active/Inactive lifecycle (4 phases) |
| **2026-04-17** | Razorpay live, Parent ERP-bento Dashboard |
| **2026-04-15** | HR canonical schema + Firestore rules deployed |
| **2026-04-08** | Attendance Firestore migration (Phase 7) |
| **2026-04-07** | Subject Assignments single source of truth |
| **2026-03-27** | Firebase Auth migration complete (legacy auth removed) |

**Operating discipline:** observe → verify → classify → decide. Financial code follows strict freeze-and-soak choreography. No surprise mutations.

---

## Slide 10 — Roadmap & Ask

# Where We're Going

**Q3 2026**
- Parent app homework submissions (Phase 2)
- Live transport GPS tracking

**Q4 2026**
- AI-assisted lesson-plan recommendations
- Inter-school analytics for school chains

**2027**
- Public API for SIS imports, payroll bureau, accounting export
- Third-party integration marketplace

---

## Slide 11 — Why Your School

# Built For The Reality Of An Indian School

**You don't need new training.**
Front office continues to operate the way it does today — receipts, admissions, marks — but the data is now structured, searchable, and shared automatically with teachers and parents.

**You don't need to migrate everything on day one.**
Bring in admissions and fees first. Attendance, marks, communication can follow as your team gets comfortable.

**You don't pay for what you don't use.**
9 subscription tiers — pre-school, primary-only, full K-12, multi-branch. Plan-gated features so a 300-student school doesn't subsidise a 5,000-student chain.

**Your data stays yours.**
Per-school backups. Per-school exports. No vendor lock-in — you can export your full school dataset at any time.

---

## Slide 12 — What You Get In Week One

# Onboarding Timeline

| Week | What Happens |
|---|---|
| **Week 1** | School onboarded, admin accounts created, branding (logo, colours) applied, classes & sections configured |
| **Week 2** | Student bulk import, fee structure setup, staff onboarded, teaching subjects assigned |
| **Week 3** | Teacher app rollout — class teachers trained (1-hour session), attendance live |
| **Week 4** | Parent app rollout — SMS invitations sent, force-password-change on first login, fee dashboard live |
| **Week 5+** | Routine operation. Office staff focus shifts from data entry to exceptions. |

**Pilot offer:**
- **Free onboarding** for the first three months
- **Dedicated support** during rollout
- **No setup charges, no per-user fees during pilot**
- **Cancel anytime** — full data export provided

---

## Slide 13 — The Outcome

# What Changes For Your School

**For the Principal**
- Real-time visibility into fee collection, attendance, and academic performance — without asking anyone
- One dashboard. No more "let me get back to you on that."

**For the Office**
- Receipt printing, defaulter calls, TC generation — minutes instead of hours
- Bank reconciliation automated; period locks prevent retroactive edits
- Audit trail on every action — accountability without finger-pointing

**For Teachers**
- Take attendance in 90 seconds from the classroom
- Push homework with attachments — parents see it the same minute
- Mark students, raise behaviour flags, message parents — all from one app

**For Parents**
- Stop calling the office to ask about fees
- See homework, marks, attendance, and notices as they happen
- Pay fees in two taps. Get receipt PDF instantly.

---

### Next Step

> **Schedule a 30-minute live demo with your principal, accountant, and one teacher.**
> See the three apps work together with your school's actual classes and fee structure (we'll preload a demo tenant).

**Contact:** *(your contact line here)*

---

*End of deck.*
