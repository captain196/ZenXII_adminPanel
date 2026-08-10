#!/usr/bin/env node
/**
 * Audit — can every admin-panel account actually log in?  READ ONLY.
 *
 * Admin_login::_try_firebase_admin_login fails CLOSED at five independent points,
 * and four of them surface to the user as the same generic "Invalid credentials.
 * Please try again." — indistinguishable from a wrong password, and each attempt
 * also burns the 5-strike / 30-minute account lockout budget.
 *
 * This walks every web-capable account (SSA/ADM/STA) and reports which gate,
 * if any, would reject it TODAY with the correct password:
 *
 *   1. claims        — schoolId or role missing            → AUTHZ_MISSING
 *   2. staff doc     — staff/{schoolId}_{id} absent        → AUTHZ_MISSING  (silent)
 *   3. status        — staff doc status != Active          → "Account deactivated"
 *   4. session       — schools/{id}.currentSession empty   → fail-closed, login blocked
 *   5. subscription  — tenantPublic/lifecycle not allowed  → "Subscription not active"
 *
 * Gate 4 blocks EVERY account in the tenant at once, so it is reported per school.
 *
 * Usage:  node scripts/audit_panel_login_readiness.js
 */
const path = require('path');
const fs   = require('fs');

let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }

const SVC = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..',
    'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'), 'utf8'));
admin.initializeApp({ credential: admin.credential.cert(SVC) });
const auth = admin.auth();
const db   = admin.firestore();

// Ids that sign in through the WEB panel. STU is app-only and excluded.
const WEB_ID = /^(SSA|ADM|STA)\d+$/i;

(async () => {
    // ── Accounts ──────────────────────────────────────────────────────────
    const users = [];
    let pageToken;
    do {
        const page = await auth.listUsers(1000, pageToken);
        for (const u of page.users) {
            if (!WEB_ID.test(u.uid)) continue;
            const c = u.customClaims || {};
            users.push({
                uid: u.uid,
                disabled: u.disabled,
                role: String(c.role || ''),
                schoolId: String(c.schoolId || c.school_id || ''),
                hasSnake: !!c.school_id,
                hasCamel: !!c.schoolId,
            });
        }
        pageToken = page.pageToken;
    } while (pageToken);

    // ── Tenant-level gates (session + subscription), one read per school ──
    const schoolIds = [...new Set(users.map(u => u.schoolId).filter(Boolean))];
    const schoolSnaps = schoolIds.length
        ? await db.getAll(...schoolIds.map(id => db.collection('schools').doc(id))) : [];
    const school = {};
    schoolIds.forEach((id, i) => {
        const s = schoolSnaps[i];
        school[id] = s && s.exists ? (s.data() || {}) : null;
    });

    // ── Per-account staff docs, batched ───────────────────────────────────
    const withSchool = users.filter(u => u.schoolId);
    const refs = withSchool.map(u => db.collection('staff').doc(`${u.schoolId}_${u.uid}`));
    const snaps = [];
    for (let i = 0; i < refs.length; i += 300) {
        snaps.push(...await db.getAll(...refs.slice(i, i + 300)));
    }
    const staffDoc = new Map();
    withSchool.forEach((u, i) => staffDoc.set(u.uid, snaps[i] && snaps[i].exists ? snaps[i].data() : null));

    // ── Verdict ───────────────────────────────────────────────────────────
    const blocked = [];
    for (const u of users) {
        if (u.disabled) continue;                       // intentionally off
        const reasons = [];

        if (!u.schoolId || !u.role) reasons.push('claims: schoolId/role missing');
        if (u.schoolId && (!u.hasSnake || !u.hasCamel)) {
            reasons.push(`claims: only ${u.hasSnake ? 'snake' : 'camel'} tenant key`);
        }

        const sd = staffDoc.get(u.uid);
        if (u.schoolId && !sd) reasons.push('no staff doc → generic "Invalid credentials"');
        else if (sd) {
            const st = String(sd.status || sd.Status || 'Active');
            if (st.toLowerCase() !== 'active') reasons.push(`status='${st}'`);
        }

        const s = u.schoolId ? school[u.schoolId] : null;
        if (u.schoolId && !s) reasons.push('schools/{id} missing');
        else if (s && !String(s.currentSession || '').trim()) reasons.push('school has NO currentSession (blocks whole tenant)');

        if (reasons.length) blocked.push({ ...u, reasons });
    }

    console.log('\n══ ADMIN-PANEL LOGIN READINESS ═══════════════════════════════');
    console.log(`Web-capable accounts : ${users.length}  (SSA/ADM/STA, excluding disabled)`);
    console.log(`Would be REJECTED    : ${blocked.length}\n`);

    const bySeverity = r => r.some(x => x.includes('no staff doc')) ? 0 : 1;
    blocked.sort((a, b) => bySeverity(a.reasons) - bySeverity(b.reasons) || a.uid.localeCompare(b.uid));

    for (const b of blocked) {
        console.log(`  ${b.uid.padEnd(9)} ${(b.role || '—').padEnd(20)} ${b.schoolId || '(no school)'}`);
        for (const r of b.reasons) console.log(`      • ${r}`);
    }

    // Tenants with no session block every account they hold.
    const deadTenants = schoolIds.filter(id => school[id] && !String(school[id].currentSession || '').trim());
    if (deadTenants.length) {
        console.log(`\n  Tenants with no currentSession (all logins blocked): ${deadTenants.join(', ')}`);
    }
    console.log('');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
