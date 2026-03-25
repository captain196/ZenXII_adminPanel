<?php
error_reporting(E_ERROR);

require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

// ── Firebase connection ──────────────────────────────────────────────
$serviceAccountPath = __DIR__ . '/application/config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
$databaseUri        = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

$factory  = (new Factory)
    ->withServiceAccount($serviceAccountPath)
    ->withDatabaseUri($databaseUri);
$database = $factory->createDatabase();

// ── Constants ────────────────────────────────────────────────────────
$schoolId    = 'SCH_9738C22243';
$session     = '2026-27';
$base        = "Schools/{$schoolId}/{$session}";
$feesBase    = "{$base}/Accounts/Fees";

// Track seeded counts
$summary = [];

// ── Helper: write to Firebase and track count ────────────────────────
function seed(object $db, string $path, $data, string $category, array &$summary, $count = 1): void
{
    $db->getReference($path)->set($data);
    if (!isset($summary[$category])) {
        $summary[$category] = 0;
    }
    $summary[$category] += $count;
}

// ══════════════════════════════════════════════════════════════════════
// 1. FEE STRUCTURE
// ══════════════════════════════════════════════════════════════════════
$feeStructure = [
    'Monthly' => [
        'Tuition Fee'  => 2500,
        'Computer Fee' => 250,
        'Sports Fee'   => 150,
        'Library Fee'  => 200,
    ],
    'Yearly' => [
        'Admission Fee'    => 5000,
        'Examination Fee'  => 1500,
        'Development Fund' => 2000,
    ],
];
seed($database, "{$feesBase}/Fees Structure", $feeStructure, 'Fee Structure', $summary, 2);

// ══════════════════════════════════════════════════════════════════════
// 2. CLASS-WISE FEES
// ══════════════════════════════════════════════════════════════════════
$months = [
    'April', 'May', 'June', 'July', 'August', 'September',
    'October', 'November', 'December', 'January', 'February', 'March',
];

$standardMonthly = [
    'Tuition Fee'  => 2500,
    'Computer Fee' => 250,
    'Sports Fee'   => 150,
    'Library Fee'  => 200,
];

$class10Monthly = [
    'Tuition Fee'  => 3000,
    'Computer Fee' => 300,
    'Sports Fee'   => 150,
    'Library Fee'  => 200,
];

$classSections = [
    'Class 8th'  => ['Section A', 'Section B'],
    'Class 9th'  => ['Section A', 'Section B'],
    'Class 10th' => ['Section A'],
];

$classFeesCount = 0;
foreach ($classSections as $class => $sections) {
    $monthlyFees = ($class === 'Class 10th') ? $class10Monthly : $standardMonthly;
    foreach ($sections as $section) {
        $sectionData = [];
        foreach ($months as $month) {
            $sectionData[$month] = $monthlyFees;
        }
        $path = "{$feesBase}/Classes Fees/{$class}/{$section}";
        $database->getReference($path)->set($sectionData);
        $classFeesCount++;
    }
}
$summary['Class-wise Fees (class/section combos)'] = $classFeesCount;

// ══════════════════════════════════════════════════════════════════════
// 3. FEE CATEGORIES
// ══════════════════════════════════════════════════════════════════════
$categories = [
    'CAT001' => [
        'category_name' => 'Mandatory Fees',
        'description'   => 'Compulsory fees for all students',
        'category_type' => 'monthly',
        'fee_titles'    => ['Tuition Fee', 'Library Fee'],
        'sort_order'    => 1,
        'status'        => 'active',
        'created_at'    => '2026-04-01',
    ],
    'CAT002' => [
        'category_name' => 'Optional Fees',
        'description'   => 'Optional facility charges',
        'category_type' => 'monthly',
        'fee_titles'    => ['Computer Fee', 'Sports Fee'],
        'sort_order'    => 2,
        'status'        => 'active',
        'created_at'    => '2026-04-01',
    ],
    'CAT003' => [
        'category_name' => 'Annual Charges',
        'description'   => 'One-time annual fees',
        'category_type' => 'yearly',
        'fee_titles'    => ['Admission Fee', 'Examination Fee', 'Development Fund'],
        'sort_order'    => 3,
        'status'        => 'active',
        'created_at'    => '2026-04-01',
    ],
];
seed($database, "{$feesBase}/Categories", $categories, 'Fee Categories', $summary, count($categories));

