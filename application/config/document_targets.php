<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PRINT-POINT REGISTRY — declared, deliberately NOT wired.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS AND IS NOT
 * ---------------------------------------------------------------------------
 * The Document Engine designs, versions, publishes and activates templates. It
 * does not issue documents, and no module's print button is wired in this build
 * (`CON-NO_PRINT_IMPL`, a HARD constraint in blueprints/certificates/
 * STATE_LEDGER.md). Issuance — number allocation, the issued-document register,
 * PDF archival, QR verification — is the NEXT engine.
 *
 * This file is the seam between the two. It records, in one place, WHERE each
 * document type is meant to surface when that engine lands: which module owns
 * the button, which screen it belongs on, which entity it is issued against,
 * and what permission gates it.
 *
 * It is a MAP, not a mechanism. Nothing here renders, routes, links or is read
 * by any print path, because no print path exists. `wired` is `false` on every
 * row and there is no code that flips it.
 *
 * ---------------------------------------------------------------------------
 * WHY WRITE IT DOWN NOW RATHER THAN WHEN ISSUANCE IS BUILT
 * ---------------------------------------------------------------------------
 * Because this is the decision that is expensive to reverse and cheap to
 * record. "A fee receipt appears in Accounts, against a payment, for whoever
 * may collect fees" is a product decision, and the person who knows it is the
 * person reading this today — not whoever implements issuance months from now
 * by guessing from a controller name.
 *
 * It also makes the missing work VISIBLE. A registry with eight rows and eight
 * `wired => false` is an honest statement that nothing prints yet. A codebase
 * with no registry at all reads, wrongly, as though the question was never
 * asked.
 *
 * ---------------------------------------------------------------------------
 * ROW SHAPE
 * ---------------------------------------------------------------------------
 *   docType      key in application/config/doc_types.php — the contract
 *   module       RBAC module key (application/helpers/rbac_helper.php) that
 *                owns the button. NOT the module that owns the template.
 *   surface      where a person triggers it: panel | teacher_app | parent_app
 *   mountHint    the screen it belongs on. A HINT, not a route — routes move,
 *                and a stale route here would be worse than a description.
 *   entity       what it is issued AGAINST. Issuance needs a subject; a
 *                certificate with no student is not a document, it is a form.
 *   capability   graded permission required to issue: view | edit | manage
 *   audience     who may ultimately receive it once issuance exists
 *   numbering    the serial series it draws from. Distinct series must never
 *                share a counter — a receipt number and a TC number are
 *                different legal sequences, and merging them corrupts both.
 *   wired        ALWAYS false in this build. See CON-NO_PRINT_IMPL.
 *   note         anything the next engineer would otherwise have to guess.
 */
