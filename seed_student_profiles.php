<?php
/**
 * Seed comprehensive student profiles and attendance data into Firebase
 * School: SCH_9738C22243, Session: 2026-27
 *
 * Usage: php seed_student_profiles.php 2>/dev/null
 */
error_reporting(E_ERROR);

require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

// ── Firebase init ──────────────────────────────────────────────────────────
$serviceAccountPath = __DIR__ . '/application/config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
$databaseUri        = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

$factory = (new Factory)
    ->withServiceAccount($serviceAccountPath)
    ->withDatabaseUri($databaseUri);

$f = $factory->createDatabase();

$school  = 'SCH_9738C22243';
$session = '2026-27';
$base    = "Schools/{$school}/{$session}";

echo "=== Student Profile & Attendance Seeder ===\n";
echo "School:  {$school}\n";
echo "Session: {$session}\n";
echo "Base:    {$base}\n\n";

// ── Student data ───────────────────────────────────────────────────────────
// Format: class => section => [ studentId => [Name, gender, rollNo, ...profile fields] ]
// We build full profiles with realistic Indian student data.

$students = [
    // ── Class 9th / Section A ──────────────────────────────────────────────
    '9th' => [
        'Section A' => [
            'STU0004' => [
                'Name'          => 'Yuvraj Singh',
                'rollNo'        => '04',
                'fatherName'    => 'Harpreet Singh',
                'motherName'    => 'Gurpreet Kaur',
                'phone'         => '9876543204',
                'email'         => 'yuvraj.singh@school.edu',
                'dob'           => '2012-05-15',
                'gender'        => 'Male',
                'admissionDate' => '2023-04-01',
                'address'       => '12, Rajpur Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'B+',
            ],
            'STU0005' => [
                'Name'          => 'Priya Sharma',
                'rollNo'        => '05',
                'fatherName'    => 'Rakesh Sharma',
                'motherName'    => 'Sunita Sharma',
                'phone'         => '9876543205',
                'email'         => 'priya.sharma@school.edu',
                'dob'           => '2012-08-22',
                'gender'        => 'Female',
                'admissionDate' => '2023-04-01',
                'address'       => '45, MG Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'A+',
            ],
            'STU0006' => [
                'Name'          => 'Rahul Kumar',
                'rollNo'        => '06',
                'fatherName'    => 'Suresh Kumar',
                'motherName'    => 'Meena Devi',
                'phone'         => '9876543206',
                'email'         => 'rahul.kumar@school.edu',
                'dob'           => '2012-03-10',
                'gender'        => 'Male',
                'admissionDate' => '2023-04-01',
                'address'       => '78, Haridwar Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'O+',
            ],
            'STU0007' => [
                'Name'          => 'Ananya Patel',
                'rollNo'        => '07',
                'fatherName'    => 'Vikash Patel',
                'motherName'    => 'Asha Patel',
                'phone'         => '9876543207',
                'email'         => 'ananya.patel@school.edu',
                'dob'           => '2012-11-05',
                'gender'        => 'Female',
                'admissionDate' => '2023-04-01',
                'address'       => '23, Clement Town, Dehradun, Uttarakhand',
                'bloodGroup'    => 'AB+',
            ],
            'STU0008' => [
                'Name'          => 'Arjun Verma',
                'rollNo'        => '08',
                'fatherName'    => 'Dinesh Verma',
                'motherName'    => 'Kavita Verma',
                'phone'         => '9876543208',
                'email'         => 'arjun.verma@school.edu',
                'dob'           => '2012-01-18',
                'gender'        => 'Male',
                'admissionDate' => '2023-04-01',
                'address'       => '56, Ballupur Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'B-',
            ],
            // NEW students
            'STU0030' => [
                'Name'          => 'Meera Joshi',
                'rollNo'        => '30',
                'fatherName'    => 'Prakash Joshi',
                'motherName'    => 'Rekha Joshi',
                'phone'         => '9876543230',
                'email'         => 'meera.joshi@school.edu',
                'dob'           => '2012-07-09',
                'gender'        => 'Female',
                'admissionDate' => '2025-04-01',
                'address'       => '34, Sahastradhara Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'A-',
            ],
            'STU0031' => [
                'Name'          => 'Karan Malhotra',
                'rollNo'        => '31',
                'fatherName'    => 'Rajiv Malhotra',
                'motherName'    => 'Neelam Malhotra',
                'phone'         => '9876543231',
                'email'         => 'karan.malhotra@school.edu',
                'dob'           => '2012-09-25',
                'gender'        => 'Male',
                'admissionDate' => '2025-04-01',
                'address'       => '89, Mussoorie Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'O-',
            ],
        ],
        // ── Class 9th / Section B ──────────────────────────────────────────
        'Section B' => [
            'STU0010' => [
                'Name'          => 'Amit Verma',
                'rollNo'        => '10',
                'fatherName'    => 'Ramesh Verma',
                'motherName'    => 'Sushila Verma',
                'phone'         => '9876543210',
                'email'         => 'amit.verma@school.edu',
                'dob'           => '2012-04-12',
                'gender'        => 'Male',
                'admissionDate' => '2023-04-01',
                'address'       => '15, Patel Nagar, Dehradun, Uttarakhand',
                'bloodGroup'    => 'A+',
            ],
            'STU0011' => [
                'Name'          => 'Kavita Devi',
                'rollNo'        => '11',
                'fatherName'    => 'Mohan Lal',
                'motherName'    => 'Geeta Devi',
                'phone'         => '9876543211',
                'email'         => 'kavita.devi@school.edu',
                'dob'           => '2012-06-30',
                'gender'        => 'Female',
                'admissionDate' => '2023-04-01',
                'address'       => '67, Kanwali Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'B+',
            ],
            'STU0012' => [
                'Name'          => 'Rohan Joshi',
                'rollNo'        => '12',
                'fatherName'    => 'Ashok Joshi',
                'motherName'    => 'Manju Joshi',
                'phone'         => '9876543212',
                'email'         => 'rohan.joshi@school.edu',
                'dob'           => '2012-02-14',
                'gender'        => 'Male',
                'admissionDate' => '2023-04-01',
                'address'       => '91, Rajender Nagar, Dehradun, Uttarakhand',
                'bloodGroup'    => 'O+',
            ],
            // NEW students
            'STU0032' => [
                'Name'          => 'Riya Pandey',
                'rollNo'        => '32',
                'fatherName'    => 'Sanjay Pandey',
                'motherName'    => 'Anita Pandey',
                'phone'         => '9876543232',
                'email'         => 'riya.pandey@school.edu',
                'dob'           => '2012-12-03',
                'gender'        => 'Female',
                'admissionDate' => '2025-04-01',
                'address'       => '22, Dalanwala, Dehradun, Uttarakhand',
                'bloodGroup'    => 'AB-',
            ],
            'STU0033' => [
                'Name'          => 'Sahil Gupta',
                'rollNo'        => '33',
                'fatherName'    => 'Anil Gupta',
                'motherName'    => 'Pooja Gupta',
                'phone'         => '9876543233',
                'email'         => 'sahil.gupta@school.edu',
                'dob'           => '2012-10-19',
                'gender'        => 'Male',
                'admissionDate' => '2025-04-01',
                'address'       => '44, Turner Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'B+',
            ],
        ],
    ],

    // ── Class 8th ──────────────────────────────────────────────────────────
    '8th' => [
        'Section A' => [
            'STU0002' => [
                'Name'          => 'Ayush Chauhan',
                'rollNo'        => '02',
                'fatherName'    => 'Manoj Chauhan',
                'motherName'    => 'Seema Chauhan',
                'phone'         => '9876543202',
                'email'         => 'ayush.chauhan@school.edu',
                'dob'           => '2013-03-28',
                'gender'        => 'Male',
                'admissionDate' => '2023-04-01',
                'address'       => '33, Race Course, Dehradun, Uttarakhand',
                'bloodGroup'    => 'O+',
            ],
            // NEW students
            'STU0036' => [
                'Name'          => 'Tanvi Sharma',
                'rollNo'        => '36',
                'fatherName'    => 'Pankaj Sharma',
                'motherName'    => 'Ritu Sharma',
                'phone'         => '9876543236',
                'email'         => 'tanvi.sharma@school.edu',
                'dob'           => '2013-06-14',
                'gender'        => 'Female',
                'admissionDate' => '2025-04-01',
                'address'       => '71, Subhash Nagar, Dehradun, Uttarakhand',
                'bloodGroup'    => 'A+',
            ],
            'STU0037' => [
                'Name'          => 'Harsh Patel',
                'rollNo'        => '37',
                'fatherName'    => 'Bharat Patel',
                'motherName'    => 'Hema Patel',
                'phone'         => '9876543237',
                'email'         => 'harsh.patel@school.edu',
                'dob'           => '2013-01-07',
                'gender'        => 'Male',
                'admissionDate' => '2025-04-01',
                'address'       => '18, Nehru Colony, Dehradun, Uttarakhand',
                'bloodGroup'    => 'B-',
            ],
        ],
        'Section B' => [
            'STU0003' => [
                'Name'          => 'Shivani Yadav',
                'rollNo'        => '03',
                'fatherName'    => 'Rajendra Yadav',
                'motherName'    => 'Poonam Yadav',
                'phone'         => '9876543203',
                'email'         => 'shivani.yadav@school.edu',
                'dob'           => '2013-09-11',
                'gender'        => 'Female',
                'admissionDate' => '2023-04-01',
                'address'       => '55, Lakhi Bagh, Dehradun, Uttarakhand',
                'bloodGroup'    => 'AB+',
            ],
        ],
    ],

    // ── Class 10th ─────────────────────────────────────────────────────────
    '10th' => [
        'Section A' => [
            'STU0020' => [
                'Name'          => 'Vikram Rathore',
                'rollNo'        => '20',
                'fatherName'    => 'Mahendra Rathore',
                'motherName'    => 'Pushpa Rathore',
                'phone'         => '9876543220',
                'email'         => 'vikram.rathore@school.edu',
                'dob'           => '2011-07-20',
                'gender'        => 'Male',
                'admissionDate' => '2022-04-01',
                'address'       => '10, Connaught Place, Dehradun, Uttarakhand',
                'bloodGroup'    => 'A+',
            ],
            'STU0021' => [
                'Name'          => 'Sunita Mehra',
                'rollNo'        => '21',
                'fatherName'    => 'Naresh Mehra',
                'motherName'    => 'Kiran Mehra',
                'phone'         => '9876543221',
                'email'         => 'sunita.mehra@school.edu',
                'dob'           => '2011-11-02',
                'gender'        => 'Female',
                'admissionDate' => '2022-04-01',
                'address'       => '28, Vasant Vihar, Dehradun, Uttarakhand',
                'bloodGroup'    => 'O-',
            ],
            'STU0022' => [
                'Name'          => 'Deepak Tiwari',
                'rollNo'        => '22',
                'fatherName'    => 'Shyam Tiwari',
                'motherName'    => 'Savitri Tiwari',
                'phone'         => '9876543222',
                'email'         => 'deepak.tiwari@school.edu',
                'dob'           => '2011-04-08',
                'gender'        => 'Male',
                'admissionDate' => '2022-04-01',
                'address'       => '63, Hathibarkala, Dehradun, Uttarakhand',
                'bloodGroup'    => 'B+',
            ],
            // NEW students
            'STU0034' => [
                'Name'          => 'Nisha Agarwal',
                'rollNo'        => '34',
                'fatherName'    => 'Vinod Agarwal',
                'motherName'    => 'Saroj Agarwal',
                'phone'         => '9876543234',
                'email'         => 'nisha.agarwal@school.edu',
                'dob'           => '2011-08-16',
                'gender'        => 'Female',
                'admissionDate' => '2025-04-01',
                'address'       => '39, Karanpur, Dehradun, Uttarakhand',
                'bloodGroup'    => 'A-',
            ],
            'STU0035' => [
                'Name'          => 'Rohit Chauhan',
                'rollNo'        => '35',
                'fatherName'    => 'Lalit Chauhan',
                'motherName'    => 'Usha Chauhan',
                'phone'         => '9876543235',
                'email'         => 'rohit.chauhan@school.edu',
                'dob'           => '2011-02-25',
                'gender'        => 'Male',
                'admissionDate' => '2025-04-01',
                'address'       => '82, Raipur Road, Dehradun, Uttarakhand',
                'bloodGroup'    => 'O+',
            ],
        ],
    ],
];

