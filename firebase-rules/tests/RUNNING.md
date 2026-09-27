# Running the Firestore rules security suite

```bash
cd firebase-rules/tests
npm test
```

Requires **JDK 21+** (firebase-tools 15 refuses Java 17). If your system Java is
older, Android Studio ships a suitable runtime:

```bash
export JAVA_HOME="C:\Program Files\Android\Android Studio\jbr"
export PATH="$JAVA_HOME/bin:$PATH"
```

## Why testTimeout is 240000

Measured 2026-09-19 on a loaded workstation. At the previous 60000 the suite
reported **870 failed / 111 passed of 1625** — and not one of those was an
assertion failure. Every single one was `Exceeded timeout ... for a hook`:

* `emulators:exec` boots the Firestore emulator, and on a busy machine that boot
  plus each suite's `beforeAll` seeding exceeded the limit;
* four suites are heavy enough to exceed it on their own —
  `institution_lifecycle_sec`, `institution_nullmap_sec`, `lifecycle_attack_sec`
  and `timetable_overlay_tenant_read`.

That is the dangerous shape: a security suite that reports mass failure for
environmental reasons trains its readers to ignore it, and a suite nobody trusts
is a suite nobody runs. Raising the timeout does not weaken a single assertion —
it only stops the harness failing before the assertions execute.

With the emulator up and the timeout raised the suite is **994 passing, zero
assertion failures**, 644 skipped. The skips are the documented cross-repo tests
that grep the Kotlin apps from a hardcoded path on another machine (see CLAUDE.md).

## Running a subset

Some suites pass `{host, port}` to `initializeTestEnvironment` explicitly (port
18080, per `firebase.json`); four rely on `emulators:exec` auto-discovery via
`FIRESTORE_EMULATOR_HOST`. So running `npx jest` directly against an
already-running emulator needs that variable set, or those four cannot find it:

```bash
FIRESTORE_EMULATOR_HOST=127.0.0.1:18080 npx jest --runInBand --forceExit
```
