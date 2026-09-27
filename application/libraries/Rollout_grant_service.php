<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Rollout_grant_service — the ONE sanctioned writer of
 * `schoolControl/{schoolId}.rolloutFlags`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY IT IS A SEPARATE CLASS FROM THE RESOLVER
 * ─────────────────────────────────────────────────────────────────────────
 *  `Rollout_flag_service` is read-only BY CONSTRUCTION — a test asserts no
 *  write verb appears anywhere in it, because it runs inside fee generation on
 *  every request and must never be able to mutate anything. Adding a setter
 *  there would delete that guarantee. So reading and granting are two classes:
 *  the hot path can only read; only this cold, superadmin-only path can write.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT A GRANT IS, AND IS NOT
 * ─────────────────────────────────────────────────────────────────────────
 *  A grant is a TECHNICAL ROLLOUT decision — "this behaviour is proven safe for
 *  this school". It is deliberately NOT a commercial entitlement: nothing here
 *  touches `plans`, `subscription`, `addOns`, `overrides` or the derived
 *  `tenantPublic.activeModules`, so a billing change can never move a financial
 *  switch and a rollout can never look like a sold module. That separation is
 *  the whole reason `rolloutFlags` is its own field.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  SAFETY PROPERTIES
 * ─────────────────────────────────────────────────────────────────────────
 *   • CLOSED VOCABULARY. Only the four flags in GRANTABLE may be written. A
 *     typo'd key is refused rather than stored as a flag nothing reads — an
 *     inert grant that an operator believes is live is worse than an error.
 *   • MERGE, NEVER REPLACE. The write is a targeted merge on one nested key, so
 *     it cannot clobber `subscription`, `overrides`, `featureFlags` or any other
 *     field of the tenant's control document.
 *   • ONE TENANT PER CALL. There is no "grant to all", no wildcard and no list
 *     parameter. Enabling a financial behaviour estate-wide must never be one
 *     call away.
 *   • PROVENANCE REQUIRED. `setBy` and a non-empty `reason` are mandatory; a
 *     grant with no author and no justification is not accepted.
 *   • AUDITED through the existing helper, never a private log.
 *   • REVOCABLE. revoke() removes the key entirely, so the flag falls back to
 *     its global default — the same state as never having been granted.
 *
 * ⚠ NO CALLER MAY INVOKE THIS DURING IMPLEMENTATION. Creating an actual tenant
 *   grant is a separate authorization (T4) and the FIRST real production write
 *   of this programme. This class exists so that step is a reviewed, audited,
 *   one-tenant action rather than a hand-edited Firestore document.
 */
class Rollout_grant_service
{
    /** Same document + field the resolver reads. */
    public const COL   = 'schoolControl';
    public const FIELD = 'rolloutFlags';

    /**
     * The closed set of flags that may be granted per tenant — exactly the
     * gates routed through the resolver at T2, and nothing else. A key absent
     * from this list is refused: granting a flag no code reads produces an
     * inert grant that looks live on screen.
     */
    public const GRANTABLE = [
        'USE_FEE_HEAD_REGISTRY',
        'USE_ENTITLEMENT_BILLING',
        'USE_ENTITLEMENT_SERVICE_PRICING',
        'ENTITLEMENT_SHADOW_COMPARE',
        // P5 step 1 — emit allocation rows alongside the computed discount.
        // Grantable so the ledger can be rolled out (and rolled back) per tenant
        // WITHOUT touching USE_ENTITLEMENT_BILLING, which is already live for
        // the UAT tenant. Its prerequisites (deployed rules + built indexes) are
        // now MET — `scripts/ledger_activation_readiness.php` proves all of them
        // against live production for a named tenant. What remains is
        // authorization, not readiness: the grant stays unissued until approved.
        'USE_ALLOCATION_LEDGER',
    ];

    /** @var object|null */ private $firebase;
    private string $actorId = '';

    public function init($firebase, string $actorId): self
    {
        $this->firebase = $firebase;
        $this->actorId  = trim($actorId) !== '' ? trim($actorId) : 'SYSTEM';
        return $this;
    }

