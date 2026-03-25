<?php
/**
 * Seed Timetable, Teacher Duties, and Exam Marks into Firebase
 * School: SCH_9738C22243 | Session: 2026-27
 *
 * Usage:  php seed_timetable_duties_exams.php
 */
error_reporting(E_ERROR);

require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

// ─── Firebase connection ─────────────────────────────────────────────────────
$serviceAccountPath = __DIR__ . '/application/config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
$databaseUri        = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

$factory  = (new Factory)
    ->withServiceAccount($serviceAccountPath)
    ->withDatabaseUri($databaseUri);

$database = $factory->createDatabase();

$school  = 'SCH_9738C22243';
$session = '2026-27';
$basePath = "Schools/{$school}/{$session}";

// Counters for summary
$stats = ['timetable' => 0, 'duties' => 0, 'exams' => 0, 'marks' => 0];

echo "========================================================\n";
echo " Firebase Seeder: Timetable / Duties / Exams\n";
echo " School : {$school}\n";
echo " Session: {$session}\n";
echo "========================================================\n\n";

// ═══════════════════════════════════════════════════════════════════════════════
// 1. TIMETABLE
// ═══════════════════════════════════════════════════════════════════════════════
echo "--- [1/3] SEEDING TIMETABLE ---\n";

$days   = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$periods = [
    ['time' => '08:00-08:40'],
    ['time' => '08:40-09:20'],
    ['time' => '09:20-10:00'],
    // BREAK 10:00-10:20
    ['time' => '10:20-11:00'],
    ['time' => '11:00-11:40'],
    ['time' => '11:40-12:20'],
    // LUNCH 12:20-1:00
    ['time' => '01:00-01:40'],
    ['time' => '01:40-02:20'],
];

$classes  = ['9th A', '9th B', '10th A', '8th A', '8th B'];
$rooms    = ['101', '102', '103', '104', '105', '106', '107', '108', '109', '110'];

// Teacher definitions: id => [name, [subjects], [classes they teach]]
$teachers = [
    'STA0002' => ['name' => 'Priya Verma',     'subjects' => ['Mathematics'],                                  'classes' => ['9th A', '9th B', '10th A', '8th A']],
    'STA0003' => ['name' => 'Rajesh Kumar',     'subjects' => ['Science', 'Chemistry'],                         'classes' => ['9th A', '9th B', '10th A']],
    'STA0004' => ['name' => 'Sunita Devi',      'subjects' => ['English'],                                      'classes' => ['9th A', '9th B', '10th A', '8th A', '8th B']],
    'STA0005' => ['name' => 'Vikram Singh',     'subjects' => ['Hindi'],                                        'classes' => ['9th A', '9th B', '8th A', '8th B']],
    'STA0006' => ['name' => 'Meena Kumari',     'subjects' => ['Social Science', 'Geography', 'History'],       'classes' => ['9th A', '9th B', '10th A']],
    'TEA0002' => ['name' => 'Ravindra Jadeja',  'subjects' => ['Physical Education'],                           'classes' => ['9th A', '9th B', '10th A', '8th A', '8th B']],
    'TEA0003' => ['name' => 'Mahendra Singh',   'subjects' => ['Computer Science'],                             'classes' => ['9th A', '9th B', '10th A']],
];

// Build deterministic timetable.
// Strategy: for each day, fill 8 period slots across 5 classes = 40 class-period slots.
// We cycle through teachers giving them classes from their allowed list.

// Pre-build a master schedule: $masterSchedule[day][periodIdx] = array of {teacherId, subject, class, room}
$masterSchedule = [];

// For reproducibility, use a seeded assignment approach
// Each class needs 8 periods per day. We'll assign teachers to class-period slots.
// Class-centric: for each class, for each period on a day, pick a teacher.

// Subject distribution per class (what subjects a class has):
$classSubjects = [
    '9th A'  => ['Mathematics', 'Science', 'English', 'Hindi', 'Social Science', 'Physical Education', 'Computer Science', 'Chemistry'],
    '9th B'  => ['Mathematics', 'Science', 'English', 'Hindi', 'Social Science', 'Physical Education', 'Computer Science', 'Geography'],
    '10th A' => ['Mathematics', 'Science', 'English', 'Social Science', 'Physical Education', 'Computer Science', 'Chemistry', 'History'],
    '8th A'  => ['Mathematics', 'English', 'Hindi', 'Physical Education', 'Mathematics', 'English', 'Hindi', 'Physical Education'],
    '8th B'  => ['English', 'Hindi', 'Physical Education', 'English', 'Hindi', 'Physical Education', 'English', 'Hindi'],
];

