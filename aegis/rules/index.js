'use strict';
/**
 * `aegis rules <cmd>` — the Firestore Rules Sentinel.
 *
 * Commands are deliberately thin: they gather evidence and print it. All
 * judgement lives in risk.js / diff.js so the same facts drive the CLI, the
 * gate, and the agent.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const { fetchLive, listRulesets, resolveServiceAccount } = require('./live');
const { threeWay, byModule, STATUS } = require('./diff');
const vcs = require('./vcs');
const { parseRules } = require('./parse');
const { scoreChange, overall, helperCallers, helperMap, SEV_ORDER } = require('./risk');
const lock = require('./lock');
const ledger = require('./ledger');
const { modules } = require('../manifest');

// ── presentation ─────────────────────────────────────────────────────────────
const C = process.stdout.isTTY && !process.env.NO_COLOR
  ? { r: '\x1b[31m', y: '\x1b[33m', g: '\x1b[32m', b: '\x1b[34m', m: '\x1b[35m', c: '\x1b[36m', d: '\x1b[2m', B: '\x1b[1m', x: '\x1b[0m' }
  : { r: '', y: '', g: '', b: '', m: '', c: '', d: '', B: '', x: '' };

const BADGE = {
  clean:            `${C.g}✓ clean${C.x}`,
  mine:             `${C.b}✎ mine${C.x}`,
  undeployed:       `${C.y}⇡ undeployed${C.x}`,
  theirs:           `${C.m}⇣ theirs${C.x}`,
  conflict:         `${C.r}✖ CONFLICT${C.x}`,
  live_uncommitted: `${C.y}⚑ LIVE-ONLY${C.x}`,
  behind:           `${C.c}⇊ BEHIND${C.x}`,
};
const VERDICT_COLOR = { block: C.r, warn: C.y, unknown: C.y, ok: C.g };
const VERDICT_MARK = { block: '✖', warn: '⚠', unknown: '?', ok: '✓' };
const SEV_COLOR = { critical: C.r, high: C.r, medium: C.y, low: C.d, info: C.d };

function hr(ch = '─', n = 74) { return ch.repeat(n); }
function fmtAgo(iso) {
  if (!iso) return 'never';
  const ms = Date.now() - new Date(iso).getTime();
  const m = Math.round(ms / 60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  if (h < 48) return `${h}h ago`;
  return `${Math.round(h / 24)}d ago`;
}

// ── sources ──────────────────────────────────────────────────────────────────
function rulesFile(cfg) { return cfg.firestoreRules; }

function gitRepoFor(cfg, file) {
  for (const s of Object.values(cfg.surfaces || {})) {
    if (s.gitRepo && file.startsWith(s.root)) return s.gitRepo;
  }
  return null;
}

/** Committed version of the rules file, or null when git can't answer. */
function headSource(cfg, ref = 'HEAD') {
  const file = rulesFile(cfg);
  const repo = gitRepoFor(cfg, file);
  if (!repo) return null;
  const rel = path.relative(repo, file);
  try {
    // stderr must be discarded, not inherited: when the surface is not a git
    // checkout (a fresh clone on a colleague's machine, a tarball copy) git
    // prints "fatal: not a git repository" straight to the terminal, above the
    // board. The absent HEAD is already reported properly as a ⚠ line.
    return execFileSync('git', ['-C', repo, 'show', `${ref}:${rel}`],
      { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 32 * 1024 * 1024 });
  } catch (e) { return null; }
}

function workSource(cfg) {
  try { return fs.readFileSync(rulesFile(cfg), 'utf8'); } catch (e) { return null; }
}

/**
 * Assemble every version, hitting the network only when asked.
 *
 * Two independent network calls: the Firebase Rules API for LIVE, and
 * `git fetch` for REMOTE. Each degrades on its own — losing one must never
 * suppress the other, because they detect different failure modes.
 *
 * `fetchMaxAgeMs` is separate from `maxAgeMs` on purpose. The live ruleset is
 * cached for 45s so the PreToolUse hook stays fast; `git fetch` is throttled
 * far more loosely (2 min) because it is the slower of the two and colleagues
 * push far less often than they deploy.
 */
async function gather(cfg, { offline = false, ref = 'HEAD', maxAgeMs = 0, fetchMaxAgeMs } = {}) {
  const work = workSource(cfg);
  if (work == null) throw new Error(`rules file unreadable: ${rulesFile(cfg)}`);
  const head = headSource(cfg, ref);
  let live = null, liveMeta = null;
  if (!offline) {
    const res = await fetchLive(cfg, 'cloud.firestore', { maxAgeMs });
    if (res.ok) { live = res.source; liveMeta = res; }
    else liveMeta = res;
  }
  const remoteSurvey = vcs.survey(cfg, rulesFile(cfg), {
    offline,
    maxAgeMs: fetchMaxAgeMs === undefined ? vcs.DEFAULT_FETCH_TTL_MS : fetchMaxAgeMs,
  });
  return { work, head, live, liveMeta, remote: remoteSurvey.source, vcs: remoteSurvey };
}

