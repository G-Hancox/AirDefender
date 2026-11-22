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
 *
 * Every change (start, stop, running out) reads, checks and writes under one
 * lock (state.lock), and every file is written atomically, so a reader never
 * sees a half-written file and two changes at once can't undo each other.
 */

require_once(__DIR__ . "/airdefender_time.php");
require_once(__DIR__ . "/airdefender_files.php");
if (!defined('AIRDEFENDER_TEST_MIN_SEC')) {
    define('AIRDEFENDER_TEST_MIN_SEC', 5);
    define('AIRDEFENDER_TEST_MAX_SEC', 1800);   // 30 minutes
    define('AIRDEFENDER_TEST_DEFAULT_SEC', 60);
}

function airdefender_test_file(): string
{
    return dirname(__DIR__) . "/test.json";
}

/* The lock every change to test.json and override.json is made under. */
function airdefender_state_lock_file(): string
{
    return dirname(__DIR__) . "/state.lock";
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
    $line = "[" . date("Y-m-d H:i:s") . "] " . $message;
    airdefender_write_atomic(airdefender_last_event_file(), json_encode(['text' => $line, 'time' => time()]));
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
    return airdefender_write_atomic(airdefender_test_file(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/*
 * Current test status. A test whose end time has passed is closed out here
 * (and logged once), so whichever reader notices first ends it.
 */
function airdefender_test_status(): array
{
    $now = time();
    $data = airdefender_test_raw();
    if (!empty($data['active']) && (int) $data['endsAt'] <= $now) {
        // Ran out: close it out under the lock, checking again there, so a test
        // started meanwhile isn't overwritten and it's only logged once.
        $data = airdefender_locked(airdefender_state_lock_file(), function () use ($now) {
            $data = airdefender_test_raw();
            if (!empty($data['active']) && (int) $data['endsAt'] <= $now) {
                $data['active'] = false;
                airdefender_test_write($data);
                airdefender_event("Test finished after {$data['duration']} s; outputs restored.");
            }
            return $data;
        });
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
    airdefender_locked(airdefender_state_lock_file(), function () use ($seconds) {
        $now = time();
        airdefender_test_write([
            'active' => true,
            'startedAt' => $now,
            'endsAt' => $now + $seconds,
            'duration' => $seconds,
            'lastDuration' => $seconds,
        ]);
        airdefender_event("Test started for {$seconds} s; safety mode forced active.");
    });

    return airdefender_test_status();
}

function airdefender_test_stop(): array
{
    airdefender_locked(airdefender_state_lock_file(), function () {
        $data = airdefender_test_raw();
        if (!empty($data['active'])) {
            $data['active'] = false;
            $data['endsAt'] = time();
            airdefender_test_write($data);
            airdefender_event("Test stopped early; outputs restored.");
        }
    });

    return airdefender_test_status();
}

/*
 * Manual tracking: check for aircraft for a while even outside show times, for
 * example while testing a sequence in the afternoon. Like a test, it stores its
 * end time, so it survives refreshes and ends by itself; changes are made under
 * the same lock.
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
        // Ran out: close it out once, under the lock, checking again there, so
        // manual tracking started meanwhile isn't deleted.
        return airdefender_locked(airdefender_state_lock_file(), function () {
            $data = json_decode((string) @file_get_contents(airdefender_override_file()), true);
            $until = is_array($data) ? (int) ($data['until'] ?? 0) : 0;
            if ($until > time()) {
                return $until;
            }
            if ($until > 0) {
                @unlink(airdefender_override_file());
                airdefender_test_log("Manual tracking ended; back to the Check for aircraft during setting.");
            }
            return null;
        });
    }
    return null;
}

function airdefender_override_start($minutes): ?int
{
    $minutes = max(5, min(720, (int) $minutes));
    return airdefender_locked(airdefender_state_lock_file(), function () use ($minutes) {
        $until = time() + $minutes * 60;
        if (!airdefender_write_atomic(airdefender_override_file(), json_encode(['until' => $until, 'minutes' => $minutes]))) {
            return null;
        }
        airdefender_test_log("Manual tracking started for {$minutes} min: checking for aircraft even with no show on.");
        return $until;
    });
}

function airdefender_override_stop(): void
{
    airdefender_locked(airdefender_state_lock_file(), function () {
        if (file_exists(airdefender_override_file())) {
            @unlink(airdefender_override_file());
            airdefender_test_log("Manual tracking stopped early; back to the Check for aircraft during setting.");
        }
    });
}
