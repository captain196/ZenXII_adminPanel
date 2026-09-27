'use strict';
/**
 * Four-way comparison of composite indexes: LIVE · REMOTE · HEAD · WORK.
 *
 * Same four sources as the rules sentinel, but indexes are a SET keyed by
 * (collection group, query scope, ordered fields) rather than a file of match
 * blocks — so identity is structural and there is no "edited in place" case.
 * An index is present or it is not.
 *
 * THE SEVERITY IS INVERTED RELATIVE TO RULES, and this is the single most
 * important thing to get right:
 *
 *   rules   — `theirs` (live has what git lacks) is the emergency, because a
 *             whole-file deploy silently REVERTS it.
 *   indexes — `undeployed` (git has what live lacks) is the emergency, because
 *             the query that needs it is failing in production RIGHT NOW with
 *             FAILED_PRECONDITION. An index deploy only ever CREATES; the
 *             Firebase CLI does not quietly delete indexes that are missing
 *             from the file, so `live_only` cannot be reverted out from under
 *             you the way a rules block can.
 *
 * `live_only` is still a real defect, just a different one: it means git cannot
 * rebuild this database. A staging project or a restored environment
 * provisioned from firestore.indexes.json comes up missing those indexes, and
 * every query behind them fails.
 */

const STATUS = {
  CLEAN: 'clean',
  // In the working file but not committed — a pending addition.
  MINE: 'mine',
  // Committed (and pushed) but NOT in production. The query needing it is
  // broken right now. For indexes this is the top severity.
  UNDEPLOYED: 'undeployed',
  // In production, in no commit. Not an outage — an unreproducible database.
  LIVE_ONLY: 'live_only',
  // On the remote, not in your HEAD: a teammate pushed an index you lack.
  BEHIND: 'behind',
  // You removed it locally, but it is committed and live. Deleting it from the
  // file does NOT delete it from production — it only stops documenting it.
  DROPPED_LOCALLY: 'dropped_locally',
  // Live but not yet serving queries (CREATING / NEEDS_REPAIR).
  BUILDING: 'building',
};

/** Highest first — drives sort order and the exit code. */
const SEVERITY = {
  [STATUS.UNDEPLOYED]: 5,
  [STATUS.BUILDING]: 4,
  [STATUS.BEHIND]: 3,
  [STATUS.LIVE_ONLY]: 2,
  [STATUS.DROPPED_LOCALLY]: 2,
  [STATUS.MINE]: 1,
  [STATUS.CLEAN]: 0,
};

/**
 * @param sources {{live:Map|null, remote:Map|null, head:Map|null, work:Map}}
 *        each a Map of key -> index record (see indexes/live.js).
 */
