# MISSION — Certificates / Document Engine · certification run 2

**QA-LEAD** · started 2026-09-04 · spec: `zenxii-uat-agent-team-v3.md`

## Why this run exists and why run 1's artefacts are not trusted

A certification run against this module completed on 2026-09-02/03 (artefacts `00`–`14`,
46 UAT rows, all `NOT TESTED` — never executed). **Every one of those artefacts predates
substantial change to the module** and is therefore treated as UNVERIFIED INPUT, not as
evidence. Per §4 of the spec, prior session summaries are not an authoritative source for
what the system does; the implementation is. Agents re-derive from source.

Changed since run 1 (each is a re-investigation trigger, not a truth claim):

| Change | Why it invalidates prior analysis |
|---|---|
| A `fee_receipt` document type | First type whose body is a REPEATING table — a new object kind (`repeatOver`), a new field type (`list`), and the first document whose height is decided by data rather than design |
| Custom document types `custom:{slug}` | Document types are no longer a closed, shipped set. Type ids are now user-minted at runtime. Every "the type is one of N" assumption in run 1 is void |
| `_safe_type()` widened from a hardcoded 3 to the catalogue | Five document types were previously uncreatable; the reachable state space of the module has changed |
| Serializer now REFUSES a too-narrow page-number box | A new hard failure path at design time |
| `Doc_contract` validates list fields per column | New validation branch; `validateBundle` behaviour changed for one field type |
| Client hydrates the school's real state/board from the server | Which document types are OFFERED now depends on server data, not a client fixture |
| Page-number CSS changed; three golden files regenerated | Rendered output changed for every existing template |

## Scope

**IN.** The Document Engine: template design, versioning, proof, publish, activate,
deactivate, archive, delete, duplicate, presence/collaboration, merge, the contract and
merge-field system, the compliance layer, PDF rendering, asset upload, and the
declared-but-unwired print-point seam (`document_targets.php`).

**IN (as absence).** Teacher app, Parent app. `CON-NO_PRINT_IMPL` is a standing operator
constraint: no module's print button is wired in this build. Absence is a finding (§4) —
the apps are investigated to PROVE the absence and to detect any drift, not skipped.

**IN (as adjacency).** The legacy `Certificates.php` controller, if it is still routable.
Two implementations of "issue a certificate" is a divergence risk regardless of intent.

**OUT.** Fees, SIS, HR and the other modules that would one day mount a print point —
except where they own data the engine reads, or would be the consumer of a shared contract.

## Agent roster

| Agent | Spawned | Mandate / reason for skip |
|---|---|---|
| A1 CARTOGRAPHER | YES | Runs first, alone. Owns the dependency graph |
| A2 WEB-SPEC | YES | The module is almost entirely an Admin Web surface |
| A3 MOBILE-SPEC | YES — **narrow** | Not full depth: mandate is to PROVE the apps have no certificate surface and detect drift. Full lifecycle/rotation/process-death analysis is not warranted for a surface that does not exist. **Logged as a deliberate depth reduction, not a skip** |
| A4 BACKEND-SPEC | YES | 15+ AJAX endpoints, CSRF posture, lifecycle service |
| A5 DATA-SPEC | YES | Firestore collections, version snapshots, proofs, rules, indexes, orphans |
| A6 MODELLER | YES | draft→published→active→archived is the heart of the module; irreversible states present |
| A7 DIFF-ANALYST | YES — **re-aimed** | Classic cross-surface parity is thin (one surface). Re-aimed at the REAL divergence axis in this module: the contract/type catalogue exists in THREE places (`doc_types.php`, `designer.js`, `Doc_contract`), which is the free oracle §7 describes |
| A8 SECURITY-RED | YES | Multi-tenant; Admin SDK bypasses firestore.rules so PHP is the only boundary |
| A9 PERF-ANALYST | YES | 65 templates exist on one school already; mPDF render cost; an unbounded query observed in create() |
| A10 UX-CRITIC | YES | Heavy authoring UI; the module's own history is dense with UX defects |
| A11 ADVERSARY | YES | Mandatory |
| A12 TEST-ARCHITECT | YES | Mandatory |

**None skipped.** A3 runs at reduced depth and A7 is re-aimed; both are recorded in the
coverage ledger as scope decisions with reasons.

## Evidence ceiling

**E2 for this entire run.** Nothing below Stop Gate A is runtime-verified. No row ships
`PASS`. The one exception, recorded honestly: automated suites this session actually
executed (PHPUnit 539 tests; the browser harness 179 rows) are E3 for *what those suites
assert* and nothing more — they are not UAT evidence and do not certify any row.
