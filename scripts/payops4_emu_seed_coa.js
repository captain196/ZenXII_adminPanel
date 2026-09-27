/**
 * Minimal chart of accounts for the emulator fixture.
 *
 * Operations_accounting refuses to post a fee journal with "CoA not set up"
 * when `chartOfAccounts` is empty — correctly. Without this the E2E could
 * assert the receipt and the allocations but not the accounting leg, and an
 * unasserted leg is an unproven one.
 *
 * Codes are the ones the resolver actually reaches: 1010 is
 * resolveCashBankCode()'s default, 4010 is the fee-income fallback, and the
 * 40xx heads are its keyword map.
 */
const admin = require('firebase-admin');
const EMU = process.env.FIRESTORE_EMULATOR_HOST || '';
if (!['127.0.0.1', 'localhost', '::1'].includes(EMU.split(':')[0])) {
  console.error('REFUSING: FIRESTORE_EMULATOR_HOST must be loopback'); process.exit(1);
}
const SCHOOL = process.env.ZX_SCHOOL || 'SCH_EMUFIXTURE1';
admin.initializeApp({ projectId: 'graderadmin' });
const db = admin.firestore();

const ACCOUNTS = [
  ['1010', 'Cash in Hand',        'asset',   true ],
  ['1020', 'Bank Account',        'asset',   true ],
  ['1210', 'Fees Receivable',     'asset',   false],
  ['4010', 'Tuition Fee Income',  'income',  false],
  ['4020', 'Admission Fee Income','income',  false],
  ['4030', 'Examination Income',  'income',  false],
  ['4040', 'Transport Income',    'income',  false],
  ['4050', 'Hostel Income',       'income',  false],
  ['4060', 'Late Fee Income',     'income',  false],
  ['4140', 'ID Card Income',      'income',  false],
];

(async () => {
  const batch = db.batch();
  ACCOUNTS.forEach(([code, name, type, isBank]) => {
    batch.set(db.collection('chartOfAccounts').doc(`${SCHOOL}_${code}`), {
      schoolId: SCHOOL, code, name, type,
      is_bank: isBank, status: 'active', is_leaf: true, postable: true,
      created_at: new Date().toISOString(),
    });
  });
  await batch.commit();
  console.log(`chartOfAccounts: ${ACCOUNTS.length} accounts seeded for ${SCHOOL}`);
  process.exit(0);
})();
