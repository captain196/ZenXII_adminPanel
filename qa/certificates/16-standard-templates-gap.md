# REQUIREMENT-DERIVED FINDING — every school should ship with the standard templates

**Source of the "should": explicit human decision, 2026-09-04 (§4 · H3 answered).**
> "the standard templates should be in every school — that's basic, every school loads it"

Recorded as a **requirement**, not an implementation observation. Per §14.1, an absence with
a stated requirement behind it is as legitimate a test target as any traced code path.

---

## IS — what a school actually gets today

**E3, observed live this session.** A second school was logged in — **Harshit Public School,
Uttar Pradesh, CBSE** — and its Document Engine library is **empty: 0 templates**.

**E2, traced.** There is **no provisioning path of any kind.** `documentTemplates` is written
only by the Document Engine's own five files; no school-creation, onboarding, or migration
code writes a template. A grep for any seeding concept (`seed_school`, `provision_school`,
`bootstrap_school`, `default_templates`, `seedTemplates`) returns nothing across
`application/`.

**How a school gets its first certificate today:** a human opens `/doc_templates` — which
has **no sidebar link** (L1) — picks a document type, clicks a starter card, and the starter
is cloned client-side into a new draft. The gallery labels this explicitly: *"Starters —
cloned into your school, never linked."* Then they must proof it, publish it, and activate
it — five to seven correct decisions (A10 §1a) before anything can print.

**So the current answer to "what certificates does a new school have?" is: none, until
somebody finds an unlinked URL and does seven things correctly.** That is the gap.

## The standard set — what "every school" can actually mean

The seven starters are **not** uniformly shippable. Two are state-gated and one is
board-gated (`startersFor()`, `designer.js:857-861`):

| Starter | Type | Gate | Ship to every school? |
|---|---|---|---|
| `tc_cbse` | transfer_certificate | `boards:["CBSE"]` | **CBSE schools only** |
| `tc_plain` | transfer_certificate | none | **yes** |
| `bonafide` | bonafide | none | **yes** |
| `conduct` | character | none | **yes** |
| `fee_rct` | fee_receipt | none | **yes** |
| `lc_5a` | leaving_certificate_5a | `states:["Kerala"]` | Kerala only |
| `sec_ker` | school_education_certificate | `states:["Kerala"]` | Kerala only |

**A blanket "seed everything everywhere" would put a Kerala Form 5A into a Uttar Pradesh
school** — a state-prescribed instrument in a state that does not prescribe it. The seeding
rule must reuse the existing gate, not bypass it.

## The decision that must be made BEFORE building this

Seeding is easy. **Deciding what a seeded template *is* afterwards is the hard part**, and
the codebase already contains the unfinished answer.

Today's model is deliberately **"cloned, never linked"**. If templates are pre-seeded, one of
two things must be true, and they are not equally safe:

**Option A — seed a snapshot; the copy is the school's, forever.**
Matches today's semantics exactly. A school edits freely and nothing ever changes underneath
it. **Cost:** when a statutory form is corrected — CBSE amends Annexure-I — every school that
was seeded keeps the old wording, and nothing tells them. Silent, permanent divergence across
every tenant.

**Option B — seed a linked copy that can receive updates.**
Solves the correction problem and introduces a worse one: a central change reaching into a
school's customised statutory document. A school that carefully adjusted its wording finds it
altered by someone else.

**The codebase already chose, twice, and both times it chose neither.**
- `Doc_compliance` (`Doc_compliance.php:10-30`) refuses to auto-invalidate when an authority
  is revised. It produces a **report** — an affected-schools list — because auto-acting *"would
  take a school's active certificate away without anyone deciding to."*
- `Doc_block_service` implements the same shape for shared blocks: an **offer** the school
  accepts or declines. (A5 found that mechanism is dead code — unwired, and broken beneath
  the wiring — but the *design intent* is recorded and consistent.)

**Recommendation: Option A + the existing offer model.** Seed a snapshot so a school owns its
templates outright, and when a standard template is revised, generate an **offer** — the
pattern this module has already committed to twice — rather than a silent update. This needs
no new concept; it needs the offer path finished.

## Consequences for the certification already in flight

1. **This does not change any existing finding.** It adds a requirement-derived gap.
2. It **raises the severity of L1 (no sidebar link)**: if seeding is the intended entry point,
   the module still cannot be reached to *use* what was seeded.
3. It **interacts with P0 · OV1.** Seeded-then-published templates are exactly the population
   whose frozen PDFs are overwritable. Seeding multiplies the blast radius by the tenant count.
4. **Do not seed before OV1 and OV2 are fixed.** Provisioning templates into every school
   while a published version's artefact is rewritable by an `edit`-grade user propagates a P0
   across the estate instead of containing it in one.

## UAT rows this generates
- A brand-new school opens the module → what is present? (requirement-derived expectation:
  the ungated standard set; **implementation-derived expectation: nothing**) → `⚑ CONTESTED`
- A Kerala-only starter must **not** appear in a non-Kerala school after seeding
- A CBSE-gated starter must not reach a non-CBSE school
- A school that has customised a seeded template is not silently overwritten when the
  standard is revised
- Seeding is idempotent — running it twice does not double the library
