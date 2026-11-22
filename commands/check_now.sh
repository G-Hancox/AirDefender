#!/bin/bash
# FPP command "Air Defender: Check Now". fppd runs this as root;
# the override file is shared with the dashboard, which runs as fpp, so run as fpp.
CLI="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/scripts/airdefender_test_cli.php"
FPPUSER="${FPPUSER:-fpp}"
if [ "$(id -u)" = "0" ] && id "${FPPUSER}" >/dev/null 2>&1; then
    command -v runuser >/dev/null 2>&1 && exec runuser -u "${FPPUSER}" -- php "${CLI}" checknow "${1:-60}"
    exec sudo -u "${FPPUSER}" php "${CLI}" checknow "${1:-60}"
fi
exec php "${CLI}" checknow "${1:-60}"
