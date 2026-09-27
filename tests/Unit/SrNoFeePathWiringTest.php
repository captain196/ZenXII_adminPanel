<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SRNO ON THE MONEY PATH — THE WIRING, NOT THE RESOLVER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `SrNoDualLookupTest` already proves the RESOLVER thoroughly, including that
 * school A can never resolve school B's SRNO and that tenant scope comes from
 * the Firestore context rather than the caller. That work is not repeated here.
 *
 * What was NOT covered is the WIRING on the fee counter: that a register
 * number entered by an operator is converted to the canonical `studentId`
 * before any money moves, and that the raw SRNO can never itself become the
 * key a receipt, allocation or journal is written against.
 *
 * ── WHY THAT MATTERS SEPARATELY ──────────────────────────────────────────
 * The two identifiers overlap in shape at the counter. A school's SR No. 12
 * and a hypothetical student key "12" are both digits. If a stale browser tab,
 * a scripted client or a direct POST ever sent the SRNO to `submit_fees`,
 * `safe_path_segment()` would accept it — digits are path-safe — and the money
 * write would target `students/{school}_12`, a student that does not exist.
 *
 * The counter UI resolves first and posts the canonical id, but that is a
 * CLIENT-SIDE guarantee. The server-side property that actually protects the
 * money is that an unresolvable key fails closed BEFORE anything is written.
 *
 * These are source-structural assertions over the executable controller and
 * service. A CI3 controller cannot be instantiated outside the framework, and
 * that limit is stated rather than glossed — the behavioural half of this
 * contract (a real SRNO payment settling against the canonical student) is
 * covered by the end-to-end harness.
 */
final class SrNoFeePathWiringTest extends TestCase
{
    private static string $fees    = '';
    private static string $service = '';
    private static string $counter = '';
    private static string $core    = '';

    public static function setUpBeforeClass(): void
    {
        self::$fees    = self::executable(APPPATH . 'controllers/Fees.php');
        self::$service = self::executable(APPPATH . 'services/FeeCollectionService.php');
        self::$core    = self::executable(APPPATH . 'core/MY_Controller.php');
        self::$counter = (string) file_get_contents(APPPATH . 'views/fees_counter.php');
    }

    /** Comments quote the very patterns under test; strip them. */
    private static function executable(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return preg_replace("/(\r?\n)(?:[ \t]*\r?\n)+/", "\$1", $out);
    }

    // ══ RESOLUTION HAPPENS, AND BEFORE THE STUDENT READ ═════════════════════

    public function test_the_counter_lookup_resolves_before_reading_the_student(): void
    {
        $fn = strpos(self::$fees, 'public function lookup_student()');
        $this->assertNotFalse($fn, 'the fee counter lookup was renamed or removed');
        $body = substr(self::$fees, $fn, 2200);

        $resolve = strpos($body, '$this->_resolveStudentRef($entered)');
        $read    = strpos($body, "\$this->fs->get('students'");

        $this->assertNotFalse($resolve,
            'the counter no longer resolves the entry — a school that operates by register '
            . 'number could not look a student up at all');
        $this->assertNotFalse($read, 'the student read was renamed');
        $this->assertLessThan($read, $resolve,
            'the student is read BEFORE resolution, which means the raw entry is being used '
            . 'as a document key');
    }

    /**
     * The read must be keyed on the RESOLVED id and tenant-prefixed. Either
     * half missing turns a register number into a document key.
     */
    public function test_the_student_read_is_keyed_on_the_resolved_id_and_the_tenant(): void
    {
        $this->assertStringContainsString(
            "\$this->fs->get('students', \"{\$this->fs->schoolId()}_{\$userId}\")", self::$fees,
            'the counter student read is no longer tenant-prefixed and keyed on the resolved '
            . 'id. Without the prefix a corrupt index entry could reach a foreign student.');

        $fn   = strpos(self::$fees, 'public function lookup_student()');
        $body = substr(self::$fees, $fn, 2200);
        $this->assertStringContainsString("\$ref['studentId']", $body,
            'the resolved canonical studentId is no longer what the read uses');
    }

    // ══ TENANT SCOPE IS NOT CALLER-SUPPLIED ═════════════════════════════════

    /**
     * The single property that stops SRNO becoming a cross-tenant key: the
     * school passed to the resolver comes from the controller's own session
     * context, never from the request.
     */
    public function test_the_resolver_takes_its_school_from_session_context(): void
    {
        $fn = strpos(self::$core, 'protected function _resolveStudentRef(string $input): array');
        $this->assertNotFalse($fn, '_resolveStudentRef was removed from the controller spine');
        $body = substr(self::$core, $fn, 1400);

        $this->assertStringContainsString('$this->school_id', $body,
            'the SRNO resolver no longer scopes to the controller\'s own school_id');

        foreach (['$this->input->post', '$_POST', '$_GET', '$_REQUEST'] as $userInput) {
            $this->assertStringNotContainsString($userInput, $body,
                "the resolver reads {$userInput} — a caller-supplied school would let an "
                . "SRNO resolve into another tenant, which is the one thing SRNO must never do");
        }
    }

