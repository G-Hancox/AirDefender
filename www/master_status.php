<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * AirDefender master status, polled once a second by every Remote.
 *
 *   GET plugin.php?plugin=AirDefender&page=www/master_status.php&nopage=1
 *
 * Answers whether this FPP wants safety mode on (an aircraft, the hold after
 * one, or a test), plus whether its detection loop is running, so a remote
 * can tell a quiet master from a dead one. Read-only.
 */

require_once(dirname(__DIR__) . "/scripts/airdefender_status.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

$now = time();

// Not $pluginSettings: that is FPP's, and this page runs inside FPP's.
$airdefenderConfig = airdefender_read_kv_file(airdefender_config_file());
$role = strtolower(($airdefenderConfig['nodeRole'] ?? '') !== '' ? $airdefenderConfig['nodeRole'] : airdefender_fpp_role()) === 'remote' ? 'remote' : 'master';

$state = airdefender_state_read();
$state = is_array($state) ? $state : [];
$updated = isset($state['lastUpdated']) ? strtotime($state['lastUpdated']) : false;

// Read the test directly rather than from state.json, so a test reaches the
// remotes without waiting a second for the loop to notice it.
$test = airdefender_test_status();

echo json_encode([
    'plugin' => 'AirDefender',
    'role' => $role,
    'safe' => $test['active'] || !empty($state['safetyMode']),
    'reason' => $test['active'] ? 'test' : ($state['reason'] ?? 'unknown'),
    'event' => $test['active'] ? "Test running" : (string) ($state['lastEvent'] ?? ''),
    'holdUntil' => $test['active'] ? null : ($state['holdUntil'] ?? null),
    'testActive' => $test['active'],
    'testRemaining' => $test['remaining'],
    'loopRunning' => airdefender_loop_running($state, $now),
    'loopAge' => $updated ? $now - $updated : null,
    'serverTime' => $now,
]);
