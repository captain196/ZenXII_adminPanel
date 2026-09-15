# Issuer identification — why it feels hectic, and what to do instead

The complaint is correct, and the research has made the problem **worse, not better.** This is the
design note for that collision.

## 1 · The measured burden

`school_config` → Issuer Identity asks **10 fields** before a school can issue anything:

| required | optional |
|---|---|
| Affiliating Board | UDISE+ Code |
| Affiliation Number | Recognition Order Number |
| **Registered School Name** — *"exactly as on the affiliation instrument"* | Order Date |
| Head of Institution | Issuing Authority · Head Holding Office Since · Re-check Every |

**One of the four required fields cannot be answered from memory.** *"Exactly as on the affiliation
instrument"* means someone has to physically retrieve a document before the form can be completed.
That is the single worst step in the flow, and it sits in the mandatory path.

## 2 · Why "just simplify the form" is the wrong instinct

The research says **ten fields is not too many — it is too few.**

- Recognition is granted **per stage × per stream × per subject** (UBSE: ₹5,000 per additional
  stream, ₹2,500 per subject, each needing its own two-year result history) — **C-33**
- Validity **cannot be one field** — recognition terms have no common shape — **C-13**
- Countersignature is **neither a boolean nor keyed on the state** — **C-26, C-27**
- An instrument on file is **not a subsisting entitlement** — it is revocable retroactively on a
  false particular — **C-33**

So a compliance-correct form is **bigger** than the one people already find hectic. The fix cannot
live in the form. **It has to live in when and why we ask.**

## 3 · The principle that resolves it

> **Evidence what the document ASSERTS — not what the school IS.**

A **Bonafide certificate** asserts one thing: *this person is enrolled here*. That is the school's
own record. It needs **no affiliation number, no recognition order, no board**.

A **CBSE Transfer Certificate** prints `Affiliation No. ____ School Code ____` in its header —
mandated by Annexure-I, read verbatim (**C-45**). That assertion is about the Board, so **it needs
evidence**.

A **Chhattisgarh TC** must state that the school is CGBSE-recognised **and print its मान्यता कोड**
(C-17). Different assertion, different evidence.

**The compliance engine already computes which authorities apply** (`resolveStack()`: national ∪
board ∪ state). So it already knows what each document will assert. **Let that drive the form
instead of asking everyone for everything.**

**Consequence:** a school that only issues Bonafide certificates answers **two** questions, not ten.
Most schools begin there. The affiliation chain is asked at the moment someone first issues a TC —
where the reason for asking is visible, because the field is about to be printed on the document in
front of them.

## 4 · Derive, don't ask

Three derivations remove most of the remaining typing.

**From the UDISE code (11 digits) → state and district.** The structure is 2 state + 2 district +
3 block + 4 school, and `Issuer_identity::stateOfUdise()` already implements it. One number answers
two questions and cross-checks a third.
*Honest limit:* UDISE is **not** established as universal — Arunachal's 2010 Rules never mention it
(`UDISE`/`DISE` = 0 across all 71 rules). So it is the best single identifier available, **not a
mandatory one**, and the flow must work without it.

**From the affiliation number + the board's own published register → almost everything else.**
This is the discovery that changes the design — see §5.

**From board × state → which identifiers are needed at all.** Already computed; currently unused for
this purpose.

## 5 · Verification can be instant, and it is real verification

`Issuer_identity`'s docblock says level 3 verification is *"a human step here (level 3) rather than a
pretence of automation."* **That was right when it was written, and HPBOSE changes it.**

**HPBOSE publishes its complete affiliation register as a text-layered PDF** —
`hpbose.org/Admin/Upload/HPBoSE.Affiliation.List.pdf`, 84 pages, ~14,700 rows, fetched and parsed:

```
School Code | Affi. code | NAME OF THE INSTITUTIONS | STATION | DISTRICT PIN | Class      | Session
       4739 |     13598  | HIM HERITAGE PUBLIC SCHOOL| SALOUNI | HAMIRPUR 174311 | 9th-10th (C) | 2026-27
       4741 |     13600  | AMAR PUBLIC SCHOOL        | SAMJAAL | HAMIRPUR 177006 | 9th-10th     | 2026-27
```

**Matching a claimed affiliation number against the board's own published register IS checking
against the board's directory.** It is not a pretence of automation — it is the same act a human
would perform, done faster.

**And the register carries the two things the research said we would have to interrogate schools
for:**

- **`Class` — the stage range the affiliation actually covers** (`9th-10th`). That is C-33's
  per-stage recognition, **published by the board**.
- **`Session` — the validity term** (`2026-27`). That is C-13's validity problem, **answered for
  this board**.

So for an HP school the whole flow collapses to: **type the affiliation number → we return your
registered name, district, stage range and validity, from your board's own document.** Nothing to
retrieve, nothing to transcribe, and the name mismatch problem disappears because we supply the name.

**How general is this?** Demonstrated for **HPBOSE**. **Unknown** for CBSE and CISCE — both returned
**HTTP 403** to this fetcher, which is not evidence either way. So: **build the register-match
mechanism generically, seed it with HP, and add each board as its register is confirmed.** Finding
out which boards publish one is a cheap, high-value research task and the obvious next step.

## 6 · Fix the defect that creates the confusion

`school_config/index.php:441` labels **one input** *"Affiliation / DISE No."* — two identifiers,
issued by two different authorities, sharing a field whose only constraint is `maxlength`. Nothing
distinguishes an 11-digit UDISE code from an affiliation number, so the two get filed into each
other, and whatever lands there **prints on marksheets** (`result/templates/cbse.php:45`).

Split the field, and use the shape heuristic already written: **eleven digits opening with a valid
census state code is almost certainly a UDISE code in the wrong box** (`udiseStateMismatch()`).
Catch it at entry with a question, not a rejection: *"That looks like a UDISE code — is it?"*

## 7 · The flow

1. **One field: UDISE code.** Derive state and district; confirm them rather than asking. Skippable.
2. **Board + affiliation number.** Shape-validated per board (there are only five formats, not
   forty). **If the board has a register, auto-fill name, stage range and validity, and record
   VERIFIED with the register's own date and edition.**
3. **Head of institution.** Needed by every document that carries a signature.
4. **Everything else is asked at first issue of a document type that needs it** — and asked with the
   reason visible: *"Your CBSE TC prints `Affiliation No. ____`. It is blank."*

Design is **never** gated — that is already true and should stay true. Issuance gates **the specific
claim the document makes**, not the school as a whole.

## 8 · What this does not fix, stated plainly

- **Goa.** r.127 makes a form mandatory *and* it is unpublished (**C-28**). No interface solves a
  form nobody can obtain.
- **Per-subject recognition.** UBSE prices it per subject; that genuinely is more questions — but
  only for a school issuing for that stream, and only once.
- **Expiry is not optional.** Recognition can be **withdrawn retroactively** on a false particular,
  with personal liability under the IPC (**C-33**). So `VERIFIED` must decay, and the register match
  must be re-run rather than cached forever. The re-check interval is already a field; it should
  default from the register's `Session`, not from our guess.
- **Boards without a register** fall back to today's flow. That is the honest floor, and it is
  where the cheap next research task pays off.
