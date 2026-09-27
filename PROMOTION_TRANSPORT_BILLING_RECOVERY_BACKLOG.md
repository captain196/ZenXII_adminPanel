# Phase A+1 Backlog — Promotion → Transport Billing Recovery

**Status:** NOT STARTED. Not authorized to implement. First task after Phase A closes.
**Severity:** High (silent revenue loss, not a display bug).
**Origin:** discovered during Phase A's G1/G2 adversarial re-audit (2026-08-08), while stress-testing the
"promotion" scenario against the actual promotion code path. Documented here per explicit instruction rather
than left as a loose finding.

## The evidence (already gathered — starting point for the audit, not a substitute for it)

`Fee_lifecycle::reassignFeesOnPromotion()` ([Fee_lifecycle.php:415](application/libraries/Fee_lifecycle.php#L415)) archives every **unpaid** demand whose stored `className`/`section` doesn't match the student's new class/section, then calls `assignInitialFees()` to regenerate — but `assignInitialFees()` is confirmed (grepped its full body, zero hits) to be tuition-fee-chart-only. It has no concept of module fees.

`Fee_lifecycle::createModuleFee()` — the function Transport's `assignStudent()`/`changeRoute()`/etc. all call — stamps `className`/`section` onto the demand doc from the student's record **at creation time** ([Fee_lifecycle.php:802-804](application/libraries/Fee_lifecycle.php#L802)). Those fields go stale the moment the student is promoted.

Consequence, traced end to end: a Transport-assigned student gets promoted → their (usually still-unpaid-for-the-remaining-months) Transport demand's stored class no longer matches → `reassignFeesOnPromotion`'s archive-loop doesn't skip it → it gets archived → nothing regenerates it, because the regeneration step doesn't know Transport exists → the student keeps their bus seat (their `studentRoutes` assignment doc is untouched by promotion) but stops being billed for it, silently, until some unrelated Transport action (route change, suspend/resume, subscription change, or a manual reconciler run) happens to touch the record again.

This is **not** something Phase A's G1/G2 fix touches or worsens — `reassignFeesOnPromotion`'s archive logic is generic (applies to any demand with a stale className, tuition or module), pre-dates this session, and lives in a shared, heavily-used promotion path (`Sis::execute_promotion`) that hasn't been audited this session.

## Required audit scope (per operator instruction)

1. **Audit the promotion flow end to end** — `Sis::execute_promotion()` and everything it calls, not just `reassignFeesOnPromotion()`. Confirm the exact trigger condition (promotion always fires this archive loop? only cross-session? only certain promotion types — regular vs. repeat vs. transfer-in?).
2. **Ensure active transport assignments survive promotion.** The `studentRoutes` doc itself already survives (promotion never touches it) — confirm this stays true, and confirm there's no OTHER promotion code path that does touch it.
3. **Ensure transport fee demands regenerate correctly post-promotion.** The fix needs a mechanism-level decision, not a patch: candidates to evaluate (not pre-select) —
   - Exclude module-fee demands (`category` != the tuition-chart's own category marker) from `reassignFeesOnPromotion`'s archive-by-classname loop entirely, since module fees aren't class-bound in the same sense tuition is.
   - Or: have `Sis::execute_promotion()` call something like `Transport_service::refreshFeeOnPromotion($studentId)` (new, narrow) after `reassignFeesOnPromotion()` runs, to re-stamp className/section and regenerate if archived — mirroring the pattern `resumeAssignment()` already uses.
   - Or: stop stamping className/section on module-fee demands at all (they're not read by any class-scoped report per the case-sensitivity/periodType investigation already done in Phase A — needs re-verification here specifically for className/section, not just category/frequency/periodType).
4. **Verify accounting.** Does an archived-then-never-regenerated Transport demand distort any ledger/journal entry, defaulter aging, or revenue report? Check `Operations_accounting.php`'s consumption of `feeDemands.status`.
5. **Verify defaulters.** `reassignFeesOnPromotion()` already calls `Fee_defaulter_check::updateDefaulterStatus()` post-archive — confirm a promoted, now-unbilled-for-Transport student doesn't either wrongly show as a defaulter (they have no outstanding demand to be in default on) or wrongly disappear from a report that should still flag them as owing for the period before promotion.
6. **Verify reports.** Any Transport revenue/collection report that reads `feeDemands` by category+status needs checking against demands that silently went `archived` mid-year for a still-actively-riding student.
7. **Verify Parent App / Teacher App.** Confirm neither app surfaces a Transport-fee balance/history view that would show stale or missing data as a result of the archive. (Both apps read Firestore directly per the platform's Firestore-only architecture — check `firestore.rules`-exposed collections and any cached aggregate the apps read, not just the admin panel.)
8. **Verify future Student Entitlements compatibility.** The frozen baseline's `Fee_entitlement_reader`/`Entitlement_service` model is the eventual replacement for this exact class of gap (entitlements persist across promotion by design, per the baseline's data model). Confirm whatever fix ships here doesn't need to be redesigned when Student Entitlements lands — prefer a fix that's naturally subsumed by the entitlement model over one that adds new promotion-specific special-casing that would need to be unwound later.

## Do not

- Do not patch `reassignFeesOnPromotion()`'s archive-loop condition without first tracing every OTHER demand category that flows through it (tuition, Hostel, any other `createModuleFee` consumer) — a fix scoped only to `'transport'` that misses `'hostel'` reintroduces the exact same bug one category later.
- Do not implement without a companion regression test reproducing the bug pre-fix and proving it closed post-fix, per this project's standing release-gate discipline.
