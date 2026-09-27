'use strict';
/**
 * Read the composite indexes that ACTUALLY EXIST in production.
 *
 * Same argument as rules/live.js, different API. Indexes are not served by the
 * Firebase Rules API — they live behind the **Firestore Admin API**
 * (`firestore.googleapis.com`), under a different OAuth scope
 * (`auth/datastore`, not `auth/firebase.readonly`). That is why the sentinel
 * had no index leg for so long: it is genuinely a second integration, not a
 * parameter change.
 *
 * Auth (JWT signing, token exchange, 0600 caching) is reused from rules/live.js
 * so there is exactly one credential path in this codebase. The token cache is
 * keyed by scope, so the two APIs do not evict each other.
 *
 * The API is also quietly hostile in two ways, both learned the hard way:
 *
 *  1. `pageSize` is rejected outright — "Invalid page size. Only 0 is
 *     supported." Paging is via `pageToken` alone.
 *  2. Every returned index carries a trailing `__name__` field that
 *     `firestore.indexes.json` omits, because Firebase appends it implicitly.
 *     Compare without normalising and all 284 live indexes look different from
 *     all 183 declared ones.
 */
const fs = require('fs');
const path = require('path');

const { resolveServiceAccount, accessToken, request, cacheDir, DATASTORE_SCOPE } = require('../rules/live');

const API = 'https://firestore.googleapis.com/v1';

/**
 * Canonical identity for an index, shared by the live and the file side.
 *
 * An index IS its (collection group, query scope, ordered field list) — the
 * resource id is server-assigned and differs between projects, so it cannot be
 * the key. Field ORDER is significant: [school, date] and [date, school] are
 * different indexes serving different queries.
 *
 * The trailing `__name__` is stripped. Firestore appends it to every composite
 * index automatically, matching the direction of the last explicit field, and
 * the CLI's JSON format leaves it out. A `__name__` whose direction does NOT
 * match the previous field is meaningful and is kept.
 */
function indexKey(collectionGroup, queryScope, fields) {
  const f = (fields || []).slice();
  while (f.length > 1) {
    const last = f[f.length - 1];
    if (last.fieldPath !== '__name__') break;
    const prev = f[f.length - 2];
    // Implicit only when it echoes the previous field's direction.
    if (prev && prev.order && last.order && prev.order !== last.order) break;
    f.pop();
  }
  const spec = f.map(x => `${x.fieldPath}:${x.order || x.arrayConfig || (x.vectorConfig ? 'VECTOR' : '?')}`).join(',');
  return `${collectionGroup}|${queryScope || 'COLLECTION'}|${spec}`;
}

/** Human-readable rendering of a key, for report lines. */
function describeKey(key) {
  const [cg, scope, spec] = key.split('|');
  const fields = spec ? spec.split(',').map(s => {
    const [p, d] = s.split(':');
    return `${p} ${d === 'ASCENDING' ? '↑' : d === 'DESCENDING' ? '↓' : d}`;
  }).join(', ') : '(no fields)';
  return { collectionGroup: cg, queryScope: scope, fields };
}

/** Collection group out of `projects/P/databases/D/collectionGroups/CG/indexes/ID`. */
function collectionGroupOf(name) {
  const m = /\/collectionGroups\/([^/]+)\/indexes\//.exec(name || '');
  return m ? m[1] : '(unknown)';
}

/**
 * Fetch every composite index in the project.
 *
 * @returns {{ok:true, indexes:Array, byKey:Map, count:number, fetchedAt:number}}
 *          or {ok:false, reason, detail} — never throws, so an offline run
 *          degrades to "unknown" rather than taking the CLI down.
 */