// ══════════════════════════════════════════════════════════════════════
// 4. RECEIPT COUNTER
// ══════════════════════════════════════════════════════════════════════
seed($database, "{$feesBase}/Receipt No", 1050, 'Receipt Counter', $summary);

// ══════════════════════════════════════════════════════════════════════
// 5. RECEIPT SEQUENCE
// ══════════════════════════════════════════════════════════════════════
seed($database, "{$feesBase}/Counters/receipt_seq", 1050, 'Receipt Sequence', $summary);

// ══════════════════════════════════════════════════════════════════════
// 6. DISCOUNT POLICIES
// ══════════════════════════════════════════════════════════════════════
$discountPolicies = [
    'DISC001' => [
        'name'                    => 'Sibling Discount',
        'type'                    => 'percentage',
        'value'                   => 10,
        'criteria'                => 'Multiple siblings enrolled',
        'applicable_categories'   => ['Mandatory Fees'],
        'applicable_titles'       => ['Tuition Fee'],
        'max_discount'            => 500,
        'active'                  => true,
        'created_at'              => '2026-04-01',
    ],
    'DISC002' => [
        'name'                    => 'Staff Ward',
        'type'                    => 'percentage',
        'value'                   => 50,
        'criteria'                => 'Staff children',
        'applicable_categories'   => ['Mandatory Fees', 'Optional Fees'],
        'applicable_titles'       => [],
        'max_discount'            => 2000,
        'active'                  => true,
        'created_at'              => '2026-04-01',
    ],
    'DISC003' => [
        'name'                    => 'Merit Scholarship',
        'type'                    => 'fixed',
        'value'                   => 500,
        'criteria'                => 'Top 3 rank holders',
        'applicable_categories'   => ['Mandatory Fees'],
        'applicable_titles'       => ['Tuition Fee'],
        'max_discount'            => 500,
        'active'                  => true,
        'created_at'              => '2026-04-01',
    ],
    'DISC004' => [
        'name'                    => 'EWS Concession',
        'type'                    => 'percentage',
        'value'                   => 25,
        'criteria'                => 'Economically weaker section',
        'applicable_categories'   => ['Mandatory Fees', 'Optional Fees', 'Annual Charges'],
        'applicable_titles'       => [],
        'max_discount'            => 3000,
        'active'                  => true,
        'created_at'              => '2026-04-01',
    ],
];
seed($database, "{$feesBase}/Discount Policies", $discountPolicies, 'Discount Policies', $summary, count($discountPolicies));

// ══════════════════════════════════════════════════════════════════════
// 7. SCHOLARSHIPS
// ══════════════════════════════════════════════════════════════════════
$scholarships = [
    'SCH001' => [
        'name'               => 'Academic Excellence Award',
        'type'               => 'fixed',
        'value'              => 1000,
        'max_beneficiaries'  => 5,
        'criteria'           => '90%+ marks in previous exam',
        'active'             => true,
        'created_at'         => '2026-04-01',
    ],
    'SCH002' => [
        'name'               => 'Sports Achievement Scholarship',
        'type'               => 'fixed',
        'value'              => 750,
        'max_beneficiaries'  => 3,
        'criteria'           => 'District/State level sports achiever',
        'active'             => true,
        'created_at'         => '2026-04-01',
    ],
];
seed($database, "{$feesBase}/Scholarships", $scholarships, 'Scholarships', $summary, count($scholarships));

