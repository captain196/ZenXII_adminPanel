# Coverage audit — measured, not asserted

You asked *"did you do complete research on all boards?"* and the honest answer at the time was no:
I had let **line count stand in for coverage**. This file replaces that with a measurement, so the
question can be answered by counting rather than by my say-so. Re-run it after any research change.

## How this is measured

Every factual claim in the corpus is tagged `[A]`/`[B]`/`[C]`/`[D]`:

| tag | meaning |
|---|---|
| **[A]** | primary official document — a rule, gazette, Act, regulation or circular on an official domain, cited with clause or page |
| **[B]** | official website text, but not a rulebook |
| **[C]** | credible secondary reporting |
| **[D]** | inference — flagged as such, never presented as fact |
| **NOT FOUND** | searched, and it is not published |

A jurisdiction counts as covered when it is discussed **within 1,400 characters of an `[A]`
citation** — i.e. the claim about it is anchored to a primary source, not merely named.

## The numbers

**436 [A] · 115 [B] · 62 [C] · 23 [D]** across 15,008 lines.

**70% of all tagged claims rest on a primary official document**, and inference is 3.8%. That ratio
is the thing worth checking, because it is what stops a corpus this size from being confident prose.

| file | [A] | [B] | [C] | [D] |
|---|---|---|---|---|
| west-central-india.md | 132 | 17 | 34 | 8 |
| boards-west-south.md | 71 | 37 | 7 | 0 |
| east-northeast-india.md | 63 | 11 | 2 | 1 |
| boards-east-northeast.md | 54 | 15 | 1 | 0 |
| boards-north-central.md | 33 | 9 | 5 | 3 |
| south-india.md | 32 | 13 | 5 | 7 |
| north-india.md | 29 | 12 | 8 | 4 |
| boards-central-completion.md | 18 | 1 | 0 | 0 |
| CONFLICTS.md | 4 | 0 | 0 | 0 |

**33 of 36 states and UTs are anchored to three or more primary sources.** The two genuinely thin
ones are **Arunachal Pradesh** (56 mentions, **zero** `[A]`) and **Sikkim** (85 mentions, one) —
both under active research.

## Two false gaps this audit produced, and why they are worth recording

**Both of my first two measurements were wrong, in the same direction: they invented gaps.**

1. **"`boards-west-south.md` and `west-central-india.md` have zero primary sources."** They use
   `[A]` bracket notation; my regex looked for `Evidence: A`. `west-central-india.md` is in fact the
   **densest primary-source file in the corpus** at 132 citations. My grep was wrong, not the corpus.

2. **"Uttar Pradesh has one primary citation"** — for the largest school system in the country.
   UP is discussed as **UPMSP** (7 anchored) and under the **Intermediate Education Act 1921** (5),
   and has its own closed section plus conflict C-20. The state name simply isn't the string the
   corpus uses.

**The lesson is about the measuring instrument, not the corpus.** A coverage metric that keys on one
spelling of a name will manufacture holes wherever the writing used a board's acronym — and a
manufactured hole costs a research agent and reports a false weakness. Any future re-run must check
aliases **before** concluding absence. This is the same failure mode as asserting a negative from a
search that was looking for the wrong string, which the programme has now hit several times.

## What genuine absence looks like here

These are recorded as **NOT FOUND**, meaning searched-and-unpublished — not unresearched:

- **UPMSP** — the Regulations under the Intermediate Education Act 1921 exist as a **printed
  volume** and are not online. The TC format, countersignature rule and migration regulation are
  therefore unretrieved. The corpus explicitly instructs: *"Do not assert a UPMSP TC-upload rule."*
- **MBOSE** Examination Regulations — exist, not published online.
- **Maharashtra's GR** on admission without a leaving certificate — **HUMAN-ONLY**, behind a
  CAPTCHA (C-23).

**An honest NOT FOUND is a result.** The failure mode worth guarding against is the opposite one —
filling a hole with a plausible rule number — and the corpus's 23 `[D]` markers against 436 `[A]`
is the evidence that it mostly didn't.

## Addendum — counting conflict entries (2026-09-14)

The same class of error recurred, in the other direction. A collision check matching
`^#{2,3} *(C-\d+)` reported **two collisions that do not exist**: `### C-11 said four judgments…`
and `### C-3's Punjab row is wrong…` are **prose subsections that open with a conflict reference**,
not entry headings.

**Real entries always carry the separator: `## C-NN · Title`.** Matching `^#{2,3} *(C-\d+[a-z]?) *·`
gives the true count — **76 entries across three files, C-1…C-62, zero collisions.**

**The pattern across all four instrument errors this programme has hit is one thing:** a regex
written against what the content *means* rather than what it *looks like*. Twice it manufactured
gaps (an `[A]` notation mismatch, a state-name alias), and twice it manufactured collisions. The fix
is the same each time — **check the convention the file actually uses before trusting the count**,
and treat any surprising measurement as a fault in the instrument until the instrument is controlled.

