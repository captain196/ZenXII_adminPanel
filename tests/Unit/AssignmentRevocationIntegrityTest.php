<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D14 — a subject assignment that fails to be revoked must not report success.
 *
 * Subject_assignment_service::saveClassAssignments() replaces a class-section's
 * assignments by deleting the existing rows and writing the new ones. The
 * delete set comes from a read:
 *
 *     foreach ($this->_collectExistingDocIds($classKey, $sectionKey) as $staleId) {
 *         $batchOps[] = ['op' => 'delete', ...];
 *     }
 *
 * and that read was `$this->fs->schoolWhere(...)`, which routes to
 * firestoreQuery() and returns [] for a FAILED read exactly as it does for
 * "no existing assignments" (see ACAD-D12). An empty delete set means the batch
 * carries only writes, so every removed teacher's row SURVIVES — and the save
 * reports success.
 *
 * WHY THIS IS THE SEVERE ONE. subjectAssignments is not bookkeeping, it is the
 * authorization substrate. firestore.rules resolves a marks write against it
 * directly:
 *
 *     let perSectionPath = /databases/$(database)/documents/subjectAssignments/
 *         $(schoolId + '_' + session + '_' + assignClassSeg + '_' +
 *           assignSectionSeg + '_' + subjectCode);
 *
 * So a surviving row keeps a removed teacher able to write marks for a subject
 * they no longer teach, enforced at the rules layer.
 *
 * AND THE FAILURE IS PRECISELY TARGETED. docIds are deterministic per subject
 * ({school}_{session}_{class}_{section}_{subjectCode}), so subjects that are
 * re-saved overwrite themselves cleanly. The only rows that survive a missing
 * delete set are the REMOVED ones — the failure lands exactly on the teachers
 * who were supposed to lose access, and nowhere else. That makes it harder to
 * notice, not easier.
 *
 * A second, independent silent path lived in the same method: Firestore_service
 * ::remove() returns BOOL and never throws, so `try { remove(); } catch {}`
 * inspected nothing at all and a refused delete was invisible.
 *
 * The fix is fail-loud reads, checked deletes, and an abort that happens BEFORE
 * any write — a partial save here is worse than no save.
 */
final class AssignmentRevocationIntegrityTest extends TestCase
{
    private function src(): string
    {
        $p = __DIR__ . '/../../application/libraries/Subject_assignment_service.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /** Comments naming a call must not satisfy assertions about that call. */
    private function code(): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $this->src());
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    /**
     * Body of the catch that follows $from, brace-matched.
     *
     * Asserting merely that the METHOD contains a throw is not enough:
     * _deleteClassAssignments also throws from its checked delete, so deleting
     * the catch's rethrow left the method still matching and the mutant alive.
     */
    private function catchBody(string $code, int $from): string
    {
        $c = strpos($code, 'catch', $from);
        if ($c === false) return '';
        $open = strpos($code, '{', $c);
        if ($open === false) return '';
        $depth = 0;
        for ($p = $open, $n = strlen($code); $p < $n; $p++) {
            if ($code[$p] === '{') $depth++;
            elseif ($code[$p] === '}') {
                $depth--;
                if ($depth === 0) return substr($code, $open, $p - $open + 1);
            }
        }
        return '';
    }

