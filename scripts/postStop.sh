#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
#
# Run by FPP after fppd stops. Normally fppd has already stopped the detection
# loop (callbacks.sh, or the compiled part). If the loop was started by
# postStart.sh's safety net instead, stop it here, so it stops with fppd.
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${DIR}/../libAirDefender.so" ]; then
    bash "${DIR}/airdefender_stop.sh" >/dev/null 2>&1
fi
exit 0
