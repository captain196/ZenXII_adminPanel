# 🛡️ ZenXii Aegis

**Answers one question automatically for every change: "what could this break?"** — before it
reaches a school in production.

Pure Node ≥ 18. **Zero runtime dependencies.** Aegis is a *control plane*: it never sits in the
request path of a school user, and it can never itself cause a production regression.

---

## 1. What ZenXii is — the three products

ZenXii is **one school ERP shipped as three products**, all sharing **one Firebase project**
(`graderadmin`, bucket `graderadmin.appspot.com`):

| # | Product | Who uses it | Stack |
|---|---|---|---|
| 1 | **Admin panel + website** — `zenxii.com` | school admins, office staff, super-admins | CodeIgniter 3.1.13, PHP 8, ~140 controllers |
| 2 | **ZenXii Teacher** — the *staff* app | all staff (teaching **and** non-teaching), gated by RBAC | Kotlin, Compose, Hilt — `com.schoolsync.teacher` |
| 3 | **ZenXii Parent** | parents / guardians | same stack — `com.schoolsync.parent` |

> "zenxii" in a request means **all three** unless narrowed. **A change to a module usually has to
> land in several of them at once — that is the defining property of this codebase, and the reason
> Aegis exists.**

They are joined by shared infrastructure rather than shared code:

```
                  ┌──────────────────────────────┐
                  │   Firebase  ·  graderadmin   │
                  │  Firestore (nam5/US) · Auth  │
                  │  Storage · Cloud Functions   │
                  └───────┬───────────┬──────────┘
                          │           │
        ┌─────────────────┼───────────┼─────────────────┐
        │                 │           │                 │
 ┌──────┴──────┐   ┌──────┴──────┐   ┌┴────────────┐   ┌┴──────────────┐
 │ Admin panel │   │   Teacher   │   │   Parent    │   │ Node backend  │
 │  PHP / CI3  │   │   Android   │   │   Android   │   │ Express/Mongo │
 └─────────────┘   └─────────────┘   └─────────────┘   └───────────────┘
        │
        └── firestore.rules + firestore.indexes.json  ← the shared blast surface
```

**`firestore.rules` and `firestore.indexes.json` are the seam all three cross.** One file, one
index set, guarding every tenant's data across every product — edited by several people and
several Claude sessions at once, and deployed independently of any code. That is what Aegis
watches most closely.

---

## 2. What Aegis is — the three subsystems

| # | Subsystem | Question it answers | Command |
|---|---|---|---|
| 1 | **Impact & contracts** | "if I change this file, what else breaks?" | `impact` · `verify` · `gate` · `graph` |
| 2 | **Rules Sentinel** | "what is *actually enforcing* production right now, and has anyone drifted?" | `rules status` · `preflight` · `plan` |
| 3 | **Index Sentinel** | "is every composite index my queries need actually deployed and READY?" | `indexes status` · `preflight` · `plan` |

### Why 2 and 3 exist at all

Git cannot answer either question.

- **Rules** — `firebase deploy --only firestore:rules` replaces the **entire file**. A teammate can
  deploy from their own machine, so production can hold rules that exist in **nobody's checkout**.
  Deploy from a stale copy and you silently revert their work: no error, no log.
- **Indexes** — deploy *creates* but never deletes. So production accumulates indexes that are in
  no commit, and git can no longer rebuild the database.

Aegis reads **production itself** — the Firebase Rules API for rules, the Firestore Admin API for
indexes — and compares it against **four** sources:

```
   LIVE          REMOTE            HEAD           WORK
production   what the team    your local     your disk
             has pushed        commit        right now
     │            │               │              │
     └────────────┴───────┬───────┴──────────────┘
                          │
              two independent axes of drift,
              each invisible to the other:

   LIVE vs HEAD    → did someone DEPLOY something in no commit?
   REMOTE vs HEAD  → did someone COMMIT+PUSH something I never pulled?
```

