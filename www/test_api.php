<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * AirDefender dashboard endpoint.
 *
 * Called as plugin.php?plugin=AirDefender&page=www/test_api.php&nopage=1
 * (nopage=1 stops FPP wrapping the response in its page header and menu).
 *
 *   GET [view=detailed]                  -> test status, live status, Last Event
 *                                           (view=detailed adds nearby aircraft)
 *   POST action=start&duration=<seconds> -> start a test
 *   POST action=stop                     -> end a test early
 *   POST action=clear_log                -> empty the Air Defender log
 *   POST action=override&minutes=<n>     -> check for aircraft now, for n minutes
 *   POST action=override_off             -> end that early
 *   POST action=elevation&lat=&lon=        -> ground elevation there, in feet (Settings'
 *                                           Look it up), from api.open-meteo.com
 *   POST action=feed_test&url=&lat=&lon=&nm=&ft=&elev=&receiver=
 *                                        -> ask a flight-data service once (Settings'
 *                                           Test connection); only offered services
 *   POST action=set_password&password=   -> save (or with '' remove) a Remote's copy
 *                                           of the Player's UI password
 *
 *   POST action=aim_start&head=<n> | aim_set&pan=&tilt=&beam=&level= | aim_keep | aim_end
 *                                        -> DeadZones aiming (scripts/airdefender_aim.php)
 *   POST action=zones_get | zone_save | zone_delete | tilt_limit | landmark_set | realign | realign_undo | zones_import
 *                                        -> DeadZones zones (scripts/airdefender_deadzones.php)
 *   GET ...&log=today|yesterday|week&what=events|all -> log lines for View log
 *
 * Anything that changes state is POST only.
 */

