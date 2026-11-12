#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
# Air Defender uninstall script. FPP runs this (as root) just before it
# deletes the plugin's folder.

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Stop the detection loop; otherwise it keeps running from memory after its
# files are gone.
pkill -f "${PLUGIN_DIR}/scripts/airdefender_supervise.sh" 2>/dev/null
pkill -f "${PLUGIN_DIR}/scripts/airdefender_loop.php" 2>/dev/null

# If safety mode was on, hand any shutter channels the loop was holding back
# to the sequence now, rather than leaving them closed until fppd restarts.
# Only overlays set during the fppd run still going are released: after an
# fppd restart they are already gone, and on FPP 10.0-10.2 deleting one fppd
# doesn't have would create it at 255 (see airdefender_controller.php).
# heads.json has them from 1.7.2; state.json before that.
HELD="${PLUGIN_DIR}/heads.json"
[ -f "${HELD}" ] || HELD="${PLUGIN_DIR}/state.json"
if [ -f "${HELD}" ]; then
    php -r '
        require $argv[2];
        $s = json_decode((string) @file_get_contents($argv[1]), true);
        $run = AirDefenderOutputs::fppdRunId();
        if ($run === null || ($s["appliedFppdRun"] ?? null) !== $run) { exit; }
        foreach (array_keys($s["appliedChannels"] ?? []) as $ch) { echo (int) $ch, "\n"; }
    ' "${HELD}" "${PLUGIN_DIR}/scripts/airdefender_controller.php" 2>/dev/null | while read -r CH; do
        [ -n "${CH}" ] && curl -s -m 3 -X PUT -H 'Content-Type: application/json' \
            -d '{"delete":true}' "http://127.0.0.1/api/overlays/range/${CH}" >/dev/null
    done
fi

# The status file kept in memory.
# A head being aimed from the DeadZones tab right now: give it back to the show (its pan, tilt,
# dimmer and shutter overlays), so nothing is left held after the plugin has gone.
if [ -f "${PLUGIN_DIR}/scripts/airdefender_aim.php" ]; then
    php -r 'require $argv[1]; airdefender_aim_end("Air Defender was uninstalled");' \
        "${PLUGIN_DIR}/scripts/airdefender_aim.php" >/dev/null 2>&1 || true
fi

rm -rf /dev/shm/AirDefender 2>/dev/null

# No restart needed: just before this, FPP asked fppd to unload the plugin,
# which withdrew the Air Defender commands and stopped the loop (callbacks.sh).

# Remove what Air Defender kept outside its folder: the Player's UI password
# and the log (which lists aircraft that passed near the show). A Reinstall
# (FPP sets FPP_PLUGIN_REINSTALL=1 from 10.4) keeps the password, so Remotes
# don't have to be given it again after an FPP OS upgrade.
. "${FPPDIR:-/opt/fpp}/scripts/common" >/dev/null 2>&1 || true   # MEDIADIR, LOGDIR
: "${MEDIADIR:=/home/fpp/media}"
: "${LOGDIR:=${MEDIADIR}/logs}"
if [ "${FPP_PLUGIN_REINSTALL:-0}" != "1" ]; then
    rm -rf "${MEDIADIR}/plugindata/AirDefender" 2>/dev/null
fi
rm -f "${LOGDIR}/plugin-AirDefender.log" "${LOGDIR}/plugin-AirDefender.log".* 2>/dev/null
