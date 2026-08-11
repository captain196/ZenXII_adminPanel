'use strict';
/**
 * Rules Sentinel self-test.
 *
 * The sentinel's whole value is that its verdict can be trusted without
 * re-checking by hand, so each state in the three-way machine is asserted
 * against a hand-built fixture — especially the two states that were WRONG on
 * the first live run:
 *   - live_uncommitted mis-reported as conflict
 *   - `allow write: if isAdmin()` mis-reported as untenanted, because the
 *     analysis could not see inside the helper
 *
 * Exported as a function so test/run.js can fold these into its own tally.
 */
const { parseRules } = require('../rules/parse');
const { threeWay, resolveModules, STATUS } = require('../rules/diff');
const { scoreAllow, scoreChange, helperMap, effectiveText, overall } = require('../rules/risk');

const WRAP = (body, helpers = '') => `
rules_version = '2';
service cloud.firestore {
  match /databases/{database}/documents {
    function isAuth() { return request.auth != null; }
    function isSameSchool() { return isAuth() && request.auth.token.school_id == schoolId; }
    function isAdmin() { return isAuth() && tenantActive(request.auth.token.school_id) && request.auth.token.role == 'Admin'; }
    function tenantActive(sid) { return sid != null; }
    ${helpers}
${body}
  }
}`;

const STUDENTS = (allows) => `    match /students/{docId} {\n${allows}\n    }`;

