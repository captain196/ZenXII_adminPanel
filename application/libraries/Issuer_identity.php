<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Issuer_identity — may this school issue a certificate, and on whose authority.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * A Transfer Certificate transcribes an entry in the school's Admission &
 * Withdrawal Register. The register is the authority; the printed sheet is
 * evidence of it. So before anything is issued, the system has to know — and be
 * able to show — that the school behind it is entitled to issue at all.
 *
 * The reason is in the form, not in any one school's data. `school_config`
 * line 441 labels a SINGLE input
 *
 *     <label>Affiliation / DISE No.</label>
 *     <input type="text" id="pf_affiliation_no" maxlength="60">
 *
 * — one field, named for two different identifiers issued by two different
 * authorities, with `maxlength` as its only constraint. No pattern, no board
 * context, no shape check. An eleven-digit UDISE code entered there is accepted
 * silently, because nothing distinguishes it from an affiliation number.
 *
 * That is not cosmetic, because the field is not private: `result/templates/
 * cbse.php` line 45 renders `Aff. No: …` onto marksheets handed to families,
 * and `Doc_templates.php` selects the statutory compliance profile FROM THE
 * BOARD STRING. So an unvalidated free-text field decides which law a
 * certificate is produced under, and then prints its own value as fact.
 *
 * (An earlier version of this note cited a census of the school records in this
 * project. That data is dummy, so the census is withdrawn — it proved nothing.
 * The argument above needs no census: it is a property of the form, verifiable
 * by reading line 441.)
 *
 * ---------------------------------------------------------------------------
 * THE LADDER
 * ---------------------------------------------------------------------------
 * Four states, strictly ordered, mirroring the evidence discipline the
 * compliance corpus already applies to authorities — an instrument on file,
 * then a named person confirming it against the source on a named date, with an
 * expiry.
 *
 *   0 UNRECORDED  nothing, or nothing well-formed
 *   1 CLAIMED     the school typed it and the shape is right for its board
 *   2 EVIDENCED   the affiliation instrument is on file
 *   3 VERIFIED    checked against the board's directory, by someone, on a date
 *
 * Design is never gated — that is drawing, and it harms nobody. What the ladder
 * gates is ISSUANCE: producing a numbered, signed, sealed instrument somebody
 * else will rely on. Gating design would only teach people to type anything to
 * get past the form.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CLASS WILL NOT DO
 * ---------------------------------------------------------------------------
 * It does not decide whether a claim is TRUE. No format check can tell whether
 * a school is really CBSE-affiliated; only a lookup against the board's
 * directory can, and that is a human step here (level 3) rather than a pretence
 * of automation.
 *
 * The number formats below are SHAPES, sufficient to reject what is already in
 * the data. They are not transcribed from board regulations, and are marked so.
 * The compliance corpus's own rule applies: inventing a plausible-looking
 * requirement asserts wrong law confidently, which is worse than enforcing
 * nothing.
 */
class Issuer_identity
{
    const UNRECORDED = 0;
    const CLAIMED    = 1;
    const EVIDENCED  = 2;
    const VERIFIED   = 3;

    /** Level at or above which issuance is permitted. */
    const ISSUE_FROM = self::EVIDENCED;

    /**
     * Boards a school may sit under, and the shape of the number each issues.
     *
     * `verified` marks whether the FORMAT was transcribed from the board's own
     * documentation. All are false today, deliberately — they are working
     * shapes, and saying so is the point.
     */
    const BOARDS = [
        'CBSE' => [
            'label'    => 'CBSE',
            'pattern'  => '/^\d{7}$/',
            'hint'     => 'CBSE affiliation numbers are 7 digits.',
            'needsNo'  => true,
            'verified' => false,
        ],
        'CISCE' => [
            'label'    => 'CISCE (ICSE/ISC)',
            'pattern'  => '/^[A-Z]{2}\d{3,4}$/i',
            'hint'     => 'CISCE school codes look like UP123.',
            'needsNo'  => true,
            'verified' => false,
        ],
        'STATE' => [
            'label'    => 'State Board',
            'pattern'  => '/^[A-Za-z0-9\/\-]{4,24}$/',
            'hint'     => 'Use the recognition order number issued by the state.',
            'needsNo'  => true,
            'verified' => false,
        ],
        'NIOS' => [
            'label'    => 'NIOS',
            'pattern'  => '/^\d{6,9}$/',
            'hint'     => 'NIOS centre code, 6 to 9 digits.',
            'needsNo'  => true,
            'verified' => false,
        ],
        'UNAFFILIATED' => [
            'label'    => 'Unaffiliated / private',
            'pattern'  => null,
            'hint'     => 'An unaffiliated school issues no board-recognised certificate.',
            'needsNo'  => false,
            'verified' => false,
        ],
    ];

