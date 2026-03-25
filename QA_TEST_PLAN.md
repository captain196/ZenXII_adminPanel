# School ERP — Complete QA Test Plan & System Validation

**Version:** 1.0
**Date:** 2026-03-16
**Prepared by:** QA Architect — Pre-Production Audit
**System:** CodeIgniter 3 + Firebase Realtime Database School ERP
**Scope:** 43 controllers, 16 modules, ~25,000 lines of server code

---

## Table of Contents

1. [Testing Strategy Overview](#1-testing-strategy-overview)
2. [Recommended Testing Order](#2-recommended-testing-order)
3. [Module-by-Module Test Plan](#3-module-by-module-test-plan)
4. [Cross-Cutting Security Tests](#4-cross-cutting-security-tests)
5. [Performance & Scalability Tests](#5-performance--scalability-tests)
6. [Data Integrity & Firebase Tests](#6-data-integrity--firebase-tests)
7. [Input Validation Matrix](#7-input-validation-matrix)
8. [Authentication & Authorization Tests](#8-authentication--authorization-tests)
9. [Logging & Monitoring Validation](#9-logging--monitoring-validation)
10. [Risk Register](#10-risk-register)

---

## 1. Testing Strategy Overview

### Testing Layers

| Layer | Focus | Approach |
|-------|-------|----------|
| **L1 — Auth & Session** | Login, CSRF, roles, session isolation | Manual + automated |
| **L2 — RBAC Enforcement** | Every endpoint checked for role guard | Automated sweep |
| **L3 — Input Validation** | Path injection, XSS, type coercion | Fuzzing + manual |
| **L4 — Business Logic** | Fees calculation, grades, payroll | Scenario-based |
| **L5 — Data Integrity** | Firebase read/write correctness | Snapshot comparison |
| **L6 — Integration** | Cross-module flows (fee->accounting) | End-to-end |
| **L7 — Performance** | Large datasets, concurrent users | Load simulation |
| **L8 — Security** | OWASP Top 10, API security | Penetration testing |

### Test Data Requirements

- **Minimum 2 schools**: 1 legacy (school_code-based), 1 SCH_ (new architecture)
- **Per school**: 5 classes, 2 sections each, 30 students/section, 10 staff
- **Exam data**: 2 exams with full marks, computed results
- **Fees data**: Mix of paid, partial, overdue students
- **Attendance data**: 2 months with P/A/L/T/H/V marks

---

## 2. Recommended Testing Order

Test in dependency order — foundational modules first, dependent modules after.

| Phase | Module | Priority | Reason |
|-------|--------|----------|--------|
| **P1** | Authentication & Session (Admin_login, MY_Controller) | CRITICAL | Everything depends on auth |
| **P2** | Super Admin Login & RBAC (Superadmin_login, MY_Superadmin_Controller) | CRITICAL | Platform admin gate |
| **P3** | Firebase Library & Common Model | CRITICAL | All data flows through these |
| **P4** | School Config & Class Management (School_config, Classes) | HIGH | Structural foundation |
| **P5** | Student Management (Sis, Student) | HIGH | Core entity for all modules |
| **P6** | Subject Management (Subjects, Academic) | HIGH | Needed by Exam/Result |
| **P7** | Attendance Module | HIGH | Independent, high-use |
| **P8** | Fees & Fee Management (Fees, Fee_management) | HIGH | Financial — zero-error tolerance |
| **P9** | Exam & Result (Exam, Result, Examination) | HIGH | Grade accuracy critical |
| **P10** | Accounting Module | HIGH | Financial ledger integrity |
| **P11** | Communication Module | MEDIUM | Cross-module triggers |
| **P12** | Operations (Library, Transport, Hostel, Inventory, Assets) | MEDIUM | Modular, independent |
| **P13** | HR & Payroll | MEDIUM | Complex but standalone |
| **P14** | Events & Academic Calendar | MEDIUM | Low-risk |
| **P15** | Super Admin Panel (Schools, Plans, Reports, Monitor, Backups, Debug, Migration) | HIGH | Platform management |
| **P16** | Public Endpoints (Online Admission, Backup Cron, Attendance API) | CRITICAL | No auth — highest attack surface |

---

## 3. Module-by-Module Test Plan

---

### 3.1 Authentication & Session (Admin_login.php, MY_Controller.php)

**Key Features:** Login, logout, session management, CSRF, role loading, subscription check, security headers

**Possible Failure Points:**
- Session fixation after login
- CSRF token mismatch on POST
- Rate limiting bypass via IP spoofing
- Subscription grace period miscalculation
- Legacy vs SCH_ school resolution failure
- Plain-text password upgrade failure

**Test Scenarios:**

| ID | Scenario | Steps | Expected Result |
|----|----------|-------|-----------------|
| AUTH-01 | Valid login | POST correct school_code + admin_id + password | Session created, redirected to dashboard, session contains school_name/admin_role/session_year |
| AUTH-02 | Invalid school code | POST non-existent school_code | Generic "Invalid credentials", no user enumeration |
| AUTH-03 | Invalid admin ID | POST valid school + wrong admin_id | Generic "Invalid credentials", timing matches AUTH-02 |
| AUTH-04 | Invalid password | POST valid school + valid admin + wrong password | Generic "Invalid credentials", failed attempt counter incremented |
| AUTH-05 | Account lockout (5 fails) | POST wrong password 5 times | 6th attempt returns "Account temporarily locked", AccessHistory/LockedUntil set |
| AUTH-06 | IP rate limit (20 fails) | POST 20 failed logins from same IP | 21st attempt returns rate limit error, RateLimit/Login/{ip} has count=20 |
| AUTH-07 | Lockout expiry | Wait 30 minutes after lockout | Login succeeds, LockedUntil cleared |
| AUTH-08 | Session regeneration | Login, capture session ID, verify new ID after login | Session ID changes on successful login |
| AUTH-09 | CSRF on POST | POST without CSRF token | 403 Forbidden |
| AUTH-10 | CSRF via header | POST with X-CSRF-Token header (no body token) | Request accepted |
| AUTH-11 | Logout clears session | Click logout, try accessing dashboard | Redirected to login, all SESSION_KEYS cleared |
| AUTH-12 | Legacy school login | Login with legacy school (code "10004", school "Demo") | parent_db_key = "10004", school_id = "Demo" |
| AUTH-13 | SCH_ school login | Login with SCH_XXXXXX school | parent_db_key = school_id = "SCH_XXXXXX" |
| AUTH-14 | Subscription expired | Login to school with expired subscription + no grace | Rejected or warning shown, access restricted |
| AUTH-15 | Subscription grace period | Login during grace window | Access granted with warning banner |
| AUTH-16 | Plain-text password upgrade | Login with legacy plain-text password | Login succeeds, password auto-hashed to bcrypt in Firebase |
| AUTH-17 | Password > 72 chars | Submit 100-char password | Rejected (bcrypt limit guard) |
| AUTH-18 | Firebase path injection in school_code | Send `../Admin` as school_code | Rejected by _is_safe_id() regex |
| AUTH-19 | Firebase path injection in admin_id | Send `test/../../` as admin_id | Rejected by _is_safe_id() |
| AUTH-20 | Security headers present | Inspect response headers | X-Frame-Options: DENY, X-Content-Type-Options: nosniff, X-XSS-Protection: 1 |
| AUTH-21 | Concurrent session | Login from two browsers | Both sessions valid (no single-session enforcement) |
| AUTH-22 | Session tamper — school_name | Modify session school_name to another school | Blocked by _is_safe_segment() validation |
| AUTH-23 | Role-based access | Login as Teacher, access Admin-only route | 403 or redirect to unauthorized page |

---

### 3.2 Super Admin Authentication (Superadmin_login.php, MY_Superadmin_Controller.php)

**Key Features:** Separate session from school admin, independent CSRF tokens, rate limiting, role enforcement

**Possible Failure Points:**
- SA session bleeds into school admin session
- CSRF token shared between panels
- "Our Panel" developer access bypass

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| SA-01 | Valid SA login | sa_id, sa_name, sa_role, sa_school set in session |
| SA-02 | SA CSRF is independent | SA CSRF token differs from school admin CSRF |
| SA-03 | Non-Super Admin role rejected | Admin with Role="Teacher" cannot login to SA panel |
| SA-04 | "Our Panel" developer access | Developer with school_id="Our Panel" can login regardless of role |
| SA-05 | SA rate limiting | 10 failed SA logins from same IP → locked for 30 min |
| SA-06 | SA logout clears SA session only | SA logout doesn't destroy school admin session |
| SA-07 | School admin cannot access SA routes | Navigate to /superadmin/dashboard without SA session → redirect to SA login |
| SA-08 | SA session has separate CSRF | POST to SA endpoint with school-admin CSRF token → 403 |
| SA-09 | Account status check | SA admin with Status="Inactive" → rejected |

---

### 3.3 Firebase Library (Firebase.php, Common_model.php)

**Key Features:** get, set, update, delete, push, shallow_get, copy, exists, file upload/download

**Possible Failure Points:**
- Firebase unreachable (network timeout)
- Null/empty path
- Large payload (>16MB Firebase limit)
- Concurrent writes to same path

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| FB-01 | get() existing path | Returns array/value |
| FB-02 | get() non-existent path | Returns null (not exception) |
| FB-03 | set() writes data | Data appears at path, returns true |
| FB-04 | update() merges data | Existing sibling keys preserved, returns true |
| FB-05 | delete() removes node | Node gone, returns true |
| FB-06 | push() generates unique key | Returns key string, data at path/{key} |
| FB-07 | shallow_get() returns keys only | Returns array of child keys, no deep data |
| FB-08 | Firebase timeout (simulate) | Returns null/false/[], logs error, no crash |
| FB-09 | Empty path argument | Logs error, returns null/false |
| FB-10 | Path with special chars | Blocked by safe_path_segment() before reaching library |
| FB-11 | Debug tracking (GRADER_DEBUG=true) | Operations logged to Debug_tracker with path, duration, result |
| FB-12 | Debug off (GRADER_DEBUG=false) | No debug overhead, no logging |
| FB-13 | Duplicate SDK instances | Both Firebase.php and Common_model.php initialize — verify no conflicts |
| FB-14 | File upload to Storage | File accessible via download URL |
| FB-15 | File upload > memory limit | Graceful error (not OOM crash) |

---

### 3.4 School Configuration (School_config.php)

**Key Features:** Profile, sessions, board, classes, sections, subjects, streams — 7-tab SPA

**Possible Failure Points:**
- Dual-write inconsistency (Config/Profile vs System/Schools)
- Delete section with enrolled students
- Invalid session year format
- Class/section path not matching Firebase structure

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| CFG-01 | Save school profile | Profile saved to Config/Profile AND System/Schools (dual-write) |
| CFG-02 | Save profile with invalid email | json_error("Invalid email address") |
| CFG-03 | Save profile with invalid phone | json_error, phone rejected |
| CFG-04 | Add new class | Class node created at `Schools/{school}/{session}/Class N/` |
| CFG-05 | Add section to class | Section node created with default max_strength |
| CFG-06 | Delete section with students | Rejected: "Section has enrolled students" |
| CFG-07 | Delete empty section | Section removed successfully |
| CFG-08 | Add academic session | New session year (YYYY-YY format) created |
| CFG-09 | Invalid session format | Rejected (must match `^\d{4}-\d{2}$`, YY = YYYY+1) |
| CFG-10 | Set active session | Config/ActiveSession updated, session_year in PHP session updated |
| CFG-11 | Save subjects | Subject codes auto-generated (numeric or 3-letter prefix) |
| CFG-12 | Delete subject with exam data | Should warn (verify cascade behavior) |

---

### 3.5 Student Management (Sis.php)

**Key Features:** Admission, profile, promotion, transfer certificate, documents, withdrawal, ID card, search, index rebuild

**Possible Failure Points:**
- Student ID collision on concurrent admissions
- Promotion to non-existent class/section
- TC issue for already-withdrawn student
- File upload path traversal
- Index rebuild on large dataset

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| SIS-01 | New student admission | Student profile at Users/Parents/{key}/{id}, roster entry at Class/Section/Students/List/{id} |
| SIS-02 | Duplicate student ID | Rejected or auto-incremented |
| SIS-03 | Admission with photo upload | Photo stored in Firebase Storage, URL in profile |
| SIS-04 | Edit student profile | Profile updated, History entry logged |
| SIS-05 | Promote students (batch) | Students moved to target class/section, profiles updated, history logged |
| SIS-06 | Promote to full section | Rejected: "Section capacity exceeded" |
| SIS-07 | Issue transfer certificate | TC record created, student status changed |
| SIS-08 | Cancel issued TC | TC cancelled, student status restored |
| SIS-09 | Withdraw student | Student marked as withdrawn, removed from active roster |
| SIS-10 | Upload document | Document at Users/Parents/{key}/{id}/Documents/{docKey} |
| SIS-11 | Delete document | Document removed from Firebase + Storage |
| SIS-12 | Search student by name | Returns matching students (partial match) |
| SIS-13 | Search with empty query | Returns empty array (not error) |
| SIS-14 | Student without email (optional) | Admission succeeds (email not required) |
| SIS-15 | Rebuild student index | SIS/Students_Index rebuilt from all class/section rosters |
| SIS-16 | Online admission form (PUBLIC) | Application submitted without authentication |
| SIS-17 | Online form spam (no rate limit) | *KNOWN GAP* — Multiple submissions from same IP accepted |
| SIS-18 | Online form XSS in student_name | Input sanitized, no script execution |

---

### 3.6 Class & Subject Management (Classes.php, Subjects.php)

**Key Features:** Class CRUD, section management, student transfer, subject assignment, timetable upload

**Possible Failure Points:**
- Transfer to same section
- Capacity overflow on transfer
- Subject code collision

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| CLS-01 | Fetch class grid | All classes with section counts, student counts |
| CLS-02 | Add section | Section created with max_strength |
| CLS-03 | Transfer student to different section | Student moved, profile Class/Section updated, roster synced |
| CLS-04 | Transfer to same section | Rejected with error |
| CLS-05 | Transfer exceeds capacity | Rejected with "Section full" message showing current/max |
| CLS-06 | Delete class with students | Should be prevented |
| CLS-07 | Upload timetable image | URL stored at Class/Section/Time_table |
| SUB-01 | Save subjects for class | Subjects stored at Subject_list/{classKey}/{code} |
| SUB-02 | Subject code auto-generation | Numeric class: "901", "902"; Text class: "NUR01", "NUR02" |
| SUB-03 | Delete subject | Removed from Subject_list |
| SUB-04 | Subjects for Nursery/LKG/UKG | 3-letter prefix codes generated correctly |

---

### 3.7 Attendance Module (Attendance.php)

**Key Features:** Student/staff attendance, bulk mark, settings, holidays, device API, analytics, individual report

**Possible Failure Points:**
- Attendance string corruption (wrong length)
- Month/year resolution for April-March academic year
- API key validation bypass
- Late time without T mark
- Device punch for wrong school

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| ATT-01 | Load student attendance grid | Students listed with month's attendance string parsed into day cells |
| ATT-02 | Mark single day (click cell) | Cell cycles V→P→A→L→T→H, dirty flag set |
| ATT-03 | Save attendance | Attendance strings written to Firebase, dirty flags cleared |
| ATT-04 | Bulk mark all present | All students get "P" for selected day |
| ATT-05 | Bulk mark holiday | All students get "H" for selected day |
| ATT-06 | Mark late (T) | Late dot appears, late time stored |
| ATT-07 | Attendance string length validation | String always equals daysInMonth, padded with V |
| ATT-08 | Invalid mark character injected | Replaced with 'V' (server-side validation) |
| ATT-09 | April attendance (year boundary) | Correct year resolved (April 2025 → 2025, March 2026 → 2026) |
| ATT-10 | Staff attendance mark | Staff attendance string at Staff_Attendance/{month year}/{staffId} |
| ATT-11 | Holiday management | Save/fetch holidays for school, dates validated YYYY-MM-DD |
| ATT-12 | Device registration | Device ID generated, API key returned (raw), hash stored |
| ATT-13 | Device API punch (valid key) | Punch recorded, student attendance updated |
| ATT-14 | Device API punch (invalid key) | 401 Unauthorized |
| ATT-15 | Device API punch (no rate limit) | *KNOWN GAP* — No throttle on failed API key attempts |
| ATT-16 | Analytics — class overview | Grade distribution, total P/A/L counts for class/section/month |
| ATT-17 | Analytics — individual report | Monthly breakdown with student name/class/section displayed |
| ATT-18 | Individual report — student name display | Person info banner shows name, ID, class/section |
| ATT-19 | Teacher marking own section only | *KNOWN GAP* — No server-side restriction (client-side only) |

---

### 3.8 Fees Module (Fees.php, Fee_management.php)

**Key Features:** Fee structure, class fees chart, fee counter, payment collection, receipt generation, discount, scholarship, refund, reminders, online payments

**Possible Failure Points:**
- Receipt number race condition (concurrent submissions)
- Negative fee amount
- Overpayment calculation error
- Class/section format mismatch ("9th 'A'" vs "Class 9th"/"Section A")
- Account book ledger double-entry error

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| FEE-01 | Set fee structure (monthly) | Fees saved at Accounts/Fees/Fees Structure/Monthly |
| FEE-02 | Set class fees chart | Fees saved per class/section at Classes Fees/{class}/{section} |
| FEE-03 | Lookup student by ID | Returns name, father_name, class (formatted as "9th 'A'") |
| FEE-04 | Fetch months (paid/unpaid) | Returns {April:0, May:1, ...} showing which months are paid |
| FEE-05 | Fetch fee details for selected months | Correct fee breakdown with school fees, transport, hostel components |
| FEE-06 | Submit full payment | Voucher created, months marked paid, receipt indexed, account book updated |
| FEE-07 | Submit partial payment | Correct fee allocation, remaining balance tracked |
| FEE-08 | Overpayment handling | Excess stored in Oversubmittedfees, carried forward |
| FEE-09 | Receipt number uniqueness | No duplicate receipt numbers under concurrent load |
| FEE-10 | Negative fee rejected | json_error returned |
| FEE-11 | Discount application | OnDemandDiscount set on student, totalDiscount accumulated |
| FEE-12 | Print receipt | Receipt page renders with school header, payment details |
| FEE-13 | Due fees report | Shows students with unpaid months |
| FEE-14 | Fee records (account book) | Daily/monthly ledger entries match submitted payments |
| FEE-15 | Scholarship award | Student fee reduced by scholarship amount |
| FEE-16 | Refund workflow | Create → Approve → Process (status transitions correct) |
| FEE-17 | Reminder sending | Reminder logged, due students filtered correctly |
| FEE-18 | Sibling detection | Students grouped by Father_name, 2+ required |
| FEE-19 | Accounting journal on payment | Dr Cash/Bank, Cr Fees Income — double-entry balanced |

---

### 3.9 Exam & Result Module (Exam.php, Result.php, Examination.php)

**Key Features:** Exam CRUD, template design, marks entry, grade computation, class result, report card, merit list, analytics, tabulation, cumulative, bulk compute

**Possible Failure Points:**
- Grade engine PHP/JS desync
- Marks exceeding component maxMarks
- Student not in roster but marks entered
- Ranking ties (competition ranking 1,1,3)
- Division by zero in percentage calculation
- Exam delete cascade (Templates, Marks, Computed, Cumulative)

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| EXM-01 | Create exam with schedule | Exam + schedule saved, per-section copies created |
| EXM-02 | Invalid exam name (special chars) | Rejected by regex `^[\w\s\-\.]{2,80}$` |
| EXM-03 | Start date > end date | Rejected |
| EXM-04 | Delete exam | Cascade: Templates, Marks, Computed, CumulativeConfig entries removed |
| RES-01 | Design mark template | Components saved at Results/Templates/{exam}/{class}/{section}/{subject} |
| RES-02 | Enter marks within range | Marks saved, total computed correctly |
| RES-03 | Enter marks exceeding maxMarks | Clamped to component maxMarks (server-side) |
| RES-04 | Enter marks for non-enrolled student | Skipped with warning (roster validation) |
| RES-05 | Compute class results | Grades assigned per grading scale, ranks computed (1,1,3 pattern) |
| RES-06 | All students same score | All get rank 1 (competition ranking) |
| RES-07 | Grade scale: Percentage | Correct percentage bands applied |
| RES-08 | Grade scale: A-F | Correct letter grades assigned |
| RES-09 | Grade scale: Pass/Fail | Only Pass or Fail assigned |
| RES-10 | Report card print | Standalone page with school header, marks table, grade legend |
| RES-11 | Batch report cards | Multi-page printable for all students in section |
| RES-12 | Student result (all exams) | Displays all exam results for one student |
| RES-13 | Stale flag set after marks save | _stale flag appears at Computed/{exam}/{class}/{section}/_stale |
| RES-14 | Merit list — class toppers | Top N students by total marks, correct ranking |
| RES-15 | Merit list — subject toppers | Top N per subject |
| RES-16 | Analytics — grade distribution | Correct count per grade band |
| RES-17 | Exam comparison | Two exams compared for same class, student deltas computed |
| RES-18 | Tabulation sheet | Full marks matrix with all subjects, printable |
| RES-19 | Bulk compute all sections | All sections computed, stale flags cleared |
| RES-20 | Cumulative (weighted exams) | Weighted average computed correctly across configured exams |
| RES-21 | Teacher sees only assigned classes | *KNOWN GAP* — No server-side filter on analytics endpoints |

---

### 3.10 Accounting Module (Accounting.php)

**Key Features:** Chart of accounts, journal entries, ledger, trial balance, P&L, balance sheet, receipt/payment, bank reconciliation

**Possible Failure Points:**
- Double-entry imbalance (Dr != Cr)
- Closing balance carry-forward error
- Date index corruption
- Account deletion with existing entries

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| ACC-01 | Create chart of account | Account at Accounts/ChartOfAccounts/{code} |
| ACC-02 | Create journal (Dr = Cr) | Journal saved, ledger updated, closing balances adjusted |
| ACC-03 | Create journal (Dr != Cr) | Rejected — debits must equal credits |
| ACC-04 | Trial balance | Sum of all Dr balances = Sum of all Cr balances |
| ACC-05 | P&L statement | Income - Expenses = Net Profit/Loss |
| ACC-06 | Balance sheet | Assets = Liabilities + Equity |
| ACC-07 | Delete account with entries | Prevented (referential integrity) |
| ACC-08 | Void/reverse journal | Original preserved, reversing entry created |
| ACC-09 | Closing balance after multiple journals | Running balance correct after sequential entries |
| ACC-10 | Fee payment journal integration | Auto-journal from Fees module matches manual ledger |

---

### 3.11 Communication Module (Communication.php)

**Key Features:** Messages, notices, circulars, templates, triggers, queue, logs, dual-write notices

**Possible Failure Points:**
- Dual-write inconsistency (Communication/Notices + legacy All Notices/Announcements)
- Bulk send exceeding recipient limit (500)
- Trigger automation fire_event failure
- Message body exceeding MAX_BODY_LENGTH (10000)

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| COM-01 | Send message to student | Message in sender's Outbox + recipient's Inbox |
| COM-02 | Create notice | Written to Communication/Notices AND legacy path |
| COM-03 | Create circular | Circular saved with attachments |
| COM-04 | Bulk send to 500 recipients | All recipients receive, queue processed |
| COM-05 | Bulk send to 501 recipients | Rejected (MAX_BULK_RECIPIENTS exceeded) |
| COM-06 | Message body > 10000 chars | Rejected |
| COM-07 | Trigger automation (fee_received) | fire_event creates queue entry for matching triggers |
| COM-08 | Template rendering | Placeholders ({student_name}, {amount}) replaced correctly |
| COM-09 | Message with HTML injection | Sanitized by _sanitize_html() — no script execution |

---

### 3.12 Operations — Library (Library.php)

**Key Features:** Books, categories, issue/return, fines, accounting integration

**Possible Failure Points:**
- Issue book when availability = 0
- Duplicate active issue for same student + book
- Fine calculation for overdue return
- Accounting journal for fine payment

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| LIB-01 | Add book (5 copies) | Book created, available_count = 5 |
| LIB-02 | Issue book to student | available_count decremented, issue record created |
| LIB-03 | Issue when available = 0 | Rejected: "Book not available" |
| LIB-04 | Duplicate issue (same student, same book, active) | Rejected |
| LIB-05 | Return on time | available_count incremented, no fine |
| LIB-06 | Return 5 days overdue (fine_per_day=10) | Fine = 50, fine record created |
| LIB-07 | Pay fine | Journal: Dr Cash 1010, Cr Late Fees 4060 |
| LIB-08 | Delete book with active issues | Prevented |
| LIB-09 | Delete category with books | Prevented |

---

### 3.13 Operations — Transport (Transport.php)

**Key Features:** Vehicles, routes, stops, student assignments, fee integration

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| TRN-01 | Add vehicle | Vehicle at Operations/Transport/Vehicles/{VH_id} |
| TRN-02 | Add route with stops | Route + ordered stops created |
| TRN-03 | Assign student to route + stop | Assignment created, transport fee component written to Fees/Student_Fee_Items |
| TRN-04 | Assign stop from different route | Rejected (stop must belong to selected route) |
| TRN-05 | Delete route with assigned students | Prevented |
| TRN-06 | Remove student assignment | Transport fee set to inactive (monthly_fee=0) |

---

### 3.14 Operations — Hostel (Hostel.php)

**Key Features:** Buildings, rooms, allocations, attendance, occupancy tracking, fee integration

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| HST-01 | Add building | Building at Operations/Hostel/Buildings/{BLD_id} |
| HST-02 | Add room (beds=4) | Room created, occupied=0 |
| HST-03 | Allocate student to room | occupied incremented, fee component written |
| HST-04 | Allocate to full room | Rejected: "Room is full" |
| HST-05 | Reduce beds below occupied | Rejected |
| HST-06 | Reallocate student to different room | Old room decremented, new room incremented |
| HST-07 | Checkout student | Status=CheckedOut, occupancy decremented, fee deactivated |
| HST-08 | Hostel attendance (session-scoped) | Attendance at {session}/Operations/Hostel/Attendance/{date}/{student} |
| HST-09 | Delete room with occupants | Prevented |

---

### 3.15 Operations — Inventory (Inventory.php)

**Key Features:** Items, categories, vendors, purchases, stock issues/returns, accounting

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| INV-01 | Add item (initial stock=0) | Item at Operations/Inventory/Items/{ITM_id} |
| INV-02 | Record purchase (qty=50) | Stock += 50, journal: Dr Inventory 1060, Cr Cash |
| INV-03 | Issue stock (qty=10) | Stock -= 10, issue record created |
| INV-04 | Issue more than available | Rejected: "Insufficient stock" |
| INV-05 | Return issued stock | Stock += return_qty, issue updated |
| INV-06 | Return more than issued | Rejected |
| INV-07 | Low stock alert | Items below min_stock flagged in report |
| INV-08 | Delete item with stock > 0 | Prevented |
| INV-09 | Delete vendor (no referential check) | *KNOWN GAP* — Succeeds even with purchase references |

---

### 3.16 Operations — Assets (Assets.php)

**Key Features:** Asset registry, assignments, maintenance, depreciation (SLM/WDV), accounting

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| AST-01 | Register asset | Asset created, purchase journal: Dr {account}, Cr Cash |
| AST-02 | Assign asset to staff | Assignment record created |
| AST-03 | Return assignment | Status=Returned (soft delete) |
| AST-04 | Delete asset with active assignment | Prevented |
| AST-05 | Compute monthly depreciation (SLM) | monthlyDep = purchaseCost * rate/100 / 12 |
| AST-06 | Compute monthly depreciation (WDV) | monthlyDep = currentValue * rate/100 / 12 |
| AST-07 | Depreciation floor (scrap value=1) | Current value never drops below 1 |
| AST-08 | Depreciation idempotency | Running twice in same month → second run skips |
| AST-09 | Depreciation journal | Dr Depreciation Exp 5050, Cr Accumulated Dep 1150 |
| AST-10 | Record maintenance | Maintenance record at Assets/Maintenance/{MNT_id} |

---

### 3.17 HR & Payroll (Hr.php)

**Key Features:** Departments, recruitment, leave (with LWP), salary structures, payroll generation, appraisals, accounting integration

**Possible Failure Points:**
- Leave balance negative
- LWP calculation wrong
- Payroll double-run for same month
- Salary deduction for absent days incorrect
- Expense journal debit != credit
- Leave overlap detection failure
- Carry-forward calculation error

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| HR-01 | Create department | Department at HR/Departments/{DEPT_id} |
| HR-02 | Duplicate department name | Rejected (case-insensitive check) |
| HR-03 | Delete department with staff | Prevented |
| HR-04 | Post job opening | Job at HR/Recruitment/Jobs/{JOB_id} |
| HR-05 | Process applicant: Applied → Shortlisted → Selected → Joined | Status transitions tracked |
| HR-06 | Create leave type (10 days/year, carry-forward=true) | Leave type at HR/Leaves/Types/{LT_id} |
| HR-07 | Allocate leave balances (new year) | All staff get 10 days, carry-forward from prior year (capped at max_carry) |
| HR-08 | Apply leave (5 days, balance=10) | Request created, status=Pending, paid_days=5, lwp_days=0 |
| HR-09 | Apply leave (15 days, balance=10) | paid_days=10, lwp_days=5 (LWP split) |
| HR-10 | Approve leave | Balance decreased by paid_days only (LWP doesn't consume balance) |
| HR-11 | Reject leave | No balance change |
| HR-12 | Cancel approved leave | paid_days restored to balance |
| HR-13 | Overlapping leave request | Rejected (overlap detection) |
| HR-14 | Set salary structure | Components saved at HR/Salary_Structures/{staff_id} |
| HR-15 | Payroll preflight check | Validates: no duplicate run, roster exists, salary structures present, accounts valid |
| HR-16 | Generate payroll (no absences) | Full basic + allowances, net salary computed |
| HR-17 | Generate payroll (5 absences in 25 working days) | Basic reduced by 5/25, proportional deduction |
| HR-18 | Generate payroll (LWP days) | Additional deduction for LWP |
| HR-19 | Duplicate month/year payroll | Rejected |
| HR-20 | Finalize payroll | Status locked to "Finalized", slips updated |
| HR-21 | Mark payroll paid | Payment journal: Dr Salary Payable 2020, Cr Cash/Bank |
| HR-22 | Delete draft payroll | Run + slips deleted, expense journal soft-deleted |
| HR-23 | Delete finalized payroll | Prevented (only Draft can be deleted) |
| HR-24 | Create appraisal (ratings 0-10) | Appraisal saved with all ratings clamped |
| HR-25 | Submit appraisal (Draft → Submitted) | Status transition recorded |
| HR-26 | Division by zero (0 working days) | Guarded: workingDays > 0 check |

---

### 3.18 Academic Module (Academic.php)

**Key Features:** Subject assignments, curriculum tracking, calendar, timetable, substitutes, conflict detection

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| ACD-01 | Assign subjects to class | Assignments saved with teacher, periods/week |
| ACD-02 | Save timetable period | Period cell saved, conflicts checked pre-save |
| ACD-03 | Teacher double-booking (same day/period) | Blocked: "Teacher already assigned to Class X" |
| ACD-04 | Add calendar event | Event at Academic/Calendar/{eventId} |
| ACD-05 | Event end_date < start_date | Rejected |
| ACD-06 | Record substitute | Substitute at Academic/Substitutes/{id} |
| ACD-07 | Curriculum topic tracking | Topics with status transitions (not_started → in_progress → completed) |
| ACD-08 | Copy subject assignments | Assignments cloned from source to target class |

---

### 3.19 Super Admin Panel (8 controllers)

**Key Features:** School onboarding, plan management, reports, monitoring, backups, debug, migration

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| SAP-01 | Onboard new school | System/Schools/{id}, Indexes/*, Users/Admin/* created |
| SAP-02 | Create subscription plan | Plan at System/Plans/{PLAN_id} |
| SAP-03 | Delete plan with active schools | Prevented |
| SAP-04 | Add payment record | Payment at System/Payments/{PAY_id}, subscription updated |
| SAP-05 | Expire check | Expired schools transitioned to grace/suspended |
| SAP-06 | Create manual backup | Backup file + metadata at System/Backups/{school}/{id} |
| SAP-07 | Restore backup (with confirmation) | Data restored, safety backup auto-created first |
| SAP-08 | Restore without "RESTORE" token | Rejected |
| SAP-09 | Download backup | File streamed with correct headers |
| SAP-10 | Toggle debug mode | Flag file created/removed, GRADER_DEBUG constant changes |
| SAP-11 | Schema validation | All required fields checked against Firebase data |
| SAP-12 | System health check | PHP, Firebase, disk, memory status returned |
| SAP-13 | Clear logs (by date) | Log entries for specified date removed |
| SAP-14 | Activity audit log | All SA actions logged to System/Logs/Activity/{date} |
| SAP-15 | Revenue report | Per-school revenue from paid payments |
| SAP-16 | Student summary report | Per-school student counts |
| SAP-17 | Migration analysis | All schools scanned, migration map at System/Migration/ |

---

### 3.20 Public Endpoints (Highest Attack Surface)

**Key Features:** Online admission form, backup cron, attendance device API

**Test Scenarios:**

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| PUB-01 | Online form submission (valid data) | Application created in CRM pipeline |
| PUB-02 | Online form — XSS in name | Input sanitized, no script execution in stored data |
| PUB-03 | Online form — SQL/NoSQL injection | No effect (Firebase, not SQL) |
| PUB-04 | Online form — 10KB name field | *KNOWN GAP* — No length limit on names |
| PUB-05 | Online form — duplicate phone | Rejected if active application exists |
| PUB-06 | Backup cron — valid key | Backup triggered for configured schools |
| PUB-07 | Backup cron — invalid key | 403 Forbidden |
| PUB-08 | Backup cron — empty key | Rejected immediately |
| PUB-09 | Backup cron — no IP whitelist | *KNOWN GAP* — Accepts from any IP |
| PUB-10 | Attendance API punch — valid key | Punch recorded |
| PUB-11 | Attendance API punch — invalid key | 401 Unauthorized |
| PUB-12 | Attendance API punch — no rate limit | *KNOWN GAP* — No throttle on failed attempts |
| PUB-13 | Attendance API — person_id from different school | *KNOWN GAP* — No cross-school validation |

---

## 4. Cross-Cutting Security Tests

### 4.1 CSRF Protection

| ID | Test | Expected |
|----|------|----------|
| SEC-01 | POST to any authenticated endpoint without CSRF token | 403 |
| SEC-02 | POST with expired CSRF token (>7200s) | 403 |
| SEC-03 | POST with CSRF in X-CSRF-Token header only | Accepted |
| SEC-04 | POST with CSRF in body only | Accepted |
| SEC-05 | jQuery AJAX auto-attaches CSRF | Verify in browser devtools |
| SEC-06 | fetch() auto-attaches CSRF | Verify via patched fetch |
| SEC-07 | FormData upload includes CSRF | Verify .has() check works |
| SEC-08 | SA panel uses separate CSRF | SA CSRF != school admin CSRF |

### 4.2 Firebase Path Injection

| ID | Test | Expected |
|----|------|----------|
| SEC-10 | Path with `/` character | Blocked by safe_path_segment |
| SEC-11 | Path with `.` character | Blocked |
| SEC-12 | Path with `#` character | Blocked |
| SEC-13 | Path with `$` character | Blocked |
| SEC-14 | Path with `[` or `]` | Blocked |
| SEC-15 | Path with Unicode characters | Blocked (only allows `[A-Za-z0-9 ',_\-]`) |
| SEC-16 | Empty path segment | Rejected |

### 4.3 XSS Prevention

| ID | Test | Expected |
|----|------|----------|
| SEC-20 | `<script>alert(1)</script>` in student name | Escaped in all views |
| SEC-21 | `"><img onerror=alert(1) src=x>` in any text field | Escaped |
| SEC-22 | JavaScript: URI in URL field | Not rendered as link |
| SEC-23 | Verify 735+ `htmlspecialchars()` usages cover all outputs | Audit confirms |

### 4.4 Role-Based Access (RBAC)

| ID | Test | Expected |
|----|------|----------|
| SEC-30 | Teacher accesses Admin-only endpoint | 403 |
| SEC-31 | Accountant accesses HR endpoint | 403 (no HR permission) |
| SEC-32 | Principal accesses all school endpoints | Allowed |
| SEC-33 | Super Admin accesses everything | Allowed |
| SEC-34 | Role case sensitivity | "admin" == "Admin" (strcasecmp used) |
| SEC-35 | Teacher marks attendance for unassigned class | *KNOWN GAP* — No server-side restriction |

### 4.5 Session Security

| ID | Test | Expected |
|----|------|----------|
| SEC-40 | Session cookie has HttpOnly flag | Yes (cookie_httponly=TRUE) |
| SEC-41 | Session cookie has SameSite=Lax | Yes |
| SEC-42 | Session regenerated on login | Yes (sess_regenerate_destroy=TRUE) |
| SEC-43 | Old session invalid after regeneration | Yes |
| SEC-44 | Session school_name tampered | Detected by _is_safe_segment() |

---

## 5. Performance & Scalability Tests

| ID | Test | Threshold | Notes |
|----|------|-----------|-------|
| PERF-01 | Dashboard load (50 students) | < 2s | Multiple Firebase reads |
| PERF-02 | Dashboard load (500 students) | < 5s | Large Users/Parents read |
| PERF-03 | Attendance grid load (60 students, 31 days) | < 3s | 1860 cells rendered |
| PERF-04 | Attendance save (30 dirty students) | < 3s | Batch write |
| PERF-05 | Fee counter lookup | < 1s | Single student read |
| PERF-06 | Class result compute (60 students, 8 subjects) | < 5s | Heavy computation |
| PERF-07 | Bulk compute all sections (10 classes x 2 sections) | < 30s | set_time_limit(120) |
| PERF-08 | Payroll generation (50 staff) | < 10s | Multiple reads + batch write |
| PERF-09 | Student index rebuild (300 students) | < 10s | Full scan |
| PERF-10 | SA dashboard (100 schools) | < 5s | Shallow reads preferred |
| PERF-11 | Backup creation (large school) | < 60s | JSON serialization of full tree |
| PERF-12 | Report card batch print (60 students) | < 10s | Multi-page render |
| PERF-13 | Concurrent fee submissions (5 simultaneous) | No duplicate receipts | Race condition test |
| PERF-14 | Firebase connection timeout | Graceful degradation | 15s timeout configured |

---

## 6. Data Integrity & Firebase Tests

| ID | Test | Expected |
|----|------|----------|
| DI-01 | Student admission creates profile + roster + index | All 3 locations consistent |
| DI-02 | Student transfer updates profile + source roster + target roster | All synchronized |
| DI-03 | Fee payment creates voucher + receipt_index + account_book | Triple-write consistent |
| DI-04 | Exam delete cascades Templates + Marks + Computed + Cumulative | All 4 paths cleared |
| DI-05 | Hostel allocation updates room occupancy + student fee | Both consistent |
| DI-06 | Payroll generation creates run + all slips + journal | Batch atomicity |
| DI-07 | Leave approval deducts balance correctly | Balance = old - paid_days |
| DI-08 | Leave cancellation restores balance | Balance = old + paid_days |
| DI-09 | Accounting journal: sum(Dr) == sum(Cr) | Always balanced |
| DI-10 | Depreciation batch: all assets updated in one call | Multi-path PATCH |
| DI-11 | School config dual-write | Config/Profile AND System/Schools both updated |
| DI-12 | Communication notice dual-write | Communication/Notices AND legacy path both updated |
| DI-13 | parent_db_key resolves correctly for legacy schools | "10004" for school code "10004" |
| DI-14 | parent_db_key resolves correctly for SCH_ schools | "SCH_XXXXXX" for school_id "SCH_XXXXXX" |

---

## 7. Input Validation Matrix

### Fields validated across all modules:

| Input Type | Validation Method | Modules Using |
|-----------|-------------------|---------------|
| Firebase IDs | `safe_path_segment()` — `[A-Za-z0-9 ',_\-]` | All |
| Dates | `preg_match('/^\d{4}-\d{2}-\d{2}$/')` + strtotime | HR, Events, Assets, Attendance |
| Email | `filter_var(FILTER_VALIDATE_EMAIL)` | Config, Admission |
| Phone | `preg_match('/^\+?\d{10,15}$/')` | Admission |
| Enum/Status | `in_array($val, $whitelist, true)` | All |
| Numeric (int) | `(int)` cast + range check | Fees, Inventory, HR |
| Numeric (float) | `floatval()` + > 0 check | Fees, Assets, HR |
| JSON payload | `json_decode()` + JSON_ERROR_NONE | Attendance, Marks, Payroll |
| Attendance marks | Char-by-char against `[P,A,L,H,T,V]` | Attendance |
| Passwords | Length 8-72, bcrypt hash | Login, Admin |
| File uploads | `is_uploaded_file()` check | SIS, Assets, Schools |

### Known Validation Gaps:

| Field | Module | Gap |
|-------|--------|-----|
| Student name (online form) | Sis.php | No length limit |
| Email (student profile) | Sis.php | Not validated |
| Free-text descriptions | All | No HTML escaping before storage (only on output) |
| Search queries | Multiple | No max length check |
| Exam schedule rows | Exam.php | Invalid rows silently skipped |

---

## 8. Authentication & Authorization Tests

### Endpoint Authorization Coverage

| Controller | Total Methods | Auth-Guarded | Gaps |
|-----------|--------------|-------------|------|
| Admin_login | 4 | 1 public (login), 3 guarded | None |
| Admin | 7 | All guarded | None |
| Classes | 15 | All guarded | None |
| Subjects | 8 | All guarded | None |
| Sis | 48 | 46 guarded, 2 PUBLIC (online_form, submit_online_form) | Intentional |
| Fees | 20 | All guarded | None |
| Fee_management | 42 | All guarded | None |
| Exam | 8 | All guarded | None |
| Result | 20 | All guarded | None |
| Examination | 11 | 2 guarded (bulk_compute, export), 9 NO role check | **HIGH RISK** |
| Attendance | 34+ | 28 guarded, 6 API-key-only | By design |
| Academic | 24+ | All guarded | None |
| Communication | 35+ | All guarded | None |
| Hr | 40+ | All guarded | None |
| Operations | 2 | All guarded | None |
| Library | 23 | All guarded | None |
| Transport | 14 | All guarded | None |
| Hostel | 14 | All guarded | None |
| Inventory | 13 | All guarded | None |
| Assets | 11 | All guarded | None |
| Accounting | ~30 | All guarded | None |
| Events | ~12 | All guarded | None |
| School_config | ~16 | All guarded | None |
| Health_check | ~16 | All guarded | None |
| Backup_cron | 1 | API key only (no session) | By design |
| Superadmin* (8) | ~80 | All guarded (SA session) | Some lack role granularity |

### Critical RBAC Gaps:

1. **Examination.php** — `get_merit_data()`, `get_analytics_data()`, `get_tabulation_data()` have NO server-side role check
2. **Attendance.php** — Teacher can mark any class/section (no server-side assignment check)
3. **Superadmin controllers** — No per-method role differentiation (all SA users can access everything)

---

## 9. Logging & Monitoring Validation

| ID | Test | Expected |
|----|------|----------|
| LOG-01 | Debug mode toggle | Flag file created/removed, GRADER_DEBUG constant updates |
| LOG-02 | Firebase operations logged (debug ON) | JSONL entries in debug_YYYY-MM-DD.log |
| LOG-03 | Firebase operations NOT logged (debug OFF) | No overhead, no log entries |
| LOG-04 | Slow operation detection (>500ms) | SLOW_OP entry in debug log |
| LOG-05 | SA activity log | All SA actions at System/Logs/Activity/{date} |
| LOG-06 | AJAX error logging | Client-side errors POSTed to log_ajax_error |
| LOG-07 | Schema validation check | Required fields verified against Firebase data |
| LOG-08 | Health check endpoints | PHP, Firebase, disk, memory status returned |
| LOG-09 | Payroll audit log | Payroll runs logged at System/Logs/Payroll/ |
| LOG-10 | Student history audit | Profile changes at Users/Parents/{key}/{id}/History/ |
| LOG-11 | SA login tracking | Login timestamp/IP at Users/Admin/.../AccessHistory |
| LOG-12 | Clear debug logs | Logs for specified date removed |
| LOG-13 | Debug panel tabs | Requests, Firebase Ops, Schema, Security, Performance, Live Schema all populated |

---

## 10. Risk Register

### Critical Risks (Fix Before Production)

| # | Risk | Impact | Likelihood | Module | Mitigation |
|---|------|--------|-----------|--------|------------|
| R1 | Encryption key hardcoded in config.php (in git) | Key compromise | HIGH | Core | Move to .env file |
| R2 | No rate limiting on public endpoints | Form spam, API brute force | HIGH | SIS, Attendance | Add rate limiter |
| R3 | No CSRF on online admission form | Cross-site form submission | MEDIUM | SIS | Add CSRF token |
| R4 | Examination analytics — no server-side role filter | Teacher sees all classes' data | MEDIUM | Examination | Add _require_role() to all methods |
| R5 | Attendance API — no cross-school validation | Punch recorded for wrong school | MEDIUM | Attendance | Validate person_id belongs to API key's school |
| R6 | Backup cron — single global key, no IP whitelist | Unauthorized backup trigger | MEDIUM | Backup_cron | Per-school keys + IP whitelist |
| R7 | Session match IP disabled | Session hijacking | LOW | Core | Enable sess_match_ip |

### Medium Risks (Fix in Sprint 1)

| # | Risk | Module | Mitigation |
|---|------|--------|------------|
| R8 | Receipt number race condition | Fees | Implement atomic increment with retry |
| R9 | Firebase operations missing try/catch | Fees, SIS, Fee_mgmt | Wrap all Firebase calls |
| R10 | Teacher can mark any class attendance | Attendance | Server-side assignment check |
| R11 | No Content-Security-Policy header | Core | Add CSP header |
| R12 | Duplicate Firebase SDK instances | Core | Singleton pattern |
| R13 | Grade engine PHP/JS desync risk | Result | Automated sync test |
| R14 | Room occupancy race condition | Hostel | Atomic increment |
| R15 | Stock/journal race condition | Inventory | Transaction wrapper |

### Low Risks (Backlog)

| # | Risk | Module | Mitigation |
|---|------|--------|------------|
| R16 | Subscription check 5-min window | Core | Reduce to 1-min or per-request |
| R17 | No 2FA for admin login | Auth | Implement TOTP |
| R18 | SA audit logging inconsistent | Superadmin | Add sa_log() to all SA methods |
| R19 | Vendor deletion has no referential check | Inventory | Add purchase reference check |
| R20 | Category depreciation rate not updateable after asset creation | Assets | Allow rate update with recalculation |

---

## Appendix A: Test Environment Checklist

- [ ] XAMPP/Apache running on localhost
- [ ] Firebase Realtime Database accessible (asia-southeast1)
- [ ] Service account JSON present and valid
- [ ] Debug mode toggle-able via flag file
- [ ] Test school "Demo" (legacy) configured with sample data
- [ ] Test school "SCH_TEST01" (new) configured with sample data
- [ ] Browser DevTools open for network/console monitoring
- [ ] Multiple browser sessions available (for concurrent tests)

## Appendix B: Test Accounts Required

| Account | School | Role | Purpose |
|---------|--------|------|---------|
| admin1 | Demo (legacy) | Admin | Full access testing |
| teacher1 | Demo | Teacher | Restricted access testing |
| accountant1 | Demo | Accountant | Fee/accounting testing |
| principal1 | SCH_TEST01 | Principal | New-school testing |
| sa_dev | Our Panel | Developer | SA full access |
| sa_admin | Our Panel | Super Admin | SA restricted testing |

## Appendix C: Severity Classification

- **CRITICAL**: System crash, data loss, security breach, financial error
- **HIGH**: Feature broken, incorrect calculation, access control bypass
- **MEDIUM**: UI issue with workaround, minor data inconsistency
- **LOW**: Cosmetic, non-functional, enhancement

---

**Total Test Scenarios: 310+**
**Estimated Manual Execution: 40-60 hours**
**Recommended: Prioritize P1-P8 (Critical Path) first — ~20 hours**

---

*End of QA Test Plan*
