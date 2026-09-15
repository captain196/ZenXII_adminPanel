<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The affiliation keys must have exactly ONE write door.
 *
 * Three doors wrote them before this change: save_issuer_identity (validated),
 * save_profile (a byte cap and nothing else) and Schools::edit_school (nothing
 * at all). Only the first cleared the verification when the claim moved, so a
 * VERIFIED badge could outlive a number nobody had checked — and because both
 * tabs hydrate from one payload, correcting the affiliation and then saving an
 * unrelated phone number silently reverted the correction.
 *
 * These tests fail the moment a second door reopens.
 */
final class IssuerOneDoorTest extends TestCase
{
    private static string $config;
    private static string $schools;
    private static string $view;

    public static function setUpBeforeClass(): void
    {
        $base = __DIR__ . '/../../application/';
        self::$config  = (string) file_get_contents($base . 'controllers/School_config.php');
        self::$schools = (string) file_get_contents($base . 'controllers/Schools.php');
        self::$view    = (string) file_get_contents($base . 'views/school_config/index.php');
    }

    /** The body of one method, sliced to where the next method begins. */
    private function body(string $src, string $name): string
    {
        $at = strpos($src, 'public function ' . $name);
        $this->assertNotFalse($at, "{$name}() not found.");
        $rest = substr($src, $at + 10);
        $end  = preg_match('/\n    (?:public|private|protected) function /', $rest, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1] : strlen($rest);
        return substr($src, $at, $end + 10);
    }

    public function test_save_profile_does_not_accept_the_affiliation(): void
    {
        $php = $this->body(self::$config, 'save_profile');
        $this->assertStringNotContainsString("'affiliation_board'", $php,
            'The Profile door accepts the affiliation again — it writes the same Firestore '
            . 'keys the Issuer tab validates, with only a byte cap.');
        $this->assertStringNotContainsString("'affiliation_no'", $php,
            'The Profile door accepts the affiliation number again; last writer wins.');
    }

    public function test_the_profile_tab_no_longer_offers_the_inputs(): void
    {
        $this->assertStringNotContainsString('pf_affiliation_board', self::$view,
            'A second input for the board is back on the Profile tab.');
        $this->assertStringNotContainsString('pf_affiliation_no', self::$view,
            'The "Affiliation / DISE No." input is back — one field for two identifiers '
            . 'from two different authorities.');
    }

    public function test_the_superadmin_door_validates_through_reconcile(): void
    {
        $php = $this->body(self::$schools, 'edit_school');
        $this->assertStringContainsString('Issuer_identity::reconcile(', $php,
            'edit_school writes the affiliation without reaching the same verdict as the '
            . 'other door.');
        $this->assertStringNotContainsString("'Affiliated To'      => 'affiliationBoard'", $php,
            'The affiliation is back in the plain field map, which applies no validation '
            . 'and never clears the verification.');
    }

    /**
     * The registered-name suggestion must stay a suggestion.
     *
     * "Exactly as on the affiliation instrument" is the one required field
     * nobody can answer from memory. We offer the name we already hold so the
     * step becomes a confirmation — but the server must never merge that guess
     * into the recorded value, and the client must never overwrite a name
     * somebody actually read off an instrument.
     */
    public function test_the_registered_name_suggestion_is_separate_and_non_destructive(): void
    {
        $this->assertStringContainsString("'registeredNameSuggestion'", self::$config,
            'The suggestion is gone, so the field that sends someone to find a document '
            . 'is unanswerable again.');

        $php = $this->body(self::$config, 'get_config');
        $this->assertStringNotContainsString(
            "'registeredName'    => (string) (\$fsSchool['registeredName'] ?? \$fsSchool['name']", $php,
            'The guess was merged into registeredName — the server now asserts as a '
            . 'recorded fact something it invented.');

        $this->assertStringContainsString('!nameEl.value', self::$view,
            'The suggestion is applied unconditionally and can overwrite a name that was '
            . 'read off the actual instrument.');
        $this->assertStringContainsString('Suggested from your school name', self::$view,
            'A silently prefilled field reads as a recorded fact; it must say it is a suggestion.');
    }

    /** Whatever door writes the claim must also settle the verification. */
    public function test_every_writer_of_the_claim_settles_the_verification(): void
    {
        foreach ([['School_config::save_issuer_identity', $this->body(self::$config, 'save_issuer_identity')],
                  ['Schools::edit_school', $this->body(self::$schools, 'edit_school')]] as [$label, $php]) {
            $this->assertMatchesRegularExpression('/reconcile\(/', $php,
                "{$label} writes the claim without using the shared decision.");
            $this->assertStringContainsString("'verification'", $php,
                "{$label} does not settle the verification, so a badge can outlive its claim.");
        }
    }
}
