<?php
/**
 * Seed script: Comprehensive Student Fee Demands, Payments, Receipts,
 *              Pending Fees, Defaulters, Discounts, Vouchers, Audit Logs,
 *              Dashboard Cache, Refunds, Online Orders
 *
 * School: SCH_9738C22243 | Session: 2026-27 | parentDbKey: 10005
 */
error_reporting(E_ERROR);

require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

$serviceAccountPath = __DIR__ . '/application/config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
$databaseUri        = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

$factory = (new Factory)
    ->withServiceAccount($serviceAccountPath)
    ->withDatabaseUri($databaseUri);

$db = $factory->createDatabase();

$school       = 'Schools/SCH_9738C22243';
$session      = '2026-27';
$base         = "$school/$session";
$schoolId     = '10005';

// ────────────────────────────────────────────────────────────────────────────
// Constants
// ────────────────────────────────────────────────────────────────────────────
$months = ['April','May','June','July','August','September','October','November','December','January','February','March'];

// Month number mapping for date generation (session year: Apr-Dec 2026, Jan-Mar 2027)
$monthNum = [
    'April'=>'04','May'=>'05','June'=>'06','July'=>'07','August'=>'08',
    'September'=>'09','October'=>'10','November'=>'11','December'=>'12',
    'January'=>'01','February'=>'02','March'=>'03'
];
$monthYear = [
    'April'=>'2026','May'=>'2026','June'=>'2026','July'=>'2026','August'=>'2026',
    'September'=>'2026','October'=>'2026','November'=>'2026','December'=>'2026',
    'January'=>'2027','February'=>'2027','March'=>'2027'
];

$feeItems9 = ['Tuition Fee'=>2500, 'Computer Fee'=>250, 'Sports Fee'=>150, 'Library Fee'=>200];
$feeItems10 = ['Tuition Fee'=>3000, 'Computer Fee'=>300, 'Sports Fee'=>150, 'Library Fee'=>200];
$feeItems8 = ['Tuition Fee'=>2500, 'Computer Fee'=>250, 'Sports Fee'=>150, 'Library Fee'=>200];

$monthly9  = 3100;
$monthly10 = 3650;
$monthly8  = 3100;

// ────────────────────────────────────────────────────────────────────────────
// Students
// ────────────────────────────────────────────────────────────────────────────
$students = [
    'STU0004' => ['name'=>'Yuvraj Singh',    'class'=>'Class 9th',  'section'=>'Section A', 'roll'=>1, 'monthly'=>$monthly9,  'feeItems'=>$feeItems9],
    'STU0005' => ['name'=>'Priya Sharma',    'class'=>'Class 9th',  'section'=>'Section A', 'roll'=>2, 'monthly'=>$monthly9,  'feeItems'=>$feeItems9],
    'STU0006' => ['name'=>'Rahul Kumar',     'class'=>'Class 9th',  'section'=>'Section A', 'roll'=>3, 'monthly'=>$monthly9,  'feeItems'=>$feeItems9],
    'STU0007' => ['name'=>'Ananya Patel',    'class'=>'Class 9th',  'section'=>'Section A', 'roll'=>4, 'monthly'=>$monthly9,  'feeItems'=>$feeItems9],
    'STU0008' => ['name'=>'Arjun Verma',     'class'=>'Class 9th',  'section'=>'Section A', 'roll'=>5, 'monthly'=>$monthly9,  'feeItems'=>$feeItems9],
    'STU0020' => ['name'=>'Vikram Rathore',  'class'=>'Class 10th', 'section'=>'Section A', 'roll'=>1, 'monthly'=>$monthly10, 'feeItems'=>$feeItems10],
    'STU0002' => ['name'=>'Ayush Chauhan',   'class'=>'Class 8th',  'section'=>'Section A', 'roll'=>1, 'monthly'=>$monthly8,  'feeItems'=>$feeItems8],
];

// ────────────────────────────────────────────────────────────────────────────
// Payment patterns: each student -> month => amount paid
// ────────────────────────────────────────────────────────────────────────────

// STU0004: Apr-Sep paid, Oct partial 1500, Nov partial 900, Dec-Mar unpaid
$pay['STU0004'] = [];
foreach (['April','May','June','July','August','September'] as $m) $pay['STU0004'][$m] = 3100;
$pay['STU0004']['October']   = 1500;
$pay['STU0004']['November']  = 900;
$pay['STU0004']['December']  = 0;
$pay['STU0004']['January']   = 0;
$pay['STU0004']['February']  = 0;
$pay['STU0004']['March']     = 0;

// STU0005: Scholarship = net 2600/month. Apr-Feb paid, Mar unpaid
$pay['STU0005'] = [];
foreach (['April','May','June','July','August','September','October','November','December','January','February'] as $m) $pay['STU0005'][$m] = 2600;
$pay['STU0005']['March'] = 0;

// STU0006: Only Apr-May paid, rest unpaid (DEFAULTER)
$pay['STU0006'] = [];
$pay['STU0006']['April'] = 3100;
$pay['STU0006']['May']   = 3100;
foreach (['June','July','August','September','October','November','December','January','February','March'] as $m) $pay['STU0006'][$m] = 0;

