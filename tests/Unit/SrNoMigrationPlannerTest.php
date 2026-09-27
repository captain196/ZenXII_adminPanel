<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Srno_migration_planner.php';

/**
 * SRNO CANONICAL MIGRATION — the plan, and the six things it must never do.
 *
 * The migration converges `srNoIndex.schoolId` and the SRNO counter from the
 * legacy `parent_db_key` namespace ("10012") onto the canonical school id
 * ("SCH_B56BB9A401"). Document ids are ALREADY canonical, which is the whole
 * reason this is safe: identity never moves.
 *
 * Every test below is written against a named production-shaped scenario rather
 * than a synthetic one, because the failure modes that matter here (a school
 * migrated halfway, a counter that lags the register, a school whose code equals
 * its id) are shapes, not edge values.
 */
class SrNoMigrationPlannerTest extends TestCase
{
    private const CANON  = 'SCH_B56BB9A401';
    private const LEGACY = '10012';

    /** @var Srno_migration_planner */
    private $p;

    protected function setUp(): void { $this->p = new Srno_migration_planner(); }

    /** Index rows as production stores them: canonical id, caller-supplied field. */
    private function rows(array $spec): array
    {
        $out = [];
        foreach ($spec as $pair) {
            list($n, $field) = $pair;
            $out[] = ['id' => self::CANON . '_' . $n, 'schoolId' => $field,
                      'srNo' => $n, 'currentStudentId' => 'STU' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)];
        }
        return $out;
    }

    private function counters(array $spec): array
    {
        $out = [];
        foreach ($spec as $key => $v) $out['_sys_SRNO_' . $key] = ['value' => $v];
        return $out;
    }

    /** The live tenant, exactly as audited: 3 rows, all legacy-fielded, counter at 3. */
    private function liveSchool(): array
    {
        return $this->p->plan(self::CANON, self::LEGACY,
            $this->rows([[1, self::LEGACY], [2, self::LEGACY], [3, self::LEGACY]]),
            $this->counters([self::LEGACY => 3]));
    }

    // == SCENARIO 1-5 — the school shapes =================================

    /** S1 — the live tenant. Field backfill of 3, counter seeded to 3. */
    public function test_s1_live_tenant_plans_a_field_backfill_and_a_counter_seed(): void
    {
        $p = $this->liveSchool();
        $this->assertTrue($p['safe']);
        $this->assertFalse($p['noop']);
        $this->assertSame('legacy_only', $p['state']);
        $this->assertSame(3, $p['actions']['updateSchoolIdField']);
        $this->assertSame(['docId' => '_sys_SRNO_' . self::CANON, 'value' => 3],
            $p['actions']['writeCanonicalCounter']);
    }

    /** S2 — a school with no SRNO data at all is a no-op, not an error. */
    public function test_s2_a_school_with_no_srno_data_is_a_noop(): void
    {
        $p = $this->p->plan(self::CANON, self::LEGACY, [], []);
        $this->assertSame('empty', $p['state']);
        $this->assertTrue($p['noop'], 'a fresh school must not be touched at all');
        $this->assertSame(0, $p['actions']['updateSchoolIdField']);
        $this->assertNull($p['actions']['writeCanonicalCounter']);
    }

    /** S3 — a school already fully canonical needs nothing. */
    public function test_s3_an_already_canonical_school_is_a_noop(): void
    {
        $p = $this->p->plan(self::CANON, self::LEGACY,
            $this->rows([[1, self::CANON], [2, self::CANON]]),
            $this->counters([self::CANON => 2]));
        $this->assertSame('canonical_only', $p['state']);
        $this->assertTrue($p['noop']);
    }

    /** S4 — school_code EQUALS school_id: there is no legacy namespace to leave. */
    public function test_s4_a_school_whose_code_equals_its_id_needs_no_migration(): void
    {
        $p = $this->p->plan(self::CANON, self::CANON,
            $this->rows([[1, self::CANON]]), $this->counters([self::CANON => 1]));
        $this->assertNull($p['legacyKey'], 'no separate legacy key should be reported');
        $this->assertTrue($p['noop']);
    }

    /** S5 — a HALF-MIGRATED school: rows under both namespaces. */
    public function test_s5_a_split_school_backfills_only_the_legacy_rows(): void
    {
        $p = $this->p->plan(self::CANON, self::LEGACY,
            $this->rows([[1, self::LEGACY], [2, self::CANON], [3, self::LEGACY]]),
            $this->counters([self::LEGACY => 3]));
        $this->assertSame('split', $p['state']);
        $this->assertSame(2, $p['actions']['updateSchoolIdField'],
            'only the legacy-fielded rows are rewritten; canonical rows are left alone');
    }

    // == SCENARIO 6-8 — the counter ======================================

    /** S6 — CANNOT RESET: the seed is the max of every signal, never the pointer alone. */
    public function test_s6_the_counter_seed_never_regresses_below_the_register(): void
    {
        // A stale pointer (5) behind the true register (9) — the dangerous shape.
        $p = $this->p->plan(self::CANON, self::LEGACY,
            $this->rows([[9, self::LEGACY]]), $this->counters([self::LEGACY => 5]));
        $this->assertSame(9, $p['counterSeed'],
            'seeding from the stale pointer would re-issue SRNO 6-9 to new students');
        $this->assertSame(9, $p['actions']['writeCanonicalCounter']['value']);
    }

    /** S7 — an already-ahead canonical counter is never pulled backwards. */
    public function test_s7_an_existing_canonical_counter_is_never_lowered(): void
    {
        $p = $this->p->plan(self::CANON, self::LEGACY,
            $this->rows([[3, self::LEGACY]]),
            $this->counters([self::LEGACY => 3, self::CANON => 12]));
        $this->assertSame(12, $p['counterSeed']);
        $this->assertNull($p['actions']['writeCanonicalCounter'],
            'nothing to write: the canonical counter already leads');
    }

    /** S8 — the legacy counter is never deleted; it remains the rollback source. */
    public function test_s8_the_legacy_counter_is_left_in_place(): void
    {
        $p = $this->liveSchool();
        $this->assertSame(0, $p['actions']['deleteAnything']);
        $this->assertNotSame('_sys_SRNO_' . self::LEGACY,
            $p['actions']['writeCanonicalCounter']['docId'],
            'the new counter is a NEW document, not a rewrite of the legacy one');
    }

    // == SCENARIO 9-11 — refusal =========================================

    /** S9 — CANNOT COLLIDE: duplicate SRNO values block the plan outright. */
    public function test_s9_duplicate_srno_values_block_the_plan(): void
    {
        $rows = array_merge($this->rows([[1, self::LEGACY]]), $this->rows([[1, self::LEGACY]]));
        $p = $this->p->plan(self::CANON, self::LEGACY, $rows, []);
        $this->assertFalse($p['safe']);
        $this->assertStringContainsString('duplicate_srno_values', implode(' ', $p['blockers']));
        $this->assertNull($p['actions']['writeCanonicalCounter'], 'a blocked plan proposes no writes');
    }

    /** S10 — a doc id that is NOT canonical means the premise fails; refuse. */
    public function test_s10_a_non_canonical_doc_id_blocks_the_plan(): void
    {
        $rows = [['id' => self::LEGACY . '_1', 'schoolId' => self::LEGACY, 'srNo' => 1]];
        $p = $this->p->plan(self::CANON, self::LEGACY, $rows, []);
        $this->assertFalse($p['safe'], 'the whole safety argument rests on canonical doc ids');
        $this->assertStringContainsString('index_doc_id_not_canonical', implode(' ', $p['blockers']));
    }

    /** S11 — a THIRD, unrecognised namespace in the field must stop a human. */
    public function test_s11_an_unrecognised_namespace_blocks_the_plan(): void
    {
        $p = $this->p->plan(self::CANON, self::LEGACY,
            $this->rows([[1, 'SCH_SOMEONE_ELSE']]), []);
        $this->assertFalse($p['safe']);
        $this->assertStringContainsString('unrecognised_schoolId_field', implode(' ', $p['blockers']));
    }

    // == THE SIX SAFETY PROPERTIES =======================================

    /** CANNOT RENUMBER · CANNOT LOSE · CANNOT ORPHAN — no id is ever rewritten. */
    public function test_no_plan_ever_rewrites_a_document_id_or_an_srno_value(): void
    {
        $plans = [
            $this->liveSchool(),
            $this->p->plan(self::CANON, self::LEGACY, $this->rows([[1, self::LEGACY], [2, self::CANON]]),
                $this->counters([self::LEGACY => 2])),
            $this->p->plan(self::CANON, self::LEGACY, [], []),
        ];
        foreach ($plans as $i => $p) {
            $this->assertSame(0, $p['actions']['rewriteDocIds'],   "plan {$i} would move identity");
            $this->assertSame(0, $p['actions']['changeSrNoValues'], "plan {$i} would renumber a student");
            $this->assertSame(0, $p['actions']['deleteAnything'],   "plan {$i} would destroy evidence");
            $this->assertTrue($this->p->isIdempotent($p));
        }
    }

    /** CANNOT DUPLICATE — re-planning after a completed migration proposes nothing. */
    public function test_replanning_after_migration_is_a_noop(): void
    {
        $before = $this->liveSchool();
        $this->assertFalse($before['noop']);

        // Apply the plan in the abstract: fields become canonical, counter seeded.
        $after = $this->p->plan(self::CANON, self::LEGACY,
            $this->rows([[1, self::CANON], [2, self::CANON], [3, self::CANON]]),
            $this->counters([self::LEGACY => 3, self::CANON => $before['counterSeed']]));

        $this->assertTrue($after['noop'], 'running the migration twice must be indistinguishable from once');
        $this->assertSame(0, $after['actions']['updateSchoolIdField']);
        $this->assertNull($after['actions']['writeCanonicalCounter']);
    }

    /** The planner is a PLANNER: it must contain no write path of its own. */
    public function test_the_planner_performs_no_writes(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'libraries/Srno_migration_planner.php')) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        foreach (['firestoreSet', 'firestoreCreate', 'firestoreUpdate', 'firestoreDelete',
                  'batchWrite', 'firestoreQuery', 'firestoreGet'] as $verb) {
            $this->assertStringNotContainsString($verb, $code,
                "the planner must be pure: found '{$verb}'");
        }
    }

    /** A blocked plan must never smuggle an action through. */
    public function test_a_blocked_plan_proposes_no_action_at_all(): void
    {
        $blocked = [
            $this->p->plan('', self::LEGACY, $this->rows([[1, self::LEGACY]]), []),
            $this->p->plan(self::CANON, self::LEGACY, $this->rows([[1, 'OTHER']]), []),
        ];
        foreach ($blocked as $p) {
            $this->assertFalse($p['safe']);
            $this->assertNull($p['actions']['writeCanonicalCounter']);
        }
    }
}
