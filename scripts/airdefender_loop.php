<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * AirDefender detection loop.
 *
 * Started through callbacks.sh when fppd starts or loads the plugin, and
 * stopped when fppd stops or unloads it (scripts/airdefender_start.sh, _stop.sh).
 * Every second it decides whether safety mode should be on and makes the
 * outputs match (see airdefender_controller.php). The ADS-B feed is fetched
 * every fetchInterval seconds.
 *
 * Safety mode is on when:
 *   - a test is running (dashboard Test Mode), or
 *   - an aircraft is inside the distance and altitude thresholds, or
 *   - one was, less than clearDelaySec ago (so a plane on the edge of the
 *     radius does not flick the lights on and off every fetch), or
 *   - safety mode was on and the feed has since stopped answering: with no
 *     data there is no way to know the plane has gone, so it stays safe.
 *
 * Settings are re-read every cycle, so changes on the settings page take
 * effect without restarting fppd.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once(__DIR__ . "/airdefender_time.php");
require_once(__DIR__ . "/airdefender_controller.php");
require_once(__DIR__ . "/airdefender_test.php");
require_once(__DIR__ . "/airdefender_models.php");
require_once(__DIR__ . "/airdefender_status.php");
require_once(__DIR__ . "/airdefender_feeds.php");
require_once(__DIR__ . "/airdefender_adsb.php");
require_once(__DIR__ . "/airdefender_version.php");
require_once(__DIR__ . "/airdefender_secret.php");
require_once(__DIR__ . "/airdefender_playlists.php");
require_once(__DIR__ . "/airdefender_receiver.php");
require_once(__DIR__ . "/airdefender_statefile.php");
require_once(__DIR__ . "/airdefender_deadzones.php");
require_once(__DIR__ . "/airdefender_aim.php");

const AIRDEFENDER_PLUGIN = "AirDefender";
const AIRDEFENDER_DEFAULT_CLEAR_DELAY = 30;
const AIRDEFENDER_MASTER_LOST_SEC = 5;     // no good answer from the master for this long = lost
// state.json is written when something in it changes, and at least this often
// so readers can tell a quiet loop from a stopped one.
const AIRDEFENDER_STATE_HEARTBEAT_SEC = 30;
const AIRDEFENDER_FPP_PLAYER_MODE = 2;     // FPPMode::PLAYER_MODE in FPP's settings.h

/* ---------- files ---------- */

/* Written to a temp file and renamed, so a reader never sees half a file. */
function airdefender_write_json(string $path, array $data): void
{
    $tmp = $path . ".tmp";
    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        @rename($tmp, $path);
    }
}

/* The status file, in memory where possible (see airdefender_statefile.php). */
function airdefender_write_state(array $state): void
{
    airdefender_write_json(airdefender_state_write_path(), $state);
}

/*
 * What must survive a reboot, on the SD card: each head's last channel and the
 * shutters being held. Written only when it changes (a head re-patched, safety
 * on or off), not on every check.
 */
function airdefender_write_heads(array $heads): void
{
    static $last = null;
    $key = json_encode($heads);
    if ($key !== $last) {
        airdefender_write_json(airdefender_heads_path(), $heads);
        $last = $key;
    }
}

function airdefender_log(string $message): void
{
    airdefender_write_log($message);
}

/* Log a problem once, not every second while it lasts. */
function airdefender_log_once(string $key, ?string $message): void
{
    static $last = [];
    if ($message === null) {
        unset($last[$key]);
        return;
    }
    if (($last[$key] ?? null) !== $message) {
        $last[$key] = $message;
        airdefender_log($message);
    }
}

/* ---------- settings ---------- */

function airdefender_load_settings(): array
{
    $settings = [];
    $lines = @file(airdefender_config_file(), FILE_IGNORE_NEW_LINES);
    foreach (is_array($lines) ? $lines : [] as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $settings[trim($parts[0])] = trim(trim($parts[1]), "\"' ");
        }
    }
    return $settings;
}

function airdefender_setting(array $settings, string $key, $default)
{
    $value = $settings[$key] ?? '';
    return $value === '' ? $default : $value;
}

function airdefender_truthy($value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'y'], true);
}

/* Show location in use, or [null, null] if there is none. */
function airdefender_location(array $settings): array
{
    if (airdefender_truthy(airdefender_setting($settings, 'useFPPLocation', '1'))) {
        return airdefender_fpp_location();
    }
    $lat = airdefender_setting($settings, 'latitude', '');
    $lon = airdefender_setting($settings, 'longitude', '');
    if (is_numeric($lat) && is_numeric($lon)) {
        return [(float) $lat, (float) $lon];
    }
    return [null, null];
}

/* ---------- ADS-B ---------- */



/* Compass bearing in degrees (0 = north) from the show to a point. */
function airdefender_bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dl = deg2rad($lon2 - $lon1);
    $y = sin($dl) * cos($p2);
    $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}

