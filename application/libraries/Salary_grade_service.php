<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Salary_grade_service — pay grades and salary bands (ZenX HRMS W2.5).
 *
 * WHAT THIS ADDS
 * --------------
 * A grade is a named salary band: "Grade C — ₹25,000 to ₹40,000 monthly gross".
 * Assignments already carried a `payGrade` string (W2.3) but nothing defined
 * what a grade WAS, so nothing could check whether a salary actually sat inside
 * its band. The field was a label; this makes it a control.
 *
 * WHY IT MATTERS BEYOND TIDINESS
 * ------------------------------
 * Out-of-band pay is the kind of error that survives for years because every
 * individual payslip looks correct. The only moment it is cheap to catch is
 * when the assignment is created, which is why `checkBand()` is a first-class
 * validation result rather than a report someone runs quarterly.
 *
 * A band breach is a WARNING, not a rejection. Real organisations make
 * deliberate exceptions — a retention offer, a hard-to-fill role — and a system
 * that refuses them outright gets worked around by putting people on the wrong
 * grade, which is worse than a recorded exception. So the assignment saves, the
 * breach is surfaced, and the operator sees it.
 *
 * MONEY: integer paise (W1.2). No rounding happens in this file.
 */
class Salary_grade_service
{
    /** @var object Firestore_service, already school-scoped. */
    private $fs;
    private string $actor = '';

    const COLLECTION = 'salaryGrades';

    /** Grade codes are the document-id suffix, so they must be path-safe. */
    const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{0,39}$/';
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

    /**
     * @return array{0:array,1:array} [clean, errors]
     */
    public function validate(array $in): array
    {
        $e = [];

        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($code === '') {
            $e[] = 'Grade code is required.';
        } elseif (!preg_match(self::CODE_PATTERN, $code)) {
            $e[] = 'Grade code must be 1-40 characters, start with a letter, and use only A-Z, 0-9 and underscore.';
        }

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $e[] = 'Grade name is required.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LEN) {
            $e[] = 'Grade name exceeds ' . self::MAX_NAME_LEN . ' characters.';
        }

        $min = $in['minPaise'] ?? null;
        $max = $in['maxPaise'] ?? null;

        if (!self::isIntPaise($min)) {
            // A decimal here means rupees leaked in — the W1.2 regression.
            $e[] = 'minPaise must be an integer number of paise (not rupees, not a decimal).';
        } elseif ((int) $min < 0) {
            $e[] = 'minPaise must not be negative.';
        }
        if (!self::isIntPaise($max)) {
            $e[] = 'maxPaise must be an integer number of paise (not rupees, not a decimal).';
        } elseif ((int) $max < 0) {
            $e[] = 'maxPaise must not be negative.';
        }
        if (self::isIntPaise($min) && self::isIntPaise($max) && (int) $max < (int) $min) {
            $e[] = 'maxPaise must not be less than minPaise.';
        }

