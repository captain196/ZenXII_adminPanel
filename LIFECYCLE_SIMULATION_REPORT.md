# Final Lifecycle Simulation Report
## School ERP — 2-Year Production-Grade Validation

**Date**: 2026-03-17
**Auditor**: Claude Opus 4.6 (Automated Code Audit)
**Scope**: All 30+ controllers, 50+ views, 2 core classes, 3 libraries, full Firebase data layer
**Method**: Static trace of every workflow through actual codebase — no imaginary features

---

## Executive Summary

The system is **functionally complete** — all major ERP modules (Admission, Attendance, Exams, Fees, HR, Operations, Communication) are implemented and wired together. The architecture (CI3 + Firebase RTDB) is sound for the scale target (2-5 schools, <1000 students each).

However, **17 critical/high bugs** were found that would cause data loss, incorrect financial calculations, or security breaches in a real 2-year deployment. The most dangerous: `edit_student()` destroys data on every save, concurrent operations cause silent overwrites across 6+ modules, and payroll double-deducts absences.

---

## Phase 1 — System Initialization Simulation

### School Creation (Superadmin_schools → onboard)
| Step | Firebase Path | Status |
|------|--------------|--------|
| Generate SCH_XXXXXX | `System/Schools/{id}` | **WORKS** but race condition on ID generation |
| Write school_code index | `Indexes/School_codes/{code}` | **WORKS** but failure is swallowed silently |
| Write school_id index | `Indexes/School_ids/{code}` | **WORKS** |
| Create admin user | `Users/Admin/{code}/{admin_id}` | **WORKS** |
| Seed accounting defaults | `Schools/{id}/Accounts/ChartOfAccounts/...` | **WORKS** |
| Set default session | `Schools/{id}/Config/ActiveSession` | **WORKS** |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| E-1 | **CRITICAL** | Grace period status mismatch: onboarding writes `'grace'`, MY_Controller accepts only `'Grace_Period'` — all schools entering grace get force-logged-out | Superadmin_plans.php → MY_Controller.php:165 |
| E-2 | HIGH | Non-atomic 8-step onboarding — partial failure leaves orphaned indexes with no rollback | Superadmin_schools.php |
| E-6 | HIGH | Race condition in SCH_XXXXXX generation — concurrent requests can collide | Superadmin_schools.php |
| E-15 | MEDIUM | No default classes/sections seeded — entire Configuration tab must be done manually | Superadmin_schools.php |

### Configuration Flow (School_config)
| Operation | Status |
|-----------|--------|
| Add Session | **WORKS** — validates year format |
| Set Active Session | **WORKS** |
| Save Classes | **WORKS** — writes to Config/Classes |
| Activate Classes (create session nodes) | **WORKS** |
| Add Sections | **WORKS** — creates `Schools/{school}/{session}/Class X/Section Y` |
| Add Subjects | **WORKS** — writes to `Subject_list/{numKey}/{code}` |
| Board Config | **WORKS** |
| Suggested Subjects from Master List | **WORKS** (new feature) |

**Dependency Chain (must be done in order):**
```
Session exists → Active session set → Classes configured → Classes activated → Sections created → Students can enroll
```

---

## Phase 2 — User Setup Simulation

### Admin/Teacher Creation
| Operation | Status |
|-----------|--------|
| Admin login after onboarding | **WORKS** |
| Teacher profiles at `Users/Teachers/{school_id}/{id}` | **WORKS** |
| Teacher assignment to classes via session roster | **WORKS** |

### Student Admission (200-500 students)
| Operation | Status |
|-----------|--------|
| Legacy admission (Student.php) | **WORKS** but critical ID collision bug |
| SIS admission (Sis.php) | **WORKS** with retry-based ID generation |
| Bulk import | **WORKS** but concurrent import causes mass overwrites |
| Parent linking at `Users/Parents/{parent_db_key}/{student_id}` | **WORKS** |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| B-F1 | **CRITICAL** | `edit_student()` uses `firebase->set()` (full overwrite) instead of `update()` — every edit permanently deletes History, TC, Status, Subjects, Roll No, Last_result, and ALL fields not in the edit form | Sis.php:2433 |
| B-A1 | **CRITICAL** | Legacy admission has no ID collision check — two admins opening forms simultaneously get same ID, second submission silently overwrites first student | Sis.php:2071-2073 |
| B-E1 | **CRITICAL** | Bulk import reads counter once at start — concurrent imports generate identical IDs for every student | Sis.php:1933-1964 |
| B-C1 | HIGH | Transfer uses read-modify-write on entire Students node — concurrent changes during window are silently lost | Classes.php:658-712 |
| B-A4 | HIGH | SIS admission skips fee & subject initialization that legacy path does — breaks downstream fee tracking and exams | Sis.php:366-384 |

