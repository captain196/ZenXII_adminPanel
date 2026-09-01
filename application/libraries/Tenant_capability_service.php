<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tenant_capability_service — answers "does THIS TENANT have capability X",
 * independent of RBAC's "can THIS USER do X". The two gates stay separate:
 * this service never looks at the acting user's role, and rbac_helper.php's
 * has_permission()/require_permission() never look at the tenant's plan.
 *
 * Backed by the existing tenantPublic/{schoolId}.activeModules entitlement
 * mirror — the same Firestore doc B2_registry_service::get_features() reads
 * to build the sidebar's `school_features` list. That existing consumer is
 * UI-visibility only; this service is the first place that data is used as
 * an ENFORCED server-side gate. Read via $this->firebase->firestoreGet(),
 * mirroring B2_registry_service's own read of this exact collection.
 *
 * A capability key absent from a tenant's activeModules is DISABLED by
 * default (fail-closed) — a brand-new capability key never silently turns
 * itself on for existing tenants; enabling one is always an explicit,
 * separate action (a plan/entitlement change), never a side effect of
 * shipping code that introduces the key.
 */
class Tenant_capability_service
{
    /** @var object|null Firebase library instance (has firestoreGet()). */
    private $firebase;
    private string $schoolId = '';
    /** @var array|null Request-scoped memo of the resolved activeModules list. */
    private ?array $cache = null;

    /**
     * Bind to a Firebase client + school scope. Idempotent; re-init with a
     * different schoolId clears the memo so a stale tenant's flags can never
     * leak into a different tenant's resolution within the same request.
     */
    public function init($firebase, string $schoolId): self
    {
        if ($this->schoolId !== $schoolId) {
            $this->cache = null;
        }
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        return $this;
    }

    private function activeModules(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if ($this->firebase === null || $this->schoolId === '') {
            return $this->cache = [];
        }
        try {
            $doc = $this->firebase->firestoreGet('tenantPublic', $this->schoolId);
            $am  = is_array($doc) ? ($doc['activeModules'] ?? []) : [];
            $this->cache = is_array($am) ? array_values(array_filter($am, 'is_string')) : [];
        } catch (\Throwable $e) {
            log_message('error', 'Tenant_capability_service: tenantPublic read failed for '
                . $this->schoolId . ': ' . $e->getMessage());
            // Fail closed — an unreadable entitlement doc must never be
            // treated as "everything enabled".
            $this->cache = [];
        }
        return $this->cache;
    }

    /**
     * Does the current tenant have this capability key enabled?
     */
    public function isEnabled(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        return in_array($key, $this->activeModules(), true);
    }
}
