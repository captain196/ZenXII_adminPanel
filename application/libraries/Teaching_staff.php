<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * WHO COUNTS AS TEACHING STAFF.
 *
 * One definition, in one place. This rule already existed inside
 * Academic::get_all_teachers(), where it decides who appears in every teacher
 * picker in the module. Substitute cover asks the same question — a school that
 * requires cover to be taken by teaching staff needs the same answer the picker
 * gives — and a second copy of a rule like this drifts from the first within a
 * release or two.
 *
 * The rule is deliberately generous. ZenX carries staff records from three eras:
 * accounts with graded `staff_roles`, accounts with only `teaching_subjects`,
 * and older ones carrying nothing but a free-text designation. Dropping any of
 * those from a cover list would quietly shrink the pool of people who can be
 * asked to cover, which looks like "nobody is available" rather than like a
 * data problem.
 */
class Teaching_staff
{
    /**
     * Designations that mean "teaches lessons" in the schools ZenX serves.
     * TGT and PGT are the standard Indian trained/post-graduate teacher grades.
     */
    private const TEACHING_TITLES = [
        'tgt', 'pgt', 'head of department', 'senior teacher',
        'lab assistant', 'sports coach',
    ];

    /** Substrings that mark a free-text designation as a teaching one. */
    private const TEACHING_WORDS = ['teacher', 'lecturer', 'faculty'];

    /**
     * @param array $data a `staff` document
     */
    public static function isTeaching(array $data): bool
    {
        $roles = $data['staff_roles'] ?? [];
        if (is_array($roles)) {
            foreach ($roles as $r) {
                if ($r === 'ROLE_TEACHER') { return true; }
            }
        }

        if (($data['primary_role'] ?? '') === 'ROLE_TEACHER') { return true; }

        $subjects = $data['teaching_subjects'] ?? [];
        if (is_array($subjects) && !empty($subjects)) { return true; }

        // Legacy free-text Position / designation, matched case-insensitively so
        // 'Maths Teacher', 'teacher' and 'TGT' from un-migrated accounts survive.
        $position = strtolower(trim((string) (
            $data['Position'] ?? $data['position'] ?? $data['designation'] ?? '')));
        if ($position === '') { return false; }

        foreach (self::TEACHING_WORDS as $w) {
            if (strpos($position, $w) !== false) { return true; }
        }

        return in_array($position, self::TEACHING_TITLES, true);
    }

    /**
     * The subjects a member of staff teaches, however their record spells it.
     *
     * An empty list means "we do not know", never "teaches nothing" — the
     * caller has to decide which of those it can act on.
     *
     * @return string[]
     */
    public static function subjects(array $data): array
    {
        $raw = $data['teaching_subjects'] ?? $data['subjects'] ?? [];
        if (!is_array($raw)) { return []; }

        $out = [];
        foreach ($raw as $s) {
            $s = trim((string) $s);
            if ($s !== '') { $out[] = $s; }
        }
        return $out;
    }

    /** The display name on a staff record, or '' when it carries none. */
    public static function name(array $data): string
    {
        foreach (['Name', 'name', 'staffName', 'fullName'] as $k) {
            $v = trim((string) ($data[$k] ?? ''));
            if ($v !== '') { return $v; }
        }
        return '';
    }
}