    /**
     * UDISE+ codes are eleven digits, and the first two are the STATE.
     *
     * Structure: 2 state · 2 district · 3 block · 4 school. The state prefix is
     * the census/GST state code, so a code can be checked against the school's
     * own declared state with no lookup and no network — which is worth having,
     * because a UDISE code is the closest thing to machine-checkable proof that
     * a school legally exists.
     *
     * The ordering is why: a school gets its recognition certificate from the
     * state, and only then does the Block Education Office register it on
     * UDISE+ after physical verification. Recognition comes first; the code is
     * downstream evidence of it. Board affiliation is a later and separate
     * thing — it governs examinations, not existence.
     *
     * The confusion has a recognisable shape. A UDISE code is eleven digits
     * that OPEN WITH THE CENSUS STATE CODE — `09` is Uttar Pradesh, `23` Madhya
     * Pradesh — so an eleven-digit affiliation number beginning with a valid
     * state code is almost certainly a UDISE code in the wrong field. The form
     * cannot tell, because it labels one input "Affiliation / DISE No." and
     * checks nothing; `stateOfUdise()` below exists so the server can.
     */
    const UDISE_PATTERN = '/^\d{11}$/';

    /** Census/GST state codes, which UDISE+ uses for its first two digits. */
    const STATE_CODES = [
        '01' => 'jammu and kashmir', '02' => 'himachal pradesh', '03' => 'punjab',
        '04' => 'chandigarh',        '05' => 'uttarakhand',      '06' => 'haryana',
        '07' => 'delhi',             '08' => 'rajasthan',        '09' => 'uttar pradesh',
        '10' => 'bihar',             '11' => 'sikkim',           '12' => 'arunachal pradesh',
        '13' => 'nagaland',          '14' => 'manipur',          '15' => 'mizoram',
        '16' => 'tripura',           '17' => 'meghalaya',        '18' => 'assam',
        '19' => 'west bengal',       '20' => 'jharkhand',        '21' => 'odisha',
        '22' => 'chhattisgarh',      '23' => 'madhya pradesh',   '24' => 'gujarat',
        '27' => 'maharashtra',       '29' => 'karnataka',        '30' => 'goa',
        '31' => 'lakshadweep',       '32' => 'kerala',           '33' => 'tamil nadu',
        '34' => 'puducherry',        '35' => 'andaman and nicobar islands',
        '36' => 'telangana',         '37' => 'andhra pradesh',   '38' => 'ladakh',
    ];

    /**
     * Which state a UDISE code says it belongs to, or null if the prefix is not
     * a state code we know. Null means "cannot tell", never "wrong".
     */
    public static function stateOfUdise(string $udise): ?string
    {
        if (!preg_match(self::UDISE_PATTERN, $udise)) {
            return null;
        }
        return self::STATE_CODES[substr($udise, 0, 2)] ?? null;
    }

    /**
     * Does this value look like a UDISE code for a DIFFERENT state than the one
     * recorded? Used to explain a mismatch rather than merely reject it.
     */
    public static function udiseStateMismatch(string $udise, string $declaredState): ?string
    {
        $codeState = self::stateOfUdise($udise);
        $declared  = strtolower(trim($declaredState));
        if ($codeState === null || $declared === '') {
            return null;                      // cannot tell — say nothing
        }
        return $codeState === $declared ? null : $codeState;
    }

    /** Our own convention, not a statutory period — which is why it is settable. */
    const DEFAULT_REVIEW_MONTHS = 12;

    /* ================================================================== *
     *  Validation
     * ================================================================== */

