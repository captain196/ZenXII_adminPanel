<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Salary_template_service — reusable salary structures (ZenX HRMS W2.6).
 *
 * WHY A TEMPLATE MASTER EXISTS AT ALL
 * -----------------------------------
 * W2.3 stored `templateId` on every assignment, but nothing defined what a
 * template WAS, so the field was a label. A reuse check confirmed nothing
 * template-like existed anywhere in HR (the other `templateId` hits belong to
 * Certificates and Communication), so this is a genuinely missing capability
 * rather than a duplicate of something already present.
 *
 * WHAT A TEMPLATE IS
 * ------------------
 * The SHAPE of a salary, not its size:
 *
 *     "Teaching Staff" = BASIC 50% of gross
 *                      + HRA 40% of basic
 *                      + SPECIAL_ALLOWANCE (balancing)
 *                      + PF_EMPLOYEE 12% of basic
 *                      …
 *
 * An administrator then types one number — the employee's monthly gross — and
 * `apply()` turns the shape into concrete assignment lines. This is the
 * "gross-down" model: enter the total, let the components derive. It is how
 * real CTC structures are built, and it is why `BALANCING` exists — one line
 * absorbs the remainder so the parts always sum exactly to the whole.
 *
 * WHAT IT IS NOT
 * --------------
 * Not a second salary engine. `apply()` produces an ordinary assignment payload
 * that goes through `Salary_assignment_service::validate()` and is priced by
 * the same `splitGross()` every other assignment uses. Deleting every template
 * would change no payslip.
 *
 * MONEY: integer paise (W1.2). Percentages stay percentages.
 */
class Salary_template_service
{
    /** @var object Firestore_service, already school-scoped. */
    private $fs;
    private string $actor = '';

    const COLLECTION = 'salaryStructureTemplates';

