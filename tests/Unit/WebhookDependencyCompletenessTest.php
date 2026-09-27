<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE WEBHOOK MUST CARRY EVERY COLLABORATOR THE MONEY PIPELINE TOUCHES
 * ══════════════════════════════════════════════════════════════════════════
 *
 * FEE-W1 was not "someone forgot a load()". It is a STRUCTURAL hazard that
 * recurs every time the shared money path gains a collaborator:
 *
 *   Fee_management::__construct() runs THREE different constructions —
 *     webhook      → CI_Controller::__construct()   (no session, minimal loads)
 *     parent API   → CI_Controller::__construct()   (token auth)
 *     admin        → parent::__construct()          (MY_Controller: full stack)
 *
 *   …and _verify_and_process() is SHARED by the webhook and the parent callback,
 *   while FeeCollectionService::submit() is handed the controller itself and
 *   reaches into it ~70 times.
 *
 * So a collaborator that MY_Controller provides is present for an admin request
 * and ABSENT for a webhook. Dereferencing it raises an Error (not an Exception),
 * which sails through catch(\Exception) and kills the request — in FEE-W1's case
 * AFTER the receipt, allocations and journal were already durable.
 *
 * This test enumerates what the money path actually uses and requires each name
 * to be satisfiable on the webhook path. It fails when someone adds a
 * collaborator to the shared core or to FeeCollectionService without making it
 * available to the session-less construction.
 *
 * It is deliberately a SOURCE test: the webhook's execution environment cannot
 * be reproduced in a unit test without bootstrapping CodeIgniter, and the
 * property being protected is precisely a construction-time one.
 */
class WebhookDependencyCompletenessTest extends TestCase
{
    private static string $ctrl = '';
    private static string $svc  = '';

    /**
     * Names CI_Controller itself provides, so they need no explicit load.
     * Kept explicit rather than inferred — an over-broad allowlist here would
     * silently re-admit the very defect this file exists to prevent.
     */
    private const CI_PROVIDED = ['load', 'input', 'output', 'uri', 'config', 'security', 'session'];

    public static function setUpBeforeClass(): void
    {
        self::$ctrl = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        self::$svc  = (string) file_get_contents(APPPATH . 'services/FeeCollectionService.php');
    }

    /**
     * Everything the constructor makes available on the WEBHOOK path: the
     * webhook branch itself, plus the common tail that runs after the
     * if/else-if chain for every construction.
     */
    private function webhookConstructionScope(): string
    {
        $branchStart = strpos(self::$ctrl, 'if ($isWebhook) {');
        $this->assertNotFalse($branchStart, 'the webhook constructor branch was renamed or removed');

        // The constructor ends at the first line closing it at 4-space indent.
        // Matched with a regex rather than a literal: this file uses CRLF, so a
        // "\n    }\n" needle silently never matches and every assertion below
        // would fail for the wrong reason.
        $found = preg_match('/\R    \}\R/', self::$ctrl, $m, PREG_OFFSET_CAPTURE, $branchStart);
        $this->assertSame(1, $found, 'could not find the end of __construct()');
        $ctorEnd = $m[0][1];

        return substr(self::$ctrl, $branchStart, $ctorEnd - $branchStart);
    }

    /** Collaborator names dereferenced inside _verify_and_process(). */
    private function sharedCoreCollaborators(): array
    {
        $start = strpos(self::$ctrl, 'private function _verify_and_process');
        $this->assertNotFalse($start, '_verify_and_process was renamed');
        $body = substr(self::$ctrl, $start, 14000);

        preg_match_all('/\$this->([a-zA-Z_]+)->/', $body, $m);
        return array_values(array_unique($m[1]));
    }

