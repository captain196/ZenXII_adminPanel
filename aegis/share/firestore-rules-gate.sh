#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Firestore-rules Blast-Gate  (system rule — global)
# PreToolUse hook for Edit / Write / MultiEdit.
#
# Fires on ANY edit to a Firebase rules file (firestore.rules / storage.rules),
# in ANY project. Rationale: `firebase deploy` ships the ENTIRE working-tree
# rules file — so editing it can silently push another session's uncommitted
# WIP (other modules' match blocks) to production, and one bad line can break
# every module at once.
#
# Before the edit runs it surfaces — into model context AND a user message:
#   1. WORKING-TREE STATE  — is the file already dirty vs HEAD? which match
#      blocks already have pending (uncommitted) changes? + `git diff --stat`.
#      This is the #1 concurrency hazard: a deploy ships ALL of it, not just you.
#   2. MODULE MAP          — every `match` block in the current file, so the
#      edit can be scoped to ONE block without touching others.
#   3. INCOMING-EDIT SCAN  — flags dangerous grants being introduced
#      (`if true`, auth-only writes with no ownership, global `{document=**}`
#      catch-all, broad public read).
#   4. DEEP-CHECKUP CHECKLIST — the pre-deploy discipline: re-read, scope,
#      compare old vs new, prove no module regresses, diff before deploy.
#
# It NEVER blocks the edit — it only informs. Deterministic (git/grep), no LLM.
# Global by design: no project-specific paths; repo-aware via `git -C`.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

AUDIT_LOG="$HOME/.claude/firestore-rules-audit.log"
MAX_BLOCKS=40   # cap match-block list shown

JQ="$(command -v jq || true)"
[ -z "$JQ" ] && exit 0   # can't parse input → allow silently

payload="$(cat)"
tool_name="$(printf '%s' "$payload" | "$JQ" -r '.tool_name // "?"')"
file_path="$(printf '%s' "$payload" | "$JQ" -r '.tool_input.file_path // empty')"
[ -z "$file_path" ] && exit 0

# ── only fire on Firebase rules files (global, any path) ─────────────────────
base="$(basename "$file_path")"
case "$base" in
  firestore.rules|storage.rules) ;;
  *) exit 0 ;;
esac

ts="$(date '+%Y-%m-%d %H:%M:%S')"
printf '%s\t%s\t%s\n' "$ts" "$tool_name" "$file_path" >> "$AUDIT_LOG" 2>/dev/null || true

dir="$(dirname "$file_path")"
gitroot="$(git -C "$dir" rev-parse --show-toplevel 2>/dev/null || true)"

# ── content being introduced by this call (regardless of tool shape) ─────────
newtext="$(printf '%s' "$payload" | "$JQ" -r '
  .tool_input.content
  // .tool_input.new_string
  // ([.tool_input.edits[]?.new_string] | join("\n"))
  // ""')"

