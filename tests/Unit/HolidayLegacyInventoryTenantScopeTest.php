<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tenant-isolation guard for Holiday_legacy_inventory (a read-only legacy
 * holiday inventory diagnostic).
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 *
 * index() gates on ALLOWED_ROLES = ['Super Admin','School Super Admin',
 * 'Admin','Principal'] and then read a CLIENT-supplied ?school_id, falling
 * back to the session:
 *
 *     $schoolId = trim((string)($this->input->get('school_id') ?: $this->school_id));
 *
 * Three of those four roles are SCHOOL-SCOPED (School Super Admin / Admin /
 * Principal — each belongs to exactly one school). So a school admin could
 * pass ?school_id=SCH_OTHER and read another school's legacy holiday stores
 * (RTDB config/events, schoolHolidays, attendance config) — a cross-tenant
 * information disclosure.
 *
 * ── THE FIX ──────────────────────────────────────────────────────────────
 *
 * Only the platform 'Super Admin' (cross-tenant by design) may inventory an
 * arbitrary school via ?school_id. Every school-scoped role is pinned to its
 * own session tenant ($this->school_id), regardless of the query string.
 *
 * Source-assertion (CI controller; cannot be instantiated offline).
 */
final class HolidayLegacyInventoryTenantScopeTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Holiday_legacy_inventory.php');
    }

    private function code(): string
    {
        $out = '';
        foreach (token_get_all(self::$src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return (string) preg_replace('/\s+/', ' ', $out);
    }

    public function test_client_school_id_is_gated_by_platform_super_admin(): void
    {
        $code = $this->code();
        // The client-supplied ?school_id may only be honoured behind an explicit
        // platform 'Super Admin' check.
        $this->assertMatchesRegularExpression(
            "~admin_role\s*===?\s*'Super Admin'\s*\)\s*\?\s*trim\([^?]*input->get\(\s*'school_id'~",
            $code,
            'the ?school_id override must be gated to the platform Super Admin role');
    }

    public function test_school_scoped_roles_fall_back_to_session_tenant(): void
    {
        $code = $this->code();
        // The non-Super-Admin branch must resolve to the session school id.
        $this->assertMatchesRegularExpression(
            "~:\s*\(string\)\s*\\\$this->school_id;~",
            $code,
            'school-scoped roles must be pinned to $this->school_id, not a client value');
    }

    public function test_the_bare_client_override_is_gone(): void
    {
        // The pre-fix unconditional `input->get('school_id') ?: $this->school_id`
        // assigned straight to $schoolId must no longer appear ungated.
        $this->assertDoesNotMatchRegularExpression(
            "~\\\$schoolId\s*=\s*trim\(\(string\)\s*\(\\\$this->input->get\(\s*'school_id'\s*\)\s*\?:~",
            $this->code(),
            'the ungated client school_id assignment must be replaced by the role-gated form');
    }
}
