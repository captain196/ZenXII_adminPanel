// Phase 9 wallet-removal migration.
//
// For each studentAdvanceBalances doc with amount > 0:
//   1. Find the student's oldest unpaid feeDemand (lowest balance-owing month).
//   2. Apply up to `amount` as additional discount on that demand, cascading
//      to the next-oldest demand until the wallet is fully absorbed.
//   3. Write a studentDiscounts audit row so the conversion is traceable.
//   4. Zero the wallet doc (amount=0, migrationReason set).
//
// Modes:
//   node scripts/wallet_removal_migration.js dry-run
//     Prints what WOULD happen. No writes.
//
//   node scripts/wallet_removal_migration.js live
//     Performs the writes inside Firestore transactions.
//
// Notes on the month-order policy:
//   "Oldest unpaid" uses the academic-year sequence April → March, Yearly
//   last. We don't rely on lexicographic sort because "April" < "August".
//
// Edge cases:
//   - Student has NO unpaid demands: wallet amount is written to a holding
//     `studentDiscounts` row with `applied=false` and flagged for admin
//     review. The wallet doc is still zeroed so it doesn't re-leak.
//   - Wallet doc has amount <= 0.005: skipped.

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const ACADEMIC_ORDER = [
  'April', 'May', 'June', 'July', 'August', 'September',
  'October', 'November', 'December', 'January', 'February', 'March',
  'Yearly Fees', 'Annual',
];

function monthRank(m) {
  const idx = ACADEMIC_ORDER.indexOf(String(m || '').trim());
  return idx === -1 ? 999 : idx;
}

async function planForStudent(walletDoc) {
  const wdata = walletDoc.data();
  const schoolId = wdata.schoolId;
  const session = wdata.session;
  const studentId = wdata.studentId;
  const amount = Number(wdata.amount ?? 0);

  if (amount <= 0.005) {
    return { walletDoc, schoolId, session, studentId, amount, absorptions: [], leftover: 0, holding: false };
  }

  const demandsSnap = await db.collection('feeDemands')
    .where('schoolId', '==', schoolId)
    .where('session', '==', session)
    .where('studentId', '==', studentId)
    .get();

  const unpaid = demandsSnap.docs
    .map(d => ({ id: d.id, ...d.data() }))
    .filter(d => {
      const bal = Number(d.balance ?? 0);
      const status = String(d.status ?? '').toLowerCase();
      return bal > 0.005 && status !== 'paid';
    })
    .sort((a, b) => monthRank(a.month) - monthRank(b.month));

  let remaining = amount;
  const absorptions = [];
  for (const d of unpaid) {
    if (remaining <= 0.005) break;
    const bal = Number(d.balance ?? 0);
    const apply = Math.min(remaining, bal);
    absorptions.push({
      demandId: d.id,
      month: d.month,
      balanceBefore: bal,
      applied: apply,
      balanceAfter: bal - apply,
      discountBefore: Number(d.discountAmount ?? 0),
    });
    remaining -= apply;
  }

  return {
    walletDoc,
    schoolId, session, studentId, amount,
    absorptions,
    leftover: remaining > 0.005 ? remaining : 0,
    holding: absorptions.length === 0,
  };
}

async function apply(plan) {
  const { walletDoc, schoolId, session, studentId, amount, absorptions, leftover, holding } = plan;
  if (amount <= 0.005) return;

  const now = new Date().toISOString();
  const auditBase = {
    schoolId, session, studentId,
    reason: 'wallet_conversion_phase9_cleanup',
    walletAmountBefore: amount,
    createdAt: now,
    source: 'wallet_removal_migration',
  };

  await db.runTransaction(async (txn) => {
    for (const a of absorptions) {
      const ref = db.collection('feeDemands').doc(a.demandId);
      const snap = await txn.get(ref);
      if (!snap.exists) continue;
      const cur = snap.data();
      const curBal = Number(cur.balance ?? 0);
      const curDisc = Number(cur.discountAmount ?? 0);
      const apply = Math.min(a.applied, curBal);
      txn.update(ref, {
        balance: curBal - apply,
        discountAmount: curDisc + apply,
        status: (curBal - apply) <= 0.005 ? 'paid' : (String(cur.status ?? '').toLowerCase() === 'unpaid' ? 'partial' : cur.status),
        lastMutation: 'wallet_conversion_phase9',
        lastMutationAt: now,
      });
    }

    // Audit row per student
    const auditId = `${schoolId}_${session}_${studentId}_walletcleanup_${Date.now()}`;
    txn.set(db.collection('studentDiscounts').doc(auditId), {
      ...auditBase,
      absorbed: absorptions.map(a => ({ demandId: a.demandId, month: a.month, applied: a.applied })),
      leftover,
      holding,
      totalAbsorbed: absorptions.reduce((s, a) => s + a.applied, 0),
    });

    // Zero wallet doc (keep as tombstone for audit)
    txn.update(walletDoc.ref, {
      amount: 0,
      migrationReason: 'wallet_system_removed_phase9',
      migratedAt: now,
      preMigrationAmount: amount,
    });
  });
}

async function main() {
  const mode = (process.argv[2] || '').toLowerCase();
  if (!['dry-run', 'live'].includes(mode)) {
    console.log('Usage:');
    console.log('  node scripts/wallet_removal_migration.js dry-run');
    console.log('  node scripts/wallet_removal_migration.js live');
    process.exit(2);
  }

  const snap = await db.collection('studentAdvanceBalances').get();
  console.log(`\nFound ${snap.size} studentAdvanceBalances doc(s).`);

  const plans = [];
  for (const doc of snap.docs) {
    plans.push(await planForStudent(doc));
  }

  const positive = plans.filter(p => p.amount > 0.005);
  console.log(`${positive.length} doc(s) with positive balance to migrate.\n`);

  positive.forEach(p => {
    console.log(`[${p.schoolId} / ${p.session} / ${p.studentId}] wallet=₹${p.amount.toFixed(2)}`);
    if (p.holding) {
      console.log(`  ⚠  No unpaid demand found — will be recorded as holding discount (applied=false, leftover=₹${p.amount.toFixed(2)}).`);
    } else {
      p.absorptions.forEach(a => {
        console.log(`  ⇒ ${a.month} demand ${a.demandId}: bal ₹${a.balanceBefore.toFixed(2)} → ₹${a.balanceAfter.toFixed(2)}  (apply ₹${a.applied.toFixed(2)}, disc ${a.discountBefore.toFixed(2)} → ${(a.discountBefore + a.applied).toFixed(2)})`);
      });
      if (p.leftover > 0.005) {
        console.log(`  ⚠  ₹${p.leftover.toFixed(2)} could not be absorbed (unpaid demands exhausted) — recorded as leftover on audit row.`);
      }
    }
  });

  if (mode === 'dry-run') {
    console.log('\nDRY RUN — no writes performed.\n');
    return;
  }

  console.log('\nApplying...\n');
  for (const p of positive) {
    await apply(p);
    console.log(`  ✓ ${p.studentId} migrated.`);
  }
  console.log('\nMigration complete.\n');
}

main().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
