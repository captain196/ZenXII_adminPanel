<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-5 · THE WEBHOOK WAS 403-ing EVERY REAL DELIVERY IN PRODUCTION
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `payment_webhook()` compared `$_SERVER['REMOTE_ADDR']` against a list of
 * Razorpay addresses and refused anything else with 403 — before the HMAC
 * check.
 *
 * zenxii.com is served **Proxied (orange cloud)** through Cloudflare
 * (PATH_A_US_SERVER_RUNBOOK.md, step 8). On the origin, REMOTE_ADDR is
 * therefore a CLOUDFLARE EDGE address and never the gateway's, and CI's
 * `proxy_ips` is empty so nothing recovered the true one. Every genuine,
 * correctly-signed Razorpay delivery would have been refused, with the
 * operator seeing only "IP not whitelisted".
 *
 * Nothing downstream of that gate could run — so the entire PAY-OPS-4
 * reconciliation path was unreachable in production no matter how correct it
 * was. This was established from the repo's own runbook and code, not guessed.
 *
 * ── THE TWO HALVES OF THE FIX ────────────────────────────────────────────
 *   1. Resolve the TRUE client IP — trust `CF-Connecting-IP` only when the
 *      unforgeable peer address is inside Cloudflare's ranges.
 *   2. Demote the allowlist from a hard gate to a signal, because a hardcoded
 *      list of provider IPs can only ever drift out of date and Razorpay's own
 *      guidance is to authenticate by SIGNATURE. The HMAC — already mandatory
 *      for every real provider — remains the gate. A school that wants the IP
 *      check enforcing opts in via `webhook_allowed_ips`.
 *
 * ── HOW THE MATCHER IS DRIVEN ────────────────────────────────────────────
 * `_ipInCidrAny()` is pure and uses no `$this`, so its body is lifted out of
 * the shipped source and executed. A CI3 controller cannot be instantiated
 * outside the framework, and a mirrored copy would be free to drift from the
 * code it claims to test.
 */
