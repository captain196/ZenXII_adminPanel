# Grader School ERP — Developer Handbook

**Version:** 1.0
**Last Updated:** 2026-03-13
**Framework:** CodeIgniter 3.1.x | PHP 7.4+ | Firebase Realtime Database
**Repository:** github.com/ankitprajapati8134/SchoolX

---

## Table of Contents

1. [System Architecture](#1-system-architecture)
2. [Database Reference](#2-database-reference)
3. [Module Documentation](#3-module-documentation)
4. [API Documentation](#4-api-documentation)
5. [Deployment Guide](#5-deployment-guide)
6. [Maintenance Guide](#6-maintenance-guide)
7. [Future Development Guidelines](#7-future-development-guidelines)

---

# 1. System Architecture

## 1.1 High-Level Overview

The application is a **Multi-Tenant SaaS** school ERP built on CodeIgniter 3 with Firebase Realtime Database as the sole backend data store. Each school is a tenant isolated by a unique `SCH_XXXXXX` key.

```
┌──────────────────────────────────────────────────────────────┐
│                         CLIENTS                               │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌─────────────┐  │
│  │ Browser  │  │ Mobile   │  │ Biometric│  │ Super Admin │  │
│  │ (Admin)  │  │ App      │  │ Devices  │  │ Panel       │  │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └──────┬──────┘  │
└───────┼──────────────┼──────────────┼───────────────┼────────┘
        │              │              │               │
┌───────▼──────────────▼──────────────▼───────────────▼────────┐
│               CodeIgniter 3 Application Layer                 │
│                                                               │
│  ┌─────────────┐  ┌──────────────────┐  ┌──────────────────┐ │
│  │MY_Controller│  │MY_Superadmin_Ctrl│  │  CI_Controller   │ │
│  │ (39 ctrls)  │  │   (8 ctrls)      │  │ (login, health)  │ │
│  └──────┬──────┘  └────────┬─────────┘  └────────┬─────────┘ │
│         │                  │                      │           │
│  ┌──────┴──────────────────┴──────────────────────┴─────────┐ │
│  │  Libraries: Firebase.php | Exam_engine.php               │ │
│  │  Communication_helper.php | Operations_accounting.php    │ │
│  │  Debug_tracker.php                                       │ │
│  ├──────────────────────────────────────────────────────────┤ │
│  │  Models: Common_model.php ($this->CM)                    │ │
│  └──────────────────────┬───────────────────────────────────┘ │
│                         │                                     │
└─────────────────────────┼─────────────────────────────────────┘
                          │
              ┌───────────┴───────────┐
              ▼                       ▼
     ┌────────────────┐     ┌──────────────────┐
     │ Firebase RTDB  │     │ Firebase Storage  │
     │ (All data)     │     │ (Files/images)    │
     └────────────────┘     └──────────────────┘
```

## 1.2 Technology Stack

| Layer | Technology | Details |
|-------|-----------|---------|
| **Server** | Apache (XAMPP) | PHP 7.4+, mod_rewrite for clean URLs |
| **Framework** | CodeIgniter 3.1.13 | MVC, `MY_Controller` subclass prefix |
| **Database** | Firebase Realtime Database | Kreait Admin SDK, Asia SE1 region |
| **File Storage** | Firebase Storage | Kreait SDK, `graders-1c047.appspot.com` |
| **Frontend** | AdminLTE 2.x + Bootstrap 3 | jQuery, DataTables, Chart.js 4.4 |
| **Auth** | Custom session-based | PHP sessions (file driver), 2-hour expiry |
| **Dependencies** | Composer | Kreait Firebase SDK auto-loaded |

## 1.3 Multi-Tenant Data Isolation

Every school's data is partitioned by `SCH_XXXXXX`:

```
Schools/SCH_000001/...          ← School 1
Schools/SCH_000002/...          ← School 2
Users/Parents/SCH_000001/...    ← School 1's students
Users/Teachers/SCH_000001/...   ← School 1's teachers
```

`MY_Controller` binds `$this->school_name` (= `SCH_XXXXXX`) from the session. All Firebase paths are scoped to this key. Cross-tenant access is impossible at the controller level.

## 1.4 Session-Year Scoping

Academic data is further isolated by session year (e.g., `2024-2025`):

```
Schools/{school_id}/2024-2025/Class 9th/Section A/Students/List/{id}: 1
Schools/{school_id}/2025-2026/Class 9th/Section A/Students/List/{id}: 1
```

Switching sessions shows only that year's enrollment, attendance, exams, results, and accounts.

## 1.5 Controller Hierarchy

```
CI_Controller
  ├── MY_Controller (school-admin, 39 controllers)
  │   ├── Auth guard (session-based)
  │   ├── CSRF (CI3 cookie-based)
  │   ├── Subscription check (every 5 min)
  │   ├── RBAC (_require_role, _teacher_can_access)
  │   └── Security headers
  │
  ├── MY_Superadmin_Controller (SA panel, 8 controllers)
  │   ├── Separate auth (sa_id in session)
  │   ├── Session-based CSRF (not cookie — avoids collision)
  │   ├── Activity logging (sa_log)
  │   └── Security headers
  │
  └── CI_Controller (no auth)
      ├── Admin_login.php
      ├── Superadmin_login.php
      ├── Health_check.php
      └── Backup_cron.php
```

## 1.6 Request Lifecycle

```
1. Apache receives request
2. CI3 routes to controller/method (routes.php, 300+ routes)
3. Base controller constructor:
   a. Send security headers
   b. Check session auth (redirect if not logged in)
   c. Validate session integrity (_is_safe_segment)
   d. Subscription check (every 300s)
   e. CSRF verification (all POST)
   f. Share vars with views (school_id, session_year, admin_role, etc.)
4. Controller method executes
   - Page load: $this->load->view('include/header') + view + footer
   - AJAX: json_success() or json_error()
5. Debug hooks (if GRADER_DEBUG):
   a. pre_controller: record request
   b. post_system: flush debug entries to JSONL log
```

## 1.7 Key Properties Available in All Controllers

```php
// In any controller extending MY_Controller:
$this->school_name          // "SCH_000001" (Firebase key)
$this->school_id            // "SCH_000001" (alias)
$this->school_code          // "10004" (login code)
$this->school_display_name  // "Delhi Public School"
$this->admin_id             // Current admin ID
$this->admin_name           // "John Smith"
$this->admin_role           // "Super Admin" | "Admin" | "Teacher" | etc.
$this->session_year         // "2025-2026"
$this->firebase             // Firebase library instance
$this->CM                   // Common_model instance
$this->school_features      // Feature flags from subscription
$this->available_sessions   // ["2024-2025", "2025-2026"]
```

## 1.8 View Variables (auto-shared by MY_Controller)

```php
// In any view loaded by a MY_Controller-based controller:
$school_id                  // "SCH_000001"
$school_name                // "Delhi Public School" (display name)
$school_firebase_key        // "SCH_000001" (for JS Firebase calls)
$school_code                // "10004"
$session_year               // "2025-2026"
$current_session            // "2025-2026"
$admin_id                   // Current admin ID
$admin_name                 // "John Smith"
$admin_role                 // "Super Admin"
$school_features            // Feature flags
$subscription_warning       // Grace period warning (if applicable)
$csrf_token                 // CSRF token for forms
```

---

# 2. Database Reference

## 2.1 Firebase Realtime Database Schema

### Top-Level Structure

```
Firebase Root
├── System/           ← Platform-wide data (Super Admin only)
├── Schools/          ← Per-school academic & operational data
├── Users/            ← User profiles (Admin, Teachers, Students)
├── Indexes/          ← Fast lookup paths
└── RateLimit/        ← Brute-force protection
```

### 2.2 System/ — Platform Data

```
System/
├── Schools/{school_id}/
│   ├── profile/          {name, address, phone, email, logo, board, status}
│   ├── subscription/     {plan_id, status, start_date, end_date, grace_end}
│   └── stats_cache/      {student_count, teacher_count, class_count}
│
├── Plans/{plan_id}/      {name, price, billing_cycle, modules[], max_students}
│
├── Payments/{payment_id}/ {school_id, amount, status, payment_date, method}
│
├── Backups/{uid}/{id}/   {backup_id, filename, size_bytes, created_at, status}
│
├── BackupSchedule/       {enabled, frequency, retention_days, cron_key}
│
├── Logs/
│   ├── Activity/{date}/{id}      SA audit trail
│   ├── Errors/{date}/{id}        Error logs
│   ├── Logins/{date}/{id}        Login tracking
│   ├── SchoolLogins/{date}/{id}  Per-school logins
│   └── Payroll/{id}              Payroll audit
│
├── Stats/Summary/        {total_schools, total_students, active_schools}
│
└── API_Keys/{hash}/      {device_id, school_id, status, created_at}
```

### 2.3 Schools/ — Per-School Data

```
Schools/{school_id}/
│
├── Config/
│   ├── Profile/          {name, address, phone, email, principal, logo_url}
│   ├── Board/            {name, code, grade_scales}
│   ├── Classes/          [{name, numeric_order, stream}]
│   ├── Streams/          {name, subjects[]}
│   ├── ActiveSession/    "2025-2026"
│   └── Devices/{id}/     {device_name, type, api_key, status}
│
├── Sessions/             [{year, is_active, created_at}]
├── Subject_list/{idx}/{code}/  {name, category, stream}
├── Logo/                 "url_string"
│
├── Communication/
│   ├── Messages/Conversations/{id}   {participants, subject}
│   ├── Messages/Chat/{conv}/{msg}    {sender, content, timestamp}
│   ├── Messages/Inbox/{role}/{uid}/{conv}  {unread_count}
│   ├── Notices/{id}      {title, content, date, scope}
│   ├── Circulars/{id}    {title, content, issued_date}
│   ├── Templates/{id}    {name, subject, body}
│   ├── Triggers/{id}     {event_type, action, enabled}
│   ├── Queue/{id}        {status, scheduled_time, recipients}
│   ├── Logs/{id}         {type, sent_count, timestamp}
│   └── Counters/         {NoticeCount, CircularCount, QueueCount}
│
├── CRM/Admissions/
│   ├── Settings/         {pipeline_stages, form_fields}
│   ├── Inquiries/{id}    {name, phone, status}
│   ├── Applications/{id} {form_data, class, status}
│   ├── Waitlist/{id}     {application_id, position}
│   └── Counter           auto-increment
│
├── Events/
│   ├── List/{id}         {name, date, type, description, status}
│   ├── Media/{id}/{media}  photo/video URLs
│   ├── Participants/{id}/{person}  {name, role, attendance}
│   └── Counters/Event    auto-increment
│
├── Operations/
│   ├── Library/{Books|Issues|Fines|Categories|Counters}
│   ├── Transport/{Vehicles|Routes|Stops|Assignments|Fees|Counters}
│   ├── Hostel/{Buildings|Rooms|Allocations|Counters}
│   ├── Inventory/{Items|Categories|Vendors|Purchases|Issues|Counters}
│   └── Assets/{Assets|Categories|Assignments|Maintenance|Counters}
│
└── {session_year}/                  ← SESSION-SCOPED DATA
    ├── Class {N}/
    │   └── Section {X}/
    │       ├── Students/List/{id}: 1   (enrollment flag)
    │       ├── Subjects/{code}         {teacher_id}
    │       ├── Timetable/{period}      {subject, teacher, day}
    │       ├── max_strength            number
    │       └── ClassTeacher            teacher_id
    │
    ├── Teachers/{id}                   {Name}
    ├── Staff_Attendance/{date}         "PPAP...L"
    ├── Staff_Attendance/Late/{date}/{id}  {time}
    │
    ├── Results/
    │   ├── Templates/{exam}/{cls}/{sec}/{subj}  {components}
    │   ├── Marks/{exam}/{cls}/{sec}/{student}    {subject: marks}
    │   ├── Computed/{exam}/{cls}/{sec}/{student}  {grade, %, rank}
    │   ├── CumulativeConfig/Exams/{exam}         enabled
    │   └── Cumulative/{id}/{student}             {cumulative data}
    │
    ├── Exams/{exam_id}/
    │   ├── ExamName, ExamType, Status, StartDate, EndDate
    │   └── Schedule/{class}/{section}  {subject: {date, time}}
    │
    ├── Accounts/
    │   ├── ChartOfAccounts/{code}      {name, type, group, active}
    │   ├── Vouchers/{date}/{id}        {type, amount, debit, credit}
    │   ├── Ledger/{entry_id}           {account, debit, credit, date}
    │   ├── Ledger_index/by_date/{date}/{id}     true
    │   ├── Ledger_index/by_account/{code}/{id}  true
    │   ├── Closing_balances/{code}     {debit, credit, balance}
    │   ├── Voucher_counters/{type}     number
    │   └── Receipt_Index/{receipt}     receipt data
    │
    ├── Fees/
    │   ├── Classes Fees/{class_key}    {head: amount}
    │   ├── Session Fee                 number
    │   └── Exempted/{student}          {months}
    │
    ├── Attendance/{date}               "PPAP...L"
    ├── Attendance/Late/{date}/{id}     {time}
    │
    ├── HR/
    │   ├── Departments/{id}            {name, head_id}
    │   ├── Payroll/Runs/{id}           {period, status, totals}
    │   └── Leaves/Requests/{id}        {employee, type, dates, status}
    │
    ├── Academic/
    │   ├── Curriculum/{classSection}/{subject}/topics  [{title, status}]
    │   ├── Calendar/{id}               {title, date, type}
    │   └── Substitutes/{id}            {absent, substitute, class}
    │
    └── All Notices/{id}                [LEGACY — dual-write target]
```

### 2.4 Users/ — User Profiles

```
Users/
├── Admin/{school_code}/{admin_id}/
│   ├── name, email, phone, password (hashed), Role, Active
│   └── AccessHistory/  {LastLogin, LoginAttempts, LockedUntil, IsLoggedIn}
│
├── Teachers/{school_id}/{teacher_id}/
│   ├── Name, phone, email, qualification, status
│   ├── Duties/{duty_type}
│   └── Doc/ProfilePic
│
└── Parents/{school_id}/{student_id}/   ← Students
    ├── Name, Class, Section, father_name, mother_name
    ├── phone, email, address, city, state, dob, gender
    ├── Enrollment, Status (Active|TC|Withdrew)
    ├── Month Fee/{month}: 1            paid flag
    ├── Fees Record/{receipt}           {amount, date, mode}
    ├── Oversubmitted fees              amount
    ├── Discount/{type}                 amount
    ├── TC/{key}                        {issue_date, reason}
    └── History/{key}                   activity log
```

### 2.5 Indexes/ — Fast Lookups

```
Indexes/
├── School_codes/{code}: school_id     ← Login resolution
├── School_codes/Count                 ← ID generation counter
└── School_names/{hash}: school_id     ← Uniqueness check
```

### 2.6 Key Naming Conventions

| Entity | Firebase Path | Example |
|--------|--------------|---------|
| Class | `Class {N}` (with prefix) | `Class 9th`, `Class Nursery` |
| Section | `Section {X}` (with prefix) | `Section A` |
| Combined key | `{class} '{section}'` | `9th 'A'` |
| Student profile field | `Class` (no prefix) | `9th` |
| Student profile field | `Section` (no prefix) | `A` |
| Subject code | Uppercase abbreviation | `MTH`, `ENG`, `SCI` |
| Attendance string | Concatenated marks | `PPAPLHHV` |

### 2.7 Attendance Mark Codes

| Code | Meaning |
|------|---------|
| P | Present |
| A | Absent |
| L | Late |
| H | Holiday |
| T | Travel/Trip |
| V | Leave |

---

# 3. Module Documentation

## 3.1 Module Inventory

| # | Module | Controller(s) | Lines | Routes | Base Class |
|---|--------|--------------|-------|--------|------------|
| 1 | Authentication | Admin_login, Superadmin_login | ~855 | 5 | CI_Controller |
| 2 | Dashboard | Admin | ~491 | 8 | MY_Controller |
| 3 | Student Management | Student, Sis | ~1900 | 30+ | MY_Controller |
| 4 | Class Management | Classes | ~600 | 10+ | MY_Controller |
| 5 | Subject Management | Subjects | ~400 | 5+ | MY_Controller |
| 6 | Fees Collection | Fees | ~900 | 15+ | MY_Controller |
| 7 | Fee Management | Fee_management | ~1400 | 35 | MY_Controller |
| 8 | Exam Management | Exam | ~800 | 10+ | MY_Controller |
| 9 | Result Management | Result | ~1200 | 21 | MY_Controller |
| 10 | Examination Analytics | Examination | ~1149 | 10 | MY_Controller |
| 11 | Attendance | Attendance | ~1500 | 35 | MY_Controller |
| 12 | School Configuration | School_config | ~800 | 22 | MY_Controller |
| 13 | Academic Management | Academic | ~900 | 19 | MY_Controller |
| 14 | Accounting | Accounting | ~1200 | 40 | MY_Controller |
| 15 | HR & Payroll | Hr | ~2650 | 51 | MY_Controller |
| 16 | Communication | Communication | ~1341 | 38 | MY_Controller |
| 17 | Admission CRM | Admission_crm | ~1100 | 30 | MY_Controller |
| 18 | Events | Events | ~800 | 18 | MY_Controller |
| 19 | Library | Library | ~600 | 19 | MY_Controller |
| 20 | Transport | Transport | ~600 | 17 | MY_Controller |
| 21 | Hostel | Hostel | ~600 | 17 | MY_Controller |
| 22 | Inventory | Inventory | ~600 | 18 | MY_Controller |
| 23 | Assets | Assets | ~600 | 18 | MY_Controller |
| 24 | Operations Hub | Operations | ~200 | 2 | MY_Controller |
| 25 | Health Check | Health_check | ~400 | 3 | CI_Controller |
| 26-33 | SA Panel (8 ctrls) | Superadmin_* | ~3000 | 63 | MY_Superadmin |

**Totals: 34 modules | 48 controllers | 300+ routes | ~25,000+ lines**

## 3.2 Core Libraries

### Firebase.php — Database Wrapper

```php
// Read
$data = $this->firebase->get("Schools/{$this->school_name}/Config/Profile");

// Write (create or overwrite)
$this->firebase->set("Schools/{$school}/{$year}/Exams/{$id}", $examData);

// Partial update (merge)
$this->firebase->update("Schools/{$school}/Config", ['logo' => $url]);

// Delete
$this->firebase->delete("Schools/{$school}/{$year}/Exams/{$id}");
// or
$this->firebase->delete("Schools/{$school}/{$year}/Exams", $id);

// Push (auto-generate key)
$key = $this->firebase->push("System/Logs/Activity/{$date}", $entry);

// Check existence
if ($this->firebase->exists("Schools/{$school}/Config/Profile")) { ... }

// Shallow read (keys only — fast)
$classKeys = $this->firebase->shallow_get("Schools/{$school}/{$year}");
// Returns: ["Class 9th", "Class 10th", "Exams", "Teachers", ...]

// File upload
$this->firebase->uploadFile($localPath, "schools/{$school}/logos/logo.png");
$url = $this->firebase->getDownloadUrl("schools/{$school}/logos/logo.png");
```

### Exam_engine.php — Grading & Ranking

```php
// Initialize (once per request)
$this->load->library('Exam_engine');
$this->exam_engine->init($this->firebase, $this->school_name, $this->session_year);

// Grade computation
$grade = $this->exam_engine->compute_grade(85.5, 'Percentage');  // "A"
$grade = $this->exam_engine->compute_grade(85.5, 'A-F Grades');  // "B"
$pf    = $this->exam_engine->compute_pass_fail(35.0, 33);        // "Pass"

// Ranking (competition: 1,1,3)
$ranked = $this->exam_engine->assign_ranks($students, 'percentage');
// Input must be pre-sorted descending

// Class structure
$structure = $this->exam_engine->get_class_structure();
// Returns: ['Class 9th' => ['A','B'], 'Class 10th' => ['A']]

// Active exams (non-Draft, sorted by StartDate)
$exams = $this->exam_engine->get_active_exams();

// Student roster
$students = $this->exam_engine->get_student_names("Class 9th", "Section A");
// Returns: ['STU001' => 'Rahul', 'STU002' => 'Priya']

// Subject list
$subjects = $this->exam_engine->get_subject_list("Class 9th");
// Returns: ['Mathematics', 'English', 'Science']
```

### Operations_accounting.php — Shared Accounting

```php
// Initialize
$this->load->library('Operations_accounting');
$this->operations_accounting->init(
    $this->firebase, $this->school_name, $this->session_year,
    $this->admin_id, $this
);

// Sequential ID generation
$bookId = $this->operations_accounting->next_id(
    "Schools/{$school}/{$year}/Operations/Library/Counters/Book", "BK", 4
);  // "BK0001"

// Student search (5-min cache)
$results = $this->operations_accounting->search_students("Rahul", 20);

// Pagination
$page = $this->operations_accounting->paginate($items, 'books', 2, 50);
// Returns: {books: [...], page: 2, limit: 50, total: 120}

// Account validation
$this->operations_accounting->validate_accounts(['1010', '4060']);
// Exits with json_error if invalid

// Double-entry journal
$entryId = $this->operations_accounting->create_journal(
    "Library fine payment",
    [
        ['account_code' => '1010', 'dr' => 100, 'cr' => 0],   // Cash
        ['account_code' => '4080', 'dr' => 0, 'cr' => 100],   // Fine Income
    ],
    'Library', 'FN0042'
);

// Fee journal (shortcut)
$entryId = $this->operations_accounting->create_fee_journal([
    'school_name' => $this->school_name,
    'session_year' => $this->session_year,
    'date' => '2026-03-13',
    'amount' => 5000,
    'payment_mode' => 'Cash',
    'receipt_no' => 'REC00123',
    'student_name' => 'Rahul',
    'student_id' => 'STU001',
    'class' => '9th',
    'admin_id' => $this->admin_id,
]);
```

### Communication_helper.php — Event Triggers

```php
// Initialize
$this->load->library('Communication_helper');
$this->communication_helper->init(
    $this->firebase, $this->school_name, $this->session_year
);

// Fire event (matches triggers, queues messages)
$queued = $this->communication_helper->fire_event('fee_received', [
    'student_id'   => 'STU001',
    'student_name' => 'Rahul',
    'amount'       => 5000,
    'class'        => '9th A',
    'receipt_no'   => 'REC00123',
]);

// Bulk event (e.g., exam results for entire class)
$queued = $this->communication_helper->fire_event_bulk('exam_result', [
    ['student_id' => 'STU001', 'student_name' => 'Rahul', 'grade' => 'A'],
    ['student_id' => 'STU002', 'student_name' => 'Priya', 'grade' => 'B+'],
]);

// Allowed event types:
// student_absent, student_late, low_attendance
// fee_due, fee_overdue, fee_received
// exam_result, exam_schedule
// admission_approved, admission_rejected
// salary_processed, leave_approved
// event_created, event_updated
```

## 3.3 The shallow_get() Pattern

Use when building class/section lists from the session node:

```php
$sessionRoot = "Schools/{$this->school_name}/{$this->session_year}";
$topKeys = $this->firebase->shallow_get($sessionRoot);

foreach ($topKeys as $classKey) {
    if (strpos($classKey, 'Class ') !== 0) continue;

    $sectionKeys = $this->firebase->shallow_get("{$sessionRoot}/{$classKey}");
    foreach ($sectionKeys as $sectionKey) {
        if (strpos($sectionKey, 'Section ') !== 0) continue;
        $letter = str_replace('Section ', '', $sectionKey);
        // Use $classKey ("Class 9th") and $letter ("A")
    }
}
```

This avoids fetching full subtrees — only child keys are returned.

## 3.4 RBAC Role Hierarchy

```
Super Admin    → Full access to everything (always passes _require_role)
Principal      → Full school access, admin management
Vice Principal → School access except admin management
Admin          → Standard school operations
Teacher        → Only assigned classes/sections/subjects
Accountant     → Financial modules only
```

Usage in controllers:

```php
// Only Super Admin and Admin can access
$this->_require_role(['Super Admin', 'Admin'], 'create exam');

// Check if teacher can access specific class
if (!$this->_teacher_can_access('Class 9th', 'Section A', 'Mathematics')) {
    $this->json_error('Not authorized for this class', 403);
}
```

## 3.5 CSS Theme System

All views use CSS custom properties for theming:

```css
/* Global variables (set in header.php) */
:root {
    --gold: #0f766e;         /* Primary teal */
    --gold2: #0d6b63;        /* Darker teal */
    --gold3: #14b8a6;        /* Lighter teal */
    --amber: #d97706;        /* Warning/accent */
    --bg: #f0f7f5;           /* Page background (day) */
    --bg2: #ffffff;          /* Card background */
    --bg3: #e6f4f1;          /* Card header */
    --bg4: #cce9e4;          /* Highlighted areas */
    --t1: #1e293b;           /* Primary text */
    --t2: #475569;           /* Secondary text */
    --t3: #94a3b8;           /* Muted text */
    --border: #e2e8f0;       /* Borders */
    --sh: 0 1px 3px rgba(0,0,0,.06);  /* Shadows */
}

/* Night mode overrides (toggled by JS) */
body.dark-mode {
    --bg: #070f1c;
    --bg2: #0c1e38;
    --bg3: #0f2545;
    --t1: #e2e8f0;
    --t2: #94a3b8;
    --t3: #64748b;
}
```

Per-view local tokens map to global vars with fallbacks:

```css
/* Example: fees_counter.php */
:root {
    --fc-teal:   var(--gold, #0f766e);
    --fc-bg:     var(--bg, #f4f6f9);
    --fc-card:   var(--bg2, #ffffff);
    --fc-border: var(--border, #e2e8f0);
    --fc-text:   var(--t1, #1e293b);
}
```

### Font Size Scale (rem-based)

| Element | Size |
|---------|------|
| Page title | 1.35rem |
| Stat value | 1.25rem |
| Card heading | .88-.92rem |
| Body text | .8-.82rem |
| Breadcrumb | .78rem |
| Label | .68-.72rem |
| Badge | .68-.7rem |

---

# 4. API Documentation

## 4.1 API Conventions

### Request Format
- All AJAX endpoints accept **POST** with `Content-Type: application/x-www-form-urlencoded` (jQuery default)
- CSRF token sent via `X-CSRF-Token` header or `csrf_token` POST field
- Arrays sent as `key[]` (jQuery serialization) or JSON string

### Response Format

```json
// Success
{"status": "success", "key1": "value1", "key2": "value2"}

// Error
{"status": "error", "message": "Human-readable error message"}
```

HTTP status codes: 200 (success), 400 (validation), 401 (auth), 403 (CSRF/RBAC), 404 (not found), 500 (server)

### CSRF Token
```javascript
// In AJAX setup (header.php provides this)
$.ajaxSetup({
    headers: { 'X-CSRF-Token': '<?= $this->security->get_csrf_hash() ?>' }
});
```

## 4.2 Authentication API

### POST `/admin_login/check_credentials`
Login and create session.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| admin_id | string | Yes | Admin user ID |
| school_id | string | Yes | School login code |
| password | string | Yes | Password |

**Response:** Redirect to `admin/index` on success, back to login with error on failure.

**Security:** IP rate limit (20 fails/15 min), account lockout (5 fails/30 min), subscription check.

### GET `/admin_login/get_server_date`
Returns server date.

**Response:** `{"date": "13-03-2026"}`

---

## 4.3 Student Management API

### POST `/fees/lookup_student`
Look up student by ID.

| Parameter | Type | Required |
|-----------|------|----------|
| user_id | string | Yes |

**Response:** `{"user_id": "STU001", "name": "Rahul", "father_name": "Mr. Kumar", "class": "9th 'A'"}`

### POST `/fees/search_student`
Search students by name.

| Parameter | Type | Required |
|-----------|------|----------|
| search_name | string | Yes (min 2 chars) |

**Response:** `[{"userId": "STU001", "name": "Rahul", "class": "9th"}, ...]`

### POST `/sis/search_student`
Search in SIS module.

| Parameter | Type | Required |
|-----------|------|----------|
| query | string | Yes (min 2 chars) |

**Response:** `{"students": [{"id": "STU001", "name": "Rahul", "class": "9th", "section": "A"}, ...]}`

---

## 4.4 Exam & Result API

### POST `/result/save_template`
Save exam template (grade components per subject).

| Parameter | Type | Required |
|-----------|------|----------|
| examId | string | Yes |
| classKey | string | Yes |
| sectionKey | string | Yes |
| subject | string | Yes |
| components | JSON | Yes |

**Roles:** Super Admin, Admin

### POST `/result/save_marks`
Save student marks for a subject.

| Parameter | Type | Required |
|-----------|------|----------|
| examId | string | Yes |
| classKey | string | Yes |
| sectionKey | string | Yes |
| subject | string | Yes |
| marks | JSON | Yes — `{userId: {componentName: marks}}` |

### POST `/result/compute_results`
Compute grades, percentages, and ranks for a class.

| Parameter | Type | Required |
|-----------|------|----------|
| examId | string | Yes |
| classKey | string | Yes |
| sectionKey | string | Yes |

**Response:** `{"status": "success", "computed_count": 45}`

### POST `/examination/bulk_compute`
Compute all classes/sections for an exam.

| Parameter | Type | Required |
|-----------|------|----------|
| examId | string | Yes |

**Roles:** Super Admin, Admin

**Response:** `{"status": "success", "total_computed": 450}`

### POST `/examination/get_merit_data`
Get merit list (toppers).

| Parameter | Type | Required |
|-----------|------|----------|
| examId | string | Yes |
| classKey | string | Yes |
| sectionKey | string | Yes |

**Response:** `{"class_merit": [...], "subject_merit": {...}}`

---

## 4.5 Fees API

### POST `/fees/fetch_months`
Get month-wise payment status.

| Parameter | Type | Required |
|-----------|------|----------|
| user_id | string | Yes |

**Response:** `{"April": 0, "May": 1, "June": 0, ...}` (0=unpaid, 1=paid)

### POST `/fees/submit_fees`
Submit fee payment.

| Parameter | Type | Required |
|-----------|------|----------|
| userId | string | Yes |
| class | string | Yes — format: `"9th 'A'"` |
| paymentMode | string | Yes — Cash/Cheque/Online/DD |
| schoolFees | number | Yes |
| hostelFees | number | No |
| transportFees | number | No |
| otherFees | number | No |
| date | string | Yes — d-m-Y |

**Response:** `{"status": "success", "receiptNo": "REC00123"}`

### GET `/fees/get_receipt_no`
Get next receipt number.

**Response:** `{"receiptNo": "REC00123"}`

---

## 4.6 Attendance API

### POST `/attendance/save_student`
Save student attendance.

| Parameter | Type | Required |
|-----------|------|----------|
| classKey | string | Yes |
| month | string | Yes |
| attendance | JSON | Yes — `{studentId: "PPAP..."}` |

### POST `/attendance/api_punch`
Biometric device punch (API key auth, no session).

| Parameter | Type | Required |
|-----------|------|----------|
| device_id | string | Yes |
| user_id | string | Yes |
| timestamp | string | Yes |

**Auth:** API key in `X-API-Key` header.

---

## 4.7 Communication API

### POST `/communication/send_message`
Send message to recipients.

| Parameter | Type | Required |
|-----------|------|----------|
| to_role | string | Yes — parent/student/teacher |
| to_ids | array | Yes |
| message | string | Yes |
| channels | array | Yes — push/sms/email/in_app |

**Roles:** Admin roles only.

### POST `/communication/create_notice`
Create notice (dual-write to legacy path).

| Parameter | Type | Required |
|-----------|------|----------|
| title | string | Yes |
| body | string | Yes |
| category | string | Yes — General/Academic/Event/Administrative/Emergency |
| recipients_type | string | Yes — parent/student/teacher/broadcast |
| priority | string | No — High/Normal/Low |

---

## 4.8 HR & Payroll API

### POST `/hr/generate_payroll`
Auto-calculate payroll for month.

| Parameter | Type | Required |
|-----------|------|----------|
| month | string | Yes |
| year | string | Yes |

**Roles:** Super Admin, Principal, Accountant

**Response:** `{"status": "success", "generated_count": 25, "total_amount": 750000}`

### POST `/hr/submit_leave_request`
Submit leave request.

| Parameter | Type | Required |
|-----------|------|----------|
| leave_type | string | Yes |
| from_date | string | Yes |
| to_date | string | Yes |
| reason | string | Yes |

---

## 4.9 School Configuration API

### POST `/school_config/get_config`
Get all configuration in one call.

**Response:**
```json
{
    "profile": {"name": "...", "address": "..."},
    "board": {"name": "CBSE", "grade_scales": [...]},
    "classes": [{"name": "9th"}, ...],
    "streams": [...],
    "sessions": [...],
    "active_session": "2025-2026"
}
```

### POST `/school_config/save_section`
Add section to class.

| Parameter | Type | Required |
|-----------|------|----------|
| class_key | string | Yes — e.g., "Class 9th" |
| section_letter | string | Yes — e.g., "A" |

### POST `/school_config/delete_section`
Delete section (refuses if students enrolled).

| Parameter | Type | Required |
|-----------|------|----------|
| class_key | string | Yes |
| section_letter | string | Yes |

---

## 4.10 Super Admin API

### POST `/superadmin/schools/onboard`
Onboard new school.

| Parameter | Type | Required |
|-----------|------|----------|
| school_name | string | Yes |
| school_code | string | Yes |
| admin_name | string | Yes |
| admin_email | string | Yes |
| admin_password | string | Yes |
| board | string | Yes |
| address | string | No |
| city | string | No |
| phone | string | No |

**Auth:** Super Admin session (MY_Superadmin_Controller).

**Response:** `{"status": "success", "school_id": "SCH_000042"}`

### POST `/superadmin/debug/toggle_debug`
Enable/disable debug mode.

**Response:** `{"status": "success", "debug_enabled": true}`

---

# 5. Deployment Guide

## 5.1 Prerequisites

- PHP 7.4+ with extensions: `curl`, `mbstring`, `openssl`, `json`, `gd`
- Apache 2.4+ with `mod_rewrite` enabled
- Composer (for Kreait Firebase SDK)
- Firebase project with Realtime Database and Storage
- Firebase service account JSON key file

## 5.2 Initial Setup

### Step 1: Clone Repository
```bash
git clone https://github.com/ankitprajapati8134/SchoolX.git school
cd school
```

### Step 2: Install Dependencies
```bash
composer install
```

### Step 3: Firebase Configuration

Place your Firebase service account JSON in:
```
application/config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json
```

Verify database URI in `application/libraries/Firebase.php`:
```php
$this->database = $factory->withDatabaseUri(
    'https://YOUR-PROJECT.firebasedatabase.app/'
)->createDatabase();
```

### Step 4: Application Config

Copy and edit `application/config/config.php`:

```php
// Set your base URL
$config['base_url'] = 'https://yourdomain.com/school/';

// Generate a new encryption key
$config['encryption_key'] = bin2hex(random_bytes(32));

// Enable HTTPS cookies for production
$config['cookie_secure'] = TRUE;

// Session settings
$config['sess_driver'] = 'files';
$config['sess_cookie_name'] = 'grader_session';
$config['sess_expiration'] = 7200;
```

### Step 5: Apache Configuration

Ensure `.htaccess` is in place:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php/$1 [L]
```

### Step 6: Directory Permissions

```bash
chmod 755 application/logs/
chmod 755 application/cache/
chmod 755 uploads/
```

### Step 7: Create First Super Admin

Use the Super Admin panel at `/superadmin/login` or manually create an entry:

```
Users/Admin/{school_code}/{admin_id}:
  name: "Admin Name"
  email: "admin@example.com"
  password: (bcrypt hash)
  Role: "Super Admin"
  Active: true
```

## 5.3 Environment-Specific Settings

### Development
```php
$config['log_threshold'] = 4;          // Log everything
$config['cookie_secure'] = FALSE;      // No HTTPS locally
// Enable debug: touch application/logs/.debug_enabled
```

### Production
```php
$config['log_threshold'] = 1;          // Errors only
$config['cookie_secure'] = TRUE;       // Require HTTPS
$config['sess_cookie_name'] = 'grader_session';
$config['sess_match_ip'] = TRUE;       // Optional: IP binding
// Disable debug: rm application/logs/.debug_enabled
```

## 5.4 Firebase Security Rules

Recommended rules (restrict direct client access):

```json
{
  "rules": {
    "System": {
      ".read": false,
      ".write": false
    },
    "Schools": {
      "$school_id": {
        ".read": "auth != null && root.child('Indexes/School_codes').child(auth.token.school_code).val() === $school_id",
        ".write": false
      }
    },
    "Users": {
      ".read": false,
      ".write": false
    },
    "Indexes": {
      ".read": false,
      ".write": false
    }
  }
}
```

> **Note:** The Admin SDK bypasses security rules. These rules protect against direct client (mobile app) access.

---

# 6. Maintenance Guide

## 6.1 Debug Mode

Toggle debug mode via Super Admin panel or manually:

```bash
# Enable
touch application/logs/.debug_enabled

# Disable
rm application/logs/.debug_enabled
```

When enabled, every Firebase operation is logged to `application/logs/debug_YYYY-MM-DD.log` in JSONL format. Operations ≥500ms are flagged as `SLOW_OP`.

### Debug Panel (Super Admin)
Access at `/superadmin/debug` — 6 tabs:
- **Requests** — HTTP request log with controller/method/IP
- **Firebase Ops** — Every read/write with path, duration, result size
- **Schema Issues** — Missing fields, wrong types in Firebase data
- **Security** — Unauthorized access attempts
- **Performance** — Slow operations (≥500ms)
- **Live Schema Check** — On-demand schema validation

## 6.2 Health Checks

Run at `/health_check/run_all` — 16 validation checks:
- Firebase connectivity
- Session configuration
- CSRF settings
- File permissions
- Required library availability
- Schema validation for key paths

## 6.3 Backup & Restore

### Manual Backup (Super Admin)
1. Navigate to `/superadmin/backups`
2. Click "Create Backup"
3. Select school(s) to backup
4. Download backup file

### Automated Backups
Configure at `/superadmin/backups` → Schedule tab:
- Frequency: Daily/Weekly/Monthly
- Retention: Number of days to keep
- Cron key: Used by `backup_cron/run/{key}` endpoint

### Cron Setup
```bash
# Daily backup at 2 AM
0 2 * * * curl -s https://yourdomain.com/school/backup_cron/run/YOUR_CRON_KEY
```

## 6.4 Log Management

### Application Logs
```
application/logs/log-YYYY-MM-DD.php    ← CI3 error logs
application/logs/debug_YYYY-MM-DD.log  ← Debug JSONL (when enabled)
```

### Log Rotation
Debug logs can be cleared via Super Admin panel or manually:
```bash
find application/logs/ -name "debug_*.log" -mtime +30 -delete
```

## 6.5 Session Management

Sessions are file-based (`sess_driver: files`). Clean stale sessions:
```bash
find /tmp -name "ci_session*" -mtime +1 -delete
```

For production, consider switching to Redis or database driver.

## 6.6 Common Troubleshooting

| Issue | Cause | Fix |
|-------|-------|-----|
| 403 on all POST | CSRF token mismatch | Clear cookies, reload page |
| Blank page | PHP error with display_errors off | Check `application/logs/` |
| Firebase timeout | Network/region issue | Check Firebase status page |
| Login loop | Session not persisting | Check `sess_save_path` permissions |
| Missing classes | Wrong session selected | Switch session via Admin panel |
| "Subscription expired" | Grace period ended | Renew via Super Admin panel |

## 6.7 Subscription Management

The system checks subscription every 5 minutes:
1. **Active** → Normal operation
2. **Grace Period** → Warning banner, 7-day window
3. **Expired** → Force logout
4. **Firebase unreachable** → Skip check (graceful degradation)

Manage via `/superadmin/plans` → Subscriptions tab.

---

# 7. Future Development Guidelines

## 7.1 Creating a New Module

### Step 1: Create Controller
```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class NewModule extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // Load on-demand libraries if needed
        // $this->load->library('Operations_accounting');
    }

    public function index() {
        $this->load->view('include/header');
        $this->load->view('new_module/index');
        $this->load->view('include/footer');
    }

    public function get_data() {
        $this->_require_role(['Super Admin', 'Admin']);

        $path = "Schools/{$this->school_name}/{$this->session_year}/NewModule";
        $data = $this->firebase->get($path);

        $this->json_success(['items' => $data ?: []]);
    }

    public function save_item() {
        $this->_require_role(['Super Admin', 'Admin']);

        $name = $this->input->post('name');
        if (empty($name)) {
            $this->json_error('Name is required');
        }

        $path = "Schools/{$this->school_name}/{$this->session_year}/NewModule";
        $id = uniqid('NM_');

        $this->firebase->set("{$path}/{$id}", [
            'name' => htmlspecialchars($name),
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $this->admin_id,
        ]);

        $this->json_success(['id' => $id]);
    }
}
```

### Step 2: Create View
```php
<!-- application/views/new_module/index.php -->
<style>
:root {
    --nm-teal:   var(--gold, #0f766e);
    --nm-bg:     var(--bg, #f4f6f9);
    --nm-card:   var(--bg2, #ffffff);
    --nm-border: var(--border, #e2e8f0);
    --nm-text:   var(--t1, #1e293b);
}
</style>

<div class="content-wrapper">
    <section class="content-header">
        <h1 style="font-size:1.35rem">New Module</h1>
    </section>
    <section class="content">
        <!-- Your content here -->
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // jQuery is loaded in footer
    loadData();
});

function loadData() {
    $.post('<?= base_url("new_module/get_data") ?>', function(res) {
        if (res.status === 'success') {
            // Render data
        }
    }, 'json');
}
</script>
```

### Step 3: Add Routes
```php
// In application/config/routes.php
$route['new_module']           = 'NewModule/index';
$route['new_module/get_data']  = 'NewModule/get_data';
$route['new_module/save_item'] = 'NewModule/save_item';
```

### Step 4: Add to Sidebar
In `application/views/include/header.php`, add a treeview entry:
```html
<li class="treeview">
    <a href="#"><i class="fa fa-cube"></i><span>New Module</span>
        <span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span>
    </a>
    <ul class="treeview-menu">
        <li><a href="<?= base_url('new_module') ?>"><i class="fa fa-circle-o"></i> Dashboard</a></li>
    </ul>
</li>
```

## 7.2 Coding Standards

### Controller Methods
- Page loads: load `include/header` + view + `include/footer`
- AJAX endpoints: return via `$this->json_success()` or `$this->json_error()`
- Always validate input with `$this->input->post()` (auto-XSS filtered)
- Use `$this->_require_role()` for access control
- Use `$this->safe_path_segment()` for any user input going into Firebase paths

### Firebase Paths
- Use `$this->school_name` (= `SCH_XXXXXX`) for school scope
- Use `$this->session_year` for session scope
- Use `shallow_get()` for class/section enumeration
- Never concatenate user input directly into paths — always validate

### Views
- Use `--module-*` CSS variable tokens mapped to global vars with fallbacks
- Use rem-based font sizes (see scale in Section 3.5)
- Wrap JS in `document.addEventListener('DOMContentLoaded', ...)` — jQuery loads in footer
- Use `<?= base_url('route') ?>` for all URLs

### Error Handling
```php
// In controllers
try {
    $result = $this->firebase->set($path, $data);
    if (!$result) {
        $this->json_error('Failed to save data');
    }
    $this->json_success(['id' => $id]);
} catch (Exception $e) {
    log_message('error', "NewModule::save_item - " . $e->getMessage());
    $this->json_error('An unexpected error occurred');
}
```

## 7.3 Firebase Path Conventions

```
Session-scoped:    Schools/{school_id}/{session_year}/ModuleName/...
School-scoped:     Schools/{school_id}/ModuleName/...
System-scoped:     System/ModuleName/...
User-scoped:       Users/{role}/{school_id}/{user_id}/...
```

### DO:
- Use `shallow_get()` for listing keys
- Batch reads when possible (read parent node, filter in PHP)
- Use counters for sequential IDs (`next_id()` from Operations_accounting)
- Use `htmlspecialchars()` on all user input before storing

### DON'T:
- Don't read `/Classes` sub-node — classes live directly as `Class 9th` under session root
- Don't use `firebase->get()` in loops — read the parent node once
- Don't store computed data that can be derived (except for performance-critical paths like `Results/Computed`)
- Don't hardcode colors in views — use CSS variables

## 7.4 Adding Accounting Integration

If your module generates financial transactions, integrate with the accounting system:

```php
$this->load->library('Operations_accounting');
$this->operations_accounting->init(
    $this->firebase, $this->school_name,
    $this->session_year, $this->admin_id, $this
);

// Validate accounts exist
$this->operations_accounting->validate_accounts(['1010', '4080']);

// Create journal entry
$entryId = $this->operations_accounting->create_journal(
    "Description of transaction",
    [
        ['account_code' => '1010', 'dr' => $amount, 'cr' => 0],  // Debit Cash
        ['account_code' => '4080', 'dr' => 0, 'cr' => $amount],  // Credit Income
    ],
    'ModuleName',     // source
    'REF001'          // source reference ID
);
```

## 7.5 Adding Communication Triggers

If your module should send automated notifications:

```php
$this->load->library('Communication_helper');
$this->communication_helper->init(
    $this->firebase, $this->school_name, $this->session_year
);

$this->communication_helper->fire_event('your_event_type', [
    'student_id'   => $studentId,
    'student_name' => $studentName,
    // ... template variables
]);
```

> **Note:** Your event type must be added to `ALLOWED_EVENTS` in `Communication_helper.php`.

## 7.6 Testing

### Health Check Integration
Add validation checks to `Health_check.php` for your module:

```php
// In Health_check.php
private function check_new_module() {
    $path = "Schools/{$this->school_name}/{$this->session_year}/NewModule";
    $data = $this->firebase->shallow_get($path);
    return [
        'name' => 'NewModule Data',
        'status' => !empty($data) ? 'pass' : 'warn',
        'message' => !empty($data) ? 'Data exists' : 'No data found',
    ];
}
```

### Manual Testing Checklist
- [ ] Page loads correctly (no JS console errors)
- [ ] AJAX calls return proper JSON
- [ ] CSRF token is sent with all POST requests
- [ ] Role restrictions work (test with Teacher role)
- [ ] Data persists correctly in Firebase
- [ ] Day/night theme toggle works
- [ ] Session switching shows correct data

## 7.7 Performance Guidelines

| Rule | Why |
|------|-----|
| Use `shallow_get()` for class/section lists | Avoids fetching entire subtrees |
| Read parent nodes, filter in PHP | Eliminates N+1 Firebase reads |
| Cache frequently-read data | Use CI file cache with 5-min TTL |
| Limit results | Firebase has no server-side pagination |
| Keep views under 50KB | Large inline CSS/JS impacts load time |
| Debounce AJAX search inputs | Reduce Firebase reads on keystroke |

## 7.8 Security Checklist for New Features

- [ ] All user input validated before use
- [ ] Firebase paths sanitized via `safe_path_segment()`
- [ ] RBAC checks on all write operations
- [ ] No raw `<?= $var ?>` — use `htmlspecialchars()`
- [ ] POST-only for mutations (no GET side effects)
- [ ] Error messages don't leak internal paths or data
- [ ] File uploads validated (type, size, extension)

## 7.9 Recommended Architecture Improvements

| Priority | Improvement | Impact |
|----------|------------|--------|
| **High** | Add Redis caching | Reduce Firebase reads by 60-80% |
| **High** | Add automated tests | Catch regressions early |
| **High** | Enable HTTPS + secure cookies | Production security |
| **Medium** | Add CDN for static assets | Faster page loads |
| **Medium** | Extract API layer for mobile | Proper auth + validation |
| **Medium** | Add CI/CD pipeline | Automated deployment |
| **Low** | Migrate hot paths to Firestore | Better querying for reports |
| **Low** | Add WebSocket for real-time updates | Live dashboards |
| **Low** | Containerize with Docker | Consistent environments |

---

*Document generated: 2026-03-13*
*Codebase: 48 controllers | 164 views | 4 libraries | 300+ routes*
