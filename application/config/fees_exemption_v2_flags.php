<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fees Exemption v2 feature flags.
 *
 * Phase 0 ships with all three OFF. Phase 2 cutover flips USE_UNIFIED_FEE_GEN
 * + CONCESSION_UI_ENABLED together (capture and billing go live together,
 * never apart — the operator's explicit requirement). SERVICE_ENROLLMENT_UI
 * stays OFF until Phase 3 converges service generation off createModuleFee.
 *
 * Loaded via $this->config->load('fees_exemption_v2_flags', true) and read
 * with $this->config->item('USE_UNIFIED_FEE_GEN', 'fees_exemption_v2_flags').
 */

$config['USE_UNIFIED_FEE_GEN']         = false; // Phase 2 cutover: true
$config['CONCESSION_UI_ENABLED']        = false; // Phase 2 cutover: true (in lockstep with USE_UNIFIED_FEE_GEN)
$config['SERVICE_ENROLLMENT_UI_ENABLED'] = false; // Phase 3 cutover: true

// PHASE_3_CONVERGED: drives the communication-layer badges + warning banners
// on the legacy manual/bulk/recalc paths. While false, those paths show the
// amber "Phase 2 — legacy gen" badge + warning banner and the smart-confirm
// modal fires on per-student concession matches. At Phase 3 cutover this
// single flip silences all amber UI in one place — no per-view rework.
$config['PHASE_3_CONVERGED'] = false; // Phase 3 cutover: true
