// Phase B — additive billingType backfill (enrichment only).
// Adds canonical billingType in {Monthly,Yearly} to: feeHeads(6), chart heads(20),
// feeDemands(666). Derived per-record from existing signals. Legacy fields UNTOUCHED.
// No financial field on any demand is modified. Idempotent. Write-frozen.
const fs=require('fs');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore(); const T='SCH_D94FE8F7AD'; const OUT=ROOT+'/recovery/fees_v7';
const norm=s=>String(s||'').trim().toLowerCase().replace(/\s+/g,' ');
const fromFreq=f=>{f=String(f||'').toLowerCase(); return (f==='annual'||f==='yearly'||f==='one-time'||f==='onetime')?'Yearly':'Monthly';};
const fromType=t=>t==='Yearly'?'Yearly':'Monthly';
const deriveDemand=d=>{const f=String(d.frequency||'').toLowerCase(),p=String(d.periodType||'').toLowerCase(),iy=d.isYearly===true,m=String(d.month||d.period||'');
  return (f==='annual'||f==='yearly'||p==='yearly'||iy||/yearly fees/i.test(m))?'Yearly':'Monthly';};
const FIN=['grossAmount','netAmount','balance','paidAmount','feeHeadId','feeHead','period','periodKey','status','studentId','discountAmount','concessionApplied'];
(async()=>{
  console.log('== PHASE B — billingType backfill -- '+new Date().toISOString()+' ==\n');
  // 1) freeze check
  let active=0;(await db.collection('feeLocks').where('schoolId','==',T).get()).forEach(d=>{if((d.data()||{}).locked)active++;});
  console.log('[freeze] feeLocks active='+active+(active?'  -> ABORT':'  -> safe'));
  if(active>0){console.error('ABORT: active lock');process.exit(1);}
  // 2) backup feeStructures+feeDemands+feeHeads+feeReceipts
  const bk={tenant:T,at:new Date().toISOString(),feeStructures:[],feeDemands:[],feeHeads:[],feeReceipts:[]};
  for(const c of ['feeStructures','feeDemands','feeHeads','feeReceipts']) (await db.collection(c).where('schoolId','==',T).get()).forEach(d=>bk[c].push({id:d.id,data:d.data()}));
  const bkPath=OUT+'/phaseB_backup_'+Date.now()+'.json'; fs.writeFileSync(bkPath,JSON.stringify(bk));
  console.log('[backup] feeStructures='+bk.feeStructures.length+' feeDemands='+bk.feeDemands.length+' feeHeads='+bk.feeHeads.length+' feeReceipts='+bk.feeReceipts.length+' -> '+bkPath.split('/').pop());
  // build name->billingType from titles(type)+charts(frequency), consistency-checked
  const charts=bk.feeStructures.filter(d=>!/_titles$/.test(d.id));
  const titles=bk.feeStructures.filter(d=>/_titles$/.test(d.id));
  const nameBill={}; const conflicts=[];
  const put=(name,bt)=>{const n=norm(name); if(!n)return; if(nameBill[n]&&nameBill[n]!==bt)conflicts.push(name+': '+nameBill[n]+' vs '+bt); else nameBill[n]=bt;};
  titles.forEach(d=>(d.data.feeHeads||[]).forEach(h=>put(h.name,fromType(h.type))));
  charts.forEach(d=>(d.data.feeHeads||[]).forEach(h=>put(h.name,fromFreq(h.frequency))));
  console.log('[derive] name->billingType cadence conflicts: '+conflicts.length+(conflicts.length?' '+JSON.stringify(conflicts):' (clean)'));
  // 3a) registry heads
  let rUpd=0; for(const r of bk.feeHeads){const bt=nameBill[norm(r.data.name)]||'Monthly'; await db.collection('feeHeads').doc(r.id).update({billingType:bt}); rUpd++;}
  console.log('[3a registry] '+rUpd+' heads updated');
  // 3b) chart heads (RMW the feeHeads array; preserve all fields, add billingType)
  let cDocs=0,cHeads=0; for(const c of charts){const arr=(c.data.feeHeads||[]).map(h=>({...h,billingType:fromFreq(h.frequency)})); cHeads+=arr.length; await db.collection('feeStructures').doc(c.id).update({feeHeads:arr}); cDocs++;}
  console.log('[3b charts] '+cDocs+' chart docs, '+cHeads+' heads enriched');
  // 3c) demands (batched, additive)
  let dUpd=0,mY=0,mM=0; for(let i=0;i<bk.feeDemands.length;i+=400){const b=db.batch(); const slice=bk.feeDemands.slice(i,i+400);
    slice.forEach(d=>{const bt=deriveDemand(d.data); if(bt==='Yearly')mY++;else mM++; b.update(db.collection('feeDemands').doc(d.id),{billingType:bt});}); await b.commit(); dUpd+=slice.length;}
  console.log('[3c demands] '+dUpd+' updated (Monthly='+mM+', Yearly='+mY+')');
  // 3d) receipts
  console.log('[3d receipts] '+bk.feeReceipts.length+' (none to enrich)');
  // 4) VERIFY
  console.log('\n[VERIFY]');
  const reg2=[];(await db.collection('feeHeads').where('schoolId','==',T).get()).forEach(d=>reg2.push(d.data()));
  const fs2=[];(await db.collection('feeStructures').where('schoolId','==',T).get()).forEach(d=>fs2.push({id:d.id,data:d.data()}));
  const dem2=[];(await db.collection('feeDemands').where('schoolId','==',T).get()).forEach(d=>dem2.push({id:d.id,data:d.data()}));
  const chart2=fs2.filter(d=>!/_titles$/.test(d.id)); const ch2=chart2.flatMap(d=>d.data.feeHeads||[]);
  const dom=v=>['Monthly','Yearly'].includes(v);
  console.log('  coverage feeHeads   : '+reg2.filter(r=>dom(r.billingType)).length+'/'+reg2.length+(reg2.every(r=>dom(r.billingType))?' PASS':' FAIL'));
  console.log('  coverage chart heads: '+ch2.filter(h=>dom(h.billingType)).length+'/'+ch2.length+(ch2.every(h=>dom(h.billingType))?' PASS':' FAIL'));
  console.log('  coverage demands    : '+dem2.filter(d=>dom(d.data.billingType)).length+'/'+dem2.length+(dem2.every(d=>dom(d.data.billingType))?' PASS':' FAIL'));
  // consistency: chart head billingType == registry head's (by name)
  const regBy={}; reg2.forEach(r=>regBy[norm(r.name)]=r.billingType);
  const chBad=ch2.filter(h=>regBy[norm(h.name)]&&h.billingType!==regBy[norm(h.name)]);
  console.log('  chart==registry     : '+(chBad.length===0?'PASS':'FAIL '+chBad.length));
  // financial integrity: compare each demand's non-billingType fields to backup
  const pre={}; bk.feeDemands.forEach(d=>pre[d.id]=d.data);
  let finBad=0; dem2.forEach(d=>{const p=pre[d.id]||{}; for(const k of FIN){ if(JSON.stringify(p[k])!==JSON.stringify(d.data[k])){finBad++;break;}}});
  console.log('  demand financial fields UNCHANGED : '+(finBad===0?'PASS':'FAIL ('+finBad+')'));
  // additive: legacy fields still present on demands
  const legacyKept=dem2.every(d=>d.data.frequency!==undefined);
  console.log('  legacy fields still present (additive): '+(legacyKept?'PASS':'FAIL'));
  console.log('  regression counts   : feeStructures='+fs2.length+' feeDemands='+dem2.length+' feeHeads='+reg2.length);
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