/**
 * The branch-sync block, shared by `status`, `plan` and `preflight` so all
 * three give byte-identical advice. Divergent phrasing between commands is how
 * a user ends up trusting the reassuring one.
 */
function printBranchSync(v, { compact = false } = {}) {
  const { info, verdict: vd, fetch } = v;
  const col = VERDICT_COLOR[vd.level] || C.d;
  console.log(`\n${C.B}Branch sync${C.x} ${C.d}(git remote — the axis LIVE-vs-HEAD cannot see)${C.x}`);
  if (info && info.ok) {
    const up = info.upstream || `${C.y}(no upstream)${C.x}`;
    console.log(`  branch   ${C.c}${info.branch}${C.x} → ${up}`);
    if (info.upstream && !info.upstreamMissing) {
      console.log(`  commits  ${info.ahead} ahead, ${info.behind} behind` +
        (info.rulesBehind ? `   ${C.r}${info.rulesBehind} unpulled touching the rules file${C.x}` : ''));
    }
    if (info.rulesDirty) console.log(`  worktree ${C.b}rules file has uncommitted changes${C.x}`);
  }
  const fetchNote = {
    fetched: 'just fetched',
    fresh: 'refs fresh',
    stale: `${C.y}refs STALE (${fmtAgo(new Date(Date.now() - (fetch.ageMs || 0)).toISOString())}) — not refreshed this run${C.x}`,
    'never-fetched': `${C.y}never fetched — remote state unknown${C.x}`,
    failed: `${C.y}fetch FAILED${C.x}`,
    'skipped-offline': `${C.y}offline — refs may be stale${C.x}`,
    'no-repo': 'no repo',
  }[fetch.state] || fetch.state;
  console.log(`  refs     ${fetchNote}${fetch.err ? ` ${C.d}(${fetch.err})${C.x}` : ''}`);
  console.log(`  ${col}${VERDICT_MARK[vd.level]} ${vd.headline}${C.x}`);
  if (vd.remedy && !compact) console.log(`    ${C.B}→ ${vd.remedy}${C.x}`);
  if (info && info.rulesCommits && info.rulesCommits.length && !compact) {
    console.log(`    ${C.d}unpulled commits in the rules file:${C.x}`);
    for (const line of info.rulesCommits) console.log(`      ${C.d}${line}${C.x}`);
  }
}

// ── commands ─────────────────────────────────────────────────────────────────

/** rules sync — refresh the live snapshot and say whether prod moved. */
async function cmdSync(cfg, args) {
  const json = args.includes('--json');
  const res = await fetchLive(cfg);
  if (!res.ok) {
    const out = { ok: false, reason: res.reason, detail: res.detail };
    if (json) { console.log(JSON.stringify(out, null, 2)); return 2; }
    console.log(`${C.r}✖ cannot read live rules${C.x} — ${res.reason}`);
    console.log(`  ${res.detail || ''}`);
    if (res.reason === 'no-credentials') {
      console.log(`  ${C.d}Add "firebase": { "project": "...", "serviceAccount": "/abs/path.json" } to aegis.config.json${C.x}`);
    }
    return 2;
  }
  const events = ledger.readAll(cfg);
  const since = ledger.deployedSinceLastSeen(cfg, res.rulesetId, events);
  ledger.archiveSnapshot(cfg, res.rulesetId, res.source);
  ledger.record(cfg, 'snapshot', {
    rulesetId: res.rulesetId, createTime: res.createTime,
    releaseUpdateTime: res.releaseUpdateTime, bytes: res.source.length,
  });

  if (json) { console.log(JSON.stringify({ ok: true, ...res, source: undefined, since }, null, 2)); return 0; }
  console.log(`${C.B}Live ruleset${C.x}  ${res.project} / ${res.release}`);
  console.log(`  ruleset    ${res.rulesetId}`);
  console.log(`  deployed   ${res.createTime}  (${fmtAgo(res.createTime)})`);
  console.log(`  size       ${res.source.length} bytes, ${res.source.split('\n').length} lines`);
  if (since === null) console.log(`  ${C.d}first observation on this machine — baseline recorded${C.x}`);
  else if (since.changed) {
    console.log(`\n  ${C.m}${C.B}⇣ PRODUCTION MOVED since you last looked (${fmtAgo(since.lastSeenAt)})${C.x}`);
    console.log(`    ${since.previousRulesetId} → ${since.currentRulesetId}`);
    console.log(`    ${C.d}run \`aegis rules status\` to see which blocks${C.x}`);
  } else console.log(`  ${C.g}unchanged since your last check (${fmtAgo(since.lastSeenAt)})${C.x}`);
  return 0;
}

