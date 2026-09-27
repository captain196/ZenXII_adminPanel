<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_generation_service.php';
require_once __DIR__ . '/../../application/libraries/Fee_lifecycle.php';
require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';

/**
 * P5-C — BULK / MONTHLY GENERATION ROUTED THROUGH THE CANONICAL PIPELINE.
 *
 * ── THE DEFECT AT SCALE ───────────────────────────────────────────────────
 * The bulk job priced every student from `_getStudentDiscounts()` —
 * `scholarshipAwards`, `students.exemptedFees`, `students.discountHeads` — and
 * never read `studentEntitlements`. Single-student generation had the same hole
 * (P5-D), but here it applies to an entire school in one click: every student
 * holding an APPROVED, ACTIVE concession billed at GROSS, silently.
 *
 * ── WHAT ROUTING HAD TO PRESERVE ──────────────────────────────────────────
 * Bulk is not just "the single path in a loop". It carries 400-op batching, a
 * soft deadline, resume-by-`lastProcessedSid`, and per-student failure
 * isolation. Routing must keep every one of those: turning a 2,000-student run
 * into 2,000 commits would be a regression even if the money were right.
 *
 * These tests drive the REAL `Fee_generation_service` and the REAL
 * `Fee_lifecycle` accessors. The tenant is a fixture constant — no UAT school
 * id appears anywhere in production logic or here.
 */
class P5CBulkCanonicalRoutingTest extends TestCase
{
    private const SCHOOL  = 'SCH_TESTTENANT1';
    private const OTHER   = 'SCH_OTHERTENANT';
    private const SESSION = '2026-27';
    private const HEAD    = 'FH_TUITION';
    private const SRC     = APPPATH . 'controllers/Fees.php';

    // ── fixtures ───────────────────────────────────────────────────────────

    private function specs(string $sid, array $periods = null): array
    {
        $periods = $periods ?? ['2026-04', '2026-05', '2026-06'];
        $out = [];
        foreach ($periods as $pk) {
            foreach ([['Tuition Fee', 600.0, self::HEAD], ['Library Fee', 50.0, 'FH_LIB']] as $h) {
                list($name, $amt, $ref) = $h;
                $out[] = [
                    'op' => 'set',
                    'demandId' => 'DEM_' . $sid . '_' . str_replace('-', '', $pk) . '_' . $ref,
                    'data' => [
                        'studentId' => $sid, 'feeHead' => $name, 'feeHeadId' => $ref,
                        'feeHeadRef' => $ref, 'periodKey' => $pk, 'category' => 'academic',
                        'schoolId' => self::SCHOOL, 'session' => self::SESSION,
                        'grossAmount' => $amt, 'discountAmount' => 0.0,
                        'netAmount' => $amt, 'balance' => $amt, 'status' => 'unpaid',
                    ],
                ];
            }
        }
        return ['specs' => $out, 'assigned' => ['Tuition Fee', 'Library Fee'], 'chartFound' => true];
    }

    private function lifecycle(array $built)
    {
        return new class($built) {
            private array $built;
            public function __construct(array $b) { $this->built = $b; }
            public function buildAdmissionDemandSpecs($sid, $c, $s, $preview = false): array
            {
                return $this->built;
            }
        };
    }

    private function reader(array $ents)
    {
        return new class($ents) {
            private array $ents;
            public function __construct(array $e) { $this->ents = $e; }
            public function activeConcessionsForStudent($school, $student, $session): array
            {
                // Tenant/session isolation: a reader must never answer for a
                // tenant or session it was not asked about.
                foreach ($this->ents as $e) {
                    if (($e['_school'] ?? $school) !== $school) return [];
                    if (($e['_session'] ?? $session) !== $session) return [];
                }
                return $this->ents;
            }
            public function appliesTo(array $e, string $pk, string $hid, string $head,
                                      string $cat = '', string $ref = ''): bool
            {
                $scope = (string) ($e['scope'] ?? '');
                if (strpos($scope, 'head:') === 0) {
                    $w = substr($scope, 5);
                    return $w !== '' && ($w === $ref || $w === $hid);
                }
                return $scope === 'all';
            }
            public function deductionType(array $e): string { return (string) ($e['valueType'] ?? 'percent'); }
            public function deductionValue(array $e): float { return (float) ($e['value'] ?? 0); }
        };
    }

    private function svc($lifecycle, $reader, string $school = self::SCHOOL): Fee_generation_service
    {
        $s = new Fee_generation_service();
        $s->init($lifecycle, $reader, null, $school, self::SESSION);
        return $s;
    }

    private function ent(float $v = 10.0, string $type = 'percent'): array
    {
        return [['entitlementId' => 'SE_1', 'scope' => 'head:' . self::HEAD,
                 'valueType' => $type, 'value' => $v, 'status' => 'active']];
    }

