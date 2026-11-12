<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * DeadZones, the PHP side. The compiled part (src/AirDefenderPlugin.cpp, loaded
 * into fppd) does the per-frame check; all it knows comes from one file this
 * writes, plugindata/AirDefender/deadzones.json: whether DeadZones is on, and
 * for each head its shutter, pan and tilt channels, how fast it turns, and its
 * zones. The detection loop keeps that file up to date, writing it only when
 * something in it changes (a setting, a zone, a head re-patched), so the SD
 * card is left alone the rest of the time.
 *
 * The zones themselves are the user's, kept in plugindata/AirDefender/zones.json:
 *   { "margin": 3, "holdMs": 250,
 *     "heads": { "1": { "tiltMin": 0, "tiltMax": 255,
 *                       "zones": [ { "name": "House", "points": [[100,50],[150,50],[150,100]],
 *                                    "alsoCloses": [2] } ] } } }
 * Points are pan and tilt channel values (0-255) of that head.
 */

require_once(__DIR__ . '/airdefender_paths.php');

if (!function_exists('airdefender_deadzones_file')) {

    /*
     * Where DeadZones keeps its files: FPP's plugindata/AirDefender folder, made if
     * needed. If that can't be written (seen in FPP's Docker image, where the
     * plugindata folder belongs to root), the plugin's own folder instead. The
     * compiled part looks in both.
     */
    function airdefender_deadzones_dir(): string
    {
        static $dir = null;
        if ($dir === null) {
            $data = airdefender_plugindata_dir();
            if (!is_dir($data)) {
                @mkdir(dirname($data), 0755, true);   // FPP's plugindata folder
                @mkdir($data, 0700);
            }
            $dir = is_dir($data) && is_writable($data) ? $data : dirname(__DIR__);
        }
        return $dir;
    }

    function airdefender_deadzones_file(): string
    {
        return airdefender_deadzones_dir() . '/deadzones.json';
    }

    function airdefender_deadzones_zones_file(): string
    {
        return airdefender_deadzones_dir() . '/zones.json';
    }

    /* The user's zones, or an empty set. Read again only when the file changes. */
    function airdefender_deadzones_zones(): array
    {
        static $cache = [], $stamp = null;
        $file = airdefender_deadzones_zones_file();
        clearstatcache(true, $file);
        $now = @filemtime($file) . ':' . @filesize($file);
        if ($now !== $stamp) {
            $stamp = $now;
            $data = json_decode((string) @file_get_contents($file), true);
            $cache = is_array($data) ? $data : [];
        }
        return $cache;
    }

    /*
     * What the compiled part needs, from the settings, the user's zones and the
     * heads as resolved by airdefender_resolve_heads() (so a head re-patched in
     * xLights is followed here too). Only heads that are on, have a shutter
     * channel and have both pan and tilt set are included.
     */
    function airdefender_deadzones_runtime(array $settings, array $resolved, array $zones): array
    {
        $on = function ($key) use ($settings) {
            return in_array(strtolower(trim((string) ($settings[$key] ?? '0'))), ['1', 'true', 'yes', 'on', 'y'], true);
        };
        $int = function ($key, $default, $min, $max) use ($settings) {
            $v = (int) ($settings[$key] ?? 0);
            return $v >= $min && $v <= $max ? $v : $default;
        };
        $out = [
            // Air Defender's own switch turns everything off, DeadZones included.
            'enabled' => $on('enabled') && $on('deadZonesEnabled'),
            'margin' => max(0, min(50, (float) ($zones['margin'] ?? 3))),
            'holdMs' => max(0, min(5000, (int) ($zones['holdMs'] ?? 250))),
            'heads' => [],
        ];
        foreach ($resolved as $n => $h) {
            $offset = (int) ($settings["head{$n}Offset"] ?? 1);
            $pan = $int("head{$n}PanCh", 0, 1, 512);
            $tilt = $int("head{$n}TiltCh", 0, 1, 512);
            if (empty($h['enabled']) || empty($h['channel']) || $offset < 1 || !$pan || !$tilt) {
                continue;
            }
            // The head's first FPP channel: its shutter's FPP channel, less where the shutter is in the fixture.
            $start = (int) $h['channel'] - $offset + 1;
            if ($start < 1) {
                continue;
            }
            $mine = is_array($zones['heads'][(string) $n] ?? null) ? $zones['heads'][(string) $n] : [];
            $list = [];
            foreach ((is_array($mine['zones'] ?? null) ? $mine['zones'] : []) as $z) {
                $points = [];
                foreach ((is_array($z['points'] ?? null) ? $z['points'] : []) as $p) {
                    if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                        $points[] = [max(0, min(255, (float) $p[0])), max(0, min(255, (float) $p[1]))];
                    }
                }
                if (count($points) >= 3) {
                    $list[] = [
                        'name' => substr(trim((string) ($z['name'] ?? '')), 0, 40),
                        'points' => $points,
                        'alsoCloses' => array_values(array_filter(array_map('intval', is_array($z['alsoCloses'] ?? null) ? $z['alsoCloses'] : []),
                            function ($id) use ($n) { return $id >= 1 && $id !== (int) $n; })),
                    ];
                }
            }
            $out['heads'][] = [
                'id' => (int) $n,
                'name' => (string) $h['label'],
                'shutter' => (int) $h['channel'],
                'closed' => (int) $h['closed'],
                'pan' => $start + $pan - 1,
                'tilt' => $start + $tilt - 1,
                'panRange' => $int("head{$n}PanRange", 540, 1, 3600),
                'panSlew' => $int("head{$n}PanSlew", 180, 1, 3600),
                'tiltRange' => $int("head{$n}TiltRange", 270, 1, 3600),
                'tiltSlew' => $int("head{$n}TiltSlew", 180, 1, 3600),
                // For aiming (scripts/airdefender_aim.php): dimmer FPP channel (0 if not set) and the shutter's open value.
                'dimmer' => ($dim = $int("head{$n}DimmerCh", 0, 1, 512)) ? $start + $dim - 1 : 0,
                'open' => max(0, min(255, (int) ($settings["head{$n}ShutterOpen"] ?? 255))),
                'tiltMin' => max(0, min(255, (float) ($mine['tiltMin'] ?? 0))),
                'tiltMax' => max(0, min(255, (float) ($mine['tiltMax'] ?? 255))),
                'zones' => $list,
            ];
        }
        return $out;
    }

    /*
     * Keep deadzones.json matching the settings. Called by the loop every
     * second; writes only when the content differs from what is on disk. A
     * setup that has never turned DeadZones on never gets the file at all.
     */
    function airdefender_deadzones_sync(array $settings, array $resolved): void
    {
        static $last = null, $failed = false;
        $file = airdefender_deadzones_file();
        $runtime = airdefender_deadzones_runtime($settings, $resolved, airdefender_deadzones_zones());
        $json = json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === $last) {
            return;
        }
        if ($last === null) {
            $onDisk = @file_get_contents($file);
            if ($onDisk === $json || ($onDisk === false && !$runtime['enabled'])) {
                $last = $json;
                return;
            }
        }
        // Written to a temp file and renamed, so fppd never reads half a file.
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json) !== false && @rename($tmp, $file)) {
            $last = $json;
            $failed = false;
        } elseif (empty($failed)) {
            $failed = true;   // said once, not every second
            if (function_exists('airdefender_log')) {
                airdefender_log("DeadZones: could not write {$file}; DeadZones can't use the latest settings until it can.");
            }
        }
    }
}

