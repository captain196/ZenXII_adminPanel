# Stream-Save Architecture Review (Design Recommendation Only)

**Scope:** Whether "Save All Streams" should keep firing N sequential `save_stream` requests, or move to a
single atomic bulk write. **No code is proposed for implementation here** — this is a decision document.

**TL;DR recommendation:** **Option B — a dedicated `save_streams` bulk endpoint doing one atomic CAS write** —
with the existing `save_stream` retained for individual edits. It is the lowest-risk option, makes streams
**consistent with the rest of the module** (Classes/Sections/Subjects already save in one request), and the
atomic-bulk write for streams **already exists** in `seed_streams()` — so this is a convergence, not new invention.

---

## 1. Current state — how ZenX School Config saves today (code-verified)

| Screen | Client handler | Requests per "Save All" | Server write | Data model | Atomic? |
|---|---|---|---|---|---|
| **Streams** | `saveAllStreams()` → `saveNext()` loop ([index.php:3519](application/views/school_config/index.php#L3519)) | **N** (one `save_stream` per stream) | `save_stream` → 1 CAS `commitBatch` per call ([School_config.php:2500](application/controllers/School_config.php#L2500)) | `schools/{id}.streams` **map field** | ❌ per-stream (N separate writes) |
| **Classes** | `saveClasses()` → `post('save_classes', {classes})` ([index.php:2480](application/views/school_config/index.php#L2480)) | **1** | `fs->update('schools', …, ['classes'=>…])` — whole array ([School_config.php save_classes](application/controllers/School_config.php)) | `schools/{id}.classes` **array field** | ✅ single-doc write |
| **Sections** | `post('bulk_save_sections', {changes})` ([index.php:2939](application/views/school_config/index.php#L2939)) | **1** | `bulk_save_sections` — loops per-row add/remove ([School_config.php:1548](application/controllers/School_config.php#L1548)) | `sections` **collection** (doc per section) | ⚠️ 1 request, per-row writes |
| **Subjects** | `post('save_bulk_subjects', {subjects})` ([index.php:3274](application/views/school_config/index.php#L3274)) | **1** | `save_bulk_subjects` — loops `setEntity('subjects',…)` | `subjects` **collection** (doc per subject) | ⚠️ 1 request, per-row writes |
| **Sessions** | `add_session` (one at a time) | **1 per add** | CAS `commitBatch` on `schools.sessions` | `schools/{id}.sessions` **array field** | ✅ (single-add, not a "save all") |

**The finding:** **Streams is the only "Save All" screen that fans out into N HTTP requests.** Every other
multi-item screen already saves in a **single request**. Streams is the outlier — almost certainly because it
was built by reusing the single-item `save_stream` endpoint in a client loop, rather than a set endpoint.

**Two facts that make Option B nearly free:**
1. **Streams is a map field on the `schools` doc** — exactly like `classes` is an array field. A whole-map
   write is naturally atomic (one document write), no cross-collection coordination needed.
2. **The atomic-bulk write already exists.** `seed_streams()` ([School_config.php:1096](application/controllers/School_config.php#L1096))
   already reads the doc, merges the **entire** streams map, and writes it in **one CAS `commitBatch`**. A
   `save_streams` endpoint is `seed_streams` with client-supplied streams instead of hardcoded defaults.

---

## 2. Comparison to production ERPs

> Honesty note: the internal request/transaction design of Fedena, MyClassCampus, Entab, Teachmint, and
> PowerSchool is proprietary and not publicly documented at the endpoint level. The points below are
> **industry-standard architectural patterns** these classes of systems are known to follow, not
> reverse-engineered internals. The strongest argument for ZenX is **internal consistency** (§1), not these.

| System (class) | Observable/known pattern for setup/config data | Bearing on ZenX |
|---|---|---|
| **PowerSchool** | Documented data APIs are **transactional/bulk** (PowerQuery, bulk data import, transactional record sets). Config is treated as data mutated in transactional units. | Favors atomic bulk (Option B/C). |
| **Fedena** (Rails, open-source) | Rails convention saves a form's **whole nested resource in one submit** inside a DB transaction; setup pages are form-level saves, not per-row AJAX loops. | Favors atomic form/bulk save. |
| **Teachmint / MyClassCampus / Entab** (modern SaaS) | Setup wizards save each step's configuration **as a unit** with a single confirm; users don't observe per-item network chatter. | Favors single-request bulk. |
| **General ERP principle** | Configuration/reference data is **low-frequency, small-cardinality, high-integrity**. The accepted pattern is **atomic form-level saves**; per-item request fan-out is considered an anti-pattern for config because it splits one user intent ("save my streams") across many independently-failing writes. | Directly supports Option B. |

**Takeaway:** across the board, production systems treat a setup screen's "save" as **one atomic unit of work**.
ZenX already does this for Classes/Sections/Subjects; Streams is the one screen out of step with both the
industry norm *and* its own module.

---

## 3. The options

### Option A — Keep sequential per-stream saves (status quo)
Client loops `save_stream` N times. Works correctly now (post the stale-cache fix), but one user action =
N requests = N CAS writes.

### Option B — Dedicated `save_streams` bulk endpoint, one atomic CAS write  ★ recommended
New server method validates the full stream set, reads `schools`, replaces the `streams` map, writes it in
**one `commitBatch` with the `updateTime` precondition** (mirrors `seed_streams`). Client `saveAllStreams()`
becomes a **single** `post('save_streams', {streams})`. `save_stream` stays for single add/edit.

### Option C — Generic "config-document PATCH" endpoint
A single endpoint that atomically patches any whitelisted `schools`-doc field (`streams`, `classes`,
`sessions`, board, …) under one CAS discipline. Architecturally elegant and would unify Streams+Classes+Sessions,
but it is a **larger refactor**, touches working screens, and concentrates risk. **Not now** — good north-star,
premature today.

---

## 4. Option comparison across the requested dimensions

| Dimension | A — sequential (status quo) | **B — bulk `save_streams`** ★ | C — generic config PATCH |
|---|---|---|---|
| **Performance** | N reads + N CAS writes + N lock acquire/release per Save-All | **1 read + 1 write + 1 lock** — best | 1 read + 1 write, but dispatch overhead |
| **Scalability** | Degrades linearly with stream count; worst under the 300 s cache window | **Flat** regardless of count | Flat; scales across all config fields |
| **Network traffic** | N round-trips (each ~cross-region) | **1 round-trip** | 1 round-trip |
| **Atomicity** | ❌ Partial-failure possible ("3 of 5 saved") — inconsistent intermediate state | ✅ **All-or-nothing** (single map write) | ✅ All-or-nothing per field |
| **User experience** | Progress/partial-failure UI needed; slower; the pattern that surfaced the false-409 | ✅ One save, one result, one toast; instant | ✅ Same, module-wide |
| **Maintainability** | Client owns loop + partial-failure renderer (`renderBulkStreamFailures`) | ✅ Server owns integrity; **mirrors `seed_streams`**; less client logic | ⚠️ New abstraction to learn; broad blast radius |
| **Rollback** | Mid-batch failure leaves a partial set; retry re-writes survivors | ✅ Failure = **nothing changed**; clean retry | ✅ Clean per field |
| **Concurrency** | N separate CAS windows; more chances for a real 2-admin race mid-loop | ✅ **One CAS window** — tighter, fewer race points; preserves optimistic lock | ✅ One CAS window per field |
| **Future extensibility** | Adding per-stream metadata multiplies request cost | ✅ Extra fields ride the same single write (order, color, subjects-per-stream) | ✅ Most extensible, but overkill now |

**A is strictly dominated by B** on every dimension except "no new endpoint." **C is better long-term but
over-scoped** for this need.

---

## 5. Should `save_stream` remain, or should both coexist?

**Both should coexist — this is already the module's established pattern.** ZenX already ships individual +
bulk siblings elsewhere:

- `save_section` (individual) **+** `bulk_save_sections` (bulk)
- `save_subject` (individual) **+** `save_bulk_subjects` (bulk)

Streams should match: **keep `save_stream`** for the "Add / Update Stream" form (single edits, inline toggle),
and **add `save_streams`** for the "Save All Streams" action. `delete_stream` stays as-is (with its dependency
guard). No breaking change; the individual endpoint is still the right tool for one-off edits.

---

## 6. Other screens — should they migrate for consistency?

| Screen | Verdict |
|---|---|
| **Classes** | **Already correct** — single-request, whole-array atomic write. It is the model to copy for Streams. (Minor future note: it uses `fs->update` with **no CAS precondition** — last-write-wins; a separate, optional hardening, not part of this review.) |
| **Sections / Subjects** | **Already single-request bulk**, so the UX/traffic goal is met. Internally they do **per-row writes to a collection** (not a single atomic batch) — because each section/subject is its own document and per-row failure reporting is a deliberate feature. Converting them to fully-atomic batches is a **separate, optional** future hardening with real trade-offs (loses granular partial-failure UX). **Not recommended as part of the streams change.** |
| **Sessions** | **Fine as-is** — sessions are added one at a time by nature (each triggers session-setup side effects); there is no "save all sessions" action to bulk. |
| **Streams** | **The one to migrate** (Option B). |

**Consistency conclusion:** migrating **Streams → Option B** brings the last outlier into line with Classes
(single-field atomic) and matches the request-level behavior of Sections/Subjects. No other screen needs to
change to achieve consistency.

---

## 7. If Option B is approved later — shape & effort (for planning only)

- **New:** `School_config::save_streams()` — validate each `{key,label,enabled}` (reuse `save_stream`'s rules:
  `^[A-Za-z0-9_]+$` key, ≤100-char label), acquire the existing `'streams'` config lock, read `schools`,
  replace the `streams` map, write via one `commitBatch` with the `updateTime` precondition, `log_audit`.
  ≈ a trimmed merge of `seed_streams` + `save_stream`.
- **New route:** `school_config/save_streams`.
- **Client:** `saveAllStreams()` → one `post('save_streams', { streams: JSON.stringify(CFG.streams) })`;
  keep `renderBulkStreamFailures` only as a fallback (bulk is all-or-nothing, so it becomes a single error path).
- **Unchanged:** `save_stream`, `delete_stream`, `seed_streams`, data model, Firestore rules/indexes
  (same `schools` doc, same `streams` field), and the just-committed `commitBatch` cache-invalidation fix
  (which this would rely on and benefit from).
- **Risk:** Low, isolated to the Streams tab; individual-edit path untouched; fully backward-compatible.
- **Effort:** ~1 controller method + 1 route + a small JS swap. No schema/rules/index change.

---

## 8. Recommendation

Adopt **Option B** as a **future enhancement** (not urgent — the correctness bug is already fixed; this is an
efficiency + consistency + integrity improvement). It:

- removes the last per-item request fan-out in School Config,
- gives streams **true atomicity** (better than Sections/Subjects, on par with Classes),
- **reuses an already-proven in-file pattern** (`seed_streams`),
- keeps `save_stream` for individual edits per the module's own convention,
- and requires **no** schema, rules, index, or cross-module change.

Defer **Option C** (generic config PATCH) as a possible longer-term unification if/when more `schools`-doc
config fields want shared atomic-save plumbing.

**This document is a recommendation only. No endpoint, route, client, or schema change has been made.**
