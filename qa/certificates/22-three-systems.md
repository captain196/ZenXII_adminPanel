# THE CERTIFICATES MODULE — three systems, none aware of the others

Deep investigation, 2026-09-04. Prompted by the operator's runtime report that the legacy
system "is damn bad", which turned out to understate it.

## The map

| | Numbering | Durable artefact | Tests | Issues? |
|---|---|---|---|---|
| **SIS** · `Sis.php` + `views/sis/tc_print.php` | **atomic claim-doc CAS**, hardened by a documented fix | 656-line **hardcoded** print view | — | **Yes — Transfer Certificates only** |
| **Legacy** · `Certificates.php` + RTDB | **read-increment-write** | **none — see L-11** | **zero** | **Yes — every type** |
| **Documents** · `Doc_templates.php` + Firestore | n/a | versioned, proofed, multilingual templates | 16 files | **No, by constraint** |

`views/sis/tc_print.php` contains **zero** references to `documentTemplates`, `Doc_resolver`
or `activeVersion`. The template a school designs and activates in Documents has no
connection to the transfer certificate SIS actually prints.

## What the legacy system does when it issues (L1, E2, all cited)

`generate_certificate()` (`Certificates.php:402-527`): resolve template → load the student
from `Users/Parents/{parent_db_key}/{userId}` → mint a number → substitute 20 placeholders
into the template's title and body → `set("Issued/{certId}", …)` → return to the client.

### L-1 · The number is minted by read-increment-write · **P1**
`:443-445` — a bare `get()`, `+1` in PHP, then `set()`. No transaction, no lock;
`Firebase.php` exposes only non-transactional `get/set/update/delete`. Two concurrent
issuances derive the **same** `certNumber` AND the same `certId`, and the second `set()`
**silently overwrites the first student's issued record**. Not merely a duplicate number —
the record of a certificate already handed to a family is destroyed, with no error.
The identical shape is reused for the custom-template counter at `:244-245`.

### L-2 · No idempotency anywhere · **P1**
Nothing checks for an existing certificate before issuing (`:402-527`). The same student,
type and template can be issued without limit — a double-click is a second certificate.

### L-3 · Revocation does not revoke · **P1**
`revoke_certificate()` sets a `revoked` flag (`:619-623`). `get_certificate()`
(`:572-600`) **never reads it**. Only the dashboard filters (`:122`). **A revoked
certificate remains fully retrievable and printable through its own endpoint.** For a
statutory document this is the most serious finding in the legacy system: the product
offers an action whose entire purpose is to invalidate a document, and the document stays
valid.

### L-11 · No certificate document is ever produced · **P2, and decisive**
`pdfUrl` is hardcoded `''` (`:515`). There are **zero PDF-generation call sites**. What a
clerk "prints" is `window.print()` over client DOM (`views/certificates/index.php:1161-1179`).
So the system records that a certificate was issued and renders it transiently in a browser
— **there is no stored artefact, nothing to re-fetch, nothing to checksum, and no way to
reprint identical bytes later.**

### Also confirmed
- **L-4 · `_sanitizePlaceholderValues()` is dead code** — defined `:684-691`, zero call
  sites. Server-side escaping of stored values does not happen; XSS safety rests entirely
  on a client `esc()` convention. **P2**
- **L-5 · A failed RTDB read is indistinguishable from "no data"** across all 8 read
  endpoints (`Firebase.php:283-288` returns `null` on any failure). The catalogued pattern,
  again — and the cause of the empty Class dropdown the operator saw after session expiry. **P2**
- **L-7 · No roster check** — a certificate can be issued to a `userId` not enrolled in the
  session (`:370,437-440` check only `is_array($student)`). **P2**

## What is CLEAN in the legacy system — and worth keeping

- **RBAC is correct and is the pattern to copy.** All 14 `_require_role()` sites pass
  `('Certificates', level)`, so the graded map is authoritative — this is **not** the
  name-gate defect catalogued elsewhere in this codebase. All four mutating endpoints
  require `manage`.
- **CSRF posture is correct** — `certificates/*` is absent from `csrf_exclude_uris`.

## What this means for the decision

The three systems each hold one third of a working answer:

- **SIS** has the numbering done properly — atomic claim-doc CAS, and a documented fix
  (Wave-3 F4) that made it *abort* rather than fall back to stale-mirror math that could
  duplicate a TC number. It also refuses a second active TC for one student.
- **Documents** has the document — designed, versioned, proofed, immutable per version,
  multilingual, with a frozen artefact per publication.
- **Legacy** has the breadth (any certificate type) and does it with a racy counter, no
  idempotency, revocation that does not revoke, and **no durable output at all**.

**Retiring the legacy system does not lose a stored certificate archive, because it never
created one.** What it loses is the *ability to issue types other than TC* — and that
capability, as implemented, produces no document and can silently overwrite the record of
one already given to a family.

The two halves needed to replace it both exist and are each sound. They have never been
joined.
