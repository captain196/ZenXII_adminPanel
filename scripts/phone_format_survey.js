/**
 * READ-ONLY survey of stored student phone formats.
 * Decides whether tightening update_profile() is a one-line change or a migration.
 * Prints SHAPES and counts, never the numbers themselves.
 */
const fs = require('fs');
const ROOT = 'C:/xampp/htdocs/ZenX/school';
const admin = require(ROOT + '/firebase-rules/tests/node_modules/firebase-admin');
const SVC = JSON.parse(fs.readFileSync(ROOT + '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if (!admin.apps.length) admin.initializeApp({ credential: admin.credential.cert(SVC) });
const db = admin.firestore();
const school = (process.argv.find(a=>a.startsWith('--school='))||'').split('=')[1]||'';

const strict = v => /^[6-9]\d{9}$/.test(v);
const norm = raw => { let d=String(raw||'').replace(/\D+/g,''); if(!d) return ''; if(d.length>10) d=d.slice(-10); return strict(d)?d:''; };

(async () => {
  let ref = db.collection('students');
  if (school) ref = ref.where('schoolId','==',school);
  const snap = await ref.get();
  const buckets = { empty:0, alreadyStrict:0, recoverable:0, unrecoverable:0 };
  const shapes = {}; const bad = [];
  snap.forEach(doc => {
    const d = doc.data()||{};
    const raw = String(d['Phone Number'] ?? d.phone ?? d.phoneNumber ?? '').trim();
    if (raw === '') { buckets.empty++; return; }
    const shape = raw.replace(/\d/g,'9').replace(/\s+/g,'_');
    shapes[shape] = (shapes[shape]||0)+1;
    if (strict(raw)) buckets.alreadyStrict++;
    else if (norm(raw)) buckets.recoverable++;
    else { buckets.unrecoverable++; bad.push(doc.id + '  shape=' + shape); }
  });
  console.log('students scanned     : ' + snap.size);
  console.log('no phone stored      : ' + buckets.empty);
  console.log('already strict 10-dig: ' + buckets.alreadyStrict);
  console.log('recoverable by normalise: ' + buckets.recoverable);
  console.log('UNRECOVERABLE (would block a profile save): ' + buckets.unrecoverable);
  console.log('\nshapes (digits masked as 9):');
  Object.entries(shapes).sort((a,b)=>b[1]-a[1]).slice(0,15).forEach(([s,c])=>console.log('  '+String(c).padStart(5)+'  '+s));
  if (bad.length) { console.log('\nunrecoverable rows:'); bad.slice(0,20).forEach(b=>console.log('  '+b)); }
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
