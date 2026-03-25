<?php
error_reporting(E_ERROR);

require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

$serviceAccountPath = __DIR__ . '/application/config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
$databaseUri        = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

$factory = (new Factory)
    ->withServiceAccount($serviceAccountPath)
    ->withDatabaseUri($databaseUri);

$database = $factory->createDatabase();

$school  = 'SCH_9738C22243';
$session = '2026-27';

$now  = time() * 1000;
$day  = 86400000;
$hour = 3600000;

$counters = ['homework' => 0, 'stories' => 0, 'redflags' => 0, 'errors' => 0];

// ============================================================================
// HELPER
// ============================================================================

function fb_set($database, string $path, $data, array &$counters, string $type): void
{
    try {
        $database->getReference($path)->set($data);
        $counters[$type]++;
    } catch (\Exception $e) {
        $counters['errors']++;
        echo "  [ERROR] {$path}: {$e->getMessage()}\n";
    }
}

// ============================================================================
// 1.  HOMEWORK
// ============================================================================

echo "=== SEEDING HOMEWORK ===\n";

$basePath = "Schools/{$school}/{$session}";

// ---- Class 9th Section A (students: STU0004-STU0008) ----
$cls = 'Class 9th';
$sec = 'Section A';
$hwBase = "{$basePath}/{$cls}/{$sec}/Homework";

// HW_MATH01
fb_set($database, "{$hwBase}/HW_MATH01", [
    'title'       => 'Quadratic Equations Practice',
    'description' => 'Solve all exercises from Chapter 4 on quadratic equations. Show complete working for each problem and verify your answers using the discriminant method.',
    'subject'     => 'Math',
    'teacherId'   => 'STA0002',
    'teacherName' => 'Priya Verma',
    'dueDate'     => '2026-03-26',
    'createdAt'   => $now - (5 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0004' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0005' => ['status' => 'Submitted', 'submittedAt' => $now - (2 * $day) + (3 * $hour), 'remarks' => 'Completed all problems'],
        'STU0006' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0007' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (5 * $hour), 'remarks' => 'Done with extra problems'],
        'STU0008' => ['status' => 'Pending Review', 'submittedAt' => $now - (1 * $day) + (2 * $hour), 'remarks' => 'Submitted late'],
    ],
], $counters, 'homework');

