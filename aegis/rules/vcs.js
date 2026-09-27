'use strict';
/**
 * The fourth leg: git REMOTE.
 *
 * The sentinel originally compared three versions — LIVE (Firebase Rules API),
 * HEAD (`git show HEAD:`) and WORK (disk). That catches the dangerous case
 * (a teammate deployed something nobody committed) but it has a blind spot that
 * git itself creates:
 *
 *   HEAD is your LOCAL head. It is not what the team has pushed.
 *
 * So if a colleague commits a rules change and pushes it, and you have not
 * fetched, then `git show HEAD:firestore.rules` still returns YOUR stale copy —
 * and the whole board confidently reports "clean". You then deploy, and the
 * whole-file deploy ships your older block over theirs. Same silent-revert
 * failure the tool exists to prevent, entered through a different door.
 *
 * This module adds REMOTE (`git show <upstream>:firestore.rules`) and, more
 * importantly, the branch-level verdict: are you behind, ahead, or diverged,
 * and does the divergence actually touch the rules file.
 *
 * Two constraints shaped the implementation:
 *
 * 1. `git fetch` is a network call, and `rules status` runs inside a PreToolUse
 *    hook with a 12s budget. So fetches are THROTTLED (a stamp in .state/) and
 *    hard-timeout'd. A fetch that cannot complete degrades to "remote unknown"
 *    and says so — it never hangs the hook and never silently pretends the
 *    remote was checked.
 * 2. Nothing here mutates the repo. No pull, no rebase, no checkout, no push.
 *    It computes the verdict and prints the exact command YOU should run.
 *    Deciding to move the branch is the user's call, every time.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const FETCH_STAMP = 'rules-fetch.json';
/** Default throttle for the advisory board. `plan`/`preflight` pass 0. */
const DEFAULT_FETCH_TTL_MS = 120000;
const FETCH_TIMEOUT_MS = 8000;
/** Past this, refs are reported as `stale` rather than `fresh`. */
const STALE_MS = 900000;

