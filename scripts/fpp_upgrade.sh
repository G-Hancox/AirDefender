#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
# Air Defender upgrade script. FPP runs this (as root) after pulling a new
# version from Plugin Manager.
#
# The detection loop is a long-running process, so it has to be restarted to
# run the new code. FPP 10.3 and later do that themselves straight after this
# script: they ask fppd to unload the plugin and load it again, which stops
# the loop and starts the new one through callbacks.sh. FPP 10.0-10.2 don't,
# so on those this script asks fppd for the same unload and load. Either way
# fppd starts the loop, never this script, so it runs under fppd and not under
# the web server. The version comes from fppd's API (/api/fppd/version).

FPPDIR="${FPPDIR:-/opt/fpp}"
API="http://127.0.0.1/api/fppd/plugin/AirDefender"

VERSION="$(curl -s -m 10 http://127.0.0.1/api/fppd/version 2>/dev/null)"
MAJOR="$(echo "${VERSION}" | sed -n 's/.*"majorVersion" *: *\([0-9]*\).*/\1/p')"
MINOR="$(echo "${VERSION}" | sed -n 's/.*"minorVersion" *: *\([0-9]*\).*/\1/p')"
if [ -z "${MAJOR}" ] || [ -z "${MINOR}" ]; then
    echo "fppd is not running; Air Defender's updated code starts with it"
    exit 0
fi
if [ "${MAJOR}" -gt 10 ] || { [ "${MAJOR}" -eq 10 ] && [ "${MINOR}" -ge 3 ]; }; then
    echo "FPP will reload Air Defender with the updated code"
    exit 0
fi

ok() { curl -s -m 30 -X POST "$1" 2>/dev/null | grep -q '"Status" *: *"OK"'; }
if ok "${API}/unload" && ok "${API}/load"; then
    echo "Reloaded Air Defender in fppd with the updated code"
else
    echo "fppd could not reload Air Defender; restart FPPD to finish the update"
    ( set +u; source "${FPPDIR}/scripts/common" && setSetting restartFlag 1 ) || true
fi
exit 0
