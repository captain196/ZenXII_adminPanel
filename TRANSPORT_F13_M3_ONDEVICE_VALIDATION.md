# F13.1 M3 — Compile + On-Device Validation Results

**Date:** 2026-08-08
**Device:** `I2011` (`96374480720007C`), USB
**Backend:** local XAMPP, bridged with `adb reverse tcp:8080 tcp:80`
**Status:** **COMPLETE.** Device and project both restored.

> Release constraints honoured: no commits, no rebases, no release branch, no push, no deployment.
> Production was never contacted by the app; the debug build points only at localhost.

---

## 1. Compile — and the defect it caught

**`BUILD SUCCESSFUL`** — `app-debug.apk`, 30.9 MB, with `TripTrackingService` and
`TripTrackingController` compiled in.

The first build **failed**, on a real defect that code inspection had missed:

```
e: AppModule.kt:299:56 No value passed for parameter 'apiService'
```

`TransportFirestoreRepository` is constructed **twice** — once via its `@Inject` constructor and
again in a Hilt `@Provides` in `AppModule`. Adding the `apiService` parameter satisfied the first
and broke the second. Fixed, rebuilt clean.

This is the third defect the verify-don't-infer pass has caught, after `ClientMetadata.asMap()`
(would have failed every position write against the rule's field allowlist) and
`R.drawable.ic_launcher_foreground` (a mipmap, not a drawable). Requiring the compile was the right
call — inspection alone would have shipped a build that does not assemble.

---

## 2. Debug-only local configuration

`app/build.gradle.kts` gains a **debug** block. The **release block is untouched** and still ships
`https://www.zenxii.com/`:

```kotlin
debug {
    val localBase = (project.findProperty("F13_LOCAL_BASE_URL") as String?)
        ?: "http://localhost:8080/ZenX/school/"
    buildConfigField("String", "BASE_URL", "\"$localBase\"")
}
```

Verified in the generated artifact, not assumed:

```
BuildConfig.java (debug) →  BASE_URL = "http://localhost:8080/ZenX/school/"
```

Port **8080** on the device, not 80: `adb reverse tcp:80 tcp:80` fails with
`cannot bind listener: Permission denied`, because ports below 1024 are privileged inside the app
sandbox. 8080 forwards to Apache's :80 on this PC — which is exactly what the build file's own
pre-existing comment recommends.

This is a standing improvement, not a temporary hack: a debug build should never be able to reach
production. It is the configuration you asked for, so it stays.

---

## 3. Device handling — made reversible before anything was removed

The installed app was **release-signed** (`flags=[ HAS_CODE ALLOW_CLEAR_USER_DATA ]`, no
`DEBUGGABLE`), so `adb install -r` failed with `INSTALL_FAILED_UPDATE_INCOMPATIBLE` — harmlessly,
changing nothing.

Proceeding required an uninstall. Before doing that I found something that changed the risk:
**`keystore.properties` is absent and no release APK exists locally**, so the release build could not
be regenerated on this machine. The uninstall would have been irreversible — more than the
"uninstall/reinstall" you approved.

So I made it reversible first: pulled all three installed splits off the device.

| Artefact | Size |
|---|---|
| `base.apk` | 12,899,735 B |
| `split_config.en.apk` | 86,425 B |
| `split_config.xxhdpi.apk` | 91,252 B |

Only then: uninstall → install debug → validate → **restore from the backup**.

The app also reported `notLaunched=true, stopped=true` (installed 2026-08-06, never opened), so
there was no login session or user data at stake either way.

---

## 4. What was validated ON DEVICE

| # | Check | Result |
|---|---|---|
| 1 | Debug APK installs | **Success**, `flags=[ DEBUGGABLE … ]` |
| 2 | App launches | **No crash**, process alive (pid 3072) |
| 3 | **Hilt graph resolves** with the new components | No `Hilt`/`InstantiationException` in logcat — a broken graph would crash at Activity creation, so this exercises `TripTrackingController` injection into the ViewModel |
| 4 | Manifest merge — service | `<service android:name="…TripTrackingService" android:exported="false" android:foregroundServiceType="location" />` |
| 5 | Manifest merge — permissions | `ACCESS_FINE_LOCATION`, `FOREGROUND_SERVICE` (granted), `FOREGROUND_SERVICE_LOCATION` all present on the installed package |
| 6 | Device reaches the local backend | from the device shell: `admin_login` → **200** |
| 7 | Driver API reachable + auth-gated from the device | `transport_driver_api/start_trip` → **401** |
| 8 | Confirmed in Apache's log | `POST /ZenX/school/transport_driver_api/start_trip 401` from `curl/7.80.0` (the phone) |

---

## 5. What was validated END-TO-END on the local backend

`transport_probe_f13_e2e` — **29 assertions, 0 failures, zero residue.** It drives the real
`Transport_service` lifecycle, which is precisely the code path `transport_driver_api` invokes for
the app, so this validates the driver flow's server half exactly.

| Step | Assertions |
|---|---|
| **A — createTrip → Ready** | `gps_session_id` minted; tracking **off**; **no hot doc** for a merely-Ready trip |
| **B — Start Trip** | status `InProgress`; **kill switch ARMED**; `gps_start_at` stamped; **hot doc created server-side**; bound to trip; ordering key seeded `0`; viewer allowlist present; **no position asserted yet** |
| **C — Live position** | clean fix **accepted** and persisted; **older fix rejected** (`out_of_order`); newer accepted; **mock rejected even though newer**; low-accuracy rejected; a 15-minute-old queued fix **never becomes "current"** |
| **D — Complete Trip** | status `Completed`; **kill switch DISARMED**; `gps_end_at` stamped; **hot doc DELETED** |
| **E — Cancel Trip** | same disarm + teardown on the abnormal path |

Every item you listed — Start Trip, GPS session creation, live position updates, Complete Trip,
session teardown — is covered, on both the normal and the cancel path.

Teardown removed all 7 fixtures and reported `live-position residue: none`.

---

## 6. What was NOT validated, and why

**Driving the flow through the app's UI.** That needs a logged-in driver with an assigned Ready
trip. I have no driver credentials, and the standing rule is not to create demo or test accounts —
you add real staff through the admin panel. So the app's own network calls (as opposed to the
device's ability to make them, §4.6–4.8) were not exercised.

What that leaves genuinely unproven is narrow but real: the Retrofit wiring inside the app —
`startMyTrip` → `ApiService.startTrip` → a 200 — and the `TripTrackingService` streaming a live fix
from actual hardware. Everything either side of that seam is proven: the app compiles and its DI
graph resolves (§4), the device reaches the endpoint and is correctly refused without a token (§4),
and the server half handles the full lifecycle including GPS (§5).

To close it you would log in on the device as a real driver with a Ready trip, with
`adb reverse tcp:8080 tcp:80` active. I can walk through that whenever you want to supply an
account.

---

## 7. Restoration — everything put back

| Item | State |
|---|---|
| **Device app** | **Restored** to the original release build from the backup, all 3 splits. `versionName=1.0.1`, `flags=[ HAS_CODE ALLOW_CLEAR_USER_DATA ]` — `DEBUGGABLE` gone. |
| **adb reverse** | **Removed** — `adb reverse --list` is empty. |
| **Backup APKs** | Retained in the session scratchpad in case they are wanted again. |
| **Production** | Never contacted by the app. The only production traffic was two read-only `curl` probes that returned 401/403 and created nothing. |
| **`build.gradle.kts` debug block** | **Kept** — this is the configuration you asked for, not a temporary change. Release is untouched. |

---

## 8. Verdict

Compile: **passed**, having caught a real defect.
On-device: **passed** for install, launch, DI, manifest merge, permissions, and backend reachability.
End-to-end lifecycle + GPS: **29/29 on the local backend**.
Outstanding: one UI-level pass with a real driver account (§6).

Proceeding to **M4 — Parent Live Map**.
