// One-off dev utility: mint a Firebase ID token for a parent UID so we
// can exercise parent-facing endpoints (parent_verify_payment, etc.)
// from PowerShell/curl without needing the mobile app.
//
// Usage:
//   node scripts/mint_parent_token.js            # default UID = STU0001
//   node scripts/mint_parent_token.js STU0002
//
// Output: the ID token is printed to stdout (nothing else — pipe-friendly).
// Errors go to stderr.
//
// Flow:
//   1. Sign a custom token using the Firebase Admin SDK + service account.
//   2. Exchange it for an ID token via the Identity Toolkit REST API
//      (signInWithCustomToken). The web API key is hard-coded below.

const admin = require('firebase-admin');
const path  = require('path');
const https = require('https');

const sa = require(path.join(
  __dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
));

admin.initializeApp({ credential: admin.credential.cert(sa) });

const UID     = process.argv[2] || 'STU0001';
const API_KEY = 'AIzaSyBe0xmEw3ms6WWmnkj3-hFAspksx9v4CTQ';

function postJson(hostname, reqPath, body) {
  return new Promise((resolve, reject) => {
    const data = JSON.stringify(body);
    const req = https.request({
      hostname, path: reqPath, method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(data),
      },
    }, (res) => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString();
        try { resolve({ status: res.statusCode, body: JSON.parse(text) }); }
        catch { resolve({ status: res.statusCode, body: text }); }
      });
    });
    req.on('error', reject);
    req.write(data);
    req.end();
  });
}

(async () => {
  try {
    const customToken = await admin.auth().createCustomToken(UID);
    const res = await postJson(
      'identitytoolkit.googleapis.com',
      `/v1/accounts:signInWithCustomToken?key=${API_KEY}`,
      { token: customToken, returnSecureToken: true }
    );
    if (res.status !== 200 || !res.body || !res.body.idToken) {
      console.error('Token exchange failed:', res.status, JSON.stringify(res.body));
      process.exit(1);
    }
    console.log(res.body.idToken);
  } catch (e) {
    console.error('Error:', e && e.message ? e.message : e);
    process.exit(1);
  }
})();