// STU0007: All paid through Feb, Mar pending
$pay['STU0007'] = [];
foreach (['April','May','June','July','August','September','October','November','December','January','February'] as $m) $pay['STU0007'][$m] = 3100;
$pay['STU0007']['March'] = 0;

// STU0008: All 12 months paid (will also have advance)
$pay['STU0008'] = [];
foreach ($months as $m) $pay['STU0008'][$m] = 3100;

// STU0020: Apr-Jan paid, Feb-Mar unpaid (Class 10th = 3650)
$pay['STU0020'] = [];
foreach (['April','May','June','July','August','September','October','November','December','January'] as $m) $pay['STU0020'][$m] = 3650;
$pay['STU0020']['February'] = 0;
$pay['STU0020']['March']    = 0;

// STU0002: Apr-Dec paid, Jan-Mar unpaid (Class 8th = 3100)
$pay['STU0002'] = [];
foreach (['April','May','June','July','August','September','October','November','December'] as $m) $pay['STU0002'][$m] = 3100;
$pay['STU0002']['January']  = 0;
$pay['STU0002']['February'] = 0;
$pay['STU0002']['March']    = 0;

// ────────────────────────────────────────────────────────────────────────────
// Counters
// ────────────────────────────────────────────────────────────────────────────
$counts = [
    'demands'        => 0,
    'monthFeeRecs'   => 0,
    'receipts'       => 0,
    'feeRecords'     => 0,
    'pendingFees'    => 0,
    'defaulters'     => 0,
    'advanceBalance' => 0,
    'discounts'      => 0,
    'vouchers'       => 0,
    'auditLogs'      => 0,
    'dashboardCache' => 0,
    'refunds'        => 0,
    'onlineOrders'   => 0,
];

// ============================================================================
// 1. FEE DEMANDS  (Fees/Demands/{studentId}/{demandId})
// ============================================================================
echo "=== 1. SEEDING FEE DEMANDS ===\n";

$demandCounter = 1;
foreach ($students as $stuId => $stu) {
    foreach ($months as $idx => $month) {
        $demandId = 'DEM' . str_pad($demandCounter, 4, '0', STR_PAD_LEFT);
        $gross = $stu['monthly'];

        // Priya gets scholarship discount of 500/month
        $discount = 0;
        $net = $gross;
        if ($stuId === 'STU0005') {
            $discount = 500;
            $net = $gross - $discount;
        }

        $paidAmt = $pay[$stuId][$month];

        // Determine status
        if ($paidAmt >= $net) {
            $status = 'paid';
        } elseif ($paidAmt > 0) {
            $status = 'partial';
        } else {
            // Unpaid: check if overdue (month has passed relative to Mar 2027 being current)
            // For simplicity: months before March 2027 that are unpaid = overdue
            $monthIdx = $idx; // 0=Apr, 11=Mar
            if ($monthIdx < 11) {
                $status = 'overdue';
            } else {
                $status = 'unpaid';
            }
        }

        $yr = $monthYear[$month];
        $mn = $monthNum[$month];
        $createdAt = "$yr-$mn-01";

        // updated_at: if paid, set a realistic payment date; otherwise same as created
        if ($paidAmt > 0) {
            $payDay = str_pad(rand(3, 15), 2, '0', STR_PAD_LEFT);
            $updatedAt = "$yr-$mn-$payDay";
        } else {
            $updatedAt = $createdAt;
        }

        $demandData = [
            'demand_id'       => $demandId,
            'student_id'      => $stuId,
            'student_name'    => $stu['name'],
            'class'           => $stu['class'],
            'section'         => $stu['section'],
            'month'           => $month,
            'gross_amount'    => $gross,
            'net_amount'      => $net,
            'paid_amount'     => $paidAmt,
            'fine_amount'     => 0,
            'discount_amount' => $discount,
            'status'          => $status,
            'fee_items'       => $stu['feeItems'],
            'created_at'      => $createdAt,
            'updated_at'      => $updatedAt,
        ];

        $db->getReference("$base/Fees/Demands/$stuId/$demandId")->set($demandData);
        $counts['demands']++;
        $demandCounter++;
    }
}
echo "   Created {$counts['demands']} fee demands across " . count($students) . " students.\n";

// ============================================================================
// 2. MONTHLY FEE RECORDS ({class}/{section}/Students/{studentId}/Month Fee)
// ============================================================================
echo "\n=== 2. SEEDING MONTHLY FEE RECORDS ===\n";

foreach ($students as $stuId => $stu) {
    $monthFee = [];
    foreach ($months as $month) {
        $monthFee[$month] = $pay[$stuId][$month];
    }
    $classPath = str_replace(' ', '_', $stu['class']);
    $sectionPath = str_replace(' ', '_', $stu['section']);
    $db->getReference("$base/$classPath/$sectionPath/Students/$stuId/Month Fee")->set($monthFee);
    $counts['monthFeeRecs']++;
}
echo "   Created {$counts['monthFeeRecs']} monthly fee records.\n";

// ============================================================================
// 3. RECEIPTS  (Accounts/Receipt_Index/{receiptNo})
// ============================================================================
echo "\n=== 3. SEEDING RECEIPTS ===\n";

