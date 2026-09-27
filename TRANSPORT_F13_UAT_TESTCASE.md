# F13.1 + F13.2 — Executable Test Case (IIT Kanpur)

**Status: NOT PERFORMED.** This is the procedure. Nothing here is a result until you perform the step
and write down what you saw. Never turn BLOCKED into PASS.

Tenant `SCH_D94FE8F7AD` · session `2026-27` · device `96374480720007C`

---

## Already verified — do NOT redo

| # | Item | Evidence |
|---|---|---|
| ✅ | Both apps installed as debug builds, pointing at this laptop | `BASE_URL = http://localhost:8080/ZenX/school/` |
| ✅ | Phone reaches the laptop | `adb reverse tcp:8080 tcp:80` → `admin_login = 200` |
| ✅ | Vipul TIwari `STA0001` is a Driver, HR Active | `role=Driver`, `staff_roles=ROLE_DRIVER` |
| ✅ | `flags.can_drive_transport = TRUE` | driver readiness reports no blocking issues |
| ✅ | Driver linked to a bus | `GJ01AB1234` (id `VH0100`) → `staff_id=STA0001` |
| ✅ | That bus is on the route the student rides | `RT0045` South Circle → `vehicle_id=VH0100` |
| ✅ | A student is on that route | `STU0159` Konda Reddy |

**Reference values — use these exactly:**

```
driver      STA0001        Vipul TIwari    login ankitprajapati8134@gmail.com
vehicle     GJ01AB1234     id VH0100
route       RT0045         South Circle          ← NOT Central Loop (RT0049)
student     STU0159        Konda Reddy
```

## Blocked until you supply these

| # | Missing | Consequence if you proceed without it |
|---|---|---|
| B1 | **Latitude/longitude on the 4 stops** — they saved as `0,0` | distance-to-next-stop and the arrival geofence have nothing to measure → §C9 distance is BLOCKED |
| B2 | **Passwords** for the driver / parent logins | cannot sign in; reset them in the admin panel |
| B3 | **MapTiler or TomTom key** | every map says "No map provider configured" → §C3, §D3 are BLOCKED, not FAIL |
| B4 | **Delete trip `TRP000095` and create a new one** | it was created before the roster fix, so its frozen roster is empty — no parent can see it |

Stops are now present on `RT0045` (4, with pickup times) — that part is done.

B3 blocks only the *map picture*. Everything else — the fleet list, states, stop progress, lifecycle —
is testable without it. Do not record those as failures.

---

# PART A — Setup (each step is itself a test)

| # | Do | Expect | Result |
|---|---|---|---|
| A1 | Transport → Routes & Stops → Stops → edit each of the 4 South Circle stops and give it a real **latitude/longitude** (they saved as `0,0`) | coordinates persist | ☐ |
| A1b | Delete trip **`TRP000095`** | gone from Trip Monitor | ☐ |
| A2 | Transport → Trips → **Create Trip** | modal opens; **Vehicle dropdown is populated** (this was the bug you hit) | ☐ |
| A3 | Read both dropdowns | each option reads `name · id`, e.g. `South Circle · RT0045`, `GJ01AB1234 · VH0100` | ☐ |
| A4 | Clear the vehicle and press Create Trip | refused with **"Please select a vehicle."** — not a generic server error | ☐ |
| A5 | Pick vehicle `GJ01AB1234`, route **`RT0045`**, date **today**, direction Pickup → Create | trip created in **Ready** | ☐ |
| A6 | Trip Monitor row | shows the trip, status Ready, **4 checkpoints** | ☐ |
| A7 | Inspect the create response (devtools → Network → Response) | **`roster_size` is 1**, `roster_student_ids` contains `STU0159`. A 0 here means the roster fix did not take — stop and tell me | ☐ |
| A8 | Same response | **no HTML before the JSON** — no "A PHP Error was encountered" block | ☐ |

> If A5 fails with *"does not have transport-driving enabled"*, the flag write did not land — stop and tell me.

# PART B — Driver app (real hardware, Teacher app)

| # | Do | Expect | Result |
|---|---|---|---|
| B1 | Sign in as `ankitprajapati8134@gmail.com` (Vipul) | Transport module **appears** — no "enabled in HR" message | ☐ |
| B2 | Turn phone **Location Services OFF** → tap Start Trip | refuses with exactly **"Please enable Location Services to start the trip."** and the trip does **not** start | ☐ |
| B3 | Turn Location Services **ON** → tap Start Trip again | starts immediately, no extra step | ☐ |
| B4 | Look for any place to type coordinates | **none exists anywhere** | ☐ |
| B5 | Pull down the notification shade | persistent tracking notification present | ☐ |
| B6 | Trip progress line | reads "Stop N of 3 · next: …" | ☐ |

# PART C — Admin Live Fleet Map

Open **Operations → Live Fleet Map** (`/transport_fleet`) in a browser on the laptop.

