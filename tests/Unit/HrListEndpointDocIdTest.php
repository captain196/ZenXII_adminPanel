<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REC-D1 — HR list endpoints returned id="" for every row.
 *
 * Firestore_service::where() (and therefore schoolWhere/sessionWhere) returns
 *
 *     [ ['id' => '<schoolId>_JOB0003', 'data' => [...]], ... ]
 *
 * The document id lives on the WRAPPER. The unwrapped data dict does not carry
 * an 'id' key, so `$d['id']` is always ''. Four endpoints read it that way, so
 * every row was emitted with id="". The views fall back to the array index
 * (`var rid = (j && j.id) ? j.id : k`), which meant row actions posted "0"/"1"
 * as the entity id.
 *
 * Observed on the real tenant: View Circular on a posting whose circular had
 * just been created answered "Job not found.", because it called
 * hr/view_circular?job_id=1 and the lookup resolved SCH_..._1.
 *
 * The same failure silently disabled edit and delete on jobs, applicants,
 * appraisals and training records.
 */
final class HrListEndpointDocIdTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/controllers/Hr.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /**
     * DEFECT REC-D1 — every doc-id extraction must consult the wrapper first.
     * Reading only the data dict is the bug.
     */
    public function test_no_endpoint_takes_the_doc_id_from_the_data_dict_alone(): void
    {
        preg_match_all('~\$rawId\s*=\s*\(string\)\s*\(([^;]+)\);~', $this->src, $m, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($m[1], 'no $rawId extraction found — did the endpoints change shape?');

        $bad = [];
        foreach ($m[1] as $i => [$expr, $off]) {
            if (strpos($expr, "\$doc['id']") === false) {
                $line = substr_count(substr($this->src, 0, $off), "\n") + 1;
                $bad[] = "line {$line}: {$expr}";
            }
        }

        $this->assertSame([], $bad,
            "schoolWhere() puts the doc id on the wrapper; the data dict has no 'id'.\n"
            . "Reading it from the data dict yields '' for every row, and the view then\n"
            . "falls back to the array index, so row actions post \"0\"/\"1\".\n"
            . "Offenders:\n  " . implode("\n  ", $bad));
    }

    /** All four known list endpoints must still perform the extraction. */
    public function test_every_list_endpoint_still_resolves_a_doc_id(): void
    {
        foreach (['get_jobs', 'get_applicants', 'get_appraisals', 'get_training_records'] as $fn) {
            $i = strpos($this->src, "public function {$fn}(");
            $this->assertNotFalse($i, "{$fn}() is gone");

            // Bound the search at the next function so we do not read a neighbour's code.
            $next = strpos($this->src, '    public function ', $i + 10);
            $body = substr($this->src, $i, ($next === false ? strlen($this->src) : $next) - $i);

            $this->assertStringContainsString('$rawId', $body,
                "{$fn}() no longer resolves a doc id, so its rows will carry id=\"\"");
            $this->assertStringContainsString("\$doc['id']", $body,
                "{$fn}() must read the doc id from the wrapper");
        }
    }

    /**
     * The school prefix must still be stripped. schoolWhere() returns the FULL
     * document id ({schoolId}_JOB0003); handing that straight back to the view
     * makes getEntity() rebuild it as {schoolId}_{schoolId}_JOB0003.
     */
    public function test_the_school_prefix_is_stripped_from_the_wrapper_id(): void
    {
        $c = preg_match_all('~\$rawId,\s*\$prefix\)\s*===\s*0~', $this->src);
        $this->assertGreaterThanOrEqual(4, $c,
            'each endpoint must strip the {schoolId}_ prefix from the wrapper doc id');
    }

    /**
     * The view's fallback is what turned an empty id into a wrong one. Keep it
     * honest: the fallback exists, but the id it prefers must be j.id.
     */
    public function test_the_view_prefers_the_canonical_id(): void
    {
        $v = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');
        $this->assertMatchesRegularExpression(
            '~var rid\s*=\s*\(j && j\.id\)\s*\?\s*j\.id\s*:\s*k~', $v,
            'loadJobs must key rows by the canonical job id');
    }
}
