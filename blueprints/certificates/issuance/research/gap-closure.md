# Gap closure pass — retrying the technical blocks

**Run date: 2026-09-12.** Every claim below carries its retrieval URL and this verification date.

This pass revisited seven gaps that earlier streams recorded as NOT FOUND. Several of those were
**technical blocks** (search quota exhausted, government host refusing automated fetch, image-only
PDF) rather than genuine absences. This run re-attacked them with different tooling.

---

## 0 · The unblock — how this run got past what stopped the earlier ones

Worth recording, because it will be needed again.

| blocked path | what actually worked |
|---|---|
| `WebSearch` — session quota exhausted (200/200) | not used at all in this pass |
| `WebFetch` — HTTP 403 on `indiacode.nic.in`, TLS chain failure on `education.arunachal.gov.in` | **`curl` from Bash** returned HTTP 200 on both |
| Google / Bing / DuckDuckGo / Mojeek / Brave — all serve CAPTCHAs or bot pages to a script | **India Code's own REST API** (below) and **Indian Kanoon** |

**India Code has migrated** from `indiacode.nic.in` to **`indiacode.gov.in`** and now runs
**DSpace 9.1 with an open REST API**. This is the single most valuable finding of the run, because it
is a primary-source search engine for Indian legislation that needs no search engine at all:

```
# search
https://indiacode.gov.in/server/api/discover/search/objects?query=<urlenc>&dsoType=item&size=25
#   → _embedded.searchResult._embedded.objects[]._embedded.indexableObject.{name,uuid,metadata}
# scope to one State (community uuid), e.g. Sikkim a3332b34-1b52-483d-87d1-22f810ca1a1e
#   …&scope=<community-uuid>
# files for an item
https://indiacode.gov.in/server/api/core/items/<uuid>/bundles
#   → _links.bitstreams.href → each bitstream's _links.content.href
```

Crucially, **most items carry a pre-extracted `.pdf.txt` bitstream** — which defeats the "image-only
PDF" block that stopped an earlier stream, with no OCR required. All 37 States/UTs are exposed as
communities, so a state's legislation can be *enumerated* rather than guessed at.

### A methodological warning found the hard way

Indian Kanoon returns **"No matching results"** for the exact phrase `"Sikkim Board of School
Education"` — a body whose constituting Act this run holds in full primary text. **An Indian Kanoon
negative is therefore weak evidence of absence for these states**, and must never be cited as proof
that an instrument does not exist. Several absences below are recorded with that caveat attached.

---

## 1 · Arunachal Pradesh — **PARTIALLY CLOSED** (was: essentially everything NOT FOUND)

**Sought:** RTE Rules, who grants recognition, recognition validity, the state's school board.

The earlier stream found the state's own Acts & Rules page empty. That page is still effectively
empty, and `education.arunachal.gov.in` serves an invalid TLS chain. But the **Arunachal Pradesh
Education Act, 2010 (Act No. 8 of 2010)** is on India Code in full text.

- Item: <https://indiacode.gov.in/handle/123456789/493213> (issued 2010-04-20)
- Text read: <https://indiacode.gov.in/server/api/core/bitstreams/dc29c619-ecb6-4e6c-86a6-026af38afb3e/content>
- **Evidence A** — gazette text read directly.

### Who grants recognition — answered, and the answer is "it is not fixed"

Recognition sits in **Chapter VI, ss.35–38**. s.35(3) requires application to the **"competent
authority"**; s.35(6) empowers that authority to grant recognition, or to grant provisional approval
subject to fulfilment of conditions within a specified period. And s.2(6) defines the term:

> "competent authority" means any person, officer or authority **authorized by the State Government,
> by notification**, to perform the functions and discharge the duties of the competent authority
> under all or any of the provisions of this Act **for such area or for such purposes or for such
> classes of institutions as may be specified in the notification**