| # | Do | Expect | Result |
|---|---|---|---|
| C1 | Look at the page frame | header "Live Fleet" **visible, not clipped** under the top bar; counters wrap, no sideways scroll | ☐ |
| C2 | Counters | 6 tiles; `Active = 1` once the driver starts | ☐ |
| C3 | Map area | with a key: real tiles. Without: **"No map provider configured"** stated inside the frame — grey silence is a FAIL | ☐ |
| C4 | Sidebar list | Vipul's bus listed within ~15 s of B3 | ☐ |
| C5 | Before the first GPS fix | state **Not tracking**, no marker | ☐ |
| C6 | After the first fix | state **Live**; marker at the phone's real position | ☐ |
| C7 | Walk 100 m+ | marker moves; **never ahead of** where the phone actually is | ☐ |
| C8 | Click the sidebar row | map focuses that bus; row highlights | ☐ |
| C9 | Detail panel | vehicle, driver, route, next stop, stops done, progress, straight-line distance, schedule, on-board | ☐ |
| C10 | Driver taps a stop **Reached** | stops-done increments; next stop advances | ☐ |
| C11 | Toggle **Day / Night** (top bar) | cards, text and **markers** all re-theme; nothing unreadable | ☐ |
| C12 | Devtools → Network | one `live` call ≈ every 15 s — **no flood** | ☐ |
| C13 | Status line | shows "cached Ns" on some polls | ☐ |
| C14 | Background the tab 2 min, return | polling pauses then resumes; poll count confirms | ☐ |

# PART D — Parent app

| # | Do | Expect | Result |
|---|---|---|---|
| D1 | Sign in as the parent of `STU0159` | My Bus opens | ☐ |
| D2 | While the trip runs | live tracking appears | ☐ |
| D3 | Map | real position on real tiles (**BLOCKED** without B3) | ☐ |
| D4 | Driver moves | marker glides; never beyond the reported position | ☐ |
| D5 | Stop progress | "N stops away from your stop", decreasing correctly | ☐ |
| D6 | Compare with the admin map | **same position on both** | ☐ |

> Note: the driver login and this parent contact are currently the **same email**. If they truly share
> one identity, D1 cannot be done independently of B1 — record that as BLOCKED and tell me.

# PART E — Stop / degrade / negative

| # | Do | Expect | Result |
|---|---|---|---|
| E1 | Turn the phone's GPS off, wait > 2 min | admin state → **Stale**, "Last seen N min ago"; parent shows a weak-signal note | ☐ |
| E2 | Wait > 10 min | state → **No signal** | ☐ |
| E3 | Turn GPS back on | returns to **Live** | ☐ |
| E4 | Driver taps **Complete Trip** | notification clears; tracking stops | ☐ |
| E5 | Admin map | bus and row **disappear** | ☐ |
| E6 | Watch 2 min after completion | **no further movement** — a moving bus after completion is a hard FAIL | ☐ |
| E7 | Repeat and **Cancel** instead | same clean stop | ☐ |
| E8 | Stop Apache mid-session | honest error on screen, **never fake data** | ☐ |
| E9 | Sign into admin as staff **without** Transport view | cannot open `/transport_fleet` | ☐ |

# PART F — The one sequence that matters most

Run continuously, no pauses:

```
Driver : sign in → Location ON → START TRIP → walk a few hundred metres
Admin  : Live Fleet → bus appears → marker tracks the walk
Parent : My Bus → same movement → stop count decreasing
Driver : COMPLETE TRIP
Admin  : bus disappears        Parent : tracking stops
```

| # | Checkpoint | Result |
|---|---|---|
| F1 | Every position came from GPS hardware — nothing typed anywhere | ☐ |
| F2 | Admin and parent showed the **same** position | ☐ |
| F3 | Both updated within ~15–30 s of movement | ☐ |
| F4 | Neither ever showed the bus ahead of its real position | ☐ |
| F5 | Completion stopped tracking on **both** | ☐ |
| F6 | No crash on any of the three surfaces | ☐ |

---

## Expected, not defects

| Observation | Why |
|---|---|
| Position up to ~27 s old | 15 s poll + 12 s server cache, matched to the GPS write cadence |
| Distance labelled "straight line" | not road distance |
| **No ETA anywhere** | deliberately deferred |
| Google / Mapbox / HERE → "not available in this app version" | placeholders |
| A route without stop times → schedule "unknown" | never guessed |
| Two phantom buses from 2026-07-22 / 2026-08-08 | probe residue; say the word and I'll close them |

## Recording

Mark each ☐ **PASS**, **FAIL** or **BLOCKED**, with what you observed. For a FAIL: which surface, the
exact steps, what appeared vs what was expected, and a screenshot. Send it to me — I'll classify and
propose a fix before changing any code.

**Until a person fills this in, the status is: MANUAL UAT = NOT PERFORMED.**
