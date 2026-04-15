// Cleanup orphans from partially-deleted PR0001 (only RUN doc removed)
// Replicates Hr::_delete_acct_journal() + slip cleanup.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const ENTRY_ID = 'JE_20260414193106_f55be96d';
const DRY_RUN = process.argv.includes('--dry-run');

function docId(name) { return `${SCHOOL}_${name}`; }

(async () => {
  const journalRef = db.collection('accounting').doc(docId(ENTRY_ID));
  const jSnap = await journalRef.get();
  if (!jSnap.exists) { console.log('journal already gone'); }
  const entry = jSnap.data() || {};
  console.log('Journal status:', entry.status, 'date:', entry.date, 'voucher:', entry.voucher_no);

  const affected = {};
  (entry.lines || []).forEach(line => {
    const ac = line.account_code || '';
    if (!ac) return;
    affected[ac] = affected[ac] || { dr: 0, cr: 0 };
    affected[ac].dr += Number(line.dr || 0);
    affected[ac].cr += Number(line.cr || 0);
  });
  console.log('Affected accounts:', JSON.stringify(affected));

  const session = entry.session || '2026-27';

  const plan = [];
  // 1. Reverse balances
  for (const [code, amt] of Object.entries(affected)) {
    const balRef = db.collection('accounting').doc(docId(`BAL_${session}_${code}`));
    const cur = (await balRef.get()).data() || {};
    const newDr = Number(cur.period_dr || 0) - amt.dr;
    const newCr = Number(cur.period_cr || 0) - amt.cr;
    plan.push({ op: 'balance-reverse', ref: balRef.path, code, from: { dr: cur.period_dr, cr: cur.period_cr }, to: { dr: newDr, cr: newCr } });
    if (!DRY_RUN) {
      await balRef.set({
        type: 'closing_balance',
        accountCode: code,
        schoolId: SCHOOL,
        session,
        period_dr: Math.round(newDr * 100) / 100,
        period_cr: Math.round(newCr * 100) / 100,
        last_computed: new Date().toISOString(),
      }, { merge: true });
    }
  }

  // 2. Delete indices
  const date = entry.date || '';
  if (date) plan.push({ op: 'delete-idx-date', id: `IDX_DATE_${date}_${ENTRY_ID}` });
  Object.keys(affected).forEach(ac => plan.push({ op: 'delete-idx-acct', id: `IDX_ACCT_${ac}_${ENTRY_ID}` }));
  if (!DRY_RUN) {
    if (date) await db.collection('accounting').doc(docId(`IDX_DATE_${date}_${ENTRY_ID}`)).delete().catch(() => {});
    for (const ac of Object.keys(affected)) {
      await db.collection('accounting').doc(docId(`IDX_ACCT_${ac}_${ENTRY_ID}`)).delete().catch(() => {});
    }
  }

  // 3. Soft-delete journal entry
  plan.push({ op: 'soft-delete-journal', ref: journalRef.path });
  if (!DRY_RUN && jSnap.exists) {
    await journalRef.set({ status: 'deleted', deleted_by: 'cleanup_script', deleted_at: new Date().toISOString() }, { merge: true });
  }

  // 4. Delete orphan SLIP docs
  const slips = await db.collection('salarySlips').where('runId', '==', 'PR0001').get();
  slips.forEach(d => plan.push({ op: 'delete-slip', ref: d.ref.path }));
  if (!DRY_RUN) {
    const batch = db.batch();
    slips.forEach(d => batch.delete(d.ref));
    await batch.commit();
  }

  console.log('\nPlan:');
  plan.forEach(p => console.log('  ', JSON.stringify(p)));
  console.log(DRY_RUN ? '\n(DRY RUN — nothing written)' : '\n✅ applied');
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
