<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * IDC-FEE-LOAD-1 — a public static must not carry a hidden load-order requirement.
 *
 * `Id_card_fee_policy::chargeableReasons()` reads three constants off
 * `Id_card_service`. CI3 has no autoloader, so that fataled with
 * `Class "Id_card_service" not found` from any request that had not already
 * loaded the service — which is every call from Sis::id_card_fee_settings()
 * and ::id_card_fee_settings_save(). The Replacement Charges dialog returned
 * 400 and said "Could not load replacement charges", a message that named the
 * symptom and hid the cause because the controller catches Throwable.
 *
 * The test runs in a SEPARATE PROCESS on purpose. In-process it would prove
 * nothing: PHPUnit's bootstrap, or any earlier test, may already have loaded
 * Id_card_service, and the call would succeed for the wrong reason. A fresh
 * interpreter is the only way to reproduce the condition the browser hit.
 */
final class IdCardFeePolicyLoadOrderTest extends TestCase
{
    private const APP = __DIR__ . '/../../application/';

    /** Run a snippet in a clean PHP process with only the named library loaded. */
    private function inFreshProcess(string $body): array
    {
        $php = <<<PHP
<?php
define('BASEPATH', 'cli');
define('APPPATH', '{$this->appPath()}');
{$body}
PHP;
        $tmp = sys_get_temp_dir() . '/zx_loadorder_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($tmp, $php);

        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);

        return ['code' => $code, 'out' => implode("\n", $out)];
    }

    private function appPath(): string
    {
        return str_replace('\\', '/', realpath(self::APP)) . '/';
    }

    /**
     * THE regression. Without the guard this exits non-zero with
     * "Class \"Id_card_service\" not found".
     */
    public function test_chargeable_reasons_works_without_the_service_preloaded(): void
    {
        $r = $this->inFreshProcess(<<<'PHP'
require_once APPPATH . 'libraries/Id_card_fee_policy.php';

// The failing condition, asserted rather than assumed: the service is NOT loaded.
if (class_exists('Id_card_service', false)) {
    fwrite(STDERR, "PRECONDITION FAILED: Id_card_service was already loaded\n");
    exit(2);
}

$reasons = Id_card_fee_policy::chargeableReasons();
echo implode(',', $reasons);
PHP);

        $this->assertSame(0, $r['code'],
            "Id_card_fee_policy::chargeableReasons() fataled with no Id_card_service "
            . "loaded — exactly what broke the Replacement Charges dialog.\n\n" . $r['out']);

        $this->assertSame('lost,stolen,damaged', trim($r['out']),
            'the chargeable set must stay lost/stolen/damaged — first issue, normal '
            . 'wear, data corrections and admin reissues are never the family\'s fault');
    }

    /** amountFor() routes through the same static, so it has the same exposure. */
    public function test_amount_for_works_without_the_service_preloaded(): void
    {
        $r = $this->inFreshProcess(<<<'PHP'
require_once APPPATH . 'libraries/Id_card_fee_policy.php';

$p = new Id_card_fee_policy();
$p->init(null, ['lost' => 150.0]);
echo $p->amountFor('lost', []) . '|' . $p->amountFor('first_issue', []);
PHP);

        $this->assertSame(0, $r['code'],
            "amountFor() fataled with no Id_card_service loaded.\n\n" . $r['out']);

        // Compare as numbers. Trimming trailing zeros off the STRING turns 150
        // into 15, which is how the first version of this assertion failed.
        [$lost, $first] = explode('|', trim($r['out']));
        $this->assertEqualsWithDelta(150.0, (float) $lost, 0.001, 'a configured charge must apply');
        $this->assertEqualsWithDelta(0.0, (float) $first, 0.001,
            'a first issue can never be charged, whatever a config says');
    }

    /** The guard itself, so it is not "tidied away" as a redundant require. */
    public function test_the_guard_is_present_and_idempotent(): void
    {
        $src = (string) file_get_contents(self::APP . 'libraries/Id_card_fee_policy.php');

        $this->assertStringContainsString("class_exists('Id_card_service', false)", $src,
            'the second argument must be false — with autoloading enabled, class_exists() '
            . 'would try to autoload and mask the very condition being guarded');
        $this->assertStringContainsString("require_once APPPATH . 'libraries/Id_card_service.php'", $src);
    }
}
