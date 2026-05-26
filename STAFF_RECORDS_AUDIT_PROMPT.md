Do a professional adversarial SaaS audit of the Staff Records module (All Staff + New Staff + Edit Staff + Staff Profile + Active/Inactive lifecycle) across all 3 systems:

- Admin: C:\xampp\htdocs\Grader\school
- Teacher: D:\Projects\SchoolSyncTeacher
- Parent: D:\Projects\SchoolSyncParent

Act like a hostile but authenticated real-world user trying to break governance, permissions, identity, role escalation, statutory data integrity, and tenant isolation.

IMPORTANT OPERATING RULES
- Do NOT praise architecture.
- Do NOT summarize what works.
- ONLY report defects, inconsistencies, exploit paths, governance gaps, race conditions, insecure defaults, dead code, and production-grade weaknesses.
- Avoid speculation. Every finding must be tied to actual code paths.
- Distinguish clearly between:
  - client-side validation
  - backend enforcement
  - Firebase rules enforcement
- Treat missing backend validation as a security issue even if UI blocks it.
- Treat stale/dead code as dangerous if it could drift behavior or confuse future maintenance.
- Be adversarial and realistic:
  - "What can a real user do that they should not be able to do?"
  - "What does a staff member with limited role privileges do to escalate?"
  - "What breaks under retries, lag, double-taps, concurrent edits, or offline replay?"
  - "What happens when a deactivated staff member comes back online with cached session?"

OUTPUT FORMAT (STRICT)
Flat numbered list only.

For every issue include:
1. Severity:
   - P0 blocker
   - P1 high
   - P2 medium
   - P3 polish
2. Exact file:path:line citation
3. Exploit scenario in one sentence
4. Root cause in one sentence
5. Professional production-grade fix in one sentence

If multiple files participate, cite all relevant files.

NO GENERIC ADVICE.
NO THEORETICAL ISSUES WITHOUT CODE EVIDENCE.
NO REPEATED FINDINGS.

AUDIT DIMENSIONS

==================================================
1. Behavioral / Governance Integrity
==================================================

Check for:
- staff create authorization (who can add staff)
- staff edit authorization (who can edit which fields)
- staff role assignment gating (who can grant which roles)
- self-edit boundary (staff editing their own profile)
- salary structure edit gating
- bank account edit gating
- statutory ID edit gating (PAN, Aadhaar, PF)
- staff deactivation authorization
- staff deletion authorization
- bulk import authorization
- role override paths
- hidden admin bypasses
- multi-role enforcement consistency
- soft-lock vs hard-lock inconsistencies on deactivated staff

Staff-specific exploit checks:
- Can a staff with `ROLE_TEACHER` only grant themselves `ROLE_ACCOUNTANT` or `School Super Admin`?
- Can `primary_role` be set to a value not in `staff_roles[]`?
- Can `staff_roles[]` contain custom role IDs that don't exist in `Schools/{school}/Config/StaffRoles/`?
- Can the `Position` field be set directly via API, bypassing `_role_id_to_label` derivation?
- Can a non-teaching staff have `teaching_subjects[]` populated, causing subject-assignment validation drift?
- Can a `ROLE_LIBRARIAN` self-assign as a Class Teacher (single-CT-per-section invariant)?
- Can a staff form submit with `is_active=true` but no password set, creating an orphan login?
- Can the deactivation flow be aborted mid-cascade, leaving subjectAssignments live for a deactivated teacher?
- Can a deactivated staff member's existing Firestore session continue to write (FCM/auth token still valid)?
- Can re-activation skip force-password-change?
- Can a salary structure edit propagate into already-run payroll cycles?
- Can a bank account number be edited after a salary slip is generated against the prior account?
- Can a school super-admin demote another school super-admin without secondary approval?
- Can a recruiter (ATS access only) directly create a permanent staff record bypassing the recruitment → onboarding workflow?
- Can staff documents (PAN/Aadhaar/qualifications) be replaced silently without audit trail?
- Can `Date Of Joining` / `joining_date` be edited retroactively to alter payroll backdating?
- Can resignation workflow be skipped via direct deactivation, bypassing clearance checklist?

==================================================
2. UX / Workflow Professionalism
==================================================

Check for:
- disabled buttons during staff-create save
- double-submit protection on new staff form
- retry idempotency on create
- duplicate staff detection (same name + same DOB, same phone, same email)
- optimistic UI rollback on partial-failure
- loading/error/empty states on All Staff list
- offline form-fill replay
- destructive action confirmation (deactivate / delete / role downgrade)
- stale state after edit → back navigation
- duplicate document upload protection
- bulk import preview before commit
- import error reporting per row
- snapshot listener cleanup on Staff list unmount

Look for:
- user can tap "Save Staff" twice creating duplicate records
- bulk import partially applied with no rollback on validation failures mid-batch
- staff form allowing save while document upload still in progress
- staff form allowing save before phone-index uniqueness check resolves
- All Staff list paginating with cursor errors on filtered queries
- multi-role chip picker allowing the same role twice
- assigned_classes dropdown still showing classes the school has removed
- silent failure when a custom role is deleted while staff still reference it

