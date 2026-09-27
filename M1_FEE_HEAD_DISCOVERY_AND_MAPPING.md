# M1 — FEE HEAD DISCOVERY AND MAPPING PROPOSAL

**READ-ONLY. ZERO registry records minted. ZERO documents written.**
**STOPPED at M1 per §24 — 1 head cannot be classified authoritatively.**
2026-08-17 · `scripts/m1_fee_head_discovery.php`

---

## A. Executive summary

M1 discovery is complete. **12 of 14 heads have an unambiguous proposed classification.** One
runtime-only head — **ID-card replacement** — cannot be registered as designed, because the module
mints an **unbounded family of head names** (one per card version) *and* that name is **load-bearing
for demand identity**. That is a genuine architectural conflict with the approved design, not a
detail, so I stopped before minting anything.

No production code was written. No registry exists. No flag was introduced.

## B. Discovery results (§11 items 1–11)

| # | Item | Result |
| --- | --- | --- |
| 1–2 | heads / names in active charts | 7 charts, **28 head rows**, 6 distinct names across 2 schools |
| 3 | existing `feeHeadId` values | **28 distinct** |
| 4 | missing `feeHeadId` | **0 in pricing charts** |
| 5 | runtime-only module heads | **3** — Transport, Hostel, ID-card (§D) |
| 6 | head names in demands | 6 + `(no feeHead)` on 98 |
| 7 | existing categories | `(empty)` 1,190 · `Academic` 24 · `Transport` 24 · `General` 4 |
| 8 | session structures | both schools, `2026-27` only |
| 9 | identity/name conflicts | 4 names carry 6 row-ids each — expected, all collapse to one registry identity |
| 10 | duplicate normalised names | **0** |
| 11 | archived/legacy conditions | no registry, no `status` concept anywhere; 1,190 legacy `category=''` |

**One correction to my earlier audit.** I previously reported "36 rows, 8 without `feeHeadId`". Scoped
correctly to *pricing charts* the figures are **28 rows, 0 without an id** — the 8 id-less rows were
all inside the two `_titles` registry docs, which are not pricing rows. The registry design is
unaffected (the `_titles` docs are retired at M7 either way), but the mapping numbers below are the
accurate ones.

## C. Proposed mapping — **PROPOSAL ONLY, awaiting your confirmation**

Nothing here is authoritative until you confirm it. The name was used **only** to generate the
proposal; after confirmation the registry is the authority and names are never consulted at runtime.

| School | Head | Proposed | Source | Evidence |
| --- | --- | --- | --- | --- |
| SCH_218AAF5C23 | Tuition Fee | `academic` | chart | 6 rows / 6 row-ids |
| SCH_218AAF5C23 | Examination Fee | `academic` | chart | 6 rows / 6 row-ids |
| SCH_218AAF5C23 | Registration Fee | `academic` | chart | 6 rows / 6 row-ids |
| SCH_218AAF5C23 | Transport Fee | `transport` | chart | 6 rows / 6 row-ids |
| SCH_B56BB9A401 | Tuition Fee | `academic` | chart | 1 row |
| SCH_B56BB9A401 | Admission Fee | `academic` | chart | 1 row |
| SCH_B56BB9A401 | Library Fee | `academic` | chart | 1 row |
| SCH_B56BB9A401 | Transport Fee | `transport` | chart | 1 row |
| both | Hostel Fee | `hostel` | **runtime** | `Hostel.php:360` passes no `fee_head` → default `"{moduleType} Fee"` |
| both | Transport Fee | `transport` | **runtime** | same head as the chart row — one identity, not two |
| both | **ID Card Replacement (Card v{N})** | **BLOCKED** | **runtime** | see §D |

The classifier was written **conservatively**: anything not positively recognised returns *ambiguous*
rather than being guessed into `academic`. Nothing fell through — the only unresolved head is the
structural one below.

## D. BLOCKER — ID-card replacement heads

**What was found.** `Id_card_fee_policy::feeHeadFor()` builds the head name dynamically:

```php
private string $head = 'ID Card Replacement';
public function feeHeadFor(int $cardVersion): string {
    return $this->head . ' (Card v' . $cardVersion . ')';
}
```

So the head names are `ID Card Replacement (Card v2)`, `(Card v3)`, `(Card v4)` … — **unbounded, one
per card version.**

**Why it blocks.** The approved design requires every module fee to resolve a registered head and to
**fail closed otherwise** (invariant 13/14, §10 of your brief). An unbounded name family cannot be
pre-registered, so under the new rule the *second* replacement for any student would fail closed in
production.

**And the obvious fix is not safe without a decision.** Collapsing all versions to one head
`ID Card Replacement` would break demand uniqueness, because the version is currently the **only**
thing that distinguishes two replacements in the same period:

