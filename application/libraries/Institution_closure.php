<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Institution_closure — the closure checklist.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHAT THIS IS
 * ──────────────────────────────────────────────────────────────────────
 * A COMPUTED work-list of everything a school still owes before it can be
 * declared CLOSED. It answers "what is unresolved?" and nothing else.
 *
 * It does NOT:
 *   • delete anything — not a student, not a staff record, not a receipt,
 *     not a journal, not a trip. Closure NEVER destroys data. Deletion, if
 *     it ever happens, is a separate retention-schedule act with its own
 *     approval and its own audit event.
 *   • withdraw students or separate staff. Those are human decisions with
 *     money and legal consequences; this produces the list a human works
 *     through, which is the whole design.
 *   • mutate any domain record. Like the readiness scorer, it is a pure
 *     read.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHY CLOSING IS NOT "OFF"
 * ──────────────────────────────────────────────────────────────────────
 * CBSE's affiliation bye-laws require a closing school to retain its
 * infrastructure and teachers until classes IX–XII have completed their
 * board examinations. A school therefore keeps operating for months after
 * closure is announced. CLOSING exists to make that period legible and
 * safe: no new intake, everything else continues, and this checklist
 * tracks what still has to finish.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  READ BUDGET
 * ──────────────────────────────────────────────────────────────────────
 * Server-side COUNT aggregations only — one per item, flat, independent of
 * school size. No collection is paged into PHP, and there is no per-student
 * or per-trip loop. An unavailable count is reported as UNKNOWN, never as
 * zero: a Firestore blip must not be able to certify a school as clear.
 */
class Institution_closure
{
    const CLEAR    = 'CLEAR';       // nothing outstanding
    const BLOCKING = 'BLOCKING';    // must be resolved or explicitly overridden
    const WARNING  = 'WARNING';     // should be resolved
    const UNKNOWN  = 'UNKNOWN';     // could not be determined — never treated as clear

    /** @var object|null */ private $firebase = null;
    /** @var bool */        private $ready    = false;

    public function init($firebase): self
    {
        $this->firebase = $firebase;
        $this->ready    = ($firebase !== null);
        return $this;
    }

    /**
     * Compute the closure checklist.
     *
     * @return array{
     *   schoolId:string, session:string,
     *   blocking:int, warnings:int, unknown:int,
     *   closeAllowed:bool, requiresOverride:bool, items:array
     * }
     */
    public function checklist(string $schoolId, string $session = ''): array
    {
        if (!$this->ready || $schoolId === '') {
            return $this->_summarise($schoolId, $session, [
                $this->_item('engine', 'System', 'Closure checklist', self::UNKNOWN, -1,
                    'The checklist could not be evaluated.',
                    'Closing a school without knowing what is outstanding risks stranding money and records.',
                    'Retry; if it persists this is an internal error.'),
            ]);
        }

        $where  = [['schoolId', '=', $schoolId]];
        $items  = [];

        // ── Admissions ────────────────────────────────────────────────
        $items[] = $this->_countItem('admissions.pending', 'Admissions',
            'Pending admission applications', 'crmApplications',
            array_merge($where, [['status', '=', 'pending']]), self::WARNING,
            'Applications still open for a school that is closing.',
            'A family waiting on an application must be told, not silently dropped.',
            'Decide or withdraw each application in Admissions.');

        // ── Money owed TO the school ──────────────────────────────────
        $items[] = $this->_countItem('fees.outstanding', 'Fees',
            'Unpaid fee demands', 'feeDemands',
            array_merge($where, [['status', '=', 'unpaid']]), self::BLOCKING,
            'Fees are still owed to the school.',
            'Closing with live receivables strands the amount: after CLOSED no ordinary collection is possible.',
            'Collect, waive or write off each demand in Fees before closing.');

        // ── Money owed BY the school ──────────────────────────────────
        $items[] = $this->_countItem('fees.refunds', 'Fees',
            'Pending refunds', 'feeRefunds',
            array_merge($where, [['status', '=', 'pending']]), self::BLOCKING,
            'Refunds are approved but not yet paid out.',
            'Money owed to a family must leave before the school stops operating.',
            'Complete or cancel each refund in Fees → Refunds.');

        // ── Accounting integrity ──────────────────────────────────────
        $items[] = $this->_countItem('accounting.unposted', 'Accounting',
            'Unposted accounting entries', 'accountingLedger',
            array_merge($where, [['status', '=', 'pending']]), self::BLOCKING,
            'Journal entries are staged but not posted.',
            'An unposted entry is a transaction the books do not yet show; the final position would be wrong.',
            'Post or reverse them in Accounting, or run the reconciler.');

        // ── Staff ─────────────────────────────────────────────────────
        $items[] = $this->_countItem('staff.separations', 'Staff',
            'Staff separations in progress', 'staffSeparations',
            array_merge($where, [['status', '=', 'pending']]), self::BLOCKING,
            'Staff separations are started but not completed.',
            'Final settlement is a legal obligation; it must not be left half-finished by a closure.',
            'Complete each separation and its final settlement in HR.');

        // ── Transport safety ──────────────────────────────────────────
        $items[] = $this->_countItem('transport.active_trips', 'Transport',
            'Trips currently running', 'tripLogs',
            array_merge($where, [['status', '=', 'in_progress']]), self::BLOCKING,
            'One or more buses are on the road right now.',
            'A trip in progress carries children. Closure must never land mid-route.',
            'Wait for the trips to complete — this clears itself.');

        $items[] = $this->_countItem('transport.assignments', 'Transport',
            'Active student transport assignments', 'studentRoutes',
            array_merge($where, [['status', '=', 'active']]), self::WARNING,
            'Students are still assigned to routes.',
            'Families need notice that transport is ending, and any advance transport fee may be refundable.',
            'Release the assignments in Transport once the last operating day is known.');

        return $this->_summarise($schoolId, $session, $items);
    }