module.exports = function runRulesTests({ ok, eq }) {
  // ── parser ────────────────────────────────────────────────────────
  {
    const p = parseRules(WRAP(STUDENTS(`      allow read: if isSameSchool();\n      allow write: if isAdmin();`)));
    const students = p.blocks.find(b => b.collection === 'students');
    ok('rules/parse: finds the students block', !!students);
    eq('rules/parse: attributes both allows', students.allows.length, 2);
    ok('rules/parse: nests under the documents root', students.depth === 1);
    ok('rules/parse: wildcard path did not split the block', students.fullPath.includes('/students/'));
    ok('rules/parse: keeps helper bodies for analysis', p.functions.find(f => f.name === 'isAdmin').body.includes('tenantActive'));
  }
  {
    // A `{` inside a comment must not open a block.
    const p = parseRules(WRAP(`    // a brace in prose: match /fake/{id} {\n${STUDENTS('      allow read: if isSameSchool();')}`));
    eq('rules/parse: ignores braces inside comments', p.blocks.filter(b => b.collection === 'fake').length, 0);
  }
  {
    // Comment-only edits must not register as a semantic change.
    const a = parseRules(WRAP(STUDENTS('      allow read: if isSameSchool();')));
    const b = parseRules(WRAP(STUDENTS('      // ── Students ──\n      allow read: if   isSameSchool();')));
    const ba = a.blocks.find(x => x.collection === 'students');
    const bb = b.blocks.find(x => x.collection === 'students');
    eq('rules/parse: comment + whitespace edits are semantically identical', ba.ownHash, bb.ownHash);
  }

  // ── three-way state machine ───────────────────────────────────────
  const base = WRAP(STUDENTS('      allow read: if isSameSchool();'));
  const edited = WRAP(STUDENTS('      allow read: if isSameSchool();\n      allow update: if isSameSchool() && request.auth.uid == resource.data.studentId;'));
  const otherEdit = WRAP(STUDENTS('      allow read: if isSameSchool();\n      allow delete: if isAdmin();'));
  const findStudents = (r) => r.blocks.find(b => b.collection === 'students');

  eq('rules/diff: identical everywhere → clean',
    findStudents(threeWay({ live: base, head: base, work: base })).status, STATUS.CLEAN);

  eq('rules/diff: my edit, prod matches git → mine',
    findStudents(threeWay({ live: base, head: base, work: edited })).status, STATUS.MINE);

  // This assertion used to expect THEIRS while its own name said "undeployed" —
  // it had encoded the bug. Presence alone cannot tell the two apart when both
  // sides have the block, so the classifier now compares which side's grants
  // contain the other's. Reported by a real reconciliation: a committed
  // prefLang clause missing from production was labelled "theirs", telling the
  // user to pull in something that did not exist anywhere.
  eq('rules/diff: committed but not deployed → undeployed (not theirs)',
    findStudents(threeWay({ live: base, head: edited, work: edited })).status, STATUS.UNDEPLOYED);
  eq('rules/diff: ...and it is tagged as git holding the superset',
    findStudents(threeWay({ live: base, head: edited, work: edited })).direction, 'git_adds');
  eq('rules/diff: live holding the superset stays theirs',
    findStudents(threeWay({ live: edited, head: base, work: base })).status, STATUS.THEIRS);
  eq('rules/diff: ...tagged live_adds',
    findStudents(threeWay({ live: edited, head: base, work: base })).direction, 'live_adds');

  {
    // live_uncommitted needs direction too: 46 of the real ones meant "commit
    // to preserve" and one meant "git has work production lost" — opposite
    // actions behind a single label.
    const { direction } = require('../rules/diff');
    const { parseRules } = require('../rules/parse');
    const blk = (src) => parseRules(src).blocks.find(b => b.collection === 'students');
    eq('rules/diff: direction — git superset', direction(blk(edited), blk(base)), 'git_adds');
    eq('rules/diff: direction — live superset', direction(blk(base), blk(edited)), 'live_adds');
    eq('rules/diff: direction — both changed independently', direction(blk(edited), blk(otherEdit)), 'divergent');
    eq('rules/diff: direction — a missing side is never guessed', direction(null, blk(base)), 'divergent');
    eq('rules/diff: no direction when live and head agree',
      findStudents(threeWay({ live: base, head: base, work: edited })).direction, null);
  }

  eq('rules/diff: prod changed, I did not touch it → theirs',
    findStudents(threeWay({ live: otherEdit, head: base, work: base })).status, STATUS.THEIRS);

  // The regression that production taught us on the first live run.
  eq('rules/diff: prod == my disk but in no commit → live_uncommitted (NOT conflict)',
    findStudents(threeWay({ live: edited, head: base, work: edited })).status, STATUS.LIVE_UNCOMMITTED);

  eq('rules/diff: I edited a block that ALSO drifted differently → conflict',
    findStudents(threeWay({ live: otherEdit, head: base, work: edited })).status, STATUS.CONFLICT);

  {
    const r = threeWay({ live: null, head: base, work: edited });
    ok('rules/diff: degrades gracefully with no live source', r.liveAvailable === false);
    eq('rules/diff: offline still reports my own edit', findStudents(r).status, STATUS.MINE);
  }

  // ── the fourth leg: git REMOTE ────────────────────────────────────
  // HEAD is the LOCAL head. A teammate who commits and pushes without
  // deploying is invisible to the live-vs-HEAD axis entirely, and before
  // `remote` existed every one of these cases reported `clean`.
  {
    const r = threeWay({ live: base, head: base, work: base, remote: otherEdit });
    ok('rules/diff: remote source marks the board remote-aware', r.remoteAvailable === true);
    eq('rules/diff: teammate pushed but nobody deployed → behind (was silently clean)',
      findStudents(r).status, STATUS.BEHIND);
    eq('rules/diff: behind block is counted in the summary', r.summary.behind, 1);
    ok('rules/diff: behind block is flagged unpulled', findStudents(r).unpulled === true);
    ok('rules/diff: behind without local edits is not a pull conflict', findStudents(r).pullConflict === false);
  }
  {
    // I edited the same block a teammate pushed. `git pull` will conflict here,
    // and neither the live axis nor a plain `git status` says so beforehand.
    const r = threeWay({ live: base, head: base, work: edited, remote: otherEdit });
    eq('rules/diff: my edit still outranks behind in the status enum',
      findStudents(r).status, STATUS.MINE);
    ok('rules/diff: but the incoming pull conflict is flagged orthogonally',
      findStudents(r).pullConflict === true);
    eq('rules/diff: pull conflicts are counted separately from live conflicts',
      r.summary.pullConflict, 1);
  }
  {
    // Production drift must keep priority: it is the more urgent of the two.
    const r = threeWay({ live: otherEdit, head: base, work: base, remote: otherEdit });
    eq('rules/diff: live drift outranks remote drift on the same block',
      findStudents(r).status, STATUS.THEIRS);
  }
  {
    const r = threeWay({ live: base, head: base, work: base, remote: base });
    eq('rules/diff: remote in sync stays clean', findStudents(r).status, STATUS.CLEAN);
    eq('rules/diff: nothing unpulled when remote agrees', r.summary.unpulled, 0);
  }
  {
    // No upstream / fetch failed. The board must say "unknown", never "clean":
    // reporting in-sync when the remote was not consulted is the exact failure
    // this leg was added to prevent.
    const r = threeWay({ live: base, head: base, work: base });
    ok('rules/diff: absent remote reports remoteAvailable=false', r.remoteAvailable === false);
    ok('rules/diff: absent remote leaves unpulled null, not false',
      findStudents(r).unpulled === null && findStudents(r).pullConflict === null);
  }
  {
    // A helper changed on the remote re-points every block that calls it, the
    // same way a live helper change does.
    const helperRemote = `
rules_version = '2';
service cloud.firestore {
  match /databases/{database}/documents {
    function isAuth() { return request.auth != null; }
    function isSameSchool() { return isAuth() && request.auth.token.school_id == schoolId && tenantActive(request.auth.token.school_id); }
    function isAdmin() { return isAuth() && tenantActive(request.auth.token.school_id) && request.auth.token.role == 'Admin'; }
    function tenantActive(sid) { return sid != null; }
${STUDENTS('      allow read: if isSameSchool();')}
  }
}`;
    const r = threeWay({ live: base, head: base, work: base, remote: helperRemote });
    const drift = r.fnDrift.find(f => f.name === 'isSameSchool');
    ok('rules/diff: a helper changed on the remote is reported as drift', !!drift);
    ok('rules/diff: that drift is attributed to the remote, not to live or me',
      drift && drift.remoteVsHead === true && drift.liveVsHead === false && drift.headVsWork === false);
  }

  // ── vcs verdicts ──────────────────────────────────────────────────
  // The verdict is what the agent and the deploy gate actually act on, so each
  // branch topology is asserted directly rather than inferred from a live repo.
  {
    const { verdict } = require('../rules/vcs');
    const R = { ok: true, repo: '/r', branch: 'work', upstream: 'origin/work' };

    eq('rules/vcs: in sync → ok',
      verdict({ ...R, ahead: 0, behind: 0, rulesBehind: 0 }, 'fetched').level, 'ok');
    eq('rules/vcs: ahead only → ok, nothing to pull',
      verdict({ ...R, ahead: 2, behind: 0, rulesBehind: 0 }, 'fetched').level, 'ok');
    eq('rules/vcs: behind but not in the rules file → warn, not block',
      verdict({ ...R, ahead: 0, behind: 5, rulesBehind: 0 }, 'fetched').level, 'warn');
    eq('rules/vcs: unpulled commits IN the rules file → block',
      verdict({ ...R, ahead: 0, behind: 5, rulesBehind: 2, rulesRel: 'f.rules' }, 'fetched').level, 'block');
    eq('rules/vcs: diverged inside the rules file is still a block',
      verdict({ ...R, ahead: 3, behind: 5, rulesBehind: 1, rulesRel: 'f.rules' }, 'fetched').code, 'diverged-rules');
    eq('rules/vcs: no upstream → warn (nothing can tell you about teammates)',
      verdict({ ...R, upstream: null, ahead: 0, behind: 0 }, 'fetched').level, 'warn');
    eq('rules/vcs: detached HEAD → warn',
      verdict({ ...R, detached: true, ahead: 0, behind: 0 }, 'fetched').level, 'warn');

    // The critical honesty case: a failed fetch must never read as "in sync".
    eq('rules/vcs: failed fetch reports unknown, NOT ok',
      verdict({ ...R, ahead: 0, behind: 0, rulesBehind: 0 }, 'failed').level, 'unknown');
    eq('rules/vcs: offline reports unknown, NOT ok',
      verdict({ ...R, ahead: 0, behind: 0, rulesBehind: 0 }, 'skipped-offline').level, 'unknown');
    eq('rules/vcs: no repo at all → unknown',
      verdict({ ok: false, reason: 'no-repo' }, 'no-repo').level, 'unknown');
    ok('rules/vcs: a blocking verdict always carries an exact remedy command',
      !!verdict({ ...R, ahead: 0, behind: 1, rulesBehind: 1, rulesRel: 'f.rules' }, 'fetched').remedy);
  }
  {
    const helperChanged = WRAP(STUDENTS('      allow read: if isSameSchool();'))
      .replace('return sid != null;', 'return true;');
    const r = threeWay({ live: base, head: base, work: helperChanged });
    ok('rules/diff: flags a changed shared helper', r.fnDrift.some(f => f.name === 'tenantActive'));
  }

  // ── risk ──────────────────────────────────────────────────────────
  {
    const helpers = helperMap(parseRules(base));
    eq('rules/risk: world-open is critical',
      scoreAllow({ ops: 'read', cond: 'true' }, helpers)[0].severity, 'critical');
    eq('rules/risk: explicit deny is clean',
      scoreAllow({ ops: 'write', cond: 'false' }, helpers).length, 0);

    // The false positive that made the first live run unreadable.
    const viaHelper = scoreAllow({ ops: 'write', cond: 'isAdmin()' }, helpers);
    eq('rules/risk: isAdmin() counts as tenant-scoped through the helper',
      viaHelper.filter(f => f.code === 'no-tenant-scope').length, 0);
    eq('rules/risk: isAdmin() counts as an auth gate through the helper',
      viaHelper.filter(f => f.code === 'no-auth-gate').length, 0);

    ok('rules/risk: an ungated write is still caught',
      scoreAllow({ ops: 'write', cond: 'someFlag == 1' }, helpers).some(f => f.code === 'no-auth-gate'));
    ok('rules/risk: helper expansion terminates on recursion',
      effectiveText('a()', new Map([['a', 'b()'], ['b', 'a()']])).length > 0);
  }
  {
    const before = { allows: [{ ops: 'update', cond: `x.diff(y).affectedKeys().hasOnly(['mustChangePassword'])` }] };
    const after = { allows: [{ ops: 'update', cond: `x.diff(y).affectedKeys().hasOnly(['mustChangePassword', 'role'])` }] };
    const f = scoreChange(before, after, new Map());
    ok('rules/risk: catches a widened field allowlist', f.some(x => x.code === 'allowlist-widened'));
    ok('rules/risk: widening is at least high severity', ['high', 'critical'].includes(overall(f)));
  }
  {
    const before = { allows: [{ ops: 'write', cond: 'false' }] };
    const after = { allows: [{ ops: 'write', cond: 'isAdmin()' }] };
    ok('rules/risk: catches a removed explicit deny',
      scoreChange(before, after, new Map()).some(x => x.code === 'deny-removed'));
  }

  // ── module ownership ──────────────────────────────────────────────
  eq('rules/diff: exact rules_block mapping wins', resolveModules('homework').matchedBy, 'rules_block');
  eq('rules/diff: students resolves to sis', resolveModules('students').modules.includes('sis'), true);
  eq('rules/diff: keyword tier maps the long tail', resolveModules('feeReceipts').modules.includes('fees'), true);
  eq('rules/diff: longest keyword wins for staffAttendance',
    resolveModules('staffAttendance').modules.includes('attendance'), true);
  eq('rules/diff: unknown collection stays honestly unmapped', resolveModules('zzzNotAThing').modules.length, 0);

  // ── integration: the real rules file ──────────────────────────────
  {
    const cfg = require('../lib/config').load();
    const fs = require('fs');
    if (fs.existsSync(cfg.firestoreRules)) {
      const real = parseRules(fs.readFileSync(cfg.firestoreRules, 'utf8'));
      ok('rules/parse: real firestore.rules yields >100 blocks', real.blocks.length > 100);
      ok('rules/parse: real file exposes the core helpers',
        ['isAuth', 'isSameSchool', 'isAdmin', 'isStaff'].every(n => real.functions.some(f => f.name === n)));
      // A block legitimately has no collection when every segment is a
      // wildcard — that is the trailing `match /{document=**}` catch-all.
      const noCollection = real.blocks.filter(b => !b.collection && b.depth !== 0);
      ok('rules/parse: the only collection-less blocks are all-wildcard catch-alls',
        noCollection.every(b => /\{[^}]*\}$/.test(b.fullPath)));
      // That catch-all is the file's backstop: if it ever stops denying, every
      // collection without an explicit block becomes readable.
      const catchAll = noCollection.find(b => b.fullPath.endsWith('{document=**}'));
      ok('rules/parse: the catch-all still denies everything',
        !catchAll || catchAll.allows.every(a => a.cond === 'false'));
    }
  }
};
