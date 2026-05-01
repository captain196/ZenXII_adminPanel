#!/usr/bin/env bash
# rtdb_audit.sh — Scan a module for residual RTDB / CodeIgniter-model data access.
#
# Usage:
#   bash scripts/rtdb_audit.sh               → scan the Fees/Accounting modules
#   bash scripts/rtdb_audit.sh fees          → same
#   bash scripts/rtdb_audit.sh all           → every PHP file under application/
#   bash scripts/rtdb_audit.sh <keyword>     → files matching *<keyword>*.php
#
# Output sections:
#   ⚠️  WRITES     — critical. Firestore-only code MUST have zero of these.
#   ℹ️  READS      — review. Intentional in verify/debug methods; suspicious elsewhere.
#   (Methods named verify_session_*, void_test_receipt, verify_test_cleanup,
#    debug_carry_forward, drainRetryQueue, and bulk/backfill scripts are
#    excluded by name because they intentionally touch RTDB.)

set -e
cd "$(dirname "$0")/.."

MODULE="${1:-fees}"

case "$MODULE" in
  fees)
    FILES="application/controllers/Fees.php
application/controllers/Fee_management.php
application/libraries/Fee_firestore_txn.php
application/libraries/Fee_refund_service.php
application/libraries/Operations_accounting.php
application/libraries/Accounting_firestore_sync.php" ;;
  all)
    FILES=$(find application/controllers application/libraries -name "*.php" 2>/dev/null) ;;
  *)
    FILES=$(find application -iname "*${MODULE}*.php" 2>/dev/null) ;;
esac

WRITE_PAT='firebase->(set|update|delete|push)[(]'
READ_PAT='firebase->(get|shallow_get)[(]|CM->get_data[(]'
SKIP_METHODS='verify_session_|void_test_receipt|verify_test_cleanup|debug_carry_forward|drainRetryQueue'

echo "═══════════════════════════════════════════════════════════"
echo "  RTDB LEAK AUDIT — module: $MODULE"
echo "═══════════════════════════════════════════════════════════"

total_w=0 ; total_r=0

for f in $FILES; do
  [ -f "$f" ] || continue

  report=$(awk -v WP="$WRITE_PAT" -v RP="$READ_PAT" -v SK="$SKIP_METHODS" '
    /^[[:space:]]*(public|private|protected)?[[:space:]]*function[[:space:]]+[a-zA-Z_]/ {
      match($0, /function[[:space:]]+[a-zA-Z_][a-zA-Z0-9_]*/)
      method = substr($0, RSTART+9, RLENGTH-9)
    }
    /^[[:space:]]*(\/\/|\*|#)/ { next }        # skip comments
    {
      if (method ~ SK) next
      if ($0 ~ WP) print "W\t" NR "\t" method "\t" $0
      else if ($0 ~ RP) print "R\t" NR "\t" method "\t" $0
    }
  ' "$f")

  [ -z "$report" ] && continue

  wlines=$(echo "$report" | grep -c "^W" || true)
  rlines=$(echo "$report" | grep -c "^R" || true)
  echo
  echo "──── $f  (W:$wlines  R:$rlines) ────"

  if [ "$wlines" -gt 0 ]; then
    echo "  ⚠️  WRITES:"
    echo "$report" | awk -F'\t' '$1=="W" {printf "    L%-5s [%s]  %s\n", $2, $3, $4}'
  fi
  if [ "$rlines" -gt 0 ]; then
    echo "  ℹ️  READS:"
    echo "$report" | awk -F'\t' '$1=="R" {printf "    L%-5s [%s]  %s\n", $2, $3, $4}'
  fi

  total_w=$((total_w + wlines))
  total_r=$((total_r + rlines))
done

echo
echo "═══════════════════════════════════════════════════════════"
echo "  SUMMARY  →  WRITES: $total_w   READS: $total_r"
echo "═══════════════════════════════════════════════════════════"
[ "$total_w" -gt 0 ] && echo "❌ RTDB writes present — module is NOT pure Firestore." && exit 1
[ "$total_r" -gt 0 ] && echo "⚠️  RTDB reads present — review each one before deploying."
[ "$total_w" -eq 0 ] && [ "$total_r" -eq 0 ] && echo "✅ No RTDB access detected."
exit 0
