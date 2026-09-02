# 07 · Open questions — ANSWERED 2026-09-03

All four business decisions are settled by the operator. Recorded here as the
decision of record; Q1 and Q2 are implemented, Q3 and Q4 are design-time and
recorded where whoever builds them will read them.

| # | Decision | Status |
|---|---|---|
| Q1 | **Activation IS reversible.** An earlier published version may be activated; logged as a rollback | **IMPLEMENTED** |
| Q2 | **Archiving the active template is REFUSED** — "activate another one first" | **IMPLEMENTED** |
| Q3 | **Document Engine owns receipt design (v2).** Both surfaces render from the activated template; the app's on-device generator is retired | Recorded on the `fee_receipt` registry row |
| Q4 | **Legacy `Certificates.php` stays for now**, removed later. Constraint: the new engine must NOT appear in the sidebar while the legacy one is still there — one RBAC key grants both | Recorded below |
| Q5 | Access supplied. **I do not enter passwords**, so Phase 5 is executed by a human — which the spec requires anyway | Human-run |

---

## Original questions, for the record

# 07 · Open questions — batched for the human (v3 §2)

Only questions that cannot be answered from source, config, history or reasoning.

## H3 · Business decisions

**Q1 — Should activating a template be reversible?**
`activate()` always uses `publishedVersion`, so a school that activates a broken
v5 cannot return to v4; the only route is publishing a corrected v6. Is that
intended (an audit trail that only moves forward) or a gap?
*Blocks:* T0-12, R7.

**Q2 — May a school archive the template that is currently active for a type?**
Today it can, silently, leaving that document type unissuable with no warning.
Refuse, or allow with a clear warning?
*Blocks:* T0-11, R8.

**Q3 — Fee receipts: one renderer or two?**
The Parent app already generates receipts on-device. Either that generator is
retired in favour of the Document Engine, or receipts stay out of the engine.
It cannot be both without two receipts that disagree.
*Blocks:* wiring `fee_receipt`. See `04-parity-matrix.md` D1.

**Q4 — What happens to the legacy `Certificates.php`?**
It is live, sidebar-linked, shares the `Certificates` RBAC key, mints duplicate
numbers under concurrency, and produces no PDF. Retire it, hide it, or run both?
*Blocks:* T0-16, R10.

## H1 · Access I do not have

**Q5 — A signed-in session.** Every row in `06-uat-matrix.csv` needs one. I
cannot sign in, so the entire online path has never been executed.

**Q6 — A second school, and a second staff account** (one `manage`, one `view`).
Required for T0-08/09/10 and the concurrency rows.

**Q7 — Apache on the Ohio box.** `uploads/.htaccess` fixes the proof-PDF
exposure **only if `AllowOverride` permits it**. If the vhost sets
`AllowOverride None`, the file is inert and R2 is still open in production. This
needs checking on the server, not in the repo.

## H5 · Ambiguity that changes the verdict

**Q8 — Is `fee_receipt` in scope at all?** It needs repeating line-item rows,
which the v1 serializer does not support. It is declared as a print point but is
not a document type. Confirm it is a v2 target, not a v1 omission.
