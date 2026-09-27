<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ROLLOUT GRANT — CLI FRONT DOOR TO THE AUTHORIZED GRANT SERVICE
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php index.php rollout_grant_cli show   <SCHOOL_ID>
 *   php index.php rollout_grant_cli grant  <SCHOOL_ID> <FLAG> "<reason>" --commit
 *   php index.php rollout_grant_cli revoke <SCHOOL_ID> <FLAG> "<reason>" --commit
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 * `Rollout_grant_service` is the authorized grant surface and already owns
 * every rule that matters: the GRANTABLE allowlist, the schoolId shape check,
 * the mandatory reason, expiry validation, tenant existence and the audited
 * merge into `schoolControl.rolloutFlags`.
 *
 * Its only existing caller is `Superadmin_rollout`, an HTTP controller behind
 * a superadmin session — unusable from a terminal during a controlled,
 * evidence-logged activation. This adds a CLI caller and NOTHING else: no
 * second grant path, no reimplemented validation, no direct write to
 * `schoolControl`. Every decision still belongs to the service.
 *
 * ── WRITING IS OPT-IN ─────────────────────────────────────────────────────
 * `show` is read-only. `grant` and `revoke` additionally require `--commit`,
 * matching the ledger backfill's convention: a flag that changes how money is
 * computed for a live tenant should not flip on a single mistyped command.
 */
