# Execution Pre-Flight — Claims Hybrid Fix (AdminUsers camelCase + Admin_login bridge)

**Status:** PRE-FLIGHT ONLY — no implementation · **Date:** 2026-06-06 · **HEAD:** `5e2499f` · branch `ank-yug_b1`
**Approved approach:** Hybrid — align AdminUsers writers to canonical camelCase **and** add a transitional snake_case reader bridge in Admin_login.

**Canonical schema:** `role`, `roleLabel`, `schoolId`, `schoolCode`, `parentDbKey` (camelCase).
**Rules honored:** Firestore canonical · no RTDB · no MongoDB · fail-closed preserved · camelCase canonical · bridge documented as transitional.

---

## 1. EXACT FILES
1. `application/controllers/AdminUsers.php` — 4 claim writer blocks → camelCase.
2. `application/controllers/Admin_login.php` — 2-key reader bridge + retirement doc-comment.

**NOT in this pre-flight (deferred follow-up C3):** `Ssa_reset.php`, `Admin.php` (same family, separate package). **Never touch:** `Staff.php`, `Sis.php` (mobile/snake consumers).
**No backfill** required — the reader bridge covers existing snake-claim admins.

---

## 2. EXACT METHODS & CLAIM MAPPINGS

### COMMIT 1 — AdminUsers.php (writer-side → camelCase)

**1a. `create_admin` (lines 494-499)**
Current:
```php
'role'          => $role,
'school_id'     => $this->school_id,
'school_code'   => $this->school_code,
'parent_db_key' => $this->parent_db_key,
```
Target:
```php
'role'        => $role,
'roleLabel'   => $role,
'schoolId'    => $this->school_id,
'schoolCode'  => $this->school_code,
'parentDbKey' => $this->parent_db_key,
```

**1b. `update_admin` (lines 659-664, role-change branch)**
Current → Target: identical mapping to 1a (`role`/`roleLabel` = `$role`; `school_id→schoolId`, `school_code→schoolCode`, `parent_db_key→parentDbKey`).

**1c. `_process_pending_syncs` (lines 192-197)**
Current:
```php
'role'          => $data['roleLabel'] ?? $data['role'] ?? '',
'school_id'     => $data['schoolId'] ?? $this->school_id,
'school_code'   => $data['schoolCode'] ?? $this->school_code,
'parent_db_key' => $data['parentDbKey'] ?? $this->parent_db_key,
```
Target:
```php
'role'        => $data['role'] ?? $data['roleLabel'] ?? '',
'roleLabel'   => $data['roleLabel'] ?? $data['role'] ?? '',
'schoolId'    => $data['schoolId'] ?? $this->school_id,
'schoolCode'  => $data['schoolCode'] ?? $this->school_code,
'parentDbKey' => $data['parentDbKey'] ?? $this->parent_db_key,
```

**1d. `reset_password` (lines 842-850)**
Current:
```php
'role'                 => (string) ($existing['Role'] ?? $existing['role'] ?? 'Admin'),
'school_id'            => $this->school_id,
'school_code'          => $this->school_code,
'parent_db_key'        => $this->parent_db_key,
'must_change_password' => true,
'password_reset_at'    => time(),
'password_reset_by'    => (string) ($this->admin_id ?? ''),
```
Target (rename the 3 authz keys + add roleLabel; **leave the must_change_password trio unchanged** — not read by Admin_login, reader casing unverified):
```php
'role'                 => (string) ($existing['Role'] ?? $existing['role'] ?? 'Admin'),
'roleLabel'            => (string) ($existing['Role'] ?? $existing['role'] ?? 'Admin'),
'schoolId'             => $this->school_id,
'schoolCode'           => $this->school_code,
'parentDbKey'          => $this->parent_db_key,
'must_change_password' => true,          // UNCHANGED — separate first-login gate
'password_reset_at'    => time(),        // UNCHANGED
'password_reset_by'    => (string) ($this->admin_id ?? ''),  // UNCHANGED
```

### COMMIT 2 — Admin_login.php (transitional reader bridge)

