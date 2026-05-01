<?php
/**
 * Strip ALL RTDB calls from Attendance.php.
 * Per Phase 7 memory: all writes are Firestore-first → RTDB mirror,
 * all reads are Firestore-first → RTDB fallback. Safe to remove.
 *
 * Strategy:
 * 1. For single-line RTDB calls: comment out the line
 * 2. For multi-line calls (set/update with array arg): replace entire
 *    statement from `$this->firebase->` to matching `);` with comment
 * 3. For try/catch blocks wrapping ONLY an RTDB call: replace entire block
 * 4. For RTDB fallback reads: replace with null/[] assignment
 */

$file = __DIR__ . '/../application/controllers/Attendance.php';
$lines = file($file);
$out = [];
$skip_until_semicolon = false;
$skip_until_catch_end = false;
$brace_depth = 0;
$removed = 0;

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $trimmed = trim($line);

    // Skip continuation of a multi-line firebase call
    if ($skip_until_semicolon) {
        if (preg_match('/;\s*$/', $trimmed) || preg_match('/\}\s*catch/', $trimmed)) {
            $skip_until_semicolon = false;
            // If the closing line is just `);` keep skipping
            if (preg_match('/^\s*\]\s*\)\s*;\s*$/', $trimmed) || preg_match('/^\s*\)\s*;\s*$/', $trimmed)) {
                $removed++;
                continue;
            }
            // If it's a catch line, keep it for the try/catch structure
            if (preg_match('/catch/', $trimmed)) {
                $out[] = $line;
                continue;
            }
        }
        $removed++;
        continue;
    }

    // Detect RTDB call (not firestoreSet/Get/etc)
    if (preg_match('/\$this->firebase->(get|set|update|delete|push|shallow_get)\s*\(/', $trimmed) &&
        !preg_match('/firestore(Set|Get|Update|Delete)|deleteFirebaseUser|updateFirebaseUser|createFirebaseUser|setFirebaseClaims|uploadFile|getDownloadUrl/', $trimmed)) {

        // Check if it's a single-line statement (ends with ;)
        if (preg_match('/;\s*$/', $trimmed)) {
            // Single line — check if it's an assignment
            if (preg_match('/^\$\w+\s*=\s*\$this->firebase->get/', $trimmed)) {
                // Read fallback — replace variable with null/[]
                $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
                if (preg_match('/\?\?\s*\[\]/', $trimmed)) {
                    $out[] = $indent . "// RTDB fallback removed per no-RTDB policy.\n";
                } else {
                    // Extract variable name
                    preg_match('/^(\$\w+)\s*=/', $trimmed, $m);
                    $var = $m[1] ?? '$_rtdb';
                    $out[] = $indent . "{$var} = null; // RTDB fallback removed\n";
                }
            } elseif (preg_match('/^\$\w+\s*=\s*\$this->firebase->shallow_get/', $trimmed)) {
                $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
                preg_match('/^(\$\w+)\s*=/', $trimmed, $m);
                $var = $m[1] ?? '$_rtdb';
                $out[] = $indent . "{$var} = []; // RTDB fallback removed\n";
            } else {
                // Write mirror — comment out
                $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
                $out[] = $indent . "// RTDB mirror removed per no-RTDB policy.\n";
            }
            $removed++;
            continue;
        } else {
            // Multi-line — skip until we find the closing );
            $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));

            // Check if it's an assignment (read)
            if (preg_match('/^\$\w+\s*=\s*\$this->firebase->(get|shallow_get)/', $trimmed)) {
                preg_match('/^(\$\w+)\s*=/', $trimmed, $m);
                $var = $m[1] ?? '$_rtdb';
                $out[] = $indent . "{$var} = null; // RTDB fallback removed (multi-line)\n";
            } else {
                $out[] = $indent . "// RTDB mirror removed per no-RTDB policy.\n";
            }
            $skip_until_semicolon = true;
            $removed++;
            continue;
        }
    }

    $out[] = $line;
}

file_put_contents($file, implode('', $out));
echo "Removed/replaced {$removed} RTDB call sites.\n";
