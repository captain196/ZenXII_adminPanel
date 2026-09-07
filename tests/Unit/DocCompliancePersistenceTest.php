<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A compliance exclusion is a human decision and must outlive the tab.
 *
 * `toggleLayer()` set `S.layerOff` in memory and called `markDirty()`. The
 * autosave patch carried `name, page, header, footer, objects, languages,
 * defaultLanguage` — and NOT `complianceLayers` — so the save succeeded, the
 * dirty flag cleared, and the status bar reported "All changes saved". The
 * exclusion died on reload.
 *
 * The dialog's own words were therefore false: *"The reason is stored with the
 * template and shown on every rule it suppresses."* Phantom success, on the one
 * control in this module that suppresses a statutory requirement.
 *
 * The server was always ready: `complianceLayers` is in save()'s allowlist and
 * publish() freezes it into the version snapshot. Only the client never sent it.
 * Confirmed live before the fix: 0 of 90 templates carried a layer, and
 * `complianceAuthorities` holds 0 documents — so `Doc_compliance`, its 11 tests
 * and the whole P5.6 report were reading a field nothing had ever written.
 *
 * qa/certificates/06-uat-matrix.csv T1-34, T2-19, T3-20
 */
final class DocCompliancePersistenceTest extends TestCase
{
    private static string $js;

    public static function setUpBeforeClass(): void
    {
        $js = @file_get_contents(__DIR__ . '/../../assets/js/doctemplates/designer.js');
        self::assertNotFalse($js, 'designer.js unreadable');
        self::$js = (string) $js;
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_compliance.php';
    }

    private function saveBody(): string
    {
        $at = strpos(self::$js, 'async function srvSaveDraft');
        $this->assertNotFalse($at, 'srvSaveDraft() is gone');
        return substr(self::$js, $at, 3000);
    }

    public function test_the_autosave_patch_carries_compliance_layers(): void
    {
        $this->assertStringContainsString(
            'complianceLayers:',
            $this->saveBody(),
            'The save patch does not carry complianceLayers, so an exclusion cannot survive '
            . 'a reload while the UI still reports the work saved.'
        );
    }

    /** Only real decisions are stored; the derived stack is recomputed. */
    public function test_only_exclusions_are_persisted(): void
    {
        $at = strpos(self::$js, 'function complianceOverrides');
        $this->assertNotFalse($at, 'complianceOverrides() is gone');
        $body = substr(self::$js, $at, 900);
        $this->assertStringContainsString('applied:     false', $body,
            'Overrides must be written as applied:false — that is what Doc_compliance reads.');
        $this->assertStringContainsString('filter(id => S.layerOff[id])', $body,
            'Everything in the stack is being written, not just the exclusions. The applied '
            . 'stack is derived from board+state and would go stale the moment either changes.');
    }

    /**
     * Doc_compliance compares `version` against the authority's current one. The
     * client has no version to give, and inventing one would make every template
     * report as behind the first authority that ever gets a version.
     */
    /**
     * Every other timestamp on a stored template is server-generated. A
     * client-stamped one here would be the single browser-supplied value in the
     * document, on the record that says WHEN a school set aside a statutory
     * requirement — and a wrong or altered laptop clock would date a legal
     * decision incorrectly. When it happened is already recorded server-side
     * twice: the template's updatedAt and the audit row for the save.
     */
    public function test_no_client_timestamp_is_written(): void
    {
        $at   = strpos(self::$js, 'function complianceOverrides');
        $body = substr(self::$js, $at, 1400);
        $this->assertDoesNotMatchRegularExpression('/\w+At\s*:\s*new Date\(\)/', $body,
            'complianceOverrides() stamps a compliance record from the browser clock.');
    }

    public function test_no_version_is_invented(): void
    {
        $at   = strpos(self::$js, 'function complianceOverrides');
        $body = substr(self::$js, $at, 900);
        $this->assertDoesNotMatchRegularExpression('/\bversion\s*:/', $body,
            'complianceOverrides() writes a version the client does not actually know.');
    }

    public function test_a_stored_exclusion_is_rehydrated_on_open(): void
    {
        $at = strpos(self::$js, 'function adoptTemplate');
        $this->assertNotFalse($at);
        $body = substr(self::$js, $at, 2600);
        $this->assertStringContainsString('complianceLayers || []', $body,
            'adoptTemplate() does not read stored layers back, so a reload silently re-applies '
            . 'an authority a person deliberately excluded.');
        $this->assertStringContainsString('S.overrideReason[l.authorityId]', $body,
            'The reason is not restored, so the rule it suppresses would show no explanation.');
    }

    /**
     * The shape the client writes must be the shape the reader reads — otherwise
     * both halves are individually correct and the feature still does nothing.
     */
    public function test_the_written_shape_is_what_doc_compliance_consumes(): void
    {
        $layer = [
            'authorityId' => 'rte',
            'label'       => 'RTE Act 2009',
            'applied'     => false,
            'reason'      => 'School teaches IX-XII only',
            'evidence'    => 'A',
            'verifiedOn'  => '2026-08-16',
        ];
        $svc = new Doc_compliance(['store' => [
            'get'   => fn($c, $id) => ['label' => 'RTE Act 2009', 'version' => 3],
            'query' => fn($c, $w) => ['SCH1_TPL1' => [
                'schoolId' => 'SCH1', 'docType' => 'transfer_certificate',
                'status' => 'draft', 'activeVersion' => null,
                'complianceLayers' => [$layer],
            ]],
        ]]);
        $report = $svc->affectedByAuthority('rte');
        $this->assertSame([], $report['affected'],
            'An EXCLUDED layer must not be reported as affected by a revision to the authority '
            . 'it is documented as not following. If this fails, the client and the reader '
            . 'disagree about the shape and the exclusion is invisible to the report.');
    }

    /** And an applied layer that is genuinely behind must still be reported. */
    public function test_an_applied_layer_that_is_behind_is_still_reported(): void
    {
        $svc = new Doc_compliance(['store' => [
            'get'   => fn($c, $id) => ['label' => 'RTE Act 2009', 'version' => 3],
            'query' => fn($c, $w) => ['SCH1_TPL1' => [
                'schoolId' => 'SCH1', 'docType' => 'transfer_certificate',
                'status' => 'draft', 'activeVersion' => 2,
                'complianceLayers' => [['authorityId' => 'rte', 'applied' => true, 'version' => 1]],
            ]],
        ]]);
        $report = $svc->affectedByAuthority('rte');
        $this->assertCount(1, $report['affected'], 'A genuinely stale applied layer stopped being reported.');
    }
}
