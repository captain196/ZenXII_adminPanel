# Transport F13.1 + F13.2 — Manual UAT Guide

**Status: MANUAL UAT = NOT PERFORMED.** This document is the procedure, not a result.
Nothing in it may be marked PASS until a person actually performs the step and observes the outcome.

---

## 0. What you need, all at once

| # | Requirement | Why |
|---|---|---|
| 1 | Android phone connected by USB, `adb devices` listing it | driver + parent apps |
| 2 | **Real driver account** with a Ready trip today | only a driver can start a trip |
| 3 | **Real parent account** whose child is on that trip's roster | the roster gates the parent's map read |
| 4 | Admin login with **`Transport` view** and **`Configuration` manage** | fleet map; provider settings |
| 5 | A commercial OSM tile key (MapTiler or TomTom) | without it the map says "not configured" |
| 6 | Local server reachable from the phone | the code is **not deployed** |

No demo accounts are to be created — use real ones.

### Why local, not production

Production does **not** have this code: `maps_config` → **404**, `transport_driver_api` → **403**. So
UAT runs against this laptop:

```bash
adb reverse tcp:8080 tcp:80          # phone localhost:8080 → laptop Apache :80
adb shell 'curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/ZenX/school/admin_login'
# expect 200
```

The **Teacher** debug build already points at `http://localhost:8080/ZenX/school/`.
The **Parent** debug build still needs the same treatment — ask me and I will add it (one debug-only
`buildConfigField`; release untouched).

> **Installing debug over release wipes that app's data** — the two are signed differently, so
> `adb install -r` fails and an uninstall is required. Confirm before I do this to the Parent app.

---

## 1. Admin — Maps & Location (prerequisite)

| # | Step | Expected | Result |
|---|---|---|---|
| 1.1 | System Settings → Maps & Location | page loads; 6 providers listed | ☐ |
| 1.2 | Read the availability chips | TomTom shows `✓ admin  ✗ parent` | ☐ |
| 1.3 | Enter a MapTiler key + style URL, Save | saved; status "Untested"; provider disabled | ☐ |
| 1.4 | Activate before testing | **refused**, message says run a test first | ☐ |
| 1.5 | Test connection with a **wrong** key | fails **visibly** with the provider's reason | ☐ |
| 1.6 | Test connection with the real key | passes; timestamp recorded | ☐ |
| 1.7 | Activate | succeeds | ☐ |
| 1.8 | Global mode → MapTiler → Save | accepted | ☐ |
| 1.9 | Try Global mode → **TomTom** | **refused** — no parent renderer | ☐ |
| 1.10 | Platform mode → admin **TomTom**, parent MapTiler | accepted | ☐ |
| 1.11 | Platform mode → parent **TomTom** | **refused**, names the missing renderer | ☐ |
| 1.12 | Change history | rows present; **no key values shown** | ☐ |
| 1.13 | Browser devtools → Network | no request carries a server-only secret | ☐ |

## 2. Admin — Live Fleet Map

| # | Step | Expected | Result |
|---|---|---|---|
| 2.1 | Open Live Fleet with **no trip running** | "No trips are running right now." — **not an error** | ☐ |
| 2.2 | Counters | all zero | ☐ |
| 2.3 | After the driver starts a trip (§3), reload | bus appears within ~15 s | ☐ |
| 2.4 | Before the first GPS fix | state **Not tracking**; no marker | ☐ |
| 2.5 | After the first fix | marker appears at the real position; **Live** | ☐ |
| 2.6 | Compare marker to the phone's actual location | matches | ☐ |
| 2.7 | Driver walks/drives 100 m+ | marker moves; **never ahead of** the phone | ☐ |
| 2.8 | Click the sidebar row | map focuses that bus; row highlights | ☐ |
| 2.9 | Detail panel | vehicle, driver, route, next stop, stops, progress, distance, schedule, on-board | ☐ |
| 2.10 | Driver taps a checkpoint Reached | stops-done increments; next stop advances | ☐ |
| 2.11 | Turn the phone's GPS off, wait >2 min | state → **Stale**, "Last seen N min ago" | ☐ |
| 2.12 | Wait >10 min | state → **No signal** | ☐ |
| 2.13 | Turn GPS back on | returns to **Live** | ☐ |
| 2.14 | Background the admin tab 2 min, return | polling paused then resumes; poll count confirms | ☐ |
| 2.15 | Driver completes the trip | marker and row **disappear** | ☐ |
| 2.16 | Repeat and **cancel** instead | marker and row disappear | ☐ |
| 2.17 | Devtools Network | one `live` call ≈ every 15 s — **no flood** | ☐ |
| 2.18 | Status line | "cached Ns" appears on some polls | ☐ |
| 2.19 | Two buses running | both move independently | ☐ |
| 2.20 | Stop Apache mid-session | honest error, **no fake data** | ☐ |
| 2.21 | Log in as staff **without** Transport view | cannot open the map | ☐ |
| 2.22 | Clear the provider config, reload | "No map provider configured"; **list still works** | ☐ |

## 3. Teacher / Driver app — real hardware

