<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_management Controller
 *
 * Advanced fee management module: categories, discount policies, scholarships,
 * refunds, fee reminders, payment gateway config, and online payments.
 *
 * Extends MY_Controller which provides:
 *   $this->school_name, $this->school_id, $this->session_year,
 *   $this->firebase, $this->CM, json_success(), json_error()
 */
class Fee_management extends MY_Controller
{
    /**
     * ── GW-2 · ADAPTERS THAT CANNOT REPORT A CAPTURED AMOUNT ──────────────
     *
     * When a gateway cannot tell us what it actually captured, the payment
     * proceeds on signature validity alone (fail-open) and the gap is reported
     * as telemetry. These two adapters are the exception: neither performs a
     * real capture, so reporting them would be pure noise.
     *
     * Expressed as a DENYLIST on purpose. The check was previously
     * `$gatewayName === 'razorpay'` — an allowlist of one, which silenced every
     * other provider including any added in future. A new adapter must be LOUD
     * by default; only an adapter deliberately listed here is quiet.
     */
    private const AMOUNTLESS_GATEWAYS = ['mock', 'unavailable'];

    /**
     * W-1 (2026-08-25) — THE ONE PLACE THIS PATH IS WRITTEN.
     *
     * The webhook URL surfaced by `test_gateway_connection()` is what an
     * operator copies into the provider's dashboard, so it must correspond to
     * a REAL route. It previously read `fee_management/webhook`, which has no
     * route and no controller method — every registered webhook 404'd, and
     * because the CALLBACK path is authoritative nothing visibly broke. A
     * silent misconfiguration is the expensive kind.
     *
     * Declared as a constant so the advertised string and the proof that it
     * routes cannot drift apart: `GatewayWebhookUrlCorrespondenceTest` reads
     * THIS constant and asserts routes.php maps it to a method that exists.
     */
    public const WEBHOOK_PATH = 'fee_management/payment_webhook';

    /**
     * PAY-OPS-5 — Cloudflare's published edge ranges.
     *
     * Used ONLY to decide whether `CF-Connecting-IP` may be believed. Membership
     * here grants no access of its own: it is the difference between "this
     * connection came from our CDN, so the header it added is trustworthy" and
     * "this connection came from anywhere, so its headers are not".
     *
     * Source: https://www.cloudflare.com/ips/ (v4 + v6). These change rarely and
     * only ever by ADDITION; a range missing from this list degrades safely —
     * `_webhook_client_ip()` falls back to the peer address, which is the
     * pre-PAY-OPS-5 behaviour, never a bypass.
     */
    private const CLOUDFLARE_RANGES = [
        // IPv4
        '173.245.48.0/20',  '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18',  '108.162.192.0/18','190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15',  '104.16.0.0/13',
        '104.24.0.0/14',    '172.64.0.0/13',   '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** Roles for admin-level config (gateway, refund approval) */
    private const ADMIN_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];

    /** Roles for financial operations (categories, discounts, scholarships) */
    private const FINANCE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'];

    /** Roles that may view fee management data */
    private const VIEW_ROLES    = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant', 'Teacher'];

    /** @var string Base Firebase path for fees */
    private $feesBase;

    /** @var string Session root path */
    private $sessionRoot;

    /** @var array|null Firebase ID-token claims for parent-app API calls */
    private $_parent_claims = null;

    /** @var string|null Cached raw body for webhook — avoids a second
     *  file_get_contents('php://input') call later in payment_webhook(),
     *  since the stream is not guaranteed to be rewindable on every PHP
     *  SAPI configuration. Populated by the constructor when the request
     *  is a webhook; null otherwise. */
    private $_webhookRawBody = null;

    public function __construct()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Webhook is a server-to-server call — no session required.
        // It authenticates via HMAC signature instead.
        $isWebhook = (strpos($uri, 'payment_webhook') !== false);

        // Parent-app endpoints authenticate via Firebase ID token (Bearer).
        // School context is resolved from the token claims.
        $isParentApi = (
            strpos($uri, 'fee_management/parent_create_order')   !== false ||
            strpos($uri, 'fee_management/parent_verify_payment') !== false
        );

        if ($isWebhook) {
            // Skip MY_Controller auth — use CI_Controller directly.
            // Razorpay webhooks are stateless (no session cookie), so we
            // CANNOT rely on $this->session->userdata() to resolve the
            // school — the earlier implementation did, and it would reject
            // every real Razorpay delivery with HTTP 400. Instead we peek
            // at the incoming payload for the order_id and look it up in
            // feeOnlineOrders (whose docs carry schoolId + session as
            // top-level fields — see Payment_service::create_order).
            CI_Controller::__construct();
            $this->load->library('firebase');

            // Cache the raw body here so payment_webhook() does not have
            // to re-read a potentially-consumed php://input stream.
            $this->_webhookRawBody = (string) file_get_contents('php://input');
            $body = json_decode($this->_webhookRawBody, true);
            if (!is_array($body)) {
                $body = $this->input->post() ?: [];
            }
            // ── PAY-OPS-3 (second site) · THE WEBHOOK BODY IS NESTED ─────
            //
            // These three top-level keys describe the BROWSER RETURN. A real
            // Razorpay webhook carries the order id at
            // `payload.payment.entity.order_id` (or `payload.order.entity.id`
            // for order.paid), so this resolved to '' and the request was
            // rejected with "Invalid order context" — a 400 raised BEFORE the
            // signature is even checked, which is why a genuinely signed
            // webhook looked indistinguishable from a forged one.
            //
            // Fixing the provider adapter's parse_callback() was not enough:
            // this extraction runs earlier, in the constructor, purely to
            // resolve the tenant from the order. Both had the same assumption.
            //
            // PROVEN AT RUNTIME: Razorpay captured pay_TV7acX8SsAmaf8 (₹1,900,
            // order_TV6eZr9M88GFTI) while the client's session expired mid
            // verification — the exact case the webhook exists for — and the
            // signed delivery could not be routed until this line understood
            // the real envelope.
            $wp = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            $wpPayment = is_array($wp['payment']['entity'] ?? null) ? $wp['payment']['entity'] : [];
            $wpOrder   = is_array($wp['order']['entity']   ?? null) ? $wp['order']['entity']   : [];

            $orderId = trim((string) (
                $body['razorpay_order_id']
                ?? $body['order_id']
                ?? $body['gateway_order_id']
                ?? $wpPayment['order_id']
                ?? $wpOrder['id']
                ?? ''
            ));

            $ctx = $orderId !== '' ? $this->resolveSchoolFromOrder($orderId) : null;

            if (!is_array($ctx) || ($ctx['school'] ?? '') === '' || ($ctx['session'] ?? '') === '') {
                log_message('error', "[WEBHOOK CONTEXT FAILED] order=" . ($orderId !== '' ? $orderId : '(missing)'));
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid order context']);
                exit;
            }

            $this->school_name  = $ctx['school'];
            // PAY-OPS-5 — school_id was never set on this branch, so
            // `Payment_provider_config_resolver::resolve($this->firebase,
            // $this->school_id, …)` received null and the webhook died with a
            // TypeError (HTTP 500) AFTER passing signature verification and
            // order resolution. The gateway would have seen a 5xx and retried
            // forever, and no payment could ever be reconciled by webhook.
            //
            // MY_Controller:1633 is the authority on the relationship —
            // `$this->school_name = $this->school_id;` (name == id) — and the
            // parent-API branch above already assigns both. This branch runs
            // CI_Controller::__construct() directly, so nothing sets it for us.
            $this->school_id    = $ctx['school'];
            $this->session_year = $ctx['session'];
            log_message('debug', "[WEBHOOK CONTEXT RESOLVED] school={$this->school_name} session={$this->session_year} order={$orderId}");

            // ── FEE-W1 ────────────────────────────────────────────────────
            // This branch runs CI_Controller::__construct(), so MY_Controller
            // never executes and NOTHING it normally loads exists here — in
            // particular `fs` and `fsTxn`. But _verify_and_process(), which the
            // webhook shares with the frontend callback, calls
            // $this->fsTxn->markPaymentProcessed() after writing the receipt.
            //
            // Undefined property → null → "Call to a member function on null",
            // which is an Error, NOT an Exception, so the catch around that call
            // did not catch it (verified: catch(\Exception) lets it escape).
            // The request died AFTER the receipt, allocations and journal were
            // written but BEFORE the feeOnlinePayments record, the claim release
            // and the webhook dedup marker — leaving "receipt exists, payment
            // record missing" and returning HTTP 500 to the gateway on every
            // webhook-processed payment.
            //
            // It did NOT double-charge: the retry short-circuits on
            // feeOnlineOrders.status === 'paid', which is written before the
            // fatal point. The damage was data integrity and false 500s.
            //
            // The tenant context is already resolved and verified above, so the
            // same collaborators the frontend path uses can be initialised here.
            $this->load->library('firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);
        } else if ($isParentApi) {
            // Parent-app API — verify Firebase ID token, derive school context
            CI_Controller::__construct();
            $this->load->library('firebase');
            $this->load->library('api_auth');

            $claims = $this->api_auth->require_auth();
            $role   = strtolower(trim((string)($claims['role'] ?? '')));
            if (!in_array($role, ['parent', 'student'], true)) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Parent/student role required.']);
                exit;
            }

            // Both tenant properties must be set here. This branch runs
            // CI_Controller::__construct() rather than parent::__construct(),
            // so MY_Controller's bootstrap — which normally assigns
            // school_id from the session/claims — never executes. Setting
            // only school_name left school_id null for the whole
            // parent-app request, and _init_payment_service() passes
            // school_id to Payment_provider_config_resolver::resolve(),
            // whose string type-hint then fataled with a 500 on BOTH
            // parent_create_order and parent_verify_payment.
            //
            // MY_Controller:1633 is the authority on the relationship:
            //     $this->school_name = $this->school_id;  // name == id
            // so it is assigned in that direction here too, from the one
            // claim that carries the tenant. Deriving name FROM id keeps a
            // single source of truth rather than two parallel reads that
            // could drift.
            $this->school_id    = (string) ($claims['school_id'] ?? '');
            $this->school_name  = $this->school_id;
            $this->session_year = $this->_resolve_active_session($this->school_name);
            $this->admin_id     = 'parent_app:' . ($claims['uid'] ?? 'unknown');
            // Save claims for handler methods
            $this->_parent_claims = $claims;

            if ($this->school_id === '' || $this->session_year === '') {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unable to resolve school/session from token.']);
                exit;
            }
        } else {
            parent::__construct();
            require_permission('Fees');
        }

        $sn = $this->school_name;
        $sy = $this->session_year;
        $this->feesBase    = "Schools/$sn/$sy/Accounts/Fees";
        $this->sessionRoot = "Schools/$sn/$sy";

