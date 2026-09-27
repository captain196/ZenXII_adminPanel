<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('FCPATH')) define('FCPATH', realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR);

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  MIGRATION SAFETY — scripts/migrate_payment_providers.js
 * ══════════════════════════════════════════════════════════════════════════
 *
 * This script is the one thing in the programme that will eventually be
 * pointed at a LIVE tenant's real payment configuration. Its safety
 * properties are therefore load-bearing, and until now nothing guarded them:
 * a future edit could turn `create()` into `set()`, or make writing the
 * default, and every other test in this programme would stay green.
 *
 * ── WHY THIS FILE IS SOURCE-BASED, STATED PLAINLY ────────────────────────
 * The script is Node + firebase-admin and reaches a real Firestore project.
 * Executing it inside PHPUnit would either need live credentials (forbidden)
 * or a fake so elaborate it would prove nothing about the real script. The
 * DRY-RUN was executed separately and its output recorded in the
 * certification; what this file pins are the invariants that make `--commit`
 * safe to authorise later.
 *
 * Where behaviour CAN be checked without credentials, it is: the dry-run
 * default is proven by parsing the actual flag logic, not by reading a
 * comment about it.
 */
class PaymentProviderMigrationSafetyTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $path = FCPATH . 'scripts/migrate_payment_providers.js';
        self::$src = file_exists($path) ? (string) file_get_contents($path) : '';
    }

    /** Strips // and /* comments so the assertions below read CODE. */
    private function executable(): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', self::$src);
        $s = preg_replace('~^\s*//.*$~m', '', (string) $s);
        return (string) $s;
    }

    public function test_the_migration_script_exists(): void
    {
        $this->assertNotSame('', self::$src,
            'scripts/migrate_payment_providers.js is missing — the documented migration '
            . 'procedure references a script that does not exist');
    }

    // ══ WRITING MUST BE OPT-IN ════════════════════════════════════════════

    /**
     * THE most important property. If writing ever became the default, running
     * the script to *inspect* a tenant would mutate it.
     */
    public function test_writing_requires_an_explicit_commit_flag(): void
    {
        $code = $this->executable();

        $this->assertMatchesRegularExpression(
            '/const\s+COMMIT\s*=\s*ARGV\.includes\(\s*[\'"]--commit[\'"]\s*\)/',
            $code,
            'the --commit flag is no longer what enables writing. If writing became the '
            . 'default, merely inspecting a live tenant would mutate its payment '
            . 'configuration.');

        $this->assertMatchesRegularExpression('/if\s*\(\s*COMMIT\s*&&/', $code,
            'the write block is no longer guarded by COMMIT');

        // No assignment that could flip the default to true.
        $this->assertDoesNotMatchRegularExpression('/COMMIT\s*=\s*true/', $code,
            'COMMIT is forced true somewhere, defeating the dry-run default');
    }

    // ══ NON-DESTRUCTIVE BY CONSTRUCTION ═══════════════════════════════════

    /**
     * `create()` fails if the document already exists; `set()` overwrites it
     * silently. On a payment-credential collection that difference is the
     * difference between a safe re-run and destroying a configuration that
     * appeared between the read and the write.
     */
    public function test_writes_use_create_never_set_or_update(): void
    {
        $code = $this->executable();

        $this->assertStringContainsString(".create(", $code,
            'the migration no longer uses create(), so a row appearing mid-run would be '
            . 'silently overwritten instead of failing loudly');

        foreach (['.set(', '.update('] as $destructive) {
            $this->assertStringNotContainsString($destructive, $code,
                "the migration uses {$destructive} on a credential collection. create() is "
                . 'what makes a concurrent write a hard failure rather than a silent '
                . 'overwrite.');
        }
    }

    /** The legacy document is the rollback path; it must never be touched. */
    public function test_the_legacy_document_is_never_modified_or_deleted(): void
    {
        $code = $this->executable();

        $this->assertStringNotContainsString('.delete(', $code,
            'the migration deletes something. The legacy feeSettings document IS the '
            . 'rollback path — deleting it makes the migration irreversible.');

        $this->assertStringNotContainsString("collection('feeSettings').doc", $code,
            'the migration writes to feeSettings. It must only READ the legacy config.');
    }

    // ══ SECRETS ═══════════════════════════════════════════════════════════

    /**
     * The script prints a per-school report. Printing a credential into a
     * terminal, CI log or screenshot is a disclosure that cannot be recalled.
     */
    public function test_secrets_are_never_printed(): void
    {
        $code = $this->executable();

        // The script routes every credential through a describeSecret() helper
        // rather than inlining length logic at each site. That is BETTER than
        // what this test originally looked for — an earlier version of this
        // assertion did not recognise the indirection and failed against
        // perfectly safe code. Verify the HELPER is safe, then allow sites to
        // use it.
        $this->assertMatchesRegularExpression(
            "/describeSecret\s*=\s*\(v\)\s*=>\s*\{.*?return\s+s\s*===\s*''\s*\?\s*'ABSENT'\s*:\s*`present\(len=\\\$\{s\.length\}\)`/s",
            $code,
            'describeSecret() no longer reduces a credential to ABSENT / present(len=N). '
            . 'If it can emit the value itself, every call site below leaks.');

        // ── PER-FIELD, NOT PER-STATEMENT. FOUND BY MUTATION TESTING. ────────
        // The first version of this check scanned each console.log AS A WHOLE
        // for evidence of a safe reduction. A mutation that replaced ONE field
        // with its raw value — `${data.api_secret}` — SURVIVED, because the
        // two sibling fields on the same line still matched the pattern and
        // masked the leak.
        //
        // Every INTERPOLATION is now examined on its own. `${...}` is where a
        // value actually reaches the output, so that is the right granularity.
        preg_match_all('/\$\{([^}]*)\}/', $code, $interp);

        foreach ($interp[1] as $expr) {
            if (!preg_match('/api_secret|webhook_secret|api_key/', $expr)) continue;

            $this->assertMatchesRegularExpression(
                '/describeSecret\s*\(|\.length|len=/', $expr,
                "a credential is interpolated raw into output: \${{$expr}}. It must go "
                . 'through describeSecret() or be reduced to a length — a credential '
                . 'printed to a terminal, CI log or screenshot cannot be recalled.');
        }

        // The dry-run output observed on 2026-08-24 was:
        //   api_key=present(len=23) api_secret=present(len=24) webhook_secret=ABSENT
        $this->assertStringContainsString('ABSENT', $code,
            'the presence/absence reporting used by the dry-run is gone');
    }

    // ══ SCOPE ═════════════════════════════════════════════════════════════

    /** A single-school migration must be possible — blast radius control. */
    public function test_a_single_school_can_be_migrated_in_isolation(): void
    {
        $this->assertStringContainsString('--school=', $this->executable(),
            'the --school= filter is gone, so the only option is migrating every tenant '
            . 'at once — there is no way to activate one school and observe it first');
    }

    /**
     * The operator must be told that --commit is an activation decision, not a
     * technical step. This is the one assertion here that is deliberately
     * about wording: the warning is the control.
     */
    public function test_the_commit_warning_names_it_a_production_activation(): void
    {
        $this->assertMatchesRegularExpression('/PRODUCTION ACTIVATION/i', self::$src,
            'the script no longer warns that --commit is a production activation '
            . 'decision. Someone will run it expecting a dry-run.');

        $this->assertMatchesRegularExpression('/[Rr]ollback/', self::$src,
            'the rollback procedure is no longer stated where the operator will see it');
    }
}
