<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * temp_password_helper — the initial password handed to a new student or staff
 * member, which they are then forced to replace on first login.
 *
 * WHY THIS EXISTS
 * The old generators derived the password from data the school already prints
 * on a class list:
 *   students  Ucfirst(name[0..3]) . dobDigits[0..4] . '@'   →  "Ayu1503@"
 *   staff     ucfirst(name)[0..3] . '123@'                  →  "Ayu123@"
 * Anyone holding a name and a date of birth could compute a classmate's first
 * password, and the staff form was 7 characters — one BELOW the 8-character
 * minimum the same user is forced to meet minutes later.
 *
 * The compensating control is the forced change on first login, so these only
 * have to survive the gap between handover and that change. They still must not
 * be *derivable*, because that gap can be days and the account is unprotected
 * for all of it.
 *
 * DESIGN
 *  - Keeps the familiar shape (name letters + separator + digits) so the slip
 *    still reads like a ZenXii password and operators aren't surprised.
 *  - The digits are drawn from random_int() (CSPRNG), never from a birth date.
 *  - Always satisfies the password policy enforced at change time: 8-72 chars,
 *    with an uppercase, a lowercase and a digit — regardless of the name given,
 *    including blank, single-letter, or non-Latin names.
 *  - The random LETTER pool omits i, l and o, which are the ones misread off a
 *    printed slip. Digits are unrestricted — 0 and 1 read fine in the numeric
 *    run, and excluding them would cost entropy for no real gain.
 */

if (!function_exists('temp_password_letters')) {
    /**
     * Up to 3 Latin letters seeded from the name, padded with random ones when
     * the name yields too few (blank, "A", or entirely non-Latin scripts).
     * Returned as Ucfirst + lowercase so both cases are always present.
     */
    function temp_password_letters(string $seedName): string
    {
        // Unambiguous in print: no I or O.
        $pool = 'abcdefghjkmnpqrstuvwxyz';

        $clean = preg_replace('/[^a-zA-Z]/', '', $seedName) ?? '';
        $stem  = strtolower(substr($clean, 0, 3));

        while (strlen($stem) < 3) {
            $stem .= $pool[random_int(0, strlen($pool) - 1)];
        }

        // Ucfirst guarantees the uppercase; the remaining two guarantee lowercase.
        return ucfirst($stem);
    }
}

if (!function_exists('generate_temp_password')) {
    /**
     * Build an initial password, e.g. "Ayu@4821".
     *
     * 8 characters: 3 letters + '@' + 4 random digits. Policy-compliant by
     * construction (upper + lower + digit, length 8).
     *
     * @param string $seedName Person's name — shapes the letters only. Never
     *                         pass a date of birth or anything else guessable
     *                         into the digits; they are always random.
     */
    function generate_temp_password(string $seedName = ''): string
    {
        return temp_password_letters($seedName) . '@' . random_int(1000, 9999);
    }
}
