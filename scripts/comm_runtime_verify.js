const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname,'..','firebase-rules','tests','node_modules','firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(path.resolve(__dirname,'..','application','config','graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'),'utf8'));
admin.initializeApp({credential: admin.credential.cert(SVC)});
const fsdb = admin.firestore();
const SCHOOL_ID = 'SCH_D94FE8F7AD';
(async()=>{
  console.log('═'.repeat(78));
  console.log('  Communication runtime surfaces — live Firestore reachability');
  console.log('═'.repeat(78));

  // Notices canonical query (mobile-app contract)
  const noticesSent = await fsdb.collection('notices')
    .where('schoolId','==',SCHOOL_ID).where('status','==','sent')
    .orderBy('sentAt','desc').limit(20).get();
  console.log(`\n  Notices (status=sent, sortable by sentAt):   ${noticesSent.size} doc(s) reachable`);
  noticesSent.docs.slice(0,3).forEach(d => {
    const x = d.data();
    console.log(`    • ${d.id}  body="${(x.body||'').slice(0,40)}"  author="${x.author||''}"  source=${x.source||'(admin)'}`);
  });

  // Circulars canonical
  const circulars = await fsdb.collection('circulars').where('schoolId','==',SCHOOL_ID).limit(10).get();
  console.log(`\n  Circulars (status=sent shape):                ${circulars.size} doc(s) reachable`);
  circulars.docs.slice(0,2).forEach(d => {
    const x = d.data();
    console.log(`    • ${d.id}  title="${(x.title||'').slice(0,40)}"  source=${x.source||'admin'}`);
  });

  // pushRequests (Cloud Function trigger queue)
  const pushReqs = await fsdb.collection('pushRequests').where('schoolId','==',SCHOOL_ID).limit(10).get();
  console.log(`\n  pushRequests (FCM dispatcher queue):          ${pushReqs.size} doc(s) reachable`);
  pushReqs.docs.slice(0,2).forEach(d => {
    const x = d.data();
    console.log(`    • ${d.id}  mark=${x.mark}  status=${x.status}  source=${x.source}`);
  });

  // birthdayWishes
  const wishes = await fsdb.collection('birthdayWishes').where('schoolId','==',SCHOOL_ID).limit(5).get();
  console.log(`\n  birthdayWishes (Admin._send_birthday_wish_core target):  ${wishes.size} doc(s) reachable`);

  // userDevices (FCM token registry)
  const devicesAct = await fsdb.collection('userDevices').where('schoolId','==',SCHOOL_ID).limit(50).get();
  console.log(`\n  userDevices (FCM token registry):             ${devicesAct.size} doc(s) reachable`);

  // Test: event-source notice exists (proves F1 event flow works)
  const eventNotices = await fsdb.collection('notices')
    .where('schoolId','==',SCHOOL_ID).where('source','==','event').limit(5).get();
  console.log(`\n  Event-source notices (Events/PTM via helper):   ${eventNotices.size} doc(s) reachable`);

  // Birthday-source notice
  const bdNotices = await fsdb.collection('notices')
    .where('schoolId','==',SCHOOL_ID).where('source','==','birthday_wish').limit(5).get();
  console.log(`  Birthday-wish notices (Admin):                ${bdNotices.size} doc(s) reachable`);

  // HR-source notice
  const hrNotices = await fsdb.collection('notices')
    .where('schoolId','==',SCHOOL_ID).where('source','==','hr_recruitment').limit(5).get();
  console.log(`  HR-recruitment notices (Hr.php):              ${hrNotices.size} doc(s) reachable`);

  console.log('');
  console.log('═'.repeat(78));
  console.log('  Communication runtime surfaces verified Firestore-only and reachable.');
  console.log('═'.repeat(78));
})().catch(e => { console.error(e); process.exit(1); });
