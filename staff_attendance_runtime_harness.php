<?php
/**
 * staff_attendance_runtime_harness.php
 * ------------------------------------
 * Standalone CLI harness that exercises the LIVE GPS staff-attendance backend
 * (staff_attendance/punch + staff_attendance/me) over HTTP with a real Firebase
 * ID token, asserting HTTP status + JSON for every scenario.
 *
 * It is NOT part of the app (CLI-only; not web-routable). It hits the backend
 * exactly as the Teacher app would — Bearer token, JSON body — so it validates
 * the production contract end-to-end without needing a device/emulator.
 *
 * ⚠️ WRITES REAL ATTENDANCE. A successful check-in/out records attendance for
 *    the token's staff member. RUN AGAINST A TEST TEACHER ACCOUNT (ideally a
 *    test school). The harness prints every clientPunchId it writes so you can
 *    locate/clean the test docs. It modifies NOTHING beyond these controlled
 *    punch scenarios.
 *
 * ⚠️ Conditional scenarios (holiday / leave / month-lock) require a precondition
 *    that this harness will NOT create (that would modify other data). They are
 *    SKIPPED unless you pass the matching --expect-* flag confirming you have
 *    set the precondition up manually.
 *
 * GET A TOKEN: sign in as the test teacher in the app/Firebase and copy the
 * Firebase ID token (e.g. FirebaseAuth.getInstance().currentUser.getIdToken()).
 * Tokens expire ~1h.
 *
 * USAGE:
 *   php staff_attendance_runtime_harness.php \
 *       --base=https://host/ZenX/school/ \
 *       --token=<staff_firebase_id_token> \
 *       --lat=26.8513 --lng=80.9463 \
 *       --out-lat=26.8700 --out-lng=80.9462 \
 *       [--accuracy=15] [--parent-token=<token>] \
 *       [--expect-holiday] [--expect-leave] [--expect-lock]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$opts = getopt('', [
    'base:', 'token:', 'lat:', 'lng:', 'out-lat:', 'out-lng:',
    'accuracy::', 'parent-token::', 'expect-holiday', 'expect-leave', 'expect-lock',
]);

$required = ['base', 'token', 'lat', 'lng', 'out-lat', 'out-lng'];
foreach ($required as $r) {
    if (empty($opts[$r])) {
        fwrite(STDERR, "Missing --$r. See header for usage.\n");
        exit(2);
    }
}

$BASE       = rtrim($opts['base'], '/') . '/';
$TOKEN      = $opts['token'];
$LAT        = (float) $opts['lat'];
$LNG        = (float) $opts['lng'];
$OUT_LAT    = (float) $opts['out-lat'];
$OUT_LNG    = (float) $opts['out-lng'];
$ACC        = isset($opts['accuracy']) ? (float) $opts['accuracy'] : 15.0;
$PARENT_TOK = $opts['parent-token'] ?? null;

$pass = 0; $fail = 0; $skip = 0;
$written = [];

/** POST a punch (or GET /me). Returns [httpCode, decodedJson|null, rawBody]. */
function http_call(string $url, ?string $token, ?array $jsonBody, string $method = 'POST'): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); return [0, null, "CURL_ERR: $err"]; }
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

function punch_body(array $over): array
{
    global $LAT, $LNG, $ACC;
    return array_merge([
        'direction'        => 'in',
        'lat'              => $LAT,
        'lng'              => $LNG,
        'accuracy'         => $ACC,
        'mock'             => false,
        'clientPunchId'    => 'harness-' . bin2hex(random_bytes(6)),
        'clientCapturedAt' => date('c'),
        'device'           => ['model' => 'harness', 'os' => 'CLI', 'osVersion' => '0', 'appVersion' => 'test'],
    ], $over);
}

function assert_scenario(string $name, array $resp, int $expectStatus, ?string $expectReasonContains = null, ?callable $extra = null): void
{
    global $pass, $fail;
    [$code, $json, $raw] = $resp;
    $okStatus = ($code === $expectStatus);
    $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
    $okReason = $expectReasonContains === null ? true : (stripos($raw, $expectReasonContains) !== false || stripos($msg, $expectReasonContains) !== false);
    $okExtra  = $extra === null ? true : (bool) $extra($code, $json, $raw);
    if ($okStatus && $okReason && $okExtra) {
        echo "PASS  $name  (HTTP $code)\n"; $pass++;
    } else {
        echo "FAIL  $name\n";
        echo "      expected HTTP $expectStatus" . ($expectReasonContains ? " containing '$expectReasonContains'" : "") . "\n";
        echo "      actual   HTTP $code  body: " . substr($raw ?? '', 0, 240) . "\n";
        $fail++;
    }
}

function skip_scenario(string $name, string $why): void
{
    global $skip; echo "SKIP  $name  ($why)\n"; $skip++;
}

echo "=== GPS Staff Attendance — Backend Runtime Harness ===\n";
echo "Base: $BASE\n\n";

