<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FINANCIAL ACTIVATION — TURNING A READY TENANT INTO AN OPERATING ONE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE GAP THIS CLOSES ──────────────────────────────────────────────────
 * A school could satisfy every readiness check, be taken live by a
 * superadmin, and still bill on the legacy authority — because the Billing V2
 * capability chain was three rollout flags somebody had to remember, in an
 * order somebody had to know. "The operator remembers" is not an architecture,
 * and the failure it produces is silent: correct-looking bills with no
 * allocation ledger behind them.
 *
 * ── WHY IT IS NOT AUTOMATIC IN THE OTHER DIRECTION ──────────────────────
 * The obvious fix — grant the chain at onboarding — was implemented, measured
 * and REVERTED. Registry mode against an empty registry makes fee head
 * resolution fail closed: students are admitted and billed NOTHING. So
 * activation cannot precede configuration; it has to follow readiness.
 *
 * ── AUTHORITY ────────────────────────────────────────────────────────────
 * This service holds NO authority of its own. Every grant goes through
 * Rollout_grant_service, which owns the allowlist, the dependency rules and
 * the audit trail — including the precondition that refuses registry mode
 * while any charted fee head is unregistered. There is no second flag
 * authority here and no way to bypass those rules.
 *
 * Its CALLERS are what is trusted: the superadmin go-live transition and the
 * operator CLI front door — the same two surfaces already authorized to issue
 * a tenant grant. Nothing school-facing may call this, because a school could
 * then escalate its own tenant's platform capabilities.
 *
 * ── CLEAN TENANT vs HISTORICAL TENANT ────────────────────────────────────
 * A brand-new school and a school with a billing past are different problems,
 * and treating them alike is how money moves silently:
 *
 *   • ACTIVE LEGACY CONCESSIONS — switching the pricing authority would stop
 *     applying discounts that are currently reducing real bills. A silent
 *     price RISE for those families.
 *   • EXISTING DEMANDS — the ledger explains discounts going forward and says
 *     nothing about history. Those tenants need the established
 *     backfill-and-certify path before the ledger means anything.
 *
 * Both are HOLDS, not failures. The school keeps billing correctly on the
 * authority it already has, and migration stays a deliberate act with its own
 * evidence.
 */
class Financial_activation_service
{
    /** The chain, in the order the grant service independently enforces. */
    public const CHAIN = [
        'USE_FEE_HEAD_REGISTRY'   => 'Go-live: readiness passed and every charted fee head is '
            . 'registered, so registry-resolved billing is safe.',
        'USE_ENTITLEMENT_BILLING' => 'Go-live: registry complete and no legacy concessions, so '
            . 'the entitlement authority is the correct model.',
        'USE_ALLOCATION_LEDGER'   => 'Go-live: the entitlement authority supplies a grant '
            . 'identity, so allocation rows can explain every discount.',
    ];

    private $firebase = null;
    private string $actorId = 'system';

    public function init($firebase, string $actorId = 'system'): self
    {
        $this->firebase = $firebase;
        $this->actorId  = trim($actorId) !== '' ? trim($actorId) : 'system';
        return $this;
    }

