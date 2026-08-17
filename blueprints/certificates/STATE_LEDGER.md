# STATE_LEDGER — Certificate Template Designer blueprint

phase INTAKE | essay v1 | amends 0/5 + 0/2 | effort 0/10 (balanced)

Modes: DEPTH_TARGET comprehensive · CREATIVITY_LEVEL balanced ·
RESEARCH_MODE operator-primary+model-supplemental · SUGGESTION_MODE proactive ·
SESSION_EFFORT_BUDGET balanced (10)

Sections in scope: S1–S14 (S3 and S11 retained per operator Q1).
S9 roadmap = relative-sequence, no dates (operator Q2).

---

## Constraints

| ID | Type | Statement | Source |
|---|---|---|---|
| CON-RENDERER | HARD | ~~dompdf 2.0.8 only~~ → **MODIFIED 2026-08-17 (option 3)**: pure-PHP renderer, no new server infra. **mPDF** for the document engine; existing dompdf paths untouched. | operator, conflict resolution |
| CON-MULTILINGUAL | REQUIREMENT | Must render correctly in English, Hindi, Tamil, Telugu, Marathi, Gujarati, Bengali, Kannada, Malayalam | operator Q2 |
| CON-CANVAS_FULL | HARD | Single full blank canvas with complete design control (Figma-grade); compliance = required objects, presence-only | operator, CONFLICT-1 resolution |
| CON-V1_SCOPE | HARD | v1 = Transfer Certificate, Bonafide, Character | operator, this session |
| CON-NO_PRINT_IMPL | HARD | No module's print button wired in this build | operator brief |
| CON-NO_DEPLOY | HARD | No commit/push/deploy without explicit per-change permission | CLAUDE.md working agreement |
| CON-COMPLIANCE | REQUIREMENT | Every compliance rule renders its authority + evidence level | essay §4.4 |
| CON-FAIL_CLOSED | REQUIREMENT | Unresolved placeholder / contract mismatch = hard error, never render | essay §6.3 |

Active: 6 of MAX 15. No conflicts detected at seeding.

---

## Assumptions

| ID | Assumption | Confidence | Resolves in |
|---|---|---|---|
| A1 | School admin/clerk is the primary designer, not a designer-by-trade | m | S4 |
| A2 | Most schools will use a starter template and edit chrome only | m | S3, S5 |
| A3 | Pre-printed certificate stationery is common enough to justify canvas in v1 | **l** | R3, S5 |
| A4 | English-only is acceptable at launch | **l** | R4, S13 |
| A5 | dompdf renders a locked Annexure-I form acceptably at A4 | m | R2, S6 |
| A6 | One active template per (school, docType) is sufficient — no per-class variants | m | S7 |
| A7 | Quill's HTML output renders faithfully through dompdf (semantic tags, no flex/grid) | m | R2→S6, proof-render |
| A8 | Self-hosting Quill in `assets/js/` beats CDN for tier-2/3 school connectivity | m | S6 |

Unresolved: 8 of MAX 20.

**R2 RESOLVED:** G1 (editor undecided) → Quill 2.0.3 recommended. CKEditor 4 and 5 both rejected.

---

## Suggestions

| ID | Type | Target | Status | Cost |
|---|---|---|---|---|
| SUG-001 | EVIDENCE | A3 / S5 | open | trivial (0.5) |
| SUG-002 | CORRECTION | essay §3.1 vs operator brief | open | small (1) |
| SUG-003 | EXPANSION | S6 / editor selection | open | small (1) |

Open: 3. Effort used: 0/10.

---

## Overrides

None.

---

## Essay Versions

| Version | Date | sha256 (16) | Note |
|---|---|---|---|
| v1 | 2026-08-17 | 38199ca5ea33dcd9 | Spec v2 (three-shape / compliance-by-construction) |

Archived: `essay-versions/essay-v1.md`

---

## Checkpoints

None yet.
