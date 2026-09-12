# Compliance research — state, central and board

Six parallel research streams, commissioned 2026-09-08, covering every state and union
territory, every board, every school type and every document type.

| file | scope |
|---|---|
| `north-india.md` | J&K · Ladakh · HP · Punjab · Haryana · Delhi · Uttarakhand · UP · Rajasthan · Chandigarh |
| `west-central-india.md` | Maharashtra · Gujarat · MP · Chhattisgarh · Goa · DNH&DD |
| `south-india.md` | Karnataka · Kerala · TN · AP · Telangana · Puducherry · Lakshadweep · A&N |
| `east-northeast-india.md` | WB · Bihar · Jharkhand · Odisha · Assam · Sikkim + the seven north-eastern states |
| `central-and-boards.md` | RTE · NEP · DPDP · IT Act · UDISE+ · APAAR · DigiLocker/NAD · CBSE · CISCE · NIOS · IB · Cambridge · KVS · NVS · Sainik |
| `school-and-document-types.md` | school types × document types × the statutory registers |
| `boards-west-south.md` | **the BOARD layer, west & south** — MSBSHSE · GSEB/GSHSEB · GBSHSE · KSEAB · Kerala (Pareeksha Bhavan + DHSE) · TN DGE · BSEAP + BIEAP · BSE Telangana + TSBIE *(added 2026-09-12)* |

`boards-west-south.md` answers the question the first wave named but did not research: **what does
the examination board itself impose on a school-issued TC?** Its headline is that the board is
almost never the author — **the STATE prescribes the TC; the BOARD supplies an identifier and owns
the migration/eligibility instruments.** It also **corrects two entries in `CONFLICTS.md`**
(see C-19 and C-20 there): the Tamil Nadu printed-identifier rule is **disputed, not established**,
and the Karnataka and Andhra printed-identifier mandates are **state/departmental rules, not board
rules**.

## The discipline every stream was given

Findings carry an **evidence level**, and a gap is recorded rather than filled:

| | meaning |
|---|---|
| **A** | the primary legal text was read directly |
| **B** | an official government portal or circular states it |
| **C** | a credible secondary source |
| **D** | unverified, or a single weak source |
| **NOT FOUND** | searched and not established — *a result, not a failure* |

This mirrors the rule the compliance corpus already enforces on itself, and the reason is
written into the product: *inventing a plausible-looking requirement would assert wrong law
confidently, which is worse than enforcing nothing.* Software that tells a school its
certificate satisfies a rule that does not exist is worse than software that says it does not
know.

## What this feeds

1. **`AUTHORITIES` in `designer.js`** — today it holds two entries (RTE Act 2009 and CBSE
   Annexure-I), both `evidence: "A"`, and the CBSE field list is flagged
   `fieldListVerified: false, illustrative: true`. Maharashtra, Karnataka and Uttar Pradesh
   have **no verified authority at all**, so schools there correctly resolve to the generic
   profile that enforces nothing.
2. **`Issuer_identity`** — the recognition model: validity in academic years, classes and
   sections covered, granting society, premises, and the 90-day renewal window.
3. **The per-state compliance profiles** the blueprint anticipated but never had the sources
   to write.

## One question above the others

Whether **CBSE has abolished countersignature by Board Regional Officers** on Transfer
Certificates. The corpus records `r.8(vii)` as current at Level A, `verifiedOn 2026-08-16`.
If it has been superseded, we would be enforcing a withdrawn requirement — the same error as
inventing one, and precisely what `verifiedOn` exists to catch.