/** rules status — the board. This is the command to run before touching anything. */
async function cmdStatus(cfg, args) {
  const json = args.includes('--json');
  const offline = args.includes('--offline');
  const verbose = args.includes('--verbose') || args.includes('-v');
  // A few seconds of staleness is fine for an advisory board and keeps the
  // PreToolUse hook inside its time budget. `--fresh` forces a refetch.
  const maxAgeMs = args.includes('--fresh') ? 0 : 45000;
  // --no-fetch reuses existing remote-tracking refs without a network call, for
  // callers on a hard time budget (the PreToolUse hook). Staleness is then
  // reported rather than hidden.
  const fetchMaxAgeMs = args.includes('--fresh') ? 0
    : args.includes('--no-fetch') ? Infinity : undefined;
  const { work, head, live, liveMeta, remote, vcs: v } = await gather(cfg, { offline, maxAgeMs, fetchMaxAgeMs });

  const result = threeWay({ work, head, live, remote });
  const holders = lock.foreignHolders(cfg, argValue(args, '--session'));
  const me = lock.sessionId(argValue(args, '--session'));

  // Risk + lease overlay on everything that is not clean.
  const interesting = result.blocks.filter(b => b.status !== STATUS.CLEAN);
  const helpers = helperMap(result.parsed.work);
  const enriched = interesting.map(b => {
    const w = result.parsed.work && result.parsed.work.blocks.find(x => x.key === b.id.split('#')[0] && x.start === b.line);
    const h = result.parsed.head && result.parsed.head.blocks.find(x => x.key === b.id.split('#')[0]);
    const findings = scoreChange(h || null, w || null, helpers);
    return { ...b, risk: overall(findings), findings, heldBy: holders.get(b.collection) || holders.get(b.id) || null };
  });
  enriched.sort((a, b) => (SEV_ORDER[b.risk] - SEV_ORDER[a.risk]) || (a.line - b.line));

  if (json) {
    console.log(JSON.stringify({
      summary: result.summary, liveAvailable: result.liveAvailable,
      liveMeta: liveMeta && { ok: liveMeta.ok, rulesetId: liveMeta.rulesetId, createTime: liveMeta.createTime, reason: liveMeta.reason },
      remoteAvailable: result.remoteAvailable,
      git: {
        branch: v.info && v.info.branch, upstream: v.info && v.info.upstream,
        ahead: v.info && v.info.ahead, behind: v.info && v.info.behind,
        rulesBehind: v.info && v.info.rulesBehind, rulesAhead: v.info && v.info.rulesAhead,
        rulesDirty: v.info && v.info.rulesDirty, rulesCommits: (v.info && v.info.rulesCommits) || [],
        fetch: v.fetch.state, verdict: v.verdict,
      },
      blocks: enriched, fnDrift: result.fnDrift, session: me,
      leases: lock.active(cfg),
    }, null, 2));
    // Exit 1 on anything that makes a deploy unsafe — a CONFLICT block, or a
    // rules file the team has moved past. Callers gate on the exit code.
    const unsafe = enriched.some(b => b.status === STATUS.CONFLICT) || v.verdict.level === 'block';
    return unsafe ? 1 : 0;
  }

  console.log(`${C.B}Firestore Rules — four-way status${C.x} ${C.d}(live · remote · HEAD · disk)${C.x}`);
  console.log(hr());
  if (!result.liveAvailable) {
    console.log(`${C.y}⚠ LIVE not read${C.x} (${liveMeta ? liveMeta.reason : 'offline'}) — "theirs" drift cannot be detected.`);
  } else {
    console.log(`live ruleset ${liveMeta.rulesetId}  deployed ${fmtAgo(liveMeta.createTime)}`);
  }
  if (!result.remoteAvailable) {
    console.log(`${C.y}⚠ REMOTE not read${C.x} (${v.verdict.code}) — "behind" drift cannot be detected.`);
  }
  const s = result.summary;
  console.log(`blocks ${s.total}   ${C.g}clean ${s.clean}${C.x}   ${C.b}mine ${s.mine}${C.x}   ${C.y}undeployed ${s.undeployed}${C.x}   ${C.m}theirs ${s.theirs}${C.x}   ${C.y}live-only ${s.live_uncommitted}${C.x}   ${C.c}behind ${s.behind}${C.x}   ${C.r}conflict ${s.conflict}${C.x}`);
  console.log(`session ${C.c}${me}${C.x}`);

  printBranchSync(v);

  if (s.pullConflict) {
    console.log(`\n  ${C.r}${C.B}✖ ${s.pullConflict} block(s) you edited were ALSO changed on the remote.${C.x}`);
    console.log(`  ${C.r}A pull will conflict here; resolving it wrong silently drops one side.${C.x}`);
  }

  if (result.fnDrift.length) {
    console.log(`\n${C.B}Shared helpers changed${C.x} ${C.d}(these re-point every block that calls them)${C.x}`);
    for (const f of result.fnDrift) {
      const callers = helperCallers(result.parsed.work, f.name);
      const who = f.liveVsHead ? `${C.m}live≠head${C.x}` : '';
      const mine = f.headVsWork ? `${C.b}head≠work${C.x}` : '';
      const rem = f.remoteVsHead ? `${C.c}remote≠head${C.x}` : '';
      console.log(`  ${C.r}${f.name}()${C.x} L${f.line}  ${[who, mine, rem].filter(Boolean).join(' ')}  → ${callers.length} calling block(s)`);
    }
  }

  if (!enriched.length) {
    console.log(`\n${C.g}✓ every block agrees across live, HEAD and your working tree.${C.x}`);
  } else {
    console.log(`\n${C.B}Blocks needing attention${C.x}`);
    for (const b of enriched) {
      const mods = b.modules.length ? b.modules.join(', ') : `${C.d}unmapped${C.x}`;
      console.log(`\n  ${BADGE[b.status]}  ${C.B}${b.collection || b.path}${C.x}  ${C.d}L${b.line}-${b.endLine}${C.x}`);
      console.log(`    module   ${mods}`);
      if (b.risk !== 'info') console.log(`    risk     ${SEV_COLOR[b.risk]}${b.risk}${C.x}`);
      if (b.heldBy) {
        for (const l of b.heldBy) console.log(`    ${C.r}leased by ${l.session}${C.x} since ${fmtAgo(l.startedAt)}${l.note ? ` — ${l.note}` : ''}`);
      }
      if (b.status === STATUS.CONFLICT) {
        console.log(`    ${C.r}You edited this block AND it drifted in production.${C.x}`);
        console.log(`    ${C.r}Deploying now reverts their change. Reconcile before you ship.${C.x}`);
      }
      if (b.status === STATUS.THEIRS) {
        console.log(`    ${C.m}Production has a version your branch never saw. Pull it in first.${C.x}`);
      }
      if (b.status === STATUS.LIVE_UNCOMMITTED) {
        console.log(`    ${C.y}Live matches your disk, but this exists in NO commit.${C.x}`);
        console.log(`    ${C.y}Production is the only copy — a deploy from a clean checkout reverts it. Commit it.${C.x}`);
      }
      if (b.status === STATUS.BEHIND) {
        console.log(`    ${C.c}A teammate committed and PUSHED this; you have not pulled it.${C.x}`);
        console.log(`    ${C.c}Nobody deployed it, so production looks fine — but your file is older. Pull first.${C.x}`);
      }
      if (b.pullConflict && b.status !== STATUS.BEHIND) {
        console.log(`    ${C.r}⇊ Also changed on the remote — pulling will conflict in this block.${C.x}`);
      }
      for (const f of b.findings.slice(0, verbose ? 99 : 3)) {
        console.log(`    ${SEV_COLOR[f.severity]}• [${f.severity}] ${f.msg}${C.x}`);
      }
    }
  }

  // Remember what we saw so the next run can say "since you last looked".
  const drifted = enriched.filter(b => b.status === STATUS.THEIRS || b.status === STATUS.CONFLICT);
  if (drifted.length) {
    ledger.record(cfg, 'drift', {
      blocks: drifted.map(b => ({ id: b.id, collection: b.collection, modules: b.modules, status: b.status })),
      rulesetId: liveMeta && liveMeta.rulesetId,
    });
  }
  if (s.conflict) {
    ledger.record(cfg, 'conflict', { session: me, blocks: enriched.filter(b => b.status === STATUS.CONFLICT).map(b => b.id) });
    console.log(`\n${C.r}${C.B}✖ ${s.conflict} conflicting block(s) — do not deploy.${C.x}`);
    return 1;
  }
  if (v.verdict.level === 'block') {
    console.log(`\n${C.r}${C.B}✖ your branch is behind the team in the rules file — pull before you deploy or push.${C.x}`);
    console.log(`${C.d}  ${v.verdict.remedy}${C.x}`);
    return 1;
  }
  return 0;
}

