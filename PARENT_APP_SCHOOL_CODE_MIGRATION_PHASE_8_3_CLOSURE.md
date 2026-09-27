# Phase 8.3 Closure Note — School-Code Lookup Migration

**Date:** 2026-08-13 · **Status: PHASE 8.3 = COMPLETE WITH DOCUMENTED DEFERRED LIMITATION**

Not FULLY CERTIFIED. Not REGRESSION FIXED. Not SECURITY RULES MODIFIED. This note freezes the
implementation exactly as it stood at the end of `PARENT_APP_SCHOOL_CODE_FIRESTORE_MIGRATION_REPORT.md`
and makes no further change of any kind — verified via `git status` immediately before writing this
note, identical to the state that report described.

---

## 1. What Was Successfully Migrated

`AuthRepository.lookupSchoolCode()` (Parent App) now resolves `schoolCode → schoolId` via a Firestore
query (`schools.whereEqualTo("schoolCode", id).limit(2)`) instead of reading RTDB
`Indexes/School_codes`. This is live for the **normal login path** — any login whose token already
carries a `schoolId` claim never calls this function at all and is completely unaffected. The
migrated code compiles cleanly, contains no RTDB fallback, and touches no unrelated file (`AppModule.kt`
and `FirebaseService.kt` were updated only as direct, required consequences of the constructor
signature change — not separate decisions).

## 2. What Remains Intentionally Deferred

The narrow bootstrap edge case — `schoolId` claim blank, `school_code` claim present — is **blocked**
by the existing Firestore `schools` collection read rule, which requires the requester's token to
already carry the `school_id` the lookup exists to discover. This is a **known, disclosed limitation**,
not a hidden one: it fails closed (the app falls back to the raw, unresolved school-code value and
continues; it does not crash, and it does not leak any other tenant's data).

## 3. Why the Bootstrap Issue Is Safe to Defer for Now

- The normal login path — the overwhelming majority of real logins, where the token already carries
  `schoolId` — is completely unaffected and unchanged.
- The blocked path fails **closed, not open**: a denied Firestore query returns nothing; it cannot
  return the wrong tenant's data. Confirmed by the query's own structure (`whereEqualTo` can only ever
  match documents with the exact `schoolCode` value, never a different tenant), independent of whether
  the rules permit it.
- No production data, security rule, or index was touched to reach this state — the deferral carries
  zero migration/rollback risk, because nothing was migrated for this specific edge case.
- Fixing it correctly requires a real security/architecture decision (broaden the `schools` read rule
  narrowly, or resolve it server-side via a trusted mechanism) — not a quick patch, and not something
  to decide under this phase's scope.

## 4. What Must NOT Be Changed

Per explicit instruction, frozen exactly as-is — none of the following happened, and none should
happen without a separate, future authorization:

- `firestore.rules` — not modified.
- `firestore.indexes.json` — not modified.
- No RTDB fallback added anywhere in the migrated path.
- No Cloud Function created.
- No new bootstrap collection created.
- Firebase Auth claims — not redesigned.
- Authentication flow — not redesigned.
- Admin Panel PHP — not modified.
- Teacher App — not modified.
- `FirebaseService.kt` — not deleted.
- `firebase-database-ktx` Gradle dependency — not removed.
- `SchoolSyncApp.kt`'s RTDB persistence init — not removed.
- `StudentRepository.kt` / `HomeworkRepository.kt` — not edited (their unused `FirebaseService`
  injection remains a separate, unrelated, out-of-scope cleanup item, documented only).
- No production data written anywhere.
- Nothing staged, committed, pushed, merged, rebased, or deployed.

## 5. Exact Future Trigger for Revisiting

Revisit this deferred limitation when either of these becomes true:

1. A real login is observed (or reported) hitting the blocked edge case in practice — i.e., a token
   issued with `schoolId` blank but `school_code` present, in production, not merely a theoretical
   possibility.
2. A separate, explicitly-scoped security/architecture decision is authorized to choose between the
   two named options — a narrowly-designed Firestore bootstrap authorization, or a trusted server-side
   `schoolCode → schoolId` resolution mechanism (or a third, not-yet-discovered existing ZenX
   tenant-bootstrap mechanism, if one is found on closer inspection). No option is selected here.

**Preserved technical note for that future work:**

> Pre-tenant-resolution `schoolCode → schoolId` lookup is incompatible with the current client-side
> `/schools` Firestore read rule when `school_id` is blank. The old RTDB index
> (`Indexes/School_codes`) was `.read: true` — fully public — precisely because a bootstrap lookup
> cannot require the tenant claim it exists to establish. The Firestore rule that replaced it as this
> lookup's target was written for a different purpose and was never designed for this case.

## 6. Confirmation

**No production data was changed.** **No security rule was changed.** **No index was changed.**
Verified via `git status` in the Parent App repository immediately before this note was written,
matching exactly the state left at the end of Phase 8.3's implementation — no drift, no additional
edit.

---

## Final Status

| Item | Status |
|---|---|
| Implementation | Complete for the authorized migration scope |
| Normal login path | Unaffected |
| School-code bootstrap edge case | Known limitation / deferred |
| RTDB fallback | Not restored |
| Firestore rule change | Not authorized and not made |
| Production data | Untouched |
| Cross-tenant safety | No cross-tenant leak identified; the blocked lookup fails closed |

**PHASE 8.3 = COMPLETE WITH DOCUMENTED DEFERRED LIMITATION.**

---

**Nothing was staged, committed, pushed, merged, rebased, or deployed. Working tree remains exactly
as Phase 8.3 left it.**
