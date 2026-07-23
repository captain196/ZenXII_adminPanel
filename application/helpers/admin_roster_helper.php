<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * admin_roster_helper — single source of truth for "who are this school's admins",
 * during and after the Admin→staff full fold.
 *
 * The roster is the UNION of two sources, deduped:
 *   1. `staff` docs carrying ROLE_ADMIN          — the post-fold home of admins
 *   2. legacy `admins` collection (ADM ids only) — pre-fold home; emptied by the
 *                                                  migration, after which it adds nothing
 *
 * Because source 2 is filtered to ADM-prefix ids, SSA mirrors and STA stubs never
 * appear (matching today's behaviour, where SSA mirror docs lack a schoolId field
 * and are already excluded by the schoolId query). The union is correct at every
 * point in the migration timeline, so no admin list ever goes blank mid-fold. Once
 * the admins collection is retired, delete the source-2 branch (teardown step).
 *
 * Rows are normalised to a common shape so every caller (roster list, name map,
 * duplicate-email scan, recipient picker) can share one read.
 */

if (!function_exists('admin_roster_rows')) {
    /**
     * @return array<int,array{id:string,name:string,email:string,phone:string,role:string,status:string,createdAt:string,lastLogin:string,source:string}>
     */
    function admin_roster_rows($fs, string $schoolId): array
    {
        if ($schoolId === '') return [];

        $byKey = [];   // dedup key => row  (staff source wins over admins)

        // ── Source 1: staff docs with ROLE_ADMIN ──
        $staffDocs = [];
        try {
            $staffDocs = $fs->where('staff', [
                ['schoolId', '==', $schoolId],
                ['staff_roles', 'array-contains', 'ROLE_ADMIN'],
            ]);
        } catch (\Throwable $e) {
            // Composite index may be absent (transition) — fall back to a scan+filter.
            try {
                foreach ($fs->where('staff', [['schoolId', '==', $schoolId]]) as $doc) {
                    $d = $doc['data'] ?? $doc;
                    $roles = _admin_roster_staff_roles(is_array($d) ? $d : []);
                    if (in_array('ROLE_ADMIN', $roles, true)) $staffDocs[] = $doc;
                }
            } catch (\Throwable $e2) { $staffDocs = []; }
        }
        foreach ($staffDocs as $doc) {
            $d = $doc['data'] ?? $doc; if (!is_array($d)) continue;
            $id = (string) ($d['staffId'] ?? $d['id'] ?? ($doc['id'] ?? ''));
            $row = _admin_roster_norm_staff($id, $d);
            $byKey[_admin_roster_key($row)] = $row;
        }

        // ── Source 2: legacy admins collection (ADM-prefix only) ──
        try {
            foreach ($fs->where('admins', [['schoolId', '==', $schoolId]]) as $doc) {
                $d = $doc['data'] ?? $doc; if (!is_array($d)) continue;
                $id = (string) ($d['adminId'] ?? ($doc['id'] ?? ''));
                // entity id must be ADM#### — skip SSA mirrors / STA stubs / junk.
                if (!preg_match('/(ADM\d+)$/', $id, $m)) continue;
                $id  = $m[1];
                $row = _admin_roster_norm_admins($id, $d);
                $k   = _admin_roster_key($row);
                if (!isset($byKey[$k])) $byKey[$k] = $row; // staff source wins
            }
        } catch (\Throwable $e) { /* admins collection may be gone post-teardown */ }

        return array_values($byKey);
    }
}

if (!function_exists('admin_record_collection')) {
    /**
     * Which collection holds an admin's authoritative record, by id prefix.
     * STA ids are staff records (post-fold home); legacy ADM ids still live in
     * the `admins` collection until the migration re-keys them. Callers that
     * read/update/delete an admin by id use this so both eras work during the
     * transition; once the admins collection is retired every admin is STA and
     * this always returns 'staff'.
     */
    function admin_record_collection(string $id): string
    {
        return preg_match('/STA\d+$/', $id) ? 'staff' : 'admins';
    }
}

if (!function_exists('_admin_roster_key')) {
    /** Stable cross-source dedup key: email if present, else id. */
    function _admin_roster_key(array $row): string
    {
        $e = strtolower(trim((string) ($row['email'] ?? '')));
        return $e !== '' ? 'e:' . $e : 'i:' . (string) ($row['id'] ?? '');
    }
}

if (!function_exists('_admin_roster_staff_roles')) {
    function _admin_roster_staff_roles(array $staff): array
    {
        foreach (['staff_roles', 'staffRoles', 'roles', 'role_ids'] as $k) {
            if (is_array($staff[$k] ?? null)) return array_values(array_filter(array_map('strval', $staff[$k])));
        }
        return [];
    }
}

if (!function_exists('_admin_roster_norm_staff')) {
    function _admin_roster_norm_staff(string $id, array $d): array
    {
        $created = $d['Profile']['created_at'] ?? ($d['createdAt'] ?? '');
        return [
            'id'        => $id,
            'name'      => (string) ($d['Name'] ?? $d['name'] ?? $d['Profile']['name'] ?? ''),
            'email'     => (string) ($d['Email'] ?? $d['email'] ?? $d['Profile']['email'] ?? ''),
            'phone'     => (string) ($d['Phone'] ?? $d['PhoneNumber'] ?? $d['Profile']['phone'] ?? ''),
            'role'      => (string) ($d['Role'] ?? $d['Profile']['role'] ?? ''),
            'status'    => strtolower((string) ($d['Status'] ?? 'Active')),
            'createdAt' => is_numeric($created) ? date('Y-m-d', (int) $created) : (string) $created,
            'lastLogin' => (string) ($d['AccessHistory']['LastLogin'] ?? ''),
            'source'    => 'staff',
        ];
    }
}

if (!function_exists('_admin_roster_norm_admins')) {
    function _admin_roster_norm_admins(string $id, array $d): array
    {
        $created = $d['Profile']['created_at'] ?? '';
        return [
            'id'        => $id,
            'name'      => (string) ($d['Name'] ?? $d['Profile']['name'] ?? ''),
            'email'     => (string) ($d['Email'] ?? $d['Profile']['email'] ?? ''),
            'phone'     => (string) ($d['PhoneNumber'] ?? $d['Profile']['phone'] ?? ''),
            'role'      => (string) ($d['Role'] ?? $d['Profile']['role'] ?? ''),
            'status'    => strtolower((string) ($d['Status'] ?? 'Active')),
            'createdAt' => is_numeric($created) ? date('Y-m-d', (int) $created) : (string) $created,
            'lastLogin' => (string) ($d['AccessHistory']['LastLogin'] ?? ''),
            'source'    => 'admins',
        ];
    }
}
