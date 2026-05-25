# SchoolSync — Project Details

A plain-language description of what the project is, what it does, and how its three parts work together.

**Date:** 2026-05-15

---

## 1. What This Project Is

SchoolSync is a complete school management system for K-12 schools. It handles every operational area of a school — admissions, fees, attendance, marks, homework, HR, payroll, accounting, transport, hostel, library, communication — and gives administrators, teachers, and parents each their own view of the same data.

The system is made of **three separate applications** that all read and write to **one shared database** (Firebase). When an action happens in one application, the other two see the change automatically.

---

## 2. The Three Applications

### 2.1 Admin Panel (Web)

- **Built with:** PHP, CodeIgniter 3 framework
- **Runs on:** A web browser (XAMPP for development, Nginx + PHP-FPM on AWS for production)
- **Used by:** Principals, accountants, office staff, school super-admins
- **Role:** This is the **main system**. All important data is created and edited here. The two mobile apps mostly read what the admin panel writes.

The admin panel contains 59 controllers covering around 50 functional modules — everything from admission CRM to payroll to bank reconciliation.

### 2.2 Teacher App (Android)

- **Built with:** Kotlin, Jetpack Compose (Android's modern UI toolkit)
- **Runs on:** Android phones
- **Used by:** Class teachers, subject teachers, heads of department
- **Role:** Mostly **read**, with some **write** capabilities:
  - **Reads:** assigned classes, student rosters, timetable, lesson plans, payslips, leave status
  - **Writes:** daily attendance, marks, homework (with attachments), red flags, messages to parents

The Teacher app has about 26 screens and 23 data repositories. It runs offline-first — data is cached locally so a teacher can take attendance even without a network, and it syncs when connectivity returns.

### 2.3 Parent App (Android)

- **Built with:** Kotlin, Jetpack Compose
- **Runs on:** Android phones
- **Used by:** Parents and guardians of students
- **Role:** Mostly **read**, with payment and engagement **writes**:
  - **Reads:** child's attendance, marks, homework, fee dues, school notices, photo galleries, transport status
  - **Writes:** online fee payments (via Razorpay), PTM bookings, messages to teachers, leave applications

The Parent app has around 25 screens and 32 data repositories. Like the Teacher app, it's offline-first.

---

## 3. The Shared Backend

All three applications connect to the **same Firebase project**. There is no separate server or sync service. Firebase itself acts as the bridge between the three.

What Firebase provides:

| Service | Purpose |
|---|---|
| **Firestore** | The main database. All school data lives here. |
| **Firebase Auth** | Handles login for all three applications. |
| **Cloud Storage** | Holds files — homework attachments, gallery photos, certificate PDFs, school logos. |
| **Cloud Messaging (FCM)** | Sends push notifications to both mobile apps. |
| **Realtime Database** | Used only for live chat presence and notification badges. Being phased out for everything else. |

Because all three applications share the same Firestore, **updates propagate automatically.** When the admin panel writes a fee receipt, the Parent app and Teacher app see the update within a second — no API call between them, no message queue, no sync job.

---

## 4. Multi-Tenancy (How Multiple Schools Are Kept Separate)

The system is multi-tenant — it can serve many schools from a single deployment. Every school gets a unique identifier in the format `SCH_XXXXXX` (six hex characters).

- Every document in Firestore carries a `schoolId` field.
- Every Cloud Storage path begins with the school's ID.
- Every query filters by school ID before returning data.
- Security rules enforce this at the database level — a user signed in for School A literally cannot read or write School B's data, even if they craft a malicious request.

There is also a separate **Super-Admin control plane** (10 controllers under `Superadmin_*`) that manages schools — onboarding new schools, assigning subscription plans, taking backups, viewing cross-school reports, running migrations.

---

## 5. Module Catalogue

This section lists every functional area, what it does, and which of the three applications participate.

For each row: **W** = writes data, **R** = reads data, **—** = not used.

### 5.1 Admissions and Student Lifecycle

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Public inquiry / admission form | W | — | — (used by prospects) |
| Admission CRM (inquiry → follow-up → conversion) | W | — | — |
| Student profile (create, edit) | W | R | R |
| Class promotion (move students to next class) | W | — | — |
| Transfer Certificate (TC) generation | W | — | — |
| Alumni records | W | — | — |

### 5.2 Academic Planner

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Subject assignment (which teacher teaches which class+subject) | W | R | — |
| Class teacher designation | W | R | R |
| Timetable creation | W | R | R |
| Curriculum (topic-by-topic plan for each subject) | W | R | — |
| Daily lesson plans | W | W (own classes) | R (child's class) |
| Substitute teacher allocation | W | R | — |

### 5.3 Attendance

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Mark student attendance | W | W (own class) | R |
| Biometric / RFID / Face-recognition attendance | W (config) | — | — |
| Staff attendance | W | R (own) | — |
| Monthly summary | W | R | R |
| Late-arrival tracking | W | W | R |

### 5.4 Homework

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Create homework with text and file attachments | — | W | — |
| View assigned homework | — | R | R |
| Open / download attachment | — | R | R |
| Mark student submission status | — | W | — |

### 5.5 Marks and Examinations

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Exam schedule | W | R | R |
| Marks entry | W | W (own subject) | — |
| Result computation (grades, ranks, pass/fail) | W | — | — |
| Hall ticket eligibility (blocked if fees pending) | W | — | — |
| Result sheet (PDF) | W | — | R |

### 5.6 Fees and Online Payment

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Fee structure setup (per class, per category) | W | — | — |
| Monthly fee demand generation | W | — | — |
| Cash / cheque receipt | W | — | — |
| Online payment via Razorpay | W (record) | R (class summary) | W |
| Defaulter list | W | R | R (own child) |
| Receipt PDF | W | — | R (download) |
| Refund processing | W | — | — |

### 5.7 Accounting

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Double-entry journal | W | — | — |
| Chart of accounts | W | — | — |
| Trial balance, P&L, balance sheet, cash flow | W | — | — |
| Bank reconciliation | W | — | — |
| Period locks (close month/year) | W | — | — |
| Audit trail of every transaction | W | — | — |

### 5.8 Human Resources

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Staff onboarding and profile | W | R (own) | — |
| Multi-role staff (a librarian can also be a class teacher) | W | R | — |
| Leave applications | W | W (own) | — |
| Performance appraisals | W | R (own) | — |
| Recruitment pipeline (applicant tracking) | W | R (own applications) | — |
| Training records | W | R (own) | — |

### 5.9 Payroll

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Salary structure setup | W | — | — |
| Monthly salary accrual | W | — | — |
| Statutory deductions (PF, ESI, TDS) | W | — | — |
| Salary slip generation | W | R (own) | — |
| Payroll integration with accounting | W | — | — |

### 5.10 Communication

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Notice / circular publishing | W | R | R |
| Bulk SMS | W | — | — |
| Bulk Email | W | — | — |
| Parent-teacher messaging | W (monitor) | W | W |
| Push notifications | W (trigger) | R | R |
| Real-time chat with online presence | — | W | W |

### 5.11 Campus Life

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Hostel (rooms, allocation, complaints) | W | R | R |
| Meal menus | W | R | R |
| Library (catalogue, issues, fines) | W | R | R |
| Behaviour red flags | W | W (one-tap) | R |
| Incident reports | W | W | R |
| SOS alerts | W | R | R (trigger) |
| Lost and Found | W | R | R |
| Events and gallery | W | W | R |
| Stories (short video content) | — | W | R |

### 5.12 Transport

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| Routes and stops | W | — | R |
| Vehicle fleet | W | — | — |
| Student-route assignment | W | — | R |
| Driver assignment | W | — | — |
| Geofences (zones for safety alerts) | W | — | R |
| Trip logs | W | — | R |

### 5.13 Parent-Teacher Meetings

| Capability | Admin | Teacher | Parent |
|---|:---:|:---:|:---:|
| PTM scheduling | W | R | R |
| Slot configuration | W | W | — |
| Slot booking | — | — | W |
| Attendance recording | W | W | R |

### 5.14 Super-Admin

For school chains and the SaaS operator:

- School onboarding, profile management, plan assignment
- 9 subscription tiers (pre-school, primary, full K-12, multi-branch, etc.)
- Cross-school analytics and reports
- System monitoring (login logs, error logs, Firebase usage)
- Per-school backup and restore
- Migration tools

---

## 6. Cross-System Flows (How The Three Apps Work Together)

This is the most important section. It shows what happens when an action in one application flows through to the others.

### 6.1 Fee Payment — Parent → Admin → Teacher

A parent pays fees online from their phone. Here's what happens:

```
Step 1.  Parent opens Fees screen on Parent App.
         Sees pending demand: "Tuition Fee April — ₹15,000 due"
         Taps "Pay Now."

Step 2.  Parent App creates a Razorpay order and opens the
         Razorpay checkout screen. Parent enters card / UPI details.

Step 3.  Razorpay processes the payment and sends a confirmation
         webhook to the Admin Panel.

Step 4.  Admin Panel (Payment_intent_listener.php) verifies the
         webhook signature, then writes three records to Firestore:
            - A new fee receipt
            - Reduces the balance on the matching fee demand
            - Updates the student's defaulter status

Step 5.  Firestore automatically pushes the changes to:
            - Parent App  — banner switches to "Paid," receipt PDF available
            - Teacher App — class-level fee count updates
            - Admin dashboard — collection number increases

Step 6.  Push notifications go out via Firebase Cloud Messaging
         (FCM) to the parent (receipt) and optionally to the class
         teacher (class summary updated).
```

End-to-end time: about 2 seconds. The parent never refreshes anything; the screen updates automatically.

### 6.2 Homework — Teacher → Parent

A teacher assigns homework with a PDF attachment.

```
Step 1.  Teacher opens Homework screen, picks class and subject,
         types description, attaches a PDF.

Step 2.  Teacher App uploads the PDF to Firebase Cloud Storage.
         Storage returns a download URL.

Step 3.  Teacher App writes a homework document to Firestore with:
            - Class, section, subject, teacher ID
            - Description, due date
            - Attachment URLs (both legacy field and new structured field
              for backward compatibility with older parent app installs)

Step 4.  Firestore pushes the new document to every parent in that
         class and section.

Step 5.  Parent App's Homework screen updates automatically.
         Parent taps the attachment — URL is validated (HTTPS only,
         must be a firebasestorage.googleapis.com URL) — opens
         in the system PDF viewer.
```

### 6.3 Attendance — Teacher → Parent

A teacher takes daily attendance.

```
Step 1.  Teacher opens Attendance screen for their class.
         Student roster loads from Firestore (cached if recently viewed).

Step 2.  Teacher taps "Present" / "Absent" / "Tardy" for each student.

Step 3.  Teacher App writes one attendance document per student
         (status, late minutes if tardy, who marked it, when).

Step 4.  Teacher App also updates a monthly summary document for
         each student (present days, absent days, tardy days, total).

Step 5.  Parent App's Attendance screen shows today's status
         immediately + the updated monthly calendar.
```

### 6.4 Subject Assignment — Admin → Teacher and Parent

An administrator assigns a teacher to a class+subject.

```
Step 1.  Admin opens Academic Planner.
         Picks Class 8, Section A, Subject = Maths.
         Picks teacher from a filtered dropdown
         (only teachers with "Maths" in their teaching subjects).
         Optionally ticks "Class Teacher."

Step 2.  Admin Panel validates:
            - Teacher actually can teach Maths (capability check)
            - Only one Class Teacher allowed per (class, section)
            - Deletes any existing assignment for this slot
         Then writes the subjectAssignments document to Firestore.

Step 3.  Teacher App detects the new assignment via a Firestore
         listener. The teacher sees "Class 8 – A – Maths" on their
         Dashboard the next time they open the app (or instantly
         if it was already open).

Step 4.  Parent App (for any student in Class 8 – A) shows the
         new teacher in the "My Teachers" screen.
```

### 6.5 Red Flag — Teacher → Admin and Parent

A teacher raises a behaviour concern about a student.

```
Step 1.  Teacher long-presses the student card on the Students screen.

Step 2.  Quick Flag dialog opens. Teacher picks:
            - Category: Behaviour / Academic / Health
            - Severity: Low / Medium / High
            - Optional note

Step 3.  Teacher App writes a redFlag document to Firestore.

Step 4.  Firebase Cloud Messaging sends:
            - A push notification to the parent
            - A push notification to the admin (if severity = High)

Step 5.  Parent App's Red Flag screen shows the alert.
         Admin Panel's Red Flag dashboard surfaces it for
         counsellor follow-up.
```

### 6.6 Payroll — Admin → Teacher

The school processes monthly salaries.

```
Step 1.  Admin opens Payroll, picks the month.

Step 2.  Admin Panel computes salary for each staff member
         (base + allowances – deductions – PF/ESI/TDS).
         Writes a salary slip document per staff member.
         Also creates the corresponding accounting journal entry.

Step 3.  Teacher App's Payslip screen shows the new slip.
         Teacher can download as PDF.
```

---

## 7. Important Design Decisions

These are the non-obvious choices in how the system works. Understanding them helps when reading the code or planning changes.

### 7.1 The Admin Panel Is the Source of Truth

All authoritative writes happen through the admin panel. The mobile apps write a few specific things (attendance, marks, homework, red flags, online fee payments, messages) but the admin panel is the single point of correctness. If a mobile-app value and an admin-panel value disagree, the admin-panel value wins.

### 7.2 Firestore-First, Realtime Database Being Removed

The system originally used Firebase Realtime Database (RTDB). It is being migrated to Firestore in phases. Today:
- New code is Firestore-only.
- Old code is being moved across in a planned 9-phase migration.
- RTDB still hosts chat presence and notification badges only.

### 7.3 Authoritative Source + Operational Cache

Several modules use a pattern where one collection is authoritative and another is a cache for fast reads:

- **Fees:** `feeDemands` is authoritative. `feeDefaulters` is a cache the parent dashboard and teacher class-fees screen read for fast counts. The cache is self-healing — every write to demands triggers a cache refresh.
- **Attendance:** Daily `attendance` records are authoritative. `attendanceSummaries` is the monthly aggregate parents see.
- **Homework:** `homework` documents are authoritative. Counts and rates are computed live (but a denormalisation cache is being evaluated).

### 7.4 Dual-Write for Schema Migrations

When a field is renamed (for example `late` → `tardy`, or snake_case → camelCase), the writer emits both shapes for at least one release cycle. This way older mobile-app installs keep working until everyone has upgraded. The old field is removed only after the upgrade is universal.

### 7.5 Class and Section Have Three String Representations

The same logical class appears differently depending on where it's stored:

| Where | How it looks |
|---|---|
| Firebase document keys | `"Class 8th"` (with "Class " prefix) |
| Student profile fields | `Class = "8th"`, `Section = "A"` (no prefix, separate fields) |
| Fee path keys (legacy) | `"8th 'A'"` (combined, single-quoted section) |

This is a historical artefact — different modules were written at different times. A helper function (`normalizeClassSection`) handles the conversions, and any new writer must use it.

### 7.6 Dates Stored as `dd-mm-yyyy` Strings

Date fields in Firestore use the `dd-mm-yyyy` string format (e.g. `07-04-2026`), not ISO-8601. Android code that uses `LocalDate.parse(...)` directly will silently fail because that function expects ISO format.

### 7.7 Financial Code Follows a Strict Change Process

For fees, accounting, payroll, and transfer-certificate logic, changes follow a four-step discipline:
1. **Observe** — telemetry only, no edits
2. **Forensic** — analyse discrepancies, build a fix plan
3. **Package** — assemble all changes with rollback documented
4. **Apply** — one controlled deploy with immediate verification

This is heavier than usual but pays off — financial bugs are expensive to recover from.

---

## 8. Technology Stack Summary

| Concern | Choice |
|---|---|
| Admin panel language | PHP 7.4+ |
| Admin panel framework | CodeIgniter 3 |
| Admin panel server | XAMPP (dev) / Nginx + PHP-FPM on AWS EC2 (production) |
| Mobile language | Kotlin |
| Mobile UI | Jetpack Compose with Material 3 |
| Mobile architecture | MVVM + Repository + Dagger Hilt |
| Mobile build | Gradle KTS, Compose BoM 2024.02.00 |
| Mobile SDK | min Android 7 (API 24), target Android 14 |
| Database | Firebase Firestore |
| Auth | Firebase Auth with custom claims |
| Storage | Firebase Cloud Storage |
| Push | Firebase Cloud Messaging |
| Payment | Razorpay (checkout SDK + webhooks) |
| PDFs | DomPDF (server-side) |
| Images | Coil (Android) |
| Video | Media3 / ExoPlayer (Android) |

---

## 9. File Structure (Where Things Live)

### Admin Panel (`C:\xampp\htdocs\Grader\school`)

```
application/
├── controllers/       59 controllers (Admin_login, Fees, Academic, Sis,
│                      Staff, Accounting, Payroll, Homework, Attendance,
│                      Communication, Superadmin_*, etc.)
├── libraries/         55+ helper libraries (Fee_*, Accounting_*, Firestore_*,
│                      Subject_assignment_service, Curriculum_service, ...)
├── views/             ~50 view directories (one per module)
├── models/            3 base model classes
├── config/            routes.php, database config, services
├── cache/             per-school cache files
└── core/              MY_Controller (handles auth and school context)

firebase-rules/        Firestore security rules + indexes + storage rules
functions/             Cloud Functions (payment listener, etc.)
scripts/               One-shot Node scripts for migration / verification
```

### Teacher App (`D:\Projects\SchoolSyncTeacher`)

```
app/src/main/java/com/schoolsync/teacher/
├── ui/screens/        ~26 screens (Dashboard, Attendance, Marks, Homework,
│                      Students, RedFlag, Messages, Notices, Profile,
│                      Payslips, Appraisals, Leave, Library, Gallery, etc.)
├── viewmodel/         ~26 ViewModels (one per screen)
├── data/
│   ├── repository/    23 repositories
│   └── model/firestore/  78+ document classes
├── di/                Hilt dependency injection modules
└── service/           FCM service, Firestore service
```

### Parent App (`D:\Projects\SchoolSyncParent`)

```
app/src/main/java/com/schoolsync/parent/
├── ui/screens/        ~25 screens
├── viewmodel/         36 ViewModels
├── data/
│   ├── repository/    32 repositories
│   └── model/firestore/  72+ document classes
├── di/
└── service/
```

---

## 10. Numbers At a Glance

| Item | Count |
|---|---|
| Admin panel controllers | 59 |
| Admin panel view directories | ~50 |
| Admin panel helper libraries | 55+ |
| Teacher app Kotlin source files | 209 |
| Teacher app screens | ~26 |
| Teacher app data repositories | 23 |
| Teacher app Firestore document types | 78+ |
| Parent app screens | ~25 |
| Parent app ViewModels | 36 |
| Parent app data repositories | 32 |
| Parent app Firestore document types | 72+ |
| Subscription plan tiers | 9 |
| Default staff roles | 9 |

---

## 11. What's Recently Been Worked On

Last 60 days, in plain language:

- **Homework with file attachments** — full Teacher upload to Parent open flow shipped 2026-05-15
- **One-tap behaviour flagging** for teachers — shipped 2026-05-11
- **Accounting improvements** — concession handling and payroll stages stabilised; 21 automated test scenarios pass
- **Fees architecture finalised** — `feeDemands` is now the single source of truth across all three apps (closed 2026-05-09)
- **Subject assignments** moved to a single Firestore source of truth (April 2026)
- **Online fee payment via Razorpay** went live for both admin and parent (April 2026)
- **Communication module** moved most of its functionality from the legacy realtime database to Firestore (April 2026)
- **Staff multi-role support** — a teacher can also be a librarian or warden (March 2026)
- **Firebase architecture refactor** — every school now has a permanent `SCH_XXXXXX` ID (March 2026)

---

*End of document.*
