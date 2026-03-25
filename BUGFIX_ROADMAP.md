# Priority Bug Fix Roadmap

**Date:** 2026-03-16
**Source:** QA_TEST_PLAN.md + Codebase Audit
**Total Issues:** 32
**Total Estimated Fix Time:** ~38-48 hours

---

## CRITICAL — Fix Immediately (Before Any Production Deploy)

> These are security vulnerabilities or data integrity risks that could result in breach, data loss, or financial error if exploited.

---

### C-01. Encryption Key Hardcoded in Source Control

**File:** `application/config/config.php:55`
**Risk:** Anyone with repo access (or git history) has the session encryption key. All session cookies can be forged.
**Current Code:**
```php
$config['encryption_key'] = 'd1d4f05775cd80b86a38bb3673bb6d064c006c5588214e76bb7df69b64540957';
```

**Fix:**
1. Create `.env` file (git-ignored) with `ENCRYPTION_KEY=<new_random_key>`
2. Load via `$config['encryption_key'] = getenv('ENCRYPTION_KEY') ?: die('Missing ENCRYPTION_KEY');`
3. Rotate the key (generate new one) since the old one is in git history
4. Add `.env` to `.gitignore`

**Est. Time:** 30 min
**Impact:** Session forgery, full account takeover

---

### C-02. Examination Analytics — No Server-Side Role Check on 6 AJAX Endpoints

**File:** `application/controllers/Examination.php`
**Risk:** Any authenticated user (including Teacher) can craft POST requests to `get_merit_data`, `get_analytics_data`, `get_exam_comparison`, `get_tabulation_data` and see ALL classes' results — not just their assigned classes. Pages `index()`, `merit_list()`, `analytics()`, `tabulation()` also lack method-level role checks.
**Current State:** Only `bulk_compute()` and `export_merit_list()` have `_require_role()`. The other 9 methods have zero role enforcement.

**Fix:** Add `_require_role()` to all public methods + add `_teacher_can_access()` filter for Teacher role on data endpoints:
```php
public function get_merit_data()
{
    $this->_require_role(self::VIEW_ROLES, 'view_merit');
    // ... existing code ...
    // For teachers, filter sections via _teacher_can_access()
}
```
Apply to: `index()`, `merit_list()`, `analytics()`, `tabulation()`, `get_merit_data()`, `get_analytics_data()`, `get_exam_comparison()`, `get_tabulation_data()`

**Est. Time:** 1.5 hours
**Impact:** Unauthorized data access — teachers see all classes' exam results

---

### C-03. No Rate Limiting on Public Endpoints (Online Form + Attendance API)

**File:** `application/controllers/Sis.php:3172` (`submit_online_form`)
**File:** `application/controllers/Attendance.php:1759` (`_validate_api_key`)
**Risk:** Attackers can:
- Spam thousands of admission applications (Firebase quota exhaustion + data pollution)
- Brute-force attendance API keys (no throttle on failed `_validate_api_key` calls)

**Fix:** Add Firebase-based rate limiter (same pattern as login rate limit):
```php
// In submit_online_form():
$ip = $this->input->ip_address();
$rateKey = "RateLimit/PublicForm/" . str_replace(['.', ':'], '-', $ip);
$hits = (int)($this->firebase->get($rateKey . '/count') ?? 0);
if ($hits >= 10) { // 10 submissions per 15 min per IP
    return $this->json_error('Too many submissions. Please try again later.');
}
$this->firebase->update($rateKey, ['count' => $hits + 1, 'window' => time() + 900]);

// In _validate_api_key() — add failed attempt tracking:
$rateKey = "RateLimit/API/" . str_replace(['.', ':'], '-', $ip);
// Same pattern: 20 failed attempts per 15 min → block
```

**Est. Time:** 2 hours
**Impact:** Denial of service, Firebase quota exhaustion, API key brute force

---

### C-04. No CSRF Token on Public Admission Form

**File:** `application/controllers/Sis.php:3172` (`submit_online_form`)
**Risk:** Attacker can host a page on `evil.com` with a hidden form that auto-submits fake admission applications to your school's endpoint. No CSRF check = accepted.
**Current State:** The `submit_online_form()` method does not validate any CSRF token. The online form view likely doesn't include one either.

