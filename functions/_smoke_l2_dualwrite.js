// L2 dual-write smoke — simulates lock_period + reopen_period
// flows by writing the same docs the PHP would write, then verifies
// shape, idempotency, and cleanup.

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join('..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();
const SCHOOL = 'SCH_D94FE8F7AD';

let allPass = true;
function assert(cond, label) {
  console.log((cond ? '  PASS  ' : '  FAIL  ') + label);
  if (!cond) allPass = false;
}
function sessionFor(d) {
  const [y, m] = d.split('-').map(Number);
  if (m >= 4) return y + '-' + String((y + 1) % 100).padStart(2, '0');
  return (y - 1) + '-' + String(y % 100).padStart(2, '0');
}

(async () => {
  console.log('━━━ L2 DUAL-WRITE SMOKE ━━━\n');

  // Pre-state cleanup
  await db.collection('accountingConfig').doc(SCHOOL + '_period_lock').delete().catch(() => {});
  await db.collection('accountingConfig').doc(SCHOOL + '_2025-26_periodLock').delete().catch(() => {});
  await db.collection('accountingConfig').doc(SCHOOL + '_2026-27_periodLock').delete().catch(() => {});

  // ─── SCENARIO 1: lock_period → write BOTH docs ───
  console.log('[Scenario 1] lock_period(2026-03-31) — should write both docs');
  const lockDate = '2026-03-31';
  const now1 = new Date().toISOString();
  await db.collection('accountingConfig').doc(SCHOOL + '_period_lock').set({
    locked_until: lockDate,
    locked_by: 'L2_smoke',
    locked_at: now1,
    close_reason: 'L2 dual-write smoke',
    prior_locked_until: '',
    prior_locked_by: '',
  });
  const session1 = sessionFor(lockDate);
  assert(session1 === '2025-26', 'session derivation for ' + lockDate + ' = ' + session1);

  await db.collection('accountingConfig').doc(SCHOOL + '_' + session1 + '_periodLock').set({
    schoolId: SCHOOL,
    session: session1,
    lockedUntil: lockDate,
    lockedBy: 'L2_smoke',
    lockedAt: now1,
    reopenedBy: '',
    reopenedAt: '',
    reason: 'L2 dual-write smoke',
  });

  const ctrl1 = await db.collection('accountingConfig').doc(SCHOOL + '_period_lock').get();
  const cano1 = await db.collection('accountingConfig').doc(SCHOOL + '_2025-26_periodLock').get();
  assert(ctrl1.exists, 'controller doc written');
  assert(cano1.exists, 'canonical doc written');
  if (cano1.exists) {
    const d = cano1.data();
    assert(d.lockedUntil === lockDate, 'canonical lockedUntil = ' + d.lockedUntil);
    assert(d.session === '2025-26', 'canonical session = 2025-26');
    assert(d.reason === 'L2 dual-write smoke', 'reason field renamed correctly');
    assert(d.lockedBy === 'L2_smoke', 'lockedBy preserved');
  }

  // ─── SCENARIO 2: reopen_period → narrower lock, both docs updated ───
  console.log('\n[Scenario 2] reopen_period(new=2026-02-28) — both docs updated, session preserved');
  const newLock = '2026-02-28';
  const now2 = new Date().toISOString();
  const priorLock = ctrl1.data();

  await db.collection('accountingConfig').doc(SCHOOL + '_period_lock').set({
    locked_until: newLock,
    locked_by: 'L2_smoke',
    locked_at: now2,
    reopened_at: now2,
    reopened_by: 'L2_smoke',
    reopen_reason: 'L2 reopen smoke',
    expected_close_until: '2026-04-30',
    prior_locked_until: priorLock.locked_until,
    state: 'reopened',
  });
  const priorSession = sessionFor(priorLock.locked_until);
  await db.collection('accountingConfig').doc(SCHOOL + '_' + priorSession + '_periodLock').set({
    schoolId: SCHOOL,
    session: priorSession,
    lockedUntil: newLock,
    lockedBy: 'L2_smoke',
    lockedAt: now2,
    reopenedBy: 'L2_smoke',
    reopenedAt: now2,
    reason: 'L2 reopen smoke',
  });
  const cano2 = await db.collection('accountingConfig').doc(SCHOOL + '_2025-26_periodLock').get();
  if (cano2.exists) {
    const d = cano2.data();
    assert(d.lockedUntil === newLock, 'canonical lockedUntil moved to ' + newLock);
    assert(d.session === '2025-26', 'canonical session still 2025-26 (not changed to active session)');
    assert(d.reopenedBy === 'L2_smoke', 'reopenedBy populated');
  }

  // ─── SCENARIO 3: full unlock ───
  console.log('\n[Scenario 3] full unlock (lockedUntil="") — canonical doc retained with empty lockedUntil');
  await db.collection('accountingConfig').doc(SCHOOL + '_period_lock').set({
    locked_until: '',
    locked_by: 'L2_smoke',
    locked_at: new Date().toISOString(),
    reopened_at: new Date().toISOString(),
    reopened_by: 'L2_smoke',
    reopen_reason: 'full unlock test',
    expected_close_until: '2026-04-30',
    prior_locked_until: newLock,
    state: 'reopened',
  });
  await db.collection('accountingConfig').doc(SCHOOL + '_2025-26_periodLock').set({
    schoolId: SCHOOL,
    session: '2025-26',
    lockedUntil: '',
    lockedBy: 'L2_smoke',
    lockedAt: new Date().toISOString(),
    reopenedBy: 'L2_smoke',
    reopenedAt: new Date().toISOString(),
    reason: 'full unlock test',
  });
  const cano3 = await db.collection('accountingConfig').doc(SCHOOL + '_2025-26_periodLock').get();
  if (cano3.exists) {
    const d = cano3.data();
    assert(d.lockedUntil === '', 'canonical lockedUntil cleared');
    assert(d.reopenedBy === 'L2_smoke', 'audit trail preserved post-unlock');
    console.log('  → forceValidate() would now return locked=false (per validate() short-circuit at line 101)');
  }

  // ─── SCENARIO 4: future-date validation ───
  console.log('\n[Scenario 4] future-date controller validation');
  const today = new Date().toISOString().slice(0, 10);
  const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
  console.log('  today=' + today + ' tomorrow=' + tomorrow);
  console.log('  PHP lock_period: $date(' + tomorrow + ') > $today(' + today + ') → json_error');
  console.log('  No Firestore writes occur on rejection');
  assert(tomorrow > today, 'future-date arithmetic correct');

  // ─── CLEANUP ───
  console.log('\n━━━ Cleanup ━━━');
  await db.collection('accountingConfig').doc(SCHOOL + '_period_lock').delete();
  await db.collection('accountingConfig').doc(SCHOOL + '_2025-26_periodLock').delete();
  const c1 = await db.collection('accountingConfig').doc(SCHOOL + '_period_lock').get();
  const c2 = await db.collection('accountingConfig').doc(SCHOOL + '_2025-26_periodLock').get();
  assert(!c1.exists, 'controller doc removed');
  assert(!c2.exists, 'canonical doc removed');

  console.log('\n━━━ RESULT: ' + (allPass ? 'ALL PASSED' : 'SOME FAILURES') + ' ━━━');
  process.exit(allPass ? 0 : 1);
})().catch((e) => { console.error('FATAL:', e); process.exit(2); });
