'use strict';
/**
 * `aegis indexes <cmd>` — the Firestore Index Sentinel.
 *
 * The rules sentinel's sibling. Same four sources, same honesty rules, opposite
 * severity ordering (see indexes/diff.js for why).
 *
 * Commands stay thin: gather evidence, print it. Judgement lives in diff.js so
 * the CLI, the gate and the agent all act on identical facts.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const { fetchLiveIndexes, parseIndexFile, describeKey, toIndexFile } = require('./live');
const { fourWay, byCollection, verdict, STATUS } = require('./diff');
const vcs = require('../rules/vcs');

const C = process.stdout.isTTY && !process.env.NO_COLOR
  ? { r: '\x1b[31m', y: '\x1b[33m', g: '\x1b[32m', b: '\x1b[34m', m: '\x1b[35m', c: '\x1b[36m', d: '\x1b[2m', B: '\x1b[1m', x: '\x1b[0m' }
  : { r: '', y: '', g: '', b: '', m: '', c: '', d: '', B: '', x: '' };

const BADGE = {
  clean:            `${C.g}✓ clean${C.x}`,
  mine:             `${C.b}✎ mine${C.x}`,
  undeployed:       `${C.r}✖ NOT DEPLOYED${C.x}`,
  live_only:        `${C.y}⚑ LIVE-ONLY${C.x}`,
  behind:           `${C.c}⇊ BEHIND${C.x}`,
  dropped_locally:  `${C.m}⌫ dropped locally${C.x}`,
  building:         `${C.y}⏳ BUILDING${C.x}`,
};
const VCOLOR = { block: C.r, warn: C.y, unknown: C.y, ok: C.g };
const VMARK = { block: '✖', warn: '⚠', unknown: '?', ok: '✓' };

function hr(ch = '─', n = 74) { return ch.repeat(n); }

// ── sources ──────────────────────────────────────────────────────────────────

function indexFile(cfg) { return cfg.firestoreIndexes; }

function repoFor(cfg, file) { return vcs.repoFor(cfg, file); }

function gitShow(repo, ref, rel) {
  try {
    return execFileSync('git', ['-C', repo, 'show', `${ref}:${rel}`],
      { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 32 * 1024 * 1024 });
  } catch (e) { return null; }
}

/**
 * Assemble all four sources. Each degrades independently — losing the live read
 * must not suppress the git comparison, and vice versa.
 */
async function gather(cfg, { offline = false, maxAgeMs = 0, fetchMaxAgeMs } = {}) {
  const file = indexFile(cfg);
  if (!file) throw new Error('no firestoreIndexes path configured — add it to aegis.config.json');

  let workText = null;
  try { workText = fs.readFileSync(file, 'utf8'); }
  catch (e) { throw new Error(`indexes file unreadable: ${file}`); }
  const work = parseIndexFile(workText, 'working file');

  const repo = repoFor(cfg, file);
  const rel = repo ? path.relative(repo, file) : null;
  const headText = repo ? gitShow(repo, 'HEAD', rel) : null;
  const head = headText ? parseIndexFile(headText, 'HEAD') : { ok: false };

  const survey = vcs.survey(cfg, file, {
    offline,
    maxAgeMs: fetchMaxAgeMs === undefined ? vcs.DEFAULT_FETCH_TTL_MS : fetchMaxAgeMs,
  });
  const upstream = survey.info && survey.info.upstream;
  const remoteText = repo && upstream && !survey.info.upstreamMissing ? gitShow(repo, upstream, rel) : null;
  const remote = remoteText ? parseIndexFile(remoteText, 'remote') : { ok: false };

  let live = { ok: false, reason: offline ? 'offline' : 'not-attempted' };
  if (!offline) live = await fetchLiveIndexes(cfg, { maxAgeMs });

  return { file, work, head, remote, live, vcs: survey, repo, rel };
}

function build(g) {
  return fourWay({
    live: g.live.ok ? g.live.byKey : null,
    remote: g.remote.ok ? g.remote.byKey : null,
    head: g.head.ok ? g.head.byKey : null,
    work: g.work.ok ? g.work.byKey : new Map(),
  });
}

function fmtIndex(i, indent = '    ') {
  const d = describeKey(i.key);
  return `${indent}${C.B}${d.collectionGroup}${C.x} ${C.d}[${d.queryScope}]${C.x}\n${indent}  ${C.d}${d.fields}${C.x}`;
}

// ── commands ─────────────────────────────────────────────────────────────────

