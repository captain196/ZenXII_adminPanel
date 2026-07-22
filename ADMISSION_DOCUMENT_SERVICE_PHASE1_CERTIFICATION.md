# Document Service Phase 1 — Final Implementation Verification & Certification

**Scope:** Admission-only Canonical Document Service. **Status:** verified; 2 genuine issues found & fixed, 5 hardening items recorded. **No commit, no push.**
**Date:** 2026-07-22

> This is an adversarial verification pass, not a feature pass. Each of the 8 requested areas was traced in code. Two genuine defects were found and fixed (one HIGH); the rest are recorded with severity. Nothing outside Admission was modified.

---

## Findings summary

| # | Severity | Area | Status |
|---|---|---|---|
| **F1** | **HIGH** | `enroll_student` (CRM approve→enroll path) created the student with **empty Profile Pic + Doc[]**, dropped the parent's uploaded documents, and created **no `student` link** — the revised-D1 objective was wired only into `save_admission` (counter path). | **FIXED** |
| **F2** | **LOW** | CRM detail rendered predefined + additional documents in one flat grid, not the spec's dedicated "Additional Documents" section. | **FIXED** |
| F3 | MEDIUM | Concurrent `replace` of the same document uses a non-atomic version bump → a silently-lost replacement and (when extensions differ) one orphan object. | Recommend (not blocking) |
| F4 | MEDIUM | Concurrent `unlink`/`delete` of the **same** link double-decrements `linkCount` (no CAS between the active-check and the decrement). | Recommend before Phase-2 auto-purge |
| F5 | LOW | `link`/`unlink` pair the link write and the counter increment as two ops → a transient mid-pair failure undercounts `linkCount`. | Recommend (reconciler recompute) |
| F6 | LOW | `purge` deletes Storage then metadata without checking the Storage-delete result → a failed Storage delete leaves an orphan. | Recommend (check + log) |
| F7 | LOW | `validate()` allows a non-`is_uploaded_file` path when it is a real file (internal-staging escape) — acceptable since CRM/public paths pass `$_FILES` tmp names. | Note only |

**No Critical issues. Phase 1 is production-ready for its wired Admission scope after F1+F2 (applied).** F3–F7 are rare/latent and do not cause active data loss in the wired flows; **F4 must be resolved before the Phase-2 automated retention reconciler/force-purge is built.**

---

## 1. Storage Cleanup — orphan analysis per operation

| Operation | Storage outcome | Orphan? |
|---|---|---|
| **Upload** | 1 object + metadata. On metadata-write failure the object is deleted (`Document_service.php:123-127`). Optimizer temp `@unlink`'d. | **No** |
| **Replace** | Upload new → repoint metadata → delete old (`:158-179`). On metadata-update failure the **new** object is deleted, old retained (`:177`). Predefined single-version path = one live object per doc. | **No** (single-admin) |
| **Delete** (force=false, still shared) | `unlink` drops refcount; object kept because another module still links it. | **No** |
| **Delete** (force=false, orphaned) | Retention clock starts; object **retained by design** until the window elapses (metadata present ⇒ not an orphan). | **No** (retained) |
| **Enrollment** | `link('student')` only — **no bytes moved/copied**. No new object. | **No** |
| **Retention start** | `unlink` sets `retention.eligibleAt`; nothing deleted. | **No** (retained) |

**Exceptions (findings):** F3 concurrent replace (differing ext) → 1 orphan; F6 purge with a failed Storage delete → 1 orphan. Both are covered above.

**Replace-retires-old demonstrated:** `Document_service.php:179` — `if ($oldPath !== '' && $oldPath !== $newPath) $this->fb->deleteStorageFile($oldPath);` after the new object + metadata are committed (safe order). The CLI verifier (`document_service_verify run`) asserts version bumps 1→2 across a replace.

---

## 2. Firestore Performance — measured op counts (Phase 1 vs previous reference-copy impl)

Counted directly from the code paths. "Previous" = pre-Phase-1 (URLs stored inline on the application; reference-copy at enrollment; no replace/verify/audit).

| Operation | Impl | FS reads | FS writes | Storage ops |
|---|---|---|---|---|
| **Upload** 1 doc | Previous | 0 | 0 (inline on app) | 2 (put + url) |
| | **Phase 1** | **1** | **6** (metadata, link, increment, retention, 2× audit) | 2 |
| **CRM display** (N docs) | Previous | 1 (`get_application`) | 0 | 0 |
| | **Phase 1** | **1** | **0** | 0 — *rendered from denormalized `documentRefs`; **zero** extra reads* |
| **Replace** 1 doc | Previous | — (no capability) | — | — |
| | **Phase 1** | ~2 | ~3 | 3 (put + url + delete old) |
| **Verify** 1 doc | Phase 1 | ~2 | ~3 | 0 |
| **Delete** 1 doc | Phase 1 | ~4 | ~5 | 0–1 (purge only if eligible) |
| **Enrollment** (per doc) | Previous | ~0 (1 lead read total, inline copy) | 0 | 0 |
| | **Phase 1** | **1/doc** | **4/doc** (link, increment, retention, audit) | 0 |

