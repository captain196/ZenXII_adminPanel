<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Renders the admin sidebar's staff menu under a chosen capability set.
 *
 * Three suites need to know what the staff menu shows to whom, and they used
 * to answer it by scanning header.php for a gate expression sitting near a
 * link. That worked only while each link carried its own inline condition; the
 * 2026-08-23 consolidation lifted the four gates into named variables computed
 * once at the top of the block, and every proximity scan broke — while the menu
 * itself behaved identically.
 *
 * So the question is answered by EXECUTING the template instead. A gate can now
 * be refactored freely, and these tests still fail if, and only if, somebody's
 * access actually changes.
 */
final class StaffMenu
{
    /**
     * @param string[] $caps     capabilities the viewer holds, e.g. ['HR']
     * @param string[] $features the school's enabled feature list
     * @return string[]          menu links, base-url stripped, in menu order
     */
    public static function render(array $caps, array $features = ['Staff Management']): array
    {
        $block = self::block();

        // Named for the template's own variables — `extract()` is not used, so
        // these must match header.php exactly.
        $school_features = $features;
        $can = static function ($c) use ($caps) { return in_array($c, $caps, true); };

        ob_start();
        try {
            eval('?>' . $block);
        } finally {
            $html = (string) ob_get_clean();
        }

        // base_url() is absolute and carries a path prefix under test
        // (GRADER_BASE_URL), so strip the base rather than assume a leading
        // slash — guessing that wrongly makes every link look missing.
        $base = base_url('');
        preg_match_all('~href="([^"]*)"~', $html, $m);

        $links = [];
        foreach ($m[1] as $href) {
            if ($href === '#') {
                continue;                       // the parent treeview toggle
            }
            $links[] = ltrim(str_replace($base, '', $href), '/');
        }

        return array_values(array_filter($links, static fn($x) => $x !== ''));
    }

    /** Does this capability set reach this link? */
    public static function reaches(string $link, array $caps, array $features = ['Staff Management']): bool
    {
        return in_array($link, self::render($caps, $features), true);
    }

    /** The staff block of header.php, isolated from the rest of the sidebar. */
    public static function block(): string
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/views/include/header.php'
        );

        $start = strpos($src, "<?php\n              \$sfStaff");
        $end   = strpos($src, 'STEP 3 — STUDENTS');

        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException(
                'the staff sidebar block could not be located in header.php — '
                . 'if it was intentionally restructured, update Tests\Support\StaffMenu'
            );
        }

        $block = substr($src, $start, $end - $start);

        // Drop the trailing comment opener that introduces the next section.
        return substr($block, 0, (int) strrpos($block, '<!--'));
    }
}