$receiptNum = 1001;
$receiptKeys = []; // receiptNo => receiptKey for cross-referencing
$receiptData = []; // store for vouchers and fee records
$paymentModes = ['Cash', 'Online', 'Cheque'];

// Build receipts for paid months of each student
// We'll track which receipts belong to which student
$allReceipts = [];

// STU0004: Apr-Sep full, Oct partial 1500, Nov partial 900 = 8 receipts
foreach (['April','May','June','July','August','September'] as $m) {
    $allReceipts[] = ['stu'=>'STU0004', 'month'=>$m, 'amount'=>3100, 'feeItems'=>$feeItems9];
}
$allReceipts[] = ['stu'=>'STU0004', 'month'=>'October',  'amount'=>1500, 'feeItems'=>['Tuition Fee'=>1100, 'Computer Fee'=>200, 'Sports Fee'=>100, 'Library Fee'=>100]];
$allReceipts[] = ['stu'=>'STU0004', 'month'=>'November', 'amount'=>900,  'feeItems'=>['Tuition Fee'=>600, 'Computer Fee'=>150, 'Sports Fee'=>100, 'Library Fee'=>50]];

// STU0005: Apr-Feb paid at 2600 (scholarship) = 11 receipts -- we'll pick a subset to stay near 30 total
// Let's do quarterly bundles for STU0005: Apr-Jun, Jul-Sep, Oct-Dec, Jan-Feb = 4 receipts
$allReceipts[] = ['stu'=>'STU0005', 'month'=>'April,May,June',            'amount'=>7800, 'feeItems'=>['Tuition Fee'=>6000,'Computer Fee'=>600,'Sports Fee'=>360,'Library Fee'=>540, 'Scholarship Discount'=>-1500], 'months_arr'=>['April','May','June']];
$allReceipts[] = ['stu'=>'STU0005', 'month'=>'July,August,September',     'amount'=>7800, 'feeItems'=>['Tuition Fee'=>6000,'Computer Fee'=>600,'Sports Fee'=>360,'Library Fee'=>540, 'Scholarship Discount'=>-1500], 'months_arr'=>['July','August','September']];
$allReceipts[] = ['stu'=>'STU0005', 'month'=>'October,November,December', 'amount'=>7800, 'feeItems'=>['Tuition Fee'=>6000,'Computer Fee'=>600,'Sports Fee'=>360,'Library Fee'=>540, 'Scholarship Discount'=>-1500], 'months_arr'=>['October','November','December']];
$allReceipts[] = ['stu'=>'STU0005', 'month'=>'January,February',          'amount'=>5200, 'feeItems'=>['Tuition Fee'=>4000,'Computer Fee'=>400,'Sports Fee'=>240,'Library Fee'=>360, 'Scholarship Discount'=>-1000], 'months_arr'=>['January','February']];

// STU0006: Apr-May paid = 2 receipts
$allReceipts[] = ['stu'=>'STU0006', 'month'=>'April', 'amount'=>3100, 'feeItems'=>$feeItems9];
$allReceipts[] = ['stu'=>'STU0006', 'month'=>'May',   'amount'=>3100, 'feeItems'=>$feeItems9];

// STU0007: Apr-Feb paid = pick 6 receipts (bimonthly bundles)
$allReceipts[] = ['stu'=>'STU0007', 'month'=>'April,May',         'amount'=>6200, 'feeItems'=>['Tuition Fee'=>5000,'Computer Fee'=>500,'Sports Fee'=>300,'Library Fee'=>400], 'months_arr'=>['April','May']];
$allReceipts[] = ['stu'=>'STU0007', 'month'=>'June,July',         'amount'=>6200, 'feeItems'=>['Tuition Fee'=>5000,'Computer Fee'=>500,'Sports Fee'=>300,'Library Fee'=>400], 'months_arr'=>['June','July']];
$allReceipts[] = ['stu'=>'STU0007', 'month'=>'August,September',  'amount'=>6200, 'feeItems'=>['Tuition Fee'=>5000,'Computer Fee'=>500,'Sports Fee'=>300,'Library Fee'=>400], 'months_arr'=>['August','September']];
$allReceipts[] = ['stu'=>'STU0007', 'month'=>'October,November',  'amount'=>6200, 'feeItems'=>['Tuition Fee'=>5000,'Computer Fee'=>500,'Sports Fee'=>300,'Library Fee'=>400], 'months_arr'=>['October','November']];
$allReceipts[] = ['stu'=>'STU0007', 'month'=>'December,January',  'amount'=>6200, 'feeItems'=>['Tuition Fee'=>5000,'Computer Fee'=>500,'Sports Fee'=>300,'Library Fee'=>400], 'months_arr'=>['December','January']];
$allReceipts[] = ['stu'=>'STU0007', 'month'=>'February',          'amount'=>3100, 'feeItems'=>$feeItems9];

