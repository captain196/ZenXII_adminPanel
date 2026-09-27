/**
 * READ-ONLY. Fetches the currently deployed Firestore ruleset and writes a
 * snapshot + collection list. Used before and after the G3 deployment so the
 * diff is measured against reality, not against an assumption.
 *
 * Buffer.concat, not string concatenation: appending Buffer chunks to a JS
 * string splits multi-byte UTF-8 across chunk boundaries and corrupts the
 * box-drawing characters in the rules comments.
 *
 *   node scripts/_g3_live_snapshot.js before
 *   node scripts/_g3_live_snapshot.js after
 */
const admin = require('firebase-admin');
const path  = require('path');
const https = require('https');
const fs    = require('fs');
const crypto = require('crypto');

const LABEL = (process.argv[2] || 'snapshot').replace(/[^a-z0-9_]/gi, '');
const sa = require(path.join(process.cwd(), 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });

const get = (host, p, token) => new Promise((res, rej) => {
  https.get({ host, path: p, headers: { Authorization: 'Bearer ' + token } }, (r) => {
    const chunks = [];
    r.on('data', (c) => chunks.push(c));
    r.on('end', () => res({ status: r.statusCode, buf: Buffer.concat(chunks) }));
  }).on('error', rej);
});

(async () => {
  const token = (await admin.credential.cert(sa).getAccessToken()).access_token;

  const rel = await get('firebaserules.googleapis.com',
    '/v1/projects/graderadmin/releases/cloud.firestore', token);
  if (rel.status !== 200) { console.error('RELEASE FETCH FAILED', rel.status); process.exit(2); }
  const j = JSON.parse(rel.buf.toString('utf8'));

  const rs = await get('firebaserules.googleapis.com', '/v1/' + j.rulesetName, token);
  if (rs.status !== 200) { console.error('RULESET FETCH FAILED', rs.status); process.exit(2); }
  const content = JSON.parse(rs.buf.toString('utf8')).source.files[0].content;

  const dir = path.join(process.cwd(), 'firebase-rules');
  fs.writeFileSync(path.join(dir, `firestore.LIVE_${LABEL}.rules`), content, 'utf8');

  const cols = [...new Set([...content.matchAll(/match \/([A-Za-z0-9_]+)\//g)].map(m => m[1]))].sort();
  fs.writeFileSync(path.join(dir, `live_collections_${LABEL}.txt`), cols.join('\n'), 'utf8');

  console.log(`  [${LABEL}]`);
  console.log('    rulesetName :', j.rulesetName);
  console.log('    updateTime  :', j.updateTime);
  console.log('    bytes       :', Buffer.byteLength(content, 'utf8'));
  console.log('    md5         :', crypto.createHash('md5').update(Buffer.from(content, 'utf8')).digest('hex'));
  console.log('    collections :', cols.length);
  console.log('    paymentProviders present :', cols.includes('paymentProviders'));
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
