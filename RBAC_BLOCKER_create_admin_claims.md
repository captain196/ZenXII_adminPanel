# 🔴 BLOCKER — AdminUsers::create_admin produces non-loginable accounts (claims-contract mismatch)

**Found:** 2026-06-06 during RBAC acceptance-test execution prep (C1 login).
**Severity:** HIGH (production-impacting) · **Status:** confirmed by code trace; live run HALTED (zero mutations made).
**Branch:** `ank-yug_b1` @ `5e2499f`

## Summary
Admins created through the standard UI (`AdminUsers → Create Admin`) **cannot log in**. `create_admin` writes Firebase Auth custom claims in **snake_case**; the current Wave-C/`f38162d` `Admin_login` contract reads them in **camelCase** and also requires `roleLabel`. Neither side normalizes, and login has no fallback — so `schoolId` resolves empty and login is rejected with `ADMIN_LOGIN_AUTHZ_MISSING`.

## Evidence (code-airtight)

**Writer — `AdminUsers::create_admin` ([AdminUsers.php:494-499](application/controllers/AdminUsers.php#L494)):**
```php
$this->firebase->setFirebaseClaims($admin_id, [
    'role'          => $role,
    'school_id'     => $this->school_id,     // snake_case
    'school_code'   => $this->school_code,   // snake_case
    'parent_db_key' => $this->parent_db_key, // snake_case
]);                                           // NO 'roleLabel'
```
`setFirebaseClaims` writes verbatim — no key transform ([Firebase.php:714-724](application/libraries/Firebase.php#L714)).

**Reader — `Admin_login::_try_firebase_admin_login` ([Admin_login.php:235-248](application/controllers/Admin_login.php#L235)):**
```php
$schoolId   = (string) ($claims['schoolId']   ?? '');  // camelCase  -> '' (only school_id exists)
$schoolCode = (string) ($claims['schoolCode'] ?? '');  // camelCase  -> ''
$roleLabel  = (string) ($claims['roleLabel']  ?? '');  // never set   -> ''
$roleRaw    = (string) ($claims['role']       ?? '');  // ok
if ($schoolId === '' || $roleRaw === '') {             // schoolId '' => TRUE
    ... emit ADMIN_LOGIN_AUTHZ_MISSING; return;        // LOGIN REJECTED
}
```
`_signin_claims` returns claims verbatim from the ID token — no normalization ([Admin_login.php:405-426](application/controllers/Admin_login.php#L405)). No self-heal/fallback for `schoolId` exists before the guard.

Secondary failure even if the guard were passed: the staff lookup uses `$schoolId . '_' . $adminId` ([Admin_login.php:252](application/controllers/Admin_login.php#L252)) → `'_ADMxxxx'` (empty schoolId) → staff doc absent → `ADMIN_LOGIN_AUTHZ_MISSING (staff_doc_absent)`.

## Root cause
The SA onboarding path was migrated to camelCase claims + `roleLabel` (commit `f38162d` "camelCase claims + staff doc"). **`AdminUsers::create_admin` was not migrated** and still emits the pre-cutover snake_case schema with no `roleLabel`.

## Impact
- Every school admin created via the AdminUsers UI on this branch cannot authenticate.
- Blocks RBAC acceptance cell **C1 (login)** for all five roles — so C2/C8 (which depend on a live session) cannot run either.
- This is a **provisioning/claims-contract** defect, NOT an RBAC-authorization-logic defect: the 34/49 logic cells already proved authorization is correct *given a valid session*. The gap is that the standard provisioning path can't produce a valid session.

## Required fix (separate, pre-requisite to acceptance sign-off)
Align `create_admin` claims to the login contract:
```php
$this->firebase->setFirebaseClaims($admin_id, [
    'role'        => $role,
    'roleLabel'   => $role,                 // login uses roleLabel ?: role
    'schoolId'    => $this->school_id,      // camelCase
    'schoolCode'  => $this->school_code,    // camelCase
    'parentDbKey' => $this->parent_db_key,  // camelCase (if still consumed)
]);
```
Also verify `saveStaff` writes `staff/{schoolId}_{adminId}` with `status=Active` (the doc id Admin_login looks up). Likely the same fix is needed in `update_admin` (role-change claim refresh) — to be confirmed during pre-flight.

## Status of the live run
- **HALTED before any mutation** per stop-on-failure. No Firebase Auth users created, no Firestore writes, no role edits. Nothing to tear down.
- RBAC logic/sidebar/enforcement/fail-closed: **34/49 PASS, 0 defects** (unchanged).
- Acceptance cells C1/C2/C8 (15) **cannot pass** until this blocker is fixed.

## Recommendation
Treat as a small, targeted fix (claims schema in `create_admin`, likely `update_admin` too) to be scoped via pre-flight and applied before re-running the acceptance checklist. It is independent of the SC-Step10 session-convergence work.
