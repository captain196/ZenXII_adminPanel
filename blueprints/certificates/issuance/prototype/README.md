# Issuer identity — working prototype

`issuer-prototype.html` — open it directly, no build step. It implements the flow proposed in
`../ISSUER_UX.md` and runs the register match **against real data**, not a mock.

| file | what it is |
|---|---|
| `issuer-prototype.html` | the prototype; 53 register rows embedded |
| `hpbose-register-sample.json` | 172 clean rows parsed from HPBOSE's published list, for wider testing |

## What it demonstrates

1. **The document decides the questions.** Switch between Bonafide / CBSE TC / HP State TC and watch
   the required-field set change. A Bonafide certificate asserts only enrolment, so it never asks for
   an affiliation number.
2. **The register answers instead of the school.** Type an HPBOSE affiliation code — or tap one of
   the real ones offered — and the board's own register returns the registered name, district,
   **recognised class range** and **valid session**.
3. **Per-stage recognition is enforced from the register** (C-33). Pick a class-12 Science leaver at
   a school the register shows as `9th-10th`, and issuance is blocked — because the certificate would
   assert recognition the board has not granted.
4. **The misfile is caught** — paste an 11-digit UDISE code into the affiliation field and it asks
   whether that is what it is, rather than silently accepting it.
5. **The honest fallback.** CBSE has **no** register path here, deliberately: its affiliated-schools
   page returned HTTP 403, so the prototype shows what happens when no register exists — a recorded
   claim, an optional instrument, and gating only the specific claim.

## Where the data came from

`hpbose.org/Admin/Upload/HPBoSE.Affiliation.List.pdf` — 84 pages, ~14,700 rows, real text layer.
Fetched and parsed with PyMuPDF. Rows retained only where the school name was unambiguous, since
wrapped names merged into the station column.

## Two defects the prototype's own tests caught

Both would have shipped as wrong compliance decisions, and both are worth knowing before this logic
is ported:

- **The class floor was ignored.** Only the ceiling was checked, so a school recognised
  `11th-12th(Sci)` was treated as able to issue a class-10 TC. **Recognition has a floor as well as a
  ceiling.**
- **"Science" is abbreviated three ways.** The register writes `Sci`, `Sci.` **and `Sc.`** — matching
  only `SCI` silently dropped Science from `9th-+2(Sc./Com)`, which would have blocked a science
  student at a school that is recognised for science.

## What it is not

A prototype. Nothing writes to ZenXii, there is no auth, and the register is embedded rather than
fetched. Escaping is applied at the `innerHTML` sinks anyway — nothing untrusted reaches them *here*,
but in production the school name and class range come from a parsed PDF and from the school, so the
pattern to copy is that one.
