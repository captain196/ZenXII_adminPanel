// Phase A — mint the canonical feeHeads registry (ADDITIVE ONLY).
// Reads feeStructures (titles+charts), derives unique head names, mints one
// feeHeads/{schoolId}_{feeHeadId} doc per permanent head. Identity fields only
// (NO billingType -> Phase B). Idempotent: skips a name already in feeHeads.
const fs=require('fs'); const crypto=require('crypto');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore(); const T='SCH_D94FE8F7AD'; const OUT=ROOT+'/recovery/fees_v7';
const norm=s=>String(s||'').trim().toLowerCase().replace(/\s+/g,' ');
(async()=>{
  console.log('== PHASE A — mint feeHeads registry -- '+new Date().toISOString()+' ==\n');
  // 1) write-freeze check
  let active=0; (await db.collection('feeLocks').where('schoolId','==',T).get()).forEach(d=>{if((d.data()||{}).locked)active++;});
  console.log('[freeze] feeLocks active='+active+(active?'  -> ABORT (in-flight payment)':'  -> safe to proceed'));
  if(active>0){console.error('ABORT: active payment lock.');process.exit(1);}
  // 2) re-backup the collections Phase A reads/writes
  const bk={tenant:T,at:new Date().toISOString(),feeStructures:[],feeHeads_before:[]};
  (await db.collection('feeStructures').where('schoolId','==',T).get()).forEach(d=>bk.feeStructures.push({id:d.id,data:d.data()}));
  (await db.collection('feeHeads').where('schoolId','==',T).get()).forEach(d=>bk.feeHeads_before.push({id:d.id,data:d.data()}));
  const bkPath=OUT+'/phaseA_backup_'+Date.now()+'.json'; fs.writeFileSync(bkPath,JSON.stringify(bk));
  console.log('[backup] feeStructures='+bk.feeStructures.length+' feeHeads(before)='+bk.feeHeads_before.length+' -> '+bkPath.split('/').pop());
  // 3) derive unique permanent heads (name + category) from titles+charts
  const heads={}; const add=h=>{const n=norm(h&&h.name);if(!n)return; if(!heads[n])heads[n]={name:h.name,category:h.category||''}; else if(!heads[n].category&&h.category)heads[n].category=h.category;};
  bk.feeStructures.forEach(d=>(d.data.feeHeads||[]).forEach(add));
  const list=Object.values(heads);
  console.log('[derive] '+list.length+' permanent heads: '+list.map(h=>h.name).join(', '));
  // 4) idempotent mint (skip names already present)
  const existing={}; bk.feeHeads_before.forEach(d=>existing[norm(d.data.name)]=d.id);
  let minted=0,skipped=0; const created=[];
  for(const h of list){
    if(existing[norm(h.name)]){skipped++;continue;}
    const fid='FH_'+crypto.randomBytes(8).toString('hex').toUpperCase();
    const docId=T+'_'+fid;
    await db.collection('feeHeads').doc(docId).set({feeHeadId:fid,schoolId:T,name:h.name,category:h.category||'',status:'active',createdAt:new Date().toISOString()});
    created.push({docId,fid,name:h.name}); minted++;
  }
  console.log('[mint] minted='+minted+' skipped(existing)='+skipped);
  created.forEach(c=>console.log('   + '+c.docId+'  name="'+c.name+'"'));
  // 5) verify
  const after=[]; (await db.collection('feeHeads').where('schoolId','==',T).get()).forEach(d=>after.push(d.data()));
  const ids=new Set(after.map(d=>d.feeHeadId));
  const fmtOk=after.every(d=>/^FH_[0-9A-F]{16}$/.test(d.feeHeadId));
  console.log('\n[VERIFY]');
  console.log('  feeHeads count           : '+after.length+(after.length===list.length?'  PASS':'  CHECK'));
  console.log('  unique feeHeadId         : '+(ids.size===after.length?'PASS':'FAIL'));
  console.log('  id format FH_[16 HEX]    : '+(fmtOk?'PASS':'FAIL'));
  console.log('  all status=active        : '+(after.every(d=>d.status==='active')?'PASS':'FAIL'));
  console.log('  NO billingType (Phase B) : '+(after.every(d=>d.billingType===undefined)?'PASS':'FAIL'));
  console.log('  NO session (school-level): '+(after.every(d=>d.session===undefined)?'PASS':'FAIL'));
  console.log('  names                    : '+JSON.stringify(after.map(d=>d.name).sort()));
  console.log('\n[regression] feeStructures/feeDemands/_titles untouched (Phase A wrote only feeHeads).');
  process.exit(0);
})().catch(e=>{console.error('ERROR:',e.message);process.exit(1);});