**Fix:**
1. In the `online_form()` view, embed a CSRF token:
   ```html
   <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
          value="<?= $this->security->get_csrf_hash() ?>">
   ```
2. CI3's global CSRF protection will then validate it automatically on POST.
3. Ensure `sis/submit_online_form` is NOT in `csrf_exclude_uris`.

**Est. Time:** 30 min
**Impact:** Cross-site form submission, data pollution

---

### C-05. Attendance API — No Cross-School Validation on person_id

**File:** `application/controllers/Attendance.php:1046`
**Risk:** A valid API key for School A can submit punches for students belonging to School B. The `person_id` is accepted without checking it belongs to the authenticated school.
**Current Code:**
```php
$personId = trim($input['person_id'] ?? '');
// ... no check that personId belongs to $auth['school_name']
```

**Fix:** After API key validation, verify student/staff exists in the authenticated school:
```php
$schoolName = $auth['school_name'];
if ($personType === 'student') {
    $exists = $this->firebase->get("Users/Parents/{$auth['parent_db_key']}/{$personId}/Name");
} else {
    $exists = $this->firebase->get("Users/Admin/{$auth['parent_db_key']}/{$personId}/Name");
}
if (!$exists) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Person not found in this school.']);
    return;
}
```

**Est. Time:** 1 hour
**Impact:** Cross-school data contamination via API

---

### C-06. Backup Cron — Single Global Key, No IP Whitelist

**File:** `application/controllers/Backup_cron.php:31`
**Risk:** Anyone who discovers the cron key can trigger full backups of ALL schools from any IP. Backup files contain complete student PII, admin credentials, and financial data.
**Current State:** Single `System/BackupSchedule/cron_key` gates the entire backup system. No IP restriction.

**Fix (2 layers):**
1. **IP whitelist** — Add server IP check at top of `run()`:
   ```php
   $allowed_ips = ['127.0.0.1', '::1', 'YOUR_SERVER_IP'];
   if (!in_array($this->input->ip_address(), $allowed_ips, true)) {
       $this->_out('error', 'Forbidden.', 403); return;
   }
   ```
2. **Key rotation** — Add `cron_key_expires` field, auto-regenerate keys on schedule save.
3. **Backup directory protection** — Verify `.htaccess Deny from all` exists in `application/backups/`.

**Est. Time:** 1.5 hours
**Impact:** Unauthorized access to full database exports

---

### C-07. Duplicate Firebase SDK Initialization

**File:** `application/libraries/Firebase.php` + `application/models/Common_model.php`
**Risk:** Both files independently initialize the Kreait Firebase Admin SDK + Storage client. This doubles memory usage, doubles connection overhead, and creates potential state inconsistency.
**Current State:** Two separate SDK instances, each loading the service account JSON and creating HTTP clients.

**Fix:** Make `Firebase.php` the single source. Refactor `Common_model.php` to use `$this->firebase` (already loaded by MY_Controller):
```php
// Common_model.php constructor:
public function __construct()
{
    parent::__construct();
    $CI =& get_instance();
    $this->firebase = $CI->firebase; // Use existing singleton
    $this->database = $CI->firebase->getDatabase();
}
```
Remove the duplicate SDK initialization from Common_model.

**Est. Time:** 2 hours
**Impact:** Memory waste, connection overhead, potential state bugs

---

**CRITICAL TOTAL: 7 issues | ~9 hours**

---

## HIGH PRIORITY — Fix Before Go-Live

> Functional bugs, data integrity risks, and security gaps that could cause incorrect data or degraded user experience in production.

---

### H-01. Teacher Can Mark Attendance for Any Class (No Server-Side Check)

**File:** `application/controllers/Attendance.php`
**Methods:** `save_student_attendance`, `mark_student_day`, `bulk_mark_student`
**Risk:** A Teacher can POST attendance data for classes/sections they are NOT assigned to. The sidebar only shows their classes, but direct POST requests bypass this.
**Fix:** Add `_teacher_can_access($classKey, $sectionKey)` check (already exists in MY_Controller) to all attendance write methods:
```php
if (!$this->_teacher_can_access("Class $class", "Section $section")) {
    return $this->json_error('Not authorized for this class/section.');
}
```

