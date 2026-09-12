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

### A controlled absence test for the three missing RTE Rules

Because "not found" is only useful if the search could have succeeded, the RTE-Rules absences were
tested against a host **known to carry this exact class of document**: the Centre for Civil Society's
RTE platform, `righttoeducation.in/sites/default/files/`, which serves Punjab's and UP's state RTE
Rules as real PDFs.

The host returns **HTTP 200 for everything** — a soft 404 — so status codes are worthless and a
control probe was required:

| requested file | bytes | type |
|---|---|---|
| `Punjab RTE Rules, 2011.pdf` | 18,853,385 | **PDF** ✔ |
| `Goa RTE Rules, 2012.pdf` | 778,546 | HTML |
| `Arunachal Pradesh RTE Rules, 2011.pdf` | 778,546 | HTML |
| `Sikkim RTE Rules, 2011.pdf` | 778,546 | HTML |
| **`ZZZ Nonexistent.pdf`** *(control)* | **778,546** | **HTML** |

The three target states return **byte-for-byte the same page as a file that certainly does not
exist**. That is a *controlled* negative: these three RTE Rules are genuinely absent from a host that
demonstrably carries the same document type for other states. It does not prove the Rules do not
exist — only that this avenue is exhausted, rigorously.

**Note the contrast with the next paragraph.** This is what a trustworthy absence looks like; the
Indian Kanoon negatives below are not that.

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
certificate`: zero hits; every "certificate" hit is a *registration* certificate under ss.32–34, or a
board examination certificate).

But the silence is **delegation, not a gap**. s.46 provides:

> **46. Admission, etc., to be according to rules:** … Admission of students to a recognized
> educational institution including the maximum number of students to be admitted thereto, **their
> transfers, migrations and removal shall be in accordance with such rules as may be prescribed.**

So Arunachal's transfer and migration regime lives entirely in the **Arunachal Pradesh Education
Rules, 2010** — the text this run could not obtain. That is now the single highest-value missing
document for this state: it is where the TC rules, and probably the recognition validity term (per
C-6), both sit.

### Still open for Arunachal

The **RTE Rules**: NOT FOUND. India Code's Arunachal community holds 235 items matching "Rules" and
only one of them is educational (the 2015 amendment above); `arunachalpradesh.gov.in/gazette/` 404s;
and the controlled probe in §0 shows they are absent from the CCS RTE platform too. Three avenues,
none of them the State gazette. **Searched-negative, not confirmed absence.**

---

## 2 · Sikkim — **MOSTLY CLOSED; two of the four claims do not survive**

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

### (a) "No RTE Rules" — **not refuted; absence now tested but still not proven**

No Sikkim RTE Rules on India Code; Indian Kanoon exact-phrase search returns nothing (weak — see the
warning in §0); and the **controlled probe in §0** shows they are genuinely absent from the CCS RTE
platform that does carry Punjab's and UP's. Three avenues exhausted, none of them the State's own
gazette. Treat as **searched-negative, not confirmed absence.**

### (b) "No state Education Act located" — **explained, and the explanation is the answer**

There was one, and it has been **repealed**. The Government of Sikkim publishes an official
**"Repealed / Withdrawn Acts"** list, and item 6 on it is:

> **6. The Sikkim Education Act, 2002**

<https://www.sikkim.gov.in/media/repealed-withdrawn-acts> · verified 2026-09-12 · **Evidence B**
(official state portal listing; the page carries no linked text or repealing instrument, so the
repeal date and the repealing Act were not obtained).

This converts a weak "not found" into a **substantive finding**: the earlier stream could not locate
a Sikkim Education Act because the State's general education statute **no longer exists as law**.
India Code's Sikkim community is consistent — it holds no Sikkim Education Act 2002, and its
education-adjacent holdings are the three board Acts, the Sikkim Children Act 1982, and the Sikkim
Reservation of Seats in Private Educational Institution Act 2008.

**What now supplies the operating-recognition regime in Sikkim is therefore unknown** — presumably
the central RTE Act s.18 plus state rules or executive orders. That is the open question, and it is a
sharper one than before.

### The 1978 Board Act is corroborated as still in force

The same official repealed list **does not contain** the Sikkim Board of School Education Act, 1978 —
while it does list twelve other Sikkim Acts including the 2002 Education Act. India Code likewise
carries the 1978 Act in its live Sikkim community. Two independent sources, one of them the State's
own repeal register. **Evidence B for currency**, on top of Evidence A for the text.

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

## 4 · TNER Rule 40 — **RESOLVED, but not as the question assumed**

**Sought:** which of the two circulating, contradictory texts of Tamil Nadu Educational Rules r.40 is
in force, and any amending instrument.

### The verdict

**TEXT TWO is rule 40. TEXT ONE could not be found as rule 40 in any source.** Rule 40 reads:

> **40.** When a pupil is allowed to continue his studies in an institution during any term on the
> assumption that there are no arrears of fees for previous terms, **a transfer certificate shall
> not be refused on the plea that such arrears exist.**

<https://indiankanoon.org/doc/148006411/> · retrieved 2026-09-12 · **Evidence C** — a credible
secondary publisher serving structured markup. **No gazette or Tamil Nadu government copy of TNER
was obtainable from any host**, so nothing here reaches Evidence A for TNER itself.

### Why this is more than a single-source answer

The obvious failure mode — an **off-by-one**, since **rule 39 is simply "Deleted."** — was checked
three independent ways:

1. **Seven standalone rule documents** (rr.38–44) fetched individually, all mutually consistent.
2. **Sequential markers in the full-Act text** (<https://indiankanoon.org/doc/24617366/>): each rule's
   text is trailed by the next rule's number, and the chain runs 33→45 unbroken.
3. **Internal cross-references — the decisive check.** Appendix 5 is captioned *"(See Chapter III,
   rule 34)"*, and the rule the chain assigns to 34 is exactly the one prescribing a TC "in the
   prescribed form (Appendix 5)". Rule 43 refers back to "rejected under rule 42" and lands on the
   refusal rule. Numbering is continuous across chapters, so there is exactly **one** rule 40.

A further corroboration from a *separately drafted* instrument: the identical clause appears in the
**Code of Regulations for Anglo-Indian Schools, Tamil Nadu, at rule 57(iv)**
(<https://indiankanoon.org/doc/135063758/>) — same wording, different code, different number. That
parallel is itself a plausible seed for the "two rule 40s" confusion.

### Where TEXT ONE probably came from

Its substance is real Tamil Nadu school law, just not rule 40 — it reads as a conflation of
**rule 34** (the TC must show "*that he has paid all fees due to that school*") and **Appendix 5,
item 8** ("*Whether the pupil has paid all the fees due to the school*") with the rr.41–43 provisos.

**Caveat, not papered over:** because TEXT ONE was never located, its existence in a print-only or
commercial edition cannot be excluded. This is a **failure to find, not proof of non-existence.**

### Rules 41–43 — the earlier premise was substantively right, numerically off

The one-term cap is **rule 38**, not rr.40–42; rr.41–43 cross-refer to it.

> **38.** Before granting a transfer certificate, the headmaster is entitled to claim the special fee
> **for one term only** and that the term in which the last attendance of the pupil is registered.
>
> **41.** When a proper application … is received at the end of a term … the headmaster shall
> **forthwith issue** the certificate, provided that his claims for special fees admissible under
> rule 38 have been satisfied.
>
> **42.** When proper application is received at any other time and when good and sufficient reasons
> are shown, the headmaster shall issue the certificate provided that his claims for fees admissible
> under rule 38 have been satisfied. **If good and sufficient reasons are not shown, the headmaster
> may refuse** to grant the transfer certificate.
>
> **43.** An application rejected under rule 42 may be renewed at the end of a term … and if so
> renewed the headmaster shall issue the transfer certificate forthwith, provided his claims for
> special fees admissible under rule 38 have been satisfied.

**Only one term's special fee is ever a valid ground; accumulated arrears never are.** Two nuances
for the product: rule 40's bar is **conditional** (it bites where the pupil was allowed to continue
on the assumption of no arrears), and rule 42 **does** permit refusal — but for failure to show good
and sufficient reasons *as to timing*, **not for fees**. **Rule 47** gives a right of appeal to the
District Educational Officer against refusal or delay.

### No amending instrument found

The consolidation carries **35 inline G.O. annotations**, the latest being **G.O. Ms. No. 78,
BC/IBS&MW, dated 4 August 2005** — so amendments *are* tracked in this text, and **rule 40 carries
none**. Meaningful absence, though not conclusive. India Code does not carry TNER at all (it holds
the 1974 and 2023 Private Schools Rules instead) — TNER is a departmental code, not a gazetted
rule-set under a live Act, which explains its absence.

### W.A. 3075/2021 — retrieved, and C-10's entry is confirmed

<https://indiankanoon.org/doc/57800773/> — Division Bench, **19.07.2024**, setting aside the order of
28.10.2021 in W.P. 16581/2021. **It does not cite TNER rr.40–42 by number or otherwise** — confirming
the earlier finding. It reasons entirely from the RTE Act 2009 (ss.5, 15, 16, 17, 30). The single
provision it strikes is **Serial No. 8 of Annexure V to the Code of Regulations for Matriculation
Schools** — *"Whether the pupil has paid all the fees due to the school"*
(<https://indiankanoon.org/doc/37801442/>), the exact twin of TNER Appendix 5 item 8.

**So the court struck the fee-entry field on the TC form — the true locus of the withholding
practice — not rule 40.** It also directed that TNER and the Matric Code be revisited and amended
**within three months**, with compliance listed for 25.10.2024.

**Was that amendment made? No evidence that it was.** In *Minor G. Sharvesh v. State of Tamil Nadu*,
W.P. 9465/2025, **19.08.2025** (<https://indiankanoon.org/doc/87392072/>), a school still asserted
"every right to deny" over unpaid fees, and the Court granted relief purely on the RTE Act and
G.O.(Ms) No. 189 dated 12.07.2010 — citing no amended rule. **This is C-11 exactly: the rule stands
and is unenforceable.** The 2024 DB reasoning is now being followed outside Tamil Nadu — Karnataka HC
15.07.2025 (<https://indiankanoon.org/doc/171614637/>) and Bombay HC 16.10.2025
(<https://indiankanoon.org/doc/112598316/>).

### The correction that matters most — TNER may no longer be the governing instrument

For **private schools in Tamil Nadu**, the current rules are the **Tamil Nadu Private Schools
(Regulation) Rules, 2023**, made under s.57 of the TN Private Schools (Regulation) Act, 2018
(TN Act 35 of 2019), notified by **G.O. Ms. No. 14, School Education (MS), dated 13 January 2023** —
retrieved as official gazette text from a government host, **Evidence A/B**:
<https://indiacode.gov.in/server/api/core/bitstreams/2bbb2658-1cac-4c51-ae0e-f81f5318b5b5/content>

Its **rule 18** contains **no withholding power and no express prohibition**:

> **18. Issue of Transfer Certificate.—** (1) Every pupil of a private school shall have the right to
> seek transfer from that school to continue his school education in another school.
> (2) An application for issue of Transfer Certificate shall be made in writing to the Head Master …
> In such case, the Head Master **shall issue** the Transfer Certificate as per the instructions
> issued in this regard.

**Net effect: no instrument retrieved authorises withholding a TC for accumulated arrears, and the
binding layer for Tamil Nadu is the RTE Act plus the 19.07.2024 DB directions — not TNER rule 40,
which no court has ever construed by number.**

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

*Currency:* the India Code consolidation carries inline amendment footnotes, the latest being the
**(Amendment) Rules, 1994 (O.G. Series I No. 28 dated 14-10-1994)**. So amendments *are* tracked in
this text and the recognition chapter carries none after 1986. No check was made for amendments after
1994, and none would appear in this consolidation if made.

Two things follow. First, the 1986 Rules **unambiguously vest recognition in the Director** — but
r.36 supplies the very mechanism by which a **Deputy** Director could lawfully act, which is likely
how the apparent contradiction arose. Second, **r.42(1) independently confirms conflict C-4**
(recognition lapses on *events*, not only on dates) from a fifth jurisdiction, and **r.41(2)
confirms C-3's shape**: validity is conditional — "where recognition has been granted for a limited
period" — not a fixed statutory term.

### The 2012 limb was NOT retrieved

`education.goa.gov.in/acts-rules/` is an **empty shell** (navigation chrome only, zero documents);
Indian Kanoon has no hits for the Goa RTE Rules 2012; India Code's Goa community holds only two
Portuguese codes; the CCS RTE platform returns the control page (§0); and `goaprintingpress.gov.in`
**drops the TLS connection outright** (`openssl s_client`: "unexpected eof while reading", no peer
certificate; it resolves to a Google Cloud address, so the block is likely geographic). That last one
is a genuine technical block, not an absence — and it is the host most likely to hold the gazette
text.

**Wayback holds the index of that host**, and it lists
`goaprintingpress.gov.in/school-education-act-rules/` and the publication
*"439-school-education-act-1984-a-rules-1986"* — so the route exists for a future pass even though
the RTE 2012 rules were not among the captured URLs.

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

### A second instrument was then read — and it does not agree

Because "one source is not verification", the **Uttar Pradesh Right of Children to Free and
Compulsory Education Rules, 2011** were retrieved independently and read in full:

- Item: <https://indiacode.gov.in/handle/123456789/604009> area · scope search of the UP community
- English text read: <https://indiacode.gov.in/server/api/core/bitstreams/9f2b5302-e841-42a6-922c-d707b6552600/content>
- **Evidence A.**

That second retrieval was worth doing, because **the two instruments give different validity
periods**, and a single-source answer would have shipped half the picture.

### Validity period — **UNRESOLVED. Two instruments, two numbers.**

| instrument | legal basis | what it says |
|---|---|---|
| **G.O. 575/…/2023, 26.09.2023**, Part IV | s.13, UP Basic Education Act 1972 | provisional **1 year** → on re-examination, **PERMANENT recognition** |
| **UP RTE Rules 2011, Form-II** (the annexed recognition certificate) | s.18, RTE Act 2009 / r.11(4) | "*provisional recognition … for Class___ to Class___ **for a period of three years** w.e.f. …*", and condition 1: "*The grant for recognition is **not extendable** and does not in any way imply any obligation to recognize/affiliate **beyond Class VIII**.*" |

Both are current on their face and neither repeals the other — they are made under **different Acts**
for **different purposes**. **Do not pick a winner and do not default the field.** Record UP as
holding *two* recognition validities, and surface both.

### This is conflict C-6, confirmed from a fourth state — and it is now a rule, not a lead

C-6 observed that Assam, Meghalaya and Tripura carry a word-identical annexed certificate reading
*"provisional recognition … for Class ___ to Class ___ for a period of three years"*, *"not
extendable"*, and nothing beyond Class VIII — while their **rule bodies state no period at all** —
and traced it to the MHRD model form. **UP's Form-II carries that same text, essentially verbatim.**

C-6's action item is therefore vindicated and should be promoted from a lead to a standing rule:
**before recording "no validity period" for any state, read its annexed recognition FORM, not just
the rule body.** Four states now prove the form carries a term the rules omit. This also means
Delhi, Himachal and J&K — the three C-3 states reported as having "no numeric term in any rule" —
are **probably artefacts of reading only the rule body**, and must be re-checked at form level.

### Conflict C-7 confirmed from a fifth state

UP's Form-II, condition 14: "*The **recognition Code Number** allotted to your school is ………… This
may please be noted and quoted for any correspondence with this office.*" And r.11(8): "*Every Zila
Shiksha Adhikari shall maintain a register of recognized schools and **allot a number** to every such
school.*"

C-7 found this fourth identifier in Assam, Meghalaya, Odisha and Nagaland. **UP makes five.** The
recognition Code Number should be modelled as a first-class identifier, distinct from the recognition
order number, the UDISE+ code and the affiliation number.

### The finding that matters most for *this* product

**UP RTE Rules 2011, rule 23** — "Award of certificate for the completion of elementary education
(section-30)":

> (1) The certificate of completion of elementary education shall be issued at the school level
> **within one month** of the completion of elementary education in the form prescribed by Director
> of Education (Basic):
> **Provided that the private institutions shall clearly mention the allotted recognition
> registration number on the certificate issued by them.**
> …
> (3) The certificate shall contain the **pupil cumulative record** of the child and also specify
> achievements of the child in areas of activities beyond the prescribed course of study …

Three hard requirements for the issuance module, all Evidence A:

1. a **one-month issuance deadline** from completion of elementary education;
2. a **mandatory printed recognition registration number** on certificates issued by private
   schools; and
3. a required **pupil cumulative record** plus beyond-syllabus achievements as certificate *content*.

Requirement 2 is a fifth independent confirmation of **conflict C-12** — *printed verifiable
identifiers are replacing human countersignature* — after Karnataka's DISE code, Tamil Nadu's
recognition/DGE numbers, MP r.19 proviso and CG clause 16(4). **That is now five states, five
sources, one direction**, and it is the strongest available argument for putting the recognition
number and UDISE code on the document behind a QR verification endpoint rather than building a
countersignature workflow.

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

#### An apparent second-officer conflict, which resolves — and the resolution is the interesting part

The UP RTE Rules 2011 r.11 appear at first to contradict the G.O. outright: there, s.18 recognition
is granted **by the Zila Shiksha Adhikari**, who "shall be the authorized officer" (r.11(1)), who
inspects (r.11(3)), grants recognition in Form-II within 60 days (r.11(4)), maintains the register
(r.11(8)) and withdraws recognition (r.12).

It resolves on the definition — **r.2(1)(j)**:

> "*Zila Shiksha Adhikari*" means a District Level Officer in Department of **Basic** Education **or**
> Department of **Secondary** Education, **as the case may be**.

So "Zila Shiksha Adhikari" is not a post but a **role that resolves to a different officer depending
on the school's class band**: for a basic/elementary school it *is* the District Basic Education
Officer (the BSA), which is exactly who the 2023 G.O. names; for a secondary school it is the
district-level officer of the Secondary Education Department.

**The BSA assumption is therefore vindicated for elementary schools — and is wrong for secondary.**
This is a third UP-sourced instance of **C-5**, and note the shape: the authority is a function of
class band written into a *definition*, where a reader checking only the operative rule would never
see it.

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

### Independent corroboration — the RTE limb was retrieved twice more, off different hosts

A parallel retrieval pulled the **UP RTE Rules 2011 gazette text from two further hosts in two
languages**, confirming r.11, r.2(1)(j) and Form-II word for word:

- English: <http://nceindia.org.in/wp-content/uploads/2018/09/Uttar-Pradesh-RTE-Rules.pdf>
- Hindi: <https://righttoeducation.in/sites/default/files/Uttar%20Pradesh%20RTE%20Rules%20%5BHindi%5D.pdf>
  — "तीन वर्षों की अवधि के लिए **औपबंधिक मान्यता**" … "मान्यता के लिए स्वीकृति **विस्तारणीय नहीं है**"

The instrument is Notification **No. 2510/79-5-2011-29/09**, Lucknow, U.P. Shasan Shiksha Anubhag-5,
published in official English translation under **Art. 348(3)**. **Form-II is printed on the
letterhead "OFFICE OF THE ZILA SHIKSHA ADHIKARI" / "कार्यालय जिला शिक्षा अधिकारी" and is signed by the
ZSA** — which settles the officer question at the level of the prescribed form, not just the rule.

So the RTE limb of UP now rests on **three retrievals across three hosts and two languages**. The
2023 G.O. limb still rests on the single India Code copy.

### Two traps in UP r.11 that must not be conflated with the validity term

Rule 11 contains **three** different "three years", and only one is a validity period:

1. **Form-II** — provisional recognition **for three years**, "not extendable". *This is the term.*
2. **r.11(5)–(6)** — schools not conforming may request re-inspection within **two years**, and those
   still not conforming "**even after three years from the commencement of the Act** shall cease to
   function" (i.e. ≈31 March 2013). *Transitional, spent.*
3. Neither is a renewal period — **r.11 contains no renewal procedure at all.**

### A source-quality warning worth recording

The Centre for Civil Society's Uttar Pradesh state-regulation paper
(<https://ccs.in/sites/default/files/2024-12/SRP_Uttar%20pradesh_CCS%20.pdf>, p.13) attributes the
three-year provisional recognition to **Rule 11(4)**. The rule body says no such thing — the term is
in **Form-II**. The conclusion is right and the citation is wrong. **Cite Form-II.** This is the C-6
error in the wild, in a respected secondary source.

*Remaining caveat:* no check was made for a post-2011 amendment to the UP RTE Rules or Form-II.
Absence of a found amendment is not proof that none exists.

---

## 7 · PSEB (Punjab) — **CLOSED, and it corrects C-3**

**Sought:** the PSEB affiliation/recognition rules that 403'd throughout earlier attempts, plus
Punjab's recognition validity (the unsourced "3 years" in C-3).

`pseb.ac.in` still serves a **Cloudflare interstitial (HTTP 403)** to every non-browser client, and
its document CDN `files-cdn.pseb.ac.in` **no longer resolves in DNS**. The route that worked was the
**Wayback Machine CDX API** over PSEB's own domain — every document below was published by PSEB and
linked from its Affiliation Branch page, recovered as an archived capture.

### The affiliation rules — FOUND

> **The Punjab School Education Board Regulations for Affiliation of Institutions, 1988**, as amended
> 1993, 1998, 2004, 2006 and 2009 — Notification **No. PSEB-Meeting-2009/184 dated 09-07-2009**, made
> under **s.24(1) read with s.17(6) of the PSEB Act 1969**, with further amendments at "item no. 2,
> dated 01-04-2013".

<https://web.archive.org/web/20220126223021id_/http://pseb.ac.in/sites/default/files/school_affiliation_section/53_1215.pdf>
· **Evidence A** (PSEB-published PDF, read in full) · retrieved 2026-09-12.

Still operative as of 2021: PSEB letter ਪਸਸਬ-ਐਫੀ:2021/545 dated 19-02-2021 directs affiliated schools
to comply with the Regulations "in letter and spirit".

### C-3's Punjab row is wrong — **PSEB affiliation has no validity term at all**

Regulation 11 makes affiliation **continuing, maintained by annual payment**:

> "An institution to which affiliation has been granted, shall pay by September 15 of the preceding
> year, a **continuation fee of Rs. 5,000/- annually** or it may pay Rs. 45,000/- lump sum which will
> remain applicable for 12 (twelve) years. In case the continuation fee and the particulars required
> by Annexure-B … are not received by September 15, **the affiliation shall stand suspended**…"

plus Regulation 18 (Annual Progress Report by 15 September). **The only "three years" in the entire
Affiliation Regulations is Regulation 22** — a *penalty* ceiling, empowering withdrawal of
affiliation "either permanently … or for a period of three years." **That is a debarment maximum,
not a licence term**, and is the likely origin of the unsourced figure in C-3.

For Classes 5 and 8, PSEB recognition is explicitly **annual** — a per-session continuation fee keeps
the ਆਰਜ਼ੀ ਸ਼ਨਾਖਤੀ ਨੰਬਰ (temporary recognition number) alive, and a one-year gap forces re-application.

**Correct model for Punjab/PSEB: continuing affiliation with an annual continuation fee and Annual
Progress Report, suspended on non-payment. Not a fixed term.**

### Where Punjab's "three years" *is* real — the RTE layer, not the board layer

> **Punjab Right of Children to Free and Compulsory Education Rules, 2011** — Notification
> **No. G.S.R. 69/C.A.35/2009/S.38/2011 dated 10 October 2011**, *Punjab Govt. Gazette (Extraordinary)*,
> **12 October 2011, pp. 447–468**.
>
> **FORM-II [See sub-rule (4) of rule 11] — OFFICE OF DISTRICT EDUCATION OFFICER:** "I convey the
> grant of **provisional recognition** to the …… for class—to class—— **for a period of three years
> w.e.f. — to —**." Condition 1: "The grant for recognition **is not extendable** and does not in any
> [way] imply any obligation to recognize/affiliate **beyond Class VIII**."

Retrieved from the Union Ministry of Education's own host
(<https://www.education.gov.in/sites/upload_files/mhrd/files/upload_document/punjab_rte-rules_2011.pdf>,
now 404, via Wayback) and from `righttoeducation.in`. **Evidence A.**

*Honest caveat carried forward from the retrieval:* the two files are **byte-identical**
(MD5 `31e086f84c3cceb6ba3a6287365ede39`) — one gazette scan on two hosts, not two independent
typesettings. Provenance is strong; independence is partial.

**Granting officer (Punjab, elementary): the District Education Officer** — r.11(4), recognition in
Form-II "within a period of **15 days** from the date of inspection"; appeal to the Director within
30 days, second appeal to the State Government; withdrawal under r.12 with appeal to the Punjab State
Commission for Protection of Child Rights. Corroborated at **Evidence B** by the Punjab DSE's own
online procedure, which routes applications to the **DEO (Elementary)**:
<https://epunjabschool.gov.in/school-recognition-guidelines.aspx>

**Do not conflate three different "three years" in the Punjab rules:** the Form-II *term*; r.11(5)–(6)'s
*transitional* three years to remove deficiencies, after which recognition is deemed withdrawn; and
PSEB Reg. 22's *debarment* ceiling.

### The finding that matters most for this product — Punjab gates the TC on a Board migration certificate

The TC rule is **not** in the Affiliation Regulations (grepped: no "transfer", "leaving" or
"migration") and **not** in the Punjab RTE Rules 2011 (no TC provision at all). It lives in PSEB's
annual admission/registration instructions, and the operative instrument is PSEB's **School Migration
Certificate**:

> **Clause 12(ੳ)** — "ਜਦ ਤੱਕ ਵਿਦਿਆਰਥੀ ਨੂੰ ਪੰਜਾਬ ਬੋਰਡ ਵੱਲੋਂ ਮਾਈਗਰੇਸ਼ਨ ਸਰਟੀਫਿਕੇਟ ਜਾਰੀ ਨਹੀਂ ਕੀਤਾ ਜਾਂਦਾ, ਤਦ ਤੱਕ **ਟਰਾਂਸਫਰ
> ਸਰਟੀਫਿਕੇਟ ਨਾ ਜਾਰੀ ਕੀਤਾ ਜਾਵੇ**" — *until PSEB issues the migration certificate, the transfer
> certificate shall not be issued*, and the student shall not be admitted to the new school.

Source: PSEB, admission qualifications and conditions for regular admission to Classes IX/X for
2017-18, Director (Academic) for Secretary PSEB — **Evidence A/B**, confirmed in PSEB's 2019-20
registration instructions and its 2021 migration circular (Controller of Examinations), by which the
process is fully online with the migration certificate printed after online fee payment.

**This is a genuine, sourced TC-withholding gate — and it is procedural, not fee-based.** It is the
first such gate this programme has found that survives scrutiny, and it belongs in the model as a
*board-layer prerequisite*, distinct from the no-dues gates the courts have gutted (C-11).

Surrounding regime, same instrument: migration must be completed **at least 45 days before** PSEB
annual exams; the form must be attested by **both** school heads; for inter-board moves the student
**must be admitted within 15 days** or approval is cancelled.

### And a state-layer countersignature, which C-12 should record

> **Clause 14(ਅ)** — for a student arriving from **another State or Board**, the **school-leaving
> certificate must be counter-signed by the competent officer of that Board or by the District
> Education Officer**, plus an **equivalence certificate (ਸਮਾਨਤਾ ਪੱਤਰ)** from PSEB's Academic Branch;
> without the equivalence certificate, admission is cancelled.

This fits the C-12 pattern exactly: **inbound, out-of-state, condition-triggered** countersignature —
alongside Kerala Ch.VI r.10, Puducherry 1996 r.62, MH r.22.1, GJ reg 12(9), Goa r.116(2), DNHDD
r.97(2) and Bihar s.272. It is *not* a gate on intra-state issuance.

---

## Closing table — what is closed, what remains, and why

| # | Target | Status | Evidence | What remains open, and why |
|---|---|---|---|---|
| 1 | **Arunachal Pradesh** | **PARTIALLY CLOSED** | **A** | Education Act 2010 read in full: recognition = ss.35–38, granting officer = a *notified* "competent authority" (not fixed), **no validity term in the Act**, **no TC provision — s.46 delegates transfers/migrations wholly to the Rules**, board = **CBSE until a state board is constituted** (s.10). Education **Rules 2010 proven to exist** via their 2015 amendment (No. SED-143/2015, 30.04.2015). **Open, and now the top priority for this state:** the **2010 Rules' own text** — it holds both the TC regime and probably the validity term. Also open: the notification naming the competent authority; whether a state board now exists; **the RTE Rules (not found anywhere)**. |
| 2 | **Sikkim** | **MOSTLY CLOSED — two of four claims overturned** | **A** + **B** | **"No state board" is WRONG**: the Sikkim Board of School Education Act, 1978 (Act 19 of 1978) establishes one — and it is absent from the State's own repealed-Acts register, so it stands. Its recognition is **exam recognition** — a third instance of C-2. **"No state Education Act" is EXPLAINED**: the Sikkim Education Act, **2002** existed and has been **repealed** (official state list). **Open:** RTE Rules still unlocated (and an Indian Kanoon negative is unreliable here — see §0); the repeal date and repealing instrument; and, now the sharpest question, **what supplies operating recognition in Sikkim at all**. Recognition officer: still unidentified. |
| 3 | **Maharashtra GR (admission without TC)** | **STILL OPEN** | — | `gr.maharashtra.gov.in` is reachable but its GR search is an ASP.NET WebForms POST requiring a scraped `__VIEWSTATE`; no search engine was usable to locate the GR code. Number and date **not obtained — and deliberately not guessed.** |
| 4 | **TNER Rule 40** | **CLOSED** (with a stated caveat) | **C** for TNER; **A/B** for the 2023 Rules | **Rule 40 FORBIDS refusing a TC over arrears.** TEXT ONE was not found as rule 40 anywhere and appears to be a conflation of r.34 / Appendix 5 item 8 with the rr.41–43 provisos. Numbering verified three ways (the r.39-is-"Deleted" off-by-one was specifically excluded). **Caveat: single host (Indian Kanoon); no gazette copy of TNER exists online, so this is not Evidence A.** No amending G.O. — and the 2024 DB's directed amendment appears never to have been made. **Bigger point: for private schools TNER is likely superseded by the TN Private Schools (Regulation) Rules 2023, r.18, which contains no withholding power.** |
| 5 | **Goa C-1 (Director vs Deputy Director)** | **PARTIALLY CLOSED** | **A** (1986 limb only) | 1986 Rules rr.36–45 read in full: recognition is the **Director's**, with an **express power under r.36 to authorise a subordinate** — which plausibly explains the Deputy Director. **Open:** the Goa RTE Rules 2012 text could not be retrieved from any host, so the two instruments were never compared directly. **Record both as current for different categories; do not collapse to one officer.** |
| 6 | **Uttar Pradesh — officer** | **CLOSED** | **A** (two independent instruments) | G.O. 575/68-3-2018-2041/2023 of 26.09.2023 (s.13, UP Basic Education Act 1972) **and** UP RTE Rules 2011 r.11 + r.2(1)(j). **The BSA assumption is correct for basic/elementary schools and wrong for secondary** — "Zila Shiksha Adhikari" resolves by class band. The BSA *issues*; a **Recognition Committee decides**, chaired by the BSA for primary but by the **Divisional Assistant Director of Education (Basic)** for upper primary. |
| 6b | **Uttar Pradesh — validity** | **STILL OPEN (new conflict)** | **A** on both limbs | The two instruments disagree: the 2023 G.O. says provisional **1 year → permanent**; the RTE Rules 2011 **Form-II** says provisional **three years, "not extendable"**, nothing beyond Class VIII. Different Acts, different purposes, neither repeals the other. **Do not default this field; surface both.** |
| 7 | **PSEB (Punjab)** | **CLOSED — and it corrects C-3** | **A** (Wayback captures of PSEB-published PDFs) | Affiliation Regulations 1988 (am. to 2013) recovered. **PSEB affiliation has NO validity term** — Reg. 11 makes it continuing on an annual ₹5,000 continuation fee (or ₹45,000 for 12 years), suspended on non-payment. **C-3's "Punjab — 3 years" is wrong**: the only 3 years in the Affiliation Regs is Reg. 22's *debarment* ceiling. The real 3-year term is the **Punjab RTE Rules 2011 Form-II** (provisional, "not extendable"), granted by the **District Education Officer** within 15 days. **Plus a major product finding: PSEB gates the TC on a Board migration certificate.** |

### Corpus consequences

1. **Sikkim joins UP and J&K** as a jurisdiction where "recognition" means *examination eligibility*.
   Conflict **C-2** now has three confirmed members and must be treated as a modelling requirement,
   not a curiosity.
2. **Validity still cannot default, and UP now proves it twice over** — the same state yields
   "permanent" from one instrument and "three years, not extendable" from another. Goa r.41(2)'s
   conditional "where recognition has been granted for a limited period" says the same thing in a
   different way. **C-3** holds.
3. **C-6 is promoted from a lead to a standing rule, and C-3's Punjab row is simply wrong.**
   UP's *and* Punjab's Form-II both carry the MHRD model-form text found in Assam, Meghalaya and
   Tripura — **five states now**, word for word, including "not extendable" and nothing beyond Class
   VIII. **Always read a state's annexed recognition FORM before recording "no validity period."**
   Delhi, Himachal and J&K must be re-checked at form level; their C-3 entries are probably artefacts
   of reading only the rule body. And **C-3's "Punjab — 3 years" must be corrected**: it conflates the
   RTE Form-II term with PSEB *affiliation*, which has no term at all.
4. **A live ambiguity the product must not paper over.** Both UP and Punjab Form-II grant recognition
   "for a period of three years" **and in the same breath say the grant "is not extendable"** — and
   neither r.11 contains any renewal procedure. Software should **surface the Form-II date range as
   recorded on the certificate** and must **not** compute an expiry and assert that a school has lost
   recognition. Nor should the 3-year figure ever be applied to board affiliation or to classes above
   VIII, where it does not exist.
5. **Punjab supplies the first TC-withholding gate that survives scrutiny** — PSEB clause 12(ੳ): no
   Board migration certificate, no transfer certificate. It is **procedural, not fee-based**, and so
   is untouched by the C-11 no-dues jurisprudence. Model it as a **board-layer prerequisite**. Punjab
   clause 14(ਅ) also adds an inbound out-of-state **countersignature + equivalence certificate**
   requirement, which slots directly into the C-12 table.
6. **C-7: the recognition Code Number is confirmed in a fifth state (UP).** Model it as a first-class
   identifier, separate from the order number, UDISE+ code and affiliation number.
7. **C-12 gains its fifth independent confirmation.** UP r.23 *requires* private schools to print the
   recognition registration number on the elementary-completion certificate. Printed verifiable
   identifiers really are the direction of travel; build the QR/identifier path, not a
   countersignature workflow.
8. **The Goa C-1 shape is not peculiar to Goa.** UP has the identical structure: a general state
   recognition instrument and an RTE s.18 instrument, each naming its own officer, both current,
   neither repealing the other. Treat "two instruments, two officers" as the **expected** pattern for
   a state rather than as a contradiction to be resolved away — and resolve it by *scope*, not by
   picking a winner.
9. **C-10's third row can be closed, and C-11 sharpened.** The TNER entry — *"half right, and
   unresolved … rule 40 exists in two circulating, opposite texts"* — resolves: rule 40 **forbids**
   refusing a TC over arrears, and the withholding text was never located under that number. C-11's
   framing (*"the rule stands and is unenforceable"*) survives, but for Tamil Nadu it should be
   restated: **the enabling rule was never there** — what existed was a *fee-entry field on the TC
   form*, and that is precisely what the 2024 Division Bench struck. The corpus should also record
   that the court's directed amendment appears **never to have been made**, and that a 2025 case
   shows schools still asserting the practice.
10. **Goa r.42(1)** — lapse on shifting premises or transfer of management — is a fifth independent
   confirmation of **C-4**. An expiry date alone still cannot model eligibility.
11. **Arunachal s.2(6)**, **UP's level-dependent committee chair**, and **UP r.2(1)(j)** are three
   more instances of **C-5**. "Recognition authority" is a function of state × class band ×
   *notification*, and in Arunachal it is not knowable from the statute at all.
12. **Arunachal's Act delegates the whole TC regime to Rules we do not hold.** Where a state's Act is
    silent, the product must fall back to the central RTE Act and the board layer — it must never
    infer a state rule from neighbouring states.
13. **Do not treat this run's absences as settled.** The Indian Kanoon false negative in §0 is the
    reason: the "not found" results above were produced by tools that are provably capable of
    missing an instrument that exists.
