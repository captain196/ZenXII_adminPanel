<?php
namespace Tests\Support;

/**
 * The SHARED backing store multiple FakeSrNoFirestore instances (one per
 * schoolId) point at, modeling the real architecture: one Firestore
 * database, many tenants, isolation via doc-id prefixing only — never via
 * separate storage. Two schools' fakes sharing ONE instance of this class
 * is what makes a cross-tenant test meaningful (see
 * SrNoServiceMultiTenantTest.php).
 */
class FakeSrNoBackingStore
{
    /** @var array<string,array<string,array>> collection => full-docId => data */
    public array $store = [];
    public int $version = 0;
}
