<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Numbering Service Registry
 *
 * Declarative configuration for the platform Numbering_service. Every kind the
 * service can allocate is registered here. Adding a new kind = adding a row to
 * the 'kinds' table. The service code itself is generic and knows nothing about
 * specific modules.
 *
 * Loaded via: $this->config->load('numbering');
 * Read via:   $this->config->item('numbering');
 *
 * Phase 1 ships with 6 Communication kinds (notice, circular, template, trigger,
 * queue, log) — every kind has a verified caller in Communication.php or
 * Communication_helper.php. All flags ship 'disabled'; Phase 2 Communication
 * migration flips them to 'enabled' after seed-from-legacy.
 *
 * STATIC PLATFORM CONFIGURATION — owned by Platform team; edited via PR review.
 * RUNTIME OPERATIONAL STATE     — owned by Operator/DevOps; flipped during
 *                                 cutover windows. Future enhancement: migrate
 *                                 to systemConfig/numbering Firestore doc for
 *                                 audit trail + operator-UI editability.
 */

$config['numbering'] = [

    // ═════════════════════════════════════════════════════════════════════════
    //  STATIC PLATFORM CONFIGURATION
    // ═════════════════════════════════════════════════════════════════════════

    '_schemaVersion' => '1.0',

    'kinds' => [

        // ─── Communication (Phase 2 migration target) ───────────────────────

        'notice' => [
            'prefix'       => 'NOT',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'notices',
                'pattern'    => '/^NOT(\d+)$/',
            ],
        ],

        'circular' => [
            'prefix'       => 'CIR',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'circulars',
                'pattern'    => '/^CIR(\d+)$/',
            ],
        ],

        'template' => [
            'prefix'       => 'TPL',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'messageTemplates',
                'pattern'    => '/^TPL(\d+)$/',
            ],
        ],

        'trigger' => [
            'prefix'       => 'TRG',
            'padWidth'     => 4,
            'gaplessClass' => 'OPERATIONAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'alertTriggers',
                'pattern'    => '/^TRG(\d+)$/',
            ],
        ],

        'queue' => [
            'prefix'       => 'QUE',
            'padWidth'     => 5,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'messageQueue',
                'pattern'    => '/^QUE(\d+)$/',
            ],
        ],

        'log' => [
            // periodScope 'none' preserves the legacy monotonic Log numbering
            // semantics. The Phase 1 architecture initially considered 'month'
            // (forward-looking) but legacy deliveryLogs docs share a single
            // sequence without a month discriminator in the doc key, so
            // monthly reset would cause LOG-ID collisions on cutover.
            'prefix'       => 'LOG',
            'padWidth'     => 5,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
            'seedSource'   => [
                'collection' => 'deliveryLogs',
                'pattern'    => '/^LOG(\d+)$/',
            ],
        ],

        // ─── Document Engine — Template Engine (P1.4) ───────────────────────
        //
        // INTERNAL identifiers only: they name a template or a reusable block
        // inside the designer. They are NOT the number printed on an issued
        // certificate.
        //
        // No seedSource: these are new kinds with no legacy data to continue
        // from, so the missing-pointer path simply starts at 1.
        //
        // periodScope 'none': a template's identity does not reset per session.
        // Issued-document numbers WILL need period scoping ('session' derives
        // the Indian financial year here), but that belongs to the Issuance
        // Engine, not this build.
        //
        // ⚠ gaplessClass is DECLARATIVE ONLY. It is returned by describe() and
        // nothing enforces it — see the note under _kindFlags. INTERNAL is the
        // honest grade for these two: a gap in template numbering is harmless.
        // Statutory document numbering must NOT be added here until the
        // contract is made real.

        'doc_template' => [
            'prefix'       => 'TPL',
            'padWidth'     => 4,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
        ],

        'doc_block' => [
            'prefix'       => 'BLK',
            'padWidth'     => 4,
            'gaplessClass' => 'INTERNAL',
            'periodScope'  => 'none',
        ],

    ],

    // ═════════════════════════════════════════════════════════════════════════
    //  RUNTIME OPERATIONAL STATE
    // ═════════════════════════════════════════════════════════════════════════

    '_serviceEnabled' => true,

    '_kindFlags' => [
        // Phase 2 cutover (2026-06-28): Communication module migrated to
        // Numbering_service. All 6 kinds active; legacy commCounters.*
        // storage on schools/{id}_profile is no longer read or written
        // by Communication. Self-heal runs once per (tenant, kind) on
        // first allocation to continue from the legacy max ID.
        'notice'   => 'enabled',
        'circular' => 'enabled',
        'template' => 'enabled',
        'trigger'  => 'enabled',
        'queue'    => 'enabled',
        'log'      => 'enabled',

        // Document Engine (P1.4). Enabled on registration: these allocate
        // INTERNAL ids only, there is no legacy sequence to cut over from,
        // and no issued document depends on them.
        'doc_template' => 'enabled',
        'doc_block'    => 'enabled',
    ],
];

/*
|--------------------------------------------------------------------------
| KNOWN GAP — gaplessClass is not enforced
|--------------------------------------------------------------------------
| Every kind above declares a gaplessClass, and Numbering_service's docblock
| describes a "gapless contract". Neither is true today: the string appears
| exactly once in Numbering_service.php (inside describe()'s return value)
| and nothing acts on it.
|
| That is acceptable for OPERATIONAL and INTERNAL kinds, where a gap is
| harmless. It is NOT acceptable for a statutory series — a Transfer
| Certificate or fee-receipt register must be gapless and auditable, and an
| unexplained missing number is an audit finding.
|
| Before ANY issued-document kind is registered here, gaplessClass must be
| made real:
|   STATUTORY   throw on allocation failure; never skip. A number leaves the
|               series only by an explicit void carrying a reason.
|   OPERATIONAL retry, then skip.
|   INTERNAL    skip freely.
|
| See blueprints/certificates/EXECUTION_PLAN_v1.1.md and
| DOCUMENT_ENGINE_ARCHITECTURE.md §5.4.
*/
