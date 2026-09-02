# 00 · Dependency graph — Document Engine (Certificates)

**Evidence ceiling: E2 (source-read).** Panel + Firebase surfaces, established
directly this session. App surfaces are reported by A3 in `01-surface-behaviour.md`;
adjacent/legacy systems by A1.

## Artefact inventory — admin panel (`~/Desktop/Zennxii_adminPanel`)

| Layer | Artefact | Role |
|---|---|---|
| Route/controller | `application/controllers/Doc_templates.php` | 3 page loads + **14 AJAX endpoints**, all wired. Central capability gate in `_remap()` |
| View | `application/views/doc_templates/index.php` | Single view; screens D0/D1/D2 switched client-side. Emits `#zxdt-boot` (csrf name+hash, base url, canEdit/canManage, templateId) |
| Client | `assets/js/doctemplates/designer.js` (~3.9k lines) | The designer. Server layer `api()`/`srv`/`srvSaveDraft`, boot hydration, debounced autosave |
| Client CSS | `assets/css/doctemplates.css` | Scoped under `.zxdt` (the `.att-grid` collision precedent) |
| Library | `Doc_serializer.php` | model → HTML. **One serializer, two sinks** (browser preview + mPDF) |
| Library | `Doc_renderer.php` | mPDF. `useOTL 0xFF`, Lohit per script, `fontManifest()` hashes font FILES |
| Library | `Doc_template_service.php` | Lifecycle: create/save/recordProof/publish/activate/archive; `contentHash()` |
| Library | `Doc_block_service.php` | Shared blocks; the *offer* model (offered, never pushed) |
| Library | `Doc_contract.php` | Merge-field contracts, `validateBundle`, `sampleBundle`, catalogue |
| Library | `Doc_compliance.php` | Authority versions + re-validation **report**. Structurally cannot mutate |
| Library | `Doc_resolver.php` | **Read-only seam.** Structurally cannot issue or write |
| Config | `application/config/doc_types.php` | 30 merge fields, 6 contracts, 8 doc types |
| Config | `application/config/document_targets.php` | **Print-point registry — 8 declared, 0 wired** |
| Helper | `application/helpers/rbac_helper.php` | `has_permission()` / `require_permission()`; module key `Certificates` |
| Helper | `audit` helper → `log_audit()` | Events land in the existing Audit Logs viewer |

## Firestore

| Collection | Written by | Read by | Rules |
|---|---|---|---|
| `documentTemplates` | panel only (Admin SDK) | panel; `Doc_resolver` | **live**, identical to branch |
| `documentTemplateVersions` | panel, create-only | panel; `Doc_resolver` | **live**, identical |
| `complianceAuthorities` | out of band | `Doc_compliance` | **live**, identical |
| *(4 composite indexes)* | — | — | **live**, `not-deployed 0` |

`uploads/{schoolId}/doctemplates/assets/` — content-hash-named images
`uploads/{schoolId}/doctemplates/_proofs/` — proof PDFs *(web-reachability is an A8 question)*

## Flow — authoring

```
clerk → designer.js → api() ──POST──▶ Doc_templates::save ──▶ Doc_template_service::save
                                            │                        │ lockVersion CAS
                                            └── json_success ◀───────┘
```

## Flow — the gate that makes a certificate lawful

```
proof_pdf ─▶ reads template FROM THE STORE (never the request body)
          ─▶ Doc_serializer::render (every declared language, p95 sample)
          ─▶ Doc_renderer::render → real PDF bytes
          ─▶ recordProof: {hash, contentHash, fontManifest, mpdfVersion, version}
                                    │
publish  ─▶ reads lastProof FROM THE HEAD, verifies version + contentHash
          ─▶ create-only snapshot → documentTemplateVersions
activate ─▶ ONE atomic :commit, complete assignment  ──▶ activeVersion
                                                              │
                                              Doc_resolver ◀──┘  (read-only)
                                                     │
                                     [ SEAM — nothing beyond this line exists ]
                                                     ▼
                              Accounts · Students · Staff · Payroll · Parent app
                                        (8 print points, 0 wired)
```

## What is NOT connected, and deliberately so

- **No print point anywhere.** `CON-NO_PRINT_IMPL` (HARD, operator brief).
- **No issuance**: no numbering, no `issuedDocuments`, no archival, no QR.
- **Neither Android app touches `documentTemplates`** — confirmed by A3.
- `Doc_resolver` is the only thing another module may call, and it can only read.
