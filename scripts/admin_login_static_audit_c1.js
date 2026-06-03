#!/usr/bin/env node
/**
 * Wave C — Step C1 : Admin_login.php static source audit.
 *
 * Purpose: lightweight regex guard against accidentally leaving forbidden
 * legacy patterns in Admin_login.php's credential path. Verifies the file
 * has the required Firebase-Auth + Firestore-only shape post-cutover.
 *
 * READ-ONLY. Reads application/controllers/Admin_login.php as text and
 * applies pattern checks. No writes, no Firebase, no network.
 *
 * Exit codes:
 *   0  — All FORBIDDEN patterns absent AND all REQUIRED patterns present
 *   1  — Any FORBIDDEN pattern present OR any REQUIRED pattern absent
 *
 * Usage:  node scripts/admin_login_static_audit_c1.js
 */
const fs   = require('fs');
const path = require('path');

const TARGET = path.resolve(__dirname, '../application/controllers/Admin_login.php');

if (!fs.existsSync(TARGET)) {
    console.error('FATAL: target file not found: ' + TARGET);
    process.exit(2);
}

const src = fs.readFileSync(TARGET, 'utf8');
const lines = src.split(/\r?\n/);
const lineCount = lines.length;

function findLines(re) {
    const hits = [];
    for (let i = 0; i < lines.length; i++) {
        if (re.test(lines[i])) hits.push(i + 1);
    }
    return hits;
}

// ── FORBIDDEN patterns (must be ABSENT post-cutover) ────────────────────
const FORBIDDEN = [
    { id: 'F1', label: 'auth_client->web_login(...)              ', re: /->auth_client\s*->\s*web_login\s*\(/ },
    { id: 'F2', label: '_firebase_fallback_login                  ', re: /_firebase_fallback_login/ },
    { id: 'F3', label: '_findSchoolForAdmin                       ', re: /_findSchoolForAdmin/ },
    { id: 'F4', label: 'firebase->get("Users/Admin/...)           ', re: /->\s*get\s*\(\s*["']Users\/Admin\// },
    { id: 'F5', label: 'firebase->update("Users/Admin/...)        ', re: /->\s*update\s*\(\s*["']Users\/Admin\// },
    { id: 'F6', label: 'password_verify(...) anywhere in file     ', re: /\bpassword_verify\s*\(/ },
];

// ── REQUIRED patterns (must be PRESENT post-cutover) ────────────────────
const REQUIRED = [
    { id: 'R1', label: '_try_firebase_admin_login method decl     ', re: /(?:private|public|protected)\s+function\s+_try_firebase_admin_login\s*\(/ },
    { id: 'R2', label: 'firebase->signInWithEmail( call           ', re: /->\s*signInWithEmail\s*\(/ },
    { id: 'R3', label: "firestoreGet('staff' call                  ", re: /->\s*firestoreGet\s*\(\s*["']staff["']/ },
    { id: 'R4a', label: "ADMIN_LOGIN_SUCCESS telemetry string       ", re: /['"]ADMIN_LOGIN_SUCCESS['"]/ },
    { id: 'R4b', label: "ADMIN_LOGIN_FAILED telemetry string        ", re: /['"]ADMIN_LOGIN_FAILED['"]/ },
];

console.log('═'.repeat(74));
console.log(' WAVE C / Step C1 — Admin_login.php static source audit');
console.log('═'.repeat(74));
console.log(' File  : ' + path.relative(process.cwd(), TARGET));
console.log(' Lines : ' + lineCount);
console.log();

let failCount = 0;

console.log(' FORBIDDEN patterns (must be ABSENT post-cutover):');
for (const chk of FORBIDDEN) {
    const hits = findLines(chk.re);
    if (hits.length === 0) {
        console.log('   ' + chk.id + ' ' + chk.label + ' : ✅ ABSENT');
    } else {
        failCount++;
        const sampleLines = hits.slice(0, 5).join(', ') + (hits.length > 5 ? ` ... (+${hits.length - 5} more)` : '');
        console.log('   ' + chk.id + ' ' + chk.label + ' : ❌ PRESENT — lines ' + sampleLines);
    }
}

console.log();
console.log(' REQUIRED patterns (must be PRESENT post-cutover):');
for (const chk of REQUIRED) {
    const hits = findLines(chk.re);
    if (hits.length > 0) {
        console.log('   ' + chk.id + ' ' + chk.label + ' : ✅ PRESENT (line ' + hits[0] + (hits.length > 1 ? ` +${hits.length - 1} more` : '') + ')');
    } else {
        failCount++;
        console.log('   ' + chk.id + ' ' + chk.label + ' : ❌ ABSENT');
    }
}

// ── Informational (auth_client occurrence count — should remain for password reset) ──
const acHits = findLines(/->\s*auth_client\s*->/);
const acLibHits = findLines(/load\s*->\s*library\s*\(\s*["']auth_client["']\s*\)/);
console.log();
console.log(' INFO (password-reset retention — auth_client lines should remain for reset flows):');
console.log('   auth_client method calls          : ' + acHits.length + ' line(s)');
console.log('   load->library("auth_client") calls : ' + acLibHits.length + ' line(s)');

console.log();
console.log('═'.repeat(74));
if (failCount === 0) {
    console.log(' ✅ VERDICT: Admin_login.php is Wave C compliant.');
    console.log('     - All forbidden legacy patterns ABSENT');
    console.log('     - All required Firebase Auth + Firestore patterns PRESENT');
    console.log('═'.repeat(74));
    process.exit(0);
} else {
    console.log(' ❌ VERDICT: Admin_login.php is NOT Wave C compliant (' + failCount + ' failure(s)).');
    console.log('     Review FORBIDDEN/REQUIRED hits above. Cutover incomplete.');
    console.log('═'.repeat(74));
    process.exit(1);
}
