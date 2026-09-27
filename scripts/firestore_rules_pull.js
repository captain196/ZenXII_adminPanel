/**
 * ══════════════════════════════════════════════════════════════════════════
 * PULL THE CURRENTLY DEPLOYED FIRESTORE RULESET · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   node scripts/firestore_rules_pull.js <OUT_FILE>
 *
 * Prints the release name, ruleset id, byte length and a SHA-256 of the source
 * to stderr, and writes the ruleset to OUT_FILE. Never deploys, never mutates.
 *
 * ── WHY THIS EXISTS AS A COMMITTED TOOL ───────────────────────────────────
 * The deployment protocol requires a pull BEFORE and AFTER every single step,
 * with the post-deploy pull becoming the ONLY baseline for the next step. A
 * throwaway script re-typed each time is exactly where a subtle difference
 * creeps in — and a wrong baseline silently reverts another artifact.
 *
 * `firebase firestore:rules:get` does not exist in the installed CLI, so this
 * uses the Firebase Rules REST API directly with the service account.
 *
 * ── ENCODING ──────────────────────────────────────────────────────────────
 * Response chunks are collected as BUFFERS. Concatenating them as strings
 * splits multi-byte UTF-8 across chunk boundaries and silently corrupts the
 * box-drawing characters in the rules' comments — which then shows up as a
 * phantom "live has changed" diff.
 */

const admin = require('firebase-admin');
const https = require('https');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const SA_PATH = path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');

const outFile = process.argv[2];
if (!outFile) {
  console.error('usage: node scripts/firestore_rules_pull.js <OUT_FILE>');
  process.exit(2);
}

const sa = require(SA_PATH);
admin.initializeApp({ credential: admin.credential.cert(sa) });

function api(token, apiPath) {
  return new Promise((resolve, reject) => {
    https.get(
      { host: 'firebaserules.googleapis.com', path: apiPath, headers: { Authorization: 'Bearer ' + token } },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const body = Buffer.concat(chunks).toString('utf8');
          try { resolve(JSON.parse(body)); }
          catch (e) { reject(new Error('non-JSON response: ' + body.slice(0, 300))); }
        });
      }
    ).on('error', reject);
  });
}

(async () => {
  const token = (await admin.credential.cert(sa).getAccessToken()).access_token;

  const rel = await api(token, '/v1/projects/graderadmin/releases/cloud.firestore');
  if (!rel.rulesetName) throw new Error('no cloud.firestore release: ' + JSON.stringify(rel).slice(0, 300));

  const rs = await api(token, '/v1/' + rel.rulesetName);
  const files = (rs.source && rs.source.files) || [];
  if (files.length !== 1) throw new Error('expected exactly 1 source file, got ' + files.length);

  const content = files[0].content;
  const sha = crypto.createHash('sha256').update(content, 'utf8').digest('hex');

  fs.writeFileSync(outFile, content, 'utf8');

  console.error('release   : ' + rel.rulesetName);
  console.error('ruleset   : ' + rel.rulesetName.split('/').pop());
  console.error('createTime: ' + (rel.createTime || '?'));
  console.error('bytes     : ' + content.length);
  console.error('sha256    : ' + sha);
  console.error('written   : ' + outFile);
})().catch((e) => {
  console.error('PULL FAILED: ' + e.message);
  process.exit(1);
});