// STU0008: All 12 months paid + advance = 4 quarterly receipts + 1 advance receipt
$allReceipts[] = ['stu'=>'STU0008', 'month'=>'April,May,June',            'amount'=>9300, 'feeItems'=>['Tuition Fee'=>7500,'Computer Fee'=>750,'Sports Fee'=>450,'Library Fee'=>600], 'months_arr'=>['April','May','June']];
$allReceipts[] = ['stu'=>'STU0008', 'month'=>'July,August,September',     'amount'=>9300, 'feeItems'=>['Tuition Fee'=>7500,'Computer Fee'=>750,'Sports Fee'=>450,'Library Fee'=>600], 'months_arr'=>['July','August','September']];
$allReceipts[] = ['stu'=>'STU0008', 'month'=>'October,November,December', 'amount'=>9300, 'feeItems'=>['Tuition Fee'=>7500,'Computer Fee'=>750,'Sports Fee'=>450,'Library Fee'=>600], 'months_arr'=>['October','November','December']];
$allReceipts[] = ['stu'=>'STU0008', 'month'=>'January,February,March',    'amount'=>9300, 'feeItems'=>['Tuition Fee'=>7500,'Computer Fee'=>750,'Sports Fee'=>450,'Library Fee'=>600], 'months_arr'=>['January','February','March']];

// STU0020: Apr-Jan paid (Class 10th = 3650) = 3 receipts
$allReceipts[] = ['stu'=>'STU0020', 'month'=>'April,May,June,July',             'amount'=>14600, 'feeItems'=>['Tuition Fee'=>12000,'Computer Fee'=>1200,'Sports Fee'=>600,'Library Fee'=>800], 'months_arr'=>['April','May','June','July']];
$allReceipts[] = ['stu'=>'STU0020', 'month'=>'August,September,October',        'amount'=>10950, 'feeItems'=>['Tuition Fee'=>9000,'Computer Fee'=>900,'Sports Fee'=>450,'Library Fee'=>600],  'months_arr'=>['August','September','October']];
$allReceipts[] = ['stu'=>'STU0020', 'month'=>'November,December,January',       'amount'=>10950, 'feeItems'=>['Tuition Fee'=>9000,'Computer Fee'=>900,'Sports Fee'=>450,'Library Fee'=>600],  'months_arr'=>['November','December','January']];

// STU0002: Apr-Dec paid (Class 8th = 3100) = 3 receipts
$allReceipts[] = ['stu'=>'STU0002', 'month'=>'April,May,June',        'amount'=>9300, 'feeItems'=>['Tuition Fee'=>7500,'Computer Fee'=>750,'Sports Fee'=>450,'Library Fee'=>600], 'months_arr'=>['April','May','June']];
$allReceipts[] = ['stu'=>'STU0002', 'month'=>'July,August,September', 'amount'=>9300, 'feeItems'=>['Tuition Fee'=>7500,'Computer Fee'=>750,'Sports Fee'=>450,'Library Fee'=>600], 'months_arr'=>['July','August','September']];
$allReceipts[] = ['stu'=>'STU0002', 'month'=>'October,November,December', 'amount'=>9300, 'feeItems'=>['Tuition Fee'=>7500,'Computer Fee'=>750,'Sports Fee'=>450,'Library Fee'=>600], 'months_arr'=>['October','November','December']];

// Now write them -- total should be about 30
// Realistic date staggering: payment typically a few days into or after the month
$receiptDateMap = [
    'April'     => '2026-05-05',
    'May'       => '2026-06-08',
    'June'      => '2026-07-03',
    'July'      => '2026-08-06',
    'August'    => '2026-09-04',
    'September' => '2026-10-07',
    'October'   => '2026-11-05',
    'November'  => '2026-12-04',
    'December'  => '2027-01-06',
    'January'   => '2027-02-05',
    'February'  => '2027-03-05',
    'March'     => '2027-03-24',
];
// For multi-month, use the date of the first month in the bundle
$bundleDateMap = [
    'April,May,June'                  => '2026-05-10',
    'July,August,September'           => '2026-08-08',
    'October,November,December'       => '2026-11-10',
    'January,February'                => '2027-02-08',
    'January,February,March'          => '2027-02-10',
    'April,May'                       => '2026-05-12',
    'June,July'                       => '2026-07-10',
    'August,September'                => '2026-09-08',
    'October,November'                => '2026-11-08',
    'December,January'                => '2027-01-08',
    'April,May,June,July'             => '2026-05-15',
    'August,September,October'        => '2026-09-10',
    'November,December,January'       => '2026-12-08',
    'July,August,September'           => '2026-08-12',
    'October,November,December'       => '2026-11-12',
];