**Lines 235-236** (inside `_try_firebase_admin_login`)
Current:
```php
$schoolId   = (string) ($claims['schoolId']   ?? '');
$schoolCode = (string) ($claims['schoolCode'] ?? '');
```
Target:
```php
// TRANSITIONAL BRIDGE (2026-06-06): accept legacy snake_case claims emitted by
// pre-camelCase writers (Staff/Ssa_reset/Admin and historical AdminUsers accounts).
// RETIRE once all admin-side writers emit camelCase AND legacy claims are backfilled.
// Tracking: RBAC_CLAIMS_REMEDIATION_PACKAGE.md §13 (C3/C4).
$schoolId   = (string) ($claims['schoolId']   ?? $claims['school_id']   ?? '');
$schoolCode = (string) ($claims['schoolCode'] ?? $claims['school_code'] ?? '');
```
- `roleLabel` needs **no** bridge — `displayRole = roleLabel ?: roleRaw` (line ~311) already falls back to `role`.
- `parentDbKey`/`parent_db_key` needs **no** bridge — Admin_login does not read it (session `parent_db_key` derives from school-doc hydration, not claims). Writers emit `parentDbKey` for canonical correctness only.

---

## 3. EXPECTED DIFF SIZE
- AdminUsers.php: 4 blocks, ~22 LoC changed (net +4 for added `roleLabel` lines).
- Admin_login.php: 2 lines changed + 4 comment lines = ~6 LoC.
- **Total ~28 LoC across 2 files.** No new files. No data migration.

---

## 4. COMMIT BOUNDARIES
- **Commit 1 — `fix(adminusers): align Firebase custom claims to canonical camelCase`** (4 writers). Unblocks all NEW admin provisioning.
- **Commit 2 — `fix(admin-login): transitional snake_case claims reader bridge`** (2 keys + retirement doc). Covers EXISTING snake admins + Staff-provisioned web users; no backfill needed.
- Independently revertable. Commit 1 is the canonical fix; Commit 2 is the documented transitional bridge.

---

## 5. PRE-EXECUTION GATES (confirm at implementation; non-blocking expectations)
- G1. `must_change_password` reader: keep snake (do NOT rename) — confirmed not read by Admin_login; renaming would risk the first-login gate. ✅ decided.
- G2. `parentDbKey` is inert at login (no reader) — emit for canonical correctness; not on critical path. ✅ noted.
- G3. `saveStaff` writes `staff/{schoolId}_{adminId}` (`docId()` = `{schoolId}_{entityId}`, Firestore_service.php:164); login defaults status→Active if field absent. ✅ satisfied.
- G4. Working tree isolation: only AdminUsers.php + Admin_login.php change; AdminUsers.php already carries the prior RBAC cleanup edits — keep both sets coherent in one file, no unrelated hunks.

---

## 6. VERIFICATION SEQUENCE
1. `php -l` on both files → no syntax errors.
2. **Grep gates:**
   - AdminUsers.php 4 blocks emit `schoolId`/`schoolCode`/`roleLabel`/`parentDbKey`; zero `school_id`/`school_code`/`parent_db_key` remain in those blocks (must_change_password trio excepted).
   - Admin_login.php reads `schoolId ?? school_id` and `schoolCode ?? school_code`.
3. **Live ephemeral proof (authorized, with teardown):**
   - Create 1 admin via the fixed `create_admin` → inspect Auth claims = camelCase + roleLabel → real login via `admin_login/check_credentials` → session established, no `ADMIN_LOGIN_AUTHZ_MISSING` → **delete**.
   - Bridge check: 1 user with legacy snake claims → login succeeds via bridge → delete.
4. **Regression:** SA SSA login still works (unchanged); mobile `Api_auth` teacher/parent token still resolves `school_id` (snake writers untouched).
5. **Fail-closed:** user with NO schoolId in either casing → still rejected (`ADMIN_LOGIN_AUTHZ_MISSING`). Bridge must not weaken this.
6. **Resume RBAC acceptance matrix** — the 15 cells (C1/C2/C8) now runnable; execute per `RBAC_ACCEPTANCE_TEST_CHECKLIST.md`.

---

## 7. ROLLBACK PLAN
- Pure code change (Firebase Auth claims + reader logic); **no Firestore/RTDB data migration**.
- `git revert <Commit 2>` → reverts to strict camelCase read (existing snake admins blocked again, but new admins fine).
- `git revert <Commit 1>` → reverts writers to snake (new admins blocked again).
- Revert either/both; instant, no data cleanup. Claims already overwritten to camelCase remain valid and harmless.

---

## 8. SCOPE GUARDRAILS
- Touch ONLY `AdminUsers.php` + `Admin_login.php`.
- Do NOT modify `Staff.php`/`Sis.php` (mobile snake contract).
- Do NOT rename `must_change_password` keys.
- Do NOT alter RBAC authorization logic (already verified 34/49).
- No commits/push until diff review approved.

---

*Pre-flight only. No files modified. On approval: implement Commits 1 + 2 locally, run §6 verification, stop at diff review before any commit.*
