'use strict';
/**
 * Four-way, block-level, SEMANTIC comparison of firestore.rules.
 *
 * The versions that matter:
 *   LIVE   — what the Firebase Rules API says is enforcing production right now
 *   REMOTE — what the team has PUSHED (`git show <upstream>:`)
 *   HEAD   — what your LOCAL branch has committed
 *   WORK   — what is on your disk this second (yours and every other open tab's)
 *
 * Comparing them pairwise is what separates "my pending edit" from "a teammate
 * deployed something my branch has never seen". Those two look identical in a
 * plain `git diff`, and only one of them is dangerous.
 *
 * REMOTE is the fourth leg and it closes a real hole. HEAD is your *local*
 * head: if a colleague pushed a rules commit and you never fetched, HEAD still
 * returns your stale copy and every block reports `clean`. The live/HEAD axis
 * cannot see that, because the colleague pushed to git without deploying.
 * So the two axes answer different questions and both are load-bearing:
 *
 *   LIVE  vs HEAD  → "did someone deploy something that is in no commit?"
 *   REMOTE vs HEAD → "did someone commit something I have not pulled?"
 *
 * SEMANTIC, not textual: comparison runs on the canonicalised block body
 * (comments stripped, whitespace collapsed). The live ruleset currently differs
 * from git in exactly 6 mojibake'd box-drawing characters inside comments — a
 * textual diff screams about that on every single run, and a tool that cries
 * wolf is a tool nobody reads.
 */
const { parseRules } = require('./parse');
const { modules } = require('../manifest');

/** collection name → module key. Built once from the manifest. */
function buildOwnership() {
  const byCollection = new Map();
  const byRulesBlock = new Map();
  for (const [name, mod] of Object.entries(modules)) {
    for (const c of mod.firestore || []) {
      if (!byCollection.has(c)) byCollection.set(c, []);
      byCollection.get(c).push(name);
    }
    for (const b of mod.rules_blocks || []) {
      if (!byRulesBlock.has(b)) byRulesBlock.set(b, []);
      byRulesBlock.get(b).push(name);
    }
  }
  return { byCollection, byRulesBlock };
}
const OWNERSHIP = buildOwnership();

/**
 * Which module(s) own a collection.
 *
 * Three tiers, most-trusted first. The keyword tier matters: the manifest names
 * ~23 collections explicitly but the rules file guards 130, so exact mapping
 * alone leaves most blocks attributed to nothing — and a block with no module
 * gets no blast-radius analysis, which is the entire point. Reusing each
 * module's existing `match` keywords covers the long tail (feeReceipts → fees,
 * attendanceSummary → attendance) without duplicating a second registry that
 * would drift out of sync with the first.
 *
 * @returns {{modules:string[], matchedBy:'rules_block'|'collection'|'keyword'|null}}
 */
function resolveModules(collection) {
  if (!collection) return { modules: [], matchedBy: null };
  const exact = OWNERSHIP.byRulesBlock.get(collection);
  if (exact) return { modules: [...new Set(exact)], matchedBy: 'rules_block' };
  const byColl = OWNERSHIP.byCollection.get(collection);
  if (byColl) return { modules: [...new Set(byColl)], matchedBy: 'collection' };

  const lower = String(collection).toLowerCase();
  for (const [k, v] of OWNERSHIP.byCollection) if (String(k).toLowerCase() === lower) return { modules: [...new Set(v)], matchedBy: 'collection' };
  for (const [k, v] of OWNERSHIP.byRulesBlock) if (String(k).toLowerCase() === lower) return { modules: [...new Set(v)], matchedBy: 'rules_block' };

  // Keyword tier — longest keyword wins so `staffAttendance` prefers the more
  // specific signal over a bare `staff`.
  const hits = [];
  for (const [name, mod] of Object.entries(modules)) {
    for (const kw of mod.match || []) {
      const k = String(kw).toLowerCase();
      if (k.length >= 3 && lower.includes(k)) hits.push({ name, len: k.length });
    }
  }
  if (!hits.length) return { modules: [], matchedBy: null };
  const best = Math.max(...hits.map(h => h.len));
  return { modules: [...new Set(hits.filter(h => h.len === best).map(h => h.name))], matchedBy: 'keyword' };
}

