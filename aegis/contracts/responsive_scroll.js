'use strict';
const { read, matches, finding, rollup } = require('./_util');

/**
 * L9 — Compose dialogs/sheets/forms must scroll & fit small + landscape screens.
 * Heuristic (warn-only): a changed Kotlin file that declares a Dialog/AlertDialog/
 * ModalBottomSheet whose body lacks any scroll container is a clipping risk —
 * the recurring Teacher/Parent bug on small + landscape.
 */
const DIALOG = /\b(AlertDialog|BasicAlertDialog|Dialog|ModalBottomSheet|BottomSheet|DialogFragment)\s*\(/;
const SCROLL = /verticalScroll|rememberScrollState|LazyColumn|LazyVerticalGrid|scrollable|imePadding/;

module.exports = {
  id: 'responsive_scroll',
  title: 'Compose dialogs scroll & fit small/landscape',
  blocking: false,
  check(cfg, ctx) {
    const changed = (ctx && ctx.changed) || [];
    const kt = changed.filter(f => f.path.endsWith('.kt') && f.kind !== 'test');
    if (!kt.length) return { id: this.id, status: 'skip', findings: [], note: 'no Kotlin UI files changed' };

    const findings = [];
    let dialogs = 0;
    for (const f of kt) {
      const t = read(f.path);
      if (!DIALOG.test(t)) continue;
      dialogs++;
      if (!SCROLL.test(t)) {
        const ln = firstLine(t, DIALOG);
        findings.push(finding('warn', f.path, ln,
          `dialog/sheet with no scroll container (verticalScroll / LazyColumn) — will clip on small + landscape. Add scroll + height cap + sticky footer.`));
      }
    }

    if (!dialogs) return { id: this.id, status: 'skip', findings, note: 'no dialogs/sheets in change set' };
    return { id: this.id, status: rollup(findings), findings, note: `checked ${dialogs} dialog/sheet file(s)` };
  }
};

function firstLine(text, re) {
  for (const { line } of matches(text, re)) return line;
  return 0;
}
