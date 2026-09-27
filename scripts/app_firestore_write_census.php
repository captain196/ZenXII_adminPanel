<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * CLIENT-SIDE FIRESTORE WRITE CENSUS (Parent + Teacher apps) · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/app_firestore_write_census.php <collection> [<collection> ...]
 *
 * Firestore rules constrain CLIENTS only — the PHP backend and the Cloud
 * Functions both use the Admin SDK and bypass rules entirely. So the question
 * "what breaks if I tighten this write arm" is exactly "what do the two Kotlin
 * apps write". This answers that from the app sources, not from assumption.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 * `userDevices` is the standing precedent: the obvious tenant-scoping fix
 * would have broken push registration for every user on both apps, because the
 * real boundary was OWNERSHIP, not tenancy. The only way to know that in
 * advance is to read what the clients actually write.
 *
 * ── HOW A COLLECTION IS REACHED ───────────────────────────────────────────
 * Three distinct paths, and a census that checks only the first is wrong:
 *
 *   1. FirestoreService.{set,update,delete}Document(COLLECTION, ...)
 *   2. FirestoreService.docRef(COLLECTION, ...) -> .set / .update / .delete,
 *      or handed to a WriteBatch / runTransaction. The service call itself
 *      looks inert; the mutation happens on the returned reference.
 *   3. Raw `firestore.collection("name")` / `.collection(Constants...)`,
 *      bypassing the service abstraction altogether.
 *
 * Collections are almost always referenced through a `Constants.Firestore.X`
 * symbol rather than a string literal, so the symbol is resolved first and
 * BOTH forms are then searched.
 *
 * Output is a per-surface verdict: READ-ONLY, or WRITES with the call sites.
 * A READ-ONLY verdict is what licenses a server-only / tenant-scoped write arm.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$targets = array_slice($argv, 1);
if ($targets === []) {
    fwrite(STDERR, "usage: php scripts/app_firestore_write_census.php <collection> [...]\n");
    exit(2);
}

$APPS = [
    'Parent'  => 'D:/Projects/SchoolSyncParent/app/src/main/java',
    'Teacher' => 'D:/Projects/SchoolSyncTeacher/app/src/main/java',
];

foreach ($APPS as $name => $root) {
    if (!is_dir($root)) { fwrite(STDERR, "MISSING app source: {$name} ({$root}) — cannot certify\n"); exit(3); }
}

/** Every .kt file under a root. */
function ktFiles($root) {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'kt') $out[] = $f->getPathname();
    }
    return $out;
}

$WRITE_METHODS = ['setDocument', 'updateDocument', 'deleteDocument'];

$cache = [];
foreach ($APPS as $app => $root) $cache[$app] = ktFiles($root);

echo "CLIENT-SIDE FIRESTORE WRITE CENSUS\n";
echo str_repeat('=', 96) . "\n";
echo "Rules bind CLIENTS only. PHP backend + Cloud Functions use the Admin SDK\n";
echo "and bypass rules, so they are deliberately OUT of scope here.\n\n";

$verdicts = [];

foreach ($targets as $col) {
    printf("\n──────── %s %s\n", $col, str_repeat('─', max(1, 78 - strlen($col))));

    foreach ($APPS as $app => $root) {
        // 1. Resolve the Constants symbol(s) that carry this collection name.
        $symbols = [];
        foreach ($cache[$app] as $file) {
            if (strpos($file, 'Constants.kt') === false) continue;
            foreach (file($file) as $line) {
                if (preg_match('/const\s+val\s+([A-Z0-9_]+)\s*=\s*"' . preg_quote($col, '/') . '"/', $line, $m)) {
                    $symbols[$m[1]] = true;
                }
            }
        }
        $symbols = array_keys($symbols);

        // Both the resolved symbol and the raw literal are searched — a
        // collection can be reached either way in the same codebase.
        $needles = ['"' . $col . '"'];
        foreach ($symbols as $s) $needles[] = 'Firestore.' . $s;

        $writes = [];
        $docRefs = [];
        $raw = [];
        $reads = 0;

        foreach ($cache[$app] as $file) {
            $src = (string) file_get_contents($file);
            $hit = false;
            foreach ($needles as $n) { if (strpos($src, $n) !== false) { $hit = true; break; } }
            if (!$hit) continue;
            if (strpos($file, 'Constants.kt') !== false) continue;

            $lines = explode("\n", str_replace("\r", '', $src));
            foreach ($lines as $i => $line) {
                $matched = false;
                foreach ($needles as $n) { if (strpos($line, $n) !== false) { $matched = true; break; } }
                if (!$matched) continue;

                // A call can span lines: the method name sits above the
                // collection argument. Look back a few lines for the verb.
                $ctx = implode(' ', array_slice($lines, max(0, $i - 4), 5));
                $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
                $rel = str_replace('\\', '/', $rel);
                $loc = $rel . ':' . ($i + 1);

                foreach ($WRITE_METHODS as $wm) {
                    if (strpos($ctx, $wm . '(') !== false) { $writes[] = [$loc, $wm, trim($line)]; $matched = 'w'; break; }
                }
                if ($matched === 'w') continue;

                if (strpos($ctx, 'docRef(') !== false) { $docRefs[] = [$loc, 'docRef', trim($line)]; continue; }
                if (preg_match('/\.collection\(/', $ctx))  { $raw[] = [$loc, 'collection()', trim($line)]; continue; }
                $reads++;
            }
        }

        $key = $col . '/' . $app;
        if ($writes === [] && $docRefs === [] && $raw === []) {
            printf("  %-8s READ-ONLY  (%d read reference%s)\n", $app, $reads, $reads === 1 ? '' : 's');
            $verdicts[$key] = 'READ-ONLY';
        } else {
            printf("  %-8s WRITES / NEEDS REVIEW\n", $app);
            foreach ($writes as $w)  printf("      WRITE   %-58s %s\n", $w[0], $w[1]);
            foreach ($docRefs as $d) printf("      DOCREF  %-58s %s  <- verify what happens to the ref\n", $d[0], $d[1]);
            foreach ($raw as $r)     printf("      RAW     %-58s %s  <- bypasses the service\n", $r[0], $r[1]);
            printf("              (%d additional read reference%s)\n", $reads, $reads === 1 ? '' : 's');
            $verdicts[$key] = $writes !== [] ? 'WRITES' : 'REVIEW';
        }
    }
}

echo "\n" . str_repeat('=', 96) . "\nVERDICTS\n";
foreach ($verdicts as $k => $v) printf("  %-46s %s\n", $k, $v);
echo "\nREAD-ONLY on BOTH surfaces = no client writes = a tenant-scoped or\n";
echo "server-only write arm cannot break an app. Anything else needs the\n";
echo "payload read before the boundary is chosen.\n";
echo "No files were modified.\n";
exit(0);