/** rules blocks [filter] — the map: which block belongs to which module. */
async function cmdBlocks(cfg, args) {
  const json = args.includes('--json');
  const filter = args.find(a => !a.startsWith('-'));
  const parsed = parseRules(workSource(cfg));
  const result = threeWay({ work: workSource(cfg), head: null, live: null });
  let blocks = result.blocks;
  if (filter) {
    const f = filter.toLowerCase();
    blocks = blocks.filter(b =>
      (b.collection || '').toLowerCase().includes(f) ||
      b.modules.some(m => m.toLowerCase().includes(f)) ||
      b.path.toLowerCase().includes(f));
  }
  if (json) { console.log(JSON.stringify(blocks, null, 2)); return 0; }

  console.log(`${C.B}Rules block map${C.x}${filter ? ` — filter “${filter}”` : ''}  ${C.d}(${blocks.length} of ${result.blocks.length})${C.x}`);
  console.log(hr());
  const groups = byModule(blocks);
  for (const [mod, list] of [...groups.entries()].sort()) {
    const note = modules[mod] && modules[mod].notes;
    console.log(`\n${C.B}${C.c}${mod}${C.x} ${C.d}(${list.length})${C.x}`);
    if (note) console.log(`  ${C.d}${note.slice(0, 100)}${C.x}`);
    for (const b of list.sort((a, c) => a.line - c.line)) {
      const grants = b.allows.map(a => a.ops).join(' / ') || '—';
      console.log(`  ${String(b.line).padStart(4)}  ${(b.collection || b.path).padEnd(30)} ${C.d}${grants}${C.x}`);
    }
  }
  if (!filter) {
    const unmapped = groups.get('(unmapped)') || [];
    if (unmapped.length) {
      console.log(`\n${C.y}${unmapped.length} block(s) map to no module.${C.x} ${C.d}Add their collection to manifest.js → firestore/rules_blocks so impact analysis can see them.${C.x}`);
    }
  }
  return 0;
}

