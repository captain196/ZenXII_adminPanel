# Transport Notification Recipient Contract — Stabilization

**Date:** 2026-07-23 · **Type:** production defect discovered during Medium/Low stabilization; handled as a **separate** stabilization item (not part of the original Transport audit). · **Authorization:** operator-approved **Option A** (canonical `userId`), recipient-contract scope only. · **Release constraints:** no commit / rebase / branch / push / deploy.

---

## 1. Defect

Every **parent-facing** transport notification resolved its recipients from student-document fields that **do not exist** — `parent_db_key` / `parentId` / `parent_id` — while the student doc actually stores camelCase `parentDbKey` (a parent group key) and `userId` (the login identity). Result: `$pid = ''` → **zero recipients → transport parent notifications silently delivered to nobody.**

The Parent App fetches its inbox with `notifications.whereEqualTo("userId", getUserId())`, where `getUserId()` = `tokenManager.user.userId` = the **student login id** (e.g. `STU0001`) = the student doc's **`userId` field**. So the canonical recipient is the student's `userId` field — not the parent group key, and not (by assumption) the studentId.

---

## 2. Full recipient-resolution audit (every transport notification workflow)

| Categories | Scope | Resolver | Recipient identity (before) | Verdict |
|---|---|---|---|---|
| `trip.delay`, `trip.disruption`, `route.suspension_notice_parents` | PARENTS_OF_ROUTE | `_fanoutParentsOfRoute` → `_resolveParentsOfRoute` | `parent_db_key ?? parentId ?? parent_id` (absent) | ❌ 0 recipients → **FIXED** |
| `trip.student_no_show`, `event.scheduled`, `event.rescheduled`, `event.cancelled`, `event.consent_reminder` | PARENTS_OF_STUDENTS | `_fanoutParentsOfStudents` | same absent fields | ❌ 0 recipients → **FIXED** |
| _(no category currently maps here)_ | PARENTS_OF_VEHICLE | `_fanoutParentsOfVehicle` → `_resolveParentsOfRoute` | same | ❌ broken (dormant) → **FIXED via shared resolver** |
| `fleet.*`, `route.suspended`/`resumed`, `assignment.*`, `driver.*`, `expense.*`, `sos.*`, `trip.started`/`completed`, `event.trip_dispatched`/`completed` | OPS / STAFF_ADMIN_ONLY | `_fanoutOps` | **role-based** (`targetRoles` → `noticeRecipients`) | ✅ sound — separate contract, **unchanged** |

**Only writer to the parent `notifications` inbox:** `Transport_notifier::_fanoutNotifications` (one site). No other transport file resolves parent recipients (a `parentId` reference exists only in the test-only `Transport_probe_f10.php`, which is why seeded tests passed while production was broken).

---

## 3. The two production notification contracts (now explicit)

1. **Parent notifications** → **per-user** delivery to `notifications`, keyed by the canonical **`userId`** (the student's `userId` field == the Parent App login id). Preferences at `notificationPreferences/{schoolId}_{userId}`.
2. **Operations / Staff notifications** → **role-based** delivery to `notices` + `noticeRecipients` via `targetRoles`; role expansion at the staff notice-bell reader.

These are two independent, valid models. This stabilization normalizes **only** contract #1. Contract #2 is intentionally untouched (operator-confirmed). SOS / trip-lifecycle / driver / fleet / expense notifications remain **staff** notifications and are **not** redirected to parents.

---

## 4. Fix — single-sourced canonical resolver

`Transport_notifier`:
- **`_recipientUserIdFromStudent(stu, studentId)`** — reads the student's **`userId` field** (falls back to `studentId` only when the field is absent, with a warning; does **not** assume `studentId == userId` is a permanent invariant).
- **`_resolveStudentRecipients(studentIds)`** — the ONE resolver every parent fanout uses. Batches student reads (`studentId IN` chunks of 10, PERF-9) with a per-student `getEntity` fallback when the composite index is absent; school-scoped reads (no cross-tenant leakage); dedupes on `userId`.
- `_fanoutParentsOfStudents` and `_resolveParentsOfRoute` (hence `_fanoutParentsOfVehicle`) now route **exclusively** through it. No parent path reads the legacy fields.

**Index:** added `students [schoolId ASC, studentId ASC]` to `firebase-rules/firestore.indexes.json` for the batched query (fallback keeps it correct until deployed). **Not deployed** (release constraint).

---

## 5. Cross-platform impact (Admin Panel ↔ Parent App)

- **Admin (writer):** now writes `notifications.userId = <student userId>` for parent categories — matching the Parent App read contract. **No schema change to `notifications`** (same fields); only the recipient value is now correct.
- **Parent App (reader):** **no app change required** — it already queries `notifications.whereEqualTo("userId", getUserId())`. With the corrected recipient value, parent transport notifications now surface. Preferences (`notificationPreferences/{schoolId}_{userId}`) already keyed by the same `userId`, so mute-filtering aligns automatically.
- **Teacher App:** unaffected (does not read the parent `notifications` inbox for transport).

---

## 6. Validation

- **Runtime probe `transport_probe_recipient` — 5/5:** G1 resolver returns the `userId` field (not studentId); G2 dedup; G3 `trip.student_no_show` delivered to the correct `userId` inbox (queried the way the Parent App reads), non-target student correctly excluded; G4 `trip.delay` (parents_of_route) delivered to every rostered `userId`; G5 cross-tenant resolution returns empty (no leakage).
- **Permanent gate `CH-TRN-RCP-1`:** asserts the canonical resolver/mapper exist + read `userId`, every parent fanout routes through it, and **no** active legacy `parent_db_key`/`parentId`/`parent_id` reads remain.
- **Regression:** full transport verifier suite green (only the 4 pre-existing runtime data gates fail); `LOG-3`/`CON-7` notifier gates still PASS after the notifier edits.

---

## 7. Deliberately out of scope (documented, no change)

- **Audience design** — whether SOS / trip-start / trip-complete etc. should ALSO notify parents is an audience-scope decision, not a recipient-contract mismatch. Operator confirmed: **do not redirect** ops notifications to parents.
- **CON-8** (route-membership query capped at 500 in `_resolveParentsOfRoute`) — unchanged; a single route (one bus) never approaches 500 students. Separate perf item.
- **Minor observation (pre-existing, not a delivery defect):** `_fanoutNotifications` builds the notification docId as `{schoolId}_{noticeId}_{uid}` before `setEntity` adds another `{schoolId}` prefix (cosmetic double-prefix). Delivery is by the `userId` **field**, so this does not affect correctness. Left as-is (out of the recipient-contract scope).

*Working artifact; not committed to git.*
