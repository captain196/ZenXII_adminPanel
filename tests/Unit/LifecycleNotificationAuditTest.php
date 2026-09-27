<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Staff Lifecycle notification audit — point 19 of the production gate.
 *
 * The gate recorded notifications as NOT ASSESSED. This suite is the
 * assessment, and it pins what was found so the picture cannot drift silently.
 *
 * THE HEADLINE FINDING
 * --------------------
 * The entire Staff Lifecycle emits exactly ONE notification:
 * `STAFF_LEAVE_DECIDED`, on leave approve/reject. Resignation, notice
 * decisions, leave submission, leave cancellation, regularization, salary
 * revision, appraisal, onboarding, clearance, exit and rehire are all SILENT.
 *
 * Whether those events *should* notify is product scope — pushing to a real
 * person's phone is a communication decision, not an engineering one — so this
 * suite records the silence rather than filling it.
 *
 * THE ONE GENUINE GAP
 * -------------------
 * `SALARY_PROCESSED` is a FULLY SPECIFIED registry row — `audience: USERS`,
 * `idField: 'staffId'`, `type: 'salary_processed'`, with a title — and has
 * ZERO producers anywhere in the codebase. Payroll runs, payslips are written,
 * and no employee is ever told. The design decision was made and recorded; only
 * the emit is missing.
 *
 * It is deliberately NOT wired here: doing so would start sending notifications
 * to real staff devices, and the trigger point (finalize? publish?) is still a
 * product choice. It is reported instead.
 *
 * A CORRECTION TO AN EARLIER READING
 * ----------------------------------
 * `HOMEWORK_CREATED` looked at first like a dead producer — PHP writes it, and
 * it is not a registry row. It is not a defect: `functions/index.js` carries
 * comments explaining the non-dispatch is DELIBERATE, because the Teacher app
 * writes those documents itself and dispatching them server-side would
 * "DOUBLE-SEND and over-broadcast". The PHP side documents the same. Recorded
 * so nobody else "fixes" it into a double-send.
 */
final class LifecycleNotificationAuditTest extends TestCase
{
    private string $registry;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../functions/index.js';
        if (!is_file($path)) $this->markTestSkipped('functions/index.js not present');