/*
 * Aircraft the feed returned, nearest first, for the dashboard's radar: at most
 * $max, each with distance, bearing, height and whether it is inside the limits.
 */
function airdefender_nearby(float $lat, float $lon, float $maxNm, float $maxFt, array $planes, int $max = 40): array
{
    $out = [];
    foreach ($planes as $p) {
        if ($p['onGround']) {
            continue;
        }
        $d = airdefender_distance_nm($lat, $lon, $p['lat'], $p['lon']);
        $out[] = [
            'id' => $p['id'],
            'nm' => round($d, 2),
            'brg' => (int) round(airdefender_bearing($lat, $lon, $p['lat'], $p['lon'])),
            'ft' => $p['altFt'] !== null ? (int) round($p['altFt']) : null,
            'trk' => isset($p['track']) && $p['track'] !== null ? (int) round($p['track']) % 360 : null,
            'in' => $d <= $maxNm && $p['altFt'] !== null && $p['altFt'] <= $maxFt,
        ] + ($p['info'] ?? []);
    }
    usort($out, function ($a, $b) { return $a['nm'] <=> $b['nm']; });
    return array_slice($out, 0, $max);
}

/* Closest aircraft inside the thresholds, with its distance, or null. */
function airdefender_find_trigger(float $lat, float $lon, float $maxNm, float $maxFt, array $planes): ?array
{
    $best = null;
    foreach ($planes as $p) {
        if ($p['onGround'] || $p['altFt'] === null || $p['altFt'] > $maxFt) {
            continue;
        }
        $d = airdefender_distance_nm($lat, $lon, $p['lat'], $p['lon']);
        if ($d <= $maxNm && ($best === null || $d < $best['distanceNm'])) {
            $best = $p + ['distanceNm' => round($d, 2)];
        }
    }
    return $best;
}


/* ---------- master / remote ---------- */

/* GET a URL and decode JSON. Returns [data, null] or [null, error]. */
function airdefender_get_json(string $url, int $timeout, string $extraHeaders = ''): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: FPP-AirDefender\r\nAccept: application/json\r\n" . $extraHeaders,
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
    // The status first: a refusal (401, 404) often comes with an empty body.
    if ($status !== 0 && $status !== 200) {
        return [null, "HTTP {$status}"];
    }
    if ($body === false || $body === '') {
        return [null, "no answer"];
    }
    $json = json_decode($body, true);
    return is_array($json) ? [$json, null] : [null, "not valid JSON"];
}

/*
 * The master's address: the Master FPP Address setting if filled in, else the
 * one player on the network sending MultiSync, from fppd's own list.
 * Returns [address, null] or [null, why it could not be found].
 */
function airdefender_find_master(array $settings): array
{
    $manual = trim((string) ($settings['masterAddress'] ?? ''));
    if ($manual !== '') {
        return [$manual, null];
    }

    [$data, $err] = airdefender_get_json(AirDefenderOutputs::FPPD_API . "/fppd/multiSyncSystems", 3);
    if ($data === null) {
        return [null, "could not read FPP's MultiSync list ({$err})"];
    }

    $players = [];
    $syncing = [];
    foreach ($data['systems'] ?? [] as $sys) {
        if (!is_array($sys) || !empty($sys['local']) || empty($sys['address'])) {
            continue;
        }
        if ((int) ($sys['fppMode'] ?? 0) === AIRDEFENDER_FPP_PLAYER_MODE) {
            $players[$sys['address']] = $sys['hostname'] ?? $sys['address'];
            if (!empty($sys['multisync'])) {
                $syncing[$sys['address']] = true;
            }
        }
    }

    // Prefer the player actually sending sync; fall back to the only player.
    $candidates = $syncing ? array_keys($syncing) : array_keys($players);
    if (count($candidates) === 1) {
        return [$candidates[0], null];
    }
    if (!$candidates) {
        return [null, "no Player FPP found in the MultiSync list; enter its address in settings"];
    }
    return [null, "more than one Player FPP found (" . implode(', ', $candidates) . "); enter the Player's address in settings"];
}

/* Ask the master what it wants. Returns [status, null] or [null, error]. */
/*
 * $password is the master's FPP UI password, if it has one: FPP protects every
 * page, plugin pages included, with the user "admin" and that password.
 */
