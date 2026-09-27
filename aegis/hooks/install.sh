#!/usr/bin/env bash
# Install Aegis git hooks into one or more repos via core.hooksPath.
# Usage: ./install.sh [repo1] [repo2] ...
# With no args, installs into the ZenXii repos Aegis knows about.
set -euo pipefail

HOOKS_DIR="$(cd "$(dirname "$0")" && pwd)"
chmod +x "$HOOKS_DIR/pre-push"

# Derive the repo list from the surface map rather than hardcoding one
# machine's absolute paths — aegis.config.local.json and $AEGIS_* overrides are
# honoured automatically, so this works on a colleague's checkout unchanged.
AEGIS_DIR="$(cd "$HOOKS_DIR/.." && pwd)"
DEFAULT_REPOS=()
while IFS= read -r r; do [ -n "$r" ] && DEFAULT_REPOS+=("$r"); done < <(
  node -e '
    const c = require(process.argv[1] + "/lib/config").load();
    const seen = new Set();
    for (const s of Object.values(c.surfaces || {}))
      if (s.gitRepo && !seen.has(s.gitRepo)) { seen.add(s.gitRepo); console.log(s.gitRepo); }
  ' "$AEGIS_DIR" 2>/dev/null || true
)
REPOS=("$@")
[ ${#REPOS[@]} -eq 0 ] && REPOS=("${DEFAULT_REPOS[@]}")

for repo in "${REPOS[@]}"; do
  if [ ! -d "$repo/.git" ]; then
    echo "skip (not a git repo): $repo"; continue
  fi
  # Use a per-repo hooks dir that chains to Aegis so we don't clobber existing hooks.
  target="$repo/.git/hooks/pre-push"
  cat > "$target" <<EOF
#!/usr/bin/env bash
exec "$HOOKS_DIR/pre-push" "\$@"
EOF
  chmod +x "$target"
  echo "installed pre-push → $repo"
done

echo ""
echo "Done. Aegis will run on every 'git push'. Set AEGIS_STRICT=1 to make blocking contracts hard-stop."
