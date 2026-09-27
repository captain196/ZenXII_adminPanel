<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($level, $msg) {} }

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';
require_once APPPATH . 'libraries/Payment_order_provenance.php';
require_once APPPATH . 'libraries/Payment_provider_config_resolver.php';
require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE DYNAMIC-SCHOOL PROVIDER ACCEPTANCE TEST
 * ══════════════════════════════════════════════════════════════════════════
 *
 * This is the test the whole programme exists to pass. Not "an interface
 * exists" — the actual product capability:
 *
 *     School A → Provider A
 *     School B → Provider B
 *     School C → Provider C
 *     School A switches A → C
 *     …and B and C are untouched, A's old orders stay bound to A, and no
 *     Billing V2 file knows any of it happened.
 *
 * ── THE PROVIDERS HERE ARE TEST FIXTURES, DELIBERATELY ───────────────────
 * Three adapters defined inside this file, with deliberately different
 * protocols and DIFFERENT CHECKOUT ENGINES (native_sdk / webview /
 * hosted_redirect). Using fixtures rather than real providers is the point:
 * it proves the FRAMEWORK is N-provider capable without implying ZenX
 * supports any particular payment company. None is registered in the
 * catalogue, so none is reachable in production — asserted at the end.
 */

/** Native-SDK provider. */
class Acc_provider_alpha implements PaymentProviderInterface
{
    public function get_name(): string { return 'acc_alpha'; }
    public function capabilities(): array {
        return ['checkout_type' => 'native_sdk', 'cards' => true, 'upi' => true,
                'net_banking' => true, 'wallets' => false, 'webhooks' => true,
                'refunds' => false, 'simulatable' => false];
    }
    public function create_order(float $a, string $r = '', array $m = []): array {
        return ['order_id' => 'ALPHA-' . strtoupper(bin2hex(random_bytes(3))), 'amount' => $a];
    }
    public function verify_signature(string $o, string $p, string $s): bool { return $s === "alpha:{$o}:{$p}"; }
    public function fetch_payment(string $p): array {
        return ['payment_id' => $p, 'amount' => 700.00, 'amount_paise' => 70000, 'status' => 'captured'];
    }
    public function parse_callback(array $b): array {
        return ['order_id' => (string)($b['alpha_order'] ?? ''), 'payment_id' => (string)($b['alpha_pay'] ?? ''),
                'signature' => (string)($b['alpha_sig'] ?? '')];
    }
}

/** WebView provider — a different checkout ENGINE, not just a different name. */
class Acc_provider_beta implements PaymentProviderInterface
{
    public function get_name(): string { return 'acc_beta'; }
    public function capabilities(): array {
        return ['checkout_type' => 'webview', 'cards' => true, 'upi' => true,
                'net_banking' => false, 'wallets' => true, 'webhooks' => true,
                'refunds' => false, 'simulatable' => false];
    }
    public function create_order(float $a, string $r = '', array $m = []): array {
        return ['order_id' => 'BETA_' . strtoupper(bin2hex(random_bytes(3))), 'amount' => $a];
    }
    public function verify_signature(string $o, string $p, string $s): bool { return $s === strrev("{$o}|{$p}"); }
    public function fetch_payment(string $p): array {
        return ['payment_id' => $p, 'amount' => 700.00, 'amount_paise' => 70000, 'status' => 'captured'];
    }
    public function parse_callback(array $b): array {
        $d = is_array($b['txn'] ?? null) ? $b['txn'] : [];
        return ['order_id' => (string)($d['ref'] ?? ''), 'payment_id' => (string)($d['id'] ?? ''),
                'signature' => (string)($d['check'] ?? '')];
    }
}

/** Hosted-redirect provider — a third distinct engine. */
class Acc_provider_gamma implements PaymentProviderInterface
{
    public function get_name(): string { return 'acc_gamma'; }
    public function capabilities(): array {
        return ['checkout_type' => 'hosted_redirect', 'cards' => true, 'upi' => false,
                'net_banking' => true, 'wallets' => false, 'webhooks' => false,
                'refunds' => false, 'simulatable' => false];
    }
    public function create_order(float $a, string $r = '', array $m = []): array {
        return ['order_id' => 'g-' . strtolower(bin2hex(random_bytes(3))), 'amount' => $a];
    }
    public function verify_signature(string $o, string $p, string $s): bool { return false; }
    public function fetch_payment(string $p): array { return []; }
    public function parse_callback(array $b): array {
        return ['order_id' => (string)($b['GAMMA_REF'] ?? ''), 'payment_id' => (string)($b['GAMMA_TXN'] ?? ''),
                'signature' => ''];
    }
}

class DynamicSchoolProviderAcceptanceTest extends TestCase
{
    private const SCH_A = 'SCH_ACC_A';
    private const SCH_B = 'SCH_ACC_B';
    private const SCH_C = 'SCH_ACC_C';