function stateDir(cfg) {
  const dir = path.join(cfg._root || path.resolve(__dirname, '..'), '.state');
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

/**
 * Run git and distinguish "it answered" from "it failed". lib/git.js collapses
 * both to '', which is fine for its callers but not here: an empty rules file
 * and an unreachable remote must not look the same.
 */
function git(repo, args, { timeoutMs = 5000, maxBuffer = 32 * 1024 * 1024 } = {}) {
  try {
    const out = execFileSync('git', ['-C', repo, ...args], {
      encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: timeoutMs, maxBuffer,
    });
    return { ok: true, out: out.replace(/\n$/, '') };
  } catch (e) {
    return { ok: false, out: '', err: (e.stderr || e.message || '').toString().trim(), timedOut: e.killed === true };
  }
}

/** The git repo that contains a file, per the surface map. */
function repoFor(cfg, file) {
  let best = null, bestLen = -1;
  for (const s of Object.values(cfg.surfaces || {})) {
    if (!s.gitRepo || !s.root) continue;
    const root = s.root.replace(/\/$/, '');
    if (file === root || file.startsWith(root + path.sep)) {
      if (root.length > bestLen) { best = s.gitRepo; bestLen = root.length; }
    }
  }
  return best;
}

// ── fetch throttling ─────────────────────────────────────────────────────────

function readStamp(cfg) {
  try { return JSON.parse(fs.readFileSync(path.join(stateDir(cfg), FETCH_STAMP), 'utf8')); }
  catch (e) { return {}; }
}

function writeStamp(cfg, repo, patch) {
  const all = readStamp(cfg);
  all[repo] = { ...(all[repo] || {}), ...patch };
  const p = path.join(stateDir(cfg), FETCH_STAMP);
  const tmp = `${p}.${process.pid}.tmp`;
  try { fs.writeFileSync(tmp, JSON.stringify(all, null, 2)); fs.renameSync(tmp, p); }
  catch (e) { /* best-effort memory; never fatal */ }
}

/**
 * Refresh remote-tracking refs, at most once per `maxAgeMs`.
 *
 * `--no-tags` and a single refspec keep it cheap; the deploy branch and the
 * work branch are both wanted, so we fetch the whole remote but skip tags.
 *
 * @returns {{state:'fresh'|'fetched'|'failed'|'skipped-offline'|'no-repo', ageMs?:number, err?:string}}
 */
function maybeFetch(cfg, repo, { maxAgeMs = DEFAULT_FETCH_TTL_MS, offline = false } = {}) {
  if (!repo) return { state: 'no-repo' };
  if (offline) return { state: 'skipped-offline' };

  const stamp = readStamp(cfg)[repo] || {};
  const age = stamp.at ? Date.now() - stamp.at : Infinity;
  if (age < maxAgeMs) {
    // Reusing refs is fine; pretending they are current is not. Past STALE_MS
    // the caller is told so it can hedge its language instead of asserting
    // "0 behind" from data that predates the teammate's push.
    if (age > STALE_MS) return { state: 'stale', ageMs: age };
    return { state: 'fresh', ageMs: age };
  }
  // maxAgeMs === Infinity means "never fetch" (the hook's fast path). If we
  // have never fetched at all there is nothing to reuse, and that is unknown —
  // not in-sync.
  if (!Number.isFinite(maxAgeMs)) {
    return stamp.at ? { state: 'stale', ageMs: age } : { state: 'never-fetched' };
  }

  const res = git(repo, ['fetch', '--no-tags', '--quiet'], { timeoutMs: FETCH_TIMEOUT_MS });
  if (!res.ok) {
    writeStamp(cfg, repo, { failedAt: Date.now(), err: res.err });
    return { state: 'failed', err: res.timedOut ? `timed out after ${FETCH_TIMEOUT_MS}ms` : res.err };
  }
  writeStamp(cfg, repo, { at: Date.now(), err: null });
  return { state: 'fetched', ageMs: 0 };
}

// ── branch topology ──────────────────────────────────────────────────────────

/**
 * Where this branch stands relative to its upstream.
 *
 * `rulesAhead`/`rulesBehind` are the ones that matter: a branch 40 commits
 * behind on PHP controllers is irrelevant to a rules deploy, while a single
 * unpulled commit that touches firestore.rules is a guaranteed silent revert.
 */
function branchInfo(cfg, repo, rulesFile) {
  if (!repo) return { ok: false, reason: 'no-repo' };

  const head = git(repo, ['rev-parse', '--abbrev-ref', 'HEAD']);
  if (!head.ok) return { ok: false, reason: 'not-a-repo', err: head.err };
  const branch = head.out;
  const detached = branch === 'HEAD';

  const up = git(repo, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}']);
  const upstream = up.ok && up.out ? up.out : null;

  const info = {
    ok: true, repo, branch, detached, upstream,
    ahead: 0, behind: 0, rulesAhead: 0, rulesBehind: 0,
    rulesDirty: false, rulesRel: null,
  };

  if (rulesFile) {
    info.rulesRel = path.relative(repo, rulesFile);
    const st = git(repo, ['status', '--porcelain', '--', info.rulesRel]);
    info.rulesDirty = st.ok && st.out.length > 0;
  }

  if (!upstream) { info.reason = 'no-upstream'; return info; }

  const counts = git(repo, ['rev-list', '--left-right', '--count', `${upstream}...HEAD`]);
  if (counts.ok) {
    const m = counts.out.split(/\s+/).map(n => parseInt(n, 10));
    if (m.length >= 2 && Number.isFinite(m[0]) && Number.isFinite(m[1])) {
      info.behind = m[0];
      info.ahead = m[1];
    }
  } else {
    // Upstream ref is configured but absent locally — never fetched, or the
    // remote branch was deleted. Treat as unknown rather than "in sync".
    info.reason = 'upstream-unreachable';
    info.upstreamMissing = true;
    return info;
  }

  if (info.rulesRel) {
    const b = git(repo, ['rev-list', '--count', `HEAD..${upstream}`, '--', info.rulesRel]);
    const a = git(repo, ['rev-list', '--count', `${upstream}..HEAD`, '--', info.rulesRel]);
    if (b.ok) info.rulesBehind = parseInt(b.out, 10) || 0;
    if (a.ok) info.rulesAhead = parseInt(a.out, 10) || 0;
    if (info.rulesBehind) {
      const who = git(repo, ['log', '--format=%h %an %ad %s', '--date=short', '-5',
        `HEAD..${upstream}`, '--', info.rulesRel]);
      info.rulesCommits = who.ok && who.out ? who.out.split('\n') : [];
    }
  }
  return info;
}

/** The committed-on-the-remote version of the rules file. */
function remoteSource(cfg, repo, rulesFile, upstream) {
  if (!repo || !upstream) return null;
  const rel = path.relative(repo, rulesFile);
  const res = git(repo, ['show', `${upstream}:${rel}`], { timeoutMs: 8000 });
  return res.ok ? res.out : null;
}

// ── the verdict ──────────────────────────────────────────────────────────────

/**
 * Turn topology into a decision, because "behind 3" is data and
 * "run this exact command before you deploy" is an answer.
 *
 * Severity ladder:
 *   block  — deploying or pushing now loses work that is already on the remote
 *   warn   — out of sync, but not in the rules file
 *   ok     — proceed
 *   unknown— the remote could not be consulted; say so, never assume ok
 */
function verdict(info, fetchState) {
  const v = verdictCore(info, fetchState);
  // Refs we did not just refresh can still be right — but an unqualified
  // "in sync with origin" drawn from 40-minute-old refs is an overclaim, and
  // overclaiming is the failure mode this whole module exists to remove.
  if (fetchState === 'stale' && v.level === 'ok') {
    return { ...v, stale: true, headline: `${v.headline} (from refs not refreshed recently — re-run with --fresh to be certain)` };
  }
  return fetchState === 'stale' ? { ...v, stale: true } : v;
}

function verdictCore(info, fetchState) {
  const remedyPull = info && info.upstream
    ? `git -C ${info.repo} pull --rebase ${String(info.upstream).replace('/', ' ')}`
    : null;

  if (!info || !info.ok) {
    return { level: 'unknown', code: info ? info.reason || 'no-repo' : 'no-repo',
      headline: 'rules file is not in a git repo Aegis knows about — remote drift cannot be checked.',
      remedy: null };
  }
  if (info.detached) {
    return { level: 'warn', code: 'detached',
      headline: 'HEAD is detached — there is no branch to compare against or push to.',
      remedy: `git -C ${info.repo} checkout <branch>` };
  }
  if (!info.upstream) {
    return { level: 'warn', code: 'no-upstream',
      headline: `branch "${info.branch}" tracks no remote — nothing can tell you if a teammate pushed rules.`,
      remedy: `git -C ${info.repo} branch --set-upstream-to=origin/${info.branch}` };
  }
  if (info.upstreamMissing) {
    return { level: 'unknown', code: 'upstream-unreachable',
      headline: `upstream ${info.upstream} is not present locally — fetch it before trusting this board.`,
      remedy: `git -C ${info.repo} fetch` };
  }
  if (fetchState === 'failed' || fetchState === 'skipped-offline' || fetchState === 'never-fetched') {
    const why = { failed: 'the fetch failed', 'skipped-offline': 'you are offline',
      'never-fetched': 'this repo has never been fetched by Aegis' }[fetchState];
    return { level: 'unknown', code: fetchState,
      headline: `remote state could not be established — ${why}. "Behind" counts are NOT trustworthy.`,
      remedy: `git -C ${info.repo} fetch` };
  }

  const bothMoved = info.ahead > 0 && info.behind > 0;
  if (info.rulesBehind > 0) {
    return { level: 'block', code: bothMoved ? 'diverged-rules' : 'behind-rules',
      headline: `${info.rulesBehind} unpulled commit(s) touch ${info.rulesRel}. ` +
                `Your HEAD is NOT what the team has — deploying now ships a stale file.`,
      remedy: remedyPull };
  }
  if (bothMoved) {
    return { level: 'warn', code: 'diverged',
      headline: `branch diverged (${info.ahead} ahead, ${info.behind} behind) — none of it in the rules file.`,
      remedy: remedyPull };
  }
  if (info.behind > 0) {
    return { level: 'warn', code: 'behind',
      headline: `${info.behind} commit(s) behind ${info.upstream}, none touching the rules file.`,
      remedy: remedyPull };
  }
  if (info.ahead > 0) {
    return { level: 'ok', code: 'ahead',
      headline: `${info.ahead} commit(s) ahead of ${info.upstream}, nothing to pull.`,
      remedy: null };
  }
  return { level: 'ok', code: 'in-sync', headline: `in sync with ${info.upstream}.`, remedy: null };
}

/**
 * One call for every consumer: fetch (throttled), read topology, read the
 * remote copy of the file, decide.
 */
function survey(cfg, rulesFile, { offline = false, maxAgeMs = DEFAULT_FETCH_TTL_MS } = {}) {
  const repo = repoFor(cfg, rulesFile);
  const fetch = maybeFetch(cfg, repo, { maxAgeMs, offline });
  const info = branchInfo(cfg, repo, rulesFile);
  const source = info.ok && info.upstream && !info.upstreamMissing
    ? remoteSource(cfg, repo, rulesFile, info.upstream)
    : null;
  return { repo, fetch, info, source, verdict: verdict(info, fetch.state) };
}

module.exports = {
  survey, maybeFetch, branchInfo, remoteSource, verdict, repoFor, git,
  DEFAULT_FETCH_TTL_MS,
};
