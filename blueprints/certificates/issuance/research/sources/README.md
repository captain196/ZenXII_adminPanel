# Preserved primary sources

Instruments the corpus cites that **exist at one URL, on one webserver, with no archive copy.**
Each was verified by reading it, then extracted to the smallest range that carries the citation.

| file | instrument | why it is here |
|---|---|---|
| `arunachal-education-rules-2010.pdf` | **Arunachal Pradesh Education Rules 2010**, 71 rules — Gazette Extraordinary No. 109, Vol. XVII, 20.08.2010, Noti. **No. ED2/167/2009** under s.141 of Act 8 of 2010 | pp. 470–489 of an **18 MB, 681-page** annual compendium at an opaque hash URL on `printing.arunachal.gov.in/uploads/pdf/`. **No Wayback coverage under `/uploads/`** — a site rebuild takes it. |
| `arunachal-education-amendment-rules-2014.pdf` | **AP Education (Amendment) Rules 2014** — Noti. **No. SEDN-77/2011(Pt-I)**, 28.11.2014, amending **Rule 55** | p. 359 of the AP Normal Gazette 2014. **Not on India Code at all** (C-38). This is the only located copy. |

**Why extract rather than mirror whole.** The compendia are ~419 MB across 19 volumes; committing
them would be worse than losing them. The extracts carry the gazette citation in their PDF metadata,
so a reader can re-cite without the compendium — and re-verify against the original while the host
lives.

**What is still unpreserved.** The other 17 gazette volumes swept for the Arunachal negative remain
only on that host. The negative itself is recorded (C-38); if it ever has to be re-established from
scratch, it means re-fetching ~419 MB from a server that may no longer serve it.