# ═════════════════════════════════════════════════════════════════════════════
# 1. WORKING-TREE STATE — what a deploy would actually ship
# ═════════════════════════════════════════════════════════════════════════════
wt_section=""
if [ -n "$gitroot" ]; then
  numstat="$(git -C "$dir" diff --numstat HEAD -- "$file_path" 2>/dev/null || true)"
  if [ -z "$numstat" ]; then
    wt_section="✅ Working tree: \`$base\` matches HEAD — no pending changes. Your edit will be the only new diff (still: check \`git diff\` before deploy)."
  else
    added="$(printf '%s' "$numstat"   | awk '{print $1}')"
    removed="$(printf '%s' "$numstat" | awk '{print $2}')"
    diffstat="$(git -C "$dir" diff --stat HEAD -- "$file_path" 2>/dev/null | grep . || true)"
    # which match blocks already have uncommitted changes (added OR removed lines)
    pending_blocks="$(git -C "$dir" diff HEAD -- "$file_path" 2>/dev/null \
        | grep -E '^[-+].*\bmatch[[:space:]]' \
        | sed -E 's/^([-+])[[:space:]]*/\1 /; s/[[:space:]]*\{[[:space:]]*$//' \
        | sort -u | head -n "$MAX_BLOCKS" || true)"
    wt_section="🔥 Working tree is DIRTY: \`$base\` already has +${added:-?}/-${removed:-?} uncommitted line(s) vs HEAD.
   ⚠️  \`firebase deploy\` ships the WHOLE file — deploying now pushes ALL of the below to prod, not just your edit (may be another session's WIP).

   git diff --stat:
$(printf '%s\n' "$diffstat" | sed 's/^/     /')"
    if [ -n "$pending_blocks" ]; then
      wt_section="$wt_section

   Match blocks already touched in the working tree (− removed / + added lines):
$(printf '%s\n' "$pending_blocks" | sed 's/^/     /')"
    fi
  fi
else
  wt_section="ℹ️  \`$base\` is not inside a git repo — can't compute pending-deploy diff. Verify manually what a deploy would ship."
fi

# ═════════════════════════════════════════════════════════════════════════════
# 1.5 LIVE PRODUCTION STATE — what is ACTUALLY enforcing right now
#
# Git cannot answer this. Teammates deploy from their own machines, so
# production can hold rules that exist in nobody's checkout — and a deploy from
# a stale file reverts them with no error and no log. Section 1 sees only the
# working tree; this reads the deployed ruleset over the Firebase Rules API.
#
# Requires the Aegis Rules Sentinel AND only fires for the exact rules file
# Aegis is configured to watch, so other projects are unaffected. Any failure
# (no node, no creds, offline, timeout) degrades to silence.
# ═════════════════════════════════════════════════════════════════════════════
live_section=""
# Aegis is not always in the same place. $AEGIS_HOME wins; otherwise probe the
# usual spots. A colleague who clones to ~/dev gets the same behaviour without
# editing this file — which matters, because a hook that silently does nothing
# looks identical to a hook that found nothing wrong.
AEGIS_DIR=""
# Aegis lives inside the admin-panel repo (its subject matter is that repo's
# firestore.rules / firestore.indexes.json). The old AndroidStudioProjects path
# is kept as a fallback so an un-migrated checkout still works.
for cand in "${AEGIS_HOME:-}" \
            "$HOME/Desktop/Zennxii_adminPanel/aegis" "$HOME/dev/Zennxii_adminPanel/aegis" \
            "$HOME/projects/Zennxii_adminPanel/aegis" "$HOME/Zennxii_adminPanel/aegis" \
            "$HOME/AndroidStudioProjects/aegis" "$HOME/dev/aegis" \
            "$HOME/projects/aegis" "$HOME/aegis" "$HOME/StudioProjects/aegis"; do
  [ -n "$cand" ] && [ -f "$cand/cli.js" ] && { AEGIS_DIR="$cand"; break; }
done
export AEGIS_DIR   # jq reads it via env.AEGIS_DIR when printing follow-up commands
AEGIS_CLI="$AEGIS_DIR/cli.js"
AEGIS_CFG="$AEGIS_DIR/aegis.config.json"
if [ -n "$AEGIS_DIR" ] && [ -f "$AEGIS_CLI" ] && [ -f "$AEGIS_CFG" ] && command -v node >/dev/null 2>&1; then
  # Resolve the watched path THROUGH Aegis so ${HOME} expansion and the
  # gitignored local override are both honoured — reading the raw JSON here
  # would compare an unexpanded "${HOME}/..." string and never match.
  watched="$(node -e 'try{process.stdout.write(require(process.argv[1]+"/lib/config").load().firestoreRules||"")}catch(e){}' "$AEGIS_DIR" 2>/dev/null || true)"
  if [ -n "$watched" ] && [ "$watched" = "$file_path" ]; then
    aegis_out="$(mktemp)"
    # Own timeout: the hook budget is 15s and a network call must never hang it.
    #
    # The watchdog MUST have its own stdout/stderr. A background job inherits
    # the script's stdout, and the caller reads this hook via command
    # substitution — which blocks until every writer closes the pipe. An
    # un-redirected `sleep` watchdog therefore pinned every invocation to the
    # full timeout even when node finished in 165ms, and truncated the JSON.
    # --no-fetch: reuse whatever remote-tracking refs exist rather than paying a
    # network round-trip inside a 12s budget. Refs older than 15 min are
    # reported as `stale` instead of `fresh`, so a stale board never passes
    # itself off as a current one.
    node "$AEGIS_CLI" rules status --json --no-fetch >"$aegis_out" 2>/dev/null &
    apid=$!
    ( sleep 12; kill -9 "$apid" 2>/dev/null ) >/dev/null 2>&1 &
    wpid=$!
    wait "$apid" 2>/dev/null
    # Kill the subshell AND the `sleep` it spawned, or the orphan lingers.
    pkill -P "$wpid" >/dev/null 2>&1 || true
    kill -9 "$wpid" >/dev/null 2>&1 || true
    wait "$wpid" 2>/dev/null || true

    if [ -s "$aegis_out" ]; then
      live_section="$("$JQ" -r '
        if (.liveAvailable | not) then
          "⚠️  LIVE rules could NOT be read (\(.liveMeta.reason // "offline")) — drift vs production is UNKNOWN this run. Do not assume disk == prod."
        else
          (
            "🌐 LIVE ruleset \(.liveMeta.rulesetId // "?") — deployed \(.liveMeta.createTime // "?")",
            "   \(.summary.clean) clean · \(.summary.mine) yours · \(.summary.undeployed) undeployed · \(.summary.theirs) THEIRS · \(.summary.live_uncommitted) live-only · \(.summary.conflict) CONFLICT",
            (if .summary.conflict > 0 then
              "   🚨 CONFLICT — you have edits in a block that ALSO drifted in prod. Deploying REVERTS their work:",
              (.blocks[] | select(.status=="conflict") | "        • \(.collection // "?") L\(.line)  [\((.modules // []) | join(", "))]")
             else empty end),
            (if .summary.theirs > 0 then
              "   ⇣ THEIRS — prod has blocks your branch never saw. Pull them in BEFORE deploying:",
              (.blocks[] | select(.status=="theirs") | "        • \(.collection // "?") L\(.line)  [\((.modules // []) | join(", "))]")
             else empty end),
            (if .summary.live_uncommitted > 0 then
              "   ⚑ LIVE-ONLY — deployed but in NO commit; a deploy from a clean checkout wipes it:",
              (.blocks[] | select(.status=="live_uncommitted") | "        • \(.collection // "?") L\(.line)")
             else empty end),
            (if (.summary.behind // 0) > 0 then
              "   ⇊ BEHIND — a teammate PUSHED these to git and you have not pulled. Live looks fine, your file is older:",
              (.blocks[] | select(.status=="behind") | "        • \(.collection // "?") L\(.line)  [\((.modules // []) | join(", "))]")
             else empty end),
            (if (.summary.pullConflict // 0) > 0 then
              "   ⇊✖ PULL CONFLICT — you edited \(.summary.pullConflict) block(s) the remote also changed; `git pull` will conflict here."
             else empty end),
            (if .git then
              (if .git.verdict.level == "block" then
                 "   🚫 GIT: \(.git.verdict.headline)",
                 "        → \(.git.verdict.remedy // "pull first")"
               elif .git.verdict.level == "unknown" then
                 "   ❔ GIT: \(.git.verdict.headline) — do NOT assume your branch is current."
               else
                 "   🌿 git: \(.git.branch // "?") → \(.git.upstream // "no upstream")  (\(.git.ahead // 0) ahead, \(.git.behind // 0) behind)"
               end)
             else empty end),
            ((.leases // [])[] | "   🔒 leased by \(.session): \((.targets // []) | join(", "))"),
            "   → full board:  node \(env.AEGIS_DIR // "<aegis>")/cli.js rules status",
            "   → before ANY deploy or push:  node \(env.AEGIS_DIR // "<aegis>")/cli.js rules preflight"
          )
        end' "$aegis_out" 2>/dev/null || true)"
    fi
    rm -f "$aegis_out"
  fi
fi

# ═════════════════════════════════════════════════════════════════════════════
# 2. MODULE MAP — every match block in the CURRENT (pre-edit) file
# ═════════════════════════════════════════════════════════════════════════════
map_section=""
if [ -f "$file_path" ]; then
  blocks="$(grep -nE '^[[:space:]]*match[[:space:]]' "$file_path" 2>/dev/null \
      | sed -E 's/[[:space:]]*\{[[:space:]]*$//' | head -n "$MAX_BLOCKS" || true)"
  nblocks="$(printf '%s\n' "$blocks" | grep -c . 2>/dev/null || true)"
  [ -z "$nblocks" ] && nblocks=0
  if [ "$nblocks" -gt 0 ]; then
    map_section="🗺️  ${nblocks} match block(s) in this file (line: path) — scope your edit to ONE, leave the rest byte-for-byte unchanged:
$(printf '%s\n' "$blocks" | sed 's/^/     /')"
  fi
fi

# ═════════════════════════════════════════════════════════════════════════════
# 3. INCOMING-EDIT SCAN — dangerous grants being introduced by this edit
# ═════════════════════════════════════════════════════════════════════════════
risk_section=""
if [ -n "$newtext" ]; then
  findings=""
  add() { findings="${findings}${findings:+
}     • $1"; }

  printf '%s' "$newtext" | grep -Eq ':[[:space:]]*if[[:space:]]+true\b' \
    && add "🔴 CRITICAL: introduces \`if true\` — a world-open rule (anyone can read/write). Almost never correct in prod."
  printf '%s' "$newtext" | grep -Eq 'match[[:space:]]+/\{[A-Za-z_]*=\*\*\}' \
    && add "🔴 CRITICAL: touches a global \`{document=**}\` catch-all — this affects EVERY collection/module, not one. Confirm this is intended."
  printf '%s' "$newtext" | grep -Eq 'allow[[:space:]]+read[[:space:]]*:[[:space:]]*if[[:space:]]+true' \
    && add "🟠 HIGH: \`allow read: if true\` — public read of this collection. Confirm no tenant/PII data is exposed."
  printf '%s' "$newtext" | grep -Eq 'allow[[:space:]]+(write|create|update)[^;]*:[[:space:]]*if[[:space:]]+request\.auth[[:space:]]*!=[[:space:]]*null[[:space:]]*;' \
    && add "🟠 HIGH: auth-only write (\`if request.auth != null\`) with no ownership/role check — any signed-in user can write. Add a uid/school_id/role predicate."
  printf '%s' "$newtext" | grep -Eq 'allow[[:space:]]+read[[:space:]]*,[[:space:]]*write' \
    && add "🟡 NOTE: combined \`allow read, write\` — split read vs write conditions; they rarely need identical predicates."

  if [ -n "$findings" ]; then
    risk_section="🔎 Incoming-edit risk scan:
$findings"
  fi
fi

# ═════════════════════════════════════════════════════════════════════════════
# 4. DEEP-CHECKUP CHECKLIST — pre-deploy discipline
# ═════════════════════════════════════════════════════════════════════════════
checklist="🧪 Deep checkup before you edit & before you deploy:
     1. RE-READ the current file first — it may have changed since you last saw it (concurrent sessions).
     2. SCOPE to a single match block; do not reformat or touch other modules' blocks.
     3. COMPARE old vs new: for the block you change, will any previously-allowed legitimate access now be DENIED? Will any new access be wrongly ALLOWED? Walk every operation (get/list/read, create/update/delete/write).
     4. EVERY CASE / EVERY MODULE: check the change doesn't regress other collections that share helper functions or the same document path — one broken predicate can PERMISSION_DENIED a whole module.
     5. CLAIM CONTRACT: rules read snake_case claims (school_id) — confirm the field names match what the app mints.
     6. DIFF BEFORE DEPLOY: run \`git diff HEAD -- $base\` and confirm ONLY your intended block changed — deploy ships the whole file, so any stray/other-session hunk goes to prod too.
     7. VALIDATE: dry-run/lint the rules (firebase deploy --only firestore:rules after a compile check) rather than blind-deploying."

# ═════════════════════════════════════════════════════════════════════════════
# assemble
# ═════════════════════════════════════════════════════════════════════════════
detail="🔥 Firestore-rules blast-gate — $base"
[ -n "$wt_section" ]   && detail="$detail

$wt_section"
[ -n "$live_section" ] && detail="$detail

$live_section"
[ -n "$map_section" ]  && detail="$detail

$map_section"
[ -n "$risk_section" ] && detail="$detail

$risk_section"
detail="$detail

$checklist"

# short one-line summary for the user
# Live drift outranks working-tree dirtiness: a dirty tree is your own mess,
# but a CONFLICT/THEIRS block means a deploy destroys someone else's live work.
if printf '%s' "$live_section" | grep -q 'CONFLICT —'; then
  summary="🚨 blast-gate: prod has drifted in a block you're editing — deploying would REVERT a teammate's live rules. Run \`aegis rules status\`."
elif printf '%s' "$live_section" | grep -q 'THEIRS —'; then
  summary="⇣ blast-gate: production has rules blocks your branch never saw — pull them in before any deploy (\`aegis rules plan\`)."
elif printf '%s' "$wt_section" | grep -q 'DIRTY'; then
  summary="🔥 blast-gate: \`$base\` working tree is DIRTY — a deploy ships ALL pending changes, not just this edit. Diff before deploying."
elif [ -n "$risk_section" ]; then
  summary="🔥 blast-gate: \`$base\` edit introduces a permissive/broad rule — see risk scan; scope to one block & diff before deploy."
else
  summary="🔥 blast-gate: editing \`$base\` — scope to one match block, compare old vs new, diff before deploy (deploy ships the whole file)."
fi

ctx="$(printf '%s' "$detail" | "$JQ" -Rs .)"
msg="$(printf '%s' "$summary" | "$JQ" -Rs .)"
printf '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":%s},"systemMessage":%s,"suppressOutput":true}\n' "$ctx" "$msg"
exit 0
