<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 * FEE HEAD RESOLVER — the single authority for fee-head classification
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Resolves `schoolId + feeHeadRef` to the authoritative registry entry and its
 * financial `category`. This is the ONLY sanctioned way to learn what kind of
 * charge a fee head represents once the registry cutover has happened.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────
 * Before the registry, classification was recovered by GUESSING FROM THE
 * DISPLAY NAME (`Fees::_resolveFeeCategory()` keyword matching) or from a
 * module name (`createModuleFee` writing `strtolower($moduleType)`), and the
 * canonical admission path emitted no category at all. The result, measured
 * live: 1,190 of 1,242 demands carried `category=''`, including 500
 * `Transport Fee` demands — every one of which a blanket `scope:'all'`
 * concession would have wrongly discounted, because invariant I3 tests a
 * value that was never populated.
 *
 * ── HARD RULES THIS CLASS ENFORCES ─────────────────────────────────────────
 *  1. IDENTITY IS SCHOOL-SCOPED.  `schoolId + feeHeadId → head`. There is no
 *     session dimension, ever. A head survives session rollover, repricing,
 *     rename and archival with the same id. (Same architectural rule as SRNO:
 *     a school-scoped operational identity must never become session-scoped.)
 *  2. NEVER CLASSIFY BY NAME. No keyword matching, no substring test, no
 *     module-name inference. The name is display identity only and is mutable.
 *  3. FAIL CLOSED. A missing ref, an unknown head, an archived-but-required
 *     head, an invalid category or a read failure returns ok=false. Callers
 *     must abort generation — never default to 'academic'.
 *  4. NEVER COERCE. An unrecognised stored category is rejected, not mapped to
 *     something plausible.
 *  5. `feeHeadRef` NEVER PARTICIPATES IN DEMAND IDENTITY. This class returns
 *     classification only. Demand ids are built by Fee_lifecycle::_demandId()
 *     from their own inputs — feeding a feeHeadRef into that key would change
 *     every existing Transport/Hostel demand id and duplicate live charges.
 *
 * NOT WIRED into any billing path yet — this class is inert until the
 * USE_FEE_HEAD_REGISTRY cutover step.
 */
class Fee_head_resolver
{
    /** The registry collection. Doc id: `{schoolId}_{feeHeadId}`. */
    public const COL = 'feeHeadRegistry';

    /** Name-uniqueness claim doc id: `{schoolId}_name_{nameNorm}`. */
    public const CLAIM_PREFIX = 'name_';

    /** `kind` marking a name-claim token rather than a head. See listForSchool(). */
    public const KIND_NAME_CLAIM = 'nameClaim';

    /**
     * THE CLOSED FINANCIAL VOCABULARY.
     *
     * The single question a category answers is: DOES THE DEFAULT
     * SCHOLARSHIP/CONCESSION RULE APPLY? It is not a display grouping —
     * 'Extra-curricular', 'Other' and 'General' are display concepts and are
     * deliberately absent, because for each of them the eligibility answer is
     * identical to 'academic' and a second way to express one rule is a second
     * way to get it wrong.
     */
    public const CATEGORIES = ['academic', 'transport', 'hostel', 'meals', 'administrative', 'unknown'];

    /**
     * Service categories — a family opts INTO these. `administrative` is
     * excluded by default but is deliberately NOT a service: nobody opts into
     * replacing a lost ID card. Kept aligned with
     * Fee_entitlement_reader::SERVICE_CATEGORIES.
     */
    public const SERVICE_CATEGORIES = ['transport', 'hostel', 'meals'];

    /**
     * Categories an operator may assign to a head.
     *  - 'unknown' is never assignable: it is a READ-TIME interpretation of
     *    legacy `category=''`, not a choice.
     *  - 'meals' is reserved but withheld: ZenX bills no meals today (no
     *    module, controller, collection or head exists), and offering it would
     *    let a school classify heads into a category nothing consumes.
     */
    public const ASSIGNABLE = ['academic', 'transport', 'hostel', 'administrative'];

    /** The only category eligible for ordinary scholarship/concession by default. */
    public const DEFAULT_ELIGIBLE = 'academic';

