<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Document types + merge-field contracts  (EXECUTION_PLAN_v1.1 P1.8)
 *
 * The SERVER side of a contract the client already enforces. `designer.js`
 * holds the same three tables (CONTRACT, CONTRACTS, TYPES) and validates
 * against them at design time; this file is what the serializer and every
 * print point resolve against at issue time.
 *
 * ---------------------------------------------------------------------------
 * THE POINT OF THIS FILE, AND THE FAILURE IT EXISTS TO PREVENT
 * ---------------------------------------------------------------------------
 * Two copies of one contract drift silently. A template published against the
 * client's list and rendered against a different server list produces a
 * document with a missing or wrong statutory field — and nothing errors,
 * because each side is internally consistent. That is the mail-merge failure
 * the append-only rule exists to prevent, arriving through the back door.
 *
 * So the two are NOT independently maintained. `tests/Unit/DocContractParityTest`
 * parses `assets/js/doctemplates/designer.js` and asserts this file agrees with
 * it, field for field and key for key. Change one, the test fails until you
 * change the other. Adding a field here without adding it there is a defect,
 * not a migration step.
 *
 * ---------------------------------------------------------------------------
 * maxLen IS OURS, NOT A STATUTE  [evidence level D]
 * ---------------------------------------------------------------------------
 * No rule text examined so far prescribes a field length. Every maxLen below is
 * derived from the longest realistic string we hold for that field — the p95
 * sample where one exists, the ordinary sample otherwise — rounded up with
 * headroom. They are labelled Level D deliberately: they are our invention.
 *
 * maxLen is a RENDERING constraint that feeds the P2.7 overflow gate. It is NOT
 * a validation rule that rejects real data at issue time. A reason-for-leaving
 * longer than its maxLen must surface as a design-time overflow finding on the
 * template, never as a truncated document: silent truncation is banned outright
 * (E2E case E5, `41`-style state rule — a clamped value is a blocking finding).
 *
 * Loaded via: $this->config->load('doc_types');
 */

/* ---------------------------------------------------------------------------
 * 1 · Merge fields — the universe of bindable keys
 *
 *   label   what the field picker shows
 *   sample  ordinary realistic value (drives the "Typical" preview mode)
 *   p95     worst-case realistic value (drives the "p95" stress mode, which is
 *           what fires auto-grow, push-down, overflow and overlap warnings at
 *           design time instead of at issue time)
 *   maxLen  see the header — Level D, rendering constraint only
 *   type    text (default) | int | enum | image | flag
 *   calc    this value is DERIVED server-side, never typed by a clerk
 * ------------------------------------------------------------------------- */
