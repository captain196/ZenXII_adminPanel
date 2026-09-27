/**
 * ZenXii Aegis — Telemetry Cloud Function (DEPLOY-PENDING, Firebase Functions v2)
 * ─────────────────────────────────────────────────────────────────────────────
 * Feeds the RUNTIME tiles of the Aegis health dashboard. Two functions:
 *
 *   aegisIngest  (HTTPS)     — every surface (Lightsail PHP, CFs, the apps' backend)
 *                              POSTs structured events or raw `[PREFIX] key=value`
 *                              log lines; we normalize → write to `aegis_events`.
 *   aegisRollup  (scheduled) — nightly: aggregate the day's events into
 *                              `aegis_metrics_daily/{YYYY-MM-DD}` for the dashboard.
 *
 * Why HTTP ingest (not log-tailing): your PHP runs on Lightsail, so its
 * `log_message()` lines never reach Google Cloud Logging. An ingest endpoint
 * lets ALL surfaces feed one pipeline with a single POST.
 *
 * The parse/rollup logic mirrors observability/normalizer.js (unit-tested in
 * aegis `npm test`) — kept inline so this folder is a self-contained deploy unit.
 *
 * Matches your existing v2 conventions (firebase-functions ^5, admin guarded).
 */
'use strict';
const admin = require('firebase-admin');
const { onRequest } = require('firebase-functions/v2/https');
const { onSchedule } = require('firebase-functions/v2/scheduler');
const logger = require('firebase-functions/logger');

if (!admin.apps.length) admin.initializeApp();
const db = admin.firestore();

// Shared-secret gate so the public HTTPS endpoint can't be spammed.
// Set with: firebase functions:config unavailable in v2 → use an env var / secret.
const INGEST_TOKEN = process.env.AEGIS_INGEST_TOKEN || '';

// ── parsing (mirror of observability/normalizer.js) ──────────────────────────
const LINE = /\[([A-Za-z0-9_.]+)\]\s+(.*)$/;
function parseLine(line, meta) {
  meta = meta || {};
  const m = LINE.exec(line);
  if (!m) return null;
  const prefix = m[1], rest = m[2], kv = {};
  const re = /(\w+)=("([^"]*)"|'([^']*)'|(\S+))/g;
  let x;
  while ((x = re.exec(rest)) !== null) kv[x[1]] = x[3] !== undefined ? x[3] : x[4] !== undefined ? x[4] : x[5];
  const parts = prefix.includes('.') ? prefix.split('.') : ['LOG', prefix.toLowerCase()];
  return {
    surface: meta.surface || 'unknown',
    namespace: parts[0],
    module: (parts[1] || '').toLowerCase(),
    event: kv.event || null,
    schoolId: kv.schoolId || kv.school_id || null,
    ms: kv.ms !== undefined ? Number(kv.ms) : null,
    ok: kv.ok !== undefined ? (kv.ok === 'true' || kv.ok === '1') : null,
    level: /error|fail|denied|exception/i.test(rest) ? 'error' : 'info',
    kv, raw: line
  };
}

// ── aegisIngest: accept events, write envelopes ──────────────────────────────
exports.aegisIngest = onRequest({ cors: false, maxInstances: 3 }, async (req, res) => {
  if (req.method !== 'POST') return res.status(405).send('POST only');
  if (INGEST_TOKEN && req.get('authorization') !== `Bearer ${INGEST_TOKEN}`) {
    return res.status(401).send('unauthorized');
  }
  try {
    const body = req.body || {};
    const surface = String(body.surface || 'unknown').slice(0, 32);
    // accept either {lines:["[AEGIS.x] ..."]} or {events:[{module,event,...}]}
    let envelopes = [];
    if (Array.isArray(body.lines)) {
      envelopes = body.lines.map(l => parseLine(String(l), { surface })).filter(Boolean);
    } else if (Array.isArray(body.events)) {
      envelopes = body.events.map(e => ({
        surface, namespace: 'EVENT',
        module: String(e.module || '').toLowerCase(),
        event: e.event || null, schoolId: e.schoolId || null,
        ms: e.ms != null ? Number(e.ms) : null,
        ok: e.ok != null ? !!e.ok : null,
        level: e.level || (e.ok === false ? 'error' : 'info'), kv: e
      })).filter(e => e.module);
    }
    if (!envelopes.length) return res.status(400).json({ ok: false, error: 'no valid events' });

    const now = admin.firestore.FieldValue.serverTimestamp();
    const batch = db.batch();
    envelopes.slice(0, 400).forEach(e => {
      batch.set(db.collection('aegis_events').doc(), { ...e, ts: now });
    });
    await batch.commit();
    return res.status(200).json({ ok: true, ingested: Math.min(envelopes.length, 400) });
  } catch (err) {
    logger.error('[AEGIS.telemetry] event=ingest_fail ok=false', err);
    return res.status(500).json({ ok: false });
  }
});

// ── aegisRollup: nightly per-module daily metrics ────────────────────────────
exports.aegisRollup = onSchedule({ schedule: '15 0 * * *', timeZone: 'Asia/Kolkata', maxInstances: 1 }, async () => {
  const end = new Date();
  const start = new Date(end.getTime() - 24 * 3600 * 1000);
  const dateKey = start.toISOString().slice(0, 10);

  const snap = await db.collection('aegis_events')
    .where('ts', '>=', admin.firestore.Timestamp.fromDate(start))
    .where('ts', '<', admin.firestore.Timestamp.fromDate(end))
    .get();

  const agg = {};
  snap.forEach(doc => {
    const e = doc.data();
    const k = e.module || 'unknown';
    const r = agg[k] || (agg[k] = { module: k, count: 0, errors: 0, msSum: 0, msN: 0 });
    r.count++;
    if (e.ok === false || e.level === 'error') r.errors++;
    if (typeof e.ms === 'number' && !Number.isNaN(e.ms)) { r.msSum += e.ms; r.msN++; }
  });

  const modules = Object.values(agg).map(r => ({
    module: r.module, count: r.count,
    errorRate: r.count ? +(r.errors / r.count).toFixed(4) : 0,
    avgMs: r.msN ? Math.round(r.msSum / r.msN) : null,
    health: r.count ? Math.round(100 * (1 - r.errors / r.count)) : 100
  }));

  await db.collection('aegis_metrics_daily').doc(dateKey).set({
    date: dateKey, generatedAt: admin.firestore.FieldValue.serverTimestamp(),
    totalEvents: snap.size, modules
  });
  logger.info(`[AEGIS.telemetry] event=rollup date=${dateKey} modules=${modules.length} events=${snap.size} ok=true`);
});
