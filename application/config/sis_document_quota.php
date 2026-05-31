<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SIS Wave-3 fix DM6 (2026-05-31): per-student document quota defaults.
 *
 * Per-school override via schools.{schoolId}.documentQuota.{key} — when
 * the override field is set on the school doc, it takes precedence over
 * the default below. Override keys (camelCase, all optional):
 *   maxDocs        : int
 *   maxFileBytes   : int (bytes)
 *   maxTotalBytes  : int (bytes)
 *
 * Enforced at upload time in Sis::_uploadStudentFile and
 * Sis::upload_document via Sis::_check_doc_quota helper.
 *
 * Grandfathered docs (existing entries without a `bytes` field) count
 * toward the COUNT cap but are treated as 0 bytes in the SIZE cap.
 * The COUNT cap is the primary safeguard for grandfathered students;
 * the SIZE cap becomes accurate over time as docs are re-uploaded
 * through the Documents tab (which now writes `bytes`).
 */
$config['sis_document_quota'] = [
    'max_docs'        => 10,
    'max_file_bytes'  => 5 * 1024 * 1024,    // 5 MB
    'max_total_bytes' => 50 * 1024 * 1024,   // 50 MB
];