// ── Attendance patterns ────────────────────────────────────────────────────
// We define attendance "profiles" (good/average/poor) and assign them per student.
// Days: 1=Mon ... 7=Sun.  Sundays are always H (holiday).
// Saturdays: alternate — some schools have alternate Saturdays off.
//
// Jan 2026: 31 days. Starts on Thursday (day 4).
// Feb 2026: 28 days. Starts on Sunday (day 7).
// Mar 2026: 31 days (only 24 chars). Starts on Sunday (day 7).
//   Holi: Mar 14 (Sat) and Mar 15 (Sun) — mark Mar 13 (Fri) and Mar 14 (Sat) as H.
//   Republic Day: Jan 26 (Mon) — H.

// Attendance category per student ID
// good = 90%+ present, average = 70-80%, poor = below 60%
$attendanceCategory = [
    'STU0004' => 'good',      // Yuvraj Singh — diligent
    'STU0005' => 'good',      // Priya Sharma — top student
    'STU0006' => 'average',   // Rahul Kumar — misses some days
    'STU0007' => 'good',      // Ananya Patel — consistent
    'STU0008' => 'average',   // Arjun Verma — average
    'STU0030' => 'good',      // Meera Joshi — new, eager
    'STU0031' => 'poor',      // Karan Malhotra — frequently absent
    'STU0010' => 'good',      // Amit Verma — reliable
    'STU0011' => 'average',   // Kavita Devi — some health issues
    'STU0012' => 'good',      // Rohan Joshi — consistent
    'STU0032' => 'average',   // Riya Pandey — adjusting
    'STU0033' => 'good',      // Sahil Gupta — good
    'STU0002' => 'good',      // Ayush Chauhan — diligent
    'STU0036' => 'average',   // Tanvi Sharma — average
    'STU0037' => 'poor',      // Harsh Patel — frequent absences
    'STU0003' => 'good',      // Shivani Yadav — consistent
    'STU0020' => 'good',      // Vikram Rathore — board year, focused
    'STU0021' => 'average',   // Sunita Mehra — some absences
    'STU0022' => 'good',      // Deepak Tiwari — diligent
    'STU0034' => 'good',      // Nisha Agarwal — new transfer, motivated
    'STU0035' => 'poor',      // Rohit Chauhan — irregular
];

