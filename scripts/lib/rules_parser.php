<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SHARED FIRESTORE RULES PARSER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * One parser, adversarially tested once, used by every census tool. Previously
 * each script re-implemented block extraction and each re-implementation had
 * its own blind spot.
 *
 * Proven against `tests/fixtures/rules/adversarial_parser.rules` by
 * `tests/Unit/RulesParserAdversarialTest.php`, which covers: single-line
 * blocks, nested blocks, duplicate allow statements, inline braces in
 * conditions, repeated predicates, comments containing rule-like syntax,
 * per-operation arms, and already-fixed shapes.
 *
 * ── THE THREE DESIGN RULES ────────────────────────────────────────────────
 *
 * 1. BLOCKS ARE DELIMITED BY BRACE DEPTH, NEVER BY A LINE PATTERN.
 *    The original extractor used `(.*?)\n    \}`, which assumes every block
 *    ends on its own line. A single-line block
 *        match /x/{d}  { allow read, write: if false; }
 *    made the scan run past its own inline `}` into the NEXT block, attributing
 *    that block's arms to this collection. Two false positives and two false
 *    negatives then cancelled in the totals, hiding `feeStructures` — the fee
 *    chart — as cross-tenant writable.
 *
 * 2. HELPERS ARE INLINED BEFORE ANYTHING IS CLASSIFIED.
 *    Classifying by helper NAME reads `isStaffOrOwnStudent()` as "staff or
 *    student, looks scoped". Inlined it is
 *        isStaff() || (isSameSchool() && isOwnStudent())
 *    and `||` short-circuits past the tenant check. That is SEC-4. A parser
 *    that does not expand helpers cannot see it.
 *
 * 3. THE EFFECTIVE BOUNDARY OF AN OPERATION IS ITS WEAKEST ARM.
 *    Firestore permits when ANY matching allow rule passes. Reporting arms
 *    independently describes the rules; resolving them by weakest describes the
 *    authorization.
 */

class RulesParser
{
    /** Weakest-last ordering. Higher rank = weaker = the one that decides. */
    const CLASS_ORDER = [
        'deny'              => 0,
        'platform-only'     => 1,
        'rbac-tenant'       => 2,
        'student-self'      => 3,
        'owner-scoped'      => 4,
        'staff-own-tenant'  => 5,
        'tenant-scoped'     => 6,
        'staff-any-tenant'  => 7,   // SEC-4
        'auth-any-tenant'   => 8,
        'public'            => 9,
        'investigate'       => 10,  // unknown is treated as weakest on purpose

        // Behaviourally a DENY, but a defective one: the arm dereferences an
        // object that does not exist for this operation, so Firestore raises an
        // evaluation error and refuses. Ranked alongside `deny` because it does
        // NOT open anything — it is a FUNCTIONAL defect (an intended, legitimate
        // operation is broken), not a security exposure.
        //
        // MEASURED, not inferred: sec5_authz_graph_findings.test.js shows a
        // same-school staff delete on `academicAuditLog`
        // (`isStaff() && isSameSchoolWrite()`) is refused. An earlier revision
        // classed this `staff-any-tenant`, i.e. wide open — the exact opposite
        // of what production does.
        'null-deref-denies' => 0,
    ];

    /** Classes that must be triaged. `investigate` counts: unclassified = unaudited. */
    const FINDING_CLASSES = ['staff-any-tenant', 'auth-any-tenant', 'public', 'investigate'];

    private $raw;
    private $clean;
    private $helpers = [];

    public function __construct($src)
    {
        $this->raw = $src;
        // Strip line comments FIRST so prose mentioning a helper is never read
        // as a check, and a commented-out `match` is never parsed as a block.
        $this->clean = preg_replace('!//[^\n]*!', '', $src);
        $this->extractHelpers();
    }

    public static function isFinding($cls)
    {
        return in_array($cls, self::FINDING_CLASSES, true);
    }