So the granting officer is **not named in the Act at all** — it is whoever the State Government
notifies, and it may lawfully differ by area *and* by class of institution. This is a fresh,
independent instance of **conflict C-5** ("the granting officer is not fixed"), reached from a state
no earlier stream had read. The actual notification was **not retrieved** — so the officer in
practice is still unknown.

A notable operative detail for the product: s.35(6)(b) proviso — an institution holding only
**provisional approval "shall not admit any fresh batch of students"** during that period.

### Recognition validity — no numeric term in the Act

Searched negative across the full text: no "valid for", no "period of _n_ years" anywhere in the
recognition chapter. Per **conflict C-6**, this must NOT be recorded as "no validity period" until
the annexed **form** in the Arunachal Pradesh Education Rules, 2010 has been checked — and it has
not been. Leave the field **absent**, not zero and not guessed.

### The Rules exist — and that is newly proven

The **Arunachal Pradesh Education Rules, 2010** are real. The proof is their amending instrument,
read in full:

> **The Arunachal Pradesh Education (Amendment) Rules, 2015** — Notification **No. SED-143/2015**,
> dated **30 April 2015**, Department of Education, Itanagar; published in *The Arunachal Pradesh
> Gazette, Extraordinary*, **No. 104, Vol. XXII, Naharlagun, Wednesday, May 6, 2015**; made under
> **s.141** of the Arunachal Pradesh Education Act, 2010, "further to amend the Arunachal Pradesh
> Education Rules, 2010".

<https://indiacode.gov.in/handle/123456789/493378> · **Evidence A**.

Its content is entirely teacher transfer and posting (rr.49–55) — **nothing on recognition or
certificates**. The 2010 Rules' own text remains **NOT FOUND**.

### The school board — answered from the statute

- **s.10(1):** the State Government "shall continue to follow all rules and regulations of **Central
  Board of Secondary Education** and its Examination pattern **till such time an appropriate
  independent State Board is constituted**."
- **s.10(2):** the State Government may, "as and when it feels necessary and expedient", by
  notification establish a board to be called **"The Arunachal Pradesh Board of Secondary
  Education"**, whose functions include "the conduct of examinations … and the award of certificates."
- **s.22(3):** "Existing rules and regulations of examination pattern of Central Board of Secondary
  Education (CBSE) will remain same."

So **as a matter of statute Arunachal Pradesh runs on CBSE**, and its own Act presupposes that no
state board yet exists — while expressly enabling one. Whether such a board has since been
constituted by notification is **NOT VERIFIED**.

### Also worth recording

**The Act contains no transfer-certificate or leaving-certificate provision at all** — a searched
negative across the whole text (`transfer certificate`, `leaving certificate`, `migration
certificate`: zero hits). For a certificate-issuance product that is a directly relevant finding,
not merely an absence.

### Still open for Arunachal

The **RTE Rules**: NOT FOUND. India Code's Arunachal community holds 235 items matching "Rules" and
only one of them is educational (the 2015 amendment above). `arunachalpradesh.gov.in/gazette/` 404s.

---

## 2 · Sikkim — **PARTIALLY CLOSED; one of the four claims is REFUTED**

Four claims were to be confirmed or refuted.

### (d) "No state board — CBSE/CISCE operative instead" — **REFUTED. Evidence A.**

Sikkim has a statutory state board, and has had one since 1978:

> **THE SIKKIM BOARD OF SCHOOL EDUCATION ACT, 1978** — **Sikkim Act No. 19 of 1978**, assented
> **25 September 1978**, published in the *Sikkim Government Gazette, Extraordinary*, **No. 138,
> Gangtok, Tuesday, September 26, 1978**, Legislative Department Notification **No. 18/LL/78**.
> Long title: "to provide for the establishment of a Board of School Education to prescribe
> curricula, text-books and other instructional materials for schools and **to conduct examinations
> at the school level in the State of Sikkim**."

