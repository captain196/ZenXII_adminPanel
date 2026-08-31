<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Benefit_award_service — turns VERIFIED eligibility plus SCHOOL POLICY into a
 * benefit, through the entitlement lifecycle that already exists.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE CHAIN, AND WHY EACH LINK IS SEPARATE
 * ─────────────────────────────────────────────────────────────────────────
 *      studentRelationships   verified FACT — who is related to whom
 *              ↓
 *      Benefit_policy_service school DECISION — is this benefit offered?
 *              ↓
 *      this service           ELIGIBILITY — does this student qualify today?
 *              ↓
 *      Entitlement_service    AUTHORITY — the benefit, under maker-checker
 *              ↓
 *      existing pricing       MONEY — with an allocation behind every rupee
 *
 *  Collapsing any two of those is how "ticked a box, got a discount" happens.
 *  A relationship row still carries `financialEffect: 'none'`; this service is
 *  the only thing that may turn evidence into an entitlement, and it may only
 *  do so because a school said it could.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  IT CREATES NOTHING OF ITS OWN
 * ─────────────────────────────────────────────────────────────────────────
 *  No new discount mechanism, no second concession collection, no bypass. It
 *  calls Entitlement_service::propose() — the same path the Services &
 *  Concessions panel uses — so a policy-derived benefit is priced, allocated,
 *  approved, rejected, discontinued and audited by exactly the machinery that
 *  already handles a hand-entered concession. A benefit the finance office
 *  cannot see in the usual queue would be a benefit nobody governs.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE DUPLICATE-MONEY TRAP THIS EXISTS TO AVOID
 * ─────────────────────────────────────────────────────────────────────────
 *  Entitlement ids are a FINGERPRINT over value, scope and dates. So when a
 *  school changes 25% to 10%, an unguarded re-evaluation would propose a
 *  SECOND entitlement and the student would hold both — 35%. Re-running is
 *  therefore not "propose again": any earlier policy-derived entitlement for
 *  the same benefit is DISCONTINUED first, and only then is the new one
 *  proposed. Identical terms fingerprint to the same id and are a no-op.
 */
class Benefit_award_service
{
    /** Stamped on every entitlement this service creates, so they are findable and reversible. */
    public const SOURCE = 'benefit_policy';

    /** @var object|null */ private $policy;
    /** @var object|null */ private $ent;
    /** @var object|null */ private $rel;
    /** @var object|null */ private $fs;

    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';
    private bool   $ready    = false;

    public function init($policy, $ent, $rel, $fs, string $schoolId, string $session, string $actorId): self
    {
        $this->policy   = $policy;
        $this->ent      = $ent;
        $this->rel      = $rel;
        $this->fs       = $fs;
        $this->schoolId = trim($schoolId);
        $this->session  = trim($session);
        $this->actorId  = trim($actorId) !== '' ? trim($actorId) : 'system';
        $this->ready    = ($policy !== null && $ent !== null && $rel !== null && $this->schoolId !== '');
        return $this;
    }