**Explanation of the deltas.**
- **Increase (writes):** upload and enrollment each add per-document writes — canonical **metadata**, a **link**, an **atomic refcount**, and a per-document **audit** row. This is the deliberate cost of lifecycle correctness (reference counting → W4 safety), per-document audit/compliance, and central retention. Enrollment adds ~4 writes/doc (≈16 for a 4-doc student) at a once-per-student event.
- **No increase (reads / display):** the read-heavy path — every CRM list and detail render — is **unchanged (zero extra reads)** because documents render from the denormalized `documentRefs` pointer. This is where volume lives, and it was kept free.
- **Storage:** flat, except replace adds one delete (the orphan-prevention) — a net storage *win*, not a cost.

Net: Phase 1 shifts a bounded number of **writes** onto low-frequency lifecycle events to buy audit, refcount safety, and storage savings, while keeping the **high-frequency read path at zero extra cost**.

---

## 3. Concurrency

| Scenario | Verdict | Evidence |
|---|---|---|
| **Two admins verify same doc** | ✅ Safe — last-writer-wins on a valid status; no corruption (both write an allowed enum; refcount/links untouched). | `verify()` `:260-273` |
| **Concurrent enrollment (same application)** | ✅ Safe — `enroll_student` holds the `Accounting_idempotency` `ENROLL_{appId}` claim (exactly-once); and `link()` is idempotent via atomic create-if-absent, so even a retry never double-counts. | `Sis.php` enroll idempotency + `link()` `:194-213` |
| **Two admins link same (doc,module,entity)** | ✅ Safe — `firestoreCreate` is atomic (409-loses); exactly one increment. | `:194-200` |
| **Two admins replace same doc** | ⚠️ **F3 MEDIUM** — version is read-then-+1 non-atomically; both target `_v{n+1}`. Lost update always; orphan when the two files' extensions differ. No refcount/link corruption; the live object is always a valid version. | `replace()` `:154-179` |
| **Two admins delete/unlink same link** | ⚠️ **F4 MEDIUM** — both read `active=true` then both decrement → `linkCount` double-decrements. Latent: Phase 1 never auto-purges (delete is `force=false`; the eligibleAt was just set to +180d), so no immediate data loss — but a wrong (too-low) count would mislead the **Phase-2** reconciler/force-purge. | `unlink()` `:221-225` |
| link write + increment not transactional | ⚠️ **F5 LOW** — a mid-pair write failure undercounts; the links themselves remain the source of truth and a reconciler recompute fixes drift. | `:199-209` |

