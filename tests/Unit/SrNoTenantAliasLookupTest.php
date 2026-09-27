<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Sr_no_service.php';

/**
 * SRNO — the dual-namespace counter lookup (forensic audit, step 1).
 *
 * THE LATENT DEFECT THIS CLOSES. `srNoIndex` is PARTIALLY MIGRATED: its document
 * ids are already canonical (`SCH_XXXX_{n}`), but the stored `schoolId` FIELD
 * carries whatever the caller passed. `Sis::_claimIdentityAttributes` passes
 * `parent_db_key` (the login code, e.g. "10012"); `Readmission` passes the
 * canonical id. Two entry points, two namespaces, one collection.
 *
 * `_findMaxExistingSrNoForSchool()` queries that FIELD, so a single-namespace
 * lookup returns 0 for the namespace the rows were NOT written under:
 *
 *     pointer 0 -> candidate 1 -> claim SCH_XXXX_1 -> ALREADY EXISTS -> retry...
 *
 * With COUNTER_MAX_ATTEMPTS = 15 that survives while a school has <= 14 SRNOs
 * and then REFUSES the admission with `contention_exhausted`. Invisible in
 * testing; certain after rollout as registers grow.
 *
 * No SRNO can ever be DUPLICATED by this — uniqueness lives in the canonical doc
 * id, which contains no namespace — so the fix is a widened READ, not a data
 * migration. Backfilling the field and re-keying the counter remain separate,
 * protected operations.
 *
 * Live state when written: 3 `srNoIndex` docs, ids `SCH_B56BB9A401_1..3`,
 * every `schoolId` field = "10012".
 */
class SrNoTenantAliasLookupTest extends TestCase
{
    private const CANON  = 'SCH_B56BB9A401';
    private const LEGACY = '10012';

    /** Firestore double: rows carry the LEGACY field, exactly as production does. */
    private function fb(array $rows, $schoolDoc)
    {
        return new class($rows, $schoolDoc) {
            public array $queried = [];
            private array $rows;
            private $schoolDoc;
            public function __construct(array $rows, $schoolDoc)
            {
                $this->rows = $rows; $this->schoolDoc = $schoolDoc;
            }
            public function firestoreGet(string $col, string $id)
            {
                return $col === 'schools' ? $this->schoolDoc : null;
            }
            public function firestoreQuery(string $col, array $conds)
            {
                $want = null;
                foreach ($conds as $c) if (($c[0] ?? '') === 'schoolId') $want = $c[2];
                $this->queried[] = (string) $want;
                $out = [];
                foreach ($this->rows as $r) if (($r['schoolId'] ?? null) === $want) $out[] = ['data' => $r];
                return $out;
            }
        };
    }

    private function svc($fb, $fs = null): Sr_no_service
    {
        $s = (new ReflectionClass('Sr_no_service'))->newInstanceWithoutConstructor();
        (function () use ($fb, $fs) {
            $this->firebase = $fb;
            $this->fs       = $fs;
            $this->schoolId = 'SCH_B56BB9A401';
            $this->actorId  = 'SSA0011';
        })->call($s);
        return $s;
    }

    private function findMax(Sr_no_service $s, string $schoolId): int
    {
        $m = new ReflectionMethod('Sr_no_service', '_findMaxExistingSrNoForSchool');
        $m->setAccessible(true);
        return (int) $m->invoke($s, $schoolId);
    }

    /** The live shape: 3 rows stored under the LEGACY field. */
    private function liveRows(): array
    {
        return [
            ['srNo' => 1, 'schoolId' => self::LEGACY],
            ['srNo' => 2, 'schoolId' => self::LEGACY],
            ['srNo' => 3, 'schoolId' => self::LEGACY],
        ];
    }

    // == THE DEFECT ======================================================

    /**
     * THE HEADLINE: asked with the CANONICAL id it must still find rows written
     * under the legacy key. Pre-fix this returned 0.
     */
    public function test_canonical_lookup_finds_rows_written_under_the_legacy_key(): void
    {
        $fb = $this->fb($this->liveRows(), ['schoolCode' => self::LEGACY]);
        $this->assertSame(3, $this->findMax($this->svc($fb), self::CANON),
            'returning 0 here is what burns 15 attempts and then refuses an admission');
        $this->assertContains(self::LEGACY, $fb->queried, 'the legacy namespace must be consulted');
    }

