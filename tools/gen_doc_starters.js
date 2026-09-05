/**
 * Generate the SERVER's copy of the standard starter templates from the client's.
 *
 * The starters are authored in `assets/js/doctemplates/designer.js` and, until now,
 * existed nowhere else — so a school only ever got a certificate when a human opened
 * an unlinked URL and clicked a card. Seeding them needs the definitions server-side.
 *
 * Hand-copying them into PHP would create a fourth copy of the same truth
 * (config + client contract + service + this), and `_patterns.md` names
 * sibling-path parity drift as the highest-leverage defect shape in this codebase —
 * it has already produced three instances in this module alone.
 *
 * So the client stays the single AUTHORED source and this generates the rest.
 * `DocStarterParityTest` regenerates and diffs, so the two cannot drift silently:
 * edit a starter, forget to regenerate, and the suite fails.
 *
 *   node tools/gen_doc_starters.js            # write application/config/doc_starters.php
 *   node tools/gen_doc_starters.js --stdout   # print JSON (used by the parity test)
 */
const fs = require('fs'), path = require('path');
const ROOT = path.join(__dirname, '..');
const SRC  = path.join(ROOT, 'assets/js/doctemplates/designer.js');
const js   = fs.readFileSync(SRC, 'utf8');

/** Slice a top-level `function name(){ … }` by its closing brace at column 0. */
function fn(name) {
  // Any signature — pruneLanguages(t) takes an argument, the starters take none.
  const m  = js.match(new RegExp(`^function ${name}\\s*\\([^)]*\\)\\s*\\{`, 'm'));
  const at = m ? m.index : -1;
  if (at < 0) throw new Error(`gen_doc_starters: function ${name}() not found in designer.js`);
  const end = js.indexOf('\n}\n', at);
  if (end < 0) throw new Error(`gen_doc_starters: function ${name}() is unterminated`);
  return js.slice(at, end + 3);
}
/** Slice a top-level `const name = …;` up to a terminator at column 0. */
function decl(name, terminator) {
  const at = js.indexOf(`const ${name} = `) >= 0 ? js.indexOf(`const ${name} = `) : js.indexOf(`const ${name}=`);
  if (at < 0) throw new Error(`gen_doc_starters: const ${name} not found`);
  const end = js.indexOf(terminator, at);
  if (end < 0) throw new Error(`gen_doc_starters: const ${name} is unterminated`);
  return js.slice(at, end + terminator.length);
}

const STARTER_FNS = ['starterTC','starterTCplain','starterForm5A','starterKeralaSEC',
                     'starterBonafide','starterConduct','starterFeeReceipt'];

const sandbox = [
  decl('T', ';'),                 // the run-builder every starter uses
  decl('ftr', '}];'),             // shared footer
  fn('hdr'),                      // shared letterhead block
  fn('pruneLanguages'),
  ...STARTER_FNS.map(fn),
  decl('STARTERS', '\n];'),
  'STARTERS.map(s => ({id:s.id, docType:s.docType, name:s.name, meta:s.meta||"",' +
  ' boards:s.boards||null, states:s.states||null, template:s.build()}));'
].join('\n');

let out;
try { out = (0, eval)(sandbox); }
catch (e) { throw new Error('gen_doc_starters: could not evaluate the starters — ' + e.message); }

if (!Array.isArray(out) || !out.length) throw new Error('gen_doc_starters: produced no starters');
for (const s of out) {
  if (!s.id || !s.docType || !s.template || !Array.isArray(s.template.objects) || !s.template.objects.length) {
    throw new Error(`gen_doc_starters: starter '${s.id}' built empty — refusing to write a broken catalogue`);
  }
}

const json = JSON.stringify(out, null, 2);
if (process.argv.includes('--stdout')) {
  /* NO process.exit() HERE.
     When stdout is a pipe it is asynchronous, and process.exit() does not wait
     for the buffer to drain — the output was silently truncated at exactly
     65536 bytes, which json_decode() then rejected as a syntax error. Writing
     and letting the process end naturally is the fix; an explicit exit would
     hand a caller a valid-looking prefix of a catalogue. */
  process.stdout.write(json);
  return;
}

const php = `<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * GENERATED FILE — DO NOT EDIT BY HAND.
 *
 *   node tools/gen_doc_starters.js
 *
 * The starter templates are AUTHORED in assets/js/doctemplates/designer.js. This is a
 * generated server-side copy so a school can be provisioned with the standard documents
 * without a human opening the designer. \`DocStarterParityTest\` regenerates and diffs,
 * so editing a starter and forgetting to regenerate fails the suite rather than shipping
 * two different standard certificates.
 *
 * Each row: id, docType, name, meta, boards (null = any), states (null = any), template.
 * The board/state gates are the SAME ones the designer applies in startersFor() — a
 * Kerala-only form must not be seeded into a school in another state.
 *
 * Starters: ${out.length}
 */
$config['doc_starters'] = json_decode(<<<'JSON'
${json}
JSON
, true);
`;
fs.writeFileSync(path.join(ROOT, 'application/config/doc_starters.php'), php);
console.log(`wrote application/config/doc_starters.php — ${out.length} starters`);