foreach ($allReceipts as $i => $rec) {
    $recNo  = 'REC' . $receiptNum;
    $recKey = 'rcpt_' . strtolower(substr(md5($recNo . $rec['stu']), 0, 12));
    $stu    = $students[$rec['stu']];

    // Determine date
    $monthStr = $rec['month'];
    if (isset($bundleDateMap[$monthStr])) {
        $date = $bundleDateMap[$monthStr];
    } elseif (isset($receiptDateMap[$monthStr])) {
        $date = $receiptDateMap[$monthStr];
    } else {
        // Fallback: parse first month
        $firstMonth = explode(',', $monthStr)[0];
        $date = $receiptDateMap[$firstMonth] ?? '2026-06-01';
    }

    $monthsPaid = isset($rec['months_arr']) ? $rec['months_arr'] : [$rec['month']];
    $mode = $paymentModes[($receiptNum - 1001) % 3];

    $receiptEntry = [
        'receipt_no'      => $recNo,
        'receipt_key'     => $recKey,
        'student_id'      => $rec['stu'],
        'student_name'    => $stu['name'],
        'class'           => $stu['class'],
        'section'         => $stu['section'],
        'amount'          => $rec['amount'],
        'payment_mode'    => $mode,
        'created_at'      => $date,
        'status'          => 'completed',
        'voucher_created' => true,
        'months_paid'     => $monthsPaid,
        'fee_breakdown'   => $rec['feeItems'],
    ];

    $db->getReference("$base/Accounts/Receipt_Index/$recNo")->set($receiptEntry);
    $counts['receipts']++;

    // Store for later use (vouchers, fee records)
    $receiptData[$recNo] = [
        'key'    => $recKey,
        'stuId'  => $rec['stu'],
        'name'   => $stu['name'],
        'amount' => $rec['amount'],
        'date'   => $date,
        'mode'   => $mode,
        'months' => $monthsPaid,
    ];
    $receiptKeys[$recNo] = $recKey;

    $receiptNum++;
}
echo "   Created {$counts['receipts']} receipts (REC1001 - REC" . ($receiptNum - 1) . ").\n";

// ============================================================================
// 4. FEE RECORDS (Users/Parents/{schoolId}/{studentId}/Fees Record/{receiptKey})
// ============================================================================
echo "\n=== 4. SEEDING FEE RECORDS ===\n";

foreach ($receiptData as $recNo => $rd) {
    // Format date as dd-mm-yyyy
    $parts = explode('-', $rd['date']);
    $fmtDate = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
    $monthsStr = implode(', ', $rd['months']);

    $feeRecord = [
        'receipt_no' => $recNo,
        'amount'     => $rd['amount'],
        'date'       => $fmtDate,
        'mode'       => $rd['mode'],
        'months'     => $monthsStr,
    ];

    $db->getReference("$base/Users/Parents/$schoolId/{$rd['stuId']}/Fees Record/{$rd['key']}")->set($feeRecord);
    $counts['feeRecords']++;
}
echo "   Created {$counts['feeRecords']} fee records under parent paths.\n";

// ============================================================================
// 5. PENDING FEES (Accounts/Pending_fees/{studentId})
// ============================================================================
echo "\n=== 5. SEEDING PENDING FEES ===\n";

$pendingMap = [
    'STU0004' => ['months'=>['October','November','December','January','February','March'], 'calc_fn'=>function() {
        // Oct: 3100-1500=1600, Nov: 3100-900=2200, Dec-Mar: 3100*4=12400 => total=16200
        // But let's calculate: remaining for Oct=1600, Nov=2200, Dec=3100, Jan=3100, Feb=3100, Mar=3100
        return 1600 + 2200 + 3100 + 3100 + 3100 + 3100;
    }],
    'STU0005' => ['months'=>['March'], 'calc_fn'=>function() {
        return 2600; // Net after scholarship
    }],
    'STU0006' => ['months'=>['June','July','August','September','October','November','December','January','February','March'], 'calc_fn'=>function() {
        return 3100 * 10; // 31000
    }],
    'STU0007' => ['months'=>['March'], 'calc_fn'=>function() {
        return 3100;
    }],
    'STU0020' => ['months'=>['February','March'], 'calc_fn'=>function() {
        return 3650 * 2; // 7300
    }],
    'STU0002' => ['months'=>['January','February','March'], 'calc_fn'=>function() {
        return 3100 * 3; // 9300
    }],
];

foreach ($pendingMap as $stuId => $info) {
    $pendingAmt = ($info['calc_fn'])();
    $pendingEntry = [
        'student_id'     => $stuId,
        'student_name'   => $students[$stuId]['name'],
        'class'          => $students[$stuId]['class'],
        'section'        => $students[$stuId]['section'],
        'pending_amount' => $pendingAmt,
        'months'         => $info['months'],
        'updated_at'     => '2026-03-24',
    ];
    $db->getReference("$base/Accounts/Pending_fees/$stuId")->set($pendingEntry);
    $counts['pendingFees']++;
}
echo "   Created {$counts['pendingFees']} pending fee entries.\n";

// ============================================================================
// 6. DEFAULTERS (Fees/Defaulters/{studentId})
// ============================================================================
echo "\n=== 6. SEEDING DEFAULTERS ===\n";

$defaulterData = [
    'student_id'        => 'STU0006',
    'student_name'      => 'Rahul Kumar',
    'class'             => 'Class 9th',
    'section'           => 'Section A',
    'is_defaulter'      => true,
    'total_dues'        => 31000,
    'unpaid_months'     => ['June','July','August','September','October','November','December','January','February','March'],
    'overdue_months'    => ['June','July','August','September','October','November'],
    'last_payment_date' => '2026-06-10',
    'flagged_at'        => '2026-09-01',
];
$db->getReference("$base/Fees/Defaulters/STU0006")->set($defaulterData);
$counts['defaulters']++;
echo "   Created {$counts['defaulters']} defaulter record.\n";

