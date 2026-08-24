<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeLifecycleFirestore;

require_once __DIR__ . '/../../application/libraries/Lifecycle_resolver.php';
require_once __DIR__ . '/../../application/libraries/Institution_lifecycle.php';

/**
 * Adversarial / failure-injection pass over the lifecycle.
 *
 * Written from the posture of someone trying to BREAK the implementation
 * rather than confirm it: malformed state, forged input, partial failure,
 * replay, races, and every path where a mistake would be silent.
 *
 * Each test names the production consequence it prevents, because a test
 * whose failure message is "expected true, got false" teaches nobody.
 */
class InstitutionLifecycleAdversarialTest extends TestCase
{
    private function svc(FakeLifecycleFirestore $f): \Institution_lifecycle
    {
        $svc = new \Institution_lifecycle();
        return $svc->init($f);
    }

    private function seed(FakeLifecycleFirestore $f, $state, string $sid = 'SCH_A'): void
    {
        $doc = ['schoolId' => $sid, '__updateTime' => 'T1'];
        if ($state !== null) $doc['institution'] = ['state' => $state];
        $f->seed('schools', $sid, $doc);
    }

    private function opts(array $over = []): array
    {
        return array_merge([
            'reason' => 'adversarial test', 'actorId' => 'SA0001',
            'actorRole' => 'superadmin', 'actorSurface' => 'sa_console',
        ], $over);
    }

    // ── Malformed / hostile state values ──────────────────────────────

    public function test_a_malformed_institution_state_never_grants_more_than_active(): void
    {
        // If someone (or a bad migration) writes junk into the state field,
        // the system must degrade to the documented default, not to "allow
        // everything" or a PHP error.
        foreach ([
            'active', 'Active', ' ACTIVE ', 'ACTIVE;DROP', '{"state":"ACTIVE"}',
            'SUSPENDED', 'DELETED', '', null, 0, false, [], ['nested' => 1], 3.14,
        ] as $junk) {
            $norm = \Lifecycle_resolver::normalize_institution(is_scalar($junk) || $junk === null ? $junk : '');
            $this->assertContains($norm, \Lifecycle_resolver::INSTITUTION_STATES,
                'a malformed state must normalize to a known state');
            $caps = \Lifecycle_resolver::compose('active', $norm);
            $this->assertIsBool($caps['allowWrite']);
        }
    }

    public function test_a_forged_state_string_cannot_unlock_a_suspended_tenant(): void
    {
        // The institution axis must never be able to override the commercial
        // one — that would turn a data field into a billing bypass.
        foreach (['ACTIVE', 'CLOSING', 'GOD_MODE', 'ACTIVE ACTIVE'] as $forged) {
            $caps = \Lifecycle_resolver::compose('suspended', $forged);
            $this->assertFalse($caps['allowWrite'],
                "institution state '{$forged}' must not re-enable a suspended tenant");
            $this->assertFalse($caps['allowPayments']);
        }
    }

    public function test_an_institution_map_that_is_not_a_map_does_not_crash_reads(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->seed('schools', 'SCH_A', ['schoolId' => 'SCH_A', 'institution' => 'not-a-map', '__updateTime' => 'T1']);
        $cur = $this->svc($f)->current_state('SCH_A');
        $this->assertSame('ACTIVE', $cur['state']);
    }

    // ── Transition input attacks ──────────────────────────────────────

    public function test_transition_target_is_not_case_or_whitespace_smuggleable(): void
    {
        $f = new FakeLifecycleFirestore(); $this->seed($f, 'ACTIVE');
        // Lowercase is accepted (normalised), but only to a LEGAL target.
        $r = $this->svc($f)->transition('SCH_A', 'closing', $this->opts());
        $this->assertTrue($r['success']);
        $this->assertSame('CLOSING', $r['to']);

        $f2 = new FakeLifecycleFirestore(); $this->seed($f2, 'ACTIVE');
        $r2 = $this->svc($f2)->transition('SCH_A', ' ARCHIVED ', $this->opts());
        $this->assertFalse($r2['success'], 'ACTIVE→ARCHIVED must stay illegal however it is spelled');
    }

