# Giving Aegis to a colleague

Aegis observes five repos it does not contain, reads the **deployed** Firestore
ruleset and index list, and reads your **git remote**. All of those are
per-machine facts,
so a clone needs ten minutes of setup — and one credential decision that is
worth making deliberately.

The short version:

```bash
git clone <this-repo> && cd aegis
node cli.js setup        # detects repos, writes the local override, installs agent + hook
node cli.js doctor       # every line must be ✓
node cli.js rules status   # must print a live ruleset id AND a branch-sync verdict
node cli.js indexes status # must print a live index count
```

---

## 1. What to send, and what never to send

**Send:** the repo. That is it. It has no dependencies (`node >= 18`, zero npm
packages) and now carries its own agent and hook in `share/`.

**Never send:**

| File | Why |
|---|---|
| `aegis.config.local.json` | their machine's paths, not yours — it is gitignored for this reason |
| `.state/` | your OAuth token, your fetch stamps, your leases, your ruleset cache |
| `.reports/` | your generated output |
| the Firebase service-account JSON | a credential — see §3 |

If you send a zip rather than a clone, delete `.state/` from it first. It
contains a cached Google access token.

---

## 2. Setup on their machine

`node cli.js setup` probes the usual locations (`~/Desktop`, `~/dev`,
`~/projects`, `~/AndroidStudioProjects`, `~/StudioProjects`, `$HOME`) and
fingerprints each hit — a directory only counts as the admin panel if it
actually contains `application/controllers` and `firebase-rules`. It then writes
**only the paths that differ** into `aegis.config.local.json`, which is
gitignored.

That last detail matters more than it looks: if everyone committed their own
absolute paths, `aegis.config.json` would produce a merge conflict on every
single pull.

If a repo lives somewhere unusual, three ways to fix it, in increasing
precedence:

```bash
# 1. edit the local override by hand (copy aegis.config.example.json for the shape)
vim aegis.config.local.json

# 2. environment variables, no file needed
export AEGIS_ADMIN_ROOT=~/work/Zennxii_adminPanel
export AEGIS_FIREBASE_SA=~/.config/aegis/sa.json

# 3. a completely separate base config
export AEGIS_CONFIG=/path/to/their-aegis.config.json
```

Paths accept `~`, `${HOME}` and `${AEGIS_ROOT}`. `node cli.js doctor` prints
which layers actually loaded, so a wrong path is one command to diagnose.

---

## 3. The Firebase credential — the one real decision

Reading the **live** ruleset requires a Google credential. Without it Aegis
still works, but only on the git axis, and every command says so rather than
pretending production is clean. That degraded mode misses the exact class of bug
the tool was built for, so it is worth doing properly.

**Do not copy your admin SDK key around.** It is a full-privilege key to the
production project; the live-rules read needs almost none of that.

Give each colleague their own read-only service account instead:

Aegis reads **two** different Google APIs, and they need **two** roles. Rules
come from the Firebase Rules API; composite indexes come from the Firestore
Admin API under a different OAuth scope. Grant only the first and
`aegis indexes` reports `forbidden` while `aegis rules` works fine — a confusing
half-working state, so bind both:

```bash
gcloud iam service-accounts create aegis-viewer --project graderadmin

# rules  — read deployed rulesets
gcloud projects add-iam-policy-binding graderadmin \
  --member="serviceAccount:aegis-viewer@graderadmin.iam.gserviceaccount.com" \
  --role="roles/firebaserules.viewer"

# indexes — list composite indexes (Firestore Admin API)
gcloud projects add-iam-policy-binding graderadmin \
  --member="serviceAccount:aegis-viewer@graderadmin.iam.gserviceaccount.com" \
  --role="roles/datastore.viewer"

gcloud iam service-accounts keys create ~/.config/aegis/aegis-viewer.json \
  --iam-account=aegis-viewer@graderadmin.iam.gserviceaccount.com
```

`roles/firebaserules.viewer` reads rulesets and nothing else. `roles/datastore.viewer`
is read-only on Firestore — it cannot create, delete or deploy indexes, and
cannot write data. Neither can touch Auth. A leaked key of that shape is an
incident you can shrug at.

> ⚠️ `roles/datastore.viewer` **can read Firestore documents**, which is more
> than index metadata. If that is too broad for your team, a custom role with
> only `datastore.indexes.list` and `datastore.indexes.get` is exactly enough
> for `aegis indexes`.