// Map subject -> teacherId
$subjectTeacher = [
    'Mathematics'        => 'STA0002',
    'Science'            => 'STA0003',
    'Chemistry'          => 'STA0003',
    'English'            => 'STA0004',
    'Hindi'              => 'STA0005',
    'Social Science'     => 'STA0006',
    'Geography'          => 'STA0006',
    'History'            => 'STA0006',
    'Physical Education' => 'TEA0002',
    'Computer Science'   => 'TEA0003',
];

// Build timetable per teacher
$teacherTimetable = [];
foreach (array_keys($teachers) as $tid) {
    foreach ($days as $day) {
        $teacherTimetable[$tid][$day] = [];
    }
}

// Room assignment: each class gets a base room
$classRoom = [
    '9th A'  => '101',
    '9th B'  => '102',
    '10th A' => '103',
    '8th A'  => '104',
    '8th B'  => '105',
];

// For labs
$labRoom = [
    'Science'           => '108',
    'Chemistry'         => '108',
    'Computer Science'  => '109',
    'Physical Education'=> '110',
];

// Day-specific subject rotations for each class to create variety
// Each class has 8 periods per day; we define which subjects go in which period per day.
$classSchedules = [];

// 9th A schedule: good mix
$classSchedules['9th A'] = [
    'Monday'    => ['Mathematics', 'Mathematics', 'Science',            'English', 'Hindi', 'Social Science',    'Computer Science', 'Physical Education'],
    'Tuesday'   => ['English', 'Science', 'Mathematics',               'Hindi', 'Social Science', 'Chemistry',  'Physical Education', 'Computer Science'],
    'Wednesday' => ['Hindi', 'Mathematics', 'English',                 'Science', 'Social Science', 'Mathematics', 'Computer Science', 'Physical Education'],
    'Thursday'  => ['Science', 'English', 'Hindi',                     'Mathematics', 'Computer Science', 'Social Science', 'Physical Education', 'Chemistry'],
    'Friday'    => ['Mathematics', 'Hindi', 'Social Science',          'English', 'Science', 'Computer Science', 'Chemistry', 'Physical Education'],
    'Saturday'  => ['English', 'Mathematics', 'Physical Education',    'Science', 'Hindi', 'Social Science',     'Computer Science', 'Mathematics'],
];

// 9th B schedule
$classSchedules['9th B'] = [
    'Monday'    => ['English', 'Hindi', 'Mathematics',                 'Science', 'Social Science', 'Physical Education', 'Computer Science', 'Geography'],
    'Tuesday'   => ['Mathematics', 'English', 'Science',               'Social Science', 'Hindi', 'Computer Science',     'Geography', 'Physical Education'],
    'Wednesday' => ['Science', 'Mathematics', 'Hindi',                 'English', 'Physical Education', 'Social Science',  'Computer Science', 'Geography'],
    'Thursday'  => ['Hindi', 'Science', 'English',                     'Mathematics', 'Geography', 'Computer Science',     'Social Science', 'Physical Education'],
    'Friday'    => ['Social Science', 'English', 'Mathematics',        'Hindi', 'Science', 'Physical Education',           'Geography', 'Computer Science'],
    'Saturday'  => ['Mathematics', 'Hindi', 'Science',                 'English', 'Computer Science', 'Physical Education', 'Social Science', 'Geography'],
];

