const fs=require('fs');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore();
const TENANT='SCH_D94FE8F7AD';
(async()=>{
  const q=await db.collection('feeStructures').where('schoolId','==',TENANT).get();
  console.log('feeStructures docs for tenant:\n');
  q.forEach(doc=>{
    const d=doc.data()||{};
    const cls=(d.className||d.class||''), sec=(d.section||'');
    const isC8=/8/.test(String(cls));
    console.log((isC8?'>>> ':'    ')+doc.id+'   class='+cls+' section='+sec+'  session='+(d.session||''));
    if(isC8){
      const heads=d.heads||d.feeHeads||d.structure||d.fees||[];
      const arr=Array.isArray(heads)?heads:Object.values(heads||{});
      arr.forEach(h=>{
        if(h&&typeof h==='object') console.log('        head: '+(h.id||h.feeHeadId||h.headId||'?')+' | name='+(h.name||h.feeHead||h.head||'?')+' | amount='+(h.amount||h.value||'?')+' | freq='+(h.frequency||h.freq||'?')+' | category='+(h.category||''));
      });
    }
  });
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
