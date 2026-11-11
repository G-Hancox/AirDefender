<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Where the loop's status file (state.json) lives. It changes on every check
 * while aircraft are about, so it is kept in memory (/dev/shm) rather than on
 * the SD card. Where /dev/shm can't be used it falls back to the plugin
 * folder, as before. The few things that must survive a reboot (each head's
 * last channel, and the shutters being held) go in heads.json in the plugin
 * folder, written only when they change.
 */

if (!function_exists('airdefender_state_path')) {

    define('AIRDEFENDER_STATE_MEM_DIR', '/dev/shm/AirDefender');

    function airdefender_state_disk_path(): string
    {
        return dirname(__DIR__) . '/state.json';
    }

    function airdefender_heads_path(): string
    {
        return dirname(__DIR__) . '/heads.json';
    }

    /* Where the loop writes state.json: memory if it can, else the plugin folder. */
    function airdefender_state_write_path(): string
    {
        static $path = null;
        if ($path === null) {
            $dir = AIRDEFENDER_STATE_MEM_DIR;
            $ok = is_dir('/dev/shm') && (is_dir($dir) || @mkdir($dir, 0755)) && is_writable($dir);
            $path = $ok ? $dir . '/state.json' : airdefender_state_disk_path();
        }
        return $path;
    }

    /* Where to read state.json: the newer of the memory and plugin-folder copies. */
    function airdefender_state_path(): string
    {
        $best = airdefender_state_disk_path();
        $bestTime = @filemtime($best) ?: 0;
        $mem = AIRDEFENDER_STATE_MEM_DIR . '/state.json';
        $memTime = @filemtime($mem) ?: 0;
        return $memTime >= $bestTime && $memTime > 0 ? $mem : $best;
    }

    function airdefender_state_read(): array
    {
        $state = json_decode((string) @file_get_contents(airdefender_state_path()), true);
        return is_array($state) ? $state : [];
    }
}
