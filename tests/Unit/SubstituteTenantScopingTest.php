<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * TT-D2 — cross-tenant read exposure on `substitutes`.
 *
 * firestore.rules keeps an UNSCOPED read arm:
 *
 *     match /substitutes/{docId} { allow read: if isAuth(); }
 *
 * i.e. any authenticated user of ANY tenant may read any school's substitute
 * records. The rule carries a comment explaining why it was left open:
 * "a Teacher-app query filters client-side and would break".
 *
 * That query was DashboardViewModel.loadSubstituteInfo(), which asked for
 * `substitutes where date == today` across every school and discarded other
 * tenants' rows on-device. Client-side discarding is not isolation — the
 * documents still reach the handset, which is exactly the leakage
 * TimetableFirestoreRepository had already been fixed for.
 *
 * With every reader scoped server-side, the rule can be tightened to
 * isSameSchool(). The index `substitutes: schoolId ASC, date ASC` is already
 * DEPLOYED, so the scoping costs nothing.
 *
 * ORDERING CONSTRAINT: the app build must roll out BEFORE the rule is
 * tightened, or handsets running the old build get PERMISSION_DENIED.
 */
final class SubstituteTenantScopingTest extends TestCase
{
    private const TEACHER_SRC = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher';

    /** @return array<string,string> path => source */
    private function kotlinFilesQuerying(string $collection): array
    {
        if (!is_dir(self::TEACHER_SRC)) {
            $this->markTestSkipped('Teacher app source not present on this machine');
        }

        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::TEACHER_SRC));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'kt') continue;
            $src = (string) file_get_contents($f->getPathname());
            if (strpos($src, '"' . $collection . '"') !== false) {
                $out[$f->getPathname()] = $src;
            }
        }
        return $out;
    }

    /**
     * DEFECT TT-D2 — every substitutes query must bind schoolId in the QUERY,
     * not after the documents have already been delivered.
     */
    public function test_every_substitutes_query_scopes_school_server_side(): void
    {
        $files = $this->kotlinFilesQuerying('substitutes');
        $this->assertNotEmpty($files, 'no Teacher-app file queries substitutes any more');

        $bad = [];
        foreach ($files as $path => $src) {
            // Each queryDocuments("substitutes") { ... } builder block.
            if (!preg_match_all(
                '~queryDocuments\(\s*"substitutes"\s*\)\s*\{(.*?)\n\s*\}~s',
                $src, $m
            )) {
                continue;   // references the name but issues no query
            }

            foreach ($m[1] as $builder) {
                if (strpos($builder, 'whereEqualTo("schoolId"') === false) {
                    $bad[] = basename($path) . ': ' . trim(preg_replace('/\s+/', ' ', $builder));
                }
            }
        }

        $this->assertSame([], $bad,
            "These queries fetch every tenant's substitutes and filter on-device.\n"
            . "Client-side filtering is not tenant isolation — the rows still reach\n"
            . "the handset, and it is what keeps firestore.rules' `substitutes` read\n"
            . "arm unscoped (allow read: if isAuth()). Offenders:\n  "
            . implode("\n  ", $bad));
    }

    /**
     * The read arm is TIGHTENED in the repository — and that is only half of
     * the job.
     *
     * This test used to assert `allow read: if isAuth();`, deliberately, as a
     * tripwire that would fire the moment someone tightened the rule. It fired
     * on 2026-08-31 when TT-D2 was applied, and its failure message said what
     * to do: confirm the Teacher-app build carrying the server-side schoolId
     * filter has rolled out first, then delete this test.
     *
     * Only the first half of that is true. The scoped query exists in the
     * Teacher-app working tree; it has NOT been released. So the test is not
     * deleted — it is turned around. It now pins the tightened rule (so a
     * silent revert is caught) and keeps naming the rollout as the outstanding
     * condition, because a tripwire removed while its condition is still open
     * is how an ordering constraint gets forgotten.
     *
     * Whether the build has actually reached handsets is not a fact this
     * repository contains, so nothing here pretends to assert it. What IS
     * asserted is that the rollback path exists: the frozen before-image and
     * the reapplyable patch are both present, so the rule can be put back
     * without hand-editing a shared file if the ordering is got wrong.
     */
    public function test_the_rules_read_arm_is_tightened_and_the_rollback_exists(): void
    {
        $p = __DIR__ . '/../../firebase-rules/firestore.rules';
        $this->assertFileExists($p);
        $rules = (string) file_get_contents($p);

        $i = strpos($rules, 'match /substitutes/{docId}');
        $this->assertNotFalse($i, 'the substitutes rule block is gone');
        $block = substr($rules, $i, 320);

        $this->assertStringContainsString('allow read: if isSameSchool();', $block,
            "The substitutes read arm has been loosened back to an unscoped read.\n"
            . "Any signed-in user of any tenant can then read every school's daily\n"
            . "staff-absence records, naming the absent and covering teachers.");

        // The way back, if the deployment order is got wrong.
        $this->assertFileExists(
            __DIR__ . '/../../firebase-rules/firestore.rules.tt_d2_before',
            'the pre-change ruleset is frozen so the change is reversible and so '
            . 'the leak stays provable by execution rather than by assertion');
        $this->assertFileExists(
            __DIR__ . '/../../firebase-rules/patches/TT-D2-and-TT-C8.patch',
            'the change is preserved as a reapplyable patch, because firestore.rules '
            . "carries other programmes' uncommitted work and cannot be committed");
    }

    /**
     * DEPLOYMENT ORDER — apps first, then the rule.
     *
     * `isSameSchool()` denies an UNSCOPED query outright: Firestore evaluates a
     * list against the rule as a whole, so a query it cannot prove is confined
     * to the caller's tenant fails, whatever the documents contain. The
     * released Teacher dashboard build still sends `where date == today` with
     * no schoolId. Deploying the rule before that build rolls out empties the
     * cover notice for every teacher still on it.
     *
     * Both directions are proven on the real engine in
     * firebase-rules/tests/timetable_overlay_tenant_read.test.js. This test
     * pins the half that lives in this repository: the constraint is written
     * down at the rule itself, where whoever deploys it will be standing.
     */
    public function test_the_deployment_order_is_recorded_at_the_rule(): void
    {
        $rules = (string) file_get_contents(
            __DIR__ . '/../../firebase-rules/firestore.rules');

        $i = strpos($rules, '// ── Substitutes ');
        $this->assertNotFalse($i);
        $block = substr($rules, $i, 1800);

        $this->assertMatchesRegularExpression('~DEPLOYMENT ORDER~i', $block,
            'the ordering constraint belongs next to the rule it constrains, not '
            . 'only in a report nobody reads at deploy time');
        $this->assertMatchesRegularExpression('~apps ship first|not yet released~i', $block);
    }

    /** The scoping must be index-supported, or it fails at runtime. */
    public function test_the_required_index_is_declared(): void
    {
        $p = __DIR__ . '/../../firebase-rules/firestore.indexes.json';
        $this->assertFileExists($p);

        $j = json_decode((string) file_get_contents($p), true);
        $this->assertIsArray($j);

        $found = false;
        foreach ($j['indexes'] ?? [] as $idx) {
            if (($idx['collectionGroup'] ?? '') !== 'substitutes') continue;
            $fields = array_column($idx['fields'] ?? [], 'fieldPath');
            if (in_array('schoolId', $fields, true) && in_array('date', $fields, true)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found,
            'substitutes needs a schoolId+date composite index for the scoped query');
    }
}