function fourWay(sources) {
  const { live, remote, head, work } = sources;
  const keys = new Set([
    ...(work ? work.keys() : []),
    ...(head ? head.keys() : []),
    ...(live ? live.keys() : []),
    ...(remote ? remote.keys() : []),
  ]);

  const out = [];
  for (const key of keys) {
    const w = work ? work.get(key) || null : null;
    const h = head ? head.get(key) || null : null;
    const l = live ? live.get(key) || null : null;
    const r = remote ? remote.get(key) || null : null;
    const ref = w || h || l || r;

    let status;
    // Order matters. "Live has it but it is not serving yet" outranks every
    // git-side observation: the index is declared and deployed, and the query
    // still fails until the build finishes.
    if (l && l.state && l.state !== 'READY') status = STATUS.BUILDING;
    // Committed AND still in the working file, but absent from production.
    else if (h && w && !l) status = STATUS.UNDEPLOYED;
    // Committed, absent from live, and you deleted it locally too — the
    // deletion is the intent; live never had it. Still not deployed.
    else if (h && !w && !l) status = STATUS.UNDEPLOYED;
    // Present locally but never committed.
    else if (w && !h) status = STATUS.MINE;
    // Committed + live, but you removed it from your working file.
    else if (h && !w && l) status = STATUS.DROPPED_LOCALLY;
    // Production has it and git does not.
    else if (l && !h && !w) status = STATUS.LIVE_ONLY;
    else status = STATUS.CLEAN;

    // Orthogonal to status, exactly as in the rules board: a teammate pushed
    // this index and you have not pulled. It can be true of a block that is
    // already interesting for another reason.
    const unpulled = remote ? (!!r !== !!h) : null;
    if (status === STATUS.CLEAN && unpulled && r && !h) status = STATUS.BEHIND;

    out.push({
      key,
      collectionGroup: ref.collectionGroup,
      queryScope: ref.queryScope,
      fields: ref.fields,
      status,
      state: l ? l.state : null,
      presence: { live: !!l, remote: !!r, head: !!h, work: !!w },
      unpulled,
      comment: (w && w.comment) || (h && h.comment) || null,
      severity: SEVERITY[status] || 0,
    });
  }

  out.sort((a, b) => b.severity - a.severity ||
    a.collectionGroup.localeCompare(b.collectionGroup) || a.key.localeCompare(b.key));

  const summary = out.reduce((acc, i) => { acc[i.status] = (acc[i.status] || 0) + 1; return acc; },
    { clean: 0, mine: 0, undeployed: 0, live_only: 0, behind: 0, dropped_locally: 0, building: 0 });
  summary.total = out.length;
  summary.unpulled = out.filter(i => i.unpulled).length;

  return {
    indexes: out,
    summary,
    liveAvailable: !!live,
    remoteAvailable: !!remote,
    headAvailable: !!head,
  };
}

/** Group by collection group — the "which module does this touch" view. */
function byCollection(list) {
  const m = new Map();
  for (const i of list) {
    if (!m.has(i.collectionGroup)) m.set(i.collectionGroup, []);
    m.get(i.collectionGroup).push(i);
  }
  return m;
}

/**
 * Is it safe to ship code that depends on these indexes?
 *
 * Deliberately narrow: only `undeployed` and `building` block, because only
 * those mean a query fails right now. `live_only` is a reproducibility defect
 * and must not stop a deploy — blocking on 101 pre-existing undocumented
 * indexes would make the gate useless on day one and it would get muted.
 */
function verdict(result) {
  const s = result.summary;
  if (!result.liveAvailable) {
    return { level: 'unknown', code: 'live-unreadable',
      headline: 'production index list could not be read — "all deployed" is unverified.' };
  }
  if (s.undeployed) {
    return { level: 'block', code: 'undeployed',
      headline: `${s.undeployed} committed index(es) are NOT in production — queries needing them fail with FAILED_PRECONDITION.`,
      remedy: 'firebase deploy --only firestore:indexes --project <project>' };
  }
  if (s.building) {
    return { level: 'block', code: 'building',
      headline: `${s.building} index(es) are deployed but still BUILDING — queries fail until they are READY.`,
      remedy: 'wait for the build to finish, then re-run `aegis indexes status`' };
  }
  if (s.behind) {
    return { level: 'warn', code: 'behind',
      headline: `${s.behind} index(es) were pushed by a teammate and you have not pulled them.`,
      remedy: 'git pull --rebase' };
  }
  if (s.mine) {
    return { level: 'warn', code: 'uncommitted',
      headline: `${s.mine} index(es) exist only in your working file — commit and deploy before the code that needs them.`,
      remedy: null };
  }
  if (s.live_only) {
    return { level: 'warn', code: 'undocumented',
      headline: `${s.live_only} index(es) exist in production but in no commit — git cannot rebuild this database.`,
      remedy: 'aegis indexes pull > firestore.indexes.json  (review, then commit)' };
  }
  return { level: 'ok', code: 'in-sync', headline: 'every index agrees across production, git and your working file.' };
}

module.exports = { fourWay, byCollection, verdict, STATUS, SEVERITY };
