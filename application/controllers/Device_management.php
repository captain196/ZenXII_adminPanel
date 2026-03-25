<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Device_management — Admin portal module for managing mobile device bindings.
 *
 * Communicates with the Node.js Auth API via the Auth_client library to
 * list, bind, remove, and block devices for teachers and students.
 * User profile data is read from Firebase RTDB.
 *
 * Access: Super Admin, Admin, Principal
 *
 * Firebase paths:
 *   Teachers:     Users/Admin/{parent_db_key}/{teacherId}/
 *   Students:     Users/Parents/{parent_db_key}/{studentId}/
 *   Staff list:   Schools/{school_name}/{session_year}/Teachers/
 */
class Device_management extends MY_Controller
{
    /** Roles allowed to access this module */
    private const ALLOWED_ROLES = ['Super Admin', 'Admin', 'Principal'];

    /** Maximum devices before flagging as suspicious */
    private const DEVICE_LIMIT = 3;

    /** Days of inactivity before flagging a device as stale */
    private const STALE_DAYS = 30;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('auth_client');
        require_permission('Device Management');
    }

    // =========================================================================
    //  PAGE
    // =========================================================================

    /**
     * Main device management SPA page.
     */
    public function index(): void
    {
        $this->_require_role(self::ALLOWED_ROLES, 'device_management_view');

        $data = [
            'page_title' => 'Device Management',
        ];

        $this->load->view('include/header', $data);
        $this->load->view('device_management/index', $data);
        $this->load->view('include/footer');
    }

    // =========================================================================
    //  AJAX: Dashboard overview
    // =========================================================================

    /**
     * GET overview stats: total users with devices, total bound, blocked, avg per user.
     */
    public function get_overview(): void
    {
        $this->_require_role(self::ALLOWED_ROLES, 'device_overview');

        try {
            $users = $this->_collect_all_users();

            $totalUsersWithDevices = 0;
            $totalBound            = 0;
            $totalBlocked          = 0;
            $distro                = [1 => 0, 2 => 0, '3+' => 0];
            $platformCounts        = ['Android' => 0, 'iOS' => 0, 'Other' => 0];
            $recentActivity        = [];

            foreach ($users as $u) {
                $result = $this->auth_client->list_devices($u['userId']);
                $devices = $result['devices'] ?? [];
                if (!is_array($devices) || empty($devices)) continue;

                $totalUsersWithDevices++;
                $activeCount = 0;

                foreach ($devices as $d) {
                    if (!is_array($d)) continue;
                    $status = strtolower($d['status'] ?? 'active');

                    if ($status === 'blocked') {
                        $totalBlocked++;
                    } else {
                        $totalBound++;
                        $activeCount++;
                    }

                    // Platform
                    $plat = ucfirst(strtolower($d['platform'] ?? 'other'));
                    if (stripos($plat, 'android') !== false) {
                        $platformCounts['Android']++;
                    } elseif (stripos($plat, 'ios') !== false || stripos($plat, 'iphone') !== false) {
                        $platformCounts['iOS']++;
                    } else {
                        $platformCounts['Other']++;
                    }

                    // Recent activity
                    if (!empty($d['boundAt']) || !empty($d['lastUsedAt'])) {
                        $recentActivity[] = [
                            'userId'     => $u['userId'],
                            'userName'   => $u['name'] ?? $u['userId'],
                            'deviceName' => $d['deviceName'] ?? 'Unknown',
                            'action'     => 'bound',
                            'time'       => $d['boundAt'] ?? $d['lastUsedAt'] ?? '',
                        ];
                    }
                }

                // Distribution
                if ($activeCount === 1)     $distro[1]++;
                elseif ($activeCount === 2)  $distro[2]++;
                elseif ($activeCount >= 3)   $distro['3+']++;
            }

            // Sort recent activity newest first, limit to 20
            usort($recentActivity, function ($a, $b) {
                return strcmp($b['time'], $a['time']);
            });
            $recentActivity = array_slice($recentActivity, 0, 20);

            $avg = $totalUsersWithDevices > 0
                ? round($totalBound / $totalUsersWithDevices, 1)
                : 0;

            $this->json_success([
                'totalUsersWithDevices' => $totalUsersWithDevices,
                'totalBound'            => $totalBound,
                'totalBlocked'          => $totalBlocked,
                'avgPerUser'            => $avg,
                'distribution'          => $distro,
                'platforms'             => $platformCounts,
                'recentActivity'        => $recentActivity,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Device_management::get_overview — ' . $e->getMessage());
            $this->json_error('Failed to load device overview.');
        }
    }

    // =========================================================================
    //  AJAX: Search user
    // =========================================================================

    /**
     * POST: Search for a user by ID or name. Returns profile + devices.
     */
    public function search_user(): void
    {
        $this->_require_role(self::ALLOWED_ROLES, 'device_search_user');

        $query = trim($this->input->post('query', TRUE) ?? '');
        if ($query === '') {
            $this->json_error('Search query is required.');
        }

        try {
            $matches = [];

            // Search teachers
            $teachers = $this->firebase->get(
                "Users/Admin/{$this->parent_db_key}"
            );
            if (is_array($teachers)) {
                foreach ($teachers as $tid => $t) {
                    if (!is_array($t)) continue;
                    $name = $t['Name'] ?? $t['name'] ?? '';
                    if (
                        stripos($tid, $query) !== false ||
                        stripos($name, $query) !== false
                    ) {
                        $matches[] = [
                            'userId' => $tid,
                            'name'   => $name,
                            'role'   => 'Teacher',
                            'email'  => $t['Email'] ?? $t['email'] ?? '',
                            'phone'  => $t['Phone'] ?? $t['phone'] ?? '',
                            'status' => $t['Status'] ?? $t['status'] ?? 'active',
                        ];
                    }
                    if (count($matches) >= 20) break;
                }
            }

            // Search students
            $students = $this->firebase->get(
                "Users/Parents/{$this->parent_db_key}"
            );
            if (is_array($students)) {
                foreach ($students as $sid => $s) {
                    if (!is_array($s)) continue;
                    $name = $s['Name'] ?? $s['name'] ?? $s['StudentName'] ?? '';
                    if (
                        stripos($sid, $query) !== false ||
                        stripos($name, $query) !== false
                    ) {
                        $matches[] = [
                            'userId' => $sid,
                            'name'   => $name,
                            'role'   => 'Student',
                            'email'  => $s['Email'] ?? $s['email'] ?? '',
                            'phone'  => $s['Phone'] ?? $s['phone'] ?? $s['ParentPhone'] ?? '',
                            'status' => $s['Status'] ?? $s['status'] ?? 'active',
                        ];
                    }
                    if (count($matches) >= 40) break;
                }
            }

            // For each match, fetch device count (quick)
            foreach ($matches as &$m) {
                $result  = $this->auth_client->list_devices($m['userId']);
                $devices = $result['devices'] ?? [];
                $m['deviceCount'] = is_array($devices) ? count($devices) : 0;
            }
            unset($m);

            $this->json_success(['users' => array_values($matches)]);
        } catch (\Exception $e) {
            log_message('error', 'Device_management::search_user — ' . $e->getMessage());
            $this->json_error('Search failed. Please try again.');
        }
    }

    // =========================================================================
    //  AJAX: List devices for a user
    // =========================================================================

    /**
     * POST: Get all devices for a given userId.
     */
    public function list_devices(): void
    {
        $this->_require_role(self::ALLOWED_ROLES, 'device_list');

        $userId = trim($this->input->post('user_id', TRUE) ?? '');
        if ($userId === '') {
            $this->json_error('User ID is required.');
        }

        try {
            $result  = $this->auth_client->list_devices($userId);

            if (!empty($result['success']) || isset($result['devices'])) {
                $devices = $result['devices'] ?? [];
                $this->json_success(['devices' => is_array($devices) ? array_values($devices) : []]);
            } else {
                $this->json_success(['devices' => []]);
            }
        } catch (\Exception $e) {
            log_message('error', 'Device_management::list_devices — ' . $e->getMessage());
            $this->json_error('Failed to load devices.');
        }
    }

    // =========================================================================
    //  AJAX: Remove device
    // =========================================================================

    /**
     * POST: Remove a device binding.
     */
    public function remove_device(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'device_remove');

        $userId   = trim($this->input->post('user_id', TRUE) ?? '');
        $deviceId = trim($this->input->post('device_id', TRUE) ?? '');

        if ($userId === '' || $deviceId === '') {
            $this->json_error('User ID and Device ID are required.');
        }

        try {
            $result = $this->auth_client->remove_device($userId, $deviceId);

            if (!empty($result['success'])) {
                log_message('info',
                    "Device removed: user={$userId} device={$deviceId}"
                    . " by admin={$this->admin_id} school={$this->school_name}"
                );
                $this->json_success(['message' => 'Device removed successfully.']);
            } else {
                $msg = $result['message'] ?? 'Failed to remove device.';
                $this->json_error($msg);
            }
        } catch (\Exception $e) {
            log_message('error', 'Device_management::remove_device — ' . $e->getMessage());
            $this->json_error('Failed to remove device.');
        }
    }

    // =========================================================================
    //  AJAX: Block device
    // =========================================================================

    /**
     * POST: Block a device (mark as blocked on the Auth API).
     */
    public function block_device(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'device_block');

        $userId   = trim($this->input->post('user_id', TRUE) ?? '');
        $deviceId = trim($this->input->post('device_id', TRUE) ?? '');

        if ($userId === '' || $deviceId === '') {
            $this->json_error('User ID and Device ID are required.');
        }

        try {
            $result = $this->auth_client->block_device($userId, $deviceId);

            if (!empty($result['success'])) {
                log_message('info',
                    "Device blocked: user={$userId} device={$deviceId}"
                    . " by admin={$this->admin_id} school={$this->school_name}"
                );
                $this->json_success(['message' => 'Device blocked successfully.']);
            } else {
                $msg = $result['message'] ?? 'Failed to block device.';
                $this->json_error($msg);
            }
        } catch (\Exception $e) {
            log_message('error', 'Device_management::block_device — ' . $e->getMessage());
            $this->json_error('Failed to block device.');
        }
    }

    // =========================================================================
    //  AJAX: Unblock device (remove + re-add)
    // =========================================================================

    /**
     * POST: Unblock a device by removing the block flag.
     * The Auth API's remove-device endpoint removes the device entirely;
     * we then re-bind it to restore it as active.
     */
    public function unblock_device(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'device_unblock');

        $userId     = trim($this->input->post('user_id', TRUE) ?? '');
        $deviceId   = trim($this->input->post('device_id', TRUE) ?? '');
        $deviceName = trim($this->input->post('device_name', TRUE) ?? 'Unknown');
        $platform   = trim($this->input->post('platform', TRUE) ?? '');
        $os         = trim($this->input->post('os', TRUE) ?? '');
        $appVersion = trim($this->input->post('app_version', TRUE) ?? '');

        if ($userId === '' || $deviceId === '') {
            $this->json_error('User ID and Device ID are required.');
        }

        try {
            // Step 1: Remove the blocked device
            $removeResult = $this->auth_client->remove_device($userId, $deviceId);
            if (empty($removeResult['success']) && ($removeResult['message'] ?? '') !== 'Device not found') {
                $this->json_error($removeResult['message'] ?? 'Failed to remove blocked device.');
            }

            // Step 2: Re-bind as active
            $bindResult = $this->auth_client->bind_device($userId, $deviceId, [
                'deviceName' => $deviceName,
                'platform'   => $platform,
                'os'         => $os,
                'appVersion' => $appVersion,
            ]);

            if (!empty($bindResult['success'])) {
                log_message('info',
                    "Device unblocked: user={$userId} device={$deviceId}"
                    . " by admin={$this->admin_id} school={$this->school_name}"
                );
                $this->json_success(['message' => 'Device unblocked and re-activated.']);
            } else {
                $this->json_error($bindResult['message'] ?? 'Failed to re-bind device after unblock.');
            }
        } catch (\Exception $e) {
            log_message('error', 'Device_management::unblock_device — ' . $e->getMessage());
            $this->json_error('Failed to unblock device.');
        }
    }

    // =========================================================================
    //  AJAX: All users with device counts (Bulk Overview)
    // =========================================================================

    /**
     * Fetch all students + teachers with their device counts.
     * Returns a flat array suitable for a DataTable.
     */
    public function get_all_users_devices(): void
    {
        $this->_require_role(self::ALLOWED_ROLES, 'device_bulk_overview');

        try {
            $users = $this->_collect_all_users();
            $rows  = [];

            foreach ($users as $u) {
                $result  = $this->auth_client->list_devices($u['userId']);
                $devices = $result['devices'] ?? [];
                $devArr  = is_array($devices) ? $devices : [];

                $activeCount  = 0;
                $blockedCount = 0;
                $deviceList   = [];

                foreach ($devArr as $d) {
                    if (!is_array($d)) continue;
                    $status = strtolower($d['status'] ?? 'active');
                    if ($status === 'blocked') {
                        $blockedCount++;
                    } else {
                        $activeCount++;
                    }
                    $deviceList[] = [
                        'deviceId'   => $d['deviceId'] ?? '',
                        'deviceName' => $d['deviceName'] ?? 'Unknown',
                        'platform'   => $d['platform'] ?? '',
                        'os'         => $d['os'] ?? '',
                        'status'     => $d['status'] ?? 'active',
                        'boundAt'    => $d['boundAt'] ?? '',
                        'lastUsedAt' => $d['lastUsedAt'] ?? '',
                        'appVersion' => $d['appVersion'] ?? '',
                    ];
                }

                $status = 'ok';
                if ($blockedCount > 0) $status = 'has_blocked';
                if ($activeCount > self::DEVICE_LIMIT) $status = 'over_limit';

                $rows[] = [
                    'userId'       => $u['userId'],
                    'name'         => $u['name'],
                    'role'         => $u['role'],
                    'deviceCount'  => $activeCount,
                    'blockedCount' => $blockedCount,
                    'status'       => $status,
                    'devices'      => $deviceList,
                ];
            }

            $this->json_success(['users' => $rows]);
        } catch (\Exception $e) {
            log_message('error', 'Device_management::get_all_users_devices — ' . $e->getMessage());
            $this->json_error('Failed to load bulk device data.');
        }
    }

    // =========================================================================
    //  AJAX: Security alerts / suspicious activity
    // =========================================================================

    /**
     * Find users with blocked devices, over-limit devices, stale devices,
     * or rapid device binding (multiple binds within a short window).
     */
    public function get_suspicious_activity(): void
    {
        $this->_require_role(self::ALLOWED_ROLES, 'device_security_alerts');

        try {
            $users  = $this->_collect_all_users();
            $alerts = [
                'blocked_devices' => [],
                'over_limit'      => [],
                'stale_devices'   => [],
                'rapid_binding'   => [],
            ];

            $nowTs = time();

            foreach ($users as $u) {
                $result  = $this->auth_client->list_devices($u['userId']);
                $devices = $result['devices'] ?? [];
                if (!is_array($devices) || empty($devices)) continue;

                $activeCount  = 0;
                $blockedList  = [];
                $staleList    = [];
                $bindTimes    = [];

                foreach ($devices as $d) {
                    if (!is_array($d)) continue;
                    $status = strtolower($d['status'] ?? 'active');

                    if ($status === 'blocked') {
                        $blockedList[] = [
                            'deviceId'   => $d['deviceId'] ?? '',
                            'deviceName' => $d['deviceName'] ?? 'Unknown',
                            'blockedAt'  => $d['blockedAt'] ?? $d['lastUsedAt'] ?? '',
                        ];
                    } else {
                        $activeCount++;
                    }

                    // Check stale
                    $lastUsed = $d['lastUsedAt'] ?? $d['boundAt'] ?? '';
                    if ($lastUsed !== '') {
                        $lastTs = strtotime($lastUsed);
                        if ($lastTs && ($nowTs - $lastTs) > (self::STALE_DAYS * 86400)) {
                            $staleList[] = [
                                'deviceId'   => $d['deviceId'] ?? '',
                                'deviceName' => $d['deviceName'] ?? 'Unknown',
                                'lastUsedAt' => $lastUsed,
                                'daysIdle'   => (int) floor(($nowTs - $lastTs) / 86400),
                            ];
                        }
                    }

                    // Collect bind times for rapid-binding detection
                    $boundAt = $d['boundAt'] ?? '';
                    if ($boundAt !== '') {
                        $bt = strtotime($boundAt);
                        if ($bt) $bindTimes[] = $bt;
                    }
                }

                // Alert: blocked devices
                if (!empty($blockedList)) {
                    $alerts['blocked_devices'][] = [
                        'userId'  => $u['userId'],
                        'name'    => $u['name'],
                        'role'    => $u['role'],
                        'devices' => $blockedList,
                    ];
                }

                // Alert: over device limit
                if ($activeCount > self::DEVICE_LIMIT) {
                    $alerts['over_limit'][] = [
                        'userId'      => $u['userId'],
                        'name'        => $u['name'],
                        'role'        => $u['role'],
                        'activeCount' => $activeCount,
                        'limit'       => self::DEVICE_LIMIT,
                    ];
                }

                // Alert: stale devices
                if (!empty($staleList)) {
                    $alerts['stale_devices'][] = [
                        'userId'  => $u['userId'],
                        'name'    => $u['name'],
                        'role'    => $u['role'],
                        'devices' => $staleList,
                    ];
                }

                // Alert: rapid binding (3+ devices bound within 24 hours)
                if (count($bindTimes) >= 3) {
                    sort($bindTimes);
                    for ($i = 0; $i <= count($bindTimes) - 3; $i++) {
                        if (($bindTimes[$i + 2] - $bindTimes[$i]) < 86400) {
                            $alerts['rapid_binding'][] = [
                                'userId'      => $u['userId'],
                                'name'        => $u['name'],
                                'role'        => $u['role'],
                                'devicesBound' => count($bindTimes),
                                'windowHours' => round(($bindTimes[$i + 2] - $bindTimes[$i]) / 3600, 1),
                            ];
                            break; // one alert per user is enough
                        }
                    }
                }
            }

            $this->json_success(['alerts' => $alerts]);
        } catch (\Exception $e) {
            log_message('error', 'Device_management::get_suspicious_activity — ' . $e->getMessage());
            $this->json_error('Failed to load security alerts.');
        }
    }

    // =========================================================================
    //  AJAX: Bulk remove all devices for a user
    // =========================================================================

    /**
     * POST: Remove all devices bound to a user.
     * Fetches device list, then removes each one.
     */
    public function bulk_remove(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'device_bulk_remove');

        $userId = trim($this->input->post('user_id', TRUE) ?? '');
        if ($userId === '') {
            $this->json_error('User ID is required.');
        }

        try {
            $result  = $this->auth_client->list_devices($userId);
            $devices = $result['devices'] ?? [];

            if (!is_array($devices) || empty($devices)) {
                $this->json_success(['message' => 'No devices to remove.', 'removed' => 0]);
                return;
            }

            $removed = 0;
            $errors  = 0;

            foreach ($devices as $d) {
                if (!is_array($d) || empty($d['deviceId'])) continue;
                $r = $this->auth_client->remove_device($userId, $d['deviceId']);
                if (!empty($r['success'])) {
                    $removed++;
                } else {
                    $errors++;
                }
            }

            log_message('info',
                "Bulk device removal: user={$userId} removed={$removed} errors={$errors}"
                . " by admin={$this->admin_id} school={$this->school_name}"
            );

            if ($errors > 0) {
                $this->json_success([
                    'message' => "Removed {$removed} device(s). {$errors} failed.",
                    'removed' => $removed,
                    'errors'  => $errors,
                ]);
            } else {
                $this->json_success([
                    'message' => "All {$removed} device(s) removed successfully.",
                    'removed' => $removed,
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', 'Device_management::bulk_remove — ' . $e->getMessage());
            $this->json_error('Failed to remove devices.');
        }
    }

    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /**
     * Collect all teacher + student user IDs and names from Firebase.
     * Returns a flat array of ['userId'=>..., 'name'=>..., 'role'=>...].
     */
    private function _collect_all_users(): array
    {
        $users = [];

        // Teachers from Users/Admin/{parent_db_key}
        $teachers = $this->firebase->get("Users/Admin/{$this->parent_db_key}");
        if (is_array($teachers)) {
            foreach ($teachers as $tid => $t) {
                if (!is_array($t)) continue;
                $users[] = [
                    'userId' => $tid,
                    'name'   => $t['Name'] ?? $t['name'] ?? $tid,
                    'role'   => 'Teacher',
                ];
            }
        }

        // Students from Users/Parents/{parent_db_key}
        $students = $this->firebase->get("Users/Parents/{$this->parent_db_key}");
        if (is_array($students)) {
            foreach ($students as $sid => $s) {
                if (!is_array($s)) continue;
                $users[] = [
                    'userId' => $sid,
                    'name'   => $s['Name'] ?? $s['name'] ?? $s['StudentName'] ?? $sid,
                    'role'   => 'Student',
                ];
            }
        }

        return $users;
    }
}