// ============================================================================
// 7. ADVANCE BALANCE (Fees/Advance_Balance/STU0008)
// ============================================================================
echo "\n=== 7. SEEDING ADVANCE BALANCE ===\n";

$advanceData = [
    'amount'        => 5000,
    'student_id'    => 'STU0008',
    'student_name'  => 'Arjun Verma',
    'class'         => 'Class 9th',
    'section'       => 'Section A',
    'last_updated'  => '2026-02-15',
    'last_receipt'  => 'REC1028',
];
$db->getReference("$base/Fees/Advance_Balance/STU0008")->set($advanceData);
$counts['advanceBalance']++;
echo "   Created {$counts['advanceBalance']} advance balance record.\n";

// ============================================================================
// 8. DISCOUNT APPLICATIONS ({class}/{section}/Students/{studentId}/Discount)
// ============================================================================
echo "\n=== 8. SEEDING DISCOUNT APPLICATIONS ===\n";

// STU0005: Merit Scholarship
$discountPriya = [
    'total_discount' => 6000,
    'Applied' => [
        'HIST001' => [
            'policy_name'   => 'Merit Scholarship',
            'discount_type' => 'fixed',
            'value'         => 500,
            'amount'        => 500,
            'applied_by'    => 'ADM0001',
            'applied_at'    => '2026-04-15',
            'description'   => 'Monthly merit scholarship of Rs.500 for academic excellence',
        ],
    ],
    'Scholarships' => [
        'AWD001' => [
            'scholarship_name' => 'Academic Excellence Award',
            'amount'           => 1000,
            'scholarship_id'   => 'SCH001',
            'awarded_date'     => '2026-04-01',
            'status'           => 'active',
        ],
    ],
];
$db->getReference("$base/Class_9th/Section_A/Students/STU0005/Discount")->set($discountPriya);
$counts['discounts']++;

// STU0006: EWS Concession
$discountRahul = [
    'total_discount' => 0,
    'Applied' => [
        'HIST002' => [
            'policy_name'   => 'EWS Concession',
            'discount_type' => 'percentage',
            'value'         => 25,
            'amount'        => 775,
            'applied_by'    => 'ADM0001',
            'applied_at'    => '2026-04-15',
            'description'   => '25% concession under EWS category (not yet activated)',
        ],
    ],
];
$db->getReference("$base/Class_9th/Section_A/Students/STU0006/Discount")->set($discountRahul);
$counts['discounts']++;
echo "   Created {$counts['discounts']} discount application records.\n";

// ============================================================================
// 9. VOUCHERS (Accounts/Vouchers/{date}/{voucherKey})
// ============================================================================
echo "\n=== 9. SEEDING VOUCHERS ===\n";

// Create vouchers for the first 10 receipts
$voucherCount = 0;
$rcNums = array_keys($receiptData);
for ($v = 0; $v < min(10, count($rcNums)); $v++) {
    $rn = $rcNums[$v];
    $rd = $receiptData[$rn];
    $vKey = 'rcpt_' . strtolower(str_replace('REC', '', $rn));

    $voucherEntry = [
        'type'           => 'Income',
        'account'        => '4010',
        'amount'         => $rd['amount'],
        'description'    => "Fee receipt $rn - {$rd['stuId']}",
        'receipt_no'     => $rn,
        'student_id'     => $rd['stuId'],
        'student_name'   => $rd['name'],
        'payment_mode'   => $rd['mode'],
        'created_by'     => 'ADM0001',
    ];

    $db->getReference("$base/Accounts/Vouchers/{$rd['date']}/$vKey")->set($voucherEntry);
    $counts['vouchers']++;
    $voucherCount++;
}
echo "   Created {$counts['vouchers']} voucher entries.\n";

// ============================================================================
// 10. AUDIT LOGS (Fees/Audit_Logs/{logId})
// ============================================================================
echo "\n=== 10. SEEDING AUDIT LOGS ===\n";

