<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase A (Document Management),
 * 2026-08-18.
 *
 * Reuses the canonical Document Service exactly as its own docblock says
 * it was designed to be reused — no new collection, no new library, config
 * additions only. The endpoint shape mirrors Sis.php's proven student-
 * document pattern field-for-field so the two document surfaces don't
 * diverge into separate conventions for the same underlying service.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class StaffDocumentManagementTest extends TestCase
{
    private string $src;
    private string $configSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->src = $out;

        $this->configSrc = file_get_contents(__DIR__ . '/../../application/config/document_service.php');
        $this->assertNotFalse($this->configSrc);
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function functionBody(string $needle): string
    {
        $start = strpos($this->src, $needle);
        $this->assertNotFalse($start, "Expected to find: {$needle}");
        return $this->extractFunctionBody($this->src, $start);
    }

    // ── Config: additive only, no core library touched ──────────────────

    public function test_new_hr_document_types_added_to_config(): void
    {
        foreach (['appointment_letter', 'staff_id_proof', 'qualification_certificate', 'experience_certificate', 'employment_contract'] as $type) {
            $this->assertStringContainsString("'{$type}' =>", $this->configSrc);
        }
    }

    public function test_config_still_declares_only_the_two_canonical_collections(): void
    {
        // No new collection introduced for staff documents.
        $this->assertSame(1, substr_count($this->configSrc, "'collection_documents' => 'documents',"));
        $this->assertSame(1, substr_count($this->configSrc, "'collection_links'     => 'documentLinks',"));
    }

    public function test_document_service_library_itself_untouched(): void
    {
        // FROZEN PLATFORM BASELINE — modules consume it, they don't modify it.
        $libSrc = file_get_contents(__DIR__ . '/../../application/libraries/Document_service.php');
        $this->assertStringNotContainsString("'staff'", $libSrc,
            'the canonical service must stay module-agnostic — it should never special-case "staff" internally; the module string is a caller-supplied parameter, not a hardcoded branch.');
    }

    // ── RBAC: reuses Staff.php's existing SIS-module grading, no new module ──

    public function test_all_five_endpoints_use_the_existing_sis_module_grading(): void
    {
        foreach ([
            ['get_staff_documents(', 'VIEW_ROLES', 'view'],
            ['upload_staff_document(', 'MANAGE_ROLES', 'manage'],
            ['verify_staff_document(', 'MANAGE_ROLES', 'manage'],
            ['delete_staff_document(', 'MANAGE_ROLES', 'manage'],
        ] as [$fn, $roles, $level]) {
            $body = $this->functionBody("public function {$fn}");
            $this->assertStringContainsString("_require_role(self::{$roles}, \"\", \"SIS\", \"{$level}\")", $body,
                "{$fn} must reuse Staff.php's existing SIS-module RBAC grading, not invent a new module string.");
        }
    }

    // ── Mirrors the proven Sis.php pattern exactly ───────────────────────

    public function test_upload_replaces_existing_predefined_slot(): void
    {
        $body = $this->functionBody('public function upload_staff_document(');
        $this->assertStringContainsString("if (\$type !== 'additional') {", $body);
        $this->assertStringContainsString('$this->docsvc->replace($existingId, $tmp, $orig, $actor);', $body);
    }

    public function test_upload_links_new_documents_to_the_staff_module(): void
    {
        $body = $this->functionBody('public function upload_staff_document(');
        $this->assertStringContainsString("\$this->docsvc->link(\$doc['documentId'], 'staff', \$staffId, \$type, \$actor);", $body);
    }

    public function test_delete_is_ownership_guarded_before_touching_the_service(): void
    {
        $body = $this->functionBody('public function delete_staff_document(');
        $guardPos = strpos($body, '_staff_doc_owned($staff, $docId)');
        $deletePos = strpos($body, "\$this->docsvc->delete(\$docId, 'staff', \$staffId,");
        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($deletePos);
        $this->assertLessThan($deletePos, $guardPos);
    }

    public function test_verify_is_also_ownership_guarded(): void
    {
        $body = $this->functionBody('public function verify_staff_document(');
        $this->assertStringContainsString('_staff_doc_owned($staff, $docId)', $body);
    }

    public function test_verify_response_never_overwrites_the_success_status_key(): void
    {
        // Same gotcha Sis::verify_student_document() explicitly documents —
        // json_success() already sets status:'success'; a data 'status' key
        // would silently make the frontend think the call failed.
        $body = $this->functionBody('public function verify_staff_document(');
        $this->assertStringContainsString("['verificationStatus' => \$status]", $body);
        $this->assertStringNotContainsString("['status' => \$status]", $body);
    }

    public function test_delete_purges_the_documentref_pointer_not_just_the_service_record(): void
    {
        $body = $this->functionBody('public function delete_staff_document(');
        $this->assertStringContainsString("\$this->fs->updateEntity('staff', \$staffId, ['documentRefs' => array_values(\$refs)]);", $body);
    }

    // ── Every mutation is audit-logged ───────────────────────────────────

    public function test_upload_verify_delete_all_audit_logged(): void
    {
        $this->assertStringContainsString("log_audit('Staff', 'document_upload',", $this->src);
        $this->assertStringContainsString("log_audit('Staff', 'document_replace',", $this->src);
        $this->assertStringContainsString("log_audit('Staff', 'document_verify',", $this->src);
        $this->assertStringContainsString("log_audit('Staff', 'document_delete',", $this->src);
    }

    // ── Sync helper: upsert, not blind append ────────────────────────────

    public function test_docref_sync_upserts_rather_than_duplicates(): void
    {
        $body = $this->functionBody('private function _staff_sync_docref(');
        $this->assertStringContainsString('$found = false;', $body);
        $this->assertStringContainsString('if (!$found) $refs[] = $entry;', $body);
    }

    public function test_document_id_validated_before_use_in_verify_and_delete(): void
    {
        foreach (['verify_staff_document(', 'delete_staff_document('] as $fn) {
            $body = $this->functionBody("public function {$fn}");
            $this->assertStringContainsString("preg_match('/^[A-Za-z0-9_]+\$/', \$docId)", $body);
        }
    }
}