// 10th A schedule
$classSchedules['10th A'] = [
    'Monday'    => ['Mathematics', 'Science', 'English',               'Social Science', 'Chemistry', 'Computer Science',  'Physical Education', 'History'],
    'Tuesday'   => ['English', 'Mathematics', 'Chemistry',             'Science', 'History', 'Physical Education',         'Computer Science', 'Social Science'],
    'Wednesday' => ['Science', 'English', 'Mathematics',               'Computer Science', 'Social Science', 'Chemistry',  'History', 'Physical Education'],
    'Thursday'  => ['Chemistry', 'Mathematics', 'Science',             'English', 'Physical Education', 'History',         'Social Science', 'Computer Science'],
    'Friday'    => ['Mathematics', 'Chemistry', 'English',             'Science', 'Computer Science', 'Social Science',    'Physical Education', 'History'],
    'Saturday'  => ['English', 'Science', 'Mathematics',               'History', 'Chemistry', 'Physical Education',       'Computer Science', 'Social Science'],
];

// 8th A schedule (fewer subjects, some repeat)
$classSchedules['8th A'] = [
    'Monday'    => ['Mathematics', 'English', 'Hindi',                 'Physical Education', 'Mathematics', 'English',     'Hindi', 'Physical Education'],
    'Tuesday'   => ['English', 'Mathematics', 'Physical Education',    'Hindi', 'English', 'Mathematics',                  'Physical Education', 'Hindi'],
    'Wednesday' => ['Hindi', 'English', 'Mathematics',                 'Physical Education', 'Hindi', 'Mathematics',       'English', 'Physical Education'],
    'Thursday'  => ['Mathematics', 'Hindi', 'English',                 'Mathematics', 'Physical Education', 'Hindi',       'English', 'Physical Education'],
    'Friday'    => ['English', 'Physical Education', 'Mathematics',    'Hindi', 'Mathematics', 'English',                  'Physical Education', 'Hindi'],
    'Saturday'  => ['Hindi', 'Mathematics', 'English',                 'Physical Education', 'Mathematics', 'Hindi',       'English', 'Physical Education'],
];

// 8th B schedule
$classSchedules['8th B'] = [
    'Monday'    => ['English', 'Hindi', 'Physical Education',          'English', 'Hindi', 'Physical Education',           'English', 'Hindi'],
    'Tuesday'   => ['Hindi', 'Physical Education', 'English',          'Hindi', 'English', 'Physical Education',           'Hindi', 'English'],
    'Wednesday' => ['Physical Education', 'English', 'Hindi',          'Physical Education', 'English', 'Hindi',           'English', 'Physical Education'],
    'Thursday'  => ['English', 'Physical Education', 'Hindi',          'English', 'Hindi', 'Physical Education',           'Hindi', 'English'],
    'Friday'    => ['Hindi', 'English', 'Physical Education',          'Hindi', 'Physical Education', 'English',           'English', 'Hindi'],
    'Saturday'  => ['Physical Education', 'Hindi', 'English',          'Physical Education', 'Hindi', 'English',           'Hindi', 'Physical Education'],
];

// Now build teacher timetables from the class schedules
foreach ($days as $day) {
    foreach ($classes as $class) {
        $daySchedule = $classSchedules[$class][$day];
        foreach ($daySchedule as $periodIdx => $subject) {
            $tid = $subjectTeacher[$subject] ?? null;
            if ($tid === null) continue;

            // Determine room
            $room = $labRoom[$subject] ?? $classRoom[$class];

            $entry = [
                'subject' => $subject,
                'class'   => $class,
                'section' => substr($class, -1),  // A or B
                'room'    => $room,
                'time'    => $periods[$periodIdx]['time'],
            ];

            $teacherTimetable[$tid][$day][] = $entry;
        }
    }
}

// Upload timetables to Firebase
foreach ($teacherTimetable as $tid => $dayData) {
    $path = "{$basePath}/Teachers/{$tid}/Timetable";
    $database->getReference($path)->set($dayData);
    $stats['timetable']++;

    $totalPeriods = 0;
    foreach ($dayData as $d => $entries) {
        $totalPeriods += count($entries);
    }
    echo "  [OK] {$tid} ({$teachers[$tid]['name']}) — {$totalPeriods} periods/week\n";
}

echo "  Timetable seeded for {$stats['timetable']} teachers.\n\n";

// ═══════════════════════════════════════════════════════════════════════════════
// 2. TEACHER DUTIES
// ═══════════════════════════════════════════════════════════════════════════════
echo "--- [2/3] SEEDING TEACHER DUTIES ---\n";

