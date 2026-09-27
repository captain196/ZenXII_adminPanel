<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Canonical Document Service — configuration (Phase 1, Admission-scoped).
 *
 * Central, per-type policy for the lightweight Document Service. Adding a new
 * document type here is all a future module needs to reuse the service — no
 * core-code change. Keep this the single source of truth for limits/policy.
 */

$config['document_service'] = [

    // ── Per-type policy ────────────────────────────────────────────────
    // category: 'predefined' → always renders in its own field.
    //           'additional' → collected under "Additional Documents".
    // ext / mime: server-side allow-lists (both are checked).
    // maxBytes:  configurable size cap per type (Storage efficiency).
    // image:     true → run image optimization/compression before upload.
    'types' => [
        'photo' => [
            'label'    => 'Student Photo', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'webp'],
            'mime'     => ['image/jpeg', 'image/png', 'image/webp'],
            'maxBytes' => 2097152,   // 2 MB
            'image'    => true,
        ],
        'aadhaar' => [
            'label'    => 'Aadhaar Card', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,   // 5 MB
            'image'    => false,
        ],
        'birth_certificate' => [
            'label'    => 'Birth Certificate', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'transfer_certificate' => [
            'label'    => 'Transfer Certificate', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'marksheet' => [
            'label'    => 'Marksheet', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'medical_certificate' => [
            'label'    => 'Medical Certificate', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        // Visitor Management MVP (2026-08-09) — front-desk ID-proof capture.
        // Photo-of-document only; the module never stores a full ID number
        // as text (masked last-4 lives on the visitors record instead).
        'visitor_id_proof' => [
            'label'    => 'Visitor ID Proof', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'visitor_photo' => [
            // Webcam capture at check-in — mirrors the student 'photo' type
            // (image=true triggers the same downscale/re-encode pipeline).
            'label'    => 'Visitor Photo', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'webp'],
            'mime'     => ['image/jpeg', 'image/png', 'image/webp'],
            'maxBytes' => 2097152,
            'image'    => true,
        ],
        'visitor_verification' => [
            // Recurring vendor/contractor background-verification doc
            // (e.g. police clearance certificate) — carries its own expiry
            // on the visitors record, checked before a recurring pass reissues.
            'label'    => 'Background Verification Document', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        // Staff HR Advanced Capability Programme, Phase A (2026-08-18) —
        // reuses the canonical Document Service exactly as it was designed
        // to be reused (see Document_service.php's own docblock: "Student/
        // HR/Hostel/... can adopt it later WITHOUT redesign"). No new
        // collection, no new library — additive config only.
        'appointment_letter' => [
            'label'    => 'Appointment Letter', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'staff_id_proof' => [
            'label'    => 'Identity Proof', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'qualification_certificate' => [
            'label'    => 'Qualification Certificate', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'experience_certificate' => [
            'label'    => 'Previous Experience Certificate', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'employment_contract' => [
            'label'    => 'Employment Contract', 'category' => 'predefined',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf'],
            'mime'     => ['image/jpeg', 'image/png', 'application/pdf'],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
        'additional' => [
            'label'    => 'Document', 'category' => 'additional',
            'ext'      => ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
            'mime'     => [
                'image/jpeg', 'image/png', 'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/octet-stream', // legacy office; extension allow-list still gates
            ],
            'maxBytes' => 5242880,
            'image'    => false,
        ],
    ],

    // ── Hard security deny-list (never accepted, any type) ──────────────
    'deny_ext'  => ['svg', 'svgz', 'html', 'htm', 'xhtml', 'js', 'mjs', 'php', 'phtml', 'exe', 'sh', 'bat', 'cmd', 'com', 'jar', 'htaccess'],
    'deny_mime' => ['image/svg+xml', 'text/html', 'application/x-httpd-php', 'application/javascript', 'text/javascript'],

    // ── Image optimization (photos) ─────────────────────────────────────
    // Downscale to fit maxDim on the long edge; re-encode JPEG at jpegQuality.
    // Big storage win with negligible quality loss for passport-style photos.
    'image_optimize' => [
        'enabled'     => true,
        'maxDim'      => 1200,
        'jpegQuality' => 82,
    ],

    // ── Retention (rejected/archived documents) ─────────────────────────
    // Days a document must sit past its last active link before it is
    // eligible for permanent delete. Per-school override:
    // schools.{id}.admissionDocRetentionDays.
    'retention_days' => 180,

    // ── Canonical Storage prefix (ID-addressed; module-agnostic) ────────
    'storage_prefix' => 'documents',

    // Firestore collections (metadata only — Firestore is the sole DB).
    'collection_documents' => 'documents',
    'collection_links'     => 'documentLinks',
];