    /** `function name(args) { return <expr>; }` */
    private function extractHelpers()
    {
        if (!preg_match_all(
            '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)\s*\{\s*return\s+(.*?);\s*\}/s',
            $this->clean, $m, PREG_SET_ORDER
        )) {
            return;
        }
        foreach ($m as $h) {
            $this->helpers[$h[1]] = preg_replace('/\s+/', ' ', trim($h[3]));
        }
    }

    public function helpers() { return $this->helpers; }

    /**
     * Inline helper calls transitively.
     *
     * Arguments are NOT substituted — the classifier only needs to see which
     * PREDICATES are reachable, not evaluate them, and a helper's body already
     * names the fields it compares. The depth cap stops mutual recursion.
     */
    public function inline($cond, $depth = 0)
    {
        if ($depth > 6) return $cond;
        $out = $cond;
        foreach ($this->helpers as $name => $body) {
            if (strpos($out, $name . '(') === false) continue;
            $out = preg_replace(
                '/\b' . preg_quote($name, '/') . '\s*\([^()]*\)/',
                '( ' . $body . ' )',
                $out
            );
        }
        return $out === $cond ? $out : $this->inline($out, $depth + 1);
    }

    /**
     * Every `match /<collection>/{var} { ... }`, delimited by BRACE DEPTH.
     * Scanning resumes INSIDE each body so nested blocks are found on their own
     * pass rather than hidden by their parent.
     */
    public function blocks()
    {
        $blocks = [];
        $len = strlen($this->clean);
        $off = 0;
        while (preg_match(
            '/match\s+\/([A-Za-z_][A-Za-z0-9_]*)\/\{([^}]*)\}\s*\{/',
            $this->clean, $m, PREG_OFFSET_CAPTURE, $off
        )) {
            $bodyFrom = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $bodyFrom;
            while ($i < $len && $depth > 0) {
                $ch = $this->clean[$i];
                if ($ch === '{')      $depth++;
                elseif ($ch === '}')  $depth--;
                $i++;
            }
            if ($depth !== 0) {
                throw new RuntimeException("unbalanced braces after match /{$m[1][0]}/");
            }
            $blocks[] = [
                'collection' => $m[1][0],
                'var'        => trim($m[2][0]),
                'body'       => substr($this->clean, $bodyFrom, $i - 1 - $bodyFrom),
            ];
            $off = $m[0][1] + strlen($m[0][0]);
        }
        return $blocks;
    }

