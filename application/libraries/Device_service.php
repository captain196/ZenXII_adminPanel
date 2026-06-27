<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Device_service — Mobile device registry on Firestore canonical.
 *
 * Stores device info at: userDevices/{userId}_{safeDeviceId}
 *
 * Each canonical record:
 *   schoolId    : Tenant id (SCH_XXXXXXXXXX)
 *   userId      : Login user id (STA0001 / STU0001 / SSA0001 / …)
 *   deviceId    : Original (unsanitized) device id from the mobile app
 *   fcmToken    : Firebase Cloud Messaging registration token (may be empty
 *                 when device registered before FCM enrollment completed)
 *   platform    : "android" | "ios" | "web"
 *   status      : "active" | "blocked"
 *   appRole     : "teacher" | "parent" | "admin"
 *   lastActive  : ISO 8601 timestamp of last token refresh / heartbeat
 *
 * Optional fields preserved when present:
 *   deviceName, appVersion, os, boundAt, blockedAt, staleAt
 *
 * Tenant context is required for every operation — see init() + _schoolId().
 *
 * Package 3A P2 (2026-06-15): legacy RTDB device subtree retired;
 * Firestore is the sole source of truth. All 8 prior RTDB API calls
 * replaced with their Firestore canonical equivalents on the
 * `userDevices` collection.
 */
class Device_service
{
    /** @var Firestore_service|null Firestore service (loaded lazily via CI). */
    private $fs = null;

    /**
     * Tenant context for Firestore-canonical operations. Populated via
     * either init() (explicit) or get_instance()->school_id (auto). Stored
     * as a trimmed string; empty string means "not yet set, fall back".
     */
    private string $schoolId = '';

    /** Max devices per user */
    private const MAX_DEVICES = 5;

    /** Firestore canonical collection for user devices. */
    private const FS_COLLECTION = 'userDevices';

    public function __construct()
    {
        $CI =& get_instance();
        // Firestore_service is autoloaded as $this->fs in MY_Controller, but
        // libraries don't inherit that — pull it from the controller.
        if (isset($CI->fs)) {
            $this->fs = $CI->fs;
        }
    }

    /**
     * Set the tenant context for subsequent Device_service calls.
     *
     * Callers with explicit tenant id (typically MY_Controller-derived
     * controllers) may call this once after loading the library. When
     * init() is NOT called, _schoolId() falls back to
     * get_instance()->school_id — which covers every web-request context
     * that runs through MY_Controller.
     *
     * Use init() explicitly in CLI / cron / verifier scopes where no CI
     * controller is on the call stack.
     *
     * Backward-compatible — existing callers do not need to invoke this.
     *
     * @param string $schoolId The tenant id (e.g. "SCH_XXXXXX"). Empty
     *                         string is treated as "not set" so callers
     *                         can pass an optional value safely.
     */
    public function init(string $schoolId): void
    {
        $this->schoolId = trim($schoolId);
    }

    /**
     * Resolve the tenant id for the current call.
     *
     * Resolution order:
     *   1. Explicitly injected via init() — preferred for non-web contexts
     *      (CLI, verifier, jobs).
     *   2. CodeIgniter session userdata 'school_id' — the canonical source
     *      every MY_Controller-derived controller writes from session at
     *      construct time, and the only one externally visible (the
     *      controller's $school_id field is `protected` and so unreadable
     *      via get_instance()->school_id from a library context).
     *
     * Fails loud with RuntimeException when neither source carries a tenant
     * id — Firestore queries cannot be safely scoped across tenants without
     * one, so silent fall-through is never acceptable.
     *
     * @throws \RuntimeException when no tenant context is available
     */
    private function _schoolId(): string
    {
        if ($this->schoolId !== '') {
            return $this->schoolId;
        }
        $CI = get_instance();
        if ($CI !== null && isset($CI->session)) {
            $sid = (string) $CI->session->userdata('school_id');
            if ($sid !== '') {
                return $sid;
            }
        }
        throw new \RuntimeException(
            'Device_service: tenant context absent — call init($schoolId) before use, '
            . 'or invoke from a context with an active CodeIgniter session.'
        );
    }

