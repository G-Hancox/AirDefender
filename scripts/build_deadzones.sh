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
# FPP's source folder, with its build files. FPP sets SRCDIR when it runs the install, but
# a shell opened by hand (or Docker) may have it, or FPPDIR, set to something else: use the
# first that really has FPP's makefiles.
FOUND=""
for d in "${SRCDIR}" "${FPPDIR:+${FPPDIR}/src}" /opt/fpp/src; do
    if [ -n "${d}" ] && [ -f "${d}/makefiles/common/setup.mk" ]; then
        FOUND="${d}"
        break
    fi
done
if [ -z "${FOUND}" ]; then
    echo "Air Defender: FPP's source folder wasn't found, so DeadZones can't be built here; everything else works without it"
    exit 0
fi
if make -C "${BASEDIR}" "SRCDIR=${FOUND}"; then
    echo "Air Defender: built DeadZones"
else
    echo "Air Defender: DeadZones could not be built on this FPP; everything else works without it"
    rm -f "${BASEDIR}"/libAirDefender.so "${BASEDIR}"/src/*.o
fi
exit 0
