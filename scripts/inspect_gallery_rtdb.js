#!/usr/bin/env node
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Phase C-2 — Gallery Inspector (READ-ONLY)
 *
 *  Compares RTDB legacy gallery data with Firestore canonical gallery
 *  collections, per school. NO writes, NO migration. Diagnostic only.
 *
 *  RTDB (legacy, written by Teacher's GalleryRepository.kt):
 *    Schools/{schoolKey}/Gallery/Albums/{albumId}        → album metadata
 *    Schools/{schoolKey}/Gallery/Media/{albumId}/{mediaId} → media metadata
 *
 *  Firestore (canonical, written by admin Events.php and parent's repo):
 *    galleryAlbums  collection
 *    galleryMedia   collection
 *
 *  Note: this script does NOT assume the field name used to filter
 *  Firestore by school. It queries by BOTH `schoolId` and `schoolCode`
 *  and reports both counts so any drift becomes visible.
 *
 *  Usage:
 *    node scripts/inspect_gallery_rtdb.js                # all schools
 *    node scripts/inspect_gallery_rtdb.js --school SCH_X
 *    node scripts/inspect_gallery_rtdb.js --school=SCH_X
 *    node scripts/inspect_gallery_rtdb.js --quiet        # summary only
 * ═══════════════════════════════════════════════════════════════════
 */

const admin = require('firebase-admin');
const path  = require('path');

const SERVICE_ACCOUNT_PATH = path.resolve(
  __dirname,
  '../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
);
const DATABASE_URL = process.env.RTDB_URL
  || 'https://graderadmin-default-rtdb.firebaseio.com/';

function parseArg(name) {
  const eq = process.argv.find(a => a.startsWith(`--${name}=`));
  if (eq) return eq.split('=').slice(1).join('=');
  const i = process.argv.indexOf(`--${name}`);
  if (i !== -1 && i + 1 < process.argv.length && !process.argv[i + 1].startsWith('--')) {
    return process.argv[i + 1];
  }
  return null;
}
const FILTER_SCHOOL = parseArg('school');
const QUIET         = process.argv.includes('--quiet');

const sa = require(SERVICE_ACCOUNT_PATH);
admin.initializeApp({
  credential:  admin.credential.cert(sa),
  databaseURL: DATABASE_URL,
});
const rtdb = admin.database();
const fs   = admin.firestore();

// ── Styling ────────────────────────────────────────────────────────
const BOLD = (s) => `\x1b[1m${s}\x1b[0m`;
const DIM  = (s) => `\x1b[2m${s}\x1b[0m`;
const CYAN = (s) => `\x1b[36m${s}\x1b[0m`;
const YEL  = (s) => `\x1b[33m${s}\x1b[0m`;
const RED  = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN  = (s) => `\x1b[32m${s}\x1b[0m`;
const HR   = '─'.repeat(72);
const HR2  = '═'.repeat(72);

// ── Helpers ────────────────────────────────────────────────────────
async function rtdbGet(p) {
  try { return (await rtdb.ref(p).once('value')).val(); }
  catch (_) { return null; }
}

async function rtdbShallow(p) {
  // Returns top-level child keys without fetching their values.
  // Useful for counting without loading huge subtrees.
  try {
    const url = new URL(`${p}.json`, admin.app().options.databaseURL);
    url.searchParams.set('shallow', 'true');
    const token = await admin.app().options.credential.getAccessToken();
    const res = await fetch(url.toString(), {
      headers: { Authorization: `Bearer ${token.access_token}` },
    });
    if (!res.ok) return null;
    return await res.json();
  } catch (_) { return null; }
}

function fieldUnion(...objs) {
  const set = new Set();
  for (const o of objs) {
    if (o && typeof o === 'object') {
      for (const k of Object.keys(o)) set.add(k);
    }
  }
  return [...set].sort();
}

// ── Per-school inspection ──────────────────────────────────────────
async function inspectSchool(schoolId) {
  console.log(`\n${HR}\n  ${BOLD(CYAN(schoolId))}\n${HR}`);

  const result = {
    schoolId,
    rtdbAlbumKeysSampled: [], // schoolKeys actually present under Schools/<key>/Gallery
    rtdbAlbumCount:  0,
    rtdbMediaCount:  0,
    rtdbAlbumSample: null,
    rtdbMediaSample: null,
    fsAlbumCountBySchoolId:    0,
    fsAlbumCountBySchoolCode:  0,
    fsMediaCountBySchoolId:    0,
    fsMediaCountBySchoolCode:  0,
    fsAlbumSample: null,
    fsMediaSample: null,
    fsAlbumIdsSample: [],
    rtdbAlbumIdsSample: [],
  };

  // ── 1. RTDB: locate Gallery node ──────────────────────────────
  // Mobile may have written under either the SCH_xxx form or the login
  // code (per audit findings). Try the SCH form first; fall back to the
  // school's login code from Indexes/School_codes.
  const schoolNode = await rtdbGet(`Schools/${schoolId}`);
  let rtdbGalleryRoot = null;
  let usedSchoolKey   = null;
  if (schoolNode && schoolNode.Gallery && typeof schoolNode.Gallery === 'object') {
    rtdbGalleryRoot = schoolNode.Gallery;
    usedSchoolKey   = schoolId;
  } else {
    // Try the login-code form via Indexes/School_codes/{schoolId}
    const loginCode = await rtdbGet(`Indexes/School_codes/${schoolId}`);
    if (typeof loginCode === 'string' && loginCode.length > 0) {
      const altNode = await rtdbGet(`Schools/${loginCode}/Gallery`);
      if (altNode && typeof altNode === 'object') {
        rtdbGalleryRoot = altNode;
        usedSchoolKey   = loginCode;
      }
    }
  }

  if (rtdbGalleryRoot) {
    result.rtdbAlbumKeysSampled.push(usedSchoolKey);
    const albums = rtdbGalleryRoot.Albums;
    const media  = rtdbGalleryRoot.Media;

    if (albums && typeof albums === 'object') {
      const ids = Object.keys(albums);
      result.rtdbAlbumCount = ids.length;
      result.rtdbAlbumIdsSample = ids.slice(0, 5);
      const firstId = ids[0];
      if (firstId) {
        result.rtdbAlbumSample = {
          albumId: firstId,
          path: `Schools/${usedSchoolKey}/Gallery/Albums/${firstId}`,
          value: albums[firstId],
        };
      }
    }
    if (media && typeof media === 'object') {
      let total = 0;
      let firstSample = null;
      for (const [albumId, byMedia] of Object.entries(media)) {
        if (byMedia && typeof byMedia === 'object') {
          const mediaIds = Object.keys(byMedia);
          total += mediaIds.length;
          if (!firstSample && mediaIds.length > 0) {
            const mid = mediaIds[0];
            firstSample = {
              albumId, mediaId: mid,
              path: `Schools/${usedSchoolKey}/Gallery/Media/${albumId}/${mid}`,
              value: byMedia[mid],
            };
          }
        }
      }
      result.rtdbMediaCount = total;
      result.rtdbMediaSample = firstSample;
    }
  } else {
    console.log(`  ${DIM('No RTDB Gallery node under Schools/' + schoolId + '/Gallery (checked SCH form + login-code form)')}`);
  }

  // ── 2. Firestore: query both possible school-key fields ──────
  for (const fieldName of ['schoolId', 'schoolCode']) {
    try {
      const albumSnap = await fs.collection('galleryAlbums')
        .where(fieldName, '==', schoolId).get();
      if (fieldName === 'schoolId') {
        result.fsAlbumCountBySchoolId = albumSnap.size;
        if (!result.fsAlbumSample && albumSnap.size > 0) {
          const d = albumSnap.docs[0];
          result.fsAlbumSample = { docId: d.id, value: d.data() };
        }
        result.fsAlbumIdsSample = albumSnap.docs.slice(0, 5).map(d => d.id);
      } else {
        result.fsAlbumCountBySchoolCode = albumSnap.size;
        if (!result.fsAlbumSample && albumSnap.size > 0) {
          const d = albumSnap.docs[0];
          result.fsAlbumSample = { docId: d.id, value: d.data() };
        }
      }
    } catch (e) {
      console.log(`  ${RED('FS galleryAlbums query failed (' + fieldName + ')')}: ${e.message}`);
    }
    try {
      const mediaSnap = await fs.collection('galleryMedia')
        .where(fieldName, '==', schoolId).get();
      if (fieldName === 'schoolId') {
        result.fsMediaCountBySchoolId = mediaSnap.size;
        if (!result.fsMediaSample && mediaSnap.size > 0) {
          const d = mediaSnap.docs[0];
          result.fsMediaSample = { docId: d.id, value: d.data() };
        }
      } else {
        result.fsMediaCountBySchoolCode = mediaSnap.size;
        if (!result.fsMediaSample && mediaSnap.size > 0) {
          const d = mediaSnap.docs[0];
          result.fsMediaSample = { docId: d.id, value: d.data() };
        }
      }
    } catch (e) {
      console.log(`  ${RED('FS galleryMedia query failed (' + fieldName + ')')}: ${e.message}`);
    }
  }

  // ── 3. Print summary ─────────────────────────────────────────
  console.log(`  ${DIM('RTDB schoolKey used:')}        ${usedSchoolKey || '(none)'}`);
  console.log();
  console.log(`  RTDB Gallery/Albums:           ${BOLD(String(result.rtdbAlbumCount))}`);
  console.log(`  RTDB Gallery/Media (total):    ${BOLD(String(result.rtdbMediaCount))}`);
  console.log();
  console.log(`  FS galleryAlbums [schoolId=${schoolId}]:    ${BOLD(String(result.fsAlbumCountBySchoolId))}`);
  console.log(`  FS galleryAlbums [schoolCode=${schoolId}]:  ${BOLD(String(result.fsAlbumCountBySchoolCode))}`);
  console.log(`  FS galleryMedia  [schoolId=${schoolId}]:    ${BOLD(String(result.fsMediaCountBySchoolId))}`);
  console.log(`  FS galleryMedia  [schoolCode=${schoolId}]:  ${BOLD(String(result.fsMediaCountBySchoolCode))}`);

  if (!QUIET) {
    if (result.rtdbAlbumIdsSample.length > 0) {
      console.log(`\n  ${YEL('Sample RTDB album IDs:')}`);
      result.rtdbAlbumIdsSample.forEach(id => console.log(`    - ${id}`));
    }
    if (result.fsAlbumIdsSample.length > 0) {
      console.log(`\n  ${CYAN('Sample Firestore album doc IDs:')}`);
      result.fsAlbumIdsSample.forEach(id => console.log(`    - ${id}`));
    }
  }

  return result;
}

// ── Schema comparison ─────────────────────────────────────────────
function printSchemaSection(allResults) {
  let rtdbA = null, fsA = null, rtdbM = null, fsM = null;
  for (const r of allResults) {
    if (!rtdbA && r.rtdbAlbumSample) rtdbA = { ...r.rtdbAlbumSample, schoolId: r.schoolId };
    if (!fsA   && r.fsAlbumSample)   fsA   = { ...r.fsAlbumSample,   schoolId: r.schoolId };
    if (!rtdbM && r.rtdbMediaSample) rtdbM = { ...r.rtdbMediaSample, schoolId: r.schoolId };
    if (!fsM   && r.fsMediaSample)   fsM   = { ...r.fsMediaSample,   schoolId: r.schoolId };
  }

  console.log(`\n${HR2}\n  ${BOLD('SCHEMA COMPARISON (real data, not assumed)')}\n${HR2}`);

  // Album side-by-side
  console.log(`\n  ${BOLD(YEL('RTDB album'))} (legacy)`);
  if (rtdbA) {
    console.log(`  Path: ${DIM(rtdbA.path)}`);
    console.log(`  Value:`);
    console.log('    ' + JSON.stringify(rtdbA.value, null, 2).split('\n').join('\n    '));
  } else {
    console.log(`  ${DIM('(no RTDB album in any inspected school)')}`);
  }

  console.log(`\n  ${BOLD(GRN('Firestore album'))} (canonical)`);
  if (fsA) {
    console.log(`  Doc ID: ${DIM(fsA.docId)}`);
    console.log(`  Value:`);
    console.log('    ' + JSON.stringify(fsA.value, null, 2).split('\n').join('\n    '));
  } else {
    console.log(`  ${DIM('(no Firestore album in any inspected school)')}`);
  }

  // Media side-by-side
  console.log(`\n  ${BOLD(YEL('RTDB media'))} (legacy)`);
  if (rtdbM) {
    console.log(`  Path: ${DIM(rtdbM.path)}`);
    console.log(`  Value:`);
    console.log('    ' + JSON.stringify(rtdbM.value, null, 2).split('\n').join('\n    '));
  } else {
    console.log(`  ${DIM('(no RTDB media in any inspected school)')}`);
  }

  console.log(`\n  ${BOLD(GRN('Firestore media'))} (canonical)`);
  if (fsM) {
    console.log(`  Doc ID: ${DIM(fsM.docId)}`);
    console.log(`  Value:`);
    console.log('    ' + JSON.stringify(fsM.value, null, 2).split('\n').join('\n    '));
  } else {
    console.log(`  ${DIM('(no Firestore media in any inspected school)')}`);
  }

  // Field-union diff
  console.log(`\n  ${BOLD('Field map — albums')}`);
  const rtdbAFields = rtdbA ? Object.keys(rtdbA.value || {}) : [];
  const fsAFields   = fsA   ? Object.keys(fsA.value   || {}) : [];
  const allA = fieldUnion(rtdbA?.value, fsA?.value);
  if (allA.length === 0) {
    console.log(`    ${DIM('(no fields to compare — at least one side empty)')}`);
  } else {
    console.log(`    ${'Field'.padEnd(28)} ${'RTDB'.padEnd(8)} ${'FS'.padEnd(8)}`);
    for (const f of allA) {
      const inRtdb = rtdbAFields.includes(f) ? GRN('✓') : DIM('—');
      const inFs   = fsAFields.includes(f)   ? GRN('✓') : DIM('—');
      console.log(`    ${f.padEnd(28)} ${inRtdb.padEnd(8)} ${inFs.padEnd(8)}`);
    }
  }

  console.log(`\n  ${BOLD('Field map — media')}`);
  const rtdbMFields = rtdbM ? Object.keys(rtdbM.value || {}) : [];
  const fsMFields   = fsM   ? Object.keys(fsM.value   || {}) : [];
  const allM = fieldUnion(rtdbM?.value, fsM?.value);
  if (allM.length === 0) {
    console.log(`    ${DIM('(no fields to compare — at least one side empty)')}`);
  } else {
    console.log(`    ${'Field'.padEnd(28)} ${'RTDB'.padEnd(8)} ${'FS'.padEnd(8)}`);
    for (const f of allM) {
      const inRtdb = rtdbMFields.includes(f) ? GRN('✓') : DIM('—');
      const inFs   = fsMFields.includes(f)   ? GRN('✓') : DIM('—');
      console.log(`    ${f.padEnd(28)} ${inRtdb.padEnd(8)} ${inFs.padEnd(8)}`);
    }
  }

  // ID format observations
  console.log(`\n  ${BOLD('Doc-ID format observations')}`);
  console.log(`    RTDB album key example:        ${DIM(rtdbA?.albumId || '(none)')}`);
  console.log(`    Firestore album docId example: ${DIM(fsA?.docId || '(none)')}`);
  console.log(`    RTDB media key example:        ${DIM(rtdbM?.mediaId || '(none)')}`);
  console.log(`    Firestore media docId example: ${DIM(fsM?.docId || '(none)')}`);
}

// ── Risks summary ─────────────────────────────────────────────────
function printRiskSection(allResults) {
  console.log(`\n${HR2}\n  ${BOLD('RISKS DISCOVERED (real, from actual data)')}\n${HR2}\n`);

  const totals = allResults.reduce((acc, r) => ({
    rtdbAlbums: acc.rtdbAlbums + r.rtdbAlbumCount,
    rtdbMedia:  acc.rtdbMedia  + r.rtdbMediaCount,
    fsAlbumsBySchoolId:   acc.fsAlbumsBySchoolId   + r.fsAlbumCountBySchoolId,
    fsAlbumsBySchoolCode: acc.fsAlbumsBySchoolCode + r.fsAlbumCountBySchoolCode,
    fsMediaBySchoolId:    acc.fsMediaBySchoolId    + r.fsMediaCountBySchoolId,
    fsMediaBySchoolCode:  acc.fsMediaBySchoolCode  + r.fsMediaCountBySchoolCode,
  }), {
    rtdbAlbums: 0, rtdbMedia: 0,
    fsAlbumsBySchoolId: 0, fsAlbumsBySchoolCode: 0,
    fsMediaBySchoolId: 0, fsMediaBySchoolCode: 0,
  });

  console.log(`  Total RTDB albums seen:               ${totals.rtdbAlbums}`);
  console.log(`  Total RTDB media seen:                ${totals.rtdbMedia}`);
  console.log(`  Total FS albums (schoolId match):     ${totals.fsAlbumsBySchoolId}`);
  console.log(`  Total FS albums (schoolCode match):   ${totals.fsAlbumsBySchoolCode}`);
  console.log(`  Total FS media  (schoolId match):     ${totals.fsMediaBySchoolId}`);
  console.log(`  Total FS media  (schoolCode match):   ${totals.fsMediaBySchoolCode}`);

  // Highlight which field name resolves the data
  if (totals.fsAlbumsBySchoolId > 0 && totals.fsAlbumsBySchoolCode === 0) {
    console.log(`\n  ${GRN('• Firestore albums use field `schoolId` for school filter.')}`);
  } else if (totals.fsAlbumsBySchoolCode > 0 && totals.fsAlbumsBySchoolId === 0) {
    console.log(`\n  ${YEL('• Firestore albums use `schoolCode` (not `schoolId`).')}`);
  } else if (totals.fsAlbumsBySchoolId > 0 && totals.fsAlbumsBySchoolCode > 0) {
    console.log(`\n  ${YEL('• Mixed: some albums use `schoolId`, others use `schoolCode` — drift in writers.')}`);
  } else if (totals.rtdbAlbums > 0) {
    console.log(`\n  ${YEL('• Firestore has zero albums for the SCH_xxx form. Either field is named differently OR no admin has written galleryAlbums yet.')}`);
  }
}

// ── Main ──────────────────────────────────────────────────────────
async function main() {
  console.log(`\n${HR2}`);
  console.log(BOLD('  Phase C-2 — Gallery Inspector (read-only)'));
  console.log(`  ${DIM('RTDB Schools/.../Gallery   vs   Firestore galleryAlbums + galleryMedia')}`);
  if (FILTER_SCHOOL) console.log(`  School filter: ${BOLD(FILTER_SCHOOL)}`);
  console.log(HR2);

  const sysSchools = await rtdbGet('System/Schools');
  if (!sysSchools) {
    console.log(`\n  ${RED('No schools at System/Schools')}`);
    process.exit(1);
  }
  let schoolIds = Object.keys(sysSchools);
  if (FILTER_SCHOOL) {
    if (!schoolIds.includes(FILTER_SCHOOL)) {
      console.log(`\n  ${RED(`School "${FILTER_SCHOOL}" not in System/Schools.`)}`);
      process.exit(1);
    }
    schoolIds = [FILTER_SCHOOL];
  }
  console.log(`\n  Inspecting ${CYAN(String(schoolIds.length))} school(s)…`);

  const results = [];
  for (const id of schoolIds) {
    try { results.push(await inspectSchool(id)); }
    catch (e) {
      console.log(`\n  ${RED(`School ${id} failed:`)} ${e.message}`);
    }
  }

  printSchemaSection(results);
  printRiskSection(results);

  console.log(`\n${HR2}\n  ${DIM('Done. No writes performed.')}\n${HR2}\n`);
  process.exit(0);
}

main().catch(e => { console.error('\nFATAL:', e); process.exit(1); });