That second axis matters more than it sounds: `git show HEAD:` returns your **local** head. If a
colleague pushes a rules change and you have not fetched, every block would otherwise report
`clean`.

### One rule above all others

**Aegis never writes.** No deploy path, no `git commit`, no `git push`, no edits to your
`settings.json`. The only state-changing command it runs is `git fetch`, which touches
remote-tracking refs and nothing else. It computes the verdict and prints the exact command —
moving the branch and shipping to production stay your decision, every time.

---

## 3. Where everything lives

| Surface | Path | Git |
|---|---|---|
| Admin panel + website | `~/Desktop/Zennxii_adminPanel` *(double-n)* | `ZenXII_adminPanel.git` · work `yug_testing` · **deploy `yug_b1_t`** |
| Cloud Functions | `…/Zennxii_adminPanel/functions` | ↑ same repo |
| Rules / indexes | `…/Zennxii_adminPanel/firebase-rules` | ↑ same repo |
| **Aegis (this tool)** | `…/Zennxii_adminPanel/aegis` | ↑ same repo, branch `aegis` |
| Node auth backend | `~/Desktop/project2` | own repo |
| Teacher app | `~/AndroidStudioProjects/ZenXII_Teacher` | `ZenXII_Teacher.git` |
| Parent app | `~/AndroidStudioProjects/ZenXII_Parent` | `ZenXII_Parent.git` |

Aegis lives **inside the admin-panel repo** because that is where its subject matter is —
`firestore.rules` and `firestore.indexes.json` are two directories away. (It previously sat in the
`Grader_Teacher` legacy-fork repo, which was the wrong home.)

`aegis.config.json` is the machine-readable version of this table. `node cli.js doctor` asserts
every path still resolves.

> **Note for deploys:** `aegis/` ships to the Ohio production box with the rest of the repo, so it
> carries its own `.htaccess` denying all web access. It is developer tooling and is never served.

---

## 4. Setup on a new system

Tested end-to-end on a clean clone with a different directory layout.

### 4.1 Prerequisites

```bash
node --version      # must be >= 18
git --version
```
Optional, only for running the rules emulator tests: the `firebase` CLI and a JRE.

### 4.2 Clone

Aegis needs the admin-panel repo — that is where the files it guards live. Cloning that repo gets
you both at once:

```bash
mkdir -p ~/dev && cd ~/dev
git clone -b aegis https://github.com/captain196/ZenXII_adminPanel.git Zennxii_adminPanel
cd Zennxii_adminPanel/aegis
```

> Keep the folder name **`Zennxii_adminPanel`** (double-n) — `setup` fingerprints it.

The apps are optional. Clone them only if you want impact analysis across all three products:

```bash
mkdir -p ~/AndroidStudioProjects && cd ~/AndroidStudioProjects
git clone https://github.com/captain196/ZenXII_Teacher.git
git clone https://github.com/captain196/ZenXII_Parent.git
```

### 4.3 Run setup

```bash
cd ~/dev/Zennxii_adminPanel/aegis
node cli.js setup
```

It probes the usual locations (`~/Desktop`, `~/dev`, `~/projects`, `~/AndroidStudioProjects`,
`~/StudioProjects`, `$HOME`), **fingerprints** each hit — a directory only counts as the admin
panel if it really contains `application/controllers` and `firebase-rules` — and writes **only the
paths that differ** into `aegis.config.local.json`, which is gitignored.

That last detail matters: if everyone committed their own absolute paths, `aegis.config.json` would
conflict on every single pull.

`setup` also installs the `firestore-rules` subagent and the blast-gate hook from the tracked
copies in `share/` — necessary because `.claude/` is gitignored, so a clone carries neither.

### 4.4 The credential — the one real decision

Reading **live** rules and indexes needs a Google credential. Without it Aegis still runs, but only
on the git axis, and every command says so rather than pretending production is clean.

**Do not copy the admin SDK key to another machine.** It is full-privilege. Mint a read-only one —
and note it needs **two** roles, because rules and indexes are two different Google APIs:

