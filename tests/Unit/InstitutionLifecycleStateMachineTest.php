<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeLifecycleFirestore;

require_once __DIR__ . '/../../application/libraries/Lifecycle_resolver.php';
require_once __DIR__ . '/../../application/libraries/Institution_lifecycle.php';

/**
 * Institution lifecycle — state machine, two-person control, idempotency,
 * CAS, replay protection, audit ordering and the domain-isolation guarantee.
 *
 * The transition test is EXHAUSTIVE over all 36 ordered (from,to) pairs
 * rather than spot-checking the happy path: a state machine is only as
 * trustworthy as its illegal edges.
 */
class InstitutionLifecycleStateMachineTest extends TestCase
{
    private const STATES = ['PROSPECT', 'ONBOARDING', 'ACTIVE', 'CLOSING', 'CLOSED', 'ARCHIVED'];

    /** The complete legal edge set, written out independently of the source. */
    private const LEGAL = [
        'PROSPECT>ONBOARDING', 'PROSPECT>CLOSED',
        'ONBOARDING>ACTIVE',   'ONBOARDING>CLOSED',
        'ACTIVE>CLOSING',
        'CLOSING>CLOSED',      'CLOSING>ACTIVE',
        'CLOSED>ARCHIVED',     'CLOSED>ACTIVE',
        'ARCHIVED>ACTIVE',
    ];

    // ── Exhaustive edge coverage ──────────────────────────────────────

    public function test_every_ordered_state_pair_matches_the_declared_edge_set(): void
    {
        foreach (self::STATES as $from) {
            foreach (self::STATES as $to) {
                $r   = \Institution_lifecycle::can_transition($from, $to);
                $key = "{$from}>{$to}";

                if ($from === $to) {
                    $this->assertFalse($r['allowed'], "{$key} must not be an 'allowed' transition");
                    $this->assertTrue($r['idempotent'], "{$key} must be reported idempotent");
                    continue;
                }
                $expected = in_array($key, self::LEGAL, true);
                $this->assertSame($expected, (bool) $r['allowed'], "{$key} legality mismatch");
                if (!$expected) {
                    $this->assertSame('illegal_transition', $r['error'], "{$key} should report illegal_transition");
                }
            }
        }
    }

    public function test_the_dangerous_shortcuts_are_all_refused(): void
    {
        // Every one of these has been a real production incident shape in
        // some ERP: skipping the announced-closure window, resurrecting an
        // archive without review, or closing a live school in one step.
        foreach ([
            ['ACTIVE',     'CLOSED'],
            ['ACTIVE',     'ARCHIVED'],
            ['PROSPECT',   'ACTIVE'],
            ['PROSPECT',   'ARCHIVED'],
            ['ONBOARDING', 'CLOSING'],
            ['ONBOARDING', 'ARCHIVED'],
            ['CLOSING',    'ARCHIVED'],
            ['ARCHIVED',   'CLOSED'],
            ['ARCHIVED',   'CLOSING'],
        ] as [$from, $to]) {
            $r = \Institution_lifecycle::can_transition($from, $to);
            $this->assertFalse($r['allowed'], "{$from} → {$to} must be refused");
        }
    }

    public function test_destructive_transitions_require_two_person_control(): void
    {
        foreach ([['CLOSING','CLOSED'], ['CLOSED','ARCHIVED'], ['CLOSED','ACTIVE'], ['ARCHIVED','ACTIVE']] as [$f,$t]) {
            $this->assertTrue(\Institution_lifecycle::requires_two_person($f, $t), "{$f}>{$t} must be two-person");
        }
        foreach ([['PROSPECT','ONBOARDING'], ['ONBOARDING','ACTIVE'], ['ACTIVE','CLOSING'], ['CLOSING','ACTIVE']] as [$f,$t]) {
            $this->assertFalse(\Institution_lifecycle::requires_two_person($f, $t), "{$f}>{$t} must NOT be two-person");
        }
    }

    public function test_unknown_target_state_is_rejected_not_coerced(): void
    {
        foreach (['SUSPENDED', 'DRAFT', 'READY', 'PILOT', 'MIGRATING', ''] as $bogus) {
            $r = \Institution_lifecycle::can_transition('ACTIVE', $bogus);
            $this->assertFalse($r['allowed'], "target '{$bogus}' must not be accepted");
            $this->assertSame('unknown_target_state', $r['error']);
        }
    }