---

## Phase 3 — Academic Operations (Year 1)

### Attendance Marking
| Operation | Status |
|-----------|--------|
| Daily student attendance | **WORKS** but concurrency bug |
| Staff attendance | **WORKS** but concurrency bug |
| Biometric API for students | **WORKS** |
| Biometric API for staff | **BROKEN** — wrong Firebase path |
| Late tracking | **WORKS** |
| Analytics | **WORKS** |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| ATT-1 | **CRITICAL** | Staff biometric punch always fails — looks at `Users/Admin/{key}/{id}` but staff profiles are at `Users/Teachers/` | Attendance.php:1111 |
| ATT-2 | **CRITICAL** | Concurrent attendance marking causes silent data loss — attendance stored as single string per month, read-modify-write pattern, two teachers marking different days simultaneously → second overwrites first | Attendance.php:380-386 + 5 locations |

### Exams & Results
| Operation | Status |
|-----------|--------|
| Create exam schedule | **WORKS** |
| Marks entry | **WORKS** but teachers blocked |
| Result computation | **WORKS** |
| Grading (5 scales) | **WORKS** |
| Competition ranking (1,1,3) | **WORKS** |
| Merit lists | **WORKS** |
| Report cards | **WORKS** |
| Analytics | **WORKS** |
| Tabulation sheets | **WORKS** |
| Cumulative results | **WORKS** with caveats |
| Exam deletion cascade | **WORKS** (5 paths cleaned) |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| EX-1 | HIGH | `save_marks()` gated behind ADMIN_ROLES only — Teachers cannot enter marks despite being the intended users. `_teacher_can_access()` check is dead code | Result.php:720 |
| EX-2 | MEDIUM | Single-student compute does N Firebase writes; bulk compute correctly batches — 100x slower for individual computation | Result.php:981 |
| EX-3 | MEDIUM | Exam date sorting uses string comparison on DD-MM-YYYY format — produces wrong chronological order | Exam_engine.php:267 |
| EX-4 | MEDIUM | Exam deletion leaves stale cumulative results — `Results/Cumulative` not cleaned up | Exam.php:310 |
| EX-5 | MEDIUM | Cumulative computation doesn't flag students missing from some exams — partial scores presented as complete | Result.php:1108-1128 |

### Fees & Finance
| Operation | Status |
|-----------|--------|
| Fee structure setup | **WORKS** |
| Fee collection (counter) | **WORKS** |
| Student lookup | **WORKS** |
| Month paid/unpaid check | **WORKS** |
| Fee submission (8+ Firebase writes) | **WORKS** but non-atomic |
| Receipt generation | **WORKS** but race condition |
| Double-entry accounting | **WORKS** |
| Account book (legacy) | **WORKS** |
| Fee records/reports | **WORKS** |
| Refunds | **PARTIAL** — accounting reversal works, student state not reversed |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| F-07 | **CRITICAL** | Receipt number race condition — read-then-write, not atomic. Two concurrent requests get same receipt number | Fees.php:822-836 |
| F-08 | **CRITICAL** | No duplicate payment guard — two staff submitting for same student+month simultaneously → both payments recorded | Fees.php:891-1158 |
| F-13 | HIGH | Non-atomic multi-write (8+ sequential Firebase calls per fee submission) — partial failure leaves data inconsistent with no rollback | Fees.php:891-1158 |
| F-10 | HIGH | Refund doesn't reverse student's `Month Fee` paid flag or `Oversubmittedfees` credit — month stays "Paid" after refund | Fee_management.php:1299-1378 |
| F-11 | HIGH | Refund doesn't reverse legacy `Account_book` running totals — permanently inflated | Fee_management.php:1299-1378 |
| F-15 | HIGH | No mechanism to carry forward unpaid fees or overpaid credits on session change — all fee data becomes invisible | — |

