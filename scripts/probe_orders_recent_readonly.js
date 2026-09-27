// READ-ONLY. Which sessions exist, and were any orders created today?
const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();
const SCHOOL = 'SCH_B56BB9A401';

(async () => {
  const out = {};

  const snap = await db.collection('feeOnlineOrders').where('schoolId','==',SCHOOL).get();
  const rows = [];
  snap.forEach(d => { const x=d.data(); rows.push({
    id:d.id, student:x.studentId||x.student_id, session:x.session||x.sessionYear||null,
    status:x.status, amount:x.amount,
    gw:(x.gateway_order_id||x.gatewayOrderId||''),
    created:x.created_at||x.createdAt||null }); });
  rows.sort((a,b)=>String(b.created).localeCompare(String(a.created)));
  out.totalOrdersThisSchool = rows.length;
  out.recent = rows.slice(0,10);
  out.realRazorpayOrders = rows.filter(r=>String(r.gw).startsWith('order_')).length;
  out.mockOrders = rows.filter(r=>String(r.gw).startsWith('ORD_')).length;

  // Which session does the school think it is in?
  const sc = await db.collection('schoolControl').doc(SCHOOL).get();
  out.currentSession = sc.exists ? (sc.data().currentSession || null) : 'schoolControl ABSENT';

  const sch = await db.collection('schools').doc(SCHOOL).get();
  out.schoolSessions = sch.exists ? (sch.data().sessions || null) : null;

  // Every gateway doc that exists for this school
  const gw = await db.collection('feeSettings').get();
  out.gatewayDocs = [];
  gw.forEach(d => { if (d.id.startsWith(SCHOOL) && d.id.endsWith('_gateway')) {
    const c=d.data();
    out.gatewayDocs.push({ id:d.id, provider:c.provider, mode:c.mode, active:c.active,
      keyPrefix:String(c.api_key||'').slice(0,14) });
  }});

  console.log(JSON.stringify(out,null,2));
  process.exit(0);
})().catch(e=>{console.error('PROBE FAILED:',e.message);process.exit(1);});