/** rules who — who is holding what, right now. */
function cmdWho(cfg, args) {
  const json = args.includes('--json');
  const leases = lock.active(cfg);
  if (json) { console.log(JSON.stringify(leases, null, 2)); return 0; }
  const me = lock.sessionId(argValue(args, '--session'));
  console.log(`${C.B}Active rules leases${C.x}  ${C.d}(you: ${me})${C.x}`);
  console.log(hr());
  if (!leases.length) { console.log(`${C.d}none — no session has claimed a block.${C.x}`); return 0; }
  for (const l of leases) {
    const mine = l.session === me;
    console.log(`${mine ? C.g + '● you' : C.m + '○    '}${C.x} ${C.B}${l.session}${C.x} ${C.d}${l.host} pid${l.pid}${C.x}`);
    console.log(`     since ${fmtAgo(l.startedAt)}, renewed ${fmtAgo(l.renewedAt)}, ttl ${l.ttlMinutes}m`);
    if (l.note) console.log(`     note: ${l.note}`);
    console.log(`     ${l.targets.join(', ')}`);
  }
  return 0;
}

function cmdClaim(cfg, args) {
  const targets = args.filter(a => !a.startsWith('-') && !isFlagValue(args, a));
  if (!targets.length) { console.log('usage: aegis rules claim <collection|blockId> [...] [--note "..."] [--session X] [--force]'); return 2; }
  const res = lock.claim(cfg, targets, {
    session: argValue(args, '--session'),
    note: argValue(args, '--note') || '',
    ttlMinutes: Number(argValue(args, '--ttl')) || lock.DEFAULT_TTL_MIN,
    force: args.includes('--force'),
  });
  if (!res.ok) {
    console.log(`${C.r}✖ already leased${C.x}`);
    for (const c of res.conflicts) {
      console.log(`  ${C.B}${c.session}${C.x} holds ${c.overlap.join(', ')} since ${fmtAgo(c.startedAt)}${c.note ? ` — ${c.note}` : ''}`);
    }
    console.log(`\n  ${C.d}Coordinate, or re-run with --force if that session is gone.${C.x}`);
    return 1;
  }
  ledger.record(cfg, 'claim', { session: res.lease.session, targets });
  console.log(`${C.g}✓ leased${C.x} ${targets.join(', ')} as ${C.c}${res.lease.session}${C.x} (ttl ${res.lease.ttlMinutes}m)`);
  return 0;
}

function cmdRelease(cfg, args) {
  const targets = args.filter(a => !a.startsWith('-') && !isFlagValue(args, a));
  const res = lock.release(cfg, targets, { session: argValue(args, '--session') });
  ledger.record(cfg, 'release', { targets: res.released });
  console.log(`${C.g}✓ released${C.x} ${res.released.length ? res.released.join(', ') : '(nothing held)'}`);
  return 0;
}

/**
 * rules plan — the pre-deploy brief.
 *
 * `firebase deploy --only firestore:rules` ships the ENTIRE file. So the
 * question before deploying is never "is my change good" — it is "what else is
 * in this file that I am about to ship on someone else's behalf".
 */