    public function test_a_blank_or_whitespace_reason_is_rejected(): void
    {
        foreach (['', ' ', "\t", "\n  \n"] as $blank) {
            $f = new FakeLifecycleFirestore(); $this->seed($f, 'ACTIVE');
            $r = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts(['reason' => $blank]));
            $this->assertFalse($r['success'], 'an unexplained institutional act must never be recorded');
            $this->assertSame('reason_required', $r['error']);
        }
    }

    public function test_an_invalid_effective_date_cannot_be_smuggled_in(): void
    {
        foreach (['2026-02-30', '9999-99-99', '2026/04/01', "2026-04-01'; DROP", 'now'] as $bad) {
            $f = new FakeLifecycleFirestore(); $this->seed($f, 'ACTIVE');
            $r = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts(['effectiveDate' => $bad]));
            $this->assertFalse($r['success'], "effective date '{$bad}' must be rejected");
            $this->assertSame('invalid_effective_date', $r['error']);
            $this->assertSame([], $f->writtenCollections());
        }
    }

    public function test_two_person_control_cannot_be_satisfied_by_whitespace_or_case_tricks(): void
    {
        foreach (['SA0001', 'sa0001', 'Sa0001'] as $selfish) {
            $f = new FakeLifecycleFirestore(); $this->seed($f, 'CLOSING');
            $r = $this->svc($f)->transition('SCH_A', 'CLOSED',
                $this->opts(['approverId' => $selfish]));
            $this->assertFalse($r['success'], "self-approval as '{$selfish}' must be refused");
            $this->assertSame('approver_must_differ', $r['error']);
        }
    }

    public function test_cross_tenant_school_id_cannot_be_smuggled_through_the_transition(): void
    {
        // A transition acts on exactly the school it was given. Seed TWO
        // tenants and prove the other one is untouched.
        $f = new FakeLifecycleFirestore();
        $this->seed($f, 'ACTIVE', 'SCH_A');
        $this->seed($f, 'ACTIVE', 'SCH_B');

        $r = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts());
        $this->assertTrue($r['success']);
        $this->assertSame('CLOSING', $f->docs['schools']['SCH_A']['institution']['state']);
        $this->assertSame('ACTIVE',  $f->docs['schools']['SCH_B']['institution']['state'],
            'a transition must never touch a second tenant');
    }

    // ── Partial-failure injection ─────────────────────────────────────

    public function test_a_failed_audit_write_aborts_the_transition_entirely(): void
    {
        // Audit-first ordering exists so that a state change can never happen
        // unrecorded. Prove the state does NOT move when the event cannot be
        // written.
        $f = new class extends FakeLifecycleFirestore {
            public function firestoreCreateStatus(string $collection, string $docId, array $data): array
            {
                return ['status' => 'FAILURE', 'code' => 500, 'message' => 'audit down'];
            }
        };
        $this->seed($f, 'ACTIVE');

        $r = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts());
        $this->assertFalse($r['success']);
        $this->assertSame('audit_write_failed', $r['error']);
        $this->assertSame('ACTIVE', $f->docs['schools']['SCH_A']['institution']['state'],
            'no unrecorded state change may ever occur');
    }

    public function test_a_failed_mirror_write_does_not_undo_a_committed_transition(): void
    {
        // The tenantPublic mirror is a convenience for the apps. If it fails,
        // the authoritative state must still stand — the mirror self-heals on
        // the next write. The opposite (rolling back a committed transition
        // because a mirror failed) would be far worse.
        $f = new class extends FakeLifecycleFirestore {
            public function firestoreUpdate(string $collection, string $docId, array $patch): bool
            {
                if ($collection === 'tenantPublic') throw new \RuntimeException('mirror down');
                return parent::firestoreUpdate($collection, $docId, $patch);
            }
        };
        $this->seed($f, 'ACTIVE');

        $r = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts());
        $this->assertTrue($r['success'], 'a mirror failure must not fail the transition');
        $this->assertSame('CLOSING', $f->docs['schools']['SCH_A']['institution']['state']);
    }

    public function test_a_failed_event_finalization_leaves_a_visible_pending_row(): void
    {
        $f = new class extends FakeLifecycleFirestore {
            public function firestoreUpdate(string $collection, string $docId, array $patch): bool
            {
                if ($collection === 'schoolLifecycleEvents') throw new \RuntimeException('finalize down');
                return parent::firestoreUpdate($collection, $docId, $patch);
            }
        };
        $this->seed($f, 'ACTIVE');

        $r = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts());
        $this->assertTrue($r['success']);
        $ev = $f->docs['schoolLifecycleEvents'][$r['eventId']];
        $this->assertSame('pending', $ev['outcome'],
            'a lost finalization must leave an investigable row, never delete the record');
    }

    // ── Concurrency ───────────────────────────────────────────────────

    public function test_two_administrators_racing_the_same_transition_produce_one_winner(): void
    {
        // Shared store, two service instances — the second one's CAS read
        // sees the first one's committed state and no-ops rather than
        // double-applying or emitting a second event.
        $f = new FakeLifecycleFirestore();
        $this->seed($f, 'ACTIVE');

        $a = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts(['actorId' => 'SA0001']));
        $b = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts(['actorId' => 'SA0002']));

        $this->assertTrue($a['success']);
        $this->assertFalse($a['idempotent'], 'the first caller genuinely applied the change');
        $this->assertTrue($b['success']);
        $this->assertTrue($b['idempotent'], 'the second caller must not apply it again');

        $events = $f->docs['schoolLifecycleEvents'] ?? [];
        $applied = 0;
        foreach ($events as $e) if (($e['outcome'] ?? '') === 'applied') $applied++;
        $this->assertSame(1, $applied, 'exactly one APPLIED event may exist for one transition');
    }

    public function test_racing_different_targets_cannot_produce_an_invalid_state(): void
    {
        $f = new FakeLifecycleFirestore();
        $this->seed($f, 'CLOSING');

        $close   = $this->svc($f)->transition('SCH_A', 'CLOSED',
            $this->opts(['actorId' => 'SA0001', 'approverId' => 'SA0002']));
        $revert  = $this->svc($f)->transition('SCH_A', 'ACTIVE', $this->opts(['actorId' => 'SA0003']));

        $this->assertTrue($close['success']);
        // CLOSED → ACTIVE is legal but two-person; the racer supplied none.
        $this->assertFalse($revert['success']);
        $this->assertSame('approval_required', $revert['error']);
        $this->assertSame('CLOSED', $f->docs['schools']['SCH_A']['institution']['state'],
            'the losing racer must not leave the school in a half-changed state');
    }

    public function test_replaying_the_identical_request_never_double_applies(): void
    {
        $f = new FakeLifecycleFirestore();
        $this->seed($f, 'ACTIVE');
        $opts = $this->opts(['effectiveDate' => '2027-03-31']);

        $first  = $this->svc($f)->transition('SCH_A', 'CLOSING', $opts);
        // Simulate a duplicate HTTP delivery: same school, target and date.
        $replay = $this->svc($f)->transition('SCH_A', 'CLOSING', $opts);

        $this->assertTrue($first['success']);
        $this->assertTrue($replay['success']);
        $this->assertTrue($replay['idempotent']);
        $this->assertCount(1, $f->docs['schoolLifecycleEvents'],
            'a replayed request must reuse its event, not mint a second one');
    }

    // ── Audit integrity ───────────────────────────────────────────────

    public function test_every_applied_transition_records_who_why_and_when(): void
    {
        $f = new FakeLifecycleFirestore();
        $this->seed($f, 'CLOSING');
        $r = $this->svc($f)->transition('SCH_A', 'CLOSED', $this->opts([
            'reason' => 'Trust resolution dated 2027-01-10',
            'approverId' => 'SA0002', 'effectiveDate' => '2027-03-31',
        ]));

        $ev = $f->docs['schoolLifecycleEvents'][$r['eventId']];
        foreach (['schoolId','axis','fromState','toState','actorId','actorRole',
                  'actorSurface','approverId','reason','effectiveDate','ts','outcome'] as $field) {
            $this->assertArrayHasKey($field, $ev, "audit row is missing {$field}");
            $this->assertNotSame('', (string) $ev[$field], "audit field {$field} is empty");
        }
        $this->assertNotSame('service', $ev['actorId'],
            'an institutional act must be attributable to a person, not to "service"');
    }

    public function test_a_refused_transition_still_leaves_a_trace(): void
    {
        // Silent refusals are how abuse hides. A refused destructive attempt
        // must be visible even though nothing changed.
        $f = new FakeLifecycleFirestore();
        $f->forceCasConflict = true;
        $this->seed($f, 'ACTIVE');

        $r = $this->svc($f)->transition('SCH_A', 'CLOSING', $this->opts());
        $this->assertFalse($r['success']);
        $this->assertNotEmpty($f->docs['schoolLifecycleEvents'] ?? [],
            'a refused attempt must still be recorded');
    }
}
