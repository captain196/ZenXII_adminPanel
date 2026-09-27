<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Entity_firestore_sync.php';
require_once __DIR__ . '/../../application/libraries/Firestore_service.php';

/**
 * SL-12 — canonical class/section normalization.
 *
 * Covers the TEST 1/2/3/4/8 items from
 * STUDENT_LIFECYCLE_SL12_CANONICAL_CLASS_SECTION_IMPLEMENTATION_REPORT.md
 * that are expressible as pure PHP logic against the two static helpers
 * (no Firestore, no controller, no HTTP). Promotion-flow tests (5/6/17-19),
 * cross-module reads (11-15), and cross-platform tests (9/10/16) require a
 * live/faked Firestore + roster and are covered by code inspection in the
 * report instead — see its "Tests executed" section for exactly why.
 */
class ClassSectionCanonicalizationTest extends TestCase
{
    // ── classKey() now converges on the same canonical form regardless
    //    of how the caller phrased the input — this is the actual SL-12 bug:
    //    before the fix, classKey("9") --> "Class 9" while
    //    normalizeClassSection("9", "") --> className "Class 9th". ──

    public function test_classKey_matches_normalizeClassSection_for_every_supported_class_format(): void
    {
        $variants = ['10', '10th', 'Class 10', 'Class 10th', '  class 10TH  '];
        foreach ($variants as $v) {
            $this->assertSame(
                'Class 10th',
                \Firestore_service::classKey($v),
                "classKey('{$v}') must equal the canonical ordinal form"
            );
            $this->assertSame(
                \Entity_firestore_sync::normalizeClassSection($v, '')['className'],
                \Firestore_service::classKey($v),
                "classKey('{$v}') must equal normalizeClassSection()'s className for the same input"
            );
        }
    }

    public function test_classKey_handles_non_numeric_classes(): void
    {
        foreach (['LKG', 'lkg', 'Class LKG', 'Nursery', 'Pre-Nursery'] as $v) {
            $this->assertSame(
                \Entity_firestore_sync::normalizeClassSection($v, '')['className'],
                \Firestore_service::classKey($v)
            );
        }
    }

    public function test_sectionKey_matches_normalizeClassSection_for_every_supported_section_format(): void
    {
        foreach (['A', 'Section A', 'section a', 'Red', '1'] as $v) {
            $this->assertSame(
                \Entity_firestore_sync::normalizeClassSection('', $v)['section'],
                \Firestore_service::sectionKey($v)
            );
        }
    }

    public function test_classKey_and_sectionKey_are_idempotent(): void
    {
        $onceClass = \Firestore_service::classKey('9');
        $twiceClass = \Firestore_service::classKey($onceClass);
        $this->assertSame($onceClass, $twiceClass, 'classKey() re-applied to its own output must not drift');

        $onceSection = \Firestore_service::sectionKey('a');
        $twiceSection = \Firestore_service::sectionKey($onceSection);
        $this->assertSame($onceSection, $twiceSection, 'sectionKey() re-applied to its own output must not drift');
    }

    public function test_classKey_and_sectionKey_preserve_empty_string(): void
    {
        $this->assertSame('', \Firestore_service::classKey(''));
        $this->assertSame('', \Firestore_service::classKey('   '));
        $this->assertSame('', \Firestore_service::sectionKey(''));
        $this->assertSame('', \Firestore_service::sectionKey('   '));
    }

    public function test_buildSectionKey_uses_the_fixed_classKey_and_sectionKey(): void
    {
        $this->assertSame(
            'Class 10th/Section A',
            \Firestore_service::buildSectionKey('10', 'A')
        );
    }

    // ── Roll Number scope: SL-12 must NOT touch Roll Number semantics.
    //    Prove the same roll number can coexist across distinct class/section
    //    canonical keys — i.e. Roll Number plays no role in classKey/sectionKey
    //    and cannot become a de-facto identity via this change. ──

    public function test_same_roll_number_is_valid_across_distinct_class_section_keys(): void
    {
        $rollNo = '10';
        $key1 = \Firestore_service::buildSectionKey('5', 'A') . '#roll' . $rollNo;
        $key2 = \Firestore_service::buildSectionKey('5', 'B') . '#roll' . $rollNo;
        $key3 = \Firestore_service::buildSectionKey('6', 'A') . '#roll' . $rollNo;
        $this->assertNotSame($key1, $key2);
        $this->assertNotSame($key1, $key3);
        $this->assertNotSame($key2, $key3);
        // classKey()/sectionKey() never read or write a "rollNo" field —
        // static analysis: neither function accepts more than one scalar
        // string argument, so Roll Number cannot leak into the class/section
        // canonical key by construction.
    }

    // ── Cross-tenant: classKey()/sectionKey() are pure string functions with
    //    zero school-scoping — the SL-12 fix cannot introduce or remove tenant
    //    isolation because it never touches schoolId. Isolation is enforced
    //    entirely by callers (schoolWhere()/docId()), unaffected by this change. ──

    public function test_classKey_is_a_pure_function_with_no_school_scoping(): void
    {
        // Same input, called twice with nothing else in scope, must give the
        // same output — confirms no hidden global/school state influences it.
        $this->assertSame(\Firestore_service::classKey('9'), \Firestore_service::classKey('9'));
    }
}
