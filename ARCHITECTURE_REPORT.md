# School ERP SaaS — Enterprise Architecture Report

**Project:** Grader School ERP
**Date:** 2026-03-13
**Version:** 1.0
**Framework:** CodeIgniter 3.1.x + Firebase Realtime Database
**Platform:** PHP 7.4+ / XAMPP / Windows

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Module Inventory](#2-module-inventory)
3. [Page-by-Page Explanation](#3-page-by-page-explanation)
4. [Database Structure](#4-database-structure)
5. [Data Flow Analysis](#5-data-flow-analysis)
6. [Mobile App Integration](#6-mobile-app-integration)
7. [Security Analysis](#7-security-analysis)
8. [Performance Analysis](#8-performance-analysis)
9. [Codebase Structure](#9-codebase-structure)
10. [Architecture Scorecard](#10-architecture-scorecard)
11. [Future Scalability](#11-future-scalability)
12. [Final Summary](#12-final-summary)

---

## 1. System Overview

### 1.1 Architecture Pattern

The application follows a **Multi-Tenant SaaS architecture** built on CodeIgniter 3 (MVC framework) with Firebase Realtime Database as the sole backend data store. Each school is a tenant identified by a unique `SCH_XXXXXX` key that partitions all data.

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENTS                               │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌─────────────┐ │
│  │ Browser  │  │ Mobile   │  │ Biometric│  │ Super Admin │ │
│  │ (Admin)  │  │ App      │  │ Devices  │  │ Panel       │ │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └──────┬──────┘ │
│       │              │              │               │        │
└───────┼──────────────┼──────────────┼───────────────┼────────┘
        │              │              │               │
┌───────┼──────────────┼──────────────┼───────────────┼────────┐
│       ▼              ▼              ▼               ▼        │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │              CodeIgniter 3 Application                  │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │ │
│  │  │ MY_Controller│  │MY_Superadmin │  │ CI_Controller│  │ │
│  │  │  (School)    │  │  Controller  │  │  (Login)     │  │ │
│  │  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  │ │
│  │         │                 │                  │          │ │
│  │  ┌──────┴─────────────────┴──────────────────┴───────┐  │ │
│  │  │           48 Controllers / 300+ Routes            │  │ │
│  │  └──────────────────────┬────────────────────────────┘  │ │
│  │                         │                               │ │
│  │  ┌──────────────────────┼────────────────────────────┐  │ │
│  │  │    Libraries         │        Models              │  │ │
│  │  │  • Firebase.php      │  • Common_model.php        │  │ │
│  │  │  • Debug_tracker     │  • Common_sql_model.php    │  │ │
│  │  │  • Communication     │                            │  │ │
│  │  │  • Ops_accounting    │                            │  │ │
│  │  └──────────┬───────────┴────────────────────────────┘  │ │
│  │             │                                           │ │
│  └─────────────┼───────────────────────────────────────────┘ │
│                │                                             │
│       ┌────────┴────────┐                                    │
│       ▼                 ▼                                    │
│  ┌─────────┐    ┌──────────────┐                             │
│  │Firebase │    │   Firebase   │                             │
│  │Realtime │    │   Storage    │                             │
│  │Database │    │   (Files)    │                             │
│  └─────────┘    └──────────────┘                             │
│                                                              │
│              APPLICATION SERVER (XAMPP)                       │
└──────────────────────────────────────────────────────────────┘
```

### 1.2 Technology Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| **Frontend** | AdminLTE + jQuery + Bootstrap 3 | AdminLTE 2.x |
| **Charts** | Chart.js | 4.4 |
| **Tables** | DataTables | 1.13+ |
| **Backend** | CodeIgniter 3 (PHP) | 3.1.13 |
| **Database** | Firebase Realtime Database | Admin SDK (Kreait) |
| **File Storage** | Firebase Storage | Admin SDK |
| **Authentication** | Custom (session-based) | PHP Sessions |
| **Server** | Apache (XAMPP) | 2.4.x |
| **Composer** | PHP Dependency Manager | autoload enabled |

### 1.3 Multi-Tenant Data Isolation

Every school's data is isolated under its `SCH_XXXXXX` key:

```
Schools/SCH_000001/...     ← School 1's data
Schools/SCH_000002/...     ← School 2's data
Users/Parents/SCH_000001/  ← School 1's students
Users/Teachers/SCH_000001/ ← School 1's teachers
```

The `MY_Controller` base class binds `$this->school_name` (= `SCH_XXXXXX`) from the session, and ALL Firebase paths are scoped to this key. Cross-tenant data access is impossible at the controller level.

### 1.4 Session Architecture

Academic data is further isolated by session year (e.g., `2024-2025`):

```
Schools/{school_id}/2024-2025/Class 9th/Section A/Students/List/{id}: 1
Schools/{school_id}/2025-2026/Class 9th/Section A/Students/List/{id}: 1
```

This allows complete historical separation — switching sessions shows only that year's enrollment, attendance, exams, and results.

---

## 2. Module Inventory

### 2.1 Core Modules (18 modules, 48 controllers)

| # | Module | Controller(s) | Lines | Routes | Status |
|---|--------|--------------|-------|--------|--------|
| 1 | **Authentication** | Admin_login.php, Superadmin_login.php | 566 + 289 | 5 | Production |
| 2 | **Dashboard** | Admin.php | 491 | 8 | Production |
| 3 | **Student Management** | Student.php, Sis.php | ~700 + ~1200 | 30+ | Production |
| 4 | **Class Management** | Classes.php | ~600 | 10+ | Production |
| 5 | **Subject Management** | Subjects.php | ~400 | 5+ | Production |
| 6 | **Fees Collection** | Fees.php | ~900 | 15+ | Production |
| 7 | **Fee Management** | Fee_management.php | ~1400 | 35 | Production |
| 8 | **Exam Management** | Exam.php | ~800 | 10+ | Production |
| 9 | **Result Management** | Result.php | ~1200 | 21 | Production |
| 10 | **Examination Analytics** | Examination.php | 1149 | 10 | Production |
| 11 | **Attendance** | Attendance.php | ~1500 | 35 | Production |
| 12 | **School Configuration** | School_config.php | ~800 | 22 | Production |
| 13 | **Academic Management** | Academic.php | ~900 | 19 | Production |
| 14 | **Accounting** | Accounting.php | ~1200 | 40 | Production |
| 15 | **HR & Payroll** | Hr.php | ~2650 | 51 | Production |
| 16 | **Communication** | Communication.php | 1341 | 38 | Production |
| 17 | **Admission CRM** | Admission_crm.php | ~1100 | 30 | Production |
| 18 | **Events** | Events.php | ~800 | 18 | Production |

### 2.2 Operations Sub-Modules (5 modules)

| # | Module | Controller | Routes | Features |
|---|--------|-----------|--------|----------|
| 19 | **Library** | Library.php | 19 | Catalog, Issues, Fines, Reports |
| 20 | **Transport** | Transport.php | 17 | Vehicles, Routes, Stops, Assignments |
| 21 | **Hostel** | Hostel.php | 17 | Buildings, Rooms, Allocations |
| 22 | **Inventory** | Inventory.php | 18 | Items, Vendors, Purchases, Stock |
| 23 | **Assets** | Assets.php | 18 | Registry, Maintenance, Depreciation |

### 2.3 Super Admin Panel (8 controllers)

| # | Module | Controller | Routes | Purpose |
|---|--------|-----------|--------|---------|
| 24 | **SA Dashboard** | Superadmin.php | 3 | Dashboard, stats, charts |
| 25 | **SA Schools** | Superadmin_schools.php | 10 | Onboarding, management |
| 26 | **SA Plans** | Superadmin_plans.php | 15 | Plans, subscriptions, payments |
| 27 | **SA Reports** | Superadmin_reports.php | 4 | Cross-school analytics |
| 28 | **SA Monitor** | Superadmin_monitor.php | 11 | Logins, errors, health |
| 29 | **SA Backups** | Superadmin_backups.php | 11 | Backup/restore, scheduling |
| 30 | **SA Debug** | Superadmin_debug.php | 6 | Debug panel, schema checks |
| 31 | **SA Migration** | Superadmin_migration.php | 3 | Legacy data migration |

### 2.4 Infrastructure Modules

| # | Module | Controller | Purpose |
|---|--------|-----------|---------|
| 32 | **Health Check** | Health_check.php | 16 validation checks |
| 33 | **Operations Hub** | Operations.php | Sub-module dashboard |
| 34 | **Backup Cron** | Backup_cron.php | Automated backups |

**Totals:** 34 modules | 48 controllers | 300+ routes | ~25,000+ lines of controller code

---

## 3. Page-by-Page Explanation

### 3.1 Authentication & Login

#### admin_login.php (View: 1016 lines)
Self-contained login page with school code, admin ID, and password fields. Features brute-force protection (IP rate limiting: 20 fails/15 min, account lockout: 5 fails/30 min), subscription status verification, session fixation prevention, and plain-text password migration on first login.

#### Superadmin Login
Separate login flow (`superadmin/login`) with session-based CSRF (distinct from school-admin's cookie CSRF to prevent collision on shared domain).

### 3.2 Dashboard

#### home.php (1125 lines) — Main Dashboard
Six KPI stat cards (total students, staff, fees collected, events, attendance rate, pending tasks). Two-column layout with classes overview grid and notification feed. Chart.js pie charts for fee distribution and attendance breakdown. Quick-action cards for common tasks. Calendar widget for upcoming events.

### 3.3 Student Management

#### all_student.php (1140 lines)
Class/section filter bar with AJAX reload. DataTables grid showing student list with photo thumbnails, name, parent name, contact, and action buttons (view/edit/delete). Bulk actions: export to CSV, print ID cards. Session-aware: only shows students enrolled in current academic session.

#### studentAdmission.php (~900 lines)
Multi-step admission form: personal details, parent information, address, previous school, documents upload. Firebase Storage integration for photo and document uploads. Auto-generates User ID. Validates required fields before submission.

#### student_profile.php (~800 lines)
Comprehensive student profile view: personal details card, parent/guardian info, academic history (class/section/roll), fee payment history, attendance summary chart, exam results summary. Action buttons for edit, transfer, TC, withdraw.

#### edit_student.php (~600 lines)
Editable version of student profile with all fields pre-populated. Photo change with preview. Document re-upload capability.

#### student_id_card.php (~500 lines)
Print-ready ID card generator. School logo, student photo, barcode/QR code, key details (name, class, DOB, blood group, address). Batch printing for entire class/section.

#### import_students.php (~400 lines)
CSV/Excel bulk import with column mapping. Preview table before import. Validation for required fields. Progress bar during import. Error report for failed rows.

#### student_fees.php (~700 lines)
Individual student fee ledger: month-wise payment status, outstanding balance, payment history table with receipt references. Summary cards for total paid, total fine, total discount.

### 3.4 Class & Section Management

#### section_students.php (~600 lines)
Section-specific student roster with DataTables. Back-navigation button for timetable view toggle. Links to class profile views. Student count indicators.

#### manage_subjects.php / manage_subjects1.php (~400 lines each)
Subject CRUD interface: add/edit subjects with code, name, category, stream assignment. manage_subjects1.php is an alternate theme version.

### 3.5 Fees Module

#### fees_counter.php (91.8KB — Largest View)
Full POS-style fee collection interface. Student lookup by ID/name with AJAX search. Month selection grid (April–March) with paid/unpaid status indicators. Fee breakdown panel showing tuition, transport, hostel, and other components. Payment mode selector (Cash/Cheque/Online/DD). Receipt generation with print functionality. Uses `--fc-*` CSS variable tokens mapped to global theme.

#### fees_structure.php (~600 lines)
Class-wise fee structure editor. Table format with fee heads as columns, classes as rows. Inline editing with save-per-row. Total auto-calculation. Import/export capability.

#### fees_chart.php (~500 lines)
Visual fee analytics dashboard. Chart.js bar/pie charts for collection vs. pending by class, monthly trends, payment mode distribution. Filter by date range, class, and section.

#### fees_records.php (~500 lines)
Payment history search and filter interface. Date range, class, payment mode, and student name filters. Detailed table with receipt number, student, amount, mode, date. Row selection for batch receipt printing.

#### class_fees.php (~400 lines)
Class-level fee configuration. Set monthly fees per class with breakdown by fee head. Bulk update capability. Visual comparison across classes.

#### fee_management/categories.php (~600 lines)
Advanced fee management: fee categories, discount rules, scholarship management, refund processing, payment reminders, and online payment gateway settings. Tab-based SPA with 6 panels.

### 3.6 Exam & Results

#### Exam Management (exam/ views)
Create/edit exams with class-subject-schedule matrix. Multi-class exam creation. Status tracking (Draft/Published/Completed). Delete cascade removes all associated templates, marks, and computed results.

#### Result Module (result/ — 8 views)
- **template_designer.php**: Design grade/mark templates per exam with customizable grade scales (5 types: Percentage, A-F, O-E, 10-Point, Pass/Fail)
- **marks_entry.php**: Teacher marks entry grid by class/section/exam with real-time grade computation
- **marks_sheet.php**: View/edit marks with "View & Compute Results" shortcut
- **class_result.php**: Class-wide result table with ranks (competition ranking: 1,1,3), per-student print links
- **student_result.php**: Individual student result across exams
- **report_card.php**: Print-ready report card styled as official school marksheet (Mount Litera Zee style) with school header, dual logos, student photo, grade legend, and signature boxes
- **cumulative.php**: Multi-exam cumulative result computation and display

#### Examination Analytics (examination/ — 4 views)
- **index.php**: Exam dashboard hub with links to all sub-pages
- **merit_list.php**: Class/subject toppers with export
- **analytics.php**: Grade distribution, subject analysis, section comparison, exam-over-exam comparison charts
- **tabulation.php**: Print-ready tabulation sheets

### 3.7 Attendance (attendance/ — 6 views)

- **index.php**: Attendance dashboard with summary stats
- **student.php**: Mark student attendance with P/A/L/H/T/V status grid
- **staff.php**: Staff attendance tracking
- **settings.php**: Attendance rules, late thresholds, biometric device configuration
- **analytics.php**: Attendance trends, class-wise comparison, individual student patterns
- **punch_log.php**: Biometric/RFID device punch log viewer

### 3.8 School Configuration (school_config/ — 1 SPA view)

**index.php** — 7-tab SPA: Profile, Sessions, Board, Classes, Sections, Subjects, Streams. All CRUD via AJAX. Session management (create/switch/set active). Class and section creation with enrollment guards on delete.

### 3.9 Academic Management (academic/ views)

Curriculum planning, academic calendar, timetable builder, substitute teacher management. Calendar with event overlay. Drag-and-drop timetable builder.

### 3.10 Accounting (accounting/ views)

Chart of accounts, general ledger, journal entries, income/expense tracking, trial balance, profit & loss, balance sheet. Double-entry accounting with auto-balancing validation.

### 3.11 HR & Payroll (hr/ — 1 SPA view, 1880 lines)

**index.php** — 6-tab SPA: Departments, Recruitment, Leave Management, Payroll Processing, Appraisals, Employee Directory. Payroll integrates with accounting module (creates journal entries). Leave management with Leave Without Pay (LWP) deductions. Audit logs at `System/Logs/Payroll/`.

### 3.12 Communication (communication/ — 8 views)

Messaging system, notice board (dual-write to legacy path), circular distribution, template management, event-triggered automation (14 trigger types), message queue, and delivery logs.

### 3.13 Admission CRM (admission_crm/ views)

Inquiry management, application pipeline with stage tracking, waitlist management, admission settings. Kanban-style pipeline view.

### 3.14 Operations Sub-Modules

Each has its own view directory with tab-based SPA:
- **library/**: Book catalog, issue/return, fine management, reports
- **transport/**: Vehicle fleet, route planning, stop management, student assignments
- **hostel/**: Building/room management, bed allocation, hostel attendance
- **inventory/**: Item tracking, vendor management, purchase orders, stock levels
- **assets/**: Asset registry, assignment tracking, maintenance schedules, depreciation

### 3.15 Super Admin Panel (superadmin/ views)

Separate themed views with `sa_header.php` / `sa_footer.php`:
- **dashboard.php**: Platform-wide stats, school count, revenue, active users
- **schools.php**: School onboarding wizard, management grid
- **plans.php**: Subscription plan CRUD, payment tracking
- **reports.php**: Cross-school analytics (students, revenue, activity, plan distribution)
- **monitor.php**: Login monitoring, error tracking, health checks
- **backups.php**: Backup management, restore, scheduling
- **debug/index.php**: 6-tab debug panel (Requests, Firebase Ops, Schema, Security, Performance, Live Schema Check)
- **migration.php**: Legacy data migration tools

### 3.16 Include Files

- **include/header.php**: Main layout wrapper — sidebar navigation (treeview menus), top navbar, theme toggle, CSRF meta tag, global CSS variables, CDN references
- **include/footer.php**: jQuery, Bootstrap JS, AdminLTE JS, page-specific scripts
- **sa_header.php** / **sa_footer.php**: Super admin layout (separate theme, session-based CSRF)

**Total View Count: 164 PHP files** across 20+ directories

---

## 4. Database Structure

### 4.1 Firebase Realtime Database Schema

The database uses a denormalized NoSQL structure optimized for real-time reads. All paths use `/` as separator.

```
Firebase Root
├── System/                          ← Platform-wide (SA only)
│   ├── Schools/{school_id}/
│   │   ├── profile                  {name, address, phone, email, logo, status}
│   │   ├── subscription             {plan_id, status, renewal_date, start_date, end_date}
│   │   └── stats_cache              {student_count, teacher_count, class_count}
│   ├── Plans/{plan_id}/             {name, price, billing_cycle, modules[], max_students}
│   ├── Payments/{payment_id}/       {school_id, amount, status, payment_date, method}
│   ├── Backups/{school_uid}/{id}/   {backup_id, filename, size_bytes, created_at}
│   ├── BackupSchedule/              {enabled, frequency, retention_days}
│   ├── Migration/                   {status, timestamp, progress}
│   ├── Logs/
│   │   ├── Activity/{date}/{id}     SA audit trail
│   │   ├── Errors/{date}/{id}       Error logs
│   │   ├── Logins/{date}/{id}       Login tracking
│   │   ├── SchoolLogins/{date}/{id} Per-school login logs
│   │   └── Payroll/{id}             Payroll audit
│   ├── Stats/Summary/               {total_schools, total_students, active_schools}
│   └── API_Keys/{hash}             {device_id, school_id, status}
│
├── Schools/{school_id}/             ← Per-School Data
│   ├── Config/
│   │   ├── Profile/                 {name, address, phone, email, principal, logo_url}
│   │   ├── Board/                   {name, code, exam_bodies[]}
│   │   ├── Classes/                 [{name, numeric_order, stream}]
│   │   ├── Streams/                 {name, subjects[]}
│   │   ├── ActiveSession/           "2024-2025"
│   │   └── Devices/{deviceId}/      {device_name, type, api_key, status}
│   ├── Sessions/                    [{year, is_active, created_at}]
│   ├── Subject_list/{idx}/{code}/   {name, category, stream}
│   ├── Logo/                        "url_string"
│   │
│   ├── {session_year}/              ← Academic Year Container
│   │   ├── Class {n}/
│   │   │   └── Section {X}/
│   │   │       ├── Students/List/{student_id}: 1
│   │   │       ├── Subjects/{code}: {teacher_id, stream}
│   │   │       ├── Timetable/{period_id}: {subject, teacher, day, time}
│   │   │       ├── max_strength: number
│   │   │       └── ClassTeacher: teacher_id
│   │   │
│   │   ├── Teachers/{teacher_id}: {Name}
│   │   ├── Staff_Attendance/{date}: "PPAP...L"
│   │   ├── Staff_Attendance/Late/{date}/{teacher_id}: {time}
│   │   │
│   │   ├── Results/
│   │   │   ├── Templates/{exam_id}/     {name, subjects[], grade_scales[]}
│   │   │   ├── Marks/{exam_id}/{cls}/{sec}/{student}: {subject: marks}
│   │   │   ├── Computed/{exam_id}/{cls}/{sec}/{student}: {grade, %, rank}
│   │   │   ├── CumulativeConfig/Exams/{exam_id}: enabled
│   │   │   └── Cumulative/{id}/{student}: {cumulative_grade, %}
│   │   │
│   │   ├── Exams/{exam_id}/
│   │   │   ├── name, exam_type, Status
│   │   │   └── Schedule/{class}/{section}: {subject: {date, time, duration}}
│   │   │
│   │   ├── Accounts/
│   │   │   ├── ChartOfAccounts/{code}: {name, type, group, active}
│   │   │   ├── Vouchers/{date}/{voucher_id}: {type, amount, debit, credit}
│   │   │   ├── Ledger/{entry_id}: {account, debit, credit, date, narration}
│   │   │   ├── Ledger_index/by_date/{date}/{entry_id}: true
│   │   │   ├── Ledger_index/by_account/{code}/{entry_id}: true
│   │   │   ├── Closing_balances/{code}: {debit, credit, balance}
│   │   │   └── Voucher_Count: number
│   │   │
│   │   ├── Attendance/{date}: "PPAP...L"
│   │   ├── Attendance/Late/{date}/{student_id}: {time}
│   │   │
│   │   ├── Fees/
│   │   │   ├── Classes Fees/{class_key}: {head: amount}
│   │   │   ├── Session Fee: number
│   │   │   └── Exempted/{student_id}: {months}
│   │   │
│   │   ├── HR/
│   │   │   ├── Departments/{dept_id}: {name, head_id}
│   │   │   ├── Payroll/Runs/{run_id}: {period, employees, totals}
│   │   │   └── Leaves/Requests/{req_id}: {employee, type, dates, status}
│   │   │
│   │   ├── Events/List/{event_id}: {name, date, type, description}
│   │   │
│   │   ├── Operations/
│   │   │   ├── Library/{Books|Issues|Fines|Categories}
│   │   │   ├── Transport/{Vehicles|Routes|Stops|Assignments|Fees}
│   │   │   ├── Hostel/{Buildings|Rooms|Allocations}
│   │   │   ├── Inventory/{Items|Categories|Vendors|Purchases|Issues}
│   │   │   └── Assets/{Assets|Categories|Assignments|Maintenance}
│   │   │
│   │   ├── CRM/Admissions/
│   │   │   ├── Settings: {pipeline_stages, form_fields}
│   │   │   ├── Inquiries/{id}: {name, phone, status}
│   │   │   ├── Applications/{id}: {form_data, class, status}
│   │   │   └── Waitlist/{id}: {application_id, position}
│   │   │
│   │   └── Academic/
│   │       ├── Curriculum/{id}: {subject, topics[]}
│   │       ├── Calendar/{id}: {title, date, type}
│   │       └── Timetable/Settings: {periods, breaks}
│   │
│   ├── Communication/
│   │   ├── Messages/Conversations/{id}: {participants, subject}
│   │   ├── Messages/Chat/{conv_id}/{msg_id}: {sender, content, timestamp}
│   │   ├── Messages/Inbox/{role}/{admin_id}/{conv_id}: {unread_count}
│   │   ├── Notices/{id}: {title, content, date, scope}
│   │   ├── Circulars/{id}: {title, content, issued_date}
│   │   ├── Templates/{id}: {name, subject, body}
│   │   ├── Triggers/{id}: {event_type, action, enabled}
│   │   ├── Queue/{msg_id}: {status, scheduled_time, recipients}
│   │   ├── Logs/{entry_id}: {type, sent_count, timestamp}
│   │   └── Counters: {NoticeCount, CircularCount}
│   │
│   └── All Notices/Announcements/   ← [LEGACY] dual-write for mobile app
│
├── Users/                           ← User Profiles
│   ├── Admin/{school_code}/{admin_id}/
│   │   ├── name, email, phone, password, Role, Active
│   │   └── AccessHistory: {LastLogin, LoginAttempts, LockedUntil, IsLoggedIn}
│   ├── Teachers/{school_id}/{teacher_id}/
│   │   ├── Name, phone, email, qualification, status
│   │   ├── Duties/{duty_type}
│   │   └── ProfilePic
│   └── Parents/{school_id}/{student_id}/   ← Students
│       ├── Name, Class, Section, father_name, mother_name
│       ├── phone, email, address, city, state, dob, gender
│       ├── Enrollment: boolean, Status: Active|TC|Withdrew
│       ├── Month Fee/{month}: 1 (paid flag)
│       ├── Fees Record/{receipt_key}: {amount, date, mode}
│       ├── Oversubmitted fees: amount
│       ├── Discount/{type}: amount
│       ├── Additional_subjects/{code}: enabled
│       └── TC/{key}: {issue_date, reason, status}
│
├── Indexes/                         ← Fast Lookups
│   ├── School_codes/{code}: school_id
│   └── School_names/{hash}: school_id
│
└── RateLimit/                       ← Security
    └── Login/{ip_hash}: {windowStart, fails}
```

### 4.2 Data Size Estimates

| Node | Records per School | Growth Rate |
|------|-------------------|-------------|
| Students (Users/Parents) | 500-5,000 | +500/year |
| Teachers | 20-200 | +10/year |
| Attendance records | ~180 days × students | ~90,000/year |
| Fee records | 12 months × students | ~6,000/year |
| Exam marks | exams × subjects × students | ~25,000/year |
| Vouchers/Ledger | 50-200/month | ~2,000/year |

### 4.3 Key Design Decisions

1. **Session-scoped data**: All academic data lives under `{session_year}/` enabling clean year-over-year separation
2. **Attendance as string**: `"PPAP...L"` — single string per day per student/staff rather than per-student objects (compact but limits querying)
3. **Denormalized students**: Student profiles at `Users/Parents/` but enrollment flags at `Students/List/` under each section
4. **No foreign keys**: Firebase has no referential integrity — the application enforces consistency (e.g., enrollment guard on section delete)
5. **Counter-based IDs**: Sequential IDs via atomic Firebase counter nodes (receipt numbers, voucher numbers, etc.)

---

## 5. Data Flow Analysis

### 5.1 Authentication Flow

```
Browser                    Server                      Firebase
  │                          │                            │
  │  POST /admin_login       │                            │
  │  {code, id, password}    │                            │
  │ ─────────────────────▶   │                            │
  │                          │  GET RateLimit/Login/{ip}   │
  │                          │ ──────────────────────────▶ │
  │                          │  ◀────── {fails, window}    │
  │                          │                            │
  │                          │  GET Indexes/School_codes   │
  │                          │ ──────────────────────────▶ │
  │                          │  ◀────── school_id          │
  │                          │                            │
  │                          │  GET Users/Admin/{code}/{id}│
  │                          │ ──────────────────────────▶ │
  │                          │  ◀────── {password, Role}   │
  │                          │                            │
  │                          │  password_verify()          │
  │                          │                            │
  │                          │  GET System/Schools/{id}/   │
  │                          │      subscription           │
  │                          │ ──────────────────────────▶ │
  │                          │  ◀────── {status, expiry}   │
  │                          │                            │
  │                          │  SET last_login, IsLoggedIn │
  │                          │ ──────────────────────────▶ │
  │                          │                            │
  │  ◀─── Set-Cookie: session│                            │
  │       + redirect /home   │                            │
```

### 5.2 Fee Payment Flow

```
fees_counter.php          Fees Controller              Firebase
  │                          │                            │
  │  AJAX: lookup_student    │                            │
  │  {user_id}               │                            │
  │ ─────────────────────▶   │  GET Users/Parents/{sch}/  │
  │                          │      {user_id}              │
  │                          │ ──────────────────────────▶ │
  │  ◀─── {name, class, ...} │                            │
  │                          │                            │
  │  AJAX: fetch_months      │                            │
  │  {user_id}               │                            │
  │ ─────────────────────▶   │  GET Month Fee/{month}     │
  │  ◀─── {April:0, May:1}   │                            │
  │                          │                            │
  │  AJAX: submit_fees       │                            │
  │  {userId, months[],      │                            │
  │   paymentMode, amounts}  │                            │
  │ ─────────────────────▶   │                            │
  │                          │  SET Fees Record/{receipt}  │
  │                          │  UPDATE Month Fee/{month}:1 │
  │                          │  SET Accounts/Vouchers/...  │
  │                          │ ──────────────────────────▶ │
  │                          │                            │
  │                          │  (Ops_accounting journal)   │
  │                          │  SET Ledger/{entry_id}      │
  │                          │  UPDATE Closing_balances    │
  │                          │ ──────────────────────────▶ │
  │                          │                            │
  │  ◀─── {success, receipt} │                            │
```

### 5.3 Exam → Result → Report Card Flow

```
1. CREATE EXAM (Exam.php)
   → Schools/{sch}/{year}/Exams/{exam_id}

2. DESIGN TEMPLATE (Result.php)
   → Schools/{sch}/{year}/Results/Templates/{exam_id}

3. ENTER MARKS (Result.php)
   → Schools/{sch}/{year}/Results/Marks/{exam_id}/{class}/{section}/{student}

4. COMPUTE RESULTS (Result.php)
   → Reads Marks + Template
   → Calculates grade, percentage, rank (competition: 1,1,3)
   → Schools/{sch}/{year}/Results/Computed/{exam_id}/{class}/{section}/{student}

5. GENERATE REPORT CARD (Result.php)
   → Reads Computed + Template + Student Profile
   → Renders print-ready HTML (no header/footer)

6. ANALYTICS (Examination.php)
   → Reads Computed for all students
   → Generates grade distribution, merit lists, subject analysis
```

### 5.4 Communication Trigger Flow

```
Event Source                Communication_helper         Firebase
  │                              │                         │
  │  fire_event('fee_received',  │                         │
  │   {student_id, amount})      │                         │
  │ ────────────────────────▶    │                         │
  │                              │  GET Triggers/           │
  │                              │ ──────────────────────▶  │
  │                              │  ◀── [matching triggers] │
  │                              │                         │
  │                              │  GET Templates/{tpl_id}  │
  │                              │ ──────────────────────▶  │
  │                              │  ◀── {subject, body}     │
  │                              │                         │
  │                              │  Replace {{variables}}   │
  │                              │  Resolve recipients      │
  │                              │                         │
  │                              │  PUSH Queue/{msg_id}     │
  │                              │ ──────────────────────▶  │
  │                              │                         │
  │  ◀── queued_count            │                         │
```

### 5.5 Cross-Module Integration Map

```
┌──────────────┐     journal entries     ┌──────────────┐
│   Fees.php   │ ──────────────────────▶ │ Accounting   │
│              │                         │              │
│   Hr.php     │ ──────────────────────▶ │ (Ledger,     │
│  (Payroll)   │     salary journals     │  Vouchers,   │
│              │                         │  Balances)   │
│ Library.php  │ ──────────────────────▶ │              │
│  (Fines)     │     fine journals       │              │
└──────────────┘                         └──────────────┘
       │
       │  fire_event()
       ▼
┌──────────────┐     dual-write          ┌──────────────┐
│Communication │ ──────────────────────▶ │ Legacy Path  │
│  (Notices,   │                         │ All Notices/ │
│   Triggers,  │                         │ Announcements│
│   Queue)     │                         │ (Mobile App) │
└──────────────┘                         └──────────────┘
       │
       │ attendance triggers
       │
┌──────────────┐     enrollment check    ┌──────────────┐
│ Attendance   │ ◀───────────────────── │   Classes    │
│              │                         │ (Students/   │
│              │                         │  List)       │
└──────────────┘                         └──────────────┘
```

---

## 6. Mobile App Integration

### 6.1 Current Integration Points

The mobile app connects to the same Firebase Realtime Database and Firebase Storage:

| Feature | Firebase Path | Mobile Access |
|---------|-------------|--------------|
| Student Profile | `Users/Parents/{school_id}/{student_id}` | Direct read |
| Attendance | `{session}/Attendance/{date}` | Direct read |
| Fee Status | `Users/Parents/{id}/Month Fee/` | Direct read |
| Notices | `All Notices/Announcements/` | Direct read (legacy path) |
| School Logo | `Schools/{id}/Logo` | Direct read |
| Report Cards | Via web URL | WebView |

### 6.2 Mobile-Specific Considerations

- **Legacy dual-write**: The `Communication_helper::write_event_notice()` writes to both `Communication/Notices/` (new) and `All Notices/Announcements/` (legacy for mobile compatibility)
- **Attendance strings**: Mobile app parses the same `"PPAP...L"` concatenated format
- **No dedicated API layer**: Mobile reads directly from Firebase using client SDK with Security Rules
- **Biometric device API**: `Attendance.php` exposes device integration endpoints (`device_sync`, `device_punch`) for RFID/biometric hardware

### 6.3 API Endpoints for External Systems

| Endpoint | Purpose | Auth |
|----------|---------|------|
| `attendance/device_sync` | Biometric device sync | API key |
| `attendance/device_punch` | Record punch event | API key |
| `attendance/mobile_mark` | Mobile attendance marking | Session |
| `attendance/mobile_summary` | Attendance summary for app | Session |

---

## 7. Security Analysis

### 7.1 Authentication & Authorization

| Control | Implementation | Rating |
|---------|---------------|--------|
| **Password Storage** | `password_hash()` / `password_verify()` with auto-migration from plaintext | ★★★★☆ |
| **Brute Force** | IP rate limit (20/15min) + Account lockout (5/30min) | ★★★★★ |
| **Session Management** | `sess_regenerate_destroy: TRUE`, 2-hour expiry, HTTP-only cookies | ★★★★☆ |
| **CSRF Protection** | CI3 cookie-based (school) + Session-based (SA), X-CSRF-Token header support | ★★★★★ |
| **RBAC** | `_require_role()` with Super Admin bypass, Teacher assignment checks | ★★★★☆ |
| **Input Validation** | `safe_path_segment()` blocks Firebase injection chars (`/`, `.`, `#`, `$`, `[`, `]`) | ★★★★★ |

### 7.2 Security Headers

Applied in `MY_Controller` and `MY_Superadmin_Controller`:

```
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' ...
```

### 7.3 RBAC Role Hierarchy

```
Super Admin    → Full access to everything
Principal      → Full school access, admin management
Vice Principal → School access except admin management
Admin          → Standard school operations
Teacher        → Only assigned classes/sections/subjects
Accountant     → Financial modules only
```

### 7.4 Identified Risks

| Risk | Severity | Mitigation |
|------|----------|-----------|
| **Firebase Security Rules** | HIGH | Not audited in codebase — mobile clients could bypass server-side RBAC if rules are permissive |
| **No password complexity** | MEDIUM | Login controller doesn't enforce minimum password requirements |
| **Encryption key in config** | MEDIUM | Hardcoded in `config.php` — should be environment variable |
| **cookie_secure: FALSE** | MEDIUM | Must enable when deploying with HTTPS |
| **CSP unsafe-inline/eval** | LOW | Required by AdminLTE/jQuery patterns — difficult to remove |
| **No 2FA** | LOW | Single-factor auth for all roles including Super Admin |
| **Session fixation** | MITIGATED | `sess_regenerate_destroy` enabled |
| **XSS in views** | LOW | Most output uses `htmlspecialchars()` but some views have raw `<?= $var ?>` |

### 7.5 Subscription Enforcement

The system performs subscription checks every 5 minutes (cached in session). If subscription expires:
1. 7-day grace period with warning banner
2. After grace: force logout on next request
3. Firebase unreachable: gracefully skips check (doesn't lock out users during Firebase downtime)

---

## 8. Performance Analysis

### 8.1 Firebase Read Optimization

| Pattern | Usage | Impact |
|---------|-------|--------|
| **shallow_get()** | Class/section listing | Avoids fetching entire subtrees — only child keys returned |
| **Path-specific reads** | Targeted `get()` calls | Reads only needed nodes, not parent trees |
| **Student search cache** | Operations_accounting | 5-minute TTL cache via CI cache driver |
| **Session caching** | Subscription status | Checked every 5 minutes, not every request |

### 8.2 Performance Concerns

| Issue | Severity | Description |
|-------|----------|-------------|
| **N+1 reads** | HIGH | Some controllers loop through students and read individual profiles instead of batch-reading the parent node |
| **Attendance string parsing** | MEDIUM | Concatenated `"PPAP...L"` strings require position-based parsing — error-prone for large classes |
| **No pagination on Firebase** | MEDIUM | Firebase RTDB doesn't support server-side pagination — entire node must be read then sliced in PHP |
| **Large view files** | LOW | `fees_counter.php` at 91.8KB — large inline CSS/JS, but served gzipped |
| **No CDN** | MEDIUM | Static assets (CSS, JS, images) served from application server |
| **No Redis/Memcached** | MEDIUM | Only CI file-based caching available |

### 8.3 Debug Performance Monitoring

When `GRADER_DEBUG` is enabled:
- Every Firebase operation is timed
- Operations ≥500ms are flagged as `SLOW_OP`
- Top 10 most-accessed Firebase paths are tracked
- Average request and Firebase operation times are computed
- All data viewable in Super Admin Debug Panel

### 8.4 Estimated Request Performance

| Operation | Firebase Reads | Estimated Latency |
|-----------|---------------|-------------------|
| Dashboard load | 5-8 | 200-400ms |
| Student list (class) | 2-3 | 100-200ms |
| Fee counter lookup | 3-4 | 150-300ms |
| Mark attendance | 1 read + 1 write | 100-200ms |
| Compute class results | N students × 2 | 500ms-2s |
| Report card generation | 3-5 | 200-400ms |
| Bulk payroll run | N employees × 3 | 1-5s |

---

## 9. Codebase Structure

### 9.1 Directory Layout

```
school/
├── application/
│   ├── config/
│   │   ├── autoload.php          Autoloaded libraries, helpers, models
│   │   ├── config.php            Core settings (session, CSRF, encryption)
│   │   ├── constants.php         GRADER_DEBUG flag, file modes
│   │   ├── hooks.php             Debug logger hooks
│   │   └── routes.php            699 lines, 300+ route definitions
│   │
│   ├── controllers/              48 controller files
│   │   ├── Admin.php             Dashboard, admin CRUD (491 lines)
│   │   ├── Admin_login.php       Auth with brute-force protection (566 lines)
│   │   ├── Academic.php          Curriculum, calendar, timetable (~900 lines)
│   │   ├── Accounting.php        Double-entry accounting (~1200 lines)
│   │   ├── Admission_crm.php     Inquiry → Application pipeline (~1100 lines)
│   │   ├── Assets.php            Asset lifecycle management
│   │   ├── Attendance.php        Student/Staff/Device attendance (~1500 lines)
│   │   ├── Backup_cron.php       Automated backup runner
│   │   ├── Classes.php           Class/section/transfer (~600 lines)
│   │   ├── Communication.php     Messages, notices, triggers (1341 lines)
│   │   ├── Events.php            School events (~800 lines)
│   │   ├── Exam.php              Exam CRUD (~800 lines)
│   │   ├── Examination.php       Analytics, merit, tabulation (1149 lines)
│   │   ├── Fee_management.php    Advanced fee config (~1400 lines)
│   │   ├── Fees.php              Fee collection POS (~900 lines)
│   │   ├── Health_check.php      System validation (16 checks)
│   │   ├── Hostel.php            Hostel management
│   │   ├── Hr.php                HR & Payroll (~2650 lines)
│   │   ├── Inventory.php         Inventory management
│   │   ├── Library.php           Library management
│   │   ├── Operations.php        Operations hub
│   │   ├── Result.php            Result engine (~1200 lines)
│   │   ├── School_config.php     School configuration (~800 lines)
│   │   ├── Schools.php           School profile/gallery
│   │   ├── Sis.php               Student Information System (~1200 lines)
│   │   ├── Student.php           Student CRUD (~700 lines)
│   │   ├── Subjects.php          Subject management (~400 lines)
│   │   ├── Superadmin.php        SA dashboard
│   │   ├── Superadmin_*.php      7 SA sub-controllers
│   │   └── Transport.php         Transport management
│   │
│   ├── core/
│   │   ├── MY_Controller.php     School-admin base (459 lines)
│   │   └── MY_Superadmin_Controller.php  SA base (172 lines)
│   │
│   ├── hooks/
│   │   └── Debug_logger.php      Pre/post request debug logging
│   │
│   ├── libraries/
│   │   ├── Firebase.php          Firebase Admin SDK wrapper (349 lines)
│   │   ├── Communication_helper.php  Event trigger engine (371 lines)
│   │   ├── Operations_accounting.php  Shared accounting (536 lines)
│   │   └── Debug_tracker.php     Debug singleton (406 lines)
│   │
│   ├── models/
│   │   ├── Common_model.php      Firebase CRUD + Storage (550+ lines)
│   │   └── Common_sql_model.php  Legacy SQL model
│   │
│   └── views/                    164 PHP view files
│       ├── include/              header.php, footer.php
│       ├── academic/             Curriculum, calendar views
│       ├── accounting/           Chart of accounts, ledger, reports
│       ├── admission_crm/        CRM pipeline views
│       ├── assets/               Asset management views
│       ├── attendance/           6 attendance views
│       ├── communication/        8 communication views
│       ├── events/               Event management views
│       ├── examination/          4 analytics views
│       ├── fee_management/       Advanced fee views
│       ├── health_check/         System health view
│       ├── hostel/               Hostel management views
│       ├── hr/                   HR & Payroll SPA
│       ├── inventory/            Inventory views
│       ├── library/              Library management views
│       ├── operations/           Operations hub view
│       ├── result/               8 result views
│       ├── school_config/        Config SPA
│       ├── sis/                  SIS views
│       ├── superadmin/           SA panel views
│       ├── transport/            Transport views
│       └── [68 root-level views]
│
├── vendor/                       Composer dependencies (Kreait Firebase SDK)
├── tools/                        Utility tools
├── uploads/                      File uploads
└── phpunit.xml                   Test configuration
```

### 9.2 Code Metrics

| Metric | Count |
|--------|-------|
| **Controller files** | 48 |
| **View files** | 164 |
| **Library files** | 4 |
| **Model files** | 2 |
| **Core files** | 2 |
| **Config files** | 5 |
| **Routes defined** | 300+ |
| **Total PHP lines (est.)** | 50,000+ |
| **Total view lines (est.)** | 80,000+ |
| **Largest controller** | Hr.php (~2,650 lines) |
| **Largest view** | fees_counter.php (91.8KB) |

### 9.3 Naming Conventions

| Convention | Pattern | Examples |
|------------|---------|----------|
| Controller class | PascalCase | `Admin`, `Fee_management`, `Superadmin_plans` |
| Controller file | Same as class | `Admin.php`, `Fee_management.php` |
| View file | snake_case | `all_student.php`, `fees_counter.php` |
| View directory | snake_case | `fee_management/`, `admission_crm/` |
| Route | snake_case with `/` | `fee_management/categories`, `hr/payroll` |
| Firebase path | Mixed (Title Case + spaces) | `Class 9th`, `Section A`, `Students/List` |
| CSS variables | `--module-property` | `--fc-teal`, `--sfr-navy`, `--fm-border` |

### 9.4 Dependency Graph

```
MY_Controller ──▶ Firebase.php (autoloaded)
     │            Common_model.php (autoloaded as $this->CM)
     │
     ├──▶ 39 School Controllers
     │      │
     │      ├──▶ Communication_helper.php (loaded on demand)
     │      ├──▶ Operations_accounting.php (loaded on demand)
     │      └──▶ Debug_tracker.php (conditional on GRADER_DEBUG)
     │
MY_Superadmin_Controller ──▶ Firebase.php
     │
     ├──▶ 8 SA Controllers
     │      └──▶ Debug_tracker.php (conditional)
     │
CI_Controller ──▶ Admin_login.php (no auth required)
               ──▶ Superadmin_login.php
               ──▶ Health_check.php
               ──▶ Backup_cron.php
```

---

## 10. Architecture Scorecard

| Category | Score | Notes |
|----------|-------|-------|
| **Modularity** | ★★★★☆ (4/5) | Well-separated modules with clear boundaries. Each module has its own controller, views, and routes. Minor coupling between Fees↔Accounting and Communication↔multiple modules. |
| **Security** | ★★★★☆ (4/5) | Strong CSRF, rate limiting, RBAC, input sanitization. Deductions for: no 2FA, hardcoded encryption key, Firebase Security Rules not audited. |
| **Scalability** | ★★★☆☆ (3/5) | Multi-tenant design is solid. Limited by Firebase RTDB (no server-side pagination, no complex queries, 10MB/s write limit). No caching layer beyond CI file cache. |
| **Maintainability** | ★★★☆☆ (3/5) | Consistent patterns (MY_Controller, json_success/error). Large controller files (Hr.php 2650 lines). Inline CSS/JS in views makes updates tedious. |
| **Code Quality** | ★★★★☆ (4/5) | Consistent error handling, try-catch patterns, graceful degradation. Some N+1 query patterns. Grade engine duplicated between PHP and JS. |
| **UI/UX** | ★★★★☆ (4/5) | Cohesive teal/navy theme with day/night modes. AdminLTE provides solid base. CSS variable system enables theming. Some views still have hardcoded colors. |
| **Testing** | ★★☆☆☆ (2/5) | phpunit.xml exists but minimal test coverage. Health_check.php provides 16 runtime validation checks. No unit tests for controllers/models. |
| **Documentation** | ★★★☆☆ (3/5) | MEMORY.md serves as living documentation. No inline API docs. No Swagger/OpenAPI. Route file is well-organized. |
| **DevOps** | ★★☆☆☆ (2/5) | No CI/CD pipeline. Manual deployment. Backup system exists (Superadmin_backups) but no automated testing. Debug panel is excellent for monitoring. |
| **Data Integrity** | ★★★☆☆ (3/5) | Enrollment guards on delete. No foreign key enforcement. Dual-write pattern risks inconsistency. Counter-based IDs could race under concurrency. |

**Overall Score: 3.3 / 5.0** — Solid functional implementation with room for infrastructure maturity.

---

## 11. Future Scalability

### 11.1 Current Bottlenecks

| Bottleneck | Impact | Recommendation |
|------------|--------|---------------|
| **Firebase RTDB limits** | 100K concurrent connections, 256MB/node, no complex queries | Migrate hot paths to Firestore or add PostgreSQL for relational data |
| **No server-side pagination** | Full node reads for large datasets | Implement cursor-based pagination with Firebase orderByKey/limitToFirst |
| **Single server** | XAMPP is single-instance | Deploy to cloud (GCP/AWS) with load balancer |
| **No caching layer** | Every request hits Firebase | Add Redis for session, student lists, fee structures |
| **Monolithic codebase** | 48 controllers in one app | Extract high-traffic modules (Attendance, Fees) into microservices |
| **No message queue** | Communication triggers are synchronous | Add RabbitMQ/SQS for notification delivery |

### 11.2 Scaling Roadmap

**Phase 1 — Quick Wins (1-3 months)**
- Add Redis caching for frequently-read data (student lists, class structures, fee templates)
- Enable Firebase connection pooling
- Implement proper CDN for static assets
- Add database indexes (Firebase `.indexOn` rules)
- Enable `cookie_secure: TRUE` with HTTPS

**Phase 2 — Infrastructure (3-6 months)**
- Deploy to cloud hosting (GCP App Engine recommended for Firebase proximity)
- Add CI/CD pipeline (GitHub Actions → automated tests → staging → production)
- Implement proper API layer for mobile (REST endpoints with JWT auth)
- Add comprehensive unit and integration tests
- Implement queue system for notifications and bulk operations

**Phase 3 — Architecture Evolution (6-12 months)**
- Evaluate Firestore migration for complex query needs (reporting, analytics)
- Extract authentication into dedicated service (Firebase Auth or Auth0)
- Implement WebSocket for real-time dashboards (instead of polling)
- Add multi-region deployment for geographic scaling
- Implement proper event sourcing for audit trail

### 11.3 Estimated Capacity

| Metric | Current Capacity | With Optimization |
|--------|-----------------|-------------------|
| Schools | 50-100 | 500+ |
| Students per school | 5,000 | 10,000+ |
| Concurrent users | 200-500 | 5,000+ |
| Daily transactions | 10,000 | 100,000+ |
| Storage | 10GB | 1TB+ |

---

## 12. Final Summary

### 12.1 Strengths

1. **Comprehensive feature set**: 34 modules covering every aspect of school administration — from admissions to alumni, fees to financial accounting, attendance to analytics
2. **Clean multi-tenant architecture**: `SCH_XXXXXX` partitioning with session-based auth prevents cross-tenant data leakage
3. **Strong security posture**: CSRF protection (dual-mode for school/SA panels), rate limiting, RBAC with teacher-level access control, Firebase path injection prevention
4. **Consistent patterns**: `MY_Controller` base class provides uniform auth, CSRF, subscription checks, and helper methods across all 39 school controllers
5. **Debug infrastructure**: Production-grade monitoring with GRADER_DEBUG toggle, SLOW_OP detection, schema validation, and audit logging
6. **Cohesive UI/UX**: Teal/navy theme with CSS variable system supporting day/night modes across 164 view files
7. **Cross-module integration**: Fees → Accounting journals, Communication triggers from 6 modules, Operations → shared accounting library

### 12.2 Areas for Improvement

1. **Testing**: Near-zero automated test coverage — the Health_check controller provides runtime validation but no unit/integration tests
2. **Large file sizes**: Some controllers (Hr.php: 2650 lines) and views (fees_counter.php: 91KB) would benefit from decomposition
3. **Code duplication**: Grade computation engine exists in both PHP (Result.php) and JavaScript (marks_sheet.php) and must be kept in sync manually
4. **Firebase limitations**: No server-side pagination, limited query capabilities, attendance stored as position-dependent strings
5. **No API layer**: Mobile app reads Firebase directly — no server-side validation for mobile requests
6. **Deployment**: Manual deployment on XAMPP — no CI/CD, no staging environment, no automated rollbacks

### 12.3 Architecture Classification

| Dimension | Classification |
|-----------|---------------|
| **Pattern** | Monolithic MVC with Multi-Tenant SaaS |
| **Data** | NoSQL (Firebase RTDB) — denormalized, real-time |
| **Auth** | Session-based with RBAC (6 roles) |
| **Frontend** | Server-rendered with AJAX SPAs (jQuery) |
| **Deployment** | Single-server (XAMPP), no containerization |
| **Maturity** | Production-functional, pre-DevOps |

### 12.4 Final Assessment

The Grader School ERP is a **functionally complete, production-grade SaaS application** that successfully manages the full lifecycle of school administration. Its greatest strength is the breadth of coverage — 34 modules with deep feature sets in each. The codebase follows consistent patterns that make it maintainable despite its size.

The primary growth path is infrastructure maturity: automated testing, CI/CD pipelines, caching layers, and eventually a migration path from Firebase RTDB to a more scalable database for complex query workloads. The security posture is strong for its scale, with the key recommendation being a Firebase Security Rules audit and enabling HTTPS-only cookies for production deployment.

---

*Report generated: 2026-03-13*
*Codebase analyzed: 48 controllers, 164 views, 4 libraries, 2 models, 300+ routes*
*Firebase paths cataloged: 100+ unique read/write paths across 5 top-level nodes*