```bash
gcloud iam service-accounts create aegis-viewer --project graderadmin

gcloud projects add-iam-policy-binding graderadmin \
  --member="serviceAccount:aegis-viewer@graderadmin.iam.gserviceaccount.com" \
  --role="roles/firebaserules.viewer"     # rules

gcloud projects add-iam-policy-binding graderadmin \
  --member="serviceAccount:aegis-viewer@graderadmin.iam.gserviceaccount.com" \
  --role="roles/datastore.viewer"         # indexes

gcloud iam service-accounts keys create ~/.config/aegis/aegis-viewer.json \
  --iam-account=aegis-viewer@graderadmin.iam.gserviceaccount.com
```

Grant only the first and you get a confusing half-working state: `aegis rules` works while
`aegis indexes` reports `forbidden`.

Then point Aegis at it (add to `~/.zshrc` / `~/.bashrc`):

```bash
export AEGIS_FIREBASE_SA=~/.config/aegis/aegis-viewer.json
```

Aegis stores only the **path**. The key is never copied, printed, or sent anywhere but Google's
token endpoint.

### 4.5 Wire the hook

`setup` prints the snippet; it will **not** edit `settings.json` for you. Add to
`~/.claude/settings.json`:

```json
{
  "hooks": {
    "PreToolUse": [
      { "matcher": "Edit|Write|MultiEdit",
        "hooks": [{ "type": "command", "command": "$HOME/.claude/hooks/firestore-rules-gate.sh" }] }
    ]
  }
}
```

Now every edit to `firestore.rules` injects the live production board automatically.

### 4.6 Verify

```bash
node cli.js doctor           # every line ✓ (incl. "firebase SA (live)")
node cli.js rules status     # must print a live ruleset id + a branch-sync verdict
node cli.js indexes status   # must print a live index count, not "forbidden"
npm test                     # 154 assertions
```

**If `rules status` says "REMOTE not read"** — the branch has no upstream, so the git axis is dark:

```bash
git branch --set-upstream-to=origin/<branch>
```

**If a push fails with `HTTP 400 / RPC failed`** — git's 1 MB default buffer is too small:

```bash
git config http.postBuffer 524288000
```

### 4.7 Never copy these between machines

| Never send | Why |
|---|---|
| `.state/` | your cached **OAuth token**, fetch stamps, leases, ruleset snapshots |
| `aegis.config.local.json` | that machine's paths — it is gitignored for this reason |
| the service-account JSON | a credential; mint a per-person read-only one instead |
| `.reports/` | generated output |

A `git clone` never carries any of them. If you send a zip, delete `.state/` first.

---

## 5. Daily use

```bash
# before touching a shared module
node cli.js graph attendance          # blast radius
node cli.js impact                    # risk + contracts + suggested tests for your diff

# before ANY rules deploy or push  ← the important one
node cli.js rules preflight           # must I pull first, and in what order do I ship

# before shipping code with new queries
node cli.js indexes preflight         # indexes first — they build asynchronously

# the hard gate
node cli.js gate                      # blocks only on BLOCKING contracts
```

The same commands are wired as VS Code tasks — **⇧⌘P → Run Task → Aegis**.

### Deploy order across the system, and why

1. **Indexes** — they build asynchronously; a query fails with `FAILED_PRECONDITION` until its
   index is `READY`.
2. **Rules** — then the boundary that permits the query.
3. **Code** (PHP / apps) — last, so it never runs against missing infrastructure.

Rules, indexes and Cloud Functions all deploy **separately** from the PHP code. Shipping code that
needs a new index without the index is a guaranteed production break.

### Working agreements Aegis is built around

- **Never commit, push or deploy without explicit permission for that specific change.** Approval
  for one deploy never carries to the next.
- **Local-first** — edit locally, verify on `localhost:8080` or a device build, *then* discuss
  deploying.
