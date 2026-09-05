# RTDB rules — scoped replacement · DRAFTED, **NOT DEPLOYED**

`database.rules.PROPOSED.json` is ready for review. `database.rules.json` (the live one) is
untouched. **Read the verification section before deploying.**

## What is live right now

Fetched from the deployed project 2026-09-04 — not read from git, because this codebase has
a catalogued history of the two differing:

```json
{ "rules": { ".read": "auth != null", ".write": "auth != null", … } }
```

22 lines, **no `Schools` block**. Everything inherits the root. ZenXii is multi-tenant on one
Firebase project, so `auth != null` is satisfied by **any parent or staff member of any
school**, and it grants read **and write** across every tenant:

| Path | Contents | Live |
|---|---|---|
| `Schools/*/…` | notices, gallery, stories, leave, teachers, config, per-session accounts | 8 schools |
| `Schools/*/Phone_Index` | raw phone numbers | ~90 for one school alone |
| `Users/{Admin,Parents,Devices}` | user records, FCM device tokens | yes |
| `User_ids_pno` | user-id ↔ phone number | 108 |
| `Exits` | 108 | yes |

**The shipped apps already use RTDB** — both hold a database reference and read/write these
paths today. This is not a hand-crafted-request risk; the client SDK is wired and pointed at
the tree.

## The blocker from the first draft — RESOLVED with evidence

The open question was whether a scoped rule could match real tokens, or would lock every app
user out. Both halves are now established from production:

**The node keys.** All 8 `Schools/*` nodes are keyed by `SCH_*`:
`SCH_218AAF5C23 · SCH_E9E683B586 · SCH_ACF67B0CD4 · SCH_B56BB9A401 · SCH_B9161BBE3C ·
SCH_54D0424022 · SCH_D94FE8F7AD · SCH_B76CFFF3C3`. **None is keyed numerically.**

**The claims.** Sampled from real accounts via the Identity Toolkit API:

| claim | value | matches `Schools/*`? |
|---|---|---|
| `school_id` | `SCH_B56BB9A401` | **yes, exactly** |
| `school_code` | `10012` | no |
| `parent_db_key` | `10012` | no — this keys `Users/Parents/*` |

So `auth.token.school_id === $schoolId` is the correct predicate, and `schoolId` (camel) is
accepted alongside it because the claim writer dual-emits both spellings and one surface
reads each.

**The app-side naming is the reverse of the claim naming**, which is why this needed
checking rather than assuming: `TokenManager.schoolCode` stores the `SCH_xxx` path — stated
in a comment at `LeaveViewModel.kt:240` — while the *claim* called `school_code` is numeric.
Both app usages therefore resolve to `SCH_*` paths.

## Every path the apps touch, and how the draft treats it

| Path | App use | Draft |
|---|---|---|
| `Indexes/School_codes`, `School_names`, `School_ids` | read **before authentication**, to resolve a school at login | public read, **no** write |
| `Schools/{SCH_}/…` | Notices, Gallery, Stories, Teachers, Config, Leave | read scoped to own school |
| `Schools/{SCH_}/Social/StoryViews/{story}/{uid}` | write a view | write allowed **only under the viewer's own uid** |
| `Schools/{SCH_}/LeaveApplications/{id}` | submit leave | write scoped to own school |
| `SOSAlerts/{SCH_}/{alertId}` | transport SOS | read + write scoped to own school |
| `Users/Devices/{uid}/{deviceId}` | FCM token mirror | read + write **own uid only** |
| `Users/Parents`, `Users/Admin`, `User_ids_pno`, `Exits`, `System`, `RateLimit` | not read by either app | **denied to clients** |

**`SOSAlerts` does not exist in the database yet** but the Teacher app writes to it
(`TransportFirestoreRepository.kt:236`). A blanket deny-by-default would have silently broken
a **safety feature** the first time it fired. It is explicitly allowed. This is the reason
the rules were written from the app source rather than from the data.

The panel is unaffected either way — it uses the Admin SDK, which bypasses rules entirely.

## Verification before deploy — do not skip

RTDB has no rules simulator comparable to the Firestore emulator here, and no sentinel
equivalent to `aegis rules status`, so this cannot be proven safe from a repo reading.

1. Deploy to a **staging** database first if one exists.
2. Otherwise deploy at a quiet hour and immediately exercise, on a real device, for a school
   of each shape: **notices · gallery · stories · leave · login · FCM registration**.
3. `firebase database:rules` retains history — roll back at the first sign of a broken read.
4. Watch for permission-denied in app logs, not just for "it looks fine".

**The failure mode to fear is silent:** a denied read surfaces in these apps as an empty
list, not an error — the catalogued "read failure indistinguishable from empty result"
pattern. An empty gallery looks like a school with no photos.

## Also done

`database.rules.json.rollback` — `".read": "true", ".write": "true"` with **no auth at all** —
has been **deleted from the repo**. It was one careless `firebase deploy --only database`
from an unauthenticated breach of everything above. Git history retains it.

## Still open

There is no drift detection for RTDB. Firestore has 47 rule blocks and a Sentinel that
compares live against git; RTDB had 22 lines and nothing watching, which is why this
survived. Either build the equivalent, or migrate what remains off RTDB.
