<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Messaging_service — Firestore-first messaging primitives shared by the
 * admin web (Communication.php), the parent app, and the teacher app.
 *
 * ARCHITECTURE (Phase 5)
 *   Firestore is the canonical store. RTDB is a best-effort mirror so
 *   any pre-Phase-5 client (or older mobile build) can still read/write
 *   while we cut over. The mirror direction is FORWARD ONLY — RTDB
 *   writes happen AFTER the Firestore write succeeds, so a transient
 *   RTDB outage never loses data.
 *
 *   Field shapes are the canonical schema from Phases 1-4 (camelCase
 *   only) so the migration is plumbing, not redesign. See
 *   memory/messaging_canonical_schema.md for the contract.
 *
 * COLLECTIONS (top-level, flat — same convention as subjectAssignments)
 *   conversations    doc id = {schoolId}_{convId}
 *   messages         doc id = {schoolId}_{convId}_{msgId}      (sortable)
 *   messageInboxes   doc id = {schoolId}_{role}_{userId}_{convId}
 *
 * RTDB MIRROR PATHS (legacy)
 *   Schools/{schoolName}/Communication/Messages/Conversations/{convId}
 *   Schools/{schoolName}/Communication/Messages/Chat/{convId}/{msgId}
 *   Schools/{schoolName}/Communication/Messages/Inbox/{role}/{userId}/{convId}
 *
 * USAGE
 *   $this->load->library('messaging_service', null, 'msg_svc');
 *   $this->msg_svc->init($this->fs, $this->firebase, $this->school_id,
 *                        $this->school_name, $this->session_year);
 *
 *   $this->msg_svc->writeConversation($convId, $convData);
 *   $this->msg_svc->writeMessage($convId, $msgId, $msgData);
 *   $this->msg_svc->writeInbox($role, $userId, $convId, $inboxData, $merge=true);
 *   $this->msg_svc->incrementUnread($role, $userId, $convId);
 *   $this->msg_svc->markRead($role, $userId, $convId);
 *
 *   $list = $this->msg_svc->listInbox($role, $userId, $limit);
 *   $msgs = $this->msg_svc->listMessages($convId, $limit);
 *   $conv = $this->msg_svc->getConversation($convId);
 */
class Messaging_service
{
    /** @var Firestore_service */
    private $fs;

    /** @var object Firebase RTDB library */
    private $firebase;

    /** @var string School ID (SCH_XXXXXX or legacy name like "Demo") */
    private $schoolId = '';

    /** @var string School name (used in RTDB paths — same as schoolId for new schools) */
    private $schoolName = '';

    /** @var string Academic session (kept for parity with sister services; not currently in path) */
    private $session = '';

    /** @var bool */
    private $ready = false;

    const COL_CONVERSATIONS = 'conversations';
    const COL_MESSAGES      = 'messages';
    const COL_INBOXES       = 'messageInboxes';