    /**
     * Would activation proceed for this tenant, and if not, why?
     *
     * Separated from activate() so a caller can show the answer without
     * changing anything — the console, the CLI and the proof all ask this
     * before deciding. Read-only.
     *
     * @return array{eligible:bool, status:string, reason?:string, detail?:string}
     */
    public function eligibility(string $schoolId): array
    {
        if ($this->firebase === null || trim($schoolId) === '') {
            return ['eligible' => false, 'status' => 'error', 'reason' => 'no_tenant'];
        }

        try {
            // Asks the GRANT service what this tenant already holds, rather than
            // the flag resolver. T2 authorises exactly five flag-routing sites,
            // and "has this tenant already been granted?" is a question about
            // grants, not about effective configuration — the grant service is
            // already the reader for it (the CLI's show verb uses the same
            // call). No sixth routing site, and no second flag authority.
            if (!class_exists('Rollout_grant_service')) {
                require_once APPPATH . 'libraries/Rollout_grant_service.php';
            }
            $held = (new Rollout_grant_service())->init($this->firebase, $this->actorId)
                        ->forSchool($schoolId);
            $reg = $held['USE_FEE_HEAD_REGISTRY'] ?? null;
            $regOn = is_bool($reg) ? $reg : (is_array($reg) && ($reg['enabled'] ?? false) === true);
            if ($regOn) {
                return ['eligible' => false, 'status' => 'already_active'];
            }

            // The foundation must exist, or the first grant would be refused
            // anyway — reported here so the caller can say WHICH heads.
            if (!class_exists('Fee_head_readiness')) {
                require_once APPPATH . 'libraries/Fee_head_readiness.php';
            }
            $missing = (new Fee_head_readiness())->missingForSchool($this->firebase, $schoolId);
            if ($missing === null) {
                return ['eligible' => false, 'status' => 'not_ready',
                        'reason' => 'registry_unreadable',
                        'detail' => 'Whether every charged fee head is registered could not be '
                                  . 'determined. Never assume ready.'];
            }
            if ($missing !== []) {
                return ['eligible' => false, 'status' => 'not_ready',
                        'reason' => 'unregistered_fee_heads',
                        'detail' => count($missing) . ' charted head(s) not registered: '
                                  . implode(', ', array_slice($missing, 0, 8))];
            }

            // One active legacy concession makes this a migration, not a start.
            $legacy = $this->firebase->firestoreQuery('studentConcessions',
                [['schoolId', '==', $schoolId], ['status', '==', 'active']], null, 'ASC', 1);
            if (!empty($legacy)) {
                return ['eligible' => false, 'status' => 'held', 'reason' => 'legacy_concessions',
                        'detail' => 'The school holds active legacy concessions. Switching the '
                                  . 'pricing authority would stop applying them.'];
            }

            // Existing demands mean discount history the ledger cannot explain.
            $demands = $this->firebase->firestoreQuery('feeDemands',
                [['schoolId', '==', $schoolId]], null, 'ASC', 1);
            if (!empty($demands)) {
                return ['eligible' => false, 'status' => 'held', 'reason' => 'existing_demands',
                        'detail' => 'Allocation history must be backfilled and certified before '
                                  . 'the ledger is meaningful for this tenant.'];
            }

            return ['eligible' => true, 'status' => 'ready'];
        } catch (\Throwable $e) {
            // Never report READY from a failed question.
            return ['eligible' => false, 'status' => 'error', 'reason' => 'check_failed',
                    'detail' => $e->getMessage()];
        }
    }

    /**
     * Activate the financial capability chain for one tenant.
     *
     * IDEMPOTENT: an already-active tenant returns `already_active` and issues
     * no grant, so a repeated go-live or a retried command cannot rewrite the
     * audit reason of an earlier deliberate decision.
     *
     * TENANT-SCOPED: every grant names this schoolId. Nothing here can touch
     * another tenant, and there is deliberately no "all schools" entry point —
     * estate-wide activation must never be one call away.
     *
     * FAIL-SAFE DIRECTION: partial failure stops the chain and reports what was
     * granted. It never rolls back, because a school left on a half-configured
     * authority is worse than one left on the authority it already had, and the
     * grant service refuses an out-of-order chain anyway.
     *
     * @return array{status:string, reason?:string, detail?:string, granted?:array, failed?:string}
     */
    public function activate(string $schoolId): array
    {
        $elig = $this->eligibility($schoolId);
        if (empty($elig['eligible'])) {
            log_message('error', 'FINANCE_ACTIVATION_SKIPPED school=[' . $schoolId . '] status=['
                . $elig['status'] . '] reason=[' . (string) ($elig['reason'] ?? '-') . ']');
            return $elig;
        }

        try {
            if (!class_exists('Rollout_grant_service')) {
                require_once APPPATH . 'libraries/Rollout_grant_service.php';
            }
            $svc = (new Rollout_grant_service())->init($this->firebase, $this->actorId);

            $granted = [];
            foreach (self::CHAIN as $flag => $why) {
                $r = $svc->set($schoolId, $flag, true, $why);
                if (empty($r['ok'])) {
                    log_message('error', 'FINANCE_ACTIVATION_PARTIAL school=[' . $schoolId
                        . '] flag=[' . $flag . '] refused=[' . (string) ($r['error'] ?? '?')
                        . '] — the school still bills correctly on the previous authority.');
                    return ['status' => 'partial', 'granted' => $granted, 'failed' => $flag,
                            'detail' => (string) ($r['error'] ?? '')];
                }
                $granted[] = $flag;
            }

            log_message('error', 'FINANCE_ACTIVATED school=[' . $schoolId . '] flags=['
                . implode(',', $granted) . '] by=[' . $this->actorId . ']');
            return ['status' => 'activated', 'granted' => $granted];
        } catch (\Throwable $e) {
            log_message('error', 'FINANCE_ACTIVATION_FAILED school=[' . $schoolId . ']: '
                . $e->getMessage());
            return ['status' => 'error', 'reason' => 'activation_failed', 'detail' => $e->getMessage()];
        }
    }
}