    /**
     * Evaluate ONE benefit for ONE student and bring the entitlement into line
     * with the school's current policy.
     *
     * @return array{outcome:string, reason:string, entitlementId:string, value:float}
     */
    public function evaluate(string $studentId, string $benefit, string $onDate = ''): array
    {
        $skip = function (string $outcome, string $reason): array {
            return ['outcome' => $outcome, 'reason' => $reason, 'entitlementId' => '', 'value' => 0.0];
        };

        if (!$this->ready)            return $skip('not_ready', 'Benefit service not initialised.');
        if (trim($studentId) === '')  return $skip('not_ready', 'studentId is required.');

        try {
            $pol    = $this->policy->resolve($benefit);
            $onDate = $onDate !== '' ? $onDate : date('Y-m-d');

            // ── 1. Does the school offer this at all? ──────────────────────
            // Checked BEFORE eligibility on purpose: a disabled benefit must
            // not even read a family's relationships.
            if (($pol['mode'] ?? 'disabled') === Benefit_policy_service::MODE_DISABLED) {
                $this->retireStale($studentId, $benefit, 'the school does not offer this benefit');
                return $skip('policy_disabled', 'No benefit: the school has not enabled it.');
            }
            if (!$this->policy->isInForce($pol, $onDate)) {
                $this->retireStale($studentId, $benefit, 'the policy is not in force on ' . $onDate);
                return $skip('policy_not_in_force',
                    'No benefit: the policy is future-dated or has ended.');
            }

            // ── 2. Is THIS student eligible, on verified evidence only? ─────
            $elig = $this->eligibility($studentId, $benefit, $pol);
            if (empty($elig['ok'])) {
                $this->retireStale($studentId, $benefit, (string) $elig['reason']);
                return $skip('not_eligible', (string) $elig['reason']);
            }

            // ── 3. Supersede whatever this policy granted before ────────────
            // Before proposing, so a rate change can never leave two live
            // entitlements stacking on one student.
            //
            // The effective-from is computed ONCE and used both to decide what
            // counts as "the same terms" and in the proposal itself. Deriving
            // it twice was a real bug: the comparison used the policy's own
            // (often empty) effectiveFrom while the proposal used today's date,
            // so the terms never matched, every re-run discontinued its own
            // entitlement, and a second evaluation left the student with a
            // discontinued benefit and no active one.
            $effFrom = $this->effectiveFrom($pol, $onDate);

            $this->retireStale($studentId, $benefit, 'superseded by policy version '
                . (string) ($pol['policyVersion'] ?? 0),
                /* keepMatching */ $this->terms($pol, $effFrom));

            // ── 4. Propose through the canonical lifecycle ──────────────────
            // approvalRequired comes from the POLICY, never from the caller.
            $needsApproval = ($pol['mode'] === Benefit_policy_service::MODE_APPROVAL);

            $res = $this->ent->propose([
                'studentId'      => $studentId,
                'kind'           => 'concession',
                'concessionType' => $benefit === Benefit_policy_service::BENEFIT_SIBLING
                                    ? 'sibling' : 'staff_ward',
                'scope'          => (string) $pol['scope'],
                'valueType'      => (string) $pol['valueType'],
                'value'          => (float)  $pol['value'],
                'effectiveFrom'  => $effFrom,
                'effectiveTo'    => (string) ($pol['effectiveTo'] ?? ''),
                'reason'         => $this->reasonLine($benefit, $pol, $elig),
                'source'         => self::SOURCE,
                'approvalRequired' => $needsApproval,
            ]);

            if (empty($res['ok'])) {
                log_message('error', 'BENEFIT_AWARD_REFUSED school=[' . $this->schoolId
                    . '] student=[' . $studentId . '] benefit=[' . $benefit
                    . '] error=[' . (string) ($res['error'] ?? '?') . ']');
                return $skip('refused', (string) ($res['error'] ?? 'The entitlement was refused.'));
            }

            $entId = (string) ($res['id'] ?? $res['entitlementId'] ?? '');

            // The policy SNAPSHOT, written beside the entitlement. A later rate
            // change must not make it impossible to answer "on what terms was
            // this granted?" — the entitlement itself carries value and dates,
            // and this records the policy version and mode behind them.
            $this->stampSnapshot($entId, $studentId, $benefit, $pol, $elig);

            log_message('error', 'BENEFIT_AWARDED school=[' . $this->schoolId
                . '] student=[' . $studentId . '] benefit=[' . $benefit
                . '] mode=[' . $pol['mode'] . '] value=[' . $pol['valueType'] . ':' . $pol['value']
                . '] scope=[' . $pol['scope'] . '] policyVersion=[' . (int) $pol['policyVersion']
                . '] entitlement=[' . $entId . '] status=['
                . ($needsApproval ? 'pending_approval' : 'active') . ']');

            return [
                'outcome'       => $needsApproval ? 'pending_approval' : 'granted',
                'reason'        => (string) $elig['reason'],
                'entitlementId' => $entId,
                'value'         => (float) $pol['value'],
            ];
        } catch (\Throwable $e) {
            // Fail CLOSED: an error must never leave a benefit half-granted.
            log_message('error', "BENEFIT_AWARD_FAILED [{$studentId}/{$benefit}]: " . $e->getMessage());
            return $skip('error', 'The benefit could not be evaluated.');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  ELIGIBILITY — verified evidence only
    // ══════════════════════════════════════════════════════════════════════

    /** @return array{ok:bool, reason:string, evidence:array} */
    private function eligibility(string $studentId, string $benefit, array $pol): array
    {
        $no = function (string $why): array { return ['ok' => false, 'reason' => $why, 'evidence' => []]; };

        $kind = $benefit === Benefit_policy_service::BENEFIT_SIBLING ? 'sibling' : 'staff_ward';
        $rows = (array) $this->rel->forStudent($studentId, $kind);
        if ($rows === []) {
            return $no('No verified ' . str_replace('_', ' ', $kind) . ' relationship on record.');
        }

        if ($benefit === Benefit_policy_service::BENEFIT_SIBLING) {
            if (!empty($pol['requireSiblingActive'])) {
                foreach ($rows as $r) {
                    $sid = (string) ($r['relatedId'] ?? '');
                    if ($sid === '') continue;
                    $stu = $this->fs !== null ? $this->fs->get('students', $this->schoolId . '_' . $sid) : null;
                    $st  = is_array($stu) ? strtolower(trim((string) ($stu['status'] ?? $stu['Status'] ?? 'active'))) : '';
                    if ($st === '' || $st === 'active') {
                        return ['ok' => true, 'reason' => "Verified sibling {$sid} is on the roll.",
                                'evidence' => ['relatedId' => $sid]];
                    }
                }
                return $no('The verified sibling is no longer active, and this school\'s policy '
                         . 'requires an active sibling.');
            }
            $first = (string) ($rows[0]['relatedId'] ?? '');
            return ['ok' => true, 'reason' => "Verified sibling {$first}.",
                    'evidence' => ['relatedId' => $first]];
        }

        // Staff ward
        foreach ($rows as $r) {
            $staffId = (string) ($r['relatedId'] ?? '');
            if ($staffId === '') continue;

            if (!empty($pol['requireStaffActive'])) {
                $staff = $this->fs !== null ? $this->fs->get('staff', $this->schoolId . '_' . $staffId) : null;
                if (!is_array($staff) || $staff === []) {
                    return $no("The linked employee {$staffId} no longer exists in this school.");
                }
                $st = strtolower(trim((string) ($staff['status'] ?? $staff['Status'] ?? 'active')));
                if ($st !== '' && $st !== 'active') {
                    return $no("The linked employee {$staffId} is {$st}, and this school's policy "
                             . 'requires current employment.');
                }
            }

            // A per-employee child cap is a real school rule ("we cover two
            // children"). Enforced on the VERIFIED links for that employee, so
            // it cannot be gamed by re-declaring.
            $cap = (int) ($pol['maxChildren'] ?? 0);
            if ($cap > 0) {
                $siblings = $this->childrenOfStaff($staffId);
                if (count($siblings) > $cap && !in_array($studentId, array_slice($siblings, 0, $cap), true)) {
                    return $no("This school covers {$cap} child(ren) per employee, and this student "
                             . 'is beyond that limit.');
                }
            }

            return ['ok' => true, 'reason' => "Verified ward of active employee {$staffId}.",
                    'evidence' => ['relatedId' => $staffId]];
        }
        return $no('No usable staff-ward relationship.');
    }

    /**
     * Every student verified against one employee, in a STABLE order, so "the
     * first two children" means the same thing on every run. An unstable order
     * would move the benefit between siblings on each evaluation.
     */
    private function childrenOfStaff(string $staffId): array
    {
        try {
            // schoolWhere() — see Student_relationship_service::forStudent().
            $rows = (array) $this->fs->schoolWhere('studentRelationships', [
                ['kind', '=', 'staff_ward'], ['relatedId', '=', $staffId],
            ]);
            $ids = [];
            foreach ($rows as $r) {
                $d = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                if ((string) ($d['status'] ?? 'active') !== 'active') continue;
                $sid = (string) ($d['studentId'] ?? '');
                if ($sid !== '') $ids[$sid] = true;
            }
            $ids = array_keys($ids);
            sort($ids);   // deterministic
            return $ids;
        } catch (\Throwable $e) {
            log_message('error', "BENEFIT_STAFF_CHILDREN_FAILED [{$staffId}]: " . $e->getMessage());
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SUPERSEDING
    // ══════════════════════════════════════════════════════════════════════

    /** The terms that identify "the same benefit on the same terms". */
    private function terms(array $pol, string $effFrom): string
    {
        return implode('|', [
            (string) $pol['valueType'],
            // Normalised like Entitlement_service's own fingerprint, so 25,
            // '25' and 25.0 are one set of terms rather than three.
            number_format((float) $pol['value'], 4, '.', ''),
            (string) $pol['scope'],
            $effFrom,
            (string) ($pol['effectiveTo'] ?? ''),
        ]);
    }

    /**
     * Discontinue policy-derived entitlements for this benefit that no longer
     * match the current terms.
     *
     * Only ever touches rows this service created (`source = benefit_policy`):
     * a hand-entered concession the finance office approved is not ours to
     * withdraw, whatever the policy now says.
     *
     * Discontinue, never delete — the entitlement stays on the record with an
     * effectiveTo, so anything already priced under it remains explicable.
     */
    private function retireStale(string $studentId, string $benefit, string $why, string $keepMatching = ''): void
    {
        try {
            $type = $benefit === Benefit_policy_service::BENEFIT_SIBLING ? 'sibling' : 'staff_ward';
            foreach ((array) $this->ent->forStudent($studentId, 200) as $e) {
                if (!is_array($e)) continue;
                if ((string) ($e['source'] ?? '') !== self::SOURCE) continue;
                if ((string) ($e['concessionType'] ?? '') !== $type) continue;

                $status = strtolower((string) ($e['status'] ?? ''));
                if (!in_array($status, ['active', 'pending_approval'], true)) continue;

                if ($keepMatching !== '' && $this->termsOf($e) === $keepMatching) continue;

                $id = (string) ($e['entitlementId'] ?? $e['id'] ?? '');
                if ($id === '') continue;

                $this->ent->discontinue($id, 'Policy benefit withdrawn: ' . $why);
                log_message('error', 'BENEFIT_WITHDRAWN school=[' . $this->schoolId
                    . '] student=[' . $studentId . '] benefit=[' . $benefit
                    . '] entitlement=[' . $id . '] reason=[' . $why . ']');
            }
        } catch (\Throwable $e) {
            log_message('error', "BENEFIT_RETIRE_FAILED [{$studentId}/{$benefit}]: " . $e->getMessage());
        }
    }

    private function termsOf(array $e): string
    {
        return implode('|', [
            (string) ($e['valueType'] ?? ''),
            number_format((float) ($e['value'] ?? 0), 4, '.', ''),
            (string) ($e['scope'] ?? ''),
            (string) ($e['effectiveFrom'] ?? ''), (string) ($e['effectiveTo'] ?? ''),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  PROVENANCE
    // ══════════════════════════════════════════════════════════════════════

    /** Never earlier than the policy allows, and never back-dated into the past. */
    private function effectiveFrom(array $pol, string $onDate): string
    {
        $from = (string) ($pol['effectiveFrom'] ?? '');
        return ($from !== '' && strcmp($from, $onDate) > 0) ? $from : $onDate;
    }

    private function reasonLine(string $benefit, array $pol, array $elig): string
    {
        $label = $benefit === Benefit_policy_service::BENEFIT_SIBLING ? 'Sibling' : 'Staff ward';
        return $label . ' benefit under school policy v' . (int) ($pol['policyVersion'] ?? 0)
             . ' (' . $pol['valueType'] . ' ' . $pol['value'] . ', scope ' . $pol['scope'] . '). '
             . (string) ($elig['reason'] ?? '');
    }

    /**
     * The immutable record of WHY this benefit exists, keyed to the
     * entitlement. Deliberately separate from the entitlement document so that
     * stamping provenance can never alter a financial record.
     *
     * `benefitValue` and `benefitScope` are kept explicitly because a staff-ward
     * waiver is a taxable perquisite in India above Rs 1,000 per child per
     * month (Rule 3(5)); whoever reports it later needs the amount attributed
     * to a named employee and a named child. Recording it costs nothing now and
     * cannot be reconstructed afterwards.
     */
    private function stampSnapshot(string $entId, string $studentId, string $benefit, array $pol, array $elig): void
    {
        if ($entId === '' || $this->fs === null) return;
        try {
            $this->fs->set('studentBenefitGrants', $this->schoolId . '_' . $entId, [
                'schoolId'      => $this->schoolId,
                'session'       => $this->session,
                'entitlementId' => $entId,
                'studentId'     => $studentId,
                'benefit'       => $benefit,
                'policyVersion' => (int) ($pol['policyVersion'] ?? 0),
                'policyMode'    => (string) $pol['mode'],
                'benefitValue'  => (float)  $pol['value'],
                'benefitType'   => (string) $pol['valueType'],
                'benefitScope'  => (string) $pol['scope'],
                'evidence'      => (array)  ($elig['evidence'] ?? []),
                'grantedBy'     => $this->actorId,
                'grantedAt'     => date('c'),
            ], /* merge */ true);
        } catch (\Throwable $e) {
            log_message('error', "BENEFIT_SNAPSHOT_FAILED [{$entId}]: " . $e->getMessage());
        }
    }
}
