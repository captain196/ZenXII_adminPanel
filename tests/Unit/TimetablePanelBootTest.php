<?php
/**
 * THE PANEL HAS TO LOAD AT ALL.
 *
 * Two faults reached the browser that every existing test missed, because both
 * are about the page BOOTING rather than about what it does once it has:
 *
 *   1. versions() read a constant from a class that was only loaded lazily
 *      elsewhere, so the very first request the panel makes returned a 500 and
 *      nothing rendered.
 *
 *   2. Three listeners were bound to controls belonging to the retired legacy
 *      timetable UI. Those elements went when the pane started rendering the
 *      canonical panel; an unguarded bind to a missing element throws, and a
 *      throw at that point silently kills every script defined after it.
 *
 * Neither is visible to a unit test that exercises a method. Both are visible
 * to a test that reads the page the way a browser does.
 */

use PHPUnit\Framework\TestCase;

final class TimetablePanelBootTest extends TestCase
{
    private function file(string $rel): string
    {
        return (string) file_get_contents(__DIR__ . '/../../' . $rel);
    }

    /** Source with comments stripped: a commented-out bind is not a bind. */
    private function code(string $src): string
    {
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        return (string) preg_replace('~^\s*//.*$~m', '', $src);
    }

    /**
     * Every class whose CONSTANTS the controller reads must be loaded by the
     * controller, not by whichever method happens to run first.
     *
     * A constant on an unloaded class is a fatal error, not a missing value —
     * so this fails as a 500 on the panel's first request rather than as a
     * quietly absent number.
     */
    public function test_every_class_the_controller_names_is_required(): void
    {
        $src  = $this->file('application/controllers/Timetable_lifecycle.php');
        $code = $this->code($src);

        // Scan for class references with STRING LITERALS blanked out. A `Class::`
        // inside quotes is not a reference to anything — and `Class::method
        // failed` is this codebase's own log-label convention, so scanning raw
        // text reported a missing require for a class the code never touches.
        // The blanking is only for this scan: the require detection below
        // matches on string content and must keep it.
        $scan = (string) preg_replace("~'(?:\\\\.|[^'\\\\])*'~", "''", $code);

        preg_match_all('~\b(Timetable_[a-z_]+)::~i', $scan, $m);
        $named = array_values(array_unique($m[1]));
        $this->assertNotEmpty($named, 'premise: the controller reads class constants');

        foreach ($named as $class) {
            // Timetable_plan_service is reached through the store, which is
            // required — but naming it directly still needs it present.
            $file = 'libraries/' . $class . '.php';
            $required = strpos($code, "require_once APPPATH . '" . $file . "'") !== false;

            // Or it is required transitively by something the controller does
            // require, which is equally sufficient at runtime.
            if (!$required) {
                foreach (['Timetable_plan_store', 'Timetable_service'] as $dep) {
                    if (strpos($code, "require_once APPPATH . 'libraries/{$dep}.php'") === false) {
                        continue;
                    }
                    $depSrc = $this->file('application/libraries/' . $dep . '.php');
                    if (strpos($depSrc, "libraries/{$class}.php") !== false) {
                        $required = true;
                        break;
                    }
                }
            }

            $this->assertTrue($required,
                "{$class}:: is read by the controller but nothing requires "
                . "{$file}. A constant on an unloaded class is a fatal error, so "
                . 'the first request that reaches it returns a 500 and the panel '
                . 'never renders.');
        }
    }

    /**
     * Every element a listener is bound to must exist on the rendered page.
     *
     * `document.getElementById('gone').addEventListener(...)` throws, and a
     * throw during setup stops every script defined after it — so one stale
     * binding takes out unrelated features with no clue as to why.
     */
    public function test_every_bound_element_exists_on_the_page(): void
    {
        $host  = $this->file('application/views/academic/index.php');
        $panel = $this->file('application/views/timetable/lifecycle.php');
        $markup = $host . $panel;

        $missing = [];
        foreach ([['academic/index.php', $host], ['timetable/lifecycle.php', $panel]] as [$name, $src]) {
            $code = $this->code($src);

            // Only UNGUARDED binds: `getElementById('x').addEventListener`.
            // The guarded form assigns first and tests before binding, which is
            // safe and is what a retired control should use.
            preg_match_all("~getElementById\('([A-Za-z_][A-Za-z0-9_-]*)'\)\s*\.?\s*\n?\s*\.addEventListener~",
                $code, $m);

            foreach (array_unique($m[1]) as $id) {
                if (strpos($markup, 'id="' . $id . '"') === false) {
                    $missing[] = "{$name}: #{$id}";
                }
            }
        }

        $this->assertSame([], $missing,
            'these listeners bind to elements that are not on the page. An '
            . 'unguarded bind to a missing element throws and silently kills '
            . 'every script after it: ' . implode(', ', $missing));
    }

    /**
     * The panel's own first request must be answerable.
     *
     * versions() is what the screen calls on load, so anything it needs has to
     * be reachable without another action having run first.
     */
    public function test_the_first_request_the_panel_makes_needs_nothing_lazy(): void
    {
        $src = $this->file('application/controllers/Timetable_lifecycle.php');

        $start = strpos($src, 'public function versions()');
        $this->assertNotFalse($start, 'premise: versions() exists');
        $body = substr($src, $start, strpos($src, "\n    }", $start) - $start);

        // It must not depend on a library that only another action loads.
        $this->assertStringNotContainsString("load->library(", $body,
            'versions() runs on page load; loading a library lazily here means '
            . 'the panel depends on the order requests happen to arrive in');

        // And the constants it reports must come from required classes — which
        // the test above proves for the controller as a whole.
        $this->assertStringContainsString('versionLimit', $body,
            'the screen shows how much room is left, so the ceiling must be sent');
        $this->assertStringContainsString('batchMax', $body);
    }
}
