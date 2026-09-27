<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A BATCH UPDATE MASK MUST CARRY ENCODED FIELD PATHS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── LC-13 · THE DEFECT (found 2026-08-27, at RUNTIME) ────────────────────
 * `Firestore_rest_client::commitBatch()` built its update mask as
 *
 *     'fieldPaths' => array_keys($data)
 *
 * — RAW keys. Firestore requires an unquoted field path to match
 * `([a-zA-Z_][a-zA-Z_0-9]*)`; anything else must be backtick-quoted. The
 * class already had `encodeFieldPath()`, used by the single-document writer.
 * The batch writer simply never called it.
 *
 * ── WHY ONLY RUNTIME COULD FIND IT ───────────────────────────────────────
 * The code reads as correct — a mask built from the very keys being written.
 * Nothing is missing to the eye. It is Firestore that rejects it, and only
 * when a key needs quoting. The emulator harness ran `rollover_session` and
 * got back:
 *
 *     HTTP 400 INVALID_ARGUMENT — Invalid property path
 *     "rolloverIntents.2026-27__to__2027-28"
 *
 * ── WHY IT MATTERS ───────────────────────────────────────────────────────
 * The rollover intent key embeds the session names, and EVERY ZenX session is
 * `YYYY-YY` — a path segment that both starts with a digit and contains a
 * hyphen. So session rollover with `promote_students=1` could never record
 * its intent, and always failed with "Could not record rollover intent.
 * Retry." — an error that reads like a transient CAS conflict and invites a
 * retry that can never succeed.
 *
 * It fails loudly rather than corrupting data, which is exactly why it went
 * unnoticed: the feature was simply inoperable.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────
 * The client cannot be constructed without CodeIgniter, so `encodeFieldPath`'s
 * RULES are re-implemented here and pinned against the real implementation.
 * The wiring — that both batch writers call it — is asserted structurally.
 * The end-to-end proof is the emulator run, where the rollover now completes.
 */
final class FirestoreBatchFieldPathTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'libraries/Firestore_rest_client.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        self::$src = (string) preg_replace('/\s+/', ' ', $out);
    }

    /** Verbatim copy of Firestore_rest_client::encodeFieldPath(). */
    private function encodeFieldPath(string $name): string
    {
        if (strpos($name, '.') === false) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/', $name)) return $name;
            return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $name) . '`';
        }
        $encoded = [];
        foreach (explode('.', $name) as $part) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/', $part)) {
                $encoded[] = $part;
            } else {
                $encoded[] = '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $part) . '`';
            }
        }
        return implode('.', $encoded);
    }

    // ── THE EXACT PATH THAT BROKE ROLLOVER ───────────────────────────────

    public function test_the_rollover_intent_path_is_quoted(): void
    {
        // The literal key from the failing production code path.
        $this->assertSame(
            'rolloverIntents.`2026-27__to__2027-28`',
            $this->encodeFieldPath('rolloverIntents.2026-27__to__2027-28'),
            'the rollover intent field path is not quoted — Firestore rejects it with '
            . 'INVALID_ARGUMENT and session rollover cannot record its intent'
        );
    }

    public function test_every_zenx_session_shape_needs_quoting(): void
    {
        // Not one unlucky session: the format itself is unquotable-raw, so
        // this was broken for every school in every year.
        foreach (['2024-25', '2025-26', '2026-27', '2027-28'] as $s) {
            $encoded = $this->encodeFieldPath("rolloverIntents.{$s}__to__2099-00");
            $this->assertStringContainsString('`', $encoded,
                "session '{$s}' produces an unquoted path segment");
        }
    }

    // ── THE RULES ────────────────────────────────────────────────────────

    public function test_a_plain_identifier_is_left_alone(): void
    {
        // The control. If everything were quoted, the assertions above would
        // pass on a function that simply wraps its input.
        $this->assertSame('status', $this->encodeFieldPath('status'));
        $this->assertSame('lifecycle.state', $this->encodeFieldPath('lifecycle.state'));
        $this->assertSame('_private0', $this->encodeFieldPath('_private0'));
    }

    public function test_a_leading_digit_is_quoted(): void
    {
        $this->assertSame('`2026`', $this->encodeFieldPath('2026'));
    }

    public function test_a_hyphen_is_quoted(): void
    {
        $this->assertSame('`a-b`', $this->encodeFieldPath('a-b'));
    }

    public function test_backticks_and_backslashes_are_escaped(): void
    {
        // An unescaped backtick would terminate the quoted path early and
        // change which field is written.
        $this->assertSame('`a\\`b`', $this->encodeFieldPath('a`b'));
        $this->assertSame('`a\\\\b`', $this->encodeFieldPath('a\\b'));
    }

    public function test_only_the_offending_segment_is_quoted(): void
    {
        // Quoting the whole dotted string would make Firestore treat it as ONE
        // literal field name containing dots, silently creating a top-level
        // junk field instead of updating the nested map — the pre-R5 defect
        // the encoder's own docblock records.
        $this->assertSame('rolloverIntents.`2026-27`.state',
            $this->encodeFieldPath('rolloverIntents.2026-27.state'));
    }

    // ── THE WIRING ───────────────────────────────────────────────────────

    public function test_the_comment_stripper_actually_strips(): void
    {
        $this->assertStringNotContainsString('LC-13', self::$src);
        $this->assertStringNotContainsString('INVALID_ARGUMENT', self::$src);
    }

    public function test_no_batch_writer_builds_a_raw_mask(): void
    {
        // The defect verbatim. Both batch writers had it.
        $this->assertStringNotContainsString("'fieldPaths' => array_keys(\$data)", self::$src,
            'a batch writer still builds its update mask from raw keys — any field '
            . 'path needing quotes will be rejected by Firestore');
    }

    public function test_both_batch_writers_encode_their_masks(): void
    {
        // Two writers exist (commitBatch and the batchWrite path). Fixing one
        // and not the other would mean the same data succeeded or failed
        // depending on which writer a caller happened to use.
        $this->assertSame(2, preg_match_all(
            '/\'fieldPaths\' => array_map\( fn\(\$k\) => \$this->encodeFieldPath/', self::$src),
            'expected both batch writers to encode their field paths');
    }

    public function test_the_encoder_under_test_matches_the_implementation(): void
    {
        // Without this the copy above could drift and keep passing.
        $this->assertStringContainsString(
            "preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*\$/', \$part)", self::$src,
            'the field-path rule in the client no longer matches the one tested here');

        // Found by mutation: stripping the backtick-wrap from the REAL
        // encoder left every assertion above passing, because they exercise
        // the copy in this file. Pinning the rule alone was not enough — the
        // ESCAPING has to be pinned too, or the one thing the encoder exists
        // to do could be deleted silently.
        // Counts the shared wrap expression, because the two arms differ in
        // shape — one returns it, the other appends it to the segment list.
        $this->assertSame(2, substr_count(self::$src, "'`' . \$escaped . '`'"),
            'the backtick-wrap is missing from an arm of encodeFieldPath — a path '
            . 'needing quotes would be emitted raw and Firestore would reject the write');
    }
}
