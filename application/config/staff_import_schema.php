<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Canonical staff-import field schema — single source of truth.
 *
 * Read by BOTH Staff_import_mapper (header resolution + validation) AND
 * Staff::download_staff_template() (header labels + sample), so the importer
 * and the template can never drift apart.
 *
 * Each field, keyed by a stable canonical key:
 *   label     – the canonical column header (also what mapped rows are keyed by,
 *               so existing import code that reads $rowData['Name'] keeps working)
 *   required  – true → a missing/empty value flags the row (role is handled
 *               separately by the controller's role-resolution chain)
 *   aliases   – alternate header spellings auto-matched (normalized, lowercase)
 *   transform – 'trim' (default) | 'digits_only' | 'date' | 'numeric' | 'upper'
 *   validate  – PCRE pattern | 'email' | 'numeric' (only checked when non-empty)
 *   enum      – allowed values (case-insensitive; only checked when non-empty)
 *   multi     – true → value is a comma-separated list; the controller parses
 *               it into an array on import (map_row keeps it as a clean string)
 *   default   – value applied when optional-and-empty
 *   error     – custom message fragment for a failed `validate`
 *
 * NOTE: keep every `label` equal to the header the existing importer reads, so
 * the canonical template stays byte-for-byte backward compatible.
 */
$config['staff_import_fields'] = [

    // ── Required identity ────────────────────────────────────────────────
    'name' => [
        'label'    => 'Name',
        'required' => true,
        'aliases'  => ['staff name', 'employee name', 'full name', 'teacher name', 'emp name'],
    ],
    'phone' => [
        'label'     => 'Phone Number',
        'required'  => true,
        'aliases'   => ['phone', 'mobile', 'mobile number', 'mobile no', 'contact', 'contact no', 'contact number', 'phone no', 'cell', 'whatsapp'],
        'transform' => 'digits_only',
        'validate'  => '/^[6-9]\d{9}$/',
        'error'     => 'must be a 10-digit Indian mobile (starts 6-9)',
    ],
    'dob' => [
        'label'     => 'DOB',
        'required'  => false,
        'aliases'   => ['date of birth', 'birth date', 'birthday', 'd.o.b'],
        'transform' => 'date',
        'error'     => 'is not a recognizable date (use DD-MM-YYYY or YYYY-MM-DD)',
    ],
    'email' => [
        'label'    => 'Email',
        'required' => false,
        'aliases'  => ['email address', 'mail', 'e-mail', 'email id'],
        'validate' => 'email',
        'error'    => 'is not a valid email address',
    ],
    'gender' => [
        'label'    => 'Gender',
        'required' => false,
        'aliases'  => ['sex'],
        'enum'     => ['Male', 'Female', 'Other'],
    ],
    'father_name' => [
        'label'    => 'Father Name',
        'required' => false,
        'aliases'  => ['fathers name', "father's name", 'father', 'guardian name'],
    ],

    // ── Role / job (role resolved via the controller chain, not required here).
    //    Label is "Role" (the Departments & Roles concept); "position"/"designation"
    //    stay as aliases so older sheets and previously-downloaded templates still map.
    'role' => [
        'label'    => 'Role',
        'required' => false,
        'aliases'  => ['position', 'role id', 'designation', 'job title', 'post', 'job role'],
    ],
    'department' => [
        'label'    => 'Department',
        'required' => false,
        'aliases'  => ['dept', 'faculty', 'subject department'],
    ],
    'teaching_subjects' => [
        'label'    => 'Teaching Subjects',
        'required' => false,
        'aliases'  => ['subjects', 'subject', 'subjects taught', 'teaching subject', 'subject handled', 'subjects handled'],
        'multi'    => true,
    ],
    'employment_type' => [
        'label'    => 'Employment Type',
        'required' => false,
        'aliases'  => ['employment', 'emp type', 'job type', 'type of employment'],
    ],
    'date_of_joining' => [
        'label'     => 'Date Of Joining',
        'required'  => false,
        'aliases'   => ['doj', 'joining date', 'date of join', 'joining', 'hire date'],
        'transform' => 'date',
        'error'     => 'is not a recognizable date (use DD-MM-YYYY or YYYY-MM-DD)',
    ],

    // ── Pay ──────────────────────────────────────────────────────────────
    'basic_salary' => [
        'label'    => 'Basic Salary',
        'required' => false,
        'aliases'  => ['basic', 'basic pay', 'salary', 'base salary', 'monthly salary'],
        'validate' => 'numeric',
        'error'    => 'must be a number',
    ],
    'allowances' => [
        'label'    => 'Allowances',
        'required' => false,
        'aliases'  => ['allowance', 'hra', 'other allowances'],
        'validate' => 'numeric',
        'default'  => '0',
        'error'    => 'must be a number',
    ],

    // ── Personal (optional) ──────────────────────────────────────────────
    'blood_group'    => ['label' => 'Blood Group',    'aliases' => ['blood', 'blood type']],
    'religion'       => ['label' => 'Religion',       'aliases' => []],
    'category'       => ['label' => 'Category',       'aliases' => ['caste category', 'reservation category']],
    'marital_status' => ['label' => 'Marital Status', 'aliases' => ['marital']],
    'alt_phone'      => ['label' => 'Alt Phone',      'aliases' => ['alternate phone', 'alternate number', 'secondary phone'], 'transform' => 'digits_only'],
    'designation'    => ['label' => 'Designation',    'aliases' => ['title', 'display title']],

    // ── Qualification (optional) ─────────────────────────────────────────
    'qualification'   => ['label' => 'Qualification',    'aliases' => ['highest qualification', 'degree', 'education']],
    'experience'      => ['label' => 'Experience',       'aliases' => ['years of experience', 'exp', 'work experience']],
    'university'      => ['label' => 'University',        'aliases' => ['institution', 'college', 'board']],
    'year_of_passing' => ['label' => 'Year Of Passing',  'aliases' => ['passing year', 'year passed', 'yop']],

    // ── Statutory IDs (optional) ─────────────────────────────────────────
    'pan_number'    => ['label' => 'PAN Number',    'aliases' => ['pan', 'pan no', 'pan card'], 'transform' => 'upper'],
    'aadhar_number' => ['label' => 'Aadhar Number', 'aliases' => ['aadhaar', 'aadhar', 'aadhaar number', 'uid'], 'transform' => 'digits_only'],
    'pf_number'     => ['label' => 'PF Number',     'aliases' => ['pf', 'provident fund', 'epf']],
    'esi_number'    => ['label' => 'ESI Number',    'aliases' => ['esi', 'esic']],

    // ── Bank (optional) ──────────────────────────────────────────────────
    'bank_name'      => ['label' => 'Bank Name',           'aliases' => ['bank']],
    'account_holder' => ['label' => 'Account Holder Name', 'aliases' => ['account holder', 'ac holder', 'holder name']],
    'account_number' => ['label' => 'Account Number',      'aliases' => ['account no', 'ac no', 'a/c number', 'bank account']],
    'ifsc_code'      => ['label' => 'IFSC Code',           'aliases' => ['ifsc', 'ifsc no'], 'transform' => 'upper'],

    // ── Emergency (optional) ─────────────────────────────────────────────
    'emergency_name'     => ['label' => 'Emergency Contact Name',   'aliases' => ['emergency name', 'emergency contact']],
    'emergency_phone'    => ['label' => 'Emergency Contact Number', 'aliases' => ['emergency number', 'emergency phone', 'emergency contact no'], 'transform' => 'digits_only'],
    'emergency_relation' => ['label' => 'Emergency Contact Relation', 'aliases' => ['emergency relation', 'relation']],

    // ── Address (optional) ───────────────────────────────────────────────
    'street'      => ['label' => 'Street',      'aliases' => ['address', 'street address', 'address line']],
    'city'        => ['label' => 'City',        'aliases' => ['town', 'district']],
    'state'       => ['label' => 'State',       'aliases' => ['province'], 'transform' => 'india_state'],
    'postal_code' => ['label' => 'Postal Code', 'aliases' => ['pincode', 'pin code', 'pin', 'zip', 'zip code'], 'transform' => 'digits_only'],

    'perm_street'      => ['label' => 'Permanent Street',      'aliases' => ['permanent address']],
    'perm_city'        => ['label' => 'Permanent City',        'aliases' => ['permanent district']],
    'perm_state'       => ['label' => 'Permanent State',       'aliases' => [], 'transform' => 'india_state'],
    'perm_postal_code' => ['label' => 'Permanent Postal Code', 'aliases' => ['permanent pincode', 'permanent pin'], 'transform' => 'digits_only'],
];