    private $fs = null;

    /** Request-scoped memo, keyed `{schoolId}|{feeHeadRef}`. Prevents a repeat
     *  read per demand — the same pattern canonicalHeadIdMap() already uses.
     *  Deliberately NOT a cross-request cache: a classification change must be
     *  visible on the next request, not after a TTL. */
    private array $memo = [];

    public function init($fs): self
    {
        $this->fs   = $fs;
        $this->memo = [];
        return $this;
    }

    /**
     * F-3 — every ACTIVE head this school has registered, for an operator to
     * PICK from. Read-only; mints nothing, resolves nothing, decides nothing.
     *
     * Why it lives here rather than in a controller: this class is the single
     * fee-head authority, and a controller querying `feeHeadRegistry` directly
     * would be a second reader of the same collection with its own idea of what
     * a "head" is — exactly the duplication the registry exists to end.
     *
     * WHAT IT EXCLUDES, and why:
     *  • name-claim documents (`kind: 'nameClaim'`) — they share the collection
     *    but are uniqueness tokens, not heads. Offering one as a pickable head
     *    would hand the operator a `feeHeadRef` that resolve() cannot resolve.
     *  • non-active heads — an archived head must not be attachable to a NEW
     *    entitlement. resolveForNewUse() already enforces this at write time;
     *    filtering here means the operator never sees the dead end.
     *  • heads whose stored category is invalid or 'unknown' — same rule as
     *    resolve(): refused, never coerced.
     *
     * Returns a LIST (not a map) sorted by sortOrder then name, so the picker's
     * order is the school's own and stable between loads.
     *
     * @return array<int, array{feeHeadRef:string, name:string, category:string}>
     */
    public function listForSchool(string $schoolId): array
    {
        $schoolId = trim($schoolId);
        if ($schoolId === '' || $this->fs === null) return [];

        // The school-scoped query scopes by the HELPER's tenant, not by the
        // argument. If the two disagree the query would return another school's
        // rows (which the per-row check below then discards) and the caller
        // would see a silently empty list. Refuse loudly instead — a
        // wrong-tenant read is a bug, not an empty registry.
        //
        // ── TENANT ACCESSOR — DEC-010 (2026-08-18) ────────────────────────────────
        // `$this->fs` is **Firestore_service** in production, and its
        // `schoolCode()` returns the LOGIN CODE ("10012"), not the canonical
        // school id ("SCH_B56BB9A401"); only `schoolId()` is canonical. This
        // guard compared against `schoolCode()`, so on every tenant whose
        // school_code differs from its school_id it tripped on ITSELF and
        // returned [] — the picker rendered "No fee heads registered" and a
        // head-scoped entitlement was impossible to create through the UI.
        // Proven in production: `listForSchool tenant mismatch:
        // asked=SCH_B56BB9A401 helper=10012`.
        //
        // Same root cause as DEC-008 (a canonical-keyed store addressed with
        // the legacy key), one layer out: there it was the caller passing the
        // wrong id, here it is the callee reading the wrong accessor.
        // `schoolCode()` is kept as a fallback for helper shapes that expose
        // only that verb, so the tenant check never silently disappears.
        $tenant = null;
        if (method_exists($this->fs, 'schoolId')) {
            $tenant = (string) $this->fs->schoolId();
        } elseif (method_exists($this->fs, 'schoolCode')) {
            $tenant = (string) $this->fs->schoolCode();
        }
        if ($tenant !== null && $tenant !== $schoolId) {
            log_message('error', 'Fee_head_resolver::listForSchool tenant mismatch: asked='
                . $schoolId . ' helper=' . $tenant);
            return [];
        }

        try {
            // Firestore_service exposes `schoolWhere()`; Firestore_helper
            // exposes `querySchool()`. This method previously called
            // `querySchool()` unconditionally — a method Firestore_service does
            // NOT have — so even with the tenant guard corrected the call threw
            // and was swallowed by the fail-closed catch below, producing the
            // same empty picker by a second, quieter route. Both defects had to
            // be fixed for a single head to reach the operator.
            if (method_exists($this->fs, 'schoolWhere')) {
                $rows = $this->fs->schoolWhere(self::COL, []);
            } elseif (method_exists($this->fs, 'querySchool')) {
                $rows = $this->fs->querySchool(self::COL, []);
            } else {
                throw new \RuntimeException(
                    'the injected Firestore helper exposes no school-scoped query verb');
            }
        } catch (\Throwable $e) {
            // Fail closed: an empty list makes the picker say "none registered"
            // and refuse, which is the safe direction. It must never fall back
            // to free text — that is the defect F-3 removes.
            log_message('error', "Fee_head_resolver::listForSchool failed school={$schoolId}: " . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ((array) $rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            if ($d === []) continue;
            if ((string) ($d['kind'] ?? '') === self::KIND_NAME_CLAIM) continue;
            if ((string) ($d['schoolId'] ?? '') !== $schoolId) continue;   // tenant isolation
            if ((string) ($d['status'] ?? 'active') !== 'active') continue;

            $ref = trim((string) ($d['feeHeadId'] ?? ''));
            $cat = (string) ($d['category'] ?? '');
            if ($ref === '' || $cat === 'unknown' || !self::isValidCategory($cat)) continue;

            $out[] = [
                'feeHeadRef' => $ref,
                'name'       => (string) ($d['name'] ?? ''),
                'category'   => $cat,
                'sortOrder'  => (int) ($d['sortOrder'] ?? 0),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return $a['sortOrder'] <=> $b['sortOrder'] ?: strcasecmp($a['name'], $b['name']);
        });
        foreach ($out as &$row) unset($row['sortOrder']);
        return $out;
    }

    /** Normalised name — the uniqueness key. Lowercase, trimmed, whitespace collapsed. */
    public static function nameNorm(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    public static function docId(string $schoolId, string $feeHeadId): string
    {
        return $schoolId . '_' . $feeHeadId;
    }

    public static function claimDocId(string $schoolId, string $name): string
    {
        return $schoolId . '_' . self::CLAIM_PREFIX . self::nameNorm($name);
    }

    public static function isValidCategory(string $c): bool
    {
        return in_array($c, self::CATEGORIES, true);
    }

    public static function isAssignableCategory(string $c): bool
    {
        return in_array($c, self::ASSIGNABLE, true);
    }

    public static function isServiceCategory(string $c): bool
    {
        return in_array($c, self::SERVICE_CATEGORIES, true);
    }

    /**
     * Does the ordinary academic scholarship/concession apply to this category
     * WITHOUT an explicit scope naming it?
     *
     * academic → yes. Everything else, including 'administrative' and the
     * fail-closed 'unknown' → no.
     */
    public static function isDefaultEligible(string $category): bool
    {
        return $category === self::DEFAULT_ELIGIBLE;
    }

    /**
     * RESOLVE — the authoritative lookup. Classification only; never identity
     * for a demand.
     *
     * @return array{ok:bool,reason?:string,feeHeadId?:string,name?:string,category?:string,status?:string}
     */
    public function resolve(string $schoolId, string $feeHeadRef): array
    {
        $schoolId    = trim($schoolId);
        $feeHeadRef  = trim($feeHeadRef);

        // Fail closed on missing inputs — an empty ref is exactly the
        // "anonymous runtime head" this design exists to abolish.
        if ($schoolId === '')   return ['ok' => false, 'reason' => 'missing_school_id'];
        if ($feeHeadRef === '') return ['ok' => false, 'reason' => 'missing_fee_head_ref'];
        if ($this->fs === null) return ['ok' => false, 'reason' => 'not_initialized'];

        $memoKey = $schoolId . '|' . $feeHeadRef;
        if (array_key_exists($memoKey, $this->memo)) return $this->memo[$memoKey];

        try {
            $doc = $this->fs->get(self::COL, self::docId($schoolId, $feeHeadRef));
        } catch (\Throwable $e) {
            // A read failure must NOT fall back to the legacy heuristic. An
            // unclassified charge is a stop, not a guess.
            log_message('error', "Fee_head_resolver::resolve read failed school={$schoolId} ref={$feeHeadRef}: " . $e->getMessage());
            return ['ok' => false, 'reason' => 'read_failed'];
        }

        if (!is_array($doc)) {
            return $this->memo[$memoKey] = ['ok' => false, 'reason' => 'not_found'];
        }

        // TENANT ISOLATION — the doc id is school-prefixed, but a stored
        // schoolId that disagrees means the document is not this school's and
        // must never resolve. Belt and braces, deliberately.
        if ((string) ($doc['schoolId'] ?? '') !== $schoolId) {
            log_message('error', "Fee_head_resolver::resolve cross-school mismatch school={$schoolId} ref={$feeHeadRef}");
            return $this->memo[$memoKey] = ['ok' => false, 'reason' => 'cross_school_mismatch'];
        }

        $category = (string) ($doc['category'] ?? '');
        if (!self::isValidCategory($category) || $category === 'unknown') {
            // An unrecognised or 'unknown' stored category is refused rather
            // than coerced. 'unknown' is a legacy read-time interpretation and
            // must never be a live head's classification.
            return $this->memo[$memoKey] = ['ok' => false, 'reason' => 'invalid_category'];
        }

        return $this->memo[$memoKey] = [
            'ok'        => true,
            'feeHeadId' => (string) ($doc['feeHeadId'] ?? $feeHeadRef),
            'name'      => (string) ($doc['name'] ?? ''),
            'category'  => $category,
            'status'    => (string) ($doc['status'] ?? 'active'),
        ];
    }

    /**
     * IDENTITY lookup by exact normalised name — for module fees only.
     *
     * ⚠ THIS IS NOT NAME-BASED CLASSIFICATION. The distinction matters and is
     * the whole point: the classification still comes from the registry
     * record. What happens here is an EXACT-MATCH binding of an already-known
     * head name (Transport supplies 'Transport Fee', Hostel 'Hostel Fee',
     * Id_card_fee_policy 'ID Card Replacement') to its authoritative entry.
     * There is no keyword matching, no substring test, no synonym list, and no
     * fallback — a head whose name is not registered resolves to NOTHING and
     * the caller fails closed.
     *
     * It exists because module callers do not yet carry a feeHeadRef; when
     * they do, this method becomes unnecessary and should be retired.
     *
     * @return array same shape as resolve(), or ok=false
     */
    public function resolveByName(string $schoolId, string $name): array
    {
        $schoolId = trim($schoolId);
        $norm     = self::nameNorm($name);
        if ($schoolId === '') return ['ok' => false, 'reason' => 'missing_school_id'];
        if ($norm === '')     return ['ok' => false, 'reason' => 'missing_fee_head_name'];
        if ($this->fs === null) return ['ok' => false, 'reason' => 'not_initialized'];

        $memoKey = $schoolId . '|name|' . $norm;
        if (array_key_exists($memoKey, $this->memo)) return $this->memo[$memoKey];

        // The name claim is the registry's own uniqueness record — one claim
        // per normalised name per school — so this is a direct document read,
        // not a scan or a guess.
        try {
            $claim = $this->fs->get(self::COL, self::claimDocId($schoolId, $norm));
        } catch (\Throwable $e) {
            log_message('error', "Fee_head_resolver::resolveByName read failed school={$schoolId} name={$norm}: " . $e->getMessage());
            return ['ok' => false, 'reason' => 'read_failed'];
        }
        if (!is_array($claim) || trim((string) ($claim['feeHeadId'] ?? '')) === '') {
            return $this->memo[$memoKey] = ['ok' => false, 'reason' => 'not_found'];
        }
        return $this->memo[$memoKey] = $this->resolve($schoolId, (string) $claim['feeHeadId']);
    }

    /**
     * Resolve strictly for NEW pricing/assignment: an archived head may still
     * explain a historical demand, but must never be selected for new use.
     */
    public function resolveForNewUse(string $schoolId, string $feeHeadRef): array
    {
        $r = $this->resolve($schoolId, $feeHeadRef);
        if (!empty($r['ok']) && ($r['status'] ?? 'active') !== 'active') {
            return ['ok' => false, 'reason' => 'archived'];
        }
        return $r;
    }
}
