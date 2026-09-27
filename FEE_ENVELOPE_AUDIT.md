# FEE-ENVELOPE-AUDIT — `json_success()` status-key collisions

**Date:** 2026-08-12 · **Status: AUDIT ONLY — nothing in this document is fixed.**
Raised while closing FEE-Z5 / FEE-Z6. Recorded as a separate future workstream.

---

## The mechanism

`MY_Controller::json_success()` builds its envelope as:

```php
echo json_encode(array_merge(['status' => 'success'], $data));
```

`$data` is merged **over** the envelope. Any caller passing a top-level `status`
key therefore **overwrites `"success"`**, and every client applying the standard
phantom-success guard —

```js
if (!res.httpOk || !res.body || res.body.status !== 'success') { throw … }
```

— reads a **successful** operation as a **failure**.

This is not hypothetical. It was the root cause of both FEE-Z5 (Student 360
approvals shown in red, list never refreshed) and FEE-Z6 (every successful
concession capture in all three admission pathways displayed as an error).

**`json_success()` is deliberately NOT changed.** Inverting the merge would fix
all of these at once but silently alters the response of every JSON endpoint in
the ERP. That is a separate, explicitly-scoped decision.

---

## Detection method and its limits

Detected with the PHP tokenizer, counting **depth-1 keys only** inside the
`json_success()` argument, so comments and nested `status` keys do not register.

**Known limitation, stated plainly:** where the argument is a *function call*
returning an array, the detector descends into that function's own array literal
and mis-reports its keys. `Academic.php:1269` is a confirmed false positive for
exactly this reason. **Every row below marked "needs verification" must be
re-read before any fix.**

---

## Confirmed and fixed (this workstream — listed for completeness)

| ID | File | Method | Line | Severity | Status |
|---|---|---|---|---|---|
| FEE-Z5 | `Entitlements.php` | `approve` | 275 | High | ✅ **FIXED + verified** |
| FEE-Z6 | `Entitlements.php` | `propose` | 116 | **High** | ✅ **FIXED + verified** |
| FEE-Z6 | `Entitlements.php` | `reject` | 315 | Medium | ✅ **FIXED + verified** |
| FEE-Z6 | `Entitlements.php` | `discontinue` | 341 | Medium | ✅ **FIXED + verified** |

`Entitlements.php` now has **zero** remaining collisions.

---

## Open findings — DO NOT FIX without separate authorization

Severity reflects **whether a client is known to apply the transport guard**, not
just whether the collision exists.

| # | File | Line | Endpoint / context | `status` carries | Classification | Severity |
|---|---|---|---|---|---|---|
| 1 | `Admin.php` | 821 | birthday wish — already sent | business (`already_sent`) | **confirmed defect** | Medium |
| 2 | `Admin.php` | 830 | birthday wish — sent | business (`sent`) | **confirmed defect** | Medium |
| 3 | `Attendance.php` | 7930 | attendance-change request created | business (`pending`) | **confirmed defect** | Medium |
| 4 | `Fees.php` | 6362 | receipt status probe | business (`unknown`) | **confirmed defect** | Medium |
| 5 | `Fees.php` | 6372 | receipt status | business (`$status`) | **confirmed defect** | Medium |
| 6 | `Fees.php` | 6478 | job re-queue | business (`queued`) | **confirmed defect** | Medium |
| 7 | `Hr.php` | 8025 | payroll context | business | needs verification | Medium |
| 8 | `Hr.php` | 9585 | payroll run unlocked | business (`Draft`) | **confirmed defect** | Medium |
| 9 | `Maps_config.php` | 142 | maps config save | business (`$res['status']`) | **confirmed defect** | Medium |
| 10 | `Ptm.php` | 1287 | PTM status unchanged | business (`$cur`) | **confirmed defect** | Medium |
| 11 | `Ptm.php` | 1319 | PTM status changed | business (`$newStatus`) | **confirmed defect** | Medium |
| 12 | `School_config.php` | 5213 | config health check | business (`$overallStatus`) | **confirmed defect** | Medium |
| 13 | `Staff_attendance.php` | 280 | punch decision | business | needs verification | Medium |
| 14 | `Academic.php` | 1269 | lesson-plan save | — | ❌ **false positive** (nested call) | — |

**Transport** (`Transport_rollover.php` 66 / 97 / 99) was also flagged by the
scan. **Excluded from this audit at the operator's instruction** — the Transport
system is mid-build and not to be touched or assessed until they say otherwise.

### Why "Medium" and not High

FEE-Z6 was High because the affected client (`_entitlements_capture.php`) was
**verified** to apply the guard, on a path staff use during admission. For the
rows above, the collision is confirmed in the response but **the client-side
interpretation has not been traced**. A caller that only reads `message`, or
reads the response with no guard, is unaffected in practice.

**Do not raise or lower these severities without tracing the caller.** That
tracing is the first task of the follow-up workstream.

---

## Recommended sequencing for the follow-up

1. **Trace callers first.** For each row, find the client and check whether it
   applies `status === 'success'`. That converts "confirmed defect" into "confirmed
   *impact*", which is what should drive priority.
2. **Fix endpoint-locally**, as FEE-Z5/Z6 were — rename the payload key, never the
   envelope.
3. **Only then** consider `json_success()` itself. Inverting the merge to
   `array_merge($data, ['status' => 'success'])` would make the envelope
   authoritative and prevent recurrence — but it changes every JSON endpoint at
   once and needs its own regression pass.

**A note worth acting on:** `Attendance.php:8076`, `Hr.php:7257` and
`Staff.php:2566` carry comments explicitly warning about this collision and
naming a workaround. The trap has been hit and side-stepped at least three times
without the pattern being addressed. That is the argument for eventually fixing
`json_success()` rather than continuing to patch call sites.

---

**Nothing in this document has been modified. Working tree HELD.**
