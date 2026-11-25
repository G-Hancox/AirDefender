<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Ignore areas: places like an airport ramp or a hospital helipad, outlined on
 * a map, where an aircraft that is parked or taxiing should not close the heads.
 * Off unless the user turns it on (Limits tab).
 *
 * An aircraft is ignored only when all three hold:
 *   - its position is inside the outline,
 *   - its reported height is no more than the area's ceiling above the area's
 *     ground height (the height a parked aircraft reports there),
 *   - it reports a ground speed, and that speed is below the area's limit.
 * An aircraft with no speed or no height is never ignored. Anything climbing
 * out, landing fast or hovering above the ceiling still counts.
 *
 * The areas live in plugindata/AirDefender/ignore_areas.json (the folder
 * DeadZones uses), written only when the user saves.
 */

require_once(__DIR__ . '/airdefender_paths.php');
require_once(__DIR__ . '/airdefender_files.php');
require_once(__DIR__ . '/airdefender_deadzones.php');
require_once(__DIR__ . '/airdefender_adsb.php');

const AIRDEFENDER_IGNORE_MAX_AREAS = 5;
const AIRDEFENDER_IGNORE_MAX_POINTS = 30;
const AIRDEFENDER_IGNORE_MAX_SPAN_NM = 10.0;

function airdefender_ignore_file(): string
{
    return airdefender_deadzones_dir() . '/ignore_areas.json';
}

/* The defaults and limits for an area's numbers: [default, min, max]. */
function airdefender_ignore_limits(): array
{
    return [
        'ceilingFt' => [100, 25, 200],
        'maxKt' => [40, 5, 60],
        'groundFt' => [0, -1500, 15000],
    ];
}

/*
 * Check one area as sent by the page or read from the file. Returns
 * [area, null] or [null, reason]. Numbers outside their limits are refused,
 * not quietly changed, so what is saved is what the user saw.
 */
function airdefender_ignore_check_area($a): array
{
    if (!is_array($a)) {
        return [null, 'not an area'];
    }
    $name = trim((string) ($a['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 40) {
        return [null, 'give the area a name (up to 40 characters)'];
    }
    $points = $a['points'] ?? null;
    if (!is_array($points) || count($points) < 3 || count($points) > AIRDEFENDER_IGNORE_MAX_POINTS) {
        return [null, 'an area needs 3 to ' . AIRDEFENDER_IGNORE_MAX_POINTS . ' corners'];
    }
    $clean = [];
    foreach ($points as $pt) {
        if (!is_array($pt) || count($pt) !== 2 || !is_numeric($pt[0] ?? null) || !is_numeric($pt[1] ?? null)
            || abs((float) $pt[0]) > 90 || abs((float) $pt[1]) > 180) {
            return [null, 'a corner is not a map position'];
        }
        $clean[] = [round((float) $pt[0], 6), round((float) $pt[1], 6)];
    }
    foreach ($clean as $p) {
        foreach ($clean as $q) {
            if (airdefender_distance_nm($p[0], $p[1], $q[0], $q[1]) > AIRDEFENDER_IGNORE_MAX_SPAN_NM) {
                return [null, 'an area can be at most ' . (int) AIRDEFENDER_IGNORE_MAX_SPAN_NM . ' NM across'];
            }
        }
    }
    $out = ['name' => $name, 'points' => $clean];
    foreach (airdefender_ignore_limits() as $key => [$default, $min, $max]) {
        $v = $a[$key] ?? $default;
        if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            return [null, "{$key} must be {$min} to {$max}"];
        }
        $out[$key] = (int) round((float) $v);
    }
    return [$out, null];
}

/* The saved areas, checked. Read again only when the file changes. */
function airdefender_ignore_areas(): array
{
    static $cache = [], $stamp = null;
    $file = airdefender_ignore_file();
    clearstatcache(true, $file);
    $now = @filemtime($file);
    if ($now === false) {
        $cache = [];
        $stamp = null;
        return [];
    }
    if ($now !== $stamp) {
        $stamp = $now;
        $data = json_decode((string) @file_get_contents($file), true);
        $cache = [];
        foreach ((is_array($data['areas'] ?? null) ? $data['areas'] : []) as $a) {
            [$ok] = airdefender_ignore_check_area($a);
            if ($ok !== null && count($cache) < AIRDEFENDER_IGNORE_MAX_AREAS) {
                $cache[] = $ok;
            }
        }
    }
    return $cache;
}

/*
 * Is the point inside the outline? Ray casting on longitude scaled by the
 * cosine of the latitude, which is exact enough for an area a few miles across.
 */
function airdefender_point_in_area(float $lat, float $lon, array $points): bool
{
    $k = cos(deg2rad($lat));
    $inside = false;
    $n = count($points);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$yi, $xi] = [$points[$i][0], $points[$i][1] * $k];
        [$yj, $xj] = [$points[$j][0], $points[$j][1] * $k];
        if (($yi > $lat) !== ($yj > $lat)) {
            $x = ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi;
            if ($lon * $k < $x) {
                $inside = !$inside;
            }
        }
    }
    return $inside;
}

/*
 * Mark each aircraft that is parked or taxiing in an ignore area: 'ignored' is
 * the area's name. $planes have their height above the show already (altFt);
 * $elevationFt puts back the height the aircraft reported, which is what an
 * area's ground height is measured in.
 */
