const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();
(async () => {
  const q = await db.collection('feeReminderLog')
    .where('schoolId','==','SCH_D94FE8F7AD')
    .orderBy('sent_date','desc').limit(2).get();
  q.docs.forEach(d => {
    console.log('DOC ID:', d.id);
    console.log(JSON.stringify(d.data(), null, 2));
    console.log('---');
  });
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
