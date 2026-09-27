<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * HRMS statutory wage-base feature flags.
 *
 * Loaded with $this->config->load('hrms_statutory_flags', true) and read via
 * $this->config->item('USE_STATUTORY_WAGE_BASE', 'hrms_statutory_flags').
 *
 * Same discipline as hrms_compensation_flags.php and fees_exemption_v2_flags.php:
 * default OFF, and flipped only after a parity gate produces evidence — never
 * because tests pass.
 *
 * DELIBERATELY SEPARATE FROM `USE_COMPONENT_COMPENSATION`. They are independent
 * concerns: one decides where a salary STRUCTURE is resolved from, the other
 * decides what counts as WAGES for statutory purposes. Coupling them would force
 * two risky cutovers into a single step, and would make it impossible to run the
 * statutory shadow against the legacy structure — which is the only comparison
 * that can be made today, because the component catalogue is unseeded in every
 * tenant.
 */

/**
 * FALSE → PF is computed on earned BASIC and ESI on GROSS, exactly as the
 *         shipping engine does today. This is current behaviour, byte-for-byte.
 * TRUE  → both resolve through `Statutory_wage_base`, which applies the Code on
 *         Wages s.2(y) definition including the add-back.
 *
 * WHAT FLIPPING THIS ACTUALLY CHANGES
 * -----------------------------------
 * Every employee's PF deduction and the school's employer-contribution
 * liability. Under s.6 of the EPF & MP Act 1952 the base is "the basic wages,
 * dearness allowance and retaining allowance (if any)" — so any tenant with a
 * populated `da` contributes MORE once this is on. Under the universality test
 * in *RPFC (II) West Bengal v. Vivekananda Vidyamandir* (SC, 2019) — a case
 * about an unaided school — an allowance paid to all staff is also wages, so a
 * tenant that has classified its residual allowance as INCLUDE contributes more
 * again.
 *
 * DEFAULT FALSE and it must stay false until:
 *   1. the tenant's component classifications are set and CA-confirmed,
 *   2. the offline parity harness has reported the delta per structure,
 *   3. the owner has accepted the resulting change in employer cost.
 *
 * This is not a flag an engineer flips. It changes what a school owes.
 */
$config['USE_STATUTORY_WAGE_BASE'] = false;

/**
 * Read-only statutory shadow. When TRUE the payroll engine computes BOTH the
 * legacy base and the statutory base, logs any divergence
 * (grep STATUTORY_WAGE_DIVERGENCE) and PAYS THE LEGACY FIGURE unchanged.
 *
 * DEFAULT OFF: it costs extra computation per employee per run, and that price
 * is only worth paying during an active verification cycle.
 *
 * Unlike the compensation shadow, this one CAN produce meaningful evidence
 * today — it compares two ways of reading the SAME legacy structure, so it does
 * not depend on the component catalogue being seeded. It is still off by
 * default because nothing should be computed twice without a reason.
 */
$config['STATUTORY_WAGE_SHADOW'] = false;

/**
 * The add-back threshold, as an exact fraction of total remuneration.
 *
 * The first proviso to section 2(y) of the Code on Wages 2019 reads: where the
 * excluded payments exceed "one-half, or such other percentage as may be
 * notified by the Central Government", of all remuneration, the excess is added
 * back into wages.
 *
 * So one-half is the CURRENT position, not a constant. It is expressed as a
 * numerator/denominator pair rather than a percentage float so the arithmetic
 * stays exact in integer paise.
 *
 * Source: Code on Wages 2019, s.2(y) first proviso. In force 21 Nov 2025.
 * TODO: verify with CA each financial year, and on any notification changing
 * the percentage. See docs/compliance/labour-codes-notes.md.
 */
$config['STATUTORY_ADDBACK_THRESHOLD_NUM'] = 1;
$config['STATUTORY_ADDBACK_THRESHOLD_DEN'] = 2;

/**
 * Warn when a salary structure is created with basic below the threshold share
 * of remuneration.
 *
 * Required by `docs/specs/hr-payroll-brief.md` §6.3: "Validation warning when a
 * structure is created with basic < 50% of remuneration." A warning changes no
 * money, so unlike the flags above this defaults ON — the point is to catch a
 * non-compliant structure at entry rather than on a payslip.
 */
$config['STATUTORY_WARN_ON_LOW_BASIC'] = true;