==================================================
3. Cross-System Contract Consistency
==================================================

Validate consistency across Admin, Teacher, Parent:

- collection names (`staff`, `Users/Teachers/{school_id}`, `staffDirectory`)
- document ID scheme (`staffId` format, prefix, generation)
- field names (snake vs camel: `Date Of Joining` / `joining_date` / `dateOfJoining`)
- field types (DOB string vs Timestamp, salary number vs string)
- enums/status values (`active` vs `is_active` vs `status: "Active"`)
- timezone handling on joining/resignation dates
- timestamp source (server vs client)
- DOB serialization format (d-m-Y vs ISO)
- staff_roles[] vs primary_role coherence
- teaching_subjects[] canonicalisation (case, plurals, subject codes)
- assigned_classes[] format (`"Class 8th"` vs `"8th"`)
- gender enum values
- phone number normalisation (with/without country code)
- Position vs primary_role drift
- schoolCode vs school_id vs parent_db_key usage

Specifically detect:
- Admin writes PascalCase legacy keys while Teacher app reads lowercase aliases (or vice versa)
- staff_roles legacy field absent on records pre-dating 2026-03-18
- inconsistent `is_active` boolean (true/"true"/1/"yes") across writers
- Teacher app filtering staff list client-side instead of querying tenant-scoped server-side
- Parent app reading staff profile via a path that doesn't enforce schoolId
- legacy `Designation` field still written somewhere and read elsewhere
- new Staff form writing to one path; All Staff list reading from a different path
- staffDirectory denormalised cache drift from authoritative staff record

==================================================
4. Data Integrity / Atomicity
==================================================

Check:
- atomic staff create across (Firestore staff doc + Firebase Auth user + phone index + roster entry + custom claims)
- partial failure recovery (Auth created but Firestore doc failed; Firestore doc created but custom claim missing)
- orphaned Auth users with no Firestore staff doc
- orphaned phone-index entries pointing to deleted staff
- transactional updates on role changes
- concurrent edits on same staff record by two admins
- concurrent role grant (race between two admins granting different roles)
- idempotent retries on bulk import
- audit logging coverage on every write (create / edit / role change / deactivation / document upload)
- rollback behavior on bulk import mid-batch failure
- delete cascades (subjectAssignments / leave applications / payslips / appraisals / training records)
- document cleanup on staff deletion
- audit log entries on silent backend changes (statutory deduction recompute, role auto-derivation)

Specifically:
- Active/Inactive toggle racing with concurrent login attempt
- subjectAssignments cascade fails silently while staff is deactivated
- session/FCM cleanup completes but custom claim revocation fails
- duplicate staff creation if phone-index check is read-then-write (TOCTOU)
- lost-update on multi-role assignment (admin A removes ROLE_TEACHER while admin B adds ROLE_LIBRARIAN, final state drops one)
- eventual consistency bugs when staff appears in roster before auth credentials propagate
- missing transaction/batch writes around staff_roles[] modifications
- client-generated staffId allowing duplicates under concurrent create

==================================================
5. Migration / Dead Code / Legacy Drift
==================================================

Find:
- stale RTDB code in Staff.php still writing legacy `Users/Schools/{name}/Staff/` paths
- duplicate APIs (`save_staff` vs `add_staff` vs `create_staff`)
- abandoned migration layers (post `SCH_XXXXXX` refactor remnants)
- unused repositories/services (StaffRepository.kt + StaffFirestoreRepository.kt duplication)
- misleading method names (`get_active_staff()` actually returning deactivated too, or vice versa)
- incompatible old schema support (records without `staff_roles[]` falling through to inferred Position)
- dead feature flags around staff role system
- temporary hacks still active (`_seed_staff_roles()` re-running on every request?)
- fallback logic masking failures (RTDB read on Firestore miss hiding migration gaps)
- legacy `Position` field paths that bypass role-derivation
- migrate_staff_roles bulk endpoint still callable in production
- dual-write remnants in `Users/Teachers/{school_id}/...` and parallel Firestore staff collection
- obsolete comments contradicting current behavior (docblocks claiming feature behaves one way while code does another)

Especially:
- `Staff.php::edit_staff` still processing fields that the new schema removed
- `_role_id_to_label` consulted by some writers, ignored by others
- staffDirectory cache regenerated by some operations but not others
- Teacher app `TeacherRepository.getProfile()` reading from a legacy path
- Parent app `MyTeachersFirestoreRepository` reading from staffDirectory while Admin writes to staff

==================================================
6. Insecure Defaults
==================================================

Determine actual defaults when config missing.

Check:
- default `is_active` on new staff (active vs requires-approval)
- default `staff_roles[]` when none specified (empty vs default-teacher?)
- default `primary_role` when staff_roles[] is empty
- default password generation algorithm (entropy, predictability)
- default password transmission (SMS, email, displayed in admin UI)
- default `force_password_change` flag (set vs unset)
- default custom claims on new auth user
- default permission scope when role is undefined
- default document visibility (public vs role-gated)
- default audit logging on staff edits (on vs off)
- default phone-uniqueness enforcement scope (per-school vs global)
- default email-uniqueness enforcement
- default FCM token retention on deactivation
- default session invalidation on role change

