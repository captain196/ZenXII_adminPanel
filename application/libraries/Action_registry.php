<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Action_registry — Student 360 renders actions; it does not contain them.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THE PRINCIPLE (baseline §Student 360 Readiness Review §9)
 * ─────────────────────────────────────────────────────────────────────────
 *  Student 360 is an ORCHESTRATION surface. Every action a user can take on a
 *  student already has exactly one canonical implementation somewhere in the
 *  ERP. Student 360 must invoke that implementation — never re-implement,
 *  never fork, never "just this once" inline a rule.
 *
 *  This registry is the contract that makes that enforceable. Each entry is an
 *  ActionDescriptor: WHAT the action is, WHO may perform it, WHEN it is
 *  available, and WHERE it is implemented. Student 360 reads descriptors and
 *  renders. It never branches on module.
 *
 *  Adding a module to Student 360 = adding rows here. No Student 360 code
 *  changes. That is the test of whether this abstraction is real, and it is
 *  the reason the registry is data rather than a switch statement.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  DESCRIPTOR SHAPE
 * ─────────────────────────────────────────────────────────────────────────
 *   actionId    string   stable machine id
 *   displayName string   what the operator reads
 *   icon        string   single glyph
 *   category    string   grouping in the rail
 *   permission  [module, level]   graded RBAC, evaluated via has_permission()
 *   service     string   the canonical implementation (documentation + audit)
 *   route       string|null  where the UI sends the user/request
 *   target      'page'|'panel'   see below
 *   panel       string|null  DOM id of the in-page panel when target='panel'
 *   workflow    'confirm'|'form'|'reason'
 *   priority    int      lower sorts first
 *   precondition callable(array $ctx): ?string   null = available,
 *                string = the reason it is unavailable (shown to the operator)
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  TWO RULES THAT MUST NOT BE RELAXED
 * ─────────────────────────────────────────────────────────────────────────
 *  1. **Preconditions receive the pre-built Student_context and may NOT read
 *     Firestore.** They are pure functions of the snapshot. Allowing a read
 *     here turns the action rail into an N+1 fan-out that worsens with every
 *     row added — the exact regression Student_context exists to prevent.
 *
 *  2. **This registry decides nothing about authorization.** It declares the
 *     capability an action requires so the UI can avoid rendering impossible
 *     buttons. The SERVER-SIDE service performs the real check on execution.
 *     A hidden button is a courtesy, never a security boundary.
 *
 *     Note the module keys are exact strings from `functions/rbac_modules.json`
 *     — `SIS`, `Fees`, `Transport`, `Certificates`. `has_permission()` compares
 *     with `in_array(..., true)`, so a near-miss like `Sis` does not fail
 *     loudly; it silently denies every non-bypass role.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  ON `service` VS `route`
 * ─────────────────────────────────────────────────────────────────────────
 *  Where a domain facade exists (Entitlement_service), `service` names it and
 *  Student 360 can dispatch straight to it. Where the canonical implementation
 *  still lives behind a controller (TC issuance, fee collection), `route`
 *  points at that controller and `service` records where the logic actually
 *  is. Student 360 hands the user off; it does not reach past the owner.
 *
 *  `Student_service` and `Fee_service` facades do not exist yet — the frozen
 *  baseline lists both as pre-Student-360 items that were never authorized.
 *  Those rows therefore route to the existing controllers TODAY, and will be
 *  repointed at the facades when they are built. **No logic was duplicated to
 *  work around their absence**, which is the point.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  ON `target` — WHY EVERY ACTION IS NOT A LINK
 * ─────────────────────────────────────────────────────────────────────────
 *  `target: 'page'` means the action is completed on another module's SCREEN,
 *  and the rail renders a link to it.
 *
 *  `target: 'panel'` means the action is completed inside a panel of this
 *  workspace, and the rail focuses that panel instead of navigating.
 *
 *  The distinction is load-bearing, not cosmetic. The Entitlements controller
 *  is pure JSON — it has no `load->view()` at all — so its routes are
 *  endpoints, not destinations. Rendering them as links would drop the
 *  operator on a bare `{"status":"error","message":"POST required."}`. On a
 *  panel action `route` therefore records the ENDPOINT its panel calls, and is
 *  never used as an href.
 *
 *  The rail branches on this declared field — never on module or actionId.
 */
