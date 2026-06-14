const path=require('path');const fs=require('fs');
const admin=require(path.resolve(__dirname,'..','firebase-rules','tests','node_modules','firebase-admin'));
const SVC=JSON.parse(fs.readFileSync(path.resolve(__dirname,'..','application','config','graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'),'utf8'));
admin.initializeApp({credential:admin.credential.cert(SVC)});
(async()=>{
  console.log('staffAttendance sample (per-day):');
  const a=await admin.firestore().collection('staffAttendance').limit(2).get();
  a.forEach(d=>{console.log('---',d.id);console.log(JSON.stringify(d.data(),null,2));});
  console.log('\nstaffAttendanceSummary sample (monthly):');
  const s=await admin.firestore().collection('staffAttendanceSummary').limit(2).get();
  s.forEach(d=>{console.log('---',d.id);console.log(JSON.stringify(d.data(),null,2));});
  console.log('\nstaffAttendanceMeta sample:');
  const m=await admin.firestore().collection('staffAttendanceMeta').limit(2).get();
  m.forEach(d=>{console.log('---',d.id);console.log(JSON.stringify(d.data(),null,2));});
})().catch(e=>{console.error(e);process.exit(1);});
