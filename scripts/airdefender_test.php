<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * AirDefender test mode helpers.
 *
 * A test forces safety mode on for a set number of seconds so the user can
 * walk outside and see the outputs go dark, then come back on when the timer
 * runs out. The test lives in test.json in the plugin directory, so the
 * dashboard, the test request handler and airdefender_loop.php all read the
 * same thing. The end time is stored, not a remaining count, so a page
 * refresh or a loop restart picks up exactly where the test was.
 */

require_once(__DIR__ . "/airdefender_time.php");
if (!defined('AIRDEFENDER_TEST_MIN_SEC')) {
    define('AIRDEFENDER_TEST_MIN_SEC', 5);
    define('AIRDEFENDER_TEST_MAX_SEC', 1800);   // 30 minutes
    define('AIRDEFENDER_TEST_DEFAULT_SEC', 60);
}

function airdefender_test_file(): string
{
    return dirname(__DIR__) . "/test.json";
}

function airdefender_test_log(string $message): void
{
    airdefender_write_log($message);
}

/*
 * A trigger: safety mode turning on or off, or a test starting or ending.
 * Logged like everything else, and also kept as the dashboard's Last Event,
 * which shows only these (not notes such as a head being re-patched).
 */
function airdefender_last_event_file(): string
{
    return dirname(__DIR__) . "/last_event.json";
}

function airdefender_event(string $message): void
{
    airdefender_test_log($message);
    $tmp = airdefender_last_event_file() . ".tmp";
    $line = "[" . date("Y-m-d H:i:s") . "] " . $message;
    if (@file_put_contents($tmp, json_encode(['text' => $line, 'time' => time()])) !== false) {
        @rename($tmp, airdefender_last_event_file());
    }
}

/* The newest trigger for the dashboard, or $fallback if there is none. */
function airdefender_last_event(string $fallback = 'No events yet.'): string
{
    $data = json_decode((string) @file_get_contents(airdefender_last_event_file()), true);
    return is_array($data) && isset($data['text']) && is_string($data['text']) && $data['text'] !== ''
        ? $data['text'] : $fallback;
}

function airdefender_test_clamp($seconds): int
{
    $seconds = (int) $seconds;
    if ($seconds <= 0) {
        $seconds = AIRDEFENDER_TEST_DEFAULT_SEC;
    }
    return max(AIRDEFENDER_TEST_MIN_SEC, min(AIRDEFENDER_TEST_MAX_SEC, $seconds));
}

function airdefender_test_raw(): array
{
    $default = [
        'active' => false,
        'startedAt' => null,
        'endsAt' => null,
        'duration' => AIRDEFENDER_TEST_DEFAULT_SEC,
        'lastDuration' => AIRDEFENDER_TEST_DEFAULT_SEC,
    ];

    $raw = @file_get_contents(airdefender_test_file());
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $default;
    }

    return array_merge($default, $data);
}

function airdefender_test_write(array $data): bool
{
    return @file_put_contents(
        airdefender_test_file(),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

/*
 * Current test status. A test whose end time has passed is closed out here
 * (and logged once), so whichever reader notices first ends it.
 */
function airdefender_test_status(): array
{
    $data = airdefender_test_raw();
    $now = time();

    if (!empty($data['active']) && (int) $data['endsAt'] <= $now) {
        $data['active'] = false;
        airdefender_test_write($data);
        airdefender_event("Test finished after {$data['duration']} s; outputs restored.");
    }

    $remaining = !empty($data['active']) ? max(0, (int) $data['endsAt'] - $now) : 0;

    return [
        'active' => !empty($data['active']),
        'remaining' => $remaining,
        'duration' => (int) $data['duration'],
        'lastDuration' => airdefender_test_clamp($data['lastDuration']),
        'endsAt' => $data['endsAt'] !== null ? (int) $data['endsAt'] : null,
        'serverTime' => $now,
        'minSec' => AIRDEFENDER_TEST_MIN_SEC,
        'maxSec' => AIRDEFENDER_TEST_MAX_SEC,
    ];
}

function airdefender_test_start($seconds): array
{
    $seconds = airdefender_test_clamp($seconds);
    $now = time();

    airdefender_test_write([
        'active' => true,
        'startedAt' => $now,
        'endsAt' => $now + $seconds,
        'duration' => $seconds,
        'lastDuration' => $seconds,
    ]);
    airdefender_event("Test started for {$seconds} s; safety mode forced active.");

    return airdefender_test_status();
}

function airdefender_test_stop(): array
{
    $data = airdefender_test_raw();
    if (!empty($data['active'])) {
        $data['active'] = false;
        $data['endsAt'] = time();
        airdefender_test_write($data);
        airdefender_event("Test stopped early; outputs restored.");
    }

    return airdefender_test_status();
}

/*
 * "Start tracking" (manual tracking) override: check for aircraft for a while even outside show
 * times, for example while testing a sequence in the afternoon. Like a test,
 * it stores its end time, so it survives refreshes and ends by itself.
 */
function airdefender_override_file(): string
{
    return dirname(__DIR__) . "/override.json";
}

function airdefender_override_until(): ?int
{
    $data = json_decode((string) @file_get_contents(airdefender_override_file()), true);
    $until = is_array($data) ? (int) ($data['until'] ?? 0) : 0;
    if ($until > time()) {
        return $until;
    }
    if ($until > 0) {
        // Ran out: close it out once, here or wherever it is noticed first.
        @unlink(airdefender_override_file());
        airdefender_test_log("Manual tracking ended; back to the Check for aircraft during setting.");
    }
    return null;
}

function airdefender_override_start($minutes): ?int
{
    $minutes = max(5, min(720, (int) $minutes));
    $until = time() + $minutes * 60;
    if (@file_put_contents(airdefender_override_file(), json_encode(['until' => $until, 'minutes' => $minutes]), LOCK_EX) === false) {
        return null;
    }
    airdefender_test_log("Manual tracking started for {$minutes} min: checking for aircraft even with no show on.");
    return $until;
}

function airdefender_override_stop(): void
{
    if (file_exists(airdefender_override_file())) {
        @unlink(airdefender_override_file());
        airdefender_test_log("Manual tracking stopped early; back to the Check for aircraft during setting.");
    }
}
