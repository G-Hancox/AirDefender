<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Writing Air Defender's small state files safely.
 *
 * airdefender_write_atomic(): a reader never sees an empty or half-written
 * file. The data goes to a uniquely named temp file in the same folder, is
 * flushed to disk, then renamed over the old one (FPP's WriteFileAtomic(),
 * from FPP 10.0, when FPP's page code is loaded; the same steps otherwise,
 * since the detection loop runs outside FPP's pages).
 *
 * airdefender_locked(): runs a read-check-write under one exclusive lock, so
 * two writers (the loop, a dashboard, a second browser, a playlist running a
 * command) can't interleave: what one checked is still true when it writes.
 */

function airdefender_write_atomic(string $path, string $data): bool
{
    if (function_exists('WriteFileAtomic')) {
        return (bool) WriteFileAtomic($path, $data);
    }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $f = @fopen($tmp, 'xb');
    if ($f === false) {
        return false;
    }
    $ok = @fwrite($f, $data) === strlen($data);
    if ($ok) {
        fflush($f);
        if (function_exists('fsync')) {
            @fsync($f);
        }
    }
    fclose($f);
    if ($ok && @rename($tmp, $path)) {
        return true;
    }
    @unlink($tmp);
    return false;
}

/*
 * Run $work while holding an exclusive lock on $lockFile (created if needed,
 * and left in place: uninstall removes the plugin folder it lives in). If the
 * lock can't be opened, $work still runs: a lost lock must never stop a test
 * from starting or the heads from being protected.
 */
function airdefender_locked(string $lockFile, callable $work)
{
    $fh = @fopen($lockFile, 'c');
    $locked = $fh !== false && flock($fh, LOCK_EX);
    try {
        return $work();
    } finally {
        if ($locked) {
            flock($fh, LOCK_UN);
        }
        if ($fh !== false) {
            fclose($fh);
        }
    }
}
