# CARRY — SC-Step11 (G3): retire the Admission_public currentSession side-channel write

**Status:** TRACKED — NOT STARTED. Immediate follow-on to SC-Step10. No implementation yet.
**Raised:** 2026-06-06 (during SC-Step10 census check 6) · branch `ank-yug_b1`
**Priority:** HIGH (couples to SC-Step10 fail-closed integrity)

## What
`Admission_public::_resolve_active_session()` at **`application/controllers/Admission_public.php:1009`** writes `schools/{id}.currentSession` (a value derived from the `sections` collection) when `currentSession` is missing:
```php
$this->fs->update('schools', $school_id, ['currentSession' => $resolved]);
```
Plus it reads legacy fields (`activeSession`/`active_session`/`session`) at :983-985.

## Why it matters (coupling to SC-Step10)
- It is a **non-canonical, public-form side-channel writer** of `currentSession` — violates "School_config is the sole authority" (SC-Step10 census check 6).
- It is **fail-OPEN on the write side**: after SC-Step10 made the *read* paths fail-closed (G1 login, G2 api_punch), this writer can **silently re-seed** a missing `currentSession` from `sections` the moment anyone hits the public admission form — partially defeating the fail-closed guarantee. SC-Step10 hardened reads; G3 closes the write loophole.

## Target (when authorized)
- Remove the side-channel `currentSession` write (:1009).
- Admission resolves the active session from `schools/{id}.currentSession` **only**; remove legacy-field reads (:983-985).
- Fail-closed: if `currentSession` is absent, the public admission form shows "admissions are not open / session not configured" rather than inventing one.

## Architecture constraints (same as SC-Step10)
Firestore canonical · `schools/{id}.currentSession` sole authority · no sessions-derived/synthetic seeding · no RTDB · no legacy-field reads · fail closed · School_config the sole `currentSession` writer.

## Scope estimate (for the eventual pre-flight)
- File: `application/controllers/Admission_public.php` — `_resolve_active_session()` (~:980-1018), call sites :76/:137/:498/:776.
- Est. diff: ~15-25 LoC (remove write + legacy cascade; fail-closed return).
- Rollback: pure logic, no data; `git revert`.
- Verification: grep — zero `currentSession =>` writes outside School_config + onboarding; public admission form fail-closed when `currentSession` empty; no regression to admission happy path on a healthy tenant.

## Sequencing
Execute as the **immediate follow-on after SC-Step10** so the write side can't re-open what the read side just closed. Reference: `SESSION_CONVERGENCE_AUDIT_ZENX.md` (G3 / SC-Step11) and `SC_STEP10_CENSUS_AND_PREFLIGHT.md` (check 6).

*Carry tracking only. No implementation, no commits, no push.*
