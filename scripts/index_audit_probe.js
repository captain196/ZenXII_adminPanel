#!/usr/bin/env node
/**
 * Firestore index audit probe — verify staged firestore.indexes.json
 * declarations are deployed live by issuing the minimum-triggering query
 * for each declared composite index.
 *
 * If a query succeeds (regardless of result count), the index OR a covering
 * one is deployed. If it fails with FAILED_PRECONDITION, the index is missing.
 *
 * READ-ONLY. No mutations.
 *
 * Usage: node scripts/index_audit_probe.js
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();

const SCHOOL_ID = 'SCH_D94FE8F7AD';
const SESSION   = '2026-27';
const STUDENT_ID = 'STU0001';
const EXAM_ID    = 'EXAM_PROBE_TEST';
const CLASS_NAME = 'Class 10';
const SECTION    = 'A';

// Each probe: { domain, code, collection, query: function(collection) -> Query }
// The query MUST exercise the composite index's fields in their declared order.
const PROBES = [
  // ── EXAM DOMAIN (12 indexes) ──────────────────────────────────────────
  {
    domain: 'Exam', code: 'E-01',
    collection: 'examTemplates',
    description: '(schoolId, examId, className, section)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('className','==',CLASS_NAME).where('section','==',SECTION).limit(1),
  },
  {
    domain: 'Exam', code: 'E-02',
    collection: 'marks',
    description: '(schoolId, examId, className, section)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('className','==',CLASS_NAME).where('section','==',SECTION).limit(1),
  },
  {
    domain: 'Exam', code: 'E-03',
    collection: 'marks',
    description: '(schoolId, examId, className, section, subject)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('className','==',CLASS_NAME).where('section','==',SECTION).where('subject','==','Math').limit(1),
  },
  {
    domain: 'Exam', code: 'E-04',
    collection: 'marks',
    description: '(schoolId, studentId)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('studentId','==',STUDENT_ID).limit(1),
  },
  {
    domain: 'Exam', code: 'E-05',
    collection: 'results',
    description: '(schoolId, examId, className, section)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('className','==',CLASS_NAME).where('section','==',SECTION).limit(1),
  },
  {
    domain: 'Exam', code: 'E-06',
    collection: 'results',
    description: '(schoolId, examId, className, section, rank)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('className','==',CLASS_NAME).where('section','==',SECTION).orderBy('rank','asc').limit(1),
  },
  {
    domain: 'Exam', code: 'E-07',
    collection: 'results',
    description: '(schoolId, examId, studentId)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('studentId','==',STUDENT_ID).limit(1),
  },
  {
    domain: 'Exam', code: 'E-08',
    collection: 'results',
    description: '(schoolId, session, studentId)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('session','==',SESSION).where('studentId','==',STUDENT_ID).limit(1),
  },
  {
    domain: 'Exam', code: 'E-09',
    collection: 'resultsStaging',
    description: '(schoolId, examId, className, section)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('className','==',CLASS_NAME).where('section','==',SECTION).limit(1),
  },
  {
    domain: 'Exam', code: 'E-10',
    collection: 'marksAudit',
    description: '(schoolId, examId, studentId, timestamp DESC)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).where('studentId','==',STUDENT_ID).orderBy('timestamp','desc').limit(1),
  },
  {
    domain: 'Exam', code: 'E-11',
    collection: 'resultsAudit',
    description: '(schoolId, examId, timestamp DESC)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('examId','==',EXAM_ID).orderBy('timestamp','desc').limit(1),
  },
  {
    domain: 'Exam', code: 'E-12',
    collection: 'exams',
    description: '(schoolId, session, status)',
    build: (c) => c.where('schoolId','==',SCHOOL_ID).where('session','==',SESSION).where('status','==','active').limit(1),
  },

  // ── SECURITY DOMAIN (1 index) ─────────────────────────────────────────
  {
    domain: 'Security', code: 'S-01',
    collection: 'security_events',
    description: '(event_type, detail.adminId, ts DESC)',
    build: (c) => c.where('event_type','==','ADMIN_LOGIN_SUCCESS').where('detail.adminId','==','SUP0001').orderBy('ts','desc').limit(1),
  },
];

(async () => {
  console.log('═'.repeat(78));
  console.log('  Firestore Index Audit — Exam + Security declarations');
  console.log('  Time:', new Date().toISOString());
  console.log('═'.repeat(78));

  const results = [];
  for (const p of PROBES) {
    let verdict, detail;
    try {
      const snap = await p.build(fsdb.collection(p.collection)).get();
      verdict = 'DEPLOYED';
      detail = `query succeeded; returned ${snap.size} docs`;
    } catch (e) {
      const msg = (e && e.message) ? e.message : String(e);
      if (/FAILED_PRECONDITION|requires an index|composite index/i.test(msg)) {
        verdict = 'MISSING';
        const m = msg.match(/https:\/\/[^\s)]+/);
        detail = m ? `MISSING; create at ${m[0]}` : `MISSING; ${msg.slice(0, 200)}`;
      } else {
        verdict = 'ERROR';
        detail = msg.slice(0, 200);
      }
    }
    results.push({ ...p, verdict, detail });
    const tag = verdict === 'DEPLOYED' ? '✓ DEPLOYED' : (verdict === 'MISSING' ? '✗ MISSING ' : '! ERROR   ');
    console.log(`  ${tag}  [${p.domain} ${p.code}] ${p.collection} ${p.description}`);
    if (verdict !== 'DEPLOYED') console.log(`             └─ ${detail}`);
  }

  console.log('');
  console.log('═'.repeat(78));
  console.log('  Summary');
  console.log('═'.repeat(78));
  const byDomain = {};
  for (const r of results) {
    if (!byDomain[r.domain]) byDomain[r.domain] = { deployed: 0, missing: 0, error: 0, total: 0 };
    byDomain[r.domain].total++;
    if (r.verdict === 'DEPLOYED') byDomain[r.domain].deployed++;
    else if (r.verdict === 'MISSING') byDomain[r.domain].missing++;
    else byDomain[r.domain].error++;
  }
  for (const [d, c] of Object.entries(byDomain)) {
    console.log(`  ${d}: ${c.deployed}/${c.total} deployed, ${c.missing} missing, ${c.error} error`);
  }

  const totalMissing = results.filter(r => r.verdict === 'MISSING').length;
  const totalError   = results.filter(r => r.verdict === 'ERROR').length;
  console.log('');
  if (totalMissing === 0 && totalError === 0) {
    console.log('  OVERALL: ALL DECLARED INDEXES ARE DEPLOYED LIVE');
    process.exit(0);
  } else {
    console.log(`  OVERALL: ${totalMissing} MISSING, ${totalError} ERROR`);
    process.exit(2);
  }
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