/**
 * Get day-of-week (1=Mon..7=Sun) for a given date.
 */
function getDow(int $year, int $month, int $day): int {
    return (int) date('N', mktime(0, 0, 0, $month, $day, $year));
}

/**
 * Generate an attendance string for a given month.
 *
 * @param int    $year
 * @param int    $month
 * @param int    $numDays  Number of chars to generate (may be less than days in month)
 * @param string $category 'good', 'average', or 'poor'
 * @param string $studentId  Used as seed offset for deterministic but varied patterns
 * @return string  Attendance string
 */
function generateAttendance(int $year, int $month, int $numDays, string $category, string $studentId): string {
    // Use student ID numeric part as seed offset for reproducible variety
    $seedNum = (int) preg_replace('/\D/', '', $studentId);
    // Deterministic seed per student+month so patterns are stable but varied
    mt_srand($seedNum * 100 + $month + $year);

    // Absence probability on working days
    $absenceProb = match($category) {
        'good'    => 0.05,   // ~5% chance per working day
        'average' => 0.18,   // ~18%
        'poor'    => 0.38,   // ~38%
    };

    // Leave probability (subset of non-present days)
    $leaveProb = match($category) {
        'good'    => 0.02,
        'average' => 0.05,
        'poor'    => 0.04,
    };

    // Special dates
    $holidays = [];

    // Republic Day: Jan 26
    if ($month === 1) {
        $holidays[26] = true;
        // Winter vacation: Jan 1-5 (returning from break)
        for ($d = 1; $d <= 5; $d++) $holidays[$d] = 'V';
    }

    // Holi holidays: Mar 13, 14 (Fri, Sat before Holi on Mar 15 Sun)
    if ($month === 3) {
        $holidays[13] = true;
        $holidays[14] = true;
        // Also mark Mar 16 (Mon after Holi) as holiday
        $holidays[16] = true;
    }

    // Feb: no special national holidays but school may have one day off
    if ($month === 2) {
        // Basant Panchami approx Feb 12
        $holidays[12] = true;
    }

    $att = '';
    // Track which Saturdays are off (alternate: 2nd and 4th Saturdays)
    $satCount = 0;

    for ($day = 1; $day <= $numDays; $day++) {
        $dow = getDow($year, $month, $day);

        // Count Saturdays
        if ($dow === 6) {
            $satCount++;
        }

        // Sunday = always holiday
        if ($dow === 7) {
            $att .= 'H';
            continue;
        }

        // 2nd and 4th Saturday = holiday
        if ($dow === 6 && ($satCount === 2 || $satCount === 4)) {
            $att .= 'H';
            continue;
        }

        // Check special holidays
        if (isset($holidays[$day])) {
            if ($holidays[$day] === 'V') {
                $att .= 'V';
            } else {
                $att .= 'H';
            }
            continue;
        }

        // Check for trip day — about 1 per month for variety
        if ($month === 2 && $day === 20) {
            $att .= 'T';
            continue;
        }
        if ($month === 1 && $day === 15) {
            $att .= 'T';
            continue;
        }

        // Working day — decide P, A, or L
        $rand = mt_rand(0, 1000) / 1000.0;
        if ($rand < $leaveProb) {
            $att .= 'L';
        } elseif ($rand < $absenceProb + $leaveProb) {
            $att .= 'A';
        } else {
            $att .= 'P';
        }
    }

    return $att;
}

