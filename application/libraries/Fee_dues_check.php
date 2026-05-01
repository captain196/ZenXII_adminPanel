<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_dues_check — canonical dues-blocking helper.
 *
 * Single source of truth for "can this student access X right now?"
 * questions across the admin panel, parent app, and teacher app. All
 * dues data is read from the Firestore `feeDefaulters` collection; the
 * per-school blocking policy is stored in
 *   `feeSettings/{schoolId}_{session}_blocking_policy`
 *
 * Policy document shape:
 *   {
 *     block_result:       bool,
 *     block_tc:           bool,
 *     block_hall_ticket:  bool,
 *     block_library:      bool,
 *     threshold_amount:   number,  // dues above this trigger blocking (default 0)
 *     admin_override_allowed: bool
 *   }
 *
 * Usage (controllers):
 *   $this->load->library('Fee_dues_check', null, 'duesCheck');
 *   $this->duesCheck->init($this->firebase, $this->school_name, $this->session_year);
 *   $verdict = $this->duesCheck->check($studentId, 'result');
 *   if ($verdict['blocked']) {
 *       $this->json_error($verdict['message'], 403);
 *   }
 *
 * NO RTDB — all reads are Firestore, per the absolute project rule.
 */
class Fee_dues_check
{
    /** @var object */ private $firebase;
    /** @var string */ private $schoolId;
    /** @var string */ private $session;
    /** @var bool   */ private $ready = false;

    /** @var array|null Cached policy so repeated check() calls in one
     *  request don't re-hit Firestore for each action. */
    private $policyCache = null;

    /** @var array studentId → defaulter doc cache (request-scope). */
    private $defaulterCache = [];

    private const POLICY_DEFAULT = [
        'block_result'           => false,
        'block_tc'               => false,
        'block_hall_ticket'      => false,
        'block_library'          => false,
        'threshold_amount'       => 0.0,
        'admin_override_allowed' => true,
    ];

    /** Actions a caller can ask about. Extend here if you add more. */
    public const ACTIONS = ['result', 'tc', 'hall_ticket', 'library'];

