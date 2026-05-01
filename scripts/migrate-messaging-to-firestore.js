#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Messaging RTDB → Firestore Backfill (Phase 5f)
 *
 *  Copies the canonical (camelCase, post-Phase-1) RTDB messaging data
 *  into the new Firestore collections used by Messaging_service.php.
 *
 *  Source RTDB tree:
 *    Schools/{school}/Communication/Messages/
 *    ├── Conversations/{convId}
 *    ├── Chat/{convId}/{msgId}
 *    └── Inbox/{role}/{userId}/{convId}        (lowercase only — run
 *                                               migrate-inbox-case.js
 *                                               first if Capital Case
 *                                               nodes still exist)
 *
 *  Destination Firestore collections:
 *    conversations    doc id = {schoolId}_{convId}
 *    messages         doc id = {schoolId}_{convId}_{msgId}
 *    messageInboxes   doc id = {schoolId}_{role}_{userId}_{convId}
 *
 *  IDEMPOTENT — safe to re-run. Uses set(merge:true) so existing
 *  Firestore docs aren't clobbered, only filled in.
 *  NON-DESTRUCTIVE — RTDB data is left alone.
 *
 *  Usage:
 *    node scripts/migrate-messaging-to-firestore.js                # dry-run
 *    node scripts/migrate-messaging-to-firestore.js --apply        # write
 *    node scripts/migrate-messaging-to-firestore.js --school Demo  # one school
 * ═══════════════════════════════════════════════════════════════════
 */

const admin = require('firebase-admin');
const path = require('path');

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);
const DATABASE_URL = 'https://graderadmin-default-rtdb.firebaseio.com/';

const args = process.argv.slice(2);
const DRY_RUN = !args.includes('--apply');
const FILTER_SCHOOL = args.includes('--school')
  ? args[args.indexOf('--school') + 1]
  : null;

const serviceAccount = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential: admin.credential.cert(serviceAccount),
  databaseURL: DATABASE_URL,
});

const rtdb = admin.database();
const fs = admin.firestore();

// ── Styling ────────────────────────────────────────────────────────
const FIXED   = '\x1b[32m✔ COPIED  \x1b[0m';
const SKIPPED = '\x1b[90m⊘ SKIPPED \x1b[0m';
const ERROR   = '\x1b[31m✖ ERROR   \x1b[0m';
const DRY     = '\x1b[35m⬡ DRY-RUN \x1b[0m';
const BOLD    = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM     = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN    = (s) => `\x1b[36m${s}\x1b[0m`;

const stats = {
  schools: 0,
  conversations: 0,
  messages: 0,
  inboxes: 0,
  errors: 0,
};

/**
 * The admin panel uses school_name as the RTDB key but school_id as
 * the Firestore prefix. For SCH_XXXXXX schools they're identical; for
 * legacy schools (e.g. "Demo") school_id resolves via Indexes/. Most
 * places in this codebase use school_name === school_id; the few
 * legacy schools (Demo) keep matching too because Demo == Demo.
 *
 * We use the RTDB key as the schoolId for the doc id prefix. If the
 * Indexes table later disagrees you can re-run the script with the
 * canonical schoolId and the merge:true semantics will dedupe.
 */
function schoolIdFor(rtdbKey) {
  return rtdbKey;
}

async function listSchools() {
  if (FILTER_SCHOOL) return [FILTER_SCHOOL];
  const snap = await rtdb.ref('Schools').once('value');
  const out = [];
  snap.forEach((c) => out.push(c.key));
  return out;
}

async function copyConversation(schoolId, convId, conv) {
  const docId = `${schoolId}_${convId}`;
  const data = {
    schoolId,
    conversationId: convId,
    ...conv,
  };
  // Derive participantIds from participants map for Firestore
  // array-contains dedup queries.
  if (conv && typeof conv.participants === 'object' && !Array.isArray(conv.participants)) {
    data.participantIds = Object.keys(conv.participants);
  }
  if (DRY_RUN) {
    console.log(`${DRY}  ${CYAN(schoolId)} ${DIM('conversations/')}${docId}`);
    return;
  }
  await fs.collection('conversations').doc(docId).set(data, { merge: true });
}

async function copyMessage(schoolId, convId, msgId, msg) {
  const docId = `${schoolId}_${convId}_${msgId}`;
  const data = {
    schoolId,
    conversationId: convId,
    messageId: msgId,
    ...msg,
  };
  if (DRY_RUN) {
    console.log(`${DRY}  ${CYAN(schoolId)} ${DIM('messages/')}${docId}`);
    return;
  }
  await fs.collection('messages').doc(docId).set(data, { merge: true });
}