    /** Remove nested-match bodies so a child's arms are not counted against its parent. */
    public static function stripNested($body)
    {
        $out = '';
        $depth = 0;
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $ch = $body[$i];
            if ($ch === '{') { $depth++; continue; }
            if ($ch === '}') { $depth--; continue; }
            if ($depth === 0) $out .= $ch;
        }
        return $out;
    }

    /**
     * Every allow arm, with `read`/`write` expanded to concrete operations.
     * DUPLICATE arms are all retained — Firestore ORs them, so dropping one
     * would report a boundary the engine does not enforce.
     */
    public function arms()
    {
        $rows = [];
        foreach ($this->blocks() as $b) {
            $body = self::stripNested($b['body']);
            if (!preg_match_all('/allow\s+([a-z, ]*?)\s*:\s*if\s+(.*?);/s', $body, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $a) {
                $verbs = array_values(array_filter(array_map('trim', explode(',', $a[1]))));
                $cond  = preg_replace('/\s+/', ' ', trim($a[2]));

                $ops = [];
                foreach ($verbs as $v) {
                    if ($v === 'read')       { $ops[] = 'get'; $ops[] = 'list'; }
                    elseif ($v === 'write')  { $ops[] = 'create'; $ops[] = 'update'; $ops[] = 'delete'; }
                    elseif ($v !== '')       { $ops[] = $v; }
                }
                foreach (array_unique($ops) as $op) {
                    $rows[] = [
                        'collection' => $b['collection'],
                        'var'        => $b['var'],
                        'operation'  => $op,
                        'condition'  => $cond,
                        'inlined'    => $this->inline($cond),
                        'class'      => $this->classify($cond, $op, $b['var']),
                    ];
                }
            }
        }
        return $rows;
    }

    /**
     * Classify ONE arm by what its INLINED condition actually reaches.
     *
     * The `||` short-circuit is the whole point: if a role predicate appears as
     * a top-level disjunct with no document-school comparison inside that same
     * disjunct, the tenant check can be bypassed however well-scoped the other
     * branches are.
     */
    /**
     * A document-school comparison, and whether it BINDS for this operation.
     *
     * This is operation-aware on purpose, because the two document handles are
     * not both available on every operation:
     *
     *   create : `resource` is null            -> `resource.data.schoolId` does NOT bind
     *   delete : `request.resource` is null    -> `request.resource.data.schoolId` does NOT bind
     *   update : both exist
     *   get/list: only `resource`
     *
     * So `allow write: if isAdmin() && isSameSchoolWrite();` is tenant-bound on
     * create and update but, on DELETE, dereferences a null and denies
     * everything. And `allow write: if isAdmin() && isSameSchool();` is
     * tenant-bound on update/delete but, on CREATE, `resource == null` is
     * trivially TRUE and the whole tenant check evaporates — leaving bare
     * `isAdmin()`. Judging the arm as a whole hides both.
     */
    private static function tenantBinds($expr, $op)
    {
        $usesIncoming = (bool) preg_match('/request\.resource\.data\s*(\.|\[)\s*[\'"]?school(Id|Code)/i', $expr);
        $stored = preg_replace('/request\.resource\.data/', 'REQRES', $expr);
        $usesStored   = (bool) preg_match('/(?<!REQ)resource\.data\s*(\.|\[)?\s*(get\(\s*)?[\'"]?school(Id|Code)/i', $stored);

        if (in_array($op, ['create'], true))                   return $usesIncoming;
        if (in_array($op, ['delete', 'get', 'list'], true))    return $usesStored;
        return $usesIncoming || $usesStored;                   // update
    }

    /**
     * `resource == null ||` fails OPEN.
     *
     * On CREATE `resource` is always null, so such a disjunct is unconditionally
     * true and every check after it is unreachable. On get/delete it means a
     * MISSING document is readable/deletable by anyone the rest of the arm
     * admits. `isSameSchool()` opens with exactly this.
     */
    private static function failsOpenOnNull($expr, $op)
    {
        if (!preg_match('/\bresource\s*==\s*null/', preg_replace('/request\.resource/', 'REQRES', $expr))) {
            return false;
        }
        return in_array($op, ['create', 'get', 'list', 'delete'], true);
    }

    public function classify($cond, $op, $var = 'docId')
    {
        $c = $this->inline($cond);

        if (preg_match('/^\s*\(?\s*false\s*\)?\s*$/', $cond)) return 'deny';
        if (preg_match('/^\s*\(?\s*true\s*\)?\s*$/', $cond))  return 'public';

        /**
         * PLATFORM DETECTION USES THE ORIGINAL CONDITION ONLY — NEVER THE
         * INLINED ONE.
         *
         * Two opposite bugs have lived on this line:
         *
         *  1. Searching only the INLINED text for `isSuperAdmin` never matched,
         *     because inlining replaces the helper name with its body. Every
         *     superadmin arm was reported as a cross-tenant finding.
         *
         *  2. Fixing (1) by also matching the strings `super_admin` / `Super
         *     Admin` in the inlined text was WORSE. `isAdmin()` expands to
         *         request.auth.token.role in ['Admin', 'admin',
         *           'School Super Admin', 'school_super_admin',
         *           'Super Admin', 'super_admin']
         *     so EVERY `allow write: if isAdmin();` arm matched and was
         *     silently reclassified `platform-only` — hiding the entire
         *     unscoped-admin defect class from the census. A false negative on
         *     the finding the tool exists to report.
         *
         * `isAdmin()` admits ordinary school admins and is emphatically NOT a
         * platform boundary. Only an explicit `isSuperAdmin()` call, or a role
         * comparison that admits super-admins and nobody else, is.
         */
        if (preg_match('/\bisSuperAdmin\s*\(/', $cond)) {
            return 'platform-only';
        }
        if (preg_match('/super_admin|Super Admin/', $cond)
            && !preg_match("/'Admin'|'admin'|isAdmin\s*\(|isStaff\s*\(/", $cond)) {
            return 'platform-only';
        }

        $hasRole = (bool) preg_match('/token\.role|isAdmin|isStaff|isTeacher|hasCapability/', $c);
        $hasRbac = (bool) preg_match('/hasCapability/', $c);
        $hasTenant = self::tenantBinds($c, $op);

        $hasOwner = (bool) preg_match(
            '/request\.auth\.uid|auth\.token\.get\(\s*[\'"]student_ids?|studentId\s*==|userId\s*==|staffId\s*==|applicantId\s*==|teacherId\s*==|ownerId\s*==/',
            $c
        );
        $hasStudentSelf = (bool) preg_match(
            '/isOwnStudent|isLinkedChild|student_ids|studentId\s*==\s*request\.auth\.uid/',
            $c
        );

        // An arm that dereferences an object unavailable for this operation
        // ERRORS, and Firestore refuses on an evaluation error. It cannot be
        // open. Checked BEFORE the bypass tests, which would otherwise report
        // "no binding tenant check -> wide open" — the exact opposite of what
        // production does. Verified on the emulator, not inferred.
        if (self::nullDerefDenies($c, $op)) return 'null-deref-denies';

        if ($this->roleBypassesTenant($c, $op))    return 'staff-any-tenant';
        if ($this->authBypassesEverything($c))     return 'auth-any-tenant';

        // "Weakest wins" applies WITHIN one arm too. `isSameSchool() && (isStaff()
        // || isOwnStudent())` admits staff AND the student themself; labelling it
        // `student-self` would understate who it actually lets in, so a role
        // predicate takes precedence over the self predicate.
        if ($hasTenant && $hasRbac)        return 'rbac-tenant';
        if ($hasTenant && $hasRole)        return 'staff-own-tenant';
        if ($hasTenant && $hasStudentSelf) return 'student-self';
        if ($hasTenant && $hasOwner)       return 'owner-scoped';
        if ($hasTenant)                    return 'tenant-scoped';
        if ($hasOwner || $hasStudentSelf)  return 'owner-scoped';
        if ($hasRole)                      return 'staff-any-tenant';

        return 'investigate';
    }

    /**
     * THE SEC-4 SHAPE.
     *
     * Split the inlined condition on TOP-LEVEL `||` (respecting parentheses).
     * If any disjunct grants on a role alone — with no document-school
     * comparison that BINDS for this operation — that disjunct authorises
     * across tenants no matter what the other branches check.
     *
     *     isStaff() || (isSameSchool() && isOwnStudent())
     *      ^^^^^^^ this branch alone decides it
     *
     * FAIL-OPEN-ON-NULL COUNTS ONLY FOR `create`.
     *
     * An earlier revision treated `resource == null ||` as a bypass on every
     * operation. That classified `isStaff() && isSameSchool()` — the shape
     * `attendance` uses — as cross-tenant. Measurement says otherwise: the
     * SEC-4 emulator suite proves `attendance` REFUSES a cross-tenant staff
     * read. On get/list/delete a null `resource` means the document does not
     * exist, so there is nothing to disclose and the classifier must not cry
     * wolf.
     *
     * On CREATE it is different in kind: `resource` is ALWAYS null, so the
     * disjunct is unconditionally true and the tenant comparison after it is
     * unreachable for every real write. That is a genuine bypass.
     *
     * `failsOpenOnNull()` is still reported as a separate FLAG for the other
     * operations — it is a real property worth seeing, just not this finding.
     */
    private function roleBypassesTenant($inlined, $op)
    {
        foreach (self::splitTopLevel($inlined, '||') as $d) {
            $hasRole = (bool) preg_match('/token\.role|isAdmin|isStaff|isTeacher|hasCapability|role\s*(==|!=|in)/', $d);
            if (!$hasRole) continue;
            if (!self::tenantMandatory($d, $op)) return true;
        }
        return false;
    }

    /**
     * Does EVERY path through this expression require a tenant comparison that
     * BINDS for this operation?
     *
     * Replaces an earlier "does a fail-open appear anywhere" heuristic, which
     * was too crude in both directions:
     *
     *   isSameSchool() && isSameSchoolWrite() && (isAdmin() || docId == ...)
     *
     * On CREATE `isSameSchool()` fails open, but `isSameSchoolWrite()` still
     * binds through the AND — the tenant IS enforced. The old rule saw the
     * fail-open and cried wolf (`notificationPreferences`). Conversely:
     *
     *   isStaff() && (isSameSchoolWrite() || isSameSchool())
     *
     * On CREATE the second disjunct is unconditionally true, so the binding
     * first one can be skipped entirely — a real bypass, measured.
     *
     * `||` needs EVERY branch to carry a binding check (any branch without one
     * is a way around it); `&&` needs only ONE branch to carry it.
     */
    private static function tenantMandatory($expr, $op, $depth = 0)
    {
        $e = self::unwrap($expr);
        if ($depth > 12) return self::tenantBinds($e, $op);

        $ors = self::splitTopLevel($e, '||');
        if (count($ors) > 1) {
            foreach ($ors as $o) {
                if (!self::tenantMandatory($o, $op, $depth + 1)) return false;
            }
            return true;
        }

        $ands = self::splitTopLevel($e, '&&');
        if (count($ands) > 1) {
            foreach ($ands as $a) {
                if (self::tenantMandatory($a, $op, $depth + 1)) return true;
            }
            return false;
        }

        /**
         * A bare `resource == null` existence test is NOT an escape hatch on
         * get/list/delete: it can only be true when the document does not
         * exist, and a missing document discloses nothing. Treating it as a
         * hole made `isStaff() && isSameSchool()` — the shape `attendance`
         * uses — read as cross-tenant, contradicting the emulator, which shows
         * `attendance` refusing that read.
         *
         * On CREATE it is the opposite: `resource` is ALWAYS null there, so the
         * branch is unconditionally true and genuinely bypasses everything
         * after it. Also measured.
         */
        if (preg_match('/^\(?\s*resource\s*==\s*null\s*\)?$/', preg_replace('/request\.resource/', 'REQRES', $e))) {
            return $op !== 'create';
        }

        /**
         * A branch that DEREFERENCES the object missing for this operation
         * cannot be an escape route — it raises an evaluation error and
         * Firestore refuses. It is a denial, not a way around the tenant check.
         *
         *     isStaff() && (isSameSchoolWrite() || isSameSchool())   on DELETE
         *
         * `request.resource` is null on delete, so the first branch cannot
         * succeed; the only path that can is the second, which DOES compare the
         * document's school. Either Firestore errors the whole arm (denied) or
         * it takes the second branch (tenant-bound) — both are non-findings, so
         * treating the erroring branch as "not an escape" is correct under
         * either semantics.
         */
        $missing = $op === 'delete' ? 'request.resource' : ($op === 'create' ? 'resource' : null);
        if ($missing !== null && self::dereferences($e, $missing)) {
            return true;
        }

        /**
         * OWNER-SCOPING IS A VALID BOUNDARY IN ITS OWN RIGHT.
         *
         * A branch that pins the document to `request.auth.uid` needs no tenant
         * comparison, because Firebase Auth UIDs are unique across the whole
         * project. `staffAttendance` is the pattern:
         *
         *     (resource != null && resource.data.staffId == request.auth.uid)
         *     || (isAdmin() && isSameSchool())
         *
         * The first branch is self-scoped, the second tenant-scoped. Demanding a
         * school comparison in BOTH reported all three staff-attendance
         * collections — and `ptmRsvps` — as cross-tenant, which they are not.
         *
         * DEPENDENCY, recorded rather than assumed: this holds because ZenX
         * mints staff and student ids from a GLOBAL counter (STA0001, STU0001),
         * so `uid == staffId` cannot collide across tenants. If ids ever become
         * per-school, every owner-scoped arm silently becomes cross-tenant and
         * this assumption must be revisited. See
         * `.claude/zenx/SEC4_SEC5_DESIGN_MATRIX.md`.
         */
        if (preg_match('/==\s*request\.auth\.uid|request\.auth\.uid\s*==|request\.auth\.uid\s+in\b/', $e)) {
            return true;
        }

        return self::tenantBinds($e, $op);
    }

    /**
     * Does this arm dereference an object that does not exist for this
     * operation, guaranteeing an evaluation error and therefore a refusal?
     *
     *   delete  -> `request.resource` is null
     *   create  -> `resource` is null
     *
     * The arm is broken only if EVERY evaluation path touches the missing
     * object. Splitting on top-level `||` alone is not enough, because the
     * disjunction is often nested inside a conjunction:
     *
     *     isStaff() && (isSameSchoolWrite() || isSameSchool())
     *
     * On delete the first branch dereferences the null `request.resource`, but
     * the second does not — so the path `isStaff() && isSameSchool()` is
     * perfectly evaluable and the arm is NOT broken. A top-level-`||` split
     * sees one disjunct, finds a dereference in it, and wrongly declares the
     * whole arm dead. Measurement disagreed, which is how this was caught.
     *
     * `resource == null` is an existence TEST, not a dereference, and must not
     * be mistaken for one — `isSameSchool()` opens with exactly that and stays
     * evaluable on create.
     */
    private static function nullDerefDenies($expr, $op)
    {
        if ($op === 'delete')      $missing = 'request.resource';
        elseif ($op === 'create')  $missing = 'resource';
        else                       return false;

        return !self::hasEvaluablePath($expr, $missing);
    }

    /**
     * Is there ANY boolean path through `$expr` that never dereferences
     * `$missing`? Recurses the real structure: `||` needs one branch to work,
     * `&&` needs all of them.
     */
    private static function hasEvaluablePath($expr, $missing, $depth = 0)
    {
        $e = self::unwrap($expr);
        if ($depth > 12) return true;   // give up permissively rather than cry wolf

        $ors = self::splitTopLevel($e, '||');
        if (count($ors) > 1) {
            foreach ($ors as $o) {
                if (self::hasEvaluablePath($o, $missing, $depth + 1)) return true;
            }
            return false;
        }

        $ands = self::splitTopLevel($e, '&&');
        if (count($ands) > 1) {
            foreach ($ands as $a) {
                if (!self::hasEvaluablePath($a, $missing, $depth + 1)) return false;
            }
            return true;
        }

        return !self::dereferences($e, $missing);
    }

    /** `<obj>.data...` is a dereference; `<obj> == null` is only a test. */
    private static function dereferences($expr, $obj)
    {
        if ($obj === 'request.resource') {
            return (bool) preg_match('/request\.resource\s*\.\s*data/', $expr);
        }
        // Plain `resource.data`, excluding the `request.resource.data` prefix.
        $masked = preg_replace('/request\.resource/', 'REQRES', $expr);
        return (bool) preg_match('/(?<![A-Za-z_])resource\s*\.\s*data/', $masked);
    }

    /** Public accessor so reports can surface fail-open as a flag, not a class. */
    public static function failOpenFlag($expr, $op)
    {
        return self::failsOpenOnNull($expr, $op);
    }

    /** A disjunct that grants on bare authentication with no scoping at all. */
    private function authBypassesEverything($inlined)
    {
        foreach (self::splitTopLevel($inlined, '||') as $d) {
            $d = trim($d);
            if ($d === '') continue;
            $bare = preg_match('/request\.auth\s*!=\s*null/', $d) || preg_match('/^\(?\s*isAuth\(\)\s*\)?$/', $d);
            if (!$bare) continue;
            if (preg_match('/(request\.)?resource\.data|request\.auth\.uid|token\.role/', $d)) continue;
            return true;
        }
        return false;
    }

    /**
     * Remove redundant fully-enclosing parentheses.
     *
     * CRITICAL, and the source of a false NEGATIVE on the exact defect this
     * parser exists to detect. `inline()` wraps every substituted helper body in
     * parentheses to preserve precedence, so a bare `isStaffOrOwnStudent()`
     * expands to
     *
     *     ( isStaff() || ( isSameSchool() && isOwnStudent() ) )
     *      ^                                                 ^
     *
     * ...and the `||` is no longer at depth 0. `splitTopLevel` then returned ONE
     * disjunct, the SEC-4 shape went undetected, and `marks.get` was reported as
     * `staff-own-tenant` — the tool clearing the very arm it was built to catch.
     *
     * Only a paren pair that encloses the WHOLE expression is stripped, so
     * `(a) || (b)` is left alone.
     */
    public static function unwrap($expr)
    {
        $e = trim($expr);
        while (strlen($e) > 1 && $e[0] === '(' && substr($e, -1) === ')') {
            $depth = 0;
            $enclosing = true;
            for ($i = 0, $n = strlen($e); $i < $n; $i++) {
                if ($e[$i] === '(') $depth++;
                elseif ($e[$i] === ')') $depth--;
                // Depth returning to 0 before the final char means the leading
                // paren closed early — it is not enclosing the whole thing.
                if ($depth === 0 && $i < $n - 1) { $enclosing = false; break; }
            }
            if (!$enclosing) break;
            $e = trim(substr($e, 1, -1));
        }
        return $e;
    }

    /** Split on a top-level operator, ignoring occurrences inside (), [] or {}. */
    public static function splitTopLevel($expr, $op)
    {
        $expr = self::unwrap($expr);
        $parts = [];
        $depth = 0;
        $buf = '';
        $len = strlen($expr);
        $opLen = strlen($op);
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === '(' || $ch === '[' || $ch === '{') $depth++;
            elseif ($ch === ')' || $ch === ']' || $ch === '}') $depth--;

            if ($depth === 0 && substr($expr, $i, $opLen) === $op) {
                $parts[] = $buf;
                $buf = '';
                $i += $opLen - 1;
                continue;
            }
            $buf .= $ch;
        }
        $parts[] = $buf;
        return array_map('trim', $parts);
    }

    /**
     * Collapse arms to ONE row per (collection, operation), keeping the WEAKEST
     * — because Firestore permits when ANY matching arm passes.
     */
    public function authorizationGraph()
    {
        $byPair = [];
        foreach ($this->arms() as $a) {
            $key = $a['collection'] . '|' . $a['operation'];
            $rank = self::CLASS_ORDER[$a['class']] ?? 99;
            if (!isset($byPair[$key]) || $rank > $byPair[$key]['rank']) {
                $byPair[$key] = [
                    'collection' => $a['collection'],
                    'operation'  => $a['operation'],
                    'class'      => $a['class'],
                    'rank'       => $rank,
                    'condition'  => $a['condition'],
                ];
            }
        }
        $rows = array_values($byPair);
        usort($rows, function ($x, $y) {
            if ($x['collection'] !== $y['collection']) return strcmp($x['collection'], $y['collection']);
            return strcmp($x['operation'], $y['operation']);
        });
        return $rows;
    }
}
