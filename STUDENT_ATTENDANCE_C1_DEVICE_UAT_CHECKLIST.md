# Phase C1 — Offline Attendance Queue — Device-UAT Checklist

**Precondition to enable:** flip `OfflineFeatureFlag.ENABLED` to `true` in a
reviewed commit, rebuild, install on a **physical Android device** (or a
Play-services emulator wired to the harness). The queue ships **dormant** — every
entry point is a no-op while the flag is false, so nothing below can be exercised
until the flag is on. Keep it OFF in any production-facing build until every row
here is **PASS**.

Backend must be a NON-production tenant (the local emulator or a staging school).
Never run device UAT against a live tenant.

Each step lists the exact **expected implemented behaviour** so a tester can mark
PASS/FAIL without guesswork. Queue states: `PENDING · SENDING · FAILED_RETRYABLE
· FAILED_TERMINAL`.

| # | Scenario | Steps | Expected (as implemented) | Result |
|---|----------|-------|---------------------------|--------|
| A | Online baseline | Mark a section's attendance with network ON; Save | `SaveSuccess` snackbar; server shows the marks; queue empty | ☐ |
| B | Airplane mode capture | Turn airplane mode ON; mark several students; Save | `QueuedOffline` snackbar ("saved on this device… will sync"); NO false server-success; row(s) `PENDING`; pending badge > 0 | ☐ |
| C | App kill survives | Force-stop the app; reopen | Pending rows still present (Room durable); badge unchanged | ☐ |
| D | Device reboot survives | Reboot device; reopen app (still offline) | Pending rows still present | ☐ |
| E | Reconnect flush | Turn network ON | `AttendanceFlushWorker` runs once (unique KEEP); each row → server via `attendance/save` with `mark_date`; rows deleted only on HTTP-200 + `status:success`; badge → 0 | ☐ |
| F | Duplicate / double flush | While flushing, toggle network / reopen to fire multiple sync triggers | Exactly one worker (KEEP); no duplicate or corrupt attendance server-side (merge-idempotent); no row deleted without a real success | ☐ |
| G | Auth expiry | Revoke/expire the session; go offline, mark, Save; reconnect | Row stays `PENDING` on 401; worker force-refreshes token and `Result.retry()`; after re-auth the row flushes; queue never dropped; no credentials stored | ☐ |
| H | Lock conflict | Queue a mark offline; lock that date server-side; reconnect | Server 423 → row `FAILED_TERMINAL`; teacher sees "locked — file a correction"; row NOT deleted, NOT retried forever | ☐ |
| I | Coverage conflict | (Substitute) queue a mark while covered; admin revokes coverage; reconnect | Server 403 → row `FAILED_TERMINAL` ("no longer assigned"); C2 authority wins at replay; no bypass | ☐ |
| J | Session rollover | Queue a mark; roll the school to a new session; reconnect | Client date-pin/session-pin OR server → row `FAILED_TERMINAL` ("previous session… re-enter"); **old row is NEVER written into the new session** | ☐ |
| K | Multi-day / stale day | Queue marks; stay offline until the calendar day changes; reconnect | Any row whose `dateIso` ≠ today → `FAILED_TERMINAL` ("no longer today — file a correction"); same-day rows flush normally; **no mark written to the wrong day** | ☐ |
| L | Final server verification | After all flushes | Canonical `attendanceSummary` correct; per-day `attendance` convergent; **no duplicate source of truth**; audit shows the real marker | ☐ |
| M | Crash during send | Kill the app while a flush is in flight (row `SENDING`); reopen | `recoverStuckSending` on app-start resets `SENDING → PENDING`; row re-sent (merge-idempotent, same UUID); no loss, no double-apply | ☐ |
| N | Supersession | Offline, mark the same section/day twice with different values; reconnect | Only the latest submission for that section+day is sent (older `PENDING` superseded on device); server ends in the final intended state | ☐ |
| O | Performance | Queue ~50–100 section saves offline; reconnect | Flush drains oldest-first without ANR; no busy loop; one worker; UI responsive; back-off on transient failures | ☐ |

## Local-tampering security spot-check (device or rooted emulator)
- Edit the Room `pending_mark` row to a **different school / class / section** you
  do not own, then let it flush. **Expected:** server 403 → `FAILED_TERMINAL`.
  The client can never gain authorization it doesn't have; tenant, role, class,
  coverage and session are all re-derived and re-checked server-side.

## Sign-off
Enable in production only after A–O and the tampering check are all PASS, on at
least one low-RAM device and one recent Android version. Record device model /
OS / date next to the ticket.