$auditLogs = [
    ['event'=>'fee_collected',    'actor'=>'ADM0001', 'actor_name'=>'Admin Office',       'target'=>'STU0004', 'target_name'=>'Yuvraj Singh',  'detail'=>'Collected Rs.3100 for April 2026. Receipt REC1001.',                'ts'=>'2026-05-05T10:30:00Z'],
    ['event'=>'fee_collected',    'actor'=>'ADM0001', 'actor_name'=>'Admin Office',       'target'=>'STU0005', 'target_name'=>'Priya Sharma',  'detail'=>'Collected Rs.7800 for April-June 2026. Receipt REC1009.',           'ts'=>'2026-05-10T11:15:00Z'],
    ['event'=>'demand_generated', 'actor'=>'SYSTEM',  'actor_name'=>'System',             'target'=>'ALL',     'target_name'=>'All Students',  'detail'=>'Monthly fee demands generated for April 2026 session 2026-27.',     'ts'=>'2026-04-01T00:05:00Z'],
    ['event'=>'demand_generated', 'actor'=>'SYSTEM',  'actor_name'=>'System',             'target'=>'ALL',     'target_name'=>'All Students',  'detail'=>'Monthly fee demands generated for October 2026 session 2026-27.',   'ts'=>'2026-10-01T00:05:00Z'],
    ['event'=>'fee_collected',    'actor'=>'ADM0001', 'actor_name'=>'Admin Office',       'target'=>'STU0008', 'target_name'=>'Arjun Verma',   'detail'=>'Collected Rs.9300 for Q1 (Apr-Jun). Receipt REC1021.',              'ts'=>'2026-05-10T09:45:00Z'],
    ['event'=>'discount_applied', 'actor'=>'ADM0001', 'actor_name'=>'Admin Office',       'target'=>'STU0005', 'target_name'=>'Priya Sharma',  'detail'=>'Applied Merit Scholarship discount of Rs.500/month.',              'ts'=>'2026-04-15T14:20:00Z'],
    ['event'=>'defaulter_flagged','actor'=>'SYSTEM',  'actor_name'=>'Defaulter Check',    'target'=>'STU0006', 'target_name'=>'Rahul Kumar',   'detail'=>'Student flagged as defaulter. 10 months unpaid. Total dues Rs.31000.','ts'=>'2026-09-01T06:00:00Z'],
    ['event'=>'fee_collected',    'actor'=>'ADM0001', 'actor_name'=>'Admin Office',       'target'=>'STU0007', 'target_name'=>'Ananya Patel',  'detail'=>'Collected Rs.6200 for April-May. Receipt REC1015.',                 'ts'=>'2026-05-12T10:00:00Z'],
    ['event'=>'fee_collected',    'actor'=>'ADM0001', 'actor_name'=>'Admin Office',       'target'=>'STU0006', 'target_name'=>'Rahul Kumar',   'detail'=>'Collected Rs.3100 for April. Receipt REC1013.',                     'ts'=>'2026-05-05T11:30:00Z'],
    ['event'=>'refund_processed', 'actor'=>'ADM0001', 'actor_name'=>'Admin Office',       'target'=>'STU0008', 'target_name'=>'Arjun Verma',   'detail'=>'Refund of Rs.3100 processed. Reason: Duplicate payment. REF001.',   'ts'=>'2026-02-15T16:00:00Z'],
];

$logCounter = 1;
foreach ($auditLogs as $log) {
    $logId = 'LOG' . str_pad($logCounter, 4, '0', STR_PAD_LEFT);
    $logEntry = [
        'log_id'       => $logId,
        'event'        => $log['event'],
        'actor'        => $log['actor'],
        'actor_name'   => $log['actor_name'],
        'target'       => $log['target'],
        'target_name'  => $log['target_name'],
        'detail'       => $log['detail'],
        'timestamp'    => $log['ts'],
        'school_id'    => 'SCH_9738C22243',
        'session'      => '2026-27',
    ];
    $db->getReference("$base/Fees/Audit_Logs/$logId")->set($logEntry);
    $counts['auditLogs']++;
    $logCounter++;
}
echo "   Created {$counts['auditLogs']} audit log entries.\n";

// ============================================================================
// 11. DASHBOARD CACHE (Fees/Dashboard_Cache/collection_summary)
// ============================================================================
echo "\n=== 11. SEEDING DASHBOARD CACHE ===\n";

$dashboardCache = [
    'total_collected'    => 285000,
    'total_due'          => 82000,
    'todays_collection'  => 6200,
    'collection_rate'    => 77.6,
    'updated_at'         => '2026-03-24T18:00:00Z',
    'session'            => '2026-27',
    'month_wise' => [
        'April'     => 45000,
        'May'       => 42000,
        'June'      => 38000,
        'July'      => 35000,
        'August'    => 32000,
        'September' => 28000,
        'October'   => 22000,
        'November'  => 18000,
        'December'  => 12000,
        'January'   => 8000,
        'February'  => 3800,
        'March'     => 1200,
    ],
    'class_wise' => [
        'Class 9th' => [
            'students'  => 12,
            'collected' => 180000,
            'due'       => 55000,
        ],
        'Class 10th' => [
            'students'  => 5,
            'collected' => 65000,
            'due'       => 18000,
        ],
        'Class 8th' => [
            'students'  => 3,
            'collected' => 40000,
            'due'       => 9000,
        ],
    ],
];
$db->getReference("$base/Fees/Dashboard_Cache/collection_summary")->set($dashboardCache);
$counts['dashboardCache']++;
echo "   Created {$counts['dashboardCache']} dashboard cache entry.\n";

// ============================================================================
// 12. REFUNDS (Accounts/Fees/Refunds/{refundId})
// ============================================================================
echo "\n=== 12. SEEDING REFUNDS ===\n";

$refunds = [
    'REF001' => [
        'refund_id'     => 'REF001',
        'student_id'    => 'STU0008',
        'student_name'  => 'Arjun Verma',
        'class'         => 'Class 9th',
        'section'       => 'Section A',
        'receipt_no'    => 'REC1028',
        'amount'        => 3100,
        'mode'          => 'bank_transfer',
        'status'        => 'completed',
        'requested_at'  => '2026-02-10',
        'approved_at'   => '2026-02-12',
        'processed_at'  => '2026-02-15',
        'reason'        => 'Duplicate payment',
        'approved_by'   => 'ADM0001',
    ],
    'REF002' => [
        'refund_id'     => 'REF002',
        'student_id'    => 'STU0007',
        'student_name'  => 'Ananya Patel',
        'class'         => 'Class 9th',
        'section'       => 'Section A',
        'receipt_no'    => 'REC1025',
        'amount'        => 1500,
        'mode'          => 'cash',
        'status'        => 'pending',
        'requested_at'  => '2026-03-20',
        'reason'        => 'Overpayment for December',
    ],
];

