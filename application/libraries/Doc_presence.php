<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Doc_rows.php';

/**
 * Doc_presence — who else has this template open.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * The optimistic lock is correct and it tells you far too late: you discover a
 * colleague only after twenty minutes of work, in a dialog whose least-bad
 * option is to throw that work away. Knowing at the moment you open the
 * template costs one read and changes the decision from "which of us loses" to
 * "how shall we split this".
 *
 * Figma's conclusion is the one worth copying here — not their CRDT, which they
 * themselves concluded was unnecessary behind a central server, but the
 * observation underneath it: most concurrent edits touch different objects, so
 * collisions are rarer than they feel, and the expensive part is the surprise.
 *
 * ---------------------------------------------------------------------------
 * ITS OWN COLLECTION, NOT A FIELD ON THE TEMPLATE
 * ---------------------------------------------------------------------------
 * Deliberate. publish() freezes the template head into an immutable snapshot
 * that an issued certificate points at forever. Presence is ephemeral chatter —
 * who had a tab open one Tuesday — and it must never be frozen into the legal
 * record of what a document said. Keeping it out of the head also means a
 * heartbeat can never collide with a save: it touches a different document and
 * never moves lockVersion.
 */
class Doc_presence
{
    const COLLECTION = 'templateSessions';

    /** Someone is "here" if they have been seen within this window. */
    const ACTIVE_WINDOW_SEC = 90;

    /** @var array<string,callable> */
    private array $store;

    public function __construct(array $params = [])
    {
        if (!empty($params['store'])) {
            $this->store = $params['store'];
            return;
        }
        $ci = &get_instance();
        $fs = $ci->fs;
        $this->store = [
            'set'    => fn(string $c, string $id, array $d) => $fs->set($c, $id, $d),
            'delete' => method_exists($fs, 'raw_client') && is_object($fs->raw_client())
                        && method_exists($fs->raw_client(), 'deleteDocument')
                ? fn(string $c, string $id) => $fs->raw_client()->deleteDocument($c, $id)
                : null,
            'query'  => fn(string $c, array $w) => Doc_rows::map($fs->schoolWhere($c, $w)),
        ];
    }

    private function key(string $schoolId, string $templateId, string $userId): string
    {
        return $schoolId . '_' . str_replace($schoolId . '_', '', $templateId) . '_' . $userId;
    }

    /**
     * Record that I am here, and report who else is.
     *
     * One call does both on purpose: a heartbeat that did not also return the
     * room would need a second round trip, and at ~2s per Firestore call from
     * outside the database region that is the difference between a heartbeat
     * you can run every minute and one you cannot.
     *
     * @return array{others: list<array{userId:string,userName:string,secondsAgo:int}>}
     */
    public function heartbeat(string $schoolId, string $templateId, string $userId,
                              string $userName = '', ?int $nowTs = null): array
    {
        $now = $nowTs ?? time();

        if ($schoolId === '' || $templateId === '' || $userId === '') {
            // Never write a session that cannot be attributed or cleaned up.
            return ['others' => []];
        }

        ($this->store['set'])(self::COLLECTION, $this->key($schoolId, $templateId, $userId), [
            'schoolId'   => $schoolId,
            'templateId' => $templateId,
            'userId'     => $userId,
            'userName'   => $userName !== '' ? $userName : $userId,
            'seenAt'     => $now,
        ]);

        return ['others' => $this->others($schoolId, $templateId, $userId, $now)];
    }

    /** Everyone except me who has been seen inside the window. */
    public function others(string $schoolId, string $templateId, string $userId,
                           ?int $nowTs = null): array
    {
        $now  = $nowTs ?? time();
        $rows = ($this->store['query'])(self::COLLECTION, [
            ['schoolId',   '=', $schoolId],
            ['templateId', '=', $templateId],
        ]) ?: [];

        $out = [];
        foreach ($rows as $r) {
            if (($r['userId'] ?? '') === $userId || ($r['userId'] ?? '') === '') {
                continue;
            }
            $age = $now - (int) ($r['seenAt'] ?? 0);
            /* A stale row is someone who closed the tab, or whose browser was
               asleep. Showing them as present would train people to ignore the
               warning, which is worse than not showing it at all. */
            if ($age > self::ACTIVE_WINDOW_SEC) {
                continue;
            }
            $out[] = [
                'userId'     => (string) $r['userId'],
                'userName'   => (string) ($r['userName'] ?? $r['userId']),
                'secondsAgo' => max(0, $age),
            ];
        }

        usort($out, fn($a, $b) => $a['secondsAgo'] <=> $b['secondsAgo']);
        return $out;
    }

    /**
     * Leave. Best effort by design: this rides on a page-unload beacon, which
     * browsers are free to drop. The freshness window is what actually retires
     * a session — this only makes it immediate when it works.
     */
    public function leave(string $schoolId, string $templateId, string $userId): bool
    {
        if (!is_callable($this->store['delete'] ?? null)) {
            return false;
        }
        ($this->store['delete'])(self::COLLECTION, $this->key($schoolId, $templateId, $userId));
        return true;
    }
}