async function copyInbox(schoolId, role, userId, convId, entry) {
  const r = role.toLowerCase();
  const docId = `${schoolId}_${r}_${userId}_${convId}`;
  const data = {
    schoolId,
    role: r,
    userId,
    conversationId: convId,
    ...entry,
  };
  if (DRY_RUN) {
    console.log(`${DRY}  ${CYAN(schoolId)} ${DIM('messageInboxes/')}${docId}`);
    return;
  }
  await fs.collection('messageInboxes').doc(docId).set(data, { merge: true });
}

async function migrateOneSchool(schoolKey) {
  const schoolId = schoolIdFor(schoolKey);
  const root = `Schools/${schoolKey}/Communication/Messages`;
  const snap = await rtdb.ref(root).once('value');
  if (!snap.exists()) {
    console.log(`${SKIPPED} ${CYAN(schoolKey)} ${DIM('(no Communication/Messages)')}`);
    return;
  }
  stats.schools += 1;
  console.log(`\n${BOLD(schoolKey)}`);

  // ── Conversations ──────────────────────────────────────────────────
  const convs = snap.child('Conversations').val() || {};
  for (const [convId, conv] of Object.entries(convs)) {
    if (!conv || typeof conv !== 'object') continue;
    try {
      await copyConversation(schoolId, convId, conv);
      stats.conversations += 1;
      if (!DRY_RUN) console.log(`${FIXED}  ${DIM('conversations/')}${schoolId}_${convId}`);
    } catch (e) {
      console.error(`${ERROR}  conversations/${convId}: ${e.message}`);
      stats.errors += 1;
    }
  }

  // ── Messages ───────────────────────────────────────────────────────
  const chat = snap.child('Chat').val() || {};
  for (const [convId, msgs] of Object.entries(chat)) {
    if (!msgs || typeof msgs !== 'object') continue;
    for (const [msgId, msg] of Object.entries(msgs)) {
      if (!msg || typeof msg !== 'object') continue;
      try {
        await copyMessage(schoolId, convId, msgId, msg);
        stats.messages += 1;
      } catch (e) {
        console.error(`${ERROR}  messages/${convId}/${msgId}: ${e.message}`);
        stats.errors += 1;
      }
    }
  }
  if (!DRY_RUN && stats.messages > 0) {
    console.log(`${FIXED}  ${stats.messages} messages copied so far`);
  }

  // ── Inboxes ────────────────────────────────────────────────────────
  const inbox = snap.child('Inbox').val() || {};
  for (const [role, byUser] of Object.entries(inbox)) {
    if (!byUser || typeof byUser !== 'object') continue;
    for (const [userId, byConv] of Object.entries(byUser)) {
      if (!byConv || typeof byConv !== 'object') continue;
      for (const [convId, entry] of Object.entries(byConv)) {
        if (!entry || typeof entry !== 'object') continue;
        try {
          await copyInbox(schoolId, role, userId, convId, entry);
          stats.inboxes += 1;
        } catch (e) {
          console.error(`${ERROR}  messageInboxes/${role}/${userId}/${convId}: ${e.message}`);
          stats.errors += 1;
        }
      }
    }
  }
}

async function main() {
  console.log(BOLD('━━━ Messaging RTDB → Firestore Backfill ━━━'));
  console.log(`Mode:   ${DRY_RUN ? 'DRY-RUN (no writes)' : 'APPLY'}`);
  console.log(`School: ${FILTER_SCHOOL || '(all)'}\n`);

  const schools = await listSchools();
  for (const school of schools) {
    await migrateOneSchool(school);
  }

  console.log('\n' + BOLD('━━━ Summary ━━━'));
  console.log(`Schools scanned:        ${stats.schools}`);
  console.log(`Conversations:          ${stats.conversations}`);
  console.log(`Messages:               ${stats.messages}`);
  console.log(`Inbox stubs:            ${stats.inboxes}`);
  if (stats.errors) console.log(`\x1b[31mErrors: ${stats.errors}\x1b[0m`);

  if (DRY_RUN) {
    console.log('\n' + DIM('Re-run with --apply to actually write to Firestore.'));
  }
  process.exit(stats.errors ? 1 : 0);
}

main().catch((e) => {
  console.error('Fatal:', e);
  process.exit(1);
});