function airdefender_poll_master(string $address, string $password = ''): array
{
    $base = preg_match('#^https?://#i', $address) ? rtrim($address, '/') : "http://{$address}";
    $auth = $password !== '' ? "Authorization: Basic " . base64_encode("admin:" . $password) . "\r\n" : '';
    [$data, $err] = airdefender_get_json($base . "/plugin.php?plugin=AirDefender&page=www/master_status.php&nopage=1", 2, $auth);
    if ($err === "HTTP 401") {
        return [null, $password === ''
            ? "the Player has a UI password; enter it as Player's UI password in this Remote's Air Defender settings"
            : "the Player did not accept this Remote's password; check Player's UI password in Air Defender settings"];
    }
    if ($data === null) {
        return [null, $err === "HTTP 404" ? "Air Defender is not installed on the Player" : $err];
    }
    if (($data['plugin'] ?? '') !== 'AirDefender') {
        return [null, "the Player did not answer as Air Defender"];
    }
    if (($data['role'] ?? '') !== 'master') {
        return [null, "that FPP's Air Defender is set to Remote, not Player"];
    }
    // loopRunning: the Player's loop is alive (it may be waiting on a slow feed,
    // which is the feed's problem, not a reason to close).
    // A Player still on 1.5.0 or earlier reports only how old its state is, written every second.
    $running = array_key_exists('loopRunning', $data) ? !empty($data['loopRunning'])
        : (isset($data['loopAge']) && $data['loopAge'] !== null && $data['loopAge'] <= 15);
    if (empty($data['testActive']) && !$running) {
        return [null, "the Player's detection loop is not running"];
    }
    return [$data, null];
}

/* ---------- show schedule ---------- */

const AIRDEFENDER_SHOW_LEAD_SEC = 120;   // start checking this long before a scheduled show
const AIRDEFENDER_PRIMARY_RETRY_SEC = 60;  // while on the backup service, try the chosen one again this often

/*
 * What FPP is playing, from fppd's status: whether anything is playing,
 * whether it is a scheduled show, and the next scheduled show. Null if fppd
 * could not be asked.
 */
function airdefender_show_state(): ?array
{
    [$st, $err] = airdefender_get_json(AirDefenderOutputs::FPPD_API . "/fppd/status", 2);
    if ($st === null) {
        return null;
    }
    $sched = is_array($st['scheduler'] ?? null) ? $st['scheduler'] : [];
    $schedStatus = (string) ($sched['status'] ?? 'idle');
    $name = strtolower((string) ($st['status_name'] ?? 'idle'));
    // "playing media" / "playing background" are audio only, not a light show.
    $playing = $schedStatus === 'playing' || $schedStatus === 'manual'
        || in_array($name, ['playing', 'paused', 'stopping gracefully', 'stopping gracefully after loop'], true);
    $next = is_array($sched['nextPlaylist'] ?? null) ? $sched['nextPlaylist'] : [];
    $nextStart = (int) ($next['scheduledStartTime'] ?? 0);
    // The playlist playing now (FPP gives it without ".json").
    $current = (string) ($st['current_playlist']['playlist'] ?? '');
    if ($current === '' && is_array($sched['currentPlaylist'] ?? null)) {
        $current = (string) ($sched['currentPlaylist']['playlistName'] ?? '');
    }
    // The schedule slot playing now. A playlist inserted into a scheduled show
    // (a Remote Falcon request, say) keeps the slot of the show it interrupted.
    $cp = is_array($sched['currentPlaylist'] ?? null) ? $sched['currentPlaylist'] : [];
    $slot = $schedStatus === 'playing' && (int) ($cp['scheduledStartTime'] ?? 0) > 0
        ? (int) $cp['scheduledStartTime'] . '|' . (int) ($cp['scheduledEndTime'] ?? 0) : '';
    return [
        'playing' => $playing,
        'scheduled' => $schedStatus === 'playing',
        'current' => $current,
        'slot' => $slot,
        'nextName' => (string) ($next['playlistName'] ?? ''),
        'nextStart' => $nextStart > 0 ? $nextStart : null,
    ];
}

/*
 * Whether what is playing now counts: a selected playlist, or anything played
 * inside the schedule slot of a ticked one ($tickedSlot), such as a Remote
 * Falcon request inserted into the show. Nothing ticked: every playlist counts.
 */
function airdefender_current_counts(array $show, array $only, string $tickedSlot): bool
{
    return airdefender_playlist_selected($show['current'], $only)
        || ($show['slot'] !== '' && $show['slot'] === $tickedSlot);
}

/*
 * Whether to check for aircraft now, for the When to Check setting:
 *   scheduled - All scheduled shows: during any scheduled show, and from
 *               shortly before one starts
 *   playing   - Only selected playlists ($only): while one of them plays,
 *               scheduled or started by hand, and shortly before a scheduled
 *               one (the loop treats nothing ticked as scheduled)
 * Manual tracking (Start tracking), or not being able to ask fppd, means check. With
 * $anyPlaying (Also check whenever FPP is playing), anything FPP plays counts
 * too: a playlist or a single sequence, scheduled or started by hand.
 */
function airdefender_should_check(string $mode, ?array $show, ?int $overrideUntil, int $now, array $only = [], string $tickedSlot = '', bool $anyPlaying = false): bool
{
    if ($overrideUntil !== null || $show === null || ($anyPlaying && $show['playing'])) {
        return true;
    }
    $startsSoon = $show['nextStart'] !== null
        && $show['nextStart'] - $now <= AIRDEFENDER_SHOW_LEAD_SEC && $show['nextStart'] - $now >= -60;
    if ($mode === 'playing') {
        return ($show['playing'] && airdefender_current_counts($show, $only, $tickedSlot))
            || ($startsSoon && airdefender_playlist_selected($show['nextName'], $only));
    }
    return $show['scheduled'] || $startsSoon;
}

