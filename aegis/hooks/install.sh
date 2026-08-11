#!/usr/bin/env bash
# Install Aegis git hooks into one or more repos via core.hooksPath.
# Usage: ./install.sh [repo1] [repo2] ...
# With no args, installs into the ZenXii repos Aegis knows about.
set -euo pipefail

HOOKS_DIR="$(cd "$(dirname "$0")" && pwd)"
chmod +x "$HOOKS_DIR/pre-push"

DEFAULT_REPOS=(
  "/Users/yuggi/AndroidStudioProjects"
  "/Users/yuggi/Desktop/Zennxii_adminPanel"
  "/Users/yuggi/Desktop/project2"
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
