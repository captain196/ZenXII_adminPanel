# 02 · State machine — Document Engine (Certificates)

**Evidence ceiling: E2 (source-read).** Nothing below is runtime-verified.
Cited from `application/libraries/Doc_template_service.php` unless stated.

## Entity: `documentTemplates/{schoolId}_{templateId}` — the HEAD (a live draft)

States (`TRANSITIONS`, Doc_template_service.php:42): `draft → published → archived`.

**The head is never "published" for long.** `publish()` freezes a snapshot and
immediately returns the head to `draft` at `version+1` (Doc_template_service.php,
`$headPatch`). So `status` on the head is nearly always `draft`; what actually
distinguishes a template is `publishedVersion` and `activeVersion`.

| Field | Meaning | Who moves it |
|---|---|---|
| `version` | the draft being edited now | `publish()` (+1) |
| `publishedVersion` | highest frozen version | `publish()` |
| `activeVersion` | **the pointer every print point resolves** | `activate()` only |
| `lockVersion` | optimistic concurrency token | `save()`, `publish()`, `activate()` |
| `lastProof` | server-minted proof record | `recordProof()` only |

## Entity: `documentTemplateVersions/{docId}_v{n}` — the SNAPSHOT

**Terminal and irreversible by design.** Create-only: `publish()` refuses if the
id exists ("Snapshots are create-only — a published version is never
rewritten"). There is no edit, no delete, no un-publish. This is the entity an
issued document must remain reproducible from years later.

## Transition table

| From | To | Trigger | Guards actually implemented |
|---|---|---|---|
| — | draft v1 | `create()` | schoolId+docType required; id minted create-only with retry (never overwrites) |
| draft | draft | `save()` | `lockVersion` must match, else **refuse** (never merge) |
| draft | draft | `recordProof()` | proof must carry `hash`, `fontManifest`, `mpdfVersion` |
| draft | **frozen v_n** + draft v_n+1 | `publish()` | ① a proof is on record ② `proof.version === head.version` ③ `proof.contentHash === contentHash(head)` ④ version id must not already exist |
| published | **active** | `activate()` | must have `publishedVersion`; requires an atomic multi-document commit or it **refuses**; `exists:true` precondition on the target |
| draft/published | archived | `archive()` | state-machine check only |

## Transitions the implementation PERMITS that the business should not

| # | Gap | Evidence |
|---|---|---|
| T1 | **Archiving the ACTIVE template silently leaves the type with nothing active.** `archive()` correctly clears `activeVersion` ("an archived template cannot stay active") so no dangling pointer is left — but it does not check whether this was the only active template, and nothing warns. The school's Transfer Certificate simply stops resolving. | `archive()`: sets `activeVersion => null` unconditionally, no sibling check |
| T2 | **`archive()` is the only route to "nothing active", and it is a side effect.** There is no explicit de-activate. A school wanting to stop issuing a type must archive the template, which also removes it from the library. | no de-activate method exists |
| T3 | **Archived is terminal.** `TRANSITIONS['archived']` is empty, so an archived template can never be revived — it must be recreated from scratch, losing its version history. Plausibly correct for statutory documents; undeclared either way. | `TRANSITIONS` |

## Transitions the business requires that the implementation CANNOT perform

| # | Missing | Consequence |
|---|---|---|
| T4 | **Issue a document.** No transition produces an issued document for a student. | Nothing prints. `CON-NO_PRINT_IMPL` — by constraint, not oversight |
| T5 | **Roll back to an earlier published version.** A school that activates a bad v5 cannot return to v4 except by... nothing. `activate()` always uses `publishedVersion` (the highest), never a chosen version. | **A bad publish cannot be undone.** Ranked in `05-risk-register.md` |
| T6 | **Delete a template.** No delete path; archive is the closest. Probably correct for statutory documents, but undeclared. | — |

## The pointer that matters

`activeVersion` is resolved by `Doc_resolver::activeTemplate()` and is what every
future print point reads. Everything above exists to protect one invariant:
**exactly one active template per (school, docType), and it is reproducible.**