/** Backwards-compatible shape: just the module names. */
function modulesForCollection(collection) { return resolveModules(collection).modules; }

/**
 * Index blocks by identity. A path can legitimately appear more than once
 * (academicAuditLog does, twice), so identity is path + occurrence ordinal.
 */
function indexBlocks(parsed) {
  const idx = new Map();
  const seen = new Map();
  for (const b of parsed.blocks) {
    const n = (seen.get(b.key) || 0);
    seen.set(b.key, n + 1);
    const id = n === 0 ? b.key : `${b.key}#${n + 1}`;
    idx.set(id, { ...b, id });
  }
  return idx;
}

/** Pairwise block comparison of two parsed documents. */
function compare(fromParsed, toParsed) {
  const A = indexBlocks(fromParsed), B = indexBlocks(toParsed);
  const changed = [], added = [], removed = [], same = [];
  for (const [id, b] of B) {
    const a = A.get(id);
    if (!a) added.push(b);
    else if (a.ownHash !== b.ownHash) changed.push({ id, from: a, to: b });
    else same.push(b);
  }
  for (const [id, a] of A) if (!B.has(id)) removed.push(a);
  return { changed, added, removed, same, counts: { changed: changed.length, added: added.length, removed: removed.length, same: same.length } };
}

const STATUS = {
  CLEAN: 'clean',
  MINE: 'mine',            // WORK differs from HEAD; HEAD matches LIVE
  UNDEPLOYED: 'undeployed',// HEAD differs from LIVE, WORK matches HEAD — committed, not shipped
  THEIRS: 'theirs',        // LIVE differs from HEAD, and you did not touch it
  CONFLICT: 'conflict',    // you are editing a block that ALSO drifted upstream
  // LIVE matches your DISK, but neither matches git: the change was deployed
  // straight from a working tree and never committed. Production is then the
  // ONLY copy — the next person to deploy from a clean checkout silently
  // reverts it. Discovered on the very first live run of this tool, which is
  // why it earns its own state instead of being lumped in with CONFLICT.
  LIVE_UNCOMMITTED: 'live_uncommitted',
  // REMOTE has a version your local HEAD lacks, and nothing else about the
  // block differs. A teammate committed and pushed it; you simply have not
  // pulled. Nobody deployed it, so the live/HEAD axis is silent — without this
  // state the block reads `clean` and a deploy quietly ships your older copy.
  BEHIND: 'behind',
};

/**
 * Full four-way status, per block.
 *
 * `remote` is optional: when it is absent (no upstream, offline, fetch failed)
 * every remote-derived field is null and `remoteAvailable` is false, so callers
 * can say "unknown" instead of silently reporting "in sync". That distinction
 * is the whole point — a tool that reports clean when it simply did not look is
 * worse than no tool.
 *
 * @param sources {{live:string|null, head:string|null, work:string, remote?:string|null}}
 * @returns {{blocks:Array, summary:Object, parsed:Object, liveAvailable:boolean, remoteAvailable:boolean}}
 */
