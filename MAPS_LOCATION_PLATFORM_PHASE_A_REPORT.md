# Centralized Maps & Location Platform — Phase A (Server + Admin)

**Date:** 2026-08-08
**Status:** **Phase A COMPLETE — stopped for approval.** F13.1 remains **unfrozen**, as instructed.

> Release constraints: no commits, no rebases, no release branch, no push, no deployment.
> **F13.1 GPS is untouched** — verified mechanically, not just by inspection (§6).

---

## 1. What Phase A delivers

| # | Deliverable | File |
|---|---|---|
| 1 | Provider registry + config engine | **NEW** `libraries/Maps_config_service.php` |
| 2 | Admin endpoints, graded `Configuration` RBAC | **NEW** `controllers/Maps_config.php` |
| 3 | **System Settings → Maps & Location** page | **NEW** `views/school_config/maps_location.php` |
| 4 | Rules for `mapsConfig` / `mapsSecrets` / `mapsConfigAudit` | `firebase-rules/firestore.rules` |
| 5 | Gates `CH-TRN-MAP-1`, `CH-TRN-MAP-2` | `controllers/Transport_verifier.php` |
| 6 | 44-assertion runtime probe | **NEW** `controllers/Maps_config_probe.php` |

Six providers registered: **MapLibre, MapTiler, Google Maps, Mapbox, HERE, TomTom.** The existing
MapLibre work is **provider #1 by registration** — not one line of it was rewritten.

---

## 2. The security decision: two documents, not one

**Firestore rules gate a document; they cannot hide a field.** A secret placed in a client-readable
config document *is* readable by every client, whatever the admin UI shows. So "clients receive only
the configuration they require" is only satisfiable by splitting storage:

| Document | Contents | Rule |
|---|---|---|
| `mapsConfig/{schoolId}` | mode, per-platform provider, style URLs, client-restricted keys, defaults, validation status | authenticated same-school **read**; write server-only |
| `mapsSecrets/{schoolId}` | server keys — geocoding, static maps, validation | **`allow read, write: if false`** |
| `mapsConfigAudit/{id}` | change history | staff read; write server-only |

Each credential is classified `CRED_PUBLIC` / `CRED_SECRET` in the registry, and `saveProvider()`
routes it to the right document. A key cannot land in the wrong one by hand.

Proven at runtime, not assumed — probe §B saved a Mapbox pair and then read both documents back:
the secret is **absent** from the client-readable doc and **present** in the server-only one, and
public credentials are not duplicated across.

"Public" never means unprotected. A browser/Android key is exposed by necessity — the SDK sends it
from the device — so the page says, at the point of entry, to restrict it at the provider by HTTP
referrer or package name + SHA-1.

---

## 3. A real defect the probe caught

My first `validateProvider` implementation trusted the HTTP status code. Testing it against Mapbox:

```
GET https://api.mapbox.com/tokens/v2?access_token=pk.PROBE_PUBLIC_TOKEN
→ HTTP 200   {"code":"TokenMalformed"}
```

**A malformed token returns 200.** So the connection test reported *passed*, the activation gate
accepted it, and a broken provider could have gone live — the exact failure the gate exists to
prevent, surfacing later on parents' phones rather than in the admin UI.

Fixed by giving each probe an `expect` contract rather than trusting the status:

| Provider | Expectation |
|---|---|
| MapLibre / MapTiler | body parses as a style document with a `version` |
| Mapbox | JSON `code == "TokenValid"` |
| Google | `Content-Type: image/*` — a bad key returns an **HTML error page with 200** |
| TomTom | `Content-Type: image/*` |
| HERE | JSON containing `items` |

This is why the probe drove the real service rather than mocking it.

**Disclosure:** validating that fix made one real outbound request to Mapbox's public token-check
endpoint. No account, no credentials of yours, no data created or modified.

---

## 4. Modes and switching

`resolveProvider($platform)` is the single question every surface asks; nothing else branches on
provider identity.

* **Global** — one provider; all four platforms resolve to it.
* **Platform** — per-surface providers. A platform left **blank falls back to the global provider**,
  so switching modes can never blank a working map.
* A platform **cannot** be pointed at a provider that is not activated.
* Saving credentials **always** resets validation and deactivates the provider — changed keys have
  not been proven, and leaving one live across an unverified edit is how a tenant's maps go dark on
  a typo.
* Activation is **refused** unless validation currently passes.