- **Commit and push before you deploy, never after.** Deploying first is exactly what creates
  blocks and indexes where production is the only copy.

---

## 6. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `indexes` says `forbidden`, `rules` works | missing `roles/datastore.viewer` | add the second role (§4.4) |
| `⚠ LIVE not read (no-credentials)` | `AEGIS_FIREBASE_SA` unset or path wrong | `node cli.js doctor` names the file it expected |
| `⚠ REMOTE not read (not-a-repo)` | the surface is not a git checkout | clone it properly, or ignore if intentional |
| `refs STALE` | last `git fetch` > 15 min ago | `--fresh` forces a refetch |
| `doctor` fails on a surface | repo lives elsewhere | `node cli.js setup`, or set `AEGIS_<NAME>_ROOT` |
| Hook prints nothing on a rules edit | Aegis not found, or watching a different file | `AEGIS_HOME=/path/to/aegis`; check `doctor`'s `firestore.rules` line |

**Config precedence**, lowest first — `doctor` prints which layers actually loaded:

```
aegis.config.json  <  aegis.config.local.json  <  AEGIS_* env vars
   (committed)          (gitignored)              (per-shell)
```

Paths accept `~`, `${HOME}` and `${AEGIS_ROOT}`.

---

## 7. What Aegis will never do

- Never deploy, commit, push, or merge.
- Never edit your `settings.json`, or overwrite an existing agent/hook without `--force`.
- Never write to production — live access is read-only against both Google APIs.
- Never claim production is clean when it simply could not look. Every degraded path reports
  `unknown` and says what it could not determine.

That last one is the design principle the whole tool turns on: **a wrong verdict is more dangerous
than no verdict.**

---

## 8. Deeper reference

- [`PORTING.md`](PORTING.md) — full per-machine porting detail, credential minting, and an honest
  table of which cross-machine collisions are and are not detectable.
- The rest of this document covers the two sentinels in depth — states, severity, and the API
  quirks each absorbs.

---

## `rules` — the Firestore Rules Sentinel

Everything above reasons about files on disk. `rules` is the one subsystem that
reads what is **actually enforcing production**, via the Firebase Rules API —
and what the **team has pushed**, via the git remote.

That distinction is the whole point. `firestore.rules` is a single large file,
guarded by ~170 match blocks, edited by several concurrent Claude sessions and
by teammates who deploy from their own machines — and `firebase deploy` replaces
the **entire file**. So a stale local copy does not fail loudly; it silently
reverts whoever deployed last.

There are **two independent axes** of staleness, and each is invisible to the
other:

| Axis | Question | Why the other axis misses it |
|---|---|---|
| **LIVE vs HEAD** | did someone *deploy* something that is in no commit? | git has never seen it |
| **REMOTE vs HEAD** | did someone *commit and push* something I have not pulled? | nobody deployed it, so production looks fine |

The second one is easy to overlook: `git show HEAD:` returns your **local**
head. If a colleague pushes a rules change and you have not fetched, HEAD is
still your stale copy and every block would otherwise report `clean`.

| Command | Does | Exit |
|---|---|---|
| `rules status [-v] [--fresh] [--offline] [--no-fetch]` | four-way board: LIVE vs REMOTE vs HEAD vs working tree, per block, with risk + module ownership + active leases | 1 on conflict or behind-in-rules |
| `rules preflight` | **run before any deploy or push** — fetches, decides whether you must pull first, prints the ordered plan | 1 if blocked |
| `rules plan` | pre-deploy brief — every block the whole-file deploy would ship, split into yours / undeployed / **theirs** / **behind** / conflicting | 1 if unsafe |
| `rules sync` | refresh the snapshot; reports "production moved since you last looked" | 2 if unreadable |
| `rules blocks [filter]` | block → module map with the grants each block makes | 0 |
| `rules claim <target…> --note "…"` | lease blocks before editing; refuses if another session holds one | 1 on collision |
| `rules who` / `rules release` | inspect / drop leases | 0 |
| `rules pull` | print the live ruleset (diff against it, or recover a block from it) | 0 |
| `rules history` | recent rulesets — the real deploy log, independent of git | 0 |
| `rules insights` | accumulated memory: which blocks and modules churn most | 0 |

