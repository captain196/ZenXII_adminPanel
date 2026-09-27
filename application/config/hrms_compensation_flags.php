<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * HRMS compensation feature flags (W2.4).
 *
 * Loaded with $this->config->load('hrms_compensation_flags', true) and read via
 * $this->config->item('USE_COMPONENT_COMPENSATION', 'hrms_compensation_flags').
 *
 * Same discipline as fees_exemption_v2_flags.php: both default OFF, the shadow
 * flag never changes a returned value, and the cutover flag is flipped only
 * after a parity gate produces evidence — never because tests pass.
 */

/**
 * FALSE  → Compensation_reader returns the legacy `salarySlips` structure.
 *          This is today's behaviour, byte-for-byte.
 * TRUE   → the reader resolves the effective `staffSalaryAssignments` record
 *          through the `salaryComponents` catalogue instead.
 *
 * DEFAULT FALSE and it must stay false until:
 *   1. the component catalogue is seeded for the tenant,
 *   2. the W2.3 migration has been run with --commit,
 *   3. the parity harness reports no divergence over that tenant's real data.
 *
 * Flipping it earlier resolves against empty collections, which does not fail
 * loudly — it produces an employee with no salary, which is a silent zero and
 * the exact failure mode the readiness surface exists to prevent.
 */
$config['USE_COMPONENT_COMPENSATION'] = false;

/**
 * Read-only A/B shadow. When TRUE the reader resolves BOTH paths, logs any
 * divergence (grep COMPENSATION_AB_DIVERGENCE) and RETURNS THE LEGACY RESULT
 * unchanged. It never alters a payslip, never writes, and swallows its own
 * failures so a shadow fault can never break a payroll run.
 *
 * DEFAULT OFF, deliberately: it costs extra Firestore reads per employee per
 * run, and that price is only worth paying during an active verification cycle.
 *
 * NOTE (2026-08-09): switching this on today would compare the legacy structure
 * against EMPTY collections and dutifully report "no divergence" — a green that
 * means nothing. The offline parity harness (`hrms_w2_parity`) exists precisely
 * because it can produce real evidence from the real legacy data without any
 * migration having happened. Use that first.
 */
$config['COMPENSATION_SHADOW_COMPARE'] = false;