function threeWay(sources) {
  const work = parseRules(sources.work);
  const head = sources.head != null ? parseRules(sources.head) : null;
  const live = sources.live != null ? parseRules(sources.live) : null;
  const remote = sources.remote != null ? parseRules(sources.remote) : null;

  const W = indexBlocks(work);
  const H = head ? indexBlocks(head) : null;
  const L = live ? indexBlocks(live) : null;
  const R = remote ? indexBlocks(remote) : null;

  const ids = new Set([...W.keys(), ...(H ? H.keys() : []), ...(L ? L.keys() : []), ...(R ? R.keys() : [])]);
  const out = [];

  for (const id of ids) {
    const w = W.get(id) || null, h = H ? (H.get(id) || null) : null;
    const l = L ? (L.get(id) || null) : null, r = R ? (R.get(id) || null) : null;
    const ref = w || h || l || r;
    const hw = h && w ? h.ownHash === w.ownHash : (h === w);          // HEAD vs WORK
    const lh = l && h ? l.ownHash === h.ownHash : (l === h);          // LIVE vs HEAD
    const rh = r && h ? r.ownHash === h.ownHash : (r === h);          // REMOTE vs HEAD
    const iEdited = H ? !hw : false;
    const drifted = L && H ? !lh : false;
    const unpulled = R && H ? !rh : false;
    const liveMatchesWork = l && w ? l.ownHash === w.ownHash : (!!l === !!w && !l);

    let status;
    // Order matters: "live already equals my disk" must be tested before
    // CONFLICT, or a change that was simply deployed-before-commit is reported
    // as a collision and the real advice (commit it) never surfaces.
    if (iEdited && drifted && liveMatchesWork) status = STATUS.LIVE_UNCOMMITTED;
    else if (iEdited && drifted) status = STATUS.CONFLICT;
    else if (iEdited) status = STATUS.MINE;
    else if (drifted) {
      // Distinguish "they deployed something I don't have" from "I committed
      // something nobody deployed". Both are LIVE≠HEAD, opposite remedies.
      // Presence settles it when one side is missing the block entirely;
      // otherwise fall back to which side's grants contain the other's.
      status = (h && !l) ? STATUS.UNDEPLOYED
             : (l && !h) ? STATUS.THEIRS
             : (direction(h, l) === 'git_adds') ? STATUS.UNDEPLOYED
             : STATUS.THEIRS;
    }
    // BEHIND is deliberately the LAST resort, claimed only by blocks that
    // would otherwise read `clean`. The live-axis states describe production,
    // which outranks "you have not pulled" — but an unpulled block with no
    // other difference must not vanish into the clean count.
    else if (unpulled) status = STATUS.BEHIND;
    else status = STATUS.CLEAN;

    const owner = resolveModules(ref.collection);
    out.push({
      id,
      collection: ref.collection,
      path: ref.fullPath,
      line: w ? w.start : (h ? h.start : (l ? l.start : r.start)),
      endLine: w ? w.end : (h ? h.end : (l ? l.end : r.end)),
      status,
      presence: { live: !!l, head: !!h, work: !!w, remote: !!r },
      hashes: { live: l && l.ownHash, head: h && h.ownHash, work: w && w.ownHash, remote: r && r.ownHash },
      // Orthogonal to `status`: a block can be MINE *and* unpulled, which is a
      // rebase conflict waiting to happen. Flattening that into one enum would
      // hide whichever axis lost the tie-break.
      // Which side holds more, for any block where live and git disagree.
      // `live_uncommitted` especially needs this: 46 of them meant "commit to
      // preserve", one meant "git has work production lost" — opposite actions
      // behind one label.
      direction: (l && h && l.ownHash !== h.ownHash) ? direction(h, l) : null,
      unpulled: R ? unpulled : null,
      // You edited a block that a teammate also changed on the remote. Neither
      // git nor the live axis warns about this until the merge actually fails.
      pullConflict: R ? (unpulled && iEdited) : null,
      allows: w ? w.allows : (h ? h.allows : (l ? l.allows : r.allows)),
      modules: owner.modules,
      matchedBy: owner.matchedBy,
    });
  }

  out.sort((a, b) => (a.line || 0) - (b.line || 0));

  const summary = out.reduce((acc, b) => { acc[b.status] = (acc[b.status] || 0) + 1; return acc; },
    { clean: 0, mine: 0, undeployed: 0, theirs: 0, conflict: 0, live_uncommitted: 0, behind: 0 });
  summary.total = out.length;
  summary.unpulled = out.filter(b => b.unpulled).length;
  summary.pullConflict = out.filter(b => b.pullConflict).length;

  // Helper-function drift matters more than any single block: every block that
  // calls isSameSchool() changes meaning when isSameSchool() changes.
  const fnDrift = [];
  if ((live && head) || (remote && head)) {
    const lf = new Map(live ? live.functions.map(f => [f.name, f]) : []);
    const hf = new Map(head ? head.functions.map(f => [f.name, f]) : []);
    const wf = new Map(work.functions.map(f => [f.name, f]));
    const rf = new Map(remote ? remote.functions.map(f => [f.name, f]) : []);
    for (const name of new Set([...lf.keys(), ...hf.keys(), ...wf.keys(), ...rf.keys()])) {
      const a = lf.get(name), b = hf.get(name), c = wf.get(name), d = rf.get(name);
      const liveVsHead = live && head ? (a && b ? a.bodyHash !== b.bodyHash : !!a !== !!b) : false;
      const headVsWork = head ? (b && c ? b.bodyHash !== c.bodyHash : !!b !== !!c) : false;
      const remoteVsHead = remote && head ? (d && b ? d.bodyHash !== b.bodyHash : !!d !== !!b) : false;
      if (liveVsHead || headVsWork || remoteVsHead) {
        fnDrift.push({ name, liveVsHead, headVsWork, remoteVsHead, line: (c || b || a || d).start });
      }
    }
  }

  return {
    blocks: out, summary, fnDrift,
    parsed: { work, head, live, remote },
    liveAvailable: !!live, remoteAvailable: !!remote,
  };
}