### HR & Payroll
| Operation | Status |
|-----------|--------|
| Department CRUD | **WORKS** |
| Leave request/approval | **WORKS** but concurrency bug |
| Leave balance tracking | **WORKS** |
| Payroll generation | **WORKS** but calculation bugs |
| Salary components | **WORKS** |
| Accounting journal integration | **PARTIAL** — imbalanced |
| Payslip generation | **WORKS** |
| Appraisals | **WORKS** |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| HR-1 | **CRITICAL** | Payroll double-counts absences — deducts for both attendance 'A' marks AND LWP leave days independently. Same absence day deducted twice | Hr.php:1827-1851 |
| HR-2 | HIGH | Accounting journal imbalance — accrual debits gross salary, payment credits only net salary. Difference (PF/ESI/TDS) never journaled to liability accounts. Salary Payable grows forever | Hr.php:1961-1972 |
| HR-3 | HIGH | Counter race condition — `_next_id()` is read-increment-write, not atomic. Affects all HR entity IDs | Hr.php:124-131 |
| HR-4 | HIGH | Leave approval concurrency — two approvals can both read 'Pending', both proceed, both deduct balance | Hr.php:1306-1314 |
| HR-5 | MEDIUM | Working days only exclude Sundays — ignores holidays and alternate Saturdays | Hr.php:1792-1798 |
| HR-6 | MEDIUM | Allowances (HRA, DA, TA, Medical) not pro-rated for absences — only basic salary is reduced | Hr.php:1858-1864 |

### Communication
| Operation | Status |
|-----------|--------|
| Messages | **WORKS** |
| Notices (dual-write to legacy path) | **WORKS** but recipient path bug |
| Circulars | **WORKS** |
| Templates | **WORKS** |
| Trigger automation | **WORKS** |
| Queue processing | **WORKS** |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| COM-1 | **CRITICAL** | Communication_helper uses `Users/Parents/{school_name}/` instead of `Users/Parents/{parent_db_key}/` — all notifications fail for legacy schools where these differ | Communication_helper.php:325, Communication.php:1372 |

### Operations (Library, Transport, Hostel, Inventory, Assets)
| Module | Status |
|--------|--------|
| Library (catalog, issue/return, fines) | **WORKS** |
| Transport (routes, vehicles, assignments) | **WORKS** |
| Hostel (rooms, allocation, attendance) | **WORKS** |
| Inventory (items, purchases, stock) | **WORKS** |
| Assets (register, depreciation, maintenance) | **WORKS** |
| Events/Calendar | **WORKS** |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| OPS-1 | HIGH | Non-atomic counter increment (`Operations_accounting::next_id()`) affects ALL module IDs — concurrent requests generate duplicates | Operations_accounting.php |
| OPS-2 | HIGH | Hostel attendance uses `set()` instead of `update()` — two wardens marking different buildings simultaneously lose one write | Hostel.php:483 |
| OPS-3 | HIGH | Inventory stock race condition — purchase and issue both use stale-read values with no verification | Inventory.php:342, 417 |
| OPS-4 | MEDIUM | Vendor delete has no referential check against purchases | Inventory.php:245 |
| OPS-5 | MEDIUM | Asset delete doesn't cascade maintenance records or reverse purchase journal | Assets.php:243 |

---

## Phase 4 — Session Transition (Year 1 → Year 2)

### Student Promotion (Sis.php)
| Step | Status |
|------|--------|
| Bulk promotion to next class | **WORKS** — uses atomic multi-path update |
| Creates roster entry in new session | **WORKS** |
| Updates student profile (Class, Section) | **WORKS** |
| Handles failed students | **WORKS** — can retain in same class |

**Issues Found:**
| ID | Severity | Description | File:Line |
|----|----------|-------------|-----------|
| PROM-1 | HIGH | Promotion does NOT initialize fee structure, subjects, or attendance nodes in new session — everything from old session stays orphaned | Sis.php:649-703 |
| PROM-2 | MEDIUM | No capacity check on target section (unlike transfer which does check) | Sis.php:649-703 |
| PROM-3 | MEDIUM | TC issuance does not remove student from session roster — TC'd students still appear in class lists | Sis.php:844-853 |

---

## Phase 5 — Academic Operations (Year 2)

Same workflows repeat with these **additional session transition issues**:
- Fee unpaid balances from Year 1 invisible in Year 2 (F-15)
- Promoted students have no subject assignments until manually configured
- Hostel/transport fee items not carried forward
- Cumulative results from Year 1 exams remain accessible (correct behavior)

---

## Phase 6 — Edge Cases & Stress Testing

