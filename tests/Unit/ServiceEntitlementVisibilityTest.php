<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SVC-1 — A SERVICE CONCESSION BILLED AT FULL FARE, SILENTLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fee_lifecycle::createModuleFee()` (transport / hostel / meals) prices through
 * `Service_entitlement_pricing` ONLY when USE_ENTITLEMENT_SERVICE_PRICING is on.
 * With the flag off it writes:
 *
 *     $net = $amount;   $deduction = 0.0;
 *
 * i.e. the GROSS fare. That is deliberate and documented — "byte-identical to
 * the pre-Phase-D document" — and harmless only while no student actually holds
 * a service entitlement.
 *
 * But families can request transport / hostel / meals through
 * Entitlement_requests, and Entitlement_service records them as ACTIVE
 * regardless of this flag. So a school can accumulate approved service
 * concessions and keep billing every one of them at full fare with nothing
 * anywhere reporting it.
 *
 * That is the third instance of one shape:
 *
 *   ADM-ENT-1  admission path   — concession recorded, not priced
 *   DEC-013b   generation path  — legacy generator cannot see entitlements
 *   SVC-1      service pricing  — flag off, gross written
 *
 * ── WHY THIS DETECTS RATHER THAN REFUSES ──────────────────────────────────
 * DEC-013b REFUSES generation, because an operator who cannot generate sees an
 * obvious failure whereas one who silently voids a concession loses money
 * invisibly. That reasoning does not transfer here: refusing to write a
 * transport demand would block that student's transport billing entirely, which
 * is worse than billing gross. So SVC-1 makes the condition visible and leaves
 * the amount alone.
 */
class ServiceEntitlementVisibilityTest extends TestCase
{
    private static string $lifecycle = '';

    public static function setUpBeforeClass(): void
    {
        self::$lifecycle = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
    }

    private function executable(): string
    {
        $src = preg_replace('!/\*.*?\*/!s', '', self::$lifecycle);
        $src = preg_replace('!^\s*//.*$!m', '', (string) $src);
        return (string) preg_replace('!^\s*\*.*$!m', '', (string) $src);
    }

    // ══ CONTROL — THE FLAG-OFF PATH REALLY DOES WRITE GROSS ═════════════════

    /**
     * If the flag-off branch ever stopped writing gross, SVC-1 would not exist
     * and this file should be re-derived rather than trusted.
     */
    public function test_control_the_flag_off_path_writes_the_gross_amount(): void
    {
        $src = $this->executable();

        $this->assertMatchesRegularExpression(
            '/\$net\s*=\s*\$amount;\s*\$deduction\s*=\s*0\.0;/',
            $src,
            'the flag-off service path no longer defaults to the gross amount — re-derive '
            . 'SVC-1 before relying on the assertions below'
        );
    }

    // ══ THE DETECTION ═══════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the else-branch did not exist at all; the flag
     * being off produced no signal of any kind.
     */
    public function test_an_unapplied_service_concession_is_reported(): void
    {
        $src = $this->executable();

        $this->assertStringContainsString('ACC_SERVICE_ENTITLEMENT_NOT_PRICED', $src,
            'writing a service demand at gross while the student holds an ACTIVE service '
            . 'entitlement produces no signal. A school could bill every concession holder '
            . 'full fare indefinitely and discover it only from a parent.');

        // Assert the CALL SITE is live, not merely that the name appears.
        //
        // The first version asserted `_studentHoldsServiceEntitlement(` existed
        // in the file. Disabling the call site left the suite GREEN, because the
        // method DEFINITION contains that same string. Third time this exact
        // weakness has appeared in this programme — a name is not a call.
        $this->assertMatchesRegularExpression(
            '/if \(\$this->_studentHoldsServiceEntitlement\(\$studentId\)\) \{/',
            $src,
            'nothing actually INVOKES the service-entitlement probe, so the condition cannot '
            . 'be distinguished from the ordinary flag-off case and no report is ever emitted'
        );

        // And it must guard the report, not sit somewhere unrelated.
        $probeAt = strpos($src, 'if ($this->_studentHoldsServiceEntitlement($studentId)) {');
        $this->assertNotFalse($probeAt);
        $this->assertStringContainsString(
            'ACC_SERVICE_ENTITLEMENT_NOT_PRICED',
            substr($src, $probeAt, 600),
            'the probe no longer guards the report — the two have drifted apart, so either '
            . 'the check fires without logging or the log fires without the check'
        );
    }

    /**
     * The log must identify the student AND the money, or it cannot be acted on.
     */
    public function test_the_report_identifies_the_student_and_the_amount(): void
    {
        $at = strpos(self::$lifecycle, 'ACC_SERVICE_ENTITLEMENT_NOT_PRICED');
        $this->assertNotFalse($at);
        $line = substr(self::$lifecycle, $at, 500);

        foreach (['$studentId', '$moduleType', '$amount'] as $needle) {
            $this->assertStringContainsString($needle, $line,
                "the SVC-1 report omits {$needle}. A money-loss log that does not say which "
                . 'student, which service, or how much cannot be reconciled against anything.');
        }
    }

    // ══ IT MUST NOT CHANGE WHAT IS BILLED ═══════════════════════════════════

    /**
     * SVC-1 is detection only. If the probe ever altered $net or $deduction it
     * would be applying an entitlement through a path that was never certified
     * for it — precisely what the flag exists to prevent.
     */
    public function test_the_detection_does_not_alter_the_billed_amount(): void
    {
        $src = $this->executable();

        $at = strpos($src, '_studentHoldsServiceEntitlement(');
        $this->assertNotFalse($at);

        // The guarded block: from the probe call to the end of that branch.
        $block = substr($src, $at, 700);

        $this->assertStringNotContainsString('$net =', $block,
            'the SVC-1 detection assigns $net. It must report only — changing the amount '
            . 'here would apply an entitlement through a path the flag deliberately gates.');
        $this->assertStringNotContainsString('$deduction =', $block,
            'the SVC-1 detection assigns $deduction; it must not touch the billed figures');
    }

    // ══ THE PROBE MUST NOT CRY WOLF ═════════════════════════════════════════

    /**
     * Deliberately asymmetric with DEC-013b's fail-closed. An unreadable
     * entitlement set must NOT be reported as "a concession is being ignored" —
     * that emits a money-loss alert on every Firestore blip and trains an
     * operator to ignore the one that matters.
     */
    public function test_an_unreadable_entitlement_set_is_not_reported_as_a_loss(): void
    {
        $at = strpos(self::$lifecycle, 'private function _studentHoldsServiceEntitlement');
        $this->assertNotFalse($at, 'the SVC-1 probe was renamed or removed');
        $body = substr(self::$lifecycle, $at, 2000);

        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable [^)]*\)\s*\{[^}]*return \$svcMemo\[\$studentId\] = false;/s',
            $body,
            'a failed entitlement read does not resolve to false. Reporting an unapplied '
            . 'concession on an unreadable set would fire a money-loss alert on any '
            . 'Firestore blip, which is how a real alert gets ignored.'
        );
    }

    /**
     * A rollover writes many module demands per student. Without memoisation
     * the probe would issue one query per DEMAND rather than per student.
     */
    public function test_the_probe_is_memoised_per_student(): void
    {
        $at = strpos(self::$lifecycle, 'private function _studentHoldsServiceEntitlement');
        $body = substr(self::$lifecycle, $at, 1500);

        $this->assertStringContainsString('static $svcMemo', $body,
            'the probe is not memoised. createModuleFee is invoked per student-period, so '
            . 'an un-memoised read turns one query per student into one per demand.');
        $this->assertStringContainsString('array_key_exists($studentId, $svcMemo)', $body,
            'the memo must be consulted by key — a student with NO entitlement caches false '
            . 'and must not be re-queried on every subsequent demand');
    }

    // ══ THE CALL MUST USE THIS CLASS'S REAL PROPERTY NAMES ══════════════════

    /**
     * `Fee_lifecycle` stores `schoolName` / `sessionYear`. The reader's
     * parameters are named `$schoolId` / `$session`. Passing `$this->schoolId`
     * would hand it two EMPTY strings — the reader would return nothing, the
     * probe would never fire, and SVC-1 would look closed while detecting
     * nothing. (That was the first draft of this code.)
     */
    public function test_the_probe_passes_the_properties_this_class_actually_has(): void
    {
        $at = strpos(self::$lifecycle, 'private function _studentHoldsServiceEntitlement');
        $body = substr(self::$lifecycle, $at, 2000);

        $this->assertMatchesRegularExpression(
            '/activeConcessionsForStudent\(\s*\$this->schoolName,\s*\$studentId,\s*\$this->sessionYear\s*\)/',
            $body,
            'the probe passes properties this class does not define. Fee_lifecycle stores '
            . 'schoolName/sessionYear; $this->schoolId and $this->session are null here, and '
            . 'the reader would silently match nothing.'
        );

        // And the value really is the SCH_ id — the class already queries with it.
        $this->assertStringContainsString("['schoolId',    '==', \$this->schoolName]", self::$lifecycle,
            'the evidence that schoolName holds the SCH_ id is gone; re-verify before '
            . 'trusting the probe passes a real tenant id');
    }
}
