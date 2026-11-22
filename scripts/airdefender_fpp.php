<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * What Air Defender asks FPP: its settings (location, time zone, Player
 * Mode) and its playlists, through FPP's API, never by reading FPP's files.
 * Inside FPP's own web pages, its $settings already holds the settings, so
 * they come from there.
 *
 * If FPP's API can't be asked (Apache restarting, say), the last answer it
 * gave is used, so the detection loop doesn't lose the show's location over
 * a blip.
 */

require_once(__DIR__ . '/airdefender_http.php');

const AIRDEFENDER_FPP_API = 'http://127.0.0.1/api';

// How long the loop trusts a setting before asking FPP again.
const AIRDEFENDER_FPP_SETTING_TTL_SEC = 60;

/* GET one of FPP's API paths ('/settings/TimeZone', say): decoded JSON, or null. */
function airdefender_fpp_api(string $path, float $timeout = 2.0)
{
    [$status, $body] = airdefender_http(AIRDEFENDER_FPP_API . $path, ['timeout' => $timeout]);
    if ($body === false || $status !== 200) {
        return null;
    }
    return json_decode($body, true);
}

/* Whether this is one of FPP's web pages, where FPP's $settings is loaded. */
function airdefender_in_fpp_page(): bool
{
    return PHP_SAPI !== 'cli' && isset($GLOBALS['settings']) && is_array($GLOBALS['settings'])
        && isset($GLOBALS['settings']['fppMode']);
}

/*
 * One of FPP's settings, or null when FPP has none set. A value equal to the
 * setting's built-in default counts as not set when $defaultIsUnset is true:
 * FPP reports its default location (Colorado) on a player whose location was
 * never entered, and that must not be watched as if it were the show's sky.
 */
function airdefender_fpp_setting(string $name, bool $defaultIsUnset = false): ?string
{
    if (airdefender_in_fpp_page()) {
        // FPP's pages fill in defaults, so read what was really saved.
        $value = $defaultIsUnset && function_exists('ReadSettingFromFile')
            ? ReadSettingFromFile($name) : ($GLOBALS['settings'][$name] ?? false);
        return $value === false || $value === null || (string) $value === '' ? null : (string) $value;
    }

    static $cache = [];
    $now = time();
    if (!isset($cache[$name]) || $now - $cache[$name]['at'] >= AIRDEFENDER_FPP_SETTING_TTL_SEC) {
        $info = airdefender_fpp_api('/settings/' . rawurlencode($name), 2.0);
        if (is_array($info)) {
            $value = isset($info['value']) && is_scalar($info['value']) ? (string) $info['value'] : '';
            if ($defaultIsUnset && isset($info['default']) && (string) $info['default'] === $value) {
                $value = '';
            }
            $cache[$name] = ['at' => $now, 'value' => $value === '' ? null : $value];
        } elseif (isset($cache[$name])) {
            $cache[$name]['at'] = $now - AIRDEFENDER_FPP_SETTING_TTL_SEC + 5;   // last good; ask again soon
        } else {
            return null;
        }
    }
    return $cache[$name]['value'];
}

/* FPP's location as [lat, lon], or [null, null] when it isn't set. */
function airdefender_fpp_location(): array
{
    $lat = airdefender_fpp_setting('Latitude', true);
    $lon = airdefender_fpp_setting('Longitude', true);
    if ($lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)) {
        return [null, null];
    }
    return [(float) $lat, (float) $lon];
}

/*
 * Air Defender's role from FPP's own Player Mode: 'remote' on an FPP in
 * Remote mode, otherwise 'master' (shown as Player). It is only the
 * default: the role saved in Settings wins.
 */
function airdefender_fpp_role(): string
{
    return strtolower((string) airdefender_fpp_setting('fppMode')) === 'remote' ? 'remote' : 'master';
}

/* FPP's playlists by name, sorted, or null when FPP can't be asked. */
function airdefender_fpp_playlists(): ?array
{
    $list = airdefender_fpp_api('/playlists', 3.0);
    if (!is_array($list)) {
        return null;
    }
    $names = array_values(array_filter(array_map('strval', $list), 'strlen'));
    natcasesort($names);
    return array_values($names);
}