    // ═════════════════════════════════════════════════════════════════

    private function _countItem(string $key, string $domain, string $label, string $collection,
                                array $conditions, string $severityIfAny,
                                string $what, string $why, string $fix): array
    {
        $n = $this->_count($collection, $conditions);

        if ($n < 0) {
            return $this->_item($key, $domain, $label, self::UNKNOWN, -1,
                "Could not read {$collection} — this item is UNRESOLVED, not clear.",
                $why,
                'Retry the checklist. A count that cannot be read must never be treated as zero.');
        }
        if ($n === 0) {
            return $this->_item($key, $domain, $label, self::CLEAR, 0,
                'Nothing outstanding.', $why, '');
        }
        return $this->_item($key, $domain, $label, $severityIfAny, $n, $what, $why, $fix);
    }

    private function _item(string $key, string $domain, string $label, string $status,
                           int $count, string $what, string $why, string $fix): array
    {
        return [
            'key' => $key, 'domain' => $domain, 'label' => $label,
            'status' => $status, 'count' => $count,
            'what' => $what, 'why' => $why, 'fix' => $fix,
        ];
    }

    private function _count(string $collection, array $conditions): int
    {
        try {
            if (!method_exists($this->firebase, 'firestoreCount')) return -1;
            return (int) $this->firebase->firestoreCount($collection, $conditions);
        } catch (\Throwable $e) {
            log_message('error', "Institution_closure: count failed {$collection}: " . $e->getMessage());
            return -1;
        }
    }

    private function _summarise(string $schoolId, string $session, array $items): array
    {
        $blocking = 0; $warnings = 0; $unknown = 0;
        foreach ($items as $i) {
            if ($i['status'] === self::BLOCKING) $blocking++;
            elseif ($i['status'] === self::WARNING) $warnings++;
            elseif ($i['status'] === self::UNKNOWN) $unknown++;
        }

        // UNKNOWN counts against closing exactly as BLOCKING does. Closure is
        // effectively irreversible in operational terms, so "we could not
        // check" must never read as "there was nothing to check".
        $closeAllowed = ($blocking === 0 && $unknown === 0);

        return [
            'schoolId'         => $schoolId,
            'session'          => $session,
            'blocking'         => $blocking,
            'warnings'         => $warnings,
            'unknown'          => $unknown,
            'closeAllowed'     => $closeAllowed,
            'requiresOverride' => !$closeAllowed,
            'items'            => $items,
        ];
    }
}