/* ---------- main ---------- */

// One copy only: a second start (fppd restarted twice quickly) just exits,
// with code 3 so the watchdog knows another copy is already running.
// A lock file left by another user (a copy once run as root) can still be
// locked through a read-only handle; if it cannot be opened at all, that is
// not "already running", so exit 1 and let the watchdog log it and retry.
$lockFile = dirname(__DIR__) . "/loop.lock";
$lock = @fopen($lockFile, "c") ?: @fopen($lockFile, "r");
if (!$lock) {
    airdefender_log("Cannot open the lock file {$lockFile}; delete it and restart FPPD.");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(3);
}

// heads.json has what must survive a reboot; before 1.7.2 it was in state.json.
$state = json_decode((string) @file_get_contents(airdefender_heads_path()), true);
if (!is_array($state)) {
    $state = airdefender_state_read();
}
// The status file now lives in memory: drop the old copy on the SD card.
if (airdefender_state_write_path() !== airdefender_state_disk_path()) {
    @unlink(airdefender_state_disk_path());
}
// Overlays a previous run of the loop left, with the fppd run they were set in:
// they are released only if that fppd is still the one running.
$outputs = new AirDefenderOutputs(
    is_array($state['appliedChannels'] ?? null) ? $state['appliedChannels'] : [],
    isset($state['appliedFppdRun']) ? (string) $state['appliedFppdRun'] : null
);
// Channel each head last resolved to (head number => channel), so a head whose
// model disappears keeps being protected, across restarts too.
$lastKnown = [];
foreach ((is_array($state['lastKnownChannels'] ?? null) ? $state['lastKnownChannels'] : []) as $n => $ch) {
    $lastKnown[(int) $n] = (int) $ch;
}
$models = null;              // FPP's model list, last good read
$modelsAt = 0;
$nearby = [];                // aircraft from the last good fetch, for the radar
$show = null;                // what FPP is playing, re-read every 2 s
$showAt = 0;
$tickedSlot = '';            // schedule slot of the last ticked playlist seen playing
$receiverUrl = null;         // where your receiver's aircraft.json was found
$wasChecking = null;
$onBackup = false;           // the chosen service is not answering and the backup is
$primaryRetryAt = 0;
$feedUsed = null;            // the service that gave the last good answer
$safeSince = null;           // when safety mode last turned on

$safe = false;
$safetyLogged = false;       // a SAFETY ON line is in the log without its SAFETY OFF
$lastTriggerAt = 0;          // last time an aircraft was inside the thresholds
$lastTrigger = null;         // that aircraft
$nextFetchAt = 0;
$firstCheckDone = true;      // false from when checking starts until the sky has been checked once
$feedError = null;
$lastFetchOkAt = null;
$aircraftCount = null;
$lastFeedKey = '';
$feedWasDown = false;
$startedAt = time();
$masterAddress = null;       // address in use (manual or found)
$masterLookupAt = 0;         // when we last looked it up
$masterLookupError = null;
$master = null;              // last good answer from the master
$masterOkAt = 0;             // when we got it
$masterError = null;
$lastReportKey = null;       // what state.json last said, and when it was written
$lastReportAt = 0;

airdefender_log("Detection loop started (Air Defender " . airdefender_version() . ", pid " . getmypid() . ").");

