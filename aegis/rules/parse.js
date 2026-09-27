'use strict';
/**
 * firestore.rules → structured blocks.
 *
 * Everything else in the rules subsystem stands on this. The file ships as ONE
 * artifact, so the only useful unit of reasoning is the individual `match`
 * block: "who owns this, did it change, is it mine or theirs".
 *
 * Parsing strategy: build a same-length MASK of the source where comment bodies
 * and string contents are blanked to spaces. Brace matching and regexes run on
 * the mask; slices are taken from the ORIGINAL text at the same offsets. That
 * makes a `{` inside a comment or a quoted path harmless, which a naive regex
 * pass gets wrong — and this file has 131 match blocks and heavy box-drawing
 * comment art, so it gets it wrong often.
 */

/** Blank out comments and string bodies, preserving length and newlines. */
function maskNonCode(text) {
  const out = text.split('');
  const n = text.length;
  let i = 0;
  const blank = (from, to) => {
    for (let k = from; k < to && k < n; k++) if (out[k] !== '\n') out[k] = ' ';
  };
  while (i < n) {
    const c = text[i], d = text[i + 1];
    if (c === '/' && d === '/') {
      let j = i; while (j < n && text[j] !== '\n') j++;
      blank(i, j); i = j;
    } else if (c === '/' && d === '*') {
      let j = i + 2; while (j < n && !(text[j] === '*' && text[j + 1] === '/')) j++;
      blank(i, Math.min(j + 2, n)); i = j + 2;
    } else if (c === '"' || c === "'") {
      let j = i + 1; while (j < n && text[j] !== c) { if (text[j] === '\\') j++; j++; }
      blank(i + 1, j); i = j + 1;
    } else i++;
  }
  return out.join('');
}

/** Prefix line-offset table for O(log n) line lookups. */
function lineIndex(text) {
  const starts = [0];
  for (let i = 0; i < text.length; i++) if (text[i] === '\n') starts.push(i + 1);
  return starts;
}
function lineAt(starts, idx) {
  let lo = 0, hi = starts.length - 1;
  while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (starts[mid] <= idx) lo = mid; else hi = mid - 1; }
  return lo + 1;
}

/** Index of the `}` closing the `{` at openIdx, scanning the mask. */
function matchBrace(mask, openIdx) {
  let depth = 0;
  for (let i = openIdx; i < mask.length; i++) {
    if (mask[i] === '{') depth++;
    else if (mask[i] === '}') { depth--; if (depth === 0) return i; }
  }
  return mask.length - 1;
}

/**
 * Canonical form of a rules fragment: comments already gone via the mask, then
 * whitespace collapsed. Two fragments with the same canon are semantically the
 * same rule.
 *
 * This is the single most important function here. A textual diff of this file
 * alarms on reformatting and on mojibake in the box-drawing comment art (the
 * live ruleset genuinely differs from git in 6 such characters and in nothing
 * else). Alarming on that trains the reader to ignore the tool.
 */
function canon(fragmentMasked) {
  return fragmentMasked.replace(/\s+/g, ' ').trim();
}

/** djb2 — short, stable, dependency-free. Only ever compared, never inverted. */
function hash(s) {
  let h = 5381;
  for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0;
  return (h >>> 0).toString(16).padStart(8, '0');
}

/** `/{schoolId}_{studentId}` → `/{*}_{*}` so a wildcard rename keeps block identity. */
function normPath(p) { return p.replace(/\{[^}]*\}/g, '{*}'); }

/**
 * The collection a block guards: the last concrete (non-wildcard) segment of
 * its path chain, skipping the `/databases/{db}/documents` preamble.
 */
