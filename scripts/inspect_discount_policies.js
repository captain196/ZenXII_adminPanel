// One-shot debug: list every feeDiscountPolicies doc for this school
// so we can see what (if anything) was actually saved during testing.
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';

(async () => {
  console.log(`\nfeeDiscountPolicies for ${SCHOOL}\n` + '─'.repeat(78));
  const snap = await db.collection('feeDiscountPolicies')
    .where('schoolId', '==', SCHOOL)
    .get();

  if (snap.empty) {
    console.log('  (no policies — nothing was saved)');
    process.exit(0);
  }

  console.log(`  Found ${snap.size} policy doc(s):\n`);
  for (const doc of snap.docs) {
    const d = doc.data();
    console.log(`  ${doc.id}`);
    console.log(`    name        : ${d.policy_name || d.name || '<MISSING>'}`);
    console.log(`    type        : ${d.discount_type || d.type || '<MISSING>'}`);
    console.log(`    value       : ${d.value ?? '<MISSING>'}`);
    console.log(`    criteria    : ${d.criteria || '<MISSING>'}`);
    console.log(`    max_cap     : ${d.max_cap ?? d.max_discount ?? '<none>'}`);
    console.log(`    active      : ${d.is_active ?? d.active ?? '<MISSING>'}`);
    console.log(`    categories  : ${JSON.stringify(d.categories || d.applicable_categories || [])}`);
    console.log(`    fee_titles  : ${JSON.stringify(d.fee_titles || d.applicable_titles || [])}`);
    console.log('');
  }
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
