#!/usr/bin/env node
/**
 * Apply the R-X1 rules patch to firestore.rules.
 *
 * RUN THIS ONLY AFTER `git pull` IN firebase-rules. The rules file is edited
 * concurrently by other sessions and a deploy ships the whole file, so applying
 * a patch to a stale copy would ship somebody else's half-finished work.
 *
 *   node patches/apply_rx1.js            # apply
 *   node patches/apply_rx1.js --check    # report only, change nothing
 *
 * Then ALWAYS:
 *   git diff firestore.rules             # must show only this one block
 *   cd tests && npx firebase emulators:exec --only firestore \
 *     --project=zenxii-rules-test "npx jest regularization_refile --runInBand --forceExit"
 *
 * Refuses to run twice, and refuses when the anchor has moved.
 */
const fs = require('fs');
const path = require('path');

const patch = require('./rx1_regularization_refile');

const RULES = path.join(__dirname, '..', 'firestore.rules');
const checkOnly = process.argv.includes('--check');

const before = fs.readFileSync(RULES, 'utf8');

if (before.includes("resource.data.status in ['pending', 'rejected']")) {
  console.log('R-X1 is already applied to firestore.rules — nothing to do.');
  process.exit(0);
}

let after;
try {
  after = patch.apply(before);
} catch (e) {
  console.error('REFUSED: ' + e.message);
  process.exit(1);
}

// Only the regularization block may move. Anything else means the anchor
// matched somewhere unintended, and a rules deploy is not the place to find out.
const removed = before.split('\n').filter((l) => !after.includes(l));
const added = after.split('\n').filter((l) => !before.includes(l));

console.log(`lines removed: ${removed.length}, lines added: ${added.length}`);

if (checkOnly) {
  console.log('\n--- would add ---\n' + patch.NEW + '\n');
  console.log('--check: no file was written.');
  process.exit(0);
}

fs.writeFileSync(RULES, after);
console.log('R-X1 applied to firestore.rules.');
console.log('NOW: git diff firestore.rules  — confirm ONLY the attendanceRegularizations block changed.');
console.log('THEN: run the rules tests before deploying.');
