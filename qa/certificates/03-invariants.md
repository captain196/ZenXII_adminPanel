# 03 · Invariant catalogue — Document Engine (Certificates)

**Evidence ceiling: E2.** "Enforced" below means *the code contains the check*,
never that it has been observed holding at runtime.

The ecosystem invariants in the v3 spec §3 are in force additionally: no
cross-tenant access, a parent sees only their own children, a locked period
never mutates, a duplicate submission never produces duplicate records.

## Module invariants

| ID | Invariant | Enforced where | Strength | If violated |
|---|---|---|---|---|
| **I1** | **Exactly one active template per (school, docType).** | `activate()` commits one *complete assignment* — winner set, every other nulled, atomically | Enforced, unit-proven for completeness; **atomicity under real contention unproven** | Two "official" certificates. A school issues one document while believing it issues another |
| **I2** | **A published version is never rewritten.** | `publish()` refuses if the version id exists; no update/delete path on `documentTemplateVersions` | Enforced | "Show me the template that produced this certificate" becomes unanswerable |
| **I3** | **Nothing publishes without a server-rendered proof of the CURRENT design.** | `publish()` requires `lastProof`, matching `version` **and** `contentHash` | Enforced (2026-09-02; the proof was previously caller-supplied and forgeable) | A snapshot records a hash no PDF ever produced |
| **I4** | **A proof is minted by the server, never accepted from a caller.** | `publish()` takes no proof parameter; guarded by a reflection test | Enforced structurally | As I3 |
| **I5** | **A concurrent save is refused, never merged.** | `save()` compares `lockVersion` | Enforced; client surfaces a 409 that keeps the draft dirty | Two clerks' edits silently combined into a document neither approved |
| **I6** | **An unresolved merge field never renders blank.** | `Doc_serializer` throws; `CON-FAIL_CLOSED` | Enforced | A statutory field prints empty and the certificate is void |
| **I7** | **A block that overflows its page is refused, not clipped.** | `assertFits()` / `overflowFindings()`, measured in mPDF | Enforced | Text silently truncated on a legal document |
| **I8** | **Every query is scoped by `schoolId`.** | `schoolWhere()` in the controller; PHP-side tenant check on `get_template` | Enforced in the paths reviewed — **A8 is auditing every write path** | Cross-tenant read/write. The panel uses the Admin SDK, so `firestore.rules` does **not** protect this |
| **I9** | **An image src is a school-relative path — no scheme, no traversal.** | `Doc_serializer::guardSrc()`, `Doc_renderer::allowImageRoot()` | Enforced | SSRF, or a local file rendered into a certificate |
| **I10** | **An uploaded asset's type is decided by content, never by extension.** | `upload_asset()`: `finfo` + `getimagesize`; stored under its own content hash | Enforced | A PHP script in a web-served directory |
| **I11** | **A failed action never reports success.** | `api()` requires `r.ok` **and** a parseable body **and** `status !== 'error'` | Enforced client-side, E2E-pinned (U1–U3, U6, U7) | The codebase's standing phantom-success class |
| **I12** | **State transitions are graded.** publish/activate/archive require `manage`; `_remap` refuses an endpoint with no declared capability | `CAPABILITIES` + `_remap` | Enforced fail-closed | Privilege escalation on a legally consequential act |

## Invariants that are DECLARED but NOT YET enforceable

| ID | Invariant | Why not enforceable yet |
|---|---|---|
| **I13** | **A document serial is allocated once and never reallocated.** | No issuance engine exists. Recorded as a `note` on `document_targets.php:fee_receipt` |
| **I14** | **Distinct document types never share a numbering counter.** | Enforced only on the *registry* (`DocResolverTest`), because no counter exists yet |
| **I15** | **A reprint is marked DUPLICATE.** | The serializer supports a duplicate mark; nothing issues, so nothing sets it |
| **I16** | **An issued document is reproducible from its frozen template.** | `Doc_resolver::activeVersion()` returns the snapshot, but nothing records which snapshot an issued document used — there are no issued documents |

## The one that has no owner

| **I17** | **A school always has an active template for a type it is expected to issue.** | **NOT enforced anywhere.** `archive()` clears `activeVersion` with no sibling check and no warning (see `02-state-machine.md` T1). Nothing monitors "type X has no active template". |