    private function method(string $name): string
    {
        $s = $this->code();
        $i = strpos($s, "function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        $e = strpos($s, "\n    private function ", $i + 10);
        if ($e === false) $e = strpos($s, "\n    public function ", $i + 10);
        $this->assertNotFalse($e, "could not bound {$name}()");
        return substr($s, $i, $e - $i);
    }

    // -- the delete set --------------------------------------------------

    /** Every read that produces a delete set must be fail-loud. */
    public function test_the_delete_set_reads_are_fail_loud(): void
    {
        $code = $this->code();

        // Three reads decide what gets DELETED: _collectExistingDocIds,
        // _deleteClassAssignments, and the class-wide (_ALL_) cleanup inside
        // saveClassAssignments. Any one of them on the tolerant helper reads a
        // failed query as "nothing to revoke".
        //
        // Counted within those three, not across the file: a fail-loud read that
        // only feeds a screen is not a delete-set read, and sweeping it into this
        // count would make an unrelated addition look like a regression while
        // saying nothing about revocation.
        foreach (['_collectExistingDocIds', '_deleteClassAssignments'] as $m) {
            $body = $this->method($m);
            $this->assertStringContainsString('whereOrFail', $body,
                "{$m}: still reads through the silent helper");
            $this->assertSame(0, preg_match_all('~fs->schoolWhere\(~', $body),
                "{$m}: a delete set read through schoolWhere() is a revocation "
                . 'that silently does not happen');
        }

        $save = $this->method('saveClassAssignments');
        $this->assertSame(1, preg_match_all('~whereOrFail\(self::COLLECTION~', $save),
            'the class-wide (_ALL_) cleanup inside saveClassAssignments must read '
            . 'fail-loud: a stale class-wide row grants access to the WHOLE class');
    }

    /** Tenant scoping must survive the switch away from schoolWhere(). */
    public function test_the_fail_loud_reads_still_scope_by_school_and_session(): void
    {
        $code = $this->code();

        // Only the three delete-set producers: a display read is scoped by its
        // own caller and is not what this test is about.
        $code = $this->method('_collectExistingDocIds')
              . $this->method('_deleteClassAssignments')
              . $this->method('saveClassAssignments');

        $off = 0; $seen = 0;
        while (($i = strpos($code, 'whereOrFail(self::COLLECTION', $off)) !== false) {
            $off = $i + 1; $seen++;
            $args = substr($code, $i, 420);

            $this->assertMatchesRegularExpression(
                "~\\['schoolId',\\s*'=='~", $args,
                "delete-set read #{$seen} lost its schoolId filter — schoolWhere() "
                . 'used to add it, so it must be spelled out now');
            $this->assertMatchesRegularExpression(
                "~\\['session',\\s*'=='~", $args,
                "delete-set read #{$seen} lost its session filter");
        }
        $this->assertSame(3, $seen);
    }

    /** A read failure must reach the caller, not be logged and forgotten. */
    public function test_a_failed_delete_set_read_throws(): void
    {
        foreach (['_collectExistingDocIds', '_deleteClassAssignments'] as $m) {
            $fn = $this->method($m);

            $this->assertMatchesRegularExpression(
                '~catch\s*\(\\\\Throwable~', $fn,
                "{$m}: catches \\Exception only, which would still swallow the "
                . 'RuntimeException the checked delete raises');

            // The rethrow must live in the CATCH, not merely somewhere in the
            // method — see catchBody().
            $r = strrpos($fn, 'catch');
            $this->assertNotFalse($r, "{$m}: no catch at all");
            $this->assertStringContainsString('throw new \RuntimeException',
                $this->catchBody($fn, $r),
                "{$m}: the catch logs the failure and returns, so the caller carries "
                . 'on and writes while the old rows survive');
        }
    }

    // -- the deletes themselves ------------------------------------------

    /**
     * remove() returns BOOL and never throws, so wrapping it in try/catch
     * inspects nothing. The return value is the only signal there is.
     */
    public function test_every_revoking_delete_checks_its_return_value(): void
    {
        $code = $this->code();

        $this->assertSame(0, preg_match_all('~try \{ \$this->fs->remove\(~', $code),
            'a remove() wrapped in try/catch is not checked at all — remove() '
            . 'returns false rather than throwing');

        $this->assertSame(2, preg_match_all('~if \(!\$this->fs->remove\(self::COLLECTION~', $code),
            'both revoking deletes (the class-wide cleanup and the non-batch '
            . 'fallback) must test the boolean remove() returns');
    }

    // -- the abort -------------------------------------------------------

    /** Nothing may be written once revocation is known to have failed. */
    public function test_the_save_aborts_before_any_write(): void
    {
        $code = $this->code();

        $i = strpos($code, 'public function saveClassAssignments');
        $this->assertNotFalse($i, 'saveClassAssignments() is gone');

        $guard = strpos($code, '_collectExistingDocIds($classKey, $sectionKey)', $i);
        $this->assertNotFalse($guard, 'the delete-set collection is gone');

        // No persistence may occur between the method opening and the guard.
        $before = substr($code, $i, $guard - $i);
        $this->assertSame(0, preg_match_all(
            '~firestoreSet|commitBatch\(|->remove\(|firestoreUpdate~', $before),
            'a write happens before the delete set is even read, so a failure '
            . 'there would leave a partial save behind');

        $this->assertMatchesRegularExpression(
            '~catch\s*\(\\\\Throwable[^)]*\)\s*\{[^}]*return \$result;~s',
            substr($code, $guard, 1400),
            'the delete-set failure must return the failure result, not fall '
            . 'through to the writes');
    }

    /** The operator must be told what did NOT happen, in their terms. */
    public function test_the_refusal_explains_the_revocation_risk(): void
    {
        $code = $this->code();

        $this->assertMatchesRegularExpression(
            '~teachers you \'\s*\.\s*\'removed still assigned~', $code,
            'the message must say why nothing was saved — that saving would have '
            . 'left removed teachers assigned — rather than a generic error');

        $this->assertStringContainsString('assigned to the whole class', $code,
            'the class-wide cleanup failure has a different consequence (whole-class '
            . 'access retained) and must say so');
    }

    /** The premise: this really is the authorization substrate. */
    public function test_the_rules_layer_really_authorizes_from_these_docs(): void
    {
        $p = __DIR__ . '/../../firebase-rules/firestore.rules';
        $this->assertFileExists($p);
        $rules = (string) file_get_contents($p);

        $this->assertMatchesRegularExpression(
            '~documents/subjectAssignments/\$\(schoolId~', $rules,
            'if the rules stopped resolving writes through subjectAssignments, the '
            . 'severity argument above would need revisiting');
    }

    // -- ACAD-D18: the SECOND write surface -------------------------------
    //
    // Academic::save_class_matrix writes subjectAssignments too, in parallel to
    // Subject_assignment_service, and carried the identical defect:
    //
    //     $current = [];                       // the REVOCATION set
    //     try { $rows = $this->fs->schoolWhere('subjectAssignments', [...]); ... }
    //     catch (\Exception $e) { log(...); }  // [] on failure, then carry on
    //     ...
    //     try { $this->fs->remove('subjectAssignments', $rid); } catch {}
    //
    // Fixing one surface and not the other would have left the same teacher
    // able to keep access through the other screen.

    private function ctrl(): string
    {
        $p = __DIR__ . '/../../application/controllers/Academic.php';
        $this->assertFileExists($p);
        $s = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($p));
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    private function saveClassMatrix(): string
    {
        $c = $this->ctrl();
        $i = strpos($c, 'function save_class_matrix(');
        $this->assertNotFalse($i, 'save_class_matrix() is gone');
        $e = strpos($c, "\n    public function ", $i + 10);
        $this->assertNotFalse($e, 'could not bound save_class_matrix()');
        return substr($c, $i, $e - $i);
    }

    public function test_the_controller_revocation_set_is_read_fail_loud(): void
    {
        $fn = $this->saveClassMatrix();

        $this->assertMatchesRegularExpression(
            "~firestoreQueryOrFail\('subjectAssignments'~", $fn,
            'the revocation set is still read through the silent helper, so a '
            . 'failed read empties it and nothing gets revoked');

        $i = strpos($fn, "firestoreQueryOrFail('subjectAssignments'");
        $args = substr($fn, $i, 320);
        $this->assertMatchesRegularExpression("~\['schoolId',\s*'=='~", $args,
            'schoolWhere() used to add schoolId; it must be spelled out now');
        $this->assertMatchesRegularExpression("~\['session',\s*'=='~", $args,
            'the session filter is gone');

        // Reading fail-loud is only half of it: the catch has to STOP the save.
        // Logging the failure and falling through leaves the writes to run with
        // an empty revocation set, which is the original defect exactly.
        $c = strpos($fn, 'catch', $i);
        $this->assertNotFalse($c, 'the read failure is no longer handled');
        $this->assertMatchesRegularExpression(
            '~catch\s*\(\\\\Throwable[^)]*\)\s*\{[^}]*return \$this->json_error~s',
            substr($fn, $c, 700),
            'the catch must return a json_error — otherwise the revocation set is '
            . 'empty and every removed teacher keeps their assignment');
    }

    public function test_the_controller_checks_every_revoking_delete(): void
    {
        $fn = $this->saveClassMatrix();

        $this->assertSame(0, preg_match_all('~try \{ \$this->fs->remove\(\x27subjectAssignments\x27~', $fn),
            'remove() returns false rather than throwing, so a try/catch around it '
            . 'checks nothing');

        $this->assertMatchesRegularExpression(
            '~if \(!\$this->fs->remove\(\x27subjectAssignments\x27, \$rid\)\)~', $fn,
            'the boolean remove() returns is the only failure signal there is');
    }

    /**
     * Ordering is the whole point: granting new access while silently failing to
     * remove old access is the one sequence that must never happen.
     */
    public function test_the_controller_aborts_before_granting_anything(): void
    {
        $fn = $this->saveClassMatrix();

        $read   = strpos($fn, "firestoreQueryOrFail('subjectAssignments'");
        $revoke = strpos($fn, '$revokeFailed[] = $rid');
        $abort  = strpos($fn, 'if (!empty($revokeFailed))');
        $write  = strpos($fn, "fs->set('subjectAssignments'");

        foreach (['read' => $read, 'revoke' => $revoke, 'abort' => $abort, 'write' => $write] as $k => $v) {
            $this->assertNotFalse($v, "the {$k} step is gone");
        }

        $this->assertLessThan($revoke, $read,  'the revocation set must be read first');
        $this->assertLessThan($abort,  $revoke, 'revocations are attempted before the abort check');
        $this->assertLessThan($write,  $abort,
            'the abort must come BEFORE the first write — otherwise new grants land '
            . 'while the old ones survive');

        $this->assertMatchesRegularExpression(
            '~if \(!empty\(\$revokeFailed\)\) \{\s*return \$this->json_error~', $fn,
            'a failed revocation must stop the save, not merely be counted');
    }

    /** Both write surfaces must be covered, or the gap just moves. */
    public function test_both_subject_assignment_write_surfaces_are_covered(): void
    {
        $svc  = $this->code();
        $ctrl = $this->saveClassMatrix();

        // The service reads through Firestore_service::whereOrFail() — the
        // fail-loud sibling of the client it already holds. It used to call
        // $this->firebase->firestoreQueryOrFail(), and this class has no
        // $this->firebase: never declared, never assigned, skipped by both
        // init() signatures. Every save aborted on that null.
        $this->assertStringContainsString('whereOrFail', $svc,
            'Subject_assignment_service surface regressed');
        $this->assertStringNotContainsString('$this->firebase', $svc,
            'this service has no wired Firebase client — a call through one '
            . 'is a call on null, and every save aborts');
        $this->assertStringContainsString('firestoreQueryOrFail', $ctrl,
            'Academic::save_class_matrix surface regressed — a teacher could keep '
            . 'access through whichever screen was left unfixed');
    }
}