/** indexes status — the board. */
async function cmdStatus(cfg, args) {
  const json = args.includes('--json');
  const offline = args.includes('--offline');
  const verbose = args.includes('--verbose') || args.includes('-v');
  const maxAgeMs = args.includes('--fresh') ? 0 : 45000;
  const fetchMaxAgeMs = args.includes('--fresh') ? 0 : args.includes('--no-fetch') ? Infinity : undefined;

  const g = await gather(cfg, { offline, maxAgeMs, fetchMaxAgeMs });
  const r = build(g);
  const v = verdict(r);

  if (json) {
    console.log(JSON.stringify({
      summary: r.summary, liveAvailable: r.liveAvailable, remoteAvailable: r.remoteAvailable,
      verdict: v,
      live: g.live.ok ? { count: g.live.count, project: g.live.project } : { ok: false, reason: g.live.reason, detail: g.live.detail },
      git: { branch: g.vcs.info && g.vcs.info.branch, upstream: g.vcs.info && g.vcs.info.upstream,
             behind: g.vcs.info && g.vcs.info.behind, fileBehind: g.vcs.info && g.vcs.info.rulesBehind,
             fetch: g.vcs.fetch.state },
      indexes: r.indexes.filter(i => i.status !== STATUS.CLEAN),
    }, null, 2));
    return v.level === 'block' ? 1 : 0;
  }

  console.log(`${C.B}Firestore Indexes — four-way status${C.x} ${C.d}(live · remote · HEAD · disk)${C.x}`);
  console.log(hr());
  if (!r.liveAvailable) {
    console.log(`${C.y}⚠ LIVE not read${C.x} (${g.live.reason}${g.live.detail ? ': ' + g.live.detail : ''})`);
    console.log(`${C.y}  "deployed" cannot be verified this run.${C.x}`);
  } else {
    console.log(`live ${C.B}${g.live.count}${C.x} composite index(es) in ${g.live.project}`);
  }
  if (!r.headAvailable) console.log(`${C.y}⚠ HEAD copy of the indexes file not readable — git comparison unavailable.${C.x}`);
  if (!r.remoteAvailable) console.log(`${C.y}⚠ REMOTE not read (${g.vcs.verdict.code}) — "behind" cannot be detected.${C.x}`);

  const s = r.summary;
  console.log(`declared ${g.work.ok ? g.work.count : '?'} on disk` +
    (g.head.ok ? `, ${g.head.count} in HEAD` : '') +
    (g.remote.ok ? `, ${g.remote.count} on ${g.vcs.info.upstream}` : ''));
  console.log(`\n${C.g}clean ${s.clean}${C.x}   ${C.r}not-deployed ${s.undeployed}${C.x}   ${C.y}building ${s.building}${C.x}   ` +
    `${C.c}behind ${s.behind}${C.x}   ${C.y}live-only ${s.live_only}${C.x}   ${C.b}mine ${s.mine}${C.x}   ${C.m}dropped ${s.dropped_locally}${C.x}`);

  console.log(`\n  ${VCOLOR[v.level]}${VMARK[v.level]} ${v.headline}${C.x}`);
  if (v.remedy) console.log(`    ${C.B}→ ${v.remedy}${C.x}`);

  // The emergencies, individually. Everything else is summarised by collection
  // because 101 live-only entries printed in full is a wall nobody reads.
  const urgent = r.indexes.filter(i => i.status === STATUS.UNDEPLOYED || i.status === STATUS.BUILDING);
  if (urgent.length) {
    console.log(`\n${C.r}${C.B}Queries that are failing or will fail${C.x}`);
    for (const i of urgent) {
      console.log(`\n  ${BADGE[i.status]}${i.state && i.state !== 'READY' ? ` ${C.d}(${i.state})${C.x}` : ''}`);
      console.log(fmtIndex(i));
      if (i.comment) console.log(`      ${C.d}${String(i.comment).slice(0, 140)}${C.x}`);
    }
  }

  const others = r.indexes.filter(i =>
    i.status !== STATUS.CLEAN && i.status !== STATUS.UNDEPLOYED && i.status !== STATUS.BUILDING);
  if (others.length) {
    const groups = byCollection(others);
    console.log(`\n${C.B}Other differences${C.x} ${C.d}(${others.length} across ${groups.size} collection group(s))${C.x}`);
    const sorted = [...groups.entries()].sort((a, b) => b[1].length - a[1].length);
    for (const [coll, list] of (verbose ? sorted : sorted.slice(0, 20))) {
      const tally = list.reduce((m, i) => { m[i.status] = (m[i.status] || 0) + 1; return m; }, {});
      const label = Object.entries(tally).map(([k, n]) => `${n} ${k.replace('_', '-')}`).join(', ');
      console.log(`  ${String(list.length).padStart(3)}  ${C.B}${coll.padEnd(32)}${C.x} ${C.d}${label}${C.x}`);
    }
    if (!verbose && sorted.length > 20) console.log(`  ${C.d}… ${sorted.length - 20} more collection group(s) — pass -v${C.x}`);
  }

  if (s.live_only) {
    console.log(`\n${C.y}${s.live_only} index(es) exist in production but in NO commit.${C.x}`);
    console.log(`${C.d}  Not an outage — an index deploy only creates, it does not delete undeclared ones.${C.x}`);
    console.log(`${C.d}  The defect is reproducibility: a staging or restored project built from${C.x}`);
    console.log(`${C.d}  firestore.indexes.json comes up missing them, and those queries fail.${C.x}`);
    console.log(`${C.d}  Recover:  node cli.js indexes pull > /tmp/firestore.indexes.json${C.x}`);
  }
  return v.level === 'block' ? 1 : 0;
}