    const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,39}$/';
    const MAX_NAME_LEN = 80;

    public function init($fs, string $actor = ''): void
    {
        $this->fs    = $fs;
        $this->actor = $actor;
    }

    private static function isIntPaise($v): bool
    {
        if (is_int($v)) return true;
        return is_string($v) && ctype_digit($v);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array $catalogue code => component metadata
     * @return array{0:array,1:array} [clean, errors]
     */
    public function validate(array $in, array $catalogue = []): array
    {
        $e = [];

        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($code === '') {
            $e[] = 'Template code is required.';
        } elseif (!preg_match(self::CODE_PATTERN, $code)) {
            $e[] = 'Template code must be 2-40 characters, start with a letter, and use only A-Z, 0-9 and underscore.';
        }

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $e[] = 'Template name is required.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LEN) {
            $e[] = 'Template name exceeds ' . self::MAX_NAME_LEN . ' characters.';
        }

        $linesIn = is_array($in['lines'] ?? null) ? $in['lines'] : [];
        $lines = [];
        $seen = [];
        $balancing = 0;
        $earnings = 0;

        foreach ($linesIn as $i => $l) {
            if (!is_array($l)) { $e[] = "lines[{$i}] must be an object."; continue; }

            $cCode = strtoupper(trim((string) ($l['componentId'] ?? '')));
            if ($cCode === '') { $e[] = "lines[{$i}] is missing componentId."; continue; }
            if (isset($seen[$cCode])) { $e[] = "Component {$cCode} appears more than once."; continue; }
            $seen[$cCode] = true;

            $meta = $catalogue[$cCode] ?? null;
            if (!empty($catalogue) && $meta === null) {
                $e[] = "Component {$cCode} is not in this school's component catalogue.";
                continue;
            }

            // The catalogue owns calcType and basis. A template supplies only
            // the VALUE, exactly as an assignment does — otherwise a template
            // could redefine what a component is.
            $calcType = (string) ($meta['calcType'] ?? '');
            $basis    = (string) ($meta['basis'] ?? 'NONE');
            $kind     = (string) ($meta['kind'] ?? 'earning');
            if ($kind === 'earning') $earnings++;

            $rate   = array_key_exists('rate', $l) && $l['rate'] !== '' && $l['rate'] !== null ? $l['rate'] : null;
            $amount = array_key_exists('amountPaise', $l) && $l['amountPaise'] !== '' && $l['amountPaise'] !== null ? $l['amountPaise'] : null;

            if ($calcType === 'PERCENT') {
                if ($rate === null)                                    $e[] = "lines[{$i}] ({$cCode}) needs a rate.";
                elseif (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) $e[] = "lines[{$i}] ({$cCode}) rate must be between 0 and 100.";
                if ($amount !== null)                                  $e[] = "lines[{$i}] ({$cCode}) is a percentage and must not carry an amount.";
            } elseif ($calcType === 'FIXED') {
                // A fixed line in a TEMPLATE is a default that every employee on
                // the template starts from; it may legitimately be 0.
                if ($amount !== null && !self::isIntPaise($amount)) {
                    $e[] = "lines[{$i}] ({$cCode}) amountPaise must be an integer number of paise.";
                } elseif ($amount !== null && (int) $amount < 0) {
                    $e[] = "lines[{$i}] ({$cCode}) amountPaise must not be negative.";
                }
                if ($rate !== null) $e[] = "lines[{$i}] ({$cCode}) is fixed and must not carry a rate.";
            } elseif ($calcType === 'BALANCING') {
                $balancing++;
                if ($rate !== null || $amount !== null) {
                    $e[] = "lines[{$i}] ({$cCode}) is balancing — its value is the remainder and must not be supplied.";
                }
            } elseif ($calcType === 'FORMULA') {
                $e[] = "lines[{$i}] ({$cCode}) is a FORMULA component, which has no evaluator yet.";
            } else {
                $e[] = "lines[{$i}] ({$cCode}) has an unknown calculation type.";
            }

            $lines[] = [
                'componentId' => $cCode,
                'calcType'    => $calcType,
                'basis'       => $basis,
                'rate'        => $calcType === 'PERCENT' && $rate !== null ? (float) $rate : null,
                'amountPaise' => $calcType === 'FIXED' && $amount !== null ? (int) $amount : null,
            ];
        }

        if ($balancing > 1) {
            $e[] = 'Only one balancing component is allowed — two cannot both absorb the remainder.';
        }
        if (empty($lines)) {
            $e[] = 'A template needs at least one component.';
        } elseif ($earnings === 0) {
            // A structure with only deductions cannot describe a salary.
            $e[] = 'A template needs at least one earning component.';
        }

        $clean = [
            'code'         => $code,
            'name'         => $name,
            'description'  => trim((string) ($in['description'] ?? '')),
            'payGrade'     => trim((string) ($in['payGrade'] ?? '')) ?: null,
            'lines'        => $lines,
            'displayOrder' => (int) ($in['displayOrder'] ?? 0),
            'active'       => array_key_exists('active', $in) ? !empty($in['active']) : true,
        ];
        return [$clean, $e];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Apply — the gross-down step
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Turn a template plus one gross figure into an assignment payload.
     *
     * Pure and static so the UI can preview the result before anything is
     * saved, and so the preview and the save path can never disagree.
     *
     * The output is an ordinary assignment: it still goes through
     * `Salary_assignment_service::validate()`, and its money is still priced by
     * `splitGross()`. A template is a convenience for building lines, never a
     * parallel path to a payslip.
     *
     * PERCENT lines keep their rate rather than being frozen into an amount —
     * so if the catalogue later changes a basis, the assignment follows the
     * catalogue instead of silently keeping a stale number.
     */
    public static function apply(array $template, string $staffId, string $effectiveFrom, int $grossPaise): array
    {
        $lines = [];
        foreach (($template['lines'] ?? []) as $l) {
            $lines[] = [
                'componentId' => (string) ($l['componentId'] ?? ''),
                'calcType'    => (string) ($l['calcType'] ?? ''),
                'basis'       => (string) ($l['basis'] ?? 'NONE'),
                'rate'        => $l['rate'] ?? null,
                'amountPaise' => $l['amountPaise'] ?? null,
            ];
        }

        return [
            'staffId'       => $staffId,
            'effectiveFrom' => $effectiveFrom,
            'effectiveTo'   => null,
            'payBasis'      => 'MONTHLY_FIXED',
            'status'        => 'draft',   // deliberate: a generated structure is reviewed before it pays
            'templateId'    => (string) ($template['code'] ?? ''),
            'payGrade'      => $template['payGrade'] ?? null,
            'lines'         => $lines,
            'grossPaise'    => $grossPaise,
            'reason'        => 'Created from template ' . (string) ($template['code'] ?? '') . '.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Persistence
    // ─────────────────────────────────────────────────────────────────────

    public function listAll(bool $activeOnly = false): array
    {
        try {
            $rows = $this->fs->schoolWhere(self::COLLECTION, []);
        } catch (\Exception $ex) {
            log_message('error', 'Salary_template_service::listAll failed — ' . $ex->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $d = $r['data'] ?? null;
            if (!is_array($d)) continue;
            if ($activeOnly && empty($d['active'])) continue;
            $out[] = $d;
        }
        usort($out, function ($a, $b) {
            $d = ((int) ($a['displayOrder'] ?? 0)) <=> ((int) ($b['displayOrder'] ?? 0));
            return $d !== 0 ? $d : strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        });
        return $out;
    }

    public function get(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (!preg_match(self::CODE_PATTERN, $code)) return null;
        try {
            $d = $this->fs->getEntity(self::COLLECTION, $code);
        } catch (\Exception $ex) {
            log_message('error', "Salary_template_service::get({$code}) failed — " . $ex->getMessage());
            return null;
        }
        return (is_array($d) && !empty($d)) ? $d : null;
    }

    public function save(array $in, array $catalogue = []): array
    {
        [$clean, $errors] = $this->validate($in, $catalogue);
        if (!empty($errors)) return [false, $errors];

        $existing = $this->get($clean['code']);
        $now = date('c');
        $clean['createdAt'] = $existing['createdAt'] ?? $now;
        $clean['createdBy'] = $existing['createdBy'] ?? $this->actor;
        $clean['updatedAt'] = $now;
        $clean['updatedBy'] = $this->actor;

        try {
            $this->fs->setEntity(self::COLLECTION, $clean['code'], $clean);
        } catch (\Exception $ex) {
            log_message('error', 'Salary_template_service::save failed — ' . $ex->getMessage());
            return [false, ['Could not save the template. Please try again.']];
        }
        return [true, []];
    }

    /**
     * Deactivate rather than delete: assignments record the `templateId` they
     * came from, and deleting one would erase that provenance. Deactivating
     * only removes it from the pick-list; existing assignments are unaffected
     * either way, because `apply()` copies the lines rather than referencing
     * them at pay time.
     */
    public function setActive(string $code, bool $active): array
    {
        $existing = $this->get($code);
        if ($existing === null) return [false, ['Template not found.']];
        try {
            $this->fs->updateEntity(self::COLLECTION, $existing['code'], [
                'active' => $active, 'updatedAt' => date('c'), 'updatedBy' => $this->actor,
            ]);
        } catch (\Exception $ex) {
            log_message('error', 'Salary_template_service::setActive failed — ' . $ex->getMessage());
            return [false, ['Could not update the template. Please try again.']];
        }
        return [true, []];
    }
}
