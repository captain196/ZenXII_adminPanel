'use strict';
/**
 * `aegis setup` — make a fresh clone work on a machine that is not Yugant's.
 *
 * Aegis observes five repos it does not contain. On the machine it was written
 * on those paths are simply true; anywhere else every one of them is wrong, and
 * the failure is unhelpful — `doctor` reports five missing surfaces and the
 * rules sentinel silently has no file to guard.
 *
 * This probes for each repo in the places people actually put them, writes only
 * what differs into the gitignored aegis.config.local.json, and installs the
 * subagent + hook. It never writes credentials and never overwrites a file it
 * did not create without being told to.
 */
const fs = require('fs');
const path = require('path');
const os = require('os');

const { ROOT, LOCAL_CONFIG_PATH, CONFIG_PATH } = require('./config');

/** Where each surface plausibly lives, most likely first. */
const CANDIDATES = {
  admin:   ['Desktop/Zennxii_adminPanel', 'Desktop/ZenXii_adminPanel', 'Desktop/Zenxii_adminPanel',
            'dev/Zennxii_adminPanel', 'projects/Zennxii_adminPanel', 'Zennxii_adminPanel',
            'code/Zennxii_adminPanel', 'Documents/Zennxii_adminPanel'],
  backend: ['Desktop/project2', 'dev/project2', 'projects/project2', 'project2'],
  teacher: ['AndroidStudioProjects/ZenXII_Teacher', 'StudioProjects/ZenXII_Teacher',
            'dev/ZenXII_Teacher', 'ZenXII_Teacher'],
  parent:  ['AndroidStudioProjects/ZenXII_Parent', 'StudioProjects/ZenXII_Parent',
            'dev/ZenXII_Parent', 'ZenXII_Parent'],
};

/** A directory only counts as a surface if it is actually that repo. */
const FINGERPRINT = {
  admin:   ['application/controllers', 'firebase-rules'],
  backend: ['server.js'],
  teacher: ['app/src/main/java'],
  parent:  ['app/src/main/java'],
};

function looksRight(dir, key) {
  if (!fs.existsSync(dir)) return false;
  return (FINGERPRINT[key] || []).every(f => fs.existsSync(path.join(dir, f)));
}

function probe(key, home) {
  for (const rel of CANDIDATES[key] || []) {
    const abs = path.join(home, rel);
    if (looksRight(abs, key)) return abs;
  }
  return null;
}

/** Nearest enclosing git repo, since apps are nested repos inside a parent. */
function gitRootOf(dir) {
  let cur = dir;
  for (let i = 0; i < 8 && cur && cur !== path.dirname(cur); i++) {
    if (fs.existsSync(path.join(cur, '.git'))) return cur;
    cur = path.dirname(cur);
  }
  return dir;
}

/** The service-account JSON, wherever it landed. Never copied — only located. */
function probeServiceAccount(adminRoot, home) {
  const dirs = [
    adminRoot && path.join(adminRoot, 'application/config'),
    path.join(home, 'Desktop/ZenXii'),
    path.join(home, '.config/aegis'),
    ROOT,
  ].filter(Boolean);
  for (const d of dirs) {
    let entries = [];
    try { entries = fs.readdirSync(d); } catch (e) { continue; }
    const hit = entries.find(f => /firebase-adminsdk.*\.json$/i.test(f));
    if (hit) return path.join(d, hit);
  }
  return null;
}

function detect({ home = os.homedir(), env = process.env } = {}) {
  const found = {};
  for (const key of Object.keys(CANDIDATES)) found[key] = probe(key, home);
  const sa = env.AEGIS_FIREBASE_SA || probeServiceAccount(found.admin, home);
  return { found, serviceAccount: sa };
}

/**
 * Build the local override. Only paths that differ from the committed defaults
 * are written — a local file that restates everything would silently pin a
 * colleague to today's layout and mask future changes to the shared config.
 */
