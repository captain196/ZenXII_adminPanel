<?php
/**
 * Seed script: Messages, Notices, Events, Fees, Leave Requests
 * School: SCH_9738C22243 | Session: 2026-27
 */
error_reporting(E_ERROR);

require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

$serviceAccountPath = __DIR__ . '/application/config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
$databaseUri        = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

$factory  = (new Factory)
    ->withServiceAccount($serviceAccountPath)
    ->withDatabaseUri($databaseUri);

$db = $factory->createDatabase();

$school = 'Schools/SCH_9738C22243';
$session = '2026-27';

$now  = time() * 1000;
$day  = 86400000;
$hour = 3600000;

$counts = [
    'conversations' => 0,
    'messages'      => 0,
    'notices'       => 0,
    'events'        => 0,
    'feeStructure'  => 0,
    'feePayments'   => 0,
    'leaveRequests' => 0,
];

// ────────────────────────────────────────────────────────────────────────────
// Helper: generate a Firebase-style push ID
// ────────────────────────────────────────────────────────────────────────────
function pushId(): string {
    $chars = '-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';
    $ts = (int)(microtime(true) * 1000);
    $id = '';
    for ($i = 7; $i >= 0; $i--) {
        $id = $chars[$ts % 64] . $id;
        $ts = intdiv($ts, 64);
    }
    for ($i = 0; $i < 12; $i++) {
        $id .= $chars[random_int(0, 63)];
    }
    return $id;
}

// ============================================================================
// 1. MESSAGES
// ============================================================================
echo "=== 1. SEEDING MESSAGES ===\n";

$convBase = "$school/Messages/Conversations";

