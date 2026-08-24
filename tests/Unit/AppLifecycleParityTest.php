<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Lifecycle_resolver.php';

/**
 * Cross-repo parity: the Parent and Teacher apps must resolve the institution
 * lifecycle EXACTLY as the server does.
 *
 * The apps re-implement the composition locally (they have to — they decide
 * what to render before any server call), so the two implementations can
 * drift silently. A drift here is not cosmetic: it would mean the app tells a
 * parent the school is open while the rules deny every read, or vice versa.
 *
 * This test reads the Kotlin source directly, in the same spirit as the
 * existing Homework/Teacher cross-repo tests in this suite. It SKIPS rather
 * than fails when the app repositories are not checked out on this machine,
 * because a missing sibling repo is an environment fact, not a defect.
 */
class AppLifecycleParityTest extends TestCase
{
    private const APPS = [
        'Teacher' => 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/data/lifecycle/SchoolAccessState.kt',
        'Parent'  => 'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/data/lifecycle/SchoolAccessState.kt',
    ];

    private function source(string $app): string
    {
        $path = self::APPS[$app];
        if (!is_file($path)) {
            $this->markTestSkipped("{$app} app not checked out at {$path}");
        }
        return (string) file_get_contents($path);
    }

    public function test_apps_use_the_same_commercial_allow_list_as_the_server(): void
    {
        $server = \Lifecycle_resolver::COMMERCIAL_ALLOW;
        sort($server);

        foreach (array_keys(self::APPS) as $app) {
            $src = $this->source($app);
            $this->assertTrue(
                (bool) preg_match('/ALLOWED_COMMERCIAL\s*=\s*setOf\(([^)]*)\)/s', $src, $m),
                "{$app}: ALLOWED_COMMERCIAL set not found"
            );
            preg_match_all('/"([a-z_]+)"/', $m[1], $states);
            $appList = $states[1];
            sort($appList);

            $this->assertSame($server, $appList,
                "{$app} app and Lifecycle_resolver disagree on which commercial states permit access. "
                . 'A drift means the app and the security rules would tell the user different things.');
        }
    }

    public function test_apps_know_exactly_the_servers_institution_states(): void
    {
        $server = \Lifecycle_resolver::INSTITUTION_STATES;
        sort($server);

        foreach (array_keys(self::APPS) as $app) {
            $src = $this->source($app);
            $this->assertTrue(
                (bool) preg_match('/enum class InstitutionState \{\s*([A-Z_,\s]+);/s', $src, $m),
                "{$app}: InstitutionState enum not found"
            );
            $appList = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
            sort($appList);

            $this->assertSame($server, $appList,
                "{$app} app does not model the same institution states as the server");
        }
    }

    public function test_apps_default_an_absent_institution_state_to_active(): void
    {
        // The backward-compatibility rule. If an app defaulted to anything
        // else, every tenant predating the feature would be shown a closure
        // or "not open" screen.
        foreach (array_keys(self::APPS) as $app) {
            $src = $this->source($app);
            $this->assertMatchesRegularExpression(
                '/else\s*->\s*ACTIVE/',
                $src,
                "{$app}: an unknown/absent institution state must fall back to ACTIVE"
            );
        }
    }

