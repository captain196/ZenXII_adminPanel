<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Device_service — Mobile device management via Firebase RTDB.
 *
 * Replaces Node.js Auth API device binding endpoints.
 * Stores device info at: Users/Devices/{userId}/{deviceId}/
 *
 * Each device record:
 *   platform    : "android" | "ios" | "web"
 *   deviceName  : Human-readable name (e.g. "Samsung Galaxy S23")
 *   appVersion  : App version string
 *   os          : OS version string
 *   boundAt     : ISO 8601 timestamp
 *   lastActive  : ISO 8601 timestamp
 *   status      : "active" | "blocked"
 *   fcmToken    : Firebase Cloud Messaging token (optional)
 */
class Device_service
{
    /** @var object Firebase library */
    private $firebase;

    /** @var Firestore_service|null Firestore service (loaded lazily via CI). */
    private $fs = null;

    /** Max devices per user */
    private const MAX_DEVICES = 5;

    /** RTDB base path (mirror only — Firestore is canonical per the Firestore-first contract). */
    private const BASE_PATH = 'Users/Devices';

    /** Firestore canonical collection for user devices. */
    private const FS_COLLECTION = 'userDevices';

    public function __construct()
    {
        $CI =& get_instance();
        if (!isset($CI->firebase)) {
            $CI->load->library('firebase');
        }
        $this->firebase = $CI->firebase;

        // Firestore_service is autoloaded as $this->fs in MY_Controller, but
        // libraries don't inherit that — pull it from the controller.
        if (isset($CI->fs)) {
            $this->fs = $CI->fs;
        }
    }

    /**
     * List all devices for a user.
     *
     * @return array  Array of device records, keyed by deviceId
     */
    public function listDevices(string $userId): array
    {
        $data = $this->firebase->get(self::BASE_PATH . "/{$userId}");
        if (!is_array($data)) return [];

        $devices = [];
        foreach ($data as $deviceId => $info) {
            if (!is_array($info)) continue;
            $devices[$deviceId] = [
                'deviceId'   => $deviceId,
                'platform'   => $info['platform']   ?? 'unknown',
                'deviceName' => $info['deviceName']  ?? 'Unknown Device',
                'appVersion' => $info['appVersion']  ?? '',
                'os'         => $info['os']          ?? '',
                'boundAt'    => $info['boundAt']     ?? '',
                'lastActive' => $info['lastActive']  ?? '',
                'status'     => $info['status']      ?? 'active',
                'fcmToken'   => $info['fcmToken']    ?? '',
            ];
        }

        return $devices;
    }

    /**
     * Bind (register) a device for a user.
     *
     * @param  string $userId
     * @param  string $deviceId  Unique device identifier
     * @param  array  $meta      Device metadata (platform, deviceName, appVersion, os, fcmToken)
     * @return array  ['success' => bool, 'message' => string]
     */
    public function bindDevice(string $userId, string $deviceId, array $meta = []): array
    {
        // Check if already bound
        $existing = $this->firebase->get(self::BASE_PATH . "/{$userId}/{$deviceId}");
        if ($existing && is_array($existing) && ($existing['status'] ?? '') === 'blocked') {
            return ['success' => false, 'message' => 'This device has been blocked.'];
        }

        // Check device limit (only for new devices)
        if (!$existing) {
            $devices = $this->listDevices($userId);
            $activeCount = count(array_filter($devices, fn($d) => ($d['status'] ?? '') !== 'blocked'));
            if ($activeCount >= self::MAX_DEVICES) {
                return ['success' => false, 'message' => 'Maximum device limit reached. Please remove an existing device first.'];
            }
        }

        $now = date('c');
        $record = [
            'platform'   => $meta['platform']   ?? 'android',
            'deviceName' => $meta['deviceName']  ?? 'Unknown Device',
            'appVersion' => $meta['appVersion']  ?? '',
            'os'         => $meta['os']          ?? '',
            'boundAt'    => $existing['boundAt'] ?? $now,
            'lastActive' => $now,
            'status'     => 'active',
        ];

        if (!empty($meta['fcmToken'])) {
            $record['fcmToken'] = $meta['fcmToken'];
        }

        $ok = $this->firebase->set(self::BASE_PATH . "/{$userId}/{$deviceId}", $record);

        return $ok
            ? ['success' => true, 'message' => 'Device registered successfully.']
            : ['success' => false, 'message' => 'Failed to register device.'];
    }

    /**
     * Remove (unbind) a device.
     */
    public function removeDevice(string $userId, string $deviceId): array
    {
        $ok = $this->firebase->delete(self::BASE_PATH . "/{$userId}/{$deviceId}");
        return $ok
            ? ['success' => true, 'message' => 'Device removed.']
            : ['success' => false, 'message' => 'Failed to remove device.'];
    }

