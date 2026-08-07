# Fees-v2 Phase-2 — Concession Attribution Follow-up (Technical Debt)

> **Status:** DOCUMENTED — NOT IMPLEMENTED. Owned by the dedicated fees-v2 window.
> **Created:** 2026-06-09 · **Scope:** fees-v2 code only (`Fee_generation_service.php`, `Fee_firestore_txn.php`, `Fees.php`, `Fee_lifecycle.php`). Flags file `application/config/fees_exemption_v2_flags.php` is operator-owned — never bundle.
> **Discipline:** explicit-path staging only, separate commit `fix(fees-v2): …`, no push without authorization.

## Background / root cause

The unified concession engine reduces `netAmount` correctly but does **not** record the
attribution: committed concession demands carry `discountAmount = 0` and drop
`concessionApplied`. Financially the dues are correct everywhere (every dues/balance/
defaulter/late-fee/collection reader uses `netAmount`/`balance`, **no consumer derives
`gross − discount`**). The gap is **discount attribution / audit / reporting**, not money owed.

Engine path (only place concessions run):
`Fee_lifecycle::assignInitialFees` → `Fee_generation_service::generateDemandsForStudent`
→ `commitDemandBatch` / `writeDemand` → `Fee_firestore_txn::_buildDemandDoc`.

Severity: **LOW–MEDIUM** (attribution/reporting only; no wrong dues).

---

## Item 1 — Concession attribution patch (the core fix)

**Symptom:** committed concession demand is internally inconsistent —
`grossAmount=300, netAmount=150 (correct), discountAmount=0, concessionApplied=absent`.

**Two surgical edits:**

1. `application/libraries/Fee_generation_service.php` (~L108-117, inside the
   `abs($net - $gross) > 0.0001` block) — persist the deduction into `discountAmount`:
   ```php
   $data['netAmount']         = $net;
   $data['concessionApplied'] = round($gross - $net, 2);
   $data['discountAmount']    = round($gross - $net, 2); // NEW: restores gross − discount == net
   ```

2. `application/libraries/Fee_firestore_txn.php::_buildDemandDoc` (~L895-898) — carry the
   audit field into the stored schema (covers both `writeDemand` + `commitDemandBatch`):
   ```php
   'concessionApplied' => (float) $pick($data, ['concessionApplied'], 0), // NEW: per-demand audit trail
   ```

**Why sufficient / safe:**
- `discountAmount` already aliases to `discount_amount` in `normalizeDemandDoc` (L687) →
  snake_case readers (collection report, rollup) self-correct, no reader changes.
- `concessionApplied` needs no alias (no snake reader consumes it; `normalizeDemandDoc`
  passes unknown keys through verbatim).
- No reader asserts absence of either field (all use `?? 0`); `concessionApplied` is read
  only by `Fee_generation_ab_verify.php`.
- Additive + re-run safe (both fields structural, recomputed each generation; payment-state
  fields stay conditionally emitted per BUG-075).

**Expected visible change (intended):** discount columns/rollups that *sum* `discount_amount`
will start reflecting concessions (previously 0): `Fees.php:4018` (Due Fees Table),
`Fee_firestore_sync::syncDemandsForMonth` (`studentFeeSummary.discountAmount`), parent-app
per-demand discount display. `dueFee`/balance unchanged.

---

## Item 2 — Manual regeneration scope gap

**Path:** `Fees::save_updated_fees` → `_auto_generate_student_demands`
(`application/controllers/Fees.php:1480`, writeDemand at `:1512`).

**Gap:** writes `gross=net`, `discountAmount=0`, and **bypasses `Fee_generation_service`
entirely** — concessions are silently NOT applied when an operator regenerates a chart here.
Goes through `_buildDemandDoc` (so Item 1's `concessionApplied` field is emitted as 0), but the
engine never runs.

**Decision needed:** route manual regen through the concession engine, or document that manual
chart regen does not honor concessions (re-run admission/`assignInitialFees` to re-apply).

---

## Item 3 — Module-fee scope gap

**Path:** `Fee_lifecycle::createModuleFee` (`application/libraries/Fee_lifecycle.php:649`,
writeDemand at `:665`) — called by Hostel (`Hostel.php:351`) and Transport
(`Transport.php:420/429`).

**Gap:** module fees set `gross=net`, never consult concessions. A concession scoped to
`all` would not reach Hostel/Transport demands. Pre-existing; invariant holds trivially
(`gross=net`, `discount=0`).

**Decision needed:** whether module fees are in-scope for concessions. If yes, run them
through the concession reader before `createModuleFee` writes.

---

## Item 4 — `recalculate_demands` interaction

**Path:** `Fees::recalculate_demands` (`application/controllers/Fees.php:6920`; recompute at
`:6981-6994` → `updateDemand` at `:7016`).

**Risk:** recomputes `discount_amount`/`net_amount` from the **legacy `studentDiscounts` map
only**. Running it on a concession student **overwrites** the (post-Item-1) `discountAmount`
with the legacy value and resets `net` — wiping the concession attribution AND its net
reduction. (Net already loses the concession here today; Item 1 makes the attribution drift
visible.)

**Decision needed:** teach `recalculate_demands` to compose with concessions, or guard/skip
concession demands, or document "re-run `assignInitialFees` after recalculation."

---

## Item 5 — `proRateFees` interaction

**Path:** `Fee_lifecycle::proRateFees` (`application/libraries/Fee_lifecycle.php:516`;
`updateDemand` at `:543`).

**Risk:** sets `netAmount = grossAmount × factor` and leaves `discountAmount` stale →
**breaks the `gross − discount == net` invariant** Item 1 establishes, and discards the
concession reduction for mid-year joiners. Pre-existing for `net`.

**Decision needed:** prorate `net` *after* applying the concession (prorate the concessioned
net), and recompute `discountAmount = gross − net`; or document the interaction.

---

## Consolidated verification plan (for when Item 1 is implemented)

1. Commit-path probe: concession demand stores `gross=300, discount=150, net=150,
   concessionApplied=150`, `gross − discount == net`.
2. Build-only 52-assertion suite stays green.
3. Non-concession demand byte-identical (`discountAmount=0`, `concessionApplied=0`).
4. Admin Due Fees Table + `studentFeeSummary` rollup reflect the concession; `dueFee`
   unchanged.
5. Idempotency: re-run `assignInitialFees` via promote/reverse → `concessionApplied` stable,
   paid/balance/status preserved.
6. Interaction probes (Items 4 + 5): run `recalculate_demands` and `proRateFees` on a
   concession student; confirm documented behavior; decide accept vs. fix.
7. Parent app: per-demand discount display consistent with net.
8. Manual-regen scope (Item 2): confirm/handle.

## Commit guidance

- Item 1 → its own commit `fix(fees-v2): persist concession into discountAmount + concessionApplied`.
- Items 2–5 → separate scoped commits or a tracked decision per item.
- Never bundle with the flags file or with SC/Exam work.
