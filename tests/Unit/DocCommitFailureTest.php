<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A failed write must be described by what actually failed.
 *
 * `commitBatch()` returns ONE boolean for every outcome: a 412 precondition
 * (someone really did edit the document), a 400, a 503, and code 0 — curl gave
 * up, usually on the 15 s CURLOPT_TIMEOUT. `save()` read all of them as a
 * conflict.
 *
 * Observed live, and this is the case that matters: a 5,000-object save
 * exceeded the timeout, logged `commitBatch HTTP 0`, and the user was told
 *
 *     E_CONFLICT: '…' changed while this save was in flight. Your edit was NOT
 *     saved and nothing was overwritten. Reload to see the current version.
 *
 * Every clause of which was wrong or unknowable. Nobody had edited it. And
 * "nothing was overwritten" is a claim the code CANNOT make after a timeout —
 * curl stopped waiting, but Firestore may still have applied the write. The
 * user reloads, sees no change, retries, and fails identically.
 *
 * qa/certificates/06-uat-matrix.csv T2-08, T2-11
 */
final class DocCommitFailureTest extends TestCase
{
    private array $docs = [];

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_template_service.php';
    }

    protected function setUp(): void
    {
        $this->docs = ['documentTemplates' => ['SCH1_TPL0001' => [
            'schoolId' => 'SCH1', 'templateId' => 'TPL0001', 'docType' => 'bonafide',
            'name' => 'n', 'status' => 'draft', 'version' => 1, 'lockVersion' => 3,
            'publishedVersion' => null, 'activeVersion' => null,
            '__updateTime' => '2026-09-07T10:00:00Z',
        ]]];
    }

    /** @param int|null $code null = the store cannot say why */
    private function svc(?int $code): Doc_template_service
    {
        $store = [
            'get'    => fn($c, $id) => $this->docs[$c][$id] ?? null,
            'set'    => function ($c, $id, $d) { $this->docs[$c][$id] = $d; return true; },
            'update' => function ($c, $id, $p) { $this->docs[$c][$id] = array_merge($this->docs[$c][$id], $p); return true; },
            'exists' => fn($c, $id) => isset($this->docs[$c][$id]),
            'query'  => fn($c, $w) => $this->docs[$c] ?? [],
            'commit' => fn(array $ops) => false,          // always fails; the WHY is the variable
        ];
        if ($code !== null) {
            $store['commitStatus'] = fn() => ['code' => $code, 'ops' => 1];
        }
        return new Doc_template_service(['store' => $store, 'schoolId' => 'SCH1']);
    }

    private function messageFor(?int $code): string
    {
        try {
            $this->svc($code)->save('SCH1_TPL0001', ['name' => 'x'], 3);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        $this->fail('save() reported success after the commit failed');
    }

    public function test_a_precondition_failure_is_the_only_thing_called_a_conflict(): void
    {
        $m = $this->messageFor(412);
        $this->assertStringContainsString('E_CONFLICT', $m);
        $this->assertStringContainsString('NOT saved', $m);
        $this->assertStringContainsString('nothing was overwritten', $m,
            'On a real 412 the write provably did not land, so the reassurance is earned.');
    }

    public function test_a_timeout_is_not_reported_as_somebody_elses_edit(): void
    {
        $m = $this->messageFor(0);
        $this->assertStringNotContainsString('E_CONFLICT', $m,
            'A timeout told the user a colleague had edited the template. Nobody had.');
        $this->assertStringContainsString('E_TIMEOUT', $m);
    }

    /**
     * The important half. After a timeout the outcome is genuinely unknown, and
     * a reassurance we cannot support is worse than none: it stops the user
     * from looking.
     */
    public function test_a_timeout_never_claims_the_write_did_not_land(): void
    {
        $m = $this->messageFor(0);
        $this->assertStringNotContainsString('nothing was overwritten', $m);
        $this->assertStringContainsStringIgnoringCase('not known', $m,
            'The message must say the outcome is unknown, because it is.');
    }

    public function test_a_refusal_says_retrying_unchanged_will_not_help(): void
    {
        $m = $this->messageFor(400);
        $this->assertStringContainsString('E_WRITE_REFUSED', $m);
        $this->assertStringContainsString('400', $m, 'The code is what a support request needs.');
        $this->assertStringContainsString('fail the same way', $m);
    }

    /** With no information, guess at nothing. */
    public function test_an_unknown_cause_falls_back_without_inventing_one(): void
    {
        $m = $this->messageFor(null);
        $this->assertStringContainsString('could not be saved', $m);
        $this->assertStringNotContainsString('changed while this save was in flight', $m,
            'Claiming a conflict with no evidence for one is the original defect.');
    }

    /** Whatever the cause, a failed save must never look like a successful one. */
    public function test_every_failure_mode_still_throws(): void
    {
        foreach ([412, 0, 400, 503, null] as $code) {
            $this->assertNotSame('', $this->messageFor($code), "code $code did not throw");
        }
    }
}
