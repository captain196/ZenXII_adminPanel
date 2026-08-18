'use strict';
/**
 * Cooperative block-level leases — the multi-tab problem.
 *
 * firestore.rules is one file edited by several Claude sessions at once, and a
 * deploy ships the WHOLE file. So two tabs editing two unrelated collections is
 * fine; two tabs editing the same match block is a lost edit, and one tab
 * deploying while another has a half-finished block on disk ships that
 * half-finished block to production.
 *
 * These leases are ADVISORY. Nothing here can stop a write — the goal is that
 * the second tab is told, before it edits, that someone else is already in that
 * block. That is the whole job: make the collision visible while it is still
 * cheap.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const DEFAULT_TTL_MIN = 120;

function stateDir(cfg) {
  const dir = path.join(cfg._root || path.resolve(__dirname, '..'), '.state');
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}
function lockPath(cfg) { return path.join(stateDir(cfg), 'rules-locks.json'); }

/**
 * Stable-ish identity for "this tab". Claude sessions are separate processes,
 * so the parent pid distinguishes them; an explicit label is far more useful to
 * a human reading `rules who`, so prefer it.
 */
function sessionId(explicit) {
  if (explicit) return explicit;
  if (process.env.AEGIS_SESSION) return process.env.AEGIS_SESSION;
  return `${os.userInfo().username}@${os.hostname().split('.')[0]}/pid${process.ppid}`;
}

function readLocks(cfg) {
  try {
    const raw = JSON.parse(fs.readFileSync(lockPath(cfg), 'utf8'));
    return Array.isArray(raw.leases) ? raw.leases : [];
  } catch (e) { return []; }
}

/** Atomic replace so two tabs writing at once cannot truncate each other. */
function writeLocks(cfg, leases) {
  const p = lockPath(cfg);
  const tmp = `${p}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify({ version: 1, leases }, null, 2));
  fs.renameSync(tmp, p);
}

function isExpired(lease, now = Date.now()) {
  const ttl = (lease.ttlMinutes || DEFAULT_TTL_MIN) * 60000;
  return now - new Date(lease.startedAt).getTime() > ttl;
}

/** Live leases, expired ones dropped (and persisted as dropped). */
function active(cfg) {
  const all = readLocks(cfg);
  const live = all.filter(l => !isExpired(l));
  if (live.length !== all.length) writeLocks(cfg, live);
  return live;
}

/**
 * Claim one or more blocks/collections.
 * @returns {{ok:boolean, lease?:Object, conflicts?:Array}}
 *          ok:false with conflicts when another live session holds an overlap.
 */
function claim(cfg, targets, { session, note = '', ttlMinutes = DEFAULT_TTL_MIN, force = false } = {}) {
  const me = sessionId(session);
  const want = [...new Set(targets.map(t => String(t).trim()).filter(Boolean))];
  const live = active(cfg);

  const conflicts = [];
  for (const l of live) {
    if (l.session === me) continue;
    const overlap = l.targets.filter(t => want.includes(t));
    if (overlap.length) conflicts.push({ session: l.session, overlap, startedAt: l.startedAt, note: l.note });
  }
  if (conflicts.length && !force) return { ok: false, conflicts };

  const mine = live.filter(l => l.session === me);
  const others = live.filter(l => l.session !== me);
  const merged = [...new Set([...mine.flatMap(l => l.targets), ...want])];
  const lease = {
    session: me,
    targets: merged,
    note: note || (mine[0] && mine[0].note) || '',
    startedAt: (mine[0] && mine[0].startedAt) || new Date().toISOString(),
    renewedAt: new Date().toISOString(),
    ttlMinutes,
    host: os.hostname().split('.')[0],
    pid: process.pid,
  };
  writeLocks(cfg, [...others, lease]);
  return { ok: true, lease, conflicts };
}

/** Release some or all of this session's targets. */
function release(cfg, targets, { session } = {}) {
  const me = sessionId(session);
  const live = active(cfg);
  const others = live.filter(l => l.session !== me);
  const mine = live.filter(l => l.session === me);
  if (!targets || !targets.length) { writeLocks(cfg, others); return { ok: true, released: mine.flatMap(l => l.targets) }; }
  const drop = new Set(targets.map(String));
  const kept = mine.map(l => ({ ...l, targets: l.targets.filter(t => !drop.has(t)) })).filter(l => l.targets.length);
  writeLocks(cfg, [...others, ...kept]);
  return { ok: true, released: [...drop] };
}

/** Leases held by anyone other than this session, keyed by target. */
function foreignHolders(cfg, session) {
  const me = sessionId(session);
  const map = new Map();
  for (const l of active(cfg)) {
    if (l.session === me) continue;
    for (const t of l.targets) {
      if (!map.has(t)) map.set(t, []);
      map.get(t).push(l);
    }
  }
  return map;
}

module.exports = { claim, release, active, foreignHolders, sessionId, stateDir, DEFAULT_TTL_MIN };
