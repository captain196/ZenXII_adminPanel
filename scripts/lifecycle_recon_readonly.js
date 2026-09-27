/**
 * READ-ONLY reconnaissance for the Student Lifecycle Gate 2 harness.
 *
 * Establishes the facts the harness needs before it writes anything: which
 * sessions exist, which classes/sections are configured and have room, which
 * admin identity carries the rights the real endpoints demand, and what the
 * tenant's CURRENT financial totals are — so the harness can prove afterwards
 * that it disturbed none of them.
 *
 *   node scripts/lifecycle_recon_readonly.js --school=SCH_B56BB9A401
 *
 * Writes nothing. Prints no credential material.
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const SCHOOL = arg('school', 'SCH_B56BB9A401');

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  console.log(`LIFECYCLE RECON — ${SCHOOL} (READ ONLY)\n`);

  const school = (await db.collection('schools').doc(SCHOOL).get()).data() || {};
  const sessions = Array.isArray(school.sessions) ? school.sessions : [];
  console.log(`  sessions        : ${sessions.join(', ') || '(none)'}`);
  console.log(`  currentSession  : ${school.currentSession || '(unset)'}`);
  const classes = (Array.isArray(school.classes) ? school.classes : [])
    .map((c) => (typeof c === 'string' ? c : (c && (c.className || c.name || c.label)) || ''))
    .filter(Boolean);
  console.log(`  classes         : ${classes.join(', ')}`);

  // ── Sections, per session, with configured capacity ──────────────────
  const secSnap = await db.collection('sections').where('schoolId', '==', SCHOOL).get();
  const bySession = {};
  secSnap.forEach((d) => {
    const s = d.data() || {};
    const k = String(s.session || '?');
    (bySession[k] = bySession[k] || []).push(
      `${s.className || '?'}/${s.section || s.sectionCode || '?'}`
      + (s.maxStrength ? `(cap ${s.maxStrength})` : '')
    );
  });
  console.log(`\n  sections by session:`);
  for (const [sess, list] of Object.entries(bySession)) {
    console.log(`    ${sess}: ${list.length} — ${list.slice(0, 12).join(', ')}`
      + (list.length > 12 ? ` …+${list.length - 12}` : ''));
  }

  // ── Admin identities (ids + roles ONLY — never any secret field) ─────
  const adminSnap = await db.collection('adminUsers').where('schoolId', '==', SCHOOL).get()
    .catch(() => ({ empty: true, forEach: () => {} }));
  console.log(`\n  admin identities:`);
  let anyAdmin = false;
  adminSnap.forEach((d) => {
    const a = d.data() || {};
    anyAdmin = true;
    const roles = Array.isArray(a.staffRoles) ? a.staffRoles.join('|') : (a.role || a.adminRole || '?');
    console.log(`    ${d.id}  role=${roles}  status=${a.status || '?'}`);
  });
  if (!anyAdmin) console.log('    (none in adminUsers — check staff collection)');

  const staffSnap = await db.collection('staff').where('schoolId', '==', SCHOOL).get();
  console.log(`\n  staff with roles (${staffSnap.size} total):`);
  staffSnap.forEach((d) => {
    const s = d.data() || {};
    const roles = Array.isArray(s.staffRoles) ? s.staffRoles : [];
    if (!roles.length) return;
    console.log(`    ${s.staffId || d.id}  roles=${roles.join('|')}  status=${s.status || '?'}`);
  });

  // ── FINANCIAL BASELINE — the number the harness must not move ────────
  const demands = await db.collection('feeDemands').where('schoolId', '==', SCHOOL).get();
  let totalNet = 0, totalPaid = 0, frozen = 0, byStatus = {};
  demands.forEach((d) => {
    const f = d.data() || {};
    totalNet  += Number(f.netAmount ?? f.amount ?? 0);
    totalPaid += Number(f.paidAmount ?? 0);
    const st = String(f.status || '?');
    byStatus[st] = (byStatus[st] || 0) + 1;
    if (st === 'frozen') frozen++;
  });
  console.log(`\n  FINANCIAL BASELINE (must be identical after the harness):`);
  console.log(`    feeDemands      : ${demands.size}`);
  console.log(`    sum(netAmount)  : ${totalNet}`);
  console.log(`    sum(paidAmount) : ${totalPaid}`);
  console.log(`    by status       : ${JSON.stringify(byStatus)}`);

  const students = await db.collection('students').where('schoolId', '==', SCHOOL).get();
  const statuses = {};
  const sessionsSeen = {};
  students.forEach((s) => {
    const d = s.data() || {};
    statuses[String(d.status || '?')] = (statuses[String(d.status || '?')] || 0) + 1;
    sessionsSeen[String(d.session || '?')] = (sessionsSeen[String(d.session || '?')] || 0) + 1;
  });
  console.log(`\n  students          : ${students.size}`);
  console.log(`    by status       : ${JSON.stringify(statuses)}`);
  console.log(`    by session      : ${JSON.stringify(sessionsSeen)}`);

  console.log('\n  Nothing was written.');
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