async function cmdPlan(cfg, args) {
  const json = args.includes('--json');
  // A deploy plan never serves cached evidence: both the live ruleset and the
  // remote refs are refetched, because this is the gate that decides whether
  // production changes.
  const { work, head, live, liveMeta, remote, vcs: v } = await gather(cfg, { maxAgeMs: 0, fetchMaxAgeMs: 0 });
  if (!live) {
    console.log(`${C.r}✖ cannot build a deploy plan without the live ruleset${C.x} — ${liveMeta && liveMeta.reason}`);
    return 2;
  }
  const result = threeWay({ work, head, live, remote });
  const shipping = result.blocks.filter(b => {
    const h = b.hashes;
    return h.work !== h.live; // anything whose live state differs from what I'd upload
  });

  const mine = shipping.filter(b => b.status === STATUS.MINE);
  const theirs = shipping.filter(b => b.status === STATUS.THEIRS);
  const conflict = shipping.filter(b => b.status === STATUS.CONFLICT);
  const undeployed = shipping.filter(b => b.status === STATUS.UNDEPLOYED);
  // Live-only blocks are already identical to what you'd upload, so they carry
  // no deploy risk — they carry a *durability* risk, reported separately below.
  const liveOnly = result.blocks.filter(b => b.status === STATUS.LIVE_UNCOMMITTED);

  const behind = result.blocks.filter(b => b.status === STATUS.BEHIND);
  const pullConflicts = result.blocks.filter(b => b.pullConflict);
  const gitBlocks = v.verdict.level === 'block';

  if (json) {
    console.log(JSON.stringify({
      mine, theirs, conflict, undeployed, behind, pullConflicts,
      git: v.verdict, safe: !(conflict.length || theirs.length || gitBlocks),
    }, null, 2));
    return conflict.length || theirs.length || gitBlocks ? 1 : 0;
  }

  console.log(`${C.B}Deploy plan — firestore.rules${C.x}`);
  console.log(hr());
  console.log(`${C.d}A rules deploy replaces the whole file. Everything below goes live together.${C.x}\n`);
  console.log(`live ruleset ${liveMeta.rulesetId}, deployed ${fmtAgo(liveMeta.createTime)}`);
  console.log(`blocks differing from live: ${shipping.length}`);
  printBranchSync(v);
  console.log('');

  const section = (title, list, colour, advice) => {
    if (!list.length) return;
    console.log(`${colour}${C.B}${title} (${list.length})${C.x}`);
    for (const b of list) console.log(`  L${String(b.line).padStart(4)}  ${(b.collection || b.path).padEnd(30)} ${C.d}${b.modules.join(', ') || 'unmapped'}${C.x}`);
    if (advice) console.log(`  ${C.d}${advice}${C.x}`);
    console.log('');
  };
  section('Your changes', mine, C.b, 'Intended — verify with the emulator tests before shipping.');
  section('Committed but never deployed', undeployed, C.y, 'Already in git; confirm it was meant to ship, not left mid-work.');
  section('NOT YOURS — live has a version your file lacks', theirs, C.m,
    'Deploying now OVERWRITES these with your older copy. Pull the live version in first.');
  section('CONFLICT — you edited a block that also drifted', conflict, C.r,
    'Reconcile by hand. Whichever side you keep, the other is silently lost.');
  section('BEHIND — pushed by a teammate, never pulled by you', behind, C.c,
    'Not deployed by anyone, so live looks fine — but your file is older. Pull before shipping.');
  section('Will CONFLICT on pull — you and the remote both changed these', pullConflicts, C.r,
    'Pull first and resolve deliberately; a bad resolution here silently drops one side.');

  if (liveOnly.length) {
    console.log(`${C.y}${C.B}⚑ Live but uncommitted (${liveOnly.length})${C.x}`);
    for (const b of liveOnly) console.log(`  L${String(b.line).padStart(4)}  ${(b.collection || b.path).padEnd(30)} ${C.d}${b.modules.join(', ') || 'unmapped'}${C.x}`);
    console.log(`  ${C.d}Identical to live, so safe to ship — but in no commit. Anyone deploying${C.x}`);
    console.log(`  ${C.d}from a clean checkout wipes it. Commit these regardless of this deploy.${C.x}\n`);
  }

  if (conflict.length || theirs.length || gitBlocks) {
    console.log(`${C.r}${C.B}✖ NOT SAFE TO DEPLOY${C.x}`);
    if (theirs.length || conflict.length) {
      console.log(`  ${theirs.length + conflict.length} block(s) would lose work that is live right now.`);
      console.log(`${C.d}  Recover the live copy:  aegis rules pull > /tmp/live.rules${C.x}`);
    }
    if (gitBlocks) {
      console.log(`  ${v.verdict.headline}`);
      console.log(`${C.d}  ${v.verdict.remedy}${C.x}`);
      console.log(`${C.d}  Then re-run this plan — a rebase changes the board.${C.x}`);
    }
    return 1;
  }
  console.log(`${C.g}${C.B}✓ safe to deploy${C.x} — everything differing from live is yours or already committed,`);
  console.log(`  and your branch is not behind the team in this file.`);
  if (v.verdict.level === 'unknown') {
    console.log(`  ${C.y}⚠ but the remote could NOT be checked (${v.verdict.code}) — "not behind" is unverified.${C.x}`);
  }
  console.log(`${C.d}  Reminder: rules, indexes and PHP deploy separately. Ship indexes first.${C.x}`);
  return 0;
}