if (!function_exists('airdefender_zones_request')) {

    define('AIRDEFENDER_ZONES_PER_HEAD', 16);
    define('AIRDEFENDER_ZONE_POINTS', 64);

    /* Save the user's zones (zones.json), only when they change something on the Zones page. */
    function airdefender_zones_write(array $z): bool
    {
        $file = airdefender_deadzones_zones_file();
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($z, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return false;
        }
        @chmod($tmp, 0644);
        return @rename($tmp, $file);
    }

    /* One head's zones and tilt limits, tidied. */
    function airdefender_zones_head(array $z, int $id): array
    {
        $h = is_array($z['heads'][(string) $id] ?? null) ? $z['heads'][(string) $id] : [];
        return [
            'tiltMin' => max(0, min(255, (int) ($h['tiltMin'] ?? 0))),
            'tiltMax' => max(0, min(255, (int) ($h['tiltMax'] ?? 255))),
            'zones' => array_values(is_array($h['zones'] ?? null) ? $h['zones'] : []),
        ];
    }

    /*
     * The Zones page's requests. $req: action and its fields.
     *   zones_get                                    -> every head's zones and tilt limits
     *   zone_save   head, name, points (JSON [[pan,tilt],...]), alsoCloses (JSON [ids]), index (to replace one; else added)
     *   zone_delete head, index
     *   tilt_limit  head, side (below|above|clear), tilt
     * Returns [http status, answer].
     */
    function airdefender_zones_request(array $req): array
    {
        $action = (string) ($req['action'] ?? '');
        $z = airdefender_deadzones_zones();
        if (!isset($z['heads']) || !is_array($z['heads'])) {
            $z['heads'] = [];
        }
        if ($action === 'zones_get') {
            $out = [];
            for ($n = 1; $n <= 8; $n++) {
                $out[(string) $n] = airdefender_zones_head($z, $n);
            }
            return [200, ['heads' => $out]];
        }
        $id = (int) ($req['head'] ?? 0);
        if ($id < 1 || $id > 8) {
            return [400, ['error' => 'Pick a head first.']];
        }
        $h = airdefender_zones_head($z, $id);
        if ($action === 'zone_save') {
            $points = json_decode((string) ($req['points'] ?? ''), true);
            $clean = [];
            foreach (is_array($points) ? $points : [] as $p) {
                if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                    $clean[] = [max(0, min(255, (int) round($p[0]))), max(0, min(255, (int) round($p[1])))];
                }
            }
            if (count($clean) < 3) {
                return [400, ['error' => 'A zone needs at least 3 points: mark each corner of the area.']];
            }
            if (count($clean) > AIRDEFENDER_ZONE_POINTS) {
                return [400, ['error' => 'A zone can have up to ' . AIRDEFENDER_ZONE_POINTS . ' points.']];
            }
            $also = json_decode((string) ($req['alsoCloses'] ?? '[]'), true);
            $also = array_values(array_unique(array_filter(array_map('intval', is_array($also) ? $also : []),
                function ($o) use ($id) { return $o >= 1 && $o <= 8 && $o !== $id; })));
            $name = trim(preg_replace('/\s+/', ' ', (string) ($req['name'] ?? '')));
            $name = $name !== '' ? $name : 'Zone ' . (count($h['zones']) + 1);
            $name = function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40);
            $zone = ['name' => $name, 'points' => $clean, 'alsoCloses' => $also];
            $index = isset($req['index']) && is_numeric($req['index']) ? (int) $req['index'] : -1;
            if ($index >= 0 && $index < count($h['zones'])) {
                $h['zones'][$index] = $zone;
            } elseif (count($h['zones']) >= AIRDEFENDER_ZONES_PER_HEAD) {
                return [400, ['error' => 'A head can have up to ' . AIRDEFENDER_ZONES_PER_HEAD . ' zones.']];
            } else {
                $h['zones'][] = $zone;
            }
        } elseif ($action === 'zone_delete') {
            $index = (int) ($req['index'] ?? -1);
            if ($index < 0 || $index >= count($h['zones'])) {
                return [400, ['error' => 'That zone is already gone.']];
            }
            array_splice($h['zones'], $index, 1);
        } elseif ($action === 'tilt_limit') {
            $side = (string) ($req['side'] ?? '');
            $tilt = max(0, min(255, (int) ($req['tilt'] ?? 0)));
            if ($side === 'below') {
                $h['tiltMin'] = $tilt;
            } elseif ($side === 'above') {
                $h['tiltMax'] = $tilt;
            } elseif ($side === 'clear') {
                $h['tiltMin'] = 0;
                $h['tiltMax'] = 255;
            } else {
                return [400, ['error' => 'Unknown tilt limit.']];
            }
            if ($h['tiltMin'] >= $h['tiltMax']) {
                return [400, ['error' => "That would block every tilt: the two limits would cross. Clear the tilt limit and set it again."]];
            }
        } else {
            return [400, ['error' => 'Unknown action']];
        }
        $z['heads'][(string) $id] = $h;
        if (!airdefender_zones_write($z)) {
            return [500, ['error' => 'Could not save the zones in ' . airdefender_deadzones_dir() . '. Check file permissions.']];
        }
        return [200, ['head' => $id, 'zones' => $h]];
    }
}
