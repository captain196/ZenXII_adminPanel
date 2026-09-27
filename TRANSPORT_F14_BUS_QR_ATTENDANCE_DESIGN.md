# F14 — Bus QR Attendance (Design)

**Status: DESIGN ONLY. No code written. Not authorized.**
Author's note: this is a proposal for review. Nothing in it has been implemented.

---

## 1. What the operator asked for

Students do **not** carry phones. A student carries **one ID card** with a QR code. The driver's
phone scans that card, four times across a school day:

| # | When | Trip | Event |
|---|---|---|---|
| 1 | Student boards at their stop, morning | Pickup | boarded |
| 2 | Student steps off inside school | Pickup | alighted |
| 3 | Student boards at school, afternoon | Drop | boarded |
| 4 | Student steps off at their stop | Drop | alighted |

Each scan updates Admin, Teacher and Parent surfaces. The parent is notified **on board** and
**on drop**. The system keeps a running **count of students on the bus**, and the alight count must
reconcile against the board count.

## 2. What already exists — do NOT rebuild

Researched before designing. A large part of this is already shipped:

| Capability | Where | State |
|---|---|---|
| Boarding attendance store | `transportAttendance` collection | **exists** |
| Board / drop / no-show endpoints | `Transport_trip::board_student`, `drop_student`, `no_show_student` | **exists** |
| Frozen per-trip roster | `tripLogs.roster_student_ids` (immutable snapshot) | **exists** |
| On-board counter | `tripLogs.boarded_count` | **exists** |
| Driver-side boarding UI (tap a name) | Teacher app trip detail | **exists** |
| **Signed** student QR tokens | `application/helpers/qr_token_helper.php` | **exists** |
| Local QR rendering (no external API) | `chillerlan/php-qrcode` → `qr_svg_data_uri()` | **exists** |
| QR scan-and-lookup precedent | `Campus_access::scan_qr_lookup()` | **exists** |
| Parent live bus map | Parent app `LiveBusMap.kt` (F13.1 M4) | **exists**, untested |
| Parent push plumbing | `Transport_notifier` + `MARK_REGISTRY` | **exists** |

**So F14 is not a new attendance system.** It is: a camera input method, two missing event types, a
reconciliation rule, and one missing notification.

### Genuine gaps

1. **No scanner in the Teacher app** — no ML Kit / ZXing / CameraX dependency at all.
2. **Only 2 of the 4 events exist.** Today: `boarded_pickup`, `dropped_home`, `no_show`.
   Missing: **alighted-at-school** (morning) and **boarded-at-school** (afternoon).
3. **No boarding notification.** Marks today are `trip.started`, `trip.completed`,
   `trip.student_no_show`. A parent is told when their child did **not** board and never when they
   did — backwards for the reassurance case.
4. **Student ID cards** — QR token generation exists; whether current cards actually print one must
   be confirmed per tenant before rollout.
5. **No reconciliation rule** — nothing today notices a student who boarded and never got off.

## 3. Event model

Four canonical event types, replacing the ambiguous pair in use now:

```
PICKUP trip :  boarded_at_stop    →  alighted_at_school
DROP   trip :  boarded_at_school  →  alighted_at_stop
```

`dropped_home` (legacy) maps to `alighted_at_stop`; existing rows keep working via a read-time
alias. `boarded_pickup` maps to `boarded_at_stop`. **No historical data is rewritten.**

Every event row records: `schoolId, tripId, studentId, event_type, event_at, source`
(`qr_scan` | `manual`), `scanned_by` (staff uid), `stop_id`, `device_lat/lng`, `client_platform`,
plus `scan_nonce` for idempotency.

## 4. The count invariant — the part that actually matters

This is the child-safety feature, not a statistic:

> **For every completed trip: every student who boarded must have alighted.**

```
on_board(trip) = count(boarded_*) − count(alighted_*)
```

- Displayed live on the driver's screen and the admin fleet map ("**On board: 12**").
- At **Complete Trip**, if `on_board > 0` the trip **cannot be silently completed**. The driver sees
  the names still marked aboard and must either scan them off or explicitly record an exception with
  a reason. An unaccounted child is the one failure mode that must never be a silent pass.
- The morning/afternoon comparison the operator asked for falls out of this: pickup `boarded` for a
  given student/day vs drop `boarded` — mismatches surface as an admin exception list, **not** as a
  blocking error, since a child legitimately going home by other means is normal.

**Design choice to confirm:** should `on_board > 0` **block** completion, or **warn and require a
reason**? I recommend warn-and-require-reason — a hard block invites drivers to fake scans to get
the trip closed, which is worse than a recorded exception.

## 5. Security analysis

The QR token is **an identifier, not a credential**. This is the core security position and every
rule below follows from it.

`qr_token_encode()` produces URL-safe base64 of `{schoolId}|{studentId}|{HMAC-SHA256}`. It is
**static per student** — no expiry, no rotation, no nonce.