while (true) {
    $now = time();
    $settings = airdefender_load_settings();

    $enabled = airdefender_truthy(airdefender_setting($settings, 'enabled', '0'));
    // Not saved yet (a new install): follow FPP's own Player Mode.
    $savedRole = (string) airdefender_setting($settings, 'nodeRole', '');
    $role = strtolower($savedRole !== '' ? $savedRole : airdefender_fpp_role()) === 'remote' ? 'remote' : 'master';
    $maxNm = max(0.1, (float) airdefender_setting($settings, 'distanceNM', 5));
    $maxFt = max(0, (float) airdefender_setting($settings, 'altitudeThresholdFt', 3000));
    // Heights are compared above the show, not above sea level.
    $elevationFt = airdefender_clamp_elevation(airdefender_setting($settings, 'showElevationFt', 0));
    // 5 to 15 seconds: a slower check lets a fast aircraft get too close between looks (an older setting above 15 is
    // treated as 15).
    $fetchInterval = min(15, max(5, (int) airdefender_setting($settings, 'fetchInterval', 10)));
    $clearDelay = max(0, (int) airdefender_setting($settings, 'clearDelaySec', AIRDEFENDER_DEFAULT_CLEAR_DELAY));
    $feedDownSafe = airdefender_truthy(airdefender_setting($settings, 'feedDownSafe', '0'));
    $checkWhen = (string) airdefender_setting($settings, 'checkWhen', 'scheduled');
    if (!in_array($checkWhen, ['scheduled', 'playing'], true)) {
        $checkWhen = 'scheduled';
    }
    $anyPlaying = airdefender_truthy(airdefender_setting($settings, 'checkAnyPlaying', '0'));
    // The selected playlists count only with Only selected playlists.
    $onlyPlaylists = $checkWhen === 'playing' ? airdefender_decode_playlists((string) ($settings['checkPlaylists'] ?? '')) : [];
    // Only selected playlists with none ticked works like the default, All
    // scheduled shows: unticking the last one stops checking a playlist started
    // by hand, while scheduled shows stay covered.
    if ($checkWhen === 'playing' && $onlyPlaylists === []) {
        $checkWhen = 'scheduled';
    }
    [$feedTemplate, $feedNote] = airdefender_effective_feed((string) airdefender_setting($settings, 'adsbSourceUrl', ''));
    $receiverAddress = trim((string) ($settings['receiverAddress'] ?? ''));
    $backupTemplate = airdefender_truthy(airdefender_setting($settings, 'feedFallback', '0')) ? airdefender_feed_backup($feedTemplate) : null;
    airdefender_log_once('feed-choice', $feedNote);
    // Re-read FPP's models every 10 s, so a head re-patched in xLights and
    // re-uploaded is followed. A failed read keeps the last good list.
    if ($now - $modelsAt >= 10) {
        $modelsAt = $now;
        $fresh = airdefender_fpp_models();
        if ($fresh !== null) {
            $models = $fresh;
        }
    }
    $resolved = airdefender_resolve_heads($settings, $models, $lastKnown);
    $heads = [];             // channel => closed value, for every enabled head
    $headNames = [];         // channel => the head's name, for the log
    foreach ($resolved as $n => $h) {
        if (in_array($h['status'], ['ok', 'manual'], true)) {
            if (($lastKnown[$n] ?? null) !== $h['channel'] && $h['status'] === 'ok' && isset($lastKnown[$n])) {
                airdefender_log("{$h['label']} ({$h['model']}) moved from channel {$lastKnown[$n]} to {$h['channel']}; following it.");
            }
            $lastKnown[$n] = $h['channel'];
        }
        if ($h['enabled'] && $h['channel'] !== null) {
            $heads[$h['channel']] = $h['closed'];
            $headNames[$h['channel']] = $h['label'];
        }
        airdefender_log_once("head{$n}", $h['enabled'] && $h['message'] !== '' ? $h['message'] . '.' : null);
    }
    ksort($heads);
    // DeadZones: keep the compiled part's file matching the settings (written only when something changes).
    airdefender_deadzones_sync($settings, $resolved);
    [$lat, $lon] = airdefender_location($settings);

    $test = airdefender_test_status();

    // Only check for aircraft when there is a show to protect (When to Check),
    // unless manual tracking is on.
    if ($now - $showAt >= 2) {
        $showAt = $now;
        $show = airdefender_show_state();
        if ($show !== null && $show['slot'] !== '' && $show['current'] !== '' && $onlyPlaylists !== []
            && airdefender_playlist_selected($show['current'], $onlyPlaylists)) {
            $tickedSlot = $show['slot'];
        }
    }
    // DeadZones aiming: ends if a show starts or the page stops checking in.
    airdefender_aim_tick($show, $now);
    $overrideUntil = airdefender_override_until();
    $checking = $role === 'remote' || airdefender_should_check($checkWhen, $show, $overrideUntil, $now, $onlyPlaylists, $tickedSlot, $anyPlaying);
    // Playing, but a playlist that is not on the list (a late-night static one, say).
    $skippedPlaylist = !$checking && $show !== null && $show['playing'] && $show['current'] !== ''
        && !airdefender_current_counts($show, $onlyPlaylists, $tickedSlot) ? $show['current'] : null;
    if ($wasChecking !== null && $checking !== $wasChecking && $role !== 'remote' && $enabled) {
        airdefender_log($checking
            ? "Checking for aircraft" . ($overrideUntil !== null ? " (manual tracking)."
                : " (" . ($show && !$show['scheduled'] && $show['playing'] ? "started by hand" : "show time")
                    . ($show && $show['current'] !== '' ? ": {$show['current']}" : "") . ").")
            : ($skippedPlaylist !== null
                ? "Standby: {$skippedPlaylist} isn't selected, so not checking for aircraft."
                : "Standby: no show playing, so not checking for aircraft."));
    }
    if ($checking && $wasChecking !== true) {
        // Close the heads first (this pass), then ask for aircraft on the next
        // pass a second later: asking can take a few seconds, and the heads
        // shouldn't be open while it does.
        $nextFetchAt = $now + 1;
        $firstCheckDone = false;
    }
    if (!$checking) {
        $feedError = null;
        $nearby = [];
    }
    $wasChecking = $checking;

    $detecting = $enabled && $role !== 'remote' && $lat !== null && $lon !== null && $checking;

    // Start over with a fresh fetch whenever what we ask the feed changes.
    $feedKey = $feedTemplate . "|" . $receiverAddress . "|" . $lat . "|" . $lon . "|" . $maxNm;
    if ($feedKey !== $lastFeedKey) {
        $lastFeedKey = $feedKey;
        $receiverUrl = null;     // a new address: look for its data again
        $nextFetchAt = 0;
        $onBackup = false;
    }

    /* --- feed --- */
    if ($detecting && $now >= $nextFetchAt) {
        $nextFetchAt = $now + $fetchInterval;

        // The chosen service, then its backup if it does not answer. While on
        // the backup, the chosen one is tried once a minute, so a
        // service that is limiting us is not asked over and over.
        $order = [$feedTemplate];
        if ($backupTemplate !== null) {
            $order = ($onBackup && $now < $primaryRetryAt) ? [$backupTemplate, $feedTemplate] : [$feedTemplate, $backupTemplate];
        }
        $planes = null;
        $errors = [];
        foreach ($order as $template) {
            if ($template === AIRDEFENDER_FEED_RECEIVER) {
                $before = $receiverUrl;
                [$planes, $err] = airdefender_fetch_receiver($receiverAddress, $receiverUrl);
                $url = $receiverUrl ?? $receiverAddress;
                if ($receiverUrl !== null && $receiverUrl !== $before) {
                    airdefender_log("Receiver found: reading {$receiverUrl}.");
                }
            } else {
                $url = airdefender_feed_url($template, $lat, $lon, $maxNm);
                // A receiver on this FPP: refuse data its decoder stopped writing.
                [$planes, $err] = airdefender_is_receiver_feed($template)
                    ? airdefender_fetch_feed($url, AIRDEFENDER_RECEIVER_MAX_AGE_SEC, 4)
                    : airdefender_fetch_feed($url);
            }
            if ($planes !== null) {
                $feedUsed = $template;
                break;
            }
            $errors[$template] = $err;
            if ($template === $feedTemplate) {
                $primaryRetryAt = $now + AIRDEFENDER_PRIMARY_RETRY_SEC;
            }
        }

        if ($planes !== null && $feedUsed === $feedTemplate && $onBackup) {
            $onBackup = false;
            airdefender_log(airdefender_feed_label($feedTemplate) . " is answering again; back to it.");
        } elseif ($planes !== null && $feedUsed !== $feedTemplate && !$onBackup) {
            $onBackup = true;
            $why = explode(';', (string) ($errors[$feedTemplate] ?? 'no answer'))[0];   // the reason, without the advice
            airdefender_log(airdefender_feed_label($feedTemplate) . " did not answer ({$why}), so Air Defender is using "
                . airdefender_feed_label($feedUsed) . " until it does.");
        }

        $firstCheckDone = true;
        if ($planes === null) {
            $feedError = $errors[$feedTemplate] ?? reset($errors);
            if ($backupTemplate !== null && isset($errors[$backupTemplate])) {
                $feedError .= "; the backup, " . airdefender_feed_label($backupTemplate) . ", did not answer either";
            }
        } else {
            $feedError = null;
        }

        if ($feedError === null) {
            if ($feedWasDown) {
                airdefender_log("ADS-B feed answering again.");
                $feedWasDown = false;
            }
            $lastFetchOkAt = $now;
            $aircraftCount = count($planes);
            $planes = airdefender_above_show($planes, $elevationFt);
            $nearby = airdefender_nearby($lat, $lon, $maxNm, $maxFt, $planes);
            airdefender_log_once('feed', null);
            $hit = airdefender_find_trigger($lat, $lon, $maxNm, $maxFt, $planes);
            if ($hit !== null) {
                $lastTriggerAt = $now;
                $lastTrigger = $hit;
            }
        } else {
            $feedWasDown = true;
            airdefender_log_once('feed', "{$feedError} ({$url}).");
        }
    }

    /* --- master (remote role only) --- */
    if ($role === 'remote' && $enabled) {
        $manual = trim((string) ($settings['masterAddress'] ?? ''));
        // Look the master up again every 30 s while it is not answering, or at once if the setting changed.
        if ($masterAddress === null || ($manual !== '' && $manual !== $masterAddress)
            || ($manual === '' && $now - $masterLookupAt >= 30 && $now - $masterOkAt > AIRDEFENDER_MASTER_LOST_SEC)) {
            $masterLookupAt = $now;
            [$found, $masterLookupError] = airdefender_find_master($settings);
            if ($found !== null && $found !== $masterAddress) {
                $masterAddress = $found;
                airdefender_log("Remote mode: using master at {$masterAddress}" . ($manual === '' ? " (found automatically)." : "."));
            }
        }

        if ($masterAddress !== null) {
            $masterPassword = airdefender_master_password();
            [$answer, $masterError] = airdefender_poll_master($masterAddress, $masterPassword);
            if ($answer !== null) {
                $master = $answer;
                $masterOkAt = $now;
            }
        } else {
            $masterError = $masterLookupError;
        }
    } elseif ($role !== 'remote') {
        $masterAddress = null;
        $master = null;
        $masterOkAt = 0;
        $masterError = null;
    }
    $masterLost = $role === 'remote' && (
        $masterOkAt === 0 ? $now - $startedAt >= AIRDEFENDER_MASTER_LOST_SEC : $now - $masterOkAt > AIRDEFENDER_MASTER_LOST_SEC
    );
    $masterName = $masterAddress ?? 'Player';

    /* --- decide --- */
    $wasSafe = $safe;
    $planeNow = $lastTriggerAt > 0 && $lastTriggerAt >= ($lastFetchOkAt ?? 0) && $feedError === null
        && $now - $lastTriggerAt <= $fetchInterval + 2;

    if ($test['active']) {
        $safe = true;
        $reason = 'test';
        $event = "Test running: safety on";
    } elseif (!$enabled) {
        $safe = false;
        $reason = 'disabled';
        $event = 'Plugin disabled';
    } elseif ($role === 'remote' && $masterLost) {
        $safe = true;
        $reason = 'master-lost';
        $event = "Lost the Player ({$masterName}): " . ($masterError ?? 'no answer') . ". Heads closed until it is back";
    } elseif ($role === 'remote' && $masterOkAt === 0) {
        $safe = $wasSafe;
        $reason = 'master-connecting';
        $event = "Connecting to the Player ({$masterName})...";
    } elseif ($role === 'remote') {
        $safe = !empty($master['safe']);
        $reason = $safe ? 'master' : 'clear';
        $event = (!empty($master['testActive']) ? "Player test: " : "Player ({$masterName}): ") . ($master['event'] ?? '');
    } elseif ($lat === null || $lon === null) {
        $safe = false;
        $reason = 'no-location';
        $event = "No show location: set it in FPP's settings, or turn off Use FPP's location in Air Defender's settings and enter one";
    } elseif (!$checking) {
        $safe = false;
        $reason = 'standby';
        $event = $skippedPlaylist !== null ? "Standby: {$skippedPlaylist} isn't selected"
            : ($checkWhen === 'playing' ? 'Standby: waiting for a selected playlist' : 'Standby: waiting for a scheduled show');
    } elseif ($planeNow) {
        $safe = true;
        $reason = 'aircraft';
        $event = "Aircraft {$lastTrigger['id']} at {$lastTrigger['distanceNm']} NM, "
            . round($lastTrigger['altFt']) . " ft" . ($elevationFt ? " above the show" : "");
    } elseif ($feedError !== null && $lastTriggerAt > 0 && $lastTriggerAt >= ($lastFetchOkAt ?? 0)) {
        // The feed's last good answer had a plane in range and it has said
        // nothing since, so the plane may still be there. Decided from the
        // feed alone, so a test starting or ending meanwhile changes nothing.
        $safe = true;
        $reason = 'feed-hold';
        $event = "Feed down while an aircraft was near: staying safe until it answers ({$feedError})";
    } elseif ($feedError !== null && $feedDownSafe) {
        // "Close Heads If Feed Is Down" is on: no data, so assume the worst.
        $safe = true;
        $reason = 'feed-down';
        $event = "ADS-B feed is down and Close Heads If Feed Is Down is on, so heads stay closed until it answers";
    } elseif ($detecting && !$firstCheckDone) {
        // Checking has just started: a show started by hand gets no 2 minutes
        // of checking beforehand, so the heads wait for the first answer
        // (seconds) rather than opening into a sky nobody has looked at.
        $safe = true;
        $reason = 'first-check';
        $event = "Checking the sky before the heads open";
    } elseif ($lastTriggerAt > 0 && $now - $lastTriggerAt < $clearDelay) {
        $safe = true;
        $reason = 'hold';
        $event = "Aircraft {$lastTrigger['id']} has left: holding safe";
    } else {
        $safe = false;
        $reason = $feedError !== null ? 'feed-error' : 'clear';
        $event = $feedError !== null
            ? "ADS-B feed problem: {$feedError}"
            : "Clear: no aircraft within {$maxNm} NM below {$maxFt} ft";
    }

    if ($role === 'remote' && $enabled && !$test['active']) {
        airdefender_log_once('master', $masterLost ? $event : ($masterOkAt ? "Connected to the Player at {$masterName}." : null));
    } elseif ($role !== 'remote') {
        airdefender_log_once('master', null);
    }

    /* --- act --- */
    $outputsOk = $outputs->apply($safe, $heads, AirDefenderOutputs::fppdRunId());
    airdefender_log_once('outputs', $outputsOk ? null : "Could not set the shutter overlays: {$outputs->lastError}");

    if ($safe && !$wasSafe) {
        $safeSince = $now;
    } elseif (!$safe) {
        $safeSince = null;
    }
    // The short hold until the first check answers isn't a safety event: it
    // happens at every start, and the log's count of times the heads closed is
    // for aircraft and tests. Logged as a plain line; a real reason that
    // follows it while the heads are still closed is logged as SAFETY ON.
    if ($safe && $reason === 'first-check') {
        if (!$wasSafe) {
            airdefender_log("Holding the shutters closed until the first check answers.");
        }
    } elseif ($safe && !$safetyLogged) {
        $safetyLogged = true;
        $what = $reason === 'test' ? "test started" : $event;
        $list = [];
        foreach (array_keys($heads) as $ch) {
            $list[] = "{$headNames[$ch]} (ch {$ch})";
        }
        airdefender_event("SAFETY ON ({$what}). Closing " . count($heads) . " shutter(s): "
            . ($list ? implode(', ', $list) : 'none configured'));
    } elseif (!$safe && $safetyLogged) {
        $safetyLogged = false;
        airdefender_event("SAFETY OFF. Shutters released back to the sequence.");
    }
    if ($safe && !$heads) {
        airdefender_log_once('heads', "Safety mode is on but no moving heads are enabled in settings, so nothing is being closed.");
    } else {
        airdefender_log_once('heads', null);
    }

    /* --- report --- */
    // Written only when it changes (plus a heartbeat), not every second, to
    // spare the SD card. Countdowns go in as the time they end, so they don't
    // change it every second; readers work out what is left.
    $report = [
        'safetyMode' => $safe,
        'reason' => $reason,
        'testActive' => $test['active'],
        'testUntil' => $test['active'] ? $test['endsAt'] : null,
        'lastEvent' => $event,
        'holdUntil' => $reason === 'hold' ? $lastTriggerAt + $clearDelay
            : ($reason === 'master' && !empty($master['holdUntil']) ? (int) $master['holdUntil'] : null),
        'lastAircraft' => $lastTrigger ? [
            'flight' => $lastTrigger['id'],
            'distanceNm' => $lastTrigger['distanceNm'],
            'altitudeFt' => round($lastTrigger['altFt']),
            'seenAt' => date('c', $lastTriggerAt),
        ] : null,
        'aircraftCount' => $aircraftCount,
        'feedUsed' => $feedUsed ? airdefender_feed_label($feedUsed) : null,
        'feedOnBackup' => $onBackup,
        'feedChosen' => airdefender_feed_label($feedTemplate),
        'nearby' => $role === 'master' && $detecting ? $nearby : [],
        'limits' => ['nm' => $maxNm, 'ft' => $maxFt, 'holdSec' => $clearDelay, 'elevationFt' => $elevationFt],
        'fetchInterval' => $fetchInterval,
        'safeSince' => $safeSince ? date('c', $safeSince) : null,
        'enabled' => $enabled,
        'checking' => $checking,
        'checkWhen' => $checkWhen,
        'overrideUntil' => $overrideUntil,
        // The next show Air Defender will check for: only one of its playlists counts.
        'nextShow' => $show && $show['nextStart'] && airdefender_playlist_selected($show['nextName'], $onlyPlaylists)
            ? ['name' => $show['nextName'], 'start' => $show['nextStart']] : null,
        'skippedPlaylist' => $skippedPlaylist,
        'playlistFilter' => $onlyPlaylists !== [],
        'lastFetchOk' => $lastFetchOkAt ? date('c', $lastFetchOkAt) : null,
        'feedError' => $feedError,
        'location' => $lat !== null ? ['lat' => $lat, 'lon' => $lon] : null,
        'role' => $role,
        'master' => $role === 'remote' ? [
            'address' => $masterAddress,
            'connected' => !$masterLost && $masterOkAt > 0,
            'lastOk' => $masterOkAt ? date('c', $masterOkAt) : null,
            'error' => $masterError,
        ] : null,
        'headsConfigured' => count($heads),
        'heads' => array_map(function ($h) {
            return ['name' => $h['label'], 'model' => $h['model'], 'channel' => $h['channel'], 'status' => $h['status'], 'problem' => $h['message']];
        }, array_filter($resolved, function ($h) { return $h['enabled']; })),
        'lastKnownChannels' => $lastKnown,
        'appliedChannels' => $outputs->applied(),
        'appliedFppdRun' => $outputs->appliedRun(),
        'outputError' => $outputsOk ? null : $outputs->lastError,
        'pid' => getmypid(),
    ];
    // The times of the last good answers change with every answer, so they
    // don't count as a change: the heartbeat brings them up to date.
    $keyed = $report;
    unset($keyed['lastFetchOk'], $keyed['master']['lastOk']);
    $reportKey = json_encode($keyed);
    airdefender_write_heads([
        'lastKnownChannels' => $lastKnown,
        'appliedChannels' => $outputs->applied(),
        'appliedFppdRun' => $outputs->appliedRun(),
    ]);
    if ($reportKey !== $lastReportKey || $now - $lastReportAt >= AIRDEFENDER_STATE_HEARTBEAT_SEC) {
        $report['lastUpdated'] = date('c', $now);
        airdefender_write_state($report);
        $lastReportKey = $reportKey;
        $lastReportAt = $now;
    }

    sleep(1);
}
