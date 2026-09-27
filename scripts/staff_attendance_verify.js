#!/usr/bin/env node
/**
 * STAFF attendance verification — STRICTLY READ-ONLY. Writes nothing.
 *
 * The existing `Attendance_canonical_verify` covers STUDENT attendance
 * (studentId/className/section). This verifies the STAFF lineage that payroll
 * actually consumes:
 *
 *   staffAttendance/{schoolId}_{dateISO}_{staffId}   per-day canonical record
 *   staffAttendanceSummary/{schoolId}_{staffId}_{monthKey}   dayWise aggregate
 *
 * The question that matters for N-35: do the per-day records and the dayWise
 * string agree, and does dayWise carry the 'O' weekly-off overlay that the
 * finaliser is supposed to apply? If 'O' is absent, payroll sees 'A' on rest
 * days — which is exactly the N-23 exposure.
 *
 *   node scripts/staff_attendance_verify.js
 */
const admin = require('firebase-admin');
admin.initializeApp({ credential: admin.credential.cert(
  require('./../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json')) });
const db = admin.firestore();

const VOCAB = ['P','A','L','H','T','V','M','O','W'];
const line = (c='=') => console.log(c.repeat(78));

(async () => {
  console.log('\nSTAFF ATTENDANCE VERIFICATION (READ-ONLY)');
  line();

  const perDay = await db.collection('staffAttendance').get();
  const summ   = await db.collection('staffAttendanceSummary').get();
  console.log(`  staffAttendance per-day docs : ${perDay.size}`);
  console.log(`  staffAttendanceSummary docs  : ${summ.size}`);

  // ── Per-day record schema ──
  const REQUIRED = ['schoolId','date','staffId','status','markedBy','markedAt','source'];
  const missing = {}, statusDist = {}, sourceDist = {}, byTenant = {};
  const perDayIndex = {};   // schoolId|staffId|monthKey -> {day: status}

  perDay.forEach(d => {
    const x = d.data();
    const sid = x.schoolId || '?';
    byTenant[sid] = (byTenant[sid]||0)+1;
    REQUIRED.forEach(f => { if (x[f] === undefined || x[f] === null || x[f] === '')
      (missing[f] = missing[f]||[]).push(d.id); });
    statusDist[x.status||'?'] = (statusDist[x.status||'?']||0)+1;
    sourceDist[x.source||'(none)'] = (sourceDist[x.source||'(none)']||0)+1;

    const date = String(x.date||'');
    const m = date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (m && x.staffId) {
      const key = `${sid}|${x.staffId}|${m[1]}-${m[2]}`;
      (perDayIndex[key] = perDayIndex[key]||{})[parseInt(m[3],10)] = x.status;
    }
  });

  console.log('\n─── per-day record schema ───');
  line('-');
  REQUIRED.forEach(f => {
    const n = (missing[f]||[]).length;
    console.log(`  ${f.padEnd(12)} missing in ${n} / ${perDay.size}` +
      (n ? `   e.g. ${missing[f][0]}` : ''));
  });
  console.log('\n  status distribution : ' + JSON.stringify(statusDist));
  console.log('  source distribution : ' + JSON.stringify(sourceDist));
  console.log('  docs per tenant     : ' + JSON.stringify(byTenant));

  // ── Summary docs: vocabulary, length, and the 'O' overlay ──
  console.log('\n─── staffAttendanceSummary: dayWise integrity ───');
  line('-');
  let noOverlay = 0, badVocab = 0, lenMismatch = 0, mismatched = 0, checked = 0;

  for (const d of summ.docs) {
    const x = d.data();
    const dw = String(x.dayWise || '');
    const monthKey = String(x.month || '');
    const mk = monthKey.match(/^(\d{4})-(\d{2})$/);
    if (!mk) continue;
    const y = +mk[1], mo = +mk[2];
    const dim = new Date(y, mo, 0).getDate();

    const bad = [...dw].filter(c => !VOCAB.includes(c));
    if (bad.length) badVocab++;
    if (dw.length !== dim) lenMismatch++;

    // How many calendar Sundays does this month have, and does dayWise carry
    // ANY 'O'? No 'O' at all + Sundays present ⇒ the weekly-off overlay never
    // ran, so payroll sees whatever mark is there (often 'A') on rest days.
    let sundays = 0;
    for (let dd = 1; dd <= dim; dd++) if (new Date(y, mo-1, dd).getDay() === 0) sundays++;
    const oCount = (dw.match(/O/g)||[]).length;
    if (sundays > 0 && oCount === 0) noOverlay++;

    // Cross-check per-day records against the aggregate string.
    const key = `${x.schoolId}|${x.staffId}|${monthKey}`;
    const days = perDayIndex[key];
    let diffs = [];
    if (days) {
      Object.keys(days).forEach(dd => {
        const fromString = dw[dd-1];
        if (fromString !== undefined && days[dd] && fromString !== days[dd]) {
          diffs.push(`d${dd}: perDay=${days[dd]} dayWise=${fromString}`);
        }
      });
      checked++;
      if (diffs.length) mismatched++;
    }

    console.log(`  ${x.schoolId} ${String(x.staffId).padEnd(9)} ${monthKey}` +
      `  len=${dw.length}/${dim}  O=${oCount}  Sundays=${sundays}` +
      `  perDayRecords=${days ? Object.keys(days).length : 0}` +
      (diffs.length ? `\n      MISMATCH: ${diffs.slice(0,4).join(' | ')}` : ''));
  }

  console.log('\n─── Verdict ───');
  line('-');
  console.log(`  summaries with invalid vocabulary chars : ${badVocab}`);
  console.log(`  summaries whose length != days-in-month : ${lenMismatch}`);
  console.log(`  summaries cross-checked vs per-day docs : ${checked}`);
  console.log(`  summaries DISAGREEING with per-day docs : ${mismatched}`);
  console.log(`  summaries with NO 'O' overlay despite Sundays : ${noOverlay}`);
  if (noOverlay) {
    console.log('\n  ** The weekly-off overlay is absent on those months. Payroll therefore');
    console.log('     reads whatever mark occupies a rest day (commonly A) — the N-23 exposure.');
  }
  line();
  console.log('  NOTHING WAS WRITTEN.\n');
})().catch(e => { console.error(e); process.exit(1); });