    /**
     * Sanitize a raw device id into the canonical safe segment used in
     * doc ids. Mirrors the regex used by Parent + Teacher app
     * AuthRepository.registerFcmToken() so server + client write to the
     * same doc id for the same hardware install.
     */
    private function _safeDeviceId(string $raw): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $raw);
    }

    /**
     * Compose the canonical doc id for a (userId, deviceId) pair.
     */
    private function _docId(string $userId, string $deviceId): string
    {
        return $userId . '_' . $this->_safeDeviceId($deviceId);
    }

    /**
     * List all devices for a user.
     *
     * Return shape (preserved from pre-P2):
     *   array keyed by original (unsanitized) deviceId, each entry has
     *   { deviceId, platform, deviceName, appVersion, os, boundAt,
     *     lastActive, status, fcmToken }.
     *
     * @return array  Array of device records, keyed by deviceId
     */
    public function listDevices(string $userId): array
    {
        if ($this->fs === null) return [];
        try {
            $rows = $this->fs->where(self::FS_COLLECTION, [
                ['schoolId', '==', $this->_schoolId()],
                ['userId',   '==', $userId],
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Device_service::listDevices({$userId}): " . $e->getMessage());
            return [];
        }

        $devices = [];
        if (!is_array($rows)) return $devices;
        foreach ($rows as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : [];
            if (!is_array($d)) continue;
            $rawDeviceId = (string) ($d['deviceId'] ?? '');
            if ($rawDeviceId === '') continue;
            $devices[$rawDeviceId] = [
                'deviceId'   => $rawDeviceId,
                'platform'   => $d['platform']   ?? 'unknown',
                'deviceName' => $d['deviceName']  ?? 'Unknown Device',
                'appVersion' => $d['appVersion']  ?? '',
                'os'         => $d['os']          ?? '',
                'boundAt'    => $d['boundAt']     ?? '',
                'lastActive' => $d['lastActive']  ?? '',
                'status'     => $d['status']      ?? 'active',
                'fcmToken'   => $d['fcmToken']    ?? '',
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
        if ($this->fs === null) {
            return ['success' => false, 'message' => 'Firestore service unavailable.'];
        }
        $docId = $this->_docId($userId, $deviceId);

        $existing = null;
        try {
            $existing = $this->fs->get(self::FS_COLLECTION, $docId);
        } catch (\Throwable $e) {
            log_message('error', "Device_service::bindDevice({$userId}/{$deviceId}) existence-check: " . $e->getMessage());
        }

        if (is_array($existing) && ($existing['status'] ?? '') === 'blocked') {
            return ['success' => false, 'message' => 'This device has been blocked.'];
        }

        // Enforce per-user device limit only for net-new bindings.
        if (!is_array($existing) || empty($existing)) {
            $devices = $this->listDevices($userId);
            $activeCount = count(array_filter($devices, fn($d) => ($d['status'] ?? '') !== 'blocked'));
            if ($activeCount >= self::MAX_DEVICES) {
                return ['success' => false, 'message' => 'Maximum device limit reached. Please remove an existing device first.'];
            }
        }

        $now = date('c');
        $record = [
            'schoolId'   => $this->_schoolId(),
            'userId'     => $userId,
            'deviceId'   => $deviceId,
            'platform'   => $meta['platform']   ?? 'android',
            'deviceName' => $meta['deviceName']  ?? 'Unknown Device',
            'appVersion' => $meta['appVersion']  ?? '',
            'os'         => $meta['os']          ?? '',
            'boundAt'    => (is_array($existing) && !empty($existing['boundAt']))
                             ? (string) $existing['boundAt']
                             : $now,
            'lastActive' => $now,
            'status'     => 'active',
        ];
        if (!empty($meta['fcmToken'])) {
            $record['fcmToken'] = $meta['fcmToken'];
        }

        try {
            $ok = (bool) $this->fs->set(self::FS_COLLECTION, $docId, $record, /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', "Device_service::bindDevice({$userId}/{$deviceId}) write: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to register device.'];
        }

        return $ok
            ? ['success' => true, 'message' => 'Device registered successfully.']
            : ['success' => false, 'message' => 'Failed to register device.'];
    }

    /**
     * Remove (unbind) a device.
     */
    public function removeDevice(string $userId, string $deviceId): array
    {
        if ($this->fs === null) {
            return ['success' => false, 'message' => 'Firestore service unavailable.'];
        }
        try {
            $ok = (bool) $this->fs->remove(self::FS_COLLECTION, $this->_docId($userId, $deviceId));
        } catch (\Throwable $e) {
            log_message('error', "Device_service::removeDevice({$userId}/{$deviceId}): " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to remove device.'];
        }
        return $ok
            ? ['success' => true, 'message' => 'Device removed.']
            : ['success' => false, 'message' => 'Failed to remove device.'];
    }

    /**
     * Block a device (prevents re-binding).
     */
    public function blockDevice(string $userId, string $deviceId): array
    {
        if ($this->fs === null) {
            return ['success' => false, 'message' => 'Firestore service unavailable.'];
        }
        $docId = $this->_docId($userId, $deviceId);

        $existing = null;
        try {
            $existing = $this->fs->get(self::FS_COLLECTION, $docId);
        } catch (\Throwable $e) {
            log_message('error', "Device_service::blockDevice({$userId}/{$deviceId}) existence-check: " . $e->getMessage());
        }
        if (!is_array($existing) || empty($existing)) {
            return ['success' => false, 'message' => 'Device not found.'];
        }

        try {
            $ok = (bool) $this->fs->update(self::FS_COLLECTION, $docId, [
                'status'    => 'blocked',
                'blockedAt' => date('c'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Device_service::blockDevice({$userId}/{$deviceId}) write: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to block device.'];
        }
        return $ok
            ? ['success' => true, 'message' => 'Device blocked.']
            : ['success' => false, 'message' => 'Failed to block device.'];
    }

    /**
     * Check if a device is bound and active for a user.
     */
    public function isDeviceBound(string $userId, string $deviceId): bool
    {
        if ($this->fs === null) return false;
        try {
            $data = $this->fs->get(self::FS_COLLECTION, $this->_docId($userId, $deviceId));
        } catch (\Throwable $e) {
            return false;
        }
        return is_array($data) && ($data['status'] ?? '') === 'active';
    }

    /**
     * Update last active timestamp and optionally FCM token.
     */
    public function touchDevice(string $userId, string $deviceId, ?string $fcmToken = null): bool
    {
        if ($this->fs === null) return false;
        $updates = ['lastActive' => date('c')];
        if ($fcmToken !== null) {
            $updates['fcmToken'] = $fcmToken;
        }
        try {
            return (bool) $this->fs->update(self::FS_COLLECTION, $this->_docId($userId, $deviceId), $updates);
        } catch (\Throwable $e) {
            log_message('error', "Device_service::touchDevice({$userId}/{$deviceId}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all active FCM tokens for a user (for sending push notifications).
     *
     * Firestore-only. Returns every non-blocked device's fcmToken for the
     * tenant-scoped user. Blocked devices and devices with empty tokens
     * are excluded. Tokens are deduplicated before return.
     *
     * Package 3A P2 (2026-06-15): the RTDB fallback that previously
     * shadowed this method has been removed; tokens are read only from
     * the Firestore `userDevices` canonical collection.
     *
     * @return array  List of FCM tokens
     */
    public function getFcmTokens(string $userId): array
    {
        if ($this->fs === null) {
            log_message('error', "Device_service::getFcmTokens({$userId}) — Firestore service not loaded; cannot resolve tokens");
            return [];
        }

        try {
            $docs = $this->fs->where(self::FS_COLLECTION, [
                ['schoolId', '==', $this->_schoolId()],
                ['userId',   '==', $userId],
            ]);
        } catch (\Throwable $e) {
            log_message('error', "Device_service::getFcmTokens({$userId}) Firestore query failed: " . $e->getMessage());
            return [];
        }

        $tokens = [];
        if (is_array($docs)) {
            foreach ($docs as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                if (($d['status'] ?? '') === 'blocked') continue;
                if (!empty($d['fcmToken'])) {
                    $tokens[] = (string) $d['fcmToken'];
                }
            }
        }

        log_message('info', "Device_service::getFcmTokens({$userId}) — returning " . count($tokens) . " token(s) from Firestore userDevices");
        return array_values(array_unique($tokens));
    }
}