        $this->load->library('Fee_integrity_checker', null, 'feeIntegrity');
        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->feeIntegrity->init($this->firebase, $this->school_name, $this->session_year);
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);

        // Firestore dual-write sync (best-effort)
        $this->load->library('Fee_firestore_sync', null, 'fsSync');
        $this->fsSync->init($this->firebase, $this->school_name, $this->session_year);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS

    /** Safe trim — never passes null to trim() (PHP 8.1+ compat) */
    private function _post(string $key): string
    {
        return trim((string)($this->input->post($key) ?? ''));
    }

    /** Strip the `{schoolId}_` prefix from a Firestore doc ID to get the entity ID. */
    private function _stripSchoolPrefix(string $docId): string
    {
        $prefix = $this->school_name . '_';
        if (strpos($docId, $prefix) === 0) {
            return substr($docId, strlen($prefix));
        }
        return $docId;
    }

    /**
     * Convert a demand `period` string to the canonical `monthFee` map
     * key — strip trailing year/session tokens but PRESERVE multi-word
     * labels like "Yearly Fees".
     *
     * Examples:
     *   "April 2026"              -> "April"
     *   "Yearly Fees 2026-27"     -> "Yearly Fees"
     *   "Apr-2026"                -> "Apr-2026"  (no trailing year token to strip)
     *
     * Without this helper the previous `explode(' ', $period)[0]` logic
     * dropped the "Fees" suffix on annual demands, producing a "Yearly"
     * key that conflicted with the existing "Yearly Fees" key in
     * students.monthFee — leaving two contradictory entries for the
     * same period.
     */
    private function _periodToMonthFeeKey(string $period): string
    {
        $period = trim($period);
        if ($period === '') return '';
        // Strip trailing 4-digit year (e.g. "April 2026") OR
        // YYYY-YY session range (e.g. "Yearly Fees 2026-27").
        $stripped = preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $period);
        return is_string($stripped) ? trim($stripped) : $period;
    }
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get existing fee structure (Monthly + Yearly titles).
     * Firestore: aggregate from feeStructures collection, grouped by type.
     */
    private function _getFeesStructure()
    {
        $result = ['Monthly' => [], 'Yearly' => []];
        try {
            $rows = $this->firebase->firestoreQuery('feeStructures', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                $heads = is_array($d['feeHeads'] ?? null) ? $d['feeHeads'] : [];
                foreach ($heads as $h) {
                    $name = trim((string) ($h['name'] ?? ''));
                    // Fees V7 reader migration (original-bug fix): classify by the canonical
                    // billingType (present on chart heads + registry). Fall back to legacy
                    // type/frequency only for titles-doc heads that predate billingType.
                    $bt = (string) ($h['billingType'] ?? '');
                    if ($bt === '') {
                        $freq = strtolower((string) ($h['frequency'] ?? ''));
                        $bt = (($h['type'] ?? '') === 'Yearly' || in_array($freq, ['annual', 'yearly'], true)) ? 'Yearly' : 'Monthly';
                    }
                    $type = ($bt === 'Yearly') ? 'Yearly' : 'Monthly';
                    if ($name !== '' && !isset($result[$type][$name])) {
                        $result[$type][$name] = '';
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_management::_getFeesStructure Firestore query failed: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * Get all distinct fee title names as a flat array.
     *
     * Pure Firestore: aggregate from feeStructures collection (Session A
     * source of truth). Falls back to the legacy RTDB Fees Structure
     * node only if the Firestore collection hasn't been seeded yet, so
     * existing admin screens keep working during the migration window.
     */
    private function _getAllFeeTitles()
    {
        $titles = [];
        $seen   = [];
        try {
            $rows = $this->firebase->firestoreQuery('feeStructures', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                $heads = is_array($d['feeHeads'] ?? null) ? $d['feeHeads'] : [];
                foreach ($heads as $h) {
                    $name = trim((string) ($h['name'] ?? ''));
                    if ($name === '' || isset($seen[$name])) continue;
                    $seen[$name] = true;
                    $titles[] = $name;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_management::_getAllFeeTitles Firestore query failed: ' . $e->getMessage());
        }
        return $titles;
    }

    /**
     * Parse class string into [classNode, sectionNode].
     * Input: "Class 9th" + "Section A" or "9th" + "A"
     */
    private function _normalizeClassSection($class, $section)
    {
        $class = trim($class);
        if (stripos($class, 'Class ') !== 0) {
            $class = 'Class ' . $class;
        }

        $section = trim($section);
        if (stripos($section, 'Section ') !== 0) {
            $section = 'Section ' . strtoupper($section);
        }

        // Reject path-injection characters (., /, #, $, [, ])
        if (preg_match('/[.\/\#\$\[\]]/', $class . $section)) {
            $this->json_error('Invalid class/section value.');
        }

        return [$class, $section];
    }

    /**
     * Get all classes and sections from Firestore sections collection.
     * Returns: [['class' => 'Class 9th', 'section' => 'Section A'], ...]
     */
    private function _getAllClassSections()
    {
        $result = [];
        try {
            $rows = $this->firebase->firestoreQuery('sections', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                $cls = trim((string) ($d['className'] ?? ''));
                $sec = trim((string) ($d['section']   ?? ''));
                if ($cls === '' || $sec === '') continue;
                // Ensure prefixed format
                if (stripos($cls, 'Class ') !== 0) $cls = 'Class ' . $cls;
                if (stripos($sec, 'Section ') !== 0) $sec = 'Section ' . $sec;
                $result[] = [
                    'class'   => $cls,
                    'section' => $sec,
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_management::_getAllClassSections Firestore query failed: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * ── RCPT-1 · THIS ALLOCATOR WAS NOT ATOMIC, DESPITE SAYING SO ─────────
     *
     * The previous implementation was documented as "Atomically increment the
     * receipt counter with retry logic". It was not atomic in any sense:
     *
     *     $doc  = firestoreGet('feeCounters', $docId);          // READ
     *     $next = ($doc['value'] ?? 0) + 1;                     // DECIDE
     *     firestoreSet('feeCounters', $docId, ['value' => $next]);  // BLIND SET
     *     $verify = firestoreGet('feeCounters', $docId);        // READ BACK
     *     if ($verify['value'] === $next) return $next;         // "optimistic check"
     *
     * The read-back verification is worse than no check, because it looks like
     * one. Two callers both read 10, both compute 11, both write 11, and both
     * read back 11 — so the "verify" SUCCEEDS FOR BOTH and both are handed
     * receipt number 11.
     *
     * The fallback was more damaging still: after five attempts it wrote
     * `(int)(microtime(true) * 1000) % 999999` as the counter VALUE. That is
     * not merely a weak number — it can be LOWER than the current counter, so
     * it rewinds the receipt sequence and collides with receipts already
     * issued, permanently.
     *
     * ── WHY THIS IS A DELEGATION AND NOT A FIX ────────────────────────────
     * The method had NO callers — verified across PHP and JS, including dynamic
     * invocation patterns. It was a loaded gun rather than a live defect, and
     * its docblock actively invited someone to use it.
     *
     * Rather than delete it (a dynamic caller cannot be fully disproved) or
     * repair it (a second correct allocator is a second thing to keep correct),
     * it now delegates to the ONE canonical allocator —
     * `Fee_firestore_txn::nextCounter('receipt_seq')` — which claims each value
     * with an atomic create and retries on 409, so a concurrent writer that
     * already took V+1 forces V+2 rather than a duplicate.
     *
     * If the transaction helper is unavailable it FAILS rather than inventing a
     * number. A refused receipt is recoverable; two receipts sharing a number
     * are not.
     */
    private function _nextReceiptNo()
    {
        if (!isset($this->fsTxn) || !method_exists($this->fsTxn, 'nextCounter')) {
            log_message('error',
                'Fee_management::_nextReceiptNo called without the transaction helper — '
                . 'refusing to allocate a receipt number outside the canonical counter.');
            throw new \RuntimeException(
                'Receipt numbering is unavailable. No receipt number was allocated.');
        }

        $seq = (int) $this->fsTxn->nextCounter('receipt_seq');
        if ($seq <= 0) {
            log_message('error',
                'Fee_management::_nextReceiptNo — canonical counter returned no value; '
                . 'refusing to fall back to a derived number.');
            throw new \RuntimeException(
                'Could not allocate a receipt number. Please retry.');
        }

        return [
            'number' => $seq,
            'key'    => 'F' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PAGE LOADERS (GET)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fee Categories page.
     */
    public function categories()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view', 'Fees', 'view');
        $data = [];
        $data['feesStructure'] = $this->_getFeesStructure();
        $data['page_title']    = 'Fee Titles & Categories';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/categories', $data);
        $this->load->view('include/footer');
    }

    /**
     * Discount Policies page.
     */
    public function discounts()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view', 'Fees', 'view');
        $data = [];
        $catRows = $this->firebase->firestoreQuery('feeCategories', [
            ['schoolId', '==', $this->school_name],
        ]);
        $cats = [];
        foreach ((array) $catRows as $row) {
            $d = $row['data'] ?? $row;
            $id = $this->_stripSchoolPrefix($row['id'] ?? '');
            $d['id'] = $id;
            $cats[$id] = $d;
        }
        $data['categories']    = $cats;
        $data['feesStructure'] = $this->_getFeesStructure();
        $data['page_title']    = 'Discount Policies';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/discounts', $data);
        $this->load->view('include/footer');
    }

    /**
     * Scholarships page.
     */
    public function scholarships()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view', 'Fees', 'view');
        $data = [];
        $data['page_title'] = 'Scholarships';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/scholarships', $data);
        $this->load->view('include/footer');
    }

    /**
     * Refunds page.
     */
    public function refunds()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view', 'Fees', 'view');
        $data = [];
        $data['fee_titles']  = $this->_getAllFeeTitles();
        $data['page_title']  = 'Fee Refunds';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/refunds', $data);
        $this->load->view('include/footer');
    }

    /**
     * Fee Reminders page.
     */
    public function reminders()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view', 'Fees', 'view');
        $data = [];
        $settingsDoc = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_reminders");
        $data['settings']   = is_array($settingsDoc) ? $settingsDoc : [];
        $data['page_title'] = 'Fee Reminders';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/reminders', $data);
        $this->load->view('include/footer');
    }

    /**
     * Payment Gateway Configuration page.
     */
    public function gateway()
    {
        // ── GW-SEC1 · THE GATEWAY PAGE LEAKED LIVE SECRETS ────────────────────
        // Two defects, either of which alone is serious:
        //
        // 1. RBAC. This page was guarded by VIEW_ROLES, which includes Teacher
        //    and Accountant. Payment credentials are not fee *data* — they are
        //    the keys to the school's merchant account, and belong behind the
        //    same roles that may CHANGE them (save_gateway_config already uses
        //    ADMIN_ROLES).
        //
        // 2. Masking that was computed and then not used. The code below built
        //    `api_secret_masked` / `webhook_secret_masked` but never removed the
        //    raw values, and the view rendered the RAW ones — an input of
        //    type="password" whose value attribute was $config['api_secret'],
        //    and an input of type="text" whose value was the webhook secret.
        //
        //    `type="password"` hides a value on screen, not in the page source.
        //    Anyone who could open this page could read both secrets from View
        //    Source or DevTools.
        //
        // The webhook secret is the one that reaches the money path: it is the
        // HMAC key `payment_webhook` verifies against, so holding it lets an
        // attacker forge a validly-signed webhook and clear fees that were never
        // paid.
        //
        // The mechanism to do this correctly already existed and was simply not
        // wired here — get_gateway_config() returns masked copies, and
        // save_gateway_config() preserves the stored secret when a masked value
        // (`^\*+`) is posted back. So the raw values are now replaced, not
        // merely accompanied, and a round-trip save is unaffected.
        $this->_require_role(self::ADMIN_ROLES, 'fee_mgmt_gateway_view', 'Fees', 'manage');
        $data = [];
        $config = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        if (is_array($config)) {
            // REPLACE the secrets — never hand a raw credential to a view.
            foreach (['api_secret', 'webhook_secret'] as $__secretField) {
                if (!empty($config[$__secretField])) {
                    $__len = strlen((string) $config[$__secretField]);
                    $__masked = $__len > 4
                        ? str_repeat('*', $__len - 4) . substr((string) $config[$__secretField], -4)
                        : str_repeat('*', $__len);
                    $config[$__secretField]              = $__masked;
                    $config[$__secretField . '_masked']  = $__masked;
                }
            }
        }
        $data['config']     = is_array($config) ? $config : [];
        $data['page_title'] = 'Payment Gateway';

        // ── THE PROVIDER PICKER IS DATA, NOT MARKUP (2026-08-24) ────────────
        // The tiles used to be hardcoded HTML, which is how Stripe and Paytm
        // stayed on this page for years after the server stopped accepting
        // them (PA-UI-1). Removing those two tiles fixed the symptom; this
        // fixes the cause — the picker now renders from the SAME catalogue
        // that decides what save_gateway_config() will accept and which
        // adapter can run, so the three cannot drift apart again.
        //
        // Carries only display metadata and credential FIELD DEFINITIONS.
        // No secret, and no value, ever reaches the view through this.
        $this->load->library('Payment_gateway_selector');
        // W-1: the Integration Info card shows the operator which URL to
        // register. It must come from the same constant the diagnostic uses.
        $data['webhook_path'] = self::WEBHOOK_PATH;
        // ── PAY-OPS-4 · THE ADVERTISED URL MUST BE THE PRODUCTION ONE ──────
        // This card is COPIED INTO THE PROVIDER DASHBOARD, where it becomes
        // the address the gateway calls for months. Built with base_url() it
        // reflected whatever host the admin happened to be browsing from —
        // `localhost:8080`, a LAN IP, or the ngrok tunnel used while testing
        // this very feature. Registering a tunnel URL works right up until the
        // tunnel dies, and then every webhook silently 404s: payments capture,
        // no receipts appear, and nothing in ZenX says why.
        //
        // public_base_url() is the canonical public origin
        // (`https://www.zenxii.com/` by default, PUBLIC_BASE_URL per
        // deployment) — the same helper the printed admission QR already uses,
        // for the same reason: a URL that leaves this machine must not depend
        // on which machine generated it.
        $this->load->helper('public_url');
        $data['webhook_url']  = public_base_url(self::WEBHOOK_PATH);

        $data['available_providers'] = Payment_gateway_selector::available_providers(
            Payment_gateway_selector::effective_environment()
        );

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/gateway', $data);
        $this->load->view('include/footer');
    }

    /**
     * Online Payments listing page.
     */
    public function online_payments()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view', 'Fees', 'view');
        $data = [];
        $config = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        $data['gateway_mode'] = is_array($config) && isset($config['mode']) ? $config['mode'] : '';
        $data['page_title']   = 'Online Payments';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/online_payments', $data);
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE TITLES (AJAX) — manage Fees Structure/{Monthly|Yearly}
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all fee titles (Monthly + Yearly).
     */
    public function fetch_fee_titles()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_fee_titles', 'Fees', 'view');
        $structure = $this->_getFeesStructure();
        $titles = [];
        foreach (['Monthly', 'Yearly'] as $type) {
            if (!empty($structure[$type]) && is_array($structure[$type])) {
                foreach (array_keys($structure[$type]) as $title) {
                    $titles[] = ['title' => $title, 'type' => $type];
                }
            }
        }
        $this->json_success(['titles' => $titles]);
    }

    /**
     * GET — Billing readiness for this school's fee heads.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     * Fee generation is ALL-OR-NOTHING under registry mode: one head the
     * registry cannot resolve aborts that student's entire billing and the
     * student is admitted with no demands at all. The failure is loud in the
     * log and, since FEEHEAD-MASK-1, in the admission response — but by then a
     * real student has already been admitted unbillable.
     *
     * This answers the same question BEFORE anyone is admitted: every head the
     * school actually charges, and whether the registry can resolve it. An
     * operator should never have to discover a missing registration from a
     * failed admission.
     *
     * Read-only. Registers nothing, repairs nothing, decides nothing.
     */
    public function fee_head_readiness()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_head_readiness', 'Fees', 'view');

        $this->load->library('Fee_head_resolver', null, 'feeHeadResolver');
        $registered = [];
        foreach ((array) $this->feeHeadResolver->init($this->fs)->listForSchool($this->school_name) as $h) {
            $n = strtolower(trim((string) preg_replace('/s+/', ' ', (string) ($h['name'] ?? ''))));
            if ($n !== '') $registered[$n] = $h;
        }

        // Every head the school charges, taken from its OWN chart — the same
        // names generation will try to resolve.
        $charted = [];
        try {
            foreach ((array) $this->firebase->firestoreQuery('feeStructures',
                [['schoolId', '==', $this->school_name]], null, 'ASC', 500) as $row) {
                $d = is_array($row['data'] ?? null) ? $row['data'] : $row;
                foreach (['feeHeads', 'heads', 'rows'] as $k) {
                    if (!is_array($d[$k] ?? null)) continue;
                    foreach ($d[$k] as $r) {
                        $nm = trim((string) (is_array($r) ? ($r['name'] ?? '') : ''));
                        if ($nm !== '') $charted[strtolower((string) preg_replace('/s+/', ' ', $nm))] = $nm;
                    }
                }
            }
        } catch (Throwable $e) {
            return $this->json_error('Could not read the fee structure: ' . $e->getMessage());
        }

        $missing = [];
        $ready   = [];
        foreach ($charted as $norm => $display) {
            if (isset($registered[$norm])) {
                $ready[] = ['name' => $display, 'category' => (string) ($registered[$norm]['category'] ?? '?')];
            } else {
                $missing[] = $display;
            }
        }
        sort($missing);

        return $this->json_success([
            'charted'    => count($charted),
            'registered' => count($ready),
            'missing'    => $missing,
            'ready'      => empty($missing),
            'message'    => empty($missing)
                ? 'Every fee head in your fee structure is registered. Billing can run.'
                : count($missing) . ' fee head(s) in your fee structure are not registered yet. '
                  . 'Students admitted now would be billed nothing at all until these are added.',
        ]);
    }

    /**
     * POST — Add a new fee title.
     * Params: fee_title, fee_type (Monthly|Yearly)
     */
    public function save_fee_title()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_fee_title', 'Fees', 'edit');
        $feeTitle = trim(ucwords(strtolower($this->_post('fee_title'))));
        $feeType  = $this->_post('fee_type');
        $category = trim((string) $this->_post('fee_category'));

        if ($feeTitle === '') {
            $this->json_error('Fee title is required.');
        }
        if (!in_array($feeType, ['Monthly', 'Yearly'], true)) {
            $this->json_error('Fee type must be Monthly or Yearly.');
        }

        // ── DAY-ONE-1 · THIS SCREEN IS WHERE A FEE HEAD IS BORN ─────────────
        //
        // Until now "Add Fee Head" appended a {name, type} row to
        // feeStructures/{school}_{session}_titles and nothing else. That list is
        // SESSION-SCOPED and carries no financial category, while the fee head
        // REGISTRY is school-scoped, permanent, and is what generation resolves
        // a demand against once registry mode is on.
        //
        // So the school created its fee heads here, the registry never learned
        // about them, and with registry mode active every admission failed
        // closed with ACC_FEEHEAD_UNRESOLVED — students admitted, nobody
        // billable. The only way to populate the registry was a developer
        // running scripts/m3_mint_fee_head_registry.php with a hardcoded
        // mapping. Two parallel notions of "fee head", one of which no operator
        // could reach.
        //
        // The category is REQUIRED here and is never inferred from the name. It
        // answers one question — does the default concession rule apply? — and
        // it is what stops a school-wide concession from discounting transport
        // or hostel charges (invariant I3). Guessing it silently moves money.
        if (!class_exists('Fee_head_resolver')) {
            require_once APPPATH . 'libraries/Fee_head_resolver.php';
        }
        if (!Fee_head_resolver::isAssignableCategory(strtolower($category))) {
            $this->json_error('Choose what kind of charge this is: '
                . implode(', ', Fee_head_resolver::ASSIGNABLE)
                . '. This decides whether a school-wide concession applies to it.');
        }

        // ── A TITLE THAT EXISTS BUT IS UNREGISTERED IS NOT A DUPLICATE ──────
        //
        // This used to refuse outright whenever the title was already in the fee
        // structure. That is right for a genuine duplicate, and wrong for the
        // case the readiness banner exists to report: a school whose chart was
        // built BEFORE the registry existed has titles with no registry identity,
        // and those are exactly the heads it must now register.
        //
        // Refusing them made the screen self-contradictory — it named the missing
        // registrations and then rejected every attempt to add them, leaving no
        // operator path at all. So a duplicate is now judged on the REGISTRY,
        // which is the thing that actually has to be unique: already registered
        // is a duplicate, merely charted is a backfill.
        $structure   = $this->_getFeesStructure();
        $titleExists = isset($structure[$feeType][$feeTitle]);

        $this->load->library('Fee_head_resolver', null, 'feeHeadResolver');
        $already = $this->feeHeadResolver->init($this->fs)
            ->resolveByName($this->school_name, $feeTitle);

        if ($titleExists && !empty($already['ok'])) {
            $this->json_error("Fee head \"{$feeTitle}\" already exists and is registered.");
        }

        // Write a feeStructures doc to register this title globally
        $docId = "{$this->school_name}_{$this->session_year}_titles";
        $existing = $this->firebase->firestoreGet('feeStructures', $docId);
        $heads = is_array($existing) ? ($existing['feeHeads'] ?? []) : [];
        if (!is_array($heads)) $heads = [];
        // REGISTRY FIRST. It holds the permanent identity and is claim-gated, so
        // it is the write that can legitimately refuse (a name already held by a
        // different identity). Appending the session title first and registering
        // second would leave a chart head the registry had rejected — which is
        // precisely the unbillable state this closes.
        $this->load->library('Fee_head_registry_service', null, 'feeHeadRegistry');
        $reg = $this->feeHeadRegistry
            ->init($this->firebase, (string) ($this->admin_id ?? 'admin'))
            ->create($this->school_name, $feeTitle, strtolower($category));

        if (empty($reg['ok'])) {
            $this->json_error((string) ($reg['error'] ?? 'Could not register this fee head.'));
        }

        // Only append the session title when it is genuinely new. Registering an
        // already-charted head must not duplicate its row in the title list.
        if (!$titleExists) {
            $heads[] = ['name' => $feeTitle, 'type' => $feeType, 'feeHeadRef' => $reg['feeHeadId']];
        }
        $this->firebase->firestoreSet('feeStructures', $docId, [
            'schoolId' => $this->school_name,
            'session'  => $this->session_year,
            'feeHeads' => $heads,
            'updatedAt' => date('c'),
        ]);
        // ── WHY THIS DOES NOT ACTIVATE BILLING V2 ITSELF ────────────────────
        //
        // Auto-activating the rollout chain here was implemented and REVERTED.
        // It worked, and it crossed a boundary the codebase defends on purpose:
        // issuing a tenant grant is confined to the superadmin surface and the
        // CLI front door (T3RolloutGrantTest), and estate-wide activation is
        // deliberately never one call away (P6). This is a SCHOOL-FACING
        // endpoint, so granting from here would let a school escalate its own
        // tenant's platform capabilities as a side effect of adding a fee head.
        //
        // Removing the operator from the loop is the right goal, but not at the
        // cost of that boundary — that is a platform decision, not a fee-head
        // decision. What this screen does instead is make the operator's step
        // safe and unmissable: fee_head_readiness() states exactly what is still
        // missing, and the grant surface now REFUSES the registry grant
        // while any charted head is unregistered, so the dangerous ordering is
        // impossible rather than merely discouraged.
        $this->json_success([
            'message'   => "Fee head \"{$feeTitle}\" added as {$feeType} ({$category}).",
            'feeHeadId' => $reg['feeHeadId'],
        ]);
    }

    /**
     * POST — Delete a fee title.
     * Params: fee_title, fee_type (Monthly|Yearly)
     */
    public function delete_fee_title()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_fee_title', 'Fees', 'manage');
        $feeTitle = $this->_post('fee_title');
        $feeType  = $this->_post('fee_type');

        if ($feeTitle === '' || $feeType === '') {
            $this->json_error('Fee title and type are required.');
        }
        if (!in_array($feeType, ['Monthly', 'Yearly'], true)) {
            $this->json_error('Fee type must be Monthly or Yearly.');
        }

        // Remove from Firestore feeStructures titles doc
        $docId = "{$this->school_name}_{$this->session_year}_titles";
        $existing = $this->firebase->firestoreGet('feeStructures', $docId);
        $heads = is_array($existing) ? ($existing['feeHeads'] ?? []) : [];
        if (!is_array($heads)) $heads = [];
        $heads = array_values(array_filter($heads, function ($h) use ($feeTitle, $feeType) {
            return !(($h['name'] ?? '') === $feeTitle && ($h['type'] ?? '') === $feeType);
        }));
        $this->firebase->firestoreSet('feeStructures', $docId, [
            'schoolId' => $this->school_name,
            'session'  => $this->session_year,
            'feeHeads' => $heads,
            'updatedAt' => date('c'),
        ]);
        $this->json_success(['message' => "Fee title \"{$feeTitle}\" deleted."]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  CATEGORY MANAGEMENT (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all fee categories.
     */
    public function fetch_categories()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_categories', 'Fees', 'view');
        $rows = $this->firebase->firestoreQuery('feeCategories', [
            ['schoolId', '==', $this->school_name],
        ]);
        $categories = [];
        foreach ((array) $rows as $row) {
            $cat = $row['data'] ?? $row;
            if (!is_array($cat)) continue;
            $cat['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            $categories[] = $cat;
        }
        // Sort by sort_order
        usort($categories, function ($a, $b) {
            return ((int)($a['sort_order'] ?? 999)) - ((int)($b['sort_order'] ?? 999));
        });
        $this->json_success(['categories' => $categories]);
    }

    /**
     * POST — Create or update a fee category.
     * Params: id?, name, description, type, fee_titles (comma-sep), sort_order
     */
    public function save_category()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_category', 'Fees', 'edit');
        // Accept both naming conventions (category_name OR name, etc.)
        $name        = $this->_post('category_name') ?: $this->_post('name');
        $description = $this->_post('description');
        $type        = $this->_post('category_type') ?: $this->_post('type');
        $sortOrder   = (int)$this->input->post('sort_order');
        $catId       = $this->_post('category_id') ?: $this->_post('id');

        if ($name === '') {
            $this->json_error('Category name is required.');
        }

        $validTypes = ['Academic', 'Transport', 'Extra-curricular', 'Other'];
        if (!in_array($type, $validTypes, true)) {
            $this->json_error('Invalid category type.');
        }

        // Accept fee_titles as array (from FormData) or comma-sep string
        $feeTitles = $this->input->post('fee_titles[]') ?: $this->input->post('fee_titles');
        $titlesArray = [];
        if (is_array($feeTitles)) {
            $titlesArray = array_values(array_filter(array_map('trim', $feeTitles)));
        } elseif (is_string($feeTitles) && trim($feeTitles) !== '') {
            $titlesArray = array_values(array_filter(array_map('trim', explode(',', $feeTitles))));
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            // BUG (2026-05-28): readers (Fees::_resolveFeeCategory, discounts
            // view) key the category display name off `name`, but this writer
            // only emitted `category_name` — so a saved category would resolve
            // to no name (always falling back to keyword-guessed groups). Emit
            // BOTH: canonical `name` for readers + `category_name` for back-compat.
            'name'          => $name,
            'category_name' => $name,
            'description'   => $description,
            'category_type' => $type,
            'fee_titles'    => $titlesArray,
            'sort_order'    => $sortOrder,
            'status'        => 'active',
        ];

        $data['schoolId'] = $this->school_name;

        if (!empty($catId)) {
            // Update existing
            $catId = $this->safe_path_segment($catId, 'category_id');
            $data['updated_at'] = $now;
            $this->firebase->firestoreSet('feeCategories', "{$this->school_name}_{$catId}", $data, true);
            $this->json_success(['message' => 'Category updated successfully.', 'id' => $catId]);
        } else {
            // Create new
            $data['created_at'] = $now;
            $newId = uniqid('cat_');
            $this->firebase->firestoreSet('feeCategories', "{$this->school_name}_{$newId}", $data);
            $this->json_success(['message' => 'Category created successfully.', 'id' => $newId]);
        }
    }

    /**
     * POST — Delete a fee category.
     * Params: category_id
     */
    public function delete_category()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_category', 'Fees', 'manage');
        $catId = $this->safe_path_segment(trim($this->input->post('category_id') ?? ''), 'category_id');

        // Verify it exists
        $existing = $this->firebase->firestoreGet('feeCategories', "{$this->school_name}_{$catId}");
        if (empty($existing)) {
            $this->json_error('Category not found.');
        }

        $this->firebase->firestoreDelete('feeCategories', "{$this->school_name}_{$catId}");
        $this->json_success(['message' => 'Category deleted successfully.']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  DISCOUNT POLICIES (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all discount policies.
     */
    public function fetch_discounts()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_discounts', 'Fees', 'view');
        $rows = $this->firebase->firestoreQuery('feeDiscountPolicies', [
            ['schoolId', '==', $this->school_name],
        ]);
        $discounts = [];
        foreach ((array) $rows as $row) {
            $disc = $row['data'] ?? $row;
            if (!is_array($disc)) continue;
            $disc['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            $discounts[] = $disc;
        }
        $this->json_success(['discounts' => $discounts]);
    }

    /**
     * POST — Create or update a discount policy.
     * Params: id?, name, type, value, criteria, applicable_categories, applicable_titles, max_discount, active
     */
    public function save_discount()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_discount', 'Fees', 'edit');

        // SCHEMA NOTE — the JS form posts these field names:
        //   policy_name, discount_type, value, criteria, max_cap, is_active,
        //   categories[], fee_titles[]
        // Older callers (legacy) post:
        //   name, type, value, criteria, max_discount, active,
        //   applicable_categories, applicable_titles
        // We accept BOTH so neither caller breaks.
        $name       = $this->_post('policy_name');
        if ($name === '') $name = $this->_post('name');

        $type       = $this->_post('discount_type');
        if ($type === '') $type = $this->_post('type');

        $value      = floatval($this->input->post('value'));
        $criteria   = $this->_post('criteria');

        $maxRaw     = $this->input->post('max_cap');
        if ($maxRaw === null || $maxRaw === '') $maxRaw = $this->input->post('max_discount');
        $maxDisc    = floatval($maxRaw);

        $activeRaw  = $this->input->post('is_active');
        if ($activeRaw === null) $activeRaw = $this->input->post('active');
        $active     = !($activeRaw === 'false' || $activeRaw === '0' || $activeRaw === 0 || $activeRaw === false);

        $discId     = $this->_post('id');

        if ($name === '') {
            $this->json_error('Discount policy name is required.');
        }
        if (!in_array($type, ['percentage', 'fixed'], true)) {
            $this->json_error('Discount type must be "percentage" or "fixed".');
        }
        if ($value <= 0) {
            $this->json_error('Discount value must be greater than zero.');
        }

        $validCriteria = ['sibling', 'early_bird', 'merit', 'staff_ward', 'custom'];
        if (!in_array($criteria, $validCriteria, true)) {
            $this->json_error('Invalid criteria. Must be one of: ' . implode(', ', $validCriteria));
        }

        // Categories + fee_titles can arrive as either:
        //   - array (jQuery $.ajax with traditional:true sends categories[])
        //   - comma-separated string (legacy)
        $appCats = $this->input->post('categories');
        if ($appCats === null) $appCats = $this->input->post('applicable_categories');
        $catsArray = [];
        if (is_array($appCats)) {
            $catsArray = array_values(array_filter(array_map('trim', $appCats)));
        } elseif (is_string($appCats) && $appCats !== '') {
            $catsArray = array_values(array_filter(array_map('trim', explode(',', $appCats))));
        }

        $appTitles = $this->input->post('fee_titles');
        if ($appTitles === null) $appTitles = $this->input->post('applicable_titles');
        $titlesArray = [];
        if (is_array($appTitles)) {
            $titlesArray = array_values(array_filter(array_map('trim', $appTitles)));
        } elseif (is_string($appTitles) && $appTitles !== '') {
            $titlesArray = array_values(array_filter(array_map('trim', explode(',', $appTitles))));
        }

        $now = date('Y-m-d H:i:s');

        // Dual-emit: write BOTH naming schemes so old AND new readers
        // (list renderer, edit prefill, apply_discount, scholarship audit)
        // all see the same data without per-reader migration.
        $data = [
            // New schema (matches JS list renderer + edit form)
            'policy_name'           => $name,
            'discount_type'         => $type,
            'max_cap'               => $maxDisc,
            'is_active'             => $active,
            'categories'            => $catsArray,
            'fee_titles'            => $titlesArray,
            // Legacy schema (older readers / apply_discount + reports)
            'name'                  => $name,
            'type'                  => $type,
            'max_discount'          => $maxDisc,
            'active'                => $active,
            'applicable_categories' => $catsArray,
            'applicable_titles'     => $titlesArray,
            // Common
            'value'                 => $value,
            'criteria'              => $criteria,
        ];

        $data['schoolId'] = $this->school_name;

        if (!empty($discId)) {
            $discId = $this->safe_path_segment($discId, 'discount_id');
            $data['updated_at'] = $now;
            $this->firebase->firestoreSet('feeDiscountPolicies', "{$this->school_name}_{$discId}", $data, true);
            $this->json_success(['message' => 'Discount policy updated successfully.', 'id' => $discId]);
        } else {
            $data['created_at'] = $now;
            $newId = uniqid('disc_');
            $this->firebase->firestoreSet('feeDiscountPolicies', "{$this->school_name}_{$newId}", $data);
            $this->json_success(['message' => 'Discount policy created successfully.', 'id' => $newId]);
        }
    }

    /**
     * POST — Delete a discount policy.
     * Params: discount_id
     */
    public function delete_discount()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_discount', 'Fees', 'manage');
        $discId = $this->safe_path_segment(trim($this->input->post('discount_id') ?? ''), 'discount_id');

        $existing = $this->firebase->firestoreGet('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        if (empty($existing)) {
            $this->json_error('Discount policy not found.');
        }

        $this->firebase->firestoreDelete('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        $this->json_success(['message' => 'Discount policy deleted successfully.']);
    }

    /**
     * POST — Fetch students eligible for a specific discount based on criteria.
     * Params: discount_id
     *
     * Criteria logic:
     *   sibling    — students sharing the same parent/guardian (same parent key in Users/Parents)
     *   early_bird — all students (manual selection expected)
     *   merit      — all students (manual selection expected)
     *   staff_ward — students whose parent is in the Teachers node
     *   custom     — all students (manual selection expected)
     */
    public function fetch_eligible_students()
    {
        $this->_require_role(self::FINANCE_ROLES, 'fetch_eligible', 'Fees', 'edit');
        $discId = $this->safe_path_segment(trim($this->input->post('discount_id') ?? ''), 'discount_id');

        $policy = $this->firebase->firestoreGet('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        if (!is_array($policy)) {
            $this->json_error('Discount policy not found.');
        }

        $criteria = isset($policy['criteria']) ? $policy['criteria'] : 'custom';
        $schoolFs = $this->school_name;

        // Load students from Firestore — SESSION-SCOPED so the discount
        // eligibility picker never bleeds in students from another academic
        // session (student `session` singular is canonical).
        $studentRows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $this->session_year],
        ]);
        $students = [];
        foreach ((array) $studentRows as $row) {
            $d = $row['data'] ?? $row;
            if (!is_array($d)) continue;
            $cls = trim((string) ($d['className'] ?? ''));
            $sec = trim((string) ($d['section'] ?? ''));
            if (stripos($cls, 'Class ') !== 0) $cls = 'Class ' . $cls;
            if (stripos($sec, 'Section ') !== 0) $sec = 'Section ' . $sec;
            $students[] = [
                'user_id' => (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? '')),
                'name'    => (string) ($d['name'] ?? $d['studentName'] ?? ''),
                'class'   => $cls,
                'section' => $sec,
            ];
        }

        // For sibling criteria, group by parent and only include those with 2+ children
        // ══ WEAK-ELIG-1 · NAMES AND PHONE NUMBERS NO LONGER DECIDE MONEY ══════
        //
        // WHAT THIS REPLACED, and why it mattered:
        //
        //   sibling    — grouped students by FATHER-NAME STRING EQUALITY. Two
        //                unrelated families whose fathers are both "Ramesh
        //                Kumar" became siblings; real siblings whose records
        //                spell the father differently ("R. Kumar") did not.
        //   staff_ward — matched a parent's phone against ANY staff phone. A
        //                recycled number, a shared family handset, or an
        //                employee who is a neighbour rather than a parent all
        //                qualified a child.
        //
        // This is NOT cosmetic. The list produced here is applied as a bulk
        // discount into `studentDiscounts`, and that collection is a real
        // financial authority at the COLLECTION layer — `Fees::fetch_months`
        // computes `netDue = max(0, totalRemaining - discAmt)` from
        // `onDemandDiscount`, and FeeCollectionService uses the same field as
        // the authorised cap on what a clerk may discount on a receipt. So a
        // coincidence of names or digits could reduce what a family actually
        // paid at the counter.
        //
        // ZenX now has verified relationships: `studentRelationships`, written
        // only after a human resolved an SR Number or a Staff ID against THIS
        // tenant and confirmed the person. That is the canonical evidence, and
        // it is the only evidence used here now.
        //
        // FAILS CLOSED. If the relationships cannot be read, the eligible list
        // is EMPTY rather than falling back to the old guess — an empty list
        // discounts nobody, while a guessed list discounts the wrong families.
        if ($criteria === 'sibling' || $criteria === 'staff_ward') {
            $kind = ($criteria === 'sibling') ? 'sibling' : 'staff_ward';

            $eligibleIds = [];
            $relOk = false;
            try {
                $relRows = $this->firebase->firestoreQuery('studentRelationships', [
                    ['schoolId', '==', $schoolFs],
                    ['kind',     '==', $kind],
                    ['status',   '==', 'active'],
                ]);
                $relOk = true;
                foreach ((array) $relRows as $row) {
                    $d = $row['data'] ?? $row;
                    if (!is_array($d)) continue;
                    $sid = trim((string) ($d['studentId'] ?? ''));
                    if ($sid !== '') $eligibleIds[$sid] = true;
                }
            } catch (\Throwable $e) {
                log_message('error', 'WEAK_ELIG_1 could not read studentRelationships for '
                    . 'school=[' . $schoolFs . '] kind=[' . $kind . '] — returning NO eligible '
                    . 'students rather than guessing from names or phone numbers: ' . $e->getMessage());
            }

            if (!$relOk) {
                $students = [];
            } else {
                if ($eligibleIds === []) {
                    log_message('error', 'WEAK_ELIG_1 school=[' . $schoolFs . '] kind=[' . $kind
                        . '] — no VERIFIED relationships on record, so no student is eligible. '
                        . 'Link them from the student record (SR Number for a sibling, Staff ID '
                        . 'for an employee ward); name and phone matching no longer qualify anyone.');
                }
                $students = array_values(array_filter($students, function ($s) use ($eligibleIds) {
                    return isset($eligibleIds[$s['user_id']]);
                }));
            }
        }
        // early_bird, merit and custom return the full list for MANUAL selection.
        // They are left untouched deliberately: none of them infers a family
        // relationship, so an operator picking students by hand is exactly what
        // they are for. Only the two relationship-based criteria carried an
        // eligibility claim the system could get wrong on its own.

        $this->json_success([
            'students' => $students,
            'criteria' => $criteria,
            'policy'   => [
                'name'  => isset($policy['name']) ? $policy['name'] : '',
                'type'  => isset($policy['type']) ? $policy['type'] : '',
                'value' => isset($policy['value']) ? $policy['value'] : 0,
            ],
        ]);
    }

    /**
     * POST — Apply a discount to selected students.
     * Params: discount_id, student_ids[] (array of user IDs with class/section info as JSON)
     *
     * Each student_ids[] element is JSON: {"user_id":"...","class":"Class 9th","section":"Section A"}
     */
    /**
     * The ONE collection-layer adjustment authority, shared with the fee
     * counter's two writers in Fees.php. Held per request so the outstanding
     * memo inside it survives a bulk apply over many students.
     */
    private function _adjustmentPolicy(): Collection_adjustment_policy
    {
        if ($this->_adjPolicy === null) {
            if (!class_exists('Collection_adjustment_policy')) {
                require_once APPPATH . 'libraries/Collection_adjustment_policy.php';
            }
            $this->_adjPolicy = (new Collection_adjustment_policy())->init(
                $this->firebase, (string) $this->school_name,
                (string) $this->session_year, (string) ($this->admin_id ?? 'system')
            );
        }
        return $this->_adjPolicy;
    }

    /** @var Collection_adjustment_policy|null */
    private $_adjPolicy = null;

    public function apply_discount()
    {
        $this->_require_role(self::FINANCE_ROLES, 'apply_discount', 'Fees', 'edit');
        $discId     = $this->safe_path_segment(trim($this->input->post('discount_id') ?? ''), 'discount_id');
        $studentRaw = $this->input->post('student_ids');
        // Phase 17: optional expiry. If supplied, fetch_fee_details will
        // treat the discount as 0 once today > validUntil. Empty string
        // means "no expiry" (legacy behaviour: persists forever).
        $validUntil = trim($this->input->post('valid_until') ?? '');
        if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
            $this->json_error('valid_until must be YYYY-MM-DD or empty.');
        }

        if (empty($studentRaw) || !is_array($studentRaw)) {
            $this->json_error('No students selected.');
        }

        $policy = $this->firebase->firestoreGet('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        if (!is_array($policy)) {
            $this->json_error('Discount policy not found.');
        }

        $discType  = isset($policy['type']) ? $policy['type'] : 'fixed';
        $discValue = isset($policy['value']) ? floatval($policy['value']) : 0;
        $discName  = isset($policy['name']) ? $policy['name'] : 'Discount';
        $maxDisc   = isset($policy['max_discount']) ? floatval($policy['max_discount']) : 0;
        $applied   = 0;
        $errors    = [];

        foreach ($studentRaw as $entry) {
            $student = is_string($entry) ? json_decode($entry, true) : $entry;
            if (!is_array($student) || empty($student['user_id'])) {
                continue;
            }

            $userId  = $student['user_id'];
            $class   = isset($student['class']) ? $student['class'] : '';
            $section = isset($student['section']) ? $student['section'] : '';

            if ($class === '' || $section === '') {
                $errors[] = "Missing class/section for student {$userId}";
                continue;
            }

            // Sanitize path segments from user-posted JSON
            list($class, $section) = $this->_normalizeClassSection($class, $section);
            $safeUserId = $this->safe_path_segment($userId, 'student_id');

            // Read existing discount data for this student from Firestore
            $discDoc = $this->firebase->firestoreGet('studentDiscounts', "{$this->school_name}_{$safeUserId}");
            $existing = is_array($discDoc) ? $discDoc : [];
            $existingAmt = floatval($existing['totalDiscount'] ?? 0);

            // Calculate discount amount
            $discountAmount = $discValue;
            if ($discType === 'percentage') {
                // For percentage, read total fee from Firestore feeStructures
                // ── ADJ-GOV-5 · THE PERCENTAGE BASE NEVER RESOLVED ──────────
                //
                // This built the key by STRIPPING the "Class " and "Section "
                // prefixes, producing `10th_A`. Every other reader of
                // feeStructures — Fee_firestore_txn included — uses the
                // canonical `{schoolId}_{session}_{className}_{section}` with
                // the FULL names, and that is how the documents are actually
                // stored (`SCH_..._2026-27_Class 10th_Section A`).
                //
                // So the lookup ALWAYS missed, `$heads` was always empty, the
                // percentage base was always 0, and every percentage-type rule
                // discount silently computed ZERO — while the endpoint still
                // reported "Discount applied to N student(s)". An operator
                // granting a class a 10% discount got nothing, and was told it
                // had worked. PROVEN at runtime before this fix.
                //
                // `$class`/`$section` have already been through
                // _normalizeClassSection(), so they are canonical here.
                $csKey = $class . '_' . $section;
                $feeStructDoc = $this->firebase->firestoreGet('feeStructures', "{$this->school_name}_{$this->session_year}_{$csKey}");
                // ── P3D-F3 · SECOND OCCURRENCE (fixed 2026-08-19) ────────────
                // The identical defect as the scholarship base below: every head
                // summed regardless of frequency, so an annual head inflates a
                // PER-PERIOD percentage rate. Found by grepping for the pattern
                // after fixing the first — a defect that appears twice will
                // reappear a third time unless both are closed together.
                //
                // Same tested helper, same `academic` default scope, so the two
                // percentage bases cannot drift apart. Behaviour change stated:
                // a newly auto-calculated percentage discount is SMALLER,
                // because the old figure mixed an annual charge into a monthly
                // rate. Only fires when the operator leaves the amount blank.
                require_once APPPATH . 'libraries/Scholarship_allocator.php';
                $heads = is_array($feeStructDoc) ? (array) ($feeStructDoc['feeHeads'] ?? []) : [];
                $discountAmount = round(
                    Scholarship_allocator::percentageBase($heads, 'academic') * $discValue / 100,
                    2
                );
            }

            // Apply max_discount cap if set
            if ($maxDisc > 0 && $discountAmount > $maxDisc) {
                $discountAmount = $maxDisc;
            }

            // ── ADJ-GOV-2 · THE SAME GATE THE FEE COUNTER PASSES ────────────
            //
            // This writes `onDemandDiscount`, which governs what the counter
            // ASKS FOR and caps what a clerk may waive on a receipt. It is the
            // same money the counter's own grant touches, and until now this
            // door had no outstanding-balance invariant and no reason — capped
            // only by the policy's optional `max_discount`, which defaults to
            // 0 meaning NO CAP. Applied in bulk, that could drive netDue to
            // zero for an entire selection of students in one click.
            //
            // `max_discount` is passed as a TIGHTENING override: it may lower
            // the ceiling, never raise it above what the student owes.
            //
            // A refusal skips THIS student and is reported — one ineligible
            // student must not abort a legitimate bulk apply, but the skip is
            // never silent.
            $adjRefusal = $this->_adjustmentPolicy()->refusal(
                $safeUserId,
                (float) $discountAmount,
                'Rule discount applied: ' . $discName,
                $maxDisc > 0 ? $maxDisc : null
            );
            if ($adjRefusal !== null) {
                $errors[] = "{$userId}: {$adjRefusal}";
                continue;
            }

            // ── SD-2 · totalDiscount IS DERIVED, NOT ACCUMULATED ────────────
            //
            // This wrote `existing total + this discount`, so the figure grew
            // on every rule application and never came down when the governing
            // `onDemandDiscount` was reduced or consumed. The school-wide
            // discount report sums this field, so it over-reported without
            // limit. OBSERVED on the persisted document: onDemandDiscount 60,
            // scholarshipDiscount 0, totalDiscount 2,740.
            //
            // `onDemandDiscount` governs the money; `totalDiscount` is the
            // REPORTED sum of its parts, exactly as the two counter doors and
            // the receipt path now write it. Stored history is left alone —
            // only new writes are coherent — so no settled record is restated.
            $__scholNow = (float) ($existing['scholarshipDiscount']
                ?? $existing['ScholarshipDiscount'] ?? 0);
            $newTotal = round($discountAmount + $__scholNow, 2);

            // Write to student's Discount node - maintain history
            $historyKey = $discId . '_' . date('Ymd_His');
            $updateData = [
                // 2026-05-29: normalized PascalCase -> camelCase to match the
                // canonical key used by every reader (Sis::student_profile,
                // Fees::submit_discount, Fees::set_student_discount,
                // FeeCollectionService) and by submit/set writers in Fees.php.
                'onDemandDiscount' => $discountAmount,
                'totalDiscount'    => $newTotal,
                'last_policy_id'   => $discId,
                'last_policy_name' => $discName,
                'applied_at'       => date('Y-m-d H:i:s'),
                'valid_until'      => $validUntil,   // '' = no expiry
                'validUntil'       => $validUntil,   // dual-emit camelCase
            ];

            // Store in Applied sub-node for audit trail
            $appliedData = [
                'policy_id'   => $discId,
                'policy_name' => $discName,
                'amount'      => $discountAmount,
                'type'        => $discType,
                'applied_at'  => date('Y-m-d H:i:s'),
                'applied_by'  => $this->admin_name,
            ];

            // Write discount summary to Firestore studentDiscounts
            $discountDoc = array_merge($updateData, [
                'schoolId'  => $this->school_name,
                'studentId' => $safeUserId,
                'applied'   => array_merge(
                    (array) ($existing['applied'] ?? []),
                    [$historyKey => $appliedData]
                ),
            ]);
            // SESSION STAMP. `studentDiscounts` is keyed {schoolId}_{studentId}
            // with NO session component, so without this a rule discount
            // applied in one session silently keeps reducing collection in
            // every session after a rollover. Stamping does not change any
            // existing record — unstamped documents stay legacy and keep
            // working — it closes the leak for everything written from now on.
            $discountDoc = array_merge(
                $discountDoc,
                $this->_adjustmentPolicy()->stamp('Rule discount applied: ' . $discName, 'fee_management_rule')
            );
            $this->firebase->firestoreSet('studentDiscounts', "{$this->school_name}_{$safeUserId}", $discountDoc, true);

            // SD-3. The only trail this had was log_message('info', …), and
            // production runs log_threshold=1 (errors only) — so the discount
            // audit trail wrote NOTHING. feeAuditLogs is the canonical trail
            // that demands, receipts, payments and refunds already use.
            $this->_adjustmentPolicy()->audit(
                $safeUserId, $existing, $discountDoc,
                'Rule discount applied: ' . $discName, 'fee_management_rule'
            );
            $applied++;

            // Mirror discount summary + history to Firestore `studentDiscounts`.
            try {
                $scholAmt = is_array($existing) ? (float) ($existing['ScholarshipDiscount'] ?? 0) : 0;
                $dOk = $this->fsSync->syncDiscount($safeUserId, [
                    'onDemandDiscount'    => $discountAmount,
                    'scholarshipDiscount' => $scholAmt,
                    'totalDiscount'       => $newTotal,
                    'lastPolicyId'        => $discId,
                    'lastPolicyName'      => $discName,
                    'appliedAt'           => $updateData['applied_at'],
                    'validUntil'          => $validUntil,   // Phase 17 — '' = no expiry
                    'applied'             => [
                        'policy_id'   => $discId,
                        'policy_name' => $discName,
                        'amount'      => $discountAmount,
                        'type'        => $discType,
                        'applied_at'  => $appliedData['applied_at'],
                        'applied_by'  => $appliedData['applied_by'],
                        'history_key' => $historyKey,
                        'valid_until' => $validUntil,
                    ],
                ], [
                    'studentName' => $student['name'] ?? $student['Name'] ?? '',
                    'className'   => $class,
                    'section'     => $section,
                ]);
                if (!$dOk) {
                    $this->fsSync->queueForRetry('studentDiscount', [
                        'studentId' => $safeUserId,
                        'summary'   => [
                            'onDemandDiscount' => $discountAmount,
                            'totalDiscount'    => $newTotal,
                            'lastPolicyId'     => $discId,
                        ],
                        'context'   => ['className' => $class, 'section' => $section],
                    ]);
                }
            } catch (Exception $e) {
                log_message('error', "apply_discount: studentDiscount sync failed for {$safeUserId}: " . $e->getMessage());
            }

            // Mirror defaulter status to Firestore per-student so the parent
            // app's balance view stays in sync with each discount application.
            try {
                $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($safeUserId);
                $studentName = $student['name'] ?? $student['Name'] ?? '';
                $defOk = $this->fsSync->syncDefaulterStatus(
                    $safeUserId, $defaulterStatus, $studentName, $class, $section
                );
                if (!$defOk) {
                    $this->fsSync->queueForRetry('feeDefaulter', [
                        'studentId'   => $safeUserId,
                        'status'      => $defaulterStatus,
                        'studentName' => $studentName,
                        'className'   => $class,
                        'section'     => $section,
                    ]);
                }
            } catch (Exception $e) {
                log_message('error', "apply_discount: defaulter sync failed for {$safeUserId}: " . $e->getMessage());
            }
        }

        $msg = "Discount applied to {$applied} student(s).";
        if (!empty($errors)) {
            $msg .= ' Errors: ' . implode('; ', $errors);
        }

        $this->json_success(['message' => $msg, 'applied' => $applied]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  SCHOLARSHIP MANAGEMENT (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all scholarships.
     */
    public function fetch_scholarships()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_scholarships', 'Fees', 'view');
        $scholRows = $this->firebase->firestoreQuery('feeScholarships', [
            ['schoolId', '==', $this->school_name],
        ]);
        $awardRows = $this->firebase->firestoreQuery('scholarshipAwards', [
            ['schoolId', '==', $this->school_name],
            ['status',   '==', 'active'],
        ]);

        // Pre-compute award counts per scholarship
        $awardCounts = [];
        foreach ((array) $awardRows as $row) {
            $d = $row['data'] ?? $row;
            $sid = (string) ($d['scholarshipId'] ?? $d['scholarship_id'] ?? '');
            if ($sid !== '') {
                $awardCounts[$sid] = ($awardCounts[$sid] ?? 0) + 1;
            }
        }

        $scholarships = [];
        foreach ((array) $scholRows as $row) {
            $schol = $row['data'] ?? $row;
            if (!is_array($schol)) continue;
            $id = $this->_stripSchoolPrefix($row['id'] ?? '');
            $schol['id'] = $id;
            $schol['current_awards'] = $awardCounts[$id] ?? 0;
            $scholarships[] = $schol;
        }
        $this->json_success(['scholarships' => $scholarships]);
    }

    /**
     * POST — Create or update a scholarship.
     * Params: id?, name, type, value, criteria, max_beneficiaries, active
     */
    public function save_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_scholarship', 'Fees', 'edit');
        $name    = trim($this->input->post('name'));
        $type    = trim($this->input->post('type'));
        $value   = floatval($this->input->post('value'));
        $criteria       = trim($this->input->post('criteria'));
        $maxBeneficiary = (int)$this->input->post('max_beneficiaries');
        $active  = ($this->input->post('active') === 'false' || $this->input->post('active') === '0')
                   ? false : true;
        $scholId = trim($this->input->post('id'));

        if ($name === '') {
            $this->json_error('Scholarship name is required.');
        }
        if (!in_array($type, ['percentage', 'fixed'], true)) {
            $this->json_error('Scholarship type must be "percentage" or "fixed".');
        }
        if ($value <= 0) {
            $this->json_error('Scholarship value must be greater than zero.');
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            'name'              => $name,
            'type'              => $type,
            'value'             => $value,
            'criteria'          => $criteria,
            'max_beneficiaries' => $maxBeneficiary,
            'academic_year'     => $this->session_year,
            'active'            => $active,
        ];

        $data['schoolId'] = $this->school_name;

        if (!empty($scholId)) {
            $scholId = $this->safe_path_segment($scholId, 'scholarship_id');
            $data['updated_at'] = $now;
            $this->firebase->firestoreSet('feeScholarships', "{$this->school_name}_{$scholId}", $data, true);
            $this->json_success(['message' => 'Scholarship updated successfully.', 'id' => $scholId]);
        } else {
            $data['created_at'] = $now;
            $newId = uniqid('schol_');
            $this->firebase->firestoreSet('feeScholarships', "{$this->school_name}_{$newId}", $data);
            $this->json_success(['message' => 'Scholarship created successfully.', 'id' => $newId]);
        }
    }

    /**
     * POST — Delete a scholarship.
     * Params: scholarship_id
     */
    public function delete_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_scholarship', 'Fees', 'manage');
        $scholId = $this->safe_path_segment(trim($this->input->post('scholarship_id') ?? ''), 'scholarship_id');

        $existing = $this->firebase->firestoreGet('feeScholarships', "{$this->school_name}_{$scholId}");
        if (empty($existing)) {
            $this->json_error('Scholarship not found.');
        }

        // Check for active awards
        $activeAwards = $this->firebase->firestoreQuery('scholarshipAwards', [
            ['schoolId',       '==', $this->school_name],
            ['scholarshipId',  '==', $scholId],
            ['status',         '==', 'active'],
        ], null, 'ASC', 1);
        if (!empty($activeAwards)) {
            $this->json_error('Cannot delete scholarship with active awards. Revoke all awards first.');
        }

        $this->firebase->firestoreDelete('feeScholarships', "{$this->school_name}_{$scholId}");
        $this->json_success(['message' => 'Scholarship deleted successfully.']);
    }

    /**
     * GET — Fetch scholarship awards, optionally filtered by scholarship_id.
     * Query param: scholarship_id (optional)
     */
    public function fetch_awards()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_awards', 'Fees', 'view');
        $scholId = trim($this->input->get('scholarship_id'));

        $conditions = [['schoolId', '==', $this->school_name]];
        if ($scholId !== '') {
            $conditions[] = ['scholarshipId', '==', $scholId];
        }
        $rows = $this->firebase->firestoreQuery('scholarshipAwards', $conditions);
        $awards = [];
        foreach ((array) $rows as $row) {
            $award = $row['data'] ?? $row;
            if (!is_array($award)) continue;
            $award['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            // Normalize for view compatibility
            $award['scholarship_id'] = (string) ($award['scholarshipId'] ?? $award['scholarship_id'] ?? '');
            $award['student_id']     = (string) ($award['studentId']     ?? $award['student_id']     ?? '');
            $award['student_name']   = (string) ($award['studentName']   ?? $award['student_name']   ?? '');
            $awards[] = $award;
        }

        $this->json_success(['awards' => $awards]);
    }

    /**
     * POST — Award a scholarship to a student.
     * Params: scholarship_id, student_id, student_name, class, section, amount
     */
    public function award_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'award_scholarship', 'Fees', 'edit');

        // ── P9 · SCHOLARSHIP WRITER CLOSURE (prepared 2026-08-19) ───────────
        //
        // `scholarshipAwards` is one of three legacy discount authorities being
        // retired in favour of `studentEntitlements`. This is the CREATION gate:
        // with the flag on, no new legacy award can be minted, so the surface
        // being retired cannot grow while the replacement is finished.
        //
        // CREATION ONLY. Revocation and every read path stay open, deliberately:
        // retiring a writer must never TRAP existing data. A school must still
        // be able to close an award it already granted, and both apps must still
        // be able to display one. A closure that strands live records is not a
        // retirement, it is a data-loss incident with a flag on it.
        //
        // Refuses LOUDLY with a message naming the replacement — a silent
        // no-op would let an operator believe a scholarship had been granted.
        //
        // DEFAULT FALSE, and its prerequisites are currently UNMET — most
        // importantly the Parent app still reads this collection directly, so
        // closing the writer before that migrates would degrade the app to ₹0
        // rather than failing loudly. See fees_exemption_v2_flags.php.
        $this->config->load('fees_exemption_v2_flags', true);
        if ((bool) $this->config->item('SCHOLARSHIP_AWARDS_RETIRED', 'fees_exemption_v2_flags')) {
            log_message('error', 'ACC_SCHOLARSHIP_WRITE_REFUSED school=[' . $this->school_name
                . '] admin=[' . ($this->admin_id ?? '?') . '] — scholarshipAwards is retired.');
            return $this->json_error(
                'Scholarship awards have been retired. Record this as a concession on the '
                . 'student\'s record instead, where it is effective-dated, audited and applied '
                . 'to the fee demands automatically. Existing awards can still be viewed and revoked.'
            );
        }
        $scholId     = $this->safe_path_segment(trim($this->input->post('scholarship_id') ?? ''), 'scholarship_id');
        $studentId   = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');
        $studentName = trim($this->input->post('student_name'));
        $class       = trim($this->input->post('class'));
        $section     = trim($this->input->post('section'));
        $amount      = floatval($this->input->post('amount'));

        // Validate scholarship exists and is active
        $scholarship = $this->firebase->firestoreGet('feeScholarships', "{$this->school_name}_{$scholId}");
        if (!is_array($scholarship)) {
            $this->json_error('Scholarship not found.');
        }
        if (isset($scholarship['active']) && $scholarship['active'] === false) {
            $this->json_error('This scholarship is not active.');
        }

        // Check max beneficiaries
        $maxBen = isset($scholarship['max_beneficiaries']) ? (int)$scholarship['max_beneficiaries'] : 0;
        if ($maxBen > 0) {
            $activeAwards = $this->firebase->firestoreQuery('scholarshipAwards', [
                ['schoolId',      '==', $this->school_name],
                ['scholarshipId', '==', $scholId],
                ['status',        '==', 'active'],
            ]);
            $currentCount = count((array) $activeAwards);
            if ($currentCount >= $maxBen) {
                $this->json_error("Maximum beneficiaries ({$maxBen}) reached for this scholarship.");
            }
        }

        // Calculate amount if not provided (use scholarship value)
        if ($amount <= 0) {
            $scholType  = isset($scholarship['type']) ? $scholarship['type'] : 'fixed';
            $scholValue = isset($scholarship['value']) ? floatval($scholarship['value']) : 0;

            if ($scholType === 'percentage') {
                // Get total fees from Firestore feeStructures
                list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);
                $csKey = str_ireplace('Class ', '', $classNode) . '_' . str_ireplace('Section ', '', $sectionNode);
                $feeStructDoc = $this->firebase->firestoreGet('feeStructures', "{$this->school_name}_{$this->session_year}_{$csKey}");
                // ── P3D-F3 · THE PERCENTAGE BASE (fixed 2026-08-19) ──────────
                //
                // THE DEFECT: this summed EVERY head regardless of frequency,
                // mixing monthly heads with the annual one. On the real Class
                // 9th chart that is 500 + 50 + 200 + 1000(annual) = 1750, so a
                // 10% scholarship computed **₹175**.
                //
                // A percentage award is PER PERIOD. An annual head is billed
                // once, so folding it into a per-period rate inflates every
                // month's discount by a twelfth of a charge that is not there.
                // Service heads are likewise excluded: the default scope is
                // `academic`, and a scholarship does not cut a bus fare unless
                // the school deliberately says so.
                //
                // Correct base on the same chart: 500 + 50 = **550 ⇒ ₹55**.
                // This is `SCHOLARSHIP_PHASE_C_TECHNICAL_DESIGN.md` §2 verbatim
                // ("Same chart, 10%, scope academic … ⇒ award ₹55 per period.
                // Not ₹175."), and it is computed by the SAME tested helper the
                // Phase C allocator uses — not a second implementation that
                // could drift from it.
                //
                // BEHAVIOUR CHANGE, STATED: a newly auto-calculated percentage
                // award is now SMALLER, because the old figure was wrong.
                // Existing awards are untouched — this only runs when the
                // operator leaves the amount blank. Safe to apply now:
                // `scholarshipAwards` is EMPTY on every tenant (measured), so no
                // award anywhere was produced by the old base.
                //
                // Phase C's whole-session APPORTIONMENT is NOT adopted here —
                // that needs the canonical pipeline (design §8). Only the base
                // is per-period safe, and only the base is changed.
                require_once APPPATH . 'libraries/Scholarship_allocator.php';
                $heads  = is_array($feeStructDoc) ? (array) ($feeStructDoc['feeHeads'] ?? []) : [];
                $amount = round(
                    Scholarship_allocator::percentageBase($heads, 'academic') * $scholValue / 100,
                    2
                );
            } else {
                $amount = $scholValue;
            }
        }

        $scholName = isset($scholarship['name']) ? $scholarship['name'] : 'Scholarship';
        $now       = date('Y-m-d H:i:s');

        // Create award record
        $awardData = [
            'scholarship_id'   => $scholId,
            'scholarship_name' => $scholName,
            'student_id'       => $studentId,
            'student_name'     => $studentName,
            'class'            => $class,
            'section'          => $section,
            'amount'           => $amount,
            'awarded_date'     => $now,
            'status'           => 'active',
            'awarded_by'       => $this->admin_name,
        ];

        $awardId = uniqid('award_');
        $awardData['schoolId']      = $this->school_name;
        $awardData['scholarshipId'] = $scholId;
        $awardData['studentId']     = $studentId;
        $awardData['studentName']   = $studentName;
        $this->firebase->firestoreSet('scholarshipAwards', "{$this->school_name}_{$awardId}", $awardData);

        // Update student's Discount in Firestore
        list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);

        $discDoc = $this->firebase->firestoreGet('studentDiscounts', "{$this->school_name}_{$studentId}");
        $existing = is_array($discDoc) ? $discDoc : [];
        $existingTotal = floatval($existing['totalDiscount'] ?? 0);
        $existingSchol = floatval($existing['ScholarshipDiscount'] ?? $existing['scholarshipDiscount'] ?? 0);

        $scholarshipUpdate = [
            'schoolId'            => $this->school_name,
            'studentId'           => $studentId,
            'ScholarshipDiscount' => $existingSchol + $amount,
            'totalDiscount'       => $existingTotal + $amount,
            'scholarships'        => array_merge(
                (array) ($existing['scholarships'] ?? []),
                [$awardId => [
                    'scholarship_id'   => $scholId,
                    'scholarship_name' => $scholName,
                    'amount'           => $amount,
                    'awarded_date'     => $now,
                ]]
            ),
        ];
        // ── CLASSIFICATION, PROVEN BEFORE GATING ────────────────────────────
        //
        // This writes `ScholarshipDiscount`, NOT `onDemandDiscount`. A
        // repository-wide trace shows `scholarshipDiscount` is only ever
        // written and then read back to re-write itself (read-before-write
        // preservation in Fee_firestore_sync / Fee_firestore_txn) — no pricing
        // path and no collection path reads it to change money. It is a
        // MIRROR/SUMMARY field. The scholarship's actual financial effect is at
        // the GENERATION layer, through `scholarshipAwards`, which
        // `Fees::_getStudentDiscounts()` reads.
        //
        // So the outstanding-balance gate is deliberately NOT applied here:
        // it governs what the counter may ASK FOR, and recording a scholarship
        // does not change that. Gating it would refuse legitimate awards.
        // Stamping and auditing DO apply — a summary that outlives its session
        // still misreports, and an unaudited write is still unaccountable.
        $scholarshipUpdate = array_merge(
            $scholarshipUpdate,
            $this->_adjustmentPolicy()->stamp('Scholarship awarded: ' . $scholName, 'scholarship_award')
        );
        $this->firebase->firestoreSet('studentDiscounts', "{$this->school_name}_{$studentId}", $scholarshipUpdate, true);
        $this->_adjustmentPolicy()->audit(
            $studentId, $existing, $scholarshipUpdate,
            'Scholarship awarded: ' . $scholName, 'scholarship_award'
        );

        // ── Firestore: canonical scholarship award record + student summary ──
        try {
            $awOk = $this->fsSync->syncScholarshipAward($awardId, [
                'scholarshipId'   => $scholId,
                'scholarshipName' => $scholName,
                'studentId'       => $studentId,
                'studentName'     => $studentName,
                'className'       => $classNode,
                'section'         => $sectionNode,
                'amount'          => $amount,
                'awardedDate'     => $now,
                'awardedBy'       => $this->admin_name,
            ]);
            if (!$awOk) {
                $this->fsSync->queueForRetry('scholarshipAward', [
                    'awardId' => $awardId,
                    'award'   => [
                        'scholarshipId'   => $scholId,
                        'studentId'       => $studentId,
                        'amount'          => $amount,
                    ],
                ]);
            }

            $dOk = $this->fsSync->syncDiscount($studentId, [
                'scholarshipDiscount' => $existingSchol + $amount,
                'totalDiscount'       => $existingTotal + $amount,
                'appliedAt'           => $now,
                'scholarships'        => [
                    $awardId => [
                        'scholarship_id'   => $scholId,
                        'scholarship_name' => $scholName,
                        'amount'           => $amount,
                        'awarded_date'     => $now,
                    ],
                ],
            ], [
                'studentName' => $studentName,
                'className'   => $classNode,
                'section'     => $sectionNode,
            ]);
            if (!$dOk) {
                $this->fsSync->queueForRetry('studentDiscount', [
                    'studentId' => $studentId,
                    'summary'   => [
                        'scholarshipDiscount' => $existingSchol + $amount,
                        'totalDiscount'       => $existingTotal + $amount,
                    ],
                    'context'   => ['className' => $classNode, 'section' => $sectionNode],
                ]);
            }
        } catch (Exception $e) {
            log_message('error', "award_scholarship: Firestore sync failed for {$studentId}/{$awardId}: " . $e->getMessage());
        }

        // Mirror defaulter status to Firestore so parent/teacher apps see the
        // scholarship-reduced balance without refetching RTDB.
        try {
            $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($studentId);
            $defOk = $this->fsSync->syncDefaulterStatus(
                $studentId, $defaulterStatus, $studentName, $classNode, $sectionNode
            );
            if (!$defOk) {
                $this->fsSync->queueForRetry('feeDefaulter', [
                    'studentId'   => $studentId,
                    'status'      => $defaulterStatus,
                    'studentName' => $studentName,
                    'className'   => $classNode,
                    'section'     => $sectionNode,
                ]);
            }
        } catch (Exception $e) {
            log_message('error', "award_scholarship: defaulter sync failed for {$studentId}: " . $e->getMessage());
        }

        $this->json_success([
            'message'  => "Scholarship awarded to {$studentName}.",
            'award_id' => $awardId,
            'amount'   => $amount,
        ]);
    }

    /**
     * POST — Revoke a scholarship award.
     * Params: award_id
     */
    public function revoke_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'revoke_scholarship', 'Fees', 'manage');
        $awardId = $this->safe_path_segment(trim($this->input->post('award_id') ?? ''), 'award_id');

        $award = $this->firebase->firestoreGet('scholarshipAwards', "{$this->school_name}_{$awardId}");
        if (!is_array($award)) {
            $this->json_error('Award not found.');
        }
        if (isset($award['status']) && $award['status'] === 'revoked') {
            $this->json_error('This award has already been revoked.');
        }

        $now = date('Y-m-d H:i:s');

        // Update award status in Firestore
        $this->firebase->firestoreSet('scholarshipAwards', "{$this->school_name}_{$awardId}", [
            'status'       => 'revoked',
            'revoked_date' => $now,
            'revokedDate'  => $now,
            'revoked_by'   => $this->admin_name,
            'revokedBy'    => $this->admin_name,
        ], true);

        // Remove scholarship discount from student's Discount
        $class   = (string) ($award['class']      ?? $award['className'] ?? '');
        $section = (string) ($award['section']     ?? '');
        $userId  = (string) ($award['student_id']  ?? $award['studentId'] ?? '');
        $amount  = floatval($award['amount'] ?? 0);

        if ($class !== '' && $section !== '' && $userId !== '') {
            list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);

            $discDoc = $this->firebase->firestoreGet('studentDiscounts', "{$this->school_name}_{$userId}");
            $existing = is_array($discDoc) ? $discDoc : [];

            if (!empty($existing)) {
                $totalDisc = floatval($existing['totalDiscount'] ?? 0);
                $scholDisc = floatval($existing['ScholarshipDiscount'] ?? $existing['scholarshipDiscount'] ?? 0);

                $newScholDisc = max(0, $scholDisc - $amount);
                $newTotal     = max(0, $totalDisc - $amount);

                // Remove this award from scholarships map
                $scholarships = (array) ($existing['scholarships'] ?? []);
                unset($scholarships[$awardId]);

                // Same classification as the award path above: a mirror field,
                // so stamped and audited but not balance-gated. A revoke can
                // only ever REDUCE a summary, so an outstanding ceiling would
                // be meaningless here in any case.
                $revokeDoc = array_merge([
                    'ScholarshipDiscount' => $newScholDisc,
                    'totalDiscount'       => $newTotal,
                    'scholarships'        => $scholarships,
                ], $this->_adjustmentPolicy()->stamp('Scholarship revoked', 'scholarship_revoke'));

                $this->firebase->firestoreSet('studentDiscounts', "{$this->school_name}_{$userId}", $revokeDoc, true);
                $this->_adjustmentPolicy()->audit(
                    $userId, $existing, $revokeDoc, 'Scholarship revoked', 'scholarship_revoke'
                );

                // ── Firestore: mark award revoked + update student summary ──
                try {
                    $rOk = $this->fsSync->syncScholarshipRevoke($awardId, $this->admin_name);
                    if (!$rOk) {
                        $this->fsSync->queueForRetry('scholarshipAward', [
                            'awardId'   => $awardId,
                            'revokedBy' => $this->admin_name,
                            'action'    => 'revoke',
                        ]);
                    }

                    $dOk = $this->fsSync->syncDiscount($userId, [
                        'scholarshipDiscount' => $newScholDisc,
                        'totalDiscount'       => $newTotal,
                        'appliedAt'           => $now,
                        'scholarshipsRemove'  => [$awardId],
                    ], [
                        'studentName' => $award['student_name'] ?? '',
                        'className'   => $classNode,
                        'section'     => $sectionNode,
                    ]);
                    if (!$dOk) {
                        $this->fsSync->queueForRetry('studentDiscount', [
                            'studentId' => $userId,
                            'summary'   => [
                                'scholarshipDiscount' => $newScholDisc,
                                'totalDiscount'       => $newTotal,
                                'scholarshipsRemove'  => [$awardId],
                            ],
                            'context'   => ['className' => $classNode, 'section' => $sectionNode],
                        ]);
                    }
                } catch (Exception $e) {
                    log_message('error', "revoke_scholarship: Firestore sync failed for {$userId}/{$awardId}: " . $e->getMessage());
                }
            }

            // Mirror defaulter status to Firestore — revoking a scholarship
            // increases the student's outstanding balance, which the parent
            // app must see.
            try {
                $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($userId);
                $studentName = $award['student_name'] ?? '';
                $defOk = $this->fsSync->syncDefaulterStatus(
                    $userId, $defaulterStatus, $studentName, $classNode, $sectionNode
                );
                if (!$defOk) {
                    $this->fsSync->queueForRetry('feeDefaulter', [
                        'studentId'   => $userId,
                        'status'      => $defaulterStatus,
                        'studentName' => $studentName,
                        'className'   => $classNode,
                        'section'     => $sectionNode,
                    ]);
                }
            } catch (Exception $e) {
                log_message('error', "revoke_scholarship: defaulter sync failed for {$userId}: " . $e->getMessage());
            }
        }

        $this->json_success(['message' => 'Scholarship award revoked successfully.']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  REFUND SYSTEM (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch refunds, optionally filtered by status.
     * Query param: status (optional — pending|approved|processed|rejected)
     */
    public function fetch_refunds()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_refunds', 'Fees', 'view');
        $this->_bootFsTxn();
        $filterStatus = trim((string) $this->input->get('status'));

        $rows = $this->fsTxn->listRefunds($filterStatus);
        $refunds = [];
        foreach ((array) $rows as $row) {
            $ref = $row['data'] ?? $row;
            if (!is_array($ref)) continue;
            // The UI and action endpoints expect the short refund id
            // (`ref_xxxxxx`), NOT the prefixed Firestore doc id
            // (`{schoolId}_ref_xxxxxx`). Prefer the field value; only fall
            // back to stripping the prefix from the doc id.
            $rid = (string) ($ref['refundId'] ?? '');
            if ($rid === '') {
                $docId  = (string) ($row['id'] ?? '');
                $prefix = $this->fs->schoolId() . '_';
                $rid    = (strpos($docId, $prefix) === 0) ? substr($docId, strlen($prefix)) : $docId;
            }
            // Normalise snake_case + camelCase for view compatibility.
            $reqDate = (string) ($ref['requestedDate'] ?? $ref['requested_date'] ?? '');
            $class   = (string) ($ref['className']     ?? $ref['class']          ?? '');
            $section = (string) ($ref['section']       ?? '');
            $ref['id']             = $rid;
            $ref['refund_id']      = $rid;
            $ref['class']          = $class;
            $ref['section']        = $section;
            $ref['class_section']  = trim($class . ' / ' . $section);
            $ref['date']           = $reqDate;
            $ref['requested_date'] = $reqDate;
            $ref['student_id']     = (string) ($ref['studentId']   ?? $ref['student_id']   ?? '');
            $ref['student_name']   = (string) ($ref['studentName'] ?? $ref['student_name'] ?? '');
            $ref['receipt_no']     = (string) ($ref['receiptNo']   ?? $ref['receipt_no']   ?? '');
            $ref['fee_title']      = (string) ($ref['feeTitle']    ?? $ref['fee_title']    ?? '');
            $ref['refund_mode']    = (string) ($ref['refundMode']  ?? $ref['refund_mode']  ?? '');
            $ref['processed_date'] = (string) ($ref['processedDate'] ?? $ref['processed_date'] ?? '');
            $refunds[] = $ref;
        }

        // Compute stats
        $stats = ['total' => count($refunds), 'pending' => 0, 'approved' => 0, 'processed' => 0, 'rejected' => 0];
        foreach ($refunds as $ref) {
            $s = (string) ($ref['status'] ?? '');
            if (isset($stats[$s])) $stats[$s]++;
        }

        $this->json_success(['refunds' => $refunds, 'stats' => $stats, 'success' => true]);
    }

    /** Lazy-load the Fee_firestore_txn helper. */
    private function _bootFsTxn(): void
    {
        if (!isset($this->fsTxn)) {
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        }
    }

    /**
     * POST — Create a refund request.
     * Params: student_id, student_name, class, section, amount, fee_title, receipt_no, reason
     */
    public function create_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'create_refund', 'Fees', 'manage');
        $this->_bootFsTxn();

        $studentId   = trim((string) $this->input->post('student_id'));
        $studentName = trim((string) $this->input->post('student_name'));
        $class       = trim((string) $this->input->post('class'));
        $section     = trim((string) $this->input->post('section'));
        $amount      = floatval($this->input->post('amount'));
        $feeTitle    = trim((string) $this->input->post('fee_title'));
        $receiptNo   = trim((string) $this->input->post('receipt_no'));
        $reason      = trim((string) $this->input->post('reason'));

        if ($studentId === '' || $studentName === '') $this->json_error('Student information is required.');
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        // Receipt-number normalisation: users see different formats at
        // different places — the success modal shows "Receipt #1", the
        // internal key is "F1", and printed receipts show "R000001".
        // Strip any prefix so all three forms resolve to the same receipt.
        //
        // Required: the parent app shows the refund as "R<origReceiptNo>".
        // Without a value here it falls back to a random "R-XXXXXX" label
        // which is confusing to parents. Server-side enforce in addition
        // to the HTML5 required attribute on the form.
        if ($receiptNo === '') {
            $this->json_error('Receipt number is required. Enter the receipt this refund is against (e.g. 2 or F2).');
        }
        $receiptNo = preg_replace('/^R0*/i', '', $receiptNo);   // R000001 → 1
        $receiptNo = preg_replace('/^F/i',  '', $receiptNo);    // F1      → 1
        $receiptNo = preg_replace('/\D/',   '', $receiptNo);    // keep digits only
        if ($receiptNo === '') {
            $this->json_error('Could not recognise the receipt number. Enter a value like "1" or "R000001".');
        }
        $receiptNo = $this->safe_path_segment($receiptNo, 'receipt_no');
        if ($amount <= 0)    $this->json_error('Refund amount must be greater than zero.');
        if ($feeTitle === '') $this->json_error('Fee title is required.');
        if ($reason === '')  $this->json_error('Refund reason is required.');

        // Handle combined class_section field from view
        $classSection = trim((string) $this->input->post('class_section'));
        if ($classSection !== '' && $class === '') {
            $parts = preg_split('/[\/,]/', $classSection, 2);
            $class = trim($parts[0]);
            $section = isset($parts[1]) ? trim($parts[1]) : '';
        }

        // Verify the receipt exists in Firestore (Session A canonical).
        if ($receiptNo !== '') {
            $rcptKey = (strpos($receiptNo, 'F') === 0) ? $receiptNo : 'F' . $receiptNo;
            $record  = $this->fsTxn->getFeeReceipt($rcptKey);
            if (!is_array($record)) {
                $this->json_error("Receipt '{$receiptNo}' not found for this student.");
            }
            $originalAmount = (float) ($record['amount'] ?? 0);
            if ($originalAmount > 0 && $amount > $originalAmount) {
                $this->json_error("Refund amount ({$amount}) exceeds original payment amount ({$originalAmount}).");
            }
        }

        $now   = date('Y-m-d H:i:s');
        $refId = uniqid('ref_');

        $refundDoc = [
            'refundId'        => $refId,
            'studentId'       => $studentId,
            'studentName'     => $studentName,
            'className'       => $class,
            'section'         => $section,
            'amount'          => $amount,
            'feeTitle'        => $feeTitle,
            'receiptNo'       => $receiptNo,
            // Emit origReceiptNo too so both the refund doc AND the
            // later-generated voucher carry the same canonical field.
            // Parent-app FeeRefundVoucherDoc expects `origReceiptNo`
            // exactly — writing it here guarantees no "R-XXXXXX"
            // random fallback regardless of processing state.
            'origReceiptNo'   => $receiptNo,
            'reason'          => $reason,
            'status'          => 'pending',
            'requestedDate'   => $now,
            'reviewedDate'    => '',
            'processedDate'   => '',
            'reviewedBy'      => '',
            'processedBy'     => '',
            'refundMode'      => '',
            'remarks'         => '',
        ];

        if (!$this->fsTxn->writeRefund($refId, $refundDoc)) {
            $this->json_error('Failed to record refund. Please retry.');
        }

        $this->json_success([
            'message'   => 'Refund request created successfully.',
            'refund_id' => $refId,
            'success'   => true,
        ]);
    }

    /**
     * POST — Update refund status (approve/reject).
     * Params: refund_id, status (approved|rejected), remarks?
     */
    public function update_refund_status()
    {
        $this->_require_role(self::ADMIN_ROLES, 'update_refund_status', 'Fees', 'manage');
        $this->_bootFsTxn();

        $refId   = $this->safe_path_segment(trim((string) $this->input->post('refund_id')), 'refund_id');
        $status  = trim((string) $this->input->post('status'));
        $remarks = trim((string) $this->input->post('remarks'));

        if (!in_array($status, ['approved', 'rejected'], true)) {
            $this->json_error('Status must be "approved" or "rejected".');
        }

        $existing = $this->fsTxn->getRefund($refId);
        if (!is_array($existing)) $this->json_error('Refund not found.');

        $currentStatus = (string) ($existing['status'] ?? '');
        if ($currentStatus !== 'pending') {
            $this->json_error("Cannot change status. Current status is '{$currentStatus}'.");
        }

        $updateData = [
            'status'       => $status,
            'reviewedDate' => date('Y-m-d H:i:s'),
            'reviewedBy'   => $this->admin_name ?? '',
        ];
        if ($remarks !== '') $updateData['remarks'] = $remarks;

        $this->fsTxn->writeRefund($refId, $updateData);

        $this->json_success(['message' => "Refund {$status} successfully.", 'success' => true]);
    }

    /**
     * POST — Approve a refund (convenience wrapper).
     */
    public function approve_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'approve_refund', 'Fees', 'manage');
        $_POST['status'] = 'approved';
        $this->update_refund_status();
    }

    /**
     * POST — Reject a refund (convenience wrapper).
     */
    public function reject_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'reject_refund', 'Fees', 'manage');
        $_POST['status'] = 'rejected';
        $this->update_refund_status();
    }

    /**
     * POST — Process an approved refund.
     * Params: refund_id (required), refund_mode (cash|bank_transfer|cheque|online)
     *
     * Thin controller: validates input, delegates to Fee_refund_service.
     */
    public function process_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'process_refund', 'Fees', 'manage');
        // L1.0 period-lock enforcement — block refund posting if
        // today's date falls within a closed accounting period.
        // Refund creates a NEW reversal journal entry; same gating
        // policy as fee posting. This sits BEFORE _bootFsTxn so the
        // 423 fires cleanly without any heavy library bootstrapping.
        $this->_abort_if_period_locked();
        $this->_bootFsTxn();

        // A single refund touches ~15 Firestore REST ops (reverse allocations,
        // voucher, ledger, defaulter, idempotency). With the default 120s
        // PHP timeout a slow Firestore network can leave the flow half-done —
        // demand reversed but journal never posted, e.g. Give the flow 10
        // minutes so it can always complete cleanly; the per-student lock +
        // idempotency guard prevent any concurrent dupe from sneaking in.
        @set_time_limit(600);

        $refId      = trim((string) $this->_post('refund_id'));
        $refundMode = trim((string) $this->_post('refund_mode'));
        if ($refId === '') $this->json_error('Refund ID is required.');
        $refId = $this->safe_path_segment($refId, 'refund_id');

        $validModes = ['cash', 'bank_transfer', 'cheque', 'online'];
        if (!in_array($refundMode, $validModes, true)) {
            $this->json_error('Invalid refund mode. Must be one of: ' . implode(', ', $validModes));
        }

        // Operations_accounting still posts the reversal ledger to RTDB +
        // Firestore mirror. Session C will make it Firestore-only.
        $this->load->library('Operations_accounting', null, 'ops_acct');
        $this->ops_acct->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->admin_id ?? 'system', $this
        );

        // Service is now Firestore-native via Fee_firestore_txn.
        $this->load->library('Fee_refund_service', null, 'refund_svc');
        $this->refund_svc->init(
            $this->fsTxn,
            $this->admin_name ?? 'System',
            $this->admin_id ?? 'system',
            $this->ops_acct
        );

        // R.6 (Phase 9): wallet subsystem removed, so stale-allocation
        // refunds are always rejected — there's no wallet to route the
        // amount into. The acknowledge_stale flag the UI used to send
        // (and which this controller used to forward) was already a
        // no-op service-side; C6 cleanup 2026-05-10 removed the dead
        // plumbing. STALE_ALLOCATION still surfaces structured details
        // to the UI so the admin can see which newer receipts are
        // blocking the refund.
        $result = $this->refund_svc->process($refId, $refundMode);

        if (!$result['ok']) {
            log_message('error', "process_refund failed for {$refId}: " . ($result['error'] ?? ''));
            // STALE_ALLOCATION: UI still surfaces the conflicts so admin
            // can investigate, but there's no override path.
            if (($result['code'] ?? '') === 'STALE_ALLOCATION') {
                $this->output->set_content_type('application/json');
                $this->output->set_output(json_encode([
                    'status'        => 'error',
                    'code'          => 'STALE_ALLOCATION',
                    'message'       => $result['error'],
                    'conflicts'     => $result['conflicts']     ?? [],
                    'superseded_by' => $result['superseded_by'] ?? [],
                ]));
                return;
            }
            $this->json_error($result['error'] ?? 'Refund processing failed.');
        }

        // Best-effort audit log (Fee_audit still RTDB — will be migrated
        // alongside the accounting journal in Session C).
        try {
            $this->load->library('Fee_audit', null, 'fee_audit');
            $this->fee_audit->init(
                $this->firebase, "{$this->sessionRoot}/Fees",
                $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                $this->school_name
            );
            $r = $this->fsTxn->getRefund($refId);
            $this->fee_audit->log('refund_processed', [
                'refund_id'  => $refId,
                'student_id' => is_array($r) ? (string) ($r['studentId'] ?? $r['student_id'] ?? '') : '',
                'amount'     => is_array($r) ? (float)  ($r['amount']    ?? 0) : 0,
                'receipt_no' => is_array($r) ? (string) ($r['receiptNo'] ?? $r['receipt_no'] ?? '') : '',
                'mode'       => $refundMode,
            ]);
        } catch (\Exception $_) { /* audit is non-critical */ }

        $this->json_success($result['data']);
    }

    /**
     * POST — Unstick a refund left stranded in 'processing' status.
     * Params: refund_id (required)
     *
     * R.7 rescue endpoint. When a crash / timeout interrupts
     * Fee_refund_service::process() after it flips the refund doc to
     * 'processing' but before it marks 'processed', the doc stays stuck
     * and cannot be re-tried because both the `processLock` timestamp
     * and the per-student `feeLocks` doc may outlive the 5-minute TTL
     * without being released. This endpoint:
     *   1. Verifies the stored `processLock` is actually stale
     *      (older than LOCK_TTL = 300s) — refuses otherwise, so we
     *      never steal from an in-flight process().
     *   2. Rolls the refund doc back to 'approved' + clears processLock.
     *   3. Force-releases the per-student feeLocks entry (token-blind).
     *   4. Deletes the feeIdempotency hash so a retry can proceed.
     * Admin then re-clicks "Process" on the refund row.
     */
    public function unstick_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'unstick_refund', 'Fees', 'manage');
        $this->_bootFsTxn();

        // Unstick writes 3-4 Firestore docs. Same rationale as process_refund
        // — don't let a slow Firestore transport kill the flow at 120s.
        @set_time_limit(600);

        $refId = trim((string) $this->_post('refund_id'));
        if ($refId === '') $this->json_error('Refund ID is required.');
        $refId = $this->safe_path_segment($refId, 'refund_id');

        $refund = $this->fsTxn->getRefund($refId);
        if (!is_array($refund)) {
            $this->json_error('Refund not found.');
        }

        $status = (string) ($refund['status'] ?? '');
        if ($status !== 'processing') {
            $this->json_error("Only 'processing' refunds can be unstuck. Current status: '{$status}'.");
        }

        // Fee_refund_service uses LOCK_TTL = 300. Match it here so an
        // admin can't race an in-flight process() that's still inside
        // its own lock window.
        $lockTsRaw = (string) ($refund['processLock'] ?? $refund['process_lock'] ?? '');
        $lockTs    = $lockTsRaw !== '' ? strtotime($lockTsRaw) : 0;
        $age       = $lockTs ? (time() - $lockTs) : PHP_INT_MAX;
        if ($lockTs && $age < 300) {
            $wait = 300 - $age;
            $this->json_error("Refund is still within its processing window. Wait {$wait}s before unsticking.");
        }

        $studentId     = (string) ($refund['studentId']  ?? $refund['student_id']  ?? '');
        $origReceiptNo = (string) ($refund['receiptNo']  ?? $refund['receipt_no']  ?? '');
        $amount        = (float)  ($refund['amount']     ?? 0);

        // 1. Roll the refund doc back to 'approved' so the admin can retry.
        $this->fsTxn->writeRefund($refId, [
            'status'      => 'approved',
            'processLock' => '',
        ]);

        // 2. Force-release the per-student lock (token-blind) so the
        //    next process() attempt can reacquire it.
        $lockReleased = false;
        if ($studentId !== '') {
            $lockReleased = $this->fsTxn->forceReleaseLock($studentId);
        }

        // 3. Clear the idempotency marker. Rebuild the exact hash
        //    Fee_refund_service used when it entered the processing
        //    window, so the deletion lands on the same doc.
        $idempCleared = false;
        if ($studentId !== '' && $amount > 0) {
            $idempHash    = $this->fsTxn->idempKey($studentId, $refId, [$origReceiptNo], $amount);
            $idempCleared = $this->fsTxn->deleteIdempotency($idempHash);
        }

        log_message('info', "Fee_management::unstick_refund rescued {$refId} (student={$studentId} age={$age}s)");

        // Best-effort audit.
        try {
            $this->load->library('Fee_audit', null, 'fee_audit');
            $this->fee_audit->init(
                $this->firebase, "{$this->sessionRoot}/Fees",
                $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                $this->school_name
            );
            $this->fee_audit->log('refund_unstuck', [
                'refund_id'     => $refId,
                'student_id'    => $studentId,
                'prior_age_s'   => $age,
                'lock_released' => $lockReleased,
                'idemp_cleared' => $idempCleared,
            ]);
        } catch (\Exception $_) { /* audit is non-critical */ }

        $this->json_success([
            'message'        => 'Refund reset to approved. Click Process to retry.',
            'refund_id'      => $refId,
            'prior_age_s'    => $age,
            'lock_released'  => $lockReleased,
            'idemp_cleared'  => $idempCleared,
        ]);
    }

    /**
     * POST — Retry the accounting journal post for a refund that was
     * processed but whose journal write failed (R.5).
     *
     * Params: refund_id (required)
     *
     * The refund itself is already complete — demands reversed, voucher
     * written, student notified. Only the ledger entry is missing. This
     * endpoint hits the idempotent journal poster: if a journal for this
     * refund already exists it returns that one; otherwise it posts a
     * fresh entry. Either way, the refund doc's journalPosted flag gets
     * set so the UI warning clears.
     */
    public function retry_refund_journal()
    {
        $this->_require_role(self::ADMIN_ROLES, 'retry_refund_journal', 'Fees', 'manage');
        $this->_bootFsTxn();

        // Ledger write + index writes + closing-balance updates. Slow
        // Firestore can easily exceed 120s. Raise the ceiling.
        @set_time_limit(600);

        $refId = trim((string) $this->_post('refund_id'));
        if ($refId === '') $this->json_error('Refund ID is required.');
        $refId = $this->safe_path_segment($refId, 'refund_id');

        // Operations_accounting + Fee_refund_service boot is identical to
        // process_refund — keep parity so both endpoints share CoA state.
        $this->load->library('Operations_accounting', null, 'ops_acct');
        $this->ops_acct->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->admin_id ?? 'system', $this
        );

        $this->load->library('Fee_refund_service', null, 'refund_svc');
        $this->refund_svc->init(
            $this->fsTxn,
            $this->admin_name ?? 'System',
            $this->admin_id ?? 'system',
            $this->ops_acct
        );

        $result = $this->refund_svc->retryJournal($refId);

        if (!$result['ok']) {
            log_message('error', "retry_refund_journal failed for {$refId}: " . ($result['error'] ?? ''));
            $this->json_error($result['error'] ?? 'Journal retry failed.');
        }

        $msg = !empty($result['already'])
            ? 'Journal was already posted — nothing to retry.'
            : 'Journal posted successfully.';

        $this->json_success([
            'message'          => $msg,
            'refund_id'        => $refId,
            'journal_entry_id' => $result['entryId'],
            'already_posted'   => !empty($result['already']),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE REMINDERS (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch reminder settings.
     */
    public function get_reminder_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_reminder_settings', 'Fees', 'view');
        // Static use only (DEFAULT_GRACE / clamp) — required rather than
        // load->library(), which would also instantiate a class this method
        // never needs an instance of.
        require_once APPPATH . 'libraries/Fee_overdue_policy.php';
        $settings = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_reminders");
        if (!is_array($settings)) {
            $settings = [
                'auto_remind'        => false,
                'days_before_due'    => [7, 3, 1],
                'reminder_message'   => 'Dear Parent, this is a reminder that fees for {month} amounting to Rs. {amount} are due. Please pay by {due_date}.',
                'due_day_of_month'   => 10,
                // Days after the due date before a demand is PRESENTED as
                // overdue. Separate from late_fee_grace_days on purpose: that
                // one governs FINES and only applies when late fees are
                // enabled, so a school running late fees OFF could not
                // otherwise express "give them a few quiet days".
                // 0 = overdue the day after it was due, which is the plain
                // meaning of the word and needs no policy to justify it.
                'overdue_grace_days' => Fee_overdue_policy::DEFAULT_GRACE,
                'late_fee_enabled'   => false,
                'late_fee_type'      => 'fixed',
                'late_fee_value'     => 0,
            ];
        }
        $this->json_success(['settings' => $settings]);
    }

    /**
     * POST — Save reminder settings.
     * Params: auto_remind, days_before_due, reminder_message, due_day_of_month,
     *         late_fee_enabled, late_fee_type, late_fee_value
     */
    public function save_reminder_settings()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_reminder_settings', 'Fees', 'edit');
        // Static use only — see get_reminder_settings().
        require_once APPPATH . 'libraries/Fee_overdue_policy.php';
        $autoRemind     = ($this->input->post('auto_remind') === '1'
                          || $this->input->post('auto_remind') === 'true') ? true : false;
        $daysBeforeDue  = $this->input->post('days_before_due');
        $message        = trim($this->input->post('reminder_message'));
        $dueDay         = (int)$this->input->post('due_day_of_month');
        $lateFeeEnabled = ($this->input->post('late_fee_enabled') === '1'
                          || $this->input->post('late_fee_enabled') === 'true') ? true : false;
        $lateFeeType    = trim($this->input->post('late_fee_type'));
        $lateFeeValue   = floatval($this->input->post('late_fee_value'));

        // Phase 15.6 — grace period and per-demand cap.
        //
        // These are per-SCHOOL and per-SESSION by design, never global
        // constants: Indian school fee regulation is STATE jurisdiction (the
        // Delhi, Maharashtra and Gujarat statutes all differ), so no single
        // grace period or ceiling is correct for every tenant. Both default
        // to 0, which reproduces the pre-15.6 behaviour exactly — grace 0 =
        // fine the day after the due date, cap 0 = uncapped.
        $lateFeeGrace   = (int) $this->input->post('late_fee_grace_days');
        $lateFeeCap     = floatval($this->input->post('late_fee_max_cap'));

        if ($dueDay < 1 || $dueDay > 28) {
            $this->json_error('Due day of month must be between 1 and 28.');
        }

        if ($lateFeeEnabled && !in_array($lateFeeType, ['percentage', 'fixed'], true)) {
            $this->json_error('Late fee type must be "percentage" or "fixed".');
        }

        if ($lateFeeGrace < 0 || $lateFeeGrace > 365) {
            $this->json_error('Late fee grace period must be between 0 and 365 days.');
        }
        if ($lateFeeCap < 0) {
            $this->json_error('Maximum late fee cannot be negative. Use 0 for "no maximum".');
        }

        // Parse days_before_due
        $daysArray = [];
        if (is_array($daysBeforeDue)) {
            $daysArray = array_map('intval', $daysBeforeDue);
        } elseif (is_string($daysBeforeDue) && $daysBeforeDue !== '') {
            $daysArray = array_map('intval', array_filter(explode(',', $daysBeforeDue)));
        }
        $daysArray = array_values(array_filter($daysArray, function ($d) {
            return $d > 0;
        }));
        rsort($daysArray);

        // Phase 15.7 — overdue reminder stages + the on-due-date stage.
        // `days_before_due` already existed but had no consumer until now;
        // these complete the set that Fee_reminder_service evaluates.
        $daysAfterRaw = $this->input->post('days_after_due');
        $afterArray   = [];
        if (is_array($daysAfterRaw)) {
            $afterArray = array_map('intval', $daysAfterRaw);
        } elseif (is_string($daysAfterRaw) && $daysAfterRaw !== '') {
            $afterArray = array_map('intval', array_filter(explode(',', $daysAfterRaw)));
        }
        $afterArray = array_values(array_filter($afterArray, function ($d) {
            return $d > 0 && $d <= 365;
        }));
        sort($afterArray);

        $remindOnDue = ($this->input->post('remind_on_due') === '1'
                       || $this->input->post('remind_on_due') === 'true') ? true : false;

        $settings = [
            'auto_remind'      => $autoRemind,
            'days_before_due'  => $daysArray,
            // Phase 15.7 — consumed by Fee_reminder_service::settings()
            'days_after_due'   => $afterArray,
            'remind_on_due'    => $remindOnDue,
            'reminder_message' => $message,
            'due_day_of_month' => $dueDay,
            // THE authoritative overdue policy — read by Fee_overdue_policy
            // and stamped onto each demand as `overdueFrom`, so the Parent App
            // derives its display from the demand and holds no policy of its
            // own. Clamped through the same pure function the policy class and
            // its tests use, so a copy cannot drift.
            'overdue_grace_days' => Fee_overdue_policy::clamp(
                $this->input->post('overdue_grace_days')
            ),
            'late_fee_enabled' => $lateFeeEnabled,
            'late_fee_type'    => $lateFeeType,
            'late_fee_value'   => $lateFeeValue,
            // Phase 15.6 — read by Fee_late_fee_service::policy()
            'late_fee_grace_days' => $lateFeeGrace,
            'late_fee_max_cap'    => $lateFeeCap,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        $settings['schoolId'] = $this->school_name;
        $settings['session']  = $this->session_year;
        $settings['type']     = 'reminders';
        $this->firebase->firestoreSet('feeSettings', "{$this->school_name}_{$this->session_year}_reminders", $settings);

        $this->json_success(['message' => 'Reminder settings saved successfully.']);
    }

    /**
     * GET — Scan all classes to find students with unpaid fee months.
     * Returns list of students with due amounts.
     */
    public function fetch_due_students()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_due_students', 'Fees', 'view');
        // Fee_firestore_txn is used statically at line ~2204 for
        // periodToMonth(). Bootstrap the library so the class file is
        // loaded before the static call.
        $this->_bootFsTxn();
        $classSections = $this->_getAllClassSections();
        $dueStudents   = [];

        // Get current month names for reference
        $months = [
            'April', 'May', 'June', 'July', 'August', 'September',
            'October', 'November', 'December', 'January', 'February', 'March'
        ];

        // Determine months up to current month
        $currentMonth  = date('n'); // 1-12
        $monthIndex    = ($currentMonth >= 4) ? ($currentMonth - 4) : ($currentMonth + 8);
        $monthsToCheck = array_slice($months, 0, $monthIndex + 1);

        // Load students from Firestore — SESSION-SCOPED. Previously this
        // filtered by schoolId only, so after a year rollover the current
        // session's due list bled in students belonging to other sessions
        // (student `session` singular is the canonical authority; the demand
        // query below is already session-scoped, so an unscoped student set
        // could only ever mis-list, never add real dues). Scoping both to the
        // same session keeps the due list consistent across multiple sessions.
        $studentRows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $this->school_name],
            ['session',  '==', $this->session_year],
        ]);

        // Load all fee demands to determine unpaid months
        $demandRows = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $this->school_name],
            ['session',  '==', $this->session_year],
        ]);

        // Build demand lookup keyed by studentId + month-LABEL (canonical
        // periodToMonth, NOT full period string). Aggregates per-month
        // balance + status across all heads. Phase 15 dropped the legacy
        // `monthFee` cache read — feeDemands is the only source of truth.
        $demandsByStudentMonth = []; // sid -> month -> ['balance', 'allPaid']
        foreach ((array) $demandRows as $row) {
            $dd = $row['data'] ?? $row;
            $sid    = (string) ($dd['studentId'] ?? '');
            $period = (string) ($dd['period']    ?? '');
            $month  = Fee_firestore_txn::periodToMonth($period);
            if ($sid === '' || $month === '') continue;
            $bal    = (float) ($dd['balance']     ?? 0);
            $status = (string) ($dd['status']     ?? 'unpaid');
            if (!isset($demandsByStudentMonth[$sid][$month])) {
                $demandsByStudentMonth[$sid][$month] = ['balance' => 0.0, 'allPaid' => true, 'has' => false];
            }
            $demandsByStudentMonth[$sid][$month]['balance'] += $bal;
            $demandsByStudentMonth[$sid][$month]['has']      = true;
            if ($status !== 'paid' || $bal > 0.005) {
                $demandsByStudentMonth[$sid][$month]['allPaid'] = false;
            }
        }

        foreach ((array) $studentRows as $row) {
            $d = $row['data'] ?? $row;
            if (!is_array($d)) continue;
            $userId = (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? ''));
            $studentName = (string) ($d['name'] ?? $d['studentName'] ?? '');
            $cls = trim((string) ($d['className'] ?? ''));
            $sec = trim((string) ($d['section'] ?? ''));
            if (stripos($cls, 'Class ') !== 0) $cls = 'Class ' . $cls;
            if (stripos($sec, 'Section ') !== 0) $sec = 'Section ' . $sec;

            $studentMonthMap = $demandsByStudentMonth[$userId] ?? [];

            $unpaidMonths = [];
            $totalDue     = 0.0;

            foreach ($monthsToCheck as $month) {
                $entry = $studentMonthMap[$month] ?? null;
                // No demand for this month → treat as unpaid (legacy cache
                // would have done the same via missing key fallback).
                if ($entry === null || !$entry['allPaid']) {
                    $unpaidMonths[] = $month;
                    if ($entry !== null) $totalDue += $entry['balance'];
                }
            }

            if (!empty($unpaidMonths)) {
                $dueStudents[] = [
                    'user_id'       => $userId,
                    'name'          => $studentName !== '' ? $studentName : $userId,
                    'class'         => $cls,
                    'section'       => $sec,
                    'unpaid_months' => $unpaidMonths,
                    'total_due'     => round($totalDue, 2),
                ];
            }
        }

        // Enrich each student with their most recent reminder timestamp so
        // the Due Students table's "Last Reminder" column shows when a
        // reminder was last dispatched (instead of always "Never"). One
        // Firestore query for the whole school, then in-memory max per
        // studentId — cheap vs per-student lookups.
        try {
            $logRows = $this->firebase->firestoreQuery('feeReminderLog', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            $lastByStudent = [];
            foreach ((array) $logRows as $lrow) {
                $ldata = $lrow['data'] ?? $lrow;
                if (!is_array($ldata)) continue;
                $lsid  = (string) ($ldata['student_id'] ?? $ldata['studentId'] ?? '');
                $lsent = (string) ($ldata['sent_date']  ?? $ldata['sentDate']  ?? '');
                if ($lsid === '' || $lsent === '') continue;
                if (!isset($lastByStudent[$lsid]) || strcmp($lsent, $lastByStudent[$lsid]) > 0) {
                    $lastByStudent[$lsid] = $lsent;
                }
            }
            foreach ($dueStudents as &$ds) {
                $dsid = (string) $ds['user_id'];
                if (isset($lastByStudent[$dsid])) {
                    $ds['last_reminder'] = $lastByStudent[$dsid];
                }
            }
            unset($ds);
        } catch (\Exception $_) { /* non-fatal — table simply falls back to "Never" */ }

        // Sort by total_due descending
        usort($dueStudents, function ($a, $b) {
            return $b['total_due'] - $a['total_due'];
        });

        $this->json_success([
            'students'     => $dueStudents,
            'total_count'  => count($dueStudents),
            'months_checked' => $monthsToCheck,
        ]);
    }

    /**
     * POST — Send reminder to selected students.
     * Params: student_ids[] (JSON objects), month
     *
     * Since SMS/email gateway is not yet integrated, this logs the reminder
     * for future integration.
     */
    public function send_reminder()
    {
        $this->_require_role(self::FINANCE_ROLES, 'send_reminder', 'Fees', 'edit');
        $studentRaw = $this->input->post('student_ids');
        $month      = trim((string) $this->input->post('month'));
        // New (2026-04-17): accept channel so Defaulter Report can send
        // bulk WhatsApp reminders. Defaults to 'log' for legacy callers.
        $channel    = strtolower(trim((string) $this->input->post('channel')));
        $template   = trim((string) $this->input->post('template'));
        if ($channel === '') $channel = 'log';
        if ($month === '')   $month   = date('F'); // current month name

        if (empty($studentRaw) || !is_array($studentRaw)) {
            $this->json_error('No students selected.');
        }

        // Build a one-shot defaulter lookup so callers that pass bare
        // student_ids (like the Defaulter Report bulk-reminder button)
        // don't have to also send name/class/due info.
        $defaulterMap = [];
        try {
            $rows = $this->firebase->firestoreQuery('feeDefaulters', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
                if ($sid !== '') $defaulterMap[$sid] = $d;
            }
        } catch (\Exception $_) { /* non-fatal */ }

        // ISO-8601 with timezone offset so mobile clients can parse
        // unambiguously. A naive "Y-m-d H:i:s" string was interpreted as
        // phone-local on the teacher app, off-by-several-hours on any
        // device whose TZ differs from the server's.
        $now    = date('c');
        $logged = 0;
        $batchData = [];

        foreach ($studentRaw as $entry) {
            // Accept three shapes: plain student_id string, JSON
            // string, or already-decoded array. Normalises to an
            // associative array with at least user_id.
            $student = null;
            if (is_array($entry)) {
                $student = $entry;
            } elseif (is_string($entry)) {
                $decoded = json_decode($entry, true);
                if (is_array($decoded)) {
                    $student = $decoded;
                } else {
                    $sid = trim($entry);
                    if ($sid !== '') $student = ['user_id' => $sid];
                }
            }
            if (!is_array($student) || empty($student['user_id'])) continue;

            $sid = (string) $student['user_id'];
            // Enrich from defaulter doc if the caller didn't supply details.
            $def = $defaulterMap[$sid] ?? [];
            $name    = $student['name']      ?? ($def['studentName'] ?? '');
            $class   = $student['class']     ?? ($def['className']   ?? '');
            $section = $student['section']   ?? ($def['section']     ?? '');
            // Prefer the UI-supplied total_due so the notification shows
            // the exact value the admin saw in the table.
            // Fallback order (all camelCase canonical first, snake/legacy
            // names second) covers older defaulter docs and manual edits:
            //   totalDues (canonical) → total_dues → totalBalance → balance
            $due = isset($student['total_due'])
                ? (float) $student['total_due']
                : (float) ($def['totalDues']
                        ?? $def['total_dues']
                        ?? $def['totalBalance']
                        ?? $def['balance']
                        ?? 0);

            // Per-student month override: if the caller didn't pass an
            // explicit month, use this student's OLDEST unpaid month from
            // the defaulter doc instead of date('F'). Previously we stamped
            // the current calendar month on every reminder, which showed
            // "April" on a notification even though April was fully paid
            // and the actual dues were in December–March.
            $studentMonth = $month;
            if (($studentMonth === '' || $studentMonth === date('F'))
                && !empty($def['unpaidMonths']) && is_array($def['unpaidMonths'])) {
                $oldest = (string) ($def['unpaidMonths'][0] ?? '');
                if ($oldest !== '') $studentMonth = $oldest;
            }
            if ($studentMonth === '') $studentMonth = date('F');

            $logId = uniqid('rem_');
            $batchData[$logId] = [
                'student_id'   => $sid,
                'student_name' => $name,
                'class'        => $class,
                'section'      => $section,
                'month'        => $studentMonth,
                'amount_due'   => $due,
                'sent_date'    => $now,
                'channel'      => $channel,                 // 'whatsapp' | 'sms' | 'email' | 'log'
                'template'     => $template,
                'type'         => 'manual',
                // Phase 15.8 — status must not claim a delivery that cannot
                // happen. 'queued' implied a worker would pick the row up;
                // none exists, so WhatsApp/SMS/email rows sat 'queued'
                // forever and the reminder-history screen showed them as
                // pending delivery. They are now marked 'not_sent', which is
                // what actually happened. 'push' is genuinely dispatched
                // inline below; 'log' is a deliberate record-only action.
                'status'       => ($channel === 'log')   ? 'logged'
                                : (($channel === 'push') ? 'queued' : 'not_sent'),
            ];
            $logged++;
        }

        // Phase 8: 'push' channel dispatches via FCM immediately
        // (parent app's FCMService already routes type=fee_reminder to a
        // notification card that opens the Fees screen on tap). Other
        // channels still queue for the external messaging worker.
        $pushStats = ['attempted' => 0, 'delivered' => 0, 'no_token' => 0];
        if ($channel === 'push' && !empty($batchData)) {
            $this->load->library('Push_service', null, 'push_svc');
            foreach ($batchData as $logId => &$entry) {
                $sid = (string) $entry['student_id'];
                $due = (float) $entry['amount_due'];
                $name = (string) $entry['student_name'];
                $payload = [
                    'title' => $template !== '' ? $template : 'Fee reminder',
                    'body'  => sprintf(
                        '%s — Rs. %s pending for %s. Tap to pay.',
                        $name !== '' ? $name : 'Student',
                        number_format($due, 2),
                        $entry['month']
                    ),
                    'data'  => [
                        'type'       => 'fee_reminder',
                        'studentId'  => $sid,
                        'schoolId'   => $this->school_name,
                        'amount_due' => (string) $due,
                        'month'      => (string) $entry['month'],
                        'logId'      => $logId,
                    ],
                ];
                $pushStats['attempted']++;
                // userDevices is keyed by studentId for parents (parent app
                // registers using the linked student's ID as its userId).
                $delivered = $this->push_svc->sendToUser($sid, $payload);
                if ($delivered > 0) {
                    $pushStats['delivered']++;
                    $entry['status']        = 'delivered';
                    $entry['deliveredCount']= $delivered;
                    $entry['deliveredAt']   = date('c');
                } else {
                    $pushStats['no_token']++;
                    $entry['status']      = 'no_device';
                    $entry['lastError']   = 'No active FCM token for parent';
                }
            }
            unset($entry);
        }

        if (!empty($batchData)) {
            foreach ($batchData as $logId => $entry) {
                $entry['schoolId'] = $this->school_name;
                $entry['session']  = $this->session_year;
                $this->firebase->firestoreSet('feeReminderLog', "{$this->school_name}_{$logId}", $entry);
            }
        }

        // Phase 15.8 — HONEST CHANNEL REPORTING.
        //
        // THE DEFECT THIS CLOSES: choosing WhatsApp / SMS / email wrote a
        // feeReminderLog row with status='queued' and told the operator
        // "Delivery by the messaging worker." NO SUCH WORKER EXISTS in this
        // repository — verified by sweep: the only readers of feeReminderLog
        // are the two history endpoints in this controller, and nothing ever
        // transitions a row out of 'queued'. So the message was never sent
        // and the row sat queued forever, while the office believed the
        // parent had been contacted.
        //
        // Same failure class as the dead `auto_remind` switch closed in Phase
        // 15.5: the damage is not the missing feature, it is that staff STOP
        // CHASING PARENTS because the screen said it was handled.
        //
        // Actual delivery requires an external provider (Gupshup / MSG91 /
        // Twilio) — a commercial decision, not a code gap, so it is NOT
        // invented here. What IS fixed now is the false promise: the operator
        // is told plainly that the reminder was recorded but not delivered,
        // and pointed at the channels that genuinely work (push / manual).
        $undeliverable = ['whatsapp', 'sms', 'email'];

        if ($channel === 'push') {
            $msg = sprintf(
                'Push notifications: %d delivered, %d no-token, %d attempted.',
                $pushStats['delivered'], $pushStats['no_token'], $pushStats['attempted']
            );
        } elseif ($channel === 'log') {
            $msg = "Reminder logged for {$logged} student(s).";
        } elseif (in_array($channel, $undeliverable, true)) {
            $msg = ucfirst($channel) . " reminder RECORDED for {$logged} student(s), but NOT SENT — "
                 . 'no ' . strtoupper($channel) . ' provider is configured for this system. '
                 . 'Use the Push channel to notify parents in the app, or contact them directly.';
            log_message('info', sprintf(
                'FEE_REMINDER_UNDELIVERABLE school=%s channel=%s recorded=%d '
                . '(no external messaging provider configured)',
                $this->school_name, $channel, $logged
            ));
        } else {
            $msg = "Reminder recorded for {$logged} student(s).";
        }

        $this->json_success([
            'message'    => $msg,
            'logged'     => $logged,
            'channel'    => $channel,
            // Explicit, machine-readable honesty so the UI can style this as a
            // warning rather than a success and never render a false tick.
            'delivered'  => ($channel === 'push' || $channel === 'log'),
            'push_stats' => $channel === 'push' ? $pushStats : null,
        ]);
    }

    /**
     * GET — Fetch all reminder log entries.
     */
    public function fetch_reminder_log()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_reminder_log', 'Fees', 'view');
        $rows = $this->firebase->firestoreQuery('feeReminderLog', [
            ['schoolId', '==', $this->school_name],
        ], 'sent_date', 'DESC');
        $logs = [];
        foreach ((array) $rows as $row) {
            $entry = $row['data'] ?? $row;
            if (!is_array($entry)) continue;
            $entry['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            $logs[] = $entry;
        }

        $this->json_success(['logs' => $logs, 'total' => count($logs)]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PAYMENT GATEWAY (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch gateway configuration (secrets masked).
     */
    public function get_gateway_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_gateway_config', 'Fees', 'manage');
        $config = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        if (!is_array($config)) {
            $config = [
                'provider'       => '',
                'mode'           => 'test',
                'api_key'        => '',
                'api_secret'     => '',
                'active'         => false,
                'webhook_secret' => '',
            ];
        }

        // Mask secrets
        $masked = $config;
        if (!empty($masked['api_secret'])) {
            $len = strlen($masked['api_secret']);
            $masked['api_secret'] = $len > 4
                ? str_repeat('*', $len - 4) . substr($masked['api_secret'], -4)
                : str_repeat('*', $len);
        }
        if (!empty($masked['webhook_secret'])) {
            $len = strlen($masked['webhook_secret']);
            $masked['webhook_secret'] = $len > 4
                ? str_repeat('*', $len - 4) . substr($masked['webhook_secret'], -4)
                : str_repeat('*', $len);
        }

        $this->json_success(['config' => $masked]);
    }

    /**
     * POST — Save gateway configuration.
     * Params: provider, api_key, api_secret, mode, active, webhook_secret
     */
    /**
     * ── GW-TEST-1 · VERIFY THE STORED CREDENTIALS AGAINST THE PROVIDER ──────
     *
     * The gateway page has always had a "Test Connection" button. It was wired
     * to `showToast('Connection test not implemented yet')` — honest, but it
     * left the one question an operator actually has unanswerable: *are the keys
     * I saved correct?*
     *
     * That question is not academic. A wrong secret fails at ORDER CREATION,
     * which surfaces to a parent as a generic payment failure and looks
     * identical to a bank decline. The two have completely different remedies
     * and there was no way to tell them apart from inside the panel.
     *
     * This performs the cheapest authenticated call the provider offers and
     * reports only the OUTCOME. The stored secret is read, used, and never
     * returned — GW-SEC1 removed secrets from this page's responses and this
     * must not reintroduce them.
     *
     * ADMIN_ROLES, matching `gateway()` and `save_gateway_config()`: exercising
     * merchant credentials belongs with the roles that may change them.
     *
     * Deliberately NOT creating an order. A test order is a real object in the
     * merchant account, and a "test" button that quietly accumulates orders is
     * a button people stop trusting. A read is enough to prove the key pair
     * authenticates.
     */
    public function test_gateway_connection()
    {
        $this->_require_role(self::ADMIN_ROLES, 'test_gateway_connection', 'Fees', 'manage');

        $cfg = $this->firebase->firestoreGet(
            'feeSettings', "{$this->school_name}_{$this->session_year}_gateway"
        );
        if (!is_array($cfg)) {
            $this->json_error('No gateway is configured for this school yet.');
            return;
        }

        $provider = strtolower(trim((string) ($cfg['provider']   ?? '')));
        $mode     = strtolower(trim((string) ($cfg['mode']       ?? '')));
        $key      = trim((string) ($cfg['api_key']    ?? ''));
        $secret   = trim((string) ($cfg['api_secret'] ?? ''));

        // ── ASK THE CONFIGURED ADAPTER (2026-08-24) ─────────────────────────
        // This whole block was `if ($provider !== 'razorpay') { refuse }`
        // followed by an inline Razorpay API call. Honest at the time, but
        // structurally a dead end: a second ENABLED provider would be
        // untestable, and the refusal message ("has no adapter") became untrue
        // the moment a second adapter existed. The provider protocol now lives
        // on the adapter; everything below this call is provider-neutral.
        $__adapterClass = Payment_gateway_selector::adapter_class($provider);

        if ($provider === '') {
            $this->json_error('No provider is configured.');
            return;
        }
        if ($__adapterClass === null) {
            $stage = Payment_gateway_selector::provider_stage($provider);
            $this->json_error('"' . $provider . '" is not an enabled provider'
                . ($stage !== null ? ' (stage: ' . $stage . ')' : '')
                . ', so its credentials cannot be tested and it cannot collect money.');
            return;
        }
        if (!method_exists($__adapterClass, 'test_connection')) {
            // A legitimate state, not a fault: an adapter may have no cheap,
            // side-effect-free probe to offer. Say so plainly rather than
            // implying the credentials are bad.
            $this->json_error('Connection testing is not implemented for "' . $provider
                . '". This does not mean the credentials are wrong — only that this '
                . 'provider offers no safe way to check them without taking a payment.');
            return;
        }

        $__probe = (new $__adapterClass([
            'api_key' => $key, 'api_secret' => $secret,
        ]))->test_connection(['api_key' => $key, 'api_secret' => $secret, 'mode' => $mode]);

        if (empty($__probe['ok'])) {
            // Log with the same granularity as before — the KIND of failure is
            // what an operator needs, and it must not be flattened away.
            log_message('error', 'GW_TEST_FAILED school=[' . $this->school_name
                . '] provider=[' . $provider . '] kind=[' . (string) ($__probe['kind'] ?? '?')
                . '] mode=[' . $mode . '] keyPrefix=[' . substr($key, 0, 12) . ']');
            $this->json_error((string) ($__probe['error'] ?? 'The connection test failed.'));
            return;
        }

        // Webhook readiness is a SEPARATE question from credentials, and both
        // answers are needed before a payment can be verified end to end.
        $webhookSecret = trim((string) ($cfg['webhook_secret'] ?? ''));
        $warnings = [];
        if ($webhookSecret === '') {
            // ── STATE THE ACTUAL CONSEQUENCE, NOT THE ASSUMED ONE ───────────
            // The obvious wording is "the HMAC check is skipped", and it is
            // wrong. `webhook()` checks the PROVIDER first: while a real
            // provider is configured, an empty webhook secret is rejected with
            // HTTP 500 before the skip branch is ever reached. Webhooks are
            // therefore REFUSED, not accepted unverified.
            //
            // Reporting it the other way round sends an operator chasing a
            // security hole that does not exist, while missing the thing that
            // is actually true: the webhook confirmation path is dead.
            $warnings[] = 'No webhook secret is set. While a real provider is configured '
                        . 'without one, the webhook endpoint refuses every delivery '
                        . 'outright (HTTP 500) — so the webhook confirmation path is '
                        . 'inactive rather than unverified. Payments still complete over '
                        . 'the callback path, which is signed with the API secret, not '
                        . 'this one.';
        }
        // ── HOST-SCOPED, AND SAY SO ─────────────────────────────────────────
        // `base_url()` describes THIS server, but the gateway config lives in
        // Firestore and is shared by every host pointed at the same project —
        // localhost and the production site read the identical document.
        //
        // So "the webhook URL is localhost" is true of the machine running the
        // test and NOT of the deployment a parent actually pays through. Stating
        // it flatly would tell an operator webhooks can never work when the
        // production host's URL is perfectly reachable, which is the opposite
        // of useful.
        // ── W-1 · THIS ADVERTISED A PATH THAT DOES NOT EXIST ────────────────
        // Was `base_url('fee_management/webhook')`. There is no route for that
        // and no `Fee_management::webhook()` method — the real endpoint is
        // `payment_webhook` (routes.php: fee_management/payment_webhook).
        //
        // An operator copying this into the Razorpay dashboard registered a
        // 404. Nothing visibly broke, which is what made it dangerous: the
        // CALLBACK path is authoritative, so payments still completed while
        // webhook confirmation silently never worked. A diagnostic that hands
        // out a dead URL is worse than one that says nothing.
        //
        // `WEBHOOK_PATH` is the single source for this string; the route and
        // the method are asserted to exist by
        // GatewayWebhookUrlCorrespondenceTest.
        //
        // PAY-OPS-4: resolved against the canonical PUBLIC origin, not the
        // request host. The diagnostic and the Integration Info card must
        // advertise the same address, and it must be the one the provider can
        // actually reach in production — not the localhost/LAN/ngrok host the
        // admin happens to be testing from. See the gateway() call site.
        $this->load->helper('public_url');
        $webhookUrl = public_base_url(self::WEBHOOK_PATH);

        // The check now interrogates the CONFIGURED public origin rather than
        // the incidental request host. Reaching this branch means
        // PUBLIC_BASE_URL itself is still pointed somewhere a provider cannot
        // reach — which is a deployment misconfiguration, not merely "you are
        // testing locally", and it must be fixed before a school goes live.
        if (preg_match('~^https?://(localhost|127\.0\.0\.1|\[?::1\]?)~i', $webhookUrl)) {
            $warnings[] = 'The public webhook URL resolves to ' . $webhookUrl
                        . ' — the provider cannot reach that. This comes from PUBLIC_BASE_URL, '
                        . 'not from the host you are browsing. Set PUBLIC_BASE_URL to the '
                        . "school's live origin (default https://www.zenxii.com/) and register "
                        . "that URL in the provider's dashboard.";
        } elseif (preg_match('~^https?://[^/]*(ngrok|trycloudflare|loca\.lt|serveo|localtunnel)~i', $webhookUrl)) {
            // A tunnel is a legitimate DEV convenience and a production trap:
            // it works when registered and dies silently later, after which
            // every payment captures with no receipt and nothing reports it.
            $warnings[] = 'The public webhook URL resolves to a development tunnel (' . $webhookUrl
                        . '). Registering it in the provider dashboard works only while the tunnel '
                        . 'lives — once it expires, payments will be captured and no receipts will '
                        . 'be issued, with no error anywhere. Point PUBLIC_BASE_URL at the '
                        . 'production domain before any school collects money.';
        }

        $this->json_success([
            // The adapter's own wording — the core does not narrate a
            // provider it no longer knows the name of.
            'message'  => (string) ($__probe['message'] ?? 'Credentials accepted.'),
            'provider' => $provider,
            'mode'     => $mode,
            'warnings' => $warnings,
        ]);
    }
    public function save_gateway_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_gateway_config', 'Fees', 'manage');
        $provider      = trim($this->input->post('provider'));
        $apiKey        = trim($this->input->post('api_key'));
        $apiSecret     = trim($this->input->post('api_secret'));
        $mode          = trim($this->input->post('mode'));
        $active        = ($this->input->post('active') === '1'
                         || $this->input->post('active') === 'true') ? true : false;
        $webhookSecret = trim($this->input->post('webhook_secret'));

        // ── GW-1 · ONLY PROVIDERS THAT HAVE AN ADAPTER MAY BE SAVED ─────────
        // This list previously accepted 'stripe' and 'paytm'. Neither has an
        // adapter: _init_payment_service() matches only 'razorpay' and sends
        // EVERYTHING else to Payment_gateway_mock.
        //
        // The mock validates signatures against a secret that is literally in
        // this repository ('mock_secret_key_for_dev') and returns
        // mock_skip_amount_check => true, which disables amount enforcement in
        // Payment_service. So a school that saved 'stripe' would run live fee
        // collection on a gateway whose signature any reader of this source can
        // forge, with no amount check — receipts minted, demands cleared,
        // journals posted, and no money collected.
        //
        // Keeping the list in step with the adapters is the front-door half of
        // the fix; _init_payment_service() now also refuses rather than falling
        // back, so a config saved before this change cannot exploit it either.
        //
        // ── DERIVED, NOT DUPLICATED (2026-08-24) ────────────────────────────
        // This was a hand-maintained `['razorpay']` literal — a SECOND list
        // alongside the registry and the UI tiles. Three lists that must agree
        // by discipline is precisely how PA-UI-1 happened, and fixing the UI
        // tiles alone left the duplication in place.
        //
        // The catalogue in Payment_gateway_selector is now the only source of
        // truth: it decides what the UI renders, what may be saved here, and
        // which adapter can run. A provider that is merely IMPLEMENTED (an
        // adapter file exists, contract tests pass) is absent from all three
        // until it is explicitly promoted to ENABLED on evidence.
        $this->load->library('Payment_gateway_selector');
        $validProviders = Payment_gateway_selector::selectable_provider_ids(
            Payment_gateway_selector::effective_environment()
        );
        if ($provider !== '' && !in_array($provider, $validProviders, true)) {
            $stage = Payment_gateway_selector::provider_stage($provider);
            $this->json_error(
                'Unsupported payment provider "' . $provider . '". Supported: '
                . implode(', ', $validProviders) . '.'
                . ($stage !== null
                    // Naming the stage turns a flat refusal into something an
                    // operator can act on: "not yet certified" is a different
                    // conversation from "no such provider".
                    ? ' That provider is present but only at stage "' . $stage
                      . '" — it is not yet certified for live collection.'
                    : ' A provider without an adapter cannot collect money safely.')
            );
        }

        if (!in_array($mode, ['test', 'live'], true)) {
            $this->json_error('Mode must be "test" or "live".');
        }

        // Phase 3F — Razorpay key-prefix vs mode consistency check.
        // Razorpay enforces strict prefix conventions:
        //   rzp_live_*  ⇒ ONLY works against the live account
        //   rzp_test_*  ⇒ ONLY works against the test account
        // A misconfig (e.g., live key with mode='test') silently disables
        // HMAC enforcement on the webhook handler, allowing spoofed
        // payloads from anyone who knows the endpoint URL. Block at save.
        // Empty/legacy/partner keys pass through unchecked — Razorpay's
        // current docs lock these two prefixes; future formats may need
        // an updated guard.
        // ── ASK THE ADAPTER, DO NOT HARDCODE ITS RULES (2026-08-24) ─────────
        // This block used to be `if ($provider === 'razorpay')` followed by
        // Razorpay's own rzp_live_/rzp_test_ prefix rules, inline. Two
        // problems: provider protocol knowledge in the core, and an allowlist
        // that silently skips credential validation for every OTHER provider —
        // the same allowlist-of-one shape that disabled amount verification in
        // Admission_public.
        //
        // The rules now live on the adapter that knows them
        // (Payment_gateway_razorpay::validate_credentials). Duck-typed, like
        // parse_callback: an adapter without the method simply has no
        // provider-specific credential rule to enforce, which is a legitimate
        // state and not a reason to fail.
        $__adapterClass = Payment_gateway_selector::adapter_class($provider);
        if ($__adapterClass !== null && method_exists($__adapterClass, 'validate_credentials')) {
            $__verdict = (new $__adapterClass())->validate_credentials([
                'api_key' => $apiKey,
                'mode'    => $mode,
            ]);
            if (empty($__verdict['ok'])) {
                $this->json_error((string) ($__verdict['error'] ?? 'Invalid gateway credentials.'));
            }
        }

        // If secret contains only asterisks, preserve existing value
        $existingConfig = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        if (preg_match('/^\*+/', $apiSecret) && is_array($existingConfig) && !empty($existingConfig['api_secret'])) {
            $apiSecret = $existingConfig['api_secret'];
        }
        if (preg_match('/^\*+/', $webhookSecret) && is_array($existingConfig) && !empty($existingConfig['webhook_secret'])) {
            $webhookSecret = $existingConfig['webhook_secret'];
        }

        $now = date('Y-m-d H:i:s');

        // ── GW-SEC4 · RECORD A ROTATION SO THE GRACE WINDOW CAN ENGAGE ───────
        // Rotating the webhook secret strands every delivery already in flight:
        // those are signed with the OLD secret, fail HMAC, and the gateway stops
        // retrying. The payment succeeded — the notification is what is lost.
        //
        // Such an order sits in 'created'/'attempted', and retry_payment_processing
        // deliberately refuses anything that is not 'fees_failed' (it does not
        // re-verify with the gateway, so that status IS the verification
        // guarantee). Recovery is therefore not available, and prevention is the
        // only correct answer.
        //
        // The previous secret is carried forward with a timestamp; the verifier
        // accepts it only inside a bounded window and fails closed if the
        // timestamp is missing or unparseable, so the widened trust expires on
        // its own rather than depending on anyone clearing it.
        $__prevWebhookSecret = is_array($existingConfig) ? (string) ($existingConfig['webhook_secret'] ?? '') : '';
        $__rotating = ($__prevWebhookSecret !== '' && $__prevWebhookSecret !== $webhookSecret);

        if ($__rotating) {
            log_message('info', 'save_gateway_config: webhook secret ROTATED for school ['
                . $this->school_name . '] — previous secret honoured for a bounded grace window');
        }

        $configData = [
            'provider'       => $provider,
            'mode'           => $mode,
            'api_key'        => $apiKey,
            'api_secret'     => $apiSecret,
            'active'         => $active,
            'webhook_secret' => $webhookSecret,
            // Only written on an actual rotation; otherwise the existing values
            // are carried through unchanged so an unrelated save (mode toggle,
            // key update) never re-opens or extends the window.
            'webhook_secret_previous'   => $__rotating
                ? $__prevWebhookSecret
                : (is_array($existingConfig) ? (string) ($existingConfig['webhook_secret_previous'] ?? '') : ''),
            'webhook_secret_rotated_at' => $__rotating
                ? date('c')
                : (is_array($existingConfig) ? (string) ($existingConfig['webhook_secret_rotated_at'] ?? '') : ''),
            'updated_at'     => $now,
        ];

        // Preserve created_at if updating
        if (is_array($existingConfig) && !empty($existingConfig['created_at'])) {
            $configData['created_at'] = $existingConfig['created_at'];
        } else {
            $configData['created_at'] = $now;
        }

        $configData['schoolId'] = $this->school_name;
        $configData['session']  = $this->session_year;
        $configData['type']     = 'gateway';
        $this->firebase->firestoreSet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway", $configData);

        // ── GW-SEC3 · A CREDENTIAL CHANGE MUST LEAVE A TRACE ─────────────────
        // This handler previously wrote the tenant's payment credentials and
        // returned success with NO audit record and NO log line. A change to the
        // keys of a school's merchant account left nothing recording who made
        // it, when, or what moved.
        //
        // The concrete risk is not abstract: an account with ADMIN_ROLES that
        // swapped api_key/api_secret for its own Razorpay account would redirect
        // every subsequent parent payment, and there would be no record that the
        // configuration had ever changed. Detection would depend on someone
        // noticing money stopped arriving.
        //
        // Reuses Fee_audit, the same facility this controller already uses for
        // amount_mismatch — no new audit mechanism.
        //
        // WHAT IS RECORDED: which fields changed, the provider, the mode, and a
        // last-4 FINGERPRINT of each secret. NEVER the secret values — an audit
        // log is read far more widely than a credentials screen, and writing
        // them here would recreate GW-SEC1 in a place that is harder to notice.
        try {
            $__prev = is_array($existingConfig) ? $existingConfig : [];
            $__fp = static function ($v): string {
                $v = (string) $v;
                return $v === '' ? '(none)' : ('…' . substr($v, -4));
            };

            $__changed = [];
            foreach (['provider', 'mode', 'api_key', 'api_secret', 'webhook_secret', 'active'] as $__f) {
                if (($__prev[$__f] ?? null) !== ($configData[$__f] ?? null)) $__changed[] = $__f;
            }

            $this->load->library('Fee_audit', null, 'feeAudit');
            $this->feeAudit->init(
                $this->firebase, "{$this->sessionRoot}/Fees",
                $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                $this->school_name
            );
            $this->feeAudit->log('gateway_config_changed', [
                'school_id'         => $this->school_name,
                'session'           => $this->session_year,
                'changed_fields'    => $__changed,
                'provider_from'     => (string) ($__prev['provider'] ?? ''),
                'provider_to'       => $provider,
                'mode_from'         => (string) ($__prev['mode'] ?? ''),
                'mode_to'           => $mode,
                'active_to'         => $active,
                'api_key_to'        => $__fp($apiKey),          // fingerprint only
                'api_secret_to'     => $__fp($apiSecret),       // fingerprint only
                'webhook_secret_to' => $__fp($webhookSecret),   // fingerprint only
            ]);
        } catch (\Throwable $e) {
            // \Throwable (FEE-W1): a missing collaborator raises an Error, and
            // the configuration is already durable at this point — an audit
            // failure must not turn a saved config into an error response.
            log_message('error', 'save_gateway_config: audit write failed for school ['
                . $this->school_name . '] — ' . $e->getMessage());
        }

        $this->json_success(['message' => 'Gateway configuration saved successfully.']);
    }

    /**
     * GET/POST — Fetch online payment orders for the admin dashboard.
     *
     * Phase 1.X (2026-05-09) — collection + Pattern-B rewrite.
     * Was: queried `feeOnlinePayments` (which is mostly webhook event logs;
     *      sparse fields). Most table cells rendered empty. View also sent
     *      filter params (status, date_from, date_to) that backend ignored.
     * Now: queries `feeOnlineOrders` (canonical order lifecycle —
     *      reconciliation already reads from here). Explicitly transforms
     *      each Firestore doc into the shape the view expects:
     *        order_id     <- gateway_order_id
     *        payment_id   <- gateway_payment_id
     *        date         <- substr(created_at, 0, 10)
     *        student_name, class, amount, fee_months, gateway, status,
     *        created_at, paid_at, updated_at, receipt_key, method, notes
     *      Filters (status, date_from, date_to) honored in-memory after
     *      the query. Server-side stats included so the JS doesn't have
     *      to recount over a filtered window.
     *
     * Status filter mapping (view-pill -> Firestore status):
     *   paid     -> ['paid']
     *   created  -> ['created', 'verified']
     *   failed   -> ['fees_failed', 'failed']
     *   refunded -> ['refunded']
     */
    public function fetch_online_payments()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_online_payments', 'Fees', 'view');

        // Filters from view (POST). Empty / missing means no filter.
        $filterStatus = trim((string) ($this->input->post('status') ?? ''));
        $dateFrom     = trim((string) ($this->input->post('date_from') ?? ''));
        $dateTo       = trim((string) ($this->input->post('date_to') ?? ''));

        // Status pill -> Firestore status set
        static $statusMap = [
            'paid'     => ['paid'],
            'created'  => ['created', 'verified'],
            'failed'   => ['fees_failed', 'failed'],
            'refunded' => ['refunded'],
        ];
        $statusAllowed = ($filterStatus !== '' && isset($statusMap[$filterStatus]))
            ? $statusMap[$filterStatus]
            : null;

        $rows = $this->firebase->firestoreQuery('feeOnlineOrders', [
            ['schoolId', '==', $this->school_name],
        ], 'created_at', 'DESC');

        $payments = [];
        $stats = [
            'total' => 0, 'paid' => 0, 'created' => 0, 'failed' => 0,
            'refunded' => 0, 'total_amount' => 0.0,
        ];

        foreach ((array) $rows as $row) {
            $o = $row['data'] ?? $row;
            if (!is_array($o)) continue;

            $createdAt = (string) ($o['created_at'] ?? '');
            $oDate     = substr($createdAt, 0, 10); // YYYY-MM-DD prefix
            $status    = (string) ($o['status'] ?? 'created');

            // In-memory filter pass — date range + status pill.
            if ($dateFrom !== '' && $oDate !== '' && $oDate < $dateFrom) continue;
            if ($dateTo   !== '' && $oDate !== '' && $oDate > $dateTo)   continue;
            if ($statusAllowed !== null && !in_array($status, $statusAllowed, true)) continue;

            $item = [
                'id'           => $this->_stripSchoolPrefix($row['id'] ?? ''),
                'date'         => $oDate,
                'order_id'     => (string) ($o['gateway_order_id']   ?? $o['order_id']   ?? ''),
                'payment_id'   => (string) ($o['gateway_payment_id'] ?? $o['payment_id'] ?? ''),
                'student_id'   => (string) ($o['student_id']   ?? ''),
                'student_name' => (string) ($o['student_name'] ?? ''),
                'class'        => (string) ($o['class']        ?? $o['className'] ?? ''),
                'section'      => (string) ($o['section']      ?? ''),
                'amount'       => (float)  ($o['amount']       ?? 0),
                'fee_months'   => is_array($o['fee_months'] ?? null) ? $o['fee_months'] : [],
                // PA-CORE-1: defaulting an unrecorded gateway to 'razorpay'
                // painted every provenance-less order as Razorpay in this list
                // — precisely the condition GW-ORD-1 treats as unsafe to reuse,
                // displayed as though it were the safe case. 'unknown' is
                // visible; 'razorpay' was a lie of omission.
                'gateway'      => (string) ($o['gateway']      ?? 'unknown'),
                'status'       => $status,
                'created_at'   => $createdAt,
                'paid_at'      => (string) ($o['paid_at']      ?? ''),
                'updated_at'   => (string) ($o['updated_at']   ?? ''),
                'receipt_key'  => (string) ($o['receipt_key']  ?? ''),
                'method'       => (string) ($o['method']       ?? ''),
                'notes'        => (string) ($o['notes']        ?? ''),
            ];

            // Stats accumulation across the post-filter window.
            $stats['total']++;
            if ($status === 'paid') {
                $stats['paid']++;
                $stats['total_amount'] += $item['amount'];
            } elseif ($status === 'created' || $status === 'verified') {
                $stats['created']++;
            } elseif ($status === 'fees_failed' || $status === 'failed') {
                $stats['failed']++;
            } elseif ($status === 'refunded') {
                $stats['refunded']++;
            }

            $payments[] = $item;
        }

        $stats['total_amount'] = round($stats['total_amount'], 2);

        $this->json_success([
            'payments' => $payments,
            'total'    => count($payments),
            'stats'    => $stats,
        ]);
    }

    /**
     * Initialize the Payment Service with the appropriate gateway adapter.
     * Uses mock gateway for test mode, real gateway for live mode.
     */
    /**
     * T3 rate-limit wrapper. Returns TRUE when the caller was blocked
     * (and the 429 response has already been written); caller must
     * return immediately. Returns FALSE when the call is allowed and
     * the counter has been bumped.
     */
    private function _rate_limit_block(string $bucket, string $userKey): bool
    {
        try {
            $this->load->library('Rate_limiter', null, 'rateLimiter');
            $verdict = $this->rateLimiter->check(
                $this->firebase,
                $bucket,
                $userKey,
                (string) $this->input->ip_address(),
                /* max */      5,
                /* windowSec */ 60
            );
        } catch (\Throwable $e) {
            // Fail-open on limiter failure — legitimate parent payments
            // must not be blocked by a Firestore hiccup.
            log_message('error', "Rate_limiter fail-open for {$bucket}/{$userKey}: " . $e->getMessage());
            return false;
        }
        if (!empty($verdict['allowed'])) return false;

        http_response_code(429);
        header('Retry-After: ' . max(1, (int) $verdict['retryAfter']));
        echo json_encode([
            'success'     => false,
            'error'       => 'Too many requests. Please wait a moment and try again.',
            'code'        => 'RATE_LIMITED',
            'retryAfter'  => (int) $verdict['retryAfter'],
        ]);
        log_message('error', "[RATE_LIMIT] {$bucket} blocked for {$userKey} from " . $this->input->ip_address());
        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENT CHARGES — a one-off charge against ONE student
    // ══════════════════════════════════════════════════════════════════

    /**
     * Load the charge service, wired to this request's tenant.
     *
     * Fee_lifecycle is the canonical generator — the SAME one transport, hostel
     * and the ID-card replacement use. The charge service does not write a
     * demand itself; giving it the generator is what keeps a single billing
     * pipeline rather than a second one.
     */
    /**
     * True when the Student Charges feature is actually deployed here.
     *
     * ── WHY THIS EXISTS (release decoupling, 2026-08-26) ─────────────────
     * The payment programme and Student Charges live in the same controller,
     * so a payment release necessarily ships this file — and with it four new
     * public methods (student_charges, list_student_charges,
     * raise_student_charge, waive_student_charge). CodeIgniter falls back to
     * automatic controller/method routing, so those endpoints become reachable
     * even without shipping routes.php.
     *
     * That left two bad options: ship Student_charge_service and silently
     * ACTIVATE an unrelated workstream in production, or omit it and have four
     * endpoints raise a fatal the first time anyone touches them.
     *
     * This is the third option. The feature reports itself unavailable when
     * its service is not deployed, so a payment release carries the code
     * without switching the feature on, and nothing fatals. Where the service
     * IS deployed — as it is locally, where F1's ID Card Replacement charge
     * was raised — behaviour is completely unchanged.
     */
    private function _student_charges_available(): bool
    {
        return is_file(APPPATH . 'libraries/Student_charge_service.php');
    }

    /**
     * Refuse cleanly when the feature is not deployed. 503, not 404: the
     * endpoint exists and may return later, which is a different thing from
     * "no such endpoint" and matters to anyone reading access logs.
     */
    private function _refuse_if_student_charges_unavailable(): bool
    {
        if ($this->_student_charges_available()) return false;
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error'   => 'Student Charges is not enabled on this installation.',
        ]);
        return true;
    }

    private function _init_student_charge_service(): void
    {
        if (!isset($this->feeLifecycle)) {
            $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
            $this->feeLifecycle->init(
                $this->firebase, $this->school_name, $this->session_year,
                $this->admin_id ?? 'system'
            );
        }
        $this->load->library('Student_charge_service', null, 'studentCharge');
        $this->studentCharge->init(
            $this->firebase, $this->feeLifecycle,
            $this->school_name, $this->session_year, $this->admin_id ?? 'system'
        );
    }

    /**
     * Raise a one-off charge against one student — a lost library book, a
     * broken window, a trip contribution.
     *
     * FINANCE_ROLES + Fees:manage. Raising a charge against a family is a
     * financial act, not a data edit: it is the same authority level required
     * to waive one (below), because the ability to bill and the ability to
     * un-bill should not sit at different levels.
     */
    /**
     * ── STUDENT CHARGES — THE ADMIN SCREEN ─────────────────────────────────
     *
     * `raise_student_charge()`, `waive_student_charge()` and
     * `list_student_charges()` shipped with routes, tests and no way to reach
     * them. A feature with a live endpoint and no screen is not a feature: the
     * only way to raise a charge was to hand-craft a POST with a CSRF token.
     *
     * That is the reachability rule this codebase already learned once, when
     * `/compensation/fnf` 404'd while its tests passed. Tests prove the service
     * is correct; only a screen proves an operator can use it.
     *
     * VIEW role to open the page, FINANCE role to act — the endpoints enforce
     * their own permission independently, so this gate is for the navigation,
     * not the authority.
     */
    public function student_charges()
    {
        // Release decoupling: this endpoint ships with the payment
        // programme but must not switch the feature on where its service
        // is not deployed. See _student_charges_available().
        if ($this->_refuse_if_student_charges_unavailable()) return;

        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view', 'Fees', 'view');

        $schoolFs = $this->school_name;
        $students = [];

        try {
            // SESSION-SCOPED — the one-off-charge picker must only offer
            // students enrolled in the CURRENT session, never bleed in
            // students from another session after a rollover.
            $rows = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $schoolFs],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;

                // Only ACTIVE students may be charged. A withdrawn student's fee
                // book is frozen, and raising a new demand against it would be
                // immediately uncollectable — and, since FRZ-1, would not even
                // attract a late fee.
                $status = (string) ($d['Status'] ?? $d['status'] ?? '');
                if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;

                $sid = (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? ''));
                if ($sid === '') continue;

                $students[$sid] = [
                    'name'    => (string) ($d['name'] ?? $d['studentName'] ?? $d['Name'] ?? ''),
                    // SRNO in the picker label — the source doc is already loaded,
                    // so the permanent school register number costs no extra read
                    // and lets the operator pick the right child by SR No.
                    'sr_no'   => (string) ($d['srNo'] ?? $d['srNumber'] ?? $d['SR Number'] ?? ''),
                    'class'   => (string) ($d['className'] ?? $d['Class'] ?? ''),
                    'section' => (string) ($d['section'] ?? $d['Section'] ?? ''),
                ];
            }
            ksort($students);
        } catch (\Throwable $e) {
            // An empty picker is honest: it shows the operator there is nothing
            // to choose rather than a stale or partial roster.
            log_message('error', 'Fee_management::student_charges student load failed: ' . $e->getMessage());
            $students = [];
        }

        // ── SC-HEAD-1 · OFFER ONLY HEADS THAT CAN ACTUALLY BE CHARGED ───────
        // The first real attempt was refused with
        // `ACC_FEEHEAD_UNRESOLVED ... head=[Lost Book] reason=[not_found]`.
        // That refusal is correct — M5 fails closed so no anonymous financial
        // head can be minted at runtime — but the page had offered "Lost Book"
        // as a free-text suggestion, so it invited an operator to type
        // something the system was always going to reject.
        //
        // The picker is now populated from the registry itself. An operator can
        // only choose a head that will be accepted, and if the registry is
        // empty they are told that rather than being left to guess.
        $feeHeads = [];
        try {
            $this->load->library('Fee_head_resolver', null, 'feeHeadResolver');
            $this->feeHeadResolver->init($this->fs);
            $feeHeads = $this->feeHeadResolver->listForSchool($schoolFs);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_management::student_charges fee-head load failed: ' . $e->getMessage());
            $feeHeads = [];
        }

        $data = [
            'students'   => $students,
            'feeHeads'   => $feeHeads,
            'page_title' => 'Student Charges',
        ];

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/student_charges', $data);
        $this->load->view('include/footer');
    }
    public function raise_student_charge()
    {
        // Release decoupling: this endpoint ships with the payment
        // programme but must not switch the feature on where its service
        // is not deployed. See _student_charges_available().
        if ($this->_refuse_if_student_charges_unavailable()) return;

        $this->_require_role(self::FINANCE_ROLES, 'raise_student_charge', 'Fees', 'manage');

        $studentId = $this->safe_path_segment($this->_post('student_id'), 'student_id');
        $feeHead   = $this->_post('fee_head');
        $reason    = $this->_post('reason');
        $amountRaw = $this->_post('amount');

        if ($studentId === '') $this->json_error('A student is required.');
        if ($feeHead === '')   $this->json_error('A fee head is required.');
        if ($reason === '')    $this->json_error('A reason is required — the family must be told what this charge is for.');

        if (!is_numeric($amountRaw)) $this->json_error('Amount must be a number.');
        $amount = round((float) $amountRaw, 2);
        if ($amount <= 0) $this->json_error('A charge amount must be greater than zero.');

        // FRZ-1 ALIGNMENT — a withdrawn / TC / inactive student's fee book is
        // frozen: do not raise a NEW charge against it. The picker hides
        // non-Active students, but this endpoint is directly reachable, so the
        // status MUST be re-checked server-side (proven: a TC student could be
        // billed via a hand-crafted POST). Fail-OPEN only on a read error (no
        // regression vs the prior no-check behaviour); a resolved non-Active
        // status is refused.
        $stuStatus = null;
        try {
            $stuDoc = $this->firebase->firestoreGet('students', $this->fs->schoolId() . '_' . $studentId);
            if (is_array($stuDoc)) $stuStatus = (string) ($stuDoc['status'] ?? $stuDoc['Status'] ?? 'Active');
        } catch (\Throwable $e) {
            log_message('error', 'raise_student_charge status precheck failed for ' . $studentId . ': ' . $e->getMessage());
        }
        if ($stuStatus !== null && $stuStatus !== '' && strcasecmp($stuStatus, 'Active') !== 0) {
            $this->json_error('This student is marked "' . $stuStatus . '" — a withdrawn/inactive student\'s fee book is frozen; a new charge cannot be raised. Re-admit the student first if this is intended.');
        }

        // ── SC-LOAD-1 · THE CLASS MUST BE LOADED BEFORE ITS CONSTANT ────────
        // `Student_charge_service::DEFAULT_DUE_DAYS` was read on the line BELOW
        // this init call, so the very first real request to this endpoint died
        // with `Class "Student_charge_service" not found` — CI loads libraries
        // on demand, and a static constant access does not trigger that load.
        //
        // Every test drove the SERVICE directly with doubles, so none of them
        // went through CI's loader and none could see this. The endpoint had
        // never actually been executed.
        $this->_init_student_charge_service();

        // An optional payment window. The service defaults it; a caller may
        // shorten or lengthen it but never remove it (ID-DUE-1: a demand with
        // no due date is invisible to both the reminder service and the
        // late-fee engine).
        $dueDaysRaw = $this->_post('due_days');
        $dueDays    = is_numeric($dueDaysRaw) ? (int) $dueDaysRaw : Student_charge_service::DEFAULT_DUE_DAYS;

        $res = $this->studentCharge->raise([
            'studentId' => $studentId,
            'feeHead'   => $feeHead,
            'amount'    => $amount,
            'reason'    => $reason,
            'dueDays'   => $dueDays,
        ]);

        if (empty($res['ok'])) {
            $this->json_error((string) ($res['error'] ?? 'The charge could not be raised.'));
            return;
        }

        // Audited through the SAME facility as every other money event in this
        // controller (Fee_audit), so a charge raised against a family sits in
        // one trail with the payments and mismatches — not in a private log.
        try {
            $this->load->library('Fee_audit', null, 'feeAudit');
            $this->feeAudit->init(
                $this->firebase, "{$this->sessionRoot}/Fees",
                $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                $this->school_name
            );
            $this->feeAudit->log('student_charge_raised', [
                'student_id' => $studentId,
                'charge_id'  => (string) $res['chargeId'],
                'demand_id'  => (string) $res['demandId'],
                'fee_head'   => $feeHead,
                'amount'     => $amount,
                'reason'     => $reason,
            ]);
        } catch (\Throwable $e) {
            // \Throwable (FEE-W1): the charge is already billed by this point,
            // so an audit failure must not turn a raised charge into an error.
            log_message('error', 'raise_student_charge: audit write failed for charge ['
                . (string) $res['chargeId'] . ']: ' . $e->getMessage());
        }

        $this->json_success([
            'message'   => 'Charge of ' . number_format($amount, 2) . ' raised for this student.',
            'chargeId'  => $res['chargeId'],
            'demandId'  => $res['demandId'],
        ]);
    }

    /**
     * Waive an UNPAID charge.
     *
     * The service refuses a paid or partly-paid charge and says why — money has
     * already moved, so reversing it is a REFUND with its own authorization and
     * accounting reversal. This endpoint deliberately does not offer a way
     * around that.
     */
    public function waive_student_charge()
    {
        // Release decoupling: this endpoint ships with the payment
        // programme but must not switch the feature on where its service
        // is not deployed. See _student_charges_available().
        if ($this->_refuse_if_student_charges_unavailable()) return;

        $this->_require_role(self::FINANCE_ROLES, 'waive_student_charge', 'Fees', 'manage');

        $chargeId = $this->safe_path_segment($this->_post('charge_id'), 'charge_id');
        $reason   = $this->_post('reason');

        if ($chargeId === '') $this->json_error('A charge is required.');
        if ($reason === '')   $this->json_error('A reason is required to waive a charge.');

        $this->_init_student_charge_service();
        $res = $this->studentCharge->waive($chargeId, $reason);

        if (empty($res['ok'])) {
            $this->json_error((string) ($res['error'] ?? 'The charge could not be waived.'));
            return;
        }

        if (!empty($res['alreadyWaived'])) {
            $this->json_success(['message' => 'This charge was already waived.', 'chargeId' => $chargeId]);
            return;
        }

        try {
            $this->load->library('Fee_audit', null, 'feeAudit');
            $this->feeAudit->init(
                $this->firebase, "{$this->sessionRoot}/Fees",
                $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                $this->school_name
            );
            $this->feeAudit->log('student_charge_waived', [
                'charge_id' => $chargeId,
                'demand_id' => (string) ($res['demandId'] ?? ''),
                'reason'    => $reason,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'waive_student_charge: audit write failed for charge ['
                . $chargeId . ']: ' . $e->getMessage());
        }

        $this->json_success(['message' => 'Charge waived.', 'chargeId' => $chargeId]);
    }

    /**
     * List a student's charges — the history surface.
     *
     * Tenant-scoped by query, not by trusting a caller-supplied school.
     */
    /**
     * How much has been collected against a charge's demand.
     *
     * Best-effort and fails to ZERO deliberately: an unreadable demand must
     * not make a settled charge LOOK unpaid in a way that hides money. It can
     * only make an unpaid one look unpaid, which is the safe direction — and
     * the server-side waiver guard re-reads the demand itself before acting,
     * so this figure is for display only and is never an authority.
     */
    private function _chargePaidAmount(string $demandId): float
    {
        if ($demandId === '') return 0.0;
        try {
            $d = $this->firebase->firestoreGet('feeDemands', $demandId);
            if (!is_array($d)) return 0.0;
            return round((float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0), 2);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_management::_chargePaidAmount failed for [' . $demandId . ']: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function list_student_charges()
    {
        // Release decoupling: this endpoint ships with the payment
        // programme but must not switch the feature on where its service
        // is not deployed. See _student_charges_available().
        if ($this->_refuse_if_student_charges_unavailable()) return;

        $this->_require_role(self::VIEW_ROLES, 'list_student_charges', 'Fees', 'view');

        $studentId = $this->safe_path_segment($this->_post('student_id'), 'student_id');
        if ($studentId === '') $this->json_error('A student is required.');

        try {
            $rows = $this->firebase->firestoreQuery('studentCharges', [
                ['schoolId',  '==', $this->school_name],
                ['session',   '==', $this->session_year],
                ['studentId', '==', $studentId],
            ], null, 'ASC', 200);
        } catch (\Throwable $e) {
            log_message('error', 'list_student_charges failed for student [' . $studentId . ']: ' . $e->getMessage());
            $this->json_error('The charge list could not be read. Please retry.');
            return;
        }

        $out = [];
        foreach ((array) $rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
            $out[] = [
                'chargeId' => (string) ($d['chargeId'] ?? ''),
                'feeHead'  => (string) ($d['feeHead']  ?? ''),
                'amount'   => (float)  ($d['amount']   ?? 0),
                'reason'   => (string) ($d['reason']   ?? ''),
                'dueDate'  => (string) ($d['dueDate']  ?? ''),
                'status'   => (string) ($d['status']   ?? ''),
                'demandId' => (string) ($d['demandId'] ?? ''),
                'raisedBy' => (string) ($d['raisedBy'] ?? ''),
                'raisedAt' => (string) ($d['raisedAt'] ?? ''),
                // SC-PAID-1 — the payment lives on the DEMAND, never on the
                // charge record. Without it the panel could not tell a settled
                // charge from an unpaid one and offered Waive on both. The
                // server refuses a paid waiver either way (WAIVE-RACE-1), so
                // no money was ever at risk — but inviting an operator to do
                // something that cannot work is its own defect.
                'paidAmount' => $this->_chargePaidAmount((string) ($d['demandId'] ?? '')),
            ];
        }

        $this->json_success(['charges' => $out]);
    }

    /**
     * ── MONEY-1 · COMPARE CAPTURED AMOUNTS EXACTLY WHERE POSSIBLE ─────────
     *
     * Both amount-mismatch guards previously read:
     *
     *     abs($orderAmount - floatval($gatewayAmount)) > 0.50
     *
     * The ±₹0.50 window existed because both sides were rupee floats. But the
     * money never was a float: Razorpay settles in PAISE and returns an integer,
     * and `Payment_gateway_razorpay::fetch_payment()` already carries it as
     * `amount_paise` — it was simply thrown away in favour of a derived
     * `round($paise / 100, 2)`.
     *
     * So the tolerance was ~50x wider than the ≤₹0.01 error the rounding can
     * introduce, and a genuine capture shortfall of up to fifty paise per
     * payment counted as a match. Small per payment; a deliberate blind spot
     * across an estate; and entirely unnecessary, because an exact integer was
     * available the whole time.
     *
     * When the adapter supplies minor units the comparison is EXACT. When it
     * does not — a gateway that reports only a decimal, or a stub — the old
     * tolerant comparison still applies rather than failing a payment on a
     * representation the adapter cannot provide.
     *
     * Deliberately NOT a float→integer migration of the money model. Stored
     * demands, receipts, allocations, journals, the Firestore shape and the
     * Parent app's Kotlin models are untouched. Converting those is a much
     * larger question; this closes the defect without opening it.
     *
     * @param float      $expectedRupees  our order amount
     * @param mixed      $reportedRupees  gateway amount in rupees (float|null)
     * @param int|null   $reportedMinor   gateway amount in exact paise, if known
     */
    private function _gatewayAmountMismatch(float $expectedRupees, $reportedRupees, $reportedMinor): bool
    {
        if ($reportedMinor !== null && is_numeric($reportedMinor)) {
            // Exact. `round()` before the cast because (int)(6000 * 100) can be
            // 599999 for values a float cannot represent precisely.
            $expectedMinor = (int) round($expectedRupees * 100);
            return $expectedMinor !== (int) $reportedMinor;
        }

        // Fallback: the adapter cannot report minor units. Keep the historical
        // tolerance rather than fail a payment on a precision the gateway does
        // not expose.
        return abs($expectedRupees - floatval($reportedRupees)) > 0.50;
    }

    private function _init_payment_service(): void
    {
        if (isset($this->paymentService)) return; // already initialized

        // P4 (2026-08-24): resolves the NEW per-school, multi-provider
        // paymentProviders registry first, falling back to this exact legacy
        // read when the school has no registry entry — see
        // Payment_provider_config_resolver's own docblock for why this is
        // safe to wire in without migrating any live tenant.
        $this->load->library('Payment_provider_config_resolver');
        $gwConfig = Payment_provider_config_resolver::resolve(
            $this->firebase, $this->school_id, $this->school_name, $this->session_year
        );

        // Reported verbatim in the refusal log below. NOT defaulted to 'mock'
        // any more: that default was the old fallback leaking into the
        // diagnostics, and it made an absent configuration log as though the
        // school had actually chosen a simulator. `$mode` was read here and
        // never used, so it is gone rather than left to imply it gates anything.
        $provider = is_array($gwConfig)
            ? trim((string) ($gwConfig['provider'] ?? ''))
            : '';

        // ── GW-1 · AN UNRECOGNISED PROVIDER MUST NOT FALL BACK TO THE MOCK ──
        // This selection previously read: razorpay → real adapter, EVERYTHING
        // ELSE → Payment_gateway_mock. "Everything else" includes an unsupported
        // provider string AND a tenant with no gateway configuration at all
        // (the $provider default above is 'mock').
        //
        // The mock is not a neutral stand-in. It verifies signatures against
        // 'mock_secret_key_for_dev' — a constant in this repository, so anyone
        // who can read the source can mint a valid signature — and its
        // fetch_payment() returns mock_skip_amount_check => true, which makes
        // Payment_service skip amount enforcement entirely. Fee collection on
        // the mock therefore mints receipts, clears demands and posts journals
        // for money that was never paid.
        //
        // The admission module already got this right
        // (Admission_public.php:897 and :1126 refuse the mock outside
        // development/testing). Fees never adopted it. Same rule, applied here.
        //
        // Note the environment check is a SECOND gate, not the only one: the
        // primary protection is that an unrecognised provider now refuses
        // outright rather than silently degrading.
        // ── GW-SEL-1 · THE DECISION IS MADE BY A TESTABLE SELECTOR ──────────
        // This used to be decided inline, where no test could execute it — a CI
        // controller cannot be instantiated without the framework, so the only
        // available assertions were string matches against this source. Those
        // prove the code LOOKS right, not that it BEHAVES right, which is too
        // weak for the switch that decides whether money is collected through a
        // forgeable adapter. The decision is pure, so it now lives in
        // Payment_gateway_selector and is driven directly by tests.
        $this->load->library('Payment_gateway_selector');
        $selection = Payment_gateway_selector::select(
            $gwConfig,
            Payment_gateway_selector::effective_environment()
        );

        $adapter = null;

        if ($selection === Payment_gateway_selector::RAZORPAY) {
            // Razorpay adapter — test or live mode driven by the key prefix
            // (rzp_test_* vs rzp_live_*). Credentials are injected here.
            $__rzpParams = [
                'api_key'    => (string) ($gwConfig['api_key']    ?? ''),
                'api_secret' => (string) ($gwConfig['api_secret'] ?? ''),
            ];

            // ── PAY-OPS-4 · THE PROVIDER-API TEST SEAM ─────────────────────
            // The adapter has always accepted an `api_base` override (see its
            // constructor) and nothing ever supplied one, so the webhook's
            // authoritative-confirmation branch could not be exercised
            // end-to-end: proving "the provider says NOT captured" requires a
            // provider that can be made to say it, and the mock adapter answers
            // 'captured' unconditionally.
            //
            // ── WHY IT IS GATED, NOT JUST ADDED ────────────────────────────
            // An api_base read from a Firestore DOCUMENT would let anyone who
            // can write that document point ZenX's payment confirmation at a
            // server they control — i.e. forge "captured" for money that was
            // never paid. That is strictly worse than the bug this change
            // exists to fix.
            //
            // So it is honoured ONLY off a real deployment, reusing the
            // selector's SIMULATOR_ENVIRONMENTS rather than introducing a
            // second environment list (the duplication that caused PA-UI-1).
            // In production the value is ignored entirely and the adapter's
            // hardcoded https://api.razorpay.com/v1 stands — unset or set,
            // production behaviour is byte-identical. Same shape as the
            // FIRESTORE_EMULATOR_HOST seam in Firestore_rest_client.
            $__apiBase = trim((string) ($gwConfig['api_base'] ?? ''));
            if ($__apiBase !== '' && in_array(
                    Payment_gateway_selector::effective_environment(),
                    Payment_gateway_selector::SIMULATOR_ENVIRONMENTS, true)) {
                // Loopback only, even in development: a dev box that can be
                // pointed at an arbitrary remote "provider" is still a way to
                // manufacture a captured payment.
                if (preg_match('~^https?://(127\.0\.0\.1|localhost|\[?::1\]?)(:\d+)?(/|$)~i', $__apiBase)) {
                    $__rzpParams['api_base'] = $__apiBase;
                    log_message('error', 'Fee_management::_init_payment_service — provider API '
                        . 'base OVERRIDDEN to a loopback stub (environment='
                        . (defined('ENVIRONMENT') ? ENVIRONMENT : '?') . '). '
                        . 'This is a TEST SEAM and is ignored in production.');
                } else {
                    log_message('error', 'Fee_management::_init_payment_service — api_base '
                        . 'override REFUSED: only loopback addresses are accepted.');
                }
            }

            $this->load->library('Payment_gateway_razorpay', $__rzpParams);
            $adapter = $this->payment_gateway_razorpay;
        } elseif ($selection === Payment_gateway_selector::MOCK) {
            // Explicitly-chosen mock, and only off a real deployment. The
            // environment gate lives in the selector.
            $this->load->library('Payment_gateway_mock');
            $adapter = $this->payment_gateway_mock;
        }

        if ($adapter === null) {
            // Fail CLOSED. Refusing to collect is recoverable — the school
            // configures a supported gateway. Collecting through a forgeable
            // adapter is not: the books would show money that never arrived.
            log_message('error',
                'Fee_management::_init_payment_service — refusing to initialise: provider ['
                . ($provider !== '' ? $provider : 'UNSET')
                . '] configPresent[' . (is_array($gwConfig) ? 'yes' : 'no')
                . '] active[' . (is_array($gwConfig) && array_key_exists('active', $gwConfig)
                    ? var_export((bool) $gwConfig['active'], true) : 'absent')
                . '] env[' . (defined('ENVIRONMENT') ? ENVIRONMENT : 'undefined')
                . '] has no usable adapter for school [' . $this->school_name
                . ']. No payment can be created or verified until a supported gateway is configured.');
            $this->load->library('Payment_gateway_unavailable');
            $adapter = $this->payment_gateway_unavailable;
        }

        $this->load->library('Payment_service');
        $this->payment_service->init(
            $this->firebase,
            $this->feesBase,
            $adapter,
            $this->admin_id ?? '',
            $this->school_name,
            $this->session_year
        );
        $this->paymentService = $this->payment_service;
    }


    /**
     * Resolve {school, session} context for a webhook by looking up the
     * order doc via its gateway_order_id. Called from the controller
     * constructor BEFORE $this->school_name / $this->session_year are
     * known, so this method must avoid any lookup that requires school
     * context (uses firestoreQuery without a schoolId filter).
     *
     * Returns ['school' => ..., 'session' => ...] on success, or null if
     * the order doc is missing or malformed. Logs errors; caller decides
     * the HTTP response shape.
     */
    private function resolveSchoolFromOrder(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }
        try {
            $rows = $this->firebase->firestoreQuery(
                'feeOnlineOrders',
                [['gateway_order_id', '==', $orderId]],
                null, 'ASC', 1
            );
        } catch (\Exception $e) {
            log_message('error', "resolveSchoolFromOrder: query threw for order={$orderId}: " . $e->getMessage());
            return null;
        }

        $orderDoc = null;
        foreach ((array) $rows as $row) {
            $orderDoc = is_array($row['data'] ?? null) ? $row['data'] : $row;
            break;
        }
        if (!is_array($orderDoc)) {
            return null;
        }

        $schoolName = (string) ($orderDoc['schoolId'] ?? '');
        $session    = (string) ($orderDoc['session']  ?? '');
        if ($schoolName === '') {
            return null;
        }
        // If session isn't on the order doc (old data / mock gateway), fall
        // back to the school's currently-active session.
        if ($session === '') {
            $session = $this->_resolve_active_session($schoolName);
        }
        return ['school' => $schoolName, 'session' => $session];
    }

    /**
     * Resolve the active session for a school (used by parent-app API
     * endpoints + the webhook, which have no CI session).
     *
     * SC-0b (Session Convergence Phase 0b — 2026-06-02): Reads
     * schools/{id}.currentSession from Firestore as the sole authority.
     * Returns '' on missing/error — callers treat '' as a hard failure
     * and reject the request with HTTP 400. No fallback to legacy orphan
     * collection (schoolConfig/{name}_activeSession), no fallback to PHP
     * session, no synthetic derivation. Fail-closed per NEW-Q12.
     */
    private function _resolve_active_session(string $schoolName): string
    {
        if ($schoolName === '') return '';
        try {
            $doc = $this->firebase->firestoreGet('schools', $schoolName);
            if (is_array($doc) && !empty($doc['currentSession'])) {
                return (string) $doc['currentSession'];
            }
            log_message('error',
                "SC-0b: Fee_management::_resolve_active_session — schools/{$schoolName}.currentSession missing or empty");
        } catch (\Exception $e) {
            log_message('error',
                "SC-0b: Fee_management::_resolve_active_session FS read failed for {$schoolName}: " . $e->getMessage());
        }
        return '';
    }

    /**
     * POST — Parent-facing: create a Razorpay order for the authenticated
     * student. Authenticates via Firebase ID token (Bearer header). The
     * student_id is taken from the token's uid claim; callers do NOT
     * pass student_id, preventing cross-student access.
     *
     * Body: amount, fee_months[] (JSON or form-encoded)
     * Returns: {success, gateway_order_id, amount, api_key, provider, mode, currency, student_id}
     */
    public function parent_create_order()
    {
        header('Content-Type: application/json; charset=utf-8');

        $claims = $this->_parent_claims ?: [];
        $studentId   = (string) ($claims['uid'] ?? '');
        $studentName = '';

        // R1.1 freeze enforcement — block new orders if the active
        // session is closed for year-end rollover. NOT applied to
        // parent_verify_payment (verification of an already-captured
        // payment must always complete to avoid orphaning the receipt).
        $this->_abort_if_session_frozen();
        // L1.0 period-lock enforcement — block new orders if today's
        // date falls within a closed accounting period. Same
        // in-flight-verify exemption applies: parent_verify_payment
        // is not gated. The order has not yet been created with
        // Razorpay at this point, so a 423 here cleanly aborts before
        // any external state is mutated.
        $this->_abort_if_period_locked();

        // T3 — rate limit BEFORE any Firestore work. 5 req/min per
        // studentId+ip; unauthenticated requests (studentId empty) are
        // bucketed on IP alone so an anon attacker still hits the cap.
        if ($this->_rate_limit_block('parent_create_order', $studentId !== '' ? $studentId : 'anon')) return;

        // Parse body — accept JSON or form-encoded
        $raw = file_get_contents('php://input');
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        if (empty($body)) $body = $this->input->post() ?: [];

        $amount    = floatval($body['amount'] ?? 0);
        $feeMonths = $body['fee_months'] ?? [];
        if (!is_array($feeMonths)) $feeMonths = [];

        // P9-DEBUG
        log_message('error', '[P9-DEBUG parent_create_order] raw=' . substr((string)$raw, 0, 400)
            . '  | post=' . json_encode($this->input->post())
            . '  | amount=' . var_export($amount, true)
            . '  | feeMonths=' . json_encode($feeMonths)
            . '  | studentId=' . $studentId
            . '  | contentType=' . ($this->input->server('CONTENT_TYPE') ?? 'none'));

        if ($studentId === '') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid auth token.']);
            return;
        }
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero.']);
            return;
        }
        if (empty($feeMonths)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'At least one fee month must be selected.']);
            return;
        }

        // Look up student profile to get name + class/section for the order record
        $class = '';
        $section = '';
        try {
            $studentDoc = $this->firebase->firestoreGet('students', "{$this->school_name}_{$studentId}");
            if (is_array($studentDoc)) {
                $studentName = (string) ($studentDoc['name']     ?? $studentDoc['studentName'] ?? '');
                $class       = (string) ($studentDoc['class']    ?? $studentDoc['className']   ?? '');
                $section     = (string) ($studentDoc['section']  ?? '');
            }
        } catch (\Exception $e) { /* non-fatal — order still gets created */ }

        // ── Sequencing guard: reject the order if any earlier month is
        // still unpaid/partial. Saves the parent a trip through Razorpay
        // just to hit the same block at verify time.
        if (!isset($this->fs)) {
            $this->load->library('Firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
        }
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);

        // ── B-027 (2026-08-22): STRICT read — two SAFETY guards depend on this ──
        //
        // This demand set drives the PAY-OLDER-FIRST guard and the overpayment
        // guard below. `demandsForStudent()` swallows a Firestore error and
        // returns [], which made both guards operate on an empty set: the
        // pay-older-first loop is skipped entirely, and $totalUnpaidSelected
        // computes to 0.00 so every order is refused as
        //     "Amount Rs. X exceeds total due Rs. 0.00"
        //
        // Fail-closed, and therefore safe — but it reports a transient read
        // failure as an overpayment, which is simply untrue and sends the parent
        // to support instead of telling them to retry.
        //
        // Refusing here is FREE: no order exists at the gateway yet and no money
        // has moved, so there is nothing to unwind.
        try {
            $allDemands = $this->fsTxn->demandsForStudentStrict($studentId);
        } catch (\RuntimeException $e) {
            log_message('error',
                'parent_create_order: demand set unreadable for student [' . $studentId . '] — '
                . $e->getMessage() . ' — order REFUSED');
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error'   => 'We could not read your fee record just now. No payment has been started. '
                           . 'Please retry in a moment.',
                'retryable' => true,
            ]);
            return;
        }
        $selectedKeys = [];
        foreach ($allDemands as $d) {
            $pk = (string) ($d['period_key'] ?? '');
            $period = Fee_firestore_txn::periodToMonth((string) ($d['period'] ?? ''));
            if ($pk !== '' && in_array($period, $feeMonths, true)) {
                $selectedKeys[] = $pk;
            }
        }
        if (!empty($selectedKeys)) {
            sort($selectedKeys);
            $earliestSelected = $selectedKeys[0];
            foreach ($allDemands as $d) {
                $pk = (string) ($d['period_key'] ?? '');
                $status = (string) ($d['status'] ?? 'unpaid');
                $bal = (float) ($d['balance'] ?? 0);
                if ($pk !== '' && $pk < $earliestSelected && $status !== 'paid' && $bal > 0.005) {
                    $olderPeriod = (string) ($d['period'] ?? $pk);
                    http_response_code(409);
                    echo json_encode([
                        'success' => false,
                        'error'   => "Please clear the earlier pending fees for {$olderPeriod} (Rs. " . number_format($bal, 2) . ") before paying this month.",
                    ]);
                    return;
                }
            }
        }

        // ── Overpayment guard (Phase 9 — wallet removed) ────────────────
        // Reject at order-creation so the parent doesn't go through
        // Razorpay only to be rejected at verify. Service re-checks.
        $totalUnpaidSelected = 0.0;
        foreach ($allDemands as $d) {
            $status = (string) ($d['status'] ?? 'unpaid');
            $period = Fee_firestore_txn::periodToMonth((string) ($d['period'] ?? ''));
            if ($period === '' || $status === 'paid') continue;
            if (!in_array($period, $feeMonths, true)) continue;
            $bal = (float) ($d['balance'] ?? 0);
            if ($bal > 0) $totalUnpaidSelected += $bal;
        }
        $totalUnpaidSelected = round($totalUnpaidSelected, 2);
        if (round($amount, 2) > $totalUnpaidSelected + 0.005) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error'   => 'Amount Rs. ' . number_format($amount, 2) . ' exceeds total due Rs. ' . number_format($totalUnpaidSelected, 2) . ' for the selected months.',
                'total_due' => $totalUnpaidSelected,
            ]);
            return;
        }

        $this->_init_payment_service();

        try {
            $result = $this->paymentService->create_order([
                'student_id'   => $studentId,
                'student_name' => $studentName,
                'class'        => $class,
                'section'      => $section,
                'amount'       => $amount,
                'fee_months'   => $feeMonths,
            ]);

            $this->load->library('Payment_provider_config_resolver'); $gwConfig = Payment_provider_config_resolver::resolve($this->firebase, $this->school_id, $this->school_name, $this->session_year); // P4

            // ── P5 (2026-08-24) · CAPABILITY-DRIVEN CHECKOUT CONTRACT ───────
            // The provider that ACTUALLY minted this order is $result['gateway']
            // — Payment_service wrote it from the live adapter's get_name().
            // Reading it from the config instead could disagree with reality
            // (a config changed between order creation and this response), and
            // the app would then open the wrong checkout for a real order.
            // Same PA-CORE-1 rule: provenance is observed, never re-derived.
            //
            // The old `?? 'mock'` default is REMOVED for exactly that reason —
            // it told the Parent App "mock" whenever config was unreadable,
            // which is a fabricated provider identity, not a safe default.
            $__providerId = (string) ($result['gateway'] ?? '');
            $__caps = [];
            $__adapterCls = Payment_gateway_selector::adapter_class($__providerId);
            if ($__adapterCls !== null && method_exists($__adapterCls, 'capabilities')) {
                $__caps = (new $__adapterCls())->capabilities();
            }

            echo json_encode([
                'success'          => true,
                'existing'         => !empty($result['existing']),
                'payment_id'       => $result['payment_record_id'],
                'gateway_order_id' => $result['order_id'],
                'amount'           => $result['amount'],
                'amount_paise'     => (int) round($result['amount'] * 100),
                'currency'         => 'INR',
                'gateway'          => $result['gateway'],
                'provider'         => $__providerId,
                'api_key'          => is_array($gwConfig) ? ($gwConfig['api_key']  ?? '') : '',
                'mode'             => is_array($gwConfig) ? ($gwConfig['mode']     ?? 'test') : 'test',
                'student_id'       => $studentId,
                'student_name'     => $studentName,
                'school_id'        => $this->school_name,

                // ── ADDITIVE: the normalized checkout contract (P5/P6) ──────
                // Purely additional keys. Every field above is unchanged, so
                // an older Parent App build that knows nothing about these
                // continues to work byte-for-byte as before — the backward
                // compatibility this gate requires. A newer build dispatches
                // on `checkout_type` instead of assuming Razorpay's SDK.
                //
                // NEVER includes api_secret or webhook_secret. `api_key` above
                // is the provider's PUBLIC key, which is what the client SDK
                // legitimately needs and is already what this endpoint sent.
                'checkout_type'    => $__caps['checkout_type'] ?? null,
                'capabilities'     => $__caps === [] ? null : [
                    'cards'       => (bool) ($__caps['cards']       ?? false),
                    'upi'         => (bool) ($__caps['upi']         ?? false),
                    'net_banking' => (bool) ($__caps['net_banking'] ?? false),
                    'wallets'     => (bool) ($__caps['wallets']     ?? false),
                ],
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST — Parent-facing: verify a Razorpay payment and record fees.
     *
     * Runs the FULL receipt + demand-allocation pipeline (same shape as
     * the admin counter's submit_fees). The legacy _verify_and_process
     * writes only a bare receipt and skips demand updates, so we do our
     * own flow here.
     *
     * Body: razorpay_order_id, razorpay_payment_id, razorpay_signature
     */
    public function parent_verify_payment()
    {
        header('Content-Type: application/json; charset=utf-8');

        // T3 — rate limit. studentId comes from verified Firebase ID
        // token (set by Api_auth upstream); unauthenticated callers
        // fall back to IP-only bucketing.
        $claimsForLimit = $this->_parent_claims ?: [];
        $studentForLimit = (string) ($claimsForLimit['uid'] ?? 'anon');
        if ($this->_rate_limit_block('parent_verify_payment', $studentForLimit)) return;

        $raw = file_get_contents('php://input');
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        if (empty($body)) $body = $this->input->post() ?: [];

        // P2 (2026-08-24): NOT routed through the adapter/registry here,
        // deliberately. Doing so would need the gateway config a second time
        // — this parse runs BEFORE _init_payment_service() reads it below,
        // and reordering that call is a real-enough risk to a live money-path
        // endpoint (untraced side effects earlier in this method) that it is
        // not taken for a protocol-parsing convenience. The extraction DID
        // happen — Payment_gateway_razorpay::parse_callback() and
        // Payment_service::parse_callback() both exist, are tested, and are
        // used at the webhook site below, which already reads this same
        // config for its own reasons and pays no extra Firestore read to use
        // them. This site keeps the original inline parse unchanged.
        $gwOrderId   = trim((string) ($body['razorpay_order_id']   ?? $body['gateway_order_id']   ?? ''));
        $gwPaymentId = trim((string) ($body['razorpay_payment_id'] ?? $body['gateway_payment_id'] ?? ''));
        $signature   = trim((string) ($body['razorpay_signature']  ?? $body['signature']          ?? ''));

        if ($gwOrderId === '' || $gwPaymentId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing order_id or payment_id.']);
            return;
        }

        $claims    = $this->_parent_claims ?: [];
        $studentId = (string) ($claims['uid'] ?? '');
        if ($studentId === '') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid auth token.']);
            return;
        }

        log_message('debug', "[VERIFY START] order={$gwOrderId} payment={$gwPaymentId} student={$studentId}");
        $_verifyT0 = microtime(true);

        // ── Idempotency check FIRST: have we already processed this exact
        // razorpay_payment_id? Razorpay can send the same payment_id on
        // network retries; without this lookup we'd re-run the receipt
        // pipeline and double-credit the demands. The previous version
        // checked feeOnlineOrders.status='paid' which left a window where
        // the order was marked paid but Firestore receipt/defaulter sync
        // had failed — the retry would early-return success but leave
        // stale dues forever. The payment-id lookup catches both cases:
        // (a) genuine retry of a fully-completed payment → return cached
        //     receipt info; (b) retry of a partially-completed payment →
        //     marker is missing, fall through and re-process.
        if (!isset($this->fs)) {
            $this->load->library('Firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
        }
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);

        $alreadyProcessed = $this->fsTxn->getProcessedPayment($gwPaymentId);
        if (is_array($alreadyProcessed)) {
            // Cross-student guard on the cached marker too — a leaked
            // payment_id from one parent shouldn't return another's
            // receipt.
            if (($alreadyProcessed['studentId'] ?? '') !== $studentId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Payment does not belong to this student.']);
                return;
            }
            log_message('debug', "[VERIFY IDEMP HIT] payment={$gwPaymentId} cached_receipt=" . ($alreadyProcessed['receiptNo'] ?? ''));
            echo json_encode([
                'success'     => true,
                'already_paid'=> true,
                'message'     => 'Payment already verified.',
                'receipt_no'  => (string) ($alreadyProcessed['receiptNo'] ?? ''),
                'receipt_key' => (string) ($alreadyProcessed['receiptKey'] ?? ''),
            ]);
            return;
        }

        // ── Look up our order record (Firestore feeOnlineOrders) ────────
        $orderDocId = "{$this->school_name}_{$gwOrderId}";
        try {
            $order = $this->firebase->firestoreGet('feeOnlineOrders', $orderDocId);
        } catch (\Exception $e) {
            $order = null;
        }
        if (!is_array($order)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            return;
        }

        // Cross-student guard
        if (($order['student_id'] ?? '') !== $studentId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Order does not belong to this student.']);
            return;
        }

        // ── Verify Razorpay signature ────────────────────────────────
        $this->_init_payment_service();
        $verifyResult = $this->paymentService->verify_payment($gwOrderId, $gwPaymentId, $signature);
        if (empty($verifyResult['verified'])) {
            log_message('error', "[VERIFY SIG FAIL] payment={$gwPaymentId} reason=" . ($verifyResult['error'] ?? 'unknown'));
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $verifyResult['error'] ?? 'Signature verification failed.']);
            return;
        }
        log_message('debug', "[VERIFY SIG OK] payment={$gwPaymentId} elapsed=" . round((microtime(true) - $_verifyT0) * 1000) . "ms");

        // ── BUG-050 / PS-3: amount-mismatch enforcement (parent path) ────
        // Mirrors the webhook-path guard at _verify_and_process (~line 3850).
        // Without this check the parent app could mark a payment paid with
        // a locally-tampered order.amount that doesn't match what Razorpay
        // actually captured. Policy: fail-CLOSED on populated mismatch;
        // fail-OPEN on fetch unavailable (sig already valid) with telemetry
        // gated by gateway name to keep mock-mode quiet.
        $orderAmountParent      = floatval($order['amount'] ?? 0);
        $gwReportedAmountParent = $verifyResult['gateway_amount'] ?? null;
        $gatewayNameParent      = (string) ($verifyResult['gateway_name'] ?? ($order['gateway'] ?? 'unknown')); // P11: no fabricated provider default

        // GW-2: the test below was `=== 'razorpay'`, i.e. an ALLOWLIST of one.
        // Its stated purpose is to keep mock-mode quiet, but as written it also
        // silences every provider that is not Razorpay — so the day a second
        // adapter is added, an amount-verification outage on it produces no
        // telemetry and no log. The unknown case should be the LOUD one.
        //
        // Inverted to a denylist of adapters that genuinely cannot report a
        // captured amount. Anything else — including a provider added later —
        // is reported by default.
        if ($gwReportedAmountParent === null) {
            // Capability first (single source of truth: the adapter itself),
            // falling back to the name denylist only when an adapter does not
            // declare capabilities at all. `null` means "did not say", which is
            // NOT the same as "said no".
            $__reportsAmt = $verifyResult['gateway_reports_amount'] ?? null;
            $__shouldAlert = $__reportsAmt === null
                ? !in_array($gatewayNameParent, self::AMOUNTLESS_GATEWAYS, true)
                : $__reportsAmt;
            if ($__shouldAlert) {
                if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                    $this->sec_telem->emit('PAYMENT_AMOUNT_UNVERIFIED', 'warning', [
                        'endpoint'     => 'parent_verify_payment',
                        'order_id'     => $gwOrderId,
                        'payment_id'   => $gwPaymentId,
                        'order_amount' => $orderAmountParent,
                    ]);
                }
                log_message('error', "parent_verify_payment: gateway_amount UNAVAILABLE order={$gwOrderId} — proceeding on signature-only");
            }
            // Fall through; signature already validated.
        } elseif ($this->_gatewayAmountMismatch(
            $orderAmountParent, $gwReportedAmountParent,
            $verifyResult['gateway_amount_minor'] ?? null)) {
            log_message('error', "parent_verify_payment: AMOUNT MISMATCH order={$gwOrderId} expected={$orderAmountParent} got={$gwReportedAmountParent}");
            try {
                $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                    'status'          => 'amount_mismatch',
                    'expected_amount' => $orderAmountParent,
                    'gateway_amount'  => $gwReportedAmountParent,
                    'flagged_at'      => date('c'),
                    'flagged_source'  => 'parent_verify_payment',
                ], true);
            } catch (\Exception $e) {
                log_message('error', 'parent_verify_payment: order flag write failed: ' . $e->getMessage());
            }
            try {
                $this->load->library('Fee_audit', null, 'feeAudit');
                $this->feeAudit->init(
                    $this->firebase, "{$this->sessionRoot}/Fees",
                    $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                    $this->school_name
                );
                // Firestore audit + critical alert (amount_mismatch ∈ ALERT_EVENTS). No RTDB.
                $this->feeAudit->log('amount_mismatch', [
                    'order_id'   => $gwOrderId,
                    'payment_id' => $gwPaymentId,
                    'student_id' => $studentId,
                    'expected'   => $orderAmountParent,
                    'gateway'    => $gwReportedAmountParent,
                    'source'     => 'parent_verify_payment',
                ]);
            } catch (\Exception $e) {
                log_message('error', 'parent_verify_payment: Fee_audit emit failed: ' . $e->getMessage());
            }
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('PAYMENT_AMOUNT_MISMATCH', 'critical', [
                    'endpoint'   => 'parent_verify_payment',
                    'order_id'   => $gwOrderId,
                    'payment_id' => $gwPaymentId,
                    'expected'   => $orderAmountParent,
                    'gateway'    => $gwReportedAmountParent,
                ]);
            }
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Payment amount does not match order amount.']);
            return;
        }

        // Signature is valid — now do the full fee allocation pipeline.
        $feeMonths = is_array($order['fee_months'] ?? null) ? $order['fee_months'] : [];
        $paidAmount = round((float) ($order['amount'] ?? 0), 2);

        // ── Delegate receipt pipeline to FeeCollectionService (P3) ────
        // Upstream of this point we've already done auth, dedup,
        // signature verify, amount re-derivation from the stored order,
        // cross-student guard. The service runs the (now-shared)
        // reserveReceipt → PAY-OLDER-FIRST → allocate → batch-commit →
        // wallet-credit → release-lock sequence. Razorpay-specific
        // post-processing (markPaymentProcessed, order.paid, pending
        // fallback) stays here so the service never has to know about
        // feeProcessedPayments or feeOnlineOrders.
        // ── B-025 · THIS PATH RACES THE WEBHOOK AND MUST TAKE THE CLAIM ──────
        // The claim was originally added only to _verify_and_process(), on the
        // strength of that method's own docblock ("Called by BOTH verify_payment
        // AND payment_webhook"). That is true — but `verify_payment()` (L3855)
        // is the admin/legacy endpoint. The PARENT APP posts to
        // `parent_verify_payment`, which is THIS method, and it has its own
        // submit() and never reaches the shared core.
        //
        // So the race that actually happens in production — the parent's
        // Razorpay callback against the webhook Razorpay fires at the same
        // moment — ran with no order-level exclusivity at all. The only thing
        // between them was FeeCollectionService's inline per-student lock, which
        // is itself read-then-blind-set (REF-1b) and therefore not exclusive.
        //
        // Same claim, same document, same lease as the webhook side, so the two
        // paths now contend for one order-level owner.
        $this->load->library('Fee_payment_claim', null, 'paymentClaim');
        $this->paymentClaim->init($this->firebase, $this->school_name);

        $__claim = $this->paymentClaim->acquire($gwOrderId, 'parent-callback');
        if ($__claim['status'] !== Fee_payment_claim::ACQUIRED) {
            // IN_PROGRESS → the webhook is applying this very payment.
            // UNKNOWN     → ownership undetermined; applying could double-apply.
            // Neither may proceed. The parent app polls its fee state after a
            // payment, and the webhook completes the application regardless, so
            // refusing here loses nothing.
            log_message('info',
                "parent_verify_payment: order [{$gwOrderId}] not claimable ({$__claim['status']}: "
                . "{$__claim['reason']}) — deferring to the process that owns it");
            http_response_code(202);
            echo json_encode([
                'success'   => false,
                'pending'   => true,
                'retryable' => true,
                'error'     => 'This payment is being processed. Your fee balance will update shortly.',
            ]);
            return;
        }

        require_once APPPATH . 'services/FeeCollectionService.php';
        $service = new FeeCollectionService();
        $result = $service->submit($this, [
            'source'                     => 'parent-razorpay',
            'admin_id'                   => 'parent_app',
            'admin_name'                 => 'parent_app',
            'school_name'                => $this->school_name,
            'session_year'               => $this->session_year,
            'safe_user_id'               => $studentId,
            'amount'                     => $paidAmount,
            'discount'                   => 0.0,
            'fine'                       => 0.0,
            'submit_amount'              => 0.0,
            'selected_months'            => $feeMonths,
            'payment_mode'               => 'Online',   // ACC-PM1: canonical, provider-neutral
            'remarks'                    => "Razorpay {$gwPaymentId}",
            'collected_by'               => 'parent_app',
            'created_by'                 => 'parent_app',
            // Preserve Razorpay's 6-hex txn_id format byte-for-byte.
            'txn_id'                     => 'RZP_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
            'batch_mode'                 => true,
            // Razorpay's parent_create_order enforces PAY-OLDER-FIRST
            // upstream, and the service re-runs it (belt-and-braces).
            // skip_pay_older_first_guard stays at its default (false).
            // Pre-P9, overpayment pushed overflow to the wallet; that
            // subsystem is gone and the service now rejects overpayment.
            'skip_duplicate_month_guard' => true,
            // H1-F1: carries the gateway identity into the money batch so the
            // processedPayments marker commits ATOMICALLY with the receipt.
            // Without it, this path writes its marker only after the batch, and
            // a crash in between leaves a durable receipt that nothing records
            // as applied — the gateway's retry would then mint a second one.
            'extra_receipt_fields'       => [
                'orderId'   => $gwOrderId,
                'paymentId' => $gwPaymentId,
                // ACC-PM1: provider identity lives HERE, not in payment_mode.
                // payment_mode is an accounting classification consumed by
                // Operations_accounting to choose the cash/bank ledger; it must
                // stay provider-neutral. This field keeps the provenance the
                // old 'Online - Razorpay' string was carrying implicitly.
                //
                // PA-CORE-1 (2026-08-24): this used to be the LITERAL string
                // 'razorpay', regardless of which adapter actually processed
                // the payment. $gatewayNameParent is computed a few lines above
                // FROM Payment_service::verify_payment()'s own get_name() call
                // — the same fact GW-ORD-1 trusts for order-reuse provenance —
                // with the stored order's gateway as a second-line fallback. A
                // constant here would make this field lie the moment a second
                // provider exists, and would keep lying silently.
                'gateway'   => $gatewayNameParent,
            ],
            'defer_defaulter'            => true,
            // INVARIANT: defer_accounting MUST stay true for every
            // fee-payment entry point. FeeCollectionService has no
            // inline JE-post path — create_fee_journal is only invoked
            // from the deferred shutdown handler (FeeCollectionService.php
            // ~line 1639, inside register_shutdown_function). When
            // defer_accounting=false the shutdown handler skips the
            // accounting block silently and no JE is ever posted,
            // producing a receipt with no GL entry (a true accounting
            // orphan). Historical incident: receipt F11 on 2026-05-22.
            // Until an inline JE-post path exists, every caller of
            // FeeCollectionService::submit must either omit this key
            // (default true) or pass true explicitly. Other callers
            // already comply (admin counter, webhook, wallet/manual).
            'defer_accounting'           => true,
            'write_response_to_output'   => false,
        ]);

        // Service contract: returns an array. ok=true + data{} on success,
        // ok=false + message + http_code on any failure past the shim's
        // upstream validation. A null return indicates an internal bug.
        if (!is_array($result) || !($result['ok'] ?? false)) {
            $errMsg = is_array($result) ? (string) ($result['message'] ?? 'Payment processing failed.') : 'Internal error (null service result).';
            // Razorpay has already captured the money but our receipt
            // pipeline failed (Firestore quota, transient outage, etc.).
            // Drop a feePendingWrites marker so the admin reconciliation
            // cron picks it up and replays the receipt write later, and
            // tell the parent app to render a soft "syncing" banner
            // instead of a red error — the payment is safe.
            try {
                $this->firebase->firestoreSet('feePendingWrites',
                    "{$this->school_name}_pay_{$gwPaymentId}",
                    [
                        'schoolId'    => $this->school_name,
                        'session'     => $this->session_year,
                        'studentId'   => $studentId,
                        'paymentId'   => $gwPaymentId,
                        'orderId'     => $gwOrderId,
                        'amount'      => $paidAmount,
                        'feeMonths'   => $feeMonths,
                        'status'      => 'pending',
                        'lastError'   => $errMsg,
                        'failedAt'    => date('c'),
                        'updatedAt'   => date('c'),
                    ]
                );
            } catch (\Exception $e) {
                log_message('error', "parent_verify_payment: failed to mark pending [{$gwPaymentId}]: " . $e->getMessage());
            }
            // B-025: this attempt did NOT apply the payment, so free the order
            // immediately. The webhook is the path that will complete it, and
            // making it wait out a 120s lease for no reason would delay the
            // parent's balance update.
            $this->paymentClaim->release($gwOrderId, $__claim['token']);

            http_response_code(202); // Accepted — being processed
            echo json_encode([
                'success' => false,
                'pending' => true,
                'error'   => 'Payment received and being processed. Please refresh in a minute.',
                'details' => $errMsg,
            ]);
            return;
        }

        $resultData  = $result['data'] ?? [];
        $receiptNo   = (string) ($resultData['receipt_no']  ?? '');
        $receiptKey  = (string) ($resultData['receipt_key'] ?? '');
        $allocMonths = $resultData['allocated_months'] ?? [];

        // Mark the gateway payment_id as processed so any retry from
        // Razorpay (network blip, app reinstall, etc.) short-circuits at
        // the top of this function instead of re-running the receipt
        // pipeline. Tied to the receipt the processing produced so the
        // retry response matches the original.
        try {
            $this->fsTxn->markPaymentProcessed(
                $gwPaymentId,
                $receiptNo,
                $receiptKey,
                $studentId,
                $gwOrderId
            );
        } catch (\Throwable $e) {
            // \Throwable, not \Exception (FEE-W1) — a missing collaborator raises
            // an Error. The money is already durable here; a bookkeeping marker
            // must never abort the writes that follow it.
            log_message('error', "parent_verify_payment: markPaymentProcessed failed [{$gwPaymentId}]: " . $e->getMessage());
        }

        // Mark the order as paid in Firestore for human-readable order
        // history. The idempotency check above no longer relies on this
        // field, but admin reports still use it.
        try {
            // MERGE the delta — do NOT full-replace from `$order`.
            //
            // `$order` was read at the top of this method, BEFORE
            // Payment_service::verify_payment() ran. Verification writes
            // `verified_at` / `verified_by` onto the same document, so
            // array_merge($order, …) + a full firestoreSet() reverted both to
            // their creation-time values. Order F1 is the proof: it reached
            // status='paid' carrying verified_at='' even though its signature
            // verified successfully seconds earlier, destroying the audit
            // record of WHEN the money was verified.
            //
            // Every other paid-writer in this controller (the webhook path and
            // both retry paths) already writes a delta with merge=true. This
            // was the one that did not.
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'             => 'paid',
                'paid_at'            => date('c'),
                'gateway_payment_id' => $gwPaymentId,
                'signature'          => $signature,
                'receipt_key'        => $receiptKey,
                'receipt_no'         => $receiptNo,
            ], true);
        } catch (\Exception $e) {
            log_message('error', "parent_verify_payment: order status update failed [{$orderDocId}]: " . $e->getMessage());
        }

        // BUG-044 fix: write feeOnlinePayments audit-trail doc to
        // match _verify_and_process line 3919 + retry_payment_processing
        // line 4066 behavior. Without this, parent-app sync verify
        // receipts are invisible to reconciliation queries.
        try {
            $payRecId = 'PAY_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('feeOnlinePayments', "{$this->school_name}_{$payRecId}", [
                'schoolId'           => $this->school_name,
                'order_id'           => $orderDocId,
                'gateway_order_id'   => $gwOrderId,
                'gateway_payment_id' => $gwPaymentId,
                'student_id'         => $studentId,
                'student_name'       => $order['student_name'] ?? '',
                'amount'             => (float) ($order['amount'] ?? 0),
                'receipt_key'        => $receiptKey,
                // P11: was `?? 'mock'`. This is a PERSISTED money record that
                // reconciliation reads; an order with unrecorded provenance must
                // stay visibly unrecorded, never be labelled as a simulator.
                'gateway'            => $order['gateway'] ?? 'unknown',
                'payment_status'     => 'captured',
                'source'             => 'parent-razorpay',
                'signature'          => substr($signature, 0, 16) . '...',
                'created_at'         => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "parent_verify_payment: feeOnlinePayments write failed [{$gwPaymentId}]: " . $e->getMessage());
        }

        // BUG-045 OP5-A fix: build response to a variable so Content-Length
        // can be computed BEFORE emit, then apply Option B early-response
        // flush (mirrors FeeCollectionService.php:1481-1495 admin-path
        // pattern). Pushes response to client BEFORE the shutdown handler
        // runs accounting journal + summary refresh, eliminating ~7-8s
        // post-response wall-clock previously borne by parent app users.
        // B-025: the money is durable and the marker committed with it, so the
        // order-level claim has done its job. Released explicitly rather than
        // left to expire, so a legitimate follow-up attempt is not blocked for
        // the remainder of the lease.
        $this->paymentClaim->release($gwOrderId, $__claim['token']);

        $__op5ResponseJson = json_encode([
            'success'          => true,
            'message'          => 'Payment verified and fees recorded.',
            'receipt_no'       => $receiptNo,
            'receipt_key'      => $receiptKey,
            'amount_paid'      => $paidAmount,
            'allocated_months' => $allocMonths,
        ]);
        @set_time_limit(0);
        @ignore_user_abort(true);
        if (!headers_sent()) {
            @header('Connection: close');
            @header('Content-Type: application/json');
            @header('Content-Length: ' . strlen($__op5ResponseJson));
            @header('Content-Encoding: none'); // disable mod_deflate so length is honest
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        echo $__op5ResponseJson;
        @flush();

        // Best-effort "payment received" push to the parent via the universal CF
        // dispatcher, AFTER the response is flushed so it adds no client-perceived
        // latency. Fires exactly once per genuine payment — network retries hit
        // the idempotency early-return far above and never reach here; the
        // deterministic docId (keyed on the receipt) makes it idempotent anyway.
        // Parent app routes type=fee_payment_confirmed to the Fees screen.
        $this->emit_push('FEE_PAID', 'fee_paid_' . $receiptNo, [
            'studentId' => (string) $studentId,
            'title'     => 'Payment Received',
            'body'      => 'Payment of Rs. ' . number_format((float) $paidAmount, 2) . ' received. Receipt ' . $receiptNo . '.',
            'receiptId' => (string) $receiptNo,
            'amount'    => (string) $paidAmount,
        ]);
    }

    // parent_pay_from_wallet() removed in Phase 9 (wallet subsystem
    // deprecated). Razorpay (parent_create_order / parent_verify_payment)
    // is the sole parent-side payment entry point now.

    // ══════════════════════════════════════════════════════════════════
    //  DUES-BASED BLOCKING POLICY (result / TC / hall-ticket / library)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — current blocking policy for the school+session.
     * Used by the Defaulter Report's "Blocking" settings card.
     */
    public function get_blocking_policy()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_blocking_policy', 'Fees', 'manage');
        $this->load->library('Fee_dues_check', null, 'duesCheck');
        $this->duesCheck->init($this->firebase, $this->school_name, $this->session_year);
        $this->json_success(['policy' => $this->duesCheck->getPolicy()]);
    }

    /**
     * POST — save blocking policy. Params: block_result, block_tc,
     * block_hall_ticket, block_library, threshold_amount,
     * admin_override_allowed (all optional; missing = keep false).
     */
    public function save_blocking_policy()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_blocking_policy', 'Fees', 'manage');
        $this->load->library('Fee_dues_check', null, 'duesCheck');
        $this->duesCheck->init($this->firebase, $this->school_name, $this->session_year);

        $payload = [
            'block_result'           => $this->input->post('block_result')           === '1',
            'block_tc'               => $this->input->post('block_tc')               === '1',
            'block_hall_ticket'      => $this->input->post('block_hall_ticket')      === '1',
            'block_library'          => $this->input->post('block_library')          === '1',
            'threshold_amount'       => (float) $this->input->post('threshold_amount'),
            'admin_override_allowed' => $this->input->post('admin_override_allowed') === '1',
            // Lifecycle decisions D & C — per-school fee policy (default OFF).
            'all_sessions_clearance'    => $this->input->post('all_sessions_clearance')    === '1',
            'reprice_on_class_transfer' => $this->input->post('reprice_on_class_transfer') === '1',
        ];

        $ok = $this->duesCheck->savePolicy($payload);
        if (!$ok) {
            $this->json_error('Failed to save blocking policy.');
            return;
        }
        log_audit('Fees', 'save_blocking_policy', $this->school_name,
            "Updated dues-blocking policy: " . json_encode($payload));
        $this->json_success([
            'message' => 'Blocking policy saved.',
            'policy'  => $payload,
        ]);
    }

    /**
     * POST — Create a payment order.
     * Uses the Payment Service (gateway-agnostic).
     */
    public function create_payment_order()
    {
        $this->_require_role(self::FINANCE_ROLES, 'create_payment_order', 'Fees', 'edit');

        $this->_init_payment_service();

        try {
            $result = $this->paymentService->create_order([
                'student_id'   => trim($this->input->post('student_id') ?? ''),
                'student_name' => trim($this->input->post('student_name') ?? ''),
                'class'        => trim($this->input->post('class') ?? ''),
                'section'      => trim($this->input->post('section') ?? ''),
                'amount'       => floatval($this->input->post('amount') ?? 0),
                'fee_months'   => $this->input->post('fee_months') ?? [],
            ]);

            $this->load->library('Payment_provider_config_resolver'); $gwConfig = Payment_provider_config_resolver::resolve($this->firebase, $this->school_id, $this->school_name, $this->session_year); // P4

            $this->json_success([
                'message'          => $result['existing'] ? 'Existing order returned.' : 'Payment order created.',
                'payment_id'       => $result['payment_record_id'],
                'gateway_order_id' => $result['order_id'],
                'amount'           => $result['amount'],
                'gateway'          => $result['gateway'],
                // P11: was `?? 'mock'`. Same fix as parent_create_order — the
                // provider reported is the one that ACTUALLY minted the order
                // ($result['gateway'], written by Payment_service from the live
                // adapter's get_name()), not a config value that may have
                // changed since, and never a fabricated default.
                'provider'         => (string) ($result['gateway'] ?? ''),
                'api_key'          => is_array($gwConfig) ? ($gwConfig['api_key'] ?? '') : '',
                'mode'             => is_array($gwConfig) ? ($gwConfig['mode'] ?? 'test') : 'test',
            ]);
        } catch (\Exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * POST — Simulate a payment (DEV/TEST mode only).
     * Calls the mock gateway to simulate success/failure.
     */
    public function simulate_payment()
    {
        $this->_require_role(self::FINANCE_ROLES, 'simulate_payment', 'Fees', 'edit');

        $this->load->library('Payment_provider_config_resolver'); $gwConfig = Payment_provider_config_resolver::resolve($this->firebase, $this->school_id, $this->school_name, $this->session_year); // P4
        $mode = is_array($gwConfig) ? ($gwConfig['mode'] ?? 'test') : 'test';
        if ($mode === 'live') {
            $this->json_error('Simulation is not available in live mode.');
        }

        $this->_init_payment_service();

        $orderId = trim($this->input->post('order_id') ?? '');
        if ($orderId === '') {
            $this->json_error('Order ID is required.');
        }

        try {
            $result = $this->paymentService->simulate($orderId);
            $this->json_success($result);
        } catch (\Exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * POST — Verify payment and process fee collection atomically.
     *
     * Status lifecycle: created → processing → paid | fees_failed
     *
     * The order is NOT marked "paid" until all fee writes succeed.
     * If fee processing fails, the order stays in "fees_failed" state
     * and can be retried via retry_payment_processing().
     */
    public function verify_payment()
    {
        $this->_require_role(self::FINANCE_ROLES, 'verify_payment', 'Fees', 'edit');

        $gwOrderId   = trim($this->input->post('gateway_order_id') ?? '');
        $gwPaymentId = trim($this->input->post('gateway_payment_id') ?? '');
        $signature   = trim($this->input->post('signature') ?? '');

        if ($gwOrderId === '') $this->json_error('Gateway order ID is required.');
        if ($gwPaymentId === '') $this->json_error('Gateway payment ID is required.');

        $result = $this->_verify_and_process($gwOrderId, $gwPaymentId, $signature, 'frontend');

        if (!$result['ok']) {
            $this->json_error($result['error']);
        }
        if (!empty($result['already_paid'])) {
            $this->json_success(['message' => 'Payment already verified.', 'already_paid' => true]);
            return;
        }
        if ($result['fee_success']) {
            $this->json_success([
                'message'    => 'Payment verified and fees recorded.',
                'receipt_no' => str_replace('F', '', $result['receipt_key'] ?? ''),
            ]);
        } else {
            $this->json_error($result['fee_error'] ?? 'Fee processing failed. Retry from Transaction Audit.');
        }
    }

    /**
     * POST — Server-to-server webhook for payment gateway callbacks.
     *
     * Does NOT require admin session (gateway calls this directly).
     * Verifies authenticity using HMAC signature from the webhook secret.
     *
     * Razorpay sends: razorpay_order_id, razorpay_payment_id, razorpay_signature
     * Mock sends: order_id, payment_id, signature
     */
    public function payment_webhook()
    {
        header('Content-Type: application/json');

        // ══ PAY-OPS-5 · WHO IS ACTUALLY CALLING US ═══════════════════════════
        //
        // This read `$_SERVER['REMOTE_ADDR']` and compared it against a list of
        // Razorpay addresses. In production that comparison could NEVER match.
        //
        // zenxii.com is served **Proxied (orange cloud)** through Cloudflare —
        // PATH_A_US_SERVER_RUNBOOK.md step 8 says so explicitly. So REMOTE_ADDR
        // on the origin is a CLOUDFLARE EDGE address, never the gateway's. Every
        // genuine, correctly-signed Razorpay delivery hit the allowlist, failed
        // it, and was refused 403 — BEFORE the HMAC check, with the operator
        // seeing only "IP not whitelisted" for a webhook that was perfectly
        // valid. CI's `proxy_ips` is empty, so nothing recovered the true IP.
        //
        // That means the webhook path could not have worked in production
        // regardless of everything else PAY-OPS-4 fixed downstream of it.
        //
        // The true client IP now comes from `_webhook_client_ip()`, which trusts
        // `CF-Connecting-IP` ONLY when the connecting peer is itself inside
        // Cloudflare's published ranges — never from a bare header, which any
        // caller can set.
        $__ipInfo = $this->_webhook_client_ip();
        $ip       = $__ipInfo['ip'];

        // ── 0. IP allowlist — see the enforcement decision below ──
        $this->load->library('Payment_provider_config_resolver'); $gwConfig = Payment_provider_config_resolver::resolve($this->firebase, $this->school_id, $this->school_name, $this->session_year); // P4
        $allowedIPs    = is_array($gwConfig) ? ($gwConfig['webhook_allowed_ips'] ?? []) : [];
        $allowedIPs    = is_array($allowedIPs) ? array_values(array_filter($allowedIPs, 'is_string')) : [];
        $defaultIPs    = ['52.66.166.11', '52.66.171.94', '3.7.116.0/24', '127.0.0.1', '::1'];
        $allAllowed    = array_merge($defaultIPs, $allowedIPs);

        $ipAllowed = false;
        foreach ($allAllowed as $allowed) {
            if ($allowed === $ip) { $ipAllowed = true; break; }
            // CIDR check
            if (strpos($allowed, '/') !== false && $this->_ipInCidr($ip, $allowed)) { $ipAllowed = true; break; }
        }

        $gwMode = is_array($gwConfig) ? ($gwConfig['mode'] ?? '') : '';

        // Phase 3F — defensive prefix vs mode assertion at webhook receive time.
        // Layer 1 (save_gateway_config) blocks new misconfig writes, but
        // legacy docs / direct Firestore edits / migrations may already
        // carry a mismatch. Refuse the webhook with a loud error so the
        // misconfig surfaces in logs immediately rather than silently
        // bypassing HMAC enforcement.
        //
        // Drift detection is collapsed into a single $driftReason string
        // so each webhook request emits AT MOST ONE log_message call —
        // prevents log-line cascades during malformed retry storms.
        $apiKey    = is_array($gwConfig) ? ($gwConfig['api_key'] ?? '') : '';
        $isLiveKey = strpos($apiKey, 'rzp_live_') === 0;
        $isTestKey = strpos($apiKey, 'rzp_test_') === 0;
        $driftReason = '';
        if ($isLiveKey && $gwMode !== 'live') {
            $driftReason = "rzp_live_* key with mode='{$gwMode}'";
        } elseif ($isTestKey && $gwMode === 'live') {
            $driftReason = "rzp_test_* key with mode='live'";
        }
        if ($driftReason !== '') {
            log_message('error',
                "payment_webhook: CONFIG DRIFT — {$driftReason} " .
                "(school={$this->school_name}, session={$this->session_year}). " .
                "Webhook refused. Fix gateway config in admin UI before retrying."
            );
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Gateway configuration mismatch. Contact admin.']);
            return;
        }

        // ── THE IP ALLOWLIST IS UNCONDITIONAL (2026-08-25) ─────────────────
        // This read `if (!$ipAllowed && $gwMode !== 'mock')`.
        //
        // `$gwMode` is the gateway MODE, and `save_gateway_config()` enforces
        // `mode ∈ {test, live}` — 'mock' is not a mode and never has been. So
        // the clause was dead against any config saved through the UI, and its
        // apparent intent (let mock webhooks in from anywhere during dev) never
        // actually took effect.
        //
        // It was not merely dead, though: the value comes from a Firestore
        // DOCUMENT, so a legacy or hand-written row carrying `mode: "mock"`
        // would have skipped the IP check on a money-path endpoint entirely.
        // A bypass reachable by editing data is a bypass.
        //
        // Removed rather than repaired, because it buys nothing: 127.0.0.1 and
        // ::1 are already in $defaultIPs, so local development was never
        // relying on it. If a genuine simulator bypass is ever wanted it must
        // key on the resolved adapter's `simulatable` capability and the
        // environment gate — never on a config string.
        // ══ PAY-OPS-5 · THE IP CHECK IS A SIGNAL, THE HMAC IS THE GATE ══════
        //
        // This was an unconditional 403. Two things make that wrong in
        // production, and the second is the reason it is being demoted rather
        // than merely repaired:
        //
        //  1. Behind Cloudflare it compared the wrong address entirely (see
        //     _webhook_client_ip above). Fixed — but not sufficient on its own.
        //
        //  2. The default list is THREE Razorpay addresses hardcoded in this
        //     file. Razorpay does not guarantee its egress IPs and its own
        //     documentation tells integrators to authenticate webhooks by
        //     SIGNATURE, not by source address. A hardcoded list can only ever
        //     drift out of date, and when it does the failure is total and
        //     silent: every genuine delivery 403s and the money it was carrying
        //     is never reconciled.
        //
        // An IP allowlist cannot stop an attacker who holds the webhook secret,
        // and an attacker who does not hold it is already stopped by the HMAC —
        // which is MANDATORY for every real provider (see the
        // `$realProvider && $webhookSecret === ''` refusal below). So as a
        // security control it adds nothing here; as a liveness hazard it is
        // severe. That asymmetry is the whole argument.
        //
        // ── WHAT IS KEPT ────────────────────────────────────────────────────
        // A school that WANTS defence in depth still gets it: configure
        // `webhook_allowed_ips` on the gateway document and the check becomes
        // enforcing again for that tenant. Opt-in, because the school then owns
        // keeping the list current — which is exactly the maintenance burden
        // that made the hardcoded default dangerous.
        //
        // Unconfigured (every tenant today), the resolved IP is LOGGED on
        // refusalless delivery so an operator can see where deliveries come
        // from and build a list if they want one. Silence was never the goal.
        $__ipEnforced = ($allowedIPs !== []);

        if (!$ipAllowed) {
            if ($__ipEnforced) {
                log_message('error',
                    "payment_webhook: IP BLOCKED ip={$ip} peer={$__ipInfo['peer']} "
                    . 'via_cdn=' . ($__ipInfo['via_cdn'] ? '1' : '0')
                    . ' — this school configures webhook_allowed_ips, so the check is enforcing');
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'IP not whitelisted.']);
                return;
            }
            // Not enforcing: record it and let the HMAC decide.
            log_message('error',
                "payment_webhook: source IP {$ip} (peer={$__ipInfo['peer']}, "
                . 'via_cdn=' . ($__ipInfo['via_cdn'] ? '1' : '0')
                . ') is outside the default allowlist. NOT refused — authenticity is '
                . 'established by the webhook HMAC. Set webhook_allowed_ips on the gateway '
                . 'config to make this check enforcing for this school.');
        }

        // Prefer the body the constructor already cached — php://input is
        // not reliably rewindable across every PHP SAPI configuration, and
        // the constructor had to read it first to resolve school context.
        $rawBody = $this->_webhookRawBody ?? (string) file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) $payload = $this->input->post() ?: [];

        // ── 1. Timestamp replay protection (5 min window) ──
        $webhookTs = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP']
            ?? $_SERVER['HTTP_X_RAZORPAY_TIMESTAMP']
            ?? ($payload['timestamp'] ?? '');
        if ($webhookTs !== '') {
            $tsAge = abs(time() - (int) $webhookTs);
            if ($tsAge > 300) {
                log_message('error', "payment_webhook: REPLAY REJECTED age={$tsAge}s ip={$ip}");
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Webhook expired (replay protection).']);
                return;
            }
        }

        // ── 2. HMAC signature verification ──
        //
        // Policy:
        //   • live mode  — webhook_secret MUST be configured AND the incoming
        //                  request MUST carry a signature that matches. Anything
        //                  else rejects. This is the hardening pass.
        //   • test/mock  — HMAC is enforced when the secret is configured, but
        //                  a missing secret or missing signature is allowed so
        //                  developer environments without a real Razorpay
        //                  webhook secret can still simulate webhooks.
        $webhookSecret = is_array($gwConfig) ? ($gwConfig['webhook_secret'] ?? '') : '';
        $webhookSig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE']
            ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
            ?? ($payload['webhook_signature'] ?? '');

        // ── GW-SEC2 · THE HMAC REQUIREMENT WAS TIED TO THE WRONG THING ───────
        // These two guards keyed on `$gwMode === 'live'`. But mode is not what
        // makes a webhook worth authenticating — the presence of a REAL payment
        // provider is. And an unconfigured tenant resolves to
        //     $gwMode = is_array($gwConfig) ? ($gwConfig['mode'] ?? '') : '';
        // i.e. '', which is not 'live', so neither guard fired and the HMAC
        // check below was skipped entirely: an unsigned POST was accepted.
        //
        // The money impact was already blocked downstream — GW-1's refusing
        // adapter makes verify_payment() fail for an unconfigured tenant, and a
        // configured one still requires a valid PAYMENT signature derived from
        // api_secret. So this was defence-in-depth rather than an open door.
        // But "we happen to be saved by a different layer" is not a reason to
        // leave an authentication check keyed on the wrong condition.
        //
        // Keyed on the provider instead: any real provider must present a
        // secret AND a signature, in test mode as much as live (Razorpay issues
        // webhook secrets for test mode too). Only a simulator may skip, and
        // GW-1 already confines that to development/testing.
        $gwProvider   = is_array($gwConfig) ? (string) ($gwConfig['provider'] ?? '') : '';
        $realProvider = ($gwProvider !== '' && $gwProvider !== 'mock');

        if ($realProvider && $webhookSecret === '') {
            log_message('error', "payment_webhook: provider={$gwProvider} configured but webhook_secret is not set (school={$this->school_name}, session={$this->session_year}, mode={$gwMode}). Reconfigure gateway settings with the webhook secret.");
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Webhook secret not configured for this school.']);
            return;
        }
        if ($realProvider && $webhookSig === '') {
            log_message('error', "payment_webhook: provider={$gwProvider} but incoming request has no signature ip={$ip}");
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Missing webhook signature.']);
            return;
        }

        if ($webhookSecret !== '' && $webhookSig !== '') {
            // GW-SEC4: accepts the current secret, or a rotated-out one inside a
            // bounded grace window. Without the window, rotating the secret
            // strands every webhook already in flight — and the admin retry path
            // cannot recover those, because it refuses anything that is not
            // 'fees_failed' (a restriction that must NOT be relaxed: retry does
            // not re-verify with the gateway).
            $this->load->helper('webhook_signature');
            if (!webhook_signature_valid($rawBody, $webhookSig, is_array($gwConfig) ? $gwConfig : [])) {
                log_message('error', "payment_webhook: HMAC FAILED ip={$ip}");
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature.']);
                return;
            }
        } elseif ($webhookSecret === '') {
            // Logged once per request so misconfiguration in test mode is
            // visible without rejecting the request outright.
            log_message('error', "payment_webhook: webhook_secret empty — HMAC check skipped (mode={$gwMode}, school={$this->school_name})");
        }

        // ── 3. Event-ID dedup (replayed webhook protection) ──
        $eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID']
            ?? ($payload['event_id'] ?? '');
        $payloadHash = $eventId !== '' ? md5($eventId) : md5($rawBody);
        $dedupDocId  = "{$this->school_name}_{$payloadHash}";
        $existing    = $this->firebase->firestoreGet('feeIdempotency', $dedupDocId);
        // ── PAY-OPS-4 . THE GATE IS `processed_at`, AND THAT IS THE FIX -----
        // This condition is unchanged and deliberately so. What changed is who
        // is allowed to WRITE `processed_at`: see _webhook_mark() below. The
        // marker used to be stamped after EVERY delivery including failed ones,
        // so a transient reconciliation failure was recorded as "handled" and
        // every subsequent gateway retry short-circuited right here - answering
        // "Already processed (duplicate webhook)" for a payment that had never
        // been processed at all. The gateway's retry is the only automatic
        // route to money it already holds, and this swallowed it.
        //
        // A retryable attempt now writes an attempt record WITHOUT
        // `processed_at`, so it is visible to an operator and to this read, but
        // does not close the door.
        if (is_array($existing) && !empty($existing['processed_at'])) {
            // Already processed this exact payload — return 200 (idempotent)
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Already processed (duplicate webhook).']);
            return;
        }

        // P2 (2026-08-24): $gwConfig was already read above (IP allowlist),
        // so this pays no extra Firestore access — unlike
        // parent_verify_payment (see that function's own comment for why it
        // was left as inline parse). The whole resolve-and-parse decision is
        // ONE pure, directly-testable function
        // (Payment_gateway_selector::parse_provider_callback,
        // ProviderProtocolParsingTest::test_parse_provider_callback_routes_through_the_correct_adapter)
        // rather than inlined here, so this call site cannot silently drift
        // from what the test actually drives.
        // FEE-W1 family — the webhook branch runs CI_Controller::__construct(),
        // so nothing MY_Controller normally loads exists here. Every other
        // caller of this class reaches it through `$this->load->library(…)` on
        // a fully-constructed controller; this path had neither, and died with
        // "Class Payment_gateway_selector not found" AFTER signature
        // verification and order resolution had already succeeded.
        $this->load->library('Payment_gateway_selector');
        // …and the ADAPTER itself. `adapter_class()` returns a class NAME and
        // never loads the file, so on this branch `method_exists($class,
        // 'parse_callback')` was false and parse_provider_callback silently
        // took its generic top-level fallback — returning empty ids for a
        // perfectly good nested webhook. Loaded via the catalogue rather than
        // hard-coding 'razorpay', so a second provider works unchanged.
        $__adapterClass = Payment_gateway_selector::adapter_class(
            (string) ($gwConfig['provider'] ?? '')
        );
        if ($__adapterClass !== null && !class_exists($__adapterClass)) {
            // ── PAY-OPS-4 · require_once, NOT load->library() ────────────────
            //
            // THIS LINE USED TO SILENTLY DISARM PAYMENT VERIFICATION.
            //
            // All that is needed here is for the CLASS FILE to exist, so that
            // `method_exists($class, 'parse_callback')` is true and
            // parse_provider_callback can reach the adapter. It does its own
            // `new $class()`; it never touches the CI instance.
            //
            // `$this->load->library()` does much more than that. It
            // INSTANTIATES the class — here with NO parameters, so with EMPTY
            // credentials — and registers it in CI's loaded-library registry.
            // Later in the same request `_init_payment_service()` calls
            //     $this->load->library('Payment_gateway_razorpay', [api_key…])
            // and CI3 finds the name already registered, logs "Second attempt
            // ignored" at DEBUG (a level config.php discards), and SKIPS it.
            // Payment_service is therefore handed the CREDENTIAL-LESS instance
            // built by this line.
            //
            // Before PAY-OPS-4 that was invisible: the webhook died at the
            // signature check long before any credential was used. Now the
            // webhook confirms payments by CALLING the provider, so it became
            // the whole story — every delivery failed with "Razorpay
            // credentials are not configured", was correctly classified as
            // unconfirmable-and-retryable, and no payment could EVER be
            // reconciled. Found only by running a real signed delivery end to
            // end against a real provider; no unit test would have seen it,
            // because the defect lives in CI's loader, not in this file's logic.
            //
            // require_once loads the file and nothing else, leaving
            // _init_payment_service() free to construct the adapter properly.
            $__adapterFile = APPPATH . 'libraries/' . $__adapterClass . '.php';
            if (is_file($__adapterFile)) {
                require_once $__adapterFile;
            } else {
                log_message('error', "payment_webhook: adapter file missing for [{$__adapterClass}] "
                    . '— the webhook will fall back to generic top-level parsing');
            }
        }
        // ══ PAY-OPS-4 . THE EVENT GATE ══════════════════════════════════════
        //
        // Until now this handler read NO event type at all. There was no branch
        // on `$payload['event']` anywhere between the signature check and the
        // settlement pipeline, so `payment.failed` took the identical path to
        // `payment.captured`. The only thing that stopped a failure
        // notification from settling fees was a signature check that a webhook
        // could never satisfy - i.e. the pipeline was protected by a bug, and
        // fixing that bug without this gate would have made failures settle.
        //
        // That is why the gate and the source-specific verification are one
        // change and not two: either alone is wrong.
        //
        // Classification is the PROVIDER's, resolved through the adapter (see
        // Payment_gateway_selector::classify_provider_event). Unknown providers,
        // unknown events and adapters that throw all resolve to IGNORE, which
        // writes nothing financial.
        $__event      = Payment_gateway_selector::classify_provider_event($gwConfig, $payload);
        $__eventClass = (string) $__event['class'];
        $__eventName  = (string) $__event['event'];

        log_message('debug', "payment_webhook: event='{$__eventName}' class={$__eventClass} "
            . "provider={$gwProvider} school={$this->school_name} — {$__event['reason']}");

        if ($__eventClass === Payment_gateway_selector::EVENT_IGNORE) {
            // Acknowledge and write NOTHING financial. 200 rather than an error
            // because the delivery was authentic and correctly formed; ZenX
            // simply does not reconcile against this event. A non-2xx here would
            // make the provider retry an event that will never be actionable.
            //
            // Terminal by nature: this exact delivery cannot later become
            // meaningful. A genuine capture arrives as its OWN delivery with its
            // own event id, so this marker cannot block it.
            log_message('error', "payment_webhook: IGNORED event='{$__eventName}' "
                . "school={$this->school_name} — {$__event['reason']}");
            $this->_webhook_mark($dedupDocId, $existing, 'ignored', true, [
                'event'       => $__eventName,
                'event_class' => $__eventClass,
                'reason'      => (string) $__event['reason'],
            ]);
            http_response_code(200);
            echo json_encode([
                'status'  => 'success',
                'message' => 'Event acknowledged; not part of fee reconciliation.',
                'event'   => $__eventName,
            ]);
            return;
        }

        $__webhookParsed = Payment_gateway_selector::parse_provider_callback($gwConfig, $payload);
        $gwOrderId   = $__webhookParsed['order_id'];
        $gwPaymentId = $__webhookParsed['payment_id'];
        $signature   = $__webhookParsed['signature'];

        if ($gwOrderId === '' || $gwPaymentId === '') {
            // No marker written: nothing was identified, so there is nothing to
            // record as handled, and a redelivery that DOES parse must not be
            // pre-empted by this one.
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing order_id or payment_id.']);
            return;
        }

        // ── 4. Log webhook receipt ──
        $webhookLogId = "{$this->school_name}_" . date('YmdHis') . '_' . substr($payloadHash, 0, 8);
        $this->firebase->firestoreSet('feeOnlinePayments', $webhookLogId, [
            'schoolId'      => $this->school_name,
            'docType'       => 'webhook_log',
            'order_id'      => $gwOrderId,
            'payment_id'    => $gwPaymentId,
            'payload_hash'  => $payloadHash,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            'received_at'   => date('c'),
            'webhook_sig'   => substr($webhookSig, 0, 16) . '...',
            // PAY-OPS-4: the log said which payment but never which EVENT, so a
            // forensic reader could not tell a capture from a failure.
            'event'         => $__eventName,
            'event_class'   => $__eventClass,
        ]);

        // ── 4b. PAY-OPS-4 . NON-SUCCESS EVENTS NEVER REACH SETTLEMENT ──────
        // A failed or abandoned payment is recorded against the order and the
        // intent, and stops here. No demand settlement, no allocation, no
        // receipt, no accounting voucher, no payment marker - the money never
        // existed, so nothing may claim it did.
        if ($__eventClass === Payment_gateway_selector::EVENT_FAILURE) {
            $this->_webhook_record_non_success($gwOrderId, $gwPaymentId, $__eventName, (string) $__event['reason']);

            // Terminal: a failure notification will not become a success on
            // redelivery. The genuine capture, if one follows, is a DIFFERENT
            // delivery with a different event id and is unaffected by this.
            $this->_webhook_mark($dedupDocId, $existing, 'non_financial', true, [
                'order_id'    => $gwOrderId,
                'payment_id'  => $gwPaymentId,
                'event'       => $__eventName,
                'event_class' => $__eventClass,
            ]);

            log_message('error', "payment_webhook: NON-SUCCESS event='{$__eventName}' "
                . "order={$gwOrderId} payment={$gwPaymentId} — recorded, nothing settled");

            http_response_code(200);
            echo json_encode([
                'status'  => 'success',
                'message' => 'Unsuccessful payment recorded. No fees were settled.',
                'event'   => $__eventName,
            ]);
            return;
        }

        // ── 5. Process using unified flow ──
        $result = $this->_verify_and_process($gwOrderId, $gwPaymentId, $signature, 'webhook');

        // ── 6. PAY-OPS-4 . MARK ONLY WHAT ACTUALLY REACHED A TERMINAL STATE ─
        // Was: an unconditional `processed_at` write with a `result` field that
        // recorded 'failed' and closed the door anyway. See _webhook_mark().
        $__outcome  = self::_webhook_outcome($result);
        $__state    = $__outcome['state'];
        $__terminal = $__outcome['terminal'];

        $this->_webhook_mark($dedupDocId, $existing, $__state, $__terminal, [
            'order_id'    => $gwOrderId,
            'payment_id'  => $gwPaymentId,
            'event'       => $__eventName,
            'event_class' => $__eventClass,
            'receipt_key' => (string) ($result['receipt_key'] ?? ''),
            'last_error'  => $__outcome['clean'] ? '' : (string) ($result['error'] ?? ''),
        ]);

        // ── 7. Update payment intent status if this was a mobile-initiated payment ──
        if ($result['ok'] && !empty($result['fee_success'])) {
            try {
                if ($gwOrderId !== '') {
                    // Query Firestore for matching payment intent
                    $intents = $this->firebase->firestoreQuery('feeOnlineOrders', [
                        ['schoolId',         '==', $this->school_name],
                        ['gateway_order_id', '==', $gwOrderId],
                        ['docType',          '==', 'payment_intent'],
                    ], null, 'ASC', 1);
                    foreach ((array) $intents as $row) {
                        $intentDocId = $row['id'] ?? '';
                        if ($intentDocId !== '') {
                            $this->firebase->firestoreSet('feeOnlineOrders', $intentDocId, [
                                'status'       => 'completed',
                                'completed_at' => date('c'),
                                'receipt_no'   => $result['receipt_key'] ?? '',
                            ], true);
                        }
                        break;
                    }
                }
            } catch (Exception $e) {
                log_message('error', "Payment intent update in webhook failed: " . $e->getMessage());
            }

            // ── 8. Update defaulter status after payment ──
            try {
                // Look up the order to get student_id
                $orderRows = $this->firebase->firestoreQuery('feeOnlineOrders', [
                    ['schoolId',         '==', $this->school_name],
                    ['gateway_order_id', '==', $gwOrderId],
                ], null, 'ASC', 1);
                foreach ((array) $orderRows as $row) {
                    $ordData = $row['data'] ?? $row;
                    $studentId = (string) ($ordData['student_id'] ?? $ordData['studentId'] ?? '');
                    if ($studentId !== '') {
                        $this->feeDefaulter->updateDefaulterStatus($studentId);
                    }
                    break;
                }
            } catch (Exception $e) {
                log_message('error', "Defaulter status update in webhook failed: " . $e->getMessage());
            }
        }

        // ── PAY-OPS-4 . THE STATUS CODE IS PART OF THE RETRY CONTRACT ──────
        // Every non-2xx makes the gateway redeliver, so the distinction is for
        // the OPERATOR reading delivery logs, and for honesty: 422 says "I
        // understood you and this is final", 503 says "try me again". Emitting
        // 422 for a Razorpay API outage told the operator the payment was
        // rejected when it was merely unconfirmed.
        //
        // Note $result['ok'] is TRUE for the fees_failed outcome (the gateway
        // did confirm), so the retryable check is made independently of it -
        // that outcome is exactly the one that most needs a redelivery.
        if (!$__terminal) {
            http_response_code(503);
            header('Retry-After: 60');
            echo json_encode([
                'status'    => 'error',
                'retryable' => true,
                'message'   => $result['error'] ?? 'Temporary failure. Please redeliver.',
            ]);
            return;
        }

        // ── PAY-OPS-6 · ACKNOWLEDGE A BUSINESS REFUSAL, DO NOT INVITE RETRIES ─
        // The gateway holds the money and ZenX will refuse to apply it on every
        // future delivery too (pay-older-first, already-fully-paid, …). A
        // non-2xx here would have the provider redeliver until its retry budget
        // is spent, achieving nothing and burying the event.
        //
        // 200 stops the retries. It does NOT mean "settled" — the order is in
        // `fees_failed` with `failure_class: business`, which is exactly what
        // the reconciliation screen lists and the admin Retry path acts on. The
        // response says so, so a delivery log is not mistaken for a receipt.
        if ($__state === 'business_refused') {
            log_message('error',
                "payment_webhook: BUSINESS REFUSAL order={$gwOrderId} payment={$gwPaymentId} — "
                . 'money is captured at the gateway and NOT applied. Order left in fees_failed '
                . 'for operator action: ' . (string) ($result['error'] ?? ''));
            http_response_code(200);
            echo json_encode([
                'status'      => 'acknowledged',
                'reconciled'  => false,
                'needs_operator_action' => true,
                'message'     => $result['error'] ?? 'Payment captured but not applied; needs school action.',
            ]);
            return;
        }

        if (!$result['ok'] && empty($result['already_paid'])) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Processing failed.']);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'status'      => 'success',
            'message'     => !empty($result['already_paid']) ? 'Already processed.' : 'Payment processed.',
            'receipt_key' => $result['receipt_key'] ?? '',
        ]);
    }

    /**
     * ══════════════════════════════════════════════════════════════════════
     *  PAY-OPS-4 · THE WEBHOOK DEDUP MARKER, WITH STATES
     * ══════════════════════════════════════════════════════════════════════
     *
     * ── THE DEFECT THIS REPLACES ──────────────────────────────────────────
     * The handler wrote ONE marker shape, unconditionally, after every
     * delivery:
     *
     *     processed_at => now,
     *     result       => success | already_paid | failed
     *
     * `processed_at` is what the dedup READ gate keys on. So a delivery that
     * had just FAILED — a Firestore blip, a claim contention, a fee write that
     * threw — was recorded as processed, and every gateway redelivery was
     * answered "Already processed (duplicate webhook)" with a 200. The
     * `result: 'failed'` field recorded the truth and nothing read it.
     *
     * The gateway's retry is the only automatic path to money the provider
     * already holds. This closed it, permanently, on the first stumble.
     *
     * ── THE SMALLEST CORRECT MODEL ────────────────────────────────────────
     * No parallel dedup system: the same collection, the same document id, the
     * same read gate. The only change is WHO may write `processed_at`.
     *
     *   TERMINAL  — processed | duplicate | rejected | ignored | non_financial
     *               writes `processed_at`. The gate engages; redelivery is
     *               answered as a duplicate. Correct, because asking again
     *               cannot change the answer.
     *
     *   RETRYABLE — retry
     *               writes an ATTEMPT record: `attempts`, `last_attempt_at`,
     *               `last_error`, and NO `processed_at`. Visible to an
     *               operator, invisible to the gate. The gateway redelivers
     *               and the payment gets another chance.
     *
     * Merge, not replace, so a terminal outcome lands on top of the attempt
     * history rather than erasing how many tries it took.
     *
     * ── WHY A RETRY CANNOT DOUBLE-SETTLE ──────────────────────────────────
     * Nothing here is what prevents duplicates, and it never was. Three older
     * layers do, all of which a retry passes through first:
     *   • feeProcessedPayments marker  — this exact payment already applied
     *   • feeOnlineOrders.status='paid' — verify_payment returns already_paid
     *   • Fee_payment_claim            — atomic exclusion for concurrent runs
     * The admin Retry button already re-enters the identical pipeline for
     * exactly these orders, so this makes the gateway's retry as safe as the
     * one an operator can already press by hand.
     *
     * A marker write that itself fails is deliberately non-fatal: the outcome
     * has already happened, and throwing here would turn a settled payment
     * into a 500.
     *
     * @param string     $dedupDocId feeIdempotency doc id
     * @param mixed      $existing   the prior read of that doc (array or null)
     * @param string     $state      processed|duplicate|rejected|ignored|non_financial|retry
     * @param bool       $terminal   true → stamp processed_at and close the door
     * @param array      $extra      additional fields to record
     */
    /**
     * ══════════════════════════════════════════════════════════════════════
     *  PAY-OPS-4 · IS THIS OUTCOME FINAL?  (pure — no I/O, no $this)
     * ══════════════════════════════════════════════════════════════════════
     *
     * The one decision that determines whether the gateway's retry survives.
     * Kept PURE and STATIC precisely so it can be driven directly by a test:
     * a CI3 controller cannot be instantiated outside the framework, so logic
     * left inline in `payment_webhook()` is reachable only by reading the
     * source — and this repository has already been bitten by an assertion
     * that "passed" while testing nothing (see WebhookCollaboratorFatalTest's
     * dedup-ordering case).
     *
     * ── THE DEFAULT IS RETRYABLE, DELIBERATELY ──────────────────────────
     * `retryable` is set explicitly on every return path of
     * `_verify_and_process()`. If a FUTURE path forgets it, this treats the
     * outcome as retryable rather than final.
     *
     * That asymmetry is chosen, not accidental. Wrongly retryable costs one
     * redelivery, which the three older idempotency layers
     * (feeProcessedPayments, feeOnlineOrders.status='paid', Fee_payment_claim)
     * absorb without moving money twice. Wrongly final loses a payment the
     * school has already received, silently and permanently — which is exactly
     * the bug this whole change exists to remove. Failing safe here means
     * failing toward the retry.
     *
     * @param  array $result the return of _verify_and_process()
     * @return array{state: string, terminal: bool, clean: bool}
     *         clean = a good outcome with no error worth recording
     */
    /**
     * ══════════════════════════════════════════════════════════════════════
     *  PAY-OPS-6 · WILL RETRYING THIS FEE WRITE EVER WORK?  (pure, static)
     * ══════════════════════════════════════════════════════════════════════
     *
     * `FeeCollectionService` already answers this and the webhook path was
     * discarding the answer. Every refusal it returns carries an `http_code`:
     *
     *   409 Conflict      pay-older-first, already-fully-paid, duplicate —
     *                     a BUSINESS verdict. Identical input gets the
     *                     identical refusal, forever.
     *   422 Unprocessable a validation refusal. Same.
     *   423 Locked        another attempt holds the lock RIGHT NOW. The
     *                     textbook transient failure.
     *   5xx               an internal fault. Transient.
     *   0                 the service returned no code, or the call threw.
     *
     * ── THE DEFAULT IS RETRYABLE, DELIBERATELY ──────────────────────────
     * Only codes positively known to be permanent are treated as permanent.
     * An unrecognised code, and code 0, stay retryable — because the cost of
     * being wrong is asymmetric in exactly the way the rest of this module is
     * built around: a needless redelivery is absorbed by three idempotency
     * layers, whereas wrongly declaring a transient failure permanent buries a
     * payment the gateway has already taken.
     *
     * 423 is called out explicitly rather than left to the 4xx default,
     * because it is the one 4xx that means "come back in a moment".
     */
    private static function _fee_failure_is_permanent(int $httpCode): bool
    {
        if ($httpCode === 423) return false;   // Locked — contention, retry
        if ($httpCode >= 500)  return false;   // Server fault — retry
        if ($httpCode === 409 || $httpCode === 422) return true;
        // Anything else (including 0 = unknown) is not positively known to be
        // permanent, so it stays retryable.
        return false;
    }

    private static function _webhook_outcome(array $result): array
    {
        $success   = !empty($result['fee_success']);
        $duplicate = !empty($result['already_paid']);

        // Success and duplicate are terminal regardless of what `retryable`
        // says: the money has been applied exactly once and there is nothing
        // left to redeliver for.
        if ($success)   return ['state' => 'processed', 'terminal' => true, 'clean' => true];
        if ($duplicate) return ['state' => 'duplicate', 'terminal' => true, 'clean' => true];

        $terminal = array_key_exists('retryable', $result) && !$result['retryable'];

        // ── PAY-OPS-6 · BUSINESS REFUSAL IS ITS OWN OUTCOME ─────────────────
        // Terminal like 'rejected' — the gateway must stop redelivering — but
        // it is NOT the same event and must not be reported as one. In a
        // 'rejected' the money was never ours to take (not captured, wrong
        // order, wrong amount). Here the gateway HAS the money and ZenX has
        // declined to apply it for a business reason. That needs a human, and
        // an operator reading `state: rejected` would reasonably close the
        // ticket. It is answered 200 (see the handler) so the provider stops
        // retrying, while the order sits in `fees_failed` for the admin.
        if ($terminal && !empty($result['business_refusal'])) {
            return ['state' => 'business_refused', 'terminal' => true, 'clean' => false];
        }

        return [
            'state'    => $terminal ? 'rejected' : 'retry',
            'terminal' => $terminal,
            'clean'    => false,
        ];
    }

    private function _webhook_mark(string $dedupDocId, $existing, string $state, bool $terminal, array $extra = []): void
    {
        $attempts = is_array($existing) ? (int) ($existing['attempts'] ?? 0) : 0;

        $doc = array_merge($extra, [
            'schoolId'        => $this->school_name,
            'state'           => $state,
            'terminal'        => $terminal,
            'attempts'        => $attempts + 1,
            'last_attempt_at' => date('c'),
        ]);

        if ($terminal) {
            // The ONLY place processed_at is written.
            $doc['processed_at'] = date('c');
            // Kept for continuity with markers written before this change, whose
            // readers (Fees.php reconciliation sweeps) expect it.
            $doc['result'] = $state === 'processed'   ? 'success'
                           : ($state === 'duplicate'  ? 'already_paid' : $state);
        }

        try {
            $this->firebase->firestoreSet('feeIdempotency', $dedupDocId, $doc, true);
        } catch (\Throwable $e) {
            log_message('error', "payment_webhook: could not write dedup marker [{$dedupDocId}] "
                . "state={$state} terminal=" . ($terminal ? '1' : '0') . ': ' . $e->getMessage());
        }
    }

    /**
     * ══════════════════════════════════════════════════════════════════════
     *  PAY-OPS-4 · RECORD AN UNSUCCESSFUL PAYMENT — AND NOTHING ELSE
     * ══════════════════════════════════════════════════════════════════════
     *
     * Reached only for events the PROVIDER classifies as unsuccessful
     * (`payment.failed`; Cashfree also `PAYMENT_USER_DROPPED_WEBHOOK`, its
     * cancellation). It writes order/intent state so the parent's failed
     * attempt is visible, and touches nothing financial: no demand, no
     * allocation, no receipt, no journal, no payment marker.
     *
     * ── WHY IT READS BEFORE IT WRITES ─────────────────────────────────────
     * Webhook deliveries are not ordered. A parent whose first card is
     * declined and whose second succeeds produces `payment.failed` and
     * `payment.captured` for the SAME order, and the failure can arrive after
     * the success. Blindly writing `status: 'failed'` would then overwrite a
     * settled order and make a paid student look unpaid — turning a cosmetic
     * event into a reconciliation defect.
     *
     * So a terminal or in-flight order is left completely alone: `paid`,
     * `processing`, `verified` and `fees_failed` are all states this event must
     * not disturb. `fees_failed` in particular means the gateway DID confirm
     * and only the fee write failed — the admin Retry path keys on exactly that
     * string, and overwriting it would strand a real payment.
     *
     * A read failure is treated as "do not touch": refusing to write is always
     * safe here, because nothing downstream depends on this record.
     */
    private function _webhook_record_non_success(string $gwOrderId, string $gwPaymentId, string $event, string $reason): void
    {
        $orderDocId = "{$this->school_name}_{$gwOrderId}";

        try {
            $order  = $this->firebase->firestoreGet('feeOnlineOrders', $orderDocId);
            $status = is_array($order) ? strtolower(trim((string) ($order['status'] ?? ''))) : null;

            if ($status === null) {
                log_message('error', "payment_webhook: non-success event '{$event}' for order "
                    . "{$gwOrderId} — order unreadable, leaving it untouched");
                return;
            }

            if (in_array($status, ['paid', 'processing', 'verified', 'fees_failed'], true)) {
                log_message('error', "payment_webhook: non-success event '{$event}' for order "
                    . "{$gwOrderId} arrived while status='{$status}' — NOT overwriting; "
                    . 'a later-arriving failure must not unpay a settled order');
                return;
            }

            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'             => 'failed',
                'failed_at'          => date('c'),
                'failure_reason'     => "Gateway event '{$event}': {$reason}",
                'failed_event'       => $event,
                'gateway_payment_id' => $gwPaymentId,
                'failure_source'     => 'webhook',
            ], true);

            // The payment intent the Parent App polls. Same non-clobber rule.
            $intents = $this->firebase->firestoreQuery('feeOnlineOrders', [
                ['schoolId',         '==', $this->school_name],
                ['gateway_order_id', '==', $gwOrderId],
                ['docType',          '==', 'payment_intent'],
            ], null, 'ASC', 1);
            foreach ((array) $intents as $row) {
                $intentDocId = $row['id'] ?? '';
                $intentData  = $row['data'] ?? $row;
                $intentState = strtolower(trim((string) ($intentData['status'] ?? '')));
                if ($intentDocId !== '' && $intentState !== 'completed') {
                    $this->firebase->firestoreSet('feeOnlineOrders', $intentDocId, [
                        'status'       => 'failed',
                        'failed_at'    => date('c'),
                        'failed_event' => $event,
                    ], true);
                }
                break;
            }
        } catch (\Throwable $e) {
            // Non-fatal by design: this is a record of something that did NOT
            // happen. Failing the delivery over it would invite the gateway to
            // redeliver a failure notification indefinitely.
            log_message('error', "payment_webhook: recording non-success event '{$event}' for "
                . "order {$gwOrderId} failed: " . $e->getMessage());
        }
    }

    /**
     * UNIFIED: Verify gateway signature → process fees → mark paid.
     *
     * Called by BOTH verify_payment (frontend) AND payment_webhook (server).
     * Single source of truth for the payment → fees flow.
     *
     * @param  string $gwOrderId    Gateway order ID
     * @param  string $gwPaymentId  Gateway payment ID
     * @param  string $signature    Payment signature
     * @param  string $source       'frontend' or 'webhook'
     * @return array  {ok, already_paid, fee_success, receipt_key, error, fee_error}
     */
    private function _verify_and_process(string $gwOrderId, string $gwPaymentId, string $signature, string $source): array
    {
        $this->_init_payment_service();

        // ── A0. PAYMENT-LEVEL IDEMPOTENCY (F4) ────────────────────────────────
        // Distinct from the order-level claim below, and deliberately so:
        //
        //   ORDER-level claim      mutual exclusion while ONE attempt runs
        //   PAYMENT-level marker   this exact gateway payment already applied
        //   WEBHOOK EVENT dedup    this exact delivery already handled
        //   RECEIPT reservation    this receipt number already issued
        //
        // These are four different questions and must never be collapsed. In
        // particular the order-level claim must NEVER carry a permanent "done"
        // state as a stand-in for payment idempotency: one order can legitimately
        // carry more than one payment attempt, and answering a second genuine
        // payment with the first payment's receipt takes money without applying it.
        //
        // Until now this check lived ONLY in the frontend wrapper
        // (parent_verify_payment), never in the shared core — so the frontend
        // deduped by payment id while the webhook deduped only by delivery-event
        // id, and the two namespaces did not overlap. Checked here, both paths
        // get it.
        //
        // Placed BEFORE the claim so a replay short-circuits without contending.
        // A read failure falls through to normal processing: that is exactly
        // today's behaviour on this path, so it is no worse, and failing closed
        // would refuse legitimate first-time payments during a Firestore blip.
        try {
            $priorPayment = $this->fsTxn->getProcessedPayment($gwPaymentId);
            if (is_array($priorPayment)) {
                // ── PAY-2 — one payment belongs to exactly one order ────────
                // A gateway payment id is globally unique and is minted against
                // a single order. A marker saying payment P belonged to order X,
                // presented while processing P for order Y, cannot both be true.
                // That is an anomaly, and it is NOT safe to resolve either way:
                // treating it as processed returns a receipt describing a
                // different order, and treating it as fresh applies a payment
                // that already landed. Refuse and surface it.
                //
                // `orderId` is a VERIFIED FIELD, deliberately not part of the
                // key — see markPaymentProcessed()'s note. Absent on markers
                // written before this field existed, so it is checked only when
                // present.
                $markerOrder = (string) ($priorPayment['orderId'] ?? '');
                if ($markerOrder !== '' && $markerOrder !== $gwOrderId) {
                    log_message('error',
                        "verify_and_process({$source}): payment [{$gwPaymentId}] is recorded against "
                        . "order [{$markerOrder}] but was presented for order [{$gwOrderId}] — "
                        . 'refusing, nothing applied');
                    return [
                        'ok'    => false,
                        // PAY-OPS-4: an anomaly, not a hiccup. Asking again gets
                        // the same contradictory answer, so the gateway must not
                        // be invited to retry into it.
                        'retryable' => false,
                        'error' => 'This payment does not match the order it was submitted for. No money has been applied.',
                    ];
                }

                log_message('info',
                    "verify_and_process({$source}): payment [{$gwPaymentId}] already applied as receipt ["
                    . (string) ($priorPayment['receiptKey'] ?? '') . '] — returning it, nothing re-applied');
                return [
                    'ok'          => true,
                    'already_paid' => true,
                    'retryable'   => false,
                    'receipt_key' => (string) ($priorPayment['receiptKey'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            log_message('error',
                "verify_and_process({$source}): processed-payment lookup failed [{$gwPaymentId}]: "
                . $e->getMessage() . ' — continuing to the order claim');
        }

        // ── A. Order-level EXCLUSIVE CLAIM (B-025) ────────────────────────────
        // Was: read the lock, judge its age, then blindly SET it. Two callers
        // that both read before either wrote BOTH entered the money pipeline —
        // and Razorpay fires the webhook while the client callback is still in
        // flight, so that interleaving is the normal case, not an edge case.
        //
        // Now: an atomic create decides ownership, so exclusion lives in the
        // WRITE and there is no read-then-write window to interleave on. The
        // claim carries a lease so a crashed process still cannot wedge the
        // order — recovery is a compare-and-swap, never a second blind write.
        // See Fee_payment_claim and .claude/zenx/B025_PAYMENT_CLAIM_PROTOCOL.md.
        // Only `firebase` is required — deliberately. The webhook path runs
        // through CI_Controller::__construct() (no session), so `fs` does not
        // exist there and a claim that depended on it could never recover an
        // expired lease on the very path the gateway retries into.
        $this->load->library('Fee_payment_claim', null, 'paymentClaim');
        $this->paymentClaim->init($this->firebase, $this->school_name);

        $claim = $this->paymentClaim->acquire($gwOrderId, $source);

        if ($claim['status'] === Fee_payment_claim::IN_PROGRESS) {
            // PAY-OPS-4: another attempt owns this order RIGHT NOW. That is the
            // textbook retryable outcome - the gateway redelivering in a minute
            // is exactly how this resolves.
            return ['ok' => false, 'retryable' => true, 'error' => 'This payment is currently being processed. Please wait.'];
        }
        if ($claim['status'] !== Fee_payment_claim::ACQUIRED) {
            // Ownership is UNDETERMINED (transport failure, unreadable claim, or
            // a scope we could not verify). Proceeding would risk a second
            // application of the same payment. Refuse and let the caller retry —
            // the gateway retries its webhook, and nothing has been applied.
            log_message('error',
                "verify_and_process({$source}): payment claim undetermined for order "
                . "[{$gwOrderId}] — {$claim['reason']} — refusing, nothing applied");
            return ['ok' => false, 'retryable' => true, 'error' => 'We could not start processing this payment just now. No money has been applied. Please retry in a moment.'];
        }

        $lockToken = $claim['token'];

        // Release only if we still own it — a token mismatch means our lease
        // expired and another process legitimately took over.
        $releaseLock = function () use ($gwOrderId, $lockToken) {
            $this->paymentClaim->release($gwOrderId, $lockToken);
        };

        // ── B. Verify signature via Payment Service ──
        // -- PAY-OPS-4 . THE SOURCE DECIDES WHAT COUNTS AS PROOF -----------
        // A webhook carries no order|payment signature and never could; its
        // authenticity was established by the webhook HMAC upstream, and is
        // re-established inside verify_payment() against the provider's own
        // record of the payment. Passing $source is what lets that method use
        // the right proof for the right caller instead of demanding the
        // browser's proof from a server-to-server delivery - which is why
        // every genuinely-signed webhook used to be refused with
        // 'Payment signature verification failed'.
        $verifyResult = $this->paymentService->verify_payment(
            $gwOrderId, $gwPaymentId, $signature,
            $source === 'webhook'
                ? Payment_service::SOURCE_WEBHOOK
                : Payment_service::SOURCE_FRONTEND
        );

        if (!$verifyResult['verified']) {
            $releaseLock();
            return [
                'ok'    => false,
                // Propagated, never re-derived: only verify_payment() knows
                // whether it refused because the provider said "not captured"
                // (final) or because it could not reach the provider at all
                // (retryable). Collapsing the two discards the gateway's retry,
                // which on an unreachable-provider blip is the only remaining
                // route to a payment the school has already received.
                'retryable' => (bool) ($verifyResult['webhook_retryable'] ?? false),
                'error' => $verifyResult['error'] ?? 'Verification failed.',
            ];
        }
        if (!empty($verifyResult['already_paid'])) {
            $releaseLock();
            return ['ok' => true, 'already_paid' => true, 'retryable' => false];
        }

        $recordId  = $verifyResult['record_id'];
        $order     = $verifyResult['order'];

        // ── PAY-OPS-4 · THE DOUBLE-PREFIXED ORDER DOCUMENT ──────────────────
        //
        // This read `"{$this->school_name}_{$recordId}"`. But `record_id` is
        // ALREADY the full document id — Payment_service::verify_payment()
        // returns `$docId` straight from `_orderDocId()`, which is itself
        // `"{$schoolId}_{$gatewayOrderId}"`. Prefixing it again produced
        //
        //     SCH_XXXX_SCH_XXXX_order_yyy
        //
        // so EVERY order-state transition this method performs — 'processing',
        // 'paid', 'fees_failed', 'amount_mismatch', receipt_key, receipt_no,
        // payment_status — was written to a phantom document that nothing ever
        // reads, while the real order stayed stuck at 'verified' forever.
        //
        // ── WHY THAT IS WORSE THAN COSMETIC ─────────────────────────────────
        //   • verify_payment()'s already-paid short-circuit keys on
        //     status === 'paid', which was never written. A repeat
        //     verification re-entered the money pipeline; only the LATER
        //     feeProcessedPayments marker (H1-F1) stopped a second receipt.
        //   • the admin Retry path refuses anything that is not 'fees_failed'
        //     — a status this method could never set — so a genuinely failed
        //     fee write was unrecoverable by the one tool built to recover it.
        //   • reconciliation reads feeOnlineOrders, and it was reading a
        //     document frozen at the wrong state.
        //   • one orphan document per payment accumulated forever.
        //
        // It affects the FRONTEND path identically — this method is shared —
        // so this is not a webhook-only fix.
        //
        // Built from `$gwOrderId` here, exactly as parent_verify_payment
        // (~L4528) and _webhook_record_non_success already do. Three sites now
        // agree, and this is the only one that ever disagreed. `$recordId` is
        // left untouched: it is still what the feeOnlinePayments record stores
        // as `order_id`, and changing that would rewrite a persisted shape.
        //
        // FOUND AT RUNTIME, not by reading: the end-to-end harness asserted the
        // order reached 'paid' and it was still 'verified', with a
        // double-prefixed twin sitting in the emulator holding the real state.
        $orderDocId = "{$this->school_name}_{$gwOrderId}";

        // ── C. Strict amount validation (BUG-050 / PS-3 restored) ──
        // Pre-PS-3 this guard was structurally complete but INERT —
        // verify_payment() never populated $verifyResult['gateway_amount']
        // so the ?? fallback collapsed the comparison to abs(0) > 0.50,
        // which is permanently false. Payment_service::verify_payment now
        // returns the authoritative captured amount via gateway fetch.
        // Policy:
        //   - gateway_amount populated + mismatch    → FAIL CLOSED (this branch)
        //   - gateway_amount null + gateway=razorpay → FAIL OPEN + telemetry (sig was valid; do not brick legit collection on Razorpay API outage)
        //   - gateway_amount null + gateway=mock     → FAIL OPEN silently (mock has no real captured amount)
        $orderAmount      = floatval($order['amount'] ?? 0);
        $gwReportedAmount = $verifyResult['gateway_amount'] ?? null;  // float|null in rupees
        $gatewayName      = (string) ($verifyResult['gateway_name'] ?? ($order['gateway'] ?? 'unknown')); // P11: no fabricated provider default

        if ($gwReportedAmount === null) {
            // GW-2: fetch unavailable — fail open, but REPORT IT unless the
            // adapter is one that structurally cannot supply an amount. Was
            // `=== 'razorpay'`, which silenced every non-Razorpay provider,
            // including any added later. See the parent-path site for the full
            // rationale.
            // Same rule as parent_verify_payment above.
            $__reportsAmt2 = $verifyResult['gateway_reports_amount'] ?? null;
            $__shouldAlert2 = $__reportsAmt2 === null
                ? !in_array($gatewayName, self::AMOUNTLESS_GATEWAYS, true)
                : $__reportsAmt2;
            if ($__shouldAlert2) {
                if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                    $this->sec_telem->emit('PAYMENT_AMOUNT_UNVERIFIED', 'warning', [
                        'endpoint'     => '_verify_and_process',
                        'source'       => $source,
                        'order_id'     => $gwOrderId,
                        'payment_id'   => $gwPaymentId,
                        'order_amount' => $orderAmount,
                    ]);
                }
                log_message('error', "verify_and_process({$source}): gateway_amount UNAVAILABLE order={$gwOrderId} — proceeding on signature-only");
            }
            // No fail; gateway fetch unavailable does not block the flow.
        } elseif ($this->_gatewayAmountMismatch(
            $orderAmount, $gwReportedAmount,
            $verifyResult['gateway_amount_minor'] ?? null)) {
            // FAIL CLOSED — captured amount differs from order amount by >₹0.50.
            log_message('error', "verify_and_process({$source}): AMOUNT MISMATCH order={$gwOrderId} expected={$orderAmount} got={$gwReportedAmount}");
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'          => 'amount_mismatch',
                'expected_amount' => $orderAmount,
                'gateway_amount'  => $gwReportedAmount,
                'flagged_at'      => date('c'),
                'flagged_source'  => $source,
            ], true);
            // Emit Fee_audit amount_mismatch event (severity=critical per Fee_audit.php:113).
            try {
                $this->load->library('Fee_audit', null, 'feeAudit');
                $this->feeAudit->init(
                    $this->firebase, "{$this->sessionRoot}/Fees",
                    $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                    $this->school_name
                );
                // Firestore audit + critical alert (amount_mismatch ∈ ALERT_EVENTS). No RTDB.
                $this->feeAudit->log('amount_mismatch', [
                    'order_id'   => $gwOrderId,
                    'payment_id' => $gwPaymentId,
                    'student_id' => (string) ($order['student_id'] ?? ''),
                    'expected'   => $orderAmount,
                    'gateway'    => $gwReportedAmount,
                    'source'     => $source,
                ]);
            } catch (\Exception $e) {
                log_message('error', 'verify_and_process: Fee_audit emit failed: ' . $e->getMessage());
            }
            if (isset($this->sec_telem) && $this->sec_telem->isReady()) {
                $this->sec_telem->emit('PAYMENT_AMOUNT_MISMATCH', 'critical', [
                    'endpoint'   => '_verify_and_process',
                    'source'     => $source,
                    'order_id'   => $gwOrderId,
                    'payment_id' => $gwPaymentId,
                    'expected'   => $orderAmount,
                    'gateway'    => $gwReportedAmount,
                ]);
            }
            $releaseLock();
            // Terminal: the captured amount will not change on redelivery.
            return ['ok' => false, 'retryable' => false, 'error' => 'Payment amount does not match order amount.'];
        }
        // gateway_amount populated AND within tolerance → proceed.

        // ── D. Transition: verified → processing ──
        $now = date('c');
        $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
            'status'              => 'processing',
            'processing_started'  => $now,
            'gateway_payment_id'  => $gwPaymentId,
            'process_source'      => $source,
            'process_lock_token'  => $lockToken,
            'webhook_received_at' => ($source === 'webhook') ? $now : null,
        ], true);

        // ── E. Process fee collection via FeeCollectionService (P3.C) ──
        // Same receipt/allocation/monthFee/wallet pipeline as
        // parent_verify_payment (P3.B), just with the webhook's auth
        // chain upstream. Razorpay-specific post-processing
        // (markPaymentProcessed, order status, feeOnlinePayments) stays
        // here — the service never has to know about gateway state.
        require_once APPPATH . 'services/FeeCollectionService.php';
        $service   = new FeeCollectionService();
        $feeMonths = is_array($order['fee_months'] ?? null) ? $order['fee_months'] : [];
        $studentId = (string) ($order['student_id'] ?? '');

        $feeSuccess = false;
        $receiptKey = '';
        $receiptNo  = '';
        $feeError   = '';
        // PAY-OPS-6: FeeCollectionService's own http_code for the refusal, which
        // is what separates "try again" from "this will never succeed".
        $feeHttpCode = 0;

        try {
            $serviceResult = $service->submit($this, [
                'source'                     => 'parent-razorpay-' . $source,  // 'parent-razorpay-frontend' or 'parent-razorpay-webhook'
                'admin_id'                   => 'parent_app',
                'admin_name'                 => 'parent_app',
                'school_name'                => $this->school_name,
                'session_year'               => $this->session_year,
                'safe_user_id'               => $studentId,
                'amount'                     => (float) ($order['amount'] ?? 0),
                'discount'                   => 0.0,
                'fine'                       => 0.0,
                'submit_amount'              => 0.0,
                'selected_months'            => $feeMonths,
                'payment_mode'               => 'Online',   // ACC-PM1: canonical, provider-neutral
                'remarks'                    => "Razorpay {$gwPaymentId}",
                'collected_by'               => 'parent_app',
                'created_by'                 => 'parent_app',
                // Preserve Razorpay's 6-hex txn_id format byte-exact with P3.B.
                'txn_id'                     => 'RZP_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
                'batch_mode'                 => true,
                'skip_duplicate_month_guard' => true,
                // Keep PAY-OLDER-FIRST active (was enforced by
                // _record_parent_payment_receipt pre-P3.B; service re-runs it).
                'defer_defaulter'            => true,
                'defer_accounting'           => true,  // Q4(i): accounting now runs via service's deferred handler (was inline in _process_online_fee_collection).
                'write_response_to_output'   => false,
                // Preserve the webhook-era receipt fields the parent app
                // relies on (Q3-A); these flat fields only appear on
                // Razorpay-recorded receipts.
                'extra_receipt_fields'       => [
                    'orderId'   => $gwOrderId,
                    'paymentId' => $gwPaymentId,
                    // ACC-PM1: provider identity is provenance, NOT accounting
                    // classification. payment_mode must stay provider-neutral
                    // because Operations_accounting uses it to pick the
                    // cash/bank ledger.
                    //
                    // PA-CORE-1: $gatewayName is computed above from
                    // Payment_service::verify_payment()'s get_name() — not a
                    // literal. See parent_verify_payment for full rationale.
                    'gateway'   => $gatewayName,
                ],
            ]);

            if (is_array($serviceResult) && ($serviceResult['ok'] ?? false)) {
                $feeSuccess = true;
                $receiptKey = (string) ($serviceResult['data']['receipt_key'] ?? '');
                $receiptNo  = (string) ($serviceResult['data']['receipt_no']  ?? '');
            } else {
                $feeError = is_array($serviceResult) ? (string) ($serviceResult['message'] ?? 'Service returned failure.') : 'Null service result.';
                // ── PAY-OPS-6 · THE SERVICE ALREADY SAID WHICH KIND OF FAILURE ──
                // FeeCollectionService returns an http_code with every refusal
                // and this call site discarded it, so "the datastore blinked"
                // and "this payment is not allowed" were indistinguishable and
                // both were answered by inviting the gateway to redeliver.
                // 0 = the service returned no code at all → treated as unknown,
                // which _fee_failure_is_permanent() resolves to RETRYABLE.
                $feeHttpCode = is_array($serviceResult) ? (int) ($serviceResult['http_code'] ?? 0) : 0;
                log_message('error', "verify_and_process({$source}): service FAILED order={$gwOrderId} code={$feeHttpCode}: {$feeError}");
            }
        } catch (\Exception $e) {
            $feeError = $e->getMessage();
            // A thrown exception is an internal fault, never a business verdict:
            // the service refuses deliberately by RETURNING, not by throwing.
            $feeHttpCode = 0;
            log_message('error', "verify_and_process({$source}): fees threw order={$gwOrderId}: {$feeError}");
        }

        if ($feeSuccess) {
            // ── F. Success → paid + payment record ──
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'         => 'paid',
                'paid_at'        => date('c'),
                'receipt_key'    => $receiptKey,
                'receipt_no'     => $receiptNo,
                'payment_status' => 'captured',
            ], true);

            // Q5(a): the feeProcessedPayments marker is now written for
            // BOTH frontend and webhook paths. Any future verify retry
            // short-circuits at parent_verify_payment L3051 regardless
            // of which path originally processed the payment.
            try {
                $this->fsTxn->markPaymentProcessed(
                    $gwPaymentId,
                    $receiptNo,
                    $receiptKey,
                    $studentId,
                    $gwOrderId
                );
            } catch (\Throwable $e) {
                // \Throwable, not \Exception (FEE-W1): a missing collaborator
                // raises an Error, which \Exception does not catch. This call is
                // deliberately non-fatal — the receipt, allocations and journal
                // are already durable at this point, and the writes that follow
                // (feeOnlinePayments, claim release, webhook dedup marker) must
                // not be skipped because a bookkeeping marker failed.
                log_message('error', "verify_and_process({$source}): markPaymentProcessed failed [{$gwPaymentId}]: " . $e->getMessage());
            }

            $payRecId = 'PAY_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('feeOnlinePayments', "{$this->school_name}_{$payRecId}", [
                'schoolId'           => $this->school_name,
                'order_id'           => $recordId,
                'gateway_order_id'   => $gwOrderId,
                'gateway_payment_id' => $gwPaymentId,
                'student_id'         => $studentId,
                'student_name'       => $order['student_name'] ?? '',
                'amount'             => (float) ($order['amount'] ?? 0),
                'receipt_key'        => $receiptKey,
                // P11: was `?? 'mock'`. This is a PERSISTED money record that
                // reconciliation reads; an order with unrecorded provenance must
                // stay visibly unrecorded, never be labelled as a simulator.
                'gateway'            => $order['gateway'] ?? 'unknown',
                'payment_status'     => 'captured',
                'source'             => $source,
                'signature'          => substr($signature, 0, 16) . '...',
                'created_at'         => date('c'),
            ]);

            log_message('info', "verify_and_process({$source}): SUCCESS order={$gwOrderId} receipt={$receiptKey}");
            $releaseLock();

            return ['ok' => true, 'fee_success' => true, 'retryable' => false, 'receipt_key' => $receiptKey];
        } else {
            // ── G. Fee writes failed → retryable ──
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'         => 'fees_failed',
                'payment_status' => 'captured',
                'failed_at'      => date('c'),
                'failure_reason' => $feeError,
                'signature'      => $signature,
                // PAY-OPS-6 · so the reconciliation screen can tell an operator
                // WHICH kind of failure this is. 'business' will not resolve by
                // waiting or by pressing Retry — the underlying refusal has to
                // be dealt with first (e.g. clear the older month) — whereas
                // 'transient' very often will.
                'failure_class'  => self::_fee_failure_is_permanent($feeHttpCode) ? 'business' : 'transient',
                'failure_code'   => $feeHttpCode,
            ], true);

            $releaseLock();

            $__permanent = self::_fee_failure_is_permanent($feeHttpCode);

            return [
                'ok'          => true,
                'fee_success' => false,
                // ── PAY-OPS-6 · A BUSINESS REFUSAL IS NOT A HICCUP ──────────
                // Both of these outcomes leave the gateway holding the money,
                // but they need opposite handling:
                //
                //   423 lock / 5xx / thrown  → try again; it may well work
                //   409 / 422 business rule  → it will NEVER work; retrying
                //                              just burns the provider's retry
                //                              budget and buries the payment
                //
                // Pay-older-first is the live example: a parent paying June
                // while April is outstanding is refused with 409 EVERY time, so
                // "retryable" was a promise the system could not keep. The
                // money still has to be recoverable, which is why the order is
                // written to `fees_failed` either way — the state the admin
                // Retry path keys on — and the webhook is ACKNOWLEDGED (200)
                // rather than 503 so the gateway stops asking and the operator
                // owns it. Terminal for retries, open for humans.
                'retryable'      => !$__permanent,
                'business_refusal' => $__permanent,
                'fee_http_code'  => $feeHttpCode,
                // -- PAY-OPS-4 . THIS IS THE ONE THAT COST MONEY ------------
                // The gateway HAS the funds and ZenX failed to record them.
                // Retrying is not merely permitted here, it is the entire
                // recovery mechanism - and the webhook handler used to stamp
                // this outcome as 'processed', so every subsequent gateway
                // redelivery was answered "already processed" and the payment
                // could never be reconciled by any automatic means.
                //
                // Safe to retry: a re-entry short-circuits on the
                // feeProcessedPayments marker (A0) or on feeOnlineOrders
                // .status='paid' if the money DID land, and FeeCollectionService
                // rolls back its claim/receipt/counter reservations on abort -
                // which is precisely why the admin Retry path already exists and
                // re-enters this same pipeline for orders in this exact state.
                //
                // PAY-OPS-6 REFINES THIS, IT DOES NOT REVERSE IT. `retryable`
                // is now set ONCE, above, from the service's own http_code —
                // still true for every transient fault, false only for a
                // business verdict that would refuse identically forever.
                //
                // There WAS a second `'retryable' => true,` here. A duplicate
                // key in a PHP array literal is not an error: the later one
                // silently wins, so the classification computed above was
                // overwritten and every business refusal went out as retryable
                // anyway. The unit tests could not see it — they drive
                // _webhook_outcome() with a hand-built array — and it was
                // caught only by the end-to-end run asserting the OUTCOME
                // (503 + state 'retry' where 200 + 'business_refused' was
                // required). Exactly the same-key collision the Parent App's
                // Razorpay options block was bitten by.
                'fee_error'   => "Gateway confirmed but fee recording failed: {$feeError}",
                'error'       => "Gateway confirmed but fee recording failed: {$feeError}",
            ];
        }
    }

    /**
     * POST — Retry fee processing for a failed online payment.
     * Only works for orders in "fees_failed" status.
     * Params: order_record_id
     */
    public function retry_payment_processing()
    {
        $this->_require_role(['Admin'], 'retry_payment_processing', 'Fees', 'manage');

        $recordId = trim($this->input->post('order_record_id') ?? '');
        if ($recordId === '') $this->json_error('Order record ID is required.');

        $orderDocId = "{$this->school_name}_{$recordId}";
        $order = $this->firebase->firestoreGet('feeOnlineOrders', $orderDocId);

        if (!is_array($order)) $this->json_error('Order not found.');
        if (($order['status'] ?? '') === 'paid') {
            $this->json_success(['message' => 'Order already paid. No retry needed.', 'already_paid' => true]);
            return;
        }
        if (($order['status'] ?? '') !== 'fees_failed') {
            $this->json_error('Only orders with status "fees_failed" can be retried. Current: ' . ($order['status'] ?? 'unknown'));
        }

        // Transition to processing
        $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
            'status'         => 'processing',
            'retry_at'       => date('c'),
            'retry_by'       => $this->admin_name ?? '',
        ], true);

        $gwPaymentId = $order['gateway_payment_id'] ?? '';
        $gwOrderId   = $order['gateway_order_id'] ?? '';
        $studentId   = (string) ($order['student_id'] ?? '');
        $feeMonths   = is_array($order['fee_months'] ?? null) ? $order['fee_months'] : [];

        // Ensure fs/fsTxn loaded (needed for markPaymentProcessed post-success).
        if (!isset($this->fs)) {
            $this->load->library('Firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
        }
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);

        // ── B-025 · THE THIRD COMPLETION PATH ────────────────────────────────
        // This is the third place that hands a payment to FeeCollectionService,
        // and it was missed when the claim was added to the other two. Its own
        // guards (Admin-only, status must be 'fees_failed', and an already-paid
        // short-circuit) narrow the window but do not close it: the paid check
        // is a READ followed by an unconditional status write, so a webhook
        // redelivery landing between them races an admin clicking Retry, and
        // both would mint a receipt for the same money.
        //
        // Unlike the other two paths this one does NOT call verify_payment(),
        // so it does not get that method's already_paid short-circuit either.
        // The payment marker is therefore checked here explicitly.
        $this->load->library('Fee_payment_claim', null, 'paymentClaim');
        $this->paymentClaim->init($this->firebase, $this->school_name);

        $__retryClaim = $this->paymentClaim->acquire($gwOrderId, 'admin-retry');
        if ($__retryClaim['status'] !== Fee_payment_claim::ACQUIRED) {
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status' => 'fees_failed',   // restore: we did not process it
            ], true);
            $this->json_error(
                $__retryClaim['status'] === Fee_payment_claim::IN_PROGRESS
                    ? 'This payment is currently being processed by another request. Please wait and retry.'
                    : 'Could not confirm the state of this payment. Nothing has been applied. Please retry in a moment.'
            );
            return;
        }

        // A retry is precisely the case where the payment may ALREADY have been
        // applied by the webhook while the order row still said fees_failed.
        $__prior = $this->fsTxn->getProcessedPayment($gwPaymentId);
        if (is_array($__prior)) {
            $this->paymentClaim->release($gwOrderId, $__retryClaim['token']);
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'      => 'paid',
                'receipt_key' => (string) ($__prior['receiptKey'] ?? ''),
                'receipt_no'  => (string) ($__prior['receiptNo'] ?? ''),
                'paid_at'     => date('c'),
            ], true);
            $this->json_success([
                'message'     => 'This payment was already processed. The order has been reconciled.',
                'already_paid' => true,
                'receipt_key' => (string) ($__prior['receiptKey'] ?? ''),
            ]);
            return;
        }

        // P3.C Q6(c): retry uses the same service-based pipeline as
        // _verify_and_process instead of the legacy
        // _process_online_fee_collection. Same flag set for consistency;
        // the only difference vs webhook is the txn_id prefix, which we
        // keep as 'RZP_' because the underlying payment WAS Razorpay.
        require_once APPPATH . 'services/FeeCollectionService.php';
        $service = new FeeCollectionService();

        try {
            $serviceResult = $service->submit($this, [
                'source'                     => 'parent-razorpay-retry',
                'admin_id'                   => $this->admin_id ?? 'admin',
                'admin_name'                 => $this->admin_name ?? 'admin',
                'school_name'                => $this->school_name,
                'session_year'               => $this->session_year,
                'safe_user_id'               => $studentId,
                'amount'                     => (float) ($order['amount'] ?? 0),
                'discount'                   => 0.0,
                'fine'                       => 0.0,
                'submit_amount'              => 0.0,
                'selected_months'            => $feeMonths,
                'payment_mode'               => 'Online',   // ACC-PM1: canonical, provider-neutral
                'remarks'                    => "Razorpay {$gwPaymentId} (retry)",
                'collected_by'               => 'parent_app',
                'created_by'                 => $this->admin_id ?? 'admin',
                'txn_id'                     => 'RZP_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
                'batch_mode'                 => true,
                'skip_duplicate_month_guard' => true,
                'defer_defaulter'            => true,
                'defer_accounting'           => true,
                'write_response_to_output'   => false,
                'extra_receipt_fields'       => [
                    'orderId'   => $gwOrderId,
                    'paymentId' => $gwPaymentId,
                    // ACC-PM1: provider identity is provenance, NOT accounting
                    // classification. payment_mode must stay provider-neutral
                    // because Operations_accounting uses it to pick the
                    // cash/bank ledger.
                    //
                    // PA-CORE-1: this path does not call verify_payment() (see
                    // the note above it), so there is no fresh gateway_name to
                    // read. The order document itself is the truthful source —
                    // Payment_service::create_order wrote it from get_name()
                    // at creation time, and GW-ORD-1 already trusts this same
                    // field for provenance matching. Falls back to 'unknown',
                    // never to 'razorpay' — an unrecorded gateway must stay
                    // visibly unrecorded, not silently become the incumbent.
                    'gateway'   => (string) ($order['gateway'] ?? 'unknown'),
                ],
            ]);

            if (!is_array($serviceResult) || !($serviceResult['ok'] ?? false)) {
                $msg = is_array($serviceResult) ? (string) ($serviceResult['message'] ?? 'Service returned failure.') : 'Null service result.';
                throw new \RuntimeException($msg);
            }

            $receiptKey = (string) ($serviceResult['data']['receipt_key'] ?? '');
            $receiptNo  = (string) ($serviceResult['data']['receipt_no']  ?? '');

            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'      => 'paid',
                'paid_at'     => date('c'),
                'receipt_key' => $receiptKey,
                'receipt_no'  => $receiptNo,
            ], true);

            // Q5(a): mark payment-id so future frontend verify retries
            // short-circuit via parent_verify_payment L3051.
            try {
                $this->fsTxn->markPaymentProcessed($gwPaymentId, $receiptNo, $receiptKey, $studentId, $gwOrderId);
            } catch (\Throwable $e) {
                // \Throwable, not \Exception (FEE-W1) — see the other two marker
                // sites. Best-effort bookkeeping after the money is durable.
                log_message('error', "retry_payment_processing: markPaymentProcessed failed [{$gwPaymentId}]: " . $e->getMessage());
            }

            // Write payment record
            $payRecId = 'PAY_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('feeOnlinePayments', "{$this->school_name}_{$payRecId}", [
                'schoolId'           => $this->school_name,
                'order_id'           => $recordId,
                'gateway_order_id'   => $gwOrderId,
                'gateway_payment_id' => $gwPaymentId,
                'student_id'         => $studentId,
                'amount'             => (float) ($order['amount'] ?? 0),
                'receipt_key'        => $receiptKey,
                // P11: was `?? 'mock'`. This is a PERSISTED money record that
                // reconciliation reads; an order with unrecorded provenance must
                // stay visibly unrecorded, never be labelled as a simulator.
                'gateway'            => $order['gateway'] ?? 'unknown',
                'status'             => 'success',
                'created_at'         => date('c'),
                'retried'            => true,
            ]);

            log_message('info', "retry_payment_processing: SUCCESS order={$recordId} receipt={$receiptKey}");

            // B-025: money is durable and the marker committed with it.
            $this->paymentClaim->release($gwOrderId, $__retryClaim['token']);

            $this->json_success([
                'message'    => 'Fee recording completed successfully on retry.',
                'receipt_no' => str_replace('F', '', $receiptKey),
            ]);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception (FEE-W1): a missing collaborator raises
            // an Error, which \Exception does not catch — and this handler is
            // what restores the order to a retryable state.
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'         => 'fees_failed',
                'failed_at'      => date('c'),
                'failure_reason'  => $e->getMessage(),
                'retry_count'    => ((int) ($order['retry_count'] ?? 0)) + 1,
            ], true);

            // Nothing was applied — free the order so the next retry (or the
            // webhook) is not blocked for the remainder of the lease.
            $this->paymentClaim->release($gwOrderId, $__retryClaim['token']);

            $this->json_error('Retry failed: ' . $e->getMessage());
        }
    }


    // ══════════════════════════════════════════════════════════════════
    //  DASHBOARD / ANALYTICS HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fee summary for dashboard stats.
     * Returns: total_collected, total_due, total_discounts, total_scholarships, total_refunds
     */
    public function get_fee_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_fee_summary', 'Fees', 'view');
        $schoolFs = $this->school_name;
        $sy       = $this->session_year;

        // Check for cached summary (valid for 5 minutes)
        $cacheDocId = "{$schoolFs}_{$sy}_summary";
        $cached = $this->firebase->firestoreGet('feeSettings', $cacheDocId);
        if (is_array($cached) && !empty($cached['as_of'])) {
            $cacheAge = time() - strtotime($cached['as_of']);
            if ($cacheAge < 300) { // 5 minutes
                $this->json_success($cached);
            }
        }

        $totalCollected    = 0;
        $totalDue          = 0;
        $totalDiscounts    = 0;
        $totalScholarships = 0;
        $totalRefunds      = 0;

        // --- Total collected: sum from fee receipts ---
        $receipts = $this->firebase->firestoreQuery('feeReceipts', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sy],
        ]);
        foreach ((array) $receipts as $row) {
            $d = $row['data'] ?? $row;
            // Phase 16: net_receivable math uses allocated_amount (sum of
            // demand allocations). Post-Phase-9, this equals input_amount
            // because overpayment is rejected, but keep the field for
            // compatibility with pre-P9 receipts that carry both.
            $amt = floatval($d['allocated_amount']
                          ?? $d['allocatedAmount']
                          ?? $d['amount']
                          ?? 0);
            if ($amt > 0) $totalCollected += $amt;
        }

        // --- Total due + session discounts: from fee demands (session-scoped) ---
        // SESSION-BLEED FIX (2026-09-19): the discount total previously summed the
        // LIFETIME `studentDiscounts` docs (keyed {school}_{studentId}, NO session
        // field), so a per-session summary subtracted PRIOR years' discounts from
        // THIS session's due — under-stating net_receivable after a rollover. Derive
        // the discount from THIS session's demands instead: each demand carries
        // `discountAmount` (coherent with netAmount = grossAmount − discountAmount),
        // read from the SAME session-scoped query already used for the due total, so
        // the discount figure is now session-consistent with every other line here.
        $demands = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sy],
        ]);
        foreach ((array) $demands as $row) {
            $d = $row['data'] ?? $row;
            $balance = floatval($d['balance'] ?? 0);
            if ($balance > 0) $totalDue += $balance;
            $totalDiscounts += floatval($d['discountAmount'] ?? $d['discount_amount'] ?? 0);
        }

        // --- Total scholarships (session-scoped) ---
        // SESSION-BLEED FIX (2026-09-20): scope active awards to the CURRENT
        // session, consistent with the collected/due/discount lines above, so a
        // per-session summary never carries a prior year's award into this year's
        // net_receivable. (scholarshipAwards is a retired/empty collection today,
        // so the sum is 0 either way — the filter keeps it correct if it is ever
        // repopulated and stamps a session onto awards.)
        $awardRows = $this->firebase->firestoreQuery('scholarshipAwards', [
            ['schoolId', '==', $schoolFs],
            ['status',   '==', 'active'],
            ['session',  '==', $sy],
        ]);
        foreach ((array) $awardRows as $row) {
            $d = $row['data'] ?? $row;
            $totalScholarships += floatval($d['amount'] ?? 0);
        }

        // --- Total refunds ---
        $this->_bootFsTxn();
        $refundRows = $this->fsTxn->listRefunds('processed');
        foreach ((array) $refundRows as $row) {
            $d = $row['data'] ?? $row;
            $totalRefunds += floatval($d['amount'] ?? 0);
        }

        $result = [
            'total_collected'    => round($totalCollected, 2),
            'total_due'          => round($totalDue, 2),
            'total_discounts'    => round($totalDiscounts, 2),
            'total_scholarships' => round($totalScholarships, 2),
            'total_refunds'      => round($totalRefunds, 2),
            'net_receivable'     => round($totalDue - $totalDiscounts - $totalScholarships, 2),
            'as_of'              => date('Y-m-d H:i:s'),
        ];

        // Cache the result
        $result['schoolId'] = $schoolFs;
        $result['session']  = $sy;
        $result['type']     = 'summary_cache';
        $this->firebase->firestoreSet('feeSettings', $cacheDocId, $result);

        $this->json_success($result);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE CARRY-FORWARD (F-15)
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST — Carry forward unpaid fees from previous session.
     * Params: from_session (e.g. "2025-26"), to_session (e.g. "2026-27")
     *
     * DEPRECATED 2026-04-17 — Carry-forward is now folded into
     * Year Rollover (`Fees::year_rollover_execute`). New code paths
     * should call that. This endpoint remains as a standalone tool
     * only for recovery / re-run scenarios.
     */
    public function carry_forward_fees()
    {
        $this->_require_role(self::ADMIN_ROLES, 'carry_forward_fees', 'Fees', 'manage');

        $fromSession = trim($this->input->post('from_session') ?? '');
        $toSession   = trim($this->input->post('to_session') ?? '');
        $sn          = $this->school_name;

        // Log that the deprecated endpoint was hit so we can eventually
        // retire it once usage goes to zero.
        log_message('error', "DEPRECATED carry_forward_fees called by admin_id={$this->admin_id} from={$fromSession} to={$toSession}. Prefer year_rollover_execute.");

        if (empty($fromSession) || empty($toSession)) {
            $this->json_error('Both from_session and to_session are required.');
        }
        if ($fromSession === $toSession) {
            $this->json_error('Source and target sessions must be different.');
        }

        $schoolFs = $sn;

        // Read fee demands from old session to find unpaid
        $oldDemands = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $fromSession],
        ]);

        // Read students from old session
        $oldStudents = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $schoolFs],
        ]);
        $studentMap = [];
        foreach ((array) $oldStudents as $row) {
            $d = $row['data'] ?? $row;
            $sid = (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? ''));
            $studentMap[$sid] = $d;
        }

        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $carriedForward = [];
        $totalStudents = 0;
        $totalAmount = 0;

        // Group demands by student and find unpaid (balance > 0)
        $studentDemands = [];
        foreach ((array) $oldDemands as $row) {
            $d = $row['data'] ?? $row;
            $sid = (string) ($d['studentId'] ?? '');
            $balance = floatval($d['balance'] ?? 0);
            $period  = (string) ($d['period'] ?? '');
            if ($sid === '' || $balance <= 0 || $period === '') continue;
            if (!isset($studentDemands[$sid])) $studentDemands[$sid] = [];
            $studentDemands[$sid][] = ['period' => $period, 'balance' => $balance];
        }

        foreach ($studentDemands as $userId => $demands) {
            $unpaidMonths = [];
            $unpaidAmount = 0;
            foreach ($demands as $dem) {
                $unpaidMonths[] = $dem['period'];
                $unpaidAmount += $dem['balance'];
            }
            if ($unpaidAmount > 0) {
                $stuData = $studentMap[$userId] ?? [];
                $carriedForward[$userId] = [
                    'student_name'  => (string) ($stuData['name'] ?? $stuData['studentName'] ?? $userId),
                    'class'         => (string) ($stuData['className'] ?? ''),
                    'section'       => (string) ($stuData['section'] ?? ''),
                    'unpaid_months' => $unpaidMonths,
                    'amount'        => round($unpaidAmount, 2),
                    'from_session'  => $fromSession,
                ];
                $totalStudents++;
                $totalAmount += $unpaidAmount;
            }
        }

        if (empty($carriedForward)) {
            $this->json_success(['message' => 'No unpaid fees found to carry forward.', 'count' => 0]);
            return;
        }

        // Write carry-forward records to Firestore
        foreach ($carriedForward as $userId => $cfData) {
            $this->firebase->firestoreSet('feeCarryForward', "{$schoolFs}_{$toSession}_{$userId}", array_merge($cfData, [
                'schoolId'    => $schoolFs,
                'session'     => $toSession,
                'studentId'   => $userId,
                'created_at'  => date('c'),
                'created_by'  => $this->session->userdata('admin_name') ?? 'Admin',
            ]));
        }

        $this->json_success([
            'message' => "Carried forward unpaid fees for {$totalStudents} student(s). Total: Rs. " . number_format($totalAmount, 2),
            'count'   => $totalStudents,
            'total'   => round($totalAmount, 2),
        ]);
    }

    // ====================================================================
    //  PAYMENT RECONCILIATION
    // ====================================================================

    /** GET — Payment Reconciliation page */
    public function payment_reconciliation()
    {
        $this->_require_role(self::ADMIN_ROLES, '', 'Fees', 'manage');
        $this->load->view('include/header');
        $this->load->view('fee_management/payment_reconciliation');
        $this->load->view('include/footer');
    }

    /** POST — Get reconciliation data */
    public function get_reconciliation_data()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_reconciliation_data', 'Fees', 'manage');

        $dateFrom  = trim($this->input->post('date_from') ?? '');
        $dateTo    = trim($this->input->post('date_to') ?? '');
        $studentId = trim($this->input->post('student_id') ?? '');
        $status    = trim($this->input->post('status') ?? '');

        $schoolFs = $this->school_name;
        $sy       = $this->session_year;

        // Load all orders from Firestore
        $orderRows = $this->firebase->firestoreQuery('feeOnlineOrders', [
            ['schoolId', '==', $schoolFs],
        ]);
        $allOrders = [];
        foreach ((array) $orderRows as $row) {
            $d = $row['data'] ?? $row;
            $rid = $this->_stripSchoolPrefix($row['id'] ?? '');
            $allOrders[$rid] = $d;
        }

        // Load all payment records
        $payRows = $this->firebase->firestoreQuery('feeOnlinePayments', [
            ['schoolId', '==', $schoolFs],
        ]);
        $allPayments = [];
        foreach ((array) $payRows as $row) {
            $d = $row['data'] ?? $row;
            $pid = $this->_stripSchoolPrefix($row['id'] ?? '');
            $allPayments[$pid] = $d;
        }

        // Load receipt index for cross-check
        $receiptRows = $this->firebase->firestoreQuery('feeReceiptIndex', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sy],
        ]);
        $receiptIdx = [];
        foreach ((array) $receiptRows as $row) {
            $d = $row['data'] ?? $row;
            $rn = (string) ($d['receiptNo'] ?? '');
            if ($rn !== '') $receiptIdx[$rn] = $d;
        }

        // Build receipt lookup by order_id for orphan detection
        $receiptsByOrder = [];
        foreach ($receiptIdx as $rn => $ri) {
            if (!is_array($ri)) continue;
            $oid = $ri['order_id'] ?? '';
            if ($oid) $receiptsByOrder[$oid] = $rn;
        }

        $successful   = [];
        $feesFailed   = [];
        $orphans      = [];
        $duplicates   = [];
        $stats        = ['total' => 0, 'paid' => 0, 'failed' => 0, 'orphan' => 0, 'duplicate' => 0, 'total_amount' => 0, 'failed_amount' => 0];

        // Track order_ids seen in payments for duplicate detection.
        //
        // Phase 1.X (2026-05-09) — field-name correction. Canonical
        // feeOnlinePayments schema (Razorpay-era, snake_case) emits
        // `order_id`, NOT `gateway_order_id`. Reading the wrong field
        // left $orderIdsInPayments empty, so $hitCount > 1 was never
        // true and the Duplicates tab never populated — even when a
        // single order legitimately had multiple webhook events
        // (verified live: order_SgFq8BpdRjVFA3 received webhook
        // payloads on 2026-04-21 AND 2026-05-08). camelCase fallback
        // is preserved defensively in case a future writer emits the
        // alternate shape.
        $orderIdsInPayments = [];
        foreach ($allPayments as $pid => $pay) {
            if (!is_array($pay)) continue;
            $oid = $pay['order_id'] ?? $pay['gateway_order_id'] ?? '';
            if ($oid) $orderIdsInPayments[$oid] = ($orderIdsInPayments[$oid] ?? 0) + 1;
        }

        foreach ($allOrders as $rid => $order) {
            if (!is_array($order)) continue;

            $oStatus = $order['status'] ?? '';
            $oDate   = substr($order['created_at'] ?? '', 0, 10);
            $oStudent = $order['student_id'] ?? '';
            $oAmount  = (float) ($order['amount'] ?? 0);
            $gwOrder  = $order['gateway_order_id'] ?? '';

            // Date filter
            if ($dateFrom && $oDate < $dateFrom) continue;
            if ($dateTo && $oDate > $dateTo) continue;
            if ($studentId && $oStudent !== $studentId) continue;
            if ($status && $oStatus !== $status) continue;

            $stats['total']++;
            $stats['total_amount'] += $oAmount;

            $item = [
                'record_id'    => $rid,
                'order_id'     => $gwOrder,
                'student_id'   => $oStudent,
                'student_name' => $order['student_name'] ?? '',
                'amount'       => $oAmount,
                'status'       => $oStatus,
                'receipt_key'  => $order['receipt_key'] ?? '',
                // P11 (2026-08-24): was `?? 'mock'`. Same provenance defect as
                // PA-CORE-1's `?? 'razorpay'` in fetch_online_payments, and
                // sharper here: this is the RECONCILIATION report — the screen
                // an operator uses to investigate payment discrepancies, and
                // the one place a provenance-less order most needs to be
                // visible AS provenance-less. Labelling it 'mock' actively
                // suggests "simulated, not real money", which invites an
                // operator to dismiss a genuine orphan as a test artifact.
                // GW-ORD-1 treats unrecorded provenance as unsafe to reuse;
                // reconciliation must show it the same way.
                'gateway'      => $order['gateway'] ?? 'unknown',
                // ── PAY-OPS-6 · WHICH KIND OF FAILURE, FOR THE OPERATOR ──────
                // A `fees_failed` order always means the same alarming thing —
                // the gateway captured money that ZenX has not applied — but
                // the remedy is opposite:
                //
                //   transient  pressing Retry will very likely work
                //   business   Retry will fail identically until the underlying
                //              refusal is dealt with (e.g. clear the older
                //              month that pay-older-first is blocking on)
                //
                // Without this the screen shows both as one red row, and an
                // operator retries a business refusal repeatedly, concludes the
                // system is broken, and the payment stays unreconciled.
                // Absent on orders written before this field existed, which the
                // view renders as "unknown" rather than guessing.
                'failure_class' => (string) ($order['failure_class'] ?? ''),
                'created_at'   => $order['created_at'] ?? '',
                'paid_at'      => $order['paid_at'] ?? '',
                'source'       => $order['process_source'] ?? '',
            ];

            if ($oStatus === 'paid') {
                $stats['paid']++;
                // Check if receipt actually exists
                $rk = $order['receipt_key'] ?? '';
                $rn = str_replace('F', '', $rk);
                if ($rk && !isset($receiptIdx[$rn])) {
                    // Paid in order but no receipt in index — orphan
                    $item['issue'] = 'Receipt missing from index';
                    $orphans[] = $item;
                    $stats['orphan']++;
                } else {
                    $successful[] = $item;
                }
            } elseif ($oStatus === 'fees_failed') {
                $stats['failed']++;
                $stats['failed_amount'] += $oAmount;
                $item['failure_reason'] = $order['failure_reason'] ?? '';
                $item['retry_count']    = (int) ($order['retry_count'] ?? 0);
                $feesFailed[] = $item;
            } elseif ($oStatus === 'created' || $oStatus === 'verified') {
                // Check if order is stale (created > 30 min ago, never paid)
                $age = time() - strtotime($order['created_at'] ?? '2000-01-01');
                if ($age > 1800) { // 30 min
                    $item['issue'] = "Order created {$age}s ago but never completed";
                    $orphans[] = $item;
                    $stats['orphan']++;
                }
            }

            // Check for duplicates (same order processed multiple times)
            $hitCount = $orderIdsInPayments[$gwOrder] ?? 0;
            if ($hitCount > 1) {
                $item['hit_count'] = $hitCount;
                $duplicates[] = $item;
                $stats['duplicate']++;
            }
        }

        // Sort each by date descending
        $sortDesc = function (&$arr) {
            usort($arr, function ($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });
        };
        $sortDesc($successful);
        $sortDesc($feesFailed);
        $sortDesc($orphans);

        $this->json_success([
            'successful' => array_slice($successful, 0, 100),
            'fees_failed' => $feesFailed,
            'orphans'     => $orphans,
            'duplicates'  => $duplicates,
            'stats'       => $stats,
        ]);
    }

    /**
     * POST — Run legacy Month Fee → Demand migration for all students.
     * Admin-only. Safe to run multiple times (idempotent).
     */
    public function migrate_to_demands()
    {
        $this->_require_role(self::ADMIN_ROLES, 'migrate_demands', 'Fees', 'manage');

        $this->load->library('Fee_migration', null, 'migration');
        $this->load->library('Fee_audit', null, 'fee_audit');
        $this->fee_audit->init(
            $this->firebase, "{$this->sessionRoot}/Fees",
            $this->admin_id ?? 'system', $this->admin_name ?? 'System',
            $this->school_name
        );
        $this->migration->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->parent_db_key ?? $this->school_name, $this->fee_audit
        );

        $result = $this->migration->migrateAll();
        $this->json_success([
            'message'  => "Migration complete: {$result['migrated']} migrated, {$result['skipped']} skipped, {$result['errors']} errors.",
            'migrated' => $result['migrated'],
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
            'details'  => array_slice($result['details'], 0, 50),
        ]);
    }

    /**
     * Check if an IP address falls within a CIDR range.
     */
    /**
     * ══════════════════════════════════════════════════════════════════════
     *  PAY-OPS-5 · THE TRUE CLIENT IP, BEHIND CLOUDFLARE
     * ══════════════════════════════════════════════════════════════════════
     *
     * zenxii.com is served Proxied (orange cloud) through Cloudflare, so on the
     * origin `REMOTE_ADDR` is a Cloudflare edge address and never the caller's.
     * Anything that reasons about "who sent this" — the webhook allowlist, the
     * operator log an incident is reconstructed from — was reading the CDN.
     *
     * ── WHY NOT JUST TRUST THE HEADER ───────────────────────────────────
     * `CF-Connecting-IP` (and `X-Forwarded-For`) are ordinary request headers.
     * Anyone can send them. Trusting one unconditionally would let a caller
     * NAME its own source address — strictly worse than reading the CDN's,
     * because at least the CDN's is true.
     *
     * So the header is honoured only when the PEER — the address the TCP
     * connection actually came from, which cannot be forged — is itself inside
     * Cloudflare's published ranges. Off Cloudflare (direct origin access, a
     * local run, a different CDN) the peer address stands unchanged.
     *
     * `X-Forwarded-For` is deliberately NOT consulted: Cloudflare sets
     * CF-Connecting-IP to the single true client, whereas XFF is a
     * caller-appendable list whose left-most entry is attacker-controlled.
     *
     * @return array{ip: string, peer: string, via_cdn: bool}
     */
    private function _webhook_client_ip(): array
    {
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $viaCdn = false;
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if ($this->_ipInCidrAny($peer, $range)) { $viaCdn = true; break; }
        }

        $ip = $peer;
        if ($viaCdn) {
            $claimed = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            // Validate: a malformed or absent header must not blank the address.
            if ($claimed !== '' && filter_var($claimed, FILTER_VALIDATE_IP) !== false) {
                $ip = $claimed;
            } else {
                log_message('error',
                    "payment_webhook: request arrived via Cloudflare (peer={$peer}) but "
                    . 'CF-Connecting-IP was absent or malformed; falling back to the peer address.');
            }
        }

        return ['ip' => $ip, 'peer' => $peer, 'via_cdn' => $viaCdn];
    }

    /**
     * CIDR membership for IPv4 AND IPv6.
     *
     * `_ipInCidr()` below is IPv4-only (`ip2long`) and is left untouched — it
     * has other callers. Cloudflare publishes IPv6 ranges and serves a large
     * share of traffic over them, so an IPv4-only check would silently treat
     * every IPv6 Cloudflare connection as "not behind the CDN" and go on
     * reading the edge address as the client.
     *
     * Compares raw address bytes from inet_pton, so the two families cannot be
     * confused: a v4 address never matches a v6 range and vice versa.
     */
    private function _ipInCidrAny(string $ip, string $cidr): bool
    {
        if ($ip === '') return false;

        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) return $ip === $cidr;
        [$subnet, $bits] = $parts;
        $bits = (int) $bits;

        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false) return false;
        // Different address families are never in the same range.
        if (strlen($ipBin) !== strlen($netBin)) return false;
        if ($bits < 0 || $bits > strlen($ipBin) * 8) return false;

        $whole = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($whole > 0 && strncmp($ipBin, $netBin, $whole) !== 0) return false;
        if ($rem === 0) return true;

        $mask = 0xFF << (8 - $rem) & 0xFF;
        return (ord($ipBin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
    }

    private function _ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) return $ip === $cidr;
        list($subnet, $bits) = $parts;
        $bits = (int)$bits;
        if ($bits < 0 || $bits > 32) return false;
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    // ====================================================================
    //  DATABASE INTEGRITY CHECKS
    // ====================================================================

    /**
     * Run all 10 database integrity checks. AJAX GET.
     */
    public function integrity_report()
    {
        // Only admin roles
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin'], '', 'Fees', 'manage');

        try {
            $report = $this->feeIntegrity->runAll();
            $this->json_success([
                'report'       => $report,
                'school'       => $this->school_name,
                'session'      => $this->session_year,
                'generated_at' => date('c')
            ]);
        } catch (Exception $e) {
            log_message('error', "integrity_report failed: " . $e->getMessage());
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Auto-fix safe integrity issues. POST only.
     * @param string $checkId  e.g., "DB-008", "DB-009"
     */
    public function integrity_fix()
    {
        $this->_require_role(['Super Admin', 'School Super Admin']);

        $checkId = trim($this->input->post('check_id') ?? '');
        if ($checkId === '') {
            $this->json_error('check_id is required');
            return;
        }

        try {
            $result = $this->feeIntegrity->autoFix($checkId);
            $this->json_success([
                'check_id'    => $checkId,
                'fixed_count' => $result['fixed'] ?? 0,
                'details'     => $result['details'] ?? []
            ]);
        } catch (Exception $e) {
            log_message('error', "integrity_fix({$checkId}) failed: " . $e->getMessage());
            $this->json_error($e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  FIRESTORE BULK SYNC
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST — Trigger a full sync of all fee data from RTDB to Firestore.
     *
     * Syncs: fee structures, defaulters for all students.
     * Useful for initial migration or reconciliation.
     */
    public function firestore_bulk_sync()
    {
        $this->_require_role(self::ADMIN_ROLES, 'firestore_bulk_sync', 'Fees', 'manage');

        // One-off admin operation: Firestore REST client makes synchronous
        // HTTP calls (15s cURL timeout each), and the full walk across
        // fee-structures, demands, receipts, ledger, CoA, etc. routinely
        // exceeds the default 120s web-request limit. Raise both PHP and
        // server-side timers so it can complete in one request.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(true);

        try {
            // Homework sync
            $this->load->library('Homework_firestore_sync', null, 'hwSync');
            $this->hwSync->init($this->firebase, $this->school_name, $this->session_year);

            // Accounting sync (Phase 4.1) — chart of accounts, ledger,
            // closing balances, income/expense, voucher counters.
            $this->load->library('Accounting_firestore_sync', null, 'acctFsSync');
            $this->acctFsSync->init($this->firebase, $this->school_name, $this->session_year);

            // Phase 1 collections (discounts, scholarships, refunds, advance
            // balance, carry-forward, month-fee flags, receipt index, counter)
            // — walk existing RTDB data and populate Firestore so the Phase 2
            // admin read-swap doesn't find empty collections.
            [$discountsSynced, $monthFeesSynced] = $this->fsSync->syncAllDiscountsAndMonthFees();

            $results = [
                'fee_structures'      => $this->fsSync->syncAllFeeStructures(),
                'fee_demands'         => $this->fsSync->syncAllDemandsForAllStudents(),
                'fee_receipts'        => $this->fsSync->syncAllReceipts(),
                'defaulters'          => $this->fsSync->syncAllDefaulters(),
                'scholarship_awards'  => $this->fsSync->syncAllScholarshipAwards(),
                'refunds'             => $this->fsSync->syncAllRefunds(),
                'carry_forward'       => $this->fsSync->syncAllCarryForward(),
                'receipt_index'       => $this->fsSync->syncAllReceiptIndex(),
                'receipt_counter'     => $this->fsSync->syncReceiptCounterFromRTDB() ? 1 : 0,
                'student_discounts'   => $discountsSynced,
                'student_month_fees'  => $monthFeesSynced,
                'homework'            => $this->hwSync->syncAllHomework(),
                // Accounting backfill (Phase 4.1)
                'chart_of_accounts'   => $this->acctFsSync->syncAllChartOfAccounts(),
                'accounting_ledger'   => $this->acctFsSync->syncAllLedgerEntries(),
                'closing_balances'    => $this->acctFsSync->syncAllClosingBalances(),
                'income_expense'      => $this->acctFsSync->syncAllIncomeExpense(),
                'voucher_counters'    => $this->acctFsSync->syncAllVoucherCounters(),
                'retry_queue'         => $this->fsSync->drainRetryQueue(),
            ];

            $this->json_success([
                'message' => 'Firestore bulk sync completed.',
                'synced'  => $results,
            ]);
        } catch (Exception $e) {
            log_message('error', "firestore_bulk_sync failed: " . $e->getMessage());
            $this->json_error('Firestore sync failed: ' . $e->getMessage());
        }
    }
}