**Est. Time:** 1 hour

---

### H-02. Receipt Number Race Condition Under Concurrent Fee Submissions

**File:** `application/controllers/Fees.php:562,822`
**Risk:** Two simultaneous `submit_fees()` calls read the same `Receipt No`, both write receipt with same number.
**Current Code:** Simple read-increment-write without atomicity.
**Fix:** Implement optimistic locking with retry:
```php
private function _nextReceiptNo()
{
    $path = "Schools/{$this->school_name}/{$this->session_year}/Accounts/Fees/Receipt No";
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $current = (int)($this->firebase->get($path) ?? 0);
        $next = $current + 1;
        $this->firebase->set($path, $next);
        // Verify the write stuck
        $check = (int)$this->firebase->get($path);
        if ($check === $next) return $next;
        usleep(100000); // 100ms backoff
    }
    throw new \RuntimeException('Failed to acquire receipt number');
}
```

**Est. Time:** 1.5 hours

---

### H-03. Missing Content-Security-Policy Header

**File:** `application/core/MY_Controller.php`
**Risk:** No CSP header means inline script injection (stored XSS) would execute freely. Current headers include X-Frame-Options and X-XSS-Protection but not CSP.
**Fix:** Add to the security headers block in MY_Controller constructor:
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https://firebasestorage.googleapis.com;");
```

**Est. Time:** 1 hour (includes testing all pages for broken resources)

---

### H-04. Firebase Operations Missing try/catch in Financial Controllers

**Files:** `application/controllers/Fees.php` (0 try/catch), `application/controllers/Fee_management.php`
**Risk:** If Firebase becomes temporarily unreachable during a fee submission, the unhandled exception could leave a half-written voucher (money collected but no receipt, or receipt but no ledger entry).
**Fix:** Wrap critical multi-write operations in try/catch:
```php
try {
    $this->firebase->set($voucherPath, $voucher);
    $this->firebase->set($receiptIndexPath, $indexData);
    $this->firebase->update($accountBookPath, $ledgerEntry);
} catch (\Exception $e) {
    log_message('error', 'Fee submission failed: ' . $e->getMessage());
    return $this->json_error('Payment processing failed. Please try again.');
}
```
Apply to: `submit_fees()`, `save_updated_fees()`, `submit_discount()`, and all Fee_management write methods.

**Est. Time:** 2.5 hours

---

### H-05. Online Admission Form — No Input Length Limits

**File:** `application/controllers/Sis.php:3172`
**Risk:** Attacker submits 1MB student_name field. Data stored in Firebase, rendered in admin views, potentially crashing pages or exhausting storage.
**Fix:** Add length validation for all fields:
```php
$limits = ['student_name' => 100, 'parent_name' => 100, 'father_name' => 100,
           'address' => 300, 'notes' => 500, 'email' => 100, 'phone' => 15];
