<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * What the dashboard shows about the detection loop right now, read from the
 * state.json it writes whenever something changes (and every 30 s anyway),
 * plus clearing the log. FPP rotates the log itself, as its plugin
 * guidelines ask: only once it reaches 10 MB, which takes a very long time,
 * so its lines (callsigns included) stay until Clear log empties it.
 */

require_once(__DIR__ . "/airdefender_test.php");
require_once(__DIR__ . "/airdefender_statefile.php");

// The loop counts as stopped once state.json is this old: two missed
// heartbeats plus the longest a feed can keep it waiting (a receiver search
// that times out on every address), so a slow feed shows as a feed problem.
const AIRDEFENDER_LOOP_STALE_SEC = 120;

/*
 * Whether the detection loop that wrote $state is still running: its process
 * is there, and it has written in the last AIRDEFENDER_LOOP_STALE_SEC.
 */
function airdefender_loop_running(array $state, ?int $now = null): bool
{
    $now = $now ?? time();
    $updated = isset($state['lastUpdated']) ? strtotime((string) $state['lastUpdated']) : false;
    if ($updated === false || $now - $updated > AIRDEFENDER_LOOP_STALE_SEC) {
        return false;
    }
    $pid = (int) ($state['pid'] ?? 0);
    if ($pid > 0 && is_dir('/proc/self')) {
        $cmd = @file_get_contents("/proc/{$pid}/cmdline");
        return $cmd !== false && strpos($cmd, 'airdefender_loop.php') !== false;
    }
    return true;
}

/* The loop's event text, with the countdown it describes worked out now. */
function airdefender_event_now(array $state, ?int $now = null): string
{
    $now = $now ?? time();
    $event = (string) ($state['lastEvent'] ?? '');
    if (!empty($state['holdUntil'])) {
        $event .= ' for ' . max(0, (int) $state['holdUntil'] - $now) . ' s more';
    } elseif (!empty($state['testUntil'])) {
        $event .= ', ' . max(0, (int) $state['testUntil'] - $now) . ' s left';
    }
    return $event;
}

/*
 * $detailed adds the nearby aircraft for the radar; the Lite view leaves
 * them out to keep each refresh small.
 */
function airdefender_live_status(bool $detailed = false): array
{
    $now = time();
    $state = airdefender_state_read();
    $state = is_array($state) ? $state : [];

    $closedChannels = array_map('intval', array_keys(is_array($state['appliedChannels'] ?? null) ? $state['appliedChannels'] : []));
    $heads = [];
    foreach ((is_array($state['heads'] ?? null) ? $state['heads'] : []) as $n => $h) {
        $channel = isset($h['channel']) ? (int) $h['channel'] : null;
        $heads[] = [
            'id' => (int) $n,
            'name' => (string) ($h['name'] ?? "MH{$n}"),
            'channel' => $channel,
            'closed' => $channel !== null && in_array($channel, $closedChannels, true),
            'problem' => (string) ($h['problem'] ?? ''),
        ];
    }

    $lastOk = isset($state['lastFetchOk']) ? strtotime($state['lastFetchOk']) : false;
    $master = is_array($state['master'] ?? null) ? $state['master'] : null;
    $since = isset($state['safeSince']) ? strtotime($state['safeSince']) : false;
    $limits = is_array($state['limits'] ?? null) ? $state['limits'] : [];
    $masterOk = $master && !empty($master['lastOk']) ? strtotime($master['lastOk']) : false;

    return [
        'loopRunning' => airdefender_loop_running($state, $now),
        'safe' => !empty($state['safetyMode']),
        'reason' => (string) ($state['reason'] ?? ''),
        'now' => airdefender_event_now($state, $now),
        'role' => ($state['role'] ?? 'master') === 'remote' ? 'remote' : 'master',
        'feed' => [
            'aircraft' => isset($state['aircraftCount']) ? (int) $state['aircraftCount'] : null,
            'lastOkAgo' => $lastOk ? max(0, $now - $lastOk) : null,
            'lastOkAgoMs' => $lastOk ? max(0, (int) round((microtime(true) - $lastOk) * 1000)) : null,
            'intervalSec' => isset($state['fetchInterval']) ? (int) $state['fetchInterval'] : null,
            'error' => $state['feedError'] ?? null,
            'used' => $state['feedUsed'] ?? null,
            'onBackup' => !empty($state['feedOnBackup']),
            'chosen' => $state['feedChosen'] ?? null,
        ],
        'master' => $master ? [
            'address' => $master['address'] ?? null,
            'connected' => !empty($master['connected']),
            'lastOkAgo' => $masterOk ? max(0, $now - $masterOk) : null,
            'error' => $master['error'] ?? null,
        ] : null,
        'heads' => $heads,
        'lastAircraft' => is_array($state['lastAircraft'] ?? null) ? $state['lastAircraft'] : null,
        'outputError' => $state['outputError'] ?? null,
        'enabled' => !array_key_exists('enabled', $state) || !empty($state['enabled']),
        'safeSince' => $since ?: null,
        'limits' => [
            'nm' => isset($limits['nm']) ? (float) $limits['nm'] : null,
            'ft' => isset($limits['ft']) ? (int) $limits['ft'] : null,
            'holdSec' => isset($limits['holdSec']) ? (int) $limits['holdSec'] : null,
            'elevationFt' => isset($limits['elevationFt']) ? (int) $limits['elevationFt'] : 0,
        ],
        'aircraft' => $detailed && is_array($state['nearby'] ?? null) ? $state['nearby'] : null,
        'checking' => !array_key_exists('checking', $state) || !empty($state['checking']),
        'checkWhen' => (string) ($state['checkWhen'] ?? 'scheduled'),
        // Read directly, so the dashboard shows a new override at once.
        'overrideUntil' => airdefender_override_until(),
        'nextShow' => is_array($state['nextShow'] ?? null) ? $state['nextShow'] : null,
        'skippedPlaylist' => isset($state['skippedPlaylist']) ? (string) $state['skippedPlaylist'] : null,
        'playlistFilter' => !empty($state['playlistFilter']),
    ];
}

