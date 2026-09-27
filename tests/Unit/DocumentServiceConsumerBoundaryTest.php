<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Canonical Document Service boundary, and what F&F may assume about it.
 *
 * The service is a FROZEN platform baseline: modules consume it, and nobody
 * changes its architecture, APIs, schema, CAS, lifecycle, Storage handling or
 * refcount without a proven defect and sign-off.
 *
 * `STAFF_FNF_SETTLEMENT_DESIGN.md` §4.8 concludes that exit letters need **no
 * modification to the service at all**. That conclusion rests on properties
 * asserted here. If any of them changes, the design must be revisited before
 * F&F links a document — and, because the service is frozen, a change to them
 * is itself a signal that something has gone wrong.
 *
 * These are structural assertions by necessity: `link()` is CAS-protected
 * against live Firestore, so exercising it behaviourally would mean standing up
 * the document stack. What CAN be verified is the contract F&F depends on —
 * the API shape, the absence of a module allow-list, and the fact that nothing
 * outside the service writes the refcounted collections.
 */
final class DocumentServiceConsumerBoundaryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../application/libraries/Document_service.php';
        if (!is_file($path)) $this->markTestSkipped('Document_service not present');

        $src = file_get_contents($path);
        $this->assertNotFalse($src);
        $this->src = $src;
    }

    // ── The consumer API F&F will use ────────────────────────────────────

    public function test_the_consumer_api_is_the_one_the_design_assumes(): void
    {
        foreach ([
            'public function init($firebase, string $schoolId)',
            'public function upload(string $tmpPath, string $origName, string $type',
            'public function link(string $documentId, string $module, string $entityId',
            'public function unlink(string $documentId, string $module, string $entityId',
            'public function getMetadata(string $documentId)',
        ] as $signature) {
            $this->assertStringContainsString($signature, $this->src,
                'the design depends on this exact entry point');
        }
    }

    /**
     * THE ONE THAT LETS F&F INTEGRATE WITHOUT TOUCHING A FROZEN FILE.
     *
     * `$module` is a free string. If an allow-list were introduced, adding
     * 'fnf' would mean modifying the frozen baseline — which needs sign-off.
     */
    public function test_the_module_argument_has_no_allow_list(): void
    {
        $this->assertMatchesRegularExpression(
            '/function link\(string \$documentId, string \$module, string \$entityId/',
            $this->src
        );

        $i = strpos($this->src, 'public function link(');
        $this->assertNotFalse($i);
        $body = substr($this->src, (int) $i, 2200);

        $this->assertStringNotContainsString('in_array($module', $body,
            'an allow-list here would make F&F integration a change to a FROZEN file');
    }

    /** The link key is composed from all three parts, so modules cannot collide. */
    public function test_the_link_key_is_scoped_by_module_and_entity(): void
    {
        $this->assertStringContainsString(
            '$linkKey = $this->linkKey($documentId, $module, $entityId);',
            $this->src,
            "a settlement's links must not collide with a student's for the same blob"
        );
    }

    // ── Idempotency and CAS — what makes retries safe ────────────────────

    /**
     * A settlement that retries a document step must not double-count the
     * refcount. Re-linking returns the existing count rather than incrementing.
     */
    public function test_relinking_is_idempotent(): void
    {
        $i = strpos($this->src, 'public function link(');
        $body = substr($this->src, (int) $i, 2200);

        $this->assertStringContainsString("return ['ok' => true, 'error' => null, 'linkCount'", $body,
            'an already-linked document must report success, not increment again');
    }

    public function test_linking_is_cas_protected(): void
    {
        $i = strpos($this->src, 'public function link(');
        $body = substr($this->src, (int) $i, 2200);

        $this->assertStringContainsString('document updateTime unavailable (CAS)', $body,
            'without CAS two concurrent links could lose a refcount increment');
    }

    public function test_the_service_retries_on_cas_contention(): void
    {
        $this->assertStringContainsString('CAS_MAX_RETRIES', $this->src);
    }

    // ── Unlink, not delete — the link-not-move model ─────────────────────

    /**
     * A cancelled settlement must UNLINK. The blob survives if anything else
     * references it, which is the whole point of link-not-move (ADR-001 C).
     */
    public function test_unlink_exists_separately_from_delete(): void
    {
        $this->assertStringContainsString('public function unlink(', $this->src);
        $this->assertStringContainsString('public function delete(', $this->src);

        $this->assertNotSame(
            strpos($this->src, 'public function unlink('),
            strpos($this->src, 'public function delete('),
            'unlink and delete must remain distinct — cancelling a settlement is not deleting a file'
        );
    }

    // ── Nothing outside the service touches the refcounted collections ───

    /**
     * The refcount is the service's invariant. A direct write to `documents` or
     * `documentLinks` from a module would corrupt it exactly as a direct ledger
     * write corrupted accounting — same class of mistake, same answer.
     */
    public function test_no_controller_writes_the_document_collections_directly(): void
    {
        $offenders = [];

        foreach (glob(__DIR__ . '/../../application/controllers/*.php') ?: [] as $file) {
            $src = file_get_contents($file);
            if ($src === false) continue;

            foreach (["set('documents'", 'set("documents"', "set('documentLinks'", "update('documents'"] as $bad) {
                if (strpos($src, $bad) !== false) $offenders[] = basename($file) . " → {$bad}";
            }
        }

        $this->assertSame([], $offenders,
            'the Document Service owns the refcount; a direct write breaks it: '
            . implode(', ', $offenders));
    }

    /** And the rules back that up — the collections are server-only. */
    public function test_the_document_collections_are_server_only_in_the_rules(): void
    {
        $rules = @file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
        if ($rules === false) $this->markTestSkipped('rules file not present');

        foreach (['documents', 'documentLinks'] as $col) {
            $this->assertMatchesRegularExpression(
                '/match \/' . $col . '\/\{\w+\}\s*\{\s*allow read, write: if false;/',
                $rules,
                "{$col} must stay server-only — no client may reach a refcounted blob"
            );
        }
    }

    // ── PDF generation is already solved ─────────────────────────────────

    /**
     * Producing the letter itself needs no new capability: the payslip path
     * already builds HTML from a pure builder and renders it to bytes with no
     * HTTP. What a relieving letter SAYS is template content, not engineering.
     */
    public function test_pdf_rendering_returns_bytes_without_http(): void
    {
        $gen = @file_get_contents(__DIR__ . '/../../application/libraries/Pdf_generator.php');
        if ($gen === false) $this->markTestSkipped('Pdf_generator not present');

        $this->assertStringContainsString('public function render(string $html): string', $gen,
            'render() is what lets a letter be produced and stored without a download');
        $this->assertStringContainsString('public function save(string $html, string $filepath)', $gen);
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