    /** A non-digit entry must bypass SRNO entirely and cost zero reads. */
    public function test_a_student_id_shaped_entry_never_enters_the_srno_path(): void
    {
        $fn   = strpos(self::$core, 'protected function _resolveStudentRef(string $input): array');
        $body = substr(self::$core, $fn, 1400);

        $guard = strpos($body, "preg_match('/^[0-9]{1,9}$/', \$raw)");
        $srno  = strpos($body, 'Sr_no_service');

        $this->assertNotFalse($guard, 'the digits-only discriminator is gone, so Student IDs '
            . 'and register numbers are no longer distinguishable');
        $this->assertLessThan($srno, $guard,
            'the SRNO service is consulted before the shape check, costing a read on every '
            . 'ordinary Student ID lookup');
    }

    // ══ MONEY IS NEVER KEYED ON AN UNRESOLVED IDENTIFIER ════════════════════

    /**
     * THE MONEY-CRITICAL GUARANTEE.
     *
     * `submit_fees` accepts `userId` from the request without resolving it —
     * correct, because the counter resolves first and posts the canonical id.
     * The server-side protection is that a key which resolves to no student
     * fails closed BEFORE any receipt, allocation or journal is written.
     *
     * Without this, a stray register number posted directly would write money
     * against a student that does not exist.
     */
    public function test_an_unresolvable_student_fails_closed_before_any_write(): void
    {
        $guard = strpos(self::$service, 'if (!$student) { return $_earlyError(');
        $this->assertNotFalse($guard,
            'FeeCollectionService no longer refuses when the student cannot be loaded. A '
            . 'register number posted directly to submit_fees would then key money writes '
            . 'against a student that does not exist.');

        foreach ([
            'feeReceipts', 'feeReceiptAllocations', 'accountingLedger', 'feeDemands',
        ] as $moneyCollection) {
            $write = strpos(self::$service, $moneyCollection);
            if ($write === false) continue;
            $this->assertGreaterThan($guard, $write,
                "{$moneyCollection} is touched BEFORE the student-existence guard — money "
                . 'would be written for an identifier that resolves to nobody');
        }
    }

    // ══ THE OPERATOR CAN SEE WHICH STUDENT THEY RESOLVED ════════════════════

    /**
     * On the SRNO path the operator typed a number, not a name. The response
     * must let them confirm the human before money moves — otherwise a
     * mistyped register number pays the wrong family's fees silently.
     */
    public function test_the_lookup_returns_enough_to_confirm_the_right_student(): void
    {
        $fn   = strpos(self::$fees, 'public function lookup_student()');
        $body = substr(self::$fees, $fn, 2600);

        foreach (["'name'", "'father_name'", "'class'", "'sr_no'", "'roll_no'", "'resolved_via'"] as $field) {
            $this->assertStringContainsString($field, $body,
                "the lookup no longer returns {$field}, so an operator who typed a register "
                . 'number cannot confirm they have the right student before collecting money');
        }
    }

    /** The UI must submit the CANONICAL id, never the register number. */
    public function test_the_counter_submits_the_canonical_id_not_the_entry(): void
    {
        $this->assertStringContainsString('FC.userId = s.user_id', self::$counter,
            'the counter no longer stores the resolved canonical id from the lookup response');
        $this->assertStringContainsString('user_id: FC.userId', self::$counter,
            'the counter posts something other than the resolved canonical id, so a register '
            . 'number could reach a money endpoint as a document key');
    }

    // ══ ROLL NUMBER IS NOT AN IDENTITY ══════════════════════════════════════

    /**
     * Roll numbers repeat across every class and section. Matching them in a
     * school-wide search would imply a uniqueness that does not exist and let
     * an operator select the wrong student with confidence.
     */
    public function test_roll_number_is_not_searchable_as_a_school_wide_identity(): void
    {
        $fn = strpos(self::$fees, 'private function _searchByName($entry)');
        $this->assertNotFalse($fn, 'the fee student search was renamed');
        $body = substr(self::$fees, $fn, 3000);

        $haystack = strpos($body, '$haystack =');
        $this->assertNotFalse($haystack, 'the search haystack was restructured');
        $line = substr($body, $haystack, 200);

        $this->assertStringNotContainsString('rollNo', $line,
            'roll number was added to the school-wide fee search haystack. It is '
            . 'class+section scoped and repeats across the school, so matching it implies a '
            . 'global uniqueness that does not exist.');
        $this->assertStringContainsString('$srNo', $line,
            'the register number is no longer searchable at the fee counter — the one '
            . 'identifier a school actually operates on');
    }
}
