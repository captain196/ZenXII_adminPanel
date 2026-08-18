'use strict';
/**
 * Read the ruleset that is ACTUALLY SERVING production.
 *
 * This is the whole reason the sentinel can be trusted. Every other tool in
 * this repo reasons about files on disk; a teammate deploying from their own
 * machine never touches your disk, so disk can be confidently, silently wrong.
 * The Firebase Rules API is the only source of truth about what is enforcing
 * right now.
 *
 * Zero dependencies on purpose (Aegis rule): the service-account JWT is signed
 * with node's crypto and exchanged for an access token by hand. ~40 lines
 * instead of a dependency tree in a security-sensitive path.
 *
 * The service-account JSON is read from disk and never copied, logged, or
 * cached. Only the resulting ruleset text is persisted.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const https = require('https');

const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const API = 'https://firebaserules.googleapis.com/v1';
const SCOPE = 'https://www.googleapis.com/auth/firebase.readonly';
// Indexes live behind a DIFFERENT Google API (Firestore Admin) than rules
// (Firebase Rules), and it will not accept a firebase.readonly token. Same
// service-account key, separate scope, separate cached token.
const DATASTORE_SCOPE = 'https://www.googleapis.com/auth/datastore';

function b64url(x) {
  return Buffer.from(typeof x === 'string' ? x : JSON.stringify(x))
    .toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
}

function request(method, url, { headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const opts = { hostname: u.hostname, path: u.pathname + u.search, method, headers: { ...headers } };
    if (body) opts.headers['Content-Length'] = Buffer.byteLength(body);
    const req = https.request(opts, (res) => {
      let data = '';
      res.setEncoding('utf8');
      res.on('data', (c) => { data += c; });
      res.on('end', () => resolve({ status: res.statusCode, body: data }));
    });
    req.on('error', reject);
    req.setTimeout(20000, () => req.destroy(new Error('firebaserules API timeout after 20s')));
    if (body) req.write(body);
    req.end();
  });
}

/** Locate service-account credentials. Explicit config wins over ambient env. */
function resolveServiceAccount(cfg) {
  const candidates = [
    process.env.AEGIS_FIREBASE_SA,
    cfg && cfg.firebase && cfg.firebase.serviceAccount,
    process.env.GOOGLE_APPLICATION_CREDENTIALS,
  ].filter(Boolean);
  for (const p of candidates) {
    const abs = path.resolve(p);
    if (fs.existsSync(abs)) return abs;
  }
  return null;
}

/**
 * Cache dir for tokens and ruleset snapshots.
 *
 * Caching is not an optimisation here, it is a correctness requirement: the
 * PreToolUse hook runs this on EVERY edit to firestore.rules, and an uncached
 * run costs a JWT exchange plus two API round-trips. Repeated cold calls ran
 * past the hook's timeout and the live section silently vanished — exactly when
 * it was most needed.
 */