function collectionOf(fullPath) {
  const segs = fullPath.split('/').filter(Boolean)
    .filter(s => s !== 'databases' && s !== 'documents' && !/^\{/.test(s));
  return segs.length ? segs[segs.length - 1] : null;
}

/**
 * Parse a rules document.
 * @returns {{blocks:Array, functions:Array, lineCount:number}}
 *   block = { path, fullPath, key, collection, start, end, depth, parentKey,
 *             allows:[{ops,cond,line}], ownCanon, ownHash, treeHash }
 */
function parseRules(text) {
  const mask = maskNonCode(text);
  const starts = lineIndex(text);
  const blocks = [];
  const functions = [];

  // A match path is a run of `/segment`, where a segment is either literal text
  // or a `{wildcard}` / `{document=**}`. The wildcard braces are why a lazy
  // `[^\s{]+` fails here — it would stop at `/databases/` and mistake the `{` of
  // `{database}` for the block's opening brace, flattening the whole tree.
  const reMatch = /\bmatch\s+((?:\/(?:\{[^}]*\}|[^\s/{}]+))+)\s*\{/g;
  let m;
  while ((m = reMatch.exec(mask)) !== null) {
    const openIdx = m.index + m[0].length - 1;
    const close = matchBrace(mask, openIdx);
    blocks.push({
      path: m[1],
      _open: openIdx, _close: close,
      start: lineAt(starts, m.index),
      end: lineAt(starts, close),
    });
  }

  // Nesting by containment: a block's parent is the innermost block enclosing it.
  blocks.sort((a, b) => a._open - b._open);
  for (const b of blocks) {
    let parent = null;
    for (const c of blocks) {
      if (c === b) continue;
      if (c._open < b._open && c._close > b._close) {
        if (!parent || c._open > parent._open) parent = c;
      }
    }
    b._parent = parent;
    b.depth = 0;
    for (let p = parent; p; p = p._parent) b.depth++;
  }
  for (const b of blocks) {
    const chain = [];
    for (let p = b; p; p = p._parent) chain.unshift(p.path);
    b.fullPath = chain.join('');
    b.key = normPath(b.fullPath);
    b.collection = collectionOf(b.fullPath);
    b.parentKey = b._parent ? normPath(b._parent.fullPath) : null;
  }

  // Per-block content. `own` excludes nested match blocks so a child's change is
  // attributed to the child, not smeared across every ancestor.
  for (const b of blocks) {
    const children = blocks.filter(c => c._parent === b).sort((x, y) => x._open - y._open);
    let own = '', cursor = b._open + 1;
    for (const c of children) {
      // stop at the child's `match` keyword, resume after its closing brace
      const kwIdx = mask.lastIndexOf('match', c._open);
      own += mask.slice(cursor, Math.max(cursor, kwIdx));
      cursor = c._close + 1;
    }
    own += mask.slice(cursor, b._close);
    b.ownCanon = canon(own);
    b.ownHash = hash(b.ownCanon);
    b.treeHash = hash(canon(mask.slice(b._open, b._close + 1)));

    b.allows = [];
    const reAllow = /\ballow\s+([a-z,\s]+?)\s*:\s*if\s+([\s\S]*?);/g;
    let a;
    const seg = mask.slice(b._open, b._close + 1);
    while ((a = reAllow.exec(seg)) !== null) {
      const absIdx = b._open + a.index;
      // attribute the allow to its innermost enclosing block only
      const inner = blocks.find(c => c._parent === b && c._open < absIdx && c._close > absIdx);
      if (inner) continue;
      b.allows.push({
        ops: a[1].replace(/\s+/g, ' ').trim(),
        cond: canon(a[2]),
        line: lineAt(starts, absIdx),
      });
    }
  }

  const reFn = /\bfunction\s+(\w+)\s*\(([^)]*)\)\s*\{/g;
  let f;
  while ((f = reFn.exec(mask)) !== null) {
    const openIdx = f.index + f[0].length - 1;
    const close = matchBrace(mask, openIdx);
    const body = canon(mask.slice(openIdx, close + 1));
    functions.push({
      name: f[1],
      args: f[2].split(',').map(s => s.trim()).filter(Boolean),
      start: lineAt(starts, f.index),
      end: lineAt(starts, close),
      // Body text is kept, not just its hash: risk analysis has to see THROUGH
      // helpers. `allow write: if isAdmin()` looks ungated to a flat regex, but
      // isAdmin() checks tenantActive(...school_id) internally.
      body,
      bodyHash: hash(body),
    });
  }

  for (const b of blocks) { delete b._parent; }
  return { blocks, functions, lineCount: starts.length, docHash: hash(canon(mask)) };
}

module.exports = { parseRules, canon, hash, maskNonCode, normPath, collectionOf };