### The seven states

`status` labels every block, and the label determines the remedy:

- **clean** — live, remote, git and disk all agree.
- **mine** — your uncommitted edit; prod matches git. Normal.
- **undeployed** — committed, never shipped.
- **theirs** — ⚠️ prod has a version your branch never saw. Deploying destroys it.
- **live_uncommitted** — ⚑ prod matches your disk but the change is in no commit.
  Production is the only copy; a deploy from a clean checkout wipes it.
- **behind** — ⇊ a teammate pushed this to git and nobody deployed it. The live
  axis is silent; only the remote axis catches it. Pull before you ship.
- **conflict** — 🚨 you edited a block that also drifted **in production**.

Two flags sit **orthogonal** to the state, because a block can be several things
at once and flattening them into one enum would hide whichever lost the
tie-break:

- **`unpulled`** — the remote has a different version of this block.
- **`pullConflict`** — you edited a block the remote also changed, so `git pull`
  will conflict here. Neither a plain `git status` nor the live axis warns first.

A changed **shared helper** (`isSameSchool`, `isAdmin`, …) is reported
separately with its caller count and tagged `live≠head` / `head≠work` /
`remote≠head`, because editing one re-points every block that calls it.

### Honesty over reassurance

Every degraded path reports itself instead of defaulting to "clean":

- live unreadable → `⚠ LIVE not read` and production drift is declared unknown
- no upstream / fetch failed / never fetched → `unknown`, never "in sync"
- refs older than 15 minutes → `stale`, and an `ok` verdict says so in words

Reporting in-sync when the remote was simply not consulted is the exact failure
the remote axis was added to remove, so it is never allowed to happen quietly.

### Why it doesn't cry wolf

Comparison is **semantic**, not textual: comments are stripped and whitespace
collapsed before hashing each block. The live ruleset genuinely differs from git
in six mojibake'd box-drawing characters inside comments — a textual diff would
alarm on every run, and a tool that alarms constantly gets ignored.

Risk analysis resolves helpers **transitively**. `allow write: if isAdmin()`
looks ungated to a flat regex, but `isAdmin()` checks
`tenantActive(request.auth.token.school_id)` internally. Flagging the codebase's
own correct idiom as high-risk is how a security tool gets muted.

### Credentials and caching

Reads use the gitignored service-account JSON already on disk
(`firebase.serviceAccount` in `aegis.config.json`, or `$AEGIS_FIREBASE_SA`).
Aegis stores only the *path*, never the key material. Access tokens and ruleset
snapshots are cached under `.state/` (gitignored, token file `0600`), because
the PreToolUse hook runs `status` on every rules edit — cold ~4s, warm ~165ms.
Anything gating a deploy (`plan`, `sync`) always refetches.

Without credentials every command still works; it reports `liveAvailable: false`
and says plainly that drift cannot be determined, rather than implying agreement.

## `indexes` — the Firestore Index Sentinel

The rules sentinel's sibling, and the answer to a gap that mattered: nothing in
Aegis could see composite indexes. `contracts/firestore_index.js` only ever
regex-matched changed *code* for chained `.where().where()` and emitted a
warn-only "confirm a matching index is deployed" — it never read
`firestore.indexes.json`, let alone production.

Indexes are a second integration, not a parameter change. They live behind the
**Firestore Admin API** (`firestore.googleapis.com`) under the `auth/datastore`
scope, not the Firebase Rules API. Same service-account key, separate scope,
separately cached token.

