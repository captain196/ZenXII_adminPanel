const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname,'..','firebase-rules','tests','node_modules','firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(path.resolve(__dirname,'..','application','config','graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'),'utf8'));
admin.initializeApp({credential: admin.credential.cert(SVC)});
const fsdb = admin.firestore();
(async()=>{
  const cols = ['conversations','messages','messageInboxes','messageQueue','alertTriggers','messageTemplates','notices','circulars','notifications','pushRequests','deliveryLogs','userDevices','birthdayWishes','circularReads'];
  console.log('═'.repeat(78));
  console.log('  COMM-DOMAIN FIRESTORE POPULATION — fleet-wide');
  console.log('═'.repeat(78));
  const sn = await fsdb.collection('schools').limit(50).get();
  const tenants = new Set();
  sn.forEach(s => { const m = s.id.match(/^(SCH_[A-F0-9]+)/i); if (m) tenants.add(m[1]); });
  console.log(`  Tenants: ${tenants.size}`);
  for (const c of cols) {
    let total = 0;
    const byTenant = {};
    for (const t of [...tenants].sort()) {
      const snap = await fsdb.collection(c).where('schoolId','==',t).limit(500).get();
      total += snap.size;
      if (snap.size > 0) byTenant[t] = snap.size;
    }
    const used = Object.keys(byTenant).length;
    const tag = total === 0 ? 'DORMANT' : (used === 1 ? 'SINGLE_TENANT' : 'MULTI_TENANT');
    console.log(`  ${c.padEnd(22)} total=${String(total).padStart(4)}  ${tag.padEnd(14)}  ${JSON.stringify(byTenant)}`);
  }
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
