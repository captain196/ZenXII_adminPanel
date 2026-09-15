# What needs a person

Everything here is **blocked by something automation should not or cannot do** — a CAPTCHA, a
geo-fence, an RTI, or a printed volume. None is blocked by absence of law, and **none will be closed
by more crawling**, so they are listed once here rather than re-queued.

Ordered by what it costs you.

## 1 · Five minutes each — a browser and a CAPTCHA

| # | what | where | exactly what to do |
|---|---|---|---|
| 1 | **Maharashtra GR on admission without a leaving certificate** (C-23) | `gr.maharashtra.gov.in/1145/Government-Resolutions` | Department **School Education**; keyword **शाळा सोडल्याचा दाखला** or **प्रवेश**. Need the **GR number and date**. The form is ASP.NET and its `__VIEWSTATE` is scrapeable — only the CAPTCHA blocks it. |
| 2 | **BSEM migration certificate rule** | BSEM migration portal | CAPTCHA-gated; recorded HUMAN-ONLY, not attempted. |
| ~~3~~ | ~~**Any judgment on TC withholding in Arunachal**~~ **DOWNGRADED 2026-09-14** | — | `indiankanoon.org` answers **direct URL fetches** even with WebSearch capped, so this no longer needs a CAPTCHA. Searched the **Gauhati High Court** (which covers Arunachal): **nothing on point** — the results are an Assam misappropriation case, a document-authenticity case and a college expulsion, and **none mentions Arunachal**. Now a *searched-negative with a stated limit* rather than an unknown. Still worth a human pass on `ecourts` only if this becomes load-bearing. |
| 4 | **UDISE code for any Sainik School** | UDISE+ *Know Your School* | CAPTCHA established from the site's own JS bundle. Several 11-digit strings appear in Sainik page source but carry **no UDISE label**, so they were deliberately **not** reported as codes. |

## 2 · Needs an Indian IP

| # | what | why |
|---|---|---|
| **13** | **DBSE — Delhi Board of School Education** affiliation bye-laws and TC rules (**new, C-63**) | `dbse.delhi.gov.in` returns **ECONNREFUSED** from here and `edudel.nic.in` exposes only a results portal. DBSE affiliates the 31 Schools of Specialised Excellence and Delhi Sports School, and **Delhi is one of only seven authorities the product encodes** — so a DBSE school currently gets no board layer at all. |


| # | what | why |
|---|---|---|
| 5 | **New Sainik Schools rulebook** (the ~100 post-2021 PPP schools) | the NCOG host is **geo-fenced to India**. Exact URLs are in `research/central-defence-and-ctsa.md`. This is the only route to **who signs a TC at a New Sainik School**, which is currently unknown. |

## 3 · Costs money, and it is the cheapest gap in the register

| # | what | cost |
|---|---|---|
| 6 | **MBOSE Annexures 1–4** — the actual certificate forms | **₹300** for the printed copy. Cheapest closure available anywhere in this programme. |
| 7 | **UPMSP Regulations under the Intermediate Education Act 1921** | a **printed volume**, not online (C-35). Holds UP's TC format, countersignature and migration rules — the largest school system in India, entirely unevidenced on these points. |

## 4 · RTI applications

All to the **Director of School Education / Commissioner (Education), Itanagar** unless noted.

| # | what | why it matters |
|---|---|---|
| 8 | Arunachal **s.2(6) / s.2(34)** competent- and registering-authority designation notifications | names the officer who actually grants recognition |
| 9 | Arunachal **s.1(4)** commencement notification for Act 8 of 2010 | fixes the date the Act came into force |
| 10 | Whatever pre-2010 departmental instrument **Rule 71 (Savings)** keeps alive on TC practice | the only remaining route to a pre-2010 Arunachal TC rule |
| 11 | **HP Secondary Education Code 2012, cl. 2.18** | reportedly holds Himachal's real TC rule. HPBOSE is **P0** and its blocking countersignature rests on Examination Regulation 3.5.7 alone. **Narrowed 2026-09-14 (C-62):** not judicially cited — the HP High Court filter was verified working (22,523 control results) and *"transfer certificate"* returns 33, **all** about proving a minor's age, none about issuance. So the clause is unreachable through case law; RTI is the remaining route. |
| 12 | **Sainik Schools' circulated uniform TC format** | the 52nd AISSPC (May 2026) records that uniform formats were **created and circulated**; they are not published. Ask the Sainik Schools Society. |

## 5 · One claim you should not rely on yet

**The MoD letter stating that "Armed Forces run schools do not fall under 'Specified Category'."**
It is **load-bearing for both AWES and RMS**, it was read directly by a research strand, and its URL
could not be recovered. It is labelled as such in the corpus and is the **top confirmation
priority**. Nothing should be built on it until it is re-found.

## 6 · ~~One gap that is not human-blocked~~ — CLOSED 2026-09-14

**The no-dues case-law limb is closed (C-39).** WebSearch is still at its session ceiling and that
never lifts — but the ceiling is on *search*, and `indiankanoon.org` answers **direct URL fetches**.
Working that way took C-11 from four southern judgments to **seven High Courts across four regions**,
and answered a question no stream had asked: **there is no Supreme Court ruling on the point.**

So the doctrine is a convergent line of High Courts, not binding national law — and two findings came
with it that reach the product: withholding can expose a school to **de-recognition proceedings**
(Rajasthan DB), and a school **may not manufacture a precondition out of its own form** (Delhi,
LPA 393/2014), which lands on the designer rather than on the school.


---

**None of these blocks implementation.** `AUTHORITY_BACKLOG.md` has ~45 `[A]`-evidenced authorities
ready to encode without any of the above. These are the edges, and the corpus states each as an
open gap rather than guessing past it.