foreach ($limits as $field => $max) {
    if (strlen($data[$field] ?? '') > $max) {
        return $this->json_error("$field exceeds maximum length of $max characters.");
    }
}
```

**Est. Time:** 30 min

---

### H-06. Session Match IP Disabled

**File:** `application/config/config.php:66`
**Risk:** If session cookie is stolen (e.g., via XSS or network sniffing on non-HTTPS), attacker can use it from any IP.
**Current:** `sess_match_ip = FALSE`
**Fix:** `$config['sess_match_ip'] = TRUE;`
**Caveat:** May cause issues for users behind load balancers or VPNs that rotate IPs. Test with actual users first.

**Est. Time:** 15 min (change) + 1 hour (regression testing)

---

### H-07. Grade Engine PHP/JS Synchronization Risk

**Files:** `application/controllers/Result.php` (PHP), `application/views/result/marks_sheet.php` (JS)
**Also duplicated in:** `application/controllers/Examination.php` (PHP)
**Risk:** Three copies of the same grade computation logic must stay in sync manually. If one is updated and others aren't, students get different grades depending on which page they view.
**Fix:** Create a single authoritative grade config:
1. Store grade thresholds in Firebase at `Schools/{school}/Config/GradeScales/{scale}`
2. PHP methods read from config (single source of truth)
3. JS receives config via AJAX response (no hardcoded thresholds in JS)
4. Remove duplicate grade functions from Examination.php

**Est. Time:** 3 hours

---

### H-08. Exam Schedule — Invalid Rows Silently Skipped

**File:** `application/controllers/Exam.php` (create method)
**Risk:** Teacher creates exam with 10 schedule rows. 3 have invalid dates. Exam saves successfully but with only 7 rows. No feedback about which rows were dropped.
**Fix:** Collect skipped rows and return them in the response:
```php
$skipped = [];
// ... in the loop:
if (!$validDate) {
    $skipped[] = ['row' => $i + 1, 'reason' => 'Invalid date format'];
    continue;
}
// ... after loop:
$response = ['status' => 'success', 'exam_id' => $examId];
if (!empty($skipped)) {
    $response['warnings'] = $skipped;
    $response['message'] = count($skipped) . ' schedule row(s) were skipped due to errors.';
}
```

**Est. Time:** 1 hour

---

**HIGH TOTAL: 8 issues | ~11-12 hours**

---

## MEDIUM PRIORITY — Fix in Sprint 1

> Edge cases, minor security hardening, and non-critical data consistency improvements.

---

### M-01. Room Occupancy Race Condition (Hostel)

**File:** `application/controllers/Hostel.php`
**Risk:** Two concurrent allocations to the same room could both pass the capacity check and increment `occupied` past `beds`.
**Fix:** Read-check-write with a verification step after write:
```php
$room = $this->firebase->get($roomPath);
if (($room['occupied'] ?? 0) >= ($room['beds'] ?? 0)) {
    return $this->json_error('Room is full.');
}
$this->firebase->update($roomPath, ['occupied' => ($room['occupied'] ?? 0) + 1]);
// Verify
$verify = $this->firebase->get($roomPath . '/occupied');
if ($verify > ($room['beds'] ?? 0)) {
    // Rollback
    $this->firebase->update($roomPath, ['occupied' => $verify - 1]);
    return $this->json_error('Room filled by another user. Please retry.');
}
```

**Est. Time:** 1.5 hours

---

### M-02. Stock/Journal Write Not Atomic (Inventory)

**File:** `application/controllers/Inventory.php`
**Risk:** Purchase records stock update but journal creation fails. Stock incremented, no accounting entry. Financial records diverge from inventory.
**Fix:** Write journal first, then update stock. If journal fails, no stock change. If stock fails, soft-delete journal:
```php
$journalId = $this->ops_accounting->create_journal(...);
if (!$journalId) {
    return $this->json_error('Failed to create accounting entry.');
}
$updated = $this->firebase->update($itemPath, ['current_stock' => $newStock]);
if (!$updated) {
    $this->ops_accounting->delete_journal($journalId);
    return $this->json_error('Failed to update stock. Transaction rolled back.');
}
```

**Est. Time:** 2 hours

---

### M-03. Vendor Deletion Has No Referential Check (Inventory)

**File:** `application/controllers/Inventory.php`
**Risk:** Deleting a vendor orphans all purchase records that reference that vendor_id.
**Fix:** Before deletion, check if any purchases reference the vendor:
```php
$purchases = $this->firebase->get($purchasesPath);
if (is_array($purchases)) {
    foreach ($purchases as $p) {
        if (($p['vendor_id'] ?? '') === $vendorId) {
            return $this->json_error('Cannot delete vendor with existing purchase records.');
        }
    }
}
```

**Est. Time:** 30 min

---

### M-04. Leave Balance Race Condition (HR)

**File:** `application/controllers/Hr.php`
**Risk:** Two concurrent leave approvals for the same staff could both read balance=10, both approve 5 days, resulting in balance=5 instead of 0. Over-deduction.
**Fix:** Same read-verify pattern as M-01, or use a simple lock flag:
```php
$balancePath = "Schools/{$school}/HR/Leaves/Balances/{$year}/{$staffId}/{$typeId}";
$bal = $this->firebase->get($balancePath);
$current = (int)($bal['balance'] ?? 0);
if ($current < $paidDays) {
    $paidDays = $current;
    $lwpDays = $totalDays - $paidDays;
}
$this->firebase->update($balancePath, [
    'balance' => max(0, $current - $paidDays),
    'used' => (int)($bal['used'] ?? 0) + $paidDays
]);
```

**Est. Time:** 1.5 hours

---

### M-05. SA Audit Logging Inconsistent (Not All Methods Call sa_log)

**Files:** All `Superadmin_*.php` controllers
**Risk:** Some SA actions (viewing reports, refreshing stats) are not logged. If a rogue SA user modifies data, there's no audit trail.
**Fix:** Add `$this->sa_log()` calls to all write operations:
- `Superadmin.php: refresh_stats()`
- `Superadmin_plans.php: seed_defaults()`
- `Superadmin_monitor.php: clear_logs(), cleanup_old_logs()`
- `Superadmin_debug.php: toggle_debug(), clear_debug_logs()`

**Est. Time:** 1 hour

---

### M-06. Subscription Check Has 5-Minute Cache Window

**File:** `application/core/MY_Controller.php`
**Risk:** If a subscription is revoked, the user continues with full access for up to 5 minutes.
**Current:** `$_SESSION['sub_check_ts']` compared against `time() - 300`.
**Fix:** Reduce to 60 seconds for near-real-time enforcement:
```php
if (time() - ($this->session->userdata('sub_check_ts') ?: 0) > 60) {
    $this->_recheck_subscription();
}
```

**Est. Time:** 15 min

---

### M-07. Email Not Validated on Student Profile (Sis.php)

**File:** `application/controllers/Sis.php` (`save_admission`, `update_profile`)
**Risk:** Invalid emails stored, communication module sends to bad addresses, bounce rates increase.
**Fix:**
```php
if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    return $this->json_error('Invalid email address.');
}
```

**Est. Time:** 20 min

---

### M-08. Cookie SameSite Should Be Strict for Admin Panel

**File:** `application/config/config.php:63`
**Current:** `sess_samesite = 'Lax'` (allows cookies on top-level navigations from other sites)
**Fix:** `$config['sess_samesite'] = 'Strict';`
**Caveat:** Test that login redirect flows still work (e.g., clicking a link from email should still land on login page).

**Est. Time:** 15 min + testing

---

### M-09. Attendance API Key Lookup Hits Firebase on Every Request

**File:** `application/controllers/Attendance.php:1771`
**Risk:** Every device punch triggers a Firebase read to `System/API_Keys/{hash}`. With 50 devices punching every 30 seconds, that's 100 reads/minute just for auth.
**Fix:** Cache valid API keys in PHP session or APCu for 5 minutes:
```php
$cacheKey = "api_key_{$keyHash}";
$cached = apcu_fetch($cacheKey);
if ($cached !== false) return $cached;
$lookup = $this->firebase->get("System/API_Keys/{$keyHash}");
if (is_array($lookup) && !empty($lookup['school_name'])) {
    apcu_store($cacheKey, $lookup, 300); // 5-min cache
    return $lookup;
}
```
If APCu not available, use file-based cache.

**Est. Time:** 1 hour

---

### M-10. Backup File Permissions Too Permissive

**File:** `application/controllers/Superadmin_backups.php`
**Current:** Files created with mode `0750` (owner + group readable).
**Fix:** Change to `0700` (owner only):
```php
chmod($filePath, 0700);
```

**Est. Time:** 10 min

---

**MEDIUM TOTAL: 10 issues | ~8-9 hours**

---

## LOW PRIORITY — Backlog / Future Sprints

> Nice-to-haves, defense-in-depth improvements, and non-urgent optimizations.

---

### L-01. No Two-Factor Authentication (2FA) for Admin Login

**File:** `application/controllers/Admin_login.php`
**Risk:** Password-only auth is weak against credential stuffing.
**Fix:** Implement TOTP (Time-based One-Time Password) using a library like `OTPHP`:
1. Add `totp_secret` field to admin profile
2. Add setup flow (QR code display)
3. Add verification step after password check
**Est. Time:** 6-8 hours

---

### L-02. Session Storage on Disk (Not Redis/Memcached)

**File:** `application/config/config.php:61`
**Risk:** If web server is compromised, session files at `application/session/` are readable.
**Fix:** Migrate to Redis or database-backed sessions for production.
**Est. Time:** 2-3 hours

---

### L-03. No API Request Signing (Attendance Devices)

**File:** `application/controllers/Attendance.php`
**Risk:** API key sent in plaintext HTTP header. MITM can capture it (especially if not HTTPS).
**Fix:** Implement HMAC-SHA256 request signing:
- Device signs: `HMAC(timestamp + method + path + body, api_key)`
- Server verifies signature, checks timestamp within 5 min window
**Est. Time:** 4-5 hours

---

### L-04. Calendar Events Allow Date Overlaps (Academic)

**File:** `application/controllers/Academic.php`
**Risk:** Multiple events on the same date with no conflict warning.
**Fix:** Check for existing events on the same date and return a warning (not a block):
```php
$existing = $this->firebase->get($calendarPath);
$conflicts = array_filter($existing ?? [], fn($e) => ($e['start_date'] ?? '') === $startDate);
if (!empty($conflicts)) {
    $response['warnings'][] = count($conflicts) . ' event(s) already exist on this date.';
}
```
**Est. Time:** 45 min

---

### L-05. Substitute Teacher Not Validated Against Teacher List

**File:** `application/controllers/Academic.php`
**Risk:** Non-existent teacher ID can be assigned as substitute.
**Fix:** Verify substitute_teacher_id exists in session Teachers roster before saving.
**Est. Time:** 30 min

---

### L-06. Category Depreciation Rate Immutable After Asset Creation

**File:** `application/controllers/Assets.php`
**Risk:** If category rate changes, existing assets don't update.
**Fix:** Allow optional rate override per-asset, or add a "recalculate" button that re-reads category rates.
**Est. Time:** 1.5 hours

---

### L-07. Device ID Generation Uses MD5 (Non-Cryptographic)

**File:** `application/controllers/Attendance.php`
**Current:** `DEV_` + `md5(uniqid())` (8 chars)
**Fix:** Use `bin2hex(random_bytes(4))` instead — same length, cryptographically random.
**Est. Time:** 10 min

---

**LOW TOTAL: 7 issues | ~15-18 hours**

---

## Summary Dashboard

| Priority | Count | Est. Hours | Deploy Gate? |
|----------|-------|-----------|-------------|
| **CRITICAL** | 7 | 9 hrs | YES — block deploy |
| **HIGH** | 8 | 11-12 hrs | YES — block deploy |
| **MEDIUM** | 10 | 8-9 hrs | No — Sprint 1 |
| **LOW** | 7 | 15-18 hrs | No — Backlog |
| **TOTAL** | **32** | **~43-48 hrs** | |

## Recommended Fix Sequence

### Week 1: Critical + High (Block Deploy)
```
Day 1 (8h):  C-01, C-02, C-04, C-05, H-05, H-06
Day 2 (8h):  C-03, C-06, H-01, H-03, H-04 (partial)
Day 3 (5h):  C-07, H-04 (finish), H-02, H-08
Day 4 (2h):  H-07 (start — complex refactor)
```

### Week 2: Medium (Sprint 1)
```
Day 5 (8h):  H-07 (finish), M-01, M-02, M-04
Day 6 (4h):  M-03, M-05, M-06, M-07, M-08, M-09, M-10
```

### Week 3+: Low (Backlog)
```
L-01 through L-07 scheduled across future sprints
```

---

## Quick Wins (< 30 min each)

These can be fixed immediately with minimal risk:

| ID | Fix | Time |
|----|-----|------|
| C-01 | Move encryption key to .env | 30 min |
| C-04 | Add CSRF to online form | 30 min |
| H-05 | Add input length limits | 30 min |
| H-06 | Enable sess_match_ip | 15 min |
| M-06 | Reduce subscription cache to 60s | 15 min |
| M-07 | Add email validation | 20 min |
| M-08 | Change SameSite to Strict | 15 min |
| M-10 | Fix backup file permissions | 10 min |
| L-07 | Fix device ID generation | 10 min |

**Total quick wins: 9 fixes in ~3 hours**

---

*End of Bug Fix Roadmap*