function buildLocal(detected, base) {
  const local = {};
  const expandBase = (s) => typeof s === 'string' ? s.replace(/\$\{HOME\}/g, os.homedir()) : s;

  for (const [key, dir] of Object.entries(detected.found)) {
    if (!dir) continue;
    const baseRoot = expandBase(base.surfaces?.[key]?.root);
    if (baseRoot === dir) continue;
    local.surfaces = local.surfaces || {};
    local.surfaces[key] = { root: dir, gitRepo: gitRootOf(dir) };
  }

  if (detected.found.admin) {
    const admin = detected.found.admin;
    const rules = path.join(admin, 'firebase-rules/firestore.rules');
    if (expandBase(base.firestoreRules) !== rules) {
      local.firestoreRules = rules;
      local.firestoreIndexes = path.join(admin, 'firebase-rules/firestore.indexes.json');
      local.bugLedger = path.join(admin, 'BUG_LEDGER.md');
    }
  }
  if (detected.serviceAccount && expandBase(base.firebase?.serviceAccount) !== detected.serviceAccount) {
    local.firebase = { serviceAccount: detected.serviceAccount };
  }
  return local;
}

// ── agent + hook installation ────────────────────────────────────────────────

/**
 * The subagent and the hook live under `.claude/`, which is gitignored — so a
 * colleague's clone contains neither. Tracked masters are kept in `share/` and
 * copied into place here. Without this the sentinel still runs manually but
 * nothing invokes it automatically, which is most of its value.
 */
function installAgent(targetRepo, { force = false } = {}) {
  const src = path.join(ROOT, 'share', 'firestore-rules.agent.md');
  if (!fs.existsSync(src)) return { ok: false, reason: 'share/firestore-rules.agent.md missing' };
  const destDir = path.join(targetRepo, '.claude', 'agents');
  const dest = path.join(destDir, 'firestore-rules.md');
  if (fs.existsSync(dest) && !force) return { ok: true, skipped: true, dest };
  fs.mkdirSync(destDir, { recursive: true });
  fs.copyFileSync(src, dest);
  return { ok: true, dest };
}

function installHook({ force = false, home = os.homedir() } = {}) {
  const src = path.join(ROOT, 'share', 'firestore-rules-gate.sh');
  if (!fs.existsSync(src)) return { ok: false, reason: 'share/firestore-rules-gate.sh missing' };
  const destDir = path.join(home, '.claude', 'hooks');
  const dest = path.join(destDir, 'firestore-rules-gate.sh');
  if (fs.existsSync(dest) && !force) return { ok: true, skipped: true, dest };
  fs.mkdirSync(destDir, { recursive: true });
  fs.copyFileSync(src, dest);
  fs.chmodSync(dest, 0o755);
  return { ok: true, dest };
}

/** Whether settings.json already wires the hook — we print, never edit it. */
function hookWired(home = os.homedir()) {
  try {
    return fs.readFileSync(path.join(home, '.claude', 'settings.json'), 'utf8')
      .includes('firestore-rules-gate.sh');
  } catch (e) { return false; }
}

const HOOK_SNIPPET = `{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Edit|Write|MultiEdit",
        "hooks": [
          { "type": "command", "command": "$HOME/.claude/hooks/firestore-rules-gate.sh" }
        ]
      }
    ]
  }
}`;

function writeLocal(local) {
  if (!Object.keys(local).length) return { written: false };
  const payload = {
    $comment: 'Per-machine overrides for Aegis. GITIGNORED — never commit this. ' +
              'Deep-merged over aegis.config.json; env vars (AEGIS_*) still win over both.',
    ...local,
  };
  fs.writeFileSync(LOCAL_CONFIG_PATH, JSON.stringify(payload, null, 2) + '\n');
  return { written: true, path: LOCAL_CONFIG_PATH };
}

module.exports = {
  detect, buildLocal, writeLocal, installAgent, installHook, hookWired,
  gitRootOf, looksRight, CANDIDATES, HOOK_SNIPPET, CONFIG_PATH, LOCAL_CONFIG_PATH,
};
