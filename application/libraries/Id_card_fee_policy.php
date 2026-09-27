<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Id_card_fee_policy — decides WHETHER and HOW MUCH to charge for a card
 * replacement, and builds the demand identity that keeps each replacement
 * separate.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  THIS IS NOT A FEE ENGINE
 * ═══════════════════════════════════════════════════════════════════════
 *  It raises nothing and writes nothing. It answers three questions —
 *  "is this reason chargeable?", "how much?", "under what fee head?" — and the
 *  caller hands the answer to the EXISTING canonical generator,
 *  `Fee_lifecycle::createModuleFee()`, exactly as Transport and Hostel already
 *  do. No second fee path, no second balance formula, no second demand writer.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  THE DEMAND-IDENTITY PROBLEM (ID-D2) AND WHY THE HEAD CARRIES THE VERSION
 * ═══════════════════════════════════════════════════════════════════════
 *  `Fee_lifecycle::createModuleFee()` builds its demand id as
 *
 *      DEM_{studentId}_{sessionYear}04_{HEAD}_{MODULE}
 *
 *  with the period HARDCODED to the session's April (Fee_lifecycle.php:999).
 *  That is correct for Transport and Hostel, which model a recurring
 *  subscription amended in place — but a card replacement is a DISCRETE
 *  chargeable event. Calling the generator twice in one session with the same
 *  head would hit the SAME demand id and REWRITE the first charge rather than
 *  add a second; worse, if the first was already paid, the balance recomputes
 *  to zero and the ledger claims the second card was paid for. The second
 *  replacement would be silently free.
 *
 *  `_demandId()` derives its key from the FEE HEAD NAME, so putting the card
 *  version in the head gives every replacement its own demand:
 *
 *      "ID Card Replacement (Card v2)" → DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V2_IDCARD
 *      "ID Card Replacement (Card v3)" → DEM_STU0012_202604_ID_CARD_REPLACEMENT_CARD_V3_IDCARD
 *
 *  Two properties fall out of this for free, and both matter:
 *    • IDEMPOTENCY — re-submitting the SAME replacement (a double-click, a
 *      retry) targets the same demand id and rewrites identical values. No
 *      duplicate charge.
 *    • LEGIBILITY — the parent's receipt line names the card they are paying
 *      for, instead of an opaque repeated "ID Card Replacement".
 *
 *  Fee_lifecycle.php is NOT modified. This works within its existing contract.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  POLICY IS THE SCHOOL'S, NOT OURS
 * ═══════════════════════════════════════════════════════════════════════
 *  Every amount defaults to ZERO, and zero means the generator is never called
 *  (`createModuleFee` returns null for amount <= 0), so no school starts
 *  charging as a side effect of this code shipping.
 *
 *  There is NO Indian K-12 norm to inherit: the external research found only
 *  CBSE *admit-card* duplicate fees, a board artefact under different
 *  authority. The structure below (first issue free, loss chargeable, wear and
 *  school-error free) follows documented US higher-ed practice; the AMOUNTS do
 *  not generalise and are deliberately left at zero for the school to set.
 *
 *  Per-school overrides live in Firestore `feeSettings` so a school can be
 *  configured without a deploy; the config file is only the fallback.
 */
class Id_card_fee_policy
{
    /** @var object|null */ private $fs;
    private array  $amounts = [];
    private string $head    = 'ID Card Replacement';
    private string $module  = 'IDCard';
    private bool   $ready   = false;

    /**
     * Due date stamped on a replacement demand (ID-DUE-1). Supplied by the
     * caller; empty means "no due date", which is the pre-19N behaviour and
     * leaves the charge unchaseable — so callers should always set it.
     */
    private string $dueDate = '';

    /** Days a family is given to pay a replacement charge before it is overdue. */
    public const DEFAULT_DUE_DAYS = 30;

    /**
     * Set the due date for demands built by this instance.
     * `$asOf` is injectable so tests are not clock-dependent.
     */
    public function withDueInDays(int $days = self::DEFAULT_DUE_DAYS, ?string $asOf = null): self
    {
        if ($days < 0) $days = 0;
        $base = $asOf !== null && $asOf !== '' ? $asOf : date('Y-m-d');
        $ts   = strtotime($base . ' +' . $days . ' days');
        $this->dueDate = $ts === false ? '' : date('Y-m-d', $ts);
        return $this;
    }

    public function dueDate(): string { return $this->dueDate; }

    /**
     * Reasons that may EVER be chargeable. `first_issue` is absent by design —
     * the first card is part of admission — and `wear` / `data_correction` /
     * `admin` are absent because normal wear and the school's own corrections
     * are not the family's fault. A reason outside this set can never produce
     * a charge no matter what a config says.
     */
    public static function chargeableReasons(): array
    {
        // ── IDC-FEE-LOAD-1 (2026-09-19) ──────────────────────────────────────
        //
        // CI3 has NO autoloader for libraries, so the three constants below fatal
        // with `Class "Id_card_service" not found` unless that library happens to
        // have been loaded earlier in the SAME request.
        //
        // That made this static silently order-dependent: it worked from the ID-card
        // page (which builds the service first) and threw from Sis::id_card_fee_settings()
        // and ::id_card_fee_settings_save(), which never load it. The Replacement
        // Charges dialog therefore failed with a 400 and "Could not load replacement
        // charges" — a message that named the symptom and hid the cause, because the
        // controller catches Throwable and rewrites it.
        //
        // The same trap was found and fixed once before, in id_card_issue() (Phase 19M),
        // by loading the service first at that ONE call site. Fixing it there did not
        // fix it here, which is the argument for closing it at the dependency instead:
        // a public static must not carry a load-order requirement its callers cannot
        // see. require_once is idempotent, so a caller that already loaded the library
        // pays nothing.
        if (!class_exists('Id_card_service', false)) {
            require_once APPPATH . 'libraries/Id_card_service.php';
        }

        return [
            Id_card_service::REASON_LOST,
            Id_card_service::REASON_STOLEN,
            Id_card_service::REASON_DAMAGED,
        ];
    }

