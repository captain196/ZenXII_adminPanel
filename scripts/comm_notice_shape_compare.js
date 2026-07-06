const path = require('path');
const admin = require(path.resolve(__dirname,'..','firebase-rules','tests','node_modules','firebase-admin'));
const SVC = JSON.parse(require('fs').readFileSync(path.resolve(__dirname,'..','application','config','graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'),'utf8'));
admin.initializeApp({credential: admin.credential.cert(SVC)});
(async()=>{
  const fsdb = admin.firestore();
  console.log('-- Helper-written notice (source=event) --');
  const h = await fsdb.collection('notices').where('schoolId','==','SCH_D94FE8F7AD').where('source','==','event').limit(1).get();
  h.forEach(s=>{
    const d = s.data();
    console.log('id='+s.id);
    for(const k of ['title','description','body','category','startDate','targetGroup','target','audience','priority','status','source','createdBy','author','createdAt','date','timestamp','publishedAt']){
      if(d[k]!==undefined) console.log('  '+k+' = '+JSON.stringify(d[k]).slice(0,80));
    }
  });
  console.log('\n-- Controller-written notice (admin authored) --');
  const c = await fsdb.collection('notices').where('schoolId','==','SCH_D94FE8F7AD').limit(50).get();
  let admin_notice = null;
  c.forEach(s=>{const d=s.data(); if(d.source !== 'event' && !admin_notice) admin_notice = {id:s.id,d}});
  if(admin_notice){
    console.log('id='+admin_notice.id);
    for(const k of Object.keys(admin_notice.d).sort()){
      console.log('  '+k+' = '+JSON.stringify(admin_notice.d[k]).slice(0,80));
    }
  }
  process.exit(0);
})().catch(e=>{console.error(e);process.exit(1);});