**Recommended fixes (not required for Phase 1's wired scope):** F3 → optimistic-concurrency (conditional update on `version`) or reconciler cleanup of loser objects; F4 → CAS the `active:true→false` transition and only decrement when *this* writer performed it (or recompute `linkCount` by counting active links before any purge decision). F4 is a **prerequisite before Phase 2** wires any automated/forced purge.

---

## 4. Legacy Compatibility (pre-Document-Service applications)

Verified by code path — old applications have **no** `documentRefs`; every consumer branches to the legacy fields, so **no migration is needed**:

- **Open / display:** CRM `viewApp` → `if documentRefs → managed cards; else → read-only tiles from photo_url/aadhaar_url/documents[]` (`applications.php`). Old apps render exactly as before.
- **Enroll:** `_admission_docs_for_student()` prefers `documentRefs`, **falls back** to `photo_url`/`aadhaar_url`/`documents[]` (`Sis.php`), so old apps still populate the student's Profile Pic + Doc[] on enrollment (now on **both** paths after F1) — with no links to create (no canonical ids exist).
- **No invalidation:** legacy fields are still written on new uploads; nothing was renamed or removed.
- **Boundary (by design):** an old app's documents have no `documentId`, so they render **view/download-only** (no verify/replace/delete buttons) — correct, since no canonical record exists to manage. Not a defect.

---

## 5. UI Verification

- **Public form:** each predefined type has its own dedicated input (`photo`, `aadhaar_card`, `birth_certificate`, `transfer_certificate`, `marksheet`, `medical_certificate`); only the generic `documents[]` picker maps to `additional`. Routing is by input name → type, so a predefined file can never land in Additional.
- **CRM detail (after F2 fix):** predefined documents render under **"Photo & Documents"**; only `category==='additional'` render under a separate **"Additional Documents"** heading (`applications.php`, `preCards`/`addCards` split).
- **Per-document actions present:** Preview (thumbnail/opens new tab), Download (tokenized URL), **Verify** (✓), **Reject** (✗), **Replace**, **Delete**, and a live **verification-status badge** (pending/verified/rejected) — all on each managed card, re-rendering the panel after each action.

---

## 6. Security

| Control | Verdict |
|---|---|
| Permission enforcement | ✅ `verify`/`replace`/`delete` each call `_require_role(MANAGE_ROLES, …)`; public upload is intentionally unauthenticated by design. |
| Tenant isolation | ✅ service scoped to `$this->school_name`; every `read()` re-checks stored `schoolId`; cross-school read returns null (asserted by the verifier). `documents`/`documentLinks` deny-all in rules. |
| Replace authorization | ✅ role gate **+ ownership guard** (`_crm_doc_owned` — the documentId must be referenced by *this* application) + server-side MIME/ext/size re-validation of the new file. |
| Delete authorization | ✅ role gate + ownership guard; `force=false` so a CRM delete can never purge a document an enrolled student still references (W4-safe). |
| Upload hardening | ✅ finfo MIME (must match type), ext allow-list, per-type caps, deny-list (`svg/html/js/php/exe/double-ext`), extension derived from validated MIME. |
| Minor (F7) | `is_uploaded_file` is soft-bypassed for real-file paths (internal staging); acceptable — CRM/public inputs come from `$_FILES`. |

---

## 7. Storage Savings — validation & assumptions

**Assumptions (explicit, conservative):** one uploaded photo (2.5 MB raw → 0.15 MB after GD 1200px/JPEG-q82 ≈ 16×) + three ~0.4 MB documents = 3.7 MB raw / 1.35 MB canonical per student; ~100% enroll (worst case). Three implementations:
- **A · Naive** (unoptimized + copy-on-enroll): 7.4 MB/student.
- **B · Pre-Phase-1 ZenX** (reference-reuse, unoptimized): 3.7 MB/student.
- **C · Canonical Phase 1** (link + optimized): 1.35 MB/student.

| Scope | A · Naive | B · Pre-P1 | **C** | C vs A | C vs B |
|---|---|---|---|---|---|
| 1k students | 7.4 GB | 3.7 GB | **1.35 GB** | 82% | 63% |
| 5k students | 37 GB | 18.5 GB | **6.75 GB** | 82% | 63% |
| 100 schools (100k) | 740 GB | 370 GB | **135 GB** | **82%** | **63%** |

**Honest characterization of the levers:**
- **vs B (63%)** is almost entirely **photo optimization** — the reference model is unchanged (pre-Phase-1 already referenced at enrollment, so it never double-stored). This is the *real, today* saving.
- **vs A (82%)** additionally counts the **de-duplication** the reference model gives over a copy-on-handoff design (the MOVE/shared-copy family the ADR rejected). This saving is *hypothetical today* (one enroll hand-off) but **compounds in Phase 2**: the same Aadhaar linked by Admission + Student + Hostel + Transport is **1×** under references vs **4×** under copies.

**Further optimization (unchanged from the impl report):** content-hash dedup (identical re-uploads → 0 bytes), WebP/AVIF photo encode (−25–35%), PDF normalization, an orphan-reclaim reconciler, and a Nearline tier for archived docs.

---

## 8. Production Certification

**Verdict: CERTIFIED for Phase 1 (Admission-only) production use** — after the F1 (HIGH) and F2 (LOW) fixes applied this pass, and subject to the boundaries below.

- **F1 fixed** — the primary public-admission flow (`enroll_student`) now carries the parent's documents onto the student **and** creates the canonical `student` link (revised D1), with a legacy fallback. Both enrollment paths (`save_admission` counter + `enroll_student` CRM) are now consistent.
- **F2 fixed** — predefined vs Additional sectioning honored in the CRM.
- **F3–F7** are recorded; none causes active data loss in Phase 1's wired flows (no automated purge; concurrent same-document admin actions are rare). **F4 is a hard prerequisite before Phase 2** builds the automated retention reconciler / force-purge.
- **`php -l` clean** on all changed files; cross-module trail unchanged (Kotlin apps read student `Doc[]`, unaffected).

### Changed this verification pass
| File | Change |
|---|---|
| `application/controllers/Sis.php` | F1 — `_admission_docs_for_student()` helper; `enroll_student` now populates Profile Pic + Doc[] and creates `student` links (D1) with legacy fallback. |
| `application/views/admission_crm/applications.php` | F2 — predefined vs "Additional Documents" section split. |

*No commit. No push. No module outside Admission modified. Awaiting your review.*