require_once(dirname(__DIR__) . "/scripts/airdefender_status.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $method === 'POST' ? ($_POST['action'] ?? '') : 'status';

// GET ...&log=today|yesterday|week&what=events|all: the dashboard's View log (read-only).
if ($method === 'GET' && isset($_GET['log'])) {
    $range = (string) $_GET['log'];
    $todayStart = strtotime('today');
    if ($range === 'yesterday') {
        [$from, $to] = [strtotime('yesterday'), $todayStart - 1];
    } elseif ($range === 'week') {
        [$from, $to] = [$todayStart - 6 * 86400, time()];
    } else {
        [$range, $from, $to] = ['today', $todayStart, time()];
    }
    $eventsOnly = ($_GET['what'] ?? 'events') !== 'all';
    $lines = airdefender_read_log($from, $to, $eventsOnly);
    echo json_encode([
        'range' => $range,
        'from' => $from,
        'lines' => $lines,
        'closedCount' => count(array_filter($lines, function ($l) { return strpos($l['text'], 'SAFETY ON') === 0; })),
        'truncated' => count($lines) >= 1500,
    ]);
    exit;
}

if (in_array($action, ['zones_get', 'zone_save', 'zone_delete', 'tilt_limit', 'landmark_set', 'realign', 'realign_undo', 'zones_import', 'heads_reorder'], true)) {
    require_once(dirname(__DIR__) . "/scripts/airdefender_deadzones.php");
    [$code, $answer] = airdefender_zones_request($_POST);
    http_response_code($code);
    echo json_encode($answer);
    exit;
}
if (strpos($action, 'aim_') === 0) {
    require_once(dirname(__DIR__) . "/scripts/airdefender_aim.php");
    [$code, $answer] = airdefender_aim_request($_POST);
    http_response_code($code);
    echo json_encode($answer);
    exit;
}

switch ($action) {
    case 'start':
        $status = airdefender_test_start($_POST['duration'] ?? 0);
        if (!$status['active']) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not save the test state. Check that the plugin folder is writable.']);
            exit;
        }
        break;
    case 'stop':
        $status = airdefender_test_stop();
        break;
    case 'clear_log':
        if (!airdefender_clear_log()) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not clear the log. Check that the log file is writable.']);
            exit;
        }
        $status = airdefender_test_status();
        break;
    case 'override':
        if (airdefender_override_start($_POST['minutes'] ?? 60) === null) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not save the override. Check that the plugin folder is writable.']);
            exit;
        }
        $status = airdefender_test_status();
        break;
    case 'override_off':
        airdefender_override_stop();
        $status = airdefender_test_status();
        break;
    case 'elevation':
        require_once(dirname(__DIR__) . "/scripts/airdefender_adsb.php");
        $lat = $_POST['lat'] ?? null;
        $lon = $_POST['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            echo json_encode(['ok' => false, 'error' => 'Set the show location first.']);
            exit;
        }
        [$feet, $error] = airdefender_lookup_elevation((float) $lat, (float) $lon);
        echo json_encode($feet === null ? ['ok' => false, 'error' => $error] : ['ok' => true, 'feet' => $feet]);
        exit;
    case 'feed_test':
        require_once(dirname(__DIR__) . "/scripts/airdefender_adsb.php");
        require_once(dirname(__DIR__) . "/scripts/airdefender_feeds.php");
        require_once(dirname(__DIR__) . "/scripts/airdefender_receiver.php");
        $template = (string) ($_POST['url'] ?? '');
        // Only the services Settings offers: never an arbitrary address.
        if (!array_key_exists($template, airdefender_feed_options())) {
            http_response_code(400);
            echo json_encode(['error' => 'Pick one of the listed services.']);
            exit;
        }
        $lat = (float) ($_POST['lat'] ?? 0);
        $lon = (float) ($_POST['lon'] ?? 0);
        $nm = max(0.1, (float) ($_POST['nm'] ?? 5));
        $ft = max(0, (float) ($_POST['ft'] ?? 3000));
        if (!is_numeric($_POST['lat'] ?? null) || !is_numeric($_POST['lon'] ?? null) || abs($lat) > 90 || abs($lon) > 180) {
            echo json_encode(['ok' => false, 'error' => 'Set the show location first.']);
            exit;
        }
        $started = microtime(true);
        $foundAt = null;
        if ($template === AIRDEFENDER_FEED_RECEIVER) {
            // Your receiver: the address typed on the page (only home-network addresses are read).
            [$planes, $error] = airdefender_fetch_receiver((string) ($_POST['receiver'] ?? ''), $foundAt);
        } else {
            $url = airdefender_feed_url($template, $lat, $lon, $nm);
            [$planes, $error] = airdefender_is_receiver_feed($template)
                ? airdefender_fetch_feed($url, AIRDEFENDER_RECEIVER_MAX_AGE_SEC, 4)
                : airdefender_fetch_feed($url);
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($planes === null) {
            echo json_encode(['ok' => false, 'error' => $error, 'ms' => $ms]);
            exit;
        }
        $planes = airdefender_above_show($planes, airdefender_clamp_elevation($_POST['elev'] ?? 0));
        $airborne = 0;
        $inside = 0;
        $nearest = null;
        foreach ($planes as $p) {
            if ($p['onGround']) {
                continue;
            }
            $airborne++;
            $d = airdefender_distance_nm($lat, $lon, $p['lat'], $p['lon']);
            if ($d <= $nm && $p['altFt'] !== null && $p['altFt'] <= $ft) {
                $inside++;
            }
            if ($nearest === null || $d < $nearest['nm']) {
                $nearest = ['id' => $p['id'], 'nm' => round($d, 1), 'ft' => $p['altFt'] !== null ? (int) round($p['altFt']) : null];
            }
        }
        echo json_encode(['ok' => true, 'aircraft' => $airborne, 'inside' => $inside, 'nearest' => $nearest, 'ms' => $ms, 'foundAt' => $foundAt]);
        exit;
    case 'set_password':
        // A Remote's copy of the Player's UI password ('' removes it). Kept in
        // plugindata at 0600, not in FPP's config folder, so it is not saved
        // with WriteSettingToFile like the other settings.
        require_once(dirname(__DIR__) . "/scripts/airdefender_secret.php");
        $password = (string) ($_POST['password'] ?? '');
        if (!airdefender_set_master_password($password)) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not save the password in ' . airdefender_plugindata_dir() . '. Check file permissions.']);
            exit;
        }
        echo json_encode(['ok' => true, 'saved' => $password !== '']);
        exit;
    case 'status':
        $status = airdefender_test_status();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
}

$live = airdefender_live_status(($_GET['view'] ?? '') === 'detailed');
// The loop's own safety state, apart from a test (the test shows separately).
$status['loopSafety'] = $live['safe'] && $live['reason'] !== 'test';
$status['live'] = $live;
$status['lastEvent'] = airdefender_last_event();

echo json_encode($status);
