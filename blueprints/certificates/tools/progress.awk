# Progress counter for EXECUTION_PLAN_v1.1.md
#
#   awk -f blueprints/certificates/tools/progress.awk blueprints/certificates/EXECUTION_PLAN_v1.1.md | sort
#
# WHY THIS IS A FILE AND NOT A ONE-LINER TYPED EACH TIME: the obvious version
# counts any row CONTAINING a checkmark, which over-counts. A blocked task's
# EVIDENCE column legitimately says "✅ E2E P5 at least proves the list is
# flagged" — the evidence is real, the task is not done. That inflated Phase 5
# from 4/6 to 5/6 until it was caught. Only a checkmark that OPENS the task
# cell counts.
#
# Progress on this module is COMPUTED, never estimated.

/^## [0-9]+\. Phase/ { ph=$0; gsub(/^## [0-9]+\. /,"",ph); gsub(/ \*.*$/,"",ph) }
/^\| P[0-9]+\.[0-9a-z]+ \|/ {
  # field 2 = task id, field 3 = the TASK cell. Only a ✅ that OPENS the task
  # cell counts — a ✅ in the evidence column can belong to a blocked task.
  split($0, f, "|");
  task = f[3];
  gsub(/^[ \t]+/, "", task);
  done = (task ~ /^✅/);
  c[ph]++; t++;
  if (done) { d[ph]++; td++ }
}
END{ for (p in c) printf "  %-30s %d/%d\n", p, d[p]+0, c[p];
     printf "\n  TOTAL %d/%d = %.0f%%\n", td, t, td*100/t }