        $clean = [
            'code'         => $code,
            'name'         => $name,
            'description'  => trim((string) ($in['description'] ?? '')),
            'minPaise'     => self::isIntPaise($min) ? (int) $min : 0,
            'maxPaise'     => self::isIntPaise($max) ? (int) $max : 0,
            'displayOrder' => (int) ($in['displayOrder'] ?? 0),
            'active'       => array_key_exists('active', $in) ? !empty($in['active']) : true,
        ];
        return [$clean, $e];
    }

    // ── Read ─────────────────────────────────────────────────────────────

    public function listAll(bool $activeOnly = false): array
    {
        try {
            $rows = $this->fs->schoolWhere(self::COLLECTION, []);
        } catch (\Exception $ex) {
            log_message('error', 'Salary_grade_service::listAll failed — ' . $ex->getMessage());
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
            return $d !== 0 ? $d : ((int) ($a['minPaise'] ?? 0)) <=> ((int) ($b['minPaise'] ?? 0));
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
            log_message('error', "Salary_grade_service::get({$code}) failed — " . $ex->getMessage());
            return null;
        }
        return (is_array($d) && !empty($d)) ? $d : null;
    }

    // ── Band check ───────────────────────────────────────────────────────

    /**
     * Does a salary sit inside its grade's band?
     *
     * Pure and static so it can be exercised without Firestore, and so the UI,
     * the assignment save path and any future bulk audit all answer the same
     * way.
     *
     * Deliberate semantics:
     *   • no grade assigned      → `checked = false`. Not a breach; ungraded
     *     staff are legitimate and must not generate noise.
     *   • grade not found        → a real warning: the assignment references a
     *     grade that no longer exists.
     *   • bounds are INCLUSIVE   → sitting exactly on the floor or ceiling of a
     *     band is inside it, not a breach.
     *
     * @return array{checked:bool,inBand:bool,severity:string,message:string,grade:?array}
     */
    public static function checkBand(?array $grade, ?string $gradeCode, int $grossPaise): array
    {
        $none = fn(string $m) => ['checked' => false, 'inBand' => true, 'severity' => 'none',
                                  'message' => $m, 'grade' => null];

        if ($gradeCode === null || trim($gradeCode) === '') {
            return $none('No pay grade assigned — band not checked.');
        }
        if ($grade === null) {
            return ['checked' => false, 'inBand' => false, 'severity' => 'warning',
                    'message' => "Pay grade '{$gradeCode}' is not defined in this school's grade master.",
                    'grade' => null];
        }

        $min = (int) ($grade['minPaise'] ?? 0);
        $max = (int) ($grade['maxPaise'] ?? 0);
        $rs  = fn(int $p) => number_format($p / 100, 2);

        if ($grossPaise < $min) {
            return ['checked' => true, 'inBand' => false, 'severity' => 'warning',
                    'message' => "Gross ₹{$rs($grossPaise)} is BELOW the {$grade['code']} band floor of ₹{$rs($min)}.",
                    'grade' => $grade];
        }
        if ($max > 0 && $grossPaise > $max) {
            return ['checked' => true, 'inBand' => false, 'severity' => 'warning',
                    'message' => "Gross ₹{$rs($grossPaise)} is ABOVE the {$grade['code']} band ceiling of ₹{$rs($max)}.",
                    'grade' => $grade];
        }
        return ['checked' => true, 'inBand' => true, 'severity' => 'none',
                'message' => "Within the {$grade['code']} band.", 'grade' => $grade];
    }

    /** Convenience: resolve the grade for an assignment and check its band. */
    public function checkAssignmentBand(array $assignment): array
    {
        $code = $assignment['payGrade'] ?? null;
        if ($code === null || trim((string) $code) === '') {
            return self::checkBand(null, null, 0);
        }
        return self::checkBand($this->get((string) $code), (string) $code, (int) ($assignment['grossPaise'] ?? 0));
    }

    /** Overlapping bands are legitimate (grades often overlap), so they are NOT rejected. */
    public function save(array $in): array
    {
        [$clean, $errors] = $this->validate($in);
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
            log_message('error', 'Salary_grade_service::save failed — ' . $ex->getMessage());
            return [false, ['Could not save the pay grade. Please try again.']];
        }
        return [true, []];
    }

    /**
     * Deactivate rather than delete: assignments reference grades by code, and
     * deleting one would leave those references dangling with no way to explain
     * the resulting warning.
     */
    public function setActive(string $code, bool $active): array
    {
        $existing = $this->get($code);
        if ($existing === null) return [false, ['Pay grade not found.']];
        try {
            $this->fs->updateEntity(self::COLLECTION, $existing['code'], [
                'active' => $active, 'updatedAt' => date('c'), 'updatedBy' => $this->actor,
            ]);
        } catch (\Exception $ex) {
            log_message('error', 'Salary_grade_service::setActive failed — ' . $ex->getMessage());
            return [false, ['Could not update the pay grade. Please try again.']];
        }
        return [true, []];
    }
}
