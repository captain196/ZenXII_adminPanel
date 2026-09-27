# Timetable gates B + D — reapplyable rules patches

`firestore.rules` in this working tree carries ~1300 uncommitted lines belonging
to other programmes (P0 #4, the SEC-* waves, and more). Committing this
programme's rules changes would sweep all of that unreviewed work into the same
commit, so the changes are preserved here as patches instead, against frozen
before-images that are also in the tree.

| patch | against | what it does |
|---|---|---|
| `TT-D2-overlay-tenant-read.patch` | `firestore.rules.tt_d2_before` | `substitutes` + `timetableSettings` read: `isAuth()` → `isSameSchool()` |
| `TT-D1-and-TT-D2-combined.patch` | `firestore.rules.tt_d1_before` | the above **plus** gate B: `timetables` write arms → `allow write: if false` |

Reapply with `patch firestore.rules < patches/<file>` from `firebase-rules/`.

## Executable evidence, both directions

`tests/timetable_projection_authority.test.js` and
`tests/timetable_overlay_tenant_read.test.js` each run **both** rulesets against
the real engine: the frozen before-image (what production still runs) and the
repository rules. Neither is a claim read off a file.

## DEPLOYMENT ORDER — the apps ship before TT-D2

The released Teacher dashboard build queries `substitutes` by `date` alone.
`isSameSchool()` denies an unscoped query outright — proven on the emulator —
so deploying TT-D2 before that build rolls out empties the cover notice for
every teacher still on it. The scoped query exists only in the uncommitted
Teacher-app tree (`DashboardViewModel.kt`, `TimetableFirestoreRepository.kt`).

Order: merge + release the apps → deploy rules → then the activation sequence in
`TIMETABLE_SWITCHOVER_READINESS.md` §8.4.
