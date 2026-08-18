'use strict';
const path = require('path');
const { surfaceForPath } = require('./config');
const { modules } = require('../manifest');

/** Classify a file's "kind" from its path/extension. Order matters — most specific first. */
function kindOf(absPath) {
  const p = absPath.toLowerCase();
  const base = path.basename(p);
  if (base.endsWith('.rules') || base === 'firestore.rules' || base === 'storage.rules') return 'rules';
  if (base.includes('.indexes.json') || base === 'firestore.indexes.json') return 'index';
  if (base === 'androidmanifest.xml') return 'android-manifest';
  if (base.endsWith('.gradle') || base.endsWith('.gradle.kts')) return 'gradle';
  if (base === 'google-services.json' || base === 'package.json' || base === 'composer.json') return 'dependency';
  if (/(test|spec)\.(kt|java|js|php)$/.test(p) || p.includes('/androidtest/') || p.includes('/test/')) return 'test';
  if (base.endsWith('.md') || base.endsWith('.txt') || base.endsWith('.html')) return 'doc';
  if (base.endsWith('.xml')) return 'xml';
  if (base.endsWith('.css') || base.endsWith('.scss')) return 'style';
  if (/viewmodel\.kt$/.test(p)) return 'viewmodel';
  if (/repository\.kt$/.test(p)) return 'repository';
  if (/adapter\.kt$/.test(p)) return 'adapter';
  if (/(fragment|activity|screen)\.kt$/.test(p)) return 'ui';
  if (p.includes('/controllers/')) return 'controller';
  if (p.includes('/models/')) return 'model';
  if (p.includes('/libraries/') || p.includes('/core/')) return 'library';
  if (p.includes('/views/')) return 'view';
  if (base.endsWith('.kt') || base.endsWith('.java')) return 'kotlin';
  if (base.endsWith('.php')) return 'php';
  if (base.endsWith('.js') || base.endsWith('.ts')) return 'js';
  return 'other';
}

/**
 * Split a name into lowercase word tokens, breaking on camelCase, snake_case,
 * and any non-alphanumeric. "AttendanceRepository.kt" → [attendance, repository, kt].
 * "MY_Controller" → [my, controller]. "B2_analytics_rollup" → [b2, analytics, rollup].
 */
function tokenize(s) {
  return s
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')       // camelCase → camel Case
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')     // HTTPServer → HTTP Server
    .toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter(Boolean);
}

// A keyword is "compound" if it embeds a separator or case-hump (e.g. MY_Controller,
// onStoryCreated, set_password). Those are specific enough for a plain substring test.
function isCompound(kw) { return /[^A-Za-z0-9]/.test(kw) || /[a-z][A-Z]/.test(kw) || /[A-Z]{2,}/.test(kw); }

/**
 * Match a keyword against a token set. For single words: exact, or plural (+s), or
 * a length-guarded prefix (len ≥ 5, so 'regulariz'→'regularization' works but
 * 'exam'→'example' does NOT — the false positive the self-test caught).
 */
function keywordHits(kw, tokens, lowerName) {
  const k = kw.toLowerCase();
  if (isCompound(kw)) return lowerName.includes(k);
  if (tokens.has(k) || tokens.has(k + 's')) return true;
  if (k.length >= 5) { for (const t of tokens) if (t.startsWith(k)) return true; }
  return false;
}

/** Match a file to zero-or-more modules by manifest `match` keywords (on basename + parent dir). */
function modulesForPath(absPath) {
  const base = path.basename(absPath);
  const parent = path.basename(path.dirname(absPath));
  const tokens = new Set(tokenize(base + ' ' + parent));
  const lowerName = (parent + '/' + base).toLowerCase();
  const hits = [];
  for (const [name, def] of Object.entries(modules)) {
    if ((def.match || []).some(kw => keywordHits(kw, tokens, lowerName))) hits.push(name);
  }
  return hits;
}

/** Classify one changed file into { path, surface, kind, modules }. */
function classifyFile(cfg, absPath) {
  const kind = kindOf(absPath);
  // A rules/index file guards MANY modules' collections — it is not a single
  // module's code. It is handled via contracts (rules_deny_default / firestore_index)
  // and the risk scorer (+8 / +4), so don't seed a bogus single-module blast from it.
  const mods = (kind === 'rules') ? [] : modulesForPath(absPath);
  return {
    path: absPath,
    rel: path.relative(cfg._root, absPath),
    surface: surfaceForPath(cfg, absPath) || guessSurfaceByExt(absPath),
    kind,
    modules: mods
  };
}

function guessSurfaceByExt(absPath) {
  const b = absPath.toLowerCase();
  if (b.endsWith('.php')) return 'admin';
  if (b.endsWith('.kt') || b.endsWith('.java')) return 'app';
  if (b.endsWith('.rules')) return 'firebase';
  if (b.endsWith('.js') || b.endsWith('.ts')) return 'node';
  return 'unknown';
}

/** Classify a whole change set. */
function classify(cfg, files) {
  return files.map(f => classifyFile(cfg, f));
}

module.exports = { classify, classifyFile, kindOf, modulesForPath };
