<?php
defined('BASEPATH') or exit('No direct script access allowed');

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\WebPushConfig;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Exception\Messaging\NotFound;

/**
 * Push_service — sends Firebase Cloud Messaging push notifications via the
 * Kreait Admin SDK (FCM HTTP v1). The previous "push" pipeline only wrote
 * RTDB notice records, which only reached the app while it was open.
 *
 * Usage:
 *     $this->load->library('push_service');
 *     $sent = $this->push_service->sendToUser($studentId, [
 *         'title' => 'Attendance: Absent',
 *         'body'  => 'Aman was marked Absent today',
 *         'data'  => ['type' => 'student_absent', 'student_id' => $studentId],
 *     ]);
 *
 * Tokens are read via Device_service from
 *   Users/Devices/{userId}/{deviceId}/fcmToken
 * which is the same path the parent + teacher apps write to on
 * `onNewToken`.
 *
 * Stale tokens (NotFound) are pruned automatically so the next send
 * doesn't waste a round-trip on them.
 */
class Push_service
{
    /** @var \Kreait\Firebase\Contract\Messaging|null */
    private $messaging = null;

    /** @var Device_service */
    private $devices;

    /** @var Firebase */
    private $firebase;

    public function __construct()
    {
        $CI =& get_instance();

        if (!isset($CI->firebase)) {
            $CI->load->library('firebase');
        }
        $this->firebase = $CI->firebase;

        if (!isset($CI->device_service)) {
            $CI->load->library('device_service');
        }
        $this->devices = $CI->device_service;

        try {
            $serviceAccountPath = __DIR__ . '/../config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
            $this->messaging = $factory->createMessaging();
        } catch (\Exception $e) {
            log_message('error', 'Push_service init failed: ' . $e->getMessage());
            $this->messaging = null;
        }
    }

    /**
     * Send a push notification to all active devices of a single user.
     *
     * @param string $userId  Parent or teacher userId (matches Users/Devices/{userId})
     * @param array  $payload {title, body, data}
     * @return int            Number of devices that accepted the message
     */
    public function sendToUser(string $userId, array $payload): int
    {
        if ($this->messaging === null) {
            log_message('error', "Push_service::sendToUser({$userId}) — messaging not initialized (Kreait factory failed)");
            return 0;
        }
        if ($userId === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $userId)) {
            log_message('error', "Push_service::sendToUser — invalid userId '{$userId}'");
            return 0;
        }

        log_message('info', "Push_service::sendToUser({$userId}) — looking up tokens, payload title='" . ($payload['title'] ?? '') . "'");
        $tokens = $this->devices->getFcmTokens($userId);
        if (empty($tokens)) {
            log_message('error', "Push_service::sendToUser({$userId}) — NO TOKENS FOUND. Either Users/Devices/{$userId} is empty, or every device is missing fcmToken / blocked. Push aborted.");
            return 0;
        }

        log_message('info', "Push_service::sendToUser({$userId}) — sending to " . count($tokens) . " token(s)");
        $sent = $this->sendToTokens($tokens, $payload, $userId);
        log_message('info', "Push_service::sendToUser({$userId}) — FCM accepted {$sent} of " . count($tokens) . " token(s)");
        return $sent;
    }

    /**
     * Send a push notification to an explicit list of FCM tokens. Used by
     * the broadcast / multi-recipient flows.
     *
     * Returns the number of successful deliveries. Tokens that come back
     * NotFound are removed from RTDB so we stop trying them.
     *
     * @param string|null $ownerUserId  user the tokens belong to (for cleanup)
     */
    public function sendToTokens(array $tokens, array $payload, ?string $ownerUserId = null): int
    {
        if ($this->messaging === null) return 0;
        $tokens = array_values(array_filter(array_unique(array_map('strval', $tokens))));
        if (empty($tokens)) return 0;

        $title = (string) ($payload['title'] ?? '');
        $body  = (string) ($payload['body']  ?? '');
        $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // FCM data values must be strings.
        $stringData = [];
        foreach ($data as $k => $v) {
            if (is_scalar($v)) $stringData[(string) $k] = (string) $v;
        }
        // Always include title/body in the data payload too so data-only
        // handlers (e.g. when notification arrives in background) can show it.
        if ($title !== '' && !isset($stringData['title'])) $stringData['title'] = $title;
        if ($body  !== '' && !isset($stringData['body']))  $stringData['body']  = $body;

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($stringData)
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'schoolsync_notifications',
                    'sound' => 'default',
                ],
            ]));

        try {
            $report = $this->messaging->sendMulticast($message, $tokens);
        } catch (\Throwable $e) {
            log_message('error', 'Push_service sendMulticast failed: ' . $e->getMessage());
            return 0;
        }

        $successCount = $report->successes()->count();

        // Prune stale tokens (UNREGISTERED / NotFound) so we don't keep
        // hammering them every time something is queued.
        if ($ownerUserId !== null && $report->hasFailures()) {
            $stale = [];
            foreach ($report->failures()->getItems() as $failure) {
                $err = $failure->error();
                if ($err instanceof NotFound) {
                    $stale[] = $failure->target()->value();
                    continue;
                }
                // Some unregistered errors come through as generic exceptions
                // — match by message as a safety net.
                $msg = $err ? $err->getMessage() : '';
                if (stripos($msg, 'registration-token-not-registered') !== false
                    || stripos($msg, 'unregistered') !== false) {
                    $stale[] = $failure->target()->value();
                }
            }
            if (!empty($stale)) {
                $this->_pruneTokens($ownerUserId, $stale);
            }
        }

        return $successCount;
    }

    /**
     * Remove stale FCM tokens from RTDB so they aren't retried next time.
     */
    private function _pruneTokens(string $userId, array $staleTokens): void
    {
        if (empty($staleTokens)) return;
        $devices = $this->devices->listDevices($userId);
        foreach ($devices as $deviceId => $info) {
            $tok = $info['fcmToken'] ?? '';
            if ($tok === '') continue;
            if (in_array($tok, $staleTokens, true)) {
                try {
                    $this->firebase->update("Users/Devices/{$userId}/{$deviceId}", [
                        'fcmToken'   => '',
                        'staleAt'    => date('c'),
                    ]);
                    log_message('info', "Push_service: pruned stale token for {$userId}/{$deviceId}");
                } catch (\Exception $e) {
                    log_message('error', 'Push_service prune failed: ' . $e->getMessage());
                }
            }
        }
    }
}