function cacheDir() {
  const dir = path.join(path.resolve(__dirname, '..'), '.state');
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

/**
 * Access tokens live an hour; reuse until 5 minutes before expiry.
 *
 * Keyed by scope as well as key path. Rules and indexes are different Google
 * APIs with different scopes, and a firebase.readonly token is rejected by the
 * Firestore Admin API — sharing one cache slot would have each call evict and
 * invalidate the other's token on every alternation.
 */
function tokenFile(scope) {
  return path.join(cacheDir(), `token-${scope.split('/').pop().replace(/[^\w.]/g, '_')}.json`);
}
function cachedToken(saPath, scope) {
  try {
    const c = JSON.parse(fs.readFileSync(tokenFile(scope), 'utf8'));
    if (c.sa === saPath && c.scope === scope && c.expiresAt - Date.now() > 300000) return c.token;
  } catch (e) { /* no usable cache */ }
  return null;
}
function storeToken(saPath, scope, token, ttlSec) {
  try {
    const p = tokenFile(scope);
    // 0600: this is a bearer credential, even if a read-only one.
    fs.writeFileSync(p, JSON.stringify({ sa: saPath, scope, token, expiresAt: Date.now() + ttlSec * 1000 }), { mode: 0o600 });
    fs.chmodSync(p, 0o600);
  } catch (e) { /* best effort */ }
}

async function accessToken(saPath, scope = SCOPE) {
  const hit = cachedToken(saPath, scope);
  if (hit) return hit;
  const sa = JSON.parse(fs.readFileSync(saPath, 'utf8'));
  if (!sa.client_email || !sa.private_key) throw new Error(`not a service-account key: ${saPath}`);
  const now = Math.floor(Date.now() / 1000);
  const unsigned = b64url({ alg: 'RS256', typ: 'JWT' }) + '.' + b64url({
    iss: sa.client_email, scope, aud: TOKEN_URL, iat: now, exp: now + 3600,
  });
  const sig = crypto.createSign('RSA-SHA256').update(unsigned).sign(sa.private_key)
    .toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');

  const res = await request('POST', TOKEN_URL, {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=' + unsigned + '.' + sig,
  });
  if (res.status !== 200) throw new Error(`token exchange failed (${res.status}): ${res.body.slice(0, 200)}`);
  const parsed = JSON.parse(res.body);
  storeToken(saPath, scope, parsed.access_token, parsed.expires_in || 3600);
  return parsed.access_token;
}

/**
 * Fetch the live ruleset for a release.
 * @param release 'cloud.firestore' | 'firebase.storage/<bucket>'
 * @returns {{ok:true, source, rulesetName, rulesetId, createTime, updateTime, files}}
 *          or {ok:false, reason, detail} — never throws for the offline case,
 *          because working on a plane must still work.
 */
async function fetchLive(cfg, release = 'cloud.firestore', { maxAgeMs = 0 } = {}) {
  const project = (cfg.firebase && cfg.firebase.project) || 'graderadmin';

  // A cached ruleset is a STALE answer about production, so it is opt-in per
  // caller. Interactive/hook reads accept a few seconds of staleness; anything
  // gating a deploy passes maxAgeMs = 0 and always refetches.
  const cachePath = path.join(cacheDir(), `live-${release.replace(/[^\w.]/g, '_')}.json`);
  if (maxAgeMs > 0) {
    try {
      const c = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
      if (Date.now() - c.fetchedAt < maxAgeMs) return { ...c.result, fromCache: true };
    } catch (e) { /* no usable cache */ }
  }

  const saPath = resolveServiceAccount(cfg);
  if (!saPath) {
    return { ok: false, reason: 'no-credentials',
      detail: 'No service-account JSON found. Set firebase.serviceAccount in aegis.config.json or $AEGIS_FIREBASE_SA.' };
  }
  let token;
  try { token = await accessToken(saPath); }
  catch (e) { return { ok: false, reason: 'auth-failed', detail: e.message }; }

  const relRes = await request('GET', `${API}/projects/${project}/releases/${encodeURIComponent(release)}`,
    { headers: { Authorization: 'Bearer ' + token } });
  if (relRes.status !== 200) {
    return { ok: false, reason: 'release-fetch-failed',
      detail: `${relRes.status} ${relRes.body.slice(0, 200)}` };
  }
  const rel = JSON.parse(relRes.body);

  const rsRes = await request('GET', `${API}/${rel.rulesetName}`,
    { headers: { Authorization: 'Bearer ' + token } });
  if (rsRes.status !== 200) {
    return { ok: false, reason: 'ruleset-fetch-failed',
      detail: `${rsRes.status} ${rsRes.body.slice(0, 200)}` };
  }
  const rs = JSON.parse(rsRes.body);
  const files = (rs.source && rs.source.files) || [];

  const result = {
    ok: true,
    project,
    release,
    rulesetName: rel.rulesetName,
    rulesetId: String(rel.rulesetName).split('/').pop(),
    // createTime of the RULESET is when this exact content was uploaded — i.e.
    // the moment of the deploy that produced what is live now.
    createTime: rs.createTime,
    releaseUpdateTime: rel.updateTime,
    files,
    source: files.length ? files[0].content : '',
  };
  try { fs.writeFileSync(cachePath, JSON.stringify({ fetchedAt: Date.now(), result })); }
  catch (e) { /* cache is best-effort */ }
  return result;
}

/** Recent rulesets — the deploy history behind the current release. */
async function listRulesets(cfg, pageSize = 15) {
  const project = (cfg.firebase && cfg.firebase.project) || 'graderadmin';
  const saPath = resolveServiceAccount(cfg);
  if (!saPath) return { ok: false, reason: 'no-credentials' };
  let token;
  try { token = await accessToken(saPath); }
  catch (e) { return { ok: false, reason: 'auth-failed', detail: e.message }; }
  const res = await request('GET', `${API}/projects/${project}/rulesets?pageSize=${pageSize}`,
    { headers: { Authorization: 'Bearer ' + token } });
  if (res.status !== 200) return { ok: false, reason: 'list-failed', detail: `${res.status} ${res.body.slice(0, 200)}` };
  return { ok: true, rulesets: JSON.parse(res.body).rulesets || [] };
}

module.exports = { fetchLive, listRulesets, resolveServiceAccount, accessToken, request, cacheDir, SCOPE, DATASTORE_SCOPE };
