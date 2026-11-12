#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
#
# Builds the compiled part (DeadZones) for this FPP. Run by fpp_install.sh and
# fpp_upgrade.sh; FPP itself rebuilds it (make -C <plugin>) when FPP is upgraded.
#
# Plane safety is all PHP and doesn't need it, so this never fails the install
# or update: if the build fails, DeadZones is simply unavailable and its
# settings tab says so. Always exits 0.
BASEDIR="$(cd "$(dirname "$0")/.." && pwd)"
SRCDIR="${SRCDIR:-${FPPDIR:-/opt/fpp}/src}"
if make -C "${BASEDIR}" "SRCDIR=${SRCDIR}"; then
    echo "Air Defender: built DeadZones"
else
    echo "Air Defender: DeadZones could not be built on this FPP; everything else works without it"
    rm -f "${BASEDIR}"/libAirDefender.so "${BASEDIR}"/src/*.o
fi
exit 0
