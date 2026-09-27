<?php
/**
 * The timetable, on paper.
 *
 * Rendered by Dompdf, which supports far less CSS than a browser: layout is
 * done with tables and simple properties on purpose, and every colour is a
 * literal because there is no cascade to inherit tokens from.
 *
 * The one rule that matters here: a timetable that is NOT published is stamped
 * as such on every page. A printed proposal is otherwise indistinguishable
 * from the real thing, and a staffroom wall is exactly where that mistake
 * becomes a class nobody teaches.
 *
 * @var string $schoolName
 * @var string $session
 * @var array  $version
 * @var array  $days
 * @var array  $breaks  afterPeriod => what this school calls that break
 */
$breaks = isset($breaks) && is_array($breaks) ? $breaks : [];

/** What to call the break after period $n, falling back to the generic word. */
$breakName = static function (int $n) use ($breaks): string {
    $name = trim((string) ($breaks[$n] ?? ''));
    return $name !== '' ? $name : 'Break';
};
$status    = (string) ($version['status'] ?? '');
$published = ($status === 'published');
$label     = trim((string) ($version['label'] ?? ''));

if ($label === '') {
    $label = $published ? 'Timetable' : 'Proposed timetable';
}

$from = (string) ($version['effectiveFrom'] ?? '');
$to   = (string) ($version['effectiveTo'] ?? '');

$fmt = static function (string $iso): string {
    // Slice, never strtotime: a date-only string must not acquire a timezone
    // and shift the day it names.
    if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $iso, $m)) { return ''; }
    $months = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
               '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
               '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'];
    return ((int) $m[3]) . ' ' . ($months[$m[2]] ?? $m[2]) . ' ' . $m[1];
};

if ($published) {
    $applies = $from !== ''
        ? 'In force from ' . $fmt($from) . ($to !== '' ? ' to ' . $fmt($to) : ' onwards')
        : 'In force';
} else {
    $applies = 'Not published — the dates are set when it is published';
}

/**
 * A break is a GAP between periods, not a slot -- nothing is ever scheduled in
 * it. The times already encode it: a period ends, the next starts later. It is
 * derived here rather than read from settings so the sheet always describes the
 * timetable that was actually published.
 *
 * This matters most on paper. A printed week that jumps from 10:15 to 11:00
 * with no explanation is one a teacher has to guess at.
 */
$toMin = static function (string $t): ?int {
    if (!preg_match('~^(\d{1,2}):(\d{2})\s*(AM|PM)$~i', trim($t), $m)) { return null; }
    $h = ((int) $m[1]) % 12;
    if (strtoupper($m[3]) === 'PM') { $h += 12; }
    return $h * 60 + (int) $m[2];
};

// Group by class-section, and keep the week in week order rather than the
// order the documents happened to come back in.
$WEEK = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4,
         'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];