/** indexes plan — what a deploy would create, and what it would leave behind. */
async function cmdPlan(cfg, args) {
  const json = args.includes('--json');
  const g = await gather(cfg, { maxAgeMs: 0, fetchMaxAgeMs: 0 });
  if (!g.live.ok) {
    console.log(`${C.r}✖ cannot build a deploy plan without the live index list${C.x} — ${g.live.reason} ${g.live.detail || ''}`);
    return 2;
  }
  const r = build(g);
  const v = verdict(r);

  const willCreate = r.indexes.filter(i => i.presence.work && !i.presence.live);
  const undocumented = r.indexes.filter(i => i.status === STATUS.LIVE_ONLY);
  const building = r.indexes.filter(i => i.status === STATUS.BUILDING);

  if (json) {
    console.log(JSON.stringify({ willCreate, undocumented, building, verdict: v, summary: r.summary }, null, 2));
    return 0;
  }

  console.log(`${C.B}Deploy plan — firestore.indexes.json${C.x}`);
  console.log(hr());
  console.log(`${C.d}An index deploy CREATES what is missing. It does not delete indexes that${C.x}`);
  console.log(`${C.d}are absent from the file, so nothing below is destructive.${C.x}\n`);
  console.log(`live ${g.live.count} · declared ${g.work.count}`);

  if (willCreate.length) {
    console.log(`\n${C.g}${C.B}Would CREATE (${willCreate.length})${C.x}`);
    for (const i of willCreate) console.log(fmtIndex(i, '  '));
    console.log(`\n  ${C.y}Indexes build ASYNCHRONOUSLY. Queries needing them keep failing until READY —${C.x}`);
    console.log(`  ${C.y}which is why indexes ship BEFORE the code that depends on them.${C.x}`);
  } else {
    console.log(`\n${C.g}✓ nothing to create — every declared index already exists in production.${C.x}`);
  }

  if (building.length) {
    console.log(`\n${C.y}${C.B}Still building (${building.length})${C.x} ${C.d}— not serving queries yet${C.x}`);
    for (const i of building) console.log(fmtIndex(i, '  '));
  }

  if (undocumented.length) {
    console.log(`\n${C.y}${C.B}Left in place, undocumented (${undocumented.length})${C.x}`);
    const groups = byCollection(undocumented);
    for (const [coll, list] of [...groups.entries()].sort((a, b) => b[1].length - a[1].length)) {
      console.log(`  ${String(list.length).padStart(3)}  ${coll}`);
    }
    console.log(`  ${C.d}This deploy neither creates nor removes them. They stay missing from git.${C.x}`);
  }

  console.log(`\n  ${VCOLOR[v.level]}${VMARK[v.level]} ${v.headline}${C.x}`);
  return 0;
}

/** indexes pull — emit production as a firestore.indexes.json. */
async function cmdPull(cfg, args) {
  const g = await gather(cfg, { maxAgeMs: 0, offline: false });
  if (!g.live.ok) {
    process.stderr.write(`cannot fetch live indexes: ${g.live.reason} ${g.live.detail || ''}\n`);
    return 2;
  }
  // Preserve the working file's fieldOverrides: the Admin API's index list does
  // not carry them, and emitting {} would look like the user had none.
  const overrides = g.work.ok ? g.work.fieldOverrides : [];
  process.stdout.write(JSON.stringify(toIndexFile(g.live.indexes, overrides), null, 2) + '\n');
  return 0;
}