class Rollout_grant_cli extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) show_404();
        $this->load->library('firebase');
        $this->load->library('Rollout_grant_service', null, 'grants');
        $this->grants->init($this->firebase, 'rollout_grant_cli');
    }

    /** Read-only: what is granted for this tenant right now. */
    /**
     * Activate the financial capability chain for ONE financially ready tenant.
     *
     *   php index.php rollout_grant_cli activate_ready <SCHOOL_ID>            (dry-run)
     *   php index.php rollout_grant_cli activate_ready <SCHOOL_ID> --commit
     *
     * ── WHY THIS EXISTS ALONGSIDE GO-LIVE ───────────────────────────────────
     * The primary path is the superadmin go-live transition, which activates a
     * tenant as the consequence of a readiness-gated decision. This is the same
     * decision, exercised from the operator front door — for a tenant that went
     * live before this existed, or one whose activation was HELD and has since
     * been migrated.
     *
     * It holds no authority of its own: the eligibility rules and every grant
     * belong to Financial_activation_service and Rollout_grant_service. Refusing
     * to duplicate them here is the point — a second copy would eventually
     * disagree with the console.
     *
     * DRY-RUN BY DEFAULT, and deliberately SINGLE-TENANT: there is no "all
     * schools" form, because estate-wide activation must never be one call away.
     */
    public function activate_ready($schoolId = '')
    {
        $schoolId = trim((string) $schoolId);
        if ($schoolId === '') {
            fwrite(STDERR, "usage: … activate_ready <SCHOOL_ID> [--commit]
");
            exit(2);
        }
        $commit = in_array('--commit', (array) ($_SERVER['argv'] ?? []), true);

        $this->load->library('Financial_activation_service', null, 'financeActivation');
        $svc = $this->financeActivation->init($this->firebase, 'rollout_grant_cli');

        echo "FINANCIAL ACTIVATION — {$schoolId}
" . str_repeat('=', 60) . "
";
        echo '  mode: ' . ($commit ? '*** COMMIT ***' : 'DRY-RUN (no writes)') . "

";

        $elig = $svc->eligibility($schoolId);
        printf("  eligible : %s
", !empty($elig['eligible']) ? 'YES' : 'no');
        printf("  status   : %s
", (string) $elig['status']);
        if (!empty($elig['reason'])) printf("  reason   : %s
", (string) $elig['reason']);
        if (!empty($elig['detail'])) printf("  detail   : %s
", (string) $elig['detail']);

        if (empty($elig['eligible'])) {
            echo "
  NOT ACTIVATED. Nothing was written.
";
            exit(empty($elig['eligible']) && $elig['status'] === 'already_active' ? 0 : 1);
        }
        if (!$commit) {
            echo "
  Would activate: " . implode(', ', array_keys(Financial_activation_service::CHAIN))
               . "
  Re-run with --commit to apply.
";
            exit(0);
        }

        $res = $svc->activate($schoolId);
        printf("
  result : %s
", (string) $res['status']);
        if (!empty($res['granted'])) printf("  granted: %s
", implode(', ', $res['granted']));
        if (!empty($res['failed']))  printf("  failed : %s
", (string) $res['failed']);
        exit($res['status'] === 'activated' ? 0 : 1);
    }

    public function show($schoolId = '')
    {
        $schoolId = trim((string) $schoolId);
        if ($schoolId === '') { fwrite(STDERR, "usage: … show <SCHOOL_ID>\n"); exit(2); }

        $flags = $this->grants->forSchool($schoolId);
        echo "ROLLOUT GRANTS — {$schoolId}\n" . str_repeat('=', 60) . "\n";
        if (!$flags) { echo "  (no per-tenant grants)\n"; exit(0); }
        foreach ($flags as $flag => $meta) {
            $on = is_array($meta) ? !empty($meta['enabled']) : (bool) $meta;
            printf("  %-34s %s\n", $flag, $on ? 'GRANTED' : 'off');
            if (is_array($meta)) {
                foreach (['reason', 'grantedAt', 'grantedBy', 'expiresAt'] as $k) {
                    if (!empty($meta[$k])) printf("      %-10s %s\n", $k, $meta[$k]);
                }
            }
        }
        exit(0);
    }

    public function grant($schoolId = '', $flag = '', $reason = '')
    {
        $this->_apply($schoolId, $flag, $reason, true);
    }

    /**
     * Hold ONE tenant back from a flag that is ON by default.
     *
     * ── WHY THIS EXISTS (2026-08-30) ────────────────────────────────────────
     * `revoke` DELETES the per-tenant entry. While every V2 default was false
     * that meant "off", so revoke was a working kill switch. Now that the
     * billing chain ships ON, deleting the override falls back to the global
     * default — which is ON — so revoke silently stopped disabling anything.
     *
     * Found by the SVC-1 proof: after revoking service pricing the fare was
     * still discounted, because the tenant had simply returned to the default.
     *
     * This writes an EXPLICIT per-tenant false, which Rollout_flag_service
     * already honours over an enabled global ("a tenant explicitly held back
     * must not be switched on by the global default"). It is the only way to
     * roll a capability back for one school without moving the default for
     * every school.
     *
     * Use `revoke` to forget a decision; use `hold` to reverse one.
     */
    public function hold($schoolId = '', $flag = '', $reason = '')
    {
        $this->_apply($schoolId, $flag, $reason, false, /* explicitOff */ true);
    }

    public function revoke($schoolId = '', $flag = '', $reason = '')
    {
        $this->_apply($schoolId, $flag, $reason, false);
    }

    private function _apply($schoolId, $flag, $reason, bool $enable, bool $explicitOff = false): void
    {
        $schoolId = trim((string) $schoolId);
        $flag     = trim((string) $flag);
        $reason   = trim((string) $reason);
        $verb     = $enable ? 'grant' : 'revoke';

        if ($schoolId === '' || $flag === '' || $reason === '') {
            fwrite(STDERR, "usage: … {$verb} <SCHOOL_ID> <FLAG> \"<reason>\" --commit\n"
                . "  a reason is mandatory — a grant without one cannot be reviewed later\n");
            exit(2);
        }
        if (!in_array('--commit', (array) ($_SERVER['argv'] ?? []), true)) {
            fwrite(STDERR, "REFUSING: `{$verb}` requires --commit as well.\n"
                . "This changes how money is computed for a live tenant.\n");
            exit(2);
        }

        echo ($enable ? 'GRANT' : 'REVOKE') . " {$flag} — {$schoolId}\n";
        echo "reason: {$reason}\n" . str_repeat('=', 60) . "\n";

        $res = $enable
            ? $this->grants->set($schoolId, $flag, true, $reason)
            : ($explicitOff
                // An explicit per-tenant OFF, which beats an enabled global.
                ? $this->grants->set($schoolId, $flag, false, $reason)
                // Forget the override entirely and fall back to the default.
                : $this->grants->revoke($schoolId, $flag, $reason));

        if (empty($res['ok'])) {
            fwrite(STDERR, 'REFUSED by Rollout_grant_service: '
                . (string) ($res['error'] ?? 'unknown') . "\n");
            exit(1);
        }

        echo "OK — the grant service accepted the change.\n\n";
        $this->show($schoolId);
    }
}
