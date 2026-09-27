<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * LIB-AUTOLOAD-1 — a static reference to a library must resolve from anywhere.
 *
 * CI3 has no library autoloader, so `Some_service::CONSTANT` resolved only if
 * that library happened to be loaded earlier in the same request. It fataled
 * twice in production code (Phase 19M, then IDC-FEE-LOAD-1) and a tokenizer
 * audit found 22 unguarded cross-library statics still armed.
 *
 * Every behavioural test here runs in a SEPARATE PROCESS. In-process they would
 * prove nothing: PHPUnit's bootstrap, or any earlier test in the run, may
 * already have loaded the class, and the reference would succeed for the wrong
 * reason. A fresh interpreter is the only way to reproduce what the browser hit.
 */
final class LibraryAutoloaderTest extends TestCase
{
    private const APP = __DIR__ . '/../../application/';

    private function appPath(): string
    {
        return str_replace('\\', '/', realpath(self::APP)) . '/';
    }

    /** Run PHP with ONLY the autoloader registered — nothing else preloaded. */
    private function inFreshProcess(string $body): array
    {
        $php = "<?php\n"
             . "define('BASEPATH', 'cli');\n"
             . "define('APPPATH', '" . $this->appPath() . "');\n"
             . "require_once APPPATH . 'hooks/Library_autoloader.php';\n"
             . "(new Library_autoloader())->register();\n"
             . $body;

        $tmp = sys_get_temp_dir() . '/zx_autoload_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($tmp, $php);

        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);

