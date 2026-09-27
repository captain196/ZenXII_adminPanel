<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FEE HEAD REGISTRY — THE WRITE AUTHORITY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THIS CLASS EXISTS ────────────────────────────────────────────────
 * Until now NOTHING in the application could create a fee head. The registry
 * had exactly one writer in the entire repository:
 *
 *     scripts/m3_mint_fee_head_registry.php
 *
 * — a developer-run script carrying a HARDCODED 12-row mapping for two named
 * tenants. `Fee_head_resolver` is read-only, `Entitlements::fee_heads()` is a
 * read-only picker, and `Fee_firestore_sync` deliberately never mints.
 *
 * So a school onboarded tomorrow had no path to a fee head at all, and the
 * consequence was not a missing feature but SILENT UNBILLABILITY: with registry
 * mode active and an empty registry, head resolution fails closed and admission
 * generates ZERO demands (measured: demands=0, ACC_FEEHEAD_UNRESOLVED
 * reason=[not_found], admission still reporting success). The only cure was a
 * developer editing a script and re-running it.
 *
 * That is the "a developer has to remember to do this" category, and this
 * class is what removes it.
 *
 * ── WHY CREATION IS AN ADMINISTRATOR ACTION, NOT AUTO-PROVISIONING ───────
 * It is tempting to mint heads automatically from the fee chart. Two pieces of
 * existing design say no, and both are right:
 *
 *  1. `Fee_firestore_sync` refuses to mint because a chart-only rename or a
 *     delete-and-re-add would hand ONE logical fee head SEVERAL identities.
 *     An absent ref is a fail-closed signal; a confident wrong one is not.
 *
 *  2. `category` is not a display grouping. It answers exactly one question —
 *     DOES THE DEFAULT CONCESSION RULE APPLY? — and it is what stops a
 *     school-wide concession from discounting transport or hostel charges
 *     (invariant I3). It cannot be inferred from a chart row's NAME without
 *     guessing at a financial decision. A wrong guess silently moves money.
 *
 * So the school decides, and the platform records the decision immutably. What
 * was missing was never the decision — it was a supported way to make it.
 *
 * ── IDENTITY RULES, PRESERVED EXACTLY FROM M3 ───────────────────────────
 * The derivation and document shape are byte-compatible with the approved M3
 * mint, so heads created here are indistinguishable from the ones already
 * live. A second minting convention would be a second identity model for the
 * same collection — the precise duplication the registry exists to end.
 *
 *  • feeHeadId  = 'FH_' . strtoupper(substr(sha1(schoolId|nameNorm), 0, 12))
 *    MINT-TIME ONLY. Once stored the id is immutable and a later rename must
 *    NEVER re-derive it, or the rename becomes a new head.
 *  • doc id     = {schoolId}_{feeHeadId}          (tenant-scoped by construction)
 *  • claim id   = {schoolId}_name_{nameNorm}      (the uniqueness gate)
 *  • The CLAIM is written FIRST, create-if-absent. If another process already
 *    holds that name, we must not mint a second identity for it.
 *  • No session participates, and none may ever be introduced: a fee head
 *    survives session rollover with one identity.
 */
class Fee_head_registry_service
{
    /**
     * @var object|null The Firebase handle (application/libraries/Firebase.php),
     *   which is what controllers hold. Uses firestoreCreate()/firestoreGet(),
     *   the same create-if-absent pair the other tenant-scoped services use.
     *   The M3 script talked to Firestore_rest_client directly because it runs
     *   outside CI; the SEMANTICS below are identical, the transport is not.
     */
    private $fs = null;

    /** @var string Actor recorded on every document this service writes. */
    private string $actorId = 'system';

    public function init($fs, string $actorId = 'system'): self
    {
        $this->fs      = $fs;
        $this->actorId = trim($actorId) !== '' ? trim($actorId) : 'system';
        return $this;
    }

    /**
     * MINT-TIME derivation only — identical to M3. Never call this to "look up"
     * an existing head: once stored, the id is authoritative and a rename must
     * not re-derive it.
     */
    public static function mintId(string $schoolId, string $nameNorm): string
    {
        return 'FH_' . strtoupper(substr(sha1($schoolId . '|' . $nameNorm), 0, 12));
    }

