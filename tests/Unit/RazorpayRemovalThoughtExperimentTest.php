<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';
require_once APPPATH . 'libraries/Payment_order_provenance.php';
require_once APPPATH . 'libraries/Payment_provider_config_resolver.php';
require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE "RIP OUT RAZORPAY" THOUGHT EXPERIMENT — MADE EXECUTABLE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Razorpay is NOT deleted. This asks the harder question in a way that can
 * actually fail: if Razorpay vanished tomorrow and a school ran on some other
 * provider entirely, would anything still quietly assume Razorpay?
 *
 * A structural audit ("grep finds no provider names") cannot answer that. It
 * catches names, not ASSUMPTIONS — a payment path that only works because
 * Razorpay's order ids start with `order_`, or because its signature is a
 * plain hex HMAC, would pass every census in this programme and still break.
 *
 * So this drives a complete payment through a provider deliberately built to
 * violate every Razorpay-shaped assumption:
 *
 *   • order ids that look nothing like `order_…`
 *   • a signature scheme that is not a hex HMAC of `order|payment`
 *   • callback fields under entirely different names, nested
 *   • a checkout engine Razorpay does not use
 *   • amounts reported in a different unit shape
 *
 * If Billing V2 has a hidden Razorpay dependency, this is where it surfaces.
 */

/** Deliberately alien. Nothing here resembles Razorpay's protocol. */
class Zzz_alien_provider implements PaymentProviderInterface
{
    public array $created = [];

    public function get_name(): string { return 'zzz_alien'; }

    public function capabilities(): array
    {
        return [
            'checkout_type' => 'hosted_redirect',   // NOT native_sdk
            'cards' => true, 'upi' => false, 'net_banking' => true,
            'wallets' => false, 'webhooks' => true, 'refunds' => false,
            'simulatable' => false,
            'reports_captured_amount' => true,
        ];
    }

    public function create_order(float $amount, string $receiptRef = '', array $meta = []): array
    {
        // No `order_` prefix. Uppercase, dotted, nothing Razorpay-shaped.
        $id = 'TXN.' . strtoupper(bin2hex(random_bytes(4))) . '.Z';
        $this->created[] = $id;
        return ['order_id' => $id, 'amount' => $amount, 'currency' => 'INR'];
    }

    /** Not a hex HMAC of "order|payment" — a reversed, delimited digest. */
    public function verify_signature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($signature === '') return false;
        return hash_equals(strrev(md5($paymentId . '::' . $orderId)), $signature);
    }

    public static function sign(string $orderId, string $paymentId): string
    {
        return strrev(md5($paymentId . '::' . $orderId));
    }

    public function fetch_payment(string $paymentId): array
    {
        return [
            'payment_id'   => $paymentId,
            'amount'       => 700.00,
            'amount_paise' => 70000,
            'status'       => 'captured',
            'captured'     => true,
        ];
    }

    /** Deeply nested, entirely different field names. */
    public function parse_callback(array $body): array
    {
        $env = is_array($body['envelope'] ?? null) ? $body['envelope'] : [];
        $ref = is_array($env['reference'] ?? null) ? $env['reference'] : [];
        return [
            'order_id'   => (string) ($ref['txn'] ?? ''),
            'payment_id' => (string) ($ref['receipt'] ?? ''),
            'signature'  => (string) ($env['seal'] ?? ''),
        ];
    }
}

class RazorpayRemovalThoughtExperimentTest extends TestCase
{
    private function firebase(array $order = null): object
    {
        return new class($order) {
            public ?array $order; public array $writes = [];
            public function __construct(?array $o) { $this->order = $o; }
            public function firestoreGet($c, $d) { return $this->order; }
            public function firestoreQuery($c, $f = []) { return []; }
            public function firestoreSet($c, $d, $data) { $this->writes[] = $data; return true; }
        };
    }

    private function service(object $gw, object $fb): Payment_service
    {
        $svc = new Payment_service();
        $ref = new ReflectionClass($svc);
        foreach (['firebase' => $fb, 'gateway' => $gw, 'schoolId' => 'SCH_ALIEN',
                  'session' => '2026-27', 'adminId' => 'A', 'feesBase' => ''] as $p => $v) {
            $prop = $ref->getProperty($p); $prop->setAccessible(true); $prop->setValue($svc, $v);
        }
        return $svc;
    }

    // ══ ORDER CREATION ════════════════════════════════════════════════════

    /**
     * An order id that looks nothing like Razorpay's must survive creation and
     * be persisted verbatim, with provenance from the alien adapter.
     */
    public function test_an_alien_order_id_is_created_and_stamped_correctly(): void
    {
        $gw = new Zzz_alien_provider();
        $fb = $this->firebase();

        $result = $this->service($gw, $fb)->create_order([
            'student_id' => 'STU1', 'student_name' => 'X', 'amount' => 700.00,
            'fee_months' => ['April'], 'class' => '9', 'section' => 'A',
        ]);

        $this->assertStringStartsWith('TXN.', $result['order_id'],
            'the alien order id was rewritten or rejected — something assumes a '
            . 'Razorpay-shaped identifier');
        $this->assertStringNotContainsString('order_', $result['order_id']);

        $this->assertCount(1, $fb->writes);
        $this->assertSame('zzz_alien', $fb->writes[0]['gateway'],
            'provenance was not taken from the minting adapter');
        $this->assertSame($result['order_id'], $fb->writes[0]['gateway_order_id']);
    }

    // ══ VERIFICATION ══════════════════════════════════════════════════════