    // ── Determinism / validation helpers ──────────────────────────────

    public function test_event_id_is_deterministic_and_distinct_per_target(): void
    {
        $a = \Institution_lifecycle::event_id('SCH_1', 'CLOSING', '2026-04-01');
        $b = \Institution_lifecycle::event_id('SCH_1', 'CLOSING', '2026-04-01');
        $c = \Institution_lifecycle::event_id('SCH_1', 'CLOSED',  '2026-04-01');
        $d = \Institution_lifecycle::event_id('SCH_2', 'CLOSING', '2026-04-01');
        $e = \Institution_lifecycle::event_id('SCH_1', 'CLOSING', '2026-04-02');

        $this->assertSame($a, $b, 'same inputs must produce the same event id (replay protection)');
        $this->assertNotSame($a, $c);
        $this->assertNotSame($a, $d);
        $this->assertNotSame($a, $e);
        $this->assertDoesNotMatchRegularExpression('#[/\s]#', $a, 'event id must be a valid Firestore doc id');
    }

    public function test_effective_date_validation_rejects_impossible_dates(): void
    {
        foreach (['2026-04-01', '2026-02-28', '2024-02-29'] as $ok) {
            $this->assertTrue(\Institution_lifecycle::valid_effective_date($ok), "{$ok} should be valid");
        }
        foreach (['2026-02-30', '2026-13-01', '01-04-2026', '2026-4-1', '', 'today', '2026-00-10'] as $bad) {
            $this->assertFalse(\Institution_lifecycle::valid_effective_date($bad), "{$bad} should be invalid");
        }
    }

    // ── transition() — I/O behaviour with a fake Firestore ─────────────

    private function svc(FakeLifecycleFirestore $f): \Institution_lifecycle
    {
        $svc = new \Institution_lifecycle();
        return $svc->init($f);
    }

    private function seedSchool(FakeLifecycleFirestore $f, string $state = 'ACTIVE'): void
    {
        $f->seed('schools', 'SCH_T', [
            'schoolId'     => 'SCH_T',
            'institution'  => ['state' => $state],
            '__updateTime' => '2026-08-21T00:00:00Z',
        ]);
    }

    private function opts(array $over = []): array
    {
        return array_merge([
            'reason'       => 'Board approved closure',
            'actorId'      => 'SA0001',
            'actorRole'    => 'superadmin',
            'actorSurface' => 'sa_console',
        ], $over);
    }