$dutiesData = [
    'STA0002' => [
        'ClassTeacher' => [
            "Class 9th 'A'" => true,
        ],
        'SubjectTeacher' => [
            "Class 9th 'A'"  => ['Mathematics' => '08:00-09:20'],
            "Class 9th 'B'"  => ['Mathematics' => '08:40-10:00'],
            "Class 10th 'A'" => ['Mathematics' => '09:20-11:00'],
            "Class 8th 'A'"  => ['Mathematics' => '10:20-11:40'],
        ],
    ],
    'STA0003' => [
        'SubjectTeacher' => [
            "Class 9th 'A'"  => ['Science' => '09:20-10:00', 'Chemistry' => '11:00-11:40'],
            "Class 9th 'B'"  => ['Science' => '10:20-11:00'],
            "Class 10th 'A'" => ['Science' => '08:00-08:40', 'Chemistry' => '11:40-12:20'],
        ],
    ],
    'STA0004' => [
        'ClassTeacher' => [
            "Class 9th 'B'" => true,
        ],
        'SubjectTeacher' => [
            "Class 9th 'A'"  => ['English' => '10:20-11:00'],
            "Class 9th 'B'"  => ['English' => '08:00-08:40'],
            "Class 10th 'A'" => ['English' => '09:20-10:00'],
            "Class 8th 'A'"  => ['English' => '08:40-09:20'],
            "Class 8th 'B'"  => ['English' => '08:00-08:40'],
        ],
    ],
    'STA0005' => [
        'SubjectTeacher' => [
            "Class 9th 'A'"  => ['Hindi' => '11:00-11:40'],
            "Class 9th 'B'"  => ['Hindi' => '08:40-09:20'],
            "Class 8th 'A'"  => ['Hindi' => '09:20-10:00'],
            "Class 8th 'B'"  => ['Hindi' => '08:40-09:20'],
        ],
    ],
    'STA0006' => [
        'ClassTeacher' => [
            "Class 10th 'A'" => true,
        ],
        'SubjectTeacher' => [
            "Class 9th 'A'"  => ['Social Science' => '11:40-12:20', 'Geography' => '01:00-01:40', 'History' => '01:40-02:20'],
            "Class 9th 'B'"  => ['Social Science' => '10:20-11:00', 'Geography' => '01:40-02:20'],
            "Class 10th 'A'" => ['Social Science' => '10:20-11:00', 'History' => '01:00-01:40'],
        ],
    ],
    'TEA0003' => [
        'SubjectTeacher' => [
            "Class 9th 'A'"  => ['Computer Science' => '01:00-01:40'],
            "Class 9th 'B'"  => ['Computer Science' => '01:40-02:20'],
            "Class 10th 'A'" => ['Computer Science' => '11:40-12:20'],
        ],
    ],
];

foreach ($dutiesData as $tid => $duties) {
    $path = "{$basePath}/Teachers/{$tid}/Duties";
    $database->getReference($path)->set($duties);
    $stats['duties']++;

    $roles = [];
    if (isset($duties['ClassTeacher'])) $roles[] = 'ClassTeacher(' . implode(',', array_keys($duties['ClassTeacher'])) . ')';
    if (isset($duties['SubjectTeacher'])) $roles[] = 'SubjectTeacher(' . count($duties['SubjectTeacher']) . ' classes)';
    echo "  [OK] {$tid} — " . implode(', ', $roles) . "\n";
}

echo "  Duties seeded for {$stats['duties']} teachers.\n\n";

// ═══════════════════════════════════════════════════════════════════════════════
// 3. EXAMS & MARKS
// ═══════════════════════════════════════════════════════════════════════════════
echo "--- [3/3] SEEDING EXAMS & MARKS ---\n";

// 3A. Exam config
$exams = [
    'EXAM001' => [
        'name'   => 'Unit Test 1',
        'type'   => 'Unit Test',
        'status' => 'Closed',
        'date'   => '2026-01-15',
        'maxTheory'    => 80,
        'maxPractical' => 20,
        'maxTotal'     => 100,
    ],
    'EXAM002' => [
        'name'   => 'Mid-Term Exam',
        'type'   => 'Mid-Term',
        'status' => 'Closed',
        'date'   => '2026-02-20',
        'maxTheory'    => 80,
        'maxPractical' => 20,
        'maxTotal'     => 100,
    ],
    'EXAM003' => [
        'name'   => 'Unit Test 2',
        'type'   => 'Unit Test',
        'status' => 'Upcoming',
        'date'   => '2026-04-10',
        'maxTheory'    => 80,
        'maxPractical' => 20,
        'maxTotal'     => 100,
    ],
];

