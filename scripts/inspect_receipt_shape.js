// Print one receipt doc fully with field types, so we can diagnose
// Kotlin Firestore deserialization issues (Double vs Long vs String,
// missing no-arg ctor, etc.)
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const snap = await db.collection('feeReceipts')
    .where('schoolId', '==', 'SCH_D94FE8F7AD')
    .where('studentId', '==', 'STU0001')
    .limit(2).get();

  for (const doc of snap.docs) {
    console.log(`\n═══ ${doc.id} ═══`);
    const d = doc.data() || {};
    for (const k of Object.keys(d).sort()) {
      const v = d[k];
      const type = v === null ? 'null'
        : Array.isArray(v) ? `array[${v.length}]`
        : typeof v;
      const shown = Array.isArray(v) ? JSON.stringify(v)
        : typeof v === 'object' && v !== null ? JSON.stringify(v).slice(0, 80)
        : String(v);
      console.log(`  ${k.padEnd(22)} ${type.padEnd(10)} ${shown}`);
    }
  }
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