class Action_registry
{
    /**
     * The declared surface. Ordered by `priority` at resolve time, not here.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function descriptors(): array
    {
        return [
            // ── Fees ──────────────────────────────────────────────────────
            [
                'actionId'    => 'collect_payment',
                'displayName' => 'Collect Payment',
                'icon'        => '₹',
                'category'    => 'Money',
                'permission'  => ['Fees', 'edit'],
                'service'     => 'Fees::fees_counter()',
                'route'       => 'fees/fees_counter?uid={studentId}',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'form',
                'priority'    => 10,
                'precondition'=> function (array $c): ?string {
                    return !empty($c['fees']['hasDues']) ? null : 'Nothing outstanding';
                },
            ],
            [
                'actionId'    => 'add_entitlement',
                'displayName' => 'Add Concession / Service',
                'icon'        => '%',
                'category'    => 'Money',
                'permission'  => ['Fees', 'edit'],
                'service'     => 'Entitlement_service::propose()',
                'route'       => 'entitlements/propose',
                'target'      => 'panel',
                'panel'       => 's360p-ent',
                'workflow'    => 'form',
                'priority'    => 20,
                'precondition'=> function (array $c): ?string {
                    return !empty($c['isActive']) ? null : 'Student is not active';
                },
            ],
            [
                'actionId'    => 'approve_entitlement',
                'displayName' => 'Approve Entitlement',
                'icon'        => '✓',
                'category'    => 'Money',
                // manage, deliberately higher than capture — a clerk who can
                // propose must not be able to grant. Mirrors Entitlements ctrl.
                'permission'  => ['Fees', 'manage'],
                'service'     => 'Entitlement_service::approve()',
                'route'       => 'entitlements/approve',
                'target'      => 'panel',
                'panel'       => 's360p-ent',
                'workflow'    => 'confirm',
                'priority'    => 30,
                'precondition'=> function (array $c): ?string {
                    return ((int) ($c['entitlements']['pendingCount'] ?? 0)) > 0
                        ? null : 'No pending requests';
                },
            ],
            [
                'actionId'    => 'discontinue_entitlement',
                'displayName' => 'Discontinue Entitlement',
                'icon'        => '⊘',
                'category'    => 'Money',
                'permission'  => ['Fees', 'manage'],
                'service'     => 'Entitlement_service::discontinue()',
                'route'       => 'entitlements/discontinue',
                'target'      => 'panel',
                'panel'       => 's360p-ent',
                'workflow'    => 'reason',
                'priority'    => 40,
                'precondition'=> function (array $c): ?string {
                    return ((int) ($c['entitlements']['activeCount'] ?? 0)) > 0
                        ? null : 'No active entitlements';
                },
            ],
            [
                'actionId'    => 'preview_fees',
                'displayName' => 'Preview Fees',
                'icon'        => '⊞',
                'category'    => 'Money',
                'permission'  => ['Fees', 'edit'],
                'service'     => 'Fee_generation_service (read-only, SE-001)',
                'route'       => 'entitlements/preview',
                'target'      => 'panel',
                'panel'       => 's360p-ent',
                'workflow'    => 'confirm',
                'priority'    => 50,
                'precondition'=> function (array $c): ?string { return null; },
            ],

            // ── Transport ─────────────────────────────────────────────────
            [
                'actionId'    => 'suspend_transport',
                'displayName' => 'Suspend Transport',
                'icon'        => '⏸',
                'category'    => 'Services',
                'permission'  => ['Transport', 'edit'],
                'service'     => 'Transport_service::suspendAssignment()',
                // Points at the assignments PAGE, not the POST endpoint that
                // performs the suspension. Suspension needs a reason and a date
                // window, so the handoff is to the screen that collects them —
                // a link straight at the endpoint would GET a POST-only route.
                'route'       => 'transport/assignments',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'reason',
                'priority'    => 60,
                'precondition'=> function (array $c): ?string {
                    if (empty($c['transport']['available']))  return 'Transport unavailable';
                    if (empty($c['transport']['assigned']))   return 'No transport assigned';
                    if (!empty($c['transport']['suspended'])) return 'Already suspended';
                    return null;
                },
            ],
            [
                'actionId'    => 'resume_transport',
                'displayName' => 'Resume Transport',
                'icon'        => '▶',
                'category'    => 'Services',
                'permission'  => ['Transport', 'edit'],
                'service'     => 'Transport_service::resumeAssignment()',
                'route'       => 'transport/assignments',   // page, not the POST endpoint
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'confirm',
                'priority'    => 70,
                'precondition'=> function (array $c): ?string {
                    if (empty($c['transport']['available'])) return 'Transport unavailable';
                    return !empty($c['transport']['suspended']) ? null : 'Not suspended';
                },
            ],
            [
                'actionId'    => 'change_route',
                'displayName' => 'Change Route / Stop',
                'icon'        => '⇄',
                'category'    => 'Services',
                'permission'  => ['Transport', 'manage'],
                'service'     => 'Transport_service::assignStudent()',
                'route'       => 'transport/assignments',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'form',
                'priority'    => 80,
                'precondition'=> function (array $c): ?string {
                    if (empty($c['transport']['available'])) return 'Transport unavailable';
                    return !empty($c['transport']['assigned']) ? null : 'No transport assigned';
                },
            ],

            // ── Hostel ────────────────────────────────────────────────────
            [
                'actionId'    => 'manage_hostel',
                'displayName' => 'Manage Hostel Allocation',
                'icon'        => '⌂',
                'category'    => 'Services',
                'permission'  => ['Hostel', 'edit'],
                'service'     => 'Hostel::allocate() / Hostel::checkout()',
                'route'       => 'hostel',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'form',
                'priority'    => 85,
                // Hostel state is NOT in the eager context — the panel is lazy.
                // A precondition may only read the snapshot, so this action
                // cannot gate on "is allocated" without reintroducing the very
                // N+1 the snapshot exists to prevent. It therefore gates only
                // on what the snapshot knows, and the Hostel screen itself
                // shows the operator the current allocation.
                'precondition'=> function (array $c): ?string {
                    return !empty($c['isActive']) ? null : 'Student is not active';
                },
            ],

            // ── Records ───────────────────────────────────────────────────
            [
                'actionId'    => 'mark_attendance',
                'displayName' => 'Open Attendance',
                'icon'        => '◷',
                'category'    => 'Academics',
                'permission'  => ['Attendance', 'view'],
                'service'     => 'Attendance::index() (read-only handoff)',
                'route'       => 'attendance',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'confirm',
                'priority'    => 95,
                'precondition'=> function (array $c): ?string { return null; },
            ],
            [
                'actionId'    => 'manage_documents',
                'displayName' => 'Manage Documents',
                'icon'        => '🗀',
                'category'    => 'Records',
                'permission'  => ['SIS', 'edit'],
                // The canonical Document Service is a FROZEN PLATFORM BASELINE;
                // Sis owns the student-facing upload/verify/replace screen and
                // consumes that service. Student 360 hands off to that screen
                // and never touches document lifecycle itself.
                'service'     => 'Sis::documents() → Document_service',
                'route'       => 'sis/documents/{studentId}',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'form',
                'priority'    => 97,
                'precondition'=> function (array $c): ?string { return null; },
            ],

            // ── Lifecycle ─────────────────────────────────────────────────
            [
                'actionId'    => 'issue_tc',
                'displayName' => 'Issue Transfer Certificate',
                'icon'        => '⎋',
                'category'    => 'Records',
                'permission'  => ['Certificates', 'edit'],
                // Canonical implementation is still controller-bound; the
                // baseline's Student_service facade was never authorized, so
                // this hands off to the Certificates module that owns TC
                // issuance today, and is repointed at the facade once it exists.
                'service'     => 'Certificates::generate_certificate()  [pending Student_service facade]',
                'route'       => 'certificates/generate',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'reason',
                'priority'    => 90,
                'precondition'=> function (array $c): ?string {
                    if (empty($c['isActive']))         return 'Student is not active';
                    if (!empty($c['fees']['hasDues'])) return 'Outstanding dues must be cleared';
                    return null;
                },
            ],

            // ── Academic ──────────────────────────────────────────────────
            [
                'actionId'    => 'open_result',
                'displayName' => 'Open Full Result',
                'icon'        => '◈',
                'category'    => 'Academics',
                // Results, not Examinations — reading a child's marks is a
                // different capability from setting up an exam.
                'permission'  => ['Results', 'view'],
                'service'     => 'Result::student_result() (read-only)',
                // Carries the student through, so the operator does not have to
                // find them again on arrival.
                'route'       => 'result/student_result/{studentId}',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'confirm',
                'priority'    => 92,
                'precondition'=> function (array $c): ?string { return null; },
            ],
            [
                'actionId'    => 'open_discipline',
                'displayName' => 'Open Red Flags',
                'icon'        => '⚑',
                'category'    => 'Student & Family',
                'permission'  => ['Red Flags', 'view'],
                'service'     => 'Red_flags::index() (module surface)',
                // The module has no per-student page route — only a JSON
                // endpoint — so this lands on the dashboard. Recorded rather
                // than faked with a URL that does not exist.
                'route'       => 'red_flags',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'confirm',
                'priority'    => 94,
                'precondition'=> function (array $c): ?string { return null; },
            ],
            [
                'actionId'    => 'view_receipts',
                'displayName' => 'Payment History',
                'icon'        => '❐',
                'category'    => 'Money',
                'permission'  => ['Fees', 'view'],
                'service'     => 'Fee_firestore_txn::listReceiptsForStudent() (read-only)',
                'route'       => null,
                'target'      => 'panel',
                'panel'       => 's360p-rcpt',
                'workflow'    => 'confirm',
                'priority'    => 98,
                'precondition'=> function (array $c): ?string { return null; },
            ],
            [
                'actionId'    => 'view_ledger',
                'displayName' => 'Open Fee Ledger',
                'icon'        => '☰',
                'category'    => 'Money',
                'permission'  => ['Fees', 'view'],
                'service'     => 'Fees::student_ledger() (read-only)',
                'route'       => 'fees/student_ledger?uid={studentId}',
                'target'      => 'page',
                'panel'       => null,
                'workflow'    => 'confirm',
                'priority'    => 100,
                'precondition'=> function (array $c): ?string { return null; },
            ],
        ];
    }

    /**
     * Resolve the registry against one student's pre-built context.
     *
     * Two distinct outcomes, deliberately NOT collapsed into one:
     *   • not permitted  → omitted entirely (the operator never sees it)
     *   • permitted but blocked → returned with `blockedReason` set
     *
     * Showing a disabled action WITH its reason teaches the operator what the
     * system needs ("clear the dues first"). Showing an action they can never
     * perform only advertises someone else's power. Hence the split.
     *
     * Instance method, not static, so it is loaded and called the CI way
     * (`$this->load->library('Action_registry')`). A static call would fatal
     * unless something else happened to have loaded the file first — a failure
     * mode that shows up only on the one route that forgot.
     *
     * @param array $ctx Student_context::build() output
     * @return array{available: array, blocked: array, hiddenCount: int}
     */
    public function resolveFor(array $ctx): array
    {
        $available = [];
        $blocked   = [];
        $hidden    = 0;

        foreach (self::descriptors() as $d) {
            [$mod, $lvl] = $d['permission'];

            // Authorization: the SAME graded helper the controllers use. The
            // registry never invents its own rule.
            $permitted = function_exists('has_permission') ? has_permission($mod, $lvl) : false;
            if (!$permitted) { $hidden++; continue; }

            // Precondition: pure function of the snapshot. No reads.
            $reason = null;
            try {
                $pre = $d['precondition'] ?? null;
                if (is_callable($pre)) $reason = $pre($ctx);
            } catch (\Throwable $e) {
                // A broken precondition disables its action; it must never take
                // down the workspace or, worse, silently enable something that
                // should be blocked. Fail CLOSED.
                log_message('error', 'Action_registry: precondition failed for '
                    . ($d['actionId'] ?? '?') . ': ' . $e->getMessage());
                $reason = 'Unavailable';
            }

            $row = [
                'actionId'      => $d['actionId'],
                'displayName'   => $d['displayName'],
                'icon'          => $d['icon'],
                'category'      => $d['category'],
                'service'       => $d['service'],
                'route'         => self::_route($d['route'] ?? null, $ctx),
                'target'        => $d['target'] ?? 'page',
                'panel'         => $d['panel'] ?? null,
                'workflow'      => $d['workflow'],
                'priority'      => (int) $d['priority'],
                'permission'    => $mod . ':' . $lvl,
                'blockedReason' => $reason,
            ];
            if ($reason === null) $available[] = $row; else $blocked[] = $row;
        }

        $sort = function (array $a, array $b) { return $a['priority'] <=> $b['priority']; };
        usort($available, $sort);
        usort($blocked,   $sort);

        return ['available' => $available, 'blocked' => $blocked, 'hiddenCount' => $hidden];
    }

    /** Interpolate {studentId} into a declared route. */
    private static function _route(?string $route, array $ctx): ?string
    {
        if ($route === null || $route === '') return null;
        return str_replace('{studentId}', rawurlencode((string) ($ctx['studentId'] ?? '')), $route);
    }
}