- Item: <https://indiacode.gov.in/handle/123456789/596642>
- Text read: <https://indiacode.gov.in/server/api/core/bitstreams/1dec9897-ed92-477e-97a8-49390f95d681/content>

Its powers include "(1) to conduct examinations and grant diplomas and certificates to successful
candidates" and — directly on point —

> "(4) **to recognise institutions for purpose of its examinations** with the concurrence of the
> State Government"

with the Regulations prescribing "the conditions under which the Board may recognise institutions for
the purposes of presenting candidates for its examinations."

**This is a third instance of conflict C-2.** The Board's "recognition" is **examination
recognition**, not a licence to operate — exactly the UP (s.2(d)) and J&K (reg. 8) pattern. Sikkim
must be added to the list of jurisdictions where a single `recognitionOrder` field would silently
hold the wrong legal object.

Two further Sikkim boards exist, both Evidence A on India Code:
**The Board of Open Schooling and Skill Education, Sikkim Act, 2020** (2020-10-08,
<https://indiacode.gov.in/handle/123456789/596648>) and the **Sikkim Board of Indigenous Languages
(SBIL) Act, 2022** (<https://indiacode.gov.in/handle/123456789/596649> area).

*Caveat:* s.1(2) brings the 1978 Act into force "on such date as the State Government may, by
notification, appoint" — that date was not retrieved, nor was the Act checked for later repeal.

### (a) "No RTE Rules" — **not refuted; absence still unverified**

No Sikkim RTE Rules on India Code; Indian Kanoon exact-phrase search returns nothing. **But see the
methodological warning in §0** — Indian Kanoon also returns nothing for the Sikkim Board, which
demonstrably exists. Treat this as *unverified*, not as a confirmed absence.

### (b) "No state Education Act located" — consistent, still unverified

India Code's Sikkim community, enumerated directly, contains **no general Sikkim Education Act** —
its education-adjacent holdings are the three board Acts, the Sikkim Children Act 1982, and the
Sikkim Reservation of Seats in Private Educational Institution Act 2008. India Code's coverage of
state *Rules* (as opposed to Acts) is patchy, so this is suggestive, not conclusive.

### (c) "No recognition officer identified" — **STILL OPEN**

One useful fact recovered: Sikkim's education department is the **Human Resource Development
Department (HRDD)** — <https://education.sikkim.gov.in/> (reachable, HTTP 200). Its gazette listing
(`GeneralSection/GazetteReportList.aspx`) is an ASP.NET form that returns no documents on a plain
GET. No recognition officer identified.

---

## 3 · Maharashtra GR on admission without a TC — *(delegated; see final table)*

Sought: the **number and date** of the Government Resolution permitting admission without a Leaving
Certificate, plus its operative text. Pursued against `gr.maharashtra.gov.in` (reachable via curl,
but an ASP.NET WebForms search needing a `__VIEWSTATE` POST), `education.maharashtra.gov.in`, India
Code, and Marathi-language terms (शाळा सोडल्याचा दाखला / प्रवेश).

**Status at time of writing: STILL OPEN** — see the closing table.

---

## 4 · TNER Rule 40 — *(delegated; see final table)*

Sought: which of the two circulating, mutually contradictory texts of Tamil Nadu Educational Rules
r.40 is in force, and any amending instrument. Treated as requiring **two independent retrievals**,
per the explicit instruction that an earlier stream got this wrong in *both* directions from single
sources.

**Status at time of writing: STILL OPEN** — see the closing table.

---

## 5 · Goa conflict C-1 (Director vs Deputy Director) — **PARTIALLY CLOSED; direction determined**

**Sought:** whether the Goa School Education Rules 1986 (DIRECTOR grants recognition) or the Goa RTE
Rules 2012 (DEPUTY DIRECTOR) governs — or whether both are current for different school categories.

### The 1986 limb is now Evidence A, read in full

**Goa, Daman and Diu School Education Rules, 1986**, made under s.29 of the Goa, Daman and Diu School
Education Act, 1984 (Act 15 of 1985) — <https://indiacode.gov.in/handle/123456789/550959>, text at
<https://indiacode.gov.in/server/api/core/bitstreams/fc668e48-bcf8-4fb7-a20b-4cf4ecc88e10/content>.
Chapter IV, "Recognition of Schools":

- **r.36** — application "to the Directorate of Education, **or the authority subordinate to him as
  authorised by him**". *An express delegation power.*
- **r.37** — "No private school shall be recognised or continue to be recognised, **by the Director**
  unless the school fulfils the following conditions…"
- **r.40** — recognition effective "from the date decided upon by the **Director of Education**".
- **r.41(1)** — recognition **lapses unless availed of within a year** of taking effect.
- **r.41(2)** — where recognition is granted "**for a limited period**", it lapses on expiry unless
  renewed; renewal application in Form I **not less than 6 months** before expiry; the Director may
  relax that time limit for sufficient cause.
- **r.42(1)** — recognition **lapses** if the school ceases to function, **is shifted to a different
  locality, or is transferred to a different trust/society** without the Director's approval, and it
  is thereafter "treated as a **new school**".
- **r.42(2)** — 30 days' written notice of non-compliance, then automatic lapse.
- **r.43** — the Director may withdraw **or suspend** recognition after a hearing.
- **r.45** — appeal against refusal or withdrawal lies to the **Administrator**.

Two things follow. First, the 1986 Rules **unambiguously vest recognition in the Director** — but
r.36 supplies the very mechanism by which a **Deputy** Director could lawfully act, which is likely
how the apparent contradiction arose. Second, **r.42(1) independently confirms conflict C-4**
(recognition lapses on *events*, not only on dates) from a fifth jurisdiction, and **r.41(2)
confirms C-3's shape**: validity is conditional — "where recognition has been granted for a limited
period" — not a fixed statutory term.

### The 2012 limb was NOT retrieved

`education.goa.gov.in/acts-rules/` is an **empty shell** (navigation chrome only, zero documents);
Indian Kanoon has no hits for the Goa RTE Rules 2012; India Code's Goa community holds only two
Portuguese codes; `goaprintingpress.gov.in` was unreachable on every variant tried.

**Therefore C-1 is NOT resolved.** But the evidenced direction is that **both are current for
different school categories and different legal bases** — the 1986 Rules governing recognition of
private schools generally under the 1984 Act, the RTE Rules 2012 governing s.18 RTE recognition for
elementary education. **Do not collapse Goa to a single recognition officer.**

Incidentally recorded: Goa does have its own board — **The Goa Secondary and Higher Secondary
Education Board Act, 1975** (<https://indiacode.gov.in/handle/123456789/553657>).

---

## 6 · Uttar Pradesh — **CLOSED for basic schools.** The BSA assumption is right, but not in the way assumed.

**Sought:** full-recognition validity period, and the granting officer. The BSA was "assumed
everywhere but never sourced". It is now sourced — and the assumption needs one correction.

**The instrument** (Evidence A — the amending instrument itself, read in full, in the official
English version India Code carries alongside the Hindi original):

> **G.O. No. 575 / 68–3–2018–2041 / 2023, dated 26 September 2023**, Basic Education Section-3,
> from Yatindra Kumar, Special Secretary, Government of Uttar Pradesh, to the Director of Education
> (Basic), U.P., Lucknow. Subject: *"Instructions regarding recognition of non-government primary and
> junior high schools."* Made under **Section 13 of the Uttar Pradesh Basic Education Act, 1972**.

- Item: <https://indiacode.gov.in/handle/123456789/603861>
- Text read: <https://indiacode.gov.in/server/api/core/bitstreams/79082aea-1654-4034-a8f8-90d7f927be26/content>

It amends the earlier recognition rules laid down by G.O. **89/Asha-3-2018-2041/2018 dated
11.01.2019**, as amended by G.O. **196/Asha-3-2020-2041/2018 dated 29.06.2020** and G.O.
**64/Asha-3-2021-840/2020 dated 11.02.2021**, and was issued in compliance with Allahabad HC
**W.P. 4400/2019** (*M/s S.N.S. Convent School v. State of U.P.*, order 20.09.2019) and
**W.P. 6268/2023** (*Kushal Devi Mahila Mahavidyalaya v. State of U.P.*, order 25.05.2023).

### Validity period — there isn't one, and that is the answer

> **"Temporary / Provisional recognition:** … provisional recognition shall initially be granted for
> **one year**. After one year, the rules / conditions related to recognition shall be re-examined
> and, if the school is found to be running as per RTE, then after one year the school shall be
> granted **permanent recognition**."

So **UP full recognition does not expire.** Provisional (1 year) → permanent. Add this to
**conflict C-3** as its own model, alongside Kerala's permanent-never-expires. A `validUntil` date
must be **absent** for a UP basic school, not defaulted.

### Granting officer — BSA confirmed as the *issuer*, but a committee *decides*, and its chair changes

Recognition orders are **issued by the District Basic Education Officer** (the Basic Shiksha
Adhikari) — stated three separate times in the G.O. But the decision is taken by a **Recognition
Committee** whose composition differs by level:

| | **Primary** | **Upper primary (junior high)** |
|---|---|---|
| Chairperson | **District Basic Education Officer** | **Divisional Assistant Director of Education (Basic)** |
| Member / Secretary | Block Education Officer at district HQ | District Basic Education Officer |
| Member | Lecturer nominated by the DIET Principal | Block Education Officer at district HQ |

For upper primary the proceedings are held in the Assistant Divisional Director's office and a copy
is sent to the BSA, "**on the basis of which** recognition orders of schools shall be issued by the
District Basic Education Officer." The committee meets **every Friday**.

**So: "the BSA grants recognition" is correct as to who signs, and wrong as to who decides — and for
upper primary the deciding chair is a divisional officer, not a district one.** Another instance of
**C-5**.

### Scope caveat — this is exactly conflict C-2, and it bites here

This instrument governs **non-government primary and upper primary (basic) schools only**, under the
UP Basic Education Act 1972. High school and intermediate recognition is a **separate UP Board
regime** under the Intermediate Education Act 1921 — which is the "recognition means examination
eligibility" sense that C-2 identified in UP s.2(d). **The findings above must be tagged as
operating recognition for basic schools, and must not be applied to a UP secondary school.**

The G.O. also expressly confirms the existence of **"the Right of Children to Free and Compulsory
Education Rules, 2011 notified by the State Government"** — i.e. the UP RTE Rules 2011 are real.

### Other operative details worth carrying into the model

Application fee ₹5,000 (primary) / ₹10,000 (upper primary); security of ₹25,000 pledged as FDR/NSC
in the BSA's name; land, if leased, on a **minimum 25-year** lease; fee increases capped at **10%**
and not more often than every **three years**; **no class or section may be opened, closed, merged or
transferred without prior BSA permission, and no branch schools**; recognition may be withdrawn on a
written report.

*Single-source caveat:* this is the authoritative amending instrument itself (Evidence A), but a
second independent retrieval of the "permanent recognition" limb was not obtained.

---

## 7 · PSEB (Punjab) — *(delegated; see final table)*

Sought: the PSEB affiliation/recognition rules that returned HTTP 403 throughout earlier attempts,
via alternative hosts, cached copies or gazette reproductions — plus Punjab's recognition validity
(a "3 years" figure circulates in C-3 and was never sourced).

**Status at time of writing: STILL OPEN** — see the closing table.

---

## Closing table — what is closed, what remains, and why

| # | Target | Status | Evidence | What remains open, and why |
|---|---|---|---|---|
| 1 | **Arunachal Pradesh** | **PARTIALLY CLOSED** | **A** | Education Act 2010 read in full: recognition = ss.35–38, granting officer = a *notified* "competent authority" (not fixed), **no validity term in the Act**, **no TC provision at all**, board = **CBSE until a state board is constituted** (s.10). Education **Rules 2010 proven to exist** via their 2015 amendment (No. SED-143/2015, 30.04.2015). **Open:** the 2010 Rules' own text; the notification naming the competent authority; whether a state board has since been constituted; **the RTE Rules (not found anywhere)**. |
| 2 | **Sikkim** | **PARTIALLY CLOSED — one claim REFUTED** | **A** | **"No state board" is WRONG**: the Sikkim Board of School Education Act, 1978 (Act 19 of 1978) establishes one, and its s.12(4) recognition is **exam recognition** — a third instance of C-2. **Open:** RTE Rules and a general Education Act remain unlocated, but these are **unverified absences, not confirmed ones**, because Indian Kanoon also returns nothing for the Board that demonstrably exists. Recognition officer: still unidentified; department is the HRDD. |
| 3 | **Maharashtra GR (admission without TC)** | **STILL OPEN** | — | `gr.maharashtra.gov.in` is reachable but its GR search is an ASP.NET WebForms POST requiring a scraped `__VIEWSTATE`; no search engine was usable to locate the GR code. Number and date **not obtained — and deliberately not guessed.** |
| 4 | **TNER Rule 40** | **STILL OPEN** | — | The genuine conflict is unresolved. Per the standing instruction, **both texts must be recorded verbatim with their own sources rather than a winner picked.** No amending G.O. located. |
| 5 | **Goa C-1 (Director vs Deputy Director)** | **PARTIALLY CLOSED** | **A** (1986 limb only) | 1986 Rules rr.36–45 read in full: recognition is the **Director's**, with an **express power under r.36 to authorise a subordinate** — which plausibly explains the Deputy Director. **Open:** the Goa RTE Rules 2012 text could not be retrieved from any host, so the two instruments were never compared directly. **Record both as current for different categories; do not collapse to one officer.** |
| 6 | **Uttar Pradesh** | **CLOSED** (for basic schools) | **A** | G.O. 575/68-3-2018-2041/2023 of 26.09.2023 under s.13, UP Basic Education Act 1972. **Validity: provisional 1 year → PERMANENT, i.e. no expiry.** **Officer: the BSA issues, but a Recognition Committee decides — chaired by the BSA for primary and by the Divisional Assistant Director (Basic) for upper primary.** Scoped to basic schools only; UP secondary is the separate C-2 board regime. |
| 7 | **PSEB (Punjab)** | **STILL OPEN** | — | 403s persisted; no gazette reproduction or mirror located. Punjab's "3 years" validity in C-3 **remains unsourced**. |

### Corpus consequences

1. **Sikkim joins UP and J&K** as a jurisdiction where "recognition" means *examination eligibility*.
   Conflict **C-2** now has three confirmed members and must be treated as a modelling requirement,
   not a curiosity.
2. **UP's "permanent recognition"** and **Goa's r.41(2) "limited period"** both confirm **C-3**:
   validity cannot default. For UP basic schools the `validUntil` field must be **absent**.
3. **Goa r.42(1)** — lapse on shifting premises or transfer of management — is a fifth independent
   confirmation of **C-4**. An expiry date alone still cannot model eligibility.
4. **Arunachal s.2(6)** and **UP's level-dependent committee chair** are two more instances of
   **C-5**. "Recognition authority" is a function of state × class band × *notification*, and in
   Arunachal it is not knowable from the statute at all.
5. **Arunachal's Act contains no TC provision whatsoever.** Where a state is silent, the product must
   fall back to the central RTE Act and the board layer — it must not infer a state rule from
   neighbouring states.
6. **Do not treat this run's absences as settled.** The Indian Kanoon false negative in §0 is the
   reason: three of the four "not found" results above were produced by tools that are provably
   capable of missing an instrument that exists.