/**
 * Which side has MORE, when two versions of a block both exist and differ.
 *
 * Presence alone cannot separate "they deployed something git lacks" from
 * "git has something nobody deployed" — both are simply LIVE≠HEAD, and the
 * remedies are opposites (pull it in, versus ship it). The original code
 * defaulted the ambiguous case to `theirs`, which mislabels every
 * committed-but-unshipped block as a teammate's live work and tells you to
 * pull in something that does not exist.
 *
 * Containment over the canonical (comment-stripped) allow set answers it:
 * if every grant live makes is also in git, git is the superset.
 *
 * @returns 'live_adds' | 'git_adds' | 'divergent'
 */
function direction(headBlock, liveBlock) {
  if (!headBlock || !liveBlock) return 'divergent';
  const setOf = (b) => new Set((b.allows || []).map(a => `${a.ops}|${String(a.cond).replace(/\s+/g, ' ').trim()}`));
  const H = setOf(headBlock), L = setOf(liveBlock);
  const liveInHead = [...L].every(x => H.has(x));
  const headInLive = [...H].every(x => L.has(x));
  if (liveInHead && !headInLive) return 'git_adds';   // git is the superset → committed, not shipped
  if (headInLive && !liveInHead) return 'live_adds';  // live is the superset → deployed, not committed
  if (liveInHead && headInLive) {
    // Same grants, different surrounding text (helpers, comments-in-canon).
    // Fall back to size: the longer canonical body is the one with more in it.
    const hl = (headBlock.ownCanon || '').length, ll = (liveBlock.ownCanon || '').length;
    return hl > ll ? 'git_adds' : ll > hl ? 'live_adds' : 'divergent';
  }
  return 'divergent';
}

/** Group blocks by owning module — the "which product does this touch" view. */
function byModule(blocks) {
  const map = new Map();
  for (const b of blocks) {
    const keys = b.modules.length ? b.modules : ['(unmapped)'];
    for (const k of keys) {
      if (!map.has(k)) map.set(k, []);
      map.get(k).push(b);
    }
  }
  return map;
}

module.exports = { threeWay, compare, indexBlocks, modulesForCollection, resolveModules, byModule, direction, STATUS };
