#!/usr/bin/env node
/**
 * Apply the FNF-6 settlement-visibility rules patch.
 *
 * RUN ONLY AFTER `git pull` IN firebase-rules. The file is edited concurrently
 * and a deploy ships all of it, so patching a stale copy would ship somebody
 * else's half-finished work.
 *
 *   node patches/apply_fnf_visibility.js            # apply
 *   node patches/apply_fnf_visibility.js --check    # report only
 *
 * Then ALWAYS:
 *   git diff firestore.rules
 *   cd tests && npx firebase emulators:exec --only firestore \
 *     --project=zenxii-rules-test "npx jest fnf_settlement_visibility --runInBand --forceExit"
 */
const fs = require('fs');
const path = require('path');
const patch = require('./fnf_settlement_visibility');

const RULES = path.join(__dirname, '..', 'firestore.rules');
const checkOnly = process.argv.includes('--check');

const before = fs.readFileSync(RULES, 'utf8');

let after;
try {
  after = patch.apply(before);
} catch (e) {
  console.error('REFUSED: ' + e.message);
  process.exit(1);
}

const added = after.split('\n').length - before.split('\n').length;
console.log(`lines added: ${added}`);

if (checkOnly) {
  console.log('\n--- would add ---\n' + patch.BLOCK);
  console.log('--check: no file was written.');
  process.exit(0);
}

fs.writeFileSync(RULES, after);
console.log('FNF-6 applied. NOW: git diff firestore.rules — confirm ONLY the staffSettlements block changed.');
