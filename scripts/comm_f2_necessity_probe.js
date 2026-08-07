#!/usr/bin/env node
/**
 * COMM-F2 necessity probe — read-only, Firestore-only.
 *
 * Verifies whether Communication::_next_id is currently functional or
 * stuck due to the flat-vs-nested commCounters drift. Per-tenant report.
 *
 * READ-ONLY. admin.firestore() only — zero RTDB.
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
  console.log('═'.repeat(78));
  console.log('  COMM-F2 necessity probe — counter dual-shape state per tenant');
  console.log('═'.repeat(78));

  // Enumerate tenants
  const schools = await fsdb.collection('schools').limit(200).get();
  const tenants = new Set();
  schools.forEach(s => {
    const m = s.id.match(/^(SCH_[A-F0-9]+)/i);
    if (m) tenants.add(m[1]);
  });

  // Pull profile per tenant
  const findings = [];
  for (const sid of [...tenants].sort()) {
    const profile = await fsdb.collection('schools').doc(`${sid}_profile`).get();
    if (!profile.exists) { findings.push({ sid, status: 'NO_PROFILE' }); continue; }
    const d = profile.data() || {};

    // Flat keys: any top-level field starting with "commCounters."
    const flatKeys = Object.keys(d).filter(k => k.startsWith('commCounters.'));
    const flat = {};
    for (const k of flatKeys) flat[k.replace('commCounters.', '')] = d[k];

    // Nested map
    const nested = (d.commCounters && typeof d.commCounters === 'object') ? d.commCounters : {};

    // Determine per-counter-type drift
    const allTypes = new Set([...Object.keys(flat), ...Object.keys(nested)]);
    const perType = {};
    let stuckCount = 0, alignedCount = 0;
    for (const t of allTypes) {
      const f = flat[t];
      const n = nested[t];
      let verdict;
      if (f === undefined && n !== undefined) verdict = 'CLEAN_NESTED_ONLY';   // self-heal works
      else if (f !== undefined && n === undefined) verdict = 'LEGACY_FLAT_ONLY'; // _next_id may work but writes will drift
      else if (f !== undefined && n !== undefined && f === n) verdict = 'ALIGNED';
      else if (f !== undefined && n !== undefined && f !== n) verdict = 'DRIFTED_STUCK'; // Communication::_next_id reads stale flat
      else verdict = 'UNKNOWN';
      perType[t] = { flat: f, nested: n, verdict };
      if (verdict === 'DRIFTED_STUCK') stuckCount++;
      if (verdict === 'ALIGNED' || verdict === 'CLEAN_NESTED_ONLY') alignedCount++;
    }

    findings.push({ sid, flatKeyCount: flatKeys.length, types: perType, stuckCount, alignedCount });
  }

  // Report per-tenant
  console.log('\n── Per-tenant counter dual-shape census ──\n');
  console.log(`  ${'tenant'.padEnd(20)} ${'flat'.padStart(5)} ${'stuck'.padStart(6)} types(verdict)`);
  console.log(`  ${'-'.repeat(76)}`);
  for (const f of findings) {
    if (f.status === 'NO_PROFILE') { console.log(`  ${f.sid.padEnd(20)} (no profile doc)`); continue; }
    const typesSummary = Object.entries(f.types).map(([t, v]) =>
      `${t}=${v.verdict.replace('_', '')}(f=${v.flat ?? '?'},n=${v.nested ?? '?'})`
    ).join(', ');
    const stuckTag = f.stuckCount > 0 ? `${f.stuckCount} STUCK` : 'clean';
    console.log(`  ${f.sid.padEnd(20)} ${String(f.flatKeyCount).padStart(5)} ${stuckTag.padStart(6)} ${typesSummary || '(no counters)'}`);
  }

  // Fleet rollup
  console.log('\n── Fleet rollup ──\n');
  const tot = findings.filter(f => !f.status).reduce((a, f) => ({
    flat: a.flat + f.flatKeyCount,
    stuck: a.stuck + f.stuckCount,
    aligned: a.aligned + f.alignedCount,
  }), { flat: 0, stuck: 0, aligned: 0 });
  console.log(`  Tenants with profile docs:     ${findings.filter(f => !f.status).length}`);
  console.log(`  Total flat-keyed commCounters:  ${tot.flat}`);
  console.log(`  Drifted/stuck counter types:    ${tot.stuck}  (Communication::_next_id reads stale value here)`);
  console.log(`  Aligned/clean counter types:    ${tot.aligned}`);

  // Necessity verdict
  console.log('\n── COMM-F2 necessity verdict ──\n');
  if (tot.stuck > 0) {
    console.log(`  COMM-F2 IS NECESSARY.`);
    console.log(`  ${tot.stuck} counter(s) across the fleet are currently in DRIFTED_STUCK state.`);
    console.log(`  Communication::_next_id reads stale flat values; writes go nested (unread by _next_id).`);
    console.log(`  Result: every Communication::save_notice would produce a duplicate ID once flat is reached.`);
  } else if (tot.flat > 0) {
    console.log(`  COMM-F2 IS LATENT but not yet manifesting (flat keys present but aligned with nested).`);
    console.log(`  Fix is still recommended to prevent future drift.`);
  } else {
    console.log(`  COMM-F2 may be obsolete: zero flat-keyed commCounters fleet-wide.`);
    console.log(`  Self-heal scan path already handles the no-flat case correctly.`);
  }

  console.log('\n' + '═'.repeat(78));
  console.log('  Probe complete. Architecture: Firestore-only (admin.firestore() used).');
  console.log('═'.repeat(78));
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