    public function test_transition_requires_a_reason(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f);
        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts(['reason' => '   ']));
        $this->assertFalse($r['success']);
        $this->assertSame('reason_required', $r['error']);
        $this->assertSame([], $f->writtenCollections(), 'a rejected transition must write nothing at all');
    }

    public function test_transition_requires_an_identified_actor(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f);
        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts(['actorId' => '']));
        $this->assertFalse($r['success']);
        $this->assertSame('actor_required', $r['error']);
        $this->assertSame([], $f->writtenCollections());
    }

    public function test_two_person_transition_is_refused_without_an_approver(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'CLOSING');
        $r = $this->svc($f)->transition('SCH_T', 'CLOSED', $this->opts());
        $this->assertFalse($r['success']);
        $this->assertSame('approval_required', $r['error']);
        $this->assertSame([], $f->writtenCollections());
    }

    public function test_approver_may_not_be_the_same_person_as_the_requester(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'CLOSING');
        $r = $this->svc($f)->transition('SCH_T', 'CLOSED', $this->opts(['approverId' => 'sa0001']));
        $this->assertFalse($r['success'], 'case-insensitive self-approval must be refused');
        $this->assertSame('approver_must_differ', $r['error']);
    }

    public function test_successful_transition_writes_audit_before_state_and_mirrors_after(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'ACTIVE');
        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts(['effectiveDate' => '2027-03-31']));

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('ACTIVE', $r['from']);
        $this->assertSame('CLOSING', $r['to']);

        // Ordering: the audit row must exist before the state changes, so a
        // crash can never produce a silent state change.
        $createIdx = null; $commitIdx = null;
        foreach ($f->ops as $i => $op) {
            if (strpos($op, 'create:schoolLifecycleEvents/') === 0) $createIdx = $i;
            if (strpos($op, 'commit:schools/') === 0)               $commitIdx = $i;
        }
        $this->assertNotNull($createIdx, 'an event row must be created');
        $this->assertNotNull($commitIdx, 'the state must be committed');
        $this->assertLessThan($commitIdx, $createIdx, 'audit row must be written BEFORE the state write');

        $event = $f->docs['schoolLifecycleEvents'][$r['eventId']];
        $this->assertSame('applied',   $event['outcome']);
        $this->assertSame('SA0001',    $event['actorId']);
        $this->assertSame('2027-03-31', $event['effectiveDate']);
        $this->assertSame('institution', $event['axis']);
        $this->assertSame('Board approved closure', $event['reason']);

        $this->assertSame('CLOSING', $f->docs['schools']['SCH_T']['institution']['state']);
        $this->assertSame('CLOSING', $f->docs['tenantPublic']['SCH_T']['institutionState']);
    }

    public function test_mirror_publishes_the_effective_date_the_apps_read(): void
    {
        // The Parent/Teacher closure notice reads
        // tenantPublic.institutionEffectiveDate to say "closing on <date>".
        // If the mirror does not publish it the apps read null forever and the
        // notice silently degrades to a vague warning — caught by the
        // cross-repo parity suite, pinned here at the source.
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'ACTIVE');
        $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts(['effectiveDate' => '2027-03-31']));

        $mirror = $f->docs['tenantPublic']['SCH_T'];
        $this->assertSame('CLOSING', $mirror['institutionState']);
        $this->assertSame('2027-03-31', $mirror['institutionEffectiveDate'],
            'the apps read this exact field to name the closure date');
    }

    public function test_mirror_carries_no_stale_effective_date_on_other_states(): void
    {
        // A date on ACTIVE/ARCHIVED would be meaningless and could be rendered
        // by an older client as a phantom closure date.
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'CLOSING');
        $this->svc($f)->transition('SCH_T', 'ACTIVE', $this->opts(['effectiveDate' => '2027-03-31']));

        $mirror = $f->docs['tenantPublic']['SCH_T'];
        $this->assertSame('ACTIVE', $mirror['institutionState']);
        $this->assertNull($mirror['institutionEffectiveDate'] ?? null,
            'a cancelled closure must not leave a closure date behind');
    }

    public function test_repeating_the_same_transition_is_an_idempotent_noop(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'CLOSING');
        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts());
        $this->assertTrue($r['success']);
        $this->assertTrue($r['idempotent']);
        $this->assertSame([], $f->writtenCollections(), 'a no-op must not emit a second audit event');
    }

    public function test_a_replayed_request_does_not_transition_twice(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'ACTIVE');
        $f->forceCreateCollision = true;   // the event row already exists

        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts());
        $this->assertTrue($r['success']);
        $this->assertTrue($r['idempotent'], 'a replay must be reported as idempotent');
        $this->assertNotContains('schools', $f->writtenCollections(), 'a replay must not rewrite the state');
    }

    public function test_concurrent_transition_loses_deterministically_and_marks_the_event_rejected(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seedSchool($f, 'ACTIVE');
        $f->forceCasConflict = true;

        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts());
        $this->assertFalse($r['success']);
        $this->assertSame('cas_failed', $r['error']);

        $eventId = \Institution_lifecycle::event_id('SCH_T', 'CLOSING', date('Y-m-d'));
        $this->assertSame('rejected_conflict', $f->docs['schoolLifecycleEvents'][$eventId]['outcome'],
            'the losing attempt must be recorded, not silently discarded');
        $this->assertSame('ACTIVE', $f->docs['schools']['SCH_T']['institution']['state'],
            'the state must be unchanged after a lost race');
    }

    public function test_state_write_carries_a_cas_precondition(): void
    {
        $f = new class extends FakeLifecycleFirestore {
            public $sawPrecondition = null;
            public function firestoreCommitBatchStatus(array $ops): array
            {
                $this->sawPrecondition = $ops[0]['precondition'] ?? null;
                return parent::firestoreCommitBatchStatus($ops);
            }
        };
        $this->seedSchool($f, 'ACTIVE');
        $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts());
        $this->assertSame(['updateTime' => '2026-08-21T00:00:00Z'], $f->sawPrecondition,
            'the state write must be conditional on the version that was validated');
    }

    public function test_transition_aborts_when_the_state_changed_under_us(): void
    {
        // TOCTOU: the pre-check saw ACTIVE, but by commit time another admin
        // has already moved the school to CLOSING. The mutator re-validates
        // against the FRESH document and must abort rather than overwrite.
        $f = new class extends FakeLifecycleFirestore {
            public $reads = 0;
            public function firestoreGet(string $collection, string $docId)
            {
                $doc = parent::firestoreGet($collection, $docId);
                if ($collection === 'schools') {
                    $this->reads++;
                    if ($this->reads > 1) {
                        // second read (inside the CAS loop) sees a changed world
                        $doc['institution']['state'] = 'CLOSING';
                    }
                }
                return $doc;
            }
        };
        $this->seedSchool($f, 'ACTIVE');

        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts());
        // ACTIVE→CLOSING where fresh is already CLOSING is a clean no-op…
        $this->assertTrue($r['success']);
        $this->assertTrue($r['idempotent']);
        $this->assertSame('ACTIVE', $f->docs['schools']['SCH_T']['institution']['state'],
            'a no-op must not rewrite the document');
    }

    public function test_transition_is_refused_when_fresh_state_makes_it_illegal(): void
    {
        $f = new class extends FakeLifecycleFirestore {
            public $reads = 0;
            public function firestoreGet(string $collection, string $docId)
            {
                $doc = parent::firestoreGet($collection, $docId);
                if ($collection === 'schools') {
                    $this->reads++;
                    if ($this->reads > 1) {
                        // fresh world: already ARCHIVED, so ACTIVE→CLOSING is void
                        $doc['institution']['state'] = 'ARCHIVED';
                    }
                }
                return $doc;
            }
        };
        $this->seedSchool($f, 'ACTIVE');

        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts());
        $this->assertFalse($r['success']);
        $this->assertSame('state_changed', $r['error']);
        // The fake reports the changed world on re-read without mutating its
        // store, so the assertion that matters is that WE never wrote: no
        // commit to schools happened, so the other administrator's transition
        // could not have been clobbered.
        $this->assertNotContains('schools', $f->writtenCollections(),
            'an aborted transition must not write the schools document at all');

        $eventId = \Institution_lifecycle::event_id('SCH_T', 'CLOSING', date('Y-m-d'));
        $this->assertSame('failed', $f->docs['schoolLifecycleEvents'][$eventId]['outcome'],
            'the refused attempt must still be recorded');
    }

    // ── The central safety property ───────────────────────────────────

    public function test_lifecycle_never_writes_to_a_domain_collection(): void
    {
        $allowed = ['schools', 'tenantPublic', 'schoolLifecycleEvents'];

        foreach ([['PROSPECT','ONBOARDING',[]], ['ONBOARDING','ACTIVE',[]], ['ACTIVE','CLOSING',[]],
                  ['CLOSING','CLOSED',['approverId'=>'SA0002']], ['CLOSED','ARCHIVED',['approverId'=>'SA0002']],
                  ['ARCHIVED','ACTIVE',['approverId'=>'SA0002']]] as [$from, $to, $extra]) {
            $f = new FakeLifecycleFirestore();
            $this->seedSchool($f, $from);
            $r = $this->svc($f)->transition('SCH_T', $to, $this->opts($extra));
            $this->assertTrue($r['success'], "{$from} → {$to} should succeed: " . $r['message']);
            foreach ($f->writtenCollections() as $col) {
                $this->assertContains($col, $allowed,
                    "lifecycle wrote to '{$col}' during {$from} → {$to}; it must never touch domain data");
            }
        }
    }

    public function test_an_unreadable_school_never_transitions(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->throwOnRead = true;
        $r = $this->svc($f)->transition('SCH_T', 'CLOSING', $this->opts());
        $this->assertFalse($r['success']);
        $this->assertSame('school_not_found', $r['error']);
        $this->assertSame([], $f->writtenCollections());
    }

    public function test_a_school_with_no_institution_block_is_treated_as_active(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->seed('schools', 'SCH_LEGACY', ['schoolId' => 'SCH_LEGACY', '__updateTime' => 'T1']);
        $cur = $this->svc($f)->current_state('SCH_LEGACY');
        $this->assertSame('ACTIVE', $cur['state']);
        $this->assertFalse($cur['explicit'], 'the console must be able to tell "never set" from "set to ACTIVE"');
        $this->assertTrue($cur['known']);
    }
}
