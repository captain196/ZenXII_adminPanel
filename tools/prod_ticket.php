<?php
/**
 * prod_ticket — mint / list / revoke SINGLE-USE production authorisation tickets.
 *
 * ── WHY A TICKET AND NOT A TOKEN ──────────────────────────────────────────
 * A shared token has to reach whoever runs the command, so it ends up pasted
 * into a chat window, a note or a shell history — and once it exists it
 * authorises every run, forever. A ticket inverts that. The authorising act is
 * a human running THIS command on the machine that will perform the write. The
 * nonce is generated here, stays here, and is consumed on first use. It never
 * needs to be read aloud, transmitted or recorded, so there is nothing to leak
 * and nothing to reuse.
 *
 * A ticket is bound to:
 *   • one tenant   — it cannot move to another school
 *   • one stage    — `admit` does not authorise `collect`
 *   • one plan     — the sha256 of the approved manifest; edit the plan and
 *                    every outstanding ticket becomes void automatically
 *   • a short life — default 30 minutes, so an approval cannot be replayed later
 *
 * Tickets live OUTSIDE the repository (~/.zenxii/prod_tickets) so one can never
 * be committed, and this command never prints a nonce — only a short digest,
 * enough to correlate with the consumption ledger.
 *
 * Usage:
 *   php tools/prod_ticket.php issue  <stage> [minutes]
 *   php tools/prod_ticket.php list
 *   php tools/prod_ticket.php revoke <stage>|--all
 *   php tools/prod_ticket.php ledger
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

const TENANT      = 'SCH_D94FE8F7AD';
/**
 * The stage list is READ FROM THE CONTROLLER, never restated here.
 *
 * These two files each held their own list and they had already drifted:
 * the controller dispatches seed_coa/add_section/fee_chart/admit while this
 * tool still offered preflight/generate/collect. A ticket for a stage the
 * controller cannot run is useless, and — worse — a missing stage silently
 * cannot be authorised at all. One source, parsed and verified.
 */
function zx_stages(): array
{
    $src = @file_get_contents(__DIR__ . '/../application/controllers/Production_admission_probe.php');
    if ($src === false || !preg_match('/private const STAGES\s*=\s*\[(.*?)\];/s', $src, $m)) {
        fwrite(STDERR, "REFUSING: cannot read the controller's STAGES list.
"
            . "A ticket must name a stage the controller will actually run.
");
        exit(3);
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $s);
    if (empty($s[1])) { fwrite(STDERR, "REFUSING: controller STAGES list is empty.
"); exit(3); }
    return $s[1];
}
const MAX_MINUTES = 120;

function zx_dir(): string
{
    $home = getenv('USERPROFILE') ?: (getenv('HOME') ?: sys_get_temp_dir());
    $d = rtrim($home, "\/") . DIRECTORY_SEPARATOR . '.zenxii' . DIRECTORY_SEPARATOR . 'prod_tickets';
    if (!is_dir($d) && !@mkdir($d, 0700, true)) { fwrite(STDERR, "cannot create {$d}\n"); exit(2); }
    return $d;
}

/** Must match Production_admission_probe::_planDigest() exactly. */
function zx_plan_digest(): string
{
    $plan = __DIR__ . '/../PRODUCTION_ADMISSION_MANIFEST.md';
    return is_file($plan) ? hash('sha256', (string) file_get_contents($plan)) : 'NO_PLAN';
}

function zx_file(string $dir, string $stage): string
{
    return $dir . DIRECTORY_SEPARATOR . TENANT . '.' . $stage . '.ticket.json';
}

$dir = zx_dir();
$cmd = $argv[1] ?? '';

if ($cmd === 'issue') {
    $stage = (string) ($argv[2] ?? '');
    if (!in_array($stage, zx_stages(), true)) {
        fwrite(STDERR, "unknown stage '{$stage}'. One of: " . implode(', ', zx_stages()) . "\n"); exit(2);
    }
    $mins = (int) ($argv[3] ?? 30);
    if ($mins < 1 || $mins > MAX_MINUTES) {
        fwrite(STDERR, "minutes must be 1.." . MAX_MINUTES . " — an approval must not outlive the run\n"); exit(2);
    }
    $digest = zx_plan_digest();
    if ($digest === 'NO_PLAN') {
        fwrite(STDERR, "REFUSING: PRODUCTION_ADMISSION_MANIFEST.md is absent.\n"
            . "A ticket authorises an APPROVED plan. With no plan there is nothing to authorise.\n"); exit(3);
    }

    // Written 0600, and the nonce is never echoed.
    $ticket = [
        'version'    => 1,
        'tenant'     => TENANT,
        'stage'      => $stage,
        'planDigest' => $digest,
        'issuedAt'   => time(),
        'expiresAt'  => time() + ($mins * 60),
        'issuedBy'   => (string) (getenv('USERNAME') ?: getenv('USER') ?: 'unknown'),
        'nonce'      => bin2hex(random_bytes(32)),
    ];
    $f = zx_file($dir, $stage);
    if (file_put_contents($f, json_encode($ticket, JSON_PRETTY_PRINT)) === false) {
        fwrite(STDERR, "could not write ticket\n"); exit(2);
    }
    @chmod($f, 0600);

    echo "ticket issued\n";
    echo "  tenant     " . TENANT . "\n";
    echo "  stage      {$stage}\n";
    echo "  plan       " . substr($digest, 0, 12) . "  (PRODUCTION_ADMISSION_MANIFEST.md)\n";
    echo "  expires    " . date('c', $ticket['expiresAt']) . "  ({$mins} min)\n";
    echo "  ref        " . substr(hash('sha256', $ticket['nonce']), 0, 12) . "\n";
    echo "  single-use — consumed before the write; a re-run needs a new ticket\n";
    echo "  the nonce is NOT displayed and must never be quoted anywhere\n";
    exit(0);
}

if ($cmd === 'list') {
    $now = time(); $n = 0;
    foreach (zx_stages() as $stage) {
        $f = zx_file($dir, $stage);
        if (!is_file($f)) continue;
        $t = json_decode((string) file_get_contents($f), true);
        $n++;
        printf("  %-10s expires %-26s plan=%s %s\n",
            $stage,
            date('c', (int) ($t['expiresAt'] ?? 0)),
            substr((string) ($t['planDigest'] ?? '?'), 0, 12),
            ((int) ($t['expiresAt'] ?? 0) < $now ? 'EXPIRED'
                : ((string) ($t['planDigest'] ?? '') !== zx_plan_digest() ? 'VOID (plan changed)' : 'valid')));
    }
    if (!$n) echo "  no outstanding tickets\n";
    exit(0);
}

if ($cmd === 'revoke') {
    $what = (string) ($argv[2] ?? '');
    $targets = $what === '--all' ? zx_stages() : [$what];
    foreach ($targets as $stage) {
        $f = zx_file($dir, $stage);
        if (is_file($f) && @unlink($f)) echo "  revoked {$stage}\n";
    }
    exit(0);
}

if ($cmd === 'ledger') {
    $l = $dir . DIRECTORY_SEPARATOR . 'consumed.log';
    echo is_file($l) ? file_get_contents($l) : "  no ticket has been consumed\n";
    exit(0);
}

fwrite(STDERR, "usage: php tools/prod_ticket.php issue <stage> [minutes] | list | revoke <stage>|--all | ledger\n"
    . "stages: " . implode(', ', zx_stages()) . "\n");
exit(2);