    /**
     * A shared, in-memory stand-in for the whole `paymentProviders`
     * collection across all three schools — so a query for one school
     * genuinely has the others' rows to (correctly) ignore.
     */
    private function world(array $rowsBySchool): object
    {
        $all = [];
        foreach ($rowsBySchool as $school => $rows) {
            foreach ($rows as $r) { $all[] = array_merge(['schoolId' => $school], $r); }
        }
        return new class($all) {
            public array $all;
            public function __construct(array $a) { $this->all = $a; }
            public function firestoreGet($c, $d) { return null; }   // none migrated from legacy
            public function firestoreQuery($c, $filters = []) {
                // Model Firestore's real behaviour: apply the schoolId filter.
                $schoolId = null;
                foreach ($filters as $f) {
                    if (($f[0] ?? '') === 'schoolId' && ($f[1] ?? '') === '==') $schoolId = $f[2];
                }
                if ($schoolId === null) return $this->all;
                return array_values(array_filter($this->all, fn($r) => $r['schoolId'] === $schoolId));
            }
        };
    }

    private function resolveFor(object $world, string $school): ?array
    {
        return Payment_provider_config_resolver::resolve($world, $school, strtolower($school), '2026-27');
    }

    private const BEFORE = [
        self::SCH_A => [['provider' => 'acc_alpha', 'active' => true, 'isDefault' => true]],
        self::SCH_B => [['provider' => 'acc_beta',  'active' => true, 'isDefault' => true]],
        self::SCH_C => [['provider' => 'acc_gamma', 'active' => true, 'isDefault' => true]],
    ];

    /** School A switched alpha → gamma. B and C rows are byte-identical to BEFORE. */
    private const AFTER = [
        self::SCH_A => [
            ['provider' => 'acc_alpha', 'active' => false, 'isDefault' => false],
            ['provider' => 'acc_gamma', 'active' => true,  'isDefault' => true],
        ],
        self::SCH_B => [['provider' => 'acc_beta',  'active' => true, 'isDefault' => true]],
        self::SCH_C => [['provider' => 'acc_gamma', 'active' => true, 'isDefault' => true]],
    ];

    // ══ 1-6 · THREE SCHOOLS, THREE PROVIDERS, RESOLVED INDEPENDENTLY ══════

    public function test_each_school_resolves_its_own_provider(): void
    {
        $w = $this->world(self::BEFORE);

        $this->assertSame('acc_alpha', $this->resolveFor($w, self::SCH_A)['provider']);
        $this->assertSame('acc_beta',  $this->resolveFor($w, self::SCH_B)['provider']);
        $this->assertSame('acc_gamma', $this->resolveFor($w, self::SCH_C)['provider']);
    }

    /** Each provider declares a DIFFERENT checkout engine — the contract carries it. */
    public function test_each_school_gets_its_providers_own_checkout_engine(): void
    {
        $engines = [
            'acc_alpha' => (new Acc_provider_alpha())->capabilities()['checkout_type'],
            'acc_beta'  => (new Acc_provider_beta())->capabilities()['checkout_type'],
            'acc_gamma' => (new Acc_provider_gamma())->capabilities()['checkout_type'],
        ];

        $this->assertSame('native_sdk',      $engines['acc_alpha']);
        $this->assertSame('webview',         $engines['acc_beta']);
        $this->assertSame('hosted_redirect', $engines['acc_gamma']);
        $this->assertCount(3, array_unique($engines),
            'the three fixtures collapsed onto the same checkout engine, so this suite no '
            . 'longer proves engine-level diversity');
    }

    // ══ 7-10 · THE SWITCH ═════════════════════════════════════════════════

    public function test_school_a_switches_provider_and_the_others_do_not_move(): void
    {
        $before = $this->world(self::BEFORE);
        $after  = $this->world(self::AFTER);

        $this->assertSame('acc_alpha', $this->resolveFor($before, self::SCH_A)['provider']);

        $this->assertSame('acc_gamma', $this->resolveFor($after, self::SCH_A)['provider'],
            "School A's next payment did not pick up its newly-activated provider");

        $this->assertSame('acc_beta',  $this->resolveFor($after, self::SCH_B)['provider'],
            'School B moved as a side effect of School A switching');
        $this->assertSame('acc_gamma', $this->resolveFor($after, self::SCH_C)['provider'],
            'School C moved as a side effect of School A switching');
    }

    /**
     * School A and School C both end on `acc_gamma`. They must still be
     * resolved independently — a shared provider is not shared configuration.
     */
    public function test_two_schools_on_the_same_provider_remain_independent_rows(): void
    {
        $w = $this->world(self::AFTER);
        $a = $this->resolveFor($w, self::SCH_A);
        $c = $this->resolveFor($w, self::SCH_C);

        $this->assertSame('acc_gamma', $a['provider']);
        $this->assertSame('acc_gamma', $c['provider']);
        $this->assertSame(self::SCH_A, $a['schoolId'], "School A resolved another school's row");
        $this->assertSame(self::SCH_C, $c['schoolId'], "School C resolved another school's row");
    }

