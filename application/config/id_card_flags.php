<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ID Card credential + replacement-fee flags.
 *
 * Same discipline as fees_exemption_v2_flags.php and hrms_compensation_flags.php:
 * every switch defaults to the SAFE value, and flipping one is an operator
 * decision recorded in a phase report — never a side effect of a code change.
 *
 * Loaded via  $this->config->load('id_card_flags', true)
 * Read with   $this->config->item('IDCARD_ENFORCE_V2', 'id_card_flags')
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  WHY EVERYTHING HERE IS OFF
 * ═══════════════════════════════════════════════════════════════════════
 *  SEC-ID-3 telemetry is code-complete but HAS NOT BEEN DEPLOYED, so there is
 *  ZERO production evidence of how many cards in circulation are legacy.
 *  Enforcing credential versions before every student has been reissued would
 *  reject every legitimate holder on the first scan — a school-wide outage at
 *  the attendance gate. The sequence is fixed and evidence-driven:
 *
 *      deploy SEC-ID-3  →  observe  →  reissue legacy holders
 *      →  migrationReadiness() reports zero  →  IDCARD_ENFORCE_V2 = true
 *      →  observe again  →  IDCARD_REJECT_LEGACY = true
 *
 *  Do NOT flip these together. Each is a separate, separately-verifiable step.
 */

/**
 * SEC-ID-1 — enforce that a presented V2 credential names the student's
 * CURRENT active card version.
 *
 * OFF: a V2 token is accepted on signature alone (today's behaviour). A
 *      replaced or revoked card still scans.
 * ON:  a superseded or revoked card is rejected. Students who hold a legacy
 *      or V1 card are UNAFFECTED — those name no card version at all and are
 *      governed by IDCARD_REJECT_LEGACY / IDCARD_REJECT_V1 below.
 *
 * PRECONDITION: migrationReadiness() reports zero students holding an
 * un-versioned credential, or the school accepts that those students must be
 * reissued first.
 */
$config['IDCARD_ENFORCE_V2'] = false;

/**
 * SEC-ID-2 — reject unsigned legacy 2-part tokens.
 *
 * OFF: accepted, and logged by SEC-ID-3 (today's behaviour).
 * ON:  rejected outright. **This bricks every card printed before the HMAC
 *      upgrade.** Only flip when the register shows zero legacy holders AND
 *      the school has confirmed no unreissued card remains in circulation.
 */
$config['IDCARD_REJECT_LEGACY'] = false;

/**
 * Reject V1 (signed but un-versioned) credentials.
 *
 * Separate from the legacy flag on purpose: a V1 card is CRYPTOGRAPHICALLY
 * SOUND — it simply predates versioning. Retiring V1 is a later, lower-urgency
 * step than retiring unsigned legacy tokens, and conflating the two would
 * force a school to reprint sound cards to close an unrelated hole.
 */
$config['IDCARD_REJECT_V1'] = false;

/**
 * SEC-ID-3b — record V1 (signed, un-versioned) credential usage.
 *
 * WHY THIS IS A SEPARATE FLAG AND NOT PART OF SEC-ID-3.
 * SEC-ID-3 is a PURE OBSERVATION change and must be deployed as such —
 * reviewed once, deployed once, changing nothing but log output. Bundling V1
 * telemetry into the same deployment would mean the first production run of
 * the observation mechanism also carried an untested second behaviour.
 *
 * Phase 19K established that the printed card minted V1 unconditionally until
 * ID-MINT-1 was fixed, so production is **legacy + V1 with zero V2 cards**.
 * V1 is therefore not an edge case — with legacy it is the entire live
 * population, and its usage must be countable before IDCARD_REJECT_V1 can
 * ever be considered.
 *
 * VOLUME WARNING, and the reason this defaults OFF rather than on: with V1
 * being most of the population, enabling this writes an `error` line for
 * essentially EVERY scan. That is acceptable for a bounded observation window
 * and unacceptable as a steady state. Turn it on, observe, turn it back off.
 *
 * Sequence: deploy SEC-ID-3 → observe legacy → THEN enable this → observe V1
 * → reissue → retire. No V1 deadline is invented.
 */
$config['IDCARD_V1_TELEMETRY'] = false;

/**
 * ═══════════════════════════════════════════════════════════════════════
 *  REPLACEMENT FEE — ALL AMOUNTS DEFAULT TO ZERO
 * ═══════════════════════════════════════════════════════════════════════
 *  A zero amount means NO fee demand is raised at all (Fee_lifecycle::
 *  createModuleFee returns null for amount <= 0), so an upgrade can never
 *  silently start charging a school's parents.
 *
 *  There is NO Indian K-12 norm for school ID replacement charges — the
 *  external research found only CBSE *admit-card* duplicate fees, which are a
 *  board artefact governed by a different authority. A school ID card is a
 *  school-internal artefact with no external standard to inherit, so the
 *  amounts MUST be a per-school decision. These values are the fallback when
 *  a school has set nothing.
 *
 *  `first_issue` is absent by design: the first card is part of admission,
 *  and this table has no key that could make it chargeable by accident.
 *  `wear`, `data_correction` and `admin` are present but zero — normal wear
 *  and the school's own corrections are not the family's fault.
 */
$config['IDCARD_REPLACEMENT_FEE'] = [
    'lost'            => 0.0,
    'stolen'          => 0.0,
    'damaged'         => 0.0,
    'wear'            => 0.0,
    'data_correction' => 0.0,
    'admin'           => 0.0,
];

/**
 * Fee head used for a replacement demand. The CARD VERSION is appended by
 * Id_card_fee_policy so each replacement gets its own demand identity —
 * `Fee_lifecycle::_demandId()` derives its key from the head name, and without
 * a per-version head a second replacement in the same session would REWRITE
 * the first demand instead of adding one (see ID-D2).
 */
$config['IDCARD_FEE_HEAD'] = 'ID Card Replacement';

/**
 * Module/category tag on the demand. Lowercase per the F10 convention
 * Fee_lifecycle already normalises Transport/Hostel to.
 */
$config['IDCARD_FEE_MODULE'] = 'IDCard';