    /**
     * Create one fee head for one school.
     *
     * IDEMPOTENT: both writes are create-if-absent and the identity is derived
     * deterministically, so a repeated call with the same (school, name) mints
     * nothing new and reports the identity that already exists. A double-click
     * cannot produce two heads.
     *
     * REFUSES rather than coerces. An unassignable category, a blank name, or a
     * name already claimed by a DIFFERENT identity all return ok=false with a
     * reason the caller can show the administrator. Nothing is written on any
     * refusal path.
     *
     * @return array{ok:bool, feeHeadId?:string, docId?:string, created?:bool, error?:string, reason?:string}
     */
    public function create(string $schoolId, string $name, string $category): array
    {
        if ($this->fs === null) {
            return ['ok' => false, 'reason' => 'no_firestore', 'error' => 'Registry service is not initialised.'];
        }

        $schoolId = trim($schoolId);
        if ($schoolId === '') {
            return ['ok' => false, 'reason' => 'no_tenant', 'error' => 'A fee head must belong to a school.'];
        }

        // Collapse internal whitespace so "Tuition  Fee" and "Tuition Fee" cannot
        // become two heads. nameNorm() is the resolver's own normalisation — the
        // claim is keyed on it, so using anything else here would let a
        // near-duplicate slip past the uniqueness gate.
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return ['ok' => false, 'reason' => 'no_name', 'error' => 'A fee head needs a name.'];
        }
        if (mb_strlen($name) > 80) {
            return ['ok' => false, 'reason' => 'name_too_long', 'error' => 'Fee head name is limited to 80 characters.'];
        }

        if (!class_exists('Fee_head_resolver')) {
            require_once APPPATH . 'libraries/Fee_head_resolver.php';
        }

        $category = strtolower(trim($category));
        if (!Fee_head_resolver::isAssignableCategory($category)) {
            // Never coerce to a default. The category decides whether a
            // school-wide concession reaches this head, so a guess here is a
            // silent money decision made on the school's behalf.
            return ['ok' => false, 'reason' => 'bad_category', 'error' =>
                'Category must be one of: ' . implode(', ', Fee_head_resolver::ASSIGNABLE) . '.'];
        }

        $nameNorm = Fee_head_resolver::nameNorm($name);
        $feeHeadId = self::mintId($schoolId, $nameNorm);
        $docId     = Fee_head_resolver::docId($schoolId, $feeHeadId);
        $claimId   = Fee_head_resolver::claimDocId($schoolId, $name);
        $now       = gmdate('c');
        $actor     = $this->actorId;

        $claim = [
            'kind'      => Fee_head_resolver::KIND_NAME_CLAIM,
            'schoolId'  => $schoolId,
            'nameNorm'  => $nameNorm,
            'feeHeadId' => $feeHeadId,
            'claimedAt' => $now,
            'claimedBy' => $actor,
        ];

        // ── THE CLAIM GOES FIRST ────────────────────────────────────────────
        // It is the uniqueness gate, and it is why two administrators clicking
        // at the same moment cannot mint two identities for one name.
        $claimOk = (bool) $this->fs->firestoreCreate(Fee_head_resolver::COL, $claimId, $claim);
        if (!$claimOk) {
            $held   = $this->fs->firestoreGet(Fee_head_resolver::COL, $claimId);
            $heldId = is_array($held) ? (string) ($held['feeHeadId'] ?? '') : '';
            if ($heldId !== '' && $heldId !== $feeHeadId) {
                // Someone else owns this name under a different identity. Minting
                // now would give one logical head two identities — refuse, loudly.
                log_message('error', 'FEEHEAD_CLAIM_CONFLICT school=[' . $schoolId . '] name=['
                    . $name . '] heldBy=[' . $heldId . '] wanted=[' . $feeHeadId . '] — refusing to mint.');
                return ['ok' => false, 'reason' => 'name_taken', 'error' =>
                    'A different fee head already uses this name. Rename it, or pick another name.'];
            }
            // Same identity: this is a re-run, which is a success, not a clash.
        }

        $doc = [
            'feeHeadId'     => $feeHeadId,
            'schoolId'      => $schoolId,
            'name'          => $name,
            'nameNorm'      => $nameNorm,
            'category'      => $category,
            'categorySetAt' => $now,
            'categorySetBy' => $actor,
            'status'        => 'active',
            'displayGroup'  => '',
            'sortOrder'     => 0,
            'createdAt'     => $now,
            'createdBy'     => $actor,
            'updatedAt'     => $now,
            'updatedBy'     => $actor,
        ];

        $created = (bool) $this->fs->firestoreCreate(Fee_head_resolver::COL, $docId, $doc);

        log_message('error', 'FEEHEAD_REGISTERED school=[' . $schoolId . '] head=[' . $name
            . '] id=[' . $feeHeadId . '] category=[' . $category . '] created=['
            . ($created ? 'yes' : 'already existed') . '] by=[' . $actor . ']');

        return ['ok' => true, 'feeHeadId' => $feeHeadId, 'docId' => $docId, 'created' => $created];
    }
}
