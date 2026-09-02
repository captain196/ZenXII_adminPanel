<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ── PHASE 20.3h — ATOMIC SECTION CAPACITY (P20.2-RACE-1) ────────────────────
 *
 * Master switch for the atomic destination-section capacity guard.
 *
 * THE DEFECT IT GATES: every seat-consuming placement path checks capacity and
 * then writes the student, with nothing in between. Two operators can both read
 * 39/40, both pass, and both write — final occupancy 41/40.
 *
 * When enabled, each placement commits the student write(s) AND an opaque CAS
 * marker on the destination section in ONE Firestore commit, guarded by
 * `currentDocument.updateTime`. Firestore admits exactly one of two competing
 * commits that captured the same updateTime; the loser's batch rolls back
 * entirely (proven on live Firestore in Phase 20.3g: two in-flight writers,
 * winners=1, losers=1, rejection `9 FAILED_PRECONDITION: the stored version …
 * does not match the required base version`).
 *
 * ── ALL-OR-NOTHING ──────────────────────────────────────────────────────────
 * This flag is deliberately a single switch for ALL FIVE seat-consuming paths
 * (admission, enroll, import-execute, promotion, transfer). There is no
 * per-path flag, because a state where some paths are guarded and others are
 * not is worse than none being guarded: the code reads as protected while a
 * bypass remains. Phase 20.3c was reverted for exactly that reason.
 *
 *   false → every path behaves EXACTLY as it did before this feature.
 *   true  → every path routes through the capacity authority.
 *
 * ── DO NOT ENABLE UNTIL ─────────────────────────────────────────────────────
 * all five paths implemented · automated tests pass at the documented baseline ·
 * static bypass guard passes · identity/SRNO audit passes · live Firestore
 * cases A–I executed on the testing tenant · bulk import and promotion verified ·
 * transfer verified · no partial commit observed.
 *
 * Occupancy is NEVER read from the marker. It continues to derive solely from
 * student documents via MY_Controller::_filterSectionRoster().
 */
$config['atomic_capacity_enabled'] = true;

/**
 * Maximum student writes in ONE destination-section group.
 *
 * Firestore's atomic commit limit is 500 writes. A group is N student writes
 * plus exactly 1 section CAS marker, so N is capped at 499.
 *
 * A larger group is REFUSED OUTRIGHT — never chunked. Splitting would break the
 * guarantee: the first chunk's commit moves the section's updateTime, so the
 * second chunk's captured precondition is stale and a competing writer could
 * interleave between them. One capacity decision must equal one atomic commit.
 */
$config['capacity_group_max_students'] = 499;

/*
| Maximum writes in ONE atomic placement commit. Firestore accepts 500, which
| is the default. A rollover needing more REFUSES — it is never split, because
| a split is the partial rollover D21 forbids; the operator stages it with
| class_from/class_to instead. Lower it only to exercise that refusal in a
| test environment.
*/
$config['capacity_commit_max_writes'] = 500;
