<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every HR query the apps issue must have an index that can serve it.
 *
 * A missing composite index is not a slow query — Firestore refuses it outright
 * with FAILED_PRECONDITION. The screen shows an error or an empty list, and
 * nothing in the PHP suite or the Kotlin suite notices, because neither runs
 * against a real Firestore. Deleting an index is therefore a silent, total
 * break of a feature that will look perfectly fine in code review.
 *
 * This pins the shapes actually required, derived from the queries themselves:
 *
 *   appraisals        schoolId == , staffId == , orderBy(updatedAt DESC)
 *                     -> NEEDS a composite. An orderBy on a field other than
 *                        the equality fields cannot be served by index merging.
 *
 *   salarySlips       schoolId == , staffId == , type == 'payslip'
 *   leaveApplications schoolId == , applicantId == , applicantType == 'staff'
 *   hrJobs            schoolId == , statusLower == 'open'
 *                     -> equality-only; a composite whose PREFIX matches serves
 *                        them, and index merging could too. The composites
 *                        exist, so the prefix is what is asserted.
 *
 * NOT asserted: that every collection has an index. Firestore serves
 * multi-equality queries by merging single-field indexes, so demanding a
 * composite everywhere would add cost for nothing — the standing rule is to
 * prove the query shape first.
 *
 * `firestore.indexes.json` is concurrently edited by other programmes, so this
 * suite only READS it.
 */
final class HrIndexQueryShapeTest extends TestCase
{
    private array $indexes = [];

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../firebase-rules/firestore.indexes.json';
        $raw  = @file_get_contents($path);
        if ($raw === false) $this->markTestSkipped('firestore.indexes.json not present');

        $json = json_decode($raw, true);
        $this->assertIsArray($json, 'firestore.indexes.json must be valid JSON');
        $this->assertArrayHasKey('indexes', $json);

        $this->indexes = $json['indexes'];
    }

    /** Field paths of every index on a collection, in order. */
    private function shapesFor(string $collection): array
    {
        $out = [];
        foreach ($this->indexes as $idx) {
            if (($idx['collectionGroup'] ?? '') !== $collection) continue;
            $out[] = array_map(
                static fn($f) => ($f['fieldPath'] ?? '') . ':' . strtoupper(substr((string) ($f['order'] ?? ''), 0, 4)),
                $idx['fields'] ?? []
            );
        }
        return $out;
    }

    /** True when some index begins with exactly $prefix. */
    private function hasPrefix(string $collection, array $prefix): bool
    {
        foreach ($this->shapesFor($collection) as $shape) {
            if (array_slice($shape, 0, count($prefix)) === $prefix) return true;
        }
        return false;
    }

    // ── The one query that genuinely REQUIRES a composite ────────────────

    /**
     * `HRFirestoreRepository.getMyAppraisals()` orders by updatedAt while
     * filtering on two other fields. Index merging cannot serve that, so the
     * composite is mandatory: without it the ESS appraisal screen fails
     * outright rather than degrading.
     */
    public function test_appraisals_has_the_composite_its_orderby_requires(): void
    {
        $this->assertTrue(
            $this->hasPrefix('appraisals', ['schoolId:ASCE', 'staffId:ASCE', 'updatedAt:DESC']),
            'getMyAppraisals() filters schoolId + staffId and orders by updatedAt DESC; '
            . 'without this composite the query is REFUSED, not merely slow'
        );
    }

    // ── Equality-only queries: a matching prefix is enough ───────────────

    public function test_payslips_are_servable(): void
    {
        $this->assertTrue(
            $this->hasPrefix('salarySlips', ['schoolId:ASCE', 'staffId:ASCE', 'type:ASCE']),
            "getMySalarySlips() filters schoolId + staffId + type == 'payslip'"
        );
    }

    public function test_staff_leave_history_is_servable(): void
    {
        $this->assertTrue(
            $this->hasPrefix('leaveApplications', ['schoolId:ASCE', 'applicantId:ASCE', 'applicantType:ASCE']),
            "getLeaveHistory() filters schoolId + applicantId + applicantType == 'staff'"
        );
    }

    public function test_job_openings_are_servable(): void
    {
        $this->assertTrue(
            $this->hasPrefix('hrJobs', ['schoolId:ASCE', 'statusLower:ASCE']),
            "getRecruitmentOpenings() filters schoolId + statusLower == 'open'"
        );
    }

    // ── Tenant scoping of the indexes themselves ─────────────────────────

    /**
     * Every HR index must lead with schoolId, or it describes a query that
     * could span tenants. The one exception present today leads with
     * applicantType and carries schoolId second, which still constrains the
     * tenant — so the assertion is containment, not position.
     */
    public function test_every_hr_index_constrains_the_tenant(): void
    {
        foreach (['appraisals', 'salarySlips', 'leaveApplications', 'hrJobs'] as $col) {
            foreach ($this->shapesFor($col) as $shape) {
                $paths = array_map(static fn($f) => explode(':', $f)[0], $shape);
                $this->assertContains(
                    'schoolId',
                    $paths,
                    "an index on {$col} without schoolId describes a cross-tenant query: "
                    . implode(' + ', $paths)
                );
            }
        }
    }

    // ── Guard against silent removal ─────────────────────────────────────

    /**
     * Counts are asserted as minimums, not exact values: another programme may
     * legitimately add an index. Removal is what breaks a feature.
     */
    public function test_the_hr_collections_keep_their_indexes(): void
    {
        foreach ([
            'appraisals'        => 1,
            'salarySlips'       => 2,
            'leaveApplications' => 3,
            'hrJobs'            => 1,
        ] as $col => $min) {
            $this->assertGreaterThanOrEqual(
                $min,
                count($this->shapesFor($col)),
                "{$col} lost an index — an app query may now be refused at runtime"
            );
        }
    }

    /** Index definitions must be well-formed, or the deploy fails. */
    public function test_hr_index_definitions_are_well_formed(): void
    {
        foreach (['appraisals', 'salarySlips', 'leaveApplications', 'hrJobs'] as $col) {
            foreach ($this->shapesFor($col) as $shape) {
                $this->assertNotEmpty($shape, "{$col} has an index with no fields");
                foreach ($shape as $f) {
                    [$path, $order] = array_pad(explode(':', $f), 2, '');
                    $this->assertNotSame('', $path, "{$col} index field has no fieldPath");
                    $this->assertContains($order, ['ASCE', 'DESC'],
                        "{$col} index field {$path} has an unusable order '{$order}'");
                }
            }
        }
    }

    /**
     * The queries this suite encodes must match the app source it claims to
     * describe — otherwise it pins a shape nobody issues.
     */
    public function test_the_asserted_shapes_match_the_teacher_app_queries(): void
    {
        $repo = @file_get_contents(
            'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/'
            . 'data/repository/firestore/HRFirestoreRepository.kt'
        );
        if ($repo === false) $this->markTestSkipped('Teacher app source not present on this machine');

        $this->assertStringContainsString('.orderBy("updatedAt", Query.Direction.DESCENDING)', $repo,
            'the appraisals composite exists to serve this orderBy');
        $this->assertStringContainsString('.whereEqualTo("statusLower", "open")', $repo);
        $this->assertStringContainsString('.whereEqualTo("type", "payslip")', $repo);
    }
}
