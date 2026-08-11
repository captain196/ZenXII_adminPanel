#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# ZenXii Aegis — Local CI (the runnable-TODAY equivalent of the GitHub gate)
#
# You deploy via Lightsail push→pull, not GitHub Actions — so this is your CI.
# Run it before any deploy. Same pipeline the GitHub workflow runs:
#   self-test → doctor → impact → gate   (per repo)
#
# Usage:
#   ci/local_ci.sh                      # gate all ZenXii repos vs origin/main
#   ci/local_ci.sh /path/to/repo ...    # gate specific repos
#   STRICT=1 ci/local_ci.sh             # non-zero exit on WARN too (not just BLOCK)
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail
AEGIS_DIR="$(cd "$(dirname "$0")/.." && pwd)"

REPOS=("$@")
if [ ${#REPOS[@]} -eq 0 ]; then
  REPOS=(
    "/Users/yuggi/Desktop/Zennxii_adminPanel"
    "/Users/yuggi/AndroidStudioProjects"
    "/Users/yuggi/Desktop/project2"
  )
fi

bold() { printf "\033[1m%s\033[0m\n" "$1"; }
line() { printf "════════════════════════════════════════════════════════════\n"; }

fail=0

line; bold "  Aegis Local CI — self-test"; line
if ! node "$AEGIS_DIR/test/run.js"; then
  echo "✗ self-test failed — the guardian itself is broken. Stop."; exit 2
fi

echo; line; bold "  Aegis Local CI — doctor"; line
node "$AEGIS_DIR/cli.js" doctor || { echo "✗ doctor failed — fix config paths."; exit 2; }

for repo in "${REPOS[@]}"; do
  [ -d "$repo/.git" ] || { echo "skip (not a git repo): $repo"; continue; }
  echo; line; bold "  Gate: $repo"; line
  node "$AEGIS_DIR/cli.js" gate --repo "$repo"
  code=$?
  if [ $code -ne 0 ]; then
    fail=1
    echo "⛔ $repo — BLOCKED"
  fi
done

echo; line
if [ $fail -ne 0 ]; then
  bold "  ⛔ Local CI: at least one repo is BLOCKED — do not deploy."
  exit 1
fi
bold "  ✓ Local CI: all repos passed the gate."
line
exit 0
