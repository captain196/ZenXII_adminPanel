# Aegis Telemetry CF — Deploy Runbook (DEPLOY-PENDING)

Status: **built, not deployed.** This lights up the RUNTIME tiles of the Aegis
dashboard (crash-free / latency / delivery per module). Deploy only when you
decide — nothing here has touched your live Firebase.

## What it adds

| Function | Type | Does |
|---|---|---|
| `aegisIngest` | HTTPS | surfaces POST structured events / log lines → writes `aegis_events` |
| `aegisRollup` | scheduled (00:15 IST) | aggregates the day → `aegis_metrics_daily/{date}` |

New Firestore collections: `aegis_events`, `aegis_metrics_daily`. **Server-written
only** (Admin SDK bypasses rules) — no `firestore.rules` change needed, same
pattern as PHASE_A_OBSERVABILITY.

## Deploy (when ready)

Option A — as its own codebase (recommended, isolates from your main functions):
```bash
cd observability/telemetry-cf
npm install
# set the ingest secret (any random string; emitters must send it)
firebase functions:secrets:set AEGIS_INGEST_TOKEN
firebase deploy --only functions:aegisIngest,functions:aegisRollup
```

Option B — drop `aegisIngest`/`aegisRollup` exports into your existing
`functions/index.js` and deploy with your normal functions deploy.

> ⚠️ Watch the known gRPC OOM on functions deploy (use the Sury/node build path
> from your Lightsail gotchas). `aegisIngest` has `maxInstances: 3`, `aegisRollup`
> `maxInstances: 1` to cap cost.

## Wire the emitters (after deploy)

Point `aegis.config.json` runtime tiles at the live data by reading
`aegis_metrics_daily` in `lib/health.js` (a TODO seam is marked there). Then have
each surface POST events. Example (PHP on Lightsail, fire-and-forget):

```php
// after a timed operation
$payload = json_encode(['surface'=>'admin','lines'=>[
  "[AEGIS.attendance] event=marked schoolId={$schoolId} ms={$ms} ok=true"
]]);
$ch = curl_init('https://<region>-<project>.cloudfunctions.net/aegisIngest');
curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>$payload,
  CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.AEGIS_INGEST_TOKEN],
  CURLOPT_TIMEOUT=>2, CURLOPT_RETURNTRANSFER=>1]);
curl_exec($ch); curl_close($ch);   // never block the user request on telemetry
```

Cloud Functions / Node backend: POST the same JSON with `firebase-admin` context
or plain `fetch`. Apps: emit via Crashlytics custom keys + a thin batch POST, or
skip (Crashlytics already covers crash-free-session on the app side).

## Verify after deploy

```bash
curl -X POST https://<region>-<project>.cloudfunctions.net/aegisIngest \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"surface":"admin","lines":["[AEGIS.attendance] event=marked schoolId=SCH_TEST ms=40 ok=true"]}'
# → {"ok":true,"ingested":1}   then check Firestore aegis_events
```

## Rollback

Functions: `firebase deploy` the previous version, or delete the two functions
(`firebase functions:delete aegisIngest aegisRollup`). Collections are additive
and server-only — safe to leave or drop.
