<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_plans
 * Subscription plan management: create, update, delete, assign modules per plan.
 */
class Superadmin_plans extends MY_Superadmin_Controller
{
    // All available modules the SA can toggle per plan.
    // Covers every shipped feature of the ERP plus the two cross-system
    // mobile app access flags. Adding a new module here makes it appear
    // automatically in the plan-create and plan-edit modals (the view
    // iterates this constant).
    const AVAILABLE_MODULES = [
        // Core SIS / academic
        'student_management' => 'Student Management',
        'staff_management'   => 'Staff Management',
        'admission'          => 'Admission Management',
        'attendance'         => 'Attendance',
        'timetable'          => 'Timetable',
        'homework'           => 'Homework',
        'exams'              => 'Exam Management',
        'results'            => 'Result Management',
        // Finance
        'fees'               => 'Fees Collection',
        'accounts'           => 'Accounts & Ledger',
        // Operations
        'library'            => 'Library Management',
        'events'             => 'Events & Calendar',
        'ptm'                => 'Parent-Teacher Meetings',
        'id_cards'           => 'ID Cards',
        'gallery'            => 'School Gallery',
        // Communication
        'notices'            => 'Notices & Announcements',
        'sms_alerts'         => 'SMS Alerts',
        // Cross-system mobile access
        'parent_app'         => 'Parent App Access',
        'teacher_app'        => 'Teacher App Access',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/plans
    // ─────────────────────────────────────────────────────────────────────────

    public function index()
    {
        $plans = [];

        // ── B2.3.2-B: single-source plan list ────────────────────────────
        $svc = $this->_b23b_registry();
        $fsPlans = $svc->list_plans();
        // PL-1: count schools per plan from a SINGLE schoolControl fetch
        // grouped in memory, instead of one filtered query per plan (N+1).
        // Equivalent to count_schools_on_plan() applied to every plan.
        $planCounts = $svc->count_schools_by_plan();
        foreach ($fsPlans as $fs) {
            $row = $this->_b23b_plan_view_shape($fs);
            $row['school_count'] = $planCounts[$row['plan_id']] ?? 0;
            $plans[] = $row;
        }
        usort($plans, fn($a, $b) => ($a['sort_order'] ?? 99) - ($b['sort_order'] ?? 99));

        $data = [
            'page_title'        => 'Subscription Plans',
            'plans'             => $plans,
            'available_modules' => self::AVAILABLE_MODULES,
        ];

        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/plans/index',       $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/create
    // ─────────────────────────────────────────────────────────────────────────

    public function create()
    {
        $name        = trim($this->input->post('name',         TRUE) ?? '');
        $price       = (float)($this->input->post('price')         ?? 0);
        $billing     = trim($this->input->post('billing_cycle', TRUE) ?? 'monthly');
        $max_students= (int)($this->input->post('max_students')     ?? 500);
        $max_staff   = (int)($this->input->post('max_staff')        ?? 50);
        $grace_days  = (int)($this->input->post('grace_days')       ?? 7);
        $sort_order  = (int)($this->input->post('sort_order')       ?? 10);
        $modules_raw = $this->input->post('modules') ?? [];

        if (empty($name)) { $this->json_error('Plan name is required.'); return; }
        if (!in_array($billing, ['monthly', 'quarterly', 'annual'])) {
            $this->json_error('Invalid billing cycle.'); return;
        }

        // Build modules map: only keys from AVAILABLE_MODULES that were submitted
        $modules = [];
        foreach (array_keys(self::AVAILABLE_MODULES) as $mod) {
            $modules[$mod] = in_array($mod, (array)$modules_raw);
        }

        $plan_id = 'PLAN_' . strtoupper(substr(md5(uniqid($name, true)), 0, 6));

        // ── B2.3.2-B: single-source plan create ──────────────────────────
        $ok = $this->_b23b_registry()->create_plan($plan_id, [
            'name'         => $name,
            'description'  => '',
            'price'        => $price,
            'billingCycle' => $billing,
            'graceDays'    => $grace_days,
            'sortOrder'    => $sort_order,
            'modules'      => $modules,
            'limits'       => ['maxStudents' => $max_students, 'maxStaff' => $max_staff],
            'status'       => 'active',
            'createdAt'    => date('Y-m-d H:i:s'),
            'createdBy'    => (string) $this->sa_id,
        ]);
        if (!$ok) { $this->json_error('Failed to create plan.'); return; }
        $this->sa_log('plan_created', '', ['plan_id' => $plan_id, 'name' => $name]);
        $this->json_success(['plan_id' => $plan_id, 'message' => "Plan '{$name}' created."]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/update
    // ─────────────────────────────────────────────────────────────────────────

    public function update()
    {
        $plan_id      = trim($this->input->post('plan_id',       TRUE) ?? '');
        $name         = trim($this->input->post('name',          TRUE) ?? '');
        $price        = (float)($this->input->post('price')            ?? 0);
        $billing      = trim($this->input->post('billing_cycle', TRUE) ?? '');
        $max_students = $this->input->post('max_students');
        $max_staff    = $this->input->post('max_staff');
        $grace_days   = (int)($this->input->post('grace_days')        ?? 7);
        $sort_order   = $this->input->post('sort_order');
        $modules_raw  = $this->input->post('modules') ?? [];

        if (empty($plan_id) || empty($name)) {
            $this->json_error('Plan ID and name are required.');
            return;
        }
        if (!preg_match('/^PLAN_[A-Z0-9]+$/', $plan_id)) {
            $this->json_error('Invalid plan ID format.'); return;
        }

        $modules = [];
        foreach (array_keys(self::AVAILABLE_MODULES) as $mod) {
            $modules[$mod] = in_array($mod, (array)$modules_raw);
        }

        // ── B2.3.2-B: single-source plan update ──────────────────────────
        $fsPatch = [
            'name'        => $name,
            'price'       => $price,
            'graceDays'   => $grace_days,
            'modules'     => $modules,
            'updatedAt'   => date('Y-m-d H:i:s'),
            'updatedBy'   => (string) $this->sa_id,
        ];
        if ($billing !== '' && in_array($billing, ['monthly', 'quarterly', 'annual'])) {
            $fsPatch['billingCycle'] = $billing;
        }
        $limitsPatch = [];
        if ($max_students !== null) $limitsPatch['maxStudents'] = (int) $max_students;
        if ($max_staff    !== null) $limitsPatch['maxStaff']    = (int) $max_staff;
        if (!empty($limitsPatch)) $fsPatch['limits'] = $limitsPatch;
        if ($sort_order   !== null) $fsPatch['sortOrder']  = (int) $sort_order;
        $ok = $this->_b23b_registry()->update_plan($plan_id, $fsPatch);
        if (!$ok) { $this->json_error('Failed to update plan.'); return; }
        $this->sa_log('plan_updated', '', ['plan_id' => $plan_id]);
        $this->json_success(['message' => "Plan '{$name}' updated."]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/delete
    // ─────────────────────────────────────────────────────────────────────────

    public function delete_plan()
    {
        $plan_id = trim($this->input->post('plan_id', TRUE) ?? '');
        if (empty($plan_id)) { $this->json_error('Plan ID required.'); return; }
        if (!preg_match('/^PLAN_[A-Z0-9]+$/', $plan_id)) {
            $this->json_error('Invalid plan ID format.'); return;
        }

        // ── B2.3.2-B: single-source plan delete ──────────────────────────
        // Safety: refuse if schools are on this plan
        $svc = $this->_b23b_registry();
        if ($svc->count_schools_on_plan($plan_id) > 0) {
            $this->json_error('Cannot delete: one or more schools are on this plan. Reassign them first.');
            return;
        }
        $ok = $svc->delete_plan($plan_id);
        if (!$ok) { $this->json_error('Failed to delete plan.'); return; }
        $this->sa_log('plan_deleted', '', ['plan_id' => $plan_id]);
        $this->json_success(['message' => 'Plan deleted.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/fetch
    // Returns single plan data for edit modal
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch()
    {
        $plan_id = trim($this->input->post('plan_id', TRUE) ?? '');

        // ── B2.3.2-B: single-source plan fetch ───────────────────────────
        $svc = $this->_b23b_registry();
        if ($plan_id !== '') {
            if (!preg_match('/^PLAN_[A-Z0-9]+$/', $plan_id)) {
                $this->json_error('Invalid plan ID format.'); return;
            }
            $fs = $svc->get_plan($plan_id);
            $plan = $fs === null ? [] : $this->_b23b_plan_view_shape($fs);
            $this->json_success(['plan' => $plan, 'plans' => [$plan_id => $plan]]);
            return;
        }
        $plans = [];
        foreach ($svc->list_plans() as $fs) {
            $row = $this->_b23b_plan_view_shape($fs);
            $plans[$row['plan_id']] = $row;
        }
        $this->json_success(['plans' => $plans, 'total' => count($plans)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/seed_defaults
    // Seeds Basic / Standard / Premium plans if they do not already exist.
    // ─────────────────────────────────────────────────────────────────────────

    public function seed_defaults()
    {
        $defaults = [
            'Basic' => [
                'price' => 5000, 'billing_cycle' => 'annual',
                'max_students' => 300, 'max_staff' => 20, 'grace_days' => 7, 'sort_order' => 1,
                'description' => 'Essential modules for small schools.',
                'modules' => [
                    'student_management' => true,  'staff_management'  => true,
                    'fees'               => true,  'attendance'        => true,
                    'notices'            => true,  'timetable'         => true,
                    'accounts'           => false, 'exams'             => false,
                    'results'            => false, 'homework'          => false,
                    'gallery'            => false, 'id_cards'          => false,
                    'sms_alerts'         => false, 'parent_app'        => false,
                    'teacher_app'        => false,
                ],
            ],
            'Standard' => [
                'price' => 12000, 'billing_cycle' => 'annual',
                'max_students' => 1000, 'max_staff' => 60, 'grace_days' => 10, 'sort_order' => 2,
                'description' => 'Full academic suite for medium-sized schools.',
                'modules' => [
                    'student_management' => true,  'staff_management'  => true,
                    'fees'               => true,  'attendance'        => true,
                    'notices'            => true,  'timetable'         => true,
                    'accounts'           => true,  'exams'             => true,
                    'results'            => true,  'homework'          => true,
                    'gallery'            => true,  'id_cards'          => true,
                    'sms_alerts'         => false, 'parent_app'        => false,
                    'teacher_app'        => false,
                ],
            ],
            'Premium' => [
                'price' => 25000, 'billing_cycle' => 'annual',
                'max_students' => 5000, 'max_staff' => 200, 'grace_days' => 15, 'sort_order' => 3,
                'description' => 'All modules including apps & SMS for large institutions.',
                'modules' => [
                    'student_management' => true, 'staff_management' => true,
                    'fees'               => true, 'attendance'       => true,
                    'notices'            => true, 'timetable'        => true,
                    'accounts'           => true, 'exams'            => true,
                    'results'            => true, 'homework'         => true,
                    'gallery'            => true, 'id_cards'         => true,
                    'sms_alerts'         => true, 'parent_app'       => true,
                    'teacher_app'        => true,
                ],
            ],
        ];

        $now    = date('Y-m-d H:i:s');
        $seeded = [];

        // ── B2.3.2-B: single-source seed ─────────────────────────────────
        $svc = $this->_b23b_registry();
        $existingPlans = $svc->list_plans();
        $existingNames = array_map(fn($p) => strtolower((string)($p['name'] ?? '')), $existingPlans);
        foreach ($defaults as $planName => $config) {
            if (in_array(strtolower($planName), $existingNames, true)) continue;
            $plan_id = 'PLAN_' . strtoupper(substr(md5(uniqid($planName, true)), 0, 6));
            $svc->create_plan($plan_id, [
                'name'         => $planName,
                'description'  => (string) ($config['description'] ?? ''),
                'price'        => (float)  ($config['price']        ?? 0),
                'billingCycle' => (string) ($config['billing_cycle']?? 'annual'),
                'graceDays'    => (int)    ($config['grace_days']   ?? 7),
                'sortOrder'    => (int)    ($config['sort_order']   ?? 99),
                'modules'      => is_array($config['modules'] ?? null) ? $config['modules'] : [],
                'limits'       => [
                    'maxStudents' => (int) ($config['max_students'] ?? 0),
                    'maxStaff'    => (int) ($config['max_staff']    ?? 0),
                ],
                'status'       => 'active',
                'createdAt'    => $now,
                'createdBy'    => (string) $this->sa_id,
            ]);
            $seeded[] = $planName;
        }
        if (empty($seeded)) {
            $this->json_success(['message' => 'Default plans already exist — no changes made.', 'seeded' => []]);
        } else {
            $this->sa_log('plans_seeded', '', ['plans' => $seeded]);
            $this->json_success(['message' => 'Created: ' . implode(', ', $seeded) . '.', 'seeded' => $seeded]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/plans/subscriptions
    // Subscription expiry tracking dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function subscriptions()
    {
        $data = ['page_title' => 'Subscription Tracking'];
        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/plans/subscriptions', $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/fetch_subscriptions
    // Returns all school subscriptions with computed status + days remaining
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_subscriptions()
    {
        // ── B2.3.2-B-2: single-source subscription rollup ────────────────
        $svc       = $this->_b23b_registry();
        $tenants   = $svc->list_tenants_summary();
        $planById  = [];
        foreach ($svc->list_plans() as $fs) {
                $pid = (string) ($fs['planFamilyId'] ?? '');
                if ($pid !== '') $planById[$pid] = $fs;
            }
            $today = date('Y-m-d');
            $rows  = [];
            foreach ($tenants as $t) {
                $expiry    = (string) $t['subscriptionPeriodEnd'];
                $grace_end = (string) $t['subscriptionGraceEnd'];
                $state     = (string) $t['lifecycleState'];
                // Canonical Firestore lifecycle.state already encodes the
                // display bucket — no recompute needed.
                static $stateToBucket = [
                    'suspended'     => 'suspended',
                    'expired'       => 'expired',
                    'grace'         => 'grace',
                    'past_due'      => 'expiring_soon',
                    'expiring_soon' => 'expiring_soon',
                    'trialing'      => 'active',
                    'active'        => 'active',
                ];
                $display = $stateToBucket[$state] ?? 'inactive';
                $planFid = (string) $t['planFamilyId'];
                $planName = '—';
                if (isset($planById[$planFid]) && is_array($planById[$planFid])) {
                    $planName = (string) ($planById[$planFid]['name'] ?? '—');
                }
                $rows[] = [
                    'uid'          => $t['schoolId'],
                    'name'         => $t['schoolName'] !== '' ? $t['schoolName'] : $t['schoolId'],
                    'school_code'  => $t['schoolCode'],
                    'plan_name'    => $planName,
                    'expiry_date'  => $expiry,
                    'grace_end'    => $grace_end,
                    'sub_status'   => $t['subscriptionStatus'],
                    'display'      => $display,
                    'days_left'    => $expiry    ? (int) ceil((strtotime($expiry)    - time()) / 86400) : null,
                    'grace_left'   => $grace_end ? (int) ceil((strtotime($grace_end) - time()) / 86400) : null,
                    'last_payment' => '', // populated separately at retirement (B2.3.3) via subscriptions doc
                ];
            }
            usort($rows, function ($a, $b) {
                if ($a['days_left'] === null) return 1;
                if ($b['days_left'] === null) return -1;
                return $a['days_left'] - $b['days_left'];
            });
            $buckets = ['active' => 0, 'expiring_soon' => 0, 'grace' => 0, 'expired' => 0, 'suspended' => 0, 'inactive' => 0];
            foreach ($rows as $r) {
                $d = $r['display'] ?? 'inactive';
                $buckets[$d] = ($buckets[$d] ?? 0) + 1;
            }
        $this->json_success(array_merge(['rows' => $rows], $buckets));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/expire_check
    // Scan all schools; move past-expiry → Grace_Period or Suspended.
    // Safe to call repeatedly — idempotent per school.
    // ─────────────────────────────────────────────────────────────────────────

    public function expire_check()
    {
        // ── B2.3.2-B-2: single-source expire check ───────────────────────
        // Canonical Firestore lifecycle is COMPUTED at write time. This
        // method re-derives state from current dates and persists transitions
        // via write_lifecycle_state(). NO RTDB read, NO RTDB write.
        $svc       = $this->_b23b_registry();
        $tenants   = $svc->list_tenants_summary();
        $today     = date('Y-m-d');
        $suspended = [];
        $graced    = [];
        foreach ($tenants as $t) {
                $sid       = (string) $t['schoolId'];
                $state     = (string) $t['lifecycleState'];
                $expiry    = (string) $t['subscriptionPeriodEnd'];
                $grace_end = (string) $t['subscriptionGraceEnd'];
                if ($expiry === '') continue;

                // active → grace/expired (legacy "Active" → state="active")
                if ($state === 'active' && $expiry < $today) {
                    if ($grace_end !== '' && $grace_end >= $today) {
                        if ($svc->write_lifecycle_state($sid, 'grace', 'period_end_passed')) {
                            $graced[] = $sid;
                            $this->sa_log('auto_grace', $sid);
                        }
                    } else {
                        if ($svc->write_lifecycle_state($sid, 'suspended', 'grace_passed_or_absent')) {
                            $suspended[] = $sid;
                            $this->sa_log('auto_suspended', $sid);
                        }
                    }
                } elseif ($state === 'grace' && $grace_end !== '' && $grace_end < $today) {
                    if ($svc->write_lifecycle_state($sid, 'suspended', 'grace_period_ended')) {
                        $suspended[] = $sid;
                        $this->sa_log('auto_suspended', $sid);
                    }
                }
            }
        $this->json_success([
            'suspended'       => $suspended,
            'suspended_count' => count($suspended),
            'graced'          => $graced,
            'graced_count'    => count($graced),
            'message'         => sprintf('Check complete. %d suspended, %d moved to grace period.',
                count($suspended), count($graced)),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PAYMENT / INVOICE MANAGEMENT
    //
    //  Data model (Firestore canonical payments collection, keyed by INV_ID):
    //    amount       – total invoice amount for the billing period
    //    amount_paid  – sum of all collections against this invoice
    //    balance      – amount − amount_paid  (auto-computed)
    //    status       – pending | partial | paid | overdue | failed
    //    transactions – { TXN_ID: {date,amount,mode,note,recorded_by,recorded_at} }
    //    + school_uid, plan_id, plan_name, billing_cycle, invoice_date,
    //      due_date, period_start, period_end, notes, created_*
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Derive invoice status from balance + due_date.
     */
    private function _derive_status(float $amount, float $amount_paid, string $due_date): string
    {
        if ($amount_paid >= $amount) return 'paid';
        $today = date('Y-m-d');
        if ($amount_paid > 0) {
            return ($due_date && $due_date < $today) ? 'overdue' : 'partial';
        }
        return ($due_date && $due_date < $today) ? 'overdue' : 'pending';
    }

    /**
     * Helper: compute next billing period for a school.
     * Returns [period_start, period_end, due_date, cycle_months].
     */
    private function _next_billing_period(string $school_uid, array $sub, array $plan_data, array $allPayments): array
    {
        $billing_cycle = $plan_data['billing_cycle'] ?? ($sub['billing_cycle'] ?? 'annual');
        $cycleMonths   = ($billing_cycle === 'monthly') ? 1 : (($billing_cycle === 'quarterly') ? 3 : 12);

        // Find latest paid invoice's period_end
        $latestPeriodEnd = '';
        $latestPaidDate  = $sub['last_payment_date'] ?? '';
        foreach ($allPayments as $pid => $pay) {
            if (!is_array($pay)) continue;
            if (($pay['school_uid'] ?? '') !== $school_uid) continue;
            if (($pay['status'] ?? '') !== 'paid') continue;
            $pe = $pay['period_end'] ?? '';
            if ($pe > $latestPeriodEnd) $latestPeriodEnd = $pe;
            $pd = $pay['paid_date'] ?? '';
            if ($pd > $latestPaidDate) $latestPaidDate = $pd;
        }

        $baseDate = $latestPeriodEnd ?: $latestPaidDate ?: ($sub['expiry_date'] ?? '');
        if ($baseDate) {
            $periodStart = date('Y-m-d', strtotime($baseDate . ' +1 day'));
        } else {
            $periodStart = date('Y-m-d');
        }
        $periodEnd = date('Y-m-d', strtotime($periodStart . " +{$cycleMonths} months -1 day"));

        return [
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'due_date'     => $periodStart,
            'cycle_months' => $cycleMonths,
            'last_paid_date'  => $latestPaidDate,
            'last_period_end' => $latestPeriodEnd,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/plans/payments
    // ─────────────────────────────────────────────────────────────────────────

    public function payments()
    {
        $schools = [];
        $plans   = [];

        // ── B2.3.2-B-2: single-source dropdown context ───────────────────
        $svc      = $this->_b23b_registry();
        $tenants  = $svc->list_tenants_summary();
        $planById = [];
        foreach ($svc->list_plans() as $fs) {
            $pid = (string) ($fs['planFamilyId'] ?? '');
            if ($pid !== '') $planById[$pid] = $fs;
        }
        foreach ($tenants as $t) {
            $sid  = (string) $t['schoolId'];
            $pfid = (string) $t['planFamilyId'];
            $planName = isset($planById[$pfid]) ? (string) ($planById[$pfid]['name'] ?? '—') : '—';
            $schools[$sid] = [
                'name'        => $t['schoolName'] !== '' ? $t['schoolName'] : $sid,
                'plan_name'   => $planName,
                'school_code' => $t['schoolCode'],
            ];
        }
        foreach ($planById as $pid => $fs) {
            $plans[$pid] = [
                'name'          => (string) ($fs['name'] ?? $pid),
                'price'         => (float)  ($fs['price'] ?? 0),
                'billing_cycle' => (string) ($fs['billingCycle'] ?? 'annual'),
            ];
        }

        $data = [
            'page_title' => 'Payment Records',
            'schools'    => $schools,
            'plans'      => $plans,
        ];
        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/plans/payments',    $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/fetch_payments
    // Returns all invoices, auto-migrates legacy records, auto-marks overdue.
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_payments()
    {
        // ── B2.3.2-B-2: single-source payments list ──────────────────────
        $svc       = $this->_b23b_registry();
        $tenants   = $svc->list_tenants_summary();
        $payments  = $svc->list_payments();
        $today     = date('Y-m-d');
        $schoolNames = [];
        foreach ($tenants as $t) {
            $schoolNames[(string) $t['schoolId']] = $t['schoolName'] !== '' ? $t['schoolName'] : (string) $t['schoolId'];
        }
        $rows = [];
        foreach ($payments as $p) {
            if (!is_array($p)) continue;
            $pid      = (string) ($p['paymentId'] ?? '');
            $status   = (string) ($p['status']   ?? 'pending');
            $due_date = (string) ($p['due_date'] ?? $p['dueDate'] ?? '');
            // Auto-mark overdue (canonical Firestore field names: snake_case
            // preserved at this layer — view template reads these keys).
            if (in_array($status, ['pending', 'partial'], true) && $due_date !== '' && $due_date < $today) {
                $svc->update_payment($pid, [
                    'status'     => 'overdue',
                    'updated_at' => date('Y-m-d H:i:s'),
                    'updated_by' => 'system_auto',
                ]);
                $p['status'] = 'overdue';
            }
            $days_due = ($due_date !== '') ? (int) round((strtotime($due_date) - strtotime($today)) / 86400) : null;
            $p['payment_id']  = $pid;
            $p['school_name'] = $schoolNames[$p['school_uid'] ?? ''] ?? ($p['school_uid'] ?? '—');
            $p['days_due']    = $days_due;
            $rows[] = $p;
        }
        usort($rows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $this->json_success(['rows' => $rows]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/get_school_plan
    // Returns plan + billing info + outstanding balance for a school.
    // ─────────────────────────────────────────────────────────────────────────

    public function get_school_plan()
    {
        $school_uid = trim($this->input->post('school_uid', TRUE) ?? '');
        if (empty($school_uid)) { $this->json_error('School ID required.'); return; }
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) {
            $this->json_error('Invalid school identifier.'); return;
        }

        // ── B2.3.2-B-2: single-source school-plan detail ─────────────────
        {
            $svc    = $this->_b23b_registry();
            $detail = $svc->get_tenant_detail($school_uid);
            if (!is_array($detail)) {
                $this->json_error('School not found.'); return;
            }
            $plan_id   = (string) $detail['planFamilyId'];
            $sub       = is_array($detail['schoolControl']['subscription'] ?? null) ? $detail['schoolControl']['subscription'] : [];
            $subDoc    = is_array($detail['subscriptionDoc'] ?? null) ? $detail['subscriptionDoc'] : [];
            $life      = is_array($detail['schoolControl']['lifecycle']    ?? null) ? $detail['schoolControl']['lifecycle']    : [];

            $plan_data = ($plan_id !== '') ? ($svc->get_plan($plan_id) ?? []) : [];
            $plan_name = (string) ($plan_data['name'] ?? '');
            $price     = (float)  ($plan_data['price'] ?? 0);
            $billing_cycle = (string) ($plan_data['billingCycle'] ?? $subDoc['billingCycle'] ?? 'annual');

            $payments = $svc->list_payments_for_school($school_uid);
            $outstanding_balance = 0.0;
            $outstanding_id      = '';
            foreach ($payments as $pay) {
                if (!is_array($pay)) continue;
                $st = (string) ($pay['status'] ?? '');
                if (in_array($st, ['pending', 'partial', 'overdue'], true)) {
                    $bal = isset($pay['balance'])
                        ? (float) $pay['balance']
                        : ((float)($pay['amount'] ?? 0) - (float)($pay['amount_paid'] ?? 0));
                    $outstanding_balance += $bal;
                    if ($outstanding_id === '') {
                        $outstanding_id = (string) ($pay['paymentId'] ?? '');
                    }
                }
            }

            // _next_billing_period expects RTDB-shaped $sub/$plan_data/$allPayments arrays.
            // Adapt the Firestore shapes locally — the helper itself is pure & store-agnostic.
            $subForNext  = $sub + [
                'last_payment_date' => '',
                'expiry_date'       => (string) ($subDoc['periodEnd'] ?? ''),
                'billing_cycle'     => (string) ($subDoc['billingCycle'] ?? $billing_cycle),
            ];
            $planForNext = $plan_data + [
                'billing_cycle' => $billing_cycle,
                'grace_days'    => (int) ($plan_data['graceDays'] ?? 7),
            ];
            // Adapt payment shape (helper looks for school_uid / status='paid' / period_end / paid_date).
            $paysForNext = [];
            foreach ($payments as $pay) {
                $paysForNext[(string)($pay['paymentId'] ?? '')] = $pay;
            }
            $next = $this->_next_billing_period($school_uid, $subForNext, $planForNext, $paysForNext);

            $this->json_success([
                'plan_id'             => $plan_id,
                'plan_name'           => $plan_name,
                'price'               => $price,
                'billing_cycle'       => $billing_cycle,
                'expiry_date'         => (string) ($subDoc['periodEnd'] ?? ''),
                'sub_status'          => (string) ($life['state'] ?? 'inactive'),
                'last_paid_date'      => $next['last_paid_date'],
                'last_period_end'     => $next['last_period_end'],
                'next_due_date'       => $next['due_date'],
                'next_period_start'   => $next['period_start'],
                'next_period_end'     => $next['period_end'],
                'cycle_months'        => $next['cycle_months'],
                'grace_days'          => (int) ($plan_data['graceDays'] ?? 7),
                'outstanding_balance' => $outstanding_balance,
                'outstanding_id'      => $outstanding_id,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/generate_invoice
    // Creates an invoice for the next billing period.
    // ─────────────────────────────────────────────────────────────────────────

    public function generate_invoice()
    {
        $school_uid = trim($this->input->post('school_uid', TRUE) ?? '');
        if (empty($school_uid)) { $this->json_error('School ID required.'); return; }
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) {
            $this->json_error('Invalid school identifier.'); return;
        }

        // B2.3α-BRIDGE: initialized for safe scope in catch (deleted at B2.7).
        $hardenOn   = false;
        $payment_id = '';

        // ── B2.3.2-B-3: single-source generate_invoice ───────────────────
        // Billing_integrity claim ledger is already Firestore-only (B2.3α).
        // Subscription + plan + payment reads/writes target Firestore canonical
        // collections. NO RTDB read, NO RTDB write, NO fallback, NO dual-write,
        // NO shadow.
        {
            try {
                $svc    = $this->_b23b_registry();
                $detail = $svc->get_tenant_detail($school_uid);
                if (!is_array($detail)) {
                    $this->json_error('School not found.'); return;
                }
                $sub      = is_array($detail['schoolControl']['subscription'] ?? null) ? $detail['schoolControl']['subscription'] : [];
                $subDoc   = is_array($detail['subscriptionDoc'] ?? null) ? $detail['subscriptionDoc'] : [];
                $plan_id  = (string) ($detail['planFamilyId'] ?? '');
                if ($plan_id === '') { $this->json_error('No plan assigned to this school.'); return; }
                $plan_data = $svc->get_plan($plan_id);
                if (!is_array($plan_data)) { $this->json_error('Plan not found.'); return; }
                $billing_cycle = (string) ($plan_data['billingCycle'] ?? 'annual');
                $price         = (float)  ($plan_data['price']        ?? 0);
                if ($price <= 0) { $this->json_error('Plan has no price set.'); return; }

                // Pre-claim conflict scan (legacy O(N)): ONLY runs when
                // Billing_integrity hardening is OFF. When ON, Billing_integrity
                // is the sole conflict gate (identical contract to the legacy
                // path's B2.3α gate).
                $allPaysList = $svc->list_payments_for_school($school_uid);
                $hardenOn = $this->_bi_active();
                if (!$hardenOn) {
                    foreach ($allPaysList as $pay) {
                        $st = (string) ($pay['status'] ?? '');
                        if (in_array($st, ['pending', 'partial', 'overdue'], true)) {
                            $bal = isset($pay['balance']) ? (float) $pay['balance']
                                 : ((float)($pay['amount'] ?? 0) - (float)($pay['amount_paid'] ?? 0));
                            if ($bal > 0) {
                                $payId = (string) ($pay['paymentId'] ?? '');
                                $this->json_error("Outstanding invoice {$payId} has ₹" . number_format($bal, 2) . " remaining. Collect or write off before generating a new invoice.");
                                return;
                            }
                        }
                    }
                }

                // Compute next billing period (adapter shape — helper is pure).
                $subForNext = $sub + [
                    'last_payment_date' => (string) ($sub['lastPaymentDate'] ?? ''),
                    'expiry_date'       => (string) ($subDoc['periodEnd'] ?? ''),
                    'billing_cycle'     => $billing_cycle,
                ];
                $planForNext = $plan_data + [
                    'billing_cycle' => $billing_cycle,
                    'grace_days'    => (int) ($plan_data['graceDays'] ?? 7),
                ];
                $paysForNext = [];
                foreach ($allPaysList as $p) {
                    $paysForNext[(string)($p['paymentId'] ?? '')] = $p;
                }
                $next       = $this->_next_billing_period($school_uid, $subForNext, $planForNext, $paysForNext);
                $payment_id = 'INV_' . strtoupper(substr(md5(uniqid($school_uid, true)), 0, 8));
                $now        = date('Y-m-d H:i:s');

                if ($hardenOn) {
                    $bi = $this->billing_integrity->claimOpenInvoice($school_uid, (string) $next['period_start'], $payment_id);
                    if (!empty($bi['conflict'])) {
                        $this->_bi_telem('B2_BILLING_CLAIM', [
                            'outcome'           => 'conflict',
                            'op'                => 'generate_invoice',
                            'schoolId'          => $school_uid,
                            'existingInvoiceId' => (string) ($bi['existingInvoiceId'] ?? ''),
                            'existingPeriod'    => (string) ($bi['existingPeriod']    ?? ''),
                        ]);
                        $this->json_error('Outstanding open invoice ' . ($bi['existingInvoiceId'] ?? '') . ' (period ' . ($bi['existingPeriod'] ?? '') . '). Collect or write off first.', 409);
                        return;
                    }
                    if (!empty($bi['error'])) { $this->json_error('Billing service unavailable. Please retry.', 503); return; }
                    $this->_bi_telem('B2_BILLING_CLAIM', [
                        'outcome' => 'locked', 'op' => 'generate_invoice',
                        'schoolId' => $school_uid, 'invoiceId' => $payment_id,
                    ]);
                }

                $svc->create_payment($payment_id, [
                    'school_uid'    => $school_uid,
                    'amount'        => $price,
                    'amount_paid'   => 0,
                    'balance'       => $price,
                    'plan_id'       => $plan_id,
                    'plan_name'     => (string) ($plan_data['name'] ?? $plan_id),
                    'billing_cycle' => $billing_cycle,
                    'status'        => 'pending',
                    'invoice_date'  => date('Y-m-d'),
                    'due_date'      => $next['due_date'],
                    'period_start'  => $next['period_start'],
                    'period_end'    => $next['period_end'],
                    'transactions'  => [],
                    'created_by'    => (string) $this->sa_id,
                    'created_at'    => $now,
                ]);

                $this->sa_log('invoice_generated', $school_uid, [
                    'invoice_id' => $payment_id, 'amount' => $price,
                ]);
                $this->json_success(['invoice_id' => $payment_id, 'amount' => $price]);
                return;
            } catch (\Throwable $e) {
                if ($hardenOn && $payment_id !== '') {
                    try { $this->billing_integrity->releaseOpenInvoice($school_uid, $payment_id); }
                    catch (\Throwable $ignored) {}
                }
                log_message('error', 'SA plans/generate_invoice (FS): ' . $e->getMessage());
                $this->json_error('Failed to generate invoice.');
                return;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/collect_payment
    // Records a payment transaction against an existing invoice.
    // Supports partial payments — updates amount_paid, balance, status.
    // ─────────────────────────────────────────────────────────────────────────

    public function collect_payment()
    {
        $invoice_id = trim($this->input->post('invoice_id',    TRUE) ?? '');
        $pay_amount = (float)($this->input->post('pay_amount', TRUE) ?? 0);
        $pay_date   = trim($this->input->post('pay_date',      TRUE) ?? date('Y-m-d'));
        $pay_mode   = trim($this->input->post('pay_mode',      TRUE) ?? '—');
        $pay_note   = trim($this->input->post('pay_note',      TRUE) ?? '');

        if (empty($invoice_id) || !preg_match('/^(PAY|INV)_[A-Z0-9]+$/', $invoice_id)) {
            $this->json_error('Invalid invoice ID.'); return;
        }
        if ($pay_amount <= 0) {
            $this->json_error('Payment amount must be greater than zero.'); return;
        }

        // ── B2.3α-BRIDGE: claim-first via Billing_integrity (gated by b2.billing_harden). ──
        // Permanent: idempotency-key derivation + claim semantics. Bridge: this flag-gated wrapper (deleted at B2.7).
        $hardenOn = $this->_bi_active();
        $idempKey = null;
        if ($hardenOn) {
            $idempKey = $this->_bi_idem_key('pay_collect', [
                $invoice_id, number_format((float) $pay_amount, 2, '.', ''), $pay_date, $pay_mode,
            ]);
            $bi = $this->billing_integrity->beginPayment($idempKey, [
                'invoiceId' => $invoice_id, 'amount' => $pay_amount,
            ]);
            if (!empty($bi['dedup'])) {
                $cached = is_array($bi['result'] ?? null) ? $bi['result'] : [];
                $this->_bi_telem('B2_BILLING_CLAIM', [
                    'outcome' => 'dedup', 'op' => 'collect_payment',
                    'invoiceId' => $invoice_id, 'idempKey' => $idempKey,
                ]);
                $this->json_success(array_merge($cached, [
                    'message' => 'Replay: payment already recorded (' . ($cached['txn_id'] ?? '') . ').',
                ]));
                return;
            }
            if (!empty($bi['in_progress'])) {
                $this->_bi_telem('B2_BILLING_CLAIM', [
                    'outcome' => 'in_progress', 'op' => 'collect_payment',
                    'invoiceId' => $invoice_id, 'idempKey' => $idempKey,
                    'ageSec' => (int) ($bi['ageSec'] ?? 0),
                ]);
                $this->json_error('Payment in progress. Please wait.', 409);
                return;
            }
            if (!empty($bi['error'])) {
                $this->json_error('Billing service unavailable. Please retry.', 503);
                return;
            }
            $this->_bi_telem('B2_BILLING_CLAIM', [
                'outcome'  => !empty($bi['reclaimed']) ? 'reclaimed' : 'claimed',
                'op'       => 'collect_payment',
                'invoiceId'=> $invoice_id, 'idempKey' => $idempKey,
            ]);
        }

        // ── B2.3.2-B-3: single-source collect_payment ────────────────────
        // Billing_integrity claim ledger (above) is Firestore-only. The
        // invoice read/write and subscription-sync post-action target
        // Firestore canonical collections.
        {
            try {
                $svc = $this->_b23b_registry();
                $inv = $svc->get_payment($invoice_id);
                if (!is_array($inv) || empty($inv)) {
                    $this->json_error('Invoice not found.'); return;
                }
                $amount      = (float) ($inv['amount']      ?? 0);
                $amount_paid = (float) ($inv['amount_paid']  ?? 0);
                $balance     = (float) ($inv['balance']      ?? ($amount - $amount_paid));
                if ($balance <= 0) {
                    $this->json_error('This invoice is already fully paid.'); return;
                }
                $actual_pay = min($pay_amount, $balance);
                $new_paid   = $amount_paid + $actual_pay;
                $new_bal    = $amount - $new_paid;
                $new_status = $this->_derive_status($amount, $new_paid, (string)($inv['due_date'] ?? ''));
                $txnId = 'TXN_' . strtoupper(substr(md5(uniqid($invoice_id, true)), 0, 8));
                $now   = date('Y-m-d H:i:s');

                $txns = is_array($inv['transactions'] ?? null) ? $inv['transactions'] : [];
                $txns[$txnId] = [
                    'date'        => $pay_date,
                    'amount'      => $actual_pay,
                    'mode'        => $pay_mode,
                    'note'        => $pay_note,
                    'recorded_by' => (string) $this->sa_id,
                    'recorded_at' => $now,
                ];
                $patch = [
                    'amount_paid'  => $new_paid,
                    'balance'      => $new_bal,
                    'status'       => $new_status,
                    'updated_at'   => $now,
                    'updated_by'   => (string) $this->sa_id,
                    'transactions' => $txns,
                ];
                if ($new_status === 'paid') $patch['paid_date'] = $pay_date;

                $svc->update_payment($invoice_id, $patch);

                if ($hardenOn && $idempKey !== null) {
                    $this->billing_integrity->completePayment($idempKey, [
                        'txn_id' => $txnId, 'amount_paid' => $actual_pay,
                        'new_balance' => $new_bal, 'new_status'  => $new_status,
                    ]);
                }

                if ($new_status === 'paid') {
                    $svc->record_payment_completion((string) ($inv['school_uid'] ?? ''), [
                        'paidDate'     => $pay_date,
                        'amount'       => (float) ($inv['amount'] ?? 0),
                        'periodEnd'    => (string) ($inv['period_end'] ?? ''),
                        'planFamilyId' => (string) ($inv['plan_id']     ?? ''),
                    ]);
                    if ($hardenOn) {
                        try { $this->billing_integrity->releaseOpenInvoice((string) ($inv['school_uid'] ?? ''), $invoice_id); } catch (\Throwable $eR) {}
                        $this->_bi_telem('B2_BILLING_CLAIM', [
                            'outcome' => 'invoice_released', 'op' => 'collect_payment',
                            'invoiceId' => $invoice_id, 'schoolId' => (string) ($inv['school_uid'] ?? ''),
                        ]);
                    }
                }
                $this->sa_log('payment_collected', $inv['school_uid'] ?? '', [
                    'invoice_id' => $invoice_id, 'txn_id' => $txnId, 'amount' => $actual_pay,
                ]);
                $msg = "₹" . number_format($actual_pay, 2) . " collected against {$invoice_id}.";
                $msg .= ($new_bal > 0)
                    ? " Balance remaining: ₹" . number_format($new_bal, 2)
                    : " Invoice fully paid!";
                $this->json_success([
                    'txn_id'      => $txnId,
                    'amount_paid' => $actual_pay,
                    'new_balance' => $new_bal,
                    'new_status'  => $new_status,
                    'message'     => $msg,
                ]);
                return;
            } catch (\Throwable $e) {
                if ($hardenOn && $idempKey !== null) {
                    try { $this->billing_integrity->failPayment($idempKey, 'firestore_update_failed'); } catch (\Throwable $eR) {}
                    $this->_bi_telem('B2_BILLING_CLAIM', [
                        'outcome' => 'failed', 'op' => 'collect_payment',
                        'invoiceId' => $invoice_id, 'idempKey' => $idempKey,
                        'reason' => 'firestore_update_failed',
                    ]);
                }
                log_message('error', 'SA plans/collect_payment (FS): ' . $e->getMessage());
                $this->json_error('Failed to record payment.');
                return;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/add_payment
    // Quick-add: creates a new invoice (optionally with an immediate payment).
    // ─────────────────────────────────────────────────────────────────────────

    public function add_payment()
    {
        $school_uid   = trim($this->input->post('school_uid',   TRUE) ?? $this->input->post('school_name', TRUE) ?? '');
        $amount       = (float)($this->input->post('amount',    TRUE) ?? 0);
        $plan_id      = trim($this->input->post('plan_id',      TRUE) ?? $this->input->post('plan_name', TRUE) ?? '');
        $status       = trim($this->input->post('status',       TRUE) ?? 'pending');
        $invoice_date = trim($this->input->post('invoice_date', TRUE) ?? date('Y-m-d'));
        $due_date     = trim($this->input->post('due_date',     TRUE) ?? '');
        $paid_date    = trim($this->input->post('paid_date',    TRUE) ?? '');
        $period_start = trim($this->input->post('period_start', TRUE) ?? '');
        $period_end   = trim($this->input->post('period_end',   TRUE) ?? '');
        $notes        = trim($this->input->post('notes',        TRUE) ?? '');
        $pay_mode     = trim($this->input->post('pay_mode',     TRUE) ?? '—');

        if (empty($school_uid) || $amount <= 0 || empty($plan_id)) {
            $this->json_error('School, amount and plan are required.'); return;
        }
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) {
            $this->json_error('Invalid school identifier.'); return;
        }
        if (!in_array($status, ['paid', 'pending', 'partial', 'overdue', 'failed'])) {
            $this->json_error('Invalid payment status.'); return;
        }
        if (!preg_match('/^PLAN_[A-Z0-9]+$/', $plan_id)) {
            $this->json_error('Invalid plan ID format.'); return;
        }
        if ($status === 'paid' && !empty($paid_date) && !empty($invoice_date) && $paid_date < $invoice_date) {
            $this->json_error('Paid date cannot be before invoice date.'); return;
        }

        // ── B2.3.2-B-3: single-source plan lookup ────────────────────────
        $plan_data = [];
        $svc       = $this->_b23b_registry();
        $fsPlan    = $svc->get_plan($plan_id);
        // Translate to legacy-shape keys the payment-doc builder below reads.
        if (is_array($fsPlan)) {
            $plan_data = [
                'name'          => (string) ($fsPlan['name']        ?? $plan_id),
                'billing_cycle' => (string) ($fsPlan['billingCycle'] ?? 'annual'),
                'price'         => (float)  ($fsPlan['price']       ?? 0),
                'grace_days'    => (int)    ($fsPlan['graceDays']   ?? 7),
            ];
        }

        $now        = date('Y-m-d H:i:s');
        $payment_id = 'INV_' . strtoupper(substr(md5(uniqid($school_uid, true)), 0, 8));

        // Determine initial amounts
        $initial_paid = 0;
        $txns         = null;
        if ($status === 'paid') {
            $initial_paid = $amount;
            $txnId = 'TXN_' . strtoupper(substr(md5(uniqid($payment_id, true)), 0, 8));
            $txns = [$txnId => [
                'date'        => $paid_date ?: date('Y-m-d'),
                'amount'      => $amount,
                'mode'        => $pay_mode,
                'note'        => $notes ?: 'Full payment',
                'recorded_by' => $this->sa_id,
                'recorded_at' => $now,
            ]];
        }

        // ── B2.3α-BRIDGE: claim-first via Billing_integrity (gated). ──
        // Permanent: idempotency semantics. Bridge: flag-gated wrapper (deleted at B2.7).
        $hardenOn = $this->_bi_active();
        $idempKey = null;
        if ($hardenOn) {
            $idempKey = $this->_bi_idem_key('pay_add', [
                $school_uid, $plan_id, number_format((float) $amount, 2, '.', ''),
                $invoice_date, $period_start, $period_end, $status,
            ]);
            $bi = $this->billing_integrity->beginPayment($idempKey, [
                'schoolId' => $school_uid, 'amount' => $amount,
            ]);
            if (!empty($bi['dedup'])) {
                $cached = is_array($bi['result'] ?? null) ? $bi['result'] : [];
                $this->_bi_telem('B2_BILLING_CLAIM', [
                    'outcome' => 'dedup', 'op' => 'add_payment',
                    'schoolId' => $school_uid, 'idempKey' => $idempKey,
                ]);
                $this->json_success(array_merge($cached, [
                    'message' => 'Replay: invoice already created (' . ($cached['payment_id'] ?? '') . ').',
                ]));
                return;
            }
            if (!empty($bi['in_progress'])) {
                $this->json_error('Add-payment in progress. Please wait.', 409);
                return;
            }
            if (!empty($bi['error'])) {
                $this->json_error('Billing service unavailable. Please retry.', 503);
                return;
            }
            $this->_bi_telem('B2_BILLING_CLAIM', [
                'outcome' => !empty($bi['reclaimed']) ? 'reclaimed' : 'claimed',
                'op' => 'add_payment', 'schoolId' => $school_uid, 'idempKey' => $idempKey,
            ]);
        }

        try {
            $payDoc = [
                'school_uid'    => $school_uid,
                'amount'        => $amount,
                'amount_paid'   => $initial_paid,
                'balance'       => $amount - $initial_paid,
                'plan_id'       => $plan_id,
                'plan_name'     => $plan_data['name']          ?? $plan_id,
                'billing_cycle' => $plan_data['billing_cycle'] ?? 'annual',
                'status'        => $status === 'paid' ? 'paid' : $this->_derive_status($amount, $initial_paid, $due_date),
                'invoice_date'  => $invoice_date,
                'due_date'      => $due_date,
                'paid_date'     => ($status === 'paid') ? ($paid_date ?: date('Y-m-d')) : '',
                'period_start'  => $period_start,
                'period_end'    => $period_end,
                'transactions'  => $txns,
                'notes'         => $notes,
                'created_by'    => (string) $this->sa_id,
                'created_at'    => $now,
            ];

            // ── B2.3.2-B-3: single-source payment write + subscription sync ──
            $this->_b23b_registry()->create_payment($payment_id, $payDoc);

            // B2.3α-BRIDGE: complete the claim (permanent dedup ledger) after data-store write succeeds.
            if ($hardenOn && $idempKey !== null) {
                $this->billing_integrity->completePayment($idempKey, [
                    'payment_id' => $payment_id, 'status' => $status,
                ]);
            }

            if ($status === 'paid') {
                $this->_b23b_registry()->record_payment_completion($school_uid, [
                    'paidDate'     => ($paid_date ?: date('Y-m-d')),
                    'amount'       => $amount,
                    'periodEnd'    => $period_end,
                    'planFamilyId' => $plan_id,
                ]);

                // B2.3α-BRIDGE: defensive open-invoice release (no-op if no lock owned for this id).
                if ($hardenOn) {
                    try { $this->billing_integrity->releaseOpenInvoice($school_uid, $payment_id); } catch (\Throwable $eR) {}
                }
            }

            $this->sa_log('payment_added', $school_uid, ['payment_id' => $payment_id, 'amount' => $amount]);
            $this->json_success(['payment_id' => $payment_id, 'message' => 'Invoice created.']);
        } catch (Exception $e) {
            // B2.3α-BRIDGE: fail the claim so retries can reclaim.
            if ($hardenOn && $idempKey !== null) {
                try { $this->billing_integrity->failPayment($idempKey, 'rtdb_set_failed'); } catch (\Throwable $eR) {}
                $this->_bi_telem('B2_BILLING_CLAIM', [
                    'outcome' => 'failed', 'op' => 'add_payment',
                    'schoolId' => $school_uid, 'idempKey' => $idempKey,
                    'reason' => 'rtdb_set_failed',
                ]);
            }
            $this->json_error('Failed to save invoice.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/update_payment
    // Updates invoice metadata (status, notes). Does NOT record payments —
    // use collect_payment for that.
    // ─────────────────────────────────────────────────────────────────────────

    public function update_payment()
    {
        $payment_id = trim($this->input->post('payment_id', TRUE) ?? '');
        $status     = trim($this->input->post('status',     TRUE) ?? '');
        $paid_date  = trim($this->input->post('paid_date',  TRUE) ?? '');
        $notes      = trim($this->input->post('notes',      TRUE) ?? '');
        $due_date   = trim($this->input->post('due_date',   TRUE) ?? '');

        if (empty($payment_id) || !preg_match('/^(PAY|INV)_[A-Z0-9]+$/', $payment_id)) {
            $this->json_error('Invalid invoice ID.'); return;
        }
        if (!empty($status) && !in_array($status, ['paid', 'pending', 'partial', 'overdue', 'failed'])) {
            $this->json_error('Invalid payment status.'); return;
        }

        // ── B2.3.2-B-3: single-source payment-record read/write ──────────
        try {
            $existing = $this->_b23b_registry()->get_payment($payment_id);
            if (empty($existing)) { $this->json_error('Invoice not found.'); return; }

            $update = ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->sa_id];
            if ($status)         $update['status']    = $status;
            if ($paid_date)      $update['paid_date'] = $paid_date;
            if ($due_date)       $update['due_date']  = $due_date;
            if ($notes !== '')   $update['notes']     = $notes;

            // If force-marking as paid, set amount_paid = amount, balance = 0
            if ($status === 'paid') {
                $amt = (float)($existing['amount'] ?? 0);
                $update['amount_paid'] = $amt;
                $update['balance']     = 0;
                $update['paid_date']   = $paid_date ?: date('Y-m-d');
            }

            // ── B2.3α-BRIDGE: destructive-path guardrail (release-and-audit, no claim integration). ──
            // Deleted at B2.7 when this method is replaced by the Firestore void/credit-note primitive.
            $hardenOnUpd = $this->_bi_active();
            if ($hardenOnUpd) {
                $school_uid_legacy = (string) ($existing['school_uid'] ?? '');
                if ($school_uid_legacy !== '') {
                    try { $this->billing_integrity->releaseOpenInvoice($school_uid_legacy, $payment_id); } catch (\Throwable $eR) {}
                }
                $this->_bi_telem('B2_BILLING_WRITE', [
                    'outcome'   => ($status === 'paid' ? 'forced_paid_override' : 'invoice_metadata_update'),
                    'op'        => 'update_payment',
                    'invoiceId' => $payment_id,
                    'schoolId'  => $school_uid_legacy,
                    'actor'     => (string) ($this->sa_id ?? ''),
                    'newStatus' => $status,
                ]);
            }

            $this->_b23b_registry()->update_payment($payment_id, $update);

            if ($status === 'paid') {
                $this->_b23b_registry()->record_payment_completion(
                    (string) ($existing['school_uid'] ?? ''),
                    [
                        'paidDate'     => (string) $update['paid_date'],
                        'amount'       => (float)  ($existing['amount']     ?? 0),
                        'periodEnd'    => (string) ($existing['period_end'] ?? ''),
                        'planFamilyId' => (string) ($existing['plan_id']     ?? ''),
                    ]
                );
            }

            $this->sa_log('payment_updated', $existing['school_uid'] ?? '', ['payment_id' => $payment_id]);
            $this->json_success(['message' => 'Invoice updated.']);
        } catch (Exception $e) {
            $this->json_error('Failed to update invoice.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/delete_payment
    // ─────────────────────────────────────────────────────────────────────────

    public function delete_payment()
    {
        $payment_id = trim($this->input->post('payment_id', TRUE) ?? '');
        if (empty($payment_id) || !preg_match('/^(PAY|INV)_[A-Z0-9]+$/', $payment_id)) {
            $this->json_error('Invalid invoice ID.'); return;
        }

        // ── B2.3.2-B-3: single-source payment delete ─────────────────────
        try {
            $existing = $this->_b23b_registry()->get_payment($payment_id);
            if (empty($existing)) { $this->json_error('Invoice not found.'); return; }

            // ── B2.3α-BRIDGE: destructive-path guardrail (release-and-audit; claim ledger preserved). ──
            // Deleted at B2.7 when this method is replaced by the Firestore void/credit-note primitive.
            $hardenOnDel = $this->_bi_active();
            if ($hardenOnDel) {
                $school_uid_legacy = (string) ($existing['school_uid'] ?? '');
                if ($school_uid_legacy !== '') {
                    try { $this->billing_integrity->releaseOpenInvoice($school_uid_legacy, $payment_id); } catch (\Throwable $eR) {}
                }
                $this->_bi_telem('B2_BILLING_WRITE', [
                    'outcome'   => 'invoice_deleted',
                    'op'        => 'delete_payment',
                    'invoiceId' => $payment_id,
                    'schoolId'  => $school_uid_legacy,
                    'actor'     => (string) ($this->sa_id ?? ''),
                ]);
            }

            $this->_b23b_registry()->delete_payment($payment_id);
            $this->sa_log('payment_deleted', $existing['school_uid'] ?? '', ['payment_id' => $payment_id]);
            $this->json_success(['message' => 'Invoice deleted.']);
        } catch (Exception $e) {
            $this->json_error('Failed to delete invoice.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/fetch_school_payments
    // Full ledger for a single school.
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch_school_payments()
    {
        $school_uid = trim($this->input->post('school_uid', TRUE) ?? '');
        if (empty($school_uid)) { $this->json_error('School ID required.'); return; }

        // ── B2.3.2-B-2: single-source per-school payments ────────────────
        {
            $svc        = $this->_b23b_registry();
            $detail     = $svc->get_tenant_detail($school_uid);
            $payments   = $svc->list_payments_for_school($school_uid);
            $sub        = is_array($detail['schoolControl']['subscription'] ?? null) ? $detail['schoolControl']['subscription'] : [];
            $subDoc     = is_array($detail['subscriptionDoc'] ?? null) ? $detail['subscriptionDoc'] : [];
            $life       = is_array($detail['schoolControl']['lifecycle'] ?? null) ? $detail['schoolControl']['lifecycle'] : [];
            $plan_id    = (string) ($sub['planId'] ?? '');
            $plan_data  = ($plan_id !== '') ? ($svc->get_plan($plan_id) ?? []) : [];

            $rows         = [];
            $totalPaid    = 0.0;
            $totalBilled  = 0.0;
            $totalBalance = 0.0;
            foreach ($payments as $p) {
                if (!is_array($p)) continue;
                $rows[] = $p + ['payment_id' => (string)($p['paymentId'] ?? '')];
                $totalBilled  += (float) ($p['amount']      ?? 0);
                $totalPaid    += (float) ($p['amount_paid'] ?? 0);
                $totalBalance += (float) ($p['balance']     ?? 0);
            }
            usort($rows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

            $this->json_success([
                'rows'          => $rows,
                'total_billed'  => $totalBilled,
                'total_paid'    => $totalPaid,
                'total_balance' => $totalBalance,
                'plan_name'     => (string) ($plan_data['name'] ?? '—'),
                'billing_cycle' => (string) ($plan_data['billingCycle'] ?? $subDoc['billingCycle'] ?? '—'),
                'expiry_date'   => (string) ($subDoc['periodEnd'] ?? ''),
                'sub_status'    => (string) ($life['state'] ?? 'inactive'),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wave B2.3α (2026-05-30) — Billing_integrity helpers
    // Gates legacy RTDB billing through the Firestore-native claim primitive.
    // Permanent SEMANTICS (claim+release+key-derivation+telemetry pattern)
    // survive into the Firestore-authoritative writer at B2.7. Only the
    // flag-gated bridge wrapper retires at B2.8.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * B2.3.2-B helper: lazy-load + bind the B2_registry_service. Idempotent.
     * Returns the bound service instance.
     */
    private function _b23b_registry()
    {
        $this->load->library('b2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        return $this->b2_registry_service;
    }

    /**
     * B2.3.2-B helper: translate Firestore canonical plan shape to the
     * legacy snake_case shape the existing view templates expect.
     *
     * NOT a compatibility mirror: scope-limited to the cutover phase + the
     * Superadmin_plans view templates. Deleted at B2.3.3 retirement when
     * the view templates are updated to read canonical Firestore shapes
     * directly.
     */
    private function _b23b_plan_view_shape(array $fsPlan): array
    {
        $modules = isset($fsPlan['modules']) && is_array($fsPlan['modules']) ? $fsPlan['modules'] : [];
        $limits  = isset($fsPlan['limits'])  && is_array($fsPlan['limits'])  ? $fsPlan['limits']  : [];
        return [
            'plan_id'       => (string) ($fsPlan['planFamilyId'] ?? ''),
            'name'          => (string) ($fsPlan['name']          ?? ''),
            'price'         => (float)  ($fsPlan['price']         ?? 0),
            'billing_cycle' => (string) ($fsPlan['billingCycle']  ?? ''),
            'max_students'  => (int)    ($limits['maxStudents']   ?? 0),
            'max_staff'     => (int)    ($limits['maxStaff']      ?? 0),
            'grace_days'    => (int)    ($fsPlan['graceDays']     ?? 7),
            'sort_order'    => (int)    ($fsPlan['sortOrder']     ?? 99),
            'modules'       => $modules,
            'description'   => (string) ($fsPlan['description']   ?? ''),
            'created_at'    => (string) ($fsPlan['createdAt']     ?? ''),
            'created_by'    => (string) ($fsPlan['createdBy']     ?? ''),
            'status'        => (string) ($fsPlan['status']        ?? 'active'),
        ];
    }

    /**
     * @b23a-BRIDGE  deleted-at:B2.8
     * Load b2.billing_harden flag + Billing_integrity if ON.
     */
    private function _bi_active(): bool
    {
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $flags = $this->config->item('b2_migration_flags');
        if (empty($flags['b2.billing_harden'])) return false;
        $this->load->library('billing_integrity');
        $this->billing_integrity->init(
            $this->firebase,
            ['uid' => (string) ($this->sa_id ?? ''), 'role' => 'developer']
        );
        return true;
    }

    /**
     * @b23a-PERMANENT  (algorithm + key shape survive unchanged into B2.7
     *                   Firestore-authoritative writer; only call-site relocates)
     * Server-derived deterministic SHA-256 idempotency key. Optional client
     * nonce is additive — correctness never depends on the client.
     */
    private function _bi_idem_key(string $kind, array $parts): string
    {
        $nonce = (string) ($this->input->post('client_nonce', TRUE) ?? '');
        $row   = array_merge([$kind], array_map('strval', $parts),
                              [(string) ($this->sa_id ?? ''), $nonce]);
        return hash('sha256', implode('|', $row));
    }

    /**
     * @b23a-PERMANENT  (event taxonomy + payload shape survive unchanged;
     *                   only call-site relocates at B2.7)
     * Best-effort fire-and-forget telemetry; never throws into caller.
     */
    private function _bi_telem(string $event, array $detail): void
    {
        try {
            $this->load->library('security_telemetry', null, 'sec_telem_bi');
            if (!$this->sec_telem_bi->isReady()) {
                $this->sec_telem_bi->init(
                    $this->firebase, 'SA_PANEL',
                    ['uid' => (string) ($this->sa_id ?? ''), 'role' => 'developer'],
                    ''
                );
            }
            $subject = ['type' => 'school', 'id' => (string) ($detail['schoolId'] ?? '')];
            $this->sec_telem_bi->emit($event, 'info', $detail, $subject);
        } catch (\Throwable $e) {
            log_message('error', 'B2.3a telemetry failed: ' . $e->getMessage());
        }
    }
}