final class WebhookClientIpTest extends TestCase
{
    private static string $src  = '';
    private static string $code = '';
    /** @var callable */
    private static $inCidr;

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $out = '';
        foreach (token_get_all(self::$src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        self::$code = preg_replace("/(\r?\n)(?:[ \t]*\r?\n)+/", "\$1", $out);

        $sig   = 'private function _ipInCidrAny(string $ip, string $cidr): bool';
        $start = strpos(self::$src, $sig);
        if ($start === false) {
            self::fail('_ipInCidrAny() is gone — the IPv6-capable matcher the Cloudflare '
                . 'peer check depends on has been removed.');
        }
        $open  = strpos(self::$src, '{', $start);
        $depth = 0; $end = null;
        for ($i = $open, $n = strlen(self::$src); $i < $n; $i++) {
            if (self::$src[$i] === '{') $depth++;
            elseif (self::$src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        if ($end === null) self::fail('could not brace-match _ipInCidrAny()');

        $body = substr(self::$src, $open + 1, $end - $open - 1);
        self::$inCidr = eval('return function (string $ip, string $cidr): bool {' . $body . '};');
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        return (self::$inCidr)($ip, $cidr);
    }

    // ══ THE MATCHER ═════════════════════════════════════════════════════════

    /**
     * @dataProvider cidrCases
     */
    public function test_cidr_membership(string $ip, string $cidr, bool $expected, string $why): void
    {
        $this->assertSame($expected, $this->inCidr($ip, $cidr), $why);
    }

    public function cidrCases(): array
    {
        return [
            // Real Cloudflare v4 ranges.
            'cf v4 inside'        => ['104.16.0.1',    '104.16.0.0/13',  true,  'a Cloudflare edge address is not recognised, so CF-Connecting-IP would be ignored and the CDN address used as the client'],
            'cf v4 upper edge'    => ['104.23.255.255','104.16.0.0/13',  true,  'the top of the range is excluded — an off-by-one in the mask'],
            'cf v4 just outside'  => ['104.24.0.0',    '104.16.0.0/13',  false, 'an address outside the range is accepted as Cloudflare'],
            'cf v4 /22 inside'    => ['131.0.72.5',    '131.0.72.0/22',  true,  ''],
            'cf v4 /22 outside'   => ['131.0.76.1',    '131.0.72.0/22',  false, ''],

            // Non-Cloudflare traffic must never be treated as CDN traffic.
            'razorpay ip'         => ['52.66.166.11',  '104.16.0.0/13',  false, 'a Razorpay address matched a Cloudflare range'],
            'loopback'            => ['127.0.0.1',     '104.16.0.0/13',  false, ''],
            'private range'       => ['10.0.0.7',      '104.16.0.0/13',  false, ''],

            // IPv6 — the reason _ipInCidr (ip2long, v4-only) could not be reused.
            'cf v6 inside'        => ['2606:4700::1',      '2606:4700::/32', true,  'Cloudflare serves heavily over IPv6; treating those as non-CDN means reading the edge address as the client'],
            'cf v6 outside'       => ['2606:4701::1',      '2606:4700::/32', false, ''],
            'cf v6 /29 inside'    => ['2a06:98c0::1',      '2a06:98c0::/29', true,  ''],
            'v6 elsewhere'        => ['2001:4860:4860::88','2606:4700::/32', false, 'an unrelated IPv6 address matched a Cloudflare range'],

            // Address families must never be confused.
            'v4 against v6 range' => ['104.16.0.1',    '2606:4700::/32', false, 'an IPv4 address matched an IPv6 range'],
            'v6 against v4 range' => ['2606:4700::1',  '104.16.0.0/13',  false, 'an IPv6 address matched an IPv4 range'],

            // Malformed input must refuse, never throw or match.
            'empty ip'            => ['',              '104.16.0.0/13',  false, ''],
            'garbage ip'          => ['not-an-ip',     '104.16.0.0/13',  false, ''],
            'garbage cidr'        => ['104.16.0.1',    'nonsense/13',    false, ''],
            'bits too large'      => ['104.16.0.1',    '104.16.0.0/64',  false, 'a /64 on an IPv4 range was accepted'],
            'negative bits'       => ['104.16.0.1',    '104.16.0.0/-1',  false, ''],
        ];
    }

    /** /0 matches everything in its family — and nothing outside it. */
    public function test_a_zero_prefix_matches_its_whole_family_only(): void
    {
        $this->assertTrue($this->inCidr('8.8.8.8', '0.0.0.0/0'));
        $this->assertFalse($this->inCidr('2606:4700::1', '0.0.0.0/0'),
            'an IPv6 address matched an IPv4 default route');
    }

    /** A bare address with no prefix is an exact-match rule. */
    public function test_a_bare_address_is_an_exact_match(): void
    {
        $this->assertTrue($this->inCidr('52.66.166.11', '52.66.166.11'));
        $this->assertFalse($this->inCidr('52.66.166.12', '52.66.166.11'));
    }

    // ══ THE HEADER IS ONLY BELIEVED BEHIND THE CDN ══════════════════════════

    /**
     * THE LOAD-BEARING SECURITY PROPERTY.
     *
     * `CF-Connecting-IP` is an ordinary request header — any caller can set it.
     * Believing it unconditionally would let a caller NAME its own source
     * address, which is worse than reading the CDN's, because at least the
     * CDN's address is true. It may be trusted only when the PEER (the TCP
     * source, which cannot be forged) is itself Cloudflare.
     */
    public function test_the_forwarded_header_is_gated_on_the_peer_address(): void
    {
        $fn = strpos(self::$code, 'private function _webhook_client_ip()');
        $this->assertNotFalse($fn, '_webhook_client_ip() was removed or renamed');
        $body = substr(self::$code, $fn, 1800);

        $peerCheck = strpos($body, 'CLOUDFLARE_RANGES');
        $headerUse = strpos($body, 'HTTP_CF_CONNECTING_IP');

        $this->assertNotFalse($peerCheck, 'the peer address is no longer tested against Cloudflare ranges');
        $this->assertNotFalse($headerUse, 'CF-Connecting-IP is no longer read');
        $this->assertLessThan($headerUse, $peerCheck,
            'CF-Connecting-IP is read before the peer is confirmed to be Cloudflare — that '
            . 'lets any caller declare its own source IP.');

        $this->assertStringContainsString('if ($viaCdn) {', $body,
            'the header is no longer gated behind the CDN check at all');
    }

    /** A malformed header must fall back to the peer, never blank the address. */
    public function test_a_malformed_forwarded_header_falls_back_to_the_peer(): void
    {
        $fn   = strpos(self::$code, 'private function _webhook_client_ip()');
        $body = substr(self::$code, $fn, 1800);

        $this->assertStringContainsString('FILTER_VALIDATE_IP', $body,
            'the forwarded address is used without being validated as an IP. A malformed '
            . 'header would become the "client IP" and be compared, logged and stored.');
    }

    /** X-Forwarded-For must NOT be consulted — its left-most entry is caller-controlled. */
    public function test_x_forwarded_for_is_not_trusted(): void
    {
        $fn   = strpos(self::$code, 'private function _webhook_client_ip()');
        $body = substr(self::$code, $fn, 1800);

        $this->assertStringNotContainsString('HTTP_X_FORWARDED_FOR', $body,
            'X-Forwarded-For is being consulted. It is a caller-appendable list; Cloudflare '
            . 'sets CF-Connecting-IP to the single true client, which is why that is the only '
            . 'header trusted here.');
    }

    // ══ ENFORCEMENT POLICY ══════════════════════════════════════════════════

    /**
     * The allowlist must be enforcing ONLY when a school has opted in. Left
     * unconditional it refuses every production delivery (the defect); made
     * unconditionally permissive it would remove a control a school may want.
     */
    public function test_the_allowlist_enforces_only_when_the_school_configures_it(): void
    {
        $this->assertStringContainsString('$__ipEnforced = ($allowedIPs !== []);', self::$code,
            'the opt-in enforcement flag is gone — the IP check is either always on '
            . '(403s every real delivery behind Cloudflare) or always off (removes a '
            . 'control schools can choose).');

        $gate = strpos(self::$code, 'if (!$ipAllowed) {');
        $this->assertNotFalse($gate);
        $block = substr(self::$code, $gate, 900);

        $this->assertStringContainsString('if ($__ipEnforced) {', $block,
            'the 403 is no longer conditional on the school having opted in');
        $this->assertStringContainsString('http_response_code(403);', $block,
            'an opted-in school no longer gets the IP check enforced');
    }

    /**
     * Demoting the IP check is only defensible because the HMAC is mandatory.
     * If that refusal ever disappears, an unsigned webhook from any address
     * would be processed — so it is pinned here, next to the demotion.
     */
    public function test_the_hmac_is_still_mandatory_for_every_real_provider(): void
    {
        $this->assertStringContainsString(
            "if (\$realProvider && \$webhookSecret === '') {", self::$code,
            'a real provider with NO webhook secret is no longer refused. The IP allowlist '
            . 'was demoted to a signal precisely because the HMAC is the gate — without this '
            . 'refusal there is no gate at all.');

        $this->assertStringContainsString(
            "if (\$realProvider && \$webhookSig === '') {", self::$code,
            'an unsigned request to a real provider is no longer refused');
    }

    /** Whatever the outcome, the resolved source must reach the operator log. */
    public function test_the_resolved_source_is_always_logged(): void
    {
        $gate  = strpos(self::$code, 'if (!$ipAllowed) {');
        $block = substr(self::$code, $gate, 900);

        $this->assertSame(2, substr_count($block, 'peer='),
            'both the enforced and the advisory branch must log the resolved IP and the peer '
            . 'it arrived from; an operator cannot build an allowlist from silence.');
    }
}
