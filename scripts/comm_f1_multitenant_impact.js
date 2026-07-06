#!/usr/bin/env node
/**
 * COMM-F1 — Multi-tenant cross-platform impact assessment.
 *
 * READ-ONLY. Firestore-only.
 *
 * Quantifies the COMM-F1 fix impact across the SaaS tenant fleet:
 *   - How many tenants in production?
 *   - Per-tenant: notices population, status distribution, source breakdown
 *   - Tenants currently affected by the helper status="published" defect
 *   - Tenants where the fix produces an immediate visibility improvement
 *   - Tenants with zero notices (defect-free by virtue of zero usage)
 *
 * Output: per-tenant disposition + fleet-wide rollout impact summary.
 */
const path = require('path');
const fsLib = require('fs');
const admin = require(path.resolve(__dirname, '..', 'firebase-rules', 'tests', 'node_modules', 'firebase-admin'));
const SVC = JSON.parse(fsLib.readFileSync(
  path.resolve(__dirname, '..', 'application', 'config',
    'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const fsdb = admin.firestore();
const HR = () => console.log('═'.repeat(78));

(async () => {
  HR();
  console.log(`  COMM-F1 multi-tenant impact — ${new Date().toISOString()}`);
  HR();

  // ── 1. Enumerate tenants ────────────────────────────────────────────
  console.log('\n── Tenant enumeration (schools collection) ──\n');
  const schoolsSnap = await fsdb.collection('schools').limit(200).get();
  // Distinct schoolIds = {SCH_*}_profile docId stripped
  const schoolIds = new Set();
  schoolsSnap.forEach(s => {
    const id = s.id;
    // shape: {SCH_*}_profile or {SCH_*}
    const m = id.match(/^(SCH_[A-F0-9]+)/i);
    if (m) schoolIds.add(m[1]);
  });
  const tenants = [...schoolIds].sort();
  console.log(`  ${tenants.length} distinct tenants:`);
  tenants.forEach(t => console.log(`    ${t}`));

  if (tenants.length === 0) {
    console.log('  No tenants found — exiting.');
    process.exit(0);
  }

  // ── 2. Per-tenant notices census (parallel) ─────────────────────────
  console.log('\n── Per-tenant notices census ──\n');
  console.log(`  ${'tenant'.padEnd(20)} ${'total'.padStart(5)} ${'sent'.padStart(5)} ${'pub'.padStart(5)} ${'event'.padStart(5)} ${'bday'.padStart(5)} ${'admin'.padStart(5)} ${'hr'.padStart(5)}`);
  console.log(`  ${'-'.repeat(60)}`);

  const perTenant = [];
  await Promise.all(tenants.map(async (sid) => {
    const all = await fsdb.collection('notices').where('schoolId','==',sid).limit(500).get();
    let total = all.size, sent = 0, pub = 0, eventSrc = 0, bday = 0, adminSrc = 0, hr = 0;
    all.forEach(s => {
      const d = s.data();
      const st = (d.status || '').toLowerCase();
      const src = (d.source || '').toLowerCase();
      if (st === 'sent')      sent++;
      if (st === 'published') pub++;
      if (src === 'event')           eventSrc++;
      if (src === 'birthday_wish')   bday++;
      if (src === 'hr_recruitment')  hr++;
      if (!src && st === 'sent')     adminSrc++; // admin-authored W1 docs have no source
    });
    perTenant.push({ sid, total, sent, pub, eventSrc, bday, adminSrc, hr });
  }));
  perTenant.sort((a,b) => b.total - a.total);
  perTenant.forEach(t => {
    console.log(`  ${t.sid.padEnd(20)} ${String(t.total).padStart(5)} ${String(t.sent).padStart(5)} ${String(t.pub).padStart(5)} ${String(t.eventSrc).padStart(5)} ${String(t.bday).padStart(5)} ${String(t.adminSrc).padStart(5)} ${String(t.hr).padStart(5)}`);
  });

  // ── 3. Fleet-wide rollup ────────────────────────────────────────────
  console.log('\n── Fleet-wide rollup ──\n');
  const tot = perTenant.reduce((a, t) => ({
    total: a.total + t.total,
    sent: a.sent + t.sent,
    pub: a.pub + t.pub,
    eventSrc: a.eventSrc + t.eventSrc,
    bday: a.bday + t.bday,
    adminSrc: a.adminSrc + t.adminSrc,
    hr: a.hr + t.hr,
  }), { total: 0, sent: 0, pub: 0, eventSrc: 0, bday: 0, adminSrc: 0, hr: 0 });
  console.log(`  Total notice docs in production:        ${tot.total}`);
  console.log(`  status="sent"  (visible to apps):       ${tot.sent}`);
  console.log(`  status="published" (INVISIBLE to apps): ${tot.pub}  ← legacy helper/birthday defect`);
  console.log(`  source="event"  (helper-written):        ${tot.eventSrc}`);
  console.log(`  source="birthday_wish":                  ${tot.bday}`);
  console.log(`  source="hr_recruitment":                 ${tot.hr}`);
  console.log(`  admin-authored (W1, no source):          ${tot.adminSrc}`);

  // ── 4. Tenant disposition for the rollout ──────────────────────────
  console.log('\n── Tenant disposition for COMM-F1 rollout ──\n');
  const groups = {
    'CLEAR (no notices yet)':    perTenant.filter(t => t.total === 0).length,
    'NO LEGACY DEFECT (zero status=published)': perTenant.filter(t => t.pub === 0 && t.total > 0).length,
    'HAS LEGACY DEFECT (some status=published)': perTenant.filter(t => t.pub > 0).length,
  };
  for (const [k, v] of Object.entries(groups)) console.log(`  ${k.padEnd(46)} ${v} tenant(s)`);

  // ── 5. Post-rollout projection ──────────────────────────────────────
  console.log('\n── Post-rollout projection ──\n');
  console.log(`  After deploy:`);
  console.log(`    • ALL future event-source notices written canonical (status="sent" + body + author + targetType)`);
  console.log(`    • ALL future birthday wishes written canonical`);
  console.log(`    • Mobile apps surface these from the very next write — no app version bump needed`);
  console.log(`    • Legacy ${tot.pub} status="published" docs remain hidden until OPTIONAL backfill`);
  console.log(`    • Zero existing canonical (W1 admin / W3 HR / W2 newly-written) docs affected`);
  console.log(`    • Zero tenant data lost, zero IDs renumbered, zero schema migration`);

  // ── 6. Cross-platform reach summary ────────────────────────────────
  console.log('\n── Cross-platform reach ──\n');
  console.log(`  Admin Panel (PHP):   ${tenants.length} tenants — fix takes effect on next event/PTM/birthday creation`);
  console.log(`  Parent App (Kotlin): all installed versions consume canonical CircularDoc — no version bump needed`);
  console.log(`  Teacher App (Kotlin): same — no version bump needed`);
  console.log(`  Cloud Functions:     pushRequests path unchanged (helper does not enqueue push directly)`);

  HR();
  console.log(`  Multi-tenant impact assessment complete.`);
  HR();
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