foreach ($refunds as $refId => $refData) {
    $db->getReference("$base/Accounts/Fees/Refunds/$refId")->set($refData);
    $counts['refunds']++;
}
echo "   Created {$counts['refunds']} refund records.\n";

// ============================================================================
// 13. ONLINE ORDERS (Fees/Online_Orders/{orderId})
// ============================================================================
echo "\n=== 13. SEEDING ONLINE ORDERS ===\n";

$onlineOrders = [
    'ORD001' => [
        'record_id'            => 'ORD001',
        'student_id'           => 'STU0004',
        'student_name'         => 'Yuvraj Singh',
        'class'                => 'Class 9th',
        'section'              => 'Section A',
        'amount'               => 3100,
        'fee_months'           => ['September'],
        'gateway_order_id'     => 'order_razorpay_001',
        'gateway_payment_id'   => 'pay_razorpay_001',
        'status'               => 'paid',
        'created_at'           => '2026-10-01',
        'webhook_received_at'  => '2026-10-01',
        'expected_amount'      => 3100,
        'gateway_amount'       => 3100,
    ],
    'ORD002' => [
        'record_id'            => 'ORD002',
        'student_id'           => 'STU0005',
        'student_name'         => 'Priya Sharma',
        'class'                => 'Class 9th',
        'section'              => 'Section A',
        'amount'               => 2600,
        'fee_months'           => ['February'],
        'gateway_order_id'     => 'order_razorpay_002',
        'gateway_payment_id'   => 'pay_razorpay_002',
        'status'               => 'paid',
        'created_at'           => '2026-03-05',
        'webhook_received_at'  => '2026-03-05',
        'expected_amount'      => 2600,
        'gateway_amount'       => 2600,
    ],
    'ORD003' => [
        'record_id'            => 'ORD003',
        'student_id'           => 'STU0020',
        'student_name'         => 'Vikram Rathore',
        'class'                => 'Class 10th',
        'section'              => 'Section A',
        'amount'               => 3650,
        'fee_months'           => ['March'],
        'gateway_order_id'     => 'order_razorpay_003',
        'status'               => 'created',
        'created_at'           => '2026-03-24',
        'expected_amount'      => 3650,
    ],
];

foreach ($onlineOrders as $ordId => $ordData) {
    $db->getReference("$base/Fees/Online_Orders/$ordId")->set($ordData);
    $counts['onlineOrders']++;
}
echo "   Created {$counts['onlineOrders']} online order records.\n";

// ============================================================================
// SUMMARY
// ============================================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║              FEE DATA SEEDING COMPLETE                     ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  Base path: $base\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  %-4d  Fee Demands          (7 students x 12 months)      ║\n", $counts['demands']);
printf("║  %-4d  Monthly Fee Records  (per-student month tracking)  ║\n", $counts['monthFeeRecs']);
printf("║  %-4d  Receipts             (REC1001 - REC%04d)           ║\n", $counts['receipts'], 1000 + $counts['receipts']);
printf("║  %-4d  Fee Records          (parent portal entries)       ║\n", $counts['feeRecords']);
printf("║  %-4d  Pending Fee Entries  (students with dues)          ║\n", $counts['pendingFees']);
printf("║  %-4d  Defaulter Records    (STU0006 flagged)             ║\n", $counts['defaulters']);
printf("║  %-4d  Advance Balance      (STU0008 = Rs.5000)           ║\n", $counts['advanceBalance']);
printf("║  %-4d  Discount Records     (scholarship + EWS)           ║\n", $counts['discounts']);
printf("║  %-4d  Voucher Entries      (income vouchers)             ║\n", $counts['vouchers']);
printf("║  %-4d  Audit Log Entries    (fee events)                  ║\n", $counts['auditLogs']);
printf("║  %-4d  Dashboard Cache      (collection summary)          ║\n", $counts['dashboardCache']);
printf("║  %-4d  Refund Records       (REF001 completed, REF002)    ║\n", $counts['refunds']);
printf("║  %-4d  Online Orders        (Razorpay gateway)            ║\n", $counts['onlineOrders']);
echo "╠══════════════════════════════════════════════════════════════╣\n";
$total = array_sum($counts);
printf("║  TOTAL: %-4d records seeded                               ║\n", $total);
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\nPending amounts by student:\n";
foreach ($pendingMap as $stuId => $info) {
    $amt = ($info['calc_fn'])();
    $name = str_pad($students[$stuId]['name'], 18);
    $mths = count($info['months']);
    echo "  $stuId ($name): Rs.$amt ($mths months pending)\n";
}
echo "  STU0008 (Arjun Verma        ): Rs.0 (0 months pending) + Rs.5000 advance\n";
echo "\nDone.\n";
