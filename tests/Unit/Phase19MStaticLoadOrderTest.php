<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19M — CI3 static-before-load regression guard.
 *
 * THE FATAL THIS EXISTS TO PREVENT:
 *
 *   Error: Class "Id_card_service" not found
 *   application/controllers/Sis.php:3527
 *
 * CodeIgniter 3 has **no autoloader for libraries**. A class only exists once
 * `$this->load->library()` has required its file. So a bare static reference —
 * `Id_card_service::validReasons()` — is a hard runtime fatal if it executes
 * before the library is loaded, no matter how correct the code looks.
 *
 * `php -l` cannot catch this: the syntax is valid. The unit suite could not
 * catch it either, because every test `require_once`s the library directly and
 * therefore always has the class in scope — the tests were *more* forgiving
 * than production. It only surfaced when a real request hit the endpoint.
 *
 * This test closes that gap by reading the controller as text and asserting
 * that, in each method, the library load precedes every static use.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19MStaticLoadOrderTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    /** Comments stripped so prose naming a class cannot register as a use. */
    private static function codeOnly(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else $out .= $t;
        }
        return $out;
    }

    /** Every method body in a file, keyed by name. */
    private static function methods(string $code): array
    {
        $out = [];
        if (!preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($m[1] as $i => [$name, $_]) {
            $at   = $m[0][$i][1];
            $open = strpos($code, '{', $at);
            if ($open === false) continue;
            $depth = 0;
            for ($p = $open, $n = strlen($code); $p < $n; $p++) {
                if ($code[$p] === '{') $depth++;
                elseif ($code[$p] === '}') {
                    $depth--;
                    if ($depth === 0) { $out[$name] = substr($code, $open, $p - $open + 1); break; }
                }
            }
        }
        return $out;
    }

    /**
     * Files that reference Id_card_service statically, and the expression each
     * uses to get the class loaded first.
     */
    public static function consumers(): array
    {
        return [
            'Sis controller'   => ['application/controllers/Sis.php', '_idCardSvc('],
            'Attendance'       => ['application/controllers/Attendance.php', "load->library('Id_card_service'"],
            'Transport F14'    => ['application/libraries/Transport_qr_attendance_service.php', "load->library('Id_card_service'"],
            'Visitor service'  => ['application/libraries/Visitor_service.php', "load->library('Id_card_service'"],
        ];
    }

    /**
     * @dataProvider consumers
     *
     * THE core guard. For every method that uses `Id_card_service::` statically,
     * the loader call must appear EARLIER in that same method body.
     */
    public function test_the_library_is_loaded_before_any_static_reference(string $rel, string $loader): void
    {
        $code    = self::codeOnly($rel);
        $checked = 0;

        foreach (self::methods($code) as $name => $body) {
            $staticAt = strpos($body, 'Id_card_service::');
            if ($staticAt === false) continue;

            $checked++;
            $loadAt = strpos($body, $loader);
            $this->assertNotFalse(
                $loadAt,
                "{$rel}::{$name}() uses Id_card_service:: statically but never loads the library — "
                . 'CI3 has no library autoloader, so this is a runtime fatal.'
            );
            $this->assertLessThan(
                $staticAt,
                $loadAt,
                "{$rel}::{$name}() references Id_card_service:: BEFORE loading it — "
                . 'this is the exact "Class not found" fatal seen at Sis.php:3527.'
            );
        }

        $this->assertGreaterThan(0, $checked, "no static use found in {$rel} — provider is stale");
    }

    /**
     * The specific endpoint that fataled, pinned by name so a future edit that
     * reintroduces the ordering fails here rather than in production.
     */
    public function test_the_endpoint_that_fataled_now_loads_first(): void
    {
        $methods = self::methods(self::codeOnly('application/controllers/Sis.php'));

        foreach (['id_card_issue', 'id_card_revoke', 'id_card'] as $name) {
            $this->assertArrayHasKey($name, $methods);
            $body = $methods[$name];
            $this->assertNotFalse(strpos($body, '_idCardSvc('), "{$name}() must build the service");
            $staticAt = strpos($body, 'Id_card_service::');
            if ($staticAt === false) continue;
            $this->assertLessThan(strpos($body, 'Id_card_service::'), strpos($body, '_idCardSvc('),
                "{$name}() must load before its first static reference");
        }
    }

    /**
     * The fee policy calls Id_card_service:: statically from inside its own
     * class, so any caller must have loaded the card service first — a
     * transitive version of the same trap.
     */
    public function test_the_fee_policy_dependency_is_loaded_by_its_caller(): void
    {
        $policy = self::codeOnly('application/libraries/Id_card_fee_policy.php');
        $this->assertStringContainsString('Id_card_service::', $policy,
            'provider stale — the policy no longer depends on the card service');

        $body = self::methods(self::codeOnly('application/controllers/Sis.php'))['_maybe_charge_id_card_replacement'] ?? '';
        $this->assertNotSame('', $body);
        $this->assertStringContainsString('Id_card_fee_policy', $body);

        // The only caller is id_card_issue(), which now builds the card
        // service before anything else — so the transitive static is safe.
        $issue = self::methods(self::codeOnly('application/controllers/Sis.php'))['id_card_issue'] ?? '';
        $this->assertLessThan(
            strpos($issue, '_maybe_charge_id_card_replacement'),
            strpos($issue, '_idCardSvc('),
            'the card service must be loaded before the fee policy runs'
        );
    }

    /**
     * Guard against the inverse mistake: silently dropping the static and
     * hardcoding the reason list in the controller, which would let the two
     * definitions drift.
     */
    public function test_the_controller_still_validates_against_the_service_enum(): void
    {
        $code = self::codeOnly('application/controllers/Sis.php');
        $this->assertSame(2, substr_count($code, 'Id_card_service::validReasons()'),
            'both id_card_issue() and id_card_revoke() must validate against the one enum');
        foreach (['lost', 'stolen', 'damaged'] as $r) {
            $this->assertStringNotContainsString("'{$r}', '", $code,
                'the controller must not hardcode a parallel reason list');
        }
    }
}