    /**
     * A non-Razorpay signature scheme must verify, and the core must NOT
     * attempt its own HMAC — signature verification is the adapter's.
     */
    public function test_an_alien_signature_scheme_verifies_through_the_core(): void
    {
        $orderId   = 'TXN.DEADBEEF.Z';
        $paymentId = 'RCPT-991';
        $sig       = Zzz_alien_provider::sign($orderId, $paymentId);

        $fb = $this->firebase([
            'status' => 'created', 'schoolId' => 'SCH_ALIEN', 'session' => '2026-27',
            'gateway' => 'zzz_alien', 'gateway_order_id' => $orderId, 'amount' => 700.00,
        ]);

        $r = $this->service(new Zzz_alien_provider(), $fb)->verify_payment($orderId, $paymentId, $sig);

        $this->assertTrue($r['verified'],
            'a valid alien signature was rejected — the core is verifying signatures '
            . 'itself instead of delegating, or assumes Razorpay\'s scheme');
        $this->assertSame('zzz_alien', $r['gateway_name']);
        $this->assertTrue($r['gateway_reports_amount']);
        $this->assertSame(70000, $r['gateway_amount_minor'],
            'the exact minor-unit amount did not survive — MONEY-1 would silently fall '
            . 'back to the tolerant float comparison for this provider');
    }

    /** THE NEGATIVE. A wrong signature must still be refused. */
    public function test_an_invalid_alien_signature_is_refused(): void
    {
        $fb = $this->firebase([
            'status' => 'created', 'schoolId' => 'SCH_ALIEN', 'session' => '2026-27',
            'gateway' => 'zzz_alien', 'amount' => 700.00,
        ]);
        $r = $this->service(new Zzz_alien_provider(), $fb)
            ->verify_payment('TXN.X.Z', 'RCPT-1', 'not-a-valid-seal');

        $this->assertFalse($r['verified'],
            'an invalid signature was accepted for a non-Razorpay provider');
    }

    // ══ CALLBACK NORMALIZATION ════════════════════════════════════════════

    /**
     * Deeply nested, alien field names must normalize. The core reads only
     * the normalized triple and never the provider's own shape.
     */
    public function test_alien_nested_callback_fields_normalize(): void
    {
        $svc = $this->service(new Zzz_alien_provider(), $this->firebase());

        $parsed = $svc->parse_callback([
            'envelope' => [
                'reference' => ['txn' => 'TXN.AB.Z', 'receipt' => 'RCPT-7'],
                'seal'      => 'SEAL123',
            ],
        ]);

        $this->assertSame(
            ['order_id' => 'TXN.AB.Z', 'payment_id' => 'RCPT-7', 'signature' => 'SEAL123'],
            $parsed,
            'a provider whose callback shape differs from Razorpay\'s could not be '
            . 'normalized — the core would receive empty identifiers and refuse a real '
            . 'payment'
        );
    }

    // ══ GW-ORD-1 WITH NO RAZORPAY ANYWHERE ═══════════════════════════════

    public function test_order_provenance_is_symmetric_for_alien_providers(): void
    {
        $this->assertTrue(Payment_order_provenance::matchesActiveGateway('zzz_alien', 'zzz_alien'));
        $this->assertFalse(Payment_order_provenance::matchesActiveGateway('zzz_alien', 'other_alien'));
        $this->assertFalse(Payment_order_provenance::matchesActiveGateway('other_alien', 'zzz_alien'));
        $this->assertFalse(Payment_order_provenance::matchesActiveGateway('', 'zzz_alien'),
            'unrecorded provenance was judged reusable');
    }

    /** A school running ONLY the alien provider resolves it with no Razorpay involved. */
    public function test_a_school_with_no_razorpay_at_all_resolves_normally(): void
    {
        $fb = new class {
            public function firestoreGet($c, $d) { return null; }   // no legacy doc
            public function firestoreQuery($c, $f = []) {
                return [['schoolId' => 'SCH_ALIEN', 'provider' => 'zzz_alien',
                         'active' => true, 'isDefault' => true]];
            }
        };
        $cfg = Payment_provider_config_resolver::resolve($fb, 'SCH_ALIEN', 'alien', '2026-27');

        $this->assertSame('zzz_alien', $cfg['provider'],
            'a school with no Razorpay configuration anywhere failed to resolve its own '
            . 'provider');
    }

    // ══ THE CATALOGUE WITHOUT RAZORPAY ════════════════════════════════════

    /**
     * Conceptually remove Razorpay from the catalogue and confirm nothing
     * structurally depends on it being present. Razorpay is NOT modified —
     * this reasons over the catalogue's own data.
     */
    public function test_the_catalogue_has_no_structural_dependency_on_razorpay(): void
    {
        $all = Payment_gateway_selector::available_providers('development');
        $withoutRazorpay = array_diff_key($all, ['razorpay' => 1]);

        // The remaining entries must still be well-formed — i.e. the catalogue
        // shape is not held together by the Razorpay row.
        foreach ($withoutRazorpay as $id => $meta) {
            foreach (['id', 'display_name', 'description', 'checkout_type', 'credentials'] as $k) {
                $this->assertArrayHasKey($k, $meta,
                    "catalogue entry '{$id}' is missing '{$k}' — the metadata contract only "
                    . 'holds for Razorpay');
            }
        }

        $this->assertNotEmpty($withoutRazorpay,
            'removing Razorpay empties the catalogue entirely. Not a defect today (it is '
            . 'the only certified provider), but recorded so the dependency is explicit '
            . 'rather than discovered later.');
    }

    /** An unregistered alien provider must fail closed — capability is not permission. */
    public function test_the_alien_provider_is_not_secretly_selectable(): void
    {
        $this->assertNull(Payment_gateway_selector::adapter_class('zzz_alien'));
        $this->assertNull(Payment_gateway_selector::provider_stage('zzz_alien'));
        $this->assertNotContains('zzz_alien',
            Payment_gateway_selector::selectable_provider_ids('development'));
    }
}