$PUNCH = $BASE . 'staff_attendance/punch';
$ME    = $BASE . 'staff_attendance/me';

// 1. Auth: no token → 401
assert_scenario('AuthFailure_noToken', http_call($PUNCH, null, punch_body([])), 401);

// 2. Auth: garbage token → 401
assert_scenario('AuthFailure_badToken', http_call($PUNCH, 'not.a.valid.token.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', punch_body([])), 401);

// 3. Authz: parent (non-staff) token → 403  (optional)
if ($PARENT_TOK) {
    assert_scenario('AuthzFailure_parentToken', http_call($PUNCH, $PARENT_TOK, punch_body([])), 403);
} else {
    skip_scenario('AuthzFailure_parentToken', 'no --parent-token supplied');
}

// 4. Mock location → 409 mock_location
assert_scenario('MockLocationRejected', http_call($PUNCH, $TOKEN, punch_body(['mock' => true])), 409, 'mock');

// 5. Poor accuracy → 409 poor_accuracy
assert_scenario('PoorAccuracyRejected', http_call($PUNCH, $TOKEN, punch_body(['accuracy' => 999.0])), 409, 'accuracy');

// 6. Outside geofence → 409 outside_geofence
assert_scenario('OutsideGeofenceRejected', http_call($PUNCH, $TOKEN, punch_body(['lat' => $OUT_LAT, 'lng' => $OUT_LNG])), 409, 'outside');

// 7. Conditional: holiday / leave / lock (operator-confirmed preconditions)
if (isset($opts['expect-holiday'])) {
    assert_scenario('HolidayRejected', http_call($PUNCH, $TOKEN, punch_body([])), 409, 'holiday');
} else { skip_scenario('HolidayRejected', 'precondition not set (pass --expect-holiday when today is a holiday)'); }

if (isset($opts['expect-leave'])) {
    assert_scenario('LeaveRejected', http_call($PUNCH, $TOKEN, punch_body([])), 409, 'leave');
} else { skip_scenario('LeaveRejected', 'precondition not set (pass --expect-leave when staff is on approved leave today)'); }

if (isset($opts['expect-lock'])) {
    assert_scenario('AttendanceLocked', http_call($PUNCH, $TOKEN, punch_body([])), 409, 'lock');
} else { skip_scenario('AttendanceLocked', 'precondition not set (pass --expect-lock when month is locked)'); }

// 8. Successful Check-In (inside, good accuracy). Accept 200 whether P/T or
//    already_checked_in (idempotent if a prior run already marked today).
$inId = 'harness-in-' . bin2hex(random_bytes(6));
$written[] = $inId;
$inResp = http_call($PUNCH, $TOKEN, punch_body(['direction' => 'in', 'clientPunchId' => $inId]));
assert_scenario('CheckIn_success', $inResp, 200, null, function ($code, $json) {
    return is_array($json) && ($json['status'] ?? '') === 'success';
});

// 9. Duplicate Check-In (new id) → 200, already checked in (no double status)
assert_scenario('CheckIn_duplicate', http_call($PUNCH, $TOKEN, punch_body(['direction' => 'in'])), 200, null, function ($code, $json) {
    // allowed=true success; message indicates already checked in OR a normal success
    return is_array($json) && ($json['status'] ?? '') === 'success';
});

// 10. Idempotency: SAME clientPunchId twice → both 200, second non-crashing
$idemId = 'harness-idem-' . bin2hex(random_bytes(6));
$written[] = $idemId;
$first  = http_call($PUNCH, $TOKEN, punch_body(['direction' => 'in', 'clientPunchId' => $idemId]));
$second = http_call($PUNCH, $TOKEN, punch_body(['direction' => 'in', 'clientPunchId' => $idemId]));
assert_scenario('Idempotency_replay', $second, 200, null, function ($code, $json) use ($first) {
    return $first[0] === 200 && is_array($json) && ($json['status'] ?? '') === 'success';
});

// 11. /me read → 200 with today + month + history
assert_scenario('Me_read', http_call($ME, $TOKEN, null, 'GET'), 200, null, function ($code, $json) {
    return is_array($json) && isset($json['today']) && isset($json['month']) && array_key_exists('history', $json);
});

// 12. Successful Check-Out (inside) → 200
$outId = 'harness-out-' . bin2hex(random_bytes(6));
$written[] = $outId;
assert_scenario('CheckOut_success', http_call($PUNCH, $TOKEN, punch_body(['direction' => 'out', 'clientPunchId' => $outId])), 200, null, function ($code, $json) {
    return is_array($json) && ($json['status'] ?? '') === 'success';
});

echo "\n=== SUMMARY ===  PASS=$pass  FAIL=$fail  SKIP=$skip\n";
echo "Wrote test punches (clientPunchIds): " . implode(', ', $written) . "\n";
echo "attendancePunches doc IDs = {schoolId}_<clientPunchId>; staffAttendance/{schoolId}_<today>_<staffId> reflects the check-in.\n";
exit($fail > 0 ? 1 : 0);
