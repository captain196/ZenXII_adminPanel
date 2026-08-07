// Phase C — write-frozen chart re-point + full verifier.
// Re-points the 5 chart docs' feeHeads[].feeHeadId -> canonical (by name),
// reaffirms billingType from registry, then verifies INV-1/2/5 + dup-safety + regression.
const fs=require('fs');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore(); const T='SCH_D94FE8F7AD'; const OUT=ROOT+'/recovery/fees_v7';
const norm=s=>String(s||'').trim().toLowerCase().replace(/\s+/g,' ');
(async()=>{
  console.log('== PHASE C — chart re-point + verify -- '+new Date().toISOString()+' ==\n');
  // freeze
  let active=0;(await db.collection('feeLocks').where('schoolId','==',T).get()).forEach(d=>{if((d.data()||{}).locked)active++;});
  console.log('[freeze] feeLocks active='+active+(active?'  -> ABORT':'  -> safe'));
  if(active>0){console.error('ABORT');process.exit(1);}
  // registry maps
  const reg={}, regBill={};
  (await db.collection('feeHeads').where('schoolId','==',T).get()).forEach(d=>{const x=d.data();reg[norm(x.name)]=x.feeHeadId;regBill[norm(x.name)]=x.billingType;});
  console.log('[registry] '+Object.keys(reg).length+' heads');
  // backup + re-point charts
  const fsAll=[];(await db.collection('feeStructures').where('schoolId','==',T).get()).forEach(d=>fsAll.push({id:d.id,data:d.data()}));
  const charts=fsAll.filter(d=>!/_titles$/.test(d.id));
  fs.writeFileSync(OUT+'/phaseC_backup_'+Date.now()+'.json',JSON.stringify({tenant:T,at:new Date().toISOString(),feeStructures:fsAll}));
  let docN=0,headN=0,unresolved=[];
  for(const c of charts){
    const arr=(c.data.feeHeads||[]).map(h=>{const n=norm(h.name);const cid=reg[n];if(!cid){unresolved.push(c.id+':'+h.name);return h;}headN++;return {...h,feeHeadId:cid,billingType:regBill[n]||h.billingType};});
    await db.collection('feeStructures').doc(c.id).update({feeHeads:arr}); docN++;
  }
  console.log('[re-point] chart docs='+docN+' heads re-pointed='+headN+' unresolved='+unresolved.length+(unresolved.length?' '+JSON.stringify(unresolved):''));
  // ---- VERIFY ----
  console.log('\n[VERIFY]');
  const reg2={};(await db.collection('feeHeads').where('schoolId','==',T).get()).forEach(d=>{const x=d.data();reg2[x.feeHeadId]={name:norm(x.name),bill:x.billingType};});
  const regIds=new Set(Object.keys(reg2));
  const charts2=[];(await db.collection('feeStructures').where('schoolId','==',T).get()).forEach(d=>{if(!/_titles$/.test(d.id))charts2.push(d.data());});
  const ch=charts2.flatMap(d=>d.data?d.data.feeHeads:(d.feeHeads||[]));
  const chHeads=charts2.flatMap(d=>d.feeHeads||[]);
  // INV-5b/c
  let inv5b=0,inv5c=0;
  chHeads.forEach(h=>{const n=norm(h.name);if(reg[n]&&h.feeHeadId===reg[n])inv5b++;if(regBill[n]&&h.billingType===regBill[n])inv5c++;});
  console.log('  INV-5b chart.feeHeadId==registry : '+inv5b+'/'+chHeads.length+(inv5b===chHeads.length?' PASS':' FAIL'));
  console.log('  INV-5c chart.billingType==registry: '+inv5c+'/'+chHeads.length+(inv5c===chHeads.length?' PASS':' FAIL'));
  // demands
  const dem=[];(await db.collection('feeDemands').where('schoolId','==',T).get()).forEach(d=>dem.push(d.data()));
  const inv1=dem.every(d=>!!d.canonicalFeeHeadId);
  const inv2=dem.every(d=>regIds.has(d.canonicalFeeHeadId));
  console.log('  INV-1 demands have canonicalFeeHeadId : '+(inv1?'PASS':'FAIL')+' ('+dem.filter(d=>!!d.canonicalFeeHeadId).length+'/'+dem.length+')');
  console.log('  INV-2 resolves to registry head       : '+(inv2?'PASS':'FAIL'));
  // dup-safety: no two NON-archived demands share (studentId, periodKey, canonicalFeeHeadId)
  const seen={}, dups=[];
  dem.forEach(d=>{if((d.status||'')==='archived')return;const k=(d.studentId||'')+'|'+(d.periodKey||d.period_key||'')+'|'+(d.canonicalFeeHeadId||'');if(seen[k])dups.push(k);else seen[k]=1;});
  console.log('  DUP-SAFETY unique canonical keys      : '+(dups.length===0?'PASS':'FAIL ('+dups.length+' dups)'));
  // regression counts
  console.log('  regression: feeStructures='+fsAll.length+' chartDocs='+charts2.length+' feeDemands='+dem.length+' feeHeads='+Object.keys(reg2).length);
  console.log('\n== PHASE C re-point + verify COMPLETE ==');
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
