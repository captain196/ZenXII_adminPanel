# Grader School ERP — Technical Project Report & Architecture Documentation

**Document Version:** 1.0
**Date:** 2026-03-16
**Platform:** Grader School ERP (Multi-Tenant SaaS)
**Classification:** Technical Reference — Project Submission

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Technology Stack](#2-technology-stack)
3. [System Architecture](#3-system-architecture)
4. [Module Breakdown](#4-module-breakdown)
5. [Database Structure](#5-database-structure)
6. [Security Analysis](#6-security-analysis)
7. [Performance & Scalability](#7-performance--scalability)
8. [Logging & Audit System](#8-logging--audit-system)
9. [Code Quality Review](#9-code-quality-review)
10. [Suggested Improvements](#10-suggested-improvements)
11. [Deployment Guide](#11-deployment-guide)
12. [Future Enhancements](#12-future-enhancements)

---

# 1. Project Overview

## 1.1 Project Identity

| Attribute | Detail |
|-----------|--------|
| **Project Name** | Grader School ERP |
| **System Type** | Multi-Tenant SaaS School Management Platform |
| **Architecture** | Server-rendered MVC with AJAX-driven SPA modules |
| **Codebase Size** | 43 Controllers, 148 Views, 668 Routes, ~30,000+ Lines of PHP |
| **Database** | Firebase Realtime Database (NoSQL, cloud-hosted) |
| **Hosting** | Apache (XAMPP) / PHP 7.4+ |

## 1.2 Purpose

Grader School ERP is a comprehensive, cloud-based school management platform designed for K-12 institutions. It digitises every operational workflow — from student admission and academic management to financial accounting, HR payroll, and facility operations — under a single, unified interface. The system operates as a multi-tenant SaaS, where each school is an isolated tenant sharing common infrastructure but maintaining strict data separation.

## 1.3 Target Users

| User Role | Access Level | Description |
|-----------|-------------|-------------|
| **Super Admin** | Platform-wide | SaaS operator managing all schools, plans, subscriptions, and system health |
| **School Admin** | School-scoped | School administrator with full control over their institution's data |
| **Principal** | School-scoped | Senior authority with management-level access across all modules |
| **Teacher** | Class-scoped | Marks attendance, enters exam results, views assigned classes only |
| **Accountant** | Finance-scoped | Manages fees, vouchers, accounting ledger, and financial reports |
| **HR Manager** | HR-scoped | Handles departments, recruitment, leave, payroll operations |
| **Operations Manager** | Operations-scoped | Manages library, transport, hostel, inventory, and assets |
| **Parents** | Read-only (via app) | View child's attendance, results, fees, notices (mobile/web) |

## 1.4 Main Capabilities

The system provides 18+ integrated modules covering the complete school operational lifecycle:

- **Student Information System (SIS):** Admission CRM, enrollment, profiles, promotion, transfer certificates, ID cards, document management, and student history tracking
- **Academic Management:** Curriculum planning, subject assignments, timetable generation, academic calendar, and teacher duty allocation
- **Examination & Results:** Exam creation, template-based grading, marks entry, result computation, report cards, merit lists, analytics dashboards, tabulation sheets, and cumulative results
- **Attendance System:** Student/staff marking, biometric device integration (RFID, face recognition), late tracking, punch logs, and attendance analytics
- **Fees & Accounting:** Fee counter, fee structure management, receipt generation, double-entry accounting with chart of accounts, journal entries, bank reconciliation, and financial reports (P&L, balance sheet, cash flow)
- **HR & Payroll:** Department management, job recruitment pipeline, leave management (with LWP), salary structures, payroll runs, payslip generation, appraisal cycles, and accounting journal integration
- **Communication:** Direct messaging, notice board, circulars, message templates, event-driven triggers, delivery queue, and audit logs
- **Operations:** Library (catalog, issue/return, fines), Transport (vehicles, routes, assignments), Hostel (buildings, rooms, attendance), Inventory (stock, purchases, issues), Assets (registry, depreciation, maintenance)
- **School Configuration:** Profile, sessions, board/grading system, class/section management, subject catalog, and stream configuration
- **Certificates:** Template designer, dynamic field mapping, bulk generation, and issued certificate tracking
- **Backup Management:** Automated daily backups, manual backup creation, retention policies, and file downloads for both superadmin and school-level users
- **Super Admin Panel:** School onboarding, subscription plans, payment tracking, system monitoring, migration tools, debug panel, and global reports

---

# 2. Technology Stack

## 2.1 Backend

| Component | Technology | Version | Purpose |
|-----------|-----------|---------|---------|
| **Framework** | CodeIgniter | 3.1.13 | PHP MVC framework providing routing, input handling, security, and session management |
| **Language** | PHP | 7.4+ (compatible to 8.x) | Server-side scripting |
| **Firebase SDK** | Kreait Firebase Admin SDK | Latest (Composer) | Authenticated server-side access to Firebase Realtime Database and Cloud Storage |
| **Package Manager** | Composer | 2.x | PHP dependency management (Kreait SDK, Dotenv) |
| **Environment** | Dotenv | 5.x | Environment variable management via `.env` file |

**Server Requirements:**
- Apache 2.4+ with `mod_rewrite` enabled (clean URLs)
- PHP 7.4 or higher (bcrypt, JSON, cURL, mbstring, zip extensions)
- Composer installed globally
- Minimum 256 MB PHP memory limit (for backup operations)
- SSL certificate recommended for production

## 2.2 Frontend

| Component | Technology | Version | Purpose |
|-----------|-----------|---------|---------|
| **CSS Framework** | Bootstrap | 3.3.x | Responsive grid system and UI components |
| **Admin Template** | AdminLTE | 2.x | Dashboard layout, sidebar, top navigation |
| **JavaScript** | jQuery | 3.x | DOM manipulation, AJAX, event handling |
| **Data Tables** | DataTables | 2.0.8 | Sortable, searchable, exportable tables with server-side processing |
| **Charts** | Chart.js | 4.4.x | Grade distribution, attendance trends, financial charts |
| **Charts (Legacy)** | Morris.js + Raphael | — | Area/line/bar charts (legacy dashboard) |
| **Date Handling** | Moment.js | — | Date parsing and formatting |
| **Date Pickers** | Bootstrap DatePicker / DateRangePicker | — | Calendar-based date selection |
| **PDF Export** | pdfMake | 0.2.7 | Client-side PDF generation for DataTable exports |
| **Excel Export** | jsZip | 3.10.1 | Client-side XLSX generation for DataTable exports |
| **Fonts** | Google Fonts | — | Plus Jakarta Sans (body), Syne (headings), JetBrains Mono (code) |
| **Icons** | Font Awesome | 4.x + 6.5 | UI icons throughout the application |
| **Icons (Secondary)** | Ionicons | — | Supplementary icon set |
| **Rich Text** | CKEditor | — | Rich text editing for notices, circulars, templates |
| **Calendar** | FullCalendar | — | Academic calendar and event scheduling |
| **Dropdowns** | Select2 | — | Enhanced searchable dropdown selectors |

**UI Architecture:**
- **Hybrid SPA/Multi-Page:** Complex modules (HR, Communication, SIS, Attendance) use single-page tab-based architecture with AJAX content loading. Simple modules use traditional multi-page navigation.
- **Theme System:** CSS custom properties (variables) for teal/navy design system with full day/night mode toggle. Theme preference persisted in `localStorage`.
- **Responsive Design:** Three breakpoints — Desktop (1020px+), Tablet (768–1019px), Mobile (<768px). Sidebar collapses to icon-only on mobile.

## 2.3 Database

| Attribute | Detail |
|-----------|--------|
| **Primary Database** | Firebase Realtime Database (NoSQL, cloud-hosted) |
| **Secondary Database** | MySQL (via CI3 Active Record — minimal usage, legacy support) |
| **Cloud Storage** | Firebase Cloud Storage (student photos, documents, logos, gallery media) |
| **Data Format** | JSON tree structure |
| **Data Access** | Kreait Admin SDK (authenticated, server-side only) |
| **Region** | Asia Southeast 1 (`asia-southeast1.firebasedatabase.app`) |

**Data Storage Pattern:**
- Multi-tenant tree: Each school's data lives under `Schools/{school_id}/` with strict isolation
- Session-scoped academic data: `Schools/{school}/{session_year}/Class 9th/Section A/...`
- Denormalized reads: Data structured for read-efficiency, not write-efficiency
- Push-key IDs: Firebase auto-generated unique keys for records like messages, audit logs
- Counter nodes: Sequential IDs for receipts, TC numbers, vouchers

## 2.4 Infrastructure

| Component | Requirement |
|-----------|------------|
| **Web Server** | Apache 2.4+ with `mod_rewrite` |
| **PHP Version** | 7.4+ (8.0+ recommended) |
| **Operating System** | Windows (XAMPP) or Linux (production) |
| **Firebase Project** | Active Firebase project with Realtime Database + Cloud Storage |
| **Service Account** | Firebase Admin SDK service account JSON key file |
| **SSL** | Required for production (HTTPS) |
| **Cron** | System cron for automated backups (daily schedule) |
| **Memory** | 256 MB+ PHP memory limit |
| **Disk Space** | 500 MB+ for application + backups |

---

# 3. System Architecture

## 3.1 Application Architecture Style

Grader School ERP follows a **Server-Rendered MVC with AJAX Enhancement** pattern:

- **Model-View-Controller (MVC):** CodeIgniter 3's standard MVC separates data access (Firebase library), presentation (PHP views), and business logic (controllers).
- **AJAX-Driven Modules:** Complex modules operate as Single-Page Applications (SPAs) within the MVC shell — a single controller method loads the page, then all data operations happen via AJAX endpoints returning JSON.
- **Multi-Tenant Isolation:** Every request is scoped to a single school via session-bound `school_name`. Firebase paths are always prefixed with the authenticated school's identifier.

## 3.2 MVC Structure

```
application/
├── config/              # Framework configuration
│   ├── config.php       # Base URL, CSRF, sessions, environment
│   ├── routes.php       # 668 URL → controller mappings
│   ├── constants.php    # GRADER_DEBUG flag, file modes
│   ├── hooks.php        # Debug logger hooks (pre/post request)
│   └── autoload.php     # Auto-loaded libraries, helpers, models
│
├── core/                # Extended base controllers
│   ├── MY_Controller.php              # School admin auth, RBAC, Firebase, CSRF
│   └── MY_Superadmin_Controller.php   # SA panel auth, session CSRF, activity logging
│
├── controllers/         # 43 controllers (business logic)
│   ├── Admin_login.php  # Authentication (extends CI_Controller)
│   ├── Admin.php        # Dashboard + admin CRUD (extends MY_Controller)
│   ├── Sis.php          # Student Information System
│   ├── Hr.php           # HR & Payroll (~2650 lines)
│   ├── Communication.php # Messaging + notices (~1341 lines)
│   ├── Attendance.php   # Student/staff attendance (~1500 lines)
│   ├── Result.php       # Marks, grades, report cards (~1200 lines)
│   ├── Accounting.php   # Double-entry accounting (~1500 lines)
│   ├── Superadmin*.php  # 8 SA panel controllers
│   └── ...              # 33 more controllers
│
├── models/              # Data access layer
│   ├── Common_model.php      # Firebase CRUD + Storage (alias: $this->CM)
│   └── Common_sql_model.php  # MySQL fallback (alias: $this->SM)
│
├── libraries/           # Shared service libraries
│   ├── Firebase.php              # Kreait SDK wrapper (14 methods)
│   ├── Backup_service.php        # Shared backup engine
│   ├── Debug_tracker.php         # Performance + operation tracking
│   ├── Communication_helper.php  # Event-driven messaging
│   └── Operations_accounting.php # Journal entry helper
│
├── helpers/             # Utility functions
│   ├── rbac_helper.php       # Role permission loader
│   ├── audit_helper.php      # Audit log writer
│   └── progress_bar_helper.php
│
├── hooks/               # Request lifecycle hooks
│   └── Debug_logger.php # Pre/post request debug logging
│
└── views/               # 148 PHP view files across 39 directories
    ├── include/
    │   ├── header.php   # Layout shell, sidebar, theme, CSRF meta tags
    │   └── footer.php   # JS library loading, DataTable init
    ├── sis/             # 10 view files
    ├── result/          # 9 view files
    ├── communication/   # 8 view files
    ├── superadmin/      # SA panel views (sub-directories)
    └── ...              # 35+ more view directories
```

## 3.3 Request Flow

```
Browser Request
      │
      ▼
  Apache (.htaccess rewrite)
      │
      ▼
  index.php (front controller)
  ├── Load Composer autoload
  ├── Load .env variables
  ├── Set ENVIRONMENT constant
  └── Bootstrap CodeIgniter
      │
      ▼
  CI3 Router (routes.php)
  ├── Match URL pattern → Controller/Method
  └── Extract URI parameters
      │
      ▼
  Hook: pre_controller (if GRADER_DEBUG)
  └── Debug_logger::pre_request() — record request metadata
      │
      ▼
  Controller Constructor
  │
  ├── [CI_Controller] → No auth (login pages, cron, health check)
  │
  ├── [MY_Controller] → School Admin Auth
  │   ├── Load Firebase, Session, Helpers
  │   ├── Send security headers (no-cache, X-Frame-Options, CSP)
  │   ├── Read session: school_name, admin_id, admin_role, session_year
  │   ├── Auth guard: redirect to /admin_login if unauthenticated
  │   ├── Session tamper check (safe segment validation)
  │   ├── Subscription check (every 5 min, force logout if expired)
  │   ├── CSRF validation on POST requests
  │   ├── RBAC permission loading (cached in session)
  │   └── Share 20+ variables to all views
  │
  └── [MY_Superadmin_Controller] → SA Panel Auth
      ├── Check sa_id in session
      ├── Session-based CSRF verification
      ├── Security headers
      └── Activity logging
      │
      ▼
  Controller Method Execution
  │
  ├── [Page Load] → Read Firebase → Load View(s) → HTML Response
  │   ├── $this->load->view('include/header')
  │   ├── $this->load->view('module/page', $data)
  │   └── $this->load->view('include/footer')
  │
  └── [AJAX Endpoint] → Read/Write Firebase → JSON Response
      ├── Validate input (role check, data validation)
      ├── Firebase operations ($this->firebase->get/set/update/delete)
      ├── Business logic (compute grades, generate receipts, etc.)
      └── $this->json_success($data) or $this->json_error($message)
      │
      ▼
  Hook: post_system (if GRADER_DEBUG)
  └── Debug_logger::post_system() — flush debug entries to log file
      │
      ▼
  HTTP Response → Browser
```

## 3.4 Controller Flow

Controllers follow a consistent pattern based on their base class:

**MY_Controller (School Panel — 35 controllers):**

```
Constructor
├── parent::__construct()
├── Auth guard (redirect if no session)
├── Session variable binding ($this->school_name, $this->firebase, etc.)
├── CSRF + subscription + tamper checks
└── RBAC permission loading

Method (Page Load)
├── $this->_require_role(['Admin', 'Principal'])
├── $data = [...] (prepare view data)
├── Firebase reads (school-scoped paths)
└── Load header + view + footer

Method (AJAX)
├── $this->_require_role([...])
├── Validate POST/GET input
├── Firebase operations (school-scoped)
├── Business logic
└── $this->json_success([...]) or $this->json_error('...')
```

## 3.5 Firebase Interaction Flow

```
Controller Method
      │
      ▼
  $this->firebase->get("Schools/{$this->school_name}/Config/Profile")
      │
      ▼
  Firebase.php Library
  ├── Kreait Admin SDK → Authenticated REST call
  ├── HTTP Client (15s timeout, 5s connection)
  ├── Debug logging (if GRADER_DEBUG: path, duration, size)
  └── Exception handling (catch, log, return null/[])
      │
      ▼
  Firebase Realtime Database (Cloud)
  ├── asia-southeast1.firebasedatabase.app
  ├── Service account authentication
  └── JSON response
      │
      ▼
  Return to Controller
  ├── Successful: array/scalar value
  └── Failed: null or empty array (graceful degradation)
```

## 3.6 Authentication Flow

### School Admin Login

```
POST /admin_login/check_credentials
      │
      ▼
  [S-01] POST-only enforcement
  [S-02] Input length validation (admin_id ≤ 32, school_id ≤ 16, password ≤ 72)
  [S-03] Firebase path injection guard (block . # $ [ ] / chars)
      │
      ▼
  [S-07] Per-IP rate limit check (20 fails / 15 min)
  [S-15] Resolve school: Indexes/School_codes/{code} → SCH_XXXXXX
      │
      ▼
  [S-06] Per-account lockout check (5 fails → 30 min lock)
  Read: Users/Admin/{school_id}/{admin_id}
      │
      ▼
  [S-05] Timing-safe password verify (dummy hash if user not found)
  [S-09] bcrypt verify + plaintext migration on success
      │
      ▼
  [S-14] Subscription status + grace period check
      │
      ▼
  [S-10] Session regeneration (fixation prevention)
  Set 18 session keys (admin_id, school_name, admin_role, session_year, ...)
  Load RBAC permissions into session
      │
      ▼
  Redirect → /admin/index (dashboard)
```

### Super Admin Login

```
POST /superadmin/login/authenticate
      │
      ▼
  Per-IP rate limit (10 fails / 30 min via Firebase RateLimit/SA/)
  Resolve school_id ("Our Panel" = developer, or school code)
  Read: Users/Admin/{school_id}/{admin_id}
      │
      ▼
  bcrypt password verify
  Role check: must be "Super Admin" (unless school_id = "Our Panel")
      │
      ▼
  Generate session-based CSRF token (bin2hex(random_bytes(32)))
  Set SA session keys (sa_id, sa_name, sa_role, sa_csrf_token)
      │
      ▼
  Redirect → /superadmin/dashboard
```

## 3.7 Module Interaction

Modules communicate through three patterns:

1. **Firebase Shared Paths:** Multiple controllers read/write the same Firebase nodes. For example, `Fees.php` writes vouchers that `Accounting.php` reads for ledger entries.

2. **Event-Driven Triggers:** `Communication_helper::fire_event()` is called by Attendance, Fees, and Exam controllers to trigger automated notifications (SMS, email, push) based on configurable rules.

3. **Library Services:** Shared libraries provide cross-cutting functionality:
   - `Backup_service.php` — Used by both `School_backup` (school admin) and `Superadmin_backups` (SA)
   - `Operations_accounting.php` — Used by Library, Inventory, Assets, and HR for journal entries
   - `Debug_tracker.php` — Used by Firebase.php, MY_Controller, and hooks for operation tracing

---

# 4. Module Breakdown

## 4.1 Module Summary

| # | Module | Controllers | Views | Routes | Lines of Code |
|---|--------|-----------|-------|--------|---------------|
| 1 | Core Authentication | 3 | 3 | 10 | ~1,500 |
| 2 | Student Information System (SIS) | 1 | 10 | 65 | ~3,200 |
| 3 | Academic Management | 3 | 4 | 70 | ~1,800 |
| 4 | Examination & Results | 3 | 13 | 40 | ~3,200 |
| 5 | Fees & Accounting | 4 | 10 | 80 | ~3,500 |
| 6 | Attendance | 1 | 6 | 34 | ~1,600 |
| 7 | HR & Payroll | 1 | 1 | 45 | ~2,650 |
| 8 | Communication | 1 | 8 | 39 | ~1,340 |
| 9 | Operations (5 sub-modules) | 6 | 6 | 84 | ~2,500 |
| 10 | School Configuration | 1 | 1 | 16 | ~500 |
| 11 | Certificates | 1 | 1 | 12 | ~800 |
| 12 | Events | 1 | 4 | 16 | ~600 |
| 13 | LMS | 1 | 4 | 33 | ~800 |
| 14 | Backup Management | 3 | 2 | 12 | ~1,200 |
| 15 | Super Admin Panel | 8 | 12 | 74 | ~4,000 |
| 16 | Utility & Support | 3 | 4 | 8 | ~1,200 |
| — | **TOTAL** | **43** | **148** | **668** | **~30,000+** |

## 4.2 Detailed Module Descriptions

### Module 1: Core Authentication & Dashboard

**Purpose:** Secure login, session management, dashboard analytics, admin user CRUD.

**Features:**
- Production-hardened login with 15 security measures
- Brute-force lockout (per-account + per-IP)
- Subscription gating with grace period
- Password migration (plaintext → bcrypt)
- Session fixation prevention
- Dashboard with student/staff/fees/event statistics
- Admin user management with role assignment
- Academic session switching

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Admin_login.php` | CI_Controller | 4 | Login, logout, credential verification |
| `Admin.php` | MY_Controller | 8 | Dashboard, admin CRUD, session switching |
| `AdminUsers.php` | MY_Controller | 8+ | Central identity and access management |

**Firebase Nodes:**
- `Users/Admin/{school_id}/{admin_id}` — credentials, profile, access history
- `Indexes/School_codes/{code}` — school ID resolution
- `RateLimit/Login/{ip}` — per-IP rate limiting

---

### Module 2: Student Information System (SIS)

**Purpose:** Complete student lifecycle management from inquiry through alumni.

**Features:**
- Admission CRM (inquiries → applications → approvals → enrollment)
- Student profile management with photo, guardian details, documents
- Batch promotion with audit trail
- Transfer Certificate (TC) generation and printing
- Student ID card generation
- Document upload and management (Firebase Storage)
- Student history tracking (every action logged)
- Data import from CSV/Excel
- Student index rebuilding for performance

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Sis.php` | MY_Controller | 40+ | Unified SIS hub (consolidated from multiple controllers) |

**Firebase Nodes:**
- `Users/Parents/{parent_db_key}/{student_id}` — student profiles
- `Schools/{school}/{session}/Class {n}/Section {X}/Students/List/{id}` — enrollment roster
- `Schools/{school}/CRM/Admissions/{type}` — CRM pipeline
- `Schools/{school}/SIS/Promotions/{batch_id}` — promotion batches
- `Users/Parents/{school_id}/{userId}/History/{key}` — action audit trail
- `Users/Parents/{school_id}/{userId}/TC/{key}` — transfer certificates

**Business Logic:**
- Enrollment guard: prevents section deletion if students are enrolled
- Promotion: batch operation with rollback tracking
- TC counter: auto-incremented per school
- CRM pipeline: Inquiry → Application → Interview → Selected → Enrolled/Rejected

---

### Module 3: Academic Management

**Purpose:** Curriculum planning, subject assignments, timetable, and academic calendar.

**Features:**
- Subject assignment per class with stream support (Classes 11–12)
- Teacher-to-subject-to-class assignment with conflict detection
- Curriculum topic tracking with completion status
- Weekly timetable grid with period management
- Academic calendar with event types
- Master class and section management
- Session management (create, switch, set active)

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Academic.php` | MY_Controller | 15+ | Curriculum, timetable, calendar |
| `Classes.php` | MY_Controller | 10+ | Class/section CRUD, student enrollment |
| `School_config.php` | MY_Controller | 16+ | 7-tab SPA for foundational configuration |

**Firebase Nodes:**
- `Schools/{school}/Config/{Profile|Board|Classes|Streams|ActiveSession}`
- `Schools/{school}/Academic/{Curriculum|Subjects|Timetable}`
- `Schools/{school}/{session}/Class {n}/Section {X}/`
- `Schools/{school}/Subject_list/{idx}/{code}`

---

### Module 4: Examination & Results

**Purpose:** End-to-end examination management from creation through analytics.

**Features:**
- Exam creation with type classification (Mid-Term, Final, Unit Test, Pre-Board, Annual)
- Template-based result design (component definitions, max marks, grading scales)
- Marks entry with teacher-scope enforcement
- Result computation with 5 grading scales (Percentage, A-F, O-E, 10-Point, Pass/Fail)
- Competition ranking (1,1,3 — ties share rank, next skips)
- Official report card generation (print-ready, school header, signatures)
- Cumulative results across multiple exams with configurable weights
- Merit lists (class toppers, subject toppers)
- Performance analytics (grade distribution, subject analysis, section comparison)
- Tabulation sheets (print-ready, class-wide)
- Bulk result computation (all sections at once)
- Exam status cascade delete (template, marks, computed, cumulative config)

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Exam.php` | MY_Controller | 8+ | Exam CRUD, scheduling, status management |
| `Result.php` | MY_Controller | 18+ | Templates, marks, computation, report cards |
| `Examination.php` | MY_Controller | 10+ | Merit lists, analytics, tabulation, bulk compute |

**Firebase Nodes:**
- `Schools/{school}/{year}/Exams/{examId}` — exam definitions
- `Schools/{school}/{year}/Results/Templates/{examId}/{class}/{section}/{subject}` — grading templates
- `Schools/{school}/{year}/Results/Marks/{examId}/{class}/{section}/{subject}/{student}` — raw marks
- `Schools/{school}/{year}/Results/Computed/{examId}/{class}/{section}/{student}` — computed grades
- `Schools/{school}/{year}/Results/CumulativeConfig` — exam weights
- `Schools/{school}/{year}/Results/Cumulative/{class}/{section}/{student}` — weighted totals

**Business Logic:**
- Grade engine must stay in sync between PHP (Result.php) and JS (marks_sheet.php)
- Exam delete cascades to all related Templates, Marks, Computed, and CumulativeConfig entries
- Teacher marks entry restricted to assigned classes/subjects via duty assignments

---

### Module 5: Fees & Accounting

**Purpose:** Complete financial management from fee collection to double-entry accounting.

**Features:**
- Fee counter with student lookup, month selection, payment processing
- Fee structure management (fee titles, class-wise amounts)
- Receipt generation with auto-incrementing receipt numbers
- Payment history and student fee profiles
- Fee categories, discounts, scholarships, refunds
- Payment gateway integration configuration
- Double-entry accounting with chart of accounts (1000–9999)
- Journal entries with Dr/Cr validation
- Income & expense tracking
- Cash book and bank reconciliation
- Financial reports (Trial Balance, P&L, Balance Sheet, Cash Flow)
- Period locking (prevent edits to finalized periods)
- Account book with monthly transaction matrix

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Fees.php` | MY_Controller | 15+ | Fee counter, structure, collection |
| `Fee_management.php` | MY_Controller | 15+ | Categories, discounts, scholarships, gateway |
| `Account.php` | MY_Controller | 10+ | Legacy account book, vouchers |
| `Accounting.php` | MY_Controller | 25+ | Modern double-entry system (7-tab SPA) |

**Firebase Nodes:**
- `Schools/{school}/{year}/Accounts/Fees/Classes Fees/{class}/{month}` — fee amounts
- `Schools/{school}/{year}/Accounts/Vouchers/{date}/{voucher_id}` — payment records
- `Schools/{school}/{year}/Accounts/Receipt_Index/{date}/{receipt_no}` — receipt tracking
- `Schools/{school}/Accounts/ChartOfAccounts/{code}` — account catalog
- `Schools/{school}/{year}/Accounts/Ledger/{entry_id}` — journal entries
- `Schools/{school}/{year}/Accounts/Closing_balances/{code}` — period-end balances

---

### Module 6: Attendance

**Purpose:** Comprehensive attendance marking with biometric device support.

**Features:**
- Student attendance marking (monthly grid view)
- Staff attendance marking (parallel structure)
- Biometric device integration (RFID, face recognition, fingerprint)
- Device pairing and API key management
- Public device API (no session auth — key-based validation)
- Late arrival tracking with timestamps
- Punch log viewer
- Attendance analytics (class-wise, monthly trends, individual reports)
- Holiday configuration
- Valid marks: P (Present), A (Absent), L (Late), H (Half Day), T (Partial), V (Vacation)
- Backward compatible with legacy "PPAP..." string format

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Attendance.php` | MY_Controller | 34+ | Student/staff attendance, devices, analytics |

**Firebase Nodes:**
- `Schools/{school}/{year}/Attendance/Student/{class}/{section}/{month}/{date}/{student}` — marks
- `Schools/{school}/Attendance/{Staff|Devices|Settings|Holidays}`

---

### Module 7: HR & Payroll

**Purpose:** Human resources management with integrated payroll and accounting.

**Features:**
- Department and designation management
- Job posting and applicant pipeline (New → Shortlisted → Interview → Offered → Hired/Rejected)
- Leave type configuration with annual balance allocation
- Leave request workflow (Apply → Approve/Reject → Deduct balance)
- Leave Without Pay (LWP) calculation
- Salary structure per staff member (basic, allowances, deductions)
- Payroll run generation (preflight validation, batch compute, finalize)
- Payslip generation and viewing
- Accounting journal integration (salary expenses, deductions, net pay)
- Appraisal cycle management (create → submit → review → close)
- HR reports and analytics

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Hr.php` | MY_Controller | 40+ | 6-tab SPA for all HR operations (~2650 lines) |

**Firebase Nodes:**
- `Schools/{school}/HR/{Departments|Recruitment|Leaves|Salary_Structures|Appraisals|Counters}`
- `Schools/{school}/{year}/HR/Payroll/{Runs|Slips}`
- `System/Logs/Payroll/` — audit trail for payroll actions

**Business Logic:**
- Payroll preflight validates salary structures exist and accounts are mapped
- Journal entries posted to accounting ledger with proper Dr/Cr codes
- Leave balance automatically decremented; LWP calculated for unpaid leave

---

### Module 8: Communication

**Purpose:** Unified messaging, announcements, and event-driven notification system.

**Features:**
- Direct messaging with conversation threads and read tracking
- Notice board with dual-write (new + legacy paths for backward compatibility)
- Circulars with acknowledgment tracking
- Message templates with variable substitution
- Event-driven triggers (14 event types: absence, fee due, exam result, etc.)
- Message delivery queue with batch processing and retry
- Delivery audit logs
- Bulk messaging (up to 500 recipients)
- Multi-channel: Push, SMS, Email, In-App
- Priority levels: High, Normal, Low

**Controllers:**
| Controller | Extends | Methods | Description |
|-----------|---------|---------|-------------|
| `Communication.php` | MY_Controller | 35+ | 8-view SPA for all communication |

**Helper Library:** `Communication_helper::fire_event()` — called by Attendance, Fees, Exam modules to trigger automated messages.

**Firebase Nodes:**
- `Schools/{school}/Communication/{Messages|Notices|Circulars|Templates|Triggers|Queue|Logs|Counters}`

---

### Module 9: Operations (5 Sub-Modules)

**Purpose:** Facility and resource management for school infrastructure.

| Sub-Module | Controller | Methods | Features |
|-----------|-----------|---------|----------|
| **Library** | `Library.php` | 20+ | Book catalog, categories, issue/return, overdue tracking, fine automation, accounting (fines → Account 4060) |
| **Transport** | `Transport.php` | 15+ | Vehicle fleet, route management, student-to-route assignment, transport fee tracking |
| **Hostel** | `Hostel.php` | 15+ | Building/room management, bed allocation, occupancy tracking, hostel-specific attendance |
| **Inventory** | `Inventory.php` | 20+ | Item catalog, vendor management, purchase orders, stock level tracking, issue/return, accounting (purchases → Account 1060) |
| **Assets** | `Assets.php` | 15+ | Fixed asset registry, depreciation (SLM/WDV methods), maintenance logs, assignment tracking, accounting (assets → Accounts 11xx/5050/1150) |

**Hub Controller:** `Operations.php` — dashboard with aggregated statistics from all sub-modules.

**Shared Library:** `Operations_accounting.php` — validates accounts and creates journal entries for financial transactions across all operations modules.

**Firebase Nodes:**
- `Schools/{school}/Operations/{Library|Transport|Hostel|Inventory|Assets}/{entity_type}/{id}`

---

### Module 10: School Configuration

**Purpose:** Foundational school setup — profile, academic sessions, board, classes, sections, subjects, streams.

**Features:** 7-tab SPA (Profile, Sessions, Board, Classes, Sections, Subjects, Streams). Enrollment guard prevents section deletion when students are enrolled. Soft-delete for classes (marked deleted, not removed). Stream configuration for Classes 11–12 with subject mapping.

**Controller:** `School_config.php` (16+ methods)
**Firebase Nodes:** `Schools/{school}/Config/{Profile|Board|Classes|Streams|ActiveSession}`

---

### Module 11–13: Certificates, Events, LMS

| Module | Controller | Features |
|--------|-----------|----------|
| **Certificates** | `Certificates.php` (12+ methods) | Template designer with dynamic placeholders, bulk generation, issued certificate tracking, print view |
| **Events** | `Events.php` (10+ methods) | Event creation/scheduling, participation tracking, calendar integration, media gallery per event |
| **LMS** | `Lms.php` (20+ methods) | Online class management, study material uploads, assignments with submission tracking, quiz engine with grading |

---

### Module 14: Backup Management

**Purpose:** Data protection for both platform-wide and per-school backups.

**Features:**
- **Superadmin Backups:** Full backup/restore for any school, scheduled backups, manual creation, retention management
- **School Backups:** Self-service daily backup toggle, manual backup (1/day limit), download, history
- **Automated Cron:** Phase 1 (global schedule) + Phase 2 (per-school schedules), cron-key validation
- **Backup Types:** Firebase-only (JSON) or Full (ZIP with files)
- **Retention:** Configurable per-school (1–14 days), automatic cleanup of oldest backups
- **Backup Format 1.3:** Includes Schools data, Users/Admin, Users/Parents, firebase_key, school_code

**Controllers:**
| Controller | Extends | Description |
|-----------|---------|-------------|
| `Superadmin_backups.php` | MY_Superadmin_Controller | Full backup management for SA |
| `School_backup.php` | MY_Controller | Self-service backups for school admins |
| `Backup_cron.php` | CI_Controller | Public cron endpoint (key-validated) |

**Shared Library:** `Backup_service.php` — extracted common backup engine used by both controllers.

---

### Module 15: Super Admin Panel

**Purpose:** Platform administration — school onboarding, subscriptions, monitoring, debugging.

| Controller | Methods | Features |
|-----------|---------|----------|
| `Superadmin.php` | 5+ | Global dashboard, summary statistics, expiry alerts |
| `Superadmin_schools.php` | 8+ | School CRUD, onboarding, plan assignment, status management |
| `Superadmin_plans.php` | 8+ | Subscription plans CRUD, feature toggles, pricing |
| `Superadmin_reports.php` | 5+ | Cross-school reports (students, revenue, activity) |
| `Superadmin_monitor.php` | 6+ | Login activity, API usage, error logs, Firebase usage |
| `Superadmin_debug.php` | 6+ | 6-tab debug panel (requests, Firebase ops, schema, security, performance) |
| `Superadmin_migration.php` | 5+ | Firebase structure migration (read-only, non-destructive) |
| `Superadmin_backups.php` | 11+ | Full backup/restore management |
| `Superadmin_login.php` | 3 | SA authentication with independent CSRF |

---

### Module 16: Utility & Support

| Controller | Purpose |
|-----------|---------|
| `Health_check.php` | Data integrity checks across 12 modules (schema validation, referential integrity, data format) |
| `AuditLogs.php` | Read-only centralized audit trail with search and export |
| `Staff.php` | Staff management — CRUD, duty assignment, timetable, import/export |
| `Schools.php` | School profile, gallery management, media upload |
| `NoticeAnnouncement.php` | Legacy notices (maintained for backward compatibility) |

---

# 5. Database Structure

## 5.1 Firebase Realtime Database — Node Hierarchy

The database follows a denormalized, read-optimized tree structure organized by functional domain:

### Root Nodes

```
Firebase Root
│
├── System/                     ← Platform administration (SA only)
├── Schools/                    ← Per-school academic & operational data
├── Users/                      ← User profiles & credentials
├── Indexes/                    ← Lookup tables for login resolution
└── RateLimit/                  ← Login protection counters
```

### 5.2 System/ (Platform-Wide Data)

```
System/
├── Schools/{school_id}/
│   ├── profile/
│   │   ├── school_name          "Green Valley Public School"
│   │   ├── city                 "New Delhi"
│   │   ├── phone                "+91-11-23456789"
│   │   ├── email                "admin@greenvalley.edu"
│   │   ├── logo_url             "https://storage.googleapis.com/..."
│   │   ├── principal_name       "Dr. Sharma"
│   │   ├── school_code          "GVP001"
│   │   └── established_year     "1995"
│   │
│   ├── subscription/
│   │   ├── plan_id              "PLAN_001"
│   │   ├── status               "Active"          // Active | Grace_Period | Expired
│   │   ├── start_date           "2026-01-01"
│   │   ├── end_date             "2026-12-31"
│   │   ├── grace_end            "2027-01-07"
│   │   └── features             ["student_management", "fees", "exams", ...]
│   │
│   └── stats_cache/
│       ├── total_students       450
│       └── total_staff          35
│
├── Plans/{plan_id}/
│   ├── name                     "Premium"
│   ├── price                    12000
│   ├── billing_cycle            "annual"
│   ├── max_students             1000
│   ├── grace_days               7
│   ├── modules/                 ["student_management", "fees", "exams", "hr", ...]
│   └── sort_order               2
│
├── Payments/{payment_id}/
│   ├── school_id                "SCH_000001"
│   ├── plan_id                  "PLAN_001"
│   ├── amount                   12000
│   ├── status                   "completed"
│   ├── method                   "bank_transfer"
│   └── created_at               "2026-01-15 10:30:00"
│
├── Backups/{safe_school_uid}/{backup_id}/
│   ├── backup_id                "BKP_20260316_023000_a1b2c3"
│   ├── filename                 "BKP_20260316_023000_a1b2c3.json"
│   ├── backup_type              "firebase"
│   ├── format                   "json"
│   ├── size_bytes               524288
│   ├── size_human               "512 KB"
│   ├── status                   "completed"
│   ├── type                     "scheduled"
│   ├── created_at               "2026-03-16 02:30:00"
│   └── created_by               "cron"
│
├── BackupSchedule/
│   ├── enabled                  true
│   ├── frequency                "daily"
│   ├── backup_time              "02:00"
│   ├── backup_type              "firebase"
│   ├── retention                7
│   ├── cron_key                 "a1b2c3d4e5f6..."
│   ├── last_run                 "2026-03-16 02:30:00"
│   └── last_run_count           5
│
├── Logs/
│   ├── Activity/{date}/{push_key}/
│   │   ├── sa_id, sa_name, action, school_uid, ip, timestamp
│   ├── Payroll/{push_key}/
│   │   ├── action, staff_id, amount, school, timestamp
│   └── ApiUsage/{date}/
│       └── {school_id}          {calls: 150, errors: 2}
│
└── RBAC/Schools/{school_id}/Roles/{role_name}/
    └── Permissions/{module}     {view: true, edit: true, delete: false}
```

### 5.3 Schools/ (Per-School Data)

```
Schools/{school_id}/
│
├── Config/
│   ├── Profile/                 {display_name, address, city, state, pincode, phone, email,
│   │                             principal_name, affiliation_board, logo_url}
│   ├── Board/                   {board_type, grading_system, grade_thresholds, pass_percentage}
│   ├── Classes/                 [{key: "9th", label: "Class 9th", order: 9, deleted: false}]
│   ├── Streams/                 {Science: {subjects: [...]}, Commerce: {...}}
│   ├── ActiveSession            "2025-2026"
│   ├── Devices/{id}             {device_name, type, api_key_hash, location, active}
│   └── BackupSchedule/          {enabled, retention, last_run, last_backup_id}
│
├── Sessions/                    ["2024-2025", "2025-2026"]
├── Subject_list/{idx}/{code}/   {name, code, type, applicable_classes}
├── Logo                         "https://storage.url/logo.png"
│
├── {session_year}/              ← e.g., "2025-2026"
│   │
│   ├── Class 9th/
│   │   └── Section A/
│   │       ├── Students/List/   {STU001: true, STU002: true}  ← enrollment flags
│   │       ├── Subjects/{code}  {name, teacher}
│   │       ├── ClassTeacher     "Mr. Kumar"
│   │       ├── Time_table/{day}/{period}  {subject, teacher, room}
│   │       ├── Month Fee        5000
│   │       └── Attendance/
│   │           └── Students/{userId}/{month}  "PPAPPA...PPAP"  ← legacy string
│   │
│   ├── Teachers/{staff_id}/     {Name: "Mrs. Gupta", Duties: {...}}
│   │
│   ├── Exams/{exam_id}/         {name, type, status, schedule, created_at}
│   │
│   ├── Results/
│   │   ├── Templates/{examId}/{class}/{section}/{subject}/  {max_marks, components}
│   │   ├── Marks/{examId}/{class}/{section}/{subject}/{student}/  {obtained, evaluated_by}
│   │   ├── Computed/{examId}/{class}/{section}/{student}/  {total, percentage, grade, rank}
│   │   ├── CumulativeConfig/    {method, exams: {id: weight}, pass_percentage}
│   │   └── Cumulative/{class}/{section}/{student}/  {weighted_pct, grade, rank}
│   │
│   ├── Accounts/
│   │   ├── ChartOfAccounts/{code}/  {name, type, group}
│   │   ├── Ledger/{entry_id}/   {date, description, dr_code, cr_code, amount, ref}
│   │   ├── Ledger_index/by_date/{date}/{entry_id}
│   │   ├── Ledger_index/by_account/{code}/{entry_id}
│   │   ├── Vouchers/{date}/{id}/  {student_id, class, month, amount, receipt_no}
│   │   ├── Receipt_Index/{date}/{receipt_no}  {student_id}
│   │   ├── Fees/
│   │   │   ├── Classes Fees/{class}/{month}  5000
│   │   │   ├── Student Fees/{student_id}/{month}  {status, paid_on}
│   │   │   └── Fees Structure/{title}  {amount, category}
│   │   └── Closing_balances/{code}  {balance: 150000}
│   │
│   └── HR/Payroll/
│       ├── Runs/{run_id}/       {month, year, status, total_net, finalized_at}
│       └── Slips/{run_id}/{staff_id}/  {basic, allowances, deductions, net, status}
│
├── HR/
│   ├── Departments/{id}/        {name, head, staff_count}
│   ├── Recruitment/
│   │   ├── Jobs/{id}/           {title, department, status, deadline}
│   │   └── Applicants/{id}/     {name, job_id, stage, resume_url}
│   ├── Leaves/
│   │   ├── Types/{id}/          {name, days_per_year, carry_forward}
│   │   ├── Balances/{year}/{staff}/{type}  {allocated, used, remaining}
│   │   └── Requests/{id}/       {staff_id, type, from, to, status, approved_by}
│   ├── Salary_Structures/{staff_id}/  {basic, hra, da, pf, esi, tax}
│   └── Appraisals/{id}/        {staff_id, period, scores, status, reviewer}
│
├── Communication/
│   ├── Messages/Conversations/{id}  {participants, last_message, unread_count}
│   ├── Messages/Chat/{conv_id}/{msg_id}  {from, body, read, timestamp}
│   ├── Notices/{id}/            {title, content, category, target, created_at}
│   ├── Circulars/{id}/          {title, body, acknowledged_by: {user: timestamp}}
│   ├── Templates/{id}/          {name, subject, body, variables}
│   ├── Triggers/{id}/           {event_type, action, channel, enabled}
│   ├── Queue/{id}/              {recipient, channel, body, status, retries}
│   ├── Logs/{id}/               {type, recipient, status, timestamp}
│   └── Counters/                {message: 150, notice: 45, circular: 12}
│
├── Operations/
│   ├── Library/{Books|Categories|Issues|Fines|Counters}/{id}
│   ├── Transport/{Vehicles|Routes|Stops|Assignments|Counters}/{id}
│   ├── Hostel/{Buildings|Rooms|Allocations|Counters}/{id}
│   ├── Inventory/{Items|Categories|Vendors|Purchases|Issues|Counters}/{id}
│   └── Assets/{Assets|Categories|Assignments|Maintenance|Counters}/{id}
│
├── CRM/Admissions/
│   ├── Inquiries/{id}/          {name, phone, class_interest, status}
│   ├── Applications/{id}/       {student_name, documents, stage, created_at}
│   └── Waitlist/{id}/           {application_id, class, position}
│
├── SIS/
│   ├── Students_Index/{student_id}/  {name, class, section, roll_no}  ← lookup cache
│   └── Promotions/{batch_id}/   {from_class, to_class, students: [...], timestamp}
│
└── AuditLogs/{log_id}/         {userId, module, action, entityId, description, ip, timestamp}
```

### 5.4 Users/ (User Profiles & Authentication)

```
Users/
├── Admin/{school_id}/{admin_id}/
│   ├── Name                     "Rajesh Kumar"
│   ├── Role                     "Admin"           // Admin | Principal | Teacher | Accountant | ...
│   ├── Status                   "Active"
│   ├── Credentials/
│   │   └── Password             "$2y$12$..."      // bcrypt hash
│   ├── Profile/
│   │   ├── name                 "Rajesh Kumar"
│   │   ├── email                "rajesh@school.com"
│   │   └── role                 "Admin"
│   └── AccessHistory/
│       ├── LastLogin             "2026-03-16T10:00:00Z"
│       ├── LoginIP              "192.168.1.100"
│       ├── LoginAttempts         0
│       ├── LockedUntil           ""
│       └── IsLoggedIn            true
│
└── Parents/{parent_db_key}/{student_id}/
    ├── Name                     "Arjun Sharma"
    ├── Class                    "9th"
    ├── Section                  "A"
    ├── Roll_no                  "15"
    ├── Status                   "Active"         // Active | TC | Alumni | Transferred
    ├── father_name              "Vikram Sharma"
    ├── mother_name              "Priya Sharma"
    ├── DOB                      "2012-05-15"
    ├── Gender                   "Male"
    ├── Admission_Date           "2024-04-01"
    ├── Admission_no             "ADM/2024/001"
    ├── Photo_url                "https://storage.url/photo.jpg"
    ├── Subjects/                {MATH: true, ENG: true, SCI: true}
    ├── History/
    │   └── {push_key}/          {action: "promoted", from: "8th A", to: "9th A", timestamp}
    ├── TC/{tc_key}/             {issued_date, reason, status, last_class}
    └── Doc/{label}/             {file_name, uploaded_at, file_url}
```

### 5.5 Indexes/ (Lookup Navigation)

```
Indexes/
├── School_codes/
│   └── GVP001                   "SCH_000001"     // Login code → canonical school ID
│
├── School_ids/
│   └── GVP001                   "GreenValley"    // Legacy fallback
│
└── School_names/
    └── green_valley_public      "SCH_000001"     // Slugified name → school ID
```

### 5.6 Indexing Strategy

| Index Path | Purpose | Lookup Direction |
|-----------|---------|-----------------|
| `Indexes/School_codes/{code}` | Login resolution | Code → School ID |
| `Indexes/School_ids/{code}` | Legacy fallback | Code → School Name |
| `Schools/{school}/SIS/Students_Index/{id}` | Fast student lookup | Student ID → Name/Class |
| `Schools/{school}/{year}/Accounts/Ledger_index/by_date/{date}/{id}` | Journal by date | Date → Entry IDs |
| `Schools/{school}/{year}/Accounts/Ledger_index/by_account/{code}/{id}` | Journal by account | Account → Entry IDs |
| `Schools/{school}/{year}/Accounts/Receipt_Index/{date}/{rcpt}` | Receipt lookup | Receipt No → Student |
| `Schools/{school}/Phone_Index/{phone}` | Reverse phone lookup | Phone → Student ID |
| `RateLimit/Login/{ip}` | Rate limiting | IP → Fail count |

### 5.7 Security Considerations

- **No client-side Firebase Rules enforcement:** The system uses server-side Admin SDK exclusively. Firebase Security Rules should be configured to deny all public access and allow only the service account.
- **PII stored in plaintext:** Student names, phone numbers, addresses, and Aadhar numbers are not encrypted at rest. Field-level encryption is recommended for production.
- **Service account key:** The JSON key file must never be committed to version control or exposed publicly.
- **parent_db_key isolation:** Critical for multi-tenancy — legacy schools store student data under login code (e.g., "10004"), not school name. The `parent_db_key` property in MY_Controller resolves this automatically.

---

# 6. Security Analysis

## 6.1 Authentication Security

| Measure | Implementation | Rating |
|---------|---------------|--------|
| **Password Hashing** | bcrypt via `password_hash()` with cost 12 | Strong |
| **Timing-Safe Verification** | Dummy hash processed when user not found (prevents enumeration) | Strong |
| **Brute-Force Protection** | Per-account lockout (5 attempts → 30 min) + per-IP rate limit (20 fails / 15 min) | Strong |
| **Session Fixation** | `sess_regenerate(TRUE)` after successful login | Strong |
| **Session Hijacking** | HttpOnly cookies, SameSite=Lax, 5-minute session ID rotation | Strong |
| **Plaintext Migration** | Legacy plaintext passwords auto-upgraded to bcrypt on first login | Adequate |
| **Password Length** | Capped at 72 chars (bcrypt limit, prevents DoS via long input) | Strong |
| **Subscription Gating** | Expired subscriptions force logout after grace period | Adequate |

## 6.2 CSRF Protection

| Panel | Method | Token Lifetime | Regeneration |
|-------|--------|---------------|-------------|
| **School Admin** | CI3 cookie-based (`csrf_token`) | 2 hours | Disabled (FALSE — prevents multi-tab issues) |
| **Super Admin** | Session-based (`sa_csrf_token`) | Session lifetime | Per-session |

**Dual CSRF Strategy Rationale:** Both panels share the same cookie domain. Cookie-based CSRF for both would cause whichever panel last POSTed to overwrite the other's cookie, causing 403 failures. The SA panel uses an independent session-based token.

**Token Delivery:** Meta tags in HTML (`<meta name="csrf-token">`), automatically attached to all `$.ajax()` and `fetch()` POST requests via global `$.ajaxSetup()` and patched `window.fetch()`.

## 6.3 Input Validation

| Validation Type | Implementation | Coverage |
|----------------|---------------|----------|
| **Firebase Path Injection** | `_is_safe_segment()` — allows only `[A-Za-z0-9 ',_\-]` | All Firebase paths |
| **Admin ID** | `^[A-Za-z0-9_\-]+$`, max 32 chars | Login |
| **School Code** | `^[A-Z0-9]{3,10}$`, max 16 chars | Login |
| **Email** | `filter_var(FILTER_VALIDATE_EMAIL)` | Profile forms |
| **URL** | `filter_var(FILTER_VALIDATE_URL)` | LMS, profile |
| **Boolean** | `filter_var(FILTER_VALIDATE_BOOLEAN)` | Toggle settings |
| **File Upload** | `finfo_file()` MIME validation, CI upload library | Communication, media |

## 6.4 Output Escaping

| Context | Method | Usage |
|---------|--------|-------|
| **HTML** | `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` | Controller output, view variables |
| **HTML5** | `htmlspecialchars($var, ENT_QUOTES \| ENT_HTML5, 'UTF-8')` | Certificates, communication |
| **JavaScript** | Client-side `esc()` function (creates text node, reads innerHTML) | AJAX response rendering |
| **JSON** | `json_encode()` with `JSON_UNESCAPED_UNICODE` | All AJAX responses |

## 6.5 Access Control

| Layer | Mechanism | Description |
|-------|-----------|-------------|
| **Route-Level** | `MY_Controller` auth guard | Redirects unauthenticated requests to login |
| **Method-Level** | `_require_role(['Admin', 'Principal'])` | Case-insensitive role check with 403 on failure |
| **Module-Level** | RBAC permissions (cached in session) | Feature-gated sidebar and controller access |
| **Data-Level** | `$this->school_name` session binding | All Firebase paths scoped to authenticated school |
| **Teacher-Level** | `_teacher_can_access(class, section, subject)` | Teachers restricted to assigned classes |
| **SA-Level** | `MY_Superadmin_Controller` with separate auth | Independent session, own CSRF, System/* paths only |

## 6.6 Security Headers

Applied on every response via MY_Controller constructor:

```
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
Pragma: no-cache
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
```

## 6.7 Potential Vulnerabilities and Recommendations

| # | Finding | Severity | Recommendation |
|---|---------|----------|----------------|
| 1 | **Plaintext password fallback** still active for legacy migration | Medium | Add a migration deadline; disable after all users have logged in once |
| 2 | **PII stored unencrypted** in Firebase (names, Aadhar, phone) | Medium | Implement field-level encryption for sensitive fields (AES-256-GCM) |
| 3 | **Service account key** in application config directory | High | Move to environment variable or secrets manager; ensure `.gitignore` coverage |
| 4 | **CSV import** in Accounting.php lacks structural validation | Low | Validate column count and data types before processing |
| 5 | **cookie_secure = FALSE** in development | Low (dev only) | Set to TRUE in production (requires HTTPS) |
| 6 | **No Content-Security-Policy header** | Low | Add CSP header to restrict inline scripts and external resources |
| 7 | **Firebase Security Rules** not enforced client-side | Info | Configure deny-all rules except for service account; add per-school rules if client apps are introduced |

---

# 7. Performance & Scalability

## 7.1 Firebase Read/Write Patterns

| Pattern | Usage | Performance Impact |
|---------|-------|-------------------|
| **Full Path Read** | `get("Schools/{school}/Config/Profile")` | Fast (exact path, small payload) |
| **Shallow Get** | `shallow_get("Schools/{school}/{year}")` → keys only | Optimized (no data transfer for child values) |
| **Deep Read** | `get("Schools/{school}/{year}")` → entire session tree | Slow for large schools (all classes, sections, students) |
| **Indexed Lookup** | `get("Indexes/School_codes/{code}")` | Fast (single value read) |
| **Batch Writes** | `update("path", {key1: val1, key2: val2})` | Efficient (single HTTP call for multiple updates) |

## 7.2 Query Optimization

| Technique | Implementation |
|-----------|---------------|
| **Shallow Get + Filter** | Use `shallow_get()` to list children, then read specific paths as needed |
| **Session Caching** | RBAC permissions, available sessions, subscription status cached in PHP session (reduce Firebase reads) |
| **Ledger Indexes** | `Ledger_index/by_date/` and `by_account/` allow targeted reads instead of scanning entire ledger |
| **Student Index** | `SIS/Students_Index/{id}` provides lightweight name/class lookup without reading full profile |
| **Counter Nodes** | Sequential counters (receipt numbers, TC numbers) avoid scanning all records to find the next ID |

## 7.3 Caching Opportunities

| Data | Current | Recommendation |
|------|---------|----------------|
| RBAC Permissions | Cached in PHP session | Adequate — reloaded on login |
| School Profile | Read per request | Cache in session with 30-minute TTL |
| Class/Section List | Read per page | Cache in session with 10-minute TTL |
| Subject List | Read per page | Cache in session with 10-minute TTL |
| Dashboard Stats | Computed per request | Cache with 5-minute TTL (Redis or session) |

## 7.4 Potential Bottlenecks

| Bottleneck | Description | Mitigation |
|-----------|-------------|------------|
| **In-Memory Filtering** | DataTables server-side processing loads all records into PHP memory, then filters/sorts | Acceptable for <5,000 records; consider Firebase queries or pagination for larger datasets |
| **Full Backup** | Large schools (1000+ students) generate 10+ MB JSON exports | Already capped at 50 MB ZIP; consider streaming writes for larger schools |
| **Attendance Grid** | Monthly grid reads all students × all days | Use shallow_get for student list, then batch-read attendance per section |
| **Payroll Generation** | Reads salary structures for all staff, computes, writes slips | Sequential processing; parallelization not possible with Firebase SDK |
| **Report Card PDF** | Server-side PDF generation for individual students | Consider client-side PDF generation (pdfMake) for bulk operations |

## 7.5 Scalability Projections

### 100 Users (5–10 Schools, Small Institutions)

| Metric | Expected |
|--------|----------|
| Firebase Reads | ~500/hour |
| Firebase Writes | ~50/hour |
| Response Time | <200ms (page), <100ms (AJAX) |
| PHP Memory | <64 MB per request |
| Database Size | <50 MB |
| **Verdict** | Comfortable — no optimization needed |

### 1,000 Users (50–100 Schools, Medium Deployment)

| Metric | Expected |
|--------|----------|
| Firebase Reads | ~5,000/hour |
| Firebase Writes | ~500/hour |
| Response Time | <300ms (page), <150ms (AJAX) |
| PHP Memory | <128 MB per request |
| Database Size | <500 MB |
| **Verdict** | Stable — consider session caching for frequently-read data |

### 10,000 Users (500+ Schools, Large Deployment)

| Metric | Expected |
|--------|----------|
| Firebase Reads | ~50,000/hour |
| Firebase Writes | ~5,000/hour |
| Response Time | <500ms (page), <300ms (AJAX) |
| PHP Memory | Up to 256 MB for large operations |
| Database Size | ~5 GB |
| **Verdict** | Requires optimization — implement Redis caching, consider Cloud Firestore migration for complex queries, add Firebase read quotas per school, implement pagination for large lists |

### Key Scaling Recommendations

1. **1,000+ students per school:** Implement server-side pagination in DataTables (Firebase REST queries with `orderBy`, `limitToFirst`, `startAt`)
2. **5,000+ concurrent users:** Add Redis/Memcached layer for session data and frequently-read configurations
3. **10,000+ total users:** Consider migrating to Cloud Firestore for indexed queries and real-time listeners
4. **Large backup volumes:** Implement streaming JSON writer to avoid loading entire school data into memory

---

# 8. Logging & Audit System

## 8.1 Debug Logging System

The platform includes a comprehensive debug and performance tracing system controlled by a flag file toggle.

### Activation

| Setting | Detail |
|---------|--------|
| **Flag File** | `application/logs/.debug_enabled` (exists = ON) |
| **Constant** | `GRADER_DEBUG` defined in `constants.php` |
| **Toggle** | `Superadmin_debug::toggle_debug()` (creates/deletes flag file) |
| **Hooks** | `pre_controller` (request start) + `post_system` (flush logs) |

### Event Types Tracked

| Event Type | Description | Trigger |
|-----------|-------------|---------|
| `REQUEST` | HTTP request metadata (URI, method, controller, action, IP, duration) | Every request |
| `FIREBASE_READ` | Firebase get/shallow operations (path, duration, size_bytes) | Every Firebase read |
| `FIREBASE_WRITE` | Firebase set/update/push operations | Every Firebase write |
| `FIREBASE_ERROR` | Firebase operation failures with error messages | On Firebase exception |
| `SLOW_OP` | Operations exceeding 500ms threshold | Auto-detected |
| `SCHEMA_MISMATCH` | Missing required fields on Firebase reads | Validated on system paths |
| `UNAUTHORIZED` | Unauthorized access attempts to SA panel | On auth failure |
| `AJAX_ERROR` | Client-side JavaScript errors (jQuery ajaxError handler) | POSTed by browser |

### Log Format

```json
{"type":"FIREBASE_READ","path":"Schools/SCH_000001/Config/Profile","duration_ms":87,"size_bytes":1024,"caller":"School_config::get_config","timestamp":"2026-03-16 10:30:45.123"}
```

- **Format:** JSONL (one JSON object per line)
- **Location:** `application/logs/debug_YYYY-MM-DD.log`
- **Buffer:** Up to 2,000 entries per request, flushed at request end
- **Non-blocking:** Log failures never interrupt the main request

### Debug Panel (Super Admin)

6-tab interface at `/superadmin/debug`:

1. **Requests** — HTTP request log with filtering by date/method
2. **Firebase Operations** — All reads/writes with path, duration, size
3. **Schema Issues** — Missing or malformed data in system paths
4. **Security** — Unauthorized access attempts with IP/timestamp
5. **Performance** — Slow operations (>500ms) with path and duration
6. **Live Schema Check** — On-demand validation against expected schemas

## 8.2 Audit Logging

### School-Level Audit Trail

| Attribute | Detail |
|-----------|--------|
| **Storage** | `Schools/{school_id}/AuditLogs/{log_id}` |
| **ID Format** | `AL_YYYYMMDD_HHmmss_UNIQUE` |
| **Archival** | Moves to `AuditArchive/` when exceeding 10,000 entries |
| **Access** | Read-only via `AuditLogs` controller (search, export) |

**Log Entry Structure:**
```json
{
  "userId": "admin_001",
  "userName": "Rajesh Kumar",
  "userRole": "Admin",
  "module": "Fees",
  "action": "collect_fee",
  "entityId": "RCPT00032",
  "description": "Fee collected: Rs 5000 for April",
  "ipAddress": "192.168.1.100",
  "timestamp": "2026-03-16T10:30:45Z"
}
```

### Super Admin Activity Logging

| Attribute | Detail |
|-----------|--------|
| **Storage** | `System/Logs/Activity/{YYYY-MM-DD}/{push_key}` |
| **Scope** | Platform-wide (all SA actions) |
| **Tracked Actions** | School creation/edit, plan assignment, backup operations, debug toggle, migration, all CRUD operations |

### Module-Specific Audit

| Module | Audit Path | What's Logged |
|--------|-----------|---------------|
| HR/Payroll | `System/Logs/Payroll/{push_key}` | Salary changes, payroll finalization, payment processing |
| SIS | `Users/Parents/{id}/History/{key}` | Admission, promotion, TC issuance, status changes |
| Communication | `Communication/Logs/{id}` | Message delivery status, channel, recipient, timestamp |
| Attendance | `Attendance/Punch_Log/{date}/{id}` | Biometric device punch records with timestamps |

## 8.3 Error Logging

| Type | Location | Format |
|------|----------|--------|
| **CI3 Error Log** | `application/logs/log-YYYY-MM-DD.php` | Standard CI3 format |
| **Firebase Errors** | CI3 error log + Debug_tracker | `log_message('error', ...)` |
| **AJAX Errors** | `System/Logs/Activity/` + Debug_tracker | Client-side errors posted to server |
| **Login Failures** | CI3 error log | IP, school_id, admin_id, reason |

---

# 9. Code Quality Review

## 9.1 Code Organization

| Aspect | Assessment | Detail |
|--------|-----------|--------|
| **Directory Structure** | Good | Standard CI3 MVC with clear separation of controllers, views, models, libraries, helpers |
| **Controller Sizing** | Mixed | Most controllers are well-scoped (300–800 lines). Some are large: Hr.php (~2,650 lines), Attendance.php (~1,600 lines), Accounting.php (~1,500 lines). These are acceptable given they represent complete SPA modules with 20–40 AJAX endpoints each. |
| **Base Controller Pattern** | Excellent | `MY_Controller` centralizes auth, CSRF, RBAC, session management, and security headers. All 35+ school controllers inherit consistently. |
| **SA Controller Pattern** | Excellent | `MY_Superadmin_Controller` with independent auth and CSRF avoids collision with school panel. |

## 9.2 Modularity

| Aspect | Assessment | Detail |
|--------|-----------|--------|
| **Library Extraction** | Good | Shared logic extracted into libraries: `Backup_service`, `Operations_accounting`, `Communication_helper`, `Debug_tracker` |
| **Helper Functions** | Good | Cross-cutting concerns in helpers: `rbac_helper`, `audit_helper` |
| **Model Usage** | Adequate | `Common_model` provides Firebase CRUD and Storage access. Direct `$this->firebase` calls are more common (appropriate for the architecture). |
| **View Reuse** | Good | `include/header.php` and `include/footer.php` shared across all pages. Module-specific CSS embedded in views using scoped prefixes. |

## 9.3 Naming Conventions

| Element | Convention | Consistency |
|---------|-----------|-------------|
| **Controllers** | PascalCase (e.g., `School_config`, `Fee_management`) | Consistent |
| **Methods** | snake_case (e.g., `get_backups`, `save_schedule`) | Consistent |
| **Views** | snake_case directories and files | Consistent |
| **CSS Classes** | Module prefix (e.g., `sb-*` for backup, `al-*` for audit logs) | Consistent |
| **JavaScript Namespaces** | Uppercase abbreviation (e.g., `SB.*`, `HR.*`) | Consistent |
| **Firebase Paths** | PascalCase nodes (e.g., `Students/List`, `Config/Profile`) | Mostly consistent |
| **Constants** | UPPER_SNAKE_CASE (e.g., `ALLOWED_ROLES`, `MAX_RETENTION`) | Consistent |

## 9.4 Reusability

| Pattern | Usage | Assessment |
|---------|-------|-----------|
| **RBAC Constants** | `const MANAGE_ROLES = ['Admin', 'Principal']` defined per controller | Consistent pattern across all modules |
| **JSON Response** | `$this->json_success()` / `$this->json_error()` | Standardized across all AJAX endpoints |
| **DataTable Init** | 3 CSS classes (`.example`, `.dataTable`, `.minimalFeatureTable`) auto-configured in footer | Excellent reuse |
| **Theme Variables** | 40+ CSS custom properties shared across all views | Excellent consistency |
| **Toast Notifications** | Module-scoped `toast()` function in each SPA view | Could be centralized but acceptable |

## 9.5 Maintainability

| Factor | Assessment | Detail |
|--------|-----------|--------|
| **Single Responsibility** | Good | Each controller handles one logical module. Shared concerns extracted to libraries. |
| **Dependency Injection** | Limited | CI3's loader pattern (`$this->load->library()`) used throughout. Adequate for the framework. |
| **Error Handling** | Good | Try/catch blocks around Firebase operations. Errors logged and generic messages returned to users. |
| **Configuration** | Good | Environment-specific settings in `.env`, debug toggle via flag file, CSRF and session config centralized. |
| **Documentation** | Good | In-code PHPDoc comments on all public methods. Memory files document architecture decisions. |

---

# 10. Suggested Improvements

## 10.1 Security

| Priority | Improvement | Detail |
|----------|------------|--------|
| **High** | Deprecate plaintext password support | Set a deadline; force password reset for unmigrted accounts |
| **High** | Move service account key to environment variable | Remove from config directory; use `GOOGLE_APPLICATION_CREDENTIALS` |
| **Medium** | Add Content-Security-Policy header | Restrict inline scripts, external resources, and frame ancestors |
| **Medium** | Implement field-level encryption for PII | Encrypt Aadhar, phone, address at rest using AES-256-GCM |
| **Low** | Add request rate limiting by session | Prevent authenticated users from overwhelming the system |

## 10.2 Performance

| Priority | Improvement | Detail |
|----------|------------|--------|
| **High** | Implement session-based caching for school profile, class list, subject list | Reduce redundant Firebase reads (3–5 reads saved per page load) |
| **Medium** | Add server-side pagination for DataTables | Use Firebase REST queries with `limitToFirst` and `startAt` for large datasets |
| **Medium** | Implement Redis/Memcached for dashboard statistics | Cache computed stats with 5-minute TTL |
| **Low** | Lazy-load sidebar sub-menus | Reduce initial page weight for large sidebars |

## 10.3 Scalability

| Priority | Improvement | Detail |
|----------|------------|--------|
| **High** | Evaluate Cloud Firestore migration for complex queries | RTDB has no indexing, filtering, or pagination — Firestore supports all three natively |
| **Medium** | Implement Firebase read quotas per school | Prevent a single large school from consuming disproportionate resources |
| **Medium** | Add horizontal scaling support | Move sessions from file driver to Redis for multi-server deployments |
| **Low** | Implement WebSocket connections for real-time features | Firebase RTDB supports real-time listeners; useful for attendance and messaging |

## 10.4 Code Structure

| Priority | Improvement | Detail |
|----------|------------|--------|
| **Medium** | Extract large controllers into service classes | Hr.php (2650 lines) and Attendance.php (1600 lines) could benefit from dedicated service layers |
| **Medium** | Centralize toast/notification JS | Create a global `Grader.toast()` function instead of per-module implementations |
| **Low** | Migrate to CodeIgniter 4 | CI3 is end-of-life; CI4 offers namespaces, PSR-4 autoloading, and modern PHP features |
| **Low** | Add TypeScript for frontend | Type safety for complex SPA modules |

## 10.5 Monitoring

| Priority | Improvement | Detail |
|----------|------------|--------|
| **Medium** | Add uptime monitoring for Firebase connectivity | Alert when Firebase reads fail consistently |
| **Medium** | Implement structured logging (JSON format) for CI3 error logs | Enable log aggregation and analysis |
| **Low** | Add APM (Application Performance Monitoring) | Track response times, error rates, and throughput per endpoint |

## 10.6 Backup Strategy

| Priority | Improvement | Detail |
|----------|------------|--------|
| **High** | Enable automated daily backups for all schools by default | Currently opt-in; should be opt-out for data safety |
| **Medium** | Implement backup verification (restore-test) | Periodically validate backup files can be restored successfully |
| **Medium** | Add off-site backup storage | Currently stored locally; replicate to cloud storage (S3/GCS) |
| **Low** | Implement incremental backups | Only backup changed data since last full backup (reduces size and time) |

---

# 11. Deployment Guide

## 11.1 Server Requirements

| Requirement | Minimum | Recommended |
|------------|---------|-------------|
| **OS** | Windows 10 / Ubuntu 20.04 | Ubuntu 22.04 LTS |
| **Web Server** | Apache 2.4 | Apache 2.4 with `mod_rewrite`, `mod_headers` |
| **PHP Version** | 7.4 | 8.1+ |
| **PHP Extensions** | json, curl, mbstring, openssl, zip | + intl, gd, fileinfo |
| **Composer** | 2.x | Latest |
| **Memory** | 128 MB | 256 MB+ |
| **Disk Space** | 500 MB | 2 GB+ (for backups) |
| **SSL** | Optional (dev) | Required (production) |

## 11.2 PHP Configuration

```ini
; php.ini recommended settings
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 20M
post_max_size = 25M
date.timezone = Asia/Kolkata
```

## 11.3 Environment Setup

### Step 1: Clone Repository

```bash
git clone <repository-url> /var/www/html/Grader/school
cd /var/www/html/Grader/school
```

### Step 2: Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### Step 3: Configure Environment

Create `.env` file in the project root:

```env
CI_ENV=production
```

### Step 4: Firebase Configuration

1. Create a Firebase project at [console.firebase.google.com](https://console.firebase.google.com)
2. Enable Realtime Database (Asia Southeast 1 region recommended)
3. Enable Cloud Storage
4. Generate a service account key (Project Settings → Service Accounts → Generate New Private Key)
5. Place the JSON key file at: `application/config/<project-id>-firebase-adminsdk-<id>.json`
6. Update `Firebase.php` library with the correct project ID, database URI, and key file path

### Step 5: Configure Application

Edit `application/config/config.php`:

```php
// Set base URL to your domain
$config['base_url'] = 'https://yourdomain.com/Grader/school/';

// Enable HTTPS cookie security
$config['cookie_secure'] = TRUE;

// Session configuration
$config['sess_save_path'] = '/tmp/ci_sessions';  // Writable directory
```

### Step 6: Apache Configuration

Ensure `.htaccess` is present in the project root:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php/$1 [L]
```

Enable required Apache modules:

```bash
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

### Step 7: Directory Permissions

```bash
chmod -R 750 application/logs/
chmod -R 750 application/backups/
chmod -R 750 uploads/
```

### Step 8: Cron Setup (Automated Backups)

```bash
# Daily at 02:00 AM
0 2 * * * curl -s "https://yourdomain.com/Grader/school/backup_cron/YOUR_CRON_KEY" >> /var/log/grader_backup.log
```

## 11.4 Folder Structure

```
Grader/school/
├── index.php                    # Front controller
├── .htaccess                    # Apache URL rewriting
├── .env                         # Environment variables
├── composer.json                # PHP dependencies
├── vendor/                      # Composer packages (auto-generated)
│
├── application/
│   ├── config/                  # Framework + Firebase configuration
│   ├── controllers/             # 43 PHP controllers
│   ├── core/                    # MY_Controller, MY_Superadmin_Controller
│   ├── helpers/                 # rbac, audit, progress_bar helpers
│   ├── hooks/                   # Debug logger hooks
│   ├── libraries/               # Firebase, Backup, Debug, Communication
│   ├── models/                  # Common_model, Common_sql_model
│   ├── views/                   # 148 PHP view files
│   ├── logs/                    # CI3 + debug log files
│   └── backups/                 # Backup files (protected by .htaccess)
│
├── system/                      # CodeIgniter 3 framework (do not modify)
│
├── tools/                       # Frontend assets
│   ├── bower_components/        # Bootstrap, jQuery, DataTables, etc.
│   ├── dist/                    # AdminLTE compiled assets
│   ├── css/                     # Custom stylesheets
│   ├── js/                      # Custom JavaScript
│   └── image/                   # Static images
│
└── uploads/                     # User-uploaded files (photos, documents)
```

## 11.5 Post-Deployment Verification

1. Access `https://yourdomain.com/Grader/school/` — should show login page
2. Log in with admin credentials — should reach dashboard
3. Check Firebase connectivity (dashboard should show statistics)
4. Test a POST operation (e.g., save schedule settings) — verifies CSRF
5. Run `/health_check` — validates data integrity across modules
6. Verify backup cron: `curl "https://yourdomain.com/Grader/school/backup_cron/YOUR_KEY"`

---

# 12. Future Enhancements

## 12.1 Short-Term (1–3 Months)

| Enhancement | Impact | Effort |
|------------|--------|--------|
| **Parent/Student Mobile App** | Allow parents to view attendance, results, fees, notices via mobile app with Firebase Auth | High | High |
| **Online Fee Payment** | Integrate Razorpay/Stripe for online fee collection with automatic receipt generation | High | Medium |
| **SMS/Email Delivery** | Connect Communication triggers to actual SMS gateway (Twilio/MSG91) and email service (SendGrid) | High | Medium |
| **Bulk Report Card PDF** | Generate all report cards for a class in a single PDF download | Medium | Low |
| **Student Dashboard** | Self-service portal for students to view their own data | Medium | Medium |

## 12.2 Medium-Term (3–6 Months)

| Enhancement | Impact | Effort |
|------------|--------|--------|
| **Cloud Firestore Migration** | Better querying, indexing, and pagination for large schools | High | High |
| **Multi-Language Support (i18n)** | Support Hindi, Tamil, Bengali, and other regional languages | Medium | Medium |
| **Advanced Analytics Dashboard** | School-wide KPIs with drill-down (enrollment trends, financial health, academic performance) | Medium | Medium |
| **Document Verification** | QR code-based verification for TCs, marksheets, and certificates | Medium | Low |
| **Automated Timetable Generation** | AI-assisted timetable creation with constraint satisfaction | Medium | High |

## 12.3 Long-Term (6–12 Months)

| Enhancement | Impact | Effort |
|------------|--------|--------|
| **API Layer (REST/GraphQL)** | Public API for third-party integrations (government portals, EdTech tools) | High | High |
| **Multi-Branch Support** | Single admin managing multiple school branches with consolidated reporting | High | High |
| **AI-Powered Insights** | Predictive analytics for student performance, dropout risk, and resource optimization | Medium | High |
| **Offline Mode** | Service worker-based offline support for attendance marking in low-connectivity areas | Medium | High |
| **CodeIgniter 4 Migration** | Modern PHP features, namespaces, PSR-4, improved testing support | Medium | High |
| **Microservices Architecture** | Decompose monolith into independent services (auth, academics, finance, communication) for independent scaling | High | Very High |

---

# Appendices

## Appendix A: Controller Inventory (43 Controllers)

| # | Controller | Base Class | Lines | Methods | Module |
|---|-----------|-----------|-------|---------|--------|
| 1 | Admin_login.php | CI_Controller | 595 | 4 | Authentication |
| 2 | Admin.php | MY_Controller | 519 | 8 | Dashboard |
| 3 | AdminUsers.php | MY_Controller | — | 8+ | Admin Management |
| 4 | Sis.php | MY_Controller | 3,207 | 40+ | Student Information |
| 5 | Classes.php | MY_Controller | 1,000+ | 10+ | Class Management |
| 6 | Subjects.php | MY_Controller | 500+ | 8+ | Subject Management |
| 7 | Academic.php | MY_Controller | 800+ | 15+ | Academic Planning |
| 8 | School_config.php | MY_Controller | 500+ | 16+ | Configuration |
| 9 | Exam.php | MY_Controller | 800+ | 8+ | Exam CRUD |
| 10 | Result.php | MY_Controller | 1,200+ | 18+ | Results & Grading |
| 11 | Examination.php | MY_Controller | 1,149 | 10+ | Analytics & Merit |
| 12 | Fees.php | MY_Controller | 800+ | 15+ | Fee Collection |
| 13 | Fee_management.php | MY_Controller | — | 15+ | Fee Configuration |
| 14 | Account.php | MY_Controller | 600+ | 10+ | Account Book |
| 15 | Accounting.php | MY_Controller | 1,500+ | 25+ | Double-Entry Accounting |
| 16 | Attendance.php | MY_Controller | 1,600+ | 34+ | Attendance |
| 17 | Hr.php | MY_Controller | 2,650 | 40+ | HR & Payroll |
| 18 | Communication.php | MY_Controller | 1,341 | 35+ | Messaging & Notices |
| 19 | Operations.php | MY_Controller | 400+ | 10+ | Operations Hub |
| 20 | Library.php | MY_Controller | 600+ | 20+ | Library Management |
| 21 | Transport.php | MY_Controller | 443 | 15+ | Transport |
| 22 | Hostel.php | MY_Controller | 518 | 14+ | Hostel |
| 23 | Inventory.php | MY_Controller | 449 | 16+ | Inventory |
| 24 | Assets.php | MY_Controller | 480 | 15+ | Asset Management |
| 25 | Staff.php | MY_Controller | 2,000+ | 20+ | Staff Management |
| 26 | Schools.php | MY_Controller | 784 | 10+ | School Profile |
| 27 | Events.php | MY_Controller | 600+ | 10+ | Event Management |
| 28 | Certificates.php | MY_Controller | 800+ | 12+ | Certificate Management |
| 29 | Lms.php | MY_Controller | 800+ | 20+ | Learning Management |
| 30 | Admission_crm.php | MY_Controller | — | 15+ | Admission CRM |
| 31 | NoticeAnnouncement.php | MY_Controller | — | 8+ | Legacy Notices |
| 32 | School_backup.php | MY_Controller | 300 | 6 | School Backups |
| 33 | Health_check.php | MY_Controller | 400+ | 3 | Data Integrity |
| 34 | AuditLogs.php | MY_Controller | — | 4+ | Audit Trail |
| 35 | Backup_cron.php | CI_Controller | 317 | 1 | Automated Backups |
| 36 | Superadmin.php | MY_Superadmin | — | 5+ | SA Dashboard |
| 37 | Superadmin_schools.php | MY_Superadmin | — | 8+ | School Management |
| 38 | Superadmin_plans.php | MY_Superadmin | — | 8+ | Plan Management |
| 39 | Superadmin_backups.php | MY_Superadmin | 550+ | 11+ | SA Backups |
| 40 | Superadmin_reports.php | MY_Superadmin | — | 5+ | Global Reports |
| 41 | Superadmin_monitor.php | MY_Superadmin | — | 6+ | System Monitoring |
| 42 | Superadmin_debug.php | MY_Superadmin | — | 6+ | Debug Panel |
| 43 | Superadmin_migration.php | MY_Superadmin | — | 5+ | Data Migration |

## Appendix B: Route Count by Module

| Module | Routes |
|--------|--------|
| Super Admin Panel | 74 |
| Student Information System | 65 |
| HR & Payroll | 45 |
| Accounting | 41 |
| Communication | 39 |
| Fee Management | 38 |
| Attendance | 34 |
| LMS | 33 |
| School Config | 28 |
| Academic | 26 |
| Result Management | 22 |
| Operations (Library) | 20 |
| Operations (Transport) | 17 |
| Operations (Inventory) | 16 |
| Events | 16 |
| Operations (Assets) | 15 |
| Operations (Hostel) | 14 |
| Certificates | 12 |
| Backup Management | 12 |
| Core (Auth, Dashboard) | 10 |
| Health Check / Utility | 8 |
| **TOTAL** | **668** |

## Appendix C: Design System Variables

```css
/* Color Palette */
--gold: #0f766e;           /* Primary Teal */
--gold2: #0d6b63;          /* Teal Dark */
--gold3: #14b8a6;          /* Teal Light */
--amber: #d97706;          /* Accent Amber */
--rose: #e05c6f;           /* Error/Badge Red */

/* Backgrounds (Night Mode) */
--bg: #070f1c;             /* Page background */
--bg2: #0c1e38;            /* Card background */
--bg3: #0f2545;            /* Input/table header */
--bg4: #1a3555;            /* Hover state */

/* Backgrounds (Day Mode) */
--bg: #f0f7f5;             /* Page background */
--bg2: #ffffff;            /* Card background */
--bg3: #e6f4f1;            /* Input/table header */
--bg4: #cce9e4;            /* Hover state */

/* Typography */
--font-d: 'Syne';         /* Display/Headings */
--font-b: 'Plus Jakarta Sans'; /* Body/Primary */
--font-m: 'JetBrains Mono';    /* Monospace/Code */

/* Spacing & Sizing */
--sw: 248px;               /* Sidebar width */
--hh: 58px;                /* Header height */
--r: 12px;                 /* Border radius large */
--r-sm: 8px;               /* Border radius small */
--ease: .22s cubic-bezier(.4,0,.2,1);  /* Transition */
```

---

**End of Report**

*This document was generated from analysis of the live codebase on 2026-03-16.*
*All technical details reflect the current state of the repository at the time of generation.*

---

*Grader School ERP — Technical Project Report v1.0*
*Total Pages: ~45 | Sections: 12 | Appendices: 3*
