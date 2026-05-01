// Phase 2.5 Step 2 — migrate legacy demand IDs (name-slug based) to the
// new feeHeadId-based format. Runs AFTER assign_fee_head_ids.js.
//
// Old: DEM_{studentId}_{YYYYMM}_{FEE_HEAD_SLUG}   (e.g. DEM_STU0001_202604_TUITION_FEE)
// New: DEM_{studentId}_{YYYYMM}_{feeHeadId}       (e.g. DEM_STU0001_202604_FH_57FF8A259BF6)
//
// Strategy (safe):
//   1. For each existing demand doc,
//      - look up the structure for its class/section/session
//      - find the feeHead by name in that structure → get feeHeadId
//      - compute the new demandId
//      - if new ID == current ID → no-op
//      - if new ID != current ID → copy data to new doc, delete old doc
//   2. Preserves ALL payment state (paidAmount / balance / status / lastReceipt)
//   3. Idempotent — running twice is a no-op
//   4. camelCase-only write (strips snake aliases while we're rewriting)
//
//   node scripts/migrate_demand_ids_to_feehead.js dry-run
//   node scripts/migrate_demand_ids_to_feehead.js live

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SNAKE_KEYS = [
  'student_id','student_name','demand_id','fee_head','period_key','period_type',
  'original_amount','discount_amount','fine_amount','net_amount','paid_amount',
  'due_date','created_at','created_by','generated_at',
];

function normalizeSnakeToCamel(d) {
  const out = Object.assign({}, d);
  const map = {
    student_id:'studentId', student_name:'studentName', demand_id:'demandId',
    fee_head:'feeHead', period_key:'periodKey', period_type:'periodType',
    original_amount:'grossAmount', discount_amount:'discountAmount',
    fine_amount:'fineAmount', net_amount:'netAmount', paid_amount:'paidAmount',
    due_date:'dueDate', created_at:'createdAt', created_by:'createdBy',
    generated_at:'generatedAt',
  };
  for (const [snake, camel] of Object.entries(map)) {
    if (out[snake] !== undefined && out[camel] === undefined) out[camel] = out[snake];
  }
  // Strip snake aliases — the new doc is single-source camelCase.
  for (const k of SNAKE_KEYS) delete out[k];
  return out;
}

function buildNewDemandId(studentId, periodKey, feeHeadId) {
  const sid = String(studentId).replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '');
  const ym  = String(periodKey).replace(/-/g, '');
  return `DEM_${sid}_${ym}_${feeHeadId}`;
}

(async () => {
  const mode = (process.argv[2] || '').toLowerCase();
  if (!['dry-run','live'].includes(mode)) {
    console.log('Usage: node scripts/migrate_demand_ids_to_feehead.js dry-run|live');
    process.exit(2);
  }

  // Pre-load every structure's (schoolId+session+className+section) → name→feeHeadId map.
  const structSnap = await db.collection('feeStructures').get();
  const headIdByStruct = new Map(); // key = `${schoolId}|${session}|${className}|${section}`
  structSnap.docs.forEach(d => {
    const x = d.data() || {};
    const key = [x.schoolId, x.session, x.className, x.section].join('|');
    const heads = Array.isArray(x.feeHeads) ? x.feeHeads : [];
    const m = {};
    heads.forEach(h => { if (h && h.name && h.feeHeadId) m[h.name] = h.feeHeadId; });
    headIdByStruct.set(key, m);
  });

  const demandSnap = await db.collection('feeDemands').get();
  console.log(`Scanning ${demandSnap.size} feeDemands...\n`);

  let renamed = 0, unchanged = 0, missingHeadId = 0, structureMissing = 0;
  let batch = db.batch();
  let batchOps = 0;

  for (const doc of demandSnap.docs) {
    const d = doc.data() || {};
    const studentId   = d.studentId   || d.student_id;
    const className   = d.className   || d.class;
    const section     = d.section;
    const session     = d.session;
    const schoolId    = d.schoolId;
    const periodKey   = d.periodKey   || d.period_key;
    const feeHead     = d.feeHead     || d.fee_head;

    if (!studentId || !periodKey || !feeHead) {
      console.warn(`  SKIP ${doc.id} — missing studentId/periodKey/feeHead`);
      continue;
    }

    const structKey = [schoolId, session, className, section].join('|');
    const map = headIdByStruct.get(structKey);
    if (!map) { structureMissing++; console.warn(`  SKIP ${doc.id} — no structure for ${structKey}`); continue; }

    const feeHeadId = map[feeHead];
    if (!feeHeadId) { missingHeadId++; console.warn(`  SKIP ${doc.id} — head "${feeHead}" has no feeHeadId in ${structKey}`); continue; }

    const newId = buildNewDemandId(studentId, periodKey, feeHeadId);
    if (newId === doc.id) { unchanged++; continue; }

    const newData = normalizeSnakeToCamel(d);
    newData.demandId  = newId;
    newData.feeHeadId = feeHeadId;
    newData.updatedAt = new Date().toISOString();

    console.log(`  RENAME ${doc.id} → ${newId}`);
    if (mode === 'live') {
      batch.set(db.collection('feeDemands').doc(newId), newData);
      batch.delete(doc.ref);
      batchOps += 2;
      if (batchOps >= 400) { await batch.commit(); batch = db.batch(); batchOps = 0; }
    }
    renamed++;
  }

  if (mode === 'live' && batchOps > 0) await batch.commit();

  console.log(`\n──────────────────────────────────────────────`);
  console.log(`Mode:               ${mode.toUpperCase()}`);
  console.log(`Demands scanned:    ${demandSnap.size}`);
  console.log(`Renamed:            ${renamed}`);
  console.log(`Already correct:    ${unchanged}`);
  console.log(`Structure missing:  ${structureMissing}`);
  console.log(`Head ID missing:    ${missingHeadId}`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