// ── Counters for summary ───────────────────────────────────────────────────
$profileCount    = 0;
$listCount       = 0;
$attendanceCount = 0;
$errorCount      = 0;

// ── Seed profiles + List + Attendance ──────────────────────────────────────
foreach ($students as $class => $sections) {
    foreach ($sections as $section => $stuList) {
        foreach ($stuList as $stuId => $profile) {
            $stuPath  = "{$base}/{$class}/{$section}/Students/{$stuId}";
            $listPath = "{$base}/{$class}/{$section}/Students/List/{$stuId}";

            // ── 1) Full profile (merge) ────────────────────────────────────
            echo "[PROFILE] {$stuPath} -> {$profile['Name']} ... ";
            try {
                $f->getReference($stuPath)->update($profile);
                $profileCount++;
                echo "OK\n";
            } catch (\Exception $e) {
                $errorCount++;
                echo "ERROR: " . $e->getMessage() . "\n";
            }

            // ── 2) List node ───────────────────────────────────────────────
            $genderShort = ($profile['gender'] === 'Male') ? 'Male' : 'Female';
            $listData = [
                'Name'    => $profile['Name'],
                'Roll_no' => $profile['rollNo'],
                'Gender'  => $genderShort,
            ];

            echo "[LIST]    {$listPath} ... ";
            try {
                $f->getReference($listPath)->update($listData);
                $listCount++;
                echo "OK\n";
            } catch (\Exception $e) {
                $errorCount++;
                echo "ERROR: " . $e->getMessage() . "\n";
            }

            // ── 3) Attendance for Jan, Feb, Mar 2026 ───────────────────────
            $category = $attendanceCategory[$stuId] ?? 'average';

            $months = [
                'January 2026'  => ['year' => 2026, 'month' => 1, 'days' => 31],
                'February 2026' => ['year' => 2026, 'month' => 2, 'days' => 28],
                'March 2026'    => ['year' => 2026, 'month' => 3, 'days' => 24], // only through day 24
            ];

            foreach ($months as $label => $info) {
                $attString = generateAttendance(
                    $info['year'], $info['month'], $info['days'],
                    $category, $stuId
                );

                $attPath = "{$stuPath}/Attendance/{$label}";

                echo "[ATTEND]  {$attPath} ({$category}) [{$attString}] ... ";
                try {
                    $f->getReference($attPath)->update(['value' => $attString]);
                    $attendanceCount++;
                    echo "OK\n";
                } catch (\Exception $e) {
                    $errorCount++;
                    echo "ERROR: " . $e->getMessage() . "\n";
                }
            }

            echo "\n";
        }
    }
}

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n";
echo "========================================\n";
echo "           SEEDING SUMMARY\n";
echo "========================================\n";
echo "Profiles created/updated : {$profileCount}\n";
echo "List entries updated     : {$listCount}\n";
echo "Attendance records       : {$attendanceCount}\n";
echo "Errors                   : {$errorCount}\n";
echo "Total Firebase writes    : " . ($profileCount + $listCount + $attendanceCount) . "\n";
echo "========================================\n";

if ($errorCount === 0) {
    echo "All operations completed successfully.\n";
} else {
    echo "WARNING: {$errorCount} error(s) occurred. Check output above.\n";
}