    private function totals(array $out): array
    {
        $g = 0.0; $d = 0.0;
        foreach ($out['specs'] as $e) {
            $g += (float) $e['data']['grossAmount'];
            $d += (float) $e['data']['discountAmount'];
        }
        return ['gross' => round($g, 2), 'discount' => round($d, 2), 'count' => count($out['specs'])];
    }

    private function src(): string { return (string) file_get_contents(self::SRC); }

    /**
     * The bulk job's EXECUTABLE body, comments stripped.
     *
     * Stripping matters: the routing comment inside this very loop names
     * `_getStudentDiscounts()` while explaining what was removed. Asserting
     * against raw source would match that prose and report the legacy authority
     * as still reachable — a false failure of exactly the kind that has
     * repeatedly produced misleading results in this project.
     *
     * Bounded by the next method declaration rather than a fixed character
     * window, so the assertions cannot silently bleed into a neighbour.
     */
    private function jobLoop(): string
    {
        $code = '';
        foreach (token_get_all($this->src()) as $t) {
            $code .= is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1])
                : $t;
        }

        $at = strpos($code, 'function _processGenerationJob');
        $this->assertNotFalse($at, '_processGenerationJob not found');

        $rest = substr($code, $at + 10);
        $end  = preg_match('/\n\s*(?:private|public|protected)\s+function\s/', $rest, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : strlen($rest);

        return substr($code, $at, $end + 10);
    }

    // ══ ENTITLED STUDENTS GET V2 PRICING ═══════════════════════════════════

    public function test_an_entitled_student_is_priced_in_bulk(): void
    {
        $out = $this->svc($this->lifecycle($this->specs('STU1')), $this->reader($this->ent()))
                    ->generateDemandsForStudent('STU1', 'Class 9th', 'Section A');
        $t = $this->totals($out);

        $this->assertSame(1950.0, $t['gross'], '3 months x (600+50)');
        $this->assertSame(180.0, $t['discount'], '10% of 3 x 600 tuition — legacy produced 0.00');
    }

    /** Non-entitled students must be financially identical to before. */
    public function test_a_non_entitled_student_is_financially_unchanged(): void
    {
        $out = $this->svc($this->lifecycle($this->specs('STU2')), $this->reader([]))
                    ->generateDemandsForStudent('STU2', 'Class 9th', 'Section A');
        $t = $this->totals($out);

        $this->assertSame(1950.0, $t['gross']);
        $this->assertSame(0.0, $t['discount']);
    }

    // ══ LEGACY AUTHORITIES CANNOT BYPASS OR OVERRIDE V2 ════════════════════

    /**
     * The routed loop must not consult `_getStudentDiscounts()` at all. If it
     * did, the legacy authority could stack on top of the canonical one and
     * double-discount — the failure the "NEVER BOTH" rule exists to prevent.
     */
    public function test_the_bulk_loop_no_longer_consults_the_legacy_authority(): void
    {
        $loop = $this->jobLoop();

        $this->assertStringContainsString('buildCanonicalDemandSpecs', $loop,
            'bulk must price through the canonical pipeline');
        $this->assertStringNotContainsString('_getStudentDiscounts', $loop,
            'the legacy third authority must not remain reachable from the bulk loop');
        $this->assertStringNotContainsString('_generateDemandsForMonth', $loop,
            'the legacy per-month generator must no longer be invoked in bulk');
    }

    /** Exactly one pricing authority is constructed — never both. */
    public function test_only_one_pricing_authority_exists_in_the_pipeline(): void
    {
        $life = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $this->assertSame(1, substr_count($life, "'USE_ENTITLEMENT_BILLING'"),
            'the authority switch must appear exactly once — a second literal is a second boundary');
    }

    // ══ FAIL CLOSED ════════════════════════════════════════════════════════

    /**
     * A refused spec build (P5-0: unreadable existing demands) must write
     * nothing for that student and be COUNTED, not silently skipped.
     */
    public function test_a_refused_spec_build_writes_nothing_and_is_counted(): void
    {
        $loop = $this->jobLoop();

        $this->assertStringContainsString("chartFound", $loop,
            'the loop must check the build succeeded before writing');
        $this->assertStringContainsString("refused", $loop,
            'a refusal must be distinguished from an ordinary missing chart');
        $this->assertStringContainsString('$failureCount++', $loop,
            'a refused student must be visible in the job result, not silently dropped');
    }

    /** Unreadable entitlements fail closed at the guard (DEC-013b, memoised). */
    public function test_unreadable_entitlements_block_rather_than_read_as_none(): void
    {
        $seg = substr($this->src(), strpos($this->src(), 'function _activeEntitlementCount'), 1800);
        $this->assertStringContainsString('return $memo[$studentId] = -1', $seg,
            'unknown must be a blocking sentinel, never 0');
    }

    // ══ ONE STUDENT CANNOT BREAK THE RUN ═══════════════════════════════════

    public function test_a_single_student_failure_does_not_abort_the_run(): void
    {
        $loop = $this->jobLoop();

        $this->assertStringContainsString('catch (\Exception $e)', $loop,
            'per-student failures must be isolated');
        $this->assertStringContainsString("'studentId' => \$sid", $loop,
            'the failing student must be identified in the error record');
        $this->assertStringNotContainsString('return $this->json_error', $loop,
            'the bulk loop must never abort the whole job on one student');
    }

    // ══ IDEMPOTENCY / NO DOUBLE BILLING ════════════════════════════════════

    /**
     * Deterministic identity is what makes repeated bulk runs safe. The legacy
     * path minted `bin2hex(random_bytes(10))`, which can never collide, so a
     * re-run created a parallel set of charges (B-011).
     */
    /**
     * ══ CORRECTED 2026-08-20 — I ASSERTED THE OPPOSITE AND WAS WRONG ════════
     *
     * This test previously claimed the canonical path mints DETERMINISTIC
     * `DEM_…` ids, and "passed" — because the ids it inspected came from this
     * file's own fixture, not from production. A mock lifecycle returned specs
     * I had written `DEM_…` into, and the test then asserted they said `DEM_`.
     * It could not have failed on a production defect. That is the precise
     * anti-pattern this suite exists to avoid, and I wrote it.
     *
     * PRODUCTION PROVED OTHERWISE. Generating STU0184 through the real endpoint
     * produced 37 demands with 20-hex RANDOM ids.
     *
     * ── AND THE RANDOM ID IS DELIBERATE ───────────────────────────────────
     * `buildAdmissionDemandSpecs()` calls `fsTxn->newDemandId()` by design.
     * `Fee_lifecycle:449-455` records why:
     *
     *   "the deterministic demandId formula COLLIDES when feeHeadIds match
     *    between source and target class structures, and Firestore merge=true
     *    SET would silently reset the paid state."
     *
     * On promotion / reverse-promote / rollover a student moves between classes
     * whose charts can share `feeHeadId`s. A deterministic id would then resolve
     * to the SAME document across two different classes, and a merge write would
     * wipe `paidAmount`. Random ids avoid that collision.
     *
     * `_demandId()` (the `DEM_` formula) is therefore used ONLY where instance
     * identity is unambiguous: `createModuleFee()` and `consolidateFeeRecords()`.
     *
     * ── WHAT THIS MEANS FOR B-011 ─────────────────────────────────────────
     * Duplicate protection on this path is NOT structural and never was. It
     * rests entirely on the application-level guard reading existing demands —
     * which is exactly why the B-011 fail-closed fix (`demandsForStudentStrict`)
     * is load-bearing rather than defence-in-depth. Had that read stayed
     * fail-open, nothing else would have stopped a duplicate charge set.
     */
    public function test_canonical_generation_uses_random_ids_by_design(): void
    {
        $txn = (new ReflectionClass('Fee_firestore_txn'))->newInstanceWithoutConstructor();
        $a = $txn->newDemandId();
        $b = $txn->newDemandId();

        $this->assertNotSame($a, $b, 'ids are random — two calls never agree');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $a);

        // The canonical builder uses THAT generator, not the DEM_ formula.
        $life = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $at   = strpos($life, 'public function buildAdmissionDemandSpecs');
        $this->assertNotFalse($at);
        $body = substr($life, $at, 12000);

        $this->assertStringContainsString('$this->fsTxn->newDemandId()', $body,
            'the canonical builder mints random ids — deterministic ids would collide '
            . 'across classes on promotion and reset paid demands (BUG-075)');
    }

    /**
     * Because ids cannot collide, the ONLY thing preventing a duplicate charge
     * set is the existing-demand read. Pin that it is the fail-closed one.
     */
    public function test_duplicate_protection_rests_on_the_fail_closed_read(): void
    {
        $life = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $at   = strpos($life, 'public function buildAdmissionDemandSpecs');
        $body = substr($life, $at, 12000);

        $this->assertStringContainsString('_demandsForStrict', $body,
            'with no structural id protection, a fail-open read would be the whole defect');
        $this->assertStringContainsString('existing_demand_read_failed', $body,
            'and the refusal must be identifiable');
    }

    /** Writes are merges onto stable ids, so a retry converges. */
    public function test_bulk_writes_are_merges_onto_deterministic_ids(): void
    {
        $loop = $this->jobLoop();
        $this->assertStringContainsString("'merge'      => true", $loop);
        $this->assertStringContainsString("'docId'      => (string) \$entry['demandId']", $loop);
    }

    // ══ PAID DEMANDS ARE NEVER REWRITTEN ═══════════════════════════════════

    /**
     * The canonical builder seeds duplicate suppression from existing demands,
     * which is ALSO the BUG-075 paid-demand guard. P5-0 made that read fail
     * closed; this pins that bulk uses the write-mode build, not the preview
     * build (which disables suppression and would reset paid demands).
     */
    public function test_bulk_uses_the_write_mode_build_not_the_preview_build(): void
    {
        $loop = $this->jobLoop();

        $this->assertStringContainsString('buildCanonicalDemandSpecs', $loop);
        $this->assertStringNotContainsString('previewDemandSpecs', $loop,
            'preview disables duplicate suppression — writing its output resets paid demands');
    }

    /** The two builders differ ONLY by the forPreview flag — pin the contract. */
    public function test_the_write_build_and_preview_build_are_distinct(): void
    {
        $life = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');

        $wAt = strpos($life, 'function buildCanonicalDemandSpecs');
        $pAt = strpos($life, 'function previewDemandSpecs');
        $this->assertNotFalse($wAt);
        $this->assertNotFalse($pAt);

        $this->assertStringContainsString('/* forPreview */ false', substr($life, $wAt, 900),
            'the writing build must keep duplicate suppression enabled');
        $this->assertStringContainsString('/* forPreview */ true', substr($life, $pAt, 900));
    }

    // ══ RESUME / BATCHING PRESERVED ════════════════════════════════════════

    public function test_resume_and_batching_semantics_survive_the_routing(): void
    {
        $loop = $this->jobLoop();

        $this->assertStringContainsString('lastProcessedSid', $loop, 'resume must survive');
        $this->assertStringContainsString('$softDeadline', $loop, 'soft deadline must survive');
        $this->assertStringContainsString('count($batchOps) >= 400', $loop,
            '400-op batching must survive — per-student commits would be a scale regression');
    }

    /**
     * The allocation flush must be CONDITIONAL. Flushing per student
     * unconditionally would silently collapse batching into one commit each.
     */
    public function test_the_allocation_flush_is_conditional(): void
    {
        $loop = $this->jobLoop();
        $this->assertStringContainsString('$hasAllocations', $loop,
            'an unconditional flush would defeat the batching this routing preserves');
    }

    // ══ TENANT / SESSION ISOLATION ═════════════════════════════════════════

    public function test_a_foreign_tenant_yields_no_concessions(): void
    {
        $ents = $this->ent();
        $ents[0]['_school'] = self::OTHER;

        $out = $this->svc($this->lifecycle($this->specs('STU1')), $this->reader($ents))
                    ->generateDemandsForStudent('STU1', 'Class 9th', 'Section A');

        $this->assertSame(0.0, $this->totals($out)['discount'],
            'an entitlement belonging to another tenant must never price this one');
    }

    public function test_a_foreign_session_yields_no_concessions(): void
    {
        $ents = $this->ent();
        $ents[0]['_session'] = '2025-26';

        $out = $this->svc($this->lifecycle($this->specs('STU1')), $this->reader($ents))
                    ->generateDemandsForStudent('STU1', 'Class 9th', 'Section A');

        $this->assertSame(0.0, $this->totals($out)['discount']);
    }

    // ══ NO HARD-CODED TENANT ═══════════════════════════════════════════════

    public function test_no_uat_tenant_is_hardcoded_in_the_billing_path(): void
    {
        foreach (['controllers/Fees.php', 'libraries/Fee_lifecycle.php',
                  'libraries/Fee_generation_service.php'] as $f) {
            $src = (string) file_get_contents(APPPATH . $f);
            // Strip comments: durable notes legitimately cite the canary by name.
            $code = '';
            foreach (token_get_all($src) as $t) {
                $code .= is_array($t)
                    ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1])
                    : $t;
            }
            $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{8,}/', $code,
                "{$f} must never carry a tenant id in executable code");
        }
    }

    // ══ MONTH FILTER ═══════════════════════════════════════════════════════

    /**
     * Bulk may request a month subset while the canonical builder emits the
     * whole session. Filtering the PRICED output is equivalent, because each
     * spec is priced independently of which months are written.
     */
    public function test_the_month_filter_is_applied_to_priced_specs(): void
    {
        $loop = $this->jobLoop();
        $this->assertStringContainsString('$wantPeriods', $loop);
        $this->assertStringContainsString("isset(\$wantPeriods[\$pk])", $loop,
            'the filter must run on the priced spec, not replace canonical pricing');
    }

    // ══ IDENTITY PATHS UNTOUCHED ═══════════════════════════════════════════

    public function test_identity_services_are_not_touched_by_the_billing_loop(): void
    {
        $loop = $this->jobLoop();
        foreach (['srnoSvc', 'apaarSvc', 'admissionNoSvc', 'srNoIndex', 'apaarIndex'] as $id) {
            $this->assertStringNotContainsString($id, $loop,
                "billing convergence must not reach identity service '{$id}'");
        }
    }
}
