<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * geofence_helper — pure geospatial math for GPS staff attendance.
 *
 * Phase 1 of the Attendance Policy Framework (single-campus GPS method).
 * This helper is intentionally I/O-FREE: it performs NO Firestore reads,
 * NO RTDB access, NO logging, and holds NO state. All inputs are passed
 * in by the caller (Attendance_policy library / Staff_attendance
 * controller in later phases) so the functions are deterministic and
 * unit-testable in isolation.
 *
 * Architectural notes
 * -------------------
 *   - Firestore-only mandate: this file deliberately contains zero data
 *     access. It cannot introduce an RTDB dependency.
 *   - The SERVER is the sole authority on geofence membership. These
 *     helpers compute distance/validity from RAW coordinates supplied by
 *     the device; the mobile client never decides attendance.
 *   - Distances are in METRES. Coordinates are decimal degrees (WGS-84).
 *
 * Reuse: no existing haversine/geofence utility exists anywhere in the
 * codebase (verified in the Phase 0 repository audit), so this is the
 * canonical primitive for all geo-distance checks going forward.
 */

if (!defined('GF_EARTH_RADIUS_M')) {
    /** Mean Earth radius in metres (spherical model — accurate to ~0.5%). */
    define('GF_EARTH_RADIUS_M', 6371000.0);
}

if (!function_exists('gf_valid_coord')) {
    /**
     * Validate a latitude/longitude pair is within legal WGS-84 ranges
     * and is finite (rejects NaN/INF that could slip through JSON decode).
     *
     * @param mixed $lat decimal degrees, expected -90..90
     * @param mixed $lng decimal degrees, expected -180..180
     * @return bool true when both values are real, finite, in-range numbers
     */
    function gf_valid_coord($lat, $lng): bool
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if (!is_finite($lat) || !is_finite($lng)) {
            return false;
        }
        return ($lat >= -90.0 && $lat <= 90.0)
            && ($lng >= -180.0 && $lng <= 180.0);
    }
}

if (!function_exists('gf_haversine')) {
    /**
     * Great-circle distance between two points using the haversine formula.
     *
     * Returns the distance in METRES. Returns INF when either coordinate is
     * invalid, so callers that forget to validate fail CLOSED (an INF
     * distance can never be "inside" a finite radius) rather than fail open.
     *
     * @param float $lat1 first point latitude  (decimal degrees)
     * @param float $lng1 first point longitude (decimal degrees)
     * @param float $lat2 second point latitude  (decimal degrees)
     * @param float $lng2 second point longitude (decimal degrees)
     * @return float distance in metres, or INF on invalid input
     */
    function gf_haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        if (!gf_valid_coord($lat1, $lng1) || !gf_valid_coord($lat2, $lng2)) {
            return INF;
        }

        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLam = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2
           + cos($phi1) * cos($phi2) * (sin($dLam / 2) ** 2);
        // Clamp against floating-point overshoot before asin/sqrt.
        $a = max(0.0, min(1.0, $a));
        $c = 2 * asin(sqrt($a));

        return GF_EARTH_RADIUS_M * $c;
    }
}

if (!function_exists('gf_accuracy_acceptable')) {
    /**
     * Decide whether a GPS fix is precise enough to trust for a geofence
     * decision. A fix with a large error radius (e.g. ±500m) cannot prove
     * presence inside a 200m fence, so it must be rejected.
     *
     * @param float $accuracyM    reported horizontal accuracy in metres (>=0)
     * @param float $maxAccuracyM per-school configured ceiling (metres, >0)
     * @return bool true when the fix is precise enough to use
     */
    function gf_accuracy_acceptable(float $accuracyM, float $maxAccuracyM): bool
    {
        if (!is_finite($accuracyM) || $accuracyM < 0.0) {
            return false; // missing/garbage accuracy → reject (fail closed)
        }
        if ($maxAccuracyM <= 0.0) {
            return false; // misconfigured ceiling → reject rather than allow all
        }
        return $accuracyM <= $maxAccuracyM;
    }
}

if (!function_exists('gf_within_radius')) {
    /**
     * Membership test: is a computed distance inside the campus radius?
     *
     * An optional tolerance band may be supplied to absorb GPS jitter at
     * the boundary (typically derived from the fix accuracy, capped by the
     * caller). Default tolerance is 0 (strict). The decision is intentionally
     * left to the caller so policy (accuracy ceiling, tolerance cap) lives in
     * the Attendance_policy library, not in this pure helper.
     *
     * @param float $distanceM  haversine distance in metres (may be INF)
     * @param float $radiusM    configured campus radius in metres (>0)
     * @param float $toleranceM optional boundary tolerance in metres (>=0)
     * @return bool true when distanceM <= radiusM + toleranceM
     */
    function gf_within_radius(float $distanceM, float $radiusM, float $toleranceM = 0.0): bool
    {
        if (!is_finite($distanceM) || $distanceM < 0.0) {
            return false; // INF/invalid distance → never inside (fail closed)
        }
        if ($radiusM <= 0.0) {
            return false; // no/zero radius → geofence not usable
        }
        if (!is_finite($toleranceM) || $toleranceM < 0.0) {
            $toleranceM = 0.0;
        }
        return $distanceM <= ($radiusM + $toleranceM);
    }
}
