const fs=require('fs');
const ROOT='C:/xampp/htdocs/ZenX/school';
const admin=require(ROOT+'/firebase-rules/tests/node_modules/firebase-admin');
const SVC=JSON.parse(fs.readFileSync(ROOT+'/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json','utf8'));
if(!admin.apps.length) admin.initializeApp({credential:admin.credential.cert(SVC)});
const db=admin.firestore();
const TENANT='SCH_D94FE8F7AD', SID='STU0001';
const FIELDS=['studentId','student_id','uid'];
const COLS=['feeDemands','feeReceipts','feeOnlineOrders','feeOnlinePayments','feeRefunds','feeRefundVouchers','feeConcessions','studentFeeSummary','feeDefaulters'];
const g=(o,...k)=>{for(const x of k){if(o[x]!==undefined&&o[x]!==null)return o[x];}return '';};
(async()=>{
  console.log('══ STU0001 fee-state verifier — '+new Date().toISOString()+' ══\n');
  // student profile + chart
  const stu=await db.collection('students').doc(TENANT+'_'+SID).get();
  let cls='',sec='';
  if(stu.exists){const d=stu.data(); cls=g(d,'class','className'); sec=g(d,'section'); console.log('  Student: '+g(d,'name','studentName')+' | class='+cls+' section='+sec);}
  else console.log('  Student doc '+TENANT+'_'+SID+' NOT found');
  // fee chart (class-keyed; should survive the wipe)
  try{
    const q=await db.collection('feeStructures').where('schoolId','==',TENANT).get();
    let n=0; q.forEach(()=>n++); console.log('  feeStructures for tenant: '+n+' doc(s) present (class charts intact)');
  }catch(e){}
  console.log('');
  // per-collection
  for(const col of COLS){
    const found=new Map();
    for(const f of FIELDS){try{const s=await db.collection(col).where(f,'==',SID).get();s.forEach(d=>{const x=d.data()||{};const sch=g(x,'schoolId','school','school_code'); if(!sch||sch===TENANT) found.set(d.id,x);});}catch(e){}}
    console.log('  '+col.padEnd(18)+': '+found.size);
    if(col==='feeDemands'){
      [...found.values()].sort((a,b)=>String(g(a,'period','periodKey')).localeCompare(String(g(b,'period','periodKey')))).forEach(x=>{
        console.log('      '+String(g(x,'feeHead','fee_head','feeHeadName')).padEnd(14)+' '+String(g(x,'period','periodKey')).padEnd(10)+
          ' gross='+g(x,'grossAmount','original_amount')+' disc='+g(x,'discountAmount','discount_amount')+
          ' net='+g(x,'netAmount','net_amount')+' paid='+g(x,'paidAmount','paid_amount')+
          ' bal='+g(x,'balance')+' '+g(x,'status')+(x.concessionApplied!==undefined?' [concessionApplied='+x.concessionApplied+']':''));
      });
    } else {
      [...found.entries()].forEach(([id,x])=>{
        if(col==='feeConcessions') console.log('      '+id+': scope='+g(x,'scope')+' target='+g(x,'target')+' type='+g(x,'type')+' value='+g(x,'value')+' status='+g(x,'status','active'));
        else if(col==='studentFeeSummary') console.log('      '+id+': totalNet='+g(x,'totalNet','totalDemanded')+' totalPaid='+g(x,'totalPaid')+' totalDiscount='+g(x,'discountAmount','totalDiscount')+' dues='+g(x,'totalDues','totalBalance'));
        else if(col==='feeDefaulters') console.log('      '+id+': dues='+g(x,'totalDues','totalBalance')+' examBlocked='+g(x,'examBlocked')+' resultWithheld='+g(x,'resultWithheld'));
        else if(col==='feeReceipts'||col==='feeOnlineOrders'||col==='feeOnlinePayments'||col==='feeRefunds') console.log('      '+id+': amount='+g(x,'amount','netAmount','paidAmount')+' status='+g(x,'status'));
        else console.log('      '+id);
      });
    }
  }
  process.exit(0);
})().catch(e=>{console.error('  ERROR:',e.message);process.exit(1);});
