'use strict';
const fs = require('fs');

/** Read a file or '' if unreadable. */
function read(p) { try { return fs.readFileSync(p, 'utf8'); } catch (e) { return ''; } }
function exists(p) { try { fs.accessSync(p); return true; } catch (e) { return false; } }

/** 1-indexed line number of a regex match in text, or 0. */
function lineOf(text, index) {
  return text.slice(0, index).split('\n').length;
}

/** Iterate regex matches, yielding {match, index, line}. */
function* matches(text, re) {
  const r = new RegExp(re.source, re.flags.includes('g') ? re.flags : re.flags + 'g');
  let m;
  while ((m = r.exec(text)) !== null) {
    yield { match: m, index: m.index, line: lineOf(text, m.index) };
    if (m.index === r.lastIndex) r.lastIndex++;
  }
}

/** A finding record. level: 'error' (fail) | 'warn' | 'info'. */
function finding(level, file, line, msg) { return { level, file, line, msg }; }

/** Roll findings into a status: any error → fail; any warn → warn; else pass. */
function rollup(findings) {
  if (findings.some(f => f.level === 'error')) return 'fail';
  if (findings.some(f => f.level === 'warn')) return 'warn';
  return 'pass';
}

module.exports = { read, exists, lineOf, matches, finding, rollup };
