'use strict';
/**
 * Config loading, resolution and portability.
 *
 * The original config was a flat JSON of absolute paths beginning
 * `/Users/yuggi/...`. That is correct on exactly one machine. The moment a
 * colleague clones the repo, every path is wrong, `doctor` fails on all five
 * surfaces, and the rules sentinel cannot find the file it exists to guard.
 *
 * Three layers, lowest precedence first:
 *
 *   1. aegis.config.json         committed, portable, uses ${HOME}
 *   2. aegis.config.local.json   gitignored, per-machine overrides (deep merged)
 *   3. AEGIS_* environment vars  per-shell overrides, highest precedence
 *
 * Layer 2 is the one a colleague edits. It is deep-merged, so overriding one
 * surface root does not require restating the other four — and it is
 * gitignored, so nobody's local paths ever land in a commit and break everyone
 * else. That mattered more than it sounds: a committed absolute path is a
 * merge conflict on every single pull.
 */
const fs = require('fs');
const path = require('path');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const CONFIG_PATH = process.env.AEGIS_CONFIG || path.join(ROOT, 'aegis.config.json');
const LOCAL_CONFIG_PATH = path.join(ROOT, 'aegis.config.local.json');

/**
 * Expand `~` and `${VAR}` in every string, recursively.
 *
 * An unset `${VAR}` is deliberately left as-is rather than blanked: an
 * unresolved `${ADMIN_ROOT}/firestore.rules` in a doctor error tells you the
 * variable is missing, whereas `/firestore.rules` looks like a corrupted path
 * and sends you hunting in the wrong place.
 */
function expand(value, env) {
  if (typeof value === 'string') {
    let out = value;
    if (out === '~') out = os.homedir();
    else if (out.startsWith('~/')) out = path.join(os.homedir(), out.slice(2));
    return out.replace(/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/g, (m, name) => {
      if (name === 'HOME') return os.homedir();
      if (name === 'AEGIS_ROOT') return ROOT;
      return env[name] !== undefined ? env[name] : m;
    });
  }
  if (Array.isArray(value)) return value.map(v => expand(v, env));
  if (value && typeof value === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(value)) out[k] = expand(v, env);
    return out;
  }
  return value;
}

/** Deep merge; arrays and scalars from `over` replace, objects merge. */
function merge(base, over) {
  if (!over || typeof over !== 'object' || Array.isArray(over)) return over === undefined ? base : over;
  const out = { ...base };
  for (const [k, v] of Object.entries(over)) {
    if (k.startsWith('$comment')) continue;
    out[k] = (v && typeof v === 'object' && !Array.isArray(v) && base && typeof base[k] === 'object' && !Array.isArray(base[k]))
      ? merge(base[k], v) : v;
  }
  return out;
}

function readJson(p) {
  try { return JSON.parse(fs.readFileSync(p, 'utf8')); }
  catch (e) {
    if (e.code === 'ENOENT') return null;
    throw new Error(`${path.basename(p)} is not valid JSON — ${e.message}`);
  }
}

/**
 * Environment overrides. Kept to the handful that a colleague or a CI job
 * realistically needs to redirect without writing a file at all.
 */
function envOverrides(env) {
  const o = {};
  const surface = (key, varName) => {
    if (!env[varName]) return;
    o.surfaces = o.surfaces || {};
    o.surfaces[key] = { root: env[varName], gitRepo: env[varName] };
  };
  surface('admin', 'AEGIS_ADMIN_ROOT');
  surface('backend', 'AEGIS_BACKEND_ROOT');
  surface('teacher', 'AEGIS_TEACHER_ROOT');
  surface('parent', 'AEGIS_PARENT_ROOT');
  if (env.AEGIS_FIREBASE_SA) o.firebase = { ...(o.firebase || {}), serviceAccount: env.AEGIS_FIREBASE_SA };
  if (env.AEGIS_FIREBASE_PROJECT) o.firebase = { ...(o.firebase || {}), project: env.AEGIS_FIREBASE_PROJECT };
  if (env.AEGIS_RULES_FILE) o.firestoreRules = env.AEGIS_RULES_FILE;
  return o;
}

function load(env = process.env) {
  const base = readJson(CONFIG_PATH);
  if (!base) throw new Error(`no config at ${CONFIG_PATH} — copy aegis.config.example.json or run \`node cli.js setup\``);
  const local = readJson(LOCAL_CONFIG_PATH);

  let cfg = merge(base, local || {});
  cfg = merge(cfg, envOverrides(env));
  cfg = expand(cfg, env);

  // `functions` lives inside the admin repo, so a colleague who overrides only
  // the admin root would otherwise be left with a dangling functions path.
  if (cfg.surfaces && cfg.surfaces.admin && cfg.surfaces.functions) {
    const adminRoot = cfg.surfaces.admin.root;
    if (!local?.surfaces?.functions && !env.AEGIS_FUNCTIONS_ROOT) {
      cfg.surfaces.functions.root = path.join(adminRoot, 'functions');
      cfg.surfaces.functions.gitRepo = cfg.surfaces.admin.gitRepo || adminRoot;
    }
  }

  cfg._root = ROOT;
  cfg._configPath = CONFIG_PATH;
  cfg._localConfigPath = fs.existsSync(LOCAL_CONFIG_PATH) ? LOCAL_CONFIG_PATH : null;
  cfg.outputDir = cfg.outputDir || path.join(ROOT, '.reports');
  return cfg;
}

/** Return the surface key whose root contains an absolute file path, else null. */
function surfaceForPath(cfg, absPath) {
  let best = null, bestLen = -1;
  for (const [key, s] of Object.entries(cfg.surfaces)) {
    if (!s.root) continue;
    const root = s.root.replace(/\/$/, '');
    if (absPath === root || absPath.startsWith(root + path.sep)) {
      if (root.length > bestLen) { best = key; bestLen = root.length; }
    }
  }
  return best;
}

module.exports = { load, surfaceForPath, expand, merge, ROOT, CONFIG_PATH, LOCAL_CONFIG_PATH };
