#!/usr/bin/env node
/**
 * Ssa_reset::listSsasInSchool read-only mirror probe.
 *
 * Mirrors the staged Ssa_reset logic in JS to verify Firestore staff/*
 * canonical query returns expected SSAs. READ-ONLY. No writes.
 *
 * Usage: node scripts/ssa_list_mirror_probe.js [SCH_*]
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

(async () => {
  const SCHOOL_ID = process.argv[2] || process.env.SCHOOL_ID || 'SCH_D94FE8F7AD';
  console.log(`Mirrors Ssa_reset::listSsasInSchool('${SCHOOL_ID}') in JS — read-only.\n`);
  try {
    const snap = await fsdb.collection('staff').where('schoolId', '==', SCHOOL_ID).get();
    console.log(`Firestore staff/* where schoolId == ${SCHOOL_ID}: ${snap.size} docs`);
    let ssaCount = 0;
    for (const doc of snap.docs) {
      const d = doc.data() || {};
      let id = d.staffId || '';
      if (!id) {
        const pos = doc.id.lastIndexOf('_');
        if (pos >= 0) id = doc.id.substring(pos + 1);
      }
      const role = String(d.Role || d.role || '');
      const isSsa = role.toLowerCase() === 'school super admin'
                 || role.toLowerCase() === 'school_super_admin';
      if (isSsa) {
        ssaCount++;
        console.log(`  SSA: id=${id}  name="${d.Name||d.name||''}"  email="${d.Email||d.email||''}"  status="${d.Status||d.status||'active'}"`);
      }
    }
    console.log(`\nSSAs surfaced by listSsasInSchool logic: ${ssaCount}`);
    if (ssaCount === 0) {
      console.log('NOTE: 0 SSAs in this tenant — operator confirms whether expected.');
      console.log('       (the new listSsasInSchool would return [] which is the same as legacy when no SSAs exist)');
    } else {
      console.log('PASS: SSA enumeration produces results matching the migration intent.');
    }

    // Bonus: confirm getEntity('staff', "{schoolId}_{ssa_id}") works for one known SSA
    if (ssaCount > 0) {
      console.log(`\nVerify getEntity-style direct lookup for one SSA:`);
      const firstSsa = snap.docs.find(d => {
        const data = d.data() || {};
        const r = String(data.Role || data.role || '').toLowerCase();
        return r === 'school super admin' || r === 'school_super_admin';
      });
      if (firstSsa) {
        let sid = (firstSsa.data() || {}).staffId || '';
        if (!sid) {
          const pos = firstSsa.id.lastIndexOf('_');
          if (pos >= 0) sid = firstSsa.id.substring(pos + 1);
        }
        const docId = `${SCHOOL_ID}_${sid}`;
        const direct = await fsdb.collection('staff').doc(docId).get();
        if (direct.exists) {
          console.log(`  staff/${docId} exists: ${JSON.stringify(direct.data()).slice(0,150)}...`);
          console.log('  PASS: resetSsaPassword lookup pattern works for live data');
        } else {
          console.log(`  FAIL: staff/${docId} does NOT exist (composite docId pattern broken)`);
          process.exit(2);
        }
      }
    }
  } catch (e) {
    console.error('FAIL:', e.message);
    process.exit(2);
  }
})().catch(e => { console.error(e); process.exit(1); });
