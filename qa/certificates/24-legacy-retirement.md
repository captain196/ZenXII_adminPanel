# LEGACY CERTIFICATE SYSTEM — RETIRED 2026-09-04 · UNCOMMITTED

## Why it was safe

**Zero certificates had ever been issued.** Verified against the live Realtime Database
before touching anything: 8 production schools, and `Certificates/Issued` held **0 records
in total**. Only two schools even had a `Certificates` branch, both empty, and both were
created by this session's own testing.

Nothing was migrated because nothing existed. A 692-line controller with zero tests that
had never once done its job in production.

## What was removed

| | |
|---|---|
| `application/controllers/Certificates.php` | 692 lines, 14 endpoints, 0 tests |
| `application/views/certificates/index.php` | 1,187 lines |
| `application/config/routes.php` | **16 route lines** + the section header, replaced by a tombstone comment |
| `application/views/include/header.php` | the "Issue Certificates" sidebar menu (4 links) |

Local backups at `/tmp/legacy_certificates_*.bak.php`; both files are also in git history.

## What was deliberately NOT touched

- **The RBAC module key `Certificates`.** It gates `Doc_templates` and is stored per-tenant
  in `schools.staffRoles` and mirrored in `functions/rbac_modules.json`. Renaming it is a
  data migration across every school for zero user benefit. Verified present after removal.
- **`application/libraries/Firebase.php`.** Used by 8+ other controllers.
- **The RTDB data.** The two empty `Certificates` branches remain. Deleting production data
  is the operator's call, not mine, and they hold nothing.
- **`Sis.php`'s TC issuance.** Untouched — it is the good implementation.

## Stale comments corrected rather than left to rot

Four files described the legacy system as "left running":
`Doc_templates.php:14` (now records what it was and why it went),
`Doc_serializer.php:21`, `Roster_helper.php:9`,
`document_targets.php:141` — which now points at `Sis::_get_tc_number()` as the pattern to
copy for numbering, instead of only naming the one not to.

The `header.php` comment on the Documents menu referenced "the RTDB system below". That menu
no longer exists, so the comment was corrected too — a comment describing a neighbour that
has been deleted is worse than no comment.

## Verification

| Check | Result |
|---|---|
| Surviving route or link to a legacy URL | **none** (repo-wide grep) |
| Code reference to the removed class | **none** |
| RBAC key intact | ✔ `Doc_templates.php`, `Staff.php`, `rbac_modules.json` |
| `GET /certificates` | **404** |
| `GET /certificates/generate` | **404** |
| `GET /certificates/issued` | **404** |
| `GET /certificates/get_dashboard` | **404** |
| `GET /doc_templates` | **200** — intact |
| Sidebar | one "Documents" entry; the legacy menu is gone |
| PHPUnit | **580 tests · 4 failures · 27 skipped — baseline** |

## What retiring it did NOT fix

**The RTDB P0 is untouched and is now the top open item in the whole engagement.** It was
never a certificates problem. The live root rule is `.read/.write: auth != null` with no
`Schools` block, and under it sit `Users/{Admin,Parents,Devices}`, `User_ids_pno` (108
entries), `Exits` (108), `Schools/*/Phone_Index` (~90 raw phone numbers for one school) and
`Schools/*/{session}/Accounts`. Every one is readable **and writable** by any authenticated
user of the shared project — which is any parent of any school.

Removing the certificate controller closed a door on an empty room. The building is still
unlocked. See `23-rtdb-P0.md`.
