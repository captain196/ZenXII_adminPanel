// Seed test classes + students so the Teacher app has multiple class
// chips to exercise the debounce (Test 3). Adds:
//   Class 9th  / Section A  — 3 students
//   Class 9th  / Section B  — 3 students
//   Class 10th / Section A  — 3 students
//
// Also writes feeStructures (fee chart) per section with slightly
// different amounts per class so it's visually obvious which class
// data is loaded on the teacher screen.
//
//   node scripts/seed_test_classes.js   (idempotent — safe to re-run)

const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL  = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const now     = new Date().toISOString();

// Fee chart spec — same heads, scaled by class level.
const CHARTS = [
  {
    className: 'Class 9th', section: 'Section A',
    feeHeads: [
      { name: 'Tuition Fee',  amount: 2200, frequency: 'monthly' },
      { name: 'Computer Fee', amount: 500,  frequency: 'monthly' },
      { name: 'Library Fee',  amount: 300,  frequency: 'monthly' },
      { name: 'Annual Fee',   amount: 1200, frequency: 'annual'  },
    ],
  },
  {
    className: 'Class 9th', section: 'Section B',
    feeHeads: [
      { name: 'Tuition Fee',  amount: 2200, frequency: 'monthly' },
      { name: 'Computer Fee', amount: 500,  frequency: 'monthly' },
      { name: 'Library Fee',  amount: 300,  frequency: 'monthly' },
      { name: 'Annual Fee',   amount: 1200, frequency: 'annual'  },
    ],
  },
  {
    className: 'Class 10th', section: 'Section A',
    feeHeads: [
      { name: 'Tuition Fee',  amount: 2500, frequency: 'monthly' },
      { name: 'Computer Fee', amount: 600,  frequency: 'monthly' },
      { name: 'Library Fee',  amount: 300,  frequency: 'monthly' },
      { name: 'Annual Fee',   amount: 1500, frequency: 'annual'  },
    ],
  },
];

const STUDENTS = [
  // Class 9th / A
  { id: 'STU_TEST_09A_01', name: 'Amit Sharma',   father: 'Rajesh Sharma',   className: 'Class 9th',  section: 'Section A', roll: '09A-01' },
  { id: 'STU_TEST_09A_02', name: 'Priya Gupta',   father: 'Vikram Gupta',    className: 'Class 9th',  section: 'Section A', roll: '09A-02' },
  { id: 'STU_TEST_09A_03', name: 'Rohit Verma',   father: 'Sunil Verma',     className: 'Class 9th',  section: 'Section A', roll: '09A-03' },
  // Class 9th / B
  { id: 'STU_TEST_09B_01', name: 'Sneha Patel',   father: 'Mahesh Patel',    className: 'Class 9th',  section: 'Section B', roll: '09B-01' },
  { id: 'STU_TEST_09B_02', name: 'Karan Mehta',   father: 'Anil Mehta',      className: 'Class 9th',  section: 'Section B', roll: '09B-02' },
  { id: 'STU_TEST_09B_03', name: 'Neha Singh',    father: 'Ravi Singh',      className: 'Class 9th',  section: 'Section B', roll: '09B-03' },
  // Class 10th / A
  { id: 'STU_TEST_10A_01', name: 'Arjun Kumar',   father: 'Dinesh Kumar',    className: 'Class 10th', section: 'Section A', roll: '10A-01' },
  { id: 'STU_TEST_10A_02', name: 'Riya Joshi',    father: 'Prakash Joshi',   className: 'Class 10th', section: 'Section A', roll: '10A-02' },
  { id: 'STU_TEST_10A_03', name: 'Vikash Yadav',  father: 'Ashok Yadav',     className: 'Class 10th', section: 'Section A', roll: '10A-03' },
];

async function seedCharts() {
  for (const c of CHARTS) {
    const docId = `${SCHOOL}_${SESSION}_${c.className}_${c.section}`;
    await db.collection('feeStructures').doc(docId).set({
      schoolId:   SCHOOL,
      session:    SESSION,
      className:  c.className,
      section:    c.section,
      feeHeads:   c.feeHeads,
      updatedAt:  now,
      createdBy:  'seed_test_classes_script',
    }, { merge: true });
    console.log(`✓ chart: ${c.className} / ${c.section} (${c.feeHeads.length} heads)`);
  }
}

async function seedStudents() {
  for (const s of STUDENTS) {
    const docId = `${SCHOOL}_${s.id}`;
    await db.collection('students').doc(docId).set({
      schoolId:    SCHOOL,
      schoolCode:  SCHOOL,
      session:     SESSION,
      studentId:   s.id,
      userId:      s.id,
      name:        s.name,
      fatherName:  s.father,
      className:   s.className,
      section:     s.section,
      sectionKey:  `${s.className.replace(/\s+/g,'')}_${s.section.replace(/\s+/g,'')}`,
      rollNo:      s.roll,
      phone:       '',
      email:       '',
      status:      'active',
      monthFee:    {},
      createdAt:   now,
      updatedAt:   now,
      source:      'seed_test_classes_script',
    }, { merge: true });
    console.log(`✓ student: ${s.id} (${s.name}) → ${s.className} / ${s.section}`);
  }
}

(async () => {
  await seedCharts();
  await seedStudents();
  console.log(`\nDone. ${CHARTS.length} fee charts + ${STUDENTS.length} students seeded.`);
})().then(()=>process.exit(0)).catch(e=>{console.error(e);process.exit(1);});