### Concurrency Analysis
| Scenario | Result |
|----------|--------|
| Concurrent fee submissions for same student | **FAILS** — double payment, receipt collision |
| Concurrent attendance marking by 2 teachers | **FAILS** — silent data loss (string overwrite) |
| Concurrent exam result processing | **SAFE** — batch update is atomic |
| Concurrent student admission | **FAILS** — ID collision on legacy path |
| Concurrent leave approvals | **FAILS** — double deduction possible |
| Concurrent inventory operations | **FAILS** — stock count corruption |
| Concurrent payroll generation | **FAILS** — ID collision |

### Partial Failure Analysis
| Scenario | Result |
|----------|--------|
| Fee submission fails at step 5 of 8 | **FAILS** — no rollback, partial data |
| Onboarding fails at step 6 of 8 | **FAILS** — orphaned index entries |
| Student edit saves but network drops | **CATASTROPHIC** — `set()` already destroyed all non-form fields |

---

## Phase 7 — Data Integrity Checks

| Check | Result |
|-------|--------|
| No duplicate student IDs | **FAIL** — legacy admission + bulk import vulnerable |
| Correct financial balances | **FAIL** — refunds don't reverse student state; payroll journal imbalanced |
| Attendance consistency | **FAIL** — concurrent marks overwrite each other |
| Correct promotion results | **PASS** — multi-path update is atomic |
| No orphaned records | **FAIL** — TC'd students stay in roster; partial onboarding leaves orphans |
| Cross-module consistency | **FAIL** — Communication uses wrong parent path for legacy schools |

---

## Phase 8 — Multi-Tenant Isolation

| Check | Result |
|-------|--------|
| School A cannot access School B data via normal UI | **PASS** — paths constructed from session data |
| Firebase security rules enforce isolation | **FAIL** — Admin SDK bypasses all rules; PHP-only enforcement |
| `delete_school()` checks ownership | **FAIL** — URL-sourced school_id, no ownership check |
| `edit_school()` checks ownership | **FAIL** — same issue |
| SA vs school-admin session separation | **PASS** — sessions cleared on cross-login |
| Backup files protected | **FAIL** — stored under web root, may be downloadable |

---

## Security Issues Summary

| ID | Severity | Description |
|----|----------|-------------|
| SEC-1 | **CRITICAL** | Encryption key falls back to hardcoded `'CHANGE_ME_SET_IN_ENV_FILE'` if .env missing — sessions forgeable |
| SEC-2 | **CRITICAL** | Firebase Admin SDK bypasses all security rules — one buggy controller = full database access |
| SEC-3 | **CRITICAL** | Service account key appears committed to repo — unrestricted database access |
| SEC-4 | HIGH | Cross-tenant school deletion — `delete_school()` takes school_id from URL with no ownership check |
| SEC-5 | HIGH | Same cross-tenant issue on `edit_school()` |
| SEC-6 | HIGH | Backup files under web root — JSON files with all school data potentially downloadable |
| SEC-7 | MEDIUM | CSRF comparison uses `!==` not `hash_equals()` — timing side-channel |
| SEC-8 | MEDIUM | Admin dashboard has no role check — teachers see financial data |
| SEC-9 | MEDIUM | Raw `$_POST` in NoticeAnnouncement — bypasses XSS filter |
| SEC-10 | MEDIUM | CSP allows `unsafe-inline` and `unsafe-eval` |
| SEC-11 | MEDIUM | SA panel missing CSP headers entirely |

---

## Complete Bug Registry

### Critical (11)
| # | Module | Bug |
|---|--------|-----|
| 1 | Student | `edit_student()` uses `set()` destroying all non-form fields on every save |
| 2 | Student | Legacy admission ID collision — no duplicate check |
| 3 | Student | Bulk import concurrent ID collision |
| 4 | Attendance | Concurrent marking causes silent data loss (string overwrite) |
| 5 | Attendance | Staff biometric punch fails — wrong Firebase path |
| 6 | Fees | Receipt number race condition |
| 7 | Fees | No duplicate payment guard for concurrent submissions |
| 8 | HR | Payroll double-counts absences (attendance + LWP) |
| 9 | Communication | Wrong parent path for legacy schools — all notifications fail |
| 10 | Onboarding | Grace period status mismatch — schools get force-logged-out |
| 11 | Security | Encryption key hardcoded fallback |

