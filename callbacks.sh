#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
#
# fppd's plugin callbacks. Air Defender uses only the lifecycle ones, so its
# detection loop lives and dies with fppd:
#   startup  - fppd started, or loaded the plugin after an install or update
#   shutdown - fppd is stopping, or unloading the plugin to uninstall or update
# fppd waits for these (on its main loop), so both return straight away:
# startup runs scripts/airdefender_start.sh in the background, since that
# waits for an old loop to go before starting the new one; shutdown only
# signals the watchdog and the loop (scripts/airdefender_stop.sh).

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

case "$1" in
    --list)
        # fppd treats a plugin as either scripts (lifecycle) or compiled (c++), never both. With
        # DeadZones built, the compiled part is loaded and starts and stops the loop itself (it runs
        # the same two scripts). If it is built but fppd won't load it, scripts/postStart.sh starts
        # the loop anyway, so plane safety never depends on DeadZones.
        if [ -f "${DIR}/libAirDefender.so" ]; then
            echo "c++"
        else
            echo "lifecycle"
        fi
        ;;
    --type)
        if [ "$2" = "lifecycle" ]; then
            case "$3" in
                "startup")  setsid bash "${DIR}/scripts/airdefender_start.sh" </dev/null >/dev/null 2>&1 & ;;
                "shutdown") bash "${DIR}/scripts/airdefender_stop.sh" ;;
            esac
        fi
        ;;
esac
exit 0
