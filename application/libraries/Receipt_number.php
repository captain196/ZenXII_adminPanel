<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  RECEIPT_NUMBER — two identifiers, deliberately not the same thing
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A fee receipt needs two identifiers that answer different questions, and
 * collapsing them causes one of two failures depending on which way you
 * collapse.
 *
 *   INTERNAL IDENTITY   receiptKey   "F1"
 *       Immutable, unique for the LIFETIME of the school, never reset.
 *       It is the persisted key everywhere: the feeReceipts doc id, the
 *       feeReceiptAllocations doc id, the journal's JE_FEE_{key} and
 *       source_ref, the processedPayments marker. Nothing about it may ever
 *       change, because changing it orphans the books from the money.
 *
 *   ACCOUNTING NUMBER   receiptDisplayNo   "2026-27/000001"
 *       What a parent quotes and an auditor asks for. Restarts each academic
 *       session, per established Indian practice: Rule 46(b) of the CGST
 *       Rules requires a consecutive serial, at most sixteen characters,
 *       "unique for a financial year", and a fresh series each year is the
 *       audit-preferred form — if the same number appears in two years with
 *       nothing to distinguish them, nobody can tell which year a receipt
 *       belongs to without walking the books.
 *
 * ── WHY THEY CANNOT BE THE SAME IDENTIFIER ───────────────────────────────
 * This is the trap, and it is a data-destroying one:
 *
 *   The display number MUST reset each session (audit practice).
 *   The doc id was `F{receiptNo}`.
 *   Reset the number, and next session mints `F1` again —
 *   which is the doc id of a receipt that already exists.
 *
 * Writing it would OVERWRITE a historical receipt, silently, with a
 * different student's payment. So the internal key keeps its own
 * never-resetting sequence, and the display number gets a separate
 * per-session one. Two counters, because there are genuinely two questions.
 *
 * Receipt F1 keeps its identity untouched. It simply gains a display number,
 * which for the first receipt of 2026-27 is 2026-27/000001 — the same value
 * the new allocator would have produced.
 *
 * Sources for the numbering convention (the implementation is ZenX's own):
 *   · Rule 46(b), CGST Rules 2017 — consecutive serial, ≤16 chars, unique per
 *     financial year, alphanumerics with '-' and '/' permitted
 *   · Indian GST commentary on fresh series at FY start and why audits need it
 */
class Receipt_number
{
    /** Counter kind for the never-resetting internal sequence. */
    const KIND_LIFETIME = 'receipt_seq';

    /** Digits in the display sequence: 000001. Six is the common school form. */
    const PAD = 6;

    /** Rule 46(b) caps a serial at sixteen characters. */
    const MAX_LEN = 16;

    /**
     * Counter kind for the per-session display sequence.
     *
     * Session-scoped so it restarts each academic year. Kept in a SEPARATE
     * counter from the lifetime one, because they advance at different times
     * and sharing would reintroduce exactly the collision described above.
     */
    public static function sessionCounterKind(string $session): string
    {
        return 'receipt_disp_' . self::normaliseSession($session);
    }

    /**
     * The immutable internal key. 'F' marks a FEE receipt, distinguishing it
     * from an admission receipt, which numbers separately.
     */
    public static function internalKey(int $lifetimeSeq): string
    {
        return 'F' . $lifetimeSeq;
    }

    /**
     * The human/accounting number: `2026-27/000001`.
     *
     * Falls back to the bare padded sequence when the session is unknown —
     * an unlabelled number is recoverable, a fabricated session label is not.
     */
    public static function displayNumber(string $session, int $sessionSeq): string
    {
        $seq = str_pad((string) max(0, $sessionSeq), self::PAD, '0', STR_PAD_LEFT);
        $ses = self::normaliseSession($session);
        if ($ses === '') return $seq;

        $out = $ses . '/' . $seq;
        // Rule 46(b) caps the serial at sixteen characters. A session label is
        // normally 7 ("2026-27") giving 14, but a school with an unusual label
        // must not silently emit a non-conforming serial.
        if (strlen($out) > self::MAX_LEN) return $seq;
        return $out;
    }

    /**
     * What to SHOW for a receipt, preferring the stored accounting number and
     * degrading to the internal key rather than to nothing.
     *
     * Receipts written before the display series existed have no stored
     * number. They are NOT retro-numbered — a stored financial identifier is
     * not rewritten to make a display consistent — so they continue to show
     * their key, which is what every admin surface already shows.
     */
    public static function forDisplay(array $receipt): string
    {
        $disp = trim((string) ($receipt['receiptDisplayNo'] ?? ''));
        if ($disp !== '') return $disp;

        $key = trim((string) ($receipt['receiptKey'] ?? ''));
        if ($key !== '') return $key;

        $no = trim((string) ($receipt['receiptNo'] ?? ''));
        return $no === '' ? '' : 'F' . $no;
    }

    /**
     * `2026-27` from `2026-27`, `2026-2027`, `2026 - 27` and similar.
     * Anything unrecognisable returns '' so the caller degrades rather than
     * inventing a financial year.
     */
    public static function normaliseSession(string $session): string
    {
        $s = trim($session);
        if ($s === '') return '';
        if (preg_match('/^(\d{4})\s*[-\/]\s*(\d{2,4})$/', $s, $m)) {
            $end = $m[2];
            if (strlen($end) === 4) $end = substr($end, 2);
            return $m[1] . '-' . str_pad($end, 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^\d{4}$/', $s)) return $s;
        // Unrecognised: keep it only if it is safe inside a serial.
        return preg_match('/^[A-Za-z0-9\-]{1,9}$/', $s) === 1 ? $s : '';
    }
}