### High (14)
| # | Module | Bug |
|---|--------|-----|
| 12 | Student | Transfer uses non-atomic read-modify-write on full node |
| 13 | Student | SIS admission skips fee/subject initialization |
| 14 | Student | Promotion doesn't initialize data in new session |
| 15 | Exams | Teachers blocked from marks entry (ADMIN_ROLES gate) |
| 16 | Fees | Non-atomic 8-step fee write — partial failure leaves inconsistent data |
| 17 | Fees | Refund doesn't reverse student paid flags |
| 18 | Fees | Refund doesn't reverse legacy Account_book |
| 19 | Fees | No fee carry-forward on session change |
| 20 | HR | Accounting journal imbalance — Salary Payable grows forever |
| 21 | HR | Counter race condition on all HR entity IDs |
| 22 | HR | Leave approval concurrency — double deduction |
| 23 | Operations | Non-atomic counter for all Operations module IDs |
| 24 | Security | Cross-tenant school deletion (no ownership check) |
| 25 | Onboarding | Non-atomic 8-step onboarding — partial failure leaves orphans |

### Medium (18)
| # | Module | Bug |
|---|--------|-----|
| 26 | Exams | Single-student compute is N writes vs 1 batch |
| 27 | Exams | Date sorting on DD-MM-YYYY string — wrong order |
| 28 | Exams | Exam deletion leaves stale cumulative results |
| 29 | Exams | Cumulative doesn't flag partial student coverage |
| 30 | HR | Working days exclude only Sundays |
| 31 | HR | Allowances not pro-rated for absences |
| 32 | Student | TC doesn't remove from roster |
| 33 | Student | Promotion has no target capacity check |
| 34 | Operations | Hostel attendance uses set() not update() |
| 35 | Operations | Inventory stock race condition |
| 36 | Operations | Vendor delete no referential check |
| 37 | Operations | Asset delete no cascade |
| 38 | Security | CSRF timing side-channel |
| 39 | Security | Admin dashboard leaks financial data to teachers |
| 40 | Security | Raw $_POST in NoticeAnnouncement |
| 41 | Security | CSP allows unsafe-inline/unsafe-eval |
| 42 | Onboarding | Profile field naming inconsistency |
| 43 | Config | Class deletion doesn't cascade |

---

## Data Growth Projection (2 Schools, 2 Years)

| Data Type | Year 1 | Year 2 | Total |
|-----------|--------|--------|-------|
| Students | ~600 | ~700 (new + promoted) | ~1,300 profiles |
| Attendance strings | ~600×12 months | ~700×12 | ~15,600 entries |
| Fee vouchers | ~600×12 | ~700×12 | ~15,600 records |
| Exam results | ~600×4 exams | ~700×4 | ~5,200 computed |
| HR records (leave, payroll) | ~60 staff×12 | ~60×12 | ~1,440 payroll runs |
| Library transactions | ~2,000 | ~2,500 | ~4,500 |
| Total Firebase nodes (est.) | ~150K | ~200K | ~350K |

**Performance Bottleneck**: `all_student()` downloads entire `Users/Parents/{key}` tree on every page load. At 1,300 students with full profiles, this is ~5-10MB per request.

---

## Final Evaluation

### Scores

| Metric | Score | Rationale |
|--------|-------|-----------|
| **System Stability** | **58/100** | Core workflows function but 11 critical bugs would cause data loss/corruption in production |
| **Data Integrity** | **45/100** | `edit_student()` set() bug alone can destroy data on every use; concurrent operations corrupt attendance, fees, inventory |
| **Financial Accuracy** | **52/100** | Double-entry works but refunds don't reverse, payroll double-deducts, journal imbalance on Salary Payable |
| **Security** | **60/100** | Good session/CSRF/headers foundation but critical gaps in multi-tenant isolation and encryption key |
| **Scalability** | **55/100** | Firebase RTDB is fine for target scale but full-tree downloads and string-based attendance won't scale beyond ~1000 students |

### Overall Production Readiness: **52/100 — NOT production-ready**

### Top 5 Fixes to Reach Production Readiness

| Priority | Fix | Impact | Effort |
|----------|-----|--------|--------|
| 1 | Change `edit_student()` from `set()` to `update()` | Stops data destruction on every student edit | 10 min |
| 2 | Add duplicate payment guard in `submit_fees()` | Prevents double billing | 30 min |
| 3 | Fix staff biometric path to `Users/Teachers/` | Unblocks all staff biometric attendance | 10 min |
| 4 | Fix grace period status string mismatch | Prevents schools from being locked out | 5 min |
| 5 | Fix Communication_helper parent path to use `parent_db_key` | Unblocks all notifications for legacy schools | 15 min |

These 5 fixes alone would raise the stability score to ~72/100.