All exercised in probe §C/§D, including admin=Google + parent=MapLibre simultaneously.

---

## 5. Admin page

System Settings → Maps & Location: mode selector, per-platform assignment showing the **effective**
provider, per-provider credential forms with server-only keys masked and labelled, Save / Test
connection / Activate / Disable, last-validated timestamp, provider error text, map defaults, and
change history.

Two traps handled from the project's own notes: the page attaches the **CSRF token from the cookie**
on every `fetch` (the header's auto-attach only wraps jQuery, so without this each POST would die as
a blank 403), and every response is checked for **both** `!r.ok` **and** `{status:'error'}` before
rendering success.

RBAC is the **existing `Configuration` module**, graded — `view` to see, `edit` to enter and test
credentials, `manage` to activate or change mode. No new RBAC module: `rbac_modules.json` is shared
byte-for-byte with the `staffCapabilities` Cloud Function, so adding an entry is a cross-system
change. `CH-TRN-MAP-1` fails if one appears.

---

## 6. F13.1 is provably untouched

`CH-TRN-MAP-2` asserts the GPS contract is intact **and** that the two layers are decoupled in both
directions: the GPS service must not reference the maps platform, and the maps platform must not
reference `vehicleLivePositions`, `vehicleBreadcrumbs`, `tripLogs` or `Transport_gps_service`. The
GPS service answers *"where is the bus"*; the maps platform answers *"which renderer"*. Neither
should ever need to know the other exists.

| Suite | Result |
|---|---|
| Full verifier | **129 gates — 107 PASS / 15 INFO / 2 WARN / 5 FAIL** |
| All 7 F13 gates | **PASS** |
| Both new MAP gates | **PASS** |
| `transport_probe_f13` | **79 / 0** |
| `transport_probe_f13_e2e` | **29 / 0** |
| `maps_config_probe` | **44 / 0** |
| `transport_probe_f7` / `xmod1` / `perf3` | 7/0 · 3/0 · 4/0 |

The 5 FAIL are the identical pre-existing tenant-data artifacts. **No gate has gone PASS → FAIL at
any point across F13 or this work.** The probe snapshots and restores the tenant's real map config,
and reported it restored.

---

## 7. What Phase A does NOT yet do — stated plainly

**The apps do not read this configuration yet.** The Parent map still takes its style from
`BuildConfig.MAP_STYLE_URL` (the M4 build-time property). The platform is built, validated and
administrable, but the wire from it to the renderers is Phase B. I am flagging this rather than
letting the report imply the switch is live.

Phase B, when authorized:

| # | Work |
|---|---|
| B1 | Parent app reads `mapsConfig` (single-doc listener, same pattern as the live position) and feeds `LiveBusMap`; `BuildConfig.MAP_STYLE_URL` becomes the offline fallback |
| B2 | `MapRenderer` interface in the Parent app; today's MapLibre code becomes `MapLibreRenderer` — moved behind the interface, not rewritten |
| B3 | Admin panel renderer (Leaflet) reading the same config |
| B4 | Teacher app config plumbing (no map surface today) |
| B5 | Additional renderers — Google, Mapbox, HERE, TomTom — as each is actually needed |

One honest consequence of §8: the architecture supports six providers **today**, but only the
MapLibre renderer exists in a client. Switching the Parent app to Google would need B5's Google
renderer. Your own wording — *"once multiple providers are implemented"* — already anticipates this;
I want it explicit so nobody expects a config-only switch to Google before that renderer ships.

---

## 8. Deploy notes (nothing deployed)

* 3 new rule blocks — until deployed, `mapsSecrets` is **not** protected by rules. It is only ever
  written by the service account today, but the rules must ship before any client build reads config.
* No new indexes: config is a point read; audit uses the existing `schoolWhere` ordering.
* No new RBAC module, no Cloud Function change, no schema change to any existing collection.

---

## 9. Open items

| Item | Status |
|---|---|
| **Phase B** — clients consume the config | Not started; awaiting approval |
| **Commercial tile provider account** | Still needed for a rendering map. Now enterable through the admin page instead of a Gradle property. |
| Firestore TTL policy (F13.1) | Operator step, `DEPLOY_RUNBOOK.md` Phase 2b |
| Teacher / Parent UI validation with real accounts | Deferred to pre-release |
| **F13.1 freeze** | **Still open**, per your instruction |

---

**Stopped for approval.** Phase B will not begin until you authorize it.