/**
 * rules preflight — the one command to run before you deploy OR push.
 *
 * `status` answers "what is the state of the world"; `plan` answers "what would
 * a deploy ship". Neither answers the question that actually comes first in
 * practice: *do I need to pull, and in what order do I do these things.*
 *
 * It never runs git for you. It fetches (read-only), works out the correct
 * sequence, and prints it. Moving the branch and shipping to production are
 * decisions that stay with the user — the tool's job is to make sure the
 * decision is an informed one.
 */
async function cmdPreflight(cfg, args) {
  const json = args.includes('--json');
  const { work, head, live, liveMeta, remote, vcs: v } = await gather(cfg, { maxAgeMs: 0, fetchMaxAgeMs: 0 });
  const result = threeWay({ work, head, live, remote });
  const s = result.summary;
  const info = v.info || {};

  const steps = [];
  const blockers = [];

  if (v.verdict.level === 'block') {
    blockers.push(v.verdict.headline);
    if (s.pullConflict) {
      steps.push({ n: steps.length + 1, cmd: v.verdict.remedy,
        note: `${s.pullConflict} block(s) WILL conflict — resolve keeping BOTH sides' intent, not whichever is easier.` });
    } else {
      steps.push({ n: steps.length + 1, cmd: v.verdict.remedy, note: 'clean rebase expected — no block overlaps your edits.' });
    }
    steps.push({ n: steps.length + 1, cmd: 'node cli.js rules status', note: 'the rebase moves HEAD; the whole board must be re-read.' });
  } else if (v.verdict.level === 'warn' && v.verdict.remedy) {
    steps.push({ n: steps.length + 1, cmd: v.verdict.remedy, note: `optional — ${v.verdict.headline}` });
  }

  if (s.conflict) blockers.push(`${s.conflict} block(s) conflict with production.`);
  if (s.theirs) blockers.push(`${s.theirs} block(s) exist in production but not in your file — deploying reverts them.`);
  if (v.verdict.level === 'unknown') blockers.push(`remote state unknown (${v.verdict.code}) — cannot prove you are up to date.`);

  if (info.rulesDirty) {
    steps.push({ n: steps.length + 1, cmd: `cd ${path.dirname(rulesFile(cfg))}/tests && npm test`, note: 'emulator suite — the only place a rule is actually proven.' });
    steps.push({ n: steps.length + 1, cmd: 'node cli.js rules plan', note: 'final gate; must print "safe to deploy".' });
    steps.push({ n: steps.length + 1, cmd: `git -C ${info.repo || '<repo>'} add ${info.rulesRel || 'firestore.rules'} && git commit`, note: 'commit BEFORE deploying, so production is never the only copy.' });
    steps.push({ n: steps.length + 1, cmd: `git -C ${info.repo || '<repo>'} push`, note: 'push so the next teammate\'s `rules status` can see your change.' });
  }
  steps.push({ n: steps.length + 1, cmd: 'firebase deploy --only firestore:rules --project ' + ((cfg.firebase && cfg.firebase.project) || 'graderadmin'),
    note: 'ships the WHOLE file. Indexes first if this change needs one. Requires explicit permission.' });
  steps.push({ n: steps.length + 1, cmd: 'node cli.js rules sync', note: 'record the new ruleset in the ledger.' });

  if (json) { console.log(JSON.stringify({ blockers, steps, git: v.verdict, summary: s }, null, 2)); return blockers.length ? 1 : 0; }

  console.log(`${C.B}Rules preflight${C.x} ${C.d}— pull/rebase order, then deploy${C.x}`);
  console.log(hr());
  if (result.liveAvailable) console.log(`live     ${liveMeta.rulesetId}  deployed ${fmtAgo(liveMeta.createTime)}`);
  else console.log(`live     ${C.y}unavailable (${liveMeta && liveMeta.reason})${C.x}`);
  console.log(`blocks   ${s.total} total · ${C.b}${s.mine} yours${C.x} · ${C.c}${s.behind} behind${C.x} · ${C.m}${s.theirs} theirs${C.x} · ${C.r}${s.conflict} conflict${C.x}`);
  printBranchSync(v, { compact: true });

  if (blockers.length) {
    console.log(`\n${C.r}${C.B}Blockers — resolve these before anything ships${C.x}`);
    for (const b of blockers) console.log(`  ${C.r}✖ ${b}${C.x}`);
  } else {
    console.log(`\n${C.g}${C.B}✓ no blockers — you are up to date with both git and production.${C.x}`);
  }

  console.log(`\n${C.B}Correct order${C.x}`);
  for (const st of steps) {
    console.log(`  ${C.B}${st.n}.${C.x} ${C.c}${st.cmd}${C.x}`);
    console.log(`     ${C.d}${st.note}${C.x}`);
  }
  console.log(`\n${C.d}Aegis runs none of these for you. Commit, push and deploy are your call, every time.${C.x}`);
  return blockers.length ? 1 : 0;
}

