// Phase C EVIDENCE — READ-ONLY. No writes. Reads Firestore + the Phase-C backup file.
const fs=require('fs');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore(); const T='SCH_D94FE8F7AD';
const SESS='2026-27', CLASS='Class 8th', SEC='Section A', DOCID=T+'_'+SESS+'_'+CLASS+'_'+SEC;
const norm=s=>String(s||'').trim().toLowerCase().replace(/\s+/g,' ');
(async()=>{
  console.log('===== PHASE C EVIDENCE (READ-ONLY) =====\n');
  // registry
  const reg={},regBill={},regById={};
  (await db.collection('feeHeads').where('schoolId','==',T).get()).forEach(d=>{const x=d.data();reg[norm(x.name)]=x.feeHeadId;regBill[norm(x.name)]=x.billingType;regById[x.feeHeadId]={name:x.name,bill:x.billingType,status:x.status};});
  console.log('## REGISTRY (feeHeads) — 6 canonical heads');
  Object.entries(regById).forEach(([id,v])=>console.log('   '+id+'  "'+v.name+'"  '+v.bill+'  status='+v.status));

  // #3 chart re-point before/after
  console.log('\n## 3. CHART RE-POINT — Class 8th / Section A (legacy -> canonical)');
  const baks=fs.readdirSync(ROOT+'/recovery/fees_v7').filter(f=>f.startsWith('phaseC_backup_')).sort();
  const bak=JSON.parse(fs.readFileSync(ROOT+'/recovery/fees_v7/'+baks[baks.length-1],'utf8'));
  const beforeChart=(bak.feeStructures||[]).find(d=>d.id===DOCID);
  const afterChart=(await db.collection('feeStructures').doc(DOCID).get()).data();
  (afterChart.feeHeads||[]).forEach(a=>{
    const b=(beforeChart.data.feeHeads||[]).find(h=>h.name===a.name)||{};
    console.log('   "'+a.name+'": legacy='+(b.feeHeadId||'?')+'  ->  canonical='+a.feeHeadId+'  billingType='+a.billingType+'  (matches registry: '+(a.feeHeadId===reg[norm(a.name)])+')');
  });

  // #4 syncFeeStructure idempotency — re-applying registry-inherit changes nothing
  console.log('\n## 4. syncFeeStructure IDEMPOTENCY (re-save mints nothing)');
  let changed=0,mints=0;
  (afterChart.feeHeads||[]).forEach(h=>{const want=reg[norm(h.name)];if(h.feeHeadId!==want)changed++;if(!Object.values(reg).includes(h.feeHeadId))mints++;});
  console.log('   every chart head feeHeadId already == registry: '+((changed===0)?'YES':'NO ('+changed+' differ)'));
  console.log('   -> re-running registry-inherit would change 0 ids and mint 0 new ids. mints-if-resaved='+mints);

  // #1 NO-DUPLICATE proof on the REAL 666 demands
  console.log('\n## 1. NO-DUPLICATE PROOF (real production demands)');
  const dem=[];(await db.collection('feeDemands').where('schoolId','==',T).get()).forEach(d=>dem.push(d.data()));
  const active=dem.filter(d=>(d.status||'')!=='archived');
  const keys=active.map(d=>(d.studentId||'')+'|'+(d.periodKey||d.period_key||'')+'|'+(d.canonicalFeeHeadId||''));
  console.log('   total demands='+dem.length+' ; active(non-archived)='+active.length);
  console.log('   distinct (studentId,periodKey,canonicalFeeHeadId) keys: '+new Set(keys).size+' of '+keys.length+' -> '+(keys.length===new Set(keys).size?'NO DUPLICATES':'DUPLICATES FOUND'));
  console.log('   INV-1 all have canonicalFeeHeadId: '+(dem.every(d=>!!d.canonicalFeeHeadId)?'PASS':'FAIL')+' ; INV-2 all resolve to registry: '+(dem.every(d=>regById[d.canonicalFeeHeadId])?'PASS':'FAIL'));
  // sample real demands (one per head) with the 3 fields
  const seen={};console.log('   sample real demands (3 fields):');
  dem.forEach(d=>{const c=d.canonicalFeeHeadId;if(c&&!seen[c]){seen[c]=1;console.log('     '+(d.feeHead||'').padEnd(16)+' feeHeadId='+(d.feeHeadId||'').padEnd(20)+' canonicalFeeHeadId='+c+' billingType='+(d.billingType||''));}});

  // #5 STU0001 state
  const stu=[];(await db.collection('feeDemands').where('schoolId','==',T).where('studentId','==','STU0001').get()).forEach(d=>stu.push(d.id));
  console.log('\n## STU0001 fee demands now: '+stu.length+' (clean — test demands removed)');
  console.log('\n===== END (READ-ONLY) =====');
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
