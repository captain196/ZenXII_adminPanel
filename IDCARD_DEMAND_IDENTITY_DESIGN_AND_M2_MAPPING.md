# 1 · ID-CARD DEMAND-IDENTITY DESIGN  ·  2 · FINAL M2 MAPPING

**DESIGN ONLY. `_demandId()` NOT CHANGED. NO REGISTRY MINTED. NO WRITES.**
2026-08-17 · evidence traced from source, no invented relationships

---

# 1. ID-card demand-identity design

## 1.1 The discriminator already exists, and it is atomic

The decisive evidence is in `Id_card_service::issue()`:

```php
$candidate = $prevVer + 1 + $i;
$claimed = (bool) $this->firebase->firestoreCreate(
    self::COL, $this->versionDocId($studentId, $candidate), [ 'version' => $candidate, … ]
);
if ($claimed) { $version = $candidate; break; }
…
if ($version === 0) return ['ok' => false, 'reason' => 'version_claim_exhausted'];
```

`versionDocId()` = `{studentId}_v{N}`, claimed with **create-if-not-exists** — the same atomic
primitive `Id_generator`, `srNoIndex` and `Sr_no_service` rely on. The class docblock states it
outright: *"a version is claimed with `firestoreCreate` — atomic … no two cards at the same
version."*

So `cardVersion` is a **per-student, atomically claimed, strictly monotonic, never-reused integer**,
and the fee is only raised *after* a successful claim (`$res['version']` → `_maybe_charge_id_card_
replacement`). **We do not need to invent a charge-instance identity — one already exists.** The
design's job is to stop smuggling it through a display string.

## 1.2 The twelve questions

| # | Question | Answer |
| --- | --- | --- |
| 1 | What identifies one replacement charge instance? | **`(studentId, cardVersion)`.** The version *is* the issuance event id |
| 2 | Two replacements for one student in one billing period? | **Yes, legitimately** — a card can be lost twice in a month. Both must be charged |
| 3 | Same card version twice? | **No.** Atomic `firestoreCreate` on `{studentId}_v{N}`; a collision probes upward; a number is never reused |
| 4 | Two different versions in one period? | **Yes** — that is exactly case 2, and it is the case today's name-based id happens to survive |
| 5 | Fields available at `createModuleFee()`? | `studentId`; `module` (`IDCard`); the payload (`fee_head`, `amount`, `frequency`, `dueDate`); and **`$cardVersion`, which is in scope at the call site** (`Sis.php`) but is currently passed *only* inside the head name |
| 6 | Which field becomes the explicit uniqueness component? | **`cardVersion`**, passed explicitly as a new optional `instanceKey` (e.g. `'V7'`), never again as part of the name |
| 7 | Why can two legitimate charges never collide? | Distinct versions (§1.1 atomicity) → distinct `instanceKey` → distinct `demandId`, regardless of student or period |
| 8 | Why can one legitimate charge never duplicate? | The id stays a **pure deterministic function**; a retry of the same issuance carries the same version → the same `demandId` → `createModuleFee`'s existing merge path (which already preserves `paidAmount`, FIN-1) updates rather than duplicates |
| 9 | Regeneration / recalculation? | The version is stored **on the demand** (`chargeInstance`), so any later pass re-derives the identical id from stored data — it never re-parses a name |
| 10 | Deterministic? | Yes — `f(studentId, periodKey, headKey, instanceKey)`, no clock, no random, no lookup |
| 11 | Existing demand ids compatible? | **Yes — see §1.4, this is the critical constraint** |
| 12 | ID-card demands needing migration? | **Zero.** Verified live: no `ID Card Replacement*` head appears in any of the 1,242 demands (and no Hostel demand either). **No migration required** |

## 1.3 Proposed change — minimal and confined

`Fee_lifecycle::_demandId()` gains an **optional trailing component**, appended **only when supplied**:

```
DEM_{student}_{YYYYMM}_{key}                     ← unchanged when no instance key
DEM_{student}_{YYYYMM}_{key}_{INSTANCE}          ← only when an instance key is passed
```

`createModuleFee()` gains an optional `instance` payload field. Only `Id_card_fee_policy` supplies it
(`'V' . $cardVersion`), and `feeHeadFor()` stops appending `(Card v{N})` so the head name becomes the
stable `ID Card Replacement` (the configured `IDCARD_FEE_HEAD` value already is exactly that).

## 1.4 The constraint that makes this safe — and a trap I want to name

**The `feeHeadRef` must never enter `_demandId()`.**

Today module fees call `_demandId($studentId, $periodKey, '', $headName . '_' . $moduleType)` — the
third argument is deliberately empty, so the key derives from the name. If the registry work were to
"helpfully" start passing the new `feeHeadRef` there, **every existing Transport demand id would
change**, and the next generation cycle would write a *second* demand alongside each existing one —
mass duplicate charges. That is a trap the registry work could walk into without noticing.

**Rule: `feeHeadRef` determines classification only. It must never participate in demand identity.**

With that rule, and because the instance component is appended only when supplied:

| Module | Today's id | After |
| --- | --- | --- |
| Transport | `DEM_{stu}_{YYYYMM}_TRANSPORT_FEE_TRANSPORT` | **byte-identical** |
| Hostel | `DEM_{stu}_{YYYYMM}_HOSTEL_FEE_HOSTEL` | **byte-identical** |
| ID-card | `DEM_{stu}_{YYYYMM}_ID_CARD_REPLACEMENT_CARD_V2_IDCARD` | `DEM_{stu}_{YYYYMM}_ID_CARD_REPLACEMENT_IDCARD_V2` |