// ══════════════════════════════════════════════════════════════════════
// 8. SCHOLARSHIP AWARDS
// ══════════════════════════════════════════════════════════════════════
$scholarshipAwards = [
    'AWD001' => [
        'award_id'          => 'AWD001',
        'scholarship_id'    => 'SCH001',
        'scholarship_name'  => 'Academic Excellence Award',
        'student_id'        => 'STU0005',
        'student_name'      => 'Priya Sharma',
        'amount'            => 1000,
        'awarded_at'        => '2026-06-15',
        'status'            => 'active',
        'class'             => 'Class 9th',
        'section'           => 'Section A',
    ],
    'AWD002' => [
        'award_id'          => 'AWD002',
        'scholarship_id'    => 'SCH002',
        'scholarship_name'  => 'Sports Achievement Scholarship',
        'student_id'        => 'STU0008',
        'student_name'      => 'Arjun Verma',
        'amount'            => 750,
        'awarded_at'        => '2026-07-01',
        'status'            => 'active',
        'class'             => 'Class 9th',
        'section'           => 'Section A',
    ],
];
seed($database, "{$feesBase}/Scholarship Awards", $scholarshipAwards, 'Scholarship Awards', $summary, count($scholarshipAwards));

// ══════════════════════════════════════════════════════════════════════
// 9. GATEWAY CONFIG
// ══════════════════════════════════════════════════════════════════════
$gatewayConfig = [
    'mode'                => 'test',
    'provider'            => 'razorpay',
    'api_key'             => 'rzp_test_1234567890',
    'api_secret'          => '***masked***',
    'webhook_secret'      => 'whsec_test_xxx',
    'webhook_allowed_ips' => ['52.66.166.0/24'],
    'created_at'          => '2026-04-01',
    'updated_at'          => '2026-04-01',
];
seed($database, "{$feesBase}/Gateway Config", $gatewayConfig, 'Gateway Config', $summary);

// ══════════════════════════════════════════════════════════════════════
// 10. REMINDER SETTINGS
// ══════════════════════════════════════════════════════════════════════
$reminderSettings = [
    'enabled'            => true,
    'frequency'          => 'monthly',
    'days_before'        => 5,
    'message_template'   => 'Dear Parent, fee for {month} of Rs.{amount} is due on {due_date}. Please pay at the earliest.',
    'created_at'         => '2026-04-01',
    'updated_at'         => '2026-04-01',
];
seed($database, "{$feesBase}/Reminder Settings", $reminderSettings, 'Reminder Settings', $summary);

// ══════════════════════════════════════════════════════════════════════
// 11. FEE-TO-ACCOUNT MAP (at school level, not session-scoped fees)
// ══════════════════════════════════════════════════════════════════════
$feeAccountMap = [
    'Tuition Fee'      => '4010',
    'Computer Fee'     => '4020',
    'Sports Fee'       => '4030',
    'Library Fee'      => '4040',
    'Admission Fee'    => '4050',
    'Examination Fee'  => '4060',
    'Development Fund' => '4070',
];
seed($database, "Schools/{$schoolId}/Accounts/Fee_Account_Map", $feeAccountMap, 'Fee-to-Account Map', $summary, count($feeAccountMap));

// ══════════════════════════════════════════════════════════════════════
// 12. DEFAULTER POLICY
// ══════════════════════════════════════════════════════════════════════
$defaulterPolicy = [
    'result_withhold_threshold' => 5000,
    'exam_block_threshold'      => 10000,
    'reminder_after_days'       => 15,
    'fine_per_day'              => 10,
    'max_fine_per_month'        => 500,
    'grace_period_days'         => 10,
];
seed($database, "{$feesBase}/Defaulter_Policy", $defaulterPolicy, 'Defaulter Policy', $summary);

// ══════════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════════
echo "\n";
echo "============================================================\n";
echo "  FEE CONFIGURATION SEED COMPLETE\n";
echo "============================================================\n";
echo "  School:  {$schoolId}\n";
echo "  Session: {$session}\n";
echo "  Base:    {$base}\n";
echo "------------------------------------------------------------\n";

$totalItems = 0;
foreach ($summary as $category => $count) {
    printf("  %-42s : %d\n", $category, $count);
    $totalItems += $count;
}

echo "------------------------------------------------------------\n";
printf("  %-42s : %d\n", 'TOTAL ITEMS SEEDED', $totalItems);
echo "============================================================\n\n";