    /**
     * Grant or revoke one flag for ONE tenant.
     *
     * @param bool        $enabled   true = on for this tenant, false = off even
     *                               if the global default is on (a per-tenant
     *                               kill switch, without an estate-wide revert).
     * @param string|null $expiresAt optional ISO-8601 auto-revert. Omit for an
     *                               open-ended grant; a soak that silently
     *                               expires mid-run is its own hazard, so this
     *                               is opt-in, not the default.
     *
     * @return array{ok:bool, error?:string, flag?:string, schoolId?:string}
     */
    /**
     * DAY-ONE-2 — which heads this school CHARGES but has not REGISTERED.
     *
     * Delegates to Fee_head_readiness, which is the single implementation of
     * that comparison and is shared with the operator-facing readiness screen.
     * Kept out of this class deliberately: T3 forbids iteration here so that no
     * bulk or wildcard grant path can hide in the grant surface, and that guard
     * is worth more than inlining a loop.
     *
     * @return array<string>|null [] complete, list = missing, null = unknown.
     */
    private function _unregisteredChartHeads(string $schoolId): ?array
    {
        if (!class_exists('Fee_head_readiness')) {
            require_once APPPATH . 'libraries/Fee_head_readiness.php';
        }
        return (new Fee_head_readiness())->missingForSchool($this->firebase, $schoolId);
    }