/** indexes preflight — order gate before shipping index-dependent code. */
async function cmdPreflight(cfg, args) {
  const json = args.includes('--json');
  const g = await gather(cfg, { maxAgeMs: 0, fetchMaxAgeMs: 0 });
  const r = build(g);
  const v = verdict(r);
  const s = r.summary;

  const blockers = [];
  if (v.level === 'block') blockers.push(v.headline);
  if (v.level === 'unknown') blockers.push(v.headline);
  if (g.vcs.verdict.level === 'block') blockers.push(g.vcs.verdict.headline);

  const steps = [];
  if (g.vcs.verdict.level === 'block' && g.vcs.verdict.remedy) {
    steps.push([g.vcs.verdict.remedy, 'your branch is behind in this file — pull before deciding anything.']);
  }
  if (s.behind) steps.push(['git pull --rebase', `${s.behind} index(es) were pushed by a teammate.`]);
  if (s.mine) steps.push([`git add ${g.rel || 'firestore.indexes.json'} && git commit`, `${s.mine} index(es) exist only in your working file.`]);
  if (s.undeployed || s.mine) {
    steps.push([`firebase deploy --only firestore:indexes --project ${(cfg.firebase && cfg.firebase.project) || 'graderadmin'}`,
      'INDEXES FIRST — they build async, and the code that queries them fails until READY.']);
    steps.push(['node cli.js indexes status', 'confirm every index reports READY before shipping the code.']);
  }
  if (s.live_only) {
    steps.push(['node cli.js indexes pull > /tmp/firestore.indexes.json',
      `${s.live_only} live index(es) are in no commit — reconcile so git can rebuild the database.`]);
  }
  if (!steps.length) steps.push(['(nothing)', 'indexes agree across production, git and disk.']);

  if (json) { console.log(JSON.stringify({ blockers, steps, verdict: v, summary: s }, null, 2)); return blockers.length ? 1 : 0; }

  console.log(`${C.B}Index preflight${C.x} ${C.d}— ship indexes before the code that needs them${C.x}`);
  console.log(hr());
  console.log(`live ${g.live.ok ? g.live.count : C.y + 'unavailable' + C.x} · declared ${g.work.ok ? g.work.count : '?'}`);
  console.log(`${C.r}not-deployed ${s.undeployed}${C.x} · ${C.y}building ${s.building}${C.x} · ${C.c}behind ${s.behind}${C.x} · ${C.y}live-only ${s.live_only}${C.x}`);

  if (blockers.length) {
    console.log(`\n${C.r}${C.B}Blockers${C.x}`);
    for (const b of blockers) console.log(`  ${C.r}✖ ${b}${C.x}`);
  } else {
    console.log(`\n${C.g}${C.B}✓ no blockers — every committed index is live and READY.${C.x}`);
  }

  console.log(`\n${C.B}Correct order${C.x}`);
  steps.forEach(([cmd, note], n) => {
    console.log(`  ${C.B}${n + 1}.${C.x} ${C.c}${cmd}${C.x}`);
    console.log(`     ${C.d}${note}${C.x}`);
  });
  console.log(`\n${C.d}Aegis runs none of these for you.${C.x}`);
  return blockers.length ? 1 : 0;
}

const HELP = `
${C.B}aegis indexes${C.x} — Firestore Index Sentinel

  ${C.B}status${C.x}    [-v] [--fresh] [--offline] [--no-fetch] [--json]
            four-way board: live vs remote vs HEAD vs your disk
  ${C.B}preflight${C.x} [--json]   what to do, in order, before shipping index-dependent code
  ${C.B}plan${C.x}      [--json]   what a deploy would create (deploys never delete)
  ${C.B}pull${C.x}                 print production as a firestore.indexes.json

  ${C.d}Severity is INVERTED vs rules: for indexes the emergency is a COMMITTED
  index that is not deployed (its query is failing now). A live index that is
  in no commit is a reproducibility defect, not an outage.${C.x}
`;

async function run(cfg, args) {
  const sub = args[0];
  const rest = args.slice(1);
  switch (sub) {
    case 'status':    return await cmdStatus(cfg, rest);
    case 'preflight': return await cmdPreflight(cfg, rest);
    case 'plan':      return await cmdPlan(cfg, rest);
    case 'pull':      return await cmdPull(cfg, rest);
    case undefined:
    case 'help':      console.log(HELP); return 0;
    default:          console.log(`unknown: indexes ${sub}`); console.log(HELP); return 2;
  }
}

module.exports = { run, gather, build };