/*
 * Empty the Air Defender log, its rotated copies and the Last Event, which
 * hold callsigns of aircraft that passed nearby. Returns false if the log
 * could not be emptied.
 */
function airdefender_clear_log(): bool
{
    $ok = true;
    if (file_exists(airdefender_log_file())) {
        $fh = @fopen(airdefender_log_file(), 'c');
        if ($fh && flock($fh, LOCK_EX)) {
            ftruncate($fh, 0);
            flock($fh, LOCK_UN);
        } else {
            $ok = false;
        }
        if ($fh) {
            fclose($fh);
        }
    }
    foreach (glob(airdefender_log_file() . ".*") ?: [] as $rotated) {
        @unlink($rotated);
    }
    @unlink(airdefender_last_event_file());
    if ($ok) {
        airdefender_test_log("Log cleared from the dashboard.");
    }
    return $ok;
}

/*
 * Log lines between $from and $to (Unix times), newest first: each as
 * ['time' => int, 'text' => string, 'event' => bool]. $eventsOnly keeps just
 * safety turning on/off and tests. Reads FPP's rotated copies too (".1", and
 * older ones gzip-compressed), if the log has ever grown enough to rotate.
 * At most $max lines, so a busy week can't swamp the page.
 */
function airdefender_read_log(int $from, int $to, bool $eventsOnly, int $max = 1500): array
{
    $files = [];
    foreach (glob(airdefender_log_file() . '.*') ?: [] as $f) {
        if (preg_match('/\.(\d+)(\.gz)?$/', $f, $m)) {
            $files[(int) $m[1]] = $f;
        }
    }
    krsort($files);                          // oldest first: .7.gz ... .1
    $files[] = airdefender_log_file();
    $from = date('Y-m-d H:i:s', $from);
    $to = date('Y-m-d H:i:s', $to);

    $out = [];
    foreach ($files as $f) {
        $fh = @fopen((substr($f, -3) === '.gz' ? 'compress.zlib://' : '') . $f, 'r');
        if (!$fh) {
            continue;
        }
        while (($line = fgets($fh)) !== false) {
            if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] (.*)$/', rtrim($line, "\r\n"), $m)
                || $m[1] < $from || $m[1] > $to) {
                continue;
            }
            $event = (bool) preg_match('/^(SAFETY (ON|OFF)\b|Test (started|finished|stopped)\b)/', $m[2]);
            if ($eventsOnly && !$event) {
                continue;
            }
            $out[] = ['time' => strtotime($m[1]), 'text' => $m[2], 'event' => $event];
        }
        fclose($fh);
    }
    $out = array_reverse($out);
    return array_slice($out, 0, $max);
}
