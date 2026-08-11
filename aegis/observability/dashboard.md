# Aegis Health Dashboard — Build Spec (Layer §08)

Build this as a **superadmin-only page inside the admin panel** (reuse the auth,
tenancy, and Lightsail hosting you already run — no second stack). It reads from
two Firestore collections written server-side (Admin SDK bypasses rules, so no
rule changes — same pattern as PHASE_A_OBSERVABILITY).

## Collections (server-written)

| Collection | Written by | Shape |
|---|---|---|
| `aegis_events` | `observability/normalizer.js` in a CF that tails logs | one envelope per structured log line |
| `aegis_metrics_daily` | nightly rollup CF calling `normalizer.rollup()` | `{ module, count, errorRate, avgMs, health, date }` |
| `aegis_synthetic` | 5-min canary probe CF | `{ probe, ms, ok, ts }` per golden-path hit |
| `aegis_impact/<sha>` | CI `aegis impact` step | the impact report JSON per merge |

## Page layout (see blueprint §08 wireframe)

1. **Header band** — overall system health % (min of surface healths), deploy-ready flag.
2. **Surface grid** — one tile per surface (admin/backend/firebase/teacher/parent),
   each: health dot (good ≥95 / warn 85–94 / crit <85), sparkline of 7-day health, trend arrow.
3. **Signal tiles** — notifications delivery %, auth PERMISSION_DENIED rate, security open count.
4. **High-risk modules** — from `aegis_impact` recent + manifest P0 modules (Fees, Attendance).
5. **Release stability** — last 10 deploys pass/fail bar, MTTR, DORA change-failure-rate.
6. **Trend strip** — crash-free sessions (Crashlytics), p95 API, tech-debt count.

## Semantic color (separate from brand accent)

- good `#1C7F53` · warn `#9A6410` · crit `#B63A31` — encode state in **form** (dot + sparkline + arrow), never color alone.

## Data-in path (minimal, no new infra)

```
PHP/Node/CF logs ([PREFIX] key=value)
        │  (existing convention)
        ▼
normalizer CF  ──writes──▶  aegis_events  ──nightly rollup──▶  aegis_metrics_daily
                                                                      │
                                          admin-panel page reads ─────┘  (Admin SDK)
```

Start with tiles 1–2 fed by `aegis_metrics_daily` only. Add synthetic + release
stability once the canary probe and CI gate are live (roadmap P4).