        return ['code' => $code, 'out' => trim(implode("\n", $out))];
    }

    // ══ 1 · the landmines resolve ════════════════════════════════════════

    /**
     * One case per module family the audit flagged. These are the real pairs,
     * not representative examples: each is a library that statically references
     * another without loading it.
     */
    public function test_every_audited_cross_library_static_resolves(): void
    {
        $pairs = [
            'Id_card_fee_policy'           => 'Id_card_service',
            'Fee_late_fee_service'         => 'Fee_firestore_txn',
            'Fee_refund_service'           => 'Fee_firestore_txn',
            'Fee_reminder_service'         => 'Fee_firestore_txn',
            'Timetable_service'            => 'Timetable_plan_store',
            'Timetable_retention'          => 'Timetable_plan_service',
            'Transport_analytics_service'  => 'Transport_service',
            'Transport_compliance_service' => 'Transport_service',
            'Benefit_award_service'        => 'Benefit_policy_service',
            'Fnf_preview_service'          => 'Fnf_policy_service',
            'Action_registry'              => 'Entitlement_service',
            'Gate_pass_service'            => 'Entity_firestore_sync',
        ];

        // ONE PROCESS PER DEPENDENCY, deliberately.
        //
        // A single process checking them in a loop reported two as "preloaded":
        // resolving an earlier library had transitively pulled them in. That is
        // correct behaviour, but it means the loop was no longer testing what it
        // claimed for those two — the later ones were resolved by a side effect
        // of the earlier ones rather than by the autoloader.
        //
        // A fresh interpreter per dependency is the only arrangement where every
        // case actually starts from "not loaded".
        $deps = array_values(array_unique(array_values($pairs)));
        $bad  = [];

        foreach ($deps as $dep) {
            $r = $this->inFreshProcess(
                '$d = ' . var_export($dep, true) . ';' . "\n"
                . 'if (class_exists($d, false)) { echo "PRELOADED"; exit; }' . "\n"
                . 'echo class_exists($d) ? "RESOLVED" : "UNRESOLVED";'
            );

            if ($r['code'] !== 0)            { $bad[] = "{$dep}:FATAL({$r['out']})"; continue; }
            if ($r['out'] !== 'RESOLVED')    { $bad[] = "{$dep}:{$r['out']}"; }
        }

        $this->assertSame([], $bad,
            'a library referenced statically by another library did not resolve from a '
            . 'clean process — that is the IDC-FEE-LOAD-1 failure, which reaches the '
            . 'operator as a generic error with the real cause swallowed: '
            . implode(', ', $bad));
    }

    // ══ 2 · it cannot be turned into a file-read primitive ═══════════════

    /**
     * The class name reaches the filesystem, so it is validated and basename()d.
     * A miss must be a quiet miss, never an include of something outside
     * application/libraries/.
     */
    public function test_a_crafted_class_name_cannot_escape_the_libraries_directory(): void
    {
        $r = $this->inFreshProcess(<<<'PHP'
$attempts = [
    '../config/database',
    '../../index',
    'Id_card_service/../../config/config',
    "Evil\0byte",
    'Name With Spaces',
];
$leaked = [];
foreach ($attempts as $a) {
    // Must return false and must not fatal.
    if (@class_exists($a)) $leaked[] = $a;
}
echo $leaked ? implode(',', $leaked) : 'NONE_RESOLVED';
PHP);

        $this->assertSame(0, $r['code'], "a crafted name caused a fatal:\n" . $r['out']);
        $this->assertSame('NONE_RESOLVED', $r['out'],
            'the autoloader resolved a name outside application/libraries/');
    }

    /** An unknown class must stay unknown — the autoloader may not invent one. */
    public function test_an_unknown_class_still_does_not_exist(): void
    {
        $r = $this->inFreshProcess(
            'echo class_exists("Totally_Made_Up_Service") ? "EXISTS" : "ABSENT";'
        );

        $this->assertSame('ABSENT', $r['out']);
    }

    // ══ 3 · wiring and ordering ══════════════════════════════════════════

    /**
     * pre_system: a static reference can be evaluated by anything the framework
     * loads afterwards, so registering later would leave the same ordering hole.
     */
    public function test_it_is_registered_as_a_pre_system_hook(): void
    {
        $hooks = (string) file_get_contents(self::APP . 'config/hooks.php');

        $this->assertMatchesRegularExpression(
            "~\\\$hook\\['pre_system'\\]\\[\\]\\s*=\\s*\\[[^\\]]*'class'\\s*=>\\s*'Library_autoloader'~s",
            $hooks,
            'the autoloader must be a pre_system hook'
        );
        $this->assertFileExists(self::APP . 'hooks/Library_autoloader.php');
    }

    /**
     * It must APPEND. Prepending would put it ahead of Composer and let a
     * library file shadow a vendor class of the same name.
     */
    public function test_it_appends_rather_than_prepends(): void
    {
        $src = (string) file_get_contents(self::APP . 'hooks/Library_autoloader.php');

        $this->assertMatchesRegularExpression(
            '~spl_autoload_register\(\s*\[__CLASS__,\s*\'load\'\]\s*\)~', $src,
            'register with the callable alone: $prepend defaults to false (append), '
            . 'and passing the deprecated $do_throw emits a PHP 8 notice on every request'
        );
    }

    /**
     * The premise of the whole approach: filename == classname. Two known
     * exceptions are excluded BY NAME so a third cannot appear unnoticed.
     */
    public function test_library_filenames_still_match_their_class_names(): void
    {
        $known = ['Firestore_rest_client', 'PaymentProviderInterface'];
        $bad   = [];

        foreach (glob(self::APP . 'libraries/*.php') as $file) {
            $base = basename($file, '.php');
            if (in_array($base, $known, true)) continue;

            $src = (string) file_get_contents($file);
            if (!preg_match('~\b(?:class|interface|trait)\s+' . preg_quote($base, '~') . '\b~', $src)) {
                $bad[] = $base;
            }
        }

        $this->assertSame([], $bad,
            'these library files do not declare a class matching their filename, so a '
            . 'static reference to them cannot be autoloaded and the IDC-FEE-LOAD-1 '
            . 'failure is possible again for: ' . implode(', ', $bad));
    }
}