return [

    /* ---------------------------------------------------------------- *
     * Statutory certificates — the Document Engine's v1 scope
     * ---------------------------------------------------------------- */

    'transfer_certificate' => [
        'docType'    => 'transfer_certificate',
        'module'     => 'SIS',
        'surface'    => 'panel',
        'mountHint'  => 'Student profile → Documents; and the exit/withdrawal flow',
        'entity'     => 'student',
        'capability' => 'manage',
        'audience'   => ['parent', 'office'],
        'numbering'  => 'tc',
        'wired'      => false,
        'note'       => 'Issued at exit. The no-dues gate is deliberately NOT a default: '
                      . 'courts have gutted it, and it must never apply to classes I-VIII. '
                      . 'Per-state configurable when issuance lands. '
                      . "\n\n"
                      . 'COLLISION, READ BEFORE WIRING: A REAL TC ISSUANCE ALREADY EXISTS. '
                      . 'Sis.php::issue_tc() checks outstanding dues across fees, library, '
                      . 'hostel and transport; DISABLES the student\'s Firebase Auth account; '
                      . 'removes them from the RTDB roster; syncs the change to both apps; '
                      . 'blanks attendance from that date; recomputes section strength; and '
                      . 'writes a TC_ISSUED history entry. Its numbering is already atomic '
                      . '(Firestore_service::nextSchoolCounter with the same \'tc\' series '
                      . 'named on this row). A document-only TC issued here would either '
                      . 'duplicate those side effects or, far worse, let a student be issued a '
                      . 'Transfer Certificate while remaining enrolled, authenticated and on '
                      . 'the roster. This row must MOUNT ON that workflow, never beside it.',
    ],

    'bonafide' => [
        'docType'    => 'bonafide',
        'module'     => 'SIS',
        'surface'    => 'panel',
        'mountHint'  => 'Student profile → Documents',
        'entity'     => 'student',
        'capability' => 'edit',
        'audience'   => ['parent', 'office'],
        'numbering'  => 'bonafide',
        'wired'      => false,
        'note'       => 'Present-tense, no statutory basis found. Routinely requested at a '
                      . 'counter, so it is the strongest candidate for a parent-app request flow.',
    ],

    'character' => [
        'docType'    => 'character',
        'module'     => 'SIS',
        'surface'    => 'panel',
        'mountHint'  => 'Student profile → Documents',
        'entity'     => 'student',
        'capability' => 'manage',
        'audience'   => ['parent', 'office'],
        'numbering'  => 'character',
        'wired'      => false,
        'note'       => 'One instrument with a configurable name (Character / Conduct).',
    ],

    /* ---------------------------------------------------------------- *
     * Financial — Accounts owns the button, NOT the Documents module
     *
     * This is the row the seam exists for. A fee receipt is designed here and
     * issued THERE: the person pressing the button is a cashier in Accounts
     * looking at a payment, not an administrator looking at a template.
     * ---------------------------------------------------------------- */

    'fee_receipt' => [
        'docType'    => 'fee_receipt',
        'module'     => 'Fees',
        'surface'    => 'panel',
        'mountHint'  => 'Accounts → Fee collection → a posted payment row, and the '
                      . 'payment-success screen immediately after collection',
        'entity'     => 'feePayment',
        'capability' => 'edit',
        'audience'   => ['parent', 'office'],
        'numbering'  => 'receipt',
        'wired'      => false,
        'note'       => 'NOT YET A DOC TYPE — needs repeating rows (line items), which the '
                      . 'v1 serializer does not do; see doc_types.php. Two hard rules for '
                      . 'whoever wires it: a receipt number is allocated ONCE per payment and '
                      . 'never reallocated on reprint, and a reprint must be marked DUPLICATE. '
                      . 'The legacy RTDB Certificates.php counter is read-increment-write and '
                      . 'mints duplicate numbers under concurrency — do not copy it. '
                      . "\n\n"
                      . 'CONFLICT, SETTLE THIS BEFORE WIRING: the PARENT APP ALREADY RENDERS '
                      . 'FEE RECEIPTS ON THE DEVICE. ZenXII_Parent util/ReceiptPdfGenerator.kt '
                      . 'builds a receipt PDF from FeeReceiptDoc with its own hardcoded layout — '
                      . 'header, itemised breakdown, and a Cashier/Accountant/Principal signature '
                      . 'row — reached from ui/fees/ReceiptDetailScreen.kt. Wiring this print '
                      . 'point without retiring that generator gives one school TWO receipt '
                      . 'documents that cannot agree: changing the activated template would not '
                      . 'change what a parent downloads, and nobody would be told, because each '
                      . 'surface only ever shows its own. Either the on-device generator is '
                      . 'retired, or receipts stay out of the Document Engine. Not both. '
                      . "\n\n"
                      . 'DECIDED 2026-09-03 (operator): the Document Engine OWNS receipt design, '
                      . 'as a v2 target. BOTH surfaces render from the activated template — the '
                      . 'panel and the app — and ReceiptPdfGenerator.kt is RETIRED when this is '
                      . 'wired, with the app fetching a server-rendered PDF instead of building '
                      . 'one. Rejected explicitly: engine-for-panel, app-keeps-its-own, which '
                      . 'would institutionalise the drift. A receipt is a financial record, and '
                      . 'two versions that can silently diverge is what surfaces badly in an '
                      . 'audit. v2 regardless of this decision: receipts need repeating '
                      . 'line-item rows the v1 serializer does not support.',
    ],

    'fee_demand_note' => [
        'docType'    => 'fee_demand_note',
        'module'     => 'Fees',
        'surface'    => 'panel',
        'mountHint'  => 'Accounts → Outstanding → bulk generate for a class',
        'entity'     => 'feeDemand',
        'capability' => 'edit',
        'audience'   => ['parent'],
        'numbering'  => 'demand',
        'wired'      => false,
        'note'       => 'Bulk by nature — hundreds at once. Issuance must stream rather than '
                      . 'hold every PDF in memory; mPDF peaks around 84MB for ONE document.',
    ],

    /* ---------------------------------------------------------------- *
     * Staff — a different tenant of the same engine
     * ---------------------------------------------------------------- */

    'staff_experience_letter' => [
        'docType'    => 'staff_experience_letter',
        'module'     => 'HR',
        'surface'    => 'panel',
        'mountHint'  => 'Staff profile → Documents; and the exit/relieving flow',
        'entity'     => 'staff',
        'capability' => 'manage',
        'audience'   => ['staff'],
        'numbering'  => 'staff_letter',
        'wired'      => false,
        'note'       => 'Reads from staffPrivate, which is owner-readable only. Issuance must '
                      . 'not denormalise those fields onto the issued document where a wider '
                      . 'audience could read them.',
    ],

    'staff_salary_slip' => [
        'docType'    => 'staff_salary_slip',
        'module'     => 'HR',
        'surface'    => 'panel',
        'mountHint'  => 'Payroll → a finalised month → per-staff row',
        'entity'     => 'payrollRun',
        'capability' => 'manage',
        'audience'   => ['staff'],
        'numbering'  => 'payslip',
        'wired'      => false,
        'note'       => 'Only issuable from a LOCKED payroll period. A slip issued from an open '
                      . 'period can be contradicted by the final run — and attendance is source '
                      . 'data for payroll, so this is financial, not clerical.',
    ],

    /* ---------------------------------------------------------------- *
     * App surfaces — read-only consumers
     * ---------------------------------------------------------------- */

    'parent_document_locker' => [
        'docType'    => '*',
        'module'     => 'SIS',
        'surface'    => 'parent_app',
        'mountHint'  => 'Parent app → child → Documents: a list of what was ISSUED to them',
        'entity'     => 'issuedDocument',
        'capability' => 'view',
        'audience'   => ['parent'],
        'numbering'  => null,
        'wired'      => false,
        'note'       => 'Consumes issued documents; never renders a template and never allocates '
                      . 'a number. Scoped to the requesting parent\'s own children — a document '
                      . 'locker is exactly the shape an IDOR hides in.',
    ],
];
