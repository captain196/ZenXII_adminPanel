<?php
/**
 * Minimal ruleset for the TIMETABLE arms, so the emulator can compile and test
 * them on a memory-constrained box (the full 200KB file OOMs the JVM here).
 * Same extractor shape as _build_min_ruleset.php: all helpers + only the
 * collections these tests touch. ARM-LEVEL proof, not a whole-file proof.
 */
$SRC = __DIR__ . '/../firestore.rules';
$OUT = __DIR__ . '/_tt_min.rules';
$KEEP = ['timetables','timetableSettings','substitutes','schools','schoolControl','staff'];

$lines = file($SRC, FILE_IGNORE_NEW_LINES);
function capture(array $l, int $i): array {
    $d=0;$o=[];$s=false;
    for($n=count($l);$i<$n;$i++){ $x=$l[$i]; $o[]=$x;
        $d += substr_count($x,'{') - substr_count($x,'}');
        if(!$s && strpos($x,'{')!==false) $s=true;
        if($s && $d<=0) return [$o,$i+1]; }
    return [$o,$i];
}
$fn=[];$bl=[];$kept=[];
for($i=0,$n=count($lines);$i<$n;){
    $l=$lines[$i];
    if(preg_match('/^\s*function\s+[a-zA-Z_]+\s*\(/',$l)){ [$b,$x]=capture($lines,$i); $fn[]=implode("\n",$b); $i=$x; continue; }
    if(preg_match('#^\s*match\s+/databases/#',$l) || preg_match('#^\s*service\s#',$l)){ $i++; continue; }
    if(preg_match('#^\s*match\s+/([a-zA-Z_]+)/#',$l,$m)){
        [$b,$x]=capture($lines,$i);
        if(in_array($m[1],$KEEP,true)){ $bl[]=implode("\n",$b); $kept[]=$m[1]; }
        $i=$x; continue; }
    $i++;
}
$out="rules_version = '2';\n\nservice cloud.firestore {\n  match /databases/{database}/documents {\n\n"
   . implode("\n\n",$fn)."\n\n".implode("\n\n",$bl)."\n\n  }\n}\n";
file_put_contents($OUT,$out);
printf("minimal: %d lines, %d bytes (%.1f%% of source)\nfunctions: %d  blocks: %d (%s)\n",
    substr_count($out,"\n"), strlen($out), 100*strlen($out)/filesize($SRC), count($fn), count($bl), implode(', ',$kept));
$missing=array_diff($KEEP,$kept);
if($missing){ fwrite(STDERR,"MISSING: ".implode(', ',$missing)."\n"); exit(1); }