| Command | Does | Exit |
|---|---|---|
| `indexes status [-v] [--fresh] [--offline] [--no-fetch] [--json]` | four-way board: live vs remote vs HEAD vs disk | 1 if blocking |
| `indexes preflight [--json]` | what to do, in order, before shipping index-dependent code | 1 if blocked |
| `indexes plan [--json]` | what a deploy would create (deploys never delete) | 0 |
| `indexes pull` | print production as a `firestore.indexes.json` | 0 |

### Severity is INVERTED relative to rules

This is the thing to get right, and it follows from how each deploy behaves:

| | rules | indexes |
|---|---|---|
| a deploy… | replaces the **whole file** | only **creates** what is missing |
| the emergency is… | `theirs` — live has what git lacks, and your deploy reverts it | `undeployed` — git has what live lacks, and its query is **failing right now** with `FAILED_PRECONDITION` |
| the milder defect is… | `live_uncommitted` | `live_only` — git cannot rebuild the database |

So `indexes` blocks **only** on `undeployed` and `building`. It deliberately
does *not* block on `live_only`: gating on 101 pre-existing undocumented indexes
would make the check useless on day one, and a check that always fails gets
muted.

### The states

`clean` · `mine` (in your file, uncommitted) · `undeployed` (committed, not in
production — **blocks**) · `building` (deployed but not yet `READY`, so still not
serving — **blocks**) · `behind` (a teammate pushed it, you have not pulled) ·
`live_only` (in production, in no commit) · `dropped_locally` (you removed it
from the file, but an index deploy will not delete it from production).

### Two API quirks it absorbs

- `pageSize` is rejected outright — *"Invalid page size. Only 0 is supported."*
  Paging is by `pageToken` alone.
- Every live index carries a trailing `__name__` field that
  `firestore.indexes.json` omits, because Firebase appends it implicitly.
  Compare naively and every live index looks different from every declared one.
  Identity strips it **only** when it echoes the previous field's direction; a
  `__name__` that overrides direction is a genuinely different index and is kept.


## What's REAL and runnable now (P0–P2)

- **Impact engine** — `lib/`: classify → BFS blast radius → weighted risk score → suite selection → markdown report. Reads real git diffs.
- **Module + contract graph** — `manifest.js`: 20 real ZenXii modules with regression fan-out, 12 cross-surface contracts, seeded from your hardening docs. `doctor` enforces its integrity.
- **6 contract checks** (`contracts/`), wired to your real files:
  - `rules_deny_default` — scans the real 1832-line `firestore.rules`; passes all 274 rules, catches an injected `if true` world-open (**BLOCK**).
  - `claim_key` — verifies the 4 claims-writer files dual-emit `school_id` + `schoolId` (**BLOCK**).
  - `push_mark_registry` — finds your real `functions/index.js` MARK_REGISTRY (21 marks); flags unregistered marks + double-send.
  - `session_scope`, `firestore_index`, `responsive_scroll` — heuristic warns for the recurring bug classes.
- **Regression memory** — parses your real `BUG_LEDGER.md`; the impact report cites prior regressions for touched modules.
- **Hooks + release gate** — `hooks/pre-push` (warn-not-block, `AEGIS_STRICT=1` to block), `ci/release_gate.sh`.
- **Log normalizer** — `observability/normalizer.js`: parses the `[PREFIX] key=value` convention into one event envelope + daily rollup. Runnable: `node observability/normalizer.js <logfile>`.

## CI — you have two paths