        $src = file_get_contents($path);
        $this->assertNotFalse($src);
        $this->registry = $src;
    }

    /**
     * Every mark the PHP side emits through the sanctioned dispatcher.
     *
     * Uses a recursive iterator, not `glob('** /*.php')` — PHP's glob does not
     * recurse, so that would silently scan nothing and make every assertion
     * built on it vacuous.
     */
    /** @var string[]|null cached across the class — the scan walks the whole app tree. */
    private static ?array $marksCache = null;

    private function emittedMarks(): array
    {
        // Cached deliberately. This walks every PHP file under application/,
        // several of which are multi-megabyte, and four tests need the result.
        // Re-scanning per test exhausted PHP's 512M limit and killed the whole
        // suite — a scan this broad must be paid for once.
        if (self::$marksCache !== null) return self::$marksCache;

        $out = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                __DIR__ . '/../../application',
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $src = @file_get_contents($file->getPathname());
            if ($src === false) continue;
            if (preg_match_all("/emit_push\('([A-Z_]+)'/", $src, $m)) {
                foreach ($m[1] as $mark) $out[$mark] = true;
            }
            unset($src);   // do not hold a multi-MB controller while walking on
        }

        $keys = array_keys($out);
        sort($keys);

        return self::$marksCache = $keys;
    }

    public static function tearDownAfterClass(): void
    {
        self::$marksCache = null;
    }

    /** The scan must actually find something, or every test using it is vacuous. */
    public function test_the_mark_scan_is_not_vacuous(): void
    {
        $emitted = $this->emittedMarks();

        $this->assertNotEmpty($emitted, 'the producer scan found nothing — it is broken');
        $this->assertContains('STAFF_LEAVE_DECIDED', $emitted,
            'the known staff-lifecycle producer must be discoverable by the scan');
        $this->assertGreaterThanOrEqual(6, count($emitted),
            'the codebase has several producers; finding almost none means the scan missed directories');
    }

    // ── The dispatcher contract ──────────────────────────────────────────

    /** One sanctioned dispatcher; an unregistered mark is silently ignored. */
    public function test_an_unregistered_mark_is_dropped_not_delivered(): void
    {
        $this->assertStringContainsString('const spec = MARK_REGISTRY[mark];', $this->registry);
        $this->assertStringContainsString('if (!spec) {', $this->registry,
            'a mark with no registry row is dropped — which is why a missing row is a silent failure');
    }

    /** Enqueue fails closed without a tenant. */
    public function test_emit_push_fails_closed_without_a_school(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/core/MY_Controller.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            'if (!isset($this->fs) || !$this->school_id) return false;',
            $src,
            'a push must never be enqueued without a tenant to scope it to'
        );
    }

    public function test_the_enqueue_is_tenant_stamped(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/core/MY_Controller.php');
        $this->assertNotFalse($src);

        $i = strpos($src, 'protected function emit_push(');
        $body = substr($src, (int) $i, 900);

        $this->assertStringContainsString('(string) $this->school_id,', $body,
            'recipient resolution must be scoped to the emitting tenant');
    }

    // ── The one Staff Lifecycle notification that exists ─────────────────

    public function test_staff_leave_decided_is_registered_and_targeted(): void
    {
        $this->assertMatchesRegularExpression(
            "/STAFF_LEAVE_DECIDED:\s*\{\s*audience:\s*AUDIENCE\.USERS,\s*idField:\s*'staffId'/",
            $this->registry,
            'it must target the individual staff member, never broadcast'
        );
    }

    /** The producer must pass the field the registry resolves recipients from. */
    public function test_its_producer_supplies_the_id_field_the_registry_expects(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);

        $i = strpos($hr, "emit_push('STAFF_LEAVE_DECIDED'");
        $this->assertNotFalse($i, 'the only staff-lifecycle push must still exist');
        $body = substr($hr, (int) $i, 400);

        $this->assertStringContainsString("'staffId'   => \$staffId,", $body,
            "idField is 'staffId'; without it the dispatcher resolves no recipient");
    }

    /** Approve and reject must not collapse into one dedupe key. */
    public function test_the_dedupe_key_distinguishes_approval_from_rejection(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);

        $this->assertStringContainsString(
            "'staffleave_' . \$id . '_' . strtolower((string) \$dec",
            $hr,
            'a shared key would let a rejection suppress a later approval'
        );
    }

    // ── The gap ──────────────────────────────────────────────────────────

    /**
     * `SALARY_PROCESSED` is designed and registered but never emitted. Recorded
     * as incomplete rather than skipped so it stays visible: payroll runs and
     * nobody is told.
     */
    public function test_salary_processed_is_registered_but_never_emitted(): void
    {
        $this->assertStringContainsString('SALARY_PROCESSED:', $this->registry,
            'the row exists — the design decision was made');

        $emitted = $this->emittedMarks();

        if (!in_array('SALARY_PROCESSED', $emitted, true)) {
            $this->markTestIncomplete(
                'DEAD CONSUMER: SALARY_PROCESSED is a fully specified registry row '
                . "(audience USERS, idField 'staffId', type 'salary_processed') with ZERO producers. "
                . 'Payroll runs, payslips are written, and no employee is notified. '
                . 'TRIGGER ANALYSED (2026-08-22): the run lifecycle is generate -> finalize -> '
                . 'approve -> mark_paid, and unlock_payroll reverses BOTH Finalized AND Approved '
                . 'back to Draft, while regenerate_staff_payroll can still change a slip. Only FULL '
                . 'PAYMENT is irreversible. Under the established rule that a person is never '
                . 'notified about a figure that can still change (the same rule behind D-003 and '
                . 'D-007), finalize and approve are both excluded, leaving fully-paid. NOT wired: '
                . 'sending to real staff devices is outward-facing, and "payslip available" vs '
                . '"money paid" is a genuine product choice. See the gate, point 19.'
            );
        }

        $this->assertContains('SALARY_PROCESSED', $emitted);
    }

    /**
     * The lifecycle is otherwise silent. Asserted as a FACT, not a complaint —
     * if any of these gains a notification, this test fails and the audit gets
     * revisited rather than quietly going stale.
     */
    public function test_the_rest_of_the_lifecycle_emits_nothing(): void
    {
        $emitted = $this->emittedMarks();

        foreach ([
            'RESIGNATION_SUBMITTED', 'NOTICE_DECIDED', 'STAFF_LEAVE_SUBMITTED',
            'STAFF_LEAVE_CANCELLED', 'REGULARIZATION_DECIDED', 'SALARY_REVISED',
            'APPRAISAL_SUBMITTED', 'APPRAISAL_REVIEWED', 'ONBOARDING_COMPLETED',
            'CLEARANCE_COMPLETED', 'SETTLEMENT_APPROVED', 'STAFF_EXITED', 'STAFF_REHIRED',
        ] as $mark) {
            $this->assertNotContains($mark, $emitted,
                "'{$mark}' now exists — the notification audit in the gate must be updated");
        }
    }

    // ── The deliberate non-dispatch ──────────────────────────────────────

    /**
     * HOMEWORK_CREATED is written by PHP and NOT dispatched, on purpose. Pinned
     * so it is not "fixed" into a double-send.
     */
    public function test_homework_created_is_deliberately_not_dispatched(): void
    {
        $this->assertStringContainsString('DOUBLE-SEND', $this->registry,
            'the reason for the non-dispatch must stay documented');
        $this->assertStringContainsString('mark=HOMEWORK_CREATED', $this->registry);

        // It appears only in those explanatory comments — never as a row.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*HOMEWORK_CREATED:\s*\{/m',
            $this->registry,
            'adding a registry row here would start the double-send the comments warn about'
        );
    }

    /** Every emitted mark must have somewhere to land. */
    public function test_every_emitted_mark_is_registered(): void
    {
        foreach ($this->emittedMarks() as $mark) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($mark, '/') . ':\s*\{/',
                $this->registry,
                "'{$mark}' is emitted but has no MARK_REGISTRY row — the dispatcher drops it silently"
            );
        }
    }
}
