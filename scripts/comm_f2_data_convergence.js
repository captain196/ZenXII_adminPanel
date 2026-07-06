#!/usr/bin/env node
/**
 * COMM-F2 — one-shot per-tenant commCounters data convergence.
 *
 * Brings the schools/{schoolId}_profile counter state to the V7 canonical
 * single nested-map shape:
 *   commCounters: { Notice: N, Queue: N, Circular: N, ... }
 *
 * For every legacy flat top-level field `commCounters.{type}`:
 *   1. Compute target = max(nested.{type} ?? 0, flat ?? 0, canonical-collection scan max).
 *   2. Write nested.{type} = target.
 *   3. Delete the flat top-level field.
 *
 * Firestore-only. Idempotent. Per-tenant. READ + WRITE.
 *
 * Usage:
 *   node scripts/comm_f2_data_convergence.js              (dry-run all tenants)
 *   node scripts/comm_f2_data_convergence.js --apply      (apply all tenants)
 *   node scripts/comm_f2_data_convergence.js SCH_X        (dry-run one tenant)
 *   node scripts/comm_f2_data_convergence.js SCH_X --apply
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

// commCounters {type} → canonical collection name + ID prefix (mirrors
// Communication.php::COUNTER_SEED_SOURCES + Communication_helper).
const SEED = {
  Conversation: { col: 'conversations',      prefix: 'CONV' },
  Message:      { col: 'messages',           prefix: 'MSG'  },
  Notice:       { col: 'notices',            prefix: 'NOT'  },
  Circular:     { col: 'circulars',          prefix: 'CIR'  },
  Template:     { col: 'messageTemplates',   prefix: 'TPL'  },
  Trigger:      { col: 'alertTriggers',      prefix: 'TRG'  },
  Log:          { col: 'deliveryLogs',       prefix: 'LOG'  },
  Queue:        { col: 'messageQueue',       prefix: 'QUE'  },
};

const HR = () => console.log('═'.repeat(78));

async function scanMaxPrefix(schoolId, collection, prefix) {
  const snap = await fsdb.collection(collection)
    .where('schoolId', '==', schoolId).limit(500).get();
  const re = new RegExp(`^${prefix}(\\d+)$`);
  let max = 0;
  snap.forEach(s => {
    const data = s.data() || {};
    // Try `id` field first; fall back to docId minus schoolPrefix.
    let raw = String(data.id || data.noticeId || data.circularId || data.queueId || '');
    if (!raw) {
      const did = s.id;
      raw = did.startsWith(schoolId + '_') ? did.slice(schoolId.length + 1) : did;
    }
    const m = raw.match(re);
    if (m) {
      const n = parseInt(m[1], 10);
      if (n > max) max = n;
    }
  });
  return max;
}

async function convergeTenant(schoolId, apply) {
  console.log(`\n── ${schoolId} ${apply ? '(APPLY)' : '(DRY-RUN)'} ──`);
  const ref = fsdb.collection('schools').doc(`${schoolId}_profile`);
  const snap = await ref.get();
  if (!snap.exists) {
    console.log(`  no profile doc — skipped`);
    return { schoolId, status: 'NO_PROFILE' };
  }
  const data = snap.data() || {};

  // Inventory flat keys.
  const flatKeys = Object.keys(data).filter(k => k.startsWith('commCounters.'));
  const nested = (data.commCounters && typeof data.commCounters === 'object') ? data.commCounters : {};

  if (flatKeys.length === 0) {
    console.log(`  zero flat keys present — already converged`);
    return { schoolId, status: 'ALREADY_CONVERGED' };
  }

  // Compute target value per type.
  const plan = [];
  for (const fk of flatKeys) {
    const type = fk.replace('commCounters.', '');
    const flatVal = Number(data[fk]) || 0;
    const nestedVal = Number(nested[type]) || 0;
    const seed = SEED[type] || null;
    let scanMax = 0;
    if (seed) {
      try { scanMax = await scanMaxPrefix(schoolId, seed.col, seed.prefix); }
      catch (e) { console.log(`    scan(${seed.col}) failed: ${e.message}`); }
    }
    const target = Math.max(nestedVal, flatVal, scanMax);
    plan.push({ type, flatVal, nestedVal, scanMax, target });
  }

  console.log(`  flat keys to converge: ${flatKeys.length}`);
  for (const p of plan) {
    console.log(`    ${p.type.padEnd(12)} flat=${String(p.flatVal).padStart(4)} nested=${String(p.nestedVal).padStart(4)} scan=${String(p.scanMax).padStart(4)} → target=${p.target}`);
  }

  if (!apply) {
    console.log(`  (dry-run; pass --apply to write)`);
    return { schoolId, status: 'DRY_RUN', plan };
  }

  // Apply: set nested.{type} = target for every planned type AND delete the flat key.
  // We use FieldValue.delete() for the flat keys. The nested map is written
  // via dot-path field updates so sibling counter values are preserved.
  const update = {};
  for (const p of plan) {
    update[`commCounters.${p.type}`] = p.target;
  }
  // First pass: write nested values.
  await ref.update(update);

  // Second pass: delete legacy flat top-level fields by name. Field names
  // with literal dots must be wrapped in admin.firestore.FieldPath using
  // an array of segments so the dot isn't treated as a path separator.
  const deletes = {};
  for (const fk of flatKeys) {
    // Use FieldPath with single literal segment to delete the dotted top-level field.
    deletes[fk] = admin.firestore.FieldValue.delete();
  }
  // Use FieldPath construction per-field — Firestore Node SDK treats string
  // keys in update() as dot-separated paths. We must call update() with
  // alternating (FieldPath, value) arguments to delete a top-level field
  // whose name literally contains a dot.
  const updateArgs = [];
  for (const fk of flatKeys) {
    updateArgs.push(new admin.firestore.FieldPath(fk));
    updateArgs.push(admin.firestore.FieldValue.delete());
  }
  await ref.update(...updateArgs);

  console.log(`  ✓ converged: ${plan.length} field(s)`);
  return { schoolId, status: 'CONVERGED', plan };
}

(async () => {
  const args = process.argv.slice(2);
  const apply = args.includes('--apply');
  const tenantArg = args.find(a => /^SCH_/i.test(a)) || null;

  HR();
  console.log(`  COMM-F2 commCounters data convergence  ${apply ? '(APPLY)' : '(DRY-RUN)'}`);
  HR();

  let tenants;
  if (tenantArg) {
    tenants = [tenantArg];
  } else {
    const sn = await fsdb.collection('schools').limit(200).get();
    const set = new Set();
    sn.forEach(s => {
      const m = s.id.match(/^(SCH_[A-F0-9]+)/i);
      if (m) set.add(m[1]);
    });
    tenants = [...set].sort();
  }
  console.log(`\n  target tenant(s): ${tenants.length}`);

  const results = [];
  for (const t of tenants) {
    try {
      results.push(await convergeTenant(t, apply));
    } catch (e) {
      console.error(`  ${t}: FATAL — ${e.message}`);
      results.push({ schoolId: t, status: 'ERROR', error: e.message });
    }
  }

  console.log('\n' + '─'.repeat(78));
  console.log('  Roll-up:');
  for (const r of results) {
    console.log(`    ${r.schoolId.padEnd(20)} ${r.status}`);
  }
  HR();
  process.exit(0);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
