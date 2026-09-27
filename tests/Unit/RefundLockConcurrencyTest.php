<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Fee_firestore_txn.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  REF-1 — THE PER-STUDENT LOCK IS ATOMIC, AND UNKNOWN SURVIVES
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `feeLocks/{school}_{userId}` has TWO producers and until now only one was
 * correct:
 *
 *   FeeCollectionService  claim batch with `precondition: exists:false`
 *                         — already atomic for the fresh case.
 *   acquireLock()         read → judge age → BLIND SET
 *                         — two callers that read before either writes BOTH
 *                           acquire, and the blind set ALSO clobbers a lock
 *                           the collection service is holding correctly.
 *
 * That second point is the one that matters: participant B does everything
 * right and participant A overwrites it anyway.
 *
 * ── THE OTHER HALF: UNKNOWN ≠ CONFLICT ────────────────────────────────────
 * acquireLock() returned '' for a live lock, for a thrown exception, AND for an
 * uninitialised helper. Fee_refund_service then deleted its idempotency guard
 * on all three — and that guard is the only thing preventing a SECOND
 * DISBURSEMENT of the same refund.
 *
 *   CONFLICT → the write definitely did NOT land → releasing the guard is safe
 *   UNKNOWN  → the write MAY have landed → the guard MUST survive
 *
 * The double below models document VERSIONS, because a precondition test
 * against a version-less store passes vacuously.
 */
class RefundLockConcurrencyTest extends TestCase
{
    private const SCHOOL  = 'SCH_REFUNDTEST';
    private const SESSION = '2026-27';
    private const STUDENT = 'STU4242';

    private function firebase(string $forceCreate = '', bool $throwOnCreate = false)
    {
        return new class($forceCreate, $throwOnCreate) {
            public array $store = [];
            public array $casAttempts = [];
            private int $clock = 500;

            public function __construct(private string $force, private bool $throwOnCreate) {}

            private function version(): string
            {
                return '2026-08-22T00:00:' . str_pad((string) (++$this->clock), 6, '0', STR_PAD_LEFT) . 'Z';
            }

            public function seed(string $key, array $data): void
            {
                $this->store[$key] = $data + ['__updateTime' => $this->version()];
            }

            public function firestoreCreateStatus($collection, $docId, $data): array
            {
                if ($this->throwOnCreate) throw new RuntimeException('transport exploded');
                if ($this->force !== '') return ['status' => $this->force, 'code' => 0, 'message' => 'forced'];

                $key = "{$collection}/{$docId}";
                if (isset($this->store[$key])) {
                    return ['status' => 'COLLISION', 'code' => 409, 'message' => 'ALREADY_EXISTS'];
                }
                $this->store[$key] = $data + ['__updateTime' => $this->version()];
                return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
            }

            public function firestoreGet($collection, $docId): ?array
            {
                return $this->store["{$collection}/{$docId}"] ?? null;
            }

            public function firestoreDelete($collection, $docId): bool
            {
                unset($this->store["{$collection}/{$docId}"]);
                return true;
            }

            public function firestoreSet($collection, $docId, $data, $merge = false): bool
            {
                $key = "{$collection}/{$docId}";
                $this->store[$key] = ($merge && isset($this->store[$key]))
                    ? array_merge($this->store[$key], $data) : $data;
                $this->store[$key]['__updateTime'] = $this->version();
                return true;
            }

            public function firestoreCommitBatchStatus(array $ops): array
            {
                foreach ($ops as $op) {
                    $key = "{$op['collection']}/{$op['docId']}";
                    $this->casAttempts[] = $key;

                    if (!isset($this->store[$key])) {
                        return ['status' => 'FAILURE', 'code' => 404, 'message' => 'missing'];
                    }
                    $want = (string) ($op['precondition']['updateTime'] ?? '');
                    if ($want === '') {
                        return ['status' => 'UNGUARDED_WRITE', 'code' => 0, 'message' => 'no precondition'];
                    }
                    if ($want !== (string) ($this->store[$key]['__updateTime'] ?? '')) {
                        return ['status' => 'PRECONDITION_CONFLICT', 'code' => 400, 'message' => 'stored version mismatch'];
                    }
                    $this->store[$key] = array_merge($this->store[$key], $op['data']);
                    $this->store[$key]['__updateTime'] = $this->version();
                }
                return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
            }
        };
    }