$examConfigPath = "Schools/{$school}/Config/Exams";
foreach ($exams as $examId => $examData) {
    $database->getReference("{$examConfigPath}/{$examId}")->set($examData);
    $stats['exams']++;
    echo "  [OK] Exam {$examId}: {$examData['name']} ({$examData['status']})\n";
}

// 3B. Marks for EXAM001 and EXAM002, Class 9th Section A
$students = ['STU0004', 'STU0005', 'STU0006', 'STU0007', 'STU0008', 'STU0030', 'STU0031'];
$subjects = ['Mathematics', 'Science', 'English', 'Hindi', 'Social Science', 'Computer Science'];

// Student profiles for score generation:
// STU0004 (Yuvraj) — average-to-good (60-80 range)
// STU0005 (Priya)  — topper (75-80 theory, 17-20 practical)
// STU0006 (Rahul)  — struggles (25-45 theory, 8-14 practical)
// STU0007           — good student (65-75)
// STU0008           — slightly below average (45-60)
// STU0030           — average (50-65)
// STU0031           — above average (60-72)

$studentProfiles = [
    'STU0004' => ['theoryMin' => 52, 'theoryMax' => 70, 'practMin' => 13, 'practMax' => 18, 'label' => 'Yuvraj (avg-good)'],
    'STU0005' => ['theoryMin' => 68, 'theoryMax' => 78, 'practMin' => 17, 'practMax' => 20, 'label' => 'Priya (topper)'],
    'STU0006' => ['theoryMin' => 22, 'theoryMax' => 42, 'practMin' => 7,  'practMax' => 14, 'label' => 'Rahul (struggles)'],
    'STU0007' => ['theoryMin' => 58, 'theoryMax' => 72, 'practMin' => 15, 'practMax' => 19, 'label' => 'Good student'],
    'STU0008' => ['theoryMin' => 38, 'theoryMax' => 55, 'practMin' => 10, 'practMax' => 16, 'label' => 'Below avg'],
    'STU0030' => ['theoryMin' => 42, 'theoryMax' => 60, 'practMin' => 12, 'practMax' => 17, 'label' => 'Average'],
    'STU0031' => ['theoryMin' => 52, 'theoryMax' => 68, 'practMin' => 14, 'practMax' => 18, 'label' => 'Above avg'],
];

// Subject-specific modifiers (some students do better/worse in certain subjects)
$subjectModifiers = [
    'STU0004' => ['Mathematics' => 5, 'Hindi' => -3, 'Science' => 2, 'English' => -2, 'Social Science' => 0, 'Computer Science' => 3],
    'STU0005' => ['Mathematics' => 2, 'Science' => 1, 'English' => 0, 'Hindi' => -1, 'Social Science' => 0, 'Computer Science' => 2],
    'STU0006' => ['Mathematics' => -5, 'Science' => -3, 'English' => 2, 'Hindi' => 5, 'Social Science' => 3, 'Computer Science' => -4],
    'STU0007' => ['Mathematics' => 3, 'Science' => 2, 'English' => -1, 'Hindi' => 0, 'Social Science' => -2, 'Computer Science' => 4],
    'STU0008' => ['Mathematics' => -3, 'Science' => 0, 'English' => 2, 'Hindi' => 1, 'Social Science' => -1, 'Computer Science' => -2],
    'STU0030' => ['Mathematics' => 0, 'Science' => 2, 'English' => -1, 'Hindi' => 3, 'Social Science' => 1, 'Computer Science' => -3],
    'STU0031' => ['Mathematics' => 2, 'Science' => -1, 'English' => 3, 'Hindi' => 0, 'Social Science' => -2, 'Computer Science' => 1],
];

// Exam difficulty modifier (Mid-Term is slightly harder)
$examDifficultyMod = [
    'EXAM001' => 0,
    'EXAM002' => -3,
];

$marksBasePath = "Schools/{$school}/{$session}/Class 9th/A/Marks/Exams";