function airdefender_ignore_apply(array $planes, float $elevationFt, array $areas): array
{
    if ($areas === []) {
        return $planes;
    }
    foreach ($planes as $i => $p) {
        $gs = $p['info']['gs'] ?? null;
        if (!empty($p['onGround']) || $p['altFt'] === null || $gs === null) {
            continue;
        }
        $reported = $p['altFt'] + $elevationFt;
        foreach ($areas as $a) {
            if ($gs < $a['maxKt'] && $reported - $a['groundFt'] <= $a['ceilingFt']
                && airdefender_point_in_area((float) $p['lat'], (float) $p['lon'], $a['points'])) {
                $planes[$i]['ignored'] = $a['name'];
                $planes[$i]['ignoredAboveFt'] = (int) round($reported - $a['groundFt']);
                break;
            }
        }
    }
    return $planes;
}

/*
 * Log when an aircraft starts and stops being ignored (not every check).
 * $was is id => area from the last check; returns the new map.
 */
function airdefender_ignore_log_changes(array $was, array $planes, callable $log): array
{
    $now = [];
    $seen = [];
    foreach ($planes as $p) {
        $seen[$p['id']] = true;
        if (isset($p['ignored'])) {
            $now[$p['id']] = $p['ignored'];
            if (($was[$p['id']] ?? null) !== $p['ignored']) {
                $log("Ignoring {$p['id']}, parked in {$p['ignored']} ({$p['ignoredAboveFt']} ft above its ground, "
                    . ($p['info']['gs'] ?? '?') . " kt).");
            }
        }
    }
    foreach ($was as $id => $area) {
        if (!isset($now[$id]) && isset($seen[$id])) {
            $log("No longer ignoring {$id}: it is moving or climbing in or out of {$area}.");
        }
    }
    return $now;
}

/* ---------- the settings page's requests ---------- */

function airdefender_ignore_read_file(): array
{
    $data = json_decode((string) @file_get_contents(airdefender_ignore_file()), true);
    $areas = [];
    foreach ((is_array($data['areas'] ?? null) ? $data['areas'] : []) as $a) {
        [$ok] = airdefender_ignore_check_area($a);
        if ($ok !== null) {
            $areas[] = $ok;
        }
    }
    return $areas;
}

/*
 * ignore_get, ignore_save, ignore_delete. Saving or deleting runs under one
 * lock, so two browsers can't lose each other's changes. A save must say which
 * area it replaces ('' for a new one) and a delete must name the area: if the
 * file has changed since the page read it, nothing changes (409).
 * Returns [http code, answer].
 */
function airdefender_ignore_request(array $req, string $from): array
{
    $action = (string) ($req['action'] ?? '');
    if ($action === 'ignore_get') {
        return [200, ['ok' => true, 'areas' => airdefender_ignore_read_file(), 'limits' => airdefender_ignore_limits(),
            'maxAreas' => AIRDEFENDER_IGNORE_MAX_AREAS]];
    }
    $lock = airdefender_deadzones_dir() . '/ignore_areas.lock';
    return airdefender_locked($lock, function () use ($req, $action, $from) {
        $areas = airdefender_ignore_read_file();
        $names = array_column($areas, 'name');
        if ($action === 'ignore_delete') {
            $name = (string) ($req['name'] ?? '');
            $at = array_search($name, $names, true);
            if ($at === false) {
                return [409, ['ok' => false, 'error' => 'That area has changed or is gone; reload the page.']];
            }
            array_splice($areas, $at, 1);
            $verb = 'deleted';
        } elseif ($action === 'ignore_save') {
            [$area, $why] = airdefender_ignore_check_area(json_decode((string) ($req['area'] ?? ''), true));
            if ($area === null) {
                return [400, ['ok' => false, 'error' => ucfirst($why) . '.']];
            }
            $replaces = (string) ($req['replaces'] ?? '');
            $at = $replaces === '' ? false : array_search($replaces, $names, true);
            if ($replaces !== '' && $at === false) {
                return [409, ['ok' => false, 'error' => 'That area has changed or is gone; reload the page.']];
            }
            $clash = array_search($area['name'], $names, true);
            if ($clash !== false && $clash !== $at) {
                return [409, ['ok' => false, 'error' => "There is already an area called {$area['name']}."]];
            }
            if ($at === false && count($areas) >= AIRDEFENDER_IGNORE_MAX_AREAS) {
                return [400, ['ok' => false, 'error' => 'Up to ' . AIRDEFENDER_IGNORE_MAX_AREAS . ' areas.']];
            }
            if ($at === false) {
                $areas[] = $area;
            } else {
                $areas[$at] = $area;
            }
            $name = $area['name'];
            $verb = $at === false ? 'added' : 'changed';
        } else {
            return [400, ['ok' => false, 'error' => 'Unknown action']];
        }
        $json = json_encode(['areas' => array_values($areas)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!airdefender_write_atomic(airdefender_ignore_file(), $json . "\n")) {
            return [500, ['ok' => false, 'error' => 'Could not save the areas.']];
        }
        airdefender_write_log("Ignore area {$name} {$verb} (from {$from}).");
        return [200, ['ok' => true, 'areas' => array_values($areas)]];
    });
}
