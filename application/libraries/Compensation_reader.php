<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Compensation_reader — the single seam between payroll and where salary
 * comes from (ZenX HRMS W2.4).
 *
 * WHY A READER RATHER THAN A REWRITE
 * ----------------------------------
 * `Hr::_compute_payslip()` is the W1.2-certified money engine: exact integer
 * paise, one controlled rounding per ratio, and the guarantee that
 * `gross == net + deductions` and `Dr == Cr`. Changing its signature would put
 * that contract back in play for the sake of changing where its INPUT comes
 * from. So the calculator stays untouched and only its input source moves.
 *
 * This reader therefore returns exactly the thirteen-field shape the calculator
 * already accepts — rupee amounts for fixed components, PERCENTAGES for
 * PF/ESI/TDS — regardless of which store it read from. There is one payroll
 * engine, and this is not a second one.
 *
 *      Hr::generate_payroll()
 *              │
 *              ▼
 *      Compensation_reader::resolve(staffId, date)
 *              ├── legacy     → salarySlips structure          (today's behaviour)
 *              ├── shadow     → resolve BOTH, log divergence, RETURN LEGACY
 *              └── component  → staffSalaryAssignments + salaryComponents
 *              │
 *              ▼   same 13 fields either way
 *      Hr::_compute_payslip()
 *
 * W2.4 BOUNDARY — NOT YET WIRED
 * -----------------------------
 * `Hr.php` does NOT call this yet, and the flag defaults false. Wiring the
 * payroll path is deliberately held until the parity harness reports no
 * divergence over a tenant that has actually been seeded and migrated. Today
 * every new collection is empty, so an in-request shadow would compare the
 * legacy structure against nothing and report a green that means nothing.
 * `Hrms_w2_parity` produces real evidence from the real legacy data instead.
 *
 * STATUTORY IS NOT IN THIS PATH YET, deliberately. `_compute_payslip()` takes
 * PF/ESI/TDS as percentages from the structure and applies no ceiling or
 * eligibility threshold. Feeding `statutoryConfig` in would change computed
 * money, which is a payroll-behaviour change and belongs to its own gate — not
 * to a reader whose whole purpose is to be inert.
 */
class Compensation_reader
{
    const MODE_LEGACY    = 'legacy';
    const MODE_SHADOW    = 'shadow';
    const MODE_COMPONENT = 'component';

    /** The exact field set `Hr::_compute_payslip()` reads. */
    const LEGACY_FIELDS = [
        'basic', 'hra', 'da', 'ta', 'medical', 'other_allowances',
        'pf_employee', 'pf_employer', 'esi_employee', 'esi_employer',
        'professional_tax', 'tds', 'other_deductions',
    ];

    /** Rupee-amount fields. Everything else in LEGACY_FIELDS is a percentage. */
    const AMOUNT_FIELDS = [
        'basic', 'hra', 'da', 'ta', 'medical', 'other_allowances',
        'professional_tax', 'other_deductions',
    ];

    /** componentId → legacy field. The inverse of fromLegacyStructure(). */
    const COMPONENT_TO_FIELD = [
        'BASIC' => 'basic', 'HRA' => 'hra', 'DA' => 'da', 'TA' => 'ta',
        'MEDICAL' => 'medical', 'OTHER_ALLOWANCES' => 'other_allowances',
        'PROFESSIONAL_TAX' => 'professional_tax', 'OTHER_DEDUCTIONS' => 'other_deductions',
        'PF_EMPLOYEE' => 'pf_employee', 'PF_EMPLOYER' => 'pf_employer',
        'ESI_EMPLOYEE' => 'esi_employee', 'ESI_EMPLOYER' => 'esi_employer',
        'TDS' => 'tds',
    ];

    /** @var object Firestore_service, already school-scoped. */
    private $fs;
    /** @var object|null Salary_assignment_service */
    private $assignments = null;
    private string $mode = self::MODE_LEGACY;

    public function init($fs, string $mode = self::MODE_LEGACY, $assignmentService = null): void
    {
        $this->fs   = $fs;
        $this->mode = in_array($mode, [self::MODE_LEGACY, self::MODE_SHADOW, self::MODE_COMPONENT], true)
            ? $mode : self::MODE_LEGACY;
        $this->assignments = $assignmentService;
    }

    /**
     * Resolve the mode from the flags. Kept separate so a caller can override
     * for a harness without touching configuration.
     */
    public static function modeFromFlags(bool $useComponents, bool $shadow): string
    {
        if ($useComponents) return self::MODE_COMPONENT;
        if ($shadow)        return self::MODE_SHADOW;
        return self::MODE_LEGACY;
    }

    public function mode(): string { return $this->mode; }

    // ─────────────────────────────────────────────────────────────────────
    //  Resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The salary structure for an employee on a date, in the calculator's shape.
     *
     * Returns null when nothing is configured. That is a real answer meaning
     * "this employee cannot be paid for this period" and callers MUST treat it
     * as a block — never as zero pay. `Salary_assignment_service::readiness()`
     * is the surface that makes it visible before a run rather than after.
     */
    public function resolve(string $staffId, string $date): ?array
    {
        switch ($this->mode) {
            case self::MODE_COMPONENT:
                return $this->resolveFromComponents($staffId, $date);

            case self::MODE_SHADOW:
                $legacy = $this->resolveLegacy($staffId);
                // The shadow must never be able to break a payroll run, so it
                // is fully isolated and its result is discarded.
                try {
                    $component = $this->resolveFromComponents($staffId, $date);
                    $diff = self::compare($legacy, $component);
                    if (!empty($diff)) {
                        log_message('error', 'COMPENSATION_AB_DIVERGENCE staff=' . $staffId
                            . ' date=' . $date . ' ' . json_encode($diff));
                    }
                } catch (\Throwable $e) {
                    log_message('error', 'COMPENSATION_AB_SHADOW_FAILED staff=' . $staffId
                        . ': ' . $e->getMessage());
                }
                return $legacy;   // authoritative result is always the legacy one

            case self::MODE_LEGACY:
            default:
                return $this->resolveLegacy($staffId);
        }
    }

    /**
     * The assignment service, constructed on demand (ZenX HRMS W3.1).
     *
     * An injected service always wins — that is how the parity harness and the
     * unit tests supply fixtures. When none was injected the reader builds its
     * own, which is what lets a CALLER select a mode without having to know
     * anything about the component model. That matters for the payroll
     * controller specifically: it asks the seam for compensation, and the
     * source-level guards in the W2 suite can keep asserting that it names none
     * of the component-model classes itself.
     *
     * Returns null outside a CodeIgniter context (the unit suite loads this
     * class directly), which preserves the existing "no assignments → null"
     * contract rather than fataling on a missing APPPATH.
     */
    private function assignmentService()
    {
        if ($this->assignments !== null) return $this->assignments;
        if (!defined('APPPATH')) return null;

        $path = APPPATH . 'libraries/Salary_assignment_service.php';
        if (!is_file($path)) return null;

        require_once $path;
        $svc = new Salary_assignment_service();
        $svc->init($this->fs);
        $this->assignments = $svc;
        return $svc;
    }

    /**
     * BULK resolve for a payroll run — the seam `Hr::generate_payroll()` and
     * `Hr::preflight_payroll()` actually use (ZenX HRMS W3.1).
     *
     * WHY BULK EXISTS AT ALL
     * ----------------------
     * Payroll resolves compensation for the WHOLE roster. Routing that loop
     * through the per-staff `resolve()` would turn today's single
     * `schoolWhere()` into one Firestore read per employee — an N+1 that grows
     * with headcount and would fail the performance gate. The seam has to be
     * introduced without paying for it, so bulk is the payroll-facing form and
     * `resolve()` stays the single-employee one.
     *
     * WHY LEGACY MODE RETURNS THE RAW DOCUMENT
     * ----------------------------------------
     * Payroll reads the thirteen canonical fields AND two pieces of metadata
     * the calculator's shape does not carry: `_version` and `source`. Returning
     * the normalised thirteen here would silently drop both. So in legacy mode
     * this returns exactly what today's inline loop built — the raw
     * `$doc['data']` — which makes the swap byte-identical rather than
     * merely equivalent.
     *
     * CUTOVER NOTE (deliberately recorded, not silently handled): component
     * mode synthesises the thirteen fields and therefore must also synthesise
     * `_version` and `source` before the flag can move. That is a cutover-gate
     * obligation, not something to paper over with a default here — inventing a
     * `_version` would fabricate provenance.
     *
     * @param  string[] $staffIds Roster to resolve. Empty means "everyone".
     * @return array<string,array> staffId → structure, absent when unconfigured.
     */
    public function resolveMany(array $staffIds, string $date): array
    {
        if ($this->mode === self::MODE_COMPONENT) {
            $out = [];
            foreach ($staffIds as $sid) {
                $r = $this->resolveFromComponents($sid, $date);
                if ($r !== null) $out[$sid] = $r;
            }
            return $out;
        }

        $legacy = $this->resolveManyLegacy();

        if ($this->mode === self::MODE_SHADOW) {
            // Isolated exactly as in resolve(): a shadow may never break a run.
            try {
                foreach ($staffIds as $sid) {
                    $component = $this->resolveFromComponents($sid, $date);
                    $diff = self::compare($legacy[$sid] ?? null, $component);
                    if (!empty($diff)) {
                        log_message('error', 'COMPENSATION_AB_DIVERGENCE staff=' . $sid
                            . ' date=' . $date . ' ' . json_encode($diff));
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'COMPENSATION_AB_SHADOW_FAILED bulk: ' . $e->getMessage());
            }
        }

        return $legacy;   // authoritative in both legacy and shadow
    }

    /**
     * The one query. Identical to the loop it replaces in `Hr.php`, including
     * the swallow-and-continue on failure: a Firestore error there yielded an
     * empty map and a caller-level "No salary structures found", and that
     * behaviour is preserved rather than converted into an exception.
     */
    public function resolveManyLegacy(): array
    {
        $out = [];
        try {
            $docs = $this->fs->schoolWhere('salarySlips', [['type', '==', 'salary_structure']]);
            if (is_array($docs)) {
                foreach ($docs as $doc) {
                    $sid = $doc['data']['staffId'] ?? '';
                    if ($sid !== '') $out[$sid] = $doc['data'];
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Compensation_reader bulk legacy read failed: ' . $e->getMessage());
        }
        return $out;
    }

    /** Today's source: the legacy `salarySlips` structure. Unmodified. */
    public function resolveLegacy(string $staffId): ?array
    {
        try {
            $doc = $this->fs->getEntity('salarySlips', "SAL_{$staffId}");
        } catch (\Exception $e) {
            log_message('error', "Compensation_reader legacy read failed for {$staffId}: " . $e->getMessage());
            return null;
        }
        if (!is_array($doc) || empty($doc)) return null;

        $out = [];
        foreach (self::LEGACY_FIELDS as $f) {
            $out[$f] = isset($doc[$f]) && is_numeric($doc[$f]) ? (float) $doc[$f] : 0.0;
        }
        return $out;
    }

    /**
     * Resolve from the effective assignment + component catalogue.
     *
     * Produces the SAME thirteen fields: FIXED lines contribute their rupee
     * amount, PERCENT lines contribute their rate. A BALANCING line is
     * expanded first (via splitGross) so its derived amount lands on the
     * component it belongs to — otherwise the remainder would silently vanish.
     */
    public function resolveFromComponents(string $staffId, string $date): ?array
    {
        $assignments = $this->assignmentService();
        if ($assignments === null) return null;

        $assignment = $assignments->resolve($staffId, $date);
        if (!is_array($assignment) || empty($assignment['lines'])) return null;
        if (($assignment['status'] ?? '') === 'draft') return null;   // not payable

        $catalogue = $this->componentCatalogue();

        // Expand BALANCING into a concrete amount before mapping.
        $derived = [];
        $hasBalancing = false;
        foreach ($assignment['lines'] as $l) {
            if (($l['calcType'] ?? '') === 'BALANCING') { $hasBalancing = true; break; }
        }
        if ($hasBalancing && class_exists('Salary_assignment_service')) {
            $split = Salary_assignment_service::splitGross($assignment, $catalogue);
            $derived = $split['components'] ?? [];
        }

        $out = array_fill_keys(self::LEGACY_FIELDS, 0.0);

        foreach ($assignment['lines'] as $l) {
            $code  = (string) ($l['componentId'] ?? '');
            $field = self::COMPONENT_TO_FIELD[$code] ?? null;
            if ($field === null) {
                // A tenant-defined component with no legacy counterpart. It
                // cannot reach the calculator until _compute_payslip() learns
                // to iterate components — a later gate. Logged rather than
                // dropped in silence.
                log_message('debug', "Compensation_reader: component {$code} has no legacy field; "
                    . 'it will not reach the calculator until the component-aware payslip gate.');
                continue;
            }
            $calcType = (string) ($l['calcType'] ?? '');

            if ($calcType === 'PERCENT') {
                $out[$field] = (float) ($l['rate'] ?? 0);
            } elseif ($calcType === 'FIXED') {
                $out[$field] = self::paiseToRupees((int) ($l['amountPaise'] ?? 0));
            } elseif ($calcType === 'BALANCING') {
                $out[$field] = self::paiseToRupees((int) ($derived[$code] ?? 0));
            }
        }
        return $out;
    }

    /** This tenant's component catalogue, keyed by code. */
    private function componentCatalogue(): array
    {
        try {
            $rows = $this->fs->schoolWhere('salaryComponents', []);
        } catch (\Exception $e) {
            log_message('error', 'Compensation_reader catalogue read failed: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $d = $r['data'] ?? null;
            if (is_array($d) && !empty($d['code'])) $out[(string) $d['code']] = $d;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Money boundary
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Integer paise → rupees, for the calculator's rupee-shaped input.
     *
     * The calculator immediately converts back with `_toPaise()`, so this is a
     * round-trip and it must be lossless. `CompensationReaderTest` exercises
     * paise → rupees → paise across boundary values including odd paise and
     * .005 cases, because a single lost paisa here would break
     * `gross == net + deductions` downstream.
     */
    public static function paiseToRupees(int $paise): float
    {
        return round($paise / 100.0, 2);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Comparison (the parity gate's primitive)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Field-by-field divergence between two resolved structures.
     *
     * Compared at PAISE precision for amounts and to four decimals for rates,
     * so a float artefact cannot be mistaken for a real difference — and, more
     * importantly, a real one-paisa difference cannot hide behind a tolerance.
     *
     * @return array<string,array{legacy:mixed,component:mixed}> empty = identical
     */
    public static function compare(?array $legacy, ?array $component): array
    {
        if ($legacy === null && $component === null) return [];
        if ($legacy === null) return ['__missing' => ['legacy' => null, 'component' => 'present']];
        if ($component === null) return ['__missing' => ['legacy' => 'present', 'component' => null]];

        $diff = [];
        foreach (self::LEGACY_FIELDS as $f) {
            $l = (float) ($legacy[$f] ?? 0);
            $c = (float) ($component[$f] ?? 0);

            if (in_array($f, self::AMOUNT_FIELDS, true)) {
                $lp = (int) round($l * 100);
                $cp = (int) round($c * 100);
                if ($lp !== $cp) $diff[$f] = ['legacy' => $l, 'component' => $c];
            } else {
                if (round($l, 4) !== round($c, 4)) $diff[$f] = ['legacy' => $l, 'component' => $c];
            }
        }
        return $diff;
    }
}