    /** Controller members FeeCollectionService reaches through the handed-in controller. */
    private function serviceCollaborators(): array
    {
        preg_match_all('/\$controller->([a-zA-Z_]+)->/', self::$svc, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * A name is satisfiable if the webhook construction provides it, CI provides
     * it, or the code that uses it loads it inline at the point of use.
     */
    private function isSatisfiable(string $name, string $scope, string $userSource): bool
    {
        if (in_array($name, self::CI_PROVIDED, true)) return true;
        if (stripos($scope, "'{$name}')") !== false) return true;              // load(..., '{alias}')
        if (strpos($scope, "\$this->{$name}->init(") !== false) return true;   // loaded + initialised
        if (stripos($userSource, ", '{$name}')") !== false) return true;       // loaded inline at use site

        // Assigned directly from another property — e.g. _init_payment_service()
        // does `$this->paymentService = $this->payment_service;` after loading
        // the library under its canonical name.
        if (preg_match('/\$this->' . preg_quote($name, '/') . '\s*=\s*\$this->/', self::$ctrl)) {
            return true;
        }

        // Optional-by-design: satisfiable ONLY if EVERY dereference is guarded.
        // A single unguarded use makes the name mandatory again, which is the
        // whole point — one new unguarded call is exactly how FEE-W1 happened.
        return $this->everyUseIsIssetGuarded($name, $userSource);
    }

    /**
     * True only when EVERY `$this->{name}->` in $source is preceded, closely,
     * by an `isset($this->{name})` guard. Deliberately strict: an optional
     * collaborator that is checked in three places and dereferenced bare in a
     * fourth is not optional, it is a latent FEE-W1.
     */
    private function everyUseIsIssetGuarded(string $name, string $source): bool
    {
        $needle = "\$this->{$name}->";
        $guard  = "isset(\$this->{$name})";

        $offset = 0;
        $uses   = 0;
        while (($p = strpos($source, $needle, $offset)) !== false) {
            $offset = $p + 1;
            $uses++;

            // Scan back only to the nearest statement terminator. A distance
            // window is the wrong tool: a genuinely separate call site further
            // down can "borrow" an earlier guard purely by being close enough.
            // The guard and the guarded use share a statement span —
            //     if (isset($this->x) && $this->x->isReady()) { $this->x->emit(
            // has no `;` between them, whereas a later bare $this->x->other()
            // always has one.
            //
            // Conservative by construction: a second guarded call INSIDE the
            // same block reads as unguarded and is reported. On the money path,
            // over-reporting is the correct direction to err.
            $spanStart = strrpos(substr($source, 0, $p), ';');
            $spanStart = ($spanStart === false) ? 0 : $spanStart;
            if (strpos(substr($source, $spanStart, $p - $spanStart), $guard) === false) {
                return false;
            }
        }
        return $uses > 0;
    }

    // ══ CONTROL — THE DETECTOR ACTUALLY DETECTS ═════════════════════════════

    /**
     * Without this, a passing run could mean "everything is provided" OR "the
     * extraction returned nothing". A name that is definitely NOT provided on
     * the webhook path must be reported as unsatisfiable.
     */
    public function test_control_the_detector_reports_a_genuinely_absent_collaborator(): void
    {
        $scope = $this->webhookConstructionScope();

        $this->assertFalse(
            $this->isSatisfiable('someCollaboratorNobodyLoads', $scope, ''),
            'the satisfiability check accepts a name nothing loads — it would have '
            . 'passed FEE-W1 and proves nothing'
        );
        $this->assertTrue(
            $this->isSatisfiable('firebase', $scope, ''),
            'the check cannot even recognise the firebase library the webhook '
            . 'branch explicitly loads — the extraction window is wrong'
        );

        // The isset-guard rule must not become a loophole. A name guarded in
        // one place and dereferenced bare in another is NOT optional.
        $partly = 'if (isset($this->thing) && $this->thing->isReady()) { $this->thing->emit(); }'
                . ' ... later ... $this->thing->emitAgain();';
        $this->assertFalse(
            $this->isSatisfiable('thing', $scope, $partly),
            'a collaborator guarded at some call sites but dereferenced bare at another '
            . 'was accepted as optional — that is exactly a latent FEE-W1'
        );

        $fully = 'if (isset($this->thing) && $this->thing->isReady()) { $this->thing->emit(); }';
        $this->assertTrue(
            $this->isSatisfiable('thing', $scope, $fully),
            'a genuinely optional, fully-guarded collaborator must not be reported missing'
        );
    }

    public function test_control_the_extraction_finds_real_collaborators(): void
    {
        $this->assertNotEmpty($this->sharedCoreCollaborators(),
            'no collaborators extracted from _verify_and_process — the regexes or the '
            . 'method name drifted, and every assertion below would pass vacuously');
        $this->assertGreaterThan(3, count($this->serviceCollaborators()),
            'FeeCollectionService reaches into the controller dozens of times; '
            . 'extracting almost nothing means the pattern drifted');
    }

    // ══ THE INVARIANT ═══════════════════════════════════════════════════════

    public function test_every_shared_core_collaborator_exists_on_the_webhook_path(): void
    {
        $scope   = $this->webhookConstructionScope();
        $core    = strpos(self::$ctrl, 'private function _verify_and_process');
        $coreSrc = substr(self::$ctrl, $core, 14000);

        $missing = [];
        foreach ($this->sharedCoreCollaborators() as $name) {
            if (!$this->isSatisfiable($name, $scope, $coreSrc)) $missing[] = $name;
        }

        $this->assertSame([], $missing,
            "_verify_and_process() dereferences [" . implode(', ', $missing) . '] but the '
            . 'webhook construction does not provide them. A gateway webhook has no session, '
            . 'so MY_Controller never runs. Dereferencing a missing collaborator raises an '
            . 'Error, which catch(\\Exception) does not catch — and in the shared core that '
            . 'happens AFTER the receipt, allocations and journal are already durable. '
            . 'That is FEE-W1.');
    }

    public function test_every_collection_service_collaborator_exists_on_the_webhook_path(): void
    {
        $scope = $this->webhookConstructionScope();

        $missing = [];
        foreach ($this->serviceCollaborators() as $name) {
            if (!$this->isSatisfiable($name, $scope, self::$svc)) $missing[] = $name;
        }

        $this->assertSame([], $missing,
            'FeeCollectionService reaches $controller->[' . implode(', ', $missing) . '] but the '
            . 'webhook construction does not provide them. The service is handed the controller '
            . 'itself, so anything MY_Controller normally supplies is absent for a webhook — and '
            . 'this failure would land DURING the money write, not after it.');
    }

    /**
     * The webhook's own post-processing (defaulter status, order lookup) runs
     * outside the shared core and has the same exposure.
     */
    public function test_the_webhook_post_processing_collaborators_exist(): void
    {
        $scope = $this->webhookConstructionScope();

        $start = strpos(self::$ctrl, 'public function payment_webhook()');
        $this->assertNotFalse($start, 'payment_webhook was renamed');
        $body = substr(self::$ctrl, $start, 9000);

        preg_match_all('/\$this->([a-zA-Z_]+)->/', $body, $m);

        $missing = [];
        foreach (array_unique($m[1]) as $name) {
            if (!$this->isSatisfiable($name, $scope, $body)) $missing[] = $name;
        }

        $this->assertSame([], $missing,
            'payment_webhook() itself dereferences [' . implode(', ', $missing) . '] which the '
            . 'webhook construction does not provide.');
    }

    // ══ THE CONSTRUCTION ORDER THAT MAKES IT WORK ═══════════════════════════

    /**
     * Firestore_service's constructor takes its REST client from
     * $CI->firebase->getFirestoreDb(). Loading it BEFORE firebase would fall
     * through to its own service-account fallback — which happens to work, but
     * silently builds a second client. Keep the intended order explicit.
     */
    public function test_the_webhook_loads_firebase_before_the_firestore_service(): void
    {
        $scope = $this->webhookConstructionScope();

        $firebase = strpos($scope, "load->library('firebase')");
        $fsvc     = strpos($scope, "load->library('firestore_service'");

        $this->assertNotFalse($firebase, 'the webhook branch no longer loads the firebase library');
        $this->assertNotFalse($fsvc, 'the webhook branch no longer loads the Firestore service');
        $this->assertLessThan($fsvc, $firebase,
            'Firestore_service::__construct() reads $CI->firebase to obtain its REST client; '
            . 'loading it first silently constructs a second client from the service account file');
    }

    /**
     * fsTxn is only usable when init() receives a non-empty schoolId AND session
     * — otherwise its $ready flag stays false and EVERY method silently returns
     * false/0/null. That is worse than a fatal: the payment marker would simply
     * never be written and nothing would say so.
     */
    public function test_the_transaction_helper_is_initialised_with_the_resolved_tenant(): void
    {
        $scope = $this->webhookConstructionScope();

        $this->assertMatchesRegularExpression(
            '/fsTxn->init\(\s*\$this->firebase,\s*\$this->fs,\s*\$this->school_name,\s*\$this->session_year\s*\)/',
            $scope,
            'fsTxn must be initialised with the firebase client, the Firestore service and the '
            . 'RESOLVED tenant + session. With an empty schoolId or session its $ready flag stays '
            . 'false and every call becomes a silent no-op.'
        );

        $this->assertStringContainsString('resolveSchoolFromOrder(', $scope,
            'the webhook tenant must be resolved from the order, never from a session it does not have');
    }
}
