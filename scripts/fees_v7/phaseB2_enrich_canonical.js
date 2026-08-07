// Phase B2 — additive canonicalFeeHeadId enrichment (enrichment only).
// Adds canonicalFeeHeadId to EVERY demand, resolved by frozen feeHead NAME -> feeHeads registry.
// Original feeHeadId UNTOUCHED. No financial field modified. Idempotent. Write-frozen.
const fs=require('fs');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore(); const T='SCH_D94FE8F7AD'; const OUT=ROOT+'/recovery/fees_v7';
const norm=s=>String(s||'').trim().toLowerCase().replace(/\s+/g,' ');
const FIN=['grossAmount','netAmount','balance','paidAmount','feeHeadId','feeHead','period','periodKey','status','studentId','billingType','frequency'];
(async()=>{
  console.log('== PHASE B2 — canonicalFeeHeadId enrichment -- '+new Date().toISOString()+' ==\n');
  // 1) freeze
  let active=0;(await db.collection('feeLocks').where('schoolId','==',T).get()).forEach(d=>{if((d.data()||{}).locked)active++;});
  console.log('[freeze] feeLocks active='+active+(active?'  -> ABORT':'  -> safe'));
  if(active>0){console.error('ABORT');process.exit(1);}
  // 2) backup
  const bk={tenant:T,at:new Date().toISOString(),feeDemands:[],feeHeads:[]};
  for(const c of ['feeDemands','feeHeads'])(await db.collection(c).where('schoolId','==',T).get()).forEach(d=>bk[c].push({id:d.id,data:d.data()}));
  const bkPath=OUT+'/phaseB2_backup_'+Date.now()+'.json'; fs.writeFileSync(bkPath,JSON.stringify(bk));
  console.log('[backup] feeDemands='+bk.feeDemands.length+' feeHeads='+bk.feeHeads.length+' -> '+bkPath.split('/').pop());
  // 3) registry name -> canonical feeHeadId
  const reg={}; bk.feeHeads.forEach(h=>reg[norm(h.data.name)]=h.data.feeHeadId);
  console.log('[registry] '+Object.keys(reg).length+' active heads: '+Object.entries(reg).map(([n,id])=>n+'='+id.slice(0,10)).join(', '));
  // 4) resolve + enrich (batched, additive)
  const unresolved=[]; const byId={}; let upd=0;
  for(let i=0;i<bk.feeDemands.length;i+=400){const b=db.batch(); const slice=bk.feeDemands.slice(i,i+400);
    slice.forEach(d=>{const nm=norm(d.data.feeHead||d.data.fee_head); const cid=reg[nm];
      if(!cid){unresolved.push({id:d.id,name:d.data.feeHead||d.data.fee_head}); return;}
      byId[cid]=(byId[cid]||0)+1; b.update(db.collection('feeDemands').doc(d.id),{canonicalFeeHeadId:cid});});
    await b.commit(); upd+=slice.length-unresolved.filter(u=>slice.find(s=>s.id===u.id)).length;}
  console.log('[enrich] demands updated='+Object.values(byId).reduce((a,c)=>a+c,0)+' unresolved='+unresolved.length);
  Object.entries(byId).forEach(([id,n])=>console.log('   '+id+' <- '+n+' demands'));
  if(unresolved.length){console.log('   !! UNRESOLVED (operator review):'); unresolved.slice(0,20).forEach(u=>console.log('      '+u.id+' name="'+u.name+'"'));}
  // 5) VERIFY
  console.log('\n[VERIFY]');
  const dem2=[];(await db.collection('feeDemands').where('schoolId','==',T).get()).forEach(d=>dem2.push({id:d.id,data:d.data()}));
  const regIds=new Set(bk.feeHeads.map(h=>h.data.feeHeadId));
  const hasAll=dem2.every(d=>!!d.data.canonicalFeeHeadId);
  const resolveOk=dem2.every(d=>regIds.has(d.data.canonicalFeeHeadId));
  console.log('  INV-1 every demand has canonicalFeeHeadId : '+(hasAll?'PASS':'FAIL')+' ('+dem2.filter(d=>!!d.data.canonicalFeeHeadId).length+'/'+dem2.length+')');
  console.log('  INV-2 resolves to a registry head         : '+(resolveOk?'PASS':'FAIL'));
  console.log('  no orphan canonicalFeeHeadId              : '+(dem2.every(d=>regIds.has(d.data.canonicalFeeHeadId))?'PASS':'FAIL'));
  // financial integrity vs backup
  const pre={}; bk.feeDemands.forEach(d=>pre[d.id]=d.data); let finBad=0;
  dem2.forEach(d=>{const p=pre[d.id]||{}; for(const k of FIN){ if(JSON.stringify(p[k])!==JSON.stringify(d.data[k])){finBad++;break;}}});
  console.log('  financial/identity fields UNCHANGED       : '+(finBad===0?'PASS':'FAIL ('+finBad+')'));
  console.log('  original feeHeadId untouched              : '+(dem2.every(d=>d.data.feeHeadId===(pre[d.id]||{}).feeHeadId)?'PASS':'FAIL'));
  console.log('  regression counts: feeDemands='+dem2.length+' feeHeads='+bk.feeHeads.length);
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
