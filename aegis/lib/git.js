'use strict';
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

/** Run git in a repo, return stdout (trimmed) or '' on failure. */
function git(repo, args) {
  try {
    return execFileSync('git', ['-C', repo, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  } catch (e) {
    return '';
  }
}

/** Changed files (absolute paths) in a repo vs a base ref. Includes staged, unstaged, and committed-vs-base. */
function changedFiles(repo, base) {
  const sets = new Set();
  const push = (out) => out.split('\n').map(s => s.trim()).filter(Boolean).forEach(f => sets.add(f));

  if (base) push(git(repo, ['diff', '--name-only', `${base}...HEAD`]));
  push(git(repo, ['diff', '--name-only']));          // unstaged
  push(git(repo, ['diff', '--name-only', '--cached'])); // staged

  return [...sets]
    .map(rel => path.resolve(repo, rel))
    .filter(p => fs.existsSync(p) || true); // keep deletions too
}

/** Files that WILL ship on push: changes in commits on HEAD not yet on base. */
function committedVsBase(repo, base) {
  if (!base) return [];
  return git(repo, ['diff', '--name-only', `${base}...HEAD`])
    .split('\n').map(s => s.trim()).filter(Boolean)
    .map(rel => path.resolve(repo, rel));
}

/** Files still UNCOMMITTED (staged + unstaged + untracked) — will NOT ship until committed. */
function uncommittedFiles(repo) {
  const tracked = git(repo, ['diff', '--name-only', 'HEAD']);              // working + index vs HEAD
  const untracked = git(repo, ['ls-files', '--others', '--exclude-standard']);
  return [tracked, untracked]
    .join('\n').split('\n').map(s => s.trim()).filter(Boolean)
    .map(rel => path.resolve(repo, rel));
}

/** Line-count churn for a repo diff vs base. */
function churn(repo, base) {
  const range = base ? [`${base}...HEAD`] : [];
  const stat = git(repo, ['diff', '--numstat', ...range]);
  let lines = 0;
  stat.split('\n').forEach(row => {
    const m = row.match(/^(\d+|-)\s+(\d+|-)/);
    if (m) lines += (parseInt(m[1], 10) || 0) + (parseInt(m[2], 10) || 0);
  });
  return lines;
}

/** Whether a specific file has uncommitted changes in a repo (for rules-drift guard). */
function isDirty(repo, relPath) {
  return git(repo, ['status', '--porcelain', relPath]).length > 0;
}

/** Diff of a single file vs a base ref (for rules review). */
function fileDiff(repo, relPath, base) {
  const range = base ? [`${base}...HEAD`] : [];
  return git(repo, ['diff', ...range, '--', relPath]);
}

module.exports = { git, changedFiles, committedVsBase, uncommittedFiles, churn, isDirty, fileDiff };