    /** Asked with the LEGACY key it still works — that is today's caller. */
    public function test_legacy_lookup_still_works(): void
    {
        $fb = $this->fb($this->liveRows(), null);
        $fs = new class { public function schoolId(): string { return 'SCH_B56BB9A401'; } };
        $this->assertSame(3, $this->findMax($this->svc($fb, $fs), self::LEGACY));
    }

    // == school_code == school_id, AND absent =============================

    /** A school whose code EQUALS its id queries once and behaves as before. */
    public function test_identical_code_and_id_is_unchanged(): void
    {
        $fb = $this->fb([['srNo' => 7, 'schoolId' => self::CANON]], ['schoolCode' => self::CANON]);
        $this->assertSame(7, $this->findMax($this->svc($fb), self::CANON));
        $this->assertSame([self::CANON], $fb->queried, 'no redundant second query');
    }

    /** A school with NO schoolCode is unaffected. */
    public function test_a_school_without_a_code_is_unaffected(): void
    {
        $fb = $this->fb([['srNo' => 5, 'schoolId' => self::CANON]], ['name' => 'X']);
        $this->assertSame(5, $this->findMax($this->svc($fb), self::CANON));
        $this->assertSame([self::CANON], $fb->queried);
    }

    // == THE MAX IS THE MAX ACROSS BOTH ==================================

    /** Rows split across BOTH namespaces yield the true maximum. */
    public function test_the_maximum_spans_both_namespaces(): void
    {
        $rows = [
            ['srNo' => 3,  'schoolId' => self::LEGACY],
            ['srNo' => 11, 'schoolId' => self::CANON],
            ['srNo' => 7,  'schoolId' => self::LEGACY],
        ];
        $fb = $this->fb($rows, ['schoolCode' => self::LEGACY]);
        $this->assertSame(11, $this->findMax($this->svc($fb), self::CANON),
            'a split register must not restart numbering from the lower namespace');
    }

    /** No rows anywhere still means zero — a genuinely new school. */
    public function test_a_new_school_starts_at_zero(): void
    {
        $fb = $this->fb([], ['schoolCode' => self::LEGACY]);
        $this->assertSame(0, $this->findMax($this->svc($fb), self::CANON));
    }

    // == FAILURE MODES ===================================================

    /** An unreadable school doc degrades to single-namespace behaviour. */
    public function test_an_unreadable_school_doc_degrades_safely(): void
    {
        $fb = new class {
            public function firestoreGet(string $c, string $i) { throw new \RuntimeException('down'); }
            public function firestoreQuery(string $c, array $x)
            {
                return [['data' => ['srNo' => 4, 'schoolId' => 'SCH_B56BB9A401']]];
            }
        };
        $this->assertSame(4, $this->findMax($this->svc($fb), self::CANON),
            'a lookup failure must not make the counter restart at zero');
    }

    /** A failing query on one namespace must not lose the other's result. */
    public function test_one_failing_namespace_does_not_lose_the_other(): void
    {
        $fb = new class {
            public function firestoreGet(string $c, string $i) { return ['schoolCode' => '10012']; }
            public function firestoreQuery(string $c, array $x)
            {
                $want = null;
                foreach ($x as $cc) if (($cc[0] ?? '') === 'schoolId') $want = $cc[2];
                if ($want === '10012') throw new \RuntimeException('transient');
                return [['data' => ['srNo' => 9, 'schoolId' => 'SCH_B56BB9A401']]];
            }
        };
        $this->assertSame(9, $this->findMax($this->svc($fb), self::CANON));
    }

    // == NOTHING WAS MIGRATED ============================================

    /** This step widens a READ. It must not write or move anything. */
    public function test_the_fix_performs_no_writes(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'libraries/Sr_no_service.php')) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $at  = strpos($code, 'function _tenantAliases');
        $this->assertNotFalse($at);
        $seg = substr($code, $at, 900);
        foreach (['firestoreSet', 'firestoreCreate', 'firestoreDelete', 'batchWrite'] as $verb) {
            $this->assertStringNotContainsString($verb, $seg,
                "the alias resolver must only READ: found '{$verb}'");
        }
    }

    /** Identity still lives in the doc id — that is why no data move is needed. */
    public function test_uniqueness_still_comes_from_the_canonical_doc_id(): void
    {
        $code = (string) file_get_contents(APPPATH . 'libraries/Sr_no_service.php');
        $this->assertStringContainsString("\$docId = \$this->fs->docId(\$n['norm']);", $code,
            'the claim doc id must stay canonical and namespace-free');
        $this->assertStringContainsString('firestoreCreate(self::COL, $docId', $code,
            'create-if-not-exists on that id is what makes duplicates impossible');
    }
}