    /**
     * Block a device (prevents re-binding).
     */
    public function blockDevice(string $userId, string $deviceId): array
    {
        $existing = $this->firebase->get(self::BASE_PATH . "/{$userId}/{$deviceId}");
        if (!$existing || !is_array($existing)) {
            return ['success' => false, 'message' => 'Device not found.'];
        }

        $ok = $this->firebase->update(self::BASE_PATH . "/{$userId}/{$deviceId}", [
            'status'    => 'blocked',
            'blockedAt' => date('c'),
        ]);

        return $ok
            ? ['success' => true, 'message' => 'Device blocked.']
            : ['success' => false, 'message' => 'Failed to block device.'];
    }

    /**
     * Check if a device is bound and active for a user.
     */
    public function isDeviceBound(string $userId, string $deviceId): bool
    {
        $data = $this->firebase->get(self::BASE_PATH . "/{$userId}/{$deviceId}");
        return is_array($data) && ($data['status'] ?? '') === 'active';
    }

    /**
     * Update last active timestamp and optionally FCM token.
     */
    public function touchDevice(string $userId, string $deviceId, ?string $fcmToken = null): bool
    {
        $updates = ['lastActive' => date('c')];
        if ($fcmToken !== null) {
            $updates['fcmToken'] = $fcmToken;
        }
        return $this->firebase->update(self::BASE_PATH . "/{$userId}/{$deviceId}", $updates);
    }

    /**
     * Get all active FCM tokens for a user (for sending push notifications).
     *
     * Phase 7x (2026-04-09): only excludes devices that are
     * EXPLICITLY blocked. Devices with a missing/empty status are
     * still considered eligible — the parent + teacher apps write
     * just the `fcmToken` field on `onNewToken`, without explicitly
     * setting `status` to "active", so the old strict filter
     * (`status === 'active'`) was rejecting every legitimate token
     * and returning an empty list, which made every push silently
     * no-op.
     *
     * @return array  List of FCM tokens
     */
    public function getFcmTokens(string $userId): array
    {
        // ── READ: Firestore FIRST (canonical) ──
        // Phase 8a (2026-04-09): canonical store is the `userDevices`
        // Firestore collection. RTDB path stays as a mirror until
        // Phase 9 cleanup.
        $tokens = [];
        $sourceTried = [];

        if ($this->fs !== null) {
            try {
                $docs = $this->fs->where(self::FS_COLLECTION, [
                    ['userId', '==', $userId],
                ]);
                $sourceTried[] = 'firestore';
                log_message('info', "Device_service::getFcmTokens({$userId}) — Firestore returned " . count($docs) . " doc(s)");
                foreach ($docs as $entry) {
                    $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                    if (!is_array($d)) continue;
                    $status = (string) ($d['status'] ?? '');
                    if ($status === 'blocked') continue;
                    if (!empty($d['fcmToken'])) {
                        $tokens[] = $d['fcmToken'];
                    }
                }
                if (!empty($tokens)) {
                    log_message('info', "Device_service::getFcmTokens({$userId}) — returning " . count($tokens) . " token(s) from Firestore");
                    return array_values(array_unique($tokens));
                }
            } catch (\Exception $e) {
                log_message('error', "Device_service::getFcmTokens({$userId}) — Firestore query failed: " . $e->getMessage());
            }
        } else {
            log_message('info', "Device_service::getFcmTokens({$userId}) — Firestore service not loaded, skipping to RTDB");
        }

        // ── RTDB fallback ──
        $sourceTried[] = 'rtdb';
        $devices = $this->listDevices($userId);
        log_message('info', "Device_service::getFcmTokens({$userId}) — RTDB Users/Devices/{$userId} returned " . count($devices) . " device(s)");

        $skipped = [];
        foreach ($devices as $deviceId => $d) {
            $status = (string) ($d['status'] ?? '');
            $hasToken = !empty($d['fcmToken']);
            if ($status === 'blocked') {
                $skipped[] = "{$deviceId}=blocked";
                continue;
            }
            if (!$hasToken) {
                $skipped[] = "{$deviceId}=no-token";
                continue;
            }
            $tokens[] = $d['fcmToken'];
        }

        if (!empty($skipped)) {
            log_message('info', "Device_service::getFcmTokens({$userId}) — RTDB skipped: " . implode(', ', $skipped));
        }
        log_message('info', "Device_service::getFcmTokens({$userId}) — final: " . count($tokens) . " token(s) (sources tried: " . implode(', ', $sourceTried) . ")");

        return array_values(array_unique($tokens));
    }
}