echo "\n  Generating marks for Class 9th Section A...\n";

// Use a big batch update per exam to reduce HTTP calls
foreach (['EXAM001', 'EXAM002'] as $examId) {
    $examMarks = [];

    foreach ($subjects as $subject) {
        foreach ($students as $stuId) {
            $profile  = $studentProfiles[$stuId];
            $subMod   = $subjectModifiers[$stuId][$subject] ?? 0;
            $examMod  = $examDifficultyMod[$examId];

            // Generate theory score
            $theory = mt_rand($profile['theoryMin'], $profile['theoryMax']) + $subMod + $examMod;
            $theory = max(15, min(80, $theory)); // clamp to 15-80

            // Generate practical score
            $practical = mt_rand($profile['practMin'], $profile['practMax']);
            $practical = max(5, min(20, $practical)); // clamp to 5-20

            $total = $theory + $practical;

            $examMarks["{$subject}/{$stuId}"] = [
                'theory'    => $theory,
                'practical' => $practical,
                'total'     => $total,
            ];
            $stats['marks']++;
        }
    }

    // Single batch update per exam
    $database->getReference("{$marksBasePath}/{$examId}")->update($examMarks);
    echo "  [OK] {$examId} ({$exams[$examId]['name']}): " . count($examMarks) . " mark entries\n";
}

// Print detailed marks summary
echo "\n  ── Marks Summary (EXAM001 - Unit Test 1) ──\n";
echo "  " . str_pad("Student", 10) . " | ";
foreach ($subjects as $s) {
    echo str_pad(substr($s, 0, 8), 9) . "| ";
}
echo "Average\n";
echo "  " . str_repeat("-", 10 + 3 + count($subjects) * 11 + 8) . "\n";

// Read back marks for summary display
foreach ($students as $stuId) {
    $label = $studentProfiles[$stuId]['label'];
    echo "  " . str_pad($stuId, 10) . " | ";
    $sum = 0;
    $cnt = 0;
    foreach ($subjects as $subject) {
        $markData = $database->getReference("{$marksBasePath}/EXAM001/{$subject}/{$stuId}")->getValue();
        $t = $markData['total'] ?? '?';
        echo str_pad($t, 9) . "| ";
        if (is_numeric($t)) { $sum += $t; $cnt++; }
    }
    $avg = $cnt > 0 ? round($sum / $cnt, 1) : '?';
    echo "{$avg}\n";
}

echo "\n  ── Marks Summary (EXAM002 - Mid-Term Exam) ──\n";
echo "  " . str_pad("Student", 10) . " | ";
foreach ($subjects as $s) {
    echo str_pad(substr($s, 0, 8), 9) . "| ";
}
echo "Average\n";
echo "  " . str_repeat("-", 10 + 3 + count($subjects) * 11 + 8) . "\n";

foreach ($students as $stuId) {
    echo "  " . str_pad($stuId, 10) . " | ";
    $sum = 0;
    $cnt = 0;
    foreach ($subjects as $subject) {
        $markData = $database->getReference("{$marksBasePath}/EXAM002/{$subject}/{$stuId}")->getValue();
        $t = $markData['total'] ?? '?';
        echo str_pad($t, 9) . "| ";
        if (is_numeric($t)) { $sum += $t; $cnt++; }
    }
    $avg = $cnt > 0 ? round($sum / $cnt, 1) : '?';
    echo "{$avg}\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// FINAL SUMMARY
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n========================================================\n";
echo " SEEDING COMPLETE\n";
echo "========================================================\n";
echo " Timetables seeded : {$stats['timetable']} teachers\n";
echo " Duties seeded     : {$stats['duties']} teachers\n";
echo " Exams created     : {$stats['exams']} exams\n";
echo " Marks entries     : {$stats['marks']} records\n";
echo " Firebase paths:\n";
echo "   Timetable : {$basePath}/Teachers/{teacherId}/Timetable\n";
echo "   Duties    : {$basePath}/Teachers/{teacherId}/Duties\n";
echo "   Exams     : Schools/{$school}/Config/Exams/{examId}\n";
echo "   Marks     : {$basePath}/Class 9th/A/Marks/Exams/{examId}/{subject}/{stuId}\n";
echo "========================================================\n";
