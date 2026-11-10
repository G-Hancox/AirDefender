<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Start or stop a test from the command line. Used by the FPP commands in
 * commands/ (Air Defender: Start Test / Stop Test).
 *
 *   php airdefender_test_cli.php start <seconds>
 *   php airdefender_test_cli.php stop
 *   php airdefender_test_cli.php checknow <minutes>   (0 ends it)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once(__DIR__ . "/airdefender_test.php");

$action = $argv[1] ?? '';
if ($action === 'start') {
    $status = airdefender_test_start($argv[2] ?? 0);
    echo $status['active'] ? "Test started for {$status['duration']} s\n" : "Could not start the test\n";
    exit($status['active'] ? 0 : 1);
}
if ($action === 'stop') {
    airdefender_test_stop();
    echo "Test stopped\n";
    exit(0);
}

if ($action === 'checknow') {
    $minutes = (int) ($argv[2] ?? 60);
    if ($minutes <= 0) {
        airdefender_override_stop();
        echo "Tracking stopped\n";
        exit(0);
    }
    $until = airdefender_override_start($minutes);
    echo $until ? "Checking for aircraft until " . date('H:i', $until) . "\n" : "Could not start tracking\n";
    exit($until ? 0 : 1);
}

fwrite(STDERR, "Usage: php airdefender_test_cli.php start <seconds> | stop | checknow <minutes>\n");
exit(2);