$config['doc_merge_fields'] = [

    /* --- school ------------------------------------------------------- */
    'school.name'              => ['label' => 'School name',              'sample' => 'Delhi Public School, Ranchi',        'p95' => 'Shri Guru Harkrishan Public Senior Secondary School, Ranchi', 'maxLen' => 120],
    'school.address'           => ['label' => 'School address',           'sample' => 'South Office Para, Doranda, Ranchi 834002', 'maxLen' => 160],
    'school.affiliationNo'     => ['label' => 'Affiliation number',       'sample' => '3430006',                            'maxLen' => 16],

    /* --- document identity -------------------------------------------- */
    'doc.bookNo'               => ['label' => 'Book No.',                 'sample' => '14',                                 'maxLen' => 8],
    'doc.slNo'                 => ['label' => 'Serial No.',               'sample' => '0207',                               'maxLen' => 12],
    'doc.issueDate'            => ['label' => 'Date of issue',            'sample' => '04/04/2026',                         'maxLen' => 12],
    'doc.station'              => ['label' => 'Station',                  'sample' => 'Ranchi',                             'maxLen' => 48],

    /* --- student ------------------------------------------------------ */
    'student.admissionNumber'  => ['label' => 'Admission number',         'sample' => 'DPSR/2019/0412',                     'maxLen' => 32],
    'student.fullName'         => ['label' => 'Student name',             'sample' => 'Aarav Sharma',   'p95' => 'Lakshmi Priyadarshini Venkataraman',    'maxLen' => 80],
    'student.fatherName'       => ['label' => "Father's name",            'sample' => 'Rakesh Sharma',  'p95' => 'Venkataraman Subrahmanya Iyer',         'maxLen' => 80],
    'student.motherName'       => ['label' => "Mother's name",            'sample' => 'Sunita Sharma',                      'maxLen' => 80],
    'student.dob'              => ['label' => 'Date of birth',            'sample' => '14/08/2011',                         'maxLen' => 12],
    'student.dobWords'         => ['label' => 'Date of birth in words',   'sample' => 'Fourteenth August Two Thousand Eleven', 'maxLen' => 96],

    /* --- schooling history -------------------------------------------- */
    'tc.dateOfFirstAdmission'  => ['label' => 'Date of first admission',  'sample' => '02/04/2019',                         'maxLen' => 12],
    'tc.lastClassStudied'      => ['label' => 'Class last studied',       'sample' => 'IX — B',                             'maxLen' => 24],
    'tc.dateOfLeaving'         => ['label' => 'Date of leaving school',   'sample' => '31/03/2026',                         'maxLen' => 12],
    'tc.reasonForLeaving'      => [
        'label'  => 'Reason for leaving',
        'sample' => 'Parent transferred out of station',
        /* The single longest realistic string in the whole contract, and the
           one that pushes a signature block off the page if the box is fixed. */
        'p95'    => 'Parent transferred out of station on Government service; the family is relocating to Bengaluru with effect from the close of the current academic session, and the pupil will continue his studies at the Kendriya Vidyalaya nearest to the new place of posting as intimated by the guardian in writing',
        'maxLen' => 400,
    ],
    'tc.conductRemark'         => ['label' => 'General conduct',          'sample' => 'Good',                               'maxLen' => 64],
    'tc.duesPaidUpto'          => ['label' => 'Fees paid up to',          'sample' => 'March 2026',                         'maxLen' => 32],

    /* --- derived server-side (a clerk never types these) ---------------- */
    'attendance.workingDays'   => ['label' => 'Working days',             'sample' => '221', 'maxLen' => 4, 'type' => 'int',  'calc' => 'attendance'],
    'attendance.daysPresent'   => ['label' => 'Days present',             'sample' => '198', 'maxLen' => 4, 'type' => 'int',  'calc' => 'attendance'],
    'result.promotionEligible' => ['label' => 'Qualified for promotion',  'sample' => 'Yes — promoted to Class X', 'maxLen' => 64, 'calc' => 'result'],

    /* --- Kerala Form 5A / r.22A --------------------------------------- */
    'sec.fromDate'             => ['label' => 'Pupil of this school from','sample' => '02/04/2019',                         'maxLen' => 12],
    'sec.toDate'               => ['label' => '…to',                      'sample' => '31/03/2026',                         'maxLen' => 12],
    'sec.outcome'              => [
        'label'  => 'Manner of leaving',
        'sample' => 'left after having passed from Standard',
        'p95'    => 'was removed from the rolls due to long absence while studying in Standard',
        'maxLen' => 160,
    ],
    'student.ageAtLeaving'     => ['label' => 'Age at leaving',           'sample' => '16', 'p95' => '21',  'maxLen' => 3, 'type' => 'int'],
    'student.removedFromRolls' => ['label' => 'Removed from the rolls',   'sample' => 'No', 'p95' => 'Yes', 'maxLen' => 8, 'type' => 'enum'],

    /* --- non-text: maxLen does not apply -------------------------------- */
    'student.photo'            => ['label' => 'Student photograph',       'sample' => '[photo]', 'type' => 'image'],
    'doc.verifyQr'             => ['label' => 'Verification QR',          'sample' => '[qr]',    'type' => 'image'],
    'doc.isDuplicate'          => ['label' => 'Issued as a duplicate',    'sample' => 'No',      'type' => 'flag'],
];

/* ---------------------------------------------------------------------------
 * 2 · Contracts — WHICH keys each document type may bind
 *
 * Per document type, never global. One global field list is a bug: it offers a
 * Kerala school-education certificate the attendance and promotion fields of a
 * CBSE transfer certificate, which its own rule text never declares. "The
 * picker is the contract" only means something if the contract is scoped.
 *
 * ⚠️ A reusable block imposes its bound keys on EVERY type that uses it. The
 * shared letterhead binds school.affiliationNo, so every contract whose starter
 * uses that block must declare it. Blocks and contracts are coupled, and the
 * coupling runs one way: block → contract.
 * ------------------------------------------------------------------------- */
