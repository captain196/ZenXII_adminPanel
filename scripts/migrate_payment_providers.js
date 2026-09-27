/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P12 — MIGRATE feeSettings GATEWAY CONFIG → paymentProviders
 * ══════════════════════════════════════════════════════════════════════════
 *
 *  Reads each tenant's legacy `feeSettings/{schoolName}_{session}_gateway`
 *  document and projects it into the new per-school, multi-provider model:
 *
 *      paymentProviders/{schoolId}_{providerId}
 *
 *  ── WHY THIS IS SAFE TO RUN, AND WHY IT CHANGES NOTHING BY ITSELF ────────
 *  `Payment_provider_config_resolver` reads `paymentProviders` FIRST and falls
 *  back to the legacy document when it finds nothing. So creating a
 *  paymentProviders row for a school is exactly the moment that school's
 *  resolution switches from legacy to new — which is why:
 *
 *    • the projected row is written with the SAME provider, mode and
 *      credentials the legacy doc already had, so the resolved config is
 *      equivalent and the school's payment behaviour is unchanged; and
 *    • the legacy document is NEVER deleted or modified. Rollback is
 *      deleting the new row, after which the resolver falls back exactly as
 *      before. There is no destructive step to reverse.
 *
 *  ── SCHOOL-SCOPED, DELIBERATELY ──────────────────────────────────────────
 *  The legacy key embeds the SESSION. The census that motivated this found
 *  `2026-27` present and `2025-26` ABSENT for the one configured tenant —
 *  i.e. a session rollover silently drops the gateway config under the old
 *  model. The new model is keyed on school only, because provider credentials
 *  are a merchant-account property, not an academic-year one.
 *
 *  ── REPO CONVENTIONS ─────────────────────────────────────────────────────
 *    • DRY-RUN BY DEFAULT. Writes require --commit.
 *    • Secrets are NEVER printed. Only presence/length is reported.
 *    • Idempotent: an existing paymentProviders row is reported and skipped,
 *      never overwritten — re-running cannot clobber a hand-corrected value.
 *
 *  USAGE
 *    node scripts/migrate_payment_providers.js                      # DRY-RUN, all
 *    node scripts/migrate_payment_providers.js --school=SCH_XXXX    # DRY-RUN, one
 *    node scripts/migrate_payment_providers.js --commit             # WRITE
 *    node scripts/migrate_payment_providers.js --school=SCH_XXXX --commit
 *
 *  ⚠  Running with --commit against a live tenant is a PRODUCTION ACTIVATION
 *     decision, not a technical step. It switches that school's resolution
 *     path. Do not run it without explicit authorization.
 */

const admin = require('firebase-admin');
const path  = require('path');

