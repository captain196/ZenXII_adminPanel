// Inspect studentFlags Firestore docs for a given student.
// Usage:  node scripts/inspect_red_flags_for_student.js <schoolId> <studentId>
// Example: node scripts/inspect_red_flags_for_student.js SCH_D94FE8F7AD STU0001
//
// Why this exists: the parent app's Firestore listener queries
//   schoolId == X AND studentId == Y
// If parents see no flags, it's almost always one of:
//   (a) flag.studentId differs from what the parent app sends,
//   (b) flag.schoolId differs (vs schoolCode),
//   (c) Firestore rules deny the read (auth claim mismatch — separate test),
//   (d) the doc has status=='deleted'.
// This script reads as a service account (bypasses rules) so it answers (a)
// (b) (d) definitively. Rule denial only shows up via the mobile app logs.
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(
  __dirname, '..', 'application', 'config',
  'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'
));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = process.argv[2] || 'SCH_D94FE8F7AD';
const STUDENT = process.argv[3] || 'STU0001';

(async () => {
  const out = { schoolFilter: SCHOOL, studentFilter: STUDENT };

  // ── 1. Verify the student doc exists and grab its identity fields ──
  const stuDoc = await db.collection('students').doc(`${SCHOOL}_${STUDENT}`).get();
  if (!stuDoc.exists) {
    out.studentDocStatus = `MISSING — students/${SCHOOL}_${STUDENT} doesn't exist`;
  } else {
    const x = stuDoc.data();
    out.studentDocStatus = 'found';
    out.studentDoc = {
      docId:     stuDoc.id,
      userId:    x.userId,
      studentId: x.studentId,
      name:      x.name || x.Name,
      className: x.className || x.Class,
      section:   x.section || x.Section,
      schoolId:  x.schoolId,
      status:    x.status || x.Status,
    };
  }

  // ── 2. Query exactly what the parent app queries ──
  const exactSnap = await db.collection('studentFlags')
    .where('schoolId', '==', SCHOOL)
    .where('studentId', '==', STUDENT)
    .get();
  out.exactMatchCount = exactSnap.size;
  out.exactMatches = [];
  exactSnap.forEach(d => {
    const x = d.data();
    out.exactMatches.push({
      docId:        d.id,
      flagId:       x.flagId,
      schoolId:     x.schoolId,
      schoolCode:   x.schoolCode,
      studentId:    x.studentId,
      studentName:  x.studentName,
      className:    x.className,
      section:      x.section,
      status:       x.status,
      createdByRole: x.createdByRole,
      createdAtMs:  x.createdAtMs,
    });
  });

  // ── 3. Try schoolCode fallback (admin's _collect_all_flags does OR) ──
  const codeSnap = await db.collection('studentFlags')
    .where('schoolCode', '==', SCHOOL)
    .where('studentId', '==', STUDENT)
    .get();
  out.schoolCodeMatchCount = codeSnap.size;
  out.schoolCodeOnlyMatches = []; // ones present in this query but NOT in #2
  const exactDocIds = new Set(out.exactMatches.map(f => f.docId));
  codeSnap.forEach(d => {
    if (exactDocIds.has(d.id)) return;
    const x = d.data();
    out.schoolCodeOnlyMatches.push({
      docId: d.id, flagId: x.flagId,
      schoolId: x.schoolId, schoolCode: x.schoolCode,
      studentId: x.studentId, status: x.status,
    });
  });

  // ── 4. Sanity: any flag in this school regardless of student ──
  const schoolSnap = await db.collection('studentFlags')
    .where('schoolId', '==', SCHOOL)
    .limit(20)
    .get();
  out.allSchoolFlagsSample = [];
  schoolSnap.forEach(d => {
    const x = d.data();
    out.allSchoolFlagsSample.push({
      docId:     d.id,
      studentId: x.studentId,
      studentName: x.studentName,
      status:    x.status,
      createdByRole: x.createdByRole,
      teacherName: x.teacherName,
      createdAtMs: x.createdAtMs,
    });
  });

  console.log(JSON.stringify(out, null, 2));
  process.exit(0);
})().catch(e => {
  console.error('Inspection failed:', e);
  process.exit(1);
});