| # | Step | Expected | Result |
|---|---|---|---|
| 3.1 | Log in as the **real driver** | assigned trip appears | ☐ |
| 3.2 | Turn phone Location Services **OFF**, tap Start Trip | trip does **not** start; message is exactly **"Please enable Location Services to start the trip."** | ☐ |
| 3.3 | Turn Location Services **ON**, tap Start Trip again | trip starts — no extra step | ☐ |
| 3.4 | Look for any coordinate entry field | **none exists anywhere** | ☐ |
| 3.5 | Notification shade | persistent, non-dismissible tracking notification | ☐ |
| 3.6 | Trip progress line | "Stop N of M · next: X" | ☐ |
| 3.7 | Walk 200 m | positions written (visible on the admin map) | ☐ |
| 3.8 | Tap a checkpoint Reached | progress advances on **all three** surfaces | ☐ |
| 3.9 | Complete Trip | notification clears; tracking stops | ☐ |
| 3.10 | Watch the admin map for 2 min after completion | **no further movement** | ☐ |
| 3.11 | Start another trip, then **Cancel** | tracking stops the same way | ☐ |
| 3.12 | Start a trip, then force-stop the app | admin shows Stale → No signal; sweeper closes the session | ☐ |
| 3.13 | Airplane mode 1 min mid-trip, then restore | recovers; **no backwards jump** | ☐ |
| 3.14 | Throughout | no crash, no ANR | ☐ |

## 4. Parent app — real account

| # | Step | Expected | Result |
|---|---|---|---|
| 4.1 | Log in as the **real parent** | My Bus opens | ☐ |
| 4.2 | With no trip running | "No trip running right now." | ☐ |
| 4.3 | Driver starts the trip | live tracking appears | ☐ |
| 4.4 | Map | real bus position on real tiles | ☐ |
| 4.5 | Driver moves | marker glides; **never beyond** the reported position | ☐ |
| 4.6 | Stop progress | "N stops away from your stop" and it decreases correctly | ☐ |
| 4.7 | Driver passes the child's stop | "Your stop has been served" | ☐ |
| 4.8 | Speed | plausible | ☐ |
| 4.9 | Driver GPS off >2 min | "Last seen N min ago. Signal may be weak…" | ☐ |
| 4.10 | Driver completes | tracking stops; no stale bus left on screen | ☐ |
| 4.11 | Leave and re-enter My Bus repeatedly | no crash, no duplicate listeners | ☐ |
| 4.12 | Background 5 min, return | recovers cleanly | ☐ |
| 4.13 | Rotate the device | map survives | ☐ |
| 4.14 | **Second parent account, different bus** | sees only **their own** child's bus | ☐ |
| 4.15 | Switch admin to platform mode, parent → MapTiler | parent map follows **with no reinstall** | ☐ |
| 4.16 | Set parent → **TomTom** in admin | **refused** at the admin (no parent renderer) | ☐ |

## 5. The critical end-to-end

Run as one continuous sequence — this is the validation that matters most.

```
Driver: log in → Location Services ON → START TRIP
      → walk/drive a few hundred metres
Admin:  Live Fleet → bus appears → marker moves as the driver moves
Parent: My Bus → live map → same movement → stop count decreasing
Driver: COMPLETE TRIP
Admin:  bus disappears from the fleet
Parent: tracking stops
```

| # | Checkpoint | Result |
|---|---|---|
| 5.1 | Positions come only from GPS hardware — nothing typed | ☐ |
| 5.2 | Admin and parent show the **same** position | ☐ |
| 5.3 | Both update within ~15–30 s of movement | ☐ |
| 5.4 | Neither ever shows the bus ahead of where it is | ☐ |
| 5.5 | Completion stops tracking on **both** | ☐ |
| 5.6 | No crash on any surface | ☐ |

**Do not mark any of §5 PASS until it has actually been performed.**

## 6. Security spot-checks

| # | Step | Expected | Result |
|---|---|---|---|
| 6.1 | Parent devtools/logs | no `mapsSecrets` value ever present | ☐ |
| 6.2 | Parent B tries parent A's vehicle | denied | ☐ |
| 6.3 | Staff without Transport view opens the map URL directly | denied | ☐ |
| 6.4 | Admin browser console | **no CSP violations** for tiles | ☐ |
| 6.5 | Browser console | an unrelated external image is still **blocked** | ☐ |

## 7. Known-limitation confirmations (expected, not defects)

| # | Observation | Expected |
|---|---|---|
| 7.1 | Position up to ~27 s old (15 s poll + 12 s cache) | by design; matched to the GPS cadence |
| 7.2 | Distance to next stop labelled "straight line" | not road distance |
| 7.3 | **No ETA anywhere** | deferred to F13.3 — deliberate |
| 7.4 | Google / Mapbox / HERE selected → "not available in this app version" | placeholders |
| 7.5 | Route without stop times → schedule "unknown" | never guessed |

---

## 8. Recording results

For each ☐: **PASS**, **FAIL**, or **BLOCKED** — with what was observed. Never convert BLOCKED into
PASS. For any FAIL, capture the surface, exact steps, what appeared versus what was expected, and a
screenshot; I will classify it and propose a fix before changing code.

**Until this document is filled in by a person, the correct status is: MANUAL UAT = NOT PERFORMED.**