Flag insecure fail-open behavior:
- staff with empty `staff_roles[]` defaulting to highest-privilege role
- missing `primary_role` falling back to "School Super Admin"
- force_password_change defaulting to false on import-created accounts
- bulk-import bypassing audit log
- default passwords being human-readable patterns (firstname123, dob-based)

==================================================
7. Identity, Credentials & PII Handling (CRITICAL)
==================================================

Audit the FULL identity pipeline for staff accounts.

Check Auth & Credentials:
- password generation algorithm (cryptographic vs predictable)
- password storage (hashed vs plaintext anywhere in Firestore/RTDB/logs)
- password transmission channel (SMS, email, UI display, WhatsApp)
- credential leakage in audit logs
- credential leakage in browser console / network tab
- force-password-change enforcement on first login (client-side only vs backend-gated)
- password reset flow authorization (who can trigger reset for whom)
- OTP-based recovery (rate limit, TTL, reuse)
- session token TTL on staff deactivation
- FCM token revocation on deactivation
- custom claims propagation latency window where role change is partial
- multi-factor / second-factor for `School Super Admin`
- staff account self-deactivation paths

Check Phone Index & Uniqueness:
- tenant-scoped phone index (`Schools/{school_name}/Phone_Index/{phone}`) vs legacy global `Exits/{phone}`
- TOCTOU window between phone-index check and staff doc create
- ability to register a phone already used by a parent in the same tenant
- ability to register a phone used in another tenant (cross-tenant index leak)
- phone normalisation drift (with/without +91, with/without spaces)
- ability to update phone post-creation without re-checking index uniqueness
- legacy `User_ids_pno/{phone}` paths still consulted/written

Check Email & Identity Drift:
- email uniqueness scope (per-school vs global Firebase Auth)
- collision when same email exists in two schools
- email change post-creation: does Firebase Auth email update? Does login break?
- ability to create staff with no email but with login enabled (orphan auth user)

Check Statutory PII (PAN, Aadhaar, Bank):
- storage encryption at field level (PAN/Aadhaar/bank acc)
- masking in UI (last 4 digits vs full display)
- exposure in audit logs
- exposure in CSV/Excel exports
- access control on PII reads (who can read full PAN/Aadhaar)
- validation (PAN format, Aadhaar checksum/Verhoeff, IFSC format, bank acc length)
- immutability after first save (PAN should be append-only with audit, not silent overwrite)
- cross-tenant exposure via shared employer/group records

Check Documents:
- MIME whitelist vs blacklist on document uploads (PAN scan, Aadhaar scan, qualification certs, prior-experience letters)
- MIME spoofing tolerance
- filename sanitization (path traversal, unicode, control chars)
- filename collision (two staff uploading "aadhaar.pdf")
- oversized upload handling
- total per-staff document quota
- abandoned uploads cleanup
- delete propagation on staff deactivation
- signed vs public URLs on document access
- cross-tenant access via predictable storage path
- direct URL access without auth check
- EXIF stripping on profile photos
- PDF executable payload safety
- attachment visibility to other roles (counsellor seeing accountant's bank details?)

Check Inspection Surfaces:
- firebase-rules/storage.rules — staff document path patterns
- firebase-rules/firestore.rules — staff collection read/write predicates
- the rules for `Users/Teachers/{school_id}/{staffId}` legacy path
- the rules for `Schools/{school_id}/Config/StaffRoles/` (write access scope)

Exploit scenarios to test:
- school A admin reading school B's staff PAN
- a teacher reading another teacher's bank account
- a parent enumerating teacher phone numbers via predictable doc IDs
- direct Firestore SDK query bypassing UI role-filter
- elevation by editing one's own staff doc to add `School Super Admin` to `staff_roles[]`
- password reset triggered for `School Super Admin` by a lower-privilege admin
- replay attack on force-password-change endpoint to skip the change
- using a deactivated staff's cached FCM token to receive notifications
- re-employing a previously-deactivated staff and inheriting their stale role assignments
- creating a staff with a phone number that matches a parent's phone in a different tenant

==================================================
8. Production Reliability
==================================================

Check:
- listener leaks on All Staff list when filters change rapidly
- coroutine/job cancellation on staff profile screen back-press
- memory leaks from large staff lists (>500 records)
- retry storms on staff-create timeout
- bulk import retry amplification (re-uploading entire batch on partial failure)
- Firestore quota abuse from re-fetching full staff list on every screen entry
- unbounded queries on Staff list (no pagination cap)
- missing composite indexes for common filters (active=true + role=ROLE_TEACHER + class=Class 8th)
- N+1 reads when expanding staff card to show roles/subjects/classes
- staffDirectory denormalisation duplication and bloat
- heavy snapshot listeners on the entire staff collection where a single-doc listener would suffice
- background sync edge cases when staff is created offline and replays after schema migration
- excessive Firebase Auth API calls during bulk import
- bulk import not chunked, hitting Firestore batch-write 500-doc limit silently

Flag anything likely to fail at scale (>200 staff per school, >50 concurrent admins, >5,000 staff across a chain).