    public function init($firebase, string $schoolId, string $session): self
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
        $this->policyCache    = null;
        $this->defaulterCache = [];
        return $this;
    }

    /** Read (and cache) the school+session blocking policy. */
    public function getPolicy(): array
    {
        if ($this->policyCache !== null) return $this->policyCache;
        if (!$this->ready) return self::POLICY_DEFAULT;

        try {
            $doc = $this->firebase->firestoreGet(
                'feeSettings',
                "{$this->schoolId}_{$this->session}_blocking_policy"
            );
            if (is_array($doc)) {
                // Merge over defaults so missing fields fall back to false/0.
                $this->policyCache = array_merge(self::POLICY_DEFAULT, array_intersect_key(
                    $doc,
                    self::POLICY_DEFAULT
                ));
                // Coerce bools/numbers defensively — Firestore may give us
                // strings ("true"/"0") from legacy writers.
                foreach (['block_result','block_tc','block_hall_ticket','block_library','admin_override_allowed'] as $k) {
                    $this->policyCache[$k] = $this->toBool($this->policyCache[$k]);
                }
                $this->policyCache['threshold_amount'] = (float) $this->policyCache['threshold_amount'];
                return $this->policyCache;
            }
        } catch (\Exception $e) {
            log_message('warning', 'Fee_dues_check::getPolicy failed: ' . $e->getMessage());
        }
        $this->policyCache = self::POLICY_DEFAULT;
        return $this->policyCache;
    }

    public function savePolicy(array $policy): bool
    {
        if (!$this->ready) return false;
        $payload = array_merge(self::POLICY_DEFAULT, array_intersect_key($policy, self::POLICY_DEFAULT));
        $payload['schoolId']  = $this->schoolId;
        $payload['session']   = $this->session;
        $payload['updatedAt'] = date('c');
        try {
            $ok = (bool) $this->firebase->firestoreSet(
                'feeSettings',
                "{$this->schoolId}_{$this->session}_blocking_policy",
                $payload,
                /* merge */ true
            );
            if ($ok) $this->policyCache = $payload;
            return $ok;
        } catch (\Exception $e) {
            log_message('error', 'Fee_dues_check::savePolicy failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Read (and cache) the student's current defaulter state.
     * @return array|null { totalBalance, unpaidMonthCount, oldestUnpaid, status }
     */
    public function getDues(string $studentId): ?array
    {
        if ($studentId === '' || !$this->ready) return null;
        if (array_key_exists($studentId, $this->defaulterCache)) {
            return $this->defaulterCache[$studentId];
        }
        try {
            $doc = $this->firebase->firestoreGet(
                'feeDefaulters',
                "{$this->schoolId}_{$this->session}_{$studentId}"
            );
            // Field names follow Fee_firestore_sync::syncDefaulterStatus (canonical writer):
            //   totalDues, unpaidMonths[], overdueMonths[], lastPaymentDate, flaggedAt
            // The doc only exists when the student IS a defaulter (writer deletes
            // it otherwise) — so a present doc implies isDefaulter=true.
            if (is_array($doc)) {
                $unpaidArr = is_array($doc['unpaidMonths'] ?? null) ? $doc['unpaidMonths'] : [];
                $totalDues = (float) ($doc['totalDues'] ?? $doc['totalBalance'] ?? $doc['balance'] ?? 0);
                $result = [
                    'totalBalance'     => $totalDues,
                    'unpaidMonthCount' => count($unpaidArr),
                    'oldestUnpaid'     => (string) ($unpaidArr[0] ?? $doc['oldestUnpaid'] ?? ''),
                    'daysOverdue'      => (int)   ($doc['daysOverdue'] ?? 0),
                    'isDefaulter'      => true,
                ];
            } else {
                $result = null;
            }
            $this->defaulterCache[$studentId] = $result;
            return $result;
        } catch (\Exception $e) {
            log_message('warning', "Fee_dues_check::getDues({$studentId}) failed: " . $e->getMessage());
            $this->defaulterCache[$studentId] = null;
            return null;
        }
    }

    /**
     * Core decision. Returns:
     *   [
     *     'blocked'      => bool,
     *     'reason'       => string,       // machine-readable: 'policy_off' | 'no_dues' | 'below_threshold' | 'overridden' | 'dues_exceed'
     *     'message'      => string,       // human-readable for UI/API
     *     'total_dues'   => float,
     *     'threshold'    => float,
     *     'can_override' => bool,
     *     'policy'       => array         // the loaded policy
     *   ]
     *
     * @param string $action one of self::ACTIONS
     * @param bool   $override true if an admin requested an override
     */
    public function check(string $studentId, string $action, bool $override = false): array
    {
        $policy    = $this->getPolicy();
        $policyKey = 'block_' . $action;
        $base = [
            'blocked'      => false,
            'reason'       => '',
            'message'      => '',
            'total_dues'   => 0.0,
            'threshold'    => (float) $policy['threshold_amount'],
            'can_override' => (bool)  $policy['admin_override_allowed'],
            'policy'       => $policy,
        ];

        // Unknown action — never block.
        if (!in_array($action, self::ACTIONS, true)) {
            return array_merge($base, ['reason' => 'unknown_action']);
        }
        // Policy toggle is off for this action.
        if (empty($policy[$policyKey])) {
            return array_merge($base, ['reason' => 'policy_off']);
        }

        $dues = $this->getDues($studentId);
        $balance = $dues ? (float) $dues['totalBalance'] : 0.0;

        $base['total_dues'] = $balance;

        if ($balance <= 0.005) {
            return array_merge($base, ['reason' => 'no_dues']);
        }
        if ($balance <= (float) $policy['threshold_amount']) {
            return array_merge($base, ['reason' => 'below_threshold']);
        }

        // Blocked by default; the override is only respected if the
        // policy allows it.
        if ($override && !empty($policy['admin_override_allowed'])) {
            return array_merge($base, [
                'reason'  => 'overridden',
                'message' => 'Admin override acknowledged — outstanding dues: Rs. ' . number_format($balance, 2),
            ]);
        }

        $label = ucwords(str_replace('_', ' ', $action)); // "Hall Ticket", "Tc" → fix TC
        if ($action === 'tc') $label = 'Transfer Certificate';
        $msg = "{$label} withheld — outstanding fees of Rs. " . number_format($balance, 2)
             . ($dues && !empty($dues['oldestUnpaid']) ? " (earliest due: {$dues['oldestUnpaid']})" : '')
             . '. Please clear pending fees to proceed.';

        return array_merge($base, [
            'blocked' => true,
            'reason'  => 'dues_exceed',
            'message' => $msg,
        ]);
    }

    /**
     * Convenience — run the check and return true to proceed, false to block.
     * Callers that want a JSON response can use check() instead.
     */
    public function allow(string $studentId, string $action, bool $override = false): bool
    {
        return empty($this->check($studentId, $action, $override)['blocked']);
    }

    private function toBool($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((float) $v) != 0.0;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['true','yes','1','on'], true);
    }
}
