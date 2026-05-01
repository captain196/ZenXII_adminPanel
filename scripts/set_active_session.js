// Create / update the schoolConfig/{schoolId}_activeSession doc that parent
// endpoints read to derive the current academic session.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const schoolId = 'SCH_D94FE8F7AD';
  const session = '2026-27';
  const docId = `${schoolId}_activeSession`;

  await db.collection('schoolConfig').doc(docId).set({
    schoolId,
    session,
    setBy: 'set_active_session_script',
    setAt: new Date().toISOString(),
  }, { merge: true });

  const snap = await db.collection('schoolConfig').doc(docId).get();
  console.log(`Written schoolConfig/${docId}:`);
  console.log(JSON.stringify(snap.data(), null, 2));
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