    public function init($fs, array $configAmounts = [], string $head = '', string $module = ''): self
    {
        $this->fs      = $fs;
        $this->amounts = $configAmounts;
        if ($head   !== '') $this->head   = $head;
        if ($module !== '') $this->module = $module;
        $this->ready   = true;
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    /**
     * Per-school override document. Read-only, best-effort: a missing or
     * unreadable document simply means "use the configured fallback", which is
     * zero — it must never fail open into charging a family.
     */
    public function schoolOverrides(string $schoolId, string $session): array
    {
        if (!$this->ready || $this->fs === null || $schoolId === '') return [];
        try {
            $doc = $this->fs->get('feeSettings', "{$schoolId}_{$session}_idcard");
            $a   = is_array($doc) ? ($doc['replacementFees'] ?? null) : null;
            return is_array($a) ? $a : [];
        } catch (\Throwable $e) {
            log_message('error', 'Id_card_fee_policy::schoolOverrides failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Amount for a reason. School override wins over the config fallback.
     * Anything non-numeric, negative or not in chargeableReasons() is 0.0.
     */
    public function amountFor(string $reason, array $overrides = []): float
    {
        if (!in_array($reason, self::chargeableReasons(), true)) return 0.0;

        $raw = $overrides[$reason] ?? ($this->amounts[$reason] ?? 0);
        if (!is_numeric($raw)) return 0.0;
        $amt = (float) $raw;
        return $amt > 0 ? round($amt, 2) : 0.0;
    }

    /** True only when this reason, under this school's policy, costs money. */
    public function isChargeable(string $reason, array $overrides = []): bool
    {
        return $this->amountFor($reason, $overrides) > 0;
    }

    /**
     * The fee head name — STABLE across card versions.
     *
     * Fee Head Registry phase (2026-08-17): this used to return
     * `'ID Card Replacement (Card v2)'`, minting a NEW head name per card
     * version. Two things were wrong with that:
     *
     *  • it produced an UNBOUNDED family of head names, which cannot be
     *    pre-registered — so once module fees must resolve a registered head
     *    or fail closed, the second replacement would have failed in
     *    production;
     *  • it made a DISPLAY STRING load-bearing for demand identity, because
     *    `_demandId()` derives its key from the head name for module fees.
     *
     * The version is now carried explicitly as the charge instance
     * (`instanceKey()` below), which is what actually distinguishes two
     * replacements. One head, many instances.
     */
    public function feeHeadFor(int $cardVersion): string
    {
        return $this->head;
    }

    /**
     * WHICH OCCURRENCE this charge is.
     *
     * `cardVersion` comes from `Id_card_service::issue()`, which claims it via
     * `firestoreCreate` on `{studentId}_v{N}` — atomic create-if-not-exists,
     * probing upward on collision. It is therefore per-student, strictly
     * monotonic and NEVER reused, and the fee is raised only after the claim
     * succeeds. That makes it the authoritative instance discriminator; no
     * timestamp, UUID, counter or display string is needed or permitted.
     */
    public function instanceKey(int $cardVersion): string
    {
        if ($cardVersion < 1) $cardVersion = 1;
        return 'V' . $cardVersion;
    }

    public function module(): string { return $this->module; }

    /**
     * The exact payload for `Fee_lifecycle::createModuleFee()`, or NULL when
     * this replacement is not chargeable.
     *
     * Returning null rather than a zero-amount payload is deliberate: it makes
     * "free" a decision taken HERE, in one place, instead of relying on the
     * generator's own amount<=0 guard as a silent second gate.
     */
    public function demandPayloadFor(string $reason, int $cardVersion, array $overrides = []): ?array
    {
        $amount = $this->amountFor($reason, $overrides);
        if ($amount <= 0) return null;

        return [
            'fee_head'  => $this->feeHeadFor($cardVersion),
            // The version now travels HERE, not inside the head name. It is
            // appended to the demand id and persisted on the demand, so two
            // replacements in one period remain two charges and a later
            // recalculation rebuilds the same id without parsing any string.
            'chargeInstance' => $this->instanceKey($cardVersion),
            'amount'    => $amount,
            'frequency' => 'Annual',   // a one-off event, not a monthly charge

            // ── ID-DUE-1 (Phase 19N) — the demand MUST carry a due date ──
            // `Fee_lifecycle::createModuleFee()` stamps no dueDate (only the
            // tuition convergence path does), and a dateless demand is
            // invisible to the two things that chase an unpaid charge:
            //   • Fee_reminder_service::stageFor() returns null without a
            //     dueDate, so the family is NEVER reminded;
            //   • Fee_late_fee_service::fineFor() returns null, so it can
            //     never age into a defaulter escalation.
            // A replacement charge would have sat unpaid and unchased forever.
            //
            // The date is supplied by the CALLER (see Sis::_maybe_charge_...)
            // rather than computed here, because a policy object must not
            // invent a billing calendar. `createModuleFee` merges this key
            // straight through to the demand document.
            'due_date'    => $this->dueDate,

            // Auditable provenance — the demand records WHY it exists and for
            // WHICH card, so a receipt or ledger line can always be traced back
            // to a specific replacement.
            'reason'      => $reason,
            'cardVersion' => $cardVersion,
        ];
    }
}