// HW_SCI01
fb_set($database, "{$hwBase}/HW_SCI01", [
    'title'       => 'Lab Report: Photosynthesis',
    'description' => 'Write a detailed lab report on the photosynthesis experiment conducted in class. Include hypothesis, procedure, observations, and conclusions with diagrams.',
    'subject'     => 'Science',
    'teacherId'   => 'STA0003',
    'teacherName' => 'Rajesh Kumar',
    'dueDate'     => '2026-03-27',
    'createdAt'   => $now - (4 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0004' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0005' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0006' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0007' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0008' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

// HW_ENG01
fb_set($database, "{$hwBase}/HW_ENG01", [
    'title'       => 'Essay: My Role Model',
    'description' => 'Write a 500-word essay on your role model. Explain who they are, why you admire them, and how they have influenced your life and aspirations.',
    'subject'     => 'English',
    'teacherId'   => 'STA0004',
    'teacherName' => 'Sunita Devi',
    'dueDate'     => '2026-03-30',
    'createdAt'   => $now - (3 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0004' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (6 * $hour), 'remarks' => 'Well-written essay'],
        'STU0005' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (4 * $hour), 'remarks' => 'Good content'],
        'STU0006' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0007' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0008' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

// HW_HIN01
fb_set($database, "{$hwBase}/HW_HIN01", [
    'title'       => 'Hindi Grammar Worksheet',
    'description' => 'Complete the grammar worksheet covering sandhi, samas, and alankar. Answer all objective and subjective questions from the provided sheet.',
    'subject'     => 'Hindi',
    'teacherId'   => 'STA0005',
    'teacherName' => 'Vikram Singh',
    'dueDate'     => '2026-03-25',
    'createdAt'   => $now - (7 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0004' => ['status' => 'Submitted', 'submittedAt' => $now - (3 * $day) + (2 * $hour), 'remarks' => 'All questions answered'],
        'STU0005' => ['status' => 'Submitted', 'submittedAt' => $now - (3 * $day) + (4 * $hour), 'remarks' => 'Neat work'],
        'STU0006' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0007' => ['status' => 'Submitted', 'submittedAt' => $now - (2 * $day) + (1 * $hour), 'remarks' => 'Late submission'],
        'STU0008' => ['status' => 'Submitted', 'submittedAt' => $now - (2 * $day) + (6 * $hour), 'remarks' => 'Complete'],
    ],
], $counters, 'homework');

// HW_SST01
fb_set($database, "{$hwBase}/HW_SST01", [
    'title'       => 'French Revolution Notes',
    'description' => 'Prepare comprehensive notes on the French Revolution covering causes, key events, and aftermath. Include a timeline of important dates from 1789 to 1799.',
    'subject'     => 'Social Science',
    'teacherId'   => 'STA0006',
    'teacherName' => 'Meena Kumari',
    'dueDate'     => '2026-03-28',
    'createdAt'   => $now - (2 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0004' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0005' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0006' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0007' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0008' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

// HW_CS01
fb_set($database, "{$hwBase}/HW_CS01", [
    'title'       => 'Python: Loops and Functions',
    'description' => 'Write Python programs for the 10 exercises on loops and functions provided in class. Save each program as a separate .py file and submit the zipped folder.',
    'subject'     => 'Computer Science',
    'teacherId'   => 'TEA0003',
    'teacherName' => 'Mahendra Singh',
    'dueDate'     => '2026-03-29',
    'createdAt'   => $now - (1 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0004' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0005' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0006' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0007' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0008' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

echo "  Class 9th Section A: 6 homework items written\n";

// ---- Class 9th Section B (students: STU0009-STU0013) ----
$sec = 'Section B';
$hwBase = "{$basePath}/{$cls}/{$sec}/Homework";

// HW_MATH02
fb_set($database, "{$hwBase}/HW_MATH02", [
    'title'       => 'Geometry: Circles',
    'description' => 'Complete all theorems and proofs from Chapter 10 on Circles. Draw accurate diagrams for each theorem and solve the NCERT exercise questions.',
    'subject'     => 'Math',
    'teacherId'   => 'STA0002',
    'teacherName' => 'Priya Verma',
    'dueDate'     => '2026-03-27',
    'createdAt'   => $now - (4 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0009' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0010' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (3 * $hour), 'remarks' => 'Completed'],
        'STU0011' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0012' => ['status' => 'Submitted', 'submittedAt' => $now - (2 * $day) + (5 * $hour), 'remarks' => 'All diagrams included'],
        'STU0013' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

// HW_ENG02
fb_set($database, "{$hwBase}/HW_ENG02", [
    'title'       => 'Grammar: Tenses',
    'description' => 'Complete the tenses worksheet covering past, present, and future tenses. Transform the given 20 sentences into all three tense forms with correct structure.',
    'subject'     => 'English',
    'teacherId'   => 'STA0004',
    'teacherName' => 'Sunita Devi',
    'dueDate'     => '2026-03-28',
    'createdAt'   => $now - (3 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0009' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (2 * $hour), 'remarks' => 'Good work'],
        'STU0010' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0011' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (7 * $hour), 'remarks' => 'Well done'],
        'STU0012' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0013' => ['status' => 'Submitted', 'submittedAt' => $now - (2 * $day) + (1 * $hour), 'remarks' => 'Complete'],
    ],
], $counters, 'homework');

// HW_SST02
fb_set($database, "{$hwBase}/HW_SST02", [
    'title'       => 'Map Work: India Rivers',
    'description' => 'Mark all major river systems of India on the outline map provided. Label tributaries, dams, and delta regions. Use different colors for each river system.',
    'subject'     => 'Social Science',
    'teacherId'   => 'STA0006',
    'teacherName' => 'Meena Kumari',
    'dueDate'     => '2026-03-26',
    'createdAt'   => $now - (6 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0009' => ['status' => 'Submitted', 'submittedAt' => $now - (3 * $day) + (4 * $hour), 'remarks' => 'Colorful map'],
        'STU0010' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0011' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0012' => ['status' => 'Submitted', 'submittedAt' => $now - (2 * $day) + (6 * $hour), 'remarks' => 'All rivers marked'],
        'STU0013' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

echo "  Class 9th Section B: 3 homework items written\n";

// ---- Class 10th Section A (students: STU0019-STU0023) ----
$cls = 'Class 10th';
$sec = 'Section A';
$hwBase = "{$basePath}/{$cls}/{$sec}/Homework";

// HW_SCI02
fb_set($database, "{$hwBase}/HW_SCI02", [
    'title'       => 'Chemical Equations Worksheet',
    'description' => 'Balance all 25 chemical equations from the worksheet. Identify the type of each reaction and write the state symbols for all reactants and products.',
    'subject'     => 'Science',
    'teacherId'   => 'STA0003',
    'teacherName' => 'Rajesh Kumar',
    'dueDate'     => '2026-03-27',
    'createdAt'   => $now - (5 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0019' => ['status' => 'Submitted', 'submittedAt' => $now - (2 * $day) + (3 * $hour), 'remarks' => 'All balanced correctly'],
        'STU0020' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0021' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (5 * $hour), 'remarks' => 'Good effort'],
        'STU0022' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0023' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (2 * $hour), 'remarks' => 'Neat presentation'],
    ],
], $counters, 'homework');

// HW_MATH03
fb_set($database, "{$hwBase}/HW_MATH03", [
    'title'       => 'Trigonometry Practice',
    'description' => 'Solve the trigonometry problems from Exercise 8.1 and 8.2. Prove all given identities step by step and solve the height-and-distance word problems.',
    'subject'     => 'Math',
    'teacherId'   => 'STA0002',
    'teacherName' => 'Priya Verma',
    'dueDate'     => '2026-03-29',
    'createdAt'   => $now - (3 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0019' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0020' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0021' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (4 * $hour), 'remarks' => 'Partial completion'],
        'STU0022' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0023' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

// HW_CS02
fb_set($database, "{$hwBase}/HW_CS02", [
    'title'       => 'HTML & CSS Project',
    'description' => 'Create a personal portfolio webpage using HTML and CSS. Include at least 3 pages with navigation, responsive layout, and use semantic HTML5 elements throughout.',
    'subject'     => 'Computer Science',
    'teacherId'   => 'TEA0003',
    'teacherName' => 'Mahendra Singh',
    'dueDate'     => '2026-04-01',
    'createdAt'   => $now - (1 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0019' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0020' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0021' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0022' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0023' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

echo "  Class 10th Section A: 3 homework items written\n";

// ---- Class 8th Section A (students: STU0001-STU0003, STU0037) ----
$cls = 'Class 8th';
$sec = 'Section A';
$hwBase = "{$basePath}/{$cls}/{$sec}/Homework";

// HW_MATH04
fb_set($database, "{$hwBase}/HW_MATH04", [
    'title'       => 'Multiplication Tables',
    'description' => 'Write multiplication tables from 12 to 20 in your notebook. Practice speed drills and complete the timed worksheet for tables 13, 17, and 19.',
    'subject'     => 'Math',
    'teacherId'   => 'STA0002',
    'teacherName' => 'Priya Verma',
    'dueDate'     => '2026-03-25',
    'createdAt'   => $now - (7 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0001' => ['status' => 'Submitted', 'submittedAt' => $now - (4 * $day) + (2 * $hour), 'remarks' => 'Completed all tables'],
        'STU0002' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0003' => ['status' => 'Submitted', 'submittedAt' => $now - (3 * $day) + (5 * $hour), 'remarks' => 'Speed drill done'],
        'STU0037' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
    ],
], $counters, 'homework');

// HW_ENG03
fb_set($database, "{$hwBase}/HW_ENG03", [
    'title'       => 'Story Writing',
    'description' => 'Write an original short story of 300-400 words on the topic "An Unexpected Journey". Use vivid descriptions, dialogue, and a clear beginning, middle, and end.',
    'subject'     => 'English',
    'teacherId'   => 'STA0004',
    'teacherName' => 'Sunita Devi',
    'dueDate'     => '2026-03-28',
    'createdAt'   => $now - (2 * $day),
    'status'      => 'Active',
    'submissions' => [
        'STU0001' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0002' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (3 * $hour), 'remarks' => 'Creative story'],
        'STU0003' => ['status' => 'Not Submitted', 'submittedAt' => 0, 'remarks' => ''],
        'STU0037' => ['status' => 'Submitted', 'submittedAt' => $now - (1 * $day) + (6 * $hour), 'remarks' => 'Good vocabulary'],
    ],
], $counters, 'homework');

echo "  Class 8th Section A: 2 homework items written\n";
echo "  Homework total: {$counters['homework']} items\n\n";

// ============================================================================
// 2.  STORIES
// ============================================================================

echo "=== SEEDING STORIES ===\n";

$storiesBase = "Schools/{$school}/{$session}/Stories";

$teacherNames = [
    'STA0002' => 'Priya Verma',
    'STA0003' => 'Rajesh Kumar',
    'STA0004' => 'Sunita Devi',
    'STA0005' => 'Vikram Singh',
    'STA0006' => 'Meena Kumari',
    'TEA0003' => 'Mahendra Singh',
];

$stories = [
    // STA0002 - 2 stories
    ['teacher' => 'STA0002', 'id' => 'STORY_001', 'mediaType' => 'image', 'random' => 1,
     'caption' => 'Math class working on quadratic equations today. Students are solving problems on the board with great enthusiasm!',
     'offset'  => -(2 * $hour), 'viewCount' => 45, 'status' => 'active'],

    ['teacher' => 'STA0002', 'id' => 'STORY_002', 'mediaType' => 'image', 'random' => 2,
     'caption' => 'Students practicing mental math speed drills. Amazing improvement in calculation speed this week!',
     'offset'  => -(1 * $day) - (6 * $hour), 'viewCount' => 62, 'status' => 'active'],

    // STA0003 - 2 stories
    ['teacher' => 'STA0003', 'id' => 'STORY_003', 'mediaType' => 'image', 'random' => 3,
     'caption' => 'Science lab session: Students observing photosynthesis experiment results under the microscope.',
     'offset'  => -(5 * $hour), 'viewCount' => 38, 'status' => 'active'],

    ['teacher' => 'STA0003', 'id' => 'STORY_004', 'mediaType' => 'video', 'random' => 4,
     'caption' => 'Chemistry demonstration: Exciting exothermic reaction showing color changes and gas evolution.',
     'offset'  => -(2 * $day) - (3 * $hour), 'viewCount' => 71, 'status' => 'active'],

    // STA0004 - 2 stories
    ['teacher' => 'STA0004', 'id' => 'STORY_005', 'mediaType' => 'image', 'random' => 5,
     'caption' => 'English creative writing session. Students are drafting essays on their role models with heartfelt stories.',
     'offset'  => -(3 * $hour), 'viewCount' => 29, 'status' => 'active'],

    ['teacher' => 'STA0004', 'id' => 'STORY_006', 'mediaType' => 'image', 'random' => 6,
     'caption' => 'Poetry recitation competition in class. Some truly moving performances from our talented students today!',
     'offset'  => -(1 * $day) - (10 * $hour), 'viewCount' => 55, 'status' => 'active'],

    // STA0005 - 2 stories (1 flagged)
    ['teacher' => 'STA0005', 'id' => 'STORY_007', 'mediaType' => 'image', 'random' => 7,
     'caption' => 'Hindi grammar workshop in progress. Interactive session on sandhi and samas with real-life examples.',
     'offset'  => -(8 * $hour), 'viewCount' => 33, 'status' => 'active'],

    ['teacher' => 'STA0005', 'id' => 'STORY_008', 'mediaType' => 'video', 'random' => 8,
     'caption' => 'Cultural program rehearsal for upcoming annual day celebration. Students performing a traditional folk dance.',
     'offset'  => -(2 * $day) - (5 * $hour), 'viewCount' => 80, 'status' => 'flagged',
     'moderationStatus' => 'flagged', 'moderationReason' => 'Review content'],

    // STA0006 - 2 stories (1 expired)
    ['teacher' => 'STA0006', 'id' => 'STORY_009', 'mediaType' => 'image', 'random' => 9,
     'caption' => 'Social Science project presentations. Students showcasing their models of the French Revolution timeline.',
     'offset'  => -(4 * $hour), 'viewCount' => 41, 'status' => 'active'],

    ['teacher' => 'STA0006', 'id' => 'STORY_010', 'mediaType' => 'image', 'random' => 10,
     'caption' => 'Geography field trip preparations. Mapping river systems of India using satellite imagery printouts.',
     'offset'  => -(2 * $day) - (14 * $hour), 'viewCount' => 18, 'status' => 'active', 'expired' => true],

    // TEA0003 - 2 stories (1 flagged)
    ['teacher' => 'TEA0003', 'id' => 'STORY_011', 'mediaType' => 'image', 'random' => 11,
     'caption' => 'Computer lab session: Students building their first HTML webpage. Great to see the excitement on their faces!',
     'offset'  => -(1 * $hour), 'viewCount' => 52, 'status' => 'active'],

    ['teacher' => 'TEA0003', 'id' => 'STORY_012', 'mediaType' => 'video', 'random' => 12,
     'caption' => 'Coding club after-school activity. Students working on Python game development projects collaboratively.',
     'offset'  => -(1 * $day) - (8 * $hour), 'viewCount' => 67, 'status' => 'flagged',
     'moderationStatus' => 'flagged', 'moderationReason' => 'Review content'],
];

foreach ($stories as $s) {
    $teacherId   = $s['teacher'];
    $storyId     = $s['id'];
    $createdAt   = $now + $s['offset'];

    // For expired stories, set expiresAt in the past
    if (!empty($s['expired'])) {
        $expiresAt = $createdAt + (24 * $hour);  // already expired since createdAt is old enough
    } else {
        $expiresAt = $createdAt + (24 * $hour);
    }

    $storyData = [
        'teacherName'       => $teacherNames[$teacherId],
        'teacherProfilePic' => '',
        'mediaUrl'          => "https://picsum.photos/800/600?random={$s['random']}",
        'mediaType'         => $s['mediaType'],
        'caption'           => $s['caption'],
        'createdAt'         => $createdAt,
        'expiresAt'         => $expiresAt,
        'viewCount'         => $s['viewCount'],
        'status'            => $s['status'],
    ];

    if (isset($s['moderationStatus'])) {
        $storyData['moderationStatus'] = $s['moderationStatus'];
        $storyData['moderationReason'] = $s['moderationReason'];
    }

    fb_set($database, "{$storiesBase}/{$teacherId}/{$storyId}", $storyData, $counters, 'stories');
}

echo "  Stories written: {$counters['stories']} across 6 teachers\n";
echo "  Flagged: 2 (STORY_008 by STA0005, STORY_012 by TEA0003)\n";
echo "  Expired: 1 (STORY_010 by STA0006)\n\n";

// ============================================================================
// 3.  RED FLAGS
// ============================================================================

echo "=== SEEDING RED FLAGS ===\n";

$flags = [
    // ---- Class 9th Section A: 6 flags ----
    // STU0004 - 2 flags
    ['class' => 'Class 9th', 'section' => 'Section A', 'student' => 'STU0004', 'id' => 'RF_001',
     'type' => 'Homework', 'severity' => 'Medium', 'subject' => 'Math',
     'message' => 'Consistently missing math homework submissions for the past two weeks. Needs parental intervention and follow-up.',
     'teacherId' => 'STA0002', 'teacherName' => 'Priya Verma',
     'createdAt' => $now - (5 * $day), 'status' => 'Active'],

    ['class' => 'Class 9th', 'section' => 'Section A', 'student' => 'STU0004', 'id' => 'RF_002',
     'type' => 'Performance', 'severity' => 'Low', 'subject' => 'Science',
     'message' => 'Scored below average in the last science unit test. Showing signs of improvement in class participation though.',
     'teacherId' => 'STA0003', 'teacherName' => 'Rajesh Kumar',
     'createdAt' => $now - (3 * $day), 'status' => 'Resolved',
     'resolvedAt' => $now - (1 * $day), 'resolvedBy' => 'STA0003'],

    // STU0006 - 3 flags
    ['class' => 'Class 9th', 'section' => 'Section A', 'student' => 'STU0006', 'id' => 'RF_003',
     'type' => 'Behavior', 'severity' => 'High', 'subject' => 'General',
     'message' => 'Repeatedly disrupting class by talking loudly during lessons. Multiple verbal warnings given without improvement. Recommend parent-teacher meeting.',
     'teacherId' => 'STA0004', 'teacherName' => 'Sunita Devi',
     'createdAt' => $now - (6 * $day), 'status' => 'Active'],

    ['class' => 'Class 9th', 'section' => 'Section A', 'student' => 'STU0006', 'id' => 'RF_004',
     'type' => 'Homework', 'severity' => 'High', 'subject' => 'Hindi',
     'message' => 'Has not submitted any Hindi homework this month. No response from student when asked about pending work. Urgent follow-up required.',
     'teacherId' => 'STA0005', 'teacherName' => 'Vikram Singh',
     'createdAt' => $now - (4 * $day), 'status' => 'Active'],

    ['class' => 'Class 9th', 'section' => 'Section A', 'student' => 'STU0006', 'id' => 'RF_005',
     'type' => 'Performance', 'severity' => 'Medium', 'subject' => 'Math',
     'message' => 'Failing grades in last two math assessments. Struggles with algebraic concepts and needs additional tutoring support.',
     'teacherId' => 'STA0002', 'teacherName' => 'Priya Verma',
     'createdAt' => $now - (2 * $day), 'status' => 'Active'],

    // STU0008 - 1 flag
    ['class' => 'Class 9th', 'section' => 'Section A', 'student' => 'STU0008', 'id' => 'RF_006',
     'type' => 'Homework', 'severity' => 'Low', 'subject' => 'Social Science',
     'message' => 'Submitted incomplete French Revolution notes. Missing the timeline section. Asked to resubmit by end of week.',
     'teacherId' => 'STA0006', 'teacherName' => 'Meena Kumari',
     'createdAt' => $now - (1 * $day), 'status' => 'Active'],

    // ---- Class 9th Section B: 3 flags ----
    // STU0010 - 2 flags
    ['class' => 'Class 9th', 'section' => 'Section B', 'student' => 'STU0010', 'id' => 'RF_007',
     'type' => 'Behavior', 'severity' => 'Medium', 'subject' => 'General',
     'message' => 'Frequently arriving late to morning classes. Tardiness affecting first period attendance. Parent to be notified about punctuality.',
     'teacherId' => 'STA0006', 'teacherName' => 'Meena Kumari',
     'createdAt' => $now - (5 * $day), 'status' => 'Resolved',
     'resolvedAt' => $now - (2 * $day), 'resolvedBy' => 'STA0006'],

    ['class' => 'Class 9th', 'section' => 'Section B', 'student' => 'STU0010', 'id' => 'RF_008',
     'type' => 'Performance', 'severity' => 'High', 'subject' => 'Math',
     'message' => 'Scored 18/100 in the mid-term math examination. Severe gaps in fundamental concepts from previous classes. Immediate remedial classes recommended.',
     'teacherId' => 'STA0002', 'teacherName' => 'Priya Verma',
     'createdAt' => $now - (3 * $day), 'status' => 'Active'],

    // STU0011 - 1 flag
    ['class' => 'Class 9th', 'section' => 'Section B', 'student' => 'STU0011', 'id' => 'RF_009',
     'type' => 'Homework', 'severity' => 'Low', 'subject' => 'English',
     'message' => 'Grammar worksheet submitted with many errors. Needs extra practice on tenses. Provided additional worksheets for home practice.',
     'teacherId' => 'STA0004', 'teacherName' => 'Sunita Devi',
     'createdAt' => $now - (2 * $day), 'status' => 'Active'],

    // ---- Class 10th Section A: 3 flags ----
    // STU0020 - 1 flag
    ['class' => 'Class 10th', 'section' => 'Section A', 'student' => 'STU0020', 'id' => 'RF_010',
     'type' => 'Homework', 'severity' => 'Medium', 'subject' => 'Science',
     'message' => 'Chemical equations worksheet not submitted despite two reminders. Board exam preparation at risk if homework pattern continues.',
     'teacherId' => 'STA0003', 'teacherName' => 'Rajesh Kumar',
     'createdAt' => $now - (4 * $day), 'status' => 'Active'],

    // STU0022 - 2 flags
    ['class' => 'Class 10th', 'section' => 'Section A', 'student' => 'STU0022', 'id' => 'RF_011',
     'type' => 'Performance', 'severity' => 'High', 'subject' => 'Math',
     'message' => 'Consistently declining performance in mathematics. Scored below 30% in last three tests. Critical intervention needed before board exams.',
     'teacherId' => 'STA0002', 'teacherName' => 'Priya Verma',
     'createdAt' => $now - (6 * $day), 'status' => 'Active'],

    ['class' => 'Class 10th', 'section' => 'Section A', 'student' => 'STU0022', 'id' => 'RF_012',
     'type' => 'Behavior', 'severity' => 'Medium', 'subject' => 'Computer Science',
     'message' => 'Using computer lab systems for non-academic browsing during class hours. Access restricted to academic websites only. Warning issued.',
     'teacherId' => 'TEA0003', 'teacherName' => 'Mahendra Singh',
     'createdAt' => $now - (2 * $day), 'status' => 'Resolved',
     'resolvedAt' => $now - (1 * $day), 'resolvedBy' => 'TEA0003'],

    // ---- Class 8th Section A: 3 flags ----
    // STU0002 - 2 flags
    ['class' => 'Class 8th', 'section' => 'Section A', 'student' => 'STU0002', 'id' => 'RF_013',
     'type' => 'Homework', 'severity' => 'Medium', 'subject' => 'Math',
     'message' => 'Multiplication tables homework not submitted. Struggling with tables beyond 12. Extra practice sessions scheduled after school.',
     'teacherId' => 'STA0002', 'teacherName' => 'Priya Verma',
     'createdAt' => $now - (5 * $day), 'status' => 'Active'],

    ['class' => 'Class 8th', 'section' => 'Section A', 'student' => 'STU0002', 'id' => 'RF_014',
     'type' => 'Behavior', 'severity' => 'Low', 'subject' => 'General',
     'message' => 'Not bringing required textbooks and notebooks to class repeatedly. Reminded to keep school bag organized. Minor issue but becoming habitual.',
     'teacherId' => 'STA0004', 'teacherName' => 'Sunita Devi',
     'createdAt' => $now - (3 * $day), 'status' => 'Resolved',
     'resolvedAt' => $now - (1 * $day), 'resolvedBy' => 'STA0004'],

    // STU0037 - 1 flag
    ['class' => 'Class 8th', 'section' => 'Section A', 'student' => 'STU0037', 'id' => 'RF_015',
     'type' => 'Performance', 'severity' => 'High', 'subject' => 'English',
     'message' => 'Unable to read and write basic English sentences fluently. Significant gap compared to class level. Recommend special English support classes and parent counseling.',
     'teacherId' => 'STA0004', 'teacherName' => 'Sunita Devi',
     'createdAt' => $now - (1 * $day), 'status' => 'Active'],
];

foreach ($flags as $f) {
    $path = "{$basePath}/{$f['class']}/{$f['section']}/RedFlags/{$f['student']}/{$f['id']}";
    $flagData = [
        'type'        => $f['type'],
        'severity'    => $f['severity'],
        'message'     => $f['message'],
        'subject'     => $f['subject'],
        'teacherId'   => $f['teacherId'],
        'teacherName' => $f['teacherName'],
        'createdAt'   => $f['createdAt'],
        'status'      => $f['status'],
    ];

    if ($f['status'] === 'Resolved') {
        $flagData['resolvedAt'] = $f['resolvedAt'];
        $flagData['resolvedBy'] = $f['resolvedBy'];
    }

    fb_set($database, $path, $flagData, $counters, 'redflags');
}

echo "  Red flags written: {$counters['redflags']}\n";
echo "  Class 9th A: 6 flags (STU0004=2, STU0006=3, STU0008=1)\n";
echo "  Class 9th B: 3 flags (STU0010=2, STU0011=1)\n";
echo "  Class 10th A: 3 flags (STU0020=1, STU0022=2)\n";
echo "  Class 8th A: 3 flags (STU0002=2, STU0037=1)\n";
echo "  Active: " . count(array_filter($flags, fn($f) => $f['status'] === 'Active')) . "\n";
echo "  Resolved: " . count(array_filter($flags, fn($f) => $f['status'] === 'Resolved')) . "\n";
echo "  High severity: " . count(array_filter($flags, fn($f) => $f['severity'] === 'High')) . "\n\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "============================================\n";
echo "           SEED COMPLETE SUMMARY\n";
echo "============================================\n";
echo "School:    {$school}\n";
echo "Session:   {$session}\n";
echo "Homework:  {$counters['homework']} items across 4 classes\n";
echo "Stories:   {$counters['stories']} stories across 6 teachers\n";
echo "Red Flags: {$counters['redflags']} flags across 4 classes\n";
echo "Errors:    {$counters['errors']}\n";
echo "============================================\n";
