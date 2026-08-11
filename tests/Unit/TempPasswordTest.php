<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Initial-password generator — regression suite.
 *
 * The three generators this replaced were all derivable from data printed on a
 * class or staff list, and two of them were shorter than the policy the same
 * user is forced to satisfy on first login:
 *
 *   students        Ucfirst(name[0..3]) . dobDigits[0..4] . '@'   "Ayu1503@"  name + DOB
 *   staff (manual)  ucfirst(name)[0..3] . '123@'                  "Ayu123@"   name only, 7 chars
 *   staff (import)  first3(name) . last3(DOB year | phone | 123)  "Ayu987@"   name + phone, 7 chars
 *
 * The forced first-login change is the only thing protecting these accounts, and
 * the gap between handover and that change can be days — so the initial value
 * must not be computable by anyone holding the roster.
 */
class TempPasswordTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', true);
        require_once __DIR__ . '/../../application/helpers/temp_password_helper.php';
    }

    /** The exact policy enforced by Auth_api and AdminUsers at change time. */
    private function assertMeetsPolicy(string $pw, string $context): void
    {
        $this->assertGreaterThanOrEqual(8, strlen($pw), "$context: shorter than the 8-char minimum — '$pw'");
        $this->assertLessThanOrEqual(72, strlen($pw), "$context: longer than 72 chars");
        $this->assertMatchesRegularExpression('/[A-Z]/', $pw, "$context: no uppercase — '$pw'");
        $this->assertMatchesRegularExpression('/[a-z]/', $pw, "$context: no lowercase — '$pw'");
        $this->assertMatchesRegularExpression('/[0-9]/', $pw, "$context: no digit — '$pw'");
    }

    /**
     * Every name shape must produce a policy-compliant password. The old staff
     * generator returned "A@1234" for a one-letter name — no lowercase, 6 chars —
     * which would be rejected by the very policy it feeds.
     *
     * @dataProvider nameShapes
     */
    public function test_policy_holds_for_every_name_shape(string $name, string $label): void
    {
        for ($i = 0; $i < 40; $i++) {          // random path — sample it
            $this->assertMeetsPolicy(generate_temp_password($name), $label);
        }
    }

    public static function nameShapes(): array
    {
        return [
            'ordinary name'      => ['Ayush Kumar',      'ordinary'],
            'single letter'      => ['A',                'single letter'],
            'two letters'        => ['Jo',               'two letters'],
            'blank'              => ['',                 'blank'],
            'whitespace only'    => ['   ',              'whitespace'],
            'digits only'        => ['12345',            'digits only'],
            'punctuation only'   => ['...',              'punctuation'],
            'devanagari'         => ['आयुष',             'non-Latin'],
            'accented'           => ['Émile',            'accented'],
            'very long'          => [str_repeat('x', 200), 'very long'],
        ];
    }

    /** Never call with no argument and get something degenerate. */
    public function test_default_argument_is_safe(): void
    {
        $this->assertMeetsPolicy(generate_temp_password(), 'no argument');
    }

    /**
     * THE regression: the digits must not be derivable. The old scheme returned
     * the same password every time for a given name + DOB.
     */
    public function test_repeated_calls_do_not_repeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[generate_temp_password('Ayush Kumar')] = true;
        }
        // 9000 possible digit runs; 200 draws collide a little but must not collapse.
        $this->assertGreaterThan(
            100,
            count($seen),
            'Generated passwords repeat far too often — the digits are not random.'
        );
    }

    /** A birth date must never appear in the output. */
    public function test_output_contains_no_date_of_birth(): void
    {
        // The old generator turned 15-03-2015 into the literal digits "1503".
        $hits = 0;
        for ($i = 0; $i < 300; $i++) {
            if (strpos(generate_temp_password('Ayush Kumar'), '1503') !== false) $hits++;
        }
        // 1503 is one of 9000 possible runs; seeing it ~0 times is expected,
        // seeing it every time would mean the DOB is still being used.
        $this->assertLessThan(300, $hits, 'Every password contained the DOB digits.');
    }

    /** Name letters still lead, so the slip reads the way operators expect. */
    public function test_keeps_the_familiar_shape(): void
    {
        $pw = generate_temp_password('Ayush Kumar');
        $this->assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}@\d{4}$/',
            $pw,
            "Expected Ucfirst + 2 lowercase + '@' + 4 digits, got '$pw'"
        );
        $this->assertStringStartsWith('Ayu', $pw, 'Name stem should seed the letters');
    }

    /** Non-Latin names still get usable Latin letters rather than an empty stem. */
    public function test_non_latin_name_is_padded_with_latin_letters(): void
    {
        $pw = generate_temp_password('आयुष');
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}@\d{4}$/', $pw, "got '$pw'");
    }

    /** Ambiguous letters are kept out of the RANDOM pad (i/l/o). */
    public function test_random_pad_avoids_ambiguous_letters(): void
    {
        for ($i = 0; $i < 300; $i++) {
            // Blank name → all three letters come from the random pool.
            $letters = substr(generate_temp_password(''), 0, 3);
            $this->assertDoesNotMatchRegularExpression(
                '/[ilo]/i',
                $letters,
                "Random pad produced an easily-misread letter: '$letters'"
            );
        }
    }

    /**
     * Strip comments and docblocks, leaving only executable code.
     *
     * Asserting on raw source is a trap here: both files legitimately *describe*
     * the old derived-password schemes in comments (and Staff.php still carries a
     * commented-out legacy add_staff block), so a naive grep reports a defect that
     * cannot run. Tokenising keeps the assertion about behaviour.
     */
    private function executableCode(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /** The helper is the single source of truth — controllers must not re-inline. */
    public function test_controllers_use_the_shared_generator(): void
    {
        $sis   = $this->executableCode(__DIR__ . '/../../application/controllers/Sis.php');
        $staff = $this->executableCode(__DIR__ . '/../../application/controllers/Staff.php');

        $this->assertStringContainsString('generate_temp_password(', $sis,   'Sis must use the shared generator');
        $this->assertStringContainsString('generate_temp_password(', $staff, 'Staff must use the shared generator');

        $this->assertStringNotContainsString("'123@'", $staff, "Staff still builds a '123@' password in live code");
        $this->assertStringNotContainsString('$dobPart', $sis, 'Sis still derives a password from DOB digits');
        // The import scheme: first3(name) . last3(DOB year | phone) . '@'
        $this->assertStringNotContainsString('$last3', $staff, 'Staff import still derives a password from DOB/phone');
    }
}
