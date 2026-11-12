#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
#
# Run by FPP each time fppd has started. Normally there is nothing to do: the
# detection loop is started by fppd itself (callbacks.sh, or the compiled part
# when DeadZones is built). This is the safety net for one case: DeadZones is
# built, so callbacks.sh tells fppd to load the compiled part, but fppd won't
# load it (for example after an FPP upgrade, until Air Defender is rebuilt).
# Then nothing else would start plane safety. So, in the background (FPP waits
# for this script), give fppd a moment and start the loop if it isn't running.
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "${DIR}/../libAirDefender.so" ] || exit 0
(
    sleep 20
    if ! pgrep -f "${DIR}/airdefender_loop.php" >/dev/null 2>&1; then
        setsid bash "${DIR}/airdefender_start.sh" </dev/null >/dev/null 2>&1
    fi
) </dev/null >/dev/null 2>&1 &
exit 0