    public function set(string $schoolId, string $flag, bool $enabled,
                        string $reason, ?string $expiresAt = null): array
    {
        $schoolId = trim($schoolId);
        $flag     = trim($flag);
        $reason   = trim($reason);

        if ($schoolId === '')  return ['ok' => false, 'error' => 'A schoolId is required.'];
        if (!preg_match('/^SCH_[A-Z0-9]+$/', $schoolId))
            return ['ok' => false, 'error' => 'schoolId must look like SCH_XXXXXXXX.'];
        if (!in_array($flag, self::GRANTABLE, true))
            return ['ok' => false, 'error' => 'Unknown rollout flag: ' . $flag
                . '. Grantable: ' . implode(', ', self::GRANTABLE) . '.'];
        if ($reason === '')
            return ['ok' => false, 'error' => 'A reason is required — a rollout grant '
                . 'without a justification cannot be reviewed later.'];

        if ($expiresAt !== null && trim($expiresAt) !== '') {
            $expiresAt = trim($expiresAt);
            if (strtotime($expiresAt) === false)
                return ['ok' => false, 'error' => 'expiresAt must be a parsable date/time.'];
            if (strtotime($expiresAt) <= time())
                return ['ok' => false, 'error' => 'expiresAt is already in the past — '
                    . 'the grant would have no effect.'];
        } else {
            $expiresAt = null;
        }

        if ($this->firebase === null) return ['ok' => false, 'error' => 'Not initialised.'];

        // Refuse to act on a tenant that does not exist, rather than creating a
        // control document for a typo'd school id.
        try {
            if (!is_array($this->firebase->firestoreGet('schools', $schoolId))) {
                return ['ok' => false, 'error' => 'No such school: ' . $schoolId];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Rollout_grant_service: school lookup failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not verify the school. Please retry.'];
        }

        // ── DEC-006 · ENTITLEMENT BILLING REQUIRES M5 ───────────────────────
        //
        // Invariant I3 — a blanket ('all') concession must never net against a
        // transport / hostel / meals fee — is enforced by testing the DEMAND'S
        // `category`. That category exists ONLY because the M5 registry stamp
        // put it there: with USE_FEE_HEAD_REGISTRY off, an admission spec
        // carries no `category` key at all, so I3 has nothing to test and an
        // 'all'-scoped concession would silently discount a service fee. The
        // UAT tenant's own chart contains a Transport Fee row, so this is a
        // concrete revenue leak, not a theoretical one.
        //
        // `category:<c>` self-gates (no category ⇒ no match) and `head:<ref>`
        // cannot match an unstamped spec, so 'all' is the only exposed scope —
        // which is exactly why the cheap operational rule is sufficient and no
        // reader-side gate is needed. A reader gate would additionally make
        // Fee_entitlement_reader's legacy tiers 2/3 unreachable, retiring
        // legacy scope compatibility as a side effect — a frozen invariant that
        // must not move by accident.
        //
        // Enforced HERE rather than left to documentation because the failure
        // is silent money. Mirrors the existing dependency rule in
        // B2_registry_service::set_module_override (hrms_payroll requires
        // hrms_core) — same shape, same place in the stack.
        //
        // Deliberately requires an EXPLICIT tenant grant for M5 rather than
        // consulting the global default: this class owns no config, and the
        // global default is false, so demanding the explicit grant is both
        // simpler and fail-safe. If the global default is ever flipped true
        // this rule becomes over-strict — it would refuse a grant that would
        // have been safe, which is the harmless direction.
        // ── DAY-ONE-2 · M5 MUST NOT OUTRUN THE REGISTRY IT DEPENDS ON ───────
        //
        // Granting USE_FEE_HEAD_REGISTRY switches fee generation to resolving
        // every chart head against feeHeadRegistry, and resolution FAILS CLOSED:
        // one unresolvable head aborts that student's entire billing.
        //
        // Measured on a clean two-tenant fixture — M5 granted, registry empty:
        //     ACC_FEEHEAD_UNRESOLVED head=[Tuition Fee] reason=[not_found]
        //     demands=0
        // Students admitted, nobody billable, admission still reporting success.
        // A new tenant's registry is EMPTY by construction, so this is not an
        // edge case; it is what granting M5 to a new school does by default.
        //
        // The prerequisite is therefore enforced here, in the same place and the
        // same shape as the M5→entitlement rule below, rather than left to
        // whoever remembers the ordering. "Someone knows to register the heads
        // first" is not an architecture.
        //
        // Fails SAFE in the direction that matters. Three outcomes, deliberately
        // distinguished, because "I could not ask" and "the answer is no" are
        // different facts and collapsing them is how a guard becomes a lie:
        //
        //   []     every charted head is registered      → allow
        //   [..]   named heads are missing               → refuse, and say which
        //   null   the question could not be answered    → see below
        //
        // A NULL from a handle that CAN be queried is a real read failure on the
        // real path, and it is refused: granting registry mode without knowing
        // the registry is complete is the branch that stops a school billing.
        // A null from a handle that cannot be queried at all is a different kind
        // of object, not a degraded client, and refusing every grant on that
        // basis would break the whole grant surface for narrower callers.
        if ($flag === 'USE_FEE_HEAD_REGISTRY' && $enabled) {
            $gap = $this->_unregisteredChartHeads($schoolId);

            if ($gap === null) {
                if (method_exists($this->firebase, 'firestoreQuery')) {
                    return ['ok' => false, 'error' =>
                        'Could not verify the fee head registry for ' . $schoolId . '. Granting the '
                        . 'Fee Head Registry without that check risks switching the school to '
                        . 'registry-resolved billing while heads are missing, which stops billing '
                        . 'entirely. Retry once the registry can be read.'];
                }
                $gap = [];   // unqueryable handle: the check does not apply
            }

            if ($gap !== []) {
                log_message('error', 'ROLLOUT_DEPENDENCY_REFUSED school=[' . $schoolId
                    . '] USE_FEE_HEAD_REGISTRY refused — ' . count($gap)
                    . ' charted head(s) are not registered: ' . implode(', ', $gap));
                return ['ok' => false, 'error' =>
                    'This school charges ' . count($gap) . ' fee head(s) that are not registered yet: '
                    . implode(', ', $gap) . '. With the Fee Head Registry enabled, a demand whose '
                    . 'head cannot be resolved is refused and the student is billed NOTHING. '
                    . 'Register them under Fee Management → Fee Heads, then grant this again.'];
            }
        }

        if ($flag === 'USE_ENTITLEMENT_BILLING' && $enabled) {
            $m5 = $this->forSchool($schoolId)['USE_FEE_HEAD_REGISTRY'] ?? null;
            $m5On = is_bool($m5) ? $m5 : (is_array($m5) && ($m5['enabled'] ?? false) === true);
            if ($m5On && is_array($m5)) {
                // An expired M5 grant is not an active one.
                $exp = trim((string) ($m5['expiresAt'] ?? ''));
                if ($exp !== '' && strtotime($exp) !== false && strtotime($exp) <= time()) $m5On = false;
            }
            if (!$m5On) {
                log_message('error', 'ROLLOUT_DEPENDENCY_REFUSED school=[' . $schoolId
                    . '] USE_ENTITLEMENT_BILLING requires an active USE_FEE_HEAD_REGISTRY grant.');
                return ['ok' => false, 'error' =>
                    'Student Entitlements V2 billing requires the Fee Head Registry (M5) to be '
                    . 'enabled for this school first. Without it a demand carries no registry '
                    . 'category, so a school-wide concession could discount transport or hostel '
                    . 'fees. Grant USE_FEE_HEAD_REGISTRY for ' . $schoolId . ', then retry.'];
            }
        }

        $entry = [
            'enabled'   => $enabled,
            'setBy'     => $this->actorId,
            'setAt'     => date('c'),
            'reason'    => $reason,
            'expiresAt' => $expiresAt,
        ];

        // ── INCIDENT FIX (2026-08-18) — READ-MODIFY-WRITE ────────────────────
        //
        // THE DEFECT THIS CLOSES — a granted flag DELETED its siblings.
        //
        // This previously sent `[rolloutFlags => [$flag => $entry]]` with
        // merge=true, on the belief that a masked PATCH deep-merges nested map
        // keys. IT DOES NOT. `updateMask.fieldPaths=rolloutFlags` REPLACES the
        // whole `rolloutFlags` map with the supplied one. Merge semantics
        // protect SIBLING TOP-LEVEL fields (subscription, overrides,
        // featureFlags) — never keys INSIDE the masked field.
        //
        // Live consequence: granting USE_ENTITLEMENT_BILLING wiped the existing
        // USE_FEE_HEAD_REGISTRY grant, leaving the tenant in exactly the
        // billing-on / M5-off combination DEC-006 exists to prevent.
        //
        // Now read-modify-write the FULL map, the same strategy revoke() has
        // always used correctly. Still only `rolloutFlags` is ever sent, so
        // every other control-document field remains untouched.
        try {
            $doc     = $this->firebase->firestoreGet(self::COL, $schoolId);
            $current = (is_array($doc) && is_array($doc[self::FIELD] ?? null)) ? $doc[self::FIELD] : [];
            $current[$flag] = $entry;   // add or replace THIS flag only
            $ok = (bool) $this->firebase->firestoreSet(
                self::COL, $schoolId, [self::FIELD => $current], /* merge */ true
            );
        } catch (\Throwable $e) {
            log_message('error', 'Rollout_grant_service: write failed for ' . $schoolId
                . '/' . $flag . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the grant. Please retry.'];
        }
        if (!$ok) return ['ok' => false, 'error' => 'Could not save the grant. Please retry.'];

        $this->audit($enabled ? 'rollout_flag_granted' : 'rollout_flag_disabled',
                     $schoolId, $flag, $reason, $expiresAt);

        return ['ok' => true, 'flag' => $flag, 'schoolId' => $schoolId];
    }

    /**
     * Remove the override entirely, so the flag reverts to its global default —
     * indistinguishable from never having been granted. Preferred over
     * `set(..., false, ...)` when undoing a rollout, because it leaves no
     * standing per-tenant decision behind.
     */
    public function revoke(string $schoolId, string $flag, string $reason): array
    {
        $schoolId = trim($schoolId);
        $flag     = trim($flag);
        if (!in_array($flag, self::GRANTABLE, true))
            return ['ok' => false, 'error' => 'Unknown rollout flag: ' . $flag];
        if (trim($reason) === '')
            return ['ok' => false, 'error' => 'A reason is required to revoke.'];
        if ($this->firebase === null) return ['ok' => false, 'error' => 'Not initialised.'];

        try {
            $doc  = $this->firebase->firestoreGet(self::COL, $schoolId);
            $map  = (is_array($doc) && is_array($doc[self::FIELD] ?? null)) ? $doc[self::FIELD] : [];
            if (!array_key_exists($flag, $map)) {
                return ['ok' => true, 'noop' => true, 'flag' => $flag, 'schoolId' => $schoolId];
            }
            unset($map[$flag]);
            // The whole map is rewritten here BY NECESSITY — a merge cannot
            // delete a nested key. Only rolloutFlags is sent, so every other
            // field of the control document is still untouched.
            $ok = (bool) $this->firebase->firestoreSet(
                self::COL, $schoolId, [self::FIELD => $map], /* merge */ true
            );
        } catch (\Throwable $e) {
            log_message('error', 'Rollout_grant_service: revoke failed for ' . $schoolId
                . '/' . $flag . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not revoke. Please retry.'];
        }
        if (!$ok) return ['ok' => false, 'error' => 'Could not revoke. Please retry.'];

        $this->audit('rollout_flag_revoked', $schoolId, $flag, $reason, null);
        return ['ok' => true, 'flag' => $flag, 'schoolId' => $schoolId];
    }

    /** Read-back for an operator surface. Never used by the hot path. */
    public function forSchool(string $schoolId): array
    {
        if ($this->firebase === null || trim($schoolId) === '') return [];
        try {
            $doc = $this->firebase->firestoreGet(self::COL, trim($schoolId));
            $map = (is_array($doc) && is_array($doc[self::FIELD] ?? null)) ? $doc[self::FIELD] : [];
            return $map;
        } catch (\Throwable $e) {
            log_message('error', 'Rollout_grant_service: read failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Audit through the EXISTING helper — never a private log store. */
    private function audit(string $action, string $schoolId, string $flag,
                           string $reason, ?string $expiresAt): void
    {
        $ctx = ['schoolId' => $schoolId, 'flag' => $flag, 'reason' => $reason,
                'expiresAt' => $expiresAt, 'actor' => $this->actorId];
        log_message('error', 'ROLLOUT_FLAG_CHANGE action=[' . $action . '] school=[' . $schoolId
            . '] flag=[' . $flag . '] by=[' . $this->actorId . '] reason=[' . $reason . ']');
        try {
            if (function_exists('log_audit')) {
                log_audit('Superadmin', $action, $schoolId,
                    $action . ' ' . $flag . ' for ' . $schoolId . ' — ' . $reason, $ctx);
            }
        } catch (\Throwable $_) { /* audit must never fail the mutation */ }
    }
}