    /**
     * Normalise and check a submitted identity.
     *
     * @return array{fields:array<string,mixed>, errors:array<string,string>}
     *         `fields` carries only what passed, in the camelCase shape the
     *         school document already uses. Nothing partial is invented.
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $out    = [];

        $board = strtoupper(trim((string) ($in['affiliationBoard'] ?? '')));
        if ($board !== '' && !isset(self::BOARDS[$board])) {
            $errors['affiliationBoard'] = 'Unknown board.';
            $board = '';
        }
        if ($board !== '') {
            $out['affiliationBoard'] = $board;
        }

        /* THE NUMBER, CHECKED AGAINST ITS OWN BOARD.
           A number cannot be validated without knowing which board issued it,
           which is why the two fields were never independently checkable and
           why one of them holds a UDISE code today. */
        $no = trim((string) ($in['affiliationNo'] ?? ''));
        if ($no !== '') {
            if ($board === '') {
                $errors['affiliationNo'] = 'Choose a board first — the format depends on it.';
            } elseif (!self::BOARDS[$board]['needsNo']) {
                $errors['affiliationNo'] = 'An unaffiliated school has no affiliation number.';
            } elseif (!preg_match(self::BOARDS[$board]['pattern'], $no)) {
                $udiseState = self::stateOfUdise($no);
                $errors['affiliationNo'] = preg_match(self::UDISE_PATTERN, $no)
                    ? 'Eleven digits is a UDISE+ code, not an affiliation number'
                      . ($udiseState ? ' — and ' . substr($no, 0, 2) . ' is ' . ucwords($udiseState) : '')
                      . '. It belongs in the UDISE field.'
                    : 'Not a valid ' . self::BOARDS[$board]['label'] . ' number. ' . self::BOARDS[$board]['hint'];
            } else {
                $out['affiliationNo'] = $no;
            }
        }

        $udise = trim((string) ($in['udiseCode'] ?? ''));
        if ($udise !== '') {
            if (!preg_match(self::UDISE_PATTERN, $udise)) {
                $errors['udiseCode'] = 'A UDISE+ code is exactly 11 digits.';
            } else {
                /* The first two digits are the state. Checking them costs
                   nothing and catches a code copied from another school. */
                $other = self::udiseStateMismatch($udise, (string) ($in['state'] ?? ''));
                if ($other !== null) {
                    $errors['udiseCode'] = 'That code begins ' . substr($udise, 0, 2)
                        . ', which is ' . ucwords($other) . '. Check it belongs to this school.';
                } else {
                    $out['udiseCode'] = $udise;
                }
            }
        }

        $name = trim((string) ($in['registeredName'] ?? ''));
        if ($name !== '') {
            if (mb_strlen($name) > 200) {
                $errors['registeredName'] = 'Registered name is longer than 200 characters.';
            } else {
                $out['registeredName'] = $name;
            }
        }

        $head = trim((string) ($in['headOfInstitution'] ?? ''));
        if ($head !== '') {
            if (mb_strlen($head) > 200) {
                $errors['headOfInstitution'] = 'Name is longer than 200 characters.';
            } else {
                $out['headOfInstitution'] = $head;
            }
        }

        $since = trim((string) ($in['headSince'] ?? ''));
        if ($since !== '') {
            if (!self::isDate($since)) {
                $errors['headSince'] = 'Use a date in YYYY-MM-DD form.';
            } else {
                $out['headSince'] = $since;
            }
        }

        /* A recognition order is a different instrument from a board
           affiliation, so it is stored separately rather than overloaded onto
           the affiliation number — which is how a UDISE code ended up there. */
        $ro = is_array($in['recognitionOrder'] ?? null) ? $in['recognitionOrder'] : [];
        $roNo   = trim((string) ($ro['number'] ?? ''));
        $roDate = trim((string) ($ro['date'] ?? ''));
        $roAuth = trim((string) ($ro['authority'] ?? ''));
        if ($roDate !== '' && !self::isDate($roDate)) {
            $errors['recognitionOrder.date'] = 'Use a date in YYYY-MM-DD form.';
            $roDate = '';
        }
        if ($roNo !== '' || $roDate !== '' || $roAuth !== '') {
            $out['recognitionOrder'] = array_filter([
                'number'    => $roNo,
                'date'      => $roDate,
                'authority' => mb_substr($roAuth, 0, 200),
            ], fn($v) => $v !== '');
        }

