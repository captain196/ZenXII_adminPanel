#!/usr/bin/env node
/**
 * Phase 0.2B — one-off, READ-ONLY verification of the EXACT subjectAssignments
 * document-ID format for the active tenant, to precisely construct the
 * Firestore rules get() path for G-09. No writes anywhere in this file.
 *
 *   SCHOOL_ID=SCH_B56BB9A401 node scripts/p02b_docid_format_verify.js
 */
const admin = require('firebase-admin');
const path = require('path');
const SA = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');
admin.initializeApp({ credential: admin.credential.cert(require(SA)) });
const db = admin.firestore();
const SCHOOL_ID = process.env.SCHOOL_ID || 'SCH_B56BB9A401';

(async () => {
  console.log(`Inspecting subjectAssignments doc IDs for ${SCHOOL_ID} (READ-ONLY)\n`);
  const snap = await db.collection('subjectAssignments')
    .where('schoolId', '==', SCHOOL_ID).limit(8).get();
  snap.forEach((d) => {
    const v = d.data();
    console.log(`docId="${d.id}"`);
    console.log(`  session="${v.session}" className="${v.className}" section="${v.section}" sectionKey="${v.sectionKey}" subjectCode="${v.subjectCode}" teacherId="${v.teacherId}" archived=${v.archived}`);
  });
  console.log('\n--- Cross-check: a real examSchedule doc for the same tenant ---');
  const sched = await db.collection('examSchedule').where('schoolId', '==', SCHOOL_ID).limit(1).get();
  sched.forEach((d) => {
    const v = d.data();
    console.log(`examSchedule docId="${d.id}" className="${v.className}" section="${v.section}"`);
  });
  console.log('\n=== End — no writes performed ===');
})().catch((e) => { console.error('ERROR:', e.message); process.exit(1); });
