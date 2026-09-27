'use strict';
/**
 * Judgement layer: given a block that changed, how dangerous is the change?
 *
 * A diff tells you *that* something moved. This tells you whether to care. The
 * heuristics are tuned to the bug classes this codebase has actually paid for
 * (see BUG_LEDGER.md and the module notes in manifest.js) rather than to
 * generic rules linting:
 *
 *   - world-open blocks (`attendanceEventsFired` shipped world-open once)
 *   - a write that isn't tenant-scoped (`school_id` is the tenant boundary and
 *     rules are the ONLY real boundary — ModuleGate.kt fails open by design)
 *   - a `hasOnly([...])` field allowlist getting wider, which is how a narrow
 *     self-service write turns into privilege escalation
 *   - helper edits, which silently re-point every block that calls them
 *
 * Severity: critical > high > medium > low > info.
 */

const TENANT_GATE = /\b(isSameSchool|isSameSchoolStrict|isSameSchoolWrite|isSuperAdmin|schoolId|school_id)\b/;
const AUTH_GATE = /\b(request\.auth|isAuth|isAdmin|isStaff|isOwnStudent|isLinkedChild|isStaffOrOwnStudent|hasCapability)\b/;
const WRITE_OPS = /\b(write|create|update|delete)\b/;

const SEV_ORDER = { critical: 4, high: 3, medium: 2, low: 1, info: 0 };
const worst = (a, b) => (SEV_ORDER[a] >= SEV_ORDER[b] ? a : b);

/** Field names inside every `hasOnly([...])` in a condition. */
function hasOnlyFields(cond) {
  const out = [];
  const re = /hasOnly\s*\(\s*\[([^\]]*)\]/g;
  let m;
  while ((m = re.exec(cond)) !== null) {
    for (const f of m[1].split(',')) {
      const t = f.trim().replace(/^['"]|['"]$/g, '');
      if (t) out.push(t);
    }
  }
  return out;
}

/**
 * A condition plus the bodies of every helper it transitively calls.
 *
 * Without this the analysis is worse than useless — it reports `allow write: if
 * isAdmin()` as ungated and untenanted, when isAdmin() checks both. Flagging
 * the codebase's own correct idiom as high-risk is exactly how a security tool
 * gets muted.
 */
function effectiveText(cond, helpers, seen = new Set()) {
  let text = cond;
  if (!helpers || !helpers.size) return text;
  const re = /\b([A-Za-z_]\w*)\s*\(/g;
  let m;
  while ((m = re.exec(cond)) !== null) {
    const name = m[1];
    if (seen.has(name) || !helpers.has(name)) continue;
    seen.add(name);
    text += ' ' + effectiveText(helpers.get(name), helpers, seen);
  }
  return text;
}

/** name → body text, from a parsed document. */
function helperMap(parsed) {
  const m = new Map();
  for (const f of (parsed && parsed.functions) || []) m.set(f.name, f.body || '');
  return m;
}

/** Static risk of a single allow statement, ignoring history. */
function scoreAllow(allow, helpers) {
  const { ops, cond } = allow;
  const findings = [];
  const isWrite = WRITE_OPS.test(ops);

  if (cond === 'true') {
    findings.push({ severity: 'critical', code: 'world-open',
      msg: `allow ${ops}: if true — grants public access across every tenant.` });
    return findings;
  }
  if (cond === 'false') return findings; // explicit deny is the safest rule there is

  const eff = effectiveText(cond, helpers);
  if (!AUTH_GATE.test(eff)) {
    findings.push({ severity: 'high', code: 'no-auth-gate',
      msg: `allow ${ops} has no auth predicate, directly or via any helper it calls.` });
  }
  if (!TENANT_GATE.test(eff)) {
    findings.push({ severity: isWrite ? 'high' : 'medium', code: 'no-tenant-scope',
      msg: `allow ${ops} is not tenant-scoped (no school_id / isSameSchool*). Rules are the only real tenant boundary.` });
  }
  return findings;
}

/**
 * Risk of a block CHANGE — compares the new allows against the old ones, which
 * catches widening that a static scan of the new state alone cannot see.
 */
function scoreChange(before, after, helpers) {
  const findings = [];
  const oldAllows = (before && before.allows) || [];
  const newAllows = (after && after.allows) || [];

  for (const a of newAllows) findings.push(...scoreAllow(a, helpers));

  // Field-allowlist widening: the classic quiet privilege escalation.
  const oldFields = new Set(oldAllows.flatMap(a => hasOnlyFields(a.cond)));
  const newFields = newAllows.flatMap(a => hasOnlyFields(a.cond));
  const added = [...new Set(newFields.filter(f => !oldFields.has(f)))];
  if (before && added.length && oldFields.size) {
    findings.push({ severity: 'high', code: 'allowlist-widened',
      msg: `writable field allowlist gained: ${added.join(', ')} — confirm each is genuinely self-service.` });
  }

  // An explicit deny disappearing is almost never intentional cleanup.
  const hadDeny = oldAllows.some(a => a.cond === 'false');
  const hasDeny = newAllows.some(a => a.cond === 'false');
  if (hadDeny && !hasDeny) {
    findings.push({ severity: 'high', code: 'deny-removed',
      msg: `an explicit \`if false\` deny was removed from this block.` });
  }

  // More grants than before on the same block.
  if (before && newAllows.length > oldAllows.length) {
    findings.push({ severity: 'medium', code: 'grants-added',
      msg: `allow statements went ${oldAllows.length} → ${newAllows.length}.` });
  }
  if (before && newAllows.length < oldAllows.length) {
    findings.push({ severity: 'medium', code: 'grants-removed',
      msg: `allow statements went ${oldAllows.length} → ${newAllows.length} — verify nothing legitimate now 403s.` });
  }
  return findings;
}

/** Highest severity across findings, or 'info'. */
function overall(findings) {
  return findings.reduce((acc, f) => worst(acc, f.severity), 'info');
}

/**
 * Blast radius of a helper change: every block whose conditions call it.
 * A one-line edit to isSameSchool() re-points ~128 blocks at once, which is
 * the single highest-leverage change anyone can make to this file.
 */
function helperCallers(parsed, fnName) {
  const re = new RegExp(`\\b${fnName}\\s*\\(`);
  return parsed.blocks
    .filter(b => b.allows.some(a => re.test(a.cond)))
    .map(b => ({ id: b.key, collection: b.collection, line: b.start }));
}

module.exports = { scoreAllow, scoreChange, overall, helperCallers, helperMap, effectiveText, hasOnlyFields, SEV_ORDER, worst };