Then point Aegis at it:

```bash
export AEGIS_FIREBASE_SA=~/.config/aegis/aegis-viewer.json
```

Aegis stores only the **path**. It never copies, prints or transmits the key
anywhere except Google's token endpoint.

---

## 4. Will this disturb anyone's work?

No — and the reasons are structural rather than a promise:

- **Aegis never writes to production.** It has no deploy path. Live access is
  read-only against `firebaserules.googleapis.com` (rules) and
  `firestore.googleapis.com` (index metadata) — it cannot create, delete or
  deploy either.
- **Aegis never runs git for you.** No pull, no rebase, no commit, no push. The
  only git command that changes anything on disk is `git fetch`, which updates
  remote-tracking refs and cannot touch your working tree, your branch or your
  stash.
- **Aegis never edits your `settings.json`.** `setup` prints the hook snippet
  and lets you paste it.
- **`setup` will not overwrite an existing agent or hook** unless you pass
  `--force`.
- **The hook only fires on the exact rules file that machine is configured to
  watch**, and is silent everywhere else. Other projects are unaffected.
- **The hook never blocks an edit.** It only injects context.

The one shared resource is the Firestore ruleset itself, and Aegis only ever
*reads* it.

---

## 5. What multi-person detection can and cannot do

Be precise about this with your team, because the gaps are not obvious.

| Situation | Detected? | How |
|---|---|---|
| Colleague **deployed** rules from their machine | ✅ yes | live ruleset vs your HEAD → `theirs` |
| Colleague **created an index** that is in no commit | ✅ yes | live index list vs git → `live_only` |
| A committed index was never deployed | ✅ yes | → `undeployed` (its query is failing now) |
| Colleague **deployed without committing** | ✅ yes | → `live_uncommitted` (production is the only copy) |
| Colleague **committed + pushed**, nobody deployed | ✅ yes | remote vs HEAD → `behind` |
| You edited a block they also pushed | ✅ yes | `pullConflict` — the pull will conflict |
| A shared helper changed anywhere | ✅ yes | reported separately, with the count of calling blocks |
| Another **Claude tab on the same machine** in your block | ⚠️ advisory | `rules claim` / `rules who` — visible, not enforced |
| Colleague's **uncommitted local edit** | ❌ no | it exists only on their disk; nothing can see it |
| Colleague **about to** deploy | ❌ no | there is no lock on the Firebase side |

The leases in `.state/rules-locks.json` are **per-machine** — they do not
travel. That is deliberate: a shared lock file would need a commit round-trip to
claim a block, which nobody would do. Cross-machine safety comes from the live
and remote comparisons instead, which is why they are the load-bearing part.

---

## 6. The protocol worth agreeing on as a team

1. **`rules preflight` before any rules deploy or push.** It fetches, decides
   whether you are behind *in that file specifically*, and prints the order.
2. **Commit and push before you deploy, never after.** Deploying first is
   exactly what creates `live_uncommitted` blocks, where production is the only
   copy and the next clean checkout silently reverts it. The 47 such blocks
   currently live in `graderadmin` all came from that ordering.
3. **`rules status` right before editing**, not once at the start of the day.
4. **Announce a deploy** in whatever channel the team uses. Nothing technical
   can substitute — Firebase has no deploy lock.
5. **`rules sync` after deploying**, so the ledger records the new ruleset and
   the next person's "production moved since you last looked" is accurate.

---

## 7. Onboarding checklist

```
[ ] git clone; node cli.js setup
[ ] own read-only SA key created; AEGIS_FIREBASE_SA exported in their shell rc
[ ] node cli.js doctor            → all ✓, including "firebase SA (live)"
[ ] node cli.js rules status      → prints a live ruleset id
                                  → prints a branch-sync verdict, not "REMOTE not read"
[ ] node cli.js rules preflight   → prints an ordered plan
[ ] node cli.js indexes status    → prints a live index count, not "forbidden"
                                  → if forbidden: roles/datastore.viewer is missing
[ ] npm test                      → all assertions pass
[ ] hook wired in ~/.claude/settings.json (setup prints the snippet)
[ ] editing firestore.rules in Claude Code shows the 🌐 LIVE + 🌿 git sections
```

If `rules status` says **"REMOTE not read"**, the branch has no upstream — the
git axis is dark until they set one:

```bash
git -C <admin-repo> branch --set-upstream-to=origin/<branch>
```