    private function txn($firebase, string $school = self::SCHOOL): Fee_firestore_txn
    {
        $t = new Fee_firestore_txn();
        $t->init($firebase, new class {}, $school, self::SESSION);
        return $t;
    }

    private function key(string $school = self::SCHOOL, string $student = self::STUDENT): string
    {
        return "feeLocks/{$school}_{$student}";
    }

    /** A lock exactly as FeeCollectionService writes it. */
    private function collectionServiceLock(string $token, int $ageSeconds, int $ttl = 120): array
    {
        return [
            'schoolId'   => self::SCHOOL,
            'userId'     => self::STUDENT,
            'token'      => $token,
            'acquiredAt' => date('c', time() - $ageSeconds),
            'ttlSeconds' => $ttl,
        ];
    }

    // ══ CONTROL — THE HARNESS CAN SHOW THE DEFECT ═══════════════════════════

    /**
     * Drives the ALGORITHM BEING REPLACED against the same double. If this ever
     * reported one winner, every test below would prove nothing.
     */
    public function test_control_the_previous_algorithm_admits_two_holders(): void
    {
        $fb  = $this->firebase();
        $key = $this->key();

        $judge = function (?array $lock): bool {
            if (is_array($lock) && !empty($lock['token'])) {
                if (time() - strtotime((string) ($lock['acquiredAt'] ?? '2000-01-01')) < 120) return false;
            }
            return true;
        };

        $refundSaw  = $fb->store[$key] ?? null;   // READ
        $paymentSaw = $fb->store[$key] ?? null;   // READ — before either writes

        $this->assertTrue($judge($refundSaw));
        $this->assertTrue($judge($paymentSaw),
            'the harness can no longer reproduce the read-then-blind-set race, so the '
            . 'assertions below would be vacuous');
    }

    // ══ EXCLUSION ═══════════════════════════════════════════════════════════

