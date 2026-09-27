# PHASE 0 — POLICY ASSIGNMENT ENGINE — GATE REPORT

**Date:** 2026-08-10 · **Status: COMPLETE** · **UNCOMMITTED, NOT PUSHED, NOT DEPLOYED**

---

## 1. What was built

| File | Lines | Purpose |
|---|---|---|
| `application/libraries/Policy_assignment_service.php` | new | the one resolver — "which policy applies to this employee on this date?" |
| `tests/Unit/PolicyAssignmentServiceTest.php` | new | 35 tests / 65 assertions |

**Nothing calls it yet.** Phase 0 is inert by design — zero runtime risk. `Work_schedule_service`
becomes a caller in Phase 1, not a copy.

## 2. Resolution order as built — four levels, per operator decision D4

```
staff  >  department  >  organisation  >  BLOCK
```

`staff` covers both the "pick" (department offers day/night) and the "override" (employee uses
something the department never offered). They differ only in whether the policy is in the
department's offering list — a *validation* question, not a *resolution* one. So there is no
separate level for it.

**D4 as implemented:** the organisation level applies **only when the caller passes an
`organisationPolicyId`** — i.e. only when the operator actually declared weekly-offs. Passing
`null` blocks. This resolves from declared data and never from a rule this code invented. The
retired 2nd/4th-Saturday rule is not present in the file (asserted, §4).

## 3. Why `department` is safe as the key

| Fact | Source | Consequence |
|---|---|---|
| `staff.department` is a **single string** | `Firestore_service.php:1070` | ties are **structurally impossible** |
| `staff_roles[]` is an **array** | RBAC `union(staff_roles[])` | role is **never** a level — it would create ties |

`validateAssignment()` rejects `attachmentType: 'role'` explicitly (test-asserted).

## 4. Bulletproof criteria — evidence

| # | Property | Evidence |
|---|---|---|
| **B1** | exactly one policy or BLOCK | 5 distinct block codes; `test_blocks_when_no_organisation_policy_declared`, `test_empty_catalogue_blocks` |
| **B2** | overlaps refused, never by read order | `test_result_is_independent_of_assignment_order` — reversing input cannot change the verdict |
| **B3** | same-level ties refused | `test_overlapping_staff_assignments_are_refused`, `..._department_...`, `test_ambiguity_blocks_rather_than_falling_through` |
| **B4** | **no fallback path in source** | 3 guards over `token_get_all()`-stripped code: 0 × `satCount` / `date('w'` / `'Sunday'`; 0 × `fallback` / `defaultPolicy` / `DEFAULT_POLICY`; 0 × `$this->fs` / `->set(` / `firebase` |
| **B5** | explainable | `chain` records every level examined; `explain()` renders it. `test_chain_records_every_level_examined` asserts all three appear in order |
| B6–B9 | frozen / historical / isolation / apps | **not applicable in Phase 0** — nothing calls the service, nothing is written, no collection is touched. Due in Phase 1 |

**Guards are asserted against comment-stripped source**, so prose can neither satisfy nor trip them —
the failure mode that cost four correction passes earlier in this programme.

## 5. Gate results

| Step | Result |
|---|---|
| `php -l` both files | **PASS** |
| New suite | **35 tests / 65 assertions — OK** |
| Full Unit suite | **546 tests / 1,723 assertions / 10 failures** |
| vs documented baseline (511 / 10) | **511 + 35 = 546 ✓ · failures unchanged at 10 ✓ — no regression** |
| Cross-module impact | **none** — new file, zero callers, no collection touched |
| Firestore rules / indexes | **none required** — no data access. `COLLECTION` constant declared for Phase 1 |
| Performance | pure in-memory resolution, no I/O |

## 6. Extensibility hook — built, deliberately inert

```php
const RESERVED_LEVELS = ['location', 'employment_type'];
```

Writable now, never resolved until promoted into `LEVELS`. Two tests hold this line:
`test_reserved_levels_never_win_resolution` and `test_reserved_levels_are_accepted_by_validation`.
This keeps the two known future cases (§8 of the execution plan — visiting faculty, multi-campus)
reachable as a new value in an existing field rather than a resolver rewrite.

## 7. Design decisions worth recording

1. **Expired assignments fall through; ambiguous ones do not.** A lapsed staff override should
   drop to the department — that is what an administrator means by an end-dated exception. An
   overlap is a data error and must stop everything.
2. **`findOverlaps()` is the first line of defence.** Callers check before writing so an ambiguous
   state is never persisted; `resolve()` blocking on it is the second line, not the first.
3. **Expired is reported in preference to missing** when a lapsed assignment was seen anywhere in
   the chain — the two are fixed differently by an administrator.

## 8. What Phase 0 does NOT do

- Does not read or write Firestore — callers supply catalogue and assignments
- Does not resolve `location` or `employment_type`
- Does not touch `Hr.php`, `Work_schedule_service`, attendance, or any payroll path
- Does not create, migrate or seed any data

## 9. Next

**Phase 1 — schedules + assignments admin surface.** The unblock: without it no tenant can configure
anything and payroll remains blocked for all 8 tenants. Phase 1 is where B6–B9 first apply, and
where the `weeklyOffs` pre-fill (D4) becomes a visible, confirmable form field.

---

**UNCOMMITTED. Not pushed, not deployed, not migrated, not seeded. PR0003 and all April evidence
untouched. UAT remains paused at Test 4.**
