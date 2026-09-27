<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * RETIRED 2026-08-21 — THIS TOOL PRODUCED A FALSE "ALREADY CORRECT" VERDICT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Use `scripts/firestore_authz_graph.php` instead.
 *
 * ── WHY IT WAS WRONG ──────────────────────────────────────────────────────
 * It classified a read arm by the PRESENCE of predicates rather than their
 * STRUCTURE:
 *
 *     $usesStaff && ($usesSame || $usesStrict)   ->  "COMPOSED"  (i.e. safe)
 *
 * `exams` and `examSchedule` both contain `isStaff()` AND `isSameSchool()`, so
 * both were reported as already correct. Their actual arms are:
 *
 *     allow read: if isStaff()
 *                 || (isSameSchool() && resource.data.status in ['Published', 'Completed']);
 *
 * That is the SEC-4 short-circuit spelled out by hand. `||` means the first
 * disjunct decides on its own, and it never compares the document's school.
 *
 * MEASURED on the live ruleset by
 * `firebase-rules/tests/sec5_authz_graph_findings.test.js`: a teacher of School
 * A CAN read School B's exam and datesheet. The tool cleared two genuinely
 * exposed collections.
 *
 * This is the same "presence, not structure" error that produced the withdrawn
 * "45 read arms across 44 collections" figure — counting the fix as the defect
 * in one direction, and the defect as the fix in the other.
 *
 * ── WHAT REPLACES IT ──────────────────────────────────────────────────────
 * `scripts/firestore_authz_graph.php`, built on `scripts/lib/rules_parser.php`:
 *
 *   - inlines helpers transitively before classifying anything, so a defect
 *     hidden behind `isStaffOrOwnStudent()` is visible;
 *   - splits on TOP-LEVEL `||` after stripping redundant parentheses, so a
 *     short-circuiting disjunct is detected wherever it appears;
 *   - judges each operation separately (get/list/create/update/delete),
 *     because `resource` and `request.resource` availability differs;
 *   - resolves OR-semantics across duplicate arms to the WEAKEST boundary,
 *     which is what the engine actually enforces;
 *   - is itself adversarially tested by `tests/Unit/RulesParserAdversarialTest.php`
 *     against `tests/fixtures/rules/adversarial_parser.rules`.
 *
 * The file is kept rather than deleted so this failure stays discoverable: a
 * security tool that clears a vulnerable collection is worse than no tool,
 * because its output is quoted as evidence.
 */

fwrite(STDERR,
    "sec4_read_arm_census.php is RETIRED — it wrongly cleared `exams` and\n" .
    "`examSchedule` as already-correct by testing for the PRESENCE of scoping\n" .
    "predicates rather than their STRUCTURE. Both are exposed; proven on the\n" .
    "emulator by sec5_authz_graph_findings.test.js.\n\n" .
    "Use:  php scripts/firestore_authz_graph.php <RULES_FILE>\n"
);
exit(2);
