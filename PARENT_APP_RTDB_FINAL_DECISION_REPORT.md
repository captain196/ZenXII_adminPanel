# Parent App RTDB Final Decision Report (Phase 8.1)

**Date:** 2026-08-13 · **Status: Investigation only. NO code modified. NO migration executed.**
**Working tree: HELD** in all three repositories — nothing reset, reverted, stashed, or cleaned;
nothing committed, staged, or pushed.

**Scope discipline:** per instruction, this is a focused investigation of exactly one question —
whether `Indexes/School_codes` in RTDB is safe to stop reading from the Parent App. It does not
touch Student Lifecycle, SRNO, re-admission, class/section, Teacher status policy, sibling matching,
Transport, Maps, Fees, or Attendance. One out-of-scope dependency **was** discovered (§9) and is
reported, not acted on, per the explicit instruction to report rather than fix cross-dependencies
found along the way.

---

## Headline answer, up front

**The prior pass's working assumption was wrong, and the evidence now available corrects it.**
`Indexes/School_codes` is **not** dead or stale — it is a live, actively-written, actively-read index
still load-bearing for the **PHP Admin Panel's own** login and OTP-recovery flows today. The Parent
App's RTDB read was never reading orphaned data; it was reading the same real index the backend
itself still depends on. **This is Decision B: migration required, STOP after design — no removal,
no code change.**

## 1. What Was Investigated

Repository-wide search for `School_codes`, `indexSchoolCodes`, `setSchoolCode`, `getSchoolByCode`,
`lookupSchoolCode`, `school_code`, `schoolCode` across all three repositories (PHP admin panel,
Parent App, Teacher App — Teacher App returned nothing new, consistent with Phase 7's finding that
it has zero RTDB usage at all). The prior pass had only checked `setSchoolCode()`'s Firestore-side
call sites; this pass searched for the **RTDB path itself**, which the prior pass had not done.

## 2. Actual RTDB Data State

**CONFIRMED live, not assumed.** Real, current, non-test code paths read and write
`Indexes/School_codes` today:

| File | Operation | Live/Reachable? |
|---|---|---|
| `Superadmin_schools.php:14` | Canonical doc-comment: *"Indexes/School_codes/{school_code} → school_id (Admin_login fast path)"* | Documentation, confirms design intent |
| `Otp_service.php:390,414,437` | `shallow_get('Indexes/School_codes')`, then iterates the **keys** as `school_code` values to build `Users/Admin/{code}/...` and `Users/Parents/{code}/...` lookups, for password-reset/account-recovery flows | **LIVE, reachable from real OTP/account-recovery requests** |
| `Common_model.php:249` | `getReference('Indexes/School_codes/' . $schoolId)->getValue()` — a direct RTDB read | **LIVE** (though see §3's residual ambiguity on this one specifically) |
| `Superadmin_migration.php:21,319,343,344,724` | Explicitly documents and reads `Indexes/School_codes/{code} — login code → school_id`, builds a `school_code → school_name` lookup from it | **LIVE**, part of an active migration/reporting tool |
| `Schools.php:105,114,116,197` | Reads/writes under the same path root, but — see §3 — this is legacy, pre-Firestore-migration code (`Schools/{schoolName}/{session}` tree, keyed by school **name**, not `schoolId`), operating on a "change school name" feature | **Reachable in principle (no explicit route blocks it), but architecturally vestigial** — inconsistent key/value shape from the other four sites, discussed below |
| `scripts/inspect_gallery_rtdb.js`, `scripts/inspect_student_flags_rtdb.js`, `scripts/migrate_student_flags_to_firestore.js` | Read `Indexes/School_codes` as a real, expected-to-exist index during other migration/inspection work | Confirms other engineers treat this index as live infrastructure, not dead data |

**This directly overturns the prior pass's inference.** The prior pass found `setSchoolCode()` (the
**Firestore-side** helper) has zero callers and concluded the underlying concept might be unused.
That conclusion was too narrow — it only checked one candidate replacement, not whether the **RTDB
original** was itself still alive. It very much is.

## 3. Exact `lookupSchoolCode()` Purpose — and one residual, disclosed ambiguity

**Key/value shape — CONFIRMED for the primary, live consumers:** `Otp_service.php`'s usage
(`shallow_get` then iterate keys) proves the **keys** of `Indexes/School_codes` are `school_code`
values (the human-facing numeric login code, e.g. "10001") — these keys are used directly as RTDB
path segments elsewhere (`Users/Admin/{code}/...`, `Users/Parents/{code}/...`), which is the *exact*
same path shape the Parent App's own (already-removed) login fallback used
(`Users/Parents/$parentDbKey/$userId`). This is strong, convergent evidence: `Superadmin_schools.php`'s
doc-comment model (`key = school_code`, `value = school_id`) is the real, live, current shape.