    // ══ 17, 23-24 · OLD ORDERS DO NOT MIGRATE ════════════════════════════

    /**
     * The switch must not reach back and re-bind an order minted under the
     * old provider. GW-ORD-1 is what prevents it, and it must work for
     * fixtures it has never heard of.
     */
    public function test_school_as_old_alpha_order_cannot_be_reused_under_gamma(): void
    {
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('acc_alpha', 'acc_gamma'),
            "School A's pre-switch order was judged reusable under its new provider — the "
            . 'checkout would be handed an id that provider never issued'
        );
        // …and the reverse, so the rule is not a one-way special case.
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('acc_gamma', 'acc_alpha')
        );
        // The old provider's OWN late callback still settles.
        $this->assertTrue(
            Payment_order_provenance::matchesActiveGateway('acc_alpha', 'acc_alpha'),
            'a payment captured before the switch was stranded by it'
        );
    }

    /**
     * Order provenance is recorded from the ADAPTER THAT MINTED IT, so a
     * later configuration change cannot rewrite history. Driven through the
     * real Payment_service.
     */
    public function test_order_provenance_is_stamped_from_the_minting_adapter(): void
    {
        $writes = [];
        $fb = new class($writes) {
            public array $writes = [];
            public function __construct($w) {}
            public function firestoreQuery($c, $f = []) { return []; }
            public function firestoreSet($c, $d, $data) { $this->writes[] = $data; return true; }
            public function firestoreGet($c, $d) { return null; }
        };

        $svc = new Payment_service();
        $ref = new ReflectionClass($svc);
        foreach (['firebase' => $fb, 'gateway' => new Acc_provider_beta(),
                  'schoolId' => self::SCH_B, 'session' => '2026-27',
                  'adminId' => 'T', 'feesBase' => ''] as $p => $v) {
            $prop = $ref->getProperty($p); $prop->setAccessible(true); $prop->setValue($svc, $v);
        }

        $result = $svc->create_order([
            'student_id' => 'STU1', 'student_name' => 'X', 'amount' => 700.00,
            'fee_months' => ['April'], 'class' => '9', 'section' => 'A',
        ]);

        $this->assertStringStartsWith('BETA_', $result['order_id']);
        $this->assertCount(1, $fb->writes);
        $this->assertSame('acc_beta', $fb->writes[0]['gateway'],
            'the persisted order did not record the adapter that actually minted it — '
            . 'provider provenance must be observed, never assumed');
    }

    // ══ 11 · NO BILLING V2 CORE FILE KNOWS ANY OF THIS ════════════════════

    public function test_no_billing_core_file_names_any_of_these_providers(): void
    {
        $core = ['libraries/Payment_service.php', 'libraries/Fee_payment_claim.php',
                 'libraries/Operations_accounting.php', 'libraries/Fee_lifecycle.php',
                 'libraries/Payment_order_provenance.php',
                 'libraries/Payment_provider_config_resolver.php'];

        foreach ($core as $file) {
            $src = (string) file_get_contents(APPPATH . $file);
            $code = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
                else $code .= $t;
            }
            $this->assertSame(0,
                preg_match_all('/acc_alpha|acc_beta|acc_gamma|razorpay|cashfree/i', $code),
                basename($file) . ' names a provider in executable code — running three '
                . 'schools on three different providers must require no core change at all');
        }
    }

    // ══ 20-22 · FAIL-CLOSED ═══════════════════════════════════════════════

    public function test_unknown_and_inactive_providers_fail_closed(): void
    {
        // Unknown provider id: not in the catalogue at all.
        $this->assertNull(Payment_gateway_selector::adapter_class('acc_alpha'),
            'a test-fixture provider resolved to a runnable adapter in production code');

        // A school whose only provider is deactivated must REFUSE, not fall back.
        $w = $this->world([self::SCH_A => [['provider' => 'acc_alpha', 'active' => false, 'isDefault' => true]]]);
        $this->assertNull($this->resolveFor($w, self::SCH_A),
            'a school that deactivated its provider resolved to something anyway');
    }

    /**
     * THE HONESTY GATE. These fixtures prove the FRAMEWORK. None of them may
     * be selectable by a real school — that is what separates "N-provider
     * capable" from "we shipped three untested providers".
     */
    public function test_none_of_these_fixture_providers_is_production_selectable(): void
    {
        foreach (['acc_alpha', 'acc_beta', 'acc_gamma'] as $id) {
            $this->assertNull(Payment_gateway_selector::provider_stage($id),
                "fixture provider '{$id}' was added to the production catalogue");
            $this->assertNotContains($id, Payment_gateway_selector::selectable_provider_ids('production'));
            $this->assertNotContains($id, Payment_gateway_selector::selectable_provider_ids('development'));
        }
    }
}