| # | Threat | Mitigation |
|---|---|---|
| T1 | Card photographed and replayed by anyone | The token alone authorises **nothing**. A scan is accepted only if the scanner holds a valid driver session, owns an **InProgress** trip, and the student is on **that trip's frozen roster**. |
| T2 | Forged QR for an arbitrary student | HMAC signature verified server-side. **Legacy 2-part unsigned tokens MUST be rejected on this path** — the helper still accepts them for backward compatibility elsewhere. Fail closed. |
| T3 | Cross-tenant scan (card from another school) | Token's `schoolId` must equal the driver's `school_id` claim **and** the trip's `schoolId`. Mismatch → reject + security telemetry. |
| T4 | Scanning a student not on this bus | Roster gate: reject unless `studentId ∈ tripLogs.roster_student_ids`. Precedent exists — `no_show` already enforces this. |
| T5 | Replay / double-scan inflating counts | `scan_nonce` + idempotent doc id `{schoolId}_{tripId}_{studentId}_{event_type}`. Re-scanning the same event is a no-op, not a second row. |
| T6 | Out-of-order events (alight before board) | State machine per student per trip; an illegal transition is rejected with a clear message. |
| T7 | Driver fabricating boardings off-route | Each scan stores the device position; admin sees scans that happened far from the expected stop. **Detection, not prevention** — deliberately not blocking, since GPS drift would strand real children. |
| T8 | Parent notified about another parent's child | Recipient resolution goes through the existing canonical `userId` resolver; no new fanout path. |
| T9 | Secret leakage | `_qr_secret()` is server-side only. It must **never** reach any app. Apps send the raw scanned string; the server decodes. |
| T10 | Conductor scans on the driver's phone | Attribution is to the **session holder** (the driver). This is an accountability gap the operator should know about: the audit row will say the driver scanned it. Optional mitigation: a per-scan "handled by" note. Flagging, not solving. |

**Explicitly rejected designs:**
- ❌ Decoding/validating the token on the phone — puts the HMAC secret in the APK.
- ❌ Letting the QR carry the trip or authorisation — a printed card would become a bearer token.
- ❌ Rotating tokens — cards are printed once; rotation would invalidate every card in circulation.

## 6. Offline behaviour

Buses lose signal. Non-negotiable, or drivers abandon the feature.

Scans queue on-device (Room/DataStore) with their true `event_at` and `scan_nonce`, and flush when
connectivity returns. The server accepts a late scan and stamps `received_at` separately, exactly as
F13.1 does for GPS fixes. Idempotency (T5) makes a duplicated flush harmless. The driver's UI shows
"N scans pending sync" — never a silent queue.

Counts computed on-device stay authoritative for the driver's live view; the server reconciles on
flush and wins on conflict.

## 7. Notifications

Two new `MARK_REGISTRY` rows (the only push dispatcher — no second sender):

| Mark | Fires on | Parent sees |
|---|---|---|
| `transport.student_boarded` | board scan | "Aarav boarded the bus at Kankaria East Gate, 7:12 AM" |
| `transport.student_alighted` | alight scan | "Aarav got off at Kankaria East Gate, 3:24 PM" |

Dedupe key = the idempotent event doc id, so a re-scan or an offline re-flush cannot double-notify.
Morning school-arrival alight is **opt-in per school** — four pushes a day per child is a lot, and
notification fatigue makes parents disable the channel entirely.

## 8. Surfaces

- **Teacher (driver):** a Scan button on the trip screen → camera → resolved student card
  (name, photo, class, stop) → confirm → event. Large-target UI, one-handed, works in sunlight.
  Manual tap-to-board **stays** as the fallback for a forgotten or damaged card.
- **Admin:** scan source (`qr_scan` vs `manual`) visible per row; live On-board count on the fleet
  map; exception lists for unaccounted students and off-route scans.
- **Parent:** boarding/drop timeline for their child, alongside the existing live map.

## 9. Rules and indexes

- `transportAttendance`: driver may **create** only for a trip they own, only for a rostered student,
  and only the allowlisted fields; **no update, no delete** (append-only audit). Parent may read only
  rows for their own child. Same three-arm ownership pattern as `tripLogs`.
- New composite index: `schoolId + tripId + event_type + event_at`.
- Both must deploy **before** the code — runbook ordering.

## 10. Phasing

| Phase | Content | Independent value |
|---|---|---|
| **F14.1** | Boarding + alight notifications on the **existing** manual flow | Immediate — no QR needed |
| **F14.2** | Four-event model + count invariant + completion guard | The safety feature |
| **F14.3** | QR scanning in the Teacher app (ML Kit) + offline queue | The speed feature |
| **F14.4** | Student ID card QR verification/printing per tenant | Rollout enabler |

F14.1 is small, useful on its own, and closes the "notified only on no-show" gap today.

## 11. Open decisions — operator input needed

1. **Completion guard**: block completion while `on_board > 0`, or warn + require a reason? (I
   recommend the latter — see §4.)
2. **Morning arrival notification**: on by default, or opt-in? (I recommend opt-in — §7.)
3. **Alight-at-school scan**: is scanning 50 children off the bus at the school gate operationally
   realistic, or should arrival be a single "all alighted" action with exceptions? This is the
   biggest practical risk in the whole design and I'd want the operator's judgement.
4. **Conductor attribution** (T10): accept driver-attributed scans, or add a "handled by" field?
5. **Cards without QR**: what happens on day one for students whose card predates this?

## 12. Cross-module impact

Touches `transportAttendance`, `tripLogs` (counters only — the frozen roster is **not** modified),
`MARK_REGISTRY`, Firestore rules + indexes, and all three surfaces. Fees, Accounting, SIS and
Attendance are **not** touched: transport boarding is not school attendance, and conflating them
would corrupt attendance reporting.

---

**Nothing here is built. Awaiting review of §11 before any implementation.**
