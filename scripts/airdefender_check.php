<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Air Defender command-line check: asks the flight-data service once, the
 * same way the detection loop does, and prints what it sees. Handy over SSH
 * when the dashboard says something is wrong.
 *
 * With no options it uses your saved settings (service, location, limits):
 *   php /home/fpp/media/plugins/AirDefender/scripts/airdefender_check.php
 *   (or wherever your media folder is)
 *
 * Any of them can be overridden:
 *   --url "https://api.adsb.lol/v2/point/{lat}/{lon}/{radius}"
 *   --lat 34.685 --lon -82.953 --distance 5 --altitude 3000 --elevation 800
 *   --receiver 192.168.1.50   (reads your own receiver instead)
 *
 * Heights are shown above the show, using the show elevation from Settings.
 *
 * Exit code: 0 clear, 1 an aircraft is inside the limits, 2 could not check.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once(__DIR__ . "/airdefender_paths.php");
require_once(__DIR__ . "/airdefender_adsb.php");
require_once(__DIR__ . "/airdefender_feeds.php");
require_once(__DIR__ . "/airdefender_receiver.php");

$opts = getopt('', ['url:', 'lat:', 'lon:', 'distance:', 'altitude:', 'elevation:', 'receiver:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php airdefender_check.php [--url TEMPLATE] [--lat N] [--lon N] [--distance NM] [--altitude FT] [--elevation FT] [--receiver ADDRESS]\n";
    exit(0);
}

$settings = airdefender_read_kv_file(airdefender_config_file());

$useFpp = !in_array(strtolower($settings['useFPPLocation'] ?? '1'), ['0', 'false', 'no', 'off'], true);
[$fppLat, $fppLon] = $useFpp ? airdefender_fpp_location() : [null, null];
$lat = $opts['lat'] ?? ($useFpp ? ($fppLat ?? '') : ($settings['latitude'] ?? ''));
$lon = $opts['lon'] ?? ($useFpp ? ($fppLon ?? '') : ($settings['longitude'] ?? ''));
$maxNm = min(15.0, max(0.1, (float) ($opts['distance'] ?? ($settings['distanceNM'] ?? 5))));   // as the loop uses it
$maxFt = (float) ($opts['altitude'] ?? ($settings['altitudeThresholdFt'] ?? 3000));
$elevationFt = airdefender_clamp_elevation($opts['elevation'] ?? ($settings['showElevationFt'] ?? 0));
[$template] = airdefender_effective_feed($opts['url'] ?? ($settings['adsbSourceUrl'] ?? ''));
$receiver = $opts['receiver'] ?? null;
if ($receiver !== null) {
    $template = AIRDEFENDER_FEED_RECEIVER;
} elseif ($template === AIRDEFENDER_FEED_RECEIVER) {
    $receiver = (string) ($settings['receiverAddress'] ?? '');
}

if (!is_numeric($lat) || !is_numeric($lon)) {
    fwrite(STDERR, "No show location: set it in FPP or Air Defender settings, or pass --lat and --lon.\n");
    exit(2);
}
$lat = (float) $lat;
$lon = (float) $lon;

echo "Limits: {$maxNm} NM, {$maxFt} ft" . ($elevationFt ? " above the show (show at {$elevationFt} ft above sea level)" : "") . "\n";
if ($template === AIRDEFENDER_FEED_RECEIVER) {
    echo "Looking for your receiver at {$receiver}\n";
    $found = null;
    [$planes, $error] = airdefender_fetch_receiver($receiver, $found);
    if ($found !== null) {
        echo "Reading {$found}\n";
    }
} else {
    $url = airdefender_feed_url($template, $lat, $lon, $maxNm);
    echo "Asking {$url}\n";
    [$planes, $error] = airdefender_is_receiver_feed($template)
        ? airdefender_fetch_feed($url, AIRDEFENDER_RECEIVER_MAX_AGE_SEC, 4)
        : airdefender_fetch_feed($url);
}
echo "\n";
if ($planes === null) {
    fwrite(STDERR, "Could not check: {$error}\n");
    exit(2);
}
$planes = airdefender_above_show($planes, $elevationFt);

$rows = [];
foreach ($planes as $p) {
    $d = airdefender_distance_nm($lat, $lon, $p['lat'], $p['lon']);
    $inside = !$p['onGround'] && $d <= $maxNm && $p['altFt'] !== null && $p['altFt'] <= $maxFt;
    $rows[] = [$d, $p, $inside];
}
usort($rows, function ($a, $b) { return $a[0] <=> $b[0]; });

$hits = 0;
foreach ($rows as [$d, $p, $inside]) {
    $alt = $p['onGround'] ? 'ground' : ($p['altFt'] === null ? '?' : number_format($p['altFt']) . ' ft');
    printf("  %-9s %6.2f NM  %10s  %s\n", $p['id'], $d, $alt, $inside ? 'INSIDE LIMITS' : '');
    $hits += $inside ? 1 : 0;
}
if (!$rows) {
    echo "  No aircraft in range.\n";
}

echo "\n" . ($hits ? "TRIGGER: {$hits} aircraft inside the limits; heads would close.\n" : "CLEAR: no aircraft inside the limits.\n");
exit($hits ? 1 : 0);
