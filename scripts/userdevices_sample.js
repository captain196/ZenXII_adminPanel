const path=require('path');const fs=require('fs');
const admin=require(path.resolve(__dirname,'..','firebase-rules','tests','node_modules','firebase-admin'));
const SVC=JSON.parse(fs.readFileSync(path.resolve(__dirname,'..','application','config','graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'),'utf8'));
admin.initializeApp({credential:admin.credential.cert(SVC)});
(async()=>{const snap=await admin.firestore().collection('userDevices').limit(3).get();
snap.forEach(d=>{console.log('---',d.id);console.log(JSON.stringify(d.data(),null,2));});})();
