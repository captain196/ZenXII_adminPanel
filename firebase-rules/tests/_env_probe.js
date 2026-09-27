/**
 * Reproduce the CAL-D1 suite's beforeAll in isolation and print the RAW error.
 *
 * Jest was reporting only the downstream "env is undefined", which hides why
 * initializeTestEnvironment actually failed. This does the same call with no
 * test framework in the way.
 */
const fs = require('fs');
const path = require('path');
const { initializeTestEnvironment } = require('@firebase/rules-unit-testing');

const RULES = process.env.CAL_D1_RULES
  ? path.resolve(process.env.CAL_D1_RULES)
  : path.resolve(__dirname, '../firestore.rules');

(async () => {
  console.log('rules :', RULES);
  console.log('bytes :', fs.statSync(RULES).size);
  try {
    const env = await initializeTestEnvironment({
      projectId: 'zenxii-rules-test',
      firestore: {
        rules: fs.readFileSync(RULES, 'utf8'),
        host: '127.0.0.1',
        port: 18080,
      },
    });
    console.log('initializeTestEnvironment: OK');
    await env.withSecurityRulesDisabled(async (ctx) => {
      await ctx.firestore().collection('calendarEvents').doc('probe').set({ ok: true });
    });
    console.log('seed write: OK');
    await env.cleanup();
    console.log('RESULT: environment usable');
    process.exit(0);
  } catch (e) {
    console.log('\nRAW FAILURE');
    console.log('  name    :', e && e.name);
    console.log('  message :', e && e.message);
    if (e && e.cause) console.log('  cause   :', e.cause && (e.cause.message || e.cause));
    if (e && e.stack) console.log('  stack   :', String(e.stack).split('\n').slice(0, 6).join('\n'));
    process.exit(1);
  }
})();
