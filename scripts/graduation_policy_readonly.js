/**
 * READ-ONLY graduation / terminal-class probe.
 *
 * ── WHAT THIS EXISTS TO ANSWER ───────────────────────────────────────────
 * ZenX graduates students in exactly ONE place: `School_config::rollover_session()`
 * with `promote_students=1`. Its transition table, `_rolloverNextClass()`, is:
 *
 *     classOrder === terminal    -> Alumni      (status='Alumni', graduatedFrom)
 *     other numeric              -> classOrder + 1
 *     Playgroup .. UKG           -> next foundational class
 *     anything else              -> skip (unknown_classOrder)
 *
 * ── WHAT THIS FOUND, AND WHAT CHANGED ────────────────────────────────────
 * That Alumni arm used to be hardcoded to classOrder 12. A school's classes
 * are CONFIGURED, in `schools.classes`, and this probe measured that 2 of 3
 * tenanted schools terminate below Class 12 — one with 12 active students in
 * its terminal class.
 *
 * For those schools the terminal cohort never graduated: it fell into the +1
 * arm and was promoted into a class the school does not configure. The
 * rollover pre-flight guarded capacity and roll collisions and never asked
 * whether the destination existed, so nothing refused the move.
 *
 * LC-7 now refuses an unconfigured destination, and LC-11 derives the
 * graduating class from `schools.classes` via `_terminalClassOrder()`.
 *
 * The probe is kept as the standing measurement: it reports each tenant's
 * terminal class and the size of the cohort that completes there, which is
 * the number an operator needs before running a rollover.
 *
 *   node scripts/graduation_policy_readonly.js
 *   node scripts/graduation_policy_readonly.js --school=SCH_B56BB9A401
 *
 * Writes nothing. Prints no credential material — school id, class labels,
 * orders and counts only.
 *
 * exit 0 = every tenant with students terminates at Class 12
 *      2 = at least one tenant terminates elsewhere (informational since LC-11 —
 *          these schools now graduate at their own terminal class)
 */
const admin = require('firebase-admin');
const path  = require('path');

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};
const ONLY = arg('school', '');

// Mirrors Entity_firestore_sync::CLASS_ORDER_MAP for the foundational labels;
// numeric classes map to themselves. Kept local so the probe never depends on
// PHP being runnable.
const FOUNDATIONAL = {
  'PLAYGROUP': -5, 'PRE-NURSERY': -4, 'PRE NURSERY': -4, 'NURSERY': -3, 'LKG': -2, 'UKG': -1,
};

const classOrderOf = (label) => {
  let t = String(label || '').trim();
  if (/^class\s+/i.test(t)) t = t.replace(/^class\s+/i, '').trim();
  const numeric = t.replace(/(\d+)(st|nd|rd|th)$/i, '$1').trim();
  if (/^\d+$/.test(numeric)) return parseInt(numeric, 10);
  const key = numeric.toUpperCase();
  return Object.prototype.hasOwnProperty.call(FOUNDATIONAL, key) ? FOUNDATIONAL[key] : null;
};

if (process.argv.includes('--selftest')) {
  const cases = [
    ['Class 12th', 12], ['Class 5th', 5], ['12', 12], ['Class 1st', 1],
    ['Class UKG', -1], ['Class Nursery', -3], ['Class Pre-Nursery', -4],
    ['Class Playgroup', -5], ['Playgroup', -5], ['Class Pre Nursery', -4],
    ['Class Montessori', null], ['', null],
  ];
  let bad = 0;
  console.log('GRADUATION PROBE — SELFTEST (no Firestore)\n');
  for (const [label, want] of cases) {
    const got = classOrderOf(label);
    const ok = got === want;
    if (!ok) bad++;
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${JSON.stringify(label).padEnd(20)} got=${got} want=${want}`);
  }
  console.log('\n' + (bad === 0
    ? '  ORDER RESOLUTION VERIFIED.'
    : `  ${bad} SELFTEST FAILURE(S) — the probe cannot be trusted.`));
  process.exit(bad === 0 ? 0 : 2);
}

const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  console.log('GRADUATION / TERMINAL-CLASS PROBE (READ ONLY)\n');

  const schools = await db.collection('schools').get();
  let offTwelve = 0;
  let examined  = 0;

  for (const doc of schools.docs) {
    if (ONLY && doc.id !== ONLY) continue;
    const d = doc.data() || {};
    const classes = Array.isArray(d.classes) ? d.classes : [];

    // Class entries are labels or objects depending on vintage; take whichever
    // carries the name rather than assuming one shape.
    const labels = classes
      .map((c) => (typeof c === 'string' ? c : (c && (c.className || c.name || c.label)) || ''))
      .filter(Boolean);

    const orders = labels.map(classOrderOf).filter((n) => n !== null);
    const maxOrder = orders.length ? Math.max(...orders) : null;

    // Only count students in the terminal class — the cohort the hardcode
    // decides the fate of.
    const stu = await db.collection('students').where('schoolId', '==', doc.id).get();
    const total = stu.size;
    if (total === 0 && !ONLY) continue;   // untenanted schools answer nothing
    examined++;

    let inTerminal = 0;
    stu.forEach((s) => {
      const sd = s.data() || {};
      if (String(sd.status || 'Active') !== 'Active') return;
      const o = sd.classOrder != null ? Number(sd.classOrder) : classOrderOf(sd.className);
      if (maxOrder !== null && o === maxOrder) inTerminal++;
    });

    const terminalLabel = labels.find((l) => classOrderOf(l) === maxOrder) || '(none)';
    const holds = maxOrder === 12;
    if (!holds) offTwelve++;

    console.log(`  ── ${doc.id}`);
    console.log(`     configured classes : ${labels.length ? labels.join(', ') : '(none configured)'}`);
    console.log(`     terminal class     : ${terminalLabel} (classOrder=${maxOrder})`);
    console.log(`     students total     : ${total}   active in terminal class: ${inTerminal}`);
    console.log(`     completes at       : ${terminalLabel} — ${inTerminal} active student(s)`);
    if (!holds) {
      console.log('     PRE-LC-11 BEHAVIOUR: these students were promoted to classOrder='
        + `${maxOrder === null ? '?' : maxOrder + 1}, which this school does not configure, `
        + 'and none of them graduated.');
      console.log('     NOW                : _terminalClassOrder() derives this class from '
        + 'schools.classes, so they complete schooling here (status=Alumni).');
    }
    console.log('');
  }

  console.log('-'.repeat(70));
  if (examined === 0) {
    console.log('  No tenant matched. Nothing measured — this is not a pass.');
    process.exit(1);
  }
  console.log(offTwelve === 0
    ? `  ${examined} tenant(s) examined — every one terminates at Class 12.`
    : `  ${offTwelve} of ${examined} tenant(s) terminate below Class 12. Before LC-11 the `
      + 'hardcoded arm never fired for them; it is now derived from schools.classes.');
  process.exit(offTwelve === 0 ? 0 : 2);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
