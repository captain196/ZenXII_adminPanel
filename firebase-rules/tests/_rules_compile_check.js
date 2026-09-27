/**
 * Does firestore.rules actually COMPILE?
 *
 * The CAL-D1 suite began failing 26/26 with ECONNRESET while loading rules.
 * The emulator itself starts fine, so the suspicion is the shared
 * firestore.rules — a file CLAUDE.md warns is edited concurrently by other
 * sessions. This asks the emulator to compile it and prints the real error
 * instead of a connection reset.
 *
 * Read-only: it loads rules into a throwaway emulator project and writes
 * nothing to the repository.
 */
const fs = require('fs');
const path = require('path');

const RULES = process.env.CAL_D1_RULES
  ? path.resolve(process.env.CAL_D1_RULES)
  : path.resolve(__dirname, '../firestore.rules');

const PROJECT = 'zenxii-rules-test';
const HOST = '127.0.0.1';
const PORT = 18080;

(async () => {
  const src = fs.readFileSync(RULES, 'utf8');
  console.log('rules file :', RULES);
  console.log('bytes      :', src.length);
  console.log('lines      :', src.split('\n').length);

  const url = `http://${HOST}:${PORT}/emulator/v1/projects/${PROJECT}:securityRules`;
  let res;
  try {
    res = await fetch(url, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rules: { files: [{ name: 'firestore.rules', content: src }] } }),
    });
  } catch (e) {
    console.log('\nTRANSPORT FAILURE:', e.message);
    process.exit(2);
  }

  const body = await res.text();
  if (res.status === 200) {
    console.log('\nCOMPILES: yes (emulator accepted the ruleset)');
    process.exit(0);
  }

  console.log('\nCOMPILE FAILED  HTTP', res.status);
  try {
    const j = JSON.parse(body);
    const issues = (j.error && j.error.details) || j.issues || [];
    if (Array.isArray(issues) && issues.length) {
      issues.forEach((i) => console.log('  -', JSON.stringify(i)));
    } else {
      console.log(body.slice(0, 3000));
    }
  } catch (_) {
    console.log(body.slice(0, 3000));
  }
  process.exit(1);
})();