    public function init($fs, $firebase, string $schoolId, string $schoolName = '', string $session = ''): self
    {
        $this->fs         = $fs;
        $this->firebase   = $firebase;
        $this->schoolId   = $schoolId;
        $this->schoolName = $schoolName ?: $schoolId;
        $this->session    = $session;
        $this->ready      = ($fs !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    // ══════════════════════════════════════════════════════════════════
    //  DOC ID HELPERS — composite keys for direct lookup
    // ══════════════════════════════════════════════════════════════════

    public function conversationDocId(string $convId): string
    {
        return "{$this->schoolId}_{$convId}";
    }

    public function messageDocId(string $convId, string $msgId): string
    {
        return "{$this->schoolId}_{$convId}_{$msgId}";
    }

    /**
     * Inbox stub doc id. Role is normalised to lowercase so we never
     * accidentally split a user's inbox across two documents.
     */
    public function inboxDocId(string $role, string $userId, string $convId): string
    {
        $r = strtolower($role);
        return "{$this->schoolId}_{$r}_{$userId}_{$convId}";
    }

    // ══════════════════════════════════════════════════════════════════
    //  WRITES — Firestore first, RTDB mirror best-effort
    // ══════════════════════════════════════════════════════════════════

    /**
     * Create or merge a conversation document.
     *
     * Adds `schoolId` + `participantIds` (flat array) to whatever caller
     * passes. `participantIds` is the array form of the `participants`
     * map and is what enables Firestore `array-contains` dedup queries.
     */
    public function writeConversation(string $convId, array $data, bool $merge = true): bool
    {
        if (!$this->ready || $convId === '') return false;

        $doc = $this->_normaliseConversation($convId, $data);

        // 1. Firestore — primary, must succeed
        $fsOk = false;
        try {
            $fsOk = (bool) $this->fs->set(self::COL_CONVERSATIONS, $this->conversationDocId($convId), $doc, $merge);
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::writeConversation FS failed: ' . $e->getMessage());
        }

        // 2. RTDB mirror — best-effort
        try {
            $rtdbPath = $this->_rtdb('Conversations/' . $convId);
            if ($merge) {
                $this->firebase->update($rtdbPath, $doc);
            } else {
                $this->firebase->set($rtdbPath, $doc);
            }
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::writeConversation RTDB mirror failed: ' . $e->getMessage());
        }

        return $fsOk;
    }

    /**
     * Append a chat message. Caller supplies the canonical message id
     * (e.g., MSG00001 from the existing _next_id sequence).
     */
    public function writeMessage(string $convId, string $msgId, array $data): bool
    {
        if (!$this->ready || $convId === '' || $msgId === '') return false;

        $doc = $this->_normaliseMessage($convId, $msgId, $data);

        $fsOk = false;
        try {
            $fsOk = (bool) $this->fs->set(self::COL_MESSAGES, $this->messageDocId($convId, $msgId), $doc, false);
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::writeMessage FS failed: ' . $e->getMessage());
        }

        try {
            $rtdbPath = $this->_rtdb("Chat/{$convId}/{$msgId}");
            $this->firebase->set($rtdbPath, $doc);
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::writeMessage RTDB mirror failed: ' . $e->getMessage());
        }

        return $fsOk;
    }

    /**
     * Create or merge an inbox stub for a single user-conversation pair.
     */
    public function writeInbox(string $role, string $userId, string $convId, array $data, bool $merge = true): bool
    {
        if (!$this->ready || $userId === '' || $convId === '') return false;

        $doc = $this->_normaliseInbox($role, $userId, $convId, $data);

        $fsOk = false;
        try {
            $fsOk = (bool) $this->fs->set(self::COL_INBOXES, $this->inboxDocId($role, $userId, $convId), $doc, $merge);
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::writeInbox FS failed: ' . $e->getMessage());
        }

        try {
            $rtdbPath = $this->_rtdb("Inbox/" . strtolower($role) . "/{$userId}/{$convId}");
            if ($merge) {
                $this->firebase->update($rtdbPath, $doc);
            } else {
                $this->firebase->set($rtdbPath, $doc);
            }
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::writeInbox RTDB mirror failed: ' . $e->getMessage());
        }

        return $fsOk;
    }

    /**
     * Read-modify-write unread increment. The Firestore REST client
     * doesn't expose FieldValue.increment, so we read the current value
     * first. This is racy under heavy concurrency but matches the
     * existing RTDB behaviour we're replacing.
     */
    public function incrementUnread(string $role, string $userId, string $convId, array $extraFields = []): bool
    {
        if (!$this->ready) return false;

        $current = $this->_safeGetInbox($role, $userId, $convId);
        $unread = is_array($current) ? (int) ($current['unreadCount'] ?? 0) : 0;

        $patch = array_merge($extraFields, ['unreadCount' => $unread + 1]);
        return $this->writeInbox($role, $userId, $convId, $patch, true);
    }

    /**
     * Mark a conversation as read for a user — resets unreadCount and
     * stamps lastSeenAt.
     */
    public function markRead(string $role, string $userId, string $convId): bool
    {
        if (!$this->ready) return false;

        return $this->writeInbox($role, $userId, $convId, [
            'unreadCount' => 0,
            'lastSeenAt'  => $this->nowMs(),
        ], true);
    }

    /**
     * "Delete chat for me" — remove this user's inbox stub only. The
     * conversation + chat history stay intact for other participants.
     */
    public function deleteInbox(string $role, string $userId, string $convId): bool
    {
        if (!$this->ready) return false;

        $fsOk = false;
        try {
            $fsOk = (bool) $this->fs->remove(self::COL_INBOXES, $this->inboxDocId($role, $userId, $convId));
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::deleteInbox FS failed: ' . $e->getMessage());
        }

        try {
            $rtdbPath = $this->_rtdb('Inbox/' . strtolower($role) . '/' . $userId);
            $this->firebase->delete($rtdbPath, $convId);
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::deleteInbox RTDB mirror failed: ' . $e->getMessage());
        }

        return $fsOk;
    }

    // ══════════════════════════════════════════════════════════════════
    //  READS — Firestore first, RTDB fallback for unmigrated data
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fetch a single conversation document.
     */
    public function getConversation(string $convId): ?array
    {
        if (!$this->ready || $convId === '') return null;

        try {
            $doc = $this->fs->get(self::COL_CONVERSATIONS, $this->conversationDocId($convId));
            if (is_array($doc) && !empty($doc)) return $doc;
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::getConversation FS failed: ' . $e->getMessage());
        }

        // RTDB fallback
        try {
            $rtdbDoc = $this->firebase->get($this->_rtdb('Conversations/' . $convId));
            return is_array($rtdbDoc) ? $rtdbDoc : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * List a user's inbox, newest first. Returns an array of inbox docs
     * each augmented with `id` (the inbox doc id) and `conversationId`.
     */
    public function listInbox(string $role, string $userId, int $limit = 100): array
    {
        if (!$this->ready) return [];

        try {
            $rows = $this->fs->schoolWhere(self::COL_INBOXES, [
                ['role',   '==', strtolower($role)],
                ['userId', '==', $userId],
            ], 'lastMessageTime', 'DESC', $limit);

            if (is_array($rows) && count($rows) > 0) {
                return array_map(function ($row) {
                    $data = $row['data'] ?? [];
                    $data['id'] = $row['id'] ?? '';
                    return $data;
                }, $rows);
            }
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::listInbox FS failed: ' . $e->getMessage());
        }

        // RTDB fallback — used while data is still backfilling.
        try {
            $rtdbInbox = $this->firebase->get($this->_rtdb('Inbox/' . strtolower($role) . '/' . $userId));
            if (!is_array($rtdbInbox)) return [];
            $out = [];
            foreach ($rtdbInbox as $convId => $entry) {
                if (!is_array($entry)) continue;
                $entry['conversationId'] = $entry['conversationId'] ?? $convId;
                $entry['id'] = $convId;
                $out[] = $entry;
            }
            usort($out, function ($a, $b) {
                $av = $a['lastMessageTime'] ?? 0;
                $bv = $b['lastMessageTime'] ?? 0;
                return ((int) $bv) - ((int) $av);
            });
            return array_slice($out, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * List the messages in a conversation, oldest first.
     */
    public function listMessages(string $convId, int $limit = 200): array
    {
        if (!$this->ready || $convId === '') return [];

        try {
            $rows = $this->fs->schoolWhere(self::COL_MESSAGES, [
                ['conversationId', '==', $convId],
            ], 'timestamp', 'ASC', $limit);

            if (is_array($rows) && count($rows) > 0) {
                return array_map(function ($row) {
                    $data = $row['data'] ?? [];
                    $data['id'] = $row['id'] ?? '';
                    return $data;
                }, $rows);
            }
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::listMessages FS failed: ' . $e->getMessage());
        }

        try {
            $chat = $this->firebase->get($this->_rtdb('Chat/' . $convId));
            if (!is_array($chat)) return [];
            $out = [];
            foreach ($chat as $msgId => $msg) {
                if (!is_array($msg)) continue;
                $msg['id'] = $msgId;
                $msg['messageId'] = $msg['messageId'] ?? $msgId;
                $out[] = $msg;
            }
            usort($out, function ($a, $b) {
                $av = $a['timestamp'] ?? 0;
                $bv = $b['timestamp'] ?? 0;
                return ((int) $av) - ((int) $bv);
            });
            return array_slice($out, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Find an existing direct conversation between two users with the
     * same student context — used by create_conversation() to avoid
     * spawning duplicate threads.
     */
    public function findDirectConversation(string $userIdA, string $userIdB, string $studentId = ''): ?array
    {
        if (!$this->ready) return null;

        try {
            // Filter by one user via array-contains, then refine in PHP.
            $rows = $this->fs->schoolWhere(self::COL_CONVERSATIONS, [
                ['participantIds', 'array-contains', $userIdA],
            ], null, 'ASC', 100);
            if (!is_array($rows)) return null;

            foreach ($rows as $row) {
                $d = $row['data'] ?? $row;
                $data = $row['data'] ?? [];
                $pids = $data['participantIds'] ?? [];
                if (!is_array($pids) || !in_array($userIdB, $pids, true)) continue;
                $ctxStudent = $data['context']['studentId'] ?? '';
                if ($ctxStudent === $studentId) {
                    $data['id'] = $d['id'] ?? '';
                    return $data;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Messaging_service::findDirectConversation FS failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Sum unread badges across a user's inbox.
     */
    public function getUnreadCount(string $role, string $userId): int
    {
        $inbox = $this->listInbox($role, $userId, 500);
        $total = 0;
        foreach ($inbox as $entry) {
            $total += (int) ($entry['unreadCount'] ?? 0);
        }
        return $total;
    }

    // ══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════

    public function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Map a participant role label to its lowercase inbox segment —
     * mirrors Communication::_inbox_role_for() so we have one
     * authoritative mapping per process.
     */
    public function inboxRoleFor(string $participantRole): string
    {
        $r = strtolower(trim($participantRole));
        if ($r === 'teacher')                       return 'teacher';
        if ($r === 'parent' || $r === 'student')    return 'parent';
        if ($r === 'hr' || $r === 'hr manager')     return 'hr';
        return 'admin';
    }

    private function _rtdb(string $sub): string
    {
        return "Schools/{$this->schoolName}/Communication/Messages/{$sub}";
    }

    private function _safeGetInbox(string $role, string $userId, string $convId): ?array
    {
        try {
            $doc = $this->fs->get(self::COL_INBOXES, $this->inboxDocId($role, $userId, $convId));
            if (is_array($doc) && !empty($doc)) return $doc;
        } catch (\Exception $e) {
            // fall through to RTDB
        }
        try {
            $rtdb = $this->firebase->get($this->_rtdb('Inbox/' . strtolower($role) . "/{$userId}/{$convId}"));
            return is_array($rtdb) ? $rtdb : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Stamp schoolId + derive participantIds from participants map +
     * fill in any required defaults so callers don't have to.
     */
    private function _normaliseConversation(string $convId, array $data): array
    {
        $data['schoolId']        = $this->schoolId;
        $data['conversationId']  = $convId;
        if (!isset($data['participantIds']) && isset($data['participants']) && is_array($data['participants'])) {
            $data['participantIds'] = array_values(array_map('strval', array_keys($data['participants'])));
        }
        if (!isset($data['updatedAt'])) {
            $data['updatedAt'] = $this->nowMs();
        }
        return $data;
    }

    private function _normaliseMessage(string $convId, string $msgId, array $data): array
    {
        $data['schoolId']       = $this->schoolId;
        $data['conversationId'] = $convId;
        $data['messageId']      = $msgId;
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = $this->nowMs();
        }
        return $data;
    }

    private function _normaliseInbox(string $role, string $userId, string $convId, array $data): array
    {
        $data['schoolId']       = $this->schoolId;
        $data['role']           = strtolower($role);
        $data['userId']         = $userId;
        $data['conversationId'] = $convId;
        if (!isset($data['lastMessageTime'])) {
            $data['lastMessageTime'] = $this->nowMs();
        }
        return $data;
    }
}