$bySection = [];
foreach ($days as $d) {
    if (!is_array($d)) { continue; }
    $sec = (string) ($d['classSection'] ?? '');
    if ($sec === '') { continue; }
    $bySection[$sec][(string) ($d['day'] ?? '')] = $d;
}
ksort($bySection);
?>
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: sans-serif; font-size: 9pt; color: #1a1d23; margin: 0; }
    .hdr { border-bottom: 2px solid #1a1d23; padding-bottom: 6px; margin-bottom: 4px; }
    .school { font-size: 14pt; font-weight: bold; }
    .meta { font-size: 8.5pt; color: #555; margin-top: 2px; }
    .stamp { background: #fdecec; border: 1px solid #dc2626; color: #dc2626;
             font-size: 8.5pt; font-weight: bold; padding: 4px 8px; margin: 8px 0; }
    .sec { font-size: 11pt; font-weight: bold; margin: 14px 0 5px; }
    table.tt { width: 100%; border-collapse: collapse; }
    table.tt th, table.tt td { border: 1px solid #c9ccd1; padding: 4px 5px;
                               vertical-align: middle; }
    table.tt th { background: #f0f1f3; font-size: 8pt; text-transform: uppercase;
                  letter-spacing: .4px; }
    td.p { width: 62px; text-align: center; background: #f7f8f9; }
    .pn { font-weight: bold; font-size: 9.5pt; }
    .pt { font-size: 6.8pt; color: #666; }
    .subj { font-weight: bold; font-size: 8.5pt; }
    .tch { font-size: 7.5pt; color: #555; }
    .free { color: #999; font-style: italic; font-size: 8pt; }
    td.brk { background: #f0f1f3; color: #555; font-size: 7.5pt; text-align: center;
             letter-spacing: .5px; padding: 3px 5px; }
    .foot { margin-top: 10px; font-size: 7.5pt; color: #777; }
</style>

<div class="hdr">
    <div class="school"><?= html_escape($schoolName) ?></div>
    <div class="meta">
        <?= html_escape($label) ?>
        &nbsp;&middot;&nbsp; Session <?= html_escape($session) ?>
        &nbsp;&middot;&nbsp; <?= html_escape($applies) ?>
    </div>
</div>

<?php if (!$published): ?>
    <div class="stamp">
        NOT PUBLISHED — this is a proposal. It is not the timetable in force,
        and teachers are not seeing it.
    </div>
<?php endif; ?>

<?php if (empty($bySection)): ?>
    <p class="free">This timetable has no days recorded.</p>
<?php endif; ?>

<?php foreach ($bySection as $sec => $dayMap):
    uksort($dayMap, static function ($a, $b) use ($WEEK) {
        return ($WEEK[$a] ?? 99) <=> ($WEEK[$b] ?? 99);
    });
    $dayNames = array_keys($dayMap);

    // How many periods this section actually has, taken from the timetable
    // rather than assumed, so a short day is not padded with invented rows.
    $periodCount = 0;
    foreach ($dayMap as $d) {
        $periodCount = max($periodCount, count($d['periods'] ?? []));
    }
    ?>
    <div class="sec"><?= html_escape($sec) ?></div>
    <table class="tt">
        <thead>
            <tr>
                <th>Period</th>
                <?php foreach ($dayNames as $dn): ?>
                    <th><?= html_escape($dn) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php for ($i = 0; $i < $periodCount; $i++):
            // The times are the same for every day, so take them from the
            // first day that actually has this period.
            $start = ''; $end = '';
            foreach ($dayMap as $d) {
                $p = ($d['periods'] ?? [])[$i] ?? null;
                if (is_array($p) && ($p['startTime'] ?? '') !== '') {
                    $start = (string) $p['startTime'];
                    $end   = (string) ($p['endTime'] ?? '');
                    break;
                }
            }
            ?>
            <tr>
                <td class="p">
                    <div class="pn"><?= $i + 1 ?></div>
                    <?php if ($start !== ''): ?>
                        <div class="pt"><?= html_escape($start) ?></div>
                        <div class="pt"><?= html_escape($end) ?></div>
                    <?php endif; ?>
                </td>
                <?php foreach ($dayNames as $dn):
                    $p    = ($dayMap[$dn]['periods'] ?? [])[$i] ?? null;
                    $subj = is_array($p) ? trim((string) ($p['subject'] ?? '')) : '';
                    $tch  = is_array($p) ? trim((string) ($p['teacher'] ?? '')) : '';
                    ?>
                    <td>
                        <?php if ($subj === ''): ?>
                            <span class="free">Free</span>
                        <?php else: ?>
                            <div class="subj"><?= html_escape($subj) ?></div>
                            <?php if ($tch !== ''): ?>
                                <div class="tch"><?= html_escape($tch) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
            <?php
            // The next period's start, from whichever day can answer: a recess
            // applies to the whole week.
            $nextStart = '';
            foreach ($dayMap as $d) {
                $np = ($d['periods'] ?? [])[$i + 1] ?? null;
                if (is_array($np) && ($np['startTime'] ?? '') !== '') {
                    $nextStart = (string) $np['startTime'];
                    break;
                }
            }
            $gap = 0;
            if ($end !== '' && $nextStart !== '') {
                $a = $toMin($end); $b = $toMin($nextStart);
                if ($a !== null && $b !== null && $b > $a) { $gap = $b - $a; }
            }
            if ($gap > 0): ?>
            <tr>
                <td class="brk" colspan="<?= count($dayNames) + 1 ?>">
                    <strong><?= html_escape(mb_strtoupper($breakName($i + 1))) ?></strong>
                    &middot; <?= html_escape($end) ?>
                    &ndash; <?= html_escape($nextStart) ?> &middot; <?= $gap ?> min
                </td>
            </tr>
            <?php endif; ?>
        <?php endfor; ?>
        </tbody>
    </table>
<?php endforeach; ?>

<div class="foot">
    Printed <?= html_escape(date('j M Y, g:i a')) ?> from ZenXii.
    <?php if ($published): ?>
        A published timetable can change only by publishing a new version.
    <?php endif; ?>
</div>