    public function test_apps_treat_closed_as_read_only_not_as_switched_off(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $src = $this->source($app);
            $this->assertTrue(
                (bool) preg_match('/object ClosedReadOnly\s*:\s*SchoolAccess\((.*?)\)/s', $src, $m),
                "{$app}: ClosedReadOnly state not found"
            );
            $args = $m[1];
            $this->assertMatchesRegularExpression('/canUseApp\s*=\s*true/', $args,
                "{$app}: a CLOSED school must remain reachable — records outlive operations");
            $this->assertMatchesRegularExpression('/isReadOnly\s*=\s*true/', $args,
                "{$app}: a CLOSED school must be read-only");
        }
    }

    public function test_apps_deny_archived_outright(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $src = $this->source($app);
            $this->assertTrue(
                (bool) preg_match('/object Archived\s*:\s*SchoolAccess\((.*?)\)/s', $src, $m),
                "{$app}: Archived state not found"
            );
            $this->assertMatchesRegularExpression('/canUseApp\s*=\s*false/', $m[1],
                "{$app}: ARCHIVED must not be usable from the app");
        }
    }

    public function test_apps_separate_commercial_hold_from_school_closure(): void
    {
        // The whole point of the two-axis model. If the app used one message
        // for both, "your school closed" and "the invoice bounced" would be
        // indistinguishable to a parent.
        foreach (array_keys(self::APPS) as $app) {
            $src = $this->source($app);
            $this->assertStringContainsString('object CommercialHold', $src,
                "{$app}: no distinct commercial-hold state");

            preg_match('/object CommercialHold\s*:\s*SchoolAccess\((.*?)\)\n/s', $src, $ch);
            preg_match('/object ClosedReadOnly\s*:\s*SchoolAccess\((.*?)\)\n/s', $src, $cl);
            $this->assertNotEmpty($ch);
            $this->assertNotEmpty($cl);
            $this->assertNotSame($ch[1], $cl[1],
                "{$app}: commercial hold and school closure must not share the same wording");
        }
    }

    public function test_apps_never_infer_lifecycle_from_a_bare_permission_error(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $src = $this->source($app);
            // A PERMISSION_DENIED must be classified WITH the school state,
            // never treated as a lifecycle signal on its own.
            $this->assertStringContainsString('fun classify(', $src,
                "{$app}: no failure classifier");
            $this->assertMatchesRegularExpression(
                '/PERMISSION_DENIED.*?schoolAccess/s',
                $src,
                "{$app}: PERMISSION_DENIED must be interpreted against the school state, not alone"
            );
            foreach (['NETWORK', 'AUTHENTICATION', 'SCHOOL_STATE', 'AUTHORIZATION'] as $kind) {
                $this->assertStringContainsString($kind, $src,
                    "{$app}: failure kind {$kind} missing — the four conditions must stay distinct");
            }
        }
    }

    public function test_apps_introduce_no_rtdb(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $dir = dirname(self::APPS[$app]);
            if (!is_dir($dir)) $this->markTestSkipped("{$app} app not checked out");
            foreach (glob($dir . '/*.kt') as $file) {
                $src = (string) file_get_contents($file);
                foreach (['FirebaseDatabase', 'firebase-database', 'getDatabase(', 'firebaseio.com'] as $needle) {
                    $this->assertStringNotContainsString($needle, $src,
                        basename($file) . " in {$app} references RTDB — the platform is Firestore-only");
                }
            }
        }
    }

    public function test_apps_read_the_existing_mirror_and_do_not_invent_another(): void
    {
        foreach (array_keys(self::APPS) as $app) {
            $repo = dirname(self::APPS[$app]) . '/SchoolLifecycleRepository.kt';
            if (!is_file($repo)) $this->markTestSkipped("{$app} repository not present");
            $src = (string) file_get_contents($repo);

            $this->assertStringContainsString('"tenantPublic"', $src,
                "{$app}: must consume the EXISTING tenantPublic mirror");
            foreach (['institutionState', 'lifecycleState', 'adminDisabled'] as $field) {
                $this->assertStringContainsString($field, $src,
                    "{$app}: must read the {$field} signal the backend already publishes");
            }
            // No new COLLECTION may be introduced for lifecycle purposes.
            // Only constants that actually name a collection are considered —
            // FIELD_* constants name fields on tenantPublic, not collections.
            preg_match_all('/const val (?!FIELD_)([A-Z_]+) = "([a-zA-Z]+)"/', $src, $cols, PREG_SET_ORDER);
            $this->assertNotEmpty($cols, "{$app}: no collection constant found");
            foreach ($cols as $c) {
                $this->assertSame('tenantPublic', $c[2],
                    "{$app}: constant {$c[1]} names collection '{$c[2]}' — no second lifecycle mirror is permitted");
            }

            // Every tenantPublic FIELD the app reads must actually be written
            // by the backend, or the app silently reads null forever.
            preg_match_all('/const val FIELD_[A-Z_]+ = "([a-zA-Z]+)"/', $src, $fields);
            $mirrorWriters = file_get_contents(__DIR__ . '/../../application/libraries/Institution_lifecycle.php')
                           . file_get_contents(__DIR__ . '/../../application/libraries/B2_registry_service.php');
            foreach ($fields[1] as $field) {
                $this->assertStringContainsString("'{$field}'", $mirrorWriters,
                    "{$app}: reads tenantPublic.{$field}, but no backend mirror writes it — "
                    . 'the app would read null forever');
            }
        }
    }
}
