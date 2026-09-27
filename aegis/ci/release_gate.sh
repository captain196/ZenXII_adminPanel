#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# ZenXii Aegis — Release Gate (Layer 18)
#
# Run this BEFORE any deploy (admin push→pull, firebase deploy, Play upload).
# It hard-stops (exit 1) only on a BLOCKING contract failure — the one place
# Aegis blocks. Everything else is advisory.
#
# Usage:
#   ci/release_gate.sh                       # gate the current repo vs origin/main
#   ci/release_gate.sh --repo <path> --base <ref>
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

AEGIS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO="${AEGIS_REPO:-$(git rev-parse --show-toplevel 2>/dev/null || echo "$PWD")}"

echo "════════════════════════════════════════════════════════════"
echo "  ZenXii Aegis — Release Gate"
echo "  repo: $REPO"
echo "════════════════════════════════════════════════════════════"

# Pass through any explicit --repo/--base/--files args; else default to REPO.
if [ "$#" -gt 0 ]; then
  node "$AEGIS_DIR/cli.js" gate "$@"
else
  node "$AEGIS_DIR/cli.js" gate --repo "$REPO"
fi
code=$?

echo ""
if [ $code -ne 0 ]; then
  echo "⛔ RELEASE BLOCKED — do not deploy. Fix blocking contracts and re-run."
else
  echo "✓ Release gate passed. Remember the deploy runbook gotchas:"
  echo "  • firestore.rules ships the WHOLE file — diff vs origin first"
  echo "  • indexes deploy separately + build async — deploy BEFORE dependent code"
  echo "  • admin panel: core.fileMode=false, skip-worktree .htaccess/.env"
fi
exit $code