    public function test_two_refunds_on_one_student_yield_exactly_one_owner(): void
    {
        $fb = $this->firebase();

        $a = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);
        $b = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $a['status']);
        $this->assertSame(Fee_firestore_txn::LOCK_CONFLICT, $b['status'],
            'two refunds for the same student both entered the critical section');
        $this->assertSame('', $b['token'], 'a loser must never receive a token');
    }

    /**
     * THE RACE THAT MATTERS MOST. The collection service holds the lock
     * correctly and atomically; the refund must not displace it.
     */
    public function test_a_refund_cannot_clobber_a_live_collection_service_lock(): void
    {
        $fb = $this->firebase();
        $fb->seed($this->key(), $this->collectionServiceLock('FCS_TOKEN', 5));

        $r = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_CONFLICT, $r['status'],
            'the refund displaced a payment that holds the lock — both would then mutate '
            . "the same student's demands concurrently");
        $this->assertSame('FCS_TOKEN', $fb->store[$this->key()]['token'],
            "the live holder's token was overwritten");
        $this->assertSame([], $fb->casAttempts,
            'no CAS should be attempted against a live lock');
    }

    public function test_distinct_students_never_contend(): void
    {
        $fb = $this->firebase();

        $a = $this->txn($fb)->acquireLockStatus('STU_AAA', 120);
        $b = $this->txn($fb)->acquireLockStatus('STU_BBB', 120);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $a['status']);
        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $b['status']);
        $this->assertNotSame($a['token'], $b['token']);
    }

    // ══ UNKNOWN IS NOT CONFLICT ═════════════════════════════════════════════

    public function test_a_transport_failure_is_UNKNOWN_not_CONFLICT(): void
    {
        $r = $this->txn($this->firebase('FAILURE'))->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_UNKNOWN, $r['status'],
            'an undetermined write was reported as a conflict. The refund caller releases '
            . 'its idempotency guard on CONFLICT — doing that when the write MAY have landed '
            . 'is how a refund gets disbursed twice.');
        $this->assertSame('', $r['token']);
    }

    public function test_a_thrown_create_is_UNKNOWN(): void
    {
        $r = $this->txn($this->firebase('', true))->acquireLockStatus(self::STUDENT, 120);
        $this->assertSame(Fee_firestore_txn::LOCK_UNKNOWN, $r['status']);
    }

    public function test_an_unready_helper_is_UNKNOWN_not_CONFLICT(): void
    {
        $r = (new Fee_firestore_txn())->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_UNKNOWN, $r['status'],
            'a misconfigured helper must not masquerade as contention');
    }

    public function test_a_collision_whose_lock_cannot_be_read_is_UNKNOWN(): void
    {
        $r = $this->txn($this->firebase('COLLISION'))->acquireLockStatus(self::STUDENT, 120);
        $this->assertSame(Fee_firestore_txn::LOCK_UNKNOWN, $r['status']);
    }

    // ══ LEASE RECOVERY ══════════════════════════════════════════════════════

    public function test_an_expired_lock_is_recovered_and_reported_as_recovered(): void
    {
        $fb = $this->firebase();
        $fb->seed($this->key(), $this->collectionServiceLock('CRASHED', 600));

        $r = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $r['status'],
            'an expired lock must be recoverable, or a crashed payment wedges the student');
        $this->assertTrue($r['recovered'], 'a takeover must be distinguishable from a clean acquire');
        $this->assertSame('CRASHED', $fb->store[$this->key()]['takenOverFrom']);
    }

    public function test_two_processes_recovering_one_expired_lock_yield_one_owner(): void
    {
        $fb = $this->firebase();
        $fb->seed($this->key(), $this->collectionServiceLock('CRASHED', 600));

        $first  = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);
        $second = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $first['status']);
        $this->assertSame(Fee_firestore_txn::LOCK_CONFLICT, $second['status'],
            'the second recoverer stole a lock the first had already taken — the race was '
            . 'moved into the recovery path rather than removed');
        $this->assertSame($first['token'], $fb->store[$this->key()]['token']);
    }

    /**
     * The recovery must be a real CAS. If the precondition were ever dropped,
     * the double reports UNGUARDED_WRITE, which maps to UNKNOWN rather than
     * success — so this pins the precondition, not merely the outcome.
     */
    public function test_the_recovery_always_carries_a_precondition(): void
    {
        $fb = $this->firebase();
        $fb->seed($this->key(), $this->collectionServiceLock('CRASHED', 600));

        $r = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $r['status'],
            'the takeover was issued as an unguarded write — that is the defect being fixed');
        $this->assertSame([$this->key()], $fb->casAttempts);
    }

    public function test_a_lock_without_a_version_fails_closed(): void
    {
        $fb = $this->firebase();
        // Bypass seed() so no __updateTime is stamped.
        $fb->store[$this->key()] = $this->collectionServiceLock('CRASHED', 600);

        $r = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_UNKNOWN, $r['status'],
            'without a version the takeover must refuse, never write unguarded');
        $this->assertSame([], $fb->casAttempts);
        $this->assertSame('CRASHED', $fb->store[$this->key()]['token']);
    }

    // ══ THE LEASE IS THE DOCUMENT'S, NOT THE CALLER'S ═══════════════════════

    /**
     * A caller passing a SHORTER ttl must not be able to declare a live lock
     * dead. The collection service takes its lock for 120s; a refund asking for
     * 10s must still honour those 120s.
     */
    public function test_a_shorter_caller_ttl_cannot_expire_a_live_lock(): void
    {
        $fb = $this->firebase();
        $fb->seed($this->key(), $this->collectionServiceLock('FCS_TOKEN', 30, 120));

        $r = $this->txn($fb)->acquireLockStatus(self::STUDENT, 10);

        $this->assertSame(Fee_firestore_txn::LOCK_CONFLICT, $r['status'],
            'a caller shortened another writer\'s lease and displaced a live lock');
        $this->assertSame('FCS_TOKEN', $fb->store[$this->key()]['token']);
    }

    public function test_a_lock_with_no_ttl_falls_back_to_the_caller_ttl(): void
    {
        $fb = $this->firebase();
        $lock = $this->collectionServiceLock('OLD', 30);
        unset($lock['ttlSeconds']);
        $fb->seed($this->key(), $lock);

        $this->assertSame(Fee_firestore_txn::LOCK_CONFLICT,
            $this->txn($fb)->acquireLockStatus(self::STUDENT, 120)['status'],
            'a lock 30s old with no stated ttl must still be live under a 120s caller ttl');
    }

    // ══ MALFORMED LOCKS MUST NOT BE IMMORTAL ════════════════════════════════

    public function test_a_lock_with_no_timestamp_is_treated_as_expired(): void
    {
        $fb = $this->firebase();
        $lock = $this->collectionServiceLock('BROKEN', 0);
        unset($lock['acquiredAt']);
        $fb->seed($this->key(), $lock);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER,
            $this->txn($fb)->acquireLockStatus(self::STUDENT, 120)['status'],
            'a malformed lock wedged the student permanently — the previous implementation '
            . "compared against '2000-01-01', i.e. also treated it as expired");
    }

    public function test_an_unparseable_timestamp_is_treated_as_expired(): void
    {
        $fb = $this->firebase();
        $lock = $this->collectionServiceLock('BROKEN', 0);
        $lock['acquiredAt'] = 'not-a-date';
        $fb->seed($this->key(), $lock);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER,
            $this->txn($fb)->acquireLockStatus(self::STUDENT, 120)['status']);
    }

    // ══ TENANT ISOLATION ════════════════════════════════════════════════════

    /**
     * Student ids are GLOBAL. Two tenants can hold a lock for the same student
     * id without contending, and neither may displace the other.
     */
    public function test_the_lock_is_tenant_scoped(): void
    {
        $fb = $this->firebase();

        $a = $this->txn($fb, 'SCH_AAA')->acquireLockStatus(self::STUDENT, 120);
        $b = $this->txn($fb, 'SCH_BBB')->acquireLockStatus(self::STUDENT, 120);

        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $a['status']);
        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $b['status'],
            'the same student id in a DIFFERENT tenant must not contend — Student ID is '
            . 'global, and schoolId is the tenant boundary');
        $this->assertArrayHasKey($this->key('SCH_AAA'), $fb->store);
        $this->assertArrayHasKey($this->key('SCH_BBB'), $fb->store);
    }

    public function test_one_tenant_cannot_release_another_tenants_lock(): void
    {
        $fb = $this->firebase();
        $a  = $this->txn($fb, 'SCH_AAA');
        $b  = $this->txn($fb, 'SCH_BBB');

        $ownerA = $a->acquireLockStatus(self::STUDENT, 120);
        $b->acquireLockStatus(self::STUDENT, 120);

        $this->assertFalse($b->releaseLock(self::STUDENT, $ownerA['token']));
        $this->assertArrayHasKey($this->key('SCH_AAA'), $fb->store,
            "another tenant's release deleted School A's live lock");
    }

    // ══ RELEASE ═════════════════════════════════════════════════════════════

    public function test_release_frees_the_student_for_the_next_operation(): void
    {
        $fb  = $this->firebase();
        $txn = $this->txn($fb);

        $first = $txn->acquireLockStatus(self::STUDENT, 120);
        $this->assertTrue($txn->releaseLock(self::STUDENT, $first['token']));
        $this->assertArrayNotHasKey($this->key(), $fb->store);

        $second = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);
        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $second['status']);
        $this->assertNotSame($first['token'], $second['token']);
    }

    public function test_a_displaced_owner_cannot_release_the_new_owners_lock(): void
    {
        $fb  = $this->firebase();
        $txn = $this->txn($fb);

        $stale = $txn->acquireLockStatus(self::STUDENT, 120);

        // The original hangs until its lease expires.
        $fb->store[$this->key()]['acquiredAt'] = date('c', time() - 600);

        $recovered = $this->txn($fb)->acquireLockStatus(self::STUDENT, 120);
        $this->assertSame(Fee_firestore_txn::LOCK_OWNER, $recovered['status']);

        $this->assertFalse($txn->releaseLock(self::STUDENT, $stale['token']),
            'a displaced owner released a lock it no longer holds');
        $this->assertSame($recovered['token'], $fb->store[$this->key()]['token']);
    }

    // ══ BACKWARD COMPATIBILITY ══════════════════════════════════════════════

    /**
     * The old signature must behave exactly as before, or every existing caller
     * silently changes behaviour.
     */
    public function test_the_legacy_wrapper_still_returns_a_token_or_empty_string(): void
    {
        $fb = $this->firebase();

        $token = $this->txn($fb)->acquireLock(self::STUDENT, 120);
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $second = $this->txn($fb)->acquireLock(self::STUDENT, 120);
        $this->assertSame('', $second, 'a contended legacy acquire must still yield an empty string');
    }

    public function test_the_legacy_wrapper_returns_empty_string_on_unknown_too(): void
    {
        $this->assertSame('', $this->txn($this->firebase('FAILURE'))->acquireLock(self::STUDENT, 120),
            'the legacy contract deliberately still collapses UNKNOWN into ""; callers that '
            . 'must distinguish them use acquireLockStatus()');
    }
}
