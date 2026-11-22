<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * AirDefender output control.
 *
 * Safety mode closes each enabled moving head's shutter by putting an FPP
 * overlay on its shutter channel: PUT /overlays/range/<ch> {"Value": closed}.
 * fppd writes that value into the channel data on every output frame, over
 * whatever the sequence is doing, and it reaches the fixture however the
 * channel is output (a Kulp's serial DMX port, E1.31/DDP to a Falcon, ...).
 *
 * Clearing deletes the overlay, so the sequence drives the shutter again.
 * It deliberately does not force the shutter open: the show may want it
 * closed at that moment.
 *
 * The signal is never cut. Most fixtures hold their last values when DMX
 * stops, which could leave a beam frozen open and pointing up.
 *
 * Overlays live in fppd's memory and vanish when fppd restarts, so the loop
 * re-applies them every cycle while safety mode is on.
 *
 * Releasing is only ever done for an overlay set during the current run of
 * fppd. Once fppd has restarted, its overlays are already gone, and on FPP
 * 10.0-10.2 a delete for a range fppd doesn't have creates one at 255 instead
 * (fixed in 10.3), which would hold the shutter open over the sequence. So
 * each overlay is recorded with the fppd run it was set in, and channels from
 * an earlier run are forgotten without a delete.
 */

require_once(__DIR__ . '/airdefender_http.php');

class AirDefenderOutputs
{
    // fppd's API through FPP's own /api proxy (Apache), the documented route
    // for plugins; requests from the FPP itself need no UI password.
    const FPPD_API = 'http://127.0.0.1/api';

    /* Channels this object has overlaid, channel => value. */
    protected $applied = [];

    /* The fppd run (see fppdRunId()) those overlays were set in. */
    protected $appliedRun = null;

    /* Last error from fppd, or '' if the last call went through. */
    public $lastError = '';

    /*
     * $previouslyApplied and $previousRun are what an earlier run of the loop
     * left overlaid, so they can be released even if their head has since been
     * disabled or changed, but only while the same fppd run is still going.
     */
    public function __construct(array $previouslyApplied = [], ?string $previousRun = null)
    {
        if ($previousRun !== null && $previousRun !== '') {
            foreach ($previouslyApplied as $ch => $value) {
                $this->applied[(int) $ch] = (int) $value;
            }
            $this->appliedRun = $previousRun;
        }
    }

    /*
     * Make the outputs match $safe. $heads is channel => closed value; $run is
     * the current fppd run (fppdRunId()), null if fppd isn't running.
     * Returns true if fppd accepted every request.
     */
    public function apply(bool $safe, array $heads, ?string $run): bool
    {
        $ok = true;
        $this->lastError = '';

        if ($run === null) {
            // fppd isn't running: there is nothing to hold or release.
            $this->lastError = 'fppd is not running';
            return false;
        }
        if ($this->appliedRun !== null && $this->appliedRun !== $run) {
            // fppd has restarted since these were set: they are already gone.
            // Never delete them (see the top of this file).
            $this->applied = [];
        }
        $this->appliedRun = $run;

        // Release anything overlaid that should not be: everything when
        // clear, or a channel no longer configured while safe.
        foreach (array_keys($this->applied) as $ch) {
            if (!$safe || !array_key_exists($ch, $heads)) {
                if (self::fppdRunId() !== $run) {
                    // fppd restarted a moment ago: its overlays went with it.
                    $this->applied = [];
                    break;
                }
                if ($this->release($ch)) {
                    unset($this->applied[$ch]);
                } else {
                    $ok = false;
                }
            }
        }

        if ($safe) {
            // Re-sent every cycle on purpose: it puts the overlay back after an
            // fppd restart, and costs one small local request per head.
            foreach ($heads as $ch => $value) {
                if ($this->hold($ch, $value)) {
                    $this->applied[$ch] = $value;
                } else {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    public function applied(): array
    {
        return $this->applied;
    }

    /* The fppd run the applied overlays belong to, or null. */
    public function appliedRun(): ?string
    {
        return $this->applied ? $this->appliedRun : null;
    }

    /*
     * Which run of fppd is going now: the boot, fppd's process id and its start
     * time in clock ticks since boot, so it changes on every fppd restart and
     * isn't fooled by the wall clock jumping (an FPP without a real-time clock
     * sets its time from the network after booting). null if fppd isn't running.
     */
    public static function fppdRunId(): ?string
    {
        static $pid = null;
        $boot = trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));
        if ($boot === '') {
            return null;            // no /proc: fppd's run can't be identified safely
        }
        foreach ([$pid, null] as $try) {
            if ($try === null) {
                $pid = null;
                foreach (glob('/proc/[0-9]*/comm') ?: [] as $f) {
                    $candidate = (int) basename(dirname($f));
                    if (trim((string) @file_get_contents($f)) === 'fppd' && self::alive($candidate)) {
                        $pid = $candidate;
                        break;
                    }
                }
                if ($pid === null) {
                    return null;
                }
                $try = $pid;
            }
            $stat = (string) @file_get_contents("/proc/{$try}/stat");
            // Fields after the ")" that closes the command name; starttime is field 22.
            $rest = substr($stat, (int) strrpos($stat, ')') + 2);
            $fields = explode(' ', $rest);
            // A process that has died but not been reaped yet ("Z") is not running.
            if (trim((string) @file_get_contents("/proc/{$try}/comm")) === 'fppd' && isset($fields[19])
                && !in_array($fields[0], ['Z', 'X', 'x'], true)) {
                return $boot . ':' . $try . ':' . $fields[19];
            }
        }
        return null;
    }

    /* Whether a process is running (not exited and waiting to be reaped). */
    protected static function alive(int $pid): bool
    {
        $stat = (string) @file_get_contents("/proc/{$pid}/stat");
        $state = substr($stat, (int) strrpos($stat, ')') + 2, 1);
        return $state !== '' && !in_array($state, ['Z', 'X', 'x'], true);
    }

    protected function hold(int $channel, int $value): bool
    {
        return $this->put("/overlays/range/{$channel}", ['Value' => $value]);
    }

    protected function release(int $channel): bool
    {
        return $this->put("/overlays/range/{$channel}", ['delete' => true]);
    }

    protected function put(string $path, array $body): bool
    {
        [$status, $response] = airdefender_http(self::FPPD_API . $path, [
            'method' => 'PUT',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($body),
            'timeout' => 3,
        ]);

        if ($response === false || $status !== 200) {
            $this->lastError = $status
                ? "fppd answered {$status} for {$path}: " . trim((string) $response)
                : "Could not reach fppd through " . self::FPPD_API . " ({$path})";
            return false;
        }

        return true;
    }
}
