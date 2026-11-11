<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Asking an ADS-B service for aircraft and reading its answer. Shared by the
 * detection loop and the settings page's Test connection button, so the test
 * does exactly what the loop does.
 */

const AIRDEFENDER_FEET_PER_METER = 3.28084;

// Open-Meteo's elevation lookup (Copernicus DEM, 90 m), used only when the
// owner presses Look it up in Settings.
const AIRDEFENDER_ELEVATION_URL = "https://api.open-meteo.com/v1/elevation?latitude={lat}&longitude={lon}";
const AIRDEFENDER_ELEVATION_MIN_FT = -1500;
const AIRDEFENDER_ELEVATION_MAX_FT = 15000;

/* A saved show elevation in feet, kept to a sensible range (0 if unset). */
function airdefender_clamp_elevation($value): int
{
    return is_numeric($value)
        ? (int) max(AIRDEFENDER_ELEVATION_MIN_FT, min(AIRDEFENDER_ELEVATION_MAX_FT, round((float) $value)))
        : 0;
}

/*
 * Aircraft report their height above sea level. Subtracting the show's own
 * elevation makes 'altFt' the height above the show, which is what the
 * altitude limit means. With an elevation of 0 nothing changes.
 */
function airdefender_above_show(array $planes, float $elevationFt): array
{
    if ($elevationFt == 0) {
        return $planes;
    }
    foreach ($planes as $i => $p) {
        if ($p['altFt'] !== null) {
            $planes[$i]['altFt'] = $p['altFt'] - $elevationFt;
        }
    }
    return $planes;
}

/* Ground elevation at a point, in feet: [feet, null] or [null, error]. Asked
   to 3 decimal places (about 100 m), close enough for the ground's height. */
function airdefender_lookup_elevation(float $lat, float $lon): array
{
    $url = str_replace(['{lat}', '{lon}'], [round($lat, 3), round($lon, 3)], AIRDEFENDER_ELEVATION_URL);
    $context = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => "User-Agent: FPP-AirDefender\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return [null, "No answer from the elevation service (api.open-meteo.com). Check the FPP has internet access, or enter the elevation yourself."];
    }
    $data = json_decode($body, true);
    $meters = $data['elevation'][0] ?? null;
    if (!is_numeric($meters)) {
        $why = is_array($data) && isset($data['reason']) ? ": " . rtrim((string) $data['reason'], '. ') : '';
        return [null, "The elevation service could not answer{$why}. Enter the elevation yourself."];
    }
    return [airdefender_clamp_elevation((float) $meters * AIRDEFENDER_FEET_PER_METER), null];
}

/* "45 s", "12 min", "3 h". */
function airdefender_age_text(int $sec): string
{
    return $sec < 120 ? "{$sec} s" : ($sec < 7200 ? round($sec / 60) . " min" : round($sec / 3600) . " h");
}

function airdefender_distance_nm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusNm = 3440.065;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadiusNm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/*
 * Aircraft list from any of the supported feed formats, with altitude
 * normalised to feet in 'altFt' (null when unknown).
 */
function airdefender_parse_aircraft($data): array
{
    if (!is_array($data)) {
        return [];
    }

    $planes = [];

    // readsb / tar1090 / dump1090 ("aircraft") and adsb.lol / adsb.fi ("ac" or "aircraft"): feet.
    $list = $data['aircraft'] ?? $data['ac'] ?? null;
    if (is_array($list)) {
        foreach ($list as $p) {
            if (!is_array($p) || !isset($p['lat'], $p['lon'])) {
                continue;
            }
            // alt_baro is the string "ground" for a plane on the ground.
            $alt = null;
            if (isset($p['alt_geom']) && is_numeric($p['alt_geom'])) {
                $alt = (float) $p['alt_geom'];
            } elseif (isset($p['alt_baro']) && is_numeric($p['alt_baro'])) {
                $alt = (float) $p['alt_baro'];
            } elseif (isset($p['altitude']) && is_numeric($p['altitude'])) {
                $alt = (float) $p['altitude'];   // older dump1090
            }
            // Direction of travel over the ground (degrees from true north), for
            // the radar; its heading when the track isn't sent.
            $track = null;
            foreach (['track', 'true_heading', 'mag_heading'] as $k) {
                if (isset($p[$k]) && is_numeric($p[$k])) {
                    $track = fmod((float) $p[$k] + 360.0, 360.0);
                    break;
                }
            }
            // For the radar's symbols and the details shown when one is clicked; only
            // what the feed sends (online services add type, name and owner from
            // their database; a home receiver may not).
            $text = function ($k) use ($p) {
                return isset($p[$k]) && is_scalar($p[$k]) && trim((string) $p[$k]) !== '' ? substr(trim((string) $p[$k]), 0, 60) : null;
            };
            $rate = $p['baro_rate'] ?? $p['geom_rate'] ?? null;
            $planes[] = [
                'id' => trim((string) ($p['flight'] ?? '')) ?: (string) ($p['hex'] ?? 'Unknown'),
                'track' => $track,
                'info' => array_filter([
                    'hex' => $text('hex'),
                    'cat' => $text('category'),
                    'type' => $text('t'),
                    'desc' => $text('desc'),
                    'reg' => $text('r'),
                    'op' => $text('ownOp'),
                    'year' => $text('year'),
                    'sq' => $text('squawk'),
                    'gs' => isset($p['gs']) && is_numeric($p['gs']) ? (int) round($p['gs']) : null,
                    'vs' => is_numeric($rate) ? (int) round($rate) : null,
                ], function ($v) { return $v !== null; }),
                'lat' => (float) $p['lat'],
                'lon' => (float) $p['lon'],
                'altFt' => $alt,
                'onGround' => ($p['alt_baro'] ?? null) === 'ground',
            ];
        }
    }

    return $planes;
}

/* Fetch and decode the feed. Returns [aircraft list, null] or [null, error]. */
/*
 * Ask a feed for aircraft: [planes, null] or [null, error]. With $maxAgeSec,
 * an answer whose "now" (when the data was written) is older than that is
 * refused: a receiver's web server goes on serving its last file after its
 * decoder stops, which would otherwise look like an empty sky.
 */
function airdefender_fetch_feed(string $url, int $maxAgeSec = 0, int $timeout = 8): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: FPP-AirDefender\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }

    if ($body === false || $body === '') {
        return [null, "No answer from the ADS-B feed"];
    }
    if ($status === 401 || $status === 403) {
        return [null, "ADS-B feed refused the request (HTTP {$status}); it may need an account now. Pick another service in Settings"];
    }
    if ($status === 429) {
        return [null, "ADS-B feed says too many requests (HTTP 429); raise the fetch interval in Settings"];
    }
    if ($status !== 0 && $status !== 200) {
        return [null, "ADS-B feed answered HTTP {$status}"];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [null, "ADS-B feed did not return valid JSON"];
    }
    if ($maxAgeSec > 0 && isset($json['now']) && is_numeric($json['now'])) {
        $written = (float) $json['now'];
        if ($written > 1e11) {
            $written /= 1000;          // some write milliseconds
        }
        $age = (int) round(time() - $written);
        if ($age > $maxAgeSec) {
            return [null, "The receiver's data is " . airdefender_age_text($age) . " old: its decoder may have stopped, or its clock is wrong"];
        }
    }
    // array_key_exists, not isset: an empty sky can come back as "ac": null.
    if (!array_key_exists('aircraft', $json) && !array_key_exists('ac', $json)) {
        return [null, "That address answered, but not with aircraft data"];
    }
    return [airdefender_parse_aircraft($json), null];
}