**Residual ambiguity, disclosed rather than resolved:** `Schools.php:197` writes
`Indexes/School_codes/{schoolId} = schoolName` — the **opposite** key semantics (keyed by `schoolId`,
not `school_code`), inside a legacy "change school name" feature that operates on an old
`Schools/{schoolName}/{session}` RTDB tree the rest of the modern codebase has abandoned in favor of
Firestore `schools/{schoolId}` documents. This looks like vestigial, pre-migration code rather than a
live, current writer — but this pass did **not** confirm `Schools.php` is unreachable (no explicit
route blocks it in `routes.php`; CI3's default routing would still dispatch to it). **This is
flagged, not resolved** — if that specific write path is ever actually exercised in production, it
would write a colliding, differently-shaped entry into the same RTDB node the login/OTP flows depend
on. Out of scope to fix here; named as a finding for your awareness (§9-adjacent, but specifically
an RTDB-data-integrity risk rather than a cross-module one).

**Which claim triggers the Parent App's read:** unchanged from the prior report — only when the
`schoolId` claim is blank but a `school_code` claim is present. This is an edge case in the normal
flow (most logins have both claims populated), not the common path.

## 4. Exact Login Edge Case — traced again, unchanged from Phase 8

Firebase Auth → custom claims → `schoolId` claim checked first → if blank, `school_code` claim
checked → if present, `lookupSchoolCode(school_code)` reads RTDB → returns a `schoolId` string (per
§3) → used for the Firestore `students/{schoolId}_{userId}` doc-id going forward. If RTDB is
unavailable or the key is missing, `lookupSchoolCode()` catches the exception and returns `null`,
`schoolCode` falls back to the raw `claimSchoolCode` value unchanged (`AuthRepository.kt:70`) — a
**graceful degradation already exists**, not a hard crash, for the case where this specific RTDB read
fails.

## 5. Firestore Equivalent Investigation — a materially better answer than Phase 8's

**New evidence this pass, not found in Phase 8:** `Superadmin_schools.php:324` —
`'schoolCode' => (string) $school_code` — is written directly onto the Firestore `schools/{schoolId}`
document during the **current, canonical `onboard()` flow** (confirmed by a second read of the same
field at `Superadmin_schools.php:557`, proving it's consistently populated and consumed elsewhere in
the same file, not a one-off). This means:

**Every school onboarded through the current onboarding flow already has a queryable `schoolCode`
field on its Firestore document.** A simple Firestore query —

```
schools.whereEqualTo("schoolCode", code).limit(1)
```

— would resolve the exact same lookup the RTDB read performs today, **without needing to build or
backfill a separate `indexSchoolCodes` index collection at all.** This is a simpler, lower-risk target
than the `indexSchoolCodes`-collection idea from Phase 8 (which, as previously found, has zero
writers and would have required inventing a whole new index-maintenance path).

**The one remaining unknown, honestly stated:** whether **every currently-active production school**
was onboarded through this current flow, or whether any older school predates the `schoolCode` field
being added to the Firestore document (in which case its Firestore doc would be missing the field even
though its RTDB `Indexes/School_codes` entry is correct). **This pass cannot verify that from source
code alone** — it requires either (a) knowing how many production schools exist and their onboarding
history, or (b) a live, read-only Firestore query counting `schools` docs with a populated vs. missing
`schoolCode` field. Relevant context already on record from this program's own memory: ZenX has been
operating at very small production scale ("1 active school," per `identity_architecture_decision.md`),
which — if still accurate — would make this verification step trivial and low-risk rather than a large
unknown. **Not assumed here as current fact, cited as directly relevant context you can confirm.**

## 6. Multi-Tenant Analysis

This index is structurally different from every other multi-tenant concern in this program: it is
not *tenant-scoped data* (like SRNO, keyed per-school) — it is the **mechanism that establishes which
tenant a login belongs to in the first place**, analogous to how Student ID's global uniqueness
underpins tenant resolution elsewhere in this program. Its uniqueness domain is correctly global by
design: each `school_code` must resolve to exactly one `schoolId` across the entire ZenX platform,
never per-school. The proposed Firestore-query replacement preserves this exactly — a top-level
`schools` collection query, not a sub-collection or tenant-prefixed lookup, is the structurally
correct shape for a "find the tenant" operation, not a defect.

## 7. Decision

**B — MIGRATION REQUIRED. STOPPING after this design, per instruction. No code changed.**

## 8. Migration Design (design only — not executed)

```
RTDB SOURCE
  Indexes/School_codes/{school_code}  →  {schoolId}          (bare string value)

FIRESTORE TARGET (recommended — no new collection needed)
  schools/{schoolId}.schoolCode  =  {school_code}             (already the write shape
                                                                 Superadmin_schools.php:324
                                                                 uses today for every new
                                                                 onboarding)

VERIFICATION STEP (read-only, before any write)
  For every school_code key found under RTDB Indexes/School_codes:
    1. Read the corresponding schools/{schoolId} Firestore document.
    2. Confirm its schoolCode field exists and matches the RTDB key.
    3. Report any RTDB entry whose Firestore counterpart is MISSING or MISMATCHED
       — do not silently overwrite; a mismatch is itself a finding worth surfacing,
       not a mechanical merge.

BACKFILL STEP (only for entries the verification step flags as missing/mismatched)
    schools/{schoolId}.set({ schoolCode: <value from RTDB> }, merge: true)
  — additive, merge-only, never touches any other field on the document.

PARENT APP SWITCH (only after verification + any needed backfill)
  Replace AuthRepository.lookupSchoolCode()'s RTDB read with:
    firestoreService.queryDocumentsAs<SchoolDoc>(Constants.Firestore.SCHOOLS) { ref ->
        ref.whereEqualTo("schoolCode", claimSchoolCode).limit(1)
    }.firstOrNull()?.id   // schoolId is the doc id

RTDB REMOVAL (only after the Parent App switch is confirmed working)
  Remove lookupSchoolCode(), FirebaseService.kt entirely, the firebase-database-ktx
  dependency, and the SchoolSyncApp.kt RTDB persistence init — the true zero-RTDB
  end state for the Parent App.

  NOTE: this step is scoped to the PARENT APP only. §9 found the PHP Admin Panel
  itself (Otp_service.php, Superadmin_migration.php) still depends on the RTDB
  index directly — removing RTDB from the Parent App does NOT retire the index
  itself, which would need its own, separately-authorized migration on the PHP
  side before RTDB could be fully retired platform-wide for this data.
```

**Nothing above was executed.** No Firestore write, no RTDB read beyond what static source analysis
required, no Parent App code change in this pass.

## 9. Out-of-scope dependency discovered — reported, not touched

Per the instruction to report rather than silently expand scope: **the PHP Admin Panel's own
`Otp_service.php` (password-reset / account-recovery) and `Superadmin_migration.php` (an active
migration/reporting tool) still depend on this same RTDB index directly.** This means the "final
zero-RTDB" goal for `Indexes/School_codes` is **larger than the Parent App** — even after the Parent
App migration above is completed, the index itself cannot be safely deleted from RTDB until those PHP
consumers are also migrated. This is a genuinely new, previously-unknown-to-this-program finding, not
something this pass will act on — flagged for your awareness and a separate, explicitly-authorized
future pass if you want the index retired platform-wide.

## 10. Testing

| Check | Status |
|---|---|
| RTDB path liveness (grep across all `.php`/`.js` files repo-wide) | **STATICALLY VERIFIED** — exact file:line citations throughout this report |
| `Otp_service.php` key/value shape inference from its own consumption pattern | **CODE-REVIEWED** — inferred from how the code uses the data, not a live RTDB read |
| Whether RTDB `Indexes/School_codes` currently holds real production entries | **NOT VERIFIED** — no Firebase credentials/console access available in this environment; this is inferred from *who reads/writes it*, not from *reading the data itself* |
| Whether every production `schools` Firestore doc has `schoolCode` populated | **NOT VERIFIED** — flagged explicitly in §5 as the one blocking unknown for the migration's backfill step |
| Any code change in this pass | **NONE MADE** — no build/compile check was needed or run |

## 11. Final Verdict

**YES, AFTER MIGRATION.**

Not a plain YES (the migration design in §8 has one unverified step — whether Firestore already has
complete `schoolCode` coverage, which this pass could not check without data access). Not a NO — the
evidence is strong enough to design the migration with confidence, not stall on genuine unknowns; the
target field already exists and is already populated for every current-flow onboarding, which is a
materially better starting point than Phase 8's report understood. Not forced to YES either, per your
explicit instruction — the one missing verification step (§5) is named plainly, not glossed over.

**What would turn this into a plain YES:** a read-only count of `schools` documents with a populated
`schoolCode` field versus RTDB `Indexes/School_codes` entries. If they match 1:1, the migration in §8
reduces to the Parent App switch step alone, with zero backfill needed.

---

**Nothing in this pass was committed, staged, reset, stashed, or pushed. No code was modified in any
of the three repositories.**
