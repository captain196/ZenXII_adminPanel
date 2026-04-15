// Seeds April 2026 staffAttendanceSummary docs for payroll test.
// Each dayWise string = 30 chars, one per calendar day.
// Legend: P=present, A=absent, L=leave, H=holiday/weekend, T=tardy, V=vacant
// Weekends for April 2026: Sundays 5/12/19/26 + off-Saturdays 11/25 = 6 H-days
const admin = require('firebase-admin');
const path = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

const SCHOOL = 'SCH_D94FE8F7AD';
const SESSION = '2026-27';
const MONTH_KEY = '2026-04';
const MONTH_LABEL = 'April 2026';
const DRY_RUN = process.argv.includes('--dry-run');

// day:   1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30
// dow:   W T F S S M T W T F  S  S  M  T  W  T  F  S  S  M  T  W  T  F  S  S  M  T  W  T
// H-days: 5,11,12,19,25,26
const scenarios = [
  { staffId: 'STA0001', name: 'Vipul Tiwari',     dayWise: 'PPPPHPAPPPHHLAPPPPHPPPPPHHPPPP' }, // 2A, 1L (matches approved CL 13 Apr)
  { staffId: 'STA0002', name: 'Priya Sharma',     dayWise: 'PPPPHPPPPPHHPPPPPPHPPPPPHHPPPP' }, // full present
  { staffId: 'STA0003', name: 'Rahul Verma',      dayWise: 'PPAPHPPPAPHHPPPPAPHPPPPPHHPPPP' }, // 3A
  { staffId: 'STA0004', name: 'Sunita Devi',      dayWise: 'PAPPHPAPPPHHPAPPPPHPAPPPHHPAPP' }, // 5A
  { staffId: 'STA0005', name: 'Amit Kumar Singh', dayWise: 'PPPPHPPPPAHHPPPPPPHPPPPPHHPPPP' }, // 1A
  { staffId: 'STA0006', name: 'Neha Gupta',       dayWise: 'PPPPHPPPPPHHPPPPPPHPPPPPHHPPPP' }, // full present
  { staffId: 'STA0007', name: 'Deepak Yadav',     dayWise: 'PPTPHAPPPPHHPPPPPPHAPPPPHHPPPP' }, // 2A, 1T
];

function count(str, ch) { return (str.match(new RegExp(ch, 'g')) || []).length; }

(async () => {
  for (const s of scenarios) {
    const dw = s.dayWise;
    if (dw.length !== 30) { console.error(`BAD LEN ${s.staffId}: ${dw.length}`); process.exit(1); }
    const summary = {
      schoolId: SCHOOL,
      session: SESSION,
      staffId: s.staffId,
      type: 'staff',
      month: MONTH_KEY,
      monthLabel: MONTH_LABEL,
      dayWise: dw,
      present:  count(dw, 'P'),
      absent:   count(dw, 'A'),
      leave:    count(dw, 'L'),
      holiday:  count(dw, 'H'),
      tardy:    count(dw, 'T'),
      vacation: count(dw, 'V'),
      updatedAt: new Date().toISOString(),
    };
    const docId = `${SCHOOL}_${s.staffId}_${MONTH_KEY}`;
    console.log(`${s.staffId} ${s.name.padEnd(20)} P=${summary.present} A=${summary.absent} L=${summary.leave} H=${summary.holiday} T=${summary.tardy}`);
    if (!DRY_RUN) {
      await db.collection('staffAttendanceSummary').doc(docId).set(summary, { merge: true });
    }
  }
  console.log(DRY_RUN ? '\n(DRY RUN)' : '\n✅ 7 summaries written');
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
