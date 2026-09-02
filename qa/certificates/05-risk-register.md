# 05 · Risk register — Document Engine (Certificates)

**Evidence ceiling: E2.** Every risk below is derived from source, not from
observed behaviour. Nothing here is proven to occur.

Ranked by (consequence × likelihood × silence). *Silence* matters
disproportionately in this module: a certificate is a legal record, and the
failures that hurt are the ones nobody notices.

| ID | Risk | Sev | Status | Defended by |
|---|---|---|---|---|
| **R1** | **Cross-tenant write on the lifecycle endpoints.** `head()` compared no `schoolId`; the panel's Admin SDK bypasses `firestore.rules`. Activating another school's template changes what they legally issue. | **P0** | **FIXED 2026-09-02**, unit-proven. Runtime unverified | T0-08, T0-13 |
| **R2** | **Proof PDFs readable with no login**, predictable filenames, carrying letterhead/crest/signature. | **P0** | **FIXED** (`uploads/.htaccess`). **Apache-config dependent — must be verified on the Ohio box** | T0-14 |
| **R3** | **Image-root scoping was a no-op** — the default root was the whole uploads tree; one school could embed another's files. | **P1** | **FIXED** | T0-15 |
| **R4** | **A second TC issuance path.** `Sis.php::issue_tc()` already does the real thing (dues gate, Auth disable, roster removal, app sync). A document-only TC beside it could certify a student who is still enrolled and authenticated. | **P0 if built wrong** | **NOT BUILT.** Recorded on the registry row | — (design-time) |
| **R5** | **Two receipt renderers.** The Parent app generates receipts on-device (`ReceiptPdfGenerator.kt`). Wiring `fee_receipt` without retiring it gives one school two receipts that cannot agree, invisibly. | **P1 if built wrong** | **NOT BUILT.** Recorded on the registry row | — (design-time) |
| **R6** | **Four counter implementations**, of which two are not CAS: the legacy `Certificates.php` RTDB counter is confirmed read-increment-write, and `Fee_firestore_txn`/`Accounting_firestore_sync` are verify-after-write. `Firestore_service::nextSchoolCounter()` is genuine claim-doc CAS and already exists. | **P1** | Pre-existing, outside this module. Issuance must adopt the CAS one | — |
| **R7** | **A bad publish could not be undone.** | **P1** | **CLOSED 2026-09-03** — rollback built (operator Q1). Runtime unverified | T0-12, T2-11, T2-12 |
| **R8** | **Archiving the active template silently left a type unissuable.** | **P1** | **CLOSED 2026-09-03** — refused outright (operator Q2). Runtime unverified | T0-11 |
| **R9** | **Activation atomicity unproven under real contention.** Completeness is unit-proven; Firestore's behaviour under a genuine race is not. | **P1** | Open — needs an emulator drill with a control that can fail | T0-02 |
| **R10** | **The legacy `Certificates.php` is live and sidebar-linked**, sharing the `Certificates` RBAC key with the new engine. Granting `manage` grants both. It mints duplicate numbers under concurrency (confirmed) and never produces a PDF (`pdfUrl` always `''`). | **P1** | Open — pre-existing | T0-16 |
| **R14** | **Version history showed FABRICATED data** — hardcoded rows on every template, in the one panel that answers "which template produced this certificate?". | **P1** | **CLOSED 2026-09-03** — `get_versions` added | T1-10 |
| **R11** | **No pagination on the template list.** `get_templates()` returns every row for the school. | **P3** | Open | T2-10 |
| **R12** | **`ModuleGate` fails open** in the Teacher app when capabilities have not loaded. UI polish only — but there is also no documents key at all. | **P2** | Open, prospective | — |
| **R13** | **Assets remain web-served.** Content-hash names make them unguessable, not private. | **P2** | Accepted for now; recorded in `14-unknown-risk-register.md` | — |

## The shape these risks share

Nine of thirteen are **silent**: no error, no log, no visible symptom. The
module's defences are therefore mostly *refusals* — refuse to publish a stale
proof, refuse to merge a conflicting save, refuse to render an unresolvable
field, refuse to activate without an atomic write, refuse an id from another
tenant. The UAT rows that matter most are the ones that check a refusal actually
happens, because a refusal that silently stopped refusing looks like success.
