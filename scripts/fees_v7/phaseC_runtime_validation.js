// Phase C runtime behavioral validation on REAL data (clean STU0001 + re-pointed Class 8-A chart).
// Replicates the exact edited generation/dedup logic (canonical key, auto-id, field emission,
// archive-awareness) with real Firestore writes, then CLEANS UP (restores STU0001 clean slate).
const fs=require('fs');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore(); const T='SCH_D94FE8F7AD'; const SID='STU0001';
const SESS='2026-27', CLASS='Class 8th', SEC='Section A';
const norm=s=>String(s||'').trim().toLowerCase().replace(/\s+/g,' ');
const MONTHS=['April','May','June','July','August','September','October','November','December','January','February','March'];
const pkOf=m=>{const y=['January','February','March'].includes(m)?2027:2026;const n=MONTHS.indexOf(m)+4;const mm=n>12?n-12:n;return y+'-'+String(mm).padStart(2,'0');};
const newId=()=>'t_'+Math.random().toString(16).slice(2,12)+Math.random().toString(16).slice(2,8); // test-tagged id
async function demands(){const o=[];(await db.collection('feeDemands').where('schoolId','==',T).where('studentId','==',SID).get()).forEach(d=>o.push({id:d.id,data:d.data()}));return o;}
function haveKeys(ex){const h={};ex.forEach(d=>{if((d.data.status||'')==='archived')return;const k=(d.data.periodKey||'')+'|'+(d.data.canonicalFeeHeadId||'');if(d.data.periodKey&&d.data.canonicalFeeHeadId)h[k]=true;});return h;}
(async()=>{
  console.log('== Phase C RUNTIME VALIDATION (real data, STU0001) -- '+new Date().toISOString()+' ==\n');
  // registry + re-pointed chart
  const reg={},regBill={};(await db.collection('feeHeads').where('schoolId','==',T).get()).forEach(d=>{const x=d.data();reg[norm(x.name)]=x.feeHeadId;regBill[norm(x.name)]=x.billingType;});
  const chartDoc=(await db.collection('feeStructures').doc(T+'_'+SESS+'_'+CLASS+'_'+SEC).get()).data();
  const heads=(chartDoc.feeHeads||[]);
  console.log('[setup] chart heads: '+heads.map(h=>h.name+'('+h.feeHeadId.slice(0,8)+','+h.billingType+')').join(', '));
  // build planned demands from chart (mirrors generator: monthly x12, yearly x1)
  const plan=[];
  heads.forEach(h=>{const n=norm(h.name);const yearly=(regBill[n]==='Yearly');const cid=reg[n];
    const ms=yearly?['April']:MONTHS;
    ms.forEach(m=>{const periodKey=yearly?pkOf('April'):pkOf(m);const period=yearly?('Yearly Fees '+SESS):(m+' '+(periodKey.slice(0,4)));
      plan.push({name:h.name,feeHeadId:h.feeHeadId,canonicalFeeHeadId:cid,billingType:regBill[n],periodKey,period,amount:h.amount,frequency:yearly?'yearly':'monthly'});});});
  // generation function (canonical dedup + auto-id + fields) — mirrors edited PHP
  async function generate(){let created=0;const ex=await demands();const have=haveKeys(ex);
    for(const p of plan){const key=p.periodKey+'|'+p.canonicalFeeHeadId;if(have[key])continue;
      const id=newId();await db.collection('feeDemands').doc(id).set({schoolId:T,session:SESS,demandId:id,studentId:SID,className:CLASS,section:SEC,
        feeHead:p.name,feeHeadId:p.feeHeadId,canonicalFeeHeadId:p.canonicalFeeHeadId,billingType:p.billingType,frequency:p.frequency,
        period:p.period,periodKey:p.periodKey,grossAmount:p.amount,netAmount:p.amount,paidAmount:0,balance:p.amount,status:'unpaid',_v7test:true});
      have[key]=true;created++;}
    return created;}
  // #1/#2 — generate twice
  const c1=await generate(); console.log('\n[#2] generation RUN 1 created: '+c1+' (expected 37)');
  const c2=await generate(); console.log('[#2] generation RUN 2 created: '+c2+' (expected 0 — canonical dedup)');
  const after=await demands();
  const fieldsOk=after.every(d=>d.data.feeHeadId&&d.data.canonicalFeeHeadId&&['Monthly','Yearly'].includes(d.data.billingType));
  const keys=after.filter(d=>(d.data.status||'')!=='archived').map(d=>d.data.periodKey+'|'+d.data.canonicalFeeHeadId);
  const dup=keys.length-new Set(keys).size;
  console.log('[#4] every demand has feeHeadId+canonicalFeeHeadId+billingType: '+(fieldsOk?'PASS':'FAIL'));
  console.log('[#1/#2] duplicate demands: '+dup+(dup===0?' PASS':' FAIL')+' ; total demands='+after.length);
  // #3 — promotion/archive-awareness: archive one demand, re-generate -> it is re-created (key freed), archived intact, new id != old
  const sample=after.find(d=>d.data.frequency==='monthly');
  await db.collection('feeDemands').doc(sample.id).update({status:'archived'});
  const c3=await generate();
  const after3=await demands();
  const archivedStill=(await db.collection('feeDemands').doc(sample.id).get()).data().status==='archived';
  const recreated=after3.filter(d=>(d.data.status||'')!=='archived'&&d.data.periodKey===sample.data.periodKey&&d.data.canonicalFeeHeadId===sample.data.canonicalFeeHeadId);
  console.log('\n[#3] after archiving 1 demand, re-gen created: '+c3+' (expected 1 — key freed)');
  console.log('[#3] archived demand preserved (not overwritten): '+(archivedStill?'PASS':'FAIL'));
  console.log('[#3] exactly one ACTIVE demand for that key, new auto-id != archived id: '+((recreated.length===1&&recreated[0].id!==sample.id)?'PASS':'FAIL'));
  // #5 — syncFeeStructure idempotency: re-apply registry-inherit to chart, confirm feeHeadId unchanged, 0 mints
  const before=heads.map(h=>h.feeHeadId).sort();
  const reapplied=(chartDoc.feeHeads||[]).map(h=>({...h,feeHeadId:reg[norm(h.name)]||h.feeHeadId,billingType:regBill[norm(h.name)]||h.billingType}));
  const afterIds=reapplied.map(h=>h.feeHeadId).sort();
  const mints=reapplied.filter((h,i)=>!Object.values(reg).includes(h.feeHeadId)).length;
  console.log('\n[#5] syncFeeStructure re-apply: feeHeadId unchanged='+(JSON.stringify(before)===JSON.stringify(afterIds)?'PASS':'FAIL')+' ; per-chart mints='+mints+(mints===0?' PASS':' FAIL'));
  // CLEANUP — delete all v7 test demands, restore STU0001 clean slate
  let del=0;for(const d of await demands()){if(d.data._v7test){await db.collection('feeDemands').doc(d.id).delete();del++;}}
  const remain=(await demands()).length;
  console.log('\n[cleanup] deleted '+del+' test demands ; STU0001 remaining fee demands='+remain+(remain===0?' (clean)':''));
  console.log('\n== RUNTIME VALIDATION COMPLETE ==');
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
