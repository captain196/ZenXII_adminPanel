// Give every existing teacher an assignment to the three new classes
// (Class 9th A, 9th B, 10th A) so whichever teacher is logged into the
// Teacher app for testing will see all 4 class chips — required to
// exercise the class-switch debounce (Test 3).
//
// Idempotent — re-running is a no-op (merge=true).

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL  = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const now     = new Date().toISOString();

const TEACHERS = [
  { staffId: 'STA0001', name: 'Vipul TIwari',  subject: 'Class Teacher Duty', code: '999' },
  { staffId: 'STA0002', name: 'Priya Sharma',  subject: 'Maths',              code: '901' },
  { staffId: 'STA0003', name: 'Rahul Verma',   subject: 'English',            code: '902' },
  { staffId: 'STA0004', name: 'Sunita Devi',   subject: 'Science',            code: '903' },
  { staffId: 'STA0005', name: 'Amit Kumar',    subject: 'Social Studies',     code: '904' },
  { staffId: 'STA0006', name: 'Neha Joshi',    subject: 'Hindi',              code: '905' },
  { staffId: 'STA0007', name: 'Ravi Mehta',    subject: 'Computer',           code: '906' },
];

const NEW_SECTIONS = [
  { className: 'Class 9th',  section: 'Section A', classOrder: 9,  sectionCode: 'A' },
  { className: 'Class 9th',  section: 'Section B', classOrder: 9,  sectionCode: 'B' },
  { className: 'Class 10th', section: 'Section A', classOrder: 10, sectionCode: 'A' },
];

(async () => {
  let written = 0;
  for (const cs of NEW_SECTIONS) {
    for (const t of TEACHERS) {
      const classSlug   = cs.className.replace(/\s+/g, '_');
      const sectionSlug = cs.section.replace(/\s+/g, '_');
      const docId = `${SCHOOL}_${SESSION}_${classSlug}_${sectionSlug}_${t.code}`;
      await db.collection('subjectAssignments').doc(docId).set({
        schoolId:       SCHOOL,
        session:        SESSION,
        className:      cs.className,
        section:        cs.section,
        sectionCode:    cs.sectionCode,
        sectionKey:     `${cs.className}/${cs.section}`,
        classOrder:     cs.classOrder,
        teacherId:      t.staffId,
        teacherName:    t.name,
        subjectCode:    t.code,
        subjectName:    t.subject,
        category:       'General',
        isClassTeacher: t.staffId === 'STA0001',  // STA0001 is class teacher
        periodsPerWeek: 6,
        updatedAt:      now,
        source:         'seed_teacher_assignments_script',
      }, { merge: true });
      written++;
    }
    console.log(`✓ ${cs.className} / ${cs.section} — assigned all ${TEACHERS.length} teachers`);
  }
  console.log(`\nDone. ${written} assignment docs written.`);
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
