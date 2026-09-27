#!/usr/bin/env node
/**
 * Test probe — executes the REAL migration mapper.
 *
 * Phase2EntitlementSemanticsTest verifies F1 against the actual
 * `scripts/migrate_student_entitlements.js::mapScope()` rather than a PHP
 * transcription of it, so a future change to the migration cannot drift away
 * from the billing behaviour the test pins.
 *
 * It lives in a file rather than being passed via `node -e` because PHP's
 * escapeshellarg() strips embedded double quotes on Windows, which mangles any
 * inline JS program.
 *
 * Usage:  node tests/Support/map_scope_probe.js <base64-json-legacy-doc>
 * Output: the mapped scope as JSON ("head:Lab Fee") or the literal null.
 *
 * READ-ONLY: requiring the migration module has no side effects — its Firebase
 * bootstrap and its main() are behind a `require.main === module` guard.
 */
const path = require('path');
const m = require(path.join(__dirname, '..', '..', 'scripts', 'migrate_student_entitlements.js'));

try {
  const raw = Buffer.from(process.argv[2] || '', 'base64').toString('utf8');
  process.stdout.write(JSON.stringify(m.mapScope(JSON.parse(raw))));
} catch (e) {
  process.stdout.write(JSON.stringify({ probeError: String((e && e.message) || e) }));
  process.exit(2);
}