$config['doc_contracts'] = [

    'transfer_certificate' => [
        'doc.isDuplicate', 'school.name', 'school.address', 'school.affiliationNo', 'doc.bookNo', 'doc.slNo',
        'student.admissionNumber', 'student.fullName', 'student.fatherName', 'student.motherName',
        'student.dob', 'student.dobWords', 'tc.dateOfFirstAdmission', 'tc.lastClassStudied',
        'attendance.workingDays', 'attendance.daysPresent', 'result.promotionEligible',
        'tc.reasonForLeaving', 'tc.conductRemark', 'tc.duesPaidUpto', 'tc.dateOfLeaving', 'doc.issueDate',
    ],

    'leaving_certificate_5a' => [
        'doc.isDuplicate', 'school.name', 'school.address', 'school.affiliationNo', 'student.admissionNumber', 'student.fullName',
        'student.fatherName', 'student.motherName', 'student.dob', 'student.dobWords',
        'tc.dateOfFirstAdmission', 'tc.lastClassStudied', 'student.ageAtLeaving', 'student.removedFromRolls',
        'sec.outcome', 'tc.dateOfLeaving', 'doc.issueDate', 'doc.station',
    ],

    'school_education_certificate' => [
        'doc.isDuplicate', 'school.name', 'school.address', 'school.affiliationNo', 'student.fullName', 'student.fatherName',
        'sec.fromDate', 'sec.toDate', 'sec.outcome', 'tc.lastClassStudied', 'student.dobWords',
        'doc.station', 'doc.issueDate',
    ],

    'bonafide' => [
        'school.name', 'school.address', 'school.affiliationNo', 'doc.slNo', 'student.admissionNumber',
        'student.fullName', 'student.fatherName', 'student.dob', 'tc.lastClassStudied', 'doc.issueDate',
        'student.photo',
    ],

    'character' => [
        'doc.isDuplicate', 'school.name', 'school.address', 'school.affiliationNo', 'student.fullName', 'student.fatherName',
        'tc.lastClassStudied', 'tc.conductRemark', 'tc.dateOfLeaving', 'doc.issueDate',
    ],

    'study' => [
        'school.name', 'student.fullName', 'student.fatherName', 'tc.lastClassStudied', 'doc.issueDate',
    ],
];

/* ---------------------------------------------------------------------------
 * 3 · Document types
 *
 *   statutory      rule text prescribing this document has been READ AT SOURCE
 *   requiresState  the type is offered only to schools in this state
 *   disabled       declared so it cannot be silently forgotten; not buildable yet
 * ------------------------------------------------------------------------- */
$config['doc_types'] = [

    'transfer_certificate' => [
        'name'      => 'Transfer Certificate',
        'alias'     => 'School Leaving Certificate · Leaving Certificate',
        'statutory' => true,
    ],
    'bonafide' => [
        'name'      => 'Bonafide Certificate',
        'alias'     => 'present-tense · no statutory basis found',
        'statutory' => false,
    ],
    'character' => [
        'name'      => 'Character Certificate',
        'alias'     => 'Conduct Certificate — one instrument, configurable name',
        'statutory' => false,
    ],
    'school_education_certificate' => [
        'name'          => 'Certificate of School Education',
        'alias'         => 'Kerala · KER r.22A — for a pupil who left before the S.S.L.C. examination',
        'requiresState' => 'Kerala',
        'statutory'     => true,
    ],
    'leaving_certificate_5a' => [
        'name'          => 'Leaving Certificate (Form 5A)',
        'alias'         => 'Kerala · KER r.17(3) — issued where a transfer certificate may not be',
        'requiresState' => 'Kerala',
        'statutory'     => true,
    ],
    'study' => [
        'name'          => 'Study Certificate',
        'alias'         => 'retrospective, year-by-year · A.P. G.O.P. 646',
        'requiresState' => 'Andhra Pradesh',
        'statutory'     => true,
    ],

    /* Declared and NOT buildable. Kept visible so neither is quietly dropped. */
    'migration' => [
        'name'     => 'Migration Certificate',
        'alias'    => 'board-issued — never merged with a TC',
        'disabled' => true,
    ],
    'fee_receipt' => [
        'name'     => 'Fee Receipt',
        'alias'    => 'needs repeating rows — v2',
        'disabled' => true,
    ],
];
