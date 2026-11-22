<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * The master's FPP UI password, which a Remote sends when it asks the master
 * for its status. FPP's plugin guidelines keep credentials out of config/
 * (FPP's backups and crash reports copy that folder), so it lives in
 * plugindata/AirDefender/master-password, readable only by its owner (0600).
 */

require_once(__DIR__ . '/airdefender_files.php');

require_once(__DIR__ . "/airdefender_paths.php");

function airdefender_master_password_file(): string
{
    return airdefender_plugindata_dir() . '/master-password';
}

/* The saved password, or '' when there is none. */
function airdefender_master_password(): string
{
    $file = airdefender_master_password_file();
    return is_file($file) ? (string) @file_get_contents($file) : '';
}

/* Save the password ('' removes it). Returns false if it could not be written. */
function airdefender_set_master_password(string $password): bool
{
    $file = airdefender_master_password_file();
    if ($password === '') {
        @unlink($file);
        return !file_exists($file);
    }

    $dir = dirname($file);
    if (!is_dir(dirname($dir))) {
        @mkdir(dirname($dir), 0755, true);       // FPP's plugindata folder
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700)) {
        return false;
    }
    @chmod($dir, 0700);

    // Written atomically with owner-only permissions from the start (umask 077).
    $mask = umask(077);
    $ok = airdefender_write_atomic($file, $password);
    umask($mask);
    return $ok && @chmod($file, 0600);
}
