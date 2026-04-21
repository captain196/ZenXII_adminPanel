// Phase 8 test helper — verifies FCM token + lets you see the latest
// feeReminderLog entry after clicking "Push" in the admin UI.
//
//   node scripts/test_phase8_push.js tokens  [studentId]
//     → list active FCM tokens for that student's parent (userDevices
//       where userId == studentId). If empty, the parent app hasn't
//       logged in on a device yet; push will report no_device.
//
//   node scripts/test_phase8_push.js log     [schoolId]
//     → print the 10 most recent feeReminderLog entries with status,
//       channel, and delivery counts.

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const DEFAULT_SCHOOL = 'SCH_D94FE8F7AD';

async function tokens(studentId) {
  studentId = studentId || 'STU0001';
  const q = await db.collection('userDevices').where('userId','==',studentId).get();
  console.log(`\nuserDevices for userId=${studentId}:  ${q.size} doc(s)\n`);
  q.docs.forEach(d => {
    const x = d.data();
    console.log(`  ${d.id}`);
    console.log(`    status=${x.status}  platform=${x.platform || x.os || '?'}  app=${x.appVersion || '?'}`);
    console.log(`    fcmToken=${String(x.fcmToken || '').slice(0, 30)}...`);
    console.log(`    updatedAt=${x.updatedAt}`);
  });
  if (q.empty) {
    console.log('  ✗ No tokens found. Parent app must be installed AND logged in on the phone before a push reminder can reach them.\n');
  }
}

async function log(schoolId) {
  schoolId = schoolId || DEFAULT_SCHOOL;
  const q = await db.collection('feeReminderLog')
    .where('schoolId','==',schoolId)
    .orderBy('sent_date','desc').limit(10).get();
  console.log(`\nfeeReminderLog (latest 10) for ${schoolId}:\n`);
  console.log('sent_date             channel    status      student       amount  delivered');
  console.log('-'.repeat(100));
  q.docs.forEach(d => {
    const x = d.data();
    const when = String(x.sent_date || '').slice(0,19).padEnd(20);
    const ch   = String(x.channel   || '').padEnd(10);
    const st   = String(x.status    || '').padEnd(11);
    const sid  = String(x.student_id || '').padEnd(13);
    const amt  = String(x.amount_due || 0).padStart(7);
    const del  = x.deliveredCount !== undefined ? `${x.deliveredCount} device(s)` : '';
    console.log(`${when} ${ch} ${st} ${sid} ${amt}   ${del}`);
    if (x.lastError) console.log(`  ↳ error: ${x.lastError}`);
  });
  if (q.empty) console.log('  (empty)\n');
}

(async () => {
  const [cmd, a] = process.argv.slice(2);
  if (cmd === 'tokens')   await tokens(a);
  else if (cmd === 'log') await log(a);
  else {
    console.log('Usage:');
    console.log('  node scripts/test_phase8_push.js tokens [studentId]');
    console.log('  node scripts/test_phase8_push.js log    [schoolId]');
  }
})().then(() => process.exit(0)).catch(e => { console.error(e); process.exit(1); });