- **Local CI (runnable TODAY)** — `ci/local_ci.sh` runs the exact gate pipeline (self-test → doctor → gate, all repos) with no GitHub. This is your CI while you deploy via Lightsail push→pull. `ci/release_gate.sh` gates a single deploy.
- **GitHub Actions** — `ci/github/aegis.yml` is production-ready and self-bootstraps Aegis (clones it if a repo doesn't vendor it). Drop it in once a repo lands on GitHub; branch-protect `main` requiring the `aegis / gate` check.

## Dashboard — real now, deeper later

- `node cli.js dashboard` renders `observability/dashboard.template.html` with a live snapshot → `.reports/dashboard.html` (self-contained, theme-aware). It shows the **integrity/change** health Aegis sees statically today.
- The **runtime** tiles (crash-free, API p95, notification delivery) stay greyed until the telemetry CF (`observability/normalizer.js`) writes `aegis_metrics_daily` — see `observability/dashboard.md` for that build spec. Same template, richer data.

## AI reviewer — real

- `ai/review.js` (`node cli.js review [--run]`) assembles the context pack and, with `--run`, pipes to `claude -p`. Note: `--run` needs `claude` on your claude.ai login — if `ANTHROPIC_API_KEY` is set it takes precedence and blocks it (`env -u ANTHROPIC_API_KEY node cli.js review --run`, or paste the saved pack into Claude Code).

## Scope & limits (read this)

- It's **static analysis + a dependency map, not a full test suite.** It catches
  *structural* mistakes — broken contracts, world-open rules, blast radius — extremely
  well. It won't catch a subtle logic error inside a function that looks fine; that's
  what the smoke tests and AI reviewer (the scaffolded P3–P5 layers) add later.
- The checks are **as smart as the map.** When you add a new module or discover a new
  bug class, you teach it once (add to `manifest.js` + `contracts/`) and it guards that
  forever after.

## Layout

```
Zennxii_adminPanel/
├── firebase-rules/
│   ├── firestore.rules          # what Aegis guards  ← 1 file, all 3 products
│   ├── firestore.indexes.json   # 183 composite indexes
│   └── tests/                   # Jest rules suite against the Firestore emulator
└── aegis/
    ├── cli.js                   # entry: setup | doctor | graph | impact | verify | gate | rules | indexes
    ├── aegis.config.json        # surface paths, ${HOME}-portable (committed)
    ├── aegis.config.local.json  # per-machine overrides (GITIGNORED — never commit)
    ├── aegis.config.example.json# template for the above
    ├── .htaccess                # denies all web access (this dir ships to prod)
    ├── manifest.js              # module + contract graph — the source of truth
    ├── rules/                   # ── Rules Sentinel ──
    │   ├── live.js              #    Firebase Rules API + shared JWT auth (scope-keyed tokens)
    │   ├── vcs.js               #    the git-remote leg: fetch, topology, verdict
    │   ├── diff.js              #    four-way semantic block comparison
    │   ├── parse.js  risk.js    #    block parser · transitive helper risk
    │   ├── lock.js   ledger.js  #    advisory leases · accumulated history
    │   └── index.js             #    status | preflight | plan | claim | who | pull | history
    ├── indexes/                 # ── Index Sentinel ──
    │   ├── live.js              #    Firestore Admin API + canonical index identity
    │   ├── diff.js              #    four-way set comparison (INVERTED severity)
    │   └── index.js             #    status | preflight | plan | pull
    ├── lib/                     # engine: config, setup, git, classify, graph, score, impact, health
    ├── contracts/               # 6 contract checks + runner
    ├── share/                   # tracked masters of the subagent + hook (.claude/ is gitignored)
    ├── test/                    # self-test — 154 assertions (npm test)
    ├── hooks/  ci/  ai/  observability/
    ├── .state/                  # GITIGNORED — OAuth token (0600), caches, leases. NEVER copy.
    └── .reports/                # GITIGNORED — generated reports
```

## Extending it

- **New sentinel or module?** Add an entry to `manifest.js` `modules` with `match`, `regression_targets`, `contracts`. `doctor` will validate the edges.
- **New recurring bug?** Add a contract to `manifest.js` `contracts` + a check in `contracts/` implementing `{ id, title, blocking, check(cfg, ctx) }`, and register it in `contracts/index.js`. Every past failure becomes a permanent machine-checked guard — that's the whole point.
- **Tune risk weights?** `lib/score.js`.

## Design principle

Big teams don't win on more tests — they win on **knowing precisely what a change
touches** and **making every past failure a permanent guard**. Aegis is the
discipline of writing both down so a machine enforces them on every commit.