const sa = require(path.join(__dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const ARGV       = process.argv.slice(2);
const COMMIT     = ARGV.includes('--commit');
const schoolArg  = ARGV.find(a => a.startsWith('--school='));
const ONLY       = schoolArg ? schoolArg.split('=')[1] : null;

/** Never print a secret. Presence + length is enough to verify a projection. */
const describeSecret = (v) => {
  const s = String(v || '');
  return s === '' ? 'ABSENT' : `present(len=${s.length})`;
};

(async () => {
  console.log('══════════════════════════════════════════════════════════');
  console.log('  PAYMENT PROVIDER MIGRATION — feeSettings → paymentProviders');
  console.log(`  MODE: ${COMMIT ? '*** COMMIT (writing) ***' : 'DRY-RUN (no writes)'}`);
  if (ONLY) console.log(`  SCOPE: single school ${ONLY}`);
  console.log('══════════════════════════════════════════════════════════\n');

  const stats = { legacyFound: 0, alreadyMigrated: 0, planned: 0, written: 0, skipped: 0 };
  const plan  = [];

  // 1. Every legacy gateway document.
  const feeSettings = await db.collection('feeSettings').get();
  const legacyDocs  = [];
  feeSettings.forEach(d => {
    if (!d.id.endsWith('_gateway')) return;
    const data = d.data();
    const schoolId = data.schoolId || '';
    if (!schoolId) {
      console.log(`  ⚠  ${d.id}: no schoolId field — cannot project, SKIPPED`);
      stats.skipped++;
      return;
    }
    if (ONLY && schoolId !== ONLY) return;
    legacyDocs.push({ docId: d.id, schoolId, data });
  });
  stats.legacyFound = legacyDocs.length;

  // 2. Project each into the new model.
  for (const { docId, schoolId, data } of legacyDocs) {
    const providerId = String(data.provider || '').toLowerCase().trim();

    if (providerId === '') {
      console.log(`  ⚠  ${docId}: provider field empty — nothing to activate, SKIPPED`);
      stats.skipped++;
      continue;
    }

    const targetId = `${schoolId}_${providerId}`;
    const existing = await db.collection('paymentProviders').doc(targetId).get();

    if (existing.exists) {
      console.log(`  ✓  ${targetId}: already migrated — NOT overwritten`);
      stats.alreadyMigrated++;
      continue;
    }

    // `active` mirrors the legacy semantics exactly: absent means active.
    const active = !('active' in data) || !!data.active;

    const projected = {
      schoolId,
      providerId,
      provider:      providerId,
      displayName:   data.displayName || providerId,
      active,
      // The migrated provider becomes the school's default — it is, by
      // definition, the one that was already collecting for them.
      isDefault:     true,
      mode:          data.mode || 'test',
      api_key:        data.api_key        || '',
      api_secret:     data.api_secret     || '',
      webhook_secret: data.webhook_secret || '',
      migratedFrom:  docId,
      migratedAt:    new Date().toISOString(),
    };

    console.log(`  →  ${targetId}`);
    console.log(`       provider=${providerId} mode=${projected.mode} active=${active}`);
    console.log(`       api_key=${describeSecret(data.api_key)} ` +
                `api_secret=${describeSecret(data.api_secret)} ` +
                `webhook_secret=${describeSecret(data.webhook_secret)}`);
    if (!projected.api_key || !projected.api_secret) {
      console.log('       ⚠  INCOMPLETE CREDENTIALS — the adapter will refuse at ' +
                  '_assert_credentials(). Projected as-is; fix at source, not here.');
    }

    plan.push({ targetId, projected });
    stats.planned++;
  }

  // 3. Write (only with --commit).
  if (COMMIT && plan.length) {
    console.log('\n  Writing…');
    for (const { targetId, projected } of plan) {
      // create() — not set() — so a row that appeared between the check above
      // and this write is a hard failure rather than a silent overwrite.
      try {
        await db.collection('paymentProviders').doc(targetId).create(projected);
        stats.written++;
        console.log(`    committed ${targetId}`);
      } catch (e) {
        console.log(`    FAILED ${targetId}: ${e.message}`);
      }
    }
  }

  console.log('\n──────────────────────────────────────────────────────────');
  console.log(`  legacy gateway docs found : ${stats.legacyFound}`);
  console.log(`  already migrated (skipped): ${stats.alreadyMigrated}`);
  console.log(`  unmigratable (skipped)    : ${stats.skipped}`);
  console.log(`  planned                   : ${stats.planned}`);
  console.log(`  written                   : ${stats.written}`);
  if (!COMMIT && stats.planned) {
    console.log('\n  DRY-RUN — nothing was written. Re-run with --commit to apply.');
    console.log('  ⚠  --commit switches these schools from legacy to registry');
    console.log('     resolution. That is a production activation decision.');
  }
  console.log('  Rollback: delete the paymentProviders row. The legacy');
  console.log('  document is never modified, so the resolver falls straight back.');
  console.log('──────────────────────────────────────────────────────────');

  process.exit(0);
})().catch(e => { console.error('MIGRATION FAILED:', e.message); process.exit(1); });
