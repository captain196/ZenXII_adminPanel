<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_plans
 * Subscription plan management: create, update, delete, assign modules per plan.
 */
class Superadmin_plans extends MY_Superadmin_Controller
{
    // All available modules the SA can toggle per plan
    const AVAILABLE_MODULES = [
        'student_management' => 'Student Management',
        'staff_management'   => 'Staff Management',
        'fees'               => 'Fees Collection',
        'accounts'           => 'Accounts & Ledger',
        'exams'              => 'Exam Management',
        'results'            => 'Result Management',
        'attendance'         => 'Attendance',
        'homework'           => 'Homework',
        'notices'            => 'Notices & Announcements',
        'gallery'            => 'School Gallery',
        'timetable'          => 'Timetable',
        'id_cards'           => 'ID Cards',
        'sms_alerts'         => 'SMS Alerts',
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
        try {
            $raw     = $this->firebase->get('System/Plans') ?? [];
            $schools = $this->firebase->get('System/Schools') ?? [];

            foreach ($raw as $pid => $p) {
                // Count schools on this plan (check System/Schools subscription)
                $school_count = 0;
                foreach ($schools as $s) {
                    if (!is_array($s)) continue;
                    $sub = is_array($s['subscription'] ?? null) ? $s['subscription'] : [];
                    if (($sub['plan_id'] ?? '') === $pid) $school_count++;
                }
                $plans[] = array_merge(['plan_id' => $pid, 'school_count' => $school_count], $p);
            }
            usort($plans, fn($a, $b) => ($a['sort_order'] ?? 99) - ($b['sort_order'] ?? 99));
        } catch (Exception $e) {
            log_message('error', 'SA plans/index: ' . $e->getMessage());
        }

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

        try {
            $this->firebase->set("System/Plans/{$plan_id}", [
                'name'         => $name,
                'price'        => $price,
                'billing_cycle'=> $billing,
                'max_students' => $max_students,
                'max_staff'    => $max_staff,
                'grace_days'   => $grace_days,
                'sort_order'   => $sort_order,
                'modules'      => $modules,
                'created_at'   => date('Y-m-d H:i:s'),
                'created_by'   => $this->sa_id,
            ]);

            $this->sa_log('plan_created', '', ['plan_id' => $plan_id, 'name' => $name]);
            $this->json_success(['plan_id' => $plan_id, 'message' => "Plan '{$name}' created."]);
        } catch (Exception $e) {
            log_message('error', 'SA plans/create: ' . $e->getMessage());
            $this->json_error('Failed to create plan.');
        }
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

        $update = [
            'name'        => $name,
            'price'       => $price,
            'grace_days'  => $grace_days,
            'modules'     => $modules,
            'updated_at'  => date('Y-m-d H:i:s'),
            'updated_by'  => $this->sa_id,
        ];
        if ($billing !== '' && in_array($billing, ['monthly', 'quarterly', 'annual'])) {
            $update['billing_cycle'] = $billing;
        }
        if ($max_students !== null) $update['max_students'] = (int)$max_students;
        if ($max_staff    !== null) $update['max_staff']    = (int)$max_staff;
        if ($sort_order   !== null) $update['sort_order']   = (int)$sort_order;

        try {
            $this->firebase->update("System/Plans/{$plan_id}", $update);
            $this->sa_log('plan_updated', '', ['plan_id' => $plan_id]);
            $this->json_success(['message' => "Plan '{$name}' updated."]);
        } catch (Exception $e) {
            $this->json_error('Failed to update plan.');
        }
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

        // Safety: refuse if schools are on this plan
        try {
            $schools = $this->firebase->get('System/Schools') ?? [];
            foreach ($schools as $s) {
                if (!is_array($s)) continue;
                $sub = is_array($s['subscription'] ?? null) ? $s['subscription'] : [];
                if (($sub['plan_id'] ?? '') === $plan_id) {
                    $this->json_error('Cannot delete: one or more schools are on this plan. Reassign them first.');
                    return;
                }
            }
            $this->firebase->delete("System/Plans", $plan_id);
            $this->sa_log('plan_deleted', '', ['plan_id' => $plan_id]);
            $this->json_success(['message' => 'Plan deleted.']);
        } catch (Exception $e) {
            $this->json_error('Failed to delete plan.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/fetch
    // Returns single plan data for edit modal
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch()
    {
        $plan_id = trim($this->input->post('plan_id', TRUE) ?? '');

        try {
            if ($plan_id !== '') {
                if (!preg_match('/^PLAN_[A-Z0-9]+$/', $plan_id)) {
                    $this->json_error('Invalid plan ID format.'); return;
                }
                // Fetch a single plan
                $plan = $this->firebase->get("System/Plans/{$plan_id}") ?? [];
                $this->json_success(['plan' => $plan, 'plans' => [$plan_id => $plan]]);
            } else {
                // No plan_id — return all plans
                $raw   = $this->firebase->get('System/Plans') ?? [];
                $plans = [];
                foreach ($raw as $pid => $p) {
                    if (is_array($p)) $plans[$pid] = $p;
                }
                $this->json_success(['plans' => $plans, 'total' => count($plans)]);
            }
        } catch (Exception $e) {
            $this->json_error('Failed to fetch plans.');
        }
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

        try {
            $existing      = $this->firebase->get('System/Plans') ?? [];
            $existingNames = array_map(fn($p) => strtolower($p['name'] ?? ''), array_filter((array)$existing, 'is_array'));

            foreach ($defaults as $planName => $config) {
                if (in_array(strtolower($planName), $existingNames)) continue;

                $plan_id = 'PLAN_' . strtoupper(substr(md5(uniqid($planName, true)), 0, 6));
                $this->firebase->set("System/Plans/{$plan_id}", array_merge($config, [
                    'name'       => $planName,
                    'plan_id'    => $plan_id,
                    'created_at' => $now,
                    'created_by' => $this->sa_id,
                ]));
                $seeded[] = $planName;
            }

            if (empty($seeded)) {
                $this->json_success(['message' => 'Default plans already exist — no changes made.', 'seeded' => []]);
            } else {
                $this->sa_log('plans_seeded', '', ['plans' => $seeded]);
                $this->json_success([
                    'message' => 'Created: ' . implode(', ', $seeded) . '.',
                    'seeded'  => $seeded,
                ]);
            }
        } catch (Exception $e) {
            $this->json_error('Failed to seed plans: ' . $e->getMessage());
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
        try {
            $schools = $this->firebase->get('System/Schools') ?? [];
            $today   = date('Y-m-d');
            $rows    = [];

            foreach ($schools as $name => $school) {
                if (!is_array($school)) continue;

                $sub      = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                $saP      = is_array($school['profile']       ?? null) ? $school['profile']      : [];
                $expiry   = $sub['expiry_date'] ?? ($sub['duration']['endDate'] ?? '');
                $grace_end= $sub['grace_end']   ?? '';
                $status      = $sub['status'] ?? 'Inactive';
                $statusLower = strtolower($status);

                // Compute display classification
                if ($statusLower === 'suspended') {
                    $display = 'suspended';
                } elseif ($statusLower === 'grace_period') {
                    $display = 'grace';
                } elseif (empty($expiry)) {
                    $display = 'inactive';
                } elseif ($expiry < $today) {
                    $display = (!empty($grace_end) && $grace_end >= $today) ? 'grace' : 'expired';
                } elseif ((int)ceil((strtotime($expiry) - time()) / 86400) <= 30) {
                    $display = 'expiring_soon';
                } else {
                    $display = 'active';
                }

                $rows[] = [
                    'uid'          => $name,
                    'name'         => $saP['name']      ?? $name,
                    'school_code'  => $saP['school_code'] ?? '',
                    'plan_name'    => $sub['plan_name']  ?? '—',
                    'expiry_date'  => $expiry,
                    'grace_end'    => $grace_end,
                    'sub_status'   => $status,
                    'display'      => $display,
                    'days_left'    => $expiry ? (int)ceil((strtotime($expiry) - time()) / 86400) : null,
                    'grace_left'   => $grace_end ? (int)ceil((strtotime($grace_end) - time()) / 86400) : null,
                    'last_payment' => $sub['last_payment_date'] ?? '',
                ];
            }

            // Sort: soonest expiry first; null (inactive) at end
            usort($rows, function ($a, $b) {
                if ($a['days_left'] === null) return 1;
                if ($b['days_left'] === null) return -1;
                return $a['days_left'] - $b['days_left'];
            });

            // Build bucketed counts for dashboard/tests
            $buckets = ['active' => 0, 'expiring_soon' => 0, 'grace' => 0, 'expired' => 0, 'suspended' => 0, 'inactive' => 0];
            foreach ($rows as $r) {
                $d = $r['display'] ?? 'inactive';
                $buckets[$d] = ($buckets[$d] ?? 0) + 1;
            }

            $this->json_success(array_merge(['rows' => $rows], $buckets));
        } catch (Exception $e) {
            $this->json_error('Failed to load subscriptions.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/plans/expire_check
    // Scan all schools; move past-expiry → Grace_Period or Suspended.
    // Safe to call repeatedly — idempotent per school.
    // ─────────────────────────────────────────────────────────────────────────

    public function expire_check()
    {
        try {
            $schools   = $this->firebase->get('System/Schools') ?? [];
            $today     = date('Y-m-d');
            $suspended = [];
            $graced    = [];

            foreach ($schools as $name => $school) {
                if (!is_array($school)) continue;
                if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $name)) continue;

                $sub      = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                $status   = $sub['status'] ?? 'Inactive';
                $expiry   = $sub['expiry_date']  ?? ($sub['duration']['endDate'] ?? '');
                $grace_end= $sub['grace_end']    ?? '';

                if (empty($expiry)) continue;

                // Normalise legacy lowercase statuses for comparison
                $statusLower = strtolower($status);

                if ($statusLower === 'active' && $expiry < $today) {
                    if (!empty($grace_end) && $grace_end >= $today) {
                        // Move to grace period — reduce access but not yet suspended
                        $this->firebase->update("System/Schools/{$name}/subscription", ['status' => 'Grace_Period']);
                        $this->firebase->update("System/Schools/{$name}/profile",      ['status' => 'grace_period']);
                        $graced[]    = $name;
                        $this->sa_log('auto_grace', $name);
                    } else {
                        // Fully suspend — top-level status gates all SA reads
                        $this->firebase->update("System/Schools/{$name}",             ['status' => 'suspended']);
                        $this->firebase->update("System/Schools/{$name}/subscription", ['status' => 'Suspended']);
                        $this->firebase->update("System/Schools/{$name}/profile",      ['status' => 'suspended']);
                        $suspended[] = $name;
                        $this->sa_log('auto_suspended', $name);
                    }
                } elseif ($statusLower === 'grace_period' && !empty($grace_end) && $grace_end < $today) {
                    // Grace period ended — fully suspend
                    $this->firebase->update("System/Schools/{$name}",             ['status' => 'suspended']);
                    $this->firebase->update("System/Schools/{$name}/subscription", ['status' => 'Suspended']);
                    $this->firebase->update("System/Schools/{$name}/profile",      ['status' => 'suspended']);
                    $suspended[] = $name;
                    $this->sa_log('auto_suspended', $name);
                }
            }

            $this->json_success([
                'suspended'       => $suspended,
                'suspended_count' => count($suspended),
                'graced'          => $graced,
                'graced_count'    => count($graced),
                'message'         => sprintf(
                    'Check complete. %d suspended, %d moved to grace period.',
                    count($suspended), count($graced)
                ),
            ]);
        } catch (Exception $e) {
            $this->json_error('Expire check failed: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PAYMENT / INVOICE MANAGEMENT
    //
    //  Data model (System/Payments/{INV_ID}):
    //    amount       – total invoice amount for the billing period
    //    amount_paid  – sum of all collections against this invoice
    //    balance      – amount − amount_paid  (auto-computed)
    //    status       – pending | partial | paid | overdue | failed
    //    transactions – { TXN_ID: {date,amount,mode,note,recorded_by,recorded_at} }
    //    + school_uid, plan_id, plan_name, billing_cycle, invoice_date,
    //      due_date, period_start, period_end, notes, created_*
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Migrate a legacy flat record to the new invoice model.
     * Called lazily on read – adds amount_paid, balance, transactions if missing.
     */
    private function _migrate_payment(string $pid, array &$p): void
    {
        if (isset($p['balance'])) return; // already migrated

        $amount      = (float)($p['amount'] ?? 0);
        $status      = $p['status'] ?? 'pending';
        $amount_paid = ($status === 'paid') ? $amount : 0.0;
        $balance     = $amount - $amount_paid;
        $txns        = [];

        if ($status === 'paid') {
            $txnId = 'TXN_' . strtoupper(substr(md5($pid . 'legacy'), 0, 8));
            $txns[$txnId] = [
                'date'        => $p['paid_date'] ?? ($p['created_at'] ?? date('Y-m-d')),
                'amount'      => $amount,
                'mode'        => '—',
                'note'        => 'Migrated from legacy record',
                'recorded_by' => 'system',
                'recorded_at' => date('Y-m-d H:i:s'),
            ];
        }

        $patch = [
            'amount_paid'  => $amount_paid,
            'balance'      => $balance,
            'transactions' => !empty($txns) ? $txns : null,
        ];

        // Derive correct status
        if ($status !== 'failed') {
            $patch['status'] = $this->_derive_status($amount, $amount_paid, $p['due_date'] ?? '');
        }

        $this->firebase->update("System/Payments/{$pid}", $patch);

        // Merge into local array so caller sees updated values
        $p['amount_paid']  = $amount_paid;
        $p['balance']      = $balance;
        $p['status']       = $patch['status'] ?? $status;
        if (!empty($txns)) $p['transactions'] = $txns;
    }

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
     * Sync school subscription when an invoice is fully paid.
     */
    private function _sync_school_sub(string $school_uid, array $invoice, string $paid_date): void
    {
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) return;

        $subUpdate = [
            'last_payment_date'   => $paid_date,
            'last_payment_amount' => (float)($invoice['amount'] ?? 0),
        ];

        $periodEnd = $invoice['period_end'] ?? '';
        if ($periodEnd) {
            $planInfo = [];
            try { $planInfo = $this->firebase->get("System/Plans/" . ($invoice['plan_id'] ?? '')) ?? []; } catch (Exception $e) {}
            $grace = (int)($planInfo['grace_days'] ?? 7);
            $subUpdate['expiry_date'] = $periodEnd;
            $subUpdate['grace_end']   = date('Y-m-d', strtotime($periodEnd . " +{$grace} days"));
            $subUpdate['status']      = 'Active';
        }

        $this->firebase->update("System/Schools/{$school_uid}/subscription", $subUpdate);
        $this->firebase->update("System/Schools/{$school_uid}", ['status' => 'active']);
        if ($periodEnd) {
            $this->firebase->update("System/Schools/{$school_uid}/profile", ['status' => 'active']);
        }
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
        try {
            $raw = $this->firebase->get('System/Schools') ?? [];
            foreach ($raw as $name => $school) {
                if (!is_array($school)) continue;
                $saP = is_array($school['profile']      ?? null) ? $school['profile']      : [];
                $sub = is_array($school['subscription'] ?? null) ? $school['subscription'] : [];
                $schools[$name] = [
                    'name'        => $saP['name']      ?? $name,
                    'plan_name'   => $sub['plan_name'] ?? '—',
                    'school_code' => $saP['school_code'] ?? '',
                ];
            }
            $rawPlans = $this->firebase->get('System/Plans') ?? [];
            foreach ($rawPlans as $pid => $p) {
                $plans[$pid] = [
                    'name'          => $p['name']  ?? $pid,
                    'price'         => (float)($p['price'] ?? 0),
                    'billing_cycle' => $p['billing_cycle'] ?? 'annual',
                ];
            }
        } catch (Exception $e) {}

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
        try {
            $raw     = $this->firebase->get('System/Payments') ?? [];
            $schools = $this->firebase->get('System/Schools')  ?? [];
            $today   = date('Y-m-d');
            $rows    = [];

            // Build school name lookup
            $schoolNames = [];
            foreach ($schools as $uid => $s) {
                if (!is_array($s)) continue;
                $prof = is_array($s['profile'] ?? null) ? $s['profile'] : [];
                $schoolNames[$uid] = $prof['name'] ?? $uid;
            }

            foreach ($raw as $pid => $p) {
                if (!is_array($p)) continue;

                // Lazy migration of legacy flat records
                $this->_migrate_payment($pid, $p);

                // Auto-mark overdue: (pending|partial) + due_date in the past
                $status   = $p['status'] ?? 'pending';
                $due_date = $p['due_date'] ?? '';
                if (in_array($status, ['pending', 'partial']) && $due_date && $due_date < $today) {
                    $this->firebase->update("System/Payments/{$pid}", [
                        'status'     => 'overdue',
                        'updated_at' => date('Y-m-d H:i:s'),
                        'updated_by' => 'system_auto',
                    ]);
                    $p['status'] = 'overdue';
                }

                // Compute days until due / days overdue
                $days_due = null;
                if ($due_date) {
                    $days_due = (int) round((strtotime($due_date) - strtotime($today)) / 86400);
                }

                $p['payment_id']  = $pid;
                $p['school_name'] = $schoolNames[$p['school_uid'] ?? ''] ?? ($p['school_uid'] ?? '—');
                $p['days_due']    = $days_due;
                $rows[] = $p;
            }

            usort($rows, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            $this->json_success(['rows' => $rows]);
        } catch (Exception $e) {
            $this->json_error('Failed to load payments.');
        }
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

        try {
            $sub       = $this->firebase->get("System/Schools/{$school_uid}/subscription") ?? [];
            $plan_id   = $sub['plan_id']   ?? '';
            $plan_name = $sub['plan_name'] ?? '';
            $price     = 0;
            $plan_data = [];

            if ($plan_id) {
                $plan_data = $this->firebase->get("System/Plans/{$plan_id}") ?? [];
                $plan_name = $plan_data['name']          ?? $plan_name;
                $price     = (float)($plan_data['price'] ?? 0);
            }

            $billing_cycle = $plan_data['billing_cycle'] ?? ($sub['billing_cycle'] ?? 'annual');
            $allPayments   = $this->firebase->get('System/Payments') ?? [];

            // Compute outstanding balance across all unpaid invoices
            $outstanding_balance = 0;
            $outstanding_id      = '';
            foreach ($allPayments as $payId => $pay) {
                if (!is_array($pay)) continue;
                if (($pay['school_uid'] ?? '') !== $school_uid) continue;
                $st = $pay['status'] ?? '';
                if (in_array($st, ['pending', 'partial', 'overdue'])) {
                    $bal = isset($pay['balance']) ? (float)$pay['balance'] : ((float)($pay['amount'] ?? 0) - (float)($pay['amount_paid'] ?? 0));
                    $outstanding_balance += $bal;
                    if (!$outstanding_id) $outstanding_id = $payId;
                }
            }

            $next = $this->_next_billing_period($school_uid, $sub, $plan_data, $allPayments);

            $this->json_success([
                'plan_id'             => $plan_id,
                'plan_name'           => $plan_name,
                'price'               => $price,
                'billing_cycle'       => $billing_cycle,
                'expiry_date'         => $sub['expiry_date'] ?? '',
                'sub_status'          => $sub['status'] ?? 'Inactive',
                'last_paid_date'      => $next['last_paid_date'],
                'last_period_end'     => $next['last_period_end'],
                'next_due_date'       => $next['due_date'],
                'next_period_start'   => $next['period_start'],
                'next_period_end'     => $next['period_end'],
                'cycle_months'        => $next['cycle_months'],
                'grace_days'          => (int)($plan_data['grace_days'] ?? 7),
                'outstanding_balance' => $outstanding_balance,
                'outstanding_id'      => $outstanding_id,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to fetch school plan.');
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

        try {
            $sub     = $this->firebase->get("System/Schools/{$school_uid}/subscription") ?? [];
            $plan_id = $sub['plan_id'] ?? '';
            if (empty($plan_id)) { $this->json_error('No plan assigned to this school.'); return; }

            $plan_data     = $this->firebase->get("System/Plans/{$plan_id}") ?? [];
            $billing_cycle = $plan_data['billing_cycle'] ?? 'annual';
            $price         = (float)($plan_data['price'] ?? 0);
            if ($price <= 0) { $this->json_error('Plan has no price set.'); return; }

            $allPayments = $this->firebase->get('System/Payments') ?? [];

            // Block if there's an existing unpaid invoice with balance remaining
            foreach ($allPayments as $payId => $pay) {
                if (!is_array($pay)) continue;
                if (($pay['school_uid'] ?? '') !== $school_uid) continue;
                $st = $pay['status'] ?? '';
                if (in_array($st, ['pending', 'partial', 'overdue'])) {
                    $bal = isset($pay['balance']) ? (float)$pay['balance'] : ((float)($pay['amount'] ?? 0) - (float)($pay['amount_paid'] ?? 0));
                    if ($bal > 0) {
                        $this->json_error("Outstanding invoice {$payId} has ₹" . number_format($bal, 2) . " remaining. Collect or write off before generating a new invoice.");
                        return;
                    }
                }
            }

            $next       = $this->_next_billing_period($school_uid, $sub, $plan_data, $allPayments);
            $payment_id = 'INV_' . strtoupper(substr(md5(uniqid($school_uid, true)), 0, 8));
            $now        = date('Y-m-d H:i:s');

            $this->firebase->set("System/Payments/{$payment_id}", [
                'school_uid'    => $school_uid,
                'amount'        => $price,
                'amount_paid'   => 0,
                'balance'       => $price,
                'plan_id'       => $plan_id,
                'plan_name'     => $plan_data['name'] ?? $plan_id,
                'billing_cycle' => $billing_cycle,
                'status'        => 'pending',
                'invoice_date'  => date('Y-m-d'),
                'due_date'      => $next['due_date'],
                'paid_date'     => '',
                'period_start'  => $next['period_start'],
                'period_end'    => $next['period_end'],
                'transactions'  => null,
                'notes'         => 'Auto-generated invoice',
                'created_by'    => $this->sa_id,
                'created_at'    => $now,
            ]);

            $this->sa_log('invoice_generated', $school_uid, ['payment_id' => $payment_id, 'amount' => $price]);
            $this->json_success([
                'payment_id' => $payment_id,
                'due_date'   => $next['due_date'],
                'amount'     => $price,
                'message'    => "Invoice {$payment_id} created — ₹" . number_format($price, 2)
                              . " due " . $next['due_date'],
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to generate invoice.');
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

        try {
            $inv = $this->firebase->get("System/Payments/{$invoice_id}");
            if (empty($inv) || !is_array($inv)) {
                $this->json_error('Invoice not found.'); return;
            }

            // Migrate legacy records if needed
            $this->_migrate_payment($invoice_id, $inv);

            $amount      = (float)($inv['amount']      ?? 0);
            $amount_paid = (float)($inv['amount_paid']  ?? 0);
            $balance     = (float)($inv['balance']      ?? ($amount - $amount_paid));

            if ($balance <= 0) {
                $this->json_error('This invoice is already fully paid.'); return;
            }

            // Cap payment at balance (no overpayment on a single invoice)
            $actual_pay = min($pay_amount, $balance);
            $new_paid   = $amount_paid + $actual_pay;
            $new_bal    = $amount - $new_paid;
            $new_status = $this->_derive_status($amount, $new_paid, $inv['due_date'] ?? '');

            // Create transaction record
            $txnId = 'TXN_' . strtoupper(substr(md5(uniqid($invoice_id, true)), 0, 8));
            $now   = date('Y-m-d H:i:s');

            $update = [
                'amount_paid'           => $new_paid,
                'balance'               => $new_bal,
                'status'                => $new_status,
                'updated_at'            => $now,
                'updated_by'            => $this->sa_id,
                "transactions/{$txnId}" => [
                    'date'        => $pay_date,
                    'amount'      => $actual_pay,
                    'mode'        => $pay_mode,
                    'note'        => $pay_note,
                    'recorded_by' => $this->sa_id,
                    'recorded_at' => $now,
                ],
            ];

            // If fully paid, set paid_date
            if ($new_status === 'paid') {
                $update['paid_date'] = $pay_date;
            }

            $this->firebase->update("System/Payments/{$invoice_id}", $update);

            // If fully paid, sync school subscription
            if ($new_status === 'paid') {
                $this->_sync_school_sub($inv['school_uid'] ?? '', $inv, $pay_date);
            }

            $this->sa_log('payment_collected', $inv['school_uid'] ?? '', [
                'invoice_id' => $invoice_id, 'txn_id' => $txnId, 'amount' => $actual_pay,
            ]);

            $msg = "₹" . number_format($actual_pay, 2) . " collected against {$invoice_id}.";
            if ($new_bal > 0) {
                $msg .= " Balance remaining: ₹" . number_format($new_bal, 2);
            } else {
                $msg .= " Invoice fully paid!";
            }

            $this->json_success([
                'txn_id'      => $txnId,
                'amount_paid' => $actual_pay,
                'new_balance' => $new_bal,
                'new_status'  => $new_status,
                'message'     => $msg,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to record payment.');
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

        $plan_data = [];
        try { $plan_data = $this->firebase->get("System/Plans/{$plan_id}") ?? []; } catch (Exception $e) {}

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

        try {
            $this->firebase->set("System/Payments/{$payment_id}", [
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
                'created_by'    => $this->sa_id,
                'created_at'    => $now,
            ]);

            if ($status === 'paid') {
                $this->_sync_school_sub($school_uid, [
                    'amount' => $amount, 'period_end' => $period_end, 'plan_id' => $plan_id,
                ], $paid_date ?: date('Y-m-d'));
            }

            $this->sa_log('payment_added', $school_uid, ['payment_id' => $payment_id, 'amount' => $amount]);
            $this->json_success(['payment_id' => $payment_id, 'message' => 'Invoice created.']);
        } catch (Exception $e) {
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

        try {
            $existing = $this->firebase->get("System/Payments/{$payment_id}");
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

            $this->firebase->update("System/Payments/{$payment_id}", $update);

            if ($status === 'paid') {
                $this->_sync_school_sub(
                    $existing['school_uid'] ?? '',
                    $existing,
                    $update['paid_date']
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

        try {
            $existing = $this->firebase->get("System/Payments/{$payment_id}");
            if (empty($existing)) { $this->json_error('Invoice not found.'); return; }

            $this->firebase->delete('System/Payments', $payment_id);
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

        try {
            $allPayments = $this->firebase->get('System/Payments') ?? [];
            $sub         = $this->firebase->get("System/Schools/{$school_uid}/subscription") ?? [];
            $plan_id     = $sub['plan_id'] ?? '';
            $plan_data   = [];
            if ($plan_id) {
                $plan_data = $this->firebase->get("System/Plans/{$plan_id}") ?? [];
            }

            $rows     = [];
            $totalPaid     = 0;
            $totalBilled   = 0;
            $totalBalance  = 0;

            foreach ($allPayments as $pid => $p) {
                if (!is_array($p)) continue;
                if (($p['school_uid'] ?? '') !== $school_uid) continue;
                $this->_migrate_payment($pid, $p);
                $p['payment_id'] = $pid;
                $rows[] = $p;

                $totalBilled  += (float)($p['amount']      ?? 0);
                $totalPaid    += (float)($p['amount_paid']  ?? 0);
                $totalBalance += (float)($p['balance']      ?? 0);
            }

            usort($rows, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

            $this->json_success([
                'rows'          => $rows,
                'total_billed'  => $totalBilled,
                'total_paid'    => $totalPaid,
                'total_balance' => $totalBalance,
                'plan_name'     => $plan_data['name'] ?? ($sub['plan_name'] ?? '—'),
                'billing_cycle' => $plan_data['billing_cycle'] ?? ($sub['billing_cycle'] ?? '—'),
                'expiry_date'   => $sub['expiry_date'] ?? '',
                'sub_status'    => $sub['status'] ?? 'Inactive',
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to fetch school payments.');
        }
    }
}
