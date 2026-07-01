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
    ],
];