        $months = (int) ($in['reviewMonths'] ?? self::DEFAULT_REVIEW_MONTHS);
        $out['reviewMonths'] = in_array($months, [12, 24, 36], true) ? $months : self::DEFAULT_REVIEW_MONTHS;

        return ['fields' => $out, 'errors' => $errors];
    }

    /* ================================================================== *
     *  The ladder
     * ================================================================== */

    /**
     * What a stored identity amounts to.
     *
     * A claim is complete only when every part a reader would need is present:
     * the board, the number its board requires, the registered name and the
     * head of institution. A partial claim is level 0 — not "nearly claimed" —
     * because a certificate carrying half an identity is not half-valid.
     */
    public static function levelOf(array $id): int
    {
        $board = strtoupper((string) ($id['affiliationBoard'] ?? ''));
        if ($board === '' || !isset(self::BOARDS[$board])) {
            return self::UNRECORDED;
        }

        $spec = self::BOARDS[$board];
        if ($spec['needsNo']) {
            $no = (string) ($id['affiliationNo'] ?? '');
            if ($no === '' || !preg_match($spec['pattern'], $no)) {
                return self::UNRECORDED;
            }
        }
        if (trim((string) ($id['registeredName'] ?? '')) === '')    return self::UNRECORDED;
        if (trim((string) ($id['headOfInstitution'] ?? '')) === '') return self::UNRECORDED;

        $v = is_array($id['verification'] ?? null) ? $id['verification'] : [];

        /* VERIFICATION EXPIRES. An affiliation lapses, a recognition is
           withdrawn, a school changes board. A badge with no review interval is
           a claim wearing a badge, so an expired check falls back to what it
           still evidences rather than staying green. */
        if (!empty($v['verifiedOn']) && !empty($v['verifiedBy'])) {
            if (!self::isStale($v, (int) ($id['reviewMonths'] ?? self::DEFAULT_REVIEW_MONTHS))) {
                return self::VERIFIED;
            }
        }
        if (!empty($v['evidencePath'])) {
            return self::EVIDENCED;
        }
        return self::CLAIMED;
    }

    /** Is a verification older than the school's own review interval? */
    public static function isStale(array $verification, int $reviewMonths, ?int $nowTs = null): bool
    {
        $on = $verification['verifiedOn'] ?? null;
        if (!$on) {
            return true;
        }
        $ts = strtotime((string) $on);
        if ($ts === false) {
            return true;
        }
        $months = in_array($reviewMonths, [12, 24, 36], true) ? $reviewMonths : self::DEFAULT_REVIEW_MONTHS;
        return (($nowTs ?? time()) - $ts) > ($months * 30 * 86400);
    }

    /** May this school issue today, and if not, what is missing. */
    public static function mayIssue(array $id): array
    {
        $level = self::levelOf($id);
        if ($level >= self::ISSUE_FROM) {
            return ['allowed' => true, 'level' => $level, 'reason' => null];
        }
        return [
            'allowed' => false,
            'level'   => $level,
            'reason'  => $level === self::UNRECORDED
                ? 'This school has no recorded issuer identity. Templates may be designed, but nothing may be issued in its name.'
                : 'The affiliation is claimed but no instrument is on file. Attach it before issuing.',
        ];
    }

    /**
     * The board that selects the compliance profile — and NEVER a guess.
     *
     * Returns '' when the school has not recorded one, so `resolveStack()`
     * matches no board authority and the generic profile takes over, enforcing
     * nothing and saying so. That is what the architecture already does; it was
     * being bypassed by an offline demo fixture (see L31).
     */
    public static function complianceBoard(array $school): string
    {
        $board = strtoupper(trim((string) ($school['affiliationBoard'] ?? $school['board'] ?? '')));
        return isset(self::BOARDS[$board]) ? $board : '';
    }

    /** Boards as the client needs them — one source, so the two cannot drift. */
    public static function boardCatalogue(): array
    {
        $out = [];
        foreach (self::BOARDS as $key => $b) {
            $out[] = [
                'key'      => $key,
                'label'    => $b['label'],
                'hint'     => $b['hint'],
                'needsNo'  => $b['needsNo'],
                'verified' => $b['verified'],
            ];
        }
        return $out;
    }

    private static function isDate(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }
}