Only ID-card ids change, and **zero ID-card demands exist**, so nothing in production is affected.

## 1.5 Identity separation held

```
Fee Head identity   "what type of charge is this?"   → ID Card Replacement → administrative   (ONE head)
Demand identity     "which charge instance is this?" → studentId + period + head + V{version}
```

No mutable display name participates in either. No head per version. No exemption from the registry.
No anonymous runtime head.

## 1.6 Tests this change requires (additions to the matrix)

T39 two replacements, same student, same period, different versions → **two distinct demands, both
charged** · T40 the same issuance retried → **one demand**, `paidAmount` preserved · T41 Transport and
Hostel demand ids are **byte-identical** before and after · T42 `feeHeadRef` never appears in any
`demandId` · T43 the version is stored on the demand and re-derives the same id on recalculation ·
T44 a head name change never alters an existing `demandId`.

---

# 2. FINAL M2 MAPPING

All 12 classifications are per your §3 confirmation. **Nothing is minted.** `feeHeadId` values are
minted at M3; the doc-id and claim shapes are shown so the write is fully specified in advance.

| # | School | Name | `nameNorm` (claim) | Category | Source | Doc id at M3 |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | SCH_218AAF5C23 | Tuition Fee | `tuition fee` | `academic` | chart | `{school}_{FH_new}` |
| 2 | SCH_218AAF5C23 | Examination Fee | `examination fee` | `academic` | chart | `{school}_{FH_new}` |
| 3 | SCH_218AAF5C23 | Registration Fee | `registration fee` | `academic` | chart | `{school}_{FH_new}` |
| 4 | SCH_218AAF5C23 | Transport Fee | `transport fee` | `transport` | **chart + runtime → ONE entry** | `{school}_{FH_new}` |
| 5 | SCH_218AAF5C23 | Hostel Fee | `hostel fee` | `hostel` | runtime only | `{school}_{FH_new}` |
| 6 | SCH_218AAF5C23 | ID Card Replacement | `id card replacement` | `administrative` | runtime only | `{school}_{FH_new}` |
| 7 | SCH_B56BB9A401 | Tuition Fee | `tuition fee` | `academic` | chart | `{school}_{FH_new}` |
| 8 | SCH_B56BB9A401 | Admission Fee | `admission fee` | `academic` | chart | `{school}_{FH_new}` |
| 9 | SCH_B56BB9A401 | Library Fee | `library fee` | `academic` | chart | `{school}_{FH_new}` |
| 10 | SCH_B56BB9A401 | Transport Fee | `transport fee` | `transport` | **chart + runtime → ONE entry** | `{school}_{FH_new}` |
| 11 | SCH_B56BB9A401 | Hostel Fee | `hostel fee` | `hostel` | runtime only | `{school}_{FH_new}` |
| 12 | SCH_B56BB9A401 | ID Card Replacement | `id card replacement` | `administrative` | runtime only | `{school}_{FH_new}` |

**12 registry entries + 12 name claims. No `meals` head — reserved and not created (§5).**

**Remaining ambiguity: NONE.** Every head required by a chart row or by a runtime module now has an
authoritative category.

**Two decisions embedded above that I want visible rather than buried:**

- **#4 and #10 collapse chart + runtime Transport into ONE identity per school**, per your §7 — they
  are the same financial concept, and two identities would defeat the purpose.
- **#5, #6, #11 and #12 pre-create heads that neither school has ever billed** (zero Hostel and zero
  ID-card demands exist). This is deliberate: after cutover an unregistered module head **fails
  closed**, so a school's first-ever hostel or ID-card charge would be refused if the head were not
  already registered. Pre-creating them is the safe choice; the alternative — creating on first use —
  would reintroduce runtime head creation, which invariant 13 forbids. They will sit `active` and
  unused until needed.

## 2.1 Notes carried into M3

- Names in this table are **display identity only**. After M3 the registry is authoritative and **no
  runtime path may classify by name** (T33).
- SCH_218AAF5C23 has no Library/Admission head; SCH_B56BB9A401 has no Examination/Registration head.
  That asymmetry is real, not a discovery gap.
- Head sets are per-school; identical names across schools are **separate identities** (T23).

---

## State

**Nothing implemented.** `_demandId()` unchanged · `createModuleFee()` unchanged ·
`Id_card_fee_policy` unchanged · no `Fee_head_resolver` · no vocabulary constants · no
`USE_FEE_HEAD_REGISTRY` flag · no registry documents · no name claims · no `feeHeadRef` on any chart
row · no `Fee_lifecycle` / `Fee_generation_service` / `Fee_firestore_txn` / `Fees.php` / `Hostel.php`
edit · no tests added · no rules or index work.

No scholarship, B3, N+1, SRNO-F2/F3 or backfill work. SRNO untouched and still school-scoped,
session-independent. `USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` · no RTDB ·
no commit, push, deploy, merge or rebase. Live data touched: **none**.

## Exact next authorisation required

1. **Approve the §1.3 change** — the optional `instanceKey` on `_demandId()`/`createModuleFee()`, the
   removal of `(Card v{N})` from `feeHeadFor()`, and the **§1.4 rule that `feeHeadRef` never enters
   demand identity**.
2. **Approve the §2 mapping** — including the two visible decisions (Transport collapse, pre-creating
   the four unused runtime heads).

On approval I proceed to sequence step 1: `Fee_head_resolver` + vocabulary constants, unwired and
unit-tested, then the ID-card identity change with T39–T44, then M3 minting.