$conversations = [
    // Conv 1: STA0002 <-> STU0004 parent (Yuvraj's math progress) - 5 messages
    [
        'teacherId'    => 'STA0002',
        'teacherName'  => 'Mr. Rajesh Kumar',
        'parentId'     => 'STU0004',
        'parentName'   => 'Mrs. Sunita Sharma',
        'studentName'  => 'Yuvraj Sharma',
        'studentClass' => 'Class 9th - Section A',
        'messages'     => [
            ['sender' => 'parent',  'name' => 'Mrs. Sunita Sharma', 'msg' => 'Good morning sir, I wanted to discuss Yuvraj\'s performance in the recent math test. He scored much lower than expected.', 'ago' => 3 * 86400000 + 6 * 3600000],
            ['sender' => 'teacher', 'name' => 'Mr. Rajesh Kumar',   'msg' => 'Good morning. Yes, I noticed that too. Yuvraj has been struggling with trigonometry concepts. He needs more practice with the basic identities before moving to applications.', 'ago' => 3 * 86400000 + 5 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Sunita Sharma', 'msg' => 'Should I arrange for extra tuition or can you suggest some exercises he can work on at home?', 'ago' => 3 * 86400000 + 4 * 3600000],
            ['sender' => 'teacher', 'name' => 'Mr. Rajesh Kumar',   'msg' => 'I will give him a set of 20 practice problems this week. If he completes those sincerely, he should improve. Let\'s see the progress before considering tuition.', 'ago' => 2 * 86400000 + 8 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Sunita Sharma', 'msg' => 'Thank you so much sir. I will make sure he completes them on time. Please keep me updated on his progress.', 'ago' => 2 * 86400000 + 7 * 3600000],
        ],
        'unreadTeacher' => 1,
        'unreadParent'  => 0,
    ],
    // Conv 2: STA0004 <-> STU0004 parent (English coaching) - 4 messages
    [
        'teacherId'    => 'STA0004',
        'teacherName'  => 'Mrs. Anita Verma',
        'parentId'     => 'STU0004',
        'parentName'   => 'Mrs. Sunita Sharma',
        'studentName'  => 'Yuvraj Sharma',
        'studentClass' => 'Class 9th - Section A',
        'messages'     => [
            ['sender' => 'teacher', 'name' => 'Mrs. Anita Verma',   'msg' => 'Dear Mrs. Sharma, I wanted to inform you that Yuvraj\'s English essay writing has improved significantly this month. His vocabulary usage is much better now.', 'ago' => 5 * 86400000 + 3 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Sunita Sharma', 'msg' => 'That is wonderful to hear! He has been reading the novels you recommended. Is there anything else he should focus on?', 'ago' => 5 * 86400000 + 2 * 3600000],
            ['sender' => 'teacher', 'name' => 'Mrs. Anita Verma',   'msg' => 'His grammar is still weak in areas like reported speech and passive voice. I would recommend he practices those chapters from the workbook.', 'ago' => 4 * 86400000 + 7 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Sunita Sharma', 'msg' => 'Noted, I will get him to focus on those areas. Thank you for your guidance, Mrs. Verma.', 'ago' => 4 * 86400000 + 6 * 3600000],
        ],
        'unreadTeacher' => 0,
        'unreadParent'  => 0,
    ],
    // Conv 3: STA0003 <-> STU0006 parent (Science lab incident) - 4 messages
    [
        'teacherId'    => 'STA0003',
        'teacherName'  => 'Dr. Meena Iyer',
        'parentId'     => 'STU0006',
        'parentName'   => 'Mr. Vikram Patel',
        'studentName'  => 'Arjun Patel',
        'studentClass' => 'Class 10th - Section B',
        'messages'     => [
            ['sender' => 'teacher', 'name' => 'Dr. Meena Iyer',  'msg' => 'Dear Mr. Patel, I need to inform you about a minor incident in the chemistry lab today. Arjun accidentally broke a test tube while handling chemicals. He was not hurt, but we need to discuss lab safety.', 'ago' => 1 * 86400000 + 5 * 3600000],
            ['sender' => 'parent',  'name' => 'Mr. Vikram Patel', 'msg' => 'Thank you for informing me, Dr. Iyer. I am relieved he is safe. Was there any damage to other equipment? I will speak to him about being more careful.', 'ago' => 1 * 86400000 + 4 * 3600000],
            ['sender' => 'teacher', 'name' => 'Dr. Meena Iyer',  'msg' => 'No other equipment was damaged. It was a simple accident. However, I would appreciate if you could reinforce the importance of following lab protocols at home. We will also have an extra safety briefing next week.', 'ago' => 1 * 86400000 + 3 * 3600000],
            ['sender' => 'parent',  'name' => 'Mr. Vikram Patel', 'msg' => 'Absolutely, I will have a detailed talk with him tonight. Thank you for handling the situation well and for the extra safety measures.', 'ago' => 1 * 86400000 + 2 * 3600000],
        ],
        'unreadTeacher' => 0,
        'unreadParent'  => 0,
    ],
    // Conv 4: STA0006 <-> STU0005 parent (Priya's excellent work) - 2 messages
    [
        'teacherId'    => 'STA0006',
        'teacherName'  => 'Mrs. Kavita Singh',
        'parentId'     => 'STU0005',
        'parentName'   => 'Mrs. Deepa Reddy',
        'studentName'  => 'Priya Reddy',
        'studentClass' => 'Class 8th - Section A',
        'messages'     => [
            ['sender' => 'teacher', 'name' => 'Mrs. Kavita Singh', 'msg' => 'Dear Mrs. Reddy, I am delighted to share that Priya secured the highest marks in our Social Science unit test. Her answer on the French Revolution was exceptionally well-written. She is a star student!', 'ago' => 6 * 86400000 + 2 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Deepa Reddy',  'msg' => 'Thank you so much for letting me know! Priya will be thrilled to hear this from her teacher. We are very proud of her. She really enjoys your classes.', 'ago' => 6 * 86400000 + 1 * 3600000],
        ],
        'unreadTeacher' => 0,
        'unreadParent'  => 0,
    ],
    // Conv 5: TEA0003 <-> STU0004 parent (Computer Science project) - 3 messages
    [
        'teacherId'    => 'TEA0003',
        'teacherName'  => 'Mr. Amit Joshi',
        'parentId'     => 'STU0004',
        'parentName'   => 'Mrs. Sunita Sharma',
        'studentName'  => 'Yuvraj Sharma',
        'studentClass' => 'Class 9th - Section A',
        'messages'     => [
            ['sender' => 'teacher', 'name' => 'Mr. Amit Joshi',     'msg' => 'Mrs. Sharma, Yuvraj has not submitted his Computer Science project on Python programming which was due last Friday. This will affect his internal assessment marks.', 'ago' => 2 * 86400000 + 4 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Sunita Sharma', 'msg' => 'I am sorry to hear that, sir. He was unwell last week and could not complete it. Can he get an extension of 2-3 days?', 'ago' => 2 * 86400000 + 3 * 3600000],
            ['sender' => 'teacher', 'name' => 'Mr. Amit Joshi',     'msg' => 'I understand. He can submit it by Wednesday. Please ensure the project includes all five programs as mentioned in the guidelines I shared in class.', 'ago' => 2 * 86400000 + 2 * 3600000],
        ],
        'unreadTeacher' => 0,
        'unreadParent'  => 1,
    ],
    // Conv 6: STA0005 <-> STU0002 parent (Hindi recitation) - 2 messages
    [
        'teacherId'    => 'STA0005',
        'teacherName'  => 'Mrs. Pooja Mishra',
        'parentId'     => 'STU0002',
        'parentName'   => 'Mr. Ramesh Gupta',
        'studentName'  => 'Aarav Gupta',
        'studentClass' => 'Class 7th - Section B',
        'messages'     => [
            ['sender' => 'teacher', 'name' => 'Mrs. Pooja Mishra', 'msg' => 'Dear Mr. Gupta, kindly note that Aarav has been selected for the Hindi recitation competition on 28th March. He needs to prepare the poem "Veer Tum Badhe Chalo" and practice daily.', 'ago' => 4 * 86400000 + 3 * 3600000],
            ['sender' => 'parent',  'name' => 'Mr. Ramesh Gupta',  'msg' => 'That is great news! Aarav is very excited. We will make sure he practices every evening. Thank you for selecting him.', 'ago' => 4 * 86400000 + 1 * 3600000],
        ],
        'unreadTeacher' => 0,
        'unreadParent'  => 0,
    ],
    // Conv 7: STA0002 <-> STU0020 parent (Math improvement) - 3 messages
    [
        'teacherId'    => 'STA0002',
        'teacherName'  => 'Mr. Rajesh Kumar',
        'parentId'     => 'STU0020',
        'parentName'   => 'Mrs. Neelam Agarwal',
        'studentName'  => 'Rohan Agarwal',
        'studentClass' => 'Class 9th - Section A',
        'messages'     => [
            ['sender' => 'parent',  'name' => 'Mrs. Neelam Agarwal', 'msg' => 'Sir, I noticed Rohan has been getting better marks in math homework lately. Is he also performing well in class tests?', 'ago' => 7 * 86400000 + 5 * 3600000],
            ['sender' => 'teacher', 'name' => 'Mr. Rajesh Kumar',    'msg' => 'Yes, Rohan has shown remarkable improvement this month. His algebra scores went up from 12/25 to 21/25. He is clearly putting in more effort.', 'ago' => 7 * 86400000 + 3 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Neelam Agarwal', 'msg' => 'That is so encouraging! We got him a math workbook as you suggested last month. It seems to be helping. Thank you for your support, sir.', 'ago' => 7 * 86400000 + 2 * 3600000],
        ],
        'unreadTeacher' => 1,
        'unreadParent'  => 0,
    ],
    // Conv 8: STA0004 <-> STU0010 parent (English struggles) - 3 messages
    [
        'teacherId'    => 'STA0004',
        'teacherName'  => 'Mrs. Anita Verma',
        'parentId'     => 'STU0010',
        'parentName'   => 'Mrs. Lakshmi Nair',
        'studentName'  => 'Aditya Nair',
        'studentClass' => 'Class 9th - Section B',
        'messages'     => [
            ['sender' => 'teacher', 'name' => 'Mrs. Anita Verma', 'msg' => 'Dear Mrs. Nair, I am concerned about Aditya\'s performance in English. He has been consistently scoring below average in comprehension passages and his writing lacks coherence.', 'ago' => 3 * 86400000 + 8 * 3600000],
            ['sender' => 'parent',  'name' => 'Mrs. Lakshmi Nair', 'msg' => 'Thank you for bringing this to my attention. Aditya has mentioned that he finds English difficult. What specific areas should we work on at home?', 'ago' => 3 * 86400000 + 6 * 3600000],
            ['sender' => 'teacher', 'name' => 'Mrs. Anita Verma', 'msg' => 'I suggest daily reading of English newspapers for 20 minutes and writing a short paragraph summary. Also, he should practice unseen passages from the NCERT supplementary reader. I will also give him extra attention during class.', 'ago' => 3 * 86400000 + 5 * 3600000],
        ],
        'unreadTeacher' => 0,
        'unreadParent'  => 1,
    ],
];

foreach ($conversations as $conv) {
    $convId = pushId();
    $convPath = "$convBase/$convId";

    $msgData = [];
    $lastMsg = '';
    $lastTime = 0;

    foreach ($conv['messages'] as $m) {
        $msgId = pushId();
        $ts = $now - $m['ago'];

        if ($m['sender'] === 'teacher') {
            $senderId   = $conv['teacherId'];
            $senderName = $m['name'];
            $recipientId = $conv['parentId'];
        } else {
            $senderId   = $conv['parentId'];
            $senderName = $m['name'];
            $recipientId = $conv['teacherId'];
        }

        $msgData[$msgId] = [
            'senderId'   => $senderId,
            'senderName' => $senderName,
            'message'    => $m['msg'],
            'timestamp'  => $ts,
            'readBy'     => [$recipientId => true],
        ];

        $lastMsg  = $m['msg'];
        $lastTime = $ts;
        $counts['messages']++;
        usleep(1000); // slight delay for unique pushId
    }

    $convData = [
        'teacherId'    => $conv['teacherId'],
        'teacherName'  => $conv['teacherName'],
        'parentId'     => $conv['parentId'],
        'parentName'   => $conv['parentName'],
        'studentName'  => $conv['studentName'],
        'studentClass' => $conv['studentClass'],
        'lastMessage'  => $lastMsg,
        'lastMessageTime' => $lastTime,
        'unreadCount'  => [
            $conv['teacherId'] => $conv['unreadTeacher'],
            $conv['parentId']  => $conv['unreadParent'],
        ],
        'messages'     => $msgData,
    ];

    $db->getReference($convPath)->set($convData);
    $counts['conversations']++;
    echo "  [OK] Conversation: {$conv['teacherName']} <-> {$conv['parentName']} ({$conv['studentName']}) - " . count($conv['messages']) . " msgs\n";
}

// Flagged Keywords
$flaggedKeywords = ['fight', 'bully', 'cheat', 'abuse', 'violence', 'inappropriate', 'threat', 'harassment'];
$db->getReference("$school/Messages/FlaggedKeywords")->set($flaggedKeywords);
echo "  [OK] FlaggedKeywords set (" . count($flaggedKeywords) . " keywords)\n";

// ============================================================================
// 2. NOTICES
// ============================================================================
echo "\n=== 2. SEEDING NOTICES ===\n";

$noticePath = "$school/$session/All Notices";

$notices = [
    [
        'title'    => 'Annual Sports Day',
        'body'     => 'The Annual Sports Day will be held on April 15th, 2026 at the school ground. All students are expected to participate in at least one event. Parents are cordially invited to attend and cheer for their children.',
        'author'   => 'Principal - Dr. S.K. Mehta',
        'date'     => '2026-03-20',
        'category' => 'Event',
        'priority' => 'Important',
        'target'   => 'All',
    ],
    [
        'title'    => 'Mid-Term Examination Schedule',
        'body'     => 'The Mid-Term Examinations for all classes will commence from April 25th and continue until May 5th, 2026. The detailed timetable has been shared with class teachers. Students must bring their admit cards to the exam hall.',
        'author'   => 'Examination Cell',
        'date'     => '2026-03-18',
        'category' => 'Exam',
        'priority' => 'Urgent',
        'target'   => 'All',
    ],
    [
        'title'    => 'Holi Holiday Announcement',
        'body'     => 'The school will remain closed on March 14th and 15th, 2026 on account of Holi festival. Classes will resume on March 16th as per the regular schedule. Wishing everyone a colourful and safe Holi!',
        'author'   => 'Administration',
        'date'     => '2026-03-10',
        'category' => 'Holiday',
        'priority' => 'Normal',
        'target'   => 'All',
    ],
    [
        'title'    => 'Parent-Teacher Meeting',
        'body'     => 'A Parent-Teacher Meeting is scheduled for March 29th, 2026 from 9:00 AM to 1:00 PM. Parents are requested to collect their ward\'s progress report and discuss academic performance with subject teachers. Attendance is mandatory.',
        'author'   => 'Vice Principal - Mrs. R. Sharma',
        'date'     => '2026-03-22',
        'category' => 'Academic',
        'priority' => 'Important',
        'target'   => 'Parents',
    ],
    [
        'title'    => 'Fee Payment Reminder',
        'body'     => 'This is a reminder that the last date for payment of quarterly fees (April-June 2026) is March 31st, 2026. Late payment will attract a fine of Rs. 50 per day. Please pay through the school portal or at the accounts office.',
        'author'   => 'Accounts Department',
        'date'     => '2026-03-15',
        'category' => 'General',
        'priority' => 'Urgent',
        'target'   => 'Parents',
    ],
    [
        'title'    => 'Summer Vacation Dates',
        'body'     => 'Summer vacation for the academic session 2026-27 will be from May 15th to June 30th, 2026. The school will reopen on July 1st, 2026. Holiday homework will be distributed before the vacation begins.',
        'author'   => 'Principal - Dr. S.K. Mehta',
        'date'     => '2026-03-23',
        'category' => 'Holiday',
        'priority' => 'Normal',
        'target'   => 'All',
    ],
    [
        'title'    => 'Science Fair 2026',
        'body'     => 'The annual Science Fair will be held on April 5th, 2026. Students from classes 6th to 10th can register their projects with their respective Science teachers by March 28th. Best projects will receive certificates and prizes.',
        'author'   => 'Science Department',
        'date'     => '2026-03-19',
        'category' => 'Event',
        'priority' => 'Normal',
        'target'   => 'Students',
    ],
    [
        'title'    => 'Anti-Bullying Awareness Workshop',
        'body'     => 'An Anti-Bullying Awareness Workshop will be conducted on March 27th, 2026 during school hours. All students from classes 5th to 12th are required to attend. Trained counsellors from the District Education Office will conduct the sessions.',
        'author'   => 'Student Welfare Committee',
        'date'     => '2026-03-21',
        'category' => 'General',
        'priority' => 'Important',
        'target'   => 'Students',
    ],
    [
        'title'    => 'Library - New Books Available',
        'body'     => 'The school library has received a new collection of 200 books including NCERT reference guides, fiction novels, and competitive exam preparation material. Students can issue books during library periods or after school from 3:00 PM to 4:30 PM.',
        'author'   => 'Librarian - Mr. P. Srinivasan',
        'date'     => '2026-03-17',
        'category' => 'Academic',
        'priority' => 'Normal',
        'target'   => 'All',
    ],
    [
        'title'    => 'School Bus Route Change',
        'body'     => 'Due to road construction on MG Road, Bus Route No. 3 and Route No. 7 will be diverted via Ring Road effective March 25th, 2026. Pick-up times may vary by 10-15 minutes. Parents are requested to adjust accordingly and contact the transport office for queries.',
        'author'   => 'Transport Department',
        'date'     => '2026-03-24',
        'category' => 'General',
        'priority' => 'Urgent',
        'target'   => 'Parents',
    ],
];

foreach ($notices as $n) {
    $noticeId = pushId();
    $noticeData = [
        'title'         => $n['title'],
        'body'          => $n['body'],
        'author'        => $n['author'],
        'date'          => $n['date'],
        'category'      => $n['category'],
        'priority'      => $n['priority'],
        'target'        => $n['target'],
        'attachmentUrl' => '',
    ];
    $db->getReference("$noticePath/$noticeId")->set($noticeData);
    $counts['notices']++;
    echo "  [OK] Notice: {$n['title']} ({$n['category']}/{$n['priority']})\n";
    usleep(1000);
}

// ============================================================================
// 3. EVENTS
// ============================================================================
echo "\n=== 3. SEEDING EVENTS ===\n";

$eventPath = "$school/Events/List";

$events = [
    [
        'title'       => 'Annual Sports Day',
        'description' => 'The grand Annual Sports Day featuring track and field events, relay races, tug of war, and prize distribution. Students from all classes will participate in various athletic competitions.',
        'category'    => 'sports',
        'startDate'   => '2026-04-15',
        'endDate'     => '2026-04-15',
        'venue'       => 'School Main Ground',
        'organizer'   => 'Physical Education Department',
        'status'      => 'scheduled',
        'media'       => [
            ['url' => '', 'type' => 'image', 'caption' => 'Sports Day Banner'],
        ],
    ],
    [
        'title'       => 'Science Fair 2026',
        'description' => 'An exhibition of innovative science projects by students from classes 6th to 10th. Projects span physics, chemistry, biology, and environmental science with live demonstrations.',
        'category'    => 'event',
        'startDate'   => '2026-04-05',
        'endDate'     => '2026-04-05',
        'venue'       => 'School Auditorium & Labs',
        'organizer'   => 'Science Department',
        'status'      => 'scheduled',
        'media'       => [
            ['url' => '', 'type' => 'image', 'caption' => 'Science Fair Poster'],
        ],
    ],
    [
        'title'       => 'Republic Day Celebration',
        'description' => 'A patriotic celebration of India\'s 77th Republic Day with flag hoisting, march past, cultural performances, and speeches by students and distinguished guests.',
        'category'    => 'cultural',
        'startDate'   => '2026-01-26',
        'endDate'     => '2026-01-26',
        'venue'       => 'School Assembly Ground',
        'organizer'   => 'Cultural Committee',
        'status'      => 'completed',
        'media'       => [
            ['url' => '', 'type' => 'image', 'caption' => 'Republic Day Flag Hoisting'],
            ['url' => '', 'type' => 'image', 'caption' => 'March Past by NCC Cadets'],
        ],
    ],
    [
        'title'       => 'Holi Celebration',
        'description' => 'A joyful celebration of the festival of colours with organic colours, dance performances, traditional sweets, and cultural activities. Eco-friendly celebration promoting water conservation.',
        'category'    => 'cultural',
        'startDate'   => '2026-03-13',
        'endDate'     => '2026-03-13',
        'venue'       => 'School Courtyard',
        'organizer'   => 'Student Council',
        'status'      => 'completed',
        'media'       => [
            ['url' => '', 'type' => 'image', 'caption' => 'Holi Colour Play'],
        ],
    ],
    [
        'title'       => 'Parent-Teacher Meeting',
        'description' => 'A structured meeting for parents and teachers to discuss student academic progress, behavioural development, and areas of improvement. Individual progress reports will be distributed.',
        'category'    => 'event',
        'startDate'   => '2026-03-29',
        'endDate'     => '2026-03-29',
        'venue'       => 'Respective Classrooms',
        'organizer'   => 'Academic Office',
        'status'      => 'scheduled',
        'media'       => [
            ['url' => '', 'type' => 'image', 'caption' => 'PTM Schedule'],
        ],
    ],
    [
        'title'       => 'Inter-School Quiz Competition',
        'description' => 'A prestigious inter-school quiz competition with participating teams from 12 schools across the district. Rounds cover general knowledge, science, mathematics, and current affairs.',
        'category'    => 'event',
        'startDate'   => '2026-04-20',
        'endDate'     => '2026-04-20',
        'venue'       => 'School Auditorium',
        'organizer'   => 'Academic Excellence Committee',
        'status'      => 'scheduled',
        'media'       => [
            ['url' => '', 'type' => 'image', 'caption' => 'Quiz Competition Banner'],
        ],
    ],
];

foreach ($events as $ev) {
    $eventId = pushId();
    $db->getReference("$eventPath/$eventId")->set($ev);
    $counts['events']++;
    echo "  [OK] Event: {$ev['title']} ({$ev['status']})\n";
    usleep(1000);
}

// Event counter
$db->getReference("$school/Events/Counters/Event")->set(6);
echo "  [OK] Event counter set to 6\n";

// ============================================================================
// 4. FEE STRUCTURE
// ============================================================================
echo "\n=== 4. SEEDING FEE STRUCTURE ===\n";

$feeStructurePath = "$school/Config/FeeStructure";

$feeStructure = [
    'Tuition Fee'  => ['amount' => 2500, 'frequency' => 'month'],
    'Transport Fee' => ['amount' => 800,  'frequency' => 'month'],
    'Library Fee'  => ['amount' => 200,  'frequency' => 'month'],
    'Lab Fee'      => ['amount' => 300,  'frequency' => 'month'],
    'Sports Fee'   => ['amount' => 150,  'frequency' => 'month'],
    'Computer Fee' => ['amount' => 250,  'frequency' => 'month'],
];

$db->getReference($feeStructurePath)->set($feeStructure);
$counts['feeStructure'] = count($feeStructure);
echo "  [OK] Fee Structure: " . count($feeStructure) . " fee titles\n";
foreach ($feeStructure as $name => $info) {
    echo "       - $name: Rs. {$info['amount']}/{$info['frequency']}\n";
}

// ============================================================================
// 5. FEE PAYMENTS
// ============================================================================
echo "\n=== 5. SEEDING FEE PAYMENTS ===\n";

$feeBasePath = "$school/$session";

$studentFees = [
    // STU0004 - Class 9th / Section A (mixed paid/pending)
    [
        'path' => "$feeBasePath/Class 9th/Section A/Students/STU0004/Month Fee",
        'name' => 'STU0004 (Yuvraj Sharma)',
        'fees' => [
            'April' => 2500, 'May' => 2500, 'June' => 2500, 'July' => 0,
            'August' => 0, 'September' => 2500, 'October' => 2500, 'November' => 0,
            'December' => 2500, 'January' => 2500, 'February' => 2500, 'March' => 0,
        ],
    ],
    // STU0006 - Class 10th / Section B (mostly paid)
    [
        'path' => "$feeBasePath/Class 10th/Section B/Students/STU0006/Month Fee",
        'name' => 'STU0006 (Arjun Patel)',
        'fees' => [
            'April' => 2500, 'May' => 2500, 'June' => 2500, 'July' => 2500,
            'August' => 2500, 'September' => 2500, 'October' => 2500, 'November' => 2500,
            'December' => 2500, 'January' => 2500, 'February' => 2500, 'March' => 0,
        ],
    ],
    // STU0005 - Class 8th / Section A (several pending)
    [
        'path' => "$feeBasePath/Class 8th/Section A/Students/STU0005/Month Fee",
        'name' => 'STU0005 (Priya Reddy)',
        'fees' => [
            'April' => 2500, 'May' => 2500, 'June' => 0, 'July' => 0,
            'August' => 2500, 'September' => 0, 'October' => 2500, 'November' => 0,
            'December' => 0, 'January' => 2500, 'February' => 0, 'March' => 0,
        ],
    ],
    // STU0002 - Class 7th / Section B (mostly paid)
    [
        'path' => "$feeBasePath/Class 7th/Section B/Students/STU0002/Month Fee",
        'name' => 'STU0002 (Aarav Gupta)',
        'fees' => [
            'April' => 2500, 'May' => 2500, 'June' => 2500, 'July' => 2500,
            'August' => 2500, 'September' => 2500, 'October' => 2500, 'November' => 0,
            'December' => 2500, 'January' => 2500, 'February' => 2500, 'March' => 2500,
        ],
    ],
    // STU0010 - Class 9th / Section B (several pending)
    [
        'path' => "$feeBasePath/Class 9th/Section B/Students/STU0010/Month Fee",
        'name' => 'STU0010 (Aditya Nair)',
        'fees' => [
            'April' => 2500, 'May' => 0, 'June' => 2500, 'July' => 0,
            'August' => 0, 'September' => 2500, 'October' => 0, 'November' => 2500,
            'December' => 0, 'January' => 2500, 'February' => 0, 'March' => 0,
        ],
    ],
];

foreach ($studentFees as $sf) {
    $db->getReference($sf['path'])->set($sf['fees']);
    $paid = count(array_filter($sf['fees'], fn($v) => $v > 0));
    $pending = 12 - $paid;
    echo "  [OK] {$sf['name']}: $paid months paid, $pending months pending\n";
    $counts['feePayments']++;
}

// ============================================================================
// 6. LEAVE REQUESTS
// ============================================================================
echo "\n=== 6. SEEDING LEAVE REQUESTS ===\n";

$teacherBasePath = "$school/$session/Teachers";

$leaveRequests = [
    [
        'teacherId' => 'STA0002',
        'type'      => 'Casual Leave',
        'startDate' => '2026-03-15',
        'endDate'   => '2026-03-16',
        'reason'    => 'Family function - attending my cousin\'s wedding ceremony in Jaipur. Need two days for travel and event.',
        'status'    => 'Approved',
        'appliedAt' => $now - (10 * $day),
        'approvedBy' => 'Principal - Dr. S.K. Mehta',
        'remarks'   => 'Approved. Please ensure lesson plans are handed over to substitute teacher.',
    ],
    [
        'teacherId' => 'STA0003',
        'type'      => 'Sick Leave',
        'startDate' => '2026-03-20',
        'endDate'   => '2026-03-22',
        'reason'    => 'Suffering from viral fever and body ache. Doctor has advised complete rest for three days. Medical certificate attached.',
        'status'    => 'Approved',
        'appliedAt' => $now - (5 * $day),
        'approvedBy' => 'Principal - Dr. S.K. Mehta',
        'remarks'   => 'Approved. Get well soon. Please submit medical certificate upon return.',
    ],
    [
        'teacherId' => 'STA0004',
        'type'      => 'Casual Leave',
        'startDate' => '2026-04-01',
        'endDate'   => '2026-04-02',
        'reason'    => 'Need to visit the bank for property documentation work and attend a personal appointment at the district office.',
        'status'    => 'Pending',
        'appliedAt' => $now - (1 * $day),
        'approvedBy' => '',
        'remarks'   => '',
    ],
    [
        'teacherId' => 'TEA0003',
        'type'      => 'Earned Leave',
        'startDate' => '2026-04-10',
        'endDate'   => '2026-04-15',
        'reason'    => 'Planning a family vacation to Kerala during the pre-exam break period. Have accumulated sufficient earned leave balance.',
        'status'    => 'Pending',
        'appliedAt' => $now - (2 * $day),
        'approvedBy' => '',
        'remarks'   => '',
    ],
    [
        'teacherId' => 'STA0006',
        'type'      => 'Sick Leave',
        'startDate' => '2026-02-10',
        'endDate'   => '2026-02-11',
        'reason'    => 'Severe migraine and dizziness since morning. Unable to commute safely. Will consult doctor today.',
        'status'    => 'Approved',
        'appliedAt' => $now - (42 * $day),
        'approvedBy' => 'Vice Principal - Mrs. R. Sharma',
        'remarks'   => 'Approved. Take care of your health.',
    ],
];

foreach ($leaveRequests as $lr) {
    $leaveId = pushId();
    $leavePath = "$teacherBasePath/{$lr['teacherId']}/Leave/$leaveId";
    $leaveData = [
        'type'       => $lr['type'],
        'startDate'  => $lr['startDate'],
        'endDate'    => $lr['endDate'],
        'reason'     => $lr['reason'],
        'status'     => $lr['status'],
        'appliedAt'  => $lr['appliedAt'],
        'approvedBy' => $lr['approvedBy'],
        'remarks'    => $lr['remarks'],
    ];
    $db->getReference($leavePath)->set($leaveData);
    $counts['leaveRequests']++;
    echo "  [OK] Leave: {$lr['teacherId']} - {$lr['type']} ({$lr['startDate']} to {$lr['endDate']}) - {$lr['status']}\n";
    usleep(1000);
}

// ============================================================================
// SUMMARY
// ============================================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "SEED COMPLETE - SUMMARY\n";
echo str_repeat('=', 60) . "\n";
echo "School:          SCH_9738C22243\n";
echo "Session:         $session\n";
echo "Timestamp:       " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 60) . "\n";
echo "Conversations:   {$counts['conversations']}\n";
echo "Messages:        {$counts['messages']}\n";
echo "Flagged Keywords: " . count($flaggedKeywords) . "\n";
echo "Notices:         {$counts['notices']}\n";
echo "Events:          {$counts['events']} (+ counter)\n";
echo "Fee Titles:      {$counts['feeStructure']}\n";
echo "Fee Payments:    {$counts['feePayments']} students\n";
echo "Leave Requests:  {$counts['leaveRequests']}\n";
echo str_repeat('-', 60) . "\n";
echo "Total DB writes: ~" . ($counts['conversations'] + 1 + $counts['notices'] + $counts['events'] + 1 + 1 + $counts['feePayments'] + $counts['leaveRequests']) . "\n";
echo str_repeat('=', 60) . "\n";
echo "Done!\n";