async function fetchLiveIndexes(cfg, { maxAgeMs = 0, database = '(default)' } = {}) {
  const project = (cfg.firebase && cfg.firebase.project) || 'graderadmin';
  const cachePath = path.join(cacheDir(), `live-indexes-${project}.json`);

  if (maxAgeMs > 0) {
    try {
      const c = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
      if (Date.now() - c.fetchedAt < maxAgeMs) {
        return { ...c.result, byKey: rebuildMap(c.result.indexes), fromCache: true };
      }
    } catch (e) { /* no usable cache */ }
  }

  const saPath = resolveServiceAccount(cfg);
  if (!saPath) {
    return { ok: false, reason: 'no-credentials',
      detail: 'No service-account JSON found. Set firebase.serviceAccount in aegis.config.json or $AEGIS_FIREBASE_SA.' };
  }
  let token;
  try { token = await accessToken(saPath, DATASTORE_SCOPE); }
  catch (e) { return { ok: false, reason: 'auth-failed', detail: e.message }; }

  const base = `${API}/projects/${project}/databases/${encodeURIComponent(database)}/collectionGroups/-/indexes`;
  const all = [];
  let pageToken = null;
  do {
    // No pageSize — the API rejects any value but 0.
    const url = pageToken ? `${base}?pageToken=${encodeURIComponent(pageToken)}` : base;
    let res;
    try { res = await request('GET', url, { headers: { Authorization: 'Bearer ' + token } }); }
    catch (e) { return { ok: false, reason: 'network', detail: e.message }; }
    if (res.status === 403) {
      return { ok: false, reason: 'forbidden',
        detail: 'the service account cannot read indexes. It needs roles/datastore.viewer (or firebase.viewer) on this project.' };
    }
    if (res.status !== 200) {
      return { ok: false, reason: 'index-fetch-failed', detail: `${res.status} ${res.body.slice(0, 200)}` };
    }
    let page;
    try { page = JSON.parse(res.body); }
    catch (e) { return { ok: false, reason: 'bad-response', detail: e.message }; }
    for (const i of page.indexes || []) {
      const cg = collectionGroupOf(i.name);
      all.push({
        key: indexKey(cg, i.queryScope, i.fields),
        collectionGroup: cg,
        queryScope: i.queryScope || 'COLLECTION',
        fields: i.fields || [],
        state: i.state || 'UNKNOWN',
        name: i.name,
        id: String(i.name || '').split('/').pop(),
      });
    }
    pageToken = page.nextPageToken || null;
  } while (pageToken);

  const result = { ok: true, project, database, indexes: all, count: all.length, fetchedAt: Date.now() };
  try { fs.writeFileSync(cachePath, JSON.stringify({ fetchedAt: Date.now(), result })); }
  catch (e) { /* cache is best-effort */ }
  return { ...result, byKey: rebuildMap(all) };
}

function rebuildMap(list) {
  const m = new Map();
  // Duplicate keys are possible in principle (same shape, two resources);
  // keep the first and let the caller surface the count if it matters.
  for (const i of list || []) if (!m.has(i.key)) m.set(i.key, i);
  return m;
}

/**
 * Parse a firestore.indexes.json into the same shape.
 * Returns {ok:false} rather than throwing so a malformed file is reportable.
 */
function parseIndexFile(text, label = 'indexes file') {
  let j;
  try { j = typeof text === 'string' ? JSON.parse(text) : text; }
  catch (e) { return { ok: false, reason: 'invalid-json', detail: `${label}: ${e.message}` }; }
  if (!j || typeof j !== 'object') return { ok: false, reason: 'invalid-shape', detail: `${label}: not an object` };
  const out = [];
  for (const i of j.indexes || []) {
    out.push({
      key: indexKey(i.collectionGroup, i.queryScope, i.fields),
      collectionGroup: i.collectionGroup,
      queryScope: i.queryScope || 'COLLECTION',
      fields: i.fields || [],
      comment: i._comment || i.$comment || null,
    });
  }
  return { ok: true, indexes: out, byKey: rebuildMap(out), fieldOverrides: j.fieldOverrides || [], count: out.length };
}

/** Render live indexes back into firestore.indexes.json shape, for recovery. */
function toIndexFile(liveIndexes, fieldOverrides = []) {
  const indexes = liveIndexes.map(i => {
    const fields = i.fields.slice();
    while (fields.length > 1) {
      const last = fields[fields.length - 1];
      if (last.fieldPath !== '__name__') break;
      const prev = fields[fields.length - 2];
      if (prev && prev.order && last.order && prev.order !== last.order) break;
      fields.pop();
    }
    return {
      collectionGroup: i.collectionGroup,
      queryScope: i.queryScope,
      fields: fields.map(f => {
        const o = { fieldPath: f.fieldPath };
        if (f.order) o.order = f.order;
        if (f.arrayConfig) o.arrayConfig = f.arrayConfig;
        if (f.vectorConfig) o.vectorConfig = f.vectorConfig;
        return o;
      }),
    };
  }).sort((a, b) => a.collectionGroup.localeCompare(b.collectionGroup) ||
                    JSON.stringify(a.fields).localeCompare(JSON.stringify(b.fields)));
  return { indexes, fieldOverrides };
}

module.exports = { fetchLiveIndexes, parseIndexFile, indexKey, describeKey, toIndexFile, collectionGroupOf };