/** rules pull — print the live ruleset (to diff or to recover from). */
async function cmdPull(cfg, args) {
  const res = await fetchLive(cfg);
  if (!res.ok) { process.stderr.write(`cannot fetch live rules: ${res.reason} ${res.detail || ''}\n`); return 2; }
  process.stdout.write(res.source);
  return 0;
}

/** rules history — recent rulesets, i.e. the real deploy log. */
async function cmdHistory(cfg, args) {
  const res = await listRulesets(cfg, Number(argValue(args, '--limit')) || 15);
  if (!res.ok) { console.log(`${C.r}✖ ${res.reason}${C.x} ${res.detail || ''}`); return 2; }
  console.log(`${C.B}Ruleset history${C.x} ${C.d}(most recent first — each entry is one deploy)${C.x}`);
  console.log(hr());
  for (const r of res.rulesets) {
    console.log(`  ${String(r.name).split('/').pop()}  ${r.createTime}  ${C.d}${fmtAgo(r.createTime)}${C.x}`);
  }
  return 0;
}

/** rules insights — what the ledger has learned. */
function cmdInsights(cfg, args) {
  const json = args.includes('--json');
  const ins = ledger.insights(cfg);
  if (json) { console.log(JSON.stringify(ins, null, 2)); return 0; }
  console.log(`${C.B}Rules sentinel memory${C.x}`);
  console.log(hr());
  console.log(`observations   ${ins.events}${ins.firstSeen ? `  since ${fmtAgo(ins.firstSeen)}` : ''}`);
  console.log(`rulesets seen  ${ins.distinctRulesetsObserved}`);
  console.log(`conflicts hit  ${ins.conflictsSeen}`);
  if (ins.hottestModules.length) {
    console.log(`\n${C.B}Modules whose rules change most${C.x}`);
    for (const m of ins.hottestModules) console.log(`  ${String(m.count).padStart(3)}×  ${m.key}`);
  }
  if (ins.hottestBlocks.length) {
    console.log(`\n${C.B}Blocks that churn most${C.x} ${C.d}(candidates for extra test coverage)${C.x}`);
    for (const b of ins.hottestBlocks) console.log(`  ${String(b.count).padStart(3)}×  ${b.key}`);
  }
  if (!ins.events) console.log(`\n${C.d}No history yet. Run \`aegis rules status\` a few times.${C.x}`);
  return 0;
}

// ── arg helpers ──────────────────────────────────────────────────────────────
function argValue(args, flag) {
  const i = args.indexOf(flag);
  return i >= 0 && args[i + 1] && !args[i + 1].startsWith('--') ? args[i + 1] : null;
}
function isFlagValue(args, token) {
  const i = args.indexOf(token);
  return i > 0 && args[i - 1].startsWith('--');
}

const HELP = `
${C.B}aegis rules${C.x} — Firestore Rules Sentinel

  ${C.B}status${C.x}  [-v] [--offline] [--json]   four-way board: live vs remote vs HEAD vs your disk
  ${C.B}preflight${C.x} [--json]                  pull/rebase order, then deploy — run this FIRST
  ${C.B}plan${C.x}    [--json]                    pre-deploy brief: what the whole-file deploy ships
  ${C.B}sync${C.x}    [--json]                    refresh live snapshot; flags a deploy since last look
  ${C.B}blocks${C.x}  [filter] [--json]           block → module map
  ${C.B}who${C.x}     [--json]                    active leases across sessions
  ${C.B}claim${C.x}   <target...> [--note "..."]  lease blocks before editing
  ${C.B}release${C.x} [target...]                 drop your leases
  ${C.B}pull${C.x}                                print the live ruleset to stdout
  ${C.B}history${C.x} [--limit N]                 recent rulesets (the real deploy log)
  ${C.B}insights${C.x}[--json]                    what the ledger has learned

  Common flags: --session <label> to name this tab.
`;

async function run(cfg, args) {
  const sub = args[0];
  const rest = args.slice(1);
  switch (sub) {
    case 'status':   return await cmdStatus(cfg, rest);
    case 'preflight':return await cmdPreflight(cfg, rest);
    case 'plan':     return await cmdPlan(cfg, rest);
    case 'sync':     return await cmdSync(cfg, rest);
    case 'blocks':   return await cmdBlocks(cfg, rest);
    case 'who':      return cmdWho(cfg, rest);
    case 'claim':    return cmdClaim(cfg, rest);
    case 'release':  return cmdRelease(cfg, rest);
    case 'pull':     return await cmdPull(cfg, rest);
    case 'history':  return await cmdHistory(cfg, rest);
    case 'insights': return cmdInsights(cfg, rest);
    case undefined:
    case 'help':     console.log(HELP); return 0;
    default:         console.log(`unknown: rules ${sub}`); console.log(HELP); return 2;
  }
}

module.exports = { run, gather, headSource, workSource };