```php
// Fee_lifecycle::createModuleFee
$demandId = $this->_demandId($studentId, $periodKey, '', $headName . '_' . $moduleType);
// _demandId: feeHeadId is '' here, so the key is derived from the NAME:
//   "ID Card Replacement (Card v2)_IDCard" → DEM_{stu}_{YYYYMM}_ID_CARD_REPLACEMENT_CARD_V2_IDCARD
//   "…(Card v3)…"                          → DEM_{stu}_{YYYYMM}_ID_CARD_REPLACEMENT_CARD_V3_IDCARD
```

Remove the version from the name and both replacements in one month collapse onto **one `demandId`** —
the second charge merges into the first and **a legitimate fee is silently lost.** That is precisely
the class of silent monetary error this whole programme exists to prevent.

**Mitigating fact, verified:** **there are zero existing ID-card demands** in live data (the only head
names present are the 6 chart heads plus 98 with no head). There is likewise **no live Hostel demand**.
So whatever is decided has **no historical impact** — it only shapes future behaviour.

**What is required — your decision, not mine:**

- **(a) One registry head, version moved out of identity.** `ID Card Replacement` →
  `administrative`, and `_demandId` gains an **explicit** uniqueness component (the card version) so
  demand identity no longer rides on a display string. Architecturally correct and consistent with the
  design's central premise. **But it changes the composition of a financial demand id** — beyond the
  approved design, so it needs explicit authorisation. Risk is currently zero-impact (no such demands
  exist), and it should be settled *before* the first one is ever raised.
- **(b) Register a head per card version.** Unbounded and unworkable.
- **(c) Exempt module heads from the registry.** Violates invariants 13 and 14 — reopens the anonymous
  runtime head. Not recommended.

I recommend **(a)**, but I have implemented nothing and will not choose for you.

## E. What was NOT done, deliberately

Per §24 ("do not work around the blocker silently") I stopped **before** writing any production code.
Building `Fee_head_resolver` now would mean encoding an unresolved rule about how ID-card fees
resolve — the resolver's fail-closed contract is exactly what the blocker is about. So:

**No** `Fee_head_resolver` · **no** vocabulary constants · **no** `USE_FEE_HEAD_REGISTRY` flag ·
**no** registry documents · **no** name claims · **no** `feeHeadRef` on any chart row · **no** change
to `Fee_lifecycle` / `Fee_generation_service` / `Fee_firestore_txn` / `Fees.php` / `Hostel.php` /
`Id_card_fee_policy` · **no** tests added (they would encode the same unresolved rule) · **no** rules
or index work (correctly sequenced after M2 anyway).

## F. Report checklist (§23)

| Item | State |
| --- | --- |
| Files changed | **1 added**: `scripts/m1_fee_head_discovery.php` (read-only). Plus this report. |
| Production code changed | **None** |
| Schema / rules / index changes | **None** |
| Tests added / passed / failed | **None added** — see §E. Baseline untouched (4 failures + 27 skipped, unchanged and not run against modified code because none exists) |
| Feature flag state | `USE_FEE_HEAD_REGISTRY` **not created** · `USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` |
| Live data touched | **None** — 0 writes, 0 deletes |
| Migration state | **M1 complete. M2 not started. M3–M7 not started.** |
| Rollback state | Nothing to roll back — no writes occurred |
| Cross-platform result | Unchanged from the verified finding: Parent app is category-first with a name fallback → compatible, no app change; Teacher app has no coupling |
| SRNO | Untouched. Still school-scoped, session-independent. SRNO-F2/F3 unimplemented |
| N+1 / B3 / scholarship | Untouched, still registered and sequenced after this work |
| Git | No commit, push, deploy, merge or rebase |

## G. Remaining risks

1. **The ID-card blocker (§D)** — must be decided before any module-fee path is wired to fail closed.
2. **Runtime-only heads have never billed.** Hostel and ID-card have zero live demands, so their
   integration will be exercised for the first time *after* cutover. They need explicit test coverage
   (T26–T29) rather than reliance on production traffic.
3. **Single session.** Only `2026-27` exists, so rollover identity (T5/T25) is test-provable but not
   observable in live data.
4. **Operator mapping quality** — 12 confirmations set the financial rule for everything downstream.

---

## EXACT NEXT AUTHORISATION REQUIRED

1. **Decide the ID-card blocker** — (a), (b) or (c) from §D. If (a), authorise the `_demandId`
   uniqueness-component change explicitly, since it alters financial demand-id composition.
2. **Confirm or correct the 12 proposed classifications** in §C (M2). Nothing is minted until every
   required head has an authoritative category.

Once both are settled I can proceed to step 1 of the sequence — `Fee_head_resolver` + vocabulary
constants, unwired and unit-tested — and then M3.

**Nothing further will be implemented until you rule on §D.**
